<?php

use App\Core\Csrf;
use App\Domain\BookingStatus;

/**
 * @var array<string, mixed> $booking findByTokenHash row, with event context.
 * @var string               $token   Raw token, needed to build the cancel URL.
 * @var array<int, string>   $attendees Names, applicant first.
 *
 * The token appears only inside form actions on this page (the visitor already
 * has it - it is the URL they are on). It is never rendered as visible text.
 */
$status = BookingStatus::from((string) $booking['status']);
?>
<h1>予約内容の確認</h1>

<?php if ($status === BookingStatus::Waitlisted): ?>
  <p class="lead">
    現在、キャンセル待ち <strong><?= (int) $booking['waitlist_position'] ?> 番目</strong>です。
    お席をご用意できるようになりましたら、メールでご連絡します。
  </p>
<?php elseif ($status === BookingStatus::Cancelled): ?>
  <p class="lead">この予約はキャンセル済みです。再度のお申し込みはイベント一覧から行えます。</p>
<?php endif; ?>

<div class="panel">
  <dl class="detail-list">
    <dt>予約番号</dt><dd><strong><?= e($booking['reference_code']) ?></strong></dd>
    <dt>状態</dt>
    <dd><span class="badge <?= e($status->badgeClass()) ?>"><?= e($status->label()) ?></span></dd>
    <dt>イベント</dt><dd><?= e($booking['event_title']) ?></dd>
    <dt>主催</dt><dd><?= e($booking['company_name']) ?></dd>
    <dt>日時</dt>
    <dd><?= e(jp_datetime((string) $booking['starts_at'])) ?>〜<?= e(jp_time((string) $booking['ends_at'])) ?></dd>
    <?php if (($booking['venue'] ?? '') !== '' && $booking['venue'] !== null): ?>
      <dt>会場</dt><dd><?= e($booking['venue']) ?></dd>
    <?php endif; ?>
    <dt>お名前</dt><dd><?= e($booking['name']) ?></dd>
    <dt>メールアドレス</dt><dd><?= e($booking['email']) ?></dd>
    <dt>参加人数</dt><dd><?= (int) $booking['party_size'] ?> 名</dd>
    <?php if (count($attendees) > 1): ?>
      <dt>ご参加者</dt>
      <dd>
        <ol style="margin:0;padding-left:1.4em">
          <?php foreach ($attendees as $attendee): ?><li><?= e($attendee) ?></li><?php endforeach; ?>
        </ol>
      </dd>
    <?php endif; ?>
    <?php if ($status === BookingStatus::Cancelled && $booking['cancelled_at'] !== null): ?>
      <dt>キャンセル日時</dt><dd><?= e(jp_datetime((string) $booking['cancelled_at'])) ?></dd>
    <?php endif; ?>
  </dl>
</div>

<?php if ($status !== BookingStatus::Cancelled): ?>
  <h2>キャンセル</h2>
  <p class="muted">
    キャンセルすると元に戻せません。参加をやめる場合のみ、下のボタンを押してください。
    <?php if ($status === BookingStatus::Confirmed): ?>
      キャンセルで空いたお席は、キャンセル待ちの方のご案内に使われることがあります。
    <?php endif; ?>
  </p>
  <form method="post" action="<?= url('/manage/') ?><?= e($token) ?>/cancel">
    <?= Csrf::field() ?>
    <div class="form-actions">
      <button type="submit" class="btn btn--danger">この予約をキャンセルする</button>
      <a class="btn btn--ghost" href="<?= url('/') ?>">イベント一覧へ</a>
    </div>
  </form>
<?php else: ?>
  <p><a class="btn btn--ghost" href="<?= url('/') ?>">イベント一覧へ</a></p>
<?php endif; ?>
