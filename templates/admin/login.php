<?php

use App\Core\Csrf;

/** @var array<string, string> $old */
?>
<h1>ログイン</h1>

<div class="panel" style="max-width:480px">
  <form method="post" action="<?= url('/admin/login') ?>">
    <?= Csrf::field() ?>

    <div class="field">
      <label for="username">ユーザー名</label>
      <input type="text" id="username" name="username" required maxlength="60"
             autocomplete="username" value="<?= e($old['username'] ?? '') ?>">
    </div>

    <div class="field">
      <label for="password">パスワード</label>
      <input type="password" id="password" name="password" required
             autocomplete="current-password">
    </div>

    <div class="form-actions">
      <button type="submit" class="btn">ログイン</button>
    </div>
  </form>
</div>
