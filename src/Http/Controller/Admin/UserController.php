<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Core\View;
use App\Domain\AdminRole;
use App\Exception\NotFoundException;
use App\Repository\AdminUserRepository;
use App\Repository\CompanyRepository;

/**
 * Back office accounts. Office-only: the routes carry 'superadmin' => true,
 * so a company account cannot reach any of this even by typing the URL.
 *
 * Passwords are generated here rather than typed by the office: a password
 * chosen for someone else tends to be weak and reused. The generated one is
 * shown once, on the redirect, and only its hash is stored.
 */
final class UserController
{
    private const PASSWORD_BYTES = 9; // 18 hex chars

    /** GET /admin/users */
    public function index(Request $request): Response
    {
        return Response::html(View::render('admin/users_index', [
            'title' => 'アカウントの管理',
            'users' => (new AdminUserRepository())->listAll(),
            'me'    => Auth::id(),
        ], 'layouts/admin'));
    }

    /** GET /admin/users/new */
    public function create(Request $request): Response
    {
        return $this->renderForm(null, [], []);
    }

    /** POST /admin/users */
    public function store(Request $request): Response
    {
        Csrf::verify($request);

        $input = $this->validate($request, null);
        if ($input instanceof Response) {
            return $input;
        }

        $password = bin2hex(random_bytes(self::PASSWORD_BYTES));
        (new AdminUserRepository())->create(
            (string) $input['username'],
            Auth::hashPassword($password),
            (string) $input['display_name'],
            (string) $input['role'],
            $input['role'] === AdminRole::Company->value ? (int) $input['company_id'] : null,
        );

        Flash::success("アカウント「{$input['username']}」を作成しました。初回パスワード: {$password}");
        Flash::info('このパスワードはこの画面でしか表示されません。本人に安全な方法で伝えてください。');
        return Response::redirect('/admin/users');
    }

    /** GET /admin/users/{id}/edit */
    public function edit(Request $request): Response
    {
        return $this->renderForm($this->load($request->routeInt('id')), [], []);
    }

    /** POST /admin/users/{id} */
    public function update(Request $request): Response
    {
        Csrf::verify($request);
        $user = $this->load($request->routeInt('id'));

        $input = $this->validate($request, (int) $user['id']);
        if ($input instanceof Response) {
            return $input;
        }

        // Demoting the last usable office account would lock everyone out of
        // the screens only the office can reach - including this one.
        $repo = new AdminUserRepository();
        if ((string) $user['role'] === AdminRole::Superadmin->value
            && $input['role'] !== AdminRole::Superadmin->value
            && $repo->activeSuperadminCount() <= 1
        ) {
            Flash::error('最後の事務局アカウントは会社担当者に変更できません。先に別の事務局アカウントを作成してください。');
            return Response::redirect('/admin/users');
        }

        $repo->updateProfile(
            (int) $user['id'],
            (string) $input['display_name'],
            (string) $input['role'],
            $input['role'] === AdminRole::Company->value ? (int) $input['company_id'] : null,
        );

        Flash::success('アカウントを更新しました。');
        return Response::redirect('/admin/users');
    }

    /** POST /admin/users/{id}/password - issue a new one. */
    public function resetPassword(Request $request): Response
    {
        Csrf::verify($request);
        $user = $this->load($request->routeInt('id'));

        $password = bin2hex(random_bytes(self::PASSWORD_BYTES));
        // updatePassword also clears failed_attempts and locked_until, so this
        // is how a locked-out account gets back in.
        (new AdminUserRepository())->updatePassword((int) $user['id'], Auth::hashPassword($password));

        Flash::success("「{$user['username']}」の新しいパスワード: {$password}");
        Flash::info('この画面でしか表示されません。ロックも解除されました。');
        return Response::redirect('/admin/users');
    }

    /** POST /admin/users/{id}/toggle - activate or deactivate. */
    public function toggleActive(Request $request): Response
    {
        Csrf::verify($request);
        $user = $this->load($request->routeInt('id'));
        $repo = new AdminUserRepository();

        $activate = (int) $user['is_active'] !== 1;

        if (!$activate) {
            if ((int) $user['id'] === Auth::id()) {
                Flash::error('自分自身のアカウントは無効にできません。');
                return Response::redirect('/admin/users');
            }
            if ((string) $user['role'] === AdminRole::Superadmin->value
                && $repo->activeSuperadminCount() <= 1
            ) {
                Flash::error('最後の事務局アカウントは無効にできません。');
                return Response::redirect('/admin/users');
            }
        }

        $repo->setActive((int) $user['id'], $activate);
        Flash::success($activate
            ? "「{$user['username']}」を有効にしました。"
            : "「{$user['username']}」を無効にしました。以後ログインできません。");
        return Response::redirect('/admin/users');
    }

    /** @return array<string, mixed> */
    private function load(int $id): array
    {
        $user = (new AdminUserRepository())->find($id);
        if ($user === null) {
            throw new NotFoundException('お探しのアカウントは見つかりませんでした。');
        }
        return $user;
    }

    /** @return array<string, mixed>|Response */
    private function validate(Request $request, ?int $exceptId): array|Response
    {
        $companies = (new CompanyRepository())->options();

        $validator = new Validator();
        $validator->required('display_name', '表示名', $request->post('display_name'))
                  ->maxLength('display_name', '表示名', $request->post('display_name'), 100);
        $validator->inList('role', '種別', $request->post('role'), [
            AdminRole::Superadmin->value,
            AdminRole::Company->value,
        ]);

        // The username is fixed after creation: it is what booking_events
        // records as the actor, and rewriting history to match a rename would
        // be worse than living with the old name.
        if ($exceptId === null) {
            $username = trim($request->post('username'));
            $validator->required('username', 'ユーザー名', $username)
                      ->maxLength('username', 'ユーザー名', $username, 60);
            if (!$validator->hasErrors() && !preg_match('/^[A-Za-z0-9._-]+$/', $username)) {
                $validator->fail('username', 'ユーザー名は英数字と . _ - のみ使用できます。');
            }
            if (!$validator->hasErrors() && (new AdminUserRepository())->usernameExists($username)) {
                $validator->fail('username', 'このユーザー名は既に使われています。');
            }
        }

        if ($request->post('role') === AdminRole::Company->value) {
            $validator->inList(
                'company_id',
                '所属会社',
                $request->post('company_id'),
                array_map('strval', array_keys($companies))
            );
        }

        if (!$validator->hasErrors()) {
            return $validator->values();
        }

        $user = $exceptId !== null ? $this->load($exceptId) : null;
        return $this->renderForm($user, $validator->errors(), [
            'username'     => $request->post('username'),
            'display_name' => $request->post('display_name'),
            'role'         => $request->post('role'),
            'company_id'   => $request->post('company_id'),
        ]);
    }

    /**
     * @param array<string, mixed>|null $user
     * @param array<string, string>     $errors
     * @param array<string, string>     $old
     */
    private function renderForm(?array $user, array $errors, array $old): Response
    {
        return Response::html(View::render('admin/user_form', [
            'title'     => $user === null ? 'アカウントの作成' : 'アカウントの編集',
            'user'      => $user,
            'errors'    => $errors,
            'old'       => $old,
            'companies' => (new CompanyRepository())->options(),
        ], 'layouts/admin'), $errors === [] ? 200 : 422);
    }
}
