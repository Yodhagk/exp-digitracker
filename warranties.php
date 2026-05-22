<?php
require_once 'includes/auth.php';
require_once 'config.php';
$page_title = 'Warranties';
$uid  = (int)$_SESSION['id'];
$today = date('Y-m-d');
$msg  = '';

$upload_dir  = __DIR__ . '/uploads/warranties/';
$allowed_ext = ['pdf','jpg','jpeg','png'];
$max_size    = 5 * 1024 * 1024;

function save_warranty_file($file, $dir, $allowed, $max) {
    if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > $max) return null;
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) return null;
    $fname = uniqid('war_', true) . '.' . $ext;
    return move_uploaded_file($file['tmp_name'], $dir . $fname) ? $fname : null;
}

// ── Handle POST ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $product  = trim($_POST['product_name'] ?? '');
        $brand    = trim($_POST['brand'] ?? '');
        $model    = trim($_POST['model_number'] ?? '');
        $purch_dt = $_POST['purchase_date'] ?: null;
        $expiry   = $_POST['warranty_expiry'] ?? '';
        $vendor   = trim($_POST['vendor'] ?? '');
        $notes    = trim($_POST['notes'] ?? '');
        $file_path= null;

        if (!empty($_FILES['warranty_file']['name'])) {
            $file_path = save_warranty_file($_FILES['warranty_file'], $upload_dir, $allowed_ext, $max_size);
        }

        if ($product && $expiry) {
            $stmt = mysqli_prepare($conn,
                'INSERT INTO warranties (user_id,product_name,brand,model_number,purchase_date,warranty_expiry,vendor,file_path,notes)
                 VALUES (?,?,?,?,?,?,?,?,?)');
            mysqli_stmt_bind_param($stmt,'issssssss',$uid,$product,$brand,$model,$purch_dt,$expiry,$vendor,$file_path,$notes);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $msg = 'success:Warranty added.';
        }
    }

    if ($action === 'edit') {
        $id       = (int)($_POST['id'] ?? 0);
        $product  = trim($_POST['product_name'] ?? '');
        $brand    = trim($_POST['brand'] ?? '');
        $model    = trim($_POST['model_number'] ?? '');
        $purch_dt = $_POST['purchase_date'] ?: null;
        $expiry   = $_POST['warranty_expiry'] ?? '';
        $vendor   = trim($_POST['vendor'] ?? '');
        $notes    = trim($_POST['notes'] ?? '');
        $old_file = trim($_POST['old_file'] ?? '');
        $file_path= $old_file ?: null;

        if (!empty($_FILES['warranty_file']['name'])) {
            $new = save_warranty_file($_FILES['warranty_file'], $upload_dir, $allowed_ext, $max_size);
            if ($new) {
                if ($old_file && file_exists($upload_dir.$old_file)) unlink($upload_dir.$old_file);
                $file_path = $new;
            }
        }

        if ($id && $product && $expiry) {
            $stmt = mysqli_prepare($conn,
                'UPDATE warranties SET product_name=?,brand=?,model_number=?,purchase_date=?,warranty_expiry=?,vendor=?,file_path=?,notes=?
                 WHERE id=? AND user_id=?');
            mysqli_stmt_bind_param($stmt,'ssssssssii',$product,$brand,$model,$purch_dt,$expiry,$vendor,$file_path,$notes,$id,$uid);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $msg = 'success:Warranty updated.';
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $r = mysqli_query($conn,"SELECT file_path FROM warranties WHERE id=$id AND user_id=$uid");
            $row = mysqli_fetch_assoc($r);
            if ($row && $row['file_path'] && file_exists($upload_dir.$row['file_path'])) unlink($upload_dir.$row['file_path']);
            mysqli_query($conn,"DELETE FROM warranties WHERE id=$id AND user_id=$uid");
            $msg = 'success:Warranty deleted.';
        }
    }

    header('Location: warranties.php?msg='.urlencode($msg));
    exit;
}

$msg    = $_GET['msg'] ?? '';
$filter = $_GET['filter'] ?? 'all';

$where = '';
if ($filter === 'valid')    $where = "AND warranty_expiry >= '$today'";
if ($filter === 'expiring') $where = "AND warranty_expiry BETWEEN '$today' AND DATE_ADD('$today', INTERVAL 90 DAY)";
if ($filter === 'expired')  $where = "AND warranty_expiry < '$today'";

$result = mysqli_query($conn,"SELECT * FROM warranties WHERE user_id=$uid $where ORDER BY warranty_expiry ASC");
$rows   = mysqli_fetch_all($result, MYSQLI_ASSOC);

require_once 'includes/header.php';

function war_badge($expiry, $today) {
    $days = (int)ceil((strtotime($expiry) - strtotime($today)) / 86400);
    if ($days < 0) return ['badge-expired','Expired'];
    if ($days <= 30) return ['badge-expiring', $days.'d left — Expiring soon'];
    if ($days <= 90) return ['badge-expiring', $days.'d left'];
    return ['badge-valid', $days.'d left'];
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
    <h6 class="card-title"><i class="fas fa-shield-halved me-2" style="color:#8b5cf6;"></i>Warranties</h6>
    <div class="d-flex gap-2 flex-wrap align-items-center">
      <div class="btn-group btn-group-sm">
        <?php foreach(['all'=>'All','valid'=>'Valid','expiring'=>'Expiring (90d)','expired'=>'Expired'] as $f=>$fl): ?>
          <a href="?filter=<?= $f ?>" class="btn btn-<?= $filter===$f?'primary':'outline-secondary' ?>"><?= $fl ?></a>
        <?php endforeach; ?>
      </div>
      <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addModal">
        <i class="fas fa-plus me-1"></i>Add Warranty
      </button>
    </div>
  </div>
  <div class="card-body p-0">
    <div class="table-wrapper">
      <table class="table mb-0">
        <thead>
          <tr><th>#</th><th>Product</th><th>Brand</th><th>Model</th><th>Purchased</th><th>Expires</th><th>Vendor</th><th>Status</th><th>Document</th><th>Actions</th></tr>
        </thead>
        <tbody>
          <?php if (empty($rows)): ?>
          <tr><td colspan="10" class="text-center py-4 text-muted">No warranties added. <a href="#" data-bs-toggle="modal" data-bs-target="#addModal">Add one.</a></td></tr>
          <?php else: foreach($rows as $i=>$w):
            [$cls,$label] = war_badge($w['warranty_expiry'], $today);
          ?>
          <tr>
            <td class="text-muted"><?= $i+1 ?></td>
            <td class="fw-semibold"><?= htmlspecialchars($w['product_name']) ?>
              <?php if($w['notes']): ?><div class="text-muted" style="font-size:.78rem;"><?= htmlspecialchars(mb_strimwidth($w['notes'],0,35,'…')) ?></div><?php endif; ?>
            </td>
            <td><?= htmlspecialchars($w['brand']??'–') ?></td>
            <td class="text-muted"><?= htmlspecialchars($w['model_number']??'–') ?></td>
            <td><?= $w['purchase_date'] ? date('d M Y',strtotime($w['purchase_date'])) : '–' ?></td>
            <td class="fw-semibold"><?= date('d M Y',strtotime($w['warranty_expiry'])) ?></td>
            <td><?= htmlspecialchars($w['vendor']??'–') ?></td>
            <td><span class="badge-status <?= $cls ?>"><?= $label ?></span></td>
            <td>
              <?php if($w['file_path']): ?>
                <a href="uploads/warranties/<?= htmlspecialchars($w['file_path']) ?>" target="_blank" class="file-link">
                  <i class="fas fa-file-arrow-down"></i>View
                </a>
              <?php else: ?>
                <span class="no-file"><i class="fas fa-minus"></i></span>
              <?php endif; ?>
            </td>
            <td>
              <div class="d-flex gap-1">
                <button class="btn-action edit" data-bs-toggle="modal" data-bs-target="#editModal"
                  data-id="<?= $w['id'] ?>"
                  data-product="<?= htmlspecialchars($w['product_name'],ENT_QUOTES) ?>"
                  data-brand="<?= htmlspecialchars($w['brand']??'',ENT_QUOTES) ?>"
                  data-model="<?= htmlspecialchars($w['model_number']??'',ENT_QUOTES) ?>"
                  data-purchase="<?= $w['purchase_date'] ?>"
                  data-expiry="<?= $w['warranty_expiry'] ?>"
                  data-vendor="<?= htmlspecialchars($w['vendor']??'',ENT_QUOTES) ?>"
                  data-notes="<?= htmlspecialchars($w['notes']??'',ENT_QUOTES) ?>"
                  data-file="<?= htmlspecialchars($w['file_path']??'',ENT_QUOTES) ?>"
                  onclick="populateEdit(this)" title="Edit"><i class="fas fa-pen"></i>
                </button>
                <form method="POST" onsubmit="return confirm('Delete this warranty?')">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= $w['id'] ?>">
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
        <h5 class="modal-title"><i class="fas fa-plus me-2"></i>Add Warranty</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="action" value="add">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Product Name <span class="text-danger">*</span></label>
              <input type="text" name="product_name" class="form-control" placeholder="e.g. Samsung TV 55&quot;" required>
            </div>
            <div class="col-md-3">
              <label class="form-label">Brand</label>
              <input type="text" name="brand" class="form-control" placeholder="Samsung">
            </div>
            <div class="col-md-3">
              <label class="form-label">Model Number</label>
              <input type="text" name="model_number" class="form-control" placeholder="UA55AU8000">
            </div>
            <div class="col-md-4">
              <label class="form-label">Purchase Date</label>
              <input type="date" name="purchase_date" class="form-control">
            </div>
            <div class="col-md-4">
              <label class="form-label">Warranty Expiry <span class="text-danger">*</span></label>
              <input type="date" name="warranty_expiry" class="form-control" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">Vendor / Store</label>
              <input type="text" name="vendor" class="form-control" placeholder="Croma, Amazon…">
            </div>
            <div class="col-12">
              <label class="form-label">Warranty Document <span class="text-muted" style="font-weight:400;">(PDF, JPG, PNG · max 5MB)</span></label>
              <input type="file" name="warranty_file" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
            </div>
            <div class="col-12">
              <label class="form-label">Notes</label>
              <textarea name="notes" class="form-control" rows="2" placeholder="Serial number, purchase receipt ref, etc."></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Save Warranty</button>
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
        <h5 class="modal-title"><i class="fas fa-pen me-2"></i>Edit Warranty</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="id" id="edit_id">
        <input type="hidden" name="old_file" id="edit_old_file">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Product Name <span class="text-danger">*</span></label>
              <input type="text" name="product_name" id="edit_product" class="form-control" required>
            </div>
            <div class="col-md-3">
              <label class="form-label">Brand</label>
              <input type="text" name="brand" id="edit_brand" class="form-control">
            </div>
            <div class="col-md-3">
              <label class="form-label">Model Number</label>
              <input type="text" name="model_number" id="edit_model" class="form-control">
            </div>
            <div class="col-md-4">
              <label class="form-label">Purchase Date</label>
              <input type="date" name="purchase_date" id="edit_purchase" class="form-control">
            </div>
            <div class="col-md-4">
              <label class="form-label">Warranty Expiry <span class="text-danger">*</span></label>
              <input type="date" name="warranty_expiry" id="edit_expiry" class="form-control" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">Vendor / Store</label>
              <input type="text" name="vendor" id="edit_vendor" class="form-control">
            </div>
            <div class="col-12">
              <label class="form-label">Replace Document <span class="text-muted" style="font-weight:400;">(leave empty to keep existing)</span></label>
              <div id="edit_current_file" class="mb-1" style="font-size:.82rem;"></div>
              <input type="file" name="warranty_file" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
            </div>
            <div class="col-12">
              <label class="form-label">Notes</label>
              <textarea name="notes" id="edit_notes" class="form-control" rows="2"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Update Warranty</button>
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
  document.getElementById('edit_product').value  = btn.dataset.product;
  document.getElementById('edit_brand').value    = btn.dataset.brand;
  document.getElementById('edit_model').value    = btn.dataset.model;
  document.getElementById('edit_purchase').value = btn.dataset.purchase;
  document.getElementById('edit_expiry').value   = btn.dataset.expiry;
  document.getElementById('edit_vendor').value   = btn.dataset.vendor;
  document.getElementById('edit_notes').value    = btn.dataset.notes;
  document.getElementById('edit_old_file').value = btn.dataset.file;
  const cf = document.getElementById('edit_current_file');
  cf.innerHTML = btn.dataset.file
    ? '<i class="fas fa-paperclip me-1 text-primary"></i>Current: ' + btn.dataset.file
    : '<span class="text-muted">No document attached</span>';
}
</script>
JS;
require_once 'includes/footer.php';
?>
