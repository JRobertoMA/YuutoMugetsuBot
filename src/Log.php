<?php

declare(strict_types=1);

namespace App;

use Throwable;

/** Registro de eventos del bot (tabla app_log, poda a 30 días en cli/worker.php). Nunca guarda el token. */
final class Log
{
    public static function write(string $level, string $message): void
    {
        $message = mb_substr(Telegram::redact($message), 0, 2000);
        try {
            Database::run('INSERT INTO app_log (level, message, created_at) VALUES (?, ?, ?)', [$level, $message, time()]);
        } catch (Throwable $e) {
            error_log('telegram-bot [' . $level . '] ' . $message);
        }
        if (PHP_SAPI === 'cli') {
            echo '[' . $level . '] ' . $message . "\n";
        }
    }

    public static function info(string $message): void
    {
        self::write('info', $message);
    }

    public static function warning(string $message): void
    {
        self::write('warning', $message);
    }

    public static function error(string $message): void
    {
        self::write('error', $message);
    }
}
