-- イベントごとの対象年齢。上限・下限とも任意で、片方だけの指定もできます。
--
-- NULL = 制限なし。「0 歳以上」と「下限なし」は実務上同じに見えますが、
-- 管理画面で空欄にしたのか 0 と書いたのかは別のことなので、既定値は置かず
-- NULL のままにします。
--
-- 検査するのは「入力された年齢すべて」です。分岐は要りません:
--   party_includes_guardians = 0 … 付き添いは guardian_count（人数だけ）で
--                                   年齢を持たないので、そもそも対象がない
--   party_includes_guardians = 1 … 付き添いは参加者そのものなので年齢があり、
--                                   そのまま対象になる
--
-- CHECK (min_age <= max_age) は置きません。002 で入れようとした CHECK 制約が
-- MariaDB 11.8（本番）では単独の ALTER にしても errno 1901 で拒否されたため。
-- 大小関係は管理画面の Validator が担保します（docs/design.md の落とし穴）。
--
-- TINYINT UNSIGNED は 0〜255。年齢の入力自体は 0〜120 に制限されています。

ALTER TABLE events
  ADD COLUMN min_age TINYINT UNSIGNED NULL AFTER party_includes_guardians;

ALTER TABLE events
  ADD COLUMN max_age TINYINT UNSIGNED NULL AFTER min_age;
