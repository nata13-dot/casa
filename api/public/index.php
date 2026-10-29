<?php
declare(strict_types=1);

/**
 * Front controller de la API Casa. Nginx manda aquí todo /api/*.
 * Respuesta estándar: { success: bool, data: mixed, error: {code, message, fields?} | null }
 */

namespace Casa;

// Solo en desarrollo con `php -S`: servir las fotos tal cual (en producción lo hace Nginx).
if (PHP_SAPI === 'cli-server' && str_starts_with($_SERVER['REQUEST_URI'], '/uploads/') && is_file(__DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))) {
    return false;
}

require __DIR__ . '/../src/bootstrap.php';

header('X-Content-Type-Options: nosniff');
Http::cors();

$r = new Router();

$r->add('GET', '/health', static fn() => Http::json([
    'ok' => true,
    'version' => API_VERSION,
    'database' => Database::schemaStatus(),
    'time' => date('c'),
]), public: true);
$r->add('POST', '/register', [UserController::class, 'register'], public: true);
$r->add('DELETE', '/register', [UserController::class, 'cancelRequest'], public: true);
$r->add('POST', '/register/transfer', [UserController::class, 'redeemTransfer'], public: true);

$r->add('GET', '/me', [UserController::class, 'me']);
$r->add('PUT', '/me', [UserController::class, 'update']);
$r->add('DELETE', '/me', [UserController::class, 'destroy']);
$r->add('PUT', '/me/push-token', [UserController::class, 'pushToken']);
$r->add('GET', '/me/notifications', [UserController::class, 'notifications']);
$r->add('PUT', '/me/notifications', [UserController::class, 'updateNotifications']);
$r->add('POST', '/me/test-push', [UserController::class, 'testPush']);
$r->add('POST', '/me/transfer-code', [UserController::class, 'createTransferCode']);
$r->add('GET', '/users', [UserController::class, 'index']);

$r->add('GET', '/dogs', [DogController::class, 'index']);
$r->add('POST', '/dogs/{id}/checks', [DogController::class, 'check']);
$r->add('POST', '/dogs/{id}/photo', [DogController::class, 'uploadPhoto']);
$r->add('DELETE', '/dogs/{id}/photo', [DogController::class, 'deletePhoto']);

$r->add('GET', '/announcements', [AnnouncementController::class, 'index']);
$r->add('GET', '/announcements/summary', [AnnouncementController::class, 'summary']);
$r->add('POST', '/announcements', [AnnouncementController::class, 'store']);
$r->add('POST', '/announcements/{id}/ack', [AnnouncementController::class, 'ack']);
$r->add('PUT', '/announcements/{id}/done', [AnnouncementController::class, 'done']);
$r->add('DELETE', '/announcements/{id}', [AnnouncementController::class, 'destroy']);

$r->add('GET', '/admin/requests', [AdminController::class, 'requests']);
$r->add('POST', '/admin/requests/{id}/approve', [AdminController::class, 'approve']);
$r->add('POST', '/admin/requests/{id}/reject', [AdminController::class, 'reject']);
$r->add('POST', '/admin/users/{id}/revoke', [AdminController::class, 'revoke']);
$r->add('PUT', '/admin/users/{id}/role', [AdminController::class, 'setRole']);
$r->add('DELETE', '/admin/users/{id}', [AdminController::class, 'remove']);
$r->add('PUT', '/admin/house-code', [AdminController::class, 'houseCode']);
$r->add('GET', '/admin/audit', [AdminController::class, 'audit']);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = preg_replace('#^/api#', '', $path);

try {
    $r->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $path);
} catch (HttpError $e) {
    Http::error($e);
} catch (\Throwable $e) {
    error_log('[Casa API] ' . $e);
    if (Database::isOutdatedSchemaError($e)) {
        Http::error(new HttpError(503, 'db_outdated',
            'La base de datos del servidor no está actualizada. Hay que correr database/actualizar.sql.'));
    }
    Http::error(new HttpError(500, 'server_error', 'Algo salió mal en el servidor. Intenta de nuevo.'));
}
