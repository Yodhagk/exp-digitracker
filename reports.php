<?php
require_once 'includes/auth.php';
require_once 'config.php';
$page_title = 'Reports';
$uid   = (int)$_SESSION['id'];
$today = date('Y-m-d');

// ── Year selector ──────────────────────────────────────────────
$current_year = (int)date('Y');
$year = max(2000, min(2100, (int)($_GET['year'] ?? $current_year)));

// Auto-mark overdue before running any report queries
mysqli_query($conn, "UPDATE expenses SET status='overdue'
    WHERE user_id=$uid AND status='pending' AND due_date < '$today'");

// ── Yearly totals ──────────────────────────────────────────────
$r = mysqli_query($conn, "
    SELECT
        COALESCE(SUM(amount), 0)                                              AS total_amount,
        COALESCE(SUM(CASE WHEN status='paid'    THEN amount ELSE 0 END), 0)  AS paid_amount,
        COALESCE(SUM(CASE WHEN status='overdue' THEN amount ELSE 0 END), 0)  AS overdue_amount,
        COALESCE(SUM(CASE WHEN status='pending' THEN amount ELSE 0 END), 0)  AS pending_amount,
        COUNT(*)                                                              AS total_count,
        SUM(CASE WHEN status='paid'    THEN 1 ELSE 0 END)                   AS paid_count,
        SUM(CASE WHEN status='overdue' THEN 1 ELSE 0 END)                   AS overdue_count,
        SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END)                   AS pending_count
    FROM expenses
    WHERE user_id=$uid AND YEAR(due_date)=$year
");
$yearly = mysqli_fetch_assoc($r);
$yearly['total_amount']   = (float)$yearly['total_amount'];
$yearly['paid_amount']    = (float)$yearly['paid_amount'];
$yearly['overdue_amount'] = (float)$yearly['overdue_amount'];
$yearly['pending_amount'] = (float)$yearly['pending_amount'];
$unpaid_amount = $yearly['pending_amount'] + $yearly['overdue_amount'];
$paid_pct = $yearly['total_amount'] > 0
    ? round(($yearly['paid_amount'] / $yearly['total_amount']) * 100, 1) : 0;
$over_pct = $yearly['total_amount'] > 0
    ? round(($yearly['overdue_amount'] / $yearly['total_amount']) * 100, 1) : 0;

// ── Monthly breakdown ──────────────────────────────────────────
$r = mysqli_query($conn, "
    SELECT
        MONTH(due_date)                                                        AS month_num,
        MONTHNAME(due_date)                                                    AS month_name,
        COALESCE(SUM(amount), 0)                                              AS total_amount,
        COALESCE(SUM(CASE WHEN status='paid'    THEN amount ELSE 0 END), 0)  AS paid_amount,
        COALESCE(SUM(CASE WHEN status='overdue' THEN amount ELSE 0 END), 0)  AS overdue_amount,
        COALESCE(SUM(CASE WHEN status='pending' THEN amount ELSE 0 END), 0)  AS pending_amount,
        COUNT(*)                                                              AS total_count,
        SUM(CASE WHEN status='paid'              THEN 1 ELSE 0 END)          AS paid_count,
        SUM(CASE WHEN status IN('pending','overdue') THEN 1 ELSE 0 END)      AS unpaid_count
    FROM expenses
    WHERE user_id=$uid AND YEAR(due_date)=$year
    GROUP BY MONTH(due_date), MONTHNAME(due_date)
    ORDER BY month_num
");
$monthly_rows = [];
while ($row = mysqli_fetch_assoc($r)) $monthly_rows[] = $row;

// ── Category breakdown ─────────────────────────────────────────
$r = mysqli_query($conn, "
    SELECT
        category,
        COALESCE(SUM(amount), 0)                                             AS total_amount,
        COALESCE(SUM(CASE WHEN status='paid' THEN amount ELSE 0 END), 0)    AS paid_amount,
        COUNT(*)                                                             AS count
    FROM expenses
    WHERE user_id=$uid AND YEAR(due_date)=$year
    GROUP BY category
    ORDER BY total_amount DESC
");
$cat_rows = [];
while ($row = mysqli_fetch_assoc($r)) $cat_rows[] = $row;

// ── Payment mode breakdown ─────────────────────────────────────
$r = mysqli_query($conn, "
    SELECT
        payment_mode,
        COALESCE(SUM(amount), 0)                                             AS total_amount,
        COALESCE(SUM(CASE WHEN status='paid' THEN amount ELSE 0 END), 0)    AS paid_amount,
        COUNT(*)                                                             AS count
    FROM expenses
    WHERE user_id=$uid AND YEAR(due_date)=$year
    GROUP BY payment_mode
    ORDER BY total_amount DESC
");
$pay_mode_rows = [];
while ($row = mysqli_fetch_assoc($r)) $pay_mode_rows[$row['payment_mode']] = $row;

// ── Per-card breakdown ─────────────────────────────────────────
$r = mysqli_query($conn, "
    SELECT
        COALESCE(card_last4, 'Unknown') AS card_num,
        COALESCE(SUM(amount), 0)                                                        AS total_amount,
        COALESCE(SUM(CASE WHEN status='paid' THEN amount ELSE 0 END), 0)               AS paid_amount,
        COALESCE(SUM(CASE WHEN status IN('pending','overdue') THEN amount ELSE 0 END), 0) AS unpaid_amount,
        COUNT(*)                                                                         AS count
    FROM expenses
    WHERE user_id=$uid AND YEAR(due_date)=$year AND payment_mode='card'
    GROUP BY card_last4
    ORDER BY total_amount DESC
");
$card_rows = [];
while ($row = mysqli_fetch_assoc($r)) $card_rows[] = $row;

$card_total   = (float)($pay_mode_rows['card']['total_amount'] ?? 0);
$card_paid    = (float)($pay_mode_rows['card']['paid_amount']  ?? 0);
$card_count   = (int)  ($pay_mode_rows['card']['count']        ?? 0);
$non_card_total = $yearly['total_amount'] - $card_total;
$non_card_paid  = $yearly['paid_amount']  - $card_paid;
$non_card_count = (int)$yearly['total_count'] - $card_count;

// ── All expense detail rows keyed by month_num ────────────────
$detail = [];
$r = mysqli_query($conn, "
    SELECT *, MONTH(due_date) AS month_num
    FROM expenses
    WHERE user_id=$uid AND YEAR(due_date)=$year
    ORDER BY due_date ASC, name ASC
");
while ($row = mysqli_fetch_assoc($r)) {
    $detail[(int)$row['month_num']][] = $row;
}

// ── Chart data – all 12 months ─────────────────────────────────
$month_map   = array_column($monthly_rows, null, 'month_num');
$ch_labels   = $ch_paid = $ch_pending = $ch_overdue = [];
for ($m = 1; $m <= 12; $m++) {
    $ch_labels[]   = date('M', mktime(0, 0, 0, $m, 1, $year));
    $ch_paid[]     = isset($month_map[$m]) ? (float)$month_map[$m]['paid_amount']    : 0;
    $ch_pending[]  = isset($month_map[$m]) ? (float)$month_map[$m]['pending_amount'] : 0;
    $ch_overdue[]  = isset($month_map[$m]) ? (float)$month_map[$m]['overdue_amount'] : 0;
}
$ch_labels_json  = json_encode($ch_labels);
$ch_paid_json    = json_encode($ch_paid);
$ch_pending_json = json_encode($ch_pending);
$ch_overdue_json = json_encode($ch_overdue);

$cat_labels_json = json_encode(array_map(fn($c) => ucfirst((string)($c['category']??'other')), $cat_rows));
$cat_totals_json = json_encode(array_map(fn($c) => (float)$c['total_amount'], $cat_rows));
$cat_colors_json = json_encode([
    '#4e73df','#1cc88a','#36b9cc','#f6c23e','#e74a3b',
    '#858796','#5a5c69','#fd7e14','#6f42c1','#20c997','#0dcaf0','#d63384',
]);

// Payment mode doughnut chart
$pm_label_map = ['cash'=>'Cash','bank_transfer'=>'Bank Transfer','card'=>'Card','upi'=>'UPI'];
$pm_color_map = ['cash'=>'#1cc88a','bank_transfer'=>'#4e73df','card'=>'#e74a3b','upi'=>'#f6c23e'];
$pm_labels_json = json_encode(array_map(fn($m) => $pm_label_map[$m] ?? ucfirst($m), array_keys($pay_mode_rows)));
$pm_totals_json = json_encode(array_map(fn($p) => (float)$p['total_amount'], array_values($pay_mode_rows)));
$pm_colors_json = json_encode(array_map(fn($m) => $pm_color_map[$m] ?? '#858796', array_keys($pay_mode_rows)));

require_once 'includes/header.php';

function rpt_badge(string $s): string {
    $m = ['pending' => 'badge-pending', 'paid' => 'badge-paid', 'overdue' => 'badge-overdue'];
    return '<span class="badge-status '.($m[$s] ?? '').'">'.$s.'</span>';
}

function rpt_pay_badge(string $mode, ?string $last4 = null): string {
    $icons  = ['cash'=>'fa-money-bill-wave','bank_transfer'=>'fa-building-columns','card'=>'fa-credit-card','upi'=>'fa-mobile-screen-button'];
    $labels = ['cash'=>'Cash','bank_transfer'=>'Bank Transfer','card'=>'Card','upi'=>'UPI'];
    $icon   = $icons[$mode]  ?? 'fa-circle-question';
    $label  = $labels[$mode] ?? ucfirst($mode);
    if ($mode === 'card' && $last4) $label .= ' ····'.$last4;
    return '<span class="badge bg-light text-dark border" style="font-size:.72rem;white-space:nowrap;">'
         . '<i class="fas '.$icon.' me-1"></i>'.$label.'</span>';
}
?>

<!-- ── Page header / year selector ─────────────────────────── -->
<div class="d-flex align-items-center flex-wrap gap-3 mb-4">
  <div>
    <h5 class="mb-0 fw-bold"><i class="fas fa-chart-bar me-2 text-primary"></i>Expense Report</h5>
    <p class="text-muted mb-0" style="font-size:.85rem">Yearly &amp; monthly paid vs outstanding breakdown</p>
  </div>
  <form class="d-flex align-items-center gap-2 ms-auto" method="get">
    <label class="form-label mb-0 text-muted small">Year</label>
    <select name="year" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
      <?php for ($y = $current_year - 4; $y <= $current_year + 1; $y++): ?>
        <option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= $y ?></option>
      <?php endfor; ?>
    </select>
  </form>
</div>

<!-- ── Summary stat cards ───────────────────────────────────── -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon blue"><i class="fas fa-wallet"></i></div>
      <div class="stat-info">
        <div class="stat-label">Total <?= $year ?></div>
        <div class="stat-value">₹<?= number_format($yearly['total_amount'], 0) ?></div>
        <div class="stat-sub"><?= (int)$yearly['total_count'] ?> expenses</div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon green"><i class="fas fa-circle-check"></i></div>
      <div class="stat-info">
        <div class="stat-label">Total Paid</div>
        <div class="stat-value" style="color:#1cc88a">₹<?= number_format($yearly['paid_amount'], 0) ?></div>
        <div class="stat-sub"><?= (int)$yearly['paid_count'] ?> items · <?= $paid_pct ?>% done</div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon orange"><i class="fas fa-clock"></i></div>
      <div class="stat-info">
        <div class="stat-label">Still to Pay</div>
        <div class="stat-value" style="color:#f6c23e">₹<?= number_format($unpaid_amount, 0) ?></div>
        <div class="stat-sub"><?= (int)$yearly['pending_count'] ?> pending · <?= (int)$yearly['overdue_count'] ?> overdue</div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon red"><i class="fas fa-triangle-exclamation"></i></div>
      <div class="stat-info">
        <div class="stat-label">Overdue Amount</div>
        <div class="stat-value" style="color:#e74a3b">₹<?= number_format($yearly['overdue_amount'], 0) ?></div>
        <div class="stat-sub"><?= (int)$yearly['overdue_count'] ?> overdue items</div>
      </div>
    </div>
  </div>
</div>

<!-- ── Annual progress bar ──────────────────────────────────── -->
<?php if ($yearly['total_amount'] > 0): ?>
<div class="card mb-4">
  <div class="card-body py-3">
    <div class="d-flex justify-content-between align-items-center mb-2">
      <span class="fw-semibold" style="font-size:.9rem"><i class="fas fa-gauge-high me-1 text-primary"></i>Annual Payment Progress – <?= $year ?></span>
      <span class="fw-bold text-success"><?= $paid_pct ?>% paid</span>
    </div>
    <div class="progress" style="height:12px;border-radius:8px">
      <div class="progress-bar bg-success" style="width:<?= $paid_pct ?>%" title="Paid <?= $paid_pct ?>%"></div>
      <div class="progress-bar bg-danger"  style="width:<?= $over_pct ?>%" title="Overdue <?= $over_pct ?>%"></div>
    </div>
    <div class="d-flex flex-wrap gap-3 mt-2" style="font-size:.8rem">
      <span><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#1cc88a;margin-right:4px"></span>Paid ₹<?= number_format($yearly['paid_amount'], 0) ?></span>
      <span><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#f6c23e;margin-right:4px"></span>Pending ₹<?= number_format($yearly['pending_amount'], 0) ?></span>
      <span><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#e74a3b;margin-right:4px"></span>Overdue ₹<?= number_format($yearly['overdue_amount'], 0) ?></span>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ── Payment Mode Analysis ────────────────────────────────── -->
<div class="d-flex align-items-center gap-2 mb-3 mt-2">
  <h6 class="mb-0 fw-bold"><i class="fas fa-credit-card me-2 text-danger"></i>Payment Mode Analysis – <?= $year ?></h6>
</div>

<!-- Card vs Non-card summary -->
<div class="row g-3 mb-3">
  <div class="col-md-6">
    <div class="card border-danger h-100" style="border-width:2px!important">
      <div class="card-body">
        <div class="d-flex align-items-center gap-3 mb-3">
          <div style="width:44px;height:44px;border-radius:10px;background:rgba(231,74,59,.12);display:flex;align-items:center;justify-content:center;">
            <i class="fas fa-credit-card text-danger fs-5"></i>
          </div>
          <div>
            <div class="fw-bold fs-5 text-danger">₹<?= number_format($card_total, 0) ?></div>
            <div class="text-muted" style="font-size:.82rem">Total Card Spending — <?= $card_count ?> expenses</div>
          </div>
        </div>
        <?php if (!empty($card_rows)): ?>
          <div class="mb-2" style="font-size:.8rem;font-weight:600;color:#555">
            <i class="fas fa-layer-group me-1"></i>Per Card Breakdown
          </div>
          <table class="table table-sm mb-0" style="font-size:.83rem">
            <thead class="table-light">
              <tr><th>Card</th><th>Total</th><th class="text-success">Paid</th><th class="text-warning">Owed</th><th>Items</th></tr>
            </thead>
            <tbody>
              <?php foreach ($card_rows as $cr):
                $cr_pct = (float)$cr['total_amount'] > 0
                    ? round(((float)$cr['paid_amount'] / (float)$cr['total_amount']) * 100) : 0;
              ?>
              <tr>
                <td>
                  <i class="fas fa-credit-card me-1 text-danger"></i>
                  <strong>····<?= htmlspecialchars((string)$cr['card_num']) ?></strong>
                </td>
                <td class="fw-bold">₹<?= number_format((float)$cr['total_amount'], 0) ?></td>
                <td class="text-success">₹<?= number_format((float)$cr['paid_amount'], 0) ?></td>
                <td class="<?= (float)$cr['unpaid_amount'] > 0 ? 'text-warning fw-semibold' : 'text-muted' ?>">
                  ₹<?= number_format((float)$cr['unpaid_amount'], 0) ?>
                </td>
                <td class="text-muted"><?= (int)$cr['count'] ?></td>
              </tr>
              <tr>
                <td colspan="5" class="p-0 pb-1 border-0">
                  <div class="progress mx-1" style="height:5px;border-radius:3px">
                    <div class="progress-bar bg-success" style="width:<?= $cr_pct ?>%"></div>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
            <?php if (count($card_rows) > 1): ?>
            <tfoot class="table-secondary fw-bold">
              <tr>
                <td>All Cards</td>
                <td>₹<?= number_format($card_total, 0) ?></td>
                <td class="text-success">₹<?= number_format($card_paid, 0) ?></td>
                <td class="text-warning">₹<?= number_format($card_total - $card_paid, 0) ?></td>
                <td><?= $card_count ?></td>
              </tr>
            </tfoot>
            <?php endif; ?>
          </table>
        <?php else: ?>
          <p class="text-muted mb-0" style="font-size:.85rem">No card expenses recorded for <?= $year ?>.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-md-6">
    <div class="card border-success h-100" style="border-width:2px!important">
      <div class="card-body">
        <div class="d-flex align-items-center gap-3 mb-3">
          <div style="width:44px;height:44px;border-radius:10px;background:rgba(28,200,138,.12);display:flex;align-items:center;justify-content:center;">
            <i class="fas fa-money-bill-wave text-success fs-5"></i>
          </div>
          <div>
            <div class="fw-bold fs-5 text-success">₹<?= number_format($non_card_total, 0) ?></div>
            <div class="text-muted" style="font-size:.82rem">Non-Card Spending — <?= $non_card_count ?> expenses</div>
          </div>
        </div>
        <div class="mb-2" style="font-size:.8rem;font-weight:600;color:#555">
          <i class="fas fa-layer-group me-1"></i>By Payment Type
        </div>
        <?php
        $non_card_modes = ['cash' => 'Cash', 'bank_transfer' => 'Bank Transfer', 'upi' => 'UPI'];
        $nc_icon_map = ['cash'=>'fa-money-bill-wave','bank_transfer'=>'fa-building-columns','upi'=>'fa-mobile-screen-button'];
        $nc_color_map = ['cash'=>'text-success','bank_transfer'=>'text-primary','upi'=>'text-warning'];
        $has_non_card = false;
        foreach ($non_card_modes as $nm => $nl):
            if (!isset($pay_mode_rows[$nm])) continue;
            $has_non_card = true;
            $pm = $pay_mode_rows[$nm];
            $pm_pct = (float)$pm['total_amount'] > 0
                ? round(((float)$pm['paid_amount'] / (float)$pm['total_amount']) * 100) : 0;
        ?>
        <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
          <span>
            <i class="fas <?= $nc_icon_map[$nm] ?> me-2 <?= $nc_color_map[$nm] ?>"></i>
            <span class="fw-semibold"><?= $nl ?></span>
            <span class="text-muted ms-1" style="font-size:.75rem">(<?= (int)$pm['count'] ?> items)</span>
          </span>
          <div class="text-end">
            <div class="fw-bold">₹<?= number_format((float)$pm['total_amount'], 0) ?></div>
            <div style="font-size:.73rem">
              <span class="text-success">₹<?= number_format((float)$pm['paid_amount'], 0) ?> paid</span>
              · <span class="text-warning">₹<?= number_format((float)$pm['total_amount'] - (float)$pm['paid_amount'], 0) ?> owed</span>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
        <?php if (!$has_non_card): ?>
          <p class="text-muted mb-0" style="font-size:.85rem">No cash/UPI/bank expenses for <?= $year ?>.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Payment mode split: donut + mode table -->
<div class="row g-3 mb-4">
  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header">
        <h6 class="card-title"><i class="fas fa-chart-pie me-2 text-danger"></i>Spend by Payment Mode</h6>
      </div>
      <div class="card-body d-flex align-items-center justify-content-center">
        <canvas id="payModeChart" style="max-height:240px"></canvas>
      </div>
    </div>
  </div>
  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-header">
        <h6 class="card-title"><i class="fas fa-table me-2 text-secondary"></i>All Payment Modes – <?= $year ?></h6>
      </div>
      <div class="card-body p-0">
        <?php if (empty($pay_mode_rows)): ?>
          <p class="text-muted text-center py-4">No data for <?= $year ?>.</p>
        <?php else: ?>
        <table class="table mb-0">
          <thead>
            <tr><th>Mode</th><th>Total</th><th class="text-success">Paid</th><th class="text-warning">Outstanding</th><th>% Paid</th><th>Items</th></tr>
          </thead>
          <tbody>
            <?php foreach ($pay_mode_rows as $pmode => $pd):
              $pml   = $pm_label_map[$pmode] ?? ucfirst($pmode);
              $pico  = ['cash'=>'fa-money-bill-wave','bank_transfer'=>'fa-building-columns','card'=>'fa-credit-card','upi'=>'fa-mobile-screen-button'][$pmode] ?? 'fa-circle';
              $p_tot = (float)$pd['total_amount'];
              $p_pai = (float)$pd['paid_amount'];
              $p_owe = $p_tot - $p_pai;
              $p_pct = $p_tot > 0 ? round(($p_pai / $p_tot) * 100) : 0;
            ?>
            <tr>
              <td><i class="fas <?= $pico ?> me-1 text-muted"></i><span class="fw-semibold"><?= $pml ?></span></td>
              <td class="fw-bold">₹<?= number_format($p_tot, 0) ?></td>
              <td class="text-success fw-semibold">₹<?= number_format($p_pai, 0) ?></td>
              <td class="<?= $p_owe > 0 ? 'text-warning fw-semibold' : 'text-muted' ?>">₹<?= number_format($p_owe, 0) ?></td>
              <td>
                <div class="progress" style="height:6px;border-radius:3px;min-width:60px">
                  <div class="progress-bar bg-success" style="width:<?= $p_pct ?>%"></div>
                </div>
                <div style="font-size:.72rem;color:#888"><?= $p_pct ?>%</div>
              </td>
              <td class="text-muted"><?= (int)$pd['count'] ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- ── Stacked bar chart ─────────────────────────────────────── -->
<div class="card mb-4">
  <div class="card-header">
    <h6 class="card-title"><i class="fas fa-chart-column me-2 text-primary"></i>Monthly Paid vs Outstanding – <?= $year ?></h6>
  </div>
  <div class="card-body">
    <canvas id="monthlyChart" height="90"></canvas>
  </div>
</div>

<!-- ── Month-by-month accordion table ───────────────────────── -->
<div class="card mb-4">
  <div class="card-header">
    <h6 class="card-title"><i class="fas fa-calendar-days me-2 text-info"></i>Month-by-Month Breakdown</h6>
    <span class="text-muted" style="font-size:.8rem">Click a row to expand individual expenses</span>
  </div>
  <div class="card-body p-0">
    <?php if (empty($monthly_rows)): ?>
      <div class="text-center text-muted py-5">
        <i class="fas fa-receipt fa-2x mb-2 d-block" style="color:#e2e8f0"></i>
        No expense data for <?= $year ?>.
        <a href="expenses.php" class="d-block mt-1">Add expenses</a>
      </div>
    <?php else: ?>
    <div class="table-wrapper">
      <table class="table mb-0">
        <thead>
          <tr>
            <th style="width:36px"></th>
            <th>Month</th>
            <th>Total Amount</th>
            <th class="text-success">Paid</th>
            <th class="text-warning">Pending</th>
            <th class="text-danger">Overdue</th>
            <th style="min-width:140px">Progress</th>
            <th>Items</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($monthly_rows as $m):
            $mn       = (int)$m['month_num'];
            $m_total  = (float)$m['total_amount'];
            $m_paid   = (float)$m['paid_amount'];
            $m_pend   = (float)$m['pending_amount'];
            $m_over   = (float)$m['overdue_amount'];
            $mp_pct   = $m_total > 0 ? round(($m_paid  / $m_total) * 100) : 0;
            $mo_pct   = $m_total > 0 ? round(($m_over  / $m_total) * 100) : 0;
            $is_cur   = ($mn === (int)date('n') && $year === $current_year);
            $acc_id   = 'acc-m-' . $mn;
          ?>
          <!-- Summary row (clickable) -->
          <tr class="<?= $is_cur ? 'table-primary' : '' ?> rpt-toggle-row"
              style="cursor:pointer"
              data-bs-toggle="collapse"
              data-bs-target="#<?= $acc_id ?>"
              aria-expanded="false"
              aria-controls="<?= $acc_id ?>">
            <td class="text-center">
              <i class="fas fa-chevron-right text-muted toggle-icon" style="font-size:.75rem;transition:transform .2s"></i>
            </td>
            <td class="fw-semibold">
              <?= htmlspecialchars($m['month_name']) ?>
              <?php if ($is_cur): ?>
                <span class="badge bg-primary ms-1" style="font-size:.65rem">Current</span>
              <?php endif; ?>
            </td>
            <td class="fw-bold">₹<?= number_format($m_total, 0) ?></td>
            <td class="fw-semibold text-success">₹<?= number_format($m_paid, 0) ?></td>
            <td class="<?= $m_pend > 0 ? 'text-warning fw-semibold' : 'text-muted' ?>">₹<?= number_format($m_pend, 0) ?></td>
            <td class="<?= $m_over > 0 ? 'text-danger fw-semibold' : 'text-muted' ?>">₹<?= number_format($m_over, 0) ?></td>
            <td>
              <div class="progress" style="height:7px;border-radius:4px">
                <div class="progress-bar bg-success" style="width:<?= $mp_pct ?>%" title="Paid <?= $mp_pct ?>%"></div>
                <div class="progress-bar bg-danger"  style="width:<?= $mo_pct ?>%" title="Overdue <?= $mo_pct ?>%"></div>
              </div>
              <div style="font-size:.72rem;color:#888;margin-top:2px"><?= $mp_pct ?>% paid</div>
            </td>
            <td class="text-muted"><?= (int)$m['paid_count'] ?> / <?= (int)$m['total_count'] ?> paid</td>
          </tr>
          <!-- Detail collapse row -->
          <tr id="<?= $acc_id ?>" class="collapse">
            <td colspan="8" class="p-0 border-0">
              <div class="bg-light border-bottom border-top">
                <table class="table table-sm mb-0">
                  <thead>
                    <tr class="table-secondary">
                      <th class="ps-4" style="width:30%">Expense Name</th>
                      <th>Category</th>
                      <th>Amount</th>
                      <th>Due Date</th>
                      <th>Payment</th>
                      <th>Status</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if (!empty($detail[$mn])): ?>
                      <?php foreach ($detail[$mn] as $e): ?>
                      <tr>
                        <td class="ps-4 fw-semibold">
                          <?= htmlspecialchars((string)($e['name'] ?? '')) ?>
                          <?php if (!empty($e['notes'])): ?>
                            <div class="text-muted" style="font-size:.73rem">
                              <?= htmlspecialchars(strlen((string)$e['notes']) > 50 ? substr((string)$e['notes'], 0, 50).'…' : (string)$e['notes']) ?>
                            </div>
                          <?php endif; ?>
                        </td>
                        <td><span class="badge bg-light text-dark border" style="font-size:.72rem"><?= htmlspecialchars(ucfirst((string)($e['category'] ?? 'general'))) ?></span></td>
                        <td class="fw-bold">₹<?= number_format((float)$e['amount'], 0) ?></td>
                        <td class="text-muted"><?= $e['due_date'] ? date('d M Y', strtotime($e['due_date'])) : '—' ?></td>
                        <td><?= rpt_pay_badge((string)($e['payment_mode'] ?? 'cash'), $e['card_last4'] ?? null) ?></td>
                        <td><?= rpt_badge((string)($e['status'] ?? 'pending')) ?></td>
                      </tr>
                      <?php endforeach; ?>
                    <?php else: ?>
                      <tr><td colspan="6" class="ps-4 text-muted py-2">No expenses recorded</td></tr>
                    <?php endif; ?>
                  </tbody>
                  <?php if (!empty($detail[$mn])): ?>
                  <tfoot class="table-light">
                    <tr>
                      <td colspan="2" class="ps-4 fw-semibold text-muted">Month Total</td>
                      <td class="fw-bold">₹<?= number_format($m_total, 0) ?></td>
                      <td colspan="2"></td>
                      <td class="small text-muted"><?= (int)$m['paid_count'] ?> paid · <?= (int)$m['unpaid_count'] ?> unpaid</td>
                    </tr>
                  </tfoot>
                  <?php endif; ?>
                </table>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <!-- Year-end footer -->
        <tfoot class="table-dark">
          <tr>
            <td></td>
            <td class="fw-bold">TOTAL <?= $year ?></td>
            <td class="fw-bold">₹<?= number_format($yearly['total_amount'], 0) ?></td>
            <td class="fw-bold text-success">₹<?= number_format($yearly['paid_amount'], 0) ?></td>
            <td class="fw-bold" style="color:#f6c23e">₹<?= number_format($yearly['pending_amount'], 0) ?></td>
            <td class="fw-bold" style="color:#e74a3b">₹<?= number_format($yearly['overdue_amount'], 0) ?></td>
            <td class="fw-semibold"><?= $paid_pct ?>% paid</td>
            <td class="fw-semibold"><?= (int)$yearly['paid_count'] ?> / <?= (int)$yearly['total_count'] ?> paid</td>
          </tr>
        </tfoot>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- ── Category breakdown ────────────────────────────────────── -->
<?php if (!empty($cat_rows)): ?>
<div class="row g-3 mb-4">
  <!-- Category table -->
  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-header">
        <h6 class="card-title"><i class="fas fa-tags me-2 text-warning"></i>By Category – <?= $year ?></h6>
      </div>
      <div class="card-body p-0">
        <table class="table mb-0">
          <thead>
            <tr>
              <th>Category</th>
              <th>Total</th>
              <th class="text-success">Paid</th>
              <th class="text-warning">Outstanding</th>
              <th>% Paid</th>
              <th>Items</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($cat_rows as $c):
              $c_total   = (float)$c['total_amount'];
              $c_paid    = (float)$c['paid_amount'];
              $c_owed    = $c_total - $c_paid;
              $c_pct     = $c_total > 0 ? round(($c_paid / $c_total) * 100) : 0;
            ?>
            <tr>
              <td><span class="badge bg-light text-dark border"><?= htmlspecialchars(ucfirst((string)($c['category'] ?? 'other'))) ?></span></td>
              <td class="fw-bold">₹<?= number_format($c_total, 0) ?></td>
              <td class="text-success fw-semibold">₹<?= number_format($c_paid, 0) ?></td>
              <td class="<?= $c_owed > 0 ? 'text-warning fw-semibold' : 'text-muted' ?>">₹<?= number_format($c_owed, 0) ?></td>
              <td>
                <div class="progress" style="height:6px;border-radius:4px;min-width:60px">
                  <div class="progress-bar bg-success" style="width:<?= $c_pct ?>%"></div>
                </div>
                <div style="font-size:.72rem;color:#888"><?= $c_pct ?>%</div>
              </td>
              <td class="text-muted"><?= (int)$c['count'] ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <!-- Category donut chart -->
  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header">
        <h6 class="card-title"><i class="fas fa-chart-pie me-2 text-warning"></i>Category Distribution</h6>
      </div>
      <div class="card-body d-flex align-items-center justify-content-center">
        <canvas id="categoryChart" style="max-height:260px"></canvas>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php
$extra_js = <<<EOT
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function () {
  // ── Stacked bar chart ──────────────────────────────────────
  const ctx1 = document.getElementById('monthlyChart');
  if (ctx1) {
    new Chart(ctx1.getContext('2d'), {
      type: 'bar',
      data: {
        labels: $ch_labels_json,
        datasets: [
          { label: 'Paid',    data: $ch_paid_json,    backgroundColor: 'rgba(28,200,138,0.85)',  borderRadius: 4, stack: 's' },
          { label: 'Pending', data: $ch_pending_json, backgroundColor: 'rgba(246,194,62,0.85)',  borderRadius: 4, stack: 's' },
          { label: 'Overdue', data: $ch_overdue_json, backgroundColor: 'rgba(231,74,59,0.85)',   borderRadius: 4, stack: 's' }
        ]
      },
      options: {
        responsive: true,
        plugins: {
          legend: { position: 'top' },
          tooltip: {
            callbacks: {
              label: c => ' ₹' + c.parsed.y.toLocaleString('en-IN')
            }
          }
        },
        scales: {
          x: { stacked: true, grid: { display: false } },
          y: { stacked: true, ticks: { callback: v => '₹' + v.toLocaleString('en-IN') } }
        }
      }
    });
  }

  // ── Payment mode donut chart ───────────────────────────────
  const ctx0 = document.getElementById('payModeChart');
  if (ctx0) {
    new Chart(ctx0.getContext('2d'), {
      type: 'doughnut',
      data: {
        labels: $pm_labels_json,
        datasets: [{ data: $pm_totals_json, backgroundColor: $pm_colors_json, hoverOffset: 8 }]
      },
      options: {
        responsive: true,
        plugins: {
          legend: { position: 'bottom', labels: { font: { size: 11 }, padding: 10 } },
          tooltip: { callbacks: { label: c => ' ₹' + c.parsed.toLocaleString('en-IN') } }
        }
      }
    });
  }

  // ── Category donut chart ────────────────────────────────────
  const ctx2 = document.getElementById('categoryChart');
  if (ctx2) {
    new Chart(ctx2.getContext('2d'), {
      type: 'doughnut',
      data: {
        labels: $cat_labels_json,
        datasets: [{ data: $cat_totals_json, backgroundColor: $cat_colors_json, hoverOffset: 8 }]
      },
      options: {
        responsive: true,
        plugins: {
          legend: { position: 'bottom', labels: { font: { size: 11 }, padding: 10 } },
          tooltip: {
            callbacks: {
              label: c => ' ₹' + c.parsed.toLocaleString('en-IN')
            }
          }
        }
      }
    });
  }

  // ── Accordion chevron toggle ────────────────────────────────
  document.querySelectorAll('.rpt-toggle-row').forEach(row => {
    const id = row.getAttribute('data-bs-target');
    const target = document.querySelector(id);
    if (!target) return;
    target.addEventListener('show.bs.collapse', () => {
      const icon = row.querySelector('.toggle-icon');
      if (icon) icon.style.transform = 'rotate(90deg)';
    });
    target.addEventListener('hide.bs.collapse', () => {
      const icon = row.querySelector('.toggle-icon');
      if (icon) icon.style.transform = 'rotate(0deg)';
    });
  });
})();
</script>
EOT;

require_once 'includes/footer.php';
