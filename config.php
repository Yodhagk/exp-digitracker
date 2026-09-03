<?php
// ── Runtime secrets ───────────────────────────────────────────────────
// Nothing sensitive lives in this repository. Values are resolved, in order:
//   1. a real environment variable (getenv), then
//   2. /etc/digitracker/digitracker.env — KEY='value' lines written on the
//      server by scripts/write-secrets.sh from GitHub Actions secrets.
// See README.md → "Secrets & configuration".
$__env_file = getenv('DIGITRACKER_ENV_FILE') ?: '/etc/digitracker/digitracker.env';
$__env = [];
if (is_readable($__env_file)) {
    foreach (file($__env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $__line) {
        $__line = trim($__line);
        if ($__line === '' || $__line[0] === '#' || !str_contains($__line, '=')) continue;
        [$__k, $__v] = explode('=', $__line, 2);
        $__v = trim($__v);
        if (strlen($__v) >= 2 && ($__v[0] === "'" || $__v[0] === '"') && $__v[-1] === $__v[0]) {
            $__v = substr($__v, 1, -1);
        }
        $__env[trim($__k)] = $__v;
    }
}
function digi_env(string $key, string $default = ''): string {
    global $__env;
    $v = getenv($key);
    if ($v !== false && $v !== '') return $v;
    return $__env[$key] ?? $default;
}

// ── Database ──────────────────────────────────────────────────────────
$__db_pass = digi_env('DB_PASS');
if ($__db_pass === '') {
    http_response_code(500);
    die('DigiTracker is not configured: DB_PASS is missing. Expected '
      . htmlspecialchars($__env_file) . ' (written by the deploy from GitHub Actions '
      . 'secrets) or a DB_PASS environment variable.');
}
$conn = mysqli_connect(
    digi_env('DB_HOST', 'localhost'),
    digi_env('DB_USER', 'digiuser'),
    $__db_pass,
    digi_env('DB_NAME', 'digitracker')
);
if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}
mysqli_set_charset($conn, "utf8mb4");

// ── Gmail API (Shopping module) ───────────────────────────────────────
// Set up credentials at: https://console.cloud.google.com/
// Enable "Gmail API", create OAuth 2.0 credentials (Web application type).
// Google rejects a bare private IP as a redirect URI, so use a hostname that
// ends in a public suffix (e.g. a free DuckDNS name) resolved locally via
// hosts file/router DNS to this box. Redirect URI: https://<your-host>/shopping.php
// (https is mandatory for the gmail.readonly scope; the app is served from the
// webroot, not a /digitracker/ subpath).
define('GMAIL_CLIENT_ID',     digi_env('GMAIL_CLIENT_ID'));
define('GMAIL_CLIENT_SECRET', digi_env('GMAIL_CLIENT_SECRET'));
define('GMAIL_REDIRECT_URI',  digi_env('GMAIL_REDIRECT_URI') ?: (
    (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
    . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
    . '/shopping.php'
));
