<?php

use App\Core\Auth;
use App\Core\Csrf;

/**
 * @var string $content
 * @var string|null $title
 */
$pageTitle = isset($title) && $title !== '' ? $title . ' | 管理画面' : '管理画面';
$user = Auth::user();
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= e($pageTitle) ?></title>
<link rel="stylesheet" href="<?= url('/assets/css/app.css') ?>">
</head>
<body>
<header class="admin-header">
  <div class="wrap wrap--wide">
    <a class="site-title" href="<?= url('/admin') ?>">イベント予約 管理画面</a>
    <?php if ($user !== null): ?>
      <?php /* Links are hidden by role for clarity; the actual boundary is
               Authz on every screen, not this menu. */ ?>
      <nav class="admin-nav">
        <a href="<?= url('/admin') ?>">ダッシュボード</a>
        <a href="<?= url('/admin/bookings') ?>">申込一覧</a>
        <a href="<?= url('/admin/events') ?>">イベント</a>
        <?php if (Auth::isSuperadmin()): ?>
          <a href="<?= url('/admin/companies') ?>">会社</a>
          <a href="<?= url('/admin/mail') ?>">メール</a>
          <a href="<?= url('/admin/users') ?>">アカウント</a>
        <?php endif; ?>
        <a href="<?= url('/') ?>" target="_blank" rel="noopener">公開サイト</a>
      </nav>
      <form class="inline-form" method="post" action="<?= url('/admin/logout') ?>">
        <?= Csrf::field() ?>
        <span class="muted">
          <?= e($user['display_name']) ?>
          <span class="badge <?= Auth::isSuperadmin() ? 'badge--ok' : 'badge--muted' ?>">
            <?= e($user['role']->label()) ?>
          </span>
        </span>
        <button type="submit" class="btn btn--ghost btn--small">ログアウト</button>
      </form>
    <?php endif; ?>
  </div>
</header>

<main class="wrap wrap--wide">
<?= App\Core\View::renderPartial('partials/flash') ?>
<?= $content ?>
</main>
</body>
</html>
