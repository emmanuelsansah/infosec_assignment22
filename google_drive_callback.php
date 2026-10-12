<?php
require_once __DIR__ . '/backup_lib.php';
backupRequireAdmin();

$expectedState = $_SESSION['backup_oauth_state'] ?? '';
unset($_SESSION['backup_oauth_state']);

if ($expectedState === '' || !isset($_GET['state']) || !hash_equals($expectedState, (string) $_GET['state'])) {
    setFlash('error', 'Google authorization could not be verified. Please reconnect and try again.');
    redirect('admin.php#backups');
}

if (!empty($_GET['error']) || empty($_GET['code'])) {
    setFlash('error', 'Google Drive authorization was not completed.');
    redirect('admin.php#backups');
}

try {
    backupExchangeAuthorizationCode((string) $_GET['code']);
    backupTestDriveConnection();
    backupDriveFolderId();
    setFlash('success', 'Google Drive connected. Backups will be stored in Project Backups.');
} catch (Throwable $exception) {
    backupLog('Google OAuth callback failed: ' . $exception->getMessage());
    setFlash('error', $exception->getMessage());
}

redirect('admin.php#backups');