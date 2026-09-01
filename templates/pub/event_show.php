<?php
/**
 * @var array<string, mixed> $event
 * @var array<int, array{date:string, sessions:array<int, array<string,mixed>>}> $days
 * @var int $total
 */
$needsBooking = (int) $event['booking_required'] === 1;
$externalUrl  = (string) ($event['external_url'] ?? '');
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

<?php if (!$needsBooking): ?>
  <?php /* 予約不要: no slot list at all - not even an empty one, because
           "受付中の開催回はありません" would read as a temporary state rather
           than the point. The external link, when set, is the call to action. */ ?>
  <h2>ご参加について</h2>
  <p class="lead">このイベントは<strong>予約不要</strong>です。当日、直接会場までお越しください。</p>

  <?php if ($externalUrl !== ''): ?>
    <p class="form-actions">
      <a class="btn" href="<?= e($externalUrl) ?>" target="_blank" rel="noopener noreferrer">
        詳細を見る（外部サイト）
      </a>
    </p>
    <p class="muted">リンク先は主催会社のサイトです。新しいタブで開きます。</p>
  <?php endif; ?>

<?php elseif ($total === 0): ?>
  <h2>開催時間を選ぶ</h2>
  <p class="empty">現在受付中の開催回はありません。</p>
<?php else: ?>
  <h2>開催時間を選ぶ</h2>
  <p class="muted">
    残席は表示時点のものです。ご予約の確定時に改めて確認しますので、
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
            <?= $isFull ? 'キャンセル待ちで予約する' : '予約する' ?>
          </a>
        </li>
      <?php endforeach; ?>
      </ul>
    </section>
  <?php endforeach; ?>
<?php endif; ?>

<p><a href="<?= url('/') ?>">イベント一覧へ戻る</a></p>
