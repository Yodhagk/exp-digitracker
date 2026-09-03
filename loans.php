<?php
require_once 'includes/auth.php';
require_once 'config.php';
$page_title = 'Loans';
$uid   = (int)$_SESSION['id'];
$today = date('Y-m-d');
$msg   = '';

// ── EMI schedule generator ───────────────────────────────────
function generate_emi_schedule(
    $conn, int $loan_id, int $uid, string $name, float $monthly,
    string $first_due, int $tenure_months, string $payment_mode, ?string $card_last4
): int {
    global $today;
    // Remove ALL auto-generated entries so re-insert starts clean (no duplicates).
    // Paid status is re-derived from due_date < today, so history is preserved correctly.
    mysqli_query($conn,
        "DELETE FROM expenses WHERE loan_ref_id=$loan_id AND user_id=$uid AND auto_generated=1");

    $dt    = new DateTime($first_due);
    $count = 0;
    for ($i = 0; $i < $tenure_months; $i++) {
        if ($i > 0) $dt->modify('+1 month');
        $due_str = $dt->format('Y-m-d');
        $status  = ($due_str < $today) ? 'paid' : 'pending';
        $n       = $i + 1;
        $label   = "EMI $n/$tenure_months";
        $emi_nm  = "$name – $label";
        $note    = "$label · auto-generated EMI";

        $cat    = 'loan';
        $is_rec = 0;
        $recur  = 'monthly';
        $auto_g = 1;
        $stmt = mysqli_prepare($conn,
            'INSERT INTO expenses
                (user_id,name,category,amount,due_date,is_recurring,recurrence,status,
                 notes,payment_mode,card_last4,loan_ref_id,auto_generated)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
        mysqli_stmt_bind_param($stmt, 'issdsisssssii',
            $uid, $emi_nm, $cat, $monthly, $due_str,
            $is_rec, $recur, $status, $note, $payment_mode, $card_last4,
            $loan_id, $auto_g);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $count++;
    }
    return $count;
}

// ── Real amortization (reducing-balance) math ─────────────────
// The mathematically correct EMI for a principal fully repaid over $tenure_months
// at $annual_rate (%), via the standard reducing-balance formula.
function calc_emi(float $principal, float $annual_rate, int $tenure_months): float {
    if ($tenure_months <= 0 || $principal <= 0) return 0.0;
    $r = $annual_rate / 12 / 100;
    if ($r <= 0) return round($principal / $tenure_months, 2);
    $factor = (1 + $r) ** $tenure_months;
    return round($principal * $r * $factor / ($factor - 1), 2);
}

// Walks the loan forward one month at a time, splitting each payment into
// interest (on the outstanding balance) and principal — unlike a flat
// "balance -= emi_amount", which ignores interest entirely and understates
// how much principal is actually left. Returns:
//   clears              — false if $monthly_payment doesn't even cover the
//                          interest, so the balance would grow forever
//   months_to_clear     — total months for the loan to reach zero at this rate/payment
//   total_interest      — total interest payable over the life of the loan
//   balance_after_paid  — real outstanding principal after $emis_paid actual payments
function amortize_loan(float $principal, float $annual_rate, float $monthly_payment, int $emis_paid = 0): array {
    $r       = $annual_rate / 12 / 100;
    $balance = $principal;
    $months  = 0;
    $total_interest     = 0.0;
    $balance_after_paid = $principal;

    while ($balance > 0.5 && $months < 1200) { // 100-year safety cap
        $interest  = $balance * $r;
        $principal_paid = $monthly_payment - $interest;
        if ($principal_paid <= 0) {
            return ['clears' => false, 'months_to_clear' => null, 'total_interest' => null, 'balance_after_paid' => null];
        }
        $balance = max(0, $balance - $principal_paid);
        $total_interest += $interest;
        $months++;
        if ($months === $emis_paid) $balance_after_paid = $balance;
    }
    if ($emis_paid >= $months) $balance_after_paid = 0.0;

    return [
        'clears'             => true,
        'months_to_clear'    => $months,
        'total_interest'     => round($total_interest, 2),
        'balance_after_paid' => round($balance_after_paid, 2),
    ];
}

// ── Handle POST ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name         = trim($_POST['name'] ?? '');
        $lender       = trim($_POST['lender'] ?? '');
        $principal    = (float)($_POST['principal_amount'] ?? 0);
        $remaining    = (float)($_POST['remaining_amount'] ?? 0);
        $monthly      = (float)($_POST['monthly_payment'] ?? 0);
        $rate         = (float)($_POST['interest_rate'] ?? 0);
        $start        = (!empty($_POST['start_date'])) ? $_POST['start_date'] : null;
        $due          = $_POST['due_date'] ?? '';
        $status       = $_POST['status'] ?? 'active';
        $notes        = trim($_POST['notes'] ?? '');
        $payment_mode = $_POST['payment_mode'] ?? 'cash';
        $card_last4   = ($payment_mode === 'card')
            ? substr(preg_replace('/\D/', '', $_POST['card_last4'] ?? ''), -4)
            : null;
        $tenure_val   = (int)($_POST['tenure_value'] ?? 0);
        $tenure_unit  = $_POST['tenure_unit'] ?? 'months';
        $tenure_months = $tenure_val > 0
            ? ($tenure_unit === 'years' ? $tenure_val * 12 : $tenure_val)
            : null;

        // Derive first EMI date from start_date + 1 month (auto-marks past EMIs as paid)
        if (!empty($start)) {
            $dt  = new DateTime($start);
            $dt->modify('+1 month');
            $due = $dt->format('Y-m-d');
        }

        if ($name && ($due || $start)) {
            $stmt = mysqli_prepare($conn,
                'INSERT INTO loans
                    (user_id,name,lender,principal_amount,remaining_amount,monthly_payment,
                     interest_rate,start_date,due_date,status,notes,payment_mode,card_last4,tenure_months)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            mysqli_stmt_bind_param($stmt, 'issddddssssssi',
                $uid, $name, $lender, $principal, $remaining, $monthly, $rate,
                $start, $due, $status, $notes, $payment_mode, $card_last4, $tenure_months);
            mysqli_stmt_execute($stmt);
            $loan_id = (int)mysqli_insert_id($conn);
            mysqli_stmt_close($stmt);

            $emi_count = 0;
            if ($loan_id && $tenure_months && $monthly > 0 && $due) {
                $emi_count = generate_emi_schedule(
                    $conn, $loan_id, $uid, $name, $monthly,
                    $due, $tenure_months, $payment_mode, $card_last4
                );
            }
            AppLogger::action("Loan added: '$name' lender=$lender remaining=₹$remaining mode=$payment_mode tenure=$tenure_months");
            $msg = $emi_count > 0
                ? "success:Loan added. $emi_count EMI entries created in Expenses."
                : 'success:Loan added successfully.';
        }
    }

    if ($action === 'edit') {
        $id           = (int)($_POST['id'] ?? 0);
        $name         = trim($_POST['name'] ?? '');
        $lender       = trim($_POST['lender'] ?? '');
        $principal    = (float)($_POST['principal_amount'] ?? 0);
        $remaining    = (float)($_POST['remaining_amount'] ?? 0);
        $monthly      = (float)($_POST['monthly_payment'] ?? 0);
        $rate         = (float)($_POST['interest_rate'] ?? 0);
        $start        = (!empty($_POST['start_date'])) ? $_POST['start_date'] : null;
        $due          = $_POST['due_date'] ?? '';
        $status       = $_POST['status'] ?? 'active';
        $notes        = trim($_POST['notes'] ?? '');
        $payment_mode = $_POST['payment_mode'] ?? 'cash';
        $card_last4   = ($payment_mode === 'card')
            ? substr(preg_replace('/\D/', '', $_POST['card_last4'] ?? ''), -4)
            : null;
        $tenure_val   = (int)($_POST['tenure_value'] ?? 0);
        $tenure_unit  = $_POST['tenure_unit'] ?? 'months';
        $tenure_months = $tenure_val > 0
            ? ($tenure_unit === 'years' ? $tenure_val * 12 : $tenure_val)
            : null;
        $regen_emi = isset($_POST['regen_emi']);

        // Derive first EMI date from start_date + 1 month (auto-marks past EMIs as paid)
        if (!empty($start)) {
            $dt  = new DateTime($start);
            $dt->modify('+1 month');
            $due = $dt->format('Y-m-d');
        }

        if ($id && $name && ($due || $start)) {
            $stmt = mysqli_prepare($conn,
                'UPDATE loans
                    SET name=?,lender=?,principal_amount=?,remaining_amount=?,monthly_payment=?,
                        interest_rate=?,start_date=?,due_date=?,status=?,notes=?,payment_mode=?,
                        card_last4=?,tenure_months=?
                 WHERE id=? AND user_id=?');
            mysqli_stmt_bind_param($stmt, 'ssddddsssssssii',
                $name, $lender, $principal, $remaining, $monthly, $rate,
                $start, $due, $status, $notes, $payment_mode, $card_last4, $tenure_months,
                $id, $uid);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            $emi_count = 0;
            if ($regen_emi && $tenure_months && $monthly > 0 && $due) {
                $emi_count = generate_emi_schedule(
                    $conn, $id, $uid, $name, $monthly,
                    $due, $tenure_months, $payment_mode, $card_last4
                );
            }
            AppLogger::action("Loan updated: id=$id '$name' status=$status tenure=$tenure_months");
            $msg = $emi_count > 0
                ? "success:Loan updated. $emi_count EMI entries regenerated."
                : 'success:Loan updated.';
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            // Delete pending auto-generated EMI expenses first
            mysqli_query($conn,
                "DELETE FROM expenses WHERE loan_ref_id=$id AND user_id=$uid AND auto_generated=1 AND status='pending'");
            mysqli_query($conn, "DELETE FROM loans WHERE id=$id AND user_id=$uid");
            AppLogger::action("Loan deleted: id=$id");
            $msg = 'success:Loan deleted.';
        }
    }

    if ($action === 'mark_paid') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            mysqli_query($conn, "UPDATE loans SET status='paid' WHERE id=$id AND user_id=$uid");
            // Mark remaining EMI expenses as paid too
            mysqli_query($conn,
                "UPDATE expenses SET status='paid' WHERE loan_ref_id=$id AND user_id=$uid AND auto_generated=1");
            AppLogger::action("Loan marked paid: id=$id");
            $msg = 'success:Loan marked as paid.';
        }
    }

    header('Location: loans.php?msg=' . urlencode($msg));
    exit;
}

$msg = $_GET['msg'] ?? '';

// Auto-mark overdue loans
mysqli_query($conn, "UPDATE loans SET status='overdue'
    WHERE user_id=$uid AND status='active' AND due_date < '$today'");
// EMIs from past years are assumed auto-debited → paid automatically.
// From the current year onward, never silently assume payment was made — flag overdue
// instead, so the Expenses schedule grid asks the user to confirm before marking it paid.
$year_start = date('Y') . '-01-01';
mysqli_query($conn, "UPDATE expenses SET status='paid'
    WHERE user_id=$uid AND auto_generated=1 AND status IN('pending','overdue') AND due_date < '$year_start'");
mysqli_query($conn, "UPDATE expenses SET status='overdue'
    WHERE user_id=$uid AND auto_generated=1 AND status='pending' AND due_date >= '$year_start' AND due_date < '$today'");

// Fetch loans with EMI counts + next pending EMI date
$filter = $_GET['status'] ?? 'all';
$where  = $filter !== 'all'
    ? "AND l.status='" . mysqli_real_escape_string($conn, $filter) . "'"
    : '';
$result = mysqli_query($conn,
    "SELECT l.*,
        (SELECT COUNT(*) FROM expenses e WHERE e.loan_ref_id=l.id AND e.auto_generated=1) AS emi_total,
        (SELECT COUNT(*) FROM expenses e WHERE e.loan_ref_id=l.id AND e.auto_generated=1 AND e.status='paid') AS emi_paid,
        (SELECT COUNT(*) FROM expenses e WHERE e.loan_ref_id=l.id AND e.auto_generated=1 AND e.due_date <= '$today') AS emi_due_by_today,
        (SELECT MIN(e.due_date) FROM expenses e WHERE e.loan_ref_id=l.id AND e.auto_generated=1 AND e.status='pending') AS next_emi_date
     FROM loans l
     WHERE l.user_id=$uid $where ORDER BY l.due_date ASC");
$loans = mysqli_fetch_all($result, MYSQLI_ASSOC);

// ── Real clearance analysis per loan (amortization, not naive subtraction) ──
$clearance = [];
foreach ($loans as $l) {
    $principal = (float)($l['principal_amount'] ?? 0);
    $rate      = (float)($l['interest_rate'] ?? 0);
    $monthly   = (float)($l['monthly_payment'] ?? 0);
    $tracked   = (float)($l['remaining_amount'] ?? 0);
    $paid      = (int)$l['emi_paid'];
    $due       = (int)$l['emi_due_by_today'];
    if ($monthly <= 0 || $l['status'] === 'paid') continue;

    // principal_amount is an optional field on this form — plenty of existing loans
    // never had it recorded. Full history (drift check + EMI check) needs it; without
    // it, fall back to projecting forward from today's tracked balance instead, which
    // still gives a real payoff date rather than none at all.
    $has_principal = $principal > 0;
    $basis         = $has_principal ? $principal : $tracked;
    if ($basis <= 0) continue;
    $emis_elapsed  = $has_principal ? $paid : 0;

    $amort  = amortize_loan($basis, $rate, $monthly, $emis_elapsed);
    $missed = max(0, $due - $paid);
    $calc_emi_val = ($has_principal && $l['tenure_months'])
        ? calc_emi($principal, $rate, (int)$l['tenure_months']) : null;

    $payoff_date = null;
    if ($amort['clears']) {
        // With full history, project from the loan's actual start date; without a
        // recorded principal there's no reliable start point, so project from today.
        $anchor = $has_principal && $l['start_date'] ? $l['start_date'] : $today;
        $payoff_date = (new DateTime($anchor))->modify('+' . $amort['months_to_clear'] . ' months')->format('Y-m-d');
    }

    $clearance[$l['id']] = [
        'has_principal' => $has_principal,
        'missed'        => $missed,
        'calc_emi'      => $calc_emi_val,
        'emi_mismatch'  => $calc_emi_val !== null && abs($calc_emi_val - $monthly) > max(5, $monthly * 0.02),
        'real_balance'  => $amort['clears'] ? $amort['balance_after_paid'] : null,
        'tracked_balance' => $tracked,
        'balance_drift' => ($amort['clears'] && $has_principal) ? round($tracked - $amort['balance_after_paid'], 2) : null,
        'payoff_date'   => $payoff_date,
        'clears'        => $amort['clears'],
        'total_interest'=> $amort['total_interest'],
    ];
}

// Upcoming monthly EMI totals (next 6 months of pending EMIs across all loans)
$upcoming_r = mysqli_query($conn,
    "SELECT DATE_FORMAT(due_date,'%Y-%m') AS month_key,
            DATE_FORMAT(due_date,'%b %Y')  AS month_label,
            SUM(amount)  AS total_amount,
            COUNT(*)     AS emi_count
     FROM expenses
     WHERE user_id=$uid AND auto_generated=1 AND status='pending' AND due_date >= '$today'
     GROUP BY month_key, month_label
     ORDER BY month_key LIMIT 6");
$upcoming_months = mysqli_fetch_all($upcoming_r, MYSQLI_ASSOC);

require_once 'includes/header.php';

function loan_status_badge(string $s): string {
    $map = ['active' => 'badge-active', 'paid' => 'badge-paid', 'overdue' => 'badge-overdue'];
    return '<span class="badge-status ' . ($map[$s] ?? '') . '">' . $s . '</span>';
}

function payment_mode_badge(string $mode, ?string $last4 = null): string {
    $icons  = ['cash' => 'fa-money-bill-wave', 'bank_transfer' => 'fa-building-columns', 'card' => 'fa-credit-card', 'upi' => 'fa-mobile-screen-button'];
    $labels = ['cash' => 'Cash', 'bank_transfer' => 'Bank Transfer', 'card' => 'Card', 'upi' => 'UPI'];
    $icon   = $icons[$mode]  ?? 'fa-circle-question';
    $label  = $labels[$mode] ?? ucfirst($mode);
    if ($mode === 'card' && $last4) $label .= ' ····' . $last4;
    return '<span class="badge bg-light text-dark border" style="font-size:.73rem;white-space:nowrap;">'
         . '<i class="fas ' . $icon . ' me-1"></i>' . $label . '</span>';
}

function tenure_label(?int $months, int $emi_paid = 0, int $emi_total = 0): string {
    if (!$months) return '<span class="text-muted">—</span>';
    $remaining = $emi_total > 0 ? max(0, $emi_total - $emi_paid) : null;
    $total_str = ($months % 12 === 0 && $months >= 12)
        ? ($months / 12) . ' yr' . (($months / 12) > 1 ? 's' : '') . ' <span class="text-muted" style="font-size:.72rem;">(' . $months . ' mo)</span>'
        : $months . ' mo';
    if ($remaining === null) return $total_str;
    if ($remaining === 0)
        return $total_str . ' <span class="badge bg-success" style="font-size:.7rem;">Done</span>';
    return $total_str . '<div style="font-size:.76rem;margin-top:2px;">'
        . '<span class="text-warning fw-semibold">' . $remaining . ' left</span>'
        . ' <span class="text-muted">/ ' . $emi_total . ' total</span></div>';
}
?>

<?php if ($msg): [$t, $text] = explode(':', $msg, 2); ?>
<div class="alert alert-<?= $t === 'success' ? 'success' : 'danger' ?> alert-dismissible fade show" role="alert">
  <i class="fas fa-<?= $t === 'success' ? 'circle-check' : 'circle-exclamation' ?> me-2"></i><?= htmlspecialchars($text) ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-header">
    <h6 class="card-title"><i class="fas fa-hand-holding-dollar me-2 text-primary"></i>Loans</h6>
    <div class="d-flex gap-2 flex-wrap align-items-center">
      <div class="btn-group btn-group-sm">
        <?php foreach (['all', 'active', 'overdue', 'paid'] as $f): ?>
          <a href="?status=<?= $f ?>" class="btn btn-<?= $filter === $f ? 'primary' : 'outline-secondary' ?>"><?= ucfirst($f) ?></a>
        <?php endforeach; ?>
      </div>
      <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addModal">
        <i class="fas fa-plus me-1"></i>Add Loan
      </button>
    </div>
  </div>
  <div class="card-body p-0">
    <div class="table-wrapper">
      <table class="table mb-0">
        <thead>
          <tr>
            <th>#</th><th>Loan Name</th><th>Lender</th>
            <th>Monthly EMI</th><th>Remaining</th>
            <th>Date Opened</th><th>Tenure</th><th>EMI Schedule</th>
            <th>Next EMI Due</th><th>Payment</th><th>Status</th><th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($loans)): ?>
          <tr>
            <td colspan="11" class="text-center py-4 text-muted">
              No loans found. <a href="#" data-bs-toggle="modal" data-bs-target="#addModal">Add your first loan.</a>
            </td>
          </tr>
          <?php else: ?>
          <?php foreach ($loans as $i => $l):
            $emi_total    = (int)$l['emi_total'];
            $emi_paid     = (int)$l['emi_paid'];
            $emi_left     = $emi_total - $emi_paid;
            $next_emi     = $l['next_emi_date'] ?? null;
            $next_days    = $next_emi ? (int)ceil((strtotime($next_emi) - time()) / 86400) : null;
          ?>
          <tr>
            <td class="text-muted"><?= $i + 1 ?></td>
            <td class="fw-semibold">
              <?= htmlspecialchars($l['name']) ?>
              <?php if ($l['notes']): ?>
                <div class="text-muted" style="font-size:.78rem;">
                  <?= htmlspecialchars(strlen((string)$l['notes']) > 40 ? substr((string)$l['notes'], 0, 40) . '…' : (string)$l['notes']) ?>
                </div>
              <?php endif; ?>
            </td>
            <td><?= htmlspecialchars((string)($l['lender'] ?? '–')) ?></td>
            <td class="fw-bold">₹<?= number_format((float)($l['monthly_payment'] ?? 0), 0) ?></td>
            <td class="fw-bold text-primary">₹<?= number_format((float)($l['remaining_amount'] ?? 0), 0) ?></td>
            <td class="text-muted" style="font-size:.85rem">
              <?php if (!empty($l['start_date'])): ?>
                <?= date('d M Y', strtotime($l['start_date'])) ?>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
            <td><?= tenure_label($l['tenure_months'] ? (int)$l['tenure_months'] : null, $emi_paid, $emi_total) ?></td>
            <td>
              <?php if ($emi_total > 0): ?>
                <div style="font-size:.8rem" class="fw-semibold text-<?= $emi_left > 0 ? 'warning' : 'success' ?>">
                  <?= $emi_paid ?>/<?= $emi_total ?> paid
                </div>
                <div class="progress mt-1" style="height:5px;border-radius:3px;min-width:70px">
                  <div class="progress-bar bg-success" style="width:<?= $emi_total > 0 ? round(($emi_paid/$emi_total)*100) : 0 ?>%"></div>
                </div>
                <?php if ($emi_left > 0 && $l['monthly_payment']): ?>
                  <div style="font-size:.75rem;margin-top:3px;" class="text-muted">
                    ₹<?= number_format((float)$l['monthly_payment'], 0) ?>/mo × <?= $emi_left ?> left
                  </div>
                <?php endif; ?>
              <?php else: ?>
                <span class="text-muted" style="font-size:.8rem">No schedule</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($next_emi): ?>
                <div class="fw-semibold" style="font-size:.88rem;">
                  <?= date('d M Y', strtotime($next_emi)) ?>
                </div>
                <div style="font-size:.75rem;" class="text-<?= $next_days < 0 ? 'danger' : ($next_days <= 7 ? 'warning' : ($next_days <= 30 ? 'primary' : 'muted')) ?>">
                  <i class="fas fa-<?= $next_days < 0 ? 'exclamation-triangle' : 'clock' ?> me-1"></i>
                  <?php if ($next_days < 0): ?>
                    <?= abs($next_days) ?> days overdue
                  <?php elseif ($next_days === 0): ?>
                    Due today
                  <?php elseif ($next_days <= 30): ?>
                    in <?= $next_days ?> days
                  <?php else: ?>
                    in <?= $next_days ?> days
                  <?php endif; ?>
                </div>
                <div style="font-size:.74rem;margin-top:2px;" class="text-success fw-semibold">
                  ₹<?= number_format((float)$l['monthly_payment'], 0) ?> due
                </div>
              <?php elseif ($emi_total > 0 && $emi_left === 0): ?>
                <span class="badge bg-success" style="font-size:.75rem;">
                  <i class="fas fa-circle-check me-1"></i>All EMIs Done
                </span>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
            <td><?= payment_mode_badge((string)($l['payment_mode'] ?? 'cash'), $l['card_last4'] ?? null) ?></td>
            <td><?= loan_status_badge($l['status']) ?></td>
            <td>
              <div class="d-flex gap-1 flex-wrap">
                <?php if ($l['status'] !== 'paid'): ?>
                <form method="POST" onsubmit="return confirm('Mark loan + all EMIs as paid?')" class="d-inline">
                  <input type="hidden" name="action" value="mark_paid">
                  <input type="hidden" name="id" value="<?= $l['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-success" title="Mark Paid">
                    <i class="fas fa-circle-check me-1"></i>Paid
                  </button>
                </form>
                <?php endif; ?>
                <button class="btn btn-sm btn-outline-primary"
                  data-bs-toggle="modal" data-bs-target="#editModal"
                  data-id="<?= $l['id'] ?>"
                  data-name="<?= htmlspecialchars($l['name'], ENT_QUOTES) ?>"
                  data-lender="<?= htmlspecialchars((string)($l['lender'] ?? ''), ENT_QUOTES) ?>"
                  data-principal="<?= (float)($l['principal_amount'] ?? 0) ?>"
                  data-remaining="<?= (float)($l['remaining_amount'] ?? 0) ?>"
                  data-monthly="<?= (float)($l['monthly_payment'] ?? 0) ?>"
                  data-rate="<?= (float)($l['interest_rate'] ?? 0) ?>"
                  data-start="<?= (string)($l['start_date'] ?? '') ?>"
                  data-due="<?= (string)($l['due_date'] ?? '') ?>"
                  data-status="<?= $l['status'] ?>"
                  data-notes="<?= htmlspecialchars((string)($l['notes'] ?? ''), ENT_QUOTES) ?>"
                  data-paymode="<?= htmlspecialchars((string)($l['payment_mode'] ?? 'cash'), ENT_QUOTES) ?>"
                  data-last4="<?= htmlspecialchars((string)($l['card_last4'] ?? ''), ENT_QUOTES) ?>"
                  data-tenure="<?= (int)($l['tenure_months'] ?? 0) ?>"
                  data-emitotal="<?= $emi_total ?>"
                  onclick="populateEdit(this)" title="Edit">
                  <i class="fas fa-pen me-1"></i>Edit
                </button>
                <form method="POST" onsubmit="return confirm('Delete this loan and its pending EMI schedule?')" class="d-inline">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= $l['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                    <i class="fas fa-trash me-1"></i>Del
                  </button>
                </form>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ── Upcoming Monthly EMI Totals ───────────────────────────── -->
<?php if (!empty($upcoming_months)): ?>
<div class="card mt-3">
  <div class="card-header">
    <h6 class="card-title mb-0">
      <i class="fas fa-calendar-alt me-2 text-warning"></i>Upcoming Monthly EMI Totals
    </h6>
  </div>
  <div class="card-body py-3">
    <div class="d-flex flex-wrap gap-3">
      <?php foreach ($upcoming_months as $idx => $m):
        $is_current = (date('Y-m') === $m['month_key']);
        $is_next    = (date('Y-m', strtotime('+1 month')) === $m['month_key']);
      ?>
      <div class="text-center px-3 py-2 rounded border <?= $is_current ? 'border-warning bg-warning bg-opacity-10' : ($is_next ? 'border-primary bg-primary bg-opacity-10' : 'bg-light') ?>"
           style="min-width:130px;">
        <div style="font-size:.78rem;font-weight:600;letter-spacing:.4px;" class="text-<?= $is_current ? 'warning' : ($is_next ? 'primary' : 'secondary') ?> text-uppercase">
          <?php if ($is_current): ?>
            <i class="fas fa-circle-dot me-1"></i>This Month
          <?php elseif ($is_next): ?>
            <i class="fas fa-arrow-right me-1"></i>Next Month
          <?php else: ?>
            <?= htmlspecialchars($m['month_label']) ?>
          <?php endif; ?>
        </div>
        <?php if ($is_current || $is_next): ?>
          <div style="font-size:.72rem;" class="text-muted"><?= htmlspecialchars($m['month_label']) ?></div>
        <?php endif; ?>
        <div class="fw-bold mt-1" style="font-size:1.1rem;">₹<?= number_format((float)$m['total_amount'], 0) ?></div>
        <div style="font-size:.72rem;" class="text-muted"><?= $m['emi_count'] ?> EMI<?= $m['emi_count'] > 1 ? 's' : '' ?></div>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="text-muted mt-2" style="font-size:.75rem;">
      <i class="fas fa-info-circle me-1"></i>Showing next 6 months of pending EMIs across all active loans.
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ── Real Clearance Analysis (reducing-balance amortization) ─── -->
<?php if (!empty($clearance)): ?>
<div class="card mt-3">
  <div class="card-header">
    <h6 class="card-title mb-0">
      <i class="fas fa-calculator me-2 text-info"></i>Loan Clearance Analysis
    </h6>
    <span class="text-muted" style="font-size:.78rem;">Interest-adjusted balance &amp; payoff date, not a flat EMI subtraction</span>
  </div>
  <div class="card-body p-0">
    <div class="table-wrapper">
      <table class="table mb-0">
        <thead>
          <tr>
            <th>Loan</th>
            <th>Payments Due vs Confirmed</th>
            <th>EMI Check</th>
            <th>Real Remaining Balance</th>
            <th>Tracked Balance</th>
            <th>Real Payoff Date</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($loans as $l): if (empty($clearance[$l['id']])) continue;
            $c = $clearance[$l['id']];
          ?>
          <tr>
            <td class="fw-semibold"><?= htmlspecialchars($l['name']) ?></td>
            <td>
              <?php if ($c['missed'] > 0): ?>
                <span class="badge-status badge-overdue">
                  <i class="fas fa-triangle-exclamation me-1"></i><?= $c['missed'] ?> unconfirmed
                </span>
              <?php else: ?>
                <span class="badge-status badge-paid"><i class="fas fa-circle-check me-1"></i>Up to date</span>
              <?php endif; ?>
              <div class="text-muted mt-1" style="font-size:.74rem;"><?= (int)$l['emi_due_by_today'] ?> due · <?= (int)$l['emi_paid'] ?> confirmed</div>
            </td>
            <td>
              <?php if ($c['calc_emi'] === null): ?>
                <span class="text-muted">—</span>
              <?php elseif ($c['emi_mismatch']): ?>
                <span class="badge-status badge-pending" title="Entered EMI does not match principal/rate/tenure">
                  <i class="fas fa-circle-exclamation me-1"></i>₹<?= number_format($c['calc_emi'], 0) ?> expected
                </span>
              <?php else: ?>
                <span class="badge-status badge-active"><i class="fas fa-circle-check me-1"></i>Matches</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if (!$c['clears']): ?>
                <span class="text-danger fw-semibold" title="Monthly payment does not cover interest — balance will never clear">
                  <i class="fas fa-exclamation-triangle me-1"></i>Never clears
                </span>
              <?php else: ?>
                <span class="fw-bold">₹<?= number_format($c['real_balance'], 0) ?></span>
                <?php if (!$c['has_principal']): ?>
                  <div class="text-muted" style="font-size:.72rem;">projected from today — no principal on record</div>
                <?php elseif ($c['balance_drift'] !== null && abs($c['balance_drift']) > max(50, $c['real_balance'] * 0.01)): ?>
                  <div style="font-size:.74rem;" class="text-<?= $c['balance_drift'] > 0 ? 'danger' : 'warning' ?>">
                    <?= $c['balance_drift'] > 0 ? 'Tracked is ₹' . number_format($c['balance_drift'], 0) . ' higher' : 'Tracked is ₹' . number_format(abs($c['balance_drift']), 0) . ' lower' ?>
                  </div>
                <?php endif; ?>
              <?php endif; ?>
            </td>
            <td class="text-muted">₹<?= number_format($c['tracked_balance'], 0) ?></td>
            <td>
              <?= $c['payoff_date'] ? date('M Y', strtotime($c['payoff_date'])) : '<span class="text-muted">—</span>' ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="text-muted p-3 pt-2" style="font-size:.75rem;border-top:1px solid var(--border);">
      <i class="fas fa-info-circle me-1"></i>
      "Real Remaining Balance" applies interest to the outstanding principal each month before
      counting the payment toward principal — the "Tracked Balance" field is only ever moved by
      subtracting the full EMI, which overstates how much principal has actually been repaid on
      any interest-bearing loan. "Payments Due vs Confirmed" counts EMI due dates that have passed
      without an explicit paid confirmation — see the Expenses schedule grid to confirm them.
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ── Add Modal ─────────────────────────────────────────────── -->
<div class="modal fade" id="addModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-plus me-2"></i>Add Loan</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" id="addForm">
        <input type="hidden" name="action" value="add">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Loan Name <span class="text-danger">*</span></label>
              <input type="text" name="name" class="form-control" placeholder="e.g. Home Loan" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Lender / Bank</label>
              <input type="text" name="lender" class="form-control" placeholder="e.g. SBI Bank">
            </div>
            <div class="col-md-4">
              <label class="form-label">Principal Amount (₹)</label>
              <input type="number" name="principal_amount" class="form-control" placeholder="0.00" min="0" step="0.01">
            </div>
            <div class="col-md-4">
              <label class="form-label">Remaining Amount (₹)</label>
              <input type="number" name="remaining_amount" class="form-control" placeholder="0.00" min="0" step="0.01">
            </div>
            <div class="col-md-4">
              <label class="form-label">Monthly EMI (₹) <span class="text-danger">*</span></label>
              <input type="number" name="monthly_payment" class="form-control" placeholder="0.00" min="0" step="0.01">
            </div>
            <div class="col-md-4">
              <label class="form-label">Interest Rate (%)</label>
              <input type="number" name="interest_rate" class="form-control" placeholder="0.00" min="0" step="0.01">
            </div>
            <div class="col-md-4">
              <label class="form-label"><i class="fas fa-calendar-day me-1 text-primary"></i>Date Loan Opened <span class="text-danger">*</span></label>
              <input type="date" name="start_date" id="add_start" class="form-control"
                     onchange="onStartDateChange('add')" required>
              <div class="form-text">1st EMI date auto-calculated as next month</div>
            </div>
            <div class="col-md-4">
              <label class="form-label">1st EMI Due Date <small class="text-muted">(auto-filled)</small></label>
              <input type="date" name="due_date" id="add_due" class="form-control" readonly
                     style="background:var(--input-bg);color:var(--text-muted);">
            </div>
            <!-- Tenure -->
            <div class="col-12">
              <div class="alert alert-info py-2 mb-0" style="font-size:.83rem">
                <i class="fas fa-calendar-check me-1"></i>
                <strong>Loan Tenure</strong> — enter the loan opened date + tenure to auto-create monthly EMI entries.
                Past EMIs will be automatically marked <strong>Paid</strong>.
              </div>
            </div>
            <div class="col-md-4">
              <label class="form-label"><i class="fas fa-hourglass-half me-1 text-muted"></i>Tenure Duration</label>
              <input type="number" name="tenure_value" id="add_tenure_val" class="form-control"
                     placeholder="e.g. 6 or 30" min="1" max="600"
                     onchange="updateEmiPreview('add')" oninput="updateEmiPreview('add')">
            </div>
            <div class="col-md-4">
              <label class="form-label">Tenure Unit</label>
              <select name="tenure_unit" id="add_tenure_unit" class="form-control" onchange="updateEmiPreview('add')">
                <option value="months">Months</option>
                <option value="years">Years</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Status</label>
              <select name="status" class="form-control">
                <option value="active">Active</option>
                <option value="paid">Paid</option>
              </select>
            </div>
            <div class="col-12" id="add_emi_preview_wrap" style="display:none">
              <div id="add_emi_preview" class="alert alert-success py-2 mb-0" style="font-size:.83rem"></div>
            </div>
            <!-- Payment mode -->
            <div class="col-md-4">
              <label class="form-label"><i class="fas fa-wallet me-1 text-muted"></i>Payment Mode</label>
              <select name="payment_mode" id="add_paymode" class="form-control" onchange="toggleCard('add')">
                <option value="cash">Cash</option>
                <option value="bank_transfer">Bank Transfer</option>
                <option value="card">Card</option>
                <option value="upi">UPI</option>
              </select>
            </div>
            <div class="col-md-4" id="add_card_wrap" style="display:none;">
              <label class="form-label"><i class="fas fa-credit-card me-1 text-muted"></i>Last 4 Card Digits</label>
              <input type="text" name="card_last4" id="add_card_last4" class="form-control"
                     maxlength="4" pattern="\d{4}" placeholder="1234" inputmode="numeric">
            </div>
            <div class="col-12">
              <label class="form-label">Notes</label>
              <textarea name="notes" class="form-control" rows="2" placeholder="Any additional details…"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Save Loan &amp; Generate Schedule</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ── Edit Modal ────────────────────────────────────────────── -->
<div class="modal fade" id="editModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-pen me-2"></i>Edit Loan</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="id" id="edit_id">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Loan Name <span class="text-danger">*</span></label>
              <input type="text" name="name" id="edit_name" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Lender / Bank</label>
              <input type="text" name="lender" id="edit_lender" class="form-control">
            </div>
            <div class="col-md-4">
              <label class="form-label">Principal Amount (₹)</label>
              <input type="number" name="principal_amount" id="edit_principal" class="form-control" min="0" step="0.01">
            </div>
            <div class="col-md-4">
              <label class="form-label">Remaining Amount (₹)</label>
              <input type="number" name="remaining_amount" id="edit_remaining" class="form-control" min="0" step="0.01">
            </div>
            <div class="col-md-4">
              <label class="form-label">Monthly EMI (₹)</label>
              <input type="number" name="monthly_payment" id="edit_monthly" class="form-control" min="0" step="0.01">
            </div>
            <div class="col-md-4">
              <label class="form-label">Interest Rate (%)</label>
              <input type="number" name="interest_rate" id="edit_rate" class="form-control" min="0" step="0.01">
            </div>
            <div class="col-md-4">
              <label class="form-label"><i class="fas fa-calendar-day me-1 text-primary"></i>Date Loan Opened <span class="text-danger">*</span></label>
              <input type="date" name="start_date" id="edit_start" class="form-control"
                     onchange="onStartDateChange('edit')">
              <div class="form-text">1st EMI = this date + 1 month</div>
            </div>
            <div class="col-md-4">
              <label class="form-label">1st EMI Due Date <small class="text-muted">(auto-filled)</small></label>
              <input type="date" name="due_date" id="edit_due" class="form-control" readonly
                     style="background:var(--input-bg);color:var(--text-muted);">
            </div>
            <!-- Tenure -->
            <div class="col-md-4">
              <label class="form-label"><i class="fas fa-hourglass-half me-1 text-muted"></i>Tenure Duration</label>
              <input type="number" name="tenure_value" id="edit_tenure_val" class="form-control"
                     placeholder="e.g. 6" min="1" max="600"
                     onchange="updateEmiPreview('edit')" oninput="updateEmiPreview('edit')">
            </div>
            <div class="col-md-4">
              <label class="form-label">Tenure Unit</label>
              <select name="tenure_unit" id="edit_tenure_unit" class="form-control" onchange="updateEmiPreview('edit')">
                <option value="months">Months</option>
                <option value="years">Years</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Status</label>
              <select name="status" id="edit_status" class="form-control">
                <option value="active">Active</option>
                <option value="paid">Paid</option>
                <option value="overdue">Overdue</option>
              </select>
            </div>
            <!-- Regen checkbox + live preview -->
            <div class="col-12">
              <div id="edit_emi_preview_wrap" class="alert alert-info py-2 mb-2" style="font-size:.83rem;display:none">
                <div id="edit_emi_preview"></div>
              </div>
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="regen_emi" id="edit_regen_emi">
                <label class="form-check-label" for="edit_regen_emi">
                  <i class="fas fa-rotate me-1 text-warning"></i>
                  Regenerate EMI schedule — rebuilds from Date Opened, marks past months <strong>Paid</strong>
                </label>
              </div>
              <div id="edit_emi_info" class="text-muted mt-1" style="font-size:.8rem"></div>
            </div>
            <!-- Payment mode -->
            <div class="col-md-4">
              <label class="form-label"><i class="fas fa-wallet me-1 text-muted"></i>Payment Mode</label>
              <select name="payment_mode" id="edit_paymode" class="form-control" onchange="toggleCard('edit')">
                <option value="cash">Cash</option>
                <option value="bank_transfer">Bank Transfer</option>
                <option value="card">Card</option>
                <option value="upi">UPI</option>
              </select>
            </div>
            <div class="col-md-4" id="edit_card_wrap" style="display:none;">
              <label class="form-label"><i class="fas fa-credit-card me-1 text-muted"></i>Last 4 Card Digits</label>
              <input type="text" name="card_last4" id="edit_card_last4" class="form-control"
                     maxlength="4" pattern="\d{4}" placeholder="1234" inputmode="numeric">
            </div>
            <div class="col-12">
              <label class="form-label">Notes</label>
              <textarea name="notes" id="edit_notes" class="form-control" rows="2"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Update Loan</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$extra_js = <<<'JS'
<script>
let _populatingEdit = false; // suppress auto-check of regen_emi during populate

// ── Helpers ────────────────────────────────────────────────
function toggleCard(prefix) {
  const sel  = document.getElementById(prefix + '_paymode');
  const wrap = document.getElementById(prefix + '_card_wrap');
  if (!sel || !wrap) return;
  wrap.style.display = sel.value === 'card' ? 'block' : 'none';
  if (sel.value !== 'card') {
    const inp = document.getElementById(prefix + '_card_last4');
    if (inp) inp.value = '';
  }
}

// ── Auto-fill 1st EMI date = start_date + 1 month ─────────
function onStartDateChange(prefix) {
  const startEl = document.getElementById(prefix + '_start');
  const dueEl   = document.getElementById(prefix + '_due');
  if (!startEl || !startEl.value) return;

  // Add 1 month to start date
  const parts = startEl.value.split('-');
  let y = parseInt(parts[0]), m = parseInt(parts[1]) - 1, d = parseInt(parts[2]);
  m += 1;
  if (m > 11) { m = 0; y++; }
  // Keep same day-of-month (clamp to month end)
  const maxDay = new Date(y, m + 1, 0).getDate();
  d = Math.min(d, maxDay);
  const pad = n => String(n).padStart(2, '0');
  dueEl.value = y + '-' + pad(m + 1) + '-' + pad(d);

  updateEmiPreview(prefix);
}

// ── Live EMI preview: paid vs pending count ────────────────
function updateEmiPreview(prefix) {
  const dueEl      = document.getElementById(prefix + '_due');
  const tenureVal  = parseInt(document.getElementById(prefix + '_tenure_val')?.value || '0', 10);
  const tenureUnit = document.getElementById(prefix + '_tenure_unit')?.value || 'months';
  const previewEl  = document.getElementById(prefix + '_emi_preview');
  const wrapEl     = document.getElementById(prefix + '_emi_preview_wrap');

  if (!previewEl || !wrapEl || !dueEl?.value || !tenureVal) {
    if (wrapEl) wrapEl.style.display = 'none';
    return;
  }

  const totalMonths = tenureUnit === 'years' ? tenureVal * 12 : tenureVal;
  const today = new Date();
  today.setHours(0, 0, 0, 0);

  // Simulate dates
  const [fy, fm, fd] = dueEl.value.split('-').map(Number);
  let paid = 0, pending = 0;
  for (let i = 0; i < totalMonths; i++) {
    let em = fm - 1 + i, ey = fy;
    ey += Math.floor(em / 12);
    em  = em % 12;
    const maxD  = new Date(ey, em + 1, 0).getDate();
    const emiDt = new Date(ey, em, Math.min(fd, maxD));
    emiDt < today ? paid++ : pending++;
  }

  previewEl.innerHTML =
    '<i class="fas fa-calendar-check me-1 text-success"></i>' +
    '<strong>' + totalMonths + ' total EMIs</strong> — ' +
    '<span class="text-success fw-semibold">' + paid + ' already paid</span> (past months) + ' +
    '<span class="text-warning fw-semibold">' + pending + ' pending</span> (upcoming months)';

  wrapEl.style.display = 'block';
  // Auto-check regen only when user actively changes fields, not on initial populate
  if (prefix === 'edit' && !_populatingEdit) {
    const cb = document.getElementById('edit_regen_emi');
    if (cb && !cb.checked) cb.checked = true;
  }
}

// ── Populate edit modal ────────────────────────────────────
function populateEdit(btn) {
  _populatingEdit = true;
  document.getElementById('edit_id').value        = btn.dataset.id;
  document.getElementById('edit_name').value      = btn.dataset.name;
  document.getElementById('edit_lender').value    = btn.dataset.lender;
  document.getElementById('edit_principal').value = btn.dataset.principal;
  document.getElementById('edit_remaining').value = btn.dataset.remaining;
  document.getElementById('edit_monthly').value   = btn.dataset.monthly;
  document.getElementById('edit_rate').value      = btn.dataset.rate;
  document.getElementById('edit_start').value     = btn.dataset.start;
  document.getElementById('edit_due').value       = btn.dataset.due;
  document.getElementById('edit_status').value    = btn.dataset.status;
  document.getElementById('edit_notes').value     = btn.dataset.notes;

  // Payment mode + card
  const pm = document.getElementById('edit_paymode');
  pm.value = btn.dataset.paymode || 'cash';
  toggleCard('edit');
  if (pm.value === 'card') {
    document.getElementById('edit_card_last4').value = btn.dataset.last4 || '';
  }

  // Tenure — convert stored months back to user-friendly value
  const tenureMonths = parseInt(btn.dataset.tenure || '0', 10);
  const emiTotal     = parseInt(btn.dataset.emitotal || '0', 10);
  const info         = document.getElementById('edit_emi_info');
  if (tenureMonths > 0) {
    const tval = document.getElementById('edit_tenure_val');
    const tunit = document.getElementById('edit_tenure_unit');
    if (tenureMonths % 12 === 0 && tenureMonths >= 12) {
      tval.value  = tenureMonths / 12;
      tunit.value = 'years';
    } else {
      tval.value  = tenureMonths;
      tunit.value = 'months';
    }
    if (info) info.textContent = 'Current: ' + emiTotal + ' EMI entries. Update "Date Opened" + tick Regenerate to recalculate paid/pending.';
  } else {
    document.getElementById('edit_tenure_val').value  = '';
    document.getElementById('edit_tenure_unit').value = 'months';
    if (info) info.textContent = 'No EMI schedule yet. Enter Date Opened + tenure and save.';
  }
  document.getElementById('edit_regen_emi').checked = false;
  const wrap = document.getElementById('edit_emi_preview_wrap');
  if (wrap) wrap.style.display = 'none';

  // Show live preview — _populatingEdit flag prevents regen from being auto-checked
  updateEmiPreview('edit');
  _populatingEdit = false;
}

document.getElementById('addModal').addEventListener('show.bs.modal', function () {
  document.getElementById('addForm').reset();
  document.getElementById('add_card_wrap').style.display = 'none';
  const pw = document.getElementById('add_emi_preview_wrap');
  if (pw) pw.style.display = 'none';
});
</script>
JS;
require_once 'includes/footer.php';
