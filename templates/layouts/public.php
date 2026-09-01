<?php
/**
 * @var string $content
 * @var string|null $title
 */
$pageTitle = isset($title) && $title !== '' ? $title . ' | イベント予約' : 'イベント予約';
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
    <a class="site-title" href="<?= url('/') ?>">イベント予約</a>
  </div>
</header>

<main class="wrap">
<?= App\Core\View::renderPartial('partials/flash') ?>
<?= $content ?>
</main>

<footer class="site-footer">
  <div class="wrap">
    <p>予約内容の確認・キャンセルは、予約完了メールに記載のURLから行えます。</p>
  </div>
</footer>
</body>
</html>
