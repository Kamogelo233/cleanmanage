 <?php
require '../includes/db.php';
require '../includes/security.php';
requireRole(['admin', 'owner', 'manager']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customerId = (int)($_POST['customer_id'] ?? 0);
    $serviceId = (int)($_POST['service_id'] ?? 0);
    $scheduledDate = !empty($_POST['scheduled_date']) ? $_POST['scheduled_date'] : null;
    $status = trim($_POST['status'] ?? 'pending');
    $notes = trim($_POST['notes'] ?? '');
    $selectedEmployeeIds = $_POST['employee_ids'] ?? [];

    if (is_array($selectedEmployeeIds)) {
        $selectedEmployeeIds = array_map('intval', $selectedEmployeeIds);
        $selectedEmployeeIds = array_values(array_filter($selectedEmployeeIds, fn($id) => $id > 0));
    } else {
        $selectedEmployeeIds = [];
    }

    // If pending, no employees assigned
    if ($status === 'pending') {
        $employeeIdCsv = null;
    } else {
        $employeeIdCsv = !empty($selectedEmployeeIds) ? implode(',', $selectedEmployeeIds) : null;
    }

    if ($customerId > 0 && $serviceId > 0) {
        // Get service name for email/whatsapp
        $serviceStmt = $conn->prepare('SELECT service_name, price, required_staff_count FROM services WHERE id = ? LIMIT 1');
        $serviceStmt->bind_param('i', $serviceId);
        $serviceStmt->execute();
        $serviceRow = $serviceStmt->get_result()->fetch_assoc();
        $serviceStmt->close();

        $serviceType = $serviceRow['service_name'] ?? 'Cleaning Service';

        $stmt = $conn->prepare('INSERT INTO bookings (customer_id, employee_id, service_id, scheduled_date, status, notes) VALUES (?, ?, ?, ?, ?, ?)');
        if (!$stmt) {
            die("Prepare failed: " . $conn->error);
        }

        // FIXED: i=int, s=string (CSV or null), i=int, s=string, s=string, s=string
        $stmt->bind_param('isssss', $customerId, $employeeIdCsv, $serviceId, $scheduledDate, $status, $notes);
        $stmt->execute();
        $bookingId = $stmt->insert_id;
        $stmt->close();

        $customerStmt = $conn->prepare('SELECT name, email, phone FROM customers WHERE id = ? LIMIT 1');
        $customerStmt->bind_param('i', $customerId);
        $customerStmt->execute();
        $customer = $customerStmt->get_result()->fetch_assoc();
        $customerStmt->close();

        if ($customer) {
            $customerName = $customer['name'] ?? 'Customer';
            $customerEmail = $customer['email'] ?? '';
            $customerPhone = $customer['phone'] ?? '';

            if ($customerEmail !== '' && function_exists('sendBookingConfirmationEmail')) {
                sendBookingConfirmationEmail($customerEmail, $customerName, $serviceType, (string)$scheduledDate, $status);
            }

            if ($customerPhone !== '' && function_exists('generateWhatsAppLink')) {
                $whatsappSent = function_exists('sendWhatsAppBookingNotification') && sendWhatsAppBookingNotification($customerPhone, $customerName, $serviceType, (string)$scheduledDate, $status);
                $_SESSION['last_whatsapp_link'] = $whatsappSent ? '' : generateWhatsAppLink($customerPhone, $customerName, $serviceType, (string)$scheduledDate, $status);
            }
        }

        setFlash('success', 'Booking created successfully and confirmation sent.');
    } else {
        setFlash('error', 'Please select a customer and service.');
        redirect('add.php');
    }

    redirect('list.php');
}

$customers = $conn->query('SELECT * FROM customers ORDER BY name ASC');
$employees = $conn->query('SELECT * FROM employees ORDER BY name ASC');
$servicesResult = $conn->query('SELECT * FROM services ORDER BY price ASC');
$services = $servicesResult ? $servicesResult->fetch_all(MYSQLI_ASSOC) : [];
$servicesJson = json_encode($services, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Booking - CleanManage</title>
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

        .navbar-brand, .nav-link, .nav-link.active { color: #fff !important; }
        .nav-link { font-weight: 600; border-radius: 10px; padding: 0.6rem 0.8rem !important; }
        .nav-link:hover, .nav-link.active { background: rgba(255,255,255,0.08); }

        .btn-primary {
            background: linear-gradient(135deg, var(--green-800) 0%, var(--green-700) 100%) !important;
            border: none !important;
            border-radius: 12px !important;
            font-weight: 700;
            box-shadow: 0 10px 20px rgba(27, 67, 50, 0.18);
        }

        .btn-primary:hover { background: linear-gradient(135deg, var(--green-900) 0%, var(--green-800) 100%) !important; }
        .btn-outline-secondary {
            border-radius: 12px;
            border-color: rgba(27, 67, 50, 0.2);
            color: var(--green-800);
            background: #fff;
            font-weight: 600;
        }
        .btn-outline-secondary:hover { background: var(--green-800); color: #fff; }

        .card {
            border: 1px solid var(--line) !important;
            box-shadow: 0 14px 30px rgba(20, 55, 38, 0.06) !important;
            border-radius: 18px !important;
        }

        .form-control, .form-select {
            border-radius: 12px;
            border: 1px solid rgba(27, 67, 50, 0.14);
            background: rgba(244, 250, 246, 0.9);
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--green-700);
            box-shadow: 0 0 0 0.2rem rgba(45, 106, 79, 0.12);
        }
    </style>
</head>
<body class="bg-light">
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container">
            <a class="navbar-brand" href="../index.php">CleanManage</a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="../index.php">Dashboard</a>
                <a class="nav-link" href="list.php">Bookings</a>
            </div>
        </div>
    </nav>

    <div class="container py-5">
        <h2 class="mb-4">New Booking</h2>
        <div class="card shadow-sm border-0">
            <div class="card-body p-4">
                <form method="POST">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Customer</label>
                            <select name="customer_id" class="form-select" required>
                                <option value="">Select customer</option>
                                <?php while ($customer = $customers->fetch_assoc()): ?>
                                    <option value="<?= $customer['id'] ?>"><?= htmlspecialchars($customer['name']) ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Employee(s)</label>
                            <div class="border rounded p-3 bg-light">
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" id="unassignedEmployee" name="employee_ids[]" value="0" checked>
                                    <label class="form-check-label" for="unassignedEmployee">Unassigned (Pending only)</label>
                                </div>
                                <?php while ($employee = $employees->fetch_assoc()): ?>
                                    <div class="form-check mb-2">
                                        <input class="form-check-input employee-checkbox" type="checkbox" name="employee_ids[]" value="<?= $employee['id'] ?>" id="employee_<?= $employee['id'] ?>">
                                        <label class="form-check-label" for="employee_<?= $employee['id'] ?>"><?= htmlspecialchars($employee['name']) ?> (<?= htmlspecialchars($employee['role']) ?>)</label>
                                    </div>
                                <?php endwhile; ?>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Service</label>
                            <select id="serviceSelect" name="service_id" class="form-select" required>
                                <option value="">Select service</option>
                                <?php foreach ($services as $service): ?>
                                    <option value="<?= $service['id'] ?>" data-price="<?= (float)$service['price'] ?>" data-required-staff="<?= (int)$service['required_staff_count'] ?>"><?= htmlspecialchars($service['service_name']) ?> - R <?= number_format((float)$service['price'], 2) ?> (<?= (int)$service['required_staff_count'] ?> cleaner<?= (int)$service['required_staff_count'] > 1 ? 's' : '' ?> required)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Scheduled Date</label>
                            <input type="date" name="scheduled_date" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Service Price</label>
                            <input type="text" id="servicePrice" class="form-control" value="R 0.00" readonly>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Estimated Total</label>
                            <input type="text" id="totalAmount" class="form-control" value="R 0.00" readonly>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Status</label>
                            <select id="statusSelect" name="status" class="form-select">
                                <option value="pending">Pending</option>
                                <option value="assigned">Assigned</option>
                                <option value="in_progress">In Progress</option>
                                <option value="completed">Completed</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Notes</label>
                            <input type="text" name="notes" class="form-control">
                        </div>
                    </div>
                    <div class="mt-4 d-flex gap-2">
                        <button class="btn btn-primary" type="submit">Create Booking</button>
                        <a href="list.php" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        const services = <?= $servicesJson ?>;
        const serviceSelect = document.getElementById('serviceSelect');
        const servicePrice = document.getElementById('servicePrice');
        const totalAmount = document.getElementById('totalAmount');
        const statusSelect = document.getElementById('statusSelect');
        const unassignedEmployee = document.getElementById('unassignedEmployee');
        const employeeCheckboxes = [...document.querySelectorAll('.employee-checkbox')];

        function formatCurrency(value) {
            return 'R ' + Number(value).toLocaleString('en-ZA', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        function updateServiceSummary() {
            const selected = services.find(item => String(item.id) === String(serviceSelect.value));
            const price = selected ? Number(selected.price) : 0;
            servicePrice.value = formatCurrency(price);
            totalAmount.value = formatCurrency(price);
        }

        function updateEmployeeState() {
            const isPending = statusSelect.value === 'pending';
            employeeCheckboxes.forEach(checkbox => {
                checkbox.disabled = isPending;
                if (isPending) { checkbox.checked = false; }
            });
            unassignedEmployee.checked = isPending;
            unassignedEmployee.disabled = false;
        }

        serviceSelect.addEventListener('change', updateServiceSummary);
        statusSelect.addEventListener('change', updateEmployeeState);

        updateServiceSummary();
        updateEmployeeState();
    </script>
</body>
</html>