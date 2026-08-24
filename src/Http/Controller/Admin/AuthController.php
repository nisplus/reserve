<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;

final class AuthController
{
    /** GET /admin/login */
    public function showLogin(Request $request): Response
    {
        if (Auth::check()) {
            return Response::redirect('/admin');
        }
        return Response::html(View::render('admin/login', [
            'title' => 'ログイン',
            'old'   => [],
        ], 'layouts/admin'));
    }

    /** POST /admin/login */
    public function login(Request $request): Response
    {
        Csrf::verify($request);

        $username = $request->post('username');
        $password = $request->post('password');

        if ($username === '' || $password === '' || !Auth::attempt($username, $password)) {
            // One message for every failure mode - wrong name, wrong password,
            // locked, inactive - so the form reveals nothing about accounts.
            Flash::error('ユーザー名またはパスワードが正しくありません。失敗が続くと一定時間ロックされます。');
            return Response::html(View::render('admin/login', [
                'title' => 'ログイン',
                'old'   => ['username' => $username],
            ], 'layouts/admin'), 422);
        }

        return Response::redirect('/admin');
    }

    /** POST /admin/logout */
    public function logout(Request $request): Response
    {
        Csrf::verify($request);
        Auth::logout();
        Flash::info('ログアウトしました。');
        return Response::redirect('/admin/login');
    }
}
