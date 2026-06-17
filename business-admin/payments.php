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
$requiredTables = ['payments', 'branches', 'payment_methods'];
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
   ACTIONS
------------------------------------------------------- */
$success = '';
$error = '';

$allowedPaymentFor = ['sales', 'service', 'other'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');

    if ($action === 'add' || $action === 'edit') {
        $id                = (int)($_POST['id'] ?? 0);
        $branch_id         = (int)($_POST['branch_id'] ?? 0);
        $payment_date      = trim($_POST['payment_date'] ?? date('Y-m-d\TH:i'));
        $customer_id       = (int)($_POST['customer_id'] ?? 0);
        $payment_for       = trim($_POST['payment_for'] ?? 'other');
        $ref_id            = (int)($_POST['ref_id'] ?? 0);
        $payment_method_id = (int)($_POST['payment_method_id'] ?? 0);
        $amount            = (float)($_POST['amount'] ?? 0);
        $reference_no      = trim($_POST['reference_no'] ?? '');
        $transaction_no    = trim($_POST['transaction_no'] ?? '');
        $notes             = trim($_POST['notes'] ?? '');

        if ($branch_id <= 0) {
            $error = 'Please select branch.';
        } elseif ($payment_date === '') {
            $error = 'Payment date is required.';
        } elseif (!in_array($payment_for, $allowedPaymentFor, true)) {
            $error = 'Invalid payment for value.';
        } elseif ($payment_method_id <= 0) {
            $error = 'Please select payment method.';
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

            if ($error === '') {
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

            if ($error === '' && $customer_id > 0 && tableExists($conn, 'customers')) {
                $stmt = $conn->prepare("SELECT id FROM customers WHERE id = ? AND business_id = ? LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param('ii', $customer_id, $businessId);
                    $stmt->execute();
                    $customerCheck = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    if (!$customerCheck) {
                        $error = 'Invalid customer selected.';
                    }
                }
            }
        }

        $paymentDateSql = date('Y-m-d H:i:s', strtotime($payment_date));

        if ($error === '' && $action === 'add') {
            $stmt = $conn->prepare("INSERT INTO payments (
                                        business_id,
                                        branch_id,
                                        payment_date,
                                        customer_id,
                                        payment_for,
                                        ref_id,
                                        payment_method_id,
                                        amount,
                                        reference_no,
                                        transaction_no,
                                        notes,
                                        created_by,
                                        created_at
                                    ) VALUES (
                                        ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()
                                    )");
            if ($stmt) {
                $customerIdOrNull = $customer_id > 0 ? $customer_id : null;
                $stmt->bind_param(
                    'iisiisidsssi',
                    $businessId,
                    $branch_id,
                    $paymentDateSql,
                    $customerIdOrNull,
                    $payment_for,
                    $ref_id,
                    $payment_method_id,
                    $amount,
                    $reference_no,
                    $transaction_no,
                    $notes,
                    $businessUserId
                );

                if ($stmt->execute()) {
                    $success = 'Payment added successfully.';
                } else {
                    $error = 'Failed to add payment.';
                }
                $stmt->close();
            } else {
                $error = 'Unable to prepare insert query.';
            }
        }

        if ($error === '' && $action === 'edit') {
            if ($id <= 0) {
                $error = 'Invalid payment id.';
            } else {
                $stmt = $conn->prepare("UPDATE payments SET
                                            branch_id = ?,
                                            payment_date = ?,
                                            customer_id = ?,
                                            payment_for = ?,
                                            ref_id = ?,
                                            payment_method_id = ?,
                                            amount = ?,
                                            reference_no = ?,
                                            transaction_no = ?,
                                            notes = ?
                                        WHERE id = ? AND business_id = ?
                                        LIMIT 1");
                if ($stmt) {
                    $customerIdOrNull = $customer_id > 0 ? $customer_id : null;
                    $stmt->bind_param(
                        'isisiidsssii',
                        $branch_id,
                        $paymentDateSql,
                        $customerIdOrNull,
                        $payment_for,
                        $ref_id,
                        $payment_method_id,
                        $amount,
                        $reference_no,
                        $transaction_no,
                        $notes,
                        $id,
                        $businessId
                    );

                    if ($stmt->execute()) {
                        $success = 'Payment updated successfully.';
                    } else {
                        $error = 'Failed to update payment.';
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
            $error = 'Invalid payment id.';
        } else {
            $stmt = $conn->prepare("DELETE FROM payments WHERE id = ? AND business_id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('ii', $id, $businessId);
                if ($stmt->execute()) {
                    $success = 'Payment deleted successfully.';
                } else {
                    $error = 'Failed to delete payment.';
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
$totalPayments = getCount($conn, 'payments', "business_id = {$businessId}");
$totalPaymentAmount = getSum($conn, 'payments', 'amount', "business_id = {$businessId}");
$todayDate = date('Y-m-d');
$todayPayments = getCount($conn, 'payments', "business_id = {$businessId} AND DATE(payment_date) = '{$todayDate}'");
$todayPaymentAmount = getSum($conn, 'payments', 'amount', "business_id = {$businessId} AND DATE(payment_date) = '{$todayDate}'");

/* -------------------------------------------------------
   FETCH PAYMENTS
------------------------------------------------------- */
$paymentRows = [];

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
        ORDER BY p.id DESC";

$res = $conn->query($sql);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $paymentRows[] = $row;
    }
}

$pageTitle = 'Payments';
$currentPage = 'payments';
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
                        <h4 class="mb-1">Payments</h4>
                        <p class="text-muted mb-0">Manage payments for your business</p>
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
                                <p class="text-muted mb-1">Total Payments</p>
                                <h3 class="mb-0"><?php echo number_format($totalPayments); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Total Amount</p>
                                <h3 class="mb-0 text-success"><?php echo money($totalPaymentAmount); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Today Payments</p>
                                <h3 class="mb-0 text-primary"><?php echo number_format($todayPayments); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Today Amount</p>
                                <h3 class="mb-0 text-info"><?php echo money($todayPaymentAmount); ?></h3>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ADD FORM -->
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4">Add Payment</h4>

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
                                    <label class="form-label">Payment Date</label>
                                    <input type="datetime-local" name="payment_date" class="form-control" value="<?php echo date('Y-m-d\TH:i'); ?>" required>
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Customer</label>
                                    <select name="customer_id" class="form-select">
                                        <option value="0">Select Customer</option>
                                        <?php foreach ($customers as $c): ?>
                                            <option value="<?php echo (int)$c['id']; ?>">
                                                <?php echo h($c['full_name'] . (!empty($c['mobile']) ? ' (' . $c['mobile'] . ')' : '')); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Payment For</label>
                                    <select name="payment_for" class="form-select" required>
                                        <option value="sales">Sales</option>
                                        <option value="service">Service</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Ref ID</label>
                                    <input type="number" name="ref_id" class="form-control" value="0" min="0">
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Payment Method</label>
                                    <select name="payment_method_id" class="form-select" required>
                                        <option value="">Select Method</option>
                                        <?php foreach ($paymentMethods as $m): ?>
                                            <option value="<?php echo (int)$m['id']; ?>">
                                                <?php echo h($m['method_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Amount</label>
                                    <input type="number" step="0.01" min="0.01" name="amount" class="form-control" required>
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Reference No</label>
                                    <input type="text" name="reference_no" class="form-control">
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Transaction No</label>
                                    <input type="text" name="transaction_no" class="form-control">
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Notes</label>
                                    <textarea name="notes" class="form-control" rows="2"></textarea>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-primary">Save Payment</button>
                        </form>
                    </div>
                </div>

                <!-- FILTERS -->
                <div class="card">
                    <div class="card-body">
                        <form method="get" class="row g-3">
                            <div class="col-md-3">
                                <input type="text" name="search" class="form-control" placeholder="Search customer, method, ref..." value="<?php echo h($search); ?>">
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
                        </form>

                        <div class="row mt-3">
                            <div class="col-md-1 ms-auto">
                                <button type="submit" formmethod="get" class="btn btn-secondary w-100" onclick="this.form=document.forms[1];">Go</button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- LIST -->
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4">Payment List</h4>

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
                                        <th>Notes</th>
                                        <th style="width: 540px;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($paymentRows)): ?>
                                        <?php $i = 1; foreach ($paymentRows as $row): ?>
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
                                                    <span class="badge bg-info">
                                                        <?php echo h(ucfirst($row['payment_for'] ?: 'other')); ?>
                                                    </span>
                                                    <div class="small text-muted">Ref ID: <?php echo h($row['ref_id'] ?: 0); ?></div>
                                                </td>

                                                <td><?php echo h($row['method_name'] ?: '-'); ?></td>

                                                <td><strong><?php echo money($row['amount']); ?></strong></td>

                                                <td class="small">
                                                    <div><strong>Ref:</strong> <?php echo h($row['reference_no'] ?: '-'); ?></div>
                                                    <div><strong>Txn:</strong> <?php echo h($row['transaction_no'] ?: '-'); ?></div>
                                                </td>

                                                <td>
                                                    <?php echo h($row['notes'] ?: '-'); ?>
                                                    <div class="small text-muted mt-1">
                                                        By: <?php echo h($row['created_by_name'] ?: '-'); ?>
                                                    </div>
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

                                                        <div class="col-md-3">
                                                            <input type="datetime-local" name="payment_date" class="form-control form-control-sm" value="<?php echo !empty($row['payment_date']) ? h(date('Y-m-d\TH:i', strtotime($row['payment_date']))) : ''; ?>">
                                                        </div>

                                                        <div class="col-md-3">
                                                            <select name="customer_id" class="form-select form-select-sm">
                                                                <option value="0">No Customer</option>
                                                                <?php foreach ($customers as $c): ?>
                                                                    <option value="<?php echo (int)$c['id']; ?>" <?php echo ((int)$row['customer_id'] === (int)$c['id']) ? 'selected' : ''; ?>>
                                                                        <?php echo h($c['full_name']); ?>
                                                                    </option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>

                                                        <div class="col-md-3">
                                                            <select name="payment_for" class="form-select form-select-sm">
                                                                <option value="sales" <?php echo ($row['payment_for'] === 'sales') ? 'selected' : ''; ?>>Sales</option>
                                                                <option value="service" <?php echo ($row['payment_for'] === 'service') ? 'selected' : ''; ?>>Service</option>
                                                                <option value="other" <?php echo ($row['payment_for'] === 'other') ? 'selected' : ''; ?>>Other</option>
                                                            </select>
                                                        </div>

                                                        <div class="col-md-2">
                                                            <input type="number" name="ref_id" class="form-control form-control-sm" value="<?php echo h($row['ref_id']); ?>" placeholder="Ref ID">
                                                        </div>

                                                        <div class="col-md-3">
                                                            <select name="payment_method_id" class="form-select form-select-sm">
                                                                <?php foreach ($paymentMethods as $m): ?>
                                                                    <option value="<?php echo (int)$m['id']; ?>" <?php echo ((int)$row['payment_method_id'] === (int)$m['id']) ? 'selected' : ''; ?>>
                                                                        <?php echo h($m['method_name']); ?>
                                                                    </option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>

                                                        <div class="col-md-2">
                                                            <input type="number" step="0.01" min="0.01" name="amount" class="form-control form-control-sm" value="<?php echo h($row['amount']); ?>" placeholder="Amount">
                                                        </div>

                                                        <div class="col-md-2">
                                                            <input type="text" name="reference_no" class="form-control form-control-sm" value="<?php echo h($row['reference_no']); ?>" placeholder="Ref No">
                                                        </div>

                                                        <div class="col-md-3">
                                                            <input type="text" name="transaction_no" class="form-control form-control-sm" value="<?php echo h($row['transaction_no']); ?>" placeholder="Txn No">
                                                        </div>

                                                        <div class="col-md-12">
                                                            <input type="text" name="notes" class="form-control form-control-sm" value="<?php echo h($row['notes']); ?>" placeholder="Notes">
                                                        </div>

                                                        <div class="col-md-12 d-flex gap-2">
                                                            <button type="submit" class="btn btn-sm btn-primary">Update</button>
                                                    </form>

                                                    <form method="post" onsubmit="return confirm('Delete this payment?');">
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
                                            <td colspan="10" class="text-center text-muted py-4">No payments found.</td>
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