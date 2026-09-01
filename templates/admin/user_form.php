<?php

use App\Core\Csrf;
use App\Domain\AdminRole;

/**
 * @var array<string, mixed>|null $user      null when creating.
 * @var array<string, string>     $errors
 * @var array<string, string>     $old
 * @var array<int, string>        $companies company id => name
 */
$action = $user === null ? url('/admin/users') : url('/admin/users/') . (int) $user['id'];
$role = $old['role'] ?? (string) ($user['role'] ?? AdminRole::Company->value);
$companyId = (int) ($old['company_id'] ?? ($user['company_id'] ?? 0));
?>
<p class="breadcrumb"><a href="<?= url('/admin/users') ?>">アカウントの管理</a> ／ <?= $user === null ? '作成' : '編集' ?></p>

<h1><?= $user === null ? 'アカウントの作成' : 'アカウントの編集' ?></h1>

<?php if ($errors !== []): ?>
  <div class="error-summary" role="alert"><p>入力内容をご確認ください。</p></div>
<?php endif; ?>

<div class="panel" style="max-width:560px">
  <form method="post" action="<?= e($action) ?>">
    <?= Csrf::field() ?>

    <div class="field">
      <label for="username">ユーザー名</label>
      <?php if ($user === null): ?>
        <input type="text" id="username" name="username" required maxlength="60"
               value="<?= e($old['username'] ?? '') ?>"
               <?= isset($errors['username']) ? 'aria-invalid="true"' : '' ?>>
        <p class="hint">英数字と . _ - のみ。作成後は変更できません。</p>
        <?php if (isset($errors['username'])): ?><p class="error"><?= e($errors['username']) ?></p><?php endif; ?>
      <?php else: ?>
        <p><strong><?= e($user['username']) ?></strong></p>
        <p class="hint">操作履歴に記録される名前のため、作成後は変更できません。</p>
      <?php endif; ?>
    </div>

    <div class="field">
      <label for="display_name">表示名</label>
      <input type="text" id="display_name" name="display_name" required maxlength="100"
             value="<?= e($old['display_name'] ?? (string) ($user['display_name'] ?? '')) ?>"
             <?= isset($errors['display_name']) ? 'aria-invalid="true"' : '' ?>>
      <p class="hint">画面右上に表示されます（例: 株式会社◯◯ 田中）。</p>
      <?php if (isset($errors['display_name'])): ?><p class="error"><?= e($errors['display_name']) ?></p><?php endif; ?>
    </div>

    <div class="field">
      <label for="role">種別</label>
      <select id="role" name="role" required>
        <?php foreach (AdminRole::cases() as $case): ?>
          <option value="<?= e($case->value) ?>" <?= $role === $case->value ? 'selected' : '' ?>>
            <?= e($case->label()) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <p class="hint">
        事務局はすべての会社と、会社・アカウント・メールキューの管理ができます。
        会社担当者は所属会社のイベントと予約者のみです。
      </p>
      <?php if (isset($errors['role'])): ?><p class="error"><?= e($errors['role']) ?></p><?php endif; ?>
    </div>

    <div class="field">
      <label for="company_id">所属会社</label>
      <select id="company_id" name="company_id">
        <option value="">（会社担当者の場合のみ選択）</option>
        <?php foreach ($companies as $id => $name): ?>
          <option value="<?= (int) $id ?>" <?= $companyId === (int) $id ? 'selected' : '' ?>><?= e($name) ?></option>
        <?php endforeach; ?>
      </select>
      <p class="hint">種別が「会社担当者」のときは必須です。事務局の場合は無視されます。</p>
      <?php if (isset($errors['company_id'])): ?><p class="error"><?= e($errors['company_id']) ?></p><?php endif; ?>
    </div>

    <?php if ($user === null): ?>
      <p class="muted">初回パスワードは自動生成され、作成完了時に一度だけ表示されます。</p>
    <?php endif; ?>

    <div class="form-actions">
      <button type="submit" class="btn"><?= $user === null ? '作成する' : '更新する' ?></button>
      <a class="btn btn--ghost" href="<?= url('/admin/users') ?>">戻る</a>
    </div>
  </form>
</div>
