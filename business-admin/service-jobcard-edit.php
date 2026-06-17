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
$requiredTables = [
    'service_job_cards',
    'service_complaints',
    'branches',
    'customers',
    'customer_vehicles'
];
foreach ($requiredTables as $tbl) {
    if (!tableExists($conn, $tbl)) {
        die($tbl . ' table not found.');
    }
}

/* -------------------------------------------------------
   FETCH JOB CARD DETAILS
------------------------------------------------------- */
$jobcardId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($jobcardId <= 0) {
    header('Location: service-jobcards.php');
    exit;
}

// Fetch job card details
$stmt = $conn->prepare("
    SELECT sj.*, 
           c.full_name AS customer_name,
           c.mobile AS customer_mobile,
           cv.registration_no,
           cv.chassis_no,
           cv.current_km AS vehicle_current_km,
           cv.color,
           vb.brand_name,
           vm.model_name,
           vm.variant_name
    FROM service_job_cards sj
    LEFT JOIN customers c ON c.id = sj.customer_id
    LEFT JOIN customer_vehicles cv ON cv.id = sj.customer_vehicle_id
    LEFT JOIN vehicle_brands vb ON vb.id = cv.brand_id
    LEFT JOIN vehicle_models vm ON vm.id = cv.model_id
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

// If no complaints, add empty row
if (empty($complaints)) {
    $complaints[] = [
        'id' => 0,
        'complaint_text' => '',
        'priority' => 'medium',
        'status' => 'pending'
    ];
}

/* -------------------------------------------------------
   MASTER DATA
------------------------------------------------------- */
$branches = fetchAllAssoc(
    $conn,
    "SELECT id, branch_name, branch_code
     FROM branches
     WHERE business_id = {$businessId}
       AND status = 'active'
     ORDER BY branch_name ASC"
);

$customers = fetchAllAssoc(
    $conn,
    "SELECT id, customer_code, full_name, mobile, city
     FROM customers
     WHERE business_id = {$businessId}
     ORDER BY full_name ASC"
);

$customerVehicles = [];
$sqlVehicles = "SELECT
                    cv.id,
                    cv.customer_id,
                    cv.vehicle_type,
                    cv.registration_no,
                    cv.chassis_no,
                    cv.engine_no,
                    cv.motor_no,
                    cv.color,
                    cv.current_km,
                    cv.purchase_date,
                    cv.battery_no,
                    cv.charger_no,
                    vb.brand_name,
                    vm.model_name,
                    vm.variant_name,
                    c.full_name AS customer_name
                FROM customer_vehicles cv
                LEFT JOIN vehicle_brands vb ON vb.id = cv.brand_id
                LEFT JOIN vehicle_models vm ON vm.id = cv.model_id
                LEFT JOIN customers c ON c.id = cv.customer_id
                WHERE cv.business_id = {$businessId}
                ORDER BY c.full_name ASC, cv.id DESC";
$resVehicles = $conn->query($sqlVehicles);
if ($resVehicles) {
    while ($row = $resVehicles->fetch_assoc()) {
        $customerVehicles[] = $row;
    }
}

/* -------------------------------------------------------
   UPDATE JOB CARD
------------------------------------------------------- */
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update') {
    $branch_id = isset($_POST['branch_id']) ? (int)$_POST['branch_id'] : 0;
    $customer_id = isset($_POST['customer_id']) ? (int)$_POST['customer_id'] : 0;
    $customer_vehicle_id = isset($_POST['customer_vehicle_id']) ? (int)$_POST['customer_vehicle_id'] : 0;
    $service_date = trim($_POST['service_date'] ?? '');
    $opening_km = trim($_POST['opening_km'] ?? '0');
    $fuel_level = trim($_POST['fuel_level'] ?? '');
    $battery_percentage = trim($_POST['battery_percentage'] ?? '');
    $promised_delivery = trim($_POST['promised_delivery'] ?? '');
    $job_status = trim($_POST['job_status'] ?? 'open');
    $washing_required = isset($_POST['washing_required']) ? 1 : 0;
    $road_test_required = isset($_POST['road_test_required']) ? 1 : 0;
    $estimated_amount = trim($_POST['estimated_amount'] ?? '0.00');
    $final_amount = trim($_POST['final_amount'] ?? '0.00');
    $customer_voice = trim($_POST['customer_voice'] ?? '');
    $technician_observation = trim($_POST['technician_observation'] ?? '');
    $recommendation = trim($_POST['recommendation'] ?? '');

    $postedComplaints = $_POST['complaints'] ?? [];
    $updatedComplaints = [];

    if (is_array($postedComplaints)) {
        foreach ($postedComplaints as $idx => $row) {
            $text = trim($row['complaint_text'] ?? '');
            $priority = trim($row['priority'] ?? 'medium');
            $compId = isset($row['id']) ? (int)$row['id'] : 0;
            $status = trim($row['status'] ?? 'pending');

            if ($text !== '') {
                $updatedComplaints[] = [
                    'id' => $compId,
                    'complaint_text' => $text,
                    'priority' => $priority,
                    'status' => $status
                ];
            }
        }
    }

    $allowedJobStatuses = ['open', 'in_progress', 'waiting_parts', 'ready', 'delivered', 'cancelled'];
    $allowedPriorities = ['low', 'medium', 'high'];
    $allowedComplaintStatuses = ['pending', 'checked', 'resolved', 'not_resolved'];

    if ($branch_id <= 0) {
        $error = 'Please select branch.';
    } elseif ($customer_id <= 0) {
        $error = 'Please select customer.';
    } elseif ($customer_vehicle_id <= 0) {
        $error = 'Please select customer vehicle.';
    } elseif ($service_date === '') {
        $error = 'Service date is required.';
    } elseif (!in_array($job_status, $allowedJobStatuses, true)) {
        $error = 'Invalid job status.';
    }

    if ($error === '') {
        // Check if customer vehicle belongs to selected customer
        $stmt = $conn->prepare("SELECT id FROM customer_vehicles WHERE id = ? AND customer_id = ? AND business_id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('iii', $customer_vehicle_id, $customer_id, $businessId);
            $stmt->execute();
            $vehCheck = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$vehCheck) {
                $error = 'Selected vehicle does not belong to selected customer.';
            }
        }
    }

    if ($error === '') {
        foreach ($updatedComplaints as $k => $comp) {
            if (!in_array($comp['priority'], $allowedPriorities, true)) {
                $error = 'Invalid complaint priority in row ' . ($k + 1) . '.';
                break;
            }
            if (!in_array($comp['status'], $allowedComplaintStatuses, true)) {
                $error = 'Invalid complaint status in row ' . ($k + 1) . '.';
                break;
            }
        }
    }

    if ($error === '') {
        $openingKmInt = (int)$opening_km;
        $estimatedAmount = round((float)$estimated_amount, 2);
        $finalAmount = round((float)$final_amount, 2);

        if ($openingKmInt < 0) {
            $error = 'Opening KM cannot be negative.';
        } elseif ($estimatedAmount < 0 || $finalAmount < 0) {
            $error = 'Amounts cannot be negative.';
        } else {
            $conn->begin_transaction();

            try {
                $serviceDateSql = date('Y-m-d H:i:s', strtotime($service_date));
                $promisedDeliverySql = null;
                if ($promised_delivery !== '') {
                    $promisedDeliverySql = date('Y-m-d H:i:s', strtotime($promised_delivery));
                }

                // Update job card
                $stmt = $conn->prepare("UPDATE service_job_cards SET 
                                            branch_id = ?,
                                            customer_id = ?,
                                            customer_vehicle_id = ?,
                                            service_date = ?,
                                            opening_km = ?,
                                            fuel_level = ?,
                                            battery_percentage = ?,
                                            promised_delivery = ?,
                                            job_status = ?,
                                            washing_required = ?,
                                            road_test_required = ?,
                                            estimated_amount = ?,
                                            final_amount = ?,
                                            customer_voice = ?,
                                            technician_observation = ?,
                                            recommendation = ?
                                        WHERE id = ? AND business_id = ?");

                if (!$stmt) {
                    throw new Exception('Unable to prepare update query.');
                }

                $stmt->bind_param(
                    'iiisissiiiddssssii',
                    $branch_id,
                    $customer_id,
                    $customer_vehicle_id,
                    $serviceDateSql,
                    $openingKmInt,
                    $fuel_level,
                    $battery_percentage,
                    $promisedDeliverySql,
                    $job_status,
                    $washing_required,
                    $road_test_required,
                    $estimatedAmount,
                    $finalAmount,
                    $customer_voice,
                    $technician_observation,
                    $recommendation,
                    $jobcardId,
                    $businessId
                );

                if (!$stmt->execute()) {
                    throw new Exception('Failed to update job card.');
                }
                $stmt->close();

                // Update complaints
                // First, get existing complaint IDs
                $existingCompIds = [];
                $existingStmt = $conn->prepare("SELECT id FROM service_complaints WHERE jobcard_id = ?");
                $existingStmt->bind_param("i", $jobcardId);
                $existingStmt->execute();
                $existingResult = $existingStmt->get_result();
                while ($row = $existingResult->fetch_assoc()) {
                    $existingCompIds[] = $row['id'];
                }
                $existingStmt->close();

                $processedCompIds = [];

                // Insert/Update complaints
                $compStmt = $conn->prepare("INSERT INTO service_complaints (jobcard_id, complaint_text, priority, status) VALUES (?, ?, ?, ?)");
                $updateCompStmt = $conn->prepare("UPDATE service_complaints SET complaint_text = ?, priority = ?, status = ? WHERE id = ? AND jobcard_id = ?");

                foreach ($updatedComplaints as $comp) {
                    if ($comp['id'] > 0) {
                        // Update existing complaint
                        $updateCompStmt->bind_param('sssii', $comp['complaint_text'], $comp['priority'], $comp['status'], $comp['id'], $jobcardId);
                        if (!$updateCompStmt->execute()) {
                            throw new Exception('Failed to update complaint.');
                        }
                        $processedCompIds[] = $comp['id'];
                    } else {
                        // Insert new complaint
                        $compStmt->bind_param('isss', $jobcardId, $comp['complaint_text'], $comp['priority'], $comp['status']);
                        if (!$compStmt->execute()) {
                            throw new Exception('Failed to insert new complaint.');
                        }
                        $processedCompIds[] = $compStmt->insert_id;
                    }
                }

                $compStmt->close();
                $updateCompStmt->close();

                // Delete complaints that were removed
                $toDelete = array_diff($existingCompIds, $processedCompIds);
                if (!empty($toDelete)) {
                    $deleteStmt = $conn->prepare("DELETE FROM service_complaints WHERE id = ? AND jobcard_id = ?");
                    foreach ($toDelete as $delId) {
                        $deleteStmt->bind_param('ii', $delId, $jobcardId);
                        if (!$deleteStmt->execute()) {
                            throw new Exception('Failed to delete complaint.');
                        }
                    }
                    $deleteStmt->close();
                }

                $conn->commit();
                $success = 'Job card updated successfully.';

                // Refresh jobcard data
                $jobcard['branch_id'] = $branch_id;
                $jobcard['customer_id'] = $customer_id;
                $jobcard['customer_vehicle_id'] = $customer_vehicle_id;
                $jobcard['service_date'] = $service_date;
                $jobcard['opening_km'] = $opening_km;
                $jobcard['fuel_level'] = $fuel_level;
                $jobcard['battery_percentage'] = $battery_percentage;
                $jobcard['promised_delivery'] = $promised_delivery;
                $jobcard['job_status'] = $job_status;
                $jobcard['washing_required'] = $washing_required;
                $jobcard['road_test_required'] = $road_test_required;
                $jobcard['estimated_amount'] = $estimated_amount;
                $jobcard['final_amount'] = $final_amount;
                $jobcard['customer_voice'] = $customer_voice;
                $jobcard['technician_observation'] = $technician_observation;
                $jobcard['recommendation'] = $recommendation;

                // Refresh complaints
                $complaints = $updatedComplaints;
                if (empty($complaints)) {
                    $complaints[] = [
                        'id' => 0,
                        'complaint_text' => '',
                        'priority' => 'medium',
                        'status' => 'pending'
                    ];
                }

            } catch (Exception $e) {
                $conn->rollback();
                $error = $e->getMessage();
            }
        }
    }
}

$pageTitle = 'Edit Service Job Card';
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
                        <h4 class="mb-1">Edit Service Job Card</h4>
                        <p class="text-muted mb-0">Update service job card details</p>
                    </div>
                    <div class="col-md-6 text-md-end mt-3 mt-md-0">
                        <a href="service-jobcards.php" class="btn btn-secondary">Back to List</a>
                    </div>
                </div>

                <?php if ($success !== ''): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?php echo h($success); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?php echo h($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <form method="post" id="jobcardForm">
                    <input type="hidden" name="action" value="update">
                    
                    <div class="card">
                        <div class="card-body">
                            <h4 class="card-title mb-4">Job Card Details</h4>

                            <div class="row">
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Job Card No</label>
                                    <input type="text" class="form-control" readonly value="<?php echo h($jobcard['jobcard_no']); ?>">
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Branch</label>
                                    <select name="branch_id" id="branch_id" class="form-select" required>
                                        <option value="">Select Branch</option>
                                        <?php foreach ($branches as $b): ?>
                                            <option value="<?php echo (int)$b['id']; ?>" <?php echo ((int)$jobcard['branch_id'] === (int)$b['id']) ? 'selected' : ''; ?>>
                                                <?php echo h($b['branch_name'] . ' (' . $b['branch_code'] . ')'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Service Date</label>
                                    <input type="datetime-local" name="service_date" class="form-control" 
                                           value="<?php echo !empty($jobcard['service_date']) ? date('Y-m-d\TH:i', strtotime($jobcard['service_date'])) : ''; ?>" required>
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Promised Delivery</label>
                                    <input type="datetime-local" name="promised_delivery" class="form-control" 
                                           value="<?php echo !empty($jobcard['promised_delivery']) ? date('Y-m-d\TH:i', strtotime($jobcard['promised_delivery'])) : ''; ?>">
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Customer</label>
                                    <select name="customer_id" id="customer_id" class="form-select" required>
                                        <option value="0">Select Customer</option>
                                        <?php foreach ($customers as $c): ?>
                                            <option value="<?php echo (int)$c['id']; ?>" <?php echo ((int)$jobcard['customer_id'] === (int)$c['id']) ? 'selected' : ''; ?>>
                                                <?php echo h($c['full_name'] . (!empty($c['mobile']) ? ' - ' . $c['mobile'] : '')); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Customer Vehicle</label>
                                    <select name="customer_vehicle_id" id="customer_vehicle_id" class="form-select" required>
                                        <option value="0">Select Vehicle</option>
                                        <?php foreach ($customerVehicles as $v): ?>
                                            <?php
                                            $vehicleLabel = ($v['brand_name'] ?: 'Vehicle') . ' - ' .
                                                            ($v['model_name'] ?: '-') .
                                                            (!empty($v['variant_name']) ? ' / ' . $v['variant_name'] : '') .
                                                            (!empty($v['registration_no']) ? ' | Reg: ' . $v['registration_no'] : '');
                                            ?>
                                            <option value="<?php echo (int)$v['id']; ?>"
                                                    data-customer-id="<?php echo (int)$v['customer_id']; ?>"
                                                    data-km="<?php echo h($v['current_km']); ?>"
                                                    <?php echo ((int)$jobcard['customer_vehicle_id'] === (int)$v['id']) ? 'selected' : ''; ?>>
                                                <?php echo h($vehicleLabel); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Opening KM</label>
                                    <input type="number" min="0" name="opening_km" id="opening_km" class="form-control" 
                                           value="<?php echo h($jobcard['opening_km']); ?>">
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Fuel Level</label>
                                    <input type="text" name="fuel_level" class="form-control" 
                                           value="<?php echo h($jobcard['fuel_level']); ?>" placeholder="E, 1/4, Half, Full">
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Battery Percentage</label>
                                    <input type="text" name="battery_percentage" class="form-control" 
                                           value="<?php echo h($jobcard['battery_percentage']); ?>" placeholder="50%, 80%">
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Job Status</label>
                                    <select name="job_status" class="form-select">
                                        <option value="open" <?php echo ($jobcard['job_status'] === 'open') ? 'selected' : ''; ?>>Open</option>
                                        <option value="in_progress" <?php echo ($jobcard['job_status'] === 'in_progress') ? 'selected' : ''; ?>>In Progress</option>
                                        <option value="waiting_parts" <?php echo ($jobcard['job_status'] === 'waiting_parts') ? 'selected' : ''; ?>>Waiting Parts</option>
                                        <option value="ready" <?php echo ($jobcard['job_status'] === 'ready') ? 'selected' : ''; ?>>Ready</option>
                                        <option value="delivered" <?php echo ($jobcard['job_status'] === 'delivered') ? 'selected' : ''; ?>>Delivered</option>
                                        <option value="cancelled" <?php echo ($jobcard['job_status'] === 'cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                                    </select>
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Estimated Amount</label>
                                    <input type="number" step="0.01" min="0" name="estimated_amount" class="form-control" 
                                           value="<?php echo h($jobcard['estimated_amount']); ?>">
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Final Amount</label>
                                    <input type="number" step="0.01" min="0" name="final_amount" class="form-control" 
                                           value="<?php echo h($jobcard['final_amount']); ?>">
                                </div>

                                <div class="col-md-3 mb-3 d-flex align-items-end">
                                    <div class="form-check me-4">
                                        <input class="form-check-input" type="checkbox" name="washing_required" id="washing_required" 
                                               <?php echo ((int)$jobcard['washing_required'] === 1) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="washing_required">Washing Required</label>
                                    </div>
                                </div>

                                <div class="col-md-3 mb-3 d-flex align-items-end">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="road_test_required" id="road_test_required" 
                                               <?php echo ((int)$jobcard['road_test_required'] === 1) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="road_test_required">Road Test Required</label>
                                    </div>
                                </div>

                                <div class="col-md-12 mb-3">
                                    <label class="form-label">Customer Voice</label>
                                    <textarea name="customer_voice" class="form-control" rows="2"><?php echo h($jobcard['customer_voice']); ?></textarea>
                                </div>

                                <div class="col-md-12 mb-3">
                                    <label class="form-label">Technician Observation</label>
                                    <textarea name="technician_observation" class="form-control" rows="2"><?php echo h($jobcard['technician_observation']); ?></textarea>
                                </div>

                                <div class="col-md-12 mb-3">
                                    <label class="form-label">Recommendation</label>
                                    <textarea name="recommendation" class="form-control" rows="2"><?php echo h($jobcard['recommendation']); ?></textarea>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h4 class="card-title mb-0">Complaints</h4>
                                <button type="button" class="btn btn-primary btn-sm" onclick="addComplaintRow()">Add Complaint</button>
                            </div>

                            <div class="table-responsive">
                                <table class="table table-bordered align-middle" id="complaintsTable">
                                    <thead>
                                        <tr>
                                            <th style="min-width:400px;">Complaint Text</th>
                                            <th style="min-width:120px;">Priority</th>
                                            <th style="min-width:120px;">Status</th>
                                            <th style="min-width:80px;">Action</th>
                                        </thead>
                                    <tbody id="complaintBody">
                                        <?php foreach ($complaints as $i => $c): ?>
                                            <tr>
                                                <td>
                                                    <input type="hidden" name="complaints[<?php echo $i; ?>][id]" value="<?php echo (int)$c['id']; ?>">
                                                    <input type="text" name="complaints[<?php echo $i; ?>][complaint_text]" class="form-control" 
                                                           value="<?php echo h($c['complaint_text']); ?>" placeholder="Enter complaint">
                                                </td>
                                                <td>
                                                    <select name="complaints[<?php echo $i; ?>][priority]" class="form-select">
                                                        <option value="low" <?php echo ($c['priority'] === 'low') ? 'selected' : ''; ?>>Low</option>
                                                        <option value="medium" <?php echo ($c['priority'] === 'medium') ? 'selected' : ''; ?>>Medium</option>
                                                        <option value="high" <?php echo ($c['priority'] === 'high') ? 'selected' : ''; ?>>High</option>
                                                    </select>
                                                </td>
                                                <td>
                                                    <select name="complaints[<?php echo $i; ?>][status]" class="form-select">
                                                        <option value="pending" <?php echo ($c['status'] === 'pending') ? 'selected' : ''; ?>>Pending</option>
                                                        <option value="checked" <?php echo ($c['status'] === 'checked') ? 'selected' : ''; ?>>Checked</option>
                                                        <option value="resolved" <?php echo ($c['status'] === 'resolved') ? 'selected' : ''; ?>>Resolved</option>
                                                        <option value="not_resolved" <?php echo ($c['status'] === 'not_resolved') ? 'selected' : ''; ?>>Not Resolved</option>
                                                    </select>
                                                </td>
                                                <td class="text-center">
                                                    <button type="button" class="btn btn-danger btn-sm" onclick="removeComplaintRow(this)">X</button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <div class="mt-4">
                                <button type="submit" class="btn btn-success">Update Job Card</button>
                                <a href="service-jobcards.php" class="btn btn-light">Cancel</a>
                                <a href="service-jobcard-view.php?id=<?php echo $jobcardId; ?>" class="btn btn-info">View Job Card</a>
                            </div>
                        </div>
                    </div>
                </form>

            </div>
        </div>

        <?php include('includes/footer.php'); ?>
    </div>
</div>

<?php include('includes/rightbar.php'); ?>
<?php include('includes/scripts.php'); ?>

<script>
let complaintIndex = <?php echo count($complaints); ?>;

function addComplaintRow() {
    const tbody = document.getElementById('complaintBody');
    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td>
            <input type="hidden" name="complaints[${complaintIndex}][id]" value="0">
            <input type="text" name="complaints[${complaintIndex}][complaint_text]" class="form-control" placeholder="Enter complaint">
        </td>
        <td>
            <select name="complaints[${complaintIndex}][priority]" class="form-select">
                <option value="low">Low</option>
                <option value="medium" selected>Medium</option>
                <option value="high">High</option>
            </select>
        </td>
        <td>
            <select name="complaints[${complaintIndex}][status]" class="form-select">
                <option value="pending" selected>Pending</option>
                <option value="checked">Checked</option>
                <option value="resolved">Resolved</option>
                <option value="not_resolved">Not Resolved</option>
            </select>
        </td>
        <td class="text-center">
            <button type="button" class="btn btn-danger btn-sm" onclick="removeComplaintRow(this)">X</button>
        </td>
    `;
    tbody.appendChild(tr);
    complaintIndex++;
}

function removeComplaintRow(btn) {
    const tbody = document.getElementById('complaintBody');
    if (tbody.querySelectorAll('tr').length > 1) {
        btn.closest('tr').remove();
    }
}

function filterVehiclesByCustomer() {
    const customerId = document.getElementById('customer_id').value;
    const vehicleSelect = document.getElementById('customer_vehicle_id');
    const options = vehicleSelect.querySelectorAll('option');

    options.forEach((opt, index) => {
        if (index === 0) {
            opt.hidden = false;
            return;
        }

        const optCustomerId = opt.getAttribute('data-customer-id') || '';
        opt.hidden = customerId !== '0' && optCustomerId !== customerId;
    });

    if (vehicleSelect.selectedOptions.length > 0) {
        const selected = vehicleSelect.selectedOptions[0];
        if (selected.hidden) {
            vehicleSelect.value = '0';
        }
    }
}

function setOpeningKmFromVehicle() {
    const vehicleSelect = document.getElementById('customer_vehicle_id');
    const kmInput = document.getElementById('opening_km');
    const selected = vehicleSelect.selectedOptions[0];

    if (selected && selected.value !== '0') {
        const km = selected.getAttribute('data-km') || '0';
        if (!kmInput.value || kmInput.value === '0') {
            kmInput.value = km;
        }
    }
}

document.getElementById('customer_id').addEventListener('change', function() {
    filterVehiclesByCustomer();
});

document.getElementById('customer_vehicle_id').addEventListener('change', function() {
    setOpeningKmFromVehicle();
});

// Initial filter
filterVehiclesByCustomer();
</script>

</body>
</html>