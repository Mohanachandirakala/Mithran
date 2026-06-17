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

$planId = (int)($_GET['id'] ?? 0);
if ($planId <= 0) {
    header("Location: subscription-plans.php");
    exit;
}

$success = '';
$error = '';

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
        status
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
   HANDLE UPDATE
------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $plan_name               = trim($_POST['plan_name'] ?? '');
    $price                   = (float)($_POST['price'] ?? 0);
    $billing_cycle           = trim($_POST['billing_cycle'] ?? 'monthly');
    $max_branches            = (int)($_POST['max_branches'] ?? 1);
    $max_users               = (int)($_POST['max_users'] ?? 5);
    $max_invoices_per_month  = trim($_POST['max_invoices_per_month'] ?? '');
    $inventory_module        = isset($_POST['inventory_module']) ? 1 : 0;
    $sales_module            = isset($_POST['sales_module']) ? 1 : 0;
    $service_module          = isset($_POST['service_module']) ? 1 : 0;
    $accounts_module         = isset($_POST['accounts_module']) ? 1 : 0;
    $reports_module          = isset($_POST['reports_module']) ? 1 : 0;
    $status                  = isset($_POST['status']) ? 1 : 0;

    if ($plan_name === '') {
        $error = 'Plan name is required.';
    } elseif (!in_array($billing_cycle, ['monthly','quarterly','half_yearly','yearly','lifetime'], true)) {
        $error = 'Invalid billing cycle selected.';
    } elseif ($price < 0) {
        $error = 'Price cannot be negative.';
    } elseif ($max_branches < 0 || $max_users < 0) {
        $error = 'Max branches and max users must be valid numbers.';
    } else {
        $check = $conn->prepare("SELECT id FROM subscription_plans WHERE plan_name = ? AND id != ? LIMIT 1");
        if ($check) {
            $check->bind_param("si", $plan_name, $planId);
            $check->execute();
            $checkRes = $check->get_result();
            $exists = $checkRes ? $checkRes->fetch_assoc() : null;
            $check->close();

            if ($exists) {
                $error = 'Plan name already exists.';
            } else {
                $maxInvoices = ($max_invoices_per_month === '' ? null : (int)$max_invoices_per_month);

                $stmt = $conn->prepare("
                    UPDATE subscription_plans SET
                        plan_name = ?,
                        price = ?,
                        billing_cycle = ?,
                        max_branches = ?,
                        max_users = ?,
                        max_invoices_per_month = ?,
                        inventory_module = ?,
                        sales_module = ?,
                        service_module = ?,
                        accounts_module = ?,
                        reports_module = ?,
                        status = ?
                    WHERE id = ?
                    LIMIT 1
                ");

                if ($stmt) {
                    $stmt->bind_param(
                        "sdsiiiiiiiiii",
                        $plan_name,
                        $price,
                        $billing_cycle,
                        $max_branches,
                        $max_users,
                        $maxInvoices,
                        $inventory_module,
                        $sales_module,
                        $service_module,
                        $accounts_module,
                        $reports_module,
                        $status,
                        $planId
                    );

                    if ($stmt->execute()) {
                        $success = 'Subscription plan updated successfully.';
                    } else {
                        $error = 'Failed to update subscription plan.';
                    }
                    $stmt->close();
                } else {
                    $error = 'Database error. Please try again.';
                }
            }
        } else {
            $error = 'Database error. Please try again.';
        }
    }

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
            status
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
}

$pageTitle = 'Edit Subscription Plan';
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

                <form method="post" action="">
                    <div class="row">
                        <div class="col-xl-8">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="card-title mb-4">Plan Details</h4>

                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Plan Name <span class="text-danger">*</span></label>
                                                <input type="text" name="plan_name" class="form-control"
                                                    value="<?php echo h($plan['plan_name']); ?>" required>
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Price <span class="text-danger">*</span></label>
                                                <input type="number" step="0.01" min="0" name="price" class="form-control"
                                                    value="<?php echo h($plan['price']); ?>" required>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label class="form-label">Billing Cycle <span class="text-danger">*</span></label>
                                                <select name="billing_cycle" class="form-select" required>
                                                    <option value="monthly" <?php echo ($plan['billing_cycle'] === 'monthly') ? 'selected' : ''; ?>>Monthly</option>
                                                    <option value="quarterly" <?php echo ($plan['billing_cycle'] === 'quarterly') ? 'selected' : ''; ?>>Quarterly</option>
                                                    <option value="half_yearly" <?php echo ($plan['billing_cycle'] === 'half_yearly') ? 'selected' : ''; ?>>Half Yearly</option>
                                                    <option value="yearly" <?php echo ($plan['billing_cycle'] === 'yearly') ? 'selected' : ''; ?>>Yearly</option>
                                                    <option value="lifetime" <?php echo ($plan['billing_cycle'] === 'lifetime') ? 'selected' : ''; ?>>Lifetime</option>
                                                </select>
                                            </div>
                                        </div>

                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label class="form-label">Max Branches</label>
                                                <input type="number" min="0" name="max_branches" class="form-control"
                                                    value="<?php echo h($plan['max_branches']); ?>">
                                            </div>
                                        </div>

                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label class="form-label">Max Users</label>
                                                <input type="number" min="0" name="max_users" class="form-control"
                                                    value="<?php echo h($plan['max_users']); ?>">
                                            </div>
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Max Invoices Per Month</label>
                                        <input type="number" min="0" name="max_invoices_per_month" class="form-control"
                                            value="<?php echo h($plan['max_invoices_per_month']); ?>">
                                        <small class="text-muted">Leave blank for unlimited.</small>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-xl-4">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="card-title mb-4">Modules</h4>

                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="checkbox" id="inventory_module" name="inventory_module"
                                            <?php echo ((int)$plan['inventory_module'] === 1) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="inventory_module">Inventory Module</label>
                                    </div>

                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="checkbox" id="sales_module" name="sales_module"
                                            <?php echo ((int)$plan['sales_module'] === 1) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="sales_module">Sales Module</label>
                                    </div>

                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="checkbox" id="service_module" name="service_module"
                                            <?php echo ((int)$plan['service_module'] === 1) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="service_module">Service Module</label>
                                    </div>

                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="checkbox" id="accounts_module" name="accounts_module"
                                            <?php echo ((int)$plan['accounts_module'] === 1) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="accounts_module">Accounts Module</label>
                                    </div>

                                    <div class="form-check mb-3">
                                        <input class="form-check-input" type="checkbox" id="reports_module" name="reports_module"
                                            <?php echo ((int)$plan['reports_module'] === 1) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="reports_module">Reports Module</label>
                                    </div>

                                    <hr>

                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="status" name="status"
                                            <?php echo ((int)$plan['status'] === 1) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="status">Active Plan</label>
                                    </div>
                                </div>
                            </div>

                            <div class="card">
                                <div class="card-body">
                                    <div class="d-grid gap-2">
                                        <button type="submit" class="btn btn-primary">Update Plan</button>
                                        <a href="subscription-plans.php" class="btn btn-secondary">Back to Plans</a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>

            </div>
        </div>

        <?php include('includes/footer.php'); ?>
    </div>
</div>

<?php include('includes/rightbar.php'); ?>
<?php include('includes/scripts.php'); ?>

</body>
</html>