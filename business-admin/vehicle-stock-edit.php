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
    $data = [];
    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $data[] = $row;
        }
    }
    return $data;
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
$requiredTables = ['vehicle_stock', 'vehicle_models', 'branches'];
foreach ($requiredTables as $tbl) {
    if (!tableExists($conn, $tbl)) {
        die($tbl . ' table not found.');
    }
}

/* -------------------------------------------------------
   MASTER DATA
------------------------------------------------------- */
$branches = fetchAllAssoc($conn, "SELECT id, branch_name, branch_code
                                  FROM branches
                                  WHERE business_id = {$businessId}
                                  ORDER BY branch_name ASC");

$models = fetchAllAssoc($conn, "SELECT 
                                    vm.id,
                                    vm.model_name,
                                    vm.variant_name,
                                    vb.brand_name,
                                    vm.ex_showroom_price,
                                    vm.gst_percent
                                FROM vehicle_models vm
                                LEFT JOIN vehicle_brands vb ON vb.id = vm.brand_id
                                WHERE vm.business_id = {$businessId}
                                ORDER BY vb.brand_name ASC, vm.model_name ASC");

$modelPrices = [];
foreach ($models as $m) {
    $modelPrices[$m['id']] = [
        'ex_showroom_price' => $m['ex_showroom_price'] ?? 0,
        'gst_percent' => $m['gst_percent'] ?? 0
    ];
}

$batteryBrands = [];
if (tableExists($conn, 'battery_brands')) {
    $batteryBrands = fetchAllAssoc($conn, "SELECT id, brand_name FROM battery_brands WHERE status = 1 ORDER BY brand_name ASC");
}

$chargerBrands = [];
if (tableExists($conn, 'charger_brands')) {
    $chargerBrands = fetchAllAssoc($conn, "SELECT id, brand_name FROM charger_brands WHERE status = 1 ORDER BY brand_name ASC");
}

/* -------------------------------------------------------
   GET STOCK ID
------------------------------------------------------- */
$stockId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($stockId <= 0) {
    header('Location: vehicle-stock.php');
    exit;
}

/* -------------------------------------------------------
   FETCH STOCK DETAILS
------------------------------------------------------- */
$sql = "SELECT * FROM vehicle_stock WHERE id = ? AND business_id = ? LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param('ii', $stockId, $businessId);
$stmt->execute();
$stock = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$stock) {
    header('Location: vehicle-stock.php');
    exit;
}

/* -------------------------------------------------------
   HANDLE UPDATE
------------------------------------------------------- */
$success = '';
$error = '';

$allowedStockStatus = ['in_stock', 'reserved', 'sold', 'demo', 'transferred'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $branch_id             = (int)($_POST['branch_id'] ?? 0);
    $model_id              = (int)($_POST['model_id'] ?? 0);
    $color                 = trim($_POST['color'] ?? '');
    $vin_no                = trim($_POST['vin_no'] ?? '');
    $chassis_no            = trim($_POST['chassis_no'] ?? '');
    $engine_no             = trim($_POST['engine_no'] ?? '');
    $motor_no              = trim($_POST['motor_no'] ?? '');
    $battery_brand         = trim($_POST['battery_brand'] ?? '');
    $battery_no            = trim($_POST['battery_no'] ?? '');
    $battery_capacity      = trim($_POST['battery_capacity'] ?? '');
    $battery_warranty_upto = trim($_POST['battery_warranty_upto'] ?? '');
    $charger_brand         = trim($_POST['charger_brand'] ?? '');
    $charger_no            = trim($_POST['charger_no'] ?? '');
    $charger_type          = trim($_POST['charger_type'] ?? '');
    $charger_warranty_upto = trim($_POST['charger_warranty_upto'] ?? '');
    $key_no                = trim($_POST['key_no'] ?? '');
    $manufacture_year      = trim($_POST['manufacture_year'] ?? '');
    $purchase_date         = trim($_POST['purchase_date'] ?? '');
    $supplier_name         = trim($_POST['supplier_name'] ?? '');
    $purchase_cost         = (float)($_POST['purchase_cost'] ?? 0);
    $ex_showroom_price     = (float)($_POST['ex_showroom_price'] ?? 0);
    $rto_charge            = (float)($_POST['rto_charge'] ?? 0);
    $registration_price    = (float)($_POST['registration_price'] ?? 0);
    $road_tax              = (float)($_POST['road_tax'] ?? 0);
    $insurance_price       = (float)($_POST['insurance_price'] ?? 0);
    $sale_price            = (float)($_POST['sale_price'] ?? 0);
    $stock_status          = trim($_POST['stock_status'] ?? 'in_stock');
    $remarks               = trim($_POST['remarks'] ?? '');

    if ($branch_id <= 0) {
        $error = 'Please select branch.';
    } elseif ($model_id <= 0) {
        $error = 'Please select vehicle model.';
    } elseif ($chassis_no === '') {
        $error = 'Chassis number is required.';
    } elseif (!in_array($stock_status, $allowedStockStatus, true)) {
        $error = 'Invalid stock status.';
    } elseif ($purchase_cost < 0 || $ex_showroom_price < 0 || $rto_charge < 0 || $registration_price < 0 || $road_tax < 0 || $insurance_price < 0 || $sale_price < 0) {
        $error = 'Price values cannot be negative.';
    } else {
        // Check for duplicate chassis
        $stmt = $conn->prepare("SELECT id FROM vehicle_stock WHERE business_id = ? AND chassis_no = ? AND id != ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('isi', $businessId, $chassis_no, $stockId);
            $stmt->execute();
            $dup = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($dup) {
                $error = 'Chassis number already exists.';
            }
        }

        // Check for duplicate engine
        if ($error === '' && $engine_no !== '') {
            $stmt = $conn->prepare("SELECT id FROM vehicle_stock WHERE business_id = ? AND engine_no = ? AND id != ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('isi', $businessId, $engine_no, $stockId);
                $stmt->execute();
                $dup = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($dup) {
                    $error = 'Engine number already exists.';
                }
            }
        }

        // Check for duplicate battery
        if ($error === '' && $battery_no !== '') {
            $stmt = $conn->prepare("SELECT id FROM vehicle_stock WHERE business_id = ? AND battery_no = ? AND id != ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('isi', $businessId, $battery_no, $stockId);
                $stmt->execute();
                $dup = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($dup) {
                    $error = 'Battery number already exists.';
                }
            }
        }

        // Check for duplicate charger
        if ($error === '' && $charger_no !== '') {
            $stmt = $conn->prepare("SELECT id FROM vehicle_stock WHERE business_id = ? AND charger_no = ? AND id != ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('isi', $businessId, $charger_no, $stockId);
                $stmt->execute();
                $dup = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($dup) {
                    $error = 'Charger number already exists.';
                }
            }
        }
    }

    // Calculate total if not provided
    if ($sale_price == 0 && $ex_showroom_price > 0) {
        $sale_price = $ex_showroom_price + $rto_charge + $registration_price + $road_tax + $insurance_price;
    }

    if ($error === '') {
        $stmt = $conn->prepare("UPDATE vehicle_stock SET
            branch_id = ?, model_id = ?, color = ?, vin_no = ?, chassis_no = ?, engine_no = ?, motor_no = ?,
            battery_brand = ?, battery_no = ?, battery_capacity = ?, battery_warranty_upto = ?,
            charger_brand = ?, charger_no = ?, charger_type = ?, charger_warranty_upto = ?,
            key_no = ?, manufacture_year = ?, purchase_date = ?, supplier_name = ?, purchase_cost = ?,
            ex_showroom_price = ?, rto_charge = ?, registration_price = ?, road_tax = ?, insurance_price = ?,
            sale_price = ?, stock_status = ?, remarks = ?
            WHERE id = ? AND business_id = ? LIMIT 1");

        if ($stmt) {
            $params = [
                $branch_id, $model_id, $color, $vin_no, $chassis_no, $engine_no, $motor_no,
                $battery_brand, $battery_no, $battery_capacity, $battery_warranty_upto,
                $charger_brand, $charger_no, $charger_type, $charger_warranty_upto,
                $key_no, $manufacture_year, $purchase_date, $supplier_name, $purchase_cost,
                $ex_showroom_price, $rto_charge, $registration_price, $road_tax, $insurance_price,
                $sale_price, $stock_status, $remarks, $stockId, $businessId
            ];

            $types = '';
            foreach ($params as $p) {
                if (is_int($p)) $types .= 'i';
                elseif (is_float($p)) $types .= 'd';
                else $types .= 's';
            }

            $stmt->bind_param($types, ...$params);

            if ($stmt->execute()) {
                $success = 'Vehicle stock updated successfully.';
                // Refresh stock data
                $stock = array_merge($stock, [
                    'branch_id' => $branch_id, 'model_id' => $model_id, 'color' => $color,
                    'vin_no' => $vin_no, 'chassis_no' => $chassis_no, 'engine_no' => $engine_no,
                    'motor_no' => $motor_no, 'battery_brand' => $battery_brand, 'battery_no' => $battery_no,
                    'battery_capacity' => $battery_capacity, 'battery_warranty_upto' => $battery_warranty_upto,
                    'charger_brand' => $charger_brand, 'charger_no' => $charger_no, 'charger_type' => $charger_type,
                    'charger_warranty_upto' => $charger_warranty_upto, 'key_no' => $key_no,
                    'manufacture_year' => $manufacture_year, 'purchase_date' => $purchase_date,
                    'supplier_name' => $supplier_name, 'purchase_cost' => $purchase_cost,
                    'ex_showroom_price' => $ex_showroom_price, 'rto_charge' => $rto_charge,
                    'registration_price' => $registration_price, 'road_tax' => $road_tax,
                    'insurance_price' => $insurance_price, 'sale_price' => $sale_price,
                    'stock_status' => $stock_status, 'remarks' => $remarks
                ]);
            } else {
                $error = 'Failed to update vehicle stock: ' . $stmt->error;
            }
            $stmt->close();
        } else {
            $error = 'Unable to prepare update query: ' . $conn->error;
        }
    }
}

$pageTitle = 'Edit Stock - ' . ($stock['chassis_no'] ?? 'Vehicle');
$currentPage = 'vehicle-stock';
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
    .main-content {
        min-height: calc(100vh - 70px);
    }
    .pricing-section {
        background: #f8f9fa;
        padding: 15px;
        border-radius: 8px;
        margin-bottom: 15px;
    }
    .pricing-title {
        font-weight: 600;
        margin-bottom: 15px;
        color: #495057;
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
                        <h4 class="mb-1">Edit Vehicle Stock</h4>
                        <p class="text-muted mb-0">
                            Chassis: <?php echo h($stock['chassis_no']); ?>
                        </p>
                    </div>
                    <div class="col-md-5 text-md-end mt-3 mt-md-0">
                        <div class="d-flex flex-wrap gap-2 justify-content-md-end">
                            <a href="vehicle-stock-view.php?id=<?php echo $stockId; ?>" class="btn btn-info">
                                <i class="ri-eye-line me-1"></i> View Stock
                            </a>
                            <a href="vehicle-stock.php" class="btn btn-secondary">
                                <i class="ri-arrow-go-back-line me-1"></i> Back to List
                            </a>
                        </div>
                    </div>
                </div>

                <?php if ($success !== ''): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="ri-check-line me-1"></i><?php echo h($success); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="ri-error-warning-line me-1"></i><?php echo h($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="card">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0">
                            <i class="ri-edit-line me-2"></i>Edit Stock #<?php echo $stockId; ?>
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="post" id="stockForm">
                            <div class="row">
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Branch <span class="text-danger">*</span></label>
                                    <select name="branch_id" class="form-select" required>
                                        <option value="">Select Branch</option>
                                        <?php foreach ($branches as $b): ?>
                                            <option value="<?php echo (int)$b['id']; ?>" <?php echo ((int)$stock['branch_id'] === (int)$b['id']) ? 'selected' : ''; ?>>
                                                <?php echo h($b['branch_name'] . ' (' . $b['branch_code'] . ')'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Vehicle Model <span class="text-danger">*</span></label>
                                    <select name="model_id" id="model_id" class="form-select" required onchange="updateModelPrices()">
                                        <option value="">Select Model</option>
                                        <?php foreach ($models as $m): ?>
                                            <option value="<?php echo (int)$m['id']; ?>" 
                                                <?php echo ((int)$stock['model_id'] === (int)$m['id']) ? 'selected' : ''; ?>
                                                data-ex-showroom="<?php echo $m['ex_showroom_price'] ?? 0; ?>"
                                                data-gst="<?php echo $m['gst_percent'] ?? 0; ?>">
                                                <?php echo h(($m['brand_name'] ?: '-') . ' - ' . ($m['model_name'] ?: '-') . (!empty($m['variant_name']) ? ' / ' . $m['variant_name'] : '')); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-md-2 mb-3">
                                    <label class="form-label">Color</label>
                                    <input type="text" name="color" class="form-control" value="<?php echo h($stock['color']); ?>">
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Chassis No <span class="text-danger">*</span></label>
                                    <input type="text" name="chassis_no" class="form-control" value="<?php echo h($stock['chassis_no']); ?>" required>
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Engine No</label>
                                    <input type="text" name="engine_no" class="form-control" value="<?php echo h($stock['engine_no']); ?>">
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Motor No</label>
                                    <input type="text" name="motor_no" class="form-control" value="<?php echo h($stock['motor_no']); ?>">
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">VIN No</label>
                                    <input type="text" name="vin_no" class="form-control" value="<?php echo h($stock['vin_no']); ?>">
                                </div>
                            </div>

                            <!-- Pricing Section -->
                            <div class="pricing-section">
                                <h5 class="pricing-title"><i class="ri-money-rupee-circle-line me-2"></i>Pricing Details</h5>
                                <div class="row">
                                    <div class="col-md-2 mb-3">
                                        <label class="form-label">Ex-Showroom</label>
                                        <input type="number" step="0.01" min="0" name="ex_showroom_price" id="ex_showroom_price" class="form-control" value="<?php echo h($stock['ex_showroom_price'] ?? '0.00'); ?>" onchange="calculateTotal()">
                                    </div>
                                    <div class="col-md-2 mb-3">
                                        <label class="form-label">RTO Charge</label>
                                        <input type="number" step="0.01" min="0" name="rto_charge" id="rto_charge" class="form-control" value="<?php echo h($stock['rto_charge'] ?? '0.00'); ?>" onchange="calculateTotal()">
                                    </div>
                                    <div class="col-md-2 mb-3">
                                        <label class="form-label">Registration</label>
                                        <input type="number" step="0.01" min="0" name="registration_price" id="registration_price" class="form-control" value="<?php echo h($stock['registration_price'] ?? '0.00'); ?>" onchange="calculateTotal()">
                                    </div>
                                    <div class="col-md-2 mb-3">
                                        <label class="form-label">Road Tax</label>
                                        <input type="number" step="0.01" min="0" name="road_tax" id="road_tax" class="form-control" value="<?php echo h($stock['road_tax'] ?? '0.00'); ?>" onchange="calculateTotal()">
                                    </div>
                                    <div class="col-md-2 mb-3">
                                        <label class="form-label">Insurance</label>
                                        <input type="number" step="0.01" min="0" name="insurance_price" id="insurance_price" class="form-control" value="<?php echo h($stock['insurance_price'] ?? '0.00'); ?>" onchange="calculateTotal()">
                                    </div>
                                    <div class="col-md-2 mb-3">
                                        <label class="form-label">Total Price</label>
                                        <input type="number" step="0.01" min="0" name="sale_price" id="sale_price" class="form-control" value="<?php echo h($stock['sale_price'] ?? '0.00'); ?>" readonly style="background:#e9ecef; font-weight:bold;">
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-3 mb-3">
                                        <label class="form-label">Purchase Cost</label>
                                        <input type="number" step="0.01" min="0" name="purchase_cost" class="form-control" value="<?php echo h($stock['purchase_cost'] ?? '0.00'); ?>">
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <label class="form-label">Supplier Name</label>
                                        <input type="text" name="supplier_name" class="form-control" value="<?php echo h($stock['supplier_name']); ?>">
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <label class="form-label">Purchase Date</label>
                                        <input type="date" name="purchase_date" class="form-control" value="<?php echo h($stock['purchase_date']); ?>">
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <label class="form-label">Stock Status</label>
                                        <select name="stock_status" class="form-select">
                                            <option value="in_stock" <?php echo ($stock['stock_status'] === 'in_stock') ? 'selected' : ''; ?>>In Stock</option>
                                            <option value="reserved" <?php echo ($stock['stock_status'] === 'reserved') ? 'selected' : ''; ?>>Reserved</option>
                                            <option value="sold" <?php echo ($stock['stock_status'] === 'sold') ? 'selected' : ''; ?>>Sold</option>
                                            <option value="demo" <?php echo ($stock['stock_status'] === 'demo') ? 'selected' : ''; ?>>Demo</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Battery Brand</label>
                                    <select name="battery_brand" class="form-select">
                                        <option value="">Select Battery Brand</option>
                                        <?php foreach ($batteryBrands as $bb): ?>
                                            <option value="<?php echo h($bb['brand_name']); ?>" <?php echo ($stock['battery_brand'] === $bb['brand_name']) ? 'selected' : ''; ?>>
                                                <?php echo h($bb['brand_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Battery No</label>
                                    <input type="text" name="battery_no" class="form-control" value="<?php echo h($stock['battery_no']); ?>">
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Battery Capacity</label>
                                    <input type="text" name="battery_capacity" class="form-control" value="<?php echo h($stock['battery_capacity']); ?>">
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Battery Warranty Upto</label>
                                    <input type="date" name="battery_warranty_upto" class="form-control" value="<?php echo h($stock['battery_warranty_upto']); ?>">
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Charger Brand</label>
                                    <select name="charger_brand" class="form-select">
                                        <option value="">Select Charger Brand</option>
                                        <?php foreach ($chargerBrands as $cb): ?>
                                            <option value="<?php echo h($cb['brand_name']); ?>" <?php echo ($stock['charger_brand'] === $cb['brand_name']) ? 'selected' : ''; ?>>
                                                <?php echo h($cb['brand_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Charger No</label>
                                    <input type="text" name="charger_no" class="form-control" value="<?php echo h($stock['charger_no']); ?>">
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Charger Type</label>
                                    <input type="text" name="charger_type" class="form-control" value="<?php echo h($stock['charger_type']); ?>">
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Charger Warranty Upto</label>
                                    <input type="date" name="charger_warranty_upto" class="form-control" value="<?php echo h($stock['charger_warranty_upto']); ?>">
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Key No</label>
                                    <input type="text" name="key_no" class="form-control" value="<?php echo h($stock['key_no']); ?>">
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Manufacture Year</label>
                                    <input type="number" name="manufacture_year" class="form-control" min="2000" max="2099" value="<?php echo h($stock['manufacture_year']); ?>">
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Remarks</label>
                                    <textarea name="remarks" class="form-control" rows="3"><?php echo h($stock['remarks']); ?></textarea>
                                </div>
                            </div>

                            <hr class="my-4">

                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary">
                                    <i class="ri-save-line me-1"></i> Update Stock
                                </button>
                                <a href="vehicle-stock-view.php?id=<?php echo $stockId; ?>" class="btn btn-info">
                                    <i class="ri-eye-line me-1"></i> View Stock
                                </a>
                                <a href="vehicle-stock.php" class="btn btn-secondary">
                                    <i class="ri-close-line me-1"></i> Cancel
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Stock Info Card -->
                <div class="card">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0">
                            <i class="ri-information-line me-2"></i>Stock Information
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-3">
                                <p class="text-muted mb-1">Created At</p>
                                <strong><?php echo !empty($stock['created_at']) ? date('d M Y h:i A', strtotime($stock['created_at'])) : '-'; ?></strong>
                            </div>
                            <div class="col-md-3">
                                <p class="text-muted mb-1">Stock ID</p>
                                <strong>#<?php echo $stockId; ?></strong>
                            </div>
                            <div class="col-md-3">
                                <p class="text-muted mb-1">Current Status</p>
                                <?php
                                $badge = 'secondary';
                                if ($stock['stock_status'] === 'in_stock') $badge = 'success';
                                elseif ($stock['stock_status'] === 'reserved') $badge = 'warning';
                                elseif ($stock['stock_status'] === 'sold') $badge = 'danger';
                                elseif ($stock['stock_status'] === 'demo') $badge = 'info';
                                ?>
                                <span class="badge bg-<?php echo $badge; ?>">
                                    <?php echo h(ucwords(str_replace('_', ' ', $stock['stock_status']))); ?>
                                </span>
                            </div>
                            <div class="col-md-3">
                                <p class="text-muted mb-1">Total Value</p>
                                <strong class="text-primary"><?php echo money($stock['sale_price'] ?? 0); ?></strong>
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
const modelPrices = <?php echo json_encode($modelPrices); ?>;

function updateModelPrices() {
    const modelSelect = document.getElementById('model_id');
    const selectedOption = modelSelect.options[modelSelect.selectedIndex];
    const exShowroom = selectedOption.getAttribute('data-ex-showroom') || 0;
    document.getElementById('ex_showroom_price').value = parseFloat(exShowroom).toFixed(2);
    calculateTotal();
}

function calculateTotal() {
    const exShowroom = parseFloat(document.getElementById('ex_showroom_price').value) || 0;
    const rto = parseFloat(document.getElementById('rto_charge').value) || 0;
    const reg = parseFloat(document.getElementById('registration_price').value) || 0;
    const roadTax = parseFloat(document.getElementById('road_tax').value) || 0;
    const insurance = parseFloat(document.getElementById('insurance_price').value) || 0;
    const total = exShowroom + rto + reg + roadTax + insurance;
    document.getElementById('sale_price').value = total.toFixed(2);
}

// Auto-hide alerts
setTimeout(function() {
    var alerts = document.querySelectorAll('.alert');
    alerts.forEach(function(alert) {
        var bsAlert = new bootstrap.Alert(alert);
        bsAlert.close();
    });
}, 5000);
</script>

</body>
</html>