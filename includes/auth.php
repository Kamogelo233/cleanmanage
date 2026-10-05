<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db.php';

function requireLogin(): void
{
    if (empty($_SESSION['user_id'])) {
        $_SESSION['error'] = 'Please log in to continue.';
        redirect('/login.php');
    }
}

function requireRole(array $allowedRoles): void
{
    requireLogin();

    $role = $_SESSION['user_role'] ?? '';
    if (!in_array($role, $allowedRoles, true)) {
        $_SESSION['error'] = 'You do not have permission to access that page.';
        redirect('/index.php');
    }
}

function isAdmin(): bool
{
    return ($_SESSION['user_role'] ?? '') === 'admin';
}

function isLoggedIn(): bool
{
    return !empty($_SESSION['user_id']);
}
