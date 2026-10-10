<?php

/**
 * The board. A whole document, not a page inside the public layout.
 *
 * Two readers, one markup:
 *
 *   signage  a wall nobody operates. It reloads itself, advances its own
 *            page, and carries nothing anybody would have to press.
 *   embed    an iframe on another site, or a phone. The box scrolls, so
 *            every row is here at once and nothing moves under the reader.
 *
 * @var array<int, array<string, mixed>> $rows   this page's slice
 * @var string      $date
 * @var string      $view     'now' | 'sessions'
 * @var string      $display  'signage' | 'embed'
 * @var string      $asOf     H:i this page was built
 * @var int         $page
 * @var int         $pages
 * @var int         $interval seconds before it reloads
 * @var string|null $nextUrl  where the reload goes; null reloads in place
 * @var bool        $preview  true for the rehearsal; the marks are invented
 * @var string|null $previewNote what the rehearsal banner says
 * @var string      $heading   the festival's name, with 'の空き状況' on it
 * @var int         $perPage   cards per page, which is NOT count($rows) on
 *                             the last page - see --sg-per below
 * @var array{area: ?string, words: array<int, string>, q: string, full: bool} $filter
 * @var array<int, string> $keywords  words offered as buttons
 */
use App\Domain\Area;

$preview ??= false;
$display ??= 'signage';
$nextUrl ??= null;
$heading ??= '当日の空き状況';
$perPage ??= count($rows);
$filter ??= ['area' => null, 'words' => [], 'q' => '', 'full' => true];
$keywords ??= [];
$previewNote ??= '表示テスト中　この画面のデータはすべて架空のものです';
?><!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= e($heading) ?></title>
<?php /*
  With a url= the refresh target is the NEXT page, and that single line is the
  entire paging mechanism: no JavaScript, no stored state, and a reload from
  any cause - power cut, browser restart - resumes somewhere valid. Without
  one it reloads in place, which is all an embedded board needs.
*/ ?>
<meta http-equiv="refresh" content="<?= (int) $interval ?><?= $nextUrl !== null ? '; url=' . e($nextUrl) : '' ?>">
<link rel="stylesheet" href="<?= url('/assets/css/signage.css') ?>">
</head>
<body class="sg sg--<?= e($display) ?>">

<?php if ($preview): ?>
  <?php /* Unmissable, and part of the page rather than a flag that could be
           left off: this URL needs no sign-in, so a rehearsal left running on
           the wall - or found by a visitor - has to say what it is without
           anybody having to remember to make it. */ ?>
  <div class="sg-preview"><?= e($previewNote) ?></div>
<?php endif; ?>

<header class="sg-head">
  <div class="sg-head__title">
    <?= e($heading) ?>
  </div>
  <div class="sg-head__meta">
    <span class="sg-head__asof"><?= e($asOf) ?> 現在</span>
    <?php if ($pages > 1): ?>
      <span class="sg-head__page"><?= (int) $page ?> / <?= (int) $pages ?></span>
    <?php endif; ?>
  </div>
</header>

<?php if ($display === 'embed'): ?>
  <?php /* Only here. A wall has nobody to press anything on it, and is
           aimed with the same parameters in its URL instead. */ ?>
  <div class="sg-filter">
    <?= App\Core\View::renderPartial('partials/vacancy_filter', [
          'base' => url('/vacancy'),
          'keep' => array_filter([
              'display' => 'embed',
              'date' => $date === App\Service\VacancyService::today() ? null : $date,
          ], static fn (mixed $v): bool => $v !== null),
          'filter' => $filter,
          'keywords' => $keywords,
          'areas' => $areas ?? [],
      ]) ?>
  </div>
<?php endif; ?>

<?php if ($rows === []): ?>
  <main class="sg-empty">ただいま掲載できる情報がありません</main>
<?php else: ?>
<?php /*
  --sg-per is how many cards this page holds. The stylesheet divides it by
  the column count it picked for the screen and sizes everything from the
  height that leaves. Without it a card was a fixed 12.5vh tall whatever room
  it had, which in one column was right for five cards and overlapped itself
  for any more - on a portrait wall exactly as badly as on a phone.
*/ ?>
<?php /*
  --sg-per is the PAGE size, not how many cards happen to be on this page.
  Passing the count made the last page - the one with the remainder on it -
  divide a whole screen between two cards, so the final page of every cycle
  came up in a different, enormous size with the titles broken over three
  lines. The stylesheet lays out a page's worth of rows and leaves the
  remainder of the screen empty, which is what the other pages look like.
*/ ?>
<main class="sg-grid" style="--sg-per: <?= max(count($rows), (int) $perPage) ?>">
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

      /*
       * Where the card goes when pressed: the programme's own 外部リンクURL,
       * and its page here when it has none - so every card leads somewhere
       * rather than a reader finding out by trial which ones are pressable.
       * Inert on a wall, which costs nothing.
       */
      $eventId = (int) ($row['event_id'] ?? $row['id']);
      $href = trim((string) ($row['external_url'] ?? ''));
      if ($href === '' && $eventId > 0) {
          $href = url('/events/' . $eventId);
      }
      $tag = $href !== '' ? 'a' : 'section';
    ?>
    <<?= $tag ?> class="<?= e($classes) ?>"<?= $href !== '' ? ' href="' . e($href) . '" target="_blank" rel="noopener noreferrer"' : '' ?>>
      <?php
        $area = ($row['area'] ?? null) !== null ? Area::labelFor((string) $row['area']) : null;
        // A round says when it is and nothing else: "15:00の回" is what a
        // visitor repeats to themselves walking over, and the end time is one
        // more number than a wall can afford.
        $slot = ($row['board_kind'] ?? 'booth') !== 'booth' && ($row['starts_at'] ?? null) !== null
            ? jp_time((string) $row['starts_at']) . 'の回'
            : null;
      ?>
      <div class="sg-card__meta">
        <span class="sg-card__company">
          <?= e($row['company_name']) ?><?php if ($area !== null): ?>（<?= e($area) ?>）<?php endif; ?>
        </span>
        <?php if ($slot !== null): ?>
          <span class="sg-card__slot"><?= e($slot) ?></span>
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
    </<?= $tag ?>>
  <?php endforeach; ?>
</main>
<?php endif; ?>

<footer class="sg-foot">
  当日券は各参加企業にてお渡ししています。表示は必ずしも最新の状況とは限りません
</footer>

</body>
</html>
