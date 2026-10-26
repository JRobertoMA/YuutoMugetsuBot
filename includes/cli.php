<?php

declare(strict_types=1);

/**
 * Arranque común de cli/*.php: solo por consola, .env, zona horaria y (si se pide) base de datos con tablas.
 * IONOS: /usr/bin/php8.4-cli cli/<script>.php (nunca `php`: allí es PHP 4.4).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('BASE_DIR', dirname(__DIR__));

require_once __DIR__ . '/autoload.php';

use App\Database;

bot_boot(BASE_DIR);

function fail(string $message): never
{
    fwrite(STDERR, "Error: {$message}\n");
    exit(1);
}

function require_database(): void
{
    try {
        if (!Database::isConfigured() || !Database::hasSchema()) {
            fail('configura la base de datos en .env y ejecuta antes cli/migrate.php');
        }
    } catch (PDOException $e) {
        fail('no se pudo conectar a la base de datos: ' . $e->getMessage());
    }
}
