<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Core\Auth;
use App\Core\Authz;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Core\View;
use App\Exception\NotFoundException;
use App\Repository\CompanyRepository;
use App\Repository\EventRepository;

final class EventController
{
    /** Matches the range the booking form accepts for an attendee's age. */
    private const AGE_MIN = 0;
    private const AGE_MAX = 120;

    /** GET /admin/events?company=N */
    public function index(Request $request): Response
    {
        // A company account gets its own id here whatever ?company= said.
        $companyId = Authz::scopeCompanyId($request->queryInt('company'));

        return Response::html(View::render('admin/events_index', [
            'title'     => '体験プログラムの管理',
            'events'    => (new EventRepository())->listForAdmin($companyId),
            'options'   => $this->companyOptions(),
            'companyId' => $companyId ?? 0,
        ], 'layouts/admin'));
    }

    /** GET /admin/events/new?company=N */
    public function create(Request $request): Response
    {
        return $this->renderForm(null, [], [
            'company_id' => (string) (Auth::companyId() ?? $request->queryInt('company')),
        ]);
    }

    /** POST /admin/events */
    public function store(Request $request): Response
    {
        Csrf::verify($request);

        $input = $this->validate($request, null);
        if ($input instanceof Response) {
            return $input;
        }

        // The form asks 予約不要 (the exception); the column stores
        // booking_required (the norm). Inverted once, here.
        $bookingRequired = !$request->has('no_booking');

        (new EventRepository())->create(
            (int) $input['company_id'],
            (string) $input['title'],
            $input['description'] !== null ? (string) $input['description'] : null,
            $input['venue'] !== null ? (string) $input['venue'] : null,
            (int) $input['sort_order'],
            $request->has('is_published'),
            $bookingRequired,
            $input['external_url'] !== null ? (string) $input['external_url'] : null,
            (int) $input['max_party_size'],
            $request->has('guardians_in_party'),
            $input['min_age'] !== null ? (int) $input['min_age'] : null,
            $input['max_age'] !== null ? (int) $input['max_age'] : null,
        );

        Flash::success($bookingRequired
            ? '体験プログラムを登録しました。続けて開催回を登録してください。'
            : '体験プログラムを登録しました（予約不要のため開催回は不要です）。');
        return Response::redirect('/admin/events?company=' . (int) $input['company_id']);
    }

    /** GET /admin/events/{id}/edit */
    public function edit(Request $request): Response
    {
        return $this->renderForm($this->load($request->routeInt('id')), [], []);
    }

    /** POST /admin/events/{id} */
    public function update(Request $request): Response
    {
        Csrf::verify($request);
        $event = $this->load($request->routeInt('id'));

        $input = $this->validate($request, (int) $event['id']);
        if ($input instanceof Response) {
            return $input;
        }

        $bookingRequired = !$request->has('no_booking');

        (new EventRepository())->update(
            (int) $event['id'],
            (int) $input['company_id'],
            (string) $input['title'],
            $input['description'] !== null ? (string) $input['description'] : null,
            $input['venue'] !== null ? (string) $input['venue'] : null,
            (int) $input['sort_order'],
            $request->has('is_published'),
            $bookingRequired,
            $input['external_url'] !== null ? (string) $input['external_url'] : null,
            (int) $input['max_party_size'],
            $request->has('guardians_in_party'),
            $input['min_age'] !== null ? (int) $input['min_age'] : null,
            $input['max_age'] !== null ? (int) $input['max_age'] : null,
        );

        // The flag decides whether the slots are a timetable or something to
        // reserve; it never deletes them. So switching it either way is
        // reversible, and saying so is the point of these two messages.
        $liveSessions = (new EventRepository())->sessionCount((int) $event['id']);
        $wasRequired  = (int) $event['booking_required'] === 1;

        if ($liveSessions > 0 && !$bookingRequired && $wasRequired) {
            Flash::info(
                "この体験プログラムは予約不要になりました。開催回 {$liveSessions} 件は"
                . '時間割として引き続き表示されますが、予約ボタンは出ず、新規予約も受け付けません'
                . '（既存の予約は残ります）。'
            );
        } elseif ($liveSessions > 0 && $bookingRequired && !$wasRequired) {
            Flash::info(
                "この体験プログラムは予約が必要になりました。開催回 {$liveSessions} 件に"
                . '予約ボタンが表示され、受付が始まります。定員をご確認ください。'
            );
        }

        Flash::success('体験プログラムを更新しました。');
        return Response::redirect('/admin/events?company=' . (int) $input['company_id']);
    }

    /** POST /admin/events/{id}/delete */
    public function delete(Request $request): Response
    {
        Csrf::verify($request);
        $event = $this->load($request->routeInt('id'));
        $repo = new EventRepository();

        // FK is ON DELETE RESTRICT; explain instead of letting it explode.
        $sessions = $repo->sessionCount((int) $event['id']);
        if ($sessions > 0) {
            Flash::error("「{$event['title']}」には開催回が {$sessions} 件あるため削除できません。先に開催回を削除してください。");
            return Response::redirect('/admin/events?company=' . (int) $event['company_id']);
        }

        $repo->delete((int) $event['id']);
        Flash::success("「{$event['title']}」を削除しました。");
        return Response::redirect('/admin/events?company=' . (int) $event['company_id']);
    }

    /** Fetch + ownership check in one place, so no screen can skip the check. */
    /** @return array<string, mixed> */
    private function load(int $id): array
    {
        $event = (new EventRepository())->find($id);
        if ($event === null) {
            throw new NotFoundException('お探しの体験プログラムは見つかりませんでした。');
        }
        Authz::assertCompany((int) $event['company_id']);
        return $event;
    }

    /**
     * Companies this account may file an event under: all of them for the
     * office, exactly one for a company account. The form's select is built
     * from this, and so is the validator's whitelist - so a posted company_id
     * outside it is rejected, not just hidden.
     *
     * @return array<int, string>
     */
    private function companyOptions(): array
    {
        $all = (new CompanyRepository())->options();
        $own = Auth::companyId();
        if ($own === null) {
            return $all;
        }
        return isset($all[$own]) ? [$own => $all[$own]] : [];
    }

    /** @return array<string, mixed>|Response */
    private function validate(Request $request, ?int $exceptId): array|Response
    {
        $options = $this->companyOptions();

        $validator = new Validator();
        $validator->inList(
            'company_id',
            '主催会社',
            $request->post('company_id'),
            array_map('strval', array_keys($options))
        );
        $validator->required('title', '体験プログラム名', $request->post('title'))
                  ->maxLength('title', '体験プログラム名', $request->post('title'), 200);
        $validator->optional('description', $request->post('description'), 5000);
        $validator->optional('venue', $request->post('venue'), 200);
        $validator->intRange('sort_order', '表示順', $request->post('sort_order', '0'), 0, 9999);
        $validator->url('external_url', '外部リンクURL', $request->post('external_url'), 500);
        // 20 is the ceiling chk_bookings_party imposes on the column.
        $validator->intRange('max_party_size', '1予約あたりの上限人数', $request->post('max_party_size', '20'), 1, 20);

        /*
         * Age limits. Either end may be left blank and so may both, so these
         * cannot go through intRange - a blank there is an error, and here it
         * is the normal answer meaning "no limit". 0 is a real lower bound and
         * must not read as blank, which is why the test is on the string.
         *
         * The pair is checked here rather than by a CHECK constraint: the one
         * migration 002 tried to add was refused by MariaDB 11.8 even as a
         * standalone ALTER (see 009_age_limits.sql).
         */
        $ages = [];
        foreach (['min_age' => '対象年齢の下限', 'max_age' => '対象年齢の上限'] as $field => $label) {
            $posted = trim((string) $request->post($field));
            if ($posted === '') {
                $ages[$field] = null;
                $validator->set($field, null);
                continue;
            }
            if (!preg_match('/^\d+$/', $posted)
                || (int) $posted < self::AGE_MIN || (int) $posted > self::AGE_MAX
            ) {
                $validator->fail($field, sprintf(
                    '%sは%d〜%dの範囲で入力してください（制限しない場合は空欄）。',
                    $label,
                    self::AGE_MIN,
                    self::AGE_MAX
                ));
                $ages[$field] = null;
                continue;
            }
            $ages[$field] = (int) $posted;
            $validator->set($field, (int) $posted);
        }
        if ($ages['min_age'] !== null && $ages['max_age'] !== null
            && $ages['min_age'] > $ages['max_age']
        ) {
            $validator->fail('min_age', '対象年齢の下限は上限以下にしてください。');
        }

        if (!$validator->hasErrors()) {
            $values = $validator->values();
            $values['company_id'] = (int) $values['company_id'];
            return $values;
        }

        $event = $exceptId !== null ? $this->load($exceptId) : null;
        return $this->renderForm($event, $validator->errors(), [
            'company_id'   => $request->post('company_id'),
            'title'        => $request->post('title'),
            'description'  => $request->post('description'),
            'venue'        => $request->post('venue'),
            'sort_order'   => $request->post('sort_order'),
            'is_published' => $request->has('is_published') ? '1' : '',
            'no_booking'   => $request->has('no_booking') ? '1' : '',
            'guardians_in_party' => $request->has('guardians_in_party') ? '1' : '',
            'min_age'      => $request->post('min_age'),
            'max_age'      => $request->post('max_age'),
            'external_url' => $request->post('external_url'),
            'max_party_size' => $request->post('max_party_size'),
        ]);
    }

    /**
     * @param array<string, mixed>|null $event
     * @param array<string, string>     $errors
     * @param array<string, string>     $old
     */
    private function renderForm(?array $event, array $errors, array $old): Response
    {
        return Response::html(View::render('admin/event_form', [
            'title'   => $event === null ? '体験プログラムの登録' : '体験プログラムの編集',
            'event'   => $event,
            'errors'  => $errors,
            'old'     => $old,
            'options' => $this->companyOptions(),
        ], 'layouts/admin'), $errors === [] ? 200 : 422);
    }
}
