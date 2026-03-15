<?php

declare(strict_types=1);

namespace Alpha\Models;

class Bookmark extends BaseModel
{
    protected static string $table = 'bookmarks';

    public function toggle(int $userId, int $mangaId): bool
    {
        $tbl = $this->tbl();
        $exists = $this->db->get_var("SELECT id FROM `{$tbl}` WHERE user_id = :uid AND manga_id = :mid LIMIT 1",
            ['uid' => $userId, 'mid' => $mangaId]);

        if ($exists) {
            $this->db->delete($tbl, ['id' => (int)$exists]);
            return false; // removed
        }
        $this->db->insert($tbl, ['user_id' => $userId, 'manga_id' => $mangaId, 'created_at' => date('Y-m-d H:i:s')]);
        return true; // added
    }

    public function isBookmarked(int $userId, int $mangaId): bool
    {
        $tbl = $this->tbl();
        return (bool)$this->db->get_var("SELECT id FROM `{$tbl}` WHERE user_id = :uid AND manga_id = :mid LIMIT 1",
            ['uid' => $userId, 'mid' => $mangaId]);
    }
}
