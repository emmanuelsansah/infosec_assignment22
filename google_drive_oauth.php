<?php
require_once __DIR__ . '/backup_lib.php';
backupRequireAdmin();

try {
    redirect(backupGoogleAuthorizationUrl());
} catch (Throwable $exception) {
    setFlash('error', $exception->getMessage());
    redirect('admin.php#backups');
}