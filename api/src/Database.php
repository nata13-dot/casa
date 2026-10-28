<?php
declare(strict_types=1);

namespace Casa;

use PDO;

final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                env('DB_HOST', '127.0.0.1'),
                env('DB_PORT', '3306'),
                env('DB_NAME', 'casa'),
            );
            self::$pdo = new PDO($dsn, env('DB_USER', ''), env('DB_PASS', ''), [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            // Mantiene NOW()/CURRENT_TIMESTAMP alineados con la zona horaria de PHP.
            self::$pdo->exec("SET time_zone = '" . date('P') . "'");
        }
        return self::$pdo;
    }

    /** @return array<int, array<string, mixed>> */
    public static function all(string $sql, array $params = []): array
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public static function one(string $sql, array $params = []): ?array
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public static function run(string $sql, array $params = []): int
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /** Tablas/columnas que exige esta versión de la API (las crea database/actualizar.sql). */
    private const REQUIRED_SCHEMA = [
        ['dog_tasks', null],
        ['device_transfers', null],
        ['dog_checks', 'task_id'],
        ['access_requests', null],
    ];

    /** 'ok' | 'desactualizada' | 'sin conexión' — para /health. */
    public static function schemaStatus(): string
    {
        try {
            foreach (self::REQUIRED_SCHEMA as [$table, $column]) {
                $found = $column === null
                    ? self::one('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table])
                    : self::one(
                        'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                        [$table, $column],
                    );
                if ($found === null) {
                    return 'desactualizada';
                }
            }
            return 'ok';
        } catch (\Throwable) {
            return 'sin conexión';
        }
    }

    /** ¿El error es porque falta una tabla o columna (BD sin actualizar)? */
    public static function isOutdatedSchemaError(\Throwable $e): bool
    {
        return $e instanceof \PDOException && in_array($e->getCode(), ['42S02', '42S22'], true);
    }

    public static function lastId(): int
    {
        return (int) self::pdo()->lastInsertId();
    }
}
