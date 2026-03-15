<?php

declare(strict_types=1);

namespace Alpha\Controllers;

use Alpha\Core\{Request, Response};
use Alpha\Models\{Manga, Chapter};
use Alpha\Services\{Auth, MangaSearch, Security};

class MangaController extends BaseController
{
    // ── Archive / Search ──────────────────────────────────────────────────────

    public function index(Request $req, array $params): void
    {
        $filters = [
            'q'      => Security::sanitizeText($req->get('q', '')),
            'genre'  => Security::sanitizeSlug($req->get('genre', '')),
            'type'   => Security::sanitizeText($req->get('type', '')),
            'status' => Security::sanitizeText($req->get('status', '')),
            'year'   => $req->int('year'),
            'sort'   => Security::sanitizeText($req->get('sort', 'updated')),
        ];

        $page   = max(1, $req->int('page', 1));
        $search = new MangaSearch();
        $result = $search->advancedSearch(array_filter($filters), $page, 24);

        $manga     = new Manga();
        $genres    = $manga->allGenres();

        $this->seo->setFromArchive('Browse Manga', 'Browse and read manga, novels, and videos online.');

        $this->view('manga/index', [
            'result'  => $result,
            'filters' => $filters,
            'genres'  => $genres,
        ]);
    }

    // ── Single manga ──────────────────────────────────────────────────────────

    public function show(Request $req, array $params): void
    {
        $slug  = $params['slug'] ?? '';
        $manga = new Manga();
        $m     = $manga->findBySlug($slug);

        if (!$m) {
            Response::notFound("Manga not found.");
        }

        // Adult gate
        if ($m['adult'] && Auth::guest()) {
            $this->view('manga/adult-gate', ['manga' => $m]);
            return;
        }

        $chapter = new Chapter();
        $page    = max(1, $req->int('page', 1));
        $chapters = $chapter->paginated((int)$m['id'], $page, 50);

        // User bookmark status
        $isBookmarked = false;
        $userRating   = null;
        if (Auth::check()) {
            $uid = Auth::id();
            $bm  = new \Alpha\Models\Bookmark();
            $rt  = new \Alpha\Models\Rating();
            $isBookmarked = $bm->isBookmarked($uid, (int)$m['id']);
            $userRating   = $rt->getUserRating($uid, (int)$m['id']);
        }

        $manga->incrementViews((int)$m['id']);
        $this->seo->setFromManga($m);

        $this->view('manga/show', [
            'manga'        => $m,
            'chapters'     => $chapters,
            'isBookmarked' => $isBookmarked,
            'userRating'   => $userRating,
        ]);
    }

    // ── Upload form ───────────────────────────────────────────────────────────

    public function uploadForm(Request $req, array $params): void
    {
        $this->requireCan('upload_manga');
        $manga  = new Manga();
        $genres = $manga->allGenres();
        $this->view('manga/upload', ['genres' => $genres]);
    }

    public function uploadStore(Request $req, array $params): void
    {
        $this->requireCan('upload_manga');
        $this->verifyCsrf();

        $data = [
            'title'       => Security::sanitizeText($req->post('title', '')),
            'alt_names'   => Security::sanitizeText($req->post('alt_names', '')),
            'author'      => Security::sanitizeText($req->post('author', '')),
            'artist'      => Security::sanitizeText($req->post('artist', '')),
            'synopsis'    => Security::sanitizeTextarea($req->post('synopsis', '')),
            'type'        => Security::sanitizeText($req->post('type', 'manga')),
            'status'      => Security::sanitizeText($req->post('status', 'ongoing')),
            'release_year'=> $req->int('release_year'),
            'adult'       => $req->bool('adult') ? 1 : 0,
            'uploader_id' => Auth::id(),
            'rating_avg'  => 0,
            'rating_count'=> 0,
            'views'       => 0,
        ];

        if (empty($data['title'])) {
            $this->redirect('manga/upload', 'Title is required.', 'error');
        }

        // Handle cover upload
        $cover = $req->file('cover');
        if ($cover && $cover['error'] === UPLOAD_ERR_OK) {
            if (!Security::isAllowedImageType($cover['tmp_name'])) {
                $this->redirect('manga/upload', 'Invalid cover image type.', 'error');
            }
            // Temporary ID; will be set after insert
            $manga  = new Manga();
            $id     = $manga->createWithSlug($data);
            $url    = \Alpha\Services\Storage\StorageManager::uploadCover($cover['tmp_name'], $id, $cover['name']);
            $manga->update($id, ['cover' => $url]);
        } else {
            $manga = new Manga();
            $id    = $manga->createWithSlug($data);
        }

        // Attach genres
        $genreIds = array_map('intval', (array)($req->post('genres') ?? []));
        if ($genreIds) {
            (new Manga())->setGenres($id, $genreIds);
        }

        $this->redirect("manga/{$id}", 'Manga submitted successfully!');
    }
}
