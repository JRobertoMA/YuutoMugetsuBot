<?php

declare(strict_types=1);

/** POST api/test.php {"chat": 123456 | "default"} — mensaje de prueba en nombre del proyecto. Firmado con HMAC. */

require __DIR__ . '/../../includes/bootstrap.php';

use App\Sender;

require_method('POST');
$project = require_project();
$in      = json_body();

$chat = $in['chat'] ?? 'default';
$chat = $chat === 'default' ? $project['default_chat_id'] : $chat;
if ($chat === null || !is_numeric($chat) || (string) (int) $chat !== (string) $chat) {
    json_out(null, 422, 'Indica un «chat» numérico (o fija el chat por defecto del proyecto).');
}

$r = Sender::send('sendMessage', [
    'chat_id'    => (int) $chat,
    'text'       => "✅ <b>Mensaje de prueba</b>\n«" . htmlspecialchars($project['name']) . '» puede escribir en este chat a través del bot.',
    'parse_mode' => 'HTML',
]);
if (!$r['ok']) {
    json_out(null, 502, 'Telegram: ' . $r['error']);
}
json_out(['sent' => true, 'queued' => $r['queued']]);
