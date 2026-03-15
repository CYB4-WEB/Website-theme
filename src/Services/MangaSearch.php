<?php

declare(strict_types=1);

namespace Alpha\Services;

use Alpha\Core\Database;

/**
 * Live search, autocomplete, and advanced filtering.
 * Ported from class-manga-search.php.
 */
class MangaSearch
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Full-text style search across title, alt_names, author, artist.
     */
    public function search(string $q, int $limit = 10): array
    {
        $term = '%' . $q . '%';
        $tbl  = Database::table('manga');
        return $this->db->get_results(
            "SELECT id, title, slug, cover, type, status, rating_avg
             FROM `{$tbl}`
             WHERE (title LIKE :q OR alt_names LIKE :q OR author LIKE :q OR artist LIKE :q)
             ORDER BY
               CASE WHEN title LIKE :exact THEN 0 ELSE 1 END,
               views DESC
             LIMIT {$limit}",
            ['q' => $term, 'exact' => $q . '%']
        );
    }

    /**
     * Advanced search with filters — returns paginated result.
     */
    public function advancedSearch(array $filters, int $page = 1, int $perPage = 24): array
    {
        $tbl   = Database::table('manga');
        $gMap  = Database::table('manga_genre_map');
        $gTbl  = Database::table('genres');
        $cTbl  = Database::table('chapters');

        $where  = ['1=1'];
        $params = [];

        if (!empty($filters['q'])) {
            $where[]    = '(m.title LIKE :q OR m.alt_names LIKE :q OR m.author LIKE :q)';
            $params['q'] = '%' . $filters['q'] . '%';
        }
        if (!empty($filters['genre'])) {
            $where[] = "m.id IN (SELECT manga_id FROM `{$gMap}` gm JOIN `{$gTbl}` g ON g.id = gm.genre_id WHERE g.slug = :genre)";
            $params['genre'] = $filters['genre'];
        }
        if (!empty($filters['type'])) {
            $where[]        = 'm.type = :type';
            $params['type'] = $filters['type'];
        }
        if (!empty($filters['status'])) {
            $where[]          = 'm.status = :status';
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['year'])) {
            $where[]        = 'm.release_year = :year';
            $params['year'] = (int)$filters['year'];
        }

        $orderBy = match ($filters['sort'] ?? 'updated') {
            'popular' => 'm.views DESC',
            'rating'  => 'm.rating_avg DESC',
            'new'     => 'm.created_at DESC',
            default   => 'last_update DESC',
        };

        $cond = implode(' AND ', $where);
        $sql  = "SELECT m.*,
                    (SELECT MAX(c.created_at) FROM `{$cTbl}` c WHERE c.manga_id = m.id AND c.status = 'publish') AS last_update
                 FROM `{$tbl}` m
                 WHERE {$cond}
                 ORDER BY {$orderBy}";

        $total   = (int)$this->db->get_var(
            "SELECT COUNT(*) FROM `{$tbl}` m WHERE {$cond}", $params
        );
        $items   = $this->db->get_results(
            "{$sql} LIMIT {$perPage} OFFSET " . (($page - 1) * $perPage), $params
        );

        return [
            'items'       => $items,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => (int)ceil($total / $perPage),
        ];
    }

    /**
     * Autocomplete suggestions (title only, fast).
     */
    public function suggestions(string $q, int $limit = 8): array
    {
        $tbl = Database::table('manga');
        return $this->db->get_results(
            "SELECT id, title, slug, cover, type FROM `{$tbl}` WHERE title LIKE :q ORDER BY views DESC LIMIT {$limit}",
            ['q' => $q . '%']
        );
    }
}
