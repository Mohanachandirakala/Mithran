<?php
session_start();
date_default_timezone_set('Asia/Kolkata');

require_once __DIR__ . '/includes/config.php';

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
$currentBranchId = isset($_SESSION['branch_id']) ? (int)$_SESSION['branch_id'] : 0;

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
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
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

function columnExists(mysqli $conn, string $table, string $column): bool
{
    $sql = "SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('ss', $table, $column);
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

function countQuery(mysqli $conn, string $sql, string $types = '', array $params = []): int
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return 0;
    }
    if ($types !== '' && !empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['total'] ?? 0);
}

function sumQuery(mysqli $conn, string $sql, string $types = '', array $params = []): float
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return 0.0;
    }
    if ($types !== '' && !empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (float)($row['total'] ?? 0);
}

function buildBadge(string $value, array $map): string
{
    $key = strtolower(trim($value));
    $class = $map[$key] ?? 'secondary';
    $label = $key !== '' ? ucwords(str_replace('_', ' ', $key)) : '-';
    return '<span class="badge bg-' . h($class) . '">' . h($label) . '</span>';
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
                                bu.branch_id,
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
$requiredTables = ['service_invoices', 'customers', 'branches', 'service_job_cards'];
$missingTables = [];
foreach ($requiredTables as $tbl) {
    if (!tableExists($conn, $tbl)) {
        $missingTables[] = $tbl;
    }
}

if (!empty($missingTables)) {
    die('Missing required table(s): ' . h(implode(', ', $missingTables)));
}

$hasCustomerVehicles = tableExists($conn, 'customer_vehicles');
$hasVehicleBrands = tableExists($conn, 'vehicle_brands');
$hasVehicleModels = tableExists($conn, 'vehicle_models');
$hasPartItems = tableExists($conn, 'service_job_part_items');
$hasLaborItems = tableExists($conn, 'service_job_labor_items');
$hasPayments = tableExists($conn, 'payments');

/* -------------------------------------------------------
   DELETE / CANCEL
   Hard delete is kept because your existing page used delete.
   Related payments are removed first to avoid old payment balance mismatch.
------------------------------------------------------- */
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $invoiceId = isset($_POST['id']) ? (int)$_POST['id'] : 0;

    if ($invoiceId <= 0) {
        $error = 'Invalid invoice id.';
    } else {
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("SELECT id, invoice_no FROM service_invoices WHERE id = ? AND business_id = ? LIMIT 1");
            if (!$stmt) {
                throw new Exception('Unable to prepare invoice validation query.');
            }
            $stmt->bind_param('ii', $invoiceId, $businessId);
            $stmt->execute();
            $invoiceRow = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$invoiceRow) {
                throw new Exception('Invoice not found or already deleted.');
            }

            if ($hasPayments) {
                $paymentFor = 'service';
                $stmt = $conn->prepare("DELETE FROM payments WHERE business_id = ? AND payment_for = ? AND ref_id = ?");
                if ($stmt) {
                    $stmt->bind_param('isi', $businessId, $paymentFor, $invoiceId);
                    $stmt->execute();
                    $stmt->close();
                }
            }

            $stmt = $conn->prepare("DELETE FROM service_invoices WHERE id = ? AND business_id = ? LIMIT 1");
            if (!$stmt) {
                throw new Exception('Unable to prepare invoice delete query.');
            }
            $stmt->bind_param('ii', $invoiceId, $businessId);
            if (!$stmt->execute()) {
                throw new Exception('Failed to delete invoice.');
            }
            if ($stmt->affected_rows <= 0) {
                throw new Exception('Invoice not found or already deleted.');
            }
            $stmt->close();

            if (tableExists($conn, 'audit_logs')) {
                $action = 'Delete';
                $module = 'Service Invoice';
                $table = 'service_invoices';
                $desc = 'Deleted service invoice: ' . (string)$invoiceRow['invoice_no'];
                $ip = $_SERVER['REMOTE_ADDR'] ?? null;
                $stmt = $conn->prepare("INSERT INTO audit_logs (business_id, branch_id, user_id, action, module_name, ref_table, ref_id, description, ip_address, created_at) VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, NOW())");
                if ($stmt) {
                    $stmt->bind_param('iisssiss', $businessId, $businessUserId, $action, $module, $table, $invoiceId, $desc, $ip);
                    $stmt->execute();
                    $stmt->close();
                }
            }

            $conn->commit();
            $success = 'Service invoice deleted successfully.';
        } catch (Throwable $e) {
            $conn->rollback();
            $error = $e->getMessage();
        }
    }
}

if (isset($_GET['success']) && trim($_GET['success']) !== '') {
    $success = trim($_GET['success']);
}
if (isset($_GET['error']) && trim($_GET['error']) !== '') {
    $error = trim($_GET['error']);
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
$paymentStatusFilter = trim($_GET['payment_status'] ?? '');
$invoiceStatusFilter = trim($_GET['invoice_status'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');

$allowedPaymentStatuses = ['unpaid', 'partial', 'paid'];
$allowedInvoiceStatuses = ['draft', 'confirmed', 'cancelled'];

$whereParts = ["si.business_id = ?"];
$types = 'i';
$params = [$businessId];

if ($search !== '') {
    $like = '%' . $search . '%';
    $whereParts[] = "(
        si.invoice_no LIKE ?
        OR sj.jobcard_no LIKE ?
        OR c.full_name LIKE ?
        OR c.mobile LIKE ?
        OR br.branch_name LIKE ?
    )";
    $types .= 'sssss';
    array_push($params, $like, $like, $like, $like, $like);
}

if ($branchFilter > 0) {
    $whereParts[] = "si.branch_id = ?";
    $types .= 'i';
    $params[] = $branchFilter;
}

if ($customerFilter > 0) {
    $whereParts[] = "si.customer_id = ?";
    $types .= 'i';
    $params[] = $customerFilter;
}

if ($paymentStatusFilter !== '' && in_array($paymentStatusFilter, $allowedPaymentStatuses, true)) {
    $whereParts[] = "si.payment_status = ?";
    $types .= 's';
    $params[] = $paymentStatusFilter;
}

if ($invoiceStatusFilter !== '' && in_array($invoiceStatusFilter, $allowedInvoiceStatuses, true)) {
    $whereParts[] = "si.invoice_status = ?";
    $types .= 's';
    $params[] = $invoiceStatusFilter;
}

if ($dateFrom !== '') {
    $whereParts[] = "DATE(si.invoice_date) >= ?";
    $types .= 's';
    $params[] = $dateFrom;
}

if ($dateTo !== '') {
    $whereParts[] = "DATE(si.invoice_date) <= ?";
    $types .= 's';
    $params[] = $dateTo;
}

$whereSql = implode(' AND ', $whereParts);

/* -------------------------------------------------------
   PAGINATION
------------------------------------------------------- */
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = (int)($_GET['per_page'] ?? 25);
if (!in_array($perPage, [10, 25, 50, 100], true)) {
    $perPage = 25;
}
$offset = ($page - 1) * $perPage;

$joinSql = "
    FROM service_invoices si
    LEFT JOIN branches br ON br.id = si.branch_id
    LEFT JOIN customers c ON c.id = si.customer_id
    LEFT JOIN service_job_cards sj ON sj.id = si.jobcard_id
";

$countSql = "SELECT COUNT(*) AS total {$joinSql} WHERE {$whereSql}";
$totalFilteredRows = countQuery($conn, $countSql, $types, $params);
$totalPages = max(1, (int)ceil($totalFilteredRows / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

/* -------------------------------------------------------
   SUMMARY
------------------------------------------------------- */
$totalInvoices = countQuery($conn, "SELECT COUNT(*) AS total FROM service_invoices WHERE business_id = ?", 'i', [$businessId]);
$totalAmount = sumQuery($conn, "SELECT COALESCE(SUM(grand_total),0) AS total FROM service_invoices WHERE business_id = ?", 'i', [$businessId]);
$totalPaid = sumQuery($conn, "SELECT COALESCE(SUM(paid_amount),0) AS total FROM service_invoices WHERE business_id = ?", 'i', [$businessId]);
$totalBalance = sumQuery($conn, "SELECT COALESCE(SUM(balance_amount),0) AS total FROM service_invoices WHERE business_id = ?", 'i', [$businessId]);
$totalTax = sumQuery($conn, "SELECT COALESCE(SUM(cgst_amount + sgst_amount + igst_amount),0) AS total FROM service_invoices WHERE business_id = ?", 'i', [$businessId]);

$paidInvoices = countQuery($conn, "SELECT COUNT(*) AS total FROM service_invoices WHERE business_id = ? AND payment_status = 'paid'", 'i', [$businessId]);
$partialInvoices = countQuery($conn, "SELECT COUNT(*) AS total FROM service_invoices WHERE business_id = ? AND payment_status = 'partial'", 'i', [$businessId]);
$unpaidInvoices = countQuery($conn, "SELECT COUNT(*) AS total FROM service_invoices WHERE business_id = ? AND payment_status = 'unpaid'", 'i', [$businessId]);

$today = date('Y-m-d');
$todayInvoices = countQuery($conn, "SELECT COUNT(*) AS total FROM service_invoices WHERE business_id = ? AND DATE(invoice_date) = ?", 'is', [$businessId, $today]);
$todayAmount = sumQuery($conn, "SELECT COALESCE(SUM(grand_total),0) AS total FROM service_invoices WHERE business_id = ? AND DATE(invoice_date) = ?", 'is', [$businessId, $today]);

/* -------------------------------------------------------
   FILTERED SUMMARY
------------------------------------------------------- */
$filteredSummarySql = "SELECT
        COUNT(*) AS invoice_count,
        COALESCE(SUM(si.subtotal),0) AS subtotal,
        COALESCE(SUM(si.cgst_amount + si.sgst_amount + si.igst_amount),0) AS tax_total,
        COALESCE(SUM(si.grand_total),0) AS grand_total,
        COALESCE(SUM(si.paid_amount),0) AS paid_total,
        COALESCE(SUM(si.balance_amount),0) AS balance_total
    {$joinSql}
    WHERE {$whereSql}";
$stmt = $conn->prepare($filteredSummarySql);
$filteredSummary = [
    'invoice_count' => 0,
    'subtotal' => 0,
    'tax_total' => 0,
    'grand_total' => 0,
    'paid_total' => 0,
    'balance_total' => 0,
];
if ($stmt) {
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) {
        $filteredSummary = array_merge($filteredSummary, $row);
    }
}

/* -------------------------------------------------------
   FETCH INVOICES
------------------------------------------------------- */
$invoiceRows = [];

$vehicleJoin = '';
$vehicleSelect = "
    '' AS registration_no,
    '' AS chassis_no,
    '' AS brand_name,
    '' AS model_name,
    '' AS variant_name
";
if ($hasCustomerVehicles) {
    $vehicleJoin .= " LEFT JOIN customer_vehicles cv ON cv.id = sj.customer_vehicle_id ";
    $vehicleSelect = "
        cv.registration_no,
        cv.chassis_no,
        " . ($hasVehicleBrands ? "vb.brand_name" : "'' AS brand_name") . ",
        " . ($hasVehicleModels ? "vm.model_name, vm.variant_name" : "'' AS model_name, '' AS variant_name") . "
    ";
    if ($hasVehicleBrands) {
        $vehicleJoin .= " LEFT JOIN vehicle_brands vb ON vb.id = cv.brand_id ";
    }
    if ($hasVehicleModels) {
        $vehicleJoin .= " LEFT JOIN vehicle_models vm ON vm.id = cv.model_id ";
    }
}

$itemSelect = "0 AS part_count, 0 AS labour_count";
if ($hasPartItems || $hasLaborItems) {
    $itemSelect = "
        " . ($hasPartItems ? "COALESCE(part_summary.part_count,0)" : "0") . " AS part_count,
        " . ($hasLaborItems ? "COALESCE(labour_summary.labour_count,0)" : "0") . " AS labour_count
    ";
}

$itemJoin = '';
if ($hasPartItems) {
    $itemJoin .= " LEFT JOIN (
        SELECT jobcard_id, COUNT(*) AS part_count
        FROM service_job_part_items
        GROUP BY jobcard_id
    ) part_summary ON part_summary.jobcard_id = sj.id ";
}
if ($hasLaborItems) {
    $itemJoin .= " LEFT JOIN (
        SELECT jobcard_id, COUNT(*) AS labour_count
        FROM service_job_labor_items
        GROUP BY jobcard_id
    ) labour_summary ON labour_summary.jobcard_id = sj.id ";
}

$sql = "SELECT
            si.*,
            br.branch_name,
            br.branch_code,
            c.full_name AS customer_name,
            c.mobile AS customer_mobile,
            sj.jobcard_no,
            sj.service_date,
            sj.job_status,
            {$vehicleSelect},
            {$itemSelect}
        FROM service_invoices si
        LEFT JOIN branches br ON br.id = si.branch_id
        LEFT JOIN customers c ON c.id = si.customer_id
        LEFT JOIN service_job_cards sj ON sj.id = si.jobcard_id
        {$vehicleJoin}
        {$itemJoin}
        WHERE {$whereSql}
        ORDER BY si.id DESC
        LIMIT ? OFFSET ?";

$listTypes = $types . 'ii';
$listParams = array_merge($params, [$perPage, $offset]);
$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param($listTypes, ...$listParams);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $invoiceRows[] = $row;
    }
    $stmt->close();
} else {
    $error = $error !== '' ? $error : 'Unable to fetch service invoices: ' . $conn->error;
}

$queryParams = $_GET;
unset($queryParams['page']);
$basePageUrl = 'service-invoices.php?' . http_build_query($queryParams);
$basePageUrl .= ($basePageUrl === 'service-invoices.php?' ? '' : '&') . 'page=';

$pageTitle = 'Service Invoices';
$currentPage = 'service-invoices';
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

                <style>
                    .service-page .stat-card{border:0;border-radius:16px;box-shadow:0 8px 24px rgba(15,23,42,.07)}
                    .service-page .stat-icon{width:44px;height:44px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:20px;background:#fff7ed;color:#b45309}
                    .service-page .filter-card,.service-page .list-card{border:0;border-radius:16px;box-shadow:0 8px 24px rgba(15,23,42,.07)}
                    .service-page .table thead th{background:#f8fafc;color:#334155;font-size:12px;text-transform:uppercase;letter-spacing:.03em;white-space:nowrap}
                    .service-page .table td{vertical-align:middle}
                    .service-page .invoice-title{font-weight:800;color:#0f172a}
                    .service-page .mini-text{font-size:12px;color:#64748b;line-height:1.35}
                    .service-page .amount-box{font-size:12px;line-height:1.45;white-space:nowrap}
                    .service-page .action-btns .btn{min-width:58px}
                    .service-page .summary-strip{background:#fff7ed;border:1px solid #fed7aa;border-radius:14px;padding:12px}
                    .service-page .summary-strip strong{font-size:15px}
                    .service-page .pagination .page-link{border-radius:8px;margin:0 2px}
                    @media(max-width:991px){.service-page .filter-actions{justify-content:flex-start!important}.service-page .action-btns{min-width:220px}}
                </style>

                <div class="service-page">
                    <div class="row align-items-center mb-3">
                        <div class="col-md-6">
                            <h4 class="mb-1">Service Invoices</h4>
                            <p class="text-muted mb-0">Manage service invoices, GST inclusive totals, payments and job-card details.</p>
                        </div>
                        <div class="col-md-6 text-md-end mt-3 mt-md-0">
                            <a href="service-invoice-add.php" class="btn btn-primary">
                                <i class="mdi mdi-plus me-1"></i> Add Service Invoice
                            </a>
                        </div>
                    </div>

                    <?php if ($success !== ''): ?>
                        <div class="alert alert-success alert-dismissible fade show">
                            <?php echo h($success); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <?php if ($error !== ''): ?>
                        <div class="alert alert-danger alert-dismissible fade show">
                            <?php echo h($error); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <div class="row">
                        <div class="col-xl-3 col-md-6">
                            <div class="card stat-card">
                                <div class="card-body d-flex align-items-center justify-content-between">
                                    <div>
                                        <p class="text-muted mb-1">Total Invoices</p>
                                        <h3 class="mb-0"><?php echo number_format($totalInvoices); ?></h3>
                                    </div>
                                    <div class="stat-icon"><i class="mdi mdi-file-document-outline"></i></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-xl-3 col-md-6">
                            <div class="card stat-card">
                                <div class="card-body d-flex align-items-center justify-content-between">
                                    <div>
                                        <p class="text-muted mb-1">Total Amount</p>
                                        <h3 class="mb-0 text-primary"><?php echo money($totalAmount); ?></h3>
                                    </div>
                                    <div class="stat-icon"><i class="mdi mdi-currency-inr"></i></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-xl-3 col-md-6">
                            <div class="card stat-card">
                                <div class="card-body d-flex align-items-center justify-content-between">
                                    <div>
                                        <p class="text-muted mb-1">Paid Amount</p>
                                        <h3 class="mb-0 text-success"><?php echo money($totalPaid); ?></h3>
                                    </div>
                                    <div class="stat-icon"><i class="mdi mdi-check-circle-outline"></i></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-xl-3 col-md-6">
                            <div class="card stat-card">
                                <div class="card-body d-flex align-items-center justify-content-between">
                                    <div>
                                        <p class="text-muted mb-1">Balance Amount</p>
                                        <h3 class="mb-0 text-danger"><?php echo money($totalBalance); ?></h3>
                                    </div>
                                    <div class="stat-icon"><i class="mdi mdi-alert-circle-outline"></i></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-xl-2 col-md-4 col-6">
                            <div class="card stat-card"><div class="card-body text-center"><p class="text-muted mb-1">Paid</p><h4 class="mb-0 text-success"><?php echo number_format($paidInvoices); ?></h4></div></div>
                        </div>
                        <div class="col-xl-2 col-md-4 col-6">
                            <div class="card stat-card"><div class="card-body text-center"><p class="text-muted mb-1">Partial</p><h4 class="mb-0 text-warning"><?php echo number_format($partialInvoices); ?></h4></div></div>
                        </div>
                        <div class="col-xl-2 col-md-4 col-6">
                            <div class="card stat-card"><div class="card-body text-center"><p class="text-muted mb-1">Unpaid</p><h4 class="mb-0 text-danger"><?php echo number_format($unpaidInvoices); ?></h4></div></div>
                        </div>
                        <div class="col-xl-2 col-md-4 col-6">
                            <div class="card stat-card"><div class="card-body text-center"><p class="text-muted mb-1">GST Collected</p><h4 class="mb-0 text-info"><?php echo money($totalTax); ?></h4></div></div>
                        </div>
                        <div class="col-xl-2 col-md-4 col-6">
                            <div class="card stat-card"><div class="card-body text-center"><p class="text-muted mb-1">Today Invoices</p><h4 class="mb-0 text-info"><?php echo number_format($todayInvoices); ?></h4></div></div>
                        </div>
                        <div class="col-xl-2 col-md-4 col-6">
                            <div class="card stat-card"><div class="card-body text-center"><p class="text-muted mb-1">Today Amount</p><h4 class="mb-0 text-primary"><?php echo money($todayAmount); ?></h4></div></div>
                        </div>
                    </div>

                    <!-- FILTERS -->
                    <div class="card filter-card">
                        <div class="card-body">
                            <form method="get" class="row g-3 align-items-end">
                                <div class="col-xl-3 col-lg-4 col-md-6">
                                    <label class="form-label">Search</label>
                                    <input
                                        type="text"
                                        name="search"
                                        class="form-control"
                                        placeholder="Invoice no, job card, customer, mobile..."
                                        value="<?php echo h($search); ?>"
                                    >
                                </div>

                                <div class="col-xl-2 col-lg-4 col-md-6">
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

                                <div class="col-xl-2 col-lg-4 col-md-6">
                                    <label class="form-label">Customer</label>
                                    <select name="customer_id" class="form-select">
                                        <option value="0">All Customers</option>
                                        <?php foreach ($customers as $c): ?>
                                            <option value="<?php echo (int)$c['id']; ?>" <?php echo ($customerFilter === (int)$c['id']) ? 'selected' : ''; ?>>
                                                <?php echo h($c['full_name'] . ' - ' . $c['mobile']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-xl-2 col-lg-4 col-md-6">
                                    <label class="form-label">Payment</label>
                                    <select name="payment_status" class="form-select">
                                        <option value="">All Payment</option>
                                        <option value="paid" <?php echo ($paymentStatusFilter === 'paid') ? 'selected' : ''; ?>>Paid</option>
                                        <option value="partial" <?php echo ($paymentStatusFilter === 'partial') ? 'selected' : ''; ?>>Partial</option>
                                        <option value="unpaid" <?php echo ($paymentStatusFilter === 'unpaid') ? 'selected' : ''; ?>>Unpaid</option>
                                    </select>
                                </div>

                                <div class="col-xl-2 col-lg-4 col-md-6">
                                    <label class="form-label">Invoice Status</label>
                                    <select name="invoice_status" class="form-select">
                                        <option value="">All Status</option>
                                        <option value="draft" <?php echo ($invoiceStatusFilter === 'draft') ? 'selected' : ''; ?>>Draft</option>
                                        <option value="confirmed" <?php echo ($invoiceStatusFilter === 'confirmed') ? 'selected' : ''; ?>>Confirmed</option>
                                        <option value="cancelled" <?php echo ($invoiceStatusFilter === 'cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                                    </select>
                                </div>

                                <div class="col-xl-1 col-lg-4 col-md-6">
                                    <label class="form-label">From</label>
                                    <input type="date" name="date_from" class="form-control" value="<?php echo h($dateFrom); ?>">
                                </div>

                                <div class="col-xl-1 col-lg-4 col-md-6">
                                    <label class="form-label">To</label>
                                    <input type="date" name="date_to" class="form-control" value="<?php echo h($dateTo); ?>">
                                </div>

                                <div class="col-xl-1 col-lg-4 col-md-6">
                                    <label class="form-label">Rows</label>
                                    <select name="per_page" class="form-select">
                                        <?php foreach ([10, 25, 50, 100] as $pp): ?>
                                            <option value="<?php echo $pp; ?>" <?php echo $perPage === $pp ? 'selected' : ''; ?>><?php echo $pp; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-12 d-flex gap-2 filter-actions justify-content-end">
                                    <button type="submit" class="btn btn-secondary">
                                        <i class="mdi mdi-filter-outline me-1"></i> Filter
                                    </button>
                                    <a href="service-invoices.php" class="btn btn-light">Reset</a>
                                </div>
                            </form>
                        </div>
                    </div>

                    <div class="summary-strip mb-3">
                        <div class="row g-2 align-items-center">
                            <div class="col-md-2"><span class="text-muted">Showing</span><br><strong><?php echo number_format((int)$filteredSummary['invoice_count']); ?> invoices</strong></div>
                            <div class="col-md-2"><span class="text-muted">Taxable</span><br><strong><?php echo money($filteredSummary['subtotal']); ?></strong></div>
                            <div class="col-md-2"><span class="text-muted">GST</span><br><strong><?php echo money($filteredSummary['tax_total']); ?></strong></div>
                            <div class="col-md-2"><span class="text-muted">Grand Total</span><br><strong><?php echo money($filteredSummary['grand_total']); ?></strong></div>
                            <div class="col-md-2"><span class="text-muted">Paid</span><br><strong class="text-success"><?php echo money($filteredSummary['paid_total']); ?></strong></div>
                            <div class="col-md-2"><span class="text-muted">Balance</span><br><strong class="text-danger"><?php echo money($filteredSummary['balance_total']); ?></strong></div>
                        </div>
                    </div>

                    <!-- LIST -->
                    <div class="card list-card">
                        <div class="card-body">
                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                                <div>
                                    <h4 class="card-title mb-1">Invoice List</h4>
                                    <p class="text-muted mb-0">Page <?php echo number_format($page); ?> of <?php echo number_format($totalPages); ?></p>
                                </div>
                                <div class="text-muted small">
                                    <?php
                                    $fromRow = $totalFilteredRows > 0 ? ($offset + 1) : 0;
                                    $toRow = min($offset + $perPage, $totalFilteredRows);
                                    echo 'Showing ' . number_format($fromRow) . ' - ' . number_format($toRow) . ' of ' . number_format($totalFilteredRows);
                                    ?>
                                </div>
                            </div>

                            <div class="table-responsive">
                                <table class="table table-bordered table-hover align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Invoice</th>
                                            <th>Date</th>
                                            <th>Branch</th>
                                            <th>Customer</th>
                                            <th>Job Card</th>
                                            <th>Vehicle</th>
                                            <th>Items</th>
                                            <th>GST Inclusive Amount</th>
                                            <th>Payment</th>
                                            <th>Status</th>
                                            <th style="width:255px;">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($invoiceRows)): ?>
                                            <?php $i = $offset + 1; foreach ($invoiceRows as $row): ?>
                                                <tr>
                                                    <td><?php echo number_format($i++); ?></td>

                                                    <td>
                                                        <div class="invoice-title"><?php echo h($row['invoice_no']); ?></div>
                                                        <div class="mini-text">ID: <?php echo (int)$row['id']; ?></div>
                                                    </td>

                                                    <td class="mini-text">
                                                        <?php echo !empty($row['invoice_date']) ? h(date('d M Y', strtotime($row['invoice_date']))) : '-'; ?><br>
                                                        <?php echo !empty($row['invoice_date']) ? h(date('h:i A', strtotime($row['invoice_date']))) : ''; ?>
                                                    </td>

                                                    <td>
                                                        <div><?php echo h($row['branch_name'] ?: '-'); ?></div>
                                                        <div class="mini-text"><?php echo h($row['branch_code'] ?: '-'); ?></div>
                                                    </td>

                                                    <td>
                                                        <div><?php echo h($row['customer_name'] ?: '-'); ?></div>
                                                        <div class="mini-text"><?php echo h($row['customer_mobile'] ?: '-'); ?></div>
                                                    </td>

                                                    <td class="mini-text">
                                                        <div><strong><?php echo h($row['jobcard_no'] ?: '-'); ?></strong></div>
                                                        <div>Service: <?php echo !empty($row['service_date']) ? h(date('d M Y', strtotime($row['service_date']))) : '-'; ?></div>
                                                        <div>Job: <?php echo h(ucwords(str_replace('_', ' ', (string)($row['job_status'] ?? '-')))); ?></div>
                                                    </td>

                                                    <td class="mini-text">
                                                        <div><strong><?php echo h(trim(($row['brand_name'] ?: 'Vehicle') . ' - ' . ($row['model_name'] ?: '-'))); ?></strong></div>
                                                        <div><?php echo h($row['variant_name'] ?: '-'); ?></div>
                                                        <div>Reg: <?php echo h($row['registration_no'] ?: '-'); ?></div>
                                                        <div>Chassis: <?php echo h($row['chassis_no'] ?: '-'); ?></div>
                                                    </td>

                                                    <td class="mini-text">
                                                        <div>Parts: <strong><?php echo number_format((int)$row['part_count']); ?></strong></div>
                                                        <div>Labour: <strong><?php echo number_format((int)$row['labour_count']); ?></strong></div>
                                                    </td>

                                                    <td class="amount-box">
                                                        <div><strong>Taxable:</strong> <?php echo money($row['subtotal']); ?></div>
                                                        <div><strong>CGST:</strong> <?php echo money($row['cgst_amount']); ?></div>
                                                        <div><strong>SGST:</strong> <?php echo money($row['sgst_amount']); ?></div>
                                                        <?php if ((float)($row['igst_amount'] ?? 0) > 0): ?>
                                                            <div><strong>IGST:</strong> <?php echo money($row['igst_amount']); ?></div>
                                                        <?php endif; ?>
                                                        <?php if ((float)($row['discount_amount'] ?? 0) > 0): ?>
                                                            <div><strong>Discount:</strong> <?php echo money($row['discount_amount']); ?></div>
                                                        <?php endif; ?>
                                                        <div class="text-primary"><strong>Total:</strong> <?php echo money($row['grand_total']); ?></div>
                                                    </td>

                                                    <td class="amount-box">
                                                        <div><?php echo buildBadge((string)($row['payment_status'] ?? ''), ['paid' => 'success', 'partial' => 'warning', 'unpaid' => 'danger']); ?></div>
                                                        <div class="mt-1"><strong>Paid:</strong> <?php echo money($row['paid_amount']); ?></div>
                                                        <div><strong>Balance:</strong> <?php echo money($row['balance_amount']); ?></div>
                                                    </td>

                                                    <td>
                                                        <?php echo buildBadge((string)($row['invoice_status'] ?? ''), ['confirmed' => 'success', 'draft' => 'warning', 'cancelled' => 'danger']); ?>
                                                    </td>

                                                    <td>
                                                        <div class="d-flex flex-wrap gap-2 action-btns">
                                                            <a href="service-invoice-view.php?id=<?php echo (int)$row['id']; ?>" class="btn btn-sm btn-info">View</a>
                                                            <a href="service-invoice-edit.php?id=<?php echo (int)$row['id']; ?>" class="btn btn-sm btn-primary">Edit</a>
                                                            <a href="service-invoice-print.php?id=<?php echo (int)$row['id']; ?>" class="btn btn-sm btn-secondary" target="_blank">Print</a>
                                                            <form method="post" onsubmit="return confirm('Delete this service invoice? This will also remove linked service payment entry.');" style="display:inline;">
                                                                <input type="hidden" name="action" value="delete">
                                                                <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                                                                <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                                                            </form>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="12" class="text-center text-muted py-4">No service invoices found.</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>

                            <?php if ($totalPages > 1): ?>
                                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3">
                                    <div class="text-muted small">
                                        Page <?php echo number_format($page); ?> of <?php echo number_format($totalPages); ?>
                                    </div>
                                    <nav>
                                        <ul class="pagination mb-0">
                                            <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                                <a class="page-link" href="<?php echo h($basePageUrl . max(1, $page - 1)); ?>">Previous</a>
                                            </li>
                                            <?php
                                            $start = max(1, $page - 2);
                                            $end = min($totalPages, $page + 2);
                                            for ($p = $start; $p <= $end; $p++):
                                            ?>
                                                <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>">
                                                    <a class="page-link" href="<?php echo h($basePageUrl . $p); ?>"><?php echo $p; ?></a>
                                                </li>
                                            <?php endfor; ?>
                                            <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                                                <a class="page-link" href="<?php echo h($basePageUrl . min($totalPages, $page + 1)); ?>">Next</a>
                                            </li>
                                        </ul>
                                    </nav>
                                </div>
                            <?php endif; ?>

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
