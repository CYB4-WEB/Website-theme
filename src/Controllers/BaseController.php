<?php

declare(strict_types=1);

namespace Alpha\Controllers;

use Alpha\Core\{Config, Request, Response, Session, View};
use Alpha\Services\{Auth, SEO};

abstract class BaseController
{
    protected Request $request;
    protected SEO     $seo;

    public function __construct()
    {
        $this->request = Request::getInstance();
        $this->seo     = new SEO();
    }

    // ── View helpers ──────────────────────────────────────────────────────────

    protected function view(string $view, array $data = [], string $layout = 'layouts/app'): void
    {
        $data['seo']   = $this->seo;
        $data['user']  = Auth::user();
        $data['flash'] = Session::flashAll();
        View::renderWithLayout($layout, $view, $data);
    }

    protected function json(mixed $data, int $status = 200): void
    {
        Response::json($data, $status);
    }

    protected function redirect(string $path, string $message = '', string $type = 'success'): void
    {
        if ($message) {
            Session::flash($type, $message);
        }
        Response::redirect(View::url($path));
    }

    protected function back(string $message = '', string $type = 'success'): void
    {
        if ($message) {
            Session::flash($type, $message);
        }
        $ref = $_SERVER['HTTP_REFERER'] ?? '/';
        Response::redirect($ref);
    }

    // ── Auth guards ───────────────────────────────────────────────────────────

    protected function requireAuth(): void
    {
        if (Auth::guest()) {
            Session::flash('error', 'Please log in to continue.');
            Response::redirect(View::url('login'));
        }
    }

    protected function requireAdmin(): void
    {
        $this->requireAuth();
        if (!Auth::isAdmin()) {
            Response::forbidden();
        }
    }

    protected function requireCan(string $cap): void
    {
        if (!Auth::can($cap)) {
            if (Auth::guest()) {
                $this->redirect('login', 'Please log in.', 'error');
            }
            Response::forbidden();
        }
    }

    // ── AJAX helpers ──────────────────────────────────────────────────────────

    protected function verifyNonce(string $action): void
    {
        $nonce = $this->request->post('_nonce') ?? $this->request->get('_nonce');
        if (!$nonce || !\Alpha\Services\Security::verifyNonce($nonce, $action)) {
            $this->json(['success' => false, 'message' => 'Security check failed.'], 403);
        }
    }

    protected function verifyCsrf(): void
    {
        $token = $this->request->post('_csrf');
        if (!$token || !Session::verifyCsrf($token)) {
            $this->json(['success' => false, 'message' => 'CSRF check failed.'], 403);
        }
    }

    protected function isAjax(): bool
    {
        return $this->request->isAjax();
    }
}
