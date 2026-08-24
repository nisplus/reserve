<?php
/**
 * @var array<string, mixed> $event
 * @var array<int, array{date:string, sessions:array<int, array<string,mixed>>}> $days
 * @var int $total
 */
?>
<p class="breadcrumb"><a href="<?= url('/') ?>">イベント一覧</a> ／ <?= e($event['company_name']) ?></p>

<h1><?= e($event['title']) ?></h1>

<div class="panel">
  <dl class="detail-list">
    <dt>主催</dt><dd><?= e($event['company_name']) ?></dd>
    <?php if ($event['venue'] !== null && $event['venue'] !== ''): ?>
      <dt>会場</dt><dd><?= e($event['venue']) ?></dd>
    <?php endif; ?>
  </dl>
  <?php if ($event['description'] !== null && $event['description'] !== ''): ?>
    <p style="margin-top:14px"><?= enl($event['description']) ?></p>
  <?php endif; ?>
</div>

<h2>開催時間を選ぶ</h2>

<?php if ($total === 0): ?>
  <p class="empty">現在受付中の開催回はありません。</p>
<?php else: ?>
  <p class="muted">
    残席は表示時点のものです。お申し込みの確定時に改めて確認しますので、
    表示と異なる結果になる場合があります。
  </p>

  <?php foreach ($days as $day): ?>
    <section class="day-group">
      <h3><?= e(jp_date($day['date'])) ?></h3>
      <ul class="slot-list">
      <?php foreach ($day['sessions'] as $session): ?>
        <?php
          $seatsLeft = (int) $session['seats_left'];
          $isFull    = $seatsLeft === 0;
          $waiting   = (int) $session['waitlist_count'];
        ?>
        <li class="slot <?= $isFull ? 'slot--full' : '' ?>">
          <span class="slot-time">
            <?= e(jp_time((string) $session['starts_at'])) ?>〜<?= e(jp_time((string) $session['ends_at'])) ?>
          </span>

          <span class="slot-seats">
            <?php if ($isFull): ?>
              <span class="badge badge--bad">満席</span>
              <?php if ($waiting > 0): ?>
                <span class="muted">キャンセル待ち <?= $waiting ?> 件</span>
              <?php endif; ?>
            <?php elseif ($seatsLeft <= 3): ?>
              <span class="badge badge--warn">残り <?= $seatsLeft ?> 名</span>
            <?php else: ?>
              <span class="badge badge--ok">残り <?= $seatsLeft ?> 名</span>
            <?php endif; ?>
            <span class="muted">／ 定員 <?= (int) $session['capacity'] ?> 名</span>
          </span>

          <a class="btn btn--small <?= $isFull ? 'btn--ghost' : '' ?>"
             href="<?= url('/sessions/') ?><?= (int) $session['id'] ?>/apply">
            <?= $isFull ? 'キャンセル待ちで申し込む' : '申し込む' ?>
          </a>
        </li>
      <?php endforeach; ?>
      </ul>
    </section>
  <?php endforeach; ?>
<?php endif; ?>

<p><a href="<?= url('/') ?>">イベント一覧へ戻る</a></p>
