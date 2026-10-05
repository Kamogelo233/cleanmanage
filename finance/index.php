<?php
require '../includes/db.php';
require '../includes/security.php';
requireAuth();

$role = normalizeUserRole(currentUserRole());
$canManagePayments = in_array($role, ['admin', 'owner', 'finance'], true);
if (!in_array($role, ['admin', 'owner', 'finance', 'manager'], true)) {
    $_SESSION['error'] = 'You do not have permission to access the finance section.';
    header('Location: ../index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_payment'])) {
    if (!$canManagePayments) {
        http_response_code(403);
        exit('You do not have permission to confirm payments.');
    }

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

    header('Location: index.php');
    exit;
}

$revenue = (float)$conn->query("SELECT COALESCE(SUM(amount), 0) AS total FROM payments WHERE status = 'paid'")->fetch_assoc()['total'];
$pendingPayments = (float)$conn->query("SELECT COALESCE(SUM(amount), 0) AS total FROM payments WHERE status = 'pending'")->fetch_assoc()['total'];
$paymentsCount = (int)$conn->query('SELECT COUNT(*) AS total FROM payments')->fetch_assoc()['total'];

$recentPayments = $conn->query("SELECT p.*, c.name AS customer_name, COALESCE(b.service_type, s.service_name, 'Cleaning Service') AS service_name FROM payments p LEFT JOIN bookings b ON b.id = p.booking_id LEFT JOIN customers c ON c.id = b.customer_id LEFT JOIN services s ON s.id = b.service_id ORDER BY p.id DESC LIMIT 5");
$recentPaymentsList = $recentPayments ? $recentPayments->fetch_all(MYSQLI_ASSOC) : [];

$monthlyRevenue = [];
$monthlyRevenueResult = $conn->query("SELECT DATE_FORMAT(payment_date, '%Y-%m') AS month, COALESCE(SUM(amount), 0) AS total FROM payments WHERE payment_date IS NOT NULL AND status = 'paid' GROUP BY DATE_FORMAT(payment_date, '%Y-%m') ORDER BY month ASC LIMIT 12");
while ($row = $monthlyRevenueResult->fetch_assoc()) {
    $monthlyRevenue[] = ['label' => $row['month'], 'total' => (float)$row['total']];
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
    <title>Finance Department - CleanManage</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary: #1b4332;
            --primary-2: #2d6a4f;
            --bg: #f4f8f5;
            --card: #ffffff;
            --text: #182521;
            --muted: #60716d;
            --line: rgba(27, 67, 50, 0.1);
            --shadow: 0 18px 40px rgba(17, 24, 39, 0.08);
        }

        body {
            background: linear-gradient(180deg, #f4f8f5 0%, #eef5f0 100%);
            color: var(--text);
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
        }

        .navbar {
            background: linear-gradient(135deg, rgba(22, 55, 37, 0.95), rgba(29, 69, 54, 0.92), rgba(45, 106, 79, 0.95));
            box-shadow: 0 12px 30px rgba(17, 24, 39, 0.14);
        }

        .nav-link {
            color: rgba(255,255,255,0.8) !important;
            font-weight: 600;
        }

        .nav-link:hover, .nav-link.active {
            color: #fff !important;
        }

        .page-shell {
            padding: 32px 0 50px;
        }

        .panel-card {
            background: linear-gradient(180deg, rgba(255,255,255,0.82), rgba(247,250,248,0.95));
            border: 1px solid var(--line);
            border-radius: 22px;
            box-shadow: var(--shadow);
            padding: 20px;
        }

        .stat-card {
            background: linear-gradient(180deg, rgba(255,255,255,0.83), rgba(247,250,248,0.96));
            border: 1px solid var(--line);
            border-radius: 20px;
            box-shadow: none;
            padding: 18px 20px;
            height: 100%;
        }

        .eyebrow {
            color: var(--primary-2);
            font-size: 11px;
            letter-spacing: 0.18em;
            font-weight: 700;
            text-transform: uppercase;
            margin-bottom: 8px;
        }

        .metric-value {
            font-size: clamp(1.5rem, 2vw, 2rem);
            font-weight: 800;
            letter-spacing: -0.05em;
            margin-bottom: 0;
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
            width: 48px;
            height: 48px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(27, 67, 50, 0.08);
            font-size: 1.25rem;
        }

        .metric-trend {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            padding: 5px 10px;
            font-size: 12px;
            font-weight: 700;
            background: rgba(61, 156, 116, 0.12);
            color: var(--primary);
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-2) 100%);
            border: none;
            border-radius: 12px;
            font-weight: 700;
            box-shadow: 0 10px 22px rgba(27, 67, 50, 0.18);
        }

        .btn-outline-primary {
            border-radius: 12px;
            border-color: rgba(27, 67, 50, 0.25);
            color: var(--primary);
            font-weight: 600;
            background: linear-gradient(180deg, rgba(255,255,255,0.96), rgba(240,246,242,0.96));
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container">
            <a class="navbar-brand fw-bold" href="../index.php">CleanManage</a>
            <div class="navbar-nav ms-auto align-items-lg-center gap-lg-2">
                <a class="nav-link" href="../index.php">Dashboard</a>
                <a class="nav-link active" href="index.php">Finance</a>
                <?php if ($canManagePayments): ?><a class="nav-link" href="../payments/list.php">Payments</a><?php endif; ?>
                <a class="nav-link" href="../bookings/list.php">Bookings</a>
                <a class="btn btn-light btn-sm ms-lg-3" href="../logout.php">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container page-shell">
        <div class="mb-4">
            <div class="eyebrow">Department</div>
            <h1 class="fw-bold mb-1">Finance Department</h1>
            <div class="text-muted">Company financial oversight and payment control<?= $canManagePayments ? '' : ' · view only' ?></div>
        </div>

        <?php renderFlash(); ?>

        <div class="row g-4 mb-4">
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <p class="stat-label">Collected</p>
                        </div>
                        <div class="icon-box">💳</div>
                    </div>
                    <p class="metric-value">R <?= number_format($revenue, 2) ?></p>
                    <div class="metric-trend">Paid invoices</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <p class="stat-label">Outstanding</p>
                        </div>
                        <div class="icon-box">🧾</div>
                    </div>
                    <p class="metric-value">R <?= number_format($pendingPayments, 2) ?></p>
                    <div class="metric-trend">Pending</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <p class="stat-label">Payments</p>
                        </div>
                        <div class="icon-box">📊</div>
                    </div>
                    <p class="metric-value"><?= $paymentsCount ?></p>
                    <div class="metric-trend">Records</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <p class="stat-label">Reports</p>
                        </div>
                        <div class="icon-box">📈</div>
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
                            <div class="eyebrow">Finance overview</div>
                            <h2 class="fw-bold mb-0">Department summary</h2>
                        </div>
                    </div>

                    <p class="mb-0 text-muted">
                        The finance team monitors payment activity, keeps the company’s financial records accurate,
                        supports budget control, and ensures every transaction is aligned with the company’s financial procedures.
                    </p>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="panel-card h-100">
                    <div class="eyebrow">Quick actions</div>
                    <h2 class="fw-bold mb-3">Finance tools</h2>
                    <div class="d-grid gap-2">
                        <?php if ($canManagePayments): ?>
                            <a href="../payments/list.php" class="btn btn-primary">Manage Payments</a>
                            <a href="../payments/add.php" class="btn btn-outline-primary">Add Payment</a>
                        <?php endif; ?>
                        <a href="../bookings/list.php" class="btn btn-outline-primary">Review Bookings</a>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-lg-8">
                <div class="panel-card h-100">
                    <div class="eyebrow">Overview</div>
                    <h2 class="fw-bold mb-3">Revenue trend</h2>
                    <canvas id="revenueChart" height="120"></canvas>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="panel-card h-100">
                    <div class="eyebrow">Recent payments</div>
                    <h2 class="fw-bold mb-3">Latest entries</h2>
                    <div class="d-grid gap-2">
                        <?php if (!empty($recentPaymentsList)): ?>
                            <?php foreach ($recentPaymentsList as $payment): ?>
                                <div class="border rounded p-2">
                                    <div class="fw-semibold"><?= htmlspecialchars($payment['customer_name'] ?? 'Customer') ?></div>
                                    <small class="text-muted"><?= htmlspecialchars($payment['service_name'] ?? 'Service') ?></small>
                                    <div class="mt-1 fw-bold">R <?= number_format((float)$payment['amount'], 2) ?></div>
                                    <div class="d-flex justify-content-between align-items-center mt-2">
                                        <span class="badge rounded-pill bg-<?= $payment['status'] === 'paid' ? 'success' : 'warning text-dark' ?>"><?= htmlspecialchars($payment['status']) ?></span>
                                        <?php if ($canManagePayments && $payment['status'] === 'pending'): ?>
                                            <form method="post" class="m-0">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="confirm_payment" value="1">
                                                <input type="hidden" name="payment_id" value="<?= (int)$payment['id'] ?>">
                                                <button class="btn btn-sm btn-outline-success" type="submit">Confirm payment</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-muted">No payment records yet.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const revenueLabels = <?= json_encode($revenueLabels) ?>;
        const revenueData = <?= json_encode($revenueData) ?>;

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
    </script>
</body>
</html>
