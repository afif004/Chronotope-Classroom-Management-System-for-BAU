<?php
/**
 * Database Configuration — EXAMPLE
 * Classroom Management System (BAU)
 *
 * SETUP:
 *   1. Copy this file to db_config.php
 *   2. Fill in your local/server database credentials below
 *   3. db_config.php is gitignored and must NEVER be committed
 */

// Database configuration
$host     = 'localhost';
$dbname   = 'your_database_name';
$username = 'your_database_user';
$password = 'your_database_password';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Database connection failed.");
}

// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Include core functions
require_once __DIR__ . '/includes/functions.php';

// Base URL
define('BASE_URL', '/RMS/');

// Time zone
date_default_timezone_set('Asia/Dhaka');
