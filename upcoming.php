<?php
require_once 'includes/auth.php';
require_once 'config.php';
$page_title = 'Upcoming';
$uid   = (int)$_SESSION['id'];
$today = date('Y-m-d');
$msg   = '';

// ── POST handlers ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';

    if ($act === 'mark_expense_paid') {
        $eid = (int)($_POST['expense_id'] ?? 0);
        if ($eid) {
            $exp = mysqli_fetch_assoc(mysqli_query($conn,
                "SELECT * FROM expenses WHERE id=$eid AND user_id=$uid"));
            if ($exp) {
                mysqli_query($conn, "UPDATE expenses SET status='paid' WHERE id=$eid AND user_id=$uid");
                $lid = (int)($exp['loan_ref_id'] ?? 0);
                if ($lid) {
                    $amt = (float)$exp['amount'];
                    mysqli_query($conn,
                        "UPDATE loans SET remaining_amount=GREATEST(0,remaining_amount-$amt) WHERE id=$lid AND user_id=$uid");
                    $pend = (int)mysqli_fetch_assoc(mysqli_query($conn,
                        "SELECT COUNT(*) c FROM expenses WHERE loan_ref_id=$lid AND user_id=$uid AND auto_generated=1 AND status='pending'"))['c'];
                    if ($pend === 0) {
                        mysqli_query($conn, "UPDATE loans SET status='paid' WHERE id=$lid AND user_id=$uid");
                        $msg = 'success:EMI paid. All EMIs complete — Loan fully settled!';
                    } elseif ($exp['due_date'] < $today) {
                        mysqli_query($conn,
                            "UPDATE loans SET status='overdue' WHERE id=$lid AND user_id=$uid AND status='active'");
                        $msg = 'warning:EMI paid (late payment). Loan marked overdue.';
                    } else {
                        $msg = 'success:EMI marked paid. Balance updated.';
                    }
                    AppLogger::action("EMI paid: expense=$eid loan=$lid");
                } else {
                    $msg = 'success:Expense marked as paid.';
                    AppLogger::action("Expense paid: id=$eid");
                }
            }
        }
    }

    if ($act === 'mark_bulk_loan_paid') {
        $lid = (int)($_POST['loan_id'] ?? 0);
        if ($lid) {
            $cnt = (int)mysqli_fetch_assoc(mysqli_query($conn,
                "SELECT COUNT(*) c FROM expenses WHERE loan_ref_id=$lid AND user_id=$uid AND auto_generated=1 AND status='pending'"))['c'];
            mysqli_query($conn,
                "UPDATE expenses SET status='paid' WHERE loan_ref_id=$lid AND user_id=$uid AND auto_generated=1 AND status='pending'");
            mysqli_query($conn,
                "UPDATE loans SET remaining_amount=0, status='paid' WHERE id=$lid AND user_id=$uid");
            AppLogger::action("Bulk loan paid: loan=$lid count=$cnt");
            $msg = "success:$cnt EMIs cleared. Loan fully settled and closed!";
        }
    }

    if ($act === 'mark_invoice_paid') {
        $iid = (int)($_POST['invoice_id'] ?? 0);
        if ($iid) {
            mysqli_query($conn, "UPDATE invoices SET status='paid' WHERE id=$iid AND user_id=$uid");
            $msg = 'success:Invoice marked as paid.';
            AppLogger::action("Invoice paid: id=$iid");
        }
    }

    header('Location: upcoming.php?msg='.urlencode($msg));
    exit;
}

$msg = $_GET['msg'] ?? '';

// Auto-marks
mysqli_query($conn, "UPDATE loans SET status='overdue' WHERE user_id=$uid AND status='active' AND due_date < '$today'");
mysqli_query($conn, "UPDATE expenses SET status='paid' WHERE user_id=$uid AND auto_generated=1 AND status IN('pending','overdue') AND due_date < '$today'");
mysqli_query($conn, "UPDATE expenses SET status='overdue' WHERE user_id=$uid AND auto_generated=0 AND status='pending' AND due_date < '$today'");

// ── Fetch loan EMI expenses ────────────────────────────────────────────
$emi_rows = mysqli_fetch_all(mysqli_query($conn,
    "SELECT e.id, e.name emi_name, e.due_date, e.amount, e.status,
            e.loan_ref_id,
            l.name loan_name, l.lender, l.remaining_amount,
            l.monthly_payment loan_monthly, l.status loan_status,
            l.payment_mode, l.card_last4,
            (SELECT COUNT(*) FROM expenses x WHERE x.loan_ref_id=l.id AND x.auto_generated=1) emi_total,
            (SELECT COUNT(*) FROM expenses x WHERE x.loan_ref_id=l.id AND x.auto_generated=1 AND x.status='paid') emi_paid,
            (SELECT COUNT(*) FROM expenses x WHERE x.loan_ref_id=l.id AND x.auto_generated=1 AND x.status='pending') emi_pending_cnt,
            (SELECT COALESCE(SUM(x.amount),0) FROM expenses x WHERE x.loan_ref_id=l.id AND x.auto_generated=1 AND x.status='pending') emi_pending_total
     FROM expenses e JOIN loans l ON l.id=e.loan_ref_id AND l.user_id=e.user_id
     WHERE e.user_id=$uid AND e.auto_generated=1 AND e.status='pending'
     ORDER BY e.loan_ref_id ASC, e.due_date ASC"), MYSQLI_ASSOC);

// Group by loan: all overdue entries + only next 1 upcoming
$loan_groups = [];
foreach ($emi_rows as $row) {
    $lid  = (int)$row['loan_ref_id'];
    $days = (int)ceil((strtotime($row['due_date']) - strtotime($today)) / 86400);
    $row['days'] = $days;
    if (!isset($loan_groups[$lid])) {
        $loan_groups[$lid] = [
            'loan_id'           => $lid,
            'loan_name'         => $row['loan_name'],
            'lender'            => $row['lender'] ?? '–',
            'remaining'         => (float)$row['remaining_amount'],
            'loan_monthly'      => (float)$row['loan_monthly'],
            'loan_status'       => $row['loan_status'],
            'payment_mode'      => $row['payment_mode'],
            'card_last4'        => $row['card_last4'],
            'emi_total'         => (int)$row['emi_total'],
            'emi_paid'          => (int)$row['emi_paid'],
            'emi_pending_cnt'   => (int)$row['emi_pending_cnt'],
            'emi_pending_total' => (float)$row['emi_pending_total'],
            'entries'           => [],
            'shown_future'      => 0,
        ];
    }
    if ($days < 0 || $loan_groups[$lid]['shown_future'] < 1) {
        $loan_groups[$lid]['entries'][] = $row;
        if ($days >= 0) $loan_groups[$lid]['shown_future']++;
    }
}

// ── Fetch regular expenses ─────────────────────────────────────────────
$reg_exps = mysqli_fetch_all(mysqli_query($conn,
    "SELECT id, 'expense' AS _type, name, due_date, amount, status, category AS extra
     FROM expenses WHERE user_id=$uid AND auto_generated=0 AND status IN('pending','overdue')
     ORDER BY due_date ASC"), MYSQLI_ASSOC);
foreach ($reg_exps as &$e)
    $e['days'] = (int)ceil((strtotime($e['due_date']) - strtotime($today)) / 86400);
unset($e);

// ── Fetch invoices ─────────────────────────────────────────────────────
$invoices = mysqli_fetch_all(mysqli_query($conn,
    "SELECT id, 'invoice' AS _type, vendor_name AS name, invoice_number AS extra,
            due_date, amount, status
     FROM invoices WHERE user_id=$uid AND status IN('unpaid','overdue') AND due_date IS NOT NULL
     ORDER BY due_date ASC"), MYSQLI_ASSOC);
foreach ($invoices as &$inv)
    $inv['days'] = (int)ceil((strtotime($inv['due_date']) - strtotime($today)) / 86400);
unset($inv);

// ── Fetch warranties ───────────────────────────────────────────────────
$warranties = mysqli_fetch_all(mysqli_query($conn,
    "SELECT * FROM warranties WHERE user_id=$uid ORDER BY warranty_expiry ASC"), MYSQLI_ASSOC);

// ── Bucket helpers ─────────────────────────────────────────────────────
function get_bucket(int $days, string $status = ''): string {
    if ($days < 0 || $status === 'overdue') return 'overdue';
    if ($days <= 7)  return 'week';
    if ($days <= 30) return 'month';
    return 'later';
}

$misc_bkt  = ['overdue'=>[],'week'=>[],'month'=>[],'later'=>[]];
foreach (array_merge($reg_exps, $invoices) as $item)
    $misc_bkt[get_bucket($item['days'], $item['status'])][] = $item;

$loan_bkt  = ['overdue'=>[],'week'=>[],'month'=>[],'later'=>[]];
foreach ($loan_groups as $grp) {
    if (empty($grp['entries'])) continue;
    $d = $grp['entries'][0]['days'];
    $b = ($grp['loan_status'] === 'overdue' && $d <= 0) ? 'overdue' : get_bucket($d);
    $loan_bkt[$b][] = $grp;
}

$totals = [];
foreach (['overdue','week','month','later'] as $b)
    $totals[$b] = count($loan_bkt[$b]) + count($misc_bkt[$b]);

require_once 'includes/header.php';

function days_text(int $days): string {
    if ($days < 0)  return abs($days).' days overdue';
    if ($days === 0) return 'Due today';
    if ($days === 1) return 'Tomorrow';
    return 'in '.$days.' days';
}
function days_cls(int $days): string {
    if ($days < 0)  return 'text-danger';
    if ($days <= 1) return 'text-warning';
    if ($days <= 7) return 'text-primary';
    return 'text-muted';
}
function fmt(float $n): string { return '₹'.number_format($n, 0); }
function pct_bar(int $paid, int $total): int {
    return $total > 0 ? (int)round($paid / $total * 100) : 0;
}
?>

<?php if ($msg): [$mt,$mx] = explode(':', $msg, 2); ?>
<div class="alert alert-<?= $mt==='success'?'success':($mt==='warning'?'warning':'danger') ?> alert-dismissible fade show py-2">
  <i class="fas fa-<?= $mt==='success'?'circle-check':'triangle-exclamation' ?> me-2"></i><?= htmlspecialchars($mx) ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- ── Summary stat cards ─────────────────────────────────────────── -->
<div class="row g-3 mb-4">
  <?php
  $stat_cfg = [
    'overdue' => ['label'=>'Overdue',       'icon'=>'circle-exclamation', 'cls'=>'red'],
    'week'    => ['label'=>'This Week',      'icon'=>'clock',              'cls'=>'orange'],
    'month'   => ['label'=>'This Month',     'icon'=>'calendar-days',      'cls'=>'blue'],
    'later'   => ['label'=>'Later (>30d)',   'icon'=>'forward',            'cls'=>'green'],
  ];
  foreach ($stat_cfg as $bk => $sc): ?>
  <div class="col-6 col-sm-3">
    <div class="stat-card">
      <div class="stat-icon <?= $sc['cls'] ?>"><i class="fas fa-<?= $sc['icon'] ?>"></i></div>
      <div class="stat-info">
        <div class="stat-label"><?= $sc['label'] ?></div>
        <div class="stat-value"><?= $totals[$bk] ?></div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<?php
$section_cfg = [
  'overdue' => ['title'=>'Overdue — Immediate Attention', 'icon'=>'circle-exclamation', 'color'=>'#ef4444', 'border'=>'border-danger'],
  'week'    => ['title'=>'Due This Week',                 'icon'=>'clock',              'color'=>'#f59e0b', 'border'=>'border-warning'],
  'month'   => ['title'=>'Due This Month',                'icon'=>'calendar-days',      'color'=>'#3b82f6', 'border'=>'border-primary'],
  'later'   => ['title'=>'Upcoming (Beyond 30 Days)',     'icon'=>'forward',            'color'=>'#10b981', 'border'=>'border-success'],
];

foreach ($section_cfg as $bk => $sc):
    $l_items = $loan_bkt[$bk];
    $m_items = $misc_bkt[$bk];
    if (empty($l_items) && empty($m_items)) continue;
?>
<div class="card mb-4">
  <div class="card-header" style="border-left:4px solid <?= $sc['color'] ?>;">
    <h6 class="card-title mb-0" style="color:<?= $sc['color'] ?>;">
      <i class="fas fa-<?= $sc['icon'] ?> me-2"></i><?= $sc['title'] ?>
      <span class="badge ms-2 text-white" style="background:<?= $sc['color'] ?>;"><?= count($l_items)+count($m_items) ?></span>
    </h6>
  </div>
  <div class="card-body p-3">

    <?php /* ── Loan EMI groups ── */ foreach ($l_items as $grp):
      $pct     = pct_bar($grp['emi_paid'], $grp['emi_total']);
      $is_bulk = $grp['emi_pending_cnt'] > 5;
      $entry0  = $grp['entries'][0];
    ?>
    <div class="border rounded mb-3 overflow-hidden" style="border-color:rgba(59,130,246,.3)!important;">

      <!-- Loan header row -->
      <div class="d-flex align-items-center px-3 py-2 flex-wrap gap-2"
           style="background:rgba(59,130,246,.06);">
        <div class="d-flex align-items-center gap-2 flex-grow-1">
          <div class="rounded-circle d-flex align-items-center justify-content-center text-white flex-shrink-0"
               style="width:36px;height:36px;background:#3b82f6;font-size:.85rem;">
            <i class="fas fa-hand-holding-dollar"></i>
          </div>
          <div>
            <div class="fw-bold" style="font-size:.92rem;"><?= htmlspecialchars($grp['loan_name']) ?></div>
            <div class="text-muted" style="font-size:.76rem;">
              <?= htmlspecialchars($grp['lender']) ?>
              &nbsp;·&nbsp; <?= $grp['emi_paid'] ?>/<?= $grp['emi_total'] ?> EMIs paid
              &nbsp;·&nbsp; <?= $grp['emi_pending_cnt'] ?> remaining
            </div>
          </div>
        </div>
        <div class="text-end flex-shrink-0">
          <div class="text-muted" style="font-size:.73rem;">Balance remaining</div>
          <div class="fw-bold text-primary"><?= fmt($grp['remaining']) ?></div>
        </div>
        <?php if ($is_bulk): ?>
        <button class="btn btn-danger btn-sm flex-shrink-0"
                onclick="openBulkModal(<?= $grp['loan_id'] ?>,
                  <?= htmlspecialchars(json_encode($grp['loan_name']), ENT_QUOTES) ?>,
                  <?= $grp['emi_pending_cnt'] ?>,
                  <?= $grp['emi_pending_total'] ?>)">
          <i class="fas fa-check-double me-1"></i>Settle All <?= $grp['emi_pending_cnt'] ?> EMIs
        </button>
        <?php endif; ?>
      </div>

      <!-- Progress bar -->
      <div class="px-3 pt-2 pb-1" style="background:#fff;">
        <div class="d-flex justify-content-between mb-1" style="font-size:.72rem;">
          <span class="text-success fw-semibold"><?= $grp['emi_paid'] ?> paid (<?= $pct ?>%)</span>
          <span class="text-warning fw-semibold"><?= $grp['emi_pending_cnt'] ?> left · <?= fmt($grp['emi_pending_total']) ?> total due</span>
        </div>
        <div class="progress" style="height:5px;border-radius:3px;">
          <div class="progress-bar bg-success" style="width:<?= $pct ?>%;"></div>
        </div>
      </div>

      <!-- EMI entry rows -->
      <?php foreach ($grp['entries'] as $idx => $entry): ?>
      <div class="d-flex align-items-center flex-wrap gap-2 px-3 py-2 border-top"
           style="background:<?= $idx%2===0?'#fff':'#fafafa' ?>;">
        <div class="flex-grow-1">
          <div class="fw-semibold" style="font-size:.85rem;">
            <?= htmlspecialchars($entry['emi_name']) ?>
          </div>
          <div class="<?= days_cls($entry['days']) ?>" style="font-size:.76rem;">
            <i class="fas fa-<?= $entry['days']<0?'exclamation-triangle':'calendar' ?> me-1"></i>
            <?= date('d M Y', strtotime($entry['due_date'])) ?> &nbsp;·&nbsp;
            <?= days_text($entry['days']) ?>
          </div>
        </div>
        <div class="fw-bold text-dark" style="font-size:1rem;"><?= fmt((float)$entry['amount']) ?></div>
        <button class="btn btn-success btn-sm"
                onclick='openPayModal(<?= $entry['id'] ?>,
                  <?= json_encode($entry['emi_name']) ?>,
                  <?= (float)$entry['amount'] ?>,
                  <?= json_encode($entry['due_date']) ?>,
                  <?= $entry['days'] ?>,
                  <?= $grp['loan_id'] ?>,
                  <?= json_encode($grp['loan_name']) ?>,
                  <?= $grp['remaining'] ?>)'>
          <i class="fas fa-check me-1"></i>Mark Paid
        </button>
      </div>
      <?php endforeach; ?>

      <?php if ($grp['emi_pending_cnt'] > $grp['shown_future'] && !$is_bulk): ?>
      <div class="px-3 py-2 text-muted border-top" style="font-size:.78rem;background:#f9fafb;">
        <i class="fas fa-info-circle me-1"></i>
        +<?= $grp['emi_pending_cnt'] - count($grp['entries']) ?> more pending EMIs not shown
        (<?= fmt(($grp['emi_pending_cnt'] - count($grp['entries'])) * $grp['loan_monthly']) ?> upcoming)
      </div>
      <?php endif; ?>
    </div>
    <?php endforeach; /* loan groups */ ?>

    <?php /* ── Misc items (expenses + invoices) ── */ foreach ($m_items as $item):
      $is_inv = ($item['_type'] === 'invoice');
    ?>
    <div class="d-flex align-items-center flex-wrap gap-2 border rounded px-3 py-2 mb-2"
         style="background:#fff;">
      <!-- Icon -->
      <div class="rounded-circle d-flex align-items-center justify-content-center text-white flex-shrink-0"
           style="width:34px;height:34px;background:<?= $is_inv?'#b45309':'#059669' ?>;font-size:.8rem;">
        <i class="fas fa-<?= $is_inv?'file-invoice':'receipt' ?>"></i>
      </div>
      <!-- Info -->
      <div class="flex-grow-1">
        <div class="fw-semibold" style="font-size:.88rem;"><?= htmlspecialchars($item['name']) ?></div>
        <div class="<?= days_cls($item['days']) ?>" style="font-size:.76rem;">
          <?php if ($is_inv): ?>
            <i class="fas fa-hashtag me-1"></i><?= htmlspecialchars($item['extra'] ?? '') ?> &nbsp;·&nbsp;
          <?php else: ?>
            <i class="fas fa-tag me-1"></i><?= htmlspecialchars(ucfirst($item['extra'] ?? '')) ?> &nbsp;·&nbsp;
          <?php endif; ?>
          <i class="fas fa-calendar me-1"></i><?= date('d M Y', strtotime($item['due_date'])) ?>
          &nbsp;·&nbsp; <?= days_text($item['days']) ?>
        </div>
      </div>
      <!-- Amount + Status -->
      <div class="fw-bold" style="font-size:.98rem;"><?= fmt((float)$item['amount']) ?></div>
      <?php
        $s = $item['status'];
        $smap = ['pending'=>'badge-pending','unpaid'=>'badge-unpaid','overdue'=>'badge-overdue'];
        echo '<span class="badge-status '.($smap[$s]??'').'">'.$s.'</span>';
      ?>
      <!-- Action -->
      <?php if ($is_inv): ?>
      <button class="btn btn-sm btn-success"
              onclick='openInvoiceModal(<?= $item['id'] ?>, <?= json_encode($item['name']) ?>, <?= (float)$item['amount'] ?>)'>
        <i class="fas fa-check me-1"></i>Mark Paid
      </button>
      <?php else: ?>
      <button class="btn btn-sm btn-success"
              onclick='openExpModal(<?= $item['id'] ?>, <?= json_encode($item['name']) ?>, <?= (float)$item['amount'] ?>, <?= json_encode($item['due_date']) ?>, <?= $item['days'] ?>)'>
        <i class="fas fa-check me-1"></i>Mark Paid
      </button>
      <?php endif; ?>
    </div>
    <?php endforeach; /* misc items */ ?>

  </div>
</div>
<?php endforeach; /* sections */ ?>

<?php if (array_sum($totals) === 0): ?>
<div class="card">
  <div class="card-body text-center py-5">
    <i class="fas fa-party-horn fa-3x text-success mb-3"></i>
    <h5 class="text-success">All clear!</h5>
    <p class="text-muted mb-0">No pending or overdue items. You're on top of everything.</p>
  </div>
</div>
<?php endif; ?>

<!-- ── Warranty section ───────────────────────────────────────────── -->
<?php if (!empty($warranties)): ?>
<div class="card mt-2">
  <div class="card-header" style="border-left:4px solid #8b5cf6;">
    <h6 class="card-title mb-0" style="color:#8b5cf6;">
      <i class="fas fa-shield-halved me-2"></i>Warranty Expiry
      <a href="warranties.php" class="btn btn-sm btn-outline-secondary ms-2" style="font-size:.75rem;">Manage</a>
    </h6>
  </div>
  <div class="card-body p-3">
    <div class="row g-2">
      <?php foreach ($warranties as $w):
        $wd = (int)ceil((strtotime($w['warranty_expiry']) - strtotime($today)) / 86400);
        if ($wd < 0)       { $wc='danger';  $wl='Expired '.abs($wd).'d ago'; }
        elseif ($wd <= 30) { $wc='warning'; $wl=$wd.'d left'; }
        elseif ($wd <= 90) { $wc='primary'; $wl=$wd.'d left'; }
        else               { $wc='success'; $wl=$wd.'d left'; }
      ?>
      <div class="col-sm-6 col-md-4">
        <div class="border rounded p-2 d-flex align-items-center gap-2"
             style="border-color:var(--bs-<?= $wc ?>)!important;background:var(--bs-<?= $wc ?>-bg-subtle,#fff);">
          <div class="rounded-circle d-flex align-items-center justify-content-center text-<?= $wc ?>"
               style="width:34px;height:34px;background:rgba(var(--bs-<?= $wc ?>-rgb),.1);flex-shrink:0;font-size:.8rem;">
            <i class="fas fa-shield-halved"></i>
          </div>
          <div class="flex-grow-1 overflow-hidden">
            <div class="fw-semibold text-truncate" style="font-size:.85rem;"><?= htmlspecialchars($w['product_name']) ?></div>
            <div class="text-muted" style="font-size:.74rem;"><?= htmlspecialchars($w['brand'] ?? '–') ?></div>
          </div>
          <div class="text-end flex-shrink-0">
            <span class="badge bg-<?= $wc ?>"><?= $wl ?></span>
            <div class="text-muted" style="font-size:.72rem;margin-top:2px;"><?= date('d M Y', strtotime($w['warranty_expiry'])) ?></div>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ── Confirm Pay EMI modal ─────────────────────────────────────── -->
<div class="modal fade" id="payModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header text-white" style="background:#10b981;">
        <h5 class="modal-title"><i class="fas fa-circle-check me-2"></i>Confirm EMI Payment</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-0">
        <div id="pm_body" class="p-3"></div>
      </div>
      <div class="modal-footer py-2">
        <form method="POST" id="pm_form">
          <input type="hidden" name="action" value="mark_expense_paid">
          <input type="hidden" name="expense_id" id="pm_eid">
          <button type="button" class="btn btn-secondary btn-sm me-2" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success btn-sm">
            <i class="fas fa-check me-1"></i>Confirm Payment
          </button>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- ── Confirm Pay Expense modal ─────────────────────────────────── -->
<div class="modal fade" id="expModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header text-white" style="background:#059669;">
        <h5 class="modal-title"><i class="fas fa-receipt me-2"></i>Confirm Expense Payment</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-0">
        <div id="em_body" class="p-3"></div>
      </div>
      <div class="modal-footer py-2">
        <form method="POST" id="em_form">
          <input type="hidden" name="action" value="mark_expense_paid">
          <input type="hidden" name="expense_id" id="em_eid">
          <button type="button" class="btn btn-secondary btn-sm me-2" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success btn-sm">
            <i class="fas fa-check me-1"></i>Mark as Paid
          </button>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- ── Bulk settle modal ──────────────────────────────────────────── -->
<div class="modal fade" id="bulkModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header text-white" style="background:#ef4444;">
        <h5 class="modal-title"><i class="fas fa-check-double me-2"></i>Settle All Pending EMIs</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-warning py-2">
          <i class="fas fa-triangle-exclamation me-2"></i>
          This will mark <strong id="bm_cnt">—</strong> EMIs as <strong>Paid</strong> and close the loan.
        </div>
        <table class="table table-sm table-borderless mb-0">
          <tr><td class="text-muted" style="width:40%">Loan</td><td class="fw-bold" id="bm_name">—</td></tr>
          <tr><td class="text-muted">EMIs to settle</td><td class="fw-bold" id="bm_cnt2">—</td></tr>
          <tr><td class="text-muted">Total amount</td><td class="fw-bold text-danger" id="bm_total">—</td></tr>
        </table>
        <div class="mt-2 p-2 rounded" style="background:rgba(239,68,68,.07);font-size:.82rem;">
          <i class="fas fa-info-circle me-1 text-danger"></i>
          Loan will be marked as <strong>Fully Settled</strong> after this action.
        </div>
      </div>
      <div class="modal-footer py-2">
        <form method="POST" id="bm_form">
          <input type="hidden" name="action" value="mark_bulk_loan_paid">
          <input type="hidden" name="loan_id" id="bm_lid">
          <button type="button" class="btn btn-secondary btn-sm me-2" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger btn-sm">
            <i class="fas fa-check-double me-1"></i>Yes, Settle All EMIs
          </button>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- ── Invoice pay modal ─────────────────────────────────────────── -->
<div class="modal fade" id="invModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header text-white" style="background:#b45309;">
        <h5 class="modal-title"><i class="fas fa-file-invoice me-2"></i>Mark Invoice Paid</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <table class="table table-sm table-borderless mb-0">
          <tr><td class="text-muted" style="width:40%">Vendor</td><td class="fw-bold" id="im_name">—</td></tr>
          <tr><td class="text-muted">Amount</td><td class="fw-bold text-warning" id="im_amt">—</td></tr>
        </table>
      </div>
      <div class="modal-footer py-2">
        <form method="POST">
          <input type="hidden" name="action" value="mark_invoice_paid">
          <input type="hidden" name="invoice_id" id="im_iid">
          <button type="button" class="btn btn-secondary btn-sm me-2" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-warning btn-sm">
            <i class="fas fa-check me-1"></i>Confirm Paid
          </button>
        </form>
      </div>
    </div>
  </div>
</div>

<?php
$extra_js = <<<'JS'
<script>
const fmtINR = n => '₹' + Math.round(n).toLocaleString('en-IN');
const fmtDate = s => { const d=new Date(s); return d.toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'}); };

function openPayModal(eid, emiName, amount, dueDate, days, loanId, loanName, remaining) {
  document.getElementById('pm_eid').value = eid;
  const late     = days < 0;
  const afterBal = Math.max(0, remaining - amount);
  const urgency  = late
    ? `<div class="alert alert-danger py-2 mt-2 mb-0" style="font-size:.82rem;">
         <i class="fas fa-exclamation-triangle me-1"></i>
         This EMI is <strong>${Math.abs(days)} days overdue</strong>.
         Late payment will set loan status to <strong>Overdue</strong>.
       </div>`
    : (days === 0
        ? `<div class="alert alert-warning py-2 mt-2 mb-0" style="font-size:.82rem;">
             <i class="fas fa-clock me-1"></i>This EMI is <strong>due today</strong>.
           </div>`
        : '');
  document.getElementById('pm_body').innerHTML = `
    <table class="table table-sm table-borderless mb-2">
      <tr><td class="text-muted" style="width:42%">EMI</td><td class="fw-bold">${emiName}</td></tr>
      <tr><td class="text-muted">Loan</td><td>${loanName}</td></tr>
      <tr><td class="text-muted">Due date</td><td>${fmtDate(dueDate)} <span class="text-${late?'danger':days===0?'warning':'muted'}" style="font-size:.8rem;">(${days<0?Math.abs(days)+' days overdue':days===0?'Today':'in '+days+' days'})</span></td></tr>
      <tr><td class="text-muted">Amount due</td><td class="fw-bold text-success" style="font-size:1.1rem;">${fmtINR(amount)}</td></tr>
      <tr><td class="text-muted">Balance after</td>
          <td><span class="text-muted text-decoration-line-through">${fmtINR(remaining)}</span>
              <i class="fas fa-arrow-right mx-1 text-muted" style="font-size:.7rem;"></i>
              <strong>${fmtINR(afterBal)}</strong></td></tr>
    </table>${urgency}`;
  new bootstrap.Modal(document.getElementById('payModal')).show();
}

function openExpModal(eid, name, amount, dueDate, days) {
  document.getElementById('em_eid').value = eid;
  const late = days < 0;
  document.getElementById('em_body').innerHTML = `
    <table class="table table-sm table-borderless mb-0">
      <tr><td class="text-muted" style="width:42%">Expense</td><td class="fw-bold">${name}</td></tr>
      <tr><td class="text-muted">Due date</td><td>${fmtDate(dueDate)} <span class="text-${late?'danger':'muted'}" style="font-size:.8rem;">(${days<0?Math.abs(days)+' days overdue':days===0?'Today':'in '+days+' days'})</span></td></tr>
      <tr><td class="text-muted">Amount</td><td class="fw-bold text-success" style="font-size:1.1rem;">${fmtINR(amount)}</td></tr>
    </table>
    ${late?'<div class="alert alert-warning py-2 mt-2 mb-0" style="font-size:.82rem;"><i class="fas fa-clock me-1"></i>This expense is overdue.</div>':''}`;
  new bootstrap.Modal(document.getElementById('expModal')).show();
}

function openBulkModal(loanId, loanName, pendingCnt, pendingTotal) {
  document.getElementById('bm_lid').value = loanId;
  document.getElementById('bm_name').textContent  = loanName;
  document.getElementById('bm_cnt').textContent   = pendingCnt + ' EMIs';
  document.getElementById('bm_cnt2').textContent  = pendingCnt + ' EMIs';
  document.getElementById('bm_total').textContent = fmtINR(pendingTotal);
  new bootstrap.Modal(document.getElementById('bulkModal')).show();
}

function openInvoiceModal(iid, name, amount) {
  document.getElementById('im_iid').value = iid;
  document.getElementById('im_name').textContent = name;
  document.getElementById('im_amt').textContent  = fmtINR(amount);
  new bootstrap.Modal(document.getElementById('invModal')).show();
}
</script>
JS;
require_once 'includes/footer.php';
