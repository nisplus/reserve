<?php
$messages = App\Core\Flash::take();
if ($messages === []) {
    return;
}
?>
<div class="flash-stack">
<?php foreach ($messages as $flash): ?>
  <div class="flash flash--<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
<?php endforeach; ?>
</div>
