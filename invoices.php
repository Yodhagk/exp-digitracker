<?php
require_once 'includes/auth.php';
require_once 'config.php';
$page_title = 'Invoices';
$uid  = (int)$_SESSION['id'];
$today = date('Y-m-d');
$msg  = '';

$upload_dir = __DIR__ . '/uploads/invoices/';
$allowed_ext = ['pdf','jpg','jpeg','png'];
$max_size = 5 * 1024 * 1024; // 5 MB

function save_file($file, $upload_dir, $allowed_ext, $max_size) {
    if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > $max_size) return null;
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed_ext, true)) return null;
    $fname = uniqid('inv_', true) . '.' . $ext;
    if (move_uploaded_file($file['tmp_name'], $upload_dir . $fname)) return $fname;
    return null;
}

// ── Handle POST ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $inv_no  = trim($_POST['invoice_number'] ?? '');
        $vendor  = trim($_POST['vendor_name'] ?? '');
        $desc    = trim($_POST['description'] ?? '');
        $amount  = (float)($_POST['amount'] ?? 0);
        $inv_dt  = $_POST['invoice_date'] ?: null;
        $due_dt  = $_POST['due_date'] ?: null;
        $status  = $_POST['status'] ?? 'unpaid';
        $notes   = trim($_POST['notes'] ?? '');
        $file_path = null;

        if (!empty($_FILES['invoice_file']['name'])) {
            $file_path = save_file($_FILES['invoice_file'], $upload_dir, $allowed_ext, $max_size);
        }

        if ($vendor) {
            $stmt = mysqli_prepare($conn,
                'INSERT INTO invoices (user_id,invoice_number,vendor_name,description,amount,invoice_date,due_date,status,file_path,notes)
                 VALUES (?,?,?,?,?,?,?,?,?,?)');
            mysqli_stmt_bind_param($stmt,'isssdsssss',$uid,$inv_no,$vendor,$desc,$amount,$inv_dt,$due_dt,$status,$file_path,$notes);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $msg = 'success:Invoice added.';
        }
    }

    if ($action === 'edit') {
        $id      = (int)($_POST['id'] ?? 0);
        $inv_no  = trim($_POST['invoice_number'] ?? '');
        $vendor  = trim($_POST['vendor_name'] ?? '');
        $desc    = trim($_POST['description'] ?? '');
        $amount  = (float)($_POST['amount'] ?? 0);
        $inv_dt  = $_POST['invoice_date'] ?: null;
        $due_dt  = $_POST['due_date'] ?: null;
        $status  = $_POST['status'] ?? 'unpaid';
        $notes   = trim($_POST['notes'] ?? '');
        $old_file= trim($_POST['old_file'] ?? '');
        $file_path = $old_file ?: null;

        if (!empty($_FILES['invoice_file']['name'])) {
            $new = save_file($_FILES['invoice_file'], $upload_dir, $allowed_ext, $max_size);
            if ($new) {
                if ($old_file && file_exists($upload_dir.$old_file)) unlink($upload_dir.$old_file);
                $file_path = $new;
            }
        }

        if ($id && $vendor) {
            $stmt = mysqli_prepare($conn,
                'UPDATE invoices SET invoice_number=?,vendor_name=?,description=?,amount=?,invoice_date=?,due_date=?,status=?,file_path=?,notes=?
                 WHERE id=? AND user_id=?');
            mysqli_stmt_bind_param($stmt,'sssdsssssii',$inv_no,$vendor,$desc,$amount,$inv_dt,$due_dt,$status,$file_path,$notes,$id,$uid);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $msg = 'success:Invoice updated.';
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $r = mysqli_query($conn,"SELECT file_path FROM invoices WHERE id=$id AND user_id=$uid");
            $row = mysqli_fetch_assoc($r);
            if ($row && $row['file_path'] && file_exists($upload_dir.$row['file_path'])) unlink($upload_dir.$row['file_path']);
            mysqli_query($conn,"DELETE FROM invoices WHERE id=$id AND user_id=$uid");
            $msg = 'success:Invoice deleted.';
        }
    }

    if ($action === 'mark_paid') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) { mysqli_query($conn,"UPDATE invoices SET status='paid' WHERE id=$id AND user_id=$uid"); $msg='success:Marked as paid.'; }
    }

    header('Location: invoices.php?msg='.urlencode($msg));
    exit;
}

$msg = $_GET['msg'] ?? '';

// Auto-overdue
mysqli_query($conn,"UPDATE invoices SET status='overdue' WHERE user_id=$uid AND status='unpaid' AND due_date < '$today'");

$filter = $_GET['status'] ?? 'all';
$where  = $filter !== 'all' ? "AND status='".mysqli_real_escape_string($conn,$filter)."'" : '';
$result = mysqli_query($conn,"SELECT * FROM invoices WHERE user_id=$uid $where ORDER BY due_date ASC, created_at DESC");
$rows   = mysqli_fetch_all($result, MYSQLI_ASSOC);

require_once 'includes/header.php';

function inv_badge($s){
    $map=['unpaid'=>'badge-unpaid','paid'=>'badge-paid','overdue'=>'badge-overdue'];
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
    <h6 class="card-title"><i class="fas fa-file-invoice-dollar me-2 text-warning"></i>Invoices</h6>
    <div class="d-flex gap-2 flex-wrap align-items-center">
      <div class="btn-group btn-group-sm">
        <?php foreach(['all','unpaid','overdue','paid'] as $f): ?>
          <a href="?status=<?= $f ?>" class="btn btn-<?= $filter===$f?'primary':'outline-secondary' ?>"><?= ucfirst($f) ?></a>
        <?php endforeach; ?>
      </div>
      <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addModal">
        <i class="fas fa-plus me-1"></i>Add Invoice
      </button>
    </div>
  </div>
  <div class="card-body p-0">
    <div class="table-wrapper">
      <table class="table mb-0">
        <thead>
          <tr><th>#</th><th>Invoice #</th><th>Vendor</th><th>Description</th><th>Amount</th><th>Invoice Date</th><th>Due Date</th><th>Status</th><th>File</th><th>Actions</th></tr>
        </thead>
        <tbody>
          <?php if (empty($rows)): ?>
          <tr><td colspan="10" class="text-center py-4 text-muted">No invoices yet. <a href="#" data-bs-toggle="modal" data-bs-target="#addModal">Add one.</a></td></tr>
          <?php else: foreach($rows as $i=>$inv):
            $days = $inv['due_date'] ? (int)ceil((strtotime($inv['due_date']) - time()) / 86400) : 9999;
          ?>
          <tr>
            <td class="text-muted"><?= $i+1 ?></td>
            <td class="fw-semibold"><?= htmlspecialchars($inv['invoice_number'] ?: '–') ?></td>
            <td class="fw-semibold"><?= htmlspecialchars($inv['vendor_name']) ?></td>
            <td class="text-muted" style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($inv['description'] ?: '–') ?></td>
            <td class="fw-bold">₹<?= number_format($inv['amount'],0) ?></td>
            <td><?= $inv['invoice_date'] ? date('d M Y',strtotime($inv['invoice_date'])) : '–' ?></td>
            <td>
              <?= $inv['due_date'] ? date('d M Y',strtotime($inv['due_date'])) : '–' ?>
              <?php if ($inv['due_date'] && $inv['status']!=='paid'): ?>
                <div style="font-size:.76rem;" class="text-<?= $days<0?'danger':($days<=7?'warning':'muted') ?>">
                  <?= $days<0?abs($days).' days overdue':($days===0?'Today':$days.' days') ?>
                </div>
              <?php endif; ?>
            </td>
            <td><?= inv_badge($inv['status']) ?></td>
            <td>
              <?php if ($inv['file_path']): ?>
                <a href="uploads/invoices/<?= htmlspecialchars($inv['file_path']) ?>" target="_blank" class="file-link">
                  <i class="fas fa-file-arrow-down"></i>View
                </a>
              <?php else: ?>
                <span class="no-file"><i class="fas fa-minus"></i></span>
              <?php endif; ?>
            </td>
            <td>
              <div class="d-flex gap-1">
                <?php if($inv['status']!=='paid'): ?>
                <form method="POST" onsubmit="return confirm('Mark as paid?')">
                  <input type="hidden" name="action" value="mark_paid">
                  <input type="hidden" name="id" value="<?= $inv['id'] ?>">
                  <button type="submit" class="btn-action pay" title="Mark Paid"><i class="fas fa-circle-check"></i></button>
                </form>
                <?php endif; ?>
                <button class="btn-action edit" data-bs-toggle="modal" data-bs-target="#editModal"
                  data-id="<?= $inv['id'] ?>"
                  data-invno="<?= htmlspecialchars($inv['invoice_number']??'',ENT_QUOTES) ?>"
                  data-vendor="<?= htmlspecialchars($inv['vendor_name'],ENT_QUOTES) ?>"
                  data-desc="<?= htmlspecialchars($inv['description']??'',ENT_QUOTES) ?>"
                  data-amount="<?= $inv['amount'] ?>"
                  data-invdate="<?= $inv['invoice_date'] ?>"
                  data-due="<?= $inv['due_date'] ?>"
                  data-status="<?= $inv['status'] ?>"
                  data-notes="<?= htmlspecialchars($inv['notes']??'',ENT_QUOTES) ?>"
                  data-file="<?= htmlspecialchars($inv['file_path']??'',ENT_QUOTES) ?>"
                  onclick="populateEdit(this)" title="Edit"><i class="fas fa-pen"></i>
                </button>
                <form method="POST" onsubmit="return confirm('Delete this invoice?')">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= $inv['id'] ?>">
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
        <h5 class="modal-title"><i class="fas fa-plus me-2"></i>Add Invoice</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="action" value="add">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label">Invoice Number</label>
              <input type="text" name="invoice_number" class="form-control" placeholder="INV-001">
            </div>
            <div class="col-md-8">
              <label class="form-label">Vendor / Company <span class="text-danger">*</span></label>
              <input type="text" name="vendor_name" class="form-control" placeholder="Vendor name" required>
            </div>
            <div class="col-12">
              <label class="form-label">Description</label>
              <input type="text" name="description" class="form-control" placeholder="What is this invoice for?">
            </div>
            <div class="col-md-4">
              <label class="form-label">Amount (₹) <span class="text-danger">*</span></label>
              <input type="number" name="amount" class="form-control" placeholder="0.00" min="0" step="0.01" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">Invoice Date</label>
              <input type="date" name="invoice_date" class="form-control">
            </div>
            <div class="col-md-4">
              <label class="form-label">Due Date</label>
              <input type="date" name="due_date" class="form-control">
            </div>
            <div class="col-md-4">
              <label class="form-label">Status</label>
              <select name="status" class="form-control">
                <option value="unpaid">Unpaid</option>
                <option value="paid">Paid</option>
              </select>
            </div>
            <div class="col-md-8">
              <label class="form-label">Attach Invoice <span class="text-muted" style="font-weight:400;">(PDF, JPG, PNG · max 5MB)</span></label>
              <input type="file" name="invoice_file" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
            </div>
            <div class="col-12">
              <label class="form-label">Notes</label>
              <textarea name="notes" class="form-control" rows="2"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Save Invoice</button>
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
        <h5 class="modal-title"><i class="fas fa-pen me-2"></i>Edit Invoice</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="id" id="edit_id">
        <input type="hidden" name="old_file" id="edit_old_file">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label">Invoice Number</label>
              <input type="text" name="invoice_number" id="edit_invno" class="form-control">
            </div>
            <div class="col-md-8">
              <label class="form-label">Vendor / Company <span class="text-danger">*</span></label>
              <input type="text" name="vendor_name" id="edit_vendor" class="form-control" required>
            </div>
            <div class="col-12">
              <label class="form-label">Description</label>
              <input type="text" name="description" id="edit_desc" class="form-control">
            </div>
            <div class="col-md-4">
              <label class="form-label">Amount (₹)</label>
              <input type="number" name="amount" id="edit_amount" class="form-control" min="0" step="0.01">
            </div>
            <div class="col-md-4">
              <label class="form-label">Invoice Date</label>
              <input type="date" name="invoice_date" id="edit_invdate" class="form-control">
            </div>
            <div class="col-md-4">
              <label class="form-label">Due Date</label>
              <input type="date" name="due_date" id="edit_due" class="form-control">
            </div>
            <div class="col-md-4">
              <label class="form-label">Status</label>
              <select name="status" id="edit_status" class="form-control">
                <option value="unpaid">Unpaid</option>
                <option value="paid">Paid</option>
                <option value="overdue">Overdue</option>
              </select>
            </div>
            <div class="col-md-8">
              <label class="form-label">Replace File <span class="text-muted" style="font-weight:400;">(leave empty to keep existing)</span></label>
              <div id="edit_current_file" class="mb-1" style="font-size:.82rem;"></div>
              <input type="file" name="invoice_file" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
            </div>
            <div class="col-12">
              <label class="form-label">Notes</label>
              <textarea name="notes" id="edit_notes" class="form-control" rows="2"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Update Invoice</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$extra_js = <<<'JS'
<script>
function populateEdit(btn) {
  document.getElementById('edit_id').value       = btn.dataset.id;
  document.getElementById('edit_invno').value    = btn.dataset.invno;
  document.getElementById('edit_vendor').value   = btn.dataset.vendor;
  document.getElementById('edit_desc').value     = btn.dataset.desc;
  document.getElementById('edit_amount').value   = btn.dataset.amount;
  document.getElementById('edit_invdate').value  = btn.dataset.invdate;
  document.getElementById('edit_due').value      = btn.dataset.due;
  document.getElementById('edit_status').value   = btn.dataset.status;
  document.getElementById('edit_notes').value    = btn.dataset.notes;
  document.getElementById('edit_old_file').value = btn.dataset.file;
  const cf = document.getElementById('edit_current_file');
  cf.innerHTML = btn.dataset.file
    ? '<i class="fas fa-paperclip me-1 text-primary"></i>Current: ' + btn.dataset.file
    : '<span class="text-muted">No file attached</span>';
}
</script>
JS;
require_once 'includes/footer.php';
?>
