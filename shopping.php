<?php
require_once 'includes/auth.php';
require_once 'config.php';
require_once 'includes/gmail.php';
$page_title = 'Shopping';
$uid   = (int)$_SESSION['id'];
$today = date('Y-m-d');
$msg   = '';

// ── OAuth callback ────────────────────────────────────────────────────
if (isset($_GET['code']) && isset($_GET['state']) && $_GET['state'] === 'digi_'.$uid) {
    if (GMAIL_CLIENT_ID) {
        $tokens = gmail_exchange_code($_GET['code']);
        if ($tokens && !empty($tokens['access_token'])) {
            $info    = gmail_user_info($tokens['access_token']);
            $email   = mysqli_real_escape_string($conn, $info['email'] ?? 'unknown');
            $at      = mysqli_real_escape_string($conn, $tokens['access_token']);
            $rt      = mysqli_real_escape_string($conn, $tokens['refresh_token'] ?? '');
            $exp     = date('Y-m-d H:i:s', time() + (int)($tokens['expires_in'] ?? 3600));
            mysqli_query($conn,
                "INSERT INTO gmail_tokens (user_id,gmail_email,access_token,refresh_token,token_expires_at)
                 VALUES ($uid,'$email','$at','$rt','$exp')
                 ON DUPLICATE KEY UPDATE
                   gmail_email='$email', access_token='$at',
                   refresh_token=IF('$rt'='',refresh_token,'$rt'),
                   token_expires_at='$exp'");
            $msg = 'success:Gmail connected! (' . htmlspecialchars($info['email'] ?? '') . ')';
        } else {
            $msg = 'danger:Google auth failed. Check your Client ID/Secret.';
        }
    }
    header('Location: shopping.php?msg='.urlencode($msg));
    exit;
}

// ── POST handlers ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';

    if ($act === 'disconnect_gmail') {
        mysqli_query($conn, "DELETE FROM gmail_tokens WHERE user_id=$uid");
        $msg = 'success:Gmail disconnected.';
    }

    if ($act === 'sync_gmail') {
        $access_token = gmail_get_valid_token($conn, $uid);
        if (!$access_token) {
            $msg = 'danger:Gmail not connected or token expired.';
        } else {
            $days  = max(7, min(365, (int)($_POST['days_back'] ?? 90)));
            $q     = gmail_shopping_query($days);
            $msgs  = gmail_search($access_token, $q, 100);
            $new   = 0; $skip = 0;
            foreach ($msgs as $ref) {
                $full = gmail_get_message($access_token, $ref['id']);
                if (!$full) continue;
                $order = gmail_parse_order($full);
                // Skip if already imported by msg_id or by order_id
                $mid = mysqli_real_escape_string($conn, $order['msg_id']);
                $oid = mysqli_real_escape_string($conn, $order['order_id']);
                $exists = mysqli_fetch_assoc(mysqli_query($conn,
                    "SELECT id FROM shopping_orders
                     WHERE user_id=$uid AND (gmail_msg_id='$mid' OR order_id='$oid')
                     LIMIT 1"));
                if ($exists) { $skip++; continue; }
                $pf = $order['platform'];
                $pn = mysqli_real_escape_string($conn, $order['product']);
                $sl = mysqli_real_escape_string($conn, $order['seller']);
                $am = (float)$order['amount'];
                $od = $order['order_date'];
                mysqli_query($conn,
                    "INSERT INTO shopping_orders
                     (user_id,platform,order_id,product_name,seller,amount,order_date,source,gmail_msg_id)
                     VALUES ($uid,'$pf','$oid','$pn','$sl',$am,'$od','gmail','$mid')");
                $new++;
                usleep(100000); // 100ms between API calls to avoid rate limit
            }
            // Update sync timestamp
            mysqli_query($conn,
                "UPDATE gmail_tokens SET last_sync_at=NOW(), sync_count=sync_count+1 WHERE user_id=$uid");
            $msg = "success:Synced — $new new orders imported, $skip already existed.";
        }
    }

    if ($act === 'add_order') {
        $platform  = $_POST['platform'] ?? 'other';
        $order_id  = trim($_POST['order_id'] ?? '') ?: 'MANUAL-'.time();
        $product   = trim($_POST['product_name'] ?? '');
        $seller    = trim($_POST['seller'] ?? '');
        $amount    = (float)($_POST['amount'] ?? 0);
        $order_dt  = $_POST['order_date'] ?: $today;
        $status    = $_POST['order_status'] ?? 'ordered';
        $notes     = trim($_POST['notes'] ?? '');
        if ($product) {
            $stmt = mysqli_prepare($conn,
                'INSERT INTO shopping_orders
                 (user_id,platform,order_id,product_name,seller,amount,order_date,status,source,notes)
                 VALUES (?,?,?,?,?,?,?,?,?,?)');
            mysqli_stmt_bind_param($stmt,'issssdssss',
                $uid,$platform,$order_id,$product,$seller,$amount,$order_dt,$status,'manual',$notes);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $msg = 'success:Order added.';
        }
    }

    if ($act === 'update_status') {
        $oid    = (int)($_POST['order_id_pk'] ?? 0);
        $status = $_POST['order_status'] ?? 'ordered';
        mysqli_query($conn,
            "UPDATE shopping_orders SET status='$status' WHERE id=$oid AND user_id=$uid");
        $msg = 'success:Status updated.';
    }

    if ($act === 'delete_order') {
        $oid = (int)($_POST['order_id_pk'] ?? 0);
        mysqli_query($conn, "DELETE FROM shopping_orders WHERE id=$oid AND user_id=$uid");
        $msg = 'success:Order deleted.';
    }

    header('Location: shopping.php?msg='.urlencode($msg));
    exit;
}

$msg = $_GET['msg'] ?? '';

// ── Gmail connection info ─────────────────────────────────────────────
$gmail_row = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT * FROM gmail_tokens WHERE user_id=$uid"));
$gmail_connected = !empty($gmail_row);

// ── Filters ───────────────────────────────────────────────────────────
$platform_filter = $_GET['platform'] ?? 'all';
$month_filter    = $_GET['month']    ?? '';
$search          = trim($_GET['q'] ?? '');

$where = "WHERE user_id=$uid";
if ($platform_filter !== 'all') $where .= " AND platform='".mysqli_real_escape_string($conn,$platform_filter)."'";
if ($month_filter)               $where .= " AND DATE_FORMAT(order_date,'%Y-%m')='".mysqli_real_escape_string($conn,$month_filter)."'";
if ($search)                     $where .= " AND (product_name LIKE '%".mysqli_real_escape_string($conn,$search)."%' OR order_id LIKE '%".mysqli_real_escape_string($conn,$search)."%')";

$orders = mysqli_fetch_all(mysqli_query($conn,
    "SELECT * FROM shopping_orders $where ORDER BY order_date DESC, id DESC"), MYSQLI_ASSOC);

// ── Stats ─────────────────────────────────────────────────────────────
$stats = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) total_orders, COALESCE(SUM(amount),0) total_spent,
            SUM(platform='amazon') amazon_cnt, COALESCE(SUM(IF(platform='amazon',amount,0)),0) amazon_amt,
            SUM(platform='flipkart') fk_cnt, COALESCE(SUM(IF(platform='flipkart',amount,0)),0) fk_amt,
            SUM(platform='myntra') myntra_cnt
     FROM shopping_orders WHERE user_id=$uid"));

$platform_totals = mysqli_fetch_all(mysqli_query($conn,
    "SELECT platform, COUNT(*) cnt, COALESCE(SUM(amount),0) total
     FROM shopping_orders WHERE user_id=$uid
     GROUP BY platform ORDER BY total DESC"), MYSQLI_ASSOC);

$monthly_totals = mysqli_fetch_all(mysqli_query($conn,
    "SELECT DATE_FORMAT(order_date,'%b %Y') mo, DATE_FORMAT(order_date,'%Y-%m') mo_key,
            COUNT(*) cnt, COALESCE(SUM(amount),0) total
     FROM shopping_orders WHERE user_id=$uid
     GROUP BY mo_key ORDER BY mo_key DESC LIMIT 6"), MYSQLI_ASSOC);

function fmt(float $n): string { return '₹'.number_format($n, 0); }

$platform_meta = [
    'amazon'   => ['icon'=>'fab fa-amazon',    'color'=>'#ff9900','bg'=>'#fff8ee','label'=>'Amazon'],
    'flipkart' => ['icon'=>'fas fa-shopping-bag','color'=>'#2874f0','bg'=>'#edf4ff','label'=>'Flipkart'],
    'myntra'   => ['icon'=>'fas fa-tshirt',     'color'=>'#ff3f6c','bg'=>'#fff0f4','label'=>'Myntra'],
    'swiggy'   => ['icon'=>'fas fa-motorcycle', 'color'=>'#fc8019','bg'=>'#fff5ee','label'=>'Swiggy'],
    'zomato'   => ['icon'=>'fas fa-utensils',   'color'=>'#e23744','bg'=>'#fff0f1','label'=>'Zomato'],
    'nykaa'    => ['icon'=>'fas fa-heart',       'color'=>'#fc2779','bg'=>'#fff0f7','label'=>'Nykaa'],
    'meesho'   => ['icon'=>'fas fa-store',       'color'=>'#9b32c4','bg'=>'#f8f0ff','label'=>'Meesho'],
    'other'    => ['icon'=>'fas fa-box',         'color'=>'#64748b','bg'=>'#f1f5f9','label'=>'Other'],
];

$status_badges = [
    'ordered'   => 'badge-pending',
    'shipped'   => 'badge-pending',
    'delivered' => 'badge-paid',
    'cancelled' => 'badge-overdue',
    'returned'  => 'badge-overdue',
];

require_once 'includes/header.php';
?>

<?php if ($msg): [$mt,$mx] = explode(':', $msg, 2); ?>
<div class="alert alert-<?= $mt ?> alert-dismissible fade show py-2">
  <i class="fas fa-<?= $mt==='success'?'circle-check':'triangle-exclamation' ?> me-2"></i><?= htmlspecialchars($mx) ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- ── Gmail connection banner ────────────────────────────────────── -->
<?php if (!$gmail_connected): ?>
<div class="card mb-4" style="border-left:4px solid #ea4335;">
  <div class="card-body py-3">
    <div class="d-flex align-items-center flex-wrap gap-3">
      <div class="d-flex align-items-center gap-3 flex-grow-1">
        <div style="width:48px;height:48px;border-radius:12px;background:#fff0f0;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
          <i class="fab fa-google" style="font-size:1.6rem;color:#ea4335;"></i>
        </div>
        <div>
          <div class="fw-bold">Connect Gmail to auto-import orders</div>
          <div class="text-muted" style="font-size:.82rem;">
            DigiTracker will scan your inbox for Amazon, Flipkart, Myntra &amp; more — read-only access.
          </div>
        </div>
      </div>
      <?php if (GMAIL_CLIENT_ID): ?>
      <a href="<?= htmlspecialchars(gmail_auth_url('digi_'.$uid)) ?>"
         class="btn btn-danger btn-sm flex-shrink-0">
        <i class="fab fa-google me-1"></i>Connect Gmail
      </a>
      <?php else: ?>
      <div class="alert alert-warning py-1 px-3 mb-0" style="font-size:.8rem;">
        <i class="fas fa-triangle-exclamation me-1"></i>
        Set <code>GMAIL_CLIENT_ID</code> &amp; <code>GMAIL_CLIENT_SECRET</code> env vars to enable Gmail sync.
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php else: ?>
<div class="card mb-4" style="border-left:4px solid #10b981;">
  <div class="card-body py-2">
    <div class="d-flex align-items-center flex-wrap gap-3">
      <div class="d-flex align-items-center gap-2 flex-grow-1">
        <i class="fas fa-circle-check text-success"></i>
        <span class="fw-semibold">Gmail connected:</span>
        <span class="text-muted"><?= htmlspecialchars($gmail_row['gmail_email']) ?></span>
        <?php if ($gmail_row['last_sync_at']): ?>
        <span class="text-muted" style="font-size:.78rem;">&nbsp;·&nbsp;Last sync: <?= date('d M, g:ia', strtotime($gmail_row['last_sync_at'])) ?></span>
        <?php endif; ?>
      </div>
      <form method="POST" class="d-flex gap-2 align-items-center">
        <input type="hidden" name="action" value="sync_gmail">
        <select name="days_back" class="form-select form-select-sm" style="width:120px;">
          <option value="30">Last 30 days</option>
          <option value="90" selected>Last 90 days</option>
          <option value="180">Last 6 months</option>
          <option value="365">Last 1 year</option>
        </select>
        <button type="submit" class="btn btn-sm btn-primary">
          <i class="fas fa-sync me-1"></i>Sync Emails
        </button>
      </form>
      <form method="POST" onsubmit="return confirm('Disconnect Gmail?')">
        <input type="hidden" name="action" value="disconnect_gmail">
        <button type="submit" class="btn btn-sm btn-outline-danger">
          <i class="fas fa-unlink me-1"></i>Disconnect
        </button>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ── Summary stats ─────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
  <div class="col-6 col-sm-3">
    <div class="stat-card">
      <div class="stat-icon blue"><i class="fas fa-boxes-stacked"></i></div>
      <div class="stat-info">
        <div class="stat-label">Total Orders</div>
        <div class="stat-value"><?= number_format((int)$stats['total_orders']) ?></div>
      </div>
    </div>
  </div>
  <div class="col-6 col-sm-3">
    <div class="stat-card">
      <div class="stat-icon orange"><i class="fas fa-indian-rupee-sign"></i></div>
      <div class="stat-info">
        <div class="stat-label">Total Spent</div>
        <div class="stat-value" style="font-size:1.15rem;"><?= fmt((float)$stats['total_spent']) ?></div>
      </div>
    </div>
  </div>
  <div class="col-6 col-sm-3">
    <div class="stat-card">
      <div class="stat-icon" style="background:#fff8ee;"><i class="fab fa-amazon" style="color:#ff9900;font-size:1.5rem;"></i></div>
      <div class="stat-info">
        <div class="stat-label">Amazon</div>
        <div class="stat-value" style="font-size:1.1rem;"><?= fmt((float)$stats['amazon_amt']) ?></div>
        <div class="stat-sub"><?= (int)$stats['amazon_cnt'] ?> orders</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-sm-3">
    <div class="stat-card">
      <div class="stat-icon" style="background:#edf4ff;"><i class="fas fa-shopping-bag" style="color:#2874f0;font-size:1.3rem;"></i></div>
      <div class="stat-info">
        <div class="stat-label">Flipkart</div>
        <div class="stat-value" style="font-size:1.1rem;"><?= fmt((float)$stats['fk_amt']) ?></div>
        <div class="stat-sub"><?= (int)$stats['fk_cnt'] ?> orders</div>
      </div>
    </div>
  </div>
</div>

<div class="row g-3 mb-4">
  <!-- Platform breakdown -->
  <div class="col-md-5">
    <div class="card h-100">
      <div class="card-header"><h6 class="card-title mb-0"><i class="fas fa-chart-pie me-2 text-primary"></i>By Platform</h6></div>
      <div class="card-body p-3">
        <?php if (empty($platform_totals)): ?>
          <div class="text-center text-muted py-3" style="font-size:.85rem;">No orders yet</div>
        <?php else:
          $max_total = max(array_column($platform_totals, 'total') ?: [1]);
          foreach ($platform_totals as $pt):
            $pm  = $platform_meta[$pt['platform']] ?? $platform_meta['other'];
            $pct = $max_total > 0 ? round((float)$pt['total'] / (float)$max_total * 100) : 0;
        ?>
        <div class="d-flex align-items-center gap-2 mb-2">
          <div style="width:28px;height:28px;border-radius:8px;background:<?= $pm['bg'] ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <i class="<?= $pm['icon'] ?>" style="color:<?= $pm['color'] ?>;font-size:.85rem;"></i>
          </div>
          <div class="flex-grow-1">
            <div class="d-flex justify-content-between" style="font-size:.78rem;">
              <span class="fw-semibold"><?= $pm['label'] ?></span>
              <span><?= fmt((float)$pt['total']) ?> <span class="text-muted">(<?= $pt['cnt'] ?>)</span></span>
            </div>
            <div class="progress mt-1" style="height:4px;">
              <div class="progress-bar" style="width:<?= $pct ?>%;background:<?= $pm['color'] ?>;"></div>
            </div>
          </div>
        </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>

  <!-- Monthly spend -->
  <div class="col-md-7">
    <div class="card h-100">
      <div class="card-header"><h6 class="card-title mb-0"><i class="fas fa-chart-bar me-2 text-warning"></i>Monthly Spend</h6></div>
      <div class="card-body p-3">
        <?php if (empty($monthly_totals)): ?>
          <div class="text-center text-muted py-3" style="font-size:.85rem;">No orders yet</div>
        <?php else:
          $max_mo = max(array_column($monthly_totals, 'total') ?: [1]);
          foreach ($monthly_totals as $mt):
            $pct = $max_mo > 0 ? round((float)$mt['total'] / (float)$max_mo * 100) : 0;
        ?>
        <div class="d-flex align-items-center gap-2 mb-2">
          <div style="width:72px;font-size:.76rem;color:var(--text-muted);flex-shrink:0;"><?= $mt['mo'] ?></div>
          <div class="flex-grow-1">
            <div class="progress" style="height:16px;border-radius:4px;">
              <div class="progress-bar bg-primary" style="width:<?= $pct ?>%;font-size:.68rem;line-height:16px;padding-left:4px;">
                <?= $pct > 15 ? fmt((float)$mt['total']) : '' ?>
              </div>
            </div>
          </div>
          <div style="width:80px;text-align:right;font-size:.78rem;font-weight:600;"><?= fmt((float)$mt['total']) ?></div>
          <div style="width:40px;text-align:right;font-size:.73rem;color:var(--text-muted);"><?= $mt['cnt'] ?>x</div>
        </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- ── Orders list ────────────────────────────────────────────────── -->
<div class="card">
  <div class="card-header flex-wrap gap-2">
    <h6 class="card-title mb-0"><i class="fas fa-shopping-cart me-2 text-primary"></i>Orders</h6>
    <div class="d-flex gap-2 flex-wrap ms-auto">
      <!-- Search -->
      <form method="GET" class="d-flex gap-1">
        <?php if ($platform_filter !== 'all') echo '<input type="hidden" name="platform" value="'.htmlspecialchars($platform_filter).'">'; ?>
        <?php if ($month_filter) echo '<input type="hidden" name="month" value="'.htmlspecialchars($month_filter).'">'; ?>
        <input type="search" name="q" class="form-control form-control-sm" placeholder="Search orders…" value="<?= htmlspecialchars($search) ?>" style="width:160px;">
        <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="fas fa-search"></i></button>
      </form>
      <!-- Platform filter -->
      <form method="GET" id="filterForm">
        <?php if ($search) echo '<input type="hidden" name="q" value="'.htmlspecialchars($search).'">'; ?>
        <select name="platform" class="form-select form-select-sm" style="width:130px;" onchange="this.form.submit()">
          <option value="all" <?= $platform_filter==='all'?'selected':'' ?>>All Platforms</option>
          <?php foreach ($platform_meta as $k => $pm): ?>
          <option value="<?= $k ?>" <?= $platform_filter===$k?'selected':'' ?>><?= $pm['label'] ?></option>
          <?php endforeach; ?>
        </select>
      </form>
      <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addOrderModal">
        <i class="fas fa-plus me-1"></i>Add Order
      </button>
    </div>
  </div>

  <?php if (empty($orders)): ?>
  <div class="card-body text-center py-5 text-muted">
    <i class="fas fa-shopping-cart fa-3x mb-3 opacity-25"></i>
    <p class="mb-2">No orders found<?= $platform_filter!=='all' ? ' for '.($platform_meta[$platform_filter]['label']??$platform_filter) : '' ?></p>
    <?php if ($gmail_connected): ?>
    <p class="mb-0" style="font-size:.83rem;">Try syncing your Gmail inbox for purchase confirmations.</p>
    <?php endif; ?>
  </div>
  <?php else: ?>
  <div class="table-wrapper">
    <table class="table">
      <thead>
        <tr>
          <th>Platform</th>
          <th>Order ID</th>
          <th>Product</th>
          <th>Amount</th>
          <th>Date</th>
          <th>Source</th>
          <th>Status</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($orders as $o):
          $pm = $platform_meta[$o['platform']] ?? $platform_meta['other'];
        ?>
        <tr>
          <td>
            <div class="d-flex align-items-center gap-2">
              <div style="width:32px;height:32px;border-radius:8px;background:<?= $pm['bg'] ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <i class="<?= $pm['icon'] ?>" style="color:<?= $pm['color'] ?>;"></i>
              </div>
              <span style="font-size:.8rem;font-weight:600;"><?= $pm['label'] ?></span>
            </div>
          </td>
          <td style="font-size:.78rem;color:var(--text-muted);"><?= htmlspecialchars($o['order_id']) ?></td>
          <td>
            <div style="max-width:280px;">
              <div style="font-size:.85rem;font-weight:500;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars($o['product_name']) ?>">
                <?= htmlspecialchars($o['product_name']) ?>
              </div>
              <?php if ($o['seller']): ?>
              <div class="text-muted" style="font-size:.73rem;">Sold by: <?= htmlspecialchars($o['seller']) ?></div>
              <?php endif; ?>
            </div>
          </td>
          <td class="fw-bold"><?= fmt((float)$o['amount']) ?></td>
          <td style="font-size:.82rem;"><?= date('d M Y', strtotime($o['order_date'])) ?></td>
          <td>
            <?php if ($o['source'] === 'gmail'): ?>
            <span class="badge" style="background:#fff0f0;color:#ea4335;font-size:.7rem;"><i class="fab fa-google me-1"></i>Gmail</span>
            <?php else: ?>
            <span class="badge bg-light text-secondary" style="font-size:.7rem;">Manual</span>
            <?php endif; ?>
          </td>
          <td>
            <select class="form-select form-select-sm" style="width:115px;font-size:.76rem;padding:.2rem .4rem;"
                    onchange="updateStatus(<?= $o['id'] ?>, this.value)">
              <?php foreach (['ordered','shipped','delivered','cancelled','returned'] as $s): ?>
              <option value="<?= $s ?>" <?= $o['status']===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
              <?php endforeach; ?>
            </select>
          </td>
          <td>
            <form method="POST" onsubmit="return confirm('Delete this order?')">
              <input type="hidden" name="action" value="delete_order">
              <input type="hidden" name="order_id_pk" value="<?= $o['id'] ?>">
              <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-body border-top py-2 text-muted" style="font-size:.79rem;">
    <?= count($orders) ?> order<?= count($orders)!==1?'s':'' ?> shown
    <?php if ($platform_filter!=='all' || $search): ?>&nbsp;·&nbsp;
    <a href="shopping.php" class="text-primary">Clear filters</a>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<!-- ── Add Order Modal ────────────────────────────────────────────── -->
<div class="modal fade" id="addOrderModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header text-white" style="background:#3b82f6;">
        <h5 class="modal-title"><i class="fas fa-shopping-cart me-2"></i>Add Order Manually</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="add_order">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-6">
              <label class="form-label fw-semibold">Platform</label>
              <select class="form-select" name="platform">
                <?php foreach ($platform_meta as $k => $pm): ?>
                <option value="<?= $k ?>"><?= $pm['label'] ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-6">
              <label class="form-label fw-semibold">Order ID</label>
              <input type="text" class="form-control" name="order_id" placeholder="Optional">
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Product Name <span class="text-danger">*</span></label>
              <input type="text" class="form-control" name="product_name" required placeholder="e.g. OnePlus Nord CE3 Lite 5G">
            </div>
            <div class="col-6">
              <label class="form-label fw-semibold">Seller</label>
              <input type="text" class="form-control" name="seller" placeholder="Optional">
            </div>
            <div class="col-6">
              <label class="form-label fw-semibold">Amount <span class="text-danger">*</span></label>
              <div class="input-group">
                <span class="input-group-text">₹</span>
                <input type="number" class="form-control" name="amount" min="0" step="0.01" required>
              </div>
            </div>
            <div class="col-6">
              <label class="form-label fw-semibold">Order Date</label>
              <input type="date" class="form-control" name="order_date" value="<?= $today ?>">
            </div>
            <div class="col-6">
              <label class="form-label fw-semibold">Status</label>
              <select class="form-select" name="order_status">
                <option value="ordered">Ordered</option>
                <option value="shipped">Shipped</option>
                <option value="delivered">Delivered</option>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Notes</label>
              <input type="text" class="form-control form-control-sm" name="notes" placeholder="Optional">
            </div>
          </div>
        </div>
        <div class="modal-footer py-2">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-plus me-1"></i>Add Order</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Hidden form for status update -->
<form method="POST" id="statusForm" style="display:none;">
  <input type="hidden" name="action" value="update_status">
  <input type="hidden" name="order_id_pk" id="status_oid">
  <input type="hidden" name="order_status" id="status_val">
</form>

<?php
$extra_js = <<<'JS'
<script>
function updateStatus(id, val) {
  document.getElementById('status_oid').value = id;
  document.getElementById('status_val').value = val;
  document.getElementById('statusForm').submit();
}
</script>
JS;
require_once 'includes/footer.php';
