<?php
declare(strict_types=1);

namespace Casa;

final class DogController
{
    private const MAX_PHOTO_BYTES = 6 * 1024 * 1024;
    private const MIME_EXT = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    /**
     * GET /dogs → perros con sus tareas del día (comida mañana/noche, agua, pastillas…)
     * y si ya se hicieron HOY. Se reinicia solo cada día porque se filtra por check_date.
     * Las tareas de cada perro viven en dog_tasks.
     */
    public static function index(): void
    {
        Http::json(['date' => date('Y-m-d'), 'dogs' => self::todayStatus()]);
    }

    /** POST /dogs/{id}/checks  { task_id: int, done: bool } */
    public static function check(array $p, array $user): void
    {
        $in = Http::body();
        $taskId = $in['task_id'] ?? null;
        if (!is_int($taskId) || !is_bool($in['done'] ?? null)) {
            throw new HttpError(422, 'validation', 'Datos inválidos.', ['task_id' => 'Envía task_id (número) y done true/false.']);
        }
        $task = Database::one('SELECT id FROM dog_tasks WHERE id = ? AND dog_id = ? AND active = 1', [$taskId, $p['id']]);
        if (!$task) {
            throw new HttpError(404, 'not_found', 'Esa tarea no existe para este perrito. Actualiza la app.');
        }

        if ($in['done']) {
            // INSERT IGNORE: si dos personas marcan a la vez, gana la primera.
            Database::run(
                'INSERT IGNORE INTO dog_checks (task_id, check_date, user_id, checked_at) VALUES (?, CURDATE(), ?, NOW())',
                [$taskId, $user['id']],
            );
        } else {
            Database::run('DELETE FROM dog_checks WHERE task_id = ? AND check_date = CURDATE()', [$taskId]);
        }

        Http::json(['date' => date('Y-m-d'), 'dogs' => self::todayStatus()]);
    }

    /** POST /dogs/{id}/photo  (multipart/form-data, campo "photo") */
    public static function uploadPhoto(array $p): void
    {
        $dog = self::findDog($p['id']);
        $file = $_FILES['photo'] ?? null;

        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            $tooBig = $file && in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
            throw new HttpError(422, 'upload_failed', $tooBig ? 'La foto es demasiado grande.' : 'No se recibió la foto.');
        }
        if ($file['size'] > self::MAX_PHOTO_BYTES) {
            throw new HttpError(422, 'upload_failed', 'La foto es demasiado grande (máx. 6 MB).');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $ext = self::MIME_EXT[$mime] ?? null;
        if ($ext === null || @getimagesize($file['tmp_name']) === false) {
            throw new HttpError(422, 'upload_failed', 'El archivo no es una imagen válida (JPG, PNG o WEBP).');
        }

        // Nombre aleatorio: la URL es pública pero no adivinable, y cambia en cada subida (evita caché vieja).
        $relative = 'uploads/dogs/' . strtolower($dog['name']) . '-' . bin2hex(random_bytes(8)) . ".$ext";
        $dest = ROOT . '/public/' . $relative;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            throw new HttpError(500, 'upload_failed', 'No se pudo guardar la foto en el servidor.');
        }
        @chmod($dest, 0644);

        self::deletePhotoFile($dog['photo_path']);
        Database::run('UPDATE dogs SET photo_path = ? WHERE id = ?', [$relative, $dog['id']]);

        Http::json(['date' => date('Y-m-d'), 'dogs' => self::todayStatus()]);
    }

    /** DELETE /dogs/{id}/photo */
    public static function deletePhoto(array $p): void
    {
        $dog = self::findDog($p['id']);
        self::deletePhotoFile($dog['photo_path']);
        Database::run('UPDATE dogs SET photo_path = NULL WHERE id = ?', [$dog['id']]);
        Http::json(['date' => date('Y-m-d'), 'dogs' => self::todayStatus()]);
    }

    /** @return array<string, mixed> */
    private static function findDog(int $id): array
    {
        $dog = Database::one('SELECT id, name, photo_path FROM dogs WHERE id = ?', [$id]);
        if (!$dog) {
            throw new HttpError(404, 'not_found', 'No encontramos a ese perrito.');
        }
        return $dog;
    }

    private static function deletePhotoFile(?string $relative): void
    {
        // basename() evita que una ruta manipulada borre algo fuera de uploads/dogs.
        if ($relative && str_starts_with($relative, 'uploads/dogs/')) {
            @unlink(ROOT . '/public/uploads/dogs/' . basename($relative));
        }
    }

    /** @return array<int, array<string, mixed>> */
    private static function todayStatus(): array
    {
        $dogs = Database::all('SELECT id, name, photo_path FROM dogs ORDER BY sort_order, id');
        $tasks = Database::all(
            'SELECT t.id, t.dog_id, t.kind, t.label, c.checked_at, u.id AS user_id, u.name AS user_name
               FROM dog_tasks t
               LEFT JOIN dog_checks c ON c.task_id = t.id AND c.check_date = CURDATE()
               LEFT JOIN users u ON u.id = c.user_id
              WHERE t.active = 1
              ORDER BY t.sort_order, t.id',
        );

        $byDog = [];
        foreach ($tasks as $t) {
            $byDog[(int) $t['dog_id']][] = [
                'id' => (int) $t['id'],
                'kind' => $t['kind'],
                'label' => $t['label'],
                'done' => $t['checked_at'] === null ? null : [
                    'by' => ['id' => (int) $t['user_id'], 'name' => $t['user_name']],
                    'at' => $t['checked_at'],
                ],
            ];
        }

        $base = rtrim((string) env('PUBLIC_URL', ''), '/');
        return array_map(static fn(array $d) => [
            'id' => (int) $d['id'],
            'name' => $d['name'],
            'photo_url' => $d['photo_path'] ? "$base/{$d['photo_path']}" : null,
            'tasks' => $byDog[(int) $d['id']] ?? [],
        ], $dogs);
    }
}
