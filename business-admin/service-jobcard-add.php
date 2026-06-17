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

function generateJobcardNo(mysqli $conn, int $businessId, int $branchId): string
{
    $prefix = 'JOB';
    $nextNo = 1;

    $stmt = $conn->prepare("SELECT id
                            FROM service_job_cards
                            WHERE business_id = ? AND branch_id = ?
                            ORDER BY id DESC
                            LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('ii', $businessId, $branchId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row && isset($row['id'])) {
            $nextNo = ((int)$row['id']) + 1;
        }
    }

    return $prefix . '-' . date('Ymd') . '-' . str_pad((string)$nextNo, 4, '0', STR_PAD_LEFT);
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
   DEFAULT FORM
------------------------------------------------------- */
$selectedBranch = $currentBranchId > 0 ? $currentBranchId : ((int)($branches[0]['id'] ?? 0));

$form = [
    'branch_id'               => $selectedBranch,
    'jobcard_no'              => $selectedBranch > 0 ? generateJobcardNo($conn, $businessId, $selectedBranch) : '',
    'customer_id'             => 0,
    'customer_vehicle_id'     => 0,
    'service_date'            => date('Y-m-d\TH:i'),
    'opening_km'              => '0',
    'fuel_level'              => '',
    'battery_percentage'      => '',
    'promised_delivery'       => '',
    'job_status'              => 'open',
    'washing_required'        => 0,
    'road_test_required'      => 0,
    'estimated_amount'        => '0.00',
    'final_amount'            => '0.00',
    'customer_voice'          => '',
    'technician_observation'  => '',
    'recommendation'          => ''
];

$complaints = [
    [
        'complaint_text' => '',
        'priority'       => 'medium'
    ]
];

$success = '';
$error = '';

/* -------------------------------------------------------
   SAVE JOB CARD
------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['branch_id']              = isset($_POST['branch_id']) ? (int)$_POST['branch_id'] : 0;
    $form['jobcard_no']             = trim($_POST['jobcard_no'] ?? '');
    $form['customer_id']            = isset($_POST['customer_id']) ? (int)$_POST['customer_id'] : 0;
    $form['customer_vehicle_id']    = isset($_POST['customer_vehicle_id']) ? (int)$_POST['customer_vehicle_id'] : 0;
    $form['service_date']           = trim($_POST['service_date'] ?? date('Y-m-d\TH:i'));
    $form['opening_km']             = trim($_POST['opening_km'] ?? '0');
    $form['fuel_level']             = trim($_POST['fuel_level'] ?? '');
    $form['battery_percentage']     = trim($_POST['battery_percentage'] ?? '');
    $form['promised_delivery']      = trim($_POST['promised_delivery'] ?? '');
    $form['job_status']             = trim($_POST['job_status'] ?? 'open');
    $form['washing_required']       = isset($_POST['washing_required']) ? 1 : 0;
    $form['road_test_required']     = isset($_POST['road_test_required']) ? 1 : 0;
    $form['estimated_amount']       = trim($_POST['estimated_amount'] ?? '0.00');
    $form['final_amount']           = trim($_POST['final_amount'] ?? '0.00');
    $form['customer_voice']         = trim($_POST['customer_voice'] ?? '');
    $form['technician_observation'] = trim($_POST['technician_observation'] ?? '');
    $form['recommendation']         = trim($_POST['recommendation'] ?? '');

    $postedComplaints = $_POST['complaints'] ?? [];
    $complaints = [];

    if (is_array($postedComplaints)) {
        foreach ($postedComplaints as $row) {
            $text = trim($row['complaint_text'] ?? '');
            $priority = trim($row['priority'] ?? 'medium');

            if ($text !== '') {
                $complaints[] = [
                    'complaint_text' => $text,
                    'priority'       => $priority
                ];
            }
        }
    }

    if (empty($complaints)) {
        $complaints[] = [
            'complaint_text' => '',
            'priority'       => 'medium'
        ];
    }

    $allowedJobStatuses = ['open', 'in_progress', 'waiting_parts', 'ready', 'delivered', 'cancelled'];
    $allowedPriorities = ['low', 'medium', 'high'];

    if ($form['branch_id'] <= 0) {
        $error = 'Please select branch.';
    } elseif ($form['jobcard_no'] === '') {
        $error = 'Job card number is required.';
    } elseif ($form['customer_id'] <= 0) {
        $error = 'Please select customer.';
    } elseif ($form['customer_vehicle_id'] <= 0) {
        $error = 'Please select customer vehicle.';
    } elseif ($form['service_date'] === '') {
        $error = 'Service date is required.';
    } elseif (!in_array($form['job_status'], $allowedJobStatuses, true)) {
        $error = 'Invalid job status.';
    }

    if ($error === '') {
        $stmt = $conn->prepare("SELECT id
                                FROM branches
                                WHERE id = ? AND business_id = ?
                                LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('ii', $form['branch_id'], $businessId);
            $stmt->execute();
            $branchCheck = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$branchCheck) {
                $error = 'Invalid branch selected.';
            }
        }
    }

    if ($error === '') {
        $stmt = $conn->prepare("SELECT id
                                FROM customers
                                WHERE id = ? AND business_id = ?
                                LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('ii', $form['customer_id'], $businessId);
            $stmt->execute();
            $custCheck = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$custCheck) {
                $error = 'Invalid customer selected.';
            }
        }
    }

    if ($error === '') {
        $stmt = $conn->prepare("SELECT id
                                FROM customer_vehicles
                                WHERE id = ? AND customer_id = ? AND business_id = ?
                                LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('iii', $form['customer_vehicle_id'], $form['customer_id'], $businessId);
            $stmt->execute();
            $vehCheck = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$vehCheck) {
                $error = 'Selected vehicle does not belong to selected customer.';
            }
        }
    }

    if ($error === '') {
        $stmt = $conn->prepare("SELECT id
                                FROM service_job_cards
                                WHERE branch_id = ? AND jobcard_no = ?
                                LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('is', $form['branch_id'], $form['jobcard_no']);
            $stmt->execute();
            $dup = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($dup) {
                $error = 'Job card number already exists for this branch.';
            }
        }
    }

    if ($error === '') {
        foreach ($complaints as $k => $comp) {
            if ($comp['complaint_text'] === '') {
                continue;
            }
            if (!in_array($comp['priority'], $allowedPriorities, true)) {
                $error = 'Invalid complaint priority in row ' . ($k + 1) . '.';
                break;
            }
        }
    }

    if ($error === '') {
        $openingKm = (int)$form['opening_km'];
        $estimatedAmount = round((float)$form['estimated_amount'], 2);
        $finalAmount = round((float)$form['final_amount'], 2);

        if ($openingKm < 0) {
            $error = 'Opening KM cannot be negative.';
        } elseif ($estimatedAmount < 0 || $finalAmount < 0) {
            $error = 'Amounts cannot be negative.';
        } else {
            $conn->begin_transaction();

            try {
                $serviceDateSql = date('Y-m-d H:i:s', strtotime($form['service_date']));
                $promisedDeliverySql = null;
                if ($form['promised_delivery'] !== '') {
                    $promisedDeliverySql = date('Y-m-d H:i:s', strtotime($form['promised_delivery']));
                }

                $stmt = $conn->prepare("INSERT INTO service_job_cards (
                                            business_id,
                                            branch_id,
                                            jobcard_no,
                                            customer_id,
                                            customer_vehicle_id,
                                            service_date,
                                            opening_km,
                                            fuel_level,
                                            battery_percentage,
                                            promised_delivery,
                                            job_status,
                                            washing_required,
                                            road_test_required,
                                            estimated_amount,
                                            final_amount,
                                            customer_voice,
                                            technician_observation,
                                            recommendation,
                                            created_by,
                                            created_at,
                                            closed_at
                                        ) VALUES (
                                            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NULL
                                        )");
                if (!$stmt) {
                    throw new Exception('Unable to prepare job card insert query.');
                }

                $stmt->bind_param(
                    'iisiisisssiiiddsssi',
                    $businessId,
                    $form['branch_id'],
                    $form['jobcard_no'],
                    $form['customer_id'],
                    $form['customer_vehicle_id'],
                    $serviceDateSql,
                    $openingKm,
                    $form['fuel_level'],
                    $form['battery_percentage'],
                    $promisedDeliverySql,
                    $form['job_status'],
                    $form['washing_required'],
                    $form['road_test_required'],
                    $estimatedAmount,
                    $finalAmount,
                    $form['customer_voice'],
                    $form['technician_observation'],
                    $form['recommendation'],
                    $businessUserId
                );

                if (!$stmt->execute()) {
                    throw new Exception('Failed to save job card.');
                }

                $jobcardId = (int)$stmt->insert_id;
                $stmt->close();

                $compStmt = $conn->prepare("INSERT INTO service_complaints (
                                                jobcard_id,
                                                complaint_text,
                                                priority,
                                                status
                                            ) VALUES (
                                                ?, ?, ?, 'pending'
                                            )");
                if (!$compStmt) {
                    throw new Exception('Unable to prepare complaint insert query.');
                }

                foreach ($complaints as $comp) {
                    if (trim($comp['complaint_text']) === '') {
                        continue;
                    }

                    $compStmt->bind_param(
                        'iss',
                        $jobcardId,
                        $comp['complaint_text'],
                        $comp['priority']
                    );

                    if (!$compStmt->execute()) {
                        throw new Exception('Failed to save complaint row.');
                    }
                }

                $compStmt->close();

                $conn->commit();
                header('Location: service-jobcards.php?success=Job card created successfully');
                exit;
            } catch (Exception $e) {
                $conn->rollback();
                $error = $e->getMessage();
            }
        }
    }
}

$pageTitle = 'Add Service Job Card';
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
                        <h4 class="mb-1">Add Service Job Card</h4>
                        <p class="text-muted mb-0">Create a new service job card</p>
                    </div>
                    <div class="col-md-6 text-md-end mt-3 mt-md-0">
                        <a href="service-jobcards.php" class="btn btn-secondary">Back</a>
                    </div>
                </div>

                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger"><?php echo h($error); ?></div>
                <?php endif; ?>

                <form method="post" id="jobcardForm">
                    <div class="card">
                        <div class="card-body">
                            <h4 class="card-title mb-4">Job Card Details</h4>

                            <div class="row">
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Branch</label>
                                    <select name="branch_id" id="branch_id" class="form-select" required>
                                        <option value="">Select Branch</option>
                                        <?php foreach ($branches as $b): ?>
                                            <option value="<?php echo (int)$b['id']; ?>" <?php echo ((int)$form['branch_id'] === (int)$b['id']) ? 'selected' : ''; ?>>
                                                <?php echo h($b['branch_name'] . ' (' . $b['branch_code'] . ')'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Job Card No</label>
                                    <input type="text" name="jobcard_no" class="form-control" value="<?php echo h($form['jobcard_no']); ?>" required>
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Service Date</label>
                                    <input type="datetime-local" name="service_date" class="form-control" value="<?php echo h($form['service_date']); ?>" required>
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Promised Delivery</label>
                                    <input type="datetime-local" name="promised_delivery" class="form-control" value="<?php echo h($form['promised_delivery']); ?>">
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Customer</label>
                                    <select name="customer_id" id="customer_id" class="form-select" required>
                                        <option value="0">Select Customer</option>
                                        <?php foreach ($customers as $c): ?>
                                            <option value="<?php echo (int)$c['id']; ?>" <?php echo ((int)$form['customer_id'] === (int)$c['id']) ? 'selected' : ''; ?>>
                                                <?php
                                                echo h(
                                                    $c['full_name']
                                                    . (!empty($c['customer_code']) ? ' (' . $c['customer_code'] . ')' : '')
                                                    . (!empty($c['mobile']) ? ' - ' . $c['mobile'] : '')
                                                );
                                                ?>
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
                                            $vehicleLabel =
                                                ($v['brand_name'] ?: 'Vehicle') . ' - ' .
                                                ($v['model_name'] ?: '-') .
                                                (!empty($v['variant_name']) ? ' / ' . $v['variant_name'] : '') .
                                                (!empty($v['registration_no']) ? ' | Reg: ' . $v['registration_no'] : '') .
                                                (!empty($v['chassis_no']) ? ' | Chassis: ' . $v['chassis_no'] : '');
                                            ?>
                                            <option
                                                value="<?php echo (int)$v['id']; ?>"
                                                data-customer-id="<?php echo (int)$v['customer_id']; ?>"
                                                data-km="<?php echo h($v['current_km']); ?>"
                                                <?php echo ((int)$form['customer_vehicle_id'] === (int)$v['id']) ? 'selected' : ''; ?>
                                            >
                                                <?php echo h($vehicleLabel); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Opening KM</label>
                                    <input type="number" min="0" name="opening_km" id="opening_km" class="form-control" value="<?php echo h($form['opening_km']); ?>">
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Fuel Level</label>
                                    <input type="text" name="fuel_level" class="form-control" value="<?php echo h($form['fuel_level']); ?>" placeholder="E, 1/4, Half, Full">
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Battery Percentage</label>
                                    <input type="text" name="battery_percentage" class="form-control" value="<?php echo h($form['battery_percentage']); ?>" placeholder="50%, 80%">
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Job Status</label>
                                    <select name="job_status" class="form-select">
                                        <option value="open" <?php echo ($form['job_status'] === 'open') ? 'selected' : ''; ?>>Open</option>
                                        <option value="in_progress" <?php echo ($form['job_status'] === 'in_progress') ? 'selected' : ''; ?>>In Progress</option>
                                        <option value="waiting_parts" <?php echo ($form['job_status'] === 'waiting_parts') ? 'selected' : ''; ?>>Waiting Parts</option>
                                        <option value="ready" <?php echo ($form['job_status'] === 'ready') ? 'selected' : ''; ?>>Ready</option>
                                        <option value="delivered" <?php echo ($form['job_status'] === 'delivered') ? 'selected' : ''; ?>>Delivered</option>
                                        <option value="cancelled" <?php echo ($form['job_status'] === 'cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                                    </select>
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Estimated Amount</label>
                                    <input type="number" step="0.01" min="0" name="estimated_amount" class="form-control" value="<?php echo h($form['estimated_amount']); ?>">
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Final Amount</label>
                                    <input type="number" step="0.01" min="0" name="final_amount" class="form-control" value="<?php echo h($form['final_amount']); ?>">
                                </div>

                                <div class="col-md-3 mb-3 d-flex align-items-end">
                                    <div class="form-check me-4">
                                        <input class="form-check-input" type="checkbox" name="washing_required" id="washing_required" <?php echo ((int)$form['washing_required'] === 1) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="washing_required">Washing Required</label>
                                    </div>
                                </div>

                                <div class="col-md-3 mb-3 d-flex align-items-end">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="road_test_required" id="road_test_required" <?php echo ((int)$form['road_test_required'] === 1) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="road_test_required">Road Test Required</label>
                                    </div>
                                </div>

                                <div class="col-md-12 mb-3">
                                    <label class="form-label">Customer Voice</label>
                                    <textarea name="customer_voice" class="form-control" rows="2"><?php echo h($form['customer_voice']); ?></textarea>
                                </div>

                                <div class="col-md-12 mb-3">
                                    <label class="form-label">Technician Observation</label>
                                    <textarea name="technician_observation" class="form-control" rows="2"><?php echo h($form['technician_observation']); ?></textarea>
                                </div>

                                <div class="col-md-12 mb-3">
                                    <label class="form-label">Recommendation</label>
                                    <textarea name="recommendation" class="form-control" rows="2"><?php echo h($form['recommendation']); ?></textarea>
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
                                            <th style="min-width:420px;">Complaint Text</th>
                                            <th style="min-width:140px;">Priority</th>
                                            <th style="min-width:80px;">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody id="complaintBody">
                                        <?php foreach ($complaints as $i => $c): ?>
                                            <tr>
                                                <td>
                                                    <input type="text" name="complaints[<?php echo $i; ?>][complaint_text]" class="form-control" value="<?php echo h($c['complaint_text']); ?>" placeholder="Enter complaint">
                                                </td>
                                                <td>
                                                    <select name="complaints[<?php echo $i; ?>][priority]" class="form-select">
                                                        <option value="low" <?php echo ($c['priority'] === 'low') ? 'selected' : ''; ?>>Low</option>
                                                        <option value="medium" <?php echo ($c['priority'] === 'medium') ? 'selected' : ''; ?>>Medium</option>
                                                        <option value="high" <?php echo ($c['priority'] === 'high') ? 'selected' : ''; ?>>High</option>
                                                    </select>
                                                </td>
                                                <td>
                                                    <button type="button" class="btn btn-danger btn-sm" onclick="removeComplaintRow(this)">X</button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <div class="mt-4">
                                <button type="submit" class="btn btn-success">Save Job Card</button>
                                <a href="service-jobcards.php" class="btn btn-light">Cancel</a>
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

    if (selected) {
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

filterVehiclesByCustomer();
</script>

</body>
</html>