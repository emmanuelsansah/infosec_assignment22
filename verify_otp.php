<?php
require_once __DIR__ . '/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('login.php');
}

$userId = $_SESSION['otp_user_id'] ?? null;
if (!$userId) {
    setFlash('error', 'Your session expired. Please log in again.');
    redirect('login.php');
}

$enteredOtp = trim($_POST['otp'] ?? '');
$stmt = $GLOBALS['pdo']->prepare('SELECT id, name, email, role, otp_code, otp_expires_at FROM users WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $userId]);
$user = $stmt->fetch();

if (!$user || empty($user['otp_code']) || empty($user['otp_expires_at'])) {
    setFlash('error', 'No active verification code found. Please log in again.');
    redirect('login.php');
}

if (!hash_equals((string) $user['otp_code'], (string) $enteredOtp)) {
    setFlash('error', 'Invalid verification code.');
    redirect('verify-2fa.php');
}

if (time() > strtotime($user['otp_expires_at'])) {
    setFlash('error', 'Your verification code has expired. Please log in again.');
    redirect('login.php');
}

$stmt = $GLOBALS['pdo']->prepare('UPDATE users SET otp_code = NULL, otp_expires_at = NULL, email_verified = 1 WHERE id = :id');
$stmt->execute([':id' => $userId]);

$_SESSION['user_id'] = (int) $user['id'];
$_SESSION['user_name'] = $user['name'];
$_SESSION['user_email'] = $user['email'];
$_SESSION['user_role'] = $user['role'];
unset($_SESSION['otp_user_id'], $_SESSION['otp_code'], $_SESSION['otp_expires_at'], $_SESSION['debug_otp']);

redirect($user['role'] === 'admin' ? 'admin.php' : 'dashboard.php');
