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
    $stmt = $conn->prepare("
        SELECT 1
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
        LIMIT 1
    ");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $res = $stmt->get_result();
    $ok = $res && $res->num_rows > 0;
    $stmt->close();
    return $ok;
}

function columnExists(mysqli $conn, string $table, string $column): bool
{
    $stmt = $conn->prepare("
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
        LIMIT 1
    ");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $res = $stmt->get_result();
    $ok = $res && $res->num_rows > 0;
    $stmt->close();
    return $ok;
}

/* -------------------------------------------------------
   REQUIRED TABLES
------------------------------------------------------- */
$requiredTables = ['business_users', 'businesses', 'branches', 'customers', 'pre_bookings'];
foreach ($requiredTables as $tbl) {
    if (!tableExists($conn, $tbl)) {
        die($tbl . ' table not found.');
    }
}

$hasVehicleModels = tableExists($conn, 'vehicle_models');
$hasVehicleBrands = tableExists($conn, 'vehicle_brands');
$hasProducts = tableExists($conn, 'products');
$hasPaymentMethods = tableExists($conn, 'payment_methods');

$hasBrandNameColumn = columnExists($conn, 'pre_bookings', 'brand_name');
$hasModelNameColumn = columnExists($conn, 'pre_bookings', 'model_name');
$hasVariantNameColumn = columnExists($conn, 'pre_bookings', 'variant_name');

/* -------------------------------------------------------
   VALIDATE LOGIN USER
------------------------------------------------------- */
$loggedUser = null;
$stmt = $conn->prepare("
    SELECT bu.id, bu.full_name, bu.role, bu.status,
           b.business_name, b.status AS business_status
    FROM business_users bu
    INNER JOIN businesses b ON b.id = bu.business_id
    WHERE bu.id = ? AND bu.business_id = ?
    LIMIT 1
");
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
   GET ID
------------------------------------------------------- */
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    die('Invalid pre booking id.');
}

/* -------------------------------------------------------
   FETCH BOOKING
------------------------------------------------------- */
$manualBrandSelect = $hasBrandNameColumn ? "pb.brand_name," : "NULL AS brand_name,";
$manualModelSelect = $hasModelNameColumn ? "pb.model_name," : "NULL AS model_name,";
$manualVariantSelect = $hasVariantNameColumn ? "pb.variant_name," : "NULL AS variant_name,";

$sql = "
    SELECT
        pb.*,
        {$manualBrandSelect}
        {$manualModelSelect}
        {$manualVariantSelect}

        br.branch_name,
        br.branch_code,
        br.contact_person AS branch_contact_person,
        br.mobile AS branch_mobile,
        br.city AS branch_city,
        br.state AS branch_state,

        c.full_name AS customer_name,
        c.mobile AS customer_mobile,
        c.alternate_mobile AS customer_alt_mobile,
        c.email AS customer_email,
        c.gstin AS customer_gstin,
        c.address_line1 AS customer_address_line1,
        c.address_line2 AS customer_address_line2,
        c.city AS customer_city,
        c.district AS customer_district,
        c.state AS customer_state,
        c.pincode AS customer_pincode,

        " . ($hasVehicleModels ? "vm.model_name AS master_model_name, vm.variant_name AS master_variant_name, vm.ex_showroom_price, vm.vehicle_type AS master_vehicle_type" : "NULL AS master_model_name, NULL AS master_variant_name, NULL AS ex_showroom_price, NULL AS master_vehicle_type") . ",
        " . ($hasVehicleBrands && $hasVehicleModels ? "vb.brand_name AS master_brand_name" : "NULL AS master_brand_name") . ",
        " . ($hasProducts ? "p.product_name, p.product_code, p.selling_price AS product_price" : "NULL AS product_name, NULL AS product_code, NULL AS product_price") . ",
        " . ($hasPaymentMethods ? "pm.method_name" : "NULL AS method_name") . ",
        bu.full_name AS created_user_name

    FROM pre_bookings pb
    LEFT JOIN branches br ON br.id = pb.branch_id
    LEFT JOIN customers c ON c.id = pb.customer_id
    " . ($hasVehicleModels ? "LEFT JOIN vehicle_models vm ON vm.id = pb.vehicle_model_id" : "") . "
    " . ($hasVehicleBrands && $hasVehicleModels ? "LEFT JOIN vehicle_brands vb ON vb.id = vm.brand_id" : "") . "
    " . ($hasProducts ? "LEFT JOIN products p ON p.id = pb.product_id" : "") . "
    " . ($hasPaymentMethods ? "LEFT JOIN payment_methods pm ON pm.id = pb.payment_method_id" : "") . "
    LEFT JOIN business_users bu ON bu.id = pb.created_by
    WHERE pb.id = ? AND pb.business_id = ?
    LIMIT 1
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die('Failed to prepare query.');
}

$stmt->bind_param('ii', $id, $businessId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    die('Pre booking not found.');
}

/* -------------------------------------------------------
   DISPLAY VALUES
------------------------------------------------------- */
$itemLabel = '-';
if (($row['booking_type'] ?? '') === 'vehicle') {
    if (!empty($row['master_model_name']) || !empty($row['master_brand_name'])) {
        $itemLabel = trim(
            ($row['master_brand_name'] ?: 'Brand') . ' - ' .
            ($row['master_model_name'] ?: 'Model') .
            (!empty($row['master_variant_name']) ? ' - ' . $row['master_variant_name'] : '')
        );
    } else {
        $itemLabel = trim(
            ($row['brand_name'] ?: 'Brand') . ' - ' .
            ($row['model_name'] ?: 'Model') .
            (!empty($row['variant_name']) ? ' - ' . $row['variant_name'] : '')
        );
    }
} elseif (($row['booking_type'] ?? '') === 'product') {
    $itemLabel = trim(($row['product_name'] ?: 'Product') . (!empty($row['product_code']) ? ' (' . $row['product_code'] . ')' : ''));
}

$statusBadge = 'secondary';
if (($row['status'] ?? '') === 'open') $statusBadge = 'secondary';
elseif (($row['status'] ?? '') === 'confirmed') $statusBadge = 'primary';
elseif (($row['status'] ?? '') === 'cancelled') $statusBadge = 'danger';
elseif (($row['status'] ?? '') === 'converted') $statusBadge = 'warning';
elseif (($row['status'] ?? '') === 'delivered') $statusBadge = 'success';

$pageTitle = 'View Pre Booking';
$currentPage = 'pre-bookings';
?>
<!doctype html>
<html lang="en">
<?php include('includes/head.php'); ?>

<body data-sidebar="dark">

<style>
.page-content {
    padding-bottom: 100px !important;
}
.main-content {
    min-height: calc(100vh - 70px);
}
.card {
    margin-bottom: 24px;
}
.view-last-row {
    margin-bottom: 40px;
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
                        <h4 class="mb-1">Pre Booking View</h4>
                        <p class="text-muted mb-0">View full pre booking details</p>
                    </div>
                    <div class="col-md-5 text-md-end mt-3 mt-md-0">
                        <a href="pre-bookings.php" class="btn btn-secondary me-2">Back</a>
                        <a href="pre-booking-edit.php?id=<?php echo (int)$row['id']; ?>" class="btn btn-primary me-2">Edit</a>
                        <a href="pre-booking-print.php?id=<?php echo (int)$row['id']; ?>" class="btn btn-dark" target="_blank">Print</a>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Booking No</p>
                                <h5 class="mb-0"><?php echo h($row['booking_no']); ?></h5>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Booking Type</p>
                                <h5 class="mb-0"><?php echo h(ucfirst((string)$row['booking_type'])); ?></h5>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Booking Amount</p>
                                <h5 class="mb-0 text-primary"><?php echo money($row['booking_amount']); ?></h5>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Status</p>
                                <span class="badge bg-<?php echo $statusBadge; ?> fs-6">
                                    <?php echo h(ucfirst((string)$row['status'])); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-xl-6">
                        <div class="card h-100">
                            <div class="card-header">
                                <h5 class="mb-0">Booking Details</h5>
                            </div>
                            <div class="card-body">
                                <table class="table table-bordered align-middle mb-0">
                                    <tbody>
                                        <tr>
                                            <th style="width:35%;">Booking No</th>
                                            <td><?php echo h($row['booking_no']); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Booking Date</th>
                                            <td><?php echo !empty($row['booking_date']) ? h(date('d M Y h:i A', strtotime($row['booking_date']))) : '-'; ?></td>
                                        </tr>
                                        <tr>
                                            <th>Booking Type</th>
                                            <td><?php echo h(ucfirst((string)$row['booking_type'])); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Booked Item</th>
                                            <td><?php echo h($itemLabel); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Color Preference</th>
                                            <td><?php echo h($row['color_preference'] ?: '-'); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Expected Delivery</th>
                                            <td><?php echo !empty($row['expected_delivery_date']) ? h(date('d M Y', strtotime($row['expected_delivery_date']))) : '-'; ?></td>
                                        </tr>
                                        <tr>
                                            <th>Booking Amount</th>
                                            <td><strong><?php echo money($row['booking_amount']); ?></strong></td>
                                        </tr>
                                        <tr>
                                            <th>Status</th>
                                            <td><span class="badge bg-<?php echo $statusBadge; ?>"><?php echo h(ucfirst((string)$row['status'])); ?></span></td>
                                        </tr>
                                        <tr>
                                            <th>Payment Method</th>
                                            <td><?php echo h($row['method_name'] ?: '-'); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Reference No</th>
                                            <td><?php echo h($row['reference_no'] ?: '-'); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Notes</th>
                                            <td><?php echo nl2br(h($row['notes'] ?: '-')); ?></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-6">
                        <div class="card h-100">
                            <div class="card-header">
                                <h5 class="mb-0">Customer Details</h5>
                            </div>
                            <div class="card-body">
                                <table class="table table-bordered align-middle mb-0">
                                    <tbody>
                                        <tr>
                                            <th style="width:35%;">Customer Name</th>
                                            <td><?php echo h($row['customer_name'] ?: '-'); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Mobile</th>
                                            <td><?php echo h($row['customer_mobile'] ?: '-'); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Alternate Mobile</th>
                                            <td><?php echo h($row['customer_alt_mobile'] ?: '-'); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Email</th>
                                            <td><?php echo h($row['customer_email'] ?: '-'); ?></td>
                                        </tr>
                                        <tr>
                                            <th>GSTIN</th>
                                            <td><?php echo h($row['customer_gstin'] ?: '-'); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Address Line 1</th>
                                            <td><?php echo h($row['customer_address_line1'] ?: '-'); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Address Line 2</th>
                                            <td><?php echo h($row['customer_address_line2'] ?: '-'); ?></td>
                                        </tr>
                                        <tr>
                                            <th>City</th>
                                            <td><?php echo h($row['customer_city'] ?: '-'); ?></td>
                                        </tr>
                                        <tr>
                                            <th>District</th>
                                            <td><?php echo h($row['customer_district'] ?: '-'); ?></td>
                                        </tr>
                                        <tr>
                                            <th>State</th>
                                            <td><?php echo h($row['customer_state'] ?: '-'); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Pincode</th>
                                            <td><?php echo h($row['customer_pincode'] ?: '-'); ?></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row view-last-row">
                    <div class="col-xl-6">
                        <div class="card h-100">
                            <div class="card-header">
                                <h5 class="mb-0">Branch Details</h5>
                            </div>
                            <div class="card-body">
                                <table class="table table-bordered align-middle mb-0">
                                    <tbody>
                                        <tr>
                                            <th style="width:35%;">Branch Name</th>
                                            <td><?php echo h($row['branch_name'] ?: '-'); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Branch Code</th>
                                            <td><?php echo h($row['branch_code'] ?: '-'); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Contact Person</th>
                                            <td><?php echo h($row['branch_contact_person'] ?: '-'); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Mobile</th>
                                            <td><?php echo h($row['branch_mobile'] ?: '-'); ?></td>
                                        </tr>
                                        <tr>
                                            <th>City</th>
                                            <td><?php echo h($row['branch_city'] ?: '-'); ?></td>
                                        </tr>
                                        <tr>
                                            <th>State</th>
                                            <td><?php echo h($row['branch_state'] ?: '-'); ?></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-6">
                        <div class="card h-100">
                            <div class="card-header">
                                <h5 class="mb-0">Record Details</h5>
                            </div>
                            <div class="card-body">
                                <table class="table table-bordered align-middle mb-0">
                                    <tbody>
                                        <tr>
                                            <th style="width:35%;">Created By</th>
                                            <td><?php echo h($row['created_user_name'] ?: '-'); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Created At</th>
                                            <td><?php echo !empty($row['created_at']) ? h(date('d M Y h:i A', strtotime($row['created_at']))) : '-'; ?></td>
                                        </tr>
                                        <tr>
                                            <th>Updated At</th>
                                            <td><?php echo !empty($row['updated_at']) ? h(date('d M Y h:i A', strtotime($row['updated_at']))) : '-'; ?></td>
                                        </tr>
                                        <tr>
                                            <th>Booking ID</th>
                                            <td><?php echo (int)$row['id']; ?></td>
                                        </tr>
                                    </tbody>
                                </table>
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