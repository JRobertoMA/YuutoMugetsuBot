<?php

declare(strict_types=1);

/**
 * Webhook del bot en Telegram.
 *
 *   php cli/webhook.php set      registra APP_URL/webhook.php con el secreto y los tipos de update que se usan
 *   php cli/webhook.php info     estado (URL, pendientes, último error)
 *   php cli/webhook.php delete   lo quita (Telegram guarda los updates hasta 24 h; con --drop los descarta)
 *
 * Telegram solo acepta HTTPS con certificado válido en los puertos 443, 80, 88 u 8443. Con el webhook activo,
 * getUpdates deja de funcionar para ESTE bot (también el «Detectar chat» antiguo de los proyectos).
 */

require __DIR__ . '/../includes/cli.php';

use App\Env;
use App\Telegram;
use App\UpdateHandler;

$action = $argv[1] ?? 'info';

switch ($action) {
    case 'set':
        $url    = rtrim((string) Env::get('APP_URL', ''), '/') . '/webhook.php';
        $secret = (string) Env::get('TELEGRAM_WEBHOOK_SECRET', '');
        if (!str_starts_with($url, 'https://')) {
            fail('APP_URL del .env tiene que ser la URL pública HTTPS del bot (p. ej. https://bot.<dominio>).');
        }
        if ($secret === '') {
            fail('Falta TELEGRAM_WEBHOOK_SECRET en el .env: ejecuta cli/migrate.php, que lo genera.');
        }
        $r = Telegram::call('setWebhook', [
            'url'             => $url,
            'secret_token'    => $secret,
            'allowed_updates' => UpdateHandler::ALLOWED_UPDATES,
            'max_connections' => 10,
        ]);
        $r['ok'] ? print("Webhook registrado: {$url}\n") : fail($r['description']);
        break;

    case 'info':
        $r = Telegram::call('getWebhookInfo');
        if (!$r['ok']) {
            fail($r['description']);
        }
        $i = $r['result'];
        echo 'URL:          ' . (($i['url'] ?? '') !== '' ? $i['url'] : '(ninguna: nadie recibe por webhook)') . "\n";
        echo 'Pendientes:   ' . ($i['pending_update_count'] ?? 0) . "\n";
        echo 'Tipos:        ' . implode(', ', $i['allowed_updates'] ?? ['(por defecto)']) . "\n";
        if (!empty($i['last_error_message'])) {
            echo 'Último error: ' . date('Y-m-d H:i', (int) $i['last_error_date']) . ' · ' . $i['last_error_message'] . "\n";
        }
        break;

    case 'delete':
        $r = Telegram::call('deleteWebhook', ['drop_pending_updates' => in_array('--drop', $argv, true)]);
        $r['ok'] ? print("Webhook eliminado.\n") : fail($r['description']);
        break;

    default:
        fwrite(STDERR, "Uso: php cli/webhook.php set|info|delete [--drop]\n");
        exit(1);
}
