<?php

declare(strict_types=1);

/**
 * The contact (bookings.contact_name) as distinct from the participants.
 *
 * bookings.name is attendee_no 1 - a child, for a workshop where only children
 * take part. The person to write to and ring is whoever owns the address and
 * the number, so the two are separate columns and the mail is addressed to the
 * second one. Before there was a field for it the form asked for the name in
 * the free-text message, where it reached neither the addressee line nor the
 * booking list.
 */

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/_fixture.php';

use App\Core\Db;
use App\Core\Validator;
use App\Repository\EventRepository;
use App\Service\BookingService;
use App\Service\CancellationService;
use App\Service\WaitlistService;

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

$mailFor = static fn (int $bookingId): string => (string) Db::scalar(
    'SELECT body FROM mail_queue WHERE booking_id = ? ORDER BY id DESC LIMIT 1',
    [$bookingId]
);
$mailToName = static fn (int $bookingId): string => (string) Db::scalar(
    'SELECT to_name FROM mail_queue WHERE booking_id = ? ORDER BY id DESC LIMIT 1',
    [$bookingId]
);

fixture_cleanup();
$company = fixture_create_company('contact');
$eventId = (new EventRepository())->create($company, 'contact event', null, null, 0, true);
$service = new BookingService();

try {
    // --- a parent booking for a child ---------------------------------------
    $s = fixture_create_session($eventId, '2028-01-01 10:00:00', '2028-01-01 11:00:00', 10);

    $booking = $service->book($s, fixture_email('ct-a'), '山田 太郎', 1,
        ages: [8], phone: '090-0000-1111', contactName: '山田 花子');
    $id = (int) $booking['booking_id'];

    $row = Db::selectOne('SELECT name, contact_name FROM bookings WHERE id = ?', [$id]);
    $assert($row['name'] === '山田 太郎', 'name stays the participant');
    $assert($row['contact_name'] === '山田 花子', 'contact_name is stored separately');
    $assert((string) Db::scalar(
        'SELECT name FROM booking_attendees WHERE booking_id = ? AND attendee_no = 1',
        [$id]
    ) === '山田 太郎', 'the attendee row is the participant, not the contact');

    // The mail goes to the contact's address, so it is addressed to them.
    $assert(str_contains($mailFor($id), '山田 花子 様'),
        'the confirmation mail is addressed to the contact');
    $assert($mailToName($id) === '山田 花子', 'and the queue row carries their name');

    // ...but it still has to say who is coming, which for a party of one used
    // to be omitted as "the person being written to".
    $assert(str_contains($mailFor($id), '山田 太郎'),
        'and still names the participant, even as a party of one');

    // --- an adult booking for themselves ------------------------------------
    $solo = $service->book($s, fixture_email('ct-b'), '鈴木 一郎', 1,
        ages: [40], phone: '090-0000-2222', contactName: '鈴木 一郎');
    $soloId = (int) $solo['booking_id'];
    $assert(substr_count($mailFor($soloId), '鈴木 一郎') === 1,
        'when the two are the same person the mail names them once, not twice');

    // --- omitted by a CLI caller --------------------------------------------
    // The concurrency harness and bin/ scripts must not have to invent a
    // second person, so the column falls back to the participant.
    $cli = $service->book($s, fixture_email('ct-c'), 'CLI 太郎', 1, ages: [30]);
    $assert((string) Db::scalar('SELECT contact_name FROM bookings WHERE id = ?',
        [$cli['booking_id']]) === 'CLI 太郎',
        'omitting the contact falls back to the participant rather than storing blank');

    // --- the other two mails are addressed the same way ---------------------
    $waitSession = fixture_create_session($eventId, '2028-01-02 10:00:00', '2028-01-02 11:00:00', 1);
    $holder = $service->book($waitSession, fixture_email('ct-d'), '占有 太郎', 1, ages: [30]);
    $queued = $service->book($waitSession, fixture_email('ct-e'), '子ども 次郎', 1,
        ages: [7], contactName: '保護者 次郎');

    (new CancellationService())->cancelById((int) $holder['booking_id'], 'test:contact');
    (new WaitlistService())->promote((int) $queued['booking_id'], 'test:contact');
    $assert(str_contains($mailFor((int) $queued['booking_id']), '保護者 次郎 様'),
        'the promotion mail is addressed to the contact');

    (new CancellationService())->cancelById((int) $queued['booking_id'], 'test:contact');
    $assert(str_contains($mailFor((int) $queued['booking_id']), '保護者 次郎 様'),
        'and so is the cancellation mail');

    // --- the admin projections carry it -------------------------------------
    $listed = (new App\Repository\BookingRepository())->searchForAdmin(
        ['company_id' => 0, 'event_id' => 0, 'session_id' => 0, 'status' => '', 'email' => fixture_email('ct-a')],
        10,
        0
    );
    $assert($listed !== [] && ($listed[0]['contact_name'] ?? null) === '山田 花子',
        'the booking list and CSV read the contact from searchForAdmin');

    $byRef = (new App\Repository\BookingRepository())->findByReference((string) $booking['reference_code']);
    $assert($byRef !== null && ($byRef['contact_name'] ?? null) === '山田 花子',
        'and the completion and manage screens read it from findByReference');

    // --- required, and capped like a name -----------------------------------
    $validator = new Validator();
    $validator->required('contact_name', '連絡先のご氏名', '');
    $assert($validator->hasErrors(), 'a blank contact name is refused');

    $validator = new Validator();
    $validator->maxLength('contact_name', '連絡先のご氏名', str_repeat('あ', 101), 100);
    $assert($validator->hasErrors(), 'and one over 100 characters is refused before VARCHAR(100)');
} finally {
    fixture_cleanup();
}

echo $failures === 0 ? "contact name: all OK\n" : "contact name: {$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);
