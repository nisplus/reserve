<?php

use App\Core\Csrf;

/**
 * @var array<string, mixed>|null $event   null when creating.
 * @var array<string, string>     $errors
 * @var array<string, string>     $old
 * @var array<int, string>        $options company id => name
 */
$action = $event === null ? url('/admin/events') : url('/admin/events/') . (int) $event['id'];
$selectedCompany = (int) ($old['company_id'] ?? ($event['company_id'] ?? 0));
$published = $old !== []
    ? ($old['is_published'] ?? '') === '1'
    : ($event === null || (int) $event['is_published'] === 1);
?>
<p class="breadcrumb"><a href="<?= url('/admin/events') ?>">イベントの管理</a> ／ <?= $event === null ? '登録' : '編集' ?></p>

<h1><?= $event === null ? 'イベントの登録' : 'イベントの編集' ?></h1>

<?php if ($errors !== []): ?>
  <div class="error-summary" role="alert"><p>入力内容をご確認ください。</p></div>
<?php endif; ?>

<div class="panel" style="max-width:640px">
  <form method="post" action="<?= e($action) ?>">
    <?= Csrf::field() ?>

    <div class="field">
      <label for="company_id">主催会社</label>
      <select id="company_id" name="company_id" required <?= isset($errors['company_id']) ? 'aria-invalid="true"' : '' ?>>
        <option value="">選択してください</option>
        <?php foreach ($options as $id => $name): ?>
          <option value="<?= (int) $id ?>" <?= $selectedCompany === (int) $id ? 'selected' : '' ?>><?= e($name) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if (isset($errors['company_id'])): ?><p class="error"><?= e($errors['company_id']) ?></p><?php endif; ?>
    </div>

    <div class="field">
      <label for="title">イベント名</label>
      <input type="text" id="title" name="title" required maxlength="200"
             value="<?= e($old['title'] ?? (string) ($event['title'] ?? '')) ?>"
             <?= isset($errors['title']) ? 'aria-invalid="true"' : '' ?>>
      <?php if (isset($errors['title'])): ?><p class="error"><?= e($errors['title']) ?></p><?php endif; ?>
    </div>

    <div class="field">
      <label for="description">説明</label>
      <textarea id="description" name="description" maxlength="5000"><?= e($old['description'] ?? (string) ($event['description'] ?? '')) ?></textarea>
      <p class="hint">公開側に表示されます。改行は反映されますが、HTMLは使えません。</p>
      <?php if (isset($errors['description'])): ?><p class="error"><?= e($errors['description']) ?></p><?php endif; ?>
    </div>

    <div class="field">
      <label for="venue">会場</label>
      <input type="text" id="venue" name="venue" maxlength="200"
             value="<?= e($old['venue'] ?? (string) ($event['venue'] ?? '')) ?>">
      <?php if (isset($errors['venue'])): ?><p class="error"><?= e($errors['venue']) ?></p><?php endif; ?>
    </div>

    <div class="field">
      <label for="sort_order">表示順</label>
      <input type="number" id="sort_order" name="sort_order" min="0" max="9999"
             value="<?= e($old['sort_order'] ?? (string) ($event['sort_order'] ?? '0')) ?>">
      <?php if (isset($errors['sort_order'])): ?><p class="error"><?= e($errors['sort_order']) ?></p><?php endif; ?>
    </div>

    <div class="field">
      <label>
        <input type="checkbox" name="is_published" value="1" <?= $published ? 'checked' : '' ?>>
        公開する（会社も公開のとき一覧に表示）
      </label>
    </div>

    <div class="form-actions">
      <button type="submit" class="btn"><?= $event === null ? '登録する' : '更新する' ?></button>
      <a class="btn btn--ghost" href="<?= url('/admin/events') ?>">戻る</a>
    </div>
  </form>
</div>
