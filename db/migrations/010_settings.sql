-- アプリ全体の設定。今のところ予約受付の開閉だけが入る。
--
-- ★ このマイグレーションを当てても挙動は変わりません。
--   行を 1 つも入れないのは意図的です。App\Core\Settings は行が無いときに
--   既定値を返し、その既定値は「受付中」なので、設定を触るまで今日とまったく
--   同じ動きになります。本番に先に入れておいても安全、という性質をここで
--   担保しています（逆に「停止」を既定にすると、migrate.php を打った瞬間に
--   予約が止まります）。
--
-- キー/値にしたのは、設定が増えるたびにマイグレーションを足したくないため。
-- 型の担保は App\Core\Settings 側が持ちます（既知のキーのホワイトリストと、
-- bool / datetime / text の読み出し）。
--
-- name であって key ではないのは、key が MySQL の予約語だからです。
--
-- value を TEXT にしているのは、停止中に出す案内文がここに入るため。
-- 真偽値は '1' / '0'、日時は 'Y-m-d H:i:s'（JST。接続は time_zone = '+09:00'
-- を張っており、PHP 側も date_default_timezone_set('Asia/Tokyo') 済み）。

CREATE TABLE settings (
  name       VARCHAR(64) NOT NULL PRIMARY KEY,
  value      TEXT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
