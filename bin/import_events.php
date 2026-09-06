<?php

declare(strict_types=1);

/**
 * Bulk-load companies, events and their sessions from one CSV.
 *
 * A row describes an event and some of its sessions. Sessions can be written
 * two ways:
 *
 *   generated  開始日時 + 所要分 + 間隔分 + 回数   a run of equal slots
 *   explicit   開始日時 + 終了日時                one slot, exactly as written
 *
 * Generated slots are the common case - most of these events are a run of
 * equal-length tours through one day - but a timetable with a long slot before
 * lunch and short ones after cannot be expressed that way, and having to fix
 * that up in the admin screen afterwards defeats the point of the file. So a
 * row may instead give the two ends of one slot, and rows sharing 会社名 +
 * イベント名 describe the same event: the first carries its details, the rest
 * add more 開催回. An event can mix the two styles.
 *
 * 終了日時 accepts a bare time (10:45) as well as a full date and time, taken
 * on the start's date. Not a shorthand for its own sake: a bare time parsed as
 * a datetime would silently mean today, which is exactly the mistake that
 * would otherwise reach the database looking plausible.
 *
 * The column is optional, so sheets written against the earlier format load
 * unchanged.
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

/** Every sheet must carry these, in any order. */
const COLUMNS = [
    '会社名', 'エリア', 'イベント名', '説明', '会場', '外部URL',
    '予約不要', '上限人数', '公開',
    '開始日時', '所要分', '間隔分', '回数', '定員',
];

/**
 * Read when present. Optional rather than required so a sheet prepared against
 * the earlier format still loads - the operator who has one already should not
 * have to add an empty column to it.
 */
const OPTIONAL_COLUMNS = ['終了日時'];

/** Column order for --template, with 終了日時 beside the start it pairs with. */
const TEMPLATE_COLUMNS = [
    '会社名', 'エリア', 'イベント名', '説明', '会場', '外部URL',
    '予約不要', '上限人数', '公開',
    '開始日時', '終了日時', '所要分', '間隔分', '回数', '定員',
];

/**
 * Details that belong to the event rather than to one 開催回, and the field
 * each lands in. Any row of a group may supply them; two rows may not supply
 * the same one differently.
 */
const EVENT_ATTRIBUTES = [
    'エリア'   => 'area',
    '説明'     => 'description',
    '会場'     => 'venue',
    '外部URL'  => 'url',
    '予約不要' => 'booking_required',
    '上限人数' => 'max_party',
    '公開'     => 'published',
];

/**
 * Fold the differences between two spellings of a company name that are
 * typing accidents rather than different companies: full-width vs half-width,
 * half-width katakana, and whitespace anywhere in the name.
 *
 * Only used to catch mistakes - the database is still keyed on the name as
 * written, and nothing here rewrites what the operator typed.
 */
function normalise_company(string $name): string
{
    // asKV: full-width alphanumerics and spaces to half-width, half-width
    // katakana to full-width, voiced marks combined.
    $folded = mb_convert_kana($name, 'asKV');
    $folded = preg_replace('/[\s\x{3000}]+/u', '', $folded) ?? $folded;
    return mb_strtolower($folded);
}

/** Levenshtein distance in characters. PHP's own counts bytes, so 1 kanji reads as 3. */
function name_distance(string $a, string $b): int
{
    $x = mb_str_split($a);
    $y = mb_str_split($b);
    $n = count($x);
    $m = count($y);

    if ($n === 0 || $m === 0) {
        return max($n, $m);
    }

    $previous = range(0, $m);
    for ($i = 1; $i <= $n; $i++) {
        $current = [$i];
        for ($j = 1; $j <= $m; $j++) {
            $current[$j] = min(
                $previous[$j] + 1,                                  // deletion
                $current[$j - 1] + 1,                               // insertion
                $previous[$j - 1] + ($x[$i - 1] === $y[$j - 1] ? 0 : 1)
            );
        }
        $previous = $current;
    }

    return $previous[$m];
}

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
    $write = static function (array $row) use ($out): void {
        fputcsv($out, $row, ',', '"', '\\', "\r\n");
    };

    $write(TEMPLATE_COLUMNS);

    // 1) Generated: six 45-minute slots, 15 minutes apart, from 10:00.
    $write([
        '株式会社サンプル製作所', 'east', '工場見学ツアー',
        "普段は入れない製造ラインをご案内します。\n動きやすい服装でお越しください。",
        '本社工場 A棟', 'https://example.com/tour', '', '5', '1',
        '2027-03-01 10:00', '', '45', '15', '6', '20',
    ]);

    // 2) Explicit: three slots of different lengths and capacities, one per
    //    row. Only 会社名 and イベント名 repeat - they are what ties the rows
    //    together; everything else is left to the first row.
    $write([
        '株式会社サンプル製作所', 'east', '手づくり体験教室',
        '刻印入りのキーホルダーを作ります。', '研修棟 2F', '', '', '4', '1',
        '2027-03-01 10:00', '10:45', '', '', '', '12',
    ]);
    $write([
        '株式会社サンプル製作所', '', '手づくり体験教室', '', '', '', '', '', '',
        '2027-03-01 11:30', '13:00', '', '', '', '12',
    ]);
    $write([
        '株式会社サンプル製作所', '', '手づくり体験教室', '', '', '', '', '', '',
        '2027-03-01 14:00', '2027-03-01 14:30', '', '', '', '8',
    ]);

    // 3) 予約不要: no schedule columns at all.
    $write([
        '株式会社サンプル製作所', 'east', '常設展示（予約不要）',
        '当日直接お越しください。', '展示ホール', 'https://example.com/exhibit', '1', '', '1',
        '', '', '', '', '', '',
    ]);
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
    fwrite(STDERR, '任意の列: ' . implode(', ', OPTIONAL_COLUMNS) . "\n");
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

    // Seeded with the optional columns so a sheet that omits them reads as
    // blank rather than warning on every row.
    $row = array_fill_keys(OPTIONAL_COLUMNS, '');
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

    // Sessions. A 予約不要 event legitimately has none; otherwise the row is
    // either a generator rule or one explicit slot, never both.
    $sessions = [];
    $hasStart     = $row['開始日時'] !== '';
    $hasEnd       = $row['終了日時'] !== '';
    $hasGenerator = $row['所要分'] !== '' || $row['間隔分'] !== '' || $row['回数'] !== '';

    if ($noBooking && ($hasStart || $hasEnd)) {
        $problem('予約不要のイベントに開催回は指定できません');
    } elseif (!$noBooking && !$hasStart) {
        $problem('開始日時が空です（予約不要にするなら「予約不要」を 1 にしてください）');
    } elseif ($hasStart) {
        $start    = date_create_immutable(str_replace('/', '-', $row['開始日時']));
        $capacity = (int) $row['定員'];

        if ($start === false) {
            $problem("開始日時を解釈できません（{$row['開始日時']}）。例: 2027-03-01 10:00");
        }
        if ($capacity < 1 || $capacity > 999) {
            $problem('定員は 1〜999 です');
        }

        if ($hasEnd && $hasGenerator) {
            $problem(
                '終了日時と、所要分・間隔分・回数は同時に指定できません。'
                . '1 回だけの開催回なら終了日時を、等間隔の連続開催なら所要分・回数を残してください'
            );
        } elseif ($hasEnd) {
            // A bare 10:45 means that time on the start's date. Handing it to
            // the date parser as-is would give today's date, which is both
            // wrong and plausible enough to survive review.
            $end = preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $row['終了日時']) === 1 && $start !== false
                ? date_create_immutable($start->format('Y-m-d') . ' ' . $row['終了日時'])
                : date_create_immutable(str_replace('/', '-', $row['終了日時']));

            if ($end === false) {
                $problem("終了日時を解釈できません（{$row['終了日時']}）。例: 2027-03-01 10:45 または 10:45");
            } elseif ($start !== false && $end <= $start) {
                $problem(sprintf(
                    '終了日時は開始日時より後にしてください（%s 〜 %s）',
                    $start->format('Y-m-d H:i'),
                    $end->format('Y-m-d H:i')
                ));
            } elseif ($start !== false && $capacity >= 1) {
                $sessions[] = [
                    // Kept so a clash between rows can name the row that
                    // caused it rather than the one that opened the event.
                    'line'      => $lineNo,
                    'starts_at' => $start->format('Y-m-d H:i:s'),
                    'ends_at'   => $end->format('Y-m-d H:i:s'),
                    'capacity'  => $capacity,
                ];
            }
        } elseif ($row['所要分'] === '') {
            $problem('所要分が空です。1 回だけの開催回なら終了日時を、等間隔の連続開催なら所要分と回数を入れてください');
        } else {
            $duration = (int) $row['所要分'];
            $gap      = $row['間隔分'] === '' ? 0 : (int) $row['間隔分'];
            $count    = $row['回数'] === '' ? 1 : (int) $row['回数'];

            if ($duration < 5 || $duration > 600) {
                $problem('所要分は 5〜600 です');
            }
            if ($gap < 0 || $gap > 600) {
                $problem('間隔分は 0〜600 です');
            }
            if ($count < 1 || $count > 20) {
                $problem('回数は 1〜20 です');
            }

            if ($start !== false && $duration >= 5 && $count >= 1 && $capacity >= 1) {
                for ($i = 0; $i < $count; $i++) {
                    $slotStart = $start->modify('+' . $i * ($duration + $gap) . ' minutes');
                    $sessions[] = [
                        'line'      => $lineNo,
                        'starts_at' => $slotStart->format('Y-m-d H:i:s'),
                        'ends_at'   => $slotStart->modify("+{$duration} minutes")->format('Y-m-d H:i:s'),
                        'capacity'  => $capacity,
                    ];
                }
            }
        }
    }

    $rows[] = [
        'line'      => $lineNo,
        'raw'       => $row, // to tell "left blank" from "set to the same thing"
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

/*
 * Rows sharing 会社名 + イベント名 are one event. Every row contributes its
 * sessions, which is what makes an irregular timetable expressible: one row
 * per slot, each with its own 終了日時 and 定員.
 *
 * 説明・会場・外部URL・予約不要・上限人数・公開・エリア belong to the event,
 * not to a slot, so they need saying once - on whichever row the operator
 * happened to write them, since which row is "first" is an accident of sort
 * order and not something they should have to think about. Blank means "not
 * stated here". Two rows stating the same detail differently is refused: the
 * file is then saying two things and either answer would be a guess.
 */
$events = [];
foreach ($rows as $row) {
    $key = $row['company'] . "\0" . $row['title'];

    if (!isset($events[$key])) {
        $row['first_line'] = $row['line'];
        // Which row supplied each detail, so a later clash can name it.
        $row['stated_on'] = [];
        foreach (EVENT_ATTRIBUTES as $column => $field) {
            if ($row['raw'][$column] !== '') {
                $row['stated_on'][$column] = $row['line'];
            }
        }
        $events[$key] = $row;
        continue;
    }

    foreach (EVENT_ATTRIBUTES as $column => $field) {
        if ($row['raw'][$column] === '') {
            continue;
        }

        if (!isset($events[$key]['stated_on'][$column])) {
            $events[$key][$field] = $row[$field];
            $events[$key]['stated_on'][$column] = $row['line'];
            continue;
        }

        if ($row[$field] !== $events[$key][$field]) {
            $errors[] = sprintf(
                '%d 行目: 「%s／%s」の「%s」が %d 行目と食い違っています。'
                . 'イベント共通の項目なので、どちらか一方だけに書いてください',
                $row['line'],
                $row['company'],
                $row['title'],
                $column,
                $events[$key]['stated_on'][$column]
            );
        }
    }

    $events[$key]['sessions'] = array_merge($events[$key]['sessions'], $row['sessions']);
}

foreach ($events as $key => $event) {
    // Reachable only across rows: a single 予約不要 row with a schedule is
    // already refused above, but a 予約不要 first row followed by a slot row
    // that leaves 予約不要 blank would otherwise slip through.
    if (!$event['booking_required'] && $event['sessions'] !== []) {
        $errors[] = sprintf(
            '%d 行目: 「%s／%s」は予約不要なのに開催回が指定されています',
            $event['first_line'],
            $event['company'],
            $event['title']
        );
    }

    // The admin screen refuses two 開催回 starting at the same moment; the
    // file has to as well, or a copied row becomes a duplicate slot.
    $starts = [];
    foreach ($event['sessions'] as $session) {
        if (isset($starts[$session['starts_at']])) {
            $errors[] = sprintf(
                '%d 行目: 「%s／%s」の開始日時 %s は %d 行目と重複しています',
                $session['line'],
                $event['company'],
                $event['title'],
                substr($session['starts_at'], 0, 16),
                $starts[$session['starts_at']]
            );
            continue;
        }
        $starts[$session['starts_at']] = $session['line'];
    }

    // Rows may be written in any order; the listing and the summary read
    // better in time order, and nothing downstream depends on file order.
    usort(
        $events[$key]['sessions'],
        static fn (array $a, array $b): int => strcmp($a['starts_at'], $b['starts_at'])
    );

    $exists = (int) Db::scalar(
        'SELECT COUNT(*) FROM events e JOIN companies c ON c.id = e.company_id
         WHERE c.name = ? AND e.title = ?',
        [$event['company'], $event['title']]
    );
    if ($exists > 0) {
        $errors[] = "{$event['first_line']} 行目: 「{$event['company']}／{$event['title']}」は既に登録されています";
    }
}

/*
 * Companies are matched on the name exactly as written, so a name that differs
 * by one character quietly becomes a second company - and the only sign of it
 * used to be the count of new companies going up by one.
 *
 * Three things guard that now. Spellings that differ only in width or spacing
 * are refused outright, because nobody means those as different companies.
 * Names within a couple of characters of an existing one are reported as a
 * warning, because sometimes they really are different companies. And every
 * company that will be created is listed by name, so the operator confirms the
 * list rather than a number.
 */
$existing = Db::select('SELECT name, area FROM companies');
$existingByFolded = [];
foreach ($existing as $company) {
    $existingByFolded[normalise_company((string) $company['name'])] = $company;
}

$warnings = [];
$newCompanies = [];
$areaClaims = [];

foreach ($events as $event) {
    $name = $event['company'];

    // The file disagreeing with itself about one company's area.
    if ($event['area'] !== null) {
        if (isset($areaClaims[$name]) && $areaClaims[$name]['area'] !== $event['area']) {
            $errors[] = sprintf(
                '%d 行目: 会社「%s」のエリアが %d 行目と食い違っています（%s / %s）',
                $event['first_line'],
                $name,
                $areaClaims[$name]['line'],
                $areaClaims[$name]['area'],
                $event['area']
            );
        }
        $areaClaims[$name] ??= ['area' => $event['area'], 'line' => $event['first_line']];
    }

    $match = Db::selectOne('SELECT name, area FROM companies WHERE name = ?', [$name]);
    if ($match !== null) {
        // Silently ignoring this used to be the documented behaviour. It still
        // is - an area chosen in the admin screen outranks a spreadsheet - but
        // going unmentioned is how the two drift apart unnoticed.
        if ($event['area'] !== null && $match['area'] !== null && $event['area'] !== $match['area']) {
            $warnings[] = sprintf(
                '%d 行目: 会社「%s」のエリアは登録済みの %s のままにします（CSV の %s は反映しません）',
                $event['first_line'],
                $name,
                (string) $match['area'],
                $event['area']
            );
        }
        continue;
    }

    $folded = normalise_company($name);
    if (isset($existingByFolded[$folded])) {
        $errors[] = sprintf(
            '%d 行目: 会社名「%s」は登録済みの「%s」と空白や全角半角の違いしかありません。'
            . 'このままでは別の会社として登録されてしまいます。登録済みの表記に合わせてください',
            $event['first_line'],
            $name,
            (string) $existingByFolded[$folded]['name']
        );
        continue;
    }

    $newCompanies[$name] ??= $event['first_line'];
}

foreach ($newCompanies as $name => $line) {
    $folded = normalise_company($name);
    foreach ($existingByFolded as $otherFolded => $company) {
        $distance = name_distance($folded, $otherFolded);
        if ($distance > 0 && $distance <= 2) {
            $warnings[] = sprintf(
                '%d 行目: 新しく作る会社「%s」は登録済みの「%s」と %d 文字違いです。'
                . '別の会社として登録されます',
                $line,
                $name,
                (string) $company['name'],
                $distance
            );
        }
    }
}

if ($errors !== []) {
    fwrite(STDERR, "取り込みを中止しました。以下を直してください:\n");
    foreach ($errors as $error) {
        fwrite(STDERR, "  {$error}\n");
    }
    exit(1);
}

printf(
    "検証 OK: イベント %d 件（%d 行）/ 開催回 %d 件\n",
    count($events),
    count($rows),
    array_sum(array_map(static fn (array $e): int => count($e['sessions']), $events))
);

if ($newCompanies !== []) {
    printf("\n新しく作る会社 %d 社:\n", count($newCompanies));
    foreach ($newCompanies as $name => $line) {
        printf("  %d 行目  %s\n", $line, $name);
    }
    echo "  ※ 登録済みの会社に足すつもりの行がここにあれば、会社名の表記が違っています。\n";
}

if ($warnings !== []) {
    echo "\n確認してください:\n";
    foreach ($warnings as $warning) {
        echo "  {$warning}\n";
    }
}

echo "\n";
foreach ($events as $event) {
    // The times are the point of the file, so print them rather than a count:
    // a slot on the wrong day is the mistake --dry-run exists to catch, and it
    // is invisible in "3 回".
    $slots = array_map(
        static fn (array $s): string => substr($s['starts_at'], 5, 11) . '〜' . substr($s['ends_at'], 11, 5),
        array_slice($event['sessions'], 0, 4)
    );
    if (count($event['sessions']) > 4) {
        $slots[] = sprintf('ほか %d 件', count($event['sessions']) - 4);
    }

    printf(
        "  %-28s %-30s %s\n",
        mb_strimwidth($event['company'], 0, 28),
        mb_strimwidth($event['title'], 0, 30),
        $event['sessions'] === []
            ? '予約不要'
            : sprintf('%d 回  %s', count($event['sessions']), implode('  ', $slots))
    );
}

if ($dryRun) {
    echo "\n--dry-run のため何も書き込んでいません。\n";
    exit(0);
}

// One transaction for the whole file: a failure part way through must not
// leave half a programme loaded.
$created = ['companies' => 0, 'events' => 0, 'sessions' => 0];

Db::transaction(static function () use ($events, &$created): void {
    $companyIds = [];

    foreach ($events as $row) {
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
