<?php

declare(strict_types=1);

namespace Alpha\Controllers;

use Alpha\Core\Request;
use Alpha\Models\{Manga, Chapter};

class HomeController extends BaseController
{
    public function index(Request $req, array $params): void
    {
        $manga   = new Manga();
        $chapter = new Chapter();

        $latest  = $manga->getLatestUpdated(12);
        $popular = $manga->getPopular('week', 10);
        $new     = $manga->findAll([], 'ORDER BY created_at DESC', 8);
        $updates = $chapter->latestAcrossAll(20);

        $this->seo->setFromArchive();

        $this->view('home/index', [
            'latest'  => $latest,
            'popular' => $popular,
            'new'     => $new,
            'updates' => $updates,
        ]);
    }
}
