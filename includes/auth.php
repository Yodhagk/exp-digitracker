<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/logger.php';

// Re-check status/role against the DB on every request, not just at login, so
// a suspended account is kicked out immediately rather than at next sign-in.
require_once __DIR__ . '/../config.php';
$__auth_row = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT role, status FROM users WHERE id=" . (int)($_SESSION['id'] ?? 0)));
if (!$__auth_row || $__auth_row['status'] === 'suspended') {
    session_destroy();
    header('Location: login.php?msg=' . urlencode('danger:Your account is no longer active.'));
    exit;
}
// Sessions created before role-based access existed won't have this set yet.
$_SESSION['role'] = $__auth_row['role'];
