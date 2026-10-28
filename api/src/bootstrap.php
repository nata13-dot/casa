<?php
declare(strict_types=1);

namespace Casa;

/**
 * Carga .env, configura zona horaria, errores y autoload (sin Composer).
 */

const ROOT = __DIR__ . '/..';

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'Casa\\')) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, 5));
    foreach ([ROOT . "/src/$relative.php", ROOT . '/src/controllers/' . basename($relative) . '.php'] as $file) {
        if (is_file($file)) {
            require $file;
            return;
        }
    }
});

function loadEnv(string $path): void
{
    if (!is_file($path)) {
        return;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        // Una variable real del entorno gana sobre el .env (útil para pruebas y despliegues).
        if (getenv($key) !== false) {
            continue;
        }
        $_ENV[$key] = trim($value, "\"'");
    }
}

function env(string $key, ?string $default = null): ?string
{
    $value = $_ENV[$key] ?? getenv($key);
    return ($value === false || $value === null || $value === '') ? $default : (string) $value;
}

loadEnv(ROOT . '/.env');

date_default_timezone_set(env('APP_TIMEZONE', 'America/Mexico_City'));

$isDev = env('APP_ENV') === 'development';
error_reporting(E_ALL);
ini_set('display_errors', $isDev ? '1' : '0');
ini_set('log_errors', '1');

// Http.php define también HttpError, que el autoload no encontraría por nombre.
require ROOT . '/src/Http.php';
