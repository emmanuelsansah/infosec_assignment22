<?php
require_once __DIR__ . '/db.php';

function generateSalt(): string
{
    return bin2hex(random_bytes(16));
}

function generateOtp(): string
{
    return str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
}

function hashPasswordWithPepper(string $password, string $salt): string
{
    return hash_hmac('sha256', $password, APP_PEPPER . $salt);
}

function verifyPassword(string $password, string $storedHash, string $salt): bool
{
    return hash_equals($storedHash, hashPasswordWithPepper($password, $salt));
}

function setFlash(string $key, string $message): void
{
    $_SESSION[$key] = $message;
}

function getFlash(string $key): ?string
{
    if (!isset($_SESSION[$key])) {
        return null;
    }

    $message = $_SESSION[$key];
    unset($_SESSION[$key]);
    return $message;
}

function requireAuth(): array
{
    global $pdo;

    if (empty($_SESSION['user_id'])) {
        redirect('login.php');
    }

    $stmt = $pdo->prepare('SELECT id, name, email, role FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user) {
        session_unset();
        session_destroy();
        redirect('login.php');
    }

    return $user;
}

function sendOtpEmail(string $email, string $otp): bool
{
    $subject = 'Your InfoSec verification code';
    $message = "Your verification code is: {$otp}\n\nThis code expires in 5 minutes.\n\nIf you did not request this, please ignore this email.";

    if (file_exists(__DIR__ . '/vendor/autoload.php')) {
        require_once __DIR__ . '/vendor/autoload.php';

        if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
            $mail = new PHPMailer\PHPMailer\PHPMailer();
            $mail->isSMTP();
            $mail->Host = defined('SMTP_HOST') ? SMTP_HOST : '';
            $mail->SMTPAuth = !empty(SMTP_HOST);
            $mail->Username = defined('SMTP_USERNAME') ? SMTP_USERNAME : '';
            $mail->Password = defined('SMTP_PASSWORD') ? SMTP_PASSWORD : '';
            $mail->SMTPSecure = defined('SMTP_ENCRYPTION') ? SMTP_ENCRYPTION : 'tls';
            $mail->Port = defined('SMTP_PORT') ? SMTP_PORT : 587;
            $mail->setFrom(EMAIL_FROM, APP_NAME);
            $mail->addAddress($email);
            $mail->Subject = $subject;
            $mail->Body = $message;
            $mail->AltBody = $message;

            if (!empty(SMTP_HOST) && !empty(SMTP_USERNAME) && !empty(SMTP_PASSWORD)) {
                try {
                    $mail->send();
                    return true;
                } catch (Exception $e) {
                    $_SESSION['debug_otp'] = $otp;
                    error_log('SMTP send failed: ' . $e->getMessage());
                    return false;
                }
            }
        }
    }

    if (!empty(SMTP_HOST) && !empty(SMTP_USERNAME) && !empty(SMTP_PASSWORD)) {
        $logFile = __DIR__ . '/otp_debug.log';
        file_put_contents($logFile, date('Y-m-d H:i:s') . ' | ' . $email . ' | ' . $otp . PHP_EOL, FILE_APPEND);
        $_SESSION['debug_otp'] = $otp;
        return true;
    }

    $headers = "From: " . EMAIL_FROM . "\r\nReply-To: " . EMAIL_FROM . "\r\nContent-Type: text/plain; charset=UTF-8\r\n";
    $sent = @mail($email, $subject, $message, $headers);

    if (!$sent) {
        $logFile = __DIR__ . '/otp_debug.log';
        file_put_contents($logFile, date('Y-m-d H:i:s') . ' | ' . $email . ' | ' . $otp . PHP_EOL, FILE_APPEND);
        $_SESSION['debug_otp'] = $otp;
        return true;
    }

    return true;
}
