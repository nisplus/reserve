<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Core\Authz;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Core\Validator;
use App\Core\View;
use DateTimeImmutable;

/**
 * The site-wide booking switch.
 *
 * Superadmin only. A company account administers its own programmes; stopping
 * the whole site is not its to do, and Authz::requireSuperadmin is what says
 * so rather than the absence of a link in the menu.
 */
final class SettingsController
{
    /** GET /admin/settings */
    public function edit(Request $request): Response
    {
        Authz::requireSuperadmin();
        return $this->render([], []);
    }

    /** POST /admin/settings */
    public function update(Request $request): Response
    {
        Authz::requireSuperadmin();
        Csrf::verify($request);

        $validator = new Validator();

        /*
         * Both datetimes are optional and independently so - a schedule may
         * have a start, an end, both or neither - so they cannot go through
         * the validator's required-field helpers. Blank means "no bound",
         * which is the normal answer, not an error.
         */
        $times = [];
        foreach (['opens_at' => '受付開始日時', 'closes_at' => '受付終了日時'] as $field => $label) {
            $posted = trim((string) $request->post($field));
            if ($posted === '') {
                $times[$field] = null;
                continue;
            }
            // The form posts datetime-local (Y-m-d\TH:i); store seconds too.
            $parsed = date_create_immutable(str_replace('T', ' ', $posted));
            if ($parsed === false) {
                $validator->fail($field, "{$label}を解釈できません。例: 2026-09-11 10:00");
                $times[$field] = null;
                continue;
            }
            $times[$field] = $parsed;
        }

        if ($times['opens_at'] !== null && $times['closes_at'] !== null
            && $times['closes_at'] <= $times['opens_at']
        ) {
            $validator->fail('closes_at', '受付終了日時は開始日時より後にしてください。');
        }

        $message = trim((string) $request->post('closed_message'));
        if (mb_strlen($message) > 1000) {
            $validator->fail('closed_message', '停止中の案内文は1000文字以内で入力してください。');
        }

        if ($validator->hasErrors()) {
            return $this->render($validator->errors(), [
                'enabled'        => $request->has('enabled') ? '1' : '',
                'opens_at'       => $request->post('opens_at'),
                'closes_at'      => $request->post('closes_at'),
                'closed_message' => $request->post('closed_message'),
            ]);
        }

        Settings::set(Settings::BOOKING_ENABLED, $request->has('enabled') ? '1' : '0');
        Settings::set(
            Settings::BOOKING_OPENS_AT,
            $times['opens_at']?->format('Y-m-d H:i:s')
        );
        Settings::set(
            Settings::BOOKING_CLOSES_AT,
            $times['closes_at']?->format('Y-m-d H:i:s')
        );
        Settings::set(Settings::BOOKING_CLOSED_MESSAGE, $message !== '' ? $message : null);

        // Say what the setting means right now rather than that it was saved:
        // the combination of a switch and two datetimes is easy to leave in a
        // state the operator did not intend, and this is the moment to notice.
        $window = Settings::bookingWindow();
        $now = new DateTimeImmutable('now');
        Flash::success(
            $window->isOpenAt($now)
                ? '保存しました。現在、予約を受け付けています。'
                : '保存しました。現在、予約の受付を停止しています（' . $window->noticeAt($now) . '）'
        );
        return Response::redirect('/admin/settings');
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, string> $old
     */
    private function render(array $errors, array $old): Response
    {
        $window = Settings::bookingWindow();
        $now = new DateTimeImmutable('now');

        return Response::html(View::render('admin/settings', [
            'title'  => '受付設定',
            'window' => $window,
            'now'    => $now,
            'open'   => $window->isOpenAt($now),
            'notice' => $window->noticeAt($now),
            'errors' => $errors,
            'old'    => $old,
        ], 'layouts/admin'), $errors === [] ? 200 : 422);
    }
}
