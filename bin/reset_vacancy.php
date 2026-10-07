<?php

declare(strict_types=1);

/**
 * Clear availability reports.
 *
 * For wiping a rehearsal before the real day, and for starting a day clean.
 *
 * ★ This deletes from vacancy_reports and NOTHING ELSE. It never touches
 *   bookings, event_sessions.confirmed_seats, or any other table - the same
 *   guarantee the feature itself makes. Deleting every row here loses the
 *   day's availability history and changes no booking.
 *
 * Usage:
 *   php bin/reset_vacancy.php --date=2026-10-10     one day's reports
 *   php bin/reset_vacancy.php --all                 every report, ever
 *   php bin/reset_vacancy.php --event=123           one programme's reports
 *   php bin/reset_vacancy.php --date=... --dry-run  count, delete nothing
 *
 * --date matches on the SESSION's date for per-session reports, and on when
 * the report was made for the "current status" ones, because those carry no
 * session to take a date from.
 *
 * The equivalent SQL, if you would rather do it by hand:
 *
 *   -- 一日分
 *   DELETE v FROM vacancy_reports v
 *     LEFT JOIN event_sessions s ON s.id = v.session_id
 *    WHERE (v.session_id IS NOT NULL AND DATE(s.starts_at) = '2026-10-10')
 *       OR (v.session_id IS NULL     AND DATE(v.reported_at) = '2026-10-10');
 *
 *   -- 全部
 *   DELETE FROM vacancy_reports;
 */

if (PHP_SAPI !== 'cli') {
    exit("This script is CLI-only.\n");
}

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Db;

$date = null;
$eventId = null;
$all = false;
$dryRun = false;

foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--date=(\d{4}-\d{2}-\d{2})$/', $arg, $m) === 1) {
        $date = $m[1];
    } elseif (preg_match('/^--event=(\d+)$/', $arg, $m) === 1) {
        $eventId = (int) $m[1];
    } elseif ($arg === '--all') {
        $all = true;
    } elseif ($arg === '--dry-run') {
        $dryRun = true;
    } else {
        exit("Unknown argument: {$arg}\n");
    }
}

if (!$all && $date === null && $eventId === null) {
    exit("Nothing selected. Pass --date=YYYY-MM-DD, --event=N or --all.\n"
       . "See the comment at the top of this file for the equivalent SQL.\n");
}

/*
 * Built as one statement with a LEFT JOIN rather than two deletes, so a day is
 * cleared atomically: a half-cleared day would read as "some booths went
 * quiet", which is exactly the state this script exists to avoid.
 */
$where = [];
$params = [];

if (!$all) {
    if ($date !== null) {
        $where[] = '((v.session_id IS NOT NULL AND DATE(s.starts_at) = ?)'
                 . ' OR (v.session_id IS NULL AND DATE(v.reported_at) = ?))';
        $params[] = $date;
        $params[] = $date;
    }
    if ($eventId !== null) {
        $where[] = 'v.event_id = ?';
        $params[] = $eventId;
    }
}

$clause = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);

$count = (int) Db::scalar(
    "SELECT COUNT(*) FROM vacancy_reports v
       LEFT JOIN event_sessions s ON s.id = v.session_id
     {$clause}",
    $params
);

printf(
    "対象: %d 件（%s）\n",
    $count,
    $all ? 'すべて' : implode(' / ', array_filter([
        $date !== null ? $date : null,
        $eventId !== null ? "event={$eventId}" : null,
    ]))
);

if ($count === 0) {
    echo "削除するものはありません。\n";
    exit(0);
}

if ($dryRun) {
    echo "--dry-run のため、削除していません。\n";
    exit(0);
}

$deleted = Db::execute(
    "DELETE v FROM vacancy_reports v
       LEFT JOIN event_sessions s ON s.id = v.session_id
     {$clause}",
    $params
);

printf("%d 件を削除しました。予約データには触れていません。\n", $deleted);
exit(0);
