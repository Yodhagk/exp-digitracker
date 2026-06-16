<?php
$conn = mysqli_connect('localhost', 'digiuser', 'Digi@2026', 'digitracker');
if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}
mysqli_set_charset($conn, "utf8mb4");

// ── Gmail API (Shopping module) ───────────────────────────────────────
// Set up credentials at: https://console.cloud.google.com/
// Enable "Gmail API", create OAuth 2.0 credentials (Web application type).
// Add authorised redirect URI: http://<your-server>/digitracker/shopping.php
define('GMAIL_CLIENT_ID',     getenv('GMAIL_CLIENT_ID')     ?: '');
define('GMAIL_CLIENT_SECRET', getenv('GMAIL_CLIENT_SECRET') ?: '');
define('GMAIL_REDIRECT_URI',  getenv('GMAIL_REDIRECT_URI')  ?: (
    (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
    . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
    . '/digitracker/shopping.php'
));
