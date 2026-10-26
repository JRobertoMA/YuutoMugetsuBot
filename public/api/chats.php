<?php

declare(strict_types=1);

/**
 * GET api/chats.php?days=30 — chats recientes del registro, para «Detectar chat» en los proyectos. Firmado con HMAC.
 * Incluye todos los chats que hablaron con el bot (el bot es compartido): el proyecto decide cuál usar.
 */

require __DIR__ . '/../../includes/bootstrap.php';

use App\Chats;

require_method('GET');
require_project();

json_out(Chats::recent(max(1, min(90, (int) ($_GET['days'] ?? 30)))));
