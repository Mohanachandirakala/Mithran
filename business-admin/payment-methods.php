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
   TABLE CHECK
------------------------------------------------------- */
if (!tableExists($conn, 'payment_methods')) {
    die('payment_methods table not found.');
}

/* -------------------------------------------------------
   ACTIONS
------------------------------------------------------- */
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');

    if ($action === 'add') {
        $method_name = trim($_POST['method_name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $status = isset($_POST['status']) ? (int)$_POST['status'] : 1;

        if ($method_name === '') {
            $error = 'Payment method name is required.';
        } else {
            $stmt = $conn->prepare("SELECT id
                                    FROM payment_methods
                                    WHERE business_id = ? AND method_name = ?
                                    LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('is', $businessId, $method_name);
                $stmt->execute();
                $dup = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($dup) {
                    $error = 'Payment method already exists.';
                }
            }
        }

        if ($error === '') {
            $stmt = $conn->prepare("INSERT INTO payment_methods (
                                        business_id,
                                        method_name,
                                        description,
                                        status,
                                        created_at
                                    ) VALUES (
                                        ?, ?, ?, ?, NOW()
                                    )");
            if ($stmt) {
                $stmt->bind_param('issi', $businessId, $method_name, $description, $status);
                if ($stmt->execute()) {
                    $success = 'Payment method added successfully.';
                } else {
                    $error = 'Failed to add payment method.';
                }
                $stmt->close();
            } else {
                $error = 'Unable to prepare insert query.';
            }
        }
    }

    if ($action === 'edit') {
        $id = (int)($_POST['id'] ?? 0);
        $method_name = trim($_POST['method_name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $status = isset($_POST['status']) ? (int)$_POST['status'] : 1;

        if ($id <= 0) {
            $error = 'Invalid payment method id.';
        } elseif ($method_name === '') {
            $error = 'Payment method name is required.';
        } else {
            $stmt = $conn->prepare("SELECT id
                                    FROM payment_methods
                                    WHERE business_id = ? AND method_name = ? AND id != ?
                                    LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('isi', $businessId, $method_name, $id);
                $stmt->execute();
                $dup = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($dup) {
                    $error = 'Payment method already exists.';
                }
            }
        }

        if ($error === '') {
            $stmt = $conn->prepare("UPDATE payment_methods
                                    SET method_name = ?, description = ?, status = ?
                                    WHERE id = ? AND business_id = ?
                                    LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('ssiii', $method_name, $description, $status, $id, $businessId);
                if ($stmt->execute()) {
                    $success = 'Payment method updated successfully.';
                } else {
                    $error = 'Failed to update payment method.';
                }
                $stmt->close();
            } else {
                $error = 'Unable to prepare update query.';
            }
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);

        if ($id <= 0) {
            $error = 'Invalid payment method id.';
        } else {
            $usedCount = 0;

            if (tableExists($conn, 'payments')) {
                $stmt = $conn->prepare("SELECT COUNT(*) AS total
                                        FROM payments
                                        WHERE business_id = ? AND payment_method_id = ?");
                if ($stmt) {
                    $stmt->bind_param('ii', $businessId, $id);
                    $stmt->execute();
                    $row = $stmt->get_result()->fetch_assoc();
                    $usedCount += (int)($row['total'] ?? 0);
                    $stmt->close();
                }
            }

            if (tableExists($conn, 'expenses')) {
                $stmt = $conn->prepare("SELECT COUNT(*) AS total
                                        FROM expenses
                                        WHERE business_id = ? AND payment_method_id = ?");
                if ($stmt) {
                    $stmt->bind_param('ii', $businessId, $id);
                    $stmt->execute();
                    $row = $stmt->get_result()->fetch_assoc();
                    $usedCount += (int)($row['total'] ?? 0);
                    $stmt->close();
                }
            }

            if ($usedCount > 0) {
                $error = 'Cannot delete this payment method because it is already used in payments or expenses.';
            } else {
                $stmt = $conn->prepare("DELETE FROM payment_methods
                                        WHERE id = ? AND business_id = ?
                                        LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param('ii', $id, $businessId);
                    if ($stmt->execute()) {
                        $success = 'Payment method deleted successfully.';
                    } else {
                        $error = 'Failed to delete payment method.';
                    }
                    $stmt->close();
                } else {
                    $error = 'Unable to prepare delete query.';
                }
            }
        }
    }
}

/* -------------------------------------------------------
   FILTERS
------------------------------------------------------- */
$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');

$where = ["pm.business_id = {$businessId}"];

if ($search !== '') {
    $safe = $conn->real_escape_string($search);
    $where[] = "(pm.method_name LIKE '%{$safe}%' OR pm.description LIKE '%{$safe}%')";
}

if ($statusFilter !== '') {
    if ($statusFilter === 'active') {
        $where[] = "pm.status = 1";
    } elseif ($statusFilter === 'inactive') {
        $where[] = "pm.status = 0";
    }
}

$whereSql = implode(' AND ', $where);

/* -------------------------------------------------------
   SUMMARY
------------------------------------------------------- */
$totalMethods = getCount($conn, 'payment_methods', "business_id = {$businessId}");
$activeMethods = getCount($conn, 'payment_methods', "business_id = {$businessId} AND status = 1");
$inactiveMethods = getCount($conn, 'payment_methods', "business_id = {$businessId} AND status = 0");

/* -------------------------------------------------------
   FETCH METHODS
------------------------------------------------------- */
$methods = [];

$sql = "SELECT
            pm.id,
            pm.method_name,
            pm.description,
            pm.status,
            pm.created_at,
            (
                SELECT COUNT(*)
                FROM payments p
                WHERE p.business_id = pm.business_id
                  AND p.payment_method_id = pm.id
            ) AS payment_count,
            (
                SELECT COUNT(*)
                FROM expenses e
                WHERE e.business_id = pm.business_id
                  AND e.payment_method_id = pm.id
            ) AS expense_count
        FROM payment_methods pm
        WHERE {$whereSql}
        ORDER BY pm.method_name ASC";

$res = $conn->query($sql);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $methods[] = $row;
    }
}

$pageTitle = 'Payment Methods';
$currentPage = 'payment-methods';
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
                        <h4 class="mb-1">Payment Methods</h4>
                        <p class="text-muted mb-0">Manage business payment methods</p>
                    </div>
                </div>

                <?php if ($success !== ''): ?>
                    <div class="alert alert-success"><?php echo h($success); ?></div>
                <?php endif; ?>

                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger"><?php echo h($error); ?></div>
                <?php endif; ?>

                <div class="row">
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Total Methods</p>
                                <h3 class="mb-0"><?php echo number_format($totalMethods); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Active Methods</p>
                                <h3 class="mb-0 text-success"><?php echo number_format($activeMethods); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Inactive Methods</p>
                                <h3 class="mb-0 text-warning"><?php echo number_format($inactiveMethods); ?></h3>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <!-- Add Form -->
                    <div class="col-xl-4">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Add Payment Method</h4>

                                <form method="post">
                                    <input type="hidden" name="action" value="add">

                                    <div class="mb-3">
                                        <label class="form-label">Method Name</label>
                                        <input type="text" name="method_name" class="form-control" placeholder="Cash, UPI, Card..." required>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Description</label>
                                        <textarea name="description" class="form-control" rows="3" placeholder="Optional description"></textarea>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Status</label>
                                        <select name="status" class="form-select">
                                            <option value="1">Active</option>
                                            <option value="0">Inactive</option>
                                        </select>
                                    </div>

                                    <button type="submit" class="btn btn-primary">Save Method</button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- List -->
                    <div class="col-xl-8">
                        <div class="card">
                            <div class="card-body">

                                <form method="get" class="row g-3 mb-4">
                                    <div class="col-md-8">
                                        <input
                                            type="text"
                                            name="search"
                                            class="form-control"
                                            placeholder="Search payment method..."
                                            value="<?php echo h($search); ?>"
                                        >
                                    </div>

                                    <div class="col-md-2">
                                        <select name="status" class="form-select">
                                            <option value="">All</option>
                                            <option value="active" <?php echo ($statusFilter === 'active') ? 'selected' : ''; ?>>Active</option>
                                            <option value="inactive" <?php echo ($statusFilter === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                                        </select>
                                    </div>

                                    <div class="col-md-2">
                                        <button type="submit" class="btn btn-secondary w-100">Search</button>
                                    </div>
                                </form>

                                <div class="table-responsive">
                                    <table class="table table-bordered table-striped align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th>#</th>
                                                <th>Method Name</th>
                                                <th>Description</th>
                                                <th>Payments</th>
                                                <th>Expenses</th>
                                                <th>Status</th>
                                                <th>Created</th>
                                                <th style="width: 330px;">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($methods)): ?>
                                                <?php $i = 1; foreach ($methods as $row): ?>
                                                    <tr>
                                                        <td><?php echo $i++; ?></td>

                                                        <td><strong><?php echo h($row['method_name']); ?></strong></td>

                                                        <td><?php echo h($row['description'] ?: '-'); ?></td>

                                                        <td>
                                                            <span class="badge bg-primary">
                                                                <?php echo number_format((int)$row['payment_count']); ?>
                                                            </span>
                                                        </td>

                                                        <td>
                                                            <span class="badge bg-info">
                                                                <?php echo number_format((int)$row['expense_count']); ?>
                                                            </span>
                                                        </td>

                                                        <td>
                                                            <?php if ((int)$row['status'] === 1): ?>
                                                                <span class="badge bg-success">Active</span>
                                                            <?php else: ?>
                                                                <span class="badge bg-warning">Inactive</span>
                                                            <?php endif; ?>
                                                        </td>

                                                        <td>
                                                            <?php echo !empty($row['created_at']) ? h(date('d M Y', strtotime($row['created_at']))) : '-'; ?>
                                                        </td>

                                                        <td>
                                                            <form method="post" class="d-inline-flex gap-2 flex-wrap align-items-center">
                                                                <input type="hidden" name="action" value="edit">
                                                                <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">

                                                                <input
                                                                    type="text"
                                                                    name="method_name"
                                                                    class="form-control form-control-sm"
                                                                    style="width:120px;"
                                                                    value="<?php echo h($row['method_name']); ?>"
                                                                    required
                                                                >

                                                                <input
                                                                    type="text"
                                                                    name="description"
                                                                    class="form-control form-control-sm"
                                                                    style="width:120px;"
                                                                    value="<?php echo h($row['description']); ?>"
                                                                    placeholder="Description"
                                                                >

                                                                <select name="status" class="form-select form-select-sm" style="width:90px;">
                                                                    <option value="1" <?php echo ((int)$row['status'] === 1) ? 'selected' : ''; ?>>Active</option>
                                                                    <option value="0" <?php echo ((int)$row['status'] === 0) ? 'selected' : ''; ?>>Inactive</option>
                                                                </select>

                                                                <button type="submit" class="btn btn-sm btn-primary">Update</button>
                                                            </form>

                                                            <form method="post" class="d-inline" onsubmit="return confirm('Delete this payment method?');">
                                                                <input type="hidden" name="action" value="delete">
                                                                <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                                                                <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                                                            </form>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="8" class="text-center text-muted py-4">No payment methods found.</td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>

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