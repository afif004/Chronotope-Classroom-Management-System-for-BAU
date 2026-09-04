<?php
require_once 'db_config.php';

if (isset($_SESSION['user_id'])) {
    // Log activity before destroying session
    logActivity($pdo, $_SESSION['user_id'], 'user_logout', 'user', $_SESSION['user_id']);
}

session_destroy();
header('Location: index.php');
exit();
