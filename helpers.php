<?php

// ── Session helpers ───────────────────────────────────────────────────────────

function flash(string $msg, string $category = 'info'): void
{
    $_SESSION['_flashes'][] = [$category, $msg];
}

function getFlashes(): array
{
    $msgs = $_SESSION['_flashes'] ?? [];
    $_SESSION['_flashes'] = [];
    return $msgs;
}

function requireLogin(): void
{
    if (empty($_SESSION['logged_in'])) {
        header('Location: /login');
        exit;
    }
}

function redirect(string $url): never
{
    header("Location: $url");
    exit;
}

// ── Number formatting ─────────────────────────────────────────────────────────

function fmt(float $n): string
{
    return ($n == (int)$n) ? (string)(int)$n : number_format($n, 1, '.', '');
}

// ── Inventory helpers ─────────────────────────────────────────────────────────

function getInventoryMap(int $officeId): array
{
    $db   = getDb();
    $stmt = $db->prepare(
        'SELECT p.name, i.quantity FROM inventory i
         JOIN parts p ON p.id = i.part_id
         WHERE i.office_id = ?'
    );
    $stmt->execute([$officeId]);
    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $map[$row['name']] = (float)$row['quantity'];
    }
    return $map;
}

function calculateBuildable(array $inventoryMap, string $productName): array
{
    $bom        = PRODUCTS[$productName]['used_parts'];
    $minBuild   = INF;
    $bottleneck = null;

    foreach ($bom as $part => $needed) {
        if ($needed <= 0) continue;
        $canBuild = floor(($inventoryMap[$part] ?? 0) / $needed);
        if ($canBuild < $minBuild) {
            $minBuild   = $canBuild;
            $bottleneck = $part;
        }
    }

    return $minBuild === INF ? [0, null] : [(int)$minBuild, $bottleneck];
}

function getPremadeStock(int $officeId, string $productName): float
{
    $partName = PRODUCT_STOCK_PARTS[$productName] ?? null;
    if (!$partName) return 0.0;

    $db   = getDb();
    $stmt = $db->prepare(
        'SELECT i.quantity FROM inventory i
         JOIN parts p ON p.id = i.part_id
         WHERE p.name = ? AND i.office_id = ?'
    );
    $stmt->execute([$partName, $officeId]);
    $row = $stmt->fetch();
    return $row ? (float)$row['quantity'] : 0.0;
}

function calculateLowestBuildable(int $officeId): array
{
    $inv     = getInventoryMap($officeId);
    $results = [];
    foreach (PRODUCTS as $name => $_) {
        [$fromParts, $bottleneck] = calculateBuildable($inv, $name);
        $premade = getPremadeStock($officeId, $name);
        $total   = $fromParts + (int)$premade;
        $results[] = [$total, $bottleneck, $name];
    }
    usort($results, fn($a, $b) => $a[0] <=> $b[0]);
    return $results[0] ?? [0, null, null];
}

function getOtherOffice(int $officeId): ?array
{
    $db   = getDb();
    $stmt = $db->prepare('SELECT * FROM offices WHERE id != ? LIMIT 1');
    $stmt->execute([$officeId]);
    return $stmt->fetch() ?: null;
}

function getOfficeOrAbort(int $officeId): array
{
    $db   = getDb();
    $stmt = $db->prepare('SELECT * FROM offices WHERE id = ?');
    $stmt->execute([$officeId]);
    $row = $stmt->fetch();
    if (!$row) { http_response_code(404); echo '404 Not Found'; exit; }
    return $row;
}

// ── Inventory logging ─────────────────────────────────────────────────────────

function recordInventoryChange(
    int $officeId, string $partName, string $changeType,
    float $amount, float $resultingQty, ?string $note = null
): void {
    $db   = getDb();
    $stmt = $db->prepare(
        'INSERT INTO inventory_logs
         (office_id, part_name, change_type, amount, resulting_quantity, timestamp, note)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $officeId, $partName, $changeType,
        $amount, $resultingQty,
        gmdate('Y-m-d H:i:s'),
        $note,
    ]);
}

function getOrCreateInv(int $officeId, int $partId): array
{
    $db   = getDb();
    $stmt = $db->prepare('SELECT * FROM inventory WHERE office_id = ? AND part_id = ?');
    $stmt->execute([$officeId, $partId]);
    $row = $stmt->fetch();
    if (!$row) {
        $db->prepare('INSERT INTO inventory (office_id, part_id, quantity) VALUES (?, ?, 0.0)')
           ->execute([$officeId, $partId]);
        $stmt->execute([$officeId, $partId]);
        $row = $stmt->fetch();
    }
    return $row;
}

function updateInvQty(int $officeId, int $partId, float $qty): void
{
    getDb()->prepare('UPDATE inventory SET quantity = ? WHERE office_id = ? AND part_id = ?')
           ->execute([$qty, $officeId, $partId]);
}

function getPartByName(string $name): ?array
{
    $stmt = getDb()->prepare('SELECT * FROM parts WHERE name = ?');
    $stmt->execute([$name]);
    return $stmt->fetch() ?: null;
}

function getPartById(int $id): ?array
{
    $stmt = getDb()->prepare('SELECT * FROM parts WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

// ── Chart datasets ────────────────────────────────────────────────────────────

function buildChartDatasets(int $officeId, array $parts): array
{
    $db   = getDb();
    $stmt = $db->prepare(
        'SELECT part_name, resulting_quantity, timestamp FROM inventory_logs
         WHERE office_id = ? AND resulting_quantity IS NOT NULL
         ORDER BY timestamp ASC'
    );
    $stmt->execute([$officeId]);
    $allLogs = $stmt->fetchAll();

    $invMap   = getInventoryMap($officeId);
    $colors   = CHART_COLORS;
    $datasets = [];

    foreach ($parts as $i => $part) {
        $partLogs = array_filter($allLogs, fn($l) => $l['part_name'] === $part['name']);
        $points   = array_map(fn($l) => [
            'x' => str_replace(' ', 'T', $l['timestamp']),
            'y' => (float)$l['resulting_quantity'],
        ], array_values($partLogs));
        $points[] = ['x' => gmdate('Y-m-d\TH:i:s'), 'y' => $invMap[$part['name']] ?? 0.0];

        $color      = $colors[$i % count($colors)];
        $datasets[] = [
            'label'           => $part['name'],
            'data'            => $points,
            'borderColor'     => $color,
            'backgroundColor' => $color . '33',
            'tension'         => 0.3,
            'fill'            => false,
            'pointRadius'     => 2,
        ];
    }
    return $datasets;
}

// ── Part steps (for spinner sizing) ──────────────────────────────────────────

function getPartSteps(): array
{
    $steps = [];
    foreach (PRODUCTS as $product) {
        foreach ($product['used_parts'] as $partName => $qty) {
            if (!isset($steps[$partName]) || $qty < $steps[$partName]) {
                $steps[$partName] = $qty;
            }
        }
    }
    return $steps;
}

// ── HTML escaping shorthand ───────────────────────────────────────────────────

function e(mixed $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
