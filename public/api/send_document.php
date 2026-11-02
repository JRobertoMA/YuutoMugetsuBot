<?php

declare(strict_types=1);

/**
 * POST api/send_document.php — un proyecto envía un archivo (sendDocument). Firmado con HMAC (require_project()).
 *
 *   {"chat": "default" | 123456, "filename": "diagnostico.json", "content_base64": "…", "caption": "…",
 *    "parse_mode": "HTML", "buttons": [[…]], "silent": false, "ref": "a1b2c3"}
 *
 * - filename: se sanea a [A-Za-z0-9._-] (≤ 64); content_base64: ≤ 1 MB una vez decodificado.
 * - caption: ≤ 1024 caracteres (límite de Telegram). Botones y prefijos: App\Message.
 * - Como api/send.php: si Telegram limita o no responde, el envío queda en la bandeja de salida (con el archivo).
 *
 * Respuesta: {"message_id": 123, "queued": false}.
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
$ref = isset($in['ref']) ? mb_substr((string) $in['ref'], 0, 64) : null;

try {
    $r = Sender::send('sendDocument', Message::forDocument($in, $project['name'], (int) $chat), $project['name'], $ref);
} catch (InvalidArgumentException $e) {
    json_out(null, 422, $e->getMessage());
}

if (!$r['ok']) {
    json_out(null, 502, 'Telegram: ' . $r['error']);
}
json_out(['message_id' => $r['message_id'], 'queued' => $r['queued']]);
