<?php

declare(strict_types=1);

/**
 * Route definitions.
 * $router is injected by App::run() via require.
 */

use Alpha\Core\App;

$router = App::getInstance()->router();

// ── Public routes ─────────────────────────────────────────────────────────────

$router->get('/',                             'HomeController@index');

// Browse & search
$router->get('/manga',                        'MangaController@index');
$router->get('/manga/upload',                 'MangaController@uploadForm');
$router->post('/manga/upload',                'MangaController@uploadStore');
$router->get('/manga/{slug}',                 'MangaController@show');

// Reader
$router->get('/manga/{slug}/chapter/{chapter}', 'ReaderController@show');

// Auth
$router->get('/login',                        'UserController@loginForm');
$router->post('/login',                       'UserController@loginStore');
$router->get('/logout',                       'UserController@logout');
$router->get('/register',                     'UserController@registerForm');
$router->post('/register',                    'UserController@registerStore');

// Profile
$router->get('/profile',                      'UserController@profile');
$router->post('/profile',                     'UserController@profileUpdate');

// ── API / AJAX routes ─────────────────────────────────────────────────────────

$router->get('/api/search',          'ApiController@liveSearch');
$router->get('/api/search/advanced', 'ApiController@advancedSearch');
$router->get('/api/search/suggest',  'ApiController@suggestions');
$router->get('/api/sitemap',         'ApiController@sitemap');

$router->post('/api/bookmark',       'ApiController@toggleBookmark');
$router->post('/api/rating',         'ApiController@submitRating');
$router->get('/api/rating',          'ApiController@getUserRating');
$router->post('/api/history',        'ApiController@saveHistory');
$router->post('/api/view',           'ApiController@trackView');
$router->post('/api/unlock',         'ApiController@unlockChapter');
$router->get('/api/coins',           'ApiController@getCoinBalance');
$router->post('/api/upload/images',  'ApiController@uploadChapterImages');

// Sitemap
$router->get('/sitemap.xml',         'ApiController@sitemap');

// ── Admin routes ──────────────────────────────────────────────────────────────

$router->group('/admin', function ($r) {
    $r->get('',                             'AdminController@dashboard');
    $r->get('/manga',                       'AdminController@mangaIndex');
    $r->get('/manga/{id}/edit',             'AdminController@mangaEdit');
    $r->post('/manga/{id}',                 'AdminController@mangaUpdate');
    $r->post('/manga/{id}/delete',          'AdminController@mangaDelete');
    $r->get('/manga/{manga_id}/chapters',   'AdminController@chapters');
    $r->get('/manga/{manga_id}/chapters/create', 'AdminController@chapterUploadForm');
    $r->post('/manga/{manga_id}/chapters',  'AdminController@chapterStore');
    $r->get('/chapters',                    'AdminController@chapters');
    $r->get('/users',                       'AdminController@users');
    $r->post('/users/{id}',                 'AdminController@userUpdate');
    $r->get('/withdrawals',                 'AdminController@withdrawals');
    $r->post('/withdrawals/{id}',           'AdminController@processWithdrawal');
    $r->get('/settings',                    'AdminController@settings');
    $r->post('/settings',                   'AdminController@settingsSave');
});
