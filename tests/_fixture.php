<?php

declare(strict_types=1);

/**
 * Shared scratch-data helpers for the tests/ scripts. Underscore-prefixed so a
 * "run every test_*.php" glob never executes this file directly.
 *
 * Everything is plain DML through the application account, created under a
 * recognisable company-name prefix and torn down by fixture_cleanup(). The
 * cleanup order respects the FKs: bookings (booking_events cascade) ->
 * applicants -> sessions -> events -> company.
 */

use App\Core\Db;

const FIXTURE_PREFIX = 'CT-TEST-';
const FIXTURE_EMAIL_DOMAIN = 'concurrency.example.test';

function fixture_create_company(string $suffix): int
{
    Db::execute(
        'INSERT INTO companies (name, sort_order, is_published) VALUES (?, 9900, 0)',
        [FIXTURE_PREFIX . $suffix]
    );
    return Db::lastInsertId();
}

function fixture_create_event(int $companyId, string $title): int
{
    Db::execute(
        'INSERT INTO events (company_id, title, is_published) VALUES (?, ?, 0)',
        [$companyId, $title]
    );
    return Db::lastInsertId();
}

function fixture_create_session(int $eventId, string $startsAt, string $endsAt, int $capacity): int
{
    Db::execute(
        "INSERT INTO event_sessions (event_id, starts_at, ends_at, capacity, status)
         VALUES (?, ?, ?, ?, 'open')",
        [$eventId, $startsAt, $endsAt, $capacity]
    );
    return Db::lastInsertId();
}

/** Remove every fixture row this file's helpers ever created, current run or a crashed earlier one. */
function fixture_cleanup(): void
{
    $companyIds = array_map(
        static fn (array $row): int => (int) $row['id'],
        Db::select('SELECT id FROM companies WHERE name LIKE ?', [FIXTURE_PREFIX . '%'])
    );
    if ($companyIds !== []) {
        $in = implode(',', array_fill(0, count($companyIds), '?'));

        Db::execute(
            "DELETE b FROM bookings b
             JOIN event_sessions s ON s.id = b.session_id
             JOIN events e ON e.id = s.event_id
             WHERE e.company_id IN ({$in})",
            $companyIds
        );
        Db::execute(
            "DELETE s FROM event_sessions s
             JOIN events e ON e.id = s.event_id
             WHERE e.company_id IN ({$in})",
            $companyIds
        );
        Db::execute("DELETE FROM events WHERE company_id IN ({$in})", $companyIds);
        Db::execute("DELETE FROM companies WHERE id IN ({$in})", $companyIds);
    }

    // Fixture applicants are deletable once their bookings are gone.
    Db::execute('DELETE FROM applicants WHERE email LIKE ?', ['%@' . FIXTURE_EMAIL_DOMAIN]);
}

function fixture_email(string $local): string
{
    return $local . '@' . FIXTURE_EMAIL_DOMAIN;
}
