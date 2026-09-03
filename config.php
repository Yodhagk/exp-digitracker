<?php
$conn = mysqli_connect('localhost', 'digiuser', 'Digi@2026', 'digitracker');
if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}
mysqli_set_charset($conn, "utf8mb4");

// ── Gmail API (Shopping module) ───────────────────────────────────────
// Set up credentials at: https://console.cloud.google.com/
// Enable "Gmail API", create OAuth 2.0 credentials (Web application type).
// Google rejects a bare private IP as a redirect URI, so use a hostname that
// ends in a public suffix (e.g. a free DuckDNS name) resolved locally via
// hosts file/router DNS to this box. Redirect URI: http://<your-host>/shopping.php
// (the app is served from the webroot, not a /digitracker/ subpath).
define('GMAIL_CLIENT_ID',     getenv('GMAIL_CLIENT_ID')     ?: '');
define('GMAIL_CLIENT_SECRET', getenv('GMAIL_CLIENT_SECRET') ?: '');
define('GMAIL_REDIRECT_URI',  getenv('GMAIL_REDIRECT_URI')  ?: (
    (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
    . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
    . '/shopping.php'
));
