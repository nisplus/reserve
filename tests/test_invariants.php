<?php

declare(strict_types=1);

/**
 * The five data invariants from docs/design.md E-4, checked against the live
 * database. Every query must return zero rows. Run after any test session -
 * especially after tests/test_concurrency.php - and before trusting a backup.
 *
 * (3) is the requirement itself: no applicant holds two live bookings with
 * overlapping times, across companies.
 */

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Db;

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$invariants = [
    '(1) counter matches SUM(party_size)' =>
        "SELECT s.id FROM event_sessions s
         LEFT JOIN bookings b ON b.session_id = s.id AND b.status = 'confirmed'
         GROUP BY s.id, s.confirmed_seats
         HAVING s.confirmed_seats <> COALESCE(SUM(b.party_size), 0)",
    '(2) no session oversold' =>
        'SELECT id FROM event_sessions WHERE confirmed_seats > capacity',
    '(3) no overlapping live bookings per applicant' =>
        "SELECT b1.applicant_id, b1.id AS b1_id, b2.id AS b2_id
         FROM bookings b1
         JOIN bookings b2 ON b2.applicant_id = b1.applicant_id AND b2.id > b1.id
         JOIN event_sessions s1 ON s1.id = b1.session_id
         JOIN event_sessions s2 ON s2.id = b2.session_id
         WHERE b1.status IN ('confirmed','waitlisted')
           AND b2.status IN ('confirmed','waitlisted')
           AND s1.starts_at < s2.ends_at AND s2.starts_at < s1.ends_at",
    '(4) waitlist_seq unique per session' =>
        "SELECT session_id, waitlist_seq, COUNT(*) AS n FROM bookings
         WHERE status = 'waitlisted'
         GROUP BY session_id, waitlist_seq HAVING COUNT(*) > 1",
    '(5) status/timestamp consistency' =>
        "SELECT id FROM bookings
         WHERE (status = 'cancelled' AND cancelled_at IS NULL)
            OR (status = 'confirmed' AND confirmed_at IS NULL)
            OR (status <> 'waitlisted' AND waitlist_seq IS NOT NULL)",
];

$failures = 0;
foreach ($invariants as $label => $sql) {
    $rows = Db::select($sql);
    $ok = $rows === [];
    if (!$ok) {
        $failures++;
    }
    printf("%s %s%s\n", $ok ? 'OK ' : 'NG ', $label, $ok ? '' : ' - ' . count($rows) . ' violating row(s)');
    foreach (array_slice($rows, 0, 5) as $row) {
        echo '     ', json_encode($row, JSON_UNESCAPED_UNICODE), "\n";
    }
}

echo $failures === 0 ? "invariants: all OK\n" : "invariants: {$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
