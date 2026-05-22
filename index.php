<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true) {
    header('Location: dashboard.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>DigiTracker – Personal Finance Manager</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="css/style.css">
</head>
<body style="background:#fff;">

<!-- ── Navbar ── -->
<nav class="landing-nav">
  <a href="index.php" class="landing-nav-logo">
    <i class="fas fa-wallet"></i>
    DigiTracker
  </a>
  <div class="d-flex gap-2">
    <a href="login.php" class="btn btn-sm btn-outline-primary px-4">Sign In</a>
    <a href="register.php" class="btn btn-sm btn-primary px-4">Get Started</a>
  </div>
</nav>

<!-- ── Hero ── -->
<section class="hero">
  <div class="hero-content">
    <div class="hero-badge"><i class="fas fa-sparkles"></i> Your Financial Command Center</div>
    <h1>Track Every Rupee.<br><span>Never Miss a Due Date.</span></h1>
    <p>Manage your loans, expenses, invoices &amp; warranties in one clean dashboard. Get reminders for upcoming payments before they become overdue.</p>
    <div class="hero-btns">
      <a href="register.php" class="btn-hero-primary">
        <i class="fas fa-rocket"></i> Start for Free
      </a>
      <a href="login.php" class="btn-hero-outline">
        <i class="fas fa-right-to-bracket"></i> Sign In
      </a>
    </div>
  </div>
</section>

<!-- ── Features ── -->
<section class="features-section">
  <div class="container">
    <div class="text-center">
      <span class="section-tag">Everything You Need</span>
      <h2 class="section-title">Four Pillars of Financial Control</h2>
      <p class="section-sub">One place for all your financial obligations — track, manage, and stay ahead.</p>
    </div>
    <div class="row g-4">

      <div class="col-md-6 col-lg-3">
        <div class="feature-card">
          <div class="feature-icon" style="background:rgba(59,130,246,.1);color:#3b82f6;">
            <i class="fas fa-hand-holding-dollar"></i>
          </div>
          <h5>Loans</h5>
          <p>Track all your loans with lender details, remaining balance, monthly EMIs, and due dates. See what's active or overdue at a glance.</p>
        </div>
      </div>

      <div class="col-md-6 col-lg-3">
        <div class="feature-card">
          <div class="feature-icon" style="background:rgba(16,185,129,.1);color:#10b981;">
            <i class="fas fa-receipt"></i>
          </div>
          <h5>Expenses</h5>
          <p>Log recurring and one-time expenses with categories and due dates. Get a clear view of pending payments before they slip past.</p>
        </div>
      </div>

      <div class="col-md-6 col-lg-3">
        <div class="feature-card">
          <div class="feature-icon" style="background:rgba(245,158,11,.1);color:#f59e0b;">
            <i class="fas fa-file-invoice-dollar"></i>
          </div>
          <h5>Invoices</h5>
          <p>Store and manage vendor invoices with file attachments. Track payment status and due dates so nothing goes unpaid or unnoticed.</p>
        </div>
      </div>

      <div class="col-md-6 col-lg-3">
        <div class="feature-card">
          <div class="feature-icon" style="background:rgba(139,92,246,.1);color:#8b5cf6;">
            <i class="fas fa-shield-halved"></i>
          </div>
          <h5>Warranties</h5>
          <p>Never lose a warranty again. Upload warranty documents and get alerted before products expire so you can claim on time.</p>
        </div>
      </div>

    </div>
  </div>
</section>

<!-- ── Stats strip ── -->
<section class="stats-strip">
  <div class="container">
    <div class="row g-4 text-center">
      <div class="col-6 col-md-3 stat-strip-item">
        <span class="stat-strip-num">100%</span>
        <span class="stat-strip-label">Private &amp; Secure</span>
      </div>
      <div class="col-6 col-md-3 stat-strip-item">
        <span class="stat-strip-num">4</span>
        <span class="stat-strip-label">Finance Modules</span>
      </div>
      <div class="col-6 col-md-3 stat-strip-item">
        <span class="stat-strip-num">0</span>
        <span class="stat-strip-label">Missed Due Dates</span>
      </div>
      <div class="col-6 col-md-3 stat-strip-item">
        <span class="stat-strip-num">∞</span>
        <span class="stat-strip-label">Records You Can Store</span>
      </div>
    </div>
  </div>
</section>

<!-- ── How it works ── -->
<section class="features-section" style="background:#f8fafc;">
  <div class="container">
    <div class="text-center">
      <span class="section-tag">Simple Workflow</span>
      <h2 class="section-title">Get Started in Minutes</h2>
      <p class="section-sub">No complicated setup. Just sign up and start tracking.</p>
    </div>
    <div class="row g-4 align-items-center">
      <div class="col-md-4">
        <div class="d-flex gap-16 align-items-start" style="gap:16px;">
          <div class="feature-icon flex-shrink-0" style="background:#0f172a;color:#fff;width:48px;height:48px;font-size:1.1rem;border-radius:12px;display:flex;align-items:center;justify-content:center;">1</div>
          <div>
            <h5 class="fw-bold mb-1" style="font-size:.97rem;">Create an Account</h5>
            <p class="text-muted mb-0" style="font-size:.88rem;">Register with your username and password. Your data stays private and linked only to your account.</p>
          </div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="d-flex align-items-start" style="gap:16px;">
          <div class="feature-icon flex-shrink-0" style="background:#0f172a;color:#fff;width:48px;height:48px;font-size:1.1rem;border-radius:12px;display:flex;align-items:center;justify-content:center;">2</div>
          <div>
            <h5 class="fw-bold mb-1" style="font-size:.97rem;">Add Your Records</h5>
            <p class="text-muted mb-0" style="font-size:.88rem;">Enter your loans, expenses, invoices and warranties. Upload documents directly — PDF, JPG, PNG supported.</p>
          </div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="d-flex align-items-start" style="gap:16px;">
          <div class="feature-icon flex-shrink-0" style="background:#0f172a;color:#fff;width:48px;height:48px;font-size:1.1rem;border-radius:12px;display:flex;align-items:center;justify-content:center;">3</div>
          <div>
            <h5 class="fw-bold mb-1" style="font-size:.97rem;">Stay Ahead</h5>
            <p class="text-muted mb-0" style="font-size:.88rem;">Check the Upcoming view daily to see what's due this week. Mark payments as paid with one click.</p>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ── CTA ── -->
<section class="cta-section">
  <div class="container text-center">
    <h2 class="section-title">Take Control of Your Finances Today</h2>
    <p class="section-sub mx-auto" style="margin-bottom:36px;">Join and start tracking. It's free, private, and takes less than a minute to set up.</p>
    <a href="register.php" class="btn-hero-primary" style="font-size:1.05rem;padding:16px 40px;">
      <i class="fas fa-rocket"></i> Create Free Account
    </a>
  </div>
</section>

<!-- ── Footer ── -->
<footer class="landing-footer">
  <p>© <?= date('Y') ?> DigiTracker &mdash; Personal Finance Manager &nbsp;|&nbsp;
     <a href="login.php">Sign In</a> &nbsp;|&nbsp;
     <a href="register.php">Register</a>
  </p>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
