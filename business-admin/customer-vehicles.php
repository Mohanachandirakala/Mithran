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
if (!tableExists($conn, 'business_users') || !tableExists($conn, 'businesses')) {
    die('Required tables not found.');
}

$loggedUser = null;
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
$requiredTables = ['customer_vehicles', 'customers'];
foreach ($requiredTables as $tbl) {
    if (!tableExists($conn, $tbl)) {
        die($tbl . ' table not found.');
    }
}

$hasVehicleBrands = tableExists($conn, 'vehicle_brands');
$hasVehicleModels = tableExists($conn, 'vehicle_models');
$hasVehicleStock  = tableExists($conn, 'vehicle_stock');

/* -------------------------------------------------------
   DELETE
------------------------------------------------------- */
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $deleteId = isset($_POST['id']) ? (int)$_POST['id'] : 0;

    if ($deleteId <= 0) {
        $error = 'Invalid vehicle id.';
    } else {
        $stmt = $conn->prepare("DELETE FROM customer_vehicles WHERE id = ? AND business_id = ? LIMIT 1");
        if (!$stmt) {
            $error = 'Failed to prepare delete query.';
        } else {
            $stmt->bind_param('ii', $deleteId, $businessId);
            if ($stmt->execute()) {
                if ($stmt->affected_rows > 0) {
                    $success = 'Customer vehicle deleted successfully.';
                } else {
                    $error = 'Vehicle not found.';
                }
            } else {
                $error = 'Failed to delete vehicle.';
            }
            $stmt->close();
        }
    }
}

if (isset($_GET['success']) && trim($_GET['success']) !== '') {
    $success = trim($_GET['success']);
}

/* -------------------------------------------------------
   FILTERS
------------------------------------------------------- */
$search = trim($_GET['search'] ?? '');
$customerFilter = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;
$brandFilter = isset($_GET['brand_id']) ? (int)$_GET['brand_id'] : 0;
$modelFilter = isset($_GET['model_id']) ? (int)$_GET['model_id'] : 0;
$activeFilter = trim($_GET['active_status'] ?? '');
$vehicleTypeFilter = trim($_GET['vehicle_type'] ?? '');

$where = ["cv.business_id = {$businessId}"];

if ($search !== '') {
    $safe = $conn->real_escape_string($search);
    $where[] = "(
        c.full_name LIKE '%{$safe}%'
        OR c.mobile LIKE '%{$safe}%'
        OR cv.registration_no LIKE '%{$safe}%'
        OR cv.chassis_no LIKE '%{$safe}%'
        OR cv.engine_no LIKE '%{$safe}%'
        OR cv.motor_no LIKE '%{$safe}%'
        OR cv.battery_no LIKE '%{$safe}%'
        OR cv.charger_no LIKE '%{$safe}%'
        " . ($hasVehicleBrands ? "OR vb.brand_name LIKE '%{$safe}%'" : "") . "
        " . ($hasVehicleModels ? "OR vm.model_name LIKE '%{$safe}%' OR vm.variant_name LIKE '%{$safe}%'" : "") . "
    )";
}

if ($customerFilter > 0) {
    $where[] = "cv.customer_id = {$customerFilter}";
}

if ($hasVehicleBrands && $brandFilter > 0) {
    $where[] = "cv.brand_id = {$brandFilter}";
}

if ($hasVehicleModels && $modelFilter > 0) {
    $where[] = "cv.model_id = {$modelFilter}";
}

if ($activeFilter === '1' || $activeFilter === '0') {
    $where[] = "cv.active_status = " . (int)$activeFilter;
}

if ($vehicleTypeFilter !== '' && in_array($vehicleTypeFilter, ['fuel', 'electric'], true)) {
    $safe = $conn->real_escape_string($vehicleTypeFilter);
    $where[] = "cv.vehicle_type = '{$safe}'";
}

$whereSql = implode(' AND ', $where);

/* -------------------------------------------------------
   MASTER DATA
------------------------------------------------------- */
$customers = fetchAllAssoc(
    $conn,
    "SELECT id, full_name, mobile
     FROM customers
     WHERE business_id = {$businessId}
     ORDER BY full_name ASC"
);

$brands = [];
if ($hasVehicleBrands) {
    $brands = fetchAllAssoc(
        $conn,
        "SELECT id, brand_name
         FROM vehicle_brands
         ORDER BY brand_name ASC"
    );
}

$models = [];
if ($hasVehicleModels) {
    $models = fetchAllAssoc(
        $conn,
        "SELECT id, model_name, variant_name
         FROM vehicle_models
         WHERE business_id = {$businessId}
         ORDER BY model_name ASC, variant_name ASC"
    );
}

/* -------------------------------------------------------
   SUMMARY
------------------------------------------------------- */
$totalVehicles = getCount($conn, 'customer_vehicles', "business_id = {$businessId}");
$activeVehicles = getCount($conn, 'customer_vehicles', "business_id = {$businessId} AND active_status = 1");
$inactiveVehicles = getCount($conn, 'customer_vehicles', "business_id = {$businessId} AND active_status = 0");
$electricVehicles = getCount($conn, 'customer_vehicles', "business_id = {$businessId} AND vehicle_type = 'electric'");
$fuelVehicles = getCount($conn, 'customer_vehicles', "business_id = {$businessId} AND vehicle_type = 'fuel'");
$filteredCount = getCount($conn, 'customer_vehicles', str_replace('cv.', '', $whereSql));

/* -------------------------------------------------------
   FETCH VEHICLES
------------------------------------------------------- */
$sql = "SELECT
            cv.*,
            c.full_name AS customer_name,
            c.mobile AS customer_mobile,
            c.city AS customer_city,
            " . ($hasVehicleBrands ? "vb.brand_name" : "NULL AS brand_name") . ",
            " . ($hasVehicleModels ? "vm.model_name, vm.variant_name" : "NULL AS model_name, NULL AS variant_name") . ",
            " . ($hasVehicleStock ? "vs.id AS stock_link_id" : "NULL AS stock_link_id") . "
        FROM customer_vehicles cv
        INNER JOIN customers c ON c.id = cv.customer_id
        " . ($hasVehicleBrands ? "LEFT JOIN vehicle_brands vb ON vb.id = cv.brand_id" : "") . "
        " . ($hasVehicleModels ? "LEFT JOIN vehicle_models vm ON vm.id = cv.model_id" : "") . "
        " . ($hasVehicleStock ? "LEFT JOIN vehicle_stock vs ON vs.id = cv.vehicle_stock_id" : "") . "
        WHERE {$whereSql}
        ORDER BY cv.id DESC";

$rows = fetchAllAssoc($conn, $sql);

$pageTitle = 'Customer Vehicles';
$currentPage = 'customer-vehicles';
?>
<!doctype html>
<html lang="en">

<?php include('includes/head.php'); ?>

<body data-sidebar="dark">

<style>
    .page-content {
        padding-bottom: 90px !important;
    }
    .report-last-row {
        margin-bottom: 40px;
    }
    .card {
        margin-bottom: 24px;
    }
    .table-responsive {
        overflow-x: auto;
    }
    .main-content {
        min-height: calc(100vh - 70px);
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
                    <div class="col-md-7">
                        <h4 class="mb-1">Customer Vehicles</h4>
                        <p class="text-muted mb-0">Manage customer-owned vehicles, warranty and registration details</p>
                    </div>
                    <div class="col-md-5 text-md-end mt-3 mt-md-0">
                        <a href="customer-vehicle-add.php<?php echo $customerFilter > 0 ? '?customer_id=' . $customerFilter : ''; ?>" class="btn btn-primary me-2">Add Vehicle</a>
                        <a href="customers.php" class="btn btn-secondary">Back to Customers</a>
                    </div>
                </div>

                <?php if ($success !== ''): ?>
                    <div class="alert alert-success"><?php echo h($success); ?></div>
                <?php endif; ?>

                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger"><?php echo h($error); ?></div>
                <?php endif; ?>

                <!-- SUMMARY -->
                <div class="row">
                    <div class="col-md">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Total Vehicles</p>
                                <h4 class="mb-0"><?php echo number_format($totalVehicles); ?></h4>
                            </div>
                        </div>
                    </div>
                    <div class="col-md">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Active</p>
                                <h4 class="mb-0 text-success"><?php echo number_format($activeVehicles); ?></h4>
                            </div>
                        </div>
                    </div>
                    <div class="col-md">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Inactive</p>
                                <h4 class="mb-0 text-danger"><?php echo number_format($inactiveVehicles); ?></h4>
                            </div>
                        </div>
                    </div>
                    <div class="col-md">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Electric</p>
                                <h4 class="mb-0 text-primary"><?php echo number_format($electricVehicles); ?></h4>
                            </div>
                        </div>
                    </div>
                    <div class="col-md">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Fuel</p>
                                <h4 class="mb-0 text-warning"><?php echo number_format($fuelVehicles); ?></h4>
                            </div>
                        </div>
                    </div>
                    <div class="col-md">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Filtered</p>
                                <h4 class="mb-0 text-info"><?php echo number_format($filteredCount); ?></h4>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- FILTER -->
                <div class="card">
                    <div class="card-body">
                        <form method="get" class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label">Search</label>
                                <input
                                    type="text"
                                    name="search"
                                    class="form-control"
                                    placeholder="Customer, reg no, chassis, engine..."
                                    value="<?php echo h($search); ?>"
                                >
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">Customer</label>
                                <select name="customer_id" class="form-select">
                                    <option value="0">All Customers</option>
                                    <?php foreach ($customers as $c): ?>
                                        <option value="<?php echo (int)$c['id']; ?>" <?php echo ($customerFilter === (int)$c['id']) ? 'selected' : ''; ?>>
                                            <?php echo h($c['full_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <?php if ($hasVehicleBrands): ?>
                            <div class="col-md-2">
                                <label class="form-label">Brand</label>
                                <select name="brand_id" class="form-select">
                                    <option value="0">All Brands</option>
                                    <?php foreach ($brands as $b): ?>
                                        <option value="<?php echo (int)$b['id']; ?>" <?php echo ($brandFilter === (int)$b['id']) ? 'selected' : ''; ?>>
                                            <?php echo h($b['brand_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>

                            <?php if ($hasVehicleModels): ?>
                            <div class="col-md-2">
                                <label class="form-label">Model</label>
                                <select name="model_id" class="form-select">
                                    <option value="0">All Models</option>
                                    <?php foreach ($models as $m): ?>
                                        <option value="<?php echo (int)$m['id']; ?>" <?php echo ($modelFilter === (int)$m['id']) ? 'selected' : ''; ?>>
                                            <?php echo h($m['model_name'] . ($m['variant_name'] ? ' - ' . $m['variant_name'] : '')); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>

                            <div class="col-md-1">
                                <label class="form-label">Type</label>
                                <select name="vehicle_type" class="form-select">
                                    <option value="">All</option>
                                    <option value="fuel" <?php echo ($vehicleTypeFilter === 'fuel') ? 'selected' : ''; ?>>Fuel</option>
                                    <option value="electric" <?php echo ($vehicleTypeFilter === 'electric') ? 'selected' : ''; ?>>Electric</option>
                                </select>
                            </div>

                            <div class="col-md-1">
                                <label class="form-label">Status</label>
                                <select name="active_status" class="form-select">
                                    <option value="">All</option>
                                    <option value="1" <?php echo ($activeFilter === '1') ? 'selected' : ''; ?>>Active</option>
                                    <option value="0" <?php echo ($activeFilter === '0') ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                            </div>

                            <div class="col-md-1 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary w-100">Go</button>
                            </div>

                            <div class="col-md-12 d-flex gap-2">
                                <a href="customer-vehicles.php" class="btn btn-light">Reset</a>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- LIST -->
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4">Customer Vehicle List</h4>

                        <div class="table-responsive">
                            <table class="table table-bordered table-striped align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Customer</th>
                                        <th>Vehicle</th>
                                        <th>Registration</th>
                                        <th>Battery / Charger</th>
                                        <th>Warranty</th>
                                        <th>Purchase</th>
                                        <th>Status</th>
                                        <th style="width:260px;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($rows)): ?>
                                        <?php $i = 1; foreach ($rows as $row): ?>
                                            <?php
                                            $today = date('Y-m-d');
                                            $warrantyStatus = 'No Warranty';
                                            $warrantyBadge = 'secondary';

                                            if (!empty($row['warranty_start_date']) && !empty($row['warranty_end_date'])) {
                                                if ($today >= $row['warranty_start_date'] && $today <= $row['warranty_end_date']) {
                                                    $warrantyStatus = 'In Warranty';
                                                    $warrantyBadge = 'success';
                                                } elseif ($today > $row['warranty_end_date']) {
                                                    $warrantyStatus = 'Expired';
                                                    $warrantyBadge = 'danger';
                                                } else {
                                                    $warrantyStatus = 'Upcoming';
                                                    $warrantyBadge = 'warning';
                                                }
                                            }
                                            ?>
                                            <tr>
                                                <td><?php echo $i++; ?></td>

                                                <td class="small">
                                                    <div><strong><?php echo h($row['customer_name'] ?: '-'); ?></strong></div>
                                                    <div class="text-muted"><?php echo h($row['customer_mobile'] ?: '-'); ?></div>
                                                    <div class="text-muted"><?php echo h($row['customer_city'] ?: '-'); ?></div>
                                                </td>

                                                <td class="small">
                                                    <div><strong><?php echo h(($row['brand_name'] ?: 'Vehicle') . ' - ' . ($row['model_name'] ?: '-')); ?></strong></div>
                                                    <div><?php echo h($row['variant_name'] ?: '-'); ?></div>
                                                    <div>Type: <?php echo h(ucfirst((string)($row['vehicle_type'] ?? '-'))); ?></div>
                                                    <div>Color: <?php echo h($row['color'] ?: '-'); ?></div>
                                                    <div>KM: <?php echo number_format((int)($row['current_km'] ?? 0)); ?></div>
                                                </td>

                                                <td class="small">
                                                    <div><strong>Reg No:</strong> <?php echo h($row['registration_no'] ?: '-'); ?></div>
                                                    <div><strong>Chassis:</strong> <?php echo h($row['chassis_no'] ?: '-'); ?></div>
                                                    <div><strong>Engine:</strong> <?php echo h($row['engine_no'] ?: '-'); ?></div>
                                                    <div><strong>Motor:</strong> <?php echo h($row['motor_no'] ?: '-'); ?></div>
                                                </td>

                                                <td class="small">
                                                    <div><strong>Battery Brand:</strong> <?php echo h($row['battery_brand'] ?: '-'); ?></div>
                                                    <div><strong>Battery No:</strong> <?php echo h($row['battery_no'] ?: '-'); ?></div>
                                                    <div><strong>Capacity:</strong> <?php echo h($row['battery_capacity'] ?: '-'); ?></div>
                                                    <div><strong>Charger Brand:</strong> <?php echo h($row['charger_brand'] ?: '-'); ?></div>
                                                    <div><strong>Charger No:</strong> <?php echo h($row['charger_no'] ?: '-'); ?></div>
                                                    <div><strong>Charger Type:</strong> <?php echo h($row['charger_type'] ?: '-'); ?></div>
                                                </td>

                                                <td class="small">
                                                    <div><span class="badge bg-<?php echo $warrantyBadge; ?>"><?php echo h($warrantyStatus); ?></span></div>
                                                    <div class="mt-1"><strong>Vehicle:</strong></div>
                                                    <div>Start: <?php echo !empty($row['warranty_start_date']) ? h(date('d M Y', strtotime($row['warranty_start_date']))) : '-'; ?></div>
                                                    <div>End: <?php echo !empty($row['warranty_end_date']) ? h(date('d M Y', strtotime($row['warranty_end_date']))) : '-'; ?></div>
                                                    <div class="mt-1"><strong>Battery:</strong> <?php echo !empty($row['battery_warranty_upto']) ? h(date('d M Y', strtotime($row['battery_warranty_upto']))) : '-'; ?></div>
                                                    <div><strong>Charger:</strong> <?php echo !empty($row['charger_warranty_upto']) ? h(date('d M Y', strtotime($row['charger_warranty_upto']))) : '-'; ?></div>
                                                </td>

                                                <td class="small">
                                                    <div><strong>Purchase:</strong> <?php echo !empty($row['purchase_date']) ? h(date('d M Y', strtotime($row['purchase_date']))) : '-'; ?></div>
                                                    <div><strong>Created:</strong> <?php echo !empty($row['created_at']) ? h(date('d M Y h:i A', strtotime($row['created_at']))) : '-'; ?></div>
                                                </td>

                                                <td>
                                                    <?php if ((int)($row['active_status'] ?? 0) === 1): ?>
                                                        <span class="badge bg-success">Active</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-danger">Inactive</span>
                                                    <?php endif; ?>
                                                </td>

                                                <td>
                                                    <div class="d-flex flex-wrap gap-2">
                                                        <a href="customer-vehicle-view.php?id=<?php echo (int)$row['id']; ?>" class="btn btn-sm btn-info">View</a>
                                                        <a href="customer-vehicle-edit.php?id=<?php echo (int)$row['id']; ?>" class="btn btn-sm btn-primary">Edit</a>
                                                        <?php if (!empty($row['customer_id'])): ?>
                                                            <a href="service-jobcard-add.php?customer_id=<?php echo (int)$row['customer_id']; ?>&customer_vehicle_id=<?php echo (int)$row['id']; ?>" class="btn btn-sm btn-success">Service</a>
                                                        <?php endif; ?>
                                                        <form method="post" style="display:inline;" onsubmit="return confirm('Delete this customer vehicle?');">
                                                            <input type="hidden" name="action" value="delete">
                                                            <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                                                            <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                                                        </form>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="9" class="text-center text-muted py-4">No customer vehicles found.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="mt-3 text-muted small">
                            Showing customer vehicle records.
                        </div>
                    </div>
                </div>

                <div class="row report-last-row">
                    <div class="col-xl-6">
                        <div class="card h-100">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Vehicle Summary</h4>
                                <table class="table table-bordered table-striped mb-0">
                                    <tbody>
                                        <tr>
                                            <th style="width:50%;">Total Vehicles</th>
                                            <td><?php echo number_format($totalVehicles); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Active Vehicles</th>
                                            <td><?php echo number_format($activeVehicles); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Inactive Vehicles</th>
                                            <td><?php echo number_format($inactiveVehicles); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Electric Vehicles</th>
                                            <td><?php echo number_format($electricVehicles); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Fuel Vehicles</th>
                                            <td><?php echo number_format($fuelVehicles); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Filtered Records</th>
                                            <td><?php echo number_format($filteredCount); ?></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-6">
                        <div class="card h-100">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Notes</h4>
                                <div class="small text-muted">
                                    <p class="mb-2">This page lists customer-owned vehicles and linked service-ready vehicle details.</p>
                                    <p class="mb-2">Use the Service button to directly create a service job card for that customer vehicle.</p>
                                    <p class="mb-0">For full workflow, also create these pages: <strong>customer-vehicle-add.php</strong>, <strong>customer-vehicle-edit.php</strong>, and <strong>customer-vehicle-view.php</strong>.</p>
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