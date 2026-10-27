<?php

declare(strict_types=1);

namespace App;

/**
 * POST JSON y GET con curl (sin Composer: el bot no necesita Guzzle). Lo usan Telegram, el reenvío a los proyectos
 * y cli/selftest.php.
 * Nunca sigue redirecciones: una respuesta 3xx se trata como error.
 */
final class Http
{
    /**
     * @param array<string,string> $headers
     * @return array{status:int, body:string, error:?string}  status 0 = no hubo respuesta (red, DNS, timeout)
     */
    public static function postJson(string $url, string $json, array $headers = [], int $timeout = 15): array
    {
        $lines = ['Content-Type: application/json', 'Accept: application/json'];
        foreach ($headers as $k => $v) {
            $lines[] = $k . ': ' . $v;
        }
        return self::request($url, [
            CURLOPT_POST       => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => $lines,
        ], $timeout);
    }

    /**
     * @param array<string,string> $headers
     * @return array{status:int, body:string, error:?string}
     */
    public static function get(string $url, array $headers = [], int $timeout = 15): array
    {
        $lines = ['Accept: application/json'];
        foreach ($headers as $k => $v) {
            $lines[] = $k . ': ' . $v;
        }
        return self::request($url, [CURLOPT_HTTPGET => true, CURLOPT_HTTPHEADER => $lines], $timeout);
    }

    /** @return array{status:int, body:string, error:?string} */
    private static function request(string $url, array $options, int $timeout): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, $options + [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_USERAGENT      => 'telegram-bot/' . trim((string) @file_get_contents(dirname(__DIR__) . '/VERSION')),
        ]);
        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error  = $body === false ? curl_error($ch) : null;
        curl_close($ch);
        return ['status' => $body === false ? 0 : $status, 'body' => $body === false ? '' : (string) $body, 'error' => $error];
    }
}
