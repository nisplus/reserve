<?php

use App\Core\Csrf;
use App\Service\VacancyService;

/**
 * @var string $date
 * @var array<int, array<string, mixed>> $events   booths running that day, with their current report
 * @var array<string, mixed>|null        $event    the programme being transcribed, if one was chosen
 * @var array<int, array<string, mixed>> $sessions that programme's whole day
 * @var array<string, string>            $levels   value => label
 * @var array<int, array<string, mixed>> $recent
 * @var bool                             $isOffice
 * @var array<int, array{date: string, sessions: int}> $otherDays
 *        days that do have sessions; filled in only when this day has none
 */
$dayUrl = static fn (string $d, int $eventId = 0): string => url('/admin/vacancy') . '?' . http_build_query(
    array_filter(['date' => $d, 'event' => $eventId > 0 ? $eventId : null])
);
$shift = static fn (int $days): string => (new DateTimeImmutable($date))
    ->modify(($days >= 0 ? '+' : '') . $days . ' day')->format('Y-m-d');

/** The current state of a row, or a dash. */
$current = static function (?array $report): string {
    if ($report === null) {
        return '<span class="muted">— 未報告</span>';
    }
    $out = '<span class="badge ' . e($report['level']->badgeClass()) . '">'
         . e($report['level']->mark()) . ' ' . e($report['level']->shortLabel()) . '</span>';
    if ($report['remaining'] !== null) {
        $out .= ' <span class="muted">残 ' . (int) $report['remaining'] . '</span>';
    }
    $out .= '<br><span class="muted" style="font-size:12px">'
          . e(substr((string) $report['reported_at'], 11, 5)) . ' 現在';
    if ($report['is_stale']) {
        $out .= ' ⚠ 古い';
    }
    return $out . '</span>';
};
?>
<h1>当日の空き状況</h1>

<p class="muted">
  来場者向けの<a href="<?= url('/vacancy') ?>" target="_blank" rel="noopener">公開ページ</a>に表示されます。
  <strong>当日券は紙でお渡しするため、予約システムの残席数は表示していません。</strong>
  ここに入力された内容だけが公開されます。
  <?php if (!$isOffice): ?>登録できるのは<strong>自社の体験プログラム</strong>のみです。<?php endif; ?>
</p>

<?php /* Stated up front rather than left to be noticed: an operator looking
         for a programme that is not here needs to know it is not missing. */ ?>
<p class="muted">
  この画面に出るのは<strong>「予約不要」の体験プログラム</strong>だけです。
  予約が必要なものは<a href="<?= url('/admin/events') ?>">体験内容</a>・<a href="<?= url('/admin/bookings') ?>">予約一覧</a>で残席がわかるため、ここでは扱いません。
</p>

<div class="filter-bar" style="margin-bottom:16px">
  <a class="btn btn--ghost btn--small" href="<?= e($dayUrl($shift(-1))) ?>">← 前の日</a>
  <strong style="font-size:17px"><?= e(jp_date($date)) ?></strong>
  <a class="btn btn--ghost btn--small" href="<?= e($dayUrl($shift(1))) ?>">次の日 →</a>
  <a class="btn btn--ghost btn--small" href="<?= e($dayUrl((new DateTimeImmutable('today'))->format('Y-m-d'))) ?>">今日に戻す</a>
  <?php if ($isOffice): ?>
    <?php /* Rehearsing the wall display needs neither the day nor any data,
             which is the point: you cannot check it for the first time on the
             morning it has to be right. */ ?>
    <?php $try = static fn (string $mode): string => url('/vacancy') . '?' . http_build_query([
        'display' => $mode,
        'preview' => '1',
        'date'    => $date,
    ]); ?>
    <a class="btn btn--ghost btn--small" href="<?= e($try('signage')) ?>"
       target="_blank" rel="noopener">サイネージ表示（この日の催事で）</a>
    <a class="btn btn--ghost btn--small" href="<?= e($try('embed')) ?>"
       target="_blank" rel="noopener">埋め込み表示（この日の催事で）</a>
    <a class="btn btn--ghost btn--small" href="<?= url('/admin/vacancy/signage') ?>"
       target="_blank" rel="noopener">見本データで見る</a>
  <?php endif; ?>
</div>

<?php if ($otherDays !== []): ?>
  <?php /* Without this the screen just looks broken on any day but the
           festival's own: the walk-up booths are all that is left, and
           nothing says why or where the rest went. */ ?>
  <div class="flash flash--info" style="margin-bottom:16px">
    <strong><?= e(jp_date($date)) ?>は、開催回のある体験プログラムがありません。</strong><br>
    下の体験プログラムには<strong>記号（◎◯△✕）だけ</strong>を登録できます。
    開催回があるのは次の日です。
    <?php foreach ($otherDays as $day): ?>
      <a class="btn btn--ghost btn--small" style="margin:4px 4px 0 0"
         href="<?= e($dayUrl($day['date'])) ?>"><?= e(jp_date($day['date'])) ?>（<?= (int) $day['sessions'] ?> 回）</a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if ($events === []): ?>
  <p class="empty">この日に開催される体験プログラムはありません。</p>
<?php else: ?>

<?php /* ------------------------------------------------ quick entry ----- */ ?>
<h2>今の状況を登録する</h2>
<p class="muted">
  チャットなどで「今は◎です」と連絡が来たときはこちらです。記号を押すとその場で登録されます
  （<strong>確認のポップアップは出ません</strong>。何度も押すものなので、押し間違えても
  正しい記号を押し直せば最新が優先されます）。
</p>

<div class="table-scroll">
  <table class="table">
    <thead>
      <tr><th>体験プログラム</th><th>現在</th><th>登録</th><th>開催回</th></tr>
    </thead>
    <tbody>
    <?php $company = null; ?>
    <?php foreach ($events as $row): ?>
      <?php if ($company !== (int) $row['company_id']): ?>
        <?php $company = (int) $row['company_id']; ?>
        <tr><td colspan="4" style="background:var(--line-soft)"><strong><?= e($row['company_name']) ?></strong></td></tr>
      <?php endif; ?>
      <tr id="e<?= (int) $row['id'] ?>">
        <td>
          <?= e($row['title']) ?>
          <?php if (($row['venue'] ?? '') !== ''): ?>
            <br><span class="muted" style="font-size:12px"><?= e($row['venue']) ?></span>
          <?php endif; ?>
        </td>
        <td><?= $current($row['report']) ?></td>
        <td>
          <form class="inline-form" method="post" action="<?= url('/admin/vacancy') ?>">
            <?= Csrf::field() ?>
            <input type="hidden" name="date" value="<?= e($date) ?>">
            <input type="hidden" name="event_id" value="<?= (int) $row['id'] ?>">
            <?php foreach ($levels as $value => $label): ?>
              <button type="submit" name="level" value="<?= e($value) ?>"
                      class="btn btn--small btn--ghost" title="<?= e($label) ?>"
                      style="font-size:16px"><?= e(mb_substr($label, 0, 1)) ?></button>
            <?php endforeach; ?>
            <input type="text" name="remaining" inputmode="numeric" maxlength="4"
                   placeholder="残数" style="width:70px" aria-label="整理券の残数">
          </form>
        </td>
        <td>
          <?php /* Rounds come from event_sessions. With none registered that
                   day there is nothing to open a per-session screen onto, so
                   the marks above are the whole of what can be said. */ ?>
          <?php if (!$row['is_walk_in']): ?>
            <a class="btn btn--ghost btn--small" href="<?= e($dayUrl($date, (int) $row['id'])) ?>#sessions">
              開催回を入力（<?= (int) $row['session_count'] ?>）
            </a>
          <?php else: ?>
            <span class="muted" style="font-size:12px">開催回なし</span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php /* ------------------------------------------- transcription ------- */ ?>
<?php if ($event !== null): ?>
  <h2 id="sessions" style="margin-top:28px">開催回ごとに登録する</h2>
  <p class="muted">
    各社から届いた<strong>整理券の状況を貼った開催回一覧表の写真</strong>を見ながら入力します。
    並びは<strong>開催時刻順</strong>で、紙の一覧表と同じ順序です。<br>
    <strong>記号を選んでいない行は登録しません。</strong>写真に写っていない回は、そのままにしておいてください。
  </p>

  <div class="panel" style="max-width:900px">
    <h3><?= e($event['company_name']) ?>　<?= e($event['title']) ?></h3>

    <?php if ($sessions === []): ?>
      <p class="empty">この日の開催回がありません。</p>
    <?php else: ?>
      <form method="post" action="<?= url('/admin/vacancy/sessions') ?>">
        <?= Csrf::field() ?>
        <input type="hidden" name="date" value="<?= e($date) ?>">
        <input type="hidden" name="event_id" value="<?= (int) $event['id'] ?>">

        <div class="table-scroll">
          <table class="table">
            <thead>
              <tr><th>開催回</th><th>現在</th><th>空き状況</th><th>整理券の残数</th></tr>
            </thead>
            <tbody>
            <?php foreach ($sessions as $session): ?>
              <?php $id = (int) $session['id']; ?>
              <tr>
                <td>
                  <?= e(jp_time((string) $session['starts_at'])) ?>〜<?= e(jp_time((string) $session['ends_at'])) ?>
                  <?php if ($session['in_progress']): ?><span class="badge badge--ok">開催中</span><?php endif; ?>
                </td>
                <td><?= $current($session['report']) ?></td>
                <td>
                  <select name="level[<?= $id ?>]" aria-label="空き状況">
                    <option value="">（登録しない）</option>
                    <?php foreach ($levels as $value => $label): ?>
                      <option value="<?= e($value) ?>"><?= e($label) ?></option>
                    <?php endforeach; ?>
                  </select>
                </td>
                <td>
                  <input type="text" name="remaining[<?= $id ?>]" inputmode="numeric" maxlength="4"
                         style="width:80px" aria-label="整理券の残数">
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div class="form-actions">
          <button type="submit" class="btn">まとめて登録</button>
          <a class="btn btn--ghost" href="<?= e($dayUrl($date)) ?>">閉じる</a>
        </div>
      </form>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php endif; ?>

<?php /* ------------------------------------------------- history ------- */ ?>
<?php if ($recent !== []): ?>
  <h2 style="margin-top:28px">最近の登録</h2>
  <p class="muted">当日はチャット経由の伝言になるため、誰がいつ何を登録したかを残しています。</p>
  <div class="table-scroll">
    <table class="table">
      <thead>
        <tr><th>登録時刻</th><th>体験プログラム</th><th>開催回</th><th>内容</th><th>登録者</th></tr>
      </thead>
      <tbody>
      <?php foreach ($recent as $row): ?>
        <?php $level = App\Domain\VacancyLevel::tryFrom((string) $row['level']); ?>
        <tr>
          <td class="muted"><?= e(substr((string) $row['reported_at'], 5, 11)) ?></td>
          <td>
            <span class="muted"><?= e($row['company_name']) ?></span><br>
            <?= e($row['event_title']) ?>
          </td>
          <td class="muted">
            <?php if ($row['starts_at'] !== null): ?>
              <?= e(jp_time((string) $row['starts_at'])) ?>〜<?= e(jp_time((string) $row['ends_at'])) ?>
            <?php else: ?>
              現在の状況
            <?php endif; ?>
          </td>
          <td>
            <?php if ($level !== null): ?>
              <?= e($level->mark()) ?> <?= e($level->shortLabel()) ?>
            <?php endif; ?>
            <?php if ($row['remaining'] !== null): ?>
              <span class="muted">残 <?= (int) $row['remaining'] ?></span>
            <?php endif; ?>
          </td>
          <td class="muted"><?= e($row['reported_by']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
