<?php
$pageTitle = e($office['name']) . ' — Vulcan Stock';
ob_start();
?>
<div class="app-wrapper">

  <!-- Top Bar -->
  <div class="topbar">
    <span class="topbar-brand">
      <i class="bi bi-lightning-charge-fill"></i> Vulcan Stock
    </span>

    <div class="office-tabs" id="officeTabs">
      <?php foreach ($offices as $o): ?>
      <button
        class="office-tab <?= $o['id'] === $office['id'] ? 'active' : '' ?>"
        data-office-id="<?= (int)$o['id'] ?>"
        onclick="switchOffice(<?= (int)$o['id'] ?>)">
        <?= e($o['name']) ?>
      </button>
      <?php endforeach; ?>
    </div>

    <div class="topbar-spacer"></div>
    <a href="/logout" class="btn-logout">
      <i class="bi bi-box-arrow-right"></i> Logout
    </a>
  </div>

  <!-- Body Row -->
  <div class="body-row">

    <!-- Sidebar -->
    <nav class="sidebar" id="sidebar">
      <div class="sidebar-label">Navigation</div>
      <?php
      $navItems = [
        ['main_menu',         'bi-house-fill',     'Main Menu'],
        ['update_inventory',  'bi-box-seam-fill',  'Update Inventory'],
        ['product_history',   'bi-clock-history',  'Product History'],
        ['inventory_history', 'bi-graph-up-arrow', 'Inventory History'],
        ['settings',          'bi-gear-fill',      'Settings'],
      ];
      foreach ($navItems as [$secId, $icon, $label]):
      ?>
      <a class="sidebar-link <?= $section === $secId ? 'active' : '' ?>"
         data-section="<?= $secId ?>"
         onclick="loadSection('<?= $secId ?>'); return false;"
         href="#">
        <i class="bi <?= $icon ?>"></i>
        <span><?= $label ?></span>
      </a>
      <?php endforeach; ?>
    </nav>

    <!-- Content Column -->
    <div class="content-col">
      <div id="page-subheader">
        <span class="ph-office"><?= e($office['name']) ?></span>
        <span class="ph-sep">—</span>
        <span class="ph-section" id="ph-section-label">Loading…</span>
      </div>
      <div id="content" class="content-loading">
        <i class="bi bi-arrow-repeat" style="font-size:1.5rem;animation:spin 1s linear infinite"></i>
      </div>
    </div>

  </div>
</div>
<?php
$content = ob_get_clean();

ob_start();
?>
<script>
let currentOfficeId = <?= (int)$office['id'] ?>;
let currentSection  = '<?= e($section) ?>';

const OFFICE_NAMES = {};
<?php foreach ($offices as $o): ?>
OFFICE_NAMES[<?= (int)$o['id'] ?>] = '<?= e($o['name']) ?>';
<?php endforeach; ?>

const SECTION_LABELS = {
  main_menu:         'Products',
  update_inventory:  'Update Inventory',
  product_history:   'Product History',
  inventory_history: 'Inventory History',
  settings:          'Settings',
};

function updateSubheader(officeId, section) {
  document.querySelector('#page-subheader .ph-office').textContent = OFFICE_NAMES[officeId] || 'Office';
  document.getElementById('ph-section-label').textContent = SECTION_LABELS[section] || section;
}

function loadSection(section) {
  currentSection = section;
  updateSubheader(currentOfficeId, section);
  document.querySelectorAll('.sidebar-link').forEach(el => {
    el.classList.toggle('active', el.dataset.section === section);
  });
  fetchContent(`/partials/${section}?office_id=${currentOfficeId}`);
  history.pushState({}, '', `/dashboard?office_id=${currentOfficeId}&section=${section}`);
}

function switchOffice(officeId) {
  currentOfficeId = officeId;
  updateSubheader(officeId, currentSection);
  document.querySelectorAll('.office-tab').forEach(el => {
    el.classList.toggle('active', parseInt(el.dataset.officeId) === officeId);
  });
  fetchContent(`/partials/${currentSection}?office_id=${officeId}`);
  history.pushState({}, '', `/dashboard?office_id=${officeId}&section=${currentSection}`);
}

function fetchContent(url) {
  const content = document.getElementById('content');
  content.style.opacity = '0.4';
  fetch(url, { credentials: 'same-origin', cache: 'no-store' })
    .then(r => {
      if (r.status === 401 || r.redirected) { location.href = '/login'; return null; }
      return r.text();
    })
    .then(html => {
      if (!html) return;
      content.classList.remove('content-loading');
      content.innerHTML = html;
      content.style.opacity = '1';
      content.querySelectorAll('script').forEach(old => {
        const s = document.createElement('script');
        s.textContent = old.textContent;
        old.parentNode.replaceChild(s, old);
      });
    })
    .catch(() => { content.style.opacity = '1'; });
}

window.addEventListener('popstate', () => {
  const p  = new URLSearchParams(window.location.search);
  currentOfficeId = parseInt(p.get('office_id') || '1');
  currentSection  = p.get('section') || 'main_menu';
  document.querySelectorAll('.office-tab').forEach(el => {
    el.classList.toggle('active', parseInt(el.dataset.officeId) === currentOfficeId);
  });
  document.querySelectorAll('.sidebar-link').forEach(el => {
    el.classList.toggle('active', el.dataset.section === currentSection);
  });
  updateSubheader(currentOfficeId, currentSection);
  fetchContent(`/partials/${currentSection}?office_id=${currentOfficeId}`);
});

document.addEventListener('DOMContentLoaded', () => {
  updateSubheader(currentOfficeId, currentSection);
  fetchContent(`/partials/${currentSection}?office_id=${currentOfficeId}`);
});
</script>
<style>@keyframes spin { to { transform: rotate(360deg); } }</style>
<?php
$scripts = ob_get_clean();
include __DIR__ . '/base.php';
