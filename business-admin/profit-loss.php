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
if (!tableExists($conn, 'expenses')) {
    die('expenses table not found.');
}
if (!tableExists($conn, 'branches')) {
    die('branches table not found.');
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

/* -------------------------------------------------------
   FILTERS
------------------------------------------------------- */
$branchFilter = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : 0;
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo   = trim($_GET['date_to'] ?? '');

/* 
   Important:
   Use alias-based WHERE parts for joined queries
*/
$paymentWhere = ["p.business_id = {$businessId}"];
$expenseWhere = ["e.business_id = {$businessId}"];

if ($branchFilter > 0) {
    $paymentWhere[] = "p.branch_id = {$branchFilter}";
    $expenseWhere[] = "e.branch_id = {$branchFilter}";
}

if ($dateFrom !== '') {
    $safeFrom = $conn->real_escape_string($dateFrom);
    $paymentWhere[] = "DATE(p.payment_date) >= '{$safeFrom}'";
    $expenseWhere[] = "DATE(e.expense_date) >= '{$safeFrom}'";
}

if ($dateTo !== '') {
    $safeTo = $conn->real_escape_string($dateTo);
    $paymentWhere[] = "DATE(p.payment_date) <= '{$safeTo}'";
    $expenseWhere[] = "DATE(e.expense_date) <= '{$safeTo}'";
}

$paymentWhereSql = implode(' AND ', $paymentWhere);
$expenseWhereSql = implode(' AND ', $expenseWhere);

/* 
   Separate WHERE strings for helper functions without alias
*/
$paymentBaseWhere = ["business_id = {$businessId}"];
$expenseBaseWhere = ["business_id = {$businessId}"];

if ($branchFilter > 0) {
    $paymentBaseWhere[] = "branch_id = {$branchFilter}";
    $expenseBaseWhere[] = "branch_id = {$branchFilter}";
}

if ($dateFrom !== '') {
    $safeFrom = $conn->real_escape_string($dateFrom);
    $paymentBaseWhere[] = "DATE(payment_date) >= '{$safeFrom}'";
    $expenseBaseWhere[] = "DATE(expense_date) >= '{$safeFrom}'";
}

if ($dateTo !== '') {
    $safeTo = $conn->real_escape_string($dateTo);
    $paymentBaseWhere[] = "DATE(payment_date) <= '{$safeTo}'";
    $expenseBaseWhere[] = "DATE(expense_date) <= '{$safeTo}'";
}

$paymentBaseWhereSql = implode(' AND ', $paymentBaseWhere);
$expenseBaseWhereSql = implode(' AND ', $expenseBaseWhere);

/* -------------------------------------------------------
   PROFIT / LOSS SUMMARY
------------------------------------------------------- */
$totalSalesCollection = getSum($conn, 'payments', 'amount', $paymentBaseWhereSql . " AND payment_for = 'sales'");
$totalServiceCollection = getSum($conn, 'payments', 'amount', $paymentBaseWhereSql . " AND payment_for = 'service'");
$totalOtherCollection = getSum($conn, 'payments', 'amount', $paymentBaseWhereSql . " AND payment_for = 'other'");
$totalCollections = getSum($conn, 'payments', 'amount', $paymentBaseWhereSql);
$totalExpenses = getSum($conn, 'expenses', 'amount', $expenseBaseWhereSql);

$netProfitLoss = $totalCollections - $totalExpenses;

$totalPaymentCount = getCount($conn, 'payments', $paymentBaseWhereSql);
$totalExpenseCount = getCount($conn, 'expenses', $expenseBaseWhereSql);

/* -------------------------------------------------------
   BRANCH-WISE SUMMARY
------------------------------------------------------- */
$branchSummary = [];

$sqlBranchSummary = "SELECT
                        b.id,
                        b.branch_name,
                        b.branch_code,

                        (
                            SELECT COALESCE(SUM(p.amount),0)
                            FROM payments p
                            WHERE p.business_id = {$businessId}
                              AND p.branch_id = b.id" .
                              ($dateFrom !== '' ? " AND DATE(p.payment_date) >= '" . $conn->real_escape_string($dateFrom) . "'" : "") .
                              ($dateTo !== '' ? " AND DATE(p.payment_date) <= '" . $conn->real_escape_string($dateTo) . "'" : "") .
                              " AND p.payment_for = 'sales'
                        ) AS sales_collection,

                        (
                            SELECT COALESCE(SUM(p.amount),0)
                            FROM payments p
                            WHERE p.business_id = {$businessId}
                              AND p.branch_id = b.id" .
                              ($dateFrom !== '' ? " AND DATE(p.payment_date) >= '" . $conn->real_escape_string($dateFrom) . "'" : "") .
                              ($dateTo !== '' ? " AND DATE(p.payment_date) <= '" . $conn->real_escape_string($dateTo) . "'" : "") .
                              " AND p.payment_for = 'service'
                        ) AS service_collection,

                        (
                            SELECT COALESCE(SUM(p.amount),0)
                            FROM payments p
                            WHERE p.business_id = {$businessId}
                              AND p.branch_id = b.id" .
                              ($dateFrom !== '' ? " AND DATE(p.payment_date) >= '" . $conn->real_escape_string($dateFrom) . "'" : "") .
                              ($dateTo !== '' ? " AND DATE(p.payment_date) <= '" . $conn->real_escape_string($dateTo) . "'" : "") .
                              " AND p.payment_for = 'other'
                        ) AS other_collection,

                        (
                            SELECT COALESCE(SUM(e.amount),0)
                            FROM expenses e
                            WHERE e.business_id = {$businessId}
                              AND e.branch_id = b.id" .
                              ($dateFrom !== '' ? " AND DATE(e.expense_date) >= '" . $conn->real_escape_string($dateFrom) . "'" : "") .
                              ($dateTo !== '' ? " AND DATE(e.expense_date) <= '" . $conn->real_escape_string($dateTo) . "'" : "") .
                        ") AS total_expense
                    FROM branches b
                    WHERE b.business_id = {$businessId}" .
                    ($branchFilter > 0 ? " AND b.id = {$branchFilter}" : "") . "
                    ORDER BY b.branch_name ASC";

$res = $conn->query($sqlBranchSummary);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $row['total_collection'] = (float)$row['sales_collection'] + (float)$row['service_collection'] + (float)$row['other_collection'];
        $row['net_profit_loss'] = (float)$row['total_collection'] - (float)$row['total_expense'];
        $branchSummary[] = $row;
    }
}

/* -------------------------------------------------------
   RECENT PAYMENTS
------------------------------------------------------- */
$recentPayments = [];

$sqlRecentPayments = "SELECT
                        p.payment_date,
                        p.payment_for,
                        p.amount,
                        p.reference_no,
                        p.transaction_no,
                        br.branch_name,
                        c.full_name AS customer_name,
                        pm.method_name
                      FROM payments p
                      LEFT JOIN branches br ON br.id = p.branch_id
                      LEFT JOIN customers c ON c.id = p.customer_id
                      LEFT JOIN payment_methods pm ON pm.id = p.payment_method_id
                      WHERE {$paymentWhereSql}
                      ORDER BY p.payment_date DESC, p.id DESC
                      LIMIT 15";

$res = $conn->query($sqlRecentPayments);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $recentPayments[] = $row;
    }
}

/* -------------------------------------------------------
   RECENT EXPENSES
------------------------------------------------------- */
$recentExpenses = [];

$sqlRecentExpenses = "SELECT
                        e.expense_date,
                        e.expense_type,
                        e.description,
                        e.amount,
                        e.paid_to,
                        e.bill_no,
                        br.branch_name,
                        pm.method_name
                      FROM expenses e
                      LEFT JOIN branches br ON br.id = e.branch_id
                      LEFT JOIN payment_methods pm ON pm.id = e.payment_method_id
                      WHERE {$expenseWhereSql}
                      ORDER BY e.expense_date DESC, e.id DESC
                      LIMIT 15";

$res = $conn->query($sqlRecentExpenses);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $recentExpenses[] = $row;
    }
}

$pageTitle = 'Profit & Loss';
$currentPage = 'profit-loss';
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
                        <h4 class="mb-1">Profit & Loss</h4>
                        <p class="text-muted mb-0">Business collection and expense summary</p>
                    </div>
                </div>

                <div class="card">
                    <div class="card-body">
                        <form method="get" class="row g-3">
                            <div class="col-md-4">
                                <select name="branch_id" class="form-select">
                                    <option value="0">All Branches</option>
                                    <?php foreach ($branches as $b): ?>
                                        <option value="<?php echo (int)$b['id']; ?>" <?php echo ($branchFilter === (int)$b['id']) ? 'selected' : ''; ?>>
                                            <?php echo h($b['branch_name'] . ' (' . $b['branch_code'] . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-3">
                                <input type="date" name="date_from" class="form-control" value="<?php echo h($dateFrom); ?>">
                            </div>

                            <div class="col-md-3">
                                <input type="date" name="date_to" class="form-control" value="<?php echo h($dateTo); ?>">
                            </div>

                            <div class="col-md-1">
                                <button type="submit" class="btn btn-secondary w-100">Go</button>
                            </div>

                            <div class="col-md-1">
                                <a href="profit-loss.php" class="btn btn-light w-100">Reset</a>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-2">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Sales</p>
                                <h5 class="mb-0 text-success"><?php echo money($totalSalesCollection); ?></h5>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-2">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Service</p>
                                <h5 class="mb-0 text-info"><?php echo money($totalServiceCollection); ?></h5>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-2">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Other</p>
                                <h5 class="mb-0 text-primary"><?php echo money($totalOtherCollection); ?></h5>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-2">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Collections</p>
                                <h5 class="mb-0 text-success"><?php echo money($totalCollections); ?></h5>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-2">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Expenses</p>
                                <h5 class="mb-0 text-danger"><?php echo money($totalExpenses); ?></h5>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-2">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Net P/L</p>
                                <h5 class="mb-0 <?php echo $netProfitLoss >= 0 ? 'text-success' : 'text-danger'; ?>">
                                    <?php echo money($netProfitLoss); ?>
                                </h5>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <div class="alert alert-info">
                            Total Payment Entries: <strong><?php echo number_format($totalPaymentCount); ?></strong>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="alert alert-warning">
                            Total Expense Entries: <strong><?php echo number_format($totalExpenseCount); ?></strong>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4">Branch-wise Profit & Loss</h4>

                        <div class="table-responsive">
                            <table class="table table-bordered table-striped align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Branch</th>
                                        <th>Sales</th>
                                        <th>Service</th>
                                        <th>Other</th>
                                        <th>Total Collection</th>
                                        <th>Total Expense</th>
                                        <th>Net P/L</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($branchSummary)): ?>
                                        <?php $i = 1; foreach ($branchSummary as $row): ?>
                                            <tr>
                                                <td><?php echo $i++; ?></td>
                                                <td>
                                                    <div><strong><?php echo h($row['branch_name']); ?></strong></div>
                                                    <div class="small text-muted"><?php echo h($row['branch_code']); ?></div>
                                                </td>
                                                <td><?php echo money($row['sales_collection']); ?></td>
                                                <td><?php echo money($row['service_collection']); ?></td>
                                                <td><?php echo money($row['other_collection']); ?></td>
                                                <td><strong class="text-success"><?php echo money($row['total_collection']); ?></strong></td>
                                                <td><strong class="text-danger"><?php echo money($row['total_expense']); ?></strong></td>
                                                <td>
                                                    <strong class="<?php echo ((float)$row['net_profit_loss'] >= 0) ? 'text-success' : 'text-danger'; ?>">
                                                        <?php echo money($row['net_profit_loss']); ?>
                                                    </strong>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="8" class="text-center text-muted py-4">No branch summary found.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4">Recent Collections</h4>

                        <div class="table-responsive">
                            <table class="table table-bordered table-striped align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Date</th>
                                        <th>Branch</th>
                                        <th>Customer</th>
                                        <th>For</th>
                                        <th>Method</th>
                                        <th>Amount</th>
                                        <th>Reference</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($recentPayments)): ?>
                                        <?php $i = 1; foreach ($recentPayments as $row): ?>
                                            <tr>
                                                <td><?php echo $i++; ?></td>
                                                <td><?php echo !empty($row['payment_date']) ? h(date('d M Y h:i A', strtotime($row['payment_date']))) : '-'; ?></td>
                                                <td><?php echo h($row['branch_name'] ?: '-'); ?></td>
                                                <td><?php echo h($row['customer_name'] ?: '-'); ?></td>
                                                <td>
                                                    <span class="badge bg-info">
                                                        <?php echo h(ucfirst($row['payment_for'] ?: 'other')); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo h($row['method_name'] ?: '-'); ?></td>
                                                <td><strong class="text-success"><?php echo money($row['amount']); ?></strong></td>
                                                <td>
                                                    <div class="small">Ref: <?php echo h($row['reference_no'] ?: '-'); ?></div>
                                                    <div class="small">Txn: <?php echo h($row['transaction_no'] ?: '-'); ?></div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="8" class="text-center text-muted py-4">No payment records found.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4">Recent Expenses</h4>

                        <div class="table-responsive">
                            <table class="table table-bordered table-striped align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Date</th>
                                        <th>Branch</th>
                                        <th>Type</th>
                                        <th>Description</th>
                                        <th>Paid To</th>
                                        <th>Method</th>
                                        <th>Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($recentExpenses)): ?>
                                        <?php $i = 1; foreach ($recentExpenses as $row): ?>
                                            <tr>
                                                <td><?php echo $i++; ?></td>
                                                <td><?php echo !empty($row['expense_date']) ? h(date('d M Y', strtotime($row['expense_date']))) : '-'; ?></td>
                                                <td><?php echo h($row['branch_name'] ?: '-'); ?></td>
                                                <td><strong><?php echo h($row['expense_type']); ?></strong></td>
                                                <td><?php echo h($row['description'] ?: '-'); ?></td>
                                                <td>
                                                    <div><?php echo h($row['paid_to'] ?: '-'); ?></div>
                                                    <div class="small text-muted">Bill: <?php echo h($row['bill_no'] ?: '-'); ?></div>
                                                </td>
                                                <td><?php echo h($row['method_name'] ?: '-'); ?></td>
                                                <td><strong class="text-danger"><?php echo money($row['amount']); ?></strong></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="8" class="text-center text-muted py-4">No expense records found.</td>
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