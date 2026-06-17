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

$userId = (int)($_GET['id'] ?? 0);
if ($userId <= 0) {
    header("Location: business-users.php");
    exit;
}

$success = '';
$error = '';

/* -------------------------------------------------------
   FETCH USER
------------------------------------------------------- */
$user = null;
$stmt = $conn->prepare("
    SELECT 
        id,
        business_id,
        branch_id,
        full_name,
        username,
        email,
        mobile,
        role,
        status
    FROM business_users
    WHERE id = ?
    LIMIT 1
");
if ($stmt) {
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result ? $result->fetch_assoc() : null;
    $stmt->close();
}

if (!$user) {
    header("Location: business-users.php");
    exit;
}

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
   FETCH BRANCHES BASED ON SELECTED BUSINESS
------------------------------------------------------- */
$selectedBusinessId = (int)($_POST['business_id'] ?? $user['business_id']);
$branches = [];

if ($selectedBusinessId > 0) {
    $stmt = $conn->prepare("
        SELECT id, branch_name, branch_code, status
        FROM branches
        WHERE business_id = ?
        ORDER BY branch_name ASC
    ");
    if ($stmt) {
        $stmt->bind_param("i", $selectedBusinessId);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $branches[] = $row;
            }
        }
        $stmt->close();
    }
}

/* -------------------------------------------------------
   HANDLE UPDATE
------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $business_id = (int)($_POST['business_id'] ?? 0);
    $branch_id   = (int)($_POST['branch_id'] ?? 0);
    $full_name   = trim($_POST['full_name'] ?? '');
    $username    = trim($_POST['username'] ?? '');
    $email       = trim($_POST['email'] ?? '');
    $mobile      = trim($_POST['mobile'] ?? '');
    $role        = trim($_POST['role'] ?? 'admin');
    $password    = (string)($_POST['password'] ?? '');
    $confirm     = (string)($_POST['confirm_password'] ?? '');
    $status      = isset($_POST['status']) ? 1 : 0;

    if ($business_id <= 0 || $full_name === '' || $username === '') {
        $error = 'Business, full name and username are required.';
    } elseif (!in_array($role, ['owner','admin','manager','sales','billing','service','store'], true)) {
        $error = 'Invalid role selected.';
    } else {
        if ($branch_id > 0) {
            $chkBranch = $conn->prepare("SELECT id FROM branches WHERE id = ? AND business_id = ? LIMIT 1");
            if ($chkBranch) {
                $chkBranch->bind_param("ii", $branch_id, $business_id);
                $chkBranch->execute();
                $chkRes = $chkBranch->get_result();
                $branchExists = $chkRes ? $chkRes->fetch_assoc() : null;
                $chkBranch->close();

                if (!$branchExists) {
                    $error = 'Selected branch does not belong to the selected business.';
                }
            }
        }

        if ($error === '') {
            $check = $conn->prepare("
                SELECT id
                FROM business_users
                WHERE business_id = ?
                  AND (username = ? OR email = ?)
                  AND id != ?
                LIMIT 1
            ");
            if ($check) {
                $check->bind_param("issi", $business_id, $username, $email, $userId);
                $check->execute();
                $checkRes = $check->get_result();
                $exists = $checkRes ? $checkRes->fetch_assoc() : null;
                $check->close();

                if ($exists) {
                    $error = 'Username or email already exists for this business.';
                } else {
                    if ($password !== '' || $confirm !== '') {
                        if ($password !== $confirm) {
                            $error = 'Password and confirm password do not match.';
                        } elseif (strlen($password) < 6) {
                            $error = 'Password must be at least 6 characters.';
                        }
                    }

                    if ($error === '') {
                        $branchValue = $branch_id > 0 ? $branch_id : null;

                        if ($password !== '') {
                            $passwordHash = password_hash($password, PASSWORD_DEFAULT);

                            $stmt = $conn->prepare("
                                UPDATE business_users SET
                                    business_id = ?,
                                    branch_id = ?,
                                    full_name = ?,
                                    username = ?,
                                    password_hash = ?,
                                    email = ?,
                                    mobile = ?,
                                    role = ?,
                                    status = ?
                                WHERE id = ?
                                LIMIT 1
                            ");

                            if ($stmt) {
                                $stmt->bind_param(
                                    "iissssssii",
                                    $business_id,
                                    $branchValue,
                                    $full_name,
                                    $username,
                                    $passwordHash,
                                    $email,
                                    $mobile,
                                    $role,
                                    $status,
                                    $userId
                                );
                            }
                        } else {
                            $stmt = $conn->prepare("
                                UPDATE business_users SET
                                    business_id = ?,
                                    branch_id = ?,
                                    full_name = ?,
                                    username = ?,
                                    email = ?,
                                    mobile = ?,
                                    role = ?,
                                    status = ?
                                WHERE id = ?
                                LIMIT 1
                            ");

                            if ($stmt) {
                                $stmt->bind_param(
                                    "iisssssii",
                                    $business_id,
                                    $branchValue,
                                    $full_name,
                                    $username,
                                    $email,
                                    $mobile,
                                    $role,
                                    $status,
                                    $userId
                                );
                            }
                        }

                        if (!empty($stmt)) {
                            if ($stmt->execute()) {
                                $success = 'Business user updated successfully.';
                            } else {
                                $error = 'Failed to update business user.';
                            }
                            $stmt->close();
                        } else {
                            $error = 'Database error. Please try again.';
                        }
                    }
                }
            } else {
                $error = 'Database error. Please try again.';
            }
        }
    }

    $stmt = $conn->prepare("
        SELECT 
            id,
            business_id,
            branch_id,
            full_name,
            username,
            email,
            mobile,
            role,
            status
        FROM business_users
        WHERE id = ?
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result ? $result->fetch_assoc() : null;
        $stmt->close();
    }

    $selectedBusinessId = (int)$user['business_id'];
    $branches = [];
    if ($selectedBusinessId > 0) {
        $stmt = $conn->prepare("
            SELECT id, branch_name, branch_code, status
            FROM branches
            WHERE business_id = ?
            ORDER BY branch_name ASC
        ");
        if ($stmt) {
            $stmt->bind_param("i", $selectedBusinessId);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $branches[] = $row;
                }
            }
            $stmt->close();
        }
    }
}

$pageTitle = 'Edit Business User';
$currentPage = 'business-users';
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
                                    <h4 class="card-title mb-4">User Details</h4>

                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Business <span class="text-danger">*</span></label>
                                                <select name="business_id" class="form-select" onchange="this.form.submit()" required>
                                                    <option value="">Select Business</option>
                                                    <?php foreach ($businesses as $biz): ?>
                                                        <option value="<?php echo (int)$biz['id']; ?>"
                                                            <?php echo ((int)$user['business_id'] === (int)$biz['id']) ? 'selected' : ''; ?>>
                                                            <?php echo h($biz['business_name'] . ' (' . $biz['business_code'] . ')'); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Branch</label>
                                                <select name="branch_id" class="form-select">
                                                    <option value="0">All Branches Access</option>
                                                    <?php foreach ($branches as $branch): ?>
                                                        <option value="<?php echo (int)$branch['id']; ?>"
                                                            <?php echo ((int)($user['branch_id'] ?? 0) === (int)$branch['id']) ? 'selected' : ''; ?>>
                                                            <?php echo h($branch['branch_name'] . ' (' . $branch['branch_code'] . ')'); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <small class="text-muted">Leave as “All Branches Access” if this user can access all branches.</small>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Full Name <span class="text-danger">*</span></label>
                                                <input type="text" name="full_name" class="form-control"
                                                    value="<?php echo h($user['full_name']); ?>" required>
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Username <span class="text-danger">*</span></label>
                                                <input type="text" name="username" class="form-control"
                                                    value="<?php echo h($user['username']); ?>" required>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Email</label>
                                                <input type="email" name="email" class="form-control"
                                                    value="<?php echo h($user['email']); ?>">
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Mobile</label>
                                                <input type="text" name="mobile" class="form-control"
                                                    value="<?php echo h($user['mobile']); ?>">
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label class="form-label">Role <span class="text-danger">*</span></label>
                                                <select name="role" class="form-select" required>
                                                    <option value="owner" <?php echo ($user['role'] === 'owner') ? 'selected' : ''; ?>>Owner</option>
                                                    <option value="admin" <?php echo ($user['role'] === 'admin') ? 'selected' : ''; ?>>Admin</option>
                                                    <option value="manager" <?php echo ($user['role'] === 'manager') ? 'selected' : ''; ?>>Manager</option>
                                                    <option value="sales" <?php echo ($user['role'] === 'sales') ? 'selected' : ''; ?>>Sales</option>
                                                    <option value="billing" <?php echo ($user['role'] === 'billing') ? 'selected' : ''; ?>>Billing</option>
                                                    <option value="service" <?php echo ($user['role'] === 'service') ? 'selected' : ''; ?>>Service</option>
                                                    <option value="store" <?php echo ($user['role'] === 'store') ? 'selected' : ''; ?>>Store</option>
                                                </select>
                                            </div>
                                        </div>

                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label class="form-label">New Password</label>
                                                <input type="password" name="password" class="form-control">
                                                <small class="text-muted">Leave blank if you do not want to change password.</small>
                                            </div>
                                        </div>

                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label class="form-label">Confirm New Password</label>
                                                <input type="password" name="confirm_password" class="form-control">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-xl-4">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="card-title mb-4">User Status</h4>

                                    <div class="form-check mb-3">
                                        <input type="checkbox" class="form-check-input" id="status" name="status"
                                            <?php echo ((int)$user['status'] === 1) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="status">Active User</label>
                                    </div>

                                    <div class="alert alert-info mb-0">
                                        This user belongs to the selected business. Branch can be specific or all-branch access.
                                    </div>
                                </div>
                            </div>

                            <div class="card">
                                <div class="card-body">
                                    <div class="d-grid gap-2">
                                        <button type="submit" class="btn btn-primary">Update Business User</button>
                                        <a href="business-users.php" class="btn btn-secondary">Back to Users</a>
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