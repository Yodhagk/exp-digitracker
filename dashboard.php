<?php
require_once 'includes/auth.php';
require_once 'config.php';
$page_title = 'Dashboard';
$uid = $_SESSION['id'];

// ── Summary stats ──────────────────────────────────────────
$today = date('Y-m-d');

// Auto-mark: past-due EMIs → paid; regular expenses → overdue
mysqli_query($conn, "UPDATE expenses SET status='paid'
    WHERE user_id=$uid AND auto_generated=1 AND status IN('pending','overdue') AND due_date < '$today'");
mysqli_query($conn, "UPDATE expenses SET status='overdue'
    WHERE user_id=$uid AND auto_generated=0 AND status='pending' AND due_date < '$today'");
// Auto-mark overdue loans
mysqli_query($conn, "UPDATE loans SET status='overdue'
    WHERE user_id=$uid AND status='active' AND due_date < '$today'");

// Active + overdue loans (all non-paid) & total remaining
$r = mysqli_query($conn, "SELECT COUNT(*) c, COALESCE(SUM(remaining_amount),0) amt FROM loans WHERE user_id=$uid AND status IN('active','overdue')");
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

// ── Monthly expense chart data ────────────────────────────
$chart_year = max(2000, min(2100, (int)($_GET['year'] ?? date('Y'))));
$months_exp = array_fill(0, 12, 0);
$r = mysqli_query($conn, "SELECT MONTH(due_date) m, COALESCE(SUM(amount),0) total FROM expenses WHERE user_id=$uid AND YEAR(due_date)=$chart_year GROUP BY MONTH(due_date)");
while ($row = mysqli_fetch_assoc($r)) $months_exp[(int)$row['m'] - 1] = (float)$row['total'];
$year_total = array_sum($months_exp);

// Loan EMI progress for active/overdue loans
$emi_loans = [];
$r = mysqli_query($conn, "
    SELECT l.id, l.name, l.lender, l.monthly_payment, l.tenure_months,
           l.status, l.remaining_amount,
           COUNT(e.id)                                                       AS emi_total,
           SUM(CASE WHEN e.status='paid'    THEN 1 ELSE 0 END)             AS emi_paid,
           SUM(CASE WHEN e.status='pending' THEN 1 ELSE 0 END)             AS emi_pending,
           SUM(CASE WHEN e.status='paid'    THEN e.amount ELSE 0 END)      AS emi_paid_amt,
           SUM(CASE WHEN e.status='pending' THEN e.amount ELSE 0 END)      AS emi_pending_amt,
           MIN(CASE WHEN e.status='pending' THEN e.due_date ELSE NULL END) AS next_emi_due
    FROM loans l
    LEFT JOIN expenses e ON e.loan_ref_id=l.id AND e.auto_generated=1 AND e.user_id=l.user_id
    WHERE l.user_id=$uid AND l.status IN('active','overdue')
    GROUP BY l.id, l.name, l.lender, l.monthly_payment, l.tenure_months,
             l.status, l.remaining_amount
    ORDER BY l.status='overdue' DESC, l.id ASC
");
while ($row = mysqli_fetch_assoc($r)) $emi_loans[] = $row;

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

<!-- ── Loan EMI Status ── -->
<?php if (!empty($emi_loans)): ?>
<div class="row g-3 mt-1">
  <div class="col-12">
    <div class="card">
      <div class="card-header">
        <h6 class="card-title">
          <i class="fas fa-rotate me-2" style="color:#6f42c1"></i>Loan EMI Status
        </h6>
        <a href="loans.php" class="btn btn-sm btn-outline-secondary">View All Loans</a>
      </div>
      <div class="card-body p-0">
        <div class="table-wrapper">
          <table class="table mb-0">
            <thead>
              <tr>
                <th>Loan</th>
                <th>Lender</th>
                <th>Monthly EMI</th>
                <th style="min-width:130px">EMI Progress</th>
                <th>EMI Closed</th>
                <th>EMI Pending</th>
                <th>Next Due</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($emi_loans as $el):
                $el_total    = (int)$el['emi_total'];
                $el_paid     = (int)$el['emi_paid'];
                $el_pending  = (int)$el['emi_pending'];
                $el_paid_amt = (float)$el['emi_paid_amt'];
                $el_pend_amt = (float)$el['emi_pending_amt'];
                $el_pct      = $el_total > 0 ? round(($el_paid / $el_total) * 100) : 0;
                $next_due    = $el['next_emi_due'];
                $next_days   = $next_due ? days_until($next_due) : null;
              ?>
              <tr>
                <td class="fw-semibold"><?= htmlspecialchars($el['name']) ?></td>
                <td class="text-muted"><?= htmlspecialchars($el['lender'] ?? '—') ?></td>
                <td class="fw-bold">₹<?= number_format((float)$el['monthly_payment'], 0) ?></td>
                <td>
                  <?php if ($el_total > 0): ?>
                    <div class="d-flex align-items-center gap-2">
                      <div class="progress flex-grow-1" style="height:7px;border-radius:4px;min-width:70px">
                        <div class="progress-bar bg-success" style="width:<?= $el_pct ?>%"></div>
                      </div>
                      <span style="font-size:.78rem;color:#555;white-space:nowrap"><?= $el_paid ?>/<?= $el_total ?></span>
                    </div>
                    <div style="font-size:.72rem;color:#888;margin-top:2px"><?= $el_pct ?>% complete</div>
                  <?php else: ?>
                    <span class="text-muted" style="font-size:.8rem">No schedule set</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($el_paid > 0): ?>
                    <span class="fw-semibold text-success"><?= $el_paid ?> EMI</span>
                    <div class="text-muted" style="font-size:.73rem">₹<?= number_format($el_paid_amt, 0) ?></div>
                  <?php else: ?>
                    <span class="text-muted">—</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($el_pending > 0): ?>
                    <span class="fw-semibold" style="color:#856404"><?= $el_pending ?> EMI</span>
                    <div class="text-muted" style="font-size:.73rem">₹<?= number_format($el_pend_amt, 0) ?></div>
                  <?php elseif ($el_total > 0): ?>
                    <span class="badge bg-success" style="font-size:.75rem">All Closed!</span>
                  <?php else: ?>
                    <span class="text-muted">—</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($next_due): ?>
                    <div><?= date('d M Y', strtotime($next_due)) ?></div>
                    <?= due_badge($next_days) ?>
                  <?php else: ?>
                    <span class="text-muted">—</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php
                    $s_map = ['active' => 'badge-active', 'overdue' => 'badge-overdue'];
                    echo '<span class="badge-status '.($s_map[$el['status']] ?? '').'">'.$el['status'].'</span>';
                  ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ── Monthly Expense Chart ── -->
<div class="row g-3 mt-1">
  <div class="col-12">
    <div class="card">
      <div class="card-header">
        <h6 class="card-title"><i class="fas fa-chart-bar me-2 text-success"></i>Monthly Expenses — <?= $chart_year ?></h6>
        <form method="GET" class="d-flex gap-2 align-items-center">
          <select name="year" class="form-control form-control-sm" style="width:auto;" onchange="this.form.submit()">
            <?php for ($y = date('Y') - 3; $y <= date('Y') + 1; $y++): ?>
              <option value="<?= $y ?>" <?= $y === $chart_year ? 'selected' : '' ?>><?= $y ?></option>
            <?php endfor; ?>
          </select>
          <span class="text-muted" style="font-size:.85rem;">Total: ₹<?= number_format($year_total, 0) ?></span>
        </form>
      </div>
      <div class="card-body">
        <canvas id="expenseChart" height="90"></canvas>
        <?php if ($year_total == 0): ?>
          <div class="text-center text-muted mt-3" style="font-size:.9rem;">No expense data for <?= $chart_year ?>.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php
$months_json = json_encode(array_values($months_exp));
$extra_js = <<<JS
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function(){
  const data = {$months_json};
  const labels = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
  const ctx = document.getElementById('expenseChart');
  if (!ctx) return;
  new Chart(ctx, {
    type: 'bar',
    data: {
      labels: labels,
      datasets: [{
        label: 'Expenses (₹)',
        data: data,
        backgroundColor: 'rgba(59,130,246,.25)',
        borderColor: '#3b82f6',
        borderWidth: 2,
        borderRadius: 6,
        hoverBackgroundColor: 'rgba(59,130,246,.45)'
      }]
    },
    options: {
      responsive: true,
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            label: ctx => '₹' + ctx.parsed.y.toLocaleString('en-IN')
          }
        }
      },
      scales: {
        y: {
          beginAtZero: true,
          ticks: {
            callback: v => '₹' + (v >= 1000 ? (v/1000).toFixed(0)+'k' : v)
          }
        }
      }
    }
  });
})();
</script>
JS;
require_once 'includes/footer.php';
?>
