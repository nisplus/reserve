<?php
/**
 * @var string $content
 * @var string|null $title
 */
$siteTitle = App\Core\Config::siteTitle();
$pageTitle = isset($title) && $title !== '' ? $title . ' | ' . $siteTitle : $siteTitle;
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?></title>
<link rel="stylesheet" href="<?= url('/assets/css/app.css') ?>">
</head>
<body>
<header class="site-header">
  <div class="wrap">
    <a class="site-title" href="<?= url('/') ?>"><?= e($siteTitle) ?></a>
  </div>
</header>

<main class="wrap">
<?= App\Core\View::renderPartial('partials/flash') ?>
<?= $content ?>
</main>

<footer class="site-footer">
  <div class="wrap">
    <p>予約内容の確認・キャンセルは、予約完了メールに記載のURLから行えます。</p>
    <?php $mailDomain = App\Core\Config::string('site.mail_domain'); ?>
    <?php if ($mailDomain !== ''): ?>
      <?php /* Left out entirely when no domain is configured: advice that
               names no domain only teaches people to skip the footer. */ ?>
      <p><?= e($mailDomain) ?> アドレスからのメールを受信できるように、迷惑メール設定から解除、もしくは受信許可設定をお願い致します</p>
    <?php endif; ?>
  </div>
</footer>
</body>
</html>
