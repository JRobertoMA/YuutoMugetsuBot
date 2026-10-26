<?php

declare(strict_types=1);

namespace App;

use Throwable;

/**
 * Reenvío de un evento (comando, callback, reacción, vinculación) al api/bot.php de su proyecto, firmado con HMAC.
 *
 * El proyecto responde con su sobre JSON habitual {data, error, status}; «data» es lo que hay que hacer:
 *   {"text": "…", "buttons": [[…]], "edit": true, "toast": "Hecho", "alert": false, "ref": "a1b2c3", …}
 * (ver App\Message para los campos del mensaje). También se acepta el objeto sin sobre.
 */
final class ProjectClient
{
    private const TIMEOUT = 10;

    /** @return array|null respuesta del proyecto, o null si no responde bien (queda en el log) */
    public static function dispatch(array $project, array $event): ?array
    {
        try {
            $body = Database::encodeJson($event);
            $res  = Http::postJson($project['url'], $body, Signature::headers($project['name'], Projects::key($project), $body), self::TIMEOUT);
        } catch (Throwable $e) {
            Log::error("Reenvío a «{$project['name']}»: " . $e->getMessage());
            return null;
        }
        if ($res['status'] === 0) {
            Log::error("«{$project['name']}» no responde ({$event['type']}): {$res['error']}");
            return null;
        }
        $json = json_decode($res['body'], true);
        if ($res['status'] >= 400 || !is_array($json)) {
            $detail = is_array($json) && isset($json['error']) ? (string) $json['error'] : 'HTTP ' . $res['status'];
            Log::error("«{$project['name']}» respondió con error ({$event['type']}): {$detail}");
            return null;
        }
        $data = array_key_exists('data', $json) && array_key_exists('status', $json) ? $json['data'] : $json;
        return is_array($data) ? $data : [];
    }
}
