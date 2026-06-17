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

function getSum(mysqli $conn, string $table, string $field, string $where = '1=1'): float
{
    $sql = "SELECT COALESCE(SUM({$field}),0) AS total FROM {$table} WHERE {$where}";
    $res = $conn->query($sql);
    if (!$res) {
        return 0;
    }
    $row = $res->fetch_assoc();
    return (float)($row['total'] ?? 0);
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
    'customers',
    'customer_vehicles',
    'branches',
    'service_complaints'
];
foreach ($requiredTables as $tbl) {
    if (!tableExists($conn, $tbl)) {
        die($tbl . ' table not found.');
    }
}

/* -------------------------------------------------------
   DELETE
------------------------------------------------------- */
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $jobcardId = isset($_POST['id']) ? (int)$_POST['id'] : 0;

    if ($jobcardId <= 0) {
        $error = 'Invalid job card id.';
    } else {
        $conn->begin_transaction();

        try {
            if (tableExists($conn, 'service_complaints')) {
                $stmt = $conn->prepare("DELETE sc
                                        FROM service_complaints sc
                                        INNER JOIN service_job_cards sj ON sj.id = sc.jobcard_id
                                        WHERE sc.jobcard_id = ? AND sj.business_id = ?");
                if (!$stmt) {
                    throw new Exception('Unable to prepare complaints delete query.');
                }
                $stmt->bind_param('ii', $jobcardId, $businessId);
                if (!$stmt->execute()) {
                    throw new Exception('Failed to delete complaints.');
                }
                $stmt->close();
            }

            if (tableExists($conn, 'service_job_part_items')) {
                $stmt = $conn->prepare("DELETE spi
                                        FROM service_job_part_items spi
                                        INNER JOIN service_job_cards sj ON sj.id = spi.jobcard_id
                                        WHERE spi.jobcard_id = ? AND sj.business_id = ?");
                if ($stmt) {
                    $stmt->bind_param('ii', $jobcardId, $businessId);
                    $stmt->execute();
                    $stmt->close();
                }
            }

            if (tableExists($conn, 'service_job_labor_items')) {
                $stmt = $conn->prepare("DELETE sli
                                        FROM service_job_labor_items sli
                                        INNER JOIN service_job_cards sj ON sj.id = sli.jobcard_id
                                        WHERE sli.jobcard_id = ? AND sj.business_id = ?");
                if ($stmt) {
                    $stmt->bind_param('ii', $jobcardId, $businessId);
                    $stmt->execute();
                    $stmt->close();
                }
            }

            if (tableExists($conn, 'service_invoices')) {
                $stmt = $conn->prepare("SELECT COUNT(*) AS total
                                        FROM service_invoices
                                        WHERE business_id = ? AND jobcard_id = ?");
                if ($stmt) {
                    $stmt->bind_param('ii', $businessId, $jobcardId);
                    $stmt->execute();
                    $invRow = $stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    if ((int)($invRow['total'] ?? 0) > 0) {
                        throw new Exception('Cannot delete job card because service invoice exists.');
                    }
                }
            }

            $stmt = $conn->prepare("DELETE FROM service_job_cards
                                    WHERE id = ? AND business_id = ?
                                    LIMIT 1");
            if (!$stmt) {
                throw new Exception('Unable to prepare job card delete query.');
            }

            $stmt->bind_param('ii', $jobcardId, $businessId);
            if (!$stmt->execute()) {
                throw new Exception('Failed to delete job card.');
            }

            if ($stmt->affected_rows <= 0) {
                throw new Exception('Job card not found or already deleted.');
            }

            $stmt->close();
            $conn->commit();
            $success = 'Service job card deleted successfully.';
        } catch (Exception $e) {
            $conn->rollback();
            $error = $e->getMessage();
        }
    }
}

if (isset($_GET['success']) && trim($_GET['success']) !== '') {
    $success = trim($_GET['success']);
}

/* -------------------------------------------------------
   MASTER DATA
------------------------------------------------------- */
$branches = fetchAllAssoc(
    $conn,
    "SELECT id, branch_name, branch_code
     FROM branches
     WHERE business_id = {$businessId}
     ORDER BY branch_name ASC"
);

$customers = fetchAllAssoc(
    $conn,
    "SELECT id, full_name, mobile
     FROM customers
     WHERE business_id = {$businessId}
     ORDER BY full_name ASC"
);

/* -------------------------------------------------------
   FILTERS
------------------------------------------------------- */
$search = trim($_GET['search'] ?? '');
$branchFilter = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : 0;
$customerFilter = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;
$jobStatusFilter = trim($_GET['job_status'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');

$allowedJobStatuses = ['open', 'in_progress', 'waiting_parts', 'ready', 'delivered', 'cancelled'];

$where = ["sj.business_id = {$businessId}"];

if ($search !== '') {
    $safe = $conn->real_escape_string($search);
    $where[] = "(
        sj.jobcard_no LIKE '%{$safe}%'
        OR c.full_name LIKE '%{$safe}%'
        OR c.mobile LIKE '%{$safe}%'
        OR br.branch_name LIKE '%{$safe}%'
        OR cv.registration_no LIKE '%{$safe}%'
        OR cv.chassis_no LIKE '%{$safe}%'
        OR cv.engine_no LIKE '%{$safe}%'
        OR cv.motor_no LIKE '%{$safe}%'
        OR sj.customer_voice LIKE '%{$safe}%'
        OR sj.technician_observation LIKE '%{$safe}%'
        OR sj.recommendation LIKE '%{$safe}%'
    )";
}

if ($branchFilter > 0) {
    $where[] = "sj.branch_id = {$branchFilter}";
}

if ($customerFilter > 0) {
    $where[] = "sj.customer_id = {$customerFilter}";
}

if ($jobStatusFilter !== '' && in_array($jobStatusFilter, $allowedJobStatuses, true)) {
    $safe = $conn->real_escape_string($jobStatusFilter);
    $where[] = "sj.job_status = '{$safe}'";
}

if ($dateFrom !== '') {
    $safe = $conn->real_escape_string($dateFrom);
    $where[] = "DATE(sj.service_date) >= '{$safe}'";
}

if ($dateTo !== '') {
    $safe = $conn->real_escape_string($dateTo);
    $where[] = "DATE(sj.service_date) <= '{$safe}'";
}

$whereSql = implode(' AND ', $where);

/* -------------------------------------------------------
   SUMMARY
------------------------------------------------------- */
$totalJobcards = getCount($conn, 'service_job_cards', "business_id = {$businessId}");
$openJobcards = getCount($conn, 'service_job_cards', "business_id = {$businessId} AND job_status = 'open'");
$inProgressJobcards = getCount($conn, 'service_job_cards', "business_id = {$businessId} AND job_status = 'in_progress'");
$readyJobcards = getCount($conn, 'service_job_cards', "business_id = {$businessId} AND job_status = 'ready'");
$deliveredJobcards = getCount($conn, 'service_job_cards', "business_id = {$businessId} AND job_status = 'delivered'");
$totalEstimated = getSum($conn, 'service_job_cards', 'estimated_amount', "business_id = {$businessId}");
$totalFinal = getSum($conn, 'service_job_cards', 'final_amount', "business_id = {$businessId}");

$today = date('Y-m-d');
$todayJobcards = getCount($conn, 'service_job_cards', "business_id = {$businessId} AND DATE(service_date) = '{$today}'");

/* -------------------------------------------------------
   FETCH JOB CARDS
------------------------------------------------------- */
$jobcardRows = [];

$sql = "SELECT
            sj.*,
            br.branch_name,
            br.branch_code,
            c.full_name AS customer_name,
            c.mobile AS customer_mobile,
            c.city AS customer_city,
            bu.full_name AS created_by_name,

            cv.vehicle_type,
            cv.registration_no,
            cv.chassis_no,
            cv.engine_no,
            cv.motor_no,
            cv.color,
            cv.current_km,
            cv.battery_no,
            cv.charger_no,

            vb.brand_name,
            vm.model_name,
            vm.variant_name,

            (
                SELECT COUNT(*)
                FROM service_complaints sc
                WHERE sc.jobcard_id = sj.id
            ) AS complaint_count

        FROM service_job_cards sj
        LEFT JOIN branches br ON br.id = sj.branch_id
        LEFT JOIN customers c ON c.id = sj.customer_id
        LEFT JOIN business_users bu ON bu.id = sj.created_by
        LEFT JOIN customer_vehicles cv ON cv.id = sj.customer_vehicle_id
        LEFT JOIN vehicle_brands vb ON vb.id = cv.brand_id
        LEFT JOIN vehicle_models vm ON vm.id = cv.model_id

        WHERE {$whereSql}
        ORDER BY sj.id DESC";

$res = $conn->query($sql);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $jobcardRows[] = $row;
    }
}

$pageTitle = 'Service Job Cards';
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
                        <h4 class="mb-1">Service Job Cards</h4>
                        <p class="text-muted mb-0">Manage and view service job cards</p>
                    </div>
                    <div class="col-md-6 text-md-end mt-3 mt-md-0">
                        <a href="service-jobcard-add.php" class="btn btn-primary">Add Job Card</a>
                    </div>
                </div>

                <?php if ($success !== ''): ?>
                    <div class="alert alert-success"><?php echo h($success); ?></div>
                <?php endif; ?>

                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger"><?php echo h($error); ?></div>
                <?php endif; ?>

                <div class="row">
                    <div class="col-md-2">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Total</p>
                                <h4 class="mb-0"><?php echo number_format($totalJobcards); ?></h4>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-2">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Open</p>
                                <h4 class="mb-0 text-primary"><?php echo number_format($openJobcards); ?></h4>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-2">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">In Progress</p>
                                <h4 class="mb-0 text-warning"><?php echo number_format($inProgressJobcards); ?></h4>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-2">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Ready</p>
                                <h4 class="mb-0 text-info"><?php echo number_format($readyJobcards); ?></h4>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-2">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Delivered</p>
                                <h4 class="mb-0 text-success"><?php echo number_format($deliveredJobcards); ?></h4>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-2">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Today</p>
                                <h4 class="mb-0 text-dark"><?php echo number_format($todayJobcards); ?></h4>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Estimated Amount</p>
                                <h4 class="mb-0 text-primary"><?php echo money($totalEstimated); ?></h4>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Final Amount</p>
                                <h4 class="mb-0 text-success"><?php echo money($totalFinal); ?></h4>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- FILTERS -->
                <div class="card">
                    <div class="card-body">
                        <form method="get" class="row g-3">
                            <div class="col-md-4">
                                <input
                                    type="text"
                                    name="search"
                                    class="form-control"
                                    placeholder="Search job card, customer, vehicle, complaint..."
                                    value="<?php echo h($search); ?>"
                                >
                            </div>

                            <div class="col-md-2">
                                <select name="branch_id" class="form-select">
                                    <option value="0">All Branches</option>
                                    <?php foreach ($branches as $b): ?>
                                        <option value="<?php echo (int)$b['id']; ?>" <?php echo ($branchFilter === (int)$b['id']) ? 'selected' : ''; ?>>
                                            <?php echo h($b['branch_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-2">
                                <select name="customer_id" class="form-select">
                                    <option value="0">All Customers</option>
                                    <?php foreach ($customers as $c): ?>
                                        <option value="<?php echo (int)$c['id']; ?>" <?php echo ($customerFilter === (int)$c['id']) ? 'selected' : ''; ?>>
                                            <?php echo h($c['full_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-2">
                                <select name="job_status" class="form-select">
                                    <option value="">All Status</option>
                                    <option value="open" <?php echo ($jobStatusFilter === 'open') ? 'selected' : ''; ?>>Open</option>
                                    <option value="in_progress" <?php echo ($jobStatusFilter === 'in_progress') ? 'selected' : ''; ?>>In Progress</option>
                                    <option value="waiting_parts" <?php echo ($jobStatusFilter === 'waiting_parts') ? 'selected' : ''; ?>>Waiting Parts</option>
                                    <option value="ready" <?php echo ($jobStatusFilter === 'ready') ? 'selected' : ''; ?>>Ready</option>
                                    <option value="delivered" <?php echo ($jobStatusFilter === 'delivered') ? 'selected' : ''; ?>>Delivered</option>
                                    <option value="cancelled" <?php echo ($jobStatusFilter === 'cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                                </select>
                            </div>

                            <div class="col-md-1">
                                <input type="date" name="date_from" class="form-control" value="<?php echo h($dateFrom); ?>">
                            </div>

                            <div class="col-md-1">
                                <input type="date" name="date_to" class="form-control" value="<?php echo h($dateTo); ?>">
                            </div>

                            <div class="col-md-12 d-flex gap-2">
                                <button type="submit" class="btn btn-secondary">Filter</button>
                                <a href="service-jobcards.php" class="btn btn-light">Reset</a>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- LIST -->
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4">Job Card List</h4>

                        <div class="table-responsive">
                            <table class="table table-bordered table-striped align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Job Card</th>
                                        <th>Date</th>
                                        <th>Branch</th>
                                        <th>Customer</th>
                                        <th>Vehicle</th>
                                        <th>KM / Service</th>
                                        <th>Complaints</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                        <th style="width:240px;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($jobcardRows)): ?>
                                        <?php $i = 1; foreach ($jobcardRows as $row): ?>
                                            <tr>
                                                <td><?php echo $i++; ?></td>

                                                <td>
                                                    <strong><?php echo h($row['jobcard_no']); ?></strong>
                                                    <div class="small text-muted">
                                                        By: <?php echo h($row['created_by_name'] ?: '-'); ?>
                                                    </div>
                                                </td>

                                                <td>
                                                    <div><?php echo !empty($row['service_date']) ? h(date('d M Y h:i A', strtotime($row['service_date']))) : '-'; ?></div>
                                                    <div class="small text-muted">
                                                        Promise:
                                                        <?php echo !empty($row['promised_delivery']) ? h(date('d M Y h:i A', strtotime($row['promised_delivery']))) : '-'; ?>
                                                    </div>
                                                </td>

                                                <td>
                                                    <div><?php echo h($row['branch_name'] ?: '-'); ?></div>
                                                    <div class="small text-muted"><?php echo h($row['branch_code'] ?: '-'); ?></div>
                                                </td>

                                                <td>
                                                    <div><strong><?php echo h($row['customer_name'] ?: '-'); ?></strong></div>
                                                    <div class="small text-muted"><?php echo h($row['customer_mobile'] ?: '-'); ?></div>
                                                    <div class="small text-muted"><?php echo h($row['customer_city'] ?: '-'); ?></div>
                                                </td>

                                                <td class="small">
                                                    <div><strong><?php echo h(($row['brand_name'] ?: 'Vehicle') . ' - ' . ($row['model_name'] ?: '-')); ?></strong></div>
                                                    <div><?php echo h($row['variant_name'] ?: '-'); ?></div>
                                                    <div>Reg: <?php echo h($row['registration_no'] ?: '-'); ?></div>
                                                    <div>Chassis: <?php echo h($row['chassis_no'] ?: '-'); ?></div>
                                                    <div>Engine: <?php echo h($row['engine_no'] ?: '-'); ?></div>
                                                    <div>Motor: <?php echo h($row['motor_no'] ?: '-'); ?></div>
                                                    <div>Color: <?php echo h($row['color'] ?: '-'); ?></div>
                                                </td>

                                                <td class="small">
                                                    <div><strong>Opening KM:</strong> <?php echo number_format((int)$row['opening_km']); ?></div>
                                                    <div><strong>Current KM:</strong> <?php echo number_format((int)($row['current_km'] ?? 0)); ?></div>
                                                    <div><strong>Fuel:</strong> <?php echo h($row['fuel_level'] ?: '-'); ?></div>
                                                    <div><strong>Battery:</strong> <?php echo h($row['battery_percentage'] ?: '-'); ?></div>
                                                    <div><strong>Wash:</strong> <?php echo ((int)$row['washing_required'] === 1) ? 'Yes' : 'No'; ?></div>
                                                    <div><strong>Road Test:</strong> <?php echo ((int)$row['road_test_required'] === 1) ? 'Yes' : 'No'; ?></div>
                                                </td>

                                                <td>
                                                    <span class="badge bg-info">
                                                        <?php echo number_format((int)($row['complaint_count'] ?? 0)); ?>
                                                    </span>
                                                    <div class="small text-muted mt-1">
                                                        Voice: <?php echo h($row['customer_voice'] ?: '-'); ?>
                                                    </div>
                                                </td>

                                                <td class="small">
                                                    <div><strong>Estimated:</strong> <?php echo money($row['estimated_amount']); ?></div>
                                                    <div><strong>Final:</strong> <?php echo money($row['final_amount']); ?></div>
                                                </td>

                                                <td>
                                                    <?php
                                                    $status = strtolower((string)($row['job_status'] ?? 'open'));
                                                    $badge = 'secondary';

                                                    if ($status === 'open') $badge = 'primary';
                                                    elseif ($status === 'in_progress') $badge = 'warning';
                                                    elseif ($status === 'waiting_parts') $badge = 'dark';
                                                    elseif ($status === 'ready') $badge = 'info';
                                                    elseif ($status === 'delivered') $badge = 'success';
                                                    elseif ($status === 'cancelled') $badge = 'danger';
                                                    ?>
                                                    <span class="badge bg-<?php echo $badge; ?>">
                                                        <?php echo h(ucwords(str_replace('_', ' ', $status))); ?>
                                                    </span>
                                                </td>

                                                <td>
                                                    <div class="d-flex flex-wrap gap-2">
                                                        <a href="service-jobcard-view.php?id=<?php echo (int)$row['id']; ?>" class="btn btn-sm btn-info">View</a>
                                                        <a href="service-jobcard-edit.php?id=<?php echo (int)$row['id']; ?>" class="btn btn-sm btn-primary">Edit</a>
                                                        <a href="service-jobcard-print.php?id=<?php echo (int)$row['id']; ?>" class="btn btn-sm btn-secondary" target="_blank">Print</a>

                                                        <form method="post" onsubmit="return confirm('Delete this service job card?');" style="display:inline;">
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
                                            <td colspan="11" class="text-center text-muted py-4">No service job cards found.</td>
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