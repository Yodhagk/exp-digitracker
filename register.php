<?php
session_start();
if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true) {
    header('Location: dashboard.php');
    exit;
}
require_once 'config.php';

$username = $password = $confirm_password = '';
$username_err = $password_err = $confirm_err = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Username
    if (empty(trim($_POST['username'] ?? ''))) {
        $username_err = 'Please enter a username.';
    } else {
        $stmt = mysqli_prepare($conn, 'SELECT id FROM users WHERE username = ?');
        $param = trim($_POST['username']);
        mysqli_stmt_bind_param($stmt, 's', $param);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_store_result($stmt);
        if (mysqli_stmt_num_rows($stmt) > 0) {
            $username_err = 'This username is already taken.';
        } else {
            $username = $param;
        }
        mysqli_stmt_close($stmt);
    }

    // Password
    if (empty(trim($_POST['password'] ?? ''))) {
        $password_err = 'Please enter a password.';
    } elseif (strlen(trim($_POST['password'])) < 6) {
        $password_err = 'Password must be at least 6 characters.';
    } else {
        $password = trim($_POST['password']);
    }

    // Confirm password
    if (empty(trim($_POST['confirm_password'] ?? ''))) {
        $confirm_err = 'Please confirm your password.';
    } else {
        $confirm_password = trim($_POST['confirm_password']);
        if (empty($password_err) && $password !== $confirm_password) {
            $confirm_err = 'Passwords do not match.';
        }
    }

    if (empty($username_err) && empty($password_err) && empty($confirm_err)) {
        $stmt = mysqli_prepare($conn, 'INSERT INTO users (username, password) VALUES (?, ?)');
        $hashed = password_hash($password, PASSWORD_DEFAULT);
        mysqli_stmt_bind_param($stmt, 'ss', $username, $hashed);
        if (mysqli_stmt_execute($stmt)) {
            $success = 'Account created! Redirecting to sign in…';
            header('Refresh: 2; URL=login.php');
        } else {
            $username_err = 'Registration failed. Please try again.';
        }
        mysqli_stmt_close($stmt);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Create Account – DigiTracker</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="css/style.css">
</head>
<body>
<div class="auth-wrapper">

  <!-- Left panel -->
  <div class="auth-left">
    <div class="auth-left-logo">
      <i class="fas fa-wallet"></i>
      <span>DigiTracker</span>
    </div>
    <ul class="auth-feature-list">
      <li><i class="fas fa-hand-holding-dollar"></i> Track loans &amp; EMI due dates</li>
      <li><i class="fas fa-receipt"></i> Manage recurring expenses</li>
      <li><i class="fas fa-calendar-alt"></i> Upcoming payment calendar</li>
      <li><i class="fas fa-file-invoice-dollar"></i> Store &amp; track invoices</li>
      <li><i class="fas fa-shield-halved"></i> Warranty document vault</li>
    </ul>
  </div>

  <!-- Right panel -->
  <div class="auth-right">
    <div class="auth-form-box">
      <h2>Create account</h2>
      <p class="subtitle">Start tracking your finances for free</p>

      <?php if ($success): ?>
        <div class="auth-success"><i class="fas fa-circle-check me-2"></i><?= htmlspecialchars($success) ?></div>
      <?php endif; ?>

      <form action="register.php" method="POST" novalidate>
        <div class="mb-3">
          <label class="form-label">Username</label>
          <input type="text" name="username"
                 class="form-control <?= $username_err ? 'is-invalid' : '' ?>"
                 value="<?= htmlspecialchars($username) ?>"
                 placeholder="Choose a username" autofocus required>
          <?php if ($username_err): ?><div class="invalid-feedback"><?= htmlspecialchars($username_err) ?></div><?php endif; ?>
        </div>
        <div class="mb-3">
          <label class="form-label">Password <span style="color:#94a3b8;font-weight:400;">(min 6 chars)</span></label>
          <input type="password" name="password"
                 class="form-control <?= $password_err ? 'is-invalid' : '' ?>"
                 placeholder="Create a password" required>
          <?php if ($password_err): ?><div class="invalid-feedback"><?= htmlspecialchars($password_err) ?></div><?php endif; ?>
        </div>
        <div class="mb-4">
          <label class="form-label">Confirm Password</label>
          <input type="password" name="confirm_password"
                 class="form-control <?= $confirm_err ? 'is-invalid' : '' ?>"
                 placeholder="Repeat your password" required>
          <?php if ($confirm_err): ?><div class="invalid-feedback"><?= htmlspecialchars($confirm_err) ?></div><?php endif; ?>
        </div>
        <button type="submit" class="btn-auth">
          <i class="fas fa-user-plus me-2"></i>Create Account
        </button>
      </form>

      <div class="auth-switch">
        Already have an account? <a href="login.php">Sign in</a>
      </div>
      <div class="text-center mt-2">
        <a href="index.php" style="font-size:.84rem;color:#64748b;text-decoration:none;">
          <i class="fas fa-arrow-left me-1"></i>Back to home
        </a>
      </div>
    </div>
  </div>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
