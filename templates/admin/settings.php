<?php

use App\Core\Csrf;

/**
 * @var \App\Domain\BookingWindow $window
 * @var \DateTimeImmutable        $now
 * @var bool                      $open
 * @var string                    $notice
 * @var array<string, string>     $errors
 * @var array<string, string>     $old
 */
$enabled = $old !== [] ? ($old['enabled'] ?? '') === '1' : $window->enabled;

/** datetime-local wants Y-m-d\TH:i, and an empty string when unset. */
$local = static function (?DateTimeImmutable $value, ?string $posted): string {
    if ($posted !== null) {
        return $posted;
    }
    return $value?->format('Y-m-d\TH:i') ?? '';
};
?>
<h1>受付設定</h1>

<?php /* The current answer first. Three controls interact, and reading them
         back out of the form is harder than being told the outcome. */ ?>
<div class="panel" style="margin-bottom:20px">
  <p>
    現在の状態:
    <?php if ($open): ?>
      <span class="badge badge--ok">予約を受け付けています</span>
    <?php else: ?>
      <span class="badge badge--bad">受付停止中</span>
    <?php endif; ?>
    <span class="muted">（<?= e(jp_datetime($now->format('Y-m-d H:i:s'))) ?> 時点 / 日本時間）</span>
  </p>
  <?php if (!$open): ?>
    <p class="muted"><?= enl($notice) ?></p>
  <?php endif; ?>
  <p class="muted">
    受付を停止しても、<strong>体験内容・開催時間・会場は公開側に表示されたまま</strong>です。
    予約ボタンだけが消えます。管理画面の繰り上げ・キャンセルは停止中も操作できます。
    予約不要の体験プログラムは影響を受けません。
  </p>
</div>

<?php if ($errors !== []): ?>
  <div class="error-summary" role="alert"><p>入力内容をご確認ください。</p></div>
<?php endif; ?>

<div class="panel" style="max-width:640px">
  <form method="post" action="<?= url('/admin/settings') ?>"
        onsubmit="return confirm('受付設定を保存します。公開側の予約ボタンに即座に反映されます。よろしいですか？')">
    <?= Csrf::field() ?>

    <div class="field">
      <label>
        <input type="checkbox" name="enabled" value="1" <?= $enabled ? 'checked' : '' ?>>
        <strong>予約を受け付ける</strong>
      </label>
      <p class="hint">
        <strong>これを外すと、日時の設定にかかわらず即座に停止します。</strong>
        天災などで急に止めるときはここを使ってください。
      </p>
    </div>

    <div class="field">
      <label for="opens_at">受付開始日時</label>
      <input type="datetime-local" id="opens_at" name="opens_at"
             value="<?= e($local($window->opensAt, $old['opens_at'] ?? null)) ?>"
             <?= isset($errors['opens_at']) ? 'aria-invalid="true"' : '' ?>>
      <p class="hint">
        この日時になるまで予約を受け付けません。公開側には
        「ご予約の受付は◯月◯日◯時から開始します」と表示されます。
        <strong>空欄なら開始日時の制限なし</strong>。
      </p>
      <?php if (isset($errors['opens_at'])): ?><p class="error"><?= e($errors['opens_at']) ?></p><?php endif; ?>
    </div>

    <div class="field">
      <label for="closes_at">受付終了日時</label>
      <input type="datetime-local" id="closes_at" name="closes_at"
             value="<?= e($local($window->closesAt, $old['closes_at'] ?? null)) ?>"
             <?= isset($errors['closes_at']) ? 'aria-invalid="true"' : '' ?>>
      <p class="hint">
        この日時ちょうどに受付を終了します（その時刻は含みません）。
        <strong>空欄なら終了日時の制限なし</strong>。
      </p>
      <?php if (isset($errors['closes_at'])): ?><p class="error"><?= e($errors['closes_at']) ?></p><?php endif; ?>
    </div>

    <div class="field">
      <label for="closed_message">停止中の案内文</label>
      <textarea id="closed_message" name="closed_message" maxlength="1000"
                placeholder="例: 台風接近のため、9月12日の開催は中止といたしました。"><?= e($old['closed_message'] ?? $window->closedMessage) ?></textarea>
      <p class="hint">
        受付停止中に公開側へ表示します。空欄なら「ご予約の受付は終了しました。」等の既定文になります。
        受付開始前は、開始日時の案内に続けて表示されます。
      </p>
      <?php if (isset($errors['closed_message'])): ?><p class="error"><?= e($errors['closed_message']) ?></p><?php endif; ?>
    </div>

    <div class="form-actions">
      <button type="submit" class="btn">保存</button>
      <a class="btn btn--ghost" href="<?= url('/') ?>" target="_blank" rel="noopener">公開側を確認</a>
    </div>
  </form>
</div>

<p class="muted" style="margin-top:16px">
  日時はすべて<strong>日本時間（JST）</strong>です。
</p>
