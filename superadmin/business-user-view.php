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

$userId = (int)($_GET['id'] ?? 0);
if ($userId <= 0) {
    header("Location: business-users.php");
    exit;
}

$user = null;

/* -------------------------------------------------------
   FETCH USER DETAILS
------------------------------------------------------- */
$stmt = $conn->prepare("
    SELECT 
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
    WHERE bu.id = ?
    LIMIT 1
");
if ($stmt) {
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result ? $result->fetch_assoc() : null;
    $stmt->close();
}

if (!$user) {
    header("Location: business-users.php");
    exit;
}

/* -------------------------------------------------------
   COUNTS FOR THIS USER'S BUSINESS / BRANCH
------------------------------------------------------- */
$businessBranchCount = 0;
$businessUserCount = 0;
$branchUserCount = 0;

$stmt = $conn->prepare("SELECT COUNT(*) AS total FROM branches WHERE business_id = ?");
if ($stmt) {
    $stmt->bind_param("i", $user['business_id']);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $businessBranchCount = (int)($row['total'] ?? 0);
    $stmt->close();
}

$stmt = $conn->prepare("SELECT COUNT(*) AS total FROM business_users WHERE business_id = ?");
if ($stmt) {
    $stmt->bind_param("i", $user['business_id']);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $businessUserCount = (int)($row['total'] ?? 0);
    $stmt->close();
}

if (!empty($user['branch_id'])) {
    $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM business_users WHERE branch_id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $user['branch_id']);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $branchUserCount = (int)($row['total'] ?? 0);
        $stmt->close();
    }
}

$pageTitle = 'View Business User';
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

                <!-- Top Card -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex flex-wrap align-items-center justify-content-between">
                                    <div>
                                        <h3 class="mb-1"><?php echo h($user['full_name']); ?></h3>
                                        <p class="text-muted mb-1">@<?php echo h($user['username']); ?></p>
                                        <span class="badge bg-<?php echo (int)$user['status'] === 1 ? 'success' : 'danger'; ?>">
                                            <?php echo (int)$user['status'] === 1 ? 'Active' : 'Inactive'; ?>
                                        </span>
                                        <span class="badge bg-primary ms-1">
                                            <?php echo h(ucwords(str_replace('_', ' ', $user['role']))); ?>
                                        </span>
                                    </div>
                                    <div class="mt-3 mt-md-0 d-flex gap-2">
                                        <a href="business-user-edit.php?id=<?php echo (int)$user['id']; ?>" class="btn btn-primary">Edit User</a>
                                        <a href="business-users.php" class="btn btn-secondary">Back</a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Stats -->
                <div class="row">
                    <div class="col-md-4 col-xl-4">
                        <div class="card text-center">
                            <div class="card-body">
                                <p class="text-muted mb-1">Business Branches</p>
                                <h3 class="text-primary mt-2 mb-0"><?php echo number_format($businessBranchCount); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4 col-xl-4">
                        <div class="card text-center">
                            <div class="card-body">
                                <p class="text-muted mb-1">Business Users</p>
                                <h3 class="text-success mt-2 mb-0"><?php echo number_format($businessUserCount); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4 col-xl-4">
                        <div class="card text-center">
                            <div class="card-body">
                                <p class="text-muted mb-1">Branch Users</p>
                                <h3 class="text-info mt-2 mb-0"><?php echo number_format($branchUserCount); ?></h3>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Details -->
                <div class="row">
                    <div class="col-xl-6">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">User Details</h4>
                                <div class="table-responsive">
                                    <table class="table table-bordered mb-0">
                                        <tbody>
                                            <tr>
                                                <th style="width:220px;">Full Name</th>
                                                <td><?php echo h($user['full_name']); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Username</th>
                                                <td><?php echo h($user['username']); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Email</th>
                                                <td><?php echo h($user['email'] ?: '-'); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Mobile</th>
                                                <td><?php echo h($user['mobile'] ?: '-'); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Role</th>
                                                <td><?php echo h(ucwords(str_replace('_', ' ', $user['role']))); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Status</th>
                                                <td>
                                                    <span class="badge bg-<?php echo (int)$user['status'] === 1 ? 'success' : 'danger'; ?>">
                                                        <?php echo (int)$user['status'] === 1 ? 'Active' : 'Inactive'; ?>
                                                    </span>
                                                </td>
                                            </tr>
                                            <tr>
                                                <th>Last Login</th>
                                                <td>
                                                    <?php
                                                    echo !empty($user['last_login_at'])
                                                        ? h(date('d M Y h:i A', strtotime($user['last_login_at'])))
                                                        : '-';
                                                    ?>
                                                </td>
                                            </tr>
                                            <tr>
                                                <th>Created At</th>
                                                <td><?php echo h(date('d M Y h:i A', strtotime($user['created_at']))); ?></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-6">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Business & Branch Details</h4>
                                <div class="table-responsive">
                                    <table class="table table-bordered mb-0">
                                        <tbody>
                                            <tr>
                                                <th style="width:220px;">Business Name</th>
                                                <td><?php echo h($user['business_name']); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Business Code</th>
                                                <td><?php echo h($user['business_code']); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Branch Access</th>
                                                <td>
                                                    <?php if (!empty($user['branch_name'])): ?>
                                                        <?php echo h($user['branch_name']); ?> (<?php echo h($user['branch_code']); ?>)
                                                    <?php else: ?>
                                                        <span class="badge bg-info">All Branches Access</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <tr>
                                                <th>Quick Links</th>
                                                <td>
                                                    <a href="business-view.php?id=<?php echo (int)$user['business_id']; ?>" class="btn btn-sm btn-primary me-1">View Business</a>
                                                    <?php if (!empty($user['branch_id'])): ?>
                                                        <a href="branch-view.php?id=<?php echo (int)$user['branch_id']; ?>" class="btn btn-sm btn-info">View Branch</a>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <tr>
                                                <th>Total Business Branches</th>
                                                <td><?php echo number_format($businessBranchCount); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Total Business Users</th>
                                                <td><?php echo number_format($businessUserCount); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Total Branch Users</th>
                                                <td><?php echo number_format($branchUserCount); ?></td>
                                            </tr>
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