<?php

declare(strict_types=1);

namespace App;

use InvalidArgumentException;
use PDOException;

/**
 * Procesa un update de Telegram (lo usan public/webhook.php y cli/poll.php).
 *
 * 1. Idempotencia: el update_id se anota ANTES de actuar. Si algo falla después, Telegram no lo repite: es preferible
 *    perder una acción a ejecutarla dos veces (marcar dos veces, verificar dos veces).
 * 2. Límite por usuario: más de RATE_LIMIT updates por minuto de la misma persona se ignoran.
 * 3. Registro de chats (message, callback_query, my_chat_member).
 * 4. Enrutado:
 *    - /start, /ayuda y /vincular <código> los resuelve el bot (el último lo reenvía por el prefijo del código);
 *    - /comando → el proyecto que tiene la ruta «command»;
 *    - callback_query → el proyecto del prefijo de callback_data («rel:sync:x» → rel; «fulfill:12» → fulfill);
 *    - message_reaction → el proyecto que envió ese mensaje (sent_messages).
 *    Lo que no tiene ruta se descarta: cualquiera puede escribirle a un bot público.
 */
final class UpdateHandler
{
    public const ALLOWED_UPDATES = ['message', 'callback_query', 'my_chat_member', 'message_reaction'];
    private const RATE_LIMIT = 30;

    public static function handle(array $update): void
    {
        if (!isset($update['update_id']) || !is_int($update['update_id'])) {
            return;
        }
        $kind = null;
        foreach (self::ALLOWED_UPDATES as $k) {
            if (isset($update[$k]) && is_array($update[$k])) {
                $kind = $k;
                break;
            }
        }
        $obj  = $kind !== null ? $update[$kind] : [];
        $from = $obj['from']['id'] ?? $obj['user']['id'] ?? null;

        if (!self::claim($update['update_id'], $from, $kind ?? 'other') || $kind === null) {
            return;
        }
        if ($from !== null && self::overLimit((int) $from)) {
            return;
        }

        match ($kind) {
            'message'          => self::onMessage($obj),
            'callback_query'   => self::onCallback($obj),
            'my_chat_member'   => Chats::record($obj['chat'] ?? [], in_array($obj['new_chat_member']['status'] ?? '', ['left', 'kicked'], true) ? 'left' : 'active'),
            'message_reaction' => self::onReaction($obj),
        };
    }

    /** Anota el update; false si ya se había procesado. */
    private static function claim(int $updateId, ?int $fromId, string $kind): bool
    {
        try {
            Database::run('INSERT INTO updates (update_id, from_id, kind, received_at) VALUES (?, ?, ?, ?)', [$updateId, $fromId, $kind, time()]);
            return true;
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? 0) === 1062) {
                return false;
            }
            throw $e;
        }
    }

    private static function overLimit(int $fromId): bool
    {
        $n = (int) Database::value('SELECT COUNT(*) FROM updates WHERE from_id = ? AND received_at >= ?', [$fromId, time() - 60]);
        if ($n === self::RATE_LIMIT + 1) {
            Log::warning("Usuario {$fromId}: más de " . self::RATE_LIMIT . ' updates por minuto; se ignoran durante un minuto.');
        }
        return $n > self::RATE_LIMIT;
    }

    // --- Mensajes y comandos ----------------------------------------------------------------------

    private static function onMessage(array $msg): void
    {
        Chats::record($msg['chat'] ?? []);
        $text = (string) ($msg['text'] ?? '');
        if (!preg_match('~^/([A-Za-z0-9_]{1,32})(?:@\w+)?(?:\s+(.*))?$~s', trim($text), $m)) {
            return;   // los mensajes normales solo sirven para el registro de chats
        }
        $command = strtolower($m[1]);
        $args    = trim($m[2] ?? '');
        $chatId  = (int) $msg['chat']['id'];
        $event   = self::baseEvent($msg['from'] ?? [], $chatId, (string) ($msg['chat']['type'] ?? ''), (int) ($msg['message_id'] ?? 0));

        switch ($command) {
            case 'start':
                self::reply($chatId, "👋 Hola. Soy el bot de avisos de tus proyectos.\nEscribe /ayuda para ver lo que puedo hacer.");
                return;
            case 'ayuda':
            case 'help':
                self::reply($chatId, self::helpText());
                return;
            case 'vincular':
                self::onLink($event, $args);
                return;
        }

        $owner = Projects::routeOwner('command', $command);
        $project = $owner !== null ? Projects::find($owner) : null;
        if ($project === null) {
            self::reply($chatId, "No conozco /{$command}. Escribe /ayuda para ver los comandos.");
            return;
        }
        $response = ProjectClient::dispatch($project, $event + ['type' => 'command', 'command' => $command, 'args' => $args]);
        if ($response === null) {
            self::reply($chatId, '⚠️ El servicio no responde ahora mismo. Inténtalo en unos minutos.');
            return;
        }
        self::apply($project['name'], $response, $chatId, null);
    }

    /** /vincular REL-ABCD1234: el prefijo del código dice a qué proyecto va. */
    private static function onLink(array $event, string $code): void
    {
        $chatId = $event['chat_id'];
        $code   = strtoupper(trim($code));
        if (!preg_match('/^([A-Z]{2,8})-[A-Z0-9]{4,16}$/', $code, $m)) {
            self::reply($chatId, "Para vincular tu cuenta, genera un código en la web del proyecto («Mi cuenta → Vincular Telegram») y envíalo así:\n<code>/vincular REL-XXXXXXXX</code>");
            return;
        }
        $owner = Projects::routeOwner('link', $m[1]);
        $project = $owner !== null ? Projects::find($owner) : null;
        if ($project === null) {
            self::reply($chatId, 'Ese código no corresponde a ningún proyecto.');
            return;
        }
        $response = ProjectClient::dispatch($project, $event + ['type' => 'link', 'code' => $code]);
        self::apply($project['name'], $response ?? ['text' => '⚠️ El servicio no responde ahora mismo. Inténtalo en unos minutos.'], $chatId, null);
    }

    // --- Botones ----------------------------------------------------------------------------------

    private static function onCallback(array $cb): void
    {
        $message = $cb['message'] ?? null;
        if (is_array($message) && isset($message['chat'])) {
            Chats::record($message['chat']);
        }
        $data   = (string) ($cb['data'] ?? '');
        $prefix = strstr($data, ':', true) ?: $data;
        $owner  = $prefix !== '' ? Projects::routeOwner('callback', $prefix) : null;
        $project = $owner !== null ? Projects::find($owner) : null;
        if ($project === null || !is_array($message)) {
            self::answerCallback((string) $cb['id'], 'Esta acción ya no está disponible.');
            return;
        }
        $chatId    = (int) $message['chat']['id'];
        $messageId = (int) ($message['message_id'] ?? 0);
        $event = self::baseEvent($cb['from'] ?? [], $chatId, (string) ($message['chat']['type'] ?? ''), $messageId)
            + ['type' => 'callback', 'data' => $data, 'ref' => self::refOf($chatId, $messageId)];

        $response = ProjectClient::dispatch($project, $event);
        if ($response === null) {
            self::answerCallback((string) $cb['id'], '⚠️ El servicio no responde. Inténtalo más tarde.', true);
            return;
        }
        self::answerCallback((string) $cb['id'], (string) ($response['toast'] ?? ''), !empty($response['alert']));
        self::apply($project['name'], $response, $chatId, $messageId);
    }

    // --- Reacciones -------------------------------------------------------------------------------

    private static function onReaction(array $r): void
    {
        $chatId    = (int) ($r['chat']['id'] ?? 0);
        $messageId = (int) ($r['message_id'] ?? 0);
        $sent = Database::one('SELECT project, ref FROM sent_messages WHERE chat_id = ? AND message_id = ?', [$chatId, $messageId]);
        $project = $sent !== null ? Projects::find($sent['project']) : null;
        if ($project === null || !isset($r['user'])) {
            return;   // reacción a un mensaje que no es de ningún proyecto, o anónima (canal)
        }
        $emojis = fn (array $list) => array_values(array_filter(array_map(fn ($x) => $x['emoji'] ?? null, $list)));
        $added  = array_values(array_diff($emojis($r['new_reaction'] ?? []), $emojis($r['old_reaction'] ?? [])));
        if ($added === []) {
            return;   // se quitó una reacción: no es una acción
        }
        $event = self::baseEvent($r['user'], $chatId, (string) ($r['chat']['type'] ?? ''), $messageId)
            + ['type' => 'reaction', 'emoji' => $added, 'ref' => $sent['ref']];
        $response = ProjectClient::dispatch($project, $event);
        if ($response !== null) {
            self::apply($project['name'], $response, $chatId, $messageId);
        }
    }

    // --- Respuestas -------------------------------------------------------------------------------

    /**
     * Ejecuta la respuesta de un proyecto: «edit» edita el mensaje del botón o la reacción; si no, se envía uno nuevo.
     * Sin «text» ni «buttons» no se hace nada (p. ej. un callback que solo muestra un toast).
     */
    private static function apply(string $project, array $response, int $chatId, ?int $messageId): void
    {
        if (!isset($response['text']) && !array_key_exists('buttons', $response)) {
            return;
        }
        try {
            if (!empty($response['edit']) && $messageId !== null) {
                [$method, $payload] = Message::forEdit($response, $project, $chatId, $messageId);
                $r = Sender::send($method, $payload);
            } else {
                $r = Sender::send('sendMessage', Message::forSend($response, $project, $chatId), $project,
                    isset($response['ref']) ? mb_substr((string) $response['ref'], 0, 64) : null);
            }
            if (!$r['ok'] && !str_contains((string) $r['error'], 'message is not modified')) {
                Log::warning("Respuesta de «{$project}» no enviada: {$r['error']}");
            }
        } catch (InvalidArgumentException $e) {
            Log::error("Respuesta no válida de «{$project}»: " . $e->getMessage());
        }
    }

    private static function reply(int $chatId, string $html): void
    {
        $r = Sender::send('sendMessage', ['chat_id' => $chatId, 'text' => $html, 'parse_mode' => 'HTML', 'link_preview_options' => ['is_disabled' => true]]);
        if (!$r['ok']) {
            Log::warning("Respuesta del bot no enviada a {$chatId}: {$r['error']}");
        }
    }

    private static function answerCallback(string $id, string $text, bool $alert = false): void
    {
        Telegram::call('answerCallbackQuery', array_filter(['callback_query_id' => $id, 'text' => mb_substr($text, 0, 200), 'show_alert' => $alert]));
    }

    private static function helpText(): string
    {
        $lines = ['<b>Comandos</b>'];
        foreach (Database::all("SELECT value, description FROM routes WHERE kind = 'command' ORDER BY value") as $r) {
            $lines[] = '/' . $r['value'] . ' — ' . htmlspecialchars((string) $r['description']);
        }
        $lines[] = '/vincular &lt;código&gt; — vincula tu cuenta de un proyecto (el código se genera en su web)';
        $lines[] = '/ayuda — esta lista';
        return implode("\n", $lines);
    }

    private static function baseEvent(array $from, int $chatId, string $chatType, int $messageId): array
    {
        return [
            'chat_id'       => $chatId,
            'chat_type'     => $chatType,
            'from_id'       => isset($from['id']) ? (int) $from['id'] : null,
            'from_name'     => trim(($from['first_name'] ?? '') . ' ' . ($from['last_name'] ?? '')),
            'from_username' => $from['username'] ?? null,
            'message_id'    => $messageId,
        ];
    }

    private static function refOf(int $chatId, int $messageId): ?string
    {
        $ref = Database::value('SELECT ref FROM sent_messages WHERE chat_id = ? AND message_id = ?', [$chatId, $messageId]);
        return $ref === null ? null : (string) $ref;
    }
}
