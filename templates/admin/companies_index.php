<?php

use App\Core\Csrf;

/** @var array<int, array<string, mixed>> $companies */
?>
<h1>会社の管理</h1>

<div class="form-actions" style="margin-bottom:16px">
  <a class="btn" href="<?= url('/admin/companies/new') ?>">会社を登録</a>
</div>

<div class="table-scroll">
  <table class="table">
    <thead>
      <tr><th>表示順</th><th>会社名</th><th>かな</th><th>エリア</th><th>公開</th><th>イベント</th><th></th></tr>
    </thead>
    <tbody>
    <?php foreach ($companies as $company): ?>
      <tr>
        <td><?= (int) $company['sort_order'] ?></td>
        <td><?= e($company['name']) ?></td>
        <td class="muted"><?= e($company['name_kana']) ?></td>
        <td>
          <?php if ($company['area'] !== null): ?>
            <span class="badge badge--muted"><?= e(App\Domain\Area::labelFor($company['area'])) ?></span>
          <?php else: ?>
            <span class="muted">未設定</span>
          <?php endif; ?>
        </td>
        <td>
          <?php if ((int) $company['is_published'] === 1): ?>
            <span class="badge badge--ok">公開</span>
          <?php else: ?>
            <span class="badge badge--muted">非公開</span>
          <?php endif; ?>
        </td>
        <td><?= (int) $company['event_count'] ?> 件</td>
        <td>
          <a class="btn btn--ghost btn--small" href="<?= url('/admin/companies/') ?><?= (int) $company['id'] ?>/edit">編集</a>
          <?php if ((int) $company['event_count'] === 0): ?>
            <?php /* The message is deliberately static: interpolating a name
                     into inline JS would need JS-escaping on top of e(). */ ?>
            <form class="inline-form" method="post" action="<?= url('/admin/companies/') ?><?= (int) $company['id'] ?>/delete"
                  onsubmit="return confirm('この会社を削除します。よろしいですか？この操作は取り消せません。')">
              <?= Csrf::field() ?>
              <button type="submit" class="btn btn--danger btn--small">削除</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
