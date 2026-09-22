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

    /**
     * How many one press of 今すぐ送信 attempts.
     *
     * A campaign is drained a batch at a time rather than in one request.
     * A relay that starts refusing does so partway through, and a batch
     * that ends by reporting what is left lets the operator see that and
     * stop. It also keeps the request inside the web server's timeout.
     */
    private const BATCH = 200;

    /** GET /admin/mail?status=&page= */
    public function index(Request $request): Response
    {
        $status = $request->query('status');
        if (!in_array($status, self::STATUSES, true)) {
            $status = '';
        }

        $category = $request->query('category');
        if (!in_array($category, [MailQueueRepository::TRANSACTIONAL, MailQueueRepository::BULK], true)) {
            $category = '';
        }

        $repo = new MailQueueRepository();
        $total = $repo->countForAdmin($status, $category);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page  = min(max($request->queryInt('page', 1), 1), $pages);

        return Response::html(View::render('admin/mail_index', [
            'title'  => 'メール送信キュー',
            'rows'     => $repo->listForAdmin($status, self::PER_PAGE, ($page - 1) * self::PER_PAGE, $category),
            'total'    => $total,
            'page'     => $page,
            'pages'    => $pages,
            'status'   => $status,
            'category' => $category,
            'pending'  => $repo->countPending(),
            'batch'    => self::BATCH,
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

    /**
     * POST /admin/mail/send-pending - send the next batch right now.
     *
     * One press is one batch, and the flash says what is left. That is
     * the progress display for a campaign: the operator watches the
     * remaining count fall while the failure count stays at zero, and
     * can simply stop pressing if the relay begins refusing.
     */
    public function sendPending(Request $request): Response
    {
        Csrf::verify($request);

        $repo = new MailQueueRepository();
        $result = (new MailDispatcher())->processPending(self::BATCH);
        $remaining = $repo->countPending();

        $message = sprintf('送信 %d 件、失敗 %d 件。', $result['sent'], $result['failed']);
        $message .= $remaining > 0
            ? sprintf('残り %d 件です。もう一度「今すぐ送信」を押すと続きを送ります（定期実行でも順次送られます）。', $remaining)
            : '未送信はありません。';

        if ($result['failed'] > 0) {
            $message .= '失敗分は下の一覧の last_error をご確認ください。';
            Flash::error($message);
        } else {
            Flash::success($message);
        }
        return Response::redirect('/admin/mail?status=pending');
    }
}
