<?php

declare(strict_types=1);

/**
 * Autocarga de App\ → src/ (una clase por fichero). Sin Composer: el bot no usa librerías externas.
 * La usan includes/bootstrap.php (web) y cli/*.php.
 */
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

/** Carga el .env y la zona horaria. Común a web y consola. */
function bot_boot(string $baseDir): void
{
    umask(0077);
    App\Env::load($baseDir);
    $tz = (string) App\Env::get('APP_TIMEZONE', '');
    date_default_timezone_set(in_array($tz, timezone_identifiers_list(), true) ? $tz : 'America/Mexico_City');
}
