<?php

declare(strict_types=1);

/**
 * Tareas periódicas (cron cada 1–5 min):
 *   - vacía la bandeja de salida (reintentos tras 429 o fallo de red, envíos programados);
 *   - poda updates (7 días), sent_messages (90 días) y app_log (30 días).
 * Un solo worker a la vez (flock no bloqueante en data/worker.lock): si ya hay uno, sale sin hacer nada.
 *
 * IONOS (crontab -e):  * * * * * /usr/bin/php8.4-cli -f <HOME>/telegram-bot/cli/worker.php > /dev/null
 */

require __DIR__ . '/../includes/cli.php';

use App\Database;
use App\Files;
use App\Sender;

require_database();

$lock = Files::openLock(BASE_DIR . '/data/worker.lock');
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}

[$sent, $retry, $dropped] = Sender::flush(100);
if ($sent + $retry + $dropped > 0) {
    echo "Bandeja: {$sent} enviados, {$retry} para reintentar, {$dropped} descartados.\n";
}

$now = time();
Database::run('DELETE FROM updates WHERE received_at < ?', [$now - 7 * 86400]);
Database::run('DELETE FROM sent_messages WHERE created_at < ?', [$now - 90 * 86400]);
Database::run('DELETE FROM app_log WHERE created_at < ?', [$now - 30 * 86400]);

flock($lock, LOCK_UN);
