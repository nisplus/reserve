<?php

/**
 * One session's availability, as a badge plus a qualifier.
 *
 * Extracted because this markup had drifted: the same three states were
 * written out in the event page, the booking form and the admin session list,
 * and when キャンセル待ち受付中 was shortened only two of the three were
 * updated - the list card announced the old wording for a week. Anything that
 * shows availability renders this instead.
 *
 * The order of the branches is the rule, not a presentation choice: a session
 * with anyone waiting cannot be booked however many seats are free, because
 * those seats belong to the queue (BookingService::wouldWaitlist). Saying
 * 残り 2 名 there is the misreport that let a cancelled seat look available to
 * whoever came next.
 *
 * @var int  $seatsLeft
 * @var int  $waiting      Live count of waitlisted bookings on this session.
 * @var int  $capacity
 * @var bool $showCapacity Whether to print ／ 定員 N 名 after the count. The
 *                         admin list has its own 定員 column, so it passes
 *                         false rather than saying it twice.
 */
$showCapacity ??= true;
?>
<?php if ($waiting > 0): ?>
  <span class="badge badge--warn">キャンセル待ち</span>
  <span class="muted">現在 <?= (int) $waiting ?> 件</span>
<?php elseif ($seatsLeft === 0): ?>
  <span class="badge badge--bad">満席</span>
<?php else: ?>
  <span class="badge <?= $seatsLeft <= 3 ? 'badge--warn' : 'badge--ok' ?>">残り <?= (int) $seatsLeft ?> 名</span>
  <?php if ($showCapacity): ?>
    <span class="muted">／ 定員 <?= (int) $capacity ?> 名</span>
  <?php endif; ?>
<?php endif; ?>
