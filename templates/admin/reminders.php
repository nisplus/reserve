<?php

use App\Core\Csrf;
use App\Core\Settings;

/**
 * @var string                            $date       Y-m-d being prepared
 * @var array<int, array<string, mixed>>  $sessions   every session that day
 * @var array<int, int>                   $skipped    session ids taken out
 * @var array<int, array<string, mixed>>  $recipients who would be sent to now
 * @var string|null                       $sample     the body, as one person reads it
 * @var string                            $notice     the office's own note
 * @var bool                              $enabled
 * @var bool                              $auto
 * @var bool                              $waitlisted
 * @var array<string, string>             $errors
 */
$count = count($recipients);
$dayUrl = static fn (string $d): string => url('/admin/reminders') . '?date=' . rawurlencode($d);
$shift = static fn (int $days): string => (new DateTimeImmutable($date))
    ->modify(($days >= 0 ? '+' : '') . $days . ' day')->format('Y-m-d');
?>
<h1>リマインド送信</h1>

<p class="muted">
  開催の前日に、申込者へ<strong>その日のご予約の内容</strong>をお送りします。
  <strong>同じ方の予約は 1 通にまとめます</strong>（当日に複数社を回る方が 3 通受け取らないように）。
  日時・会場・人数は予約データから自動で作られ、編集できません。編集できるのは「お知らせ」欄だけです。
</p>

<?php if ($errors !== []): ?>
  <div class="error-summary" role="alert"><p>入力内容をご確認ください。</p></div>
<?php endif; ?>

<?php /* --- the switch ------------------------------------------------- */ ?>
<div class="panel" style="max-width:860px;margin-bottom:20px">
  <h2>設定</h2>
  <form method="post" action="<?= url('/admin/reminders/settings') ?>">
    <?= Csrf::field() ?>
    <input type="hidden" name="date" value="<?= e($date) ?>">

    <div class="field">
      <label>
        <input type="checkbox" name="enabled" value="1" <?= $enabled ? 'checked' : '' ?>>
        <strong>リマインドを使う</strong>
      </label>
      <p class="hint">外しておくと、定期実行は何もしません。この画面からの送信も行わないでください。</p>
    </div>

    <div class="field">
      <label for="mode">送信のしかた</label>
      <select id="mode" name="mode">
        <option value="<?= e(Settings::REMINDER_MODE_APPROVAL) ?>" <?= $auto ? '' : 'selected' ?>>
          承認制（この画面で送信ボタンを押すまで送らない）
        </option>
        <option value="<?= e(Settings::REMINDER_MODE_AUTO) ?>" <?= $auto ? 'selected' : '' ?>>
          自動送信（前日の朝に定期実行がそのまま送る）
        </option>
      </select>
      <p class="hint">
        <strong>はじめは承認制をおすすめします。</strong>
        何度か中身を確認して問題がなければ、自動に切り替えてください。
      </p>
    </div>

    <div class="field">
      <label>
        <input type="checkbox" name="include_waitlisted" value="1" <?= $waitlisted ? 'checked' : '' ?>>
        キャンセル待ちの方にも送る
      </label>
      <p class="hint">送る場合、本文には「現在キャンセル待ち ◯ 番です」と明記されます。</p>
    </div>

    <div class="form-actions">
      <button type="submit" class="btn btn--ghost">設定を保存</button>
    </div>
  </form>
</div>

<?php /* --- the day -------------------------------------------------- */ ?>
<div class="filter-bar" style="margin-bottom:16px">
  <a class="btn btn--ghost btn--small" href="<?= e($dayUrl($shift(-1))) ?>">← 前の日</a>
  <strong style="font-size:17px"><?= e(jp_date($date)) ?></strong>
  <a class="btn btn--ghost btn--small" href="<?= e($dayUrl($shift(1))) ?>">次の日 →</a>
  <a class="btn btn--ghost btn--small" href="<?= e($dayUrl((new DateTimeImmutable('tomorrow'))->format('Y-m-d'))) ?>">明日に戻す</a>
</div>

<?php if ($sessions === []): ?>
  <p class="empty">この日に開催回はありません。</p>
<?php else: ?>

<form method="post" action="<?= url('/admin/reminders/notice') ?>">
  <?= Csrf::field() ?>
  <input type="hidden" name="date" value="<?= e($date) ?>">

  <div class="panel" style="max-width:980px;margin-bottom:20px">
    <h2>この日の開催回</h2>
    <p class="muted">
      <strong>中止になった回はチェックを外してください。</strong>
      外した回の申込者にはリマインドが届きません。
      受付終了の回も、開催するのであればチェックしたままにしてください
      （受付終了は「申込を締め切った」という意味で、中止とは限りません）。
    </p>

    <div class="table-scroll">
      <table class="table">
        <thead>
          <tr><th>送る</th><th>時間</th><th>開催企業 ／ 体験内容</th><th>受付</th><th>確定</th><th>待ち</th></tr>
        </thead>
        <tbody>
        <?php foreach ($sessions as $session): ?>
          <?php $id = (int) $session['id']; $isSkipped = in_array($id, $skipped, true); ?>
          <tr>
            <td>
              <input type="checkbox" name="sessions[]" value="<?= $id ?>" <?= $isSkipped ? '' : 'checked' ?>>
            </td>
            <td><?= e(jp_time((string) $session['starts_at'])) ?>〜<?= e(jp_time((string) $session['ends_at'])) ?></td>
            <td>
              <span class="muted"><?= e($session['company_name']) ?></span><br>
              <?= e($session['event_title']) ?>
            </td>
            <td>
              <?php if ((string) $session['status'] === 'closed'): ?>
                <span class="badge badge--muted">受付終了</span>
              <?php else: ?>
                <span class="badge badge--ok">受付中</span>
              <?php endif; ?>
            </td>
            <td><?= (int) $session['confirmed'] ?> 名</td>
            <td><?= (int) $session['waitlisted'] ?> 件</td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="panel" style="max-width:980px;margin-bottom:20px">
    <h2>事務局からのお知らせ</h2>
    <div class="field">
      <label for="notice">本文に差し込む一言（任意）</label>
      <textarea id="notice" name="notice" rows="4" maxlength="2000"
                placeholder="例: 雨天決行です。足元の悪い場合がありますので、歩きやすい靴でお越しください。"
                <?= isset($errors['notice']) ? 'aria-invalid="true"' : '' ?>><?= e($notice) ?></textarea>
      <p class="hint">
        <strong>空のままで構いません。</strong>空なら、この枠ごと本文に出ません。<br>
        日付や会場は本文に自動で入ります。ここに書くと<strong>古い日付が残ったまま次回も送られます</strong>ので、
        その日限りの連絡を書いたときは、送信後に消してください。
      </p>
      <?php if (isset($errors['notice'])): ?><p class="error"><?= e($errors['notice']) ?></p><?php endif; ?>
    </div>
    <div class="form-actions">
      <button type="submit" class="btn btn--ghost">保存して内容を更新</button>
    </div>
  </div>
</form>

<?php /* --- what will go ---------------------------------------------- */ ?>
<div class="panel" style="max-width:980px">
  <h2>送信内容の確認</h2>

  <p>
    送信先: <strong style="font-size:18px"><?= number_format($count) ?> 件</strong>
    <span class="muted">（同じ方の予約はまとめて 1 通。送信済みの方は除いています）</span>
  </p>

  <?php if ($count === 0): ?>
    <p class="empty">送信対象がありません。すでに送信済みか、この日に予約がありません。</p>
  <?php endif; ?>

  <?php if ($sample !== null): ?>
    <p class="muted">実際に届く本文（1 件目の方の例）:</p>
    <pre class="panel" style="white-space:pre-wrap;font-size:13px"><?= e($sample) ?></pre>
  <?php endif; ?>

  <?php if ($count > 0): ?>
    <h3>1. まず自分宛に試す</h3>
    <form method="post" action="<?= url('/admin/reminders/test') ?>">
      <?= Csrf::field() ?>
      <input type="hidden" name="date" value="<?= e($date) ?>">
      <div class="filter-bar">
        <div class="field">
          <label for="test_email">テスト送信先</label>
          <input type="email" id="test_email" name="test_email" maxlength="255"
                 <?= isset($errors['test_email']) ? 'aria-invalid="true"' : '' ?>>
          <?php if (isset($errors['test_email'])): ?><p class="error"><?= e($errors['test_email']) ?></p><?php endif; ?>
        </div>
        <button type="submit" class="btn btn--ghost btn--small">テスト送信</button>
      </div>
    </form>

    <h3>2. 本送信</h3>
    <?php if (!$enabled): ?>
      <p class="error">リマインドが「使わない」設定です。送信する場合は、上の設定で有効にしてください。</p>
    <?php else: ?>
      <form method="post" action="<?= url('/admin/reminders/send') ?>"
            onsubmit="return confirm('<?= e(jp_date($date)) ?> のリマインドを <?= $count ?> 件に送信します。送信後は取り消せません。よろしいですか？')">
        <?= Csrf::field() ?>
        <input type="hidden" name="date" value="<?= e($date) ?>">
        <div class="form-actions">
          <button type="submit" class="btn btn--danger"><?= number_format($count) ?> 件に送信する</button>
        </div>
      </form>
      <p class="muted">
        送信はキューに積まれ、順次送られます。急ぐ場合は
        <a href="<?= url('/admin/mail') ?>?category=reminder">メール送信キュー</a>の「未送信を今すぐ送る」を使ってください。
      </p>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php endif; ?>
