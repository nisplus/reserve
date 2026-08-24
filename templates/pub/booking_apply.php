<?php

use App\Core\Csrf;

/**
 * @var array<string, mixed>  $session Session with event context (findWithContext).
 * @var array<string, string> $errors  Field errors; '_top' is a form-level message.
 * @var array<string, string> $old     Previous input to re-fill after an error.
 */
$seatsLeft = (int) $session['seats_left'];
$isFull    = $seatsLeft === 0;
?>
<p class="breadcrumb">
  <a href="/">イベント一覧</a> ／
  <a href="/events/<?= (int) $session['event_id'] ?>"><?= e($session['event_title']) ?></a> ／
  申し込み
</p>

<h1>お申し込み</h1>

<div class="panel">
  <dl class="detail-list">
    <dt>イベント</dt><dd><?= e($session['event_title']) ?></dd>
    <dt>主催</dt><dd><?= e($session['company_name']) ?></dd>
    <dt>日時</dt>
    <dd><?= e(jp_datetime((string) $session['starts_at'])) ?>〜<?= e(jp_time((string) $session['ends_at'])) ?></dd>
    <?php if (($session['venue'] ?? '') !== '' && $session['venue'] !== null): ?>
      <dt>会場</dt><dd><?= e($session['venue']) ?></dd>
    <?php endif; ?>
    <dt>空き状況</dt>
    <dd>
      <?php if ($isFull): ?>
        <span class="badge badge--bad">満席</span>
        <span class="muted">キャンセル待ちでの受付になります</span>
      <?php else: ?>
        <span class="badge <?= $seatsLeft <= 3 ? 'badge--warn' : 'badge--ok' ?>">残り <?= $seatsLeft ?> 名</span>
        <span class="muted">／ 定員 <?= (int) $session['capacity'] ?> 名</span>
      <?php endif; ?>
    </dd>
  </dl>
</div>

<?php if (isset($errors['_top'])): ?>
  <div class="error-summary" role="alert">
    <p><?= e($errors['_top']) ?></p>
  </div>
<?php elseif ($errors !== []): ?>
  <div class="error-summary" role="alert">
    <p>入力内容をご確認ください。</p>
  </div>
<?php endif; ?>

<p class="muted">
  残席は表示時点のものです。お申し込みの確定時に改めて確認するため、
  確定の時点で満席となった場合はキャンセル待ちでの受付になります。
</p>

<form method="post" action="/sessions/<?= (int) $session['id'] ?>/confirm" novalidate>
  <?= Csrf::field() ?>

  <div class="field">
    <label for="email">メールアドレス</label>
    <input type="email" id="email" name="email" required maxlength="255"
           value="<?= e($old['email'] ?? '') ?>"
           <?= isset($errors['email']) ? 'aria-invalid="true"' : '' ?>>
    <p class="hint">確認・キャンセル用のURLをこのアドレスにお送りします。</p>
    <?php if (isset($errors['email'])): ?><p class="error"><?= e($errors['email']) ?></p><?php endif; ?>
  </div>

  <div class="field">
    <label for="name">お名前</label>
    <input type="text" id="name" name="name" required maxlength="100"
           value="<?= e($old['name'] ?? '') ?>"
           <?= isset($errors['name']) ? 'aria-invalid="true"' : '' ?>>
    <?php if (isset($errors['name'])): ?><p class="error"><?= e($errors['name']) ?></p><?php endif; ?>
  </div>

  <div class="field">
    <label for="party_size">参加人数</label>
    <input type="number" id="party_size" name="party_size" required min="1" max="20"
           value="<?= e($old['party_size'] ?? '1') ?>"
           <?= isset($errors['party_size']) ? 'aria-invalid="true"' : '' ?>>
    <p class="hint">ご本人を含めた人数を入力してください。</p>
    <?php if (isset($errors['party_size'])): ?><p class="error"><?= e($errors['party_size']) ?></p><?php endif; ?>
  </div>

  <div class="form-actions">
    <button type="submit" class="btn"><?= $isFull ? 'キャンセル待ちで確認画面へ' : '確認画面へ進む' ?></button>
    <a class="btn btn--ghost" href="/events/<?= (int) $session['event_id'] ?>">開催時間の選択に戻る</a>
  </div>
</form>
