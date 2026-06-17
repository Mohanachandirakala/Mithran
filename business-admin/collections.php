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

if (!$loggedUser || (int)$loggedUser['status'] !== 1 || ($loggedUser['business_status'] ?? '') !== 'active') {
    session_destroy();
    header('Location: login.php');
    exit;
}

/* -------------------------------------------------------
   TABLE CHECKS
------------------------------------------------------- */
if (!tableExists($conn, 'payments')) {
    die('payments table not found.');
}
if (!tableExists($conn, 'branches')) {
    die('branches table not found.');
}
if (!tableExists($conn, 'payment_methods')) {
    die('payment_methods table not found.');
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

$paymentMethods = fetchAllAssoc(
    $conn,
    "SELECT id, method_name
     FROM payment_methods
     WHERE business_id = {$businessId}
     ORDER BY method_name ASC"
);

$customers = [];
if (tableExists($conn, 'customers')) {
    $customers = fetchAllAssoc(
        $conn,
        "SELECT id, full_name, mobile
         FROM customers
         WHERE business_id = {$businessId}
         ORDER BY full_name ASC"
    );
}

/* -------------------------------------------------------
   FILTERS
------------------------------------------------------- */
$allowedPaymentFor = ['sales', 'service', 'other'];

$search = trim($_GET['search'] ?? '');
$branchFilter = (int)($_GET['branch_id'] ?? 0);
$customerFilter = (int)($_GET['customer_id'] ?? 0);
$paymentForFilter = trim($_GET['payment_for'] ?? '');
$methodFilter = (int)($_GET['payment_method_id'] ?? 0);
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo   = trim($_GET['date_to'] ?? '');

$where = ["p.business_id = {$businessId}"];

if ($branchFilter > 0) {
    $where[] = "p.branch_id = {$branchFilter}";
}

if ($customerFilter > 0) {
    $where[] = "p.customer_id = {$customerFilter}";
}

if ($paymentForFilter !== '' && in_array($paymentForFilter, $allowedPaymentFor, true)) {
    $safe = $conn->real_escape_string($paymentForFilter);
    $where[] = "p.payment_for = '{$safe}'";
}

if ($methodFilter > 0) {
    $where[] = "p.payment_method_id = {$methodFilter}";
}

if ($dateFrom !== '') {
    $safe = $conn->real_escape_string($dateFrom);
    $where[] = "DATE(p.payment_date) >= '{$safe}'";
}

if ($dateTo !== '') {
    $safe = $conn->real_escape_string($dateTo);
    $where[] = "DATE(p.payment_date) <= '{$safe}'";
}

if ($search !== '') {
    $safe = $conn->real_escape_string($search);
    $where[] = "(
        c.full_name LIKE '%{$safe}%'
        OR c.mobile LIKE '%{$safe}%'
        OR pm.method_name LIKE '%{$safe}%'
        OR br.branch_name LIKE '%{$safe}%'
        OR p.reference_no LIKE '%{$safe}%'
        OR p.transaction_no LIKE '%{$safe}%'
        OR p.notes LIKE '%{$safe}%'
    )";
}

$whereSql = implode(' AND ', $where);

/* -------------------------------------------------------
   SUMMARY
------------------------------------------------------- */
$totalCollectionsCount = getCount($conn, 'payments', "business_id = {$businessId}");
$totalCollectionsAmount = getSum($conn, 'payments', 'amount', "business_id = {$businessId}");

$todayDate = date('Y-m-d');
$todayCollectionsCount = getCount($conn, 'payments', "business_id = {$businessId} AND DATE(payment_date) = '{$todayDate}'");
$todayCollectionsAmount = getSum($conn, 'payments', 'amount', "business_id = {$businessId} AND DATE(payment_date) = '{$todayDate}'");

$salesCollectionAmount = getSum($conn, 'payments', 'amount', "business_id = {$businessId} AND payment_for = 'sales'");
$serviceCollectionAmount = getSum($conn, 'payments', 'amount', "business_id = {$businessId} AND payment_for = 'service'");
$otherCollectionAmount = getSum($conn, 'payments', 'amount', "business_id = {$businessId} AND payment_for = 'other'");

/* -------------------------------------------------------
   FILTERED SUMMARY
------------------------------------------------------- */
$filteredCollectionsCount = 0;
$filteredCollectionsAmount = 0;

$sqlFilteredSummary = "SELECT COUNT(*) AS total_count, COALESCE(SUM(p.amount),0) AS total_amount
                       FROM payments p
                       LEFT JOIN branches br ON br.id = p.branch_id
                       LEFT JOIN customers c ON c.id = p.customer_id
                       LEFT JOIN payment_methods pm ON pm.id = p.payment_method_id
                       WHERE {$whereSql}";
$res = $conn->query($sqlFilteredSummary);
if ($res) {
    $row = $res->fetch_assoc();
    $filteredCollectionsCount = (int)($row['total_count'] ?? 0);
    $filteredCollectionsAmount = (float)($row['total_amount'] ?? 0);
}

/* -------------------------------------------------------
   FETCH COLLECTIONS
------------------------------------------------------- */
$collectionRows = [];

$sql = "SELECT
            p.*,
            br.branch_name,
            br.branch_code,
            c.full_name AS customer_name,
            c.mobile AS customer_mobile,
            pm.method_name,
            bu.full_name AS created_by_name
        FROM payments p
        LEFT JOIN branches br ON br.id = p.branch_id
        LEFT JOIN customers c ON c.id = p.customer_id
        LEFT JOIN payment_methods pm ON pm.id = p.payment_method_id
        LEFT JOIN business_users bu ON bu.id = p.created_by
        WHERE {$whereSql}
        ORDER BY p.payment_date DESC, p.id DESC";

$res = $conn->query($sql);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $collectionRows[] = $row;
    }
}

$pageTitle = 'Collections';
$currentPage = 'collections';
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
                        <h4 class="mb-1">Collections</h4>
                        <p class="text-muted mb-0">Collection report from payments received</p>
                    </div>
                </div>

                <!-- SUMMARY -->
                <div class="row">
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Total Collections</p>
                                <h3 class="mb-0"><?php echo number_format($totalCollectionsCount); ?></h3>
                                <div class="small text-success mt-1"><?php echo money($totalCollectionsAmount); ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Today Collections</p>
                                <h3 class="mb-0 text-primary"><?php echo number_format($todayCollectionsCount); ?></h3>
                                <div class="small text-info mt-1"><?php echo money($todayCollectionsAmount); ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-2">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Sales</p>
                                <h4 class="mb-0 text-success"><?php echo money($salesCollectionAmount); ?></h4>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-2">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Service</p>
                                <h4 class="mb-0 text-warning"><?php echo money($serviceCollectionAmount); ?></h4>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-2">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Other</p>
                                <h4 class="mb-0 text-secondary"><?php echo money($otherCollectionAmount); ?></h4>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- FILTERS -->
                <div class="card">
                    <div class="card-body">
                        <form method="get" class="row g-3">
                            <div class="col-md-3">
                                <input
                                    type="text"
                                    name="search"
                                    class="form-control"
                                    placeholder="Search customer, ref, transaction..."
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

                            <div class="col-md-1">
                                <select name="payment_for" class="form-select">
                                    <option value="">All</option>
                                    <option value="sales" <?php echo ($paymentForFilter === 'sales') ? 'selected' : ''; ?>>Sales</option>
                                    <option value="service" <?php echo ($paymentForFilter === 'service') ? 'selected' : ''; ?>>Service</option>
                                    <option value="other" <?php echo ($paymentForFilter === 'other') ? 'selected' : ''; ?>>Other</option>
                                </select>
                            </div>

                            <div class="col-md-2">
                                <select name="payment_method_id" class="form-select">
                                    <option value="0">All Methods</option>
                                    <?php foreach ($paymentMethods as $m): ?>
                                        <option value="<?php echo (int)$m['id']; ?>" <?php echo ($methodFilter === (int)$m['id']) ? 'selected' : ''; ?>>
                                            <?php echo h($m['method_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
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
                                <a href="collections.php" class="btn btn-light">Reset</a>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- FILTER RESULT SUMMARY -->
                <div class="row">
                    <div class="col-md-6">
                        <div class="alert alert-info">
                            Filtered Collections:
                            <strong><?php echo number_format($filteredCollectionsCount); ?></strong>
                            |
                            Amount:
                            <strong><?php echo money($filteredCollectionsAmount); ?></strong>
                        </div>
                    </div>
                </div>

                <!-- LIST -->
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4">Collection List</h4>

                        <div class="table-responsive">
                            <table class="table table-bordered table-striped align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Date</th>
                                        <th>Branch</th>
                                        <th>Customer</th>
                                        <th>Collection For</th>
                                        <th>Method</th>
                                        <th>Amount</th>
                                        <th>Reference</th>
                                        <th>Notes</th>
                                        <th>Created By</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($collectionRows)): ?>
                                        <?php $i = 1; foreach ($collectionRows as $row): ?>
                                            <tr>
                                                <td><?php echo $i++; ?></td>

                                                <td>
                                                    <?php echo !empty($row['payment_date']) ? h(date('d M Y h:i A', strtotime($row['payment_date']))) : '-'; ?>
                                                </td>

                                                <td>
                                                    <div><?php echo h($row['branch_name'] ?: '-'); ?></div>
                                                    <div class="small text-muted"><?php echo h($row['branch_code'] ?: '-'); ?></div>
                                                </td>

                                                <td>
                                                    <div><?php echo h($row['customer_name'] ?: '-'); ?></div>
                                                    <div class="small text-muted"><?php echo h($row['customer_mobile'] ?: '-'); ?></div>
                                                </td>

                                                <td>
                                                    <?php
                                                    $badge = 'secondary';
                                                    if (($row['payment_for'] ?? '') === 'sales') $badge = 'success';
                                                    elseif (($row['payment_for'] ?? '') === 'service') $badge = 'warning';
                                                    elseif (($row['payment_for'] ?? '') === 'other') $badge = 'info';
                                                    ?>
                                                    <span class="badge bg-<?php echo $badge; ?>">
                                                        <?php echo h(ucfirst($row['payment_for'] ?: 'other')); ?>
                                                    </span>
                                                    <div class="small text-muted">Ref ID: <?php echo h($row['ref_id'] ?: 0); ?></div>
                                                </td>

                                                <td><?php echo h($row['method_name'] ?: '-'); ?></td>

                                                <td><strong class="text-success"><?php echo money($row['amount']); ?></strong></td>

                                                <td class="small">
                                                    <div><strong>Ref:</strong> <?php echo h($row['reference_no'] ?: '-'); ?></div>
                                                    <div><strong>Txn:</strong> <?php echo h($row['transaction_no'] ?: '-'); ?></div>
                                                </td>

                                                <td><?php echo h($row['notes'] ?: '-'); ?></td>

                                                <td><?php echo h($row['created_by_name'] ?: '-'); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="10" class="text-center text-muted py-4">No collections found.</td>
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