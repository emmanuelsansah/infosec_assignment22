<?php
require_once __DIR__ . '/backup_lib.php';
$user = backupRequireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('admin.php#backups');
}

if (!backupVerifyCsrf($_POST['csrf_token'] ?? null)) {
    setFlash('error', 'The request expired. Reload the dashboard and try again.');
    redirect('admin.php#backups');
}

try {
    $action = $_POST['action'] ?? '';
    if ($action === 'backup') {
        $historyId = backupCreate((int) $user['id']);
        setFlash('success', 'Backup completed and verified. History entry #' . $historyId . '.');
    } elseif ($action === 'restore') {
        $historyId = filter_var($_POST['history_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$historyId || $historyId < 1) {
            throw new RuntimeException('Select a valid backup to restore.');
        }
        backupRestore($historyId, (int) $user['id']);
        setFlash('success', 'The selected database backup was restored.');
    } else {
        throw new RuntimeException('Unknown backup operation.');
    }
} catch (Throwable $exception) {
    setFlash('error', $exception->getMessage());
}

redirect('admin.php#backups');