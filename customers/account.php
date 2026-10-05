<?php
require '../includes/db.php';
require '../includes/security.php';
requireRole(['customer']);

$email = trim($_SESSION['user_email'] ?? '');
$userId = (int)($_SESSION['user_id'] ?? 0);
$customerStmt = $conn->prepare('SELECT * FROM customers WHERE email = ? LIMIT 1');
$customerStmt->bind_param('s', $email);
$customerStmt->execute();
$customer = $customerStmt->get_result()->fetch_assoc();
$customerStmt->close();

if (!$customer && $email !== '') {
    $name = trim($_SESSION['user_name'] ?? 'Customer');
    $createCustomer = $conn->prepare('INSERT INTO customers (name, phone, email) VALUES (?, ?, ?)');
    $emptyPhone = '';
    $createCustomer->bind_param('sss', $name, $emptyPhone, $email);
    $createCustomer->execute();
    $customerId = (int)$createCustomer->insert_id;
    $createCustomer->close();
    $customer = ['id' => $customerId, 'name' => $name, 'phone' => '', 'email' => $email, 'address' => ''];
}

if (!$customer) {
    http_response_code(400);
    exit('Your customer profile could not be loaded. Please contact the company.');
}

$customerId = (int)$customer['id'];

function redirectCustomerAccount(string $section): void
{
    header('Location: account.php#' . rawurlencode($section));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        setFlash('danger', 'Your session security check expired. Please try again.');
        redirectCustomerAccount('top');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'profile') {
        $name = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $newEmail = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');

        if ($name === '' || !validatePhone($phone) || !validateEmail($newEmail)) {
            setFlash('danger', 'Enter your name, a valid phone number, and a valid email address.');
            redirectCustomerAccount('profile');
        }

        $emailCheck = $conn->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
        $emailCheck->bind_param('si', $newEmail, $userId);
        $emailCheck->execute();
        $emailTaken = (bool)$emailCheck->get_result()->fetch_assoc();
        $emailCheck->close();

        if ($emailTaken) {
            setFlash('danger', 'That email address is already used by another account.');
            redirectCustomerAccount('profile');
        }

        $conn->begin_transaction();
        try {
            $updateCustomer = $conn->prepare('UPDATE customers SET name = ?, phone = ?, email = ?, address = ? WHERE id = ?');
            $updateCustomer->bind_param('ssssi', $name, $phone, $newEmail, $address, $customerId);
            $updateCustomer->execute();
            $updateCustomer->close();

            $updateUser = $conn->prepare('UPDATE users SET name = ?, email = ? WHERE id = ? AND role = "customer"');
            $updateUser->bind_param('ssi', $name, $newEmail, $userId);
            $updateUser->execute();
            $updateUser->close();
            $conn->commit();

            $_SESSION['user_name'] = $name;
            $_SESSION['user_email'] = $newEmail;
            setFlash('success', 'Your personal information has been updated.');
        } catch (Throwable $error) {
            $conn->rollback();
            setFlash('danger', 'We could not update your information. Please try again.');
        }
        redirectCustomerAccount('profile');
    }

    if ($action === 'booking') {
        $serviceId = (int)($_POST['service_id'] ?? 0);
        $scheduledDate = trim($_POST['scheduled_date'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $scheduledDate);

        if ($serviceId <= 0 || !$parsedDate || $parsedDate->format('Y-m-d') !== $scheduledDate || $scheduledDate < date('Y-m-d')) {
            setFlash('danger', 'Choose a service and a valid date that is today or later.');
            redirectCustomerAccount('new-booking');
        }

        $serviceCheck = $conn->prepare('SELECT service_name FROM services WHERE id = ? LIMIT 1');
        $serviceCheck->bind_param('i', $serviceId);
        $serviceCheck->execute();
        $service = $serviceCheck->get_result()->fetch_assoc();
        $serviceCheck->close();

        if (!$service) {
            setFlash('danger', 'Please choose an available service.');
            redirectCustomerAccount('new-booking');
        }

        $servicePriceStmt = $conn->prepare('SELECT price FROM services WHERE id = ? LIMIT 1');
        $servicePriceStmt->bind_param('i', $serviceId);
        $servicePriceStmt->execute();
        $servicePrice = (float)$servicePriceStmt->get_result()->fetch_assoc()['price'];
        $servicePriceStmt->close();

        $insertBooking = $conn->prepare('INSERT INTO bookings (customer_id, employee_id, service_id, service_type, service_price, scheduled_date, status, notes) VALUES (?, NULL, ?, ?, ?, ?, "pending", ?)');
        $insertBooking->bind_param('iisdss', $customerId, $serviceId, $service['service_name'], $servicePrice, $scheduledDate, $notes);
        $insertBooking->execute();
        $insertBooking->close();
        setFlash('success', 'Your booking request has been sent. The company will confirm the assignment.');
        redirectCustomerAccount('bookings');
    }

    if ($action === 'payment') {
        $bookingId = (int)($_POST['booking_id'] ?? 0);
        $amountInput = trim($_POST['amount'] ?? '');
        $amount = preg_match('/^\d+(?:[.,]\d{1,2})?$/', $amountInput) ? (float)str_replace(',', '.', $amountInput) : false;
        $method = $_POST['method'] ?? '';
        $reference = trim($_POST['reference_no'] ?? '');

        if (!$amount || $amount <= 0 || !in_array($method, ['eft', 'cash'], true)) {
            setFlash('danger', 'Enter a valid payment amount and choose EFT or cash.');
            redirectCustomerAccount('payments');
        }

        $balanceStmt = $conn->prepare('SELECT COALESCE(b.service_price, s.price, (SELECT legacy_service.price FROM services legacy_service WHERE legacy_service.service_name = b.service_type LIMIT 1), 0) AS price, COALESCE(SUM(CASE WHEN p.status IN ("paid", "pending") THEN p.amount ELSE 0 END), 0) AS submitted FROM bookings b LEFT JOIN services s ON s.id = b.service_id LEFT JOIN payments p ON p.booking_id = b.id WHERE b.id = ? AND b.customer_id = ? GROUP BY b.id, b.service_price, s.price');
        if (!$balanceStmt) {
            setFlash('danger', 'We could not check the booking balance. Please contact the company.');
            redirectCustomerAccount('payments');
        }
        $balanceStmt->bind_param('ii', $bookingId, $customerId);
        $balanceStmt->execute();
        $balance = $balanceStmt->get_result()->fetch_assoc();
        $balanceStmt->close();

        $remaining = $balance ? max(0, (float)$balance['price'] - (float)$balance['submitted']) : 0;
        if (!$balance || $amount > $remaining + 0.001 || $remaining <= 0) {
            setFlash('danger', 'That payment exceeds the remaining balance or the booking is not available.');
            redirectCustomerAccount('payments');
        }

        $paymentStatus = 'pending';
        $paymentDate = date('Y-m-d');
        $paymentStmt = $conn->prepare('INSERT INTO payments (booking_id, amount, payment_date, method, reference_no, status) VALUES (?, ?, ?, ?, ?, ?)');
        if (!$paymentStmt) {
            setFlash('danger', 'We could not record your payment submission. Please contact the company.');
            redirectCustomerAccount('payments');
        }
        $paymentStmt->bind_param('idssss', $bookingId, $amount, $paymentDate, $method, $reference, $paymentStatus);
        $paymentStmt->execute();
        $paymentStmt->close();
        setFlash('success', 'Payment submitted for finance verification. It will show as paid after the finance team confirms receipt.');
        redirectCustomerAccount('payments');
    }

    if ($action === 'confirm_delivery') {
        $bookingId = (int)($_POST['booking_id'] ?? 0);
        $confirmStmt = $conn->prepare('UPDATE bookings SET delivery_confirmed_at = NOW() WHERE id = ? AND customer_id = ? AND status = "completed" AND delivery_confirmed_at IS NULL');
        $confirmStmt->bind_param('ii', $bookingId, $customerId);
        $confirmStmt->execute();
        $confirmed = $confirmStmt->affected_rows > 0;
        $confirmStmt->close();
        setFlash($confirmed ? 'success' : 'danger', $confirmed ? 'Service delivery confirmed. You can now leave feedback.' : 'Only completed services can be confirmed.');
        redirectCustomerAccount('feedback');
    }

    if ($action === 'feedback') {
        $bookingId = (int)($_POST['booking_id'] ?? 0);
        $rating = (int)($_POST['rating'] ?? 0);
        $comment = trim($_POST['feedback_comment'] ?? '');

        if ($rating < 1 || $rating > 5) {
            setFlash('danger', 'Choose a rating from 1 to 5 stars.');
            redirectCustomerAccount('feedback');
        }

        $eligibleStmt = $conn->prepare('SELECT id FROM bookings WHERE id = ? AND customer_id = ? AND delivery_confirmed_at IS NOT NULL LIMIT 1');
        $eligibleStmt->bind_param('ii', $bookingId, $customerId);
        $eligibleStmt->execute();
        $eligibleBooking = (bool)$eligibleStmt->get_result()->fetch_assoc();
        $eligibleStmt->close();

        if (!$eligibleBooking) {
            setFlash('danger', 'Confirm completed service delivery before submitting feedback.');
            redirectCustomerAccount('feedback');
        }

        $feedbackStmt = $conn->prepare('UPDATE bookings SET feedback_rating = ?, feedback_comment = ? WHERE id = ? AND customer_id = ? AND delivery_confirmed_at IS NOT NULL');
        $feedbackStmt->bind_param('isii', $rating, $comment, $bookingId, $customerId);
        $feedbackStmt->execute();
        $saved = $feedbackStmt->errno === 0;
        $feedbackStmt->close();
        setFlash($saved ? 'success' : 'danger', $saved ? 'Thank you. Your feedback has been saved.' : 'Confirm completed service delivery before submitting feedback.');
        redirectCustomerAccount('feedback');
    }

    setFlash('danger', 'That customer action is not available.');
    redirectCustomerAccount('top');
}

$servicesResult = $conn->query('SELECT id, service_name, price, duration_hours FROM services ORDER BY service_name ASC');
$services = $servicesResult ? $servicesResult->fetch_all(MYSQLI_ASSOC) : [];
$bookingStmt = $conn->prepare('SELECT b.*, COALESCE(s.service_name, b.service_type, "Cleaning Service") AS service_name, COALESCE(b.service_price, s.price, (SELECT legacy_service.price FROM services legacy_service WHERE legacy_service.service_name = b.service_type LIMIT 1), 0) AS service_price, e.name AS cleaner_name, COALESCE(SUM(CASE WHEN p.status = "paid" THEN p.amount ELSE 0 END), 0) AS paid_amount, COALESCE(SUM(CASE WHEN p.status = "pending" THEN p.amount ELSE 0 END), 0) AS pending_amount FROM bookings b LEFT JOIN services s ON s.id = b.service_id LEFT JOIN employees e ON FIND_IN_SET(e.id, b.employee_id) > 0 AND LOWER(TRIM(e.role)) IN ("cleaner", "employee", "staff") LEFT JOIN payments p ON p.booking_id = b.id WHERE b.customer_id = ? GROUP BY b.id, b.service_price, s.price ORDER BY b.scheduled_date DESC, b.id DESC');
$bookingStmt->bind_param('i', $customerId);
$bookingStmt->execute();
$bookings = $bookingStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$bookingStmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Account - CleanManage</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root { --green: #1b4332; --green-light: #2d6a4f; --ink: #1c2b26; --muted: #60716d; --line: rgba(27,67,50,.12); }
        body { background: linear-gradient(180deg, #f3f8f5, #edf4ef); color: var(--ink); font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif; }
        .navbar { background: linear-gradient(135deg, #163725, var(--green), var(--green-light)); }
        .page-shell { padding: 30px 0 56px; }
        .panel { background: #fff; border: 1px solid var(--line); border-radius: 18px; box-shadow: 0 12px 28px rgba(17,24,39,.05); }
        .eyebrow { color: var(--green-light); font-size: .72rem; font-weight: 800; letter-spacing: .12em; text-transform: uppercase; }
        .form-control, .form-select { border-color: var(--line); border-radius: 10px; }
        .btn-primary { background: linear-gradient(135deg, var(--green), var(--green-light)); border: 0; }
        .status { display: inline-block; padding: .35rem .65rem; border-radius: 99px; background: #edf4ef; color: var(--green); font-size: .78rem; font-weight: 700; text-transform: capitalize; }
        .booking-card { border: 1px solid var(--line); border-radius: 14px; padding: 18px; background: #fff; }
        .section-anchor { scroll-margin-top: 88px; }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container">
            <a class="navbar-brand fw-bold" href="../index.php">CleanManage</a>
            <div class="navbar-nav ms-auto flex-row gap-3 align-items-center">
                <a class="nav-link" href="../index.php">Dashboard</a>
                <a class="nav-link active" href="account.php">My account</a>
                <a class="nav-link" href="../logout.php">Logout</a>
            </div>
        </div>
    </nav>

    <main class="container page-shell" id="top">
        <?php renderFlash(); ?>
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
            <div>
                <div class="eyebrow mb-1">Customer workspace</div>
                <h1 class="h2 fw-bold mb-1">Welcome, <?= htmlspecialchars($customer['name']) ?></h1>
                <p class="text-muted mb-0">Bookings, account details, payments and service follow-up.</p>
            </div>
            <a href="../index.php" class="btn btn-outline-success">Back to dashboard</a>
        </div>

        <section class="panel p-4 mb-4 section-anchor" id="profile">
            <div class="eyebrow mb-1">Personal information</div>
            <h2 class="h5 fw-bold mb-3">Update your account details</h2>
            <form method="post" class="row g-3">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="profile">
                <div class="col-md-6"><label class="form-label" for="name">Full name</label><input class="form-control" id="name" name="name" value="<?= htmlspecialchars($customer['name']) ?>" required></div>
                <div class="col-md-6"><label class="form-label" for="phone">Phone</label><input class="form-control" id="phone" name="phone" value="<?= htmlspecialchars($customer['phone']) ?>" required></div>
                <div class="col-md-6"><label class="form-label" for="email">Email</label><input class="form-control" type="email" id="email" name="email" value="<?= htmlspecialchars($customer['email'] ?? '') ?>" required></div>
                <div class="col-md-6"><label class="form-label" for="address">Service address</label><input class="form-control" id="address" name="address" value="<?= htmlspecialchars($customer['address'] ?? '') ?>"></div>
                <div class="col-12"><button class="btn btn-primary" type="submit">Save personal information</button></div>
            </form>
        </section>

        <section class="panel p-4 mb-4 section-anchor" id="new-booking">
            <div class="eyebrow mb-1">New service</div>
            <h2 class="h5 fw-bold mb-3">Create a booking</h2>
            <form method="post" class="row g-3">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="booking">
                <div class="col-md-5"><label class="form-label" for="service">Cleaning service</label><select class="form-select" id="service" name="service_id" required><option value="">Choose a service</option><?php foreach ($services as $service): ?><option value="<?= (int)$service['id'] ?>"><?= htmlspecialchars($service['service_name']) ?> · R <?= number_format((float)$service['price'], 2) ?></option><?php endforeach; ?></select></div>
                <div class="col-md-3"><label class="form-label" for="scheduled_date">Preferred date</label><input class="form-control" type="date" id="scheduled_date" name="scheduled_date" min="<?= date('Y-m-d') ?>" required></div>
                <div class="col-md-4"><label class="form-label" for="notes">Access notes or requests</label><input class="form-control" id="notes" name="notes" maxlength="500"></div>
                <div class="col-12"><button class="btn btn-primary" type="submit">Submit booking request</button></div>
            </form>
        </section>

        <section class="section-anchor mb-4" id="bookings">
            <div class="section-anchor" id="payments"></div>
            <div class="section-anchor" id="feedback"></div>
            <div class="d-flex justify-content-between align-items-end mb-3">
                <div><div class="eyebrow mb-1">Service activity</div><h2 class="h5 fw-bold mb-0">Your bookings</h2></div>
                <span class="text-muted small"><?= count($bookings) ?> total</span>
            </div>
            <?php if (!$bookings): ?>
                <div class="panel p-4 text-muted">No bookings yet. Use the booking form above to request a service.</div>
            <?php else: ?>
                <div class="row g-3">
                    <?php foreach ($bookings as $booking): ?>
                        <?php $remaining = max(0, (float)$booking['service_price'] - (float)$booking['paid_amount'] - (float)$booking['pending_amount']); ?>
                        <div class="col-12"><article class="booking-card">
                            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                                <div><h3 class="h6 fw-bold mb-1"><?= htmlspecialchars($booking['service_name']) ?></h3><div class="small text-muted">Booking #<?= (int)$booking['id'] ?> · <?= htmlspecialchars($booking['scheduled_date'] ?? 'Date pending') ?><?= $booking['cleaner_name'] ? ' · ' . htmlspecialchars($booking['cleaner_name']) : '' ?></div></div>
                                <span class="status"><?= htmlspecialchars($booking['status']) ?></span>
                            </div>
                            <div class="small mb-3">Service price: R <?= number_format((float)$booking['service_price'], 2) ?> · Paid: R <?= number_format((float)$booking['paid_amount'], 2) ?> · Balance: R <?= number_format($remaining, 2) ?><?php if ((float)$booking['pending_amount'] > 0): ?> · Payment awaiting verification: R <?= number_format((float)$booking['pending_amount'], 2) ?><?php endif; ?></div>
                            <?php if (!empty($booking['proof_photo'])): ?><p class="mb-3"><a href="../<?= htmlspecialchars($booking['proof_photo']) ?>" target="_blank" rel="noopener">View service completion photo</a></p><?php endif; ?>

                            <?php if ($remaining > 0): ?>
                                <form method="post" class="row g-2 align-items-end border-top pt-3 mb-3 section-anchor">
                                    <?= csrfField() ?><input type="hidden" name="action" value="payment"><input type="hidden" name="booking_id" value="<?= (int)$booking['id'] ?>">
                                    <div class="col-sm-3"><label class="form-label small" for="amount-<?= (int)$booking['id'] ?>">Payment amount</label><input class="form-control form-control-sm" type="text" inputmode="decimal" id="amount-<?= (int)$booking['id'] ?>" name="amount" placeholder="e.g. 250.00" pattern="\d+([.,]\d{1,2})?" title="Enter an amount such as 250.00" autocomplete="off" required></div>
                                    <div class="col-sm-3"><label class="form-label small" for="method-<?= (int)$booking['id'] ?>">Method</label><select class="form-select form-select-sm" id="method-<?= (int)$booking['id'] ?>" name="method"><option value="eft">EFT / bank transfer</option><option value="cash">Cash</option></select></div>
                                    <div class="col-sm-4"><label class="form-label small" for="reference-<?= (int)$booking['id'] ?>">Payment reference</label><input class="form-control form-control-sm" id="reference-<?= (int)$booking['id'] ?>" name="reference_no" maxlength="100"></div>
                                    <div class="col-sm-2 d-grid"><button class="btn btn-primary btn-sm" type="submit">Submit payment</button></div>
                                    <div class="col-12 small text-muted">Payment submissions remain pending until finance verifies receipt.</div>
                                </form>
                            <?php endif; ?>

                            <?php if ($booking['status'] === 'completed' && empty($booking['delivery_confirmed_at'])): ?>
                                <form method="post" class="border-top pt-3 mb-3">
                                    <?= csrfField() ?><input type="hidden" name="action" value="confirm_delivery"><input type="hidden" name="booking_id" value="<?= (int)$booking['id'] ?>">
                                    <button class="btn btn-outline-success btn-sm" type="submit">Confirm service delivery</button>
                                </form>
                            <?php elseif (!empty($booking['delivery_confirmed_at'])): ?>
                                <div class="small text-success border-top pt-3 mb-3">Delivery confirmed <?= htmlspecialchars($booking['delivery_confirmed_at']) ?>.</div>
                            <?php endif; ?>

                            <?php if (!empty($booking['delivery_confirmed_at'])): ?>
                                <form method="post" class="row g-2 align-items-end border-top pt-3 section-anchor">
                                    <?= csrfField() ?><input type="hidden" name="action" value="feedback"><input type="hidden" name="booking_id" value="<?= (int)$booking['id'] ?>">
                                    <div class="col-sm-3"><label class="form-label small" for="rating-<?= (int)$booking['id'] ?>">Service rating</label><select class="form-select form-select-sm" id="rating-<?= (int)$booking['id'] ?>" name="rating" required><option value="">Choose rating</option><?php for ($rating = 5; $rating >= 1; $rating--): ?><option value="<?= $rating ?>" <?= (int)$booking['feedback_rating'] === $rating ? 'selected' : '' ?>><?= $rating ?> / 5</option><?php endfor; ?></select></div>
                                    <div class="col-sm-7"><label class="form-label small" for="feedback-<?= (int)$booking['id'] ?>">Feedback</label><input class="form-control form-control-sm" id="feedback-<?= (int)$booking['id'] ?>" name="feedback_comment" maxlength="1000" value="<?= htmlspecialchars($booking['feedback_comment'] ?? '') ?>" placeholder="Share a note about the service"></div>
                                    <div class="col-sm-2 d-grid"><button class="btn btn-primary btn-sm" type="submit">Save feedback</button></div>
                                </form>
                            <?php endif; ?>
                        </article></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>