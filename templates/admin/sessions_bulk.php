<?php

use App\Core\Csrf;

/**
 * @var array<string, mixed>  $event
 * @var array<string, string> $errors
 * @var array<string, string> $old
 */
?>
<p class="breadcrumb">
  <a href="<?= url('/admin/events') ?>">イベントの管理</a> ／
  <a href="<?= url('/admin/events/') ?><?= (int) $event['id'] ?>/sessions"><?= e($event['title']) ?></a> ／
  一括作成
</p>

<h1>開催回の一括作成</h1>
<p class="muted">
  初回の開始日時から「所要時間＋間隔」ごとに、指定した回数分の開催回を一気に作成します。
  例: 10:00 開始・所要 45 分・間隔 15 分・6 回 → 10:00 / 11:00 / 12:00 / 13:00 / 14:00 / 15:00。
  既存の開催回と開始時刻が重なる場合は、1件も作成せずエラーになります。
</p>

<?php if ($errors !== []): ?>
  <div class="error-summary" role="alert">
    <p><?= isset($errors['first_start']) && str_contains($errors['first_start'], '既にあります')
        ? e($errors['first_start']) : '入力内容をご確認ください。' ?></p>
  </div>
<?php endif; ?>

<div class="panel" style="max-width:560px">
  <form method="post" action="<?= url('/admin/events/') ?><?= (int) $event['id'] ?>/sessions/bulk">
    <?= Csrf::field() ?>

    <div class="field">
      <label for="first_start">初回の開始日時</label>
      <input type="datetime-local" id="first_start" name="first_start" required
             value="<?= e($old['first_start'] ?? '') ?>"
             <?= isset($errors['first_start']) ? 'aria-invalid="true"' : '' ?>>
      <?php if (isset($errors['first_start'])): ?><p class="error"><?= e($errors['first_start']) ?></p><?php endif; ?>
    </div>

    <div class="field">
      <label for="duration_min">所要時間（分）</label>
      <input type="number" id="duration_min" name="duration_min" required min="5" max="600"
             value="<?= e($old['duration_min'] ?? '45') ?>">
      <?php if (isset($errors['duration_min'])): ?><p class="error"><?= e($errors['duration_min']) ?></p><?php endif; ?>
    </div>

    <div class="field">
      <label for="gap_min">間隔（分）</label>
      <input type="number" id="gap_min" name="gap_min" required min="0" max="600"
             value="<?= e($old['gap_min'] ?? '15') ?>">
      <p class="hint">前の回の終了から次の回の開始までの休憩時間。0 にすると隙間なく連続します。</p>
      <?php if (isset($errors['gap_min'])): ?><p class="error"><?= e($errors['gap_min']) ?></p><?php endif; ?>
    </div>

    <div class="field">
      <label for="count">回数</label>
      <input type="number" id="count" name="count" required min="1" max="20"
             value="<?= e($old['count'] ?? '6') ?>">
      <?php if (isset($errors['count'])): ?><p class="error"><?= e($errors['count']) ?></p><?php endif; ?>
    </div>

    <div class="field">
      <label for="capacity">定員（各回・人数）</label>
      <input type="number" id="capacity" name="capacity" required min="1" max="999"
             value="<?= e($old['capacity'] ?? '') ?>">
      <?php if (isset($errors['capacity'])): ?><p class="error"><?= e($errors['capacity']) ?></p><?php endif; ?>
    </div>

    <div class="form-actions">
      <button type="submit" class="btn">一括作成する</button>
      <a class="btn btn--ghost" href="<?= url('/admin/events/') ?><?= (int) $event['id'] ?>/sessions">戻る</a>
    </div>
  </form>
</div>
