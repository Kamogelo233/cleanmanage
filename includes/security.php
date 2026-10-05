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

function secureRedirect(string $destination): void
{
    $scriptPath = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
    $parts = array_filter(explode('/', trim(dirname($scriptPath), '/')));
    $prefix = count($parts) > 0 ? str_repeat('../', count($parts)) : '';
    header('Location: ' . $prefix . ltrim($destination, '/'));
    exit;
}

function isAuthenticated(): bool
{
    return !empty($_SESSION['user_id']);
}

function currentUserRole(): string
{
    return $_SESSION['user_role'] ?? 'guest';
}

function currentUserEmployeeId(mysqli $conn): ?int
{
    if (empty($_SESSION['user_email']) && empty($_SESSION['user_name'])) {
        return null;
    }

    $email = trim($_SESSION['user_email'] ?? '');
    $name = trim($_SESSION['user_name'] ?? '');

    $stmt = $conn->prepare('SELECT id FROM employees WHERE email = ? OR name = ? ORDER BY id ASC LIMIT 1');
    $stmt->bind_param('ss', $email, $name);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();

    return $row ? (int)$row['id'] : null;
}

function requireAuth(): void
{
    if (!isAuthenticated()) {
        $_SESSION['error'] = 'Please log in first.';
        secureRedirect('login.php');
    }
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') . '">';
}

function verifyCsrfToken(?string $token): bool
{
    $expected = $_SESSION['csrf_token'] ?? '';
    if ($expected === '' || !is_string($token)) {
        return false;
    }

    return hash_equals($expected, $token);
}

function requireCsrfToken(string $fallbackPath = 'index.php'): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $_SESSION['error'] = 'Security token expired or invalid. Please try again.';
        secureRedirect($fallbackPath);
    }
}

function requireRole(array $allowedRoles): void
{
    requireAuth();

    $role = normalizeUserRole(currentUserRole());
    $normalizedAllowed = array_map(static fn (string $item): string => normalizeUserRole($item), $allowedRoles);

    if (!in_array($role, $normalizedAllowed, true)) {
        $_SESSION['error'] = 'You do not have permission to access that page.';
        secureRedirect('index.php');
    }
}

function normalizeUserRole(string $role): string
{
    $normalized = strtolower(trim($role));
    return match ($normalized) {
        'employee', 'staff' => 'cleaner',
        default => $normalized,
    };
}

function allowedRoles(): array
{
    return ['admin', 'owner', 'manager', 'finance', 'cleaner', 'customer'];
}

function requireAdmin(): void
{
    requireRole(['admin']);
}
