<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Core\Auth;
use App\Core\Db;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Repository\EventSessionRepository;

final class DashboardController
{
    /** GET /admin */
    public function index(Request $request): Response
    {
        $companyId = Auth::companyId();

        // Every count below is either whole-system (office) or restricted to
        // one company. Rather than two sets of queries, each carries the same
        // optional join and predicate.
        $scope  = $companyId !== null ? 'AND e.company_id = ?' : '';
        $params = $companyId !== null ? [$companyId] : [];

        $bookingCounts = ['confirmed' => 0, 'waitlisted' => 0, 'cancelled' => 0];
        $rows = Db::select(
            "SELECT b.status, COUNT(*) AS n
             FROM bookings b
             JOIN event_sessions s ON s.id = b.session_id
             JOIN events e         ON e.id = s.event_id
             WHERE 1 = 1 {$scope}
             GROUP BY b.status",
            $params
        );
        foreach ($rows as $row) {
            $bookingCounts[(string) $row['status']] = (int) $row['n'];
        }

        $stats = [
            'events' => (int) Db::scalar(
                "SELECT COUNT(*) FROM events e WHERE 1 = 1 {$scope}",
                $params
            ),
            'sessions' => (int) Db::scalar(
                "SELECT COUNT(*) FROM event_sessions s
                 JOIN events e ON e.id = s.event_id
                 WHERE 1 = 1 {$scope}",
                $params
            ),
            'sessions_full' => (int) Db::scalar(
                "SELECT COUNT(*) FROM event_sessions s
                 JOIN events e ON e.id = s.event_id
                 WHERE s.confirmed_seats >= s.capacity {$scope}",
                $params
            ),
            'confirmed'  => $bookingCounts['confirmed'],
            'waitlisted' => $bookingCounts['waitlisted'],
            'cancelled'  => $bookingCounts['cancelled'],
        ];

        // Office-only figures: companies and the mail queue belong to nobody
        // in particular, so a company account is not shown them at all.
        if ($companyId === null) {
            $stats['companies']    = (int) Db::scalar('SELECT COUNT(*) FROM companies');
            $stats['mail_pending'] = (int) Db::scalar("SELECT COUNT(*) FROM mail_queue WHERE status = 'pending'");
            $stats['mail_failed']  = (int) Db::scalar("SELECT COUNT(*) FROM mail_queue WHERE status = 'failed'");
        }

        return Response::html(View::render('admin/dashboard', [
            'title'       => 'ダッシュボード',
            'stats'       => $stats,
            'promotable'  => (new EventSessionRepository())->withPromotableWaitlist($companyId),
            'isSuperadmin' => Auth::isSuperadmin(),
        ], 'layouts/admin'));
    }
}
