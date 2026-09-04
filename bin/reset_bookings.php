<?php

declare(strict_types=1);

/**
 * Clear booking data, keeping the events.
 *
 * The obvious "DELETE FROM bookings" is wrong on its own: event_sessions
 * carries a denormalised confirmed_seats (and waitlist_counter), so deleting
 * the rows behind those counters leaves every session claiming seats that
 * nobody holds - invariant (1) broken, and the next booking refused as full.
 * This script puts the counters back in the same transaction.
 *
 * Typical use is clearing test bookings before opening to the public.
 *
 * Usage:
 *   php bin/reset_bookings.php --dry-run     show what would be deleted
 *   php bin/reset_bookings.php --yes         do it
 *   php bin/reset_bookings.php --yes --keep-applicants
 *   php bin/reset_bookings.php --yes --keep-mail
 *
 * Nothing happens without --yes. To wipe the events too, use
 * `php bin/migrate.php --fresh` followed by `php bin/seed.php`.
 */

if (PHP_SAPI !== 'cli') {
    exit("This script is CLI-only.\n");
}

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Db;

$options         = array_slice($argv, 1);
$confirmed       = in_array('--yes', $options, true);
$dryRun          = in_array('--dry-run', $options, true);
$keepApplicants  = in_array('--keep-applicants', $options, true);
$keepMail        = in_array('--keep-mail', $options, true);

$counts = [
    'bookings'          => (int) Db::scalar('SELECT COUNT(*) FROM bookings'),
    'booking_attendees' => (int) Db::scalar('SELECT COUNT(*) FROM booking_attendees'),
    'booking_events'    => (int) Db::scalar('SELECT COUNT(*) FROM booking_events'),
    'applicants'        => (int) Db::scalar('SELECT COUNT(*) FROM applicants'),
    'mail_queue'        => (int) Db::scalar('SELECT COUNT(*) FROM mail_queue'),
];
$sessionsWithSeats = (int) Db::scalar(
    'SELECT COUNT(*) FROM event_sessions WHERE confirmed_seats > 0 OR waitlist_counter > 0'
);

echo "削除される件数:\n";
printf("  bookings            %6d\n", $counts['bookings']);
printf("  booking_attendees   %6d\n", $counts['booking_attendees']);
printf("  booking_events      %6d\n", $counts['booking_events']);
printf("  applicants          %6d%s\n", $counts['applicants'], $keepApplicants ? '  (--keep-applicants で保持)' : '');
printf("  mail_queue          %6d%s\n", $counts['mail_queue'], $keepMail ? '  (--keep-mail で保持)' : '');
printf("座席カウンタを 0 に戻す開催回: %d\n", $sessionsWithSeats);
echo "\nイベント・開催回・会社・管理アカウントは削除しません。\n";

if ($dryRun || !$confirmed) {
    echo $dryRun
        ? "\n--dry-run のため何も変更していません。\n"
        : "\n実行するには --yes を付けてください。\n";
    exit(0);
}

Db::transaction(static function () use ($keepApplicants, $keepMail): void {
    // Children first. booking_attendees and booking_events cascade from
    // bookings, but they are deleted explicitly so the script does not depend
    // on the FK rules staying as they are.
    Db::execute('DELETE FROM booking_attendees');
    Db::execute('DELETE FROM booking_events');

    if (!$keepMail) {
        // No FK from mail_queue, so these would otherwise dangle by booking_id.
        Db::execute('DELETE FROM mail_queue');
    } else {
        Db::execute('UPDATE mail_queue SET booking_id = NULL WHERE booking_id IS NOT NULL');
    }

    Db::execute('DELETE FROM bookings');

    if (!$keepApplicants) {
        Db::execute('DELETE FROM applicants');
    }

    // The whole reason this is a script and not a DELETE.
    Db::execute('UPDATE event_sessions SET confirmed_seats = 0, waitlist_counter = 0');
});

echo "\n完了しました。\n";

// Prove it rather than assert it: the same check tests/test_invariants.php runs.
$drift = Db::select(
    "SELECT s.id FROM event_sessions s
     LEFT JOIN bookings b ON b.session_id = s.id AND b.status = 'confirmed'
     GROUP BY s.id, s.confirmed_seats
     HAVING s.confirmed_seats <> COALESCE(SUM(b.party_size), 0)"
);
printf("座席カウンタの整合性: %s\n", $drift === [] ? 'OK' : 'NG (' . count($drift) . ' 件ずれ)');
printf("残った予約: %d 件 / イベント: %d 件 / 開催回: %d 件\n",
    (int) Db::scalar('SELECT COUNT(*) FROM bookings'),
    (int) Db::scalar('SELECT COUNT(*) FROM events'),
    (int) Db::scalar('SELECT COUNT(*) FROM event_sessions'));

exit($drift === [] ? 0 : 1);
