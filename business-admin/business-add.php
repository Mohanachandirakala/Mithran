<?php
date_default_timezone_set('Asia/Kolkata');
require_once 'includes/config.php';

if (!isset($_SESSION['platform_admin_id']) || (int)$_SESSION['platform_admin_id'] <= 0) {
    header("Location: login.php");
    exit;
}

$platformAdminId = (int)$_SESSION['platform_admin_id'];
$success = '';
$error = '';

function h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $business_name     = trim($_POST['business_name'] ?? '');
    $business_code     = trim($_POST['business_code'] ?? '');
    $owner_name        = trim($_POST['owner_name'] ?? '');
    $email             = trim($_POST['email'] ?? '');
    $mobile            = trim($_POST['mobile'] ?? '');
    $alternate_mobile  = trim($_POST['alternate_mobile'] ?? '');
    $gstin             = trim($_POST['gstin'] ?? '');
    $pan_no            = trim($_POST['pan_no'] ?? '');
    $address_line1     = trim($_POST['address_line1'] ?? '');
    $address_line2     = trim($_POST['address_line2'] ?? '');
    $city              = trim($_POST['city'] ?? '');
    $district          = trim($_POST['district'] ?? '');
    $state             = trim($_POST['state'] ?? '');
    $pincode           = trim($_POST['pincode'] ?? '');
    $status            = trim($_POST['status'] ?? 'active');
    $notes             = trim($_POST['notes'] ?? '');

    if ($business_name === '' || $business_code === '') {
        $error = 'Business name and business code are required.';
    } else {
        $check = $conn->prepare("SELECT id FROM businesses WHERE business_code = ? LIMIT 1");
        if ($check) {
            $check->bind_param("s", $business_code);
            $check->execute();
            $res = $check->get_result();
            $exists = $res ? $res->fetch_assoc() : null;
            $check->close();

            if ($exists) {
                $error = 'Business code already exists.';
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO businesses (
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
                        status,
                        notes,
                        created_by_platform_admin,
                        created_at,
                        updated_at
                    ) VALUES (
                        ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW()
                    )
                ");

                if ($stmt) {
                    $stmt->bind_param(
                        "ssssssssssssssssi",
                        $business_name,
                        $business_code,
                        $owner_name,
                        $email,
                        $mobile,
                        $alternate_mobile,
                        $gstin,
                        $pan_no,
                        $address_line1,
                        $address_line2,
                        $city,
                        $district,
                        $state,
                        $pincode,
                        $status,
                        $notes,
                        $platformAdminId
                    );

                    if ($stmt->execute()) {
                        $businessId = (int)$stmt->insert_id;
                        $stmt->close();

                        $set = $conn->prepare("
                            INSERT INTO business_settings (
                                business_id,
                                invoice_prefix,
                                service_invoice_prefix,
                                quotation_prefix,
                                payment_receipt_prefix,
                                timezone,
                                currency_symbol,
                                tax_type,
                                show_logo_on_invoice,
                                updated_at
                            ) VALUES (
                                ?, 'INV', 'SRV', 'QTN', 'PAY', 'Asia/Kolkata', '₹', 'exclusive', 1, NOW()
                            )
                        ");
                        if ($set) {
                            $set->bind_param("i", $businessId);
                            $set->execute();
                            $set->close();
                        }

                        $success = 'Business created successfully.';
                        $_POST = [];
                    } else {
                        $error = 'Failed to create business.';
                        $stmt->close();
                    }
                } else {
                    $error = 'Database error. Please try again.';
                }
            }
        } else {
            $error = 'Database error. Please try again.';
        }
    }
}

$pageTitle = 'Add Business';
$currentPage = 'business-add';
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
                                    <h4 class="card-title mb-4">Business Details</h4>

                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Business Name <span class="text-danger">*</span></label>
                                                <input type="text" name="business_name" class="form-control"
                                                    value="<?php echo h($_POST['business_name'] ?? ''); ?>" required>
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Business Code <span class="text-danger">*</span></label>
                                                <input type="text" name="business_code" class="form-control"
                                                    value="<?php echo h($_POST['business_code'] ?? ''); ?>" required>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Owner Name</label>
                                                <input type="text" name="owner_name" class="form-control"
                                                    value="<?php echo h($_POST['owner_name'] ?? ''); ?>">
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Email</label>
                                                <input type="email" name="email" class="form-control"
                                                    value="<?php echo h($_POST['email'] ?? ''); ?>">
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Mobile</label>
                                                <input type="text" name="mobile" class="form-control"
                                                    value="<?php echo h($_POST['mobile'] ?? ''); ?>">
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Alternate Mobile</label>
                                                <input type="text" name="alternate_mobile" class="form-control"
                                                    value="<?php echo h($_POST['alternate_mobile'] ?? ''); ?>">
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">GSTIN</label>
                                                <input type="text" name="gstin" class="form-control"
                                                    value="<?php echo h($_POST['gstin'] ?? ''); ?>">
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">PAN No</label>
                                                <input type="text" name="pan_no" class="form-control"
                                                    value="<?php echo h($_POST['pan_no'] ?? ''); ?>">
                                            </div>
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Address Line 1</label>
                                        <input type="text" name="address_line1" class="form-control"
                                            value="<?php echo h($_POST['address_line1'] ?? ''); ?>">
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Address Line 2</label>
                                        <input type="text" name="address_line2" class="form-control"
                                            value="<?php echo h($_POST['address_line2'] ?? ''); ?>">
                                    </div>

                                    <div class="row">
                                        <div class="col-md-3">
                                            <div class="mb-3">
                                                <label class="form-label">City</label>
                                                <input type="text" name="city" class="form-control"
                                                    value="<?php echo h($_POST['city'] ?? ''); ?>">
                                            </div>
                                        </div>

                                        <div class="col-md-3">
                                            <div class="mb-3">
                                                <label class="form-label">District</label>
                                                <input type="text" name="district" class="form-control"
                                                    value="<?php echo h($_POST['district'] ?? ''); ?>">
                                            </div>
                                        </div>

                                        <div class="col-md-3">
                                            <div class="mb-3">
                                                <label class="form-label">State</label>
                                                <input type="text" name="state" class="form-control"
                                                    value="<?php echo h($_POST['state'] ?? ''); ?>">
                                            </div>
                                        </div>

                                        <div class="col-md-3">
                                            <div class="mb-3">
                                                <label class="form-label">Pincode</label>
                                                <input type="text" name="pincode" class="form-control"
                                                    value="<?php echo h($_POST['pincode'] ?? ''); ?>">
                                            </div>
                                        </div>
                                    </div>

                                    <div class="mb-0">
                                        <label class="form-label">Notes</label>
                                        <textarea name="notes" rows="4" class="form-control"><?php echo h($_POST['notes'] ?? ''); ?></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-xl-4">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="card-title mb-4">Business Status</h4>

                                    <div class="mb-3">
                                        <label class="form-label">Status</label>
                                        <select name="status" class="form-select">
                                            <option value="active" <?php echo (($_POST['status'] ?? 'active') === 'active') ? 'selected' : ''; ?>>Active</option>
                                            <option value="inactive" <?php echo (($_POST['status'] ?? '') === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                                            <option value="suspended" <?php echo (($_POST['status'] ?? '') === 'suspended') ? 'selected' : ''; ?>>Suspended</option>
                                        </select>
                                    </div>

                                    <div class="alert alert-info mb-0">
                                        Default business settings will be created automatically after business creation.
                                    </div>
                                </div>
                            </div>

                            <div class="card">
                                <div class="card-body">
                                    <div class="d-grid gap-2">
                                        <button type="submit" class="btn btn-primary">Create Business</button>
                                        <a href="businesses.php" class="btn btn-secondary">Back to Businesses</a>
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