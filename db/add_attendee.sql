-- 受付後に同行者を 1 名増やす。
--
-- ★ booking_attendees に行を足すだけでは足りません。
--   このテーブルは「誰が来るか」の記録で、席を取っているのは
--   bookings.party_size だけです。行だけ足すと、名前はあるのに席が無い人が
--   できます。**しかも不変条件は破れないので、検査では見つかりません。**
--   残席はその人の分を数えないまま売られ続け、当日その回は 1 人ぶん溢れます。
--
-- 席まで動かすには 3 つを同時に更新します:
--   1. booking_attendees   誰が来るか
--   2. bookings.party_size 何席取るか
--   3. event_sessions.confirmed_seats  その回が何席埋まっているか（不変条件 1）
--
-- 使い方:
--   1. @booking_id / @name / @age を埋める
--   2. 手順 1 の事前確認をすべて通す（1 行でも出たら追加してはいけません）
--   3. 手順 2 を実行
--   4. 手順 3 で確認
--
-- 予約者ご本人に「N 名で受け付けました」とメールが届いています。人数が変わる
-- ことは別途ご連絡ください（このスクリプトはメールを送りません）。

SET @booking_id = 0;
SET @name = '';
SET @age  = NULL;     -- 不明なら NULL のまま
SET @actor = 'admin:office';   -- 監査ログに残す操作者


-- ============================================================
-- 手順 1: 事前確認。1a〜1d は 1 行でも出たら追加できません
-- ============================================================

-- 1a. 予約の状態。confirmed か waitlisted のみ。キャンセル済みは増やせません
--     （キャンセル済みに足すと、復活させたときに席がずれます）
SELECT b.id, b.reference_code, b.status, b.party_size, b.guardian_count,
       s.id AS session_id, s.capacity, s.confirmed_seats,
       s.capacity - s.confirmed_seats AS 空き,
       e.max_party_size, e.party_includes_guardians
  FROM bookings b
  JOIN event_sessions s ON s.id = b.session_id
  JOIN events e         ON e.id = s.event_id
 WHERE b.id = @booking_id;

-- 1b. 席が無い（confirmed のときだけ問題になります）
SELECT s.id AS session_id, s.capacity, s.confirmed_seats
  FROM bookings b JOIN event_sessions s ON s.id = b.session_id
 WHERE b.id = @booking_id
   AND b.status = 'confirmed'
   AND s.confirmed_seats + 1 > s.capacity;

-- 1c. ★ その回にキャンセル待ちの方がいる
--     空席はお待ちの方のものです（BookingService::wouldWaitlist）。ここで
--     既存予約に足すと、新規予約には回さないと決めた席を、あとから申し出た
--     同行者に回すことになり、待っている方を追い越します。
--     足すなら、それが妥当かどうかを人が判断したうえで。
SELECT COUNT(*) AS キャンセル待ち件数
  FROM bookings w
  JOIN bookings b ON b.session_id = w.session_id
 WHERE b.id = @booking_id AND w.status = 'waitlisted'
HAVING キャンセル待ち件数 > 0;

-- 1d. 1 予約あたりの上限人数、および 20 名の上限（chk_bookings_party）
SELECT b.id, b.party_size + 1 AS 追加後, e.max_party_size
  FROM bookings b
  JOIN event_sessions s ON s.id = b.session_id
  JOIN events e         ON e.id = s.event_id
 WHERE b.id = @booking_id
   AND (b.party_size + 1 > e.max_party_size OR b.party_size + 1 > 20);

-- 1e. 空いている attendee_no（uq_attendees_slot があるので重複させられません）
SELECT COALESCE(MAX(attendee_no), 0) + 1 AS 次の番号
  FROM booking_attendees WHERE booking_id = @booking_id;


-- ============================================================
-- 手順 2: 追加（1 トランザクション）
-- ============================================================
START TRANSACTION;

-- 2a. 誰が来るか。番号は既存の最大 + 1（欠番があっても詰めません）
INSERT INTO booking_attendees (booking_id, attendee_no, name, age)
SELECT @booking_id,
       COALESCE(MAX(attendee_no), 0) + 1,
       @name,
       @age
  FROM booking_attendees WHERE booking_id = @booking_id;

-- 2b. 何席取るか
UPDATE bookings SET party_size = party_size + 1 WHERE id = @booking_id;

-- 2c. その回が何席埋まっているか。**confirmed のときだけ**。
--     キャンセル待ちはまだ席を取っていないので、ここを動かすと
--     不変条件 (1) が壊れます。
UPDATE event_sessions s
  JOIN bookings b ON b.session_id = s.id
   SET s.confirmed_seats = s.confirmed_seats + 1
 WHERE b.id = @booking_id AND b.status = 'confirmed';

-- 2d. 監査ログ。状態は変わらないので from と to は同じにし、note に残します。
INSERT INTO booking_events (booking_id, from_status, to_status, actor, note)
SELECT id, status, status, @actor,
       CONCAT('同行者を追加: ', @name, '（人数 ', party_size - 1, ' → ', party_size, '）')
  FROM bookings WHERE id = @booking_id;

COMMIT;


-- ============================================================
-- 手順 3: 事後確認。3a・3b は 0 行であること
-- ============================================================

-- 3a. 座席カウンタと実際の合計が一致（不変条件 1）
SELECT s.id AS session_id, s.confirmed_seats, COALESCE(SUM(b.party_size), 0) AS 実際の合計
  FROM event_sessions s
  LEFT JOIN bookings b ON b.session_id = s.id AND b.status = 'confirmed'
 WHERE s.id = (SELECT session_id FROM bookings WHERE id = @booking_id)
 GROUP BY s.id, s.confirmed_seats
HAVING s.confirmed_seats <> 実際の合計;

-- 3b. 定員を超えていない（不変条件 2）
SELECT id AS session_id, confirmed_seats, capacity
  FROM event_sessions
 WHERE id = (SELECT session_id FROM bookings WHERE id = @booking_id)
   AND confirmed_seats > capacity;

-- 3c. 結果
SELECT b.id, b.reference_code, b.status, b.party_size AS 人数,
       (SELECT COUNT(*) FROM booking_attendees a WHERE a.booking_id = b.id) AS 氏名の登録数,
       s.confirmed_seats AS 確定席, s.capacity AS 定員
  FROM bookings b JOIN event_sessions s ON s.id = b.session_id
 WHERE b.id = @booking_id;

SELECT attendee_no, name, age FROM booking_attendees
 WHERE booking_id = @booking_id ORDER BY attendee_no;


-- ============================================================
-- 別のケース
-- ============================================================
-- ■ 増えるのが「体験しない付き添い」で、そのイベントが
--   party_includes_guardians = 0 のとき
--   席を取らないので、attendees も party_size も触りません。
--     UPDATE bookings SET guardian_count = guardian_count + 1 WHERE id = @booking_id;
--
-- ■ 減らすとき
--   2a を DELETE、2b/2c を -1 にします。attendee_no は詰めません（欠番のまま
--   で構いません。BookingService も空欄の氏名で番号を飛ばします）。
--   confirmed_seats を戻し忘れると、その席は誰も取れないまま埋まり続けます。
