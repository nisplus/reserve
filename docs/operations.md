# 運用手順

データの初期化と一括投入。スキーマそのものは [database.md](database.md)、設計判断の背景は [design.md](design.md) にあります。

Windows では `php` を `php.cmd` に読み替えてください（`$env:PHP_BIN` の設定が必要な場合があります）。

---

## 1. データの初期化

**どこまで消すかで手順が違います。** 消しすぎると戻せないので、まず下の表で選んでください。

| やりたいこと | コマンド | 残るもの |
|---|---|---|
| テスト予約だけ消す（**本番公開前の定番**） | `php bin/reset_bookings.php --yes` | 会社・イベント・開催回・管理アカウント |
| サンプルデータで作り直す | `php bin/migrate.php --fresh` → `php bin/seed.php` | なし（管理アカウントも再作成） |
| 何も入っていない状態にする | `php bin/migrate.php --fresh` | 空のテーブルのみ |

### 予約だけ消す

```
php bin/reset_bookings.php --dry-run     # 件数を確認するだけ
php bin/reset_bookings.php --yes         # 実行
```

`--yes` を付けない限り何も変更しません。

消えるのは `bookings` / `booking_attendees` / `booking_events` / `applicants` / `mail_queue` で、**同時に `event_sessions` の `confirmed_seats` と `waitlist_counter` を 0 に戻します**。

> ここが手作業でやってはいけない理由です。`DELETE FROM bookings` だけを実行すると座席カウンタが残り、誰も予約していないのに「満席」になります（不変条件(1) 違反）。このスクリプトは同一トランザクションでカウンタも戻し、最後に整合性を検査して結果を表示します。

保持したいものがあれば:

```
php bin/reset_bookings.php --yes --keep-applicants   # 申込者マスタを残す
php bin/reset_bookings.php --yes --keep-mail         # 送信済みメール履歴を残す
```

`--keep-mail` を付けた場合、`mail_queue.booking_id` は NULL に落とします（消えた予約を指したままにしないため）。

### サンプルイベントとテスト予約を消して、本番データを入れる

`bin/seed.php` が入れたサンプル（14社・56イベント・422開催回）とテスト予約をまとめて消し、**スキーマと事務局アカウントは残す**手順です。この状態から [イベントの一括投入](#2-イベント情報の一括投入) に進めます。

```sql
-- 実行前に必ずバックアップを取ってください:
--   mysqldump -u root -p booking > backup_$(date +%Y%m%d).sql

SET FOREIGN_KEY_CHECKS = 0;

TRUNCATE TABLE booking_attendees;
TRUNCATE TABLE booking_events;
TRUNCATE TABLE mail_queue;
TRUNCATE TABLE bookings;
TRUNCATE TABLE applicants;
TRUNCATE TABLE event_sessions;
TRUNCATE TABLE events;

-- 会社が消えると所属先を失うため、会社担当者アカウントも一緒に削除します。
-- 事務局アカウント（role='superadmin'）は残ります。
DELETE FROM admin_users WHERE role = 'company';

TRUNCATE TABLE companies;

SET FOREIGN_KEY_CHECKS = 1;
```

ファイルに保存して流す場合:

```bash
mysql -u root -p booking < wipe.sql
```

#### なぜこの順番と `FOREIGN_KEY_CHECKS = 0` が要るのか

- **子テーブルが先**。`FOREIGN_KEY_CHECKS = 0` の間は `ON DELETE CASCADE` が**働かない**ので、`booking_attendees` や `booking_events` を明示的に消さないと親だけ消えて孤児が残ります
- **`DELETE` ではなく `TRUNCATE`**。AUTO_INCREMENT が 1 に戻るので、投入し直した本番データの ID が 1 から始まります（検証済み）
- **`admin_users` の扱いが要注意**。`admin_users.company_id` は `companies` を参照しており、そのままだと `Cannot delete or update a parent row`（errno 1451）で止まります。かといって `FOREIGN_KEY_CHECKS = 0` のまま放置すると、**存在しない会社を指したアカウントが残ります**（実測で 2 件発生）。上の SQL はそれを避けるため会社担当者を先に削除しています
- **最後に必ず `SET FOREIGN_KEY_CHECKS = 1`**。戻し忘れると、そのセッションの間だけ整合性チェックが効きません

#### 実行後の確認

```sql
SELECT 'companies' t, COUNT(*) n FROM companies
UNION ALL SELECT 'events',         COUNT(*) FROM events
UNION ALL SELECT 'event_sessions', COUNT(*) FROM event_sessions
UNION ALL SELECT 'bookings',       COUNT(*) FROM bookings
UNION ALL SELECT 'admin_users',    COUNT(*) FROM admin_users;

-- 宙に浮いた参照が無いこと（0 件が正常）
SELECT COUNT(*) AS dangling
FROM admin_users u LEFT JOIN companies c ON c.id = u.company_id
WHERE u.company_id IS NOT NULL AND c.id IS NULL;
```

`admin_users` が 0 件になってしまった場合は、ログインできなくなる前に作り直してください。

```bash
php bin/create_admin.php admin <パスワード>
```

#### PHP のツールでも同じことができます

SQL を触りたくない場合は、こちらでも同じ結果になります（`--fresh` は全テーブルを作り直すので**事務局アカウントも消えます**。`seed.php` が新しい管理者を作り、パスワードを一度だけ表示します）。

```bash
php bin/migrate.php --fresh
php bin/seed.php --fresh   # サンプルも入れ直す場合
```

サンプルを入れずに空から始めたいなら `bin/seed.php` を実行せず、`bin/create_admin.php` で管理者だけ作ってください。

### 全部作り直す

```
php bin/migrate.php --fresh    # 全テーブルを DROP して再作成
php bin/seed.php               # サンプルデータ（14社 × 4イベント × 計422開催回）
```

`bin/seed.php` は**管理者アカウントを作り、パスワードを一度だけ表示します。** 控えてください。控え損ねたら:

```
php bin/create_admin.php admin <新しいパスワード>
```

### 実施後の確認

```
php tests/test_invariants.php     # 5 本すべて 0 行なら健全
```

---

## 2. イベント情報の一括投入

`bin/import_events.php` が CSV から会社・イベント・開催回をまとめて作ります。1 行が 1 イベントで、開催回は「初回開始・所要分・間隔分・回数・定員」から生成します（管理画面の一括生成と同じ考え方）。

### 手順

```
php bin/import_events.php --template > events.csv   # ひな形を出力
（Excel などで編集）
php bin/import_events.php events.csv --dry-run      # 検証だけ
php bin/import_events.php events.csv                # 投入
```

### 列

| 列 | 必須 | 内容 |
|---|---|---|
| 会社名 | ○ | 既存と同名なら再利用、なければ新規作成 |
| エリア | | `east` / `south` / `north` / `main`。空欄可 |
| イベント名 | ○ | 同じ会社に同名があると中止 |
| 説明 | | 改行可（セルを `"` で囲む） |
| 会場 | | |
| 外部URL | | `http://` または `https://` のみ |
| 予約不要 | | `1` なら予約を受け付けない。この場合、開催回の列は空にする |
| 上限人数 | | 1 予約あたりの上限。空欄なら 20 |
| 公開 | | 空欄なら公開。`0` で非公開 |
| 開始日時 | ○※ | 初回の開始。`2027-03-01 10:00` |
| 所要分 | ○※ | 1 回の長さ（5〜600） |
| 間隔分 | | 回と回の間の休憩。空欄なら 0 |
| 回数 | ○※ | 1〜20 |
| 定員 | ○※ | 1〜999 |

※ 予約不要のイベントでは空欄にします。

### 安全策

- **ファイル全体を検証してから書き込みます。** 40 行目に誤りがあれば 1 行も入りません
- 書き込みは**単一トランザクション**です。途中で失敗しても中途半端に残りません
- 既に同じ「会社名／イベント名」がある行は**エラーで中止**します。二重投入で重複が増えません
- 会社のエリアは、**未設定のときだけ** CSV の値で埋めます。既に設定済みの値は上書きしません
- Excel の「CSV UTF-8」形式で出力した BOM 付きファイルもそのまま読めます

### 不規則な時間割

CSV は「等間隔に N 回」だけを表現します。時間がばらばらの開催回は、投入後に管理画面の**イベント → 開催回 → 1件だけ作成**から追加してください。

### 投入例

```csv
会社名,エリア,イベント名,説明,会場,外部URL,予約不要,上限人数,公開,開始日時,所要分,間隔分,回数,定員
株式会社サンプル製作所,east,工場見学ツアー,ラインをご案内します。,本社工場 A棟,https://example.com/tour,,5,1,2027-03-01 10:00,45,15,6,20
株式会社サンプル製作所,east,常設展示,当日直接お越しください。,展示ホール,https://example.com/exhibit,1,,1,,,,,
```

上は 10:00 から 45 分の回を 15 分あけて 6 回（10:00 / 11:00 / 12:00 / 13:00 / 14:00 / 15:00）、定員 20 名・1 予約あたり 5 名まで。下は予約不要で開催回なし。

---

## 3. スキーマ資料の更新

マイグレーションを追加したら、テーブル定義書を再生成してください。

```
php bin/schema_doc.php           # docs/database.md を書き直す
php bin/schema_doc.php --check   # 最新でなければ終了コード 1
```

列の説明文は `bin/schema_doc.php` 内の `$columnNotes` に書きます（生成のたびに引き継がれます）。
