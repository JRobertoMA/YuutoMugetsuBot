<?php

declare(strict_types=1);

/**
 * POST api/send.php — un proyecto envía (o edita) un mensaje. Firmado con HMAC (require_project()).
 *
 *   {"chat": "default" | 123456, "text": "…", "parse_mode": "HTML", "buttons": [[…]], "silent": false,
 *    "preview": false, "ref": "a1b2c3", "send_after": 1767225600, "edit_message_id": null}
 *
 * - chat "default": el chat por defecto del proyecto (cli/project.php default-chat).
 * - ref: a qué se refiere el mensaje (repo, cupón…); el bot lo devuelve en callbacks y reacciones a ese mensaje.
 * - send_after: hora Unix; si es futura, el envío espera en la bandeja de salida (horas de silencio).
 * - edit_message_id: edita ese mensaje en lugar de enviar uno nuevo.
 * Campos del mensaje y botones: App\Message.
 *
 * Respuesta: {"message_id": 123, "queued": false}. queued = true si Telegram limitó o no respondió (se reintentará).
 */

require __DIR__ . '/../../includes/bootstrap.php';

use App\Message;
use App\Sender;

require_method('POST');
$project = require_project();
$in      = json_body();

$chat = $in['chat'] ?? 'default';
if ($chat === 'default') {
    $chat = $project['default_chat_id'];
    if ($chat === null) {
        json_out(null, 422, 'El proyecto no tiene chat por defecto: indica «chat» o fíjalo con cli/project.php default-chat.');
    }
}
if (!is_numeric($chat) || (string) (int) $chat !== (string) $chat) {
    json_out(null, 422, '«chat» debe ser un id numérico o "default".');
}
$chatId = (int) $chat;
$ref    = isset($in['ref']) ? mb_substr((string) $in['ref'], 0, 64) : null;

try {
    if (!empty($in['edit_message_id'])) {
        [$method, $payload] = Message::forEdit($in, $project['name'], $chatId, (int) $in['edit_message_id']);
        $r = Sender::send($method, $payload, $project['name'], $ref);
    } else {
        $r = Sender::send('sendMessage', Message::forSend($in, $project['name'], $chatId), $project['name'], $ref, (int) ($in['send_after'] ?? 0));
    }
} catch (InvalidArgumentException $e) {
    json_out(null, 422, $e->getMessage());
}

if (!$r['ok']) {
    json_out(null, 502, 'Telegram: ' . $r['error']);
}
json_out(['message_id' => $r['message_id'], 'queued' => $r['queued']]);
