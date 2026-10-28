<?php
declare(strict_types=1);

namespace Casa;

final class UserController
{
    /**
     * POST /register  (pública)
     * { name, phone, device_id, house_code, platform? }
     *   → 201 { status: "active",  token, user }   el primer registro (queda como admin)
     *   → 202 { status: "pending", token }         todos los demás: esperan aprobación
     *
     * Un teléfono que ya existe (cambio de celular) también requiere aprobación,
     * así nadie puede quedarse con la cuenta de otro solo con saber su número.
     */
    public static function register(): void
    {
        $in = Http::body();

        Security::assertNotLimited('bad_code');
        if (!Security::houseCodeMatches((string) ($in['house_code'] ?? ''))) {
            Security::recordAttempt('bad_code');
            Security::audit(null, 'bad_house_code', 'Intento de registro con código incorrecto');
            throw new HttpError(403, 'bad_house_code', 'El código de la casa no es correcto.', [
                'house_code' => 'Pide el código a quien administra la app.',
            ]);
        }

        $name = Http::str($in, 'name', 60);
        $phone = self::normalizePhone((string) ($in['phone'] ?? ''));
        $deviceId = Http::str($in, 'device_id', 100);
        $platform = Http::str($in, 'platform', 20, false);
        [$token, $hash] = Auth::newToken();

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            // Primer registro de todos: se vuelve administrador sin aprobación.
            if (Database::one('SELECT id FROM users LIMIT 1 FOR UPDATE') === null) {
                Database::run(
                    "INSERT INTO users (name, phone, role, device_id, token_hash, platform, approved_at, last_seen_at)
                     VALUES (?, ?, 'admin', ?, ?, ?, NOW(), NOW())",
                    [$name, $phone, $deviceId, $hash, $platform],
                );
                $id = Database::lastId();
                $pdo->commit();
                Security::audit(['id' => $id, 'name' => $name], 'first_admin', "$name se registró como primer administrador");
                Http::json(['status' => 'active', 'token' => $token, 'user' => [
                    'id' => $id, 'name' => $name, 'phone' => $phone, 'role' => 'admin',
                ]], 201);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        Security::assertNotLimited('request');
        $existing = Database::one('SELECT id, name FROM users WHERE phone = ?', [$phone]);

        // Una sola solicitud viva por dispositivo y por teléfono.
        Database::run('DELETE FROM access_requests WHERE device_id = ? OR phone = ?', [$deviceId, $phone]);
        Database::run(
            'INSERT INTO access_requests (name, phone, device_id, token_hash, platform, existing_user_id, ip, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())',
            [$name, $phone, $deviceId, $hash, $platform, $existing['id'] ?? null, Security::clientIp()],
        );
        Security::recordAttempt('request');

        $summary = $existing
            ? "$name pidió entrar desde un celular nuevo con el número de {$existing['name']}"
            : "$name pidió unirse a la casa";
        Security::audit(null, 'access_requested', $summary);

        Http::jsonThen(['status' => 'pending', 'token' => $token], 202, static function () use ($summary) {
            Fcm::sendToMany(
                Database::all("SELECT id, fcm_token FROM users WHERE role = 'admin' AND fcm_token IS NOT NULL"),
                '🔐 Solicitud de acceso',
                "$summary. Ábrela en Ajustes › Administración.",
                ['type' => 'access_request'],
                false,
            );
        });
    }

    /** GET /me */
    public static function me(array $p, array $user): void
    {
        Http::json($user);
    }

    /** PUT /me  { name?, phone? } */
    public static function update(array $p, array $user): void
    {
        $in = Http::body();
        $name = Http::str($in, 'name', 60, false) ?? $user['name'];
        $phone = isset($in['phone']) ? self::normalizePhone((string) $in['phone']) : $user['phone'];

        $taken = Database::one('SELECT id FROM users WHERE phone = ? AND id <> ?', [$phone, $user['id']]);
        if ($taken) {
            throw new HttpError(409, 'phone_taken', 'Ese número ya lo usa otra persona.', ['phone' => 'Número ya registrado.']);
        }

        Database::run('UPDATE users SET name = ?, phone = ? WHERE id = ?', [$name, $phone, $user['id']]);
        if ($phone !== $user['phone'] || $name !== $user['name']) {
            Security::audit($user, 'profile_updated', "{$user['name']} cambió sus datos" . ($phone !== $user['phone'] ? ' (incluye teléfono)' : ''));
        }
        Http::json(['id' => $user['id'], 'name' => $name, 'phone' => $phone, 'role' => $user['role']]);
    }

    /** PUT /me/push-token  { token: string|null } */
    public static function pushToken(array $p, array $user): void
    {
        $token = Http::str(Http::body(), 'token', 255, false);
        if ($token !== null) {
            // Un token FCM pertenece a una sola instalación.
            Database::run('UPDATE users SET fcm_token = NULL WHERE fcm_token = ? AND id <> ?', [$token, $user['id']]);
        }
        Database::run('UPDATE users SET fcm_token = ? WHERE id = ?', [$token, $user['id']]);
        Http::json(['registered' => $token !== null]);
    }

    /** DELETE /me  — "Salir de la app" en este dispositivo. */
    public static function destroy(array $p, array $user): void
    {
        AdminController::assertNotLastAdmin($user['id'], 'Eres el único administrador. Nombra a otro antes de salir.');
        Database::run('DELETE FROM users WHERE id = ?', [$user['id']]);
        Security::audit($user, 'user_left', "{$user['name']} salió de la app");
        Http::json(['deleted' => true]);
    }

    /** DELETE /register — cancelar la propia solicitud pendiente (autenticado con el token de solicitud). */
    public static function cancelRequest(): void
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/^Bearer\s+([a-f0-9]{64})$/i', $header, $m)) {
            Database::run('DELETE FROM access_requests WHERE token_hash = ?', [hash('sha256', strtolower($m[1]))]);
        }
        Http::json(['deleted' => true]);
    }

    /** GET /users — lista de contactos de la casa. */
    public static function index(array $p, array $user): void
    {
        $rows = Database::all('SELECT id, name, phone, role, last_seen_at FROM users ORDER BY name');
        Http::json(array_map(static fn(array $r) => [
            'id' => (int) $r['id'],
            'name' => $r['name'],
            'phone' => $r['phone'],
            'role' => $r['role'],
            'is_me' => (int) $r['id'] === $user['id'],
            'last_seen_at' => $r['last_seen_at'],
        ], $rows));
    }

    /**
     * Acepta "+52 55 1234 5678", "55-1234-5678", etc. Guarda solo dígitos con lada de país.
     * Un número de 10 dígitos sin lada se asume de México (52).
     */
    public static function normalizePhone(string $raw): string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if (strlen($digits) === 10) {
            $digits = '52' . $digits;
        }
        // Formato antiguo de celulares mexicanos: 52 1 XXXXXXXXXX
        if (strlen($digits) === 13 && str_starts_with($digits, '521')) {
            $digits = '52' . substr($digits, 3);
        }
        if (strlen($digits) < 11 || strlen($digits) > 15) {
            throw new HttpError(422, 'validation', 'Revisa el número de celular.', [
                'phone' => 'Escribe los 10 dígitos de tu celular.',
            ]);
        }
        return $digits;
    }
}
