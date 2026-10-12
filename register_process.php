<?php
require_once __DIR__ . '/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('register.php');
}

$name = trim($_POST['name'] ?? '');
$email = trim(strtolower($_POST['email'] ?? ''));
$password = $_POST['password'] ?? '';
$confirmPassword = $_POST['confirmPassword'] ?? '';
$role = 'user';

if (!$name || !$email || !$password || !$confirmPassword) {
    setFlash('error', 'Please complete all fields.');
    redirect('register.php');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    setFlash('error', 'Please enter a valid email address.');
    redirect('register.php');
}

if (strlen($password) < 8) {
    setFlash('error', 'Password must be at least 8 characters long.');
    redirect('register.php');
}

if ($password !== $confirmPassword) {
    setFlash('error', 'Passwords do not match.');
    redirect('register.php');
}

$stmt = $GLOBALS['pdo']->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
$stmt->execute([':email' => $email]);
if ($stmt->fetch()) {
    setFlash('error', 'This email is already registered.');
    redirect('register.php');
}

$salt = generateSalt();
$passwordHash = hashPasswordWithPepper($password, $salt);
$otp = generateOtp();
$expiresAt = date('Y-m-d H:i:s', time() + OTP_EXPIRY_SECONDS);

$stmt = $GLOBALS['pdo']->prepare('INSERT INTO users (name, email, password_hash, password_salt, role, otp_code, otp_expires_at) VALUES (:name, :email, :password_hash, :password_salt, :role, :otp_code, :otp_expires_at)');
$stmt->execute([
    ':name' => $name,
    ':email' => $email,
    ':password_hash' => $passwordHash,
    ':password_salt' => $salt,
    ':role' => $role,
    ':otp_code' => $otp,
    ':otp_expires_at' => $expiresAt,
]);

$_SESSION['otp_user_id'] = (int) $GLOBALS['pdo']->lastInsertId();
$_SESSION['otp_code'] = $otp;
$_SESSION['otp_expires_at'] = $expiresAt;

sendOtpEmail($email, $otp);
setFlash('success', 'Your account was created. Please enter the OTP sent to your email.');
redirect('verify-2fa.php');
