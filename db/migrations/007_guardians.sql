-- 参加者と付き添い（保護者等）の数え方は、イベントによって違う。
--
-- 工作教室のように「体験するのは子どもだけ、保護者は横で見ている」イベントでは、
-- 定員が意味するのは体験する人数であって、会場に来る人数ではない。一方、親子で
-- 一緒に体験するイベントでは保護者も参加者そのものになる。同じ「参加人数」という
-- 欄が、イベントによって別のものを数えている。
--
-- events.party_includes_guardians
--   0 = 参加人数は体験する人だけ。付き添いは bookings.guardian_count に別に
--       記録し、定員は消費しない
--   1 = 付き添いも参加人数に含める。guardian_count は使わず 0 のまま
--
-- 既定を 0 にしたのは、公開中の予約画面が既に「体験されない付き添いの方は
-- 含めません」と案内しているため。既存イベントの挙動が変わらない側を既定に置く。
--
-- 座席の勘定は変えない。confirmed_seats が数えるのは party_size のままで、
-- guardian_count は定員を消費しない。0 のイベントでは
-- 「来場する人数 = party_size + guardian_count」になるので、会場の収容や
-- 配布物はそちらで見積もる必要がある（管理画面と CSV に出している）。

ALTER TABLE events
  ADD COLUMN party_includes_guardians TINYINT(1) NOT NULL DEFAULT 0 AFTER max_party_size;

ALTER TABLE bookings
  ADD COLUMN guardian_count TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER party_size;

-- 上限は CHECK ではなくアプリ側で持つ。002 で入れようとした CHECK 制約が
-- MariaDB 11.8（本番）では単独の ALTER にしても errno 1901 で拒否され、
-- 同じやり方をここで繰り返す理由がない。UNSIGNED が負数を防ぎ、20 名までは
-- Validator と BookingService が担保する（docs/design.md の落とし穴を参照）。
