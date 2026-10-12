<?php
require_once __DIR__ . '/functions.php';
$user = requireAuth();

if ($user['role'] === 'admin') {
  redirect('admin.php');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Dashboard | InfoSec</title>
  <link rel="stylesheet" href="style.css" />
</head>
<body>
  <header>
    <h1>InfoSec System</h1>
    <nav>
      <a href="index.php">Home</a>
      <a href="dashboard.php">Dashboard</a>
      <a href="logout.php">Logout</a>
    </nav>
  </header>

  <main class="dashboard-wrap">
    <section class="dashboard-card">
      <h2>Welcome, <?php echo htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8'); ?>!</h2>
      <p class="subtitle" style="text-align:left; margin-top: 10px;">You have successfully completed login and OTP verification.</p>

      <div class="user-meta">
        <div class="meta-box">
          <strong>Name</strong>
          <span><?php echo htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
        <div class="meta-box">
          <strong>Email</strong>
          <span><?php echo htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
        <div class="meta-box">
          <strong>Role</strong>
          <span><?php echo htmlspecialchars($user['role'], ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
      </div>

      <form method="post" action="logout.php" style="margin-top: 24px;">
        <button type="submit" class="auth-button" style="max-width: 220px;">Logout</button>
      </form>
    </section>
  </main>
</body>
</html>
