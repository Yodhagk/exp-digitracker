<?php
require_once 'includes/auth.php';
require_once 'config.php';
$page_title = 'Loans';
$uid = (int)$_SESSION['id'];
$today = date('Y-m-d');
$msg = '';

// ── Handle POST actions ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name         = trim($_POST['name'] ?? '');
        $lender       = trim($_POST['lender'] ?? '');
        $principal    = (float)($_POST['principal_amount'] ?? 0);
        $remaining    = (float)($_POST['remaining_amount'] ?? 0);
        $monthly      = (float)($_POST['monthly_payment'] ?? 0);
        $rate         = (float)($_POST['interest_rate'] ?? 0);
        $start        = $_POST['start_date'] ?: null;
        $due          = $_POST['due_date'] ?? '';
        $status       = $_POST['status'] ?? 'active';
        $notes        = trim($_POST['notes'] ?? '');
        $payment_mode = $_POST['payment_mode'] ?? 'cash';
        $card_last4   = ($payment_mode === 'card') ? substr(preg_replace('/\D/', '', $_POST['card_last4'] ?? ''), -4) : null;

        if ($name && $due) {
            $stmt = mysqli_prepare($conn,
                'INSERT INTO loans (user_id,name,lender,principal_amount,remaining_amount,monthly_payment,interest_rate,start_date,due_date,status,notes,payment_mode,card_last4)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
            mysqli_stmt_bind_param($stmt, 'issddddssssss',
                $uid,$name,$lender,$principal,$remaining,$monthly,$rate,$start,$due,$status,$notes,$payment_mode,$card_last4);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            AppLogger::action("Loan added: '$name' lender=$lender remaining=₹$remaining mode=$payment_mode");
            $msg = 'success:Loan added successfully.';
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
        $start        = $_POST['start_date'] ?: null;
        $due          = $_POST['due_date'] ?? '';
        $status       = $_POST['status'] ?? 'active';
        $notes        = trim($_POST['notes'] ?? '');
        $payment_mode = $_POST['payment_mode'] ?? 'cash';
        $card_last4   = ($payment_mode === 'card') ? substr(preg_replace('/\D/', '', $_POST['card_last4'] ?? ''), -4) : null;

        if ($id && $name && $due) {
            $stmt = mysqli_prepare($conn,
                'UPDATE loans SET name=?,lender=?,principal_amount=?,remaining_amount=?,monthly_payment=?,interest_rate=?,start_date=?,due_date=?,status=?,notes=?,payment_mode=?,card_last4=?
                 WHERE id=? AND user_id=?');
            mysqli_stmt_bind_param($stmt, 'ssddddssssssii',
                $name,$lender,$principal,$remaining,$monthly,$rate,$start,$due,$status,$notes,$payment_mode,$card_last4,$id,$uid);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            AppLogger::action("Loan updated: id=$id '$name' status=$status mode=$payment_mode");
            $msg = 'success:Loan updated.';
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            mysqli_query($conn, "DELETE FROM loans WHERE id=$id AND user_id=$uid");
            AppLogger::action("Loan deleted: id=$id");
            $msg = 'success:Loan deleted.';
        }
    }

    if ($action === 'mark_paid') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            mysqli_query($conn, "UPDATE loans SET status='paid' WHERE id=$id AND user_id=$uid");
            AppLogger::action("Loan marked paid: id=$id");
            $msg = 'success:Loan marked as paid.';
        }
    }

    header('Location: loans.php?msg=' . urlencode($msg));
    exit;
}

$msg = $_GET['msg'] ?? '';

// Auto-mark overdue
mysqli_query($conn, "UPDATE loans SET status='overdue' WHERE user_id=$uid AND status='active' AND due_date < '$today'");

// ── Fetch loans ─────────────────────────────────────────
$filter = $_GET['status'] ?? 'all';
$where  = $filter !== 'all' ? "AND status='".mysqli_real_escape_string($conn,$filter)."'" : '';
$result = mysqli_query($conn, "SELECT * FROM loans WHERE user_id=$uid $where ORDER BY due_date ASC");
$loans  = mysqli_fetch_all($result, MYSQLI_ASSOC);

require_once 'includes/header.php';

function loan_status_badge($s) {
    $map = ['active'=>'badge-active','paid'=>'badge-paid','overdue'=>'badge-overdue'];
    return '<span class="badge-status '.($map[$s]??'').'">'.$s.'</span>';
}

function payment_mode_badge($mode, $last4 = null) {
    $icons  = ['cash'=>'fa-money-bill-wave','bank_transfer'=>'fa-building-columns','card'=>'fa-credit-card','upi'=>'fa-mobile-screen-button'];
    $labels = ['cash'=>'Cash','bank_transfer'=>'Bank Transfer','card'=>'Card','upi'=>'UPI'];
    $icon   = $icons[$mode] ?? 'fa-circle-question';
    $label  = $labels[$mode] ?? ucfirst((string)$mode);
    if ($mode === 'card' && $last4) $label .= ' ····'.$last4;
    return '<span class="badge bg-light text-dark border" style="font-size:.73rem;white-space:nowrap;"><i class="fas '.$icon.' me-1"></i>'.$label.'</span>';
}
?>

<?php if ($msg): list($type,$text) = explode(':',$msg,2); ?>
<div class="alert alert-<?= $type==='success'?'success':'danger' ?> alert-dismissible fade show" role="alert">
  <i class="fas fa-<?= $type==='success'?'circle-check':'circle-exclamation' ?> me-2"></i><?= htmlspecialchars($text) ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-header">
    <h6 class="card-title"><i class="fas fa-hand-holding-dollar me-2 text-primary"></i>Loans</h6>
    <div class="d-flex gap-2 flex-wrap align-items-center">
      <div class="btn-group btn-group-sm">
        <?php foreach(['all','active','overdue','paid'] as $f): ?>
          <a href="?status=<?= $f ?>" class="btn btn-<?= $filter===$f?'primary':'outline-secondary' ?>"><?= ucfirst($f) ?></a>
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
            <th>Due Date</th><th>Payment Mode</th><th>Status</th><th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($loans)): ?>
          <tr><td colspan="9" class="text-center py-4 text-muted">No loans found. <a href="#" data-bs-toggle="modal" data-bs-target="#addModal">Add your first loan.</a></td></tr>
          <?php else: ?>
          <?php foreach ($loans as $i => $l):
            $days = $l['due_date'] ? (int)ceil((strtotime($l['due_date']) - time()) / 86400) : 0;
          ?>
          <tr>
            <td class="text-muted"><?= $i+1 ?></td>
            <td class="fw-semibold"><?= htmlspecialchars($l['name']) ?>
              <?php if ($l['notes']): ?><div class="text-muted" style="font-size:.78rem;"><?= htmlspecialchars(strlen((string)$l['notes'])>40 ? substr((string)$l['notes'],0,40).'…' : (string)$l['notes']) ?></div><?php endif; ?>
            </td>
            <td><?= htmlspecialchars((string)($l['lender'] ?? '–')) ?></td>
            <td class="fw-bold">₹<?= number_format((float)($l['monthly_payment'] ?? 0),0) ?></td>
            <td class="fw-bold text-primary">₹<?= number_format((float)($l['remaining_amount'] ?? 0),0) ?></td>
            <td>
              <?= $l['due_date'] ? date('d M Y', strtotime($l['due_date'])) : '—' ?>
              <?php if ($l['status'] !== 'paid' && $l['due_date']): ?>
                <div style="font-size:.76rem;" class="text-<?= $days<0?'danger':($days<=7?'warning':'muted') ?>">
                  <?= $days<0?abs($days).' days overdue':($days===0?'Today':$days.' days') ?>
                </div>
              <?php endif; ?>
            </td>
            <td><?= payment_mode_badge((string)($l['payment_mode'] ?? 'cash'), $l['card_last4'] ?? null) ?></td>
            <td><?= loan_status_badge($l['status']) ?></td>
            <td>
              <div class="d-flex gap-1 flex-wrap">
                <?php if ($l['status'] !== 'paid'): ?>
                <form method="POST" onsubmit="return confirm('Mark this loan as paid?')" class="d-inline">
                  <input type="hidden" name="action" value="mark_paid">
                  <input type="hidden" name="id" value="<?= $l['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-success" title="Mark Paid">
                    <i class="fas fa-circle-check me-1"></i>Paid
                  </button>
                </form>
                <?php endif; ?>
                <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editModal"
                  data-id="<?= $l['id'] ?>"
                  data-name="<?= htmlspecialchars($l['name'],ENT_QUOTES) ?>"
                  data-lender="<?= htmlspecialchars((string)($l['lender']??''),ENT_QUOTES) ?>"
                  data-principal="<?= (float)($l['principal_amount'] ?? 0) ?>"
                  data-remaining="<?= (float)($l['remaining_amount'] ?? 0) ?>"
                  data-monthly="<?= (float)($l['monthly_payment'] ?? 0) ?>"
                  data-rate="<?= (float)($l['interest_rate'] ?? 0) ?>"
                  data-start="<?= (string)($l['start_date'] ?? '') ?>"
                  data-due="<?= (string)($l['due_date'] ?? '') ?>"
                  data-status="<?= $l['status'] ?>"
                  data-notes="<?= htmlspecialchars((string)($l['notes']??''),ENT_QUOTES) ?>"
                  data-paymode="<?= htmlspecialchars((string)($l['payment_mode']??'cash'),ENT_QUOTES) ?>"
                  data-last4="<?= htmlspecialchars((string)($l['card_last4']??''),ENT_QUOTES) ?>"
                  onclick="populateEdit(this)" title="Edit">
                  <i class="fas fa-pen me-1"></i>Edit
                </button>
                <form method="POST" onsubmit="return confirm('Delete this loan?')" class="d-inline">
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

<!-- ── Add Modal ── -->
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
              <label class="form-label">Monthly EMI (₹)</label>
              <input type="number" name="monthly_payment" class="form-control" placeholder="0.00" min="0" step="0.01">
            </div>
            <div class="col-md-4">
              <label class="form-label">Interest Rate (%)</label>
              <input type="number" name="interest_rate" class="form-control" placeholder="0.00" min="0" step="0.01">
            </div>
            <div class="col-md-4">
              <label class="form-label">Start Date</label>
              <input type="date" name="start_date" class="form-control">
            </div>
            <div class="col-md-4">
              <label class="form-label">Next Due Date <span class="text-danger">*</span></label>
              <input type="date" name="due_date" class="form-control" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">Status</label>
              <select name="status" class="form-control">
                <option value="active">Active</option>
                <option value="paid">Paid</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Payment Mode</label>
              <select name="payment_mode" id="add_paymode" class="form-control" onchange="toggleCard('add')">
                <option value="cash">Cash</option>
                <option value="bank_transfer">Bank Transfer</option>
                <option value="card">Card</option>
                <option value="upi">UPI</option>
              </select>
            </div>
            <div class="col-md-4" id="add_card_wrap" style="display:none;">
              <label class="form-label">Last 4 Card Digits</label>
              <input type="text" name="card_last4" id="add_card_last4" class="form-control" maxlength="4" pattern="\d{4}" placeholder="1234">
            </div>
            <div class="col-12">
              <label class="form-label">Notes</label>
              <textarea name="notes" class="form-control" rows="2" placeholder="Any additional details…"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Save Loan</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ── Edit Modal ── -->
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
              <label class="form-label">Start Date</label>
              <input type="date" name="start_date" id="edit_start" class="form-control">
            </div>
            <div class="col-md-4">
              <label class="form-label">Next Due Date <span class="text-danger">*</span></label>
              <input type="date" name="due_date" id="edit_due" class="form-control" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">Status</label>
              <select name="status" id="edit_status" class="form-control">
                <option value="active">Active</option>
                <option value="paid">Paid</option>
                <option value="overdue">Overdue</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Payment Mode</label>
              <select name="payment_mode" id="edit_paymode" class="form-control" onchange="toggleCard('edit')">
                <option value="cash">Cash</option>
                <option value="bank_transfer">Bank Transfer</option>
                <option value="card">Card</option>
                <option value="upi">UPI</option>
              </select>
            </div>
            <div class="col-md-4" id="edit_card_wrap" style="display:none;">
              <label class="form-label">Last 4 Card Digits</label>
              <input type="text" name="card_last4" id="edit_card_last4" class="form-control" maxlength="4" pattern="\d{4}" placeholder="1234">
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
function toggleCard(prefix) {
  const sel = document.getElementById(prefix + '_paymode');
  const wrap = document.getElementById(prefix + '_card_wrap');
  wrap.style.display = sel.value === 'card' ? 'block' : 'none';
  if (sel.value !== 'card') document.getElementById(prefix + '_card_last4').value = '';
}
function populateEdit(btn) {
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
  document.getElementById('edit_paymode').value   = btn.dataset.paymode || 'cash';
  document.getElementById('edit_card_last4').value = btn.dataset.last4 || '';
  toggleCard('edit');
}
document.getElementById('addModal').addEventListener('show.bs.modal', function () {
  document.getElementById('addForm').reset();
  document.getElementById('add_card_wrap').style.display = 'none';
});
</script>
JS;
require_once 'includes/footer.php';
?>
