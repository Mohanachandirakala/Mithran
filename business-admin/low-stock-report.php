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
$requiredTables = ['product_stock', 'products', 'branches'];
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
     WHERE business_id = {$businessId}
     ORDER BY branch_name ASC"
);

$categories = [];
if (tableExists($conn, 'product_categories')) {
    $categories = fetchAllAssoc(
        $conn,
        "SELECT id, category_name
         FROM product_categories
         ORDER BY category_name ASC"
    );
}

/* -------------------------------------------------------
   FILTERS
------------------------------------------------------- */
$search = trim($_GET['search'] ?? '');
$branchFilter = (int)($_GET['branch_id'] ?? 0);
$categoryFilter = (int)($_GET['category_id'] ?? 0);
$stockType = trim($_GET['stock_type'] ?? 'all'); // all | low | out

$where = [
    "ps.business_id = {$businessId}",
    "ps.qty_available <= p.min_stock_qty"
];

if ($search !== '') {
    $safe = $conn->real_escape_string($search);
    $where[] = "(
        p.product_name LIKE '%{$safe}%'
        OR p.item_code LIKE '%{$safe}%'
        OR p.product_code LIKE '%{$safe}%'
        OR p.brand_name LIKE '%{$safe}%'
        OR pc.category_name LIKE '%{$safe}%'
        OR br.branch_name LIKE '%{$safe}%'
    )";
}

if ($branchFilter > 0) {
    $where[] = "ps.branch_id = {$branchFilter}";
}

if ($categoryFilter > 0) {
    $where[] = "p.category_id = {$categoryFilter}";
}

if ($stockType === 'out') {
    $where[] = "ps.qty_available <= 0";
} elseif ($stockType === 'low') {
    $where[] = "ps.qty_available > 0 AND ps.qty_available <= p.min_stock_qty";
}

$whereSql = implode(' AND ', $where);

/* -------------------------------------------------------
   SUMMARY
------------------------------------------------------- */
$totalLowRows = 0;
$outOfStockRows = 0;
$lowButAvailableRows = 0;
$totalShortageQty = 0.0;

$res = $conn->query("SELECT COUNT(*) AS total
                     FROM product_stock ps
                     INNER JOIN products p ON p.id = ps.product_id
                     WHERE ps.business_id = {$businessId}
                       AND ps.qty_available <= p.min_stock_qty");
if ($res) {
    $row = $res->fetch_assoc();
    $totalLowRows = (int)($row['total'] ?? 0);
}

$res = $conn->query("SELECT COUNT(*) AS total
                     FROM product_stock ps
                     INNER JOIN products p ON p.id = ps.product_id
                     WHERE ps.business_id = {$businessId}
                       AND ps.qty_available <= 0");
if ($res) {
    $row = $res->fetch_assoc();
    $outOfStockRows = (int)($row['total'] ?? 0);
}

$res = $conn->query("SELECT COUNT(*) AS total
                     FROM product_stock ps
                     INNER JOIN products p ON p.id = ps.product_id
                     WHERE ps.business_id = {$businessId}
                       AND ps.qty_available > 0
                       AND ps.qty_available <= p.min_stock_qty");
if ($res) {
    $row = $res->fetch_assoc();
    $lowButAvailableRows = (int)($row['total'] ?? 0);
}

$res = $conn->query("SELECT COALESCE(SUM(
                        CASE 
                            WHEN p.min_stock_qty > ps.qty_available 
                            THEN (p.min_stock_qty - ps.qty_available)
                            ELSE 0
                        END
                     ),0) AS total_shortage
                     FROM product_stock ps
                     INNER JOIN products p ON p.id = ps.product_id
                     WHERE ps.business_id = {$businessId}
                       AND ps.qty_available <= p.min_stock_qty");
if ($res) {
    $row = $res->fetch_assoc();
    $totalShortageQty = (float)($row['total_shortage'] ?? 0);
}

/* -------------------------------------------------------
   FETCH LOW STOCK ROWS
------------------------------------------------------- */
$rows = [];

$sql = "SELECT
            ps.id,
            ps.branch_id,
            ps.product_id,
            ps.qty_available,
            ps.last_purchase_price,
            ps.updated_at,

            p.product_name,
            p.product_code,
            p.item_code,
            p.brand_name,
            p.unit,
            p.unit_name,
            p.min_stock_qty,
            p.selling_price,
            p.purchase_price,
            p.status AS product_status,

            pc.category_name,

            br.branch_name,
            br.branch_code

        FROM product_stock ps
        INNER JOIN products p ON p.id = ps.product_id
        LEFT JOIN product_categories pc ON pc.id = p.category_id
        LEFT JOIN branches br ON br.id = ps.branch_id
        WHERE {$whereSql}
        ORDER BY 
            CASE WHEN ps.qty_available <= 0 THEN 0 ELSE 1 END,
            (p.min_stock_qty - ps.qty_available) DESC,
            p.product_name ASC";

$res = $conn->query($sql);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $row['shortage_qty'] = max(0, (float)$row['min_stock_qty'] - (float)$row['qty_available']);
        $rows[] = $row;
    }
}

$pageTitle = 'Low Stock Report';
$currentPage = 'low-stock-report';
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
                        <h4 class="mb-1">Low Stock Report</h4>
                        <p class="text-muted mb-0">View branch-wise low stock and out of stock products</p>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Total Low Stock Rows</p>
                                <h3 class="mb-0"><?php echo number_format($totalLowRows); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Out of Stock</p>
                                <h3 class="mb-0 text-danger"><?php echo number_format($outOfStockRows); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Low but Available</p>
                                <h3 class="mb-0 text-warning"><?php echo number_format($lowButAvailableRows); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Total Shortage Qty</p>
                                <h3 class="mb-0 text-info"><?php echo qtyf($totalShortageQty); ?></h3>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- FILTERS -->
                <div class="card">
                    <div class="card-body">
                        <form method="get" class="row g-3">
                            <div class="col-md-4">
                                <input
                                    type="text"
                                    name="search"
                                    class="form-control"
                                    placeholder="Search product, code, brand, branch..."
                                    value="<?php echo h($search); ?>"
                                >
                            </div>

                            <div class="col-md-2">
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
                                <select name="category_id" class="form-select">
                                    <option value="0">All Categories</option>
                                    <?php foreach ($categories as $c): ?>
                                        <option value="<?php echo (int)$c['id']; ?>" <?php echo ($categoryFilter === (int)$c['id']) ? 'selected' : ''; ?>>
                                            <?php echo h($c['category_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-2">
                                <select name="stock_type" class="form-select">
                                    <option value="all" <?php echo ($stockType === 'all') ? 'selected' : ''; ?>>All Low Stock</option>
                                    <option value="low" <?php echo ($stockType === 'low') ? 'selected' : ''; ?>>Low Only</option>
                                    <option value="out" <?php echo ($stockType === 'out') ? 'selected' : ''; ?>>Out of Stock</option>
                                </select>
                            </div>

                            <div class="col-md-1">
                                <button type="submit" class="btn btn-secondary w-100">Go</button>
                            </div>

                            <div class="col-md-1">
                                <a href="low-stock-report.php" class="btn btn-light w-100">Reset</a>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- LIST -->
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4">Low Stock Items</h4>

                        <div class="table-responsive">
                            <table class="table table-bordered table-striped align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Product</th>
                                        <th>Branch</th>
                                        <th>Category</th>
                                        <th>Available Qty</th>
                                        <th>Min Qty</th>
                                        <th>Shortage</th>
                                        <th>Price</th>
                                        <th>Status</th>
                                        <th>Updated</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($rows)): ?>
                                        <?php $i = 1; foreach ($rows as $row): ?>
                                            <tr>
                                                <td><?php echo $i++; ?></td>

                                                <td>
                                                    <strong><?php echo h($row['product_name']); ?></strong>
                                                    <div class="small text-muted">
                                                        Item: <?php echo h($row['item_code'] ?: '-'); ?>
                                                    </div>
                                                    <div class="small text-muted">
                                                        Product: <?php echo h($row['product_code'] ?: '-'); ?>
                                                    </div>
                                                    <div class="small text-muted">
                                                        Brand: <?php echo h($row['brand_name'] ?: '-'); ?>
                                                    </div>
                                                </td>

                                                <td>
                                                    <div><?php echo h($row['branch_name'] ?: '-'); ?></div>
                                                    <div class="small text-muted"><?php echo h($row['branch_code'] ?: '-'); ?></div>
                                                </td>

                                                <td><?php echo h($row['category_name'] ?: '-'); ?></td>

                                                <td>
                                                    <strong class="<?php echo ((float)$row['qty_available'] <= 0) ? 'text-danger' : 'text-warning'; ?>">
                                                        <?php echo qtyf($row['qty_available']); ?>
                                                    </strong>
                                                    <?php echo h($row['unit'] ?: 'pcs'); ?>

                                                    <?php if ((float)$row['qty_available'] <= 0): ?>
                                                        <div><span class="badge bg-danger mt-1">Out of Stock</span></div>
                                                    <?php else: ?>
                                                        <div><span class="badge bg-warning mt-1">Low Stock</span></div>
                                                    <?php endif; ?>
                                                </td>

                                                <td>
                                                    <?php echo qtyf($row['min_stock_qty']); ?>
                                                    <?php echo h($row['unit'] ?: 'pcs'); ?>
                                                </td>

                                                <td>
                                                    <strong class="text-info">
                                                        <?php echo qtyf($row['shortage_qty']); ?>
                                                    </strong>
                                                    <?php echo h($row['unit'] ?: 'pcs'); ?>
                                                </td>

                                                <td class="small">
                                                    <div><strong>Last Purchase:</strong> <?php echo money($row['last_purchase_price']); ?></div>
                                                    <div><strong>Purchase:</strong> <?php echo money($row['purchase_price']); ?></div>
                                                    <div><strong>Sale:</strong> <?php echo money($row['selling_price']); ?></div>
                                                </td>

                                                <td>
                                                    <?php if ((int)$row['product_status'] === 1): ?>
                                                        <span class="badge bg-success">Active</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary">Inactive</span>
                                                    <?php endif; ?>
                                                </td>

                                                <td>
                                                    <?php echo !empty($row['updated_at']) ? h(date('d M Y h:i A', strtotime($row['updated_at']))) : '-'; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="10" class="text-center text-muted py-4">No low stock items found.</td>
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