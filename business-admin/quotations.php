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

function fetchAllAssoc(mysqli $conn, string $sql): array
{
    $rows = [];
    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $res->free();
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

/* -------------------------------------------------------
   REQUIRED TABLES
------------------------------------------------------- */
$requiredTables = ['business_users', 'businesses', 'branches', 'customers', 'quotations'];
foreach ($requiredTables as $tbl) {
    if (!tableExists($conn, $tbl)) {
        die($tbl . ' table not found.');
    }
}

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
   DELETE
------------------------------------------------------- */
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $deleteId = isset($_POST['id']) ? (int)$_POST['id'] : 0;

    if ($deleteId <= 0) {
        $error = 'Invalid quotation id.';
    } else {
        $stmt = $conn->prepare("DELETE FROM quotations WHERE id = ? AND business_id = ? LIMIT 1");
        if (!$stmt) {
            $error = 'Failed to prepare delete query.';
        } else {
            $stmt->bind_param('ii', $deleteId, $businessId);
            if ($stmt->execute()) {
                if ($stmt->affected_rows > 0) {
                    $success = 'Quotation deleted successfully.';
                } else {
                    $error = 'Quotation not found.';
                }
            } else {
                $error = 'Failed to delete quotation.';
            }
            $stmt->close();
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
$typeFilter = trim($_GET['quotation_type'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');

$allowedTypes = ['vehicle', 'product', 'mixed'];
$allowedStatuses = ['draft', 'sent', 'approved', 'rejected', 'converted', 'expired'];

$where = ["q.business_id = {$businessId}"];

if ($search !== '') {
    $safe = $conn->real_escape_string($search);
    $where[] = "(
        q.quotation_no LIKE '%{$safe}%'
        OR c.full_name LIKE '%{$safe}%'
        OR c.mobile LIKE '%{$safe}%'
        OR br.branch_name LIKE '%{$safe}%'
        OR q.customer_note LIKE '%{$safe}%'
    )";
}

if ($branchFilter > 0) {
    $where[] = "q.branch_id = {$branchFilter}";
}

if ($customerFilter > 0) {
    $where[] = "q.customer_id = {$customerFilter}";
}

if ($typeFilter !== '' && in_array($typeFilter, $allowedTypes, true)) {
    $safe = $conn->real_escape_string($typeFilter);
    $where[] = "q.quotation_type = '{$safe}'";
}

if ($statusFilter !== '' && in_array($statusFilter, $allowedStatuses, true)) {
    $safe = $conn->real_escape_string($statusFilter);
    $where[] = "q.status = '{$safe}'";
}

if ($dateFrom !== '') {
    $safe = $conn->real_escape_string($dateFrom);
    $where[] = "DATE(q.quotation_date) >= '{$safe}'";
}

if ($dateTo !== '') {
    $safe = $conn->real_escape_string($dateTo);
    $where[] = "DATE(q.quotation_date) <= '{$safe}'";
}

$whereSql = implode(' AND ', $where);

/* -------------------------------------------------------
   SUMMARY
------------------------------------------------------- */
$totalQuotations = getCount($conn, 'quotations', "business_id = {$businessId}");
$totalAmount = getSum($conn, 'quotations', 'grand_total', "business_id = {$businessId}");

$draftCount = getCount($conn, 'quotations', "business_id = {$businessId} AND status = 'draft'");
$sentCount = getCount($conn, 'quotations', "business_id = {$businessId} AND status = 'sent'");
$approvedCount = getCount($conn, 'quotations', "business_id = {$businessId} AND status = 'approved'");
$rejectedCount = getCount($conn, 'quotations', "business_id = {$businessId} AND status = 'rejected'");
$convertedCount = getCount($conn, 'quotations', "business_id = {$businessId} AND status = 'converted'");
$expiredCount = getCount($conn, 'quotations', "business_id = {$businessId} AND status = 'expired'");

$today = date('Y-m-d');
$todayCount = getCount($conn, 'quotations', "business_id = {$businessId} AND DATE(quotation_date) = '{$today}'");
$todayAmount = getSum($conn, 'quotations', 'grand_total', "business_id = {$businessId} AND DATE(quotation_date) = '{$today}'");

/* -------------------------------------------------------
   FETCH QUOTATIONS
------------------------------------------------------- */
$rows = fetchAllAssoc(
    $conn,
    "SELECT
        q.*,
        br.branch_name,
        br.branch_code,
        c.full_name AS customer_name,
        c.mobile AS customer_mobile
     FROM quotations q
     LEFT JOIN branches br ON br.id = q.branch_id
     LEFT JOIN customers c ON c.id = q.customer_id
     WHERE {$whereSql}
     ORDER BY q.id DESC
     LIMIT 500"
);

$pageTitle = 'Quotations';
$currentPage = 'quotations';
?>
<!doctype html>
<html lang="en">
<?php include('includes/head.php'); ?>

<body data-sidebar="dark">

<style>
.page-content { padding-bottom: 90px !important; }
.card { margin-bottom: 24px; }
.main-content { min-height: calc(100vh - 70px); }
.report-last-row { margin-bottom: 40px; }
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
                        <h4 class="mb-1">Quotations</h4>
                        <p class="text-muted mb-0">Manage and view customer quotations</p>
                    </div>
                    <div class="col-md-5 text-md-end mt-3 mt-md-0">
                        <div class="btn-group">
                            <a href="quotation-add.php" class="btn btn-primary">
                                <i class="mdi mdi-plus-circle"></i> Create Quotation
                            </a>
                            <a href="index.php" class="btn btn-info">
                                <i class="mdi mdi-view-dashboard"></i> Dashboard
                            </a>
                            <a href="sales-dashboard.php" class="btn btn-secondary">
                                <i class="mdi mdi-chart-line"></i> Back to Sales
                            </a>
                        </div>
                    </div>
                </div>

                <?php if ($success !== ''): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="mdi mdi-check-circle me-2"></i> <?php echo h($success); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="mdi mdi-alert-circle me-2"></i> <?php echo h($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <div class="row">
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Total Quotations</p>
                                <h3 class="mb-0"><?php echo number_format($totalQuotations); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Total Amount</p>
                                <h3 class="mb-0 text-primary"><?php echo money($totalAmount); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Today Quotations</p>
                                <h3 class="mb-0 text-info"><?php echo number_format($todayCount); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Today Amount</p>
                                <h3 class="mb-0 text-success"><?php echo money($todayAmount); ?></h3>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-2">
                        <div class="card"><div class="card-body text-center"><p class="text-muted mb-1">Draft</p><h5 class="mb-0"><?php echo number_format($draftCount); ?></h5></div></div>
                    </div>
                    <div class="col-md-2">
                        <div class="card"><div class="card-body text-center"><p class="text-muted mb-1">Sent</p><h5 class="mb-0 text-primary"><?php echo number_format($sentCount); ?></h5></div></div>
                    </div>
                    <div class="col-md-2">
                        <div class="card"><div class="card-body text-center"><p class="text-muted mb-1">Approved</p><h5 class="mb-0 text-success"><?php echo number_format($approvedCount); ?></h5></div></div>
                    </div>
                    <div class="col-md-2">
                        <div class="card"><div class="card-body text-center"><p class="text-muted mb-1">Rejected</p><h5 class="mb-0 text-danger"><?php echo number_format($rejectedCount); ?></h5></div></div>
                    </div>
                    <div class="col-md-2">
                        <div class="card"><div class="card-body text-center"><p class="text-muted mb-1">Converted</p><h5 class="mb-0 text-warning"><?php echo number_format($convertedCount); ?></h5></div></div>
                    </div>
                    <div class="col-md-2">
                        <div class="card"><div class="card-body text-center"><p class="text-muted mb-1">Expired</p><h5 class="mb-0 text-dark"><?php echo number_format($expiredCount); ?></h5></div></div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-body">
                        <form method="get" class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label">Search</label>
                                <input
                                    type="text"
                                    name="search"
                                    class="form-control"
                                    placeholder="Quotation no, customer, mobile..."
                                    value="<?php echo h($search); ?>"
                                >
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">Branch</label>
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
                                <label class="form-label">Customer</label>
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
                                <label class="form-label">Type</label>
                                <select name="quotation_type" class="form-select">
                                    <option value="">All Types</option>
                                    <option value="vehicle" <?php echo ($typeFilter === 'vehicle') ? 'selected' : ''; ?>>Vehicle</option>
                                    <option value="product" <?php echo ($typeFilter === 'product') ? 'selected' : ''; ?>>Product</option>
                                    <option value="mixed" <?php echo ($typeFilter === 'mixed') ? 'selected' : ''; ?>>Mixed</option>
                                </select>
                            </div>

                            <div class="col-md-1">
                                <label class="form-label">Status</label>
                                <select name="status" class="form-select">
                                    <option value="">All</option>
                                    <option value="draft" <?php echo ($statusFilter === 'draft') ? 'selected' : ''; ?>>Draft</option>
                                    <option value="sent" <?php echo ($statusFilter === 'sent') ? 'selected' : ''; ?>>Sent</option>
                                    <option value="approved" <?php echo ($statusFilter === 'approved') ? 'selected' : ''; ?>>Approved</option>
                                    <option value="rejected" <?php echo ($statusFilter === 'rejected') ? 'selected' : ''; ?>>Rejected</option>
                                    <option value="converted" <?php echo ($statusFilter === 'converted') ? 'selected' : ''; ?>>Converted</option>
                                    <option value="expired" <?php echo ($statusFilter === 'expired') ? 'selected' : ''; ?>>Expired</option>
                                </select>
                            </div>

                            <div class="col-md-1">
                                <label class="form-label">From</label>
                                <input type="date" name="date_from" class="form-control" value="<?php echo h($dateFrom); ?>">
                            </div>

                            <div class="col-md-1">
                                <label class="form-label">To</label>
                                <input type="date" name="date_to" class="form-control" value="<?php echo h($dateTo); ?>">
                            </div>

                            <div class="col-md-12 d-flex gap-2">
                                <button type="submit" class="btn btn-primary">Filter</button>
                                <a href="quotations.php" class="btn btn-light">Reset</a>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card report-last-row">
                    <div class="card-body">
                        <h4 class="card-title mb-4">Quotation List</h4>

                        <div class="table-responsive">
                            <table class="table table-bordered table-striped align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Quotation</th>
                                        <th>Date</th>
                                        <th>Branch</th>
                                        <th>Customer</th>
                                        <th>Type</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                        <th style="width:260px;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($rows)): ?>
                                        <?php $i = 1; foreach ($rows as $row): ?>
                                            <?php
                                            $statusBadge = 'secondary';
                                            if (($row['status'] ?? '') === 'draft') $statusBadge = 'secondary';
                                            elseif (($row['status'] ?? '') === 'sent') $statusBadge = 'primary';
                                            elseif (($row['status'] ?? '') === 'approved') $statusBadge = 'success';
                                            elseif (($row['status'] ?? '') === 'rejected') $statusBadge = 'danger';
                                            elseif (($row['status'] ?? '') === 'converted') $statusBadge = 'warning';
                                            elseif (($row['status'] ?? '') === 'expired') $statusBadge = 'dark';
                                            ?>
                                            <tr>
                                                <td><?php echo $i++; ?></td>

                                                <td class="small">
                                                    <div><strong><?php echo h($row['quotation_no']); ?></strong></div>
                                                    <?php if (!empty($row['valid_until'])): ?>
                                                        <div class="text-muted">Valid Until: <?php echo h(date('d M Y', strtotime($row['valid_until']))); ?></div>
                                                    <?php endif; ?>
                                                 </div>

                                                <td class="small">
                                                    <div><?php echo !empty($row['quotation_date']) ? h(date('d M Y h:i A', strtotime($row['quotation_date']))) : '-'; ?></div>
                                                    <div class="text-muted">Created: <?php echo !empty($row['created_at']) ? h(date('d M Y', strtotime($row['created_at']))) : '-'; ?></div>
                                                 </div>

                                                <td class="small">
                                                    <div><?php echo h($row['branch_name'] ?: '-'); ?></div>
                                                    <div class="text-muted"><?php echo h($row['branch_code'] ?: '-'); ?></div>
                                                 </div>

                                                <td class="small">
                                                    <div><strong><?php echo h($row['customer_name'] ?: '-'); ?></strong></div>
                                                    <div class="text-muted"><?php echo h($row['customer_mobile'] ?: '-'); ?></div>
                                                 </div>

                                                <td>
                                                    <span class="badge bg-info">
                                                        <?php echo h(ucwords(str_replace('_', ' ', (string)$row['quotation_type']))); ?>
                                                    </span>
                                                 </div>

                                                <td class="small">
                                                    <div><strong>Subtotal:</strong> <?php echo money($row['subtotal']); ?></div>
                                                    <div><strong>Discount:</strong> <?php echo money($row['discount_amount']); ?></div>
                                                    <div><strong>Total:</strong> <?php echo money($row['grand_total']); ?></div>
                                                 </div>

                                                <td>
                                                    <span class="badge bg-<?php echo $statusBadge; ?>">
                                                        <?php echo h(ucfirst((string)$row['status'])); ?>
                                                    </span>
                                                 </div>

                                                <td>
                                                    <div class="d-flex flex-wrap gap-2">
                                                        <a href="quotation-view.php?id=<?php echo (int)$row['id']; ?>" class="btn btn-sm btn-info">View</a>
                                                        <a href="quotation-edit.php?id=<?php echo (int)$row['id']; ?>" class="btn btn-sm btn-primary">Edit</a>
                                                        <a href="quotation-print.php?id=<?php echo (int)$row['id']; ?>" class="btn btn-sm btn-secondary" target="_blank">Print</a>

                                                        <form method="post" style="display:inline;" onsubmit="return confirm('Delete this quotation?');">
                                                            <input type="hidden" name="action" value="delete">
                                                            <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                                                            <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                                                        </form>
                                                    </div>
                                                 </div>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="9" class="text-center text-muted py-4">No quotations found. </div>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="mt-3 text-muted small">
                            Showing latest 500 quotation records.
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