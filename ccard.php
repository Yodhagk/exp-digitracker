<?php
require_once 'includes/auth.php';
require_once 'config.php';
$page_title = 'Credit Cards';
$uid   = (int)$_SESSION['id'];
$today = date('Y-m-d');

// ── POST handlers ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';

    if ($act === 'add_card' || $act === 'edit_card') {
        $holder    = trim($_POST['card_holder'] ?? '');
        $bank      = trim($_POST['bank_name'] ?? '');
        $cname     = trim($_POST['card_name'] ?? '');
        $last4     = preg_replace('/\D/', '', $_POST['card_last4'] ?? '');
        $last4     = substr($last4, -4);
        $network   = $_POST['card_network'] ?? 'visa';
        $limit     = (float)($_POST['credit_limit'] ?? 0);
        $balance   = (float)($_POST['current_balance'] ?? 0);
        $stmt_day  = max(1, min(28, (int)($_POST['statement_date'] ?? 1)));
        $due_days  = max(1, min(45, (int)($_POST['payment_due_days'] ?? 20)));
        $rate      = (float)($_POST['interest_rate'] ?? 0);
        $color     = preg_match('/^#[0-9a-f]{6}$/i', $_POST['card_color'] ?? '') ? $_POST['card_color'] : '#1e3a5f';
        $status    = $_POST['card_status'] ?? 'active';
        $notes     = trim($_POST['notes'] ?? '');

        if ($act === 'add_card') {
            $stmt = mysqli_prepare($conn,
                'INSERT INTO credit_cards
                 (user_id,card_holder,bank_name,card_name,card_last4,card_network,
                  credit_limit,current_balance,statement_date,payment_due_days,
                  interest_rate,card_color,status,notes)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            mysqli_stmt_bind_param($stmt, 'isssssddiiidss',
                $uid,$holder,$bank,$cname,$last4,$network,
                $limit,$balance,$stmt_day,$due_days,
                $rate,$color,$status,$notes);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $msg = 'success:Card added successfully.';
        } else {
            $cid = (int)($_POST['card_id'] ?? 0);
            mysqli_query($conn,
                "UPDATE credit_cards SET
                  card_holder='".mysqli_real_escape_string($conn,$holder)."',
                  bank_name='".mysqli_real_escape_string($conn,$bank)."',
                  card_name='".mysqli_real_escape_string($conn,$cname)."',
                  card_last4='$last4', card_network='$network',
                  credit_limit=$limit, current_balance=$balance,
                  statement_date=$stmt_day, payment_due_days=$due_days,
                  interest_rate=$rate, card_color='$color',
                  status='$status',
                  notes='".mysqli_real_escape_string($conn,$notes)."'
                 WHERE id=$cid AND user_id=$uid");
            $msg = 'success:Card updated.';
        }
    }

    if ($act === 'delete_card') {
        $cid = (int)($_POST['card_id'] ?? 0);
        mysqli_query($conn, "DELETE FROM card_bills WHERE card_id=$cid AND user_id=$uid");
        mysqli_query($conn, "DELETE FROM credit_cards WHERE id=$cid AND user_id=$uid");
        $msg = 'success:Card deleted.';
    }

    if ($act === 'generate_bill') {
        $cid      = (int)($_POST['card_id'] ?? 0);
        $bill_amt = (float)($_POST['bill_amount'] ?? 0);
        $min_due  = (float)($_POST['min_due'] ?? 0);
        $month    = $_POST['bill_month'] ?? date('Y-m');
        $card     = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT * FROM credit_cards WHERE id=$cid AND user_id=$uid"));
        if ($card) {
            // Compute statement date and due date for this month
            $year   = (int)substr($month, 0, 4);
            $mon    = (int)substr($month, 5, 2);
            $sday   = min((int)$card['statement_date'], cal_days_in_month(CAL_GREGORIAN, $mon, $year));
            $stmt_d = "$year-".str_pad($mon,2,'0',STR_PAD_LEFT)."-".str_pad($sday,2,'0',STR_PAD_LEFT);
            $due_d  = date('Y-m-d', strtotime("+{$card['payment_due_days']} days", strtotime($stmt_d)));
            $notes  = mysqli_real_escape_string($conn, trim($_POST['notes'] ?? ''));
            mysqli_query($conn,
                "INSERT INTO card_bills
                 (user_id,card_id,bill_month,statement_date,due_date,bill_amount,min_due,status,notes)
                 VALUES ($uid,$cid,'$month','$stmt_d','$due_d',$bill_amt,$min_due,'pending','$notes')
                 ON DUPLICATE KEY UPDATE
                   bill_amount=$bill_amt, min_due=$min_due, statement_date='$stmt_d',
                   due_date='$due_d', notes='$notes'");
            // Update card balance
            mysqli_query($conn,
                "UPDATE credit_cards SET current_balance=$bill_amt WHERE id=$cid AND user_id=$uid");
            $msg = 'success:Bill recorded for '.date('M Y', strtotime($month.'-01')).'.';
        }
    }

    if ($act === 'pay_bill') {
        $bid      = (int)($_POST['bill_id'] ?? 0);
        $paid_amt = (float)($_POST['paid_amount'] ?? 0);
        $mode     = mysqli_real_escape_string($conn, $_POST['payment_mode'] ?? 'upi');
        $pd       = $_POST['paid_date'] ?: $today;
        $bill     = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT * FROM card_bills WHERE id=$bid AND user_id=$uid"));
        if ($bill) {
            $total_paid = (float)$bill['paid_amount'] + $paid_amt;
            $status     = $total_paid >= (float)$bill['bill_amount'] ? 'paid' : 'partially_paid';
            mysqli_query($conn,
                "UPDATE card_bills
                 SET paid_amount=$total_paid, status='$status',
                     paid_date='$pd', payment_mode='$mode'
                 WHERE id=$bid AND user_id=$uid");
            // Reduce card outstanding balance
            $card_id = (int)$bill['card_id'];
            mysqli_query($conn,
                "UPDATE credit_cards
                 SET current_balance=GREATEST(0,current_balance-$paid_amt)
                 WHERE id=$card_id AND user_id=$uid");
            $msg = 'success:Payment of ₹'.number_format($paid_amt,0).' recorded.';
        }
    }

    header('Location: ccard.php?msg='.urlencode($msg ?? ''));
    exit;
}

$msg = $_GET['msg'] ?? '';

// Auto-flag overdue bills
mysqli_query($conn,
    "UPDATE card_bills SET status='overdue'
     WHERE user_id=$uid AND status='pending' AND due_date < '$today'");

// ── Fetch data ────────────────────────────────────────────────────────
$cards = mysqli_fetch_all(mysqli_query($conn,
    "SELECT c.*,
       (SELECT COUNT(*) FROM card_bills b WHERE b.card_id=c.id AND b.user_id=c.user_id) bill_count,
       (SELECT COALESCE(SUM(b.bill_amount),0) FROM card_bills b WHERE b.card_id=c.id AND b.user_id=c.user_id AND b.status='pending') pending_total,
       (SELECT COALESCE(SUM(b.bill_amount),0) FROM card_bills b WHERE b.card_id=c.id AND b.user_id=c.user_id AND b.status='overdue') overdue_total
     FROM credit_cards c WHERE c.user_id=$uid ORDER BY c.created_at DESC"), MYSQLI_ASSOC);

$bills = mysqli_fetch_all(mysqli_query($conn,
    "SELECT b.*, c.card_name, c.bank_name, c.card_last4, c.card_color, c.card_network
     FROM card_bills b JOIN credit_cards c ON c.id=b.card_id AND c.user_id=b.user_id
     WHERE b.user_id=$uid ORDER BY b.due_date ASC"), MYSQLI_ASSOC);

// ── Stats ─────────────────────────────────────────────────────────────
$total_limit   = array_sum(array_column($cards, 'credit_limit'));
$total_balance = array_sum(array_column($cards, 'current_balance'));
$total_avail   = $total_limit - $total_balance;
$utilization   = $total_limit > 0 ? round($total_balance / $total_limit * 100, 1) : 0;

function util_color(float $pct): string {
    if ($pct >= 80) return '#ef4444';
    if ($pct >= 50) return '#f59e0b';
    return '#10b981';
}
function fmt(float $n): string { return '₹'.number_format($n, 0); }

$network_icons = [
    'visa'       => '<i class="fab fa-cc-visa" style="font-size:1.5rem;color:#fff;opacity:.85;"></i>',
    'mastercard' => '<i class="fab fa-cc-mastercard" style="font-size:1.5rem;"></i>',
    'rupay'      => '<span style="font-size:.65rem;font-weight:800;color:#fff;letter-spacing:.5px;border:1px solid rgba(255,255,255,.5);padding:2px 5px;border-radius:3px;">RuPay</span>',
    'amex'       => '<i class="fab fa-cc-amex" style="font-size:1.5rem;color:#fff;opacity:.85;"></i>',
];

require_once 'includes/header.php';
?>

<?php if ($msg): [$mt,$mx] = explode(':', $msg, 2); ?>
<div class="alert alert-<?= $mt==='success'?'success':'danger' ?> alert-dismissible fade show py-2">
  <i class="fas fa-<?= $mt==='success'?'circle-check':'triangle-exclamation' ?> me-2"></i><?= htmlspecialchars($mx) ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- ── Summary stats ─────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
  <div class="col-6 col-sm-3">
    <div class="stat-card">
      <div class="stat-icon blue"><i class="fas fa-credit-card"></i></div>
      <div class="stat-info">
        <div class="stat-label">Total Cards</div>
        <div class="stat-value"><?= count($cards) ?></div>
      </div>
    </div>
  </div>
  <div class="col-6 col-sm-3">
    <div class="stat-card">
      <div class="stat-icon purple"><i class="fas fa-circle-dollar-to-slot"></i></div>
      <div class="stat-info">
        <div class="stat-label">Total Limit</div>
        <div class="stat-value" style="font-size:1.2rem;"><?= fmt($total_limit) ?></div>
      </div>
    </div>
  </div>
  <div class="col-6 col-sm-3">
    <div class="stat-card">
      <div class="stat-icon red"><i class="fas fa-arrow-trend-up"></i></div>
      <div class="stat-info">
        <div class="stat-label">Total Used</div>
        <div class="stat-value" style="font-size:1.2rem;"><?= fmt($total_balance) ?></div>
        <div class="stat-sub"><?= $utilization ?>% utilization</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-sm-3">
    <div class="stat-card">
      <div class="stat-icon green"><i class="fas fa-wallet"></i></div>
      <div class="stat-info">
        <div class="stat-label">Available</div>
        <div class="stat-value" style="font-size:1.2rem;"><?= fmt($total_avail) ?></div>
      </div>
    </div>
  </div>
</div>

<!-- ── Cards section ─────────────────────────────────────────────── -->
<div class="card mb-4">
  <div class="card-header">
    <h6 class="card-title mb-0"><i class="fas fa-credit-card me-2 text-primary"></i>My Credit Cards</h6>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addCardModal">
      <i class="fas fa-plus me-1"></i>Add Card
    </button>
  </div>
  <div class="card-body p-3">
    <?php if (empty($cards)): ?>
      <div class="text-center text-muted py-4">
        <i class="fas fa-credit-card fa-3x mb-3 opacity-25"></i>
        <p class="mb-1">No credit cards added yet.</p>
        <button class="btn btn-primary btn-sm mt-2" data-bs-toggle="modal" data-bs-target="#addCardModal">
          <i class="fas fa-plus me-1"></i>Add your first card
        </button>
      </div>
    <?php else: ?>
    <div class="row g-3">
      <?php foreach ($cards as $c):
        $util_pct  = $c['credit_limit'] > 0 ? round((float)$c['current_balance'] / (float)$c['credit_limit'] * 100, 1) : 0;
        $util_col  = util_color($util_pct);
        $avail     = max(0, (float)$c['credit_limit'] - (float)$c['current_balance']);
        $bg        = $c['card_color'] ?: '#1e3a5f';
        $net_icon  = $network_icons[$c['card_network']] ?? '';
        // Next statement date
        $stmt_day  = (int)$c['statement_date'];
        $this_m_stmt = date('Y-m-').str_pad($stmt_day, 2, '0', STR_PAD_LEFT);
        $next_stmt = $this_m_stmt >= $today ? $this_m_stmt
            : date('Y-m-', strtotime('+1 month')).$stmt_day;
      ?>
      <div class="col-md-6 col-xl-4">
        <div class="rounded-3 overflow-hidden" style="box-shadow:0 4px 20px rgba(0,0,0,.15);">

          <!-- Visual card face -->
          <div class="p-3 position-relative" style="background:linear-gradient(135deg,<?= $bg ?> 0%,<?= $bg ?>cc 100%);min-height:160px;">
            <!-- Chip + Network -->
            <div class="d-flex justify-content-between align-items-start">
              <div style="width:38px;height:28px;background:linear-gradient(135deg,#e8c96b,#c8a84b);border-radius:4px;display:flex;align-items:center;justify-content:center;">
                <div style="width:24px;height:18px;border:1.5px solid rgba(0,0,0,.25);border-radius:2px;background:linear-gradient(135deg,#d4b050,#b8903a);"></div>
              </div>
              <div><?= $net_icon ?></div>
            </div>

            <!-- Bank + Card name -->
            <div class="mt-3">
              <div style="color:rgba(255,255,255,.6);font-size:.72rem;letter-spacing:1px;text-transform:uppercase;"><?= htmlspecialchars($c['bank_name']) ?></div>
              <div style="color:#fff;font-size:.85rem;font-weight:600;"><?= htmlspecialchars($c['card_name']) ?></div>
            </div>

            <!-- Card number (masked) -->
            <div class="d-flex gap-2 mt-2" style="font-size:1rem;font-weight:700;letter-spacing:3px;color:rgba(255,255,255,.9);">
              <span>••••</span><span>••••</span><span>••••</span><span><?= htmlspecialchars($c['card_last4']) ?></span>
            </div>

            <!-- Holder -->
            <div class="mt-2" style="color:rgba(255,255,255,.75);font-size:.78rem;text-transform:uppercase;letter-spacing:.5px;">
              <?= htmlspecialchars($c['card_holder']) ?>
            </div>

            <!-- Status badge -->
            <?php if ($c['status'] !== 'active'): ?>
            <div class="position-absolute" style="top:10px;right:12px;">
              <span class="badge bg-<?= $c['status']==='blocked'?'danger':'secondary' ?>"><?= ucfirst($c['status']) ?></span>
            </div>
            <?php endif; ?>
          </div>

          <!-- Info below card -->
          <div class="p-3 bg-white border border-top-0 rounded-bottom-3">
            <!-- Utilization bar -->
            <div class="d-flex justify-content-between mb-1" style="font-size:.75rem;">
              <span class="text-muted">Used: <strong><?= fmt((float)$c['current_balance']) ?></strong></span>
              <span style="color:<?= $util_col ?>;font-weight:700;"><?= $util_pct ?>%</span>
            </div>
            <div class="progress mb-2" style="height:6px;border-radius:3px;">
              <div class="progress-bar" style="width:<?= $util_pct ?>%;background:<?= $util_col ?>;;transition:width .6s;"></div>
            </div>

            <div class="row g-1" style="font-size:.78rem;">
              <div class="col-6">
                <div class="text-muted">Limit</div>
                <div class="fw-semibold"><?= fmt((float)$c['credit_limit']) ?></div>
              </div>
              <div class="col-6">
                <div class="text-muted">Available</div>
                <div class="fw-semibold text-success"><?= fmt($avail) ?></div>
              </div>
              <div class="col-6">
                <div class="text-muted">Statement Day</div>
                <div class="fw-semibold"><?= $c['statement_date'] ?><sup>th</sup> every month</div>
              </div>
              <div class="col-6">
                <div class="text-muted">Due After</div>
                <div class="fw-semibold"><?= $c['payment_due_days'] ?> days</div>
              </div>
            </div>

            <?php if ($c['overdue_total'] > 0): ?>
            <div class="alert alert-danger py-1 px-2 mt-2 mb-0" style="font-size:.78rem;">
              <i class="fas fa-exclamation-triangle me-1"></i>
              Overdue: <?= fmt((float)$c['overdue_total']) ?>
            </div>
            <?php endif; ?>

            <div class="d-flex gap-2 mt-3">
              <button class="btn btn-outline-secondary btn-sm flex-fill"
                      onclick='editCard(<?= json_encode($c) ?>)'>
                <i class="fas fa-pen me-1"></i>Edit
              </button>
              <button class="btn btn-outline-primary btn-sm flex-fill"
                      onclick='openBillModal(<?= $c['id'] ?>, <?= json_encode($c['card_name']) ?>, <?= (float)$c['current_balance'] ?>)'>
                <i class="fas fa-file-invoice-dollar me-1"></i>Add Bill
              </button>
              <button class="btn btn-outline-danger btn-sm"
                      onclick='deleteCard(<?= $c['id'] ?>, <?= json_encode($c['card_name']) ?>)'>
                <i class="fas fa-trash"></i>
              </button>
            </div>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- ── Monthly Bills ──────────────────────────────────────────────── -->
<?php if (!empty($bills)): ?>
<div class="card">
  <div class="card-header">
    <h6 class="card-title mb-0"><i class="fas fa-calendar-check me-2 text-warning"></i>Monthly Bills</h6>
    <div class="d-flex gap-2 flex-wrap">
      <select id="billFilter" class="form-select form-select-sm" style="width:auto;" onchange="filterBills(this.value)">
        <option value="all">All Bills</option>
        <option value="pending">Pending</option>
        <option value="overdue">Overdue</option>
        <option value="paid">Paid</option>
      </select>
    </div>
  </div>
  <div class="table-wrapper">
    <table class="table" id="billsTable">
      <thead>
        <tr>
          <th>Card</th>
          <th>Bill Month</th>
          <th>Statement Date</th>
          <th>Due Date</th>
          <th>Bill Amount</th>
          <th>Min Due</th>
          <th>Paid</th>
          <th>Status</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($bills as $b):
          $days_left = (int)ceil((strtotime($b['due_date']) - strtotime($today)) / 86400);
          $smap = ['pending'=>'badge-pending','paid'=>'badge-paid','overdue'=>'badge-overdue','partially_paid'=>'badge-pending'];
          $remaining = max(0, (float)$b['bill_amount'] - (float)$b['paid_amount']);
        ?>
        <tr data-status="<?= $b['status'] ?>">
          <td>
            <div class="d-flex align-items-center gap-2">
              <div class="rounded-circle" style="width:8px;height:8px;background:<?= htmlspecialchars($b['card_color']) ?>;flex-shrink:0;"></div>
              <div>
                <div class="fw-semibold" style="font-size:.85rem;"><?= htmlspecialchars($b['card_name']) ?></div>
                <div class="text-muted" style="font-size:.73rem;"><?= htmlspecialchars($b['bank_name']) ?> ••<?= htmlspecialchars($b['card_last4']) ?></div>
              </div>
            </div>
          </td>
          <td><?= date('M Y', strtotime($b['bill_month'].'-01')) ?></td>
          <td><?= date('d M Y', strtotime($b['statement_date'])) ?></td>
          <td>
            <?= date('d M Y', strtotime($b['due_date'])) ?>
            <?php if ($b['status'] !== 'paid'): ?>
            <div class="<?= $days_left < 0 ?'text-danger':($days_left<=3?'text-warning':'text-muted') ?>" style="font-size:.73rem;">
              <?= $days_left < 0 ? abs($days_left).'d overdue' : ($days_left===0 ? 'Due today' : 'in '.$days_left.'d') ?>
            </div>
            <?php endif; ?>
          </td>
          <td class="fw-bold"><?= fmt((float)$b['bill_amount']) ?></td>
          <td class="text-muted"><?= fmt((float)$b['min_due']) ?></td>
          <td>
            <?= fmt((float)$b['paid_amount']) ?>
            <?php if ($remaining > 0 && $b['status']!=='paid'): ?>
            <div class="text-danger" style="font-size:.73rem;">↳ <?= fmt($remaining) ?> left</div>
            <?php endif; ?>
          </td>
          <td><span class="badge-status <?= $smap[$b['status']] ?? '' ?>"><?= ucfirst(str_replace('_',' ',$b['status'])) ?></span></td>
          <td>
            <?php if ($b['status'] !== 'paid'): ?>
            <button class="btn btn-sm btn-success"
                    onclick='openPayBillModal(<?= $b['id'] ?>, <?= json_encode($b['card_name']) ?>, <?= (float)$b['bill_amount'] ?>, <?= (float)$b['paid_amount'] ?>)'>
              <i class="fas fa-check me-1"></i>Pay
            </button>
            <?php else: ?>
            <span class="text-success"><i class="fas fa-circle-check"></i> Cleared</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- ── Add Card Modal ─────────────────────────────────────────────── -->
<div class="modal fade" id="addCardModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header" style="background:#1e3a5f;color:#fff;">
        <h5 class="modal-title"><i class="fas fa-credit-card me-2"></i>Add Credit Card</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="add_card">
        <div class="modal-body">
          <?= card_form_fields() ?>
        </div>
        <div class="modal-footer py-2">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-plus me-1"></i>Add Card</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ── Edit Card Modal ────────────────────────────────────────────── -->
<div class="modal fade" id="editCardModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header" style="background:#1e3a5f;color:#fff;">
        <h5 class="modal-title"><i class="fas fa-pen me-2"></i>Edit Credit Card</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="edit_card">
        <input type="hidden" name="card_id" id="edit_card_id">
        <div class="modal-body">
          <?= card_form_fields('edit_') ?>
        </div>
        <div class="modal-footer py-2">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-save me-1"></i>Save Changes</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ── Generate Bill Modal ────────────────────────────────────────── -->
<div class="modal fade" id="billModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header text-white" style="background:#7c3aed;">
        <h5 class="modal-title"><i class="fas fa-file-invoice-dollar me-2"></i>Record Monthly Bill</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="generate_bill">
        <input type="hidden" name="card_id" id="bill_card_id">
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label fw-semibold">Card</label>
            <input type="text" class="form-control" id="bill_card_name" readonly>
          </div>
          <div class="row g-3">
            <div class="col-6">
              <label class="form-label fw-semibold">Bill Month</label>
              <input type="month" class="form-control" name="bill_month" id="bill_month" value="<?= date('Y-m') ?>" required>
            </div>
            <div class="col-6">
              <label class="form-label fw-semibold">Bill Amount <span class="text-danger">*</span></label>
              <div class="input-group">
                <span class="input-group-text">₹</span>
                <input type="number" class="form-control" name="bill_amount" id="bill_amount" min="0" step="0.01" required>
              </div>
            </div>
            <div class="col-6">
              <label class="form-label fw-semibold">Minimum Due</label>
              <div class="input-group">
                <span class="input-group-text">₹</span>
                <input type="number" class="form-control" name="min_due" min="0" step="0.01" value="0">
              </div>
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Notes</label>
              <input type="text" class="form-control form-control-sm" name="notes" placeholder="Optional note">
            </div>
          </div>
        </div>
        <div class="modal-footer py-2">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-purple btn-sm" style="background:#7c3aed;color:#fff;border:none;">
            <i class="fas fa-save me-1"></i>Save Bill
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ── Pay Bill Modal ─────────────────────────────────────────────── -->
<div class="modal fade" id="payBillModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header text-white" style="background:#10b981;">
        <h5 class="modal-title"><i class="fas fa-circle-check me-2"></i>Record Payment</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="pay_bill">
        <input type="hidden" name="bill_id" id="pay_bill_id">
        <div class="modal-body">
          <table class="table table-sm table-borderless mb-3">
            <tr><td class="text-muted" style="width:40%">Card</td><td class="fw-bold" id="pay_card_name">—</td></tr>
            <tr><td class="text-muted">Total Bill</td><td class="fw-bold" id="pay_bill_total">—</td></tr>
            <tr><td class="text-muted">Already Paid</td><td id="pay_already_paid">—</td></tr>
            <tr><td class="text-muted">Balance Due</td><td class="fw-bold text-danger" id="pay_balance_due">—</td></tr>
          </table>
          <div class="row g-3">
            <div class="col-7">
              <label class="form-label fw-semibold">Paying Now <span class="text-danger">*</span></label>
              <div class="input-group">
                <span class="input-group-text">₹</span>
                <input type="number" class="form-control" name="paid_amount" id="pay_amount" min="1" step="0.01" required>
              </div>
            </div>
            <div class="col-5">
              <label class="form-label fw-semibold">Payment Date</label>
              <input type="date" class="form-control" name="paid_date" value="<?= $today ?>">
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Payment Mode</label>
              <select class="form-select" name="payment_mode">
                <option value="upi">UPI</option>
                <option value="netbanking">Net Banking</option>
                <option value="neft">NEFT / RTGS</option>
                <option value="auto-debit">Auto Debit</option>
                <option value="cheque">Cheque</option>
                <option value="cash">Cash</option>
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer py-2">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success btn-sm"><i class="fas fa-check me-1"></i>Confirm Payment</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ── Delete confirm ─────────────────────────────────────────────── -->
<form method="POST" id="deleteCardForm">
  <input type="hidden" name="action" value="delete_card">
  <input type="hidden" name="card_id" id="delete_card_id">
</form>

<?php
function card_form_fields(string $p = ''): string {
    $colors = ['#1e3a5f','#7c3aed','#0f766e','#b91c1c','#0369a1','#1d4ed8','#374151','#065f46'];
    $html  = '<div class="row g-3">';
    $html .= '<div class="col-md-6"><label class="form-label fw-semibold">Card Holder Name <span class="text-danger">*</span></label>'
           . '<input type="text" class="form-control" name="card_holder" id="'.$p.'card_holder" placeholder="As on card" required></div>';
    $html .= '<div class="col-md-6"><label class="form-label fw-semibold">Bank / Issuer <span class="text-danger">*</span></label>'
           . '<input type="text" class="form-control" name="bank_name" id="'.$p.'bank_name" placeholder="e.g. HDFC Bank" required></div>';
    $html .= '<div class="col-md-6"><label class="form-label fw-semibold">Card Name <span class="text-danger">*</span></label>'
           . '<input type="text" class="form-control" name="card_name" id="'.$p.'card_name" placeholder="e.g. Millennia, Sapphire" required></div>';
    $html .= '<div class="col-md-3"><label class="form-label fw-semibold">Last 4 Digits <span class="text-danger">*</span></label>'
           . '<input type="text" class="form-control" name="card_last4" id="'.$p.'card_last4" maxlength="4" placeholder="1234" required></div>';
    $html .= '<div class="col-md-3"><label class="form-label fw-semibold">Network</label>'
           . '<select class="form-select" name="card_network" id="'.$p.'card_network">'
           . '<option value="visa">Visa</option><option value="mastercard">Mastercard</option>'
           . '<option value="rupay">RuPay</option><option value="amex">Amex</option></select></div>';
    $html .= '<div class="col-md-4"><label class="form-label fw-semibold">Credit Limit <span class="text-danger">*</span></label>'
           . '<div class="input-group"><span class="input-group-text">₹</span>'
           . '<input type="number" class="form-control" name="credit_limit" id="'.$p.'credit_limit" min="0" step="1000" required></div></div>';
    $html .= '<div class="col-md-4"><label class="form-label fw-semibold">Current Balance / Outstanding</label>'
           . '<div class="input-group"><span class="input-group-text">₹</span>'
           . '<input type="number" class="form-control" name="current_balance" id="'.$p.'current_balance" min="0" step="0.01" value="0"></div></div>';
    $html .= '<div class="col-md-4"><label class="form-label fw-semibold">Interest Rate (% p.m.)</label>'
           . '<div class="input-group"><input type="number" class="form-control" name="interest_rate" id="'.$p.'interest_rate" min="0" max="10" step="0.01" value="3.5">'
           . '<span class="input-group-text">%</span></div></div>';
    $html .= '<div class="col-md-4"><label class="form-label fw-semibold">Statement Day</label>'
           . '<div class="input-group"><input type="number" class="form-control" name="statement_date" id="'.$p.'statement_date" min="1" max="28" value="1">'
           . '<span class="input-group-text">of month</span></div></div>';
    $html .= '<div class="col-md-4"><label class="form-label fw-semibold">Due Days After Statement</label>'
           . '<div class="input-group"><input type="number" class="form-control" name="payment_due_days" id="'.$p.'payment_due_days" min="1" max="45" value="20">'
           . '<span class="input-group-text">days</span></div></div>';
    $html .= '<div class="col-md-4"><label class="form-label fw-semibold">Card Status</label>'
           . '<select class="form-select" name="card_status" id="'.$p.'card_status">'
           . '<option value="active">Active</option><option value="inactive">Inactive</option><option value="blocked">Blocked</option></select></div>';
    // Color picker
    $html .= '<div class="col-12"><label class="form-label fw-semibold">Card Color</label><div class="d-flex gap-2 flex-wrap">';
    foreach ($colors as $clr)
        $html .= '<label class="color-swatch" style="cursor:pointer;">'
               . '<input type="radio" name="card_color" value="'.$clr.'" '.($clr==='#1e3a5f'?'checked':'').' style="display:none;" id="'.$p.'color_'.$clr.'">'
               . '<div class="rounded" style="width:32px;height:32px;background:'.$clr.';border:3px solid transparent;transition:border .15s;" '
               . 'onclick="selectColor(this,\''.$p.'\')"></div></label>';
    $html .= '</div></div>';
    $html .= '<div class="col-12"><label class="form-label fw-semibold">Notes</label>'
           . '<textarea class="form-control form-control-sm" name="notes" id="'.$p.'notes" rows="2"></textarea></div>';
    $html .= '</div>';
    return $html;
}
?>

<?php
$extra_js = <<<'JS'
<script>
const fmtINR = n => '₹' + Math.round(n).toLocaleString('en-IN');

function editCard(c) {
  const p = 'edit_';
  document.getElementById('edit_card_id').value       = c.id;
  document.getElementById(p+'card_holder').value      = c.card_holder;
  document.getElementById(p+'bank_name').value        = c.bank_name;
  document.getElementById(p+'card_name').value        = c.card_name;
  document.getElementById(p+'card_last4').value       = c.card_last4;
  document.getElementById(p+'card_network').value     = c.card_network;
  document.getElementById(p+'credit_limit').value     = c.credit_limit;
  document.getElementById(p+'current_balance').value  = c.current_balance;
  document.getElementById(p+'interest_rate').value    = c.interest_rate;
  document.getElementById(p+'statement_date').value   = c.statement_date;
  document.getElementById(p+'payment_due_days').value = c.payment_due_days;
  document.getElementById(p+'card_status').value      = c.status;
  document.getElementById(p+'notes').value            = c.notes || '';
  // color
  document.querySelectorAll('#editCardModal .color-swatch div').forEach(d => {
    d.style.borderColor = 'transparent';
    const inp = d.closest('label').querySelector('input');
    if (inp.value === c.card_color) { d.style.borderColor = '#fff'; inp.checked = true; }
  });
  new bootstrap.Modal(document.getElementById('editCardModal')).show();
}

function selectColor(el, prefix) {
  const container = el.closest('.d-flex');
  container.querySelectorAll('div').forEach(d => d.style.borderColor = 'transparent');
  el.style.borderColor = '#fff';
}

function openBillModal(cid, cname, balance) {
  document.getElementById('bill_card_id').value  = cid;
  document.getElementById('bill_card_name').value = cname;
  document.getElementById('bill_amount').value   = balance > 0 ? balance : '';
  new bootstrap.Modal(document.getElementById('billModal')).show();
}

function openPayBillModal(bid, cname, total, paid) {
  document.getElementById('pay_bill_id').value     = bid;
  document.getElementById('pay_card_name').textContent  = cname;
  document.getElementById('pay_bill_total').textContent = fmtINR(total);
  document.getElementById('pay_already_paid').textContent = fmtINR(paid);
  const bal = Math.max(0, total - paid);
  document.getElementById('pay_balance_due').textContent = fmtINR(bal);
  document.getElementById('pay_amount').value = bal > 0 ? bal : total;
  new bootstrap.Modal(document.getElementById('payBillModal')).show();
}

function deleteCard(cid, name) {
  if (!confirm(`Delete card "${name}" and all its bills? This cannot be undone.`)) return;
  document.getElementById('delete_card_id').value = cid;
  document.getElementById('deleteCardForm').submit();
}

function filterBills(status) {
  document.querySelectorAll('#billsTable tbody tr').forEach(tr => {
    tr.style.display = (status === 'all' || tr.dataset.status === status) ? '' : 'none';
  });
}

// Highlight selected color swatch on load
document.querySelectorAll('.color-swatch input[type=radio]:checked').forEach(inp => {
  inp.closest('label').querySelector('div').style.borderColor = '#fff';
});
</script>
JS;
require_once 'includes/footer.php';
