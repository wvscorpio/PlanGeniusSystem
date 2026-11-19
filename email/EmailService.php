<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$projectDirectory = dirname(__DIR__);
$envDirectory = is_file($projectDirectory . '/.env') ? $projectDirectory : $projectDirectory . '/.env';
$envFiles = is_file($envDirectory . '/.env') ? ['.env'] : ['.env.local'];
Dotenv\Dotenv::createImmutable($envDirectory, $envFiles)->safeLoad();

final class EmailService
{
    private string $logFile;

    public function __construct(?string $logFile = null)
    {
        $this->logFile = $logFile ?? dirname(__DIR__) . '/storage/logs/email.log';
    }

    public function send(string $recipientEmail, string $recipientName, string $subject, string $htmlBody): bool
    {
        try {
            $mail = $this->createMailer();
            $mail->addAddress($recipientEmail, $recipientName);
            $mail->Subject = $subject;
            $mail->Body = $htmlBody;
            $mail->AltBody = trim(strip_tags($htmlBody));
            $mail->send();

            $this->log('info', 'Email sent', [
                'recipient' => $recipientEmail,
                'subject' => $subject,
            ]);
            return true;
        } catch (\Throwable $exception) {
            $this->log('error', 'Email failed', [
                'recipient' => $recipientEmail,
                'subject' => $subject,
                'error' => $exception->getMessage(),
            ]);
            return false;
        }
    }

    public function sendTest(string $recipientEmail, string $recipientName): bool
    {
        $body = '<h2>PlanGenius Mailtrap test</h2>'
            . '<p>This confirms that PHP, PHPMailer, and Mailtrap SMTP are connected.</p>';

        return $this->send($recipientEmail, $recipientName, 'PlanGenius Mailtrap test email', $body);
    }

    public function sendReminder(
        string $recipientEmail,
        string $recipientName,
        string $subject,
        string $type,
        array $details,
        string $timeLeft
    ): bool {
        $escape = static fn ($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
        $name = $escape($recipientName ?: 'Student');
        $course = $escape(($details['courseCode'] ?? '') . ' - ' . ($details['courseName'] ?? ''));
        $title = $escape($details['title'] ?? $type);
        $dueDate = $escape($details['dueDate'] ?? 'N/A');
        $time = $escape($details['time'] ?? 'TBA');
        $location = $escape($details['location'] ?? 'TBA');
        $priority = $escape($details['priority'] ?? 'Medium');
        $safeType = $escape($type);
        $safeTimeLeft = $escape($timeLeft);

        $body = "<h2>PlanGenius Academic Alert</h2>
            <p>Hello <strong>{$name}</strong>,</p>
            <p>You have an upcoming academic deadline requiring attention.</p>
            <table>
              <tr><td>Type:</td><td>{$safeType}</td></tr>
              <tr><td>Course:</td><td>{$course}</td></tr>
              <tr><td>Task title:</td><td>{$title}</td></tr>
              <tr><td>Due date:</td><td>{$dueDate}</td></tr>
              <tr><td>Time:</td><td>{$time}</td></tr>
              <tr><td>Location:</td><td>{$location}</td></tr>
              <tr><td>Time remaining:</td><td>{$safeTimeLeft}</td></tr>
              <tr><td>Priority:</td><td>{$priority}</td></tr>
            </table>";

        return $this->send($recipientEmail, $recipientName, $subject, $body);
    }

    private function createMailer(): PHPMailer
    {
        $requiredVariables = [
            'MAILTRAP_HOST', 'MAILTRAP_PORT', 'MAILTRAP_USERNAME',
            'MAILTRAP_PASSWORD', 'MAIL_FROM_ADDRESS', 'MAIL_FROM_NAME',
        ];

        foreach ($requiredVariables as $variable) {
            if (!isset($_ENV[$variable]) || $_ENV[$variable] === '') {
                throw new RuntimeException('Missing email configuration: ' . $variable);
            }
        }

        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $_ENV['MAILTRAP_HOST'];
        $mail->SMTPAuth = true;
        $mail->Username = $_ENV['MAILTRAP_USERNAME'];
        $mail->Password = $_ENV['MAILTRAP_PASSWORD'];
        $mail->Port = (int)$_ENV['MAILTRAP_PORT'];
        $mail->SMTPSecure = $_ENV['MAILTRAP_ENCRYPTION'] ?? PHPMailer::ENCRYPTION_STARTTLS;
        $mail->CharSet = 'UTF-8';
        $mail->setFrom($_ENV['MAIL_FROM_ADDRESS'], $_ENV['MAIL_FROM_NAME']);
        $mail->isHTML(true);

        return $mail;
    }

    private function log(string $level, string $message, array $context = []): void
    {
        $directory = dirname($this->logFile);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $line = json_encode([
            'time' => date(DATE_ATOM),
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ], JSON_UNESCAPED_SLASHES) . PHP_EOL;

        file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX);
    }
}