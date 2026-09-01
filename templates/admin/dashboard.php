<?php

/**
 * @var array<string, int>               $stats        Office-only keys absent for company accounts.
 * @var array<int, array<string, mixed>> $promotable
 * @var bool                             $isSuperadmin
 */
?>
<h1>ダッシュボード</h1>

<?php if (!$isSuperadmin): ?>
  <p class="muted">自社のイベントに関する情報のみが表示されます。</p>
<?php endif; ?>

<div class="stat-row">
  <div class="stat"><strong><?= $stats['confirmed'] ?></strong><span>確定予約</span></div>
  <div class="stat"><strong><?= $stats['waitlisted'] ?></strong><span>キャンセル待ち</span></div>
  <div class="stat"><strong><?= $stats['sessions_full'] ?></strong><span>満席の開催回</span></div>
  <div class="stat"><strong><?= count($promotable) ?></strong><span>繰り上げ候補あり</span></div>
</div>

<div class="stat-row">
  <?php if ($isSuperadmin): ?>
    <div class="stat"><strong><?= $stats['companies'] ?></strong><span>会社</span></div>
  <?php endif; ?>
  <div class="stat"><strong><?= $stats['events'] ?></strong><span>イベント</span></div>
  <div class="stat"><strong><?= $stats['sessions'] ?></strong><span>開催回</span></div>
  <?php if ($isSuperadmin): ?>
    <div class="stat">
      <strong><?= $stats['mail_pending'] ?></strong><span>メール未送信<?= $stats['mail_failed'] > 0 ? '（失敗 ' . $stats['mail_failed'] . '）' : '' ?></span>
    </div>
  <?php endif; ?>
</div>

<?php if ($promotable !== []): ?>
  <h2>繰り上げ候補のある開催回 <span class="badge badge--warn"><?= count($promotable) ?></span></h2>
  <p class="muted">空席があり、キャンセル待ちの方が居る開催回です。「候補を見る」から個別に繰り上げできます。</p>
  <div class="table-scroll">
    <table class="table">
      <thead>
        <tr><th>開催回</th><th>日時</th><th>空き</th><th>待ち</th><th></th></tr>
      </thead>
      <tbody>
      <?php foreach ($promotable as $row): ?>
        <tr>
          <td><?= e($row['company_name']) ?>　<?= e($row['event_title']) ?></td>
          <td><?= e(jp_datetime((string) $row['starts_at'])) ?>〜<?= e(jp_time((string) $row['ends_at'])) ?></td>
          <td><?= (int) $row['seats_left'] ?> 名分</td>
          <td><?= (int) $row['waitlist_count'] ?> 件</td>
          <td>
            <a class="btn btn--ghost btn--small"
               href="<?= url('/admin/bookings?session=') ?><?= (int) $row['id'] ?>&status=waitlisted">候補を見る</a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<h2>管理</h2>
<div class="form-actions">
  <a class="btn" href="<?= url('/admin/events') ?>">イベントと開催回の管理</a>
  <a class="btn" href="<?= url('/admin/bookings') ?>">予約一覧</a>
  <?php if ($isSuperadmin): ?>
    <a class="btn btn--ghost" href="<?= url('/admin/companies') ?>">会社の管理</a>
    <a class="btn btn--ghost" href="<?= url('/admin/users') ?>">アカウントの管理</a>
  <?php endif; ?>
</div>
