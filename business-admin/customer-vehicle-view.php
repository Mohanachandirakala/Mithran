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
   GET VEHICLE ID
------------------------------------------------------- */
$vehicleId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($vehicleId <= 0) {
    header('Location: customer-vehicles.php');
    exit;
}

/* -------------------------------------------------------
   TABLE CHECKS
------------------------------------------------------- */
$hasVehicleBrands = tableExists($conn, 'vehicle_brands');
$hasVehicleModels = tableExists($conn, 'vehicle_models');
$hasVehicleStock = tableExists($conn, 'vehicle_stock');
$hasServiceJobs = tableExists($conn, 'service_job_cards');
$hasServiceInvoices = tableExists($conn, 'service_invoices');

/* -------------------------------------------------------
   FETCH VEHICLE DETAILS
------------------------------------------------------- */
$sql = "SELECT
            cv.*,
            c.id AS customer_id,
            c.full_name AS customer_name,
            c.mobile AS customer_mobile,
            c.email AS customer_email,
            c.address_line1 AS customer_address_line1,
            c.address_line2 AS customer_address_line2,
            c.city AS customer_city,
            c.district AS customer_district,
            c.state AS customer_state,
            c.pincode AS customer_pincode,
            " . ($hasVehicleBrands ? "vb.brand_name" : "NULL AS brand_name") . ",
            " . ($hasVehicleModels ? "vm.model_name, vm.variant_name, vm.vehicle_type AS model_vehicle_type, 
                  vm.fuel_type, vm.transmission, vm.engine_cc, vm.mileage,
                  vm.battery_capacity AS model_battery_capacity, vm.motor_power, 
                  vm.range_km, vm.charging_time" : "NULL AS model_name, NULL AS variant_name") . ",
            " . ($hasVehicleStock ? "vs.id AS stock_id, vs.vin_no, vs.manufacture_year, 
                  vs.supplier_name, vs.purchase_cost, vs.sale_price, vs.stock_status,
                  vs.key_no, vs.remarks AS stock_remarks" : "NULL AS stock_id") . "
        FROM customer_vehicles cv
        INNER JOIN customers c ON c.id = cv.customer_id
        " . ($hasVehicleBrands ? "LEFT JOIN vehicle_brands vb ON vb.id = cv.brand_id" : "") . "
        " . ($hasVehicleModels ? "LEFT JOIN vehicle_models vm ON vm.id = cv.model_id" : "") . "
        " . ($hasVehicleStock ? "LEFT JOIN vehicle_stock vs ON vs.id = cv.vehicle_stock_id" : "") . "
        WHERE cv.id = ? AND cv.business_id = ?
        LIMIT 1";

$vehicle = null;
$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param('ii', $vehicleId, $businessId);
    $stmt->execute();
    $vehicle = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if (!$vehicle) {
    header('Location: customer-vehicles.php');
    exit;
}

/* -------------------------------------------------------
   FETCH SERVICE HISTORY FOR THIS VEHICLE
------------------------------------------------------- */
$serviceHistory = [];
if ($hasServiceJobs) {
    $sql = "SELECT 
                sjc.id,
                sjc.jobcard_no,
                sjc.service_date,
                sjc.job_status,
                sjc.opening_km,
                sjc.estimated_amount,
                sjc.final_amount,
                sjc.customer_voice,
                sjc.technician_observation,
                sjc.recommendation,
                sjc.created_at,
                sjc.closed_at,
                " . ($hasServiceInvoices ? "si.invoice_no, si.payment_status, si.grand_total" : "NULL AS invoice_no, NULL AS payment_status, NULL AS grand_total") . "
            FROM service_job_cards sjc
            " . ($hasServiceInvoices ? "LEFT JOIN service_invoices si ON si.jobcard_id = sjc.id" : "") . "
            WHERE sjc.customer_vehicle_id = ? AND sjc.business_id = ?
            ORDER BY sjc.id DESC
            LIMIT 20";
    
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('ii', $vehicleId, $businessId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $serviceHistory[] = $row;
        }
        $stmt->close();
    }
}

/* -------------------------------------------------------
   CALCULATE VEHICLE AGE AND WARRANTY STATUS
------------------------------------------------------- */
$vehicleAge = null;
if (!empty($vehicle['purchase_date'])) {
    $purchaseDate = new DateTime($vehicle['purchase_date']);
    $today = new DateTime();
    $interval = $purchaseDate->diff($today);
    $vehicleAge = $interval->y . ' years, ' . $interval->m . ' months, ' . $interval->d . ' days';
}

$warrantyStatus = 'No Warranty';
$warrantyBadge = 'secondary';
$warrantyDaysLeft = 0;

if (!empty($vehicle['warranty_start_date']) && !empty($vehicle['warranty_end_date'])) {
    $today = date('Y-m-d');
    if ($today >= $vehicle['warranty_start_date'] && $today <= $vehicle['warranty_end_date']) {
        $warrantyStatus = 'In Warranty';
        $warrantyBadge = 'success';
        
        $endDate = new DateTime($vehicle['warranty_end_date']);
        $today = new DateTime();
        $interval = $today->diff($endDate);
        $warrantyDaysLeft = $interval->days;
    } elseif ($today > $vehicle['warranty_end_date']) {
        $warrantyStatus = 'Expired';
        $warrantyBadge = 'danger';
    } else {
        $warrantyStatus = 'Upcoming';
        $warrantyBadge = 'warning';
    }
}

$batteryWarrantyStatus = 'No Warranty';
$batteryWarrantyBadge = 'secondary';
if (!empty($vehicle['battery_warranty_upto'])) {
    $today = date('Y-m-d');
    if ($today <= $vehicle['battery_warranty_upto']) {
        $batteryWarrantyStatus = 'In Warranty';
        $batteryWarrantyBadge = 'success';
    } else {
        $batteryWarrantyStatus = 'Expired';
        $batteryWarrantyBadge = 'danger';
    }
}

$chargerWarrantyStatus = 'No Warranty';
$chargerWarrantyBadge = 'secondary';
if (!empty($vehicle['charger_warranty_upto'])) {
    $today = date('Y-m-d');
    if ($today <= $vehicle['charger_warranty_upto']) {
        $chargerWarrantyStatus = 'In Warranty';
        $chargerWarrantyBadge = 'success';
    } else {
        $chargerWarrantyStatus = 'Expired';
        $chargerWarrantyBadge = 'danger';
    }
}

// Summary counts
$totalServices = count($serviceHistory);
$completedServices = 0;
foreach ($serviceHistory as $job) {
    if ($job['job_status'] === 'delivered') {
        $completedServices++;
    }
}

$pageTitle = 'Vehicle Details - ' . ($vehicle['registration_no'] ?: 'Vehicle');
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
    .info-label {
        font-weight: 600;
        color: #495057;
        min-width: 180px;
    }
    .warranty-badge {
        font-size: 0.9rem;
        padding: 0.35rem 0.65rem;
    }
    .status-badge {
        font-size: 1rem;
        padding: 0.5rem 1rem;
    }
    .detail-table td {
        padding: 8px 12px;
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

                <!-- Page Title -->
                <div class="row mb-3">
                    <div class="col-md-7">
                        <h4 class="mb-1">Vehicle Details</h4>
                        <p class="text-muted mb-0">
                            <?php echo h($vehicle['registration_no'] ?: 'Unregistered Vehicle'); ?>
                            <?php if (!empty($vehicle['brand_name']) || !empty($vehicle['model_name'])): ?>
                                - <?php echo h(trim(($vehicle['brand_name'] ?? '') . ' ' . ($vehicle['model_name'] ?? ''))); ?>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="col-md-5 text-md-end mt-3 mt-md-0">
                        <div class="d-flex flex-wrap gap-2 justify-content-md-end">
                            <a href="customer-vehicle-edit.php?id=<?php echo $vehicleId; ?>" class="btn btn-primary">
                                <i class="ri-pencil-line me-1"></i> Edit Vehicle
                            </a>
                            <a href="service-jobcard-add.php?customer_id=<?php echo $vehicle['customer_id']; ?>&customer_vehicle_id=<?php echo $vehicleId; ?>" class="btn btn-success">
                                <i class="ri-tools-line me-1"></i> New Service
                            </a>
                            <a href="customer-vehicles.php" class="btn btn-secondary">
                                <i class="ri-arrow-go-back-line me-1"></i> Back to List
                            </a>
                        </div>
                    </div>
                </div>

                <!-- SUMMARY CARDS -->
                <div class="row">
                    <div class="col-md">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Vehicle Status</p>
                                <h4 class="mb-0">
                                    <?php if ((int)$vehicle['active_status'] === 1): ?>
                                        <span class="badge bg-success status-badge">Active</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger status-badge">Inactive</span>
                                    <?php endif; ?>
                                </h4>
                            </div>
                        </div>
                    </div>
                    <div class="col-md">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Warranty Status</p>
                                <h4 class="mb-0">
                                    <span class="badge bg-<?php echo $warrantyBadge; ?> status-badge">
                                        <?php echo $warrantyStatus; ?>
                                    </span>
                                </h4>
                                <?php if ($warrantyDaysLeft > 0): ?>
                                    <small class="text-muted"><?php echo $warrantyDaysLeft; ?> days left</small>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-md">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Current KM</p>
                                <h4 class="mb-0"><?php echo number_format((int)($vehicle['current_km'] ?? 0)); ?></h4>
                            </div>
                        </div>
                    </div>
                    <div class="col-md">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Vehicle Age</p>
                                <h5 class="mb-0"><?php echo $vehicleAge ?: 'N/A'; ?></h5>
                            </div>
                        </div>
                    </div>
                    <div class="col-md">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Total Services</p>
                                <h4 class="mb-0"><?php echo number_format($totalServices); ?></h4>
                            </div>
                        </div>
                    </div>
                    <div class="col-md">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Completed</p>
                                <h4 class="mb-0 text-success"><?php echo number_format($completedServices); ?></h4>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Customer Information Card -->
                <div class="card">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0">
                            <i class="ri-user-line me-2"></i>Customer Information
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <table class="table table-borderless detail-table">
                                    <tr>
                                        <td class="info-label">Customer Name:</td>
                                        <td>
                                            <a href="customer-view.php?id=<?php echo $vehicle['customer_id']; ?>" class="fw-bold">
                                                <?php echo h($vehicle['customer_name']); ?>
                                            </a>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="info-label">Mobile Number:</td>
                                        <td><?php echo h($vehicle['customer_mobile']); ?></td>
                                    </tr>
                                    <tr>
                                        <td class="info-label">Email:</td>
                                        <td><?php echo !empty($vehicle['customer_email']) ? h($vehicle['customer_email']) : '<span class="text-muted">Not provided</span>'; ?></td>
                                    </tr>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <table class="table table-borderless detail-table">
                                    <tr>
                                        <td class="info-label">Address:</td>
                                        <td>
                                            <?php 
                                            $address = [];
                                            if (!empty($vehicle['customer_address_line1'])) $address[] = $vehicle['customer_address_line1'];
                                            if (!empty($vehicle['customer_address_line2'])) $address[] = $vehicle['customer_address_line2'];
                                            if (!empty($vehicle['customer_city'])) $address[] = $vehicle['customer_city'];
                                            if (!empty($vehicle['customer_district'])) $address[] = $vehicle['customer_district'];
                                            if (!empty($vehicle['customer_state'])) $address[] = $vehicle['customer_state'];
                                            if (!empty($vehicle['customer_pincode'])) $address[] = $vehicle['customer_pincode'];
                                            
                                            echo !empty($address) ? h(implode(', ', $address)) : '<span class="text-muted">Not provided</span>';
                                            ?>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Vehicle Details Card -->
                <div class="card">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0">
                            <i class="ri-motorbike-line me-2"></i>Vehicle Information
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped mb-0">
                                <tbody>
                                    <tr>
                                        <th style="width: 200px;">Vehicle Type</th>
                                        <td>
                                            <?php if (($vehicle['vehicle_type'] ?? '') === 'electric'): ?>
                                                <span class="badge bg-primary">Electric</span>
                                            <?php else: ?>
                                                <span class="badge bg-warning">Fuel</span>
                                            <?php endif; ?>
                                        </td>
                                        <th style="width: 200px;">Registration No</th>
                                        <td class="fw-bold"><?php echo h($vehicle['registration_no'] ?: 'Not Registered'); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Brand</th>
                                        <td><?php echo h($vehicle['brand_name'] ?: '-'); ?></td>
                                        <th>Model</th>
                                        <td><?php echo h($vehicle['model_name'] ?: '-'); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Variant</th>
                                        <td><?php echo h($vehicle['variant_name'] ?: '-'); ?></td>
                                        <th>Color</th>
                                        <td><?php echo h($vehicle['color'] ?: '-'); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Chassis Number</th>
                                        <td><?php echo h($vehicle['chassis_no'] ?: '-'); ?></td>
                                        <th><?php echo ($vehicle['vehicle_type'] ?? '') === 'electric' ? 'Motor Number' : 'Engine Number'; ?></th>
                                        <td><?php echo h(($vehicle['vehicle_type'] ?? '') === 'electric' ? ($vehicle['motor_no'] ?: '-') : ($vehicle['engine_no'] ?: '-')); ?></td>
                                    </tr>
                                    <?php if (!empty($vehicle['fuel_type'])): ?>
                                    <tr>
                                        <th>Fuel Type</th>
                                        <td><?php echo h(ucfirst($vehicle['fuel_type'])); ?></td>
                                        <th>Transmission</th>
                                        <td><?php echo h(ucfirst($vehicle['transmission'] ?? 'N/A')); ?></td>
                                    </tr>
                                    <?php endif; ?>
                                    <?php if (!empty($vehicle['engine_cc'])): ?>
                                    <tr>
                                        <th>Engine CC</th>
                                        <td><?php echo h($vehicle['engine_cc']); ?></td>
                                        <th>Mileage</th>
                                        <td><?php echo h($vehicle['mileage'] ?: '-'); ?></td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Purchase & Warranty Card -->
                <div class="card">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0">
                            <i class="ri-calendar-check-line me-2"></i>Purchase & Warranty Information
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped mb-0">
                                <tbody>
                                    <tr>
                                        <th style="width: 200px;">Purchase Date</th>
                                        <td><?php echo !empty($vehicle['purchase_date']) ? date('d M Y', strtotime($vehicle['purchase_date'])) : '<span class="text-muted">Not recorded</span>'; ?></td>
                                        <th style="width: 200px;">Current KM</th>
                                        <td class="fw-bold"><?php echo number_format((int)($vehicle['current_km'] ?? 0)); ?> km</td>
                                    </tr>
                                    <tr>
                                        <th>Warranty Period</th>
                                        <td>
                                            <?php if (!empty($vehicle['warranty_start_date']) && !empty($vehicle['warranty_end_date'])): ?>
                                                <?php echo date('d M Y', strtotime($vehicle['warranty_start_date'])); ?> 
                                                to 
                                                <?php echo date('d M Y', strtotime($vehicle['warranty_end_date'])); ?>
                                                <span class="badge bg-<?php echo $warrantyBadge; ?> ms-2"><?php echo $warrantyStatus; ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">No warranty recorded</span>
                                            <?php endif; ?>
                                        </td>
                                        <th>Registered On</th>
                                        <td><?php echo date('d M Y h:i A', strtotime($vehicle['created_at'])); ?></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Electric Vehicle Details (if applicable) -->
                <?php if (($vehicle['vehicle_type'] ?? '') === 'electric'): ?>
                <div class="card">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0">
                            <i class="ri-flashlight-line me-2"></i>Electric Vehicle Details
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th colspan="4">Battery Information</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <th style="width: 200px;">Battery Brand</th>
                                        <td><?php echo h($vehicle['battery_brand'] ?: '-'); ?></td>
                                        <th style="width: 200px;">Battery Number</th>
                                        <td><?php echo h($vehicle['battery_no'] ?: '-'); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Battery Capacity</th>
                                        <td><?php echo h($vehicle['battery_capacity'] ?: '-'); ?></td>
                                        <th>Battery Warranty</th>
                                        <td>
                                            <?php if (!empty($vehicle['battery_warranty_upto'])): ?>
                                                <?php echo date('d M Y', strtotime($vehicle['battery_warranty_upto'])); ?>
                                                <span class="badge bg-<?php echo $batteryWarrantyBadge; ?> ms-2"><?php echo $batteryWarrantyStatus; ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">No warranty recorded</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                </tbody>
                                <thead class="table-light">
                                    <tr>
                                        <th colspan="4">Charger Information</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <th>Charger Brand</th>
                                        <td><?php echo h($vehicle['charger_brand'] ?: '-'); ?></td>
                                        <th>Charger Number</th>
                                        <td><?php echo h($vehicle['charger_no'] ?: '-'); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Charger Type</th>
                                        <td><?php echo h($vehicle['charger_type'] ?: '-'); ?></td>
                                        <th>Charger Warranty</th>
                                        <td>
                                            <?php if (!empty($vehicle['charger_warranty_upto'])): ?>
                                                <?php echo date('d M Y', strtotime($vehicle['charger_warranty_upto'])); ?>
                                                <span class="badge bg-<?php echo $chargerWarrantyBadge; ?> ms-2"><?php echo $chargerWarrantyStatus; ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">No warranty recorded</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                </tbody>
                                <?php if (!empty($vehicle['model_battery_capacity']) || !empty($vehicle['motor_power']) || !empty($vehicle['range_km']) || !empty($vehicle['charging_time'])): ?>
                                <thead class="table-light">
                                    <tr>
                                        <th colspan="4">Specifications (from Model)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <?php if (!empty($vehicle['model_battery_capacity'])): ?>
                                            <th>Battery Capacity (Model)</th>
                                            <td><?php echo h($vehicle['model_battery_capacity']); ?></td>
                                        <?php else: ?>
                                            <th></th><td></td>
                                        <?php endif; ?>
                                        <?php if (!empty($vehicle['motor_power'])): ?>
                                            <th>Motor Power</th>
                                            <td><?php echo h($vehicle['motor_power']); ?></td>
                                        <?php else: ?>
                                            <th></th><td></td>
                                        <?php endif; ?>
                                    </tr>
                                    <tr>
                                        <?php if (!empty($vehicle['range_km'])): ?>
                                            <th>Range</th>
                                            <td><?php echo h($vehicle['range_km']); ?></td>
                                        <?php else: ?>
                                            <th></th><td></td>
                                        <?php endif; ?>
                                        <?php if (!empty($vehicle['charging_time'])): ?>
                                            <th>Charging Time</th>
                                            <td><?php echo h($vehicle['charging_time']); ?></td>
                                        <?php else: ?>
                                            <th></th><td></td>
                                        <?php endif; ?>
                                    </tr>
                                </tbody>
                                <?php endif; ?>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Stock Link Information (if applicable) -->
                <?php if (!empty($vehicle['stock_id'])): ?>
                <div class="card">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0">
                            <i class="ri-stack-line me-2"></i>Linked Stock Information
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped mb-0">
                                <tbody>
                                    <tr>
                                        <th style="width: 200px;">Stock ID</th>
                                        <td>
                                            <a href="vehicle-stock-view.php?id=<?php echo $vehicle['stock_id']; ?>">
                                                #<?php echo $vehicle['stock_id']; ?>
                                            </a>
                                        </td>
                                        <th style="width: 200px;">VIN Number</th>
                                        <td><?php echo h($vehicle['vin_no'] ?: '-'); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Manufacture Year</th>
                                        <td><?php echo h($vehicle['manufacture_year'] ?: '-'); ?></td>
                                        <th>Supplier</th>
                                        <td><?php echo h($vehicle['supplier_name'] ?: '-'); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Purchase Cost</th>
                                        <td><?php echo money($vehicle['purchase_cost'] ?? 0); ?></td>
                                        <th>Key Number</th>
                                        <td><?php echo h($vehicle['key_no'] ?: '-'); ?></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <?php if (!empty($vehicle['stock_remarks'])): ?>
                        <div class="mt-3">
                            <strong>Stock Remarks:</strong>
                            <p class="mb-0 mt-1"><?php echo nl2br(h($vehicle['stock_remarks'])); ?></p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Service History Card -->
                <div class="card">
                    <div class="card-header bg-light d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">
                            <i class="ri-tools-line me-2"></i>Service History
                        </h5>
                        <a href="service-jobcard-add.php?customer_id=<?php echo $vehicle['customer_id']; ?>&customer_vehicle_id=<?php echo $vehicleId; ?>" class="btn btn-sm btn-success">
                            <i class="ri-add-line me-1"></i> New Service
                        </a>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($serviceHistory)): ?>
                            <div class="table-responsive">
                                <table class="table table-bordered table-striped align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Job Card No</th>
                                            <th>Service Date</th>
                                            <th>Opening KM</th>
                                            <th>Status</th>
                                            <th>Amount</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $i = 1; foreach ($serviceHistory as $job): ?>
                                            <tr>
                                                <td><?php echo $i++; ?></td>
                                                <td>
                                                    <strong><?php echo h($job['jobcard_no']); ?></strong>
                                                </td>
                                                <td><?php echo date('d M Y', strtotime($job['service_date'])); ?></td>
                                                <td><?php echo number_format($job['opening_km'] ?? 0); ?> km</td>
                                                <td>
                                                    <?php
                                                    $status = $job['job_status'];
                                                    $badge = 'secondary';
                                                    if ($status === 'delivered') $badge = 'success';
                                                    elseif ($status === 'in_progress') $badge = 'info';
                                                    elseif ($status === 'open') $badge = 'warning';
                                                    elseif ($status === 'ready') $badge = 'primary';
                                                    elseif ($status === 'cancelled') $badge = 'danger';
                                                    ?>
                                                    <span class="badge bg-<?php echo $badge; ?>">
                                                        <?php echo ucwords(str_replace('_', ' ', $status)); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo money($job['final_amount'] ?? 0); ?></td>
                                                <td>
                                                    <div class="d-flex flex-wrap gap-2">
                                                        <a href="service-jobcard-view.php?id=<?php echo $job['id']; ?>" class="btn btn-sm btn-info">View</a>
                                                        <?php if (!empty($job['invoice_no'])): ?>
                                                            <a href="service-invoice-view.php?jobcard_id=<?php echo $job['id']; ?>" class="btn btn-sm btn-primary">Invoice</a>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-4">
                                <i class="ri-tools-line" style="font-size: 3rem; color: #ccc;"></i>
                                <p class="mt-2 text-muted">No service history found for this vehicle.</p>
                                <a href="service-jobcard-add.php?customer_id=<?php echo $vehicle['customer_id']; ?>&customer_vehicle_id=<?php echo $vehicleId; ?>" class="btn btn-success btn-sm">
                                    <i class="ri-add-line me-1"></i> Create First Service
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Notes Card (if any) -->
                <?php if (!empty($vehicle['notes'])): ?>
                <div class="card">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0">
                            <i class="ri-file-text-line me-2"></i>Additional Notes
                        </h5>
                    </div>
                    <div class="card-body">
                        <p class="mb-0"><?php echo nl2br(h($vehicle['notes'])); ?></p>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Summary Section -->
                <div class="row report-last-row">
                    <div class="col-xl-6">
                        <div class="card h-100">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Vehicle Summary</h4>
                                <table class="table table-bordered table-striped mb-0">
                                    <tbody>
                                        <tr>
                                            <th style="width:50%;">Registration Number</th>
                                            <td><?php echo h($vehicle['registration_no'] ?: 'Not Registered'); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Customer Name</th>
                                            <td><?php echo h($vehicle['customer_name']); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Vehicle Type</th>
                                            <td><?php echo ucfirst($vehicle['vehicle_type'] ?? 'N/A'); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Warranty Status</th>
                                            <td>
                                                <span class="badge bg-<?php echo $warrantyBadge; ?>"><?php echo $warrantyStatus; ?></span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th>Current KM</th>
                                            <td><?php echo number_format((int)($vehicle['current_km'] ?? 0)); ?> km</td>
                                        </tr>
                                        <tr>
                                            <th>Total Services</th>
                                            <td><?php echo number_format($totalServices); ?></td>
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
                                    <p class="mb-2">This page displays complete details of the customer vehicle including registration, warranty, battery/charger information, and service history.</p>
                                    <p class="mb-2">Use the <strong>Edit Vehicle</strong> button to update vehicle information or warranty details.</p>
                                    <p class="mb-2">Use the <strong>New Service</strong> button to create a service job card for this vehicle.</p>
                                    <p class="mb-0">Service history shows all past services performed on this vehicle.</p>
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
</script>

</body>
</html>