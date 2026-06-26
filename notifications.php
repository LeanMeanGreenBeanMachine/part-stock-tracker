<?php

// ── SMTP / Email ──────────────────────────────────────────────────────────────

function sendEmail(array $contact, string $subject, string $body): bool
{
    $host = SMTP_HOST;
    $port = SMTP_PORT;
    $user = SMTP_USER;
    $pass = SMTP_PASS;
    $from = SMTP_FROM;
    $to   = $contact['email'];

    if (!$to || !$user || !$pass) return false;

    $conn = @fsockopen($host, $port, $errno, $errstr, 10);
    if (!$conn) return false;

    try {
        $read = function() use ($conn): string { return (string)fgets($conn, 515); };
        $send = function(string $cmd) use ($conn): void { fwrite($conn, $cmd . "\r\n"); };

        $read(); // 220 banner
        $send("EHLO localhost");
        while ($line = $read()) { if (isset($line[3]) && $line[3] === ' ') break; }

        $send("STARTTLS");
        $read(); // 220 Go ahead
        stream_socket_enable_crypto($conn, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);

        $send("EHLO localhost");
        while ($line = $read()) { if (isset($line[3]) && $line[3] === ' ') break; }

        $send("AUTH LOGIN");
        $read(); // 334
        $send(base64_encode($user));
        $read(); // 334
        $send(base64_encode($pass));
        $read(); // 235 Authenticated

        $send("MAIL FROM: <{$from}>");
        $read();
        $send("RCPT TO: <{$to}>");
        $read();
        $send("DATA");
        $read(); // 354

        $headers = implode("\r\n", [
            "From: {$from}",
            "To: {$to}",
            "Subject: {$subject}",
            "MIME-Version: 1.0",
            "Content-Type: text/plain; charset=UTF-8",
        ]);
        $send("{$headers}\r\n\r\n{$body}\r\n.");
        $read(); // 250 OK

        $send("QUIT");
        fclose($conn);
        return true;
    } catch (Throwable) {
        fclose($conn);
        return false;
    }
}

// ── Telegram ──────────────────────────────────────────────────────────────────

function sendTelegram(array $contact, string $message): bool
{
    $token  = $contact['telegram_bot_token'] ?? '';
    $chatId = $contact['telegram_chat_id']   ?? '';
    if (!$token || !$chatId) return false;

    $url     = "https://api.telegram.org/bot{$token}/sendMessage";
    $payload = json_encode(['chat_id' => $chatId, 'text' => $message]);

    $ctx = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/json\r\nContent-Length: " . strlen($payload),
        'content' => $payload,
        'timeout' => 10,
    ]]);

    $result = @file_get_contents($url, false, $ctx);
    if ($result === false) return false;

    $data = json_decode($result, true);
    return !empty($data['ok']);
}

// ── Dispatch ──────────────────────────────────────────────────────────────────

function dispatch(array $contact, string $officeName, int $lowestBuildable, ?string $bottleneck, string $productName): void
{
    $subject = "[Vulcan] Low Stock — {$officeName}";
    $body    = "⚠️  Low stock alert for {$officeName}.\n\n"
             . "You can build only {$lowestBuildable} unit(s) of '{$productName}' "
             . "(and possibly other products).\n"
             . "Current bottleneck: " . ($bottleneck ?? 'N/A') . "\n\n"
             . "Please reorder soon.";

    if (in_array($contact['method'], ['Email', 'Both'], true) && $contact['email']) {
        sendEmail($contact, $subject, $body);
    }
    if (in_array($contact['method'], ['Telegram', 'Both'], true) && $contact['telegram_bot_token']) {
        sendTelegram($contact, $body);
    }
}

function dispatchAdvanced(array $contact, string $officeName, array $newlyLowParts): void
{
    $lines   = implode("\n", array_map(
        fn($p) => "  • {$p[0]} — {$p[1]} remaining (threshold: {$p[2]})",
        $newlyLowParts
    ));
    $subject = "[Vulcan] Low Parts Alert — {$officeName}";
    $body    = "⚠️  Low parts alert for {$officeName}.\n\n"
             . "Parts below threshold:\n{$lines}\n\n"
             . "Please reorder soon.";

    if (in_array($contact['method'], ['Email', 'Both'], true) && $contact['email']) {
        sendEmail($contact, $subject, $body);
    }
    if (in_array($contact['method'], ['Telegram', 'Both'], true) && $contact['telegram_bot_token']) {
        sendTelegram($contact, $body);
    }
}

// ── Low-stock check logic ─────────────────────────────────────────────────────

function checkAllOffices(): array
{
    $db      = getDb();
    $offices = $db->query('SELECT * FROM offices')->fetchAll();
    return array_map('checkOffice', $offices);
}

function checkOffice(array $office): array
{
    $db           = getDb();
    $invMap       = getInventoryMap($office['id']);
    [$lowest, $bottleneck, $productName] = calculateLowestBuildable($office['id']);

    $stmt = $db->prepare(
        'SELECT * FROM office_contact_settings WHERE office_id = ? AND notifications_enabled = 1'
    );
    $stmt->execute([$office['id']]);
    $enabledOcs = $stmt->fetchAll();

    $results = [];
    foreach ($enabledOcs as $ocs) {
        $contact = $db->prepare('SELECT * FROM contacts WHERE id = ?');
        $contact->execute([$ocs['contact_id']]);
        $contact = $contact->fetch();
        if (!$contact) continue;

        if ($ocs['advanced_mode']) {
            $newlyLow = checkContactAdvanced($office, $contact, $invMap);
            $results[] = [
                'contact'             => $contact['label'] ?: $contact['method'],
                'mode'                => 'advanced',
                'newly_alerted_parts' => array_column($newlyLow, 0),
            ];
        } else {
            $threshold = (int)$ocs['threshold'];
            $state     = getOrCreateContactAlertState($office['id'], $contact['id']);
            $isLow     = $lowest <= $threshold;

            if ($isLow && !$state['is_currently_low']) {
                dispatch($contact, $office['name'], $lowest, $bottleneck, $productName ?? '');
                $db->prepare(
                    'UPDATE contact_alert_states SET is_currently_low = 1, last_notified_at = ?
                     WHERE office_id = ? AND contact_id = ?'
                )->execute([gmdate('Y-m-d H:i:s'), $office['id'], $contact['id']]);
                $results[] = ['contact' => $contact['label'] ?: $contact['method'],
                              'action' => 'notified', 'lowest' => $lowest, 'threshold' => $threshold];
            } elseif (!$isLow && $state['is_currently_low']) {
                $db->prepare(
                    'UPDATE contact_alert_states SET is_currently_low = 0
                     WHERE office_id = ? AND contact_id = ?'
                )->execute([$office['id'], $contact['id']]);
                $results[] = ['contact' => $contact['label'] ?: $contact['method'], 'action' => 'reset'];
            } else {
                $results[] = ['contact' => $contact['label'] ?: $contact['method'],
                              'action'  => $isLow ? 'already_alerted' : 'ok'];
            }
        }
    }

    return ['office' => $office['name'], 'lowest' => $lowest, 'results' => $results];
}

function checkContactAdvanced(array $office, array $contact, array $inventoryMap): array
{
    $db   = getDb();
    $stmt = $db->prepare(
        'SELECT * FROM part_thresholds WHERE office_id = ? AND contact_id = ? AND threshold > 0'
    );
    $stmt->execute([$office['id'], $contact['id']]);
    $partThresholds = $stmt->fetchAll();

    $newlyLow = [];
    foreach ($partThresholds as $pt) {
        $part = getPartById((int)$pt['part_id']);
        if (!$part) continue;

        $qty   = $inventoryMap[$part['name']] ?? 0;
        $isLow = $qty <= $pt['threshold'];

        if ($isLow && !$pt['is_currently_low']) {
            $newlyLow[] = [$part['name'], $qty, $pt['threshold']];
            $db->prepare(
                'UPDATE part_thresholds SET is_currently_low = 1 WHERE id = ?'
            )->execute([$pt['id']]);
        } elseif (!$isLow && $pt['is_currently_low']) {
            $db->prepare(
                'UPDATE part_thresholds SET is_currently_low = 0 WHERE id = ?'
            )->execute([$pt['id']]);
        }
    }

    if ($newlyLow) {
        dispatchAdvanced($contact, $office['name'], $newlyLow);
    }
    return $newlyLow;
}

function getOrCreateContactAlertState(int $officeId, int $contactId): array
{
    $db   = getDb();
    $stmt = $db->prepare(
        'SELECT * FROM contact_alert_states WHERE office_id = ? AND contact_id = ?'
    );
    $stmt->execute([$officeId, $contactId]);
    $row = $stmt->fetch();
    if (!$row) {
        $db->prepare(
            'INSERT INTO contact_alert_states (office_id, contact_id, is_currently_low) VALUES (?, ?, 0)'
        )->execute([$officeId, $contactId]);
        $stmt->execute([$officeId, $contactId]);
        $row = $stmt->fetch();
    }
    return $row;
}
