<?php
declare(strict_types=1);

namespace SyncCampus;

use PDO;
use PDOStatement;
use RuntimeException;

/**
 * Thin PDO wrapper. The same queries run on MySQL (XAMPP / shared hosting) and
 * PostgreSQL (Neon), so SQL here sticks to the common subset:
 *  - booleans are written as TRUE/FALSE in SQL and bound as 1/0,
 *  - timestamps are generated in PHP (UTC) instead of NOW()-style functions,
 *  - LIMIT values are cast to int and inlined.
 */
final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            [$dsn, $user, $pass] = self::settings();
            self::$pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            self::$pdo->exec(self::isPostgres() ? "SET TIME ZONE 'UTC'" : "SET time_zone = '+00:00'");
        }
        return self::$pdo;
    }

    public static function isPostgres(): bool
    {
        return self::pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
    }

    public static function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute(array_map(static fn ($v) => is_bool($v) ? (int) $v : $v, $params));
        return $stmt;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function value(string $sql, array $params = []): mixed
    {
        $value = self::run($sql, $params)->fetchColumn();
        return $value === false ? null : $value;
    }

    /** Inserts a row and returns its new id. */
    public static function insert(string $table, array $row): int
    {
        $columns = implode(', ', array_keys($row));
        $marks = implode(', ', array_fill(0, count($row), '?'));
        $sql = "INSERT INTO $table ($columns) VALUES ($marks)";
        if (self::isPostgres()) {
            return (int) self::run("$sql RETURNING id", array_values($row))->fetchColumn();
        }
        self::run($sql, array_values($row));
        return (int) self::pdo()->lastInsertId();
    }

    public static function transaction(callable $work): mixed
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $result = $work();
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * DATABASE_URL (postgres://... or mysql://...) is used when present, which is
     * what Neon and most hosts hand out. Otherwise DB_DSN / DB_USER / DB_PASS.
     */
    private static function settings(): array
    {
        $url = Env::get('DATABASE_URL');
        if ($url === '') {
            return [
                Env::get('DB_DSN', 'mysql:host=127.0.0.1;dbname=synccampus;charset=utf8mb4'),
                Env::get('DB_USER', 'root'),
                Env::get('DB_PASS'),
            ];
        }

        $parts = parse_url($url);
        if (!$parts || empty($parts['host'])) {
            throw new RuntimeException('DATABASE_URL is not a valid connection URL');
        }
        $name = ltrim($parts['path'] ?? '', '/');
        $user = urldecode($parts['user'] ?? '');
        $pass = urldecode($parts['pass'] ?? '');
        parse_str($parts['query'] ?? '', $options);

        switch ($parts['scheme'] ?? '') {
            case 'postgres':
            case 'postgresql':
                $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $parts['host'], $parts['port'] ?? 5432, $name);
                // Pass on the options that matter (Neon needs sslmode=require). Others, such as
                // channel_binding, are skipped: older client libraries reject them outright.
                // Neon routes by endpoint ID. Older client libraries (e.g. XAMPP's) cannot send it
                // through TLS SNI, so name it explicitly. It must match what newer libraries send by
                // SNI, i.e. the first part of the host name ("ep-abc-123-pooler.region..." -> "ep-abc-123-pooler").
                if (!isset($options['options']) && str_ends_with($parts['host'], '.neon.tech')) {
                    $options['options'] = 'endpoint=' . explode('.', $parts['host'])[0];
                }
                foreach (['sslmode', 'options', 'connect_timeout'] as $key) {
                    if (isset($options[$key])) {
                        $dsn .= ";$key='" . addcslashes((string) $options[$key], "'\\") . "'";
                    }
                }
                return [$dsn, $user, $pass];
            case 'mysql':
                $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $parts['host'], $parts['port'] ?? 3306, $name);
                return [$dsn, $user, $pass];
            default:
                throw new RuntimeException('DATABASE_URL must start with postgres:// or mysql://');
        }
    }
}
