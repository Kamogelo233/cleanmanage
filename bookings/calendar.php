<?php
require '../includes/db.php';
require '../includes/security.php';
requireAuth();

$bookingsResult = $conn->query('SELECT b.*, c.name AS customer_name, COALESCE(s.service_name, b.service_type, "Cleaning Service") AS service_name FROM bookings b LEFT JOIN customers c ON c.id = b.customer_id LEFT JOIN services s ON s.id = b.service_id ORDER BY b.scheduled_date ASC');
$bookingsByDate = [];
$bookingsByMonth = [];
while ($row = $bookingsResult->fetch_assoc()) {
    $scheduledDate = $row['scheduled_date'] ?? null;
    if ($scheduledDate) {
        $bookingsByDate[$scheduledDate][] = $row;
        $monthKey = substr($scheduledDate, 0, 7);
        $bookingsByMonth[$monthKey][] = $row;
    }
}

$view = in_array($_GET['view'] ?? 'year', ['year', 'month', 'day'], true) ? $_GET['view'] ?? 'year' : 'year';
$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$year = ($year >= 1 && $year <= 9999) ? $year : (int)date('Y');
$selectedMonth = null;
$selectedDate = null;

if (isset($_GET['month']) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $_GET['month'])) {
    $selectedMonth = new DateTimeImmutable($_GET['month'] . '-01');
    $year = (int)$selectedMonth->format('Y');
}

if (isset($_GET['date']) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $_GET['date'], $dateParts) && checkdate((int)$dateParts[2], (int)$dateParts[3], (int)$dateParts[1])) {
    $selectedDate = new DateTimeImmutable($_GET['date']);
    $selectedMonth = $selectedDate->modify('first day of this month');
    $year = (int)$selectedDate->format('Y');
}

$selectedMonth = $selectedMonth ?? new DateTimeImmutable(sprintf('%d-%02d-01', $year, (int)date('n')));
$selectedDayBookings = $selectedDate ? ($bookingsByDate[$selectedDate->format('Y-m-d')] ?? []) : [];
$previousDay = $selectedDate ? $selectedDate->modify('-1 day')->format('Y-m-d') : '';
$nextDay = $selectedDate ? $selectedDate->modify('+1 day')->format('Y-m-d') : '';
$previousYear = $year - 1;
$nextYear = $year + 1;
$previousMonth = $selectedMonth->modify('-1 month');
$nextMonth = $selectedMonth->modify('+1 month');

$months = [];
for ($monthNumber = 1; $monthNumber <= 12; $monthNumber++) {
    $monthStart = new DateTimeImmutable(sprintf('%d-%02d-01', $year, $monthNumber));
    $daysInMonth = (int)$monthStart->format('t');
    $firstWeekday = (int)$monthStart->format('N');
    $monthKey = $monthStart->format('Y-m');
    $monthBookings = $bookingsByMonth[$monthKey] ?? [];

    $days = [];
    for ($day = 1; $day <= $daysInMonth; $day++) {
        $dateKey = $monthStart->format('Y-m') . '-' . str_pad((string)$day, 2, '0', STR_PAD_LEFT);
        $days[] = [
            'day' => $day,
            'date_key' => $dateKey,
            'bookings' => $bookingsByDate[$dateKey] ?? []
        ];
    }

    $months[] = [
        'label' => $monthStart->format('F'),
        'month_key' => $monthKey,
        'count' => count($monthBookings),
        'days' => $days,
        'first_weekday' => $firstWeekday,
    ];
}

$annualBookingCount = array_sum(array_map(fn ($month) => $month['count'], $months));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Calendar - CleanManage</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --bg: #f5f8f6;
            --surface: #ffffff;
            --surface-soft: #f3f7f4;
            --primary: #1b4332;
            --primary-2: #2d6a4f;
            --primary-3: #d8c9a3;
            --muted: #60716d;
            --line: rgba(27, 67, 50, 0.10);
            --text: #192521;
            --shadow: 0 18px 40px rgba(17,24,39,0.08);
        }

        body {
            background: linear-gradient(180deg, #f3f8f5 0%, #edf4ef 100%);
            color: var(--text);
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
        }

        .navbar {
            background: linear-gradient(135deg, rgba(22, 55, 37, 0.96), rgba(29, 69, 54, 0.92), rgba(45, 106, 79, 0.95));
            box-shadow: 0 12px 30px rgba(17,24,39,0.12);
        }

        .main-shell {
            padding: 32px 0 50px;
        }

        .year-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 18px;
        }

        .calendar-card {
            background: rgba(255,255,255,0.8);
            border: 1px solid var(--line);
            border-radius: 24px;
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .month-card {
            background: rgba(255,255,255,0.9);
            border: 1px solid var(--line);
            border-radius: 18px;
            box-shadow: 0 12px 24px rgba(17,24,39,0.04);
            overflow: hidden;
            min-height: 260px;
        }

        .month-card-header {
            background: linear-gradient(135deg, rgba(27, 67, 50, 0.98), rgba(45, 106, 79, 0.90));
            color: white;
            padding: 12px 14px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .mini-calendar {
            padding: 12px;
        }

        .mini-weekdays, .mini-days {
            display: grid;
            grid-template-columns: repeat(7, minmax(0, 1fr));
            gap: 5px;
        }

        .mini-weekdays div {
            font-size: 0.65rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            text-align: center;
            color: var(--muted);
            font-weight: 700;
            padding: 4px 0;
        }

        .mini-day {
            min-height: 26px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            font-size: 0.7rem;
            font-weight: 600;
            background: #f5f8f5;
            color: var(--muted);
            border: 1px solid rgba(27, 67, 50, 0.04);
            text-decoration: none;
        }

        a.mini-day:hover, a.month-link:hover { background: rgba(27, 67, 50, 0.16); color: var(--primary); }

        .month-link { color: inherit; text-decoration: none; }

        .month-link:focus-visible, .mini-day:focus-visible, .day-link:focus-visible {
            outline: 3px solid #d8c9a3;
            outline-offset: 2px;
        }

        .day-link { color: var(--primary); text-decoration: none; font-weight: 700; }

        .day-link:hover { text-decoration: underline; }

        .agenda-row { border-bottom: 1px solid var(--line); padding: 16px 0; }

        .agenda-row:last-child { border-bottom: 0; }

        .mini-day.empty {
            background: transparent;
            border: 1px solid transparent;
        }

        .mini-day.has-booking {
            background: rgba(27, 67, 50, 0.08);
            color: var(--primary);
            border-color: rgba(27, 67, 50, 0.08);
        }

        .mini-day.today {
            background: rgba(22, 163, 74, 0.12);
            color: #166534;
            border-color: rgba(22, 163, 74, 0.12);
        }

        .calendar-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-2) 100%);
            color: #fff;
            border-bottom: 1px solid rgba(255,255,255,0.12);
        }

        .calendar-day {
            min-height: 128px;
            border-radius: 14px;
            background: #fff;
            border: 1px solid rgba(27, 67, 50, 0.08);
            padding: 10px 8px;
            display: flex;
            flex-direction: column;
            align-items: stretch;
        }

        .calendar-day.empty {
            background: rgba(243,247,244,0.9);
            border-style: dashed;
        }

        .day-number {
            font-size: 0.82rem;
            font-weight: 700;
            color: var(--muted);
            margin-bottom: 8px;
        }

        .calendar-day.today .day-number {
            background: rgba(27, 67, 50, 0.08);
            color: var(--primary);
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
        }

        .booking-pill {
            display: block;
            width: 100%;
            font-size: 0.68rem;
            padding: 0.42rem 0.48rem;
            margin-bottom: 0.32rem;
            border-radius: 999px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            text-align: left;
            border: 1px solid transparent;
        }

        .status-pending { background: rgba(245, 158, 11, 0.12); color: #8a5b00; border-color: rgba(245, 158, 11, 0.18); }
        .status-assigned { background: rgba(59, 130, 246, 0.10); color: #1d4ed8; border-color: rgba(59, 130, 246, 0.16); }
        .status-in_progress { background: rgba(168, 85, 247, 0.10); color: #7c3aed; border-color: rgba(168, 85, 247, 0.16); }
        .status-completed { background: rgba(22, 163, 74, 0.10); color: #166534; border-color: rgba(22, 163, 74, 0.18); }

        .summary-card {
            background: linear-gradient(180deg, rgba(255,255,255,0.84), rgba(246,249,247,0.96));
            border: 1px solid var(--line);
            border-radius: 22px;
            box-shadow: var(--shadow);
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-2) 100%);
            border: none;
            border-radius: 12px;
            font-weight: 700;
        }

        .btn-outline-primary {
            border-radius: 12px;
            border-color: rgba(27, 67, 50, 0.25);
            color: var(--primary);
            font-weight: 600;
        }

        .weekday-header {
            font-size: 0.74rem;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            font-weight: 700;
            color: var(--muted);
            text-align: center;
            padding: 10px 0;
        }

        @media (max-width: 991.98px) {
            .year-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        }

        @media (max-width: 767.98px) {
            .year-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
            .mini-calendar { padding: 8px; }
            .mini-weekdays, .mini-days { gap: 3px; }
            .mini-day { min-height: 24px; font-size: 0.65rem; }
        }

        @media (max-width: 479.98px) {
            .year-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container">
            <a class="navbar-brand fw-bold" href="../index.php">CleanManage</a>
            <div class="navbar-nav ms-auto align-items-lg-center gap-lg-2">
                <a class="nav-link" href="../index.php">Dashboard</a>
                <a class="nav-link active" href="list.php">Bookings</a>
                <a class="btn btn-light btn-sm ms-lg-3" href="../logout.php">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container main-shell">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <div>
                <div class="text-uppercase small fw-bold text-success mb-2">Operations calendar</div>
                <h2 class="mb-1 fw-bold"><?= $view === 'day' && $selectedDate ? 'Day Agenda' : ($view === 'month' ? 'Monthly Calendar' : 'Annual Booking Overview') ?></h2>
            </div>
            <div class="d-flex gap-2">
                <a href="?year=<?= $previousYear ?>&amp;view=<?= htmlspecialchars($view) ?><?= $view === 'month' ? '&amp;month=' . $selectedMonth->modify('-1 year')->format('Y-m') : '' ?><?= $view === 'day' && $selectedDate ? '&amp;date=' . $selectedDate->modify('-1 year')->format('Y-m-d') : '' ?>" class="btn btn-outline-primary btn-sm">Previous year</a>
                <a href="?year=<?= $nextYear ?>&amp;view=<?= htmlspecialchars($view) ?><?= $view === 'month' ? '&amp;month=' . $selectedMonth->modify('+1 year')->format('Y-m') : '' ?><?= $view === 'day' && $selectedDate ? '&amp;date=' . $selectedDate->modify('+1 year')->format('Y-m-d') : '' ?>" class="btn btn-outline-primary btn-sm">Next year</a>
                <?php if ($view !== 'year'): ?>
                    <a href="?year=<?= $year ?>" class="btn btn-outline-primary btn-sm">Year overview</a>
                <?php endif; ?>
                <a href="list.php" class="btn btn-primary btn-sm">Back to list</a>
            </div>
        </div>

        <div class="summary-card p-4 mb-4">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div>
                    <div class="text-uppercase small fw-semibold text-muted mb-1">Operational summary</div>
                    <h5 class="mb-0 fw-bold"><?php if ($view === 'day' && $selectedDate): ?><?= count($selectedDayBookings) ?> bookings on <?= $selectedDate->format('F j, Y') ?><?php elseif ($view === 'month'): ?><?= count($bookingsByMonth[$selectedMonth->format('Y-m')] ?? []) ?> bookings in <?= $selectedMonth->format('F Y') ?><?php else: ?><?= $annualBookingCount ?> bookings scheduled in <?= $year ?><?php endif; ?></h5>
                </div>
                <div class="text-end">
                    <div class="text-uppercase small fw-semibold text-muted mb-1"><?= $view === 'year' ? 'Year' : 'Selected date' ?></div>
                    <div class="fw-bold fs-5"><?= $view === 'year' ? $year : ($view === 'month' ? $selectedMonth->format('F Y') : $selectedDate->format('F j, Y')) ?></div>
                </div>
            </div>
        </div>

        <?php if ($view === 'day' && $selectedDate): ?>
            <div class="calendar-card">
                <div class="calendar-header p-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <h4 class="mb-1 fw-bold"><?= $selectedDate->format('l, F j, Y') ?></h4>
                        <a class="text-white" href="?view=month&amp;month=<?= $selectedMonth->format('Y-m') ?>">View <?= $selectedMonth->format('F') ?></a>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="?view=day&amp;date=<?= $previousDay ?>" class="btn btn-light btn-sm">Previous day</a>
                        <a href="?view=day&amp;date=<?= $nextDay ?>" class="btn btn-light btn-sm">Next day</a>
                    </div>
                </div>
                <div class="p-4">
                    <?php if (empty($selectedDayBookings)): ?>
                        <div class="text-muted py-4 text-center">No bookings scheduled for this day.</div>
                    <?php else: ?>
                        <?php foreach ($selectedDayBookings as $booking): ?>
                            <?php $statusClass = 'status-' . str_replace(' ', '_', strtolower($booking['status'] ?? 'pending')); ?>
                            <div class="agenda-row d-flex justify-content-between align-items-start flex-wrap gap-2">
                                <div>
                                    <div class="fw-bold"><?= htmlspecialchars($booking['customer_name'] ?? 'Customer') ?></div>
                                    <div class="text-muted"><?= htmlspecialchars($booking['service_name'] ?? 'Cleaning Service') ?></div>
                                </div>
                                <span class="booking-pill <?= $statusClass ?> w-auto mb-0"><?= htmlspecialchars($booking['status'] ?? 'pending') ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php elseif ($view === 'month'): ?>
            <?php $monthDays = (int)$selectedMonth->format('t'); $monthFirstWeekday = (int)$selectedMonth->format('N'); ?>
            <div class="calendar-card">
                <div class="calendar-header p-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <h4 class="mb-0 fw-bold"><?= $selectedMonth->format('F Y') ?></h4>
                    <div class="d-flex gap-2">
                        <a href="?view=month&amp;month=<?= $previousMonth->format('Y-m') ?>" class="btn btn-light btn-sm">Previous month</a>
                        <a href="?view=month&amp;month=<?= $nextMonth->format('Y-m') ?>" class="btn btn-light btn-sm">Next month</a>
                    </div>
                </div>
                <div class="p-3">
                    <div class="row row-cols-7 g-2 mb-2">
                        <?php foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $weekday): ?>
                            <div class="weekday-header"><?= $weekday ?></div>
                        <?php endforeach; ?>
                    </div>
                    <div class="row row-cols-7 g-2">
                        <?php for ($i = 1; $i < $monthFirstWeekday; $i++): ?><div class="col"><div class="calendar-day empty"></div></div><?php endfor; ?>
                        <?php for ($day = 1; $day <= $monthDays; $day++): ?>
                            <?php $dateKey = $selectedMonth->format('Y-m') . '-' . str_pad((string)$day, 2, '0', STR_PAD_LEFT); $dayBookings = $bookingsByDate[$dateKey] ?? []; ?>
                            <div class="col">
                                <div class="calendar-day <?= $dateKey === date('Y-m-d') ? 'today' : '' ?>">
                                    <div class="day-number"><a class="day-link" href="?view=day&amp;date=<?= $dateKey ?>"><?= $day ?></a></div>
                                    <?php foreach (array_slice($dayBookings, 0, 3) as $booking): ?>
                                        <?php $statusClass = 'status-' . str_replace(' ', '_', strtolower($booking['status'] ?? 'pending')); ?>
                                        <a class="booking-pill <?= $statusClass ?>" href="?view=day&amp;date=<?= $dateKey ?>" title="Open day agenda"><?= htmlspecialchars($booking['customer_name'] ?? 'Customer') ?></a>
                                    <?php endforeach; ?>
                                    <?php if (count($dayBookings) > 3): ?><a class="small day-link" href="?view=day&amp;date=<?= $dateKey ?>">+<?= count($dayBookings) - 3 ?> more</a><?php endif; ?>
                                </div>
                            </div>
                        <?php endfor; ?>
                    </div>
                </div>
            </div>
        <?php else: ?>
        <div class="calendar-card">
            <div class="calendar-header p-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h4 class="mb-0 fw-bold"><?= $year ?> Calendar</h4>
                <span class="badge bg-light text-dark rounded-pill px-3 py-2"><?= $annualBookingCount ?> total bookings</span>
            </div>

            <div class="p-3">
                <div class="year-grid">
                    <?php foreach ($months as $month): ?>
                        <?php $monthDate = new DateTimeImmutable($month['month_key'] . '-01'); ?>
                        <div class="month-card">
                            <div class="month-card-header">
                                <a class="month-link" href="?view=month&amp;month=<?= $month['month_key'] ?>"><strong><?= htmlspecialchars($month['label']) ?></strong></a>
                                <span class="badge bg-light text-dark rounded-pill"><?= $month['count'] ?></span>
                            </div>
                            <div class="mini-calendar">
                                <div class="mini-weekdays mb-2">
                                    <div>M</div>
                                    <div>T</div>
                                    <div>W</div>
                                    <div>T</div>
                                    <div>F</div>
                                    <div>S</div>
                                    <div>S</div>
                                </div>

                                <div class="mini-days">
                                    <?php for ($i = 1; $i < $month['first_weekday']; $i++): ?>
                                        <div class="mini-day empty"></div>
                                    <?php endfor; ?>

                                    <?php foreach ($month['days'] as $day): ?>
                                        <?php $isToday = $day['date_key'] === date('Y-m-d'); ?>
                                        <?php $hasBookings = !empty($day['bookings']); ?>
                                        <a class="mini-day <?= $hasBookings ? 'has-booking' : '' ?> <?= $isToday ? 'today' : '' ?>" href="?view=day&amp;date=<?= $day['date_key'] ?>" title="<?= count($day['bookings']) ?> booking(s)">
                                            <?= $day['day'] ?>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
