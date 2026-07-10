<?php
/**
 * cron/send_sms.php — Background worker for sending queued SMS messages.
 * Should be run via Cron job every minute on Hostinger:
 * * * * * * php /home/username/public_html/cron/send_sms.php > /dev/null 2>&1
 */

// Prevent execution via web browser for security (only allow CLI/cron execution)
if (php_sapi_name() !== 'cli') {
    header('HTTP/1.1 403 Forbidden');
    die('Direct access forbidden. This script must be run from the command line.');
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

set_time_limit(60); // 1 minute limit (since cron runs every minute)

// ── Execution Locking ────────────────────────────────────────────────────────
$lockFile = __DIR__ . '/send_sms.lock';
$lockHandle = fopen($lockFile, 'c');
if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    echo "Another instance is already running. Exiting.\n";
    exit(0);
}

try {
    $pdo = getDB();
    $settings = getSettings();

    // Fetch up to 30 pending SMS messages
    $stmt = $pdo->prepare("
        SELECT id, phone, message, attempts 
        FROM sms_queue 
        WHERE status = 'pending' AND attempts < 3 
        ORDER BY id ASC 
        LIMIT 30
    ");
    $stmt->execute();
    $queuedSms = $stmt->fetchAll();

    if (empty($queuedSms)) {
        echo "No pending SMS messages in queue.\n";
        exit(0);
    }

    echo "Found " . count($queuedSms) . " pending SMS message(s) to send.\n";

    // Prepare update queries
    $updateSuccess = $pdo->prepare("
        UPDATE sms_queue 
        SET status = 'sent', attempts = attempts + 1, last_attempt = NOW() 
        WHERE id = ?
    ");
    
    $updateFailure = $pdo->prepare("
        UPDATE sms_queue 
        SET status = ?, attempts = attempts + 1, last_attempt = NOW(), error_message = ? 
        WHERE id = ?
    ");

    foreach ($queuedSms as $sms) {
        $id = (int)$sms['id'];
        $phone = $sms['phone'];
        $message = $sms['message'];
        $attempts = (int)$sms['attempts'] + 1;

        echo "Sending to $phone (ID: $id, Attempt: $attempts)... ";
        
        try {
            $success = sendSmsDirect($phone, $message, $settings);
            if ($success) {
                $updateSuccess->execute([$id]);
                echo "SUCCESS\n";
            } else {
                $status = ($attempts >= 3) ? 'failed' : 'pending';
                $updateFailure->execute([$status, 'Unknown error', $id]);
                echo "FAILED (No error details)\n";
            }
        } catch (Exception $e) {
            $status = ($attempts >= 3) ? 'failed' : 'pending';
            $updateFailure->execute([$status, $e->getMessage(), $id]);
            echo "FAILED: " . $e->getMessage() . "\n";
        }
    }

    echo "Batch complete.\n";

} catch (Exception $e) {
    $msg = 'SMS Cron Fatal Error: ' . $e->getMessage();
    error_log($msg);
    echo $msg . "\n";
    // Alert via email if configured
    $alertEmail = getenv('CRON_ALERT_EMAIL');
    if ($alertEmail) {
        @mail($alertEmail, '[KIMTECH] SMS Cron Failed', $msg);
    }
    exit(1);
} finally {
    // Release lock and close handle
    if ($lockHandle) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
}
