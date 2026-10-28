<?php
declare(strict_types=1);

/**
 * Herramienta de recuperación por SSH (cuando no hay ningún admin con acceso).
 *
 *   sudo -u casa php bin/casa-admin.php solicitudes
 *   sudo -u casa php bin/casa-admin.php aprobar <id>
 *   sudo -u casa php bin/casa-admin.php hacer-admin <telefono>
 *   sudo -u casa php bin/casa-admin.php miembros
 *   sudo -u casa php bin/casa-admin.php diagnostico   ← ¿la BD está al día?
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
            break;

        case 'miembros':
            foreach (Database::all('SELECT id, name, phone, role, last_seen_at FROM users ORDER BY name') as $u) {
                printf("#%d  %-20s %s  %-6s  visto: %s\n", $u['id'], $u['name'], $u['phone'], $u['role'], $u['last_seen_at'] ?? '-');
            }
            break;

        default:
            fwrite(STDERR, "Uso: php bin/casa-admin.php solicitudes | aprobar <id> | hacer-admin <telefono> | miembros | diagnostico\n");
            exit(1);
    }
} catch (HttpError $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
