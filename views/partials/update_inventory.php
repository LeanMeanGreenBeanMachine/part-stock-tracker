<!-- Adjust Modal -->
<div class="modal fade" id="adjModal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content modal-content-dark">
      <form method="POST" action="/api/update_inventory">
        <input type="hidden" name="office_id" value="<?= (int)$office['id'] ?>" />
        <input type="hidden" name="part_name" id="adjPartName" />
        <input type="hidden" name="action"    id="adjAction" />

        <div class="modal-header-dark">
          <span class="modal-title" id="adjModalTitle">Adjust Stock</span>
          <button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body-dark">
          <label class="form-label-dark" for="adjAmount">Amount</label>
          <div style="display:flex;align-items:center;gap:0.4rem">
            <button type="button" class="btn-outline-subtle" onclick="stepAdj(-1)"
                    style="flex-shrink:0;padding:0.25rem 0.7rem;font-size:1.1rem;line-height:1">−</button>
            <input type="number" name="amount" id="adjAmount"
                   class="form-control-dark" min="1" step="1" placeholder="0" required autofocus />
            <button type="button" class="btn-neon" onclick="stepAdj(1)"
                    style="flex-shrink:0;padding:0.25rem 0.7rem;font-size:1.1rem;line-height:1">+</button>
          </div>
          <label class="form-label-dark mt-3" for="adjNote">Note (optional)</label>
          <input type="text" name="note" id="adjNote"
                 class="form-control-dark" placeholder="Reason or source…" />
        </div>
        <div class="modal-footer-dark d-flex justify-content-end gap-2">
          <button type="button" class="btn-outline-subtle" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn-neon" id="adjSubmitBtn">Confirm</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Transfer Modal -->
<div class="modal fade" id="xfrModal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content modal-content-dark">
      <form method="POST" action="/api/transfer_inventory">
        <input type="hidden" name="office_id" value="<?= (int)$office['id'] ?>" />
        <input type="hidden" name="part_name" id="xfrPartName" />

        <div class="modal-header-dark">
          <span class="modal-title">Transfer to
            <strong><?= e($otherOffice ? $otherOffice['name'] : 'Other Office') ?></strong>
          </span>
          <button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body-dark">
          <div class="mb-2" style="font-size:0.82rem;color:var(--text-secondary)" id="xfrPartLabel"></div>
          <label class="form-label-dark" for="xfrAmount">Amount to transfer</label>
          <div style="display:flex;align-items:center;gap:0.4rem">
            <button type="button" class="btn-outline-subtle" onclick="stepXfr(-1)"
                    style="flex-shrink:0;padding:0.25rem 0.7rem;font-size:1.1rem;line-height:1">−</button>
            <input type="number" name="amount" id="xfrAmount"
                   class="form-control-dark" min="1" step="1" placeholder="0" required />
            <button type="button" class="btn-neon" onclick="stepXfr(1)"
                    style="flex-shrink:0;padding:0.25rem 0.7rem;font-size:1.1rem;line-height:1">+</button>
          </div>
        </div>
        <div class="modal-footer-dark d-flex justify-content-end gap-2">
          <button type="button" class="btn-outline-subtle" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn-neon">Transfer</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Ready Stock Table -->
<div class="card-dark mb-3" style="overflow:hidden">
  <div style="padding:0.55rem 1rem;border-bottom:1px solid #222;font-size:0.75rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.06em">
    <i class="bi bi-box-seam me-1"></i> Ready Stock
  </div>
  <table class="table-dark-custom">
    <thead><tr><th></th><th>Product</th><th>Qty</th><th style="text-align:right;padding-right:1rem">Actions</th></tr></thead>
    <tbody>
      <?php foreach ($parts as $part):
        if (!in_array($part['name'], $stockPartNames, true)) continue;
        $qty  = $inventoryMap[$part['name']] ?? 0;
        $img  = PART_IMAGES[$part['name']] ?? null;
        $disp = str_replace(' [Ready]', '', $part['name']);
      ?>
      <tr>
        <td style="width:36px;padding:0.5rem 0 0.5rem 0.75rem">
          <?php if ($img): ?>
          <img src="/static/images/products/<?= e($img) ?>" alt="<?= e($disp) ?>"
               style="width:28px;height:28px;object-fit:contain;background:var(--bg-elevated);border-radius:3px"
               onerror="this.onerror=null;this.src='data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';" />
          <?php endif; ?>
        </td>
        <td><?= e($disp) ?></td>
        <td><strong style="color:var(--neon)"><?= e(fmt((float)$qty)) ?></strong></td>
        <td style="text-align:right;padding-right:0.75rem">
          <div class="d-flex gap-2 justify-content-end">
            <button class="btn-neon" style="font-size:0.76rem;padding:0.25rem 0.65rem"
                    onclick="openAdj('<?= e(addslashes($part['name'])) ?>', 'add', <?= (float)$qty ?>)">
              <i class="bi bi-plus-lg"></i><span class="d-none d-sm-inline"> Add</span>
            </button>
            <button class="btn-outline-subtle" style="font-size:0.76rem"
                    onclick="openAdj('<?= e(addslashes($part['name'])) ?>', 'subtract', <?= (float)$qty ?>)">
              <i class="bi bi-dash-lg"></i><span class="d-none d-sm-inline"> Sub</span>
            </button>
            <button class="btn-outline-subtle" style="font-size:0.76rem"
                    onclick="openXfr('<?= e(addslashes($part['name'])) ?>', <?= (float)$qty ?>)">
              <i class="bi bi-arrow-left-right"></i><span class="d-none d-sm-inline"> Transfer</span>
            </button>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- Parts Table -->
<div class="card-dark" style="overflow:hidden">
  <div style="padding:0.55rem 1rem;border-bottom:1px solid #222;font-size:0.75rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.06em">
    <i class="bi bi-boxes me-1"></i> Parts
  </div>
  <table class="table-dark-custom">
    <thead><tr><th></th><th>Part</th><th>Qty</th><th class="d-none d-sm-table-cell">Unit</th><th style="text-align:right;padding-right:1rem">Actions</th></tr></thead>
    <tbody>
      <?php foreach ($parts as $part):
        if (in_array($part['name'], $stockPartNames, true)) continue;
        $qty = $inventoryMap[$part['name']] ?? 0;
        $img = PART_IMAGES[$part['name']] ?? null;
      ?>
      <tr>
        <td style="width:36px;padding:0.5rem 0 0.5rem 0.75rem">
          <?php if ($img): ?>
          <img src="/static/images/parts/<?= e($img) ?>" alt="<?= e($part['name']) ?>"
               style="width:28px;height:28px;object-fit:contain;background:var(--bg-elevated);border-radius:3px"
               onerror="this.onerror=null;this.src='data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';" />
          <?php endif; ?>
        </td>
        <td><?= e($part['name']) ?></td>
        <td><strong style="color:<?= $qty <= 3 ? 'var(--danger)' : 'var(--text-primary)' ?>"><?= e(fmt((float)$qty)) ?></strong></td>
        <td class="d-none d-sm-table-cell" style="color:var(--text-muted)"><?= e($part['unit']) ?></td>
        <td style="text-align:right;padding-right:0.75rem">
          <div class="d-flex gap-2 justify-content-end">
            <button class="btn-neon" style="font-size:0.76rem;padding:0.25rem 0.65rem"
                    onclick="openAdj('<?= e(addslashes($part['name'])) ?>', 'add', <?= (float)$qty ?>)">
              <i class="bi bi-plus-lg"></i><span class="d-none d-sm-inline"> Add</span>
            </button>
            <button class="btn-outline-subtle" style="font-size:0.76rem"
                    onclick="openAdj('<?= e(addslashes($part['name'])) ?>', 'subtract', <?= (float)$qty ?>)">
              <i class="bi bi-dash-lg"></i><span class="d-none d-sm-inline"> Sub</span>
            </button>
            <button class="btn-outline-subtle" style="font-size:0.76rem"
                    onclick="openXfr('<?= e(addslashes($part['name'])) ?>', <?= (float)$qty ?>)">
              <i class="bi bi-arrow-left-right"></i><span class="d-none d-sm-inline"> Transfer</span>
            </button>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<script>
const adjModal = new bootstrap.Modal(document.getElementById('adjModal'));
const xfrModal = new bootstrap.Modal(document.getElementById('xfrModal'));

const PART_STEPS = <?= json_encode($partSteps) ?>;
const PART_UNITS = <?= json_encode($partUnits) ?>;

function getStep(partName) {
  if (partName.includes('[Ready]')) return 1;
  if ((PART_UNITS[partName] || 'units') === 'units') return 1;
  return PART_STEPS[partName] || 1;
}

function stepAdj(dir) {
  const input = document.getElementById('adjAmount');
  const step  = parseFloat(input.step) || 1;
  const cur   = parseFloat(input.value) || 0;
  const max   = input.max ? parseFloat(input.max) : Infinity;
  input.value = Math.min(max, Math.max(step, parseFloat((cur + dir * step).toFixed(6))));
}

function stepXfr(dir) {
  const input = document.getElementById('xfrAmount');
  const step  = parseFloat(input.step) || 1;
  const cur   = parseFloat(input.value) || 0;
  const max   = input.max ? parseFloat(input.max) : Infinity;
  input.value = Math.min(max, Math.max(step, parseFloat((cur + dir * step).toFixed(6))));
}

function openAdj(partName, action, currentQty) {
  const step   = getStep(partName);
  const isAdd  = action === 'add';
  const disp   = partName.replace(' [Ready]', '');
  const amount = document.getElementById('adjAmount');

  document.getElementById('adjPartName').value  = partName;
  document.getElementById('adjAction').value    = action;
  document.getElementById('adjNote').value      = '';
  document.getElementById('adjModalTitle').textContent = (isAdd ? 'Add Stock — ' : 'Remove Stock — ') + disp;
  document.getElementById('adjSubmitBtn').textContent  = isAdd ? 'Add Stock' : 'Remove Stock';

  amount.step = step;
  amount.min  = step;
  amount.value = '';
  if (!isAdd) { amount.max = currentQty; amount.placeholder = `Max: ${currentQty}`; }
  else        { amount.removeAttribute('max'); amount.placeholder = '0'; }

  adjModal.show();
  setTimeout(() => amount.focus(), 400);
}

function openXfr(partName, currentQty) {
  const step  = getStep(partName);
  const disp  = partName.replace(' [Ready]', '');
  const input = document.getElementById('xfrAmount');

  input.step  = step;
  input.min   = step;
  input.value = '';
  input.max   = currentQty;
  document.getElementById('xfrPartName').value   = partName;
  document.getElementById('xfrPartLabel').textContent = `${disp} — ${currentQty} in stock`;
  xfrModal.show();
  setTimeout(() => input.focus(), 400);
}
</script>
