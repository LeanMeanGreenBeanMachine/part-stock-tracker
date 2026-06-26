<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/products.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/notifications.php';

// No-cache headers
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

session_start();

// Initialize DB (creates schema + seeds on first run)
getDb();

$method = $_SERVER['REQUEST_METHOD'];
$path   = rtrim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/') ?: '/';

// ── Auth helpers ──────────────────────────────────────────────────────────────

function isLoggedIn(): bool { return !empty($_SESSION['logged_in']); }

// ── Router ────────────────────────────────────────────────────────────────────

// GET /
if ($method === 'GET' && $path === '/') {
    if (!isLoggedIn()) redirect('/login');
    redirect('/dashboard?office_id=1&section=main_menu');
}

// GET /login
if ($method === 'GET' && $path === '/login') {
    if (isLoggedIn()) redirect('/dashboard?office_id=1&section=main_menu');
    $error = null;
    include __DIR__ . '/views/login.php';
    exit;
}

// POST /login
if ($method === 'POST' && $path === '/login') {
    $u = strtolower(trim($_POST['username'] ?? ''));
    $p = strtolower(trim($_POST['password'] ?? ''));
    if ($u === APP_USER && $p === APP_PASS) {
        $_SESSION['logged_in'] = true;
        redirect('/dashboard?office_id=1&section=main_menu');
    }
    $error = 'Invalid username or password.';
    include __DIR__ . '/views/login.php';
    exit;
}

// GET /logout
if ($method === 'GET' && $path === '/logout') {
    session_destroy();
    redirect('/login');
}

// ── Dashboard ──────────────────────────────────────────────────────────────────

if ($method === 'GET' && $path === '/dashboard') {
    requireLogin();
    $db       = getDb();
    $officeId = (int)($_GET['office_id'] ?? 1);
    $section  = $_GET['section'] ?? 'main_menu';
    $validSections = ['main_menu', 'update_inventory', 'product_history', 'inventory_history', 'settings'];
    if (!in_array($section, $validSections, true)) $section = 'main_menu';

    $offices = $db->query('SELECT * FROM offices ORDER BY id')->fetchAll();
    $office  = getOfficeOrAbort($officeId);

    include __DIR__ . '/views/dashboard.php';
    exit;
}

// ── Partials ──────────────────────────────────────────────────────────────────

if ($method === 'GET' && $path === '/partials/main_menu') {
    requireLogin();
    $db           = getDb();
    $officeId     = (int)($_GET['office_id'] ?? 1);
    $office       = getOfficeOrAbort($officeId);
    $inventoryMap = getInventoryMap($officeId);
    $parts        = $db->query('SELECT * FROM parts ORDER BY name')->fetchAll();
    $stockPartNames = array_values(PRODUCT_STOCK_PARTS);

    $productData = [];
    foreach (PRODUCTS as $name => $info) {
        [$buildable, $bottleneck] = calculateBuildable($inventoryMap, $name);
        $premade = getPremadeStock($officeId, $name);
        $productData[$name] = array_merge($info, [
            'buildable'  => $buildable,
            'bottleneck' => $bottleneck,
            'premade'    => $premade,
        ]);
    }
    include __DIR__ . '/views/partials/main_menu.php';
    exit;
}

if ($method === 'GET' && $path === '/partials/update_inventory') {
    requireLogin();
    $db           = getDb();
    $officeId     = (int)($_GET['office_id'] ?? 1);
    $office       = getOfficeOrAbort($officeId);
    $otherOffice  = getOtherOffice($officeId);
    $parts        = $db->query('SELECT * FROM parts ORDER BY name')->fetchAll();
    $inventoryMap = getInventoryMap($officeId);
    $stockPartNames = array_values(PRODUCT_STOCK_PARTS);
    $partSteps      = getPartSteps();
    $partUnits      = [];
    foreach ($parts as $p) $partUnits[$p['name']] = $p['unit'];

    include __DIR__ . '/views/partials/update_inventory.php';
    exit;
}

if ($method === 'GET' && $path === '/partials/product_history') {
    requireLogin();
    $db       = getDb();
    $officeId = (int)($_GET['office_id'] ?? 1);
    $office   = getOfficeOrAbort($officeId);

    $stmt = $db->prepare(
        'SELECT * FROM product_logs WHERE office_id = ? AND struck = 0 ORDER BY timestamp DESC LIMIT 50'
    );
    $stmt->execute([$officeId]);
    $logs        = $stmt->fetchAll();
    $productNames = array_keys(PRODUCTS);

    include __DIR__ . '/views/partials/product_history.php';
    exit;
}

if ($method === 'GET' && $path === '/partials/inventory_history') {
    requireLogin();
    $db       = getDb();
    $officeId = (int)($_GET['office_id'] ?? 1);
    $office   = getOfficeOrAbort($officeId);

    $stmt = $db->prepare(
        'SELECT * FROM inventory_logs WHERE office_id = ? ORDER BY timestamp DESC LIMIT 50'
    );
    $stmt->execute([$officeId]);
    $recentLogs = $stmt->fetchAll();

    $parts         = $db->query('SELECT * FROM parts ORDER BY name')->fetchAll();
    $chartDatasets = buildChartDatasets($officeId, $parts);

    include __DIR__ . '/views/partials/inventory_history.php';
    exit;
}

if ($method === 'GET' && $path === '/partials/settings') {
    requireLogin();
    $db       = getDb();
    $officeId = (int)($_GET['office_id'] ?? 1);
    $office   = getOfficeOrAbort($officeId);

    $stmt = $db->prepare('SELECT * FROM office_settings WHERE office_id = ?');
    $stmt->execute([$officeId]);
    $settings = $stmt->fetch();

    $contacts = $db->query('SELECT * FROM contacts')->fetchAll();
    $parts    = $db->query('SELECT * FROM parts ORDER BY name')->fetchAll();

    $ocsMap = [];
    foreach ($contacts as $c) {
        $s = $db->prepare('SELECT * FROM office_contact_settings WHERE office_id = ? AND contact_id = ?');
        $s->execute([$officeId, $c['id']]);
        $ocsMap[$c['id']] = $s->fetch() ?: null;
    }

    $partThresholdsMap = [];
    foreach ($contacts as $c) {
        $s = $db->prepare('SELECT * FROM part_thresholds WHERE office_id = ? AND contact_id = ?');
        $s->execute([$officeId, $c['id']]);
        $rows = $s->fetchAll();
        $partThresholdsMap[$c['id']] = [];
        foreach ($rows as $row) {
            $partThresholdsMap[$c['id']][$row['part_id']] = (int)$row['threshold'];
        }
    }

    [$lowestBuildable, $bottleneck] = calculateLowestBuildable($officeId);

    include __DIR__ . '/views/partials/settings.php';
    exit;
}

// ── API — Actions ─────────────────────────────────────────────────────────────

if ($method === 'POST' && $path === '/api/log_order') {
    requireLogin();
    $db          = getDb();
    $officeId    = (int)($_POST['office_id'] ?? 0);
    $productName = trim($_POST['product_name'] ?? '');

    if (!isset(PRODUCTS[$productName])) {
        flash('Unknown product.', 'danger');
        redirect("/dashboard?office_id=$officeId&section=main_menu");
    }

    $bom          = PRODUCTS[$productName]['used_parts'];
    $inventoryMap = getInventoryMap($officeId);
    $premadeQty   = getPremadeStock($officeId, $productName);
    [$buildable, $bottleneck] = calculateBuildable($inventoryMap, $productName);

    if ($premadeQty < 1 && $buildable < 1) {
        flash("Not enough stock to build \"{$productName}\". Bottleneck: " . ($bottleneck ?? 'N/A') . '.', 'danger');
        redirect("/dashboard?office_id=$officeId&section=main_menu");
    }

    try {
        $db->beginTransaction();
        if ($premadeQty >= 1) {
            $stockPartName = PRODUCT_STOCK_PARTS[$productName];
            $stockPart     = getPartByName($stockPartName);
            $stockInv      = getOrCreateInv($officeId, $stockPart['id']);
            $newQty        = max(0.0, (float)$stockInv['quantity'] - 1);
            updateInvQty($officeId, $stockPart['id'], $newQty);
            recordInventoryChange($officeId, $stockPartName, 'order_log', 1, $newQty, "Pre-made: {$productName}");
            $db->prepare('INSERT INTO product_logs (office_id, product_name, used_premade) VALUES (?, ?, 1)')
               ->execute([$officeId, $productName]);
        } else {
            foreach ($bom as $partName => $amount) {
                $part = getPartByName($partName);
                if (!$part) continue;
                $inv    = getOrCreateInv($officeId, $part['id']);
                $newQty = max(0.0, (float)$inv['quantity'] - $amount);
                updateInvQty($officeId, $part['id'], $newQty);
                recordInventoryChange($officeId, $partName, 'order_log', $amount, $newQty, "Used for: {$productName}");
            }
            $db->prepare('INSERT INTO product_logs (office_id, product_name, used_premade) VALUES (?, ?, 0)')
               ->execute([$officeId, $productName]);
        }
        $db->commit();
        flash("Order logged: {$productName}", 'success');
    } catch (Throwable $e) {
        $db->rollBack();
        flash('Error logging order. Please try again.', 'danger');
    }

    redirect("/dashboard?office_id=$officeId&section=main_menu");
}

if ($method === 'POST' && $path === '/api/update_inventory') {
    requireLogin();
    $db       = getDb();
    $officeId = (int)($_POST['office_id'] ?? 0);
    $partName = trim($_POST['part_name'] ?? '');
    $action   = trim($_POST['action'] ?? '');
    $amount   = isset($_POST['amount']) ? (float)$_POST['amount'] : null;
    $note     = trim($_POST['note'] ?? '') ?: null;

    if (!$officeId || !$partName || !$action || $amount === null || $amount <= 0) {
        flash('Please fill in all fields with a positive amount.', 'danger');
        redirect("/dashboard?office_id=" . ($officeId ?: 1) . "&section=update_inventory");
    }

    $part = getPartByName($partName);
    if (!$part) {
        flash('Unknown part.', 'danger');
        redirect("/dashboard?office_id=$officeId&section=update_inventory");
    }

    $inv = getOrCreateInv($officeId, $part['id']);

    try {
        $db->beginTransaction();
        if ($action === 'add') {
            $newQty = (float)$inv['quantity'] + $amount;
            updateInvQty($officeId, $part['id'], $newQty);
            recordInventoryChange($officeId, $partName, 'add', $amount, $newQty, $note ?? 'Stock added');
            flash('Added ' . fmt($amount) . " {$partName}.", 'success');
        } elseif ($action === 'subtract') {
            if ((float)$inv['quantity'] < $amount) {
                $db->rollBack();
                flash('Cannot subtract ' . fmt($amount) . ' — only ' . fmt((float)$inv['quantity']) . ' in stock.', 'danger');
                redirect("/dashboard?office_id=$officeId&section=update_inventory");
            }
            $newQty = max(0.0, (float)$inv['quantity'] - $amount);
            updateInvQty($officeId, $part['id'], $newQty);
            recordInventoryChange($officeId, $partName, 'subtract', $amount, $newQty, $note ?? 'Stock removed');
            flash('Subtracted ' . fmt($amount) . " {$partName}.", 'success');
        } else {
            $db->rollBack();
            flash('Invalid action.', 'danger');
            redirect("/dashboard?office_id=$officeId&section=update_inventory");
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        flash('Error updating inventory.', 'danger');
    }

    redirect("/dashboard?office_id=$officeId&section=update_inventory");
}

if ($method === 'POST' && $path === '/api/transfer_inventory') {
    requireLogin();
    $db          = getDb();
    $fromOfficeId = (int)($_POST['office_id'] ?? 0);
    $partName    = trim($_POST['part_name'] ?? '');
    $amount      = isset($_POST['amount']) ? (float)$_POST['amount'] : null;

    if (!$fromOfficeId || !$partName || !$amount || $amount <= 0) {
        flash('Please fill in all fields with a positive amount.', 'danger');
        redirect("/dashboard?office_id=" . ($fromOfficeId ?: 1) . "&section=update_inventory");
    }

    $toOffice = getOtherOffice($fromOfficeId);
    if (!$toOffice) {
        flash('No destination office found.', 'danger');
        redirect("/dashboard?office_id=$fromOfficeId&section=update_inventory");
    }

    $part = getPartByName($partName);
    if (!$part) {
        flash('Unknown part.', 'danger');
        redirect("/dashboard?office_id=$fromOfficeId&section=update_inventory");
    }

    $fromInv = getOrCreateInv($fromOfficeId, $part['id']);
    if ((float)$fromInv['quantity'] < $amount) {
        flash('Insufficient stock: ' . fmt((float)$fromInv['quantity']) . " available, " . fmt($amount) . ' requested.', 'danger');
        redirect("/dashboard?office_id=$fromOfficeId&section=update_inventory");
    }

    $fromOffice = getOfficeOrAbort($fromOfficeId);
    $toInv      = getOrCreateInv($toOffice['id'], $part['id']);

    try {
        $db->beginTransaction();
        $newFrom = (float)$fromInv['quantity'] - $amount;
        $newTo   = (float)$toInv['quantity']   + $amount;
        updateInvQty($fromOfficeId, $part['id'], $newFrom);
        updateInvQty($toOffice['id'], $part['id'], $newTo);
        recordInventoryChange($fromOfficeId, $partName, 'transfer', $amount, $newFrom, 'Transfer to ' . $toOffice['name']);
        recordInventoryChange($toOffice['id'], $partName, 'transfer', $amount, $newTo, 'Transfer from ' . $fromOffice['name']);
        $db->commit();
        flash('Transferred ' . fmt($amount) . " {$partName} to {$toOffice['name']}.", 'success');
    } catch (Throwable $e) {
        $db->rollBack();
        flash('Transfer failed. Please try again.', 'danger');
    }

    redirect("/dashboard?office_id=$fromOfficeId&section=update_inventory");
}

// POST /api/strike_log/{id}
if ($method === 'POST' && preg_match('#^/api/strike_log/(\d+)$#', $path, $m)) {
    requireLogin();
    $db    = getDb();
    $logId = (int)$m[1];

    $stmt = $db->prepare('SELECT * FROM product_logs WHERE id = ?');
    $stmt->execute([$logId]);
    $log = $stmt->fetch();
    if (!$log) { http_response_code(404); echo '404'; exit; }

    $officeId    = (int)$log['office_id'];
    $productName = $log['product_name'];

    if ($log['struck']) {
        flash('This log entry has already been struck.', 'warning');
        redirect("/dashboard?office_id=$officeId&section=product_history");
    }

    $bom = PRODUCTS[$productName]['used_parts'] ?? [];

    try {
        $db->beginTransaction();
        if ($log['used_premade']) {
            $stockPartName = PRODUCT_STOCK_PARTS[$productName] ?? null;
            if ($stockPartName) {
                $stockPart = getPartByName($stockPartName);
                if ($stockPart) {
                    $inv    = getOrCreateInv($officeId, $stockPart['id']);
                    $newQty = (float)$inv['quantity'] + 1;
                    updateInvQty($officeId, $stockPart['id'], $newQty);
                    recordInventoryChange($officeId, $stockPartName, 'order_strike', 1, $newQty,
                        "Strike: pre-made {$productName} log #{$logId}");
                }
            }
        } else {
            foreach ($bom as $partName => $amount) {
                $part = getPartByName($partName);
                if (!$part) continue;
                $inv    = getOrCreateInv($officeId, $part['id']);
                $newQty = (float)$inv['quantity'] + $amount;
                updateInvQty($officeId, $part['id'], $newQty);
                recordInventoryChange($officeId, $partName, 'order_strike', $amount, $newQty,
                    "Strike: {$productName} log #{$logId}");
            }
        }
        $db->prepare('UPDATE product_logs SET struck = 1 WHERE id = ?')->execute([$logId]);
        $db->commit();
        flash("Log #{$logId} struck — inventory restored for \"{$productName}\".", 'success');
    } catch (Throwable $e) {
        $db->rollBack();
        flash('Strike failed. Please try again.', 'danger');
    }

    redirect("/dashboard?office_id=$officeId&section=product_history");
}

// POST /api/strike_inventory_log/{id}
if ($method === 'POST' && preg_match('#^/api/strike_inventory_log/(\d+)$#', $path, $m)) {
    requireLogin();
    $db    = getDb();
    $logId = (int)$m[1];

    $stmt = $db->prepare('SELECT * FROM inventory_logs WHERE id = ?');
    $stmt->execute([$logId]);
    $log = $stmt->fetch();
    if (!$log) { http_response_code(404); echo '404'; exit; }

    $officeId = (int)$log['office_id'];
    $partName = $log['part_name'];

    if (!in_array($log['change_type'], ['add', 'subtract'], true)) {
        flash('Only manual add/subtract entries can be struck. Use Product History to reverse order logs.', 'warning');
        redirect("/dashboard?office_id=$officeId&section=inventory_history");
    }

    try {
        $db->beginTransaction();
        $part = getPartByName($partName);
        if ($part) {
            $inv = getOrCreateInv($officeId, $part['id']);
            if ($log['change_type'] === 'add') {
                $newQty = max(0.0, (float)$inv['quantity'] - (float)$log['amount']);
            } else {
                $newQty = (float)$inv['quantity'] + (float)$log['amount'];
            }
            updateInvQty($officeId, $part['id'], $newQty);
        }
        $db->prepare('DELETE FROM inventory_logs WHERE id = ?')->execute([$logId]);
        $db->commit();
        flash("Log entry removed and inventory adjusted for \"{$partName}\".", 'success');
    } catch (Throwable $e) {
        $db->rollBack();
        flash('Strike failed. Please try again.', 'danger');
    }

    redirect("/dashboard?office_id=$officeId&section=inventory_history");
}

if ($method === 'POST' && $path === '/api/save_settings') {
    requireLogin();
    $db        = getDb();
    $officeId  = (int)($_POST['office_id'] ?? 0);
    $threshold = isset($_POST['threshold']) ? (int)$_POST['threshold'] : null;

    if ($threshold === null || $threshold < 3 || $threshold > 10) {
        flash('Threshold must be between 3 and 10.', 'danger');
        redirect("/dashboard?office_id=$officeId&section=settings");
    }

    $stmt = $db->prepare('SELECT id FROM office_settings WHERE office_id = ?');
    $stmt->execute([$officeId]);
    if ($stmt->fetch()) {
        $db->prepare('UPDATE office_settings SET low_stock_threshold = ? WHERE office_id = ?')
           ->execute([$threshold, $officeId]);
    } else {
        $db->prepare('INSERT INTO office_settings (office_id, low_stock_threshold) VALUES (?, ?)')
           ->execute([$officeId, $threshold]);
    }
    flash('Settings saved.', 'success');
    redirect("/dashboard?office_id=$officeId&section=settings");
}

if ($method === 'POST' && $path === '/api/save_contact_threshold') {
    requireLogin();
    $db        = getDb();
    $officeId  = (int)($_POST['office_id']  ?? 0);
    $contactId = (int)($_POST['contact_id'] ?? 0);
    $threshold = isset($_POST['threshold']) ? (int)$_POST['threshold'] : null;

    if ($threshold === null || $threshold < 1) {
        flash('Threshold must be at least 1.', 'danger');
        redirect("/dashboard?office_id=$officeId&section=settings");
    }

    $stmt = $db->prepare('SELECT id FROM office_contact_settings WHERE office_id = ? AND contact_id = ?');
    $stmt->execute([$officeId, $contactId]);
    if ($stmt->fetch()) {
        $db->prepare('UPDATE office_contact_settings SET threshold = ? WHERE office_id = ? AND contact_id = ?')
           ->execute([$threshold, $officeId, $contactId]);
    } else {
        $db->prepare('INSERT INTO office_contact_settings (office_id, contact_id, notifications_enabled, threshold) VALUES (?, ?, 0, ?)')
           ->execute([$officeId, $contactId, $threshold]);
    }

    // Reset alert state so updated threshold can fire fresh
    $db->prepare('UPDATE contact_alert_states SET is_currently_low = 0 WHERE office_id = ? AND contact_id = ?')
       ->execute([$officeId, $contactId]);

    flash('Threshold saved.', 'success');
    redirect("/dashboard?office_id=$officeId&section=settings");
}

if ($method === 'POST' && $path === '/api/toggle_advanced_mode') {
    requireLogin();
    $db        = getDb();
    $officeId  = (int)($_POST['office_id']  ?? 0);
    $contactId = (int)($_POST['contact_id'] ?? 0);

    $stmt = $db->prepare('SELECT id, advanced_mode FROM office_contact_settings WHERE office_id = ? AND contact_id = ?');
    $stmt->execute([$officeId, $contactId]);
    $ocs = $stmt->fetch();
    if ($ocs) {
        $db->prepare('UPDATE office_contact_settings SET advanced_mode = ? WHERE office_id = ? AND contact_id = ?')
           ->execute([$ocs['advanced_mode'] ? 0 : 1, $officeId, $contactId]);
    }
    redirect("/dashboard?office_id=$officeId&section=settings");
}

if ($method === 'POST' && $path === '/api/save_advanced_thresholds') {
    requireLogin();
    $db        = getDb();
    $officeId  = (int)($_POST['office_id']  ?? 0);
    $contactId = (int)($_POST['contact_id'] ?? 0);

    $stmt = $db->prepare('SELECT id FROM office_contact_settings WHERE office_id = ? AND contact_id = ?');
    $stmt->execute([$officeId, $contactId]);
    if (!$stmt->fetch()) {
        flash('Contact setting not found.', 'danger');
        redirect("/dashboard?office_id=$officeId&section=settings");
    }

    $db->prepare('UPDATE office_contact_settings SET advanced_mode = 1 WHERE office_id = ? AND contact_id = ?')
       ->execute([$officeId, $contactId]);

    $parts = $db->query('SELECT * FROM parts')->fetchAll();
    foreach ($parts as $part) {
        $threshold = max(0, (int)($_POST['part_' . $part['id']] ?? 0));
        $s = $db->prepare('SELECT id FROM part_thresholds WHERE office_id = ? AND contact_id = ? AND part_id = ?');
        $s->execute([$officeId, $contactId, $part['id']]);
        if ($s->fetch()) {
            $db->prepare('UPDATE part_thresholds SET threshold = ? WHERE office_id = ? AND contact_id = ? AND part_id = ?')
               ->execute([$threshold, $officeId, $contactId, $part['id']]);
        } else {
            $db->prepare('INSERT INTO part_thresholds (office_id, contact_id, part_id, threshold) VALUES (?, ?, ?, ?)')
               ->execute([$officeId, $contactId, $part['id'], $threshold]);
        }
    }
    flash('Advanced thresholds saved.', 'success');
    redirect("/dashboard?office_id=$officeId&section=settings");
}

if ($method === 'POST' && $path === '/api/add_contact') {
    requireLogin();
    $db       = getDb();
    $officeId = (int)($_POST['office_id'] ?? 0);
    $method2  = trim($_POST['method'] ?? '');
    $label    = trim($_POST['label']  ?? '') ?: null;
    $email    = trim($_POST['email']  ?? '') ?: null;
    $token    = trim($_POST['telegram_bot_token'] ?? '') ?: null;
    $chatId   = trim($_POST['telegram_chat_id']   ?? '') ?: null;

    if (!in_array($method2, ['Email', 'Telegram', 'Both'], true)) {
        flash('Select a valid contact method.', 'danger');
        redirect("/dashboard?office_id=$officeId&section=settings");
    }

    $db->prepare('INSERT INTO contacts (label, method, email, telegram_bot_token, telegram_chat_id) VALUES (?, ?, ?, ?, ?)')
       ->execute([$label, $method2, $email, $token, $chatId]);
    $contactId = (int)$db->lastInsertId();

    $offices = $db->query('SELECT id FROM offices')->fetchAll();
    foreach ($offices as $off) {
        $s = $db->prepare('SELECT low_stock_threshold FROM office_settings WHERE office_id = ?');
        $s->execute([$off['id']]);
        $os = $s->fetch();
        $defaultThreshold = $os ? (int)$os['low_stock_threshold'] : 3;
        $db->prepare('INSERT INTO office_contact_settings (office_id, contact_id, notifications_enabled, threshold) VALUES (?, ?, 0, ?)')
           ->execute([$off['id'], $contactId, $defaultThreshold]);
    }

    flash('Contact "' . ($label ?: $method2) . '" added.', 'success');
    redirect("/dashboard?office_id=$officeId&section=settings");
}

if ($method === 'POST' && $path === '/api/toggle_contact') {
    requireLogin();
    $db        = getDb();
    $officeId  = (int)($_POST['office_id']  ?? 0);
    $contactId = (int)($_POST['contact_id'] ?? 0);

    $stmt = $db->prepare('SELECT id, notifications_enabled FROM office_contact_settings WHERE office_id = ? AND contact_id = ?');
    $stmt->execute([$officeId, $contactId]);
    $ocs = $stmt->fetch();
    if ($ocs) {
        $db->prepare('UPDATE office_contact_settings SET notifications_enabled = ? WHERE office_id = ? AND contact_id = ?')
           ->execute([$ocs['notifications_enabled'] ? 0 : 1, $officeId, $contactId]);
    } else {
        $db->prepare('INSERT INTO office_contact_settings (office_id, contact_id, notifications_enabled) VALUES (?, ?, 1)')
           ->execute([$officeId, $contactId]);
    }
    redirect("/dashboard?office_id=$officeId&section=settings");
}

if ($method === 'POST' && $path === '/api/delete_contact') {
    requireLogin();
    $db        = getDb();
    $officeId  = (int)($_POST['office_id']  ?? 0);
    $contactId = (int)($_POST['contact_id'] ?? 0);
    $db->prepare('DELETE FROM contacts WHERE id = ?')->execute([$contactId]);
    flash('Contact deleted.', 'success');
    redirect("/dashboard?office_id=$officeId&section=settings");
}

// GET /api/check_low_stock
if ($method === 'GET' && $path === '/api/check_low_stock') {
    $expected = CHECK_TOKEN;
    $provided = $_GET['token'] ?? '';
    if ($expected !== '' && $provided !== $expected) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }

    header('Content-Type: application/json');
    echo json_encode(['results' => checkAllOffices()]);
    exit;
}

// ── 404 ───────────────────────────────────────────────────────────────────────
http_response_code(404);
echo '<h1>404 Not Found</h1>';
