<?php
session_start();
date_default_timezone_set('Asia/Kolkata');

require_once 'includes/config.php';

/*
|--------------------------------------------------------------------------
| Business Admin Blocked Users - blocked-users.php
|--------------------------------------------------------------------------
| This page displays and manages blocked/suspended users with:
| - List view with search and filters
| - Reactivate user functionality
| - View blocked user details
|--------------------------------------------------------------------------
*/

if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not available.");
}
$conn->set_charset("utf8mb4");

/* -------------------------------------------------------
   AUTH CHECK
------------------------------------------------------- */
$businessUserId = isset($_SESSION['business_user_id']) ? (int)$_SESSION['business_user_id'] : 0;
if ($businessUserId <= 0 && isset($_SESSION['user_id'])) {
    $businessUserId = (int)$_SESSION['user_id'];
}

$businessId = isset($_SESSION['business_id']) ? (int)$_SESSION['business_id'] : 0;
$branchId   = isset($_SESSION['branch_id']) ? (int)$_SESSION['branch_id'] : 0;

if ($businessUserId <= 0 || $businessId <= 0) {
    header("Location: login.php");
    exit;
}

/* -------------------------------------------------------
   HELPERS
------------------------------------------------------- */
function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function tableExists(mysqli $conn, string $table): bool
{
    $sql = "SELECT 1
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return false;

    $stmt->bind_param("s", $table);
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
    if (!$res) return 0;
    $row = $res->fetch_assoc();
    return (int)($row['total'] ?? 0);
}

/* -------------------------------------------------------
   FETCH LOGGED-IN BUSINESS USER
------------------------------------------------------- */
$admin = null;
if (tableExists($conn, 'business_users') && tableExists($conn, 'businesses')) {
    $sql = "SELECT 
                bu.id,
                bu.business_id,
                bu.branch_id,
                bu.full_name,
                bu.username,
                bu.email,
                bu.mobile,
                bu.role,
                bu.status,
                b.business_name,
                b.business_code,
                b.status AS business_status
            FROM business_users bu
            INNER JOIN businesses b ON b.id = bu.business_id
            WHERE bu.id = ? AND bu.business_id = ?
            LIMIT 1";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("ii", $businessUserId, $businessId);
        $stmt->execute();
        $admin = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}

if (!$admin || (int)$admin['status'] !== 1 || ($admin['business_status'] ?? '') !== 'active') {
    session_destroy();
    header("Location: login.php");
    exit;
}

/* -------------------------------------------------------
   HANDLE POST REQUESTS (Reactivate User)
------------------------------------------------------- */
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        
        // REACTIVATE USER
        if ($_POST['action'] === 'reactivate' && isset($_POST['user_id'])) {
            $userId = (int)$_POST['user_id'];
            
            $sql = "UPDATE business_users SET status = 1 WHERE id = ? AND business_id = ?";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param("ii", $userId, $businessId);
                
                if ($stmt->execute()) {
                    $message = 'User reactivated successfully.';
                    $messageType = 'success';
                    
                    // Add to audit log
                    if (tableExists($conn, 'audit_logs')) {
                        $auditSql = "INSERT INTO audit_logs (business_id, branch_id, user_id, action, module_name, ref_table, ref_id, description) 
                                     VALUES (?, ?, ?, 'Reactivate User', 'Users', 'business_users', ?, ?)";
                        $auditStmt = $conn->prepare($auditSql);
                        if ($auditStmt) {
                            $desc = "Reactivated user ID: " . $userId;
                            $auditStmt->bind_param("iiiis", $businessId, $branchId, $businessUserId, $userId, $desc);
                            $auditStmt->execute();
                            $auditStmt->close();
                        }
                    }
                } else {
                    $message = 'Error reactivating user: ' . $conn->error;
                    $messageType = 'danger';
                }
                $stmt->close();
            }
        }
        
        // PERMANENTLY DELETE USER
        elseif ($_POST['action'] === 'delete' && isset($_POST['user_id'])) {
            $userId = (int)$_POST['user_id'];
            
            // Check if user has any activity before deleting
            $hasActivity = false;
            if (tableExists($conn, 'audit_logs')) {
                $checkSql = "SELECT id FROM audit_logs WHERE user_id = ? LIMIT 1";
                $checkStmt = $conn->prepare($checkSql);
                if ($checkStmt) {
                    $checkStmt->bind_param("i", $userId);
                    $checkStmt->execute();
                    $hasActivity = $checkStmt->get_result()->num_rows > 0;
                    $checkStmt->close();
                }
            }
            
            if ($hasActivity) {
                $message = 'Cannot delete user with existing activity. You can only deactivate them.';
                $messageType = 'danger';
            } else {
                $sql = "DELETE FROM business_users WHERE id = ? AND business_id = ?";
                $stmt = $conn->prepare($sql);
                if ($stmt) {
                    $stmt->bind_param("ii", $userId, $businessId);
                    
                    if ($stmt->execute()) {
                        $message = 'User permanently deleted successfully.';
                        $messageType = 'success';
                        
                        // Add to audit log
                        if (tableExists($conn, 'audit_logs')) {
                            $auditSql = "INSERT INTO audit_logs (business_id, branch_id, user_id, action, module_name, ref_table, ref_id, description) 
                                         VALUES (?, ?, ?, 'Delete User', 'Users', 'business_users', ?, ?)";
                            $auditStmt = $conn->prepare($auditSql);
                            if ($auditStmt) {
                                $desc = "Permanently deleted user ID: " . $userId;
                                $auditStmt->bind_param("iiiis", $businessId, $branchId, $businessUserId, $userId, $desc);
                                $auditStmt->execute();
                                $auditStmt->close();
                            }
                        }
                    } else {
                        $message = 'Error deleting user: ' . $conn->error;
                        $messageType = 'danger';
                    }
                    $stmt->close();
                }
            }
        }
    }
}

/* -------------------------------------------------------
   FILTERS AND PAGINATION
------------------------------------------------------- */
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$filterRole = isset($_GET['role']) ? trim($_GET['role']) : '';
$filterBranch = isset($_GET['branch']) ? (int)$_GET['branch'] : 0;

$whereClause = "bu.business_id = {$businessId} AND bu.status = 0"; // Only blocked users (status = 0)
if (!empty($search)) {
    $searchEscaped = $conn->real_escape_string($search);
    $whereClause .= " AND (bu.full_name LIKE '%{$searchEscaped}%' 
                        OR bu.username LIKE '%{$searchEscaped}%' 
                        OR bu.email LIKE '%{$searchEscaped}%'
                        OR bu.mobile LIKE '%{$searchEscaped}%')";
}
if (!empty($filterRole)) {
    $filterRoleEscaped = $conn->real_escape_string($filterRole);
    $whereClause .= " AND bu.role = '{$filterRoleEscaped}'";
}
if ($filterBranch > 0) {
    $whereClause .= " AND bu.branch_id = {$filterBranch}";
}

/* -------------------------------------------------------
   SUMMARY STATS
------------------------------------------------------- */
$totalBlocked = tableExists($conn, 'business_users')
    ? getCount($conn, 'business_users', "business_id = {$businessId} AND status = 0")
    : 0;

$totalAdmins = tableExists($conn, 'business_users')
    ? getCount($conn, 'business_users', "business_id = {$businessId} AND status = 0 AND role = 'admin'")
    : 0;

$totalManagers = tableExists($conn, 'business_users')
    ? getCount($conn, 'business_users', "business_id = {$businessId} AND status = 0 AND role = 'manager'")
    : 0;

$totalStaff = tableExists($conn, 'business_users')
    ? getCount($conn, 'business_users', "business_id = {$businessId} AND status = 0 AND role IN ('sales', 'billing', 'service', 'store')")
    : 0;

/* -------------------------------------------------------
   FETCH BLOCKED USERS
------------------------------------------------------- */
$blockedUsers = [];
if (tableExists($conn, 'business_users') && tableExists($conn, 'branches')) {
    $sql = "SELECT 
                bu.*,
                b.branch_name
            FROM business_users bu
            LEFT JOIN branches b ON b.id = bu.branch_id
            WHERE {$whereClause}
            ORDER BY bu.id DESC
            LIMIT {$limit} OFFSET {$offset}";
    
    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $blockedUsers[] = $row;
        }
    }
}

// Get total count for pagination
$totalUsers = 0;
if (tableExists($conn, 'business_users')) {
    $countSql = "SELECT COUNT(*) AS total FROM business_users bu WHERE {$whereClause}";
    $countRes = $conn->query($countSql);
    if ($countRes) {
        $totalUsers = $countRes->fetch_assoc()['total'];
    }
}

$totalPages = ceil($totalUsers / $limit);

// Get branches for filter dropdown
$branches = [];
if (tableExists($conn, 'branches')) {
    $branchSql = "SELECT id, branch_name FROM branches WHERE business_id = {$businessId} AND status = 'active' ORDER BY branch_name";
    $branchRes = $conn->query($branchSql);
    if ($branchRes) {
        while ($row = $branchRes->fetch_assoc()) {
            $branches[] = $row;
        }
    }
}

// Get unique roles for filter dropdown
$roles = ['admin', 'manager', 'sales', 'billing', 'service', 'store'];

/* -------------------------------------------------------
   PAGE VARIABLES
------------------------------------------------------- */
$pageTitle = 'Blocked Users';
$currentPage = 'blocked-users';
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

                <!-- Page Title -->
                <div class="row">
                    <div class="col-12">
                        <div class="page-title-box d-flex align-items-center justify-content-between">
                            <h4 class="mb-0">Blocked Users</h4>
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
                                    <li class="breadcrumb-item"><a href="users.php">Users</a></li>
                                    <li class="breadcrumb-item active">Blocked Users</li>
                                </ol>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if (!empty($message)): ?>
                    <div class="row">
                        <div class="col-12">
                            <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
                                <?php echo h($message); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Summary Cards -->
                <div class="row">
                    <div class="col-md-6 col-xl-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Total Blocked</p>
                                <h3 class="mb-0 text-danger"><?php echo number_format($totalBlocked); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Admins</p>
                                <h3 class="mb-0 text-warning"><?php echo number_format($totalAdmins); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Managers</p>
                                <h3 class="mb-0 text-info"><?php echo number_format($totalManagers); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Staff</p>
                                <h3 class="mb-0 text-secondary"><?php echo number_format($totalStaff); ?></h3>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Main Card -->
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex flex-wrap align-items-center justify-content-between mb-4">
                            <h4 class="card-title mb-0">Blocked Users Management</h4>
                            <a href="business-users.php" class="btn btn-primary">
                                <i class="ri-user-line align-middle me-1"></i> View Active Users
                            </a>
                        </div>

                        <!-- Filters -->
                        <div class="row mb-3">
                            <div class="col-md-5">
                                <form method="GET" action="blocked-users.php" id="filterForm">
                                    <div class="input-group">
                                        <input type="text" class="form-control" name="search" 
                                               placeholder="Search by name, username, email, mobile..." 
                                               value="<?php echo h($search); ?>">
                                        <button class="btn btn-primary" type="submit">
                                            <i class="ri-search-line"></i>
                                        </button>
                                        <?php if (!empty($search) || !empty($filterRole) || !empty($filterBranch)): ?>
                                            <a href="blocked-users.php" class="btn btn-secondary">
                                                <i class="ri-close-line"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </form>
                            </div>
                            <div class="col-md-3">
                                <select class="form-control" name="role" form="filterForm" onchange="this.form.submit()">
                                    <option value="">All Roles</option>
                                    <?php foreach ($roles as $role): ?>
                                        <option value="<?php echo $role; ?>" <?php echo $filterRole === $role ? 'selected' : ''; ?>>
                                            <?php echo ucfirst($role); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <select class="form-control" name="branch" form="filterForm" onchange="this.form.submit()">
                                    <option value="0">All Branches</option>
                                    <?php foreach ($branches as $branch): ?>
                                        <option value="<?php echo $branch['id']; ?>" <?php echo $filterBranch == $branch['id'] ? 'selected' : ''; ?>>
                                            <?php echo h($branch['branch_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-1 text-end">
                                <span class="text-muted"><?php echo number_format($totalUsers); ?></span>
                            </div>
                        </div>

                        <!-- Users Table -->
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th style="width: 60px;">#</th>
                                        <th>User</th>
                                        <th>Username</th>
                                        <th>Role</th>
                                        <th>Branch</th>
                                        <th>Contact</th>
                                        <th>Last Login</th>
                                        <th>Status</th>
                                        <th style="width: 180px;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($blockedUsers)): ?>
                                        <?php $i = $offset + 1; ?>
                                        <?php foreach ($blockedUsers as $user): ?>
                                            <tr>
                                                <td><?php echo $i++; ?></td>

                                                <td>
                                                    <div class="fw-bold">
                                                        <?php echo h($user['full_name']); ?>
                                                    </div>
                                                    <div class="text-muted small">ID: <?php echo $user['id']; ?></div>
                                                </td>

                                                <td><?php echo h($user['username']); ?></td>

                                                <td>
                                                    <?php
                                                    $roleBadge = 'secondary';
                                                    if ($user['role'] === 'admin') $roleBadge = 'danger';
                                                    elseif ($user['role'] === 'manager') $roleBadge = 'warning';
                                                    elseif ($user['role'] === 'sales') $roleBadge = 'info';
                                                    elseif ($user['role'] === 'billing') $roleBadge = 'primary';
                                                    elseif ($user['role'] === 'service') $roleBadge = 'success';
                                                    ?>
                                                    <span class="badge bg-<?php echo $roleBadge; ?>">
                                                        <?php echo ucfirst($user['role']); ?>
                                                    </span>
                                                </td>

                                                <td><?php echo h($user['branch_name'] ?? 'All Branches'); ?></td>

                                                <td>
                                                    <?php if (!empty($user['mobile'])): ?>
                                                        <div><i class="ri-phone-line me-1"></i><?php echo h($user['mobile']); ?></div>
                                                    <?php endif; ?>
                                                    <?php if (!empty($user['email'])): ?>
                                                        <div class="text-muted small"><i class="ri-mail-line me-1"></i><?php echo h($user['email']); ?></div>
                                                    <?php endif; ?>
                                                </td>

                                                <td>
                                                    <?php 
                                                    if (!empty($user['last_login_at'])) {
                                                        echo date('d M Y h:i A', strtotime($user['last_login_at']));
                                                    } else {
                                                        echo '<span class="text-muted">Never</span>';
                                                    }
                                                    ?>
                                                </td>

                                                <td>
                                                    <span class="badge bg-danger">Blocked</span>
                                                </td>

                                                <td>
                                                    <div class="btn-group" role="group">
                                                        <button type="button" 
                                                                class="btn btn-sm btn-success" 
                                                                title="Reactivate User"
                                                                onclick="confirmReactivate(<?php echo $user['id']; ?>, '<?php echo h(addslashes($user['full_name'])); ?>')">
                                                            <i class="ri-user-unfollow-line"></i> Reactivate
                                                        </button>
                                                        <button type="button" 
                                                                class="btn btn-sm btn-danger" 
                                                                title="Permanently Delete"
                                                                onclick="confirmPermanentDelete(<?php echo $user['id']; ?>, '<?php echo h(addslashes($user['full_name'])); ?>')">
                                                            <i class="ri-delete-bin-line"></i>
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="9" class="text-center text-muted py-4">
                                                <i class="ri-user-unfollow-line" style="font-size: 3rem; color: #ccc;"></i>
                                                <p class="mt-2">No blocked users found.</p>
                                                <?php if (!empty($search) || !empty($filterRole) || !empty($filterBranch)): ?>
                                                    <a href="blocked-users.php" class="btn btn-sm btn-primary">Clear Filters</a>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination -->
                        <?php if ($totalPages > 1): ?>
                            <div class="row mt-4">
                                <div class="col-12">
                                    <nav>
                                        <ul class="pagination justify-content-center">
                                            <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                                <a class="page-link" href="?page=<?php echo $page-1; ?>&search=<?php echo urlencode($search); ?>&role=<?php echo urlencode($filterRole); ?>&branch=<?php echo $filterBranch; ?>" tabindex="-1">Previous</a>
                                            </li>
                                            
                                            <?php 
                                            $startPage = max(1, $page - 2);
                                            $endPage = min($totalPages, $page + 2);
                                            
                                            for ($i = $startPage; $i <= $endPage; $i++): 
                                            ?>
                                                <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                                    <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&role=<?php echo urlencode($filterRole); ?>&branch=<?php echo $filterBranch; ?>">
                                                        <?php echo $i; ?>
                                                    </a>
                                                </li>
                                            <?php endfor; ?>
                                            
                                            <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                                                <a class="page-link" href="?page=<?php echo $page+1; ?>&search=<?php echo urlencode($search); ?>&role=<?php echo urlencode($filterRole); ?>&branch=<?php echo $filterBranch; ?>">Next</a>
                                            </li>
                                        </ul>
                                    </nav>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Info Card -->
                <div class="row">
                    <div class="col-12">
                        <div class="card bg-light">
                            <div class="card-body">
                                <div class="d-flex">
                                    <div class="flex-shrink-0 me-3">
                                        <i class="ri-information-line" style="font-size: 2rem; color: #6c757d;"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <h5 class="mb-2">About Blocked Users</h5>
                                        <p class="text-muted mb-0">
                                            Blocked users cannot log in to the system. You can reactivate them at any time, which will restore their access with the same permissions.
                                            Permanently deleting a user will remove them from the system completely and cannot be undone.
                                        </p>
                                    </div>
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

<!-- Reactivate Confirmation Form -->
<form method="POST" id="reactivateForm" style="display: none;">
    <input type="hidden" name="action" value="reactivate">
    <input type="hidden" name="user_id" id="reactivateUserId">
</form>

<!-- Permanent Delete Confirmation Form -->
<form method="POST" id="permanentDeleteForm" style="display: none;">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="user_id" id="permanentDeleteUserId">
</form>

<?php include('includes/rightbar.php'); ?>
<?php include('includes/scripts.php'); ?>

<script>
function confirmReactivate(userId, userName) {
    if (confirm('Are you sure you want to reactivate user "' + userName + '"? They will be able to log in again.')) {
        document.getElementById('reactivateUserId').value = userId;
        document.getElementById('reactivateForm').submit();
    }
}

function confirmPermanentDelete(userId, userName) {
    if (confirm('WARNING: Are you sure you want to permanently delete user "' + userName + '"? This action CANNOT be undone.')) {
        document.getElementById('permanentDeleteUserId').value = userId;
        document.getElementById('permanentDeleteForm').submit();
    }
}

// Auto-hide alerts after 5 seconds
setTimeout(function() {
    var alerts = document.querySelectorAll('.alert');
    alerts.forEach(function(alert) {
        var bsAlert = new bootstrap.Alert(alert);
        bsAlert.close();
    });
}, 5000);
</script>

</body>
</html>