<?php

function getDb(): PDO
{
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $dir = dirname(DB_PATH);
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE,            PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON;');

    createSchema($pdo);
    runMigrations($pdo);
    seedDatabase($pdo);

    return $pdo;
}

function createSchema(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS offices (
            id   INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE
        );

        CREATE TABLE IF NOT EXISTS parts (
            id   INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            unit TEXT NOT NULL DEFAULT 'units'
        );

        CREATE TABLE IF NOT EXISTS inventory (
            id        INTEGER PRIMARY KEY AUTOINCREMENT,
            office_id INTEGER NOT NULL REFERENCES offices(id) ON DELETE CASCADE,
            part_id   INTEGER NOT NULL REFERENCES parts(id)   ON DELETE CASCADE,
            quantity  REAL NOT NULL DEFAULT 0.0,
            UNIQUE(office_id, part_id)
        );

        CREATE TABLE IF NOT EXISTS product_logs (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            office_id    INTEGER NOT NULL REFERENCES offices(id) ON DELETE CASCADE,
            product_name TEXT NOT NULL,
            timestamp    TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now')),
            quantity     INTEGER NOT NULL DEFAULT 1,
            struck       INTEGER NOT NULL DEFAULT 0,
            used_premade INTEGER NOT NULL DEFAULT 0
        );

        CREATE TABLE IF NOT EXISTS inventory_logs (
            id                 INTEGER PRIMARY KEY AUTOINCREMENT,
            office_id          INTEGER NOT NULL REFERENCES offices(id) ON DELETE CASCADE,
            part_name          TEXT NOT NULL,
            change_type        TEXT NOT NULL,
            amount             REAL NOT NULL,
            resulting_quantity REAL,
            timestamp          TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now')),
            note               TEXT
        );

        CREATE TABLE IF NOT EXISTS contacts (
            id                 INTEGER PRIMARY KEY AUTOINCREMENT,
            label              TEXT,
            method             TEXT NOT NULL,
            email              TEXT,
            telegram_bot_token TEXT,
            telegram_chat_id   TEXT
        );

        CREATE TABLE IF NOT EXISTS office_contact_settings (
            id                    INTEGER PRIMARY KEY AUTOINCREMENT,
            office_id             INTEGER NOT NULL REFERENCES offices(id)  ON DELETE CASCADE,
            contact_id            INTEGER NOT NULL REFERENCES contacts(id) ON DELETE CASCADE,
            notifications_enabled INTEGER NOT NULL DEFAULT 0,
            threshold             INTEGER NOT NULL DEFAULT 3,
            advanced_mode         INTEGER NOT NULL DEFAULT 0,
            UNIQUE(office_id, contact_id)
        );

        CREATE TABLE IF NOT EXISTS office_settings (
            id                  INTEGER PRIMARY KEY AUTOINCREMENT,
            office_id           INTEGER NOT NULL UNIQUE REFERENCES offices(id) ON DELETE CASCADE,
            low_stock_threshold INTEGER NOT NULL DEFAULT 3
        );

        CREATE TABLE IF NOT EXISTS office_alert_states (
            id               INTEGER PRIMARY KEY AUTOINCREMENT,
            office_id        INTEGER NOT NULL UNIQUE REFERENCES offices(id) ON DELETE CASCADE,
            is_currently_low INTEGER NOT NULL DEFAULT 0,
            last_notified_at TEXT
        );

        CREATE TABLE IF NOT EXISTS contact_alert_states (
            id               INTEGER PRIMARY KEY AUTOINCREMENT,
            office_id        INTEGER NOT NULL REFERENCES offices(id)  ON DELETE CASCADE,
            contact_id       INTEGER NOT NULL REFERENCES contacts(id) ON DELETE CASCADE,
            is_currently_low INTEGER NOT NULL DEFAULT 0,
            last_notified_at TEXT,
            UNIQUE(office_id, contact_id)
        );

        CREATE TABLE IF NOT EXISTS part_thresholds (
            id               INTEGER PRIMARY KEY AUTOINCREMENT,
            office_id        INTEGER NOT NULL REFERENCES offices(id)  ON DELETE CASCADE,
            contact_id       INTEGER NOT NULL REFERENCES contacts(id) ON DELETE CASCADE,
            part_id          INTEGER NOT NULL REFERENCES parts(id)    ON DELETE CASCADE,
            threshold        INTEGER NOT NULL DEFAULT 0,
            is_currently_low INTEGER NOT NULL DEFAULT 0,
            UNIQUE(office_id, contact_id, part_id)
        );
    ");
}

function runMigrations(PDO $db): void
{
    // Safe column additions for databases that existed before these columns were added
    $migrations = [
        ['product_logs',            'used_premade',  'INTEGER NOT NULL DEFAULT 0'],
        ['office_contact_settings', 'threshold',     'INTEGER NOT NULL DEFAULT 3'],
        ['office_contact_settings', 'advanced_mode', 'INTEGER NOT NULL DEFAULT 0'],
    ];

    foreach ($migrations as [$table, $column, $def]) {
        try {
            $db->exec("ALTER TABLE $table ADD COLUMN $column $def");
        } catch (PDOException) {
            // Column already exists — ignore
        }
    }

    // Part renames (idempotent updates)
    $renames = [
        ['Aux Ports',  'Audio Jacks'],
        ['Aux Port Nuts', 'Audio Jack Nuts'],
        ['Connectors', '3 Pin Connectors'],
    ];
    foreach ($renames as [$old, $new]) {
        $db->prepare('UPDATE parts SET name = ? WHERE name = ?')->execute([$new, $old]);
        $db->prepare('UPDATE inventory_logs SET part_name = ? WHERE part_name = ?')->execute([$new, $old]);
    }
}

function seedDatabase(PDO $db): void
{
    $officeNames = ['Rozet Office', 'Recluse Office'];
    $offices     = [];
    foreach ($officeNames as $name) {
        $row = $db->query("SELECT * FROM offices WHERE name = " . $db->quote($name))->fetch();
        if (!$row) {
            $db->prepare('INSERT INTO offices (name) VALUES (?)')->execute([$name]);
            $row = $db->query("SELECT * FROM offices WHERE name = " . $db->quote($name))->fetch();
        }
        $offices[] = $row;
    }

    $parts = [];
    foreach (SEED_PARTS as $name) {
        $row = $db->query("SELECT * FROM parts WHERE name = " . $db->quote($name))->fetch();
        if (!$row) {
            $unit = str_contains($name, 'Shrink Tube') ? 'inches' : 'units';
            $db->prepare('INSERT INTO parts (name, unit) VALUES (?, ?)')->execute([$name, $unit]);
            $row = $db->query("SELECT * FROM parts WHERE name = " . $db->quote($name))->fetch();
        }
        $parts[] = $row;
    }

    foreach ($offices as $office) {
        foreach ($parts as $part) {
            $exists = $db->prepare('SELECT 1 FROM inventory WHERE office_id = ? AND part_id = ?');
            $exists->execute([$office['id'], $part['id']]);
            if (!$exists->fetch()) {
                $db->prepare('INSERT INTO inventory (office_id, part_id, quantity) VALUES (?, ?, 0.0)')
                   ->execute([$office['id'], $part['id']]);
            }
        }

        $hasSetting = $db->prepare('SELECT 1 FROM office_settings WHERE office_id = ?');
        $hasSetting->execute([$office['id']]);
        if (!$hasSetting->fetch()) {
            $db->prepare('INSERT INTO office_settings (office_id, low_stock_threshold) VALUES (?, 3)')
               ->execute([$office['id']]);
        }

        $hasAlert = $db->prepare('SELECT 1 FROM office_alert_states WHERE office_id = ?');
        $hasAlert->execute([$office['id']]);
        if (!$hasAlert->fetch()) {
            $db->prepare('INSERT INTO office_alert_states (office_id, is_currently_low) VALUES (?, 0)')
               ->execute([$office['id']]);
        }
    }
}
