<?php
declare(strict_types=1);

/**
 * Herramienta de recuperación por SSH (cuando no hay ningún admin con acceso).
 *
 *   sudo -u casa php bin/casa-admin.php solicitudes
 *   sudo -u casa php bin/casa-admin.php aprobar <id>
 *   sudo -u casa php bin/casa-admin.php hacer-admin <telefono>
 *   sudo -u casa php bin/casa-admin.php miembros
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

        case 'miembros':
            foreach (Database::all('SELECT id, name, phone, role, last_seen_at FROM users ORDER BY name') as $u) {
                printf("#%d  %-20s %s  %-6s  visto: %s\n", $u['id'], $u['name'], $u['phone'], $u['role'], $u['last_seen_at'] ?? '-');
            }
            break;

        default:
            fwrite(STDERR, "Uso: php bin/casa-admin.php solicitudes | aprobar <id> | hacer-admin <telefono> | miembros\n");
            exit(1);
    }
} catch (HttpError $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
