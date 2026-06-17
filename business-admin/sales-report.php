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

function qtyf($qty): string
{
    $qty = (float)$qty;
    return ((int)$qty == $qty) ? number_format($qty, 0) : number_format($qty, 2);
}

function tableExists(mysqli $conn, string $table): bool
{
    $sql = "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return false;

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

function paymentStatusByAmount($grandTotal, $paidAmount): string
{
    $grandTotal = (float)$grandTotal;
    $paidAmount = (float)$paidAmount;

    if ($grandTotal > 0 && $paidAmount >= $grandTotal) {
        return 'paid';
    }

    if ($paidAmount > 0 && $paidAmount < $grandTotal) {
        return 'partial';
    }

    return 'unpaid';
}

function paymentBadge($status): string
{
    if ($status === 'paid') return 'success';
    if ($status === 'partial') return 'warning';
    return 'danger';
}

function saleBadge($status): string
{
    if ($status === 'draft') return 'warning';
    if ($status === 'confirmed') return 'success';
    if ($status === 'cancelled') return 'danger';
    if ($status === 'delivered') return 'primary';
    return 'secondary';
}

function invoiceTypeBadge($type): string
{
    if ($type === 'vehicle_sale') return 'primary';
    if ($type === 'product_sale') return 'info';
    if ($type === 'mixed_sale') return 'dark';
    return 'secondary';
}

/* -------------------------------------------------------
   VALIDATE USER
------------------------------------------------------- */
$loggedUser = null;

if (tableExists($conn, 'business_users') && tableExists($conn, 'businesses')) {
    $stmt = $conn->prepare("
        SELECT bu.id, bu.full_name, bu.role, bu.status, b.business_name, b.status AS business_status
        FROM business_users bu
        INNER JOIN businesses b ON b.id = bu.business_id
        WHERE bu.id = ? AND bu.business_id = ?
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param('ii', $businessUserId, $businessId);
        $stmt->execute();
        $loggedUser = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}

if (!$loggedUser || (int)($loggedUser['status'] ?? 0) !== 1 || ($loggedUser['business_status'] ?? '') !== 'active') {
    session_destroy();
    header('Location: login.php');
    exit;
}

/* -------------------------------------------------------
   TABLE CHECKS
------------------------------------------------------- */
$requiredTables = ['sales_invoices', 'customers', 'branches'];
foreach ($requiredTables as $tbl) {
    if (!tableExists($conn, $tbl)) {
        die($tbl . ' table not found.');
    }
}

$hasSalesItems = tableExists($conn, 'sales_invoice_items');
$hasVehicleSales = tableExists($conn, 'vehicle_sales');

/* -------------------------------------------------------
   MASTER DATA
------------------------------------------------------- */
$branches = fetchAllAssoc($conn, "
    SELECT id, branch_name, branch_code
    FROM branches
    WHERE business_id = {$businessId}
    ORDER BY branch_name ASC
");

$customers = fetchAllAssoc($conn, "
    SELECT id, full_name, mobile
    FROM customers
    WHERE business_id = {$businessId}
    ORDER BY full_name ASC
");

/* -------------------------------------------------------
   FILTERS
------------------------------------------------------- */
$search = trim($_GET['search'] ?? '');
$branchFilter = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : 0;
$customerFilter = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;
$invoiceTypeFilter = trim($_GET['invoice_type'] ?? '');
$paymentStatusFilter = trim($_GET['payment_status'] ?? '');
$saleStatusFilter = trim($_GET['sale_status'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');

$allowedInvoiceTypes = ['vehicle_sale', 'product_sale', 'mixed_sale'];
$allowedPaymentStatuses = ['unpaid', 'partial', 'paid'];
$allowedSaleStatuses = ['draft', 'confirmed', 'cancelled', 'delivered'];

$where = ["si.business_id = {$businessId}"];

if ($search !== '') {
    $safe = $conn->real_escape_string($search);
    $where[] = "(
        si.invoice_no LIKE '%{$safe}%'
        OR c.full_name LIKE '%{$safe}%'
        OR c.mobile LIKE '%{$safe}%'
        OR br.branch_name LIKE '%{$safe}%'
        OR si.customer_note LIKE '%{$safe}%'
    )";
}

if ($branchFilter > 0) {
    $where[] = "si.branch_id = {$branchFilter}";
}

if ($customerFilter > 0) {
    $where[] = "si.customer_id = {$customerFilter}";
}

if ($invoiceTypeFilter !== '' && in_array($invoiceTypeFilter, $allowedInvoiceTypes, true)) {
    $safe = $conn->real_escape_string($invoiceTypeFilter);
    $where[] = "si.invoice_type = '{$safe}'";
}

if ($saleStatusFilter !== '' && in_array($saleStatusFilter, $allowedSaleStatuses, true)) {
    $safe = $conn->real_escape_string($saleStatusFilter);
    $where[] = "si.sale_status = '{$safe}'";
}

if ($dateFrom !== '') {
    $safe = $conn->real_escape_string($dateFrom);
    $where[] = "DATE(si.invoice_date) >= '{$safe}'";
}

if ($dateTo !== '') {
    $safe = $conn->real_escape_string($dateTo);
    $where[] = "DATE(si.invoice_date) <= '{$safe}'";
}

/*
|--------------------------------------------------------------------------
| Payment status is calculated from amount, not blindly from DB value.
|--------------------------------------------------------------------------
*/
if ($paymentStatusFilter !== '' && in_array($paymentStatusFilter, $allowedPaymentStatuses, true)) {
    if ($paymentStatusFilter === 'paid') {
        $where[] = "si.grand_total > 0 AND si.paid_amount >= si.grand_total";
    } elseif ($paymentStatusFilter === 'partial') {
        $where[] = "si.paid_amount > 0 AND si.paid_amount < si.grand_total";
    } elseif ($paymentStatusFilter === 'unpaid') {
        $where[] = "COALESCE(si.paid_amount, 0) <= 0";
    }
}

$whereSql = implode(' AND ', $where);

/* -------------------------------------------------------
   OVERALL SUMMARY - CORRECT CALCULATIONS
------------------------------------------------------- */
$summary = fetchOne($conn, "
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
            WHEN COALESCE(paid_amount, 0) <= 0 THEN 1 
            ELSE 0 
        END), 0) AS unpaid_count,

        COALESCE(SUM(CASE WHEN invoice_type = 'vehicle_sale' THEN 1 ELSE 0 END), 0) AS vehicle_count,
        COALESCE(SUM(CASE WHEN invoice_type = 'product_sale' THEN 1 ELSE 0 END), 0) AS product_count,
        COALESCE(SUM(CASE WHEN invoice_type = 'mixed_sale' THEN 1 ELSE 0 END), 0) AS mixed_count
    FROM sales_invoices
    WHERE business_id = {$businessId}
");

$totalInvoices = (int)($summary['total_invoices'] ?? 0);
$totalSales = (float)($summary['total_sales'] ?? 0);
$totalPaid = (float)($summary['total_paid'] ?? 0);
$totalBalance = (float)($summary['total_balance'] ?? 0);

$paidCount = (int)($summary['paid_count'] ?? 0);
$partialCount = (int)($summary['partial_count'] ?? 0);
$unpaidCount = (int)($summary['unpaid_count'] ?? 0);

$vehicleSaleCount = (int)($summary['vehicle_count'] ?? 0);
$productSaleCount = (int)($summary['product_count'] ?? 0);
$mixedSaleCount = (int)($summary['mixed_count'] ?? 0);

$today = date('Y-m-d');
$todaySummary = fetchOne($conn, "
    SELECT
        COUNT(*) AS total,
        COALESCE(SUM(grand_total), 0) AS amount
    FROM sales_invoices
    WHERE business_id = {$businessId}
    AND DATE(invoice_date) = '{$today}'
");

$todaySalesCount = (int)($todaySummary['total'] ?? 0);
$todaySalesAmount = (float)($todaySummary['amount'] ?? 0);

$thisMonthStart = date('Y-m-01');
$thisMonthEnd = date('Y-m-t');

$monthSummary = fetchOne($conn, "
    SELECT COALESCE(SUM(grand_total), 0) AS amount
    FROM sales_invoices
    WHERE business_id = {$businessId}
    AND DATE(invoice_date) BETWEEN '{$thisMonthStart}' AND '{$thisMonthEnd}'
");

$thisMonthSalesAmount = (float)($monthSummary['amount'] ?? 0);

/* -------------------------------------------------------
   FILTERED SUMMARY
------------------------------------------------------- */
$filteredSummary = fetchOne($conn, "
    SELECT
        COUNT(*) AS total_invoices,
        COALESCE(SUM(si.grand_total), 0) AS total_amount,
        COALESCE(SUM(si.paid_amount), 0) AS paid_amount,
        COALESCE(SUM(GREATEST(si.grand_total - si.paid_amount, 0)), 0) AS balance_amount
    FROM sales_invoices si
    LEFT JOIN branches br ON br.id = si.branch_id
    LEFT JOIN customers c ON c.id = si.customer_id
    WHERE {$whereSql}
");

$filteredInvoices = (int)($filteredSummary['total_invoices'] ?? 0);
$filteredAmount = (float)($filteredSummary['total_amount'] ?? 0);
$filteredPaid = (float)($filteredSummary['paid_amount'] ?? 0);
$filteredBalance = (float)($filteredSummary['balance_amount'] ?? 0);

/* -------------------------------------------------------
   INVOICE LIST
------------------------------------------------------- */
$invoiceRows = fetchAllAssoc($conn, "
    SELECT
        si.*,
        GREATEST(si.grand_total - si.paid_amount, 0) AS real_balance,
        CASE
            WHEN si.grand_total > 0 AND si.paid_amount >= si.grand_total THEN 'paid'
            WHEN si.paid_amount > 0 AND si.paid_amount < si.grand_total THEN 'partial'
            ELSE 'unpaid'
        END AS real_payment_status,
        br.branch_name,
        br.branch_code,
        c.full_name AS customer_name,
        c.mobile AS customer_mobile
    FROM sales_invoices si
    LEFT JOIN branches br ON br.id = si.branch_id
    LEFT JOIN customers c ON c.id = si.customer_id
    WHERE {$whereSql}
    ORDER BY si.id DESC
    LIMIT 500
");

/* -------------------------------------------------------
   ITEM SUMMARY - BASED ON FILTERS
------------------------------------------------------- */
$itemSummaryRows = [];

if ($hasSalesItems) {
    $itemSummaryRows = fetchAllAssoc($conn, "
        SELECT
            sii.item_type,
            COUNT(*) AS line_count,
            COALESCE(SUM(sii.qty), 0) AS total_qty,
            COALESCE(SUM(sii.taxable_value), 0) AS taxable_value,
            COALESCE(SUM(sii.cgst_amount + sii.sgst_amount + sii.igst_amount + sii.cess_amount), 0) AS total_tax,
            COALESCE(SUM(sii.line_total), 0) AS line_total
        FROM sales_invoice_items sii
        INNER JOIN sales_invoices si ON si.id = sii.invoice_id
        LEFT JOIN branches br ON br.id = si.branch_id
        LEFT JOIN customers c ON c.id = si.customer_id
        WHERE {$whereSql}
        GROUP BY sii.item_type
        ORDER BY sii.item_type ASC
    ");
}

/* -------------------------------------------------------
   VEHICLE SALE SUMMARY - BASED ON FILTERS
------------------------------------------------------- */
$vehicleSaleSummary = [
    'count' => 0,
    'ex_showroom_total' => 0.00,
    'insurance_total' => 0.00,
    'registration_total' => 0.00,
    'rto_total' => 0.00,
    'road_tax_total' => 0.00,
    'other_total' => 0.00,
    'grand_total' => 0.00,
];

if ($hasVehicleSales) {
    $vehicleRow = fetchOne($conn, "
        SELECT
            COUNT(vs.id) AS total,
            COALESCE(SUM(vs.ex_showroom_price), 0) AS ex_showroom_total,
            COALESCE(SUM(vs.insurance_amount), 0) AS insurance_total,
            COALESCE(SUM(vs.registration_amount), 0) AS registration_total,
            COALESCE(SUM(vs.rto_charge), 0) AS rto_total,
            COALESCE(SUM(vs.road_tax_amount), 0) AS road_tax_total,
            COALESCE(SUM(
                vs.hypothecation_charge +
                vs.handling_charge +
                vs.fastag_charge +
                vs.accessories_amount +
                vs.warranty_amount +
                vs.other_charges
            ), 0) AS other_total,
            COALESCE(SUM(vs.total_vehicle_amount), 0) AS grand_total
        FROM vehicle_sales vs
        INNER JOIN sales_invoices si ON si.id = vs.invoice_id
        LEFT JOIN branches br ON br.id = si.branch_id
        LEFT JOIN customers c ON c.id = si.customer_id
        WHERE {$whereSql}
    ");

    $vehicleSaleSummary['count'] = (int)($vehicleRow['total'] ?? 0);
    $vehicleSaleSummary['ex_showroom_total'] = (float)($vehicleRow['ex_showroom_total'] ?? 0);
    $vehicleSaleSummary['insurance_total'] = (float)($vehicleRow['insurance_total'] ?? 0);
    $vehicleSaleSummary['registration_total'] = (float)($vehicleRow['registration_total'] ?? 0);
    $vehicleSaleSummary['rto_total'] = (float)($vehicleRow['rto_total'] ?? 0);
    $vehicleSaleSummary['road_tax_total'] = (float)($vehicleRow['road_tax_total'] ?? 0);
    $vehicleSaleSummary['other_total'] = (float)($vehicleRow['other_total'] ?? 0);
    $vehicleSaleSummary['grand_total'] = (float)($vehicleRow['grand_total'] ?? 0);
}

/* -------------------------------------------------------
   EXPORT CSV
------------------------------------------------------- */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="sales_report_' . date('Y-m-d_H-i-s') . '.csv"');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    fputcsv($output, ['Sales Report']);
    fputcsv($output, ['Generated On:', date('d-m-Y H:i:s')]);
    fputcsv($output, []);

    fputcsv($output, [
        'S.No',
        'Invoice No',
        'Invoice Date',
        'Branch',
        'Branch Code',
        'Customer Name',
        'Customer Mobile',
        'Invoice Type',
        'Subtotal',
        'Discount',
        'Grand Total',
        'Paid Amount',
        'Balance Amount',
        'Payment Status',
        'Sale Status',
        'Customer Note'
    ]);

    $sno = 1;
    foreach ($invoiceRows as $row) {
        $realStatus = $row['real_payment_status'];
        $realBalance = (float)$row['real_balance'];

        fputcsv($output, [
            $sno++,
            $row['invoice_no'],
            (!empty($row['invoice_date']) && $row['invoice_date'] !== '0000-00-00 00:00:00') ? date('d-m-Y H:i:s', strtotime($row['invoice_date'])) : '-',
            $row['branch_name'] ?: '-',
            $row['branch_code'] ?: '-',
            $row['customer_name'] ?: '-',
            $row['customer_mobile'] ?: '-',
            ucwords(str_replace('_', ' ', (string)$row['invoice_type'])),
            number_format((float)$row['subtotal'], 2, '.', ''),
            number_format((float)$row['discount_amount'], 2, '.', ''),
            number_format((float)$row['grand_total'], 2, '.', ''),
            number_format((float)$row['paid_amount'], 2, '.', ''),
            number_format($realBalance, 2, '.', ''),
            ucfirst($realStatus),
            ucfirst((string)$row['sale_status']),
            $row['customer_note'] ?: ''
        ]);
    }

    fputcsv($output, []);
    fputcsv($output, ['SUMMARY']);
    fputcsv($output, ['Total Records', $filteredInvoices]);
    fputcsv($output, ['Total Sales Amount', number_format($filteredAmount, 2, '.', '')]);
    fputcsv($output, ['Total Paid Amount', number_format($filteredPaid, 2, '.', '')]);
    fputcsv($output, ['Total Balance Amount', number_format($filteredBalance, 2, '.', '')]);

    fclose($output);
    exit;
}

/* -------------------------------------------------------
   EXPORT URL
------------------------------------------------------- */
$queryParams = $_GET;
$queryParams['export'] = 'csv';
$exportUrl = 'sales-report.php?' . http_build_query($queryParams);

$pageTitle = 'Sales Report';
$currentPage = 'sales-report';
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
                    <div class="col-md-7">
                        <h4 class="mb-1">Sales Report</h4>
                        <p class="text-muted mb-0">Sales invoices, payment summary, vehicle sales and item totals</p>
                    </div>
                    <div class="col-md-5 text-md-end mt-3 mt-md-0">
                        <div class="btn-group">
                            <a href="sales-invoice-add.php" class="btn btn-primary">
                                <i class="mdi mdi-plus-circle"></i> Add Invoice
                            </a>
                            <a href="<?php echo h($exportUrl); ?>" class="btn btn-success">
                                <i class="mdi mdi-file-excel"></i> Export CSV
                            </a>
                            <a href="sales-invoices.php" class="btn btn-secondary">
                                <i class="mdi mdi-list-box"></i> View Invoices
                            </a>
                            <a href="index.php" class="btn btn-info">
                                <i class="mdi mdi-view-dashboard"></i> Dashboard
                            </a>
                        </div>
                    </div>
                </div>

                <!-- SUMMARY -->
                <div class="row">
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Total Invoices</p>
                                <h3 class="mb-0"><?php echo number_format($totalInvoices); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Total Sales</p>
                                <h3 class="mb-0 text-primary"><?php echo money($totalSales); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Total Paid</p>
                                <h3 class="mb-0 text-success"><?php echo money($totalPaid); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Total Balance</p>
                                <h3 class="mb-0 text-danger"><?php echo money($totalBalance); ?></h3>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- STATUS SUMMARY -->
                <div class="row">
                    <div class="col-md-2">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Paid</p>
                                <h4 class="mb-0 text-success"><?php echo number_format($paidCount); ?></h4>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-2">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Partial</p>
                                <h4 class="mb-0 text-warning"><?php echo number_format($partialCount); ?></h4>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-2">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Unpaid</p>
                                <h4 class="mb-0 text-danger"><?php echo number_format($unpaidCount); ?></h4>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-2">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Vehicle</p>
                                <h4 class="mb-0 text-primary"><?php echo number_format($vehicleSaleCount); ?></h4>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-2">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Product</p>
                                <h4 class="mb-0 text-info"><?php echo number_format($productSaleCount); ?></h4>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-2">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Mixed</p>
                                <h4 class="mb-0 text-dark"><?php echo number_format($mixedSaleCount); ?></h4>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- TODAY + MONTH -->
                <div class="row">
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Today Sales Count</p>
                                <h4 class="mb-0 text-info"><?php echo number_format($todaySalesCount); ?></h4>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Today Sales Amount</p>
                                <h4 class="mb-0 text-primary"><?php echo money($todaySalesAmount); ?></h4>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">This Month Sales</p>
                                <h4 class="mb-0 text-success"><?php echo money($thisMonthSalesAmount); ?></h4>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- FILTERS -->
                <div class="card">
                    <div class="card-body">
                        <form method="get" class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label">Search</label>
                                <input type="text" name="search" class="form-control" placeholder="Invoice no, customer, note..." value="<?php echo h($search); ?>">
                            </div>

                            <div class="col-md-2">
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

                            <div class="col-md-2">
                                <label class="form-label">Customer</label>
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
                                <label class="form-label">Invoice Type</label>
                                <select name="invoice_type" class="form-select">
                                    <option value="">All Types</option>
                                    <option value="vehicle_sale" <?php echo ($invoiceTypeFilter === 'vehicle_sale') ? 'selected' : ''; ?>>Vehicle Sale</option>
                                    <option value="product_sale" <?php echo ($invoiceTypeFilter === 'product_sale') ? 'selected' : ''; ?>>Product Sale</option>
                                    <option value="mixed_sale" <?php echo ($invoiceTypeFilter === 'mixed_sale') ? 'selected' : ''; ?>>Mixed Sale</option>
                                </select>
                            </div>

                            <div class="col-md-1">
                                <label class="form-label">Payment</label>
                                <select name="payment_status" class="form-select">
                                    <option value="">All</option>
                                    <option value="paid" <?php echo ($paymentStatusFilter === 'paid') ? 'selected' : ''; ?>>Paid</option>
                                    <option value="partial" <?php echo ($paymentStatusFilter === 'partial') ? 'selected' : ''; ?>>Partial</option>
                                    <option value="unpaid" <?php echo ($paymentStatusFilter === 'unpaid') ? 'selected' : ''; ?>>Unpaid</option>
                                </select>
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">Sale Status</label>
                                <select name="sale_status" class="form-select">
                                    <option value="">All Status</option>
                                    <option value="draft" <?php echo ($saleStatusFilter === 'draft') ? 'selected' : ''; ?>>Draft</option>
                                    <option value="confirmed" <?php echo ($saleStatusFilter === 'confirmed') ? 'selected' : ''; ?>>Confirmed</option>
                                    <option value="cancelled" <?php echo ($saleStatusFilter === 'cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                                    <option value="delivered" <?php echo ($saleStatusFilter === 'delivered') ? 'selected' : ''; ?>>Delivered</option>
                                </select>
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">Date From</label>
                                <input type="date" name="date_from" class="form-control" value="<?php echo h($dateFrom); ?>">
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">Date To</label>
                                <input type="date" name="date_to" class="form-control" value="<?php echo h($dateTo); ?>">
                            </div>

                            <div class="col-md-12 d-flex gap-2 flex-wrap">
                                <button type="submit" class="btn btn-primary">Filter</button>
                                <a href="sales-report.php" class="btn btn-light">Reset</a>
                                <span class="btn btn-outline-secondary disabled">
                                    Filtered: <?php echo number_format($filteredInvoices); ?> |
                                    Sales <?php echo money($filteredAmount); ?> |
                                    Paid <?php echo money($filteredPaid); ?> |
                                    Balance <?php echo money($filteredBalance); ?>
                                </span>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- INVOICE REPORT -->
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4">Sales Invoice Report</h4>

                        <div class="table-responsive">
                            <table class="table table-bordered table-striped align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Invoice</th>
                                        <th>Date</th>
                                        <th>Branch</th>
                                        <th>Customer</th>
                                        <th>Type</th>
                                        <th>Amounts</th>
                                        <th>Payment</th>
                                        <th>Sale Status</th>
                                        <th style="width: 210px;">Actions</th>
                                    </tr>
                                </thead>

                                <tbody>
                                    <?php if (!empty($invoiceRows)): ?>
                                        <?php $i = 1; foreach ($invoiceRows as $row): ?>
                                            <?php
                                            $realStatus = $row['real_payment_status'];
                                            $realBalance = (float)$row['real_balance'];
                                            ?>
                                            <tr>
                                                <td><?php echo $i++; ?></td>

                                                <td>
                                                    <div><strong><?php echo h($row['invoice_no']); ?></strong></div>
                                                    <?php if (!empty($row['customer_note'])): ?>
                                                        <div class="small text-muted"><?php echo h($row['customer_note']); ?></div>
                                                    <?php endif; ?>
                                                </td>

                                                <td>
                                                    <?php
                                                    echo (!empty($row['invoice_date']) && $row['invoice_date'] !== '0000-00-00 00:00:00')
                                                        ? h(date('d M Y h:i A', strtotime($row['invoice_date'])))
                                                        : '-';
                                                    ?>
                                                </td>

                                                <td>
                                                    <div><?php echo h($row['branch_name'] ?: '-'); ?></div>
                                                    <div class="small text-muted"><?php echo h($row['branch_code'] ?: '-'); ?></div>
                                                </td>

                                                <td>
                                                    <div><?php echo h($row['customer_name'] ?: '-'); ?></div>
                                                    <div class="small text-muted"><?php echo h($row['customer_mobile'] ?: '-'); ?></div>
                                                </td>

                                                <td>
                                                    <span class="badge bg-<?php echo invoiceTypeBadge($row['invoice_type']); ?>">
                                                        <?php echo h(ucwords(str_replace('_', ' ', (string)$row['invoice_type']))); ?>
                                                    </span>
                                                </td>

                                                <td class="small">
                                                    <div><strong>Subtotal:</strong> <?php echo money($row['subtotal']); ?></div>
                                                    <div><strong>Discount:</strong> <?php echo money($row['discount_amount']); ?></div>
                                                    <div><strong>Total:</strong> <?php echo money($row['grand_total']); ?></div>
                                                    <div><strong>Paid:</strong> <?php echo money($row['paid_amount']); ?></div>
                                                    <div><strong>Balance:</strong> <?php echo money($realBalance); ?></div>
                                                </td>

                                                <td>
                                                    <span class="badge bg-<?php echo paymentBadge($realStatus); ?>">
                                                        <?php echo h(ucfirst($realStatus)); ?>
                                                    </span>
                                                </td>

                                                <td>
                                                    <span class="badge bg-<?php echo saleBadge($row['sale_status']); ?>">
                                                        <?php echo h(ucfirst((string)$row['sale_status'])); ?>
                                                    </span>
                                                </td>

                                                <td>
                                                    <div class="d-flex flex-wrap gap-2">
                                                        <a href="sales-invoice-view.php?id=<?php echo (int)$row['id']; ?>" class="btn btn-sm btn-info">View</a>
                                                        <a href="sales-invoice-edit.php?id=<?php echo (int)$row['id']; ?>" class="btn btn-sm btn-primary">Edit</a>
                                                        <a href="sales-invoice-print.php?id=<?php echo (int)$row['id']; ?>" class="btn btn-sm btn-secondary" target="_blank">Print</a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="10" class="text-center text-muted py-4">No sales records found.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="mt-3 text-muted small">
                            Showing latest 500 records.
                        </div>
                    </div>
                </div>

                <div class="row">
                    <!-- ITEM SUMMARY -->
                    <div class="col-xl-6">
                        <div class="card h-100">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Sales Item Summary</h4>

                                <?php if ($hasSalesItems && !empty($itemSummaryRows)): ?>
                                    <div class="table-responsive">
                                        <table class="table table-bordered table-striped align-middle mb-0">
                                            <thead>
                                                <tr>
                                                    <th>Item Type</th>
                                                    <th>Lines</th>
                                                    <th>Qty</th>
                                                    <th>Taxable</th>
                                                    <th>Tax</th>
                                                    <th>Total</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($itemSummaryRows as $row): ?>
                                                    <tr>
                                                        <td><?php echo h(ucfirst((string)$row['item_type'])); ?></td>
                                                        <td><?php echo number_format((int)$row['line_count']); ?></td>
                                                        <td><?php echo qtyf($row['total_qty']); ?></td>
                                                        <td><?php echo money($row['taxable_value']); ?></td>
                                                        <td><?php echo money($row['total_tax']); ?></td>
                                                        <td><?php echo money($row['line_total']); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <div class="text-muted">No sales item summary available.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- VEHICLE SALE SUMMARY -->
                    <div class="col-xl-6">
                        <div class="card h-100">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Vehicle Sale Summary</h4>

                                <?php if ($hasVehicleSales): ?>
                                    <div class="table-responsive">
                                        <table class="table table-bordered table-striped mb-0">
                                            <tbody>
                                                <tr>
                                                    <th style="width:50%;">Vehicle Sales Count</th>
                                                    <td><?php echo number_format($vehicleSaleSummary['count']); ?></td>
                                                </tr>
                                                <tr>
                                                    <th>Ex-Showroom Total</th>
                                                    <td><?php echo money($vehicleSaleSummary['ex_showroom_total']); ?></td>
                                                </tr>
                                                <tr>
                                                    <th>Insurance Total</th>
                                                    <td><?php echo money($vehicleSaleSummary['insurance_total']); ?></td>
                                                </tr>
                                                <tr>
                                                    <th>Registration Total</th>
                                                    <td><?php echo money($vehicleSaleSummary['registration_total']); ?></td>
                                                </tr>
                                                <tr>
                                                    <th>RTO Charge Total</th>
                                                    <td><?php echo money($vehicleSaleSummary['rto_total']); ?></td>
                                                </tr>
                                                <tr>
                                                    <th>Road Tax Total</th>
                                                    <td><?php echo money($vehicleSaleSummary['road_tax_total']); ?></td>
                                                </tr>
                                                <tr>
                                                    <th>Other Charges Total</th>
                                                    <td><?php echo money($vehicleSaleSummary['other_total']); ?></td>
                                                </tr>
                                                <tr>
                                                    <th>Total Vehicle Amount</th>
                                                    <td><strong class="text-primary"><?php echo money($vehicleSaleSummary['grand_total']); ?></strong></td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <div class="text-muted">Vehicle sales table not available.</div>
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

</body>
</html>