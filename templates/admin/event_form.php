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
?>
<p class="breadcrumb"><a href="<?= url('/admin/events') ?>">イベントの管理</a> ／ <?= $event === null ? '登録' : '編集' ?></p>

<h1><?= $event === null ? 'イベントの登録' : 'イベントの編集' ?></h1>

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
      <label for="title">イベント名</label>
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
      <label for="max_party_size">1申込あたりの上限人数</label>
      <input type="number" id="max_party_size" name="max_party_size" required min="1" max="20"
             value="<?= e($old['max_party_size'] ?? (string) ($event['max_party_size'] ?? '20')) ?>"
             <?= isset($errors['max_party_size']) ? 'aria-invalid="true"' : '' ?>>
      <p class="hint">
        1回のお申し込みで受け付ける人数の上限です（1〜20）。
        2名以上の申込では、申込フォームで人数分のお名前を入力していただきます。
      </p>
      <?php if (isset($errors['max_party_size'])): ?><p class="error"><?= e($errors['max_party_size']) ?></p><?php endif; ?>
    </div>

    <div class="field">
      <label>
        <input type="checkbox" name="no_booking" value="1" <?= $noBooking ? 'checked' : '' ?>>
        <strong>予約不要</strong>（申し込みを受け付けない）
      </label>
      <p class="hint">
        チェックすると公開側で開催回が表示されなくなり、一覧のボタンが「詳細を見る」に変わります。
        既存の開催回は削除されませんが、申し込みは受け付けなくなります。
      </p>
    </div>

    <div class="field">
      <label for="external_url">外部リンクURL</label>
      <input type="url" id="external_url" name="external_url" maxlength="500"
             placeholder="https://example.com/event"
             value="<?= e($old['external_url'] ?? (string) ($event['external_url'] ?? '')) ?>"
             <?= isset($errors['external_url']) ? 'aria-invalid="true"' : '' ?>>
      <p class="hint">
        予約不要のイベントで、詳細ページに「詳細を見る（外部サイト）」ボタンとして表示します。
        新しいタブで開きます。http:// または https:// から入力してください。
      </p>
      <?php if (isset($errors['external_url'])): ?><p class="error"><?= e($errors['external_url']) ?></p><?php endif; ?>
    </div>

    <div class="form-actions">
      <button type="submit" class="btn"><?= $event === null ? '登録する' : '更新する' ?></button>
      <a class="btn btn--ghost" href="<?= url('/admin/events') ?>">戻る</a>
    </div>
  </form>
</div>
