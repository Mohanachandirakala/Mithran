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
    if (!$res) return 0;
    $row = $res->fetch_assoc();
    return (int)($row['total'] ?? 0);
}

/* -------------------------------------------------------
   VALIDATE USER / BUSINESS
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
   HANDLE STATUS CHANGE
------------------------------------------------------- */
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    $branchId = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $newStatus = isset($_POST['new_status']) ? trim($_POST['new_status']) : '';

    if ($branchId <= 0 || !in_array($newStatus, ['active', 'inactive'])) {
        $error = 'Invalid request.';
    } else {
        // Check if trying to deactivate the last active branch
        if ($newStatus === 'inactive') {
            // Get current branch status
            $checkStmt = $conn->prepare("SELECT status FROM branches WHERE id = ? AND business_id = ?");
            $checkStmt->bind_param('ii', $branchId, $businessId);
            $checkStmt->execute();
            $currentStatusResult = $checkStmt->get_result();
            $currentBranch = $currentStatusResult->fetch_assoc();
            $checkStmt->close();
            
            // Only check if the branch is currently active
            if ($currentBranch && $currentBranch['status'] === 'active') {
                $activeCount = getCount($conn, 'branches', "business_id = {$businessId} AND status = 'active'");
                if ($activeCount <= 1) {
                    $error = 'Cannot deactivate the only active branch. At least one branch must remain active.';
                }
            }
        }

        if (empty($error)) {
            $stmt = $conn->prepare("UPDATE branches SET status = ?, updated_at = NOW() WHERE id = ? AND business_id = ?");
            if ($stmt) {
                $stmt->bind_param('sii', $newStatus, $branchId, $businessId);
                if ($stmt->execute()) {
                    $success = 'Branch status updated successfully.';
                } else {
                    $error = 'Failed to update branch status.';
                }
                $stmt->close();
            } else {
                $error = 'Failed to prepare status update.';
            }
        }
    }
}

/* -------------------------------------------------------
   FILTERS
------------------------------------------------------- */
$search = trim($_GET['search'] ?? '');
$where = "business_id = {$businessId}";

if ($search !== '') {
    $safeSearch = $conn->real_escape_string($search);
    $where .= " AND (
        branch_name LIKE '%{$safeSearch}%'
        OR branch_code LIKE '%{$safeSearch}%'
        OR city LIKE '%{$safeSearch}%'
        OR district LIKE '%{$safeSearch}%'
        OR state LIKE '%{$safeSearch}%'
        OR mobile LIKE '%{$safeSearch}%'
        OR contact_person LIKE '%{$safeSearch}%'
    )";
}

/* -------------------------------------------------------
   SUMMARY
------------------------------------------------------- */
$totalBranches = tableExists($conn, 'branches')
    ? getCount($conn, 'branches', "business_id = {$businessId}")
    : 0;

$activeBranches = tableExists($conn, 'branches')
    ? getCount($conn, 'branches', "business_id = {$businessId} AND status = 'active'")
    : 0;

$inactiveBranches = tableExists($conn, 'branches')
    ? getCount($conn, 'branches', "business_id = {$businessId} AND status = 'inactive'")
    : 0;

$headOfficeCount = tableExists($conn, 'branches')
    ? getCount($conn, 'branches', "business_id = {$businessId} AND is_head_office = 1")
    : 0;

/* -------------------------------------------------------
   FETCH BRANCHES
------------------------------------------------------- */
$branches = [];

if (tableExists($conn, 'branches')) {
    $sql = "SELECT 
                id,
                branch_name,
                branch_code,
                contact_person,
                email,
                mobile,
                alternate_mobile,
                gstin,
                address_line1,
                address_line2,
                city,
                district,
                state,
                pincode,
                is_head_office,
                status,
                created_at,
                updated_at
            FROM branches
            WHERE {$where}
            ORDER BY is_head_office DESC, id DESC";

    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $branches[] = $row;
        }
    }
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

                <div class="row mb-3">
                    <div class="col-md-6">
                        <h4 class="mb-1">Branches</h4>
                        <p class="text-muted mb-0">Manage all branches of your business</p>
                    </div>
                    <div class="col-md-6 text-md-end mt-3 mt-md-0">
                        <div class="btn-group">
                            <a href="branch-add.php" class="btn btn-primary">
                                <i class="mdi mdi-plus-circle"></i> Add Branch
                            </a>
                            <a href="dashboard.php" class="btn btn-info">
                                <i class="mdi mdi-view-dashboard"></i> Dashboard
                            </a>
                        </div>
                    </div>
                </div>

                <?php if ($success !== ''): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="mdi mdi-check-circle me-2"></i> <?php echo h($success); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="mdi mdi-alert-circle me-2"></i> <?php echo h($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <div class="row">
                    <div class="col-md-6 col-xl-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Total Branches</p>
                                <h3 class="mb-0"><?php echo number_format($totalBranches); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Active Branches</p>
                                <h3 class="mb-0 text-success"><?php echo number_format($activeBranches); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Inactive Branches</p>
                                <h3 class="mb-0 text-warning"><?php echo number_format($inactiveBranches); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Head Offices</p>
                                <h3 class="mb-0 text-info"><?php echo number_format($headOfficeCount); ?></h3>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-body">

                        <form method="get" class="row g-3 mb-4">
                            <div class="col-md-10">
                                <input
                                    type="text"
                                    name="search"
                                    class="form-control"
                                    placeholder="Search by branch name, code, city, district, state, mobile..."
                                    value="<?php echo h($search); ?>"
                                >
                            </div>
                            <div class="col-md-2">
                                <button type="submit" class="btn btn-primary w-100">Search</button>
                            </div>
                        </form>

                        <div class="table-responsive">
                            <table class="table table-bordered table-striped align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th style="width: 60px;">#</th>
                                        <th>Branch</th>
                                        <th>Code</th>
                                        <th>Contact Person</th>
                                        <th>Mobile</th>
                                        <th>Location</th>
                                        <th>Status</th>
                                        <th>Created</th>
                                        <th style="width: 220px;">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($branches)): ?>
                                        <?php $i = 1; ?>
                                        <?php foreach ($branches as $branch): ?>
                                            <?php
                                            $currentStatus = strtolower((string)($branch['status'] ?? 'inactive'));
                                            $isActive = ($currentStatus === 'active');
                                            $isLastActive = ($isActive && $activeBranches <= 1);
                                            ?>
                                            <tr>
                                                <td><?php echo $i++; ?></td>

                                                <td>
                                                    <div class="fw-bold">
                                                        <?php echo h($branch['branch_name']); ?>
                                                    </div>
                                                    <?php if ((int)$branch['is_head_office'] === 1): ?>
                                                        <span class="badge bg-info mt-1">Head Office</span>
                                                    <?php endif; ?>
                                                    <?php if (!empty($branch['email'])): ?>
                                                        <div class="text-muted small mt-1"><?php echo h($branch['email']); ?></div>
                                                    <?php endif; ?>
                                                 </td>

                                                <td><?php echo h($branch['branch_code'] ?: '-'); ?></td>

                                                <td><?php echo h($branch['contact_person'] ?: '-'); ?></td>

                                                <td>
                                                    <div><?php echo h($branch['mobile'] ?: '-'); ?></div>
                                                    <?php if (!empty($branch['alternate_mobile'])): ?>
                                                        <div class="text-muted small"><?php echo h($branch['alternate_mobile']); ?></div>
                                                    <?php endif; ?>
                                                 </td>

                                                <td>
                                                    <div><?php echo h($branch['city'] ?: '-'); ?></div>
                                                    <div class="text-muted small">
                                                        <?php
                                                        $loc = trim(
                                                            ($branch['district'] ?? '') .
                                                            (($branch['district'] ?? '') && ($branch['state'] ?? '') ? ', ' : '') .
                                                            ($branch['state'] ?? '')
                                                        );
                                                        echo h($loc !== '' ? $loc : '-');
                                                        ?>
                                                    </div>
                                                    <?php if (!empty($branch['pincode'])): ?>
                                                        <div class="text-muted small"><?php echo h($branch['pincode']); ?></div>
                                                    <?php endif; ?>
                                                 </td>

                                                <td>
                                                    <?php
                                                    $badge = 'secondary';
                                                    if ($currentStatus === 'active') {
                                                        $badge = 'success';
                                                    } elseif ($currentStatus === 'inactive') {
                                                        $badge = 'warning';
                                                    }
                                                    ?>
                                                    <span class="badge bg-<?php echo $badge; ?>">
                                                        <?php echo h(ucfirst($currentStatus)); ?>
                                                    </span>
                                                 </td>

                                                <td>
                                                    <?php echo !empty($branch['created_at']) ? h(date('d M Y', strtotime($branch['created_at']))) : '-'; ?>
                                                 </td>

                                                <td>
                                                    <div class="d-flex flex-wrap gap-2">
                                                        <a href="branch-view.php?id=<?php echo (int)$branch['id']; ?>" class="btn btn-sm btn-info" title="View Branch">
                                                            <i class="mdi mdi-eye"></i>
                                                        </a>
                                                        <a href="branch-edit.php?id=<?php echo (int)$branch['id']; ?>" class="btn btn-sm btn-primary" title="Edit Branch">
                                                            <i class="mdi mdi-pencil"></i>
                                                        </a>
                                                        
                                                        <?php if ($isActive): ?>
                                                            <?php if ($isLastActive): ?>
                                                                <button type="button" class="btn btn-sm btn-secondary" disabled title="Cannot deactivate the only active branch">
                                                                    <i class="mdi mdi-close-circle"></i> Deactivate
                                                                </button>
                                                            <?php else: ?>
                                                                <form method="post" style="display:inline;" 
                                                                      onsubmit="return confirm('Are you sure you want to deactivate this branch?');">
                                                                    <input type="hidden" name="action" value="update_status">
                                                                    <input type="hidden" name="id" value="<?php echo (int)$branch['id']; ?>">
                                                                    <input type="hidden" name="new_status" value="inactive">
                                                                    <button type="submit" class="btn btn-sm btn-warning" title="Deactivate Branch">
                                                                        <i class="mdi mdi-close-circle"></i> Deactivate
                                                                    </button>
                                                                </form>
                                                            <?php endif; ?>
                                                        <?php else: ?>
                                                            <form method="post" style="display:inline;" 
                                                                  onsubmit="return confirm('Are you sure you want to activate this branch?');">
                                                                <input type="hidden" name="action" value="update_status">
                                                                <input type="hidden" name="id" value="<?php echo (int)$branch['id']; ?>">
                                                                <input type="hidden" name="new_status" value="active">
                                                                <button type="submit" class="btn btn-sm btn-success" title="Activate Branch">
                                                                    <i class="mdi mdi-check-circle"></i> Activate
                                                                </button>
                                                            </form>
                                                        <?php endif; ?>
                                                    </div>
                                                 </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="9" class="text-center text-muted py-4">
                                                No branches found.
                                             </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Info note -->
                        <?php if ($activeBranches <= 1): ?>
                            <div class="alert alert-info mt-3 mb-0">
                                <i class="mdi mdi-information-outline me-2"></i>
                                <strong>Note:</strong> At least one branch must remain active. The last active branch cannot be deactivated.
                            </div>
                        <?php endif; ?>

                    </div>
                </div>

            </div>
        </div>

        <?php include('includes/footer.php'); ?>
    </div>
</div>

<?php include('includes/rightbar.php'); ?>
<?php include('includes/scripts.php'); ?>

<script>
    // Auto-hide alerts after 5 seconds
    setTimeout(function() {
        var alerts = document.querySelectorAll('.alert');
        alerts.forEach(function(alert) {
            var bsAlert = new bootstrap.Alert(alert);
            setTimeout(function() {
                bsAlert.close();
            }, 5000);
        });
    }, 100);
</script>

</body>
</html>