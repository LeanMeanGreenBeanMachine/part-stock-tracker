<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?= e($pageTitle ?? 'Vulcan Stock Tracker') ?></title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" />
  <script src="https://unpkg.com/htmx.org@1.9.10" defer></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns@3.0.0/dist/chartjs-adapter-date-fns.bundle.min.js"></script>
  <link rel="stylesheet" href="/static/css/style.css" />
</head>
<body>

<?php $flashes = getFlashes(); if ($flashes): ?>
<div class="toast-stack" id="toastStack">
  <?php foreach ($flashes as [$cat, $msg]): ?>
  <div class="toast-item toast-<?= e($cat) ?>">
    <?php if ($cat === 'success'): ?><i class="bi bi-check-circle-fill"></i>
    <?php elseif ($cat === 'danger'):  ?><i class="bi bi-exclamation-circle-fill"></i>
    <?php elseif ($cat === 'warning'): ?><i class="bi bi-exclamation-triangle-fill"></i>
    <?php else: ?><i class="bi bi-info-circle-fill"></i><?php endif; ?>
    <span><?= e($msg) ?></span>
  </div>
  <?php endforeach; ?>
</div>
<script>
  setTimeout(() => {
    const stack = document.getElementById('toastStack');
    if (stack) { stack.style.transition = 'opacity 0.4s'; stack.style.opacity = '0'; }
    setTimeout(() => { if (stack) stack.remove(); }, 400);
  }, 4000);
</script>
<?php endif; ?>

<?= $content ?? '' ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<?= $scripts ?? '' ?>
</body>
</html>
