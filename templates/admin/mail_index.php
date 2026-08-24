<?php

use App\Core\Csrf;

/**
 * @var array<int, array<string, mixed>> $rows
 * @var int    $total
 * @var int    $page
 * @var int    $pages
 * @var string $status Current filter ('' = all).
 */
$badge = static fn (string $s): string => match ($s) {
    'sent'    => 'badge--ok',
    'pending' => 'badge--warn',
    default   => 'badge--bad',
};
$label = static fn (string $s): string => match ($s) {
    'sent'    => '送信済み',
    'pending' => '未送信',
    default   => '失敗',
};
$pageUrl = static fn (int $p): string => url('/admin/mail') . '?' . ($status !== '' ? 'status=' . e($status) . '&' : '') . 'page=' . $p;
?>
<h1>メール送信キュー</h1>

<div class="filter-bar" style="margin-bottom:16px">
  <?php foreach (['' => 'すべて', 'pending' => '未送信', 'sent' => '送信済み', 'failed' => '失敗'] as $key => $name): ?>
    <a class="btn btn--small <?= $status === $key ? '' : 'btn--ghost' ?>"
       href="<?= url('/admin/mail') ?><?= $key !== '' ? '?status=' . e($key) : '' ?>"><?= e($name) ?></a>
  <?php endforeach; ?>
  <form class="inline-form" method="post" action="<?= url('/admin/mail/send-pending') ?>">
    <?= Csrf::field() ?>
    <button type="submit" class="btn btn--small">未送信を今すぐ送る</button>
  </form>
</div>

<p class="muted"><?= number_format($total) ?> 件<?= $pages > 1 ? "（{$page} / {$pages} ページ）" : '' ?></p>

<?php if ($rows === []): ?>
  <p class="empty">該当するメールがありません。</p>
<?php else: ?>
<div class="table-scroll">
  <table class="table">
    <thead>
      <tr><th>ID</th><th>状態</th><th>宛先</th><th>件名</th><th>試行</th><th>作成</th><th>送信</th><th></th></tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $row): ?>
      <?php $s = (string) $row['status']; ?>
      <tr>
        <td class="muted"><?= (int) $row['id'] ?></td>
        <td><span class="badge <?= e($badge($s)) ?>"><?= e($label($s)) ?></span></td>
        <td><?= e($row['to_email']) ?></td>
        <td>
          <?= e(mb_strimwidth((string) $row['subject'], 0, 60, '…')) ?>
          <?php if ($row['last_error'] !== null && $row['last_error'] !== ''): ?>
            <br><span class="error" style="font-size:12px"><?= e(mb_strimwidth((string) $row['last_error'], 0, 90, '…')) ?></span>
          <?php endif; ?>
        </td>
        <td><?= (int) $row['attempts'] ?></td>
        <td class="muted"><?= e(substr((string) $row['created_at'], 5, 11)) ?></td>
        <td class="muted"><?= $row['sent_at'] !== null ? e(substr((string) $row['sent_at'], 5, 11)) : '—' ?></td>
        <td>
          <?php if ($s === 'failed'): ?>
            <form class="inline-form" method="post" action="<?= url('/admin/mail/') ?><?= (int) $row['id'] ?>/resend">
              <?= Csrf::field() ?>
              <button type="submit" class="btn btn--small">再送</button>
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
