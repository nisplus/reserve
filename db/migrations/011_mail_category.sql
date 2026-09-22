-- 一斉送信と取引メールを分けるための区分。
--
-- 分ける理由は一つで、予約した人を待たせないためです。予約が確定すると
-- MailDispatcher::tryProcessPending() がそのリクエストの中で数通を送ります。
-- 一斉送信で数百通がキューに積まれていると、予約した人のリクエストが
-- 他人宛のメールの送信を待たされることになります。区分を持たせて、
-- インライン送信は transactional だけを拾うようにします。
--
-- cron（bin/send_mail.php）と管理画面の「今すぐ送信」は区別せず全部流します。
-- そこは待たされて困る人がいません。
--
-- 既存行はすべて予約にひもづく取引メールなので、DEFAULT がそのまま正しい値に
-- なります。NULL 可にして「未設定」を作らないのは、区分を見て分岐する側が
-- NULL を考えなくて済むようにするためです。

ALTER TABLE mail_queue
  ADD COLUMN category VARCHAR(20) NOT NULL DEFAULT 'transactional' AFTER booking_id;

-- pendingIds はこの並びで引きます（status → category → id）。
CREATE INDEX idx_mail_status_category ON mail_queue (status, category, id);
