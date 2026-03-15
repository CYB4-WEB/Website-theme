<?php

declare(strict_types=1);

namespace Alpha\Models;

class History extends BaseModel
{
    protected static string $table = 'history';

    public function savePosition(int $userId, int $mangaId, int $chapterId, int $page = 0): void
    {
        $tbl     = $this->tbl();
        $existing = $this->db->get_var(
            "SELECT id FROM `{$tbl}` WHERE user_id = :uid AND manga_id = :mid LIMIT 1",
            ['uid' => $userId, 'mid' => $mangaId]
        );

        $data = [
            'chapter_id' => $chapterId,
            'page'       => $page,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($existing) {
            $this->db->update($tbl, $data, ['id' => (int)$existing]);
        } else {
            $this->db->insert($tbl, array_merge($data, [
                'user_id'    => $userId,
                'manga_id'   => $mangaId,
                'created_at' => date('Y-m-d H:i:s'),
            ]));
        }
    }

    public function getPosition(int $userId, int $mangaId): ?array
    {
        $tbl = $this->tbl();
        return $this->db->get_row(
            "SELECT * FROM `{$tbl}` WHERE user_id = :uid AND manga_id = :mid LIMIT 1",
            ['uid' => $userId, 'mid' => $mangaId]
        );
    }
}
