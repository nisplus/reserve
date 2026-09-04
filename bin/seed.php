<?php

declare(strict_types=1);

/**
 * Loads representative data: 14 companies, 4 events each, 5-10 sessions per
 * event on a single day.
 *
 * The dataset is shaped for the tests that matter, not just for looks:
 *
 *   - Every company runs its events on the same day and overlapping hours, so
 *     the cross-company overlap rule has something to catch.
 *   - Each company's fourth event runs on a half-hour offset, producing slots
 *     that partially overlap the others rather than lining up neatly.
 *   - Each event gets one deliberately tiny slot (capacity 2) so the full /
 *     waitlist path can be reached by hand in a few clicks.
 *   - Slot 1 and slot 2 of every event are back-to-back with no gap, which is
 *     the boundary case: they must NOT count as overlapping.
 *
 * Usage:
 *   php bin/seed.php            insert (fails if data already present)
 *   php bin/seed.php --fresh    truncate booking data first, then insert
 */

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Auth;
use App\Core\Db;

if (PHP_SAPI !== 'cli') {
    exit("This script is CLI-only.\n");
}

Db::useAdminCredentials();

$fresh = in_array('--fresh', array_slice($argv, 1), true);

// Fixed seed: re-running produces the same catalogue, so a bug report that
// mentions "session 137" still means the same thing tomorrow.
mt_srand(20260820);

/** The events all take place on this day. */
$eventDay = '2026-09-15';

$companies = [
    ['name' => '株式会社アオイ製作所',     'kana' => 'アオイセイサクショ'],
    ['name' => '有限会社イワセ運輸',       'kana' => 'イワセウンユ'],
    ['name' => '株式会社ウメダ電機',       'kana' => 'ウメダデンキ'],
    ['name' => 'エノキ食品株式会社',       'kana' => 'エノキショクヒン'],
    ['name' => '株式会社オガワ精密',       'kana' => 'オガワセイミツ'],
    ['name' => 'カシワギ化学株式会社',     'kana' => 'カシワギカガク'],
    ['name' => '株式会社キリノ設計',       'kana' => 'キリノセッケイ'],
    ['name' => 'クスノキ印刷株式会社',     'kana' => 'クスノキインサツ'],
    ['name' => '株式会社ケヤキソフト',     'kana' => 'ケヤキソフト'],
    ['name' => 'コウノ産業株式会社',       'kana' => 'コウノサンギョウ'],
    ['name' => '株式会社サカキ金属',       'kana' => 'サカキキンゾク'],
    ['name' => 'シノハラ物流株式会社',     'kana' => 'シノハラブツリュウ'],
    ['name' => '株式会社スギモト農園',     'kana' => 'スギモトノウエン'],
    ['name' => 'セリザワ機械株式会社',     'kana' => 'セリザワキカイ'],
];

/** Four event templates, applied to every company. */
$eventTemplates = [
    [
        'suffix'      => '　工場見学ツアー',
        'description' => "普段は入れない製造ラインを、担当者の解説付きでご案内します。\n動きやすい服装でお越しください。安全のため、ヒールのある靴ではご参加いただけません。",
        'venue'       => '本社工場 A棟エントランス',
        'duration'    => 45,
        'interval'    => 60,   // 45 min tour + 15 min turnaround
        'firstStart'  => '10:00',
    ],
    [
        'suffix'      => '　技術説明会',
        'description' => "自社の要素技術の概要と、直近の開発事例をご紹介します。\n質疑応答の時間を設けています。",
        'venue'       => '本社 3階 大会議室',
        'duration'    => 50,
        'interval'    => 60,
        'firstStart'  => '10:00',
    ],
    [
        'suffix'      => '　採用相談会',
        'description' => "現場社員との少人数座談会です。仕事の進め方や一日の流れなど、気になることをお聞きください。",
        'venue'       => '本社 2階 応接室',
        'duration'    => 40,
        'interval'    => 60,
        'firstStart'  => '11:00',
    ],
    [
        // Deliberately offset by 30 minutes so its slots straddle the
        // boundaries of the other three events rather than aligning with them.
        'suffix'      => '　製品体験ワークショップ',
        'description' => "実際に製品に触れていただく体験型のワークショップです。\n材料費は無料です。作ったものはお持ち帰りいただけます。",
        'venue'       => '別館 1階 実習室',
        'duration'    => 55,
        'interval'    => 60,
        'firstStart'  => '10:30',
    ],
];

$pdo = Db::pdo();

if ($fresh) {
    echo "--fresh: clearing booking data\n";
    // Every table this script or the booking flow writes, children first.
    // FOREIGN_KEY_CHECKS is off, so ON DELETE CASCADE does NOT fire and each
    // child has to be named: a table added by a later migration and forgotten
    // here would survive the truncate and dangle. admin_users is deliberately
    // absent - wiping the accounts would lock the office out of its own site.
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ([
        'booking_attendees',
        'booking_events',
        'mail_queue',
        'bookings',
        'applicants',
        'event_sessions',
        'events',
        'companies',
    ] as $table) {
        $pdo->exec("TRUNCATE TABLE {$table}");
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
}

$existing = (int) Db::scalar('SELECT COUNT(*) FROM companies');
if ($existing > 0) {
    exit("companies already holds {$existing} row(s). Re-run with --fresh to replace the data.\n");
}

$pdo->beginTransaction();

$insertCompany = $pdo->prepare(
    'INSERT INTO companies (name, name_kana, sort_order) VALUES (?, ?, ?)'
);
$insertEvent = $pdo->prepare(
    'INSERT INTO events (company_id, title, description, venue, sort_order) VALUES (?, ?, ?, ?, ?)'
);
$insertSession = $pdo->prepare(
    'INSERT INTO event_sessions (event_id, starts_at, ends_at, capacity) VALUES (?, ?, ?, ?)'
);

$companyCount = 0;
$eventCount   = 0;
$sessionCount = 0;
$tinySlots    = [];

foreach ($companies as $companyIndex => $company) {
    $insertCompany->execute([$company['name'], $company['kana'], ($companyIndex + 1) * 10]);
    $companyId = (int) $pdo->lastInsertId();
    $companyCount++;

    foreach ($eventTemplates as $templateIndex => $template) {
        $insertEvent->execute([
            $companyId,
            $company['name'] . $template['suffix'],
            $template['description'],
            $template['venue'],
            ($templateIndex + 1) * 10,
        ]);
        $eventId = (int) $pdo->lastInsertId();
        $eventCount++;

        $slots = mt_rand(5, 10);
        $cursor = new DateTimeImmutable($eventDay . ' ' . $template['firstStart']);

        for ($slot = 0; $slot < $slots; $slot++) {
            $startsAt = $cursor;
            $endsAt   = $cursor->modify('+' . $template['duration'] . ' minutes');

            // Slot 1 and 2 are made contiguous: slot 2 begins exactly when slot
            // 1 ends. Booking both must be allowed - it is the boundary the
            // half-open overlap rule is there to get right.
            if ($slot === 1) {
                $startsAt = $cursor->modify('-' . ($template['interval'] - $template['duration']) . ' minutes');
                $endsAt   = $startsAt->modify('+' . $template['duration'] . ' minutes');
            }

            // One slot per event is tiny, so "full -> waitlist" is two clicks away.
            $isTiny   = ($slot === 2);
            $capacity = $isTiny ? 2 : mt_rand(8, 20);

            $insertSession->execute([
                $eventId,
                $startsAt->format('Y-m-d H:i:s'),
                $endsAt->format('Y-m-d H:i:s'),
                $capacity,
            ]);
            $sessionCount++;

            if ($isTiny && count($tinySlots) < 3) {
                $tinySlots[] = sprintf(
                    '  session #%d  %s  %s〜%s  capacity 2',
                    (int) $pdo->lastInsertId(),
                    $company['name'] . $template['suffix'],
                    $startsAt->format('H:i'),
                    $endsAt->format('H:i')
                );
            }

            $cursor = $cursor->modify('+' . $template['interval'] . ' minutes');
        }
    }
}

$pdo->commit();

printf("Seeded %d companies, %d events, %d sessions on %s.\n",
    $companyCount, $eventCount, $sessionCount, $eventDay);

if ($tinySlots !== []) {
    echo "Small slots for exercising the waitlist:\n" . implode("\n", $tinySlots) . "\n";
}

// An admin account, so the back office is reachable straight after seeding.
$adminExists = (int) Db::scalar('SELECT COUNT(*) FROM admin_users');
if ($adminExists === 0) {
    $password = bin2hex(random_bytes(6));
    Db::execute(
        'INSERT INTO admin_users (username, password_hash, display_name) VALUES (?, ?, ?)',
        ['admin', Auth::hashPassword($password), '管理者']
    );
    echo "\nAdmin account created:\n  username: admin\n  password: {$password}\n";
    echo "  (Change it with: php bin/create_admin.php admin <new-password>)\n";
}
