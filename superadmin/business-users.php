<?php
date_default_timezone_set('Asia/Kolkata');
require_once 'includes/config.php';

if (!isset($_SESSION['platform_admin_id']) || (int)$_SESSION['platform_admin_id'] <= 0) {
    header("Location: login.php");
    exit;
}

function h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$success = '';
$error   = '';

/* -------------------------------------------------------
   DELETE USER
------------------------------------------------------- */
if (isset($_GET['delete']) && $_GET['delete'] !== '') {
    $deleteId = (int)$_GET['delete'];

    if ($deleteId > 0) {
        $stmt = $conn->prepare("DELETE FROM business_users WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("i", $deleteId);
            if ($stmt->execute()) {
                $success = 'Business user deleted successfully.';
            } else {
                $error = 'Failed to delete business user.';
            }
            $stmt->close();
        } else {
            $error = 'Database error while deleting user.';
        }
    }
}

/* -------------------------------------------------------
   FILTERS
------------------------------------------------------- */
$search      = trim($_GET['search'] ?? '');
$business_id = (int)($_GET['business_id'] ?? 0);
$branch_id   = (int)($_GET['branch_id'] ?? 0);
$role        = trim($_GET['role'] ?? '');
$status      = trim($_GET['status'] ?? '');

$where  = " WHERE 1=1 ";
$params = [];
$types  = "";

if ($search !== '') {
    $where .= " AND (
        bu.full_name LIKE ?
        OR bu.username LIKE ?
        OR bu.email LIKE ?
        OR bu.mobile LIKE ?
        OR b.business_name LIKE ?
        OR br.branch_name LIKE ?
    ) ";
    $searchLike = "%{$search}%";
    $params[] = $searchLike;
    $params[] = $searchLike;
    $params[] = $searchLike;
    $params[] = $searchLike;
    $params[] = $searchLike;
    $params[] = $searchLike;
    $types   .= "ssssss";
}

if ($business_id > 0) {
    $where .= " AND bu.business_id = ? ";
    $params[] = $business_id;
    $types   .= "i";
}

if ($branch_id > 0) {
    $where .= " AND bu.branch_id = ? ";
    $params[] = $branch_id;
    $types   .= "i";
}

if ($role !== '' && in_array($role, ['owner','admin','manager','sales','billing','service','store'], true)) {
    $where .= " AND bu.role = ? ";
    $params[] = $role;
    $types   .= "s";
}

if ($status !== '' && in_array($status, ['1','0'], true)) {
    $where .= " AND bu.status = ? ";
    $params[] = (int)$status;
    $types   .= "i";
}

/* -------------------------------------------------------
   FETCH BUSINESSES
------------------------------------------------------- */
$businesses = [];
$res = $conn->query("SELECT id, business_name, business_code FROM businesses ORDER BY business_name ASC");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $businesses[] = $row;
    }
}

/* -------------------------------------------------------
   FETCH BRANCHES FOR FILTER
------------------------------------------------------- */
$branches = [];
if ($business_id > 0) {
    $stmt = $conn->prepare("SELECT id, branch_name, branch_code FROM branches WHERE business_id = ? ORDER BY branch_name ASC");
    if ($stmt) {
        $stmt->bind_param("i", $business_id);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $branches[] = $row;
            }
        }
        $stmt->close();
    }
} else {
    $res = $conn->query("SELECT id, branch_name, branch_code FROM branches ORDER BY branch_name ASC LIMIT 200");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $branches[] = $row;
        }
    }
}

/* -------------------------------------------------------
   FETCH USERS
------------------------------------------------------- */
$users = [];

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
            bu.last_login_at,
            bu.created_at,
            b.business_name,
            b.business_code,
            br.branch_name,
            br.branch_code
        FROM business_users bu
        INNER JOIN businesses b ON b.id = bu.business_id
        LEFT JOIN branches br ON br.id = bu.branch_id
        {$where}
        ORDER BY bu.id DESC";

$stmt = $conn->prepare($sql);
if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $users[] = $row;
        }
    }
    $stmt->close();
}

/* -------------------------------------------------------
   COUNTS
------------------------------------------------------- */
$totalUsers = 0;
$activeUsers = 0;
$inactiveUsers = 0;
$allBranchUsers = 0;

$res = $conn->query("SELECT COUNT(*) AS total FROM business_users");
if ($res && $row = $res->fetch_assoc()) {
    $totalUsers = (int)$row['total'];
}

$res = $conn->query("SELECT COUNT(*) AS total FROM business_users WHERE status = 1");
if ($res && $row = $res->fetch_assoc()) {
    $activeUsers = (int)$row['total'];
}

$res = $conn->query("SELECT COUNT(*) AS total FROM business_users WHERE status = 0");
if ($res && $row = $res->fetch_assoc()) {
    $inactiveUsers = (int)$row['total'];
}

$res = $conn->query("SELECT COUNT(*) AS total FROM business_users WHERE branch_id IS NULL");
if ($res && $row = $res->fetch_assoc()) {
    $allBranchUsers = (int)$row['total'];
}

$pageTitle = 'Business Users';
$currentPage = 'business-users';
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


                <!-- Alerts -->
                <?php if ($success !== ''): ?>
                    <div class="row">
                        <div class="col-12">
                            <div class="alert alert-success alert-dismissible fade show" role="alert">
                                <?php echo h($success); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($error !== ''): ?>
                    <div class="row">
                        <div class="col-12">
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <?php echo h($error); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Stats -->
                <div class="row">
                    <div class="col-md-6 col-xl-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <p class="text-muted mb-1">Total Users</p>
                                <h3 class="text-primary mt-2 mb-0"><?php echo number_format($totalUsers); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <p class="text-muted mb-1">Active Users</p>
                                <h3 class="text-success mt-2 mb-0"><?php echo number_format($activeUsers); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <p class="text-muted mb-1">Inactive Users</p>
                                <h3 class="text-danger mt-2 mb-0"><?php echo number_format($inactiveUsers); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <p class="text-muted mb-1">All-Branch Access</p>
                                <h3 class="text-info mt-2 mb-0"><?php echo number_format($allBranchUsers); ?></h3>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="card">
                    <div class="card-body">
                        <form method="get" class="row g-3 align-items-end">
                            <div class="col-md-3">
                                <label class="form-label">Search</label>
                                <input type="text" name="search" class="form-control"
                                       placeholder="Name / username / email / mobile"
                                       value="<?php echo h($search); ?>">
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">Business</label>
                                <select name="business_id" class="form-select" onchange="this.form.submit()">
                                    <option value="">All Businesses</option>
                                    <?php foreach ($businesses as $biz): ?>
                                        <option value="<?php echo (int)$biz['id']; ?>" <?php echo $business_id === (int)$biz['id'] ? 'selected' : ''; ?>>
                                            <?php echo h($biz['business_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">Branch</label>
                                <select name="branch_id" class="form-select">
                                    <option value="">All Branches</option>
                                    <?php foreach ($branches as $br): ?>
                                        <option value="<?php echo (int)$br['id']; ?>" <?php echo $branch_id === (int)$br['id'] ? 'selected' : ''; ?>>
                                            <?php echo h($br['branch_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">Role</label>
                                <select name="role" class="form-select">
                                    <option value="">All Roles</option>
                                    <option value="owner" <?php echo $role === 'owner' ? 'selected' : ''; ?>>Owner</option>
                                    <option value="admin" <?php echo $role === 'admin' ? 'selected' : ''; ?>>Admin</option>
                                    <option value="manager" <?php echo $role === 'manager' ? 'selected' : ''; ?>>Manager</option>
                                    <option value="sales" <?php echo $role === 'sales' ? 'selected' : ''; ?>>Sales</option>
                                    <option value="billing" <?php echo $role === 'billing' ? 'selected' : ''; ?>>Billing</option>
                                    <option value="service" <?php echo $role === 'service' ? 'selected' : ''; ?>>Service</option>
                                    <option value="store" <?php echo $role === 'store' ? 'selected' : ''; ?>>Store</option>
                                </select>
                            </div>

                            <div class="col-md-1">
                                <label class="form-label">Status</label>
                                <select name="status" class="form-select">
                                    <option value="">All</option>
                                    <option value="1" <?php echo $status === '1' ? 'selected' : ''; ?>>Active</option>
                                    <option value="0" <?php echo $status === '0' ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                            </div>

                            <div class="col-md-2">
                                <div class="d-flex gap-2">
                                    <button type="submit" class="btn btn-primary">Filter</button>
                                    <a href="business-users.php" class="btn btn-secondary">Reset</a>
                                </div>
                            </div>
                        </form>

                        <div class="mt-3 text-end">
                            <a href="business-user-add.php" class="btn btn-success">Add Business User</a>
                        </div>
                    </div>
                </div>

                <!-- Table -->
                <div class="card">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width:60px;">#</th>
                                        <th>User</th>
                                        <th>Business</th>
                                        <th>Branch</th>
                                        <th>Role</th>
                                        <th>Mobile</th>
                                        <th>Status</th>
                                        <th>Last Login</th>
                                        <th>Created</th>
                                        <th style="width:220px;">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($users)): ?>
                                        <?php $sno = 1; ?>
                                        <?php foreach ($users as $row): ?>
                                            <tr>
                                                <td><?php echo $sno++; ?></td>
                                                <td>
                                                    <div><strong><?php echo h($row['full_name']); ?></strong></div>
                                                    <div class="text-muted">@<?php echo h($row['username']); ?></div>
                                                    <div class="text-muted"><?php echo h($row['email'] ?: '-'); ?></div>
                                                </td>
                                                <td>
                                                    <div><?php echo h($row['business_name']); ?></div>
                                                    <small class="text-muted"><?php echo h($row['business_code']); ?></small>
                                                </td>
                                                <td>
                                                    <?php if (!empty($row['branch_name'])): ?>
                                                        <div><?php echo h($row['branch_name']); ?></div>
                                                        <small class="text-muted"><?php echo h($row['branch_code']); ?></small>
                                                    <?php else: ?>
                                                        <span class="badge bg-info">All Branches</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo h(ucwords(str_replace('_', ' ', $row['role']))); ?></td>
                                                <td><?php echo h($row['mobile'] ?: '-'); ?></td>
                                                <td>
                                                    <?php if ((int)$row['status'] === 1): ?>
                                                        <span class="badge bg-success">Active</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-danger">Inactive</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php
                                                    echo !empty($row['last_login_at'])
                                                        ? h(date('d M Y h:i A', strtotime($row['last_login_at'])))
                                                        : '-';
                                                    ?>
                                                </td>
                                                <td><?php echo h(date('d M Y', strtotime($row['created_at']))); ?></td>
                                                <td>
                                                    <div class="d-flex flex-wrap gap-1">
                                                        <a href="business-user-view.php?id=<?php echo (int)$row['id']; ?>" class="btn btn-info btn-sm">View</a>
                                                        <a href="business-user-edit.php?id=<?php echo (int)$row['id']; ?>" class="btn btn-primary btn-sm">Edit</a>
                                                        <a href="business-users.php?delete=<?php echo (int)$row['id']; ?>"
                                                           class="btn btn-danger btn-sm"
                                                           onclick="return confirm('Are you sure you want to delete this user?');">
                                                            Delete
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="10" class="text-center text-muted py-4">No business users found.</td>
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