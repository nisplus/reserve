<?php
/**
 * @var array<int, array{label:string, value:string, ok:bool, note:string}> $checks
 * @var int $failed
 */
?>
<h1>環境診断</h1>

<?php if ($failed === 0): ?>
  <p class="flash flash--success">すべての項目が正常です。</p>
<?php else: ?>
  <p class="flash flash--error"><?= (int) $failed ?> 件の問題があります。</p>
<?php endif; ?>

<table class="table table--diag">
  <thead><tr><th>項目</th><th>値</th><th>判定</th></tr></thead>
  <tbody>
  <?php foreach ($checks as $check): ?>
    <tr class="<?= $check['ok'] ? '' : 'is-bad' ?>">
      <th scope="row"><?= e($check['label']) ?></th>
      <td><code><?= e($check['value']) ?></code>
        <?php if ($check['note'] !== ''): ?><br><small class="muted"><?= e($check['note']) ?></small><?php endif; ?>
      </td>
      <td><?= $check['ok'] ? 'OK' : 'NG' ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
