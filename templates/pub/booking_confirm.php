<?php

use App\Core\Csrf;

/**
 * @var array<string, mixed> $session     Session with event context.
 * @var array<string, mixed> $input       Validated form values.
 * @var bool                 $willWait    Whether, at display time, this would waitlist.
 * @var array{conflict: array<string, mixed>, gap_minutes: int}|null $travelWarn
 *      A booking of this address within the travel buffer, or null.
 * @var bool                 $travelBlock true = such bookings are refused; the
 *                                        submit is withheld. false = warn only,
 *                                        submit carries a confirm() popup.
 */
$travelPopup = $travelWarn !== null && !$travelBlock
    ? ' onsubmit="return confirm(\'移動時間を考慮すると、この予約は間に合いません。このまま申し込みますか？\')"'
    : '';
?>
<p class="breadcrumb">
  <a href="<?= url('/') ?>">イベント一覧</a> ／
  <a href="<?= url('/events/') ?><?= (int) $session['event_id'] ?>"><?= e($session['event_title']) ?></a> ／
  申し込み内容の確認
</p>

<h1>申し込み内容の確認</h1>

<?php if ($willWait): ?>
  <div class="error-summary" role="alert">
    <p>
      この開催回は満席のため、<strong>キャンセル待ち</strong>としての受付になります。
      お席をご用意できるようになった場合に、ご連絡のうえ繰り上げとなります。
    </p>
  </div>
<?php endif; ?>

<?php if ($travelWarn !== null): ?>
  <?php $near = $travelWarn['conflict']; ?>
  <div class="error-summary" role="alert">
    <p>
      <strong>移動時間を考慮すると、この予約は間に合いません。</strong><br>
      お申し込み済みの
      「<?= e($near['company_name']) ?>　<?= e($near['event_title']) ?>」
      （<?= e(jp_datetime((string) $near['starts_at'])) ?>〜<?= e(jp_time((string) $near['ends_at'])) ?>）
      との間隔が <?= (int) $travelWarn['gap_minutes'] ?> 分しかありません。
      <?php if ($travelBlock): ?>
        <br>この間隔ではお申し込みいただけません。別の開催時間をお選びください。
      <?php endif; ?>
    </p>
  </div>
<?php endif; ?>

<div class="panel">
  <dl class="detail-list">
    <dt>イベント</dt><dd><?= e($session['event_title']) ?></dd>
    <dt>主催</dt><dd><?= e($session['company_name']) ?></dd>
    <dt>日時</dt>
    <dd><?= e(jp_datetime((string) $session['starts_at'])) ?>〜<?= e(jp_time((string) $session['ends_at'])) ?></dd>
    <?php if (($session['venue'] ?? '') !== '' && $session['venue'] !== null): ?>
      <dt>会場</dt><dd><?= e($session['venue']) ?></dd>
    <?php endif; ?>
    <dt>メールアドレス</dt><dd><?= e($input['email']) ?></dd>
    <dt>お名前</dt><dd><?= e($input['name']) ?></dd>
    <dt>参加人数</dt><dd><?= (int) $input['party_size'] ?> 名</dd>
  </dl>
</div>

<p class="muted">
  空き状況はお申し込みの確定時に改めて確認します。確定の時点で満席となっていた場合は、
  キャンセル待ちでの受付になります。
</p>

<?php if ($travelWarn !== null && $travelBlock): ?>
  <?php /* Blocking mode: no submit at all. The service refuses these anyway
           (inside the transaction), so a hand-crafted POST gains nothing. */ ?>
  <div class="form-actions">
    <a class="btn btn--ghost" href="<?= url('/events/') ?><?= (int) $session['event_id'] ?>">別の開催時間を選ぶ</a>
  </div>
<?php else: ?>
  <form method="post" action="<?= url('/bookings') ?>"<?= $travelPopup ?>>
    <?= Csrf::field() ?>
    <input type="hidden" name="session_id" value="<?= (int) $session['id'] ?>">
    <input type="hidden" name="email" value="<?= e($input['email']) ?>">
    <input type="hidden" name="name" value="<?= e($input['name']) ?>">
    <input type="hidden" name="party_size" value="<?= (int) $input['party_size'] ?>">

    <div class="form-actions">
      <button type="submit" class="btn">
        <?= $willWait ? 'キャンセル待ちで申し込む' : 'この内容で申し込む' ?>
      </button>
      <a class="btn btn--ghost" href="<?= url('/sessions/') ?><?= (int) $session['id'] ?>/apply">入力し直す</a>
    </div>
  </form>
<?php endif; ?>
