<?php

declare(strict_types=1);

/**
 * Aplica las migraciones pendientes de database/migrations/ (en orden por nombre) y las registra en
 * schema_migrations. Idempotente: se ejecuta tras cada actualización.
 *
 *   php cli/migrate.php            aplica lo pendiente
 *   php cli/migrate.php --status   lista aplicadas y pendientes
 *
 * Además añade al .env, si faltan (nunca los imprime ni los sustituye):
 *   APP_KEY                  cifra las claves de los proyectos en la base de datos
 *   TELEGRAM_WEBHOOK_SECRET  lo que Telegram devuelve en cada llamada al webhook
 */

require __DIR__ . '/../includes/cli.php';

use App\Crypto;
use App\Database;
use App\Env;

if (!Env::exists()) {
    fail('No existe .env. Cópialo de .env.example y rellena DB_HOST, DB_NAME, DB_USER, DB_PASS y TELEGRAM_BOT_TOKEN.');
}

try {
    $pdo = Database::pdo();
    $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
        version    VARCHAR(100) NOT NULL,
        applied_at INT UNSIGNED NOT NULL,
        PRIMARY KEY (version)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
} catch (RuntimeException $e) {
    fail($e->getMessage());
} catch (PDOException $e) {
    fail(match ((int) ($e->errorInfo[1] ?? 0)) {
        1045    => 'Usuario o contraseña de la base de datos incorrectos (.env).',
        1049    => 'La base de datos de DB_NAME no existe.',
        1142    => 'El usuario de la base de datos no tiene permiso CREATE sobre su base.',
        2002    => 'No se puede conectar a DB_HOST.',
        default => 'Base de datos: ' . $e->getMessage(),
    });
}

$applied = array_column(Database::all('SELECT version FROM schema_migrations'), 'version');
$files   = glob(BASE_DIR . '/database/migrations/*.sql') ?: [];
sort($files, SORT_STRING);

if (in_array('--status', $argv, true)) {
    foreach ($files as $file) {
        $v = basename($file, '.sql');
        printf("%-12s %s\n", in_array($v, $applied, true) ? 'aplicada' : 'PENDIENTE', $v);
    }
    exit(0);
}

$done = 0;
foreach ($files as $file) {
    $version = basename($file, '.sql');
    if (in_array($version, $applied, true)) {
        continue;
    }
    // Sentencias separadas por «;» a final de línea; las migraciones no usan «;» dentro de textos
    $sql = preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($file));
    try {
        foreach (preg_split('/;\s*$/m', $sql) as $statement) {
            if (trim($statement) !== '') {
                $pdo->exec($statement);
            }
        }
    } catch (PDOException $e) {
        fail("{$version}: " . ((int) ($e->errorInfo[1] ?? 0) === 1142
            ? 'el usuario de la base de datos no tiene permisos CREATE/ALTER/INDEX/REFERENCES.'
            : $e->getMessage()));
    }
    Database::run('INSERT INTO schema_migrations (version, applied_at) VALUES (?, ?)', [$version, time()]);
    echo "Aplicada: {$version}\n";
    $done++;
}
echo $done === 0 ? "La base de datos ya está al día.\n" : "{$done} migración(es) aplicada(s).\n";

try {
    $added = Env::appendMissing(array_filter([
        'APP_KEY'                 => Env::has('APP_KEY') ? null : Crypto::generateKey(),
        'TELEGRAM_WEBHOOK_SECRET' => Env::has('TELEGRAM_WEBHOOK_SECRET') ? null : bin2hex(random_bytes(32)),
    ]));
} catch (Throwable $e) {
    fail('no se pudo escribir en el .env: ' . $e->getMessage());
}
foreach ($added as $key) {
    echo "{$key} generada y añadida al .env." . ($key === 'APP_KEY' ? ' No la cambies ni la pierdas: cifra las claves de los proyectos.' : '') . "\n";
}
