<?php
require_once 'includes/auth.php';
require_once 'config.php';
$page_title = 'Expenses';
$uid  = (int)$_SESSION['id'];
$today = date('Y-m-d');
$msg  = '';

// ── Handle POST ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name      = trim($_POST['name'] ?? '');
        $category  = trim($_POST['category'] ?? 'general');
        $amount    = (float)($_POST['amount'] ?? 0);
        $due_date  = $_POST['due_date'] ?? '';
        $recurring = isset($_POST['is_recurring']) ? 1 : 0;
        $recurrence= $_POST['recurrence'] ?? 'monthly';
        $status    = $_POST['status'] ?? 'pending';
        $notes     = trim($_POST['notes'] ?? '');

        if ($name && $due_date) {
            $stmt = mysqli_prepare($conn,
                'INSERT INTO expenses (user_id,name,category,amount,due_date,is_recurring,recurrence,status,notes)
                 VALUES (?,?,?,?,?,?,?,?,?)');
            mysqli_stmt_bind_param($stmt,'issdsisss',$uid,$name,$category,$amount,$due_date,$recurring,$recurrence,$status,$notes);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $msg = 'success:Expense added.';
        }
    }

    if ($action === 'edit') {
        $id        = (int)($_POST['id'] ?? 0);
        $name      = trim($_POST['name'] ?? '');
        $category  = trim($_POST['category'] ?? 'general');
        $amount    = (float)($_POST['amount'] ?? 0);
        $due_date  = $_POST['due_date'] ?? '';
        $recurring = isset($_POST['is_recurring']) ? 1 : 0;
        $recurrence= $_POST['recurrence'] ?? 'monthly';
        $status    = $_POST['status'] ?? 'pending';
        $notes     = trim($_POST['notes'] ?? '');

        if ($id && $name && $due_date) {
            $stmt = mysqli_prepare($conn,
                'UPDATE expenses SET name=?,category=?,amount=?,due_date=?,is_recurring=?,recurrence=?,status=?,notes=?
                 WHERE id=? AND user_id=?');
            mysqli_stmt_bind_param($stmt,'ssdssissii',$name,$category,$amount,$due_date,$recurring,$recurrence,$status,$notes,$id,$uid);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $msg = 'success:Expense updated.';
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) { mysqli_query($conn,"DELETE FROM expenses WHERE id=$id AND user_id=$uid"); $msg='success:Expense deleted.'; }
    }

    if ($action === 'mark_paid') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) { mysqli_query($conn,"UPDATE expenses SET status='paid' WHERE id=$id AND user_id=$uid"); $msg='success:Marked as paid.'; }
    }

    header('Location: expenses.php?msg='.urlencode($msg));
    exit;
}

$msg = $_GET['msg'] ?? '';

// Auto-overdue
mysqli_query($conn,"UPDATE expenses SET status='overdue' WHERE user_id=$uid AND status='pending' AND due_date < '$today'");

$filter = $_GET['status'] ?? 'all';
$where  = $filter !== 'all' ? "AND status='".mysqli_real_escape_string($conn,$filter)."'" : '';
$result = mysqli_query($conn,"SELECT * FROM expenses WHERE user_id=$uid $where ORDER BY due_date ASC");
$rows   = mysqli_fetch_all($result, MYSQLI_ASSOC);

$categories = ['general','utilities','rent','groceries','subscription','insurance','transport','education','medical','entertainment','other'];

require_once 'includes/header.php';

function exp_badge($s){
    $map=['pending'=>'badge-pending','paid'=>'badge-paid','overdue'=>'badge-overdue'];
    return '<span class="badge-status '.($map[$s]??'').'">'.$s.'</span>';
}
?>

<?php if ($msg): list($t,$text)=explode(':',$msg,2); ?>
<div class="alert alert-<?= $t==='success'?'success':'danger' ?> alert-dismissible fade show">
  <i class="fas fa-<?= $t==='success'?'circle-check':'circle-exclamation' ?> me-2"></i><?= htmlspecialchars($text) ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-header">
    <h6 class="card-title"><i class="fas fa-receipt me-2 text-success"></i>Expenses</h6>
    <div class="d-flex gap-2 flex-wrap align-items-center">
      <div class="btn-group btn-group-sm">
        <?php foreach(['all','pending','overdue','paid'] as $f): ?>
          <a href="?status=<?= $f ?>" class="btn btn-<?= $filter===$f?'primary':'outline-secondary' ?>"><?= ucfirst($f) ?></a>
        <?php endforeach; ?>
      </div>
      <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addModal">
        <i class="fas fa-plus me-1"></i>Add Expense
      </button>
    </div>
  </div>
  <div class="card-body p-0">
    <div class="table-wrapper">
      <table class="table mb-0">
        <thead>
          <tr><th>#</th><th>Name</th><th>Category</th><th>Amount</th><th>Due Date</th><th>Recurring</th><th>Status</th><th>Actions</th></tr>
        </thead>
        <tbody>
          <?php if (empty($rows)): ?>
          <tr><td colspan="8" class="text-center py-4 text-muted">No expenses found. <a href="#" data-bs-toggle="modal" data-bs-target="#addModal">Add one.</a></td></tr>
          <?php else: foreach($rows as $i=>$e):
            $days = (int)ceil((strtotime($e['due_date']) - time()) / 86400); ?>
          <tr>
            <td class="text-muted"><?= $i+1 ?></td>
            <td class="fw-semibold"><?= htmlspecialchars($e['name']) ?>
              <?php if($e['notes']): ?><div class="text-muted" style="font-size:.78rem;"><?= htmlspecialchars(mb_strimwidth($e['notes'],0,40,'…')) ?></div><?php endif; ?>
            </td>
            <td><span class="badge bg-light text-dark border" style="font-size:.75rem;"><?= ucfirst($e['category']) ?></span></td>
            <td class="fw-bold">₹<?= number_format($e['amount'],0) ?></td>
            <td>
              <?= date('d M Y',strtotime($e['due_date'])) ?>
              <?php if($e['status']!=='paid'): ?>
                <div style="font-size:.76rem;" class="text-<?= $days<0?'danger':($days<=7?'warning':'muted') ?>">
                  <?= $days<0?abs($days).' days overdue':($days===0?'Today':$days.' days') ?>
                </div>
              <?php endif; ?>
            </td>
            <td><?= $e['is_recurring'] ? '<span class="badge-status badge-active"><i class="fas fa-rotate me-1"></i>'.ucfirst($e['recurrence']).'</span>' : '<span class="text-muted" style="font-size:.8rem;">One-time</span>' ?></td>
            <td><?= exp_badge($e['status']) ?></td>
            <td>
              <div class="d-flex gap-1">
                <?php if($e['status']!=='paid'): ?>
                <form method="POST" onsubmit="return confirm('Mark as paid?')">
                  <input type="hidden" name="action" value="mark_paid">
                  <input type="hidden" name="id" value="<?= $e['id'] ?>">
                  <button type="submit" class="btn-action pay" title="Mark Paid"><i class="fas fa-circle-check"></i></button>
                </form>
                <?php endif; ?>
                <button class="btn-action edit" data-bs-toggle="modal" data-bs-target="#editModal"
                  data-id="<?= $e['id'] ?>"
                  data-name="<?= htmlspecialchars($e['name'],ENT_QUOTES) ?>"
                  data-category="<?= $e['category'] ?>"
                  data-amount="<?= $e['amount'] ?>"
                  data-due="<?= $e['due_date'] ?>"
                  data-recurring="<?= $e['is_recurring'] ?>"
                  data-recurrence="<?= $e['recurrence'] ?>"
                  data-status="<?= $e['status'] ?>"
                  data-notes="<?= htmlspecialchars($e['notes']??'',ENT_QUOTES) ?>"
                  onclick="populateEdit(this)" title="Edit">
                  <i class="fas fa-pen"></i>
                </button>
                <form method="POST" onsubmit="return confirm('Delete this expense?')">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= $e['id'] ?>">
                  <button type="submit" class="btn-action delete" title="Delete"><i class="fas fa-trash"></i></button>
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

<!-- Add Modal -->
<div class="modal fade" id="addModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-plus me-2"></i>Add Expense</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
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
                <?php foreach($categories as $c): ?><option value="<?= $c ?>"><?= ucfirst($c) ?></option><?php endforeach; ?>
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
            <div class="col-12">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="is_recurring" id="add_recurring" onchange="toggleRecurrence('add')">
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

<!-- Edit Modal -->
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
                <?php foreach($categories as $c): ?><option value="<?= $c ?>"><?= ucfirst($c) ?></option><?php endforeach; ?>
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
            <div class="col-12">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="is_recurring" id="edit_recurring" onchange="toggleRecurrence('edit')">
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
function toggleRecurrence(prefix) {
  const cb = document.getElementById(prefix + '_recurring');
  const wrap = document.getElementById(prefix + '_recurrence_wrap');
  wrap.style.display = cb.checked ? 'block' : 'none';
}
function populateEdit(btn) {
  document.getElementById('edit_id').value       = btn.dataset.id;
  document.getElementById('edit_name').value     = btn.dataset.name;
  document.getElementById('edit_category').value = btn.dataset.category;
  document.getElementById('edit_amount').value   = btn.dataset.amount;
  document.getElementById('edit_due').value      = btn.dataset.due;
  document.getElementById('edit_status').value   = btn.dataset.status;
  document.getElementById('edit_notes').value    = btn.dataset.notes;
  const cb = document.getElementById('edit_recurring');
  cb.checked = btn.dataset.recurring === '1';
  toggleRecurrence('edit');
  if (cb.checked) document.getElementById('edit_recurrence').value = btn.dataset.recurrence;
}
</script>
JS;
require_once 'includes/footer.php';
?>
