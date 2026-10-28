<?php
declare(strict_types=1);

namespace Casa;

/**
 * Cliente mínimo de Firebase Cloud Messaging (API HTTP v1) sin Composer.
 * Firma el JWT de la cuenta de servicio con openssl y cachea el access token
 * en storage/cache durante ~55 minutos.
 */
final class Fcm
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';
    private const CACHE = ROOT . '/storage/cache/fcm_token.json';

    public static function enabled(): bool
    {
        return self::account() !== null;
    }

    /**
     * Credenciales de la cuenta de servicio. Dos formas de darlas en .env:
     *  1. FCM_PROJECT_ID + FCM_CLIENT_EMAIL + FCM_PRIVATE_KEY (valores copiados del JSON).
     *  2. FCM_SERVICE_ACCOUNT = ruta al archivo JSON completo.
     * Si están las dos, gana la 1.
     * @return array{project_id:string, client_email:string, private_key:string, token_uri:string}|null
     */
    private static function account(): ?array
    {
        $projectId = env('FCM_PROJECT_ID');
        $email = env('FCM_CLIENT_EMAIL');
        $key = env('FCM_PRIVATE_KEY');
        if ($projectId && $email && $key) {
            return [
                'project_id' => $projectId,
                'client_email' => $email,
                'private_key' => self::unescapeKey($key),
                'token_uri' => 'https://oauth2.googleapis.com/token',
            ];
        }

        $path = env('FCM_SERVICE_ACCOUNT');
        if ($path === null || !is_readable($path)) {
            return null;
        }
        $json = json_decode((string) file_get_contents($path), true);
        return is_array($json) && isset($json['project_id'], $json['client_email'], $json['private_key'])
            ? $json + ['token_uri' => 'https://oauth2.googleapis.com/token']
            : null;
    }

    /**
     * Envía la misma notificación a varios usuarios.
     * @param array<int, array{id:int|string, fcm_token:string}> $recipients
     * @param array<string, string> $data
     */
    public static function sendToMany(array $recipients, string $title, string $body, array $data, bool $urgent): void
    {
        $account = self::account();
        if ($account === null || !$recipients) {
            return;
        }

        try {
            $accessToken = self::accessToken($account);
        } catch (\Throwable $e) {
            error_log('[FCM] No se pudo obtener access token: ' . $e->getMessage());
            return;
        }

        $url = "https://fcm.googleapis.com/v1/projects/{$account['project_id']}/messages:send";
        $multi = curl_multi_init();
        $handles = [];

        foreach ($recipients as $r) {
            $payload = [
                'message' => [
                    'token' => $r['fcm_token'],
                    'notification' => ['title' => $title, 'body' => $body],
                    'data' => $data,
                    'android' => [
                        'priority' => $urgent ? 'HIGH' : 'NORMAL',
                        'notification' => [
                            // Canales creados por la app (src/services/push.ts).
                            'channel_id' => $urgent ? 'avisos_urgentes' : 'avisos',
                            'sound' => 'default',
                            'default_vibrate_timings' => true,
                        ],
                    ],
                ],
            ];
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => ["Authorization: Bearer $accessToken", 'Content-Type: application/json'],
                CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
            ]);
            curl_multi_add_handle($multi, $ch);
            $handles[(int) $r['id']] = $ch;
        }

        do {
            $status = curl_multi_exec($multi, $running);
            if ($running) {
                curl_multi_select($multi);
            }
        } while ($running && $status === CURLM_OK);

        foreach ($handles as $userId => $ch) {
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($code === 404 || $code === 400) {
                // Token caducado o app desinstalada: lo limpiamos para no reintentar.
                $response = (string) curl_multi_getcontent($ch);
                if (str_contains($response, 'UNREGISTERED') || str_contains($response, 'INVALID_ARGUMENT')) {
                    Database::run('UPDATE users SET fcm_token = NULL WHERE id = ?', [$userId]);
                }
            } elseif ($code !== 200) {
                error_log("[FCM] Usuario $userId → HTTP $code: " . curl_multi_getcontent($ch));
            }
            curl_multi_remove_handle($multi, $ch);
        }
        curl_multi_close($multi);
    }

    /**
     * En .env la llave va en una sola línea, tal como aparece en el JSON
     * (con \n y a veces \/ escritos). Se decodifica como cadena JSON.
     */
    private static function unescapeKey(string $key): string
    {
        $decoded = json_decode('"' . $key . '"');
        return is_string($decoded) ? $decoded : str_replace('\n', "\n", $key);
    }

    /** @param array<string, string> $account */
    private static function accessToken(array $account): string
    {
        if (is_readable(self::CACHE)) {
            $cached = json_decode((string) file_get_contents(self::CACHE), true);
            if (is_array($cached) && ($cached['expires_at'] ?? 0) > time() + 60) {
                return $cached['access_token'];
            }
        }

        $now = time();
        $b64 = static fn(string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $header = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = $b64(json_encode([
            'iss' => $account['client_email'],
            'scope' => self::SCOPE,
            'aud' => $account['token_uri'],
            'iat' => $now,
            'exp' => $now + 3600,
        ]));
        if (!openssl_sign("$header.$claims", $signature, $account['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Firma JWT fallida; revisa private_key.');
        }
        $jwt = "$header.$claims." . $b64($signature);

        $ch = curl_init($account['token_uri']);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $response = json_decode((string) curl_exec($ch), true);

        if (!isset($response['access_token'])) {
            throw new \RuntimeException('Respuesta OAuth inválida: ' . json_encode($response));
        }

        file_put_contents(self::CACHE, json_encode([
            'access_token' => $response['access_token'],
            'expires_at' => $now + (int) ($response['expires_in'] ?? 3600) - 300,
        ]), LOCK_EX);
        @chmod(self::CACHE, 0600);

        return $response['access_token'];
    }
}
