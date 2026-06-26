<!-- Filter Bar -->
<div class="card-dark p-3 mb-3">
  <div class="d-flex flex-wrap gap-3 align-items-end">
    <div>
      <label class="form-label-dark">Filter by Product</label>
      <select id="filterProduct" class="form-select-dark" style="width:180px" onchange="applyFilters()">
        <option value="">All Products</option>
        <?php foreach ($productNames as $name): ?>
        <option value="<?= e($name) ?>"><?= e($name) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="form-label-dark">From Date</label>
      <input type="date" id="filterFrom" class="form-control-dark" style="width:150px" onchange="applyFilters()" />
    </div>
    <div>
      <label class="form-label-dark">To Date</label>
      <input type="date" id="filterTo" class="form-control-dark" style="width:150px" onchange="applyFilters()" />
    </div>
    <button class="btn-outline-subtle" onclick="clearFilters()">
      <i class="bi bi-x-lg"></i> Clear
    </button>
  </div>
</div>

<?php if ($logs): ?>
<div class="card-dark" style="overflow:hidden">
  <table class="table-dark-custom" id="logTable">
    <thead>
      <tr>
        <th>Timestamp</th>
        <th>Product</th>
        <th style="text-align:right;padding-right:1rem">Action</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($logs as $log): ?>
      <tr data-product="<?= e($log['product_name']) ?>"
          data-date="<?= e(substr($log['timestamp'], 0, 10)) ?>">
        <td style="color:var(--text-secondary);white-space:nowrap">
          <?= e(substr($log['timestamp'], 0, 16)) ?>
        </td>
        <td><strong><?= e($log['product_name']) ?></strong></td>
        <td style="text-align:right;padding-right:0.75rem">
          <form method="POST" action="/api/strike_log/<?= (int)$log['id'] ?>"
                style="margin:0;display:inline"
                onsubmit="return confirm('Strike this log entry and restore its inventory?')">
            <button type="submit" class="btn-danger-subtle" style="font-size:0.76rem">
              <i class="bi bi-x-circle"></i> Strike
            </button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<p class="mt-2" style="font-size:0.76rem;color:var(--text-muted)">
  Showing last 50 active log entries. Struck entries are hidden.
</p>
<?php else: ?>
<div class="empty-state">
  <i class="bi bi-inbox"></i>
  No product orders logged yet.
</div>
<?php endif; ?>

<script>
function applyFilters() {
  const product  = document.getElementById('filterProduct').value;
  const fromDate = document.getElementById('filterFrom').value;
  const toDate   = document.getElementById('filterTo').value;
  document.querySelectorAll('#logTable tbody tr').forEach(row => {
    const ok = (!product  || row.dataset.product === product)
            && (!fromDate || row.dataset.date >= fromDate)
            && (!toDate   || row.dataset.date <= toDate);
    row.style.display = ok ? '' : 'none';
  });
}
function clearFilters() {
  document.getElementById('filterProduct').value = '';
  document.getElementById('filterFrom').value    = '';
  document.getElementById('filterTo').value      = '';
  applyFilters();
}
</script>
