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
$sessionBranchId = isset($_SESSION['branch_id']) ? (int)$_SESSION['branch_id'] : 0;

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

function formatDisplayDate($dateValue): string
{
    if (empty($dateValue) || $dateValue == '0000-00-00' || $dateValue == '0000-00-00 00:00:00') {
        return '-';
    }
    $timestamp = strtotime($dateValue);
    if (!$timestamp || $timestamp <= 0) {
        return '-';
    }
    return date('d-m-Y', $timestamp);
}

function formatDisplayDateTime($dateValue): string
{
    if (empty($dateValue) || $dateValue == '0000-00-00' || $dateValue == '0000-00-00 00:00:00') {
        return '-';
    }
    $timestamp = strtotime($dateValue);
    if (!$timestamp || $timestamp <= 0) {
        return '-';
    }
    return date('d-m-Y h:i A', $timestamp);
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
   GET SELECTED BRANCH (from GET or SESSION)
------------------------------------------------------- */
$selectedBranchId = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : $sessionBranchId;

// If branch changed via filter, update session
if (isset($_GET['branch_id']) && $_GET['branch_id'] != $sessionBranchId) {
    $_SESSION['branch_id'] = $selectedBranchId;
}

/* -------------------------------------------------------
   TABLE CHECKS
------------------------------------------------------- */
$requiredTables = [
    'sales_invoices',
    'customers',
    'branches',
    'vehicle_sales',
    'vehicle_stock',
    'vehicle_models',
    'vehicle_brands'
];
foreach ($requiredTables as $tbl) {
    if (!tableExists($conn, $tbl)) {
        die($tbl . ' table not found.');
    }
}

/* -------------------------------------------------------
   MASTER DATA
------------------------------------------------------- */
$branches = fetchAllAssoc(
    $conn,
    "SELECT id, branch_name, branch_code
     FROM branches
     WHERE business_id = {$businessId} AND status = 'active'
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
$branchFilter = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : $selectedBranchId;
$customerFilter = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;
$insuranceStatus = trim($_GET['insurance_status'] ?? '');
$rtoStatus = trim($_GET['rto_status'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');

// Build branch condition for stats
$branchCondition = "";
if ($branchFilter > 0) {
    $branchCondition = " AND si.branch_id = {$branchFilter}";
}

/* -------------------------------------------------------
   SUMMARY STATISTICS
------------------------------------------------------- */
// Total vehicle sales
$totalVehicleSales = 0;
$salesCountSql = "SELECT COUNT(*) as total FROM sales_invoices si 
                  WHERE si.business_id = {$businessId} 
                  AND si.invoice_type IN ('vehicle_sale', 'mixed_sale') 
                  {$branchCondition}";
$countResult = $conn->query($salesCountSql);
if ($countResult) {
    $totalVehicleSales = (int)$countResult->fetch_assoc()['total'];
}

// Get sums from vehicle_sales table
$totalInsuranceAmount = 0;
$totalInsurancePremium = 0;
$totalRegistrationAmount = 0;
$totalRtoCharge = 0;
$insuranceDone = 0;
$insurancePending = 0;
$rtoDone = 0;
$rtoPending = 0;

$statsSql = "SELECT 
                COALESCE(SUM(vs.insurance_amount),0) as total_insurance,
                COALESCE(SUM(vs.insurance_premium),0) as total_premium,
                COALESCE(SUM(vs.registration_amount),0) as total_registration,
                COALESCE(SUM(vs.rto_charge),0) as total_rto,
                SUM(CASE WHEN vs.insurance_policy_no IS NOT NULL AND vs.insurance_policy_no != '' THEN 1 ELSE 0 END) as ins_done,
                SUM(CASE WHEN vs.insurance_policy_no IS NULL OR vs.insurance_policy_no = '' THEN 1 ELSE 0 END) as ins_pending,
                SUM(CASE WHEN vs.registration_no IS NOT NULL AND vs.registration_no != '' THEN 1 ELSE 0 END) as rto_done,
                SUM(CASE WHEN vs.registration_no IS NULL OR vs.registration_no = '' THEN 1 ELSE 0 END) as rto_pending
             FROM vehicle_sales vs
             INNER JOIN sales_invoices si ON si.id = vs.invoice_id
             WHERE si.business_id = {$businessId} {$branchCondition}";

$statsResult = $conn->query($statsSql);
if ($statsResult && $statsResult->num_rows > 0) {
    $stats = $statsResult->fetch_assoc();
    $totalInsuranceAmount = (float)($stats['total_insurance'] ?? 0);
    $totalInsurancePremium = (float)($stats['total_premium'] ?? 0);
    $totalRegistrationAmount = (float)($stats['total_registration'] ?? 0);
    $totalRtoCharge = (float)($stats['total_rto'] ?? 0);
    $insuranceDone = (int)($stats['ins_done'] ?? 0);
    $insurancePending = (int)($stats['ins_pending'] ?? 0);
    $rtoDone = (int)($stats['rto_done'] ?? 0);
    $rtoPending = (int)($stats['rto_pending'] ?? 0);
}

/* -------------------------------------------------------
   BUILD WHERE CLAUSE FOR MAIN QUERY
------------------------------------------------------- */
$whereConditions = ["si.business_id = {$businessId}"];

// Branch filter
if ($branchFilter > 0) {
    $whereConditions[] = "si.branch_id = {$branchFilter}";
}

// Date range filter
if ($dateFrom !== '') {
    $whereConditions[] = "DATE(si.invoice_date) >= '{$dateFrom}'";
}
if ($dateTo !== '') {
    $whereConditions[] = "DATE(si.invoice_date) <= '{$dateTo}'";
}

// Search filter
if ($search !== '') {
    $safe = $conn->real_escape_string($search);
    $whereConditions[] = "(
        si.invoice_no LIKE '%{$safe}%'
        OR c.full_name LIKE '%{$safe}%'
        OR c.mobile LIKE '%{$safe}%'
        OR br.branch_name LIKE '%{$safe}%'
        OR vstock.chassis_no LIKE '%{$safe}%'
        OR vstock.engine_no LIKE '%{$safe}%'
        OR vs.registration_no LIKE '%{$safe}%'
        OR vs.insurance_policy_no LIKE '%{$safe}%'
    )";
}

// Customer filter
if ($customerFilter > 0) {
    $whereConditions[] = "si.customer_id = {$customerFilter}";
}

// Insurance status filter
if ($insuranceStatus === 'done') {
    $whereConditions[] = "vs.insurance_policy_no IS NOT NULL AND vs.insurance_policy_no != ''";
} elseif ($insuranceStatus === 'pending') {
    $whereConditions[] = "(vs.insurance_policy_no IS NULL OR vs.insurance_policy_no = '')";
} elseif ($insuranceStatus === 'expired') {
    $today = date('Y-m-d');
    $whereConditions[] = "vs.insurance_end_date IS NOT NULL AND vs.insurance_end_date < '{$today}'";
}

// RTO status filter
if ($rtoStatus === 'done') {
    $whereConditions[] = "vs.registration_no IS NOT NULL AND vs.registration_no != ''";
} elseif ($rtoStatus === 'pending') {
    $whereConditions[] = "(vs.registration_no IS NULL OR vs.registration_no = '')";
}

$whereSql = implode(' AND ', $whereConditions);

/* -------------------------------------------------------
   FETCH VEHICLE SALES DATA WITH INSURANCE AND RTO DETAILS
------------------------------------------------------- */
$rows = [];

$sql = "SELECT
            si.id,
            si.invoice_no,
            si.invoice_date,
            si.payment_status,
            si.sale_status,
            si.grand_total,
            si.paid_amount,
            si.balance_amount,

            br.id as branch_id,
            br.branch_name,
            br.branch_code,

            c.id as customer_id,
            c.full_name AS customer_name,
            c.mobile AS customer_mobile,
            c.city AS customer_city,

            vs.id as vehicle_sale_id,
            vs.sale_date,
            vs.ex_showroom_price,
            vs.ex_showroom_cost,
            vs.insurance_amount,
            vs.insurance_policy_no,
            vs.insurance_company,
            vs.insurance_start_date,
            vs.insurance_end_date,
            vs.insurance_idv,
            vs.insurance_premium,
            vs.registration_amount,
            vs.rto_charge,
            vs.road_tax_amount,
            vs.hypothecation_charge,
            vs.handling_charge,
            vs.fastag_charge,
            vs.registration_no,
            vs.temp_registration_no,
            vs.rto_state,
            vs.rto_office,
            vs.rto_file_no,
            vs.rto_application_no,
            vs.battery_brand,
            vs.battery_no,
            vs.battery_capacity,
            vs.battery_warranty_upto,
            vs.charger_brand,
            vs.charger_no,
            vs.charger_type,
            vs.charger_warranty_upto,
            vs.total_vehicle_amount,
            vs.profit_amount,

            vstock.id as stock_id,
            vstock.chassis_no,
            vstock.engine_no,
            vstock.motor_no,
            vstock.color,
            vstock.manufacture_year,
            vstock.key_no,

            vm.model_name,
            vm.variant_name,
            vm.vehicle_type,
            vm.fuel_type,

            vb.brand_name

        FROM sales_invoices si
        INNER JOIN customers c ON c.id = si.customer_id
        INNER JOIN branches br ON br.id = si.branch_id
        INNER JOIN vehicle_sales vs ON vs.invoice_id = si.id AND vs.business_id = si.business_id
        INNER JOIN vehicle_stock vstock ON vstock.id = vs.vehicle_stock_id AND vstock.business_id = si.business_id
        LEFT JOIN vehicle_models vm ON vm.id = vstock.model_id
        LEFT JOIN vehicle_brands vb ON vb.id = vm.brand_id

        WHERE {$whereSql} 
        ORDER BY si.invoice_date DESC, si.id DESC";

$res = $conn->query($sql);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }
}

$pageTitle = 'Insurance & RTO Report';
$currentPage = 'insurance-rto-report';
?>
<!doctype html>
<html lang="en">

<?php include('includes/head.php'); ?>

<body data-sidebar="dark">

<style>
    .status-badge {
        padding: 4px 8px;
        border-radius: 4px;
        font-size: 11px;
        font-weight: 500;
        display: inline-block;
    }
    .status-paid { background: #d4edda; color: #155724; }
    .status-partial { background: #fff3cd; color: #856404; }
    .status-unpaid { background: #f8d7da; color: #721c24; }
    .status-confirmed { background: #d1ecf1; color: #0c5460; }
    .status-draft { background: #e2e3e5; color: #383d41; }
    .status-cancelled { background: #f8d7da; color: #721c24; }
    .status-delivered { background: #d4edda; color: #155724; }
    .status-done { background: #d4edda; color: #155724; }
    .status-pending { background: #fff3cd; color: #856404; }
    .status-expired { background: #f8d7da; color: #721c24; }
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

                <!-- Page Header -->
                <div class="row">
                    <div class="col-12">
                        <div class="card bg-primary text-white">
                            <div class="card-body">
                                <div class="d-flex flex-wrap align-items-center justify-content-between">
                                    <div>
                                        <h4 class="text-white mb-1">Insurance & RTO Report</h4>
                                        <p class="mb-0 opacity-75">Track insurance and registration details for sold vehicles</p>
                                    </div>
                                    <div class="text-end">
                                        <div class="btn-group">
                                            <a href="sales-invoice-add.php?invoice_type=vehicle_sale" class="btn btn-light">
                                                <i class="ri-add-line me-1"></i> New Vehicle Sale
                                            </a>
                                            <a href="sales-invoices.php" class="btn btn-outline-light">
                                                <i class="ri-arrow-left-line me-1"></i> All Invoices
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header bg-light">
                                <h5 class="card-title mb-0">
                                    <i class="ri-flashlight-fill me-2" style="color: #ffc107;"></i>Quick Actions
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-6 col-md-3 col-xl-2">
                                        <a href="sales-invoice-add.php?invoice_type=vehicle_sale" style="text-decoration: none; color: inherit;">
                                            <div class="border rounded p-3 text-center quick-action-card">
                                                <div class="mb-2"><span style="font-size: 28px;">🏍️</span></div>
                                                <h6 class="mb-1">New Vehicle Sale</h6>
                                                <small class="text-muted">Create vehicle invoice</small>
                                            </div>
                                        </a>
                                    </div>
                                    <div class="col-6 col-md-3 col-xl-2">
                                        <a href="vehicle-stock.php" style="text-decoration: none; color: inherit;">
                                            <div class="border rounded p-3 text-center quick-action-card">
                                                <div class="mb-2"><span style="font-size: 28px;">📦</span></div>
                                                <h6 class="mb-1">Vehicle Stock</h6>
                                                <small class="text-muted">Manage inventory</small>
                                            </div>
                                        </a>
                                    </div>
                                    <div class="col-6 col-md-3 col-xl-2">
                                        <a href="customers.php" style="text-decoration: none; color: inherit;">
                                            <div class="border rounded p-3 text-center quick-action-card">
                                                <div class="mb-2"><span style="font-size: 28px;">👤</span></div>
                                                <h6 class="mb-1">Customers</h6>
                                                <small class="text-muted">View customers</small>
                                            </div>
                                        </a>
                                    </div>
                                    <div class="col-6 col-md-3 col-xl-2">
                                        <a href="sales-invoices.php" style="text-decoration: none; color: inherit;">
                                            <div class="border rounded p-3 text-center quick-action-card">
                                                <div class="mb-2"><span style="font-size: 28px;">📋</span></div>
                                                <h6 class="mb-1">All Invoices</h6>
                                                <small class="text-muted">View all sales</small>
                                            </div>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Summary Cards -->
                <div class="row">
                    <div class="col-md-6 col-xl-2">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Vehicle Sales</p>
                                <h3 class="mb-0"><?php echo number_format($totalVehicleSales); ?></h3>
                                <small class="text-muted">Total vehicles sold</small>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-2">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Insurance Amount</p>
                                <h5 class="mb-0 text-primary"><?php echo money($totalInsuranceAmount); ?></h5>
                                <small class="text-muted">Total insurance value</small>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-2">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Insurance Premium</p>
                                <h5 class="mb-0 text-info"><?php echo money($totalInsurancePremium); ?></h5>
                                <small class="text-muted">Total premium paid</small>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-2">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Registration Amount</p>
                                <h5 class="mb-0 text-success"><?php echo money($totalRegistrationAmount); ?></h5>
                                <small class="text-muted">Total registration fees</small>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-2">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">RTO Charges</p>
                                <h5 class="mb-0 text-warning"><?php echo money($totalRtoCharge); ?></h5>
                                <small class="text-muted">Total RTO fees</small>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-2">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Insurance / RTO</p>
                                <h5 class="mb-0">
                                    <span class="text-success"><?php echo number_format($insuranceDone); ?></span>
                                    <span class="text-muted">/</span>
                                    <span class="text-primary"><?php echo number_format($rtoDone); ?></span>
                                </h5>
                                <small class="text-muted">Completed applications</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Status Summary -->
                <div class="row">
                    <div class="col-md-6">
                        <div class="card border-warning">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <i class="ri-shield-line fs-2 text-warning"></i>
                                    </div>
                                    <div class="text-end">
                                        <p class="text-muted mb-1">Insurance Status</p>
                                        <h5 class="mb-0">
                                            <span class="text-success">Done: <?php echo number_format($insuranceDone); ?></span> |
                                            <span class="text-warning">Pending: <?php echo number_format($insurancePending); ?></span>
                                        </h5>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card border-info">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <i class="ri-file-copy-line fs-2 text-info"></i>
                                    </div>
                                    <div class="text-end">
                                        <p class="text-muted mb-1">RTO Status</p>
                                        <h5 class="mb-0">
                                            <span class="text-success">Done: <?php echo number_format($rtoDone); ?></span> |
                                            <span class="text-warning">Pending: <?php echo number_format($rtoPending); ?></span>
                                        </h5>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Branch Info Alert -->
                <?php if ($branchFilter > 0): ?>
                    <?php 
                    $branchName = '';
                    foreach ($branches as $b) {
                        if ($b['id'] == $branchFilter) {
                            $branchName = $b['branch_name'];
                            break;
                        }
                    }
                    ?>
                    <div class="alert alert-info alert-dismissible fade show" role="alert">
                        <i class="ri-information-line me-2"></i>
                        Showing data for branch: <strong><?php echo h($branchName); ?></strong>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php else: ?>
                    <div class="alert alert-secondary alert-dismissible fade show" role="alert">
                        <i class="ri-information-line me-2"></i>
                        Showing data for <strong>All Branches</strong>. Select a branch to filter data by branch.
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Filters -->
                <div class="card">
                    <div class="card-body">
                        <form method="get" class="row g-3">
                            <div class="col-md-3">
                                <input type="text" name="search" class="form-control" placeholder="Search invoice, policy, reg no, chassis..." value="<?php echo h($search); ?>">
                            </div>

                            <div class="col-md-2">
                                <select name="branch_id" class="form-select" onchange="this.form.submit()">
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
                                <select name="insurance_status" class="form-select">
                                    <option value="">All Insurance</option>
                                    <option value="done" <?php echo ($insuranceStatus === 'done') ? 'selected' : ''; ?>>Insurance Done</option>
                                    <option value="pending" <?php echo ($insuranceStatus === 'pending') ? 'selected' : ''; ?>>Insurance Pending</option>
                                    <option value="expired" <?php echo ($insuranceStatus === 'expired') ? 'selected' : ''; ?>>Insurance Expired</option>
                                </select>
                            </div>

                            <div class="col-md-2">
                                <select name="rto_status" class="form-select">
                                    <option value="">All RTO</option>
                                    <option value="done" <?php echo ($rtoStatus === 'done') ? 'selected' : ''; ?>>RTO Done</option>
                                    <option value="pending" <?php echo ($rtoStatus === 'pending') ? 'selected' : ''; ?>>RTO Pending</option>
                                </select>
                            </div>

                            <div class="col-md-1">
                                <input type="date" name="date_from" class="form-control" placeholder="From" value="<?php echo h($dateFrom); ?>">
                            </div>

                            <div class="col-md-1">
                                <input type="date" name="date_to" class="form-control" placeholder="To" value="<?php echo h($dateTo); ?>">
                            </div>

                            <div class="col-md-12 d-flex gap-2">
                                <button type="submit" class="btn btn-primary">
                                    <i class="ri-filter-line me-1"></i> Filter
                                </button>
                                <a href="insurance-rto-report.php" class="btn btn-secondary">
                                    <i class="ri-refresh-line me-1"></i> Reset
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Report table -->
                <div class="card">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0">
                            <i class="ri-file-list-line me-2"></i>Insurance & RTO Details
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>#</th>
                                        <th>Invoice Details</th>
                                        <th>Customer Details</th>
                                        <th>Vehicle Details</th>
                                        <th>Insurance Details</th>
                                        <th>RTO Details</th>
                                        <th>Charges</th>
                                        <th>Payment</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($rows)): ?>
                                        <?php $i = 1; foreach ($rows as $row): ?>
                                            <?php
                                            $insDone = !empty($row['insurance_policy_no']);
                                            $rtoDoneFlag = !empty($row['registration_no']);
                                            $isExpired = !empty($row['insurance_end_date']) && strtotime($row['insurance_end_date']) < time();
                                            ?>
                                            <tr>
                                                <td><?php echo $i++; ?></td>

                                                <!-- Invoice Details -->
                                                <td> class="small">
                                                    <strong><?php echo h($row['invoice_no']); ?></strong>
                                                    <div class="text-muted">Date: <?php echo formatDisplayDateTime($row['invoice_date']); ?></div>
                                                    <div class="text-muted">Branch: <?php echo h($row['branch_name']); ?></div>
                                                    <div class="text-muted">Sale Date: <?php echo formatDisplayDate($row['sale_date']); ?></div>
                                                  </div>

                                                <!-- Customer Details -->
                                                <td> class="small">
                                                    <strong><?php echo h($row['customer_name']); ?></strong>
                                                    <div class="text-muted">Mobile: <?php echo h($row['customer_mobile'] ?: '-'); ?></div>
                                                    <div class="text-muted">City: <?php echo h($row['customer_city'] ?: '-'); ?></div>
                                                  </div>

                                                <!-- Vehicle Details -->
                                                <td> class="small">
                                                    <strong><?php echo h($row['brand_name'] ?? '-') . ' ' . h($row['model_name'] ?? ''); ?></strong>
                                                    <div class="text-muted">Variant: <?php echo h($row['variant_name'] ?: '-'); ?></div>
                                                    <div class="text-muted">Color: <?php echo h($row['color'] ?: '-'); ?></div>
                                                    <div class="text-muted">Chassis: <?php echo h($row['chassis_no'] ?: '-'); ?></div>
                                                    <div class="text-muted">Engine: <?php echo h($row['engine_no'] ?: '-'); ?></div>
                                                    <div class="text-muted">Motor: <?php echo h($row['motor_no'] ?: '-'); ?></div>
                                                    <div class="text-muted">Mfg Year: <?php echo h($row['manufacture_year'] ?: '-'); ?></div>
                                                    <?php if (!empty($row['battery_brand'])): ?>
                                                        <div class="text-muted mt-1">Battery: <?php echo h($row['battery_brand']); ?></div>
                                                        <div class="text-muted">Battery Warranty: <?php echo formatDisplayDate($row['battery_warranty_upto']); ?></div>
                                                    <?php endif; ?>
                                                    <?php if (!empty($row['charger_brand'])): ?>
                                                        <div class="text-muted">Charger: <?php echo h($row['charger_brand']); ?></div>
                                                        <div class="text-muted">Charger Warranty: <?php echo formatDisplayDate($row['charger_warranty_upto']); ?></div>
                                                    <?php endif; ?>
                                                  </div>

                                                <!-- Insurance Details -->
                                                <td> class="small">
                                                    <div class="mb-1">
                                                        <?php if ($isExpired): ?>
                                                            <span class="status-badge status-expired">Expired</span>
                                                        <?php elseif ($insDone): ?>
                                                            <span class="status-badge status-done">Insurance Done</span>
                                                        <?php else: ?>
                                                            <span class="status-badge status-pending">Insurance Pending</span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div><strong>Company:</strong> <?php echo h($row['insurance_company'] ?: '-'); ?></div>
                                                    <div><strong>Policy No:</strong> <?php echo h($row['insurance_policy_no'] ?: '-'); ?></div>
                                                    <div><strong>IDV:</strong> <?php echo money($row['insurance_idv']); ?></div>
                                                    <div><strong>Premium:</strong> <?php echo money($row['insurance_premium']); ?></div>
                                                    <div><strong>Amount:</strong> <?php echo money($row['insurance_amount']); ?></div>
                                                    <div><strong>Start Date:</strong> <?php echo formatDisplayDate($row['insurance_start_date']); ?></div>
                                                    <div><strong>End Date:</strong> <?php echo formatDisplayDate($row['insurance_end_date']); ?></div>
                                                  </div>

                                                <!-- RTO Details -->
                                                <td> class="small">
                                                    <div class="mb-1">
                                                        <?php if ($rtoDoneFlag): ?>
                                                            <span class="status-badge status-done">RTO Done</span>
                                                        <?php else: ?>
                                                            <span class="status-badge status-pending">RTO Pending</span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div><strong>Reg No:</strong> <?php echo h($row['registration_no'] ?: '-'); ?></div>
                                                    <div><strong>Temp Reg:</strong> <?php echo h($row['temp_registration_no'] ?: '-'); ?></div>
                                                    <div><strong>RTO State:</strong> <?php echo h($row['rto_state'] ?: '-'); ?></div>
                                                    <div><strong>RTO Office:</strong> <?php echo h($row['rto_office'] ?: '-'); ?></div>
                                                    <div><strong>File No:</strong> <?php echo h($row['rto_file_no'] ?: '-'); ?></div>
                                                    <div><strong>App No:</strong> <?php echo h($row['rto_application_no'] ?: '-'); ?></div>
                                                  </div>

                                                <!-- Charges -->
                                                <td class="small">
                                                    <div><strong>Ex-Showroom:</strong> <?php echo money($row['ex_showroom_price']); ?></div>
                                                    <div><strong>Insurance:</strong> <?php echo money($row['insurance_amount']); ?></div>
                                                    <div><strong>Registration:</strong> <?php echo money($row['registration_amount']); ?></div>
                                                    <div><strong>RTO Charge:</strong> <?php echo money($row['rto_charge']); ?></div>
                                                    <div><strong>Road Tax:</strong> <?php echo money($row['road_tax_amount']); ?></div>
                                                    <hr class="my-1">
                                                    <div><strong>Total Amount:</strong> <span class="text-success"><?php echo money($row['total_vehicle_amount']); ?></span></div>
                                                    <div><strong>Profit:</strong> <span class="<?php echo ($row['profit_amount'] >= 0) ? 'text-success' : 'text-danger'; ?>"><?php echo money($row['profit_amount']); ?></span></div>
                                                  </div>

                                                <!-- Payment -->
                                                <td class="small text-center">
                                                    <?php
                                                    $payBadge = 'secondary';
                                                    $paymentStatus = strtolower($row['payment_status'] ?? 'unpaid');
                                                    if ($paymentStatus === 'paid') $payBadge = 'success';
                                                    elseif ($paymentStatus === 'partial') $payBadge = 'warning';
                                                    elseif ($paymentStatus === 'unpaid') $payBadge = 'danger';
                                                    ?>
                                                    <span class="status-badge status-<?php echo $paymentStatus; ?>">
                                                        <?php echo ucfirst($paymentStatus); ?>
                                                    </span>
                                                    <div class="mt-2">
                                                        <div><strong>Total:</strong> <?php echo money($row['grand_total']); ?></div>
                                                        <div><strong>Paid:</strong> <?php echo money($row['paid_amount']); ?></div>
                                                        <div><strong>Balance:</strong> <?php echo money($row['balance_amount']); ?></div>
                                                    </div>
                                                    <div class="mt-1">
                                                        <a href="sales-invoice-view.php?id=<?php echo $row['id']; ?>" class="btn btn-sm btn-info" title="View">
                                                            <i class="ri-eye-line"></i>
                                                        </a>
                                                        <a href="sales-invoice-print.php?id=<?php echo $row['id']; ?>" class="btn btn-sm btn-secondary" title="Print" target="_blank">
                                                            <i class="ri-printer-line"></i>
                                                        </a>
                                                    </div>
                                                  </div>
                                              </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="8" class="text-center text-muted py-5">
                                                <i class="ri-car-off-line" style="font-size: 48px;"></i>
                                                <p class="mt-3 mb-0">No vehicle sales records found.</p>
                                                <p class="text-muted small">Try adjusting your filters or create a new vehicle sale.</p>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php if (!empty($rows)): ?>
                        <div class="mt-3 text-muted small">
                            <i class="ri-information-line"></i> Showing <?php echo count($rows); ?> vehicle sale records.
                        </div>
                        <?php endif; ?>
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