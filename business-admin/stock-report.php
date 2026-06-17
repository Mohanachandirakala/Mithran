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

function fetchOne(mysqli $conn, string $sql): array
{
    $res = $conn->query($sql);
    if (!$res) return [];
    $row = $res->fetch_assoc();
    $res->free();
    return $row ?: [];
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

$hasProducts = tableExists($conn, 'products');
$hasProductStock = tableExists($conn, 'product_stock');
$hasProductCategories = tableExists($conn, 'product_categories');
$hasVehicleStock = tableExists($conn, 'vehicle_stock');
$hasVehicleModels = tableExists($conn, 'vehicle_models');
$hasVehicleBrands = tableExists($conn, 'vehicle_brands');
$hasVehicleCategories = tableExists($conn, 'vehicle_categories');

/* -------------------------------------------------------
   MASTER DATA
------------------------------------------------------- */
$branches = fetchAllAssoc($conn, "
    SELECT id, branch_name, branch_code
    FROM branches
    WHERE business_id = {$businessId}
    ORDER BY branch_name ASC
");

/* -------------------------------------------------------
   FILTERS
------------------------------------------------------- */
$search = trim($_GET['search'] ?? '');
$branchFilter = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : 0;
$productTypeFilter = trim($_GET['product_type'] ?? '');
$stockStatusFilter = trim($_GET['stock_status'] ?? '');
$stockView = trim($_GET['stock_view'] ?? 'all');

$allowedProductTypes = ['spare_part', 'accessory', 'consumable', 'lubricant', 'battery', 'tyre', 'helmet', 'other'];
$allowedVehicleStockStatuses = ['in_stock', 'reserved', 'sold', 'demo', 'transferred'];
$allowedStockViews = ['all', 'products', 'vehicles', 'low_stock'];

if (!in_array($stockView, $allowedStockViews, true)) {
    $stockView = 'all';
}

/* -------------------------------------------------------
   PRODUCT SUMMARY - CORRECT STOCK CALCULATION
------------------------------------------------------- */
$totalProducts = 0;
$totalProductQty = 0.00;
$totalProductStockValue = 0.00;
$lowStockProducts = 0;

if ($hasProducts) {
    if ($hasProductStock) {
        $productSummaryWhere = "p.business_id = {$businessId}";
        $stockJoinBranch = "";

        if ($branchFilter > 0) {
            $stockJoinBranch = "AND ps.branch_id = {$branchFilter}";
        }

        $summary = fetchOne($conn, "
            SELECT
                COUNT(DISTINCT p.id) AS total_products,
                COALESCE(SUM(COALESCE(pss.total_qty, p.stock_qty)), 0) AS total_qty,
                COALESCE(SUM(COALESCE(pss.total_qty, p.stock_qty) * COALESCE(pss.last_price, p.purchase_price)), 0) AS stock_value,
                COALESCE(SUM(
                    CASE 
                        WHEN p.min_stock_qty > 0 
                        AND COALESCE(pss.total_qty, p.stock_qty) <= p.min_stock_qty 
                        THEN 1 ELSE 0 
                    END
                ), 0) AS low_stock
            FROM products p
            LEFT JOIN (
                SELECT
                    ps.product_id,
                    SUM(ps.qty_available) AS total_qty,
                    MAX(ps.last_purchase_price) AS last_price
                FROM product_stock ps
                WHERE ps.business_id = {$businessId}
                " . ($branchFilter > 0 ? "AND ps.branch_id = {$branchFilter}" : "") . "
                GROUP BY ps.product_id
            ) pss ON pss.product_id = p.id
            WHERE {$productSummaryWhere}
        ");

        $totalProducts = (int)($summary['total_products'] ?? 0);
        $totalProductQty = (float)($summary['total_qty'] ?? 0);
        $totalProductStockValue = (float)($summary['stock_value'] ?? 0);
        $lowStockProducts = (int)($summary['low_stock'] ?? 0);
    } else {
        $summary = fetchOne($conn, "
            SELECT
                COUNT(*) AS total_products,
                COALESCE(SUM(stock_qty), 0) AS total_qty,
                COALESCE(SUM(stock_qty * purchase_price), 0) AS stock_value,
                COALESCE(SUM(CASE WHEN min_stock_qty > 0 AND stock_qty <= min_stock_qty THEN 1 ELSE 0 END), 0) AS low_stock
            FROM products
            WHERE business_id = {$businessId}
        ");

        $totalProducts = (int)($summary['total_products'] ?? 0);
        $totalProductQty = (float)($summary['total_qty'] ?? 0);
        $totalProductStockValue = (float)($summary['stock_value'] ?? 0);
        $lowStockProducts = (int)($summary['low_stock'] ?? 0);
    }
}

/* -------------------------------------------------------
   VEHICLE SUMMARY
------------------------------------------------------- */
$totalVehicles = 0;
$inStockVehicles = 0;
$reservedVehicles = 0;
$soldVehicles = 0;
$vehicleStockValue = 0.00;

if ($hasVehicleStock) {
    $vehicleSummaryWhere = "business_id = {$businessId}";
    if ($branchFilter > 0) {
        $vehicleSummaryWhere .= " AND branch_id = {$branchFilter}";
    }

    $vsum = fetchOne($conn, "
        SELECT
            COUNT(*) AS total_vehicles,
            COALESCE(SUM(CASE WHEN stock_status = 'in_stock' THEN 1 ELSE 0 END), 0) AS in_stock,
            COALESCE(SUM(CASE WHEN stock_status = 'reserved' THEN 1 ELSE 0 END), 0) AS reserved,
            COALESCE(SUM(CASE WHEN stock_status = 'sold' THEN 1 ELSE 0 END), 0) AS sold,
            COALESCE(SUM(CASE WHEN stock_status IN ('in_stock','reserved','demo') THEN purchase_cost ELSE 0 END), 0) AS stock_value
        FROM vehicle_stock
        WHERE {$vehicleSummaryWhere}
    ");

    $totalVehicles = (int)($vsum['total_vehicles'] ?? 0);
    $inStockVehicles = (int)($vsum['in_stock'] ?? 0);
    $reservedVehicles = (int)($vsum['reserved'] ?? 0);
    $soldVehicles = (int)($vsum['sold'] ?? 0);
    $vehicleStockValue = (float)($vsum['stock_value'] ?? 0);
}

$totalBranches = (int)(fetchOne($conn, "SELECT COUNT(*) AS total FROM branches WHERE business_id = {$businessId}")['total'] ?? 0);

/* -------------------------------------------------------
   PRODUCT STOCK REPORT
------------------------------------------------------- */
$productRows = [];

if ($hasProducts) {
    $productWhere = ["p.business_id = {$businessId}"];

    if ($search !== '') {
        $safe = $conn->real_escape_string($search);
        $productWhere[] = "(
            p.product_name LIKE '%{$safe}%'
            OR p.product_code LIKE '%{$safe}%'
            OR p.brand_name LIKE '%{$safe}%'
            OR p.part_number LIKE '%{$safe}%'
            OR p.item_code LIKE '%{$safe}%'
            " . ($hasProductCategories ? "OR pc.category_name LIKE '%{$safe}%'" : "") . "
        )";
    }

    if ($productTypeFilter !== '' && in_array($productTypeFilter, $allowedProductTypes, true)) {
        $safe = $conn->real_escape_string($productTypeFilter);
        $productWhere[] = "p.product_type = '{$safe}'";
    }

    if ($stockView === 'low_stock') {
        $productWhere[] = "p.min_stock_qty > 0 AND COALESCE(pss.total_qty, p.stock_qty) <= p.min_stock_qty";
    }

    $productWhereSql = implode(' AND ', $productWhere);

    if ($hasProductStock) {
        $branchNameSelect = "NULL AS branch_name, NULL AS branch_code";
        $stockSubQueryBranch = "";

        if ($branchFilter > 0) {
            $stockSubQueryBranch = "AND ps.branch_id = {$branchFilter}";
            $branchNameSelect = "br.branch_name, br.branch_code";
        }

        $productRows = fetchAllAssoc($conn, "
            SELECT
                p.id,
                p.product_name,
                p.product_code,
                p.brand_name,
                p.part_number,
                p.item_code,
                p.hsn_code,
                p.product_type,
                p.unit,
                p.unit_name,
                p.purchase_price,
                p.mrp,
                p.selling_price,
                p.gst_percent,
                p.stock_qty,
                p.min_stock_qty,
                p.status,
                p.created_at,
                " . ($hasProductCategories ? "pc.category_name" : "NULL AS category_name") . ",
                COALESCE(pss.total_qty, p.stock_qty) AS qty_available,
                COALESCE(pss.last_price, p.purchase_price) AS last_purchase_price,
                pss.last_updated AS stock_updated_at,
                {$branchNameSelect}
            FROM products p
            " . ($hasProductCategories ? "LEFT JOIN product_categories pc ON pc.id = p.category_id" : "") . "
            LEFT JOIN (
                SELECT
                    ps.product_id,
                    " . ($branchFilter > 0 ? "MAX(ps.branch_id) AS branch_id," : "NULL AS branch_id,") . "
                    SUM(ps.qty_available) AS total_qty,
                    MAX(ps.last_purchase_price) AS last_price,
                    MAX(ps.updated_at) AS last_updated
                FROM product_stock ps
                WHERE ps.business_id = {$businessId}
                {$stockSubQueryBranch}
                GROUP BY ps.product_id
            ) pss ON pss.product_id = p.id
            " . ($branchFilter > 0 ? "LEFT JOIN branches br ON br.id = pss.branch_id" : "") . "
            WHERE {$productWhereSql}
            ORDER BY p.id DESC
            LIMIT 500
        ");
    } else {
        $productRows = fetchAllAssoc($conn, "
            SELECT
                p.id,
                p.product_name,
                p.product_code,
                p.brand_name,
                p.part_number,
                p.item_code,
                p.hsn_code,
                p.product_type,
                p.unit,
                p.unit_name,
                p.purchase_price,
                p.mrp,
                p.selling_price,
                p.gst_percent,
                p.stock_qty,
                p.min_stock_qty,
                p.status,
                p.created_at,
                " . ($hasProductCategories ? "pc.category_name" : "NULL AS category_name") . ",
                p.stock_qty AS qty_available,
                p.purchase_price AS last_purchase_price,
                NULL AS stock_updated_at,
                NULL AS branch_name,
                NULL AS branch_code
            FROM products p
            " . ($hasProductCategories ? "LEFT JOIN product_categories pc ON pc.id = p.category_id" : "") . "
            WHERE {$productWhereSql}
            ORDER BY p.id DESC
            LIMIT 500
        ");
    }
}

/* -------------------------------------------------------
   VEHICLE STOCK REPORT
------------------------------------------------------- */
$vehicleRows = [];

if ($hasVehicleStock) {
    $vehicleWhere = ["vs.business_id = {$businessId}"];

    if ($search !== '') {
        $safe = $conn->real_escape_string($search);
        $vehicleWhere[] = "(
            vs.chassis_no LIKE '%{$safe}%'
            OR vs.engine_no LIKE '%{$safe}%'
            OR vs.motor_no LIKE '%{$safe}%'
            OR vs.battery_no LIKE '%{$safe}%'
            OR vs.charger_no LIKE '%{$safe}%'
            OR vs.key_no LIKE '%{$safe}%'
            OR vs.color LIKE '%{$safe}%'
            OR br.branch_name LIKE '%{$safe}%'
            " . ($hasVehicleModels ? "OR vm.model_name LIKE '%{$safe}%' OR vm.variant_name LIKE '%{$safe}%'" : "") . "
            " . ($hasVehicleBrands ? "OR vb.brand_name LIKE '%{$safe}%'" : "") . "
            " . ($hasVehicleCategories ? "OR vc.category_name LIKE '%{$safe}%'" : "") . "
        )";
    }

    if ($branchFilter > 0) {
        $vehicleWhere[] = "vs.branch_id = {$branchFilter}";
    }

    if ($stockStatusFilter !== '' && in_array($stockStatusFilter, $allowedVehicleStockStatuses, true)) {
        $safe = $conn->real_escape_string($stockStatusFilter);
        $vehicleWhere[] = "vs.stock_status = '{$safe}'";
    }

    $vehicleWhereSql = implode(' AND ', $vehicleWhere);

    $vehicleRows = fetchAllAssoc($conn, "
        SELECT
            vs.*,
            br.branch_name,
            br.branch_code,
            " . ($hasVehicleModels ? "vm.model_name, vm.variant_name, vm.vehicle_type, vm.ex_showroom_price" : "NULL AS model_name, NULL AS variant_name, NULL AS vehicle_type, 0 AS ex_showroom_price") . ",
            " . ($hasVehicleBrands ? "vb.brand_name" : "NULL AS brand_name") . ",
            " . ($hasVehicleCategories ? "vc.category_name" : "NULL AS category_name") . "
        FROM vehicle_stock vs
        LEFT JOIN branches br ON br.id = vs.branch_id
        " . ($hasVehicleModels ? "LEFT JOIN vehicle_models vm ON vm.id = vs.model_id" : "") . "
        " . ($hasVehicleModels && $hasVehicleBrands ? "LEFT JOIN vehicle_brands vb ON vb.id = vm.brand_id" : "") . "
        " . ($hasVehicleModels && $hasVehicleCategories ? "LEFT JOIN vehicle_categories vc ON vc.id = vm.category_id" : "") . "
        WHERE {$vehicleWhereSql}
        ORDER BY vs.id DESC
        LIMIT 500
    ");
}

$pageTitle = 'Stock Report';
$currentPage = 'stock-report';
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
                        <h4 class="mb-1">Stock Report</h4>
                        <p class="text-muted mb-0">Product stock, vehicle stock, low stock items and stock value summary</p>
                    </div>
                    <div class="col-md-5 text-md-end mt-3 mt-md-0">
                        <a href="product-add.php" class="btn btn-primary me-2">Add Product</a>
                        <a href="vehicle-stock.php" class="btn btn-secondary">Vehicle Stock</a>
                    </div>
                </div>

                <!-- SUMMARY -->
                <div class="row">
                    <div class="col-md-2">
                        <div class="card"><div class="card-body text-center">
                            <p class="text-muted mb-1">Products</p>
                            <h4 class="mb-0"><?php echo number_format($totalProducts); ?></h4>
                        </div></div>
                    </div>

                    <div class="col-md-2">
                        <div class="card"><div class="card-body text-center">
                            <p class="text-muted mb-1">Product Qty</p>
                            <h4 class="mb-0 text-primary"><?php echo qtyf($totalProductQty); ?></h4>
                        </div></div>
                    </div>

                    <div class="col-md-2">
                        <div class="card"><div class="card-body text-center">
                            <p class="text-muted mb-1">Low Stock</p>
                            <h4 class="mb-0 text-danger"><?php echo number_format($lowStockProducts); ?></h4>
                        </div></div>
                    </div>

                    <div class="col-md-2">
                        <div class="card"><div class="card-body text-center">
                            <p class="text-muted mb-1">Vehicles</p>
                            <h4 class="mb-0 text-success"><?php echo number_format($totalVehicles); ?></h4>
                        </div></div>
                    </div>

                    <div class="col-md-2">
                        <div class="card"><div class="card-body text-center">
                            <p class="text-muted mb-1">Vehicle In Stock</p>
                            <h4 class="mb-0 text-info"><?php echo number_format($inStockVehicles); ?></h4>
                        </div></div>
                    </div>

                    <div class="col-md-2">
                        <div class="card"><div class="card-body text-center">
                            <p class="text-muted mb-1">Branches</p>
                            <h4 class="mb-0"><?php echo number_format($totalBranches); ?></h4>
                        </div></div>
                    </div>
                </div>

                <!-- VALUE SUMMARY -->
                <div class="row">
                    <div class="col-md-4">
                        <div class="card"><div class="card-body text-center">
                            <p class="text-muted mb-1">Product Stock Value</p>
                            <h4 class="mb-0 text-primary"><?php echo money($totalProductStockValue); ?></h4>
                            <small class="text-muted">Qty × Purchase Price</small>
                        </div></div>
                    </div>

                    <div class="col-md-4">
                        <div class="card"><div class="card-body text-center">
                            <p class="text-muted mb-1">Vehicle Stock Value</p>
                            <h4 class="mb-0 text-success"><?php echo money($vehicleStockValue); ?></h4>
                            <small class="text-muted">Only in stock / reserved / demo</small>
                        </div></div>
                    </div>

                    <div class="col-md-2">
                        <div class="card"><div class="card-body text-center">
                            <p class="text-muted mb-1">Reserved</p>
                            <h4 class="mb-0 text-warning"><?php echo number_format($reservedVehicles); ?></h4>
                        </div></div>
                    </div>

                    <div class="col-md-2">
                        <div class="card"><div class="card-body text-center">
                            <p class="text-muted mb-1">Sold</p>
                            <h4 class="mb-0 text-dark"><?php echo number_format($soldVehicles); ?></h4>
                        </div></div>
                    </div>
                </div>

                <!-- FILTERS -->
                <div class="card">
                    <div class="card-body">
                        <form method="get" class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label">Search</label>
                                <input type="text" name="search" class="form-control" placeholder="Product, code, chassis, model..." value="<?php echo h($search); ?>">
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
                                <label class="form-label">Product Type</label>
                                <select name="product_type" class="form-select">
                                    <option value="">All Types</option>
                                    <?php foreach ($allowedProductTypes as $ptype): ?>
                                        <option value="<?php echo h($ptype); ?>" <?php echo ($productTypeFilter === $ptype) ? 'selected' : ''; ?>>
                                            <?php echo h(ucwords(str_replace('_', ' ', $ptype))); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">Vehicle Stock Status</label>
                                <select name="stock_status" class="form-select">
                                    <option value="">All Status</option>
                                    <?php foreach ($allowedVehicleStockStatuses as $sstatus): ?>
                                        <option value="<?php echo h($sstatus); ?>" <?php echo ($stockStatusFilter === $sstatus) ? 'selected' : ''; ?>>
                                            <?php echo h(ucwords(str_replace('_', ' ', $sstatus))); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">View</label>
                                <select name="stock_view" class="form-select">
                                    <option value="all" <?php echo ($stockView === 'all') ? 'selected' : ''; ?>>All</option>
                                    <option value="products" <?php echo ($stockView === 'products') ? 'selected' : ''; ?>>Products</option>
                                    <option value="vehicles" <?php echo ($stockView === 'vehicles') ? 'selected' : ''; ?>>Vehicles</option>
                                    <option value="low_stock" <?php echo ($stockView === 'low_stock') ? 'selected' : ''; ?>>Low Stock</option>
                                </select>
                            </div>

                            <div class="col-md-1 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary w-100">Go</button>
                            </div>

                            <div class="col-md-12">
                                <a href="stock-report.php" class="btn btn-light">Reset</a>
                            </div>
                        </form>
                    </div>
                </div>

                <?php if ($stockView === 'all' || $stockView === 'products' || $stockView === 'low_stock'): ?>
                <!-- PRODUCT STOCK REPORT -->
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4">Product Stock Report</h4>

                        <div class="table-responsive">
                            <table class="table table-bordered table-striped align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Product</th>
                                        <th>Category / Type</th>
                                        <th>Branch</th>
                                        <th>Codes</th>
                                        <th>Pricing</th>
                                        <th>Stock</th>
                                        <th>Tax</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($productRows)): ?>
                                        <?php $i = 1; foreach ($productRows as $row): ?>
                                            <?php
                                            $qtyAvailable = (float)($row['qty_available'] ?? 0);
                                            $minStockQty = (float)($row['min_stock_qty'] ?? 0);
                                            $purchasePrice = (float)($row['last_purchase_price'] ?? $row['purchase_price'] ?? 0);
                                            $isLowStock = ($minStockQty > 0 && $qtyAvailable <= $minStockQty);
                                            $isOutStock = ($qtyAvailable <= 0);
                                            ?>
                                            <tr>
                                                <td><?php echo $i++; ?></td>

                                                <td class="small">
                                                    <div><strong><?php echo h($row['product_name'] ?: '-'); ?></strong></div>
                                                    <div class="text-muted"><?php echo h($row['brand_name'] ?: '-'); ?></div>
                                                </td>

                                                <td class="small">
                                                    <div><?php echo h($row['category_name'] ?: '-'); ?></div>
                                                    <div class="text-muted"><?php echo h(ucwords(str_replace('_', ' ', (string)($row['product_type'] ?? '-')))); ?></div>
                                                </td>

                                                <td class="small">
                                                    <?php if ($branchFilter > 0): ?>
                                                        <div><?php echo h($row['branch_name'] ?: '-'); ?></div>
                                                        <div class="text-muted"><?php echo h($row['branch_code'] ?: '-'); ?></div>
                                                    <?php else: ?>
                                                        <div>All Branches</div>
                                                        <div class="text-muted">Combined Stock</div>
                                                    <?php endif; ?>
                                                </td>

                                                <td class="small">
                                                    <div><strong>Product Code:</strong> <?php echo h($row['product_code'] ?: '-'); ?></div>
                                                    <div><strong>Item Code:</strong> <?php echo h($row['item_code'] ?: '-'); ?></div>
                                                    <div><strong>Part No:</strong> <?php echo h($row['part_number'] ?: '-'); ?></div>
                                                    <div><strong>HSN:</strong> <?php echo h($row['hsn_code'] ?: '-'); ?></div>
                                                </td>

                                                <td class="small">
                                                    <div><strong>Purchase:</strong> <?php echo money($row['purchase_price']); ?></div>
                                                    <div><strong>Last Purchase:</strong> <?php echo money($purchasePrice); ?></div>
                                                    <div><strong>MRP:</strong> <?php echo money($row['mrp']); ?></div>
                                                    <div><strong>Selling:</strong> <?php echo money($row['selling_price']); ?></div>
                                                </td>

                                                <td class="small">
                                                    <div><strong>Qty:</strong> <?php echo qtyf($qtyAvailable); ?> <?php echo h($row['unit'] ?: 'pcs'); ?></div>
                                                    <div><strong>Min:</strong> <?php echo qtyf($minStockQty); ?></div>
                                                    <div><strong>Stock Value:</strong> <?php echo money($qtyAvailable * $purchasePrice); ?></div>

                                                    <?php if ($isOutStock): ?>
                                                        <span class="badge bg-dark mt-1">Out of Stock</span>
                                                    <?php elseif ($isLowStock): ?>
                                                        <span class="badge bg-danger mt-1">Low Stock</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-success mt-1">Available</span>
                                                    <?php endif; ?>
                                                </td>

                                                <td><?php echo number_format((float)($row['gst_percent'] ?? 0), 2); ?>%</td>

                                                <td>
                                                    <?php if ((int)($row['status'] ?? 0) === 1): ?>
                                                        <span class="badge bg-success">Active</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-danger">Inactive</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="9" class="text-center text-muted py-4">No product stock records found.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="mt-3 text-muted small">
                            Showing latest 500 product records.
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($stockView === 'all' || $stockView === 'vehicles'): ?>
                <!-- VEHICLE STOCK REPORT -->
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4">Vehicle Stock Report</h4>

                        <div class="table-responsive">
                            <table class="table table-bordered table-striped align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Vehicle</th>
                                        <th>Branch</th>
                                        <th>Stock Details</th>
                                        <th>Battery / Charger</th>
                                        <th>Purchase / Sale</th>
                                        <th>Status</th>
                                        <th>Supplier</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($vehicleRows)): ?>
                                        <?php $j = 1; foreach ($vehicleRows as $row): ?>
                                            <?php
                                            $statusBadge = 'secondary';
                                            if (($row['stock_status'] ?? '') === 'in_stock') $statusBadge = 'success';
                                            elseif (($row['stock_status'] ?? '') === 'reserved') $statusBadge = 'warning';
                                            elseif (($row['stock_status'] ?? '') === 'sold') $statusBadge = 'danger';
                                            elseif (($row['stock_status'] ?? '') === 'demo') $statusBadge = 'info';
                                            elseif (($row['stock_status'] ?? '') === 'transferred') $statusBadge = 'dark';
                                            ?>
                                            <tr>
                                                <td><?php echo $j++; ?></td>

                                                <td class="small">
                                                    <div><strong><?php echo h(($row['brand_name'] ?: 'Vehicle') . ' - ' . ($row['model_name'] ?: '-')); ?></strong></div>
                                                    <div><?php echo h($row['variant_name'] ?: '-'); ?></div>
                                                    <div><?php echo h($row['category_name'] ?: '-'); ?></div>
                                                    <div class="text-muted"><?php echo h(ucfirst((string)($row['vehicle_type'] ?? '-'))); ?></div>
                                                </td>

                                                <td class="small">
                                                    <div><?php echo h($row['branch_name'] ?: '-'); ?></div>
                                                    <div class="text-muted"><?php echo h($row['branch_code'] ?: '-'); ?></div>
                                                </td>

                                                <td class="small">
                                                    <div><strong>Chassis:</strong> <?php echo h($row['chassis_no'] ?: '-'); ?></div>
                                                    <div><strong>Engine:</strong> <?php echo h($row['engine_no'] ?: '-'); ?></div>
                                                    <div><strong>Motor:</strong> <?php echo h($row['motor_no'] ?: '-'); ?></div>
                                                    <div><strong>VIN:</strong> <?php echo h($row['vin_no'] ?: '-'); ?></div>
                                                    <div><strong>Color:</strong> <?php echo h($row['color'] ?: '-'); ?></div>
                                                    <div><strong>Year:</strong> <?php echo h($row['manufacture_year'] ?: '-'); ?></div>
                                                    <div><strong>Key No:</strong> <?php echo h($row['key_no'] ?: '-'); ?></div>
                                                </td>

                                                <td class="small">
                                                    <div><strong>Battery Brand:</strong> <?php echo h($row['battery_brand'] ?: '-'); ?></div>
                                                    <div><strong>Battery No:</strong> <?php echo h($row['battery_no'] ?: '-'); ?></div>
                                                    <div><strong>Battery Capacity:</strong> <?php echo h($row['battery_capacity'] ?: '-'); ?></div>
                                                    <div><strong>Charger Brand:</strong> <?php echo h($row['charger_brand'] ?: '-'); ?></div>
                                                    <div><strong>Charger No:</strong> <?php echo h($row['charger_no'] ?: '-'); ?></div>
                                                    <div><strong>Charger Type:</strong> <?php echo h($row['charger_type'] ?: '-'); ?></div>
                                                </td>

                                                <td class="small">
                                                    <div><strong>Purchase Cost:</strong> <?php echo money($row['purchase_cost']); ?></div>
                                                    <div><strong>Sale Price:</strong> <?php echo money($row['sale_price']); ?></div>
                                                    <div><strong>Model Price:</strong> <?php echo money($row['ex_showroom_price'] ?? 0); ?></div>
                                                    <div><strong>Purchase Date:</strong>
                                                        <?php
                                                        echo (!empty($row['purchase_date']) && $row['purchase_date'] !== '0000-00-00')
                                                            ? h(date('d M Y', strtotime($row['purchase_date'])))
                                                            : '-';
                                                        ?>
                                                    </div>
                                                </td>

                                                <td>
                                                    <span class="badge bg-<?php echo $statusBadge; ?>">
                                                        <?php echo h(ucwords(str_replace('_', ' ', (string)($row['stock_status'] ?? '-')))); ?>
                                                    </span>
                                                </td>

                                                <td class="small">
                                                    <div><?php echo h($row['supplier_name'] ?: '-'); ?></div>
                                                    <?php if (!empty($row['remarks'])): ?>
                                                        <div class="text-muted"><?php echo h($row['remarks']); ?></div>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="8" class="text-center text-muted py-4">No vehicle stock records found.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="mt-3 text-muted small">
                            Showing latest 500 vehicle records.
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="row report-last-row">
                    <div class="col-xl-6">
                        <div class="card h-100">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Product Stock Summary</h4>
                                <table class="table table-bordered table-striped mb-0">
                                    <tbody>
                                        <tr>
                                            <th style="width:50%;">Total Products</th>
                                            <td><?php echo number_format($totalProducts); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Total Quantity</th>
                                            <td><?php echo qtyf($totalProductQty); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Low Stock Products</th>
                                            <td><?php echo number_format($lowStockProducts); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Stock Value</th>
                                            <td><strong class="text-primary"><?php echo money($totalProductStockValue); ?></strong></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-6">
                        <div class="card h-100">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Vehicle Stock Summary</h4>
                                <table class="table table-bordered table-striped mb-0">
                                    <tbody>
                                        <tr>
                                            <th style="width:50%;">Total Vehicles</th>
                                            <td><?php echo number_format($totalVehicles); ?></td>
                                        </tr>
                                        <tr>
                                            <th>In Stock</th>
                                            <td><?php echo number_format($inStockVehicles); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Reserved</th>
                                            <td><?php echo number_format($reservedVehicles); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Sold</th>
                                            <td><?php echo number_format($soldVehicles); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Stock Value</th>
                                            <td><strong class="text-success"><?php echo money($vehicleStockValue); ?></strong></td>
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