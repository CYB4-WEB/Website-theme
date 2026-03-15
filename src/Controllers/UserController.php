<?php

declare(strict_types=1);

namespace Alpha\Controllers;

use Alpha\Core\{Config, Request, Response};
use Alpha\Models\{User, Coin};
use Alpha\Services\{Auth, Security};

class UserController extends BaseController
{
    // ── Auth pages ────────────────────────────────────────────────────────────

    public function loginForm(Request $req, array $params): void
    {
        if (Auth::check()) {
            Response::redirect(\Alpha\Core\View::url(''));
        }
        $this->view('user/login', [], 'layouts/minimal');
    }

    public function loginStore(Request $req, array $params): void
    {
        if (!Security::checkHoneypot($_POST)) {
            $this->back('Security check failed.', 'error');
        }

        $result = Auth::attempt(
            $req->str('identifier'),
            $req->post('password', ''),
            $req->bool('remember'),
            $req->ip()
        );

        if (!$result['success']) {
            \Alpha\Core\Session::flash('error', $result['message']);
            Response::redirect(\Alpha\Core\View::url('login'));
        }

        $intended = \Alpha\Core\Session::get('intended', '');
        \Alpha\Core\Session::remove('intended');
        Response::redirect($intended ?: \Alpha\Core\View::url(''));
    }

    public function logout(Request $req, array $params): void
    {
        Auth::logout();
        Response::redirect(\Alpha\Core\View::url('login'));
    }

    public function registerForm(Request $req, array $params): void
    {
        if (!Config::bool('FEATURE_REGISTRATION', true)) {
            Response::notFound();
        }
        if (Auth::check()) {
            Response::redirect(\Alpha\Core\View::url(''));
        }
        $this->view('user/register', [], 'layouts/minimal');
    }

    public function registerStore(Request $req, array $params): void
    {
        if (!Config::bool('FEATURE_REGISTRATION', true)) {
            Response::notFound();
        }
        if (!Security::checkHoneypot($_POST)) {
            $this->back('Security check failed.', 'error');
        }

        $result = Auth::register([
            'username'     => $req->str('username'),
            'email'        => Security::sanitizeEmail($req->post('email', '')),
            'password'     => $req->post('password', ''),
            'display_name' => $req->str('display_name'),
        ]);

        if (!$result['success']) {
            \Alpha\Core\Session::flash('errors', $result['errors']);
            Response::redirect(\Alpha\Core\View::url('register'));
        }

        \Alpha\Core\Session::flash('success', 'Account created! Please log in.');
        Response::redirect(\Alpha\Core\View::url('login'));
    }

    // ── Profile ───────────────────────────────────────────────────────────────

    public function profile(Request $req, array $params): void
    {
        $this->requireAuth();
        $uid    = Auth::id();
        $user   = new User();
        $coin   = new Coin();
        $u      = $user->find($uid);

        $this->view('user/profile', [
            'profile'      => $user->safe($u),
            'bookmarks'    => $user->getBookmarks($uid),
            'history'      => $user->getReadingHistory($uid),
            'coinBalance'  => $coin->getBalance($uid),
            'transactions' => $coin->getTransactions($uid, 10),
        ]);
    }

    public function profileUpdate(Request $req, array $params): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        $uid  = Auth::id();
        $data = [];

        if ($req->str('display_name')) {
            $data['display_name'] = $req->str('display_name');
        }
        if ($req->str('email')) {
            $email = Security::sanitizeEmail($req->post('email', ''));
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $data['email'] = $email;
            }
        }

        // Password change
        $newPass = $req->post('new_password', '');
        if ($newPass) {
            $curPass = $req->post('current_password', '');
            $user    = new User();
            $u       = $user->find($uid);
            if (!password_verify($curPass, $u['password'])) {
                $this->back('Current password is incorrect.', 'error');
            }
            if (strlen($newPass) < 8) {
                $this->back('Password must be at least 8 characters.', 'error');
            }
            $data['password'] = password_hash($newPass, PASSWORD_BCRYPT, ['cost' => 12]);
        }

        // Avatar upload
        $avatar = $req->file('avatar');
        if ($avatar && $avatar['error'] === UPLOAD_ERR_OK) {
            if (Security::isAllowedImageType($avatar['tmp_name'])) {
                $url = \Alpha\Services\Storage\StorageManager::driver()->upload(
                    $avatar['tmp_name'],
                    "avatars/{$uid}." . pathinfo($avatar['name'], PATHINFO_EXTENSION)
                );
                $data['avatar'] = $url;
            }
        }

        if ($data) {
            (new User())->update($uid, $data);
            Auth::refresh();
        }

        $this->back('Profile updated successfully.');
    }
}
