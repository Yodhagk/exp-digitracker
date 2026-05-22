<?php
session_start();
if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true) {
    header('Location: dashboard.php');
    exit;
}
require_once 'config.php';

$username = $password = '';
$login_err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($username) || empty($password)) {
        $login_err = 'Please enter both username and password.';
    } else {
        $stmt = mysqli_prepare($conn, 'SELECT id, username, password FROM users WHERE username = ?');
        mysqli_stmt_bind_param($stmt, 's', $username);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_store_result($stmt);

        if (mysqli_stmt_num_rows($stmt) === 1) {
            mysqli_stmt_bind_result($stmt, $id, $db_username, $hashed_password);
            mysqli_stmt_fetch($stmt);
            if (password_verify($password, $hashed_password)) {
                $_SESSION['loggedin'] = true;
                $_SESSION['id']       = $id;
                $_SESSION['username'] = $db_username;
                header('Location: dashboard.php');
                exit;
            } else {
                $login_err = 'Invalid username or password.';
            }
        } else {
            $login_err = 'Invalid username or password.';
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
  <title>Sign In – DigiTracker</title>
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
      <h2>Welcome back</h2>
      <p class="subtitle">Sign in to your DigiTracker account</p>

      <?php if ($login_err): ?>
        <div class="auth-alert"><i class="fas fa-circle-exclamation me-2"></i><?= htmlspecialchars($login_err) ?></div>
      <?php endif; ?>

      <form action="login.php" method="POST" novalidate>
        <div class="mb-4">
          <label class="form-label">Username</label>
          <input type="text" name="username" class="form-control"
                 value="<?= htmlspecialchars($username) ?>"
                 placeholder="Enter your username" autofocus required>
        </div>
        <div class="mb-4">
          <label class="form-label">Password</label>
          <input type="password" name="password" class="form-control"
                 placeholder="Enter your password" required>
        </div>
        <button type="submit" class="btn-auth">
          <i class="fas fa-right-to-bracket me-2"></i>Sign In
        </button>
      </form>

      <div class="auth-switch">
        Don't have an account? <a href="register.php">Create one free</a>
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
