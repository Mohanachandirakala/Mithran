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

function getSum(mysqli $conn, string $table, string $field, string $where = '1=1'): float
{
    $sql = "SELECT COALESCE(SUM({$field}),0) AS total FROM {$table} WHERE {$where}";
    $res = $conn->query($sql);
    if (!$res) {
        return 0;
    }
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
if (!tableExists($conn, 'stock_movements')) {
    die('stock_movements table not found.');
}
if (!tableExists($conn, 'branches')) {
    die('branches table not found.');
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

$allowedItemTypes = ['vehicle', 'product'];
$allowedMovementTypes = [
    'purchase',
    'sale',
    'sale_return',
    'service_use',
    'adjustment',
    'transfer_in',
    'transfer_out'
];

/* -------------------------------------------------------
   FILTERS
------------------------------------------------------- */
$search = trim($_GET['search'] ?? '');
$branchFilter = (int)($_GET['branch_id'] ?? 0);
$itemTypeFilter = trim($_GET['item_type'] ?? '');
$movementTypeFilter = trim($_GET['movement_type'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo   = trim($_GET['date_to'] ?? '');

$where = ["sm.business_id = {$businessId}"];

if ($branchFilter > 0) {
    $where[] = "sm.branch_id = {$branchFilter}";
}

if ($itemTypeFilter !== '' && in_array($itemTypeFilter, $allowedItemTypes, true)) {
    $safe = $conn->real_escape_string($itemTypeFilter);
    $where[] = "sm.item_type = '{$safe}'";
}

if ($movementTypeFilter !== '' && in_array($movementTypeFilter, $allowedMovementTypes, true)) {
    $safe = $conn->real_escape_string($movementTypeFilter);
    $where[] = "sm.movement_type = '{$safe}'";
}

if ($dateFrom !== '') {
    $safe = $conn->real_escape_string($dateFrom);
    $where[] = "DATE(sm.movement_date) >= '{$safe}'";
}

if ($dateTo !== '') {
    $safe = $conn->real_escape_string($dateTo);
    $where[] = "DATE(sm.movement_date) <= '{$safe}'";
}

if ($search !== '') {
    $safe = $conn->real_escape_string($search);
    $where[] = "(
        sm.ref_table LIKE '%{$safe}%'
        OR sm.notes LIKE '%{$safe}%'
        OR br.branch_name LIKE '%{$safe}%'
        OR bu.full_name LIKE '%{$safe}%'
        OR CAST(sm.ref_id AS CHAR) LIKE '%{$safe}%'
        OR CAST(sm.item_id AS CHAR) LIKE '%{$safe}%'
    )";
}

$whereSql = implode(' AND ', $where);

/* -------------------------------------------------------
   SUMMARY
------------------------------------------------------- */
$totalMovements = getCount($conn, 'stock_movements', "business_id = {$businessId}");
$totalProductMovements = getCount($conn, 'stock_movements', "business_id = {$businessId} AND item_type = 'product'");
$totalVehicleMovements = getCount($conn, 'stock_movements', "business_id = {$businessId} AND item_type = 'vehicle'");
$totalMovementQty = getSum($conn, 'stock_movements', 'qty', "business_id = {$businessId}");

/* -------------------------------------------------------
   FETCH MOVEMENTS
------------------------------------------------------- */
$movements = [];

$sql = "SELECT
            sm.*,
            br.branch_name,
            br.branch_code,
            bu.full_name AS created_by_name
        FROM stock_movements sm
        LEFT JOIN branches br ON br.id = sm.branch_id
        LEFT JOIN business_users bu ON bu.id = sm.created_by
        WHERE {$whereSql}
        ORDER BY sm.id DESC";

$res = $conn->query($sql);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $itemLabel = '-';

        if (($row['item_type'] ?? '') === 'product' && tableExists($conn, 'products')) {
            $productId = (int)$row['item_id'];
            $pRes = $conn->query("SELECT product_name, item_code, product_code, brand_name
                                  FROM products
                                  WHERE id = {$productId} AND business_id = {$businessId}
                                  LIMIT 1");
            if ($pRes && $pRow = $pRes->fetch_assoc()) {
                $itemLabel = $pRow['product_name'] ?: 'Product';
                if (!empty($pRow['item_code'])) {
                    $itemLabel .= ' (' . $pRow['item_code'] . ')';
                } elseif (!empty($pRow['product_code'])) {
                    $itemLabel .= ' (' . $pRow['product_code'] . ')';
                }
            }
        }

        if (($row['item_type'] ?? '') === 'vehicle' && tableExists($conn, 'vehicle_stock') && tableExists($conn, 'vehicle_models')) {
            $vehicleId = (int)$row['item_id'];
            $vRes = $conn->query("SELECT 
                                    vs.chassis_no,
                                    vs.engine_no,
                                    vm.model_name,
                                    vm.variant_name,
                                    vb.brand_name
                                  FROM vehicle_stock vs
                                  LEFT JOIN vehicle_models vm ON vm.id = vs.model_id
                                  LEFT JOIN vehicle_brands vb ON vb.id = vm.brand_id
                                  WHERE vs.id = {$vehicleId} AND vs.business_id = {$businessId}
                                  LIMIT 1");
            if ($vRes && $vRow = $vRes->fetch_assoc()) {
                $itemLabel = trim(
                    ($vRow['brand_name'] ?: '') .
                    (($vRow['brand_name'] && $vRow['model_name']) ? ' - ' : '') .
                    ($vRow['model_name'] ?: 'Vehicle')
                );
                if (!empty($vRow['variant_name'])) {
                    $itemLabel .= ' / ' . $vRow['variant_name'];
                }
                if (!empty($vRow['chassis_no'])) {
                    $itemLabel .= ' (' . $vRow['chassis_no'] . ')';
                }
            }
        }

        $row['item_label'] = $itemLabel;
        $movements[] = $row;
    }
}

$pageTitle = 'Stock Movements';
$currentPage = 'stock-movements';
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
                        <h4 class="mb-1">Stock Movements</h4>
                        <p class="text-muted mb-0">Track product and vehicle stock movement history</p>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Total Movements</p>
                                <h3 class="mb-0"><?php echo number_format($totalMovements); ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Product Movements</p>
                                <h3 class="mb-0 text-primary"><?php echo number_format($totalProductMovements); ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Vehicle Movements</p>
                                <h3 class="mb-0 text-info"><?php echo number_format($totalVehicleMovements); ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Total Qty Moved</p>
                                <h3 class="mb-0 text-success"><?php echo qtyf($totalMovementQty); ?></h3>
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
                                    placeholder="Search notes, ref, branch..."
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
                                <select name="item_type" class="form-select">
                                    <option value="">All Item Types</option>
                                    <option value="product" <?php echo ($itemTypeFilter === 'product') ? 'selected' : ''; ?>>Product</option>
                                    <option value="vehicle" <?php echo ($itemTypeFilter === 'vehicle') ? 'selected' : ''; ?>>Vehicle</option>
                                </select>
                            </div>

                            <div class="col-md-2">
                                <select name="movement_type" class="form-select">
                                    <option value="">All Movement Types</option>
                                    <option value="purchase" <?php echo ($movementTypeFilter === 'purchase') ? 'selected' : ''; ?>>Purchase</option>
                                    <option value="sale" <?php echo ($movementTypeFilter === 'sale') ? 'selected' : ''; ?>>Sale</option>
                                    <option value="sale_return" <?php echo ($movementTypeFilter === 'sale_return') ? 'selected' : ''; ?>>Sale Return</option>
                                    <option value="service_use" <?php echo ($movementTypeFilter === 'service_use') ? 'selected' : ''; ?>>Service Use</option>
                                    <option value="adjustment" <?php echo ($movementTypeFilter === 'adjustment') ? 'selected' : ''; ?>>Adjustment</option>
                                    <option value="transfer_in" <?php echo ($movementTypeFilter === 'transfer_in') ? 'selected' : ''; ?>>Transfer In</option>
                                    <option value="transfer_out" <?php echo ($movementTypeFilter === 'transfer_out') ? 'selected' : ''; ?>>Transfer Out</option>
                                </select>
                            </div>

                            <div class="col-md-1">
                                <input type="date" name="date_from" class="form-control" value="<?php echo h($dateFrom); ?>">
                            </div>

                            <div class="col-md-1">
                                <input type="date" name="date_to" class="form-control" value="<?php echo h($dateTo); ?>">
                            </div>

                            <div class="col-md-1">
                                <button type="submit" class="btn btn-secondary w-100">Go</button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- LIST -->
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4">Movement List</h4>

                        <div class="table-responsive">
                            <table class="table table-bordered table-striped align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Date</th>
                                        <th>Branch</th>
                                        <th>Item Type</th>
                                        <th>Item</th>
                                        <th>Movement Type</th>
                                        <th>Qty</th>
                                        <th>Unit Price</th>
                                        <th>Reference</th>
                                        <th>Notes</th>
                                        <th>Created By</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($movements)): ?>
                                        <?php $i = 1; foreach ($movements as $row): ?>
                                            <tr>
                                                <td><?php echo $i++; ?></td>

                                                <td>
                                                    <?php echo !empty($row['movement_date']) ? h(date('d M Y h:i A', strtotime($row['movement_date']))) : '-'; ?>
                                                </td>

                                                <td>
                                                    <div><?php echo h($row['branch_name'] ?: '-'); ?></div>
                                                    <div class="small text-muted"><?php echo h($row['branch_code'] ?: '-'); ?></div>
                                                </td>

                                                <td>
                                                    <span class="badge bg-<?php echo ($row['item_type'] === 'vehicle') ? 'info' : 'primary'; ?>">
                                                        <?php echo h(ucfirst($row['item_type'])); ?>
                                                    </span>
                                                </td>

                                                <td>
                                                    <div><?php echo h($row['item_label']); ?></div>
                                                    <div class="small text-muted">Item ID: <?php echo (int)$row['item_id']; ?></div>
                                                </td>

                                                <td>
                                                    <?php
                                                    $badge = 'secondary';
                                                    if ($row['movement_type'] === 'purchase') $badge = 'success';
                                                    elseif ($row['movement_type'] === 'sale') $badge = 'danger';
                                                    elseif ($row['movement_type'] === 'sale_return') $badge = 'warning';
                                                    elseif ($row['movement_type'] === 'service_use') $badge = 'info';
                                                    elseif ($row['movement_type'] === 'adjustment') $badge = 'dark';
                                                    elseif ($row['movement_type'] === 'transfer_in') $badge = 'primary';
                                                    elseif ($row['movement_type'] === 'transfer_out') $badge = 'secondary';
                                                    ?>
                                                    <span class="badge bg-<?php echo $badge; ?>">
                                                        <?php echo h(ucwords(str_replace('_', ' ', $row['movement_type']))); ?>
                                                    </span>
                                                </td>

                                                <td><?php echo qtyf($row['qty']); ?></td>

                                                <td><?php echo money($row['unit_price']); ?></td>

                                                <td>
                                                    <div><?php echo h($row['ref_table'] ?: '-'); ?></div>
                                                    <div class="small text-muted">Ref ID: <?php echo h($row['ref_id'] ?: '-'); ?></div>
                                                </td>

                                                <td><?php echo h($row['notes'] ?: '-'); ?></td>

                                                <td><?php echo h($row['created_by_name'] ?: '-'); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="11" class="text-center text-muted py-4">No stock movements found.</td>
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