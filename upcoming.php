<?php
require_once 'includes/auth.php';
require_once 'config.php';
$page_title = 'Upcoming';
$uid  = (int)$_SESSION['id'];
$today = date('Y-m-d');

// ── Gather all unpaid/pending items with due dates ──────
$items = [];

// Loans (active or overdue)
$r = mysqli_query($conn,"SELECT id,'Loan' type, name, due_date, monthly_payment amount, status, lender extra FROM loans WHERE user_id=$uid AND status IN ('active','overdue') ORDER BY due_date ASC");
while ($row = mysqli_fetch_assoc($r)) $items[] = $row;

// Expenses
$r = mysqli_query($conn,"SELECT id,'Expense' type, name, due_date, amount, status, category extra FROM expenses WHERE user_id=$uid AND status IN ('pending','overdue') ORDER BY due_date ASC");
while ($row = mysqli_fetch_assoc($r)) $items[] = $row;

// Invoices
$r = mysqli_query($conn,"SELECT id,'Invoice' type, vendor_name name, due_date, amount, status, invoice_number extra FROM invoices WHERE user_id=$uid AND status IN ('unpaid','overdue') AND due_date IS NOT NULL ORDER BY due_date ASC");
while ($row = mysqli_fetch_assoc($r)) $items[] = $row;

// Sort all by due_date
usort($items, fn($a,$b) => strcmp($a['due_date'],$b['due_date']));

// ── Expiring warranties ─────────────────────────────────
$warranties = [];
$r = mysqli_query($conn,"SELECT product_name, brand, warranty_expiry, vendor, notes FROM warranties WHERE user_id=$uid ORDER BY warranty_expiry ASC");
while ($row = mysqli_fetch_assoc($r)) $warranties[] = $row;

// Bucket items
$overdue = $this_week = $this_month = $later = [];
foreach ($items as $item) {
    $days = (int)ceil((strtotime($item['due_date']) - strtotime($today)) / 86400);
    $item['days'] = $days;
    if ($days < 0 || $item['status'] === 'overdue') $overdue[] = $item;
    elseif ($days <= 7)  $this_week[]  = $item;
    elseif ($days <= 30) $this_month[] = $item;
    else                 $later[]      = $item;
}

require_once 'includes/header.php';

function type_icon($type) {
    return match($type) {
        'Loan'    => '<span class="badge" style="background:rgba(59,130,246,.15);color:#2563eb;font-size:.73rem;"><i class="fas fa-hand-holding-dollar me-1"></i>Loan</span>',
        'Expense' => '<span class="badge" style="background:rgba(16,185,129,.15);color:#059669;font-size:.73rem;"><i class="fas fa-receipt me-1"></i>Expense</span>',
        'Invoice' => '<span class="badge" style="background:rgba(245,158,11,.15);color:#b45309;font-size:.73rem;"><i class="fas fa-file-invoice me-1"></i>Invoice</span>',
        default   => '<span class="badge bg-secondary">'.$type.'</span>',
    };
}

function render_group(array $items, string $headerColor, string $icon, string $title, string $emptyMsg = ''): void {
    ?>
    <div class="card mb-4">
      <div class="card-header">
        <h6 class="card-title" style="color:<?= $headerColor ?>;">
          <i class="fas fa-<?= $icon ?> me-2"></i><?= $title ?>
          <span class="badge ms-2" style="background:<?= $headerColor ?>;color:#fff;"><?= count($items) ?></span>
        </h6>
      </div>
      <div class="card-body p-0">
        <?php if (empty($items)): ?>
          <div class="text-center py-4 text-muted" style="font-size:.9rem;"><?= $emptyMsg ?: 'Nothing here.' ?></div>
        <?php else: ?>
        <div class="table-wrapper">
          <table class="table mb-0">
            <thead><tr><th>Name</th><th>Type</th><th>Extra Info</th><th>Due Date</th><th>Amount</th><th>Status</th></tr></thead>
            <tbody>
              <?php foreach ($items as $item): ?>
              <tr>
                <td class="fw-semibold"><?= htmlspecialchars($item['name']) ?></td>
                <td><?= type_icon($item['type']) ?></td>
                <td class="text-muted" style="font-size:.83rem;"><?= htmlspecialchars($item['extra'] ?? '–') ?></td>
                <td>
                  <?= date('d M Y', strtotime($item['due_date'])) ?>
                  <div style="font-size:.76rem;color:<?= $headerColor ?>;">
                    <?= $item['days'] < 0 ? abs($item['days']).' days overdue' : ($item['days'] === 0 ? 'Today' : $item['days'].' days away') ?>
                  </div>
                </td>
                <td class="fw-bold">₹<?= number_format($item['amount'],0) ?></td>
                <td>
                  <?php
                    $s = $item['status'];
                    $smap=['pending'=>'badge-pending','unpaid'=>'badge-unpaid','overdue'=>'badge-overdue','active'=>'badge-active'];
                    echo '<span class="badge-status '.($smap[$s]??'').'">'.$s.'</span>';
                  ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php
}
?>

<!-- Summary bar -->
<div class="row g-3 mb-4">
  <div class="col-sm-3">
    <div class="stat-card">
      <div class="stat-icon red"><i class="fas fa-circle-exclamation"></i></div>
      <div class="stat-info">
        <div class="stat-label">Overdue</div>
        <div class="stat-value"><?= count($overdue) ?></div>
      </div>
    </div>
  </div>
  <div class="col-sm-3">
    <div class="stat-card">
      <div class="stat-icon orange"><i class="fas fa-clock"></i></div>
      <div class="stat-info">
        <div class="stat-label">Due This Week</div>
        <div class="stat-value"><?= count($this_week) ?></div>
      </div>
    </div>
  </div>
  <div class="col-sm-3">
    <div class="stat-card">
      <div class="stat-icon blue"><i class="fas fa-calendar-days"></i></div>
      <div class="stat-info">
        <div class="stat-label">This Month</div>
        <div class="stat-value"><?= count($this_month) ?></div>
      </div>
    </div>
  </div>
  <div class="col-sm-3">
    <div class="stat-card">
      <div class="stat-icon green"><i class="fas fa-forward"></i></div>
      <div class="stat-info">
        <div class="stat-label">Later</div>
        <div class="stat-value"><?= count($later) ?></div>
      </div>
    </div>
  </div>
</div>

<!-- Payment groups -->
<?php render_group($overdue,    '#ef4444', 'circle-exclamation', 'Overdue — Immediate Attention', '<i class="fas fa-check-circle me-1 text-success"></i>No overdue items!'); ?>
<?php render_group($this_week,  '#f59e0b', 'clock',              'Due This Week'); ?>
<?php render_group($this_month, '#3b82f6', 'calendar-days',      'Due This Month'); ?>
<?php render_group($later,      '#10b981', 'forward',            'Upcoming (Beyond 30 Days)'); ?>

<!-- Warranties section -->
<div class="card">
  <div class="card-header">
    <h6 class="card-title" style="color:#8b5cf6;"><i class="fas fa-shield-halved me-2"></i>Warranty Expiry Timeline</h6>
    <a href="warranties.php" class="btn btn-sm btn-outline-secondary">Manage</a>
  </div>
  <div class="card-body p-0">
    <?php if (empty($warranties)): ?>
      <div class="text-center py-4 text-muted">No warranties stored. <a href="warranties.php">Add one.</a></div>
    <?php else: ?>
    <div class="table-wrapper">
      <table class="table mb-0">
        <thead><tr><th>Product</th><th>Brand</th><th>Vendor</th><th>Expires</th><th>Status</th></tr></thead>
        <tbody>
          <?php foreach($warranties as $w):
            $days = (int)ceil((strtotime($w['warranty_expiry']) - strtotime($today)) / 86400);
            if ($days < 0)    { $cls='badge-expired';  $label='Expired '.abs($days).'d ago'; }
            elseif ($days<=30){ $cls='badge-expiring'; $label=$days.'d left'; }
            elseif ($days<=90){ $cls='badge-expiring'; $label=$days.'d left'; }
            else              { $cls='badge-valid';    $label=$days.'d left'; }
          ?>
          <tr>
            <td class="fw-semibold"><?= htmlspecialchars($w['product_name']) ?></td>
            <td><?= htmlspecialchars($w['brand']??'–') ?></td>
            <td><?= htmlspecialchars($w['vendor']??'–') ?></td>
            <td><?= date('d M Y',strtotime($w['warranty_expiry'])) ?></td>
            <td><span class="badge-status <?= $cls ?>"><?= $label ?></span></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php require_once 'includes/footer.php'; ?>
