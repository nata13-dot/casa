<?php
declare(strict_types=1);

namespace Casa;

final class AnnouncementController
{
    private const LIST_LIMIT = 100;

    /**
     * GET /announcements?status=active|done|all
     * Activos: sin resolver. Se ordenan por prioridad y luego por fecha.
     */
    public static function index(array $p, array $user): void
    {
        $status = $_GET['status'] ?? 'active';
        $where = match ($status) {
            'done' => 'a.done_at IS NOT NULL AND a.done_at > NOW() - INTERVAL 30 DAY',
            'all' => '1 = 1',
            default => 'a.done_at IS NULL',
        };
        $order = $status === 'done'
            ? 'a.done_at DESC'
            : "FIELD(a.priority, 'urgente', 'importante', 'normal'), a.created_at DESC";

        $rows = Database::all(
            "SELECT a.*, u.name AS author_name, d.name AS done_by_name
               FROM announcements a
               JOIN users u ON u.id = a.user_id
               LEFT JOIN users d ON d.id = a.done_by
              WHERE $where
              ORDER BY $order
              LIMIT " . self::LIST_LIMIT,
        );

        Http::json(self::present($rows, $user));
    }

    /** GET /announcements/summary → contadores para la insignia de la pestaña. */
    public static function summary(array $p, array $user): void
    {
        $row = Database::one(
            "SELECT COUNT(*) AS active,
                    SUM(a.priority = 'urgente') AS urgent,
                    SUM(k.user_id IS NULL AND a.user_id <> :me) AS unread
               FROM announcements a
               LEFT JOIN announcement_acks k ON k.announcement_id = a.id AND k.user_id = :me2
              WHERE a.done_at IS NULL",
            ['me' => $user['id'], 'me2' => $user['id']],
        );
        $pending = $user['role'] === 'admin'
            ? (int) Database::one('SELECT COUNT(*) AS n FROM access_requests')['n']
            : 0;
        Http::json([
            'active' => (int) $row['active'],
            'urgent' => (int) $row['urgent'],
            'unread' => (int) $row['unread'],
            'pending_requests' => $pending,
        ]);
    }

    /** POST /announcements  { title, body?, category, priority? } */
    public static function store(array $p, array $user): void
    {
        $in = Http::body();
        $title = Http::str($in, 'title', 120);
        $body = Http::str($in, 'body', 1000, false);
        $category = (string) ($in['category'] ?? '');
        $priority = (string) ($in['priority'] ?? 'normal');

        if (!isset(Categories::ALL[$category])) {
            throw new HttpError(422, 'validation', 'Elige un tipo de aviso.', ['category' => 'Tipo de aviso no válido.']);
        }
        if (!isset(Categories::PRIORITIES[$priority])) {
            throw new HttpError(422, 'validation', 'Prioridad no válida.', ['priority' => 'Usa normal, importante o urgente.']);
        }
        $priority = Categories::effectivePriority($category, $priority);

        Database::run(
            'INSERT INTO announcements (user_id, category, priority, title, body, created_at) VALUES (?, ?, ?, ?, ?, NOW())',
            [$user['id'], $category, $priority, $title, $body],
        );
        $id = Database::lastId();
        // El autor ya lo "vio".
        Database::run('INSERT INTO announcement_acks (announcement_id, user_id, created_at) VALUES (?, ?, NOW())', [$id, $user['id']]);

        $created = self::find($id, $user);

        Http::jsonThen($created, 201, static function () use ($created, $user) {
            $recipients = Database::all(
                'SELECT id, fcm_token FROM users WHERE fcm_token IS NOT NULL AND id <> ?',
                [$user['id']],
            );
            $prefix = match ($created['priority']) {
                'urgente' => '🚨 ',
                'importante' => '⚠️ ',
                default => '',
            };
            Fcm::sendToMany(
                $recipients,
                $prefix . Categories::label($created['category']) . ' · ' . $user['name'],
                $created['title'] . ($created['body'] ? "\n" . $created['body'] : ''),
                ['type' => 'announcement', 'announcement_id' => (string) $created['id'], 'priority' => $created['priority']],
                $created['priority'] === 'urgente',
            );
        });
    }

    /** POST /announcements/{id}/ack — "Enterado". */
    public static function ack(array $p, array $user): void
    {
        self::find($p['id'], $user);
        Database::run(
            'INSERT IGNORE INTO announcement_acks (announcement_id, user_id, created_at) VALUES (?, ?, NOW())',
            [$p['id'], $user['id']],
        );
        Http::json(self::find($p['id'], $user));
    }

    /** PUT /announcements/{id}/done  { done: bool } — "Ya está" / "Ya lo compré". */
    public static function done(array $p, array $user): void
    {
        self::find($p['id'], $user);
        $done = (Http::body()['done'] ?? true) === true;
        if ($done) {
            Database::run(
                'UPDATE announcements SET done_by = ?, done_at = NOW() WHERE id = ? AND done_at IS NULL',
                [$user['id'], $p['id']],
            );
        } else {
            Database::run('UPDATE announcements SET done_by = NULL, done_at = NULL WHERE id = ?', [$p['id']]);
        }
        Http::json(self::find($p['id'], $user));
    }

    /** DELETE /announcements/{id} — quien lo publicó o un admin. */
    public static function destroy(array $p, array $user): void
    {
        $a = self::find($p['id'], $user);
        if (!$a['can_delete']) {
            throw new HttpError(403, 'forbidden', 'Solo quien publicó el aviso o un administrador puede borrarlo.');
        }
        Database::run('DELETE FROM announcements WHERE id = ?', [$p['id']]);
        if (!$a['is_mine']) {
            Security::audit($user, 'announcement_deleted', "{$user['name']} borró el aviso de {$a['author']['name']}: «{$a['title']}»");
        }
        Http::json(['deleted' => true]);
    }

    /** @return array<string, mixed> */
    private static function find(int $id, array $user): array
    {
        $rows = Database::all(
            'SELECT a.*, u.name AS author_name, d.name AS done_by_name
               FROM announcements a
               JOIN users u ON u.id = a.user_id
               LEFT JOIN users d ON d.id = a.done_by
              WHERE a.id = ?',
            [$id],
        );
        if (!$rows) {
            throw new HttpError(404, 'not_found', 'Ese aviso ya no existe.');
        }
        return self::present($rows, $user)[0];
    }

    /**
     * Da forma a las filas y añade quién dio "Enterado" (una sola consulta para todos).
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private static function present(array $rows, array $user): array
    {
        $me = $user['id'];
        $isAdmin = $user['role'] === 'admin';
        if (!$rows) {
            return [];
        }
        $ids = array_map(static fn($r) => (int) $r['id'], $rows);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $acks = Database::all(
            "SELECT k.announcement_id, u.id, u.name
               FROM announcement_acks k JOIN users u ON u.id = k.user_id
              WHERE k.announcement_id IN ($placeholders)
              ORDER BY k.created_at",
            $ids,
        );
        $ackMap = [];
        foreach ($acks as $k) {
            $ackMap[(int) $k['announcement_id']][] = ['id' => (int) $k['id'], 'name' => $k['name']];
        }

        return array_map(static function (array $r) use ($me, $isAdmin, $ackMap) {
            $id = (int) $r['id'];
            $seenBy = $ackMap[$id] ?? [];
            return [
                'id' => $id,
                'category' => $r['category'],
                'priority' => $r['priority'],
                'title' => $r['title'],
                'body' => $r['body'],
                'created_at' => $r['created_at'],
                'author' => ['id' => (int) $r['user_id'], 'name' => $r['author_name']],
                'is_mine' => (int) $r['user_id'] === $me,
                'can_delete' => (int) $r['user_id'] === $me || $isAdmin,
                'done' => $r['done_at'] === null ? null : [
                    'at' => $r['done_at'],
                    'by' => $r['done_by'] === null ? null : ['id' => (int) $r['done_by'], 'name' => $r['done_by_name']],
                ],
                'seen_by' => $seenBy,
                'seen_by_me' => in_array($me, array_column($seenBy, 'id'), true),
            ];
        }, $rows);
    }
}
