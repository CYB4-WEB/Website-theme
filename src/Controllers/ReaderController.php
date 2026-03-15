<?php

declare(strict_types=1);

namespace Alpha\Controllers;

use Alpha\Core\{Request, Response};
use Alpha\Models\{Manga, Chapter, Coin, History};
use Alpha\Services\Auth;

class ReaderController extends BaseController
{
    public function show(Request $req, array $params): void
    {
        $mangaSlug     = $params['slug']   ?? '';
        $chapterNumber = (float)($params['chapter'] ?? 0);

        $manga   = new Manga();
        $chapter = new Chapter();

        $m = $manga->findBySlug($mangaSlug);
        if (!$m) {
            Response::notFound();
        }

        $ch = $chapter->findByNumber((int)$m['id'], $chapterNumber);
        if (!$ch) {
            Response::notFound();
        }

        // Premium / coin gate
        $canAccess = $this->checkAccess($m, $ch);
        if (!$canAccess['allowed']) {
            $this->view('manga/locked', [
                'manga'   => $m,
                'chapter' => $ch,
                'reason'  => $canAccess['reason'],
            ]);
            return;
        }

        // Navigation
        $prev = $chapter->getPrev((int)$m['id'], $chapterNumber);
        $next = $chapter->getNext((int)$m['id'], $chapterNumber);

        // Count view
        $chapter->countView((int)$ch['id'], Auth::id(), $req->ip());

        // Save reading position for logged-in users
        if (Auth::check()) {
            (new History())->savePosition(Auth::id(), (int)$m['id'], (int)$ch['id'], 0);
        }

        $this->seo->setFromChapter($m, $ch);

        $layout = match ($ch['chapter_type']) {
            'text'  => 'manga/reader-novel',
            'video' => 'manga/reader-video',
            default => 'manga/reader',
        };

        $this->view($layout, [
            'manga'   => $m,
            'chapter' => $ch,
            'prev'    => $prev,
            'next'    => $next,
            'layout'  => 'layouts/reader',
        ], 'layouts/reader');
    }

    private function checkAccess(array $manga, array $chapter): array
    {
        $uid = Auth::id();

        // Public free chapter
        if (!$chapter['is_premium'] && !$chapter['coin_price']) {
            return ['allowed' => true, 'reason' => ''];
        }

        // VIP / editor / admin bypass
        if (Auth::can('access_premium')) {
            return ['allowed' => true, 'reason' => ''];
        }

        // Must be logged in
        if (!$uid) {
            return ['allowed' => false, 'reason' => 'login'];
        }

        // Coin-priced chapter
        if ($chapter['coin_price'] > 0) {
            $coin = new Coin();
            if ($coin->isUnlocked($uid, (int)$chapter['id'])) {
                return ['allowed' => true, 'reason' => ''];
            }
            return ['allowed' => false, 'reason' => 'coins', 'price' => $chapter['coin_price']];
        }

        // Premium-only (subscription)
        return ['allowed' => false, 'reason' => 'premium'];
    }
}
