<?php

/**
 * The wall display. A whole document, not a page inside the public layout.
 *
 * Nobody operates this screen, so everything it needs to keep working for a
 * day unattended is here: it reloads itself, it advances its own page, and it
 * carries no link, tab or control that somebody would have to press.
 *
 * @var array<int, array<string, mixed>> $rows   this page's slice
 * @var string $date
 * @var string $view     'now' | 'sessions'
 * @var string $asOf     H:i this page was built
 * @var int    $page
 * @var int    $pages
 * @var int    $interval seconds before moving to the next page
 * @var string $nextUrl  where the refresh goes: the next page, wrapping
 */
?><!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>当日の空き状況</title>
<?php /*
  The refresh target is the NEXT page rather than this one. That single line
  is the entire paging mechanism: no JavaScript, no stored state, and a reload
  from any cause - power cut, browser restart - resumes somewhere valid.
*/ ?>
<meta http-equiv="refresh" content="<?= (int) $interval ?>; url=<?= e($nextUrl) ?>">
<link rel="stylesheet" href="<?= url('/assets/css/signage.css') ?>">
</head>
<body class="sg">

<header class="sg-head">
  <div class="sg-head__title">
    当日の空き状況
    <?php if ($view === 'sessions'): ?><span class="sg-head__sub">開催回ごと</span><?php endif; ?>
  </div>
  <div class="sg-head__meta">
    <span class="sg-head__asof"><?= e($asOf) ?> 現在</span>
    <?php if ($pages > 1): ?>
      <span class="sg-head__page"><?= (int) $page ?> / <?= (int) $pages ?></span>
    <?php endif; ?>
  </div>
</header>

<?php if ($rows === []): ?>
  <main class="sg-empty">ただいま掲載できる情報がありません</main>
<?php else: ?>
<main class="sg-grid">
  <?php foreach ($rows as $row): ?>
    <?php
      // A slot with no report of its own falls back to the booth's state. The
      // card says which it is showing, because they are different claims.
      $report = $row['report'] ?? null;
      $isFallback = false;
      if ($report === null && ($row['fallback'] ?? null) !== null) {
          $report = $row['fallback'];
          $isFallback = true;
      }
      $level = $report['level'];
      $classes = 'sg-card ' . $level->signageClass() . ($report['is_stale'] ? ' sg-card--stale' : '');
    ?>
    <section class="<?= e($classes) ?>">
      <div class="sg-card__company">
        <?= e($row['company_name']) ?>
        <?php if ($view === 'sessions'): ?>
          <span class="sg-card__slot">
            <?= e(jp_time((string) $row['starts_at'])) ?>〜<?= e(jp_time((string) $row['ends_at'])) ?>
          </span>
        <?php endif; ?>
      </div>
      <div class="sg-card__event"><?= e($row['event_title'] ?? $row['title']) ?></div>

      <div class="sg-card__mark"><?= e($level->mark()) ?></div>
      <div class="sg-card__label">
        <?= e($level->label()) ?>
        <?php if ($report['remaining'] !== null): ?>
          <span class="sg-card__remaining">残り <?= (int) $report['remaining'] ?> 枚</span>
        <?php endif; ?>
      </div>

      <div class="sg-card__when">
        <?php if ($isFallback): ?>現在の状況 <?php endif; ?>
        <?= e(substr((string) $report['reported_at'], 11, 5)) ?> 現在
        <?php if ($report['is_stale']): ?>
          <span class="sg-card__stale">情報が古い可能性があります</span>
        <?php endif; ?>
      </div>
    </section>
  <?php endforeach; ?>
</main>
<?php endif; ?>

<footer class="sg-foot">
  当日券は紙でお渡ししています。表示は各社からのご連絡によるもので、予約システムの残席数とは異なります。
</footer>

</body>
</html>
