<?php

declare(strict_types=1);

/**
 * Regenerate docs/database.md from the live schema.
 *
 * Hand-written table definitions drift the moment someone adds a migration
 * and forgets the document, so the mechanical half - columns, types,
 * nullability, defaults, keys, foreign keys - is read out of
 * information_schema every time. What a table is FOR, and why a column looks
 * the way it does, cannot be introspected, so those notes live in the map
 * below and are the only part a person maintains.
 *
 * A column with no note is listed with an empty description rather than
 * silently omitted, and the script says how many are missing - so a new
 * migration shows up here as a gap to fill, not as a hole to overlook.
 *
 * Usage:
 *   php bin/schema_doc.php            write docs/database.md
 *   php bin/schema_doc.php --check    exit 1 if the file is out of date
 */

if (PHP_SAPI !== 'cli') {
    exit("This script is CLI-only.\n");
}

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Db;

$checkOnly = in_array('--check', array_slice($argv, 1), true);

/** What each table is for. Order here is the order in the document. */
$tableNotes = [
    'companies' => 'イベントを主催する企業。公開側では会社ごとにイベントをまとめて表示し、エリアで絞り込む。',
    'events' => '1 つの催し。開催回を複数持つのが基本だが、予約不要のイベントは開催回を持たない。',
    'event_sessions' => 'イベントの開催回。**座席の勘定はこの行のロックの下でのみ行う**（docs/design.md B章）。',
    'applicants' => 'メールアドレス 1 件につき 1 行。予約処理で最初にロックする親行であり、これが「同一人物の操作を直列化する」唯一の拠り所。',
    'bookings' => '予約。1 行が 1 予約で、人数は party_size が持つ（複数人でも行は増えない）。',
    'booking_attendees' => '予約に含まれる参加者。attendee_no 1 が予約者本人。',
    'booking_events' => '予約の状態遷移の監査ログ。「誰がいつキャンセルしたか」に答える。',
    'mail_queue' => '送信待ちメール（トランザクショナル・アウトボックス）。予約と同じトランザクションで積むので、ロールバックした予約のメールは残らない。',
    'admin_users' => '管理画面のアカウント。事務局（全社）と会社担当者（自社のみ）の 2 種類。',
    'schema_migrations' => '適用済みマイグレーションの記録。bin/migrate.php が管理する。',
];

/** Per-column notes. Only the ones worth explaining; the rest are self-evident. */
$columnNotes = [
    'companies.area' => 'エリア。値は URL に載せるため英字（東=east / 南=south / 北=north / 本館=main）。NULL は未設定で、エリア絞り込みには現れない。',
    'companies.sort_order' => '小さいほど先頭。同値なら id 順。',
    'companies.is_published' => '0 なら公開側に一切出ない（配下のイベントごと）。',

    'events.booking_required' => '0 なら「予約不要」。開催回を表示せず、申込も受け付けない（既存の開催回が残っていても拒否する）。',
    'events.external_url' => '開催企業のサイトなど。設定されていれば予約画面とイベント詳細に別タブリンクとして出る。http/https のみ。',
    'events.max_party_size' => '1 予約あたりの上限人数。既定 20 は bookings.party_size の上限と同じ。',
    'events.is_published' => '0 なら公開側に出ない。会社が非公開ならイベントも出ない。',

    'event_sessions.capacity' => '定員（人数）。予約 1 件で party_size 人ぶん消費する。',
    'event_sessions.confirmed_seats' => '**意図的な非正規化**。SUM(party_size) では「まだ存在しない行」をロックできず同時予約が両方通るため、この行を FOR UPDATE して直列化する。',
    'event_sessions.waitlist_counter' => 'キャンセル待ちの採番元。MAX(waitlist_seq)+1 では衝突するので親行に置く。',
    'event_sessions.status' => 'closed にすると公開側から消え、新規予約も受け付けない。既存予約は残る。',
    'event_sessions.session_date' => '生成列（STORED）。日付でのグループ表示・索引用。',

    'applicants.email' => 'utf8mb4_unicode_ci なので UNIQUE が大文字小文字を区別しない。保存前に小文字化・trim もしている。',

    'bookings.reference_code' => '利用者に見せる予約番号。AUTO_INCREMENT は欠番や件数が漏れるため独立した乱数（48bit）。',
    'bookings.email' => '申込時点のアドレスの写し。applicants への外部キーとは別に保持する。',
    'bookings.phone' => '当日連絡が取れる番号。予約に 1 つ。',
    'bookings.message' => '開催企業へのメッセージ（任意）。',
    'bookings.party_size' => '人数。1〜20（chk_bookings_party）。イベント側の max_party_size がさらに上限を絞る。',
    'bookings.waitlist_seq' => 'キャンセル待ちの受付順。**waitlisted のときだけ値を持つ**（不変条件(5)）。',
    'bookings.cancel_token_hash' => 'キャンセル用トークンの SHA-256。**生の値は保存しない**ので、DB が漏れてもキャンセル権限は渡らない。',
    'bookings.active_key' => '生成列（STORED）。cancelled のとき NULL になり、UNIQUE 内で NULL が重複を許される性質で「キャンセル後の再予約」を可能にしている。',

    'booking_attendees.attendee_no' => '1..party_size。1 が予約者本人。列名を position にしないのは MariaDB の関数名と衝突するため。',
    'booking_attendees.age' => '参加者ごとの年齢。年齢制限の確認はこの粒度で行う。',

    'booking_events.actor' => "誰の操作か。'applicant' / 'admin:ユーザー名' / 'system:auto_promote' など。",

    'mail_queue.status' => 'pending → sent。5 回失敗すると failed で滞留し、管理画面から再送できる。',
    'mail_queue.booking_id' => '関連する予約。外部キーは張っていない（予約が消えてもメール履歴は残す）。',

    'admin_users.role' => 'superadmin=事務局（全社）/ company=会社担当者（自社のみ）。company_id との対応は AdminUserRepository が強制する（MariaDB 11.8 が CHECK を受け付けないため）。',
    'admin_users.company_id' => 'role=company のとき必須、superadmin のとき NULL。',
    'admin_users.locked_until' => '10 回連続で失敗すると 15 分ロック。',
];

// ---------------------------------------------------------------------------

$schema = (string) Db::scalar('SELECT DATABASE()');

/** @return array<int, array<string, mixed>> */
$query = static fn (string $sql, array $params = []): array => Db::select($sql, $params);

$columns = [];
foreach ($query(
    'SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLUMN_KEY
     FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, ORDINAL_POSITION',
    [$schema]
) as $row) {
    $columns[(string) $row['TABLE_NAME']][] = $row;
}

$indexes = [];
foreach ($query(
    'SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME
     FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ?
     ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX',
    [$schema]
) as $row) {
    $name = (string) $row['INDEX_NAME'];
    $indexes[(string) $row['TABLE_NAME']][$name]['unique'] = (int) $row['NON_UNIQUE'] === 0;
    $indexes[(string) $row['TABLE_NAME']][$name]['columns'][] = (string) $row['COLUMN_NAME'];
}

$foreignKeys = [];
foreach ($query(
    'SELECT k.TABLE_NAME, k.CONSTRAINT_NAME, k.COLUMN_NAME,
            k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME,
            r.DELETE_RULE, r.UPDATE_RULE
     FROM information_schema.KEY_COLUMN_USAGE k
     JOIN information_schema.REFERENTIAL_CONSTRAINTS r
       ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
     WHERE k.TABLE_SCHEMA = ? AND k.REFERENCED_TABLE_NAME IS NOT NULL
     ORDER BY k.TABLE_NAME, k.CONSTRAINT_NAME',
    [$schema]
) as $row) {
    $foreignKeys[(string) $row['TABLE_NAME']][] = $row;
}

// Tables in the curated order first, then anything the map does not know about.
$ordered = array_keys($tableNotes);
foreach (array_keys($columns) as $table) {
    if (!in_array($table, $ordered, true)) {
        $ordered[] = $table;
    }
}

$missingNotes = 0;
$out = [];

$out[] = '# データベース設計';
$out[] = '';
$out[] = '**このファイルは `php bin/schema_doc.php` が実際のスキーマから生成します。直接編集しないでください。**';
$out[] = '列の型・NULL 可否・既定値・索引・外部キーは `information_schema` から読み出したものなので、';
$out[] = 'マイグレーションを適用したあとに再生成すれば必ず実物と一致します。説明文だけは';
$out[] = '`bin/schema_doc.php` 内の表に人手で書きます。';
$out[] = '';
$out[] = '設計判断の背景（なぜこの形なのか）は [design.md](design.md)、運用手順は [operations.md](operations.md) にあります。';
$out[] = '';

// --- relationships ---------------------------------------------------------
$out[] = '## テーブル関連図';
$out[] = '';
$out[] = '```';
$out[] = 'companies ──< events ──< event_sessions ──< bookings ──< booking_attendees';
$out[] = '    │                                          │';
$out[] = '    │                                          ├──< booking_events   (監査ログ)';
$out[] = '    │                                          │';
$out[] = '    │                        applicants ───────┘';
$out[] = '    │                                          │';
$out[] = '    └──< admin_users                           └┄┄> mail_queue  (外部キーなし)';
$out[] = '```';
$out[] = '';
$out[] = '`──<` は 1 対多、`┄┄>` は外部キーを張らない緩い参照です。';
$out[] = '';

// --- table of contents -----------------------------------------------------
$out[] = '## テーブル一覧';
$out[] = '';
$out[] = '| テーブル | 用途 |';
$out[] = '|---|---|';
foreach ($ordered as $table) {
    $note = $tableNotes[$table] ?? '';
    if ($note === '') {
        $missingNotes++;
    }
    $out[] = sprintf('| [`%s`](#%s) | %s |', $table, $table, $note);
}
$out[] = '';

// --- per table -------------------------------------------------------------
foreach ($ordered as $table) {
    $out[] = '---';
    $out[] = '';
    $out[] = "## {$table}";
    $out[] = '';
    if (($tableNotes[$table] ?? '') !== '') {
        $out[] = $tableNotes[$table];
        $out[] = '';
    }

    $out[] = '| 列 | 型 | NULL | 既定値 | 説明 |';
    $out[] = '|---|---|---|---|---|';
    foreach ($columns[$table] ?? [] as $column) {
        $name = (string) $column['COLUMN_NAME'];
        $type = (string) $column['COLUMN_TYPE'];
        $extra = (string) $column['EXTRA'];
        if (str_contains($extra, 'auto_increment')) {
            $type .= ' AI';
        }
        if (str_contains($extra, 'GENERATED')) {
            $type .= ' 生成列';
        }

        $default = $column['COLUMN_DEFAULT'];
        $defaultText = $default === null
            ? ((string) $column['IS_NULLABLE'] === 'YES' ? 'NULL' : '—')
            : (string) $default;
        if (str_contains($extra, 'on update')) {
            $defaultText .= '（更新時に現在時刻）';
        }

        $note = $columnNotes["{$table}.{$name}"] ?? '';
        $out[] = sprintf(
            '| `%s` | %s | %s | %s | %s |',
            $name,
            $type,
            (string) $column['IS_NULLABLE'] === 'YES' ? '可' : '不可',
            $defaultText,
            $note
        );
    }
    $out[] = '';

    $tableIndexes = $indexes[$table] ?? [];
    if ($tableIndexes !== []) {
        $out[] = '**索引**';
        $out[] = '';
        foreach ($tableIndexes as $name => $index) {
            $out[] = sprintf(
                '- `%s`%s — %s',
                $name,
                $index['unique'] ? '（UNIQUE）' : '',
                implode(', ', array_map(static fn (string $c): string => "`{$c}`", $index['columns']))
            );
        }
        $out[] = '';
    }

    $tableKeys = $foreignKeys[$table] ?? [];
    if ($tableKeys !== []) {
        $out[] = '**外部キー**';
        $out[] = '';
        foreach ($tableKeys as $key) {
            $out[] = sprintf(
                '- `%s` → `%s.%s`（ON DELETE %s / ON UPDATE %s）',
                (string) $key['COLUMN_NAME'],
                (string) $key['REFERENCED_TABLE_NAME'],
                (string) $key['REFERENCED_COLUMN_NAME'],
                (string) $key['DELETE_RULE'],
                (string) $key['UPDATE_RULE']
            );
        }
        $out[] = '';
    }
}

// --- invariants ------------------------------------------------------------
$out[] = '---';
$out[] = '';
$out[] = '## 守るべき不変条件';
$out[] = '';
$out[] = 'いずれも `php tests/test_invariants.php` が検査します（違反 0 行が正常）。';
$out[] = '';
$out[] = '1. `event_sessions.confirmed_seats` が、その回の confirmed な予約の `SUM(party_size)` と一致する';
$out[] = '2. `confirmed_seats <= capacity`（売り越しなし）';
$out[] = '3. 同一予約者が時間帯の重なる live な予約を 2 件持たない（会社をまたいで検査）';
$out[] = '4. `waitlist_seq` が開催回ごとに重複しない';
$out[] = '5. 状態と日時が整合する（cancelled なら cancelled_at がある、waitlisted 以外は waitlist_seq が NULL、など）';
$out[] = '';
$out[] = 'これらは DB 制約だけでは表現しきれず、アプリ側のトランザクション（`applicants` → `event_sessions` → `bookings` の固定順ロック）が支えています。詳細は [design.md の B 章](design.md#b-排他制御--このシステムの核心)。';
$out[] = '';

$markdown = implode("\n", $out);
$target = dirname(__DIR__) . '/docs/database.md';

if ($checkOnly) {
    $current = is_file($target) ? (string) file_get_contents($target) : '';
    if (trim($current) === trim($markdown)) {
        echo "docs/database.md is up to date.\n";
        exit(0);
    }
    fwrite(STDERR, "docs/database.md is out of date. Run: php bin/schema_doc.php\n");
    exit(1);
}

file_put_contents($target, $markdown . "\n");
printf("Wrote docs/database.md (%d tables)\n", count($ordered));
if ($missingNotes > 0) {
    printf("%d table(s) have no description yet - add them to \$tableNotes.\n", $missingNotes);
}
