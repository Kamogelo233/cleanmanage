<?php
require '../includes/db.php';
require '../includes/security.php';
require '../includes/pdf.php';
requireAuth();

$bookingId = (int)($_GET['id'] ?? 0);
$booking = null;
if ($bookingId > 0) {
    $stmt = $conn->prepare('SELECT b.*, c.name AS customer_name, c.phone AS customer_phone, c.address AS customer_address, e.name AS employee_name FROM bookings b LEFT JOIN customers c ON c.id = b.customer_id LEFT JOIN employees e ON e.id = b.employee_id WHERE b.id = ? LIMIT 1');
    $stmt->bind_param('i', $bookingId);
    $stmt->execute();
    $booking = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if (!$booking) {
    http_response_code(404);
    echo 'Booking not found.';
    exit;
}

$paymentStmt = $conn->prepare('SELECT COALESCE(SUM(amount), 0) AS total FROM payments WHERE booking_id = ?');
$paymentStmt->bind_param('i', $bookingId);
$paymentStmt->execute();
$paymentAmount = (float)$paymentStmt->get_result()->fetch_assoc()['total'];
$paymentStmt->close();

if (isset($_GET['download']) && $_GET['download'] === '1') {
    $filename = generateSimpleInvoicePdf(
        $bookingId,
        (string)($booking['customer_name'] ?? 'Customer'),
        (string)($booking['service_type'] ?? 'Cleaning Service'),
        $paymentAmount,
        (string)($booking['scheduled_date'] ?? date('Y-m-d'))
    );

    $pdfPath = __DIR__ . '/../uploads/' . $filename;
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    readfile($pdfPath);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Invoice</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container py-5">
        <div class="card shadow-sm border-0">
            <div class="card-body p-5">
                <div class="d-flex justify-content-between align-items-center mb-5">
                    <div>
                        <h2 class="mb-1">CleanManage</h2>
                        <div class="text-muted">Invoice / Receipt</div>
                    </div>
                    <div class="text-end">
                        <div class="fw-semibold">Invoice #<?= $booking['id'] ?></div>
                        <div class="text-muted"><?= htmlspecialchars($booking['scheduled_date'] ?? date('Y-m-d')) ?></div>
                    </div>
                </div>

                <div class="row mb-4">
                    <div class="col-md-6">
                        <h6 class="text-uppercase text-muted">Billed To</h6>
                        <div class="fw-semibold"><?= htmlspecialchars($booking['customer_name'] ?? 'Customer') ?></div>
                        <div><?= htmlspecialchars($booking['customer_phone'] ?? '-') ?></div>
                        <div><?= htmlspecialchars($booking['customer_address'] ?? '-') ?></div>
                    </div>
                    <div class="col-md-6 text-md-end">
                        <h6 class="text-uppercase text-muted">Booking</h6>
                        <div><?= htmlspecialchars($booking['service_type']) ?></div>
                        <div>Status: <span class="badge bg-secondary"><?= htmlspecialchars($booking['status']) ?></span></div>
                        <div>Assigned to: <?= htmlspecialchars($booking['employee_name'] ?? 'Unassigned') ?></div>
                    </div>
                </div>

                <table class="table table-bordered">
                    <thead class="table-dark">
                        <tr>
                            <th>Description</th>
                            <th>Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><?= htmlspecialchars($booking['service_type']) ?></td>
                            <td>R <?= number_format((float)$paymentAmount, 2) ?></td>
                        </tr>
                    </tbody>
                </table>

                <div class="d-flex justify-content-end mt-4">
                    <div class="text-end">
                        <div class="fw-semibold">Total: R <?= number_format((float)$paymentAmount, 2) ?></div>
                    </div>
                </div>

                <div class="mt-4 d-flex gap-2 justify-content-end">
                    <a href="invoice.php?id=<?= $bookingId ?>&download=1" class="btn btn-primary">Download PDF</a>
                    <a href="list.php" class="btn btn-outline-secondary">Back to bookings</a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
