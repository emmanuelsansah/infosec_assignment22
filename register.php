<?php
require_once __DIR__ . '/functions.php';
$errorMessage = getFlash('error');
$successMessage = getFlash('success');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Register | InfoSec</title>
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
      <div class="auth-logo">🛡️</div>
      <h2>Create account</h2>
      <p class="subtitle">Register and verify your email with OTP</p>

      <?php if ($errorMessage): ?>
        <div class="alert error"><?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?></div>
      <?php endif; ?>

      <?php if ($successMessage): ?>
        <div class="alert success"><?php echo htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8'); ?></div>
      <?php endif; ?>

      <form method="post" action="register_process.php">
        <div class="form-group">
          <label for="name">Full name</label>
          <input id="name" name="name" type="text" placeholder="Enter your full name" required />
        </div>

        <div class="form-group">
          <label for="email">Email</label>
          <input id="email" name="email" type="email" placeholder="Enter your email" required />
        </div>

        <div class="form-group">
          <label for="password">Password</label>
          <div class="password-wrapper">
            <input id="password" name="password" type="password" placeholder="Create a strong password" required />
            <button type="button" class="toggle-password" aria-label="Show password">👁</button>
          </div>
          <div id="passwordStrength">Password strength: -</div>
        </div>

        <div class="form-group">
          <label for="confirmPassword">Confirm password</label>
          <div class="password-wrapper">
            <input id="confirmPassword" name="confirmPassword" type="password" placeholder="Repeat your password" required />
            <button type="button" class="toggle-password" aria-label="Show password">👁</button>
          </div>
        </div>

        <button type="submit" class="auth-button">Register</button>
      </form>

      <p class="auth-link">
        Already have an account? <a href="login.php">Login</a>
      </p>
    </section>
  </main>

  <script src="script.js"></script>
</body>
</html>
