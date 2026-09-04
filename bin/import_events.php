<?php

declare(strict_types=1);

/**
 * Bulk-load companies, events and their sessions from one CSV.
 *
 * One row describes one event, plus the rule for generating its sessions
 * (first start, duration, gap, count, capacity) - the same shape as the admin
 * screen's bulk generator, because that is how these events are actually
 * scheduled: a run of equal slots on one day. Irregular times are added
 * afterwards in the admin screen; trying to express them here would need a
 * second file and would not earn it.
 *
 * Companies are matched by name and created when missing, so a spreadsheet of
 * 14 companies x 4 events works without anyone looking up ids.
 *
 * The whole file is validated before anything is written, and the write is a
 * single transaction: a typo on row 40 leaves the database untouched rather
 * than half-loaded.
 *
 * Usage:
 *   php bin/import_events.php events.csv --dry-run
 *   php bin/import_events.php events.csv
 *   php bin/import_events.php --template > events.csv
 *
 * The file must be UTF-8. Excel's "CSV UTF-8" export works; a BOM is fine.
 */

if (PHP_SAPI !== 'cli') {
    exit("This script is CLI-only.\n");
}

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Db;
use App\Domain\Area;

const COLUMNS = [
    '会社名', 'エリア', 'イベント名', '説明', '会場', '外部URL',
    '予約不要', '上限人数', '公開',
    '開始日時', '所要分', '間隔分', '回数', '定員',
];

$options  = array_slice($argv, 1);
$dryRun   = in_array('--dry-run', $options, true);
$template = in_array('--template', $options, true);
$path     = null;
foreach ($options as $option) {
    if (!str_starts_with($option, '--')) {
        $path = $option;
    }
}

if ($template) {
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, COLUMNS, ',', '"', '\\', "\r\n");
    fputcsv($out, [
        '株式会社サンプル製作所', 'east', '工場見学ツアー',
        "普段は入れない製造ラインをご案内します。\n動きやすい服装でお越しください。",
        '本社工場 A棟', 'https://example.com/tour', '', '5', '1',
        '2027-03-01 10:00', '45', '15', '6', '20',
    ], ',', '"', '\\', "\r\n");
    fputcsv($out, [
        '株式会社サンプル製作所', 'east', '常設展示（予約不要）',
        '当日直接お越しください。', '展示ホール', 'https://example.com/exhibit', '1', '', '1',
        '', '', '', '', '',
    ], ',', '"', '\\', "\r\n");
    exit(0);
}

if ($path === null || !is_file($path)) {
    fwrite(STDERR, "Usage: php bin/import_events.php <file.csv> [--dry-run]\n");
    fwrite(STDERR, "       php bin/import_events.php --template > events.csv\n");
    exit(1);
}

$handle = fopen($path, 'r');
if ($handle === false) {
    fwrite(STDERR, "Cannot read {$path}\n");
    exit(1);
}

// Excel writes a BOM; left in place it becomes part of the first header name.
$first = fgets($handle);
if ($first !== false) {
    rewind($handle);
    if (str_starts_with($first, "\xEF\xBB\xBF")) {
        fseek($handle, 3);
    }
}

$header = fgetcsv($handle, 0, ',', '"', '\\');
if ($header === false) {
    fwrite(STDERR, "The file is empty.\n");
    exit(1);
}
$header = array_map(static fn (string $h): string => trim($h), $header);

$missing = array_diff(COLUMNS, $header);
if ($missing !== []) {
    fwrite(STDERR, '見出し行に次の列がありません: ' . implode(', ', $missing) . "\n");
    fwrite(STDERR, '必要な列: ' . implode(', ', COLUMNS) . "\n");
    exit(1);
}

$rows = [];
$errors = [];
$lineNo = 1;
$areaValues = array_keys(Area::options());

/** Booleans are written as 1/0/はい/いいえ/空欄 by whoever made the sheet. */
$asBool = static function (string $value, bool $default): bool {
    $value = trim($value);
    if ($value === '') {
        return $default;
    }
    return in_array(mb_strtolower($value), ['1', 'yes', 'y', 'true', 'はい', '○', 'o'], true);
};

while (($line = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
    $lineNo++;
    if ($line === [null] || implode('', array_map('strval', $line)) === '') {
        continue; // blank row
    }

    $row = [];
    foreach ($header as $index => $name) {
        $row[$name] = trim((string) ($line[$index] ?? ''));
    }

    $problem = static function (string $message) use (&$errors, $lineNo): void {
        $errors[] = "{$lineNo} 行目: {$message}";
    };

    if ($row['会社名'] === '') {
        $problem('会社名は必須です');
    }
    if ($row['イベント名'] === '') {
        $problem('イベント名は必須です');
    }
    if ($row['エリア'] !== '' && !in_array($row['エリア'], $areaValues, true)) {
        $problem("エリアは " . implode(' / ', $areaValues) . " のいずれかです（{$row['エリア']}）");
    }
    if ($row['外部URL'] !== '' && !preg_match('#^https?://#i', $row['外部URL'])) {
        $problem('外部URLは http:// または https:// で始めてください');
    }

    $noBooking = $asBool($row['予約不要'], false);
    $maxParty  = $row['上限人数'] === '' ? 20 : (int) $row['上限人数'];
    if ($maxParty < 1 || $maxParty > 20) {
        $problem('上限人数は 1〜20 です');
    }

    // Session generation. A 予約不要 event legitimately has none.
    $sessions = [];
    $hasSchedule = $row['開始日時'] !== '';
    if ($noBooking && $hasSchedule) {
        $problem('予約不要のイベントに開催回は指定できません');
    } elseif (!$noBooking && !$hasSchedule) {
        $problem('開始日時が空です（予約不要にするなら「予約不要」を 1 にしてください）');
    } elseif ($hasSchedule) {
        $start = date_create_immutable(str_replace('/', '-', $row['開始日時']));
        $duration = (int) $row['所要分'];
        $gap      = $row['間隔分'] === '' ? 0 : (int) $row['間隔分'];
        $count    = (int) $row['回数'];
        $capacity = (int) $row['定員'];

        if ($start === false) {
            $problem("開始日時を解釈できません（{$row['開始日時']}）。例: 2027-03-01 10:00");
        }
        if ($duration < 5 || $duration > 600) {
            $problem('所要分は 5〜600 です');
        }
        if ($gap < 0 || $gap > 600) {
            $problem('間隔分は 0〜600 です');
        }
        if ($count < 1 || $count > 20) {
            $problem('回数は 1〜20 です');
        }
        if ($capacity < 1 || $capacity > 999) {
            $problem('定員は 1〜999 です');
        }

        if ($start !== false && $duration >= 5 && $count >= 1 && $capacity >= 1) {
            for ($i = 0; $i < $count; $i++) {
                $slotStart = $start->modify('+' . $i * ($duration + $gap) . ' minutes');
                $sessions[] = [
                    'starts_at' => $slotStart->format('Y-m-d H:i:s'),
                    'ends_at'   => $slotStart->modify("+{$duration} minutes")->format('Y-m-d H:i:s'),
                    'capacity'  => $capacity,
                ];
            }
        }
    }

    $rows[] = [
        'line'      => $lineNo,
        'company'   => $row['会社名'],
        'area'      => $row['エリア'] !== '' ? $row['エリア'] : null,
        'title'     => $row['イベント名'],
        'description' => $row['説明'] !== '' ? $row['説明'] : null,
        'venue'     => $row['会場'] !== '' ? $row['会場'] : null,
        'url'       => $row['外部URL'] !== '' ? $row['外部URL'] : null,
        'booking_required' => !$noBooking,
        'max_party' => $maxParty,
        'published' => $asBool($row['公開'], true),
        'sessions'  => $sessions,
    ];
}
fclose($handle);

if ($rows === []) {
    fwrite(STDERR, "データ行がありません。\n");
    exit(1);
}

// Duplicate (company, event) inside the file, and against what is already stored.
$seen = [];
foreach ($rows as $row) {
    $key = $row['company'] . "\0" . $row['title'];
    if (isset($seen[$key])) {
        $errors[] = "{$row['line']} 行目: 「{$row['company']}／{$row['title']}」が {$seen[$key]} 行目と重複しています";
    }
    $seen[$key] = $row['line'];

    $exists = (int) Db::scalar(
        'SELECT COUNT(*) FROM events e JOIN companies c ON c.id = e.company_id
         WHERE c.name = ? AND e.title = ?',
        [$row['company'], $row['title']]
    );
    if ($exists > 0) {
        $errors[] = "{$row['line']} 行目: 「{$row['company']}／{$row['title']}」は既に登録されています";
    }
}

if ($errors !== []) {
    fwrite(STDERR, "取り込みを中止しました。以下を直してください:\n");
    foreach ($errors as $error) {
        fwrite(STDERR, "  {$error}\n");
    }
    exit(1);
}

$newCompanies = [];
foreach ($rows as $row) {
    if ((int) Db::scalar('SELECT COUNT(*) FROM companies WHERE name = ?', [$row['company']]) === 0) {
        $newCompanies[$row['company']] = true;
    }
}

printf(
    "検証 OK: イベント %d 件 / 開催回 %d 件 / 新規に作成する会社 %d 社\n",
    count($rows),
    array_sum(array_map(static fn (array $r): int => count($r['sessions']), $rows)),
    count($newCompanies)
);
foreach ($rows as $row) {
    printf(
        "  %-28s %-30s %s\n",
        mb_strimwidth($row['company'], 0, 28),
        mb_strimwidth($row['title'], 0, 30),
        $row['sessions'] === []
            ? '予約不要'
            : sprintf('%d 回 %s〜', count($row['sessions']), substr($row['sessions'][0]['starts_at'], 0, 16))
    );
}

if ($dryRun) {
    echo "\n--dry-run のため何も書き込んでいません。\n";
    exit(0);
}

// One transaction for the whole file: a failure part way through must not
// leave half a programme loaded.
$created = ['companies' => 0, 'events' => 0, 'sessions' => 0];

Db::transaction(static function () use ($rows, &$created): void {
    $companyIds = [];

    foreach ($rows as $row) {
        $name = $row['company'];
        if (!isset($companyIds[$name])) {
            $existing = Db::selectOne('SELECT id, area FROM companies WHERE name = ?', [$name]);
            if ($existing === null) {
                Db::execute(
                    'INSERT INTO companies (name, area, sort_order, is_published) VALUES (?, ?, ?, 1)',
                    [$name, $row['area'], 0]
                );
                $companyIds[$name] = Db::lastInsertId();
                $created['companies']++;
            } else {
                $companyIds[$name] = (int) $existing['id'];
                // Fill the area in when the sheet supplies one and the record
                // has none; never overwrite a value someone already chose.
                if ($row['area'] !== null && $existing['area'] === null) {
                    Db::execute('UPDATE companies SET area = ? WHERE id = ?', [$row['area'], $companyIds[$name]]);
                }
            }
        }

        Db::execute(
            'INSERT INTO events
               (company_id, title, description, venue, booking_required, external_url,
                max_party_size, sort_order, is_published)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $companyIds[$name], $row['title'], $row['description'], $row['venue'],
                $row['booking_required'] ? 1 : 0, $row['url'], $row['max_party'],
                0, $row['published'] ? 1 : 0,
            ]
        );
        $eventId = Db::lastInsertId();
        $created['events']++;

        foreach ($row['sessions'] as $session) {
            Db::execute(
                "INSERT INTO event_sessions (event_id, starts_at, ends_at, capacity, status)
                 VALUES (?, ?, ?, ?, 'open')",
                [$eventId, $session['starts_at'], $session['ends_at'], $session['capacity']]
            );
            $created['sessions']++;
        }
    }
});

printf(
    "\n取り込みました: 会社 %d 社 / イベント %d 件 / 開催回 %d 件\n",
    $created['companies'],
    $created['events'],
    $created['sessions']
);
