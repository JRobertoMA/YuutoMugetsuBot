<?php

declare(strict_types=1);

namespace App;

/**
 * Llamadas a la Bot API. El token solo vive en el .env del bot (TELEGRAM_BOT_TOKEN) y nunca aparece en un error ni
 * en el log: los mensajes se limpian con redact().
 *
 * TELEGRAM_API_BASE (opcional, por defecto https://api.telegram.org) permite apuntar a un servidor falso en pruebas.
 *
 * Si los parámetros traen «_document» ({name, base64}, de Message::forDocument), la llamada va en multipart con el
 * archivo en memoria (sin escribirlo a disco); así el mismo payload JSON sirve para la bandeja de salida.
 */
final class Telegram
{
    /**
     * @return array{ok:bool, result:mixed, error_code:int, description:string, retry_after:?int, network:bool}
     *         network = no hubo respuesta de Telegram (reintentable); error_code 429 trae retry_after.
     */
    public static function call(string $method, array $params = [], int $timeout = 15): array
    {
        $token = (string) Env::get('TELEGRAM_BOT_TOKEN', '');
        if ($token === '') {
            return self::fail(0, 'Falta TELEGRAM_BOT_TOKEN en el .env', false);
        }
        $base = rtrim((string) Env::get('TELEGRAM_API_BASE', 'https://api.telegram.org'), '/');
        $url  = $base . '/bot' . $token . '/' . $method;
        $res  = isset($params['_document'])
            ? Http::postMultipart($url, self::multipart($params), max($timeout, 30))
            : Http::postJson($url, Database::encodeJson((object) $params), [], $timeout);
        if ($res['status'] === 0) {
            return self::fail(0, 'Sin respuesta de Telegram: ' . self::redact((string) $res['error']), true);
        }
        $body = json_decode($res['body'], true);
        if (!is_array($body)) {
            return self::fail($res['status'], 'Respuesta no válida de Telegram (HTTP ' . $res['status'] . ')', $res['status'] >= 500);
        }
        if (!empty($body['ok'])) {
            return ['ok' => true, 'result' => $body['result'] ?? null, 'error_code' => 0, 'description' => '', 'retry_after' => null, 'network' => false];
        }
        $code = (int) ($body['error_code'] ?? $res['status']);
        return [
            'ok'          => false,
            'result'      => null,
            'error_code'  => $code,
            'description' => self::redact((string) ($body['description'] ?? 'Error de Telegram')),
            'retry_after' => isset($body['parameters']['retry_after']) ? (int) $body['parameters']['retry_after'] : null,
            'network'     => $code >= 500,
        ];
    }

    /** Campos multipart: el documento como archivo, los arrays (reply_markup…) en JSON y el resto como texto. */
    private static function multipart(array $params): array
    {
        $doc = $params['_document'];
        unset($params['_document']);
        $fields = [];
        foreach ($params as $k => $v) {
            $fields[$k] = is_array($v) ? Database::encodeJson($v) : (is_bool($v) ? ($v ? 'true' : 'false') : (string) $v);
        }
        $name = (string) ($doc['name'] ?? 'archivo');
        $mime = str_ends_with(strtolower($name), '.json') ? 'application/json' : 'application/octet-stream';
        $fields['document'] = new \CURLStringFile((string) base64_decode((string) ($doc['base64'] ?? '')), $name, $mime);
        return $fields;
    }

    /** Quita el token de cualquier texto que vaya a un log o a una respuesta. */
    public static function redact(string $text): string
    {
        $token = (string) Env::get('TELEGRAM_BOT_TOKEN', '');
        return $token === '' ? $text : str_replace($token, '<TOKEN>', $text);
    }

    private static function fail(int $code, string $description, bool $network): array
    {
        return ['ok' => false, 'result' => null, 'error_code' => $code, 'description' => $description, 'retry_after' => null, 'network' => $network];
    }
}
