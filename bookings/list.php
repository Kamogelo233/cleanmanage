<?php
require '../includes/db.php';
require '../includes/security.php';
requireRole(['admin', 'owner', 'manager', 'finance', 'cleaner', 'customer']);

$role = currentUserRole();
if (normalizeUserRole($role) === 'customer') {
    redirect('../customers/account.php#bookings');
}
$currentEmployeeId = $role === 'employee' ? currentUserEmployeeId($conn) : null;
$canManageBookings = in_array($role, ['admin', 'owner', 'manager'], true);
$canConfirmPayments = in_array(normalizeUserRole($role), ['admin', 'owner', 'finance'], true);

if ($canConfirmPayments && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['payment_confirm'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        setFlash('danger', 'Your session security check expired. Please try again.');
    } else {
        $paymentId = (int)($_POST['payment_id'] ?? 0);
        $confirmStmt = $conn->prepare('UPDATE payments SET status = "paid" WHERE id = ? AND status = "pending"');
        if ($confirmStmt && $paymentId > 0) {
            $confirmStmt->bind_param('i', $paymentId);
            $confirmStmt->execute();
            $confirmed = $confirmStmt->affected_rows > 0;
            $confirmStmt->close();
            setFlash($confirmed ? 'success' : 'info', $confirmed ? 'Payment confirmed and recorded as paid.' : 'This payment was already confirmed or is no longer pending.');
        } else {
            if ($confirmStmt) {
                $confirmStmt->close();
            }
            setFlash('danger', 'The payment could not be confirmed. Please try again.');
        }
    }
    redirect('list.php');
}

if ($canManageBookings && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['status_update'])) {
    $bookingId = (int)$_POST['booking_id'];
    $status = trim($_POST['status'] ?? 'pending');
    $employeeId = (int)($_POST['employee_id'] ?? 0);

    $employeeIdValue = $employeeId > 0 ? (string)$employeeId : null;
    $stmt = $conn->prepare('UPDATE bookings SET status=?, employee_id=? WHERE id=?');
    $stmt->bind_param('ssi', $status, $employeeIdValue, $bookingId);
    $stmt->execute();
    $stmt->close();

    $customerStmt = $conn->prepare('SELECT c.name, c.email, c.phone, COALESCE(s.service_name, b.service_type, "Cleaning Service") AS service_name, b.scheduled_date FROM bookings b LEFT JOIN customers c ON c.id = b.customer_id LEFT JOIN services s ON s.id = b.service_id WHERE b.id = ? LIMIT 1');
    $customerStmt->bind_param('i', $bookingId);
    $customerStmt->execute();
    $customerBooking = $customerStmt->get_result()->fetch_assoc();
    $customerStmt->close();

    if ($customerBooking) {
        $customerName = $customerBooking['name'] ?? 'Customer';
        $customerEmail = $customerBooking['email'] ?? '';
        $customerPhone = $customerBooking['phone'] ?? '';
        $serviceType = $customerBooking['service_name'] ?? 'Cleaning Service';
        $scheduledDate = $customerBooking['scheduled_date'] ?? '';

        if ($customerEmail !== '') {
            sendBookingConfirmationEmail($customerEmail, $customerName, $serviceType, $scheduledDate, $status);
        }

        if ($customerPhone !== '') {
            $whatsappSent = function_exists('sendWhatsAppBookingNotification') && sendWhatsAppBookingNotification($customerPhone, $customerName, $serviceType, $scheduledDate, $status);
            $_SESSION['last_whatsapp_link'] = $whatsappSent ? '' : generateWhatsAppLink($customerPhone, $customerName, $serviceType, $scheduledDate, $status);
        }
    }

    setFlash('success', 'Booking status updated and customer notified.');
    redirect('list.php');
}

if ($canManageBookings && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['proof_upload'])) {
    $bookingId = (int)($_POST['booking_id'] ?? 0);
    if ($bookingId > 0 && isset($_FILES['proof_photo']) && $_FILES['proof_photo']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = realpath(__DIR__ . '/../uploads');
        if ($uploadDir === false) {
            mkdir(__DIR__ . '/../uploads', 0777, true);
            $uploadDir = realpath(__DIR__ . '/../uploads');
        }

        $fileExt = strtolower(pathinfo($_FILES['proof_photo']['name'], PATHINFO_EXTENSION));
        $safeName = 'proof_' . $bookingId . '_' . time() . '.' . $fileExt;
        $targetFile = $uploadDir . DIRECTORY_SEPARATOR . $safeName;
        if (move_uploaded_file($_FILES['proof_photo']['tmp_name'], $targetFile)) {
            $relativePath = 'uploads/' . $safeName;
            $stmt = $conn->prepare('UPDATE bookings SET proof_photo = ?, status = IF(status = "pending", "assigned", status) WHERE id = ?');
            $stmt->bind_param('si', $relativePath, $bookingId);
            $stmt->execute();
            $stmt->close();
            setFlash('success', 'Cleaning proof uploaded successfully.');
        }
    }
    redirect('list.php');
}

$search = trim($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;
$searchPattern = '%' . $search . '%';

$baseSql = 'SELECT b.*, c.name AS customer_name, c.phone AS customer_phone, e.name AS employee_name, COALESCE(s.service_name, b.service_type, "Cleaning Service") AS service_name, (SELECT p.id FROM payments p WHERE p.booking_id = b.id AND p.status = "pending" ORDER BY p.id DESC LIMIT 1) AS pending_payment_id, (SELECT p.amount FROM payments p WHERE p.booking_id = b.id AND p.status = "pending" ORDER BY p.id DESC LIMIT 1) AS pending_payment_amount, (SELECT p.reference_no FROM payments p WHERE p.booking_id = b.id AND p.status = "pending" ORDER BY p.id DESC LIMIT 1) AS pending_payment_reference FROM bookings b LEFT JOIN customers c ON c.id = b.customer_id LEFT JOIN employees e ON e.id = b.employee_id LEFT JOIN services s ON s.id = b.service_id';
$countSql = 'SELECT COUNT(*) AS total FROM bookings b LEFT JOIN customers c ON c.id = b.customer_id LEFT JOIN employees e ON e.id = b.employee_id LEFT JOIN services s ON s.id = b.service_id';

$whereClauses = [];
$params = [];
$types = '';

if ($role === 'employee') {
    $whereClauses[] = 'b.employee_id LIKE ?';
    $params[] = '%' . (string)$currentEmployeeId . '%';
    $types .= 's';
}

if ($search !== '') {
    $whereClauses[] = '(c.name LIKE ? OR c.phone LIKE ? OR e.name LIKE ? OR s.service_name LIKE ? OR b.service_type LIKE ? OR b.status LIKE ?)';
    $params[] = $searchPattern;
    $params[] = $searchPattern;
    $params[] = $searchPattern;
    $params[] = $searchPattern;
    $params[] = $searchPattern;
    $params[] = $searchPattern;
    $types .= 'ssssss';
}

if (!empty($whereClauses)) {
    $countSql .= ' WHERE ' . implode(' AND ', $whereClauses);
    $baseSql .= ' WHERE ' . implode(' AND ', $whereClauses);
}

$countStmt = $conn->prepare($countSql);
if ($countStmt && !empty($params)) {
    $countStmt->bind_param($types, ...$params);
}
$countStmt->execute();
$total = (int)$countStmt->get_result()->fetch_assoc()['total'];
$countStmt->close();

$offset = ($page - 1) * $perPage;
$listSql = $baseSql . ' ORDER BY b.id DESC LIMIT ? OFFSET ?';
$listStmt = $conn->prepare($listSql);
if ($listStmt && !empty($params)) {
    $params[] = $perPage;
    $params[] = $offset;
    $types .= 'ii';
    $listStmt->bind_param($types, ...$params);
} else {
    $listStmt->bind_param('ii', $perPage, $offset);
}
$listStmt->execute();
$bookings = $listStmt->get_result();
$listStmt->close();

$totalPages = max(1, (int)ceil($total / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}

$employeesResult = $conn->query('SELECT * FROM employees ORDER BY name ASC');
$employees = $employeesResult ? $employeesResult->fetch_all(MYSQLI_ASSOC) : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bookings - CleanManage</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --primary: #0F4C3A;
            --primary-2: #1A6B4F;
            --bg: #F8FAF8;
            --card: #ffffff;
            --text: #102a43;
            --muted: #5f7388;
            --line: rgba(15,76,58,.08);
            --shadow: 0 18px 40px rgba(15, 23, 42, 0.08);
        }

        body {
            background: linear-gradient(180deg, #F8FAF8 0%, #EEF5F0 100%);
            color: var(--text);
        }

        .navbar {
            background: linear-gradient(135deg, var(--primary) 0%, #153a5f 100%);
            box-shadow: 0 10px 30px rgba(20, 83, 45, 0.15);
        }

        .nav-link {
            color: rgba(255,255,255,0.88) !important;
            font-weight: 500;
        }

        .nav-link:hover,
        .nav-link.active {
            color: #fff !important;
        }

        .page-shell {
            padding: 32px 0 50px;
        }

        .panel {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 20px;
            box-shadow: var(--shadow);
        }

        .header-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 14px;
            margin-bottom: 18px;
        }

        .eyebrow {
            color: var(--primary-2);
            font-size: 11px;
            letter-spacing: 0.18em;
            font-weight: 700;
            text-transform: uppercase;
            margin-bottom: 8px;
        }

        h2 {
            font-weight: 800;
            letter-spacing: -0.04em;
            margin-bottom: 0;
        }

        .breadcrumb a {
            color: var(--primary);
            text-decoration: none;
        }

        .search-panel {
            padding: 18px 20px;
        }

        .table-panel {
            overflow: hidden;
        }

        .table thead th {
            background: #0f172a;
            color: #fff;
            border: 0;
            font-size: 12px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            padding: 14px 16px;
        }

        .table tbody td {
            padding: 16px;
            vertical-align: middle;
            border-color: rgba(15, 23, 42, 0.06);
        }

        .status-pill {
            display: inline-flex;
            padding: 7px 12px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
            text-transform: capitalize;
        }

        .status-pending { background: rgba(108,117,125,.12); color: #495057; }
        .status-assigned { background: rgba(15,76,58,.12); color: #0F4C3A; }
        .status-in_progress { background: rgba(245,158,11,.15); color: #b45309; }
        .status-completed { background: rgba(25,135,84,.12); color: #198754; }
        .status-paid { background: rgba(17,24,39,.12); color: #111827; }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-2) 100%);
            border: 0;
            box-shadow: 0 8px 18px rgba(20, 83, 45, 0.18);
        }

        .btn-primary:hover,
        .btn-primary:focus,
        .btn-primary:active {
            background: linear-gradient(135deg, var(--primary-2) 0%, #C5A880 100%) !important;
            border-color: transparent !important;
        }

        @media (max-width: 767.98px) {
            .header-row {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container">
            <a class="navbar-brand" href="../index.php">CleanManage</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#bookingNav" aria-controls="bookingNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="bookingNav">
                <div class="navbar-nav ms-auto">
                    <a class="nav-link" href="../index.php">Dashboard</a>
                    <a class="nav-link" href="../customers/list.php">Customers</a>
                    <a class="nav-link" href="../employees/list.php">Employees</a>
                    <a class="nav-link active" href="list.php">Bookings</a>
                    <a class="nav-link" href="../payments/list.php">Payments</a>
                    <a class="btn btn-light btn-sm ms-lg-3" href="../logout.php">Logout</a>
                </div>
            </div>
        </div>
    </nav>

    <div class="container page-shell">
        <div class="header-row">
            <div>
                <div class="eyebrow">Operations</div>
                <h2>Booking Management</h2>
                <nav aria-label="breadcrumb" class="mt-2">
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item"><a href="../index.php">Dashboard</a></li>
                        <li class="breadcrumb-item active">Bookings</li>
                    </ol>
                </nav>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <a href="calendar.php" class="btn btn-outline-primary">Calendar</a>
                <?php if ($canManageBookings): ?>
                    <a href="add.php" class="btn btn-primary">+ New Booking</a>
                <?php endif; ?>
            </div>
        </div>

        <?php renderFlash(); ?>

        <div class="panel search-panel mb-4">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-8">
                    <label class="form-label fw-semibold">Search</label>
                    <input type="text" class="form-control" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search customer, employee, service, or status">
                </div>
                <div class="col-md-4 d-grid">
                    <button type="submit" class="btn btn-primary">Filter</button>
                </div>
            </form>
        </div>

        <div class="panel table-panel">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead>
                    <tr>
                        <th>#</th>
                        <th>Customer</th>
                        <th>Service</th>
                        <th>Employee</th>
                        <th>Date</th>
                        <th>Status</th>
                        <th class="text-end">Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($bookings && $bookings->num_rows > 0): ?>
                        <?php while ($booking = $bookings->fetch_assoc()): ?>
                            <?php
                            $status = $booking['status'] ?? 'pending';
                            $statusClass = 'status-' . str_replace(' ', '_', strtolower($status));
                            ?>
                            <tr>
                                <td><?= $booking['id'] ?></td>
                                <td>
                                    <div class="fw-semibold"><?= htmlspecialchars($booking['customer_name'] ?? 'Unknown') ?></div>
                                    <small class="text-muted"><?= htmlspecialchars($booking['customer_phone'] ?? '') ?></small>
                                </td>
                                <td>
                                    <div class="fw-semibold"><?= htmlspecialchars($booking['service_name'] ?? ($booking['service_type'] ?? 'Cleaning Service')) ?></div>
                                    <?php if (!empty($booking['proof_photo'])): ?>
                                        <a href="../<?= htmlspecialchars($booking['proof_photo']) ?>" target="_blank" class="small">View proof</a>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($booking['employee_name'] ?? 'Unassigned') ?></td>
                                <td><?= htmlspecialchars($booking['scheduled_date'] ?? '-') ?></td>
                                <td><span class="status-pill <?= $statusClass ?>"><?= htmlspecialchars($status) ?></span></td>
                                <td class="text-end">
                                    <?php if ($canManageBookings): ?>
                                        <div class="d-flex flex-column gap-2">
                                            <form method="POST" class="d-flex gap-2 flex-wrap justify-content-end">
                                                <input type="hidden" name="status_update" value="1">
                                                <input type="hidden" name="booking_id" value="<?= $booking['id'] ?>">
                                                <select name="status" class="form-select form-select-sm" style="width: 140px;">
                                                    <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>Pending</option>
                                                    <option value="assigned" <?= $status === 'assigned' ? 'selected' : '' ?>>Assigned</option>
                                                    <option value="in_progress" <?= $status === 'in_progress' ? 'selected' : '' ?>>In Progress</option>
                                                    <option value="completed" <?= $status === 'completed' ? 'selected' : '' ?>>Completed</option>
                                                    <option value="paid" <?= $status === 'paid' ? 'selected' : '' ?>>Paid</option>
                                                </select>
                                                <select name="employee_id" class="form-select form-select-sm" style="width: 140px;">
                                                    <option value="0">Unassigned</option>
                                                    <?php foreach ($employees as $employee): ?>
                                                        <option value="<?= $employee['id'] ?>" <?= (int)$booking['employee_id'] === (int)$employee['id'] ? 'selected' : '' ?>><?= htmlspecialchars($employee['name']) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <button class="btn btn-sm btn-primary" type="submit">Save</button>
                                            </form>
                                            <div class="d-flex gap-2 justify-content-end flex-wrap">
                                                <?php if (!empty($booking['customer_phone'])): ?>
                                                    <?php $whatsAppLink = generateWhatsAppLink($booking['customer_phone'], $booking['customer_name'] ?? 'Customer', $booking['service_name'] ?? ($booking['service_type'] ?? 'Cleaning Service'), $booking['scheduled_date'] ?? '', $status); ?>
                                                    <?php if ($whatsAppLink !== ''): ?>
                                                        <a href="<?= htmlspecialchars($whatsAppLink) ?>" target="_blank" class="btn btn-sm btn-success">WhatsApp</a>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                                <a href="invoice.php?id=<?= $booking['id'] ?>" target="_blank" class="btn btn-sm btn-outline-dark">Invoice</a>
                                            </div>
                                            <form method="POST" enctype="multipart/form-data" class="d-flex gap-2 flex-wrap justify-content-end">
                                                <input type="hidden" name="proof_upload" value="1">
                                                <input type="hidden" name="booking_id" value="<?= $booking['id'] ?>">
                                                <input type="file" name="proof_photo" class="form-control form-control-sm" style="max-width: 220px;">
                                                <button class="btn btn-sm btn-outline-secondary" type="submit">Upload Proof</button>
                                            </form>
                                        </div>
                                    <?php elseif ($role === 'employee'): ?>
                                        <span class="text-muted small">Assigned job</span>
                                    <?php elseif ($canConfirmPayments && !empty($booking['pending_payment_id'])): ?>
                                        <div class="d-flex flex-column align-items-end gap-2">
                                            <span class="small text-muted">Payment submitted: R <?= number_format((float)$booking['pending_payment_amount'], 2) ?></span>
                                            <?php if (!empty($booking['pending_payment_reference'])): ?>
                                                <span class="small text-muted">Ref: <?= htmlspecialchars($booking['pending_payment_reference']) ?></span>
                                            <?php endif; ?>
                                            <form method="POST" class="m-0">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="payment_confirm" value="1">
                                                <input type="hidden" name="payment_id" value="<?= (int)$booking['pending_payment_id'] ?>">
                                                <button class="btn btn-sm btn-success" type="submit">Confirm payment</button>
                                            </form>
                                        </div>
                                    <?php elseif ($canConfirmPayments): ?>
                                        <span class="text-muted small">No pending payment</span>
                                    <?php else: ?>
                                        <span class="text-muted small">View only</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="text-center py-4 text-muted">No bookings found.</td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if ($totalPages > 1): ?>
            <nav aria-label="Booking pagination" class="mt-4">
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
