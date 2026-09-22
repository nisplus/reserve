<?php

use App\Core\Csrf;
use App\Service\BulkMailService;

/**
 * @var array<int, array<string, mixed>> $events
 * @var array<int, array<string, mixed>> $sessions  Sessions of the chosen event ([] otherwise)
 * @var array<string, string>            $errors
 * @var array<string, mixed>             $old
 * @var array<string, mixed>|null        $resolved  Set once a preview or test has run
 * @var bool                             $isOffice  Superadmin; a company account cannot address everyone
 */
$scope = (string) ($old['scope'] ?? ($isOffice ? BulkMailService::SCOPE_ALL : BulkMailService::SCOPE_EVENT));

/*
 * The test send and the real send re-post the whole composition, so both
 * are validated and resolved by the same code path as the preview. Hidden
 * fields rather than a session stash: a second tab composing a different
 * announcement would otherwise overwrite the first, and the one thing this
 * screen must never do is send the wrong message to the wrong list.
 */
$hidden = static function (array $old): string {
    $out = '';
    foreach (['scope', 'event', 'session', 'subject', 'body', 'include_waitlisted'] as $key) {
        $value = (string) ($old[$key] ?? '');
        if ($key === 'include_waitlisted' && $value !== '1') {
            continue; // an unchecked box posts nothing at all
        }
        $out .= sprintf('<input type="hidden" name="%s" value="%s">' . "\n", e($key), e($value));
    }
    return $out;
};
?>
<h1>メール一斉送信</h1>

<p class="muted">
  申込者へのお知らせを一斉に送ります。中止・延期のご連絡を想定しています。<br>
  <strong>キャンセル済みの方には送りません。</strong>
  同じメールアドレスの方は、何件ご予約されていても 1 通だけお送りします。
  <?php if (!$isOffice): ?>
    送信できるのは<strong>自社の体験プログラムの申込者</strong>のみです。
  <?php endif; ?>
</p>

<?php if (isset($errors['_top'])): ?>
  <div class="error-summary" role="alert"><p><?= e($errors['_top']) ?></p></div>
<?php elseif ($errors !== []): ?>
  <div class="error-summary" role="alert"><p>入力内容をご確認ください。</p></div>
<?php endif; ?>

<div class="panel" style="max-width:760px">
  <form method="post" action="<?= url('/admin/mail/bulk/preview') ?>" id="bulk-form">
    <?= Csrf::field() ?>

    <h2>宛先</h2>

    <div class="field">
      <label for="scope">対象</label>
      <select id="scope" name="scope" onchange="this.form.action='<?= url('/admin/mail/bulk/preview') ?>';this.form.submit()">
        <?php if ($isOffice): ?>
          <option value="all" <?= $scope === 'all' ? 'selected' : '' ?>>全申込者</option>
        <?php endif; ?>
        <option value="event" <?= $scope === 'event' ? 'selected' : '' ?>>体験プログラムを指定</option>
        <option value="session" <?= $scope === 'session' ? 'selected' : '' ?>>開催回を指定</option>
      </select>
    </div>

    <?php if ($scope !== 'all'): ?>
      <div class="field">
        <label for="event">体験プログラム</label>
        <select id="event" name="event"
                onchange="this.form.action='<?= url('/admin/mail/bulk/preview') ?>';this.form.submit()"
                <?= isset($errors['event']) ? 'aria-invalid="true"' : '' ?>>
          <option value="0">選択してください</option>
          <?php foreach ($events as $event): ?>
            <option value="<?= (int) $event['id'] ?>" <?= (int) ($old['event'] ?? 0) === (int) $event['id'] ? 'selected' : '' ?>>
              <?= e($event['company_name']) ?>　<?= e($event['title']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <?php if (isset($errors['event'])): ?><p class="error"><?= e($errors['event']) ?></p><?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if ($scope === 'session'): ?>
      <div class="field">
        <label for="session">開催回</label>
        <select id="session" name="session"
                onchange="this.form.action='<?= url('/admin/mail/bulk/preview') ?>';this.form.submit()"
                <?= isset($errors['session']) ? 'aria-invalid="true"' : '' ?>>
          <option value="0">選択してください</option>
          <?php foreach ($sessions as $session): ?>
            <option value="<?= (int) $session['id'] ?>" <?= (int) ($old['session'] ?? 0) === (int) $session['id'] ? 'selected' : '' ?>>
              <?= e(jp_datetime((string) $session['starts_at'])) ?>〜<?= e(jp_time((string) $session['ends_at'])) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <p class="hint">天災などで<strong>特定の回だけ</strong>中止・延期になる場合はこちらです。</p>
        <?php if (isset($errors['session'])): ?><p class="error"><?= e($errors['session']) ?></p><?php endif; ?>
      </div>
    <?php endif; ?>

    <div class="field">
      <label>
        <input type="checkbox" name="include_waitlisted" value="1"
               <?= ($old['include_waitlisted'] ?? '') === '1' ? 'checked' : '' ?>>
        キャンセル待ちの方も含める
      </label>
      <p class="hint">中止・延期のご連絡なら、含めるのが親切です。</p>
    </div>

    <h2>本文</h2>

    <div class="field">
      <label for="subject">件名</label>
      <input type="text" id="subject" name="subject" required maxlength="200"
             value="<?= e($old['subject'] ?? '') ?>"
             <?= isset($errors['subject']) ? 'aria-invalid="true"' : '' ?>>
      <?php if (isset($errors['subject'])): ?><p class="error"><?= e($errors['subject']) ?></p><?php endif; ?>
    </div>

    <div class="field">
      <label for="body">本文</label>
      <textarea id="body" name="body" required maxlength="5000" rows="10"
                placeholder="例: 台風接近のため、9月12日の開催は中止といたしました。&#10;振替のご案内は改めてお送りします。"><?= e($old['body'] ?? '') ?></textarea>
      <p class="hint">
        体験プログラムや開催回を指定した場合、<strong>体験内容と日時は本文の先頭に自動で付きます</strong>。
        受信された方がどのご予約の話か迷わないようにするためです。
      </p>
      <?php if (isset($errors['body'])): ?><p class="error"><?= e($errors['body']) ?></p><?php endif; ?>
    </div>

    <div class="form-actions">
      <button type="submit" class="btn btn--ghost">送信先と本文を確認</button>
    </div>
  </form>

  <?php if ($resolved !== null): ?>
    <?php $count = count($resolved['recipients']); ?>
    <hr style="margin:24px 0;border:0;border-top:1px solid var(--line)">

    <h2>確認</h2>

    <p>
      送信先: <strong style="font-size:18px"><?= number_format($count) ?> 件</strong>
      <span class="muted">（メールアドレスの重複を除いた件数）</span>
    </p>

    <?php if ($count === 0): ?>
      <p class="empty">条件に一致する申込者がいません。</p>
    <?php endif; ?>

    <?php /* The message exactly as one recipient will read it. Built by the
             same method the send uses, so what is shown here is what goes. */ ?>
    <p class="muted">実際に届く本文:</p>
    <pre class="panel" style="white-space:pre-wrap;font-size:13px"><?= e($resolved['composed']) ?></pre>

    <?php if ($count > 0): ?>
      <h3>1. まず自分宛に試す</h3>
      <p class="muted">
        1 通が正しく届くか確かめてから本送信してください。
        受信側に弾かれていないか、この 1 通で分かります。
      </p>
      <form method="post" action="<?= url('/admin/mail/bulk/test') ?>">
        <?= Csrf::field() ?>
        <?= $hidden($old) ?>
        <div class="filter-bar">
          <div class="field">
            <label for="test_email">テスト送信先</label>
            <input type="email" id="test_email" name="test_email" maxlength="255"
                   value="<?= e($old['test_email'] ?? '') ?>"
                   <?= isset($errors['test_email']) ? 'aria-invalid="true"' : '' ?>>
            <?php if (isset($errors['test_email'])): ?><p class="error"><?= e($errors['test_email']) ?></p><?php endif; ?>
          </div>
          <button type="submit" class="btn btn--ghost btn--small">テスト送信</button>
        </div>
      </form>

      <h3>2. 本送信</h3>
      <form method="post" action="<?= url('/admin/mail/bulk') ?>"
            onsubmit="return confirm('<?= $count ?> 件にメールを送信します。送信後は取り消せません。よろしいですか？')">
        <?= Csrf::field() ?>
        <?= $hidden($old) ?>
        <div class="form-actions">
          <button type="submit" class="btn btn--danger"><?= number_format($count) ?> 件に送信する</button>
        </div>
      </form>
      <p class="muted">
        送信はキューに積まれ、定期実行で順次送られます。急ぐ場合は
        <a href="<?= url('/admin/mail') ?>">メール送信キュー</a>の「今すぐ送信」を使ってください。
      </p>
    <?php endif; ?>
  <?php endif; ?>
</div>
