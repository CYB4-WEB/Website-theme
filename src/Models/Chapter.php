<?php

declare(strict_types=1);

namespace Alpha\Models;

use Alpha\Core\Database;

/**
 * Chapter model — maps to alpha_chapters.
 * chapter_data is JSON (image array | text content | video url).
 */
class Chapter extends BaseModel
{
    protected static string $table = 'chapters';

    public function forManga(int $mangaId, string $status = 'publish', string $order = 'ASC'): array
    {
        $tbl = $this->tbl();
        return $this->db->get_results(
            "SELECT * FROM `{$tbl}` WHERE manga_id = :mid AND status = :s ORDER BY chapter_number {$order}",
            ['mid' => $mangaId, 's' => $status]
        );
    }

    public function paginated(int $mangaId, int $page = 1, int $perPage = 50, string $status = 'publish'): array
    {
        $tbl = $this->tbl();
        $sql = "SELECT * FROM `{$tbl}` WHERE manga_id = :mid AND status = :s ORDER BY chapter_number DESC";
        return $this->paginate($sql, ['mid' => $mangaId, 's' => $status], $page, $perPage);
    }

    public function findByNumber(int $mangaId, float $number): ?array
    {
        $tbl = $this->tbl();
        $row = $this->db->get_row(
            "SELECT * FROM `{$tbl}` WHERE manga_id = :mid AND chapter_number = :num AND status = 'publish' LIMIT 1",
            ['mid' => $mangaId, 'num' => $number]
        );
        if ($row) {
            $row['chapter_data'] = json_decode($row['chapter_data'] ?? '{}', true);
        }
        return $row;
    }

    public function getPrev(int $mangaId, float $current): ?array
    {
        $tbl = $this->tbl();
        return $this->db->get_row(
            "SELECT * FROM `{$tbl}` WHERE manga_id = :mid AND chapter_number < :cur AND status = 'publish' ORDER BY chapter_number DESC LIMIT 1",
            ['mid' => $mangaId, 'cur' => $current]
        );
    }

    public function getNext(int $mangaId, float $current): ?array
    {
        $tbl = $this->tbl();
        return $this->db->get_row(
            "SELECT * FROM `{$tbl}` WHERE manga_id = :mid AND chapter_number > :cur AND status = 'publish' ORDER BY chapter_number ASC LIMIT 1",
            ['mid' => $mangaId, 'cur' => $current]
        );
    }

    public function create(array $data): int
    {
        // Encode JSON data
        if (isset($data['chapter_data']) && is_array($data['chapter_data'])) {
            $data['chapter_data'] = json_encode($data['chapter_data']);
        }
        $data['created_at'] = $data['created_at'] ?? date('Y-m-d H:i:s');
        $data['updated_at'] = date('Y-m-d H:i:s');
        return parent::create($data);
    }

    public function update(int $id, array $data): int
    {
        if (isset($data['chapter_data']) && is_array($data['chapter_data'])) {
            $data['chapter_data'] = json_encode($data['chapter_data']);
        }
        $data['updated_at'] = date('Y-m-d H:i:s');
        return parent::update($id, $data);
    }

    public function countView(int $chapterId, int|null $userId, string $ip): void
    {
        $vTbl = Database::table('views_log');
        $cTbl = $this->tbl();

        // Dedup: one view per user/IP per 6 hours
        $key  = $userId ? "user_{$userId}" : "ip_{$ip}";
        $key .= "_{$chapterId}";
        $hash = md5($key);

        $tTbl = Database::table('transients');
        $existing = $this->db->get_var(
            "SELECT `key` FROM `{$tTbl}` WHERE `key` = :k AND (expires_at IS NULL OR expires_at > NOW()) LIMIT 1",
            ['k' => "view_{$hash}"]
        );
        if ($existing) {
            return;
        }

        // Record view
        $this->db->insert($vTbl, [
            'chapter_id' => $chapterId,
            'manga_id'   => $this->getMangaId($chapterId),
            'user_id'    => $userId,
            'ip_address' => $ip,
            'viewed_at'  => date('Y-m-d H:i:s'),
        ]);

        // Increment chapter view counter
        $this->db->execute("UPDATE `{$cTbl}` SET views = views + 1 WHERE id = :id", ['id' => $chapterId]);

        // Set dedup transient (6 hours)
        $expires = date('Y-m-d H:i:s', time() + 21600);
        $this->db->insert($tTbl, ['key' => "view_{$hash}", 'value' => '1', 'expires_at' => $expires]);
    }

    private function getMangaId(int $chapterId): int
    {
        $tbl = $this->tbl();
        return (int)$this->db->get_var("SELECT manga_id FROM `{$tbl}` WHERE id = :id", ['id' => $chapterId]);
    }

    public function getImages(int $chapterId): array
    {
        $tbl = $this->tbl();
        $row = $this->db->get_row("SELECT chapter_data FROM `{$tbl}` WHERE id = :id", ['id' => $chapterId]);
        if (!$row) {
            return [];
        }
        $data = json_decode($row['chapter_data'] ?? '[]', true);
        return is_array($data) ? $data : [];
    }

    /** Latest N chapters across all manga, used for dashboard. */
    public function latestAcrossAll(int $limit = 20): array
    {
        $cTbl = $this->tbl();
        $mTbl = Database::table('manga');
        return $this->db->get_results(
            "SELECT c.*, m.title AS manga_title, m.slug AS manga_slug, m.cover AS manga_cover
             FROM `{$cTbl}` c
             JOIN `{$mTbl}` m ON m.id = c.manga_id
             WHERE c.status = 'publish'
             ORDER BY c.created_at DESC
             LIMIT {$limit}"
        );
    }
}
