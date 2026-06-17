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
   INPUT
------------------------------------------------------- */
$branchId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($branchId <= 0) {
    die('Invalid branch id.');
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
    $stmt = $conn->prepare("
        SELECT 1
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = ?
        LIMIT 1
    ");
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
    $stmt = $conn->prepare("
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = ?
        AND COLUMN_NAME = ?
        LIMIT 1
    ");
    if (!$stmt) return false;

    $stmt->bind_param('ss', $table, $column);
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
    $sql = "SELECT COALESCE(SUM({$field}),0) AS total FROM {$table} WHERE {$where}";
    $res = $conn->query($sql);
    if (!$res) return 0;

    $row = $res->fetch_assoc();
    return (float)($row['total'] ?? 0);
}

function getSingleFloat(mysqli $conn, string $sql): float
{
    $res = $conn->query($sql);
    if (!$res) return 0.00;

    $row = $res->fetch_assoc();
    return (float)($row['total'] ?? 0);
}

function qtyf($qty): string
{
    $qty = (float)$qty;
    return ((int)$qty == $qty) ? number_format($qty, 0) : number_format($qty, 2);
}

function paymentStatusFromAmounts($grandTotal, $paidAmount, $balanceAmount, $dbStatus = ''): array
{
    $grandTotal = round((float)$grandTotal, 2);
    $paidAmount = round((float)$paidAmount, 2);
    $balanceAmount = round((float)$balanceAmount, 2);
    $dbStatus = strtolower(trim((string)$dbStatus));

    if ($grandTotal <= 0) {
        return ['Unpaid', 'danger', 0.00];
    }

    if ($balanceAmount <= 0 && $paidAmount > 0) {
        $balanceAmount = max($grandTotal - $paidAmount, 0);
    }

    if ($paidAmount >= $grandTotal || $balanceAmount <= 0 || $dbStatus === 'paid') {
        return ['Paid', 'success', 0.00];
    }

    if ($paidAmount > 0 && $paidAmount < $grandTotal) {
        return ['Partial', 'warning', max($grandTotal - $paidAmount, 0)];
    }

    if ($dbStatus === 'partial') {
        return ['Partial', 'warning', max($grandTotal - $paidAmount, 0)];
    }

    return ['Unpaid', 'danger', max($grandTotal - $paidAmount, 0)];
}

/* -------------------------------------------------------
   VALIDATE LOGIN USER
------------------------------------------------------- */
$loggedUser = null;
if (tableExists($conn, 'business_users') && tableExists($conn, 'businesses')) {
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
   TABLE / COLUMN CHECKS
------------------------------------------------------- */
$hasBranches = tableExists($conn, 'branches');
$hasUsers = tableExists($conn, 'business_users');
$hasSalesInvoices = tableExists($conn, 'sales_invoices');
$hasServiceInvoices = tableExists($conn, 'service_invoices');
$hasPayments = tableExists($conn, 'payments');
$hasExpenses = tableExists($conn, 'expenses');
$hasProductStock = tableExists($conn, 'product_stock');
$hasVehicleStock = tableExists($conn, 'vehicle_stock');

if (!$hasBranches) {
    die('branches table not found.');
}

$salesHasPaid = $hasSalesInvoices && columnExists($conn, 'sales_invoices', 'paid_amount');
$salesHasBalance = $hasSalesInvoices && columnExists($conn, 'sales_invoices', 'balance_amount');
$salesHasPaymentStatus = $hasSalesInvoices && columnExists($conn, 'sales_invoices', 'payment_status');
$salesHasSaleStatus = $hasSalesInvoices && columnExists($conn, 'sales_invoices', 'sale_status');

$serviceHasPaid = $hasServiceInvoices && columnExists($conn, 'service_invoices', 'paid_amount');
$serviceHasBalance = $hasServiceInvoices && columnExists($conn, 'service_invoices', 'balance_amount');

$productStockHasQty = $hasProductStock && columnExists($conn, 'product_stock', 'qty_available');
$vehicleHasStatus = $hasVehicleStock && columnExists($conn, 'vehicle_stock', 'stock_status');

/* -------------------------------------------------------
   FETCH BRANCH
------------------------------------------------------- */
$branch = null;
$stmt = $conn->prepare("
    SELECT *
    FROM branches
    WHERE id = ?
    AND business_id = ?
    LIMIT 1
");
if ($stmt) {
    $stmt->bind_param('ii', $branchId, $businessId);
    $stmt->execute();
    $branch = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if (!$branch) {
    die('Branch not found.');
}

/* -------------------------------------------------------
   SUMMARY CALCULATIONS
------------------------------------------------------- */
$totalUsers = $hasUsers
    ? getCount($conn, 'business_users', "business_id = {$businessId} AND branch_id = {$branchId}")
    : 0;

$activeUsers = $hasUsers
    ? getCount($conn, 'business_users', "business_id = {$businessId} AND branch_id = {$branchId} AND status = 1")
    : 0;

$totalSalesInvoices = $hasSalesInvoices
    ? getCount($conn, 'sales_invoices', "business_id = {$businessId} AND branch_id = {$branchId}")
    : 0;

$totalServiceInvoices = $hasServiceInvoices
    ? getCount($conn, 'service_invoices', "business_id = {$businessId} AND branch_id = {$branchId}")
    : 0;

$salesPaidExpr = $salesHasPaid ? "COALESCE(paid_amount,0)" : "0";
$salesBalanceExpr = $salesHasBalance
    ? "GREATEST(COALESCE(balance_amount,0),0)"
    : "GREATEST(COALESCE(grand_total,0) - {$salesPaidExpr},0)";

$totalSalesAmount = $hasSalesInvoices
    ? getSum($conn, 'sales_invoices', 'grand_total', "business_id = {$businessId} AND branch_id = {$branchId}")
    : 0.00;

$totalSalesPaid = $hasSalesInvoices
    ? getSingleFloat($conn, "
        SELECT COALESCE(SUM({$salesPaidExpr}),0) AS total
        FROM sales_invoices
        WHERE business_id = {$businessId}
        AND branch_id = {$branchId}
    ")
    : 0.00;

$totalSalesBalance = $hasSalesInvoices
    ? getSingleFloat($conn, "
        SELECT COALESCE(SUM({$salesBalanceExpr}),0) AS total
        FROM sales_invoices
        WHERE business_id = {$businessId}
        AND branch_id = {$branchId}
    ")
    : 0.00;

$servicePaidExpr = $serviceHasPaid ? "COALESCE(paid_amount,0)" : "0";
$serviceBalanceExpr = $serviceHasBalance
    ? "GREATEST(COALESCE(balance_amount,0),0)"
    : "GREATEST(COALESCE(grand_total,0) - {$servicePaidExpr},0)";

$totalServiceAmount = $hasServiceInvoices
    ? getSum($conn, 'service_invoices', 'grand_total', "business_id = {$businessId} AND branch_id = {$branchId}")
    : 0.00;

$totalServicePaid = $hasServiceInvoices
    ? getSingleFloat($conn, "
        SELECT COALESCE(SUM({$servicePaidExpr}),0) AS total
        FROM service_invoices
        WHERE business_id = {$businessId}
        AND branch_id = {$branchId}
    ")
    : 0.00;

$totalServiceBalance = $hasServiceInvoices
    ? getSingleFloat($conn, "
        SELECT COALESCE(SUM({$serviceBalanceExpr}),0) AS total
        FROM service_invoices
        WHERE business_id = {$businessId}
        AND branch_id = {$branchId}
    ")
    : 0.00;

$totalPayments = $hasPayments
    ? getSum($conn, 'payments', 'amount', "business_id = {$businessId} AND branch_id = {$branchId}")
    : 0.00;

$totalExpenses = $hasExpenses
    ? getSum($conn, 'expenses', 'amount', "business_id = {$businessId} AND branch_id = {$branchId}")
    : 0.00;

$productQty = ($hasProductStock && $productStockHasQty)
    ? getSum($conn, 'product_stock', 'qty_available', "business_id = {$businessId} AND branch_id = {$branchId}")
    : 0.00;

$vehicleCount = $hasVehicleStock
    ? getCount($conn, 'vehicle_stock', "business_id = {$businessId} AND branch_id = {$branchId}")
    : 0;

$vehicleInStock = ($hasVehicleStock && $vehicleHasStatus)
    ? getCount($conn, 'vehicle_stock', "business_id = {$businessId} AND branch_id = {$branchId} AND stock_status = 'in_stock'")
    : 0;

$netCollection = $totalPayments - $totalExpenses;

/* -------------------------------------------------------
   RECENT BRANCH USERS
------------------------------------------------------- */
$branchUsers = [];
if ($hasUsers) {
    $sql = "
        SELECT id, full_name, username, email, mobile, role, status, created_at
        FROM business_users
        WHERE business_id = {$businessId}
        AND branch_id = {$branchId}
        ORDER BY id DESC
        LIMIT 8
    ";
    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $branchUsers[] = $row;
        }
        $res->free();
    }
}

/* -------------------------------------------------------
   RECENT SALES INVOICES
------------------------------------------------------- */
$recentSales = [];
if ($hasSalesInvoices) {
    $selectPaymentStatus = $salesHasPaymentStatus ? "payment_status" : "'' AS payment_status";
    $selectSaleStatus = $salesHasSaleStatus ? "sale_status" : "'' AS sale_status";
    $selectPaid = $salesHasPaid ? "paid_amount" : "0 AS paid_amount";
    $selectBalance = $salesHasBalance ? "balance_amount" : "0 AS balance_amount";

    $sql = "
        SELECT 
            id,
            invoice_no,
            invoice_date,
            grand_total,
            {$selectPaid},
            {$selectBalance},
            {$selectPaymentStatus},
            {$selectSaleStatus}
        FROM sales_invoices
        WHERE business_id = {$businessId}
        AND branch_id = {$branchId}
        ORDER BY id DESC
        LIMIT 8
    ";
    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $recentSales[] = $row;
        }
        $res->free();
    }
}

/* -------------------------------------------------------
   RECENT EXPENSES
------------------------------------------------------- */
$recentExpenses = [];
if ($hasExpenses) {
    $sql = "
        SELECT expense_date, expense_type, description, amount, paid_to
        FROM expenses
        WHERE business_id = {$businessId}
        AND branch_id = {$branchId}
        ORDER BY id DESC
        LIMIT 8
    ";
    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $recentExpenses[] = $row;
        }
        $res->free();
    }
}

$pageTitle = 'Branch View';
$currentPage = 'branches';
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
                    <div class="col-md-6">
                        <h4 class="mb-1">Branch Details</h4>
                        <p class="text-muted mb-0">View complete branch information</p>
                    </div>
                    <div class="col-md-6 text-md-end mt-3 mt-md-0">
                        <a href="branches.php" class="btn btn-secondary">Back</a>
                        <a href="branch-edit.php?id=<?php echo (int)$branchId; ?>" class="btn btn-primary">Edit Branch</a>
                    </div>
                </div>

                <!-- STATS ROW 1 -->
                <div class="row">
                    <div class="col-md-6 col-xl-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Branch Users</p>
                                <h3 class="mb-0"><?php echo number_format($totalUsers); ?></h3>
                                <small class="text-muted">Active: <?php echo number_format($activeUsers); ?></small>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Sales Invoices</p>
                                <h3 class="mb-0 text-success"><?php echo number_format($totalSalesInvoices); ?></h3>
                                <small class="text-muted"><?php echo money($totalSalesAmount); ?></small>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Service Invoices</p>
                                <h3 class="mb-0 text-info"><?php echo number_format($totalServiceInvoices); ?></h3>
                                <small class="text-muted"><?php echo money($totalServiceAmount); ?></small>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Product Stock Qty</p>
                                <h3 class="mb-0 text-primary"><?php echo qtyf($productQty); ?></h3>
                                <small class="text-muted">Vehicles: <?php echo number_format($vehicleInStock); ?> / <?php echo number_format($vehicleCount); ?></small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- STATS ROW 2 -->
                <div class="row">
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Sales Paid</p>
                                <h4 class="mb-0 text-success"><?php echo money($totalSalesPaid); ?></h4>
                                <small class="text-danger">Balance: <?php echo money($totalSalesBalance); ?></small>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Service Paid</p>
                                <h4 class="mb-0 text-success"><?php echo money($totalServicePaid); ?></h4>
                                <small class="text-danger">Balance: <?php echo money($totalServiceBalance); ?></small>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Collections</p>
                                <h4 class="mb-0 text-info"><?php echo money($totalPayments); ?></h4>
                                <small class="text-muted">Payments received</small>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Expenses</p>
                                <h4 class="mb-0 text-danger"><?php echo money($totalExpenses); ?></h4>
                                <small class="<?php echo $netCollection >= 0 ? 'text-success' : 'text-danger'; ?>">
                                    Net: <?php echo money($netCollection); ?>
                                </small>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <!-- LEFT -->
                    <div class="col-xl-4">
                        <div class="card">
                            <div class="card-body text-center">
                                <h4 class="mb-1"><?php echo h($branch['branch_name'] ?? '-'); ?></h4>
                                <p class="text-muted mb-2"><?php echo h($branch['branch_code'] ?? '-'); ?></p>

                                <?php
                                $status = strtolower((string)($branch['status'] ?? 'inactive'));
                                $badge = 'secondary';
                                if ($status === 'active') $badge = 'success';
                                elseif ($status === 'inactive') $badge = 'warning';
                                ?>
                                <span class="badge bg-<?php echo $badge; ?>">
                                    <?php echo h(ucfirst($status)); ?>
                                </span>

                                <?php if ((int)($branch['is_head_office'] ?? 0) === 1): ?>
                                    <span class="badge bg-info ms-1">Head Office</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-3">Branch Summary</h4>
                                <table class="table table-borderless mb-0">
                                    <tr>
                                        <th>Sales Total</th>
                                        <td><?php echo money($totalSalesAmount); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Sales Paid</th>
                                        <td><?php echo money($totalSalesPaid); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Sales Balance</th>
                                        <td class="text-danger"><?php echo money($totalSalesBalance); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Service Total</th>
                                        <td><?php echo money($totalServiceAmount); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Total Collections</th>
                                        <td class="text-success"><?php echo money($totalPayments); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Total Expenses</th>
                                        <td class="text-danger"><?php echo money($totalExpenses); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Net Collection</th>
                                        <td class="<?php echo $netCollection >= 0 ? 'text-success' : 'text-danger'; ?>">
                                            <?php echo money($netCollection); ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>Created At</th>
                                        <td><?php echo !empty($branch['created_at']) ? h(date('d M Y', strtotime($branch['created_at']))) : '-'; ?></td>
                                    </tr>
                                    <tr>
                                        <th>Updated At</th>
                                        <td><?php echo !empty($branch['updated_at']) ? h(date('d M Y', strtotime($branch['updated_at']))) : '-'; ?></td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- RIGHT -->
                    <div class="col-xl-8">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Branch Information</h4>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="text-muted d-block mb-1">Branch Name</label>
                                        <div class="fw-bold"><?php echo h($branch['branch_name'] ?? '-'); ?></div>
                                    </div>

                                    <div class="col-md-6 mb-3">
                                        <label class="text-muted d-block mb-1">Branch Code</label>
                                        <div class="fw-bold"><?php echo h($branch['branch_code'] ?? '-'); ?></div>
                                    </div>

                                    <div class="col-md-6 mb-3">
                                        <label class="text-muted d-block mb-1">Contact Person</label>
                                        <div class="fw-bold"><?php echo h($branch['contact_person'] ?? '-'); ?></div>
                                    </div>

                                    <div class="col-md-6 mb-3">
                                        <label class="text-muted d-block mb-1">Email</label>
                                        <div class="fw-bold"><?php echo h($branch['email'] ?? '-'); ?></div>
                                    </div>

                                    <div class="col-md-6 mb-3">
                                        <label class="text-muted d-block mb-1">Mobile</label>
                                        <div class="fw-bold"><?php echo h($branch['mobile'] ?? '-'); ?></div>
                                    </div>

                                    <div class="col-md-6 mb-3">
                                        <label class="text-muted d-block mb-1">Alternate Mobile</label>
                                        <div class="fw-bold"><?php echo h($branch['alternate_mobile'] ?? '-'); ?></div>
                                    </div>

                                    <div class="col-md-6 mb-3">
                                        <label class="text-muted d-block mb-1">GSTIN</label>
                                        <div class="fw-bold"><?php echo h($branch['gstin'] ?? '-'); ?></div>
                                    </div>

                                    <div class="col-md-6 mb-3">
                                        <label class="text-muted d-block mb-1">Head Office</label>
                                        <div class="fw-bold"><?php echo ((int)($branch['is_head_office'] ?? 0) === 1) ? 'Yes' : 'No'; ?></div>
                                    </div>

                                    <div class="col-md-12 mb-3">
                                        <label class="text-muted d-block mb-1">Address Line 1</label>
                                        <div class="fw-bold"><?php echo h($branch['address_line1'] ?? '-'); ?></div>
                                    </div>

                                    <div class="col-md-12 mb-3">
                                        <label class="text-muted d-block mb-1">Address Line 2</label>
                                        <div class="fw-bold"><?php echo h($branch['address_line2'] ?? '-'); ?></div>
                                    </div>

                                    <div class="col-md-3 mb-3">
                                        <label class="text-muted d-block mb-1">City</label>
                                        <div class="fw-bold"><?php echo h($branch['city'] ?? '-'); ?></div>
                                    </div>

                                    <div class="col-md-3 mb-3">
                                        <label class="text-muted d-block mb-1">District</label>
                                        <div class="fw-bold"><?php echo h($branch['district'] ?? '-'); ?></div>
                                    </div>

                                    <div class="col-md-3 mb-3">
                                        <label class="text-muted d-block mb-1">State</label>
                                        <div class="fw-bold"><?php echo h($branch['state'] ?? '-'); ?></div>
                                    </div>

                                    <div class="col-md-3 mb-3">
                                        <label class="text-muted d-block mb-1">Pincode</label>
                                        <div class="fw-bold"><?php echo h($branch['pincode'] ?? '-'); ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Recent Branch Users</h4>
                                <div class="table-responsive">
                                    <table class="table table-bordered align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th>#</th>
                                                <th>Name</th>
                                                <th>Username</th>
                                                <th>Role</th>
                                                <th>Mobile</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($branchUsers)): ?>
                                                <?php $i = 1; foreach ($branchUsers as $u): ?>
                                                    <tr>
                                                        <td><?php echo $i++; ?></td>
                                                        <td><?php echo h($u['full_name']); ?></td>
                                                        <td><?php echo h($u['username']); ?></td>
                                                        <td><?php echo h(ucwords(str_replace('_', ' ', $u['role'] ?? '-'))); ?></td>
                                                        <td><?php echo h($u['mobile'] ?: '-'); ?></td>
                                                        <td>
                                                            <span class="badge bg-<?php echo ((int)$u['status'] === 1) ? 'success' : 'warning'; ?>">
                                                                <?php echo ((int)$u['status'] === 1) ? 'Active' : 'Inactive'; ?>
                                                            </span>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="6" class="text-center text-muted">No users found for this branch.</td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>

                <div class="row">
                    <div class="col-xl-7">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Recent Sales Invoices</h4>
                                <div class="table-responsive">
                                    <table class="table table-bordered align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th>#</th>
                                                <th>Invoice No</th>
                                                <th>Date</th>
                                                <th>Total</th>
                                                <th>Paid</th>
                                                <th>Balance</th>
                                                <th>Payment Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($recentSales)): ?>
                                                <?php $i = 1; foreach ($recentSales as $sale): ?>
                                                    <?php
                                                    [$statusText, $pbadge, $calcBalance] = paymentStatusFromAmounts(
                                                        $sale['grand_total'] ?? 0,
                                                        $sale['paid_amount'] ?? 0,
                                                        $sale['balance_amount'] ?? 0,
                                                        $sale['payment_status'] ?? ''
                                                    );
                                                    ?>
                                                    <tr>
                                                        <td><?php echo $i++; ?></td>
                                                        <td>
                                                            <a href="sales-invoice-view.php?id=<?php echo (int)$sale['id']; ?>" class="fw-bold text-primary">
                                                                <?php echo h($sale['invoice_no']); ?>
                                                            </a>
                                                        </td>
                                                        <td><?php echo !empty($sale['invoice_date']) ? h(date('d M Y', strtotime($sale['invoice_date']))) : '-'; ?></td>
                                                        <td><?php echo money($sale['grand_total'] ?? 0); ?></td>
                                                        <td class="text-success"><?php echo money($sale['paid_amount'] ?? 0); ?></td>
                                                        <td class="<?php echo $calcBalance > 0 ? 'text-danger' : 'text-success'; ?>">
                                                            <?php echo money($calcBalance); ?>
                                                        </td>
                                                        <td>
                                                            <span class="badge bg-<?php echo $pbadge; ?>">
                                                                <?php echo h($statusText); ?>
                                                            </span>
                                                            <?php if (!empty($sale['sale_status'])): ?>
                                                                <div class="small text-muted mt-1">
                                                                    Sale: <?php echo h(ucwords(str_replace('_', ' ', $sale['sale_status']))); ?>
                                                                </div>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="7" class="text-center text-muted">No sales invoices found.</td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-5">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Recent Expenses</h4>
                                <div class="table-responsive">
                                    <table class="table table-bordered align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th>#</th>
                                                <th>Date</th>
                                                <th>Type</th>
                                                <th>Paid To</th>
                                                <th>Amount</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($recentExpenses)): ?>
                                                <?php $i = 1; foreach ($recentExpenses as $exp): ?>
                                                    <tr>
                                                        <td><?php echo $i++; ?></td>
                                                        <td><?php echo !empty($exp['expense_date']) ? h(date('d M Y', strtotime($exp['expense_date']))) : '-'; ?></td>
                                                        <td><?php echo h($exp['expense_type'] ?: '-'); ?></td>
                                                        <td><?php echo h($exp['paid_to'] ?: '-'); ?></td>
                                                        <td class="text-danger"><?php echo money($exp['amount']); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="5" class="text-center text-muted">No expenses found.</td>
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