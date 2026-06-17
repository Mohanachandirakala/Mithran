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
    $stmt = $conn->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1");
    if (!$stmt) return false;
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $res = $stmt->get_result();
    $ok = $res && $res->num_rows > 0;
    $stmt->close();
    return $ok;
}

function columnExists(mysqli $conn, string $table, string $column): bool
{
    $stmt = $conn->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
    if (!$stmt) return false;
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $res = $stmt->get_result();
    $ok = $res && $res->num_rows > 0;
    $stmt->close();
    return $ok;
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

function getOneInt(mysqli $conn, string $sql): int
{
    $res = $conn->query($sql);
    if (!$res) return 0;
    $row = $res->fetch_assoc();
    $res->free();
    return (int)($row['total'] ?? 0);
}

function getOneFloat(mysqli $conn, string $sql): float
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
if (!tableExists($conn, 'branches')) {
    die('branches table not found.');
}

$hasSalesInvoices   = tableExists($conn, 'sales_invoices');
$hasServiceInvoices = tableExists($conn, 'service_invoices');
$hasExpenses        = tableExists($conn, 'expenses');
$hasPayments        = tableExists($conn, 'payments');
$hasProductStock    = tableExists($conn, 'product_stock');
$hasProducts        = tableExists($conn, 'products');
$hasVehicleStock    = tableExists($conn, 'vehicle_stock');

$branchHasStatus = columnExists($conn, 'branches', 'status');
$branchHasHeadOffice = columnExists($conn, 'branches', 'is_head_office');

$salesHasPaid = $hasSalesInvoices && columnExists($conn, 'sales_invoices', 'paid_amount');
$salesHasBalance = $hasSalesInvoices && columnExists($conn, 'sales_invoices', 'balance_amount');

$serviceHasPaid = $hasServiceInvoices && columnExists($conn, 'service_invoices', 'paid_amount');
$serviceHasBalance = $hasServiceInvoices && columnExists($conn, 'service_invoices', 'balance_amount');

$expenseHasBranch = $hasExpenses && columnExists($conn, 'expenses', 'branch_id');
$paymentHasBranch = $hasPayments && columnExists($conn, 'payments', 'branch_id');

$productStockHasQty = $hasProductStock && columnExists($conn, 'product_stock', 'qty_available');
$productStockHasBranch = $hasProductStock && columnExists($conn, 'product_stock', 'branch_id');

$productHasStockQty = $hasProducts && columnExists($conn, 'products', 'stock_qty');

$vehicleHasBranch = $hasVehicleStock && columnExists($conn, 'vehicle_stock', 'branch_id');
$vehicleHasStatus = $hasVehicleStock && columnExists($conn, 'vehicle_stock', 'stock_status');

/* -------------------------------------------------------
   FILTERS
------------------------------------------------------- */
$search = trim($_GET['search'] ?? '');
$branchFilter = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : 0;
$statusFilter = trim($_GET['status'] ?? '');
$cityFilter = trim($_GET['city'] ?? '');
$stateFilter = trim($_GET['state'] ?? '');

$allowedStatus = ['active', 'inactive'];

$where = ["b.business_id = {$businessId}"];

if ($search !== '') {
    $safe = $conn->real_escape_string($search);
    $where[] = "(
        b.branch_name LIKE '%{$safe}%'
        OR b.branch_code LIKE '%{$safe}%'
        OR b.contact_person LIKE '%{$safe}%'
        OR b.email LIKE '%{$safe}%'
        OR b.mobile LIKE '%{$safe}%'
        OR b.city LIKE '%{$safe}%'
        OR b.district LIKE '%{$safe}%'
        OR b.state LIKE '%{$safe}%'
        OR b.pincode LIKE '%{$safe}%'
    )";
}

if ($branchFilter > 0) {
    $where[] = "b.id = {$branchFilter}";
}

if ($branchHasStatus && $statusFilter !== '' && in_array($statusFilter, $allowedStatus, true)) {
    $safe = $conn->real_escape_string($statusFilter);
    $where[] = "b.status = '{$safe}'";
}

if ($cityFilter !== '') {
    $safe = $conn->real_escape_string($cityFilter);
    $where[] = "b.city = '{$safe}'";
}

if ($stateFilter !== '') {
    $safe = $conn->real_escape_string($stateFilter);
    $where[] = "b.state = '{$safe}'";
}

$whereSql = implode(' AND ', $where);

/* -------------------------------------------------------
   MASTER DATA
------------------------------------------------------- */
$branches = fetchAllAssoc($conn, "
    SELECT id, branch_name, branch_code
    FROM branches
    WHERE business_id = {$businessId}
    ORDER BY branch_name ASC
");

$cities = fetchAllAssoc($conn, "
    SELECT DISTINCT city
    FROM branches
    WHERE business_id = {$businessId}
    AND city IS NOT NULL
    AND city <> ''
    ORDER BY city ASC
");

$states = fetchAllAssoc($conn, "
    SELECT DISTINCT state
    FROM branches
    WHERE business_id = {$businessId}
    AND state IS NOT NULL
    AND state <> ''
    ORDER BY state ASC
");

/* -------------------------------------------------------
   SUMMARY CARDS
------------------------------------------------------- */
$totalBranches = getOneInt($conn, "SELECT COUNT(*) AS total FROM branches WHERE business_id = {$businessId}");
$activeBranches = $branchHasStatus
    ? getOneInt($conn, "SELECT COUNT(*) AS total FROM branches WHERE business_id = {$businessId} AND status = 'active'")
    : $totalBranches;
$inactiveBranches = $branchHasStatus
    ? getOneInt($conn, "SELECT COUNT(*) AS total FROM branches WHERE business_id = {$businessId} AND status = 'inactive'")
    : 0;
$headOfficeCount = $branchHasHeadOffice
    ? getOneInt($conn, "SELECT COUNT(*) AS total FROM branches WHERE business_id = {$businessId} AND is_head_office = 1")
    : 0;

$filteredBranches = getOneInt($conn, "SELECT COUNT(*) AS total FROM branches b WHERE {$whereSql}");

$salesPaidExpr = $salesHasPaid ? "COALESCE(paid_amount,0)" : "0";
$salesBalanceExpr = $salesHasBalance ? "GREATEST(COALESCE(balance_amount,0),0)" : "GREATEST(COALESCE(grand_total,0) - {$salesPaidExpr},0)";

$servicePaidExpr = $serviceHasPaid ? "COALESCE(paid_amount,0)" : "0";
$serviceBalanceExpr = $serviceHasBalance ? "GREATEST(COALESCE(balance_amount,0),0)" : "GREATEST(COALESCE(grand_total,0) - {$servicePaidExpr},0)";

$totalSales = $hasSalesInvoices
    ? getOneFloat($conn, "SELECT COALESCE(SUM(grand_total),0) AS total FROM sales_invoices WHERE business_id = {$businessId}")
    : 0.00;

$totalSalesPaid = $hasSalesInvoices
    ? getOneFloat($conn, "SELECT COALESCE(SUM({$salesPaidExpr}),0) AS total FROM sales_invoices WHERE business_id = {$businessId}")
    : 0.00;

$totalSalesBalance = $hasSalesInvoices
    ? getOneFloat($conn, "SELECT COALESCE(SUM({$salesBalanceExpr}),0) AS total FROM sales_invoices WHERE business_id = {$businessId}")
    : 0.00;

$totalService = $hasServiceInvoices
    ? getOneFloat($conn, "SELECT COALESCE(SUM(grand_total),0) AS total FROM service_invoices WHERE business_id = {$businessId}")
    : 0.00;

$totalServicePaid = $hasServiceInvoices
    ? getOneFloat($conn, "SELECT COALESCE(SUM({$servicePaidExpr}),0) AS total FROM service_invoices WHERE business_id = {$businessId}")
    : 0.00;

$totalServiceBalance = $hasServiceInvoices
    ? getOneFloat($conn, "SELECT COALESCE(SUM({$serviceBalanceExpr}),0) AS total FROM service_invoices WHERE business_id = {$businessId}")
    : 0.00;

$totalExpenses = $hasExpenses
    ? getOneFloat($conn, "SELECT COALESCE(SUM(amount),0) AS total FROM expenses WHERE business_id = {$businessId}")
    : 0.00;

$totalCollections = $hasPayments
    ? getOneFloat($conn, "SELECT COALESCE(SUM(amount),0) AS total FROM payments WHERE business_id = {$businessId}")
    : 0.00;

if ($hasProductStock && $productStockHasQty) {
    $totalProductQty = getOneFloat($conn, "SELECT COALESCE(SUM(qty_available),0) AS total FROM product_stock WHERE business_id = {$businessId}");
} elseif ($hasProducts && $productHasStockQty) {
    $totalProductQty = getOneFloat($conn, "SELECT COALESCE(SUM(stock_qty),0) AS total FROM products WHERE business_id = {$businessId}");
} else {
    $totalProductQty = 0.00;
}

$totalVehicles = $hasVehicleStock
    ? getOneInt($conn, "SELECT COUNT(*) AS total FROM vehicle_stock WHERE business_id = {$businessId}")
    : 0;

/* -------------------------------------------------------
   BRANCH REPORT ROWS
------------------------------------------------------- */
$salesBranchPaidSub = $hasSalesInvoices
    ? "(SELECT COALESCE(SUM({$salesPaidExpr}),0) FROM sales_invoices si WHERE si.branch_id = b.id AND si.business_id = {$businessId})"
    : "0";

$salesBranchBalanceSub = $hasSalesInvoices
    ? "(SELECT COALESCE(SUM({$salesBalanceExpr}),0) FROM sales_invoices si WHERE si.branch_id = b.id AND si.business_id = {$businessId})"
    : "0";

$serviceBranchPaidSub = $hasServiceInvoices
    ? "(SELECT COALESCE(SUM({$servicePaidExpr}),0) FROM service_invoices svi WHERE svi.branch_id = b.id AND svi.business_id = {$businessId})"
    : "0";

$serviceBranchBalanceSub = $hasServiceInvoices
    ? "(SELECT COALESCE(SUM({$serviceBalanceExpr}),0) FROM service_invoices svi WHERE svi.branch_id = b.id AND svi.business_id = {$businessId})"
    : "0";

$productQtyBranchSub = "0";
$productStockLinesSub = "0";

if ($hasProductStock && $productStockHasBranch && $productStockHasQty) {
    $productStockLinesSub = "(SELECT COUNT(*) FROM product_stock ps WHERE ps.branch_id = b.id AND ps.business_id = {$businessId})";
    $productQtyBranchSub = "(SELECT COALESCE(SUM(ps.qty_available),0) FROM product_stock ps WHERE ps.branch_id = b.id AND ps.business_id = {$businessId})";
} elseif ($hasProducts && $productHasStockQty) {
    $productStockLinesSub = "(SELECT COUNT(*) FROM products p WHERE p.business_id = {$businessId})";
    $productQtyBranchSub = "(SELECT COALESCE(SUM(p.stock_qty),0) FROM products p WHERE p.business_id = {$businessId})";
}

$vehicleTotalSub = ($hasVehicleStock && $vehicleHasBranch)
    ? "(SELECT COUNT(*) FROM vehicle_stock vs WHERE vs.branch_id = b.id AND vs.business_id = {$businessId})"
    : "0";

$vehicleInStockSub = ($hasVehicleStock && $vehicleHasBranch && $vehicleHasStatus)
    ? "(SELECT COUNT(*) FROM vehicle_stock vs WHERE vs.branch_id = b.id AND vs.business_id = {$businessId} AND vs.stock_status = 'in_stock')"
    : "0";

$expenseSub = ($hasExpenses && $expenseHasBranch)
    ? "(SELECT COALESCE(SUM(e.amount),0) FROM expenses e WHERE e.branch_id = b.id AND e.business_id = {$businessId})"
    : "0";

$paymentCountSub = ($hasPayments && $paymentHasBranch)
    ? "(SELECT COUNT(*) FROM payments p WHERE p.branch_id = b.id AND p.business_id = {$businessId})"
    : "0";

$collectionSub = ($hasPayments && $paymentHasBranch)
    ? "(SELECT COALESCE(SUM(p.amount),0) FROM payments p WHERE p.branch_id = b.id AND p.business_id = {$businessId})"
    : "0";

$rows = fetchAllAssoc($conn, "
    SELECT
        b.*,

        " . ($hasSalesInvoices ? "(
            SELECT COUNT(*)
            FROM sales_invoices si
            WHERE si.branch_id = b.id
            AND si.business_id = {$businessId}
        )" : "0") . " AS sales_invoice_count,

        " . ($hasSalesInvoices ? "(
            SELECT COALESCE(SUM(si.grand_total),0)
            FROM sales_invoices si
            WHERE si.branch_id = b.id
            AND si.business_id = {$businessId}
        )" : "0") . " AS sales_total,

        {$salesBranchPaidSub} AS sales_paid,
        {$salesBranchBalanceSub} AS sales_balance,

        " . ($hasServiceInvoices ? "(
            SELECT COUNT(*)
            FROM service_invoices svi
            WHERE svi.branch_id = b.id
            AND svi.business_id = {$businessId}
        )" : "0") . " AS service_invoice_count,

        " . ($hasServiceInvoices ? "(
            SELECT COALESCE(SUM(svi.grand_total),0)
            FROM service_invoices svi
            WHERE svi.branch_id = b.id
            AND svi.business_id = {$businessId}
        )" : "0") . " AS service_total,

        {$serviceBranchPaidSub} AS service_paid,
        {$serviceBranchBalanceSub} AS service_balance,

        {$expenseSub} AS expense_total,
        {$paymentCountSub} AS payment_count,
        {$collectionSub} AS collection_total,
        {$productStockLinesSub} AS product_stock_lines,
        {$productQtyBranchSub} AS product_qty_total,
        {$vehicleTotalSub} AS vehicle_stock_count,
        {$vehicleInStockSub} AS vehicle_instock_count

    FROM branches b
    WHERE {$whereSql}
    ORDER BY b.branch_name ASC
    LIMIT 500
");

$pageTitle = 'Branch Report';
$currentPage = 'branch-report';
?>
<!doctype html>
<html lang="en">

<?php include('includes/head.php'); ?>

<body data-sidebar="dark">

<style>
    .page-content { padding-bottom: 90px !important; }
    .report-last-row { margin-bottom: 40px; }
    .card { margin-bottom: 24px; }
    .table-responsive { overflow-x: auto; }
    .main-content { min-height: calc(100vh - 70px); }
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
                        <h4 class="mb-1">Branch Report</h4>
                        <p class="text-muted mb-0">Branch-wise sales, service, expenses, collections and stock summary</p>
                    </div>
                    <div class="col-md-5 text-md-end mt-3 mt-md-0">
                        <a href="branch-add.php" class="btn btn-primary me-2">Add Branch</a>
                        <a href="branches.php" class="btn btn-secondary">View Branches</a>
                    </div>
                </div>

                <!-- BRANCH SUMMARY -->
                <div class="row">
                    <div class="col-md-2"><div class="card"><div class="card-body text-center"><p class="text-muted mb-1">Total Branches</p><h4 class="mb-0"><?php echo number_format($totalBranches); ?></h4></div></div></div>
                    <div class="col-md-2"><div class="card"><div class="card-body text-center"><p class="text-muted mb-1">Active</p><h4 class="mb-0 text-success"><?php echo number_format($activeBranches); ?></h4></div></div></div>
                    <div class="col-md-2"><div class="card"><div class="card-body text-center"><p class="text-muted mb-1">Inactive</p><h4 class="mb-0 text-danger"><?php echo number_format($inactiveBranches); ?></h4></div></div></div>
                    <div class="col-md-2"><div class="card"><div class="card-body text-center"><p class="text-muted mb-1">Head Office</p><h4 class="mb-0 text-primary"><?php echo number_format($headOfficeCount); ?></h4></div></div></div>
                    <div class="col-md-2"><div class="card"><div class="card-body text-center"><p class="text-muted mb-1">Filtered</p><h4 class="mb-0 text-info"><?php echo number_format($filteredBranches); ?></h4></div></div></div>
                    <div class="col-md-2"><div class="card"><div class="card-body text-center"><p class="text-muted mb-1">Vehicles</p><h4 class="mb-0"><?php echo number_format($totalVehicles); ?></h4></div></div></div>
                </div>

                <!-- VALUE SUMMARY -->
                <div class="row">
                    <div class="col-md-3"><div class="card"><div class="card-body text-center"><p class="text-muted mb-1">Sales Total</p><h4 class="mb-0 text-primary"><?php echo money($totalSales); ?></h4><small>Paid: <?php echo money($totalSalesPaid); ?> | Bal: <?php echo money($totalSalesBalance); ?></small></div></div></div>
                    <div class="col-md-3"><div class="card"><div class="card-body text-center"><p class="text-muted mb-1">Service Total</p><h4 class="mb-0 text-success"><?php echo money($totalService); ?></h4><small>Paid: <?php echo money($totalServicePaid); ?> | Bal: <?php echo money($totalServiceBalance); ?></small></div></div></div>
                    <div class="col-md-2"><div class="card"><div class="card-body text-center"><p class="text-muted mb-1">Expenses</p><h4 class="mb-0 text-danger"><?php echo money($totalExpenses); ?></h4></div></div></div>
                    <div class="col-md-2"><div class="card"><div class="card-body text-center"><p class="text-muted mb-1">Collections</p><h4 class="mb-0 text-info"><?php echo money($totalCollections); ?></h4></div></div></div>
                    <div class="col-md-2"><div class="card"><div class="card-body text-center"><p class="text-muted mb-1">Product Qty</p><h4 class="mb-0"><?php echo qtyf($totalProductQty); ?></h4></div></div></div>
                </div>

                <!-- FILTER -->
                <div class="card">
                    <div class="card-body">
                        <form method="get" class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label">Search</label>
                                <input type="text" name="search" class="form-control" placeholder="Branch, code, city, mobile..." value="<?php echo h($search); ?>">
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
                                <label class="form-label">Status</label>
                                <select name="status" class="form-select">
                                    <option value="">All Status</option>
                                    <option value="active" <?php echo ($statusFilter === 'active') ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo ($statusFilter === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                            </div>

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

                            <div class="col-md-1 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary w-100">Go</button>
                            </div>

                            <div class="col-md-12 d-flex gap-2">
                                <a href="branch-report.php" class="btn btn-light">Reset</a>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- BRANCH REPORT TABLE -->
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4">Branch Report List</h4>

                        <div class="table-responsive">
                            <table class="table table-bordered table-striped align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Branch</th>
                                        <th>Contact</th>
                                        <th>Address</th>
                                        <th>Sales</th>
                                        <th>Service</th>
                                        <th>Expense / Collection</th>
                                        <th>Stock</th>
                                        <th>Status</th>
                                        <th style="width:220px;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($rows)): ?>
                                        <?php $i = 1; foreach ($rows as $row): ?>
                                            <?php
                                            $branchNet = ((float)$row['collection_total']) - (float)$row['expense_total'];
                                            ?>
                                            <tr>
                                                <td><?php echo $i++; ?></td>

                                                <td class="small">
                                                    <div><strong><?php echo h($row['branch_name'] ?: '-'); ?></strong></div>
                                                    <div class="text-muted">Code: <?php echo h($row['branch_code'] ?: '-'); ?></div>
                                                    <div class="text-muted">
                                                        <?php echo ((int)($row['is_head_office'] ?? 0) === 1) ? 'Head Office' : 'Branch Office'; ?>
                                                    </div>
                                                </td>

                                                <td class="small">
                                                    <div><strong>Person:</strong> <?php echo h($row['contact_person'] ?: '-'); ?></div>
                                                    <div><strong>Email:</strong> <?php echo h($row['email'] ?: '-'); ?></div>
                                                    <div><strong>Mobile:</strong> <?php echo h($row['mobile'] ?: '-'); ?></div>
                                                    <div><strong>Alt:</strong> <?php echo h($row['alternate_mobile'] ?: '-'); ?></div>
                                                    <div><strong>GSTIN:</strong> <?php echo h($row['gstin'] ?: '-'); ?></div>
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

                                                <td class="small">
                                                    <div><strong>Expenses:</strong> <span class="text-danger"><?php echo money($row['expense_total'] ?? 0); ?></span></div>
                                                    <div><strong>Collections:</strong> <span class="text-success"><?php echo money($row['collection_total'] ?? 0); ?></span></div>
                                                    <div><strong>Payment Count:</strong> <?php echo number_format((int)($row['payment_count'] ?? 0)); ?></div>
                                                    <div>
                                                        <strong>Net Collection:</strong>
                                                        <?php if ($branchNet >= 0): ?>
                                                            <span class="text-success"><?php echo money($branchNet); ?></span>
                                                        <?php else: ?>
                                                            <span class="text-danger"><?php echo money($branchNet); ?></span>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>

                                                <td class="small">
                                                    <div><strong>Product Lines:</strong> <?php echo number_format((int)($row['product_stock_lines'] ?? 0)); ?></div>
                                                    <div><strong>Product Qty:</strong> <?php echo qtyf($row['product_qty_total'] ?? 0); ?></div>
                                                    <div><strong>Vehicles:</strong> <?php echo number_format((int)($row['vehicle_stock_count'] ?? 0)); ?></div>
                                                    <div><strong>In Stock:</strong> <?php echo number_format((int)($row['vehicle_instock_count'] ?? 0)); ?></div>
                                                </td>

                                                <td>
                                                    <?php if (($row['status'] ?? 'active') === 'active'): ?>
                                                        <span class="badge bg-success">Active</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-danger">Inactive</span>
                                                    <?php endif; ?>

                                                    <?php if ((int)($row['is_head_office'] ?? 0) === 1): ?>
                                                        <div class="mt-1">
                                                            <span class="badge bg-primary">Head Office</span>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>

                                                <td>
                                                    <div class="d-flex flex-wrap gap-2">
                                                        <a href="branch-view.php?id=<?php echo (int)$row['id']; ?>" class="btn btn-sm btn-info">View</a>
                                                        <a href="branch-edit.php?id=<?php echo (int)$row['id']; ?>" class="btn btn-sm btn-primary">Edit</a>
                                                        <a href="stock-report.php?branch_id=<?php echo (int)$row['id']; ?>" class="btn btn-sm btn-success">Stock</a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="10" class="text-center text-muted py-4">No branch records found.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="mt-3 text-muted small">
                            Showing latest 500 branch records.
                        </div>
                    </div>
                </div>

                <div class="row report-last-row">
                    <div class="col-xl-6">
                        <div class="card h-100">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Branch Summary</h4>
                                <table class="table table-bordered table-striped mb-0">
                                    <tbody>
                                        <tr><th style="width:50%;">Total Branches</th><td><?php echo number_format($totalBranches); ?></td></tr>
                                        <tr><th>Active Branches</th><td><?php echo number_format($activeBranches); ?></td></tr>
                                        <tr><th>Inactive Branches</th><td><?php echo number_format($inactiveBranches); ?></td></tr>
                                        <tr><th>Head Offices</th><td><?php echo number_format($headOfficeCount); ?></td></tr>
                                        <tr><th>Total Product Quantity</th><td><?php echo qtyf($totalProductQty); ?></td></tr>
                                        <tr><th>Total Vehicles</th><td><?php echo number_format($totalVehicles); ?></td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-6">
                        <div class="card h-100">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Financial Summary</h4>
                                <table class="table table-bordered table-striped mb-0">
                                    <tbody>
                                        <tr><th style="width:50%;">Sales Total</th><td><?php echo money($totalSales); ?></td></tr>
                                        <tr><th>Sales Paid</th><td><?php echo money($totalSalesPaid); ?></td></tr>
                                        <tr><th>Sales Balance</th><td><?php echo money($totalSalesBalance); ?></td></tr>
                                        <tr><th>Service Total</th><td><?php echo money($totalService); ?></td></tr>
                                        <tr><th>Service Paid</th><td><?php echo money($totalServicePaid); ?></td></tr>
                                        <tr><th>Service Balance</th><td><?php echo money($totalServiceBalance); ?></td></tr>
                                        <tr><th>Expense Total</th><td><?php echo money($totalExpenses); ?></td></tr>
                                        <tr><th>Collections Total</th><td><?php echo money($totalCollections); ?></td></tr>
                                        <tr>
                                            <th>Collection Net</th>
                                            <td>
                                                <?php
                                                $overallNet = $totalCollections - $totalExpenses;
                                                echo $overallNet >= 0
                                                    ? '<strong class="text-success">' . money($overallNet) . '</strong>'
                                                    : '<strong class="text-danger">' . money($overallNet) . '</strong>';
                                                ?>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
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