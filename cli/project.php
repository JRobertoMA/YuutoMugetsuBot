<?php

declare(strict_types=1);

/**
 * Proyectos conectados al bot y sus rutas. Solo por consola.
 *
 *   php cli/project.php add <nombre> <url>              alta; muestra la clave UNA vez (va al .env del proyecto)
 *   php cli/project.php list                            proyectos y rutas
 *   php cli/project.php url <nombre> <url>              cambia la URL de su api/bot.php
 *   php cli/project.php rotate-key <nombre>             clave nueva (la anterior deja de valer al momento)
 *   php cli/project.php default-chat <nombre> <chat|->  chat por defecto («-» lo quita)
 *   php cli/project.php route <nombre> command <cmd> "<descripción>"
 *   php cli/project.php route <nombre> callback <prefijo>
 *   php cli/project.php route <nombre> link <PREFIJO>
 *   php cli/project.php unroute <command|callback|link> <valor>
 *   php cli/project.php remove <nombre>
 *
 * Ejemplo (notificador): add releases https://<subdominio>/api/bot.php · route releases callback rel ·
 * route releases link REL · route releases command pendientes "Repos con versión nueva sin instalar".
 * Después de cambiar comandos: php cli/commands.php sync (para el menú del chat).
 */

require __DIR__ . '/../includes/cli.php';

use App\Projects;

require_database();

$args = array_slice($argv, 1);
$cmd  = $args[0] ?? '';

function show_key(string $name, string $key): void
{
    echo "Clave de «{$name}» (solo se muestra ahora; cópiala al .env del proyecto como BOT_PROJECT_KEY):\n\n  {$key}\n\n";
}

try {
    switch ($cmd) {
        case 'add':
            [$name, $url] = [$args[1] ?? '', $args[2] ?? ''];
            $key = Projects::add($name, $url);
            echo "Proyecto «{$name}» creado.\n";
            show_key($name, $key);
            break;

        case 'list':
            $projects = Projects::all();
            if ($projects === []) {
                echo "No hay proyectos. Alta con: php cli/project.php add <nombre> <url>\n";
                break;
            }
            foreach ($projects as $p) {
                echo "{$p['name']}  →  {$p['url']}" . ($p['default_chat_id'] !== null ? "  (chat por defecto {$p['default_chat_id']})" : '') . "\n";
                foreach (Projects::routes($p['name']) as $r) {
                    printf("    %-9s %-16s %s\n", $r['kind'], $r['kind'] === 'command' ? '/' . $r['value'] : $r['value'], (string) $r['description']);
                }
            }
            break;

        case 'url':
            Projects::setUrl($args[1] ?? '', $args[2] ?? '');
            echo "URL actualizada.\n";
            break;

        case 'rotate-key':
            $name = $args[1] ?? '';
            show_key($name, Projects::rotateKey($name));
            break;

        case 'default-chat':
            $chat = $args[2] ?? '';
            if ($chat !== '-' && !preg_match('/^-?\d+$/', $chat)) {
                fail('Indica el id numérico del chat (o «-» para quitarlo). Lista de chats: php cli/chats.php');
            }
            Projects::setDefaultChat($args[1] ?? '', $chat === '-' ? null : (int) $chat);
            echo "Chat por defecto actualizado.\n";
            break;

        case 'route':
            Projects::addRoute($args[2] ?? '', $args[3] ?? '', $args[1] ?? '', $args[4] ?? null);
            echo "Ruta añadida.\n";
            break;

        case 'unroute':
            Projects::removeRoute($args[1] ?? '', $args[2] ?? '');
            echo "Ruta quitada (si existía).\n";
            break;

        case 'remove':
            Projects::remove($args[1] ?? '');
            echo "Proyecto eliminado, con sus rutas.\n";
            break;

        default:
            fwrite(STDERR, "Uso: php cli/project.php add|list|url|rotate-key|default-chat|route|unroute|remove … (ver cabecera del script)\n");
            exit(1);
    }
} catch (InvalidArgumentException $e) {
    fail($e->getMessage());
}
