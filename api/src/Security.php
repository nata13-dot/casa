<?php
declare(strict_types=1);

namespace Casa;

final class Security
{
    /** Días sin abrir la app antes de cerrar la sesión del dispositivo. */
    public const SESSION_MAX_IDLE_DAYS = 180;

    /** Máximo de códigos incorrectos por IP en 15 minutos. */
    private const MAX_BAD_CODES = 5;
    /** Máximo de solicitudes de acceso por IP en 1 hora. */
    private const MAX_REQUESTS = 5;

    /**
     * IP real del cliente. Nginx solo escucha en localhost y el único que llega
     * es cloudflared, así que CF-Connecting-IP es confiable aquí. Si algún día
     * expones Nginx directo a internet, quita esa cabecera.
     */
    public static function clientIp(): string
    {
        $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
    }

    /** Lanza 429 si la IP superó el límite de intentos de ese tipo. */
    public static function assertNotLimited(string $kind): void
    {
        [$max, $minutes] = $kind === 'bad_code' ? [self::MAX_BAD_CODES, 15] : [self::MAX_REQUESTS, 60];
        $row = Database::one(
            'SELECT COUNT(*) AS n FROM auth_attempts WHERE ip = ? AND kind = ? AND created_at > NOW() - INTERVAL ? MINUTE',
            [self::clientIp(), $kind, $minutes],
        );
        if ((int) $row['n'] >= $max) {
            // Un solo registro por IP y ventana, para que un ataque no llene el historial.
            $logged = Database::one(
                "SELECT id FROM audit_log WHERE action = 'rate_limited' AND ip = ? AND created_at > NOW() - INTERVAL ? MINUTE",
                [self::clientIp(), $minutes],
            );
            if (!$logged) {
                self::audit(null, 'rate_limited', "Bloqueo temporal por demasiados intentos ($kind)");
            }
            throw new HttpError(429, 'too_many_attempts', "Demasiados intentos. Espera $minutes minutos e inténtalo de nuevo.");
        }
    }

    public static function recordAttempt(string $kind): void
    {
        Database::run('INSERT INTO auth_attempts (ip, kind, created_at) VALUES (?, ?, NOW())', [self::clientIp(), $kind]);
        // Limpieza barata y ocasional, sin cron.
        if (random_int(1, 50) === 1) {
            Database::run('DELETE FROM auth_attempts WHERE created_at < NOW() - INTERVAL 1 DAY');
            Database::run('DELETE FROM audit_log WHERE created_at < NOW() - INTERVAL 1 YEAR');
        }
    }

    /** @param array{id:int, name:string}|null $actor */
    public static function audit(?array $actor, string $action, string $summary): void
    {
        Database::run(
            'INSERT INTO audit_log (actor_id, actor_name, action, summary, ip, created_at) VALUES (?, ?, ?, ?, ?, NOW())',
            [$actor['id'] ?? null, $actor['name'] ?? null, $action, mb_substr($summary, 0, 255), self::clientIp()],
        );
    }

    /**
     * El código vive en la BD como hash (password_hash) para poder cambiarlo desde la app.
     * Si aún no hay uno guardado, se usa HOUSE_CODE del .env como valor inicial.
     */
    public static function houseCodeMatches(string $code): bool
    {
        if ($code === '') {
            return false;
        }
        $row = Database::one("SELECT value FROM settings WHERE name = 'house_code_hash'");
        if ($row !== null) {
            return password_verify($code, $row['value']);
        }
        $initial = (string) env('HOUSE_CODE', '');
        return $initial !== '' && hash_equals($initial, $code);
    }

    public static function setHouseCode(string $code): void
    {
        Database::run(
            "INSERT INTO settings (name, value) VALUES ('house_code_hash', ?) ON DUPLICATE KEY UPDATE value = VALUES(value)",
            [password_hash($code, PASSWORD_DEFAULT)],
        );
    }
}
