<?php
require_once __DIR__ . '/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('login.php');
}

$email = trim(strtolower($_POST['email'] ?? ''));
$password = $_POST['password'] ?? '';

if (!$email || !$password) {
    setFlash('error', 'Please enter both email and password.');
    redirect('login.php');
}

$stmt = $GLOBALS['pdo']->prepare('SELECT id, name, email, password_hash, password_salt, role FROM users WHERE email = :email LIMIT 1');
$stmt->execute([':email' => $email]);
$user = $stmt->fetch();

if (!$user || !verifyPassword($password, $user['password_hash'], $user['password_salt'])) {
    setFlash('error', 'Invalid email or password.');
    redirect('login.php');
}

$otp = generateOtp();
$expiresAt = date('Y-m-d H:i:s', time() + OTP_EXPIRY_SECONDS);

$stmt = $GLOBALS['pdo']->prepare('UPDATE users SET otp_code = :otp_code, otp_expires_at = :otp_expires_at WHERE id = :id');
$stmt->execute([
    ':otp_code' => $otp,
    ':otp_expires_at' => $expiresAt,
    ':id' => $user['id'],
]);

$_SESSION['otp_user_id'] = (int) $user['id'];
$_SESSION['otp_code'] = $otp;
$_SESSION['otp_expires_at'] = $expiresAt;

sendOtpEmail($user['email'], $otp);
setFlash('success', 'A verification code has been sent to your email.');
redirect('verify-2fa.php');
