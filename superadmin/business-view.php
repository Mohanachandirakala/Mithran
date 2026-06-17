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

$businessId = (int)($_GET['id'] ?? 0);
if ($businessId <= 0) {
    header("Location: businesses.php");
    exit;
}

/* -------------------------------------------------------
   FETCH BUSINESS
------------------------------------------------------- */
$business = null;
$stmt = $conn->prepare("
    SELECT 
        id,
        business_name,
        business_code,
        owner_name,
        email,
        mobile,
        alternate_mobile,
        gstin,
        pan_no,
        address_line1,
        address_line2,
        city,
        district,
        state,
        pincode,
        logo_path,
        status,
        notes,
        created_at,
        updated_at
    FROM businesses
    WHERE id = ?
    LIMIT 1
");
if ($stmt) {
    $stmt->bind_param("i", $businessId);
    $stmt->execute();
    $result = $stmt->get_result();
    $business = $result ? $result->fetch_assoc() : null;
    $stmt->close();
}

if (!$business) {
    header("Location: businesses.php");
    exit;
}

/* -------------------------------------------------------
   BUSINESS SETTINGS
------------------------------------------------------- */
$settings = null;
$stmt = $conn->prepare("
    SELECT 
        invoice_prefix,
        service_invoice_prefix,
        quotation_prefix,
        payment_receipt_prefix,
        timezone,
        currency_symbol,
        tax_type,
        show_logo_on_invoice,
        updated_at
    FROM business_settings
    WHERE business_id = ?
    LIMIT 1
");
if ($stmt) {
    $stmt->bind_param("i", $businessId);
    $stmt->execute();
    $result = $stmt->get_result();
    $settings = $result ? $result->fetch_assoc() : null;
    $stmt->close();
}

/* -------------------------------------------------------
   COUNTS
------------------------------------------------------- */
$totalBranches = 0;
$activeBranches = 0;
$totalUsers = 0;
$activeUsers = 0;
$totalCustomers = 0;
$totalSalesInvoices = 0;
$totalServiceInvoices = 0;

$stmt = $conn->prepare("SELECT COUNT(*) AS total FROM branches WHERE business_id = ?");
if ($stmt) {
    $stmt->bind_param("i", $businessId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $totalBranches = (int)($row['total'] ?? 0);
    $stmt->close();
}

$stmt = $conn->prepare("SELECT COUNT(*) AS total FROM branches WHERE business_id = ? AND status = 'active'");
if ($stmt) {
    $stmt->bind_param("i", $businessId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $activeBranches = (int)($row['total'] ?? 0);
    $stmt->close();
}

$stmt = $conn->prepare("SELECT COUNT(*) AS total FROM business_users WHERE business_id = ?");
if ($stmt) {
    $stmt->bind_param("i", $businessId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $totalUsers = (int)($row['total'] ?? 0);
    $stmt->close();
}

$stmt = $conn->prepare("SELECT COUNT(*) AS total FROM business_users WHERE business_id = ? AND status = 1");
if ($stmt) {
    $stmt->bind_param("i", $businessId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $activeUsers = (int)($row['total'] ?? 0);
    $stmt->close();
}

$stmt = $conn->prepare("SELECT COUNT(*) AS total FROM customers WHERE business_id = ?");
if ($stmt) {
    $stmt->bind_param("i", $businessId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $totalCustomers = (int)($row['total'] ?? 0);
    $stmt->close();
}

$stmt = $conn->prepare("SELECT COUNT(*) AS total FROM sales_invoices WHERE business_id = ?");
if ($stmt) {
    $stmt->bind_param("i", $businessId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $totalSalesInvoices = (int)($row['total'] ?? 0);
    $stmt->close();
}

$stmt = $conn->prepare("SELECT COUNT(*) AS total FROM service_invoices WHERE business_id = ?");
if ($stmt) {
    $stmt->bind_param("i", $businessId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $totalServiceInvoices = (int)($row['total'] ?? 0);
    $stmt->close();
}

/* -------------------------------------------------------
   RECENT BRANCHES
------------------------------------------------------- */
$recentBranches = [];
$stmt = $conn->prepare("
    SELECT id, branch_name, branch_code, city, state, status, is_head_office, created_at
    FROM branches
    WHERE business_id = ?
    ORDER BY id DESC
    LIMIT 5
");
if ($stmt) {
    $stmt->bind_param("i", $businessId);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $recentBranches[] = $row;
        }
    }
    $stmt->close();
}

/* -------------------------------------------------------
   RECENT USERS
------------------------------------------------------- */
$recentUsers = [];
$stmt = $conn->prepare("
    SELECT id, full_name, username, email, role, status, created_at
    FROM business_users
    WHERE business_id = ?
    ORDER BY id DESC
    LIMIT 5
");
if ($stmt) {
    $stmt->bind_param("i", $businessId);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $recentUsers[] = $row;
        }
    }
    $stmt->close();
}

$pageTitle = 'View Business';
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


                <!-- Top Card -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex flex-wrap align-items-center justify-content-between">
                                    <div>
                                        <h3 class="mb-1"><?php echo h($business['business_name']); ?></h3>
                                        <p class="text-muted mb-1">Code: <?php echo h($business['business_code']); ?></p>
                                        <span class="badge bg-<?php echo $business['status'] === 'active' ? 'success' : ($business['status'] === 'inactive' ? 'warning' : 'danger'); ?>">
                                            <?php echo h(ucfirst($business['status'])); ?>
                                        </span>
                                    </div>
                                    <div class="mt-3 mt-md-0 d-flex gap-2">
                                        <a href="business-edit.php?id=<?php echo (int)$business['id']; ?>" class="btn btn-primary">Edit Business</a>
                                        <a href="branch-add.php?business_id=<?php echo (int)$business['id']; ?>" class="btn btn-success">Add Branch</a>
                                        <a href="businesses.php" class="btn btn-secondary">Back</a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Stats -->
                <div class="row">
                    <div class="col-md-6 col-xl-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <p class="text-muted mb-1">Branches</p>
                                <h3 class="text-primary mt-2 mb-0"><?php echo number_format($totalBranches); ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <p class="text-muted mb-1">Business Users</p>
                                <h3 class="text-info mt-2 mb-0"><?php echo number_format($totalUsers); ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <p class="text-muted mb-1">Customers</p>
                                <h3 class="text-success mt-2 mb-0"><?php echo number_format($totalCustomers); ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <p class="text-muted mb-1">Sales Invoices</p>
                                <h3 class="text-danger mt-2 mb-0"><?php echo number_format($totalSalesInvoices); ?></h3>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6 col-xl-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <p class="text-muted mb-1">Active Branches</p>
                                <h4 class="mb-0"><?php echo number_format($activeBranches); ?></h4>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <p class="text-muted mb-1">Active Users</p>
                                <h4 class="mb-0"><?php echo number_format($activeUsers); ?></h4>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <p class="text-muted mb-1">Service Invoices</p>
                                <h4 class="mb-0"><?php echo number_format($totalServiceInvoices); ?></h4>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Details -->
                <div class="row">
                    <div class="col-xl-6">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Business Details</h4>
                                <div class="table-responsive">
                                    <table class="table table-bordered mb-0">
                                        <tbody>
                                            <tr>
                                                <th style="width: 220px;">Business Name</th>
                                                <td><?php echo h($business['business_name']); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Business Code</th>
                                                <td><?php echo h($business['business_code']); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Owner Name</th>
                                                <td><?php echo h($business['owner_name'] ?: '-'); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Email</th>
                                                <td><?php echo h($business['email'] ?: '-'); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Mobile</th>
                                                <td><?php echo h($business['mobile'] ?: '-'); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Alternate Mobile</th>
                                                <td><?php echo h($business['alternate_mobile'] ?: '-'); ?></td>
                                            </tr>
                                            <tr>
                                                <th>GSTIN</th>
                                                <td><?php echo h($business['gstin'] ?: '-'); ?></td>
                                            </tr>
                                            <tr>
                                                <th>PAN No</th>
                                                <td><?php echo h($business['pan_no'] ?: '-'); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Address</th>
                                                <td>
                                                    <?php
                                                    $address = trim(
                                                        ($business['address_line1'] ?? '') . ' ' .
                                                        ($business['address_line2'] ?? '')
                                                    );
                                                    echo h($address !== '' ? $address : '-');
                                                    ?>
                                                </td>
                                            </tr>
                                            <tr>
                                                <th>City / District / State</th>
                                                <td>
                                                    <?php
                                                    $location = trim(
                                                        ($business['city'] ?? '') . ', ' .
                                                        ($business['district'] ?? '') . ', ' .
                                                        ($business['state'] ?? ''),
                                                        ', '
                                                    );
                                                    echo h($location !== '' ? $location : '-');
                                                    ?>
                                                </td>
                                            </tr>
                                            <tr>
                                                <th>Pincode</th>
                                                <td><?php echo h($business['pincode'] ?: '-'); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Status</th>
                                                <td><?php echo h(ucfirst($business['status'])); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Created At</th>
                                                <td><?php echo h(date('d M Y h:i A', strtotime($business['created_at']))); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Updated At</th>
                                                <td><?php echo h(date('d M Y h:i A', strtotime($business['updated_at']))); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Notes</th>
                                                <td><?php echo nl2br(h($business['notes'] ?: '-')); ?></td>
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
                                <h4 class="card-title mb-4">Business Settings</h4>
                                <div class="table-responsive">
                                    <table class="table table-bordered mb-0">
                                        <tbody>
                                            <tr>
                                                <th style="width: 220px;">Invoice Prefix</th>
                                                <td><?php echo h($settings['invoice_prefix'] ?? '-'); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Service Invoice Prefix</th>
                                                <td><?php echo h($settings['service_invoice_prefix'] ?? '-'); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Quotation Prefix</th>
                                                <td><?php echo h($settings['quotation_prefix'] ?? '-'); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Payment Receipt Prefix</th>
                                                <td><?php echo h($settings['payment_receipt_prefix'] ?? '-'); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Timezone</th>
                                                <td><?php echo h($settings['timezone'] ?? '-'); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Currency Symbol</th>
                                                <td><?php echo h($settings['currency_symbol'] ?? '-'); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Tax Type</th>
                                                <td><?php echo h(isset($settings['tax_type']) ? ucfirst($settings['tax_type']) : '-'); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Show Logo on Invoice</th>
                                                <td><?php echo isset($settings['show_logo_on_invoice']) ? ((int)$settings['show_logo_on_invoice'] === 1 ? 'Yes' : 'No') : '-'; ?></td>
                                            </tr>
                                            <tr>
                                                <th>Settings Updated</th>
                                                <td>
                                                    <?php
                                                    echo !empty($settings['updated_at'])
                                                        ? h(date('d M Y h:i A', strtotime($settings['updated_at'])))
                                                        : '-';
                                                    ?>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recent Branches -->
                <div class="row">
                    <div class="col-xl-6">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center mb-4">
                                    <h4 class="card-title mb-0">Recent Branches</h4>
                                    <a href="branches.php?business_id=<?php echo (int)$business['id']; ?>" class="btn btn-sm btn-primary">View All</a>
                                </div>

                                <div class="table-responsive">
                                    <table class="table table-bordered table-hover mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Branch</th>
                                                <th>Code</th>
                                                <th>Location</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($recentBranches)): ?>
                                                <?php foreach ($recentBranches as $row): ?>
                                                    <tr>
                                                        <td>
                                                            <?php echo h($row['branch_name']); ?>
                                                            <?php if ((int)$row['is_head_office'] === 1): ?>
                                                                <span class="badge bg-info ms-1">HO</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td><?php echo h($row['branch_code']); ?></td>
                                                        <td>
                                                            <?php
                                                            $loc = trim(($row['city'] ?? '') . ', ' . ($row['state'] ?? ''), ', ');
                                                            echo h($loc !== '' ? $loc : '-');
                                                            ?>
                                                        </td>
                                                        <td>
                                                            <span class="badge bg-<?php echo $row['status'] === 'active' ? 'success' : 'warning'; ?>">
                                                                <?php echo h(ucfirst($row['status'])); ?>
                                                            </span>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="4" class="text-center text-muted">No branches found.</td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>

                            </div>
                        </div>
                    </div>

                    <!-- Recent Users -->
                    <div class="col-xl-6">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center mb-4">
                                    <h4 class="card-title mb-0">Recent Users</h4>
                                    <a href="business-users.php?business_id=<?php echo (int)$business['id']; ?>" class="btn btn-sm btn-primary">View All</a>
                                </div>

                                <div class="table-responsive">
                                    <table class="table table-bordered table-hover mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Name</th>
                                                <th>Username</th>
                                                <th>Role</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($recentUsers)): ?>
                                                <?php foreach ($recentUsers as $row): ?>
                                                    <tr>
                                                        <td><?php echo h($row['full_name']); ?></td>
                                                        <td><?php echo h($row['username']); ?></td>
                                                        <td><?php echo h(ucwords(str_replace('_', ' ', $row['role']))); ?></td>
                                                        <td>
                                                            <span class="badge bg-<?php echo (int)$row['status'] === 1 ? 'success' : 'danger'; ?>">
                                                                <?php echo (int)$row['status'] === 1 ? 'Active' : 'Inactive'; ?>
                                                            </span>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="4" class="text-center text-muted">No users found.</td>
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