<?php

use App\Domain\Area;

/**
 * @var array<int, array{id:int, name:string, kana:?string, events:array<int, array<string,mixed>>}> $companies
 * @var array<string, string> $areas          area value => label
 * @var array<int, array{id:int, name:string, area:?string}> $companyOptions
 * @var string|null           $area           Current area filter.
 * @var int                   $companyId      Current company filter (0 = none).
 * @var bool                  $filtered       Whether any filter is active.
 * @var \App\Domain\BookingClosedReason|null $closed Site-wide booking stop.
 */
/*
 * Defaulted rather than required, as the partials are. These pages are
 * rendered from tests and from two controllers, and a page that hard-requires
 * a variable makes every future caller learn about booking windows to render
 * an unrelated thing. Uninformed means "open": the stop is enforced in the
 * booking transaction, so what is lost by a caller that forgets is the notice,
 * not the rule - and the booking-window test renders the real path to check
 * the controllers do pass it.
 */
$closed ??= null;
$closedNotice ??= '';
?>
<h1>はいてくヒルズ2026 体験内容一覧</h1>
<p class="lead">開催企業ごとに体験できる内容を掲載しています。参加したい体験プログラムを選び、開催時間をお選びください。</p>
<p class="muted">同じ時間帯に重なる複数の体験プログラムはご予約いただけません。</p>
<p class="muted">一度にお申し込みいただける体験プログラムは1つです。1つずつ予約を完了させてから別の予約を始めて下さい</p>

<?php if ($closed !== null): ?>
  <div class="error-summary" role="alert">
    <p><?= enl($closedNotice) ?></p>
    <p class="muted">開催内容と時間は引き続きご覧いただけます。</p>
  </div>
<?php endif; ?>

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
        ? '条件に一致する体験プログラムがありません。絞り込みを解除してご覧ください。'
        : '現在公開中の体験プログラムはありません。' ?>
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
        // Seats a new applicant could take now: sessions with a queue
        // contribute none, because their free seats are owed to it.
        $seatsLeft    = (int) $event['seats_left'];
        $waitingCount = (int) ($event['waiting_count'] ?? 0);
        // 予約不要 events may still keep a timetable, so the card shows their
        // times like any other. What it does not show is 残席 - there is
        // nothing to reserve, so a seat count would be a number about nothing.
        $needsBooking = (int) $event['booking_required'] === 1;
      ?>
      <article class="card">
        <h3><a href="<?= url('/events/') ?><?= (int) $event['id'] ?>"><?= e($event['title']) ?></a></h3>

        <?php if ($event['venue'] !== null && $event['venue'] !== ''): ?>
          <p class="card-meta">会場: <?= e($event['venue']) ?></p>
        <?php endif; ?>

        <?php if ($sessionCount > 0): ?>
          <p class="card-meta">
            <?= e(jp_date((string) $event['first_starts_at'])) ?>
            ／ 全 <?= $sessionCount ?> 回
            （<?= e(jp_time((string) $event['first_starts_at'])) ?>〜<?= e(jp_time((string) $event['last_ends_at'])) ?>）
          </p>
        <?php elseif (!$needsBooking): ?>
          <p class="card-meta">ご予約なしでご参加いただけます。</p>
        <?php else: ?>
          <p class="card-meta">開催回は準備中です。</p>
        <?php endif; ?>

        <p class="card-foot">
          <?= App\Core\View::renderPartial('partials/event_availability', [
                'needsBooking' => $needsBooking,
                'sessionCount' => $sessionCount,
                'seatsLeft'    => $seatsLeft,
                'waitingCount' => $waitingCount,
                'closed'       => $closed,
              ]) ?>
          <a class="btn btn--small btn--ghost" href="<?= url('/events/') ?><?= (int) $event['id'] ?>">
            <?= $needsBooking || $sessionCount > 0 ? '開催時間を見る' : '詳細を見る' ?>
          </a>
        </p>
      </article>
    <?php endforeach; ?>
    </div>
  </section>
<?php endforeach; ?>
