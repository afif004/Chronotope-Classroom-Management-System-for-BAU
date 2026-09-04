#!/usr/bin/env php
<?php
/**
 * Automated Maintenance: Mark Past Bookings
 * This script should run daily at midnight
 * 
 * Usage: php mark_past_bookings.php
 * Cron: 0 0 * * * php /path/to/mark_past_bookings.php
 */

require_once __DIR__ . '/../db_config.php';

$yesterday = date('Y-m-d', strtotime('-1 day'));

try {
    // No need to mark as 'completed' - bookings stay as 'booked' or 'cancelled'
    // This script is mainly for cleanup and logging

    echo "[" . date('Y-m-d H:i:s') . "] Maintenance: Checking past bookings\n";

    // Log statistics for past day
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'booked' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled
        FROM room_bookings
        WHERE booking_date = ?
    ");
    $stmt->execute([$yesterday]);
    $stats = $stmt->fetch();

    echo "Yesterday's bookings: {$stats['total']} total, {$stats['completed']} completed, {$stats['cancelled']} cancelled\n";

    // Archive old resolved booking attempts (older than 30 days)
    $archive_date = date('Y-m-d', strtotime('-30 days'));
    $stmt = $pdo->prepare("
        DELETE FROM booking_attempts
        WHERE attempted_date < ? AND is_resolved = TRUE
    ");
    $stmt->execute([$archive_date]);
    $deleted = $stmt->rowCount();

    echo "Archived {$deleted} old resolved booking attempts\n";
    echo "[" . date('Y-m-d H:i:s') . "] Maintenance completed successfully\n";

} catch (Exception $e) {
    echo "[ERROR] " . $e->getMessage() . "\n";
    error_log("Daily maintenance error: " . $e->getMessage());
    exit(1);
}
