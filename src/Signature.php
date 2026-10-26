<?php

declare(strict_types=1);

namespace App;

/**
 * Firma de las peticiones entre el bot y los proyectos, en los dos sentidos (PROPUESTA_BOT_TELEGRAM.md §4).
 *
 *   X-Bot-Project:   nombre del proyecto
 *   X-Bot-Timestamp: hora Unix
 *   X-Bot-Signature: hex(hmac_sha256(clave_del_proyecto, timestamp + "." + cuerpo))
 *
 * Se rechaza si la hora se desvía más de WINDOW segundos o si la firma no coincide (hash_equals). El tráfico sale a
 * internet aunque bot y proyectos estén en el mismo hosting, por eso no basta un secreto fijo en una cabecera.
 */
final class Signature
{
    public const WINDOW = 300;

    public static function sign(string $key, int $timestamp, string $body): string
    {
        return hash_hmac('sha256', $timestamp . '.' . $body, $key);
    }

    /** @return array<string,string> cabeceras listas para una petición saliente */
    public static function headers(string $project, string $key, string $body): array
    {
        $ts = time();
        return [
            'X-Bot-Project'   => $project,
            'X-Bot-Timestamp' => (string) $ts,
            'X-Bot-Signature' => self::sign($key, $ts, $body),
        ];
    }

    public static function verify(string $key, string $timestamp, string $signature, string $body): bool
    {
        if (!ctype_digit($timestamp) || abs(time() - (int) $timestamp) > self::WINDOW) {
            return false;
        }
        return preg_match('/^[0-9a-f]{64}$/', $signature) === 1
            && hash_equals(self::sign($key, (int) $timestamp, $body), $signature);
    }
}
