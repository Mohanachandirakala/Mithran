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

function numf($amount): string
{
    return number_format((float)$amount, 2, '.', '');
}

function tableExists(mysqli $conn, string $table): bool
{
    $sql = "SELECT 1 FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            LIMIT 1";
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
    }
    return $rows;
}

function getPaymentStatusText($paymentStatus, $paidAmount, $grandTotal, $balanceAmount): string
{
    $status = strtolower(trim((string)$paymentStatus));

    if (in_array($status, ['paid', 'partial', 'unpaid'], true)) {
        return $status;
    }

    $paidAmount = (float)$paidAmount;
    $grandTotal = (float)$grandTotal;
    $balanceAmount = (float)$balanceAmount;

    if ($grandTotal > 0 && ($paidAmount >= $grandTotal || $balanceAmount <= 0)) {
        return 'paid';
    }

    if ($paidAmount > 0 && $paidAmount < $grandTotal) {
        return 'partial';
    }

    return 'unpaid';
}

function paymentBadgeClass(string $status): string
{
    if ($status === 'paid') return 'success';
    if ($status === 'partial') return 'warning';
    if ($status === 'unpaid') return 'danger';
    return 'secondary';
}

function exportCsv(string $filename, array $headers, array $rows): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($out, $headers);

    foreach ($rows as $row) {
        fputcsv($out, $row);
    }

    fclose($out);
    exit;
}

function exportExcelHtml(string $filename, array $headers, array $rows): void
{
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    echo '<table border="1">';
    echo '<tr>';
    foreach ($headers as $header) {
        echo '<th>' . h($header) . '</th>';
    }
    echo '</tr>';

    foreach ($rows as $row) {
        echo '<tr>';
        foreach ($row as $cell) {
            echo '<td>' . h((string)$cell) . '</td>';
        }
        echo '</tr>';
    }

    echo '</table>';
    exit;
}

/* -------------------------------------------------------
   VALIDATE USER
------------------------------------------------------- */
if (!tableExists($conn, 'business_users') || !tableExists($conn, 'businesses')) {
    die('Required tables not found.');
}

$loggedUser = null;
$stmt = $conn->prepare("
    SELECT
        bu.id,
        bu.full_name,
        bu.role,
        bu.status,
        b.business_name,
        b.status AS business_status
    FROM business_users bu
    INNER JOIN businesses b ON b.id = bu.business_id
    WHERE bu.id = ?
    AND bu.business_id = ?
    LIMIT 1
");
if ($stmt) {
    $stmt->bind_param('ii', $businessUserId, $businessId);
    $stmt->execute();
    $loggedUser = $stmt->get_result()->fetch_assoc();
    $stmt->close();
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
$hasSalesInvoices = tableExists($conn, 'sales_invoices');
$hasServiceInvoices = tableExists($conn, 'service_invoices');

if (!$hasBranches) {
    die('branches table not found.');
}

if (!$hasSalesInvoices && !$hasServiceInvoices) {
    die('No invoice tables found.');
}

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
   FILTERS
------------------------------------------------------- */
$search = trim($_GET['search'] ?? '');
$branchFilter = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : 0;
$reportType = trim($_GET['report_type'] ?? 'all');
$invoiceTypeFilter = trim($_GET['invoice_type'] ?? '');
$paymentStatusFilter = trim($_GET['payment_status'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? date('Y-m-01'));
$dateTo = trim($_GET['date_to'] ?? date('Y-m-t'));
$export = trim($_GET['export'] ?? '');

$allowedReportTypes = ['all', 'sales', 'service'];
$allowedInvoiceTypes = ['vehicle_sale', 'product_sale', 'mixed_sale'];
$allowedPaymentStatuses = ['unpaid', 'partial', 'paid'];

if (!in_array($reportType, $allowedReportTypes, true)) {
    $reportType = 'all';
}

if ($invoiceTypeFilter !== '' && !in_array($invoiceTypeFilter, $allowedInvoiceTypes, true)) {
    $invoiceTypeFilter = '';
}

if ($paymentStatusFilter !== '' && !in_array($paymentStatusFilter, $allowedPaymentStatuses, true)) {
    $paymentStatusFilter = '';
}

if ($dateFrom !== '' && $dateTo !== '' && strtotime($dateFrom) > strtotime($dateTo)) {
    $tmp = $dateFrom;
    $dateFrom = $dateTo;
    $dateTo = $tmp;
}

/* -------------------------------------------------------
   BUILD UNION QUERY
------------------------------------------------------- */
$unionParts = [];

/* SALES */
if ($hasSalesInvoices && ($reportType === 'all' || $reportType === 'sales')) {
    $salesWhere = ["si.business_id = {$businessId}"];

    if ($branchFilter > 0) {
        $salesWhere[] = "si.branch_id = {$branchFilter}";
    }

    if ($invoiceTypeFilter !== '') {
        $safe = $conn->real_escape_string($invoiceTypeFilter);
        $salesWhere[] = "si.invoice_type = '{$safe}'";
    }

    if ($paymentStatusFilter !== '') {
        $safe = $conn->real_escape_string($paymentStatusFilter);
        $salesWhere[] = "LOWER(si.payment_status) = '{$safe}'";
    }

    if ($dateFrom !== '') {
        $safe = $conn->real_escape_string($dateFrom);
        $salesWhere[] = "DATE(si.invoice_date) >= '{$safe}'";
    }

    if ($dateTo !== '') {
        $safe = $conn->real_escape_string($dateTo);
        $salesWhere[] = "DATE(si.invoice_date) <= '{$safe}'";
    }

    if ($search !== '') {
        $safe = $conn->real_escape_string($search);
        $salesWhere[] = "(
            si.invoice_no LIKE '%{$safe}%'
            OR c.full_name LIKE '%{$safe}%'
            OR c.mobile LIKE '%{$safe}%'
            OR br.branch_name LIKE '%{$safe}%'
            OR br.branch_code LIKE '%{$safe}%'
        )";
    }

    $salesWhereSql = implode(' AND ', $salesWhere);

    $unionParts[] = "
        SELECT
            'Sales' AS report_source,
            si.id,
            si.invoice_no,
            si.invoice_date,
            br.branch_name,
            br.branch_code,
            c.full_name AS customer_name,
            c.mobile AS customer_mobile,
            si.invoice_type,
            si.payment_status,
            si.paid_amount,
            si.balance_amount,
            si.subtotal,
            si.discount_amount,
            CASE
                WHEN si.subtotal > 0 THEN si.subtotal
                ELSE (
                    SELECT COALESCE(SUM(sii.taxable_value), 0)
                    FROM sales_invoice_items sii
                    WHERE sii.invoice_id = si.id
                )
            END AS taxable_value,
            si.cgst_amount,
            si.sgst_amount,
            si.igst_amount,
            si.cess_amount,
            si.round_off,
            si.grand_total
        FROM sales_invoices si
        LEFT JOIN branches br ON br.id = si.branch_id
        LEFT JOIN customers c ON c.id = si.customer_id
        WHERE {$salesWhereSql}
    ";
}

/* SERVICE */
if ($hasServiceInvoices && ($reportType === 'all' || $reportType === 'service')) {
    $serviceWhere = ["si.business_id = {$businessId}"];

    if ($branchFilter > 0) {
        $serviceWhere[] = "si.branch_id = {$branchFilter}";
    }

    if ($paymentStatusFilter !== '') {
        $safe = $conn->real_escape_string($paymentStatusFilter);
        $serviceWhere[] = "LOWER(si.payment_status) = '{$safe}'";
    }

    if ($dateFrom !== '') {
        $safe = $conn->real_escape_string($dateFrom);
        $serviceWhere[] = "DATE(si.invoice_date) >= '{$safe}'";
    }

    if ($dateTo !== '') {
        $safe = $conn->real_escape_string($dateTo);
        $serviceWhere[] = "DATE(si.invoice_date) <= '{$safe}'";
    }

    if ($search !== '') {
        $safe = $conn->real_escape_string($search);
        $serviceWhere[] = "(
            si.invoice_no LIKE '%{$safe}%'
            OR c.full_name LIKE '%{$safe}%'
            OR c.mobile LIKE '%{$safe}%'
            OR br.branch_name LIKE '%{$safe}%'
            OR br.branch_code LIKE '%{$safe}%'
        )";
    }

    $serviceWhereSql = implode(' AND ', $serviceWhere);

    $unionParts[] = "
        SELECT
            'Service' AS report_source,
            si.id,
            si.invoice_no,
            si.invoice_date,
            br.branch_name,
            br.branch_code,
            c.full_name AS customer_name,
            c.mobile AS customer_mobile,
            'service_invoice' AS invoice_type,
            si.payment_status,
            si.paid_amount,
            si.balance_amount,
            si.subtotal,
            si.discount_amount,
            si.subtotal AS taxable_value,
            si.cgst_amount,
            si.sgst_amount,
            si.igst_amount,
            0.00 AS cess_amount,
            0.00 AS round_off,
            si.grand_total
        FROM service_invoices si
        LEFT JOIN branches br ON br.id = si.branch_id
        LEFT JOIN customers c ON c.id = si.customer_id
        WHERE {$serviceWhereSql}
    ";
}

$rows = [];
if (!empty($unionParts)) {
    $mainSql = implode(" UNION ALL ", $unionParts) . " ORDER BY invoice_date DESC, id DESC";
    $rows = fetchAllAssoc($conn, $mainSql);
}

/* -------------------------------------------------------
   SUMMARY
------------------------------------------------------- */
$totalInvoices = count($rows);
$totalSubtotal = 0.00;
$totalDiscount = 0.00;
$totalTaxable = 0.00;
$totalCgst = 0.00;
$totalSgst = 0.00;
$totalIgst = 0.00;
$totalCess = 0.00;
$totalRoundOff = 0.00;
$totalGrand = 0.00;
$totalPaid = 0.00;
$totalBalance = 0.00;

$paidCount = 0;
$partialCount = 0;
$unpaidCount = 0;

foreach ($rows as $row) {
    $grand = (float)($row['grand_total'] ?? 0);
    $paid = (float)($row['paid_amount'] ?? 0);
    $balance = (float)($row['balance_amount'] ?? ($grand - $paid));
    if ($balance < 0) $balance = 0;

    $payStatus = getPaymentStatusText($row['payment_status'] ?? '', $paid, $grand, $balance);

    if ($payStatus === 'paid') {
        $paidCount++;
    } elseif ($payStatus === 'partial') {
        $partialCount++;
    } else {
        $unpaidCount++;
    }

    $totalSubtotal += (float)($row['subtotal'] ?? 0);
    $totalDiscount += (float)($row['discount_amount'] ?? 0);
    $totalTaxable += (float)($row['taxable_value'] ?? 0);
    $totalCgst += (float)($row['cgst_amount'] ?? 0);
    $totalSgst += (float)($row['sgst_amount'] ?? 0);
    $totalIgst += (float)($row['igst_amount'] ?? 0);
    $totalCess += (float)($row['cess_amount'] ?? 0);
    $totalRoundOff += (float)($row['round_off'] ?? 0);
    $totalGrand += $grand;
    $totalPaid += $paid;
    $totalBalance += $balance;
}

/* -------------------------------------------------------
   EXPORT
------------------------------------------------------- */
$exportHeaders = [
    'Source',
    'Invoice No',
    'Invoice Date',
    'Branch',
    'Branch Code',
    'Customer',
    'Mobile',
    'Invoice Type',
    'Payment Status',
    'Paid Amount',
    'Balance Amount',
    'Subtotal',
    'Discount',
    'Taxable Value',
    'CGST',
    'SGST',
    'IGST',
    'CESS',
    'Round Off',
    'Grand Total'
];

$exportRows = [];

foreach ($rows as $row) {
    $grand = (float)($row['grand_total'] ?? 0);
    $paid = (float)($row['paid_amount'] ?? 0);
    $balance = (float)($row['balance_amount'] ?? ($grand - $paid));
    if ($balance < 0) $balance = 0;

    $payStatus = getPaymentStatusText($row['payment_status'] ?? '', $paid, $grand, $balance);

    $exportRows[] = [
        $row['report_source'] ?? '',
        $row['invoice_no'] ?? '',
        !empty($row['invoice_date']) ? date('d-m-Y H:i:s', strtotime($row['invoice_date'])) : '',
        $row['branch_name'] ?? '',
        $row['branch_code'] ?? '',
        $row['customer_name'] ?? '',
        $row['customer_mobile'] ?? '',
        $row['invoice_type'] ?? '',
        ucfirst($payStatus),
        numf($paid),
        numf($balance),
        numf($row['subtotal'] ?? 0),
        numf($row['discount_amount'] ?? 0),
        numf($row['taxable_value'] ?? 0),
        numf($row['cgst_amount'] ?? 0),
        numf($row['sgst_amount'] ?? 0),
        numf($row['igst_amount'] ?? 0),
        numf($row['cess_amount'] ?? 0),
        numf($row['round_off'] ?? 0),
        numf($row['grand_total'] ?? 0),
    ];
}

$exportRows[] = [
    'TOTAL',
    '',
    '',
    '',
    '',
    '',
    '',
    '',
    '',
    numf($totalPaid),
    numf($totalBalance),
    numf($totalSubtotal),
    numf($totalDiscount),
    numf($totalTaxable),
    numf($totalCgst),
    numf($totalSgst),
    numf($totalIgst),
    numf($totalCess),
    numf($totalRoundOff),
    numf($totalGrand),
];

if ($export === 'csv') {
    exportCsv('gst-report-' . date('Ymd-His') . '.csv', $exportHeaders, $exportRows);
}

if ($export === 'excel') {
    exportExcelHtml('gst-report-' . date('Ymd-His') . '.xls', $exportHeaders, $exportRows);
}

/* -------------------------------------------------------
   PAGE
------------------------------------------------------- */
$pageTitle = 'GST Report';
$currentPage = 'gst-report';

$queryStringBase = http_build_query([
    'search' => $search,
    'branch_id' => $branchFilter,
    'report_type' => $reportType,
    'invoice_type' => $invoiceTypeFilter,
    'payment_status' => $paymentStatusFilter,
    'date_from' => $dateFrom,
    'date_to' => $dateTo
]);
?>
<!doctype html>
<html lang="en">

<?php include('includes/head.php'); ?>

<body data-sidebar="dark">

<style>
    .page-content {
        padding-bottom: 90px !important;
    }

    .report-last-row {
        margin-bottom: 40px;
    }

    .card {
        margin-bottom: 24px;
    }

    .table-responsive {
        overflow-x: auto;
    }

    .main-content {
        min-height: calc(100vh - 70px);
    }

    .summary-card h4 {
        font-size: 18px;
        font-weight: 700;
    }

    .badge {
        font-size: 11px;
        padding: 6px 9px;
    }

    @media print {
        .no-print,
        .vertical-menu,
        .topbar,
        .page-title-box,
        footer,
        #right-bar,
        .right-bar,
        .btn,
        form {
            display: none !important;
        }

        .main-content,
        .page-content,
        .container-fluid {
            margin: 0 !important;
            padding: 0 !important;
            width: 100% !important;
        }

        .card {
            border: 1px solid #ddd !important;
            box-shadow: none !important;
        }
    }
</style>

<?php include('includes/pre-loader.php'); ?>

<div id="layout-wrapper">

    <?php include('includes/topbar.php'); ?>

    <div class="vertical-menu no-print">
        <div data-simplebar class="h-100">
            <?php include('includes/sidebar.php'); ?>
        </div>
    </div>

    <div class="main-content">
        <div class="page-content">
            <div class="container-fluid">

                <div class="row mb-3 no-print">
                    <div class="col-md-6">
                        <h4 class="mb-1">GST Report</h4>
                        <p class="text-muted mb-0">Sales and service GST summary with correct payment status</p>
                    </div>

                    <div class="col-md-6 text-md-end mt-3 mt-md-0">
                        <a href="?<?php echo h($queryStringBase . '&export=csv'); ?>" class="btn btn-success me-2">Export CSV</a>
                        <a href="?<?php echo h($queryStringBase . '&export=excel'); ?>" class="btn btn-primary me-2">Export Excel</a>
                        <a href="gst-report-print.php?<?php echo h($queryStringBase . '&auto_print=1'); ?>" class="btn btn-secondary me-2" target="_blank">Print</a>
                    </div>
                </div>

                <!-- GST SUMMARY -->
                <div class="row">
                    <div class="col-md-2">
                        <div class="card summary-card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Invoices</p>
                                <h4 class="mb-0"><?php echo number_format($totalInvoices); ?></h4>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-2">
                        <div class="card summary-card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Taxable</p>
                                <h4 class="mb-0 text-primary"><?php echo money($totalTaxable); ?></h4>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-2">
                        <div class="card summary-card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">CGST</p>
                                <h4 class="mb-0 text-success"><?php echo money($totalCgst); ?></h4>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-2">
                        <div class="card summary-card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">SGST</p>
                                <h4 class="mb-0 text-success"><?php echo money($totalSgst); ?></h4>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-2">
                        <div class="card summary-card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">IGST</p>
                                <h4 class="mb-0 text-warning"><?php echo money($totalIgst); ?></h4>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-2">
                        <div class="card summary-card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">CESS</p>
                                <h4 class="mb-0 text-danger"><?php echo money($totalCess); ?></h4>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- AMOUNT SUMMARY -->
                <div class="row">
                    <div class="col-md-3">
                        <div class="card summary-card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Subtotal</p>
                                <h4 class="mb-0"><?php echo money($totalSubtotal); ?></h4>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card summary-card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Discount</p>
                                <h4 class="mb-0 text-danger"><?php echo money($totalDiscount); ?></h4>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card summary-card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Grand Total</p>
                                <h4 class="mb-0 text-primary"><?php echo money($totalGrand); ?></h4>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card summary-card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Round Off</p>
                                <h4 class="mb-0 text-info"><?php echo money($totalRoundOff); ?></h4>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- PAYMENT SUMMARY -->
                <div class="row">
                    <div class="col-md-2">
                        <div class="card summary-card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Paid Count</p>
                                <h4 class="mb-0 text-success"><?php echo number_format($paidCount); ?></h4>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-2">
                        <div class="card summary-card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Partial Count</p>
                                <h4 class="mb-0 text-warning"><?php echo number_format($partialCount); ?></h4>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-2">
                        <div class="card summary-card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Unpaid Count</p>
                                <h4 class="mb-0 text-danger"><?php echo number_format($unpaidCount); ?></h4>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card summary-card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Paid Amount</p>
                                <h4 class="mb-0 text-success"><?php echo money($totalPaid); ?></h4>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card summary-card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Balance Amount</p>
                                <h4 class="mb-0 text-danger"><?php echo money($totalBalance); ?></h4>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- FILTERS -->
                <div class="card no-print">
                    <div class="card-body">
                        <form method="get" class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label">Search</label>
                                <input type="text" name="search" class="form-control" placeholder="Invoice, customer, branch..." value="<?php echo h($search); ?>">
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
                                <label class="form-label">Report Type</label>
                                <select name="report_type" class="form-select">
                                    <option value="all" <?php echo ($reportType === 'all') ? 'selected' : ''; ?>>All</option>
                                    <option value="sales" <?php echo ($reportType === 'sales') ? 'selected' : ''; ?>>Sales Only</option>
                                    <option value="service" <?php echo ($reportType === 'service') ? 'selected' : ''; ?>>Service Only</option>
                                </select>
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">Invoice Type</label>
                                <select name="invoice_type" class="form-select">
                                    <option value="">All</option>
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

                            <div class="col-md-1">
                                <label class="form-label">From</label>
                                <input type="date" name="date_from" class="form-control" value="<?php echo h($dateFrom); ?>">
                            </div>

                            <div class="col-md-1">
                                <label class="form-label">To</label>
                                <input type="date" name="date_to" class="form-control" value="<?php echo h($dateTo); ?>">
                            </div>

                            <div class="col-md-12 d-flex gap-2">
                                <button type="submit" class="btn btn-primary">Filter</button>
                                <a href="gst-report.php" class="btn btn-light">Reset</a>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- TABLE -->
                <div class="card report-last-row">
                    <div class="card-body">
                        <h4 class="card-title mb-4">GST Invoice Report</h4>

                        <div class="table-responsive">
                            <table class="table table-bordered table-striped align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Source</th>
                                        <th>Invoice</th>
                                        <th>Date</th>
                                        <th>Branch</th>
                                        <th>Customer</th>
                                        <th>Type</th>
                                        <th>Payment Status</th>
                                        <th>Paid</th>
                                        <th>Balance</th>
                                        <th>Taxable</th>
                                        <th>CGST</th>
                                        <th>SGST</th>
                                        <th>IGST</th>
                                        <th>CESS</th>
                                        <th>Total</th>
                                    </tr>
                                </thead>

                                <tbody>
                                    <?php if (!empty($rows)): ?>
                                        <?php $i = 1; foreach ($rows as $row): ?>
                                            <?php
                                            $grand = (float)($row['grand_total'] ?? 0);
                                            $paid = (float)($row['paid_amount'] ?? 0);
                                            $balance = (float)($row['balance_amount'] ?? ($grand - $paid));
                                            if ($balance < 0) $balance = 0;

                                            $payStatus = getPaymentStatusText($row['payment_status'] ?? '', $paid, $grand, $balance);
                                            $payBadge = paymentBadgeClass($payStatus);
                                            ?>
                                            <tr>
                                                <td><?php echo $i++; ?></td>

                                                <td>
                                                    <?php if (($row['report_source'] ?? '') === 'Sales'): ?>
                                                        <span class="badge bg-primary">Sales</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-success">Service</span>
                                                    <?php endif; ?>
                                                </td>

                                                <td>
                                                    <strong><?php echo h($row['invoice_no'] ?? '-'); ?></strong>
                                                </td>

                                                <td>
                                                    <?php echo !empty($row['invoice_date']) ? h(date('d M Y h:i A', strtotime($row['invoice_date']))) : '-'; ?>
                                                </td>

                                                <td>
                                                    <div><?php echo h($row['branch_name'] ?? '-'); ?></div>
                                                    <div class="small text-muted"><?php echo h($row['branch_code'] ?? '-'); ?></div>
                                                </td>

                                                <td>
                                                    <div><?php echo h($row['customer_name'] ?? '-'); ?></div>
                                                    <div class="small text-muted"><?php echo h($row['customer_mobile'] ?? '-'); ?></div>
                                                </td>

                                                <td>
                                                    <?php echo h(ucwords(str_replace('_', ' ', (string)($row['invoice_type'] ?? '-')))); ?>
                                                </td>

                                                <td>
                                                    <span class="badge bg-<?php echo $payBadge; ?>">
                                                        <?php echo h(ucfirst($payStatus)); ?>
                                                    </span>
                                                </td>

                                                <td><?php echo money($paid); ?></td>
                                                <td><?php echo money($balance); ?></td>
                                                <td><?php echo money($row['taxable_value'] ?? 0); ?></td>
                                                <td><?php echo money($row['cgst_amount'] ?? 0); ?></td>
                                                <td><?php echo money($row['sgst_amount'] ?? 0); ?></td>
                                                <td><?php echo money($row['igst_amount'] ?? 0); ?></td>
                                                <td><?php echo money($row['cess_amount'] ?? 0); ?></td>
                                                <td><strong><?php echo money($row['grand_total'] ?? 0); ?></strong></td>
                                            </tr>
                                        <?php endforeach; ?>

                                        <tr class="table-dark">
                                            <td colspan="8" class="text-end"><strong>Total</strong></td>
                                            <td><strong><?php echo money($totalPaid); ?></strong></td>
                                            <td><strong><?php echo money($totalBalance); ?></strong></td>
                                            <td><strong><?php echo money($totalTaxable); ?></strong></td>
                                            <td><strong><?php echo money($totalCgst); ?></strong></td>
                                            <td><strong><?php echo money($totalSgst); ?></strong></td>
                                            <td><strong><?php echo money($totalIgst); ?></strong></td>
                                            <td><strong><?php echo money($totalCess); ?></strong></td>
                                            <td><strong><?php echo money($totalGrand); ?></strong></td>
                                        </tr>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="16" class="text-center text-muted py-4">No GST records found.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="mt-3 text-muted small">
                            Showing filtered GST report records with payment status from DB.
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