# データベース設計

**このファイルは `php bin/schema_doc.php` が実際のスキーマから生成します。直接編集しないでください。**
列の型・NULL 可否・既定値・索引・外部キーは `information_schema` から読み出したものなので、
マイグレーションを適用したあとに再生成すれば必ず実物と一致します。説明文だけは
`bin/schema_doc.php` 内の表に人手で書きます。

設計判断の背景（なぜこの形なのか）は [design.md](design.md)、運用手順は [operations.md](operations.md) にあります。

## テーブル関連図

```
companies ──< events ──< event_sessions ──< bookings ──< booking_attendees
    │                                          │
    │                                          ├──< booking_events   (監査ログ)
    │                                          │
    │                        applicants ───────┘
    │                                          │
    └──< admin_users                           └┄┄> mail_queue  (外部キーなし)
```

`──<` は 1 対多、`┄┄>` は外部キーを張らない緩い参照です。

## テーブル一覧

| テーブル | 用途 |
|---|---|
| [`companies`](#companies) | イベントを主催する企業。公開側では会社ごとにイベントをまとめて表示し、エリアで絞り込む。 |
| [`events`](#events) | 1 つの催し。開催回を複数持つのが基本だが、予約不要のイベントは開催回を持たない。 |
| [`event_sessions`](#event_sessions) | イベントの開催回。**座席の勘定はこの行のロックの下でのみ行う**（docs/design.md B章）。 |
| [`applicants`](#applicants) | メールアドレス 1 件につき 1 行。予約処理で最初にロックする親行であり、これが「同一人物の操作を直列化する」唯一の拠り所。 |
| [`bookings`](#bookings) | 予約。1 行が 1 予約で、人数は party_size が持つ（複数人でも行は増えない）。 |
| [`booking_attendees`](#booking_attendees) | 予約に含まれる参加者。attendee_no 1 が予約者本人。 |
| [`booking_events`](#booking_events) | 予約の状態遷移の監査ログ。「誰がいつキャンセルしたか」に答える。 |
| [`mail_queue`](#mail_queue) | 送信待ちメール（トランザクショナル・アウトボックス）。予約と同じトランザクションで積むので、ロールバックした予約のメールは残らない。 |
| [`admin_users`](#admin_users) | 管理画面のアカウント。事務局（全社）と会社担当者（自社のみ）の 2 種類。 |
| [`schema_migrations`](#schema_migrations) | 適用済みマイグレーションの記録。bin/migrate.php が管理する。 |

---

## companies

イベントを主催する企業。公開側では会社ごとにイベントをまとめて表示し、エリアで絞り込む。

| 列 | 型 | NULL | 既定値 | 説明 |
|---|---|---|---|---|
| `id` | int(10) unsigned AI | 不可 | — |  |
| `name` | varchar(120) | 不可 | — |  |
| `name_kana` | varchar(120) | 可 | NULL |  |
| `area` | enum('east','south','north','main') | 可 | NULL | エリア。値は URL に載せるため英字（東=east / 南=south / 北=north / 本館=main）。NULL は未設定で、エリア絞り込みには現れない。 |
| `sort_order` | int(11) | 不可 | 0 | 小さいほど先頭。同値なら id 順。 |
| `is_published` | tinyint(1) | 不可 | 1 | 0 なら公開側に一切出ない（配下のイベントごと）。 |
| `created_at` | datetime | 不可 | current_timestamp() |  |
| `updated_at` | datetime | 不可 | current_timestamp()（更新時に現在時刻） |  |

**索引**

- `idx_companies_area` — `area`, `sort_order`, `id`
- `idx_companies_order` — `sort_order`, `id`
- `PRIMARY`（UNIQUE） — `id`
- `uq_companies_name`（UNIQUE） — `name`

---

## events

1 つの催し。開催回を複数持つのが基本だが、予約不要のイベントは開催回を持たない。

| 列 | 型 | NULL | 既定値 | 説明 |
|---|---|---|---|---|
| `id` | int(10) unsigned AI | 不可 | — |  |
| `company_id` | int(10) unsigned | 不可 | — |  |
| `title` | varchar(200) | 不可 | — |  |
| `description` | text | 可 | NULL |  |
| `venue` | varchar(200) | 可 | NULL |  |
| `booking_required` | tinyint(1) | 不可 | 1 | 0 なら「予約不要」。開催回を表示せず、申込も受け付けない（既存の開催回が残っていても拒否する）。 |
| `external_url` | varchar(500) | 可 | NULL | 開催企業のサイトなど。設定されていれば予約画面とイベント詳細に別タブリンクとして出る。http/https のみ。 |
| `max_party_size` | tinyint(3) unsigned | 不可 | 20 | 1 予約あたりの上限人数。既定 20 は bookings.party_size の上限と同じ。 |
| `sort_order` | int(11) | 不可 | 0 |  |
| `is_published` | tinyint(1) | 不可 | 1 | 0 なら公開側に出ない。会社が非公開ならイベントも出ない。 |
| `created_at` | datetime | 不可 | current_timestamp() |  |
| `updated_at` | datetime | 不可 | current_timestamp()（更新時に現在時刻） |  |

**索引**

- `idx_events_company` — `company_id`, `sort_order`, `id`
- `PRIMARY`（UNIQUE） — `id`

**外部キー**

- `company_id` → `companies.id`（ON DELETE RESTRICT / ON UPDATE CASCADE）

---

## event_sessions

イベントの開催回。**座席の勘定はこの行のロックの下でのみ行う**（docs/design.md B章）。

| 列 | 型 | NULL | 既定値 | 説明 |
|---|---|---|---|---|
| `id` | int(10) unsigned AI | 不可 | — |  |
| `event_id` | int(10) unsigned | 不可 | — |  |
| `starts_at` | datetime | 不可 | — |  |
| `ends_at` | datetime | 不可 | — |  |
| `capacity` | smallint(5) unsigned | 不可 | — | 定員（人数）。予約 1 件で party_size 人ぶん消費する。 |
| `confirmed_seats` | smallint(5) unsigned | 不可 | 0 | **意図的な非正規化**。SUM(party_size) では「まだ存在しない行」をロックできず同時予約が両方通るため、この行を FOR UPDATE して直列化する。 |
| `waitlist_counter` | int(10) unsigned | 不可 | 0 | キャンセル待ちの採番元。MAX(waitlist_seq)+1 では衝突するので親行に置く。 |
| `status` | enum('open','closed') | 不可 | 'open' | closed にすると公開側から消え、新規予約も受け付けない。既存予約は残る。 |
| `session_date` | date 生成列 | 可 | NULL | 生成列（STORED）。日付でのグループ表示・索引用。 |
| `created_at` | datetime | 不可 | current_timestamp() |  |
| `updated_at` | datetime | 不可 | current_timestamp()（更新時に現在時刻） |  |

**索引**

- `idx_sessions_date` — `session_date`, `starts_at`
- `idx_sessions_event_start` — `event_id`, `starts_at`
- `PRIMARY`（UNIQUE） — `id`
- `uq_sessions_event_start`（UNIQUE） — `event_id`, `starts_at`

**外部キー**

- `event_id` → `events.id`（ON DELETE RESTRICT / ON UPDATE CASCADE）

---

## applicants

メールアドレス 1 件につき 1 行。予約処理で最初にロックする親行であり、これが「同一人物の操作を直列化する」唯一の拠り所。

| 列 | 型 | NULL | 既定値 | 説明 |
|---|---|---|---|---|
| `id` | int(10) unsigned AI | 不可 | — |  |
| `email` | varchar(255) | 不可 | — | utf8mb4_unicode_ci なので UNIQUE が大文字小文字を区別しない。保存前に小文字化・trim もしている。 |
| `created_at` | datetime | 不可 | current_timestamp() |  |

**索引**

- `PRIMARY`（UNIQUE） — `id`
- `uq_applicants_email`（UNIQUE） — `email`

---

## bookings

予約。1 行が 1 予約で、人数は party_size が持つ（複数人でも行は増えない）。

| 列 | 型 | NULL | 既定値 | 説明 |
|---|---|---|---|---|
| `id` | bigint(20) unsigned AI | 不可 | — |  |
| `reference_code` | char(12) | 不可 | — | 利用者に見せる予約番号。AUTO_INCREMENT は欠番や件数が漏れるため独立した乱数（48bit）。 |
| `session_id` | int(10) unsigned | 不可 | — |  |
| `applicant_id` | int(10) unsigned | 不可 | — |  |
| `email` | varchar(255) | 不可 | — | 申込時点のアドレスの写し。applicants への外部キーとは別に保持する。 |
| `phone` | varchar(30) | 可 | NULL | 当日連絡が取れる番号。予約に 1 つ。 |
| `name` | varchar(100) | 不可 | — |  |
| `message` | text | 可 | NULL | 開催企業へのメッセージ（任意）。 |
| `party_size` | tinyint(3) unsigned | 不可 | 1 | 人数。1〜20（chk_bookings_party）。イベント側の max_party_size がさらに上限を絞る。 |
| `status` | enum('confirmed','waitlisted','cancelled') | 不可 | — |  |
| `waitlist_seq` | int(10) unsigned | 可 | NULL | キャンセル待ちの受付順。**waitlisted のときだけ値を持つ**（不変条件(5)）。 |
| `cancel_token_hash` | char(64) | 不可 | — | キャンセル用トークンの SHA-256。**生の値は保存しない**ので、DB が漏れてもキャンセル権限は渡らない。 |
| `created_at` | datetime | 不可 | current_timestamp() |  |
| `updated_at` | datetime | 不可 | current_timestamp()（更新時に現在時刻） |  |
| `confirmed_at` | datetime | 可 | NULL |  |
| `cancelled_at` | datetime | 可 | NULL |  |
| `active_key` | varchar(300) 生成列 | 可 | NULL | 生成列（STORED）。cancelled のとき NULL になり、UNIQUE 内で NULL が重複を許される性質で「キャンセル後の再予約」を可能にしている。 |

**索引**

- `idx_bookings_applicant_status` — `applicant_id`, `status`
- `idx_bookings_created` — `created_at`
- `idx_bookings_email` — `email`
- `idx_bookings_session_status` — `session_id`, `status`
- `PRIMARY`（UNIQUE） — `id`
- `uq_bookings_active`（UNIQUE） — `active_key`
- `uq_bookings_ref`（UNIQUE） — `reference_code`
- `uq_bookings_token`（UNIQUE） — `cancel_token_hash`

**外部キー**

- `applicant_id` → `applicants.id`（ON DELETE RESTRICT / ON UPDATE RESTRICT）
- `session_id` → `event_sessions.id`（ON DELETE RESTRICT / ON UPDATE RESTRICT）

---

## booking_attendees

予約に含まれる参加者。attendee_no 1 が予約者本人。

| 列 | 型 | NULL | 既定値 | 説明 |
|---|---|---|---|---|
| `id` | bigint(20) unsigned AI | 不可 | — |  |
| `booking_id` | bigint(20) unsigned | 不可 | — |  |
| `attendee_no` | tinyint(3) unsigned | 不可 | — | 1..party_size。1 が予約者本人。列名を position にしないのは MariaDB の関数名と衝突するため。 |
| `name` | varchar(100) | 不可 | — |  |
| `age` | smallint(5) unsigned | 可 | NULL | 参加者ごとの年齢。年齢制限の確認はこの粒度で行う。 |
| `created_at` | datetime | 不可 | current_timestamp() |  |

**索引**

- `idx_attendees_booking` — `booking_id`
- `PRIMARY`（UNIQUE） — `id`
- `uq_attendees_slot`（UNIQUE） — `booking_id`, `attendee_no`

**外部キー**

- `booking_id` → `bookings.id`（ON DELETE CASCADE / ON UPDATE RESTRICT）

---

## booking_events

予約の状態遷移の監査ログ。「誰がいつキャンセルしたか」に答える。

| 列 | 型 | NULL | 既定値 | 説明 |
|---|---|---|---|---|
| `id` | bigint(20) unsigned AI | 不可 | — |  |
| `booking_id` | bigint(20) unsigned | 不可 | — |  |
| `from_status` | varchar(20) | 可 | NULL |  |
| `to_status` | varchar(20) | 不可 | — |  |
| `actor` | varchar(60) | 不可 | — | 誰の操作か。'applicant' / 'admin:ユーザー名' / 'system:auto_promote' など。 |
| `note` | varchar(255) | 可 | NULL |  |
| `created_at` | datetime | 不可 | current_timestamp() |  |

**索引**

- `idx_bevents_booking` — `booking_id`, `id`
- `PRIMARY`（UNIQUE） — `id`

**外部キー**

- `booking_id` → `bookings.id`（ON DELETE CASCADE / ON UPDATE RESTRICT）

---

## mail_queue

送信待ちメール（トランザクショナル・アウトボックス）。予約と同じトランザクションで積むので、ロールバックした予約のメールは残らない。

| 列 | 型 | NULL | 既定値 | 説明 |
|---|---|---|---|---|
| `id` | bigint(20) unsigned AI | 不可 | — |  |
| `to_email` | varchar(255) | 不可 | — |  |
| `to_name` | varchar(100) | 可 | NULL |  |
| `subject` | varchar(255) | 不可 | — |  |
| `body` | mediumtext | 不可 | — |  |
| `status` | enum('pending','sent','failed') | 不可 | 'pending' | pending → sent。5 回失敗すると failed で滞留し、管理画面から再送できる。 |
| `attempts` | tinyint(3) unsigned | 不可 | 0 |  |
| `last_error` | varchar(500) | 可 | NULL |  |
| `booking_id` | bigint(20) unsigned | 可 | NULL | 関連する予約。外部キーは張っていない（予約が消えてもメール履歴は残す）。 |
| `created_at` | datetime | 不可 | current_timestamp() |  |
| `sent_at` | datetime | 可 | NULL |  |

**索引**

- `idx_mail_booking` — `booking_id`
- `idx_mail_status` — `status`, `id`
- `PRIMARY`（UNIQUE） — `id`

---

## admin_users

管理画面のアカウント。事務局（全社）と会社担当者（自社のみ）の 2 種類。

| 列 | 型 | NULL | 既定値 | 説明 |
|---|---|---|---|---|
| `id` | int(10) unsigned AI | 不可 | — |  |
| `username` | varchar(60) | 不可 | — |  |
| `password_hash` | varchar(255) | 不可 | — |  |
| `display_name` | varchar(100) | 不可 | — |  |
| `role` | enum('superadmin','company') | 不可 | 'superadmin' | superadmin=事務局（全社）/ company=会社担当者（自社のみ）。company_id との対応は AdminUserRepository が強制する（MariaDB 11.8 が CHECK を受け付けないため）。 |
| `company_id` | int(10) unsigned | 可 | NULL | role=company のとき必須、superadmin のとき NULL。 |
| `is_active` | tinyint(1) | 不可 | 1 |  |
| `failed_attempts` | smallint(5) unsigned | 不可 | 0 |  |
| `locked_until` | datetime | 可 | NULL | 10 回連続で失敗すると 15 分ロック。 |
| `last_login_at` | datetime | 可 | NULL |  |
| `created_at` | datetime | 不可 | current_timestamp() |  |

**索引**

- `idx_admin_users_company` — `company_id`
- `PRIMARY`（UNIQUE） — `id`
- `uq_admin_username`（UNIQUE） — `username`

**外部キー**

- `company_id` → `companies.id`（ON DELETE RESTRICT / ON UPDATE CASCADE）

---

## schema_migrations

適用済みマイグレーションの記録。bin/migrate.php が管理する。

| 列 | 型 | NULL | 既定値 | 説明 |
|---|---|---|---|---|
| `filename` | varchar(255) | 不可 | — |  |
| `applied_at` | datetime | 不可 | current_timestamp() |  |

**索引**

- `PRIMARY`（UNIQUE） — `filename`

---

## 守るべき不変条件

いずれも `php tests/test_invariants.php` が検査します（違反 0 行が正常）。

1. `event_sessions.confirmed_seats` が、その回の confirmed な予約の `SUM(party_size)` と一致する
2. `confirmed_seats <= capacity`（売り越しなし）
3. 同一予約者が時間帯の重なる live な予約を 2 件持たない（会社をまたいで検査）
4. `waitlist_seq` が開催回ごとに重複しない
5. 状態と日時が整合する（cancelled なら cancelled_at がある、waitlisted 以外は waitlist_seq が NULL、など）

これらは DB 制約だけでは表現しきれず、アプリ側のトランザクション（`applicants` → `event_sessions` → `bookings` の固定順ロック）が支えています。詳細は [design.md の B 章](design.md#b-排他制御--このシステムの核心)。

