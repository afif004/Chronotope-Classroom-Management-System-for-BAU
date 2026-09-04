#!/usr/bin/env php
<?php
/**
 * Automated Maintenance: Clean Up Old Notifications
 * Deletes read notifications older than 90 days
 * 
 * Usage: php cleanup_notifications.php
 * Cron: 0 2 * * 0 (Every Sunday at 2 AM)
 */

require_once __DIR__ . '/../db_config.php';

try {
    echo "[" . date('Y-m-d H:i:s') . "] Starting notification cleanup\n";

    // Delete read notifications older than 90 days
    $cutoff_date = date('Y-m-d H:i:s', strtotime('-90 days'));

    $stmt = $pdo->prepare("
        DELETE FROM notifications
        WHERE is_read = TRUE AND created_at < ?
    ");
    $stmt->execute([$cutoff_date]);
    $deleted = $stmt->rowCount();

    echo "Deleted {$deleted} old read notifications\n";

    // Get current notification count per user
    $stmt = $pdo->query("
        SELECT user_id, COUNT(*) as count
        FROM notifications
        WHERE is_read = FALSE
        GROUP BY user_id
        HAVING count > 100
    ");
    $users_with_many = $stmt->fetchAll();

    if (count($users_with_many) > 0) {
        echo "WARNING: " . count($users_with_many) . " users have over 100 unread notifications\n";
    }

    echo "[" . date('Y-m-d H:i:s') . "] Cleanup completed successfully\n";

} catch (Exception $e) {
    echo "[ERROR] " . $e->getMessage() . "\n";
    error_log("Notification cleanup error: " . $e->getMessage());
    exit(1);
}
