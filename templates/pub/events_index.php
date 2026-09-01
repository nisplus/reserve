<?php

use App\Domain\Area;

/**
 * @var array<int, array{id:int, name:string, kana:?string, events:array<int, array<string,mixed>>}> $companies
 * @var array<string, string> $areas          area value => label
 * @var array<int, array{id:int, name:string, area:?string}> $companyOptions
 * @var string|null           $area           Current area filter.
 * @var int                   $companyId      Current company filter (0 = none).
 * @var bool                  $filtered       Whether any filter is active.
 */
?>
<h1>イベント一覧</h1>
<p class="lead">主催会社ごとにイベントを掲載しています。参加したいイベントを選び、開催時間をお選びください。</p>
<p class="muted">同じ時間帯に重なる複数のイベントはご予約いただけません。</p>

<?php /* GET, so filtering leaves the state in the address bar and the result
         is a link anyone can be sent. */ ?>
<form method="get" action="<?= url('/') ?>">
  <div class="filter-bar" style="margin-bottom:16px">
    <div class="field">
      <label for="area">エリア</label>
      <select id="area" name="area" onchange="this.form.submit()">
        <option value="">すべてのエリア</option>
        <?php foreach ($areas as $value => $label): ?>
          <option value="<?= e($value) ?>" <?= $area === $value ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="field">
      <label for="company">会社</label>
      <select id="company" name="company" onchange="this.form.submit()">
        <option value="0">すべての会社</option>
        <?php foreach ($companyOptions as $option): ?>
          <option value="<?= $option['id'] ?>" <?= $companyId === $option['id'] ? 'selected' : '' ?>>
            <?= e($option['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <noscript><button type="submit" class="btn btn--small">絞り込む</button></noscript>
    <?php if ($filtered): ?>
      <a class="btn btn--ghost btn--small" href="<?= url('/') ?>">絞り込みを解除</a>
    <?php endif; ?>
  </div>
</form>

<?php if ($filtered): ?>
  <p class="muted">
    <?= $area !== null ? e(Area::labelFor($area)) : 'すべてのエリア' ?>
    <?php if ($companyId > 0): ?>
      ／
      <?php
        $selected = '';
        foreach ($companyOptions as $option) {
            if ($option['id'] === $companyId) {
                $selected = $option['name'];
            }
        }
      ?>
      <?= $selected !== '' ? e($selected) : '該当なし' ?>
    <?php endif; ?>
    で絞り込み中。<strong>この画面のURLをそのまま共有できます。</strong>
  </p>
<?php endif; ?>

<?php if ($companies === []): ?>
  <p class="empty">
    <?= $filtered
        ? '条件に一致するイベントがありません。絞り込みを解除してご覧ください。'
        : '現在公開中のイベントはありません。' ?>
  </p>
<?php endif; ?>

<?php foreach ($companies as $company): ?>
  <section class="company-block">
    <h2>
      <?= e($company['name']) ?>
      <?php if ($company['kana'] !== null && $company['kana'] !== ''): ?>
        <small class="muted"><?= e($company['kana']) ?></small>
      <?php endif; ?>
      <?php if (($company['area'] ?? null) !== null): ?>
        <span class="badge badge--muted"><?= e(Area::labelFor($company['area'])) ?></span>
      <?php endif; ?>
    </h2>

    <div class="card-grid">
    <?php foreach ($company['events'] as $event): ?>
      <?php
        $sessionCount = (int) $event['session_count'];
        $seatsLeft    = (int) $event['seats_left'];
        // 予約不要: nothing to reserve, so seats and slot counts say nothing
        // useful. The card carries the badge and sends people to the detail
        // page for whatever the host wants to tell them.
        $needsBooking = (int) $event['booking_required'] === 1;
      ?>
      <article class="card">
        <h3><a href="<?= url('/events/') ?><?= (int) $event['id'] ?>"><?= e($event['title']) ?></a></h3>

        <?php if ($event['venue'] !== null && $event['venue'] !== ''): ?>
          <p class="card-meta">会場: <?= e($event['venue']) ?></p>
        <?php endif; ?>

        <?php if (!$needsBooking): ?>
          <p class="card-meta">ご予約なしでご参加いただけます。</p>
        <?php elseif ($sessionCount > 0): ?>
          <p class="card-meta">
            <?= e(jp_date((string) $event['first_starts_at'])) ?>
            ／ 全 <?= $sessionCount ?> 回
            （<?= e(jp_time((string) $event['first_starts_at'])) ?>〜<?= e(jp_time((string) $event['last_ends_at'])) ?>）
          </p>
        <?php else: ?>
          <p class="card-meta">開催回は準備中です。</p>
        <?php endif; ?>

        <p class="card-foot">
          <?php if (!$needsBooking): ?>
            <span class="badge badge--muted">予約不要</span>
          <?php elseif ($sessionCount === 0): ?>
            <span class="badge badge--muted">受付前</span>
          <?php elseif ($seatsLeft === 0): ?>
            <span class="badge badge--bad">全回満席</span>
          <?php else: ?>
            <span class="badge badge--ok">空き <?= $seatsLeft ?> 名分</span>
          <?php endif; ?>
          <a class="btn btn--small btn--ghost" href="<?= url('/events/') ?><?= (int) $event['id'] ?>">
            <?= $needsBooking ? '開催時間を見る' : '詳細を見る' ?>
          </a>
        </p>
      </article>
    <?php endforeach; ?>
    </div>
  </section>
<?php endforeach; ?>
