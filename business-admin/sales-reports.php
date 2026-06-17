<?php
session_start();
date_default_timezone_set('Asia/Kolkata');

require_once 'includes/config.php';

/*
|--------------------------------------------------------------------------
| Business Admin Sales Reports - sales-reports.php
|--------------------------------------------------------------------------
| This page displays detailed sales reports with:
| - Date range filtering
| - Summary statistics
| - Detailed sales listings
| - Payment collections
| - Export options
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
   DATE RANGE FILTERS
------------------------------------------------------- */
$startDate = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$endDate = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$reportType = isset($_GET['report_type']) ? $_GET['report_type'] : 'summary';
$branchFilter = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : 0;
$paymentStatus = isset($_GET['payment_status']) ? $_GET['payment_status'] : '';

// Validate dates
if (strtotime($startDate) > strtotime($endDate)) {
    $temp = $startDate;
    $startDate = $endDate;
    $endDate = $temp;
}

/* -------------------------------------------------------
   BUILD WHERE CLAUSE
------------------------------------------------------- */
$whereClause = "si.business_id = {$businessId} AND DATE(si.invoice_date) BETWEEN '{$startDate}' AND '{$endDate}'";

if ($branchFilter > 0) {
    $whereClause .= " AND si.branch_id = {$branchFilter}";
}

if (!empty($paymentStatus)) {
    $whereClause .= " AND si.payment_status = '{$paymentStatus}'";
}

/* -------------------------------------------------------
   FETCH BRANCHES FOR FILTER
------------------------------------------------------- */
$branches = [];
if (tableExists($conn, 'branches')) {
    $sql = "SELECT id, branch_name FROM branches WHERE business_id = {$businessId} AND status = 'active' ORDER BY branch_name";
    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $branches[] = $row;
        }
    }
}

/* -------------------------------------------------------
   SUMMARY STATISTICS
------------------------------------------------------- */
$totalInvoices = 0;
$totalAmount = 0;
$totalPaid = 0;
$totalBalance = 0;
$totalDiscount = 0;
$totalTax = 0;

if (tableExists($conn, 'sales_invoices')) {
    // Get invoice statistics
    $sql = "SELECT 
                COUNT(*) AS invoice_count,
                SUM(grand_total) AS total_amount,
                SUM(paid_amount) AS total_paid,
                SUM(balance_amount) AS total_balance,
                SUM(discount_amount) AS total_discount,
                SUM(cgst_amount + sgst_amount + igst_amount + cess_amount) AS total_tax
            FROM sales_invoices si
            WHERE {$whereClause}";
    
    $res = $conn->query($sql);
    if ($res) {
        $row = $res->fetch_assoc();
        $totalInvoices = (int)($row['invoice_count'] ?? 0);
        $totalAmount = (float)($row['total_amount'] ?? 0);
        $totalPaid = (float)($row['total_paid'] ?? 0);
        $totalBalance = (float)($row['total_balance'] ?? 0);
        $totalDiscount = (float)($row['total_discount'] ?? 0);
        $totalTax = (float)($row['total_tax'] ?? 0);
    }
    
    // Get payment status counts
    $statusSql = "SELECT 
                    payment_status,
                    COUNT(*) AS status_count,
                    SUM(grand_total) AS status_amount
                  FROM sales_invoices si
                  WHERE {$whereClause}
                  GROUP BY payment_status";
    
    $statusCounts = [
        'paid' => ['count' => 0, 'amount' => 0],
        'partial' => ['count' => 0, 'amount' => 0],
        'unpaid' => ['count' => 0, 'amount' => 0]
    ];
    
    $statusRes = $conn->query($statusSql);
    if ($statusRes) {
        while ($row = $statusRes->fetch_assoc()) {
            $status = $row['payment_status'];
            $statusCounts[$status] = [
                'count' => (int)$row['status_count'],
                'amount' => (float)$row['status_amount']
            ];
        }
    }
}

/* -------------------------------------------------------
   PAYMENT COLLECTIONS
------------------------------------------------------- */
$totalCollections = 0;
$paymentMethodBreakdown = [];

if (tableExists($conn, 'payments') && tableExists($conn, 'payment_methods')) {
    $paymentSql = "SELECT 
                        pm.method_name,
                        COUNT(p.id) AS payment_count,
                        SUM(p.amount) AS total_amount
                    FROM payments p
                    INNER JOIN payment_methods pm ON pm.id = p.payment_method_id
                    WHERE p.business_id = {$businessId}
                    AND p.payment_for = 'sales'
                    AND DATE(p.payment_date) BETWEEN '{$startDate}' AND '{$endDate}'
                    GROUP BY pm.id, pm.method_name
                    ORDER BY total_amount DESC";
    
    $paymentRes = $conn->query($paymentSql);
    if ($paymentRes) {
        while ($row = $paymentRes->fetch_assoc()) {
            $paymentMethodBreakdown[] = $row;
            $totalCollections += (float)$row['total_amount'];
        }
    }
}

/* -------------------------------------------------------
   DETAILED INVOICES LIST
------------------------------------------------------- */
$invoices = [];
if (tableExists($conn, 'sales_invoices') && tableExists($conn, 'customers') && tableExists($conn, 'branches')) {
    $sql = "SELECT 
                si.id,
                si.invoice_no,
                si.invoice_date,
                si.grand_total,
                si.paid_amount,
                si.balance_amount,
                si.payment_status,
                si.invoice_type,
                si.discount_amount,
                si.cgst_amount,
                si.sgst_amount,
                si.igst_amount,
                si.cess_amount,
                c.full_name AS customer_name,
                c.mobile,
                b.branch_name
            FROM sales_invoices si
            INNER JOIN customers c ON c.id = si.customer_id
            LEFT JOIN branches b ON b.id = si.branch_id
            WHERE {$whereClause}
            ORDER BY si.invoice_date DESC, si.id DESC";
    
    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $invoices[] = $row;
        }
    }
}

/* -------------------------------------------------------
   DAILY BREAKDOWN
------------------------------------------------------- */
$dailyBreakdown = [];
if (tableExists($conn, 'sales_invoices')) {
    $sql = "SELECT 
                DATE(invoice_date) AS sale_date,
                COUNT(*) AS invoice_count,
                SUM(grand_total) AS total_amount,
                SUM(paid_amount) AS total_paid,
                SUM(balance_amount) AS total_balance
            FROM sales_invoices si
            WHERE {$whereClause}
            GROUP BY DATE(invoice_date)
            ORDER BY sale_date DESC";
    
    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $dailyBreakdown[] = $row;
        }
    }
}

/* -------------------------------------------------------
   TOP PRODUCTS IN DATE RANGE
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
            AND si.business_id = {$businessId}
            AND DATE(si.invoice_date) BETWEEN '{$startDate}' AND '{$endDate}'
            GROUP BY p.id, p.product_name, p.product_code, p.selling_price
            ORDER BY total_sales_value DESC
            LIMIT 10";
    
    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $topProducts[] = $row;
        }
    }
}

/* -------------------------------------------------------
   TOP VEHICLES IN DATE RANGE
------------------------------------------------------- */
$topVehicles = [];
if (tableExists($conn, 'sales_invoice_items') && tableExists($conn, 'vehicle_models') && tableExists($conn, 'vehicle_brands')) {
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
            AND DATE(si.invoice_date) BETWEEN '{$startDate}' AND '{$endDate}'
            GROUP BY vm.id, vm.model_name, vm.variant_name, vb.brand_name
            ORDER BY total_sales_value DESC
            LIMIT 10";
    
    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $topVehicles[] = $row;
        }
    }
}

/* -------------------------------------------------------
   EXPORT FUNCTIONALITY
------------------------------------------------------- */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="sales_report_' . $startDate . '_to_' . $endDate . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Add headers
    fputcsv($output, ['Invoice No', 'Date', 'Customer', 'Mobile', 'Branch', 'Type', 'Subtotal', 'Discount', 'Tax', 'Total', 'Paid', 'Balance', 'Status']);
    
    // Add data
    foreach ($invoices as $invoice) {
        fputcsv($output, [
            $invoice['invoice_no'],
            date('d-m-Y', strtotime($invoice['invoice_date'])),
            $invoice['customer_name'],
            $invoice['mobile'],
            $invoice['branch_name'] ?? 'Main',
            ucfirst(str_replace('_', ' ', $invoice['invoice_type'])),
            money($invoice['grand_total'] - $invoice['discount_amount'] - ($invoice['cgst_amount'] + $invoice['sgst_amount'] + $invoice['igst_amount'] + $invoice['cess_amount'])),
            money($invoice['discount_amount']),
            money($invoice['cgst_amount'] + $invoice['sgst_amount'] + $invoice['igst_amount'] + $invoice['cess_amount']),
            money($invoice['grand_total']),
            money($invoice['paid_amount']),
            money($invoice['balance_amount']),
            ucfirst($invoice['payment_status'])
        ]);
    }
    
    fclose($output);
    exit;
}

/* -------------------------------------------------------
   PAGE VARIABLES
------------------------------------------------------- */
$pageTitle = 'Sales Reports';
$currentPage = 'sales-reports';
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
    [class*="col-"] {
        padding-right: 12px;
        padding-left: 12px;
    }
    .report-filters {
        background-color: #f8f9fa;
        border-radius: 0.5rem;
        padding: 1.5rem;
        margin-bottom: 1.5rem;
    }
    .summary-card {
        transition: all 0.3s ease;
    }
    .summary-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 10px 20px rgba(0,0,0,0.1);
    }
    /* Fix table overflow */
    .table-responsive {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        width: 100%;
    }
    .table {
        min-width: 800px; /* Ensures table doesn't get too compressed */
    }
    .badge {
        font-size: 0.75rem;
        padding: 0.25rem 0.5rem;
        white-space: nowrap;
    }
    /* Fix export button positioning and overflow */
    .export-btn {
        position: fixed;
        bottom: 30px;
        right: 30px;
        z-index: 100;
        box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        width: 50px;
        height: 50px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        padding: 0;
    }
    .export-btn:hover {
        transform: scale(1.05);
    }
    .export-btn i {
        font-size: 1.25rem;
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
    /* Fix button groups on mobile */
    .btn-group {
        flex-wrap: wrap;
        gap: 5px;
    }
    .btn-group .btn {
        border-radius: 0.25rem !important;
        margin: 2px;
    }
    /* Fix progress bars */
    .progress {
        min-width: 50px;
    }
    /* Fix text overflow */
    .text-truncate {
        max-width: 100%;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    /* Responsive fixes */
    @media (max-width: 768px) {
        .btn-group {
            width: 100%;
        }
        .btn-group .btn {
            flex: 1 1 auto;
        }
        .card-body {
            padding: 1rem;
        }
        h4 {
            font-size: 1.25rem;
        }
        h5 {
            font-size: 1rem;
        }
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
                            <h4 class="mb-0">Sales Reports</h4>
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
                                    <li class="breadcrumb-item"><a href="sales-dashboard.php">Sales</a></li>
                                    <li class="breadcrumb-item active">Reports</li>
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
                                            Sales Reports
                                        </h4>
                                        <p class="mb-0 opacity-75">
                                            Generate and analyze sales reports with date range filtering
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
                                <form method="GET" action="sales-reports.php" class="row g-3">
                                    <div class="col-md-3">
                                        <label class="form-label">Start Date</label>
                                        <input type="date" name="start_date" class="form-control" value="<?php echo $startDate; ?>" required>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">End Date</label>
                                        <input type="date" name="end_date" class="form-control" value="<?php echo $endDate; ?>" required>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Branch</label>
                                        <select name="branch_id" class="form-select">
                                            <option value="0">All Branches</option>
                                            <?php foreach ($branches as $branch): ?>
                                                <option value="<?php echo $branch['id']; ?>" <?php echo $branchFilter == $branch['id'] ? 'selected' : ''; ?>>
                                                    <?php echo h($branch['branch_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Payment Status</label>
                                        <select name="payment_status" class="form-select">
                                            <option value="">All Status</option>
                                            <option value="paid" <?php echo $paymentStatus === 'paid' ? 'selected' : ''; ?>>Paid</option>
                                            <option value="partial" <?php echo $paymentStatus === 'partial' ? 'selected' : ''; ?>>Partial</option>
                                            <option value="unpaid" <?php echo $paymentStatus === 'unpaid' ? 'selected' : ''; ?>>Unpaid</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2 d-flex align-items-end">
                                        <button type="submit" class="btn btn-primary w-100">
                                            <i class="ri-filter-3-line me-1"></i> Generate Report
                                        </button>
                                    </div>
                                </form>
                                
                                <!-- Quick Date Filters -->
                                <div class="row mt-3">
                                    <div class="col-12">
                                        <div class="btn-group flex-wrap" role="group">
                                            <a href="?start_date=<?php echo date('Y-m-01'); ?>&end_date=<?php echo date('Y-m-d'); ?>" class="btn btn-sm btn-outline-primary">This Month</a>
                                            <a href="?start_date=<?php echo date('Y-m-01', strtotime('-1 month')); ?>&end_date=<?php echo date('Y-m-t', strtotime('-1 month')); ?>" class="btn btn-sm btn-outline-primary">Last Month</a>
                                            <a href="?start_date=<?php echo date('Y-01-01'); ?>&end_date=<?php echo date('Y-m-d'); ?>" class="btn btn-sm btn-outline-primary">Year to Date</a>
                                            <a href="?start_date=<?php echo date('Y-m-d', strtotime('-7 days')); ?>&end_date=<?php echo date('Y-m-d'); ?>" class="btn btn-sm btn-outline-primary">Last 7 Days</a>
                                            <a href="?start_date=<?php echo date('Y-m-d', strtotime('-30 days')); ?>&end_date=<?php echo date('Y-m-d'); ?>" class="btn btn-sm btn-outline-primary">Last 30 Days</a>
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
                                <div class="d-flex">
                                    <div class="flex-shrink-0 me-3">
                                        <div class="avatar-sm">
                                            <span class="avatar-title bg-soft-primary text-primary rounded-circle">
                                                <i class="ri-file-list-3-line font-size-20"></i>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1 min-width-0">
                                        <p class="text-muted mb-1">Total Invoices</p>
                                        <h4><?php echo number_format($totalInvoices); ?></h4>
                                        <div class="text-muted small text-truncate">
                                            <span class="text-info">
                                                <?php 
                                                echo number_format($statusCounts['paid']['count'] ?? 0) . ' paid, ';
                                                echo number_format($statusCounts['partial']['count'] ?? 0) . ' partial, ';
                                                echo number_format($statusCounts['unpaid']['count'] ?? 0) . ' unpaid';
                                                ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6">
                        <div class="card summary-card">
                            <div class="card-body">
                                <div class="d-flex">
                                    <div class="flex-shrink-0 me-3">
                                        <div class="avatar-sm">
                                            <span class="avatar-title bg-soft-success text-success rounded-circle">
                                                <i class="ri-money-rupee-circle-line font-size-20"></i>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1 min-width-0">
                                        <p class="text-muted mb-1">Total Sales</p>
                                        <h4><?php echo money($totalAmount); ?></h4>
                                        <div class="text-muted small text-truncate">
                                            <span class="text-success">Avg: <?php echo money($totalInvoices > 0 ? $totalAmount / $totalInvoices : 0); ?> per invoice</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6">
                        <div class="card summary-card">
                            <div class="card-body">
                                <div class="d-flex">
                                    <div class="flex-shrink-0 me-3">
                                        <div class="avatar-sm">
                                            <span class="avatar-title bg-soft-warning text-warning rounded-circle">
                                                <i class="ri-bank-card-line font-size-20"></i>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1 min-width-0">
                                        <p class="text-muted mb-1">Total Collected</p>
                                        <h4><?php echo money($totalPaid); ?></h4>
                                        <div class="text-muted small text-truncate">
                                            <span class="text-warning"><?php echo $totalAmount > 0 ? round(($totalPaid / $totalAmount) * 100, 1) : 0; ?>% collected</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6">
                        <div class="card summary-card">
                            <div class="card-body">
                                <div class="d-flex">
                                    <div class="flex-shrink-0 me-3">
                                        <div class="avatar-sm">
                                            <span class="avatar-title bg-soft-danger text-danger rounded-circle">
                                                <i class="ri-error-warning-line font-size-20"></i>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1 min-width-0">
                                        <p class="text-muted mb-1">Outstanding</p>
                                        <h4><?php echo money($totalBalance); ?></h4>
                                        <div class="text-muted small text-truncate">
                                            <span class="text-danger">Discount: <?php echo money($totalDiscount); ?></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tax and Payment Methods Row -->
                <div class="row">
                    <div class="col-xl-4">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="card-title mb-3">Tax Summary</h5>
                                <div class="row">
                                    <div class="col-6">
                                        <div class="p-3 border rounded text-center">
                                            <p class="text-muted mb-1">Total Tax</p>
                                            <h5 class="mb-0"><?php echo money($totalTax); ?></h5>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="p-3 border rounded text-center">
                                            <p class="text-muted mb-1">Tax %</p>
                                            <h5 class="mb-0"><?php echo $totalAmount > 0 ? round(($totalTax / $totalAmount) * 100, 2) : 0; ?>%</h5>
                                        </div>
                                    </div>
                                </div>
                                <div class="mt-3">
                                    <div class="d-flex justify-content-between mb-2">
                                        <span class="text-muted">CGST + SGST</span>
                                        <span class="fw-bold"><?php echo money($totalTax * 0.5); ?></span>
                                    </div>
                                    <div class="d-flex justify-content-between mb-2">
                                        <span class="text-muted">IGST</span>
                                        <span class="fw-bold"><?php echo money(0); ?></span>
                                    </div>
                                    <div class="d-flex justify-content-between">
                                        <span class="text-muted">Cess</span>
                                        <span class="fw-bold"><?php echo money(0); ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-4">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="card-title mb-3">Payment Methods</h5>
                                <?php if (!empty($paymentMethodBreakdown)): ?>
                                    <?php foreach ($paymentMethodBreakdown as $method): ?>
                                        <div class="d-flex justify-content-between align-items-center mb-3">
                                            <span class="text-truncate" style="max-width: 60%;">
                                                <span class="badge bg-primary me-2"><?php echo $method['payment_count']; ?></span>
                                                <?php echo h($method['method_name']); ?>
                                            </span>
                                            <span class="fw-bold"><?php echo money($method['total_amount']); ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                    <hr>
                                    <div class="d-flex justify-content-between">
                                        <span class="text-muted">Total Collections</span>
                                        <span class="fw-bold"><?php echo money($totalCollections); ?></span>
                                    </div>
                                <?php else: ?>
                                    <p class="text-muted text-center py-3">No payment data available</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-4">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="card-title mb-3">Status Breakdown</h5>
                                <div class="mb-3">
                                    <div class="d-flex justify-content-between mb-2">
                                        <span class="text-success">Paid</span>
                                        <span class="fw-bold"><?php echo money($statusCounts['paid']['amount'] ?? 0); ?></span>
                                    </div>
                                    <div class="progress mb-2" style="height: 8px;">
                                        <div class="progress-bar bg-success" role="progressbar" 
                                             style="width: <?php echo $totalAmount > 0 ? (($statusCounts['paid']['amount'] ?? 0) / $totalAmount) * 100 : 0; ?>%"></div>
                                    </div>
                                    
                                    <div class="d-flex justify-content-between mb-2">
                                        <span class="text-warning">Partial</span>
                                        <span class="fw-bold"><?php echo money($statusCounts['partial']['amount'] ?? 0); ?></span>
                                    </div>
                                    <div class="progress mb-2" style="height: 8px;">
                                        <div class="progress-bar bg-warning" role="progressbar" 
                                             style="width: <?php echo $totalAmount > 0 ? (($statusCounts['partial']['amount'] ?? 0) / $totalAmount) * 100 : 0; ?>%"></div>
                                    </div>
                                    
                                    <div class="d-flex justify-content-between mb-2">
                                        <span class="text-danger">Unpaid</span>
                                        <span class="fw-bold"><?php echo money($statusCounts['unpaid']['amount'] ?? 0); ?></span>
                                    </div>
                                    <div class="progress mb-2" style="height: 8px;">
                                        <div class="progress-bar bg-danger" role="progressbar" 
                                             style="width: <?php echo $totalAmount > 0 ? (($statusCounts['unpaid']['amount'] ?? 0) / $totalAmount) * 100 : 0; ?>%"></div>
                                    </div>
                                </div>
                                <div class="d-flex justify-content-between mt-3 pt-2 border-top">
                                    <span class="text-muted">Collection Efficiency</span>
                                    <span class="fw-bold <?php echo $totalAmount > 0 && ($totalPaid / $totalAmount) * 100 >= 80 ? 'text-success' : 'text-warning'; ?>">
                                        <?php echo $totalAmount > 0 ? round(($totalPaid / $totalAmount) * 100, 1) : 0; ?>%
                                    </span>
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
                                <h5 class="card-title mb-4">Daily Sales Breakdown</h5>
                                <div class="table-responsive">
                                    <table class="table table-bordered table-striped align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th>Date</th>
                                                <th>Invoices</th>
                                                <th>Total Sales</th>
                                                <th>Collected</th>
                                                <th>Outstanding</th>
                                                <th>Collection %</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($dailyBreakdown)): ?>
                                                <?php foreach ($dailyBreakdown as $day): ?>
                                                    <tr>
                                                        <td><strong><?php echo date('d M Y', strtotime($day['sale_date'])); ?></strong></td>
                                                        <td><?php echo number_format($day['invoice_count']); ?></td>
                                                        <td><?php echo money($day['total_amount']); ?></td>
                                                        <td><?php echo money($day['total_paid']); ?></td>
                                                        <td><?php echo money($day['total_balance']); ?></td>
                                                        <td>
                                                            <?php $collectionPercent = $day['total_amount'] > 0 ? ($day['total_paid'] / $day['total_amount']) * 100 : 0; ?>
                                                            <div class="d-flex align-items-center">
                                                                <span class="me-2"><?php echo round($collectionPercent, 1); ?>%</span>
                                                                <div class="progress flex-grow-1" style="height: 5px; min-width: 60px;">
                                                                    <div class="progress-bar bg-<?php echo $collectionPercent >= 80 ? 'success' : ($collectionPercent >= 50 ? 'warning' : 'danger'); ?>" 
                                                                         style="width: <?php echo $collectionPercent; ?>%"></div>
                                                                </div>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="6" class="text-center text-muted py-4">No daily data available</td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Top Products and Vehicles -->
                <div class="row">
                    <div class="col-xl-6">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="card-title mb-4">Top Selling Products</h5>
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
                                                    <td colspan="4" class="text-center text-muted">No product sales in this period</td>
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
                                <h5 class="card-title mb-4">Top Selling Vehicles</h5>
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
                                                    <td colspan="4" class="text-center text-muted">No vehicle sales in this period</td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Detailed Invoices Table -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex align-items-center justify-content-between mb-4">
                                    <h5 class="card-title mb-0">Detailed Invoice List</h5>
                                    <a href="?start_date=<?php echo $startDate; ?>&end_date=<?php echo $endDate; ?>&branch_id=<?php echo $branchFilter; ?>&payment_status=<?php echo $paymentStatus; ?>&export=csv" class="btn btn-success btn-sm">
                                        <i class="ri-download-2-line me-1"></i> Export CSV
                                    </a>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-bordered table-striped align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th>Invoice #</th>
                                                <th>Date</th>
                                                <th>Customer</th>
                                                <th>Branch</th>
                                                <th>Type</th>
                                                <th>Total</th>
                                                <th>Paid</th>
                                                <th>Balance</th>
                                                <th>Status</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($invoices)): ?>
                                                <?php foreach ($invoices as $invoice): ?>
                                                    <tr>
                                                        <td>
                                                            <strong><?php echo h($invoice['invoice_no']); ?></strong>
                                                        </td>
                                                        <td><?php echo date('d M Y', strtotime($invoice['invoice_date'])); ?></td>
                                                        <td>
                                                            <?php echo h($invoice['customer_name']); ?>
                                                            <br><small class="text-muted"><?php echo h($invoice['mobile']); ?></small>
                                                        </td>
                                                        <td><?php echo h($invoice['branch_name'] ?? 'Main'); ?></td>
                                                        <td>
                                                            <span class="badge bg-soft-info text-info">
                                                                <?php echo ucfirst(str_replace('_', ' ', $invoice['invoice_type'])); ?>
                                                            </span>
                                                        </td>
                                                        <td class="fw-bold"><?php echo money($invoice['grand_total']); ?></td>
                                                        <td class="text-success"><?php echo money($invoice['paid_amount']); ?></td>
                                                        <td class="text-danger"><?php echo money($invoice['balance_amount']); ?></td>
                                                        <td>
                                                            <?php
                                                            $status = $invoice['payment_status'];
                                                            $badge = 'secondary';
                                                            if ($status === 'paid') $badge = 'success';
                                                            elseif ($status === 'partial') $badge = 'warning';
                                                            elseif ($status === 'unpaid') $badge = 'danger';
                                                            ?>
                                                            <span class="badge bg-<?php echo $badge; ?>">
                                                                <?php echo ucfirst($status); ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <a href="sales-invoice-view.php?id=<?php echo $invoice['id']; ?>" class="btn btn-sm btn-outline-primary">
                                                                <i class="ri-eye-line"></i>
                                                            </a>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="10" class="text-center text-muted py-4">
                                                        <i class="ri-file-list-3-line" style="font-size: 3rem; color: #ccc;"></i>
                                                        <p class="mt-2">No invoices found for the selected period.</p>
                                                    </td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <!-- Summary Footer -->
                                <?php if (!empty($invoices)): ?>
                                <div class="row mt-4">
                                    <div class="col-md-6">
                                        <p class="text-muted mb-0">Showing <?php echo count($invoices); ?> invoices from <?php echo date('d M Y', strtotime($startDate)); ?> to <?php echo date('d M Y', strtotime($endDate)); ?></p>
                                    </div>
                                    <div class="col-md-6 text-md-end">
                                        <p class="mb-0">
                                            <strong>Total:</strong> <?php echo money($totalAmount); ?> | 
                                            <strong>Collected:</strong> <?php echo money($totalPaid); ?> | 
                                            <strong>Outstanding:</strong> <?php echo money($totalBalance); ?>
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

<!-- Floating Export Button (visible on scroll) -->
<a href="?start_date=<?php echo $startDate; ?>&end_date=<?php echo $endDate; ?>&branch_id=<?php echo $branchFilter; ?>&payment_status=<?php echo $paymentStatus; ?>&export=csv" 
   class="btn btn-success export-btn" 
   title="Export to CSV">
    <i class="ri-download-2-line"></i>
</a>

<?php include('includes/rightbar.php'); ?>
<?php include('includes/scripts.php'); ?>

<script>
// Auto-hide alerts after 5 seconds
setTimeout(function() {
    var alerts = document.querySelectorAll('.alert');
    alerts.forEach(function(alert) {
        var bsAlert = new bootstrap.Alert(alert);
        bsAlert.close();
    });
}, 5000);

// Show/hide floating export button based on scroll
window.addEventListener('scroll', function() {
    var exportBtn = document.querySelector('.export-btn');
    if (exportBtn) {
        if (window.scrollY > 300) {
            exportBtn.style.display = 'flex';
        } else {
            exportBtn.style.display = 'none';
        }
    }
});

// Initialize - hide export button at top
document.addEventListener('DOMContentLoaded', function() {
    var exportBtn = document.querySelector('.export-btn');
    if (exportBtn) {
        exportBtn.style.display = 'none';
    }
});

// Handle window resize to prevent overflow
window.addEventListener('resize', function() {
    // Force table containers to recalculate
    document.querySelectorAll('.table-responsive').forEach(function(el) {
        el.style.overflowX = 'auto';
    });
});
</script>

</body>
</html>