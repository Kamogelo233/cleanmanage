<?php
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => false,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

$host = 'localhost';
$user = 'root';
$pass = '';
$dbname = 'cleanmanage_db';

$conn = new mysqli($host, $user, $pass, $dbname);

if ($conn->connect_error) {
    $fallback = new mysqli($host, $user, $pass);
    if ($fallback->connect_error) {
        die('Database connection failed: ' . $fallback->connect_error);
    }

    $fallback->query("CREATE DATABASE IF NOT EXISTS `$dbname`");
    $conn = new mysqli($host, $user, $pass, $dbname);

    if ($conn->connect_error) {
        die('Database connection failed after creating the database: ' . $conn->connect_error);
    }
}

$conn->set_charset('utf8mb4');

$schemaQueries = [
    "CREATE TABLE IF NOT EXISTS services (
        id INT AUTO_INCREMENT PRIMARY KEY,
        service_name VARCHAR(100) NOT NULL UNIQUE,
        price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        duration_hours DECIMAL(5,2) NOT NULL DEFAULT 0.00,
        required_staff_count INT NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE TABLE IF NOT EXISTS customers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        phone VARCHAR(30) NOT NULL,
        email VARCHAR(100) DEFAULT NULL,
        address TEXT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE TABLE IF NOT EXISTS employees (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        role VARCHAR(50) NOT NULL,
        phone VARCHAR(30) DEFAULT NULL,
        email VARCHAR(100) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE TABLE IF NOT EXISTS bookings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        customer_id INT NOT NULL,
        employee_id VARCHAR(255) DEFAULT NULL,
        service_id INT DEFAULT NULL,
        service_type VARCHAR(100) NOT NULL DEFAULT 'Cleaning Service',
        scheduled_date DATE DEFAULT NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'pending',
        notes TEXT DEFAULT NULL,
        proof_photo VARCHAR(255) DEFAULT NULL,
        delivery_confirmed_at DATETIME DEFAULT NULL,
        feedback_rating TINYINT DEFAULT NULL,
        feedback_comment TEXT DEFAULT NULL,
        whatsapp_sent TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_bookings_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE SET NULL
    )",
    "CREATE TABLE IF NOT EXISTS payments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        booking_id INT NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        payment_date DATE DEFAULT NULL,
        method VARCHAR(30) NOT NULL DEFAULT 'eft',
        reference_no VARCHAR(100) DEFAULT NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'pending'
    )",
    "CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        email VARCHAR(150) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        role VARCHAR(20) NOT NULL DEFAULT 'customer',
        reset_token VARCHAR(255) DEFAULT NULL,
        reset_expires DATETIME DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE TABLE IF NOT EXISTS api_tokens (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        token_hash VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        expires_at DATETIME NOT NULL,
        last_used_at TIMETIME NULL,
        KEY idx_api_tokens_user_id (user_id),
        KEY idx_api_tokens_hash (token_hash),
        CONSTRAINT fk_api_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )"
];

foreach ($schemaQueries as $query) {
    $conn->query($query);
}

$serviceColumns = [];
$serviceColumnsResult = $conn->query('SHOW COLUMNS FROM services');
if ($serviceColumnsResult) {
    while ($column = $serviceColumnsResult->fetch_assoc()) {
        $serviceColumns[] = $column['Field'];
    }
}

if (!in_array('required_staff_count', $serviceColumns, true)) {
    $conn->query('ALTER TABLE services ADD COLUMN required_staff_count INT NOT NULL DEFAULT 1 AFTER duration_hours');
}

$bookingColumns = [];
$bookingColumnsResult = $conn->query('SHOW COLUMNS FROM bookings');
if ($bookingColumnsResult) {
    while ($column = $bookingColumnsResult->fetch_assoc()) {
        $bookingColumns[] = $column['Field'];
    }
}

if (!in_array('service_id', $bookingColumns, true)) {
    $conn->query('ALTER TABLE bookings ADD COLUMN service_id INT NULL AFTER employee_id');
}

if (!in_array('service_type', $bookingColumns, true)) {
    $conn->query("ALTER TABLE bookings ADD COLUMN service_type VARCHAR(100) NOT NULL DEFAULT 'Cleaning Service' AFTER service_id");
}

if (!in_array('proof_photo', $bookingColumns, true)) {
    $conn->query('ALTER TABLE bookings ADD COLUMN proof_photo VARCHAR(255) NULL AFTER notes');
}

if (!in_array('whatsapp_sent', $bookingColumns, true)) {
    $conn->query('ALTER TABLE bookings ADD COLUMN whatsapp_sent TINYINT(1) NOT NULL DEFAULT 0 AFTER proof_photo');
    $bookingColumns[] = 'whatsapp_sent';
}

if (!in_array('delivery_confirmed_at', $bookingColumns, true)) {
    $conn->query('ALTER TABLE bookings ADD COLUMN delivery_confirmed_at DATETIME DEFAULT NULL AFTER proof_photo');
    $bookingColumns[] = 'delivery_confirmed_at';
}

if (!in_array('feedback_rating', $bookingColumns, true)) {
    $conn->query('ALTER TABLE bookings ADD COLUMN feedback_rating TINYINT DEFAULT NULL AFTER delivery_confirmed_at');
    $bookingColumns[] = 'feedback_rating';
}

if (!in_array('feedback_comment', $bookingColumns, true)) {
    $conn->query('ALTER TABLE bookings ADD COLUMN feedback_comment TEXT DEFAULT NULL AFTER feedback_rating');
    $bookingColumns[] = 'feedback_comment';
}

$paymentColumns = [];
$paymentColumnsResult = $conn->query('SHOW COLUMNS FROM payments');
if ($paymentColumnsResult) {
    while ($column = $paymentColumnsResult->fetch_assoc()) {
        $paymentColumns[] = $column['Field'];
    }
}

if (!in_array('reference_no', $paymentColumns, true)) {
    $conn->query('ALTER TABLE payments ADD COLUMN reference_no VARCHAR(100) DEFAULT NULL AFTER method');
}

if (in_array('employee_id', $bookingColumns, true)) {
    $employeeTypeResult = $conn->query("SHOW COLUMNS FROM bookings LIKE 'employee_id'");
    if ($employeeTypeResult && $employeeTypeResult->num_rows > 0) {
        $employeeTypeRow = $employeeTypeResult->fetch_assoc();
        $employeeType = strtolower($employeeTypeRow['Type'] ?? '');
        if (strpos($employeeType, 'varchar') === false && strpos($employeeType, 'int') !== false) {
            $conn->query('ALTER TABLE bookings MODIFY employee_id VARCHAR(255) NULL');
        }
    }
}

$userColumns = [];
$userColumnsResult = $conn->query('SHOW COLUMNS FROM users');
if ($userColumnsResult) {
    while ($column = $userColumnsResult->fetch_assoc()) {
        $userColumns[] = $column['Field'];
    }
}

if (!in_array('reset_token', $userColumns, true)) {
    $conn->query('ALTER TABLE users ADD COLUMN reset_token VARCHAR(255) NULL AFTER role');
}

if (!in_array('reset_expires', $userColumns, true)) {
    $conn->query('ALTER TABLE users ADD COLUMN reset_expires DATETIME NULL AFTER reset_token');
}

$servicesSeed = [
    ['Standard Cleaning', 350.00, 2.00, 1],
    ['Deep Cleaning', 750.00, 4.00, 2],
    ['Move In/Out', 1200.00, 6.00, 2],
    ['Office Cleaning', 800.00, 5.00, 3],
    ['Window Cleaning', 450.00, 3.00, 2],
    ['Post-Construction', 1500.00, 8.00, 4],
    ['Residential Cleaning', 420.00, 2.50, 1],
    ['Apartment Cleaning', 500.00, 3.00, 2],
    ['Luxury Home Cleaning', 950.00, 5.00, 2],
    ['Vacation Rental Cleaning', 600.00, 3.50, 2],
    ['Bathroom Sanitization', 380.00, 2.00, 1],
    ['Kitchen Deep Clean', 470.00, 3.00, 2],
    ['Carpet Cleaning', 650.00, 4.00, 2],
    ['Tile and Grout Cleaning', 700.00, 4.50, 2],
    ['Upholstery Cleaning', 780.00, 5.00, 2],
    ['End of Lease Cleaning', 1100.00, 6.00, 2],
    ['Commercial Cleaning', 1300.00, 6.50, 3],
    ['School Cleaning', 900.00, 5.00, 3],
    ['Hospitality Cleaning', 1200.00, 6.00, 3],
    ['Garden Sweep & Debris Removal', 560.00, 3.00, 2],
];

foreach ($servicesSeed as $service) {
    $serviceName = $service[0];
    $price = (float)$service[1];
    $duration = (float)$service[2];
    $requiredStaff = (int)$service[3];
    $serviceStmt = $conn->prepare('INSERT INTO services (service_name, price, duration_hours, required_staff_count) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE price = VALUES(price), duration_hours = VALUES(duration_hours), required_staff_count = VALUES(required_staff_count)');
    if (!$serviceStmt) {
        die($conn->error);
    }
    $serviceStmt->bind_param('sddi', $serviceName, $price, $duration, $requiredStaff);
    $serviceStmt->execute();
    $serviceStmt->close();
}

$constraintCheck = $conn->query("SELECT 1 FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bookings' AND COLUMN_NAME = 'service_id' AND REFERENCED_TABLE_NAME = 'services' LIMIT 1");
if ($constraintCheck && $constraintCheck->num_rows === 0) {
    $conn->query('ALTER TABLE bookings ADD CONSTRAINT fk_bookings_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE SET NULL');
}

require_once __DIR__ . '/config.php';
$appEnvironmentValues = loadEnvFile(__DIR__ . '/../.env');
$appEnvironment = strtolower(trim($appEnvironmentValues['APP_ENV'] ?? (getenv('APP_ENV') ?: 'local')));
$seedUsers = $appEnvironment === 'production' ? [] : [
    ['System Administrator', 'admin@cleanmanage.com', 'Admin@2026!', 'admin'],
    ['Business Owner', 'owner@cleanmanage.com', 'Owner@2026!', 'owner'],
    ['Operations Manager', 'manager@cleanmanage.com', 'Manager@2026!', 'manager'],
    ['Finance Officer', 'finance@cleanmanage.com', 'Finance@2026!', 'finance'],
    ['Senior Cleaner', 'cleaner@cleanmanage.com', 'Cleaner@2026!', 'cleaner'],
];

if ($appEnvironment !== 'production') {
    foreach ($seedUsers as $seedUser) {
        [$name, $email, $password, $role] = $seedUser;
        $seedStmt = $conn->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $seedStmt->bind_param('s', $email);
        $seedStmt->execute();
        $existingUser = $seedStmt->get_result()->fetch_assoc();
        $seedStmt->close();

        if (!$existingUser) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $insertStmt = $conn->prepare('INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)');
            if (!$insertStmt) {
                die($conn->error);
            }
            $insertStmt->bind_param('ssss', $name, $email, $hash, $role);
            $insertStmt->execute();
            $insertStmt->close();
        }
    }
}

function setFlash(string $type, string $message): void
{
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function getFlash(): ?array
{
    if (!isset($_SESSION['flash'])) {
        return null;
    }

    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

function renderFlash(): void
{
    $flash = getFlash();
    if (!$flash) {
        return;
    }

    $class = $flash['type'] === 'success' ? 'alert-success' : ($flash['type'] === 'danger' ? 'alert-danger' : 'alert-info');
    echo '<div class="alert ' . htmlspecialchars($class) . ' alert-dismissible fade show" role="alert">' . htmlspecialchars($flash['message']) . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>';
}

function redirect($url)
{
    header('Location: ' . $url);
    exit;
}

function validatePhone(string $phone): bool
{
    $phone = trim($phone);
    return $phone !== '' && preg_match('/^[0-9+()\-\s]{7,20}$/', $phone) === 1;
}

function validateEmail(string $email): bool
{
    return filter_var(trim($email), FILTER_VALIDATE_EMAIL) !== false;
}

function evaluatePasswordStrength(string $password): array
{
    $password = trim($password);
    $errors = [];

    if ($password === '') {
        return ['Password is required.'];
    }

    if (strlen($password) < 12) {
        $errors[] = 'Password must be at least 12 characters long.';
    }

    if (preg_match('/^0/', $password) === 1) {
        $errors[] = 'Password must not start with 0.';
    }

    if (preg_match('/\s/', $password) === 1) {
        $errors[] = 'Password cannot contain spaces.';
    }

    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = 'Password must contain at least one uppercase letter.';
    }

    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = 'Password must contain at least one lowercase letter.';
    }

    if (!preg_match('/\d/', $password)) {
        $errors[] = 'Password must contain at least one number.';
    }

    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        $errors[] = 'Password must contain at least one special character.';
    }

    return $errors;
}

function isStrongPassword(string $password): bool
{
    return empty(evaluatePasswordStrength($password));
}

function buildBookingConfirmationMessage(string $customerName, string $serviceType, string $scheduledDate, string $status): string
{
    $date = $scheduledDate !== '' ? $scheduledDate : 'your selected date';
    return "Hello " . $customerName . ",\n\nYour cleaning booking for " . $serviceType . " has been updated to status: " . $status . ".\nScheduled date: " . $date . ".\n\nThank you for choosing CleanManage.";
}

function normalizeWhatsAppNumber(string $phone): string
{
    return preg_replace('/\D+/', '', trim($phone)) ?? '';
}

function generateWhatsAppLink(string $phone, string $customerName, string $serviceType, string $scheduledDate, string $status): string
{
    $cleanPhone = normalizeWhatsAppNumber($phone);
    if ($cleanPhone === '') {
        return '';
    }

    $message = buildBookingConfirmationMessage($customerName, $serviceType, $scheduledDate, $status);
    return 'https://wa.me/' . $cleanPhone . '?text=' . urlencode($message);
}

function sendBookingConfirmationEmail(string $email, string $customerName, string $serviceType, string $scheduledDate, string $status): bool
{
    if (!validateEmail($email)) {
        return false;
    }

    require_once __DIR__ . '/config.php';

    $subject = 'CleanManage booking confirmation';
    $message = buildBookingConfirmationMessage($customerName, $serviceType, $scheduledDate, $status);
    $smtp = getSmtpConfig();

    if (!empty($smtp['enabled'])) {
        $fromEmail = $smtp['from_email'] ?? 'no-reply@cleanmanage.local';
        $fromName = $smtp['from_name'] ?? 'CleanManage';
        $sent = smtpSendEmail($email, $subject, $message, $fromEmail, $fromName);
        if ($sent) {
            return true;
        }
    }

    $headers = [
        'From: ' . ($smtp['from_email'] ?? 'no-reply@cleanmanage.local'),
        'Reply-To: ' . ($smtp['from_email'] ?? 'no-reply@cleanmanage.local'),
        'X-Mailer: PHP/' . phpversion(),
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8'
    ];

    $sent = @mail($email, $subject, $message, implode("\r\n", $headers));
    if (!$sent) {
        $logDir = __DIR__ . '/../uploads';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0777, true);
        }

        $logFile = $logDir . '/email_log.txt';
        $logEntry = date('Y-m-d H:i:s') . ' - Failed to send email to ' . $email . ' for ' . $serviceType . PHP_EOL;
        file_put_contents($logFile, $logEntry, FILE_APPEND);
        return false;
    }

    return true;
}
