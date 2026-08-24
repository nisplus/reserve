<?php

use App\Core\Csrf;
use App\Domain\SessionStatus;

/**
 * @var array<string, mixed>             $event
 * @var array<int, array<string, mixed>> $sessions
 */
?>
<p class="breadcrumb">
  <a href="/admin/events">イベントの管理</a> ／ <?= e($event['company_name']) ?> ／ <?= e($event['title']) ?>
</p>

<h1>開催回：<?= e($event['title']) ?></h1>

<div class="form-actions" style="margin-bottom:16px">
  <a class="btn" href="/admin/events/<?= (int) $event['id'] ?>/sessions/bulk">まとめて作成</a>
  <a class="btn btn--ghost" href="/admin/events/<?= (int) $event['id'] ?>/sessions/new">1件だけ作成</a>
  <a class="btn btn--ghost" href="/events/<?= (int) $event['id'] ?>" target="_blank" rel="noopener">公開側で見る</a>
</div>

<?php if ($sessions === []): ?>
  <p class="empty">開催回がまだありません。「まとめて作成」から登録してください。</p>
<?php else: ?>
<div class="table-scroll">
  <table class="table">
    <thead>
      <tr><th>日時</th><th>定員</th><th>確定</th><th>待ち</th><th>受付</th><th></th></tr>
    </thead>
    <tbody>
    <?php foreach ($sessions as $session): ?>
      <?php $status = SessionStatus::from((string) $session['status']); ?>
      <tr>
        <td>
          <?= e(jp_datetime((string) $session['starts_at'])) ?>〜<?= e(jp_time((string) $session['ends_at'])) ?>
        </td>
        <td><?= (int) $session['capacity'] ?> 名</td>
        <td>
          <?= (int) $session['confirmed_seats'] ?> 名
          <?php if ((int) $session['seats_left'] === 0): ?>
            <span class="badge badge--bad">満席</span>
          <?php endif; ?>
        </td>
        <td><?= (int) $session['waitlist_count'] ?> 件</td>
        <td>
          <span class="badge <?= $status === SessionStatus::Open ? 'badge--ok' : 'badge--muted' ?>">
            <?= e($status->label()) ?>
          </span>
        </td>
        <td>
          <a class="btn btn--ghost btn--small" href="/admin/sessions/<?= (int) $session['id'] ?>/edit">編集</a>
          <?php if ((int) $session['confirmed_seats'] === 0 && (int) $session['waitlist_count'] === 0): ?>
            <form class="inline-form" method="post" action="/admin/sessions/<?= (int) $session['id'] ?>/delete"
                  onsubmit="return confirm('この開催回を削除します。よろしいですか？この操作は取り消せません。')">
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
