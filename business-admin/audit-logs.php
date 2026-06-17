<?php
session_start();
date_default_timezone_set('Asia/Kolkata');

require_once 'includes/config.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not available.');
}
$conn->set_charset('utf8mb4');

$businessUserId = isset($_SESSION['business_user_id']) ? (int)$_SESSION['business_user_id'] : 0;
if ($businessUserId <= 0 && isset($_SESSION['user_id'])) {
    $businessUserId = (int)$_SESSION['user_id'];
}

$businessId = isset($_SESSION['business_id']) ? (int)$_SESSION['business_id'] : 0;

if ($businessUserId <= 0 || $businessId <= 0) {
    header('Location: login.php');
    exit;
}

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
        SELECT 1 FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
        LIMIT 1
    ");
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
    $stmt = $conn->prepare("
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
        LIMIT 1
    ");
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

function getCountFromSql(mysqli $conn, string $sql): int
{
    $res = $conn->query($sql);
    if (!$res) return 0;

    $row = $res->fetch_assoc();
    $res->free();

    return (int)($row['total'] ?? 0);
}

function getSumFromSql(mysqli $conn, string $sql): float
{
    $res = $conn->query($sql);
    if (!$res) return 0.00;

    $row = $res->fetch_assoc();
    $res->free();

    return (float)($row['total'] ?? 0);
}

foreach (['audit_logs', 'business_users', 'businesses'] as $tbl) {
    if (!tableExists($conn, $tbl)) {
        die($tbl . ' table not found.');
    }
}

$hasBranches = tableExists($conn, 'branches');
$hasSalesInvoices = tableExists($conn, 'sales_invoices');
$hasVehicleSales = tableExists($conn, 'vehicle_sales');
$hasSalesInvoiceItems = tableExists($conn, 'sales_invoice_items');

$hasAuditBranchId = columnExists($conn, 'audit_logs', 'branch_id');
$hasAuditUserId = columnExists($conn, 'audit_logs', 'user_id');
$hasAuditAction = columnExists($conn, 'audit_logs', 'action');
$hasAuditModule = columnExists($conn, 'audit_logs', 'module_name');
$hasAuditRefTable = columnExists($conn, 'audit_logs', 'ref_table');
$hasAuditRefId = columnExists($conn, 'audit_logs', 'ref_id');
$hasAuditDesc = columnExists($conn, 'audit_logs', 'description');
$hasAuditIp = columnExists($conn, 'audit_logs', 'ip_address');
$hasAuditCreatedAt = columnExists($conn, 'audit_logs', 'created_at');

$hasSiGrandTotal = $hasSalesInvoices && columnExists($conn, 'sales_invoices', 'grand_total');
$hasSiPaidAmount = $hasSalesInvoices && columnExists($conn, 'sales_invoices', 'paid_amount');
$hasSiBalanceAmount = $hasSalesInvoices && columnExists($conn, 'sales_invoices', 'balance_amount');
$hasSiInvoiceDate = $hasSalesInvoices && columnExists($conn, 'sales_invoices', 'invoice_date');
$hasSiCreatedAt = $hasSalesInvoices && columnExists($conn, 'sales_invoices', 'created_at');
$hasSiPaymentStatus = $hasSalesInvoices && columnExists($conn, 'sales_invoices', 'payment_status');
$hasSiInvoiceType = $hasSalesInvoices && columnExists($conn, 'sales_invoices', 'invoice_type');

$salesDateColumn = $hasSiInvoiceDate ? 'invoice_date' : ($hasSiCreatedAt ? 'created_at' : 'id');

$loggedUser = null;
$stmt = $conn->prepare("
    SELECT bu.id, bu.full_name, bu.role, bu.status,
           b.business_name, b.status AS business_status
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

if (!$loggedUser || (int)($loggedUser['status'] ?? 0) !== 1 || ($loggedUser['business_status'] ?? '') !== 'active') {
    session_destroy();
    header('Location: login.php');
    exit;
}

/* SALES CALCULATIONS */
$totalSalesInvoices = 0;
$totalSalesAmount = 0.00;
$totalPaidAmount = 0.00;
$totalBalanceAmount = 0.00;
$paidInvoicesCount = 0;
$partialInvoicesCount = 0;
$unpaidInvoicesCount = 0;
$vehicleSalesCount = 0;
$vehicleSalesAmount = 0.00;
$productSalesCount = 0;
$productSalesAmount = 0.00;
$mixedSalesCount = 0;
$mixedSalesAmount = 0.00;
$todaySalesCount = 0;
$todaySalesAmount = 0.00;
$thisMonthSalesCount = 0;
$thisMonthSalesAmount = 0.00;

$today = date('Y-m-d');
$thisMonthStart = date('Y-m-01');
$thisMonthEnd = date('Y-m-t');

if ($hasSalesInvoices) {
    $totalSalesInvoices = getCountFromSql($conn, "
        SELECT COUNT(*) AS total
        FROM sales_invoices
        WHERE business_id = {$businessId}
    ");

    $totalSalesAmount = $hasSiGrandTotal ? getSumFromSql($conn, "
        SELECT COALESCE(SUM(grand_total), 0) AS total
        FROM sales_invoices
        WHERE business_id = {$businessId}
    ") : 0.00;

    $totalPaidAmount = $hasSiPaidAmount ? getSumFromSql($conn, "
        SELECT COALESCE(SUM(paid_amount), 0) AS total
        FROM sales_invoices
        WHERE business_id = {$businessId}
    ") : 0.00;

    if ($hasSiBalanceAmount) {
        $totalBalanceAmount = getSumFromSql($conn, "
            SELECT COALESCE(SUM(balance_amount), 0) AS total
            FROM sales_invoices
            WHERE business_id = {$businessId}
        ");
    } elseif ($hasSiGrandTotal && $hasSiPaidAmount) {
        $totalBalanceAmount = getSumFromSql($conn, "
            SELECT COALESCE(SUM(GREATEST(grand_total - paid_amount, 0)), 0) AS total
            FROM sales_invoices
            WHERE business_id = {$businessId}
        ");
    }

    if ($hasSiPaymentStatus) {
        $paidInvoicesCount = getCountFromSql($conn, "
            SELECT COUNT(*) AS total
            FROM sales_invoices
            WHERE business_id = {$businessId}
              AND payment_status = 'paid'
        ");

        $partialInvoicesCount = getCountFromSql($conn, "
            SELECT COUNT(*) AS total
            FROM sales_invoices
            WHERE business_id = {$businessId}
              AND payment_status IN ('partial', 'partially_paid')
        ");

        $unpaidInvoicesCount = getCountFromSql($conn, "
            SELECT COUNT(*) AS total
            FROM sales_invoices
            WHERE business_id = {$businessId}
              AND payment_status IN ('unpaid', 'pending')
        ");
    } elseif ($hasSiGrandTotal && $hasSiPaidAmount) {
        $paidInvoicesCount = getCountFromSql($conn, "
            SELECT COUNT(*) AS total
            FROM sales_invoices
            WHERE business_id = {$businessId}
              AND grand_total > 0
              AND paid_amount >= grand_total
        ");

        $partialInvoicesCount = getCountFromSql($conn, "
            SELECT COUNT(*) AS total
            FROM sales_invoices
            WHERE business_id = {$businessId}
              AND paid_amount > 0
              AND paid_amount < grand_total
        ");

        $unpaidInvoicesCount = getCountFromSql($conn, "
            SELECT COUNT(*) AS total
            FROM sales_invoices
            WHERE business_id = {$businessId}
              AND COALESCE(paid_amount, 0) <= 0
        ");
    }

    if ($hasSiInvoiceDate || $hasSiCreatedAt) {
        $todaySalesCount = getCountFromSql($conn, "
            SELECT COUNT(*) AS total
            FROM sales_invoices
            WHERE business_id = {$businessId}
              AND DATE({$salesDateColumn}) = '{$today}'
        ");

        $todaySalesAmount = $hasSiGrandTotal ? getSumFromSql($conn, "
            SELECT COALESCE(SUM(grand_total), 0) AS total
            FROM sales_invoices
            WHERE business_id = {$businessId}
              AND DATE({$salesDateColumn}) = '{$today}'
        ") : 0.00;

        $thisMonthSalesCount = getCountFromSql($conn, "
            SELECT COUNT(*) AS total
            FROM sales_invoices
            WHERE business_id = {$businessId}
              AND DATE({$salesDateColumn}) BETWEEN '{$thisMonthStart}' AND '{$thisMonthEnd}'
        ");

        $thisMonthSalesAmount = $hasSiGrandTotal ? getSumFromSql($conn, "
            SELECT COALESCE(SUM(grand_total), 0) AS total
            FROM sales_invoices
            WHERE business_id = {$businessId}
              AND DATE({$salesDateColumn}) BETWEEN '{$thisMonthStart}' AND '{$thisMonthEnd}'
        ") : 0.00;
    }

    if ($hasSalesInvoiceItems) {
        $vehicleSalesCount = getCountFromSql($conn, "
            SELECT COUNT(*) AS total
            FROM (
                SELECT si.id
                FROM sales_invoices si
                INNER JOIN sales_invoice_items sii ON sii.invoice_id = si.id
                WHERE si.business_id = {$businessId}
                  AND sii.item_type = 'vehicle'
                GROUP BY si.id
            ) x
        ");

        $vehicleSalesAmount = getSumFromSql($conn, "
            SELECT COALESCE(SUM(x.invoice_total), 0) AS total
            FROM (
                SELECT si.id, MAX(si.grand_total) AS invoice_total
                FROM sales_invoices si
                INNER JOIN sales_invoice_items sii ON sii.invoice_id = si.id
                WHERE si.business_id = {$businessId}
                  AND sii.item_type = 'vehicle'
                GROUP BY si.id
            ) x
        ");

        $productSalesCount = getCountFromSql($conn, "
            SELECT COUNT(*) AS total
            FROM (
                SELECT si.id
                FROM sales_invoices si
                INNER JOIN sales_invoice_items sii ON sii.invoice_id = si.id
                WHERE si.business_id = {$businessId}
                  AND sii.item_type IN ('product', 'charger')
                GROUP BY si.id
            ) x
        ");

        $productSalesAmount = getSumFromSql($conn, "
            SELECT COALESCE(SUM(x.invoice_total), 0) AS total
            FROM (
                SELECT si.id, MAX(si.grand_total) AS invoice_total
                FROM sales_invoices si
                INNER JOIN sales_invoice_items sii ON sii.invoice_id = si.id
                WHERE si.business_id = {$businessId}
                  AND sii.item_type IN ('product', 'charger')
                GROUP BY si.id
            ) x
        ");

        $mixedSalesCount = getCountFromSql($conn, "
            SELECT COUNT(*) AS total
            FROM (
                SELECT si.id
                FROM sales_invoices si
                INNER JOIN sales_invoice_items sii ON sii.invoice_id = si.id
                WHERE si.business_id = {$businessId}
                GROUP BY si.id
                HAVING SUM(sii.item_type = 'vehicle') > 0
                   AND SUM(sii.item_type IN ('product', 'charger')) > 0
            ) x
        ");

        $mixedSalesAmount = getSumFromSql($conn, "
            SELECT COALESCE(SUM(x.invoice_total), 0) AS total
            FROM (
                SELECT si.id, MAX(si.grand_total) AS invoice_total
                FROM sales_invoices si
                INNER JOIN sales_invoice_items sii ON sii.invoice_id = si.id
                WHERE si.business_id = {$businessId}
                GROUP BY si.id
                HAVING SUM(sii.item_type = 'vehicle') > 0
                   AND SUM(sii.item_type IN ('product', 'charger')) > 0
            ) x
        ");
    } elseif ($hasSiInvoiceType) {
        $vehicleSalesCount = getCountFromSql($conn, "
            SELECT COUNT(*) AS total
            FROM sales_invoices
            WHERE business_id = {$businessId}
              AND invoice_type LIKE '%vehicle%'
        ");

        $vehicleSalesAmount = getSumFromSql($conn, "
            SELECT COALESCE(SUM(grand_total), 0) AS total
            FROM sales_invoices
            WHERE business_id = {$businessId}
              AND invoice_type LIKE '%vehicle%'
        ");

        $productSalesCount = getCountFromSql($conn, "
            SELECT COUNT(*) AS total
            FROM sales_invoices
            WHERE business_id = {$businessId}
              AND invoice_type LIKE '%product%'
        ");

        $productSalesAmount = getSumFromSql($conn, "
            SELECT COALESCE(SUM(grand_total), 0) AS total
            FROM sales_invoices
            WHERE business_id = {$businessId}
              AND invoice_type LIKE '%product%'
        ");

        $mixedSalesCount = getCountFromSql($conn, "
            SELECT COUNT(*) AS total
            FROM sales_invoices
            WHERE business_id = {$businessId}
              AND invoice_type LIKE '%mixed%'
        ");

        $mixedSalesAmount = getSumFromSql($conn, "
            SELECT COALESCE(SUM(grand_total), 0) AS total
            FROM sales_invoices
            WHERE business_id = {$businessId}
              AND invoice_type LIKE '%mixed%'
        ");
    }
}

/* MASTER DATA */
$branches = [];
if ($hasBranches) {
    $branches = fetchAllAssoc($conn, "
        SELECT id, branch_name, branch_code
        FROM branches
        WHERE business_id = {$businessId}
        ORDER BY branch_name ASC
    ");
}

$users = fetchAllAssoc($conn, "
    SELECT id, full_name, username, role
    FROM business_users
    WHERE business_id = {$businessId}
    ORDER BY full_name ASC
");

$moduleRows = $hasAuditModule ? fetchAllAssoc($conn, "
    SELECT DISTINCT module_name
    FROM audit_logs
    WHERE business_id = {$businessId}
      AND module_name IS NOT NULL
      AND module_name <> ''
    ORDER BY module_name ASC
") : [];

$actionRows = $hasAuditAction ? fetchAllAssoc($conn, "
    SELECT DISTINCT action
    FROM audit_logs
    WHERE business_id = {$businessId}
      AND action IS NOT NULL
      AND action <> ''
    ORDER BY action ASC
") : [];

/* FILTERS */
$search = trim($_GET['search'] ?? '');
$branchFilter = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : 0;
$userFilter = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$moduleFilter = trim($_GET['module_name'] ?? '');
$actionFilter = trim($_GET['action'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');
$showOnlySales = isset($_GET['show_sales']) ? (int)$_GET['show_sales'] : 0;

$where = ["al.business_id = {$businessId}"];

if ($search !== '') {
    $safe = $conn->real_escape_string($search);
    $searchParts = [];

    if ($hasAuditAction) $searchParts[] = "al.action LIKE '%{$safe}%'";
    if ($hasAuditModule) $searchParts[] = "al.module_name LIKE '%{$safe}%'";
    if ($hasAuditRefTable) $searchParts[] = "al.ref_table LIKE '%{$safe}%'";
    if ($hasAuditDesc) $searchParts[] = "al.description LIKE '%{$safe}%'";
    if ($hasAuditIp) $searchParts[] = "al.ip_address LIKE '%{$safe}%'";
    $searchParts[] = "bu.full_name LIKE '%{$safe}%'";
    $searchParts[] = "bu.username LIKE '%{$safe}%'";

    if ($hasBranches && $hasAuditBranchId) {
        $searchParts[] = "br.branch_name LIKE '%{$safe}%'";
        $searchParts[] = "br.branch_code LIKE '%{$safe}%'";
    }

    $where[] = '(' . implode(' OR ', $searchParts) . ')';
}

if ($branchFilter > 0 && $hasAuditBranchId) {
    $where[] = "al.branch_id = {$branchFilter}";
}

if ($userFilter > 0 && $hasAuditUserId) {
    $where[] = "al.user_id = {$userFilter}";
}

if ($moduleFilter !== '' && $hasAuditModule) {
    $safe = $conn->real_escape_string($moduleFilter);
    $where[] = "al.module_name = '{$safe}'";
}

if ($actionFilter !== '' && $hasAuditAction) {
    $safe = $conn->real_escape_string($actionFilter);
    $where[] = "al.action = '{$safe}'";
}

if ($dateFrom !== '' && $hasAuditCreatedAt) {
    $safe = $conn->real_escape_string($dateFrom);
    $where[] = "DATE(al.created_at) >= '{$safe}'";
}

if ($dateTo !== '' && $hasAuditCreatedAt) {
    $safe = $conn->real_escape_string($dateTo);
    $where[] = "DATE(al.created_at) <= '{$safe}'";
}

if ($showOnlySales === 1) {
    $salesParts = [];
    if ($hasAuditModule) $salesParts[] = "al.module_name LIKE '%Sales%'";
    if ($hasAuditRefTable) $salesParts[] = "al.ref_table = 'sales_invoices'";
    if ($hasAuditDesc) $salesParts[] = "al.description LIKE '%Invoice%'";
    if (!empty($salesParts)) {
        $where[] = '(' . implode(' OR ', $salesParts) . ')';
    }
}

$whereSql = implode(' AND ', $where);

$joinSql = "";
$joinSql .= $hasAuditUserId
    ? " LEFT JOIN business_users bu ON bu.id = al.user_id "
    : " LEFT JOIN business_users bu ON 1 = 0 ";

$joinSql .= ($hasBranches && $hasAuditBranchId)
    ? " LEFT JOIN branches br ON br.id = al.branch_id "
    : " LEFT JOIN branches br ON 1 = 0 ";

/* AUDIT CALCULATIONS */
$totalLogs = getCountFromSql($conn, "
    SELECT COUNT(*) AS total
    FROM audit_logs
    WHERE business_id = {$businessId}
");

$todayLogs = $hasAuditCreatedAt ? getCountFromSql($conn, "
    SELECT COUNT(*) AS total
    FROM audit_logs
    WHERE business_id = {$businessId}
      AND DATE(created_at) = '{$today}'
") : 0;

$thisMonthLogs = $hasAuditCreatedAt ? getCountFromSql($conn, "
    SELECT COUNT(*) AS total
    FROM audit_logs
    WHERE business_id = {$businessId}
      AND DATE(created_at) BETWEEN '{$thisMonthStart}' AND '{$thisMonthEnd}'
") : 0;

$uniqueUsersCount = $hasAuditUserId ? getCountFromSql($conn, "
    SELECT COUNT(DISTINCT user_id) AS total
    FROM audit_logs
    WHERE business_id = {$businessId}
      AND user_id IS NOT NULL
      AND user_id > 0
") : 0;

$filteredCount = getCountFromSql($conn, "
    SELECT COUNT(*) AS total
    FROM audit_logs al
    {$joinSql}
    WHERE {$whereSql}
");

$createCount = $hasAuditAction ? getCountFromSql($conn, "
    SELECT COUNT(*) AS total
    FROM audit_logs
    WHERE business_id = {$businessId}
      AND (
        LOWER(action) LIKE '%create%'
        OR LOWER(action) LIKE '%add%'
        OR LOWER(action) LIKE '%insert%'
      )
") : 0;

$updateCount = $hasAuditAction ? getCountFromSql($conn, "
    SELECT COUNT(*) AS total
    FROM audit_logs
    WHERE business_id = {$businessId}
      AND (
        LOWER(action) LIKE '%update%'
        OR LOWER(action) LIKE '%edit%'
        OR LOWER(action) LIKE '%modify%'
      )
") : 0;

$deleteCount = $hasAuditAction ? getCountFromSql($conn, "
    SELECT COUNT(*) AS total
    FROM audit_logs
    WHERE business_id = {$businessId}
      AND (
        LOWER(action) LIKE '%delete%'
        OR LOWER(action) LIKE '%remove%'
      )
") : 0;

$loginCount = $hasAuditAction ? getCountFromSql($conn, "
    SELECT COUNT(*) AS total
    FROM audit_logs
    WHERE business_id = {$businessId}
      AND (
        LOWER(action) LIKE '%login%'
        OR LOWER(action) LIKE '%logout%'
      )
") : 0;

$moduleStats = $hasAuditModule ? fetchAllAssoc($conn, "
    SELECT module_name, COUNT(*) AS total
    FROM audit_logs
    WHERE business_id = {$businessId}
      AND module_name IS NOT NULL
      AND module_name <> ''
    GROUP BY module_name
    ORDER BY total DESC
    LIMIT 10
") : [];

$actionStats = $hasAuditAction ? fetchAllAssoc($conn, "
    SELECT action, COUNT(*) AS total
    FROM audit_logs
    WHERE business_id = {$businessId}
      AND action IS NOT NULL
      AND action <> ''
    GROUP BY action
    ORDER BY total DESC
    LIMIT 10
") : [];

$selectBranch = ($hasBranches && $hasAuditBranchId)
    ? "br.branch_name, br.branch_code"
    : "NULL AS branch_name, NULL AS branch_code";

$selectUser = $hasAuditUserId
    ? "bu.full_name AS user_name, bu.username, bu.role AS user_role"
    : "NULL AS user_name, NULL AS username, NULL AS user_role";

$rows = fetchAllAssoc($conn, "
    SELECT al.*, {$selectBranch}, {$selectUser}
    FROM audit_logs al
    {$joinSql}
    WHERE {$whereSql}
    ORDER BY al.id DESC
    LIMIT 500
");

$pageTitle = 'Audit Logs & Sales Reports';
$currentPage = 'audit-logs';
?>
<!doctype html>
<html lang="en">
<?php include('includes/head.php'); ?>

<body data-sidebar="dark">

<style>
.page-content { padding-bottom: 90px !important; }
.card { margin-bottom: 24px; }
.table-responsive { overflow-x: auto; }
.main-content { min-height: calc(100vh - 70px); }
.stat-card .card-body { min-height: 92px; }
.log-desc { min-width: 280px; white-space: normal; }
.sales-highlight { background-color: #e8f5e9 !important; }
.badge-vehicle { background-color: #4caf50; }
.badge-product { background-color: #2196f3; }
.badge-mixed { background-color: #ff9800; }
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
        <h4 class="mb-1">Audit Logs & Sales Reports</h4>
        <p class="text-muted mb-0">Business activity logs with correct sales, paid and pending calculations</p>
    </div>
    <div class="col-md-5 text-md-end mt-3 mt-md-0">
        <a href="audit-logs.php?show_sales=1" class="btn btn-info <?php echo $showOnlySales === 1 ? 'active' : ''; ?>">Show Sales Only</a>
        <a href="audit-logs.php" class="btn btn-secondary <?php echo $showOnlySales === 0 ? 'active' : ''; ?>">All Logs</a>
        <a href="index.php" class="btn btn-light">Dashboard</a>
    </div>
</div>

<div class="row">
    <div class="col-md-3">
        <div class="card stat-card bg-success bg-opacity-10">
            <div class="card-body text-center">
                <p class="text-muted mb-1">Total Sales Invoices</p>
                <h3 class="mb-0 text-success"><?php echo number_format($totalSalesInvoices); ?></h3>
                <small><?php echo money($totalSalesAmount); ?></small>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="card stat-card" style="background:#e8f5e9;">
            <div class="card-body text-center">
                <p class="text-muted mb-1">Vehicle Sales</p>
                <h3 class="mb-0 text-success"><?php echo number_format($vehicleSalesCount); ?></h3>
                <small><?php echo money($vehicleSalesAmount); ?></small>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="card stat-card" style="background:#e3f2fd;">
            <div class="card-body text-center">
                <p class="text-muted mb-1">Product / Charger Sales</p>
                <h3 class="mb-0 text-primary"><?php echo number_format($productSalesCount); ?></h3>
                <small><?php echo money($productSalesAmount); ?></small>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="card stat-card" style="background:#fff3e0;">
            <div class="card-body text-center">
                <p class="text-muted mb-1">Mixed Sales</p>
                <h3 class="mb-0 text-warning"><?php echo number_format($mixedSalesCount); ?></h3>
                <small><?php echo money($mixedSalesAmount); ?></small>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-4">
        <div class="card stat-card">
            <div class="card-body text-center">
                <p class="text-muted mb-1">Total Sales Value</p>
                <h3 class="mb-0 text-primary"><?php echo money($totalSalesAmount); ?></h3>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card stat-card">
            <div class="card-body text-center">
                <p class="text-muted mb-1">Total Paid Amount</p>
                <h3 class="mb-0 text-success"><?php echo money($totalPaidAmount); ?></h3>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card stat-card">
            <div class="card-body text-center">
                <p class="text-muted mb-1">Pending Amount</p>
                <h3 class="mb-0 text-danger"><?php echo money($totalBalanceAmount); ?></h3>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body text-center">
                <p class="text-muted mb-1">Paid Invoices</p>
                <h4 class="text-success mb-0"><?php echo number_format($paidInvoicesCount); ?></h4>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body text-center">
                <p class="text-muted mb-1">Partial Invoices</p>
                <h4 class="text-warning mb-0"><?php echo number_format($partialInvoicesCount); ?></h4>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body text-center">
                <p class="text-muted mb-1">Unpaid Invoices</p>
                <h4 class="text-danger mb-0"><?php echo number_format($unpaidInvoicesCount); ?></h4>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body text-center">
                <p class="text-muted mb-1">Filtered Logs</p>
                <h4 class="text-info mb-0"><?php echo number_format($filteredCount); ?></h4>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-6">
        <div class="card stat-card">
            <div class="card-body d-flex justify-content-between align-items-center">
                <div>
                    <p class="text-muted mb-1">Today's Sales</p>
                    <h3 class="text-info mb-0"><?php echo number_format($todaySalesCount); ?></h3>
                </div>
                <div class="text-end">
                    <p class="text-muted mb-1">Amount</p>
                    <h4 class="mb-0"><?php echo money($todaySalesAmount); ?></h4>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card stat-card">
            <div class="card-body d-flex justify-content-between align-items-center">
                <div>
                    <p class="text-muted mb-1">This Month Sales</p>
                    <h3 class="text-primary mb-0"><?php echo number_format($thisMonthSalesCount); ?></h3>
                </div>
                <div class="text-end">
                    <p class="text-muted mb-1">Amount</p>
                    <h4 class="mb-0"><?php echo money($thisMonthSalesAmount); ?></h4>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-3"><div class="card stat-card"><div class="card-body text-center"><p class="text-muted mb-1">Total Audit Logs</p><h3><?php echo number_format($totalLogs); ?></h3></div></div></div>
    <div class="col-md-3"><div class="card stat-card"><div class="card-body text-center"><p class="text-muted mb-1">Today Logs</p><h3 class="text-primary"><?php echo number_format($todayLogs); ?></h3></div></div></div>
    <div class="col-md-3"><div class="card stat-card"><div class="card-body text-center"><p class="text-muted mb-1">This Month Logs</p><h3 class="text-success"><?php echo number_format($thisMonthLogs); ?></h3></div></div></div>
    <div class="col-md-3"><div class="card stat-card"><div class="card-body text-center"><p class="text-muted mb-1">Users in Logs</p><h3 class="text-info"><?php echo number_format($uniqueUsersCount); ?></h3></div></div></div>
</div>

<div class="card">
<div class="card-body">
<form method="get" class="row g-3">
    <?php if ($showOnlySales === 1): ?>
        <input type="hidden" name="show_sales" value="1">
    <?php endif; ?>

    <div class="col-md-3">
        <label class="form-label">Search</label>
        <input type="text" name="search" class="form-control" placeholder="Action, module, description, IP..." value="<?php echo h($search); ?>">
    </div>

    <?php if ($hasBranches && $hasAuditBranchId): ?>
    <div class="col-md-2">
        <label class="form-label">Branch</label>
        <select name="branch_id" class="form-select">
            <option value="0">All Branches</option>
            <?php foreach ($branches as $b): ?>
                <option value="<?php echo (int)$b['id']; ?>" <?php echo $branchFilter === (int)$b['id'] ? 'selected' : ''; ?>>
                    <?php echo h($b['branch_name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>

    <?php if ($hasAuditUserId): ?>
    <div class="col-md-2">
        <label class="form-label">User</label>
        <select name="user_id" class="form-select">
            <option value="0">All Users</option>
            <?php foreach ($users as $u): ?>
                <option value="<?php echo (int)$u['id']; ?>" <?php echo $userFilter === (int)$u['id'] ? 'selected' : ''; ?>>
                    <?php echo h($u['full_name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>

    <?php if ($hasAuditModule): ?>
    <div class="col-md-2">
        <label class="form-label">Module</label>
        <select name="module_name" class="form-select">
            <option value="">All Modules</option>
            <?php foreach ($moduleRows as $m): ?>
                <option value="<?php echo h($m['module_name']); ?>" <?php echo $moduleFilter === (string)$m['module_name'] ? 'selected' : ''; ?>>
                    <?php echo h($m['module_name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>

    <?php if ($hasAuditAction): ?>
    <div class="col-md-2">
        <label class="form-label">Action</label>
        <select name="action" class="form-select">
            <option value="">All Actions</option>
            <?php foreach ($actionRows as $a): ?>
                <option value="<?php echo h($a['action']); ?>" <?php echo $actionFilter === (string)$a['action'] ? 'selected' : ''; ?>>
                    <?php echo h($a['action']); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>

    <?php if ($hasAuditCreatedAt): ?>
    <div class="col-md-1">
        <label class="form-label">From</label>
        <input type="date" name="date_from" class="form-control" value="<?php echo h($dateFrom); ?>">
    </div>

    <div class="col-md-1">
        <label class="form-label">To</label>
        <input type="date" name="date_to" class="form-control" value="<?php echo h($dateTo); ?>">
    </div>
    <?php endif; ?>

    <div class="col-md-12 d-flex gap-2 flex-wrap">
        <button type="submit" class="btn btn-primary">Filter</button>
        <a href="audit-logs.php" class="btn btn-light">Reset</a>
        <span class="btn btn-outline-secondary disabled">Filtered: <?php echo number_format($filteredCount); ?></span>
    </div>
</form>
</div>
</div>

<div class="row">
    <div class="col-xl-6">
        <div class="card h-100">
            <div class="card-body">
                <h4 class="card-title mb-3">Top Modules</h4>
                <table class="table table-bordered table-striped mb-0">
                    <thead><tr><th>Module</th><th style="width:130px;">Logs</th></tr></thead>
                    <tbody>
                    <?php if ($moduleStats): foreach ($moduleStats as $m): ?>
                        <tr><td><?php echo h($m['module_name']); ?></td><td><?php echo number_format((int)$m['total']); ?></td></tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="2" class="text-center text-muted">No module data.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-xl-6">
        <div class="card h-100">
            <div class="card-body">
                <h4 class="card-title mb-3">Top Actions</h4>
                <table class="table table-bordered table-striped mb-0">
                    <thead><tr><th>Action</th><th style="width:130px;">Logs</th></tr></thead>
                    <tbody>
                    <?php if ($actionStats): foreach ($actionStats as $a): ?>
                        <tr><td><?php echo h($a['action']); ?></td><td><?php echo number_format((int)$a['total']); ?></td></tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="2" class="text-center text-muted">No action data.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="card">
<div class="card-body">
<h4 class="card-title mb-4">
    Audit Log List
    <?php if ($showOnlySales === 1): ?>
        <span class="badge bg-info ms-2">Sales Records Only</span>
    <?php endif; ?>
</h4>

<div class="table-responsive">
<table class="table table-bordered table-striped align-middle mb-0">
<thead>
<tr>
    <th>#</th>
    <th>Date & Time</th>
    <th>User</th>
    <th>Branch</th>
    <th>Action</th>
    <th>Module</th>
    <th>Reference</th>
    <th>Description</th>
    <th>IP Address</th>
</tr>
</thead>
<tbody>
<?php if ($rows): ?>
    <?php $i = 1; foreach ($rows as $row): ?>
        <?php
        $actionText = strtolower((string)($row['action'] ?? ''));
        $badge = 'secondary';

        if (str_contains($actionText, 'create') || str_contains($actionText, 'add') || str_contains($actionText, 'insert')) {
            $badge = 'success';
        } elseif (str_contains($actionText, 'update') || str_contains($actionText, 'edit') || str_contains($actionText, 'modify')) {
            $badge = 'primary';
        } elseif (str_contains($actionText, 'delete') || str_contains($actionText, 'remove')) {
            $badge = 'danger';
        } elseif (str_contains($actionText, 'login') || str_contains($actionText, 'logout')) {
            $badge = 'warning';
        }

        $isSalesLog = (($row['module_name'] ?? '') === 'Sales Invoice' || ($row['ref_table'] ?? '') === 'sales_invoices');
        ?>
        <tr class="<?php echo $isSalesLog ? 'sales-highlight' : ''; ?>">
            <td><?php echo $i++; ?></td>
            <td>
                <div><?php echo !empty($row['created_at']) ? h(date('d M Y', strtotime($row['created_at']))) : '-'; ?></div>
                <div class="small text-muted"><?php echo !empty($row['created_at']) ? h(date('h:i:s A', strtotime($row['created_at']))) : '-'; ?></div>
            </td>
            <td>
                <div><?php echo h($row['user_name'] ?: '-'); ?></div>
                <div class="small text-muted">
                    <?php echo h($row['username'] ?: '-'); ?>
                    <?php if (!empty($row['user_role'])): ?>
                        | <?php echo h(ucwords(str_replace('_', ' ', $row['user_role']))); ?>
                    <?php endif; ?>
                </div>
            </td>
            <td>
                <div><?php echo h($row['branch_name'] ?: '-'); ?></div>
                <div class="small text-muted"><?php echo h($row['branch_code'] ?: '-'); ?></div>
            </td>
            <td><span class="badge bg-<?php echo $badge; ?>"><?php echo h($row['action'] ?? '-'); ?></span></td>
            <td>
                <?php if (!empty($row['module_name'])): ?>
                    <span class="badge bg-info"><?php echo h($row['module_name']); ?></span>
                <?php else: ?>
                    -
                <?php endif; ?>
            </td>
            <td class="small">
                <div><strong>Table:</strong> <?php echo h($row['ref_table'] ?? '-'); ?></div>
                <div><strong>ID:</strong> <?php echo isset($row['ref_id']) && $row['ref_id'] !== null ? (int)$row['ref_id'] : '-'; ?></div>
            </td>
            <td class="log-desc"><?php echo nl2br(h($row['description'] ?? '-')); ?></td>
            <td><?php echo h($row['ip_address'] ?? '-'); ?></td>
        </tr>
    <?php endforeach; ?>
<?php else: ?>
    <tr>
        <td colspan="9" class="text-center text-muted py-4">No audit logs found.</td>
    </tr>
<?php endif; ?>
</tbody>
</table>
</div>

<div class="mt-3 text-muted small">Showing latest 500 records.</div>
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