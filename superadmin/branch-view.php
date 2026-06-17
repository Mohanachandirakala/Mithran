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

$branchId = (int)($_GET['id'] ?? 0);
if ($branchId <= 0) {
    header("Location: branches.php");
    exit;
}

/* -------------------------------------------------------
   FETCH BRANCH
------------------------------------------------------- */
$branch = null;
$stmt = $conn->prepare("
    SELECT 
        br.id,
        br.business_id,
        br.branch_name,
        br.branch_code,
        br.contact_person,
        br.email,
        br.mobile,
        br.alternate_mobile,
        br.gstin,
        br.address_line1,
        br.address_line2,
        br.city,
        br.district,
        br.state,
        br.pincode,
        br.is_head_office,
        br.status,
        br.created_at,
        br.updated_at,
        b.business_name,
        b.business_code,
        b.owner_name
    FROM branches br
    INNER JOIN businesses b ON b.id = br.business_id
    WHERE br.id = ?
    LIMIT 1
");
if ($stmt) {
    $stmt->bind_param("i", $branchId);
    $stmt->execute();
    $result = $stmt->get_result();
    $branch = $result ? $result->fetch_assoc() : null;
    $stmt->close();
}

if (!$branch) {
    header("Location: branches.php");
    exit;
}

/* -------------------------------------------------------
   COUNTS
------------------------------------------------------- */
$totalUsers = 0;
$activeUsers = 0;
$totalVehicleStock = 0;
$totalProductStock = 0;
$totalSalesInvoices = 0;
$totalServiceInvoices = 0;
$totalPayments = 0.00;
$totalExpenses = 0.00;

$stmt = $conn->prepare("SELECT COUNT(*) AS total FROM business_users WHERE branch_id = ?");
if ($stmt) {
    $stmt->bind_param("i", $branchId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $totalUsers = (int)($row['total'] ?? 0);
    $stmt->close();
}

$stmt = $conn->prepare("SELECT COUNT(*) AS total FROM business_users WHERE branch_id = ? AND status = 1");
if ($stmt) {
    $stmt->bind_param("i", $branchId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $activeUsers = (int)($row['total'] ?? 0);
    $stmt->close();
}

$stmt = $conn->prepare("SELECT COUNT(*) AS total FROM vehicle_stock WHERE branch_id = ?");
if ($stmt) {
    $stmt->bind_param("i", $branchId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $totalVehicleStock = (int)($row['total'] ?? 0);
    $stmt->close();
}

$stmt = $conn->prepare("SELECT COUNT(*) AS total FROM product_stock WHERE branch_id = ?");
if ($stmt) {
    $stmt->bind_param("i", $branchId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $totalProductStock = (int)($row['total'] ?? 0);
    $stmt->close();
}

$stmt = $conn->prepare("SELECT COUNT(*) AS total FROM sales_invoices WHERE branch_id = ?");
if ($stmt) {
    $stmt->bind_param("i", $branchId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $totalSalesInvoices = (int)($row['total'] ?? 0);
    $stmt->close();
}

$stmt = $conn->prepare("SELECT COUNT(*) AS total FROM service_invoices WHERE branch_id = ?");
if ($stmt) {
    $stmt->bind_param("i", $branchId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $totalServiceInvoices = (int)($row['total'] ?? 0);
    $stmt->close();
}

$stmt = $conn->prepare("SELECT COALESCE(SUM(amount),0) AS total FROM payments WHERE branch_id = ?");
if ($stmt) {
    $stmt->bind_param("i", $branchId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $totalPayments = (float)($row['total'] ?? 0);
    $stmt->close();
}

$stmt = $conn->prepare("SELECT COALESCE(SUM(amount),0) AS total FROM expenses WHERE branch_id = ?");
if ($stmt) {
    $stmt->bind_param("i", $branchId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $totalExpenses = (float)($row['total'] ?? 0);
    $stmt->close();
}

/* -------------------------------------------------------
   RECENT USERS
------------------------------------------------------- */
$recentUsers = [];
$stmt = $conn->prepare("
    SELECT id, full_name, username, email, role, status, created_at
    FROM business_users
    WHERE branch_id = ?
    ORDER BY id DESC
    LIMIT 5
");
if ($stmt) {
    $stmt->bind_param("i", $branchId);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $recentUsers[] = $row;
        }
    }
    $stmt->close();
}

/* -------------------------------------------------------
   RECENT SALES
------------------------------------------------------- */
$recentSales = [];
$stmt = $conn->prepare("
    SELECT id, invoice_no, invoice_date, grand_total, payment_status, sale_status
    FROM sales_invoices
    WHERE branch_id = ?
    ORDER BY id DESC
    LIMIT 5
");
if ($stmt) {
    $stmt->bind_param("i", $branchId);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $recentSales[] = $row;
        }
    }
    $stmt->close();
}

$pageTitle = 'View Branch';
$currentPage = 'branches';

function money($amount)
{
    return '₹' . number_format((float)$amount, 2);
}
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
                                        <h3 class="mb-1">
                                            <?php echo h($branch['branch_name']); ?>
                                            <?php if ((int)$branch['is_head_office'] === 1): ?>
                                                <span class="badge bg-info ms-2">Head Office</span>
                                            <?php endif; ?>
                                        </h3>
                                        <p class="text-muted mb-1">Code: <?php echo h($branch['branch_code']); ?></p>
                                        <p class="text-muted mb-0">
                                            Business: <?php echo h($branch['business_name']); ?> (<?php echo h($branch['business_code']); ?>)
                                        </p>
                                    </div>
                                    <div class="mt-3 mt-md-0 d-flex gap-2">
                                        <a href="branch-edit.php?id=<?php echo (int)$branch['id']; ?>" class="btn btn-primary">Edit Branch</a>
                                        <a href="branches.php?business_id=<?php echo (int)$branch['business_id']; ?>" class="btn btn-info">All Branches</a>
                                        <a href="branches.php" class="btn btn-secondary">Back</a>
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
                                <p class="text-muted mb-1">Branch Users</p>
                                <h3 class="text-primary mt-2 mb-0"><?php echo number_format($totalUsers); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <p class="text-muted mb-1">Vehicle Stock</p>
                                <h3 class="text-success mt-2 mb-0"><?php echo number_format($totalVehicleStock); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <p class="text-muted mb-1">Sales Invoices</p>
                                <h3 class="text-info mt-2 mb-0"><?php echo number_format($totalSalesInvoices); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <p class="text-muted mb-1">Service Invoices</p>
                                <h3 class="text-danger mt-2 mb-0"><?php echo number_format($totalServiceInvoices); ?></h3>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
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
                                <p class="text-muted mb-1">Product Stock Items</p>
                                <h4 class="mb-0"><?php echo number_format($totalProductStock); ?></h4>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <p class="text-muted mb-1">Collections</p>
                                <h4 class="mb-0 text-success"><?php echo money($totalPayments); ?></h4>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <p class="text-muted mb-1">Expenses</p>
                                <h4 class="mb-0 text-danger"><?php echo money($totalExpenses); ?></h4>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Details -->
                <div class="row">
                    <div class="col-xl-6">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Branch Details</h4>
                                <div class="table-responsive">
                                    <table class="table table-bordered mb-0">
                                        <tbody>
                                            <tr>
                                                <th style="width: 220px;">Branch Name</th>
                                                <td><?php echo h($branch['branch_name']); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Branch Code</th>
                                                <td><?php echo h($branch['branch_code']); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Contact Person</th>
                                                <td><?php echo h($branch['contact_person'] ?: '-'); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Email</th>
                                                <td><?php echo h($branch['email'] ?: '-'); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Mobile</th>
                                                <td><?php echo h($branch['mobile'] ?: '-'); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Alternate Mobile</th>
                                                <td><?php echo h($branch['alternate_mobile'] ?: '-'); ?></td>
                                            </tr>
                                            <tr>
                                                <th>GSTIN</th>
                                                <td><?php echo h($branch['gstin'] ?: '-'); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Address</th>
                                                <td>
                                                    <?php
                                                    $address = trim(($branch['address_line1'] ?? '') . ' ' . ($branch['address_line2'] ?? ''));
                                                    echo h($address !== '' ? $address : '-');
                                                    ?>
                                                </td>
                                            </tr>
                                            <tr>
                                                <th>City / District / State</th>
                                                <td>
                                                    <?php
                                                    $location = trim(
                                                        ($branch['city'] ?? '') . ', ' .
                                                        ($branch['district'] ?? '') . ', ' .
                                                        ($branch['state'] ?? ''),
                                                        ', '
                                                    );
                                                    echo h($location !== '' ? $location : '-');
                                                    ?>
                                                </td>
                                            </tr>
                                            <tr>
                                                <th>Pincode</th>
                                                <td><?php echo h($branch['pincode'] ?: '-'); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Status</th>
                                                <td>
                                                    <span class="badge bg-<?php echo $branch['status'] === 'active' ? 'success' : 'warning'; ?>">
                                                        <?php echo h(ucfirst($branch['status'])); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                            <tr>
                                                <th>Head Office</th>
                                                <td><?php echo (int)$branch['is_head_office'] === 1 ? 'Yes' : 'No'; ?></td>
                                            </tr>
                                            <tr>
                                                <th>Created At</th>
                                                <td><?php echo h(date('d M Y h:i A', strtotime($branch['created_at']))); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Updated At</th>
                                                <td><?php echo h(date('d M Y h:i A', strtotime($branch['updated_at']))); ?></td>
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
                                <h4 class="card-title mb-4">Business Details</h4>
                                <div class="table-responsive">
                                    <table class="table table-bordered mb-0">
                                        <tbody>
                                            <tr>
                                                <th style="width: 220px;">Business Name</th>
                                                <td><?php echo h($branch['business_name']); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Business Code</th>
                                                <td><?php echo h($branch['business_code']); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Owner Name</th>
                                                <td><?php echo h($branch['owner_name'] ?: '-'); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Quick Links</th>
                                                <td>
                                                    <a href="business-view.php?id=<?php echo (int)$branch['business_id']; ?>" class="btn btn-sm btn-info me-1">View Business</a>
                                                    <a href="branches.php?business_id=<?php echo (int)$branch['business_id']; ?>" class="btn btn-sm btn-primary">All Branches</a>
                                                </td>
                                            </tr>
                                            <tr>
                                                <th>Branch Users</th>
                                                <td><?php echo number_format($totalUsers); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Vehicle Stock</th>
                                                <td><?php echo number_format($totalVehicleStock); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Product Stock</th>
                                                <td><?php echo number_format($totalProductStock); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Sales Invoices</th>
                                                <td><?php echo number_format($totalSalesInvoices); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Service Invoices</th>
                                                <td><?php echo number_format($totalServiceInvoices); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Total Collections</th>
                                                <td><?php echo money($totalPayments); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Total Expenses</th>
                                                <td><?php echo money($totalExpenses); ?></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recent Users and Sales -->
                <div class="row">
                    <div class="col-xl-6">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Recent Branch Users</h4>
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
                                                    <td colspan="4" class="text-center text-muted">No branch users found.</td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-6">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Recent Sales Invoices</h4>
                                <div class="table-responsive">
                                    <table class="table table-bordered table-hover mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Invoice No</th>
                                                <th>Date</th>
                                                <th>Amount</th>
                                                <th>Payment</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($recentSales)): ?>
                                                <?php foreach ($recentSales as $row): ?>
                                                    <tr>
                                                        <td><?php echo h($row['invoice_no']); ?></td>
                                                        <td><?php echo h(date('d M Y', strtotime($row['invoice_date']))); ?></td>
                                                        <td><?php echo money($row['grand_total']); ?></td>
                                                        <td>
                                                            <span class="badge bg-<?php echo $row['payment_status'] === 'paid' ? 'success' : ($row['payment_status'] === 'partial' ? 'warning' : 'danger'); ?>">
                                                                <?php echo h(ucfirst($row['payment_status'])); ?>
                                                            </span>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="4" class="text-center text-muted">No sales invoices found.</td>
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