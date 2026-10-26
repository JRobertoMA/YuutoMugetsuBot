<?php

declare(strict_types=1);

/**
 * php cli/commands.php sync  —  publica en Telegram (setMyCommands) el menú de comandos: los de todos los proyectos
 * (rutas «command» con su descripción) más /vincular y /ayuda. Ejecutar tras cambiar rutas de comandos.
 */

require __DIR__ . '/../includes/cli.php';

use App\Database;
use App\Telegram;

require_database();

if (($argv[1] ?? '') !== 'sync') {
    fwrite(STDERR, "Uso: php cli/commands.php sync\n");
    exit(1);
}

$commands = array_map(
    fn ($r) => ['command' => $r['value'], 'description' => mb_substr((string) $r['description'], 0, 256)],
    Database::all("SELECT value, description FROM routes WHERE kind = 'command' ORDER BY value")
);
$commands[] = ['command' => 'vincular', 'description' => 'Vincular tu cuenta de un proyecto'];
$commands[] = ['command' => 'ayuda', 'description' => 'Lista de comandos'];

$r = Telegram::call('setMyCommands', ['commands' => $commands]);
$r['ok'] ? print(count($commands) . " comandos publicados.\n") : fail($r['description']);
