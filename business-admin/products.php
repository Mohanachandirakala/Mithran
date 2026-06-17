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

/**
 * Calculate Base Price (without GST) from Final Price (Inclusive GST)
 * Formula: Base = Final / (1 + GST%)
 */
function calculateBasePrice($finalPrice, $gstPercent): float
{
    $finalPrice = (float)$finalPrice;
    $gstPercent = (float)$gstPercent;

    if ($finalPrice <= 0 || $gstPercent <= 0) {
        return $finalPrice;
    }

    return round($finalPrice / (1 + ($gstPercent / 100)), 2);
}

/**
 * Calculate GST Amount from Final Price (Inclusive GST)
 * Formula: GST = Final - Base
 */
function calculateGstAmount($finalPrice, $gstPercent): float
{
    $finalPrice = (float)$finalPrice;
    $basePrice = calculateBasePrice($finalPrice, $gstPercent);
    return round($finalPrice - $basePrice, 2);
}

function tableExists(mysqli $conn, string $table): bool
{
    $sql = "SELECT 1
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
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

function getCount(mysqli $conn, string $table, string $where = '1=1'): int
{
    $sql = "SELECT COUNT(*) AS total FROM {$table} WHERE {$where}";
    $res = $conn->query($sql);
    if (!$res) return 0;

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
   FILTERS
------------------------------------------------------- */
$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$typeFilter = trim($_GET['product_type'] ?? '');
$categoryFilter = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;

$where = ["p.business_id = {$businessId}"];

if ($search !== '') {
    $safe = $conn->real_escape_string($search);
    $where[] = "(
        p.product_name LIKE '%{$safe}%'
        OR p.product_code LIKE '%{$safe}%'
        OR p.item_code LIKE '%{$safe}%'
        OR p.part_number LIKE '%{$safe}%'
        OR p.brand_name LIKE '%{$safe}%'
        OR p.hsn_code LIKE '%{$safe}%'
    )";
}

if ($statusFilter !== '') {
    if ($statusFilter === 'active') {
        $where[] = "p.status = 1";
    } elseif ($statusFilter === 'inactive') {
        $where[] = "p.status = 0";
    }
}

if ($typeFilter !== '') {
    $safeType = $conn->real_escape_string($typeFilter);
    $where[] = "p.product_type = '{$safeType}'";
}

if ($categoryFilter > 0) {
    $where[] = "p.category_id = {$categoryFilter}";
}

$whereSql = implode(' AND ', $where);

/* -------------------------------------------------------
   LOAD CATEGORIES
------------------------------------------------------- */
$categories = [];
if (tableExists($conn, 'product_categories')) {
    $res = $conn->query("SELECT id, category_name FROM product_categories ORDER BY category_name ASC");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $categories[] = $row;
        }
        $res->free();
    }
}

/* -------------------------------------------------------
   SUMMARY
------------------------------------------------------- */
$totalProducts = tableExists($conn, 'products')
    ? getCount($conn, 'products', "business_id = {$businessId}")
    : 0;

$activeProducts = tableExists($conn, 'products')
    ? getCount($conn, 'products', "business_id = {$businessId} AND status = 1")
    : 0;

$inactiveProducts = tableExists($conn, 'products')
    ? getCount($conn, 'products', "business_id = {$businessId} AND status = 0")
    : 0;

$lowStockProducts = tableExists($conn, 'products')
    ? getCount($conn, 'products', "business_id = {$businessId} AND status = 1 AND stock_qty <= min_stock_qty")
    : 0;

/* -------------------------------------------------------
   FETCH PRODUCTS
------------------------------------------------------- */
$products = [];

if (tableExists($conn, 'products')) {
    $sql = "SELECT
                p.id,
                p.category_id,
                p.product_type,
                p.product_name,
                p.product_code,
                p.brand_name,
                p.part_number,
                p.item_code,
                p.hsn_code,
                p.unit_name,
                p.unit,
                p.purchase_price,
                p.mrp,
                p.selling_price,
                p.gst_percent,
                p.stock_qty,
                p.min_stock_qty,
                p.description,
                p.status,
                p.created_at,
                pc.category_name
            FROM products p
            LEFT JOIN product_categories pc ON pc.id = p.category_id
            WHERE {$whereSql}
            ORDER BY p.id DESC";

    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $products[] = $row;
        }
        $res->free();
    }
}

$pageTitle = 'Products';
$currentPage = 'products';
?>
<!doctype html>
<html lang="en">

<?php include('includes/head.php'); ?>

<body data-sidebar="dark">

<style>
    .gst-inclusive-badge {
        background-color: #28a745;
        color: white;
        font-size: 10px;
        padding: 2px 6px;
        border-radius: 4px;
        margin-left: 5px;
    }
    .price-breakdown {
        font-size: 11px;
        border-top: 1px dashed #dee2e6;
        margin-top: 5px;
        padding-top: 5px;
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
                    <div class="col-md-6">
                        <h4 class="mb-1">Products</h4>
                        <p class="text-muted mb-0">Manage your business products (GST Inclusive Pricing)</p>
                    </div>
                    <div class="col-md-6 text-md-end mt-3 mt-md-0">
                        <a href="product-add.php" class="btn btn-primary">Add Product</a>
                    </div>
                </div>

                <!-- GST Information Alert -->
                <div class="alert alert-info alert-dismissible fade show mb-3" role="alert">
                    <i class="mdi mdi-information-outline me-2"></i>
                    <strong>GST Inclusive Pricing:</strong> All prices displayed are inclusive of GST. The base price (without GST) and GST amount are calculated automatically.
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>

                <div class="row">
                    <div class="col-md-6 col-xl-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Total Products</p>
                                <h3 class="mb-0"><?php echo number_format($totalProducts); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Active Products</p>
                                <h3 class="mb-0 text-success"><?php echo number_format($activeProducts); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Inactive Products</p>
                                <h3 class="mb-0 text-warning"><?php echo number_format($inactiveProducts); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Low Stock</p>
                                <h3 class="mb-0 text-danger"><?php echo number_format($lowStockProducts); ?></h3>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-body">

                        <form method="get" class="row g-3 mb-4">
                            <div class="col-md-4">
                                <label class="form-label">Search</label>
                                <input
                                    type="text"
                                    name="search"
                                    class="form-control"
                                    placeholder="Name, code, brand, HSN..."
                                    value="<?php echo h($search); ?>"
                                >
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">Status</label>
                                <select name="status" class="form-select">
                                    <option value="">All</option>
                                    <option value="active" <?php echo ($statusFilter === 'active') ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo ($statusFilter === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">Product Type</label>
                                <select name="product_type" class="form-select">
                                    <option value="">All</option>
                                    <option value="spare_part" <?php echo ($typeFilter === 'spare_part') ? 'selected' : ''; ?>>Spare Part</option>
                                    <option value="accessory" <?php echo ($typeFilter === 'accessory') ? 'selected' : ''; ?>>Accessory</option>
                                    <option value="consumable" <?php echo ($typeFilter === 'consumable') ? 'selected' : ''; ?>>Consumable</option>
                                    <option value="lubricant" <?php echo ($typeFilter === 'lubricant') ? 'selected' : ''; ?>>Lubricant</option>
                                    <option value="battery" <?php echo ($typeFilter === 'battery') ? 'selected' : ''; ?>>Battery</option>
                                    <option value="tyre" <?php echo ($typeFilter === 'tyre') ? 'selected' : ''; ?>>Tyre</option>
                                    <option value="helmet" <?php echo ($typeFilter === 'helmet') ? 'selected' : ''; ?>>Helmet</option>
                                    <option value="other" <?php echo ($typeFilter === 'other') ? 'selected' : ''; ?>>Other</option>
                                </select>
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">Category</label>
                                <select name="category_id" class="form-select">
                                    <option value="0">All</option>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?php echo (int)$cat['id']; ?>" <?php echo ($categoryFilter === (int)$cat['id']) ? 'selected' : ''; ?>>
                                            <?php echo h($cat['category_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-1 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary w-100">Go</button>
                            </div>
                        </form>

                        <div class="table-responsive">
                            <table class="table table-bordered table-striped align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Product</th>
                                        <th>Category</th>
                                        <th>Type</th>
                                        <th>Codes</th>
                                        <th>Price Details (GST Inclusive)</th>
                                        <th>GST</th>
                                        <th>Stock</th>
                                        <th>Status</th>
                                        <th style="width: 170px;">Action</th>
                                    </tr>
                                </thead>

                                <tbody>
                                    <?php if (!empty($products)): ?>
                                        <?php $i = 1; foreach ($products as $p): ?>
                                            <?php
                                            $gst = (float)$p['gst_percent'];

                                            // Calculate inclusive GST breakdown
                                            $purchaseFinal = (float)$p['purchase_price'];
                                            $mrpFinal = (float)$p['mrp'];
                                            $sellingFinal = (float)$p['selling_price'];

                                            // Calculate base prices (without GST)
                                            $purchaseBase = calculateBasePrice($purchaseFinal, $gst);
                                            $purchaseGst = calculateGstAmount($purchaseFinal, $gst);

                                            $mrpBase = calculateBasePrice($mrpFinal, $gst);
                                            $mrpGst = calculateGstAmount($mrpFinal, $gst);

                                            $sellingBase = calculateBasePrice($sellingFinal, $gst);
                                            $sellingGst = calculateGstAmount($sellingFinal, $gst);

                                            // Calculate profit on final price
                                            $profitFinal = $sellingFinal - $purchaseFinal;
                                            $profitBase = $sellingBase - $purchaseBase;
                                            ?>
                                            <tr>
                                                <td><?php echo $i++; ?></td>

                                                <td>
                                                    <div class="fw-bold">
                                                        <?php echo h($p['product_name']); ?>
                                                        <span class="gst-inclusive-badge">GST Inclusive</span>
                                                    </div>

                                                    <?php if (!empty($p['brand_name'])): ?>
                                                        <div class="text-muted small">Brand: <?php echo h($p['brand_name']); ?></div>
                                                    <?php endif; ?>

                                                    <?php if (!empty($p['description'])): ?>
                                                        <div class="text-muted small">
                                                            <?php echo h(mb_strimwidth((string)$p['description'], 0, 60, '...')); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>

                                                <td><?php echo h($p['category_name'] ?: '-'); ?></td>

                                                <td><?php echo h(ucwords(str_replace('_', ' ', $p['product_type'] ?: '-'))); ?></td>

                                                <td>
                                                    <div><strong>Item:</strong> <?php echo h($p['item_code'] ?: '-'); ?></div>
                                                    <div class="small text-muted"><strong>Product:</strong> <?php echo h($p['product_code'] ?: '-'); ?></div>
                                                    <div class="small text-muted"><strong>Part:</strong> <?php echo h($p['part_number'] ?: '-'); ?></div>
                                                    <div class="small text-muted"><strong>HSN:</strong> <?php echo h($p['hsn_code'] ?: '-'); ?></div>
                                                </td>

                                                <td>
                                                    <!-- Purchase Price -->
                                                    <div class="mb-2">
                                                        <strong>Purchase:</strong> <?php echo money($purchaseFinal); ?>
                                                        <div class="price-breakdown">
                                                            <span class="text-muted">Base:</span> <?php echo money($purchaseBase); ?> |
                                                            <span class="text-muted">GST:</span> <?php echo money($purchaseGst); ?>
                                                        </div>
                                                    </div>

                                                    <!-- MRP -->
                                                    <div class="mb-2">
                                                        <strong>MRP:</strong> <?php echo money($mrpFinal); ?>
                                                        <div class="price-breakdown">
                                                            <span class="text-muted">Base:</span> <?php echo money($mrpBase); ?> |
                                                            <span class="text-muted">GST:</span> <?php echo money($mrpGst); ?>
                                                        </div>
                                                    </div>

                                                    <!-- Selling Price -->
                                                    <div class="mb-2">
                                                        <strong>Selling:</strong> <?php echo money($sellingFinal); ?>
                                                        <div class="price-breakdown">
                                                            <span class="text-muted">Base:</span> <?php echo money($sellingBase); ?> |
                                                            <span class="text-muted">GST:</span> <?php echo money($sellingGst); ?>
                                                        </div>
                                                    </div>

                                                    <!-- Profit -->
                                                    <div>
                                                        <strong>Profit:</strong>
                                                        <span class="<?php echo $profitFinal >= 0 ? 'text-success' : 'text-danger'; ?>">
                                                            <?php echo money($profitFinal); ?>
                                                        </span>
                                                        <div class="small text-muted">
                                                            (Base: <?php echo money($profitBase); ?>)
                                                        </div>
                                                    </div>
                                                </td>

                                                <td>
                                                    <span class="badge bg-info">
                                                        <?php echo qtyf($gst); ?>%
                                                    </span>
                                                    <div class="small text-muted mt-1">Inclusive</div>
                                                </td>

                                                <td>
                                                    <div><strong><?php echo qtyf($p['stock_qty']); ?></strong> <?php echo h($p['unit'] ?: 'pcs'); ?></div>
                                                    <div class="small text-muted">Min: <?php echo qtyf($p['min_stock_qty']); ?></div>

                                                    <?php if ((float)$p['stock_qty'] <= 0): ?>
                                                        <span class="badge bg-dark mt-1">Out of Stock</span>
                                                    <?php elseif ((float)$p['stock_qty'] <= (float)$p['min_stock_qty']): ?>
                                                        <span class="badge bg-danger mt-1">Low Stock</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-success mt-1">Available</span>
                                                    <?php endif; ?>
                                                 </td>

                                                <td>
                                                    <?php if ((int)$p['status'] === 1): ?>
                                                        <span class="badge bg-success">Active</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-warning">Inactive</span>
                                                    <?php endif; ?>
                                                 </td>

                                                <td>
                                                    <a href="product-view.php?id=<?php echo (int)$p['id']; ?>" class="btn btn-sm btn-info">View</a>
                                                    <a href="product-edit.php?id=<?php echo (int)$p['id']; ?>" class="btn btn-sm btn-primary">Edit</a>
                                                 </td>
                                             </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="10" class="text-center text-muted py-4">No products found.</td>
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