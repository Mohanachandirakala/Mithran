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

/* -------------------------------------------------------
   LOAD DATA
------------------------------------------------------- */
$business = null;
$user = null;
$branch = null;
$settings = null;
$success = '';
$error = '';

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
   UPDATE BUSINESS PROFILE
------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $business_name     = trim($_POST['business_name'] ?? '');
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
    $notes             = trim($_POST['notes'] ?? '');

    if ($business_name === '') {
        $error = 'Business name is required.';
    } else {
        $stmt = $conn->prepare("UPDATE businesses SET
                    business_name = ?,
                    owner_name = ?,
                    email = ?,
                    mobile = ?,
                    alternate_mobile = ?,
                    gstin = ?,
                    pan_no = ?,
                    address_line1 = ?,
                    address_line2 = ?,
                    city = ?,
                    district = ?,
                    state = ?,
                    pincode = ?,
                    notes = ?,
                    updated_at = NOW()
                WHERE id = ?
                LIMIT 1");

        if ($stmt) {
            $stmt->bind_param(
                'ssssssssssssssi',
                $business_name,
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
                $notes,
                $businessId
            );

            if ($stmt->execute()) {
                $_SESSION['business_name'] = $business_name;
                $_SESSION['business_gstin'] = $gstin;
                $success = 'Business profile updated successfully.';
            } else {
                $error = 'Failed to update business profile.';
            }
            $stmt->close();
        } else {
            $error = 'Unable to prepare update query.';
        }
    }

    if ($error === '') {
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
}

$pageTitle = 'Business Profile';
$currentPage = 'business-profile';
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
                            <div class="alert alert-success"><?php echo h($success); ?></div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($error !== ''): ?>
                    <div class="row">
                        <div class="col-12">
                            <div class="alert alert-danger"><?php echo h($error); ?></div>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="row">
                    <div class="col-xl-4">
                        <div class="card">
                            <div class="card-body text-center">
                                <div class="mb-3">
                                    <?php if (!empty($business['logo_path'])): ?>
                                        <img src="<?php echo h($business['logo_path']); ?>" alt="Logo" style="max-width:90px; max-height:90px;">
                                    <?php else: ?>
                                        <div style="width:90px;height:90px;line-height:90px;border-radius:50%;background:#e9ecef;margin:0 auto;font-size:30px;font-weight:bold;">
                                            <?php echo h(strtoupper(substr($business['business_name'] ?? 'B', 0, 1))); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <h4 class="mb-1"><?php echo h($business['business_name'] ?? '-'); ?></h4>
                                <p class="text-muted mb-1"><?php echo h($business['business_code'] ?? '-'); ?></p>
                                <p class="mb-0">
                                    <span class="badge bg-<?php echo (($business['status'] ?? '') === 'active') ? 'success' : 'secondary'; ?>">
                                        <?php echo h(ucfirst($business['status'] ?? 'inactive')); ?>
                                    </span>
                                </p>
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
                                        <td><?php echo !empty($user['last_login_at']) ? h(date('d M Y h:i A', strtotime($user['last_login_at']))) : '-'; ?></td>
                                    </tr>
                                </table>
                            </div>
                        </div>

                        <?php if ($branch): ?>
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-3">Branch Details</h4>
                                <table class="table table-borderless mb-0">
                                    <tr>
                                        <th>Branch</th>
                                        <td><?php echo h($branch['branch_name'] ?? '-'); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Code</th>
                                        <td><?php echo h($branch['branch_code'] ?? '-'); ?></td>
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

                        <?php if ($settings): ?>
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-3">Business Settings</h4>
                                <table class="table table-borderless mb-0">
                                    <tr>
                                        <th>Invoice Prefix</th>
                                        <td><?php echo h($settings['invoice_prefix'] ?? '-'); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Quotation Prefix</th>
                                        <td><?php echo h($settings['quotation_prefix'] ?? '-'); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Receipt Prefix</th>
                                        <td><?php echo h($settings['payment_receipt_prefix'] ?? '-'); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Timezone</th>
                                        <td><?php echo h($settings['timezone'] ?? '-'); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Currency</th>
                                        <td><?php echo h($settings['currency_symbol'] ?? '-'); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Tax Type</th>
                                        <td><?php echo h(ucfirst($settings['tax_type'] ?? '-')); ?></td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="col-xl-8">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Edit Business Details</h4>

                                <form method="post">
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Business Name</label>
                                            <input type="text" name="business_name" class="form-control" value="<?php echo h($business['business_name'] ?? ''); ?>" required>
                                        </div>

                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Owner Name</label>
                                            <input type="text" name="owner_name" class="form-control" value="<?php echo h($business['owner_name'] ?? ''); ?>">
                                        </div>

                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Email</label>
                                            <input type="email" name="email" class="form-control" value="<?php echo h($business['email'] ?? ''); ?>">
                                        </div>

                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Mobile</label>
                                            <input type="text" name="mobile" class="form-control" value="<?php echo h($business['mobile'] ?? ''); ?>">
                                        </div>

                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Alternate Mobile</label>
                                            <input type="text" name="alternate_mobile" class="form-control" value="<?php echo h($business['alternate_mobile'] ?? ''); ?>">
                                        </div>

                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">GSTIN</label>
                                            <input type="text" name="gstin" class="form-control" value="<?php echo h($business['gstin'] ?? ''); ?>">
                                        </div>

                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">PAN No</label>
                                            <input type="text" name="pan_no" class="form-control" value="<?php echo h($business['pan_no'] ?? ''); ?>">
                                        </div>

                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">City</label>
                                            <input type="text" name="city" class="form-control" value="<?php echo h($business['city'] ?? ''); ?>">
                                        </div>

                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">District</label>
                                            <input type="text" name="district" class="form-control" value="<?php echo h($business['district'] ?? ''); ?>">
                                        </div>

                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">State</label>
                                            <input type="text" name="state" class="form-control" value="<?php echo h($business['state'] ?? ''); ?>">
                                        </div>

                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Pincode</label>
                                            <input type="text" name="pincode" class="form-control" value="<?php echo h($business['pincode'] ?? ''); ?>">
                                        </div>

                                        <div class="col-md-12 mb-3">
                                            <label class="form-label">Address Line 1</label>
                                            <input type="text" name="address_line1" class="form-control" value="<?php echo h($business['address_line1'] ?? ''); ?>">
                                        </div>

                                        <div class="col-md-12 mb-3">
                                            <label class="form-label">Address Line 2</label>
                                            <input type="text" name="address_line2" class="form-control" value="<?php echo h($business['address_line2'] ?? ''); ?>">
                                        </div>

                                        <div class="col-md-12 mb-3">
                                            <label class="form-label">Notes</label>
                                            <textarea name="notes" class="form-control" rows="4"><?php echo h($business['notes'] ?? ''); ?></textarea>
                                        </div>
                                    </div>

                                    <div class="mt-2">
                                        <button type="submit" class="btn btn-primary">Update Business Profile</button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-3">Business Information</h4>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <div class="border rounded p-3 h-100">
                                            <div class="text-muted mb-1">Business Code</div>
                                            <div class="fw-bold"><?php echo h($business['business_code'] ?? '-'); ?></div>
                                        </div>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <div class="border rounded p-3 h-100">
                                            <div class="text-muted mb-1">Created At</div>
                                            <div class="fw-bold">
                                                <?php echo !empty($business['created_at']) ? h(date('d M Y h:i A', strtotime($business['created_at']))) : '-'; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <div class="border rounded p-3 h-100">
                                            <div class="text-muted mb-1">Updated At</div>
                                            <div class="fw-bold">
                                                <?php echo !empty($business['updated_at']) ? h(date('d M Y h:i A', strtotime($business['updated_at']))) : '-'; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <div class="border rounded p-3 h-100">
                                            <div class="text-muted mb-1">Business Status</div>
                                            <div class="fw-bold"><?php echo h(ucfirst($business['status'] ?? '-')); ?></div>
                                        </div>
                                    </div>
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