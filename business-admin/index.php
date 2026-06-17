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

function formatDisplayDate($dateValue): string
{
    if (empty($dateValue) || $dateValue == '0000-00-00' || $dateValue == '0000-00-00 00:00:00') {
        return '-';
    }

    $timestamp = strtotime($dateValue);
    if (!$timestamp || $timestamp <= 0) {
        return '-';
    }

    return date('d M Y', $timestamp);
}

function tableExists(mysqli $conn, string $table): bool
{
    $sql = "SELECT 1 
            FROM information_schema.TABLES 
            WHERE TABLE_SCHEMA = DATABASE() 
              AND TABLE_NAME = ? 
            LIMIT 1";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param("s", $table);
    $stmt->execute();

    $exists = $stmt->get_result()->num_rows > 0;

    $stmt->close();

    return $exists;
}

function getSingleRow(mysqli $conn, string $sql): array
{
    $res = $conn->query($sql);
    if (!$res) {
        return [];
    }

    $row = $res->fetch_assoc();
    $res->free();

    return $row ?: [];
}

function getSingleValue(mysqli $conn, string $sql, string $key = 'total'): float
{
    $row = getSingleRow($conn, $sql);
    return (float)($row[$key] ?? 0);
}

/* -------------------------------------------------------
   DATE VALUES
------------------------------------------------------- */
$today = date('Y-m-d');
$currentMonthStart = date('Y-m-01');
$currentMonthEnd = date('Y-m-t');

/* -------------------------------------------------------
   FETCH LOGGED-IN USER
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
        WHERE bu.id = ?
          AND bu.business_id = ?
        LIMIT 1
    ");

    if ($stmt) {
        $stmt->bind_param("ii", $businessUserId, $businessId);
        $stmt->execute();
        $admin = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}

if (!$admin || (int)$admin['status'] !== 1 || ($admin['business_status'] ?? '') !== 'active') {
    session_destroy();
    header("Location: login.php");
    exit;
}

/* -------------------------------------------------------
   SUPER ADMIN / BRANCH ACCESS
------------------------------------------------------- */
$userRole = (string)($admin['role'] ?? '');
$isSuperAdmin = ($userRole === 'super_admin');

$userBranchId = isset($admin['branch_id']) ? (int)$admin['branch_id'] : 0;

/*
   Super Admin:
   - Can see all branches
   - Can filter by any branch
   - Default selected branch is All Branches

   Normal User:
   - Locked to assigned branch
*/
if ($isSuperAdmin) {
    $selectedBranchId = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : 0;

    if ($selectedBranchId < 0) {
        $selectedBranchId = 0;
    }

    $_SESSION['branch_id'] = $selectedBranchId;
} else {
    $selectedBranchId = $userBranchId > 0 ? $userBranchId : $sessionBranchId;

    if ($selectedBranchId <= 0) {
        $selectedBranchId = $sessionBranchId;
    }

    $_SESSION['branch_id'] = $selectedBranchId;
}

/* -------------------------------------------------------
   BRANCH FILTERS
------------------------------------------------------- */
$branchFilter = "";
$branchFilterSi = "";

if ($selectedBranchId > 0) {
    $branchFilter = " AND branch_id = {$selectedBranchId} ";
    $branchFilterSi = " AND si.branch_id = {$selectedBranchId} ";
}

/* -------------------------------------------------------
   BRANCHES
------------------------------------------------------- */
$branches = [];

if (tableExists($conn, 'branches')) {
    if ($isSuperAdmin) {
        $branchSql = "
            SELECT id, branch_name, branch_code, status
            FROM branches
            WHERE business_id = {$businessId}
            ORDER BY branch_name ASC
        ";
    } else {
        $safeUserBranchId = (int)$selectedBranchId;

        $branchSql = "
            SELECT id, branch_name, branch_code, status
            FROM branches
            WHERE business_id = {$businessId}
              AND id = {$safeUserBranchId}
              AND status = 'active'
            ORDER BY branch_name ASC
        ";
    }

    $result = $conn->query($branchSql);

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $branches[] = $row;
        }
        $result->free();
    }
}

$selectedBranchName = '';
$selectedBranchCode = '';

if ($selectedBranchId > 0) {
    foreach ($branches as $branch) {
        if ((int)$branch['id'] === $selectedBranchId) {
            $selectedBranchName = $branch['branch_name'];
            $selectedBranchCode = $branch['branch_code'];
            break;
        }
    }
}

/* -------------------------------------------------------
   SALES INVOICE CALCULATIONS
------------------------------------------------------- */
$totalSalesInvoices = 0;
$totalSalesAmount = 0;
$totalPaidFromInvoices = 0;
$totalBalanceFromInvoices = 0;

$paidInvoices = 0;
$partialInvoices = 0;
$unpaidInvoices = 0;

$monthSalesCount = 0;
$monthSalesAmount = 0;
$todaySalesCount = 0;
$todaySalesAmount = 0;

if (tableExists($conn, 'sales_invoices')) {
    $salesSummary = getSingleRow($conn, "
        SELECT
            COUNT(*) AS total_invoices,
            COALESCE(SUM(grand_total), 0) AS total_sales,
            COALESCE(SUM(paid_amount), 0) AS total_paid,
            COALESCE(SUM(GREATEST(grand_total - paid_amount, 0)), 0) AS total_balance,

            COALESCE(SUM(CASE 
                WHEN grand_total > 0 AND paid_amount >= grand_total THEN 1 
                ELSE 0 
            END), 0) AS paid_count,

            COALESCE(SUM(CASE 
                WHEN paid_amount > 0 AND paid_amount < grand_total THEN 1 
                ELSE 0 
            END), 0) AS partial_count,

            COALESCE(SUM(CASE 
                WHEN paid_amount <= 0 OR paid_amount IS NULL THEN 1 
                ELSE 0 
            END), 0) AS unpaid_count
        FROM sales_invoices
        WHERE business_id = {$businessId} {$branchFilter}
    ");

    $totalSalesInvoices = (int)($salesSummary['total_invoices'] ?? 0);
    $totalSalesAmount = (float)($salesSummary['total_sales'] ?? 0);
    $totalPaidFromInvoices = (float)($salesSummary['total_paid'] ?? 0);
    $totalBalanceFromInvoices = (float)($salesSummary['total_balance'] ?? 0);

    $paidInvoices = (int)($salesSummary['paid_count'] ?? 0);
    $partialInvoices = (int)($salesSummary['partial_count'] ?? 0);
    $unpaidInvoices = (int)($salesSummary['unpaid_count'] ?? 0);

    $monthSales = getSingleRow($conn, "
        SELECT 
            COUNT(*) AS total,
            COALESCE(SUM(grand_total), 0) AS amount
        FROM sales_invoices
        WHERE business_id = {$businessId}
        {$branchFilter}
        AND DATE(invoice_date) BETWEEN '{$currentMonthStart}' AND '{$currentMonthEnd}'
    ");

    $monthSalesCount = (int)($monthSales['total'] ?? 0);
    $monthSalesAmount = (float)($monthSales['amount'] ?? 0);

    $todaySales = getSingleRow($conn, "
        SELECT 
            COUNT(*) AS total,
            COALESCE(SUM(grand_total), 0) AS amount
        FROM sales_invoices
        WHERE business_id = {$businessId}
        {$branchFilter}
        AND DATE(invoice_date) = '{$today}'
    ");

    $todaySalesCount = (int)($todaySales['total'] ?? 0);
    $todaySalesAmount = (float)($todaySales['amount'] ?? 0);
}

/* -------------------------------------------------------
   PAYMENTS RECEIVED
------------------------------------------------------- */
$totalPaymentsReceived = 0;
$paymentsCount = 0;
$monthPayments = 0;
$todayPayments = 0;

if (tableExists($conn, 'payments')) {
    $paySummary = getSingleRow($conn, "
        SELECT 
            COUNT(*) AS count,
            COALESCE(SUM(amount), 0) AS total
        FROM payments
        WHERE business_id = {$businessId} {$branchFilter}
    ");

    $paymentsCount = (int)($paySummary['count'] ?? 0);
    $totalPaymentsReceived = (float)($paySummary['total'] ?? 0);

    $monthPayments = getSingleValue($conn, "
        SELECT COALESCE(SUM(amount), 0) AS total
        FROM payments
        WHERE business_id = {$businessId}
        {$branchFilter}
        AND DATE(payment_date) BETWEEN '{$currentMonthStart}' AND '{$currentMonthEnd}'
    ");

    $todayPayments = getSingleValue($conn, "
        SELECT COALESCE(SUM(amount), 0) AS total
        FROM payments
        WHERE business_id = {$businessId}
        {$branchFilter}
        AND DATE(payment_date) = '{$today}'
    ");
}

$dashboardCollections = $totalPaymentsReceived > 0 ? $totalPaymentsReceived : $totalPaidFromInvoices;

if ($paymentsCount <= 0) {
    $paymentsCount = $paidInvoices + $partialInvoices;
}

if ($monthPayments <= 0 && tableExists($conn, 'sales_invoices')) {
    $monthPayments = getSingleValue($conn, "
        SELECT COALESCE(SUM(paid_amount), 0) AS total
        FROM sales_invoices
        WHERE business_id = {$businessId}
        {$branchFilter}
        AND DATE(invoice_date) BETWEEN '{$currentMonthStart}' AND '{$currentMonthEnd}'
    ");
}

if ($todayPayments <= 0 && tableExists($conn, 'sales_invoices')) {
    $todayPayments = getSingleValue($conn, "
        SELECT COALESCE(SUM(paid_amount), 0) AS total
        FROM sales_invoices
        WHERE business_id = {$businessId}
        {$branchFilter}
        AND DATE(invoice_date) = '{$today}'
    ");
}

/* -------------------------------------------------------
   EXPENSES
------------------------------------------------------- */
$totalExpenses = 0;
$expensesCount = 0;
$monthExpenses = 0;
$todayExpenses = 0;

if (tableExists($conn, 'expenses')) {
    $expenseSummary = getSingleRow($conn, "
        SELECT 
            COUNT(*) AS count,
            COALESCE(SUM(amount), 0) AS total
        FROM expenses
        WHERE business_id = {$businessId} {$branchFilter}
    ");

    $expensesCount = (int)($expenseSummary['count'] ?? 0);
    $totalExpenses = (float)($expenseSummary['total'] ?? 0);

    $monthExpenses = getSingleValue($conn, "
        SELECT COALESCE(SUM(amount), 0) AS total
        FROM expenses
        WHERE business_id = {$businessId}
        {$branchFilter}
        AND DATE(expense_date) BETWEEN '{$currentMonthStart}' AND '{$currentMonthEnd}'
    ");

    $todayExpenses = getSingleValue($conn, "
        SELECT COALESCE(SUM(amount), 0) AS total
        FROM expenses
        WHERE business_id = {$businessId}
        {$branchFilter}
        AND DATE(expense_date) = '{$today}'
    ");
}

/* -------------------------------------------------------
   SERVICE INVOICES
------------------------------------------------------- */
$totalServiceInvoices = 0;
$totalServiceAmount = 0;
$monthServiceCount = 0;
$monthServiceAmount = 0;
$todayServiceCount = 0;
$todayServiceAmount = 0;

if (tableExists($conn, 'service_invoices')) {
    $serviceSummary = getSingleRow($conn, "
        SELECT 
            COUNT(*) AS total,
            COALESCE(SUM(grand_total), 0) AS amount
        FROM service_invoices
        WHERE business_id = {$businessId} {$branchFilter}
    ");

    $totalServiceInvoices = (int)($serviceSummary['total'] ?? 0);
    $totalServiceAmount = (float)($serviceSummary['amount'] ?? 0);

    $monthService = getSingleRow($conn, "
        SELECT 
            COUNT(*) AS total,
            COALESCE(SUM(grand_total), 0) AS amount
        FROM service_invoices
        WHERE business_id = {$businessId}
        {$branchFilter}
        AND DATE(invoice_date) BETWEEN '{$currentMonthStart}' AND '{$currentMonthEnd}'
    ");

    $monthServiceCount = (int)($monthService['total'] ?? 0);
    $monthServiceAmount = (float)($monthService['amount'] ?? 0);

    $todayService = getSingleRow($conn, "
        SELECT 
            COUNT(*) AS total,
            COALESCE(SUM(grand_total), 0) AS amount
        FROM service_invoices
        WHERE business_id = {$businessId}
        {$branchFilter}
        AND DATE(invoice_date) = '{$today}'
    ");

    $todayServiceCount = (int)($todayService['total'] ?? 0);
    $todayServiceAmount = (float)($todayService['amount'] ?? 0);
}

/* -------------------------------------------------------
   BUSINESS SUMMARY
------------------------------------------------------- */
$totalBranches = 0;
$activeBranches = 0;

if (tableExists($conn, 'branches')) {
    $totalBranches = (int)getSingleValue($conn, "SELECT COUNT(*) AS total FROM branches WHERE business_id = {$businessId}");
    $activeBranches = (int)getSingleValue($conn, "SELECT COUNT(*) AS total FROM branches WHERE business_id = {$businessId} AND status = 'active'");
}

$totalBusinessUsers = 0;
$activeBusinessUsers = 0;

if (tableExists($conn, 'business_users')) {
    $totalBusinessUsers = (int)getSingleValue($conn, "SELECT COUNT(*) AS total FROM business_users WHERE business_id = {$businessId}");
    $activeBusinessUsers = (int)getSingleValue($conn, "SELECT COUNT(*) AS total FROM business_users WHERE business_id = {$businessId} AND status = 1");
}

$totalCustomers = 0;

if (tableExists($conn, 'customers')) {
    if ($selectedBranchId > 0) {
        $totalCustomers = (int)getSingleValue($conn, "
            SELECT COUNT(*) AS total 
            FROM customers 
            WHERE business_id = {$businessId}
              AND (branch_id = {$selectedBranchId} OR branch_id IS NULL)
        ");
    } else {
        $totalCustomers = (int)getSingleValue($conn, "
            SELECT COUNT(*) AS total 
            FROM customers 
            WHERE business_id = {$businessId}
        ");
    }
}

$totalProducts = 0;
$lowStockProducts = 0;

if (tableExists($conn, 'products')) {
    $totalProducts = (int)getSingleValue($conn, "
        SELECT COUNT(*) AS total 
        FROM products 
        WHERE business_id = {$businessId}
    ");

    $lowStockProducts = (int)getSingleValue($conn, "
        SELECT COUNT(*) AS total 
        FROM products 
        WHERE business_id = {$businessId}
          AND stock_qty <= min_stock_qty
          AND stock_qty > 0
    ");
}

$totalVehiclesInStock = 0;

if (tableExists($conn, 'vehicle_stock')) {
    $totalVehiclesInStock = (int)getSingleValue($conn, "
        SELECT COUNT(*) AS total 
        FROM vehicle_stock 
        WHERE business_id = {$businessId}
        {$branchFilter}
        AND stock_status IN ('in_stock', 'reserved', 'demo')
    ");
}

$openJobCards = 0;

if (tableExists($conn, 'service_job_cards')) {
    $openJobCards = (int)getSingleValue($conn, "
        SELECT COUNT(*) AS total 
        FROM service_job_cards 
        WHERE business_id = {$businessId}
        {$branchFilter}
        AND job_status IN ('open','in_progress','waiting_parts','ready')
    ");
}

/* -------------------------------------------------------
   FINAL CALCULATED METRICS
------------------------------------------------------- */
$netProfitLoss = $dashboardCollections - $totalExpenses;
$collectionRate = $totalSalesAmount > 0 ? ($totalPaidFromInvoices / $totalSalesAmount) * 100 : 0;
$avgInvoiceValue = $totalSalesInvoices > 0 ? $totalSalesAmount / $totalSalesInvoices : 0;
$monthProfit = $monthPayments - $monthExpenses;
$todayNet = $todayPayments - $todayExpenses;

$pageTitle = 'Business Dashboard';
$currentPage = 'dashboard';
?>
<!doctype html>
<html lang="en">

<?php include('includes/head.php'); ?>

<body data-sidebar="dark">

<style>
    .quick-action-card {
        transition: all 0.2s ease;
        cursor: pointer;
        text-decoration: none;
        color: inherit;
        display: block;
    }

    .quick-action-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 8px 20px rgba(0,0,0,0.1);
        border-color: #3b82f6 !important;
    }

    .service-action-card {
        background: linear-gradient(180deg, #fff7ed 0%, #ffffff 100%);
        border-color: #f6ad22 !important;
    }

    .service-action-card:hover {
        border-color: #f59e0b !important;
        box-shadow: 0 10px 24px rgba(246,173,34,0.22);
    }

    .table-centered td,
    .table-centered th {
        vertical-align: middle;
    }

    .badge-paid {
        background-color: #198754;
    }

    .badge-partial {
        background-color: #ffc107;
        color: #000;
    }

    .badge-unpaid {
        background-color: #dc3545;
    }

    .status-card-paid {
        border-left: 4px solid #198754;
    }

    .status-card-partial {
        border-left: 4px solid #ffc107;
    }

    .status-card-unpaid {
        border-left: 4px solid #dc3545;
    }

    .branch-filter-card {
        border-left: 4px solid #0d6efd;
    }

    .super-admin-badge {
        background: #ecfdf5;
        color: #047857;
        border: 1px solid #a7f3d0;
        padding: 4px 9px;
        border-radius: 999px;
        font-size: 12px;
        font-weight: 600;
    }
</style>

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

                <!-- QUICK ACTIONS -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <div class="row g-3">

                                    <div class="col-6 col-md-3 col-xl-2">
                                        <a href="sales-invoice-add.php" style="text-decoration:none;color:inherit;">
                                            <div class="border rounded p-3 text-center quick-action-card">
                                                <div class="mb-2"><span style="font-size:28px;">💰</span></div>
                                                <h6 class="mb-1">New Sale</h6>
                                                <small class="text-muted">Create invoice</small>
                                            </div>
                                        </a>
                                    </div>

                                    <div class="col-6 col-md-3 col-xl-2">
                                        <a href="service-invoice-add.php" style="text-decoration:none;color:inherit;">
                                            <div class="border rounded p-3 text-center quick-action-card service-action-card">
                                                <div class="mb-2"><span style="font-size:28px;">🛠️</span></div>
                                                <h6 class="mb-1">Service Invoice</h6>
                                                <small class="text-muted">Create service bill</small>
                                            </div>
                                        </a>
                                    </div>

                                    <div class="col-6 col-md-3 col-xl-2">
                                        <a href="quotations.php" style="text-decoration:none;color:inherit;">
                                            <div class="border rounded p-3 text-center quick-action-card">
                                                <div class="mb-2"><span style="font-size:28px;">📄</span></div>
                                                <h6 class="mb-1">Quotation</h6>
                                                <small class="text-muted">Create quote</small>
                                            </div>
                                        </a>
                                    </div>

                                    <div class="col-6 col-md-3 col-xl-2">
                                        <a href="product-add.php" style="text-decoration:none;color:inherit;">
                                            <div class="border rounded p-3 text-center quick-action-card">
                                                <div class="mb-2"><span style="font-size:28px;">🛍️</span></div>
                                                <h6 class="mb-1">Add Product</h6>
                                                <small class="text-muted">New item</small>
                                            </div>
                                        </a>
                                    </div>

                                    <div class="col-6 col-md-3 col-xl-2">
                                        <a href="customers.php" style="text-decoration:none;color:inherit;">
                                            <div class="border rounded p-3 text-center quick-action-card">
                                                <div class="mb-2"><span style="font-size:28px;">👤</span></div>
                                                <h6 class="mb-1">Add Customer</h6>
                                                <small class="text-muted">New customer</small>
                                            </div>
                                        </a>
                                    </div>

                                    <div class="col-6 col-md-3 col-xl-2">
                                        <a href="vehicle-stock.php" style="text-decoration:none;color:inherit;">
                                            <div class="border rounded p-3 text-center quick-action-card">
                                                <div class="mb-2"><span style="font-size:28px;">🏍️</span></div>
                                                <h6 class="mb-1">Add Stock</h6>
                                                <small class="text-muted">Vehicle stock</small>
                                            </div>
                                        </a>
                                    </div>

                                    <div class="col-6 col-md-3 col-xl-2">
                                        <a href="sales-invoices.php" style="text-decoration:none;color:inherit;">
                                            <div class="border rounded p-3 text-center quick-action-card">
                                                <div class="mb-2"><span style="font-size:28px;">📋</span></div>
                                                <h6 class="mb-1">Sales Invoices</h6>
                                                <small class="text-muted">View all</small>
                                            </div>
                                        </a>
                                    </div>

                                </div>
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
                                        <h4 class="text-white mb-1">
                                            Welcome, <?php echo h($admin['full_name'] ?? 'Business Admin'); ?>
                                        </h4>
                                        <p class="mb-0 opacity-75">
                                            <?php echo h($admin['business_name'] ?? ''); ?>

                                            <?php if (!empty($admin['role'])): ?>
                                                | Role: <?php echo h(ucwords(str_replace('_', ' ', $admin['role']))); ?>
                                            <?php endif; ?>

                                            <?php if ($isSuperAdmin): ?>
                                                <span class="super-admin-badge ms-2">All Branch Access</span>
                                            <?php endif; ?>
                                        </p>
                                    </div>

                                    <div class="text-end">
                                        <div class="small">Today</div>
                                        <div class="fw-bold"><?php echo date('d M Y, h:i A'); ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Branch Filter -->
                <div class="row">
                    <div class="col-12">
                        <div class="card branch-filter-card">
                            <div class="card-body">
                                <form method="get" class="row g-3 align-items-end">

                                    <div class="col-md-4">
                                        <label class="form-label fw-bold">Select Branch</label>

                                        <?php if ($isSuperAdmin): ?>
                                            <select name="branch_id" class="form-select" onchange="this.form.submit()">
                                                <option value="0" <?php echo $selectedBranchId == 0 ? 'selected' : ''; ?>>
                                                    -- All Branches --
                                                </option>

                                                <?php foreach ($branches as $branch): ?>
                                                    <?php
                                                    $branchId = (int)$branch['id'];
                                                    $branchStatus = (string)($branch['status'] ?? '');
                                                    $branchLabel = $branch['branch_name'] . ' (' . $branch['branch_code'] . ')';

                                                    if ($branchStatus !== 'active') {
                                                        $branchLabel .= ' - Inactive';
                                                    }
                                                    ?>
                                                    <option value="<?php echo $branchId; ?>" <?php echo $selectedBranchId == $branchId ? 'selected' : ''; ?>>
                                                        <?php echo h($branchLabel); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>

                                            <div class="small text-muted mt-1">
                                                Super Admin can view all branches or filter any branch.
                                            </div>
                                        <?php else: ?>
                                            <select name="branch_id" class="form-select" disabled>
                                                <?php if (!empty($branches)): ?>
                                                    <?php foreach ($branches as $branch): ?>
                                                        <option value="<?php echo (int)$branch['id']; ?>" selected>
                                                            <?php echo h($branch['branch_name'] . ' (' . $branch['branch_code'] . ')'); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <option value="">No branch assigned</option>
                                                <?php endif; ?>
                                            </select>

                                            <div class="small text-muted mt-1">
                                                Your login is restricted to assigned branch.
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="col-md-8">
                                        <?php if ($selectedBranchId > 0 && !empty($selectedBranchName)): ?>
                                            <div class="alert alert-info mb-0">
                                                <i class="ri-information-line me-2"></i>
                                                Showing data for branch:
                                                <strong><?php echo h($selectedBranchName); ?></strong>
                                                (<?php echo h($selectedBranchCode); ?>)
                                            </div>
                                        <?php else: ?>
                                            <div class="alert alert-secondary mb-0">
                                                <i class="ri-information-line me-2"></i>
                                                Showing data for <strong>All Branches</strong>.
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Stats Row 1 -->
                <div class="row">
                    <div class="col-md-6 col-xl-3">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <p class="text-muted mb-1">Total Sales Value</p>
                                        <h3 class="mb-0"><?php echo money($totalSalesAmount); ?></h3>
                                        <small class="text-muted"><?php echo number_format($totalSalesInvoices); ?> invoices</small>
                                    </div>
                                    <div class="avatar-sm">
                                        <div class="avatar-title bg-primary-subtle text-primary rounded-circle">
                                            <i class="ri-shopping-cart-line fs-4"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <p class="text-muted mb-1">Paid from Invoices</p>
                                        <h3 class="mb-0 text-success"><?php echo money($totalPaidFromInvoices); ?></h3>
                                        <small class="text-muted">From paid_amount</small>
                                    </div>
                                    <div class="avatar-sm">
                                        <div class="avatar-title bg-success-subtle text-success rounded-circle">
                                            <i class="ri-check-line fs-4"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <p class="text-muted mb-1">Balance Due</p>
                                        <h3 class="mb-0 text-danger"><?php echo money($totalBalanceFromInvoices); ?></h3>
                                        <small class="text-muted">Grand Total - Paid</small>
                                    </div>
                                    <div class="avatar-sm">
                                        <div class="avatar-title bg-danger-subtle text-danger rounded-circle">
                                            <i class="ri-wallet-line fs-4"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <p class="text-muted mb-1">Collection Rate</p>
                                        <h3 class="mb-0"><?php echo number_format($collectionRate, 1); ?>%</h3>
                                        <small class="text-muted">Paid / Total Sales</small>
                                    </div>
                                    <div class="avatar-sm">
                                        <div class="avatar-title bg-info-subtle text-info rounded-circle">
                                            <i class="ri-percent-line fs-4"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Stats Row 2 -->
                <div class="row">
                    <div class="col-md-4 col-xl-4">
                        <div class="card">
                            <div class="card-body">
                                <p class="text-muted mb-1">Actual Collections</p>
                                <h4 class="mb-0 text-success"><?php echo money($dashboardCollections); ?></h4>
                                <small><?php echo number_format($paymentsCount); ?> payment records / paid invoices</small>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4 col-xl-4">
                        <div class="card">
                            <div class="card-body">
                                <p class="text-muted mb-1">Total Expenses</p>
                                <h4 class="mb-0 text-danger"><?php echo money($totalExpenses); ?></h4>
                                <small><?php echo number_format($expensesCount); ?> expenses</small>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4 col-xl-4">
                        <div class="card">
                            <div class="card-body">
                                <p class="text-muted mb-1">Net Profit/Loss</p>
                                <h4 class="mb-0 <?php echo $netProfitLoss >= 0 ? 'text-success' : 'text-danger'; ?>">
                                    <?php echo money($netProfitLoss); ?>
                                </h4>
                                <small>Collections - Expenses</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Invoice Status Cards -->
                <div class="row">
                    <div class="col-md-4">
                        <div class="card status-card-paid">
                            <div class="card-body text-center">
                                <div class="mb-2">
                                    <i class="ri-checkbox-circle-line fs-1 text-success"></i>
                                </div>
                                <p class="text-muted mb-1">Paid Invoices</p>
                                <h3 class="mb-0 text-success"><?php echo number_format($paidInvoices); ?></h3>
                                <small class="text-muted">paid_amount >= grand_total</small>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="card status-card-partial">
                            <div class="card-body text-center">
                                <div class="mb-2">
                                    <i class="ri-information-line fs-1 text-warning"></i>
                                </div>
                                <p class="text-muted mb-1">Partial Invoices</p>
                                <h3 class="mb-0 text-warning"><?php echo number_format($partialInvoices); ?></h3>
                                <small class="text-muted">0 &lt; paid_amount &lt; grand_total</small>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="card status-card-unpaid">
                            <div class="card-body text-center">
                                <div class="mb-2">
                                    <i class="ri-error-warning-line fs-1 text-danger"></i>
                                </div>
                                <p class="text-muted mb-1">Unpaid Invoices</p>
                                <h3 class="mb-0 text-danger"><?php echo number_format($unpaidInvoices); ?></h3>
                                <small class="text-muted">paid_amount is zero</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Month / Today Overview -->
                <div class="row">
                    <div class="col-xl-6">
                        <div class="card">
                            <div class="card-header bg-light">
                                <h5 class="card-title mb-0">
                                    <i class="ri-calendar-line me-2"></i>Current Month Overview (<?php echo date('F Y'); ?>)
                                </h5>
                            </div>

                            <div class="card-body">
                                <div class="row text-center">
                                    <div class="col-4">
                                        <div class="p-3 border rounded">
                                            <p class="text-muted mb-1">Sales</p>
                                            <h4 class="mb-0"><?php echo number_format($monthSalesCount); ?></h4>
                                            <small><?php echo money($monthSalesAmount); ?></small>
                                        </div>
                                    </div>

                                    <div class="col-4">
                                        <div class="p-3 border rounded">
                                            <p class="text-muted mb-1">Service</p>
                                            <h4 class="mb-0"><?php echo number_format($monthServiceCount); ?></h4>
                                            <small><?php echo money($monthServiceAmount); ?></small>
                                        </div>
                                    </div>

                                    <div class="col-4">
                                        <div class="p-3 border rounded">
                                            <p class="text-muted mb-1">Total Revenue</p>
                                            <h4 class="mb-0 text-primary">
                                                <?php echo money($monthSalesAmount + $monthServiceAmount); ?>
                                            </h4>
                                            <small>Sales + Service</small>
                                        </div>
                                    </div>
                                </div>

                                <div class="row mt-4 text-center">
                                    <div class="col-4">
                                        <div class="p-3 border rounded">
                                            <p class="text-muted mb-1">Collections</p>
                                            <h4 class="mb-0 text-success"><?php echo money($monthPayments); ?></h4>
                                            <small>Paid amount / payments</small>
                                        </div>
                                    </div>

                                    <div class="col-4">
                                        <div class="p-3 border rounded">
                                            <p class="text-muted mb-1">Expenses</p>
                                            <h4 class="mb-0 text-danger"><?php echo money($monthExpenses); ?></h4>
                                            <small>This month</small>
                                        </div>
                                    </div>

                                    <div class="col-4">
                                        <div class="p-3 border rounded">
                                            <p class="text-muted mb-1">Profit</p>
                                            <h4 class="mb-0 <?php echo $monthProfit >= 0 ? 'text-success' : 'text-danger'; ?>">
                                                <?php echo money($monthProfit); ?>
                                            </h4>
                                            <small>Collections - Expenses</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-6">
                        <div class="card">
                            <div class="card-header bg-light">
                                <h5 class="card-title mb-0">
                                    <i class="ri-today-line me-2"></i>Today's Overview (<?php echo date('d M Y'); ?>)
                                </h5>
                            </div>

                            <div class="card-body">
                                <div class="row text-center">
                                    <div class="col-4">
                                        <div class="p-3 border rounded">
                                            <p class="text-muted mb-1">Sales Invoices</p>
                                            <h3 class="mb-0"><?php echo number_format($todaySalesCount); ?></h3>
                                            <small><?php echo money($todaySalesAmount); ?></small>
                                        </div>
                                    </div>

                                    <div class="col-4">
                                        <div class="p-3 border rounded">
                                            <p class="text-muted mb-1">Service Invoices</p>
                                            <h3 class="mb-0"><?php echo number_format($todayServiceCount); ?></h3>
                                            <small><?php echo money($todayServiceAmount); ?></small>
                                        </div>
                                    </div>

                                    <div class="col-4">
                                        <div class="p-3 border rounded">
                                            <p class="text-muted mb-1">Payments Today</p>
                                            <h3 class="mb-0 text-success"><?php echo money($todayPayments); ?></h3>
                                        </div>
                                    </div>
                                </div>

                                <div class="row mt-4">
                                    <div class="col-12">
                                        <div class="alert alert-warning mb-0">
                                            <strong>Today's Expenses:</strong> <?php echo money($todayExpenses); ?>
                                            <br>
                                            <strong>Today's Net:</strong>
                                            <span class="<?php echo $todayNet >= 0 ? 'text-success' : 'text-danger'; ?>">
                                                <?php echo money($todayNet); ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>

                <!-- Business Summary -->
                <div class="row">
                    <div class="col-md-6 col-xl-3">
                        <div class="card">
                            <div class="card-body">
                                <p class="text-muted mb-1">Total Branches</p>
                                <h4 class="mb-0"><?php echo number_format($totalBranches); ?></h4>
                                <small>Active: <?php echo number_format($activeBranches); ?></small>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="card">
                            <div class="card-body">
                                <p class="text-muted mb-1">Total Customers</p>
                                <h4 class="mb-0"><?php echo number_format($totalCustomers); ?></h4>
                                <small><?php echo $selectedBranchId > 0 ? 'Selected branch' : 'All branches'; ?></small>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="card">
                            <div class="card-body">
                                <p class="text-muted mb-1">Total Products</p>
                                <h4 class="mb-0"><?php echo number_format($totalProducts); ?></h4>
                                <small>Low stock: <?php echo number_format($lowStockProducts); ?></small>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="card">
                            <div class="card-body">
                                <p class="text-muted mb-1">Vehicles In Stock</p>
                                <h4 class="mb-0"><?php echo number_format($totalVehiclesInStock); ?></h4>
                                <small><?php echo $selectedBranchId > 0 ? 'Selected branch' : 'All branches'; ?></small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Latest Invoices -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header bg-light">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h5 class="card-title mb-0">
                                        <i class="ri-receipt-line me-2"></i>Latest Sales Invoices
                                    </h5>
                                    <a href="sales-invoices.php" class="btn btn-primary btn-sm">View All</a>
                                </div>
                            </div>

                            <div class="card-body">
                                <?php
                                $latestInvoicesQuery = null;

                                if (tableExists($conn, 'sales_invoices')) {
                                    $latestInvoicesQuery = $conn->query("
                                        SELECT 
                                            si.id,
                                            si.invoice_no,
                                            si.invoice_date,
                                            si.grand_total,
                                            si.paid_amount,
                                            GREATEST(si.grand_total - si.paid_amount, 0) AS real_balance,
                                            CASE
                                                WHEN si.grand_total > 0 AND si.paid_amount >= si.grand_total THEN 'paid'
                                                WHEN si.paid_amount > 0 AND si.paid_amount < si.grand_total THEN 'partial'
                                                ELSE 'unpaid'
                                            END AS real_status,
                                            si.sale_status,
                                            si.invoice_type,
                                            c.full_name AS customer_name
                                        FROM sales_invoices si
                                        LEFT JOIN customers c ON c.id = si.customer_id
                                        WHERE si.business_id = {$businessId} {$branchFilterSi}
                                        ORDER BY si.id DESC
                                        LIMIT 10
                                    ");
                                }
                                ?>

                                <div class="table-responsive">
                                    <table class="table table-hover table-centered mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>#</th>
                                                <th>Invoice No</th>
                                                <th>Customer</th>
                                                <th>Date</th>
                                                <th>Type</th>
                                                <th>Total</th>
                                                <th>Paid</th>
                                                <th>Balance</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>

                                        <tbody>
                                            <?php if ($latestInvoicesQuery && $latestInvoicesQuery->num_rows > 0): ?>
                                                <?php $sno = 1; while ($inv = $latestInvoicesQuery->fetch_assoc()): ?>
                                                    <?php
                                                    $status = $inv['real_status'] ?? 'unpaid';

                                                    if ($status === 'paid') {
                                                        $statusText = 'Paid';
                                                        $badgeClass = 'success';
                                                    } elseif ($status === 'partial') {
                                                        $statusText = 'Partial';
                                                        $badgeClass = 'warning';
                                                    } else {
                                                        $statusText = 'Unpaid';
                                                        $badgeClass = 'danger';
                                                    }

                                                    if (($inv['invoice_type'] ?? '') === 'product_sale') {
                                                        $typeLabel = 'Product';
                                                        $typeIcon = '🛍️';
                                                    } elseif (($inv['invoice_type'] ?? '') === 'vehicle_sale') {
                                                        $typeLabel = 'Vehicle';
                                                        $typeIcon = '🏍️';
                                                    } elseif (($inv['invoice_type'] ?? '') === 'mixed_sale') {
                                                        $typeLabel = 'Mixed';
                                                        $typeIcon = '📦';
                                                    } else {
                                                        $typeLabel = '-';
                                                        $typeIcon = '📄';
                                                    }
                                                    ?>

                                                    <tr>
                                                        <td><?php echo $sno++; ?></td>

                                                        <td>
                                                            <a href="sales-invoice-view.php?id=<?php echo (int)$inv['id']; ?>" class="text-primary fw-bold">
                                                                <?php echo h($inv['invoice_no']); ?>
                                                            </a>
                                                        </td>

                                                        <td><?php echo h($inv['customer_name'] ?: '-'); ?></td>

                                                        <td><?php echo formatDisplayDate($inv['invoice_date']); ?></td>

                                                        <td>
                                                            <span class="badge bg-secondary">
                                                                <?php echo h($typeIcon . ' ' . $typeLabel); ?>
                                                            </span>
                                                        </td>

                                                        <td>
                                                            <strong><?php echo money($inv['grand_total']); ?></strong>
                                                        </td>

                                                        <td>
                                                            <span class="text-success">
                                                                <?php echo money($inv['paid_amount']); ?>
                                                            </span>
                                                        </td>

                                                        <td>
                                                            <span class="<?php echo ((float)$inv['real_balance'] > 0) ? 'text-danger' : 'text-success'; ?> fw-bold">
                                                                <?php echo money($inv['real_balance']); ?>
                                                            </span>
                                                        </td>

                                                        <td>
                                                            <span class="badge bg-<?php echo $badgeClass; ?>">
                                                                <?php echo h($statusText); ?>
                                                            </span>
                                                        </td>
                                                    </tr>
                                                <?php endwhile; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="9" class="text-center text-muted">
                                                        No sales invoices found.
                                                    </td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>

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

</body>
</html>