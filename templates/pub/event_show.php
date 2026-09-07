<?php
/**
 * @var array<string, mixed> $event
 * @var array<int, array{date:string, sessions:array<int, array<string,mixed>>}> $days
 * @var int $total
 */
$needsBooking = (int) $event['booking_required'] === 1;
$externalUrl  = (string) ($event['external_url'] ?? '');
?>
<p class="breadcrumb"><a href="<?= url('/') ?>">体験一覧</a> ／ <?= e($event['company_name']) ?></p>

<h1><?= e($event['title']) ?></h1>

<div class="panel">
  <dl class="detail-list">
    <dt>開催企業</dt><dd><?= e($event['company_name']) ?></dd>
    <?php if ($event['venue'] !== null && $event['venue'] !== ''): ?>
      <dt>会場</dt><dd><?= e($event['venue']) ?></dd>
    <?php endif; ?>
    <?php /* For a bookable event the link is supplementary information and
             belongs in this list. For a 予約不要 event it is the call to
             action and appears as a button further down instead. Blank in
             either case shows nothing. */ ?>
    <?php if ($needsBooking && $externalUrl !== ''): ?>
      <dt>詳細</dt>
      <dd>
        <a href="<?= e($externalUrl) ?>" target="_blank" rel="noopener noreferrer">
          開催企業の紹介ページで見る
        </a>
        <span class="muted">（新しいタブで開きます）</span>
      </dd>
    <?php endif; ?>
  </dl>
  <?php if ($event['description'] !== null && $event['description'] !== ''): ?>
    <p style="margin-top:14px"><?= enl($event['description']) ?></p>
  <?php endif; ?>
</div>

<?php if (!$needsBooking): ?>
  <h2>ご参加について</h2>
  <p class="lead">この体験プログラムは<strong>予約不要</strong>です。当日、直接会場までお越しください。整理券配布の情報は公式サイトにてお知らせします。</p>

  <?php if ($externalUrl !== ''): ?>
    <p class="form-actions">
      <a class="btn" href="<?= e($externalUrl) ?>" target="_blank" rel="noopener noreferrer">
        詳細を見る
      </a>
    </p>
    <p class="muted">リンク先ははいてくヒルズ公式サイトです。新しいタブで開きます。</p>
  <?php endif; ?>
<?php endif; ?>

<?php
/*
 * The slot list is shown either way. For a 予約不要 event it is a timetable -
 * when to turn up - so it carries no seat counts and no buttons: 残り 12 名 on
 * something nobody reserves would be a number with no meaning behind it, and a
 * 予約する button would lead to a page that refuses.
 *
 * A 予約不要 event with no slots shows nothing rather than an empty list. Here
 * "受付中の開催回はありません" would read as a temporary state instead of the
 * point, which is what it means for a bookable event.
 */
?>
<?php if ($total === 0): ?>
  <?php if ($needsBooking): ?>
    <h2>開催時間を選ぶ</h2>
    <p class="empty">現在受付中の開催回はありません。</p>
  <?php endif; ?>
<?php else: ?>
  <h2><?= $needsBooking ? '開催時間を選ぶ' : '開催時間' ?></h2>
  <?php if ($needsBooking): ?>
    <p class="muted">
      残席は表示時点のものです。ご予約の確定時に改めて確認しますので、
      ご予約確定時に空き状況が変わって予約が既に終了している場合があります。
    </p>
  <?php else: ?>
    <p class="muted">ご予約は不要です。下記の時間内に、直接会場までお越しください。</p>
  <?php endif; ?>

  <?php foreach ($days as $day): ?>
    <section class="day-group">
      <h3><?= e(jp_date($day['date'])) ?></h3>
      <ul class="slot-list">
      <?php foreach ($day['sessions'] as $session): ?>
        <?php
          $seatsLeft = (int) $session['seats_left'];
          $isFull    = $needsBooking && $seatsLeft === 0;
          $waiting   = (int) $session['waitlist_count'];
        ?>
        <li class="slot <?= $isFull ? 'slot--full' : '' ?>">
          <span class="slot-time">
            <?= e(jp_time((string) $session['starts_at'])) ?>〜<?= e(jp_time((string) $session['ends_at'])) ?>
          </span>

          <?php if ($needsBooking): ?>
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
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
      </ul>
    </section>
  <?php endforeach; ?>
<?php endif; ?>

<p><a href="<?= url('/') ?>">一覧へ戻る</a></p>
