<?php
declare(strict_types=1);

namespace Casa;

/** Rutas /admin/*: todas exigen rol admin. */
final class AdminController
{
    /** GET /admin/requests — solicitudes de acceso pendientes. */
    public static function requests(array $p, array $user): void
    {
        Auth::requireAdmin($user);
        $rows = Database::all(
            'SELECT r.id, r.name, r.phone, r.platform, r.created_at, r.existing_user_id, u.name AS existing_name
               FROM access_requests r LEFT JOIN users u ON u.id = r.existing_user_id
              ORDER BY r.created_at DESC',
        );
        Http::json(array_map(static fn(array $r) => [
            'id' => (int) $r['id'],
            'name' => $r['name'],
            'phone' => $r['phone'],
            'platform' => $r['platform'],
            'created_at' => $r['created_at'],
            // Si no es null, es un cambio de celular de un miembro existente.
            'replaces' => $r['existing_user_id'] === null ? null : ['id' => (int) $r['existing_user_id'], 'name' => $r['existing_name']],
        ], $rows));
    }

    /** POST /admin/requests/{id}/approve */
    public static function approve(array $p, array $admin): void
    {
        Auth::requireAdmin($admin);
        $summary = self::approveRequest($p['id'], $admin);
        Security::audit($admin, 'request_approved', $summary);
        Http::json(['approved' => true]);
    }

    /**
     * Aprueba una solicitud. $admin = null cuando se usa desde la consola (bin/casa-admin.php).
     * Devuelve el texto para el historial.
     */
    public static function approveRequest(int $requestId, ?array $admin): string
    {
        $by = $admin['name'] ?? 'Consola del servidor';
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $req = Database::one('SELECT * FROM access_requests WHERE id = ? FOR UPDATE', [$requestId]);
            if (!$req) {
                throw new HttpError(404, 'not_found', 'Esa solicitud ya no existe.');
            }

            // Si este celular estaba ligado a otra cuenta, esa sesión se cierra.
            Database::run(
                'UPDATE users SET device_id = CONCAT(\'liberado:\', id, \':\', ?), token_hash = ?, fcm_token = NULL WHERE device_id = ?',
                [bin2hex(random_bytes(6)), Auth::revokedHash(), $req['device_id']],
            );

            if ($req['existing_user_id'] !== null) {
                // Cambio de celular: la cuenta pasa al dispositivo nuevo; el anterior queda desconectado.
                Database::run(
                    'UPDATE users SET name = ?, device_id = ?, token_hash = ?, platform = ?, fcm_token = NULL,
                                      approved_by = ?, approved_at = NOW(), last_seen_at = NOW()
                      WHERE id = ?',
                    [$req['name'], $req['device_id'], $req['token_hash'], $req['platform'], $admin['id'] ?? null, $req['existing_user_id']],
                );
                $summary = "$by aprobó el celular nuevo de {$req['name']}";
            } else {
                if (Database::one('SELECT id FROM users WHERE phone = ?', [$req['phone']])) {
                    throw new HttpError(409, 'phone_taken', 'Ese número ya pertenece a un miembro. Rechaza esta solicitud.');
                }
                Database::run(
                    "INSERT INTO users (name, phone, role, device_id, token_hash, platform, approved_by, approved_at, last_seen_at)
                     VALUES (?, ?, 'member', ?, ?, ?, ?, NOW(), NOW())",
                    [$req['name'], $req['phone'], $req['device_id'], $req['token_hash'], $req['platform'], $admin['id'] ?? null],
                );
                $summary = "$by aprobó a {$req['name']}";
            }
            Database::run('DELETE FROM access_requests WHERE id = ?', [$req['id']]);
            $pdo->commit();
            return $summary;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** POST /admin/requests/{id}/reject */
    public static function reject(array $p, array $admin): void
    {
        Auth::requireAdmin($admin);
        $req = Database::one('SELECT name FROM access_requests WHERE id = ?', [$p['id']]);
        if ($req) {
            Database::run('DELETE FROM access_requests WHERE id = ?', [$p['id']]);
            Security::audit($admin, 'request_rejected', "{$admin['name']} rechazó la solicitud de {$req['name']}");
        }
        Http::json(['rejected' => true]);
    }

    /** POST /admin/users/{id}/revoke — "celular perdido": cierra su sesión, conserva al miembro. */
    public static function revoke(array $p, array $admin): void
    {
        Auth::requireAdmin($admin);
        $target = self::findUser($p['id']);
        Database::run('UPDATE users SET token_hash = ?, fcm_token = NULL WHERE id = ?', [Auth::revokedHash(), $target['id']]);
        Security::audit($admin, 'session_revoked', "{$admin['name']} cerró la sesión del celular de {$target['name']}");
        Http::json(['revoked' => true]);
    }

    /** DELETE /admin/users/{id} — eliminar miembro. */
    public static function remove(array $p, array $admin): void
    {
        Auth::requireAdmin($admin);
        $target = self::findUser($p['id']);
        self::assertNotLastAdmin($target['id'], 'No puedes eliminar al único administrador.');
        Database::run('DELETE FROM users WHERE id = ?', [$target['id']]);
        Security::audit($admin, 'user_removed', "{$admin['name']} eliminó a {$target['name']}");
        Http::json(['deleted' => true]);
    }

    /** PUT /admin/users/{id}/role  { role: "admin"|"member" } */
    public static function setRole(array $p, array $admin): void
    {
        Auth::requireAdmin($admin);
        $role = Http::body()['role'] ?? null;
        if (!in_array($role, ['admin', 'member'], true)) {
            throw new HttpError(422, 'validation', 'Rol no válido.');
        }
        $target = self::findUser($p['id']);
        if ($role === 'member') {
            self::assertNotLastAdmin($target['id'], 'Debe quedar al menos un administrador.');
        }
        Database::run('UPDATE users SET role = ? WHERE id = ?', [$role, $target['id']]);
        Security::audit($admin, 'role_changed', $role === 'admin'
            ? "{$admin['name']} hizo administrador a {$target['name']}"
            : "{$admin['name']} quitó de administrador a {$target['name']}");
        Http::json(['role' => $role]);
    }

    /** PUT /admin/house-code  { code } */
    public static function houseCode(array $p, array $admin): void
    {
        Auth::requireAdmin($admin);
        $code = Http::str(Http::body(), 'code', 60);
        if (mb_strlen($code) < 6) {
            throw new HttpError(422, 'validation', 'El código debe tener al menos 6 caracteres.', ['code' => 'Mínimo 6 caracteres.']);
        }
        Security::setHouseCode($code);
        Security::audit($admin, 'house_code_changed', "{$admin['name']} cambió el código de la casa");
        Http::json(['changed' => true]);
    }

    /** GET /admin/audit — últimos 150 eventos. */
    public static function audit(array $p, array $admin): void
    {
        Auth::requireAdmin($admin);
        $rows = Database::all('SELECT id, actor_name, action, summary, ip, created_at FROM audit_log ORDER BY id DESC LIMIT 150');
        Http::json(array_map(static fn(array $r) => ['id' => (int) $r['id']] + $r, $rows));
    }

    public static function assertNotLastAdmin(int $userId, string $message): void
    {
        $row = Database::one(
            "SELECT SUM(role = 'admin') AS admins, SUM(role = 'admin' AND id = ?) AS is_admin FROM users",
            [$userId],
        );
        if ((int) $row['is_admin'] === 1 && (int) $row['admins'] <= 1) {
            throw new HttpError(409, 'last_admin', $message);
        }
    }

    /** @return array{id:int, name:string} */
    private static function findUser(int $id): array
    {
        $u = Database::one('SELECT id, name FROM users WHERE id = ?', [$id]);
        if (!$u) {
            throw new HttpError(404, 'not_found', 'Ese miembro ya no existe.');
        }
        return ['id' => (int) $u['id'], 'name' => $u['name']];
    }
}
