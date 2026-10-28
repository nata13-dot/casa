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
        $path = env('FCM_SERVICE_ACCOUNT');
        return $path !== null && is_readable($path);
    }

    /**
     * Envía la misma notificación a varios usuarios.
     * @param array<int, array{id:int|string, fcm_token:string}> $recipients
     * @param array<string, string> $data
     */
    public static function sendToMany(array $recipients, string $title, string $body, array $data, bool $urgent): void
    {
        if (!self::enabled() || !$recipients) {
            return;
        }

        try {
            $account = json_decode((string) file_get_contents((string) env('FCM_SERVICE_ACCOUNT')), true, flags: JSON_THROW_ON_ERROR);
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
