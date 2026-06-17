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
   REQUIRED TABLE
------------------------------------------------------- */
if (!tableExists($conn, 'expenses')) {
    die('expenses table not found.');
}

/* -------------------------------------------------------
   FILTERS
------------------------------------------------------- */
$startDate = trim($_GET['start_date'] ?? date('Y-m-01'));
$endDate = trim($_GET['end_date'] ?? date('Y-m-d'));
$branchFilter = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : 0;
$expenseTypeFilter = trim($_GET['expense_type'] ?? '');

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

/* -------------------------------------------------------
   MASTER DATA
------------------------------------------------------- */
$branches = [];
if (tableExists($conn, 'branches')) {
    $branches = fetchAllAssoc($conn, "
        SELECT id, branch_name, branch_code
        FROM branches
        WHERE business_id = {$businessId}
        AND status = 'active'
        ORDER BY branch_name ASC
    ");
}

$expenseTypes = fetchAllAssoc($conn, "
    SELECT DISTINCT expense_type
    FROM expenses
    WHERE business_id = {$businessId}
    AND expense_type IS NOT NULL
    AND expense_type != ''
    ORDER BY expense_type ASC
");

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

/* -------------------------------------------------------
   COMMON WHERE
------------------------------------------------------- */
$where = ["e.business_id = {$businessId}"];
$where[] = "DATE(e.expense_date) BETWEEN '{$conn->real_escape_string($startDate)}' AND '{$conn->real_escape_string($endDate)}'";

if ($branchFilter > 0) {
    $where[] = "e.branch_id = {$branchFilter}";
}

if ($expenseTypeFilter !== '') {
    $safeType = $conn->real_escape_string($expenseTypeFilter);
    $where[] = "e.expense_type = '{$safeType}'";
}

$whereClause = implode(' AND ', $where);

/* -------------------------------------------------------
   SUMMARY STATISTICS
------------------------------------------------------- */
$summary = fetchOne($conn, "
    SELECT 
        COUNT(*) AS expense_count,
        COALESCE(SUM(e.amount), 0) AS total_amount,
        COALESCE(AVG(e.amount), 0) AS avg_amount,
        COALESCE(MAX(e.amount), 0) AS max_amount,
        COALESCE(MIN(e.amount), 0) AS min_amount
    FROM expenses e
    WHERE {$whereClause}
");

$totalExpenses = (int)($summary['expense_count'] ?? 0);
$totalExpenseAmount = (float)($summary['total_amount'] ?? 0);
$averageExpense = (float)($summary['avg_amount'] ?? 0);
$maxExpense = (float)($summary['max_amount'] ?? 0);
$minExpense = (float)($summary['min_amount'] ?? 0);

/* -------------------------------------------------------
   EXPENSE TYPE BREAKDOWN
------------------------------------------------------- */
$typeBreakdown = fetchAllAssoc($conn, "
    SELECT 
        e.expense_type,
        COUNT(e.id) AS expense_count,
        COALESCE(SUM(e.amount), 0) AS total_amount,
        COALESCE(AVG(e.amount), 0) AS avg_amount,
        COALESCE(MIN(e.amount), 0) AS min_amount,
        COALESCE(MAX(e.amount), 0) AS max_amount
    FROM expenses e
    WHERE {$whereClause}
    GROUP BY e.expense_type
    ORDER BY total_amount DESC
");

/* -------------------------------------------------------
   BRANCH SUMMARY - SAME FILTERS
------------------------------------------------------- */
$branchJoinFilters = [
    "e.business_id = b.business_id",
    "DATE(e.expense_date) BETWEEN '{$conn->real_escape_string($startDate)}' AND '{$conn->real_escape_string($endDate)}'"
];

if ($expenseTypeFilter !== '') {
    $safeType = $conn->real_escape_string($expenseTypeFilter);
    $branchJoinFilters[] = "e.expense_type = '{$safeType}'";
}

$branchJoinSql = implode(' AND ', $branchJoinFilters);

$branchSummary = [];
if (tableExists($conn, 'branches')) {
    if ($branchFilter > 0) {
        $branchSummaryWhere = "b.business_id = {$businessId} AND b.id = {$branchFilter}";
    } else {
        $branchSummaryWhere = "b.business_id = {$businessId}";
    }

    $branchSummary = fetchAllAssoc($conn, "
        SELECT 
            b.id,
            b.branch_name,
            b.branch_code,
            COUNT(e.id) AS expense_count,
            COALESCE(SUM(e.amount), 0) AS total_amount,
            COALESCE(AVG(e.amount), 0) AS avg_amount,
            COALESCE(MIN(e.amount), 0) AS min_amount,
            COALESCE(MAX(e.amount), 0) AS max_amount
        FROM branches b
        LEFT JOIN expenses e ON e.branch_id = b.id AND {$branchJoinSql}
        WHERE {$branchSummaryWhere}
        GROUP BY b.id, b.branch_name, b.branch_code
        ORDER BY total_amount DESC, b.branch_name ASC
    ");
}

/* -------------------------------------------------------
   PAYMENT METHOD BREAKDOWN - SAME FILTERS
------------------------------------------------------- */
$methodBreakdown = [];
if (tableExists($conn, 'payment_methods')) {
    $methodBreakdown = fetchAllAssoc($conn, "
        SELECT 
            pm.id,
            pm.method_name,
            COUNT(e.id) AS expense_count,
            COALESCE(SUM(e.amount), 0) AS total_amount,
            COALESCE(AVG(e.amount), 0) AS avg_amount
        FROM payment_methods pm
        LEFT JOIN expenses e ON e.payment_method_id = pm.id AND {$whereClause}
        WHERE pm.business_id = {$businessId}
        AND pm.status = 1
        GROUP BY pm.id, pm.method_name
        ORDER BY total_amount DESC, pm.method_name ASC
    ");
}

/* -------------------------------------------------------
   DAILY BREAKDOWN
------------------------------------------------------- */
$dailyBreakdown = fetchAllAssoc($conn, "
    SELECT 
        DATE(e.expense_date) AS expense_date,
        COUNT(*) AS expense_count,
        COALESCE(SUM(e.amount), 0) AS total_amount
    FROM expenses e
    WHERE {$whereClause}
    GROUP BY DATE(e.expense_date)
    ORDER BY expense_date DESC
");

/* -------------------------------------------------------
   MONTHLY TREND - SAME YEAR + FILTERS
------------------------------------------------------- */
$currentYear = (int)date('Y', strtotime($startDate));
$monthlyTrend = [];
for ($i = 1; $i <= 12; $i++) {
    $monthlyTrend[$i] = 0.00;
}

$monthlyWhere = ["e.business_id = {$businessId}", "YEAR(e.expense_date) = {$currentYear}"];

if ($branchFilter > 0) {
    $monthlyWhere[] = "e.branch_id = {$branchFilter}";
}
if ($expenseTypeFilter !== '') {
    $safeType = $conn->real_escape_string($expenseTypeFilter);
    $monthlyWhere[] = "e.expense_type = '{$safeType}'";
}

$monthlyWhereSql = implode(' AND ', $monthlyWhere);

$trendRows = fetchAllAssoc($conn, "
    SELECT 
        MONTH(e.expense_date) AS month_num,
        COALESCE(SUM(e.amount), 0) AS total_amount
    FROM expenses e
    WHERE {$monthlyWhereSql}
    GROUP BY MONTH(e.expense_date)
    ORDER BY month_num ASC
");

foreach ($trendRows as $row) {
    $monthlyTrend[(int)$row['month_num']] = (float)$row['total_amount'];
}

/* -------------------------------------------------------
   DETAILED EXPENSE LIST
------------------------------------------------------- */
$expenses = fetchAllAssoc($conn, "
    SELECT 
        e.*,
        b.branch_name,
        b.branch_code,
        pm.method_name,
        u.full_name AS created_by_name
    FROM expenses e
    LEFT JOIN branches b ON b.id = e.branch_id
    LEFT JOIN payment_methods pm ON pm.id = e.payment_method_id
    LEFT JOIN business_users u ON u.id = e.created_by
    WHERE {$whereClause}
    ORDER BY e.expense_date DESC, e.id DESC
");

/* -------------------------------------------------------
   EXPORT CSV
------------------------------------------------------- */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="expense_report_' . $startDate . '_to_' . $endDate . '.csv"');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    fputcsv($output, ['Expense Report']);
    fputcsv($output, ['Date Range', $startDate . ' to ' . $endDate]);
    fputcsv($output, []);

    fputcsv($output, [
        'S.No',
        'Date',
        'Branch',
        'Branch Code',
        'Expense Type',
        'Description',
        'Amount',
        'Payment Method',
        'Paid To',
        'Bill No',
        'Created By'
    ]);

    $sno = 1;
    foreach ($expenses as $expense) {
        fputcsv($output, [
            $sno++,
            !empty($expense['expense_date']) ? date('d-m-Y', strtotime($expense['expense_date'])) : '',
            $expense['branch_name'] ?: '-',
            $expense['branch_code'] ?: '-',
            $expense['expense_type'] ?: '-',
            $expense['description'] ?: '',
            number_format((float)$expense['amount'], 2, '.', ''),
            $expense['method_name'] ?: '-',
            $expense['paid_to'] ?: '',
            $expense['bill_no'] ?: '',
            $expense['created_by_name'] ?: '-'
        ]);
    }

    fputcsv($output, []);
    fputcsv($output, ['SUMMARY']);
    fputcsv($output, ['Total Expenses', $totalExpenses]);
    fputcsv($output, ['Total Amount', number_format($totalExpenseAmount, 2, '.', '')]);
    fputcsv($output, ['Average Expense', number_format($averageExpense, 2, '.', '')]);
    fputcsv($output, ['Minimum Expense', number_format($minExpense, 2, '.', '')]);
    fputcsv($output, ['Maximum Expense', number_format($maxExpense, 2, '.', '')]);

    fclose($output);
    exit;
}

/* -------------------------------------------------------
   PAGE VARIABLES
------------------------------------------------------- */
$pageTitle = 'Expense Report';
$currentPage = 'expense-reports';

$monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

$queryParams = $_GET;
$queryParams['export'] = 'csv';
$exportUrl = 'expense-report.php?' . http_build_query($queryParams);

$typeChartLabels = [];
$typeChartData = [];

foreach ($typeBreakdown as $t) {
    if ((int)$t['expense_count'] > 0) {
        $typeChartLabels[] = $t['expense_type'];
        $typeChartData[] = (float)$t['total_amount'];
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
    .btn-group {
        flex-wrap: wrap;
        gap: 5px;
    }
    .btn-group .btn {
        border-radius: 0.25rem !important;
        margin: 2px;
    }
    .expense-positive {
        color: #dc3545;
        font-weight: 600;
    }
    .chart-container {
        position: relative;
        height: 300px;
        width: 100%;
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
                            <h4 class="mb-0">Expense Report</h4>
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
                                    <li class="breadcrumb-item"><a href="expenses.php">Expenses</a></li>
                                    <li class="breadcrumb-item active">Expense Report</li>
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
                                        <h4 class="text-white mb-1">Expense Analysis Report</h4>
                                        <p class="mb-0 opacity-75">
                                            Analyze expenses by type, branch, method, and date range.
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

                <!-- Filters -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="card-title mb-4">Filter Reports</h5>

                                <form method="get" action="expense-report.php" class="row g-3">
                                    <div class="col-md-3">
                                        <label class="form-label">Start Date</label>
                                        <input type="date" name="start_date" class="form-control" value="<?php echo h($startDate); ?>" required>
                                    </div>

                                    <div class="col-md-3">
                                        <label class="form-label">End Date</label>
                                        <input type="date" name="end_date" class="form-control" value="<?php echo h($endDate); ?>" required>
                                    </div>

                                    <div class="col-md-2">
                                        <label class="form-label">Branch</label>
                                        <select name="branch_id" class="form-select">
                                            <option value="0">All Branches</option>
                                            <?php foreach ($branches as $branch): ?>
                                                <option value="<?php echo (int)$branch['id']; ?>" <?php echo $branchFilter === (int)$branch['id'] ? 'selected' : ''; ?>>
                                                    <?php echo h($branch['branch_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="col-md-2">
                                        <label class="form-label">Expense Type</label>
                                        <select name="expense_type" class="form-select">
                                            <option value="">All Types</option>
                                            <?php foreach ($expenseTypes as $type): ?>
                                                <option value="<?php echo h($type['expense_type']); ?>" <?php echo $expenseTypeFilter === $type['expense_type'] ? 'selected' : ''; ?>>
                                                    <?php echo h($type['expense_type']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="col-md-2 d-flex align-items-end">
                                        <button type="submit" class="btn btn-primary w-100">
                                            <i class="ri-filter-3-line me-1"></i> Generate Report
                                        </button>
                                    </div>

                                    <div class="col-md-12 d-flex gap-2 flex-wrap">
                                        <a href="expense-report.php" class="btn btn-light">Reset</a>
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
                                <p class="text-muted mb-1">Total Expenses</p>
                                <h4><?php echo number_format($totalExpenses); ?></h4>
                                <span class="text-info small">Transactions</span>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6">
                        <div class="card summary-card">
                            <div class="card-body">
                                <p class="text-muted mb-1">Total Amount</p>
                                <h4 class="expense-positive"><?php echo money($totalExpenseAmount); ?></h4>
                                <span class="text-danger small">Total expenses</span>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6">
                        <div class="card summary-card">
                            <div class="card-body">
                                <p class="text-muted mb-1">Average Expense</p>
                                <h4><?php echo money($averageExpense); ?></h4>
                                <span class="text-warning small">Per transaction</span>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6">
                        <div class="card summary-card">
                            <div class="card-body">
                                <p class="text-muted mb-1">Min - Max</p>
                                <h5><?php echo money($minExpense); ?> - <?php echo money($maxExpense); ?></h5>
                                <span class="text-info small">Range</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Charts -->
                <div class="row">
                    <div class="col-xl-8">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="card-title mb-4">Monthly Expense Trend - <?php echo $currentYear; ?></h5>
                                <div class="chart-container">
                                    <canvas id="monthlyExpenseChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-4">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="card-title mb-4">Expense by Type</h5>
                                <div class="chart-container" style="height:250px;">
                                    <canvas id="expenseTypeChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Expense Type Breakdown -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="card-title mb-4">Expense Type Breakdown</h5>

                                <div class="table-responsive">
                                    <table class="table table-bordered table-striped align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th>#</th>
                                                <th>Expense Type</th>
                                                <th>No. of Transactions</th>
                                                <th>Total Amount</th>
                                                <th>Average</th>
                                                <th>Min</th>
                                                <th>Max</th>
                                                <th>% of Total</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($typeBreakdown)): ?>
                                                <?php $i = 1; foreach ($typeBreakdown as $type): ?>
                                                    <?php $percentage = $totalExpenseAmount > 0 ? ((float)$type['total_amount'] / $totalExpenseAmount) * 100 : 0; ?>
                                                    <tr>
                                                        <td><?php echo $i++; ?></td>
                                                        <td><strong><?php echo h($type['expense_type']); ?></strong></td>
                                                        <td><?php echo number_format((int)$type['expense_count']); ?></td>
                                                        <td class="expense-positive"><?php echo money($type['total_amount']); ?></td>
                                                        <td><?php echo money($type['avg_amount']); ?></td>
                                                        <td><?php echo money($type['min_amount']); ?></td>
                                                        <td><?php echo money($type['max_amount']); ?></td>
                                                        <td><?php echo number_format($percentage, 1); ?>%</td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="8" class="text-center text-muted py-4">No expense type data available</td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>

                            </div>
                        </div>
                    </div>
                </div>

                <!-- Branch Summary -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="card-title mb-4">Branch-wise Expense Summary</h5>

                                <div class="table-responsive">
                                    <table class="table table-bordered table-striped align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th>#</th>
                                                <th>Branch</th>
                                                <th>Code</th>
                                                <th>No. of Expenses</th>
                                                <th>Total Amount</th>
                                                <th>Average</th>
                                                <th>Min</th>
                                                <th>Max</th>
                                                <th>% of Total</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($branchSummary)): ?>
                                                <?php $i = 1; foreach ($branchSummary as $branch): ?>
                                                    <?php $percentage = $totalExpenseAmount > 0 ? ((float)$branch['total_amount'] / $totalExpenseAmount) * 100 : 0; ?>
                                                    <tr>
                                                        <td><?php echo $i++; ?></td>
                                                        <td><strong><?php echo h($branch['branch_name']); ?></strong></td>
                                                        <td><?php echo h($branch['branch_code']); ?></td>
                                                        <td><?php echo number_format((int)$branch['expense_count']); ?></td>
                                                        <td class="expense-positive"><?php echo money($branch['total_amount']); ?></td>
                                                        <td><?php echo money($branch['avg_amount']); ?></td>
                                                        <td><?php echo money($branch['min_amount']); ?></td>
                                                        <td><?php echo money($branch['max_amount']); ?></td>
                                                        <td><?php echo number_format($percentage, 1); ?>%</td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="9" class="text-center text-muted py-4">No branch data available</td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>

                            </div>
                        </div>
                    </div>
                </div>

                <!-- Payment Method Breakdown -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="card-title mb-4">Payment Method Breakdown</h5>

                                <div class="table-responsive">
                                    <table class="table table-bordered table-striped align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th>#</th>
                                                <th>Payment Method</th>
                                                <th>No. of Transactions</th>
                                                <th>Total Amount</th>
                                                <th>Average</th>
                                                <th>% of Total</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($methodBreakdown)): ?>
                                                <?php $i = 1; foreach ($methodBreakdown as $method): ?>
                                                    <?php
                                                    if ((int)$method['expense_count'] <= 0) {
                                                        continue;
                                                    }
                                                    $percentage = $totalExpenseAmount > 0 ? ((float)$method['total_amount'] / $totalExpenseAmount) * 100 : 0;
                                                    ?>
                                                    <tr>
                                                        <td><?php echo $i++; ?></td>
                                                        <td><strong><?php echo h($method['method_name']); ?></strong></td>
                                                        <td><?php echo number_format((int)$method['expense_count']); ?></td>
                                                        <td class="expense-positive"><?php echo money($method['total_amount']); ?></td>
                                                        <td><?php echo money($method['avg_amount']); ?></td>
                                                        <td><?php echo number_format($percentage, 1); ?>%</td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="6" class="text-center text-muted py-4">No payment method data available</td>
                                                </tr>
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
                                <h5 class="card-title mb-4">Daily Expense Breakdown</h5>

                                <div class="table-responsive">
                                    <table class="table table-bordered table-striped align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th>Date</th>
                                                <th>No. of Expenses</th>
                                                <th>Total Amount</th>
                                                <th>% of Period Total</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($dailyBreakdown)): ?>
                                                <?php foreach ($dailyBreakdown as $day): ?>
                                                    <?php $percentage = $totalExpenseAmount > 0 ? ((float)$day['total_amount'] / $totalExpenseAmount) * 100 : 0; ?>
                                                    <tr>
                                                        <td><strong><?php echo date('d M Y', strtotime($day['expense_date'])); ?></strong></td>
                                                        <td><?php echo number_format((int)$day['expense_count']); ?></td>
                                                        <td class="expense-positive"><?php echo money($day['total_amount']); ?></td>
                                                        <td><?php echo number_format($percentage, 1); ?>%</td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="4" class="text-center text-muted py-4">No daily data available</td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>

                            </div>
                        </div>
                    </div>
                </div>

                <!-- Detailed Expenses -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">

                                <div class="d-flex align-items-center justify-content-between mb-4">
                                    <h5 class="card-title mb-0">Detailed Expense List</h5>
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
                                                <th>Branch</th>
                                                <th>Expense Type</th>
                                                <th>Description</th>
                                                <th>Amount</th>
                                                <th>Payment Method</th>
                                                <th>Paid To</th>
                                                <th>Bill No</th>
                                                <th>Created By</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($expenses)): ?>
                                                <?php $i = 1; foreach ($expenses as $expense): ?>
                                                    <tr>
                                                        <td><?php echo $i++; ?></td>
                                                        <td><?php echo !empty($expense['expense_date']) ? date('d M Y', strtotime($expense['expense_date'])) : '-'; ?></td>
                                                        <td>
                                                            <?php echo h($expense['branch_name'] ?: '-'); ?>
                                                            <?php if (!empty($expense['branch_code'])): ?>
                                                                <br><small class="text-muted"><?php echo h($expense['branch_code']); ?></small>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <span class="badge bg-primary"><?php echo h($expense['expense_type'] ?: '-'); ?></span>
                                                        </td>
                                                        <td><?php echo h($expense['description'] ?: '-'); ?></td>
                                                        <td class="expense-positive"><?php echo money($expense['amount']); ?></td>
                                                        <td><?php echo h($expense['method_name'] ?: '-'); ?></td>
                                                        <td><?php echo h($expense['paid_to'] ?: '-'); ?></td>
                                                        <td><?php echo h($expense['bill_no'] ?: '-'); ?></td>
                                                        <td><?php echo h($expense['created_by_name'] ?: '-'); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="10" class="text-center text-muted py-4">
                                                        No expenses found for the selected period.
                                                    </td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <?php if (!empty($expenses)): ?>
                                    <div class="row mt-4">
                                        <div class="col-md-6">
                                            <p class="text-muted mb-0">
                                                Showing <?php echo count($expenses); ?> expenses from <?php echo date('d M Y', strtotime($startDate)); ?> to <?php echo date('d M Y', strtotime($endDate)); ?>
                                            </p>
                                        </div>
                                        <div class="col-md-6 text-md-end">
                                            <p class="mb-0">
                                                <strong>Total:</strong> <?php echo money($totalExpenseAmount); ?> |
                                                <strong>Average:</strong> <?php echo money($averageExpense); ?>
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
    if (exportBtn) {
        exportBtn.style.display = 'none';
    }

    const monthlyCtx = document.getElementById('monthlyExpenseChart').getContext('2d');

    new Chart(monthlyCtx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode($monthNames); ?>,
            datasets: [{
                label: 'Expenses',
                data: <?php echo json_encode(array_values($monthlyTrend)); ?>,
                fill: true,
                backgroundColor: 'rgba(220, 53, 69, 0.1)',
                borderColor: 'rgba(220, 53, 69, 1)',
                tension: 0.4,
                pointBackgroundColor: 'rgba(220, 53, 69, 1)',
                pointBorderColor: '#fff',
                pointRadius: 5,
                pointHoverRadius: 7
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
                            return '₹' + Number(value).toLocaleString();
                        }
                    }
                }
            },
            plugins: {
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return 'Expenses: ₹' + Number(context.raw).toLocaleString();
                        }
                    }
                },
                legend: {
                    display: false
                }
            }
        }
    });

    const typeLabels = <?php echo json_encode($typeChartLabels); ?>;
    const typeData = <?php echo json_encode($typeChartData); ?>;

    if (typeLabels.length > 0) {
        const typeCtx = document.getElementById('expenseTypeChart').getContext('2d');

        new Chart(typeCtx, {
            type: 'doughnut',
            data: {
                labels: typeLabels,
                datasets: [{
                    data: typeData,
                    backgroundColor: [
                        'rgba(220, 53, 69, 0.8)',
                        'rgba(255, 193, 7, 0.8)',
                        'rgba(13, 110, 253, 0.8)',
                        'rgba(25, 135, 84, 0.8)',
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
                            font: {
                                size: 10
                            }
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