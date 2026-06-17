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

function money($amount)
{
    return '₹' . number_format((float)$amount, 2);
}

$success = '';
$error   = '';

/* -------------------------------------------------------
   DELETE PLAN
------------------------------------------------------- */
if (isset($_GET['delete']) && $_GET['delete'] !== '') {
    $deleteId = (int)$_GET['delete'];

    if ($deleteId > 0) {
        $check = $conn->prepare("SELECT COUNT(*) AS total FROM business_subscriptions WHERE plan_id = ?");
        if ($check) {
            $check->bind_param("i", $deleteId);
            $check->execute();
            $res = $check->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            $usedCount = (int)($row['total'] ?? 0);
            $check->close();

            if ($usedCount > 0) {
                $error = 'This subscription plan is already used by businesses, so it cannot be deleted.';
            } else {
                $stmt = $conn->prepare("DELETE FROM subscription_plans WHERE id = ? LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param("i", $deleteId);
                    if ($stmt->execute()) {
                        $success = 'Subscription plan deleted successfully.';
                    } else {
                        $error = 'Failed to delete subscription plan.';
                    }
                    $stmt->close();
                } else {
                    $error = 'Database error while deleting plan.';
                }
            }
        } else {
            $error = 'Database error while checking plan usage.';
        }
    }
}

/* -------------------------------------------------------
   FILTERS
------------------------------------------------------- */
$search = trim($_GET['search'] ?? '');
$status = trim($_GET['status'] ?? '');
$billing_cycle = trim($_GET['billing_cycle'] ?? '');

$where = " WHERE 1=1 ";
$params = [];
$types = "";

if ($search !== '') {
    $where .= " AND plan_name LIKE ? ";
    $params[] = "%{$search}%";
    $types .= "s";
}

if ($status !== '' && in_array($status, ['1', '0'], true)) {
    $where .= " AND status = ? ";
    $params[] = (int)$status;
    $types .= "i";
}

if ($billing_cycle !== '' && in_array($billing_cycle, ['monthly','quarterly','half_yearly','yearly','lifetime'], true)) {
    $where .= " AND billing_cycle = ? ";
    $params[] = $billing_cycle;
    $types .= "s";
}

/* -------------------------------------------------------
   FETCH PLANS
------------------------------------------------------- */
$plans = [];

$sql = "
    SELECT 
        sp.id,
        sp.plan_name,
        sp.price,
        sp.billing_cycle,
        sp.max_branches,
        sp.max_users,
        sp.max_invoices_per_month,
        sp.inventory_module,
        sp.sales_module,
        sp.service_module,
        sp.accounts_module,
        sp.reports_module,
        sp.status,
        sp.created_at,
        (
            SELECT COUNT(*) 
            FROM business_subscriptions bs 
            WHERE bs.plan_id = sp.id
        ) AS businesses_count
    FROM subscription_plans sp
    {$where}
    ORDER BY sp.id DESC
";

$stmt = $conn->prepare($sql);
if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $plans[] = $row;
        }
    }
    $stmt->close();
}

/* -------------------------------------------------------
   COUNTS
------------------------------------------------------- */
$totalPlans = 0;
$activePlans = 0;
$inactivePlans = 0;
$totalSubscriptions = 0;

$res = $conn->query("SELECT COUNT(*) AS total FROM subscription_plans");
if ($res && $row = $res->fetch_assoc()) {
    $totalPlans = (int)$row['total'];
}

$res = $conn->query("SELECT COUNT(*) AS total FROM subscription_plans WHERE status = 1");
if ($res && $row = $res->fetch_assoc()) {
    $activePlans = (int)$row['total'];
}

$res = $conn->query("SELECT COUNT(*) AS total FROM subscription_plans WHERE status = 0");
if ($res && $row = $res->fetch_assoc()) {
    $inactivePlans = (int)$row['total'];
}

$res = $conn->query("SELECT COUNT(*) AS total FROM business_subscriptions");
if ($res && $row = $res->fetch_assoc()) {
    $totalSubscriptions = (int)$row['total'];
}

$pageTitle = 'Subscription Plans';
$currentPage = 'subscription-plans';
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
                                <p class="text-muted mb-1">Total Plans</p>
                                <h3 class="text-primary mt-2 mb-0"><?php echo number_format($totalPlans); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <p class="text-muted mb-1">Active Plans</p>
                                <h3 class="text-success mt-2 mb-0"><?php echo number_format($activePlans); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <p class="text-muted mb-1">Inactive Plans</p>
                                <h3 class="text-danger mt-2 mb-0"><?php echo number_format($inactivePlans); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <p class="text-muted mb-1">Business Subscriptions</p>
                                <h3 class="text-info mt-2 mb-0"><?php echo number_format($totalSubscriptions); ?></h3>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="card">
                    <div class="card-body">
                        <form method="get" class="row g-3 align-items-end">
                            <div class="col-md-4">
                                <label class="form-label">Search</label>
                                <input type="text" name="search" class="form-control"
                                       placeholder="Search by plan name"
                                       value="<?php echo h($search); ?>">
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">Billing Cycle</label>
                                <select name="billing_cycle" class="form-select">
                                    <option value="">All Cycles</option>
                                    <option value="monthly" <?php echo $billing_cycle === 'monthly' ? 'selected' : ''; ?>>Monthly</option>
                                    <option value="quarterly" <?php echo $billing_cycle === 'quarterly' ? 'selected' : ''; ?>>Quarterly</option>
                                    <option value="half_yearly" <?php echo $billing_cycle === 'half_yearly' ? 'selected' : ''; ?>>Half Yearly</option>
                                    <option value="yearly" <?php echo $billing_cycle === 'yearly' ? 'selected' : ''; ?>>Yearly</option>
                                    <option value="lifetime" <?php echo $billing_cycle === 'lifetime' ? 'selected' : ''; ?>>Lifetime</option>
                                </select>
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">Status</label>
                                <select name="status" class="form-select">
                                    <option value="">All Status</option>
                                    <option value="1" <?php echo $status === '1' ? 'selected' : ''; ?>>Active</option>
                                    <option value="0" <?php echo $status === '0' ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                            </div>

                            <div class="col-md-3">
                                <div class="d-flex gap-2">
                                    <button type="submit" class="btn btn-primary">Filter</button>
                                    <a href="subscription-plans.php" class="btn btn-secondary">Reset</a>
                                    <a href="subscription-plan-add.php" class="btn btn-success ms-auto">Add Plan</a>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Plans Table -->
                <div class="card">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 60px;">#</th>
                                        <th>Plan</th>
                                        <th>Price</th>
                                        <th>Branches</th>
                                        <th>Users</th>
                                        <th>Invoices / Month</th>
                                        <th>Modules</th>
                                        <th>Businesses</th>
                                        <th>Status</th>
                                        <th>Created</th>
                                        <th style="width: 240px;">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($plans)): ?>
                                        <?php $sno = 1; ?>
                                        <?php foreach ($plans as $row): ?>
                                            <tr>
                                                <td><?php echo $sno++; ?></td>

                                                <td>
                                                    <div><strong><?php echo h($row['plan_name']); ?></strong></div>
                                                    <small class="text-muted"><?php echo h(ucwords(str_replace('_', ' ', $row['billing_cycle']))); ?></small>
                                                </td>

                                                <td><?php echo money($row['price']); ?></td>

                                                <td><?php echo number_format((int)$row['max_branches']); ?></td>

                                                <td><?php echo number_format((int)$row['max_users']); ?></td>

                                                <td>
                                                    <?php echo $row['max_invoices_per_month'] !== null ? number_format((int)$row['max_invoices_per_month']) : 'Unlimited'; ?>
                                                </td>

                                                <td>
                                                    <div class="d-flex flex-wrap gap-1">
                                                        <?php if ((int)$row['inventory_module'] === 1): ?>
                                                            <span class="badge bg-primary">Inventory</span>
                                                        <?php endif; ?>
                                                        <?php if ((int)$row['sales_module'] === 1): ?>
                                                            <span class="badge bg-success">Sales</span>
                                                        <?php endif; ?>
                                                        <?php if ((int)$row['service_module'] === 1): ?>
                                                            <span class="badge bg-info">Service</span>
                                                        <?php endif; ?>
                                                        <?php if ((int)$row['accounts_module'] === 1): ?>
                                                            <span class="badge bg-warning">Accounts</span>
                                                        <?php endif; ?>
                                                        <?php if ((int)$row['reports_module'] === 1): ?>
                                                            <span class="badge bg-dark">Reports</span>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>

                                                <td><?php echo number_format((int)$row['businesses_count']); ?></td>

                                                <td>
                                                    <?php if ((int)$row['status'] === 1): ?>
                                                        <span class="badge bg-success">Active</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-danger">Inactive</span>
                                                    <?php endif; ?>
                                                </td>

                                                <td><?php echo h(date('d M Y', strtotime($row['created_at']))); ?></td>

                                                <td>
                                                    <div class="d-flex flex-wrap gap-1">
                                                        <a href="subscription-plan-view.php?id=<?php echo (int)$row['id']; ?>" class="btn btn-info btn-sm">View</a>
                                                        <a href="subscription-plan-edit.php?id=<?php echo (int)$row['id']; ?>" class="btn btn-primary btn-sm">Edit</a>
                                                        <a href="subscription-plans.php?delete=<?php echo (int)$row['id']; ?>"
                                                           class="btn btn-danger btn-sm"
                                                           onclick="return confirm('Are you sure you want to delete this plan?');">
                                                            Delete
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="11" class="text-center text-muted py-4">
                                                No subscription plans found.
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