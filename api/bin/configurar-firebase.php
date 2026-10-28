<?php
declare(strict_types=1);

/**
 * Copia project_id, client_email y private_key del JSON de la cuenta de servicio
 * de Firebase a api/.env (FCM_PROJECT_ID, FCM_CLIENT_EMAIL, FCM_PRIVATE_KEY) y
 * comprueba con Google que la llave funciona.
 *
 *   php api/bin/configurar-firebase.php ~/Descargas/casa-xxxx-firebase-adminsdk-xxxx.json
 */

namespace Casa;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$jsonPath = $argv[1] ?? '';
$envPath = __DIR__ . '/../.env';

if ($jsonPath === '' || !is_readable($jsonPath)) {
    fwrite(STDERR, "Uso: php api/bin/configurar-firebase.php <ruta-al-archivo.json>\n");
    exit(1);
}
if (!is_writable($envPath)) {
    fwrite(STDERR, "No puedo escribir en $envPath\n");
    exit(1);
}

$json = json_decode((string) file_get_contents($jsonPath), true);
if (!is_array($json) || ($json['type'] ?? '') !== 'service_account') {
    fwrite(STDERR, "Ese archivo no es el JSON de una cuenta de servicio de Google.\n");
    exit(1);
}
foreach (['project_id', 'client_email', 'private_key'] as $field) {
    if (empty($json[$field])) {
        fwrite(STDERR, "Al JSON le falta \"$field\".\n");
        exit(1);
    }
}

$values = [
    'FCM_PROJECT_ID' => $json['project_id'],
    'FCM_CLIENT_EMAIL' => $json['client_email'],
    // Una sola línea, con \n escritos y entre comillas, como la lee Fcm.php.
    'FCM_PRIVATE_KEY' => json_encode($json['private_key'], JSON_UNESCAPED_SLASHES),
];

$env = (string) file_get_contents($envPath);
foreach ($values as $name => $value) {
    $line = "$name=$value";
    $count = 0;
    $env = preg_replace_callback("/^$name=.*$/m", static fn() => $line, $env, 1, $count);
    if ($count === 0) {
        $env = rtrim($env) . "\n$line\n";
    }
}
file_put_contents($envPath, $env);
@chmod($envPath, 0600);

echo "✓ .env actualizado (proyecto: {$json['project_id']})\n";

// Comprobar con Google que la llave firma bien y la cuenta existe.
require __DIR__ . '/../src/bootstrap.php';
foreach ($values as $name => $value) {
    $_ENV[$name] = trim($value, '"');
}
$method = new \ReflectionMethod(Fcm::class, 'accessToken');
$account = (new \ReflectionMethod(Fcm::class, 'account'))->invoke(null);
try {
    @unlink(ROOT . '/storage/cache/fcm_token.json');
    $method->invoke(null, $account);
    echo "✓ Google aceptó la llave: las notificaciones están listas.\n";
} catch (\Throwable $e) {
    echo "✗ Google rechazó la llave: {$e->getMessage()}\n";
    exit(1);
}

if (str_starts_with($json['client_email'], 'firebase-adminsdk')) {
    echo "ℹ Usas la llave del Admin SDK (permisos amplios). Más seguro: cuenta 'casa-push' (SEGURIDAD.md §4).\n";
}
echo "Ya puedes borrar el JSON descargado:  rm '$jsonPath'\n";
