<?php

declare(strict_types=1);

namespace App;

use InvalidArgumentException;
use RuntimeException;

/**
 * Proyectos conectados (tabla projects) y sus rutas (tabla routes). Se gestionan solo por consola (cli/project.php).
 *
 * Rutas:
 *   command   «pendientes»  → /pendientes va a ese proyecto (minúsculas, cifras y _, máx. 32: regla de Telegram)
 *   callback  «rel»         → callback_data que empiece por «rel:» (también prefijos heredados: «fulfill»)
 *   link      «REL»         → /vincular REL-XXXXXXXX
 */
final class Projects
{
    public const KINDS = ['command', 'callback', 'link'];

    private const ROUTE_RULES = [
        'command'  => '/^[a-z0-9_]{1,32}$/',
        'callback' => '/^[a-z0-9_]{1,16}$/',
        'link'     => '/^[A-Z]{2,8}$/',
    ];

    /** Comandos que resuelve el propio bot: no se pueden asignar a un proyecto. */
    public const BUILTIN_COMMANDS = ['start', 'ayuda', 'help', 'vincular'];

    public static function find(string $name): ?array
    {
        return Database::one('SELECT * FROM projects WHERE name = ?', [$name]);
    }

    /** @return list<array> */
    public static function all(): array
    {
        return Database::all('SELECT * FROM projects ORDER BY name');
    }

    /** Clave HMAC en claro del proyecto. */
    public static function key(array $project): string
    {
        $key = Crypto::decrypt((string) $project['key_enc']);
        if ($key === null) {
            throw new RuntimeException("No se puede descifrar la clave de «{$project['name']}»: ¿cambió APP_KEY? Regénérala con cli/project.php rotate-key.");
        }
        return $key;
    }

    /** @return string la clave nueva en claro (se muestra una sola vez) */
    public static function add(string $name, string $url): string
    {
        self::assertName($name);
        self::assertUrl($url);
        if (self::find($name) !== null) {
            throw new InvalidArgumentException("El proyecto «{$name}» ya existe.");
        }
        $key = self::newKey();
        Database::run('INSERT INTO projects (name, url, key_enc, created_at) VALUES (?, ?, ?, ?)', [$name, $url, Crypto::encrypt($key), time()]);
        return $key;
    }

    public static function setUrl(string $name, string $url): void
    {
        self::assertUrl($url);
        self::require($name);
        Database::run('UPDATE projects SET url = ? WHERE name = ?', [$url, $name]);
    }

    /** @return string la clave nueva en claro */
    public static function rotateKey(string $name): string
    {
        self::require($name);
        $key = self::newKey();
        Database::run('UPDATE projects SET key_enc = ? WHERE name = ?', [Crypto::encrypt($key), $name]);
        return $key;
    }

    public static function setDefaultChat(string $name, ?int $chatId): void
    {
        self::require($name);
        Database::run('UPDATE projects SET default_chat_id = ? WHERE name = ?', [$chatId, $name]);
    }

    public static function remove(string $name): void
    {
        self::require($name);
        Database::run('DELETE FROM projects WHERE name = ?', [$name]);   // sus rutas caen en cascada
    }

    // --- Rutas ------------------------------------------------------------------------------------

    public static function addRoute(string $kind, string $value, string $project, ?string $description = null): void
    {
        if (!in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException('Tipo de ruta no válido: command, callback o link.');
        }
        if (!preg_match(self::ROUTE_RULES[$kind], $value)) {
            throw new InvalidArgumentException(match ($kind) {
                'command'  => 'Comando no válido: minúsculas, cifras y _, hasta 32 caracteres, sin «/».',
                'callback' => 'Prefijo no válido: minúsculas, cifras y _, hasta 16 caracteres.',
                'link'     => 'Prefijo de vinculación no válido: de 2 a 8 mayúsculas.',
            });
        }
        if ($kind === 'command' && in_array($value, self::BUILTIN_COMMANDS, true)) {
            throw new InvalidArgumentException("/{$value} lo resuelve el propio bot.");
        }
        if ($kind === 'command' && ($description === null || trim($description) === '')) {
            throw new InvalidArgumentException('Un comando necesita descripción: aparece en el menú del chat.');
        }
        self::require($project);
        $owner = self::routeOwner($kind, $value);
        if ($owner !== null && $owner !== $project) {
            throw new InvalidArgumentException("La ruta {$kind} «{$value}» ya es de «{$owner}».");
        }
        Database::run(
            'INSERT INTO routes (kind, value, project, description) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE description = VALUES(description)',
            [$kind, $value, $project, $description !== null ? mb_substr(trim($description), 0, 255) : null]
        );
    }

    public static function removeRoute(string $kind, string $value): void
    {
        Database::run('DELETE FROM routes WHERE kind = ? AND value = ?', [$kind, $value]);
    }

    public static function routeOwner(string $kind, string $value): ?string
    {
        $p = Database::value('SELECT project FROM routes WHERE kind = ? AND value = ?', [$kind, $value]);
        return $p === null ? null : (string) $p;
    }

    /** @return list<array{kind:string, value:string, project:string, description:?string}> */
    public static function routes(?string $project = null): array
    {
        return $project === null
            ? Database::all('SELECT * FROM routes ORDER BY project, kind, value')
            : Database::all('SELECT * FROM routes WHERE project = ? ORDER BY kind, value', [$project]);
    }

    /** Prefijos de callback de un proyecto: sus botones solo pueden llevar callback_data con ellos. */
    public static function callbackPrefixes(string $project): array
    {
        return array_column(Database::all("SELECT value FROM routes WHERE kind = 'callback' AND project = ?", [$project]), 'value');
    }

    // --- Validación -------------------------------------------------------------------------------

    public static function assertName(string $name): void
    {
        if (!preg_match('/^[a-z][a-z0-9_-]{1,31}$/', $name)) {
            throw new InvalidArgumentException('Nombre de proyecto no válido: minúsculas, cifras, - y _, de 2 a 32 caracteres.');
        }
    }

    private static function assertUrl(string $url): void
    {
        $parts = parse_url($url);
        $local = in_array($parts['host'] ?? '', ['localhost', '127.0.0.1', 'php-apache'], true);
        if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array($parts['scheme'] ?? '', $local ? ['http', 'https'] : ['https'], true)) {
            throw new InvalidArgumentException('URL no válida: tiene que ser https:// (http:// solo para localhost en local).');
        }
    }

    private static function require(string $name): void
    {
        if (self::find($name) === null) {
            throw new InvalidArgumentException("No existe el proyecto «{$name}».");
        }
    }

    private static function newKey(): string
    {
        return bin2hex(random_bytes(32));
    }
}
