<?php

declare(strict_types=1);

namespace App;

use InvalidArgumentException;

/**
 * Traduce el mensaje que manda un proyecto (api/send o la respuesta de su api/bot.php) a parámetros de la Bot API.
 *
 *   text        texto (obligatorio salvo que solo se cambien los botones)
 *   parse_mode  "HTML" (por defecto), "MarkdownV2" o "none"
 *   buttons     filas de botones: [[{"text": "…", "data": "rel:sync:a1b2c3"} | {"text": "…", "url": "https://…"}
 *               | {"text": "…", "copy": "docker pull …"}]]  —  [] quita el teclado
 *   silent      true = sin sonido (disable_notification)
 *   preview     true = con vista previa de enlaces (por defecto sin ella)
 *
 * Un proyecto solo puede poner en callback_data sus propios prefijos (rutas «callback» suyas): así no puede crear
 * botones que acaben en otro proyecto.
 *
 * Documentos (api/send_document): filename, content_base64 (≤ 1 MB decodificado), caption (≤ 1024), parse_mode,
 * buttons y silent. El archivo viaja en el payload como «_document» (Telegram::call lo envía en multipart).
 */
final class Message
{
    private const MAX_BUTTONS = 100;
    private const MAX_PER_ROW = 8;

    /** Tamaño máximo de un documento, ya decodificado. Telegram admite 50 MB; aquí solo pasan diagnósticos y similares. */
    public const MAX_DOCUMENT_BYTES = 1048576;

    /** Parámetros para sendMessage. */
    public static function forSend(array $msg, string $project, int $chatId): array
    {
        $p = ['chat_id' => $chatId] + self::content($msg, $project, true);
        if (!empty($msg['silent'])) {
            $p['disable_notification'] = true;
        }
        return $p;
    }

    /** Parámetros para editMessageText (o editMessageReplyMarkup si no hay texto). @return array{0:string,1:array} */
    public static function forEdit(array $msg, string $project, int $chatId, int $messageId): array
    {
        $base = ['chat_id' => $chatId, 'message_id' => $messageId];
        if (!isset($msg['text']) || $msg['text'] === '') {
            if (!array_key_exists('buttons', $msg)) {
                throw new InvalidArgumentException('Para editar hace falta «text» o «buttons».');
            }
            return ['editMessageReplyMarkup', $base + ['reply_markup' => self::keyboard((array) $msg['buttons'], $project)]];
        }
        return ['editMessageText', $base + self::content($msg, $project, true)];
    }

    /** Parámetros para sendDocument, con el archivo en «_document» ({name, base64}). */
    public static function forDocument(array $msg, string $project, int $chatId): array
    {
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', basename((string) ($msg['filename'] ?? '')));
        $name = ltrim(mb_substr((string) $name, 0, 64), '.');
        if ($name === '') {
            throw new InvalidArgumentException('Falta «filename».');
        }
        $b64 = (string) ($msg['content_base64'] ?? '');
        $bytes = base64_decode($b64, true);
        if ($bytes === false || $bytes === '') {
            throw new InvalidArgumentException('«content_base64» falta o no es base64 válido.');
        }
        if (strlen($bytes) > self::MAX_DOCUMENT_BYTES) {
            throw new InvalidArgumentException('El documento supera ' . intdiv(self::MAX_DOCUMENT_BYTES, 1024) . ' KB.');
        }
        $p = ['chat_id' => $chatId, '_document' => ['name' => $name, 'base64' => base64_encode($bytes)]];
        $caption = (string) ($msg['caption'] ?? '');
        if (mb_strlen($caption) > 1024) {
            throw new InvalidArgumentException('«caption» supera los 1024 caracteres de Telegram.');
        }
        if ($caption !== '') {
            $p['caption'] = $caption;
            $mode = (string) ($msg['parse_mode'] ?? 'HTML');
            if (!in_array($mode, ['HTML', 'MarkdownV2', 'none'], true)) {
                throw new InvalidArgumentException('parse_mode debe ser HTML, MarkdownV2 o none.');
            }
            if ($mode !== 'none') {
                $p['parse_mode'] = $mode;
            }
        }
        if (array_key_exists('buttons', $msg)) {
            $p['reply_markup'] = self::keyboard((array) $msg['buttons'], $project);
        }
        if (!empty($msg['silent'])) {
            $p['disable_notification'] = true;
        }
        return $p;
    }

    /** Texto, formato, vista previa y teclado. */
    private static function content(array $msg, string $project, bool $requireText): array
    {
        $text = isset($msg['text']) ? (string) $msg['text'] : '';
        if ($requireText && trim($text) === '') {
            throw new InvalidArgumentException('Falta «text».');
        }
        if (mb_strlen($text) > 4096) {
            throw new InvalidArgumentException('«text» supera los 4096 caracteres de Telegram.');
        }
        $p = ['text' => $text];
        $mode = (string) ($msg['parse_mode'] ?? 'HTML');
        if (!in_array($mode, ['HTML', 'MarkdownV2', 'none'], true)) {
            throw new InvalidArgumentException('parse_mode debe ser HTML, MarkdownV2 o none.');
        }
        if ($mode !== 'none') {
            $p['parse_mode'] = $mode;
        }
        $p['link_preview_options'] = ['is_disabled' => empty($msg['preview'])];
        if (array_key_exists('buttons', $msg)) {
            $p['reply_markup'] = self::keyboard((array) $msg['buttons'], $project);
        }
        return $p;
    }

    /** Teclado inline validado. */
    public static function keyboard(array $rows, string $project): array
    {
        $prefixes = Projects::callbackPrefixes($project);
        $out = [];
        $count = 0;
        foreach ($rows as $row) {
            if (!is_array($row) || $row === [] || count($row) > self::MAX_PER_ROW) {
                throw new InvalidArgumentException('Cada fila de «buttons» debe tener de 1 a ' . self::MAX_PER_ROW . ' botones.');
            }
            $line = [];
            foreach ($row as $b) {
                if (++$count > self::MAX_BUTTONS) {
                    throw new InvalidArgumentException('Demasiados botones (máx. ' . self::MAX_BUTTONS . ').');
                }
                $line[] = self::button(is_array($b) ? $b : [], $prefixes);
            }
            $out[] = $line;
        }
        return ['inline_keyboard' => $out];
    }

    private static function button(array $b, array $prefixes): array
    {
        $text = trim((string) ($b['text'] ?? ''));
        if ($text === '') {
            throw new InvalidArgumentException('Botón sin «text».');
        }
        if (isset($b['data'])) {
            $data = (string) $b['data'];
            if (strlen($data) > 64) {
                throw new InvalidArgumentException('callback_data supera los 64 bytes de Telegram.');
            }
            if (!in_array(strstr($data, ':', true) ?: $data, $prefixes, true)) {
                throw new InvalidArgumentException('callback_data «' . $data . '» no empieza por un prefijo de este proyecto.');
            }
            return ['text' => $text, 'callback_data' => $data];
        }
        if (isset($b['url'])) {
            $url = (string) $b['url'];
            if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(parse_url($url, PHP_URL_SCHEME), ['https', 'http', 'tg'], true)) {
                throw new InvalidArgumentException('URL de botón no válida.');
            }
            return ['text' => $text, 'url' => $url];
        }
        if (isset($b['copy'])) {
            $copy = (string) $b['copy'];
            if ($copy === '' || mb_strlen($copy) > 256) {
                throw new InvalidArgumentException('«copy» debe tener de 1 a 256 caracteres.');
            }
            return ['text' => $text, 'copy_text' => ['text' => $copy]];
        }
        throw new InvalidArgumentException('Cada botón necesita «data», «url» o «copy».');
    }
}
