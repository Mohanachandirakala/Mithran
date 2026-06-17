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
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
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
   FETCH JOB CARD DETAILS
------------------------------------------------------- */
$jobcardId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($jobcardId <= 0) {
    header('Location: service-jobcards.php');
    exit;
}

// Fetch job card details with all related information
$stmt = $conn->prepare("
    SELECT sj.*, 
           br.branch_name,
           br.branch_code,
           br.address_line1 AS branch_address1,
           br.address_line2 AS branch_address2,
           br.city AS branch_city,
           br.state AS branch_state,
           br.pincode AS branch_pincode,
           br.mobile AS branch_mobile,
           br.email AS branch_email,
           c.full_name AS customer_name,
           c.mobile AS customer_mobile,
           c.alternate_mobile AS customer_alt_mobile,
           c.email AS customer_email,
           c.address_line1 AS customer_address1,
           c.address_line2 AS customer_address2,
           c.city AS customer_city,
           c.district AS customer_district,
           c.state AS customer_state,
           c.pincode AS customer_pincode,
           c.gstin AS customer_gstin,
           cv.vehicle_type,
           cv.registration_no,
           cv.chassis_no,
           cv.engine_no,
           cv.motor_no,
           cv.color,
           cv.current_km,
           cv.purchase_date,
           cv.battery_brand,
           cv.battery_no,
           cv.battery_capacity,
           cv.battery_warranty_upto,
           cv.charger_brand,
           cv.charger_no,
           cv.charger_type,
           cv.charger_warranty_upto,
           vb.brand_name,
           vm.model_name,
           vm.variant_name,
           bu.full_name AS created_by_name
    FROM service_job_cards sj
    LEFT JOIN branches br ON br.id = sj.branch_id
    LEFT JOIN customers c ON c.id = sj.customer_id
    LEFT JOIN customer_vehicles cv ON cv.id = sj.customer_vehicle_id
    LEFT JOIN vehicle_brands vb ON vb.id = cv.brand_id
    LEFT JOIN vehicle_models vm ON vm.id = cv.model_id
    LEFT JOIN business_users bu ON bu.id = sj.created_by
    WHERE sj.id = ? AND sj.business_id = ?
    LIMIT 1
");
$stmt->bind_param("ii", $jobcardId, $businessId);
$stmt->execute();
$jobcard = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$jobcard) {
    header('Location: service-jobcards.php');
    exit;
}

// Fetch complaints
$complaints = [];
$compStmt = $conn->prepare("
    SELECT id, complaint_text, priority, status 
    FROM service_complaints 
    WHERE jobcard_id = ?
    ORDER BY id ASC
");
$compStmt->bind_param("i", $jobcardId);
$compStmt->execute();
$compResult = $compStmt->get_result();
while ($row = $compResult->fetch_assoc()) {
    $complaints[] = $row;
}
$compStmt->close();

// Fetch part items if table exists
$partItems = [];
if (tableExists($conn, 'service_job_part_items')) {
    $partStmt = $conn->prepare("
        SELECT spi.*, p.product_name, p.product_code, p.hsn_code
        FROM service_job_part_items spi
        LEFT JOIN products p ON p.id = spi.product_id
        WHERE spi.jobcard_id = ?
        ORDER BY spi.id ASC
    ");
    $partStmt->bind_param("i", $jobcardId);
    $partStmt->execute();
    $partResult = $partStmt->get_result();
    while ($row = $partResult->fetch_assoc()) {
        $partItems[] = $row;
    }
    $partStmt->close();
}

// Fetch labor items if table exists
$laborItems = [];
if (tableExists($conn, 'service_job_labor_items')) {
    $laborStmt = $conn->prepare("
        SELECT sli.*, slm.labor_name
        FROM service_job_labor_items sli
        LEFT JOIN service_labor_master slm ON slm.id = sli.labor_id
        WHERE sli.jobcard_id = ?
        ORDER BY sli.id ASC
    ");
    $laborStmt->bind_param("i", $jobcardId);
    $laborStmt->execute();
    $laborResult = $laborStmt->get_result();
    while ($row = $laborResult->fetch_assoc()) {
        $laborItems[] = $row;
    }
    $laborStmt->close();
}

// Fetch service invoice if exists
$serviceInvoice = null;
if (tableExists($conn, 'service_invoices')) {
    $invStmt = $conn->prepare("
        SELECT invoice_no, invoice_date, grand_total, payment_status
        FROM service_invoices
        WHERE jobcard_id = ? AND business_id = ?
        LIMIT 1
    ");
    $invStmt->bind_param("ii", $jobcardId, $businessId);
    $invStmt->execute();
    $serviceInvoice = $invStmt->get_result()->fetch_assoc();
    $invStmt->close();
}

$statusBadge = '';
$statusText = ucwords(str_replace('_', ' ', $jobcard['job_status']));
if ($jobcard['job_status'] == 'open') $statusBadge = 'primary';
elseif ($jobcard['job_status'] == 'in_progress') $statusBadge = 'warning';
elseif ($jobcard['job_status'] == 'waiting_parts') $statusBadge = 'dark';
elseif ($jobcard['job_status'] == 'ready') $statusBadge = 'info';
elseif ($jobcard['job_status'] == 'delivered') $statusBadge = 'success';
elseif ($jobcard['job_status'] == 'cancelled') $statusBadge = 'danger';

$pageTitle = 'View Service Job Card';
$currentPage = 'service-jobcards';
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
                        <h4 class="mb-1">Service Job Card Details</h4>
                        <p class="text-muted mb-0">View service job card information</p>
                    </div>
                    <div class="col-md-6 text-md-end mt-3 mt-md-0">
                        <a href="service-jobcard-edit.php?id=<?php echo $jobcardId; ?>" class="btn btn-primary me-2">Edit Job Card</a>
                        <a href="service-jobcard-print.php?id=<?php echo $jobcardId; ?>" class="btn btn-secondary me-2" target="_blank">Print</a>
                        <a href="service-jobcards.php" class="btn btn-light">Back to List</a>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-body">
                                <div class="text-center mb-4">
                                    <h4 class="mb-1"><?php echo h($jobcard['jobcard_no']); ?></h4>
                                    <span class="badge bg-<?php echo $statusBadge; ?> badge-lg" style="font-size: 14px; padding: 5px 15px;">
                                        <?php echo $statusText; ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Job Card Information</h5>
                            </div>
                            <div class="card-body">
                                <table class="table table-bordered table-sm">
                                    <tr>
                                        <th style="width: 40%;">Job Card No</th>
                                        <td><strong><?php echo h($jobcard['jobcard_no']); ?></strong></td>
                                    </tr>
                                    <tr>
                                        <th>Branch</th>
                                        <td><?php echo h($jobcard['branch_name'] ?: '-'); ?> (<?php echo h($jobcard['branch_code'] ?: '-'); ?>)</td>
                                    </tr>
                                    <tr>
                                        <th>Service Date</th>
                                        <td><?php echo !empty($jobcard['service_date']) ? date('d M Y, h:i A', strtotime($jobcard['service_date'])) : '-'; ?></td>
                                    </tr>
                                    <tr>
                                        <th>Promised Delivery</th>
                                        <td><?php echo !empty($jobcard['promised_delivery']) ? date('d M Y, h:i A', strtotime($jobcard['promised_delivery'])) : 'Not specified'; ?></td>
                                    </tr>
                                    <tr>
                                        <th>Created By</th>
                                        <td><?php echo h($jobcard['created_by_name'] ?: 'System'); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Created Date</th>
                                        <td><?php echo !empty($jobcard['created_at']) ? date('d M Y, h:i A', strtotime($jobcard['created_at'])) : '-'; ?></td>
                                    </tr>
                                    <?php if (!empty($jobcard['closed_at'])): ?>
                                    <tr>
                                        <th>Closed Date</th>
                                        <td><?php echo date('d M Y, h:i A', strtotime($jobcard['closed_at'])); ?></td>
                                    </tr>
                                    <?php endif; ?>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Branch Information</h5>
                            </div>
                            <div class="card-body">
                                <table class="table table-bordered table-sm">
                                    <tr>
                                        <th style="width: 40%;">Branch Name</th>
                                        <td><?php echo h($jobcard['branch_name'] ?: '-'); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Branch Code</th>
                                        <td><?php echo h($jobcard['branch_code'] ?: '-'); ?></td>
                                    </tr>
                                    <?php if (!empty($jobcard['branch_address1'])): ?>
                                    <tr>
                                        <th>Address</th>
                                        <td>
                                            <?php echo h($jobcard['branch_address1']); ?>
                                            <?php echo !empty($jobcard['branch_address2']) ? ', ' . h($jobcard['branch_address2']) : ''; ?><br>
                                            <?php echo h($jobcard['branch_city'] ?: ''); ?>
                                            <?php echo !empty($jobcard['branch_state']) ? ', ' . h($jobcard['branch_state']) : ''; ?>
                                            <?php echo !empty($jobcard['branch_pincode']) ? ' - ' . h($jobcard['branch_pincode']) : ''; ?>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                    <?php if (!empty($jobcard['branch_mobile'])): ?>
                                    <tr>
                                        <th>Phone</th>
                                        <td><?php echo h($jobcard['branch_mobile']); ?></td>
                                    </tr>
                                    <?php endif; ?>
                                    <?php if (!empty($jobcard['branch_email'])): ?>
                                    <tr>
                                        <th>Email</th>
                                        <td><?php echo h($jobcard['branch_email']); ?></td>
                                    </tr>
                                    <?php endif; ?>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Customer Information</h5>
                            </div>
                            <div class="card-body">
                                <table class="table table-bordered table-sm">
                                    <tr>
                                        <th style="width: 40%;">Customer Name</th>
                                        <td><strong><?php echo h($jobcard['customer_name'] ?: '-'); ?></strong></td>
                                    </tr>
                                    <tr>
                                        <th>Mobile</th>
                                        <td><?php echo h($jobcard['customer_mobile'] ?: '-'); ?></td>
                                    </tr>
                                    <?php if (!empty($jobcard['customer_alt_mobile'])): ?>
                                    <tr>
                                        <th>Alternate Mobile</th>
                                        <td><?php echo h($jobcard['customer_alt_mobile']); ?></td>
                                    </tr>
                                    <?php endif; ?>
                                    <?php if (!empty($jobcard['customer_email'])): ?>
                                    <tr>
                                        <th>Email</th>
                                        <td><?php echo h($jobcard['customer_email']); ?></td>
                                    </tr>
                                    <?php endif; ?>
                                    <?php if (!empty($jobcard['customer_address1']) || !empty($jobcard['customer_city'])): ?>
                                    <tr>
                                        <th>Address</th>
                                        <td>
                                            <?php echo h($jobcard['customer_address1'] ?: ''); ?>
                                            <?php echo !empty($jobcard['customer_address2']) ? ', ' . h($jobcard['customer_address2']) : ''; ?><br>
                                            <?php echo h($jobcard['customer_city'] ?: ''); ?>
                                            <?php echo !empty($jobcard['customer_district']) ? ', ' . h($jobcard['customer_district']) : ''; ?><br>
                                            <?php echo h($jobcard['customer_state'] ?: ''); ?>
                                            <?php echo !empty($jobcard['customer_pincode']) ? ' - ' . h($jobcard['customer_pincode']) : ''; ?>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                    <?php if (!empty($jobcard['customer_gstin'])): ?>
                                    <tr>
                                        <th>GSTIN</th>
                                        <td><?php echo h($jobcard['customer_gstin']); ?></td>
                                    </tr>
                                    <?php endif; ?>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Vehicle Information</h5>
                            </div>
                            <div class="card-body">
                                <table class="table table-bordered table-sm">
                                    <tr>
                                        <th style="width: 40%;">Vehicle</th>
                                        <td>
                                            <strong><?php echo h($jobcard['brand_name'] ?: '-'); ?></strong>
                                            <?php echo !empty($jobcard['model_name']) ? ' - ' . h($jobcard['model_name']) : ''; ?>
                                            <?php echo !empty($jobcard['variant_name']) ? ' (' . h($jobcard['variant_name']) . ')' : ''; ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>Registration No</th>
                                        <td><?php echo h($jobcard['registration_no'] ?: '-'); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Chassis No</th>
                                        <td><?php echo h($jobcard['chassis_no'] ?: '-'); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Engine No</th>
                                        <td><?php echo h($jobcard['engine_no'] ?: '-'); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Motor No</th>
                                        <td><?php echo h($jobcard['motor_no'] ?: '-'); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Color</th>
                                        <td><?php echo h($jobcard['color'] ?: '-'); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Current KM</th>
                                        <td><?php echo number_format((int)($jobcard['current_km'] ?? 0)); ?></td>
                                    </tr>
                                    <?php if (!empty($jobcard['purchase_date'])): ?>
                                    <tr>
                                        <th>Purchase Date</th>
                                        <td><?php echo date('d M Y', strtotime($jobcard['purchase_date'])); ?></td>
                                    </tr>
                                    <?php endif; ?>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Service Details</h5>
                            </div>
                            <div class="card-body">
                                <table class="table table-bordered table-sm">
                                    <tr>
                                        <th style="width: 40%;">Opening KM</th>
                                        <td><?php echo number_format((int)$jobcard['opening_km']); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Fuel Level</th>
                                        <td><?php echo h($jobcard['fuel_level'] ?: '-'); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Battery Percentage</th>
                                        <td><?php echo h($jobcard['battery_percentage'] ?: '-'); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Washing Required</th>
                                        <td><?php echo ((int)$jobcard['washing_required'] === 1) ? 'Yes' : 'No'; ?></td>
                                    </tr>
                                    <tr>
                                        <th>Road Test Required</th>
                                        <td><?php echo ((int)$jobcard['road_test_required'] === 1) ? 'Yes' : 'No'; ?></td>
                                    </tr>
                                    <?php if (!empty($jobcard['customer_voice'])): ?>
                                    <tr>
                                        <th>Customer Voice</th>
                                        <td><?php echo nl2br(h($jobcard['customer_voice'])); ?></td>
                                    </tr>
                                    <?php endif; ?>
                                    <?php if (!empty($jobcard['technician_observation'])): ?>
                                    <tr>
                                        <th>Technician Observation</th>
                                        <td><?php echo nl2br(h($jobcard['technician_observation'])); ?></td>
                                    </tr>
                                    <?php endif; ?>
                                    <?php if (!empty($jobcard['recommendation'])): ?>
                                    <tr>
                                        <th>Recommendation</th>
                                        <td><?php echo nl2br(h($jobcard['recommendation'])); ?></td>
                                    </tr>
                                    <?php endif; ?>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Battery & Charger Information</h5>
                            </div>
                            <div class="card-body">
                                <table class="table table-bordered table-sm">
                                    <tr>
                                        <th style="width: 40%;">Battery Brand</th>
                                        <td><?php echo h($jobcard['battery_brand'] ?: '-'); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Battery No</th>
                                        <td><?php echo h($jobcard['battery_no'] ?: '-'); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Battery Capacity</th>
                                        <td><?php echo h($jobcard['battery_capacity'] ?: '-'); ?></td>
                                    </tr>
                                    <?php if (!empty($jobcard['battery_warranty_upto'])): ?>
                                    <tr>
                                        <th>Battery Warranty Upto</th>
                                        <td><?php echo date('d M Y', strtotime($jobcard['battery_warranty_upto'])); ?></td>
                                    </tr>
                                    <?php endif; ?>
                                    <tr>
                                        <th>Charger Brand</th>
                                        <td><?php echo h($jobcard['charger_brand'] ?: '-'); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Charger No</th>
                                        <td><?php echo h($jobcard['charger_no'] ?: '-'); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Charger Type</th>
                                        <td><?php echo h($jobcard['charger_type'] ?: '-'); ?></td>
                                    </tr>
                                    <?php if (!empty($jobcard['charger_warranty_upto'])): ?>
                                    <tr>
                                        <th>Charger Warranty Upto</th>
                                        <td><?php echo date('d M Y', strtotime($jobcard['charger_warranty_upto'])); ?></td>
                                    </tr>
                                    <?php endif; ?>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if (!empty($complaints)): ?>
                <div class="row">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Complaints</h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-bordered table-sm">
                                        <thead>
                                            <tr>
                                                <th>#</th>
                                                <th>Complaint Text</th>
                                                <th>Priority</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php $i = 1; foreach ($complaints as $comp): ?>
                                            <tr>
                                                <td><?php echo $i++; ?></td>
                                                <td><?php echo nl2br(h($comp['complaint_text'])); ?></td>
                                                <td>
                                                    <span class="badge bg-<?php 
                                                        if ($comp['priority'] == 'low') echo 'success';
                                                        elseif ($comp['priority'] == 'medium') echo 'warning';
                                                        else echo 'danger';
                                                    ?>">
                                                        <?php echo ucfirst(h($comp['priority'])); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="badge bg-<?php 
                                                        if ($comp['status'] == 'pending') echo 'secondary';
                                                        elseif ($comp['status'] == 'checked') echo 'info';
                                                        elseif ($comp['status'] == 'resolved') echo 'success';
                                                        else echo 'danger';
                                                    ?>">
                                                        <?php echo ucwords(str_replace('_', ' ', h($comp['status']))); ?>
                                                    </span>
                                                </td>
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

                <?php if (!empty($partItems)): ?>
                <div class="row">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Parts Used</h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-bordered table-sm">
                                        <thead>
                                            <tr>
                                                <th>#</th>
                                                <th>Product Name</th>
                                                <th>Product Code</th>
                                                <th>HSN Code</th>
                                                <th>Quantity</th>
                                                <th>Unit Price</th>
                                                <th>Discount</th>
                                                <th>Total</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php $i = 1; foreach ($partItems as $item): ?>
                                            <tr>
                                                <td><?php echo $i++; ?></td>
                                                <td><?php echo h($item['product_name'] ?: '-'); ?></td>
                                                <td><?php echo h($item['product_code'] ?: '-'); ?></td>
                                                <td><?php echo h($item['hsn_code'] ?: '-'); ?></td>
                                                <td class="text-center"><?php echo number_format($item['qty'], 2); ?></td>
                                                <td class="text-right"><?php echo money($item['unit_price']); ?></td>
                                                <td class="text-right"><?php echo money($item['discount_amount']); ?></td>
                                                <td class="text-right"><?php echo money($item['line_total']); ?></td>
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

                <?php if (!empty($laborItems)): ?>
                <div class="row">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Labor Charges</h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-bordered table-sm">
                                        <thead>
                                            <tr>
                                                <th>#</th>
                                                <th>Labor Name</th>
                                                <th>Description</th>
                                                <th>Quantity</th>
                                                <th>Unit Price</th>
                                                <th>Discount</th>
                                                <th>Total</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php $i = 1; foreach ($laborItems as $item): ?>
                                            <tr>
                                                <td><?php echo $i++; ?></td>
                                                <td><?php echo h($item['labor_name'] ?: '-'); ?></td>
                                                <td><?php echo nl2br(h($item['description'])); ?></td>
                                                <td class="text-center"><?php echo number_format($item['qty'], 2); ?></td>
                                                <td class="text-right"><?php echo money($item['unit_price']); ?></td>
                                                <td class="text-right"><?php echo money($item['discount_amount']); ?></td>
                                                <td class="text-right"><?php echo money($item['line_total']); ?></td>
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

                <div class="row">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Amount Details</h5>
                            </div>
                            <div class="card-body">
                                <table class="table table-bordered table-sm">
                                    <tr>
                                        <th style="width: 50%;">Estimated Amount</th>
                                        <td class="text-right"><strong><?php echo money($jobcard['estimated_amount']); ?></strong></td>
                                    </tr>
                                    <tr>
                                        <th>Final Amount</th>
                                        <td class="text-right"><strong class="text-success"><?php echo money($jobcard['final_amount']); ?></strong></td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Invoice Information</h5>
                            </div>
                            <div class="card-body">
                                <?php if ($serviceInvoice): ?>
                                <table class="table table-bordered table-sm">
                                    <tr>
                                        <th style="width: 40%;">Invoice No</th>
                                        <td><a href="service-invoice-view.php?id=<?php echo $serviceInvoice['invoice_no']; ?>"><?php echo h($serviceInvoice['invoice_no']); ?></a></td>
                                    </tr>
                                    <tr>
                                        <th>Invoice Date</th>
                                        <td><?php echo date('d M Y', strtotime($serviceInvoice['invoice_date'])); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Invoice Amount</th>
                                        <td><?php echo money($serviceInvoice['grand_total']); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Payment Status</th>
                                        <td>
                                            <span class="badge bg-<?php 
                                                if ($serviceInvoice['payment_status'] == 'paid') echo 'success';
                                                elseif ($serviceInvoice['payment_status'] == 'partial') echo 'warning';
                                                else echo 'danger';
                                            ?>">
                                                <?php echo ucfirst($serviceInvoice['payment_status']); ?>
                                            </span>
                                        </td>
                                    </tr>
                                </table>
                                <?php else: ?>
                                <p class="text-muted text-center mb-0">No invoice generated for this job card yet.</p>
                                <?php endif; ?>
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