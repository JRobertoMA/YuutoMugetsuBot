<?php

declare(strict_types=1);

/** GET api/me.php — datos del bot (getMe), para que los proyectos muestren «@usuario». Firmado con HMAC. */

require __DIR__ . '/../../includes/bootstrap.php';

use App\Telegram;

require_method('GET');
require_project();

$r = Telegram::call('getMe');
if (!$r['ok']) {
    json_out(null, 502, 'Telegram: ' . $r['description']);
}
json_out(['id' => $r['result']['id'] ?? null, 'username' => $r['result']['username'] ?? '', 'name' => $r['result']['first_name'] ?? '']);
