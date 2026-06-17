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

function tableExists(mysqli $conn, string $table): bool
{
    $sql = "SELECT 1
            FROM information_schema.TABLES
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

function columnExists(mysqli $conn, string $table, string $column): bool
{
    $sql = "SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            AND COLUMN_NAME = ?
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return false;

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

function getSingleInt(mysqli $conn, string $sql): int
{
    $res = $conn->query($sql);
    if (!$res) return 0;

    $row = $res->fetch_assoc();
    $res->free();

    return (int)($row['total'] ?? 0);
}

function getSingleFloat(mysqli $conn, string $sql): float
{
    $res = $conn->query($sql);
    if (!$res) return 0.00;

    $row = $res->fetch_assoc();
    $res->free();

    return (float)($row['total'] ?? 0);
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
                            WHERE bu.id = ?
                            AND bu.business_id = ?
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
if (!tableExists($conn, 'customers')) {
    die('customers table not found.');
}

$hasSalesInvoices     = tableExists($conn, 'sales_invoices');
$hasServiceInvoices   = tableExists($conn, 'service_invoices');
$hasCustomerVehicles  = tableExists($conn, 'customer_vehicles');
$hasVehicleSales      = tableExists($conn, 'vehicle_sales');
$hasBranches          = tableExists($conn, 'branches');

$salesHasPaid         = $hasSalesInvoices && columnExists($conn, 'sales_invoices', 'paid_amount');
$salesHasBalance      = $hasSalesInvoices && columnExists($conn, 'sales_invoices', 'balance_amount');
$serviceHasPaid       = $hasServiceInvoices && columnExists($conn, 'service_invoices', 'paid_amount');
$serviceHasBalance    = $hasServiceInvoices && columnExists($conn, 'service_invoices', 'balance_amount');
$customerVehicleHasBranch = $hasCustomerVehicles && columnExists($conn, 'customer_vehicles', 'branch_id');
$vehicleSalesHasBranch    = $hasVehicleSales && columnExists($conn, 'vehicle_sales', 'branch_id');

/* -------------------------------------------------------
   MASTER DATA
------------------------------------------------------- */
$branches = [];
if ($hasBranches) {
    $branches = fetchAllAssoc(
        $conn,
        "SELECT id, branch_name, branch_code
         FROM branches
         WHERE business_id = {$businessId}
         ORDER BY branch_name ASC"
    );
}

$cities = fetchAllAssoc(
    $conn,
    "SELECT DISTINCT city
     FROM customers
     WHERE business_id = {$businessId}
     AND city IS NOT NULL
     AND city <> ''
     ORDER BY city ASC"
);

$states = fetchAllAssoc(
    $conn,
    "SELECT DISTINCT state
     FROM customers
     WHERE business_id = {$businessId}
     AND state IS NOT NULL
     AND state <> ''
     ORDER BY state ASC"
);

/* -------------------------------------------------------
   FILTERS
------------------------------------------------------- */
$search       = trim($_GET['search'] ?? '');
$branchFilter = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : 0;
$cityFilter   = trim($_GET['city'] ?? '');
$stateFilter  = trim($_GET['state'] ?? '');
$genderFilter = trim($_GET['gender'] ?? '');

$allowedGender = ['male', 'female', 'other'];

$where = ["c.business_id = {$businessId}"];

if ($search !== '') {
    $safe = $conn->real_escape_string($search);
    $where[] = "(
        c.full_name LIKE '%{$safe}%'
        OR c.mobile LIKE '%{$safe}%'
        OR c.alternate_mobile LIKE '%{$safe}%'
        OR c.email LIKE '%{$safe}%'
        OR c.customer_code LIKE '%{$safe}%'
        OR c.gstin LIKE '%{$safe}%'
        OR c.pan_no LIKE '%{$safe}%'
        OR c.driving_license_no LIKE '%{$safe}%'
        OR c.city LIKE '%{$safe}%'
        OR c.state LIKE '%{$safe}%'
        OR c.district LIKE '%{$safe}%'
    )";
}

if ($cityFilter !== '') {
    $safe = $conn->real_escape_string($cityFilter);
    $where[] = "c.city = '{$safe}'";
}

if ($stateFilter !== '') {
    $safe = $conn->real_escape_string($stateFilter);
    $where[] = "c.state = '{$safe}'";
}

if ($genderFilter !== '' && in_array($genderFilter, $allowedGender, true)) {
    $safe = $conn->real_escape_string($genderFilter);
    $where[] = "c.gender = '{$safe}'";
}

if ($branchFilter > 0) {
    $branchSub = [];

    if ($hasSalesInvoices) {
        $branchSub[] = "EXISTS (
            SELECT 1
            FROM sales_invoices si_b
            WHERE si_b.customer_id = c.id
            AND si_b.business_id = {$businessId}
            AND si_b.branch_id = {$branchFilter}
        )";
    }

    if ($hasServiceInvoices) {
        $branchSub[] = "EXISTS (
            SELECT 1
            FROM service_invoices svi_b
            WHERE svi_b.customer_id = c.id
            AND svi_b.business_id = {$businessId}
            AND svi_b.branch_id = {$branchFilter}
        )";
    }

    if ($customerVehicleHasBranch) {
        $branchSub[] = "EXISTS (
            SELECT 1
            FROM customer_vehicles cv_b
            WHERE cv_b.customer_id = c.id
            AND cv_b.business_id = {$businessId}
            AND cv_b.branch_id = {$branchFilter}
        )";
    }

    if ($vehicleSalesHasBranch) {
        $branchSub[] = "EXISTS (
            SELECT 1
            FROM vehicle_sales vs_b
            WHERE vs_b.customer_id = c.id
            AND vs_b.business_id = {$businessId}
            AND vs_b.branch_id = {$branchFilter}
        )";
    }

    if (!empty($branchSub)) {
        $where[] = '(' . implode(' OR ', $branchSub) . ')';
    }
}

$whereSql = implode(' AND ', $where);

/* -------------------------------------------------------
   CONDITIONS FOR CUSTOMER-WISE SUBQUERIES
------------------------------------------------------- */
$salesBranchCond = ($branchFilter > 0) ? " AND si.branch_id = {$branchFilter}" : "";
$serviceBranchCond = ($branchFilter > 0) ? " AND svi.branch_id = {$branchFilter}" : "";
$customerVehicleBranchCond = ($branchFilter > 0 && $customerVehicleHasBranch) ? " AND cv.branch_id = {$branchFilter}" : "";
$vehicleSalesBranchCond = ($branchFilter > 0 && $vehicleSalesHasBranch) ? " AND vs.branch_id = {$branchFilter}" : "";

$salesPaidExpr = $salesHasPaid ? "COALESCE(si.paid_amount,0)" : "0";
$salesBalanceExpr = $salesHasBalance
    ? "GREATEST(COALESCE(si.balance_amount, COALESCE(si.grand_total,0) - {$salesPaidExpr}),0)"
    : "GREATEST(COALESCE(si.grand_total,0) - {$salesPaidExpr},0)";

$servicePaidExpr = $serviceHasPaid ? "COALESCE(svi.paid_amount,0)" : "0";
$serviceBalanceExpr = $serviceHasBalance
    ? "GREATEST(COALESCE(svi.balance_amount, COALESCE(svi.grand_total,0) - {$servicePaidExpr}),0)"
    : "GREATEST(COALESCE(svi.grand_total,0) - {$servicePaidExpr},0)";

/* -------------------------------------------------------
   SUMMARY - SAME FILTER BASED
------------------------------------------------------- */
$totalCustomers = getSingleInt(
    $conn,
    "SELECT COUNT(*) AS total
     FROM customers
     WHERE business_id = {$businessId}"
);

$filteredCustomers = getSingleInt(
    $conn,
    "SELECT COUNT(*) AS total
     FROM customers c
     WHERE {$whereSql}"
);

$filteredCustomerSql = "
    SELECT c.id
    FROM customers c
    WHERE {$whereSql}
";

$totalSalesCustomers = 0;
$totalServiceCustomers = 0;
$totalVehicleOwners = 0;
$totalReceivable = 0.00;

if ($hasSalesInvoices) {
    $totalSalesCustomers = getSingleInt(
        $conn,
        "SELECT COUNT(DISTINCT si.customer_id) AS total
         FROM sales_invoices si
         INNER JOIN ({$filteredCustomerSql}) fc ON fc.id = si.customer_id
         WHERE si.business_id = {$businessId}
         {$salesBranchCond}"
    );

    $totalReceivable += getSingleFloat(
        $conn,
        "SELECT COALESCE(SUM({$salesBalanceExpr}),0) AS total
         FROM sales_invoices si
         INNER JOIN ({$filteredCustomerSql}) fc ON fc.id = si.customer_id
         WHERE si.business_id = {$businessId}
         {$salesBranchCond}"
    );
}

if ($hasServiceInvoices) {
    $totalServiceCustomers = getSingleInt(
        $conn,
        "SELECT COUNT(DISTINCT svi.customer_id) AS total
         FROM service_invoices svi
         INNER JOIN ({$filteredCustomerSql}) fc ON fc.id = svi.customer_id
         WHERE svi.business_id = {$businessId}
         {$serviceBranchCond}"
    );

    $totalReceivable += getSingleFloat(
        $conn,
        "SELECT COALESCE(SUM({$serviceBalanceExpr}),0) AS total
         FROM service_invoices svi
         INNER JOIN ({$filteredCustomerSql}) fc ON fc.id = svi.customer_id
         WHERE svi.business_id = {$businessId}
         {$serviceBranchCond}"
    );
}

if ($hasCustomerVehicles) {
    $totalVehicleOwners = getSingleInt(
        $conn,
        "SELECT COUNT(DISTINCT cv.customer_id) AS total
         FROM customer_vehicles cv
         INNER JOIN ({$filteredCustomerSql}) fc ON fc.id = cv.customer_id
         WHERE cv.business_id = {$businessId}
         {$customerVehicleBranchCond}"
    );
}

$today = date('Y-m-d');
$newCustomersToday = getSingleInt(
    $conn,
    "SELECT COUNT(*) AS total
     FROM customers c
     WHERE {$whereSql}
     AND DATE(c.created_at) = '{$today}'"
);

/* -------------------------------------------------------
   CUSTOMER REPORT
------------------------------------------------------- */
$rows = fetchAllAssoc(
    $conn,
    "SELECT
        c.*,

        " . ($hasCustomerVehicles ? "(
            SELECT COUNT(*)
            FROM customer_vehicles cv
            WHERE cv.customer_id = c.id
            AND cv.business_id = {$businessId}
            {$customerVehicleBranchCond}
        )" : "0") . " AS vehicle_count,

        " . ($hasSalesInvoices ? "(
            SELECT COUNT(*)
            FROM sales_invoices si
            WHERE si.customer_id = c.id
            AND si.business_id = {$businessId}
            {$salesBranchCond}
        )" : "0") . " AS sales_invoice_count,

        " . ($hasSalesInvoices ? "(
            SELECT COALESCE(SUM(si.grand_total),0)
            FROM sales_invoices si
            WHERE si.customer_id = c.id
            AND si.business_id = {$businessId}
            {$salesBranchCond}
        )" : "0") . " AS sales_total,

        " . ($hasSalesInvoices ? "(
            SELECT COALESCE(SUM({$salesPaidExpr}),0)
            FROM sales_invoices si
            WHERE si.customer_id = c.id
            AND si.business_id = {$businessId}
            {$salesBranchCond}
        )" : "0") . " AS sales_paid,

        " . ($hasSalesInvoices ? "(
            SELECT COALESCE(SUM({$salesBalanceExpr}),0)
            FROM sales_invoices si
            WHERE si.customer_id = c.id
            AND si.business_id = {$businessId}
            {$salesBranchCond}
        )" : "0") . " AS sales_balance,

        " . ($hasServiceInvoices ? "(
            SELECT COUNT(*)
            FROM service_invoices svi
            WHERE svi.customer_id = c.id
            AND svi.business_id = {$businessId}
            {$serviceBranchCond}
        )" : "0") . " AS service_invoice_count,

        " . ($hasServiceInvoices ? "(
            SELECT COALESCE(SUM(svi.grand_total),0)
            FROM service_invoices svi
            WHERE svi.customer_id = c.id
            AND svi.business_id = {$businessId}
            {$serviceBranchCond}
        )" : "0") . " AS service_total,

        " . ($hasServiceInvoices ? "(
            SELECT COALESCE(SUM({$servicePaidExpr}),0)
            FROM service_invoices svi
            WHERE svi.customer_id = c.id
            AND svi.business_id = {$businessId}
            {$serviceBranchCond}
        )" : "0") . " AS service_paid,

        " . ($hasServiceInvoices ? "(
            SELECT COALESCE(SUM({$serviceBalanceExpr}),0)
            FROM service_invoices svi
            WHERE svi.customer_id = c.id
            AND svi.business_id = {$businessId}
            {$serviceBranchCond}
        )" : "0") . " AS service_balance,

        " . ($hasVehicleSales ? "(
            SELECT COUNT(*)
            FROM vehicle_sales vs
            WHERE vs.customer_id = c.id
            AND vs.business_id = {$businessId}
            {$vehicleSalesBranchCond}
        )" : "0") . " AS vehicle_sales_count

     FROM customers c
     WHERE {$whereSql}
     ORDER BY c.id DESC
     LIMIT 500"
);

$pageTitle = 'Customer Report';
$currentPage = 'customer-report';
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
                        <h4 class="mb-1">Customer Report</h4>
                        <p class="text-muted mb-0">Customer list, sales summary, service summary, vehicles and receivables</p>
                    </div>
                    <div class="col-md-5 text-md-end mt-3 mt-md-0">
                        <a href="customer-add.php" class="btn btn-primary me-2">Add Customer</a>
                        <a href="customers.php" class="btn btn-secondary">View Customers</a>
                    </div>
                </div>

                <!-- SUMMARY -->
                <div class="row">
                    <div class="col-md-2">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Total Customers</p>
                                <h4 class="mb-0"><?php echo number_format($totalCustomers); ?></h4>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-2">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Sales Customers</p>
                                <h4 class="mb-0 text-primary"><?php echo number_format($totalSalesCustomers); ?></h4>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-2">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Service Customers</p>
                                <h4 class="mb-0 text-success"><?php echo number_format($totalServiceCustomers); ?></h4>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-2">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Vehicle Owners</p>
                                <h4 class="mb-0 text-info"><?php echo number_format($totalVehicleOwners); ?></h4>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-2">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Receivable</p>
                                <h4 class="mb-0 text-danger"><?php echo money($totalReceivable); ?></h4>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-2">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">New Today</p>
                                <h4 class="mb-0 text-warning"><?php echo number_format($newCustomersToday); ?></h4>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- FILTER -->
                <div class="card">
                    <div class="card-body">
                        <form method="get" class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label">Search</label>
                                <input
                                    type="text"
                                    name="search"
                                    class="form-control"
                                    placeholder="Name, mobile, GSTIN, PAN..."
                                    value="<?php echo h($search); ?>"
                                >
                            </div>

                            <?php if ($hasBranches): ?>
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
                            <?php endif; ?>

                            <div class="col-md-2">
                                <label class="form-label">City</label>
                                <select name="city" class="form-select">
                                    <option value="">All Cities</option>
                                    <?php foreach ($cities as $row): ?>
                                        <option value="<?php echo h($row['city']); ?>" <?php echo ($cityFilter === (string)$row['city']) ? 'selected' : ''; ?>>
                                            <?php echo h($row['city']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">State</label>
                                <select name="state" class="form-select">
                                    <option value="">All States</option>
                                    <?php foreach ($states as $row): ?>
                                        <option value="<?php echo h($row['state']); ?>" <?php echo ($stateFilter === (string)$row['state']) ? 'selected' : ''; ?>>
                                            <?php echo h($row['state']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">Gender</label>
                                <select name="gender" class="form-select">
                                    <option value="">All</option>
                                    <option value="male" <?php echo ($genderFilter === 'male') ? 'selected' : ''; ?>>Male</option>
                                    <option value="female" <?php echo ($genderFilter === 'female') ? 'selected' : ''; ?>>Female</option>
                                    <option value="other" <?php echo ($genderFilter === 'other') ? 'selected' : ''; ?>>Other</option>
                                </select>
                            </div>

                            <div class="col-md-12 d-flex gap-2">
                                <button type="submit" class="btn btn-primary">Filter</button>
                                <a href="customer-report.php" class="btn btn-light">Reset</a>
                                <span class="btn btn-outline-secondary disabled">Filtered: <?php echo number_format($filteredCustomers); ?></span>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- REPORT TABLE -->
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4">Customer Report List</h4>

                        <div class="table-responsive">
                            <table class="table table-bordered table-striped align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Customer</th>
                                        <th>Contact</th>
                                        <th>Address</th>
                                        <th>Identity</th>
                                        <th>Vehicles</th>
                                        <th>Sales</th>
                                        <th>Service</th>
                                        <th>Receivable</th>
                                        <th style="width:220px;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($rows)): ?>
                                        <?php $i = 1; foreach ($rows as $row): ?>
                                            <?php
                                            $totalBalance = (float)($row['sales_balance'] ?? 0) + (float)($row['service_balance'] ?? 0);
                                            ?>
                                            <tr>
                                                <td><?php echo $i++; ?></td>

                                                <td class="small">
                                                    <div><strong><?php echo h($row['full_name'] ?: '-'); ?></strong></div>
                                                    <div class="text-muted">Code: <?php echo h($row['customer_code'] ?: '-'); ?></div>
                                                    <div class="text-muted">Gender: <?php echo h($row['gender'] ? ucfirst((string)$row['gender']) : '-'); ?></div>
                                                    <div class="text-muted">DOB: <?php echo !empty($row['dob']) ? h(date('d M Y', strtotime($row['dob']))) : '-'; ?></div>
                                                </td>

                                                <td class="small">
                                                    <div><strong>Mobile:</strong> <?php echo h($row['mobile'] ?: '-'); ?></div>
                                                    <div><strong>Alt:</strong> <?php echo h($row['alternate_mobile'] ?: '-'); ?></div>
                                                    <div><strong>Email:</strong> <?php echo h($row['email'] ?: '-'); ?></div>
                                                </td>

                                                <td class="small">
                                                    <div><?php echo h($row['address_line1'] ?: '-'); ?></div>
                                                    <div><?php echo h($row['address_line2'] ?: ''); ?></div>
                                                    <div>
                                                        <?php echo h($row['city'] ?: '-'); ?>,
                                                        <?php echo h($row['district'] ?: '-'); ?>,
                                                        <?php echo h($row['state'] ?: '-'); ?>
                                                    </div>
                                                    <div><?php echo h($row['pincode'] ?: '-'); ?></div>
                                                </td>

                                                <td class="small">
                                                    <div><strong>GSTIN:</strong> <?php echo h($row['gstin'] ?: '-'); ?></div>
                                                    <div><strong>PAN:</strong> <?php echo h($row['pan_no'] ?: '-'); ?></div>
                                                    <div><strong>Aadhar:</strong> <?php echo h($row['aadhar_no'] ?: '-'); ?></div>
                                                    <div><strong>DL:</strong> <?php echo h($row['driving_license_no'] ?: '-'); ?></div>
                                                </td>

                                                <td class="small">
                                                    <div><strong>Owned:</strong> <?php echo number_format((int)($row['vehicle_count'] ?? 0)); ?></div>
                                                    <div><strong>Vehicle Sales:</strong> <?php echo number_format((int)($row['vehicle_sales_count'] ?? 0)); ?></div>
                                                </td>

                                                <td class="small">
                                                    <div><strong>Invoices:</strong> <?php echo number_format((int)($row['sales_invoice_count'] ?? 0)); ?></div>
                                                    <div><strong>Total:</strong> <?php echo money($row['sales_total'] ?? 0); ?></div>
                                                    <div><strong>Paid:</strong> <?php echo money($row['sales_paid'] ?? 0); ?></div>
                                                    <div><strong>Balance:</strong> <?php echo money($row['sales_balance'] ?? 0); ?></div>
                                                </td>

                                                <td class="small">
                                                    <div><strong>Invoices:</strong> <?php echo number_format((int)($row['service_invoice_count'] ?? 0)); ?></div>
                                                    <div><strong>Total:</strong> <?php echo money($row['service_total'] ?? 0); ?></div>
                                                    <div><strong>Paid:</strong> <?php echo money($row['service_paid'] ?? 0); ?></div>
                                                    <div><strong>Balance:</strong> <?php echo money($row['service_balance'] ?? 0); ?></div>
                                                </td>

                                                <td>
                                                    <?php if ($totalBalance > 0): ?>
                                                        <span class="badge bg-danger"><?php echo money($totalBalance); ?></span>
                                                    <?php else: ?>
                                                        <span class="badge bg-success">₹0.00</span>
                                                    <?php endif; ?>
                                                </td>

                                                <td>
                                                    <div class="d-flex flex-wrap gap-2">
                                                        <a href="customer-view.php?id=<?php echo (int)$row['id']; ?>" class="btn btn-sm btn-info">View</a>
                                                        <a href="customer-edit.php?id=<?php echo (int)$row['id']; ?>" class="btn btn-sm btn-primary">Edit</a>
                                                        <a href="customer-vehicles.php?customer_id=<?php echo (int)$row['id']; ?>" class="btn btn-sm btn-success">Vehicles</a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="10" class="text-center text-muted py-4">No customer records found.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="mt-3 text-muted small">
                            Showing latest 500 customer records.
                        </div>
                    </div>
                </div>

                <div class="row report-last-row">
                    <div class="col-xl-6">
                        <div class="card h-100">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Customer Summary</h4>
                                <table class="table table-bordered table-striped mb-0">
                                    <tbody>
                                        <tr>
                                            <th style="width:50%;">Total Customers</th>
                                            <td><?php echo number_format($totalCustomers); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Filtered Customers</th>
                                            <td><?php echo number_format($filteredCustomers); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Customers With Sales</th>
                                            <td><?php echo number_format($totalSalesCustomers); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Customers With Service</th>
                                            <td><?php echo number_format($totalServiceCustomers); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Vehicle Owners</th>
                                            <td><?php echo number_format($totalVehicleOwners); ?></td>
                                        </tr>
                                        <tr>
                                            <th>New Customers Today</th>
                                            <td><?php echo number_format($newCustomersToday); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Total Receivable</th>
                                            <td><strong class="text-danger"><?php echo money($totalReceivable); ?></strong></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-6">
                        <div class="card h-100">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Notes</h4>
                                <div class="small text-muted">
                                    <p class="mb-2">All sales, service, vehicle and receivable calculations now follow the selected filters.</p>
                                    <p class="mb-2">If balance_amount is missing or empty, balance is calculated from grand_total minus paid_amount.</p>
                                    <p class="mb-0">Branch filter checks sales invoices, service invoices, customer vehicles and vehicle sales when those tables/columns exist.</p>
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