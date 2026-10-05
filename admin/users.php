<?php
require '../includes/db.php';
require '../includes/security.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_user'])) {
    requireCsrfToken('users.php');
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = trim($_POST['role'] ?? 'employee');

    $errors = [];
    if ($name === '') $errors[] = 'Name is required.';
    if (!validateEmail($email)) $errors[] = 'Valid email is required.';
    if (strlen($password) < 6) $errors[] = 'Password must be at least 6 characters.';
    if (!in_array($role, ['admin', 'owner', 'manager', 'finance', 'cleaner', 'customer'], true)) $errors[] = 'Invalid role selected.';

    if (!$errors) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare('INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)');
        $stmt->bind_param('ssss', $name, $email, $hash, $role);
        $stmt->execute();
        $stmt->close();
        $_SESSION['success'] = 'User created successfully.';
        redirect('users.php');
    }
}

$search = trim($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;
$searchPattern = '%' . $search . '%';

if ($search === '') {
    $countSql = 'SELECT COUNT(*) AS total FROM users';
    $countStmt = $conn->prepare($countSql);
    $countStmt->execute();
    $total = (int)$countStmt->get_result()->fetch_assoc()['total'];
    $countStmt->close();

    $offset = ($page - 1) * $perPage;
    $listSql = 'SELECT id, name, email, role FROM users ORDER BY id DESC LIMIT ? OFFSET ?';
    $listStmt = $conn->prepare($listSql);
    $listStmt->bind_param('ii', $perPage, $offset);
    $listStmt->execute();
    $users = $listStmt->get_result();
    $listStmt->close();
} else {
    $countSql = 'SELECT COUNT(*) AS total FROM users WHERE name LIKE ? OR email LIKE ? OR role LIKE ?';
    $countStmt = $conn->prepare($countSql);
    $countStmt->bind_param('sss', $searchPattern, $searchPattern, $searchPattern);
    $countStmt->execute();
    $total = (int)$countStmt->get_result()->fetch_assoc()['total'];
    $countStmt->close();

    $offset = ($page - 1) * $perPage;
    $listSql = 'SELECT id, name, email, role FROM users WHERE name LIKE ? OR email LIKE ? OR role LIKE ? ORDER BY id DESC LIMIT ? OFFSET ?';
    $listStmt = $conn->prepare($listSql);
    $listStmt->bind_param('sssii', $searchPattern, $searchPattern, $searchPattern, $perPage, $offset);
    $listStmt->execute();
    $users = $listStmt->get_result();
    $listStmt->close();
}

$totalPages = max(1, (int)ceil($total / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - CleanManage</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --green-900: #163725;
            --green-800: #1b4332;
            --green-700: #2d6a4f;
            --green-100: #edf5f0;
            --green-50: #f4faf6;
            --text: #1c2b26;
            --muted: #5b7068;
            --line: rgba(27, 67, 50, 0.12);
        }

        body {
            background: linear-gradient(180deg, #f3f7f4 0%, #edf4ef 100%);
            color: var(--text);
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
        }

        .navbar {
            background: linear-gradient(135deg, var(--green-900) 0%, var(--green-800) 26%, var(--green-700) 100%) !important;
            border-bottom: 1px solid rgba(255,255,255,0.08);
            box-shadow: 0 10px 24px rgba(22, 55, 37, 0.12);
        }

        .navbar-brand, .nav-link, .nav-link.active { color: #fff !important; }
        .nav-link { font-weight: 600; border-radius: 10px; padding: 0.6rem 0.8rem !important; }
        .nav-link:hover, .nav-link.active { background: rgba(255,255,255,0.08); }

        .btn-primary {
            background: linear-gradient(135deg, var(--green-800) 0%, var(--green-700) 100%) !important;
            border: none !important;
            border-radius: 12px !important;
            font-weight: 700;
            box-shadow: 0 10px 20px rgba(27, 67, 50, 0.18);
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, var(--green-900) 0%, var(--green-800) 100%) !important;
        }

        .card {
            border: 1px solid var(--line) !important;
            box-shadow: 0 14px 30px rgba(20, 55, 38, 0.06) !important;
            border-radius: 18px !important;
        }

        .form-control, .form-select {
            border-radius: 12px;
            border: 1px solid rgba(27, 67, 50, 0.14);
            background: rgba(244, 250, 246, 0.9);
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--green-700);
            box-shadow: 0 0 0 0.2rem rgba(45, 106, 79, 0.12);
        }

        .table thead { background: linear-gradient(135deg, var(--green-900) 0%, var(--green-800) 100%) !important; color: #fff; }
        .table thead th { border-color: rgba(255,255,255,0.08); }
    </style>
</head>
<body class="bg-light">
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container">
            <a class="navbar-brand" href="../index.php">CleanManage</a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="../index.php">Dashboard</a>
                <a class="nav-link" href="users.php">Users</a>
                <a class="nav-link" href="../logout.php">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container py-5">
        <h2 class="mb-4">User Management</h2>
        <?php if (!empty($errors ?? [])): ?>
            <div class="alert alert-danger">
                <ul class="mb-0">
                    <?php foreach ($errors as $error): ?>
                        <li><?= htmlspecialchars($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-lg-5">
                <div class="card shadow-sm border-0">
                    <div class="card-body p-4">
                        <h5>Create User</h5>
                        <form method="POST">
                            <input type="hidden" name="create_user" value="1">
                            <?= csrfField() ?>
                            <div class="mb-3">
                                <label class="form-label">Name</label>
                                <input type="text" name="name" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" name="email" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Password</label>
                                <input type="password" name="password" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Role</label>
                                <select name="role" class="form-select">
                                    <option value="admin">Admin</option>
                                    <option value="owner">Business Owner</option>
                                    <option value="manager">Manager</option>
                                    <option value="finance">Finance</option>
                                    <option value="cleaner">Cleaner</option>
                                    <option value="customer">Customer</option>
                                </select>
                            </div>
                            <button type="submit" class="btn btn-primary">Create User</button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-lg-7">
                <div class="card shadow-sm border-0 mb-4">
                    <div class="card-body">
                        <form method="GET" class="row g-2 align-items-end">
                            <div class="col-md-8">
                                <label class="form-label">Search</label>
                                <input type="text" class="form-control" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search name, email, or role">
                            </div>
                            <div class="col-md-4 d-grid">
                                <button type="submit" class="btn btn-primary">Filter</button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card shadow-sm border-0">
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-dark">
                                    <tr>
                                        <th>ID</th>
                                        <th>Name</th>
                                        <th>Email</th>
                                        <th>Role</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($users && $users->num_rows > 0): ?>
                                        <?php while ($user = $users->fetch_assoc()): ?>
                                            <tr>
                                                <td><?= $user['id'] ?></td>
                                                <td><?= htmlspecialchars($user['name']) ?></td>
                                                <td><?= htmlspecialchars($user['email']) ?></td>
                                                <td><span class="badge bg-<?= $user['role'] === 'admin' ? 'primary' : 'secondary' ?>"><?= htmlspecialchars($user['role']) ?></span></td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr><td colspan="4" class="text-center py-4 text-muted">No users found.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <?php if ($totalPages > 1): ?>
                    <nav aria-label="Users pagination" class="mt-4">
                        <ul class="pagination justify-content-center">
                            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                <a class="page-link" href="?page=<?= max(1, $page - 1) ?>&search=<?= urlencode($search) ?>">Previous</a>
                            </li>
                            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                    <a class="page-link" href="?page=<?= $i ?>&search=<?= urlencode($search) ?>"><?= $i ?></a>
                                </li>
                            <?php endfor; ?>
                            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                                <a class="page-link" href="?page=<?= min($totalPages, $page + 1) ?>&search=<?= urlencode($search) ?>">Next</a>
                            </li>
                        </ul>
                    </nav>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
