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

function formatDisplayDate($dateValue): string
{
    if (empty($dateValue) || $dateValue == '0000-00-00' || $dateValue == '0000-00-00 00:00:00') {
        return '-';
    }

    $timestamp = strtotime($dateValue);
    if (!$timestamp || $timestamp <= 0) {
        return '-';
    }

    return date('d M Y h:i A', $timestamp);
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

/* -------------------------------------------------------
   VALIDATE USER
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
$requiredTables = ['sales_invoices', 'customers', 'branches'];

foreach ($requiredTables as $tbl) {
    if (!tableExists($conn, $tbl)) {
        die($tbl . ' table not found.');
    }
}

/* -------------------------------------------------------
   DELETE
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
            if (tableExists($conn, 'sales_invoice_items') && tableExists($conn, 'vehicle_stock')) {
                $stmt = $conn->prepare("
                    SELECT vehicle_stock_id 
                    FROM sales_invoice_items 
                    WHERE invoice_id = ? AND item_type = 'vehicle'
                ");

                if ($stmt) {
                    $stmt->bind_param('i', $invoiceId);
                    $stmt->execute();
                    $result = $stmt->get_result();

                    while ($row = $result->fetch_assoc()) {
                        $vehicleStockId = (int)($row['vehicle_stock_id'] ?? 0);

                        if ($vehicleStockId > 0) {
                            $updateStock = $conn->prepare("
                                UPDATE vehicle_stock 
                                SET stock_status = 'in_stock' 
                                WHERE id = ? AND business_id = ?
                            ");

                            if ($updateStock) {
                                $updateStock->bind_param('ii', $vehicleStockId, $businessId);
                                $updateStock->execute();
                                $updateStock->close();
                            }
                        }
                    }

                    $stmt->close();
                }
            }

            if (tableExists($conn, 'vehicle_sales')) {
                $stmt = $conn->prepare("DELETE FROM vehicle_sales WHERE invoice_id = ? AND business_id = ?");
                if ($stmt) {
                    $stmt->bind_param('ii', $invoiceId, $businessId);
                    $stmt->execute();
                    $stmt->close();
                }
            }

            if (tableExists($conn, 'sales_invoice_items')) {
                $stmt = $conn->prepare("DELETE FROM sales_invoice_items WHERE invoice_id = ?");
                if ($stmt) {
                    $stmt->bind_param('i', $invoiceId);
                    $stmt->execute();
                    $stmt->close();
                }
            }

            $stmt = $conn->prepare("DELETE FROM sales_invoices WHERE id = ? AND business_id = ? LIMIT 1");
            if (!$stmt) {
                throw new Exception('Delete query failed.');
            }

            $stmt->bind_param('ii', $invoiceId, $businessId);

            if (!$stmt->execute()) {
                throw new Exception('Failed to delete invoice.');
            }

            if ($stmt->affected_rows <= 0) {
                throw new Exception('Invoice not found.');
            }

            $stmt->close();

            $conn->commit();
            $success = 'Sales invoice deleted successfully.';
        } catch (Exception $e) {
            $conn->rollback();
            $error = $e->getMessage();
        }
    }
}

if (isset($_GET['success']) && trim($_GET['success']) !== '') {
    $success = trim($_GET['success']);
}

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
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');

$allowedInvoiceTypes = ['vehicle_sale', 'product_sale', 'mixed_sale'];
$allowedPaymentStatuses = ['unpaid', 'partial', 'paid'];

$where = ["si.business_id = {$businessId}"];

if ($search !== '') {
    $safe = $conn->real_escape_string($search);
    $where[] = "(
        si.invoice_no LIKE '%{$safe}%' 
        OR c.full_name LIKE '%{$safe}%' 
        OR c.mobile LIKE '%{$safe}%' 
        OR br.branch_name LIKE '%{$safe}%'
    )";
}

if ($branchFilter > 0) {
    $where[] = "si.branch_id = {$branchFilter}";
}

if ($customerFilter > 0) {
    $where[] = "si.customer_id = {$customerFilter}";
}

if ($invoiceTypeFilter !== '' && in_array($invoiceTypeFilter, $allowedInvoiceTypes, true)) {
    $invoiceTypeSafe = $conn->real_escape_string($invoiceTypeFilter);
    $where[] = "si.invoice_type = '{$invoiceTypeSafe}'";
}

/*
   IMPORTANT:
   Payment status is calculated from amount fields because old DB rows have empty payment_status.
*/
if ($paymentStatusFilter !== '' && in_array($paymentStatusFilter, $allowedPaymentStatuses, true)) {
    if ($paymentStatusFilter === 'paid') {
        $where[] = "si.grand_total > 0 AND si.paid_amount >= si.grand_total";
    } elseif ($paymentStatusFilter === 'partial') {
        $where[] = "si.paid_amount > 0 AND si.paid_amount < si.grand_total";
    } elseif ($paymentStatusFilter === 'unpaid') {
        $where[] = "(si.paid_amount <= 0 OR si.paid_amount IS NULL)";
    }
}

if ($dateFrom !== '') {
    $dateFromSafe = $conn->real_escape_string($dateFrom);
    $where[] = "DATE(si.invoice_date) >= '{$dateFromSafe}'";
}

if ($dateTo !== '') {
    $dateToSafe = $conn->real_escape_string($dateTo);
    $where[] = "DATE(si.invoice_date) <= '{$dateToSafe}'";
}

$whereSql = implode(' AND ', $where);

/* -------------------------------------------------------
   SUMMARY - CORRECT BASED ON DB AMOUNTS
------------------------------------------------------- */
$totalInvoices = getCount(
    $conn,
    'sales_invoices',
    "business_id = {$businessId}"
);

$totalAmount = getSum(
    $conn,
    'sales_invoices',
    'grand_total',
    "business_id = {$businessId}"
);

$totalPaid = getSum(
    $conn,
    'sales_invoices',
    'paid_amount',
    "business_id = {$businessId}"
);

$totalBalance = getSum(
    $conn,
    'sales_invoices',
    "GREATEST(grand_total - paid_amount, 0)",
    "business_id = {$businessId}"
);

$paidInvoices = getCount(
    $conn,
    'sales_invoices',
    "business_id = {$businessId} AND grand_total > 0 AND paid_amount >= grand_total"
);

$partialInvoices = getCount(
    $conn,
    'sales_invoices',
    "business_id = {$businessId} AND paid_amount > 0 AND paid_amount < grand_total"
);

$unpaidInvoices = getCount(
    $conn,
    'sales_invoices',
    "business_id = {$businessId} AND (paid_amount <= 0 OR paid_amount IS NULL)"
);

$today = date('Y-m-d');

$todayInvoices = getCount(
    $conn,
    'sales_invoices',
    "business_id = {$businessId} AND DATE(invoice_date) = '{$today}'"
);

$todayAmount = getSum(
    $conn,
    'sales_invoices',
    'grand_total',
    "business_id = {$businessId} AND DATE(invoice_date) = '{$today}'"
);

/* -------------------------------------------------------
   FETCH INVOICES
------------------------------------------------------- */
$invoiceRows = [];

$sql = "
    SELECT 
        si.*,

        CASE
            WHEN si.grand_total > 0 AND si.paid_amount >= si.grand_total THEN 'paid'
            WHEN si.paid_amount > 0 AND si.paid_amount < si.grand_total THEN 'partial'
            ELSE 'unpaid'
        END AS real_payment_status,

        GREATEST(si.grand_total - si.paid_amount, 0) AS real_balance_amount,

        br.branch_name,
        br.branch_code,
        c.full_name AS customer_name,
        c.mobile AS customer_mobile,
        bu.full_name AS created_by_name,

        (
            SELECT COUNT(*) 
            FROM sales_invoice_items sii 
            WHERE sii.invoice_id = si.id
        ) AS item_count,

        (
            SELECT GROUP_CONCAT(
                CONCAT(
                    COALESCE(vb.brand_name, ''),
                    CASE 
                        WHEN vm.model_name IS NOT NULL AND vm.model_name != '' 
                        THEN CONCAT(' ', vm.model_name) 
                        ELSE '' 
                    END,
                    CASE 
                        WHEN vm.variant_name IS NOT NULL AND vm.variant_name != '' 
                        THEN CONCAT(' ', vm.variant_name) 
                        ELSE '' 
                    END,
                    CASE 
                        WHEN vs.chassis_no IS NOT NULL AND vs.chassis_no != '' AND vs.chassis_no != '0'
                        THEN CONCAT(' | Ch: ', vs.chassis_no) 
                        ELSE '' 
                    END,
                    CASE 
                        WHEN vs.color IS NOT NULL AND vs.color != '' 
                        THEN CONCAT(' | ', vs.color) 
                        ELSE '' 
                    END
                )
                SEPARATOR ' | '
            )
            FROM sales_invoice_items sii
            LEFT JOIN vehicle_stock vs ON vs.id = sii.vehicle_stock_id
            LEFT JOIN vehicle_models vm ON vm.id = vs.model_id
            LEFT JOIN vehicle_brands vb ON vb.id = vm.brand_id
            WHERE sii.invoice_id = si.id 
            AND sii.item_type = 'vehicle'
        ) AS vehicle_details

    FROM sales_invoices si
    LEFT JOIN branches br ON br.id = si.branch_id
    LEFT JOIN customers c ON c.id = si.customer_id
    LEFT JOIN business_users bu ON bu.id = si.created_by
    WHERE {$whereSql}
    ORDER BY si.id DESC
";

$res = $conn->query($sql);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $invoiceRows[] = $row;
    }
    $res->free();
}

$pageTitle = 'Sales Invoices';
$currentPage = 'sales-invoices';
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
                        <h4 class="mb-1">Sales Invoices</h4>
                        <p class="text-muted mb-0">Manage and view sales invoices</p>
                    </div>

                    <div class="col-md-6 text-md-end mt-3 mt-md-0">
                        <div class="btn-group">
                            <a href="sales-invoice-add.php" class="btn btn-primary">
                                <i class="mdi mdi-plus-circle"></i> Add Sales Invoice
                            </a>
                            <a href="index.php" class="btn btn-info">
                                <i class="mdi mdi-view-dashboard"></i> Dashboard
                            </a>
                        </div>
                    </div>
                </div>

                <?php if ($success !== ''): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="mdi mdi-check-circle me-2"></i> <?php echo h($success); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="mdi mdi-alert-circle me-2"></i> <?php echo h($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <!-- MAIN STATS -->
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
                                <p class="text-muted mb-1">Total Amount</p>
                                <h3 class="mb-0 text-primary"><?php echo money($totalAmount); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Paid Amount</p>
                                <h3 class="mb-0 text-success"><?php echo money($totalPaid); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Balance Amount</p>
                                <h3 class="mb-0 text-danger"><?php echo money($totalBalance); ?></h3>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- PAYMENT STATS -->
                <div class="row">
                    <div class="col-md-2">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Paid</p>
                                <h4 class="mb-0 text-success"><?php echo number_format($paidInvoices); ?></h4>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-2">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Partial</p>
                                <h4 class="mb-0 text-warning"><?php echo number_format($partialInvoices); ?></h4>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-2">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Unpaid</p>
                                <h4 class="mb-0 text-danger"><?php echo number_format($unpaidInvoices); ?></h4>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Today Invoices</p>
                                <h4 class="mb-0 text-info"><?php echo number_format($todayInvoices); ?></h4>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Today Amount</p>
                                <h4 class="mb-0 text-primary"><?php echo money($todayAmount); ?></h4>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- FILTERS -->
                <div class="card">
                    <div class="card-body">
                        <form method="get" class="row g-3">
                            <div class="col-md-3">
                                <input 
                                    type="text" 
                                    name="search" 
                                    class="form-control" 
                                    placeholder="Search invoice no, customer, mobile..." 
                                    value="<?php echo h($search); ?>"
                                >
                            </div>

                            <div class="col-md-2">
                                <select name="branch_id" class="form-select">
                                    <option value="0">All Branches</option>
                                    <?php foreach ($branches as $b): ?>
                                        <option 
                                            value="<?php echo (int)$b['id']; ?>" 
                                            <?php echo ($branchFilter === (int)$b['id']) ? 'selected' : ''; ?>
                                        >
                                            <?php echo h($b['branch_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-2">
                                <select name="customer_id" class="form-select">
                                    <option value="0">All Customers</option>
                                    <?php foreach ($customers as $c): ?>
                                        <option 
                                            value="<?php echo (int)$c['id']; ?>" 
                                            <?php echo ($customerFilter === (int)$c['id']) ? 'selected' : ''; ?>
                                        >
                                            <?php echo h($c['full_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-2">
                                <select name="invoice_type" class="form-select">
                                    <option value="">All Types</option>
                                    <option value="product_sale" <?php echo ($invoiceTypeFilter === 'product_sale') ? 'selected' : ''; ?>>
                                        🛍️ Product
                                    </option>
                                    <option value="vehicle_sale" <?php echo ($invoiceTypeFilter === 'vehicle_sale') ? 'selected' : ''; ?>>
                                        🏍️ Vehicle
                                    </option>
                                    <option value="mixed_sale" <?php echo ($invoiceTypeFilter === 'mixed_sale') ? 'selected' : ''; ?>>
                                        📦 Mixed
                                    </option>
                                </select>
                            </div>

                            <div class="col-md-2">
                                <select name="payment_status" class="form-select">
                                    <option value="">All Payment</option>
                                    <option value="paid" <?php echo ($paymentStatusFilter === 'paid') ? 'selected' : ''; ?>>
                                        Paid
                                    </option>
                                    <option value="partial" <?php echo ($paymentStatusFilter === 'partial') ? 'selected' : ''; ?>>
                                        Partial
                                    </option>
                                    <option value="unpaid" <?php echo ($paymentStatusFilter === 'unpaid') ? 'selected' : ''; ?>>
                                        Unpaid
                                    </option>
                                </select>
                            </div>

                            <div class="col-md-1">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="mdi mdi-filter"></i> Filter
                                </button>
                            </div>

                            <div class="col-md-2">
                                <input 
                                    type="date" 
                                    name="date_from" 
                                    class="form-control" 
                                    value="<?php echo h($dateFrom); ?>"
                                >
                            </div>

                            <div class="col-md-2">
                                <input 
                                    type="date" 
                                    name="date_to" 
                                    class="form-control" 
                                    value="<?php echo h($dateTo); ?>"
                                >
                            </div>

                            <div class="col-md-2">
                                <a href="sales-invoices.php" class="btn btn-secondary w-100">
                                    Reset
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- LIST -->
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4">Invoice List</h4>

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
                                        <th>Items</th>
                                        <th>Vehicle Details</th>
                                        <th>Amount</th>
                                        <th>Payment</th>
                                        <th style="width:240px;">Actions</th>
                                    </tr>
                                </thead>

                                <tbody>
                                    <?php if (!empty($invoiceRows)): ?>
                                        <?php $i = 1; foreach ($invoiceRows as $row): ?>
                                            <?php
                                            $realPaymentStatus = $row['real_payment_status'] ?? 'unpaid';

                                            $payBadge = 'danger';
                                            if ($realPaymentStatus === 'paid') {
                                                $payBadge = 'success';
                                            } elseif ($realPaymentStatus === 'partial') {
                                                $payBadge = 'warning';
                                            }

                                            $realBalance = (float)($row['real_balance_amount'] ?? 0);

                                            $typeBadge = 'secondary';
                                            $typeLabel = 'Unknown';

                                            if (($row['invoice_type'] ?? '') === 'product_sale') {
                                                $typeBadge = 'primary';
                                                $typeLabel = 'Product';
                                            } elseif (($row['invoice_type'] ?? '') === 'vehicle_sale') {
                                                $typeBadge = 'success';
                                                $typeLabel = 'Vehicle';
                                            } elseif (($row['invoice_type'] ?? '') === 'mixed_sale') {
                                                $typeBadge = 'info';
                                                $typeLabel = 'Mixed';
                                            }

                                            $vehicleDetails = trim((string)($row['vehicle_details'] ?? ''));
                                            ?>

                                            <tr>
                                                <td><?php echo $i++; ?></td>

                                                <td>
                                                    <strong><?php echo h($row['invoice_no']); ?></strong>
                                                    <div class="small text-muted">
                                                        By: <?php echo h($row['created_by_name'] ?: '-'); ?>
                                                    </div>
                                                </td>

                                                <td><?php echo formatDisplayDate($row['invoice_date']); ?></td>

                                                <td>
                                                    <div><?php echo h($row['branch_name'] ?: '-'); ?></div>
                                                    <div class="small text-muted">
                                                        <?php echo h($row['branch_code'] ?: '-'); ?>
                                                    </div>
                                                </td>

                                                <td>
                                                    <div><?php echo h($row['customer_name'] ?: '-'); ?></div>
                                                    <div class="small text-muted">
                                                        <?php echo h($row['customer_mobile'] ?: '-'); ?>
                                                    </div>
                                                </td>

                                                <td>
                                                    <span class="badge bg-<?php echo $typeBadge; ?>">
                                                        <?php echo $typeLabel; ?> Sale
                                                    </span>
                                                </td>

                                                <td>
                                                    <span class="badge bg-dark">
                                                        <?php echo number_format((int)($row['item_count'] ?? 0)); ?>
                                                    </span>
                                                </td>

                                                <td class="small">
                                                    <?php if ($vehicleDetails !== ''): ?>
                                                        <?php echo h($vehicleDetails); ?>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>

                                                <td class="small">
                                                    <div>
                                                        <strong>Total:</strong> 
                                                        <?php echo money($row['grand_total']); ?>
                                                    </div>
                                                    <div>
                                                        <strong>Paid:</strong> 
                                                        <?php echo money($row['paid_amount']); ?>
                                                    </div>
                                                    <div>
                                                        <strong>Balance:</strong> 
                                                        <?php echo money($realBalance); ?>
                                                    </div>
                                                </td>

                                                <td>
                                                    <span class="badge bg-<?php echo $payBadge; ?>">
                                                        <?php echo h(ucfirst($realPaymentStatus)); ?>
                                                    </span>
                                                </td>

                                                <td>
                                                    <div class="d-flex flex-wrap gap-2">
                                                        <a 
                                                            href="sales-invoice-view.php?id=<?php echo (int)$row['id']; ?>" 
                                                            class="btn btn-sm btn-info"
                                                        >
                                                            View
                                                        </a>

                                                        <a 
                                                            href="sales-invoice-edit.php?id=<?php echo (int)$row['id']; ?>" 
                                                            class="btn btn-sm btn-primary"
                                                        >
                                                            Edit
                                                        </a>

                                                        <a 
                                                            href="sales-invoice-print.php?id=<?php echo (int)$row['id']; ?>" 
                                                            class="btn btn-sm btn-secondary" 
                                                            target="_blank"
                                                        >
                                                            Print
                                                        </a>

                                                        <form 
                                                            method="post" 
                                                            onsubmit="return confirm('Delete this sales invoice?');" 
                                                            style="display:inline;"
                                                        >
                                                            <input type="hidden" name="action" value="delete">
                                                            <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                                                            <button type="submit" class="btn btn-sm btn-danger">
                                                                Delete
                                                            </button>
                                                        </form>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="11" class="text-center text-muted py-4">
                                                No sales invoices found.
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
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