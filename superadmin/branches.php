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
   DELETE BRANCH
------------------------------------------------------- */
if (isset($_GET['delete']) && $_GET['delete'] !== '') {
    $deleteId = (int)$_GET['delete'];

    if ($deleteId > 0) {
        $stmt = $conn->prepare("DELETE FROM branches WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("i", $deleteId);
            if ($stmt->execute()) {
                $success = 'Branch deleted successfully.';
            } else {
                $error = 'Failed to delete branch.';
            }
            $stmt->close();
        } else {
            $error = 'Database error while deleting branch.';
        }
    }
}

/* -------------------------------------------------------
   FILTERS
------------------------------------------------------- */
$search = trim($_GET['search'] ?? '');
$status = trim($_GET['status'] ?? '');
$business_id = (int)($_GET['business_id'] ?? 0);

$where = " WHERE 1=1 ";
$params = [];
$types = "";

if ($search !== '') {
    $where .= " AND (
        br.branch_name LIKE ?
        OR br.branch_code LIKE ?
        OR br.contact_person LIKE ?
        OR br.mobile LIKE ?
        OR br.email LIKE ?
        OR b.business_name LIKE ?
    ) ";
    $searchLike = "%{$search}%";
    $params[] = $searchLike;
    $params[] = $searchLike;
    $params[] = $searchLike;
    $params[] = $searchLike;
    $params[] = $searchLike;
    $params[] = $searchLike;
    $types .= "ssssss";
}

if ($status !== '' && in_array($status, ['active', 'inactive'], true)) {
    $where .= " AND br.status = ? ";
    $params[] = $status;
    $types .= "s";
}

if ($business_id > 0) {
    $where .= " AND br.business_id = ? ";
    $params[] = $business_id;
    $types .= "i";
}

/* -------------------------------------------------------
   FETCH BUSINESSES FOR FILTER
------------------------------------------------------- */
$businesses = [];
$res = $conn->query("SELECT id, business_name, business_code FROM businesses ORDER BY business_name ASC");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $businesses[] = $row;
    }
}

/* -------------------------------------------------------
   FETCH BRANCHES
------------------------------------------------------- */
$branches = [];

$sql = "SELECT 
            br.id,
            br.business_id,
            br.branch_name,
            br.branch_code,
            br.contact_person,
            br.mobile,
            br.email,
            br.city,
            br.state,
            br.status,
            br.is_head_office,
            br.created_at,
            b.business_name,
            b.business_code
        FROM branches br
        INNER JOIN businesses b ON b.id = br.business_id
        {$where}
        ORDER BY br.id DESC";

$stmt = $conn->prepare($sql);
if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $branches[] = $row;
        }
    }
    $stmt->close();
}

/* -------------------------------------------------------
   COUNTS
------------------------------------------------------- */
$totalBranches = 0;
$activeBranches = 0;
$inactiveBranches = 0;
$headOffices = 0;

$res = $conn->query("SELECT COUNT(*) AS total FROM branches");
if ($res && $row = $res->fetch_assoc()) {
    $totalBranches = (int)$row['total'];
}

$res = $conn->query("SELECT COUNT(*) AS total FROM branches WHERE status = 'active'");
if ($res && $row = $res->fetch_assoc()) {
    $activeBranches = (int)$row['total'];
}

$res = $conn->query("SELECT COUNT(*) AS total FROM branches WHERE status = 'inactive'");
if ($res && $row = $res->fetch_assoc()) {
    $inactiveBranches = (int)$row['total'];
}

$res = $conn->query("SELECT COUNT(*) AS total FROM branches WHERE is_head_office = 1");
if ($res && $row = $res->fetch_assoc()) {
    $headOffices = (int)$row['total'];
}

$pageTitle = 'Branches';
$currentPage = 'branches';
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
                                <p class="text-muted mb-1">Total Branches</p>
                                <h3 class="text-primary mt-2 mb-0"><?php echo number_format($totalBranches); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <p class="text-muted mb-1">Active Branches</p>
                                <h3 class="text-success mt-2 mb-0"><?php echo number_format($activeBranches); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <p class="text-muted mb-1">Inactive Branches</p>
                                <h3 class="text-warning mt-2 mb-0"><?php echo number_format($inactiveBranches); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <p class="text-muted mb-1">Head Offices</p>
                                <h3 class="text-info mt-2 mb-0"><?php echo number_format($headOffices); ?></h3>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filter -->
                <div class="card">
                    <div class="card-body">
                        <form method="get" class="row g-3 align-items-end">
                            <div class="col-md-4">
                                <label class="form-label">Search</label>
                                <input type="text" name="search" class="form-control"
                                       placeholder="Branch / business / contact / mobile / email"
                                       value="<?php echo h($search); ?>">
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">Business</label>
                                <select name="business_id" class="form-select">
                                    <option value="">All Businesses</option>
                                    <?php foreach ($businesses as $biz): ?>
                                        <option value="<?php echo (int)$biz['id']; ?>"
                                            <?php echo ($business_id === (int)$biz['id']) ? 'selected' : ''; ?>>
                                            <?php echo h($biz['business_name'] . ' (' . $biz['business_code'] . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">Status</label>
                                <select name="status" class="form-select">
                                    <option value="">All Status</option>
                                    <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo $status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                            </div>

                            <div class="col-md-3">
                                <div class="d-flex gap-2">
                                    <button type="submit" class="btn btn-primary">Filter</button>
                                    <a href="branches.php" class="btn btn-secondary">Reset</a>
                                    <a href="branch-add.php" class="btn btn-success ms-auto">Add Branch</a>
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
                                        <th style="width:60px;">#</th>
                                        <th>Branch Name</th>
                                        <th>Branch Code</th>
                                        <th>Business</th>
                                        <th>Contact Person</th>
                                        <th>Mobile</th>
                                        <th>Email</th>
                                        <th>Location</th>
                                        <th>Head Office</th>
                                        <th>Status</th>
                                        <th>Created</th>
                                        <th style="width:220px;">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($branches)): ?>
                                        <?php $sno = 1; ?>
                                        <?php foreach ($branches as $row): ?>
                                            <tr>
                                                <td><?php echo $sno++; ?></td>
                                                <td><?php echo h($row['branch_name']); ?></td>
                                                <td><?php echo h($row['branch_code']); ?></td>
                                                <td>
                                                    <div><?php echo h($row['business_name']); ?></div>
                                                    <small class="text-muted"><?php echo h($row['business_code']); ?></small>
                                                </td>
                                                <td><?php echo h($row['contact_person'] ?: '-'); ?></td>
                                                <td><?php echo h($row['mobile'] ?: '-'); ?></td>
                                                <td><?php echo h($row['email'] ?: '-'); ?></td>
                                                <td>
                                                    <?php
                                                    $location = trim(($row['city'] ?? '') . ', ' . ($row['state'] ?? ''), ', ');
                                                    echo h($location !== '' ? $location : '-');
                                                    ?>
                                                </td>
                                                <td>
                                                    <?php if ((int)$row['is_head_office'] === 1): ?>
                                                        <span class="badge bg-info">Yes</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary">No</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($row['status'] === 'active'): ?>
                                                        <span class="badge bg-success">Active</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-warning">Inactive</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo h(date('d M Y', strtotime($row['created_at']))); ?></td>
                                                <td>
                                                    <div class="d-flex flex-wrap gap-1">
                                                        <a href="branch-view.php?id=<?php echo (int)$row['id']; ?>" class="btn btn-info btn-sm">View</a>
                                                        <a href="branch-edit.php?id=<?php echo (int)$row['id']; ?>" class="btn btn-primary btn-sm">Edit</a>
                                                        <a href="branches.php?delete=<?php echo (int)$row['id']; ?>"
                                                           class="btn btn-danger btn-sm"
                                                           onclick="return confirm('Are you sure you want to delete this branch?');">
                                                            Delete
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="12" class="text-center text-muted py-4">No branches found.</td>
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