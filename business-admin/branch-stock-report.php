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

if (!$loggedUser || (int)$loggedUser['status'] !== 1 || ($loggedUser['business_status'] ?? '') !== 'active') {
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
if (!tableExists($conn, 'product_stock')) {
    die('product_stock table not found.');
}
if (!tableExists($conn, 'vehicle_stock')) {
    die('vehicle_stock table not found.');
}
if (!tableExists($conn, 'products')) {
    die('products table not found.');
}
if (!tableExists($conn, 'vehicle_models')) {
    die('vehicle_models table not found.');
}

$hasProductCategories = tableExists($conn, 'product_categories');
$hasVehicleBrands = tableExists($conn, 'vehicle_brands');

/* -------------------------------------------------------
   FILTERS
------------------------------------------------------- */
$search = trim($_GET['search'] ?? '');
$branchFilter = (int)($_GET['branch_id'] ?? 0);
$stockType = trim($_GET['stock_type'] ?? ''); // all, low, out, positive

$whereBranches = ["b.business_id = {$businessId}"];

if ($branchFilter > 0) {
    $whereBranches[] = "b.id = {$branchFilter}";
}

if ($search !== '') {
    $safe = $conn->real_escape_string($search);
    $whereBranches[] = "(
        b.branch_name LIKE '%{$safe}%'
        OR b.branch_code LIKE '%{$safe}%'
        OR b.city LIKE '%{$safe}%'
        OR b.district LIKE '%{$safe}%'
        OR b.state LIKE '%{$safe}%'
    )";
}

$whereBranchSql = implode(' AND ', $whereBranches);

/* -------------------------------------------------------
   MASTER BRANCHES
------------------------------------------------------- */
$branchOptions = fetchAllAssoc(
    $conn,
    "SELECT id, branch_name, branch_code
     FROM branches
     WHERE business_id = {$businessId} AND status = 'active'
     ORDER BY branch_name ASC"
);

// Get current branch name for display
$currentBranchName = '';
foreach ($branchOptions as $b) {
    if ((int)$b['id'] === $currentBranchId) {
        $currentBranchName = $b['branch_name'];
        break;
    }
}

/* -------------------------------------------------------
   SUMMARY CARDS
------------------------------------------------------- */
$totalBranches = getCount($conn, 'branches', "business_id = {$businessId} AND status = 'active'");
$totalProductStockRows = getCount($conn, 'product_stock', "business_id = {$businessId}");
$totalVehicleStockRows = getCount($conn, 'vehicle_stock', "business_id = {$businessId}");

// Total products in stock (sum of quantities)
$totalProductQuantity = 0;
$res = $conn->query("SELECT COALESCE(SUM(qty_available), 0) AS total_qty FROM product_stock WHERE business_id = {$businessId}");
if ($res) {
    $row = $res->fetch_assoc();
    $totalProductQuantity = (float)($row['total_qty'] ?? 0);
}

// Total vehicles in stock
$totalVehiclesInStock = getCount($conn, 'vehicle_stock', "business_id = {$businessId} AND stock_status = 'in_stock'");

// Low stock products count
$totalLowStockProducts = 0;
$res = $conn->query("SELECT COUNT(*) AS total
                     FROM product_stock ps
                     INNER JOIN products p ON p.id = ps.product_id
                     WHERE ps.business_id = {$businessId}
                       AND ps.qty_available <= p.min_stock_qty
                       AND ps.qty_available > 0");
if ($res) {
    $row = $res->fetch_assoc();
    $totalLowStockProducts = (int)($row['total'] ?? 0);
}

// Out of stock products count
$totalOutOfStockProducts = 0;
$res = $conn->query("SELECT COUNT(*) AS total
                     FROM product_stock
                     WHERE business_id = {$businessId}
                       AND qty_available <= 0");
if ($res) {
    $row = $res->fetch_assoc();
    $totalOutOfStockProducts = (int)($row['total'] ?? 0);
}

/* -------------------------------------------------------
   BRANCH-WISE SUMMARY
------------------------------------------------------- */
$branchSummaries = [];

$sqlSummary = "SELECT
                    b.id,
                    b.branch_name,
                    b.branch_code,
                    b.city,
                    b.district,
                    b.state,
                    b.status,
                    b.is_head_office,
                    b.contact_person,
                    b.mobile,
                    b.email,

                    (
                        SELECT COUNT(*)
                        FROM product_stock ps
                        WHERE ps.business_id = b.business_id
                          AND ps.branch_id = b.id
                    ) AS product_stock_rows,

                    (
                        SELECT COALESCE(SUM(ps.qty_available), 0)
                        FROM product_stock ps
                        WHERE ps.business_id = b.business_id
                          AND ps.branch_id = b.id
                    ) AS total_product_qty,

                    (
                        SELECT COALESCE(SUM(ps.qty_available * COALESCE(ps.last_purchase_price, p.purchase_price, 0)), 0)
                        FROM product_stock ps
                        INNER JOIN products p ON p.id = ps.product_id
                        WHERE ps.business_id = b.business_id
                          AND ps.branch_id = b.id
                    ) AS total_stock_value,

                    (
                        SELECT COUNT(*)
                        FROM product_stock ps
                        INNER JOIN products p ON p.id = ps.product_id
                        WHERE ps.business_id = b.business_id
                          AND ps.branch_id = b.id
                          AND ps.qty_available <= p.min_stock_qty
                          AND ps.qty_available > 0
                    ) AS low_stock_products,

                    (
                        SELECT COUNT(*)
                        FROM product_stock ps
                        WHERE ps.business_id = b.business_id
                          AND ps.branch_id = b.id
                          AND ps.qty_available <= 0
                    ) AS out_of_stock_products,

                    (
                        SELECT COUNT(*)
                        FROM vehicle_stock vs
                        WHERE vs.business_id = b.business_id
                          AND vs.branch_id = b.id
                    ) AS vehicle_stock_rows,

                    (
                        SELECT COUNT(*)
                        FROM vehicle_stock vs
                        WHERE vs.business_id = b.business_id
                          AND vs.branch_id = b.id
                          AND vs.stock_status = 'in_stock'
                    ) AS vehicles_in_stock,

                    (
                        SELECT COUNT(*)
                        FROM vehicle_stock vs
                        WHERE vs.business_id = b.business_id
                          AND vs.branch_id = b.id
                          AND vs.stock_status = 'sold'
                    ) AS vehicles_sold,

                    (
                        SELECT COUNT(*)
                        FROM vehicle_stock vs
                        WHERE vs.business_id = b.business_id
                          AND vs.branch_id = b.id
                          AND vs.stock_status = 'reserved'
                    ) AS vehicles_reserved,

                    (
                        SELECT COUNT(*)
                        FROM vehicle_stock vs
                        WHERE vs.business_id = b.business_id
                          AND vs.branch_id = b.id
                          AND vs.stock_status = 'demo'
                    ) AS vehicles_demo

                FROM branches b
                WHERE {$whereBranchSql}
                ORDER BY b.branch_name ASC";

$res = $conn->query($sqlSummary);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $branchSummaries[] = $row;
    }
}

/* -------------------------------------------------------
   PRODUCT STOCK DETAILS WITH FILTERS
------------------------------------------------------- */
$productStockRows = [];

$productWhere = ["ps.business_id = {$businessId}"];

if ($branchFilter > 0) {
    $productWhere[] = "ps.branch_id = {$branchFilter}";
}

if ($search !== '') {
    $safe = $conn->real_escape_string($search);
    $productWhere[] = "(
        p.product_name LIKE '%{$safe}%'
        OR p.item_code LIKE '%{$safe}%'
        OR p.product_code LIKE '%{$safe}%'
        OR p.brand_name LIKE '%{$safe}%'
        OR p.part_number LIKE '%{$safe}%'
        OR p.hsn_code LIKE '%{$safe}%'
        " . ($hasProductCategories ? "OR pc.category_name LIKE '%{$safe}%'" : "") . "
        OR br.branch_name LIKE '%{$safe}%'
        OR br.branch_code LIKE '%{$safe}%'
    )";
}

if ($stockType === 'low') {
    $productWhere[] = "ps.qty_available <= p.min_stock_qty AND ps.qty_available > 0";
} elseif ($stockType === 'out') {
    $productWhere[] = "ps.qty_available <= 0";
} elseif ($stockType === 'positive') {
    $productWhere[] = "ps.qty_available > 0";
}

$productWhereSql = implode(' AND ', $productWhere);

$sqlProducts = "SELECT
                    br.id AS branch_id,
                    br.branch_name,
                    br.branch_code,
                    p.id AS product_id,
                    p.product_name,
                    p.item_code,
                    p.product_code,
                    p.brand_name,
                    p.part_number,
                    p.unit,
                    p.unit_name,
                    p.min_stock_qty,
                    ps.qty_available,
                    ps.last_purchase_price,
                    p.purchase_price,
                    p.selling_price,
                    p.mrp,
                    p.gst_percent,
                    p.hsn_code,
                    " . ($hasProductCategories ? "pc.category_name" : "NULL AS category_name") . ",
                    ps.updated_at,
                    (ps.qty_available * COALESCE(ps.last_purchase_price, p.purchase_price, 0)) AS stock_value
                FROM product_stock ps
                INNER JOIN branches br ON br.id = ps.branch_id
                INNER JOIN products p ON p.id = ps.product_id
                " . ($hasProductCategories ? "LEFT JOIN product_categories pc ON pc.id = p.category_id" : "") . "
                WHERE {$productWhereSql}
                ORDER BY br.branch_name ASC, 
                    CASE 
                        WHEN ps.qty_available <= 0 THEN 1
                        WHEN ps.qty_available <= p.min_stock_qty THEN 2
                        ELSE 3
                    END,
                    p.product_name ASC";

$res = $conn->query($sqlProducts);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $productStockRows[] = $row;
    }
}

/* -------------------------------------------------------
   VEHICLE STOCK DETAILS WITH FILTERS
------------------------------------------------------- */
$vehicleStockRows = [];

$vehicleWhere = ["vs.business_id = {$businessId}"];

if ($branchFilter > 0) {
    $vehicleWhere[] = "vs.branch_id = {$branchFilter}";
}

if ($search !== '') {
    $safe = $conn->real_escape_string($search);
    $vehicleWhere[] = "(
        vb.brand_name LIKE '%{$safe}%'
        OR vm.model_name LIKE '%{$safe}%'
        OR vm.variant_name LIKE '%{$safe}%'
        OR vs.chassis_no LIKE '%{$safe}%'
        OR vs.engine_no LIKE '%{$safe}%'
        OR vs.motor_no LIKE '%{$safe}%'
        OR vs.battery_no LIKE '%{$safe}%'
        OR vs.charger_no LIKE '%{$safe}%'
        OR vs.color LIKE '%{$safe}%'
        OR br.branch_name LIKE '%{$safe}%'
        OR br.branch_code LIKE '%{$safe}%'
    )";
}

if ($stockType === 'vehicle_in_stock') {
    $vehicleWhere[] = "vs.stock_status = 'in_stock'";
} elseif ($stockType === 'vehicle_sold') {
    $vehicleWhere[] = "vs.stock_status = 'sold'";
} elseif ($stockType === 'vehicle_reserved') {
    $vehicleWhere[] = "vs.stock_status = 'reserved'";
}

$vehicleWhereSql = implode(' AND ', $vehicleWhere);

$sqlVehicles = "SELECT
                    br.id AS branch_id,
                    br.branch_name,
                    br.branch_code,
                    vs.id AS vehicle_stock_id,
                    vb.brand_name,
                    vm.model_name,
                    vm.variant_name,
                    vs.color,
                    vs.chassis_no,
                    vs.engine_no,
                    vs.motor_no,
                    vs.battery_brand,
                    vs.battery_no,
                    vs.battery_capacity,
                    vs.battery_warranty_upto,
                    vs.charger_brand,
                    vs.charger_no,
                    vs.charger_type,
                    vs.charger_warranty_upto,
                    vs.key_no,
                    vs.manufacture_year,
                    vs.purchase_date,
                    vs.purchase_cost,
                    vs.sale_price,
                    vs.stock_status,
                    vs.remarks,
                    vs.created_at,
                    vm.battery_capacity AS model_battery_capacity,
                    vm.motor_power,
                    vm.range_km
                FROM vehicle_stock vs
                INNER JOIN branches br ON br.id = vs.branch_id
                INNER JOIN vehicle_models vm ON vm.id = vs.model_id
                " . ($hasVehicleBrands ? "LEFT JOIN vehicle_brands vb ON vb.id = vm.brand_id" : "") . "
                WHERE {$vehicleWhereSql}
                ORDER BY br.branch_name ASC, 
                    CASE vs.stock_status
                        WHEN 'in_stock' THEN 1
                        WHEN 'reserved' THEN 2
                        WHEN 'demo' THEN 3
                        WHEN 'transferred' THEN 4
                        WHEN 'sold' THEN 5
                        ELSE 6
                    END,
                    vs.id DESC";

$res = $conn->query($sqlVehicles);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $vehicleStockRows[] = $row;
    }
}

$pageTitle = 'Branch Stock Report';
$currentPage = 'branch-stock-report';
?>
<!doctype html>
<html lang="en">

<?php include('includes/head.php'); ?>

<body data-sidebar="dark">

<style>
    .page-content {
        padding-bottom: 90px !important;
    }
    .card {
        margin-bottom: 24px;
    }
    .table-responsive {
        overflow-x: auto;
    }
    .summary-card {
        transition: transform 0.2s;
        cursor: pointer;
    }
    .summary-card:hover {
        transform: translateY(-2px);
    }
    .stock-badge {
        font-size: 0.75rem;
        padding: 3px 8px;
    }
    .branch-header {
        background-color: #f8f9fa;
        font-weight: 600;
    }
    .filter-active {
        background-color: #e3f2fd;
        border-left: 3px solid #0d6efd;
    }
    .nav-pills .nav-link {
        color: #495057;
    }
    .nav-pills .nav-link.active {
        background-color: #0d6efd;
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
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="d-flex flex-wrap align-items-center justify-content-between">
                            <div>
                                <h4 class="mb-1">Branch Stock Report</h4>
                                <p class="text-muted mb-0">
                                    View branch-wise product and vehicle stock details
                                    <?php if ($branchFilter > 0 && !empty($currentBranchName)): ?>
                                        <span class="badge bg-info ms-2">Filtered: <?php echo h($currentBranchName); ?></span>
                                    <?php endif; ?>
                                </p>
                            </div>
                            <div class="mt-3 mt-sm-0">
                                <a href="product-stock.php" class="btn btn-outline-primary me-2">
                                    <i class="ri-stack-line me-1"></i> Product Stock
                                </a>
                                <a href="vehicle-stock.php" class="btn btn-outline-secondary">
                                    <i class="ri-car-line me-1"></i> Vehicle Stock
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Summary Cards -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card summary-card bg-primary text-white" onclick="filterByStock('all')">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0 me-3">
                                        <i class="ri-store-line font-size-32"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="mb-1 opacity-75">Active Branches</p>
                                        <h3 class="mb-0"><?php echo number_format($totalBranches); ?></h3>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card summary-card bg-info text-white" onclick="filterByStock('positive')">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0 me-3">
                                        <i class="ri-stack-line font-size-32"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="mb-1 opacity-75">Total Products in Stock</p>
                                        <h3 class="mb-0"><?php echo qtyf($totalProductQuantity); ?></h3>
                                        <small class="opacity-75">(<?php echo number_format($totalProductStockRows); ?> items)</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card summary-card bg-success text-white" onclick="filterByStock('vehicle_in_stock')">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0 me-3">
                                        <i class="ri-car-line font-size-32"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="mb-1 opacity-75">Vehicles In Stock</p>
                                        <h3 class="mb-0"><?php echo number_format($totalVehiclesInStock); ?></h3>
                                        <small class="opacity-75">(<?php echo number_format($totalVehicleStockRows); ?> total)</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card summary-card bg-warning text-white" onclick="filterByStock('low')">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0 me-3">
                                        <i class="ri-alert-line font-size-32"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="mb-1 opacity-75">Low Stock Products</p>
                                        <h3 class="mb-0"><?php echo number_format($totalLowStockProducts); ?></h3>
                                        <small class="opacity-75">Out of Stock: <?php echo number_format($totalOutOfStockProducts); ?></small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filters Section -->
                <div class="card mb-4">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0">
                            <i class="ri-filter-line me-2"></i>Filter Stock Report
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="get" class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Search</label>
                                <input type="text" name="search" class="form-control" 
                                       placeholder="Branch, product, vehicle, code..." 
                                       value="<?php echo h($search); ?>">
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">Branch</label>
                                <select name="branch_id" class="form-select">
                                    <option value="0">All Branches</option>
                                    <?php foreach ($branchOptions as $b): ?>
                                        <option value="<?php echo (int)$b['id']; ?>" <?php echo ($branchFilter === (int)$b['id']) ? 'selected' : ''; ?>>
                                            <?php echo h($b['branch_name'] . ' (' . $b['branch_code'] . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">Stock Filter</label>
                                <select name="stock_type" class="form-select">
                                    <option value="">All Stock</option>
                                    <option value="positive" <?php echo ($stockType === 'positive') ? 'selected' : ''; ?>>Products In Stock</option>
                                    <option value="low" <?php echo ($stockType === 'low') ? 'selected' : ''; ?>>Low Stock Products</option>
                                    <option value="out" <?php echo ($stockType === 'out') ? 'selected' : ''; ?>>Out of Stock Products</option>
                                    <option value="vehicle_in_stock" <?php echo ($stockType === 'vehicle_in_stock') ? 'selected' : ''; ?>>Vehicles In Stock</option>
                                    <option value="vehicle_sold" <?php echo ($stockType === 'vehicle_sold') ? 'selected' : ''; ?>>Vehicles Sold</option>
                                    <option value="vehicle_reserved" <?php echo ($stockType === 'vehicle_reserved') ? 'selected' : ''; ?>>Vehicles Reserved</option>
                                </select>
                            </div>

                            <div class="col-md-2 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="ri-filter-line me-1"></i> Apply Filters
                                </button>
                            </div>
                            
                            <?php if ($search !== '' || $branchFilter > 0 || $stockType !== ''): ?>
                            <div class="col-12">
                                <a href="branch-stock-report.php" class="btn btn-sm btn-outline-secondary">
                                    <i class="ri-close-line me-1"></i> Clear All Filters
                                </a>
                            </div>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>

                <!-- Tab Navigation -->
                <ul class="nav nav-pills nav-fill mb-4" id="stockTab" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="branch-summary-tab" data-bs-toggle="tab" data-bs-target="#branch-summary" type="button" role="tab">
                            <i class="ri-store-line me-1"></i> Branch Summary
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="product-stock-tab" data-bs-toggle="tab" data-bs-target="#product-stock" type="button" role="tab">
                            <i class="ri-stack-line me-1"></i> Product Stock 
                            <span class="badge bg-secondary ms-1"><?php echo count($productStockRows); ?></span>
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="vehicle-stock-tab" data-bs-toggle="tab" data-bs-target="#vehicle-stock" type="button" role="tab">
                            <i class="ri-car-line me-1"></i> Vehicle Stock
                            <span class="badge bg-secondary ms-1"><?php echo count($vehicleStockRows); ?></span>
                        </button>
                    </li>
                </ul>

                <!-- Tab Content -->
                <div class="tab-content">
                    
                    <!-- Branch Summary Tab -->
                    <div class="tab-pane fade show active" id="branch-summary" role="tabpanel">
                        <div class="card">
                            <div class="card-header bg-light">
                                <h5 class="card-title mb-0">
                                    <i class="ri-store-line me-2"></i>Branch-wise Stock Summary
                                </h5>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-bordered table-striped align-middle mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th style="width: 40px;">#</th>
                                                <th>Branch Details</th>
                                                <th>Product Stock</th>
                                                <th>Product Qty</th>
                                                <th>Stock Value</th>
                                                <th>Low Stock</th>
                                                <th>Out of Stock</th>
                                                <th>Vehicle Stock</th>
                                                <th>In Stock</th>
                                                <th>Sold</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($branchSummaries)): ?>
                                                <?php $i = 1; foreach ($branchSummaries as $row): ?>
                                                    <tr>
                                                        <td><?php echo $i++; ?></td>
                                                        <td>
                                                            <div><strong><?php echo h($row['branch_name']); ?></strong></div>
                                                            <div class="small text-muted">Code: <?php echo h($row['branch_code'] ?: '-'); ?></div>
                                                            <?php if (!empty($row['contact_person'])): ?>
                                                                <div class="small text-muted">Contact: <?php echo h($row['contact_person']); ?></div>
                                                            <?php endif; ?>
                                                            <?php if (!empty($row['mobile'])): ?>
                                                                <div class="small text-muted">Mobile: <?php echo h($row['mobile']); ?></div>
                                                            <?php endif; ?>
                                                            <div class="small text-muted">
                                                                <?php
                                                                $loc = trim(
                                                                    ($row['city'] ?? '') .
                                                                    (($row['city'] ?? '') && ($row['district'] ?? '') ? ', ' : '') .
                                                                    ($row['district'] ?? '') .
                                                                    ((($row['city'] ?? '') || ($row['district'] ?? '')) && ($row['state'] ?? '') ? ', ' : '') .
                                                                    ($row['state'] ?? '')
                                                                );
                                                                echo h($loc ?: '-');
                                                                ?>
                                                            </div>
                                                            <?php if ((int)($row['is_head_office'] ?? 0) === 1): ?>
                                                                <span class="badge bg-primary mt-1">Head Office</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td><?php echo number_format((int)$row['product_stock_rows']); ?></td>
                                                        <td><?php echo qtyf($row['total_product_qty']); ?></td>
                                                        <td><?php echo money($row['total_stock_value']); ?></td>
                                                        <td>
                                                            <?php if ((int)$row['low_stock_products'] > 0): ?>
                                                                <span class="badge bg-warning"><?php echo number_format((int)$row['low_stock_products']); ?></span>
                                                            <?php else: ?>
                                                                <span class="text-muted">0</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <?php if ((int)$row['out_of_stock_products'] > 0): ?>
                                                                <span class="badge bg-danger"><?php echo number_format((int)$row['out_of_stock_products']); ?></span>
                                                            <?php else: ?>
                                                                <span class="text-muted">0</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td><?php echo number_format((int)$row['vehicle_stock_rows']); ?></td>
                                                        <td>
                                                            <span class="badge bg-success"><?php echo number_format((int)$row['vehicles_in_stock']); ?></span>
                                                            <?php if ((int)$row['vehicles_reserved'] > 0): ?>
                                                                <span class="badge bg-warning ms-1">R:<?php echo number_format((int)$row['vehicles_reserved']); ?></span>
                                                            <?php endif; ?>
                                                            <?php if ((int)$row['vehicles_demo'] > 0): ?>
                                                                <span class="badge bg-info ms-1">D:<?php echo number_format((int)$row['vehicles_demo']); ?></span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <span class="badge bg-secondary"><?php echo number_format((int)$row['vehicles_sold']); ?></span>
                                                        </td>
                                                        <td>
                                                            <?php if (($row['status'] ?? '') === 'active'): ?>
                                                                <span class="badge bg-success">Active</span>
                                                            <?php else: ?>
                                                                <span class="badge bg-danger">Inactive</span>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="11" class="text-center text-muted py-5">
                                                        <i class="ri-inbox-line font-size-48 mb-3 d-block"></i>
                                                        <h5>No branch stock summary found</h5>
                                                        <p class="mb-0">Try adjusting your search or filter criteria.</p>
                                                    </td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Product Stock Tab -->
                    <div class="tab-pane fade" id="product-stock" role="tabpanel">
                        <div class="card">
                            <div class="card-header bg-light d-flex justify-content-between align-items-center flex-wrap">
                                <h5 class="card-title mb-0">
                                    <i class="ri-stack-line me-2"></i>Branch-wise Product Stock Details
                                </h5>
                                <span class="badge bg-info mt-2 mt-sm-0"><?php echo count($productStockRows); ?> product stock records</span>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-bordered table-striped align-middle mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th style="width: 40px;">#</th>
                                                <th>Branch</th>
                                                <th>Product Details</th>
                                                <th>Category</th>
                                                <th>Available Qty</th>
                                                <th>Min Stock</th>
                                                <th>Last Purchase</th>
                                                <th>Selling Price</th>
                                                <th>Stock Value</th>
                                                <th>Updated</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($productStockRows)): ?>
                                                <?php $i = 1; foreach ($productStockRows as $row): ?>
                                                    <?php
                                                    $isLowStock = (float)$row['qty_available'] <= (float)$row['min_stock_qty'] && (float)$row['qty_available'] > 0;
                                                    $isOutOfStock = (float)$row['qty_available'] <= 0;
                                                    $rowClass = $isOutOfStock ? 'table-danger' : ($isLowStock ? 'table-warning' : '');
                                                    ?>
                                                    <tr class="<?php echo $rowClass; ?>">
                                                        <td><?php echo $i++; ?></td>
                                                        <td>
                                                            <div><strong><?php echo h($row['branch_name']); ?></strong></div>
                                                            <div class="small text-muted"><?php echo h($row['branch_code'] ?: '-'); ?></div>
                                                        </td>
                                                        <td>
                                                            <div><strong><?php echo h($row['product_name']); ?></strong></div>
                                                            <div class="small text-muted">Code: <?php echo h($row['product_code'] ?: '-'); ?></div>
                                                            <div class="small text-muted">Item: <?php echo h($row['item_code'] ?: '-'); ?></div>
                                                            <div class="small text-muted">Brand: <?php echo h($row['brand_name'] ?: '-'); ?></div>
                                                            <?php if (!empty($row['part_number'])): ?>
                                                                <div class="small text-muted">Part: <?php echo h($row['part_number']); ?></div>
                                                            <?php endif; ?>
                                                            <?php if (!empty($row['hsn_code'])): ?>
                                                                <div class="small text-muted">HSN: <?php echo h($row['hsn_code']); ?></div>
                                                            <?php endif; ?>
                                                            <?php if ($row['gst_percent'] > 0): ?>
                                                                <div class="small text-muted">GST: <?php echo qtyf($row['gst_percent']); ?>%</div>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td><?php echo h($row['category_name'] ?: '-'); ?></td>
                                                        <td>
                                                            <strong class="<?php echo $isLowStock ? 'text-warning' : ($isOutOfStock ? 'text-danger' : 'text-success'); ?>">
                                                                <?php echo qtyf($row['qty_available']); ?>
                                                            </strong>
                                                            <?php echo h($row['unit'] ?: 'pcs'); ?>
                                                            <?php if ($isLowStock): ?>
                                                                <div><span class="badge bg-warning stock-badge mt-1">Low Stock</span></div>
                                                            <?php elseif ($isOutOfStock): ?>
                                                                <div><span class="badge bg-danger stock-badge mt-1">Out of Stock</span></div>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <?php echo qtyf($row['min_stock_qty']); ?>
                                                            <?php echo h($row['unit'] ?: 'pcs'); ?>
                                                        </td>
                                                        <td>
                                                            <?php echo money($row['last_purchase_price']); ?>
                                                            <div class="small text-muted">Purchase: <?php echo money($row['purchase_price']); ?></div>
                                                        </td>
                                                        <td>
                                                            <?php echo money($row['selling_price']); ?>
                                                            <?php if ($row['mrp'] > 0): ?>
                                                                <div class="small text-muted">MRP: <?php echo money($row['mrp']); ?></div>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <strong class="text-primary"><?php echo money($row['stock_value']); ?></strong>
                                                            <div class="small text-muted">
                                                                <?php echo qtyf($row['qty_available']); ?> × <?php echo money($row['last_purchase_price'] ?: $row['purchase_price']); ?>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <?php if (!empty($row['updated_at'])): ?>
                                                                <?php echo h(date('d M Y h:i A', strtotime($row['updated_at']))); ?>
                                                            <?php else: ?>
                                                                -
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="10" class="text-center text-muted py-5">
                                                        <i class="ri-inbox-line font-size-48 mb-3 d-block"></i>
                                                        <h5>No product stock records found</h5>
                                                        <p class="mb-0">Try adjusting your search or filter criteria.</p>
                                                    </td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Vehicle Stock Tab -->
                    <div class="tab-pane fade" id="vehicle-stock" role="tabpanel">
                        <div class="card">
                            <div class="card-header bg-light d-flex justify-content-between align-items-center flex-wrap">
                                <h5 class="card-title mb-0">
                                    <i class="ri-car-line me-2"></i>Branch-wise Vehicle Stock Details
                                </h5>
                                <span class="badge bg-info mt-2 mt-sm-0"><?php echo count($vehicleStockRows); ?> vehicle stock records</span>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-bordered table-striped align-middle mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th style="width: 40px;">#</th>
                                                <th>Branch</th>
                                                <th>Vehicle Details</th>
                                                <th>Chassis / Engine</th>
                                                <th>Battery Info</th>
                                                <th>Charger Info</th>
                                                <th>Purchase Cost</th>
                                                <th>Sale Price</th>
                                                <th>Status</th>
                                                <th>Created</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($vehicleStockRows)): ?>
                                                <?php $i = 1; foreach ($vehicleStockRows as $row): ?>
                                                    <?php
                                                    $statusBadge = 'secondary';
                                                    $statusClass = '';
                                                    if ($row['stock_status'] === 'in_stock') {
                                                        $statusBadge = 'success';
                                                        $statusClass = 'table-success';
                                                    } elseif ($row['stock_status'] === 'reserved') {
                                                        $statusBadge = 'warning';
                                                        $statusClass = 'table-warning';
                                                    } elseif ($row['stock_status'] === 'sold') {
                                                        $statusBadge = 'danger';
                                                        $statusClass = 'table-danger';
                                                    } elseif ($row['stock_status'] === 'demo') {
                                                        $statusBadge = 'info';
                                                    } elseif ($row['stock_status'] === 'transferred') {
                                                        $statusBadge = 'primary';
                                                    }
                                                    ?>
                                                    <tr class="<?php echo $statusClass; ?>">
                                                        <td><?php echo $i++; ?></td>
                                                        <td>
                                                            <div><strong><?php echo h($row['branch_name']); ?></strong></div>
                                                            <div class="small text-muted"><?php echo h($row['branch_code'] ?: '-'); ?></div>
                                                        </td>
                                                        <td>
                                                            <div><strong><?php echo h($row['brand_name'] ?: '-'); ?></strong></div>
                                                            <div><strong><?php echo h($row['model_name'] ?: '-'); ?></strong></div>
                                                            <div class="small text-muted"><?php echo h($row['variant_name'] ?: '-'); ?></div>
                                                            <div class="small text-muted">Color: <?php echo h($row['color'] ?: '-'); ?></div>
                                                            <?php if (!empty($row['motor_power'])): ?>
                                                                <div class="small text-muted">Motor: <?php echo h($row['motor_power']); ?></div>
                                                            <?php endif; ?>
                                                            <?php if (!empty($row['range_km'])): ?>
                                                                <div class="small text-muted">Range: <?php echo h($row['range_km']); ?></div>
                                                            <?php endif; ?>
                                                            <?php if (!empty($row['key_no'])): ?>
                                                                <div class="small text-muted">Key No: <?php echo h($row['key_no']); ?></div>
                                                            <?php endif; ?>
                                                            <?php if (!empty($row['manufacture_year'])): ?>
                                                                <div class="small text-muted">Year: <?php echo h($row['manufacture_year']); ?></div>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td class="small">
                                                            <div><strong>Chassis:</strong> <?php echo h($row['chassis_no'] ?: '-'); ?></div>
                                                            <div><strong>Engine:</strong> <?php echo h($row['engine_no'] ?: '-'); ?></div>
                                                            <div><strong>Motor:</strong> <?php echo h($row['motor_no'] ?: '-'); ?></div>
                                                        </td>
                                                        <td class="small">
                                                            <div><strong>Brand:</strong> <?php echo h($row['battery_brand'] ?: '-'); ?></div>
                                                            <div><strong>No:</strong> <?php echo h($row['battery_no'] ?: '-'); ?></div>
                                                            <div><strong>Capacity:</strong> <?php echo h($row['battery_capacity'] ?: $row['model_battery_capacity']); ?></div>
                                                            <?php if (!empty($row['battery_warranty_upto'])): ?>
                                                                <div><strong>Warranty:</strong> <?php echo h(date('d M Y', strtotime($row['battery_warranty_upto']))); ?></div>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td class="small">
                                                            <div><strong>Brand:</strong> <?php echo h($row['charger_brand'] ?: '-'); ?></div>
                                                            <div><strong>No:</strong> <?php echo h($row['charger_no'] ?: '-'); ?></div>
                                                            <div><strong>Type:</strong> <?php echo h($row['charger_type'] ?: '-'); ?></div>
                                                            <?php if (!empty($row['charger_warranty_upto'])): ?>
                                                                <div><strong>Warranty:</strong> <?php echo h(date('d M Y', strtotime($row['charger_warranty_upto']))); ?></div>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td><?php echo money($row['purchase_cost']); ?></td>
                                                        <td><?php echo money($row['sale_price']); ?></td>
                                                        <td>
                                                            <span class="badge bg-<?php echo $statusBadge; ?>">
                                                                <?php echo h(ucwords(str_replace('_', ' ', $row['stock_status']))); ?>
                                                            </span>
                                                            <?php if (!empty($row['remarks'])): ?>
                                                                <div class="small text-muted mt-1"><?php echo h($row['remarks']); ?></div>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <?php if (!empty($row['created_at'])): ?>
                                                                <?php echo h(date('d M Y', strtotime($row['created_at']))); ?>
                                                                <?php if (!empty($row['purchase_date'])): ?>
                                                                    <div class="small text-muted">Purchased: <?php echo h(date('d M Y', strtotime($row['purchase_date']))); ?></div>
                                                                <?php endif; ?>
                                                            <?php else: ?>
                                                                -
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="10" class="text-center text-muted py-5">
                                                        <i class="ri-inbox-line font-size-48 mb-3 d-block"></i>
                                                        <h5>No vehicle stock records found</h5>
                                                        <p class="mb-0">Try adjusting your search or filter criteria.</p>
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

            </div>
        </div>

        <?php include('includes/footer.php'); ?>
    </div>
</div>

<?php include('includes/rightbar.php'); ?>
<?php include('includes/scripts.php'); ?>

<script>
function filterByStock(type) {
    var url = new URL(window.location.href);
    
    if (type === 'all') {
        url.searchParams.delete('stock_type');
        url.searchParams.delete('branch_id');
    } else {
        url.searchParams.set('stock_type', type);
        if (type === 'positive' || type === 'low' || type === 'out') {
            // Switch to product stock tab
            var productTab = document.querySelector('#product-stock-tab');
            if (productTab) {
                setTimeout(function() {
                    var tab = new bootstrap.Tab(productTab);
                    tab.show();
                }, 100);
            }
        } else if (type === 'vehicle_in_stock' || type === 'vehicle_sold' || type === 'vehicle_reserved') {
            // Switch to vehicle stock tab
            var vehicleTab = document.querySelector('#vehicle-stock-tab');
            if (vehicleTab) {
                setTimeout(function() {
                    var tab = new bootstrap.Tab(vehicleTab);
                    tab.show();
                }, 100);
            }
        }
    }
    
    window.location.href = url.toString();
}

// Preserve active tab after form submission
document.addEventListener('DOMContentLoaded', function() {
    var hash = window.location.hash;
    if (hash === '#product-stock') {
        var productTab = document.querySelector('#product-stock-tab');
        if (productTab) {
            var tab = new bootstrap.Tab(productTab);
            tab.show();
        }
    } else if (hash === '#vehicle-stock') {
        var vehicleTab = document.querySelector('#vehicle-stock-tab');
        if (vehicleTab) {
            var tab = new bootstrap.Tab(vehicleTab);
            tab.show();
        }
    }
    
    // Update URL hash when tab changes
    var tabs = document.querySelectorAll('button[data-bs-toggle="tab"]');
    tabs.forEach(function(tab) {
        tab.addEventListener('shown.bs.tab', function(e) {
            var targetId = e.target.getAttribute('data-bs-target');
            if (targetId === '#product-stock') {
                window.location.hash = 'product-stock';
            } else if (targetId === '#vehicle-stock') {
                window.location.hash = 'vehicle-stock';
            } else {
                window.location.hash = '';
            }
        });
    });
});
</script>

</body>
</html>