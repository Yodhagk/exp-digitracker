<?php
require_once 'includes/auth.php';
require_once 'config.php';
$page_title = 'Users';
$uid = (int)$_SESSION['id'];

if (($_SESSION['role'] ?? 'user') !== 'admin') {
    header('Location: dashboard.php?msg=' . urlencode('danger:Admin access required.'));
    exit;
}

$msg = '';

// ── Handle POST ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action    = $_POST['action'] ?? '';
    $target_id = (int)($_POST['id'] ?? 0);

    if ($target_id === $uid && in_array($action, ['suspend', 'delete', 'revoke_admin'], true)) {
        $msg = 'danger:You cannot ' . str_replace('_', ' ', $action) . ' your own account.';
    } elseif ($target_id > 0) {
        if ($action === 'suspend') {
            mysqli_query($conn, "UPDATE users SET status='suspended' WHERE id=$target_id");
            AppLogger::action("User suspended: id=$target_id");
            $msg = 'success:User suspended.';
        }

        if ($action === 'reactivate') {
            mysqli_query($conn, "UPDATE users SET status='active' WHERE id=$target_id");
            AppLogger::action("User reactivated: id=$target_id");
            $msg = 'success:User reactivated.';
        }

        if ($action === 'make_admin') {
            mysqli_query($conn, "UPDATE users SET role='admin' WHERE id=$target_id");
            AppLogger::action("User promoted to admin: id=$target_id");
            $msg = 'success:User granted admin access.';
        }

        if ($action === 'revoke_admin') {
            $admin_count = (int)mysqli_fetch_assoc(mysqli_query($conn,
                "SELECT COUNT(*) c FROM users WHERE role='admin'"))['c'];
            if ($admin_count <= 1) {
                $msg = 'danger:Cannot revoke the last remaining admin.';
            } else {
                mysqli_query($conn, "UPDATE users SET role='user' WHERE id=$target_id");
                AppLogger::action("Admin access revoked: id=$target_id");
                $msg = 'success:Admin access revoked.';
            }
        }

        if ($action === 'delete') {
            $target      = mysqli_fetch_assoc(mysqli_query($conn, "SELECT role FROM users WHERE id=$target_id"));
            $admin_count = (int)mysqli_fetch_assoc(mysqli_query($conn,
                "SELECT COUNT(*) c FROM users WHERE role='admin'"))['c'];

            if (!$target) {
                $msg = 'danger:User not found.';
            } elseif ($target['role'] === 'admin' && $admin_count <= 1) {
                $msg = 'danger:Cannot delete the last remaining admin.';
            } else {
                // Best-effort removal of uploaded files before the rows referencing them go away.
                foreach (['invoices' => 'uploads/invoices/', 'warranties' => 'uploads/warranties/'] as $table => $dir) {
                    $fr = mysqli_query($conn,
                        "SELECT file_path FROM $table WHERE user_id=$target_id AND file_path IS NOT NULL");
                    while ($frow = mysqli_fetch_assoc($fr)) {
                        $full = __DIR__ . '/' . $dir . $frow['file_path'];
                        if (is_file($full)) @unlink($full);
                    }
                }
                mysqli_begin_transaction($conn);
                try {
                    foreach (['loans', 'expenses', 'invoices', 'warranties', 'credit_cards',
                              'card_bills', 'gmail_tokens', 'shopping_orders'] as $table) {
                        mysqli_query($conn, "DELETE FROM $table WHERE user_id=$target_id");
                    }
                    mysqli_query($conn, "DELETE FROM users WHERE id=$target_id");
                    mysqli_commit($conn);
                    AppLogger::action("User deleted: id=$target_id (all owned records removed)");
                    $msg = 'success:User and all their data deleted.';
                } catch (\Throwable $e) {
                    mysqli_rollback($conn);
                    $msg = 'danger:Delete failed — no changes were made.';
                }
            }
        }
    }

    header('Location: users.php?msg=' . urlencode($msg));
    exit;
}

$msg = $_GET['msg'] ?? '';

$users = mysqli_fetch_all(mysqli_query($conn,
    "SELECT u.*,
        (SELECT COUNT(*) FROM loans l    WHERE l.user_id=u.id) AS loan_count,
        (SELECT COUNT(*) FROM expenses e WHERE e.user_id=u.id) AS expense_count,
        (SELECT COUNT(*) FROM invoices i WHERE i.user_id=u.id) AS invoice_count
     FROM users u ORDER BY u.created_at ASC"), MYSQLI_ASSOC);

$admin_total = 0;
foreach ($users as $u) if ($u['role'] === 'admin') $admin_total++;

require_once 'includes/header.php';

function user_status_badge(string $s): string {
    return $s === 'suspended'
        ? '<span class="badge-status badge-overdue"><i class="fas fa-ban me-1"></i>Suspended</span>'
        : '<span class="badge-status badge-paid"><i class="fas fa-circle-check me-1"></i>Active</span>';
}
function user_role_badge(string $r): string {
    return $r === 'admin'
        ? '<span class="badge-status badge-active"><i class="fas fa-user-shield me-1"></i>Admin</span>'
        : '<span class="badge bg-light text-dark border" style="font-size:.72rem;">User</span>';
}
?>

<?php if ($msg): [$mt, $mx] = explode(':', $msg, 2); ?>
<div class="alert alert-<?= $mt === 'success' ? 'success' : 'danger' ?> alert-dismissible fade show">
  <i class="fas fa-<?= $mt === 'success' ? 'circle-check' : 'circle-exclamation' ?> me-2"></i>
  <?= htmlspecialchars($mx) ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-header">
    <h6 class="card-title"><i class="fas fa-users-gear me-2 text-info"></i>User Accounts</h6>
    <span class="text-muted" style="font-size:.8rem;"><?= count($users) ?> account<?= count($users) === 1 ? '' : 's' ?> · <?= $admin_total ?> admin<?= $admin_total === 1 ? '' : 's' ?></span>
  </div>
  <div class="card-body p-0">
    <div class="table-wrapper">
      <table class="table mb-0">
        <thead>
          <tr>
            <th>#</th><th>Username</th><th>Email</th><th>Role</th><th>Status</th>
            <th>Created</th><th>Data</th><th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($users)): ?>
          <tr><td colspan="8" class="text-center py-4 text-muted">No user accounts found.</td></tr>
          <?php else: foreach ($users as $i => $u):
            $is_self = (int)$u['id'] === $uid;
          ?>
          <tr>
            <td class="text-muted"><?= $i + 1 ?></td>
            <td class="fw-semibold">
              <?= htmlspecialchars($u['username']) ?>
              <?php if ($is_self): ?><span class="text-muted" style="font-size:.74rem;">(you)</span><?php endif; ?>
            </td>
            <td class="text-muted"><?= htmlspecialchars($u['email'] ?? '—') ?></td>
            <td><?= user_role_badge($u['role']) ?></td>
            <td><?= user_status_badge($u['status']) ?></td>
            <td class="text-muted" style="font-size:.85rem;"><?= date('d M Y', strtotime($u['created_at'])) ?></td>
            <td style="font-size:.78rem;" class="text-muted">
              <?= (int)$u['loan_count'] ?> loans · <?= (int)$u['expense_count'] ?> expenses · <?= (int)$u['invoice_count'] ?> invoices
            </td>
            <td>
              <div class="d-flex gap-1 flex-wrap">
                <button class="btn btn-sm btn-outline-primary" title="View profile"
                  data-bs-toggle="modal" data-bs-target="#viewModal"
                  data-username="<?= htmlspecialchars($u['username'], ENT_QUOTES) ?>"
                  data-email="<?= htmlspecialchars($u['email'] ?? '', ENT_QUOTES) ?>"
                  data-role="<?= htmlspecialchars($u['role'], ENT_QUOTES) ?>"
                  data-status="<?= htmlspecialchars($u['status'], ENT_QUOTES) ?>"
                  data-created="<?= htmlspecialchars(date('d M Y, g:ia', strtotime($u['created_at'])), ENT_QUOTES) ?>"
                  data-loans="<?= (int)$u['loan_count'] ?>"
                  data-expenses="<?= (int)$u['expense_count'] ?>"
                  data-invoices="<?= (int)$u['invoice_count'] ?>"
                  onclick="populateView(this)">
                  <i class="fas fa-eye"></i>
                </button>

                <?php if (!$is_self): ?>
                  <?php if ($u['status'] === 'suspended'): ?>
                  <form method="POST" class="d-inline" onsubmit="return confirm('Reactivate <?= htmlspecialchars(addslashes($u['username']), ENT_QUOTES) ?>?')">
                    <input type="hidden" name="action" value="reactivate">
                    <input type="hidden" name="id" value="<?= $u['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-success" title="Reactivate"><i class="fas fa-rotate-left"></i></button>
                  </form>
                  <?php else: ?>
                  <form method="POST" class="d-inline" onsubmit="return confirm('Suspend <?= htmlspecialchars(addslashes($u['username']), ENT_QUOTES) ?>? They will be signed out immediately and unable to log back in.')">
                    <input type="hidden" name="action" value="suspend">
                    <input type="hidden" name="id" value="<?= $u['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-warning" title="Suspend"><i class="fas fa-ban"></i></button>
                  </form>
                  <?php endif; ?>

                  <?php if ($u['role'] === 'admin'): ?>
                  <form method="POST" class="d-inline" onsubmit="return confirm('Revoke admin access for <?= htmlspecialchars(addslashes($u['username']), ENT_QUOTES) ?>?')">
                    <input type="hidden" name="action" value="revoke_admin">
                    <input type="hidden" name="id" value="<?= $u['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-secondary" title="Revoke admin"><i class="fas fa-user-minus"></i></button>
                  </form>
                  <?php else: ?>
                  <form method="POST" class="d-inline" onsubmit="return confirm('Grant admin access to <?= htmlspecialchars(addslashes($u['username']), ENT_QUOTES) ?>?')">
                    <input type="hidden" name="action" value="make_admin">
                    <input type="hidden" name="id" value="<?= $u['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-secondary" title="Make admin"><i class="fas fa-user-shield"></i></button>
                  </form>
                  <?php endif; ?>

                  <form method="POST" class="d-inline" onsubmit="return confirm('Permanently delete <?= htmlspecialchars(addslashes($u['username']), ENT_QUOTES) ?> and ALL of their loans, expenses, invoices, warranties and other data? This cannot be undone.')">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= $u['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"><i class="fas fa-trash"></i></button>
                  </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <div class="text-muted p-3 pt-2" style="font-size:.75rem;border-top:1px solid var(--border);">
      <i class="fas fa-info-circle me-1"></i>
      A suspended account is signed out on its next request and cannot log back in until reactivated.
      Deleting an account permanently removes every loan, expense, invoice, warranty, credit card and
      shopping record it owns. You cannot suspend, delete, or revoke your own admin access, and the
      last remaining admin cannot be demoted or deleted.
    </div>
  </div>
</div>

<!-- ── View Profile Modal ────────────────────────────────────── -->
<div class="modal fade" id="viewModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-user me-2"></i><span id="vw_username"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <table class="table table-sm table-borderless mb-0">
          <tr><td class="text-muted" style="width:40%;">Email</td><td id="vw_email" class="fw-semibold"></td></tr>
          <tr><td class="text-muted">Role</td><td id="vw_role"></td></tr>
          <tr><td class="text-muted">Status</td><td id="vw_status"></td></tr>
          <tr><td class="text-muted">Created</td><td id="vw_created"></td></tr>
          <tr><td class="text-muted">Loans</td><td id="vw_loans"></td></tr>
          <tr><td class="text-muted">Expenses</td><td id="vw_expenses"></td></tr>
          <tr><td class="text-muted">Invoices</td><td id="vw_invoices"></td></tr>
        </table>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<?php
$extra_js = <<<'JS'
<script>
function populateView(btn) {
  document.getElementById('vw_username').textContent = btn.dataset.username;
  document.getElementById('vw_email').textContent     = btn.dataset.email || '—';
  document.getElementById('vw_role').innerHTML         = btn.dataset.role === 'admin'
    ? '<span class="badge-status badge-active"><i class="fas fa-user-shield me-1"></i>Admin</span>'
    : '<span class="badge bg-light text-dark border" style="font-size:.72rem;">User</span>';
  document.getElementById('vw_status').innerHTML = btn.dataset.status === 'suspended'
    ? '<span class="badge-status badge-overdue"><i class="fas fa-ban me-1"></i>Suspended</span>'
    : '<span class="badge-status badge-paid"><i class="fas fa-circle-check me-1"></i>Active</span>';
  document.getElementById('vw_created').textContent  = btn.dataset.created;
  document.getElementById('vw_loans').textContent    = btn.dataset.loans;
  document.getElementById('vw_expenses').textContent = btn.dataset.expenses;
  document.getElementById('vw_invoices').textContent = btn.dataset.invoices;
}
</script>
JS;
require_once 'includes/footer.php';
