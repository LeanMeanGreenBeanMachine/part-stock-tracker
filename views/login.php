<?php
$pageTitle = 'Login — Vulcan Stock Tracker';
ob_start();
?>
<div class="login-wrapper">
  <div class="login-card">
    <div class="login-logo">
      <div class="login-logo-icon"><i class="bi bi-lightning-charge-fill"></i></div>
      <h1>Vulcan Stock</h1>
      <p>Internal Inventory Tracker</p>
    </div>

    <?php if ($error ?? null): ?>
    <div class="toast-item toast-danger mb-3">
      <i class="bi bi-exclamation-circle-fill"></i>
      <span><?= e($error) ?></span>
    </div>
    <?php endif; ?>

    <form method="POST" action="/login">
      <div class="mb-3">
        <label class="form-label-dark" for="username">Username</label>
        <input type="text" id="username" name="username" class="form-control-dark"
               placeholder="Enter username" autocomplete="username" autofocus required />
      </div>
      <div class="mb-4">
        <label class="form-label-dark" for="password">Password</label>
        <input type="password" id="password" name="password" class="form-control-dark"
               placeholder="Enter password" autocomplete="current-password" required />
      </div>
      <button type="submit" class="btn-neon w-100" style="padding:0.55rem">
        <i class="bi bi-box-arrow-in-right me-1"></i> Sign In
      </button>
    </form>
  </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/base.php';
