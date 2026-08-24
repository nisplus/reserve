<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Exception\ValidationException;
use App\Mail\MailDispatcher;
use App\Repository\BookingRepository;
use App\Repository\CompanyRepository;
use App\Repository\EventRepository;
use App\Repository\EventSessionRepository;
use App\Service\CancellationService;
use App\Service\CsvExporter;
use App\Service\WaitlistService;

/**
 * Admin booking operations: the filterable list, promotion from the waitlist,
 * cancellation on the applicant's behalf, and the CSV export (which carries
 * the exact same filters as the list on screen).
 */
final class BookingController
{
    private const PER_PAGE = 50;
    private const EXPORT_MAX = 10000;
    private const STATUSES = ['confirmed', 'waitlisted', 'cancelled'];

    /** GET /admin/bookings */
    public function index(Request $request): Response
    {
        $filters = $this->filters($request);
        $repo = new BookingRepository();

        $total = $repo->countForAdmin($filters);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page  = min(max($request->queryInt('page', 1), 1), $pages);

        $sessions = [];
        if ((int) $filters['event_id'] > 0) {
            $sessions = (new EventSessionRepository())->forEvent((int) $filters['event_id']);
        }

        return Response::html(View::render('admin/bookings_index', [
            'title'    => '申込一覧',
            'rows'     => $repo->searchForAdmin($filters, self::PER_PAGE, ($page - 1) * self::PER_PAGE),
            'total'    => $total,
            'page'     => $page,
            'pages'    => $pages,
            'filters'  => $filters,
            'options'  => (new CompanyRepository())->options(),
            'events'   => (new EventRepository())->listForAdmin(
                (int) $filters['company_id'] > 0 ? (int) $filters['company_id'] : null
            ),
            'sessions' => $sessions,
        ], 'layouts/admin'));
    }

    /** GET /admin/bookings/export - same filters as the list, as CSV. */
    public function export(Request $request): Response
    {
        $filters = $this->filters($request);
        $rows = (new BookingRepository())->searchForAdmin($filters, self::EXPORT_MAX, 0);

        $header = ['予約番号', '状態', '会社', 'イベント', '開催日時', '氏名', 'メールアドレス',
                   '人数', 'キャンセル待ち順', '申込日時', 'キャンセル日時'];
        $statusLabels = ['confirmed' => '確定', 'waitlisted' => 'キャンセル待ち', 'cancelled' => 'キャンセル済み'];

        $lines = [];
        foreach ($rows as $row) {
            $lines[] = [
                $row['reference_code'],
                $statusLabels[(string) $row['status']] ?? $row['status'],
                $row['company_name'],
                $row['event_title'],
                jp_datetime((string) $row['starts_at']) . '〜' . jp_time((string) $row['ends_at']),
                $row['name'],
                $row['email'],
                $row['party_size'],
                $row['waitlist_seq'],
                $row['created_at'],
                $row['cancelled_at'],
            ];
        }

        return Response::raw((new CsvExporter())->build($header, $lines), 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="bookings_' . date('Ymd_His') . '.csv"',
        ]);
    }

    /** POST /admin/bookings/{id}/promote */
    public function promote(Request $request): Response
    {
        Csrf::verify($request);

        try {
            $booking = (new WaitlistService())->promote($request->routeInt('id'), Auth::actor());
        } catch (ValidationException $e) {
            Flash::error($e->getMessage());
            return $this->backToList($request);
        }

        MailDispatcher::tryProcessPending();
        Flash::success("繰り上げました。{$booking['name']} 様（{$booking['reference_code']}）の参加が確定し、ご本人にメールをお送りしています。");
        return $this->backToList($request);
    }

    /** POST /admin/bookings/{id}/cancel */
    public function cancel(Request $request): Response
    {
        Csrf::verify($request);

        $result = (new CancellationService())->cancelById($request->routeInt('id'), Auth::actor());

        if ($result['already_cancelled']) {
            Flash::info('この申込は既にキャンセル済みです。');
        } else {
            MailDispatcher::tryProcessPending();
            $note = $result['auto_promoted'] > 0
                ? " 空きにより {$result['auto_promoted']} 件を自動で繰り上げました。"
                : '';
            Flash::success('キャンセルしました。ご本人に通知メールをお送りしています。' . $note);
        }
        return $this->backToList($request);
    }

    /**
     * Round-trip the filters through POST so cancelling row 3 on page 2 of a
     * filtered list lands back on that same view.
     */
    private function backToList(Request $request): Response
    {
        $query = $request->post('return_query');
        // The value is a query string we ourselves rendered into the form, but
        // it still arrives as client input: allow only safe characters.
        if (!preg_match('/^[A-Za-z0-9_=&%.\-]*$/', $query)) {
            $query = '';
        }
        return Response::redirect('/admin/bookings' . ($query !== '' ? '?' . $query : ''));
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        $status = $request->query('status');
        return [
            'company_id' => $request->queryInt('company'),
            'event_id'   => $request->queryInt('event'),
            'session_id' => $request->queryInt('session'),
            'status'     => in_array($status, self::STATUSES, true) ? $status : '',
            'email'      => mb_substr($request->query('email'), 0, 255),
        ];
    }
}
