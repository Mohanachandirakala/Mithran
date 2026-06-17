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
$sql = "SELECT 
            vs.*,
            vm.model_name,
            vm.variant_name,
            vm.vehicle_type,
            vm.fuel_type,
            vm.engine_cc,
            vm.mileage,
            vm.battery_capacity AS model_battery_capacity,
            vm.motor_power,
            vm.range_km,
            vb.brand_name,
            vc.category_name,
            br.branch_name,
            br.branch_code,
            br.address_line1 AS branch_address,
            br.city AS branch_city,
            br.state AS branch_state
        FROM vehicle_stock vs
        LEFT JOIN vehicle_models vm ON vm.id = vs.model_id
        LEFT JOIN vehicle_brands vb ON vb.id = vm.brand_id
        LEFT JOIN vehicle_categories vc ON vc.id = vm.category_id
        LEFT JOIN branches br ON br.id = vs.branch_id
        WHERE vs.id = ? AND vs.business_id = ?
        LIMIT 1";

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
   CALCULATE WARRANTY STATUS
------------------------------------------------------- */
$warrantyStatus = 'No Warranty';
$warrantyBadge = 'secondary';

if (!empty($stock['battery_warranty_upto'])) {
    $today = date('Y-m-d');
    if ($today <= $stock['battery_warranty_upto']) {
        $warrantyStatus = 'In Warranty';
        $warrantyBadge = 'success';
    } else {
        $warrantyStatus = 'Expired';
        $warrantyBadge = 'danger';
    }
}

$chargerWarrantyStatus = 'No Warranty';
$chargerWarrantyBadge = 'secondary';

if (!empty($stock['charger_warranty_upto'])) {
    $today = date('Y-m-d');
    if ($today <= $stock['charger_warranty_upto']) {
        $chargerWarrantyStatus = 'In Warranty';
        $chargerWarrantyBadge = 'success';
    } else {
        $chargerWarrantyStatus = 'Expired';
        $chargerWarrantyBadge = 'danger';
    }
}

$pageTitle = 'Stock Details - ' . ($stock['chassis_no'] ?? 'Vehicle');
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
    .info-label {
        font-weight: 600;
        color: #495057;
        min-width: 180px;
    }
    .detail-table td {
        padding: 10px 15px;
    }
    .status-badge {
        font-size: 0.9rem;
        padding: 0.4rem 0.8rem;
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
                        <h4 class="mb-1">Vehicle Stock Details</h4>
                        <p class="text-muted mb-0">
                            <?php echo h(($stock['brand_name'] ?? '') . ' ' . ($stock['model_name'] ?? '')); ?>
                            <?php if (!empty($stock['variant_name'])): ?>
                                - <?php echo h($stock['variant_name']); ?>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="col-md-5 text-md-end mt-3 mt-md-0">
                        <div class="d-flex flex-wrap gap-2 justify-content-md-end">
                            <a href="vehicle-stock-edit.php?id=<?php echo $stockId; ?>" class="btn btn-primary">
                                <i class="ri-pencil-line me-1"></i> Edit Stock
                            </a>
                            <a href="vehicle-stock.php" class="btn btn-secondary">
                                <i class="ri-arrow-go-back-line me-1"></i> Back to List
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Summary Cards -->
                <div class="row">
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Stock Status</p>
                                <h4 class="mb-0">
                                    <?php
                                    $badge = 'secondary';
                                    if ($stock['stock_status'] === 'in_stock') $badge = 'success';
                                    elseif ($stock['stock_status'] === 'reserved') $badge = 'warning';
                                    elseif ($stock['stock_status'] === 'sold') $badge = 'danger';
                                    elseif ($stock['stock_status'] === 'demo') $badge = 'info';
                                    ?>
                                    <span class="badge bg-<?php echo $badge; ?> status-badge">
                                        <?php echo h(ucwords(str_replace('_', ' ', $stock['stock_status']))); ?>
                                    </span>
                                </h4>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Total Price</p>
                                <h4 class="mb-0 text-primary"><?php echo money($stock['sale_price'] ?? 0); ?></h4>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Purchase Cost</p>
                                <h4 class="mb-0 text-success"><?php echo money($stock['purchase_cost'] ?? 0); ?></h4>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Profit Margin</p>
                                <h4 class="mb-0 text-info">
                                    <?php 
                                    $profit = ($stock['sale_price'] ?? 0) - ($stock['purchase_cost'] ?? 0);
                                    echo money($profit);
                                    ?>
                                </h4>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Vehicle Information -->
                <div class="card">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0">
                            <i class="ri-motorbike-line me-2"></i>Vehicle Information
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered detail-table mb-0">
                                <tbody>
                                    <tr>
                                        <th class="bg-light" style="width: 200px;">Brand</th>
                                        <td><?php echo h($stock['brand_name'] ?? '-'); ?></td>
                                        <th class="bg-light" style="width: 200px;">Model</th>
                                        <td><?php echo h($stock['model_name'] ?? '-'); ?></td>
                                    </tr>
                                    <tr>
                                        <th class="bg-light">Variant</th>
                                        <td><?php echo h($stock['variant_name'] ?? '-'); ?></td>
                                        <th class="bg-light">Category</th>
                                        <td><?php echo h($stock['category_name'] ?? '-'); ?></td>
                                    </tr>
                                    <tr>
                                        <th class="bg-light">Vehicle Type</th>
                                        <td>
                                            <span class="badge bg-<?php echo ($stock['vehicle_type'] ?? '') === 'electric' ? 'info' : 'primary'; ?>">
                                                <?php echo h(ucfirst($stock['vehicle_type'] ?? '-')); ?>
                                            </span>
                                        </td>
                                        <th class="bg-light">Fuel Type</th>
                                        <td><?php echo h(ucfirst($stock['fuel_type'] ?? '-')); ?></td>
                                    </tr>
                                    <tr>
                                        <th class="bg-light">Color</th>
                                        <td><?php echo h($stock['color'] ?? '-'); ?></td>
                                        <th class="bg-light">Manufacture Year</th>
                                        <td><?php echo h($stock['manufacture_year'] ?? '-'); ?></td>
                                    </tr>
                                    <?php if (!empty($stock['engine_cc'])): ?>
                                    <tr>
                                        <th class="bg-light">Engine CC</th>
                                        <td><?php echo h($stock['engine_cc']); ?></td>
                                        <th class="bg-light">Mileage</th>
                                        <td><?php echo h($stock['mileage'] ?? '-'); ?></td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Identification Numbers -->
                <div class="card">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0">
                            <i class="ri-barcode-line me-2"></i>Identification Numbers
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered detail-table mb-0">
                                <tbody>
                                    <tr>
                                        <th class="bg-light" style="width: 200px;">Chassis Number</th>
                                        <td class="fw-bold"><?php echo h($stock['chassis_no'] ?? '-'); ?></td>
                                        <th class="bg-light" style="width: 200px;">Engine Number</th>
                                        <td><?php echo h($stock['engine_no'] ?? '-'); ?></td>
                                    </tr>
                                    <tr>
                                        <th class="bg-light">Motor Number</th>
                                        <td><?php echo h($stock['motor_no'] ?? '-'); ?></td>
                                        <th class="bg-light">VIN Number</th>
                                        <td><?php echo h($stock['vin_no'] ?? '-'); ?></td>
                                    </tr>
                                    <tr>
                                        <th class="bg-light">Key Number</th>
                                        <td><?php echo h($stock['key_no'] ?? '-'); ?></td>
                                        <th class="bg-light"></th>
                                        <td></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Battery & Charger Information -->
                <div class="card">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0">
                            <i class="ri-battery-line me-2"></i>Battery & Charger Information
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered detail-table mb-0">
                                <tbody>
                                    <tr>
                                        <th class="bg-light" style="width: 200px;">Battery Brand</th>
                                        <td><?php echo h($stock['battery_brand'] ?? '-'); ?></td>
                                        <th class="bg-light" style="width: 200px;">Battery Number</th>
                                        <td><?php echo h($stock['battery_no'] ?? '-'); ?></td>
                                    </tr>
                                    <tr>
                                        <th class="bg-light">Battery Capacity</th>
                                        <td><?php echo h($stock['battery_capacity'] ?? '-'); ?></td>
                                        <th class="bg-light">Battery Warranty</th>
                                        <td>
                                            <?php if (!empty($stock['battery_warranty_upto'])): ?>
                                                <?php echo date('d M Y', strtotime($stock['battery_warranty_upto'])); ?>
                                                <span class="badge bg-<?php echo $warrantyBadge; ?> ms-2"><?php echo $warrantyStatus; ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">Not specified</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th class="bg-light">Charger Brand</th>
                                        <td><?php echo h($stock['charger_brand'] ?? '-'); ?></td>
                                        <th class="bg-light">Charger Number</th>
                                        <td><?php echo h($stock['charger_no'] ?? '-'); ?></td>
                                    </tr>
                                    <tr>
                                        <th class="bg-light">Charger Type</th>
                                        <td><?php echo h($stock['charger_type'] ?? '-'); ?></td>
                                        <th class="bg-light">Charger Warranty</th>
                                        <td>
                                            <?php if (!empty($stock['charger_warranty_upto'])): ?>
                                                <?php echo date('d M Y', strtotime($stock['charger_warranty_upto'])); ?>
                                                <span class="badge bg-<?php echo $chargerWarrantyBadge; ?> ms-2"><?php echo $chargerWarrantyStatus; ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">Not specified</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Pricing Details -->
                <div class="card">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0">
                            <i class="ri-money-rupee-circle-line me-2"></i>Pricing Details
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered detail-table mb-0">
                                <tbody>
                                    <tr>
                                        <th class="bg-light" style="width: 200px;">Ex-Showroom Price</th>
                                        <td><?php echo money($stock['ex_showroom_price'] ?? 0); ?></td>
                                        <th class="bg-light" style="width: 200px;">RTO Charge</th>
                                        <td><?php echo money($stock['rto_charge'] ?? 0); ?></td>
                                    </tr>
                                    <tr>
                                        <th class="bg-light">Registration Price</th>
                                        <td><?php echo money($stock['registration_price'] ?? 0); ?></td>
                                        <th class="bg-light">Road Tax</th>
                                        <td><?php echo money($stock['road_tax'] ?? 0); ?></td>
                                    </tr>
                                    <tr>
                                        <th class="bg-light">Insurance Price</th>
                                        <td><?php echo money($stock['insurance_price'] ?? 0); ?></td>
                                        <th class="bg-light">Total Sale Price</th>
                                        <td class="fw-bold text-primary"><?php echo money($stock['sale_price'] ?? 0); ?></td>
                                    </tr>
                                    <tr>
                                        <th class="bg-light">Purchase Cost</th>
                                        <td><?php echo money($stock['purchase_cost'] ?? 0); ?></td>
                                        <th class="bg-light">Supplier Name</th>
                                        <td><?php echo h($stock['supplier_name'] ?? '-'); ?></td>
                                    </tr>
                                    <tr>
                                        <th class="bg-light">Purchase Date</th>
                                        <td><?php echo !empty($stock['purchase_date']) ? date('d M Y', strtotime($stock['purchase_date'])) : '-'; ?></td>
                                        <th class="bg-light"></th>
                                        <td></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Branch Information -->
                <div class="card">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0">
                            <i class="ri-store-line me-2"></i>Branch Information
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered detail-table mb-0">
                                <tbody>
                                    <tr>
                                        <th class="bg-light" style="width: 200px;">Branch Name</th>
                                        <td><?php echo h($stock['branch_name'] ?? '-'); ?></td>
                                        <th class="bg-light" style="width: 200px;">Branch Code</th>
                                        <td><?php echo h($stock['branch_code'] ?? '-'); ?></td>
                                    </tr>
                                    <tr>
                                        <th class="bg-light">Address</th>
                                        <td colspan="3">
                                            <?php 
                                            $address = [];
                                            if (!empty($stock['branch_address'])) $address[] = $stock['branch_address'];
                                            if (!empty($stock['branch_city'])) $address[] = $stock['branch_city'];
                                            if (!empty($stock['branch_state'])) $address[] = $stock['branch_state'];
                                            echo !empty($address) ? h(implode(', ', $address)) : '-';
                                            ?>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Remarks -->
                <?php if (!empty($stock['remarks'])): ?>
                <div class="card">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0">
                            <i class="ri-file-text-line me-2"></i>Remarks
                        </h5>
                    </div>
                    <div class="card-body">
                        <p class="mb-0"><?php echo nl2br(h($stock['remarks'])); ?></p>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Metadata -->
                <div class="card">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0">
                            <i class="ri-information-line me-2"></i>Additional Information
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered detail-table mb-0">
                                <tbody>
                                    <tr>
                                        <th class="bg-light" style="width: 200px;">Created At</th>
                                        <td><?php echo !empty($stock['created_at']) ? date('d M Y h:i A', strtotime($stock['created_at'])) : '-'; ?></td>
                                        <th class="bg-light" style="width: 200px;">Stock ID</th>
                                        <td>#<?php echo $stockId; ?></td>
                                    </tr>
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