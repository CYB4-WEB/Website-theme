<?php

declare(strict_types=1);

namespace Alpha\Models;

use Alpha\Core\Database;

/**
 * Manga series model.
 * Maps to alpha_manga, with joins to alpha_manga_genres and alpha_chapters.
 */
class Manga extends BaseModel
{
    protected static string $table = 'manga';

    // ── Listing ───────────────────────────────────────────────────────────────

    public function listing(array $filters = [], string $orderBy = 'updated_at DESC', int $page = 1, int $perPage = 24): array
    {
        $tbl     = $this->tbl();
        $where   = ['1=1'];
        $params  = [];

        if (!empty($filters['genre'])) {
            $gTbl    = Database::table('manga_genre_map');
            $genres  = Database::table('genres');
            $where[] = "m.id IN (SELECT manga_id FROM `{$gTbl}` gm JOIN `{$genres}` g ON g.id = gm.genre_id WHERE g.slug = :genre)";
            $params['genre'] = $filters['genre'];
        }
        if (!empty($filters['type'])) {
            $where[] = 'm.type = :type';
            $params['type'] = $filters['type'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'm.status = :status';
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['year'])) {
            $where[] = 'm.release_year = :year';
            $params['year'] = (int)$filters['year'];
        }
        if (!empty($filters['q'])) {
            $where[]      = '(m.title LIKE :q OR m.alt_names LIKE :q OR m.author LIKE :q OR m.artist LIKE :q)';
            $params['q'] = '%' . $filters['q'] . '%';
        }

        $cond = implode(' AND ', $where);
        $sql  = "SELECT m.*,
                    (SELECT COUNT(*) FROM `" . Database::table('chapters') . "` c WHERE c.manga_id = m.id AND c.status = 'publish') AS chapter_count,
                    (SELECT MAX(c2.created_at) FROM `" . Database::table('chapters') . "` c2 WHERE c2.manga_id = m.id AND c2.status = 'publish') AS last_chapter_at
                 FROM `{$tbl}` m
                 WHERE {$cond}
                 ORDER BY {$orderBy}";

        return $this->paginate($sql, $params, $page, $perPage);
    }

    public function findBySlug(string $slug): ?array
    {
        $tbl  = $this->tbl();
        $row  = $this->db->get_row("SELECT * FROM `{$tbl}` WHERE slug = :slug LIMIT 1", ['slug' => $slug]);
        if ($row) {
            $row['genres'] = $this->getGenres((int)$row['id']);
        }
        return $row;
    }

    // ── Related data ──────────────────────────────────────────────────────────

    public function getGenres(int $mangaId): array
    {
        $gMap  = Database::table('manga_genre_map');
        $gTbl  = Database::table('genres');
        return $this->db->get_results(
            "SELECT g.id, g.name, g.slug FROM `{$gTbl}` g
             JOIN `{$gMap}` gm ON gm.genre_id = g.id
             WHERE gm.manga_id = :id ORDER BY g.name",
            ['id' => $mangaId]
        );
    }

    public function setGenres(int $mangaId, array $genreIds): void
    {
        $gMap = Database::table('manga_genre_map');
        $this->db->execute("DELETE FROM `{$gMap}` WHERE manga_id = :id", ['id' => $mangaId]);
        foreach ($genreIds as $gid) {
            $this->db->insert($gMap, ['manga_id' => $mangaId, 'genre_id' => (int)$gid]);
        }
    }

    public function getWithChapterCount(int $id): ?array
    {
        $tbl  = $this->tbl();
        $cTbl = Database::table('chapters');
        return $this->db->get_row(
            "SELECT m.*, COUNT(c.id) AS chapter_count
             FROM `{$tbl}` m
             LEFT JOIN `{$cTbl}` c ON c.manga_id = m.id AND c.status = 'publish'
             WHERE m.id = :id
             GROUP BY m.id",
            ['id' => $id]
        );
    }

    // ── Stats ─────────────────────────────────────────────────────────────────

    public function incrementViews(int $id): void
    {
        $tbl = $this->tbl();
        $this->db->execute("UPDATE `{$tbl}` SET views = views + 1 WHERE id = :id", ['id' => $id]);
    }

    public function updateRatingCache(int $mangaId): void
    {
        $rTbl = Database::table('ratings');
        $avg  = $this->db->get_var("SELECT ROUND(AVG(rating), 2) FROM `{$rTbl}` WHERE manga_id = :id", ['id' => $mangaId]);
        $cnt  = $this->db->get_var("SELECT COUNT(*) FROM `{$rTbl}` WHERE manga_id = :id", ['id' => $mangaId]);
        $this->update($mangaId, ['rating_avg' => $avg ?? 0, 'rating_count' => $cnt ?? 0]);
    }

    // ── Popular / Latest ──────────────────────────────────────────────────────

    public function getPopular(string $period = 'week', int $limit = 10): array
    {
        $tbl  = $this->tbl();
        $vTbl = Database::table('views_log');

        $since = match ($period) {
            'today' => 'NOW() - INTERVAL 1 DAY',
            'week'  => 'NOW() - INTERVAL 7 DAY',
            'month' => 'NOW() - INTERVAL 30 DAY',
            default => "'2000-01-01'",
        };

        return $this->db->get_results(
            "SELECT m.*, COUNT(v.id) AS period_views
             FROM `{$tbl}` m
             JOIN `{$vTbl}` v ON v.manga_id = m.id AND v.viewed_at >= {$since}
             GROUP BY m.id
             ORDER BY period_views DESC
             LIMIT {$limit}"
        );
    }

    public function getLatestUpdated(int $limit = 12): array
    {
        $tbl  = $this->tbl();
        $cTbl = Database::table('chapters');
        return $this->db->get_results(
            "SELECT m.*, MAX(c.created_at) AS last_update
             FROM `{$tbl}` m
             JOIN `{$cTbl}` c ON c.manga_id = m.id AND c.status = 'publish'
             GROUP BY m.id
             ORDER BY last_update DESC
             LIMIT {$limit}"
        );
    }

    // ── Admin ─────────────────────────────────────────────────────────────────

    public function createWithSlug(array $data): int
    {
        if (empty($data['slug'])) {
            $data['slug'] = $this->uniqueSlug($data['title']);
        }
        $data['created_at'] = $data['created_at'] ?? date('Y-m-d H:i:s');
        $data['updated_at'] = date('Y-m-d H:i:s');
        return $this->create($data);
    }

    private function uniqueSlug(string $title): string
    {
        $base = preg_replace('/[^a-z0-9]+/', '-', strtolower($title));
        $slug = trim($base, '-');
        $tbl  = $this->tbl();
        $i    = 0;
        $try  = $slug;
        while ($this->db->get_var("SELECT id FROM `{$tbl}` WHERE slug = :s LIMIT 1", ['s' => $try])) {
            $try = $slug . '-' . (++$i);
        }
        return $try;
    }

    // ── All genres ────────────────────────────────────────────────────────────

    public function allGenres(): array
    {
        $tbl = Database::table('genres');
        return $this->db->get_results("SELECT * FROM `{$tbl}` ORDER BY name");
    }
}
