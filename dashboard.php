<?php
require_once 'includes/auth.php';
require_once 'config.php';
$page_title = 'Dashboard';
$uid = $_SESSION['id'];

// ── Summary stats ──────────────────────────────────────────
$today = date('Y-m-d');

// Total active loans & remaining amount
$r = mysqli_query($conn, "SELECT COUNT(*) c, COALESCE(SUM(remaining_amount),0) amt FROM loans WHERE user_id=$uid AND status='active'");
$loans_row = mysqli_fetch_assoc($r);

// Pending expenses due in next 30 days
$r = mysqli_query($conn, "SELECT COUNT(*) c, COALESCE(SUM(amount),0) amt FROM expenses WHERE user_id=$uid AND status='pending' AND due_date BETWEEN '$today' AND DATE_ADD('$today', INTERVAL 30 DAY)");
$exp_row = mysqli_fetch_assoc($r);

// Unpaid invoices
$r = mysqli_query($conn, "SELECT COUNT(*) c, COALESCE(SUM(amount),0) amt FROM invoices WHERE user_id=$uid AND status='unpaid'");
$inv_row = mysqli_fetch_assoc($r);

// Expiring warranties (within 90 days)
$r = mysqli_query($conn, "SELECT COUNT(*) c FROM warranties WHERE user_id=$uid AND warranty_expiry BETWEEN '$today' AND DATE_ADD('$today', INTERVAL 90 DAY)");
$war_row = mysqli_fetch_assoc($r);

// Overdue count (all modules)
$r = mysqli_query($conn, "SELECT
  (SELECT COUNT(*) FROM loans WHERE user_id=$uid AND status='overdue') +
  (SELECT COUNT(*) FROM expenses WHERE user_id=$uid AND status='overdue') +
  (SELECT COUNT(*) FROM invoices WHERE user_id=$uid AND status='overdue') AS total");
$overdue_row = mysqli_fetch_assoc($r);

// ── Upcoming dues (next 30 days, all modules) ─────────────
$upcoming = [];

$r = mysqli_query($conn, "SELECT 'Loan' type, name, due_date, monthly_payment amount, status FROM loans WHERE user_id=$uid AND status='active' AND due_date <= DATE_ADD('$today', INTERVAL 30 DAY) ORDER BY due_date LIMIT 5");
while ($row = mysqli_fetch_assoc($r)) $upcoming[] = $row;

$r = mysqli_query($conn, "SELECT 'Expense' type, name, due_date, amount, status FROM expenses WHERE user_id=$uid AND status='pending' AND due_date <= DATE_ADD('$today', INTERVAL 30 DAY) ORDER BY due_date LIMIT 5");
while ($row = mysqli_fetch_assoc($r)) $upcoming[] = $row;

$r = mysqli_query($conn, "SELECT 'Invoice' type, vendor_name name, due_date, amount, status FROM invoices WHERE user_id=$uid AND status='unpaid' AND due_date <= DATE_ADD('$today', INTERVAL 30 DAY) ORDER BY due_date LIMIT 5");
while ($row = mysqli_fetch_assoc($r)) $upcoming[] = $row;

usort($upcoming, fn($a,$b) => strcmp($a['due_date'], $b['due_date']));

// ── Expiring warranties ───────────────────────────────────
$warr_list = [];
$r = mysqli_query($conn, "SELECT product_name, brand, warranty_expiry FROM warranties WHERE user_id=$uid AND warranty_expiry >= '$today' ORDER BY warranty_expiry LIMIT 5");
while ($row = mysqli_fetch_assoc($r)) $warr_list[] = $row;

require_once 'includes/header.php';

function days_until($date) {
    return (int) ceil((strtotime($date) - strtotime(date('Y-m-d'))) / 86400);
}
function due_badge($days, $status='') {
    if ($status === 'overdue' || $days < 0) return '<span class="badge-status badge-overdue"><i class="fas fa-circle-exclamation"></i>Overdue</span>';
    if ($days === 0) return '<span class="badge-status badge-overdue"><i class="fas fa-clock"></i>Today</span>';
    if ($days <= 7) return '<span class="badge-status badge-pending"><i class="fas fa-clock"></i>'.$days.'d</span>';
    return '<span class="badge-status badge-active"><i class="fas fa-circle-check"></i>'.$days.'d</span>';
}
?>

<!-- ── Stat cards ── -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon blue"><i class="fas fa-hand-holding-dollar"></i></div>
      <div class="stat-info">
        <div class="stat-label">Active Loans</div>
        <div class="stat-value"><?= $loans_row['c'] ?></div>
        <div class="stat-sub">₹<?= number_format($loans_row['amt'],0) ?> remaining</div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon green"><i class="fas fa-receipt"></i></div>
      <div class="stat-info">
        <div class="stat-label">Expenses Due (30d)</div>
        <div class="stat-value"><?= $exp_row['c'] ?></div>
        <div class="stat-sub">₹<?= number_format($exp_row['amt'],0) ?> upcoming</div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon orange"><i class="fas fa-file-invoice-dollar"></i></div>
      <div class="stat-info">
        <div class="stat-label">Unpaid Invoices</div>
        <div class="stat-value"><?= $inv_row['c'] ?></div>
        <div class="stat-sub">₹<?= number_format($inv_row['amt'],0) ?> outstanding</div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon <?= $overdue_row['total'] > 0 ? 'red' : 'purple' ?>">
        <i class="fas fa-<?= $overdue_row['total'] > 0 ? 'circle-exclamation' : 'shield-halved' ?>"></i>
      </div>
      <div class="stat-info">
        <div class="stat-label"><?= $overdue_row['total'] > 0 ? 'Overdue Items' : 'Warranties Expiring' ?></div>
        <div class="stat-value"><?= $overdue_row['total'] > 0 ? $overdue_row['total'] : $war_row['c'] ?></div>
        <div class="stat-sub"><?= $overdue_row['total'] > 0 ? 'need attention' : 'within 90 days' ?></div>
      </div>
    </div>
  </div>
</div>

<!-- ── Two-column section ── -->
<div class="row g-3">

  <!-- Upcoming payments -->
  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-header">
        <h6 class="card-title"><i class="fas fa-calendar-alt me-2 text-primary"></i>Upcoming Payments (30 days)</h6>
        <a href="upcoming.php" class="btn btn-sm btn-outline-primary">View all</a>
      </div>
      <div class="card-body p-0">
        <?php if (empty($upcoming)): ?>
          <div class="text-center py-5 text-muted">
            <i class="fas fa-check-circle fa-2x mb-2 d-block text-success"></i>
            No payments due in the next 30 days!
          </div>
        <?php else: ?>
          <div class="table-wrapper">
            <table class="table mb-0">
              <thead>
                <tr>
                  <th>Name</th><th>Type</th><th>Due Date</th><th>Amount</th><th>Status</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($upcoming as $u):
                  $days = days_until($u['due_date']);
                ?>
                <tr>
                  <td class="fw-semibold"><?= htmlspecialchars($u['name']) ?></td>
                  <td><span class="badge bg-secondary bg-opacity-10 text-secondary" style="font-size:.75rem;"><?= $u['type'] ?></span></td>
                  <td><?= date('d M Y', strtotime($u['due_date'])) ?></td>
                  <td class="fw-bold">₹<?= number_format($u['amount'],0) ?></td>
                  <td><?= due_badge($days, $u['status']) ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Expiring warranties -->
  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header">
        <h6 class="card-title"><i class="fas fa-shield-halved me-2 text-purple" style="color:#8b5cf6;"></i>Warranty Tracker</h6>
        <a href="warranties.php" class="btn btn-sm btn-outline-secondary">Manage</a>
      </div>
      <div class="card-body">
        <?php if (empty($warr_list)): ?>
          <div class="text-center py-4 text-muted">
            <i class="fas fa-shield-halved fa-2x mb-2 d-block" style="color:#e2e8f0;"></i>
            No warranties added yet.
          </div>
        <?php else: ?>
          <?php foreach ($warr_list as $w):
            $days = days_until($w['warranty_expiry']);
            if ($days < 0) { $cls = 'badge-expired'; $label = 'Expired'; }
            elseif ($days <= 30) { $cls = 'badge-expiring'; $label = $days.'d left'; }
            else { $cls = 'badge-valid'; $label = $days.'d left'; }
          ?>
          <div class="upcoming-item">
            <div class="upcoming-dot <?= $days <= 30 ? 'overdue' : 'month' ?>"></div>
            <div class="flex-grow-1">
              <div class="upcoming-name"><?= htmlspecialchars($w['product_name']) ?></div>
              <div class="upcoming-meta"><?= htmlspecialchars($w['brand'] ?? '') ?> · Expires <?= date('d M Y', strtotime($w['warranty_expiry'])) ?></div>
            </div>
            <span class="badge-status <?= $cls ?>"><?= $label ?></span>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

</div>

<!-- ── Quick links ── -->
<div class="row g-3 mt-1">
  <div class="col-12">
    <div class="card">
      <div class="card-body d-flex flex-wrap gap-2">
        <a href="loans.php" class="btn btn-outline-primary btn-sm"><i class="fas fa-plus me-1"></i>Add Loan</a>
        <a href="expenses.php" class="btn btn-outline-success btn-sm"><i class="fas fa-plus me-1"></i>Add Expense</a>
        <a href="invoices.php" class="btn btn-outline-warning btn-sm"><i class="fas fa-plus me-1"></i>Add Invoice</a>
        <a href="warranties.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-plus me-1"></i>Add Warranty</a>
        <a href="upcoming.php" class="btn btn-primary btn-sm ms-auto"><i class="fas fa-calendar me-1"></i>View Upcoming</a>
      </div>
    </div>
  </div>
</div>

<?php require_once 'includes/footer.php'; ?>
