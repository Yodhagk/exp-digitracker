<?php
require_once 'includes/auth.php';
require_once 'config.php';
$page_title = 'Expenses';
$uid   = (int)$_SESSION['id'];
$today = date('Y-m-d');
$msg   = '';

// ── Handle POST ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name         = trim($_POST['name'] ?? '');
        $category     = trim($_POST['category'] ?? 'general');
        $amount       = (float)($_POST['amount'] ?? 0);
        $due_date     = $_POST['due_date'] ?? '';
        $recurring    = isset($_POST['is_recurring']) ? 1 : 0;
        $recurrence   = $_POST['recurrence'] ?? 'monthly';
        $status       = $_POST['status'] ?? 'pending';
        $notes        = trim($_POST['notes'] ?? '');
        $payment_mode = $_POST['payment_mode'] ?? 'cash';
        $card_last4   = ($payment_mode === 'card')
            ? substr(preg_replace('/\D/', '', $_POST['card_last4'] ?? ''), -4)
            : null;

        if ($name && $due_date) {
            $stmt = mysqli_prepare($conn,
                'INSERT INTO expenses
                    (user_id,name,category,amount,due_date,is_recurring,recurrence,status,notes,payment_mode,card_last4)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)');
            mysqli_stmt_bind_param($stmt, 'issdsisssss',
                $uid, $name, $category, $amount, $due_date,
                $recurring, $recurrence, $status, $notes, $payment_mode, $card_last4);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            AppLogger::action("Expense added: '$name' category=$category amount=₹$amount mode=$payment_mode");
            $msg = 'success:Expense added.';
        }
    }

    if ($action === 'edit') {
        $id           = (int)($_POST['id'] ?? 0);
        $name         = trim($_POST['name'] ?? '');
        $category     = trim($_POST['category'] ?? 'general');
        $amount       = (float)($_POST['amount'] ?? 0);
        $due_date     = $_POST['due_date'] ?? '';
        $recurring    = isset($_POST['is_recurring']) ? 1 : 0;
        $recurrence   = $_POST['recurrence'] ?? 'monthly';
        $status       = $_POST['status'] ?? 'pending';
        $notes        = trim($_POST['notes'] ?? '');
        $payment_mode = $_POST['payment_mode'] ?? 'cash';
        $card_last4   = ($payment_mode === 'card')
            ? substr(preg_replace('/\D/', '', $_POST['card_last4'] ?? ''), -4)
            : null;

        if ($id && $name && $due_date) {
            $stmt = mysqli_prepare($conn,
                'UPDATE expenses
                    SET name=?,category=?,amount=?,due_date=?,is_recurring=?,recurrence=?,status=?,notes=?,payment_mode=?,card_last4=?
                 WHERE id=? AND user_id=?');
            mysqli_stmt_bind_param($stmt, 'ssdsisssssii',
                $name, $category, $amount, $due_date,
                $recurring, $recurrence, $status, $notes, $payment_mode, $card_last4,
                $id, $uid);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            AppLogger::action("Expense updated: id=$id '$name' amount=₹$amount status=$status mode=$payment_mode");
            $msg = 'success:Expense updated.';
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            mysqli_query($conn, "DELETE FROM expenses WHERE id=$id AND user_id=$uid");
            AppLogger::action("Expense deleted: id=$id");
            $msg = 'success:Expense deleted.';
        }
    }

    if ($action === 'mark_paid') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            mysqli_query($conn, "UPDATE expenses SET status='paid' WHERE id=$id AND user_id=$uid");
            AppLogger::action("Expense marked paid: id=$id");
            $msg = 'success:Marked as paid.';
        }
    }

    header('Location: expenses.php?msg=' . urlencode($msg));
    exit;
}

$msg = $_GET['msg'] ?? '';

// Auto-mark: EMIs from past years are assumed auto-debited → paid automatically.
// From the current year onward, never silently assume payment was made — flag overdue
// instead, so the schedule grid asks the user to confirm each due month before it's marked paid.
$year_start = date('Y') . '-01-01';
mysqli_query($conn, "UPDATE expenses SET status='paid'
    WHERE user_id=$uid AND auto_generated=1 AND status IN('pending','overdue') AND due_date < '$year_start'");
mysqli_query($conn, "UPDATE expenses SET status='overdue'
    WHERE user_id=$uid AND auto_generated=1 AND status='pending' AND due_date >= '$year_start' AND due_date < '$today'");
mysqli_query($conn, "UPDATE expenses SET status='overdue'
    WHERE user_id=$uid AND auto_generated=0 AND status='pending' AND due_date < '$today'");

// ── Year selector ────────────────────────────────────────────
$year = (int)($_GET['year'] ?? date('Y'));
if ($year < 1970 || $year > 2200) $year = (int)date('Y');

$years_r   = mysqli_query($conn, "SELECT DISTINCT YEAR(due_date) AS y FROM expenses WHERE user_id=$uid");
$year_list = array_column(mysqli_fetch_all($years_r, MYSQLI_ASSOC), 'y');
$year_list[] = (int)date('Y');
$year_list[] = $year;
$year_list = array_unique(array_map('intval', $year_list));
rsort($year_list);

// ── Loan EMI schedule grid (selected year) — one row per loan, one column per month ──
$emi_r = mysqli_query($conn,
    "SELECT e.*, COALESCE(l.name, SUBSTRING_INDEX(e.name,' – EMI',1)) AS loan_name
     FROM expenses e LEFT JOIN loans l ON l.id = e.loan_ref_id
     WHERE e.user_id=$uid AND e.auto_generated=1 AND YEAR(e.due_date)=$year
     ORDER BY loan_name, e.due_date");
$schedule = [];
foreach (mysqli_fetch_all($emi_r, MYSQLI_ASSOC) as $r) {
    $key = $r['loan_ref_id'] ? 'loan_' . $r['loan_ref_id'] : 'name_' . $r['loan_name'];
    if (!isset($schedule[$key])) $schedule[$key] = ['label' => $r['loan_name'], 'months' => []];
    $schedule[$key]['months'][(int)date('n', strtotime($r['due_date']))] = $r;
}
uasort($schedule, fn($a, $b) => strcasecmp($a['label'], $b['label']));
$months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

// ── One-time / non-EMI expenses (selected year) ───────────────
$filter = $_GET['status'] ?? 'all';
$where  = $filter !== 'all'
    ? "AND status='" . mysqli_real_escape_string($conn, $filter) . "'"
    : '';
$result = mysqli_query($conn,
    "SELECT * FROM expenses WHERE user_id=$uid AND auto_generated=0 AND YEAR(due_date)=$year $where ORDER BY due_date ASC");
$rows = mysqli_fetch_all($result, MYSQLI_ASSOC);

$categories = [
    'general','utilities','rent','groceries','subscription',
    'insurance','transport','education','medical','entertainment','loan','other',
];

require_once 'includes/header.php';

function exp_badge(string $s): string {
    $map = ['pending' => 'badge-pending', 'paid' => 'badge-paid', 'overdue' => 'badge-overdue'];
    return '<span class="badge-status ' . ($map[$s] ?? '') . '">' . $s . '</span>';
}

function exp_pay_badge(string $mode, ?string $last4 = null): string {
    $icons  = [
        'cash'          => 'fa-money-bill-wave',
        'bank_transfer' => 'fa-building-columns',
        'card'          => 'fa-credit-card',
        'upi'           => 'fa-mobile-screen-button',
    ];
    $labels = [
        'cash'          => 'Cash',
        'bank_transfer' => 'Bank Transfer',
        'card'          => 'Card',
        'upi'           => 'UPI',
    ];
    $icon  = $icons[$mode]  ?? 'fa-circle-question';
    $label = $labels[$mode] ?? ucfirst($mode);
    if ($mode === 'card' && $last4) $label .= ' ····' . $last4;
    return '<span class="badge bg-light text-dark border" style="font-size:.73rem;white-space:nowrap;">'
         . '<i class="fas ' . $icon . ' me-1"></i>' . $label . '</span>';
}
?>

<?php if ($msg): [$t, $text] = explode(':', $msg, 2); ?>
<div class="alert alert-<?= $t === 'success' ? 'success' : 'danger' ?> alert-dismissible fade show">
  <i class="fas fa-<?= $t === 'success' ? 'circle-check' : 'circle-exclamation' ?> me-2"></i>
  <?= htmlspecialchars($text) ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="d-flex gap-2 flex-wrap align-items-center justify-content-between mb-3">
  <form method="GET" class="d-flex align-items-center gap-2">
    <label class="form-label mb-0 text-muted" style="font-size:.85rem;white-space:nowrap;">
      <i class="fas fa-calendar me-1"></i>Year
    </label>
    <select name="year" class="form-control form-control-sm" style="width:auto;" onchange="this.form.submit()">
      <?php foreach ($year_list as $y): ?>
        <option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= $y ?></option>
      <?php endforeach; ?>
    </select>
    <?php if ($filter !== 'all'): ?><input type="hidden" name="status" value="<?= htmlspecialchars($filter) ?>"><?php endif; ?>
  </form>
  <div class="d-flex gap-2 flex-wrap align-items-center">
    <div class="btn-group btn-group-sm">
      <?php foreach (['all', 'pending', 'overdue', 'paid'] as $f): ?>
        <a href="?year=<?= $year ?>&status=<?= $f ?>" class="btn btn-<?= $filter === $f ? 'primary' : 'outline-secondary' ?>"><?= ucfirst($f) ?></a>
      <?php endforeach; ?>
    </div>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addModal">
      <i class="fas fa-plus me-1"></i>Add Expense
    </button>
  </div>
</div>

<!-- ── Loan EMI Schedule Grid ───────────────────────────────── -->
<div class="card mb-3">
  <div class="card-header">
    <h6 class="card-title"><i class="fas fa-calendar-check me-2" style="color:#8b5cf6"></i>Loan EMI Schedule — <?= $year ?></h6>
    <span class="text-muted" style="font-size:.78rem;">
      <i class="fas fa-circle-check me-1"></i>Click <i class="fas fa-check"></i> on a due month to confirm the payment
    </span>
  </div>
  <div class="card-body p-0">
    <?php if (empty($schedule)): ?>
      <div class="text-center py-4 text-muted">No loan EMIs scheduled in <?= $year ?>.</div>
    <?php else: ?>
    <div class="table-wrapper">
      <table class="table schedule-grid mb-0">
        <thead>
          <tr>
            <th>Loan</th>
            <?php foreach ($months as $m): ?><th class="text-center"><?= $m ?></th><?php endforeach; ?>
            <th class="text-end">Total</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($schedule as $s): $row_total = 0; ?>
          <tr>
            <td class="fw-semibold"><?= htmlspecialchars($s['label']) ?></td>
            <?php for ($m = 1; $m <= 12; $m++):
              $cell = $s['months'][$m] ?? null;
              if ($cell) $row_total += (float)$cell['amount'];
            ?>
              <td class="sched-cell <?= $cell ? 'sched-' . $cell['status'] : 'sched-empty' ?>">
                <?php if ($cell): ?>
                  <div class="sched-amt">₹<?= number_format((float)$cell['amount'], 0) ?></div>
                  <?php if ($cell['status'] !== 'paid'): ?>
                    <form method="POST" class="d-inline"
                          onsubmit="return confirm('Confirm payment:\n<?= htmlspecialchars(addslashes($s['label']), ENT_QUOTES) ?> — <?= $months[$m - 1] ?> <?= $year ?>\n\nMark this EMI as paid?')">
                      <input type="hidden" name="action" value="mark_paid">
                      <input type="hidden" name="id" value="<?= $cell['id'] ?>">
                      <button type="submit" class="sched-btn pay" title="Confirm payment"><i class="fas fa-check"></i></button>
                    </form>
                  <?php else: ?>
                    <i class="fas fa-circle-check text-success" title="Paid"></i>
                  <?php endif; ?>
                <?php else: ?>
                  <span class="text-muted">—</span>
                <?php endif; ?>
              </td>
            <?php endfor; ?>
            <td class="text-end fw-bold">₹<?= number_format($row_total, 0) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- ── Other Expenses ───────────────────────────────────────── -->
<div class="card">
  <div class="card-header">
    <h6 class="card-title"><i class="fas fa-receipt me-2 text-success"></i>Other Expenses — <?= $year ?></h6>
  </div>
  <div class="card-body p-0">
    <div class="table-wrapper">
      <table class="table mb-0">
        <thead>
          <tr>
            <th>#</th><th>Name</th><th>Category</th><th>Amount</th>
            <th>Due Date</th><th>Payment</th><th>Recurring</th><th>Status</th><th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($rows)): ?>
          <tr>
            <td colspan="9" class="text-center py-4 text-muted">
              No expenses found in <?= $year ?>. <a href="#" data-bs-toggle="modal" data-bs-target="#addModal">Add one.</a>
            </td>
          </tr>
          <?php else: foreach ($rows as $i => $e):
            $days = $e['due_date']
                ? (int)ceil((strtotime($e['due_date']) - time()) / 86400)
                : 0;
          ?>
          <tr>
            <td class="text-muted"><?= $i + 1 ?></td>
            <td class="fw-semibold">
              <?= htmlspecialchars((string)($e['name'] ?? '')) ?>
              <?php if (!empty($e['notes'])): ?>
                <div class="text-muted" style="font-size:.78rem;">
                  <?= htmlspecialchars(strlen((string)$e['notes']) > 40
                      ? substr((string)$e['notes'], 0, 40) . '…'
                      : (string)$e['notes']) ?>
                </div>
              <?php endif; ?>
            </td>
            <td>
              <span class="badge bg-light text-dark border" style="font-size:.75rem;">
                <?= htmlspecialchars(ucfirst((string)($e['category'] ?? 'general'))) ?>
              </span>
            </td>
            <td class="fw-bold">₹<?= number_format((float)($e['amount'] ?? 0), 0) ?></td>
            <td>
              <?= $e['due_date'] ? date('d M Y', strtotime($e['due_date'])) : '—' ?>
              <?php if ($e['status'] !== 'paid' && $e['due_date']): ?>
                <div style="font-size:.76rem;" class="text-<?= $days < 0 ? 'danger' : ($days <= 7 ? 'warning' : 'muted') ?>">
                  <?= $days < 0
                      ? abs($days) . ' days overdue'
                      : ($days === 0 ? 'Today' : $days . ' days') ?>
                </div>
              <?php endif; ?>
            </td>
            <td><?= exp_pay_badge((string)($e['payment_mode'] ?? 'cash'), $e['card_last4'] ?? null) ?></td>
            <td>
              <?= $e['is_recurring']
                  ? '<span class="badge-status badge-active"><i class="fas fa-rotate me-1"></i>'
                    . ucfirst((string)($e['recurrence'] ?? 'monthly')) . '</span>'
                  : '<span class="text-muted" style="font-size:.8rem;">One-time</span>' ?>
            </td>
            <td><?= exp_badge((string)($e['status'] ?? 'pending')) ?></td>
            <td>
              <div class="d-flex gap-1 flex-wrap">
                <?php if ($e['status'] !== 'paid'): ?>
                <form method="POST" onsubmit="return confirm('Mark as paid?')" class="d-inline">
                  <input type="hidden" name="action" value="mark_paid">
                  <input type="hidden" name="id" value="<?= $e['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-success" title="Mark Paid">
                    <i class="fas fa-circle-check me-1"></i>Paid
                  </button>
                </form>
                <?php endif; ?>
                <button class="btn btn-sm btn-outline-primary"
                  data-bs-toggle="modal" data-bs-target="#editModal"
                  data-id="<?= $e['id'] ?>"
                  data-name="<?= htmlspecialchars((string)($e['name'] ?? ''), ENT_QUOTES) ?>"
                  data-category="<?= htmlspecialchars((string)($e['category'] ?? 'general'), ENT_QUOTES) ?>"
                  data-amount="<?= (float)($e['amount'] ?? 0) ?>"
                  data-due="<?= (string)($e['due_date'] ?? '') ?>"
                  data-recurring="<?= (int)($e['is_recurring'] ?? 0) ?>"
                  data-recurrence="<?= htmlspecialchars((string)($e['recurrence'] ?? 'monthly'), ENT_QUOTES) ?>"
                  data-status="<?= htmlspecialchars((string)($e['status'] ?? 'pending'), ENT_QUOTES) ?>"
                  data-notes="<?= htmlspecialchars((string)($e['notes'] ?? ''), ENT_QUOTES) ?>"
                  data-paymode="<?= htmlspecialchars((string)($e['payment_mode'] ?? 'cash'), ENT_QUOTES) ?>"
                  data-last4="<?= htmlspecialchars((string)($e['card_last4'] ?? ''), ENT_QUOTES) ?>"
                  onclick="populateEdit(this)" title="Edit">
                  <i class="fas fa-pen me-1"></i>Edit
                </button>
                <form method="POST" onsubmit="return confirm('Delete this expense?')" class="d-inline">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= $e['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                    <i class="fas fa-trash me-1"></i>Del
                  </button>
                </form>
              </div>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ── Add Modal ─────────────────────────────────────────────── -->
<div class="modal fade" id="addModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-plus me-2"></i>Add Expense</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" id="addForm">
        <input type="hidden" name="action" value="add">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Expense Name <span class="text-danger">*</span></label>
              <input type="text" name="name" class="form-control" placeholder="e.g. Electricity Bill" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Category</label>
              <select name="category" class="form-control">
                <?php foreach ($categories as $c): ?>
                  <option value="<?= $c ?>"><?= ucfirst($c) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Amount (₹) <span class="text-danger">*</span></label>
              <input type="number" name="amount" class="form-control" placeholder="0.00" min="0" step="0.01" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">Due Date <span class="text-danger">*</span></label>
              <input type="date" name="due_date" class="form-control" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">Status</label>
              <select name="status" class="form-control">
                <option value="pending">Pending</option>
                <option value="paid">Paid</option>
              </select>
            </div>
            <!-- Payment mode row -->
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
            <!-- Recurring -->
            <div class="col-12">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="is_recurring"
                       id="add_recurring" onchange="toggleRecurrence('add')">
                <label class="form-check-label" for="add_recurring">Recurring expense</label>
              </div>
            </div>
            <div class="col-md-4" id="add_recurrence_wrap" style="display:none;">
              <label class="form-label">Recurrence</label>
              <select name="recurrence" class="form-control">
                <option value="weekly">Weekly</option>
                <option value="monthly" selected>Monthly</option>
                <option value="quarterly">Quarterly</option>
                <option value="yearly">Yearly</option>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label">Notes</label>
              <textarea name="notes" class="form-control" rows="2" placeholder="Optional notes…"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Save Expense</button>
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
        <h5 class="modal-title"><i class="fas fa-pen me-2"></i>Edit Expense</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="id" id="edit_id">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Expense Name <span class="text-danger">*</span></label>
              <input type="text" name="name" id="edit_name" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Category</label>
              <select name="category" id="edit_category" class="form-control">
                <?php foreach ($categories as $c): ?>
                  <option value="<?= $c ?>"><?= ucfirst($c) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Amount (₹)</label>
              <input type="number" name="amount" id="edit_amount" class="form-control" min="0" step="0.01">
            </div>
            <div class="col-md-4">
              <label class="form-label">Due Date</label>
              <input type="date" name="due_date" id="edit_due" class="form-control">
            </div>
            <div class="col-md-4">
              <label class="form-label">Status</label>
              <select name="status" id="edit_status" class="form-control">
                <option value="pending">Pending</option>
                <option value="paid">Paid</option>
                <option value="overdue">Overdue</option>
              </select>
            </div>
            <!-- Payment mode row -->
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
            <!-- Recurring -->
            <div class="col-12">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="is_recurring"
                       id="edit_recurring" onchange="toggleRecurrence('edit')">
                <label class="form-check-label" for="edit_recurring">Recurring expense</label>
              </div>
            </div>
            <div class="col-md-4" id="edit_recurrence_wrap" style="display:none;">
              <label class="form-label">Recurrence</label>
              <select name="recurrence" id="edit_recurrence" class="form-control">
                <option value="weekly">Weekly</option>
                <option value="monthly">Monthly</option>
                <option value="quarterly">Quarterly</option>
                <option value="yearly">Yearly</option>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label">Notes</label>
              <textarea name="notes" id="edit_notes" class="form-control" rows="2"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Update</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$extra_js = <<<'JS'
<script>
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
function toggleRecurrence(prefix) {
  const cb   = document.getElementById(prefix + '_recurring');
  const wrap = document.getElementById(prefix + '_recurrence_wrap');
  if (!cb || !wrap) return;
  wrap.style.display = cb.checked ? 'block' : 'none';
}
function populateEdit(btn) {
  document.getElementById('edit_id').value       = btn.dataset.id;
  document.getElementById('edit_name').value     = btn.dataset.name;
  document.getElementById('edit_amount').value   = btn.dataset.amount;
  document.getElementById('edit_due').value      = btn.dataset.due;
  document.getElementById('edit_status').value   = btn.dataset.status;
  document.getElementById('edit_notes').value    = btn.dataset.notes;
  document.getElementById('edit_category').value = btn.dataset.category;

  // Payment mode + card
  const pm = document.getElementById('edit_paymode');
  if (pm) {
    pm.value = btn.dataset.paymode || 'cash';
    toggleCard('edit');
    if (pm.value === 'card') {
      const l4 = document.getElementById('edit_card_last4');
      if (l4) l4.value = btn.dataset.last4 || '';
    }
  }

  // Recurring
  const cb = document.getElementById('edit_recurring');
  cb.checked = btn.dataset.recurring === '1';
  toggleRecurrence('edit');
  if (cb.checked) {
    document.getElementById('edit_recurrence').value = btn.dataset.recurrence;
  }
}
// Reset add modal on open
document.getElementById('addModal').addEventListener('show.bs.modal', function () {
  this.querySelector('form').reset();
  document.getElementById('add_recurrence_wrap').style.display = 'none';
  document.getElementById('add_card_wrap').style.display = 'none';
});
</script>
JS;
require_once 'includes/footer.php';
