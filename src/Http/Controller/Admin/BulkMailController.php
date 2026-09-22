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
use App\Exception\NotFoundException;
use App\Mail\MailDispatcher;
use App\Repository\EventRepository;
use App\Repository\EventSessionRepository;
use App\Repository\MailQueueRepository;
use App\Service\BulkMailService;

/**
 * Bulk announcements to applicants.
 *
 * Reachable by company accounts as well as the office, which is why every
 * path here derives the company scope from the session rather than from the
 * form: a company account may address its own events and sessions only, and
 * may not address everyone. Getting that wrong sends one company's notice to
 * another company's applicants, which cannot be taken back.
 */
final class BulkMailController
{
    private const SUBJECT_MAX = 200;
    private const BODY_MAX = 5000;

    /** GET /admin/mail/bulk */
    public function compose(Request $request): Response
    {
        return $this->render([], [
            'scope'   => $request->query('scope'),
            'event'   => (string) $request->queryInt('event'),
            'session' => (string) $request->queryInt('session'),
        ]);
    }

    /** POST /admin/mail/bulk/preview - count the recipients without sending. */
    public function preview(Request $request): Response
    {
        Csrf::verify($request);

        $input = $this->validate($request);
        if ($input instanceof Response) {
            return $input;
        }

        return $this->render([], $input['old'], $input);
    }

    /** POST /admin/mail/bulk/test - one copy, to the operator's own address. */
    public function test(Request $request): Response
    {
        Csrf::verify($request);

        $input = $this->validate($request);
        if ($input instanceof Response) {
            return $input;
        }

        $validator = new Validator();
        $validator->email('test_email', 'テスト送信先', $request->post('test_email'));
        if ($validator->hasErrors()) {
            return $this->render($validator->errors(), $input['old'], $input);
        }

        $to = (string) $validator->value('test_email');
        $id = (new MailQueueRepository())->enqueue(
            $to,
            (string) (Auth::user()['display_name'] ?? '') ?: null,
            '[テスト] ' . $input['subject'],
            $input['composed'],
            null,
            MailQueueRepository::BULK,
        );
        // This message, now - not the five oldest in the queue. Seeing
        // whether THIS one arrives is the entire point of a test send.
        $result = (new MailDispatcher())->processIds([$id]);

        if ($result['failed'] > 0) {
            Flash::error(
                "テスト送信に失敗しました（{$to}）。"
                . 'メール送信キューの「失敗」で last_error をご確認ください。'
                . '本送信の前に原因を解消してください。'
            );
        } else {
            Flash::success("テスト送信しました（{$to}）。届き方を確認してから本送信してください。");
        }
        return $this->render([], $input['old'], $input);
    }

    /** POST /admin/mail/bulk - queue the campaign. */
    public function send(Request $request): Response
    {
        Csrf::verify($request);

        $input = $this->validate($request);
        if ($input instanceof Response) {
            return $input;
        }

        if ($input['recipients'] === []) {
            return $this->render(
                ['_top' => '送信先が 0 件です。宛先の条件をご確認ください。'],
                $input['old'],
                $input
            );
        }

        $queued = (new BulkMailService())->enqueue(
            $input['recipients'],
            $input['subject'],
            $input['composed'],
        );

        Flash::success(
            "{$queued} 件をキューに積みました。下の「今すぐ送信」か定期実行で順次送信されます。"
        );
        return Response::redirect('/admin/mail');
    }

    /**
     * Validate, resolve the recipients and build the message body.
     *
     * Every action goes through this, so the preview, the test send and the
     * real send are looking at one answer - a preview that counted
     * differently from the send would be worse than no preview at all.
     *
     * @return array<string, mixed>|Response
     */
    private function validate(Request $request): array|Response
    {
        $companyId = Auth::companyId();

        $scope = $request->post('scope');
        if (!in_array($scope, [
            BulkMailService::SCOPE_ALL,
            BulkMailService::SCOPE_EVENT,
            BulkMailService::SCOPE_SESSION,
        ], true)) {
            $scope = BulkMailService::SCOPE_ALL;
        }

        // A company account has no "everyone" to address.
        if ($companyId !== null && $scope === BulkMailService::SCOPE_ALL) {
            $scope = BulkMailService::SCOPE_EVENT;
        }

        $targetId = $scope === BulkMailService::SCOPE_SESSION
            ? $request->postInt('session')
            : $request->postInt('event');

        $includeWaitlisted = $request->has('include_waitlisted');

        $old = [
            'scope'   => $scope,
            'event'   => (string) $request->postInt('event'),
            'session' => (string) $request->postInt('session'),
            'subject' => $request->post('subject'),
            'body'    => $request->post('body'),
            'include_waitlisted' => $includeWaitlisted ? '1' : '',
            'test_email' => $request->post('test_email'),
        ];

        $validator = new Validator();
        $validator->required('subject', '件名', $request->post('subject'))
                  ->maxLength('subject', '件名', $request->post('subject'), self::SUBJECT_MAX);
        $validator->required('body', '本文', $request->post('body'))
                  ->maxLength('body', '本文', $request->post('body'), self::BODY_MAX);

        if ($scope !== BulkMailService::SCOPE_ALL && $targetId <= 0) {
            $validator->fail(
                $scope === BulkMailService::SCOPE_SESSION ? 'session' : 'event',
                '宛先の対象を選んでください。'
            );
        }

        // Checked against what this account may address, not merely against
        // what the form happened to offer.
        if ($targetId > 0) {
            $this->assertOwned($scope, $targetId, $companyId);
        }

        if ($validator->hasErrors()) {
            return $this->render($validator->errors(), $old);
        }

        $service = new BulkMailService();
        $context = $service->context($scope, $targetId);

        return [
            'scope'      => $scope,
            'targetId'   => $targetId,
            'subject'    => (string) $validator->value('subject'),
            'composed'   => $service->compose((string) $validator->value('body'), $context),
            'context'    => $context,
            'recipients' => $service->recipients($scope, $targetId, $includeWaitlisted, $companyId),
            'old'        => $old,
        ];
    }

    /**
     * 404 rather than 403 when the target belongs to another company - the
     * choice the rest of the admin makes, so an id cannot be probed for
     * existence.
     */
    private function assertOwned(string $scope, int $targetId, ?int $companyId): void
    {
        if ($companyId === null) {
            return;
        }

        $row = $scope === BulkMailService::SCOPE_SESSION
            ? (new EventSessionRepository())->findWithContext($targetId)
            : (new EventRepository())->findWithCompany($targetId);

        if ($row === null || (int) ($row['company_id'] ?? 0) !== $companyId) {
            throw new NotFoundException('お探しの対象は見つかりませんでした。');
        }
    }

    /**
     * @param array<string, string>     $errors
     * @param array<string, mixed>      $old
     * @param array<string, mixed>|null $resolved
     */
    private function render(array $errors, array $old, ?array $resolved = null): Response
    {
        $companyId = Auth::companyId();

        $sessions = [];
        $eventId = (int) ($old['event'] ?? 0);
        if ($eventId > 0) {
            $sessions = (new EventSessionRepository())->forEvent($eventId);
        }

        return Response::html(View::render('admin/mail_bulk', [
            'title'    => 'メール一斉送信',
            'events'   => (new EventRepository())->listForAdmin($companyId),
            'sessions' => $sessions,
            'errors'   => $errors,
            'old'      => $old,
            'resolved' => $resolved,
            'isOffice' => $companyId === null,
        ], 'layouts/admin'), $errors === [] ? 200 : 422);
    }
}
