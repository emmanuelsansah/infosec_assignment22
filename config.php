<?php
session_start();

date_default_timezone_set('UTC');

$privateDir = getenv('INFOSEC_PRIVATE_DIR') ?: dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'INFOSEC';
$privateConfigPath = $privateDir . DIRECTORY_SEPARATOR . 'app-secrets.php';
$privateConfig = is_file($privateConfigPath) ? require $privateConfigPath : [];
$getSetting = static function (string $name, $default = '') use ($privateConfig) {
    $environmentValue = getenv($name);
    if ($environmentValue !== false) {
        return $environmentValue;
    }

    return $privateConfig[$name] ?? $default;
};

define('INFOSEC_PRIVATE_DIR', $privateDir);
define('APP_NAME', 'InfoSec Authentication System');
define('APP_PEPPER', (string) $getSetting('APP_PEPPER'));
define('DB_HOST', (string) $getSetting('DB_HOST', '127.0.0.1'));
define('DB_NAME', (string) $getSetting('DB_NAME', 'infosec_assignment'));
define('DB_USER', (string) $getSetting('DB_USER', 'root'));
define('DB_PASS', (string) $getSetting('DB_PASS'));
define('EMAIL_FROM', (string) $getSetting('EMAIL_FROM'));
define('SMTP_HOST', (string) $getSetting('SMTP_HOST'));
define('SMTP_USERNAME', (string) $getSetting('SMTP_USERNAME'));
define('SMTP_PASSWORD', (string) $getSetting('SMTP_PASSWORD'));
define('SMTP_PORT', (int) $getSetting('SMTP_PORT', 587));
define('SMTP_ENCRYPTION', (string) $getSetting('SMTP_ENCRYPTION', 'tls'));
define('OTP_EXPIRY_SECONDS', 300);
define('GOOGLE_CLIENT_ID', (string) getenv('INFOSEC_GOOGLE_CLIENT_ID'));
define('GOOGLE_CLIENT_SECRET', (string) getenv('INFOSEC_GOOGLE_CLIENT_SECRET'));
define('GOOGLE_REDIRECT_URI', (string) getenv('INFOSEC_GOOGLE_REDIRECT_URI'));
define('BACKUP_RETENTION_DAYS', max(1, (int) (getenv('INFOSEC_BACKUP_RETENTION_DAYS') ?: 30)));
define('MYSQLDUMP_PATH', (string) (getenv('INFOSEC_MYSQLDUMP_PATH') ?: 'C:\\xampp\\mysql\\bin\\mysqldump.exe'));

if (!function_exists('redirect')) {
    function redirect(string $path): void
    {
        header('Location: ' . $path);
        exit;
    }
}
