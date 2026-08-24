# イベント予約システム

14 社が主催するイベントの参加申込を受け付ける Web アプリケーション。素の PHP 8.2 + MariaDB / MySQL で、外部ライブラリへの依存はありません（Composer 不要）。

設計判断とその理由は **[docs/design.md](docs/design.md)** にまとまっています。このファイルはセットアップと運用手順だけを扱います。

## 何ができるか

- 会社ごとにグループ化したイベント一覧と、開催回（1 イベントあたり 1 日 5〜10 回）の選択
- メールアドレス・氏名・参加人数での申込
- **同一申込者が時間帯の重なる回に二重申込できない**（主催会社をまたいでも検出）
- 開催回ごとの定員管理（単位は人数）と、満席時のキャンセル待ち
- トークン付き URL による本人での予約確認・キャンセル
- 管理画面（会社／イベント／開催回の CRUD、申込一覧、繰り上げ、CSV 出力）

## 必要なもの

| | 要件 | 備考 |
|---|---|---|
| PHP | **8.2 以上** | 拡張: `mbstring` `openssl` `pdo_mysql` `fileinfo` |
| DB | **MariaDB 10.2+ / MySQL 8.0+** | `CHECK` 制約と生成列を使うため |
| その他 | なし | Composer も Node.js も使いません |

---

## セットアップ

### 1. コードを取得

```
git clone <リポジトリURL> booking
cd booking
```

### 2. PHP を用意する

<details>
<summary><b>Windows</b></summary>

すでに PHP 8.2 があるなら飛ばしてください。無い場合:

1. [windows.php.net](https://windows.php.net/download/) から **VS16 x64 Thread Safe** の 8.2 系 zip を取得し、任意の場所（例 `C:\php`）に展開
2. `php.ini-development` を同じ場所に `php.ini` としてコピーし、以下を設定

```ini
; パスに空白や括弧が含まれる場合、ダブルクォートは必須。
; 無いと ini パーサが構文エラーで起動しません。
extension_dir = "C:\php\ext"

date.timezone   = Asia/Tokyo
default_charset = "UTF-8"

extension=mbstring
extension=openssl
extension=pdo_mysql
extension=fileinfo

error_reporting = E_ALL
display_errors  = On
log_errors      = On

session.use_strict_mode = 1
session.cookie_httponly = 1
session.cookie_samesite = Lax
session.use_only_cookies = 1
```

3. `php.cmd` は `%PHP_BIN%` → `C:\Program Files (ext)\php\php.exe` → `C:\php\php.exe` → PATH の順に探します。別の場所に置いた場合は環境変数で指定してください。

```
set "PHP_BIN=D:\tools\php\php.exe"
```

確認:
```
php.cmd -v
php.cmd -m
```
</details>

<details>
<summary><b>Linux (Debian / Ubuntu)</b></summary>

```
sudo apt install php8.2-cli php8.2-mysql php8.2-mbstring
chmod +x php.sh serve.sh
```

`php.sh` は `php8.4` → `php8.3` → `php8.2` → `php` の順に探します。明示するなら:
```
export PHP_BIN=/usr/bin/php8.2
```
</details>

<details>
<summary><b>macOS</b></summary>

```
brew install php@8.2
chmod +x php.sh serve.sh
export PHP_BIN="$(brew --prefix php@8.2)/bin/php"
```

Homebrew の PHP は `mbstring` `openssl` `pdo_mysql` `fileinfo` を同梱しています。
</details>

### 3. データベースを用意する

MariaDB / MySQL を起動してから、管理者アカウントで:

```sql
CREATE DATABASE booking
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;

-- ホスト指定に注意。127.0.0.1 で接続してもサーバー側は逆引きして
-- 'localhost' として認証することがあるため、両方作っておくのが確実。
CREATE USER 'booking_app'@'localhost' IDENTIFIED BY '<任意のパスワード>';
CREATE USER 'booking_app'@'127.0.0.1' IDENTIFIED BY '<同じパスワード>';
GRANT SELECT, INSERT, UPDATE, DELETE ON booking.* TO 'booking_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON booking.* TO 'booking_app'@'127.0.0.1';
FLUSH PRIVILEGES;
```

アプリ用アカウントに DDL 権限は与えません。スキーマ変更は後述の `db.admin` で行います。

<details>
<summary><b>Windows で XAMPP の MariaDB を使う場合</b></summary>

```
C:\pleiades\xampp\mysql_start.bat
```
必ずこの bat 経由で起動してください。中身が相対パス前提のため、`mysqld.exe` を直接叩くと datadir を見失います。停止は `mysql_stop.bat`。

**プロセスを強制終了しないこと。** システムテーブル（Aria エンジン）が破損して `GRANT` が通らなくなることがあります。その場合は `REPAIR TABLE mysql.db;` で修復できます。
</details>

### 4. 設定ファイルを作る

```
cp config/config.sample.php config/config.php      # Windows: copy config\config.sample.php config\config.php
```

`config/config.php` を編集します。最低限、`db.pass` を手順 3 で決めたパスワードにしてください。

```php
'db' => [
    'dsn'   => 'mysql:host=127.0.0.1;port=3306;dbname=booking;charset=utf8mb4',
    'user'  => 'booking_app',
    'pass'  => '<手順3のパスワード>',
    // CLI 専用。migrate と seed は DDL を実行するため、アプリ用より強い権限が要る
    'admin' => ['user' => 'root', 'pass' => ''],
],
```

このファイルは Git 管理対象外です（`.gitignore` 済み）。パスワードがコミットされることはありません。

### 5. スキーマ投入とサンプルデータ

```
php.cmd bin/migrate.php          # Windows
./php.sh bin/migrate.php         # Linux / macOS

php.cmd bin/seed.php             # サンプルデータ（14社 × 4イベント × 計422開催回）
```

**DB を別マシンから持ち込む必要はありません。** シードは固定シード（`mt_srand(20260820)`）なので、どのマシンでも同じ ID・同じ時刻のデータが再現されます。作り直したいときは:

```
php.cmd bin/migrate.php --fresh
php.cmd bin/seed.php --fresh
```

`bin/seed.php` は管理者アカウントも作り、パスワードを標準出力に一度だけ表示します。控えてください。

### 6. 起動して確認

```
serve.cmd                        # Windows
./serve.sh                       # Linux / macOS
```

- <http://127.0.0.1:8000/> — イベント一覧
- <http://127.0.0.1:8000/_diag> — **環境診断**。ここが全項目 OK になっていれば設定は正しい

`/_diag` は PHP バージョン・拡張・タイムゾーンに加えて、**接続時に上書きしている DB セッション設定**（`sql_mode` に `STRICT_TRANS_TABLES` があるか、`time_zone` が `+09:00` か、分離レベルが `READ-COMMITTED` か）まで確認します。ここが赤いまま進めると、後で原因の分かりにくいデータ破損になります。

---

## 日常の操作

```
php.cmd bin/migrate.php --status      # マイグレーションの適用状況
php.cmd bin/request.php / --text      # サーバーを立てずにページを描画（動作確認用）
php.cmd bin/request.php /events/1 --headers
```

`bin/request.php` はフロントコントローラを CLI から叩くスクリプトです。`--post key=value` で POST も再現できます。

---

## 設計上、知っておくべきこと

### 排他制御がこのシステムの核心

「時間帯の重なり」は範囲比較なので UNIQUE 制約では表現できず、MariaDB には `EXCLUDE` 制約も部分インデックスもありません。そのためアプリ側のトランザクションで正しさを担保しています。

- **分離レベルは READ COMMITTED**（MariaDB 既定の REPEATABLE READ ではない）。RR だと直前に他トランザクションがコミットした予約が見えず、重複チェックをすり抜けます
- **ロック順序は `applicants` → `event_sessions` → `bookings` で固定**。全操作でこれを守ることでデッドロックを防いでいます
- `event_sessions.confirmed_seats` は**意図的な非正規化**です。`SUM(party_size)` では、まだ存在しない行をロックできないため同時申込が両方とも最後の 1 席を取れてしまいます
- `bookings.active_key` は `status='cancelled'` のとき NULL になる生成列で、UNIQUE インデックス内で NULL が重複を許される性質を使って「キャンセル後の再申込」を可能にしています

**なぜこの設計なのかは [docs/design.md](docs/design.md) に詳しく書いてあります。** 排他制御まわりを変更する前に必ず読んでください。実装のコメントも `src/Service/BookingService.php` と `db/migrations/001_init.sql` にあります。

### 競合テストは HTTP 経由では再現できない

**Windows の `php -S` は 1 リクエストずつ逐次処理**します。`PHP_CLI_SERVER_WORKERS` は POSIX 専用で無視されるため、ブラウザで何タブ開いても競合は起きません。「テストしたら通った」が偽の安心になる典型なので、`bin/concurrency_test.php`（バリア同期付きの CLI ワーカー）で検証してください。

### 文字コード

- PHP ファイルは **UTF-8 BOM なし**で保存（BOM があると `headers already sent` になります）
- テーブルは全て `utf8mb4` / `utf8mb4_unicode_ci` を明示。MariaDB で `utf8` と書くと `utf8mb3` になり、絵文字や一部の漢字が壊れます
- サーバーの `character_set_server` が `latin1` の環境があるため、DSN・DDL の両方で明示しています

### 改行コード

`.gitattributes` でリポジトリ内は LF に統一しています。`.cmd` だけは CRLF を強制（LF だとラベルや `goto` が誤動作するため）。Windows と Linux を行き来しても差分は出ません。

---

## 進捗

| 段階 | 状態 |
|---|---|
| 0. 環境セットアップ | 完了 |
| 1. アプリ骨格（ルータ / View / DB / セッション） | 完了 |
| 2. スキーマとシード | 完了 |
| 3. 公開カタログ（一覧・開催回選択） | ほぼ完了（イベント詳細ページの目視確認が残り） |
| 4. 申込コア（`BookingService`） | ほぼ完了（CLI で申込・重複拒否・満席→キャンセル待ち・隣接枠・不変条件を検証済み。ブラウザでの目視確認と段階9の並列競合テストが残り） |
| 5. 本人キャンセル | ほぼ完了（トークン表示・キャンセル・座席返却・二重キャンセルの冪等性・再申込・管理者宛空き通知を CLI 検証済み。並列競合テストは段階9） |
| 6. メール送信 | ほぼ完了（`bin/send_mail.php` でキュー12通を `.eml` 化、CRLF・RFC 2047 件名・base64 本文を検証済み。申込／キャンセル直後の即時送信も動作。SMTP 実サーバーでの送信とメールクライアントでの目視が残り） |
| 7. 管理 CRUD | ほぼ完了（ログイン／ロックアウト、会社・イベント・開催回 CRUD、一括生成、削除・定員ガードを実HTTPで検証済み。ブラウザでの目視が残り） |
| 8. 管理・運用機能 | ほぼ完了（申込一覧の絞込＋ページング、繰り上げ（空席・重複の再検証付き）、管理者キャンセル、CSV出力（BOM/CRLF/数式インジェクション対策）、mail_queue 画面を実HTTPで検証済み） |
| 9. 競合テストと堅牢化 | 未着手 ← **次はここ** |

各段階で何を作るかは [docs/design.md の実装順序](docs/design.md#d-実装順序) を参照してください。

---

## ディレクトリ構成

```
public/          ドキュメントルート。ここだけが HTTP から見える
src/
  Core/          Config Db Router Request Response View Csrf Auth ほか
  Domain/        BookingStatus SessionStatus TimeRange
  Repository/    テーブルごとのデータアクセス
  Service/       BookingService ほか業務ロジック
  Http/Controller/  Pub/ (公開側) Admin/ (管理側)
templates/       素の PHP テンプレート
config/          config.sample.php（コミット） / config.php（対象外）
db/migrations/   スキーマ
bin/             CLI スクリプト
docs/design.md   設計判断の記録（排他制御・スキーマ・落とし穴）
storage/         ログ・セッション・メール出力（対象外）
```

`config/` `src/` `storage/` はドキュメントルートの外にあるため、HTTP からは到達できません。
