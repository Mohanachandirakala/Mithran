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
   DELETE SUBSCRIPTION
------------------------------------------------------- */
if (isset($_GET['delete']) && $_GET['delete'] !== '') {
    $deleteId = (int)$_GET['delete'];

    if ($deleteId > 0) {
        $stmt = $conn->prepare("DELETE FROM business_subscriptions WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("i", $deleteId);
            if ($stmt->execute()) {
                $success = 'Business subscription deleted successfully.';
            } else {
                $error = 'Failed to delete business subscription.';
            }
            $stmt->close();
        } else {
            $error = 'Database error while deleting subscription.';
        }
    }
}

/* -------------------------------------------------------
   FETCH FILTER DROPDOWNS
------------------------------------------------------- */
$businesses = [];
$res = $conn->query("SELECT id, business_name, business_code FROM businesses ORDER BY business_name ASC");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $businesses[] = $row;
    }
}

$plans = [];
$res = $conn->query("SELECT id, plan_name, billing_cycle FROM subscription_plans ORDER BY plan_name ASC");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $plans[] = $row;
    }
}

/* -------------------------------------------------------
   FILTERS
------------------------------------------------------- */
$search              = trim($_GET['search'] ?? '');
$business_id         = (int)($_GET['business_id'] ?? 0);
$plan_id             = (int)($_GET['plan_id'] ?? 0);
$subscription_status = trim($_GET['subscription_status'] ?? '');
$payment_status      = trim($_GET['payment_status'] ?? '');

$where  = " WHERE 1=1 ";
$params = [];
$types  = "";

if ($search !== '') {
    $where .= " AND (
        b.business_name LIKE ?
        OR b.business_code LIKE ?
        OR sp.plan_name LIKE ?
    ) ";
    $like = "%{$search}%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types   .= "sss";
}

if ($business_id > 0) {
    $where .= " AND bs.business_id = ? ";
    $params[] = $business_id;
    $types   .= "i";
}

if ($plan_id > 0) {
    $where .= " AND bs.plan_id = ? ";
    $params[] = $plan_id;
    $types   .= "i";
}

if ($subscription_status !== '' && in_array($subscription_status, ['active','expired','cancelled','trial'], true)) {
    $where .= " AND bs.subscription_status = ? ";
    $params[] = $subscription_status;
    $types   .= "s";
}

if ($payment_status !== '' && in_array($payment_status, ['pending','partial','paid'], true)) {
    $where .= " AND bs.payment_status = ? ";
    $params[] = $payment_status;
    $types   .= "s";
}

/* -------------------------------------------------------
   FETCH SUBSCRIPTIONS
------------------------------------------------------- */
$subscriptions = [];

$sql = "
    SELECT
        bs.id,
        bs.business_id,
        bs.plan_id,
        bs.start_date,
        bs.end_date,
        bs.amount_paid,
        bs.payment_status,
        bs.subscription_status,
        bs.created_at,
        b.business_name,
        b.business_code,
        b.status AS business_status,
        sp.plan_name,
        sp.price,
        sp.billing_cycle
    FROM business_subscriptions bs
    INNER JOIN businesses b ON b.id = bs.business_id
    INNER JOIN subscription_plans sp ON sp.id = bs.plan_id
    {$where}
    ORDER BY bs.id DESC
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
            $subscriptions[] = $row;
        }
    }
    $stmt->close();
}

/* -------------------------------------------------------
   COUNTS
------------------------------------------------------- */
$totalSubscriptions   = 0;
$activeSubscriptions  = 0;
$expiredSubscriptions = 0;
$totalRevenue         = 0.00;

$res = $conn->query("SELECT COUNT(*) AS total FROM business_subscriptions");
if ($res && $row = $res->fetch_assoc()) {
    $totalSubscriptions = (int)$row['total'];
}

$res = $conn->query("SELECT COUNT(*) AS total FROM business_subscriptions WHERE subscription_status = 'active'");
if ($res && $row = $res->fetch_assoc()) {
    $activeSubscriptions = (int)$row['total'];
}

$res = $conn->query("SELECT COUNT(*) AS total FROM business_subscriptions WHERE subscription_status = 'expired'");
if ($res && $row = $res->fetch_assoc()) {
    $expiredSubscriptions = (int)$row['total'];
}

$res = $conn->query("SELECT COALESCE(SUM(amount_paid),0) AS total FROM business_subscriptions");
if ($res && $row = $res->fetch_assoc()) {
    $totalRevenue = (float)$row['total'];
}

$pageTitle = 'Business Subscriptions';
$currentPage = 'business-subscriptions';
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
                                <p class="text-muted mb-1">Total Subscriptions</p>
                                <h3 class="text-primary mt-2 mb-0"><?php echo number_format($totalSubscriptions); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <p class="text-muted mb-1">Active Subscriptions</p>
                                <h3 class="text-success mt-2 mb-0"><?php echo number_format($activeSubscriptions); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <p class="text-muted mb-1">Expired Subscriptions</p>
                                <h3 class="text-danger mt-2 mb-0"><?php echo number_format($expiredSubscriptions); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <p class="text-muted mb-1">Total Revenue</p>
                                <h3 class="text-info mt-2 mb-0"><?php echo money($totalRevenue); ?></h3>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="card">
                    <div class="card-body">
                        <form method="get" class="row g-3 align-items-end">
                            <div class="col-md-3">
                                <label class="form-label">Search</label>
                                <input type="text" name="search" class="form-control"
                                       placeholder="Business or plan name"
                                       value="<?php echo h($search); ?>">
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">Business</label>
                                <select name="business_id" class="form-select">
                                    <option value="">All Businesses</option>
                                    <?php foreach ($businesses as $business): ?>
                                        <option value="<?php echo (int)$business['id']; ?>" <?php echo $business_id === (int)$business['id'] ? 'selected' : ''; ?>>
                                            <?php echo h($business['business_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">Plan</label>
                                <select name="plan_id" class="form-select">
                                    <option value="">All Plans</option>
                                    <?php foreach ($plans as $plan): ?>
                                        <option value="<?php echo (int)$plan['id']; ?>" <?php echo $plan_id === (int)$plan['id'] ? 'selected' : ''; ?>>
                                            <?php echo h($plan['plan_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">Subscription Status</label>
                                <select name="subscription_status" class="form-select">
                                    <option value="">All</option>
                                    <option value="active" <?php echo $subscription_status === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="trial" <?php echo $subscription_status === 'trial' ? 'selected' : ''; ?>>Trial</option>
                                    <option value="expired" <?php echo $subscription_status === 'expired' ? 'selected' : ''; ?>>Expired</option>
                                    <option value="cancelled" <?php echo $subscription_status === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                </select>
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">Payment Status</label>
                                <select name="payment_status" class="form-select">
                                    <option value="">All</option>
                                    <option value="paid" <?php echo $payment_status === 'paid' ? 'selected' : ''; ?>>Paid</option>
                                    <option value="partial" <?php echo $payment_status === 'partial' ? 'selected' : ''; ?>>Partial</option>
                                    <option value="pending" <?php echo $payment_status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                </select>
                            </div>

                            <div class="col-md-1">
                                <div class="d-flex gap-2">
                                    <button type="submit" class="btn btn-primary">Filter</button>
                                </div>
                            </div>
                        </form>

                        <div class="mt-3 text-end">
                            <a href="business-subscriptions.php" class="btn btn-secondary">Reset</a>
                            <a href="subscription-renewals.php" class="btn btn-success">Add Renewal</a>
                        </div>
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
                                        <th>Business</th>
                                        <th>Plan</th>
                                        <th>Start Date</th>
                                        <th>End Date</th>
                                        <th>Amount Paid</th>
                                        <th>Payment</th>
                                        <th>Subscription</th>
                                        <th>Business Status</th>
                                        <th>Created</th>
                                        <th style="width:220px;">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($subscriptions)): ?>
                                        <?php $sno = 1; ?>
                                        <?php foreach ($subscriptions as $row): ?>
                                            <tr>
                                                <td><?php echo $sno++; ?></td>

                                                <td>
                                                    <div><?php echo h($row['business_name']); ?></div>
                                                    <small class="text-muted"><?php echo h($row['business_code']); ?></small>
                                                </td>

                                                <td>
                                                    <div><?php echo h($row['plan_name']); ?></div>
                                                    <small class="text-muted">
                                                        <?php echo h(ucwords(str_replace('_', ' ', $row['billing_cycle']))); ?>
                                                    </small>
                                                </td>

                                                <td>
                                                    <?php echo !empty($row['start_date']) ? h(date('d M Y', strtotime($row['start_date']))) : '-'; ?>
                                                </td>

                                                <td>
                                                    <?php echo !empty($row['end_date']) ? h(date('d M Y', strtotime($row['end_date']))) : '-'; ?>
                                                </td>

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

                                                <td>
                                                    <?php
                                                    $bizClass = 'secondary';
                                                    if ($row['business_status'] === 'active') $bizClass = 'success';
                                                    elseif ($row['business_status'] === 'inactive') $bizClass = 'warning';
                                                    elseif ($row['business_status'] === 'suspended') $bizClass = 'danger';
                                                    ?>
                                                    <span class="badge bg-<?php echo $bizClass; ?>">
                                                        <?php echo h(ucfirst($row['business_status'])); ?>
                                                    </span>
                                                </td>

                                                <td><?php echo h(date('d M Y', strtotime($row['created_at']))); ?></td>

                                                <td>
                                                    <div class="d-flex flex-wrap gap-1">
                                                        <a href="business-view.php?id=<?php echo (int)$row['business_id']; ?>" class="btn btn-info btn-sm">Business</a>
                                                        <a href="subscription-plan-view.php?id=<?php echo (int)$row['plan_id']; ?>" class="btn btn-primary btn-sm">Plan</a>
                                                        <a href="business-subscriptions.php?delete=<?php echo (int)$row['id']; ?>"
                                                           class="btn btn-danger btn-sm"
                                                           onclick="return confirm('Are you sure you want to delete this subscription?');">
                                                            Delete
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="11" class="text-center text-muted py-4">
                                                No business subscriptions found.
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