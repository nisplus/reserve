<?php

/**
 * One event's availability, summed over its sessions.
 *
 * The per-session sibling is partials/seat_status.php; this is the event-level
 * summary, and it is a separate partial because the wording is genuinely
 * different - 全回満席 is a claim about every session, not about one.
 *
 * Extracted for the same reason as the other one: the same four states now
 * have to read alike on the public catalogue and the admin programme list,
 * and the last time this markup lived in two places the two drifted.
 *
 * The precedence is the rule, not a presentation choice. A session whose free
 * seats are held by its queue contributes none of them to $seatsLeft (see
 * EventRepository::publishedCatalogue), so an event that is entirely queued
 * arrives here as 0 seats with a waiting count - and must not read 全回満席,
 * because seats exist, nor 空き N 名分, because none can be booked.
 *
 * @var bool $needsBooking
 * @var int  $sessionCount  Sessions that could take a booking (open ones).
 * @var int  $seatsLeft     Seats a new applicant could actually take.
 * @var int  $waitingCount  People queued across the event's sessions.
 * @var \App\Domain\BookingClosedReason|null $closed Site-wide booking stop.
 *                          Outranks the seat totals for the same reason it
 *                          does per session, but NOT 予約不要: an event that
 *                          never took bookings is unaffected by bookings
 *                          being stopped, and saying 受付終了 about it would
 *                          be telling people to stay away from something they
 *                          can still walk into.
 */
$closed ??= null;
?>
<?php if (!$needsBooking): ?>
  <span class="badge badge--muted">予約不要</span>
<?php elseif ($closed !== null): ?>
  <span class="badge badge--muted"><?= e($closed->badgeLabel()) ?></span>
<?php elseif ($sessionCount === 0): ?>
  <span class="badge badge--muted">受付前</span>
<?php elseif ($seatsLeft === 0 && $waitingCount > 0): ?>
  <span class="badge badge--warn">キャンセル待ち</span>
<?php elseif ($seatsLeft === 0): ?>
  <span class="badge badge--bad">全回満席</span>
<?php else: ?>
  <span class="badge badge--ok">空き <?= (int) $seatsLeft ?> 名分</span>
<?php endif; ?>
