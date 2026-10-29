<?php
declare(strict_types=1);

/**
 * Herramienta de recuperación por SSH (cuando no hay ningún admin con acceso).
 *
 *   sudo -u casa php bin/casa-admin.php solicitudes
 *   sudo -u casa php bin/casa-admin.php aprobar <id>
 *   sudo -u casa php bin/casa-admin.php hacer-admin <telefono>
 *   sudo -u casa php bin/casa-admin.php miembros
 *   sudo -u casa php bin/casa-admin.php diagnostico   ← ¿la BD y las notificaciones están bien?
 *   sudo -u casa php bin/casa-admin.php probar-push <telefono>
 */

namespace Casa;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require __DIR__ . '/../src/bootstrap.php';

$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
[, $command, $arg] = $argv + [null, null, null];

try {
    switch ($command) {
        case 'solicitudes':
            foreach (Database::all('SELECT id, name, phone, existing_user_id, created_at FROM access_requests ORDER BY id') as $r) {
                printf("#%d  %s  (%s)  %s%s\n", $r['id'], $r['name'], $r['phone'], $r['created_at'],
                    $r['existing_user_id'] ? '  [cambio de celular]' : '');
            }
            break;

        case 'aprobar':
            $summary = AdminController::approveRequest((int) $arg, null);
            Security::audit(null, 'request_approved', $summary);
            echo "$summary\n";
            break;

        case 'hacer-admin':
            $phone = UserController::normalizePhone((string) $arg);
            $n = Database::run("UPDATE users SET role = 'admin' WHERE phone = ?", [$phone]);
            if ($n === 0 && !Database::one('SELECT id FROM users WHERE phone = ?', [$phone])) {
                throw new \RuntimeException("No hay ningún miembro con el teléfono $phone");
            }
            Security::audit(null, 'role_changed', "Consola del servidor hizo administrador al teléfono $phone");
            echo "Listo: $phone es administrador.\n";
            break;

        case 'diagnostico':
            // A qué BD se conecta la API (con el .env real) y qué le falta.
            $info = Database::one('SELECT DATABASE() AS bd, CURRENT_USER() AS usuario, @@hostname AS servidor, VERSION() AS version');
            printf("API v%s\nArchivo .env: %s\nBase de datos: %s\nUsuario: %s\nServidor MariaDB: %s (%s)\n",
                API_VERSION, realpath(ROOT . '/.env') ?: 'NO ENCONTRADO', $info['bd'], $info['usuario'], $info['servidor'], $info['version']);
            $missing = Database::missingSchema();
            if ($missing === []) {
                echo "✓ La base de datos está al día.\n";
            } else {
                echo "✗ Le falta: " . implode(', ', $missing) . "\n";
                echo "  Corre:  sudo mariadb {$info['bd']} < " . realpath(ROOT . '/database/actualizar.sql') . "\n";
                exit(1);
            }

            // Notificaciones: extensiones de PHP, llaves de Firebase, celulares registrados y el timer.
            echo "\nNotificaciones:\n";
            foreach (['curl', 'openssl'] as $ext) {
                echo extension_loaded($ext) ? "✓ PHP $ext\n" : "✗ Falta PHP $ext → sudo apt install php8.2-$ext && sudo systemctl restart php8.2-fpm\n";
            }
            echo Fcm::enabled() ? "✓ Llaves de Firebase en .env\n" : "✗ Sin llaves de Firebase (FCM_PROJECT_ID, FCM_CLIENT_EMAIL, FCM_PRIVATE_KEY)\n";
            $cache = ROOT . '/storage/cache';
            echo is_writable($cache) ? "✓ storage/cache se puede escribir\n" : "✗ storage/cache no se puede escribir → sudo chown casa: $cache\n";
            foreach (Database::all('SELECT name, fcm_token IS NOT NULL AS ok FROM users ORDER BY name') as $u) {
                echo $u['ok'] ? "✓ {$u['name']}: celular registrado\n" : "· {$u['name']}: sin celular registrado (debe abrir la app instalada y permitir notificaciones)\n";
            }
            // Fotos de los perritos: el archivo debe existir y la URL pública debe ser la del dominio.
            $publicUrl = env('PUBLIC_URL') ?? 'SIN DEFINIR';
            echo "\nFotos de los perritos (PUBLIC_URL = $publicUrl):\n";
            if (str_contains($publicUrl, 'tudominio') || $publicUrl === 'SIN DEFINIR') {
                echo "✗ PUBLIC_URL no es tu dominio → pon en .env  PUBLIC_URL=https://casa.espinazodeldiablo.site\n";
            }
            foreach (Database::all('SELECT name, photo_path FROM dogs ORDER BY sort_order') as $d) {
                if (!$d['photo_path']) {
                    echo "· {$d['name']}: sin foto\n";
                } elseif (is_readable(ROOT . "/public/{$d['photo_path']}")) {
                    echo "✓ {$d['name']}: {$d['photo_path']}\n";
                } else {
                    echo "✗ {$d['name']}: falta el archivo public/{$d['photo_path']} → vuelve a subir la foto desde Ajustes\n";
                }
            }
            $uploads = ROOT . '/public/uploads/dogs';
            echo is_writable($uploads) ? "✓ public/uploads/dogs se puede escribir\n" : "✗ public/uploads/dogs no se puede escribir → sudo mkdir -p $uploads && sudo chown -R casa: " . ROOT . "/public/uploads\n";
            echo "\nRecordatorios: revisa con  systemctl list-timers casa-recordatorios.timer\n";
            echo "Probar a un miembro:  php bin/casa-admin.php probar-push <telefono>\n";
            break;

        case 'probar-push':
            $phone = UserController::normalizePhone((string) $arg);
            $u = Database::one('SELECT id, name, fcm_token, notify_prefs FROM users WHERE phone = ?', [$phone]);
            if (!$u || !$u['fcm_token']) {
                throw new \RuntimeException("No hay un miembro con el teléfono $phone y celular registrado para notificaciones.");
            }
            $result = Fcm::sendToMany([$u], '🔔 Prueba desde el servidor', 'Si ves esto, las notificaciones funcionan.', ['type' => 'test'], 'avisos', 'prueba');
            echo ($result[(int) $u['id']] ?? 'Firebase sin configurar') === 'ok'
                ? "✓ Enviada a {$u['name']}\n"
                : "✗ Falló: " . ($result[(int) $u['id']] ?? 'Firebase sin configurar') . "\n";
            break;

        case 'miembros':
            foreach (Database::all('SELECT id, name, phone, role, last_seen_at FROM users ORDER BY name') as $u) {
                printf("#%d  %-20s %s  %-6s  visto: %s\n", $u['id'], $u['name'], $u['phone'], $u['role'], $u['last_seen_at'] ?? '-');
            }
            break;

        default:
            fwrite(STDERR, "Uso: php bin/casa-admin.php solicitudes | aprobar <id> | hacer-admin <telefono> | miembros | diagnostico | probar-push <telefono>\n");
            exit(1);
    }
} catch (HttpError $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
