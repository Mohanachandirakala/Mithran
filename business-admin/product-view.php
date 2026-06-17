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
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
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
    if (!$stmt) return false;

    $stmt->bind_param('s', $table);
    $stmt->execute();
    $res = $stmt->get_result();
    $exists = $res && $res->num_rows > 0;
    $stmt->close();
    return $exists;
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
   FETCH PRODUCT DETAILS
------------------------------------------------------- */
$productId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($productId <= 0) {
    header('Location: products.php');
    exit;
}

// Fetch product details with category
$stmt = $conn->prepare("
    SELECT p.*, pc.category_name
    FROM products p
    LEFT JOIN product_categories pc ON pc.id = p.category_id
    WHERE p.id = ? AND p.business_id = ?
    LIMIT 1
");
$stmt->bind_param("ii", $productId, $businessId);
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$product) {
    header('Location: products.php');
    exit;
}

// Fetch stock movements from product_stock table if exists
$stockMovements = [];
if (tableExists($conn, 'product_stock')) {
    $stockStmt = $conn->prepare("
        SELECT ps.*, b.branch_name
        FROM product_stock ps
        LEFT JOIN branches b ON b.id = ps.branch_id
        WHERE ps.product_id = ? AND ps.business_id = ?
        ORDER BY ps.updated_at DESC
    ");
    $stockStmt->bind_param("ii", $productId, $businessId);
    $stockStmt->execute();
    $stockResult = $stockStmt->get_result();
    while ($row = $stockResult->fetch_assoc()) {
        $stockMovements[] = $row;
    }
    $stockStmt->close();
}

$statusBadge = '';
if ((int)$product['status'] === 1) {
    $statusBadge = 'success';
    $statusText = 'Active';
} else {
    $statusBadge = 'danger';
    $statusText = 'Inactive';
}

$lowStock = ((float)$product['stock_qty'] <= (float)$product['min_stock_qty']);

$pageTitle = 'View Product';
$currentPage = 'products';
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
                        <h4 class="mb-1">Product Details</h4>
                        <p class="text-muted mb-0">View product information</p>
                    </div>
                    <div class="col-md-6 text-md-end mt-3 mt-md-0">
                        <a href="product-edit.php?id=<?php echo $productId; ?>" class="btn btn-primary me-2">Edit Product</a>
                        <a href="products.php" class="btn btn-secondary">Back to List</a>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-body">
                                <div class="text-center mb-4">
                                    <h4 class="mb-1"><?php echo h($product['product_name']); ?></h4>
                                    <span class="badge bg-<?php echo $statusBadge; ?> me-2" style="font-size: 12px; padding: 5px 10px;">
                                        <?php echo $statusText; ?>
                                    </span>
                                    <?php if ($lowStock): ?>
                                        <span class="badge bg-danger" style="font-size: 12px; padding: 5px 10px;">
                                            Low Stock Alert
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Basic Information</h5>
                            </div>
                            <div class="card-body">
                                <table class="table table-bordered table-sm">
                                    <tr>
                                        <th style="width: 40%;">Product Name</th>
                                        <td><strong><?php echo h($product['product_name']); ?></strong></td>
                                    </tr>
                                    <tr>
                                        <th>Category</th>
                                        <td><?php echo h($product['category_name'] ?: '-'); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Product Type</th>
                                        <td><?php echo h(ucwords(str_replace('_', ' ', $product['product_type'] ?: '-'))); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Brand Name</th>
                                        <td><?php echo h($product['brand_name'] ?: '-'); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Product Code</th>
                                        <td><?php echo h($product['product_code'] ?: '-'); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Part Number</th>
                                        <td><?php echo h($product['part_number'] ?: '-'); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Item Code</th>
                                        <td><?php echo h($product['item_code'] ?: '-'); ?></td>
                                    </tr>
                                    <tr>
                                        <th>HSN Code</th>
                                        <td><?php echo h($product['hsn_code'] ?: '-'); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Unit</th>
                                        <td><?php echo h($product['unit'] ?: '-'); ?> <?php echo !empty($product['unit_name']) ? '(' . h($product['unit_name']) . ')' : ''; ?></td>
                                    </tr>
                                    <tr>
                                        <th>Created Date</th>
                                        <td><?php echo !empty($product['created_at']) ? date('d M Y, h:i A', strtotime($product['created_at'])) : '-'; ?></td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Pricing & Stock</h5>
                            </div>
                            <div class="card-body">
                                <table class="table table-bordered table-sm">
                                    <tr>
                                        <th style="width: 40%;">Purchase Price</th>
                                        <td><?php echo money($product['purchase_price']); ?></td>
                                    </tr>
                                    <tr>
                                        <th>MRP</th>
                                        <td><?php echo money($product['mrp']); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Selling Price</th>
                                        <td><strong class="text-success"><?php echo money($product['selling_price']); ?></strong></td>
                                    </tr>
                                    <tr>
                                        <th>GST Percentage</th>
                                        <td><?php echo qtyf($product['gst_percent']); ?>%</td>
                                    </tr>
                                    <tr>
                                        <th>Current Stock</th>
                                        <td>
                                            <strong class="<?php echo $lowStock ? 'text-danger' : 'text-success'; ?>">
                                                <?php echo qtyf($product['stock_qty']); ?> <?php echo h($product['unit']); ?>
                                            </strong>
                                            <?php if ($lowStock): ?>
                                                <div class="small text-danger">Minimum stock: <?php echo qtyf($product['min_stock_qty']); ?> <?php echo h($product['unit']); ?></div>
                                            <?php else: ?>
                                                <div class="small text-muted">Minimum stock: <?php echo qtyf($product['min_stock_qty']); ?> <?php echo h($product['unit']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if (!empty($product['description'])): ?>
                <div class="row">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Description</h5>
                            </div>
                            <div class="card-body">
                                <p class="mb-0"><?php echo nl2br(h($product['description'])); ?></p>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($stockMovements)): ?>
                <div class="row">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Stock by Branch</h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-bordered table-sm">
                                        <thead>
                                            <tr>
                                                <th>#</th>
                                                <th>Branch</th>
                                                <th>Quantity Available</th>
                                                <th>Last Purchase Price</th>
                                                <th>Last Updated</th>
                                             </tr>
                                        </thead>
                                        <tbody>
                                            <?php $i = 1; foreach ($stockMovements as $stock): ?>
                                            <tr>
                                                <td><?php echo $i++; ?></td>
                                                <td><?php echo h($stock['branch_name'] ?: '-'); ?></td>
                                                <td><?php echo qtyf($stock['qty_available']); ?> <?php echo h($product['unit']); ?></td>
                                                <td><?php echo money($stock['last_purchase_price']); ?></td>
                                                <td><?php echo !empty($stock['updated_at']) ? date('d M Y, h:i A', strtotime($stock['updated_at'])) : '-'; ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

            </div>
        </div>

        <?php include('includes/footer.php'); ?>
    </div>
</div>

<?php include('includes/rightbar.php'); ?>
<?php include('includes/scripts.php'); ?>

</body>
</html>