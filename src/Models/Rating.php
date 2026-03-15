<?php

declare(strict_types=1);

namespace Alpha\Models;

use Alpha\Core\Database;

class Rating extends BaseModel
{
    protected static string $table = 'ratings';

    public function getUserRating(int $userId, int $mangaId): ?int
    {
        $tbl = $this->tbl();
        $val = $this->db->get_var("SELECT rating FROM `{$tbl}` WHERE user_id = :uid AND manga_id = :mid LIMIT 1",
            ['uid' => $userId, 'mid' => $mangaId]);
        return $val !== null ? (int)$val : null;
    }

    public function upsert(int $userId, int $mangaId, int $rating): void
    {
        $tbl = $this->tbl();
        $existing = $this->db->get_var("SELECT id FROM `{$tbl}` WHERE user_id = :uid AND manga_id = :mid LIMIT 1",
            ['uid' => $userId, 'mid' => $mangaId]);

        if ($existing) {
            $this->db->update($tbl, ['rating' => $rating, 'updated_at' => date('Y-m-d H:i:s')], ['id' => (int)$existing]);
        } else {
            $this->db->insert($tbl, ['user_id' => $userId, 'manga_id' => $mangaId, 'rating' => $rating, 'created_at' => date('Y-m-d H:i:s')]);
        }

        // Update cache on manga row
        (new \Alpha\Models\Manga())->updateRatingCache($mangaId);
    }
}
