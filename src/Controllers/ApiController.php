<?php

declare(strict_types=1);

namespace Alpha\Controllers;

use Alpha\Core\{Config, Request, Response};
use Alpha\Models\{Bookmark, Chapter, Coin, History, Rating};
use Alpha\Services\{Auth, MangaSearch, Security};

/**
 * AJAX / JSON API endpoints.
 * All handlers return JSON and exit.
 */
class ApiController extends BaseController
{
    // ── Search ────────────────────────────────────────────────────────────────

    public function liveSearch(Request $req, array $params): void
    {
        $q      = Security::sanitizeText($req->get('q', ''));
        $search = new MangaSearch();
        $items  = $search->search($q, 10);
        $this->json(['success' => true, 'items' => $items]);
    }

    public function advancedSearch(Request $req, array $params): void
    {
        $filters = [
            'q'      => Security::sanitizeText($req->get('q', '')),
            'genre'  => $req->get('genre', ''),
            'type'   => $req->get('type', ''),
            'status' => $req->get('status', ''),
            'year'   => $req->int('year'),
            'sort'   => $req->get('sort', 'updated'),
        ];
        $page   = max(1, $req->int('page', 1));
        $search = new MangaSearch();
        $result = $search->advancedSearch(array_filter($filters), $page, 24);
        $this->json(['success' => true, 'result' => $result]);
    }

    public function suggestions(Request $req, array $params): void
    {
        $q    = Security::sanitizeText($req->get('q', ''));
        $s    = new MangaSearch();
        $data = $s->suggestions($q);
        $this->json(['success' => true, 'items' => $data]);
    }

    // ── Bookmark ──────────────────────────────────────────────────────────────

    public function toggleBookmark(Request $req, array $params): void
    {
        if (Auth::guest()) {
            $this->json(['success' => false, 'message' => 'Login required.'], 401);
        }
        $mangaId = $req->int('manga_id');
        if (!$mangaId) {
            $this->json(['success' => false, 'message' => 'Invalid ID.'], 400);
        }
        $bm    = new Bookmark();
        $added = $bm->toggle(Auth::id(), $mangaId);
        $this->json(['success' => true, 'bookmarked' => $added]);
    }

    // ── Rating ────────────────────────────────────────────────────────────────

    public function submitRating(Request $req, array $params): void
    {
        if (Auth::guest()) {
            $this->json(['success' => false, 'message' => 'Login required.'], 401);
        }
        $mangaId = $req->int('manga_id');
        $rating  = $req->int('rating');

        if ($rating < 1 || $rating > 5) {
            $this->json(['success' => false, 'message' => 'Rating must be 1–5.'], 400);
        }

        $rt = new Rating();
        $rt->upsert(Auth::id(), $mangaId, $rating);

        $manga = new \Alpha\Models\Manga();
        $m     = $manga->find($mangaId);
        $this->json(['success' => true, 'avg' => $m['rating_avg'], 'count' => $m['rating_count']]);
    }

    public function getUserRating(Request $req, array $params): void
    {
        if (Auth::guest()) {
            $this->json(['success' => true, 'rating' => null]);
        }
        $mangaId = $req->int('manga_id');
        $rt      = new Rating();
        $this->json(['success' => true, 'rating' => $rt->getUserRating(Auth::id(), $mangaId)]);
    }

    // ── History ───────────────────────────────────────────────────────────────

    public function saveHistory(Request $req, array $params): void
    {
        if (Auth::guest()) {
            $this->json(['success' => true]); // silent for guests
        }
        $mangaId   = $req->int('manga_id');
        $chapterId = $req->int('chapter_id');
        $page      = $req->int('page', 0);

        (new History())->savePosition(Auth::id(), $mangaId, $chapterId, $page);
        $this->json(['success' => true]);
    }

    // ── Chapter view tracking ─────────────────────────────────────────────────

    public function trackView(Request $req, array $params): void
    {
        $chapterId = $req->int('chapter_id');
        if ($chapterId) {
            (new Chapter())->countView($chapterId, Auth::id(), $req->ip());
        }
        $this->json(['success' => true]);
    }

    // ── Coin unlock ───────────────────────────────────────────────────────────

    public function unlockChapter(Request $req, array $params): void
    {
        if (Auth::guest()) {
            $this->json(['success' => false, 'message' => 'Login required.'], 401);
        }
        $chapterId = $req->int('chapter_id');
        $chapter   = (new Chapter())->find($chapterId);
        if (!$chapter) {
            $this->json(['success' => false, 'message' => 'Chapter not found.'], 404);
        }

        $coin   = new Coin();
        $result = $coin->unlock(Auth::id(), $chapterId, (int)$chapter['coin_price']);
        $this->json($result);
    }

    public function getCoinBalance(Request $req, array $params): void
    {
        if (Auth::guest()) {
            $this->json(['success' => false, 'message' => 'Login required.'], 401);
        }
        $balance = (new Coin())->getBalance(Auth::id());
        $this->json(['success' => true, 'balance' => $balance]);
    }

    // ── Chapter upload (AJAX images) ──────────────────────────────────────────

    public function uploadChapterImages(Request $req, array $params): void
    {
        if (!Auth::can('upload_chapter')) {
            $this->json(['success' => false, 'message' => 'Permission denied.'], 403);
        }

        $mangaId   = $req->int('manga_id');
        $chapterId = $req->int('chapter_id');
        $files     = $_FILES['images'] ?? null;

        if (!$files) {
            $this->json(['success' => false, 'message' => 'No files uploaded.'], 400);
        }

        $urls   = [];
        $count  = count($files['name']);
        $storage = \Alpha\Services\Storage\StorageManager::driver();

        for ($i = 0; $i < $count; $i++) {
            if ($files['error'][$i] !== UPLOAD_ERR_OK) {
                continue;
            }
            if (!Security::isAllowedImageType($files['tmp_name'][$i])) {
                continue;
            }
            $url    = \Alpha\Services\Storage\StorageManager::uploadChapterImage(
                $files['tmp_name'][$i], $mangaId, $chapterId, $files['name'][$i]
            );
            $urls[] = ['url' => $url, 'alt' => ''];
        }

        // Update chapter data
        if ($chapterId && $urls) {
            $existing = (new Chapter())->find($chapterId);
            $current  = json_decode($existing['chapter_data'] ?? '[]', true);
            $merged   = array_merge((array)$current, $urls);
            (new Chapter())->update($chapterId, ['chapter_data' => json_encode($merged)]);
        }

        $this->json(['success' => true, 'urls' => $urls]);
    }

    // ── Sitemap ───────────────────────────────────────────────────────────────

    public function sitemap(Request $req, array $params): void
    {
        header('Content-Type: application/xml; charset=utf-8');
        echo (new \Alpha\Services\SEO())->generateSitemap();
        exit;
    }
}
