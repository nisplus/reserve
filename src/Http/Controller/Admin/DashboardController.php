<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

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
        // One aggregate pass over bookings; the rest are single-value lookups.
        $bookingCounts = ['confirmed' => 0, 'waitlisted' => 0, 'cancelled' => 0];
        foreach (Db::select('SELECT status, COUNT(*) AS n FROM bookings GROUP BY status') as $row) {
            $bookingCounts[(string) $row['status']] = (int) $row['n'];
        }

        $stats = [
            'companies'      => (int) Db::scalar('SELECT COUNT(*) FROM companies'),
            'events'         => (int) Db::scalar('SELECT COUNT(*) FROM events'),
            'sessions'       => (int) Db::scalar('SELECT COUNT(*) FROM event_sessions'),
            'sessions_full'  => (int) Db::scalar(
                'SELECT COUNT(*) FROM event_sessions WHERE confirmed_seats >= capacity'
            ),
            'confirmed'      => $bookingCounts['confirmed'],
            'waitlisted'     => $bookingCounts['waitlisted'],
            'cancelled'      => $bookingCounts['cancelled'],
            'mail_pending'   => (int) Db::scalar("SELECT COUNT(*) FROM mail_queue WHERE status = 'pending'"),
            'mail_failed'    => (int) Db::scalar("SELECT COUNT(*) FROM mail_queue WHERE status = 'failed'"),
        ];

        return Response::html(View::render('admin/dashboard', [
            'title'      => 'ダッシュボード',
            'stats'      => $stats,
            // Sessions where someone waits AND seats are free: the human
            // decision the vacancy mails point at (promotion itself is stage 8).
            'promotable' => (new EventSessionRepository())->withPromotableWaitlist(),
        ], 'layouts/admin'));
    }
}
