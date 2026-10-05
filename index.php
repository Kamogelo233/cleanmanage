<?php
require 'includes/db.php';
require 'includes/security.php';
if (!isAuthenticated()) {
    require __DIR__ . '/landing.php';
    exit;
}

$role = normalizeUserRole(currentUserRole());
$currentUserEmail = trim($_SESSION['user_email'] ?? '');
$currentEmployeeId = currentUserEmployeeId($conn);
$currentCustomerId = null;

$isAdmin = $role === 'admin';
$isOwner = $role === 'owner';
$isManager = $role === 'manager';
$isFinance = $role === 'finance';
$isCleaner = $role === 'cleaner';
$isCustomer = $role === 'customer';

if ($role === 'customer' && $currentUserEmail !== '') {
    $customerStmt = $conn->prepare('SELECT id FROM customers WHERE email = ? LIMIT 1');
    $customerStmt->bind_param('s', $currentUserEmail);
    $customerStmt->execute();
    $customerRow = $customerStmt->get_result()->fetch_assoc();
    $customerStmt->close();
    $currentCustomerId = $customerRow ? (int)$customerRow['id'] : null;
}

$customersCount = (int)$conn->query('SELECT COUNT(*) AS total FROM customers')->fetch_assoc()['total'];
$employeesCount = (int)$conn->query('SELECT COUNT(*) AS total FROM employees')->fetch_assoc()['total'];
$bookingsCount = (int)$conn->query('SELECT COUNT(*) AS total FROM bookings')->fetch_assoc()['total'];
$paymentsCount = (int)$conn->query('SELECT COUNT(*) AS total FROM payments')->fetch_assoc()['total'];
$pendingPaymentsCount = (int)$conn->query('SELECT COUNT(*) AS total FROM payments WHERE status = "pending"')->fetch_assoc()['total'];
$revenue = (float)$conn->query('SELECT COALESCE(SUM(amount), 0) AS total FROM payments WHERE status = "paid"')->fetch_assoc()['total'];
$pendingPayments = (float)$conn->query('SELECT COALESCE(SUM(amount), 0) AS total FROM payments WHERE status = "pending"')->fetch_assoc()['total'];
$completedBookings = (int)$conn->query('SELECT COUNT(*) AS total FROM bookings WHERE status = "completed"')->fetch_assoc()['total'];

$myBookingsCount = 0;
$myOpenTasks = 0;
$myCompletedTasks = 0;
$myUpcomingCleanings = 0;
$myPaidInvoices = 0;
$myAssignedJobs = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['proof_upload']) && $currentEmployeeId && $role === 'cleaner') {
    $bookingId = (int)($_POST['booking_id'] ?? 0);
    if ($bookingId > 0 && isset($_FILES['proof_photo']) && $_FILES['proof_photo']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = realpath(__DIR__ . '/uploads');
        if ($uploadDir === false) {
            mkdir(__DIR__ . '/uploads', 0777, true);
            $uploadDir = realpath(__DIR__ . '/uploads');
        }

        $fileExt = strtolower(pathinfo($_FILES['proof_photo']['name'], PATHINFO_EXTENSION));
        $safeName = 'cleaner_proof_' . $bookingId . '_' . time() . '.' . $fileExt;
        $targetFile = $uploadDir . DIRECTORY_SEPARATOR . $safeName;

        $isAssignedJob = (bool)$conn->query("SELECT COUNT(*) AS total FROM bookings WHERE id = $bookingId AND FIND_IN_SET($currentEmployeeId, employee_id) > 0")->fetch_assoc()['total'];
        if ($isAssignedJob && move_uploaded_file($_FILES['proof_photo']['tmp_name'], $targetFile)) {
            $relativePath = 'uploads/' . $safeName;
            $stmt = $conn->prepare('UPDATE bookings SET proof_photo = ?, status = IF(status = "pending", "assigned", status) WHERE id = ? AND FIND_IN_SET(?, employee_id) > 0');
            $stmt->bind_param('sii', $relativePath, $bookingId, $currentEmployeeId);
            $stmt->execute();
            $stmt->close();
            setFlash('success', 'Proof uploaded successfully.');
        } else {
            setFlash('error', 'You can only upload proof for your assigned jobs.');
        }
    } else {
        setFlash('error', 'Please select a valid proof photo to upload.');
    }
    header('Location: index.php');
    exit;
}

if (($role === 'cleaner' || $role === 'employee') && $currentEmployeeId) {
    $employeeId = (int)$currentEmployeeId;
    $myBookingsCount = (int)$conn->query("SELECT COUNT(*) AS total FROM bookings WHERE FIND_IN_SET($employeeId, employee_id) > 0")->fetch_assoc()['total'];
    $myOpenTasks = (int)$conn->query("SELECT COUNT(*) AS total FROM bookings WHERE FIND_IN_SET($employeeId, employee_id) > 0 AND status IN ('pending', 'assigned', 'in_progress')")->fetch_assoc()['total'];
    $myCompletedTasks = (int)$conn->query("SELECT COUNT(*) AS total FROM bookings WHERE FIND_IN_SET($employeeId, employee_id) > 0 AND status = 'completed'")->fetch_assoc()['total'];
    $myAssignedJobs = $conn->query("SELECT b.*, c.name AS customer_name, COALESCE(s.service_name, b.service_type, 'Cleaning Service') AS service_name FROM bookings b LEFT JOIN customers c ON c.id = b.customer_id LEFT JOIN services s ON s.id = b.service_id WHERE FIND_IN_SET($employeeId, b.employee_id) > 0 ORDER BY b.scheduled_date DESC, b.id DESC LIMIT 10")->fetch_all(MYSQLI_ASSOC);
} elseif ($role === 'customer' && $currentCustomerId) {
    $myBookingsCount = (int)$conn->query('SELECT COUNT(*) AS total FROM bookings WHERE customer_id = ' . (int)$currentCustomerId)->fetch_assoc()['total'];
    $myUpcomingCleanings = (int)$conn->query("SELECT COUNT(*) AS total FROM bookings WHERE customer_id = " . (int)$currentCustomerId . " AND status IN ('pending', 'assigned', 'in_progress')")->fetch_assoc()['total'];
    $myPaidInvoices = (int)$conn->query('SELECT COUNT(*) AS total FROM payments p INNER JOIN bookings b ON b.id = p.booking_id WHERE b.customer_id = ' . (int)$currentCustomerId . ' AND p.status = "paid"')->fetch_assoc()['total'];
}

$monthlyRevenue = [];
$monthlyRevenueResult = $conn->query("SELECT DATE_FORMAT(payment_date, '%Y-%m') AS month, COALESCE(SUM(amount), 0) AS total FROM payments WHERE payment_date IS NOT NULL AND status = 'paid' GROUP BY DATE_FORMAT(payment_date, '%Y-%m') ORDER BY month ASC LIMIT 12");
while ($row = $monthlyRevenueResult->fetch_assoc()) {
    $monthlyRevenue[] = ['label' => $row['month'], 'total' => (float)$row['total']];
}

$statusResult = $conn->query("SELECT status, COUNT(*) AS total FROM bookings GROUP BY status");
$statusCounts = ['pending' => 0, 'assigned' => 0, 'in_progress' => 0, 'completed' => 0, 'paid' => 0];
while ($row = $statusResult->fetch_assoc()) {
    if (isset($statusCounts[$row['status']])) {
        $statusCounts[$row['status']] = (int)$row['total'];
    }
}

$managerPendingBookings = 0;
$managerUnassignedBookings = 0;
$managerOverdueBookings = 0;
$managerFeedbackCount = 0;
$managerAverageRating = 0.0;
if ($isManager) {
    $managerPendingBookings = (int)$conn->query("SELECT COUNT(*) AS total FROM bookings WHERE status = 'pending'")->fetch_assoc()['total'];
    $managerUnassignedBookings = (int)$conn->query("SELECT COUNT(*) AS total FROM bookings WHERE status IN ('pending', 'assigned', 'in_progress') AND (employee_id IS NULL OR employee_id = '')")->fetch_assoc()['total'];
    $managerOverdueBookings = (int)$conn->query("SELECT COUNT(*) AS total FROM bookings WHERE scheduled_date < CURDATE() AND status IN ('pending', 'assigned', 'in_progress')")->fetch_assoc()['total'];
    $feedbackSummary = $conn->query('SELECT COUNT(*) AS total, COALESCE(AVG(feedback_rating), 0) AS average_rating FROM bookings WHERE feedback_rating IS NOT NULL OR feedback_comment IS NOT NULL')->fetch_assoc();
    $managerFeedbackCount = (int)($feedbackSummary['total'] ?? 0);
    $managerAverageRating = (float)($feedbackSummary['average_rating'] ?? 0);
}

$revenueLabels = [];
$revenueData = [];
foreach ($monthlyRevenue as $entry) {
    $revenueLabels[] = $entry['label'];
    $revenueData[] = $entry['total'];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CleanManage Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --bg: #f5f7f4;
            --bg-soft: #eef6f0;
            --surface: rgba(255,255,255,0.72);
            --surface-strong: #ffffff;
            --primary: #1b4332;
            --primary-2: #2d6a4f;
            --primary-3: #6f9b7a;
            --accent: #d8c9a3;
            --accent-2: #b89a67;
            --slate: #1f2a37;
            --slate-soft: #586779;
            --text: #182521;
            --muted: #60716d;
            --line: rgba(27, 67, 50, 0.1);
            --shadow: 0 18px 40px rgba(17, 24, 39, 0.08);
            --shadow-soft: 0 10px 24px rgba(17, 24, 39, 0.05);
            --success: #3d9c74;
            --warning: #d89b2b;
            --danger: #d75858;
        }

        * { box-sizing: border-box; }

        body {
            background:
                radial-gradient(circle at top left, rgba(109, 157, 121, 0.15), transparent 28%),
                linear-gradient(180deg, var(--bg) 0%, var(--bg-soft) 100%);
            color: var(--text);
            min-height: 100vh;
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
        }

        .navbar {
            background: linear-gradient(135deg, rgba(22, 55, 37, 0.95), rgba(29, 69, 54, 0.92), rgba(45, 106, 79, 0.95));
            backdrop-filter: blur(12px);
            border-bottom: 1px solid rgba(255,255,255,0.08);
            box-shadow: 0 12px 30px rgba(17, 24, 39, 0.14);
        }

        .navbar-brand {
            letter-spacing: 0.08em;
            font-weight: 800;
            text-transform: uppercase;
        }

        .nav-link {
            color: rgba(255,255,255,0.8) !important;
            font-weight: 600;
            border-radius: 10px;
            padding: 0.55rem 0.8rem !important;
            transition: 0.2s ease;
        }

        .nav-link:hover,
        .nav-link.active {
            background: rgba(255,255,255,0.06);
            color: #fff !important;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-2) 100%);
            border: none;
            border-radius: 12px;
            box-shadow: 0 10px 24px rgba(27, 67, 50, 0.2);
            font-weight: 700;
        }

        .btn-outline-primary,
        .btn-outline-secondary,
        .btn-outline-success,
        .btn-outline-warning,
        .btn-outline-info {
            border-radius: 12px;
            font-weight: 600;
            color: var(--primary);
            background: linear-gradient(180deg, rgba(255,255,255,0.96), rgba(240,246,242,0.96));
            border-color: rgba(27, 67, 50, 0.22);
            box-shadow: none;
        }

        .btn-outline-primary:hover,
        .btn-outline-secondary:hover,
        .btn-outline-success:hover,
        .btn-outline-warning:hover,
        .btn-outline-info:hover {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-2) 100%);
            border-color: transparent;
            color: #ffffff;
        }

        .page-shell {
            padding: 32px 0 50px;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            margin-bottom: 28px;
        }

        .eyebrow {
            color: var(--primary-2);
            font-size: 11px;
            letter-spacing: 0.18em;
            font-weight: 700;
            text-transform: uppercase;
            margin-bottom: 8px;
        }

        h1 {
            font-weight: 800;
            letter-spacing: -0.05em;
            margin-bottom: 4px;
            color: var(--slate);
        }

        .subtle {
            color: var(--muted);
        }

        .glass-card,
        .stat-card,
        .chart-card,
        .panel-card {
            background: rgba(255,255,255,0.7);
            backdrop-filter: blur(10px);
            border: 1px solid var(--line);
            border-radius: 22px;
            box-shadow: var(--shadow-soft);
        }

        .stat-card {
            padding: 18px 20px;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            border: 1px solid rgba(27, 67, 50, 0.08);
            background: linear-gradient(180deg, rgba(255,255,255,0.82), rgba(247,250,248,0.92));
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow);
        }

        .panel-card, .chart-card, .glass-card {
            border: 1px solid rgba(27, 67, 50, 0.08);
            background: linear-gradient(180deg, rgba(255,255,255,0.8), rgba(249,251,249,0.95));
        }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .stat-label {
            color: var(--muted);
            margin: 0;
            font-size: 12px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            font-weight: 700;
        }

        .icon-box {
            width: 54px;
            height: 54px;
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.45rem;
            background: linear-gradient(135deg, rgba(27, 67, 50, 0.12), rgba(143, 180, 156, 0.18));
            border: 1px solid rgba(27, 67, 50, 0.08);
        }

        .metric-value {
            font-size: clamp(1.5rem, 2vw, 2rem);
            font-weight: 800;
            letter-spacing: -0.05em;
            margin-bottom: 0;
            color: var(--slate);
        }

        .metric-trend {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border-radius: 999px;
            padding: 5px 10px;
            font-size: 12px;
            font-weight: 700;
            background: rgba(61, 156, 116, 0.12);
            color: var(--primary);
            border: 1px solid rgba(27, 67, 50, 0.06);
        }

        .hero-panel {
            background: linear-gradient(135deg, #142b22 0%, #1b4332 35%, #2d6a4f 100%);
            color: white;
            border: 0;
            border-radius: 26px;
            box-shadow: 0 20px 40px rgba(18, 52, 42, 0.18);
            overflow: hidden;
            position: relative;
        }

        .hero-panel::after {
            content: "";
            position: absolute;
            inset: 0;
            background: linear-gradient(120deg, rgba(216, 201, 163, 0.12), transparent 40%);
            pointer-events: none;
        }

        .hero-panel .panel-body {
            position: relative;
            z-index: 1;
            padding: 28px 28px 20px;
        }

        .pill {
            display: inline-block;
            background: rgba(255,255,255,0.1);
            border: 1px solid rgba(255,255,255,0.14);
            color: rgba(255,255,255,0.9);
            border-radius: 999px;
            padding: 6px 12px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .quick-access-grid {
            display: grid;
            gap: 14px;
        }

        .quick-action {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            background: linear-gradient(180deg, #ffffff 0%, #f2f8f3 100%);
            border: 1px solid rgba(27, 67, 50, 0.12);
            border-radius: 18px;
            padding: 16px 18px;
            color: var(--text);
            text-decoration: none;
            transition: all 0.2s ease;
            box-shadow: 0 10px 18px rgba(20, 83, 45, 0.04);
        }

        .quick-action:hover {
            transform: translateY(-2px);
            box-shadow: 0 14px 24px rgba(17, 24, 39, 0.06);
            text-decoration: none;
            color: var(--text);
            border-color: rgba(27, 67, 50, 0.2);
        }

        .quick-action strong {
            display: block;
            margin-bottom: 4px;
            font-weight: 700;
        }

        .quick-action span {
            color: var(--muted);
        }

        .quick-action-badge {
            min-width: 42px;
            height: 42px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-2) 100%);
            color: #C5A880;
            font-weight: 700;
            font-size: 0.9rem;
            border: 1px solid rgba(27, 67, 50, 0.08);
        }

        .chart-card,
        .panel-card {
            padding: 18px 20px;
        }

        .card-title {
            margin: 0 0 18px;
            font-weight: 700;
            color: var(--slate);
        }

        @media (max-width: 767.98px) {
            .topbar {
                flex-direction: column;
                align-items: flex-start;
            }
            .page-shell {
                padding-top: 22px;
            }
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container">
            <a class="navbar-brand fw-bold" href="index.php">CleanManage</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="mainNav">
                <div class="navbar-nav ms-auto align-items-lg-center gap-lg-2">
                    <a class="nav-link active" href="index.php">Dashboard</a>
                    <?php if ($isAdmin || $isOwner || $isManager): ?>
                        <a class="nav-link" href="customers/list.php">Customers</a>
                        <a class="nav-link" href="employees/list.php">Employees</a>
                    <?php endif; ?>
                    <?php if ($isAdmin || $isOwner || $isManager || $isFinance || $isCleaner || $isCustomer): ?>
                        <a class="nav-link" href="<?= $isCustomer ? 'customers/account.php#bookings' : 'bookings/list.php' ?>"><?php echo $isAdmin || $isOwner || $isManager || $isFinance ? 'Bookings' : ($isCleaner ? 'My Jobs' : 'My Bookings'); ?></a>
                    <?php endif; ?>
                    <?php if ($isAdmin || $isOwner || $isFinance): ?>
                        <a class="nav-link" href="finance/index.php">Finance</a>
                        <a class="nav-link" href="payments/list.php">Payments</a>
                    <?php endif; ?>
                    <a class="btn btn-light btn-sm ms-lg-3" href="logout.php">Logout</a>
                </div>
            </div>
        </div>
    </nav>

    <div class="container page-shell">
        <?php renderFlash(); ?>

        <div class="topbar">
            <div>
                <div class="eyebrow">Operations overview</div>
                <h1>CleanManage System</h1>
                <div class="subtle">Logged in as <?= htmlspecialchars($_SESSION['user_name'] ?? 'User') ?> · <?= htmlspecialchars($role) ?></div>
            </div>
            <div class="d-flex gap-2 align-items-center flex-wrap">
                <?php if ($isAdmin || $isOwner || $isManager): ?>
                    <a href="bookings/add.php" class="btn btn-primary btn-lg">+ New Booking</a>
                <?php endif; ?>
                <a href="logout.php" class="btn btn-outline-secondary">Logout</a>
            </div>
        </div>

        <?php if ($isAdmin): ?>
        <div class="row g-4 mb-4">
            <div class="col-md-6 col-xl-3">
                <div class="stat-card h-100">
                    <div class="stat-header">
                        <div>
                            <p class="stat-label">Customers</p>
                        </div>
                        <div class="icon-box" style="background: rgba(13,110,253,0.10);">👥</div>
                    </div>
                    <p class="metric-value"><?= $customersCount ?></p>
                    <div class="metric-trend">Active clients</div>
                </div>
            </div>
            <div class="col-md-6 col-xl-3">
                <div class="stat-card h-100">
                    <div class="stat-header">
                        <div>
                            <p class="stat-label">Employees</p>
                        </div>
                        <div class="icon-box" style="background: rgba(22,163,74,0.10);">🧹</div>
                    </div>
                    <p class="metric-value"><?= $employeesCount ?></p>
                    <div class="metric-trend">On roster</div>
                </div>
            </div>
            <div class="col-md-6 col-xl-3">
                <div class="stat-card h-100">
                    <div class="stat-header">
                        <div>
                            <p class="stat-label">Bookings</p>
                        </div>
                        <div class="icon-box" style="background: rgba(245,158,11,0.12);">📅</div>
                    </div>
                    <p class="metric-value"><?= $bookingsCount ?></p>
                    <div class="metric-trend">This cycle</div>
                </div>
            </div>
            <div class="col-md-6 col-xl-3">
                <div class="stat-card h-100">
                    <div class="stat-header">
                        <div>
                            <p class="stat-label">Payments</p>
                        </div>
                        <div class="icon-box" style="background: rgba(15,118,110,0.10);">💳</div>
                    </div>
                    <p class="metric-value"><?= $paymentsCount ?></p>
                    <div class="metric-trend">Transactions</div>
                </div>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-lg-8">
                <div class="hero-panel h-100">
                    <div class="panel-body">
                        <span class="pill">Business snapshot</span>
                        <h3 class="mt-3 mb-2">Revenue and booking performance</h3>
                        <div class="row g-3 mt-2">
                            <div class="col-md-4">
                                <div class="text-white-50 small text-uppercase">Collected revenue</div>
                                <div class="fs-3 fw-bold">R <?= number_format($revenue, 2) ?></div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-white-50 small text-uppercase">Pending</div>
                                <div class="fs-3 fw-bold">R <?= number_format($pendingPayments, 2) ?></div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-white-50 small text-uppercase">Completed</div>
                                <div class="fs-3 fw-bold"><?= $completedBookings ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="panel-card h-100">
                    <div class="card-title">Quick actions</div>
                    <div class="quick-access-grid">
                        <a href="customers/list.php" class="quick-action">
                            <div>
                                <strong>Manage customers</strong>
                                <span class="small">View, add and update client records</span>
                            </div>
                            <div class="quick-action-badge">01</div>
                        </a>
                        <a href="employees/list.php" class="quick-action">
                            <div>
                                <strong>Manage employees</strong>
                                <span class="small">Track cleaners and staff assignments</span>
                            </div>
                            <div class="quick-action-badge">02</div>
                        </a>
                        <a href="bookings/list.php" class="quick-action">
                            <div>
                                <strong>Manage bookings</strong>
                                <span class="small">Review schedules and booking status</span>
                            </div>
                            <div class="quick-action-badge">03</div>
                        </a>
                        <a href="payments/list.php" class="quick-action">
                            <div>
                                <strong>Track payments</strong>
                                <span class="small">Invoices, revenue and payment status</span>
                            </div>
                            <div class="quick-action-badge">04</div>
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-lg-8">
                <div class="chart-card h-100">
                    <h5 class="card-title">Revenue per month</h5>
                    <canvas id="revenueChart" height="120"></canvas>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="chart-card h-100">
                    <h5 class="card-title">Booking status</h5>
                    <canvas id="statusChart" height="120"></canvas>
                </div>
            </div>
        </div>
        <?php elseif ($isOwner): ?>
        <div class="row g-4 mb-4">
            <div class="col-md-4">
                <div class="stat-card h-100">
                    <div class="stat-header">
                        <div>
                            <p class="stat-label">Total revenue</p>
                        </div>
                        <div class="icon-box" style="background: rgba(15,118,110,0.10);">💰</div>
                    </div>
                    <p class="metric-value">R <?= number_format($revenue, 2) ?></p>
                    <div class="metric-trend">Business growth</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card h-100">
                    <div class="stat-header">
                        <div>
                            <p class="stat-label">Bookings</p>
                        </div>
                        <div class="icon-box" style="background: rgba(13,110,253,0.10);">📅</div>
                    </div>
                    <p class="metric-value"><?= $bookingsCount ?></p>
                    <div class="metric-trend">Across all teams</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card h-100">
                    <div class="stat-header">
                        <div>
                            <p class="stat-label">Customers</p>
                        </div>
                        <div class="icon-box" style="background: rgba(22,163,74,0.10);">👥</div>
                    </div>
                    <p class="metric-value"><?= $customersCount ?></p>
                    <div class="metric-trend">Active accounts</div>
                </div>
            </div>
        </div>
        <div class="row g-4 mb-4">
            <div class="col-lg-8">
                <div class="chart-card h-100">
                    <h5 class="card-title">Revenue performance</h5>
                    <canvas id="revenueChart" height="120"></canvas>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="chart-card h-100">
                    <h5 class="card-title">Service mix</h5>
                    <canvas id="statusChart" height="120"></canvas>
                </div>
            </div>
        </div>
        <?php elseif ($isManager): ?>
        <div class="mb-4">
            <div class="eyebrow mb-1">Manager workspace</div>
            <h2 class="h3 fw-bold mb-1">Operations Dashboard</h2>
            <p class="subtle mb-0">Coordinate people, schedules, client requests and service delivery.</p>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-md-6 col-xl-3"><a class="text-decoration-none" href="bookings/list.php"><div class="stat-card h-100"><p class="stat-label">Open workload</p><p class="metric-value"><?= $statusCounts['pending'] + $statusCounts['assigned'] + $statusCounts['in_progress'] ?></p><div class="metric-trend">Bookings in progress</div></div></a></div>
            <div class="col-md-6 col-xl-3"><a class="text-decoration-none" href="bookings/list.php"><div class="stat-card h-100"><p class="stat-label">Awaiting approval</p><p class="metric-value"><?= $managerPendingBookings ?></p><div class="metric-trend">Pending requests</div></div></a></div>
            <div class="col-md-6 col-xl-3"><a class="text-decoration-none" href="bookings/list.php"><div class="stat-card h-100"><p class="stat-label">Unassigned</p><p class="metric-value"><?= $managerUnassignedBookings ?></p><div class="metric-trend">Need cleaner assignment</div></div></a></div>
            <div class="col-md-6 col-xl-3"><a class="text-decoration-none" href="bookings/list.php"><div class="stat-card h-100"><p class="stat-label">Past due</p><p class="metric-value"><?= $managerOverdueBookings ?></p><div class="metric-trend">Open jobs past schedule</div></div></a></div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-lg-7">
                <div class="panel-card h-100">
                    <div class="eyebrow mb-1">Daily operations</div>
                    <h2 class="card-title mb-3">Manager actions</h2>
                    <div class="quick-access-grid">
                        <a href="employees/list.php" class="quick-action"><div><strong>Manage employees</strong><span class="small">Review staff and cleaner details</span></div><div class="quick-action-badge">01</div></a>
                        <a href="bookings/calendar.php" class="quick-action"><div><strong>Work schedules</strong><span class="small">View the annual schedule and daily jobs</span></div><div class="quick-action-badge">02</div></a>
                        <a href="bookings/list.php" class="quick-action"><div><strong>Approve and assign bookings</strong><span class="small">Update status and assign cleaners</span></div><div class="quick-action-badge">03</div></a>
                        <a href="customers/list.php" class="quick-action"><div><strong>Manage clients</strong><span class="small">Review customer contact records</span></div><div class="quick-action-badge">04</div></a>
                        <a href="bookings/feedback.php" class="quick-action"><div><strong>Service quality and feedback</strong><span class="small"><?= $managerFeedbackCount ?> feedback entries<?php if ($managerFeedbackCount > 0): ?> · average <?= number_format($managerAverageRating, 1) ?>/5<?php endif; ?></span></div><div class="quick-action-badge">05</div></a>
                        <a href="bookings/add.php" class="quick-action"><div><strong>Create a booking</strong><span class="small">Register a customer service request</span></div><div class="quick-action-badge">06</div></a>
                        <a href="manager/operations.php#attendance" class="quick-action"><div><strong>Attendance and performance</strong><span class="small">Record cleaner attendance and review job completion</span></div><div class="quick-action-badge">07</div></a>
                        <a href="manager/operations.php#inventory" class="quick-action"><div><strong>Materials and equipment</strong><span class="small">Track stock and reorder thresholds</span></div><div class="quick-action-badge">08</div></a>
                        <a href="manager/operations.php#services" class="quick-action"><div><strong>Services and pricing</strong><span class="small">Update rates, duration and cleaner requirements</span></div><div class="quick-action-badge">09</div></a>
                        <a href="finance/index.php" class="quick-action"><div><strong>Financial reports</strong><span class="small">View revenue and payment summaries</span></div><div class="quick-action-badge">10</div></a>
                    </div>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="panel-card h-100">
                    <div class="eyebrow mb-1">Operational performance</div>
                    <h2 class="card-title mb-3">Service and business overview</h2>
                    <div class="d-flex justify-content-between align-items-center py-3 border-top"><span class="fw-semibold">Customers</span><strong><?= $customersCount ?></strong></div>
                    <div class="d-flex justify-content-between align-items-center py-3 border-top"><span class="fw-semibold">Employees</span><strong><?= $employeesCount ?></strong></div>
                    <div class="d-flex justify-content-between align-items-center py-3 border-top"><span class="fw-semibold">Completed services</span><strong><?= $statusCounts['completed'] ?></strong></div>
                    <div class="d-flex justify-content-between align-items-center py-3 border-top"><span class="fw-semibold">Collected revenue</span><strong>R <?= number_format($revenue, 2) ?></strong></div>
                    <div class="d-flex justify-content-between align-items-center py-3 border-top border-bottom"><span class="fw-semibold">Pending payments</span><strong>R <?= number_format($pendingPayments, 2) ?></strong></div>
                    <div class="small text-muted mt-3">Revenue and payment figures are view-only. Finance remains responsible for confirming receipts.</div>
                </div>
            </div>
        </div>

        <div class="panel-card mb-4">
            <div class="eyebrow mb-1">Additional controls</div>
            <h2 class="card-title mb-3">Operational tracking status</h2>
            <div class="row g-3">
                <div class="col-md-4"><div class="border rounded-3 p-3 h-100"><strong>Cleaning materials and equipment</strong><div class="small text-muted mt-1">On-hand quantities and reorder levels are available in operations.</div><a class="btn btn-sm btn-outline-primary mt-3" href="manager/operations.php#inventory">Open inventory</a></div></div>
                <div class="col-md-4"><div class="border rounded-3 p-3 h-100"><strong>Attendance and performance</strong><div class="small text-muted mt-1">Daily attendance records and completed-job totals are available in operations.</div><a class="btn btn-sm btn-outline-primary mt-3" href="manager/operations.php#attendance">Open attendance</a></div></div>
                <div class="col-md-4"><div class="border rounded-3 p-3 h-100"><strong>Services and prices</strong><div class="small text-muted mt-1">Update service rates, expected duration and cleaner requirements.</div><a class="btn btn-sm btn-outline-primary mt-3" href="manager/operations.php#services">Manage services</a></div></div>
            </div>
        </div>
        <?php elseif ($isFinance): ?>
        <div class="row g-4 mb-4">
            <div class="col-md-3">
                <div class="stat-card h-100">
                    <div class="stat-header">
                        <div>
                            <p class="stat-label">Collected</p>
                        </div>
                        <div class="icon-box" style="background: rgba(22,163,74,0.10);">💳</div>
                    </div>
                    <p class="metric-value">R <?= number_format($revenue, 2) ?></p>
                    <div class="metric-trend">Paid invoices</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card h-100">
                    <div class="stat-header">
                        <div>
                            <p class="stat-label">Pending</p>
                        </div>
                        <div class="icon-box" style="background: rgba(245,158,11,0.12);">🧾</div>
                    </div>
                    <p class="metric-value">R <?= number_format($pendingPayments, 2) ?></p>
                    <div class="metric-trend">Outstanding</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card h-100">
                    <div class="stat-header">
                        <div>
                            <p class="stat-label">Transactions</p>
                        </div>
                        <div class="icon-box" style="background: rgba(13,110,253,0.10);">📊</div>
                    </div>
                    <p class="metric-value"><?= $paymentsCount ?></p>
                    <div class="metric-trend">Payment records</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card h-100">
                    <div class="stat-header">
                        <div>
                            <p class="stat-label">Reports</p>
                        </div>
                        <div class="icon-box" style="background: rgba(109,40,217,0.10);">📈</div>
                    </div>
                    <p class="metric-value"><?= count($monthlyRevenue) ?></p>
                    <div class="metric-trend">Monthly periods</div>
                </div>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-lg-7">
                <div class="panel-card h-100">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <div class="eyebrow mb-1">Department</div>
                            <h2 class="card-title mb-0">Finance Department</h2>
                        </div>
                    </div>
                    <p class="subtle mb-4">Payment review and transaction tracking for CleanManage.</p>
                    <div class="d-flex justify-content-between align-items-center py-3 border-top">
                        <span class="fw-semibold">Payments awaiting verification</span>
                        <span class="badge rounded-pill text-bg-warning"><?= $pendingPaymentsCount ?></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center py-3 border-top">
                        <span class="fw-semibold">Amount awaiting verification</span>
                        <span class="fw-bold">R <?= number_format($pendingPayments, 2) ?></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center py-3 border-top border-bottom">
                        <span class="fw-semibold">Payment records</span>
                        <span class="fw-bold"><?= $paymentsCount ?></span>
                    </div>
                    <div class="d-flex flex-wrap gap-2 mt-4">
                        <a href="payments/list.php" class="btn btn-primary">Review payments</a>
                        <a href="finance/index.php" class="btn btn-outline-primary">Open finance dashboard</a>
                    </div>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="panel-card h-100">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <div class="eyebrow mb-1">Finance reports</div>
                            <h2 class="card-title mb-0">Monthly overview</h2>
                        </div>
                    </div>
                    <canvas id="revenueChart" height="180"></canvas>
                    <div class="mt-4">
                        <a href="payments/list.php" class="btn btn-primary w-100 mb-2">Manage Payments</a>
                        <a href="payments/add.php" class="btn btn-outline-primary w-100">Add Payment</a>
                    </div>
                </div>
            </div>
        </div>
        <?php elseif ($isCleaner): ?>
        <div class="row g-4 mb-4">
            <div class="col-md-4">
                <div class="stat-card h-100">
                    <div class="stat-header">
                        <div>
                            <p class="stat-label">Assigned jobs</p>
                        </div>
                        <div class="icon-box" style="background: rgba(13,110,253,0.10);">🧹</div>
                    </div>
                    <p class="metric-value"><?= $myBookingsCount ?></p>
                    <div class="metric-trend">Current workload</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card h-100">
                    <div class="stat-header">
                        <div>
                            <p class="stat-label">Open tasks</p>
                        </div>
                        <div class="icon-box" style="background: rgba(245,158,11,0.12);">📌</div>
                    </div>
                    <p class="metric-value"><?= $myOpenTasks ?></p>
                    <div class="metric-trend">Needs attention</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card h-100">
                    <div class="stat-header">
                        <div>
                            <p class="stat-label">Completed</p>
                        </div>
                        <div class="icon-box" style="background: rgba(22,163,74,0.10);">✅</div>
                    </div>
                    <p class="metric-value"><?= $myCompletedTasks ?></p>
                    <div class="metric-trend">Jobs finished</div>
                </div>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-12">
                <div class="panel-card">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <div class="eyebrow mb-1">Assigned work</div>
                            <h2 class="card-title mb-0">My Cleaning Jobs</h2>
                        </div>
                        <a href="bookings/list.php" class="btn btn-outline-primary btn-sm">View all jobs</a>
                    </div>

                    <?php if (!empty($myAssignedJobs)): ?>
                        <div class="row g-3">
                            <?php foreach ($myAssignedJobs as $job): ?>
                                <?php $jobStatus = $job['status'] ?? 'pending'; ?>
                                <div class="col-lg-6">
                                    <div class="border rounded-4 p-3 h-100" style="background: rgba(248,250,248,0.9); border-color: rgba(27, 67, 50, 0.08);">
                                        <div class="d-flex justify-content-between align-items-start gap-3">
                                            <div>
                                                <div class="fw-bold"><?= htmlspecialchars($job['customer_name'] ?? 'Customer') ?></div>
                                                <small class="text-muted"><?= htmlspecialchars($job['service_name'] ?? 'Cleaning Service') ?></small>
                                            </div>
                                            <span class="status-pill" style="display:inline-block; padding:6px 10px; border-radius:999px; background: rgba(15,76,58,0.12); color: #0F4C3A; font-size: 11px; font-weight: 700; text-transform: capitalize;">
                                                <?= htmlspecialchars($jobStatus) ?>
                                            </span>
                                        </div>

                                        <div class="small text-muted mt-3">
                                            <div>Date: <?= htmlspecialchars($job['scheduled_date'] ?? '-') ?></div>
                                            <?php if (!empty($job['notes'])): ?>
                                                <div>Notes: <?= htmlspecialchars($job['notes']) ?></div>
                                            <?php endif; ?>
                                        </div>

                                        <div class="d-flex flex-wrap gap-2 mt-3">
                                            <?php if (!empty($job['proof_photo'])): ?>
                                                <a href="<?= htmlspecialchars($job['proof_photo']) ?>" target="_blank" class="btn btn-sm btn-outline-success">View proof</a>
                                            <?php endif; ?>
                                            <a href="bookings/invoice.php?id=<?= (int)$job['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary">Invoice</a>
                                        </div>

                                        <form method="POST" enctype="multipart/form-data" class="mt-3 d-flex flex-wrap gap-2 align-items-center">
                                            <input type="hidden" name="proof_upload" value="1">
                                            <input type="hidden" name="booking_id" value="<?= (int)$job['id'] ?>">
                                            <input type="file" name="proof_photo" class="form-control form-control-sm" style="max-width: 220px;" required>
                                            <button type="submit" class="btn btn-sm btn-primary">Upload Proof</button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="text-muted mb-0">No jobs assigned to you yet.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php elseif ($isCustomer): ?>
        <div class="panel-card p-4 mb-4">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-3">
                <div>
                    <div class="eyebrow mb-1">Customer services</div>
                    <h2 class="card-title mb-0">Manage your services</h2>
                </div>
                <a href="customers/account.php" class="btn btn-primary">Open customer account</a>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a href="customers/account.php#new-booking" class="btn btn-outline-primary">Create booking</a>
                <a href="customers/account.php#profile" class="btn btn-outline-primary">Personal information</a>
                <a href="customers/account.php#payments" class="btn btn-outline-primary">Make a payment</a>
                <a href="customers/account.php#feedback" class="btn btn-outline-primary">Confirm delivery and feedback</a>
            </div>
        </div>
        <div class="row g-4 mb-4">
            <div class="col-md-4">
                <div class="stat-card h-100">
                    <div class="stat-header">
                        <div>
                            <p class="stat-label">My bookings</p>
                        </div>
                        <div class="icon-box" style="background: rgba(13,110,253,0.10);">📅</div>
                    </div>
                    <p class="metric-value"><?= $myBookingsCount ?></p>
                    <div class="metric-trend">Total</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card h-100">
                    <div class="stat-header">
                        <div>
                            <p class="stat-label">Upcoming</p>
                        </div>
                        <div class="icon-box" style="background: rgba(245,158,11,0.12);">🕒</div>
                    </div>
                    <p class="metric-value"><?= $myUpcomingCleanings ?></p>
                    <div class="metric-trend">Scheduled</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card h-100">
                    <div class="stat-header">
                        <div>
                            <p class="stat-label">Paid jobs</p>
                        </div>
                        <div class="icon-box" style="background: rgba(22,163,74,0.10);">💳</div>
                    </div>
                    <p class="metric-value"><?= $myPaidInvoices ?></p>
                    <div class="metric-trend">Completed</div>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const revenueLabels = <?= json_encode($revenueLabels) ?>;
        const revenueData = <?= json_encode($revenueData) ?>;
        const statusCounts = <?= json_encode($statusCounts) ?>;

        const revenueChartEl = document.getElementById('revenueChart');
        if (revenueChartEl) {
            new Chart(revenueChartEl, {
                type: 'line',
                data: {
                    labels: revenueLabels.length ? revenueLabels : ['No data'],
                    datasets: [{
                        label: 'Revenue (R)',
                        data: revenueData.length ? revenueData : [0],
                        borderColor: '#14532d',
                        backgroundColor: 'rgba(20, 83, 45, 0.12)',
                        fill: true,
                        tension: 0.4,
                        borderWidth: 3
                    }]
                },
                options: {
                    responsive: true,
                    plugins: { legend: { display: false } },
                    scales: { y: { beginAtZero: true } }
                }
            });
        }

        const statusChartEl = document.getElementById('statusChart');
        if (statusChartEl) {
            new Chart(statusChartEl, {
                type: 'doughnut',
                data: {
                    labels: ['Pending', 'Assigned', 'In Progress', 'Completed', 'Paid'],
                    datasets: [{
                        data: [
                            statusCounts.pending,
                            statusCounts.assigned,
                            statusCounts.in_progress,
                            statusCounts.completed,
                            statusCounts.paid
                        ],
                        backgroundColor: ['#6c757d', '#14532d', '#f59e0b', '#0f766e', '#2563eb']
                    }]
                },
                options: { responsive: true }
            });
        }
    </script>
</body>
</html>