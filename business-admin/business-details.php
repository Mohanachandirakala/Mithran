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
$branchId   = isset($_SESSION['branch_id']) ? (int)$_SESSION['branch_id'] : 0;

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
    $ok = $res && $res->num_rows > 0;
    $stmt->close();

    return $ok;
}

function getCount(mysqli $conn, string $table, string $where = '1=1'): int
{
    $sql = "SELECT COUNT(*) AS total FROM {$table} WHERE {$where}";
    $res = $conn->query($sql);
    if (!$res) return 0;
    $row = $res->fetch_assoc();
    return (int)($row['total'] ?? 0);
}

function statusBadge(string $status): string
{
    $status = strtolower(trim($status));
    if ($status === 'active') return 'success';
    if ($status === 'inactive') return 'warning';
    if ($status === 'suspended') return 'danger';
    return 'secondary';
}

/* -------------------------------------------------------
   FETCH BUSINESS
------------------------------------------------------- */
$business = null;
$user = null;
$branch = null;
$settings = null;

if (tableExists($conn, 'businesses')) {
    $stmt = $conn->prepare("SELECT *
                            FROM businesses
                            WHERE id = ?
                            LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $businessId);
        $stmt->execute();
        $business = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}

if (tableExists($conn, 'business_users')) {
    $stmt = $conn->prepare("SELECT id, full_name, username, email, mobile, role, status, last_login_at, created_at
                            FROM business_users
                            WHERE id = ? AND business_id = ?
                            LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('ii', $businessUserId, $businessId);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}

if ($branchId > 0 && tableExists($conn, 'branches')) {
    $stmt = $conn->prepare("SELECT *
                            FROM branches
                            WHERE id = ? AND business_id = ?
                            LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('ii', $branchId, $businessId);
        $stmt->execute();
        $branch = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}

if (tableExists($conn, 'business_settings')) {
    $stmt = $conn->prepare("SELECT *
                            FROM business_settings
                            WHERE business_id = ?
                            LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $businessId);
        $stmt->execute();
        $settings = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}

if (!$business || !$user) {
    session_destroy();
    header('Location: login.php');
    exit;
}

/* -------------------------------------------------------
   SUMMARY COUNTS
------------------------------------------------------- */
$totalBranches = tableExists($conn, 'branches')
    ? getCount($conn, 'branches', "business_id = {$businessId}")
    : 0;

$totalUsers = tableExists($conn, 'business_users')
    ? getCount($conn, 'business_users', "business_id = {$businessId}")
    : 0;

$totalCustomers = tableExists($conn, 'customers')
    ? getCount($conn, 'customers', "business_id = {$businessId}")
    : 0;

$totalProducts = tableExists($conn, 'products')
    ? getCount($conn, 'products', "business_id = {$businessId}")
    : 0;

$pageTitle = 'Business Details';
$currentPage = 'business-details';
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
                    <div class="col-12 d-flex justify-content-between align-items-center">
                        <div>
                            <h4 class="mb-1">Business Details</h4>
                            <p class="text-muted mb-0">View your business information and settings</p>
                        </div>
                        <a href="business-profile.php" class="btn btn-primary">Edit Business Profile</a>
                    </div>
                </div>

                <!-- top cards -->
                <div class="row">
                    <div class="col-md-6 col-xl-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Branches</p>
                                <h3 class="mb-0"><?php echo number_format($totalBranches); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Users</p>
                                <h3 class="mb-0"><?php echo number_format($totalUsers); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Customers</p>
                                <h3 class="mb-0"><?php echo number_format($totalCustomers); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Products</p>
                                <h3 class="mb-0"><?php echo number_format($totalProducts); ?></h3>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <!-- left -->
                    <div class="col-xl-4">
                        <div class="card">
                            <div class="card-body text-center">
                                <div class="mb-3">
                                    <?php if (!empty($business['logo_path'])): ?>
                                        <img src="<?php echo h($business['logo_path']); ?>" alt="Business Logo" style="max-width:90px; max-height:90px;">
                                    <?php else: ?>
                                        <div style="width:90px;height:90px;line-height:90px;border-radius:50%;background:#e9ecef;margin:0 auto;font-size:28px;font-weight:700;">
                                            <?php echo h(strtoupper(substr($business['business_name'] ?? 'B', 0, 1))); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <h4 class="mb-1"><?php echo h($business['business_name'] ?? '-'); ?></h4>
                                <p class="text-muted mb-2"><?php echo h($business['business_code'] ?? '-'); ?></p>

                                <span class="badge bg-<?php echo statusBadge($business['status'] ?? 'inactive'); ?>">
                                    <?php echo h(ucfirst($business['status'] ?? 'inactive')); ?>
                                </span>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-3">Logged In User</h4>
                                <table class="table table-borderless mb-0">
                                    <tr>
                                        <th>Name</th>
                                        <td><?php echo h($user['full_name'] ?? '-'); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Username</th>
                                        <td><?php echo h($user['username'] ?? '-'); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Email</th>
                                        <td><?php echo h($user['email'] ?? '-'); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Mobile</th>
                                        <td><?php echo h($user['mobile'] ?? '-'); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Role</th>
                                        <td><?php echo h(ucwords(str_replace('_', ' ', $user['role'] ?? '-'))); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Status</th>
                                        <td><?php echo ((int)($user['status'] ?? 0) === 1) ? 'Active' : 'Inactive'; ?></td>
                                    </tr>
                                    <tr>
                                        <th>Last Login</th>
                                        <td>
                                            <?php echo !empty($user['last_login_at']) ? h(date('d M Y h:i A', strtotime($user['last_login_at']))) : '-'; ?>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        </div>

                        <?php if ($branch): ?>
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-3">Current Branch</h4>
                                <table class="table table-borderless mb-0">
                                    <tr>
                                        <th>Branch Name</th>
                                        <td><?php echo h($branch['branch_name'] ?? '-'); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Branch Code</th>
                                        <td><?php echo h($branch['branch_code'] ?? '-'); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Contact Person</th>
                                        <td><?php echo h($branch['contact_person'] ?? '-'); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Mobile</th>
                                        <td><?php echo h($branch['mobile'] ?? '-'); ?></td>
                                    </tr>
                                    <tr>
                                        <th>City</th>
                                        <td><?php echo h($branch['city'] ?? '-'); ?></td>
                                    </tr>
                                    <tr>
                                        <th>District</th>
                                        <td><?php echo h($branch['district'] ?? '-'); ?></td>
                                    </tr>
                                    <tr>
                                        <th>State</th>
                                        <td><?php echo h($branch['state'] ?? '-'); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Status</th>
                                        <td><?php echo h(ucfirst($branch['status'] ?? '-')); ?></td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- right -->
                    <div class="col-xl-8">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Business Information</h4>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="text-muted d-block mb-1">Business Name</label>
                                        <div class="fw-bold"><?php echo h($business['business_name'] ?? '-'); ?></div>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="text-muted d-block mb-1">Business Code</label>
                                        <div class="fw-bold"><?php echo h($business['business_code'] ?? '-'); ?></div>
                                    </div>

                                    <div class="col-md-6 mb-3">
                                        <label class="text-muted d-block mb-1">Owner Name</label>
                                        <div class="fw-bold"><?php echo h($business['owner_name'] ?? '-'); ?></div>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="text-muted d-block mb-1">Email</label>
                                        <div class="fw-bold"><?php echo h($business['email'] ?? '-'); ?></div>
                                    </div>

                                    <div class="col-md-6 mb-3">
                                        <label class="text-muted d-block mb-1">Mobile</label>
                                        <div class="fw-bold"><?php echo h($business['mobile'] ?? '-'); ?></div>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="text-muted d-block mb-1">Alternate Mobile</label>
                                        <div class="fw-bold"><?php echo h($business['alternate_mobile'] ?? '-'); ?></div>
                                    </div>

                                    <div class="col-md-6 mb-3">
                                        <label class="text-muted d-block mb-1">GSTIN</label>
                                        <div class="fw-bold"><?php echo h($business['gstin'] ?? '-'); ?></div>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="text-muted d-block mb-1">PAN No</label>
                                        <div class="fw-bold"><?php echo h($business['pan_no'] ?? '-'); ?></div>
                                    </div>

                                    <div class="col-md-12 mb-3">
                                        <label class="text-muted d-block mb-1">Address Line 1</label>
                                        <div class="fw-bold"><?php echo h($business['address_line1'] ?? '-'); ?></div>
                                    </div>

                                    <div class="col-md-12 mb-3">
                                        <label class="text-muted d-block mb-1">Address Line 2</label>
                                        <div class="fw-bold"><?php echo h($business['address_line2'] ?? '-'); ?></div>
                                    </div>

                                    <div class="col-md-4 mb-3">
                                        <label class="text-muted d-block mb-1">City</label>
                                        <div class="fw-bold"><?php echo h($business['city'] ?? '-'); ?></div>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="text-muted d-block mb-1">District</label>
                                        <div class="fw-bold"><?php echo h($business['district'] ?? '-'); ?></div>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="text-muted d-block mb-1">State</label>
                                        <div class="fw-bold"><?php echo h($business['state'] ?? '-'); ?></div>
                                    </div>

                                    <div class="col-md-4 mb-3">
                                        <label class="text-muted d-block mb-1">Pincode</label>
                                        <div class="fw-bold"><?php echo h($business['pincode'] ?? '-'); ?></div>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="text-muted d-block mb-1">Status</label>
                                        <div class="fw-bold"><?php echo h(ucfirst($business['status'] ?? '-')); ?></div>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="text-muted d-block mb-1">Created At</label>
                                        <div class="fw-bold">
                                            <?php echo !empty($business['created_at']) ? h(date('d M Y h:i A', strtotime($business['created_at']))) : '-'; ?>
                                        </div>
                                    </div>

                                    <div class="col-md-12 mb-3">
                                        <label class="text-muted d-block mb-1">Notes</label>
                                        <div class="fw-bold"><?php echo h($business['notes'] ?? '-'); ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <?php if ($settings): ?>
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Business Settings</h4>
                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <label class="text-muted d-block mb-1">Invoice Prefix</label>
                                        <div class="fw-bold"><?php echo h($settings['invoice_prefix'] ?? '-'); ?></div>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="text-muted d-block mb-1">Service Invoice Prefix</label>
                                        <div class="fw-bold"><?php echo h($settings['service_invoice_prefix'] ?? '-'); ?></div>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="text-muted d-block mb-1">Quotation Prefix</label>
                                        <div class="fw-bold"><?php echo h($settings['quotation_prefix'] ?? '-'); ?></div>
                                    </div>

                                    <div class="col-md-4 mb-3">
                                        <label class="text-muted d-block mb-1">Receipt Prefix</label>
                                        <div class="fw-bold"><?php echo h($settings['payment_receipt_prefix'] ?? '-'); ?></div>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="text-muted d-block mb-1">Timezone</label>
                                        <div class="fw-bold"><?php echo h($settings['timezone'] ?? '-'); ?></div>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="text-muted d-block mb-1">Currency Symbol</label>
                                        <div class="fw-bold"><?php echo h($settings['currency_symbol'] ?? '-'); ?></div>
                                    </div>

                                    <div class="col-md-4 mb-3">
                                        <label class="text-muted d-block mb-1">Tax Type</label>
                                        <div class="fw-bold"><?php echo h(ucfirst($settings['tax_type'] ?? '-')); ?></div>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="text-muted d-block mb-1">Show Logo on Invoice</label>
                                        <div class="fw-bold"><?php echo ((int)($settings['show_logo_on_invoice'] ?? 0) === 1) ? 'Yes' : 'No'; ?></div>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="text-muted d-block mb-1">Updated At</label>
                                        <div class="fw-bold">
                                            <?php echo !empty($settings['updated_at']) ? h(date('d M Y h:i A', strtotime($settings['updated_at']))) : '-'; ?>
                                        </div>
                                    </div>

                                    <div class="col-md-12 mb-3">
                                        <label class="text-muted d-block mb-1">Invoice Terms</label>
                                        <div class="fw-bold"><?php echo nl2br(h($settings['invoice_terms'] ?? '-')); ?></div>
                                    </div>

                                    <div class="col-md-12 mb-0">
                                        <label class="text-muted d-block mb-1">Service Terms</label>
                                        <div class="fw-bold"><?php echo nl2br(h($settings['service_terms'] ?? '-')); ?></div>
                                    </div>
                                </div>
                            </div>
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

</body>
</html>