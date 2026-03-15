<?php

declare(strict_types=1);

namespace Alpha\Models;

use Alpha\Core\Database;

/**
 * Lightweight Active-Record base.
 */
abstract class BaseModel
{
    protected static string $table  = '';
    protected static string $pk     = 'id';

    protected Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    protected function tbl(): string
    {
        return Database::table(static::$table);
    }

    // ── Finders ───────────────────────────────────────────────────────────────

    public function find(int $id): ?array
    {
        $tbl = $this->tbl();
        $pk  = static::$pk;
        return $this->db->get_row("SELECT * FROM `{$tbl}` WHERE `{$pk}` = :id LIMIT 1", ['id' => $id]);
    }

    public function findBy(array $where, string $extra = ''): ?array
    {
        [$sql, $params] = $this->buildWhere($where);
        $tbl = $this->tbl();
        return $this->db->get_row("SELECT * FROM `{$tbl}` WHERE {$sql} {$extra} LIMIT 1", $params);
    }

    public function findAll(array $where = [], string $extra = '', int $limit = 0, int $offset = 0): array
    {
        $tbl = $this->tbl();
        if (empty($where)) {
            $sql    = "SELECT * FROM `{$tbl}` {$extra}";
            $params = [];
        } else {
            [$cond, $params] = $this->buildWhere($where);
            $sql = "SELECT * FROM `{$tbl}` WHERE {$cond} {$extra}";
        }
        if ($limit > 0) {
            $sql .= " LIMIT {$limit} OFFSET {$offset}";
        }
        return $this->db->get_results($sql, $params);
    }

    public function count(array $where = []): int
    {
        $tbl = $this->tbl();
        if (empty($where)) {
            return (int)$this->db->get_var("SELECT COUNT(*) FROM `{$tbl}`");
        }
        [$cond, $params] = $this->buildWhere($where);
        return (int)$this->db->get_var("SELECT COUNT(*) FROM `{$tbl}` WHERE {$cond}", $params);
    }

    // ── Write ─────────────────────────────────────────────────────────────────

    public function create(array $data): int
    {
        return $this->db->insert($this->tbl(), $data);
    }

    public function update(int $id, array $data): int
    {
        return $this->db->update($this->tbl(), $data, [static::$pk => $id]);
    }

    public function delete(int $id): int
    {
        return $this->db->delete($this->tbl(), [static::$pk => $id]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    protected function buildWhere(array $where): array
    {
        $parts  = [];
        $params = [];
        foreach ($where as $col => $val) {
            if ($val === null) {
                $parts[] = "`{$col}` IS NULL";
            } else {
                $parts[]        = "`{$col}` = :{$col}";
                $params[$col]   = $val;
            }
        }
        return [implode(' AND ', $parts), $params];
    }

    protected function paginate(string $sql, array $params, int $page, int $perPage): array
    {
        $countSql = preg_replace('/SELECT\s+.+?\s+FROM/si', 'SELECT COUNT(*) FROM', $sql, 1);
        // Strip ORDER BY for count
        $countSql = preg_replace('/ORDER BY.+$/si', '', $countSql);
        $total    = (int)$this->db->get_var($countSql, $params);

        $offset  = ($page - 1) * $perPage;
        $items   = $this->db->get_results("{$sql} LIMIT {$perPage} OFFSET {$offset}", $params);

        return [
            'items'       => $items,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => (int)ceil($total / $perPage),
        ];
    }
}
