<?php
session_start();
date_default_timezone_set('Asia/Kolkata');

require_once 'includes/config.php';

/*
|--------------------------------------------------------------------------
| Business Admin Sales Dashboard - sales-dashboard.php
|--------------------------------------------------------------------------
| This page displays sales analytics and summaries including:
| - Today's sales, monthly sales, yearly performance
| - Payment collections, pending payments
| - Top selling products, recent invoices
| - Sales charts and graphs
|--------------------------------------------------------------------------
*/

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
$branchId   = isset($_SESSION['branch_id']) ? (int)$_SESSION['branch_id'] : 0;

if ($businessUserId <= 0 || $businessId <= 0) {
    header("Location: login.php");
    exit;
}

/* -------------------------------------------------------
   HELPERS
------------------------------------------------------- */
function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
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
    if (!$stmt) return false;

    $stmt->bind_param("s", $table);
    $stmt->execute();
    $res = $stmt->get_result();
    $exists = $res && $res->num_rows > 0;
    $stmt->close();

    return $exists;
}

function getCount(mysqli $conn, string $table, string $where = '1=1'): int
{
    $sql = "SELECT COUNT(*) AS total FROM {$table} WHERE {$where}";
    $res = $conn->query($sql);
    if (!$res) return 0;
    $row = $res->fetch_assoc();
    return (int)($row['total'] ?? 0);
}

function getSum(mysqli $conn, string $table, string $field, string $where = '1=1'): float
{
    $sql = "SELECT COALESCE(SUM({$field}), 0) AS total FROM {$table} WHERE {$where}";
    $res = $conn->query($sql);
    if (!$res) return 0;
    $row = $res->fetch_assoc();
    return (float)($row['total'] ?? 0);
}

/* -------------------------------------------------------
   FETCH LOGGED-IN BUSINESS USER
------------------------------------------------------- */
$admin = null;
if (tableExists($conn, 'business_users') && tableExists($conn, 'businesses')) {
    $sql = "SELECT 
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
            LIMIT 1";

    $stmt = $conn->prepare($sql);
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
   DATE RANGES
------------------------------------------------------- */
$today = date('Y-m-d');
$currentMonthStart = date('Y-m-01');
$currentMonthEnd = date('Y-m-t');
$currentYear = date('Y');
$lastMonthStart = date('Y-m-01', strtotime('-1 month'));
$lastMonthEnd = date('Y-m-t', strtotime('-1 month'));

/* -------------------------------------------------------
   SALES OVERVIEW STATISTICS
------------------------------------------------------- */
// Today's sales
$todaySalesCount = tableExists($conn, 'sales_invoices')
    ? getCount($conn, 'sales_invoices', "business_id = {$businessId} AND DATE(invoice_date) = '{$today}'")
    : 0;

$todaySalesAmount = tableExists($conn, 'sales_invoices')
    ? getSum($conn, 'sales_invoices', 'grand_total', "business_id = {$businessId} AND DATE(invoice_date) = '{$today}'")
    : 0;

$todayCollections = tableExists($conn, 'payments')
    ? getSum($conn, 'payments', 'amount', "business_id = {$businessId} AND payment_for = 'sales' AND DATE(payment_date) = '{$today}'")
    : 0;

// Month to date sales
$monthSalesCount = tableExists($conn, 'sales_invoices')
    ? getCount($conn, 'sales_invoices', "business_id = {$businessId} AND DATE(invoice_date) BETWEEN '{$currentMonthStart}' AND '{$currentMonthEnd}'")
    : 0;

$monthSalesAmount = tableExists($conn, 'sales_invoices')
    ? getSum($conn, 'sales_invoices', 'grand_total', "business_id = {$businessId} AND DATE(invoice_date) BETWEEN '{$currentMonthStart}' AND '{$currentMonthEnd}'")
    : 0;

$monthCollections = tableExists($conn, 'payments')
    ? getSum($conn, 'payments', 'amount', "business_id = {$businessId} AND payment_for = 'sales' AND DATE(payment_date) BETWEEN '{$currentMonthStart}' AND '{$currentMonthEnd}'")
    : 0;

// Last month sales
$lastMonthSalesAmount = tableExists($conn, 'sales_invoices')
    ? getSum($conn, 'sales_invoices', 'grand_total', "business_id = {$businessId} AND DATE(invoice_date) BETWEEN '{$lastMonthStart}' AND '{$lastMonthEnd}'")
    : 0;

// Year to date sales
$yearSalesAmount = tableExists($conn, 'sales_invoices')
    ? getSum($conn, 'sales_invoices', 'grand_total', "business_id = {$businessId} AND YEAR(invoice_date) = {$currentYear}")
    : 0;

// Calculate growth percentage
$growthPercentage = 0;
if ($lastMonthSalesAmount > 0) {
    $growthPercentage = (($monthSalesAmount - $lastMonthSalesAmount) / $lastMonthSalesAmount) * 100;
}

/* -------------------------------------------------------
   PAYMENT STATUS STATISTICS
------------------------------------------------------- */
$paidInvoices = tableExists($conn, 'sales_invoices')
    ? getCount($conn, 'sales_invoices', "business_id = {$businessId} AND payment_status = 'paid'")
    : 0;

$partialInvoices = tableExists($conn, 'sales_invoices')
    ? getCount($conn, 'sales_invoices', "business_id = {$businessId} AND payment_status = 'partial'")
    : 0;

$unpaidInvoices = tableExists($conn, 'sales_invoices')
    ? getCount($conn, 'sales_invoices', "business_id = {$businessId} AND payment_status = 'unpaid'")
    : 0;

$totalOutstanding = tableExists($conn, 'sales_invoices')
    ? getSum($conn, 'sales_invoices', 'balance_amount', "business_id = {$businessId} AND payment_status IN ('unpaid', 'partial')")
    : 0;

/* -------------------------------------------------------
   TOP SELLING PRODUCTS
------------------------------------------------------- */
$topProducts = [];
if (tableExists($conn, 'sales_invoice_items') && tableExists($conn, 'products')) {
    $sql = "SELECT 
                p.id,
                p.product_name,
                p.product_code,
                p.selling_price,
                SUM(sii.qty) AS total_qty_sold,
                SUM(sii.line_total) AS total_sales_value
            FROM sales_invoice_items sii
            INNER JOIN products p ON p.id = sii.product_id
            INNER JOIN sales_invoices si ON si.id = sii.invoice_id
            WHERE sii.item_type = 'product'
            AND p.business_id = {$businessId}
            AND si.business_id = {$businessId}
            AND YEAR(si.invoice_date) = {$currentYear}
            GROUP BY p.id, p.product_name, p.product_code, p.selling_price
            ORDER BY total_qty_sold DESC
            LIMIT 10";
    
    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $topProducts[] = $row;
        }
    }
}

/* -------------------------------------------------------
   TOP SELLING VEHICLES (MODELS)
------------------------------------------------------- */
$topVehicles = [];
if (tableExists($conn, 'sales_invoice_items') && tableExists($conn, 'vehicle_models') && tableExists($conn, 'vehicle_brands') && tableExists($conn, 'vehicle_stock')) {
    $sql = "SELECT 
                vm.id,
                vm.model_name,
                vm.variant_name,
                vb.brand_name,
                COUNT(sii.id) AS total_units_sold,
                SUM(sii.line_total) AS total_sales_value
            FROM sales_invoice_items sii
            INNER JOIN vehicle_stock vs ON vs.id = sii.vehicle_stock_id
            INNER JOIN vehicle_models vm ON vm.id = vs.model_id
            INNER JOIN vehicle_brands vb ON vb.id = vm.brand_id
            INNER JOIN sales_invoices si ON si.id = sii.invoice_id
            WHERE sii.item_type = 'vehicle'
            AND si.business_id = {$businessId}
            AND YEAR(si.invoice_date) = {$currentYear}
            GROUP BY vm.id, vm.model_name, vm.variant_name, vb.brand_name
            ORDER BY total_units_sold DESC
            LIMIT 10";
    
    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $topVehicles[] = $row;
        }
    }
}

/* -------------------------------------------------------
   MONTHLY SALES DATA FOR CHARTS
------------------------------------------------------- */
$monthlySales = [];
for ($i = 1; $i <= 12; $i++) {
    $monthlySales[$i] = 0;
}

if (tableExists($conn, 'sales_invoices')) {
    $sql = "SELECT 
                MONTH(invoice_date) AS month_num,
                SUM(grand_total) AS total_amount
            FROM sales_invoices
            WHERE business_id = {$businessId}
            AND YEAR(invoice_date) = {$currentYear}
            GROUP BY MONTH(invoice_date)
            ORDER BY month_num";
    
    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $monthlySales[(int)$row['month_num']] = (float)$row['total_amount'];
        }
    }
}

/* -------------------------------------------------------
   DAILY SALES FOR CURRENT MONTH
------------------------------------------------------- */
$dailySales = [];
$daysInMonth = date('t');
for ($i = 1; $i <= $daysInMonth; $i++) {
    $dailySales[$i] = 0;
}

if (tableExists($conn, 'sales_invoices')) {
    $sql = "SELECT 
                DAY(invoice_date) AS day_num,
                SUM(grand_total) AS total_amount
            FROM sales_invoices
            WHERE business_id = {$businessId}
            AND DATE(invoice_date) BETWEEN '{$currentMonthStart}' AND '{$currentMonthEnd}'
            GROUP BY DAY(invoice_date)
            ORDER BY day_num";
    
    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $dailySales[(int)$row['day_num']] = (float)$row['total_amount'];
        }
    }
}

/* -------------------------------------------------------
   RECENT INVOICES
------------------------------------------------------- */
$recentInvoices = [];
if (tableExists($conn, 'sales_invoices') && tableExists($conn, 'customers')) {
    $sql = "SELECT 
                si.id,
                si.invoice_no,
                si.invoice_date,
                si.grand_total,
                si.paid_amount,
                si.payment_status,
                si.invoice_type,
                c.full_name AS customer_name,
                c.mobile
            FROM sales_invoices si
            INNER JOIN customers c ON c.id = si.customer_id
            WHERE si.business_id = {$businessId}
            ORDER BY si.id DESC
            LIMIT 10";
    
    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $recentInvoices[] = $row;
        }
    }
}

/* -------------------------------------------------------
   PAYMENT METHODS BREAKDOWN
------------------------------------------------------- */
$paymentMethods = [];
if (tableExists($conn, 'payments') && tableExists($conn, 'payment_methods')) {
    $sql = "SELECT 
                pm.method_name,
                COUNT(p.id) AS payment_count,
                SUM(p.amount) AS total_amount
            FROM payments p
            INNER JOIN payment_methods pm ON pm.id = p.payment_method_id
            WHERE p.business_id = {$businessId}
            AND p.payment_for = 'sales'
            AND YEAR(p.payment_date) = {$currentYear}
            GROUP BY pm.id, pm.method_name
            ORDER BY total_amount DESC";
    
    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $paymentMethods[] = $row;
        }
    }
}

/* -------------------------------------------------------
   TOP CUSTOMERS
------------------------------------------------------- */
$topCustomers = [];
if (tableExists($conn, 'sales_invoices') && tableExists($conn, 'customers')) {
    $sql = "SELECT 
                c.id,
                c.full_name,
                c.mobile,
                c.city,
                COUNT(si.id) AS invoice_count,
                SUM(si.grand_total) AS total_purchases
            FROM sales_invoices si
            INNER JOIN customers c ON c.id = si.customer_id
            WHERE si.business_id = {$businessId}
            AND YEAR(si.invoice_date) = {$currentYear}
            GROUP BY c.id, c.full_name, c.mobile, c.city
            ORDER BY total_purchases DESC
            LIMIT 10";
    
    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $topCustomers[] = $row;
        }
    }
}

/* -------------------------------------------------------
   PAGE VARIABLES
------------------------------------------------------- */
$pageTitle = 'Sales Dashboard';
$currentPage = 'sales-dashboard';

// Format data for charts
$monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
?>
<!doctype html>
<html lang="en">

<?php include('includes/head.php'); ?>

<!-- Include Chart.js for graphs -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

<style>
    /* Fix overflow issues */
    .page-content {
        padding-bottom: 90px !important;
        overflow-x: hidden;
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
        padding-right: 15px;
        padding-left: 15px;
        margin-right: auto;
        margin-left: auto;
        max-width: 100%;
        overflow-x: hidden;
    }
    .row {
        margin-right: -12px;
        margin-left: -12px;
    }
    .col-xl-3, .col-xl-4, .col-xl-6, .col-xl-8, .col-md-6 {
        padding-right: 12px;
        padding-left: 12px;
    }
    /* Ensure charts don't overflow */
    canvas {
        max-width: 100%;
        height: auto !important;
    }
    .chart-container {
        position: relative;
        width: 100%;
        height: 300px;
        overflow: hidden;
    }
    /* Fix table overflow */
    .table-responsive {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
    /* Fix avatar sizes */
    .avatar-sm {
        width: 2.5rem;
        height: 2.5rem;
        flex-shrink: 0;
    }
    .font-size-20 {
        font-size: 1.25rem;
    }
    /* Ensure cards don't cause overflow */
    .card-body {
        padding: 1.5rem;
        word-wrap: break-word;
    }
    /* Fix badge display */
    .badge {
        white-space: nowrap;
    }
    /* Ensure text doesn't overflow */
    .text-truncate {
        max-width: 100%;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
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
                            <h4 class="mb-0">Sales Dashboard</h4>
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
                                    <li class="breadcrumb-item active">Sales</li>
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
                                        <h4 class="text-white mb-1">
                                            Sales Overview
                                        </h4>
                                        <p class="mb-0 opacity-75">
                                            Track your sales performance, collections, and analytics for <?php echo date('Y'); ?>
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

                <!-- Key Metrics Cards -->
                <div class="row">
                    <div class="col-xl-3 col-md-6">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex">
                                    <div class="flex-shrink-0 me-3">
                                        <div class="avatar-sm">
                                            <span class="avatar-title bg-soft-primary text-primary rounded-circle">
                                                <i class="ri-money-rupee-circle-line font-size-20"></i>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-1">Today's Sales</p>
                                        <h4><?php echo money($todaySalesAmount); ?></h4>
                                        <div class="text-muted small">
                                            <span class="text-<?php echo $todaySalesCount > 0 ? 'success' : 'muted'; ?>">
                                                <?php echo $todaySalesCount; ?> invoices
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex">
                                    <div class="flex-shrink-0 me-3">
                                        <div class="avatar-sm">
                                            <span class="avatar-title bg-soft-success text-success rounded-circle">
                                                <i class="ri-bank-card-line font-size-20"></i>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-1">Today's Collection</p>
                                        <h4><?php echo money($todayCollections); ?></h4>
                                        <div class="text-muted small">
                                            <?php echo $todaySalesAmount > 0 ? round(($todayCollections / $todaySalesAmount) * 100, 1) : 0; ?>% collected
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex">
                                    <div class="flex-shrink-0 me-3">
                                        <div class="avatar-sm">
                                            <span class="avatar-title bg-soft-warning text-warning rounded-circle">
                                                <i class="ri-calendar-check-line font-size-20"></i>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-1">Month to Date</p>
                                        <h4><?php echo money($monthSalesAmount); ?></h4>
                                        <div class="text-muted small">
                                            <span class="text-<?php echo $growthPercentage >= 0 ? 'success' : 'danger'; ?>">
                                                <i class="ri-arrow-<?php echo $growthPercentage >= 0 ? 'up' : 'down'; ?>-line"></i>
                                                <?php echo round(abs($growthPercentage), 1); ?>% vs last month
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex">
                                    <div class="flex-shrink-0 me-3">
                                        <div class="avatar-sm">
                                            <span class="avatar-title bg-soft-danger text-danger rounded-circle">
                                                <i class="ri-error-warning-line font-size-20"></i>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-1">Outstanding</p>
                                        <h4><?php echo money($totalOutstanding); ?></h4>
                                        <div class="text-muted small">
                                            <?php echo $unpaidInvoices + $partialInvoices; ?> pending invoices
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Second Row Metrics -->
                <div class="row">
                    <div class="col-xl-3 col-md-6">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0 me-3">
                                        <div class="avatar-sm">
                                            <span class="avatar-title bg-soft-info text-info rounded-circle">
                                                <i class="ri-file-list-3-line font-size-20"></i>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-1">Total Invoices</p>
                                        <h4 class="mb-0"><?php echo number_format($paidInvoices + $partialInvoices + $unpaidInvoices); ?></h4>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0 me-3">
                                        <div class="avatar-sm">
                                            <span class="avatar-title bg-soft-success text-success rounded-circle">
                                                <i class="ri-checkbox-circle-line font-size-20"></i>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-1">Paid</p>
                                        <h4 class="mb-0"><?php echo number_format($paidInvoices); ?></h4>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0 me-3">
                                        <div class="avatar-sm">
                                            <span class="avatar-title bg-soft-warning text-warning rounded-circle">
                                                <i class="ri-time-line font-size-20"></i>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-1">Partial</p>
                                        <h4 class="mb-0"><?php echo number_format($partialInvoices); ?></h4>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0 me-3">
                                        <div class="avatar-sm">
                                            <span class="avatar-title bg-soft-danger text-danger rounded-circle">
                                                <i class="ri-close-circle-line font-size-20"></i>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-1">Unpaid</p>
                                        <h4 class="mb-0"><?php echo number_format($unpaidInvoices); ?></h4>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Charts Row -->
                <div class="row">
                    <div class="col-xl-8">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Monthly Sales <?php echo $currentYear; ?></h4>
                                <div class="chart-container">
                                    <canvas id="monthlySalesChart" style="width:100%; height:300px;"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-4">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Payment Methods</h4>
                                <div class="chart-container" style="height:250px;">
                                    <canvas id="paymentMethodsChart" style="width:100%; height:250px;"></canvas>
                                </div>
                                <div class="mt-4">
                                    <?php foreach ($paymentMethods as $method): ?>
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <span class="text-muted"><?php echo h($method['method_name']); ?></span>
                                            <span class="fw-bold"><?php echo money($method['total_amount']); ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php if (empty($paymentMethods)): ?>
                                        <p class="text-muted text-center">No payment data available</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Daily Sales Chart -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Daily Sales - <?php echo date('F Y'); ?></h4>
                                <div class="chart-container">
                                    <canvas id="dailySalesChart" style="width:100%; height:300px;"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Top Selling and Recent Invoices Row -->
                <div class="row">
                    <div class="col-xl-6">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Top Selling Products</h4>
                                <div class="table-responsive">
                                    <table class="table table-centered table-nowrap mb-0">
                                        <thead>
                                            <tr>
                                                <th>Product</th>
                                                <th>Code</th>
                                                <th>Qty Sold</th>
                                                <th>Total Value</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($topProducts)): ?>
                                                <?php foreach ($topProducts as $product): ?>
                                                    <tr>
                                                        <td><?php echo h($product['product_name']); ?></td>
                                                        <td><?php echo h($product['product_code']); ?></td>
                                                        <td><?php echo number_format($product['total_qty_sold']); ?></td>
                                                        <td><?php echo money($product['total_sales_value']); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="4" class="text-center text-muted">No product sales data</td>
                                                </tr>
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
                                <h4 class="card-title mb-4">Top Selling Vehicles</h4>
                                <div class="table-responsive">
                                    <table class="table table-centered table-nowrap mb-0">
                                        <thead>
                                            <tr>
                                                <th>Model</th>
                                                <th>Brand</th>
                                                <th>Units Sold</th>
                                                <th>Total Value</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($topVehicles)): ?>
                                                <?php foreach ($topVehicles as $vehicle): ?>
                                                    <tr>
                                                        <td>
                                                            <?php echo h($vehicle['model_name']); ?>
                                                            <?php if (!empty($vehicle['variant_name'])): ?>
                                                                <br><small class="text-muted"><?php echo h($vehicle['variant_name']); ?></small>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td><?php echo h($vehicle['brand_name']); ?></td>
                                                        <td><?php echo number_format($vehicle['total_units_sold']); ?></td>
                                                        <td><?php echo money($vehicle['total_sales_value']); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="4" class="text-center text-muted">No vehicle sales data</td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recent Invoices and Top Customers -->
                <div class="row">
                    <div class="col-xl-6">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex align-items-center justify-content-between mb-4">
                                    <h4 class="card-title mb-0">Recent Invoices</h4>
                                    <a href="sales-invoices.php" class="btn btn-primary btn-sm">View All</a>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-centered table-nowrap mb-0">
                                        <thead>
                                            <tr>
                                                <th>Invoice #</th>
                                                <th>Customer</th>
                                                <th>Date</th>
                                                <th>Total</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($recentInvoices)): ?>
                                                <?php foreach ($recentInvoices as $inv): ?>
                                                    <tr>
                                                        <td>
                                                            <a href="sales-invoice-view.php?id=<?php echo $inv['id']; ?>">
                                                                <?php echo h($inv['invoice_no']); ?>
                                                            </a>
                                                        </td>
                                                        <td>
                                                            <?php echo h($inv['customer_name']); ?>
                                                            <br><small class="text-muted"><?php echo h($inv['mobile']); ?></small>
                                                        </td>
                                                        <td><?php echo date('d M Y', strtotime($inv['invoice_date'])); ?></td>
                                                        <td><?php echo money($inv['grand_total']); ?></td>
                                                        <td>
                                                            <?php
                                                            $status = $inv['payment_status'];
                                                            $badge = 'secondary';
                                                            if ($status === 'paid') $badge = 'success';
                                                            elseif ($status === 'partial') $badge = 'warning';
                                                            elseif ($status === 'unpaid') $badge = 'danger';
                                                            ?>
                                                            <span class="badge bg-<?php echo $badge; ?>">
                                                                <?php echo ucfirst($status); ?>
                                                            </span>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="5" class="text-center text-muted">No recent invoices</td>
                                                </tr>
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
                                <h4 class="card-title mb-4">Top Customers</h4>
                                <div class="table-responsive">
                                    <table class="table table-centered table-nowrap mb-0">
                                        <thead>
                                            <tr>
                                                <th>Customer</th>
                                                <th>Location</th>
                                                <th>Invoices</th>
                                                <th>Total Purchase</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($topCustomers)): ?>
                                                <?php foreach ($topCustomers as $cust): ?>
                                                    <tr>
                                                        <td>
                                                            <?php echo h($cust['full_name']); ?>
                                                            <br><small class="text-muted"><?php echo h($cust['mobile']); ?></small>
                                                        </td>
                                                        <td><?php echo h($cust['city'] ?? '-'); ?></td>
                                                        <td><?php echo number_format($cust['invoice_count']); ?></td>
                                                        <td><?php echo money($cust['total_purchases']); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="4" class="text-center text-muted">No customer data</td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Year Summary Card -->
                <div class="row">
                    <div class="col-12">
                        <div class="card bg-light">
                            <div class="card-body">
                                <div class="row align-items-center">
                                    <div class="col-md-8">
                                        <h5 class="mb-2">Year <?php echo $currentYear; ?> Summary</h5>
                                        <p class="text-muted mb-0">
                                            Total Sales: <strong><?php echo money($yearSalesAmount); ?></strong> | 
                                            Average Monthly: <strong><?php echo money($yearSalesAmount / 12); ?></strong> | 
                                            Total Invoices: <strong><?php echo number_format($paidInvoices + $partialInvoices + $unpaidInvoices); ?></strong>
                                        </p>
                                    </div>
                                    <div class="col-md-4 text-md-end mt-3 mt-md-0">
                                        <a href="sales-reports.php" class="btn btn-primary">
                                            <i class="ri-file-chart-line me-1"></i> View Detailed Reports
                                        </a>
                                    </div>
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

<script>
// Wait for DOM to be fully loaded
document.addEventListener('DOMContentLoaded', function() {
    
    // Monthly Sales Chart
    const monthlyCtx = document.getElementById('monthlySalesChart').getContext('2d');
    new Chart(monthlyCtx, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($monthNames); ?>,
            datasets: [{
                label: 'Sales (₹)',
                data: <?php echo json_encode(array_values($monthlySales)); ?>,
                backgroundColor: 'rgba(13, 110, 253, 0.2)',
                borderColor: 'rgba(13, 110, 253, 1)',
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
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return 'Sales: ₹' + context.raw.toLocaleString();
                        }
                    }
                },
                legend: {
                    display: false
                }
            }
        }
    });

    // Daily Sales Chart
    const dailyCtx = document.getElementById('dailySalesChart').getContext('2d');
    new Chart(dailyCtx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode(range(1, $daysInMonth)); ?>,
            datasets: [{
                label: 'Daily Sales (₹)',
                data: <?php echo json_encode(array_values($dailySales)); ?>,
                fill: true,
                backgroundColor: 'rgba(13, 110, 253, 0.1)',
                borderColor: 'rgba(13, 110, 253, 1)',
                tension: 0.4,
                pointRadius: 3,
                pointHoverRadius: 5
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
                },
                x: {
                    ticks: {
                        maxTicksLimit: 15,
                        maxRotation: 45,
                        minRotation: 45
                    }
                }
            },
            plugins: {
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return 'Sales: ₹' + context.raw.toLocaleString();
                        }
                    }
                },
                legend: {
                    display: false
                }
            }
        }
    });

    // Payment Methods Chart
    <?php if (!empty($paymentMethods)): ?>
    const paymentCtx = document.getElementById('paymentMethodsChart').getContext('2d');
    new Chart(paymentCtx, {
        type: 'doughnut',
        data: {
            labels: <?php echo json_encode(array_column($paymentMethods, 'method_name')); ?>,
            datasets: [{
                data: <?php echo json_encode(array_column($paymentMethods, 'total_amount')); ?>,
                backgroundColor: [
                    'rgba(13, 110, 253, 0.8)',
                    'rgba(25, 135, 84, 0.8)',
                    'rgba(255, 193, 7, 0.8)',
                    'rgba(220, 53, 69, 0.8)',
                    'rgba(111, 66, 193, 0.8)'
                ],
                borderWidth: 1,
                borderColor: '#ffffff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        boxWidth: 12,
                        padding: 10
                    }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            let label = context.label || '';
                            let value = context.raw || 0;
                            let total = context.dataset.data.reduce((a, b) => a + b, 0);
                            let percentage = ((value / total) * 100).toFixed(1);
                            return label + ': ₹' + value.toLocaleString() + ' (' + percentage + '%)';
                        }
                    }
                }
            },
            cutout: '60%'
        }
    });
    <?php endif; ?>

    // Auto-refresh data every 5 minutes (300000 ms)
    setTimeout(function() {
        location.reload();
    }, 300000);
});
</script>

</body>
</html>