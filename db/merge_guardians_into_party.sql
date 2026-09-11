-- 「付き添いの保護者も参加人数に含める」を後から有効にするための、既存予約の付け替え。
--
-- チェックを入れ忘れたまま予約を受け付けると、予約は
--   party_size = 体験する人数 / guardian_count = 付き添い人数
-- として保存されています。チェックを入れるだけでは既存分はこの形のまま残り、
-- 以後の予約だけが「全員 party_size」になるため、同じイベントの中に
-- 2 通りの数え方が混在します。
--
-- ここでは既存分を新しい数え方に寄せます:
--   party_size += guardian_count / guardian_count = 0
--
-- ★ 定員の意味が変わることに注意
--   変換前の定員は「体験する人の数」、変換後は「来場する人の数」です。
--   定員 10（子ども 10 人）のつもりだった回は、変換後は「保護者込みで 10 人」に
--   なります。受け入れ人数を維持したいなら、定員も上げる必要があります（手順 4）。
--
-- ★ 参加者の氏名は増えません
--   booking_attendees に付き添いの方の行はありません（氏名も年齢も聞いていない）。
--   変換後は party_size より参加者一覧が短くなります。表示は対応済みですが、
--   「3 名なのに氏名は 2 名分」という状態になります。埋めるなら当日確認になります。
--
-- 使い方:
--   1. mysqldump でバックアップを取る
--   2. @event_id に対象イベントの ID を入れる
--   3. 手順 1 の事前確認をすべて 0 行にする（1 行でも出たら手順 4 を先に）
--   4. 手順 2 を実行
--   5. 手順 3 の事後確認

-- ============================================================
-- 対象イベント（管理画面の URL /admin/events/{ID}/edit の ID）
-- ============================================================
SET @event_id = 0;


-- ============================================================
-- 手順 1: 事前確認。1a〜1c は 1 行でも出たら変換してはいけません
-- ============================================================

-- 1a. 変換すると定員を超える開催回
--     変換前の定員は体験する人だけを数えていたので、ここが最も出やすい。
--     出た場合は先に定員を上げる（手順 4）か、その回は変換しない。
SELECT s.id AS session_id, s.starts_at, s.capacity,
       s.confirmed_seats                      AS 現在の確定席,
       COALESCE(SUM(b.party_size + b.guardian_count), 0) AS 変換後の確定席
  FROM event_sessions s
  LEFT JOIN bookings b
         ON b.session_id = s.id AND b.status = 'confirmed'
 WHERE s.event_id = @event_id
 GROUP BY s.id, s.starts_at, s.capacity, s.confirmed_seats
HAVING 変換後の確定席 > s.capacity;

-- 1b. 変換すると「1 予約あたりの上限人数」を超える予約
--     この値は予約時にしか効かないため超えていても動きますが、
--     以後その予約を管理画面で編集できなくなるので、上限を上げるほうが安全。
SELECT b.id AS booking_id, b.reference_code, b.status,
       b.party_size, b.guardian_count,
       b.party_size + b.guardian_count AS 変換後の人数,
       e.max_party_size                AS 上限人数
  FROM bookings b
  JOIN event_sessions s ON s.id = b.session_id
  JOIN events e         ON e.id = s.event_id
 WHERE s.event_id = @event_id
   AND b.status <> 'cancelled'
   AND b.party_size + b.guardian_count > e.max_party_size;

-- 1c. 変換すると 20 名を超える予約（chk_bookings_party に弾かれ、UPDATE が失敗します）
SELECT b.id AS booking_id, b.reference_code,
       b.party_size, b.guardian_count,
       b.party_size + b.guardian_count AS 変換後の人数
  FROM bookings b
  JOIN event_sessions s ON s.id = b.session_id
 WHERE s.event_id = @event_id
   AND b.party_size + b.guardian_count > 20;

-- 1d. 参考: 変換後に定員そのものを超えてしまうキャンセル待ち
--     （繰り上げが永久にできなくなるので、定員を見直す材料に）
SELECT b.id AS booking_id, b.reference_code, b.waitlist_seq,
       b.party_size + b.guardian_count AS 変換後の人数, s.capacity
  FROM bookings b
  JOIN event_sessions s ON s.id = b.session_id
 WHERE s.event_id = @event_id
   AND b.status = 'waitlisted'
   AND b.party_size + b.guardian_count > s.capacity;

-- 1e. 変換前の健全性。0 行でなければ、この作業とは別の不整合が既にあります
SELECT s.id AS session_id, s.confirmed_seats, COALESCE(SUM(b.party_size), 0) AS 実際の合計
  FROM event_sessions s
  LEFT JOIN bookings b ON b.session_id = s.id AND b.status = 'confirmed'
 WHERE s.event_id = @event_id
 GROUP BY s.id, s.confirmed_seats
HAVING s.confirmed_seats <> 実際の合計;

-- 1f. 変換対象の一覧（目視用）
SELECT b.id, b.reference_code, b.status, b.contact_name,
       b.party_size AS 参加者, b.guardian_count AS 付き添い,
       b.party_size + b.guardian_count AS 変換後
  FROM bookings b
  JOIN event_sessions s ON s.id = b.session_id
 WHERE s.event_id = @event_id AND b.guardian_count > 0
 ORDER BY b.id;


-- ============================================================
-- 手順 2: 変換（3 つまとめて 1 トランザクション）
-- ============================================================
START TRANSACTION;

-- 2a. 予約の人数を寄せる。キャンセル済みも含めるのは、記録として
--     同じイベントの予約が同じ意味を持つようにするため（席には影響しません）。
UPDATE bookings b
  JOIN event_sessions s ON s.id = b.session_id
   SET b.party_size     = b.party_size + b.guardian_count,
       b.guardian_count = 0
 WHERE s.event_id = @event_id
   AND b.guardian_count > 0;

-- 2b. 座席カウンタを引き直す。加算ではなく SUM からの再計算にしているのは、
--     加算し損ね・二重加算が起きないため（何度実行しても同じ値になります）。
UPDATE event_sessions s
   SET s.confirmed_seats = (
         SELECT COALESCE(SUM(b.party_size), 0)
           FROM bookings b
          WHERE b.session_id = s.id AND b.status = 'confirmed'
       )
 WHERE s.event_id = @event_id;

-- 2c. イベント側のフラグ。これを忘れると、以後の予約がまた別カウントに戻ります。
UPDATE events SET party_includes_guardians = 1 WHERE id = @event_id;

COMMIT;


-- ============================================================
-- 手順 3: 事後確認。3a〜3c はすべて 0 行であること
-- ============================================================

-- 3a. 座席カウンタと実際の合計が一致（不変条件 1）
SELECT s.id AS session_id, s.confirmed_seats, COALESCE(SUM(b.party_size), 0) AS 実際の合計
  FROM event_sessions s
  LEFT JOIN bookings b ON b.session_id = s.id AND b.status = 'confirmed'
 WHERE s.event_id = @event_id
 GROUP BY s.id, s.confirmed_seats
HAVING s.confirmed_seats <> 実際の合計;

-- 3b. 定員を超えていない（不変条件 2）
SELECT id AS session_id, confirmed_seats, capacity
  FROM event_sessions
 WHERE event_id = @event_id AND confirmed_seats > capacity;

-- 3c. 付き添いの別カウントが残っていない
SELECT b.id AS booking_id, b.guardian_count
  FROM bookings b
  JOIN event_sessions s ON s.id = b.session_id
 WHERE s.event_id = @event_id AND b.guardian_count <> 0;

-- 3d. 結果の一覧（目視用）。参加者一覧が人数より短い予約もここで分かります。
SELECT b.id, b.reference_code, b.status, b.contact_name,
       b.party_size AS 人数,
       (SELECT COUNT(*) FROM booking_attendees a WHERE a.booking_id = b.id) AS 氏名の登録数
  FROM bookings b
  JOIN event_sessions s ON s.id = b.session_id
 WHERE s.event_id = @event_id
 ORDER BY b.id;

-- 3e. イベントのフラグ
SELECT id, title, max_party_size, party_includes_guardians FROM events WHERE id = @event_id;


-- ============================================================
-- 手順 4: 事前確認で引っかかったときの対処（必要な回だけ）
-- ============================================================
-- 定員を上げる（1a が出たとき）。変換後の人数以上にすること。
--   UPDATE event_sessions SET capacity = 12 WHERE id = 0;
--
-- 1 予約あたりの上限人数を上げる（1b が出たとき）。20 が上限。
--   UPDATE events SET max_party_size = 10 WHERE id = @event_id;
--
-- どちらも管理画面から変更できます。定員は「現在の確定席数未満には下げられない」
-- という制限が管理画面側にありますが、上げるぶんには制限はありません。
