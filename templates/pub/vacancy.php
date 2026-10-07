<?php

use App\Service\VacancyService;

/**
 * @var array<int, array<string, mixed>> $rows
 * @var string $date
 * @var string $view  'now' | 'sessions'
 * @var string $asOf  H:i the page was built
 * @var int    $refresh seconds between automatic reloads
 */
$tab = static fn (string $v): string => url('/vacancy') . '?' . http_build_query(array_filter([
    'view' => $v === 'sessions' ? 'sessions' : null,
    'date' => $date === VacancyService::today() ? null : $date,
]));

/** One report, as a mark plus the words and the ticket count. */
$status = static function (?array $report, string $prefix = ''): string {
    if ($report === null) {
        return '<span class="vac-none">—</span>';
    }
    $level = $report['level'];
    $out = '<span class="badge ' . e($level->badgeClass()) . '">'
         . e($level->mark()) . ' ' . e($level->label()) . '</span>';
    if ($report['remaining'] !== null) {
        $out .= ' <span class="vac-remaining">残り ' . (int) $report['remaining'] . ' 枚</span>';
    }
    $out .= ' <span class="vac-when">' . e(substr((string) $report['reported_at'], 11, 5)) . ' 現在</span>';
    if ($prefix !== '') {
        $out = '<span class="vac-label">' . e($prefix) . '</span> ' . $out;
    }
    if ($report['is_stale']) {
        $out .= '<br><span class="vac-stale">⚠ ' . (int) floor($report['age_minutes'] / 60)
              . ' 時間以上更新がありません。情報が古い可能性があります</span>';
    }
    return $out;
};
?>
<?php /* A plain meta refresh: the page must keep itself current on a phone
         left on a table, and this works with no JavaScript at all. */ ?>
<meta http-equiv="refresh" content="<?= (int) $refresh ?>">

<h1>当日の空き状況</h1>

<p class="lead">
  <strong><?= e(jp_date($date)) ?></strong>
  <strong style="font-size:18px"><?= e($asOf) ?> 現在</strong>
  <span class="muted">（<?= (int) $refresh ?> 秒ごとに自動更新）</span>
</p>

<p class="muted">
  各社からご連絡いただいた状況を掲載しています。<strong>当日券は紙でお渡ししているため、
  予約システムの残席数とは異なります。</strong>お越しの際は現地の表示をご確認ください。
</p>

<div class="filter-bar" style="margin-bottom:16px">
  <a class="btn btn--small <?= $view === 'now' ? '' : 'btn--ghost' ?>" href="<?= e($tab('now')) ?>">いま空いているか</a>
  <a class="btn btn--small <?= $view === 'sessions' ? '' : 'btn--ghost' ?>" href="<?= e($tab('sessions')) ?>">開催回ごと</a>
</div>

<?php if ($rows === []): ?>
  <p class="empty">この日に開催される体験はありません。</p>

<?php elseif ($view === 'now'): ?>
  <?php $company = null; ?>
  <?php foreach ($rows as $row): ?>
    <?php if ($company !== (int) $row['company_id']): ?>
      <?php $company = (int) $row['company_id']; ?>
      <h2 class="vac-company"><?= e($row['company_name']) ?></h2>
    <?php endif; ?>
    <div class="vac-row">
      <div class="vac-row__name">
        <?= e($row['title']) ?>
        <?php if (($row['venue'] ?? '') !== ''): ?>
          <br><span class="muted" style="font-size:12px"><?= e($row['venue']) ?></span>
        <?php endif; ?>
      </div>
      <div class="vac-row__status"><?= $status($row['report']) ?></div>
    </div>
  <?php endforeach; ?>

<?php else: ?>
  <?php $slot = null; ?>
  <?php foreach ($rows as $row): ?>
    <?php $key = substr((string) $row['starts_at'], 11, 5) . '-' . substr((string) $row['ends_at'], 11, 5); ?>
    <?php if ($slot !== $key): ?>
      <?php $slot = $key; ?>
      <h2 class="vac-company">
        <?= e(jp_time((string) $row['starts_at'])) ?>〜<?= e(jp_time((string) $row['ends_at'])) ?>
        <?php if ($row['in_progress']): ?><span class="badge badge--ok">開催中</span><?php endif; ?>
      </h2>
    <?php endif; ?>
    <div class="vac-row">
      <div class="vac-row__name">
        <span class="muted"><?= e($row['company_name']) ?></span><br>
        <?= e($row['event_title']) ?>
      </div>
      <div class="vac-row__status">
        <?php if ($row['report'] !== null): ?>
          <?= $status($row['report']) ?>
        <?php elseif ($row['fallback'] !== null): ?>
          <?php /* Nothing about this slot. The booth's own state is still
                   worth showing, under a label that says what it is. */ ?>
          <?= $status($row['fallback'], 'この回の情報はありません。現在の状況:') ?>
        <?php else: ?>
          <span class="vac-none">—</span>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<p class="muted" style="margin-top:24px">
  「—」は、その時点でご連絡をいただいていないことを表します。空きがないという意味ではありません。
</p>
