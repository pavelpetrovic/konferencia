<?php
declare(strict_types=1);

$config = require dirname(__DIR__, 2) . '/config.php';

date_default_timezone_set($config['timezone'] ?? 'Europe/Bratislava');

ini_set('session.use_strict_mode', '1');
session_set_cookie_params([
    'httponly' => true,
    'secure' => true,
    'samesite' => 'Lax',
    'path' => '/',
]);
session_start();

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');

function db(): PDO
{
    global $config;

    static $pdo = null;

    if ($pdo === null) {
        $dsn = 'mysql:host=' . $config['db_host']
             . ';dbname=' . $config['db_name']
             . ';charset=utf8mb4';

        $pdo = new PDO($dsn, $config['db_user'], $config['db_password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    return $pdo;
}

function q(string $sql, array $args = []): PDOStatement
{
    $statement = db()->prepare($sql);
    $statement->execute($args);

    return $statement;
}

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf(): string
{
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}

function verify_csrf(): void
{
    if (!hash_equals(csrf(), (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(403);
        exit('Neplatný bezpečnostný token.');
    }
}

function flash(string $message): void
{
    $_SESSION['flash'] = $message;
}

function go(string $url): never
{
    header('Location: ' . $url, true, 303);
    exit;
}

function classes(): array
{
    return q('SELECT * FROM classes ORDER BY id')->fetchAll();
}

function class_by_id(int $id): ?array
{
    return q('SELECT * FROM classes WHERE id = ?', [$id])->fetch() ?: null;
}

function student(): ?array
{
    $id = (int) ($_SESSION['student_id'] ?? 0);

    if (!$id) {
        return null;
    }

    return q(
        'SELECT id, class_id, name, email FROM students WHERE id = ?',
        [$id]
    )->fetch() ?: null;
}

function send_code(string $address, string $name, string $code): void
{
    global $config;

    $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';

    if (!is_file($autoload)) {
        throw new RuntimeException('PHPMailer missing: composer install');
    }

    require_once $autoload;

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = $config['smtp_host'];
    $mail->SMTPAuth = true;
    $mail->Username = $config['smtp_user'];
    $mail->Password = $config['smtp_password'];
    $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = (int) $config['smtp_port'];
    $mail->CharSet = 'UTF-8';

    $mail->setFrom($config['mail_from'], 'Minikonferencia z fyziky');
    $mail->addAddress($address, $name);
    $mail->Subject = 'Minikonferencia – prístupový kód';
    $mail->Body = "Ahoj $name,\n\n"
                . "Tvoj prístupový kód: $code\n\n"
                . "Prihlásenie: " . rtrim($config['base_url'], '/') . "\n\n"
                . "Ak si si kód nevyžiadal(a), túto správu ignoruj.\n";

    $mail->send();
}

// Permanent per-student code derived from the private server-side secret.
function student_code(array $student): string
{
    global $config;

    if (empty($config['code_secret']) || strlen($config['code_secret']) < 32) {
        throw new RuntimeException('Configure a strong code_secret in config.php');
    }

    $message = 'conference-student:' . $student['id']
             . ':' . strtolower($student['email']);

    return strtoupper(substr(
        hash_hmac('sha256', $message, $config['code_secret']),
        0,
        16
    ));
}
