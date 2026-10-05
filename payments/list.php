<?php
require '../includes/db.php';
require '../includes/security.php';
requireRole(['admin', 'owner', 'finance']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['payment_update'])) {
    $paymentId = (int)$_POST['payment_id'];
    $status = trim($_POST['status'] ?? 'pending');
    $amount = (float)($_POST['amount'] ?? 0);

    $stmt = $conn->prepare('UPDATE payments SET amount=?, status=? WHERE id=?');
    $stmt->bind_param('dsi', $amount, $status, $paymentId);
    $stmt->execute();
    $stmt->close();
    redirect('list.php');
}

$search = trim($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;
$searchPattern = '%' . $search . '%';

if ($search === '') {
    $countSql = 'SELECT COUNT(*) AS total FROM payments p LEFT JOIN bookings b ON b.id = p.booking_id LEFT JOIN customers c ON c.id = b.customer_id';
    $countStmt = $conn->prepare($countSql);
    $countStmt->execute();
    $total = (int)$countStmt->get_result()->fetch_assoc()['total'];
    $countStmt->close();

    $offset = ($page - 1) * $perPage;
    $listSql = 'SELECT p.*, b.service_type, c.name AS customer_name FROM payments p LEFT JOIN bookings b ON b.id = p.booking_id LEFT JOIN customers c ON c.id = b.customer_id ORDER BY p.id DESC LIMIT ? OFFSET ?';
    $listStmt = $conn->prepare($listSql);
    $listStmt->bind_param('ii', $perPage, $offset);
    $listStmt->execute();
    $payments = $listStmt->get_result();
    $listStmt->close();
} else {
    $countSql = 'SELECT COUNT(*) AS total FROM payments p LEFT JOIN bookings b ON b.id = p.booking_id LEFT JOIN customers c ON c.id = b.customer_id WHERE c.name LIKE ? OR b.service_type LIKE ? OR p.status LIKE ? OR p.reference_no LIKE ?';
    $countStmt = $conn->prepare($countSql);
    $countStmt->bind_param('ssss', $searchPattern, $searchPattern, $searchPattern, $searchPattern);
    $countStmt->execute();
    $total = (int)$countStmt->get_result()->fetch_assoc()['total'];
    $countStmt->close();

    $offset = ($page - 1) * $perPage;
    $listSql = 'SELECT p.*, b.service_type, c.name AS customer_name FROM payments p LEFT JOIN bookings b ON b.id = p.booking_id LEFT JOIN customers c ON c.id = b.customer_id WHERE c.name LIKE ? OR b.service_type LIKE ? OR p.status LIKE ? OR p.reference_no LIKE ? ORDER BY p.id DESC LIMIT ? OFFSET ?';
    $listStmt = $conn->prepare($listSql);
    $listStmt->bind_param('ssssii', $searchPattern, $searchPattern, $searchPattern, $searchPattern, $perPage, $offset);
    $listStmt->execute();
    $payments = $listStmt->get_result();
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
    <title>Payments - CleanManage</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="../index.php">CleanManage</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#paymentNav" aria-controls="paymentNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="paymentNav">
                <div class="navbar-nav ms-auto">
                    <a class="nav-link" href="../index.php">Dashboard</a>
                    <a class="nav-link active" href="list.php">Payments</a>
                    <a class="nav-link" href="../bookings/list.php">Bookings</a>
                    <a class="nav-link" href="../logout.php">Logout</a>
                </div>
            </div>
        </div>
    </nav>

    <div class="container py-5">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="mb-1">Payment Tracking</h2>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item"><a href="../index.php">Dashboard</a></li>
                        <li class="breadcrumb-item active">Payments</li>
                    </ol>
                </nav>
            </div>
            <a href="add.php" class="btn btn-primary">+ Add Payment</a>
        </div>

        <div class="card shadow-sm border-0 mb-4">
            <div class="card-body">
                <form method="GET" class="row g-2 align-items-end">
                    <div class="col-md-8">
                        <label class="form-label">Search</label>
                        <input type="text" class="form-control" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search customer, service, status, or reference">
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
                                <th>Customer</th>
                                <th>Service</th>
                                <th>Amount</th>
                                <th>Date</th>
                                <th>Status</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($payments && $payments->num_rows > 0): ?>
                                <?php while ($payment = $payments->fetch_assoc()): ?>
                                    <tr>
                                        <td><?= $payment['id'] ?></td>
                                        <td><?= htmlspecialchars($payment['customer_name'] ?? 'Unknown') ?></td>
                                        <td><?= htmlspecialchars($payment['service_type'] ?? '-') ?></td>
                                        <td>R <?= number_format((float)$payment['amount'], 2) ?></td>
                                        <td><?= htmlspecialchars($payment['payment_date'] ?? '-') ?></td>
                                        <td>
                                            <span class="badge rounded-pill bg-<?= $payment['status'] === 'paid' ? 'success' : 'warning text-dark' ?>">
                                                <?= htmlspecialchars($payment['status']) ?>
                                            </span>
                                        </td>
                                        <td class="text-end">
                                            <form method="POST" class="d-flex gap-2 justify-content-end flex-wrap">
                                                <input type="hidden" name="payment_update" value="1">
                                                <input type="hidden" name="payment_id" value="<?= $payment['id'] ?>">
                                                <input type="number" step="0.01" name="amount" class="form-control form-control-sm" value="<?= (float)$payment['amount'] ?>" style="width: 110px;">
                                                <select name="status" class="form-select form-select-sm" style="width: 110px;">
                                                    <option value="pending" <?= $payment['status'] === 'pending' ? 'selected' : '' ?>>Pending</option>
                                                    <option value="paid" <?= $payment['status'] === 'paid' ? 'selected' : '' ?>>Paid</option>
                                                </select>
                                                <button class="btn btn-sm btn-primary" type="submit">Save</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="7" class="text-center py-4 text-muted">No payments found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <?php if ($totalPages > 1): ?>
            <nav aria-label="Payment pagination" class="mt-4">
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
