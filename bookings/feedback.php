<?php
require '../includes/db.php';
require '../includes/security.php';
requireRole(['admin', 'owner', 'manager']);

$feedbackResult = $conn->query('SELECT b.id, b.scheduled_date, b.status, b.delivery_confirmed_at, b.feedback_rating, b.feedback_comment, c.name AS customer_name, COALESCE(s.service_name, b.service_type, "Cleaning Service") AS service_name FROM bookings b LEFT JOIN customers c ON c.id = b.customer_id LEFT JOIN services s ON s.id = b.service_id WHERE b.feedback_rating IS NOT NULL OR b.feedback_comment IS NOT NULL ORDER BY b.delivery_confirmed_at DESC, b.id DESC');
$feedbackEntries = $feedbackResult ? $feedbackResult->fetch_all(MYSQLI_ASSOC) : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Feedback - CleanManage</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: linear-gradient(180deg, #f3f8f5, #edf4ef); color: #192521; font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif; }
        .navbar { background: linear-gradient(135deg, #163725, #1b4332, #2d6a4f); }
        .page-shell { padding: 32px 0 48px; }
        .feedback-row { background: #fff; border: 1px solid rgba(27,67,50,.1); border-radius: 14px; padding: 18px; }
        .eyebrow { color: #2d6a4f; font-size: .72rem; font-weight: 800; letter-spacing: .12em; text-transform: uppercase; }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container"><a class="navbar-brand fw-bold" href="../index.php">CleanManage</a><div class="navbar-nav ms-auto flex-row gap-3"><a class="nav-link" href="../index.php">Dashboard</a><a class="nav-link" href="list.php">Bookings</a><a class="nav-link active" href="feedback.php">Feedback</a></div></div>
    </nav>
    <main class="container page-shell">
        <div class="d-flex justify-content-between align-items-end flex-wrap gap-3 mb-4">
            <div><div class="eyebrow mb-1">Service quality</div><h1 class="h2 fw-bold mb-1">Customer Feedback</h1><p class="text-muted mb-0">Review confirmed service ratings and comments.</p></div>
            <a href="list.php" class="btn btn-outline-success">Booking management</a>
        </div>
        <?php if (!$feedbackEntries): ?>
            <div class="feedback-row text-muted">No customer feedback has been submitted yet.</div>
        <?php else: ?>
            <div class="d-grid gap-3">
                <?php foreach ($feedbackEntries as $entry): ?>
                    <article class="feedback-row">
                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                            <div><h2 class="h6 fw-bold mb-1"><?= htmlspecialchars($entry['service_name']) ?></h2><div class="small text-muted">Booking #<?= (int)$entry['id'] ?> · <?= htmlspecialchars($entry['customer_name'] ?? 'Customer') ?> · <?= htmlspecialchars($entry['scheduled_date'] ?? 'Date not set') ?></div></div>
                            <?php if ($entry['feedback_rating'] !== null): ?><span class="badge rounded-pill text-bg-success"><?= (int)$entry['feedback_rating'] ?>/5</span><?php endif; ?>
                        </div>
                        <p class="mb-0 mt-3"><?= nl2br(htmlspecialchars($entry['feedback_comment'] ?? 'No written comment.')) ?></p>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>
</body>
</html>