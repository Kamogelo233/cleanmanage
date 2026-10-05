<?php
require '../includes/db.php';
require '../includes/security.php';
requireRole(['admin', 'owner', 'manager']);

$role = normalizeUserRole(currentUserRole());
$selectedDate = $_GET['date'] ?? date('Y-m-d');
$parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $selectedDate);
if (!$parsedDate || $parsedDate->format('Y-m-d') !== $selectedDate) {
    $selectedDate = date('Y-m-d');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        setFlash('danger', 'Your session security check expired. Please try again.');
        redirect('operations.php');
    }

    $action = $_POST['action'] ?? '';
    if ($action === 'attendance') {
        $employeeId = (int)($_POST['employee_id'] ?? 0);
        $attendanceDate = trim($_POST['attendance_date'] ?? '');
        $status = $_POST['status'] ?? '';
        $notes = trim($_POST['notes'] ?? '');
        $validDate = DateTimeImmutable::createFromFormat('!Y-m-d', $attendanceDate);
        $validStatuses = ['present', 'absent', 'late', 'leave'];

        $employeeCheck = $conn->prepare("SELECT id FROM employees WHERE id = ? AND LOWER(TRIM(role)) IN ('cleaner', 'employee', 'staff') LIMIT 1");
        $employeeCheck->bind_param('i', $employeeId);
        $employeeCheck->execute();
        $isCleaner = (bool)$employeeCheck->get_result()->fetch_assoc();
        $employeeCheck->close();

        if (!$isCleaner || !$validDate || $validDate->format('Y-m-d') !== $attendanceDate || !in_array($status, $validStatuses, true)) {
            setFlash('danger', 'Select a cleaner, valid date, and attendance status.');
            redirect('operations.php?date=' . urlencode($selectedDate) . '#attendance');
        }

        $markedBy = (int)($_SESSION['user_id'] ?? 0);
        $attendanceStmt = $conn->prepare('INSERT INTO attendance_records (employee_id, attendance_date, status, notes, marked_by) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE status = VALUES(status), notes = VALUES(notes), marked_by = VALUES(marked_by)');
        $attendanceStmt->bind_param('isssi', $employeeId, $attendanceDate, $status, $notes, $markedBy);
        $attendanceStmt->execute();
        $attendanceStmt->close();
        setFlash('success', 'Cleaner attendance saved.');
        redirect('operations.php?date=' . urlencode($attendanceDate) . '#attendance');
    }

    if ($action === 'inventory') {
        $itemId = (int)($_POST['item_id'] ?? 0);
        $itemName = trim($_POST['item_name'] ?? '');
        if ($itemName === '__custom__') {
            $itemName = trim($_POST['custom_item_name'] ?? '');
        }
        $quantity = filter_var($_POST['quantity'] ?? null, FILTER_VALIDATE_FLOAT);
        $unit = trim($_POST['unit'] ?? 'units');
        $reorderLevel = filter_var($_POST['reorder_level'] ?? null, FILTER_VALIDATE_FLOAT);

        if ($itemName === '' || strlen($itemName) > 120 || $quantity === false || $quantity < 0 || $reorderLevel === false || $reorderLevel < 0 || $unit === '' || strlen($unit) > 30) {
            setFlash('danger', 'Enter an item name, non-negative stock and reorder amounts, and a unit.');
            redirect('operations.php#inventory');
        }

        $updatedBy = (int)($_SESSION['user_id'] ?? 0);
        if ($itemId > 0) {
            $inventoryStmt = $conn->prepare('UPDATE inventory_items SET item_name = ?, quantity = ?, unit = ?, reorder_level = ?, updated_by = ? WHERE id = ?');
            $inventoryStmt->bind_param('sdsdii', $itemName, $quantity, $unit, $reorderLevel, $updatedBy, $itemId);
        } else {
            $inventoryStmt = $conn->prepare('INSERT INTO inventory_items (item_name, quantity, unit, reorder_level, updated_by) VALUES (?, ?, ?, ?, ?)');
            $inventoryStmt->bind_param('sdsdi', $itemName, $quantity, $unit, $reorderLevel, $updatedBy);
        }

        if (!$inventoryStmt->execute()) {
            $inventoryStmt->close();
            setFlash('danger', 'Could not save this item. Check that the item name is not already in use.');
            redirect('operations.php#inventory');
        }
        $inventoryStmt->close();
        setFlash('success', 'Inventory item saved.');
        redirect('operations.php#inventory');
    }

    if ($action === 'service') {
        $serviceId = (int)($_POST['service_id'] ?? 0);
        $price = filter_var($_POST['price'] ?? null, FILTER_VALIDATE_FLOAT);
        $duration = filter_var($_POST['duration_hours'] ?? null, FILTER_VALIDATE_FLOAT);
        $staffCount = filter_var($_POST['required_staff_count'] ?? null, FILTER_VALIDATE_INT);

        if ($serviceId <= 0 || $price === false || $price < 0 || $duration === false || $duration < 0 || $staffCount === false || $staffCount < 1 || $staffCount > 20) {
            setFlash('danger', 'Enter a valid price, duration, and cleaner requirement.');
            redirect('operations.php#services');
        }

        $serviceStmt = $conn->prepare('UPDATE services SET price = ?, duration_hours = ?, required_staff_count = ? WHERE id = ?');
        $serviceStmt->bind_param('ddii', $price, $duration, $staffCount, $serviceId);
        $serviceStmt->execute();
        $updated = $serviceStmt->affected_rows >= 0;
        $serviceStmt->close();
        setFlash($updated ? 'success' : 'danger', $updated ? 'Service pricing and requirements saved.' : 'Could not update the service.');
        redirect('operations.php#services');
    }

    setFlash('danger', 'That manager operation is not available.');
    redirect('operations.php');
}

$employeesResult = $conn->query("SELECT e.id, e.name, a.status AS attendance_status, a.notes AS attendance_notes FROM employees e LEFT JOIN attendance_records a ON a.employee_id = e.id AND a.attendance_date = '" . $conn->real_escape_string($selectedDate) . "' WHERE LOWER(TRIM(e.role)) IN ('cleaner', 'employee', 'staff') ORDER BY e.name ASC");
$employees = $employeesResult ? $employeesResult->fetch_all(MYSQLI_ASSOC) : [];
$performanceResult = $conn->query("SELECT e.id, e.name, COUNT(DISTINCT b.id) AS assigned_jobs, COUNT(DISTINCT CASE WHEN b.status = 'completed' THEN b.id END) AS completed_jobs FROM employees e LEFT JOIN bookings b ON FIND_IN_SET(e.id, b.employee_id) > 0 WHERE LOWER(TRIM(e.role)) IN ('cleaner', 'employee', 'staff') GROUP BY e.id, e.name ORDER BY e.name ASC");
$performance = $performanceResult ? $performanceResult->fetch_all(MYSQLI_ASSOC) : [];
$performanceByEmployeeId = [];
foreach ($performance as $performanceRow) {
    $performanceByEmployeeId[(int)$performanceRow['id']] = $performanceRow;
}
$inventoryResult = $conn->query('SELECT * FROM inventory_items ORDER BY item_name ASC');
$inventoryItems = $inventoryResult ? $inventoryResult->fetch_all(MYSQLI_ASSOC) : [];
$inventoryOptions = [
    'All-purpose cleaner', 'Bleach', 'Broom', 'Bucket', 'Disinfectant',
    'Dish soap', 'Dustpan', 'Floor cleaner', 'Glass cleaner', 'Gloves',
    'Microfiber cloths', 'Mop', 'Paper towels', 'Protective masks',
    'Scrub brush', 'Spray bottles', 'Sponges', 'Trash bags',
    'Vacuum cleaner', 'Window squeegee',
];
$servicesResult = $conn->query('SELECT id, service_name, price, duration_hours, required_staff_count FROM services ORDER BY service_name ASC');
$services = $servicesResult ? $servicesResult->fetch_all(MYSQLI_ASSOC) : [];
$lowStockCount = count(array_filter($inventoryItems, static fn ($item) => (float)$item['quantity'] <= (float)$item['reorder_level']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manager Operations - CleanManage</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root { --green-900: #163725; --green-800: #1b4332; --green-700: #2d6a4f; --ink: #1c2b26; --muted: #60716d; --line: rgba(27,67,50,.12); }
        body { background: linear-gradient(180deg, #f3f8f5 0%, #edf4ef 100%); color: var(--ink); font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif; }
        .navbar { background: linear-gradient(135deg, var(--green-900), var(--green-800), var(--green-700)); }
        .page-shell { padding: 30px 0 52px; }
        .panel { background: #fff; border: 1px solid var(--line); border-radius: 18px; box-shadow: 0 12px 28px rgba(17,24,39,.05); padding: 22px; }
        .eyebrow { color: var(--green-700); font-size: .72rem; font-weight: 800; letter-spacing: .12em; text-transform: uppercase; }
        .form-control, .form-select { border-color: var(--line); border-radius: 9px; }
        .btn-primary { background: linear-gradient(135deg, var(--green-800), var(--green-700)); border: 0; }
        .section-anchor { scroll-margin-top: 88px; }
        .table thead th { color: var(--muted); font-size: .74rem; text-transform: uppercase; }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark"><div class="container"><a class="navbar-brand fw-bold" href="../index.php">CleanManage</a><div class="navbar-nav ms-auto flex-row gap-3"><a class="nav-link" href="../index.php">Dashboard</a><a class="nav-link" href="../employees/list.php">Employees</a><a class="nav-link" href="../bookings/list.php">Bookings</a></div></div></nav>
    <main class="container page-shell">
        <?php renderFlash(); ?>
        <div class="d-flex justify-content-between align-items-end flex-wrap gap-3 mb-4">
            <div><div class="eyebrow mb-1">Manager workspace</div><h1 class="h2 fw-bold mb-1">Operations Controls</h1><p class="text-muted mb-0">Attendance, supplies, service catalog and cleaner performance.</p></div>
            <a href="../index.php" class="btn btn-outline-success">Back to dashboard</a>
        </div>

        <section class="panel section-anchor mb-4" id="attendance">
            <div class="d-flex justify-content-between align-items-end flex-wrap gap-3 mb-3"><div><div class="eyebrow">People operations</div><h2 class="h5 fw-bold mb-0">Cleaner attendance and performance</h2></div><form method="get" class="d-flex gap-2 align-items-end"><div><label class="form-label small mb-1" for="attendance-date">Attendance date</label><input class="form-control form-control-sm" type="date" id="attendance-date" name="date" value="<?= htmlspecialchars($selectedDate) ?>"></div><button class="btn btn-outline-success btn-sm" type="submit">View date</button></form></div>
            <?php if (!$employees): ?>
                <div class="alert alert-light border mb-0">No cleaner records are available. Add cleaners from <a href="../employees/add.php">Employee management</a>.</div>
            <?php else: ?>
                <div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Cleaner</th><th>Assigned jobs</th><th>Completed</th><th>Attendance</th><th>Notes</th><th></th></tr></thead><tbody>
                    <?php foreach ($employees as $employee): ?>
                        <?php $metrics = $performanceByEmployeeId[(int)$employee['id']] ?? ['assigned_jobs' => 0, 'completed_jobs' => 0]; ?>
                        <tr><td class="fw-semibold"><?= htmlspecialchars($employee['name']) ?></td><td><?= (int)$metrics['assigned_jobs'] ?></td><td><?= (int)$metrics['completed_jobs'] ?></td><td colspan="3"><form method="post" class="row g-2 align-items-end"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="attendance"><input type="hidden" name="employee_id" value="<?= (int)$employee['id'] ?>"><input type="hidden" name="attendance_date" value="<?= htmlspecialchars($selectedDate) ?>"><div class="col-md-3"><label class="visually-hidden" for="status-<?= (int)$employee['id'] ?>">Attendance status</label><select class="form-select form-select-sm" id="status-<?= (int)$employee['id'] ?>" name="status" required><option value="">Mark attendance</option><?php foreach (['present' => 'Present', 'late' => 'Late', 'absent' => 'Absent', 'leave' => 'Leave'] as $value => $label): ?><option value="<?= $value ?>" <?= ($employee['attendance_status'] ?? '') === $value ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></div><div class="col-md-7"><label class="visually-hidden" for="note-<?= (int)$employee['id'] ?>">Attendance note</label><input class="form-control form-control-sm" id="note-<?= (int)$employee['id'] ?>" name="notes" maxlength="500" value="<?= htmlspecialchars($employee['attendance_notes'] ?? '') ?>" placeholder="Optional note"></div><div class="col-md-2 d-grid"><button class="btn btn-primary btn-sm" type="submit">Save</button></div></form></td></tr>
                    <?php endforeach; ?>
                </tbody></table></div>
            <?php endif; ?>
        </section>

        <section class="panel section-anchor mb-4" id="inventory">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3"><div><div class="eyebrow">Resources</div><h2 class="h5 fw-bold mb-0">Materials and equipment</h2></div><span class="badge rounded-pill <?= $lowStockCount ? 'text-bg-warning' : 'text-bg-success' ?>"><?= $lowStockCount ?> at/below reorder level</span></div>
            <form method="post" class="row g-2 align-items-end mb-4">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="inventory">
                <input type="hidden" name="item_id" value="0">
                <div class="col-md-4">
                    <label class="form-label small" for="new-item">Item name</label>
                    <select class="form-select inventory-name-select" id="new-item" name="item_name" data-custom-target="new-custom-item" required>
                        <option value="">Choose an item</option>
                        <?php foreach ($inventoryOptions as $option): ?><option value="<?= htmlspecialchars($option) ?>"><?= htmlspecialchars($option) ?></option><?php endforeach; ?>
                        <option value="__custom__">Other (enter item name)</option>
                    </select>
                    <input class="form-control mt-2 d-none" id="new-custom-item" name="custom_item_name" maxlength="120" placeholder="Enter item name" disabled>
                </div>
                <div class="col-md-2"><label class="form-label small" for="new-quantity">Current quantity</label><input class="form-control" type="number" id="new-quantity" name="quantity" min="0" step="0.01" required></div>
                <div class="col-md-2"><label class="form-label small" for="new-unit">Unit</label><input class="form-control" id="new-unit" name="unit" maxlength="30" value="units" required></div>
                <div class="col-md-2"><label class="form-label small" for="new-reorder">Reorder at</label><input class="form-control" type="number" id="new-reorder" name="reorder_level" min="0" step="0.01" value="0" required></div>
                <div class="col-md-2 d-grid"><button class="btn btn-primary" type="submit">Add item</button></div>
            </form>
            <?php if ($inventoryItems): ?>
                <div class="d-grid gap-2">
                    <?php foreach ($inventoryItems as $item): ?>
                        <?php $knownItem = in_array($item['item_name'], $inventoryOptions, true); ?>
                        <form method="post" class="row g-2 align-items-end border-top pt-3">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="action" value="inventory">
                            <input type="hidden" name="item_id" value="<?= (int)$item['id'] ?>">
                            <div class="col-md-4">
                                <label class="visually-hidden" for="item-<?= (int)$item['id'] ?>">Item name</label>
                                <select class="form-select form-select-sm inventory-name-select" id="item-<?= (int)$item['id'] ?>" name="item_name" data-custom-target="custom-item-<?= (int)$item['id'] ?>" required>
                                    <?php foreach ($inventoryOptions as $option): ?><option value="<?= htmlspecialchars($option) ?>" <?= $item['item_name'] === $option ? 'selected' : '' ?>><?= htmlspecialchars($option) ?></option><?php endforeach; ?>
                                    <?php if (!$knownItem): ?><option value="<?= htmlspecialchars($item['item_name']) ?>" selected><?= htmlspecialchars($item['item_name']) ?></option><?php endif; ?>
                                    <option value="__custom__">Other (enter item name)</option>
                                </select>
                                <input class="form-control form-control-sm mt-2 d-none" id="custom-item-<?= (int)$item['id'] ?>" name="custom_item_name" maxlength="120" placeholder="Enter item name" disabled>
                            </div>
                            <div class="col-md-2"><label class="visually-hidden" for="quantity-<?= (int)$item['id'] ?>">Quantity</label><input class="form-control form-control-sm" type="number" id="quantity-<?= (int)$item['id'] ?>" name="quantity" min="0" step="0.01" value="<?= htmlspecialchars((string)$item['quantity']) ?>" required></div>
                            <div class="col-md-2"><label class="visually-hidden" for="unit-<?= (int)$item['id'] ?>">Unit</label><input class="form-control form-control-sm" id="unit-<?= (int)$item['id'] ?>" name="unit" maxlength="30" value="<?= htmlspecialchars($item['unit']) ?>" required></div>
                            <div class="col-md-2"><label class="visually-hidden" for="reorder-<?= (int)$item['id'] ?>">Reorder level</label><input class="form-control form-control-sm" type="number" id="reorder-<?= (int)$item['id'] ?>" name="reorder_level" min="0" step="0.01" value="<?= htmlspecialchars((string)$item['reorder_level']) ?>" required></div>
                            <div class="col-md-2 d-grid"><button class="btn btn-outline-success btn-sm" type="submit">Save stock</button></div>
                        </form>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="text-muted mb-0">No supplies or equipment have been entered yet.</p>
            <?php endif; ?>
        </section>

        <section class="panel section-anchor" id="services">
            <div class="eyebrow">Service catalog</div><h2 class="h5 fw-bold mb-3">Services, prices and staffing requirements</h2>
            <div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Service</th><th>Price (R)</th><th>Duration (hours)</th><th>Cleaners</th><th></th></tr></thead><tbody>
                <?php foreach ($services as $service): ?><tr><td class="fw-semibold"><?= htmlspecialchars($service['service_name']) ?></td><td colspan="4"><form method="post" class="row g-2 align-items-end"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="service"><input type="hidden" name="service_id" value="<?= (int)$service['id'] ?>"><div class="col-md-3"><label class="visually-hidden" for="price-<?= (int)$service['id'] ?>">Price</label><input class="form-control form-control-sm" type="number" id="price-<?= (int)$service['id'] ?>" name="price" min="0" step="0.01" value="<?= htmlspecialchars((string)$service['price']) ?>" required></div><div class="col-md-3"><label class="visually-hidden" for="duration-<?= (int)$service['id'] ?>">Duration</label><input class="form-control form-control-sm" type="number" id="duration-<?= (int)$service['id'] ?>" name="duration_hours" min="0" step="0.25" value="<?= htmlspecialchars((string)$service['duration_hours']) ?>" required></div><div class="col-md-3"><label class="visually-hidden" for="staff-<?= (int)$service['id'] ?>">Required cleaners</label><input class="form-control form-control-sm" type="number" id="staff-<?= (int)$service['id'] ?>" name="required_staff_count" min="1" max="20" step="1" value="<?= (int)$service['required_staff_count'] ?>" required></div><div class="col-md-3 d-grid"><button class="btn btn-outline-success btn-sm" type="submit">Save service</button></div></form></td></tr><?php endforeach; ?>
            </tbody></table></div>
        </section>
    </main>
<script>
    document.querySelectorAll('.inventory-name-select').forEach((select) => {
        const customInput = document.getElementById(select.dataset.customTarget);
        const syncCustomInput = () => {
            const useCustomName = select.value === '__custom__';
            customInput.classList.toggle('d-none', !useCustomName);
            customInput.disabled = !useCustomName;
            customInput.required = useCustomName;
        };
        select.addEventListener('change', syncCustomInput);
    });
</script>
</body>
</html>