<?php

use App\Core\Csrf;

/**
 * @var array<int, array<string, mixed>> $rows
 * @var int    $total
 * @var int    $page
 * @var int    $pages
 * @var string $status   Current status filter ('' = all).
 * @var string $category Current category filter ('' = all).
 * @var int    $pending  Unsent messages of every category.
 * @var int    $batch    How many one press of 今すぐ送信 attempts.
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
/*
 * Every link on this screen carries both filters, so narrowing to the
 * unsent half of a campaign and then paging does not silently drop back
 * to the whole queue.
 */
$listUrl = static function (string $s, string $c, int $p = 1) : string {
    $query = array_filter(['status' => $s, 'category' => $c, 'page' => $p > 1 ? (string) $p : '']);
    return url('/admin/mail') . ($query === [] ? '' : '?' . http_build_query($query));
};
$pageUrl = static fn (int $p): string => $listUrl($status, $category, $p);
?>
<h1>メール送信キュー</h1>

<div class="filter-bar" style="margin-bottom:8px">
  <?php foreach (['' => 'すべて', 'pending' => '未送信', 'sent' => '送信済み', 'failed' => '失敗'] as $key => $name): ?>
    <a class="btn btn--small <?= $status === $key ? '' : 'btn--ghost' ?>"
       href="<?= e($listUrl($key, $category)) ?>"><?= e($name) ?></a>
  <?php endforeach; ?>
</div>

<div class="filter-bar" style="margin-bottom:16px">
  <span class="muted">種別:</span>
  <?php foreach (['' => 'すべて', 'transactional' => '個別（予約関連）', 'bulk' => '一斉送信'] as $key => $name): ?>
    <a class="btn btn--small <?= $category === $key ? '' : 'btn--ghost' ?>"
       href="<?= e($listUrl($status, $key)) ?>"><?= e($name) ?></a>
  <?php endforeach; ?>

  <?php /* One press sends one batch. The remaining count is the progress
           display: press, watch it fall, and stop if 失敗 starts rising. */ ?>
  <form class="inline-form" method="post" action="<?= url('/admin/mail/send-pending') ?>"
        <?= $pending > $batch ? 'onsubmit="return confirm(\'未送信 ' . number_format($pending) . ' 件のうち ' . number_format($batch) . ' 件を送信します。残りはもう一度押すか定期実行で送られます。\')"' : '' ?>>
    <?= Csrf::field() ?>
    <button type="submit" class="btn btn--small" <?= $pending === 0 ? 'disabled' : '' ?>>
      未送信を今すぐ送る<?= $pending > 0 ? '（' . number_format($pending) . ' 件）' : '' ?>
    </button>
  </form>
</div>

<?php if ($pending > $batch): ?>
  <p class="muted">
    未送信が <?= number_format($pending) ?> 件あります。1 回の送信は <?= number_format($batch) ?> 件までです。
    急がない場合は、そのままお待ちいただければ定期実行で順次送信されます。
  </p>
<?php endif; ?>

<p class="muted"><?= number_format($total) ?> 件<?= $pages > 1 ? "（{$page} / {$pages} ページ）" : '' ?></p>

<?php if ($rows === []): ?>
  <p class="empty">該当するメールがありません。</p>
<?php else: ?>
<div class="table-scroll">
  <table class="table">
    <thead>
      <tr><th>ID</th><th>状態</th><th>種別</th><th>宛先</th><th>件名</th><th>試行</th><th>作成</th><th>送信</th><th></th></tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $row): ?>
      <?php $s = (string) $row['status']; ?>
      <tr>
        <td class="muted"><?= (int) $row['id'] ?></td>
        <td><span class="badge <?= e($badge($s)) ?>"><?= e($label($s)) ?></span></td>
        <td><?php if ((string) $row['category'] === 'bulk'): ?>
          <span class="badge badge--muted">一斉</span>
        <?php else: ?><span class="muted">個別</span><?php endif; ?></td>
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
