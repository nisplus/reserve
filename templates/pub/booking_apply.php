<?php

use App\Core\Csrf;

/**
 * @var array<string, mixed>  $session  Session with event context (findWithContext).
 * @var array<string, string> $errors   Field errors; '_top' is a form-level message.
 * @var array<string, mixed>  $old      Previous input to re-fill after an error.
 * @var int                   $maxParty Per-application cap for this event.
 */
$seatsLeft   = (int) $session['seats_left'];
$waiting     = (int) ($session['waitlist_count'] ?? 0);
// Anyone waiting owns the free seats, so this application joins the queue
// whatever the seat count says (BookingService::wouldWaitlist).
$isFull      = $waiting > 0 || $seatsLeft === 0;
$externalUrl = (string) ($session['external_url'] ?? '');
// Whether 参加人数 already counts the people coming along. Where it does not,
// they are asked for separately and do not take a seat.
$guardiansInParty = (int) ($session['party_includes_guardians'] ?? 0) === 1;
?>
<p class="breadcrumb">
  <a href="<?= url('/') ?>">体験一覧</a> ／
  <a href="<?= url('/events/') ?><?= (int) $session['event_id'] ?>"><?= e($session['event_title']) ?></a> ／
  予約
</p>

<h1>ご予約</h1>

<div class="panel">
  <dl class="detail-list">
    <dt>体験プログラム</dt><dd><?= e($session['event_title']) ?></dd>
    <dt>開催企業</dt><dd><?= e($session['company_name']) ?></dd>
    <dt>日時</dt>
    <dd><?= e(jp_datetime((string) $session['starts_at'])) ?>〜<?= e(jp_time((string) $session['ends_at'])) ?></dd>
    <?php if (($session['venue'] ?? '') !== '' && $session['venue'] !== null): ?>
      <dt>会場</dt><dd><?= e($session['venue']) ?></dd>
    <?php endif; ?>
    <?php /* Shown whenever the event has one, regardless of whether booking
             is required - it is the host's own page about this event, useful
             to read before reserving. Hidden when blank. */ ?>
    <?php if ($externalUrl !== ''): ?>
      <dt>詳細</dt>
      <dd>
        <a href="<?= e($externalUrl) ?>" target="_blank" rel="noopener noreferrer">
          開催企業の紹介ページで見る
        </a>
        <span class="muted">（新しいタブで開きます）</span>
      </dd>
    <?php endif; ?>
    <dt>空き状況</dt>
    <dd>
      <?php if ($waiting > 0): ?>
        <span class="badge badge--warn">キャンセル待ち受付中</span>
        <span class="muted">現在 <?= $waiting ?> 件</span>
      <?php elseif ($seatsLeft === 0): ?>
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

<?php if ($waiting > 0): ?>
  <p class="muted">
    この開催回はキャンセル待ちの受付中です。キャンセルで空いたお席は、
    <strong>お待ちの方から順にご案内</strong>しますので、いま空きが出ていても
    キャンセル待ちとしての受付になります。
  </p>
<?php else: ?>
  <p class="muted">
    残席は表示時点のものです。ご予約の確定時に改めて確認するため、
    確定の時点で満席となった場合はキャンセル待ちでの受付になります。
  </p>
<?php endif; ?>

<form method="post" action="<?= url('/sessions/') ?><?= (int) $session['id'] ?>/confirm" novalidate>
  <?= Csrf::field() ?>

  <div class="field">
    <label for="email">メールアドレス</label>
    <input type="email" id="email" name="email" required maxlength="255"
           value="<?= e($old['email'] ?? '') ?>"
           <?= isset($errors['email']) ? 'aria-invalid="true"' : '' ?>>
    <p class="hint">申込完了確認メールをこのアドレスにお送りします。</p>
    <?php if (isset($errors['email'])): ?><p class="error"><?= e($errors['email']) ?></p><?php endif; ?>
  </div>

  <div class="field">
    <label for="phone">事前もしくは当日連絡が取れる電話番号</label>
    <input type="tel" id="phone" name="phone" required maxlength="30"
           placeholder="090-1234-5678"
           value="<?= e($old['phone'] ?? '') ?>"
           <?= isset($errors['phone']) ? 'aria-invalid="true"' : '' ?>>
    <p class="hint">事前確認および開催当日に連絡がつく番号をご入力ください。</p>
    <?php if (isset($errors['phone'])): ?><p class="error"><?= e($errors['phone']) ?></p><?php endif; ?>
  </div>

  <div class="field">
    <label for="party_size">参加人数</label>
    <input type="number" id="party_size" name="party_size" required min="1" max="<?= $maxParty ?>"
           value="<?= e($old['party_size'] ?? '1') ?>"
           <?= isset($errors['party_size']) ? 'aria-invalid="true"' : '' ?>>
    <p class="hint">
      <?php if ($guardiansInParty): ?>
        付き添いの保護者様も含めた、ご来場になる全員の人数をご入力ください。
      <?php else: ?>
        保護者等、体験されない付き添いの方は含めません。連絡先となる方の氏名をメッセージ欄にご記入下さい。
      <?php endif; ?>
      <?php if ($maxParty < 20): ?>1回のご予約につき <?= $maxParty ?> 名までです。<?php endif; ?>
    </p>
    <?php if (isset($errors['party_size'])): ?><p class="error"><?= e($errors['party_size']) ?></p><?php endif; ?>
  </div>

  <?php /* Asked for only where 参加人数 excludes them; the other events have
           already counted these people above. Not required - blank means
           nobody, which is the common answer. */ ?>
  <?php if (!$guardiansInParty): ?>
    <div class="field">
      <label for="guardian_count">付き添いの人数（体験されない方）</label>
      <input type="number" id="guardian_count" name="guardian_count" min="0" max="20"
             value="<?= e($old['guardian_count'] ?? '0') ?>"
             <?= isset($errors['guardian_count']) ? 'aria-invalid="true"' : '' ?>>
      <p class="hint">
        保護者様など、ご一緒に来場されるが体験はされない方の人数です。
        いらっしゃらない場合は 0 のままで結構です。定員には含まれません。
      </p>
      <?php if (isset($errors['guardian_count'])): ?><p class="error"><?= e($errors['guardian_count']) ?></p><?php endif; ?>
    </div>
  <?php endif; ?>

  <h2>ご参加者</h2>

  <div class="field">
    <label for="name">1人目のお名前（ご予約者）</label>
    <input type="text" id="name" name="name" required maxlength="100"
           value="<?= e($old['name'] ?? '') ?>"
           <?= isset($errors['name']) ? 'aria-invalid="true"' : '' ?>>
    <?php if (isset($errors['name'])): ?><p class="error"><?= e($errors['name']) ?></p><?php endif; ?>
  </div>

  <div class="field">
    <label for="age_1">1人目の年齢</label>
    <input type="number" id="age_1" name="age_1" required min="0" max="120"
           value="<?= e((string) ($old['ages'][1] ?? '')) ?>"
           <?= isset($errors['age_1']) ? 'aria-invalid="true"' : '' ?>>
    <?php if (isset($errors['age_1'])): ?><p class="error"><?= e($errors['age_1']) ?></p><?php endif; ?>
  </div>

  <?php
    /*
     * Name and age for each person beyond the first.
     *
     * Rendered server-side for the party size currently in hand, so the page
     * works without JavaScript: pick 3, submit, and the redisplayed form comes
     * back with the extra fields and errors asking for them. The script below
     * only removes that round trip.
     */
    $shownParty = max(1, min($maxParty, (int) ($old['party_size'] ?? 1)));
  ?>
  <div id="companions" data-max="<?= $maxParty ?>">
    <?php for ($i = 2; $i <= $maxParty; $i++): ?>
      <div class="companion-field" data-no="<?= $i ?>" <?= $i > $shownParty ? 'hidden' : '' ?>>
        <div class="field">
          <label for="companion_<?= $i ?>"><?= $i ?>人目のお名前</label>
          <input type="text" id="companion_<?= $i ?>" name="companion_<?= $i ?>" maxlength="100"
                 value="<?= e($old['companions'][$i] ?? '') ?>"
                 <?= isset($errors["companion_{$i}"]) ? 'aria-invalid="true"' : '' ?>>
          <?php if (isset($errors["companion_{$i}"])): ?>
            <p class="error"><?= e($errors["companion_{$i}"]) ?></p>
          <?php endif; ?>
        </div>
        <div class="field">
          <label for="age_<?= $i ?>"><?= $i ?>人目の年齢</label>
          <input type="number" id="age_<?= $i ?>" name="age_<?= $i ?>" min="0" max="120"
                 value="<?= e((string) ($old['ages'][$i] ?? '')) ?>"
                 <?= isset($errors["age_{$i}"]) ? 'aria-invalid="true"' : '' ?>>
          <?php if (isset($errors["age_{$i}"])): ?>
            <p class="error"><?= e($errors["age_{$i}"]) ?></p>
          <?php endif; ?>
        </div>
      </div>
    <?php endfor; ?>
  </div>

  <script>
    // Show exactly as many people as the party size says. Hidden fields still
    // submit, so they are cleared on the way out - otherwise a name typed for
    // a 4th person and then reduced to 2 would be posted and rejected as more
    // names than people.
    (function () {
      var size = document.getElementById('party_size');
      var box = document.getElementById('companions');
      if (!size || !box) { return; }
      var groups = box.querySelectorAll('.companion-field');
      function sync() {
        var n = parseInt(size.value, 10) || 1;
        groups.forEach(function (group) {
          var show = parseInt(group.dataset.no, 10) <= n;
          group.hidden = !show;
          group.querySelectorAll('input').forEach(function (input) {
            input.required = show;
            if (!show) { input.value = ''; }
          });
        });
      }
      size.addEventListener('input', sync);
      sync();
    })();
  </script>

  <h2>開催企業へのメッセージ</h2>

  <div class="field">
    <label for="message">メッセージ（任意）</label>
    <textarea id="message" name="message" maxlength="1000"
              placeholder="ご参加者と連絡先となる方（保護者等）が別になる場合は連絡先の御氏名をご記入下さい、他にもご質問、配慮が必要なこと、当日の予定など"><?= e($old['message'] ?? '') ?></textarea>
    <p class="hint">開催企業に伝えたいことがあればご記入ください。1000文字以内・省略できます。</p>
    <?php if (isset($errors['message'])): ?><p class="error"><?= e($errors['message']) ?></p><?php endif; ?>
  </div>

  <div class="form-actions">
    <button type="submit" class="btn"><?= $isFull ? 'キャンセル待ちで確認画面へ' : '確認画面へ進む' ?></button>
    <a class="btn btn--ghost" href="<?= url('/events/') ?><?= (int) $session['event_id'] ?>">開催時間の選択に戻る</a>
  </div>
</form>
