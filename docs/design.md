# 設計ドキュメント

このシステムがなぜこの形をしているかの記録。セットアップ手順と現在の進捗は [README.md](../README.md) にあります。

## 目次

- [解くべき問題](#解くべき問題)
- [確定要件](#確定要件)
- [A. データベース設計](#a-データベース設計)
- [B. 排他制御 — このシステムの核心](#b-排他制御--このシステムの核心)
- [C. アプリケーション構成](#c-アプリケーション構成)
- [D. 実装順序](#d-実装順序)
- [E. 検証方法](#e-検証方法)
- [F. 想定される落とし穴](#f-想定される落とし穴)

---

## 解くべき問題

14 社が主催する約 56 イベント（14 社 × 各 4 個）の参加申込を Web で受け付ける。1 イベントにつき 1 日 5〜10 回の開催枠があり、申込者はメールアドレス・氏名・参加人数を入力して 1 回ずつ申し込む。

中心的な問題は 2 つ。

1. **同一申込者が時間帯の重なる複数の回に申し込めないこと**（主催会社をまたいでも検出する）
2. **開催回ごとの定員を超えないこと**（超過分はキャンセル待ちとして受け付ける）

どちらも「複数人が同時に申し込んだとき」に壊れる類の問題です。しかも時間帯の重なりは範囲比較なので、DB の UNIQUE 制約では表現できません。MariaDB には PostgreSQL の `EXCLUDE` 制約も部分インデックスもないため、**アプリ側のトランザクションと行ロックで正しさを担保する**設計が全体の核になっています。

最終的な運用先は Linux のレンタルサーバーですが、開発は Windows でも行うため、移植性は最初から作り込んでいます（ファイル名の大文字小文字、パス区切り、Windows 依存 API の排除）。

## 確定要件

| 項目 | 決定 |
|---|---|
| 重複の意味 | 申込者が時間帯の重なる回に重複予約できない。会社をまたいでチェック |
| 申込フロー | 1 回ずつ個別に申込（カート形式ではない） |
| 定員 | 開催回ごとに設定。**単位は人数**（3 名申込 = 3 席消費） |
| 満席時 | キャンセル待ちとして登録。**管理者が手動で繰り上げ** |
| 管理画面 | フル（会社・イベント・開催回の CRUD、申込一覧、CSV 出力、パスワードログイン） |
| 本人操作 | トークン付き URL で予約内容の確認とキャンセル |
| メール | 送信処理は作る。開発中はファイル出力、設定 1 行で SMTP に切替 |
| 本番環境 | Linux レンタルサーバー |

---

## A. データベース設計

実体は [`db/migrations/001_init.sql`](../db/migrations/001_init.sql) にあり、各テーブルの意図はそこのコメントに書いてあります。ここには「なぜそう決めたか」だけを残します。

### 文字コード

**`utf8mb4` / `utf8mb4_unicode_ci` で全面統一**し、CREATE DATABASE・CREATE TABLE・DSN のすべてで明示します。

- MariaDB で `utf8` と書くと `utf8mb3`（3 バイト）になり、絵文字や `𠮷` のような CJK 拡張 B が壊れます。この綴りはコードに一切登場させません
- `utf8mb4_ja_0900_as_cs` は **MySQL 8 専用**で MariaDB には存在しません。`utf8mb4_uca1400_*` も 10.10 以降です
- `utf8mb4_general_ci` は日本語で不適切な同一視をするため避け、`utf8mb4_unicode_ci` を使います
- 開発機の XAMPP は `character_set_server` が未設定（= latin1）でした。charset を明示しないテーブルを作ると日本語が `?????` に潰れます

`applicants.email` を `utf8mb4_unicode_ci` に置いたことで UNIQUE 制約が大文字小文字を区別しなくなり、重複判定に都合よく働きます。アプリ側でも `mb_strtolower` + `trim` で正規化してから保存しています。

### 日時の持ち方

**`starts_at` / `ends_at` の 2 本の DATETIME** で持ちます。`date` + `start_time` + `end_time` の分割形式は採りませんでした。

1. 重なり判定 `a.starts_at < b.ends_at AND b.starts_at < a.ends_at` が 1 行で書けてインデックスが効く。分割形式だと `CONCAT` や `ADDTIME` が必要になり、関数適用でインデックスが死にます
2. 日をまたぐ枠（22:00〜翌 01:00）を表現できる。今回は 1 日内で収まりますが、夜間枠が後から出てくると分割形式は破綻します
3. 日単位のグループ表示は生成列 `session_date DATE GENERATED ALWAYS AS (DATE(starts_at)) PERSISTENT` で解決できます

**`TIMESTAMP` は使いません。** セッションの `time_zone` に応じて暗黙変換され、2038 年上限もあります。**JST の壁時計をそのまま DATETIME に格納**し、PHP 側は `Asia/Tokyo`、DB 接続時に `SET time_zone = '+09:00'` を明示します。日本は DST 非採用なので曖昧な時刻は発生しません。

なお XAMPP は `mysql.time_zone*` テーブルを投入していないため `CONVERT_TZ()` は NULL を返します。使わない設計にしてあります。

**境界の扱い**: `<` を使うので **10:00–11:00 と 11:00–12:00 は「重ならない」**。隣接する枠の連続申込は許可されます。これは意図的な仕様です。

### `active_key` 生成列のトリック

「キャンセル済みを除いて `(session_id, applicant_id)` が一意」を DB 制約で表現したいのですが、MariaDB には部分インデックスもフィルタ付き UNIQUE もありません。

```sql
active_key VARCHAR(300) GENERATED ALWAYS AS (
  CASE WHEN status = 'cancelled' THEN NULL
       ELSE CONCAT(session_id, ':', applicant_id) END
) STORED,
UNIQUE KEY uq_bookings_active (active_key)
```

**UNIQUE インデックス内で NULL は何個でも許される**という InnoDB の仕様を使っています。キャンセルすると `active_key` が NULL になるので、同じ回への再申込が通ります。

`VIRTUAL` ではなく `STORED` にすること。300 文字 × 4 バイト = 1200 バイトで、インデックスの 3072 バイト上限内に収まります。キーワードは `STORED` と綴ること — MariaDB は `PERSISTENT` と `STORED` の両方を受け付けますが、**MySQL 8 は `STORED` のみ**です。

### `confirmed_seats` は意図的な非正規化

`event_sessions.confirmed_seats` と `waitlist_counter` は集計で求められる値ですが、**あえてカラムとして持っています**。理由は [排他制御](#なぜ非正規化カウンタが必要か) を参照。

### `chk_sessions_seats` の副作用

`CHECK (confirmed_seats <= capacity)` を張ってあり、MariaDB 10.2 以降では実際に強制されます。アプリのロジックにバグがあっても売り越しは DB が止めます。

ただし副作用として、**管理画面で定員を現在の確定席数より下に変更すると CHECK 違反で失敗します**。管理者が意味の分からないエラーに遭遇しないよう、「現在の確定席数（N 名）未満には変更できません」というバリデーションとメッセージを必ず用意してください。

### `reference_code`

AUTO_INCREMENT はロールバックで欠番が出るため、利用者に見せる予約番号は `bin2hex(random_bytes(6))` 由来の `reference_code` を別に振ります。内部 ID を URL に露出させない意味もあります。

---

## B. 排他制御 — このシステムの核心

**ここが間違っていると、平常時は動くのに繁忙時だけ壊れます。** 変更するときは [E-3 のテストシナリオ](#e-3-テストシナリオ)を必ず通してください。

### 分離レベルは READ COMMITTED

MariaDB の既定は REPEATABLE READ ですが、これは本件に**明確に不適**です。

- RR では通常の `SELECT` がトランザクション開始時のスナップショットを読みます。つまり**他のトランザクションが直前に COMMIT した予約が見えず**、重複チェックがそれを素通りさせます
- RR ではギャップロックが発生し、範囲検索を含む本件では余計なデッドロックを誘発します

READ COMMITTED なら各文が常に最新のコミット済みデータを読むため、行ロックを取った直後の SELECT は必ず最新状態を見ます。**これが以下すべての正しさの根拠です。**

接続ごとに [`src/Core/Db.php`](../src/Core/Db.php) が設定しています。

```sql
SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED;
SET SESSION innodb_lock_wait_timeout = 5;   -- 既定の 50 秒は Web には長すぎる
SET SESSION sql_mode = 'STRICT_TRANS_TABLES,...';
SET SESSION time_zone = '+09:00';
```

### ロック順序は固定

**必ず `applicants` → `event_sessions` → `bookings` の順**でロックを取ります。申込・キャンセル・繰り上げの全操作でこの順を守れば、循環待ちが構成できないためデッドロックが起きません。

**新しい操作を追加するときも、この順序を崩さないでください。**

### 申込トランザクション

```php
// 0) トランザクション外で申込者行を確保（ロック保持時間を最小化）
$pdo->prepare("INSERT INTO applicants (email) VALUES (?)
               ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)")->execute([$email]);
$applicantId = (int) $pdo->lastInsertId();

$pdo->beginTransaction();
```

```sql
-- 1) 申込者ゲート：同一メールの申込を完全に直列化する
SELECT id FROM applicants WHERE id = :applicant_id FOR UPDATE;

-- 2) 時間帯の重なりチェック（このロックの内側なので安全）
--    waitlisted も対象に含める。含めないと、繰り上げ時に重複が発生する
SELECT b.id, s.starts_at, s.ends_at, e.title
FROM bookings b
JOIN event_sessions s ON s.id = b.session_id
JOIN events e         ON e.id = s.event_id
WHERE b.applicant_id = :applicant_id
  AND b.status IN ('confirmed','waitlisted')
  AND s.starts_at < :new_ends_at
  AND :new_starts_at < s.ends_at
LIMIT 1;
-- 1 件でもあれば ROLLBACK。会社をまたいでも検出される

-- 3) 対象開催回の親行をロック：座席計算を直列化する
SELECT capacity, confirmed_seats, waitlist_counter, status
FROM event_sessions WHERE id = :session_id FOR UPDATE;

-- 4a) 空きがある場合
UPDATE event_sessions SET confirmed_seats = confirmed_seats + :party_size
WHERE id = :session_id;
--     status='confirmed', confirmed_at=NOW()

-- 4b) 満席の場合（confirmed_seats + party_size > capacity）
UPDATE event_sessions SET waitlist_counter = waitlist_counter + 1
WHERE id = :session_id;
--     status='waitlisted', waitlist_seq = 新しい waitlist_counter

-- 5) INSERT INTO bookings
-- 6) INSERT INTO booking_events（監査ログ）
-- 7) INSERT INTO mail_queue（トランザクショナル・アウトボックス）
```

```php
$pdo->commit();
// commit 後にベストエフォートで即時送信を試みる。失敗してもキューに残る
```

メールをトランザクション内でキューに積むのが重要です。ロールバックされた予約について「確定しました」というメールが飛ぶ事故を構造的に防げます。

### なぜ非正規化カウンタが必要か

`SELECT SUM(party_size) FROM bookings WHERE session_id = ? AND status = 'confirmed' FOR UPDATE` では**不十分**です。

READ COMMITTED にはギャップロックがないため、**まだ存在しない行（これから INSERT される予約）はロックできません**。2 つのトランザクションが同時に「残り 1 席」を見て、両方が INSERT できてしまいます。

`event_sessions` の**親行 1 行**を `FOR UPDATE` することで、その開催回に対する座席操作が確実に 1 つずつ直列化されます。カウンタはそのロックの内側でしか更新されないので、常に整合します。

`waitlist_counter` を親行に置いているのも同じ理由です。`MAX(waitlist_seq) + 1` では採番が衝突します。

### なぜ申込者行のロックが必要か

時間帯の重なりは範囲比較なので、`bookings` に対する行ロックでは表現できません（存在しない行は守れない、という同じ問題です）。

**「1 人の申込者」という粒度の唯一の親行**を `applicants` に作り、そこをロックすることで、同一メールアドレスからの同時申込を直列化しています。これは範囲制約をアプリ側で安全に実装するための定石です。

代替案の `GET_LOCK()` も MariaDB で使えますが、**非トランザクショナルで COMMIT / ROLLBACK では解放されません**。`finally` での解放漏れが即バグになるため、コミット・ロールバックで自動解放される行ロック方式を採っています。

### リトライ

SQLSTATE `40001`（デッドロック）と `1205`（ロック待ちタイムアウト）を捕捉し、指数バックオフ + ジッタで最大 3 回リトライします（[`Db::transaction()`](../src/Core/Db.php)）。3 回失敗したら「混み合っています」を返します。

ロック順序を守っていればデッドロックはほぼ起きませんが、外部キーによる暗黙ロックで稀に発生するため必須です。

### キャンセル（本人・トークン経由）

ロック順序を守るため、**先に非ロック SELECT で `session_id` / `applicant_id` を引いてから**、順にロックします。

```sql
-- 0) 非ロックで対象特定
SELECT id, session_id, applicant_id, status, party_size
FROM bookings WHERE cancel_token_hash = :hash;

-- BEGIN
-- 1) SELECT id FROM applicants WHERE id = :applicant_id FOR UPDATE
-- 2) SELECT confirmed_seats FROM event_sessions WHERE id = :session_id FOR UPDATE
-- 3) 再取得して状態を再検証
SELECT status, party_size FROM bookings WHERE id = :booking_id FOR UPDATE;
--    既に 'cancelled' なら冪等に成功扱いで終了（座席は戻さない）
-- 4) confirmed だった場合のみ座席を返す
--    UPDATE event_sessions SET confirmed_seats = confirmed_seats - :party_size
--    UPDATE bookings SET status='cancelled', cancelled_at=NOW(), waitlist_seq=NULL
-- 5) booking_events, mail_queue（管理者宛「空きが出ました」通知）
-- COMMIT
```

**ステップ 3 の再検証を省略すると、同時キャンセル 2 件で `confirmed_seats` が 2 回減算されます。** `SMALLINT UNSIGNED` なのでアンダーフローして 65000 台の巨大な値になります。必ず入れてください。

### 繰り上げ（管理者が手動実行）

同じロック順序で、`waitlist_seq` 昇順の候補に対して:

1. `applicants` → `event_sessions` → `bookings` の順にロック
2. `status = 'waitlisted'` であることを再検証
3. `confirmed_seats + party_size <= capacity` を確認
4. **重なりチェックを再実行**（待機中に本人が他の予約を入れている可能性がある）
5. `confirmed` に更新して座席加算
6. `booking_events` / `mail_queue`（本人宛「繰り上がりました」）

設定 `waitlist.auto_promote` は用意しますが**既定は false**（手動）です。キャンセル発生時は管理画面に「繰り上げ候補あり」バッジを出し、管理者宛に通知メールを積みます。

残席 2 のときに先頭候補が 3 名だった場合は詰まったままにし、管理者が候補一覧から個別に選んで繰り上げます。自動で飛ばすと不公平になるため、判断は人に委ねる方針です。

### 状態遷移

```
(新規) ──空きあり──> confirmed ──本人/管理者キャンセル──> cancelled (終端)
   └───満席────> waitlisted ─┬─管理者繰り上げ────> confirmed
                              └─本人/管理者キャンセル─> cancelled (終端)
```

`cancelled` は終端です。再申込は**新しい行**を作ります（`active_key` が NULL になるので UNIQUE に抵触しません）。

---

## C. アプリケーション構成

### ディレクトリ構成

```
public/          ドキュメントルート。ここだけが HTTP から見える
  index.php      フロントコントローラ兼 php -S 用ルータ
  .htaccess      本番 Apache 用の書き換えルール
src/
  Core/          Config Db Router Request Response View Csrf
                 SessionManager Flash Validator ErrorHandler Auth
  Domain/        BookingStatus(enum) SessionStatus(enum) TimeRange
  Repository/    Company Event EventSession Booking Applicant
                 AdminUser MailQueue
  Service/       BookingService WaitlistService CancellationService
                 CsvExporter TokenService
  Mail/          MailerInterface FileMailer SmtpMailer MailMessage
                 MailerFactory MailDispatcher Template/
  Exception/     DuplicateBooking SessionFull Validation NotFound
  Http/Controller/  Pub/ (Event Booking Manage)
                    Admin/ (Auth Company Event Session Booking Export)
templates/       layouts/ partials/ pub/ admin/
config/          config.sample.php（コミット） / config.php（対象外）
db/migrations/
storage/         mail/ logs/ sessions/（対象外）
bin/             migrate.php seed.php create_admin.php send_mail.php
                 request.php concurrency_test.php
tests/           test_overlap.php test_capacity.php test_invariants.php
                 test_autoload_case.php
```

`public/` だけをドキュメントルートにすることで、`config.php`・`storage/`・`src/` が **HTTP から絶対に読めなくなります**。`php -S -t public` がこれを自然に実現します。

本番 Apache 用には `public/.htaccess` にフロントコントローラへの rewrite を置き、DocumentRoot を `public/` に向けられない共用サーバー向けにルート `.htaccess` も用意する予定です。

### オートローダ

Composer を使わないため、[`bootstrap.php`](../bootstrap.php) に PSR-4 相当を 10 行で実装しています。

**本番が Linux なのでファイル名の大文字小文字が致命的です。** NTFS は区別しないため、`BookingService` を `bookingservice.php` に置いてもローカルでは動き、デプロイして初めて壊れます。`tests/test_autoload_case.php` で「宣言されたクラス名 == ファイル名の basename」を全ファイル走査して照合します。

同様に、パス連結は `DIRECTORY_SEPARATOR` か `/` に統一し、`\` をハードコードしません。

### ルーティング

[`public/index.php`](../public/index.php) の先頭に内蔵サーバー用の静的ファイル分岐を置き、CSS 1 枚のためにアプリ全体を起動しないようにしています。

ルータ（[`src/Core/Router.php`](../src/Core/Router.php)）は「メソッド + パスパターン」を正規表現化する 60 行程度のものです。`{id}` → `(?P<id>[0-9]+)`、`{token}` → `(?P<token>[0-9a-f]{64})`、`{ref}` → 12 文字の英数字。

ミドルウェアはルート定義に `['auth' => true]` を付ける簡易実装で足ります。管理画面 7 画面のためにそれ以上の仕組みを持ち込む理由がありません。

### 画面一覧

**公開側**

| メソッド・パス | 画面 | 備考 |
|---|---|---|
| `GET /` | イベント一覧 | **会社ごとにグループ化**。1 クエリで JOIN 取得しアプリ側でグルーピング（N+1 回避） |
| `GET /events/{id}` | 開催回選択 | 日付ごとに枠を並べ、各枠に「残り N 名」「満席（キャンセル待ち可）」 |
| `GET /sessions/{id}/apply` | 申込フォーム | メール・氏名・参加人数。CSRF トークン埋込 |
| `POST /sessions/{id}/confirm` | 確認 | 満席なら「キャンセル待ちで申し込む」と明示。残席は参考値である旨を表示 |
| `POST /bookings` | 確定 | 上記トランザクション。成功で 303 リダイレクト（**PRG パターン**で二重送信防止） |
| `GET /bookings/done/{ref}` | 完了 | **トークンは画面に出さずメールのみ**（肩越しに見られる対策） |
| `GET /manage/{token}` | 予約確認 | 内容と状態（確定 / キャンセル待ち N 番目）を表示 |
| `POST /manage/{token}/cancel` | キャンセル実行 | CSRF + 確認ステップ |

**管理側**（`/admin` 配下、全て認証必須）

ログイン／ログアウト、ダッシュボード（総申込数・満席の回・繰り上げ候補あり）、会社 CRUD、イベント CRUD（会社で絞込）、開催回 CRUD、申込一覧（会社／イベント／回／状態／メールで絞込・ページング）、繰り上げ、管理者キャンセル、CSV 出力（絞込条件を引き継ぐ）、mail_queue 確認・再送。

**開催回の一括生成フォーム**（開始時刻・所要分・間隔・回数・定員 → 5〜10 枠を一気に作成）は必須です。これが無いと 56 イベント分の登録が現実的でありません。

### テンプレート

素の PHP テンプレート。ヘルパは [`src/helpers.php`](../src/helpers.php) の `e()` ほか数個だけです。

```php
function e(mixed $v): string {
    return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
```

`?? ''` は必須です。**PHP 8.2 では `htmlspecialchars(null)` が deprecated** で、NULL 許容カラムを表示するたびに警告が出ます。`ENT_SUBSTITUTE` も必須で、不正な UTF-8 が来たときに文字列全体が空になる事故を防ぎます。

イベント説明文は **HTML を一切許可せず**、改行のみ `nl2br(e($text))` で反映します（`enl()`）。サニタイザを自作するより安全です。

### セキュリティ

- **PDO**: `ERRMODE_EXCEPTION`、`EMULATE_PREPARES => false`（ネイティブプリペアド）、`DEFAULT_FETCH_MODE => FETCH_ASSOC`、`STRINGIFY_FETCHES => false`。エミュレーション無効時は `LIMIT ?` に `PARAM_INT` でバインドすること。ORDER BY のカラム名はプレースホルダにできないので**ホワイトリスト照合**
- **接続先は `localhost` ではなく `127.0.0.1`**。Windows で名前解決が IPv6 の `::1` に向いて遅延・失敗することがあります。なお DB ユーザーは `@'localhost'` と `@'127.0.0.1'` の両方を作ってください。サーバー側は接続元 IP を逆引きするため、127.0.0.1 で接続しても `localhost` として認証されることがあります
- **CSRF**: セッションに 32 バイト乱数、全 POST に `_token`、`hash_equals()` で照合。ログイン成功時にローテート
- **管理パスワード**: `password_hash($pw, PASSWORD_DEFAULT, ['cost' => 12])`（bcrypt）。`password_verify` + `password_needs_rehash` で自動再ハッシュ。10 回失敗で 15 分ロック。ユーザー名の存在有無で応答時間もメッセージも変えない
- **キャンセルトークン**: `bin2hex(random_bytes(32))`（64 文字）を URL に載せ、DB には `hash('sha256', $raw)` のみ保存。**DB が漏れてもキャンセル権限は渡りません**
- **セッション**: `storage/sessions` に保存、`use_strict_mode=1`、httponly、samesite=Lax、ログイン直後に `session_regenerate_id(true)`。管理画面はアイドル 30 分 / 絶対 8 時間でタイムアウト
- **メールヘッダインジェクション**: 宛先・件名から `\r` `\n` を除去。件名は `mb_encode_mimeheader($subject, 'UTF-8', 'B')`
- **レート制限**: 公開申込 POST に IP 単位の簡易制限（1 分 10 件超で 429）

### メール送信

**PHP の `mail()` は使いません。** Windows 版は `SMTP` / `smtp_port` の ini しか持たず SMTP AUTH も STARTTLS も非対応で、Linux でも sendmail 依存になります。

`SmtpMailer` を `stream_socket_client` + STARTTLS で自作します（openssl があれば 150 行程度）。日本語ヘッダの RFC 2047 符号化は `mb_encode_mimeheader()` が担当するので、危険な部分はほとんどありません。

開発中は `FileMailer` が `storage/mail/*.eml` に書き出します。設定 `mail.transport` を `'file'` / `'smtp'` で切り替えます。

---

## D. 実装順序

各段階の終わりで必ず動作確認できるように並べてあります。**現在どこまで進んでいるかは [README.md](../README.md#進捗) を見てください。**

| 段階 | 内容 | この段階で動くようになること |
|---|---|---|
| 0. 環境 | php.ini / DB とユーザー作成 / 起動スクリプト | `/_diag` が全項目 OK になる |
| 1. 骨格 | オートローダ・Config・ErrorHandler・Db・Router・View | ルーティングが動き、404 と開発用エラーページが出る。日本語が化けない |
| 2. スキーマ | `001_init.sql`、`bin/migrate.php`、`bin/seed.php` | 14 社 × 4 イベント × 5〜10 回が投入され、DB クライアントで確認できる |
| 3. 公開・参照系 | Repository 群、イベント一覧、開催回選択 | カタログを閲覧でき、**データモデルの妥当性を目視検証**できる |
| **4. 申込コア** ★最大の山 | `BookingService`（二段ロック + 重複判定 + 定員/キャンセル待ち）、フォーム→確認→確定→完了（PRG）、`TokenService` | 申込ができ、**重複と売り越しが拒否される**。mail_queue に行が積まれる |
| 5. 本人キャンセル | `/manage/{token}`、`CancellationService` | トークン URL で確認とキャンセルができ、座席が正しく戻る |
| 6. メール | `FileMailer` / `SmtpMailer` / `bin/send_mail.php` | `.eml` が生成され、メールクライアントで日本語が正しく開ける |
| 7. 管理 CRUD | 認証、会社／イベント／開催回 CRUD、**一括生成フォーム** | ブラウザからデータ整備が完結する |
| 8. 管理・運用系 | 申込一覧、繰り上げ、CSV 出力、mail_queue 画面 | 運用に必要な機能が揃い、一旦「使える」状態 |
| 9. 堅牢化 | 競合テスト、不変条件チェック、レート制限、`.htaccess` | E 章の全シナリオがグリーン |

**段階 3 まででデータモデルを確定させてから段階 4 に着手すること。**

---

## E. 検証方法

### E-1. 競合テストは HTTP 経由では不可能

**Windows の `php -S` は 1 リクエストずつ逐次処理します。** `PHP_CLI_SERVER_WORKERS` は fork ベースで POSIX 専用のため無視されます。

ブラウザで何タブ開いても、curl を並列に叩いても、**リクエストは直列化され競合は絶対に再現しません**。「テストしたら通った」という偽の安心が最も危険なので、必ず CLI で検証してください。

（Linux / macOS では `PHP_CLI_SERVER_WORKERS=4 ./serve.sh` が効きますが、それでも下記の CLI ハーネスのほうが確実です。）

### E-2. CLI 競合ハーネス

`bin/concurrency_test.php` は**コントローラを通さず `BookingService` を直接呼ぶ**ワーカーです。

PHP のプロセス起動には 50〜150ms かかるため、単純に 20 個起動しても順番に処理されて競合しません。各ワーカーは以下の**バリア同期**を実装します。

1. PDO 接続とプリペアドを済ませる（コストの高い準備を先に終わらせる）
2. `--start-at` の絶対時刻まで `usleep` でスピンウェイト
3. 一斉に発火

Windows（PowerShell）からの一斉起動:

```powershell
$script  = "bin\concurrency_test.php"
$startAt = [Math]::Floor(([DateTimeOffset]::UtcNow).ToUnixTimeMilliseconds() / 1000) + 5
$jobs = 1..20 | ForEach-Object {
    Start-Job -ScriptBlock { param($script, $i, $startAt)
        & .\php.cmd $script "--email=u$i@example.test" "--session=123" "--party=1" "--start-at=$startAt"
    } -ArgumentList $script, $_, $startAt
}
Receive-Job -Job $jobs -Wait -AutoRemoveJob
```

Linux / macOS:

```bash
START_AT=$(( $(date +%s) + 5 ))
for i in $(seq 1 20); do
  ./php.sh bin/concurrency_test.php \
    --email="u$i@example.test" --session=123 --party=1 --start-at=$START_AT &
done
wait
```

`Start-Job` は 20 個で数秒かかるため、`--start-at` の猶予は 5 秒以上取ってください。

### E-3. テストシナリオ

| # | シナリオ | 期待結果 |
|---|---|---|
| 1 | 定員 10、20 ワーカーが別々のメールで `party=1` を同時申込 | confirmed が**ちょうど 10**、waitlisted が 10、`confirmed_seats = 10` |
| 2 | 定員 10、20 ワーカーが `party=3` | confirmed は**3 件（計 9 席）**、`confirmed_seats <= 10` |
| 3 | **同一メール**、時間帯が重なる 10 個の別セッションへ同時申込 | 成功**1 件のみ**、残り 9 件は重複エラー |
| 4 | 同一メール・同一セッションへ 10 並列 | 成功 1 件。すり抜けた分は `uq_bookings_active` が SQLSTATE 23000 を返し、それが利用者向けメッセージに正しく変換される |
| 5 | 確定 → キャンセル → 同じセッションへ再申込 | 成功する（`active_key` の NULL 化が機能） |
| 6 | 同一予約へのキャンセル 2 並列 | `confirmed_seats` の減算が**ちょうど 1 回**。アンダーフローしない |
| 7 | 隣接枠（10:00–10:45 と 10:45–11:30）を同一メールで申込 | **両方成功**（`<` による境界処理の確認） |
| 8 | キャンセル → 管理者繰り上げ 2 並列 | 二重昇格が起きず `confirmed_seats <= capacity` |

シードデータの各イベントは 1 番目と 2 番目の枠が隙間なく連続しているので、シナリオ 7 はそのまま試せます。定員 2 の枠も各イベントに 1 つあるので、満席とキャンセル待ちの確認が数クリックで済みます。

### E-4. 不変条件チェック

`tests/test_invariants.php` に以下を入れ、各テストの後に**全て 0 行**であることを検証します。

```sql
-- (1) カウンタと実データの乖離
SELECT s.id, s.confirmed_seats, COALESCE(SUM(b.party_size), 0) AS actual
FROM event_sessions s
LEFT JOIN bookings b ON b.session_id = s.id AND b.status = 'confirmed'
GROUP BY s.id, s.confirmed_seats
HAVING s.confirmed_seats <> actual;

-- (2) 定員超過
SELECT id, capacity, confirmed_seats FROM event_sessions WHERE confirmed_seats > capacity;

-- (3) 要件そのもの: 同一申込者の時間帯重複（会社をまたいで検出）
SELECT b1.applicant_id, b1.id AS b1_id, b2.id AS b2_id
FROM bookings b1
JOIN bookings b2 ON b2.applicant_id = b1.applicant_id AND b2.id > b1.id
JOIN event_sessions s1 ON s1.id = b1.session_id
JOIN event_sessions s2 ON s2.id = b2.session_id
WHERE b1.status IN ('confirmed','waitlisted')
  AND b2.status IN ('confirmed','waitlisted')
  AND s1.starts_at < s2.ends_at AND s2.starts_at < s1.ends_at;

-- (4) キャンセル待ち番号の重複
SELECT session_id, waitlist_seq, COUNT(*) FROM bookings WHERE status = 'waitlisted'
GROUP BY session_id, waitlist_seq HAVING COUNT(*) > 1;

-- (5) 状態と日時の整合
SELECT id FROM bookings
WHERE (status = 'cancelled' AND cancelled_at IS NULL)
   OR (status = 'confirmed' AND confirmed_at IS NULL)
   OR (status <> 'waitlisted' AND waitlist_seq IS NOT NULL);
```

**(3) は要件そのものの直接検証です。** これがグリーンなら重複チェックは正しく動いています。

### E-5. シードデータ

[`bin/seed.php`](../bin/seed.php) は `mt_srand(20260820)` の固定シードで、どのマシンでも同じ ID・同じ時刻のデータを再現します。DB をマシン間で持ち運ぶ必要はありません。

データは見栄えではなくテストのために形を決めてあります。

- 全社が同じ日・重なる時間帯に開催するので、会社をまたぐ重複ルールに検出対象がある（実測 12,479 組）
- 各社の 4 つ目のイベントだけ 30 分ずらしてあり、他と半端に重なる
- 各イベントの 1 番目と 2 番目の枠は隙間なく連続（境界ケース。重複と判定されてはいけない）
- 各イベントに定員 2 の枠が 1 つ

PHP で実装しているのは、PowerShell 5.1 に `<` 入力リダイレクトがなく `mysql.exe ... < file.sql` がパーサエラーになるためです。

### E-6. 手動確認チェックリスト

1. トップで 14 社が会社ごとにまとまって表示される
2. 開催回選択で残席が正しい
3. 申込 → `storage/mail/*.eml` 生成 → メールクライアントで件名・本文の日本語が正常
4. メール内トークン URL → 内容表示 → キャンセル → 残席が +N される
5. 定員 2 の枠を埋めてから 3 人目 → 「キャンセル待ち 1 番目」と表示
6. 管理画面で繰り上げ → confirmed に変わり本人宛メールが生成される
7. CSV を **Excel で開いて日本語が化けない**、`=` 始まりのセルが数式にならない
8. ブラウザバックで確認画面に戻って再送信 → PRG により二重登録されない

---

## F. 想定される落とし穴

### PHP 8.2

- **動的プロパティが deprecated。** 全クラスでプロパティを型付き宣言する（`readonly` も積極利用）
- **`htmlspecialchars(null)` が deprecated。** `e()` の `?? ''` が必須
- `"${var}"` は deprecated → `"{$var}"`。`utf8_encode` / `utf8_decode` も使わない
- `enum BookingStatus: string` を DB の ENUM に対応付けている。PDO からは文字列で来るので `BookingStatus::from()` で変換する

### MariaDB

- **`utf8mb4_ja_0900_as_cs` は MySQL 8 専用**で MariaDB には無い。`utf8mb4_uca1400_*` も 10.10 以降。`utf8mb4_unicode_ci` を使う
- **`utf8` = `utf8mb3`。** 必ず `utf8mb4` と綴る
- **`character_set_server` が latin1 の環境がある。** CREATE DATABASE / CREATE TABLE の両方で明示し、DSN にも付ける
- **`sql_mode` に `STRICT_TRANS_TABLES` が無い環境がある。** このままだと `VARCHAR(100)` に 150 文字を入れても警告だけで**黙って切り捨て**られ、`party_size` の範囲外も丸められる。接続ごとの `SET SESSION sql_mode` で必ず有効化する
- **`SKIP LOCKED` は 10.6 以降。** `FOR UPDATE WAIT n` / `NOWAIT` は 10.3 以降なので使える
- **EXCLUDE 制約・部分インデックス・フィルタ付き UNIQUE が無い。** だから `active_key` 生成列トリックとアプリ側の重なりチェックが必要
- **CHECK 制約は 10.2 以降で実際に強制される。** 定員の引き下げが失敗し得る点に注意（[前述](#chk_sessions_seats-の副作用)）
- **MySQL 8 でも動くように書く。** 生成列は `STORED`（`PERSISTENT` は MariaDB 方言）。分離レベルの確認は `@@transaction_isolation` を先に試す（MySQL 8 は `tx_isolation` を削除、MariaDB は 11.1 まで新名称なし）。CHECK 違反のエラー番号は MariaDB 4025 / MySQL 8 は 3819（`Db::isCheckViolation` は両対応）。CHECK の強制は MySQL では 8.0.16 以降

### Linux 本番への移植

- **ファイル名の大文字小文字。** NTFS では通るが Linux で壊れる。`tests/test_autoload_case.php` で常時チェック
- パス区切りは `DIRECTORY_SEPARATOR` か `/` に統一。`\` をハードコードしない
- `storage/` に Web サーバーユーザーの書き込み権限があるか、`config/` `storage/` `src/` が HTTP から見えないかを本番でも確認する
- 共用サーバーで DocumentRoot を `public/` に向けられない場合に備え、ルート `.htaccess` も用意する
- 改行コードは `.gitattributes` で LF に統一済み

### Windows 開発環境

- **`extension_dir` にダブルクォートが要る場合がある。** パスに空白や `(` が含まれると、無しでは ini パーサが構文エラーを出して PHP が起動しない。コマンドラインの `-d extension_dir=...` はこの場合まったく機能しない
- **`php -S` は完全逐次。** 競合テストは必ず CLI 並列プロセスで
- **PowerShell 5.1 に `<` 入力リダイレクトが無い。** `mysql.exe ... < schema.sql` はパーサエラー。`bin/migrate.php` から実行する
- **コンソール文字化け。** PowerShell 5.1 の既定出力は CP932。CLI 検証時は `chcp 65001` と `[Console]::OutputEncoding = [Text.Encoding]::UTF8`。DB の目視確認は HeidiSQL / A5:SQL が確実
- **古い PHP を誤って呼ぶ事故。** XAMPP には 7.4 が同梱されており、`enum` も `str_starts_with` も無いので失敗の理由が分かりにくい。常に `php.cmd` 経由で呼ぶ
- **XAMPP の Apache に PHP 8.2 は載せられない。** httpd 2.4.46 は VC15 ビルド、PHP 8.2 は VS16 ビルドでランタイムが混在する。開発は `php -S` を使う
- **mysqld を強制終了しないこと。** システムテーブル（Aria エンジン）が破損して `GRANT` が通らなくなることがある。その場合は `REPAIR TABLE mysql.db;` で修復できる
- **`.eml` は CRLF 必須。** `FileMailer` は明示的に `\r\n` を連結する

### 文字化け

- **PHP ファイルは UTF-8 BOM なしで保存。** BOM があると `headers already sent` になる。VS Code の `files.encoding` は `utf8`（`utf8bom` ではない）
- `header('Content-Type: text/html; charset=UTF-8')` と `<meta charset="utf-8">` の両方
- フォームは `accept-charset="UTF-8"`、受信値は `mb_check_encoding($v, 'UTF-8')` で検証し不正なら拒否（[`Request::clean()`](../src/Core/Request.php)）
- 文字数バリデーションは `mb_strlen`（`strlen` はバイト数）。MariaDB の `VARCHAR(n)` は文字数単位なので一致する
- **CSV と Excel**: UTF-8 のまま出すと Excel が CP932 と誤認して化ける。**UTF-8 BOM（`\xEF\xBB\xBF`）を先頭に付与**し、`fputcsv($fh, $row, ',', '"', '\\', "\r\n")` で CRLF を出す（`eol` 引数は PHP 8.2 以降に存在）
- **CSV インジェクション**: `=` `+` `-` `@`、タブ、CR で始まるセルは先頭に `'` を付ける。氏名やイベント名に入り得る

---

## 主要ファイル

| ファイル | 役割 |
|---|---|
| `src/Service/BookingService.php` | READ COMMITTED + 二段ロック、重複判定、定員/キャンセル待ち分岐。**このシステムの正しさが集中する唯一の場所** |
| [`db/migrations/001_init.sql`](../db/migrations/001_init.sql) | utf8mb4 指定、`active_key` 生成列 UNIQUE、座席カウンタ、CHECK 制約 |
| [`src/Core/Db.php`](../src/Core/Db.php) | 接続時の `sql_mode` / `time_zone` / 分離レベル / ロックタイムアウト上書きと、リトライ付きトランザクション |
| `bin/concurrency_test.php` | バリア同期付き競合ワーカー。`php -S` が逐次実行のため**競合検証はこれ以外の手段がない** |
| `src/Service/CancellationService.php` | ロック後の状態再検証（省略すると `confirmed_seats` がアンダーフローする） |
