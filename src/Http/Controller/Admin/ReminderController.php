<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Core\Auth;
use App\Core\Authz;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Core\Validator;
use App\Core\View;
use App\Mail\MailDispatcher;
use App\Repository\MailQueueRepository;
use App\Service\ReminderService;

/**
 * The day-before reminder, as the office sees it.
 *
 * Office only. The reminder covers the whole site in one message per person,
 * so there is no company-scoped version of it to show - a company account
 * would be looking at other companies' sessions by definition.
 *
 * The screen exists mostly so that sending is a decision. In approval mode
 * nothing leaves until someone presses the button here; in automatic mode the
 * nightly run sends and this screen is where you look afterwards, or beforehand
 * if you want to take a cancelled session out.
 */
final class ReminderController
{
    /** GET /admin/reminders?date= */
    public function index(Request $request): Response
    {
        Authz::requireSuperadmin();

        return $this->render($request, []);
    }

    /** POST /admin/reminders/settings - the switch, the mode, and who is included. */
    public function saveSettings(Request $request): Response
    {
        Authz::requireSuperadmin();
        Csrf::verify($request);

        $mode = $request->post('mode') === Settings::REMINDER_MODE_AUTO
            ? Settings::REMINDER_MODE_AUTO
            : Settings::REMINDER_MODE_APPROVAL;

        Settings::set(Settings::REMINDER_ENABLED, $request->has('enabled') ? '1' : '0');
        Settings::set(Settings::REMINDER_MODE, $mode);
        Settings::set(
            Settings::REMINDER_INCLUDE_WAITLISTED,
            $request->has('include_waitlisted') ? '1' : '0'
        );

        Flash::success($this->describeSettings());
        return Response::redirect('/admin/reminders?date=' . urlencode($this->date($request)));
    }

    /**
     * POST /admin/reminders/notice - the one editable part of the message, and
     * the sessions to leave out.
     */
    public function saveNotice(Request $request): Response
    {
        Authz::requireSuperadmin();
        Csrf::verify($request);

        $validator = new Validator();
        $validator->maxLength('notice', 'お知らせ', $request->post('notice'), 2000);
        if ($validator->hasErrors()) {
            return $this->render($request, $validator->errors());
        }

        $notice = trim((string) $request->post('notice'));
        Settings::set(Settings::REMINDER_NOTICE, $notice === '' ? null : $notice);

        /*
         * The skip list is global and permanent, but this form only shows one
         * day - so the day's sessions are removed from the stored list and the
         * ones left unchecked are put back. Another day's exclusions are left
         * alone rather than being wiped by a form that never mentioned them.
         */
        $service = new ReminderService();
        $onThisDay = array_map(
            static fn (array $row): int => (int) $row['id'],
            $service->sessionsOn($this->date($request))
        );
        $keep = array_values(array_diff(Settings::reminderSkippedSessions(), $onThisDay));

        $included = $request->postInts('sessions');
        foreach ($onThisDay as $id) {
            if (!in_array($id, $included, true)) {
                $keep[] = $id;
            }
        }
        Settings::setReminderSkippedSessions($keep);

        Flash::success('保存しました。送信内容をご確認ください。');
        return Response::redirect('/admin/reminders?date=' . urlencode($this->date($request)));
    }

    /** POST /admin/reminders/test - one copy, to whoever is signed in. */
    public function test(Request $request): Response
    {
        Authz::requireSuperadmin();
        Csrf::verify($request);

        $validator = new Validator();
        $validator->email('test_email', 'テスト送信先', $request->post('test_email'));
        if ($validator->hasErrors()) {
            return $this->render($request, $validator->errors());
        }

        $date = $this->date($request);
        $service = new ReminderService();
        $recipients = $service->recipients($date, Settings::reminderIncludesWaitlisted(), false);

        if ($recipients === []) {
            Flash::error('この日に対象の予約がないため、テスト送信する本文を作れません。');
            return Response::redirect('/admin/reminders?date=' . urlencode($date));
        }

        $to = (string) $validator->value('test_email');
        $id = (new MailQueueRepository())->enqueue(
            $to,
            (string) (Auth::user()['display_name'] ?? '') ?: null,
            '[テスト] ' . $service->subject($date),
            $service->compose($recipients[0], $date, (string) (Settings::get(Settings::REMINDER_NOTICE) ?? '')),
            null,
            MailQueueRepository::REMINDER,
        );
        $result = (new MailDispatcher())->processIds([$id]);

        if ($result['failed'] > 0) {
            Flash::error("テスト送信に失敗しました（{$to}）。メール送信キューの「失敗」で last_error をご確認ください。");
        } else {
            Flash::success("テスト送信しました（{$to}）。1 件目の方に届く本文と同じものです。");
        }
        return Response::redirect('/admin/reminders?date=' . urlencode($date));
    }

    /** POST /admin/reminders/send - queue the day's reminders. */
    public function send(Request $request): Response
    {
        Authz::requireSuperadmin();
        Csrf::verify($request);

        $date = $this->date($request);
        $service = new ReminderService();
        $recipients = $service->recipients($date, Settings::reminderIncludesWaitlisted());

        if ($recipients === []) {
            Flash::info('送信対象がありません（すでに送信済みか、この日に予約がありません）。');
            return Response::redirect('/admin/reminders?date=' . urlencode($date));
        }

        $queued = $service->enqueue(
            $recipients,
            $date,
            (string) (Settings::get(Settings::REMINDER_NOTICE) ?? '')
        );

        Flash::success(
            "{$queued} 件をキューに積みました。下の「未送信を今すぐ送る」か定期実行で順次送信されます。"
        );
        return Response::redirect('/admin/mail?category=reminder');
    }

    private function date(Request $request): string
    {
        $raw = trim((string) ($request->post('date') ?: $request->query('date')));
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1
            ? $raw
            : ReminderService::defaultDate();
    }

    private function describeSettings(): string
    {
        if (!Settings::reminderEnabled()) {
            return 'リマインドを「使わない」に設定しました。定期実行は何もしません。';
        }
        return Settings::reminderSendsItself()
            ? '自動送信に設定しました。定期実行が前日の朝にそのまま送信します。'
            : '承認制に設定しました。この画面で送信ボタンを押すまで送られません。';
    }

    /** @param array<string, string> $errors */
    private function render(Request $request, array $errors): Response
    {
        $date = $this->date($request);
        $service = new ReminderService();

        $skipped = Settings::reminderSkippedSessions();
        $sessions = $service->sessionsOn($date);
        $recipients = $service->recipients($date, Settings::reminderIncludesWaitlisted());
        $pending = $service->recipients($date, Settings::reminderIncludesWaitlisted(), false);

        return Response::html(View::render('admin/reminders', [
            'title'      => 'リマインド送信',
            'date'       => $date,
            'sessions'   => $sessions,
            'skipped'    => $skipped,
            'recipients' => $recipients,
            // Used only to build the sample body, so it ignores who has already
            // been reminded - otherwise the preview would go blank after sending.
            'sample'     => $pending === [] ? null : $service->compose(
                $pending[0],
                $date,
                (string) (Settings::get(Settings::REMINDER_NOTICE) ?? '')
            ),
            'notice'     => (string) (Settings::get(Settings::REMINDER_NOTICE) ?? ''),
            'enabled'    => Settings::reminderEnabled(),
            'auto'       => Settings::reminderSendsItself(),
            'waitlisted' => Settings::reminderIncludesWaitlisted(),
            'errors'     => $errors,
        ], 'layouts/admin'), $errors === [] ? 200 : 422);
    }
}
