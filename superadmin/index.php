<?php
session_start();
date_default_timezone_set('Asia/Kolkata');

require_once 'includes/config.php';

/*
|--------------------------------------------------------------------------
| Super Admin Dashboard - index.php
| Uses includes/config.php
|--------------------------------------------------------------------------
| Expected:
| - $conn should be available as mysqli connection from includes/config.php
| - Super admin session can be stored in:
|   $_SESSION['platform_admin_id'] or $_SESSION['super_admin_id']
|--------------------------------------------------------------------------
*/

if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not available.");
}
$conn->set_charset("utf8mb4");

/* -------------------------------------------------------
   AUTH CHECK
------------------------------------------------------- */
$platformAdminId = 0;

if (isset($_SESSION['platform_admin_id'])) {
    $platformAdminId = (int) $_SESSION['platform_admin_id'];
} elseif (isset($_SESSION['super_admin_id'])) {
    $platformAdminId = (int) $_SESSION['super_admin_id'];
}

if ($platformAdminId <= 0) {
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

function money($amount): string
{
    return '₹' . number_format((float)$amount, 2);
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

function getDecimal(mysqli $conn, string $table, string $field, string $where = '1=1'): float
{
    $sql = "SELECT COALESCE(SUM({$field}),0) AS total FROM {$table} WHERE {$where}";
    $res = $conn->query($sql);
    if (!$res) return 0;
    $row = $res->fetch_assoc();
    return (float)($row['total'] ?? 0);
}

/* -------------------------------------------------------
   FETCH PLATFORM ADMIN
------------------------------------------------------- */
$admin = null;
if (tableExists($conn, 'platform_admins')) {
    $stmt = $conn->prepare("SELECT id, full_name, username, email, role FROM platform_admins WHERE id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("i", $platformAdminId);
        $stmt->execute();
        $admin = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}

if (!$admin) {
    session_destroy();
    header("Location: login.php");
    exit;
}

/* -------------------------------------------------------
   DASHBOARD COUNTS
------------------------------------------------------- */
$totalBusinesses       = tableExists($conn, 'businesses') ? getCount($conn, 'businesses') : 0;
$activeBusinesses      = tableExists($conn, 'businesses') ? getCount($conn, 'businesses', "status='active'") : 0;
$suspendedBusinesses   = tableExists($conn, 'businesses') ? getCount($conn, 'businesses', "status='suspended'") : 0;

$totalBranches         = tableExists($conn, 'branches') ? getCount($conn, 'branches') : 0;
$activeBranches        = tableExists($conn, 'branches') ? getCount($conn, 'branches', "status='active'") : 0;

$totalBusinessUsers    = tableExists($conn, 'business_users') ? getCount($conn, 'business_users') : 0;
$activeBusinessUsers   = tableExists($conn, 'business_users') ? getCount($conn, 'business_users', "status=1") : 0;

$totalPlans            = tableExists($conn, 'subscription_plans') ? getCount($conn, 'subscription_plans') : 0;
$activeSubscriptions   = tableExists($conn, 'business_subscriptions') ? getCount($conn, 'business_subscriptions', "subscription_status='active'") : 0;
$expiredSubscriptions  = tableExists($conn, 'business_subscriptions') ? getCount($conn, 'business_subscriptions', "subscription_status='expired'") : 0;

$totalCustomers        = tableExists($conn, 'customers') ? getCount($conn, 'customers') : 0;
$totalSalesInvoices    = tableExists($conn, 'sales_invoices') ? getCount($conn, 'sales_invoices') : 0;
$totalServiceInvoices  = tableExists($conn, 'service_invoices') ? getCount($conn, 'service_invoices') : 0;
$totalPayments         = tableExists($conn, 'payments') ? getDecimal($conn, 'payments', 'amount') : 0;
$totalExpenses         = tableExists($conn, 'expenses') ? getDecimal($conn, 'expenses', 'amount') : 0;

/* -------------------------------------------------------
   MONTHLY / TODAY STATS
------------------------------------------------------- */
$today = date('Y-m-d');
$currentMonthStart = date('Y-m-01');
$currentMonthEnd   = date('Y-m-t');

$monthSalesCount = tableExists($conn, 'sales_invoices')
    ? getCount($conn, 'sales_invoices', "DATE(invoice_date) BETWEEN '{$currentMonthStart}' AND '{$currentMonthEnd}'")
    : 0;

$monthServiceCount = tableExists($conn, 'service_invoices')
    ? getCount($conn, 'service_invoices', "DATE(invoice_date) BETWEEN '{$currentMonthStart}' AND '{$currentMonthEnd}'")
    : 0;

$todaySalesCount = tableExists($conn, 'sales_invoices')
    ? getCount($conn, 'sales_invoices', "DATE(invoice_date) = '{$today}'")
    : 0;

$todayServiceCount = tableExists($conn, 'service_invoices')
    ? getCount($conn, 'service_invoices', "DATE(invoice_date) = '{$today}'")
    : 0;

$monthCollections = tableExists($conn, 'payments')
    ? getDecimal($conn, 'payments', 'amount', "DATE(payment_date) BETWEEN '{$currentMonthStart}' AND '{$currentMonthEnd}'")
    : 0;

/* -------------------------------------------------------
   LATEST BUSINESSES
------------------------------------------------------- */
$latestBusinesses = [];
if (tableExists($conn, 'businesses')) {
    $sql = "SELECT id, business_name, owner_name, mobile, status, created_at
            FROM businesses
            ORDER BY id DESC
            LIMIT 5";
    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $latestBusinesses[] = $row;
        }
    }
}

/* -------------------------------------------------------
   RECENT PLATFORM ACTIVITIES
------------------------------------------------------- */
$recentActivities = [];
if (tableExists($conn, 'platform_activity_logs')) {
    $sql = "SELECT action, module_name, description, created_at
            FROM platform_activity_logs
            ORDER BY id DESC
            LIMIT 8";
    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $recentActivities[] = $row;
        }
    }
}

/* -------------------------------------------------------
   PAGE VARIABLES
------------------------------------------------------- */
$pageTitle = 'Platform Dashboard';
$currentPage = 'dashboard';
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

                <!-- Welcome -->
                <div class="row">
                    <div class="col-12">
                        <div class="card bg-primary text-white">
                            <div class="card-body">
                                <div class="d-flex flex-wrap align-items-center justify-content-between">
                                    <div>
                                        <h4 class="text-white mb-1">Welcome, <?php echo h($admin['full_name'] ?? 'Admin'); ?></h4>
                                        <p class="mb-0 opacity-75">
                                            Role: <?php echo h(ucwords(str_replace('_', ' ', $admin['role'] ?? 'super_admin'))); ?>
                                        </p>
                                    </div>
                                    <div class="text-end">
                                        <div class="small">Today</div>
                                        <div class="fw-bold"><?php echo date('d M Y, h:i A'); ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Main Stats -->
                <div class="row">
                    <div class="col-md-6 col-xl-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <p class="text-muted mb-1">Total Businesses</p>
                                <h3 class="text-info mt-2 mb-0"><?php echo number_format($totalBusinesses); ?></h3>
                            </div>
                        </div>
                    </div>

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
                                <p class="text-muted mb-1">Business Users</p>
                                <h3 class="text-success mt-2 mb-0"><?php echo number_format($totalBusinessUsers); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <p class="text-muted mb-1">Active Subscriptions</p>
                                <h3 class="text-danger mt-2 mb-0"><?php echo number_format($activeSubscriptions); ?></h3>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Secondary Stats -->
                <div class="row">
                    <div class="col-md-6 col-xl-3">
                        <div class="card">
                            <div class="card-body">
                                <p class="text-muted mb-1">Active Businesses</p>
                                <h4 class="mb-0"><?php echo number_format($activeBusinesses); ?></h4>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="card">
                            <div class="card-body">
                                <p class="text-muted mb-1">Suspended Businesses</p>
                                <h4 class="mb-0"><?php echo number_format($suspendedBusinesses); ?></h4>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="card">
                            <div class="card-body">
                                <p class="text-muted mb-1">Active Branches</p>
                                <h4 class="mb-0"><?php echo number_format($activeBranches); ?></h4>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="card">
                            <div class="card-body">
                                <p class="text-muted mb-1">Active Users</p>
                                <h4 class="mb-0"><?php echo number_format($activeBusinessUsers); ?></h4>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Business Snapshot -->
                <div class="row">
                    <div class="col-xl-4">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Subscription Summary</h4>

                                <div class="row text-center mt-4">
                                    <div class="col-6">
                                        <h5 class="mb-2 font-size-18"><?php echo number_format($totalPlans); ?></h5>
                                        <p class="text-muted text-truncate">Total Plans</p>
                                    </div>
                                    <div class="col-6">
                                        <h5 class="mb-2 font-size-18"><?php echo number_format($expiredSubscriptions); ?></h5>
                                        <p class="text-muted text-truncate">Expired Plans</p>
                                    </div>
                                </div>

                                <div class="mt-4">
                                    <div class="mb-3">
                                        <span class="text-muted">Total Customers Across Businesses</span>
                                        <h5 class="mt-1 mb-0"><?php echo number_format($totalCustomers); ?></h5>
                                    </div>
                                    <div class="mb-3">
                                        <span class="text-muted">Sales Invoices</span>
                                        <h5 class="mt-1 mb-0"><?php echo number_format($totalSalesInvoices); ?></h5>
                                    </div>
                                    <div class="mb-0">
                                        <span class="text-muted">Service Invoices</span>
                                        <h5 class="mt-1 mb-0"><?php echo number_format($totalServiceInvoices); ?></h5>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-8">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Current Month Overview</h4>

                                <div class="row text-center mt-4">
                                    <div class="col-4">
                                        <h5 class="mb-2 font-size-18"><?php echo number_format($monthSalesCount); ?></h5>
                                        <p class="text-muted text-truncate">Sales Invoices</p>
                                    </div>
                                    <div class="col-4">
                                        <h5 class="mb-2 font-size-18"><?php echo number_format($monthServiceCount); ?></h5>
                                        <p class="text-muted text-truncate">Service Invoices</p>
                                    </div>
                                    <div class="col-4">
                                        <h5 class="mb-2 font-size-18"><?php echo money($monthCollections); ?></h5>
                                        <p class="text-muted text-truncate">Collections</p>
                                    </div>
                                </div>

                                <div class="row mt-4">
                                    <div class="col-md-6">
                                        <div class="border rounded p-3">
                                            <p class="text-muted mb-1">Today Sales Invoices</p>
                                            <h4 class="mb-0"><?php echo number_format($todaySalesCount); ?></h4>
                                        </div>
                                    </div>
                                    <div class="col-md-6 mt-3 mt-md-0">
                                        <div class="border rounded p-3">
                                            <p class="text-muted mb-1">Today Service Invoices</p>
                                            <h4 class="mb-0"><?php echo number_format($todayServiceCount); ?></h4>
                                        </div>
                                    </div>
                                </div>

                                <div class="row mt-3">
                                    <div class="col-md-6">
                                        <div class="border rounded p-3">
                                            <p class="text-muted mb-1">Total Payments</p>
                                            <h4 class="mb-0 text-success"><?php echo money($totalPayments); ?></h4>
                                        </div>
                                    </div>
                                    <div class="col-md-6 mt-3 mt-md-0">
                                        <div class="border rounded p-3">
                                            <p class="text-muted mb-1">Total Expenses</p>
                                            <h4 class="mb-0 text-danger"><?php echo money($totalExpenses); ?></h4>
                                        </div>
                                    </div>
                                </div>

                            </div>
                        </div>
                    </div>
                </div>

                <!-- Latest Businesses + Activity -->
                <div class="row">
                    <div class="col-xl-8">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex align-items-center justify-content-between mb-4">
                                    <h4 class="card-title mb-0">Latest Businesses</h4>
                                    <a href="businesses.php" class="btn btn-primary btn-sm">View All</a>
                                </div>

                                <div class="table-responsive">
                                    <table class="table table-centered table-nowrap mb-0">
                                        <thead>
                                            <tr>
                                                <th>#</th>
                                                <th>Business</th>
                                                <th>Owner</th>
                                                <th>Mobile</th>
                                                <th>Status</th>
                                                <th>Created</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($latestBusinesses)): ?>
                                                <?php $sno = 1; foreach ($latestBusinesses as $biz): ?>
                                                    <tr>
                                                        <td><?php echo $sno++; ?></td>
                                                        <td><?php echo h($biz['business_name']); ?></td>
                                                        <td><?php echo h($biz['owner_name'] ?: '-'); ?></td>
                                                        <td><?php echo h($biz['mobile'] ?: '-'); ?></td>
                                                        <td>
                                                            <?php
                                                            $status = strtolower((string)($biz['status'] ?? 'inactive'));
                                                            $badge = 'secondary';
                                                            if ($status === 'active') $badge = 'success';
                                                            elseif ($status === 'inactive') $badge = 'warning';
                                                            elseif ($status === 'suspended') $badge = 'danger';
                                                            ?>
                                                            <span class="badge bg-<?php echo $badge; ?>">
                                                                <?php echo h(ucfirst($status)); ?>
                                                            </span>
                                                        </td>
                                                        <td><?php echo h(date('d M Y', strtotime($biz['created_at']))); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="6" class="text-center text-muted">No businesses found.</td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>

                            </div>
                        </div>
                    </div>

                    <div class="col-xl-4">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-3">Recent Platform Activity</h4>

                                <ol class="activity-feed mb-0">
                                    <?php if (!empty($recentActivities)): ?>
                                        <?php foreach ($recentActivities as $activity): ?>
                                            <li class="feed-item">
                                                <span class="date">
                                                    <?php echo h(date('d M', strtotime($activity['created_at']))); ?>
                                                </span>
                                                <span class="activity-text">
                                                    <strong><?php echo h($activity['action']); ?></strong>
                                                    <?php if (!empty($activity['module_name'])): ?>
                                                        in <?php echo h($activity['module_name']); ?>
                                                    <?php endif; ?>
                                                    <?php if (!empty($activity['description'])): ?>
                                                        <br><small class="text-muted"><?php echo h($activity['description']); ?></small>
                                                    <?php endif; ?>
                                                </span>
                                            </li>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <li class="feed-item">
                                            <span class="date"><?php echo date('d M'); ?></span>
                                            <span class="activity-text">No recent activity available.</span>
                                        </li>
                                    <?php endif; ?>
                                </ol>
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