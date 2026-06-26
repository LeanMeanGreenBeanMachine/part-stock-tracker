<div class="card-dark p-4">
  <div style="margin-bottom:1rem">
    <h6 style="color:var(--text-primary);margin-bottom:0.4rem">
      Notification Contacts
      <i class="bi bi-question-circle ms-1"
         style="color:var(--text-muted);font-size:0.82rem;cursor:pointer;vertical-align:middle"
         data-bs-toggle="tooltip" data-bs-placement="right"
         title="Contacts are global across both offices. Each contact has its own notification threshold — alerts fire per-contact when stock drops to or below their threshold. Advanced mode enables per-part raw quantity alerts."></i>
    </h6>
    <div style="font-size:0.82rem;color:var(--text-secondary);margin-bottom:0.75rem">
      Current lowest buildable:
      <strong style="color:var(--neon)"><?= (int)$lowestBuildable ?></strong>
      <?php if ($bottleneck): ?>
        <span style="color:var(--text-muted)">(bottleneck: <?= e($bottleneck) ?>)</span>
      <?php endif; ?>
    </div>
    <button class="btn-neon" data-bs-toggle="modal" data-bs-target="#addContactModal"
            style="font-size:0.8rem;padding:0.3rem 0.85rem">
      <i class="bi bi-plus-lg me-1"></i>Add Contact
    </button>
  </div>

  <?php if ($contacts): ?>
    <?php foreach ($contacts as $contact):
      $ocs        = $ocsMap[$contact['id']] ?? null;
      $enabled    = $ocs && $ocs['notifications_enabled'];
      $cThreshold = $ocs ? (int)$ocs['threshold'] : 3;
      $cAdvanced  = $ocs && $ocs['advanced_mode'];
    ?>
    <div class="contact-card" style="flex-wrap:wrap;align-items:flex-start">

      <div class="contact-card-info" style="flex:1 1 auto">
        <div class="contact-label">
          <?= e($contact['label'] ?: $contact['method']) ?>
          <span style="font-size:0.72rem;font-weight:400;color:var(--text-muted);margin-left:0.4rem">
            <?= e($contact['method']) ?>
          </span>
        </div>
        <div class="contact-detail">
          <?php if ($contact['email']): ?>📧 <?= e($contact['email']) ?><?php endif; ?>
          <?php if ($contact['telegram_chat_id']): ?>🤖 Chat <?= e($contact['telegram_chat_id']) ?><?php endif; ?>
        </div>
      </div>

      <form method="POST" action="/api/toggle_contact" style="margin:0">
        <input type="hidden" name="office_id"  value="<?= (int)$office['id'] ?>" />
        <input type="hidden" name="contact_id" value="<?= (int)$contact['id'] ?>" />
        <label class="toggle-switch"
               title="<?= $enabled ? 'Enabled for ' . e($office['name']) : 'Disabled for ' . e($office['name']) ?>">
          <input type="checkbox" <?= $enabled ? 'checked' : '' ?> onchange="this.form.submit()" />
          <span class="toggle-slider"></span>
        </label>
      </form>

      <form method="POST" action="/api/delete_contact" style="margin:0"
            onsubmit="return confirm('Delete this contact? Cannot be undone.')">
        <input type="hidden" name="office_id"  value="<?= (int)$office['id'] ?>" />
        <input type="hidden" name="contact_id" value="<?= (int)$contact['id'] ?>" />
        <button type="submit" class="btn-danger-subtle" style="padding:0.25rem 0.6rem;font-size:0.8rem">
          <i class="bi bi-trash"></i>
        </button>
      </form>

      <div style="width:100%;margin-top:0.75rem;padding-top:0.75rem;border-top:1px solid #2a2a2a">

        <?php if (!$cAdvanced): ?>
        <form method="POST" action="/api/save_contact_threshold"
              style="display:flex;align-items:center;gap:0.6rem;flex-wrap:wrap;margin-bottom:0.6rem">
          <input type="hidden" name="office_id"  value="<?= (int)$office['id'] ?>" />
          <input type="hidden" name="contact_id" value="<?= (int)$contact['id'] ?>" />
          <span style="font-size:0.78rem;color:var(--text-muted)">Alert at:</span>
          <span style="font-size:0.75rem;color:var(--text-muted)">1</span>
          <input type="range" name="threshold" id="slider_<?= (int)$contact['id'] ?>"
                 class="range-neon" min="1" max="20" step="1" value="<?= $cThreshold ?>"
                 oninput="updateSliderFill(this); document.getElementById('tv_<?= (int)$contact['id'] ?>').textContent = this.value"
                 style="flex:1;min-width:80px" />
          <span style="font-size:0.75rem;color:var(--text-muted)">20</span>
          <span id="tv_<?= (int)$contact['id'] ?>"
                style="font-size:1rem;font-weight:700;color:var(--neon);min-width:2rem;text-align:center">
            <?= $cThreshold ?>
          </span>
          <button type="submit" class="btn-neon" style="font-size:0.75rem;padding:0.2rem 0.6rem">
            <i class="bi bi-save me-1"></i>Save
          </button>
        </form>
        <?php else: ?>
        <div style="font-size:0.78rem;color:var(--text-muted);margin-bottom:0.6rem">
          Advanced mode active — per-part thresholds in use.
        </div>
        <?php endif; ?>

        <div style="display:flex;align-items:center;gap:0.75rem;flex-wrap:wrap">
          <form method="POST" action="/api/toggle_advanced_mode"
                style="margin:0;display:flex;align-items:center;gap:0.5rem">
            <input type="hidden" name="office_id"  value="<?= (int)$office['id'] ?>" />
            <input type="hidden" name="contact_id" value="<?= (int)$contact['id'] ?>" />
            <label class="toggle-switch" style="transform:scale(0.85)" title="Toggle advanced per-part threshold mode">
              <input type="checkbox" <?= $cAdvanced ? 'checked' : '' ?> onchange="this.form.submit()" />
              <span class="toggle-slider"></span>
            </label>
            <span style="font-size:0.78rem;color:var(--text-muted)">
              Advanced
              <i class="bi bi-question-circle ms-1" style="cursor:pointer"
                 data-bs-toggle="tooltip" data-bs-placement="top"
                 title="Advanced mode lets you set raw quantity thresholds per part. Each part alerts independently — once per dip — when its quantity drops to or below your threshold."></i>
            </span>
          </form>
        </div>

        <?php if ($cAdvanced): ?>
        <form method="POST" action="/api/save_advanced_thresholds" style="margin-top:0.75rem">
          <input type="hidden" name="office_id"  value="<?= (int)$office['id'] ?>" />
          <input type="hidden" name="contact_id" value="<?= (int)$contact['id'] ?>" />
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:0.35rem 1.25rem;margin-bottom:0.6rem">
            <?php foreach ($parts as $part):
              $ptVal = $partThresholdsMap[$contact['id']][$part['id']] ?? 0;
              $disp  = str_replace(' [Ready]', '', $part['name']);
            ?>
            <div style="display:flex;align-items:center;gap:0.4rem">
              <span style="font-size:0.75rem;color:var(--text-secondary);flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                    title="<?= e($part['name']) ?>"><?= e($disp) ?></span>
              <input type="number" name="part_<?= (int)$part['id'] ?>"
                     value="<?= (int)$ptVal ?>" min="0" max="9999"
                     class="form-control-dark"
                     style="width:64px;text-align:center;padding:0.2rem 0.3rem;font-size:0.78rem;flex-shrink:0" />
            </div>
            <?php endforeach; ?>
          </div>
          <div style="font-size:0.72rem;color:var(--text-muted);margin-bottom:0.5rem">0 = no alert for that part</div>
          <button type="submit" class="btn-neon" style="font-size:0.75rem;padding:0.2rem 0.6rem">
            <i class="bi bi-save me-1"></i>Save Thresholds
          </button>
        </form>
        <?php endif; ?>

      </div>
    </div>
    <?php endforeach; ?>
  <?php else: ?>
  <div class="empty-state" style="padding:1.5rem">
    <i class="bi bi-bell-slash"></i>
    No contacts added yet.
  </div>
  <?php endif; ?>
</div>

<div style="margin-top:0.75rem;font-size:0.76rem;color:var(--text-muted);display:flex;align-items:center;gap:0.4rem;flex-wrap:wrap">
  <i class="bi bi-info-circle"></i>
  Low-stock checks are triggered by calling
  <code style="color:var(--neon);font-size:0.76rem">/api/check_low_stock?token=…</code>
  from a cron job or uptime monitor.
</div>

<!-- Add Contact Modal -->
<div class="modal fade" id="addContactModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content modal-content-dark">
      <form method="POST" action="/api/add_contact">
        <input type="hidden" name="office_id" value="<?= (int)$office['id'] ?>" />

        <div class="modal-header-dark">
          <span class="modal-title">Add Notification Contact</span>
          <button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="modal"></button>
        </div>

        <div class="modal-body-dark">
          <div class="mb-3">
            <label class="form-label-dark">Display Name <span style="color:var(--text-muted)">(optional)</span></label>
            <input type="text" name="label" class="form-control-dark" placeholder="e.g. Brandon's Phone" />
          </div>
          <div class="mb-3">
            <label class="form-label-dark">Contact Method</label>
            <select name="method" id="contactMethod" class="form-select-dark"
                    onchange="updateContactFields()" required>
              <option value="Email">Email</option>
              <option value="Telegram">Telegram</option>
              <option value="Both">Both</option>
            </select>
          </div>
          <div id="emailFields">
            <div class="mb-3">
              <label class="form-label-dark">Email Address</label>
              <input type="email" name="email" id="emailInput"
                     class="form-control-dark" placeholder="you@example.com" />
            </div>
          </div>
          <div id="telegramFields" style="display:none">
            <div class="mb-3">
              <label class="form-label-dark">Telegram Bot Token</label>
              <input type="text" name="telegram_bot_token" class="form-control-dark"
                     placeholder="123456:ABC-DEF…" />
            </div>
            <div class="mb-3">
              <label class="form-label-dark">Telegram Chat ID</label>
              <input type="text" name="telegram_chat_id" class="form-control-dark"
                     placeholder="-100123456789" />
            </div>
          </div>
        </div>

        <div class="modal-footer-dark d-flex justify-content-end gap-2">
          <button type="button" class="btn-outline-subtle" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn-neon">Add Contact</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => new bootstrap.Tooltip(el, { trigger: 'hover focus' }));

function updateSliderFill(el) {
  const pct = (el.value - el.min) / (el.max - el.min) * 100;
  el.style.background = `linear-gradient(to right, #6cc546 ${pct}%, #252525 ${pct}%)`;
}
document.querySelectorAll('.range-neon').forEach(s => updateSliderFill(s));

function updateContactFields() {
  const method = document.getElementById('contactMethod').value;
  document.getElementById('emailFields').style.display    = method !== 'Telegram' ? '' : 'none';
  document.getElementById('telegramFields').style.display = method !== 'Email'    ? '' : 'none';
  document.getElementById('emailInput').required          = method !== 'Telegram';
}
</script>
