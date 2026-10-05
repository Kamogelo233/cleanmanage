<?php
require '../includes/db.php';
require '../includes/security.php';
requireRole(['admin', 'owner', 'finance']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $bookingId = (int)($_POST['booking_id'] ?? 0);
    $amount = (float)($_POST['amount'] ?? 0);
    $paymentDate = $_POST['payment_date'] ?? date('Y-m-d');
    $method = trim($_POST['method'] ?? 'cash');
    $status = trim($_POST['status'] ?? 'pending');

    if ($bookingId > 0 && $amount > 0) {
        $stmt = $conn->prepare('INSERT INTO payments (booking_id, amount, payment_date, method, status) VALUES (?, ?, ?, ?, ?)');
        $stmt->bind_param('idsss', $bookingId, $amount, $paymentDate, $method, $status);
        $stmt->execute();
        $stmt->close();
    }

    redirect('list.php');
}

$bookings = $conn->query("SELECT b.*, c.name AS customer_name FROM bookings b LEFT JOIN customers c ON c.id = b.customer_id ORDER BY b.id DESC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Payment - CleanManage</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="../index.php">CleanManage</a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="../index.php">Dashboard</a>
                <a class="nav-link" href="list.php">Payments</a>
            </div>
        </div>
    </nav>

    <div class="container py-5">
        <h2 class="mb-4">Add Payment</h2>
        <div class="card shadow-sm border-0">
            <div class="card-body p-4">
                <form method="POST">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Booking</label>
                            <select name="booking_id" class="form-select" required>
                                <option value="">Select booking</option>
                                <?php while ($booking = $bookings->fetch_assoc()): ?>
                                    <option value="<?= $booking['id'] ?>"><?= htmlspecialchars($booking['customer_name'] ?? 'Customer') ?> - <?= htmlspecialchars($booking['service_type']) ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Amount</label>
                            <input type="number" step="0.01" name="amount" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Payment Date</label>
                            <input type="date" name="payment_date" class="form-control" value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Method</label>
                            <select name="method" class="form-select">
                                <option value="cash">Cash</option>
                                <option value="card">Card</option>
                                <option value="transfer">Transfer</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <option value="pending">Pending</option>
                                <option value="paid">Paid</option>
                            </select>
                        </div>
                    </div>
                    <div class="mt-4 d-flex gap-2">
                        <button class="btn btn-primary" type="submit">Save Payment</button>
                        <a href="list.php" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</body>
</html>
