<?php
declare(strict_types=1);

namespace Casa;

/**
 * Error controlado: se convierte en { success:false, error:{ code, message } }.
 */
final class HttpError extends \RuntimeException
{
    /** @param array<string, string> $fields */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $fields = [],
    ) {
        parent::__construct($message);
    }
}

final class Http
{
    /** @var array<string, mixed>|null */
    private static ?array $jsonBody = null;

    /**
     * URL pública de la API (para armar las URLs de las fotos). Usa PUBLIC_URL del .env;
     * si está vacío o se quedó con el ejemplo, la saca del dominio por el que llegó la petición
     * (Cloudflare Tunnel conserva el Host y avisa https en X-Forwarded-Proto).
     */
    public static function publicUrl(): string
    {
        $configured = rtrim((string) env('PUBLIC_URL', ''), '/');
        if ($configured !== '' && !str_contains($configured, 'tudominio')) {
            return $configured;
        }
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if (!preg_match('/^[a-z0-9.-]+(:\d+)?$/i', $host)) {
            return '';
        }
        $https = ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' || ($_SERVER['HTTPS'] ?? '') === 'on'
            || isset($_SERVER['HTTP_CF_VISITOR']);
        return ($https ? 'https' : 'http') . "://$host";
    }

    public static function json(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(
            ['success' => true, 'data' => $data, 'error' => null],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
        exit;
    }

    /**
     * Responde al cliente y después ejecuta $after (p. ej. enviar notificaciones),
     * para que la app no espere a Firebase.
     */
    public static function jsonThen(mixed $data, int $status, callable $after): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(
            ['success' => true, 'data' => $data, 'error' => null],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        try {
            $after();
        } catch (\Throwable $e) {
            error_log('[Casa API] tarea posterior: ' . $e->getMessage());
        }
        exit;
    }

    public static function error(HttpError $e): never
    {
        http_response_code($e->status);
        header('Content-Type: application/json; charset=utf-8');
        $error = ['code' => $e->errorCode, 'message' => $e->getMessage()];
        if ($e->fields) {
            $error['fields'] = $e->fields;
        }
        echo json_encode(['success' => false, 'data' => null, 'error' => $error], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /** @return array<string, mixed> */
    public static function body(): array
    {
        if (self::$jsonBody === null) {
            $raw = file_get_contents('php://input') ?: '';
            $decoded = $raw === '' ? [] : json_decode($raw, true);
            if (!is_array($decoded)) {
                throw new HttpError(400, 'invalid_json', 'El cuerpo de la petición no es JSON válido.');
            }
            self::$jsonBody = $decoded;
        }
        return self::$jsonBody;
    }

    public static function str(array $src, string $key, int $max, bool $required = true): ?string
    {
        $value = $src[$key] ?? null;
        $value = is_string($value) ? trim(preg_replace('/\s+/u', ' ', $value)) : null;
        if ($value === null || $value === '') {
            if ($required) {
                throw new HttpError(422, 'validation', 'Falta información.', [$key => 'Este campo es obligatorio.']);
            }
            return null;
        }
        if (mb_strlen($value) > $max) {
            throw new HttpError(422, 'validation', 'Texto demasiado largo.', [$key => "Máximo $max caracteres."]);
        }
        return $value;
    }

    public static function cors(): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $allowed = array_map('trim', explode(',', env('CORS_ORIGINS', '') ?? ''));
        if ($origin !== '' && in_array($origin, $allowed, true)) {
            header("Access-Control-Allow-Origin: $origin");
            header('Vary: Origin');
            header('Access-Control-Allow-Headers: Authorization, Content-Type');
            header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
            header('Access-Control-Max-Age: 86400');
        }
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }
}
