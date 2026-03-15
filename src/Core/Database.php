<?php

declare(strict_types=1);

namespace Alpha\Core;

use PDO;
use PDOException;
use PDOStatement;

/**
 * Thin PDO wrapper. Exposes prepare/execute/query helpers used throughout the app.
 * Mirrors the $wpdb API shape where convenient.
 */
class Database
{
    private static ?self $instance = null;
    private PDO $pdo;
    public  int $last_insert_id = 0;

    private function __construct()
    {
        $host    = Config::get('DB_HOST', '127.0.0.1');
        $port    = Config::get('DB_PORT', '3306');
        $name    = Config::get('DB_NAME', 'alpha_manga');
        $user    = Config::get('DB_USER', 'root');
        $pass    = Config::get('DB_PASS', '');
        $charset = Config::get('DB_CHARSET', 'utf8mb4');

        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";

        $this->pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    // ── Query helpers ────────────────────────────────────────────────────────

    /**
     * Run a prepared query, return the statement.
     */
    public function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /** Return all rows as associative arrays. */
    public function get_results(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    /** Return a single row. */
    public function get_row(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch();
        return $row !== false ? $row : null;
    }

    /** Return a single scalar value. */
    public function get_var(string $sql, array $params = []): mixed
    {
        return $this->query($sql, $params)->fetchColumn();
    }

    /**
     * INSERT / UPDATE / DELETE. Returns affected rows.
     * Accepts either raw SQL with :named params, or an INSERT helper:
     *   insert('table', ['col' => 'val'])
     */
    public function insert(string $table, array $data): int
    {
        $cols        = implode(', ', array_map(fn($c) => "`{$c}`", array_keys($data)));
        $placeholders = implode(', ', array_map(fn($c) => ":{$c}", array_keys($data)));
        $sql = "INSERT INTO `{$table}` ({$cols}) VALUES ({$placeholders})";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($data);
        $this->last_insert_id = (int)$this->pdo->lastInsertId();
        return $this->last_insert_id;
    }

    public function update(string $table, array $data, array $where): int
    {
        $set   = implode(', ', array_map(fn($c) => "`{$c}` = :set_{$c}", array_keys($data)));
        $cond  = implode(' AND ', array_map(fn($c) => "`{$c}` = :where_{$c}", array_keys($where)));
        $sql   = "UPDATE `{$table}` SET {$set} WHERE {$cond}";

        $params = [];
        foreach ($data  as $k => $v) { $params["set_{$k}"]   = $v; }
        foreach ($where as $k => $v) { $params["where_{$k}"] = $v; }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public function delete(string $table, array $where): int
    {
        $cond  = implode(' AND ', array_map(fn($c) => "`{$c}` = :{$c}", array_keys($where)));
        $sql   = "DELETE FROM `{$table}` WHERE {$cond}";
        $stmt  = $this->pdo->prepare($sql);
        $stmt->execute($where);
        return $stmt->rowCount();
    }

    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->query($sql, $params);
        $this->last_insert_id = (int)$this->pdo->lastInsertId();
        return $stmt->rowCount();
    }

    public function lastInsertId(): int
    {
        return (int)$this->pdo->lastInsertId();
    }

    /** Wrap in a transaction; rollback on exception. */
    public function transaction(callable $callback): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $callback($this);
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /** Table prefix (configurable). */
    public static function prefix(): string
    {
        return Config::get('DB_PREFIX', 'alpha_');
    }

    public static function table(string $name): string
    {
        return self::prefix() . $name;
    }
}
