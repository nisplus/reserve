-- サンプルイベントとテスト予約をすべて削除し、本番データを入れられる状態にする。
-- スキーマ・マイグレーション記録・事務局アカウントは残ります。
--
--   mysqldump -u root -p booking > backup.sql     ← 先にバックアップ
--   mysql -u root -p booking < db/wipe_sample_data.sql
--
-- 順番と FOREIGN_KEY_CHECKS の理由は docs/operations.md に書いてあります。要点:
--
--   * FOREIGN_KEY_CHECKS = 0 の間は ON DELETE CASCADE が働かないので、
--     子テーブルを一つずつ明示的に消す必要があります。
--   * TRUNCATE を使うのは AUTO_INCREMENT を 1 に戻すためです。
--   * admin_users.company_id が companies を参照しているため、会社担当者
--     アカウントを先に消さないと、存在しない会社を指す行が残ります。

SET FOREIGN_KEY_CHECKS = 0;

TRUNCATE TABLE booking_attendees;
TRUNCATE TABLE booking_events;
TRUNCATE TABLE mail_queue;
TRUNCATE TABLE bookings;
TRUNCATE TABLE applicants;
TRUNCATE TABLE event_sessions;
TRUNCATE TABLE events;

-- 所属先を失うため会社担当者も削除。事務局（superadmin）は残す。
DELETE FROM admin_users WHERE role = 'company';

TRUNCATE TABLE companies;

SET FOREIGN_KEY_CHECKS = 1;

-- 確認
SELECT 'companies' AS t, COUNT(*) AS n FROM companies
UNION ALL SELECT 'events',         COUNT(*) FROM events
UNION ALL SELECT 'event_sessions', COUNT(*) FROM event_sessions
UNION ALL SELECT 'bookings',       COUNT(*) FROM bookings
UNION ALL SELECT 'applicants',     COUNT(*) FROM applicants
UNION ALL SELECT 'mail_queue',     COUNT(*) FROM mail_queue
UNION ALL SELECT 'admin_users',    COUNT(*) FROM admin_users;

SELECT COUNT(*) AS dangling_admin_company_id
FROM admin_users u
LEFT JOIN companies c ON c.id = u.company_id
WHERE u.company_id IS NOT NULL AND c.id IS NULL;
