<?php

use App\Core\Csrf;

/**
 * @var array<string, mixed>|null $event   null when creating.
 * @var array<string, string>     $errors
 * @var array<string, string>     $old
 * @var array<int, string>        $options company id => name
 */
$action = $event === null ? url('/admin/events') : url('/admin/events/') . (int) $event['id'];
$selectedCompany = (int) ($old['company_id'] ?? ($event['company_id'] ?? 0));
$published = $old !== []
    ? ($old['is_published'] ?? '') === '1'
    : ($event === null || (int) $event['is_published'] === 1);
// The form speaks 予約不要; the column stores booking_required. New events
// default to requiring a booking, so the box starts unchecked.
$noBooking = $old !== []
    ? ($old['no_booking'] ?? '') === '1'
    : ($event !== null && (int) $event['booking_required'] !== 1);
// Unchecked by default, which is the behaviour the public form already
// describes: 参加人数 counts the people taking part, not their escorts.
$guardiansInParty = $old !== []
    ? ($old['guardians_in_party'] ?? '') === '1'
    : ($event !== null && (int) $event['party_includes_guardians'] === 1);
?>
<p class="breadcrumb"><a href="<?= url('/admin/events') ?>">体験プログラムの管理</a> ／ <?= $event === null ? '登録' : '編集' ?></p>

<h1><?= $event === null ? '体験プログラムの登録' : '体験プログラムの編集' ?></h1>

<?php if ($errors !== []): ?>
  <div class="error-summary" role="alert"><p>入力内容をご確認ください。</p></div>
<?php endif; ?>

<div class="panel" style="max-width:640px">
  <form method="post" action="<?= e($action) ?>">
    <?= Csrf::field() ?>

    <div class="field">
      <label for="company_id">主催会社</label>
      <select id="company_id" name="company_id" required <?= isset($errors['company_id']) ? 'aria-invalid="true"' : '' ?>>
        <option value="">選択してください</option>
        <?php foreach ($options as $id => $name): ?>
          <option value="<?= (int) $id ?>" <?= $selectedCompany === (int) $id ? 'selected' : '' ?>><?= e($name) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if (isset($errors['company_id'])): ?><p class="error"><?= e($errors['company_id']) ?></p><?php endif; ?>
    </div>

    <div class="field">
      <label for="title">体験プログラム名</label>
      <input type="text" id="title" name="title" required maxlength="200"
             value="<?= e($old['title'] ?? (string) ($event['title'] ?? '')) ?>"
             <?= isset($errors['title']) ? 'aria-invalid="true"' : '' ?>>
      <?php if (isset($errors['title'])): ?><p class="error"><?= e($errors['title']) ?></p><?php endif; ?>
    </div>

    <div class="field">
      <label for="description">説明</label>
      <textarea id="description" name="description" maxlength="5000"><?= e($old['description'] ?? (string) ($event['description'] ?? '')) ?></textarea>
      <p class="hint">公開側に表示されます。改行は反映されますが、HTMLは使えません。</p>
      <?php if (isset($errors['description'])): ?><p class="error"><?= e($errors['description']) ?></p><?php endif; ?>
    </div>

    <div class="field">
      <label for="venue">会場</label>
      <input type="text" id="venue" name="venue" maxlength="200"
             value="<?= e($old['venue'] ?? (string) ($event['venue'] ?? '')) ?>">
      <?php if (isset($errors['venue'])): ?><p class="error"><?= e($errors['venue']) ?></p><?php endif; ?>
    </div>

    <div class="field">
      <label for="sort_order">表示順</label>
      <input type="number" id="sort_order" name="sort_order" min="0" max="9999"
             value="<?= e($old['sort_order'] ?? (string) ($event['sort_order'] ?? '0')) ?>">
      <?php if (isset($errors['sort_order'])): ?><p class="error"><?= e($errors['sort_order']) ?></p><?php endif; ?>
    </div>

    <div class="field">
      <label>
        <input type="checkbox" name="is_published" value="1" <?= $published ? 'checked' : '' ?>>
        公開する（会社も公開のとき一覧に表示）
      </label>
    </div>

    <div class="field">
      <label for="max_party_size">1予約あたりの上限人数</label>
      <input type="number" id="max_party_size" name="max_party_size" required min="1" max="20"
             value="<?= e($old['max_party_size'] ?? (string) ($event['max_party_size'] ?? '20')) ?>"
             <?= isset($errors['max_party_size']) ? 'aria-invalid="true"' : '' ?>>
      <p class="hint">
        1回のご予約で受け付ける人数の上限です（1〜20）。
        2名以上の予約では、予約フォームで人数分のお名前を入力していただきます。
      </p>
      <?php if (isset($errors['max_party_size'])): ?><p class="error"><?= e($errors['max_party_size']) ?></p><?php endif; ?>
    </div>

    <?php /* Both optional, and independently so - an event may have a floor,
             a ceiling, both or neither. Blank means no limit; 0 is a real
             floor, so the form must not treat them alike. */ ?>
    <div class="field">
      <label for="min_age">対象年齢の下限</label>
      <input type="number" id="min_age" name="min_age" min="0" max="120"
             placeholder="制限なし"
             value="<?= e($old['min_age'] ?? (string) ($event['min_age'] ?? '')) ?>"
             <?= isset($errors['min_age']) ? 'aria-invalid="true"' : '' ?>>
      <p class="hint">この年齢以上の方のみご予約いただけます。<strong>空欄なら下限なし</strong>（0 と空欄は別の意味です）。</p>
      <?php if (isset($errors['min_age'])): ?><p class="error"><?= e($errors['min_age']) ?></p><?php endif; ?>
    </div>

    <div class="field">
      <label for="max_age">対象年齢の上限</label>
      <input type="number" id="max_age" name="max_age" min="0" max="120"
             placeholder="制限なし"
             value="<?= e($old['max_age'] ?? (string) ($event['max_age'] ?? '')) ?>"
             <?= isset($errors['max_age']) ? 'aria-invalid="true"' : '' ?>>
      <p class="hint">
        この年齢以下の方のみご予約いただけます。<strong>空欄なら上限なし</strong>。<br>
        対象年齢は<strong>予約フォームに入力されたすべての年齢</strong>で判定します。
        「付き添いの保護者も参加人数に含める」場合は保護者も対象になります
        （含めない場合、付き添いの方は年齢を伺わないため対象外です）。
        既に受け付けた予約は、あとから範囲を変えても取り消されません。
      </p>
      <?php if (isset($errors['max_age'])): ?><p class="error"><?= e($errors['max_age']) ?></p><?php endif; ?>
    </div>
    <div class="field">
      <label>
        <input type="checkbox" name="guardians_in_party" value="1" <?= $guardiansInParty ? 'checked' : '' ?>>
        <strong>付き添いの保護者も参加人数に含める</strong>
      </label>
      <p class="hint">
        親子で一緒に体験するなど、<strong>来場する全員が参加者</strong>となるイベントで
        チェックしてください。定員は来場人数と同じ意味になります。<br>
        チェックしない場合、参加人数は<strong>体験する方だけ</strong>を数え、予約画面に
        「付き添いの人数」欄が出ます。付き添いの方は<strong>定員を消費しません</strong>ので、
        会場の収容は「参加人数＋付き添い」でご確認ください（予約一覧と CSV に出しています）。
      </p>
    </div>

    <div class="field">
      <label>
        <input type="checkbox" name="no_booking" value="1" <?= $noBooking ? 'checked' : '' ?>>
        <strong>予約不要</strong>（予約を受け付けない）
      </label>
      <p class="hint">
        チェックすると<strong>予約ボタンが表示されなくなります</strong>。開催回は削除されず、
        公開側には<strong>時間割として引き続き表示されます</strong>（予約不要のまま開催時間だけ
        お知らせできます）。チェックを外せば、その開催回のまま予約の受付が始まります。
      </p>
    </div>

    <div class="field">
      <label for="external_url">外部リンクURL</label>
      <input type="url" id="external_url" name="external_url" maxlength="500"
             placeholder="https://example.com/event"
             value="<?= e($old['external_url'] ?? (string) ($event['external_url'] ?? '')) ?>"
             <?= isset($errors['external_url']) ? 'aria-invalid="true"' : '' ?>>
      <p class="hint">
        予約不要の体験プログラムでは詳細ページに「詳細を見る」ボタンとして、
        予約を受け付ける場合は詳細ページと予約画面にリンクとして表示します。
        空欄なら表示しません。新しいタブで開きます。http:// または https:// から入力してください。
      </p>
      <?php if (isset($errors['external_url'])): ?><p class="error"><?= e($errors['external_url']) ?></p><?php endif; ?>
    </div>

    <div class="form-actions">
      <button type="submit" class="btn"><?= $event === null ? '登録する' : '更新する' ?></button>
      <a class="btn btn--ghost" href="<?= url('/admin/events') ?>">戻る</a>
    </div>
  </form>
</div>
