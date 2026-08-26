<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Core\Auth;
use App\Core\Authz;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Exception\NotFoundException;
use App\Exception\ValidationException;
use App\Mail\MailDispatcher;
use App\Repository\BookingAttendeeRepository;
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
        $rows  = $repo->searchForAdmin($filters, self::PER_PAGE, ($page - 1) * self::PER_PAGE);

        $sessions = [];
        if ((int) $filters['event_id'] > 0) {
            $sessions = (new EventSessionRepository())->forEvent((int) $filters['event_id']);
        }

        $options = (new CompanyRepository())->options();
        if (Auth::companyId() !== null) {
            // No point offering a company filter with one entry the account
            // cannot change; the list is already confined to it.
            $options = [];
        }

        return Response::html(View::render('admin/bookings_index', [
            'title'     => '申込一覧',
            'rows'      => $rows,
            'attendees' => (new BookingAttendeeRepository())->namesForMany(
                array_map(static fn (array $row): int => (int) $row['id'], $rows)
            ),
            'total'    => $total,
            'page'     => $page,
            'pages'    => $pages,
            'filters'  => $filters,
            'options'  => $options,
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
                   '人数', '参加者', 'キャンセル待ち順', '申込日時', 'キャンセル日時'];
        $statusLabels = ['confirmed' => '確定', 'waitlisted' => 'キャンセル待ち', 'cancelled' => 'キャンセル済み'];

        // One query for every row's attendees rather than one per row.
        $attendees = (new BookingAttendeeRepository())->namesForMany(
            array_map(static fn (array $row): int => (int) $row['id'], $rows)
        );

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
                implode(' / ', $attendees[(int) $row['id']] ?? []),
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
        $this->loadBooking($request->routeInt('id')); // 404s on another company's booking

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
        $this->loadBooking($request->routeInt('id')); // 404s on another company's booking

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

    /**
     * Filters for the list and the export.
     *
     * company_id is not a user preference for a company account - it is the
     * boundary. Authz::scopeCompanyId overrides whatever ?company= asked for,
     * so an event or session id belonging to someone else simply matches
     * nothing rather than widening the result.
     *
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        $status = $request->query('status');
        return [
            'company_id' => Authz::scopeCompanyId($request->queryInt('company')) ?? 0,
            'event_id'   => $request->queryInt('event'),
            'session_id' => $request->queryInt('session'),
            'status'     => in_array($status, self::STATUSES, true) ? $status : '',
            'email'      => mb_substr($request->query('email'), 0, 255),
        ];
    }

    /**
     * Load a booking for a write operation, checking it belongs to this
     * account's company. Returns the context row (which carries company_id).
     *
     * @return array<string, mixed>
     */
    private function loadBooking(int $id): array
    {
        $booking = (new BookingRepository())->findById($id);
        if ($booking === null) {
            throw new NotFoundException('お探しの申込は見つかりませんでした。');
        }
        Authz::assertCompany((int) $booking['company_id']);
        return $booking;
    }
}
