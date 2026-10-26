<?php

declare(strict_types=1);

/**
 * Arranque de los endpoints web (public/webhook.php y public/api/*.php). No hay páginas ni sesiones: todo es JSON.
 *
 * - Sin .env, sin base de datos o sin tablas → 503 JSON (nunca muestra secretos; el detalle va a error_log).
 * - api/*: require_project() comprueba la firma HMAC del proyecto (App\Signature).
 */

define('BASE_DIR', dirname(__DIR__));

require_once __DIR__ . '/autoload.php';

use App\Database;
use App\Env;
use App\Projects;
use App\Signature;

bot_boot(BASE_DIR);

header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store, max-age=0');
header('Referrer-Policy: no-referrer');

/** Sobre JSON común: {data, error, status}. */
function json_out(mixed $data, int $status = 200, ?string $error = null): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['data' => $data, 'error' => $error, 'status' => $status], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function require_method(string $method): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== $method) {
        header('Allow: ' . $method);
        json_out(null, 405, 'Método no permitido');
    }
}

/** Cuerpo crudo de la petición (se lee una vez: la firma se calcula sobre estos bytes exactos). */
function raw_body(): string
{
    static $body = null;
    return $body ??= (string) file_get_contents('php://input');
}

/** Cuerpo JSON como array (422 si no lo es). */
function json_body(): array
{
    $data = json_decode(raw_body() === '' ? '{}' : raw_body(), true);
    if (!is_array($data)) {
        json_out(null, 422, 'El cuerpo debe ser JSON');
    }
    return $data;
}

/** Proyecto autenticado por X-Bot-Project + X-Bot-Timestamp + X-Bot-Signature, o 401. */
function require_project(): array
{
    $name    = (string) ($_SERVER['HTTP_X_BOT_PROJECT'] ?? '');
    $project = preg_match('/^[a-z][a-z0-9_-]{1,31}$/', $name) ? Projects::find($name) : null;
    $ok      = $project !== null && Signature::verify(
        Projects::key($project),
        (string) ($_SERVER['HTTP_X_BOT_TIMESTAMP'] ?? ''),
        (string) ($_SERVER['HTTP_X_BOT_SIGNATURE'] ?? ''),
        raw_body()
    );
    if (!$ok) {
        json_out(null, 401, 'Firma no válida');
    }
    return $project;
}

if (!Env::exists() || !Database::isConfigured()) {
    json_out(null, 503, 'Falta configurar el .env');
}
try {
    if (!Database::hasSchema()) {
        json_out(null, 503, 'Faltan las tablas: ejecuta cli/migrate.php');
    }
} catch (PDOException $e) {
    error_log('telegram-bot: no se pudo conectar a la base de datos: ' . $e->getMessage());
    json_out(null, 503, 'Base de datos no disponible');
}
