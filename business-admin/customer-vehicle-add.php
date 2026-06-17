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
$requiredTables = ['customers'];
foreach ($requiredTables as $tbl) {
    if (!tableExists($conn, $tbl)) {
        die($tbl . ' table not found.');
    }
}

$hasVehicleBrands = tableExists($conn, 'vehicle_brands');
$hasVehicleModels = tableExists($conn, 'vehicle_models');
$hasVehicleStock = tableExists($conn, 'vehicle_stock');

/* -------------------------------------------------------
   GET CUSTOMER ID FROM URL (optional)
------------------------------------------------------- */
$preSelectedCustomerId = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;

/* -------------------------------------------------------
   FETCH MASTER DATA
------------------------------------------------------- */
// Fetch customers for dropdown
$customers = fetchAllAssoc(
    $conn,
    "SELECT id, full_name, mobile, email, city
     FROM customers
     WHERE business_id = {$businessId}
     ORDER BY full_name ASC"
);

// Fetch vehicle brands
$brands = [];
if ($hasVehicleBrands) {
    $brands = fetchAllAssoc(
        $conn,
        "SELECT id, brand_name
         FROM vehicle_brands
         WHERE status = 1
         ORDER BY brand_name ASC"
    );
}

// Fetch vehicle models
$models = [];
if ($hasVehicleModels) {
    $models = fetchAllAssoc(
        $conn,
        "SELECT id, model_name, variant_name, brand_id, vehicle_type, 
                battery_capacity, charger_type
         FROM vehicle_models
         WHERE business_id = {$businessId} AND status = 1
         ORDER BY model_name ASC, variant_name ASC"
    );
}

// Fetch available stock vehicles (optional linking)
$stockVehicles = [];
if ($hasVehicleStock) {
    $stockVehicles = fetchAllAssoc(
        $conn,
        "SELECT vs.id, vs.chassis_no, vs.engine_no, vs.motor_no, vs.color,
                vm.model_name, vm.variant_name, vb.brand_name
         FROM vehicle_stock vs
         LEFT JOIN vehicle_models vm ON vm.id = vs.model_id
         LEFT JOIN vehicle_brands vb ON vb.id = vm.brand_id
         WHERE vs.business_id = {$businessId} 
           AND vs.stock_status IN ('in_stock', 'reserved')
         ORDER BY vs.id DESC"
    );
}

/* -------------------------------------------------------
   HANDLE FORM SUBMISSION
------------------------------------------------------- */
$success = '';
$error = '';
$formData = [
    'customer_id' => $preSelectedCustomerId,
    'vehicle_stock_id' => '',
    'brand_id' => '',
    'model_id' => '',
    'vehicle_type' => 'fuel',
    'registration_no' => '',
    'chassis_no' => '',
    'engine_no' => '',
    'motor_no' => '',
    'battery_brand' => '',
    'battery_no' => '',
    'battery_capacity' => '',
    'battery_warranty_upto' => '',
    'charger_brand' => '',
    'charger_no' => '',
    'charger_type' => '',
    'charger_warranty_upto' => '',
    'color' => '',
    'purchase_date' => '',
    'warranty_start_date' => '',
    'warranty_end_date' => '',
    'current_km' => 0,
    'active_status' => 1,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Collect form data
    $formData['customer_id'] = isset($_POST['customer_id']) ? (int)$_POST['customer_id'] : 0;
    $formData['vehicle_stock_id'] = isset($_POST['vehicle_stock_id']) && $_POST['vehicle_stock_id'] !== '' ? (int)$_POST['vehicle_stock_id'] : null;
    $formData['brand_id'] = isset($_POST['brand_id']) && $_POST['brand_id'] !== '' ? (int)$_POST['brand_id'] : null;
    $formData['model_id'] = isset($_POST['model_id']) && $_POST['model_id'] !== '' ? (int)$_POST['model_id'] : null;
    $formData['vehicle_type'] = trim($_POST['vehicle_type'] ?? 'fuel');
    $formData['registration_no'] = trim($_POST['registration_no'] ?? '');
    $formData['chassis_no'] = trim($_POST['chassis_no'] ?? '');
    $formData['engine_no'] = trim($_POST['engine_no'] ?? '');
    $formData['motor_no'] = trim($_POST['motor_no'] ?? '');
    $formData['battery_brand'] = trim($_POST['battery_brand'] ?? '');
    $formData['battery_no'] = trim($_POST['battery_no'] ?? '');
    $formData['battery_capacity'] = trim($_POST['battery_capacity'] ?? '');
    $formData['battery_warranty_upto'] = !empty($_POST['battery_warranty_upto']) ? $_POST['battery_warranty_upto'] : null;
    $formData['charger_brand'] = trim($_POST['charger_brand'] ?? '');
    $formData['charger_no'] = trim($_POST['charger_no'] ?? '');
    $formData['charger_type'] = trim($_POST['charger_type'] ?? '');
    $formData['charger_warranty_upto'] = !empty($_POST['charger_warranty_upto']) ? $_POST['charger_warranty_upto'] : null;
    $formData['color'] = trim($_POST['color'] ?? '');
    $formData['purchase_date'] = !empty($_POST['purchase_date']) ? $_POST['purchase_date'] : null;
    $formData['warranty_start_date'] = !empty($_POST['warranty_start_date']) ? $_POST['warranty_start_date'] : null;
    $formData['warranty_end_date'] = !empty($_POST['warranty_end_date']) ? $_POST['warranty_end_date'] : null;
    $formData['current_km'] = isset($_POST['current_km']) ? (int)$_POST['current_km'] : 0;
    $formData['active_status'] = isset($_POST['active_status']) ? 1 : 0;

    // Validate required fields
    if ($formData['customer_id'] <= 0) {
        $error = 'Please select a customer.';
    } elseif (!in_array($formData['vehicle_type'], ['fuel', 'electric'], true)) {
        $error = 'Invalid vehicle type.';
    } else {
        // Check if chassis number is unique (if provided)
        if (!empty($formData['chassis_no'])) {
            $stmt = $conn->prepare("SELECT id FROM customer_vehicles 
                                   WHERE business_id = ? AND chassis_no = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('is', $businessId, $formData['chassis_no']);
                $stmt->execute();
                $exists = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($exists) {
                    $error = 'Chassis number already exists in system.';
                }
            }
        }

        // Check if registration number is unique (if provided)
        if ($error === '' && !empty($formData['registration_no'])) {
            $stmt = $conn->prepare("SELECT id FROM customer_vehicles 
                                   WHERE business_id = ? AND registration_no = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('is', $businessId, $formData['registration_no']);
                $stmt->execute();
                $exists = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($exists) {
                    $error = 'Registration number already exists in system.';
                }
            }
        }
    }

    // If no errors, insert the record
    if ($error === '') {
        $conn->begin_transaction();

        try {
            $sql = "INSERT INTO customer_vehicles (
                        business_id, customer_id, vehicle_stock_id, brand_id, model_id,
                        vehicle_type, registration_no, chassis_no, engine_no, motor_no,
                        battery_brand, battery_no, battery_capacity, battery_warranty_upto,
                        charger_brand, charger_no, charger_type, charger_warranty_upto,
                        color, purchase_date, warranty_start_date, warranty_end_date,
                        current_km, active_status, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new Exception('Failed to prepare insert query.');
            }

            $stmt->bind_param(
                'iiiiisssssssssssssssssii',
                $businessId,
                $formData['customer_id'],
                $formData['vehicle_stock_id'],
                $formData['brand_id'],
                $formData['model_id'],
                $formData['vehicle_type'],
                $formData['registration_no'],
                $formData['chassis_no'],
                $formData['engine_no'],
                $formData['motor_no'],
                $formData['battery_brand'],
                $formData['battery_no'],
                $formData['battery_capacity'],
                $formData['battery_warranty_upto'],
                $formData['charger_brand'],
                $formData['charger_no'],
                $formData['charger_type'],
                $formData['charger_warranty_upto'],
                $formData['color'],
                $formData['purchase_date'],
                $formData['warranty_start_date'],
                $formData['warranty_end_date'],
                $formData['current_km'],
                $formData['active_status']
            );

            if (!$stmt->execute()) {
                throw new Exception('Failed to add vehicle: ' . $stmt->error);
            }

            $newId = $stmt->insert_id;
            $stmt->close();

            // If linked to stock vehicle, update stock status to 'sold' or 'reserved'
            if (!empty($formData['vehicle_stock_id']) && $hasVehicleStock) {
                $updateStock = $conn->prepare("UPDATE vehicle_stock 
                                              SET stock_status = 'sold' 
                                              WHERE id = ? AND business_id = ?");
                if ($updateStock) {
                    $updateStock->bind_param('ii', $formData['vehicle_stock_id'], $businessId);
                    $updateStock->execute();
                    $updateStock->close();
                }
            }

            // Add to audit log
            if (tableExists($conn, 'audit_logs')) {
                $auditSql = "INSERT INTO audit_logs (business_id, branch_id, user_id, action, 
                             module_name, ref_table, ref_id, description) 
                             VALUES (?, ?, ?, 'Add Vehicle', 'Customer Vehicles', 
                             'customer_vehicles', ?, ?)";
                $auditStmt = $conn->prepare($auditSql);
                if ($auditStmt) {
                    $desc = "Added vehicle for customer ID: " . $formData['customer_id'] . 
                            " (Reg: " . ($formData['registration_no'] ?: 'Not registered') . ")";
                    $branchId = $_SESSION['branch_id'] ?? null;
                    $auditStmt->bind_param('iiiis', $businessId, $branchId, $businessUserId, $newId, $desc);
                    $auditStmt->execute();
                    $auditStmt->close();
                }
            }

            $conn->commit();
            
            // Redirect back to customer-vehicles page with success message
            header('Location: customer-vehicles.php?success=' . urlencode('Customer vehicle added successfully.'));
            exit;
            
        } catch (Exception $e) {
            $conn->rollback();
            $error = $e->getMessage();
        }
    }
}

$pageTitle = 'Add Customer Vehicle';
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
    .card {
        margin-bottom: 24px;
    }
    .main-content {
        min-height: calc(100vh - 70px);
    }
    .required-field::after {
        content: " *";
        color: red;
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
                    <div class="col-md-8">
                        <h4 class="mb-1">Add Customer Vehicle</h4>
                        <p class="text-muted mb-0">Register a new vehicle for a customer</p>
                    </div>
                    <div class="col-md-4 text-md-end mt-3 mt-md-0">
                        <a href="customer-vehicles.php" class="btn btn-secondary">Back to List</a>
                    </div>
                </div>

                <?php if ($success !== ''): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?php echo h($success); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?php echo h($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <div class="card">
                    <div class="card-body">
                        <form method="post" id="vehicleForm">
                            <!-- Customer Selection -->
                            <div class="row mb-4">
                                <div class="col-12">
                                    <h5 class="card-title mb-3">Customer Information</h5>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label required-field">Select Customer</label>
                                    <select name="customer_id" class="form-select" required>
                                        <option value="">-- Select Customer --</option>
                                        <?php foreach ($customers as $cust): ?>
                                            <option value="<?php echo $cust['id']; ?>" 
                                                <?php echo ($formData['customer_id'] == $cust['id']) ? 'selected' : ''; ?>>
                                                <?php echo h($cust['full_name']); ?> 
                                                (<?php echo h($cust['mobile']); ?>)
                                                <?php echo !empty($cust['city']) ? ' - ' . h($cust['city']) : ''; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text">
                                        <a href="customers.php" target="_blank">Add new customer</a> if not listed.
                                    </div>
                                </div>

                                <?php if (!empty($stockVehicles)): ?>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Link to Stock Vehicle (Optional)</label>
                                    <select name="vehicle_stock_id" class="form-select" id="stockVehicle">
                                        <option value="">-- Not Linked to Stock --</option>
                                        <?php foreach ($stockVehicles as $stock): ?>
                                            <option value="<?php echo $stock['id']; ?>" 
                                                data-brand="<?php echo h($stock['brand_name'] ?? ''); ?>"
                                                data-model="<?php echo h($stock['model_name'] ?? ''); ?>"
                                                data-variant="<?php echo h($stock['variant_name'] ?? ''); ?>"
                                                data-chassis="<?php echo h($stock['chassis_no'] ?? ''); ?>"
                                                data-engine="<?php echo h($stock['engine_no'] ?? ''); ?>"
                                                data-motor="<?php echo h($stock['motor_no'] ?? ''); ?>"
                                                data-color="<?php echo h($stock['color'] ?? ''); ?>">
                                                <?php 
                                                echo h(($stock['brand_name'] ?? 'Unknown') . ' ' . 
                                                      ($stock['model_name'] ?? '') . ' ' . 
                                                      ($stock['variant_name'] ?? '')); 
                                                ?> - 
                                                <?php echo h($stock['chassis_no'] ?? 'No Chassis'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text">Select to auto-fill vehicle details from stock</div>
                                </div>
                                <?php endif; ?>
                            </div>

                            <!-- Vehicle Type -->
                            <div class="row mb-4">
                                <div class="col-12">
                                    <h5 class="card-title mb-3">Vehicle Type</h5>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="radio" name="vehicle_type" 
                                               id="typeFuel" value="fuel" 
                                               <?php echo $formData['vehicle_type'] === 'fuel' ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="typeFuel">Fuel Vehicle</label>
                                    </div>
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="radio" name="vehicle_type" 
                                               id="typeElectric" value="electric"
                                               <?php echo $formData['vehicle_type'] === 'electric' ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="typeElectric">Electric Vehicle</label>
                                    </div>
                                </div>
                            </div>

                            <!-- Vehicle Details -->
                            <div class="row mb-4">
                                <div class="col-12">
                                    <h5 class="card-title mb-3">Vehicle Details</h5>
                                </div>
                                
                                <?php if ($hasVehicleBrands): ?>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Brand</label>
                                    <select name="brand_id" class="form-select" id="brandSelect">
                                        <option value="">-- Select Brand --</option>
                                        <?php foreach ($brands as $brand): ?>
                                            <option value="<?php echo $brand['id']; ?>" 
                                                <?php echo ($formData['brand_id'] == $brand['id']) ? 'selected' : ''; ?>>
                                                <?php echo h($brand['brand_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <?php endif; ?>

                                <?php if ($hasVehicleModels): ?>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Model</label>
                                    <select name="model_id" class="form-select" id="modelSelect">
                                        <option value="">-- Select Model --</option>
                                        <?php foreach ($models as $model): ?>
                                            <option value="<?php echo $model['id']; ?>" 
                                                data-brand="<?php echo $model['brand_id']; ?>"
                                                data-type="<?php echo $model['vehicle_type'] ?? ''; ?>"
                                                <?php echo ($formData['model_id'] == $model['id']) ? 'selected' : ''; ?>>
                                                <?php 
                                                echo h($model['model_name']); 
                                                if (!empty($model['variant_name'])) {
                                                    echo ' - ' . h($model['variant_name']);
                                                }
                                                ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <?php endif; ?>

                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Color</label>
                                    <input type="text" name="color" class="form-control" 
                                           value="<?php echo h($formData['color']); ?>" 
                                           placeholder="e.g., Red, Black, Blue">
                                </div>

                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Registration Number</label>
                                    <input type="text" name="registration_no" class="form-control" 
                                           value="<?php echo h($formData['registration_no']); ?>" 
                                           placeholder="e.g., TN23 AB 1234">
                                </div>

                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Chassis Number</label>
                                    <input type="text" name="chassis_no" class="form-control" 
                                           value="<?php echo h($formData['chassis_no']); ?>" 
                                           placeholder="17-character VIN">
                                </div>

                                <div class="col-md-4 mb-3 fuel-field">
                                    <label class="form-label">Engine Number</label>
                                    <input type="text" name="engine_no" class="form-control" 
                                           value="<?php echo h($formData['engine_no']); ?>">
                                </div>

                                <div class="col-md-4 mb-3 electric-field" style="display: <?php echo $formData['vehicle_type'] === 'electric' ? 'block' : 'none'; ?>;">
                                    <label class="form-label">Motor Number</label>
                                    <input type="text" name="motor_no" class="form-control" 
                                           value="<?php echo h($formData['motor_no']); ?>">
                                </div>
                            </div>

                            <!-- Purchase & Warranty -->
                            <div class="row mb-4">
                                <div class="col-12">
                                    <h5 class="card-title mb-3">Purchase & Warranty Information</h5>
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Purchase Date</label>
                                    <input type="date" name="purchase_date" class="form-control" 
                                           value="<?php echo h($formData['purchase_date']); ?>">
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Warranty Start Date</label>
                                    <input type="date" name="warranty_start_date" class="form-control" 
                                           value="<?php echo h($formData['warranty_start_date']); ?>">
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Warranty End Date</label>
                                    <input type="date" name="warranty_end_date" class="form-control" 
                                           value="<?php echo h($formData['warranty_end_date']); ?>">
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Current KM Reading</label>
                                    <input type="number" name="current_km" class="form-control" 
                                           value="<?php echo h($formData['current_km']); ?>" min="0">
                                </div>
                            </div>

                            <!-- Battery Details (Electric) -->
                            <div class="row mb-4 electric-details" style="display: <?php echo $formData['vehicle_type'] === 'electric' ? 'block' : 'none'; ?>;">
                                <div class="col-12">
                                    <h5 class="card-title mb-3">Battery Details (Electric Vehicles)</h5>
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Battery Brand</label>
                                    <input type="text" name="battery_brand" class="form-control" 
                                           value="<?php echo h($formData['battery_brand']); ?>">
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Battery Number</label>
                                    <input type="text" name="battery_no" class="form-control" 
                                           value="<?php echo h($formData['battery_no']); ?>">
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Battery Capacity</label>
                                    <input type="text" name="battery_capacity" class="form-control" 
                                           value="<?php echo h($formData['battery_capacity']); ?>" 
                                           placeholder="e.g., 48V 20Ah">
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Battery Warranty Upto</label>
                                    <input type="date" name="battery_warranty_upto" class="form-control" 
                                           value="<?php echo h($formData['battery_warranty_upto']); ?>">
                                </div>
                            </div>

                            <!-- Charger Details (Electric) -->
                            <div class="row mb-4 electric-details" style="display: <?php echo $formData['vehicle_type'] === 'electric' ? 'block' : 'none'; ?>;">
                                <div class="col-12">
                                    <h5 class="card-title mb-3">Charger Details (Electric Vehicles)</h5>
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Charger Brand</label>
                                    <input type="text" name="charger_brand" class="form-control" 
                                           value="<?php echo h($formData['charger_brand']); ?>">
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Charger Number</label>
                                    <input type="text" name="charger_no" class="form-control" 
                                           value="<?php echo h($formData['charger_no']); ?>">
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Charger Type</label>
                                    <select name="charger_type" class="form-select">
                                        <option value="">-- Select Type --</option>
                                        <option value="portable" <?php echo $formData['charger_type'] === 'portable' ? 'selected' : ''; ?>>Portable</option>
                                        <option value="fixed" <?php echo $formData['charger_type'] === 'fixed' ? 'selected' : ''; ?>>Fixed</option>
                                        <option value="fast" <?php echo $formData['charger_type'] === 'fast' ? 'selected' : ''; ?>>Fast Charger</option>
                                        <option value="standard" <?php echo $formData['charger_type'] === 'standard' ? 'selected' : ''; ?>>Standard</option>
                                    </select>
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Charger Warranty Upto</label>
                                    <input type="date" name="charger_warranty_upto" class="form-control" 
                                           value="<?php echo h($formData['charger_warranty_upto']); ?>">
                                </div>
                            </div>

                            <!-- Status -->
                            <div class="row mb-4">
                                <div class="col-12">
                                    <h5 class="card-title mb-3">Status</h5>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="active_status" 
                                               id="activeStatus" value="1" 
                                               <?php echo $formData['active_status'] ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="activeStatus">
                                            Active (Vehicle is currently with customer)
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <!-- Form Actions -->
                            <div class="row mt-4">
                                <div class="col-12">
                                    <button type="submit" class="btn btn-primary">Add Vehicle</button>
                                    <a href="customer-vehicles.php" class="btn btn-secondary ms-2">Cancel</a>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Info Card -->
                <div class="row">
                    <div class="col-12">
                        <div class="card bg-light">
                            <div class="card-body">
                                <div class="d-flex">
                                    <div class="flex-shrink-0 me-3">
                                        <i class="ri-information-line" style="font-size: 2rem; color: #6c757d;"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <h5 class="mb-2">About Customer Vehicles</h5>
                                        <p class="text-muted mb-0">
                                            Register vehicles owned by customers. This information is used for service job cards, 
                                            warranty tracking, and vehicle history. For electric vehicles, battery and charger 
                                            details are mandatory for service tracking.
                                        </p>
                                    </div>
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
// Auto-hide alerts after 5 seconds
setTimeout(function() {
    var alerts = document.querySelectorAll('.alert');
    alerts.forEach(function(alert) {
        var bsAlert = new bootstrap.Alert(alert);
        bsAlert.close();
    });
}, 5000);

// Toggle between fuel and electric fields
document.querySelectorAll('input[name="vehicle_type"]').forEach(function(radio) {
    radio.addEventListener('change', function() {
        if (this.value === 'electric') {
            document.querySelectorAll('.electric-field, .electric-details').forEach(function(el) {
                el.style.display = 'block';
            });
            document.querySelectorAll('.fuel-field').forEach(function(el) {
                el.style.display = 'none';
            });
        } else {
            document.querySelectorAll('.electric-field, .electric-details').forEach(function(el) {
                el.style.display = 'none';
            });
            document.querySelectorAll('.fuel-field').forEach(function(el) {
                el.style.display = 'block';
            });
        }
    });
});

// Auto-fill from stock vehicle selection
<?php if (!empty($stockVehicles)): ?>
document.getElementById('stockVehicle')?.addEventListener('change', function() {
    if (this.value) {
        var selected = this.options[this.selectedIndex];
        
        // Set vehicle type (determine from model if possible)
        // You may need to add data-type attribute to options
        
        // Set brand/model if available
        <?php if ($hasVehicleBrands): ?>
        // Try to match brand by name (simplified - you might need a more sophisticated approach)
        var brandName = selected.dataset.brand;
        if (brandName) {
            var brandSelect = document.getElementById('brandSelect');
            for (var i = 0; i < brandSelect.options.length; i++) {
                if (brandSelect.options[i].text.toLowerCase() === brandName.toLowerCase()) {
                    brandSelect.value = brandSelect.options[i].value;
                    break;
                }
            }
        }
        <?php endif; ?>
        
        // Set chassis, engine, motor, color
        document.querySelector('input[name="chassis_no"]').value = selected.dataset.chassis || '';
        document.querySelector('input[name="engine_no"]').value = selected.dataset.engine || '';
        document.querySelector('input[name="motor_no"]').value = selected.dataset.motor || '';
        document.querySelector('input[name="color"]').value = selected.dataset.color || '';
        
        // If it's an electric vehicle from stock, set appropriate type
        if (selected.dataset.motor) {
            document.getElementById('typeElectric').checked = true;
            // Trigger change event
            var event = new Event('change');
            document.getElementById('typeElectric').dispatchEvent(event);
        }
    }
});
<?php endif; ?>

// Model selection - filter by brand if possible
<?php if ($hasVehicleBrands && $hasVehicleModels): ?>
document.getElementById('brandSelect')?.addEventListener('change', function() {
    var brandId = this.value;
    var modelSelect = document.getElementById('modelSelect');
    
    for (var i = 0; i < modelSelect.options.length; i++) {
        var option = modelSelect.options[i];
        if (option.value === '') continue;
        
        if (brandId === '' || option.dataset.brand === brandId) {
            option.style.display = '';
        } else {
            option.style.display = 'none';
        }
    }
});

// Trigger on page load
document.getElementById('brandSelect')?.dispatchEvent(new Event('change'));
<?php endif; ?>
</script>

</body>
</html>