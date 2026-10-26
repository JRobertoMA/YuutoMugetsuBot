<?php

declare(strict_types=1);

/** php cli/chats.php [días]  —  chats del registro (para elegir el chat por defecto de un proyecto). */

require __DIR__ . '/../includes/cli.php';

use App\Chats;

require_database();

$chats = Chats::recent(max(1, (int) ($argv[1] ?? 90)), 200);
if ($chats === []) {
    echo "No hay chats registrados. Escribe /start al bot (o añádelo a un grupo) y vuelve a mirar.\n";
}
foreach ($chats as $c) {
    printf("%-16s %-11s %s%s · visto %s\n", $c['id'], $c['type'], $c['title'], $c['username'] ? ' (@' . $c['username'] . ')' : '', date('Y-m-d H:i', $c['last_seen']));
}
