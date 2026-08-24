<?php

use App\Core\Csrf;

/**
 * @var array<string, mixed>|null $company null when creating.
 * @var array<string, string>     $errors
 * @var array<string, string>     $old
 */
$action = $company === null ? '/admin/companies' : '/admin/companies/' . (int) $company['id'];
$value = static fn (string $key, string $column) => $old[$key] ?? (string) ($company[$column] ?? '');
$published = $old !== []
    ? ($old['is_published'] ?? '') === '1'
    : ($company === null || (int) $company['is_published'] === 1);
?>
<p class="breadcrumb"><a href="/admin/companies">会社の管理</a> ／ <?= $company === null ? '登録' : '編集' ?></p>

<h1><?= $company === null ? '会社の登録' : '会社の編集' ?></h1>

<?php if ($errors !== []): ?>
  <div class="error-summary" role="alert"><p>入力内容をご確認ください。</p></div>
<?php endif; ?>

<div class="panel" style="max-width:560px">
  <form method="post" action="<?= e($action) ?>">
    <?= Csrf::field() ?>

    <div class="field">
      <label for="name">会社名</label>
      <input type="text" id="name" name="name" required maxlength="120"
             value="<?= e($value('name', 'name')) ?>"
             <?= isset($errors['name']) ? 'aria-invalid="true"' : '' ?>>
      <?php if (isset($errors['name'])): ?><p class="error"><?= e($errors['name']) ?></p><?php endif; ?>
    </div>

    <div class="field">
      <label for="name_kana">会社名（かな）</label>
      <input type="text" id="name_kana" name="name_kana" maxlength="120"
             value="<?= e($value('name_kana', 'name_kana')) ?>">
      <p class="hint">並び替え・検索用。省略できます。</p>
      <?php if (isset($errors['name_kana'])): ?><p class="error"><?= e($errors['name_kana']) ?></p><?php endif; ?>
    </div>

    <div class="field">
      <label for="sort_order">表示順</label>
      <input type="number" id="sort_order" name="sort_order" min="0" max="9999"
             value="<?= e($old['sort_order'] ?? (string) ($company['sort_order'] ?? '0')) ?>">
      <p class="hint">小さいほど上に表示されます。</p>
      <?php if (isset($errors['sort_order'])): ?><p class="error"><?= e($errors['sort_order']) ?></p><?php endif; ?>
    </div>

    <div class="field">
      <label>
        <input type="checkbox" name="is_published" value="1" <?= $published ? 'checked' : '' ?>>
        公開する（公開側の一覧に表示）
      </label>
    </div>

    <div class="form-actions">
      <button type="submit" class="btn"><?= $company === null ? '登録する' : '更新する' ?></button>
      <a class="btn btn--ghost" href="/admin/companies">戻る</a>
    </div>
  </form>
</div>
