<?php

declare(strict_types=1);

// El bot no tiene páginas: la raíz no anuncia nada. Su estado se consulta por consola (cli/webhook.php info).
http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
echo "Not Found\n";
