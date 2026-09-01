<?php

declare(strict_types=1);

/**
 * 予約不要 events (events.booking_required = 0): no sessions shown, no
 * applications accepted, and an optional external link.
 *
 * The interesting part is not the display - it is that sessions created
 * before the flag was set still exist, so a bookmarked apply URL has to be
 * refused rather than merely unlinked.
 */

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/_fixture.php';

use App\Core\Db;
use App\Core\Validator;
use App\Exception\NotFoundException;
use App\Exception\ValidationException;
use App\Repository\EventRepository;
use App\Repository\EventSessionRepository;
use App\Service\BookingService;

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
$company = fixture_create_company('nobooking');
$events  = new EventRepository();

try {
    // --- the flag round-trips, and existing rows are unaffected -------------
    $normal = fixture_create_event($company, 'normal event');
    $normalRow = $events->find($normal);
    $assert((int) $normalRow['booking_required'] === 1 && $normalRow['external_url'] === null,
        'events default to requiring a booking, no external link');

    $freeId = $events->create(
        $company, 'drop-in exhibit', '当日直接お越しください。', '展示ホール', 0, true,
        bookingRequired: false,
        externalUrl: 'https://example.test/exhibit?utm_source=list',
    );
    $freeRow = $events->find($freeId);
    $assert((int) $freeRow['booking_required'] === 0, '予約不要 stored');
    $assert($freeRow['external_url'] === 'https://example.test/exhibit?utm_source=list',
        'external URL stored with its query string intact');

    // --- sessions that predate the flag stay in the table but stop working --
    $session = fixture_create_session($freeId, '2027-04-01 10:00:00', '2027-04-01 11:00:00', 10);
    $assert((int) Db::scalar('SELECT COUNT(*) FROM event_sessions WHERE id = ?', [$session]) === 1,
        'the session row still exists (setting the flag deletes nothing)');

    // Fixtures are created unpublished so they never surface on the real site;
    // the public-facing queries filter on that, so publish for these two
    // assertions and hide the rows again straight afterwards.
    $publish = static function (bool $on) use ($company, $freeId): void {
        Db::execute('UPDATE companies SET is_published = ? WHERE id = ?', [$on ? 1 : 0, $company]);
        Db::execute('UPDATE events SET is_published = ? WHERE id = ?', [$on ? 1 : 0, $freeId]);
    };
    $publish(true);

    // The public booking screens look sessions up through this method.
    $context = (new EventSessionRepository())->findWithContext($session, true);
    $assert($context !== null && (int) $context['booking_required'] === 0,
        'session context carries the parent event flag for the controller guard');

    // And the service refuses even when called directly, past the controller.
    $refused = false;
    try {
        (new BookingService())->book($session, fixture_email('nb-a'), 'A', 1);
    } catch (ValidationException $e) {
        $refused = str_contains($e->getMessage(), '予約不要');
    }
    $assert($refused, 'BookingService refuses a session under a 予約不要 event');
    $assert((int) Db::scalar('SELECT COUNT(*) FROM bookings WHERE session_id = ?', [$session]) === 0,
        'nothing was written by the refused booking');
    $assert((int) Db::scalar('SELECT confirmed_seats FROM event_sessions WHERE id = ?', [$session]) === 0,
        'seat counter untouched');

    // Flip it back and the same session books again - the flag gates, it does
    // not destroy.
    $events->update($freeId, $company, 'drop-in exhibit', null, null, 0, true,
        bookingRequired: true, externalUrl: $freeRow['external_url'],
        maxPartySize: (int) $freeRow['max_party_size']);
    $booked = (new BookingService())->book($session, fixture_email('nb-a'), 'A', 1);
    $assert($booked['booking_id'] > 0, 'clearing the flag makes the same session bookable again');
    $events->update($freeId, $company, 'drop-in exhibit', null, null, 0, true,
        bookingRequired: false, externalUrl: $freeRow['external_url'],
        maxPartySize: (int) $freeRow['max_party_size']);

    // --- the catalogue query exposes what the templates branch on ----------
    $catalogue = $events->publishedCatalogue();
    $row = null;
    foreach ($catalogue as $candidate) {
        if ((int) $candidate['id'] === $freeId) {
            $row = $candidate;
        }
    }
    $assert($row !== null, '予約不要 events still appear in the public catalogue');
    $assert($row !== null && (int) $row['booking_required'] === 0 && $row['external_url'] !== null,
        'catalogue rows carry booking_required and external_url for the card');
    $publish(false);

    // --- URL validation: the scheme whitelist is the security-relevant part -
    $cases = [
        'https://example.test/x'       => true,
        'http://example.test/x'        => true,
        ''                             => true,  // optional
        '   '                          => true,  // optional after trim
        'javascript:alert(1)'          => false, // would execute from an href
        'JavaScript:alert(1)'          => false, // case does not help
        'data:text/html;base64,PHNjcg' => false,
        'ftp://example.test/x'         => false,
        'example.test/x'               => false, // scheme-less
        '//example.test/x'             => false, // protocol-relative
    ];
    foreach ($cases as $candidate => $shouldPass) {
        $validator = new Validator();
        $validator->url('external_url', '外部リンクURL', (string) $candidate);
        $ok = !$validator->hasErrors();
        $assert($ok === $shouldPass, sprintf(
            'URL %-32s %s',
            $candidate === '' ? "''" : ($candidate === '   ' ? "'   '" : $candidate),
            $shouldPass ? 'accepted' : 'rejected'
        ));
    }

    $validator = new Validator();
    $validator->url('external_url', '外部リンクURL', '   ');
    $assert($validator->value('external_url') === null, 'a blank URL normalises to NULL, not an empty string');

    $validator = new Validator();
    $validator->url('external_url', '外部リンクURL', 'https://example.test/' . str_repeat('a', 500));
    $assert($validator->hasErrors(), 'an over-long URL is rejected before it hits VARCHAR(500)');
} finally {
    fixture_cleanup();
}

echo $failures === 0 ? "no-booking events: all OK\n" : "no-booking events: {$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);
