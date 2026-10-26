<?php

declare(strict_types=1);

/**
 * Receptor del webhook de Telegram (registrado con cli/webhook.php set).
 *
 * Telegram manda la cabecera X-Telegram-Bot-Api-Secret-Token con el secret_token de setWebhook; sin ella (o con otra)
 * se responde 403 sin procesar nada. Si falla la base de datos se responde 500 para que Telegram lo reintente: la
 * idempotencia por update_id evita procesar dos veces lo que sí llegó a anotarse.
 */

require __DIR__ . '/../includes/bootstrap.php';

use App\Env;
use App\UpdateHandler;

require_method('POST');

$secret = (string) Env::get('TELEGRAM_WEBHOOK_SECRET', '');
$sent   = (string) ($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
if ($secret === '' || !hash_equals($secret, $sent)) {
    json_out(null, 403, 'Prohibido');
}

$update = json_decode(raw_body(), true);
if (!is_array($update)) {
    json_out(null, 400, 'Update no válido');
}

try {
    UpdateHandler::handle($update);
} catch (Throwable $e) {
    error_log('telegram-bot webhook: ' . App\Telegram::redact($e->getMessage()));
    json_out(null, 500, 'Error interno');
}

json_out(['ok' => true]);
