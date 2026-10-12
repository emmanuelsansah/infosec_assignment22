<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/backup_lib.php';

$mode = $argv[1] ?? '--run';
try {
    if ($mode === '--self-test') {
        echo json_encode(backupSelfTest(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
        exit(0);
    }

    if ($mode === '--check') {
        backupTestDriveConnection();
        backupDriveFolderId();
        echo "Google Drive authorization and backup folder are available.\n";
        exit(0);
    }

    if ($mode !== '--run') {
        fwrite(STDERR, "Usage: php backup_run.php [--run|--check|--self-test]\n");
        exit(2);
    }

    $historyId = backupCreate();
    echo 'Backup completed. History entry #' . $historyId . ".\n";
    exit(0);
} catch (Throwable $exception) {
    backupLog('CLI operation failed: ' . $exception->getMessage());
    fwrite(STDERR, 'Backup operation failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}