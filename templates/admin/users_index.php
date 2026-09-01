<?php

use App\Core\Csrf;
use App\Domain\AdminRole;

/**
 * @var array<int, array<string, mixed>> $users
 * @var int                              $me   Signed-in account id.
 */
?>
<h1>アカウントの管理</h1>

<div class="form-actions" style="margin-bottom:16px">
  <a class="btn" href="<?= url('/admin/users/new') ?>">アカウントを作成</a>
</div>

<p class="muted">
  会社担当者は、所属する会社のイベント・開催回・予約者だけを閲覧・編集できます。
  会社の追加や他社の情報、メール送信キューには一切アクセスできません。
</p>

<div class="table-scroll">
  <table class="table">
    <thead>
      <tr><th>ユーザー名</th><th>表示名</th><th>種別</th><th>所属会社</th><th>状態</th><th>最終ログイン</th><th></th></tr>
    </thead>
    <tbody>
    <?php foreach ($users as $user): ?>
      <?php
        $role = AdminRole::from((string) $user['role']);
        $active = (int) $user['is_active'] === 1;
        $locked = $user['locked_until'] !== null && strtotime((string) $user['locked_until']) > time();
      ?>
      <tr>
        <td>
          <?= e($user['username']) ?>
          <?php if ((int) $user['id'] === $me): ?><span class="muted">（自分）</span><?php endif; ?>
        </td>
        <td><?= e($user['display_name']) ?></td>
        <td>
          <span class="badge <?= $role === AdminRole::Superadmin ? 'badge--ok' : 'badge--muted' ?>">
            <?= e($role->label()) ?>
          </span>
        </td>
        <td><?= e($user['company_name'] ?? '—') ?></td>
        <td>
          <?php if (!$active): ?>
            <span class="badge badge--bad">無効</span>
          <?php elseif ($locked): ?>
            <span class="badge badge--warn">ロック中</span>
          <?php else: ?>
            <span class="badge badge--ok">有効</span>
          <?php endif; ?>
        </td>
        <td class="muted">
          <?= $user['last_login_at'] !== null ? e(substr((string) $user['last_login_at'], 0, 16)) : '—' ?>
        </td>
        <td>
          <a class="btn btn--ghost btn--small" href="<?= url('/admin/users/') ?><?= (int) $user['id'] ?>/edit">編集</a>

          <form class="inline-form" method="post" action="<?= url('/admin/users/') ?><?= (int) $user['id'] ?>/password"
                onsubmit="return confirm('新しいパスワードを発行します。現在のパスワードは使えなくなります。よろしいですか？')">
            <?= Csrf::field() ?>
            <button type="submit" class="btn btn--ghost btn--small">パスワード再発行</button>
          </form>

          <?php if ((int) $user['id'] !== $me): ?>
            <form class="inline-form" method="post" action="<?= url('/admin/users/') ?><?= (int) $user['id'] ?>/toggle"
                  onsubmit="return confirm(<?= $active
                      ? "'このアカウントを無効にします。以後ログインできなくなります。よろしいですか？'"
                      : "'このアカウントを有効に戻します。よろしいですか？'" ?>)">
              <?= Csrf::field() ?>
              <button type="submit" class="btn btn--small <?= $active ? 'btn--danger' : '' ?>">
                <?= $active ? '無効にする' : '有効にする' ?>
              </button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
