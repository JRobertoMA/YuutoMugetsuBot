<?php

declare(strict_types=1);

namespace App;

/**
 * Envío a Telegram con bandeja de salida (tabla outbox).
 *
 * - Se intenta al momento. Si Telegram responde 429 (respetando retry_after), 5xx o no responde, el envío se guarda
 *   en outbox y lo reintenta cli/worker.php con espera creciente, hasta MAX_ATTEMPTS.
 * - Un error 4xx (chat inexistente, bot expulsado, texto mal formado…) no se reintenta: se devuelve al proyecto.
 * - send_after en el futuro (horas de silencio) va directo a la bandeja.
 * - Cada mensaje enviado se anota en sent_messages con su proyecto y su ref, para enrutar reacciones y ediciones.
 */
final class Sender
{
    public const MAX_ATTEMPTS = 8;

    /**
     * @return array{ok:bool, queued:bool, message_id:?int, error:?string}
     */
    public static function send(string $method, array $payload, ?string $project = null, ?string $ref = null, int $sendAfter = 0): array
    {
        if ($sendAfter > time()) {
            self::enqueue($method, $payload, $project, $ref, $sendAfter, null);
            return ['ok' => true, 'queued' => true, 'message_id' => null, 'error' => null];
        }
        $r = Telegram::call($method, $payload);
        if ($r['ok']) {
            return ['ok' => true, 'queued' => false, 'message_id' => self::remember($r['result'], $project, $ref), 'error' => null];
        }
        if (self::retryable($r)) {
            self::enqueue($method, $payload, $project, $ref, time() + self::delay($r, 0), $r['description']);
            return ['ok' => true, 'queued' => true, 'message_id' => null, 'error' => null];
        }
        return ['ok' => false, 'queued' => false, 'message_id' => null, 'error' => $r['description']];
    }

    /** Procesa la bandeja: lo que ya toca enviar. Devuelve [enviados, reintentar, descartados]. */
    public static function flush(int $limit = 50): array
    {
        $sent = $retry = $dropped = 0;
        $rows = Database::all('SELECT * FROM outbox WHERE send_after <= ? ORDER BY send_after, id LIMIT ' . max(1, $limit), [time()]);
        foreach ($rows as $row) {
            $payload = Database::decodeJson($row['payload'], []);
            $r = Telegram::call($row['method'], $payload);
            if ($r['ok']) {
                self::remember($r['result'], $row['project'], $row['ref']);
                Database::run('DELETE FROM outbox WHERE id = ?', [$row['id']]);
                $sent++;
                continue;
            }
            $attempts = (int) $row['attempts'] + 1;
            if (!self::retryable($r) || $attempts >= self::MAX_ATTEMPTS) {
                Database::run('DELETE FROM outbox WHERE id = ?', [$row['id']]);
                Log::error("Envío descartado ({$row['method']}" . ($row['project'] ? ", {$row['project']}" : '') . ") tras {$attempts} intento(s): {$r['description']}");
                $dropped++;
                continue;
            }
            Database::run('UPDATE outbox SET attempts = ?, send_after = ?, last_error = ? WHERE id = ?',
                [$attempts, time() + self::delay($r, $attempts), mb_substr($r['description'], 0, 500), $row['id']]);
            $retry++;
        }
        return [$sent, $retry, $dropped];
    }

    private static function retryable(array $r): bool
    {
        return $r['network'] || $r['error_code'] === 429;
    }

    /** 429: lo que diga Telegram; resto: 1, 2, 4, 8… min (máx. 1 h). */
    private static function delay(array $r, int $attempts): int
    {
        if ($r['retry_after'] !== null) {
            return max(1, $r['retry_after']);
        }
        return min(3600, 60 * (2 ** $attempts));
    }

    private static function enqueue(string $method, array $payload, ?string $project, ?string $ref, int $sendAfter, ?string $error): void
    {
        Database::run(
            'INSERT INTO outbox (project, method, payload, ref, attempts, send_after, last_error, created_at) VALUES (?, ?, ?, ?, 0, ?, ?, ?)',
            [$project, $method, Database::encodeJson((object) $payload), $ref, $sendAfter, $error !== null ? mb_substr($error, 0, 500) : null, time()]
        );
    }

    /** Anota el mensaje enviado (si lo es) y devuelve su message_id. */
    private static function remember(mixed $result, ?string $project, ?string $ref): ?int
    {
        if (!is_array($result) || !isset($result['message_id'], $result['chat']['id'])) {
            return null;
        }
        if ($project !== null) {
            Database::run(
                'INSERT INTO sent_messages (chat_id, message_id, project, ref, created_at) VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE project = VALUES(project), ref = VALUES(ref)',
                [(int) $result['chat']['id'], (int) $result['message_id'], $project, $ref, time()]
            );
        }
        return (int) $result['message_id'];
    }
}
