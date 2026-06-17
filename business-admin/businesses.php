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
$error = '';

/* -------------------------------------------------------
   DELETE BUSINESS
------------------------------------------------------- */
if (isset($_GET['delete']) && $_GET['delete'] !== '') {
    $deleteId = (int)$_GET['delete'];

    if ($deleteId > 0) {
        $stmt = $conn->prepare("DELETE FROM businesses WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("i", $deleteId);
            if ($stmt->execute()) {
                $success = 'Business deleted successfully.';
            } else {
                $error = 'Failed to delete business.';
            }
            $stmt->close();
        } else {
            $error = 'Database error while deleting business.';
        }
    }
}

/* -------------------------------------------------------
   FILTERS
------------------------------------------------------- */
$search = trim($_GET['search'] ?? '');
$status = trim($_GET['status'] ?? '');

$where = " WHERE 1=1 ";
$params = [];
$types = "";

if ($search !== '') {
    $where .= " AND (
        business_name LIKE ?
        OR business_code LIKE ?
        OR owner_name LIKE ?
        OR mobile LIKE ?
        OR email LIKE ?
    ) ";
    $searchLike = "%{$search}%";
    $params[] = $searchLike;
    $params[] = $searchLike;
    $params[] = $searchLike;
    $params[] = $searchLike;
    $params[] = $searchLike;
    $types .= "sssss";
}

if ($status !== '' && in_array($status, ['active', 'inactive', 'suspended'], true)) {
    $where .= " AND status = ? ";
    $params[] = $status;
    $types .= "s";
}

/* -------------------------------------------------------
   FETCH BUSINESSES
------------------------------------------------------- */
$businesses = [];

$sql = "SELECT 
            id,
            business_name,
            business_code,
            owner_name,
            email,
            mobile,
            city,
            state,
            status,
            created_at
        FROM businesses
        {$where}
        ORDER BY id DESC";

$stmt = $conn->prepare($sql);
if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $businesses[] = $row;
        }
    }
    $stmt->close();
}

/* -------------------------------------------------------
   COUNTS
------------------------------------------------------- */
$totalBusinesses = 0;
$activeBusinesses = 0;
$inactiveBusinesses = 0;
$suspendedBusinesses = 0;

$res = $conn->query("SELECT COUNT(*) AS total FROM businesses");
if ($res && $row = $res->fetch_assoc()) {
    $totalBusinesses = (int)$row['total'];
}

$res = $conn->query("SELECT COUNT(*) AS total FROM businesses WHERE status = 'active'");
if ($res && $row = $res->fetch_assoc()) {
    $activeBusinesses = (int)$row['total'];
}

$res = $conn->query("SELECT COUNT(*) AS total FROM businesses WHERE status = 'inactive'");
if ($res && $row = $res->fetch_assoc()) {
    $inactiveBusinesses = (int)$row['total'];
}

$res = $conn->query("SELECT COUNT(*) AS total FROM businesses WHERE status = 'suspended'");
if ($res && $row = $res->fetch_assoc()) {
    $suspendedBusinesses = (int)$row['total'];
}

$pageTitle = 'Businesses';
$currentPage = 'businesses';
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
                                <p class="text-muted mb-1">Total Businesses</p>
                                <h3 class="text-primary mt-2 mb-0"><?php echo number_format($totalBusinesses); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <p class="text-muted mb-1">Active</p>
                                <h3 class="text-success mt-2 mb-0"><?php echo number_format($activeBusinesses); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <p class="text-muted mb-1">Inactive</p>
                                <h3 class="text-warning mt-2 mb-0"><?php echo number_format($inactiveBusinesses); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <p class="text-muted mb-1">Suspended</p>
                                <h3 class="text-danger mt-2 mb-0"><?php echo number_format($suspendedBusinesses); ?></h3>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filter + Add -->
                <div class="card">
                    <div class="card-body">
                        <form method="get" class="row g-3 align-items-end">
                            <div class="col-md-5">
                                <label class="form-label">Search</label>
                                <input type="text" name="search" class="form-control"
                                    placeholder="Business name / code / owner / mobile / email"
                                    value="<?php echo h($search); ?>">
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">Status</label>
                                <select name="status" class="form-select">
                                    <option value="">All Status</option>
                                    <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo $status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                    <option value="suspended" <?php echo $status === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                                </select>
                            </div>

                            <div class="col-md-4">
                                <div class="d-flex gap-2">
                                    <button type="submit" class="btn btn-primary">
                                        Filter
                                    </button>
                                    <a href="businesses.php" class="btn btn-secondary">
                                        Reset
                                    </a>
                                    <a href="business-add.php" class="btn btn-success ms-auto">
                                        Add Business
                                    </a>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Table -->
                <div class="card">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 60px;">#</th>
                                        <th>Business Name</th>
                                        <th>Code</th>
                                        <th>Owner</th>
                                        <th>Mobile</th>
                                        <th>Email</th>
                                        <th>Location</th>
                                        <th>Status</th>
                                        <th>Created</th>
                                        <th style="width: 220px;">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($businesses)): ?>
                                        <?php $sno = 1; ?>
                                        <?php foreach ($businesses as $row): ?>
                                            <tr>
                                                <td><?php echo $sno++; ?></td>
                                                <td><?php echo h($row['business_name']); ?></td>
                                                <td><?php echo h($row['business_code']); ?></td>
                                                <td><?php echo h($row['owner_name'] ?: '-'); ?></td>
                                                <td><?php echo h($row['mobile'] ?: '-'); ?></td>
                                                <td><?php echo h($row['email'] ?: '-'); ?></td>
                                                <td>
                                                    <?php
                                                    $location = trim(($row['city'] ?? '') . ', ' . ($row['state'] ?? ''), ', ');
                                                    echo h($location !== '' ? $location : '-');
                                                    ?>
                                                </td>
                                                <td>
                                                    <?php
                                                    $badgeClass = 'secondary';
                                                    if ($row['status'] === 'active') {
                                                        $badgeClass = 'success';
                                                    } elseif ($row['status'] === 'inactive') {
                                                        $badgeClass = 'warning';
                                                    } elseif ($row['status'] === 'suspended') {
                                                        $badgeClass = 'danger';
                                                    }
                                                    ?>
                                                    <span class="badge bg-<?php echo $badgeClass; ?>">
                                                        <?php echo h(ucfirst($row['status'])); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo h(date('d M Y', strtotime($row['created_at']))); ?></td>
                                                <td>
                                                    <div class="d-flex flex-wrap gap-1">
                                                        <a href="business-view.php?id=<?php echo (int)$row['id']; ?>" class="btn btn-info btn-sm">
                                                            View
                                                        </a>
                                                        <a href="business-edit.php?id=<?php echo (int)$row['id']; ?>" class="btn btn-primary btn-sm">
                                                            Edit
                                                        </a>
                                                        <a href="branches.php?business_id=<?php echo (int)$row['id']; ?>" class="btn btn-warning btn-sm">
                                                            Branches
                                                        </a>
                                                        <a href="businesses.php?delete=<?php echo (int)$row['id']; ?>"
                                                           class="btn btn-danger btn-sm"
                                                           onclick="return confirm('Are you sure you want to delete this business?');">
                                                            Delete
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="10" class="text-center text-muted py-4">
                                                No businesses found.
                                            </td>
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