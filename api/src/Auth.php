<?php
declare(strict_types=1);

namespace Casa;

/**
 * Autenticación por dispositivo: al registrarse se emite un token Bearer aleatorio
 * de 256 bits. En BD solo se guarda su SHA-256, así que un volcado de la BD no
 * permite suplantar a nadie.
 *
 * Un token puede estar en dos lugares:
 *  - users.token_hash            → sesión activa.
 *  - access_requests.token_hash  → esperando aprobación de un admin (403 pending_approval).
 */
final class Auth
{
    public static function newToken(): array
    {
        $token = bin2hex(random_bytes(32));
        return [$token, hash('sha256', $token)];
    }

    /** Hash imposible de adivinar: deja inservible el token actual del dispositivo. */
    public static function revokedHash(): string
    {
        return hash('sha256', 'revoked:' . bin2hex(random_bytes(32)));
    }

    /** @return array{id:int, name:string, phone:string, role:string} */
    public static function require(): array
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (!preg_match('/^Bearer\s+([a-f0-9]{64})$/i', $header, $m)) {
            throw new HttpError(401, 'unauthorized', 'Este dispositivo no está registrado.');
        }
        $hash = hash('sha256', strtolower($m[1]));

        $user = Database::one(
            'SELECT id, name, phone, role, last_seen_at, approved_at, created_at FROM users WHERE token_hash = ?',
            [$hash],
        );

        if ($user === null) {
            if (Database::one('SELECT id FROM access_requests WHERE token_hash = ?', [$hash])) {
                throw new HttpError(403, 'pending_approval', 'Tu acceso está esperando la aprobación del administrador.');
            }
            throw new HttpError(401, 'unauthorized', 'Este dispositivo no está registrado.');
        }

        $lastActivity = max(array_map(
            static fn($v) => $v === null ? 0 : (int) strtotime((string) $v),
            [$user['last_seen_at'], $user['approved_at'], $user['created_at']],
        ));
        if ($lastActivity < time() - Security::SESSION_MAX_IDLE_DAYS * 86400) {
            Database::run('UPDATE users SET token_hash = ?, fcm_token = NULL WHERE id = ?', [self::revokedHash(), $user['id']]);
            Security::audit(['id' => (int) $user['id'], 'name' => $user['name']], 'session_expired', "Sesión de {$user['name']} cerrada por inactividad");
            throw new HttpError(401, 'session_expired', 'Tu sesión se cerró porque no abriste la app en mucho tiempo. Regístrate de nuevo.');
        }

        // Solo actualiza "última vez visto" cada 5 min para no escribir en cada petición.
        if ($user['last_seen_at'] === null || strtotime((string) $user['last_seen_at']) < time() - 300) {
            Database::run('UPDATE users SET last_seen_at = NOW() WHERE id = ?', [$user['id']]);
        }

        return [
            'id' => (int) $user['id'],
            'name' => $user['name'],
            'phone' => $user['phone'],
            'role' => $user['role'],
        ];
    }

    public static function requireAdmin(array $user): void
    {
        if ($user['role'] !== 'admin') {
            throw new HttpError(403, 'forbidden', 'Solo un administrador puede hacer esto.');
        }
    }
}
