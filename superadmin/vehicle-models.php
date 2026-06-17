<?php
date_default_timezone_set('Asia/Kolkata');
require_once 'includes/config.php';

if (!isset($_SESSION['platform_admin_id']) || (int)$_SESSION['platform_admin_id'] <= 0) {
    header("Location: login.php");
    exit;
}

function h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function money($amount)
{
    return '₹' . number_format((float)$amount, 2);
}

$success = '';
$error   = '';

/* -------------------------------------------------------
   DELETE MODEL
------------------------------------------------------- */
if (isset($_GET['delete']) && $_GET['delete'] !== '') {
    $deleteId = (int)$_GET['delete'];

    if ($deleteId > 0) {
        $check = $conn->prepare("SELECT COUNT(*) AS total FROM vehicle_stock WHERE model_id = ?");
        if ($check) {
            $check->bind_param("i", $deleteId);
            $check->execute();
            $res = $check->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            $usedCount = (int)($row['total'] ?? 0);
            $check->close();

            if ($usedCount > 0) {
                $error = 'This vehicle model is already used in stock, so it cannot be deleted.';
            } else {
                $stmt = $conn->prepare("DELETE FROM vehicle_models WHERE id = ? LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param("i", $deleteId);
                    if ($stmt->execute()) {
                        $success = 'Vehicle model deleted successfully.';
                    } else {
                        $error = 'Failed to delete vehicle model.';
                    }
                    $stmt->close();
                } else {
                    $error = 'Database error while deleting vehicle model.';
                }
            }
        } else {
            $error = 'Database error while checking model usage.';
        }
    }
}

/* -------------------------------------------------------
   FETCH MASTER DATA
------------------------------------------------------- */
$businesses = [];
$res = $conn->query("SELECT id, business_name, business_code FROM businesses WHERE status = 'active' ORDER BY business_name ASC");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $businesses[] = $row;
    }
}

$brands = [];
$res = $conn->query("SELECT id, brand_name FROM vehicle_brands WHERE status = 1 ORDER BY brand_name ASC");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $brands[] = $row;
    }
}

$categories = [];
$res = $conn->query("SELECT id, category_name FROM vehicle_categories ORDER BY category_name ASC");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $categories[] = $row;
    }
}

/* -------------------------------------------------------
   ADD / UPDATE MODEL
------------------------------------------------------- */
$editId = (int)($_GET['edit'] ?? 0);
$editModel = null;

if ($editId > 0) {
    $stmt = $conn->prepare("
        SELECT *
        FROM vehicle_models
        WHERE id = ?
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param("i", $editId);
        $stmt->execute();
        $result = $stmt->get_result();
        $editModel = $result ? $result->fetch_assoc() : null;
        $stmt->close();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $model_id             = (int)($_POST['model_id'] ?? 0);
    $business_id          = (int)($_POST['business_id'] ?? 0);
    $brand_id             = (int)($_POST['brand_id'] ?? 0);
    $category_id          = (int)($_POST['category_id'] ?? 0);
    $model_name           = trim($_POST['model_name'] ?? '');
    $variant_name         = trim($_POST['variant_name'] ?? '');
    $vehicle_type         = trim($_POST['vehicle_type'] ?? 'fuel');
    $fuel_type            = trim($_POST['fuel_type'] ?? '');
    $transmission         = trim($_POST['transmission'] ?? '');
    $engine_cc            = trim($_POST['engine_cc'] ?? '');
    $mileage              = trim($_POST['mileage'] ?? '');
    $battery_capacity     = trim($_POST['battery_capacity'] ?? '');
    $motor_power          = trim($_POST['motor_power'] ?? '');
    $range_km             = trim($_POST['range_km'] ?? '');
    $charging_time        = trim($_POST['charging_time'] ?? '');
    $battery_brand        = trim($_POST['battery_brand'] ?? '');
    $charger_brand        = trim($_POST['charger_brand'] ?? '');
    $charger_type         = trim($_POST['charger_type'] ?? '');
    $color_options        = trim($_POST['color_options'] ?? '');
    $ex_showroom_price    = (float)($_POST['ex_showroom_price'] ?? 0);
    $insurance_price      = (float)($_POST['insurance_price'] ?? 0);
    $registration_price   = (float)($_POST['registration_price'] ?? 0);
    $rto_charge           = (float)($_POST['rto_charge'] ?? 0);
    $road_tax             = (float)($_POST['road_tax'] ?? 0);
    $hypothecation_charge = (float)($_POST['hypothecation_charge'] ?? 0);
    $other_charge         = (float)($_POST['other_charge'] ?? 0);
    $gst_percent          = (float)($_POST['gst_percent'] ?? 0);
    $cess_percent         = (float)($_POST['cess_percent'] ?? 0);
    $hsn_code             = trim($_POST['hsn_code'] ?? '');
    $status               = isset($_POST['status']) ? 1 : 0;

    if ($business_id <= 0 || $brand_id <= 0 || $category_id <= 0 || $model_name === '' || $vehicle_type === '') {
        $error = 'Business, brand, category, model name and vehicle type are required.';
    } elseif (!in_array($vehicle_type, ['fuel', 'electric'], true)) {
        $error = 'Invalid vehicle type.';
    } else {
        if ($model_id > 0) {
            $check = $conn->prepare("
                SELECT id 
                FROM vehicle_models 
                WHERE business_id = ? 
                  AND brand_id = ? 
                  AND model_name = ? 
                  AND IFNULL(variant_name,'') = IFNULL(?, '')
                  AND id != ?
                LIMIT 1
            ");
            if ($check) {
                $check->bind_param("iissi", $business_id, $brand_id, $model_name, $variant_name, $model_id);
                $check->execute();
                $res = $check->get_result();
                $exists = $res ? $res->fetch_assoc() : null;
                $check->close();

                if ($exists) {
                    $error = 'This model/variant already exists for the selected business and brand.';
                } else {
                    $stmt = $conn->prepare("
                        UPDATE vehicle_models SET
                            business_id = ?,
                            brand_id = ?,
                            category_id = ?,
                            model_name = ?,
                            variant_name = ?,
                            vehicle_type = ?,
                            fuel_type = ?,
                            transmission = ?,
                            engine_cc = ?,
                            mileage = ?,
                            battery_capacity = ?,
                            motor_power = ?,
                            range_km = ?,
                            charging_time = ?,
                            battery_brand = ?,
                            charger_brand = ?,
                            charger_type = ?,
                            color_options = ?,
                            ex_showroom_price = ?,
                            insurance_price = ?,
                            registration_price = ?,
                            rto_charge = ?,
                            road_tax = ?,
                            hypothecation_charge = ?,
                            other_charge = ?,
                            gst_percent = ?,
                            cess_percent = ?,
                            hsn_code = ?,
                            status = ?
                        WHERE id = ?
                        LIMIT 1
                    ");

                    if ($stmt) {
                        $stmt->bind_param(
                            "iiisssssssssssssssdddddddddsii",
                            $business_id,
                            $brand_id,
                            $category_id,
                            $model_name,
                            $variant_name,
                            $vehicle_type,
                            $fuel_type,
                            $transmission,
                            $engine_cc,
                            $mileage,
                            $battery_capacity,
                            $motor_power,
                            $range_km,
                            $charging_time,
                            $battery_brand,
                            $charger_brand,
                            $charger_type,
                            $color_options,
                            $ex_showroom_price,
                            $insurance_price,
                            $registration_price,
                            $rto_charge,
                            $road_tax,
                            $hypothecation_charge,
                            $other_charge,
                            $gst_percent,
                            $cess_percent,
                            $hsn_code,
                            $status,
                            $model_id
                        );

                        if ($stmt->execute()) {
                            $success = 'Vehicle model updated successfully.';
                            $editModel = null;
                            $_GET['edit'] = null;
                        } else {
                            $error = 'Failed to update vehicle model.';
                        }
                        $stmt->close();
                    } else {
                        $error = 'Database error while updating model.';
                    }
                }
            } else {
                $error = 'Database error while checking model.';
            }
        } else {
            $check = $conn->prepare("
                SELECT id 
                FROM vehicle_models 
                WHERE business_id = ? 
                  AND brand_id = ? 
                  AND model_name = ? 
                  AND IFNULL(variant_name,'') = IFNULL(?, '')
                LIMIT 1
            ");
            if ($check) {
                $check->bind_param("iiss", $business_id, $brand_id, $model_name, $variant_name);
                $check->execute();
                $res = $check->get_result();
                $exists = $res ? $res->fetch_assoc() : null;
                $check->close();

                if ($exists) {
                    $error = 'This model/variant already exists for the selected business and brand.';
                } else {
                    $stmt = $conn->prepare("
                        INSERT INTO vehicle_models (
                            business_id,
                            brand_id,
                            category_id,
                            model_name,
                            variant_name,
                            vehicle_type,
                            fuel_type,
                            transmission,
                            engine_cc,
                            mileage,
                            battery_capacity,
                            motor_power,
                            range_km,
                            charging_time,
                            battery_brand,
                            charger_brand,
                            charger_type,
                            color_options,
                            ex_showroom_price,
                            insurance_price,
                            registration_price,
                            rto_charge,
                            road_tax,
                            hypothecation_charge,
                            other_charge,
                            gst_percent,
                            cess_percent,
                            hsn_code,
                            status,
                            created_at
                        ) VALUES (
                            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()
                        )
                    ");

                    if ($stmt) {
                        $stmt->bind_param(
                            "iiisssssssssssssssdddddddddsi",
                            $business_id,
                            $brand_id,
                            $category_id,
                            $model_name,
                            $variant_name,
                            $vehicle_type,
                            $fuel_type,
                            $transmission,
                            $engine_cc,
                            $mileage,
                            $battery_capacity,
                            $motor_power,
                            $range_km,
                            $charging_time,
                            $battery_brand,
                            $charger_brand,
                            $charger_type,
                            $color_options,
                            $ex_showroom_price,
                            $insurance_price,
                            $registration_price,
                            $rto_charge,
                            $road_tax,
                            $hypothecation_charge,
                            $other_charge,
                            $gst_percent,
                            $cess_percent,
                            $hsn_code,
                            $status
                        );

                        if ($stmt->execute()) {
                            $success = 'Vehicle model added successfully.';
                        } else {
                            $error = 'Failed to add vehicle model.';
                        }
                        $stmt->close();
                    } else {
                        $error = 'Database error while adding model.';
                    }
                }
            } else {
                $error = 'Database error while checking model.';
            }
        }
    }
}

/* -------------------------------------------------------
   FILTERS
------------------------------------------------------- */
$search = trim($_GET['search'] ?? '');
$filter_business_id = (int)($_GET['filter_business_id'] ?? 0);
$filter_brand_id = (int)($_GET['filter_brand_id'] ?? 0);
$filter_category_id = (int)($_GET['filter_category_id'] ?? 0);
$filter_vehicle_type = trim($_GET['filter_vehicle_type'] ?? '');
$filter_status = trim($_GET['filter_status'] ?? '');

$where = " WHERE 1=1 ";
$params = [];
$types = "";

if ($search !== '') {
    $where .= " AND (
        vm.model_name LIKE ?
        OR vm.variant_name LIKE ?
        OR vb.brand_name LIKE ?
        OR b.business_name LIKE ?
    ) ";
    $like = "%{$search}%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= "ssss";
}

if ($filter_business_id > 0) {
    $where .= " AND vm.business_id = ? ";
    $params[] = $filter_business_id;
    $types .= "i";
}

if ($filter_brand_id > 0) {
    $where .= " AND vm.brand_id = ? ";
    $params[] = $filter_brand_id;
    $types .= "i";
}

if ($filter_category_id > 0) {
    $where .= " AND vm.category_id = ? ";
    $params[] = $filter_category_id;
    $types .= "i";
}

if ($filter_vehicle_type !== '' && in_array($filter_vehicle_type, ['fuel', 'electric'], true)) {
    $where .= " AND vm.vehicle_type = ? ";
    $params[] = $filter_vehicle_type;
    $types .= "s";
}

if ($filter_status !== '' && in_array($filter_status, ['1', '0'], true)) {
    $where .= " AND vm.status = ? ";
    $params[] = (int)$filter_status;
    $types .= "i";
}

/* -------------------------------------------------------
   FETCH MODELS
------------------------------------------------------- */
$models = [];

$sql = "
    SELECT 
        vm.*,
        b.business_name,
        b.business_code,
        vb.brand_name,
        vc.category_name,
        (
            SELECT COUNT(*) 
            FROM vehicle_stock vs 
            WHERE vs.model_id = vm.id
        ) AS stock_count
    FROM vehicle_models vm
    INNER JOIN businesses b ON b.id = vm.business_id
    INNER JOIN vehicle_brands vb ON vb.id = vm.brand_id
    INNER JOIN vehicle_categories vc ON vc.id = vm.category_id
    {$where}
    ORDER BY vm.id DESC
";

$stmt = $conn->prepare($sql);
if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $models[] = $row;
        }
    }
    $stmt->close();
}

/* -------------------------------------------------------
   COUNTS
------------------------------------------------------- */
$totalModels = 0;
$fuelModels = 0;
$electricModels = 0;
$activeModels = 0;

$res = $conn->query("SELECT COUNT(*) AS total FROM vehicle_models");
if ($res && $row = $res->fetch_assoc()) {
    $totalModels = (int)$row['total'];
}

$res = $conn->query("SELECT COUNT(*) AS total FROM vehicle_models WHERE vehicle_type = 'fuel'");
if ($res && $row = $res->fetch_assoc()) {
    $fuelModels = (int)$row['total'];
}

$res = $conn->query("SELECT COUNT(*) AS total FROM vehicle_models WHERE vehicle_type = 'electric'");
if ($res && $row = $res->fetch_assoc()) {
    $electricModels = (int)$row['total'];
}

$res = $conn->query("SELECT COUNT(*) AS total FROM vehicle_models WHERE status = 1");
if ($res && $row = $res->fetch_assoc()) {
    $activeModels = (int)$row['total'];
}

$pageTitle = 'Vehicle Models';
$currentPage = 'vehicle-models';
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

                <?php if ($success !== ''): ?>
                    <div class="row">
                        <div class="col-12">
                            <div class="alert alert-success alert-dismissible fade show" role="alert">
                                <?php echo h($success); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($error !== ''): ?>
                    <div class="row">
                        <div class="col-12">
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <?php echo h($error); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Stats -->
                <div class="row">
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <p class="text-muted mb-1">Total Models</p>
                                <h3 class="text-primary mt-2 mb-0"><?php echo number_format($totalModels); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <p class="text-muted mb-1">Fuel Models</p>
                                <h3 class="text-success mt-2 mb-0"><?php echo number_format($fuelModels); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <p class="text-muted mb-1">Electric Models</p>
                                <h3 class="text-info mt-2 mb-0"><?php echo number_format($electricModels); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <p class="text-muted mb-1">Active Models</p>
                                <h3 class="text-warning mt-2 mb-0"><?php echo number_format($activeModels); ?></h3>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Form -->
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4"><?php echo $editModel ? 'Edit Vehicle Model' : 'Add Vehicle Model'; ?></h4>

                        <form method="post" action="">
                            <input type="hidden" name="model_id" value="<?php echo (int)($editModel['id'] ?? 0); ?>">

                            <div class="row">
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label class="form-label">Business <span class="text-danger">*</span></label>
                                        <select name="business_id" class="form-select" required>
                                            <option value="">Select Business</option>
                                            <?php foreach ($businesses as $business): ?>
                                                <option value="<?php echo (int)$business['id']; ?>" <?php echo ((int)($editModel['business_id'] ?? 0) === (int)$business['id']) ? 'selected' : ''; ?>>
                                                    <?php echo h($business['business_name'] . ' (' . $business['business_code'] . ')'); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>

                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label class="form-label">Brand <span class="text-danger">*</span></label>
                                        <select name="brand_id" class="form-select" required>
                                            <option value="">Select Brand</option>
                                            <?php foreach ($brands as $brand): ?>
                                                <option value="<?php echo (int)$brand['id']; ?>" <?php echo ((int)($editModel['brand_id'] ?? 0) === (int)$brand['id']) ? 'selected' : ''; ?>>
                                                    <?php echo h($brand['brand_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>

                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label class="form-label">Category <span class="text-danger">*</span></label>
                                        <select name="category_id" class="form-select" required>
                                            <option value="">Select Category</option>
                                            <?php foreach ($categories as $category): ?>
                                                <option value="<?php echo (int)$category['id']; ?>" <?php echo ((int)($editModel['category_id'] ?? 0) === (int)$category['id']) ? 'selected' : ''; ?>>
                                                    <?php echo h($category['category_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label class="form-label">Model Name <span class="text-danger">*</span></label>
                                        <input type="text" name="model_name" class="form-control" value="<?php echo h($editModel['model_name'] ?? ''); ?>" required>
                                    </div>
                                </div>

                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label class="form-label">Variant Name</label>
                                        <input type="text" name="variant_name" class="form-control" value="<?php echo h($editModel['variant_name'] ?? ''); ?>">
                                    </div>
                                </div>

                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label class="form-label">Vehicle Type <span class="text-danger">*</span></label>
                                        <select name="vehicle_type" class="form-select" required>
                                            <option value="fuel" <?php echo (($editModel['vehicle_type'] ?? 'fuel') === 'fuel') ? 'selected' : ''; ?>>Fuel</option>
                                            <option value="electric" <?php echo (($editModel['vehicle_type'] ?? '') === 'electric') ? 'selected' : ''; ?>>Electric</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label class="form-label">Fuel Type</label>
                                        <input type="text" name="fuel_type" class="form-control" value="<?php echo h($editModel['fuel_type'] ?? ''); ?>">
                                    </div>
                                </div>

                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label class="form-label">Transmission</label>
                                        <input type="text" name="transmission" class="form-control" value="<?php echo h($editModel['transmission'] ?? ''); ?>">
                                    </div>
                                </div>

                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label class="form-label">Engine CC</label>
                                        <input type="text" name="engine_cc" class="form-control" value="<?php echo h($editModel['engine_cc'] ?? ''); ?>">
                                    </div>
                                </div>

                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label class="form-label">Mileage</label>
                                        <input type="text" name="mileage" class="form-control" value="<?php echo h($editModel['mileage'] ?? ''); ?>">
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label class="form-label">Battery Capacity</label>
                                        <input type="text" name="battery_capacity" class="form-control" value="<?php echo h($editModel['battery_capacity'] ?? ''); ?>">
                                    </div>
                                </div>

                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label class="form-label">Motor Power</label>
                                        <input type="text" name="motor_power" class="form-control" value="<?php echo h($editModel['motor_power'] ?? ''); ?>">
                                    </div>
                                </div>

                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label class="form-label">Range KM</label>
                                        <input type="text" name="range_km" class="form-control" value="<?php echo h($editModel['range_km'] ?? ''); ?>">
                                    </div>
                                </div>

                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label class="form-label">Charging Time</label>
                                        <input type="text" name="charging_time" class="form-control" value="<?php echo h($editModel['charging_time'] ?? ''); ?>">
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label class="form-label">Battery Brand</label>
                                        <input type="text" name="battery_brand" class="form-control" value="<?php echo h($editModel['battery_brand'] ?? ''); ?>">
                                    </div>
                                </div>

                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label class="form-label">Charger Brand</label>
                                        <input type="text" name="charger_brand" class="form-control" value="<?php echo h($editModel['charger_brand'] ?? ''); ?>">
                                    </div>
                                </div>

                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label class="form-label">Charger Type</label>
                                        <input type="text" name="charger_type" class="form-control" value="<?php echo h($editModel['charger_type'] ?? ''); ?>">
                                    </div>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Color Options</label>
                                <textarea name="color_options" class="form-control" rows="2"><?php echo h($editModel['color_options'] ?? ''); ?></textarea>
                            </div>

                            <div class="row">
                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label class="form-label">Ex Showroom Price</label>
                                        <input type="number" step="0.01" name="ex_showroom_price" class="form-control" value="<?php echo h($editModel['ex_showroom_price'] ?? '0.00'); ?>">
                                    </div>
                                </div>

                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label class="form-label">Insurance Price</label>
                                        <input type="number" step="0.01" name="insurance_price" class="form-control" value="<?php echo h($editModel['insurance_price'] ?? '0.00'); ?>">
                                    </div>
                                </div>

                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label class="form-label">Registration Price</label>
                                        <input type="number" step="0.01" name="registration_price" class="form-control" value="<?php echo h($editModel['registration_price'] ?? '0.00'); ?>">
                                    </div>
                                </div>

                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label class="form-label">RTO Charge</label>
                                        <input type="number" step="0.01" name="rto_charge" class="form-control" value="<?php echo h($editModel['rto_charge'] ?? '0.00'); ?>">
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label class="form-label">Road Tax</label>
                                        <input type="number" step="0.01" name="road_tax" class="form-control" value="<?php echo h($editModel['road_tax'] ?? '0.00'); ?>">
                                    </div>
                                </div>

                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label class="form-label">Hypothecation Charge</label>
                                        <input type="number" step="0.01" name="hypothecation_charge" class="form-control" value="<?php echo h($editModel['hypothecation_charge'] ?? '0.00'); ?>">
                                    </div>
                                </div>

                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label class="form-label">Other Charge</label>
                                        <input type="number" step="0.01" name="other_charge" class="form-control" value="<?php echo h($editModel['other_charge'] ?? '0.00'); ?>">
                                    </div>
                                </div>

                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label class="form-label">GST %</label>
                                        <input type="number" step="0.01" name="gst_percent" class="form-control" value="<?php echo h($editModel['gst_percent'] ?? '0.00'); ?>">
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label class="form-label">Cess %</label>
                                        <input type="number" step="0.01" name="cess_percent" class="form-control" value="<?php echo h($editModel['cess_percent'] ?? '0.00'); ?>">
                                    </div>
                                </div>

                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label class="form-label">HSN Code</label>
                                        <input type="text" name="hsn_code" class="form-control" value="<?php echo h($editModel['hsn_code'] ?? ''); ?>">
                                    </div>
                                </div>

                                <div class="col-md-4 d-flex align-items-center">
                                    <div class="form-check mt-3">
                                        <input class="form-check-input" type="checkbox" id="status1" name="status" <?php echo (!isset($editModel['status']) || (int)$editModel['status'] === 1) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="status">Active Model</label>
                                    </div>
                                </div>
                            </div>

                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary"><?php echo $editModel ? 'Update Model' : 'Add Model'; ?></button>
                                <?php if ($editModel): ?>
                                    <a href="vehicle-models.php" class="btn btn-secondary">Cancel Edit</a>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Filters -->
                <div class="card">
                    <div class="card-body">
                        <form method="get" class="row g-3 align-items-end">
                            <div class="col-md-3">
                                <label class="form-label">Search</label>
                                <input type="text" name="search" class="form-control" placeholder="Model / variant / brand / business" value="<?php echo h($search); ?>">
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">Business</label>
                                <select name="filter_business_id" class="form-select">
                                    <option value="">All</option>
                                    <?php foreach ($businesses as $business): ?>
                                        <option value="<?php echo (int)$business['id']; ?>" <?php echo $filter_business_id === (int)$business['id'] ? 'selected' : ''; ?>>
                                            <?php echo h($business['business_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">Brand</label>
                                <select name="filter_brand_id" class="form-select">
                                    <option value="">All</option>
                                    <?php foreach ($brands as $brand): ?>
                                        <option value="<?php echo (int)$brand['id']; ?>" <?php echo $filter_brand_id === (int)$brand['id'] ? 'selected' : ''; ?>>
                                            <?php echo h($brand['brand_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">Category</label>
                                <select name="filter_category_id" class="form-select">
                                    <option value="">All</option>
                                    <?php foreach ($categories as $category): ?>
                                        <option value="<?php echo (int)$category['id']; ?>" <?php echo $filter_category_id === (int)$category['id'] ? 'selected' : ''; ?>>
                                            <?php echo h($category['category_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-1">
                                <label class="form-label">Type</label>
                                <select name="filter_vehicle_type" class="form-select">
                                    <option value="">All</option>
                                    <option value="fuel" <?php echo $filter_vehicle_type === 'fuel' ? 'selected' : ''; ?>>Fuel</option>
                                    <option value="electric" <?php echo $filter_vehicle_type === 'electric' ? 'selected' : ''; ?>>EV</option>
                                </select>
                            </div>

                            <div class="col-md-1">
                                <label class="form-label">Status</label>
                                <select name="filter_status" class="form-select">
                                    <option value="">All</option>
                                    <option value="1" <?php echo $filter_status === '1' ? 'selected' : ''; ?>>Active</option>
                                    <option value="0" <?php echo $filter_status === '0' ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                            </div>

                            <div class="col-md-1">
                                <div class="d-flex gap-2">
                                    <button type="submit" class="btn btn-primary">Go</button>
                                </div>
                            </div>
                        </form>
                        <div class="mt-3">
                            <a href="vehicle-models.php" class="btn btn-secondary btn-sm">Reset Filters</a>
                        </div>
                    </div>
                </div>

                <!-- Table -->
                <div class="card">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>#</th>
                                        <th>Business</th>
                                        <th>Brand / Category</th>
                                        <th>Model</th>
                                        <th>Type</th>
                                        <th>Price</th>
                                        <th>Stock</th>
                                        <th>Status</th>
                                        <th style="width:180px;">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($models)): ?>
                                        <?php $sno = 1; foreach ($models as $row): ?>
                                            <tr>
                                                <td><?php echo $sno++; ?></td>
                                                <td>
                                                    <div><?php echo h($row['business_name']); ?></div>
                                                    <small class="text-muted"><?php echo h($row['business_code']); ?></small>
                                                </td>
                                                <td>
                                                    <div><?php echo h($row['brand_name']); ?></div>
                                                    <small class="text-muted"><?php echo h($row['category_name']); ?></small>
                                                </td>
                                                <td>
                                                    <div><strong><?php echo h($row['model_name']); ?></strong></div>
                                                    <small class="text-muted"><?php echo h($row['variant_name'] ?: '-'); ?></small>
                                                </td>
                                                <td>
                                                    <span class="badge bg-<?php echo $row['vehicle_type'] === 'electric' ? 'info' : 'success'; ?>">
                                                        <?php echo h(ucfirst($row['vehicle_type'])); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo money($row['ex_showroom_price']); ?></td>
                                                <td><?php echo number_format((int)$row['stock_count']); ?></td>
                                                <td>
                                                    <?php if ((int)$row['status'] === 1): ?>
                                                        <span class="badge bg-success">Active</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-danger">Inactive</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="d-flex flex-wrap gap-1">
                                                        <a href="vehicle-models.php?edit=<?php echo (int)$row['id']; ?>" class="btn btn-primary btn-sm">Edit</a>
                                                        <a href="vehicle-models.php?delete=<?php echo (int)$row['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Are you sure you want to delete this vehicle model?');">Delete</a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="9" class="text-center text-muted py-4">No vehicle models found.</td>
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