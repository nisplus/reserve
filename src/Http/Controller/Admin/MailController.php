<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Core\Csrf;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Mail\MailDispatcher;
use App\Repository\MailQueueRepository;

final class MailController
{
    private const PER_PAGE = 50;
    private const STATUSES = ['pending', 'sent', 'failed'];

    /** GET /admin/mail?status=&page= */
    public function index(Request $request): Response
    {
        $status = $request->query('status');
        if (!in_array($status, self::STATUSES, true)) {
            $status = '';
        }

        $repo = new MailQueueRepository();
        $total = $repo->countForAdmin($status);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page  = min(max($request->queryInt('page', 1), 1), $pages);

        return Response::html(View::render('admin/mail_index', [
            'title'  => 'メール送信キュー',
            'rows'   => $repo->listForAdmin($status, self::PER_PAGE, ($page - 1) * self::PER_PAGE),
            'total'  => $total,
            'page'   => $page,
            'pages'  => $pages,
            'status' => $status,
        ], 'layouts/admin'));
    }

    /** POST /admin/mail/{id}/resend - put a failed message back in the queue. */
    public function resend(Request $request): Response
    {
        Csrf::verify($request);

        if ((new MailQueueRepository())->requeueFailed($request->routeInt('id'))) {
            MailDispatcher::tryProcessPending();
            Flash::success('再送キューに戻しました。');
        } else {
            Flash::error('この行は再送できません（失敗状態のメールだけが再送できます）。');
        }
        return Response::redirect('/admin/mail?status=failed');
    }

    /** POST /admin/mail/send-pending - drain the queue right now. */
    public function sendPending(Request $request): Response
    {
        Csrf::verify($request);

        $result = (new MailDispatcher())->processPending(200);
        Flash::success(sprintf(
            '送信 %d 件、失敗 %d 件。%s',
            $result['sent'],
            $result['failed'],
            $result['failed'] > 0 ? '失敗分は下の一覧の last_error を確認してください。' : ''
        ));
        return Response::redirect('/admin/mail');
    }
}
