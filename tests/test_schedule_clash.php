<?php

declare(strict_types=1);

/**
 * The same-day panel behind the 繰り上げ button
 * (App\Service\ApplicantScheduleService).
 *
 * Its job is to answer, before the button is pressed, "what else has this
 * person booked today?" - because promotion turns a maybe into a commitment
 * and the transaction only refuses the impossible cases.
 *
 * Two things are asserted hardest:
 *
 *   - the classification. Touring several companies in a day is the normal
 *     use of this site, so an ordinary second booking must NOT be flagged;
 *     only a genuine overlap, or a connection at or under the travel buffer
 *     between two different companies.
 *   - what comes back. The panel shows one company's staff what their
 *     applicant booked elsewhere. The programme is public knowledge; the
 *     names, contacts, party sizes and notes attached to that other company's
 *     booking are not, and must not be in the rows at all.
 */

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/_fixture.php';

use App\Core\Db;
use App\Domain\ScheduleClash;
use App\Repository\BookingRepository;
use App\Repository\EventRepository;
use App\Service\ApplicantScheduleService;
use App\Service\BookingService;
use App\Service\CancellationService;

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

fixture_cleanup();

$events   = new EventRepository();
$service  = new BookingService();
$schedule = new ApplicantScheduleService();
$bookings = new BookingRepository();

$buffer = BookingService::travelBufferMinutes();

try {
    $companyA = fixture_create_company('clashA');
    $companyB = fixture_create_company('clashB');

    $eventA = $events->create($companyA, 'A社 見学', null, null, 0, true);
    $eventB = $events->create($companyB, 'B社 説明会', null, null, 0, true);

    // The booking every case is measured against: A社 10:00-11:00.
    $anchor = fixture_create_session($eventA, '2034-03-01 10:00:00', '2034-03-01 11:00:00', 10);

    /** Row shaped like an admin list row, which is what the service takes. */
    $rowFor = static function (int $bookingId) use ($bookings): array {
        $b = $bookings->findById($bookingId);
        return [
            'id'           => (int) $b['id'],
            'applicant_id' => (int) $b['applicant_id'],
            'starts_at'    => (string) $b['starts_at'],
            'ends_at'      => (string) $b['ends_at'],
            'company_id'   => (int) $b['company_id'],
        ];
    };

    /** @return array{clash: ScheduleClash|null, others: array<int, array<string, mixed>>}|null */
    $entryFor = static function (int $bookingId) use ($schedule, $rowFor): ?array {
        return $schedule->forRows([$rowFor($bookingId)])[$bookingId] ?? null;
    };

    // --- nothing else booked --------------------------------------------------
    $aloneEmail = fixture_email('clash-alone');
    $alone = (int) $service->book($anchor, $aloneEmail, 'Alone', 1, contactName: '単独 一郎')['booking_id'];
    $assert($entryFor($alone) === null,
        'someone with one booking gets no panel at all - silence is the common case');

    // --- another company, comfortably later ----------------------------------
    $farEmail = fixture_email('clash-far');
    $far = (int) $service->book($anchor, $farEmail, 'Far', 1, contactName: '余裕 二郎')['booking_id'];
    $farSlot = fixture_create_session($eventB, '2034-03-01 14:00:00', '2034-03-01 15:00:00', 10);
    $service->book($farSlot, $farEmail, 'Far', 1, contactName: '余裕 二郎');

    $entry = $entryFor($far);
    $assert($entry !== null && count($entry['others']) === 1,
        'a second booking the same day is shown');
    $assert($entry['clash'] === ScheduleClash::SameDay,
        'and left uncoloured - visiting two companies in a day is the point of the site');
    $assert(!$entry['clash']->needsAttention(),
        'so it does not ask the operator to stop and look');

    // --- another company, inside the travel buffer ---------------------------
    $tightEmail = fixture_email('clash-tight');
    $tight = (int) $service->book($anchor, $tightEmail, 'Tight', 1, contactName: '接続 三郎')['booking_id'];
    // 11:00 end + 5 minutes.
    $tightSlot = fixture_create_session($eventB, '2034-03-01 11:05:00', '2034-03-01 12:00:00', 10);
    $service->book($tightSlot, $tightEmail, 'Tight', 1, contactName: '接続 三郎');

    $entry = $entryFor($tight);
    $assert($entry !== null && $entry['clash'] === ScheduleClash::Tight,
        "a {$buffer} 分 buffer with a 5 分 gap across companies is flagged as tight");
    $assert($entry['clash']->needsAttention(),
        'and that is one the operator should see before promoting');
    $assert($schedule->noteFor($rowFor($tight)) !== null
        && str_contains((string) $schedule->noteFor($rowFor($tight)), '移動時間が短い'),
        'the flash note says so in words, for after the promotion is done');

    // --- the same company, back to back --------------------------------------
    $sameHostEmail = fixture_email('clash-samehost');
    $sameHost = (int) $service->book($anchor, $sameHostEmail, 'Same', 1, contactName: '同社 四郎')['booking_id'];
    // A second programme of the SAME company: one applicant may not hold two
    // sessions of one programme, so the same-host case needs another event.
    $eventA2 = $events->create($companyA, 'A社 別の見学', null, null, 0, true);
    $sameHostSlot = fixture_create_session($eventA2, '2034-03-01 11:05:00', '2034-03-01 12:00:00', 10);
    $service->book($sameHostSlot, $sameHostEmail, 'Same', 1, contactName: '同社 四郎');

    $entry = $entryFor($sameHost);
    $assert($entry !== null && $entry['clash'] === ScheduleClash::SameDay,
        'two sessions of the SAME company back to back are not a travel problem');
    $assert($schedule->noteFor($rowFor($sameHost)) === null,
        'and say nothing after a promotion - there is nowhere to travel to');

    // --- a genuine overlap ----------------------------------------------------
    // The booking form refuses this, so it is built directly - which is exactly
    // the state a race or a hand-edit can leave behind.
    $overlapEmail = fixture_email('clash-overlap');
    $overlap = (int) $service->book($anchor, $overlapEmail, 'Over', 1, contactName: '重複 五郎')['booking_id'];
    $overlapSlot = fixture_create_session($eventB, '2034-03-01 10:30:00', '2034-03-01 11:30:00', 10);
    Db::execute(
        "INSERT INTO bookings (reference_code, cancel_token_hash, session_id, applicant_id, email,
                               name, contact_name, party_size, status, confirmed_at, created_at)
         SELECT ?, ?, ?, a.id, a.email, 'Over', '重複 五郎', 1, 'confirmed', NOW(), NOW()
           FROM applicants a WHERE a.email = ?",
        [bin2hex(random_bytes(5)), bin2hex(random_bytes(32)), $overlapSlot, $overlapEmail]
    );

    $entry = $entryFor($overlap);
    $assert($entry !== null && $entry['clash'] === ScheduleClash::Overlap,
        'overlapping times are the worst kind and are reported as such');

    // --- cancelled bookings are not part of anyone's day ---------------------
    $goneEmail = fixture_email('clash-gone');
    $gone = (int) $service->book($anchor, $goneEmail, 'Gone', 1, contactName: '取消 六郎')['booking_id'];
    $goneSlot = fixture_create_session($eventB, '2034-03-01 13:00:00', '2034-03-01 14:00:00', 10);
    $goneOther = (int) $service->book($goneSlot, $goneEmail, 'Gone', 1, contactName: '取消 六郎')['booking_id'];
    $assert($entryFor($gone) !== null, 'while it is live, the other booking is shown');
    (new CancellationService())->cancelById($goneOther, 'test:clash');
    $assert($entryFor($gone) === null, 'once cancelled it is gone from the day');

    // --- another day is not context ------------------------------------------
    $nextDayEmail = fixture_email('clash-nextday');
    $nextDay = (int) $service->book($anchor, $nextDayEmail, 'Next', 1, contactName: '翌日 七郎')['booking_id'];
    $nextDaySlot = fixture_create_session($eventB, '2034-03-02 10:00:00', '2034-03-02 11:00:00', 10);
    $service->book($nextDaySlot, $nextDayEmail, 'Next', 1, contactName: '翌日 七郎');
    $assert($entryFor($nextDay) === null,
        'a booking on another day is not shown - it is noise, not context');

    // --- what the rows carry ---------------------------------------------------
    // The boundary the whole feature turns on: the other company's programme is
    // public, the people attached to its bookings are not.
    $rows = $bookings->liveForApplicants([
        (int) $bookings->findById($tight)['applicant_id'],
    ]);
    $assert($rows !== [], 'the lookup returns the applicant\'s bookings');

    $columns = array_keys($rows[0]);
    sort($columns);
    $assert(
        $columns === ['applicant_id', 'company_id', 'company_name', 'ends_at',
                      'event_id', 'event_title', 'id', 'starts_at', 'status'],
        'and carries event, company and times only: ' . implode(', ', $columns)
    );
    foreach (['name', 'contact_name', 'email', 'phone', 'message', 'party_size',
              'guardian_count', 'reference_code'] as $forbidden) {
        $assert(!array_key_exists($forbidden, $rows[0]),
            "no {$forbidden} - another company's applicant data stays with that company");
    }

    // --- one query for a whole page -------------------------------------------
    // Counted at the server: Com_select is a per-connection counter, and SHOW
    // STATUS is not itself a SELECT, so the difference is what forRows() ran.
    $selects = static function (): int {
        $row = Db::selectOne("SHOW SESSION STATUS LIKE 'Com_select'");
        return (int) ($row['Value'] ?? 0);
    };
    $pageRows = array_map($rowFor, [$alone, $far, $tight, $sameHost, $overlap]);
    $baseline = $selects();
    $measureCost = $selects() - $baseline;   // what the measurement itself costs
    $before = $selects();
    $schedule->forRows($pageRows);
    $ran = $selects() - $before - $measureCost;
    $assert($ran === 1,
        "a page of bookings costs one query, not one per row (ran {$ran})");
} finally {
    Db::execute('DELETE FROM mail_queue WHERE to_email LIKE ?', ['%@' . FIXTURE_EMAIL_DOMAIN]);
    fixture_cleanup();
}

echo $failures === 0 ? "schedule clash: all OK\n" : "schedule clash: {$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);
