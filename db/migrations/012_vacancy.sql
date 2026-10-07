-- 当日の空き状況の報告。
--
-- ★ このマイグレーションを当てても挙動は変わりません。
--   既存のテーブルは 1 列も触らず、CREATE TABLE だけです。表示も入力も
--   ルートを足して初めて現れるので、先に本番へ入れておいても安全です。
--
-- 当日券は紙で配るため、予約システムの confirmed_seats は当日の残数と
-- 一致しません（定員 20・予約 12 の回に紙を 8 枚配れば満席だが、システムは
-- 「残り 8」と言う）。だからここに入るのは「人が見て報告した値」だけで、
-- 公開ページも予約システムの残席数は出しません。
--
-- ■ なぜ上書きではなく追記か
--   当日はチャット経由の伝言です。「誰が・いつ・何と言ったか」が残らないと、
--   食い違ったときに確認できません。「10:35 現在」を出すにもどのみち報告時刻が
--   要ります。そして追記しかしないので、不具合が出ても過去の行を壊しません。
--   現在値は (event_id, session_id) ごとの最新 1 件として引きます。
--
-- ■ session_id が NULL のとき
--   その体験の「現在の」空き状況です。チャットで「今は◎」と届く経路がこれ。
--   値が入っていればその開催回の状況で、整理券を貼った一覧表の写真から
--   転記する経路がこちらになります。どちらも当日の主役です。
--
-- ■ level に CHECK を張らない理由
--   MariaDB 11.8 が ADD CONSTRAINT ... CHECK を受け付けない（errno 1901）ため、
--   このプロジェクトでは値の妥当性を PHP 側に置く方針です。ここでは
--   App\Domain\VacancyLevel が担保します。

CREATE TABLE vacancy_reports (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,

  event_id    INT UNSIGNED NOT NULL,
  -- NULL = その体験の「現在の」状況。値あり = その開催回の状況。
  session_id  INT UNSIGNED NULL,

  -- open / ample / few / none（◎ ◯ △ ✕）。VacancyLevel が知っている値だけ。
  level       VARCHAR(8) NOT NULL,
  -- 整理券の残数。任意。記号が主で、これは併記される従。
  remaining   SMALLINT UNSIGNED NULL,
  note        VARCHAR(200) NULL,

  -- 「何時何分現在」として表示する時刻。入力した時刻ではなく、報告された
  -- 時刻を入れられるようにしてある（写真が届くまでに時間が空くため）。
  reported_at DATETIME NOT NULL,
  -- 'admin:<username>'。booking_events の actor と同じ書き方。
  reported_by VARCHAR(100) NOT NULL,

  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  CONSTRAINT fk_vacancy_event   FOREIGN KEY (event_id)   REFERENCES events(id)         ON DELETE CASCADE,
  CONSTRAINT fk_vacancy_session FOREIGN KEY (session_id) REFERENCES event_sessions(id) ON DELETE CASCADE,

  -- 「(event_id, session_id) ごとの最新」を引くための並び。id が末尾なのは、
  -- MAX(id) をインデックスの端から取れるようにするため。
  KEY idx_vacancy_latest (event_id, session_id, id),
  KEY idx_vacancy_reported (reported_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
