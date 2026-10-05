<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/api.php';

$action = $_GET['action'] ?? ($_POST['action'] ?? 'health');

function buildDashboardPayload(mysqli $conn, array $user): array
{
    $role = $user['role'] ?? 'customer';
    $userEmail = trim($user['email'] ?? '');
    $userId = (int)($user['id'] ?? 0);

    if ($role === 'admin') {
        return [
            'summary' => [
                'customers' => (int)$conn->query('SELECT COUNT(*) AS total FROM customers')->fetch_assoc()['total'],
                'employees' => (int)$conn->query('SELECT COUNT(*) AS total FROM employees')->fetch_assoc()['total'],
                'bookings' => (int)$conn->query('SELECT COUNT(*) AS total FROM bookings')->fetch_assoc()['total'],
                'payments' => (int)$conn->query('SELECT COUNT(*) AS total FROM payments')->fetch_assoc()['total'],
                'revenue' => (float)$conn->query('SELECT COALESCE(SUM(amount), 0) AS total FROM payments WHERE status = "paid"')->fetch_assoc()['total'],
                'pending_amount' => (float)$conn->query('SELECT COALESCE(SUM(amount), 0) AS total FROM payments WHERE status = "pending"')->fetch_assoc()['total'],
            ],
            'bookings_status' => [
                'pending' => (int)$conn->query('SELECT COUNT(*) AS total FROM bookings WHERE status = "pending"')->fetch_assoc()['total'],
                'assigned' => (int)$conn->query('SELECT COUNT(*) AS total FROM bookings WHERE status = "assigned"')->fetch_assoc()['total'],
                'in_progress' => (int)$conn->query('SELECT COUNT(*) AS total FROM bookings WHERE status = "in_progress"')->fetch_assoc()['total'],
                'completed' => (int)$conn->query('SELECT COUNT(*) AS total FROM bookings WHERE status = "completed"')->fetch_assoc()['total'],
            ],
        ];
    }

    if ($role === 'employee') {
        $employeeId = null;
        $empStmt = $conn->prepare('SELECT id FROM employees WHERE email = ? OR name = ? LIMIT 1');
        if ($empStmt) {
            $empStmt->bind_param('ss', $userEmail, $user['name']);
            $empStmt->execute();
            $empRow = $empStmt->get_result()->fetch_assoc();
            $empStmt->close();
            $employeeId = $empRow ? (int)$empRow['id'] : null;
        }

        return [
            'summary' => [
                'assigned_jobs' => $employeeId ? (int)$conn->query("SELECT COUNT(*) AS total FROM bookings WHERE FIND_IN_SET($employeeId, employee_id) > 0")->fetch_assoc()['total'] : 0,
                'open_tasks' => $employeeId ? (int)$conn->query("SELECT COUNT(*) AS total FROM bookings WHERE FIND_IN_SET($employeeId, employee_id) > 0 AND status IN ('pending', 'assigned', 'in_progress')")->fetch_assoc()['total'] : 0,
                'completed_jobs' => $employeeId ? (int)$conn->query("SELECT COUNT(*) AS total FROM bookings WHERE FIND_IN_SET($employeeId, employee_id) > 0 AND status = 'completed'")->fetch_assoc()['total'] : 0,
            ],
        ];
    }

    $customerId = null;
    $custStmt = $conn->prepare('SELECT id FROM customers WHERE email = ? LIMIT 1');
    if ($custStmt) {
        $custStmt->bind_param('s', $userEmail);
        $custStmt->execute();
        $custRow = $custStmt->get_result()->fetch_assoc();
        $custStmt->close();
        $customerId = $custRow ? (int)$custRow['id'] : null;
    }

    return [
        'summary' => [
            'my_bookings' => $customerId ? (int)$conn->query('SELECT COUNT(*) AS total FROM bookings WHERE customer_id = ' . (int)$customerId)->fetch_assoc()['total'] : 0,
            'upcoming' => $customerId ? (int)$conn->query('SELECT COUNT(*) AS total FROM bookings WHERE customer_id = ' . (int)$customerId . ' AND status IN ("pending", "assigned", "in_progress")')->fetch_assoc()['total'] : 0,
            'paid_jobs' => $customerId ? (int)$conn->query('SELECT COUNT(*) AS total FROM payments p INNER JOIN bookings b ON b.id = p.booking_id WHERE b.customer_id = ' . (int)$customerId . ' AND p.status = "paid"')->fetch_assoc()['total'] : 0,
        ],
    ];
}

switch ($action) {
    case 'health':
        apiJson(['success' => true, 'status' => 'ok', 'app' => 'CleanManage API']);
        break;

    case 'login':
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (!validateEmail($email) || $password === '') {
            apiError('Valid email and password are required.', 400);
        }

        $stmt = $conn->prepare('SELECT id, name, email, password, role FROM users WHERE email = ? LIMIT 1');
        if (!$stmt) {
            apiError('Login is unavailable right now.', 500);
        }

        $stmt->bind_param('s', $email);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$user || !password_verify($password, $user['password'])) {
            apiError('Invalid email or password.', 401);
        }

        try {
            $token = createApiToken($conn, (int)$user['id']);
            apiJson([
                'success' => true,
                'token' => $token,
                'user' => [
                    'id' => (int)$user['id'],
                    'name' => $user['name'],
                    'email' => $user['email'],
                    'role' => $user['role'],
                ],
            ]);
        } catch (Throwable $e) {
            apiError('Unable to create a secure mobile token.', 500, ['debug' => $e->getMessage()]);
        }
        break;

    case 'me':
        $user = requireApiAuth($conn);
        apiJson(['success' => true, 'user' => $user]);
        break;

    case 'dashboard':
        $user = requireApiAuth($conn);
        apiJson(['success' => true, 'data' => buildDashboardPayload($conn, $user)]);
        break;

    case 'bookings':
        $user = requireApiAuth($conn);
        $role = $user['role'] ?? 'customer';
        $userEmail = trim($user['email'] ?? '');

        if ($role === 'admin') {
            $result = $conn->query('SELECT b.*, c.name AS customer_name, COALESCE(s.service_name, b.service_type, "Cleaning Service") AS service_name FROM bookings b LEFT JOIN customers c ON c.id = b.customer_id LEFT JOIN services s ON s.id = b.service_id ORDER BY b.id DESC LIMIT 100');
        } elseif ($role === 'employee') {
            $employeeId = null;
            $empStmt = $conn->prepare('SELECT id FROM employees WHERE email = ? OR name = ? LIMIT 1');
            if ($empStmt) {
                $empStmt->bind_param('ss', $userEmail, $user['name']);
                $empStmt->execute();
                $empRow = $empStmt->get_result()->fetch_assoc();
                $empStmt->close();
                $employeeId = $empRow ? (int)$empRow['id'] : null;
            }

            if ($employeeId === null) {
                apiJson(['success' => true, 'data' => []]);
            }

            $result = $conn->query("SELECT b.*, c.name AS customer_name, COALESCE(s.service_name, b.service_type, 'Cleaning Service') AS service_name FROM bookings b LEFT JOIN customers c ON c.id = b.customer_id LEFT JOIN services s ON s.id = b.service_id WHERE FIND_IN_SET($employeeId, b.employee_id) > 0 ORDER BY b.id DESC LIMIT 100");
        } else {
            $customerId = null;
            $custStmt = $conn->prepare('SELECT id FROM customers WHERE email = ? LIMIT 1');
            if ($custStmt) {
                $custStmt->bind_param('s', $userEmail);
                $custStmt->execute();
                $custRow = $custStmt->get_result()->fetch_assoc();
                $custStmt->close();
                $customerId = $custRow ? (int)$custRow['id'] : null;
            }

            if ($customerId === null) {
                apiJson(['success' => true, 'data' => []]);
            }

            $result = $conn->query('SELECT b.*, c.name AS customer_name, COALESCE(s.service_name, b.service_type, "Cleaning Service") AS service_name FROM bookings b LEFT JOIN customers c ON c.id = b.customer_id LEFT JOIN services s ON s.id = b.service_id WHERE b.customer_id = ' . (int)$customerId . ' ORDER BY b.id DESC LIMIT 100');
        }

        $rows = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
        }

        apiJson(['success' => true, 'data' => $rows]);
        break;

    default:
        apiError('Route not found.', 404);
        break;
}
