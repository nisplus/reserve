<?php

use App\Core\Csrf;

/**
 * @var array<string, mixed>      $event
 * @var array<string, mixed>|null $session null when creating.
 * @var array<string, string>     $errors
 * @var array<string, string>     $old
 */
$action = $session === null
    ? '/admin/events/' . (int) $event['id'] . '/sessions'
    : '/admin/sessions/' . (int) $session['id'];

// datetime-local wants "Y-m-d\TH:i".
$toLocal = static fn (string $dt): string => str_replace(' ', 'T', substr($dt, 0, 16));
$starts = $old['starts_at'] ?? ($session !== null ? $toLocal((string) $session['starts_at']) : '');
$ends   = $old['ends_at'] ?? ($session !== null ? $toLocal((string) $session['ends_at']) : '');
$status = $old['status'] ?? (string) ($session['status'] ?? 'open');
?>
<p class="breadcrumb">
  <a href="/admin/events">イベントの管理</a> ／
  <a href="/admin/events/<?= (int) $event['id'] ?>/sessions"><?= e($event['title']) ?></a> ／
  <?= $session === null ? '登録' : '編集' ?>
</p>

<h1><?= $session === null ? '開催回の登録' : '開催回の編集' ?></h1>

<?php if ($errors !== []): ?>
  <div class="error-summary" role="alert"><p>入力内容をご確認ください。</p></div>
<?php endif; ?>

<?php if ($session !== null && (int) $session['confirmed_seats'] > 0): ?>
  <p class="muted">
    現在の確定席数は <?= (int) $session['confirmed_seats'] ?> 名です。定員はこれ未満に変更できません。
  </p>
<?php endif; ?>

<div class="panel" style="max-width:560px">
  <form method="post" action="<?= e($action) ?>">
    <?= Csrf::field() ?>

    <div class="field">
      <label for="starts_at">開始日時</label>
      <input type="datetime-local" id="starts_at" name="starts_at" required
             value="<?= e($starts) ?>" <?= isset($errors['starts_at']) ? 'aria-invalid="true"' : '' ?>>
      <?php if (isset($errors['starts_at'])): ?><p class="error"><?= e($errors['starts_at']) ?></p><?php endif; ?>
    </div>

    <div class="field">
      <label for="ends_at">終了日時</label>
      <input type="datetime-local" id="ends_at" name="ends_at" required
             value="<?= e($ends) ?>" <?= isset($errors['ends_at']) ? 'aria-invalid="true"' : '' ?>>
      <?php if (isset($errors['ends_at'])): ?><p class="error"><?= e($errors['ends_at']) ?></p><?php endif; ?>
    </div>

    <div class="field">
      <label for="capacity">定員（人数）</label>
      <input type="number" id="capacity" name="capacity" required min="1" max="999"
             value="<?= e($old['capacity'] ?? (string) ($session['capacity'] ?? '')) ?>"
             <?= isset($errors['capacity']) ? 'aria-invalid="true"' : '' ?>>
      <?php if (isset($errors['capacity'])): ?><p class="error"><?= e($errors['capacity']) ?></p><?php endif; ?>
    </div>

    <div class="field">
      <label for="status">受付状態</label>
      <select id="status" name="status">
        <option value="open" <?= $status === 'open' ? 'selected' : '' ?>>受付中</option>
        <option value="closed" <?= $status === 'closed' ? 'selected' : '' ?>>受付終了</option>
      </select>
      <p class="hint">受付終了にすると公開側に表示されなくなります。既存の予約はそのまま残ります。</p>
    </div>

    <div class="form-actions">
      <button type="submit" class="btn"><?= $session === null ? '登録する' : '更新する' ?></button>
      <a class="btn btn--ghost" href="/admin/events/<?= (int) $event['id'] ?>/sessions">戻る</a>
    </div>
  </form>
</div>
