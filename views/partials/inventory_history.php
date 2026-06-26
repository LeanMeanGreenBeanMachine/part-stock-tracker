<div class="chart-container">
  <canvas id="inventoryChart"></canvas>
</div>

<div style="font-size:0.8rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.06em;margin:1rem 0 0.6rem">
  Recent Changes
</div>

<?php if ($recentLogs): ?>
<div class="card-dark" style="overflow:hidden">
  <table class="table-dark-custom">
    <thead>
      <tr>
        <th>Timestamp</th>
        <th>Part</th>
        <th>Type</th>
        <th>Amount</th>
        <th>After</th>
        <th class="d-none d-sm-table-cell">Note</th>
        <th style="text-align:right;padding-right:1rem"></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($recentLogs as $log):
        $changeType = $log['change_type'];
      ?>
      <tr>
        <td style="color:var(--text-secondary);white-space:nowrap">
          <?= e(substr($log['timestamp'], 0, 16)) ?>
        </td>
        <td><strong><?= e($log['part_name']) ?></strong></td>
        <td>
          <span class="badge-change badge-<?= e($changeType) ?>">
            <?= e(str_replace('_', ' ', $changeType)) ?>
          </span>
        </td>
        <td><?= e(fmt((float)$log['amount'])) ?></td>
        <td style="color:var(--text-secondary)">
          <?= $log['resulting_quantity'] !== null ? e(fmt((float)$log['resulting_quantity'])) : '—' ?>
        </td>
        <td class="d-none d-sm-table-cell" style="color:var(--text-muted);font-size:0.8rem">
          <?= e($log['note'] ?: '—') ?>
        </td>
        <td style="text-align:right;padding-right:0.75rem">
          <?php if (in_array($changeType, ['add', 'subtract'], true)): ?>
          <form method="POST" action="/api/strike_inventory_log/<?= (int)$log['id'] ?>"
                style="margin:0;display:inline"
                onsubmit="return confirm('Remove this log entry and reverse the inventory change?')">
            <button type="submit" class="btn-danger-subtle" style="font-size:0.76rem">
              <i class="bi bi-x-circle"></i><span class="d-none d-sm-inline"> Strike</span>
            </button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<p class="mt-2" style="font-size:0.76rem;color:var(--text-muted)">Showing last 50 inventory changes.</p>
<?php else: ?>
<div class="empty-state">
  <i class="bi bi-inbox"></i>
  No inventory changes recorded yet.
</div>
<?php endif; ?>

<script>
(function() {
  const datasets = <?= json_encode($chartDatasets) ?>;
  const ctx = document.getElementById('inventoryChart');
  if (!ctx) return;
  new Chart(ctx, {
    type: 'line',
    data: { datasets },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      animation: { duration: 400 },
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: {
          position: 'bottom',
          labels: { color: '#888', font: { size: 11 }, boxWidth: 12, padding: 12, usePointStyle: true }
        },
        tooltip: {
          backgroundColor: '#1e1e1e', borderColor: '#333', borderWidth: 1,
          titleColor: '#e8e8e8', bodyColor: '#aaa', padding: 10,
        }
      },
      scales: {
        x: {
          type: 'time',
          time: { unit: 'day', displayFormats: { day: 'MMM d' } },
          grid: { color: '#222' }, ticks: { color: '#555', maxTicksLimit: 8 },
        },
        y: {
          beginAtZero: true,
          grid: { color: '#222' }, ticks: { color: '#555' },
        }
      }
    }
  });
})();
</script>
