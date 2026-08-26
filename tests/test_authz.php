<?php

declare(strict_types=1);

/**
 * Company scoping: a company account must not read or write another
 * company's rows, and the office must still see everything.
 *
 * The screens hide the links, but hiding is not a boundary - a typed URL is
 * the attack. These assertions go against Authz and the scoped repository
 * queries directly, which is where the boundary actually lives.
 */

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/_fixture.php';

use App\Core\Auth;
use App\Core\Authz;
use App\Core\Db;
use App\Domain\AdminRole;
use App\Exception\NotFoundException;
use App\Repository\AdminUserRepository;
use App\Repository\BookingRepository;
use App\Repository\EventRepository;
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
$denied = static function (callable $fn): bool {
    try {
        $fn();
        return false;
    } catch (NotFoundException) {
        return true;
    }
};

/**
 * Auth reads the signed-in account from the session; in CLI there is none, so
 * sign in by hand for the duration of a closure. This is the same static the
 * web request populates - no production code path is bypassed.
 */
$actingAs = static function (?int $companyId, callable $fn): void {
    $reflection = new ReflectionClass(Auth::class);
    $cached = $reflection->getProperty('cached');
    $cached->setValue(null, [
        'id'           => 999,
        'username'     => 'test',
        'display_name' => 'test',
        'role'         => $companyId === null ? AdminRole::Superadmin : AdminRole::Company,
        'company_id'   => $companyId,
    ]);
    try {
        $fn();
    } finally {
        $cached->setValue(null, null);
    }
};

fixture_cleanup();

// Two companies, one event and one booked session each.
$companyA = fixture_create_company('authz-A');
$companyB = fixture_create_company('authz-B');
$eventA   = fixture_create_event($companyA, 'authz A');
$eventB   = fixture_create_event($companyB, 'authz B');
$sessionA = fixture_create_session($eventA, '2026-12-01 10:00:00', '2026-12-01 11:00:00', 5);
$sessionB = fixture_create_session($eventB, '2026-12-02 10:00:00', '2026-12-02 11:00:00', 5);

$service = new BookingService();
$bookingA = $service->book($sessionA, fixture_email('authz-a'), 'A', 1);
$bookingB = $service->book($sessionB, fixture_email('authz-b'), 'B', 1);

try {
    $events   = new EventRepository();
    $bookings = new BookingRepository();

    // --- as company A ------------------------------------------------------
    $actingAs($companyA, function () use ($assert, $denied, $companyA, $companyB, $events, $bookings, $bookingB) {
        $assert(Authz::scopeCompanyId(0) === $companyA, 'listing is scoped to own company');
        $assert(Authz::scopeCompanyId($companyB) === $companyA, 'requesting another company does not widen the scope');

        $assert($denied(static fn () => Authz::assertCompany($companyB)), 'assertCompany rejects another company');
        $assert($denied(static fn () => Authz::assertCompany(null)), 'assertCompany rejects an unowned row');
        Authz::assertCompany($companyA);
        $assert(true, 'assertCompany allows own company');

        $assert($denied(static fn () => Authz::requireSuperadmin()), 'office-only screens are refused');

        $titles = array_column($events->listForAdmin(Authz::scopeCompanyId(0)), 'company_id');
        $assert($titles !== [] && array_unique($titles) === [$companyA], 'event list contains only own events');

        // The write path guard: load the row, then check who owns it.
        $other = $bookings->findById((int) $bookingB['booking_id']);
        $assert($other !== null, 'the row is fetchable (the repository is not the boundary)');
        $assert($denied(static fn () => Authz::assertCompany((int) $other['company_id'])),
            'another company\'s booking is refused before any write');
    });

    // --- as the office -----------------------------------------------------
    $actingAs(null, function () use ($assert, $companyA, $companyB, $events, $bookings, $bookingA, $bookingB) {
        Authz::requireSuperadmin();
        Authz::assertCompany($companyA);
        Authz::assertCompany($companyB);
        Authz::assertCompany(null);
        $assert(true, 'office passes every company check');
        $assert(Authz::scopeCompanyId(0) === null, 'office listing is unscoped by default');
        $assert(Authz::scopeCompanyId($companyB) === $companyB, 'office may filter to one company');

        $ids = array_column($events->listForAdmin(null), 'company_id');
        $assert(in_array($companyA, array_map('intval', $ids), true)
            && in_array($companyB, array_map('intval', $ids), true), 'office sees both companies');

        $assert($bookings->findById((int) $bookingA['booking_id']) !== null
            && $bookings->findById((int) $bookingB['booking_id']) !== null, 'office reaches both bookings');
    });

    // --- the scoped search used by the booking list -------------------------
    $rowsA = $bookings->searchForAdmin(['company_id' => $companyA], 100, 0);
    $assert(count($rowsA) === 1 && (int) $rowsA[0]['company_id'] === $companyA,
        'searchForAdmin returns only the scoped company');
    $assert($bookings->countForAdmin(['company_id' => $companyA]) === 1, 'countForAdmin agrees');

    // A session id from another company must not leak through the filter.
    $crossed = $bookings->searchForAdmin(['company_id' => $companyA, 'session_id' => $sessionB], 100, 0);
    $assert($crossed === [], 'another company\'s session id matches nothing under the scope');

    // --- the role/company pairing ------------------------------------------
    // This was a CHECK constraint until MariaDB 11.8 refused it (errno 1901),
    // so AdminUserRepository carries the rule now. The database no longer
    // catches a bad pair, which makes these assertions the guarantee rather
    // than a second opinion on it.
    $accounts = new AdminUserRepository();
    $refused = static function (callable $fn): bool {
        try {
            $fn();
            return false;
        } catch (InvalidArgumentException) {
            return true;
        }
    };

    $assert($refused(static fn () => $accounts->create(
        'ct-bad-1', 'x', 'bad', AdminRole::Company->value, null
    )), 'a company account without a company is refused');

    $assert($refused(static fn () => $accounts->create(
        'ct-bad-2', 'x', 'bad', AdminRole::Superadmin->value, $companyA
    )), 'an office account with a company is refused');

    $assert($refused(static fn () => $accounts->create(
        'ct-bad-3', 'x', 'bad', 'auditor', $companyA
    )), 'an unknown role is refused');

    $assert((int) Db::scalar("SELECT COUNT(*) FROM admin_users WHERE username LIKE 'ct-bad-%'") === 0,
        'none of the refused accounts reached the table');

    // updateProfile guards the same way - the admin edit screen goes through it.
    $goodId = $accounts->create('ct-good', 'x', 'good', AdminRole::Company->value, $companyA);
    $assert($refused(static fn () => $accounts->updateProfile(
        $goodId, 'good', AdminRole::Company->value, null
    )), 'updating a company account to have no company is refused');
    $assert((int) Db::scalar('SELECT company_id FROM admin_users WHERE id = ?', [$goodId]) === $companyA,
        'the refused update changed nothing');

    // The valid transitions still work.
    $accounts->updateProfile($goodId, 'good', AdminRole::Superadmin->value, null);
    $assert(Db::scalar('SELECT company_id FROM admin_users WHERE id = ?', [$goodId]) === null,
        'promoting to office clears the company');
    Db::execute('DELETE FROM admin_users WHERE id = ?', [$goodId]);
} finally {
    fixture_cleanup();
}

echo $failures === 0 ? "authz: all OK\n" : "authz: {$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);
