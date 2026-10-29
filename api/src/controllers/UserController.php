<?php
declare(strict_types=1);

namespace Casa;

final class UserController
{
    /** Minutos que dura un código para pasar la cuenta a otro dispositivo. */
    private const TRANSFER_MINUTES = 10;

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
                Database::all("SELECT id, fcm_token, notify_prefs FROM users WHERE role = 'admin' AND fcm_token IS NOT NULL"),
                '🔐 Solicitud de acceso',
                "$summary. Ábrela en Ajustes › Administración.",
                ['type' => 'access_request'],
                'avisos',
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

    /** GET /me/notifications — tonos por tipo y si recibe recordatorios de los perritos. */
    public static function notifications(array $p, array $user): void
    {
        $row = Database::one('SELECT notify_prefs FROM users WHERE id = ?', [$user['id']]);
        Http::json(Fcm::prefs($row['notify_prefs'] ?? null));
    }

    /** PUT /me/notifications  { tones?: {avisos?, urgentes?, perritos?}, dog_reminders?: bool } */
    public static function updateNotifications(array $p, array $user): void
    {
        $in = Http::body();
        $row = Database::one('SELECT notify_prefs FROM users WHERE id = ?', [$user['id']]);
        $prefs = Fcm::prefs($row['notify_prefs'] ?? null);

        foreach ((array) ($in['tones'] ?? []) as $kind => $tone) {
            if (!isset(Fcm::DEFAULT_TONES[$kind]) || !in_array($tone, Fcm::TONES, true)) {
                throw new HttpError(422, 'validation', 'Tono no válido.', ['tones' => "Tono no válido para $kind."]);
            }
            $prefs['tones'][$kind] = $tone;
        }
        if (array_key_exists('dog_reminders', $in)) {
            $prefs['dog_reminders'] = (bool) $in['dog_reminders'];
        }

        Database::run('UPDATE users SET notify_prefs = ? WHERE id = ?', [json_encode($prefs), $user['id']]);
        Http::json($prefs);
    }

    /** POST /me/test-push  { kind?: avisos|urgentes|perritos } — manda una notificación de prueba a este celular. */
    public static function testPush(array $p, array $user): void
    {
        if (!Fcm::enabled()) {
            throw new HttpError(503, 'push_disabled',
                'El servidor no tiene las llaves de Firebase. Revisa FCM_PROJECT_ID, FCM_CLIENT_EMAIL y FCM_PRIVATE_KEY en el .env.');
        }
        $row = Database::one('SELECT id, fcm_token, notify_prefs FROM users WHERE id = ?', [$user['id']]);
        if (!$row['fcm_token']) {
            throw new HttpError(409, 'no_push_token',
                'Este celular todavía no está registrado para notificaciones. Cierra la app por completo y vuelve a abrirla.');
        }
        $kind = (string) (Http::body()['kind'] ?? 'avisos');
        if (!isset(Fcm::DEFAULT_TONES[$kind])) {
            $kind = 'avisos';
        }
        $result = Fcm::sendToMany([$row], '🔔 Prueba de notificación', 'Si ves y escuchas esto, las notificaciones funcionan.',
            ['type' => 'test'], $kind, 'prueba');
        if (($result[$user['id']] ?? '') !== 'ok') {
            throw new HttpError(502, 'push_failed', 'Firebase no pudo enviarla: ' . ($result[$user['id']] ?? 'sin respuesta'));
        }
        Http::json(['sent' => true]);
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

    /**
     * POST /me/transfer-code → { code, expires_at }
     * Código de 8 dígitos para pasar ESTA cuenta a otro dispositivo sin aprobación del admin:
     * tenerlo demuestra que se tiene el dispositivo actual. Dura 10 minutos y es de un solo uso.
     */
    public static function createTransferCode(array $p, array $user): void
    {
        $code = sprintf('%08d', random_int(0, 99_999_999));
        Database::run(
            'REPLACE INTO device_transfers (user_id, code_hash, expires_at, created_at)
             VALUES (?, ?, NOW() + INTERVAL ' . self::TRANSFER_MINUTES . ' MINUTE, NOW())',
            [$user['id'], hash('sha256', $code)],
        );
        $row = Database::one('SELECT expires_at FROM device_transfers WHERE user_id = ?', [$user['id']]);
        Security::audit($user, 'transfer_code_created', "{$user['name']} generó un código para pasar su cuenta a otro dispositivo");
        Http::json(['code' => $code, 'expires_at' => $row['expires_at']], 201);
    }

    /**
     * POST /register/transfer  (pública)  { code, device_id, platform? } → { status: "active", token, user }
     * La cuenta pasa a este dispositivo y el anterior queda desconectado.
     */
    public static function redeemTransfer(): void
    {
        $in = Http::body();
        Security::assertNotLimited('bad_code');

        $code = preg_replace('/\D+/', '', (string) ($in['code'] ?? '')) ?? '';
        $deviceId = Http::str($in, 'device_id', 100);
        $platform = Http::str($in, 'platform', 20, false);

        Database::run('DELETE FROM device_transfers WHERE expires_at < NOW()');
        $transfer = strlen($code) === 8
            ? Database::one('SELECT user_id FROM device_transfers WHERE code_hash = ?', [hash('sha256', $code)])
            : null;
        if ($transfer === null) {
            Security::recordAttempt('bad_code');
            Security::audit(null, 'bad_transfer_code', 'Intento de pasar una cuenta con un código incorrecto o vencido');
            throw new HttpError(403, 'bad_transfer_code', 'El código no es correcto o ya venció. Genera otro en el dispositivo anterior.', [
                'code' => 'Revisa los 8 números o genera un código nuevo.',
            ]);
        }

        [$token, $hash] = Auth::newToken();
        $userId = (int) $transfer['user_id'];
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            // Si este dispositivo tenía otra cuenta o una solicitud pendiente, se liberan.
            Database::run(
                'UPDATE users SET device_id = CONCAT(\'liberado:\', id, \':\', ?), token_hash = ?, fcm_token = NULL WHERE device_id = ? AND id <> ?',
                [bin2hex(random_bytes(6)), Auth::revokedHash(), $deviceId, $userId],
            );
            Database::run('DELETE FROM access_requests WHERE device_id = ?', [$deviceId]);
            Database::run(
                'UPDATE users SET device_id = ?, token_hash = ?, platform = ?, fcm_token = NULL, last_seen_at = NOW() WHERE id = ?',
                [$deviceId, $hash, $platform, $userId],
            );
            Database::run('DELETE FROM device_transfers WHERE user_id = ?', [$userId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $user = Database::one('SELECT id, name, phone, role FROM users WHERE id = ?', [$userId]);
        $user['id'] = (int) $user['id'];
        Security::audit($user, 'device_transferred', "{$user['name']} pasó su cuenta a otro dispositivo con código");
        Http::json(['status' => 'active', 'token' => $token, 'user' => $user]);
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
