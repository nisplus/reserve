<?php

use App\Core\Csrf;

/**
 * @var array<int, array<string, mixed>> $events
 * @var array<int, string>               $options   company id => name
 * @var int                              $companyId 0 = all
 */
?>
<h1>イベントの管理</h1>

<div class="filter-bar" style="margin-bottom:16px">
  <form class="inline-form" method="get" action="/admin/events">
    <div class="field">
      <label for="company">会社で絞り込み</label>
      <select id="company" name="company" onchange="this.form.submit()">
        <option value="0">すべての会社</option>
        <?php foreach ($options as $id => $name): ?>
          <option value="<?= (int) $id ?>" <?= $companyId === (int) $id ? 'selected' : '' ?>><?= e($name) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <noscript><button type="submit" class="btn btn--small">絞り込む</button></noscript>
  </form>
  <a class="btn" href="/admin/events/new<?= $companyId > 0 ? '?company=' . $companyId : '' ?>">イベントを登録</a>
</div>

<?php if ($events === []): ?>
  <p class="empty">イベントがありません。</p>
<?php else: ?>
<div class="table-scroll">
  <table class="table">
    <thead>
      <tr><th>会社</th><th>イベント名</th><th>会場</th><th>公開</th><th>開催回</th><th></th></tr>
    </thead>
    <tbody>
    <?php foreach ($events as $event): ?>
      <tr>
        <td class="muted"><?= e($event['company_name']) ?></td>
        <td><?= e($event['title']) ?></td>
        <td class="muted"><?= e($event['venue']) ?></td>
        <td>
          <?php if ((int) $event['is_published'] === 1): ?>
            <span class="badge badge--ok">公開</span>
          <?php else: ?>
            <span class="badge badge--muted">非公開</span>
          <?php endif; ?>
        </td>
        <td>
          <a href="/admin/events/<?= (int) $event['id'] ?>/sessions"><?= (int) $event['session_count'] ?> 件</a>
        </td>
        <td>
          <a class="btn btn--ghost btn--small" href="/admin/events/<?= (int) $event['id'] ?>/sessions">開催回</a>
          <a class="btn btn--ghost btn--small" href="/admin/events/<?= (int) $event['id'] ?>/edit">編集</a>
          <?php if ((int) $event['session_count'] === 0): ?>
            <form class="inline-form" method="post" action="/admin/events/<?= (int) $event['id'] ?>/delete"
                  onsubmit="return confirm('このイベントを削除します。よろしいですか？この操作は取り消せません。')">
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
<?php endif; ?>
