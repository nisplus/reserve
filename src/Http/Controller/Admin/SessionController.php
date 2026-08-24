<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Core\Authz;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Core\View;
use App\Domain\TimeRange;
use App\Exception\NotFoundException;
use App\Repository\EventRepository;
use App\Repository\EventSessionRepository;
use DateTimeImmutable;

/**
 * Session CRUD plus the bulk generator.
 *
 * The bulk form is not a convenience extra: 14 companies x 4 events x 5-10
 * slots is several hundred sessions, and entering them one by one is not a
 * realistic way to run this system (docs/design.md calls it out as required).
 */
final class SessionController
{
    private const CAPACITY_MAX = 999;

    /** GET /admin/events/{id}/sessions */
    public function index(Request $request): Response
    {
        $event = $this->loadEvent($request->routeInt('id'));

        return Response::html(View::render('admin/sessions_index', [
            'title'    => '開催回：' . $event['title'],
            'event'    => $event,
            'sessions' => (new EventSessionRepository())->forEvent((int) $event['id']),
        ], 'layouts/admin'));
    }

    /** GET /admin/events/{id}/sessions/new */
    public function create(Request $request): Response
    {
        $event = $this->loadEvent($request->routeInt('id'));
        return $this->renderForm($event, null, [], []);
    }

    /** POST /admin/events/{id}/sessions */
    public function store(Request $request): Response
    {
        Csrf::verify($request);
        $event = $this->loadEvent($request->routeInt('id'));

        $input = $this->validate($request, $event, null);
        if ($input instanceof Response) {
            return $input;
        }

        (new EventSessionRepository())->create(
            (int) $event['id'],
            (string) $input['starts_at'],
            (string) $input['ends_at'],
            (int) $input['capacity'],
            (string) $input['status']
        );

        Flash::success('開催回を登録しました。');
        return Response::redirect('/admin/events/' . (int) $event['id'] . '/sessions');
    }

    /** GET /admin/sessions/{id}/edit */
    public function edit(Request $request): Response
    {
        $session = $this->loadSession($request->routeInt('id'));
        $event   = $this->loadEvent((int) $session['event_id']);
        return $this->renderForm($event, $session, [], []);
    }

    /** POST /admin/sessions/{id} */
    public function update(Request $request): Response
    {
        Csrf::verify($request);
        $session = $this->loadSession($request->routeInt('id'));
        $event   = $this->loadEvent((int) $session['event_id']);

        $input = $this->validate($request, $event, $session);
        if ($input instanceof Response) {
            return $input;
        }

        (new EventSessionRepository())->update(
            (int) $session['id'],
            (string) $input['starts_at'],
            (string) $input['ends_at'],
            (int) $input['capacity'],
            (string) $input['status']
        );

        Flash::success('開催回を更新しました。');
        return Response::redirect('/admin/events/' . (int) $event['id'] . '/sessions');
    }

    /** POST /admin/sessions/{id}/delete */
    public function delete(Request $request): Response
    {
        Csrf::verify($request);
        $session = $this->loadSession($request->routeInt('id'));
        $repo = new EventSessionRepository();

        // Cancelled bookings block deletion too - they are audit history.
        // Closing the session is the way to retire it.
        if ($repo->hasAnyBookings((int) $session['id'])) {
            Flash::error('この開催回には申込履歴があるため削除できません。受付を止めたい場合は「受付終了」に変更してください。');
            return Response::redirect('/admin/events/' . (int) $session['event_id'] . '/sessions');
        }

        $repo->delete((int) $session['id']);
        Flash::success('開催回を削除しました。');
        return Response::redirect('/admin/events/' . (int) $session['event_id'] . '/sessions');
    }

    /** GET /admin/events/{id}/sessions/bulk */
    public function bulkForm(Request $request): Response
    {
        $event = $this->loadEvent($request->routeInt('id'));
        return $this->renderBulk($event, [], []);
    }

    /** POST /admin/events/{id}/sessions/bulk */
    public function bulkStore(Request $request): Response
    {
        Csrf::verify($request);
        $event = $this->loadEvent($request->routeInt('id'));

        $validator = new Validator();
        $validator->datetime('first_start', '初回の開始日時', $request->post('first_start'));
        $validator->intRange('duration_min', '所要時間（分）', $request->post('duration_min'), 5, 600);
        $validator->intRange('gap_min', '間隔（分）', $request->post('gap_min', '0'), 0, 600);
        $validator->intRange('count', '回数', $request->post('count'), 1, 20);
        $validator->intRange('capacity', '定員', $request->post('capacity'), 1, self::CAPACITY_MAX);

        $old = [
            'first_start'  => $request->post('first_start'),
            'duration_min' => $request->post('duration_min'),
            'gap_min'      => $request->post('gap_min'),
            'count'        => $request->post('count'),
            'capacity'     => $request->post('capacity'),
        ];

        if ($validator->hasErrors()) {
            return $this->renderBulk($event, $validator->errors(), $old);
        }

        // Generate every slot first and check them all before writing any:
        // a half-created batch would be worse than a clear error.
        $duration = (int) $validator->value('duration_min');
        $gap      = (int) $validator->value('gap_min');
        $count    = (int) $validator->value('count');
        $start    = new DateTimeImmutable((string) $validator->value('first_start'));

        $repo = new EventSessionRepository();
        $slots = [];
        $conflicts = [];
        for ($i = 0; $i < $count; $i++) {
            $slotStart = $start->modify('+' . $i * ($duration + $gap) . ' minutes');
            $slotEnd   = $slotStart->modify('+' . $duration . ' minutes');
            $slots[] = new TimeRange($slotStart, $slotEnd);

            if ($repo->startExists((int) $event['id'], $slotStart->format('Y-m-d H:i:s'))) {
                $conflicts[] = $slotStart->format('H:i');
            }
        }

        if ($conflicts !== []) {
            $validator->fail('first_start', '同じ開始時刻の開催回が既にあります: ' . implode('、', $conflicts) . '。1件も作成していません。');
            return $this->renderBulk($event, $validator->errors(), $old);
        }

        $capacity = (int) $validator->value('capacity');
        \App\Core\Db::transaction(static function () use ($repo, $event, $slots, $capacity): void {
            foreach ($slots as $slot) {
                $repo->create((int) $event['id'], $slot->startsAt(), $slot->endsAt(), $capacity, 'open');
            }
        });

        Flash::success(count($slots) . ' 件の開催回を作成しました（'
            . jp_datetime($slots[0]->startsAt()) . ' 〜 ' . jp_time($slots[count($slots) - 1]->endsAt()) . '）。');
        return Response::redirect('/admin/events/' . (int) $event['id'] . '/sessions');
    }

    /**
     * Every session screen reaches its data through here, so the ownership
     * check on the parent event covers the sessions underneath it too.
     *
     * @return array<string, mixed>
     */
    private function loadEvent(int $id): array
    {
        $event = (new EventRepository())->findWithCompany($id);
        if ($event === null) {
            throw new NotFoundException('お探しのイベントは見つかりませんでした。');
        }
        Authz::assertCompany((int) $event['company_id']);
        return $event;
    }

    /** @return array<string, mixed> */
    private function loadSession(int $id): array
    {
        $session = (new EventSessionRepository())->find($id);
        if ($session === null) {
            throw new NotFoundException('お探しの開催回は見つかりませんでした。');
        }
        return $session;
    }

    /**
     * @param array<string, mixed>      $event
     * @param array<string, mixed>|null $session Editing target, null when creating.
     * @return array<string, mixed>|Response
     */
    private function validate(Request $request, array $event, ?array $session): array|Response
    {
        $validator = new Validator();
        $validator->datetime('starts_at', '開始日時', $request->post('starts_at'));
        $validator->datetime('ends_at', '終了日時', $request->post('ends_at'));
        $validator->intRange('capacity', '定員', $request->post('capacity'), 1, self::CAPACITY_MAX);
        $validator->inList('status', '受付状態', $request->post('status', 'open'), ['open', 'closed']);

        if (!$validator->hasErrors()) {
            if ((string) $validator->value('ends_at') <= (string) $validator->value('starts_at')) {
                $validator->fail('ends_at', '終了日時は開始日時より後にしてください。');
            }

            if ((new EventSessionRepository())->startExists(
                (int) $event['id'],
                (string) $validator->value('starts_at'),
                $session !== null ? (int) $session['id'] : null
            )) {
                $validator->fail('starts_at', 'このイベントには同じ開始日時の開催回が既にあります。');
            }

            // chk_sessions_seats would reject this at the database anyway, but
            // "現在の確定席数未満" is an explanation and a CHECK violation is not.
            if ($session !== null && (int) $validator->value('capacity') < (int) $session['confirmed_seats']) {
                $validator->fail('capacity', sprintf(
                    '定員は現在の確定席数（%d 名）未満には変更できません。先にキャンセルまたは繰り上げで確定席数を減らしてください。',
                    (int) $session['confirmed_seats']
                ));
            }
        }

        if (!$validator->hasErrors()) {
            return $validator->values();
        }

        return $this->renderForm($event, $session, $validator->errors(), [
            'starts_at' => $request->post('starts_at'),
            'ends_at'   => $request->post('ends_at'),
            'capacity'  => $request->post('capacity'),
            'status'    => $request->post('status'),
        ]);
    }

    /**
     * @param array<string, mixed>      $event
     * @param array<string, mixed>|null $session
     * @param array<string, string>     $errors
     * @param array<string, string>     $old
     */
    private function renderForm(array $event, ?array $session, array $errors, array $old): Response
    {
        return Response::html(View::render('admin/session_form', [
            'title'   => ($session === null ? '開催回の登録：' : '開催回の編集：') . $event['title'],
            'event'   => $event,
            'session' => $session,
            'errors'  => $errors,
            'old'     => $old,
        ], 'layouts/admin'), $errors === [] ? 200 : 422);
    }

    /**
     * @param array<string, mixed>  $event
     * @param array<string, string> $errors
     * @param array<string, string> $old
     */
    private function renderBulk(array $event, array $errors, array $old): Response
    {
        return Response::html(View::render('admin/sessions_bulk', [
            'title'  => '開催回の一括作成：' . $event['title'],
            'event'  => $event,
            'errors' => $errors,
            'old'    => $old,
        ], 'layouts/admin'), $errors === [] ? 200 : 422);
    }
}
