<?php
/** @var array<int, array{id:int, name:string, kana:?string, events:array<int, array<string,mixed>>}> $companies */
?>
<h1>イベント一覧</h1>
<p class="lead">主催会社ごとにイベントを掲載しています。参加したいイベントを選び、開催時間をお選びください。</p>
<p class="muted">同じ時間帯に重なる複数のイベントにはお申し込みいただけません。</p>

<?php if ($companies === []): ?>
  <p class="empty">現在公開中のイベントはありません。</p>
<?php endif; ?>

<?php foreach ($companies as $company): ?>
  <section class="company-block">
    <h2>
      <?= e($company['name']) ?>
      <?php if ($company['kana'] !== null && $company['kana'] !== ''): ?>
        <small class="muted"><?= e($company['kana']) ?></small>
      <?php endif; ?>
    </h2>

    <div class="card-grid">
    <?php foreach ($company['events'] as $event): ?>
      <?php
        $sessionCount = (int) $event['session_count'];
        $seatsLeft    = (int) $event['seats_left'];
      ?>
      <article class="card">
        <h3><a href="/events/<?= (int) $event['id'] ?>"><?= e($event['title']) ?></a></h3>

        <?php if ($event['venue'] !== null && $event['venue'] !== ''): ?>
          <p class="card-meta">会場: <?= e($event['venue']) ?></p>
        <?php endif; ?>

        <?php if ($sessionCount > 0): ?>
          <p class="card-meta">
            <?= e(jp_date((string) $event['first_starts_at'])) ?>
            ／ 全 <?= $sessionCount ?> 回
            （<?= e(jp_time((string) $event['first_starts_at'])) ?>〜<?= e(jp_time((string) $event['last_ends_at'])) ?>）
          </p>
        <?php else: ?>
          <p class="card-meta">開催回は準備中です。</p>
        <?php endif; ?>

        <p class="card-foot">
          <?php if ($sessionCount === 0): ?>
            <span class="badge badge--muted">受付前</span>
          <?php elseif ($seatsLeft === 0): ?>
            <span class="badge badge--bad">全回満席</span>
          <?php else: ?>
            <span class="badge badge--ok">空き <?= $seatsLeft ?> 名分</span>
          <?php endif; ?>
          <a class="btn btn--small btn--ghost" href="/events/<?= (int) $event['id'] ?>">開催時間を見る</a>
        </p>
      </article>
    <?php endforeach; ?>
    </div>
  </section>
<?php endforeach; ?>
