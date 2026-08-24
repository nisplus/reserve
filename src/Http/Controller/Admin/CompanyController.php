<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Core\Csrf;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Core\View;
use App\Exception\NotFoundException;
use App\Repository\AdminUserRepository;
use App\Repository\CompanyRepository;

final class CompanyController
{
    /** GET /admin/companies */
    public function index(Request $request): Response
    {
        $repo = new CompanyRepository();
        $companies = $repo->all();
        foreach ($companies as &$company) {
            $company['event_count'] = $repo->eventCount((int) $company['id']);
        }
        unset($company);

        return Response::html(View::render('admin/companies_index', [
            'title'     => '会社の管理',
            'companies' => $companies,
        ], 'layouts/admin'));
    }

    /** GET /admin/companies/new */
    public function create(Request $request): Response
    {
        return $this->renderForm(null, [], []);
    }

    /** POST /admin/companies */
    public function store(Request $request): Response
    {
        Csrf::verify($request);

        $input = $this->validate($request, null);
        if ($input instanceof Response) {
            return $input;
        }

        (new CompanyRepository())->create(
            (string) $input['name'],
            $input['name_kana'] !== null ? (string) $input['name_kana'] : null,
            (int) $input['sort_order'],
            $request->has('is_published')
        );

        Flash::success('会社を登録しました。');
        return Response::redirect('/admin/companies');
    }

    /** GET /admin/companies/{id}/edit */
    public function edit(Request $request): Response
    {
        return $this->renderForm($this->load($request->routeInt('id')), [], []);
    }

    /** POST /admin/companies/{id} */
    public function update(Request $request): Response
    {
        Csrf::verify($request);
        $company = $this->load($request->routeInt('id'));

        $input = $this->validate($request, (int) $company['id']);
        if ($input instanceof Response) {
            return $input;
        }

        (new CompanyRepository())->update(
            (int) $company['id'],
            (string) $input['name'],
            $input['name_kana'] !== null ? (string) $input['name_kana'] : null,
            (int) $input['sort_order'],
            $request->has('is_published')
        );

        Flash::success('会社を更新しました。');
        return Response::redirect('/admin/companies');
    }

    /** POST /admin/companies/{id}/delete */
    public function delete(Request $request): Response
    {
        Csrf::verify($request);
        $company = $this->load($request->routeInt('id'));
        $repo = new CompanyRepository();

        // Both FKs are ON DELETE RESTRICT; explain instead of letting them explode.
        $events = $repo->eventCount((int) $company['id']);
        if ($events > 0) {
            Flash::error("「{$company['name']}」にはイベントが {$events} 件あるため削除できません。先にイベントを削除してください。");
            return Response::redirect('/admin/companies');
        }

        $users = (new AdminUserRepository())->countForCompany((int) $company['id']);
        if ($users > 0) {
            Flash::error("「{$company['name']}」には担当者アカウントが {$users} 件あるため削除できません。先にアカウントを削除してください。");
            return Response::redirect('/admin/companies');
        }

        $repo->delete((int) $company['id']);
        Flash::success("「{$company['name']}」を削除しました。");
        return Response::redirect('/admin/companies');
    }

    /** @return array<string, mixed> */
    private function load(int $id): array
    {
        $company = (new CompanyRepository())->find($id);
        if ($company === null) {
            throw new NotFoundException('お探しの会社は見つかりませんでした。');
        }
        return $company;
    }

    /** @return array<string, mixed>|Response Values, or the re-rendered form. */
    private function validate(Request $request, ?int $exceptId): array|Response
    {
        $validator = new Validator();
        $validator->required('name', '会社名', $request->post('name'))
                  ->maxLength('name', '会社名', $request->post('name'), 120);
        $validator->optional('name_kana', $request->post('name_kana'), 120);
        $validator->intRange('sort_order', '表示順', $request->post('sort_order', '0'), 0, 9999);

        if (!$validator->hasErrors()
            && (new CompanyRepository())->nameExists((string) $validator->value('name'), $exceptId)
        ) {
            $validator->fail('name', 'この会社名は既に登録されています。');
        }

        if (!$validator->hasErrors()) {
            return $validator->values();
        }

        $company = $exceptId !== null ? $this->load($exceptId) : null;
        return $this->renderForm($company, $validator->errors(), [
            'name'         => $request->post('name'),
            'name_kana'    => $request->post('name_kana'),
            'sort_order'   => $request->post('sort_order'),
            'is_published' => $request->has('is_published') ? '1' : '',
        ]);
    }

    /**
     * @param array<string, mixed>|null $company
     * @param array<string, string>     $errors
     * @param array<string, string>     $old
     */
    private function renderForm(?array $company, array $errors, array $old): Response
    {
        return Response::html(View::render('admin/company_form', [
            'title'   => $company === null ? '会社の登録' : '会社の編集',
            'company' => $company,
            'errors'  => $errors,
            'old'     => $old,
        ], 'layouts/admin'), $errors === [] ? 200 : 422);
    }
}
