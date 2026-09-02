<?php

use App\Domain\BookingStatus;

/**
 * @var array<string, mixed> $booking   findByReference row, with event context.
 * @var array<int, string>   $attendees Names, applicant first. May be shorter
 *                                      than party_size when not all were given.
 *
 * The cancel token is deliberately absent from this page: it goes into the
 * e-mail and nowhere else, so someone glancing at the screen cannot take over
 * the booking. The reference code alone grants no rights.
 */
$status = BookingStatus::from((string) $booking['status']);
?>
<h1>
  <?= $status === BookingStatus::Waitlisted ? 'キャンセル待ちで受け付けました' : 'ご予約を受け付けました' ?>
</h1>

<?php if ($status === BookingStatus::Waitlisted): ?>
  <p class="lead">
    現在、キャンセル待ち <strong><?= (int) $booking['waitlist_position'] ?> 番目</strong>です。
    お席をご用意できるようになりましたら、メールでご連絡します。
  </p>
<?php elseif ($status === BookingStatus::Confirmed): ?>
  <p class="lead">ご参加が確定しました。当日のご来場をお待ちしています。</p>
<?php endif; ?>

<div class="panel">
  <dl class="detail-list">
    <dt>予約番号</dt><dd><strong><?= e($booking['reference_code']) ?></strong></dd>
    <dt>状態</dt>
    <dd><span class="badge <?= e($status->badgeClass()) ?>"><?= e($status->label()) ?></span></dd>
    <dt>イベント</dt><dd><?= e($booking['event_title']) ?></dd>
    <dt>開催企業</dt><dd><?= e($booking['company_name']) ?></dd>
    <dt>日時</dt>
    <dd><?= e(jp_datetime((string) $booking['starts_at'])) ?>〜<?= e(jp_time((string) $booking['ends_at'])) ?></dd>
    <?php if (($booking['venue'] ?? '') !== '' && $booking['venue'] !== null): ?>
      <dt>会場</dt><dd><?= e($booking['venue']) ?></dd>
    <?php endif; ?>
    <dt>電話番号</dt><dd><?= e($booking['phone']) ?></dd>
    <dt>参加人数</dt><dd><?= (int) $booking['party_size'] ?> 名</dd>
    <?php if ($attendees !== []): ?>
      <dt>ご参加者</dt>
      <dd>
        <ol style="margin:0;padding-left:1.4em">
          <?php foreach ($attendees as $attendee): ?>
            <li><?= e($attendee['name']) ?><?php if ($attendee['age'] !== null): ?>（<?= $attendee['age'] ?> 歳）<?php endif; ?></li>
          <?php endforeach; ?>
        </ol>
      </dd>
    <?php endif; ?>
    <?php if (trim((string) ($booking['message'] ?? '')) !== ''): ?>
      <dt>開催企業へのメッセージ</dt>
      <dd><?= enl($booking['message']) ?></dd>
    <?php endif; ?>
  </dl>
</div>

<p>
  <strong><?= e($booking['email']) ?></strong> 宛に確認メールをお送りしました。
  予約内容の確認とキャンセルは、メールに記載のURLから行えます。
  メールが届かない場合は、迷惑メールフォルダもご確認ください。
</p>

<p><a href="<?= url('/') ?>">イベント一覧へ戻る</a></p>
