<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>InfoSec Authentication System</title>
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

  <main class="hero">
    <div class="hero-box">
      <h2>Secure login with email OTP</h2>
      <p>
        Register or sign in to your account. After successful password verification,
        a 6-digit OTP is emailed to you before access is granted.
      </p>
      <div class="buttons">
        <a href="login.php" class="btn">Login</a>
        <a href="register.php" class="btn secondary">Create account</a>
      </div>
    </div>
  </main>

  <section class="container overview">
    <h2>Security controls included</h2>

    <div class="security-grid">
      <article class="card">
        <h3>User identification</h3>
        <p>Each account is linked to a unique email and profile record in the database.</p>
      </article>
      <article class="card">
        <h3>Authentication</h3>
        <p>Users must provide the correct password before the system can generate an OTP.</p>
      </article>
      <article class="card">
        <h3>OTP verification</h3>
        <p>A 6-digit one-time password is sent to the user's email and must be entered before access is granted.</p>
      </article>
      <article class="card">
        <h3>Salt and pepper</h3>
        <p>Passwords are hashed using a unique salt and a site-wide pepper value before storage.</p>
      </article>
    </div>
  </section>

  <footer>
    <p>&copy; 2026 InfoSec Authentication System</p>
    <p>Database-backed OTP authentication demo</p>
  </footer>
</body>
</html>
