<?php
// Gmail OAuth2 helper — raw curl, zero Composer dependencies.
// Config constants (GMAIL_CLIENT_ID, GMAIL_CLIENT_SECRET, GMAIL_REDIRECT_URI)
// must be defined before including this file (done in config.php).

const GMAIL_AUTH_URL  = 'https://accounts.google.com/o/oauth2/v2/auth';
const GMAIL_TOKEN_URL = 'https://oauth2.googleapis.com/token';
const GMAIL_API_BASE  = 'https://gmail.googleapis.com/gmail/v1/users/me';
const GMAIL_INFO_URL  = 'https://www.googleapis.com/oauth2/v3/userinfo';
const GMAIL_SCOPE     = 'https://www.googleapis.com/auth/gmail.readonly https://www.googleapis.com/auth/userinfo.email';

// ── Auth URL ──────────────────────────────────────────────────────────
function gmail_auth_url(string $state = ''): string {
    return GMAIL_AUTH_URL . '?' . http_build_query([
        'client_id'     => GMAIL_CLIENT_ID,
        'redirect_uri'  => GMAIL_REDIRECT_URI,
        'response_type' => 'code',
        'scope'         => GMAIL_SCOPE,
        'access_type'   => 'offline',
        'prompt'        => 'consent select_account',
        'state'         => $state,
    ]);
}

// ── Token exchange (code → tokens) ───────────────────────────────────
function gmail_exchange_code(string $code): ?array {
    return _gmail_post(GMAIL_TOKEN_URL, [
        'code'          => $code,
        'client_id'     => GMAIL_CLIENT_ID,
        'client_secret' => GMAIL_CLIENT_SECRET,
        'redirect_uri'  => GMAIL_REDIRECT_URI,
        'grant_type'    => 'authorization_code',
    ]);
}

// ── Refresh access token ──────────────────────────────────────────────
function gmail_do_refresh(string $refresh_token): ?array {
    return _gmail_post(GMAIL_TOKEN_URL, [
        'refresh_token' => $refresh_token,
        'client_id'     => GMAIL_CLIENT_ID,
        'client_secret' => GMAIL_CLIENT_SECRET,
        'grant_type'    => 'refresh_token',
    ]);
}

// ── Get valid access token (auto-refresh if expired) ─────────────────
function gmail_get_valid_token($conn, int $uid): ?string {
    $row = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT * FROM gmail_tokens WHERE user_id=$uid"));
    if (!$row) return null;

    if (strtotime($row['token_expires_at']) > time() + 60)
        return $row['access_token'];

    $new = gmail_do_refresh($row['refresh_token']);
    if (!$new || empty($new['access_token'])) return null;

    $exp = date('Y-m-d H:i:s', time() + (int)($new['expires_in'] ?? 3600));
    $at  = mysqli_real_escape_string($conn, $new['access_token']);
    mysqli_query($conn,
        "UPDATE gmail_tokens SET access_token='$at', token_expires_at='$exp' WHERE user_id=$uid");
    return $new['access_token'];
}

// ── Fetch Gmail user info ─────────────────────────────────────────────
function gmail_user_info(string $access_token): ?array {
    return _gmail_get(GMAIL_INFO_URL, $access_token);
}

// ── Search messages ───────────────────────────────────────────────────
function gmail_search(string $access_token, string $query, int $max = 50): array {
    $url = GMAIL_API_BASE . '/messages?' . http_build_query([
        'q'          => $query,
        'maxResults' => $max,
    ]);
    $r = _gmail_get($url, $access_token);
    return $r['messages'] ?? [];
}

// ── Get single message ────────────────────────────────────────────────
function gmail_get_message(string $access_token, string $msg_id): ?array {
    $url = GMAIL_API_BASE . '/messages/' . $msg_id . '?format=full';
    return _gmail_get($url, $access_token);
}

// ── Decode base64url ──────────────────────────────────────────────────
function gmail_b64_decode(string $data): string {
    return base64_decode(strtr($data, '-_', '+/'));
}

// ── Extract text body from message payload ────────────────────────────
function gmail_extract_body(array $payload): string {
    $parts = $payload['parts'] ?? [
        ['mimeType' => $payload['mimeType'] ?? '', 'body' => $payload['body'] ?? []]
    ];

    // DFS through multipart tree
    $text = ''; $html = '';
    $stack = $parts;
    while ($stack) {
        $part = array_shift($stack);
        if (!empty($part['parts'])) {
            $stack = array_merge($part['parts'], $stack);
            continue;
        }
        $data = $part['body']['data'] ?? '';
        if (!$data) continue;
        if ($part['mimeType'] === 'text/plain') $text = gmail_b64_decode($data);
        elseif ($part['mimeType'] === 'text/html' && !$text)
            $html = strip_tags(gmail_b64_decode($data));
    }
    return $text ?: $html;
}

// ── Detect platform from sender / subject ─────────────────────────────
function gmail_detect_platform(string $from, string $subject): string {
    $s = strtolower($from . ' ' . $subject);
    if (str_contains($s, 'amazon'))   return 'amazon';
    if (str_contains($s, 'flipkart')) return 'flipkart';
    if (str_contains($s, 'myntra'))   return 'myntra';
    if (str_contains($s, 'swiggy'))   return 'swiggy';
    if (str_contains($s, 'zomato'))   return 'zomato';
    if (str_contains($s, 'nykaa'))    return 'nykaa';
    if (str_contains($s, 'meesho'))   return 'meesho';
    return 'other';
}

// ── Parse a message into a shopping order array ───────────────────────
function gmail_parse_order(array $msg): array {
    $headers = [];
    foreach ($msg['payload']['headers'] ?? [] as $h)
        $headers[strtolower($h['name'])] = $h['value'];

    $from     = $headers['from'] ?? '';
    $subject  = $headers['subject'] ?? 'Unknown Order';
    $date_hdr = $headers['date'] ?? '';
    $body     = gmail_extract_body($msg['payload']);

    $platform   = gmail_detect_platform($from, $subject);
    $order_id   = '';
    $amount     = 0.0;
    $product    = trim($subject);
    $seller     = '';

    switch ($platform) {
        case 'amazon':
            // Order ID: 3 groups of digits separated by hyphens
            if (preg_match('/\b(\d{3}-\d{7}-\d{7})\b/', "$body $subject", $m))
                $order_id = $m[1];
            // Total
            if (preg_match('/(?:order total|grand total|total amount)[^₹\d]*₹?\s*([\d,]+(?:\.\d{2})?)/i', $body, $m))
                $amount = (float)str_replace(',', '', $m[1]);
            // Product from subject
            if (preg_match('/(?:order of|you ordered)[:\s]+(.+?)(?:\n|has been|will be)/i', $body, $m))
                $product = trim($m[1]);
            break;

        case 'flipkart':
            if (preg_match('/\b(OD\d{10,})\b/i', "$body $subject", $m)) $order_id = $m[0];
            if (preg_match('/(?:total amount|order total|amount payable)[^₹\d]*₹?\s*([\d,]+(?:\.\d{2})?)/i', $body, $m))
                $amount = (float)str_replace(',', '', $m[1]);
            break;

        case 'myntra':
            if (preg_match('/\b(M\d{9,})\b/i', "$body $subject", $m)) $order_id = $m[0];
            if (preg_match('/₹\s*([\d,]+(?:\.\d{2})?)/u', $body, $m))
                $amount = (float)str_replace(',', '', $m[1]);
            break;
    }

    // Generic amount fallback
    if (!$amount) {
        if (preg_match('/₹\s*([\d,]+(?:\.\d{2})?)/u', $body, $m))
            $amount = (float)str_replace(',', '', $m[1]);
        elseif (preg_match('/(?:Rs\.?|INR)\s*([\d,]+(?:\.\d{2})?)/i', $body, $m))
            $amount = (float)str_replace(',', '', $m[1]);
    }

    if (!$order_id) $order_id = 'MSG-' . substr($msg['id'], -12);

    $order_date = $date_hdr ? date('Y-m-d', strtotime($date_hdr) ?: time()) : date('Y-m-d');

    return [
        'platform'  => $platform,
        'order_id'  => $order_id,
        'product'   => substr($product, 0, 490),
        'seller'    => substr($seller, 0, 190),
        'amount'    => $amount,
        'order_date'=> $order_date,
        'msg_id'    => $msg['id'],
    ];
}

// ── Build Gmail search query for e-commerce confirmation emails ───────
function gmail_shopping_query(int $days_back = 90): string {
    $since = date('Y/m/d', strtotime("-{$days_back} days"));
    return implode(' OR ', [
        'from:auto-confirm@amazon.in',
        'from:order-update@amazon.in',
        'from:shipment-tracking@amazon.in',
        'from:noreply@flipkart.com',
        'from:order@myntra.com',
        'from:no-reply@swiggy.in',
        'from:noreply@zomato.com',
        'from:noreply@nykaa.com',
        'from:orders@meesho.com',
    ]) . " after:$since subject:(order OR purchase OR confirmed OR dispatched OR delivered)";
}

// ── Internal curl helpers ─────────────────────────────────────────────
function _gmail_get(string $url, string $access_token): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer $access_token", 'Accept: application/json'],
    ]);
    $r = json_decode(curl_exec($ch), true);
    curl_close($ch);
    return is_array($r) ? $r : null;
}

function _gmail_post(string $url, array $fields): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_POSTFIELDS     => http_build_query($fields),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $r = json_decode(curl_exec($ch), true);
    curl_close($ch);
    return is_array($r) ? $r : null;
}
