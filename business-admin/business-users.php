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
                                bu.username,
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
$requiredTables = ['business_users', 'businesses'];
foreach ($requiredTables as $tbl) {
    if (!tableExists($conn, $tbl)) {
        die($tbl . ' table not found.');
    }
}

$hasBranches = tableExists($conn, 'branches');

/* -------------------------------------------------------
   ACTIONS
------------------------------------------------------- */
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

    if ($id <= 0) {
        $error = 'Invalid user id.';
    } elseif ($id === $businessUserId && $action === 'delete') {
        $error = 'You cannot delete your own account.';
    } elseif ($id === $businessUserId && $action === 'deactivate') {
        $error = 'You cannot deactivate your own account.';
    } else {
        if ($action === 'activate' || $action === 'deactivate') {
            $newStatus = ($action === 'activate') ? 1 : 0;

            $stmt = $conn->prepare("UPDATE business_users
                                    SET status = ?
                                    WHERE id = ? AND business_id = ?
                                    LIMIT 1");
            if (!$stmt) {
                $error = 'Failed to prepare status update query.';
            } else {
                $stmt->bind_param('iii', $newStatus, $id, $businessId);
                if ($stmt->execute()) {
                    $success = ($action === 'activate') ? 'User activated successfully.' : 'User deactivated successfully.';
                } else {
                    $error = 'Failed to update user status.';
                }
                $stmt->close();
            }
        } elseif ($action === 'delete') {
            $stmt = $conn->prepare("DELETE FROM business_users
                                    WHERE id = ? AND business_id = ?
                                    LIMIT 1");
            if (!$stmt) {
                $error = 'Failed to prepare delete query.';
            } else {
                $stmt->bind_param('ii', $id, $businessId);
                if ($stmt->execute()) {
                    if ($stmt->affected_rows > 0) {
                        $success = 'User deleted successfully.';
                    } else {
                        $error = 'User not found.';
                    }
                } else {
                    $error = 'Failed to delete user.';
                }
                $stmt->close();
            }
        }
    }
}

if (isset($_GET['success']) && trim($_GET['success']) !== '') {
    $success = trim($_GET['success']);
}

/* -------------------------------------------------------
   FILTERS
------------------------------------------------------- */
$search = trim($_GET['search'] ?? '');
$roleFilter = trim($_GET['role'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$branchFilter = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : 0;

$allowedRoles = ['owner', 'admin', 'manager', 'sales', 'billing', 'service', 'store'];

$where = ["bu.business_id = {$businessId}"];

if ($search !== '') {
    $safe = $conn->real_escape_string($search);
    $where[] = "(
        bu.full_name LIKE '%{$safe}%'
        OR bu.username LIKE '%{$safe}%'
        OR bu.email LIKE '%{$safe}%'
        OR bu.mobile LIKE '%{$safe}%'
        " . ($hasBranches ? "OR br.branch_name LIKE '%{$safe}%'" : "") . "
    )";
}

if ($roleFilter !== '' && in_array($roleFilter, $allowedRoles, true)) {
    $safe = $conn->real_escape_string($roleFilter);
    $where[] = "bu.role = '{$safe}'";
}

if ($statusFilter === 'active') {
    $where[] = "bu.status = 1";
} elseif ($statusFilter === 'inactive') {
    $where[] = "bu.status = 0";
}

if ($hasBranches && $branchFilter > 0) {
    $where[] = "bu.branch_id = {$branchFilter}";
}

$whereSql = implode(' AND ', $where);

/* -------------------------------------------------------
   MASTER DATA
------------------------------------------------------- */
$branches = [];
if ($hasBranches) {
    $branches = fetchAllAssoc(
        $conn,
        "SELECT id, branch_name, branch_code
         FROM branches
         WHERE business_id = {$businessId}
         ORDER BY branch_name ASC"
    );
}

/* -------------------------------------------------------
   SUMMARY
------------------------------------------------------- */
$totalUsers = getCount($conn, 'business_users', "business_id = {$businessId}");
$activeUsers = getCount($conn, 'business_users', "business_id = {$businessId} AND status = 1");
$inactiveUsers = getCount($conn, 'business_users', "business_id = {$businessId} AND status = 0");

$ownerCount = getCount($conn, 'business_users', "business_id = {$businessId} AND role = 'owner'");
$adminCount = getCount($conn, 'business_users', "business_id = {$businessId} AND role = 'admin'");
$managerCount = getCount($conn, 'business_users', "business_id = {$businessId} AND role = 'manager'");
$salesCount = getCount($conn, 'business_users', "business_id = {$businessId} AND role = 'sales'");
$billingCount = getCount($conn, 'business_users', "business_id = {$businessId} AND role = 'billing'");
$serviceCount = getCount($conn, 'business_users', "business_id = {$businessId} AND role = 'service'");
$storeCount = getCount($conn, 'business_users', "business_id = {$businessId} AND role = 'store'");

/* -------------------------------------------------------
   FETCH USERS
------------------------------------------------------- */
$sql = "SELECT
            bu.*,
            " . ($hasBranches ? "br.branch_name, br.branch_code" : "NULL AS branch_name, NULL AS branch_code") . "
        FROM business_users bu
        " . ($hasBranches ? "LEFT JOIN branches br ON br.id = bu.branch_id" : "") . "
        WHERE {$whereSql}
        ORDER BY bu.id DESC";

$userRows = fetchAllAssoc($conn, $sql);

$pageTitle = 'Business Users';
$currentPage = 'business-users';
?>
<!doctype html>
<html lang="en">

<?php include('includes/head.php'); ?>

<body data-sidebar="dark">

<style>
    .page-content {
        padding-bottom: 90px !important;
    }
    .report-last-row {
        margin-bottom: 40px;
    }
    .card {
        margin-bottom: 24px;
    }
    .table-responsive {
        overflow-x: auto;
    }
    .main-content {
        min-height: calc(100vh - 70px);
    }
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
                        <h4 class="mb-1">Business Users</h4>
                        <p class="text-muted mb-0">Manage business user accounts, roles, branches and status</p>
                    </div>
                    <div class="col-md-5 text-md-end mt-3 mt-md-0">
                        <a href="business-user-add.php" class="btn btn-primary me-2">Add User</a>
                        <a href="index.php" class="btn btn-secondary">Back to Dashboard</a>
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
                                <p class="text-muted mb-1">Total Users</p>
                                <h3 class="mb-0"><?php echo number_format($totalUsers); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Active Users</p>
                                <h3 class="mb-0 text-success"><?php echo number_format($activeUsers); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Inactive Users</p>
                                <h3 class="mb-0 text-danger"><?php echo number_format($inactiveUsers); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Current Login</p>
                                <h3 class="mb-0 text-primary"><?php echo h($loggedUser['full_name'] ?? '-'); ?></h3>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Owner</p>
                                <h5 class="mb-0"><?php echo number_format($ownerCount); ?></h5>
                            </div>
                        </div>
                    </div>
                    <div class="col-md">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Admin</p>
                                <h5 class="mb-0"><?php echo number_format($adminCount); ?></h5>
                            </div>
                        </div>
                    </div>
                    <div class="col-md">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Manager</p>
                                <h5 class="mb-0"><?php echo number_format($managerCount); ?></h5>
                            </div>
                        </div>
                    </div>
                    <div class="col-md">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Sales</p>
                                <h5 class="mb-0"><?php echo number_format($salesCount); ?></h5>
                            </div>
                        </div>
                    </div>
                    <div class="col-md">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Billing</p>
                                <h5 class="mb-0"><?php echo number_format($billingCount); ?></h5>
                            </div>
                        </div>
                    </div>
                    <div class="col-md">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Service</p>
                                <h5 class="mb-0"><?php echo number_format($serviceCount); ?></h5>
                            </div>
                        </div>
                    </div>
                    <div class="col-md">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Store</p>
                                <h5 class="mb-0"><?php echo number_format($storeCount); ?></h5>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- FILTER -->
                <div class="card">
                    <div class="card-body">
                        <form method="get" class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Search</label>
                                <input
                                    type="text"
                                    name="search"
                                    class="form-control"
                                    placeholder="Name, username, email, mobile..."
                                    value="<?php echo h($search); ?>"
                                >
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">Role</label>
                                <select name="role" class="form-select">
                                    <option value="">All Roles</option>
                                    <?php foreach ($allowedRoles as $role): ?>
                                        <option value="<?php echo h($role); ?>" <?php echo ($roleFilter === $role) ? 'selected' : ''; ?>>
                                            <?php echo h(ucwords(str_replace('_', ' ', $role))); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">Status</label>
                                <select name="status" class="form-select">
                                    <option value="">All Status</option>
                                    <option value="active" <?php echo ($statusFilter === 'active') ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo ($statusFilter === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                            </div>

                            <?php if ($hasBranches): ?>
                            <div class="col-md-3">
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
                            <?php endif; ?>

                            <div class="col-md-1 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary w-100">Go</button>
                            </div>

                            <div class="col-md-12 d-flex gap-2">
                                <a href="business-users.php" class="btn btn-light">Reset</a>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- USER LIST -->
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4">User List</h4>

                        <div class="table-responsive">
                            <table class="table table-bordered table-striped align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>User</th>
                                        <th>Login</th>
                                        <th>Contact</th>
                                        <th>Role</th>
                                        <th>Branch</th>
                                        <th>Status</th>
                                        <th>Last Login</th>
                                        <th>Created</th>
                                        <th style="width:260px;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($userRows)): ?>
                                        <?php $i = 1; foreach ($userRows as $row): ?>
                                            <?php
                                            $roleBadge = 'secondary';
                                            if (($row['role'] ?? '') === 'owner') $roleBadge = 'dark';
                                            elseif (($row['role'] ?? '') === 'admin') $roleBadge = 'primary';
                                            elseif (($row['role'] ?? '') === 'manager') $roleBadge = 'info';
                                            elseif (($row['role'] ?? '') === 'sales') $roleBadge = 'success';
                                            elseif (($row['role'] ?? '') === 'billing') $roleBadge = 'warning';
                                            elseif (($row['role'] ?? '') === 'service') $roleBadge = 'danger';
                                            elseif (($row['role'] ?? '') === 'store') $roleBadge = 'secondary';
                                            ?>
                                            <tr>
                                                <td><?php echo $i++; ?></td>

                                                <td class="small">
                                                    <div><strong><?php echo h($row['full_name'] ?: '-'); ?></strong></div>
                                                    <?php if ((int)$row['id'] === $businessUserId): ?>
                                                        <div><span class="badge bg-primary">You</span></div>
                                                    <?php endif; ?>
                                                </td>

                                                <td class="small">
                                                    <div><strong>Username:</strong> <?php echo h($row['username'] ?: '-'); ?></div>
                                                </td>

                                                <td class="small">
                                                    <div><strong>Email:</strong> <?php echo h($row['email'] ?: '-'); ?></div>
                                                    <div><strong>Mobile:</strong> <?php echo h($row['mobile'] ?: '-'); ?></div>
                                                </td>

                                                <td>
                                                    <span class="badge bg-<?php echo $roleBadge; ?>">
                                                        <?php echo h(ucwords(str_replace('_', ' ', (string)$row['role']))); ?>
                                                    </span>
                                                </td>

                                                <td class="small">
                                                    <div><?php echo h($row['branch_name'] ?: '-'); ?></div>
                                                    <div class="text-muted"><?php echo h($row['branch_code'] ?: '-'); ?></div>
                                                </td>

                                                <td>
                                                    <?php if ((int)($row['status'] ?? 0) === 1): ?>
                                                        <span class="badge bg-success">Active</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-danger">Inactive</span>
                                                    <?php endif; ?>
                                                </td>

                                                <td class="small">
                                                    <?php echo !empty($row['last_login_at']) ? h(date('d M Y h:i A', strtotime($row['last_login_at']))) : '-'; ?>
                                                </td>

                                                <td class="small">
                                                    <?php echo !empty($row['created_at']) ? h(date('d M Y h:i A', strtotime($row['created_at']))) : '-'; ?>
                                                </td>

                                                <td>
                                                    <div class="d-flex flex-wrap gap-2">
                                                        <a href="business-user-view.php?id=<?php echo (int)$row['id']; ?>" class="btn btn-sm btn-info">View</a>
                                                        <a href="business-user-edit.php?id=<?php echo (int)$row['id']; ?>" class="btn btn-sm btn-primary">Edit</a>

                                                        <?php if ((int)($row['status'] ?? 0) === 1): ?>
                                                            <form method="post" style="display:inline;" onsubmit="return confirm('Deactivate this user?');">
                                                                <input type="hidden" name="action" value="deactivate">
                                                                <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                                                                <button type="submit" class="btn btn-sm btn-warning">Deactivate</button>
                                                            </form>
                                                        <?php else: ?>
                                                            <form method="post" style="display:inline;" onsubmit="return confirm('Activate this user?');">
                                                                <input type="hidden" name="action" value="activate">
                                                                <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                                                                <button type="submit" class="btn btn-sm btn-success">Activate</button>
                                                            </form>
                                                        <?php endif; ?>

                                                        <form method="post" style="display:inline;" onsubmit="return confirm('Delete this user?');">
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
                                            <td colspan="10" class="text-center text-muted py-4">No users found.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="mt-3 text-muted small">
                            Showing business user records.
                        </div>
                    </div>
                </div>

                <div class="row report-last-row">
                    <div class="col-xl-6">
                        <div class="card h-100">
                            <div class="card-body">
                                <h4 class="card-title mb-4">User Summary</h4>
                                <table class="table table-bordered table-striped mb-0">
                                    <tbody>
                                        <tr>
                                            <th style="width:50%;">Total Users</th>
                                            <td><?php echo number_format($totalUsers); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Active Users</th>
                                            <td><?php echo number_format($activeUsers); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Inactive Users</th>
                                            <td><?php echo number_format($inactiveUsers); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Owners</th>
                                            <td><?php echo number_format($ownerCount); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Admins</th>
                                            <td><?php echo number_format($adminCount); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Managers</th>
                                            <td><?php echo number_format($managerCount); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Sales Users</th>
                                            <td><?php echo number_format($salesCount); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Billing Users</th>
                                            <td><?php echo number_format($billingCount); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Service Users</th>
                                            <td><?php echo number_format($serviceCount); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Store Users</th>
                                            <td><?php echo number_format($storeCount); ?></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-6">
                        <div class="card h-100">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Notes</h4>
                                <div class="small text-muted">
                                    <p class="mb-2">This page lists all business users created under the current business.</p>
                                    <p class="mb-2">You cannot deactivate or delete your own currently logged-in account from this page.</p>
                                    <p class="mb-0">For complete user management, create these linked pages also: <strong>business-user-add.php</strong>, <strong>business-user-edit.php</strong>, and <strong>business-user-view.php</strong>.</p>
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