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

function money($amount): string
{
    return '₹' . number_format((float)$amount, 2);
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

function getCountFromSql(mysqli $conn, string $sql): int
{
    $res = $conn->query($sql);
    if (!$res) {
        return 0;
    }
    $row = $res->fetch_assoc();
    return (int)($row['total'] ?? 0);
}

function getSumFromSql(mysqli $conn, string $sql): float
{
    $res = $conn->query($sql);
    if (!$res) {
        return 0;
    }
    $row = $res->fetch_assoc();
    return (float)($row['total'] ?? 0);
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
$requiredTables = ['service_job_cards', 'customers', 'branches', 'customer_vehicles'];
foreach ($requiredTables as $tbl) {
    if (!tableExists($conn, $tbl)) {
        die($tbl . ' table not found.');
    }
}

$hasServiceInvoices = tableExists($conn, 'service_invoices');
$hasServiceComplaints = tableExists($conn, 'service_complaints');

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

$customers = fetchAllAssoc(
    $conn,
    "SELECT id, full_name, mobile
     FROM customers
     WHERE business_id = {$businessId}
     ORDER BY full_name ASC"
);

/* -------------------------------------------------------
   FILTERS
------------------------------------------------------- */
$search = trim($_GET['search'] ?? '');
$branchFilter = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : 0;
$customerFilter = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;
$jobStatusFilter = trim($_GET['job_status'] ?? '');
$warrantyTypeFilter = trim($_GET['warranty_type'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');

$allowedJobStatuses = ['open', 'in_progress', 'waiting_parts', 'ready', 'delivered', 'cancelled'];

$where = [];
$where[] = "sj.business_id = {$businessId}";
$where[] = "cv.business_id = {$businessId}";
$where[] = "cv.warranty_start_date IS NOT NULL";
$where[] = "cv.warranty_end_date IS NOT NULL";
$where[] = "DATE(sj.service_date) BETWEEN cv.warranty_start_date AND cv.warranty_end_date";

if ($search !== '') {
    $safe = $conn->real_escape_string($search);
    $where[] = "(
        sj.jobcard_no LIKE '%{$safe}%'
        OR c.full_name LIKE '%{$safe}%'
        OR c.mobile LIKE '%{$safe}%'
        OR cv.registration_no LIKE '%{$safe}%'
        OR cv.chassis_no LIKE '%{$safe}%'
        OR br.branch_name LIKE '%{$safe}%'
        OR vb.brand_name LIKE '%{$safe}%'
        OR vm.model_name LIKE '%{$safe}%'
    )";
}

if ($branchFilter > 0) {
    $where[] = "sj.branch_id = {$branchFilter}";
}

if ($customerFilter > 0) {
    $where[] = "sj.customer_id = {$customerFilter}";
}

if ($jobStatusFilter !== '' && in_array($jobStatusFilter, $allowedJobStatuses, true)) {
    $safe = $conn->real_escape_string($jobStatusFilter);
    $where[] = "sj.job_status = '{$safe}'";
}

if ($warrantyTypeFilter === 'active_today') {
    $today = date('Y-m-d');
    $where[] = "'{$today}' BETWEEN cv.warranty_start_date AND cv.warranty_end_date";
} elseif ($warrantyTypeFilter === 'expired_today') {
    $today = date('Y-m-d');
    $where[] = "cv.warranty_end_date < '{$today}'";
}

if ($dateFrom !== '') {
    $safe = $conn->real_escape_string($dateFrom);
    $where[] = "DATE(sj.service_date) >= '{$safe}'";
}

if ($dateTo !== '') {
    $safe = $conn->real_escape_string($dateTo);
    $where[] = "DATE(sj.service_date) <= '{$safe}'";
}

$whereSql = implode(' AND ', $where);

/* -------------------------------------------------------
   SUMMARY
------------------------------------------------------- */
$summaryBaseWhere = "
    sj.business_id = {$businessId}
    AND cv.business_id = {$businessId}
    AND cv.warranty_start_date IS NOT NULL
    AND cv.warranty_end_date IS NOT NULL
    AND DATE(sj.service_date) BETWEEN cv.warranty_start_date AND cv.warranty_end_date
";

$totalWarrantyServices = getCountFromSql(
    $conn,
    "SELECT COUNT(*) AS total
     FROM service_job_cards sj
     INNER JOIN customer_vehicles cv ON cv.id = sj.customer_vehicle_id
     WHERE {$summaryBaseWhere}"
);

$totalEstimatedAmount = getSumFromSql(
    $conn,
    "SELECT COALESCE(SUM(sj.estimated_amount),0) AS total
     FROM service_job_cards sj
     INNER JOIN customer_vehicles cv ON cv.id = sj.customer_vehicle_id
     WHERE {$summaryBaseWhere}"
);

$totalFinalAmount = getSumFromSql(
    $conn,
    "SELECT COALESCE(SUM(sj.final_amount),0) AS total
     FROM service_job_cards sj
     INNER JOIN customer_vehicles cv ON cv.id = sj.customer_vehicle_id
     WHERE {$summaryBaseWhere}"
);

$openCount = getCountFromSql(
    $conn,
    "SELECT COUNT(*) AS total
     FROM service_job_cards sj
     INNER JOIN customer_vehicles cv ON cv.id = sj.customer_vehicle_id
     WHERE {$summaryBaseWhere} AND sj.job_status = 'open'"
);

$inProgressCount = getCountFromSql(
    $conn,
    "SELECT COUNT(*) AS total
     FROM service_job_cards sj
     INNER JOIN customer_vehicles cv ON cv.id = sj.customer_vehicle_id
     WHERE {$summaryBaseWhere} AND sj.job_status = 'in_progress'"
);

$readyCount = getCountFromSql(
    $conn,
    "SELECT COUNT(*) AS total
     FROM service_job_cards sj
     INNER JOIN customer_vehicles cv ON cv.id = sj.customer_vehicle_id
     WHERE {$summaryBaseWhere} AND sj.job_status = 'ready'"
);

$deliveredCount = getCountFromSql(
    $conn,
    "SELECT COUNT(*) AS total
     FROM service_job_cards sj
     INNER JOIN customer_vehicles cv ON cv.id = sj.customer_vehicle_id
     WHERE {$summaryBaseWhere} AND sj.job_status = 'delivered'"
);

$todayDate = date('Y-m-d');

$todayWarrantyServices = getCountFromSql(
    $conn,
    "SELECT COUNT(*) AS total
     FROM service_job_cards sj
     INNER JOIN customer_vehicles cv ON cv.id = sj.customer_vehicle_id
     WHERE {$summaryBaseWhere} AND DATE(sj.service_date) = '{$todayDate}'"
);

$activeWarrantyVehicles = getCountFromSql(
    $conn,
    "SELECT COUNT(*) AS total
     FROM customer_vehicles cv
     WHERE cv.business_id = {$businessId}
       AND cv.warranty_start_date IS NOT NULL
       AND cv.warranty_end_date IS NOT NULL
       AND '{$todayDate}' BETWEEN cv.warranty_start_date AND cv.warranty_end_date"
);

/* -------------------------------------------------------
   FETCH WARRANTY SERVICE ROWS
------------------------------------------------------- */
$rows = [];

$invoiceSelect = $hasServiceInvoices ? "
    si.id AS invoice_id,
    si.invoice_no,
    si.invoice_date,
    si.grand_total,
    si.paid_amount,
    si.balance_amount,
    si.payment_status,
    si.invoice_status,
" : "
    NULL AS invoice_id,
    NULL AS invoice_no,
    NULL AS invoice_date,
    0 AS grand_total,
    0 AS paid_amount,
    0 AS balance_amount,
    NULL AS payment_status,
    NULL AS invoice_status,
";

$invoiceJoin = $hasServiceInvoices ? "
    LEFT JOIN service_invoices si ON si.jobcard_id = sj.id
" : "";

$complaintSelect = $hasServiceComplaints ? "
    (
        SELECT COUNT(*)
        FROM service_complaints sc
        WHERE sc.jobcard_id = sj.id
    ) AS complaint_count,
" : "
    0 AS complaint_count,
";

$sql = "SELECT
            sj.*,
            br.branch_name,
            br.branch_code,
            c.full_name AS customer_name,
            c.mobile AS customer_mobile,
            cv.registration_no,
            cv.chassis_no,
            cv.engine_no,
            cv.motor_no,
            cv.color,
            cv.purchase_date,
            cv.warranty_start_date,
            cv.warranty_end_date,
            DATEDIFF(cv.warranty_end_date, DATE(sj.service_date)) AS warranty_days_left,
            vb.brand_name,
            vm.model_name,
            vm.variant_name,
            {$invoiceSelect}
            {$complaintSelect}
            CASE
                WHEN DATE(sj.service_date) BETWEEN cv.warranty_start_date AND cv.warranty_end_date THEN 'under_warranty'
                ELSE 'out_of_warranty'
            END AS warranty_status
        FROM service_job_cards sj
        INNER JOIN customer_vehicles cv ON cv.id = sj.customer_vehicle_id
        LEFT JOIN branches br ON br.id = sj.branch_id
        LEFT JOIN customers c ON c.id = sj.customer_id
        LEFT JOIN vehicle_brands vb ON vb.id = cv.brand_id
        LEFT JOIN vehicle_models vm ON vm.id = cv.model_id
        {$invoiceJoin}
        WHERE {$whereSql}
        ORDER BY sj.id DESC";

$res = $conn->query($sql);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }
}

$pageTitle = 'Warranty Services';
$currentPage = 'warranty-services';
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
                        <h4 class="mb-1">Warranty Services</h4>
                        <p class="text-muted mb-0">View and manage service jobs covered under vehicle warranty</p>
                    </div>
                    <div class="col-md-6 text-md-end mt-3 mt-md-0">
                        <a href="service-jobcard-add.php" class="btn btn-primary">Add Job Card</a>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Total Warranty Services</p>
                                <h3 class="mb-0"><?php echo number_format($totalWarrantyServices); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Estimated Amount</p>
                                <h3 class="mb-0 text-primary"><?php echo money($totalEstimatedAmount); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Final Amount</p>
                                <h3 class="mb-0 text-success"><?php echo money($totalFinalAmount); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Active Warranty Vehicles</p>
                                <h3 class="mb-0 text-info"><?php echo number_format($activeWarrantyVehicles); ?></h3>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-2">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Open</p>
                                <h4 class="mb-0 text-secondary"><?php echo number_format($openCount); ?></h4>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-2">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">In Progress</p>
                                <h4 class="mb-0 text-warning"><?php echo number_format($inProgressCount); ?></h4>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-2">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Ready</p>
                                <h4 class="mb-0 text-success"><?php echo number_format($readyCount); ?></h4>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Delivered</p>
                                <h4 class="mb-0 text-dark"><?php echo number_format($deliveredCount); ?></h4>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Today Warranty Jobs</p>
                                <h4 class="mb-0 text-primary"><?php echo number_format($todayWarrantyServices); ?></h4>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- FILTERS -->
                <div class="card">
                    <div class="card-body">
                        <form method="get" class="row g-3">
                            <div class="col-md-3">
                                <input
                                    type="text"
                                    name="search"
                                    class="form-control"
                                    placeholder="Search job card, customer, reg no..."
                                    value="<?php echo h($search); ?>"
                                >
                            </div>

                            <div class="col-md-2">
                                <select name="branch_id" class="form-select">
                                    <option value="0">All Branches</option>
                                    <?php foreach ($branches as $b): ?>
                                        <option value="<?php echo (int)$b['id']; ?>" <?php echo ($branchFilter === (int)$b['id']) ? 'selected' : ''; ?>>
                                            <?php echo h($b['branch_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-2">
                                <select name="customer_id" class="form-select">
                                    <option value="0">All Customers</option>
                                    <?php foreach ($customers as $c): ?>
                                        <option value="<?php echo (int)$c['id']; ?>" <?php echo ($customerFilter === (int)$c['id']) ? 'selected' : ''; ?>>
                                            <?php echo h($c['full_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-2">
                                <select name="job_status" class="form-select">
                                    <option value="">All Job Status</option>
                                    <option value="open" <?php echo ($jobStatusFilter === 'open') ? 'selected' : ''; ?>>Open</option>
                                    <option value="in_progress" <?php echo ($jobStatusFilter === 'in_progress') ? 'selected' : ''; ?>>In Progress</option>
                                    <option value="waiting_parts" <?php echo ($jobStatusFilter === 'waiting_parts') ? 'selected' : ''; ?>>Waiting Parts</option>
                                    <option value="ready" <?php echo ($jobStatusFilter === 'ready') ? 'selected' : ''; ?>>Ready</option>
                                    <option value="delivered" <?php echo ($jobStatusFilter === 'delivered') ? 'selected' : ''; ?>>Delivered</option>
                                    <option value="cancelled" <?php echo ($jobStatusFilter === 'cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                                </select>
                            </div>

                            <div class="col-md-1">
                                <input type="date" name="date_from" class="form-control" value="<?php echo h($dateFrom); ?>">
                            </div>

                            <div class="col-md-1">
                                <input type="date" name="date_to" class="form-control" value="<?php echo h($dateTo); ?>">
                            </div>

                            <div class="col-md-1">
                                <select name="warranty_type" class="form-select">
                                    <option value="">Warranty</option>
                                    <option value="active_today" <?php echo ($warrantyTypeFilter === 'active_today') ? 'selected' : ''; ?>>Active</option>
                                    <option value="expired_today" <?php echo ($warrantyTypeFilter === 'expired_today') ? 'selected' : ''; ?>>Expired</option>
                                </select>
                            </div>

                            <div class="col-md-12 d-flex gap-2">
                                <button type="submit" class="btn btn-secondary">Filter</button>
                                <a href="warranty-services.php" class="btn btn-light">Reset</a>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- LIST -->
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4">Warranty Service List</h4>

                        <div class="table-responsive">
                            <table class="table table-bordered table-striped align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Job Card</th>
                                        <th>Service Date</th>
                                        <th>Branch</th>
                                        <th>Customer</th>
                                        <th>Vehicle</th>
                                        <th>Warranty</th>
                                        <th>Status</th>
                                        <th>Amounts</th>
                                        <th>Invoice</th>
                                        <th style="width:240px;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($rows)): ?>
                                        <?php $i = 1; foreach ($rows as $row): ?>
                                            <tr>
                                                <td><?php echo $i++; ?></td>

                                                <td class="small">
                                                    <div><strong><?php echo h($row['jobcard_no']); ?></strong></div>
                                                    <div>Complaints: <?php echo (int)($row['complaint_count'] ?? 0); ?></div>
                                                    <div>
                                                        Promised:
                                                        <?php echo !empty($row['promised_delivery']) ? h(date('d M Y h:i A', strtotime($row['promised_delivery']))) : '-'; ?>
                                                    </div>
                                                </td>

                                                <td>
                                                    <?php echo !empty($row['service_date']) ? h(date('d M Y h:i A', strtotime($row['service_date']))) : '-'; ?>
                                                </td>

                                                <td>
                                                    <div><?php echo h($row['branch_name'] ?: '-'); ?></div>
                                                    <div class="small text-muted"><?php echo h($row['branch_code'] ?: '-'); ?></div>
                                                </td>

                                                <td>
                                                    <div><?php echo h($row['customer_name'] ?: '-'); ?></div>
                                                    <div class="small text-muted"><?php echo h($row['customer_mobile'] ?: '-'); ?></div>
                                                </td>

                                                <td class="small">
                                                    <div><strong><?php echo h(($row['brand_name'] ?: 'Vehicle') . ' - ' . ($row['model_name'] ?: '-')); ?></strong></div>
                                                    <div><?php echo h($row['variant_name'] ?: '-'); ?></div>
                                                    <div>Reg: <?php echo h($row['registration_no'] ?: '-'); ?></div>
                                                    <div>Chassis: <?php echo h($row['chassis_no'] ?: '-'); ?></div>
                                                    <div>Color: <?php echo h($row['color'] ?: '-'); ?></div>
                                                </td>

                                                <td class="small">
                                                    <div><strong>Start:</strong> <?php echo !empty($row['warranty_start_date']) ? h(date('d M Y', strtotime($row['warranty_start_date']))) : '-'; ?></div>
                                                    <div><strong>End:</strong> <?php echo !empty($row['warranty_end_date']) ? h(date('d M Y', strtotime($row['warranty_end_date']))) : '-'; ?></div>
                                                    <div><strong>Days Left:</strong> <?php echo (int)($row['warranty_days_left'] ?? 0); ?></div>
                                                    <div>
                                                        <span class="badge bg-success">Under Warranty</span>
                                                    </div>
                                                </td>

                                                <td>
                                                    <?php
                                                    $jobStatus = (string)($row['job_status'] ?? '');
                                                    $jobBadge = 'secondary';

                                                    if ($jobStatus === 'open') {
                                                        $jobBadge = 'secondary';
                                                    } elseif ($jobStatus === 'in_progress') {
                                                        $jobBadge = 'warning';
                                                    } elseif ($jobStatus === 'waiting_parts') {
                                                        $jobBadge = 'danger';
                                                    } elseif ($jobStatus === 'ready') {
                                                        $jobBadge = 'success';
                                                    } elseif ($jobStatus === 'delivered') {
                                                        $jobBadge = 'dark';
                                                    } elseif ($jobStatus === 'cancelled') {
                                                        $jobBadge = 'danger';
                                                    }
                                                    ?>
                                                    <span class="badge bg-<?php echo $jobBadge; ?>">
                                                        <?php echo h(ucwords(str_replace('_', ' ', $jobStatus))); ?>
                                                    </span>
                                                </td>

                                                <td class="small">
                                                    <div><strong>Estimated:</strong> <?php echo money($row['estimated_amount']); ?></div>
                                                    <div><strong>Final:</strong> <?php echo money($row['final_amount']); ?></div>
                                                    <div><strong>Opening KM:</strong> <?php echo number_format((int)($row['opening_km'] ?? 0)); ?></div>
                                                </td>

                                                <td class="small">
                                                    <?php if (!empty($row['invoice_no'])): ?>
                                                        <div><strong><?php echo h($row['invoice_no']); ?></strong></div>
                                                        <div>Total: <?php echo money($row['grand_total']); ?></div>
                                                        <div>Paid: <?php echo money($row['paid_amount']); ?></div>
                                                        <div>Balance: <?php echo money($row['balance_amount']); ?></div>
                                                        <?php
                                                        $payBadge = 'danger';
                                                        if (($row['payment_status'] ?? '') === 'paid') {
                                                            $payBadge = 'success';
                                                        } elseif (($row['payment_status'] ?? '') === 'partial') {
                                                            $payBadge = 'warning';
                                                        }
                                                        ?>
                                                        <div>
                                                            <span class="badge bg-<?php echo $payBadge; ?>">
                                                                <?php echo h(ucfirst((string)($row['payment_status'] ?? 'unpaid'))); ?>
                                                            </span>
                                                        </div>
                                                    <?php else: ?>
                                                        <span class="text-muted">Invoice not created</span>
                                                    <?php endif; ?>
                                                </td>

                                                <td>
                                                    <div class="d-flex flex-wrap gap-2">
                                                        <a href="service-jobcard-view.php?id=<?php echo (int)$row['id']; ?>" class="btn btn-sm btn-info">View</a>
                                                        <a href="service-jobcard-edit.php?id=<?php echo (int)$row['id']; ?>" class="btn btn-sm btn-primary">Edit</a>

                                                        <?php if (!empty($row['invoice_id'])): ?>
                                                            <a href="service-invoice-view.php?id=<?php echo (int)$row['invoice_id']; ?>" class="btn btn-sm btn-success">Invoice</a>
                                                        <?php else: ?>
                                                            <a href="service-invoice-add.php?jobcard_id=<?php echo (int)$row['id']; ?>" class="btn btn-sm btn-success">Create Invoice</a>
                                                        <?php endif; ?>

                                                        <a href="service-jobcard-print.php?id=<?php echo (int)$row['id']; ?>" class="btn btn-sm btn-secondary" target="_blank">Print</a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="11" class="text-center text-muted py-4">No warranty services found.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
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

</body>
</html>