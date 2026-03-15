<?php

declare(strict_types=1);

namespace Alpha\Controllers;

use Alpha\Core\{Database, Request, Response};
use Alpha\Models\{Manga, Chapter, User, Coin};
use Alpha\Services\Security;

class AdminController extends BaseController
{
    public function __construct()
    {
        parent::__construct();
        $this->requireAdmin();
    }

    // ── Dashboard ─────────────────────────────────────────────────────────────

    public function dashboard(Request $req, array $params): void
    {
        $db    = Database::getInstance();
        $stats = [
            'manga'    => (int)$db->get_var("SELECT COUNT(*) FROM `" . Database::table('manga') . "`"),
            'chapters' => (int)$db->get_var("SELECT COUNT(*) FROM `" . Database::table('chapters') . "`"),
            'users'    => (int)$db->get_var("SELECT COUNT(*) FROM `" . Database::table('users') . "`"),
            'views'    => (int)$db->get_var("SELECT COUNT(*) FROM `" . Database::table('views_log') . "`"),
        ];

        $recent    = (new Chapter())->latestAcrossAll(10);
        $newUsers  = (new User())->findAll([], 'ORDER BY created_at DESC', 5);

        $this->view('admin/dashboard', compact('stats', 'recent', 'newUsers'), 'layouts/admin');
    }

    // ── Manga management ──────────────────────────────────────────────────────

    public function mangaIndex(Request $req, array $params): void
    {
        $page   = max(1, $req->int('page', 1));
        $manga  = new Manga();
        $result = $manga->listing([], 'created_at DESC', $page, 20);
        $this->view('admin/manga/index', compact('result'), 'layouts/admin');
    }

    public function mangaEdit(Request $req, array $params): void
    {
        $id    = (int)$params['id'];
        $manga = (new Manga())->find($id);
        if (!$manga) {
            Response::notFound();
        }
        $genres    = (new Manga())->allGenres();
        $selected  = array_column((new Manga())->getGenres($id), 'id');
        $this->view('admin/manga/edit', compact('manga', 'genres', 'selected'), 'layouts/admin');
    }

    public function mangaUpdate(Request $req, array $params): void
    {
        $this->verifyCsrf();
        $id   = (int)$params['id'];
        $data = [
            'title'        => Security::sanitizeText($req->post('title', '')),
            'synopsis'     => Security::sanitizeTextarea($req->post('synopsis', '')),
            'author'       => Security::sanitizeText($req->post('author', '')),
            'artist'       => Security::sanitizeText($req->post('artist', '')),
            'status'       => Security::sanitizeText($req->post('status', 'ongoing')),
            'type'         => Security::sanitizeText($req->post('type', 'manga')),
            'release_year' => $req->int('release_year'),
            'adult'        => $req->bool('adult') ? 1 : 0,
            'updated_at'   => date('Y-m-d H:i:s'),
        ];
        (new Manga())->update($id, $data);
        $genreIds = array_map('intval', (array)($req->post('genres') ?? []));
        (new Manga())->setGenres($id, $genreIds);
        $this->redirect('admin/manga', 'Manga updated.', 'success');
    }

    public function mangaDelete(Request $req, array $params): void
    {
        $this->verifyCsrf();
        $id = (int)$params['id'];
        (new Manga())->delete($id);
        $this->json(['success' => true]);
    }

    // ── Chapter management ────────────────────────────────────────────────────

    public function chapters(Request $req, array $params): void
    {
        $mangaId = $req->int('manga_id');
        $page    = max(1, $req->int('page', 1));
        $chapter = new Chapter();
        $manga   = new Manga();

        $result = $mangaId
            ? $chapter->paginated($mangaId, $page, 30, 'publish')
            : $chapter->paginate(
                "SELECT c.*, m.title AS manga_title FROM `" . Database::table('chapters') . "` c JOIN `" . Database::table('manga') . "` m ON m.id = c.manga_id ORDER BY c.created_at DESC",
                [], $page, 30
              );

        $mangas = $manga->findAll([], 'ORDER BY title ASC');
        $this->view('admin/chapters', compact('result', 'mangaId', 'mangas'), 'layouts/admin');
    }

    public function chapterUploadForm(Request $req, array $params): void
    {
        $mangaId = (int)$params['manga_id'];
        $manga   = (new Manga())->find($mangaId);
        if (!$manga) {
            Response::notFound();
        }
        $this->view('admin/chapter-upload', compact('manga'), 'layouts/admin');
    }

    public function chapterStore(Request $req, array $params): void
    {
        $this->verifyCsrf();
        $mangaId = (int)$params['manga_id'];
        $type    = Security::sanitizeText($req->post('chapter_type', 'image'));

        $data = [
            'manga_id'       => $mangaId,
            'chapter_number' => (float)$req->post('chapter_number', 1),
            'title'          => Security::sanitizeText($req->post('title', '')),
            'chapter_type'   => $type,
            'status'         => Security::sanitizeText($req->post('status', 'publish')),
            'is_premium'     => $req->bool('is_premium') ? 1 : 0,
            'coin_price'     => $req->int('coin_price'),
            'chapter_data'   => json_encode([]),
        ];

        // Text chapter
        if ($type === 'text') {
            $data['chapter_data'] = json_encode(['content' => Security::sanitizeTextarea($req->post('content', ''))]);
        }
        // Video chapter
        if ($type === 'video') {
            $data['chapter_data'] = json_encode(['url' => Security::sanitizeUrl($req->post('video_url', '')), 'provider' => 'direct']);
        }

        $chId = (new Chapter())->create($data);
        $this->redirect("admin/manga/{$mangaId}/chapters", "Chapter created (ID #{$chId}).", 'success');
    }

    // ── User management ───────────────────────────────────────────────────────

    public function users(Request $req, array $params): void
    {
        $page   = max(1, $req->int('page', 1));
        $user   = new User();
        $result = $user->paginate(
            "SELECT id, username, email, role, status, created_at, last_login FROM `" . Database::table('users') . "` ORDER BY created_at DESC",
            [], $page, 30
        );
        $this->view('admin/users', compact('result'), 'layouts/admin');
    }

    public function userUpdate(Request $req, array $params): void
    {
        $this->verifyCsrf();
        $id   = (int)$params['id'];
        $data = [
            'role'   => Security::sanitizeText($req->post('role', 'starter_member')),
            'status' => Security::sanitizeText($req->post('status', 'active')),
        ];
        (new User())->update($id, $data);
        $this->json(['success' => true]);
    }

    // ── Settings ──────────────────────────────────────────────────────────────

    public function settings(Request $req, array $params): void
    {
        $this->view('admin/settings', [], 'layouts/admin');
    }

    public function settingsSave(Request $req, array $params): void
    {
        $this->verifyCsrf();
        // Settings saved back to .env or a DB settings table
        $db    = Database::getInstance();
        $sTbl  = Database::table('settings');
        $keys  = ['APP_NAME', 'FEATURE_REGISTRATION', 'FEATURE_COINS', 'FEATURE_IMAGE_ENCRYPTION',
                   'COIN_EXCHANGE_RATE', 'MIN_WITHDRAWAL', 'REVENUE_SHARE_PCT',
                   'RECAPTCHA_SITE_KEY', 'RECAPTCHA_SECRET_KEY',
                   'STORAGE_DRIVER', 'FEATURE_DARK_MODE_DEFAULT'];

        foreach ($keys as $k) {
            $val = $req->post($k);
            if ($val !== null) {
                $exists = $db->get_var("SELECT `key` FROM `{$sTbl}` WHERE `key` = :k LIMIT 1", ['k' => $k]);
                if ($exists) {
                    $db->update($sTbl, ['value' => (string)$val, 'updated_at' => date('Y-m-d H:i:s')], ['key' => $k]);
                } else {
                    $db->insert($sTbl, ['key' => $k, 'value' => (string)$val, 'updated_at' => date('Y-m-d H:i:s')]);
                }
            }
        }
        $this->redirect('admin/settings', 'Settings saved.');
    }

    // ── Withdrawals ───────────────────────────────────────────────────────────

    public function withdrawals(Request $req, array $params): void
    {
        $db    = Database::getInstance();
        $wTbl  = Database::table('withdrawals');
        $uTbl  = Database::table('users');
        $list  = $db->get_results(
            "SELECT w.*, u.username FROM `{$wTbl}` w JOIN `{$uTbl}` u ON u.id = w.user_id ORDER BY w.created_at DESC LIMIT 50"
        );
        $this->view('admin/withdrawals', compact('list'), 'layouts/admin');
    }

    public function processWithdrawal(Request $req, array $params): void
    {
        $this->verifyCsrf();
        $id     = (int)$params['id'];
        $status = in_array($req->post('status'), ['approved', 'rejected']) ? $req->post('status') : 'rejected';

        $db   = Database::getInstance();
        $wTbl = Database::table('withdrawals');
        $db->update($wTbl, [
            'status'       => $status,
            'processed_at' => date('Y-m-d H:i:s'),
        ], ['id' => $id]);

        // If rejected, refund coins
        if ($status === 'rejected') {
            $w = $db->get_row("SELECT * FROM `{$wTbl}` WHERE id = :id", ['id' => $id]);
            if ($w) {
                (new Coin())->add((int)$w['user_id'], (int)$w['amount'], 'refund', 'Withdrawal rejected – refund');
            }
        }
        $this->json(['success' => true]);
    }
}
