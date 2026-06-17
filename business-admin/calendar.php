<?php
session_start();
date_default_timezone_set('Asia/Kolkata');

require_once 'includes/config.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not available.');
}
$conn->set_charset('utf8mb4');

/* -------------------------------------------------------
   AUTH CHECK
------------------------------------------------------- */
$businessUserId = isset($_SESSION['business_user_id']) ? (int)$_SESSION['business_user_id'] : 0;
if ($businessUserId <= 0 && isset($_SESSION['user_id'])) {
    $businessUserId = (int)$_SESSION['user_id'];
}

$businessId = isset($_SESSION['business_id']) ? (int)$_SESSION['business_id'] : 0;

if ($businessUserId <= 0 || $businessId <= 0) {
    header('Location: login.php');
    exit;
}

/* -------------------------------------------------------
   HELPERS
------------------------------------------------------- */
function h($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function tableExists(mysqli $conn, string $table): bool
{
    $sql = "SELECT 1
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('s', $table);
    $stmt->execute();
    $res = $stmt->get_result();
    $exists = $res && $res->num_rows > 0;
    $stmt->close();

    return $exists;
}

function fetchAllAssoc(mysqli $conn, string $sql): array
{
    $rows = [];
    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
    }
    return $rows;
}

function monthName(int $month, int $year): string
{
    return date('F Y', strtotime(sprintf('%04d-%02d-01', $year, $month)));
}

/* -------------------------------------------------------
   VALIDATE USER
------------------------------------------------------- */
$loggedUser = null;

if (tableExists($conn, 'business_users') && tableExists($conn, 'businesses')) {
    $stmt = $conn->prepare("SELECT
                                bu.id,
                                bu.full_name,
                                bu.role,
                                bu.status,
                                b.business_name,
                                b.status AS business_status
                            FROM business_users bu
                            INNER JOIN businesses b ON b.id = bu.business_id
                            WHERE bu.id = ? AND bu.business_id = ?
                            LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('ii', $businessUserId, $businessId);
        $stmt->execute();
        $loggedUser = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}

if (
    !$loggedUser ||
    (int)($loggedUser['status'] ?? 0) !== 1 ||
    ($loggedUser['business_status'] ?? '') !== 'active'
) {
    session_destroy();
    header('Location: login.php');
    exit;
}

/* -------------------------------------------------------
   TABLE CHECKS
------------------------------------------------------- */
$hasBranches = tableExists($conn, 'branches');
$hasCustomers = tableExists($conn, 'customers');
$hasServiceJobCards = tableExists($conn, 'service_job_cards');
$hasServiceInvoices = tableExists($conn, 'service_invoices');

if (!$hasBranches) {
    die('branches table not found.');
}

/* -------------------------------------------------------
   FILTERS
------------------------------------------------------- */
$currentYear = (int)date('Y');
$currentMonth = (int)date('m');
$today = date('Y-m-d');

$month = isset($_GET['month']) ? (int)$_GET['month'] : $currentMonth;
$year = isset($_GET['year']) ? (int)$_GET['year'] : $currentYear;
$branchFilter = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : 0;
$selectedDate = trim($_GET['selected_date'] ?? $today);

if ($month < 1 || $month > 12) {
    $month = $currentMonth;
}
if ($year < 2000 || $year > 2100) {
    $year = $currentYear;
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
    $selectedDate = $today;
}

/* -------------------------------------------------------
   MONTH RANGE
------------------------------------------------------- */
$firstDayOfMonth = sprintf('%04d-%02d-01', $year, $month);
$daysInMonth = (int)date('t', strtotime($firstDayOfMonth));
$startWeekday = (int)date('N', strtotime($firstDayOfMonth)); // 1=Mon, 7=Sun

$prevMonthTs = strtotime('-1 month', strtotime($firstDayOfMonth));
$nextMonthTs = strtotime('+1 month', strtotime($firstDayOfMonth));

$prevMonth = (int)date('m', $prevMonthTs);
$prevYear  = (int)date('Y', $prevMonthTs);
$nextMonth = (int)date('m', $nextMonthTs);
$nextYear  = (int)date('Y', $nextMonthTs);

$monthStartDateTime = $firstDayOfMonth . ' 00:00:00';
$monthEndDateTime = sprintf('%04d-%02d-%02d 23:59:59', $year, $month, $daysInMonth);

/* -------------------------------------------------------
   MASTER DATA
------------------------------------------------------- */
$branches = fetchAllAssoc(
    $conn,
    "SELECT id, branch_name, branch_code
     FROM branches
     WHERE business_id = {$businessId}
     ORDER BY branch_name ASC"
);

/* -------------------------------------------------------
   EVENTS
------------------------------------------------------- */
$calendarEvents = [];
$eventsByDate = [];

$branchWhereJob = $branchFilter > 0 ? " AND sj.branch_id = {$branchFilter}" : "";
$branchWhereInv = $branchFilter > 0 ? " AND si.branch_id = {$branchFilter}" : "";

if ($hasServiceJobCards) {
    $jobSql = "SELECT
                    sj.id,
                    sj.jobcard_no,
                    sj.service_date,
                    sj.promised_delivery,
                    sj.job_status,
                    sj.branch_id,
                    br.branch_name,
                    c.full_name AS customer_name,
                    c.mobile AS customer_mobile
               FROM service_job_cards sj
               LEFT JOIN branches br ON br.id = sj.branch_id
               LEFT JOIN customers c ON c.id = sj.customer_id
               WHERE sj.business_id = {$businessId}
                 {$branchWhereJob}
                 AND (
                    (sj.service_date IS NOT NULL AND sj.service_date BETWEEN '{$monthStartDateTime}' AND '{$monthEndDateTime}')
                    OR
                    (sj.promised_delivery IS NOT NULL AND sj.promised_delivery BETWEEN '{$monthStartDateTime}' AND '{$monthEndDateTime}')
                 )
               ORDER BY sj.service_date ASC, sj.promised_delivery ASC";
    $jobRows = fetchAllAssoc($conn, $jobSql);

    foreach ($jobRows as $row) {
        if (!empty($row['service_date'])) {
            $dateKey = date('Y-m-d', strtotime($row['service_date']));
            $event = [
                'type' => 'service_job',
                'label' => 'Service Job',
                'title' => 'Job Card: ' . ($row['jobcard_no'] ?: '-'),
                'time' => date('h:i A', strtotime($row['service_date'])),
                'date' => $dateKey,
                'status' => (string)($row['job_status'] ?? ''),
                'customer_name' => (string)($row['customer_name'] ?? ''),
                'customer_mobile' => (string)($row['customer_mobile'] ?? ''),
                'branch_name' => (string)($row['branch_name'] ?? ''),
                'view_link' => 'service-jobcard-view.php?id=' . (int)$row['id'],
                'color_class' => 'primary'
            ];
            $calendarEvents[] = $event;
            $eventsByDate[$dateKey][] = $event;
        }

        if (!empty($row['promised_delivery'])) {
            $dateKey = date('Y-m-d', strtotime($row['promised_delivery']));
            $event = [
                'type' => 'delivery_due',
                'label' => 'Promised Delivery',
                'title' => 'Delivery Due: ' . ($row['jobcard_no'] ?: '-'),
                'time' => date('h:i A', strtotime($row['promised_delivery'])),
                'date' => $dateKey,
                'status' => (string)($row['job_status'] ?? ''),
                'customer_name' => (string)($row['customer_name'] ?? ''),
                'customer_mobile' => (string)($row['customer_mobile'] ?? ''),
                'branch_name' => (string)($row['branch_name'] ?? ''),
                'view_link' => 'service-jobcard-view.php?id=' . (int)$row['id'],
                'color_class' => 'warning'
            ];
            $calendarEvents[] = $event;
            $eventsByDate[$dateKey][] = $event;
        }
    }
}

if ($hasServiceInvoices) {
    $invoiceSql = "SELECT
                        si.id,
                        si.invoice_no,
                        si.invoice_date,
                        si.payment_status,
                        si.invoice_status,
                        si.grand_total,
                        br.branch_name,
                        c.full_name AS customer_name,
                        c.mobile AS customer_mobile
                   FROM service_invoices si
                   LEFT JOIN branches br ON br.id = si.branch_id
                   LEFT JOIN customers c ON c.id = si.customer_id
                   WHERE si.business_id = {$businessId}
                     {$branchWhereInv}
                     AND si.invoice_date BETWEEN '{$monthStartDateTime}' AND '{$monthEndDateTime}'
                   ORDER BY si.invoice_date ASC";
    $invoiceRows = fetchAllAssoc($conn, $invoiceSql);

    foreach ($invoiceRows as $row) {
        $dateKey = date('Y-m-d', strtotime($row['invoice_date']));
        $event = [
            'type' => 'service_invoice',
            'label' => 'Service Invoice',
            'title' => 'Invoice: ' . ($row['invoice_no'] ?: '-'),
            'time' => date('h:i A', strtotime($row['invoice_date'])),
            'date' => $dateKey,
            'status' => (string)($row['payment_status'] ?? ''),
            'customer_name' => (string)($row['customer_name'] ?? ''),
            'customer_mobile' => (string)($row['customer_mobile'] ?? ''),
            'branch_name' => (string)($row['branch_name'] ?? ''),
            'amount' => (float)($row['grand_total'] ?? 0),
            'view_link' => 'service-invoice-view.php?id=' . (int)$row['id'],
            'color_class' => 'success'
        ];
        $calendarEvents[] = $event;
        $eventsByDate[$dateKey][] = $event;
    }
}

if (isset($eventsByDate[$selectedDate])) {
    usort($eventsByDate[$selectedDate], function ($a, $b) {
        return strcmp($a['time'], $b['time']);
    });
}

$selectedDateEvents = $eventsByDate[$selectedDate] ?? [];

/* -------------------------------------------------------
   COUNTS
------------------------------------------------------- */
$totalMonthEvents = count($calendarEvents);
$selectedDateCount = count($selectedDateEvents);

$monthServiceJobs = 0;
$monthDueDeliveries = 0;
$monthInvoices = 0;

foreach ($calendarEvents as $e) {
    if ($e['type'] === 'service_job') {
        $monthServiceJobs++;
    } elseif ($e['type'] === 'delivery_due') {
        $monthDueDeliveries++;
    } elseif ($e['type'] === 'service_invoice') {
        $monthInvoices++;
    }
}

$pageTitle = 'Calendar';
$currentPage = 'calendar';
?>
<!doctype html>
<html lang="en">
<?php include('includes/head.php'); ?>

<body data-sidebar="dark">

<?php include('includes/pre-loader.php'); ?>

<div id="layout-wrapper">

    <?php include('includes/topbar.php'); ?>

    <div class="vertical-menu">
        <div data-simplebar class="h-100">
            <?php include('includes/sidebar.php'); ?>
        </div>
    </div>

    <div class="main-content">
        <div class="page-content">
            <div class="container-fluid">

                <div class="row mb-3">
                    <div class="col-md-6">
                        <h4 class="mb-1">Calendar</h4>
                        <p class="text-muted mb-0">Service jobs, promised deliveries and service invoices</p>
                    </div>
                    <div class="col-md-6 text-md-end mt-3 mt-md-0">
                        <a href="service-jobcard-add.php" class="btn btn-primary me-2">Add Job Card</a>
                        <a href="service-invoice-add.php" class="btn btn-success">Add Service Invoice</a>
                    </div>
                </div>

                <!-- SUMMARY -->
                <div class="row">
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Month Events</p>
                                <h3 class="mb-0"><?php echo number_format($totalMonthEvents); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Service Jobs</p>
                                <h3 class="mb-0 text-primary"><?php echo number_format($monthServiceJobs); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Promised Deliveries</p>
                                <h3 class="mb-0 text-warning"><?php echo number_format($monthDueDeliveries); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Service Invoices</p>
                                <h3 class="mb-0 text-success"><?php echo number_format($monthInvoices); ?></h3>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- FILTER -->
                <div class="card">
                    <div class="card-body">
                        <form method="get" class="row g-3 align-items-end">
                            <div class="col-md-3">
                                <label class="form-label">Month</label>
                                <select name="month" class="form-select">
                                    <?php for ($m = 1; $m <= 12; $m++): ?>
                                        <option value="<?php echo $m; ?>" <?php echo ($month === $m) ? 'selected' : ''; ?>>
                                            <?php echo h(date('F', mktime(0, 0, 0, $m, 1))); ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">Year</label>
                                <select name="year" class="form-select">
                                    <?php for ($y = $currentYear - 3; $y <= $currentYear + 3; $y++): ?>
                                        <option value="<?php echo $y; ?>" <?php echo ($year === $y) ? 'selected' : ''; ?>>
                                            <?php echo $y; ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">Branch</label>
                                <select name="branch_id" class="form-select">
                                    <option value="0">All Branches</option>
                                    <?php foreach ($branches as $b): ?>
                                        <option value="<?php echo (int)$b['id']; ?>" <?php echo ($branchFilter === (int)$b['id']) ? 'selected' : ''; ?>>
                                            <?php echo h($b['branch_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">Selected Date</label>
                                <input type="date" name="selected_date" class="form-control" value="<?php echo h($selectedDate); ?>">
                            </div>

                            <div class="col-md-1">
                                <button type="submit" class="btn btn-secondary w-100">Go</button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- MONTH NAV -->
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <a class="btn btn-light"
                               href="calendar.php?month=<?php echo $prevMonth; ?>&year=<?php echo $prevYear; ?>&branch_id=<?php echo $branchFilter; ?>&selected_date=<?php echo h($selectedDate); ?>">
                                ← Previous
                            </a>

                            <h4 class="mb-0"><?php echo h(monthName($month, $year)); ?></h4>

                            <a class="btn btn-light"
                               href="calendar.php?month=<?php echo $nextMonth; ?>&year=<?php echo $nextYear; ?>&branch_id=<?php echo $branchFilter; ?>&selected_date=<?php echo h($selectedDate); ?>">
                                Next →
                            </a>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <!-- CALENDAR -->
                    <div class="col-xl-8">
                        <div class="card">
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-bordered align-middle calendar-table mb-0">
                                        <thead>
                                            <tr class="text-center">
                                                <th>Mon</th>
                                                <th>Tue</th>
                                                <th>Wed</th>
                                                <th>Thu</th>
                                                <th>Fri</th>
                                                <th>Sat</th>
                                                <th>Sun</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            $day = 1;
                                            $cell = 1;
                                            $totalCells = ceil(($daysInMonth + $startWeekday - 1) / 7) * 7;

                                            while ($cell <= $totalCells):
                                                echo '<tr>';
                                                for ($weekDay = 1; $weekDay <= 7; $weekDay++, $cell++):
                                                    if ($cell < $startWeekday || $day > $daysInMonth) {
                                                        echo '<td style="height:130px;background:#f8f9fa;"></td>';
                                                    } else {
                                                        $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $day);
                                                        $isToday = ($dateStr === $today);
                                                        $isSelected = ($dateStr === $selectedDate);
                                                        $dayEvents = $eventsByDate[$dateStr] ?? [];
                                                        $dayEventCount = count($dayEvents);

                                                        $tdClass = '';
                                                        if ($isToday) {
                                                            $tdClass .= ' border-primary ';
                                                        }
                                                        if ($isSelected) {
                                                            $tdClass .= ' bg-light ';
                                                        }

                                                        echo '<td class="' . h($tdClass) . '" style="height:130px;vertical-align:top;">';
                                                        echo '<div class="d-flex justify-content-between align-items-start mb-2">';
                                                        echo '<a href="calendar.php?month=' . $month . '&year=' . $year . '&branch_id=' . $branchFilter . '&selected_date=' . $dateStr . '" class="text-decoration-none fw-bold">';
                                                        echo $day;
                                                        echo '</a>';
                                                        if ($isToday) {
                                                            echo '<span class="badge bg-primary">Today</span>';
                                                        }
                                                        echo '</div>';

                                                        if ($dayEventCount > 0) {
                                                            $shown = 0;
                                                            foreach ($dayEvents as $ev) {
                                                                if ($shown >= 3) {
                                                                    break;
                                                                }
                                                                echo '<div class="mb-1">';
                                                                echo '<span class="badge bg-' . h($ev['color_class']) . ' w-100 text-start" style="font-size:11px;">';
                                                                echo h($ev['time']) . ' - ' . h($ev['label']);
                                                                echo '</span>';
                                                                echo '</div>';
                                                                $shown++;
                                                            }

                                                            if ($dayEventCount > 3) {
                                                                echo '<div class="small text-muted">+' . ($dayEventCount - 3) . ' more</div>';
                                                            }
                                                        } else {
                                                            echo '<div class="small text-muted">No events</div>';
                                                        }

                                                        echo '</td>';
                                                        $day++;
                                                    }
                                                endfor;
                                                echo '</tr>';
                                            endwhile;
                                            ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- EVENT DETAILS -->
                    <div class="col-xl-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Events on <?php echo h(date('d M Y', strtotime($selectedDate))); ?></h5>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <span class="badge bg-primary me-1">Service Job</span>
                                    <span class="badge bg-warning me-1">Promised Delivery</span>
                                    <span class="badge bg-success">Service Invoice</span>
                                </div>

                                <p class="text-muted">Total events: <strong><?php echo number_format($selectedDateCount); ?></strong></p>

                                <?php if (!empty($selectedDateEvents)): ?>
                                    <?php foreach ($selectedDateEvents as $ev): ?>
                                        <div class="border rounded p-3 mb-3">
                                            <div class="d-flex justify-content-between align-items-start mb-2">
                                                <span class="badge bg-<?php echo h($ev['color_class']); ?>">
                                                    <?php echo h($ev['label']); ?>
                                                </span>
                                                <span class="small text-muted"><?php echo h($ev['time']); ?></span>
                                            </div>

                                            <div class="fw-bold mb-1"><?php echo h($ev['title']); ?></div>

                                            <?php if (!empty($ev['customer_name'])): ?>
                                                <div class="small mb-1"><strong>Customer:</strong> <?php echo h($ev['customer_name']); ?></div>
                                            <?php endif; ?>

                                            <?php if (!empty($ev['customer_mobile'])): ?>
                                                <div class="small mb-1"><strong>Mobile:</strong> <?php echo h($ev['customer_mobile']); ?></div>
                                            <?php endif; ?>

                                            <?php if (!empty($ev['branch_name'])): ?>
                                                <div class="small mb-1"><strong>Branch:</strong> <?php echo h($ev['branch_name']); ?></div>
                                            <?php endif; ?>

                                            <?php if (!empty($ev['status'])): ?>
                                                <div class="small mb-1">
                                                    <strong>Status:</strong>
                                                    <?php echo h(ucwords(str_replace('_', ' ', $ev['status']))); ?>
                                                </div>
                                            <?php endif; ?>

                                            <?php if (isset($ev['amount'])): ?>
                                                <div class="small mb-2"><strong>Amount:</strong> ₹<?php echo number_format((float)$ev['amount'], 2); ?></div>
                                            <?php endif; ?>

                                            <a href="<?php echo h($ev['view_link']); ?>" class="btn btn-sm btn-outline-primary">View</a>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="text-muted">No events found for selected date.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <?php include('includes/footer.php'); ?>
    </div>
</div>

<?php include('includes/rightbar.php'); ?>
<?php include('includes/scripts.php'); ?>

<style>
.calendar-table th,
.calendar-table td {
    min-width: 110px;
}
.calendar-table td.border-primary {
    box-shadow: inset 0 0 0 2px #0d6efd;
}
</style>

</body>
</html>