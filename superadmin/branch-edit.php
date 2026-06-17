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

$success = '';
$error = '';

/* -------------------------------------------------------
   FETCH BUSINESSES
------------------------------------------------------- */
$businesses = [];
$res = $conn->query("SELECT id, business_name, business_code, status FROM businesses ORDER BY business_name ASC");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $businesses[] = $row;
    }
}

/* -------------------------------------------------------
   FETCH BRANCH
------------------------------------------------------- */
$branch = null;
$stmt = $conn->prepare("
    SELECT 
        id,
        business_id,
        branch_name,
        branch_code,
        contact_person,
        email,
        mobile,
        alternate_mobile,
        gstin,
        address_line1,
        address_line2,
        city,
        district,
        state,
        pincode,
        is_head_office,
        status
    FROM branches
    WHERE id = ?
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
   HANDLE UPDATE
------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $business_id       = (int)($_POST['business_id'] ?? 0);
    $branch_name       = trim($_POST['branch_name'] ?? '');
    $branch_code       = trim($_POST['branch_code'] ?? '');
    $contact_person    = trim($_POST['contact_person'] ?? '');
    $email             = trim($_POST['email'] ?? '');
    $mobile            = trim($_POST['mobile'] ?? '');
    $alternate_mobile  = trim($_POST['alternate_mobile'] ?? '');
    $gstin             = trim($_POST['gstin'] ?? '');
    $address_line1     = trim($_POST['address_line1'] ?? '');
    $address_line2     = trim($_POST['address_line2'] ?? '');
    $city              = trim($_POST['city'] ?? '');
    $district          = trim($_POST['district'] ?? '');
    $state             = trim($_POST['state'] ?? '');
    $pincode           = trim($_POST['pincode'] ?? '');
    $status            = trim($_POST['status'] ?? 'active');
    $is_head_office    = isset($_POST['is_head_office']) ? 1 : 0;

    if ($business_id <= 0 || $branch_name === '' || $branch_code === '') {
        $error = 'Business, branch name and branch code are required.';
    } else {
        $check = $conn->prepare("SELECT id FROM branches WHERE business_id = ? AND branch_code = ? AND id != ? LIMIT 1");
        if ($check) {
            $check->bind_param("isi", $business_id, $branch_code, $branchId);
            $check->execute();
            $checkRes = $check->get_result();
            $exists = $checkRes ? $checkRes->fetch_assoc() : null;
            $check->close();

            if ($exists) {
                $error = 'Branch code already exists for this business.';
            } else {
                if ($is_head_office === 1) {
                    $resetHead = $conn->prepare("UPDATE branches SET is_head_office = 0 WHERE business_id = ? AND id != ?");
                    if ($resetHead) {
                        $resetHead->bind_param("ii", $business_id, $branchId);
                        $resetHead->execute();
                        $resetHead->close();
                    }
                }

                $update = $conn->prepare("
                    UPDATE branches SET
                        business_id = ?,
                        branch_name = ?,
                        branch_code = ?,
                        contact_person = ?,
                        email = ?,
                        mobile = ?,
                        alternate_mobile = ?,
                        gstin = ?,
                        address_line1 = ?,
                        address_line2 = ?,
                        city = ?,
                        district = ?,
                        state = ?,
                        pincode = ?,
                        is_head_office = ?,
                        status = ?,
                        updated_at = NOW()
                    WHERE id = ?
                    LIMIT 1
                ");

                if ($update) {
                    $update->bind_param(
                        "isssssssssssssisi",
                        $business_id,
                        $branch_name,
                        $branch_code,
                        $contact_person,
                        $email,
                        $mobile,
                        $alternate_mobile,
                        $gstin,
                        $address_line1,
                        $address_line2,
                        $city,
                        $district,
                        $state,
                        $pincode,
                        $is_head_office,
                        $status,
                        $branchId
                    );

                    if ($update->execute()) {
                        $success = 'Branch updated successfully.';
                    } else {
                        $error = 'Failed to update branch.';
                    }
                    $update->close();
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
            business_id,
            branch_name,
            branch_code,
            contact_person,
            email,
            mobile,
            alternate_mobile,
            gstin,
            address_line1,
            address_line2,
            city,
            district,
            state,
            pincode,
            is_head_office,
            status
        FROM branches
        WHERE id = ?
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param("i", $branchId);
        $stmt->execute();
        $result = $stmt->get_result();
        $branch = $result ? $result->fetch_assoc() : null;
        $stmt->close();
    }
}

$pageTitle = 'Edit Branch';
$currentPage = 'branches';
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
                                    <h4 class="card-title mb-4">Branch Details</h4>

                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Business <span class="text-danger">*</span></label>
                                                <select name="business_id" class="form-select" required>
                                                    <option value="">Select Business</option>
                                                    <?php foreach ($businesses as $biz): ?>
                                                        <option value="<?php echo (int)$biz['id']; ?>"
                                                            <?php echo ((int)$branch['business_id'] === (int)$biz['id']) ? 'selected' : ''; ?>>
                                                            <?php echo h($biz['business_name'] . ' (' . $biz['business_code'] . ')'); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Branch Name <span class="text-danger">*</span></label>
                                                <input type="text" name="branch_name" class="form-control"
                                                       value="<?php echo h($branch['branch_name']); ?>" required>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Branch Code <span class="text-danger">*</span></label>
                                                <input type="text" name="branch_code" class="form-control"
                                                       value="<?php echo h($branch['branch_code']); ?>" required>
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Contact Person</label>
                                                <input type="text" name="contact_person" class="form-control"
                                                       value="<?php echo h($branch['contact_person']); ?>">
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Email</label>
                                                <input type="email" name="email" class="form-control"
                                                       value="<?php echo h($branch['email']); ?>">
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Mobile</label>
                                                <input type="text" name="mobile" class="form-control"
                                                       value="<?php echo h($branch['mobile']); ?>">
                                            </div>
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Alternate Mobile</label>
                                        <input type="text" name="alternate_mobile" class="form-control"
                                               value="<?php echo h($branch['alternate_mobile']); ?>">
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">GSTIN</label>
                                        <input type="text" name="gstin" class="form-control"
                                               value="<?php echo h($branch['gstin']); ?>">
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Address Line 1</label>
                                        <input type="text" name="address_line1" class="form-control"
                                               value="<?php echo h($branch['address_line1']); ?>">
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Address Line 2</label>
                                        <input type="text" name="address_line2" class="form-control"
                                               value="<?php echo h($branch['address_line2']); ?>">
                                    </div>

                                    <div class="row">
                                        <div class="col-md-3">
                                            <div class="mb-3">
                                                <label class="form-label">City</label>
                                                <input type="text" name="city" class="form-control"
                                                       value="<?php echo h($branch['city']); ?>">
                                            </div>
                                        </div>

                                        <div class="col-md-3">
                                            <div class="mb-3">
                                                <label class="form-label">District</label>
                                                <input type="text" name="district" class="form-control"
                                                       value="<?php echo h($branch['district']); ?>">
                                            </div>
                                        </div>

                                        <div class="col-md-3">
                                            <div class="mb-3">
                                                <label class="form-label">State</label>
                                                <input type="text" name="state" class="form-control"
                                                       value="<?php echo h($branch['state']); ?>">
                                            </div>
                                        </div>

                                        <div class="col-md-3">
                                            <div class="mb-3">
                                                <label class="form-label">Pincode</label>
                                                <input type="text" name="pincode" class="form-control"
                                                       value="<?php echo h($branch['pincode']); ?>">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-xl-4">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="card-title mb-4">Branch Settings</h4>

                                    <div class="mb-3">
                                        <label class="form-label">Status</label>
                                        <select name="status" class="form-select">
                                            <option value="active" <?php echo ($branch['status'] === 'active') ? 'selected' : ''; ?>>Active</option>
                                            <option value="inactive" <?php echo ($branch['status'] === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                                        </select>
                                    </div>

                                    <div class="form-check mb-3">
                                        <input type="checkbox" class="form-check-input" id="is_head_office" name="is_head_office"
                                            <?php echo ((int)$branch['is_head_office'] === 1) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="is_head_office">Set as Head Office</label>
                                    </div>

                                    <div class="alert alert-info mb-0">
                                        If selected as head office, other head office branch of that business will be unset automatically.
                                    </div>
                                </div>
                            </div>

                            <div class="card">
                                <div class="card-body">
                                    <div class="d-grid gap-2">
                                        <button type="submit" class="btn btn-primary">Update Branch</button>
                                        <a href="branches.php" class="btn btn-secondary">Back to Branches</a>
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