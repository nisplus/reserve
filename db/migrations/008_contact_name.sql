-- 連絡を取る相手の氏名。参加者の氏名とは別。
--
-- bookings.name は「1 人目の参加者」の氏名で、booking_attendees の
-- attendee_no 1 と同じ人です。子どもの工作教室のように参加者が子どもだけの
-- 場合、連絡先は付き添いの保護者であって参加者ではありません。これまでは
-- 「連絡先となる方の氏名はメッセージ欄にご記入ください」と案内していました
-- が、自由記述に頼ると宛名にも一覧にも出てこないため、欄として持ちます。
--
-- 確認メールの宛名はこの列を使います（メールアドレスと電話番号の持ち主に
-- 宛てるのが自然で、参加している子どもに宛てるのは不自然）。
--
-- 既存行は連絡先＝予約者だったので name をそのまま写します。写してから
-- NOT NULL にするので、「空文字が入っているかもしれない列」を読む側が
-- 気にする必要はありません。

ALTER TABLE bookings
  ADD COLUMN contact_name VARCHAR(100) NULL AFTER name;

UPDATE bookings SET contact_name = name WHERE contact_name IS NULL;

ALTER TABLE bookings
  MODIFY COLUMN contact_name VARCHAR(100) NOT NULL;
