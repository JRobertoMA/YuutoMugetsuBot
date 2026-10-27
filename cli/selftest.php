<?php

declare(strict_types=1);

/**
 * Autotest de las cabeceras, contra la URL pública del bot (APP_URL). Solo por consola.
 *
 *   php cli/selftest.php <proyecto> [--url=https://…]
 *
 * Comprueba que el servidor web deja llegar a PHP las cabeceras propias, que es lo que no se puede probar en local
 * (en local el vhost es otro):
 *   1. api/chats.php firmado con la clave del proyecto → 200; con la firma alterada o una hora vieja → 401;
 *   2. webhook.php con el secreto de Telegram → 200; con otro secreto → 403. Usa un update_id negativo (Telegram
 *      solo usa positivos) sin contenido, que se anota y se borra al terminar: no responde ni reenvía nada;
 *   3. el api/bot.php del proyecto con un evento «ping» firmado (informativo: el proyecto puede no tenerlo aún).
 *
 * La clave y el secreto no salen del servidor y no se imprimen. No envía nada a Telegram.
 */

require __DIR__ . '/../includes/cli.php';

use App\Database;
use App\Env;
use App\Http;
use App\Projects;
use App\Signature;

require_database();

$name = '';
$base = (string) Env::get('APP_URL', '');
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--url=')) {
        $base = substr($arg, 6);
    } else {
        $name = $arg;
    }
}
$base = rtrim($base, '/');
if ($name === '') {
    fail('uso: php cli/selftest.php <proyecto> [--url=https://…]');
}
if (!preg_match('#^https?://#', $base)) {
    fail('falta APP_URL en el .env (o --url=): la URL pública del bot.');
}
$project = Projects::find($name);
if ($project === null) {
    fail("no existe el proyecto «{$name}» (php cli/project.php list).");
}
$key = Projects::key($project);

$failed = 0;
function check(string $label, bool $ok, string $detail): void
{
    global $failed;
    $failed += $ok ? 0 : 1;
    printf("  %s %s (%s)\n", $ok ? '✔' : '✖', $label, $detail);
}

/** Estado HTTP y el «error» del sobre, para el detalle. */
function describe(array $res): string
{
    if ($res['status'] === 0) {
        return 'sin respuesta: ' . $res['error'];
    }
    $json = json_decode($res['body'], true);
    return 'HTTP ' . $res['status'] . (is_array($json) && !empty($json['error']) ? ' «' . $json['error'] . '»' : '');
}

echo "Bot: {$base}\n\n1. Proyecto → bot (X-Bot-Project, X-Bot-Timestamp, X-Bot-Signature)\n";
$url = $base . '/api/chats.php?days=1';
$h   = Signature::headers($name, $key, '');
$res = Http::get($url, $h);
check('firma correcta', $res['status'] === 200, describe($res));
if ($res['status'] === 401) {
    echo "    → Si las otras dos dan 401 también, el servidor no deja pasar las cabeceras X-Bot-* a PHP.\n";
}
$bad = $h;
$bad['X-Bot-Signature'] = strrev($h['X-Bot-Signature']);
$res = Http::get($url, $bad);
check('firma alterada se rechaza', $res['status'] === 401, describe($res));
$old = time() - Signature::WINDOW - 60;
$res = Http::get($url, ['X-Bot-Project' => $name, 'X-Bot-Timestamp' => (string) $old, 'X-Bot-Signature' => Signature::sign($key, $old, '')]);
check('hora de hace más de ' . (Signature::WINDOW / 60) . ' min se rechaza', $res['status'] === 401, describe($res));

echo "\n2. Telegram → bot (X-Telegram-Bot-Api-Secret-Token)\n";
$secret = (string) Env::get('TELEGRAM_WEBHOOK_SECRET', '');
if ($secret === '') {
    check('secreto en el .env', false, 'falta TELEGRAM_WEBHOOK_SECRET: ejecuta cli/migrate.php');
} else {
    $id   = -random_int(1, 2_000_000_000);
    $body = json_encode(['update_id' => $id]);
    $res  = Http::postJson($base . '/webhook.php', $body, ['X-Telegram-Bot-Api-Secret-Token' => $secret]);
    check('secreto correcto', $res['status'] === 200, describe($res));
    $res = Http::postJson($base . '/webhook.php', $body, ['X-Telegram-Bot-Api-Secret-Token' => strrev($secret)]);
    check('secreto incorrecto se rechaza', $res['status'] === 403, describe($res));
    Database::run('DELETE FROM updates WHERE update_id = ?', [$id]);
}

echo "\n3. Bot → proyecto ({$project['url']})\n";
$body = Database::encodeJson(['type' => 'ping']);
$res  = Http::postJson($project['url'], $body, Signature::headers($name, $key, $body), 10);
$json = json_decode($res['body'], true);
$pong = $res['status'] === 200 && is_array($json) && (($json['data']['pong'] ?? false) === true);
printf("  %s %s\n", $pong ? '✔' : '·', $pong ? 'responde al ping firmado' : 'no responde al ping (' . describe($res) . '); normal si el proyecto aún no tiene su api/bot.php');

echo "\n" . ($failed === 0 ? "Todo bien.\n" : "{$failed} comprobación(es) fallida(s).\n");
exit($failed === 0 ? 0 : 1);
