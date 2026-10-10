<?php

use App\Domain\Area;

/**
 * The narrowing controls, as links.
 *
 * Links and not a form, because nothing on this feature uses JavaScript: the
 * wall has to keep working on whatever browser the venue's display stick
 * runs, and a screen that needed script for half its controls would be a
 * screen that half-works there. A link also survives being bookmarked, which
 * is how a company ends up with "our hall" on a phone propped at the desk.
 *
 * Shown on the page and in an embed. The wall gets none of it - nobody can
 * press anything on a wall - and is aimed with the same parameters in its URL.
 *
 * @var string $base    where the links point, without a query
 * @var array<string, string> $keep  query parameters to carry through
 * @var array{area: ?string, words: array<int, string>, q: string, full: bool} $filter
 * @var array<int, string> $keywords
 */
$link = static function (array $changed) use ($base, $keep, $filter): string {
    $query = $keep + array_filter([
        'area' => $filter['area'],
        'q'    => $filter['q'] !== '' ? $filter['q'] : null,
        'none' => $filter['full'] ? null : '0',
    ], static fn (mixed $v): bool => $v !== null);

    foreach ($changed as $name => $value) {
        if ($value === null) {
            unset($query[$name]);
        } else {
            $query[$name] = $value;
        }
    }

    return $base . ($query === [] ? '' : '?' . http_build_query($query));
};

$areas = Area::cases();
?>
<div class="vac-filter">
  <div class="vac-filter__row">
    <span class="vac-filter__label">エリア</span>
    <a class="btn btn--small <?= $filter['area'] === null ? '' : 'btn--ghost' ?>"
       href="<?= e($link(['area' => null])) ?>">すべて</a>
    <?php foreach ($areas as $area): ?>
      <a class="btn btn--small <?= $filter['area'] === $area->value ? '' : 'btn--ghost' ?>"
         href="<?= e($link(['area' => $area->value])) ?>"><?= e($area->label()) ?></a>
    <?php endforeach; ?>
  </div>

  <?php if ($keywords !== []): ?>
    <div class="vac-filter__row">
      <span class="vac-filter__label">内容</span>
      <a class="btn btn--small <?= $filter['q'] === '' ? '' : 'btn--ghost' ?>"
         href="<?= e($link(['q' => null])) ?>">すべて</a>
      <?php foreach ($keywords as $word): ?>
        <a class="btn btn--small <?= $filter['q'] === $word ? '' : 'btn--ghost' ?>"
           href="<?= e($link(['q' => $word])) ?>"><?= e($word) ?></a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="vac-filter__row">
    <span class="vac-filter__label">表示</span>
    <a class="btn btn--small <?= $filter['full'] ? '' : 'btn--ghost' ?>"
       href="<?= e($link(['none' => null])) ?>">すべて</a>
    <?php /* ✕ is an answer, so it is shown by default; this is for a screen
             with more programmes on it than room, where the space is better
             spent on the ones somebody can still get into. */ ?>
    <a class="btn btn--small <?= $filter['full'] ? 'btn--ghost' : '' ?>"
       href="<?= e($link(['none' => '0'])) ?>">空きのみ</a>
  </div>
</div>
