<?php

declare(strict_types=1);

/**
 * Long-polling SOLO para desarrollo local, donde no hay HTTPS público para el webhook.
 *
 *   php cli/poll.php          recibe updates hasta Ctrl+C (también vacía la bandeja de salida entre vueltas)
 *   php cli/poll.php --once   una sola vuelta (para pruebas)
 *
 * Usa un bot de PRUEBAS, nunca el de producción: si el bot tiene webhook, getUpdates falla (409) y este script se
 * niega a arrancar; y si no lo tiene, robaría los updates a quien los esté esperando.
 * El offset se guarda en data/poll_offset; un solo proceso a la vez (data/poll.lock).
 */

require __DIR__ . '/../includes/cli.php';

use App\Files;
use App\Sender;
use App\Telegram;
use App\UpdateHandler;

require_database();

$info = Telegram::call('getWebhookInfo');
if (!$info['ok']) {
    fail($info['description']);
}
if (($info['result']['url'] ?? '') !== '') {
    fail('Este bot tiene webhook (' . $info['result']['url'] . '): ¿es el de producción? En local usa un bot de pruebas.');
}

$lock = Files::openLock(BASE_DIR . '/data/poll.lock');
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    fail('Ya hay otro cli/poll.php en marcha.');
}

$offsetFile = BASE_DIR . '/data/poll_offset';
$offset     = is_file($offsetFile) ? (int) file_get_contents($offsetFile) : 0;
$once       = in_array('--once', $argv, true);
echo "Escuchando updates (Ctrl+C para salir)…\n";

do {
    $r = Telegram::call('getUpdates', ['offset' => $offset, 'timeout' => $once ? 0 : 25, 'allowed_updates' => UpdateHandler::ALLOWED_UPDATES], 35);
    if (!$r['ok']) {
        fwrite(STDERR, "getUpdates: {$r['description']}\n");
        sleep(5);
        continue;
    }
    foreach ($r['result'] as $update) {
        try {
            UpdateHandler::handle($update);
        } catch (Throwable $e) {
            fwrite(STDERR, "Update {$update['update_id']}: " . Telegram::redact($e->getMessage()) . "\n");
        }
        $offset = (int) $update['update_id'] + 1;
        $new    = !is_file($offsetFile);
        file_put_contents($offsetFile, (string) $offset);
        if ($new) {
            Files::claimNew($offsetFile);
        }
        echo date('H:i:s') . " update {$update['update_id']}\n";
    }
    Sender::flush();
} while (!$once);
