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
    if ((int)$qty == $qty) {
        return number_format($qty, 0);
    }
    return number_format($qty, 2);
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

function getCount(mysqli $conn, string $table, string $where = '1=1'): int
{
    $sql = "SELECT COUNT(*) AS total FROM {$table} WHERE {$where}";
    $res = $conn->query($sql);
    if (!$res) {
        return 0;
    }
    $row = $res->fetch_assoc();
    return (int)($row['total'] ?? 0);
}

function getSum(mysqli $conn, string $table, string $field, string $where = '1=1'): float
{
    $sql = "SELECT COALESCE(SUM({$field}),0) AS total FROM {$table} WHERE {$where}";
    $res = $conn->query($sql);
    if (!$res) {
        return 0;
    }
    $row = $res->fetch_assoc();
    return (float)($row['total'] ?? 0);
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

/* -------------------------------------------------------
   EXPORT TO CSV
------------------------------------------------------- */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    // Get filter parameters for export
    $exportSearch = trim($_GET['search'] ?? '');
    $exportBranchFilter = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : 0;
    $exportCustomerFilter = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;
    $exportJobStatusFilter = trim($_GET['job_status'] ?? '');
    $exportPaymentStatusFilter = trim($_GET['payment_status'] ?? '');
    $exportInvoiceStatusFilter = trim($_GET['invoice_status'] ?? '');
    $exportDateFrom = trim($_GET['date_from'] ?? '');
    $exportDateTo = trim($_GET['date_to'] ?? '');
    
    $allowedJobStatuses = ['open', 'in_progress', 'waiting_parts', 'ready', 'delivered', 'cancelled'];
    $allowedPaymentStatuses = ['unpaid', 'partial', 'paid'];
    $allowedInvoiceStatuses = ['draft', 'confirmed', 'cancelled'];
    
    $hasServiceInvoices = tableExists($conn, 'service_invoices');
    $hasBrands = tableExists($conn, 'vehicle_brands');
    $hasModels = tableExists($conn, 'vehicle_models');
    
    $exportWhere = ["sj.business_id = {$businessId}"];
    
    if ($exportSearch !== '') {
        $safe = $conn->real_escape_string($exportSearch);
        $exportWhere[] = "(
            sj.jobcard_no LIKE '%{$safe}%'
            OR c.full_name LIKE '%{$safe}%'
            OR c.mobile LIKE '%{$safe}%'
            OR br.branch_name LIKE '%{$safe}%'
            OR cv.registration_no LIKE '%{$safe}%'
            OR cv.chassis_no LIKE '%{$safe}%'
        )";
    }
    
    if ($exportBranchFilter > 0) {
        $exportWhere[] = "sj.branch_id = {$exportBranchFilter}";
    }
    
    if ($exportCustomerFilter > 0) {
        $exportWhere[] = "sj.customer_id = {$exportCustomerFilter}";
    }
    
    if ($exportJobStatusFilter !== '' && in_array($exportJobStatusFilter, $allowedJobStatuses, true)) {
        $safe = $conn->real_escape_string($exportJobStatusFilter);
        $exportWhere[] = "sj.job_status = '{$safe}'";
    }
    
    if ($hasServiceInvoices && $exportPaymentStatusFilter !== '' && in_array($exportPaymentStatusFilter, $allowedPaymentStatuses, true)) {
        $safe = $conn->real_escape_string($exportPaymentStatusFilter);
        $exportWhere[] = "si.payment_status = '{$safe}'";
    }
    
    if ($hasServiceInvoices && $exportInvoiceStatusFilter !== '' && in_array($exportInvoiceStatusFilter, $allowedInvoiceStatuses, true)) {
        $safe = $conn->real_escape_string($exportInvoiceStatusFilter);
        $exportWhere[] = "si.invoice_status = '{$safe}'";
    }
    
    if ($exportDateFrom !== '') {
        $safe = $conn->real_escape_string($exportDateFrom);
        $exportWhere[] = "DATE(sj.service_date) >= '{$safe}'";
    }
    
    if ($exportDateTo !== '') {
        $safe = $conn->real_escape_string($exportDateTo);
        $exportWhere[] = "DATE(sj.service_date) <= '{$safe}'";
    }
    
    $exportWhereSql = implode(' AND ', $exportWhere);
    
    $exportRows = fetchAllAssoc(
        $conn,
        "SELECT
            sj.jobcard_no,
            sj.service_date,
            sj.opening_km,
            sj.promised_delivery,
            br.branch_name,
            br.branch_code,
            c.full_name AS customer_name,
            c.mobile AS customer_mobile,
            cv.registration_no,
            cv.chassis_no,
            cv.color,
            " . ($hasBrands ? "vb.brand_name" : "'' AS brand_name") . ",
            " . ($hasModels ? "vm.model_name, vm.variant_name" : "'' AS model_name, '' AS variant_name") . ",
            sj.job_status,
            sj.estimated_amount,
            sj.final_amount,
            " . ($hasServiceInvoices ? "si.invoice_no, si.grand_total, si.paid_amount, si.balance_amount, si.payment_status, si.invoice_status" : "'' AS invoice_no, 0 AS grand_total, 0 AS paid_amount, 0 AS balance_amount, '' AS payment_status, '' AS invoice_status") . "
         FROM service_job_cards sj
         LEFT JOIN branches br ON br.id = sj.branch_id
         LEFT JOIN customers c ON c.id = sj.customer_id
         LEFT JOIN customer_vehicles cv ON cv.id = sj.customer_vehicle_id
         " . ($hasBrands ? "LEFT JOIN vehicle_brands vb ON vb.id = cv.brand_id" : "") . "
         " . ($hasModels ? "LEFT JOIN vehicle_models vm ON vm.id = cv.model_id" : "") . "
         " . ($hasServiceInvoices ? "LEFT JOIN service_invoices si ON si.jobcard_id = sj.id" : "") . "
         WHERE {$exportWhereSql}
         ORDER BY sj.id DESC"
    );
    
    // Set headers for CSV download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="service_report_' . date('Y-m-d_H-i-s') . '.csv"');
    
    // Create output stream
    $output = fopen('php://output', 'w');
    
    // Add UTF-8 BOM for Excel compatibility
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Add report header
    fputcsv($output, ['Service Report']);
    fputcsv($output, ['Generated On: ' . date('d-m-Y H:i:s')]);
    fputcsv($output, []);
    
    // Add filters info if applied
    $filters = [];
    if ($exportSearch) $filters[] = 'Search: ' . $exportSearch;
    if ($exportBranchFilter > 0) {
        $branchName = '';
        foreach ($branches as $b) {
            if ($b['id'] == $exportBranchFilter) {
                $branchName = $b['branch_name'];
                break;
            }
        }
        if ($branchName) $filters[] = 'Branch: ' . $branchName;
    }
    if ($exportCustomerFilter > 0) {
        $customerName = '';
        foreach ($customers as $c) {
            if ($c['id'] == $exportCustomerFilter) {
                $customerName = $c['full_name'];
                break;
            }
        }
        if ($customerName) $filters[] = 'Customer: ' . $customerName;
    }
    if ($exportJobStatusFilter) $filters[] = 'Job Status: ' . ucwords(str_replace('_', ' ', $exportJobStatusFilter));
    if ($exportPaymentStatusFilter) $filters[] = 'Payment Status: ' . ucfirst($exportPaymentStatusFilter);
    if ($exportInvoiceStatusFilter) $filters[] = 'Invoice Status: ' . ucfirst($exportInvoiceStatusFilter);
    if ($exportDateFrom) $filters[] = 'From Date: ' . $exportDateFrom;
    if ($exportDateTo) $filters[] = 'To Date: ' . $exportDateTo;
    
    if (!empty($filters)) {
        fputcsv($output, ['Applied Filters:']);
        foreach ($filters as $filter) {
            fputcsv($output, ['- ' . $filter]);
        }
        fputcsv($output, []);
    }
    
    // Add column headers
    fputcsv($output, [
        'S.No',
        'Job Card No',
        'Service Date',
        'Opening KM',
        'Promised Delivery',
        'Branch',
        'Branch Code',
        'Customer Name',
        'Customer Mobile',
        'Registration No',
        'Chassis No',
        'Color',
        'Brand',
        'Model',
        'Variant',
        'Job Status',
        'Estimated Amount (₹)',
        'Final Amount (₹)',
        'Invoice No',
        'Invoice Total (₹)',
        'Paid Amount (₹)',
        'Balance Amount (₹)',
        'Payment Status',
        'Invoice Status'
    ]);
    
    // Add data rows
    $sno = 1;
    foreach ($exportRows as $row) {
        fputcsv($output, [
            $sno++,
            $row['jobcard_no'],
            date('d-m-Y H:i:s', strtotime($row['service_date'])),
            $row['opening_km'] ?? '0',
            !empty($row['promised_delivery']) ? date('d-m-Y H:i:s', strtotime($row['promised_delivery'])) : '-',
            $row['branch_name'] ?: '-',
            $row['branch_code'] ?: '-',
            $row['customer_name'] ?: '-',
            $row['customer_mobile'] ?: '-',
            $row['registration_no'] ?: '-',
            $row['chassis_no'] ?: '-',
            $row['color'] ?: '-',
            $row['brand_name'] ?: '-',
            $row['model_name'] ?: '-',
            $row['variant_name'] ?: '-',
            ucwords(str_replace('_', ' ', $row['job_status'])),
            number_format($row['estimated_amount'], 2),
            number_format($row['final_amount'], 2),
            $row['invoice_no'] ?: '-',
            number_format($row['grand_total'], 2),
            number_format($row['paid_amount'], 2),
            number_format($row['balance_amount'], 2),
            ucfirst($row['payment_status']),
            ucfirst($row['invoice_status'])
        ]);
    }
    
    // Add summary footer
    fputcsv($output, []);
    fputcsv($output, ['SUMMARY']);
    fputcsv($output, ['Total Records:', count($exportRows)]);
    fputcsv($output, ['Total Estimated Amount:', number_format(array_sum(array_column($exportRows, 'estimated_amount')), 2)]);
    fputcsv($output, ['Total Final Amount:', number_format(array_sum(array_column($exportRows, 'final_amount')), 2)]);
    fputcsv($output, ['Total Invoice Amount:', number_format(array_sum(array_column($exportRows, 'grand_total')), 2)]);
    fputcsv($output, ['Total Paid Amount:', number_format(array_sum(array_column($exportRows, 'paid_amount')), 2)]);
    fputcsv($output, ['Total Balance Amount:', number_format(array_sum(array_column($exportRows, 'balance_amount')), 2)]);
    
    fclose($output);
    exit;
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
$hasLaborItems = tableExists($conn, 'service_job_labor_items');
$hasPartItems = tableExists($conn, 'service_job_part_items');
$hasBrands = tableExists($conn, 'vehicle_brands');
$hasModels = tableExists($conn, 'vehicle_models');

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
$paymentStatusFilter = trim($_GET['payment_status'] ?? '');
$invoiceStatusFilter = trim($_GET['invoice_status'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');

$allowedJobStatuses = ['open', 'in_progress', 'waiting_parts', 'ready', 'delivered', 'cancelled'];
$allowedPaymentStatuses = ['unpaid', 'partial', 'paid'];
$allowedInvoiceStatuses = ['draft', 'confirmed', 'cancelled'];

$where = ["sj.business_id = {$businessId}"];

if ($search !== '') {
    $safe = $conn->real_escape_string($search);
    $where[] = "(
        sj.jobcard_no LIKE '%{$safe}%'
        OR c.full_name LIKE '%{$safe}%'
        OR c.mobile LIKE '%{$safe}%'
        OR br.branch_name LIKE '%{$safe}%'
        OR cv.registration_no LIKE '%{$safe}%'
        OR cv.chassis_no LIKE '%{$safe}%'
        OR sj.customer_voice LIKE '%{$safe}%'
        OR sj.technician_observation LIKE '%{$safe}%'
        OR sj.recommendation LIKE '%{$safe}%'
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

if ($hasServiceInvoices && $paymentStatusFilter !== '' && in_array($paymentStatusFilter, $allowedPaymentStatuses, true)) {
    $safe = $conn->real_escape_string($paymentStatusFilter);
    $where[] = "si.payment_status = '{$safe}'";
}

if ($hasServiceInvoices && $invoiceStatusFilter !== '' && in_array($invoiceStatusFilter, $allowedInvoiceStatuses, true)) {
    $safe = $conn->real_escape_string($invoiceStatusFilter);
    $where[] = "si.invoice_status = '{$safe}'";
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
$totalJobCards = getCount($conn, 'service_job_cards', "business_id = {$businessId}");
$totalEstimated = getSum($conn, 'service_job_cards', 'estimated_amount', "business_id = {$businessId}");
$totalFinal = getSum($conn, 'service_job_cards', 'final_amount', "business_id = {$businessId}");

$openCount = getCount($conn, 'service_job_cards', "business_id = {$businessId} AND job_status = 'open'");
$inProgressCount = getCount($conn, 'service_job_cards', "business_id = {$businessId} AND job_status = 'in_progress'");
$waitingPartsCount = getCount($conn, 'service_job_cards', "business_id = {$businessId} AND job_status = 'waiting_parts'");
$readyCount = getCount($conn, 'service_job_cards', "business_id = {$businessId} AND job_status = 'ready'");
$deliveredCount = getCount($conn, 'service_job_cards', "business_id = {$businessId} AND job_status = 'delivered'");
$cancelledCount = getCount($conn, 'service_job_cards', "business_id = {$businessId} AND job_status = 'cancelled'");

$today = date('Y-m-d');
$todayJobs = getCount($conn, 'service_job_cards', "business_id = {$businessId} AND DATE(service_date) = '{$today}'");

$pendingServices = getCount(
    $conn,
    'service_job_cards',
    "business_id = {$businessId} AND job_status IN ('open','in_progress','waiting_parts','ready')"
);

$overdueServices = getCount(
    $conn,
    'service_job_cards',
    "business_id = {$businessId}
     AND job_status IN ('open','in_progress','waiting_parts')
     AND promised_delivery IS NOT NULL
     AND DATE(promised_delivery) < '{$today}'"
);

$warrantyServices = getCountFromSql(
    $conn,
    "SELECT COUNT(*) AS total
     FROM service_job_cards sj
     INNER JOIN customer_vehicles cv ON cv.id = sj.customer_vehicle_id
     WHERE sj.business_id = {$businessId}
       AND cv.business_id = {$businessId}
       AND cv.warranty_start_date IS NOT NULL
       AND cv.warranty_end_date IS NOT NULL
       AND DATE(sj.service_date) BETWEEN cv.warranty_start_date AND cv.warranty_end_date"
);

/* -------------------------------------------------------
   SERVICE INVOICE SUMMARY
------------------------------------------------------- */
$totalInvoices = 0;
$totalInvoiceAmount = 0.00;
$totalPaid = 0.00;
$totalBalance = 0.00;
$paidInvoices = 0;
$partialInvoices = 0;
$unpaidInvoices = 0;

if ($hasServiceInvoices) {
    $totalInvoices = getCount($conn, 'service_invoices', "business_id = {$businessId}");
    $totalInvoiceAmount = getSum($conn, 'service_invoices', 'grand_total', "business_id = {$businessId}");
    $totalPaid = getSum($conn, 'service_invoices', 'paid_amount', "business_id = {$businessId}");
    $totalBalance = getSum($conn, 'service_invoices', 'balance_amount', "business_id = {$businessId}");

    $paidInvoices = getCount($conn, 'service_invoices', "business_id = {$businessId} AND payment_status = 'paid'");
    $partialInvoices = getCount($conn, 'service_invoices', "business_id = {$businessId} AND payment_status = 'partial'");
    $unpaidInvoices = getCount($conn, 'service_invoices', "business_id = {$businessId} AND payment_status = 'unpaid'");
}

/* -------------------------------------------------------
   FILTERED SUMMARY
------------------------------------------------------- */
$filteredJobs = getCountFromSql(
    $conn,
    "SELECT COUNT(*) AS total
     FROM service_job_cards sj
     LEFT JOIN branches br ON br.id = sj.branch_id
     LEFT JOIN customers c ON c.id = sj.customer_id
     LEFT JOIN customer_vehicles cv ON cv.id = sj.customer_vehicle_id
     " . ($hasServiceInvoices ? "LEFT JOIN service_invoices si ON si.jobcard_id = sj.id" : "") . "
     WHERE {$whereSql}"
);

$filteredEstimated = getSumFromSql(
    $conn,
    "SELECT COALESCE(SUM(sj.estimated_amount),0) AS total
     FROM service_job_cards sj
     LEFT JOIN branches br ON br.id = sj.branch_id
     LEFT JOIN customers c ON c.id = sj.customer_id
     LEFT JOIN customer_vehicles cv ON cv.id = sj.customer_vehicle_id
     " . ($hasServiceInvoices ? "LEFT JOIN service_invoices si ON si.jobcard_id = sj.id" : "") . "
     WHERE {$whereSql}"
);

$filteredFinal = getSumFromSql(
    $conn,
    "SELECT COALESCE(SUM(sj.final_amount),0) AS total
     FROM service_job_cards sj
     LEFT JOIN branches br ON br.id = sj.branch_id
     LEFT JOIN customers c ON c.id = sj.customer_id
     LEFT JOIN customer_vehicles cv ON cv.id = sj.customer_vehicle_id
     " . ($hasServiceInvoices ? "LEFT JOIN service_invoices si ON si.jobcard_id = sj.id" : "") . "
     WHERE {$whereSql}"
);

/* -------------------------------------------------------
   JOB CARD REPORT
------------------------------------------------------- */
$rows = fetchAllAssoc(
    $conn,
    "SELECT
        sj.*,
        br.branch_name,
        br.branch_code,
        c.full_name AS customer_name,
        c.mobile AS customer_mobile,
        cv.registration_no,
        cv.chassis_no,
        cv.color,
        cv.warranty_start_date,
        cv.warranty_end_date,
        " . ($hasBrands ? "vb.brand_name" : "NULL AS brand_name") . ",
        " . ($hasModels ? "vm.model_name, vm.variant_name" : "NULL AS model_name, NULL AS variant_name") . ",
        " . ($hasServiceInvoices ? "si.id AS invoice_id, si.invoice_no, si.invoice_date, si.grand_total, si.paid_amount, si.balance_amount, si.payment_status, si.invoice_status" : "NULL AS invoice_id, NULL AS invoice_no, NULL AS invoice_date, 0 AS grand_total, 0 AS paid_amount, 0 AS balance_amount, NULL AS payment_status, NULL AS invoice_status") . "
     FROM service_job_cards sj
     LEFT JOIN branches br ON br.id = sj.branch_id
     LEFT JOIN customers c ON c.id = sj.customer_id
     LEFT JOIN customer_vehicles cv ON cv.id = sj.customer_vehicle_id
     " . ($hasBrands ? "LEFT JOIN vehicle_brands vb ON vb.id = cv.brand_id" : "") . "
     " . ($hasModels ? "LEFT JOIN vehicle_models vm ON vm.id = cv.model_id" : "") . "
     " . ($hasServiceInvoices ? "LEFT JOIN service_invoices si ON si.jobcard_id = sj.id" : "") . "
     WHERE {$whereSql}
     ORDER BY sj.id DESC
     LIMIT 500"
);

/* -------------------------------------------------------
   LABOR SUMMARY
------------------------------------------------------- */
$laborSummary = [
    'line_count' => 0,
    'total_qty' => 0.00,
    'subtotal' => 0.00,
    'discount' => 0.00,
    'tax' => 0.00,
    'total' => 0.00,
];

if ($hasLaborItems) {
    $laborSummary['line_count'] = getCountFromSql(
        $conn,
        "SELECT COUNT(*) AS total
         FROM service_job_labor_items sli
         INNER JOIN service_job_cards sj ON sj.id = sli.jobcard_id
         WHERE sj.business_id = {$businessId}"
    );

    $laborSummary['total_qty'] = getSumFromSql(
        $conn,
        "SELECT COALESCE(SUM(sli.qty),0) AS total
         FROM service_job_labor_items sli
         INNER JOIN service_job_cards sj ON sj.id = sli.jobcard_id
         WHERE sj.business_id = {$businessId}"
    );

    $laborSummary['subtotal'] = getSumFromSql(
        $conn,
        "SELECT COALESCE(SUM(sli.qty * sli.unit_price),0) AS total
         FROM service_job_labor_items sli
         INNER JOIN service_job_cards sj ON sj.id = sli.jobcard_id
         WHERE sj.business_id = {$businessId}"
    );

    $laborSummary['discount'] = getSumFromSql(
        $conn,
        "SELECT COALESCE(SUM(sli.discount_amount),0) AS total
         FROM service_job_labor_items sli
         INNER JOIN service_job_cards sj ON sj.id = sli.jobcard_id
         WHERE sj.business_id = {$businessId}"
    );

    $laborSummary['tax'] = getSumFromSql(
        $conn,
        "SELECT COALESCE(SUM(sli.tax_amount),0) AS total
         FROM service_job_labor_items sli
         INNER JOIN service_job_cards sj ON sj.id = sli.jobcard_id
         WHERE sj.business_id = {$businessId}"
    );

    $laborSummary['total'] = getSumFromSql(
        $conn,
        "SELECT COALESCE(SUM(sli.line_total),0) AS total
         FROM service_job_labor_items sli
         INNER JOIN service_job_cards sj ON sj.id = sli.jobcard_id
         WHERE sj.business_id = {$businessId}"
    );
}

/* -------------------------------------------------------
   PART SUMMARY
------------------------------------------------------- */
$partSummary = [
    'line_count' => 0,
    'total_qty' => 0.00,
    'subtotal' => 0.00,
    'discount' => 0.00,
    'tax' => 0.00,
    'total' => 0.00,
];

if ($hasPartItems) {
    $partSummary['line_count'] = getCountFromSql(
        $conn,
        "SELECT COUNT(*) AS total
         FROM service_job_part_items spi
         INNER JOIN service_job_cards sj ON sj.id = spi.jobcard_id
         WHERE sj.business_id = {$businessId}"
    );

    $partSummary['total_qty'] = getSumFromSql(
        $conn,
        "SELECT COALESCE(SUM(spi.qty),0) AS total
         FROM service_job_part_items spi
         INNER JOIN service_job_cards sj ON sj.id = spi.jobcard_id
         WHERE sj.business_id = {$businessId}"
    );

    $partSummary['subtotal'] = getSumFromSql(
        $conn,
        "SELECT COALESCE(SUM(spi.qty * spi.unit_price),0) AS total
         FROM service_job_part_items spi
         INNER JOIN service_job_cards sj ON sj.id = spi.jobcard_id
         WHERE sj.business_id = {$businessId}"
    );

    $partSummary['discount'] = getSumFromSql(
        $conn,
        "SELECT COALESCE(SUM(spi.discount_amount),0) AS total
         FROM service_job_part_items spi
         INNER JOIN service_job_cards sj ON sj.id = spi.jobcard_id
         WHERE sj.business_id = {$businessId}"
    );

    $partSummary['tax'] = getSumFromSql(
        $conn,
        "SELECT COALESCE(SUM(spi.tax_amount),0) AS total
         FROM service_job_part_items spi
         INNER JOIN service_job_cards sj ON sj.id = spi.jobcard_id
         WHERE sj.business_id = {$businessId}"
    );

    $partSummary['total'] = getSumFromSql(
        $conn,
        "SELECT COALESCE(SUM(spi.line_total),0) AS total
         FROM service_job_part_items spi
         INNER JOIN service_job_cards sj ON sj.id = spi.jobcard_id
         WHERE sj.business_id = {$businessId}"
    );
}

// Build query parameters for export
$queryParams = [];
if ($search) $queryParams['search'] = $search;
if ($branchFilter > 0) $queryParams['branch_id'] = $branchFilter;
if ($customerFilter > 0) $queryParams['customer_id'] = $customerFilter;
if ($jobStatusFilter) $queryParams['job_status'] = $jobStatusFilter;
if ($paymentStatusFilter) $queryParams['payment_status'] = $paymentStatusFilter;
if ($invoiceStatusFilter) $queryParams['invoice_status'] = $invoiceStatusFilter;
if ($dateFrom) $queryParams['date_from'] = $dateFrom;
if ($dateTo) $queryParams['date_to'] = $dateTo;
$queryParams['export'] = 'csv';
$exportUrl = 'service-report.php?' . http_build_query($queryParams);

$pageTitle = 'Service Report';
$currentPage = 'service-report';
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

                <div class="row mb-3">
                    <div class="col-md-7">
                        <h4 class="mb-1">Service Report</h4>
                        <p class="text-muted mb-0">Service jobs, invoices, labor, parts, warranty and payment summary</p>
                    </div>
                    <div class="col-md-5 text-md-end mt-3 mt-md-0">
                        <div class="btn-group">
                            <a href="service-jobcard-add.php" class="btn btn-primary">
                                <i class="mdi mdi-plus-circle"></i> Add Job Card
                            </a>
                            <a href="<?php echo $exportUrl; ?>" class="btn btn-success">
                                <i class="mdi mdi-file-excel"></i> Export CSV
                            </a>
                            <a href="service-invoices.php" class="btn btn-secondary">
                                <i class="mdi mdi-list-box"></i> Service Invoices
                            </a>
                            <a href="index.php" class="btn btn-info">
                                <i class="mdi mdi-view-dashboard"></i> Dashboard
                            </a>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Total Job Cards</p>
                                <h3 class="mb-0"><?php echo number_format($totalJobCards); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Estimated Amount</p>
                                <h3 class="mb-0 text-primary"><?php echo money($totalEstimated); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Final Amount</p>
                                <h3 class="mb-0 text-success"><?php echo money($totalFinal); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Pending Services</p>
                                <h3 class="mb-0 text-warning"><?php echo number_format($pendingServices); ?></h3>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-2">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Open</p>
                                <h4 class="mb-0"><?php echo number_format($openCount); ?></h4>
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
                                <p class="text-muted mb-1">Waiting Parts</p>
                                <h4 class="mb-0 text-danger"><?php echo number_format($waitingPartsCount); ?></h4>
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

                    <div class="col-md-2">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Delivered</p>
                                <h4 class="mb-0 text-primary"><?php echo number_format($deliveredCount); ?></h4>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-2">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Cancelled</p>
                                <h4 class="mb-0 text-dark"><?php echo number_format($cancelledCount); ?></h4>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Today Jobs</p>
                                <h4 class="mb-0 text-info"><?php echo number_format($todayJobs); ?></h4>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Overdue Services</p>
                                <h4 class="mb-0 text-danger"><?php echo number_format($overdueServices); ?></h4>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Warranty Services</p>
                                <h4 class="mb-0 text-success"><?php echo number_format($warrantyServices); ?></h4>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Service Invoices</p>
                                <h4 class="mb-0 text-primary"><?php echo number_format($totalInvoices); ?></h4>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($hasServiceInvoices): ?>
                <div class="row">
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Invoice Amount</p>
                                <h4 class="mb-0 text-primary"><?php echo money($totalInvoiceAmount); ?></h4>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Paid Amount</p>
                                <h4 class="mb-0 text-success"><?php echo money($totalPaid); ?></h4>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Balance Amount</p>
                                <h4 class="mb-0 text-danger"><?php echo money($totalBalance); ?></h4>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-1">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Paid</p>
                                <h5 class="mb-0 text-success"><?php echo number_format($paidInvoices); ?></h5>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-1">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Part</p>
                                <h5 class="mb-0 text-warning"><?php echo number_format($partialInvoices); ?></h5>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-1">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Due</p>
                                <h5 class="mb-0 text-danger"><?php echo number_format($unpaidInvoices); ?></h5>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="card">
                    <div class="card-body">
                        <form method="get" class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label">Search</label>
                                <input
                                    type="text"
                                    name="search"
                                    class="form-control"
                                    placeholder="Job card, customer, reg no..."
                                    value="<?php echo h($search); ?>"
                                >
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
                                <label class="form-label">Job Status</label>
                                <select name="job_status" class="form-select">
                                    <option value="">All Status</option>
                                    <option value="open" <?php echo ($jobStatusFilter === 'open') ? 'selected' : ''; ?>>Open</option>
                                    <option value="in_progress" <?php echo ($jobStatusFilter === 'in_progress') ? 'selected' : ''; ?>>In Progress</option>
                                    <option value="waiting_parts" <?php echo ($jobStatusFilter === 'waiting_parts') ? 'selected' : ''; ?>>Waiting Parts</option>
                                    <option value="ready" <?php echo ($jobStatusFilter === 'ready') ? 'selected' : ''; ?>>Ready</option>
                                    <option value="delivered" <?php echo ($jobStatusFilter === 'delivered') ? 'selected' : ''; ?>>Delivered</option>
                                    <option value="cancelled" <?php echo ($jobStatusFilter === 'cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                                </select>
                            </div>

                            <?php if ($hasServiceInvoices): ?>
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
                                <label class="form-label">Invoice Status</label>
                                <select name="invoice_status" class="form-select">
                                    <option value="">All</option>
                                    <option value="draft" <?php echo ($invoiceStatusFilter === 'draft') ? 'selected' : ''; ?>>Draft</option>
                                    <option value="confirmed" <?php echo ($invoiceStatusFilter === 'confirmed') ? 'selected' : ''; ?>>Confirmed</option>
                                    <option value="cancelled" <?php echo ($invoiceStatusFilter === 'cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                                </select>
                            </div>
                            <?php endif; ?>

                            <div class="col-md-2">
                                <label class="form-label">Date From</label>
                                <input type="date" name="date_from" class="form-control" value="<?php echo h($dateFrom); ?>">
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">Date To</label>
                                <input type="date" name="date_to" class="form-control" value="<?php echo h($dateTo); ?>">
                            </div>

                            <div class="col-md-12 d-flex gap-2">
                                <button type="submit" class="btn btn-primary">Filter</button>
                                <a href="service-report.php" class="btn btn-light">Reset</a>
                                <span class="btn btn-outline-secondary disabled">
                                    Filtered: <?php echo number_format($filteredJobs); ?> |
                                    Est: <?php echo money($filteredEstimated); ?> |
                                    Final: <?php echo money($filteredFinal); ?>
                                </span>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4">Service Job Report</h4>

                        <div class="table-responsive">
                            <table class="table table-bordered table-striped align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Job Card</th>
                                        <th>Date</th>
                                        <th>Branch</th>
                                        <th>Customer</th>
                                        <th>Vehicle</th>
                                        <th>Status</th>
                                        <th>Amounts</th>
                                        <th>Invoice</th>
                                        <th style="width:210px;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($rows)): ?>
                                        <?php $i = 1; foreach ($rows as $row): ?>
                                            <tr>
                                                <td><?php echo $i++; ?></td>

                                                <td class="small">
                                                    <div><strong><?php echo h($row['jobcard_no']); ?></strong></div>
                                                    <div>Opening KM: <?php echo number_format((int)($row['opening_km'] ?? 0)); ?></div>
                                                    <div>Promised:
                                                        <?php echo !empty($row['promised_delivery']) ? h(date('d M Y h:i A', strtotime($row['promised_delivery']))) : '-'; ?>
                                                    </div>
                                                 </div>

                                                <td>
                                                    <?php echo !empty($row['service_date']) ? h(date('d M Y h:i A', strtotime($row['service_date']))) : '-'; ?>
                                                 </div>

                                                <td>
                                                    <div><?php echo h($row['branch_name'] ?: '-'); ?></div>
                                                    <div class="small text-muted"><?php echo h($row['branch_code'] ?: '-'); ?></div>
                                                 </div>

                                                <td>
                                                    <div><?php echo h($row['customer_name'] ?: '-'); ?></div>
                                                    <div class="small text-muted"><?php echo h($row['customer_mobile'] ?: '-'); ?></div>
                                                 </div>

                                                <td class="small">
                                                    <div><strong><?php echo h((($row['brand_name'] ?: 'Vehicle') . ' - ' . ($row['model_name'] ?: '-'))); ?></strong></div>
                                                    <div><?php echo h($row['variant_name'] ?: '-'); ?></div>
                                                    <div>Reg: <?php echo h($row['registration_no'] ?: '-'); ?></div>
                                                    <div>Chassis: <?php echo h($row['chassis_no'] ?: '-'); ?></div>
                                                    <div>Color: <?php echo h($row['color'] ?: '-'); ?></div>
                                                 </div>

                                                <td>
                                                    <?php
                                                    $jobBadge = 'secondary';
                                                    if (($row['job_status'] ?? '') === 'open') {
                                                        $jobBadge = 'secondary';
                                                    } elseif (($row['job_status'] ?? '') === 'in_progress') {
                                                        $jobBadge = 'warning';
                                                    } elseif (($row['job_status'] ?? '') === 'waiting_parts') {
                                                        $jobBadge = 'danger';
                                                    } elseif (($row['job_status'] ?? '') === 'ready') {
                                                        $jobBadge = 'success';
                                                    } elseif (($row['job_status'] ?? '') === 'delivered') {
                                                        $jobBadge = 'primary';
                                                    } elseif (($row['job_status'] ?? '') === 'cancelled') {
                                                        $jobBadge = 'dark';
                                                    }
                                                    ?>
                                                    <span class="badge bg-<?php echo $jobBadge; ?>">
                                                        <?php echo h(ucwords(str_replace('_', ' ', (string)$row['job_status']))); ?>
                                                    </span>

                                                    <?php
                                                    $isWarranty = false;
                                                    if (!empty($row['warranty_start_date']) && !empty($row['warranty_end_date']) && !empty($row['service_date'])) {
                                                        $serviceDate = date('Y-m-d', strtotime($row['service_date']));
                                                        if ($serviceDate >= $row['warranty_start_date'] && $serviceDate <= $row['warranty_end_date']) {
                                                            $isWarranty = true;
                                                        }
                                                    }
                                                    ?>
                                                    <?php if ($isWarranty): ?>
                                                        <div class="mt-1">
                                                            <span class="badge bg-success">Warranty</span>
                                                        </div>
                                                    <?php endif; ?>
                                                 </div>

                                                <td class="small">
                                                    <div><strong>Estimated:</strong> <?php echo money($row['estimated_amount']); ?></div>
                                                    <div><strong>Final:</strong> <?php echo money($row['final_amount']); ?></div>
                                                 </div>

                                                <td class="small">
                                                    <?php if (!empty($row['invoice_no'])): ?>
                                                        <div><strong><?php echo h($row['invoice_no']); ?></strong></div>
                                                        <div>Total: <?php echo money($row['grand_total']); ?></div>
                                                        <div>Paid: <?php echo money($row['paid_amount']); ?></div>
                                                        <div>Balance: <?php echo money($row['balance_amount']); ?></div>
                                                        <?php
                                                        $payBadge = 'secondary';
                                                        if (($row['payment_status'] ?? '') === 'paid') {
                                                            $payBadge = 'success';
                                                        } elseif (($row['payment_status'] ?? '') === 'partial') {
                                                            $payBadge = 'warning';
                                                        } elseif (($row['payment_status'] ?? '') === 'unpaid') {
                                                            $payBadge = 'danger';
                                                        }
                                                        ?>
                                                        <div class="mt-1">
                                                            <span class="badge bg-<?php echo $payBadge; ?>">
                                                                <?php echo h(ucfirst((string)$row['payment_status'])); ?>
                                                            </span>
                                                        </div>
                                                    <?php else: ?>
                                                        <span class="text-muted">No invoice</span>
                                                    <?php endif; ?>
                                                 </div>

                                                <td>
                                                    <div class="d-flex flex-wrap gap-2">
                                                        <a href="service-jobcard-view.php?id=<?php echo (int)$row['id']; ?>" class="btn btn-sm btn-info">View</a>
                                                        <a href="service-jobcard-edit.php?id=<?php echo (int)$row['id']; ?>" class="btn btn-sm btn-primary">Edit</a>
                                                        <?php if (!empty($row['invoice_id'])): ?>
                                                            <a href="service-invoice-view.php?id=<?php echo (int)$row['invoice_id']; ?>" class="btn btn-sm btn-success">Invoice</a>
                                                        <?php else: ?>
                                                            <a href="service-invoice-add.php?jobcard_id=<?php echo (int)$row['id']; ?>" class="btn btn-sm btn-success">Create Invoice</a>
                                                        <?php endif; ?>
                                                    </div>
                                                 </div>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="10" class="text-center text-muted py-4">No service records found.</td>
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

                <div class="row report-last-row">
                    <div class="col-xl-6">
                        <div class="card h-100">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Labor Summary</h4>
                                <?php if ($hasLaborItems): ?>
                                    <table class="table table-bordered table-striped mb-0">
                                        <tbody>
                                            <tr>
                                                <th style="width:50%;">Labor Lines</th>
                                                <td><?php echo number_format($laborSummary['line_count']); ?> </div>
                                            </tr>
                                            <tr>
                                                <th>Total Qty</th>
                                                <td><?php echo qtyf($laborSummary['total_qty']); ?> </div>
                                            </tr>
                                            <tr>
                                                <th>Subtotal</th>
                                                <td><?php echo money($laborSummary['subtotal']); ?> </div>
                                            </tr>
                                            <tr>
                                                <th>Discount</th>
                                                <td><?php echo money($laborSummary['discount']); ?> </div>
                                            </tr>
                                            <tr>
                                                <th>Tax</th>
                                                <td><?php echo money($laborSummary['tax']); ?> </div>
                                            </tr>
                                            <tr>
                                                <th>Total</th>
                                                <td><strong class="text-primary"><?php echo money($laborSummary['total']); ?></strong> </div>
                                            </tr>
                                        </tbody>
                                    </table>
                                <?php else: ?>
                                    <div class="text-muted">Labor item table not available.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-6">
                        <div class="card h-100">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Parts Summary</h4>
                                <?php if ($hasPartItems): ?>
                                    <table class="table table-bordered table-striped mb-0">
                                        <tbody>
                                            <tr>
                                                <th style="width:50%;">Part Lines</th>
                                                <td><?php echo number_format($partSummary['line_count']); ?> </div>
                                            </tr>
                                            <tr>
                                                <th>Total Qty</th>
                                                <td><?php echo qtyf($partSummary['total_qty']); ?> </div>
                                            </tr>
                                            <tr>
                                                <th>Subtotal</th>
                                                <td><?php echo money($partSummary['subtotal']); ?> </div>
                                            </tr>
                                            <tr>
                                                <th>Discount</th>
                                                <td><?php echo money($partSummary['discount']); ?> </div>
                                            </tr>
                                            <tr>
                                                <th>Tax</th>
                                                <td><?php echo money($partSummary['tax']); ?> </div>
                                            </tr>
                                            <tr>
                                                <th>Total</th>
                                                <td><strong class="text-primary"><?php echo money($partSummary['total']); ?></strong> </div>
                                            </tr>
                                        </tbody>
                                    </table>
                                <?php else: ?>
                                    <div class="text-muted">Part item table not available.</div>
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