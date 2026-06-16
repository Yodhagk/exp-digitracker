<?php
$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($page_title ?? 'DigiTracker') ?> – DigiTracker</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="css/style.css">
</head>
<body>

<!-- ── Sidebar ── -->
<nav class="sidebar" id="sidebar">
  <div class="sidebar-header">
    <a href="dashboard.php" class="sidebar-logo">
      <i class="fas fa-wallet"></i>
      <span>DigiTracker</span>
    </a>
  </div>
  <div class="sidebar-user">
    <div class="user-avatar"><i class="fas fa-user-circle"></i></div>
    <div class="user-info">
      <span class="user-name"><?= htmlspecialchars($_SESSION['username']) ?></span>
      <span class="user-role">Personal Finance</span>
    </div>
  </div>
  <ul class="sidebar-menu">
    <li class="<?= $current_page === 'dashboard.php' ? 'active' : '' ?>">
      <a href="dashboard.php"><i class="fas fa-th-large"></i><span>Dashboard</span></a>
    </li>
    <li class="<?= $current_page === 'loans.php' ? 'active' : '' ?>">
      <a href="loans.php"><i class="fas fa-hand-holding-dollar"></i><span>Loans</span></a>
    </li>
    <li class="<?= $current_page === 'expenses.php' ? 'active' : '' ?>">
      <a href="expenses.php"><i class="fas fa-receipt"></i><span>Expenses</span></a>
    </li>
    <li class="<?= $current_page === 'upcoming.php' ? 'active' : '' ?>">
      <a href="upcoming.php"><i class="fas fa-calendar-alt"></i><span>Upcoming</span></a>
    </li>
    <li class="<?= $current_page === 'invoices.php' ? 'active' : '' ?>">
      <a href="invoices.php"><i class="fas fa-file-invoice-dollar"></i><span>Invoices</span></a>
    </li>
    <li class="<?= $current_page === 'warranties.php' ? 'active' : '' ?>">
      <a href="warranties.php"><i class="fas fa-shield-halved"></i><span>Warranties</span></a>
    </li>
    <li class="<?= $current_page === 'ccard.php' ? 'active' : '' ?>">
      <a href="ccard.php"><i class="fas fa-credit-card"></i><span>Credit Cards</span></a>
    </li>
    <li class="<?= $current_page === 'shopping.php' ? 'active' : '' ?>">
      <a href="shopping.php"><i class="fas fa-bag-shopping"></i><span>Shopping</span></a>
    </li>
    <li class="<?= $current_page === 'reports.php' ? 'active' : '' ?>">
      <a href="reports.php"><i class="fas fa-chart-bar"></i><span>Reports</span></a>
    </li>
  </ul>
</nav>

<!-- ── Main wrapper ── -->
<div class="main-wrapper" id="main-wrapper">

  <!-- Top nav -->
  <nav class="top-nav">
    <button class="sidebar-toggle" id="sidebar-toggle" title="Toggle sidebar">
      <i class="fas fa-bars"></i>
    </button>
    <div class="nav-title"><?= htmlspecialchars($page_title ?? 'Dashboard') ?></div>
    <div class="nav-right">
      <span class="nav-date"><i class="fas fa-calendar me-1"></i><?= date('D, M j Y') ?></span>
      <a href="logout.php" class="btn btn-sm btn-outline-danger ms-2">
        <i class="fas fa-right-from-bracket me-1"></i>Logout
      </a>
    </div>
  </nav>

  <!-- Page content starts here -->
  <div class="page-content">
