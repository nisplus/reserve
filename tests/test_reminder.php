<?php

declare(strict_types=1);

/**
 * The day-before reminder (App\Service\ReminderService).
 *
 * Three properties decide whether this feature is safe to leave running:
 *
 *   - it sends once. The nightly job, a retry and an operator pressing the
 *     button must not between them send the same person three reminders.
 *   - it sends one message per person, not per booking. Visitors here tour
 *     several companies in a day.
 *   - it never sends about a session the office took out, and never about a
 *     cancelled booking.
 *
 * And one that decides whether it is worth sending at all: the body has to
 * carry the facts, because the whole argument for building it rather than
 * reusing the bulk announcement was that the bulk one cannot.
 */

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/_fixture.php';

use App\Core\Db;
use App\Core\Settings;
use App\Repository\EventRepository;
use App\Repository\MailQueueRepository;
use App\Service\BookingService;
use App\Service\CancellationService;
use App\Service\ReminderService;

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$failures = 0;
$assert = static function (bool $condition, string $label) use (&$failures): void {
    echo ($condition ? 'OK  ' : 'NG  ') . $label . "\n";
    if (!$condition) {
        $failures++;
    }
};

/** @param array<int, array<string, mixed>> $people */
$emailsOf = static function (array $people): array {
    $out = array_map(static fn (array $p): string => $p['email'], $people);
    sort($out);
    return $out;
};

fixture_cleanup();

$events = new EventRepository();
$book = new BookingService();
$service = new ReminderService();

$DAY = '2035-04-04';
$notice = '';

// Settings are global, so remember what the operator had and put it back.
$savedEnabled = Settings::get(Settings::REMINDER_ENABLED);
$savedMode = Settings::get(Settings::REMINDER_MODE);
$savedWait = Settings::get(Settings::REMINDER_INCLUDE_WAITLISTED);
$savedSkip = Settings::get(Settings::REMINDER_SKIP_SESSIONS);
$savedNotice = Settings::get(Settings::REMINDER_NOTICE);

try {
    // --- the defaults a release ships with -----------------------------------
    Settings::set(Settings::REMINDER_ENABLED, null);
    Settings::set(Settings::REMINDER_MODE, null);
    Settings::forget();
    $assert(!Settings::reminderEnabled(),
        'with nothing configured the reminder is OFF - deploying it mails nobody');
    $assert(!Settings::reminderSendsItself(),
        'and would not send by itself even if it were on, because approval is the default mode');

    // --- a day with a tourer, a solo visitor, a leaver and a waiter ----------
    $companyA = fixture_create_company('remA');
    $companyB = fixture_create_company('remB');

    $eventA = $events->create($companyA, 'A社 工場見学', null, '本社 第2ゲート', 0, true);
    $eventB = $events->create($companyB, 'B社 体験教室', null, '第2工場', 0, true);
    $eventC = $events->create($companyB, 'B社 説明会', null, null, 0, true);

    $slotA = fixture_create_session($eventA, $DAY . ' 10:00:00', $DAY . ' 11:00:00', 2);
    $slotTight = fixture_create_session($eventB, $DAY . ' 11:05:00', $DAY . ' 12:00:00', 5);
    $slotLater = fixture_create_session($eventC, $DAY . ' 15:00:00', $DAY . ' 16:00:00', 5);
    $slotOtherDay = fixture_create_session($eventC, '2035-04-05 10:00:00', '2035-04-05 11:00:00', 5);

    $tourer = fixture_email('rem-tourer');
    $book->book($slotA, $tourer, '周遊 太郎', 2, contactName: '周遊 保護者', guardianCount: 1);
    $book->book($slotTight, $tourer, '周遊 太郎', 2, contactName: '周遊 保護者');

    $solo = fixture_email('rem-solo');
    $book->book($slotLater, $solo, '単独 花子', 1, contactName: '単独 花子');

    $gone = fixture_email('rem-gone');
    $goneId = (int) $book->book($slotLater, $gone, '離脱 次郎', 1, contactName: '離脱 次郎')['booking_id'];
    (new CancellationService())->cancelById($goneId, 'test:reminder');

    $nextDay = fixture_email('rem-nextday');
    $book->book($slotOtherDay, $nextDay, '翌日 三郎', 1, contactName: '翌日 三郎');

    // slotA holds 2; the tourer took both, so this one waits.
    $waiter = fixture_email('rem-waiter');
    $waiting = $book->book($slotA, $waiter, '待機 四郎', 1, contactName: '待機 四郎');
    $assert((string) Db::scalar('SELECT status FROM bookings WHERE id = ?', [$waiting['booking_id']])
        === 'waitlisted', 'the fixture has someone waitlisted');

    // --- who is in --------------------------------------------------------
    $withWait = $service->recipients($DAY, true);
    $emails = $emailsOf($withWait);

    $assert(!in_array($gone, $emails, true),
        'a cancelled booking is never reminded about');
    $assert(!in_array($nextDay, $emails, true),
        'and neither is a booking on another day');
    $assert(in_array($waiter, $emails, true),
        'the waitlisted visitor is included when the checkbox says so');
    $assert($emailsOf($service->recipients($DAY, false)) === [$solo, $tourer],
        'and left out when it does not');

    // --- one message per person ------------------------------------------
    $tourerEntry = null;
    foreach ($withWait as $person) {
        if ($person['email'] === $tourer) {
            $tourerEntry = $person;
        }
    }
    $assert($tourerEntry !== null && count($tourerEntry['bookings']) === 2,
        'two bookings in one day make one recipient carrying both');
    $assert(count(array_keys($emails, $tourer, true)) === 1,
        'so the tourer is addressed once, not twice');
    $assert($tourerEntry['name'] === '周遊 保護者',
        'addressed to the contact, whose inbox it is');
    $assert((string) $tourerEntry['bookings'][0]['starts_at'] < (string) $tourerEntry['bookings'][1]['starts_at'],
        'and their day is in time order');

    // --- the body ---------------------------------------------------------
    $body = $service->compose($tourerEntry, $DAY, '雨天決行です。');

    $assert(str_contains($body, 'のご予約は 2 件です'), 'the body opens with how many');
    $assert(str_contains($body, 'A社 工場見学') && str_contains($body, 'B社 体験教室'),
        'and names both programmes');
    $assert(str_contains($body, '本社 第2ゲート'), 'with the venue, which is what people need on the day');
    $assert(str_contains($body, '付き添い: 1 名'), 'escorts are listed, since they are coming too');
    $assert(str_contains($body, '雨天決行です。'), "the office's own note is included");
    $assert(str_contains($body, '間隔は 5 分です'),
        'and a five-minute connection is pointed out to the person who has to walk it');
    $assert(!str_contains($body, '/manage/'),
        'no cancellation link: the raw token lives in the confirmation mail and nowhere else');
    $assert(str_contains($service->compose($tourerEntry, $DAY, ''), '事務局からのお知らせ') === false,
        'an empty note takes its whole block out rather than leaving an empty frame');

    $waiterEntry = null;
    foreach ($withWait as $person) {
        if ($person['email'] === $waiter) {
            $waiterEntry = $person;
        }
    }
    $assert(str_contains($service->compose($waiterEntry, $DAY, ''), 'キャンセル待ち'),
        'a waitlisted booking says so, so nobody turns up expecting a seat');

    // --- a session the office took out ------------------------------------
    Settings::setReminderSkippedSessions([$slotLater]);
    Settings::forget();
    $assert(!in_array($solo, $emailsOf($service->recipients($DAY, true)), true),
        'unchecking a session removes its applicants from the day');
    Settings::setReminderSkippedSessions([]);
    Settings::forget();
    $assert(in_array($solo, $emailsOf($service->recipients($DAY, true)), true),
        'and checking it again puts them back');

    // --- sending once ------------------------------------------------------
    $before = (int) Db::scalar("SELECT COUNT(*) FROM mail_queue WHERE category = 'reminder'");
    $queued = $service->enqueue($service->recipients($DAY, true), $DAY, '');
    $assert($queued === 3, "one message each for the three people ({$queued})");
    $assert((int) Db::scalar("SELECT COUNT(*) FROM mail_queue WHERE category = 'reminder'") === $before + 3,
        'and they are queued as reminders');

    $again = $service->recipients($DAY, true);
    $assert($again === [], 'running it a second time finds nobody left - this is what makes cron safe');
    $assert($service->enqueue($again, $DAY, '') === 0, 'and queues nothing');

    $row = Db::selectOne(
        "SELECT to_email, to_name, booking_id FROM mail_queue
          WHERE category = 'reminder' AND to_email = ? ORDER BY id DESC LIMIT 1",
        [$tourer]
    );
    $assert($row !== null && (int) $row['booking_id'] === (int) $tourerEntry['bookings'][0]['id'],
        'the queued row hangs off the first booking of the day, which is how it is found again');

    // --- the screen's session list ----------------------------------------
    $listed = $service->sessionsOn($DAY);
    $assert(count($listed) === 3, 'the day lists its three sessions for the operator to tick');
    $totals = array_sum(array_map(static fn (array $r): int => (int) $r['confirmed'], $listed));
    $assert($totals === 5, "with live headcounts ({$totals} confirmed)");
} finally {
    Settings::set(Settings::REMINDER_ENABLED, $savedEnabled);
    Settings::set(Settings::REMINDER_MODE, $savedMode);
    Settings::set(Settings::REMINDER_INCLUDE_WAITLISTED, $savedWait);
    Settings::set(Settings::REMINDER_SKIP_SESSIONS, $savedSkip);
    Settings::set(Settings::REMINDER_NOTICE, $savedNotice);
    Settings::forget();

    Db::execute('DELETE FROM mail_queue WHERE to_email LIKE ?', ['%@' . FIXTURE_EMAIL_DOMAIN]);
    fixture_cleanup();
}

echo $failures === 0 ? "reminder: all OK\n" : "reminder: {$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);
