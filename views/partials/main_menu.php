<div class="main-menu-layout">

  <!-- Left: Product Cards -->
  <div class="product-list">
    <?php foreach ($productData as $name => $data):
      $canBuild = $data['premade'] >= 1 || $data['buildable'] >= 1;
    ?>
    <div class="product-row">

      <img src="/static/images/products/<?= e($data['image']) ?>"
           alt="<?= e($name) ?>"
           class="product-img"
           onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';" />
      <div class="product-img-placeholder" style="display:none"><i class="bi bi-box"></i></div>

      <div class="product-info">
        <div class="product-name"><?= e($name) ?></div>

        <div class="bom-label">Used Parts</div>
        <div class="bom-items">
          <?php $i = 0; foreach ($data['used_parts'] as $partName => $qty):
            echo '<span class="bom-qty">' . e(fmt((float)$qty)) . "</span>&thinsp;" . e($partName);
            if (++$i < count($data['used_parts'])) echo ',&nbsp;';
          endforeach; ?>
        </div>

        <div class="bom-label" style="margin-top:0.3rem">Contains</div>
        <div class="bom-items" style="color:var(--text-muted)">
          <?= e(implode(', ', $data['contains'])) ?>
        </div>
      </div>

      <div class="product-action">
        <div class="buildable-badge">
          <?php if ($data['premade'] >= 1): ?>
            <span style="color:var(--neon);font-weight:700"><?= (int)$data['premade'] ?> ready</span>
            <?php if ($data['buildable'] >= 1): ?>
              <span style="color:var(--text-muted);font-size:0.72rem"> + <?= (int)$data['buildable'] ?> from parts</span>
            <?php endif; ?>
          <?php else: ?>
            From parts: <span class="buildable-count"><?= (int)$data['buildable'] ?></span>
          <?php endif; ?>
        </div>

        <?php if ($canBuild): ?>
        <form method="POST" action="/api/log_order" style="margin:0">
          <input type="hidden" name="office_id"    value="<?= (int)$office['id'] ?>" />
          <input type="hidden" name="product_name" value="<?= e($name) ?>" />
          <button type="submit" class="btn-neon">
            <i class="bi bi-plus-circle-fill me-1"></i>Log Order
          </button>
        </form>
        <?php else: ?>
        <button class="btn-neon disabled" disabled
                title="No pre-made stock and not enough parts. Bottleneck: <?= e($data['bottleneck'] ?? 'N/A') ?>"
                data-bs-toggle="tooltip" data-bs-placement="left">
          <i class="bi bi-slash-circle me-1"></i>Log Order
        </button>
        <div style="font-size:0.72rem;color:var(--danger);text-align:right;max-width:120px">
          Low: <?= e($data['bottleneck'] ?? '') ?>
        </div>
        <?php endif; ?>
      </div>

    </div>
    <?php endforeach; ?>
  </div>

  <!-- Right: Inventory Sidebar -->
  <div class="inv-sidebar">
    <div class="inv-sidebar-title"><i class="bi bi-boxes"></i> Current Stock</div>

    <?php foreach ($parts as $part):
      if (in_array($part['name'], $stockPartNames, true)) continue;
      $qty = $inventoryMap[$part['name']] ?? 0;
      $img = PART_IMAGES[$part['name']] ?? null;
    ?>
    <div class="inv-part-row">
      <?php if ($img): ?>
      <img src="/static/images/parts/<?= e($img) ?>" alt="<?= e($part['name']) ?>"
           class="inv-part-img"
           onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';" />
      <?php endif; ?>
      <div class="inv-part-img-placeholder" style="display:none"><i class="bi bi-circle"></i></div>
      <span class="inv-part-name" title="<?= e($part['name']) ?>"><?= e($part['name']) ?></span>
      <span class="inv-part-qty <?= $qty <= 3 ? 'low' : '' ?>"><?= e(fmt((float)$qty)) ?></span>
    </div>
    <?php endforeach; ?>

    <?php
    $readyParts = array_filter($parts, fn($p) => in_array($p['name'], $stockPartNames, true));
    if ($readyParts): ?>
    <div class="inv-sidebar-title" style="margin-top:0.85rem;padding-top:0.6rem;border-top:1px solid #2a2a2a;font-size:0.7rem">
      <i class="bi bi-box-seam"></i> Ready Stock
    </div>
    <?php foreach ($readyParts as $part):
      $qty  = $inventoryMap[$part['name']] ?? 0;
      $img  = PART_IMAGES[$part['name']] ?? null;
      $disp = str_replace(' [Ready]', '', $part['name']);
    ?>
    <div class="inv-part-row">
      <?php if ($img): ?>
      <img src="/static/images/products/<?= e($img) ?>" alt="<?= e($disp) ?>"
           class="inv-part-img"
           onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';" />
      <?php endif; ?>
      <div class="inv-part-img-placeholder" style="display:none"><i class="bi bi-circle"></i></div>
      <span class="inv-part-name" title="<?= e($disp) ?>"><?= e($disp) ?></span>
      <span class="inv-part-qty" style="color:var(--neon)"><?= e(fmt((float)$qty)) ?></span>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

  </div>

</div>

<script>
document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => new bootstrap.Tooltip(el));
</script>
