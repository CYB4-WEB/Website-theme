<?php

declare(strict_types=1);

namespace Alpha\Models;

use Alpha\Core\Database;

class User extends BaseModel
{
    protected static string $table = 'users';

    public function findByEmail(string $email): ?array
    {
        $tbl = $this->tbl();
        return $this->db->get_row("SELECT * FROM `{$tbl}` WHERE email = :e LIMIT 1", ['e' => $email]);
    }

    public function findByUsername(string $username): ?array
    {
        $tbl = $this->tbl();
        return $this->db->get_row("SELECT * FROM `{$tbl}` WHERE username = :u LIMIT 1", ['u' => $username]);
    }

    /** Return user without sensitive fields. */
    public function safe(array $user): array
    {
        return array_diff_key($user, array_flip(['password', 'remember_token', 'remember_expires', 'reset_token', 'reset_expires', 'email_token']));
    }

    public function getBookmarks(int $userId): array
    {
        $bTbl = Database::table('bookmarks');
        $mTbl = Database::table('manga');
        return $this->db->get_results(
            "SELECT m.id, m.title, m.slug, m.cover, m.status, b.created_at AS bookmarked_at
             FROM `{$bTbl}` b
             JOIN `{$mTbl}` m ON m.id = b.manga_id
             WHERE b.user_id = :uid ORDER BY b.created_at DESC",
            ['uid' => $userId]
        );
    }

    public function getReadingHistory(int $userId, int $limit = 20): array
    {
        $hTbl = Database::table('history');
        $mTbl = Database::table('manga');
        $cTbl = Database::table('chapters');
        return $this->db->get_results(
            "SELECT h.*, m.title AS manga_title, m.slug AS manga_slug, m.cover,
                    c.chapter_number, c.title AS chapter_title
             FROM `{$hTbl}` h
             JOIN `{$mTbl}` m ON m.id = h.manga_id
             JOIN `{$cTbl}` c ON c.id = h.chapter_id
             WHERE h.user_id = :uid
             ORDER BY h.updated_at DESC LIMIT {$limit}",
            ['uid' => $userId]
        );
    }

    public function getCoinBalance(int $userId): int
    {
        $tbl = Database::table('user_coins');
        return (int)($this->db->get_var("SELECT balance FROM `{$tbl}` WHERE user_id = :uid", ['uid' => $userId]) ?? 0);
    }

    public function paginate(string $sql, array $params, int $page, int $perPage): array
    {
        return parent::paginate($sql, $params, $page, $perPage);
    }
}
