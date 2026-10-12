<?php
require_once __DIR__ . '/functions.php';
$errorMessage = getFlash('error');
$successMessage = getFlash('success');

if (empty($_SESSION['otp_user_id'])) {
    redirect('login.php');
}

$debugOtp = $_SESSION['debug_otp'] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Verify OTP | InfoSec</title>
  <link rel="stylesheet" href="style.css" />
</head>
<body>
  <header>
    <h1>InfoSec System</h1>
    <nav>
      <a href="index.php">Home</a>
      <a href="login.php">Login</a>
      <a href="register.php">Register</a>
    </nav>
  </header>

  <main class="auth-page">
    <section class="auth-container">
      <div class="auth-logo">📲</div>
      <h2>Verify identity</h2>
      <p class="subtitle">Enter the 6-digit code sent to your email</p>

      <?php if ($errorMessage): ?>
        <div class="alert error"><?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?></div>
      <?php endif; ?>

      <?php if ($successMessage): ?>
        <div class="alert success"><?php echo htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8'); ?></div>
      <?php endif; ?>

      <?php if ($debugOtp): ?>
        <div class="otp-box">Debug OTP: <strong><?php echo htmlspecialchars($debugOtp, ENT_QUOTES, 'UTF-8'); ?></strong></div>
      <?php endif; ?>

      <form method="post" action="verify_otp.php">
        <div class="form-group">
          <label for="otp">Verification code</label>
          <input id="otp" name="otp" type="text" maxlength="6" placeholder="Enter 6-digit code" required />
        </div>

        <button type="submit" class="auth-button">Verify</button>
      </form>

      <p class="auth-link">
        <a href="login.php">Back to login</a>
      </p>
    </section>
  </main>

  <script src="script.js"></script>
</body>
</html>
