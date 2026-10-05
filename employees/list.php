<?php
require '../includes/db.php';
require '../includes/security.php';
requireAdmin();

$role = currentUserRole();
$search = trim($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;
$searchPattern = '%' . $search . '%';

if ($role === 'employee') {
    $employeeId = currentUserEmployeeId($conn);
    if ($employeeId === null) {
        $employeeId = 0;
    }
    $countSql = 'SELECT COUNT(*) AS total FROM employees WHERE id = ?';
    $countStmt = $conn->prepare($countSql);
    $countStmt->bind_param('i', $employeeId);
    $countStmt->execute();
    $total = (int)$countStmt->get_result()->fetch_assoc()['total'];
    $countStmt->close();

    $offset = ($page - 1) * $perPage;
    $listSql = 'SELECT * FROM employees WHERE id = ? ORDER BY id DESC LIMIT ? OFFSET ?';
    $listStmt = $conn->prepare($listSql);
    $listStmt->bind_param('iii', $employeeId, $perPage, $offset);
    $listStmt->execute();
    $employees = $listStmt->get_result();
    $listStmt->close();
} else {
    if ($search === '') {
        $countSql = 'SELECT COUNT(*) AS total FROM employees';
    } else {
        $countSql = 'SELECT COUNT(*) AS total FROM employees WHERE name LIKE ? OR role LIKE ? OR email LIKE ? OR phone LIKE ?';
    }
    $countStmt = $conn->prepare($countSql);
    if ($search === '') {
        $countStmt->execute();
    } else {
        $countStmt->bind_param('ssss', $searchPattern, $searchPattern, $searchPattern, $searchPattern);
        $countStmt->execute();
    }
    $total = (int)$countStmt->get_result()->fetch_assoc()['total'];
    $countStmt->close();

    $offset = ($page - 1) * $perPage;
    if ($search === '') {
        $listSql = 'SELECT * FROM employees ORDER BY id DESC LIMIT ? OFFSET ?';
        $listStmt = $conn->prepare($listSql);
        $listStmt->bind_param('ii', $perPage, $offset);
    } else {
        $listSql = 'SELECT * FROM employees WHERE name LIKE ? OR role LIKE ? OR email LIKE ? OR phone LIKE ? ORDER BY id DESC LIMIT ? OFFSET ?';
        $listStmt = $conn->prepare($listSql);
        $listStmt->bind_param('sssssii', $searchPattern, $searchPattern, $searchPattern, $searchPattern, $perPage, $offset);
    }
    $listStmt->execute();
    $employees = $listStmt->get_result();
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
    <title>Employees - CleanManage</title>
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

        .navbar-brand,
        .nav-link,
        .nav-link.active {
            color: #fff !important;
        }

        .nav-link {
            font-weight: 600;
            border-radius: 10px;
            padding: 0.6rem 0.8rem !important;
        }

        .nav-link:hover,
        .nav-link.active {
            background: rgba(255,255,255,0.08);
        }

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

        .btn-outline-primary {
            color: var(--green-800) !important;
            border-color: rgba(27, 67, 50, 0.2) !important;
            background: #fff !important;
            border-radius: 10px;
            font-weight: 600;
        }

        .btn-outline-primary:hover {
            background: linear-gradient(135deg, var(--green-800) 0%, var(--green-700) 100%) !important;
            border-color: transparent !important;
            color: #fff !important;
        }

        .card {
            border: 1px solid var(--line) !important;
            box-shadow: 0 14px 30px rgba(20, 55, 38, 0.06) !important;
            border-radius: 18px !important;
        }

        .table thead {
            background: linear-gradient(135deg, var(--green-900) 0%, var(--green-800) 100%) !important;
            color: #fff;
        }

        .table thead th {
            border-color: rgba(255,255,255,0.08);
        }

        .breadcrumb a,
        a {
            color: var(--green-800);
        }
    </style>
</head>
<body class="bg-light">
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="../index.php">CleanManage</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#employeeNav" aria-controls="employeeNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="employeeNav">
                <div class="navbar-nav ms-auto">
                    <a class="nav-link" href="../index.php">Dashboard</a>
                    <a class="nav-link active" href="list.php">Employees</a>
                    <a class="nav-link" href="../bookings/list.php">Bookings</a>
                    <a class="nav-link" href="../logout.php">Logout</a>
                </div>
            </div>
        </div>
    </nav>

    <div class="container py-5">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="mb-1">Employees</h2>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item"><a href="../index.php">Dashboard</a></li>
                        <li class="breadcrumb-item active">Employees</li>
                    </ol>
                </nav>
            </div>
            <?php if ($role === 'admin'): ?>
                <a href="add.php" class="btn btn-primary">+ Add Employee</a>
            <?php endif; ?>
        </div>

        <div class="card shadow-sm border-0 mb-4">
            <div class="card-body">
                <form method="GET" class="row g-2 align-items-end">
                    <div class="col-md-8">
                        <label class="form-label">Search</label>
                        <input type="text" class="form-control" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search name, email, role, or phone">
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
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-dark">
                            <tr>
                                <th>#</th>
                                <th>Name</th>
                                <th>Role</th>
                                <th>Phone</th>
                                <th>Email</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($employees && $employees->num_rows > 0): ?>
                                <?php while ($row = $employees->fetch_assoc()): ?>
                                    <tr>
                                        <td><?= $row['id'] ?></td>
                                        <td><?= htmlspecialchars($row['name']) ?></td>
                                        <td><?= htmlspecialchars($row['role']) ?></td>
                                        <td><?= htmlspecialchars($row['phone'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($row['email'] ?? '-') ?></td>
                                        <td class="text-end">
                                            <?php if ($role === 'admin'): ?>
                                                <a class="btn btn-sm btn-outline-primary" href="edit.php?id=<?= $row['id'] ?>">Edit</a>
                                            <?php else: ?>
                                                <span class="text-muted small">Read only</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="6" class="text-center py-4 text-muted">No employees found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <?php if ($totalPages > 1): ?>
            <nav aria-label="Employee pagination" class="mt-4">
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
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
