<?php
session_start();
date_default_timezone_set('Asia/Kolkata');

require_once 'includes/config.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not available.");
}
$conn->set_charset("utf8mb4");

/* -------------------------------------------------------
   AUTH CHECK
------------------------------------------------------- */
$businessUserId = isset($_SESSION['business_user_id']) ? (int)$_SESSION['business_user_id'] : 0;
if ($businessUserId <= 0 && isset($_SESSION['user_id'])) {
    $businessUserId = (int)$_SESSION['user_id'];
}

$businessId = isset($_SESSION['business_id']) ? (int)$_SESSION['business_id'] : 0;
$sessionBranchId = isset($_SESSION['branch_id']) ? (int)$_SESSION['branch_id'] : 0;

if ($businessUserId <= 0 || $businessId <= 0) {
    header("Location: login.php");
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
    $sql = "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return false;
    $stmt->bind_param("s", $table);
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
        $res->free();
    }
    return $rows;
}

function fetchOne(mysqli $conn, string $sql): array
{
    $res = $conn->query($sql);
    if (!$res) return [];
    $row = $res->fetch_assoc();
    $res->free();
    return $row ?: [];
}

/* -------------------------------------------------------
   VALIDATE USER
------------------------------------------------------- */
$admin = null;

if (tableExists($conn, 'business_users') && tableExists($conn, 'businesses')) {
    $stmt = $conn->prepare("
        SELECT 
            bu.id,
            bu.business_id,
            bu.branch_id,
            bu.full_name,
            bu.username,
            bu.email,
            bu.mobile,
            bu.role,
            bu.status,
            b.business_name,
            b.business_code,
            b.gstin,
            b.status AS business_status
        FROM business_users bu
        INNER JOIN businesses b ON b.id = bu.business_id
        WHERE bu.id = ? AND bu.business_id = ?
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param("ii", $businessUserId, $businessId);
        $stmt->execute();
        $admin = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}

if (!$admin || (int)($admin['status'] ?? 0) !== 1 || ($admin['business_status'] ?? '') !== 'active') {
    session_destroy();
    header("Location: login.php");
    exit;
}

/* -------------------------------------------------------
   REQUIRED TABLES
------------------------------------------------------- */
if (!tableExists($conn, 'payments')) {
    die('payments table not found.');
}

/* -------------------------------------------------------
   FILTERS
------------------------------------------------------- */
$startDate = trim($_GET['start_date'] ?? date('Y-m-01'));
$endDate = trim($_GET['end_date'] ?? date('Y-m-d'));
$paymentFor = trim($_GET['payment_for'] ?? '');
$methodFilter = isset($_GET['method_id']) ? (int)$_GET['method_id'] : 0;
$customerFilter = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;
$branchFilter = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : 0;

if ($startDate === '' || !strtotime($startDate)) {
    $startDate = date('Y-m-01');
}
if ($endDate === '' || !strtotime($endDate)) {
    $endDate = date('Y-m-d');
}

if (strtotime($startDate) > strtotime($endDate)) {
    $tmp = $startDate;
    $startDate = $endDate;
    $endDate = $tmp;
}

$allowedPaymentFor = ['sales', 'service', 'other'];
if ($paymentFor !== '' && !in_array($paymentFor, $allowedPaymentFor, true)) {
    $paymentFor = '';
}

/* -------------------------------------------------------
   MASTER DATA
------------------------------------------------------- */
$branches = [];
if (tableExists($conn, 'branches')) {
    $branches = fetchAllAssoc($conn, "
        SELECT id, branch_name, branch_code
        FROM branches
        WHERE business_id = {$businessId}
        ORDER BY branch_name ASC
    ");
}

$paymentMethods = [];
if (tableExists($conn, 'payment_methods')) {
    $paymentMethods = fetchAllAssoc($conn, "
        SELECT id, method_name
        FROM payment_methods
        WHERE business_id = {$businessId}
        AND status = 1
        ORDER BY method_name ASC
    ");
}

$customers = [];
if (tableExists($conn, 'customers')) {
    $customers = fetchAllAssoc($conn, "
        SELECT id, full_name, mobile
        FROM customers
        WHERE business_id = {$businessId}
        ORDER BY full_name ASC
        LIMIT 1000
    ");
}

/* -------------------------------------------------------
   COMMON WHERE
------------------------------------------------------- */
$where = ["p.business_id = {$businessId}"];
$where[] = "DATE(p.payment_date) BETWEEN '{$conn->real_escape_string($startDate)}' AND '{$conn->real_escape_string($endDate)}'";

if ($branchFilter > 0) {
    $where[] = "p.branch_id = {$branchFilter}";
}

if ($paymentFor !== '') {
    $safe = $conn->real_escape_string($paymentFor);
    $where[] = "p.payment_for = '{$safe}'";
}

if ($methodFilter > 0) {
    $where[] = "p.payment_method_id = {$methodFilter}";
}

if ($customerFilter > 0) {
    $where[] = "p.customer_id = {$customerFilter}";
}

$whereClause = implode(' AND ', $where);

/* -------------------------------------------------------
   SUMMARY STATISTICS
------------------------------------------------------- */
$summary = fetchOne($conn, "
    SELECT
        COUNT(*) AS payment_count,
        COALESCE(SUM(amount), 0) AS total_amount,
        COALESCE(AVG(amount), 0) AS avg_amount,
        COALESCE(MAX(amount), 0) AS max_amount,
        COALESCE(MIN(amount), 0) AS min_amount
    FROM payments p
    WHERE {$whereClause}
");

$totalPayments = (int)($summary['payment_count'] ?? 0);
$totalAmount = (float)($summary['total_amount'] ?? 0);
$averagePayment = (float)($summary['avg_amount'] ?? 0);
$maxPayment = (float)($summary['max_amount'] ?? 0);
$minPayment = (float)($summary['min_amount'] ?? 0);

/* -------------------------------------------------------
   PAYMENT FOR BREAKDOWN
------------------------------------------------------- */
$paymentForBreakdown = fetchAllAssoc($conn, "
    SELECT
        p.payment_for,
        COUNT(p.id) AS payment_count,
        COALESCE(SUM(p.amount), 0) AS total_amount,
        COALESCE(AVG(p.amount), 0) AS avg_amount
    FROM payments p
    WHERE {$whereClause}
    GROUP BY p.payment_for
    ORDER BY total_amount DESC
");

/* -------------------------------------------------------
   PAYMENT METHOD BREAKDOWN - SAME FILTERS
------------------------------------------------------- */
$methodBreakdown = [];
if (tableExists($conn, 'payment_methods')) {
    $methodBreakdown = fetchAllAssoc($conn, "
        SELECT
            pm.id,
            pm.method_name,
            COUNT(p.id) AS payment_count,
            COALESCE(SUM(p.amount), 0) AS total_amount,
            COALESCE(AVG(p.amount), 0) AS avg_amount
        FROM payment_methods pm
        LEFT JOIN payments p ON p.payment_method_id = pm.id
            AND {$whereClause}
        WHERE pm.business_id = {$businessId}
        AND pm.status = 1
        GROUP BY pm.id, pm.method_name
        ORDER BY total_amount DESC, pm.method_name ASC
    ");
}

/* -------------------------------------------------------
   CUSTOMER SUMMARY - SAME FILTERS
------------------------------------------------------- */
$customerSummary = [];
if (tableExists($conn, 'customers')) {
    $customerSummary = fetchAllAssoc($conn, "
        SELECT
            c.id,
            c.full_name,
            c.mobile,
            COUNT(p.id) AS payment_count,
            COALESCE(SUM(p.amount), 0) AS total_amount,
            COALESCE(AVG(p.amount), 0) AS avg_amount,
            COALESCE(MAX(p.amount), 0) AS max_amount
        FROM customers c
        INNER JOIN payments p ON p.customer_id = c.id
        WHERE {$whereClause}
        GROUP BY c.id, c.full_name, c.mobile
        ORDER BY total_amount DESC
        LIMIT 50
    ");
}

/* -------------------------------------------------------
   DAILY BREAKDOWN
------------------------------------------------------- */
$dailyBreakdown = fetchAllAssoc($conn, "
    SELECT
        DATE(p.payment_date) AS payment_date,
        COUNT(*) AS payment_count,
        COALESCE(SUM(p.amount), 0) AS total_amount
    FROM payments p
    WHERE {$whereClause}
    GROUP BY DATE(p.payment_date)
    ORDER BY payment_date DESC
");

/* -------------------------------------------------------
   DETAILED PAYMENTS
------------------------------------------------------- */
$payments = fetchAllAssoc($conn, "
    SELECT
        p.*,
        c.full_name AS customer_name,
        c.mobile AS customer_mobile,
        pm.method_name,
        b.branch_name,
        b.branch_code,
        u.full_name AS created_by_name,
        si.invoice_no AS sales_invoice_no,
        srv.invoice_no AS service_invoice_no
    FROM payments p
    LEFT JOIN customers c ON c.id = p.customer_id
    LEFT JOIN payment_methods pm ON pm.id = p.payment_method_id
    LEFT JOIN branches b ON b.id = p.branch_id
    LEFT JOIN business_users u ON u.id = p.created_by
    LEFT JOIN sales_invoices si ON si.id = p.ref_id AND p.payment_for = 'sales'
    LEFT JOIN service_invoices srv ON srv.id = p.ref_id AND p.payment_for = 'service'
    WHERE {$whereClause}
    ORDER BY p.payment_date DESC, p.id DESC
");

/* -------------------------------------------------------
   MONTHLY TREND - SAME YEAR + BUSINESS + BRANCH
------------------------------------------------------- */
$currentYear = (int)date('Y', strtotime($startDate));
$monthlyTrend = [];
for ($i = 1; $i <= 12; $i++) {
    $monthlyTrend[$i] = 0.00;
}

$monthlyWhere = ["p.business_id = {$businessId}", "YEAR(p.payment_date) = {$currentYear}"];

if ($branchFilter > 0) {
    $monthlyWhere[] = "p.branch_id = {$branchFilter}";
}
if ($paymentFor !== '') {
    $safe = $conn->real_escape_string($paymentFor);
    $monthlyWhere[] = "p.payment_for = '{$safe}'";
}
if ($methodFilter > 0) {
    $monthlyWhere[] = "p.payment_method_id = {$methodFilter}";
}
if ($customerFilter > 0) {
    $monthlyWhere[] = "p.customer_id = {$customerFilter}";
}

$monthlyWhereSql = implode(' AND ', $monthlyWhere);

$trendRows = fetchAllAssoc($conn, "
    SELECT
        MONTH(p.payment_date) AS month_num,
        COALESCE(SUM(p.amount), 0) AS total_amount
    FROM payments p
    WHERE {$monthlyWhereSql}
    GROUP BY MONTH(p.payment_date)
    ORDER BY month_num ASC
");

foreach ($trendRows as $row) {
    $monthlyTrend[(int)$row['month_num']] = (float)$row['total_amount'];
}

/* -------------------------------------------------------
   REFERENCE BREAKDOWN
------------------------------------------------------- */
$referenceBreakdown = fetchAllAssoc($conn, "
    SELECT
        p.payment_for,
        p.ref_id,
        COUNT(*) AS payment_count,
        COALESCE(SUM(p.amount), 0) AS total_amount
    FROM payments p
    WHERE {$whereClause}
    GROUP BY p.payment_for, p.ref_id
    ORDER BY total_amount DESC
    LIMIT 20
");

/* -------------------------------------------------------
   EXPORT CSV
------------------------------------------------------- */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="payment_report_' . $startDate . '_to_' . $endDate . '.csv"');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    fputcsv($output, ['Payment Report']);
    fputcsv($output, ['Date Range', $startDate . ' to ' . $endDate]);
    fputcsv($output, []);

    fputcsv($output, [
        'S.No',
        'Date',
        'Reference',
        'Customer',
        'Mobile',
        'Payment For',
        'Method',
        'Amount',
        'Transaction No',
        'Reference No',
        'Branch',
        'Created By',
        'Notes'
    ]);

    $i = 1;
    foreach ($payments as $payment) {
        if ($payment['payment_for'] === 'sales') {
            $refText = !empty($payment['sales_invoice_no']) ? $payment['sales_invoice_no'] : 'Sales #' . $payment['ref_id'];
        } elseif ($payment['payment_for'] === 'service') {
            $refText = !empty($payment['service_invoice_no']) ? $payment['service_invoice_no'] : 'Service #' . $payment['ref_id'];
        } else {
            $refText = 'Other #' . $payment['ref_id'];
        }

        fputcsv($output, [
            $i++,
            date('d-m-Y H:i', strtotime($payment['payment_date'])),
            $refText,
            $payment['customer_name'] ?: 'Walk-in Customer',
            $payment['customer_mobile'] ?: '',
            ucfirst($payment['payment_for']),
            $payment['method_name'] ?: '-',
            number_format((float)$payment['amount'], 2, '.', ''),
            $payment['transaction_no'] ?: '',
            $payment['reference_no'] ?: '',
            $payment['branch_name'] ?: '-',
            $payment['created_by_name'] ?: '-',
            $payment['notes'] ?: ''
        ]);
    }

    fputcsv($output, []);
    fputcsv($output, ['SUMMARY']);
    fputcsv($output, ['Total Payments', $totalPayments]);
    fputcsv($output, ['Total Amount', number_format($totalAmount, 2, '.', '')]);
    fputcsv($output, ['Average Payment', number_format($averagePayment, 2, '.', '')]);
    fputcsv($output, ['Minimum Payment', number_format($minPayment, 2, '.', '')]);
    fputcsv($output, ['Maximum Payment', number_format($maxPayment, 2, '.', '')]);

    fclose($output);
    exit;
}

/* -------------------------------------------------------
   PAGE VARIABLES
------------------------------------------------------- */
$pageTitle = 'Payment Report';
$currentPage = 'payment-reports';

$monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

$queryParams = $_GET;
$queryParams['export'] = 'csv';
$exportUrl = 'payment-report.php?' . http_build_query($queryParams);

$methodChartLabels = [];
$methodChartData = [];

foreach ($methodBreakdown as $m) {
    if ((int)$m['payment_count'] > 0) {
        $methodChartLabels[] = $m['method_name'];
        $methodChartData[] = (float)$m['total_amount'];
    }
}
?>
<!doctype html>
<html lang="en">

<?php include('includes/head.php'); ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

<style>
    .page-content {
        padding-bottom: 90px !important;
        overflow-x: hidden;
        width: 100%;
    }
    .card {
        margin-bottom: 24px;
        width: 100%;
    }
    .main-content {
        min-height: calc(100vh - 70px);
        overflow-x: hidden;
    }
    .container-fluid {
        max-width: 100%;
        overflow-x: hidden;
    }
    .summary-card {
        transition: all 0.3s ease;
    }
    .summary-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 10px 20px rgba(0,0,0,0.1);
    }
    .table-responsive {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        width: 100%;
    }
    .table {
        min-width: 850px;
    }
    .badge {
        font-size: 0.75rem;
        padding: 0.25rem 0.5rem;
        white-space: nowrap;
    }
    .export-btn {
        position: fixed;
        bottom: 30px;
        right: 30px;
        z-index: 100;
        box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        width: 50px;
        height: 50px;
        display: none;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        padding: 0;
    }
    .export-btn:hover {
        transform: scale(1.05);
    }
    .avatar-sm {
        width: 2.5rem;
        height: 2.5rem;
        flex-shrink: 0;
    }
    .font-size-20 {
        font-size: 1.25rem;
    }
    .payment-positive {
        color: #28a745;
        font-weight: 600;
    }
    .chart-container {
        position: relative;
        height: 300px;
        width: 100%;
    }
    .btn-group {
        flex-wrap: wrap;
        gap: 5px;
    }
    .btn-group .btn {
        border-radius: 0.25rem !important;
        margin: 2px;
    }
</style>

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

                <!-- Page Title -->
                <div class="row">
                    <div class="col-12">
                        <div class="page-title-box d-flex align-items-center justify-content-between">
                            <h4 class="mb-0">Payment Report</h4>
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
                                    <li class="breadcrumb-item"><a href="payments.php">Payments</a></li>
                                    <li class="breadcrumb-item active">Payment Report</li>
                                </ol>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Welcome Card -->
                <div class="row">
                    <div class="col-12">
                        <div class="card bg-primary text-white">
                            <div class="card-body">
                                <div class="d-flex flex-wrap align-items-center justify-content-between">
                                    <div>
                                        <h4 class="text-white mb-1">Payment Analysis Report</h4>
                                        <p class="mb-0 opacity-75">
                                            Analyze payment collections by branch, method, customer, and date range.
                                        </p>
                                    </div>
                                    <div class="text-end">
                                        <span class="badge bg-light text-dark p-2">
                                            <i class="ri-calendar-line me-1"></i> <?php echo date('d M Y'); ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filter Section -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="card-title mb-4">Filter Reports</h5>

                                <form method="get" action="payment-report.php" class="row g-3" id="filterForm">
                                    <div class="col-md-2">
                                        <label class="form-label">Start Date</label>
                                        <input type="date" name="start_date" class="form-control" value="<?php echo h($startDate); ?>" required>
                                    </div>

                                    <div class="col-md-2">
                                        <label class="form-label">End Date</label>
                                        <input type="date" name="end_date" class="form-control" value="<?php echo h($endDate); ?>" required>
                                    </div>

                                    <div class="col-md-2">
                                        <label class="form-label">Branch</label>
                                        <select name="branch_id" class="form-select">
                                            <option value="0">All Branches</option>
                                            <?php foreach ($branches as $branch): ?>
                                                <option value="<?php echo (int)$branch['id']; ?>" <?php echo $branchFilter === (int)$branch['id'] ? 'selected' : ''; ?>>
                                                    <?php echo h($branch['branch_name'] . ' (' . $branch['branch_code'] . ')'); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="col-md-2">
                                        <label class="form-label">Payment For</label>
                                        <select name="payment_for" class="form-select">
                                            <option value="">All Types</option>
                                            <option value="sales" <?php echo $paymentFor === 'sales' ? 'selected' : ''; ?>>Sales</option>
                                            <option value="service" <?php echo $paymentFor === 'service' ? 'selected' : ''; ?>>Service</option>
                                            <option value="other" <?php echo $paymentFor === 'other' ? 'selected' : ''; ?>>Other</option>
                                        </select>
                                    </div>

                                    <div class="col-md-2">
                                        <label class="form-label">Payment Method</label>
                                        <select name="method_id" class="form-select">
                                            <option value="0">All Methods</option>
                                            <?php foreach ($paymentMethods as $method): ?>
                                                <option value="<?php echo (int)$method['id']; ?>" <?php echo $methodFilter === (int)$method['id'] ? 'selected' : ''; ?>>
                                                    <?php echo h($method['method_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="col-md-2">
                                        <label class="form-label">Customer</label>
                                        <select name="customer_id" class="form-select">
                                            <option value="0">All Customers</option>
                                            <?php foreach ($customers as $customer): ?>
                                                <option value="<?php echo (int)$customer['id']; ?>" <?php echo $customerFilter === (int)$customer['id'] ? 'selected' : ''; ?>>
                                                    <?php echo h($customer['full_name']); ?> <?php echo !empty($customer['mobile']) ? '(' . h($customer['mobile']) . ')' : ''; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="col-md-12 d-flex gap-2 flex-wrap">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="ri-filter-3-line me-1"></i> Generate Report
                                        </button>
                                        <a href="payment-report.php" class="btn btn-light">Reset</a>
                                        <a href="<?php echo h($exportUrl); ?>" class="btn btn-success">
                                            <i class="ri-download-2-line me-1"></i> Export CSV
                                        </a>
                                    </div>
                                </form>

                                <div class="row mt-3">
                                    <div class="col-12">
                                        <div class="btn-group flex-wrap" role="group">
                                            <a href="?start_date=<?php echo date('Y-m-01'); ?>&end_date=<?php echo date('Y-m-d'); ?>" class="btn btn-sm btn-outline-primary">This Month</a>
                                            <a href="?start_date=<?php echo date('Y-m-01', strtotime('-1 month')); ?>&end_date=<?php echo date('Y-m-t', strtotime('-1 month')); ?>" class="btn btn-sm btn-outline-primary">Last Month</a>
                                            <a href="?start_date=<?php echo date('Y-01-01'); ?>&end_date=<?php echo date('Y-m-d'); ?>" class="btn btn-sm btn-outline-primary">Year to Date</a>
                                            <a href="?start_date=<?php echo date('Y-m-d', strtotime('-7 days')); ?>&end_date=<?php echo date('Y-m-d'); ?>" class="btn btn-sm btn-outline-primary">Last 7 Days</a>
                                            <a href="?start_date=<?php echo date('Y-m-d', strtotime('-30 days')); ?>&end_date=<?php echo date('Y-m-d'); ?>" class="btn btn-sm btn-outline-primary">Last 30 Days</a>
                                            <a href="?start_date=<?php echo date('Y-01-01'); ?>&end_date=<?php echo date('Y-12-31'); ?>" class="btn btn-sm btn-outline-primary">Full Year</a>
                                        </div>
                                    </div>
                                </div>

                            </div>
                        </div>
                    </div>
                </div>

                <!-- Summary Cards -->
                <div class="row">
                    <div class="col-xl-3 col-md-6">
                        <div class="card summary-card">
                            <div class="card-body">
                                <p class="text-muted mb-1">Total Payments</p>
                                <h4><?php echo number_format($totalPayments); ?></h4>
                                <span class="text-info small">Transactions</span>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6">
                        <div class="card summary-card">
                            <div class="card-body">
                                <p class="text-muted mb-1">Total Amount</p>
                                <h4 class="payment-positive"><?php echo money($totalAmount); ?></h4>
                                <span class="text-success small">Total collections</span>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6">
                        <div class="card summary-card">
                            <div class="card-body">
                                <p class="text-muted mb-1">Average Payment</p>
                                <h4><?php echo money($averagePayment); ?></h4>
                                <span class="text-warning small">Per transaction</span>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6">
                        <div class="card summary-card">
                            <div class="card-body">
                                <p class="text-muted mb-1">Min - Max</p>
                                <h5><?php echo money($minPayment); ?> - <?php echo money($maxPayment); ?></h5>
                                <span class="text-info small">Range</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Charts Row -->
                <div class="row">
                    <div class="col-xl-8">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="card-title mb-4">Monthly Payment Trend - <?php echo $currentYear; ?></h5>
                                <div class="chart-container">
                                    <canvas id="monthlyPaymentChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-4">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="card-title mb-4">Payment by Method</h5>
                                <div class="chart-container" style="height:250px;">
                                    <canvas id="paymentMethodChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Breakdown Tables -->
                <div class="row">
                    <div class="col-xl-6">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="card-title mb-4">Payment Type Breakdown</h5>
                                <div class="table-responsive">
                                    <table class="table table-bordered table-striped align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th>Payment For</th>
                                                <th>No. of Payments</th>
                                                <th>Total Amount</th>
                                                <th>Average</th>
                                                <th>% of Total</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($paymentForBreakdown)): ?>
                                                <?php foreach ($paymentForBreakdown as $type): ?>
                                                    <?php
                                                    $percentage = $totalAmount > 0 ? ((float)$type['total_amount'] / $totalAmount) * 100 : 0;
                                                    $badge = $type['payment_for'] === 'sales' ? 'primary' : ($type['payment_for'] === 'service' ? 'success' : 'secondary');
                                                    ?>
                                                    <tr>
                                                        <td><span class="badge bg-<?php echo $badge; ?>"><?php echo h(ucfirst($type['payment_for'])); ?></span></td>
                                                        <td><?php echo number_format((int)$type['payment_count']); ?></td>
                                                        <td class="payment-positive"><?php echo money($type['total_amount']); ?></td>
                                                        <td><?php echo money($type['avg_amount']); ?></td>
                                                        <td><?php echo number_format($percentage, 1); ?>%</td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr><td colspan="5" class="text-center text-muted py-4">No payment type data available</td></tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-6">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="card-title mb-4">Payment Method Summary</h5>
                                <div class="table-responsive">
                                    <table class="table table-bordered table-striped align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th>Method</th>
                                                <th>Payments</th>
                                                <th>Total Amount</th>
                                                <th>Average</th>
                                                <th>% of Total</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($methodBreakdown)): ?>
                                                <?php foreach ($methodBreakdown as $method): ?>
                                                    <?php $percentage = $totalAmount > 0 ? ((float)$method['total_amount'] / $totalAmount) * 100 : 0; ?>
                                                    <tr>
                                                        <td><strong><?php echo h($method['method_name']); ?></strong></td>
                                                        <td><?php echo number_format((int)$method['payment_count']); ?></td>
                                                        <td class="payment-positive"><?php echo money($method['total_amount']); ?></td>
                                                        <td><?php echo money($method['avg_amount']); ?></td>
                                                        <td><?php echo number_format($percentage, 1); ?>%</td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr><td colspan="5" class="text-center text-muted py-4">No payment method data available</td></tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Customer Summary -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="card-title mb-4">Top Customers by Payment</h5>
                                <div class="table-responsive">
                                    <table class="table table-bordered table-striped align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th>#</th>
                                                <th>Customer</th>
                                                <th>Mobile</th>
                                                <th>No. of Payments</th>
                                                <th>Total Amount</th>
                                                <th>Average</th>
                                                <th>Max Payment</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($customerSummary)): ?>
                                                <?php $i = 1; foreach ($customerSummary as $customer): ?>
                                                    <tr>
                                                        <td><?php echo $i++; ?></td>
                                                        <td><strong><?php echo h($customer['full_name']); ?></strong></td>
                                                        <td><?php echo h($customer['mobile']); ?></td>
                                                        <td><?php echo number_format((int)$customer['payment_count']); ?></td>
                                                        <td class="payment-positive"><?php echo money($customer['total_amount']); ?></td>
                                                        <td><?php echo money($customer['avg_amount']); ?></td>
                                                        <td><?php echo money($customer['max_amount']); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr><td colspan="7" class="text-center text-muted py-4">No customer payment data available</td></tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Daily Breakdown -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="card-title mb-4">Daily Payment Breakdown</h5>
                                <div class="table-responsive">
                                    <table class="table table-bordered table-striped align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th>Date</th>
                                                <th>No. of Payments</th>
                                                <th>Total Amount</th>
                                                <th>% of Period Total</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($dailyBreakdown)): ?>
                                                <?php foreach ($dailyBreakdown as $day): ?>
                                                    <?php $percentage = $totalAmount > 0 ? ((float)$day['total_amount'] / $totalAmount) * 100 : 0; ?>
                                                    <tr>
                                                        <td><strong><?php echo date('d M Y', strtotime($day['payment_date'])); ?></strong></td>
                                                        <td><?php echo number_format((int)$day['payment_count']); ?></td>
                                                        <td class="payment-positive"><?php echo money($day['total_amount']); ?></td>
                                                        <td><?php echo number_format($percentage, 1); ?>%</td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr><td colspan="4" class="text-center text-muted py-4">No daily data available</td></tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Detailed Payments -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex align-items-center justify-content-between mb-4">
                                    <h5 class="card-title mb-0">Detailed Payment List</h5>
                                    <a href="<?php echo h($exportUrl); ?>" class="btn btn-success btn-sm">
                                        <i class="ri-download-2-line me-1"></i> Export CSV
                                    </a>
                                </div>

                                <div class="table-responsive">
                                    <table class="table table-bordered table-striped align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th>#</th>
                                                <th>Date</th>
                                                <th>Reference</th>
                                                <th>Customer</th>
                                                <th>Type</th>
                                                <th>Method</th>
                                                <th>Amount</th>
                                                <th>Transaction No</th>
                                                <th>Branch</th>
                                                <th>Created By</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($payments)): ?>
                                                <?php $i = 1; foreach ($payments as $payment): ?>
                                                    <?php
                                                    if ($payment['payment_for'] === 'sales') {
                                                        $refText = !empty($payment['sales_invoice_no']) ? $payment['sales_invoice_no'] : 'Sales #' . $payment['ref_id'];
                                                        $badge = 'primary';
                                                    } elseif ($payment['payment_for'] === 'service') {
                                                        $refText = !empty($payment['service_invoice_no']) ? $payment['service_invoice_no'] : 'Service #' . $payment['ref_id'];
                                                        $badge = 'success';
                                                    } else {
                                                        $refText = 'Other #' . $payment['ref_id'];
                                                        $badge = 'secondary';
                                                    }
                                                    ?>
                                                    <tr>
                                                        <td><?php echo $i++; ?></td>
                                                        <td><?php echo date('d M Y h:i A', strtotime($payment['payment_date'])); ?></td>
                                                        <td><?php echo h($refText); ?></td>
                                                        <td>
                                                            <?php echo h($payment['customer_name'] ?: 'Walk-in Customer'); ?>
                                                            <?php if (!empty($payment['customer_mobile'])): ?>
                                                                <br><small class="text-muted"><?php echo h($payment['customer_mobile']); ?></small>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td><span class="badge bg-<?php echo $badge; ?>"><?php echo h(ucfirst($payment['payment_for'])); ?></span></td>
                                                        <td><?php echo h($payment['method_name'] ?: '-'); ?></td>
                                                        <td class="payment-positive"><?php echo money($payment['amount']); ?></td>
                                                        <td><?php echo h($payment['transaction_no'] ?: '-'); ?></td>
                                                        <td><?php echo h($payment['branch_name'] ?: '-'); ?></td>
                                                        <td><?php echo h($payment['created_by_name'] ?: '-'); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="10" class="text-center text-muted py-4">
                                                        No payments found for the selected period.
                                                    </td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <?php if (!empty($payments)): ?>
                                    <div class="row mt-4">
                                        <div class="col-md-6">
                                            <p class="text-muted mb-0">
                                                Showing <?php echo count($payments); ?> payments from <?php echo date('d M Y', strtotime($startDate)); ?> to <?php echo date('d M Y', strtotime($endDate)); ?>
                                            </p>
                                        </div>
                                        <div class="col-md-6 text-md-end">
                                            <p class="mb-0">
                                                <strong>Total:</strong> <?php echo money($totalAmount); ?> |
                                                <strong>Average:</strong> <?php echo money($averagePayment); ?>
                                            </p>
                                        </div>
                                    </div>
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

<a href="<?php echo h($exportUrl); ?>" class="btn btn-success export-btn" title="Export to CSV">
    <i class="ri-download-2-line"></i>
</a>

<?php include('includes/rightbar.php'); ?>
<?php include('includes/scripts.php'); ?>

<script>
window.addEventListener('scroll', function() {
    var exportBtn = document.querySelector('.export-btn');
    if (exportBtn) {
        exportBtn.style.display = window.scrollY > 300 ? 'flex' : 'none';
    }
});

document.addEventListener('DOMContentLoaded', function() {
    var exportBtn = document.querySelector('.export-btn');
    if (exportBtn) exportBtn.style.display = 'none';

    const monthlyCtx = document.getElementById('monthlyPaymentChart').getContext('2d');
    new Chart(monthlyCtx, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($monthNames); ?>,
            datasets: [{
                label: 'Payments',
                data: <?php echo json_encode(array_values($monthlyTrend)); ?>,
                backgroundColor: 'rgba(40, 167, 69, 0.2)',
                borderColor: 'rgba(40, 167, 69, 1)',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return '₹' + value.toLocaleString();
                        }
                    }
                }
            },
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return 'Payments: ₹' + Number(context.raw).toLocaleString();
                        }
                    }
                }
            }
        }
    });

    const methodLabels = <?php echo json_encode($methodChartLabels); ?>;
    const methodData = <?php echo json_encode($methodChartData); ?>;

    if (methodLabels.length > 0) {
        const methodCtx = document.getElementById('paymentMethodChart').getContext('2d');
        new Chart(methodCtx, {
            type: 'doughnut',
            data: {
                labels: methodLabels,
                datasets: [{
                    data: methodData,
                    backgroundColor: [
                        'rgba(40, 167, 69, 0.8)',
                        'rgba(13, 110, 253, 0.8)',
                        'rgba(255, 193, 7, 0.8)',
                        'rgba(220, 53, 69, 0.8)',
                        'rgba(111, 66, 193, 0.8)',
                        'rgba(23, 162, 184, 0.8)',
                        'rgba(108, 117, 125, 0.8)',
                        'rgba(253, 126, 20, 0.8)'
                    ],
                    borderWidth: 1,
                    borderColor: '#ffffff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                cutout: '60%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            boxWidth: 12,
                            padding: 10,
                            font: { size: 10 }
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let value = Number(context.raw || 0);
                                let total = context.dataset.data.reduce((a, b) => Number(a) + Number(b), 0);
                                let percentage = total > 0 ? ((value / total) * 100).toFixed(1) : '0.0';
                                return context.label + ': ₹' + value.toLocaleString() + ' (' + percentage + '%)';
                            }
                        }
                    }
                }
            }
        });
    }
});
</script>

</body>
</html>