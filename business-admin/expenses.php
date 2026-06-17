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
$requiredTables = ['expenses', 'branches'];
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
     ORDER BY branch_name ASC"
);

$paymentMethods = [];
if (tableExists($conn, 'payment_methods')) {
    $paymentMethods = fetchAllAssoc(
        $conn,
        "SELECT id, method_name
         FROM payment_methods
         WHERE business_id = {$businessId}
         ORDER BY method_name ASC"
    );
}

/* -------------------------------------------------------
   ACTIONS
------------------------------------------------------- */
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');

    if ($action === 'add' || $action === 'edit') {
        $id                = (int)($_POST['id'] ?? 0);
        $branch_id         = (int)($_POST['branch_id'] ?? 0);
        $expense_date      = trim($_POST['expense_date'] ?? date('Y-m-d'));
        $expense_type      = trim($_POST['expense_type'] ?? '');
        $description       = trim($_POST['description'] ?? '');
        $amount            = (float)($_POST['amount'] ?? 0);
        $payment_method_id = (int)($_POST['payment_method_id'] ?? 0);
        $paid_to           = trim($_POST['paid_to'] ?? '');
        $bill_no           = trim($_POST['bill_no'] ?? '');

        if ($branch_id <= 0) {
            $error = 'Please select branch.';
        } elseif ($expense_date === '') {
            $error = 'Expense date is required.';
        } elseif ($expense_type === '') {
            $error = 'Expense type is required.';
        } elseif ($amount <= 0) {
            $error = 'Amount must be greater than 0.';
        } else {
            $stmt = $conn->prepare("SELECT id FROM branches WHERE id = ? AND business_id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('ii', $branch_id, $businessId);
                $stmt->execute();
                $branchCheck = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$branchCheck) {
                    $error = 'Invalid branch selected.';
                }
            }

            if ($error === '' && $payment_method_id > 0 && tableExists($conn, 'payment_methods')) {
                $stmt = $conn->prepare("SELECT id FROM payment_methods WHERE id = ? AND business_id = ? LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param('ii', $payment_method_id, $businessId);
                    $stmt->execute();
                    $methodCheck = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    if (!$methodCheck) {
                        $error = 'Invalid payment method selected.';
                    }
                }
            }
        }

        if ($error === '' && $action === 'add') {
            $stmt = $conn->prepare("INSERT INTO expenses (
                                        business_id,
                                        branch_id,
                                        expense_date,
                                        expense_type,
                                        description,
                                        amount,
                                        payment_method_id,
                                        paid_to,
                                        bill_no,
                                        created_by,
                                        created_at
                                    ) VALUES (
                                        ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()
                                    )");
            if ($stmt) {
                $paymentMethodIdOrNull = $payment_method_id > 0 ? $payment_method_id : null;
                $stmt->bind_param(
                    'iisssdissi',
                    $businessId,
                    $branch_id,
                    $expense_date,
                    $expense_type,
                    $description,
                    $amount,
                    $paymentMethodIdOrNull,
                    $paid_to,
                    $bill_no,
                    $businessUserId
                );

                if ($stmt->execute()) {
                    $success = 'Expense added successfully.';
                } else {
                    $error = 'Failed to add expense.';
                }
                $stmt->close();
            } else {
                $error = 'Unable to prepare insert query.';
            }
        }

        if ($error === '' && $action === 'edit') {
            if ($id <= 0) {
                $error = 'Invalid expense id.';
            } else {
                $stmt = $conn->prepare("UPDATE expenses SET
                                            branch_id = ?,
                                            expense_date = ?,
                                            expense_type = ?,
                                            description = ?,
                                            amount = ?,
                                            payment_method_id = ?,
                                            paid_to = ?,
                                            bill_no = ?
                                        WHERE id = ? AND business_id = ?
                                        LIMIT 1");
                if ($stmt) {
                    $paymentMethodIdOrNull = $payment_method_id > 0 ? $payment_method_id : null;
                    $stmt->bind_param(
                        'isssdissii',
                        $branch_id,
                        $expense_date,
                        $expense_type,
                        $description,
                        $amount,
                        $paymentMethodIdOrNull,
                        $paid_to,
                        $bill_no,
                        $id,
                        $businessId
                    );

                    if ($stmt->execute()) {
                        $success = 'Expense updated successfully.';
                    } else {
                        $error = 'Failed to update expense.';
                    }
                    $stmt->close();
                } else {
                    $error = 'Unable to prepare update query.';
                }
            }
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);

        if ($id <= 0) {
            $error = 'Invalid expense id.';
        } else {
            $stmt = $conn->prepare("DELETE FROM expenses WHERE id = ? AND business_id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('ii', $id, $businessId);
                if ($stmt->execute()) {
                    $success = 'Expense deleted successfully.';
                } else {
                    $error = 'Failed to delete expense.';
                }
                $stmt->close();
            } else {
                $error = 'Unable to prepare delete query.';
            }
        }
    }
}

/* -------------------------------------------------------
   FILTERS
------------------------------------------------------- */
$search = trim($_GET['search'] ?? '');
$branchFilter = (int)($_GET['branch_id'] ?? 0);
$typeFilter = trim($_GET['expense_type'] ?? '');
$methodFilter = (int)($_GET['payment_method_id'] ?? 0);
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo   = trim($_GET['date_to'] ?? '');

$where = ["e.business_id = {$businessId}"];

if ($branchFilter > 0) {
    $where[] = "e.branch_id = {$branchFilter}";
}

if ($typeFilter !== '') {
    $safe = $conn->real_escape_string($typeFilter);
    $where[] = "e.expense_type = '{$safe}'";
}

if ($methodFilter > 0) {
    $where[] = "e.payment_method_id = {$methodFilter}";
}

if ($dateFrom !== '') {
    $safe = $conn->real_escape_string($dateFrom);
    $where[] = "DATE(e.expense_date) >= '{$safe}'";
}

if ($dateTo !== '') {
    $safe = $conn->real_escape_string($dateTo);
    $where[] = "DATE(e.expense_date) <= '{$safe}'";
}

if ($search !== '') {
    $safe = $conn->real_escape_string($search);
    $where[] = "(
        e.expense_type LIKE '%{$safe}%'
        OR e.description LIKE '%{$safe}%'
        OR e.paid_to LIKE '%{$safe}%'
        OR e.bill_no LIKE '%{$safe}%'
        OR pm.method_name LIKE '%{$safe}%'
        OR br.branch_name LIKE '%{$safe}%'
    )";
}

$whereSql = implode(' AND ', $where);

/* -------------------------------------------------------
   SUMMARY
------------------------------------------------------- */
$totalExpenses = getCount($conn, 'expenses', "business_id = {$businessId}");
$totalExpenseAmount = getSum($conn, 'expenses', 'amount', "business_id = {$businessId}");
$todayDate = date('Y-m-d');
$todayExpenses = getCount($conn, 'expenses', "business_id = {$businessId} AND DATE(expense_date) = '{$todayDate}'");
$todayExpenseAmount = getSum($conn, 'expenses', 'amount', "business_id = {$businessId} AND DATE(expense_date) = '{$todayDate}'");

/* -------------------------------------------------------
   DISTINCT EXPENSE TYPES
------------------------------------------------------- */
$expenseTypes = [];
$res = $conn->query("SELECT DISTINCT expense_type
                     FROM expenses
                     WHERE business_id = {$businessId}
                       AND expense_type IS NOT NULL
                       AND expense_type != ''
                     ORDER BY expense_type ASC");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $expenseTypes[] = $row['expense_type'];
    }
}

/* -------------------------------------------------------
   FETCH EXPENSES
------------------------------------------------------- */
$expenseRows = [];

$sql = "SELECT
            e.*,
            br.branch_name,
            br.branch_code,
            pm.method_name,
            bu.full_name AS created_by_name
        FROM expenses e
        LEFT JOIN branches br ON br.id = e.branch_id
        LEFT JOIN payment_methods pm ON pm.id = e.payment_method_id
        LEFT JOIN business_users bu ON bu.id = e.created_by
        WHERE {$whereSql}
        ORDER BY e.id DESC";

$res = $conn->query($sql);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $expenseRows[] = $row;
    }
}

$pageTitle = 'Expenses';
$currentPage = 'expenses';
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
                        <h4 class="mb-1">Expenses</h4>
                        <p class="text-muted mb-0">Manage expenses for your business</p>
                    </div>
                </div>

                <?php if ($success !== ''): ?>
                    <div class="alert alert-success"><?php echo h($success); ?></div>
                <?php endif; ?>

                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger"><?php echo h($error); ?></div>
                <?php endif; ?>

                <div class="row">
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Total Expenses</p>
                                <h3 class="mb-0"><?php echo number_format($totalExpenses); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Total Amount</p>
                                <h3 class="mb-0 text-danger"><?php echo money($totalExpenseAmount); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Today Expenses</p>
                                <h3 class="mb-0 text-primary"><?php echo number_format($todayExpenses); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Today Amount</p>
                                <h3 class="mb-0 text-warning"><?php echo money($todayExpenseAmount); ?></h3>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ADD FORM -->
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4">Add Expense</h4>

                        <form method="post">
                            <input type="hidden" name="action" value="add">

                            <div class="row">
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Branch</label>
                                    <select name="branch_id" class="form-select" required>
                                        <option value="">Select Branch</option>
                                        <?php foreach ($branches as $b): ?>
                                            <option value="<?php echo (int)$b['id']; ?>" <?php echo ($currentBranchId === (int)$b['id']) ? 'selected' : ''; ?>>
                                                <?php echo h($b['branch_name'] . ' (' . $b['branch_code'] . ')'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Expense Date</label>
                                    <input type="date" name="expense_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Expense Type</label>
                                    <input type="text" name="expense_type" class="form-control" placeholder="Electricity, Rent, Salary..." required>
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Amount</label>
                                    <input type="number" step="0.01" min="0.01" name="amount" class="form-control" required>
                                </div>

                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Payment Method</label>
                                    <select name="payment_method_id" class="form-select">
                                        <option value="0">Select Method</option>
                                        <?php foreach ($paymentMethods as $m): ?>
                                            <option value="<?php echo (int)$m['id']; ?>">
                                                <?php echo h($m['method_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Paid To</label>
                                    <input type="text" name="paid_to" class="form-control" placeholder="Vendor or person name">
                                </div>

                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Bill No</label>
                                    <input type="text" name="bill_no" class="form-control" placeholder="Bill or invoice number">
                                </div>

                                <div class="col-md-12 mb-3">
                                    <label class="form-label">Description</label>
                                    <textarea name="description" class="form-control" rows="2"></textarea>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-primary">Save Expense</button>
                        </form>
                    </div>
                </div>

                <!-- FILTERS -->
                <div class="card">
                    <div class="card-body">
                        <form method="get" class="row g-3">
                            <div class="col-md-3">
                                <input type="text" name="search" class="form-control" placeholder="Search type, paid to, branch..." value="<?php echo h($search); ?>">
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
                                <select name="expense_type" class="form-select">
                                    <option value="">All Types</option>
                                    <?php foreach ($expenseTypes as $type): ?>
                                        <option value="<?php echo h($type); ?>" <?php echo ($typeFilter === $type) ? 'selected' : ''; ?>>
                                            <?php echo h($type); ?>
                                        </option>
                                    <?php endforeach; ?>
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

                            <div class="col-md-1">
                                <button type="submit" class="btn btn-secondary w-100">Go</button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- LIST -->
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4">Expense List</h4>

                        <div class="table-responsive">
                            <table class="table table-bordered table-striped align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Date</th>
                                        <th>Branch</th>
                                        <th>Type</th>
                                        <th>Description</th>
                                        <th>Amount</th>
                                        <th>Method</th>
                                        <th>Paid To / Bill</th>
                                        <th style="width: 540px;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($expenseRows)): ?>
                                        <?php $i = 1; foreach ($expenseRows as $row): ?>
                                            <tr>
                                                <td><?php echo $i++; ?></td>

                                                <td>
                                                    <?php echo !empty($row['expense_date']) ? h(date('d M Y', strtotime($row['expense_date']))) : '-'; ?>
                                                </td>

                                                <td>
                                                    <div><?php echo h($row['branch_name'] ?: '-'); ?></div>
                                                    <div class="small text-muted"><?php echo h($row['branch_code'] ?: '-'); ?></div>
                                                </td>

                                                <td>
                                                    <strong><?php echo h($row['expense_type']); ?></strong>
                                                </td>

                                                <td>
                                                    <?php echo h($row['description'] ?: '-'); ?>
                                                    <div class="small text-muted mt-1">
                                                        By: <?php echo h($row['created_by_name'] ?: '-'); ?>
                                                    </div>
                                                </td>

                                                <td><strong class="text-danger"><?php echo money($row['amount']); ?></strong></td>

                                                <td><?php echo h($row['method_name'] ?: '-'); ?></td>

                                                <td class="small">
                                                    <div><strong>Paid To:</strong> <?php echo h($row['paid_to'] ?: '-'); ?></div>
                                                    <div><strong>Bill No:</strong> <?php echo h($row['bill_no'] ?: '-'); ?></div>
                                                </td>

                                                <td>
                                                    <form method="post" class="row g-2">
                                                        <input type="hidden" name="action" value="edit">
                                                        <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">

                                                        <div class="col-md-3">
                                                            <select name="branch_id" class="form-select form-select-sm">
                                                                <?php foreach ($branches as $b): ?>
                                                                    <option value="<?php echo (int)$b['id']; ?>" <?php echo ((int)$row['branch_id'] === (int)$b['id']) ? 'selected' : ''; ?>>
                                                                        <?php echo h($b['branch_name']); ?>
                                                                    </option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>

                                                        <div class="col-md-2">
                                                            <input type="date" name="expense_date" class="form-control form-control-sm" value="<?php echo h($row['expense_date']); ?>">
                                                        </div>

                                                        <div class="col-md-2">
                                                            <input type="text" name="expense_type" class="form-control form-control-sm" value="<?php echo h($row['expense_type']); ?>" placeholder="Type">
                                                        </div>

                                                        <div class="col-md-2">
                                                            <input type="number" step="0.01" min="0.01" name="amount" class="form-control form-control-sm" value="<?php echo h($row['amount']); ?>" placeholder="Amount">
                                                        </div>

                                                        <div class="col-md-3">
                                                            <select name="payment_method_id" class="form-select form-select-sm">
                                                                <option value="0">No Method</option>
                                                                <?php foreach ($paymentMethods as $m): ?>
                                                                    <option value="<?php echo (int)$m['id']; ?>" <?php echo ((int)$row['payment_method_id'] === (int)$m['id']) ? 'selected' : ''; ?>>
                                                                        <?php echo h($m['method_name']); ?>
                                                                    </option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>

                                                        <div class="col-md-3">
                                                            <input type="text" name="paid_to" class="form-control form-control-sm" value="<?php echo h($row['paid_to']); ?>" placeholder="Paid To">
                                                        </div>

                                                        <div class="col-md-3">
                                                            <input type="text" name="bill_no" class="form-control form-control-sm" value="<?php echo h($row['bill_no']); ?>" placeholder="Bill No">
                                                        </div>

                                                        <div class="col-md-6">
                                                            <input type="text" name="description" class="form-control form-control-sm" value="<?php echo h($row['description']); ?>" placeholder="Description">
                                                        </div>

                                                        <div class="col-md-12 d-flex gap-2">
                                                            <button type="submit" class="btn btn-sm btn-primary">Update</button>
                                                    </form>

                                                    <form method="post" onsubmit="return confirm('Delete this expense?');">
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
                                            <td colspan="9" class="text-center text-muted py-4">No expenses found.</td>
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