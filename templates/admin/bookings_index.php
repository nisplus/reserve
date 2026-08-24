<?php

use App\Core\Csrf;
use App\Domain\BookingStatus;

/**
 * @var array<int, array<string, mixed>> $rows
 * @var int                              $total
 * @var int                              $page
 * @var int                              $pages
 * @var array<string, mixed>             $filters
 * @var array<int, string>               $options  company id => name
 * @var array<int, array<string, mixed>> $events   events for the event select
 * @var array<int, array<string, mixed>> $sessions sessions of the chosen event ([] otherwise)
 */
$query = http_build_query(array_filter([
    'company' => (int) $filters['company_id'] ?: null,
    'event'   => (int) $filters['event_id'] ?: null,
    'session' => (int) $filters['session_id'] ?: null,
    'status'  => (string) $filters['status'] ?: null,
    'email'   => (string) $filters['email'] ?: null,
]));
$pageUrl = static fn (int $p): string => url('/admin/bookings') . '?' . ($query !== '' ? $query . '&' : '') . 'page=' . $p;
?>
<h1>申込一覧</h1>

<form method="get" action="<?= url('/admin/bookings') ?>">
  <div class="filter-bar" style="margin-bottom:16px">
    <?php /* Empty for a company account: the list is already confined. */ ?>
    <?php if ($options !== []): ?>
      <div class="field">
        <label for="company">会社</label>
        <select id="company" name="company" onchange="this.form.submit()">
          <option value="0">すべて</option>
          <?php foreach ($options as $id => $name): ?>
            <option value="<?= (int) $id ?>" <?= (int) $filters['company_id'] === (int) $id ? 'selected' : '' ?>><?= e($name) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php endif; ?>
    <div class="field">
      <label for="event">イベント</label>
      <select id="event" name="event" onchange="this.form.submit()">
        <option value="0">すべて</option>
        <?php foreach ($events as $event): ?>
          <option value="<?= (int) $event['id'] ?>" <?= (int) $filters['event_id'] === (int) $event['id'] ? 'selected' : '' ?>>
            <?= e($event['title']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php if ($sessions !== []): ?>
      <div class="field">
        <label for="session">開催回</label>
        <select id="session" name="session" onchange="this.form.submit()">
          <option value="0">すべて</option>
          <?php foreach ($sessions as $session): ?>
            <option value="<?= (int) $session['id'] ?>" <?= (int) $filters['session_id'] === (int) $session['id'] ? 'selected' : '' ?>>
              <?= e(jp_time((string) $session['starts_at'])) ?>〜<?= e(jp_time((string) $session['ends_at'])) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php endif; ?>
    <div class="field">
      <label for="status">状態</label>
      <select id="status" name="status" onchange="this.form.submit()">
        <option value="">すべて</option>
        <?php foreach (BookingStatus::cases() as $case): ?>
          <option value="<?= e($case->value) ?>" <?= $filters['status'] === $case->value ? 'selected' : '' ?>><?= e($case->label()) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="email">メールアドレス</label>
      <input type="text" id="email" name="email" value="<?= e($filters['email']) ?>" placeholder="部分一致">
    </div>
    <button type="submit" class="btn btn--small">絞り込む</button>
    <a class="btn btn--ghost btn--small" href="<?= url('/admin/bookings') ?>">解除</a>
    <a class="btn btn--ghost btn--small" href="<?= url('/admin/bookings/export') ?><?= $query !== '' ? '?' . e($query) : '' ?>">CSV 出力</a>
  </div>
</form>

<p class="muted"><?= number_format($total) ?> 件<?= $pages > 1 ? "（{$page} / {$pages} ページ）" : '' ?></p>

<?php if ($rows === []): ?>
  <p class="empty">条件に一致する申込がありません。</p>
<?php else: ?>
<div class="table-scroll">
  <table class="table">
    <thead>
      <tr><th>申込日時</th><th>予約番号</th><th>状態</th><th>イベント</th><th>開催日時</th><th>氏名</th><th>メール</th><th>人数</th><th></th></tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $row): ?>
      <?php $status = BookingStatus::from((string) $row['status']); ?>
      <tr>
        <td class="muted"><?= e(substr((string) $row['created_at'], 5, 11)) ?></td>
        <td><?= e($row['reference_code']) ?></td>
        <td>
          <span class="badge <?= e($status->badgeClass()) ?>"><?= e($status->label()) ?></span>
          <?php if ($status === BookingStatus::Waitlisted): ?>
            <span class="muted"><?= (int) $row['waitlist_seq'] ?> 番</span>
          <?php endif; ?>
        </td>
        <td>
          <span class="muted"><?= e($row['company_name']) ?></span><br>
          <?= e($row['event_title']) ?>
        </td>
        <td><?= e(jp_datetime((string) $row['starts_at'])) ?>〜<?= e(jp_time((string) $row['ends_at'])) ?></td>
        <td><?= e($row['name']) ?></td>
        <td class="muted"><?= e($row['email']) ?></td>
        <td><?= (int) $row['party_size'] ?></td>
        <td>
          <?php if ($status === BookingStatus::Waitlisted): ?>
            <?php $fits = ((int) $row['capacity'] - (int) $row['confirmed_seats']) >= (int) $row['party_size']; ?>
            <form class="inline-form" method="post" action="<?= url('/admin/bookings/') ?><?= (int) $row['id'] ?>/promote"
                  onsubmit="return confirm('この申込を繰り上げて確定にします。よろしいですか？ご本人に確定メールが送られます。')">
              <?= Csrf::field() ?>
              <input type="hidden" name="return_query" value="<?= e($query . ($query !== '' ? '&' : '') . 'page=' . $page) ?>">
              <button type="submit" class="btn btn--small <?= $fits ? '' : 'btn--ghost' ?>" <?= $fits ? '' : 'title="現在の空きでは足りません"' ?>>繰り上げ</button>
            </form>
          <?php endif; ?>
          <?php if ($status !== BookingStatus::Cancelled): ?>
            <form class="inline-form" method="post" action="<?= url('/admin/bookings/') ?><?= (int) $row['id'] ?>/cancel"
                  onsubmit="return confirm('この申込をキャンセルします。よろしいですか？この操作は取り消せません。ご本人に通知メールが送られます。')">
              <?= Csrf::field() ?>
              <input type="hidden" name="return_query" value="<?= e($query . ($query !== '' ? '&' : '') . 'page=' . $page) ?>">
              <button type="submit" class="btn btn--danger btn--small">キャンセル</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php if ($pages > 1): ?>
  <nav class="pagination">
    <?php if ($page > 1): ?><a href="<?= e($pageUrl($page - 1)) ?>">前へ</a><?php endif; ?>
    <?php for ($p = max(1, $page - 3); $p <= min($pages, $page + 3); $p++): ?>
      <?php if ($p === $page): ?><strong><?= $p ?></strong>
      <?php else: ?><a href="<?= e($pageUrl($p)) ?>"><?= $p ?></a><?php endif; ?>
    <?php endfor; ?>
    <?php if ($page < $pages): ?><a href="<?= e($pageUrl($page + 1)) ?>">次へ</a><?php endif; ?>
  </nav>
<?php endif; ?>
<?php endif; ?>
