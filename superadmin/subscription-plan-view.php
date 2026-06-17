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

$planId = (int)($_GET['id'] ?? 0);
if ($planId <= 0) {
    header("Location: subscription-plans.php");
    exit;
}

/* -------------------------------------------------------
   FETCH PLAN
------------------------------------------------------- */
$plan = null;
$stmt = $conn->prepare("
    SELECT 
        id,
        plan_name,
        price,
        billing_cycle,
        max_branches,
        max_users,
        max_invoices_per_month,
        inventory_module,
        sales_module,
        service_module,
        accounts_module,
        reports_module,
        status,
        created_at
    FROM subscription_plans
    WHERE id = ?
    LIMIT 1
");
if ($stmt) {
    $stmt->bind_param("i", $planId);
    $stmt->execute();
    $result = $stmt->get_result();
    $plan = $result ? $result->fetch_assoc() : null;
    $stmt->close();
}

if (!$plan) {
    header("Location: subscription-plans.php");
    exit;
}

/* -------------------------------------------------------
   COUNTS
------------------------------------------------------- */
$totalBusinessesUsing = 0;
$activeBusinessesUsing = 0;
$expiredBusinessesUsing = 0;
$totalRevenueFromPlan = 0.00;

$stmt = $conn->prepare("SELECT COUNT(*) AS total FROM business_subscriptions WHERE plan_id = ?");
if ($stmt) {
    $stmt->bind_param("i", $planId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $totalBusinessesUsing = (int)($row['total'] ?? 0);
    $stmt->close();
}

$stmt = $conn->prepare("SELECT COUNT(*) AS total FROM business_subscriptions WHERE plan_id = ? AND subscription_status = 'active'");
if ($stmt) {
    $stmt->bind_param("i", $planId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $activeBusinessesUsing = (int)($row['total'] ?? 0);
    $stmt->close();
}

$stmt = $conn->prepare("SELECT COUNT(*) AS total FROM business_subscriptions WHERE plan_id = ? AND subscription_status = 'expired'");
if ($stmt) {
    $stmt->bind_param("i", $planId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $expiredBusinessesUsing = (int)($row['total'] ?? 0);
    $stmt->close();
}

$stmt = $conn->prepare("SELECT COALESCE(SUM(amount_paid),0) AS total FROM business_subscriptions WHERE plan_id = ?");
if ($stmt) {
    $stmt->bind_param("i", $planId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $totalRevenueFromPlan = (float)($row['total'] ?? 0);
    $stmt->close();
}

/* -------------------------------------------------------
   RECENT BUSINESS SUBSCRIPTIONS
------------------------------------------------------- */
$subscriptions = [];
$stmt = $conn->prepare("
    SELECT 
        bs.id,
        bs.business_id,
        bs.start_date,
        bs.end_date,
        bs.amount_paid,
        bs.payment_status,
        bs.subscription_status,
        bs.created_at,
        b.business_name,
        b.business_code
    FROM business_subscriptions bs
    INNER JOIN businesses b ON b.id = bs.business_id
    WHERE bs.plan_id = ?
    ORDER BY bs.id DESC
    LIMIT 10
");
if ($stmt) {
    $stmt->bind_param("i", $planId);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $subscriptions[] = $row;
        }
    }
    $stmt->close();
}

$pageTitle = 'View Subscription Plan';
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


                <!-- Top Card -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex flex-wrap align-items-center justify-content-between">
                                    <div>
                                        <h3 class="mb-1"><?php echo h($plan['plan_name']); ?></h3>
                                        <p class="text-muted mb-1">
                                            <?php echo h(ucwords(str_replace('_', ' ', $plan['billing_cycle']))); ?> Plan
                                        </p>
                                        <span class="badge bg-<?php echo (int)$plan['status'] === 1 ? 'success' : 'danger'; ?>">
                                            <?php echo (int)$plan['status'] === 1 ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </div>
                                    <div class="mt-3 mt-md-0 d-flex gap-2">
                                        <a href="subscription-plan-edit.php?id=<?php echo (int)$plan['id']; ?>" class="btn btn-primary">Edit Plan</a>
                                        <a href="subscription-plans.php" class="btn btn-secondary">Back</a>
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
                                <p class="text-muted mb-1">Businesses Using</p>
                                <h3 class="text-primary mt-2 mb-0"><?php echo number_format($totalBusinessesUsing); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <p class="text-muted mb-1">Active Subscriptions</p>
                                <h3 class="text-success mt-2 mb-0"><?php echo number_format($activeBusinessesUsing); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <p class="text-muted mb-1">Expired Subscriptions</p>
                                <h3 class="text-danger mt-2 mb-0"><?php echo number_format($expiredBusinessesUsing); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <p class="text-muted mb-1">Revenue From Plan</p>
                                <h3 class="text-info mt-2 mb-0"><?php echo money($totalRevenueFromPlan); ?></h3>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Details -->
                <div class="row">
                    <div class="col-xl-6">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Plan Details</h4>
                                <div class="table-responsive">
                                    <table class="table table-bordered mb-0">
                                        <tbody>
                                            <tr>
                                                <th style="width:220px;">Plan Name</th>
                                                <td><?php echo h($plan['plan_name']); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Price</th>
                                                <td><?php echo money($plan['price']); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Billing Cycle</th>
                                                <td><?php echo h(ucwords(str_replace('_', ' ', $plan['billing_cycle']))); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Max Branches</th>
                                                <td><?php echo number_format((int)$plan['max_branches']); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Max Users</th>
                                                <td><?php echo number_format((int)$plan['max_users']); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Max Invoices / Month</th>
                                                <td>
                                                    <?php echo $plan['max_invoices_per_month'] !== null ? number_format((int)$plan['max_invoices_per_month']) : 'Unlimited'; ?>
                                                </td>
                                            </tr>
                                            <tr>
                                                <th>Status</th>
                                                <td>
                                                    <span class="badge bg-<?php echo (int)$plan['status'] === 1 ? 'success' : 'danger'; ?>">
                                                        <?php echo (int)$plan['status'] === 1 ? 'Active' : 'Inactive'; ?>
                                                    </span>
                                                </td>
                                            </tr>
                                            <tr>
                                                <th>Created At</th>
                                                <td><?php echo h(date('d M Y h:i A', strtotime($plan['created_at']))); ?></td>
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
                                <h4 class="card-title mb-4">Modules Enabled</h4>
                                <div class="table-responsive">
                                    <table class="table table-bordered mb-0">
                                        <tbody>
                                            <tr>
                                                <th style="width:220px;">Inventory Module</th>
                                                <td>
                                                    <span class="badge bg-<?php echo (int)$plan['inventory_module'] === 1 ? 'success' : 'secondary'; ?>">
                                                        <?php echo (int)$plan['inventory_module'] === 1 ? 'Enabled' : 'Disabled'; ?>
                                                    </span>
                                                </td>
                                            </tr>
                                            <tr>
                                                <th>Sales Module</th>
                                                <td>
                                                    <span class="badge bg-<?php echo (int)$plan['sales_module'] === 1 ? 'success' : 'secondary'; ?>">
                                                        <?php echo (int)$plan['sales_module'] === 1 ? 'Enabled' : 'Disabled'; ?>
                                                    </span>
                                                </td>
                                            </tr>
                                            <tr>
                                                <th>Service Module</th>
                                                <td>
                                                    <span class="badge bg-<?php echo (int)$plan['service_module'] === 1 ? 'success' : 'secondary'; ?>">
                                                        <?php echo (int)$plan['service_module'] === 1 ? 'Enabled' : 'Disabled'; ?>
                                                    </span>
                                                </td>
                                            </tr>
                                            <tr>
                                                <th>Accounts Module</th>
                                                <td>
                                                    <span class="badge bg-<?php echo (int)$plan['accounts_module'] === 1 ? 'success' : 'secondary'; ?>">
                                                        <?php echo (int)$plan['accounts_module'] === 1 ? 'Enabled' : 'Disabled'; ?>
                                                    </span>
                                                </td>
                                            </tr>
                                            <tr>
                                                <th>Reports Module</th>
                                                <td>
                                                    <span class="badge bg-<?php echo (int)$plan['reports_module'] === 1 ? 'success' : 'secondary'; ?>">
                                                        <?php echo (int)$plan['reports_module'] === 1 ? 'Enabled' : 'Disabled'; ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>

                                <div class="mt-4">
                                    <div class="d-flex flex-wrap gap-2">
                                        <?php if ((int)$plan['inventory_module'] === 1): ?><span class="badge bg-primary">Inventory</span><?php endif; ?>
                                        <?php if ((int)$plan['sales_module'] === 1): ?><span class="badge bg-success">Sales</span><?php endif; ?>
                                        <?php if ((int)$plan['service_module'] === 1): ?><span class="badge bg-info">Service</span><?php endif; ?>
                                        <?php if ((int)$plan['accounts_module'] === 1): ?><span class="badge bg-warning">Accounts</span><?php endif; ?>
                                        <?php if ((int)$plan['reports_module'] === 1): ?><span class="badge bg-dark">Reports</span><?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Businesses using this plan -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex align-items-center justify-content-between mb-4">
                                    <h4 class="card-title mb-0">Recent Business Subscriptions</h4>
                                    <a href="business-subscriptions.php?plan_id=<?php echo (int)$plan['id']; ?>" class="btn btn-sm btn-primary">View All</a>
                                </div>

                                <div class="table-responsive">
                                    <table class="table table-bordered table-hover align-middle mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Business</th>
                                                <th>Start Date</th>
                                                <th>End Date</th>
                                                <th>Amount Paid</th>
                                                <th>Payment Status</th>
                                                <th>Subscription Status</th>
                                                <th>Created</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($subscriptions)): ?>
                                                <?php foreach ($subscriptions as $row): ?>
                                                    <tr>
                                                        <td>
                                                            <div><?php echo h($row['business_name']); ?></div>
                                                            <small class="text-muted"><?php echo h($row['business_code']); ?></small>
                                                        </td>
                                                        <td><?php echo !empty($row['start_date']) ? h(date('d M Y', strtotime($row['start_date']))) : '-'; ?></td>
                                                        <td><?php echo !empty($row['end_date']) ? h(date('d M Y', strtotime($row['end_date']))) : '-'; ?></td>
                                                        <td><?php echo money($row['amount_paid']); ?></td>
                                                        <td>
                                                            <?php
                                                            $payClass = 'secondary';
                                                            if ($row['payment_status'] === 'paid') $payClass = 'success';
                                                            elseif ($row['payment_status'] === 'partial') $payClass = 'warning';
                                                            elseif ($row['payment_status'] === 'pending') $payClass = 'danger';
                                                            ?>
                                                            <span class="badge bg-<?php echo $payClass; ?>">
                                                                <?php echo h(ucfirst($row['payment_status'])); ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <?php
                                                            $subClass = 'secondary';
                                                            if ($row['subscription_status'] === 'active') $subClass = 'success';
                                                            elseif ($row['subscription_status'] === 'trial') $subClass = 'info';
                                                            elseif ($row['subscription_status'] === 'expired') $subClass = 'danger';
                                                            elseif ($row['subscription_status'] === 'cancelled') $subClass = 'dark';
                                                            ?>
                                                            <span class="badge bg-<?php echo $subClass; ?>">
                                                                <?php echo h(ucfirst($row['subscription_status'])); ?>
                                                            </span>
                                                        </td>
                                                        <td><?php echo h(date('d M Y', strtotime($row['created_at']))); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="7" class="text-center text-muted py-4">
                                                        No business subscriptions found for this plan.
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

            </div>
        </div>

        <?php include('includes/footer.php'); ?>
    </div>
</div>

<?php include('includes/rightbar.php'); ?>
<?php include('includes/scripts.php'); ?>

</body>
</html>