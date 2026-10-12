<?php
require_once __DIR__ . '/functions.php';

const BACKUP_HISTORY_TABLE = 'infosec_backup_history';
const GOOGLE_DRIVE_SCOPE = 'https://www.googleapis.com/auth/drive.file';

function backupPrivateDir(): string
{
    $directory = INFOSEC_PRIVATE_DIR;
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('The private backup directory could not be created.');
    }

    $realDirectory = realpath($directory);
    $documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
    $realDocumentRoot = $documentRoot !== '' ? realpath($documentRoot) : false;
    if ($realDirectory === false || ($realDocumentRoot !== false && str_starts_with(
        strtolower($realDirectory . DIRECTORY_SEPARATOR),
        strtolower(rtrim($realDocumentRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)
    ))) {
        throw new RuntimeException('Private backup storage must be outside the web root.');
    }

    return $realDirectory;
}

function backupPrivateFile(string $name): string
{
    if (!preg_match('/\A[a-zA-Z0-9._-]+\z/', $name)) {
        throw new InvalidArgumentException('Invalid private file name.');
    }

    return backupPrivateDir() . DIRECTORY_SEPARATOR . $name;
}

function backupEnsureSchema(): void
{
    $GLOBALS['pdo']->exec('CREATE TABLE IF NOT EXISTS ' . BACKUP_HISTORY_TABLE . ' (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        operation VARCHAR(16) NOT NULL,
        drive_file_id VARCHAR(255) DEFAULT NULL,
        file_name VARCHAR(255) DEFAULT NULL,
        size_bytes BIGINT UNSIGNED DEFAULT NULL,
        sha256 CHAR(64) DEFAULT NULL,
        status VARCHAR(16) NOT NULL,
        message VARCHAR(500) DEFAULT NULL,
        requested_by INT DEFAULT NULL,
        started_at DATETIME NOT NULL,
        completed_at DATETIME DEFAULT NULL,
        INDEX idx_backup_history_created (started_at),
        INDEX idx_backup_history_status (operation, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

function backupRequireAdmin(): array
{
    $user = requireAuth();
    if ($user['role'] !== 'admin') {
        http_response_code(403);
        exit('Administrator access required.');
    }

    backupEnsureSchema();
    return $user;
}

function backupCsrfToken(): string
{
    if (empty($_SESSION['backup_csrf_token'])) {
        $_SESSION['backup_csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['backup_csrf_token'];
}

function backupVerifyCsrf(?string $token): bool
{
    return is_string($token)
        && isset($_SESSION['backup_csrf_token'])
        && hash_equals($_SESSION['backup_csrf_token'], $token);
}

function backupGoogleConfigured(): bool
{
    return GOOGLE_CLIENT_ID !== '' && GOOGLE_CLIENT_SECRET !== '' && GOOGLE_REDIRECT_URI !== '';
}

function backupGoogleAuthorizationUrl(): string
{
    if (!backupGoogleConfigured()) {
        throw new RuntimeException('Google OAuth is not configured on this server.');
    }

    $state = bin2hex(random_bytes(32));
    $_SESSION['backup_oauth_state'] = $state;
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id' => GOOGLE_CLIENT_ID,
        'redirect_uri' => GOOGLE_REDIRECT_URI,
        'response_type' => 'code',
        'scope' => GOOGLE_DRIVE_SCOPE,
        'access_type' => 'offline',
        'prompt' => 'consent',
        'include_granted_scopes' => 'true',
        'state' => $state,
    ], '', '&', PHP_QUERY_RFC3986);
}

function backupHttpRequest(string $method, string $url, array $headers = [], ?string $body = null, $outputFile = null): array
{
    $responseHeaders = [];
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => $outputFile === null,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 300,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$responseHeaders): int {
            $parts = explode(':', $header, 2);
            if (count($parts) === 2) {
                $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return strlen($header);
        },
    ]);

    if ($body !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
    }
    if (is_resource($outputFile)) {
        curl_setopt($curl, CURLOPT_FILE, $outputFile);
    }

    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $failed = $response === false;
    curl_close($curl);

    if ($failed) {
        throw new RuntimeException('The Google API connection failed.');
    }

    return ['status' => $status, 'body' => is_string($response) ? $response : '', 'headers' => $responseHeaders];
}

function backupExchangeAuthorizationCode(string $code): void
{
    $response = backupHttpRequest('POST', 'https://oauth2.googleapis.com/token', [
        'Content-Type: application/x-www-form-urlencoded',
    ], http_build_query([
        'code' => $code,
        'client_id' => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'redirect_uri' => GOOGLE_REDIRECT_URI,
        'grant_type' => 'authorization_code',
    ], '', '&', PHP_QUERY_RFC3986));

    $tokens = json_decode($response['body'], true);
    if (is_array($tokens) && empty($tokens['refresh_token'])) {
        $tokens['refresh_token'] = backupReadTokens()['refresh_token'] ?? null;
    }
    if ($response['status'] !== 200 || !is_array($tokens) || empty($tokens['refresh_token'])) {
        throw new RuntimeException('Google authorization did not return a refresh token. Check OAuth consent and redirect URI settings.');
    }

    backupStoreTokens($tokens);
}

function backupStoreTokens(array $newTokens): void
{
    $path = backupPrivateFile('google-drive-token.json');
    $current = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];
    if (!is_array($current)) {
        $current = [];
    }

    $tokens = array_merge($current, $newTokens);
    if (!empty($tokens['expires_in'])) {
        $tokens['expires_at'] = time() + (int) $tokens['expires_in'];
    }

    $temporaryPath = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
    $encoded = json_encode($tokens, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (file_put_contents($temporaryPath, $encoded, LOCK_EX) === false) {
        throw new RuntimeException('Google authorization could not be saved to private storage.');
    }

    @chmod($temporaryPath, 0600);
    if (!rename($temporaryPath, $path)) {
        @unlink($temporaryPath);
        throw new RuntimeException('Google authorization could not be saved to private storage.');
    }
    @chmod($path, 0600);
}

function backupReadTokens(): array
{
    $path = backupPrivateFile('google-drive-token.json');
    if (!is_file($path)) {
        return [];
    }

    $tokens = json_decode((string) file_get_contents($path), true);
    return is_array($tokens) ? $tokens : [];
}

function backupDriveConnected(): bool
{
    return backupGoogleConfigured() && !empty(backupReadTokens()['refresh_token']);
}

function backupAccessToken(): string
{
    if (!backupGoogleConfigured()) {
        throw new RuntimeException('Google OAuth is not configured on this server.');
    }

    $tokens = backupReadTokens();
    if (empty($tokens['refresh_token'])) {
        throw new RuntimeException('Connect an administrator Google account before running backups.');
    }
    if (!empty($tokens['access_token']) && (int) ($tokens['expires_at'] ?? 0) > time() + 60) {
        return $tokens['access_token'];
    }

    $response = backupHttpRequest('POST', 'https://oauth2.googleapis.com/token', [
        'Content-Type: application/x-www-form-urlencoded',
    ], http_build_query([
        'client_id' => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'refresh_token' => $tokens['refresh_token'],
        'grant_type' => 'refresh_token',
    ], '', '&', PHP_QUERY_RFC3986));

    $refreshed = json_decode($response['body'], true);
    if ($response['status'] !== 200 || !is_array($refreshed) || empty($refreshed['access_token'])) {
        throw new RuntimeException('Google authorization expired or was revoked. Reconnect the administrator account.');
    }

    backupStoreTokens($refreshed);
    return $refreshed['access_token'];
}

function backupDriveRequest(string $method, string $url, ?string $body = null, array $headers = [], $outputFile = null): array
{
    $token = backupAccessToken();
    $headers[] = 'Authorization: Bearer ' . $token;
    $response = backupHttpRequest($method, $url, $headers, $body, $outputFile);
    if ($response['status'] < 200 || $response['status'] >= 300) {
        throw new RuntimeException('Google Drive API request failed (HTTP ' . $response['status'] . ').');
    }

    return $response;
}

function backupTestDriveConnection(): void
{
    backupDriveRequest('GET', 'https://www.googleapis.com/drive/v3/about?fields=kind');
}

function backupDriveFolderId(): string
{
    $tokens = backupReadTokens();
    if (!empty($tokens['folder_id'])) {
        try {
            backupDriveRequest('GET', 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($tokens['folder_id']) . '?fields=id,trashed');
            return $tokens['folder_id'];
        } catch (RuntimeException $exception) {
            unset($tokens['folder_id']);
        }
    }

    $query = "name = 'Project Backups' and mimeType = 'application/vnd.google-apps.folder' and trashed = false";
    $url = 'https://www.googleapis.com/drive/v3/files?' . http_build_query([
        'q' => $query,
        'spaces' => 'drive',
        'fields' => 'files(id,name)',
        'pageSize' => 100,
    ], '', '&', PHP_QUERY_RFC3986);
    $result = json_decode(backupDriveRequest('GET', $url)['body'], true);
    if (!empty($result['files'][0]['id'])) {
        $folderId = $result['files'][0]['id'];
    } else {
        $metadata = json_encode([
            'name' => 'Project Backups',
            'mimeType' => 'application/vnd.google-apps.folder',
        ], JSON_THROW_ON_ERROR);
        $created = json_decode(backupDriveRequest('POST', 'https://www.googleapis.com/drive/v3/files?fields=id', $metadata, [
            'Content-Type: application/json',
        ])['body'], true);
        $folderId = $created['id'] ?? '';
        if ($folderId === '') {
            throw new RuntimeException('Google Drive did not create the backup folder.');
        }
    }

    $tokens['folder_id'] = $folderId;
    backupStoreTokens($tokens);
    return $folderId;
}

function backupDriveUpload(string $path, string $name): array
{
    $size = filesize($path);
    if ($size === false || $size < 1) {
        throw new RuntimeException('The completed archive is empty or unreadable.');
    }

    $metadata = json_encode([
        'name' => $name,
        'mimeType' => 'application/gzip',
        'parents' => [backupDriveFolderId()],
    ], JSON_THROW_ON_ERROR);
    $start = backupDriveRequest('POST', 'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&fields=id,name,size,md5Checksum', $metadata, [
        'Content-Type: application/json; charset=UTF-8',
        'X-Upload-Content-Type: application/gzip',
        'X-Upload-Content-Length: ' . $size,
    ]);
    $sessionUrl = $start['headers']['location'] ?? '';
    if ($sessionUrl === '') {
        throw new RuntimeException('Google Drive did not provide a resumable upload session.');
    }

    $stream = fopen($path, 'rb');
    if ($stream === false) {
        throw new RuntimeException('The completed archive could not be opened for upload.');
    }

    $offset = 0;
    $upload = null;
    try {
        while ($offset < $size) {
            $length = min(8 * 1024 * 1024, $size - $offset);
            $chunk = fread($stream, $length);
            if ($chunk === false || strlen($chunk) !== $length) {
                throw new RuntimeException('The archive could not be read completely.');
            }

            $end = $offset + $length - 1;
            $upload = backupHttpRequest('PUT', $sessionUrl, [
                'Content-Type: application/gzip',
                'Content-Length: ' . $length,
                'Content-Range: bytes ' . $offset . '-' . $end . '/' . $size,
            ], $chunk);

            if ($upload['status'] === 308) {
                $range = $upload['headers']['range'] ?? '';
                $offset = preg_match('/bytes=0-(\d+)/', $range, $matches) ? (int) $matches[1] + 1 : $end + 1;
                continue;
            }
            if ($upload['status'] !== 200 && $upload['status'] !== 201) {
                throw new RuntimeException('Google Drive rejected the archive upload (HTTP ' . $upload['status'] . ').');
            }
            $offset = $end + 1;
        }
    } finally {
        fclose($stream);
    }

    $file = json_decode($upload['body'] ?? '', true);
    if (!is_array($file) || empty($file['id']) || (int) ($file['size'] ?? 0) !== (int) $size) {
        throw new RuntimeException('Google Drive did not confirm the complete uploaded archive.');
    }

    return $file;
}

function backupDriveDownload(string $fileId, string $destination): void
{
    $stream = fopen($destination, 'wb');
    if ($stream === false) {
        throw new RuntimeException('A private restore file could not be created.');
    }

    try {
        $response = backupDriveRequest('GET', 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($fileId) . '?alt=media', null, [], $stream);
    } catch (Throwable $exception) {
        fclose($stream);
        @unlink($destination);
        throw $exception;
    }
    fclose($stream);

    if ($response['status'] !== 200) {
        @unlink($destination);
        throw new RuntimeException('Google Drive did not return the selected backup.');
    }
}

function backupDriveDelete(string $fileId): void
{
    backupDriveRequest('DELETE', 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($fileId));
}

function backupHistory(int $limit = 50): array
{
    $limit = max(1, min(200, $limit));
    return $GLOBALS['pdo']->query('SELECT * FROM ' . BACKUP_HISTORY_TABLE . ' ORDER BY id DESC LIMIT ' . $limit)->fetchAll();
}

function backupConnectionLabel(): string
{
    if (!backupGoogleConfigured()) {
        return 'Not configured';
    }
    return backupDriveConnected() ? 'Connected' : 'Not connected';
}

function backupLog(string $message): void
{
    error_log('[INFOSEC backup] ' . preg_replace('/[\r\n]+/', ' ', $message));
}

function backupMysqlOptionsFile(): string
{
    $path = backupPrivateFile('mysql-' . bin2hex(random_bytes(8)) . '.cnf');
    $escape = static fn (string $value): string => '"' . addcslashes($value, "\\\"\n\r") . '"';
    $contents = "[client]\n"
        . 'host=' . $escape(DB_HOST) . "\n"
        . 'user=' . $escape(DB_USER) . "\n"
        . 'password=' . $escape(DB_PASS) . "\n"
        . "default-character-set=utf8mb4\n";

    if (file_put_contents($path, $contents, LOCK_EX) === false) {
        throw new RuntimeException('Private database client settings could not be created.');
    }
    @chmod($path, 0600);
    return $path;
}

function backupRunCommand(array $command, ?string $inputPath = null): void
{
    $nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $descriptors = [
        0 => $inputPath === null ? ['pipe', 'r'] : ['file', $inputPath, 'r'],
        1 => ['file', $nullDevice, 'a'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptors, $pipes, __DIR__, null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        throw new RuntimeException('The database utility could not be started.');
    }

    if ($inputPath === null) {
        fclose($pipes[0]);
    }
    $errorOutput = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        backupLog('Database utility failed with exit code ' . $exitCode . '.');
        throw new RuntimeException('The database utility failed. Check the server error log for details.');
    }
}

function backupCreateDatabaseDump(string $dumpPath): void
{
    if (!is_file(MYSQLDUMP_PATH)) {
        throw new RuntimeException('XAMPP mysqldump.exe was not found. Configure INFOSEC_MYSQLDUMP_PATH.');
    }

    $optionsPath = backupMysqlOptionsFile();
    try {
        $command = [
            MYSQLDUMP_PATH,
            '--defaults-extra-file=' . $optionsPath,
            '--single-transaction',
            '--quick',
            '--routines',
            '--triggers',
            '--events',
            '--hex-blob',
            '--add-drop-table',
            '--default-character-set=utf8mb4',
            '--ignore-table=' . DB_NAME . '.' . BACKUP_HISTORY_TABLE,
            '--result-file=' . $dumpPath,
            DB_NAME,
        ];
        backupRunCommand($command);
    } finally {
        @unlink($optionsPath);
    }

    if (!is_file($dumpPath) || filesize($dumpPath) === 0) {
        throw new RuntimeException('The database export was incomplete.');
    }
}

function backupProjectFileFilter(SplFileInfo $file): bool
{
    $name = $file->getFilename();
    if ($file->isDir()) {
        return !in_array($name, ['.git', 'node_modules', 'storage', 'backups'], true);
    }

    return $name !== '.env'
        && !str_starts_with($name, '.env.')
        && !preg_match('/(?:\.log|\.tmp|token\.json|credentials[^.]*\.json|client_secret[^.]*\.json)\z/i', $name);
}

function backupBuildArchive(): array
{
    $privateDir = backupPrivateDir();
    $stamp = gmdate('Y-m-d_H-i-s');
    $name = 'INFOSEC_' . $stamp . '.tar.gz';
    $id = bin2hex(random_bytes(8));
    $dumpPath = $privateDir . DIRECTORY_SEPARATOR . 'database-' . $id . '.sql';
    $tarPath = $privateDir . DIRECTORY_SEPARATOR . 'archive-' . $id . '.tar';
    $archivePath = $tarPath . '.gz';

    try {
        backupCreateDatabaseDump($dumpPath);
        $archive = new PharData($tarPath);
        $archive->addFile($dumpPath, 'database.sql');

        $root = realpath(__DIR__);
        $directory = new RecursiveDirectoryIterator(__DIR__, FilesystemIterator::SKIP_DOTS);
        $filtered = new RecursiveCallbackFilterIterator($directory, 'backupProjectFileFilter');
        $files = new RecursiveIteratorIterator($filtered, RecursiveIteratorIterator::LEAVES_ONLY);
        $fileCount = 0;
        foreach ($files as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $relativePath = substr($file->getPathname(), strlen($root) + 1);
            $archive->addFile($file->getPathname(), str_replace(DIRECTORY_SEPARATOR, '/', $relativePath));
            $fileCount++;
        }

        $archive->addFromString('manifest.json', json_encode([
            'format_version' => 1,
            'project' => 'INFOSEC',
            'created_at_utc' => gmdate(DATE_ATOM),
            'database_file' => 'database.sql',
            'project_file_count' => $fileCount,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        unset($archive);
        gc_collect_cycles();

        $compressed = (new PharData($tarPath))->compress(Phar::GZ);
        unset($compressed);
        gc_collect_cycles();
        @unlink($tarPath);

        $size = filesize($archivePath);
        $checksum = hash_file('sha256', $archivePath);
        if ($size === false || $size < 1 || $checksum === false) {
            throw new RuntimeException('The completed archive could not be verified.');
        }

        return [
            'path' => $archivePath,
            'name' => $name,
            'size' => $size,
            'sha256' => $checksum,
        ];
    } catch (Throwable $exception) {
        @unlink($dumpPath);
        @unlink($tarPath);
        @unlink($archivePath);
        throw $exception;
    } finally {
        @unlink($dumpPath);
    }
}

function backupInsertHistory(string $operation, ?int $requestedBy, ?array $source = null): int
{
    backupEnsureSchema();
    $statement = $GLOBALS['pdo']->prepare('INSERT INTO ' . BACKUP_HISTORY_TABLE . ' (operation, drive_file_id, file_name, size_bytes, sha256, status, message, requested_by, started_at) VALUES (:operation, :drive_file_id, :file_name, :size_bytes, :sha256, \'pending\', :message, :requested_by, UTC_TIMESTAMP())');
    $statement->execute([
        ':operation' => $operation,
        ':drive_file_id' => $source['drive_file_id'] ?? null,
        ':file_name' => $source['file_name'] ?? null,
        ':size_bytes' => $source['size_bytes'] ?? null,
        ':sha256' => $source['sha256'] ?? null,
        ':message' => 'Operation started.',
        ':requested_by' => $requestedBy,
    ]);
    return (int) $GLOBALS['pdo']->lastInsertId();
}

function backupUpdateHistory(int $id, string $status, string $message, ?array $details = null): void
{
    $statement = $GLOBALS['pdo']->prepare('UPDATE ' . BACKUP_HISTORY_TABLE . ' SET status = :status, message = :message, drive_file_id = COALESCE(:drive_file_id, drive_file_id), file_name = COALESCE(:file_name, file_name), size_bytes = COALESCE(:size_bytes, size_bytes), sha256 = COALESCE(:sha256, sha256), completed_at = UTC_TIMESTAMP() WHERE id = :id');
    $statement->execute([
        ':status' => $status,
        ':message' => substr($message, 0, 500),
        ':drive_file_id' => $details['drive_file_id'] ?? null,
        ':file_name' => $details['file_name'] ?? null,
        ':size_bytes' => $details['size_bytes'] ?? null,
        ':sha256' => $details['sha256'] ?? null,
        ':id' => $id,
    ]);
}

function backupApplyRetention(): void
{
    $cutoff = gmdate('Y-m-d H:i:s', time() - (BACKUP_RETENTION_DAYS * 86400));
    $statement = $GLOBALS['pdo']->prepare('SELECT id, drive_file_id FROM ' . BACKUP_HISTORY_TABLE . ' WHERE operation = \'backup\' AND status = \'success\' AND started_at < :cutoff ORDER BY started_at ASC');
    $statement->execute([':cutoff' => $cutoff]);
    foreach ($statement->fetchAll() as $backup) {
        try {
            backupDriveDelete($backup['drive_file_id']);
            backupUpdateHistory((int) $backup['id'], 'expired', 'Removed from Google Drive by the retention policy.');
        } catch (Throwable $exception) {
            backupLog('Retention cleanup failed for history entry ' . (int) $backup['id'] . '.');
        }
    }
}

function backupCreate(?int $requestedBy = null): int
{
    $historyId = backupInsertHistory('backup', $requestedBy);
    $archive = null;
    try {
        backupAccessToken();
        $archive = backupBuildArchive();
        $uploaded = backupDriveUpload($archive['path'], $archive['name']);
        if (!isset($uploaded['size']) || (int) $uploaded['size'] !== $archive['size']) {
            throw new RuntimeException('Google Drive did not confirm the complete archive size.');
        }
        if (!empty($uploaded['md5Checksum']) && !hash_equals($uploaded['md5Checksum'], hash_file('md5', $archive['path']))) {
            throw new RuntimeException('Google Drive archive checksum did not match the uploaded file.');
        }

        backupUpdateHistory($historyId, 'success', 'Uploaded and verified.', [
            'drive_file_id' => $uploaded['id'],
            'file_name' => $uploaded['name'] ?? $archive['name'],
            'size_bytes' => $archive['size'],
            'sha256' => $archive['sha256'],
        ]);
        backupApplyRetention();
        return $historyId;
    } catch (Throwable $exception) {
        backupUpdateHistory($historyId, 'failed', $exception->getMessage());
        backupLog('Backup history entry ' . $historyId . ' failed: ' . $exception->getMessage());
        throw $exception;
    } finally {
        if (is_array($archive) && is_file($archive['path'])) {
            @unlink($archive['path']);
        }
    }
}

function backupExtractDatabaseDump(string $archivePath, string $restoreDirectory): string
{
    $compressedArchive = new PharData($archivePath);
    $tarArchive = $compressedArchive->decompress();
    $manifestEntry = $tarArchive['manifest.json'] ?? null;
    if ($manifestEntry === null) {
        throw new RuntimeException('The selected file is not a valid INFOSEC backup.');
    }

    $manifest = json_decode($manifestEntry->getContent(), true);
    if (!is_array($manifest) || ($manifest['format_version'] ?? null) !== 1 || ($manifest['project'] ?? '') !== 'INFOSEC') {
        throw new RuntimeException('The selected file is not a supported INFOSEC backup.');
    }

    if (!is_dir($restoreDirectory) && !mkdir($restoreDirectory, 0700, true) && !is_dir($restoreDirectory)) {
        throw new RuntimeException('A private restore directory could not be created.');
    }
    $tarArchive->extractTo($restoreDirectory, ['database.sql'], true);
    $dumpPath = $restoreDirectory . DIRECTORY_SEPARATOR . 'database.sql';
    if (!is_file($dumpPath) || filesize($dumpPath) === 0) {
        throw new RuntimeException('The selected backup does not contain a complete database export.');
    }

    return $dumpPath;
}

function backupRestore(int $historyId, int $requestedBy): void
{
    $query = $GLOBALS['pdo']->prepare('SELECT id, drive_file_id, file_name, size_bytes, sha256 FROM ' . BACKUP_HISTORY_TABLE . ' WHERE id = :id AND operation = \'backup\' AND status = \'success\' LIMIT 1');
    $query->execute([':id' => $historyId]);
    $source = $query->fetch();
    if (!$source || empty($source['drive_file_id']) || empty($source['sha256'])) {
        throw new RuntimeException('That backup is not available for restoration.');
    }

    $restoreId = backupInsertHistory('restore', $requestedBy, [
        'drive_file_id' => $source['drive_file_id'],
        'file_name' => $source['file_name'],
        'size_bytes' => $source['size_bytes'],
        'sha256' => $source['sha256'],
    ]);
    $restoreDirectory = backupPrivateDir() . DIRECTORY_SEPARATOR . 'restore-' . bin2hex(random_bytes(8));
    $downloadPath = $restoreDirectory . '.tar.gz';

    try {
        backupCreate($requestedBy);
        backupDriveDownload($source['drive_file_id'], $downloadPath);
        $actualChecksum = hash_file('sha256', $downloadPath);
        if ($actualChecksum === false || !hash_equals($source['sha256'], $actualChecksum)) {
            throw new RuntimeException('The downloaded backup failed its integrity check.');
        }

        $dumpPath = backupExtractDatabaseDump($downloadPath, $restoreDirectory);
        $mysqlPath = dirname(MYSQLDUMP_PATH) . DIRECTORY_SEPARATOR . 'mysql.exe';
        if (!is_file($mysqlPath)) {
            throw new RuntimeException('XAMPP mysql.exe was not found beside mysqldump.exe.');
        }

        $optionsPath = backupMysqlOptionsFile();
        try {
            backupRunCommand([
                $mysqlPath,
                '--defaults-extra-file=' . $optionsPath,
                '--binary-mode=1',
                '--default-character-set=utf8mb4',
                '--database=' . DB_NAME,
            ], $dumpPath);
        } finally {
            @unlink($optionsPath);
        }

        backupUpdateHistory($restoreId, 'success', 'Database restored after a verified safety backup.');
    } catch (Throwable $exception) {
        backupUpdateHistory($restoreId, 'failed', $exception->getMessage());
        backupLog('Restore history entry ' . $restoreId . ' failed: ' . $exception->getMessage());
        throw $exception;
    } finally {
        @unlink($downloadPath);
        if (is_dir($restoreDirectory)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($restoreDirectory, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $file) {
                $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
            }
            @rmdir($restoreDirectory);
        }
    }
}

function backupSelfTest(): array
{
    backupEnsureSchema();
    $archive = backupBuildArchive();
    try {
        $phar = new PharData($archive['path']);
        $manifest = json_decode($phar['manifest.json']->getContent(), true);
        if (!is_array($manifest) || ($manifest['project'] ?? '') !== 'INFOSEC' || !isset($phar['database.sql'])) {
            throw new RuntimeException('The generated backup archive failed its contents check.');
        }

        return [
            'filename' => $archive['name'],
            'size_bytes' => $archive['size'],
            'project_files' => (int) ($manifest['project_file_count'] ?? 0),
            'database_dump_bytes' => strlen($phar['database.sql']->getContent()),
        ];
    } finally {
        @unlink($archive['path']);
    }
}

function backupFormatSize(?int $bytes): string
{
    if ($bytes === null || $bytes < 0) {
        return 'Not available';
    }
    if ($bytes < 1024) {
        return $bytes . ' B';
    }

    $units = ['KB', 'MB', 'GB', 'TB'];
    $size = (float) $bytes;
    foreach ($units as $unit) {
        $size /= 1024;
        if ($size < 1024 || $unit === 'TB') {
            return number_format($size, 1) . ' ' . $unit;
        }
    }

    return number_format($size, 1) . ' TB';
}