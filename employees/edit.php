<?php
require '../includes/db.php';
require '../includes/security.php';
requireRole(['admin', 'owner', 'manager']);
$currentRole = normalizeUserRole(currentUserRole());

$id = (int)($_GET['id'] ?? 0);
$errors = [];

if ($id <= 0) {
    redirect('list.php');
}

$result = $conn->query("SELECT * FROM employees WHERE id = $id LIMIT 1");
$employee = $result->fetch_assoc();
if (!$employee) {
    redirect('list.php');
}

if ($currentRole === 'manager' && !in_array(strtolower(trim($employee['role'])), ['cleaner', 'employee', 'staff'], true)) {
    setFlash('danger', 'Managers can only update cleaner records.');
    redirect('list.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $employeeRole = $currentRole === 'manager' ? 'cleaner' : trim($_POST['role'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');

    if ($name === '') {
        $errors[] = 'Employee name is required.';
    }

    if ($employeeRole === '') {
        $errors[] = 'Employee role is required.';
    }

    if ($phone !== '' && !validatePhone($phone)) {
        $errors[] = 'Please enter a valid phone number.';
    }

    if ($email !== '' && !validateEmail($email)) {
        $errors[] = 'Please enter a valid email address.';
    }

    if (!$errors) {
        $stmt = $conn->prepare('UPDATE employees SET name=?, role=?, phone=?, email=? WHERE id=?');
        $stmt->bind_param('ssssi', $name, $employeeRole, $phone, $email, $id);
        $stmt->execute();
        $stmt->close();
        redirect('list.php');
    }
}

if ($currentRole === 'manager') {
    $employee['role'] = 'cleaner';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Employee - CleanManage</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="../index.php">CleanManage</a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="../index.php">Dashboard</a>
                <a class="nav-link" href="list.php">Employees</a>
            </div>
        </div>
    </nav>

    <div class="container py-5">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="mb-1">Edit Employee</h2>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item"><a href="../index.php">Dashboard</a></li>
                        <li class="breadcrumb-item"><a href="list.php">Employees</a></li>
                        <li class="breadcrumb-item active">Edit</li>
                    </ol>
                </nav>
            </div>
        </div>

        <div class="card shadow-sm border-0">
            <div class="card-body p-4">
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <ul class="mb-0">
                            <?php foreach ($errors as $error): ?>
                                <li><?= htmlspecialchars($error) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                <form method="POST">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Name</label>
                            <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($employee['name']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Role</label>
                            <?php if ($currentRole === 'manager'): ?>
                                <input type="text" class="form-control" value="Cleaner" readonly>
                            <?php else: ?>
                                <input type="text" name="role" class="form-control" value="<?= htmlspecialchars($employee['role']) ?>" required>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Phone</label>
                            <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($employee['phone'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($employee['email'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="mt-4 d-flex gap-2">
                        <button type="submit" class="btn btn-primary">Update Employee</button>
                        <a href="list.php" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</body>
</html>
