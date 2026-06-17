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

$admin = null;

$stmt = $conn->prepare("SELECT id, full_name, username, email, mobile, role, status, last_login_at, created_at 
                        FROM platform_admins 
                        WHERE id = ? 
                        LIMIT 1");
if ($stmt) {
    $stmt->bind_param("i", $platformAdminId);
    $stmt->execute();
    $result = $stmt->get_result();
    $admin = $result ? $result->fetch_assoc() : null;
    $stmt->close();
}

if (!$admin) {
    session_destroy();
    header("Location: login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $full_name = trim($_POST['full_name'] ?? '');
        $email     = trim($_POST['email'] ?? '');
        $mobile    = trim($_POST['mobile'] ?? '');

        if ($full_name === '') {
            $error = 'Full name is required.';
        } else {
            $check = $conn->prepare("SELECT id FROM platform_admins WHERE email = ? AND id != ? LIMIT 1");
            if ($check) {
                $check->bind_param("si", $email, $platformAdminId);
                $check->execute();
                $checkRes = $check->get_result();
                $exists = $checkRes ? $checkRes->fetch_assoc() : null;
                $check->close();

                if ($email !== '' && $exists) {
                    $error = 'Email already exists.';
                } else {
                    $up = $conn->prepare("UPDATE platform_admins SET full_name = ?, email = ?, mobile = ? WHERE id = ?");
                    if ($up) {
                        $up->bind_param("sssi", $full_name, $email, $mobile, $platformAdminId);
                        if ($up->execute()) {
                            $_SESSION['platform_admin_name'] = $full_name;
                            $success = 'Profile updated successfully.';
                        } else {
                            $error = 'Failed to update profile.';
                        }
                        $up->close();
                    } else {
                        $error = 'Database error while updating profile.';
                    }
                }
            } else {
                $error = 'Database error while checking email.';
            }
        }
    }

    if ($action === 'change_password') {
        $current_password = (string)($_POST['current_password'] ?? '');
        $new_password     = (string)($_POST['new_password'] ?? '');
        $confirm_password = (string)($_POST['confirm_password'] ?? '');

        if ($current_password === '' || $new_password === '' || $confirm_password === '') {
            $error = 'All password fields are required.';
        } elseif ($new_password !== $confirm_password) {
            $error = 'New password and confirm password do not match.';
        } elseif (strlen($new_password) < 6) {
            $error = 'New password must be at least 6 characters.';
        } else {
            $pwStmt = $conn->prepare("SELECT password_hash FROM platform_admins WHERE id = ? LIMIT 1");
            if ($pwStmt) {
                $pwStmt->bind_param("i", $platformAdminId);
                $pwStmt->execute();
                $pwRes = $pwStmt->get_result();
                $pwRow = $pwRes ? $pwRes->fetch_assoc() : null;
                $pwStmt->close();

                if (!$pwRow || !password_verify($current_password, $pwRow['password_hash'])) {
                    $error = 'Current password is incorrect.';
                } else {
                    $newHash = password_hash($new_password, PASSWORD_DEFAULT);
                    $chg = $conn->prepare("UPDATE platform_admins SET password_hash = ? WHERE id = ?");
                    if ($chg) {
                        $chg->bind_param("si", $newHash, $platformAdminId);
                        if ($chg->execute()) {
                            $success = 'Password changed successfully.';
                        } else {
                            $error = 'Failed to change password.';
                        }
                        $chg->close();
                    } else {
                        $error = 'Database error while changing password.';
                    }
                }
            } else {
                $error = 'Database error while verifying password.';
            }
        }
    }

    $stmt = $conn->prepare("SELECT id, full_name, username, email, mobile, role, status, last_login_at, created_at 
                            FROM platform_admins 
                            WHERE id = ? 
                            LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("i", $platformAdminId);
        $stmt->execute();
        $result = $stmt->get_result();
        $admin = $result ? $result->fetch_assoc() : null;
        $stmt->close();
    }
}

$pageTitle = 'My Profile';
$currentPage = 'platform-admin-profile';
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

                <div class="row">
                    <div class="col-xl-4">
                        <div class="card">
                            <div class="card-body text-center">
                                <div class="mb-4">
                                    <div class="avatar-xl mx-auto mb-3">
                                        <div class="avatar-title rounded-circle bg-primary-subtle text-primary font-size-24">
                                            <?php echo h(strtoupper(substr($admin['full_name'], 0, 1))); ?>
                                        </div>
                                    </div>
                                    <h4 class="mb-1"><?php echo h($admin['full_name']); ?></h4>
                                    <p class="text-muted mb-1">@<?php echo h($admin['username']); ?></p>
                                    <span class="badge bg-primary">
                                        <?php echo h(ucwords(str_replace('_', ' ', $admin['role']))); ?>
                                    </span>
                                </div>

                                <div class="table-responsive">
                                    <table class="table table-borderless mb-0">
                                        <tbody>
                                            <tr>
                                                <th class="text-start">Email :</th>
                                                <td class="text-end"><?php echo h($admin['email'] ?: '-'); ?></td>
                                            </tr>
                                            <tr>
                                                <th class="text-start">Mobile :</th>
                                                <td class="text-end"><?php echo h($admin['mobile'] ?: '-'); ?></td>
                                            </tr>
                                            <tr>
                                                <th class="text-start">Status :</th>
                                                <td class="text-end">
                                                    <?php if ((int)$admin['status'] === 1): ?>
                                                        <span class="badge bg-success">Active</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-danger">Inactive</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <tr>
                                                <th class="text-start">Last Login :</th>
                                                <td class="text-end">
                                                    <?php echo !empty($admin['last_login_at']) ? h(date('d M Y h:i A', strtotime($admin['last_login_at']))) : '-'; ?>
                                                </td>
                                            </tr>
                                            <tr>
                                                <th class="text-start">Created At :</th>
                                                <td class="text-end">
                                                    <?php echo !empty($admin['created_at']) ? h(date('d M Y h:i A', strtotime($admin['created_at']))) : '-'; ?>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>

                            </div>
                        </div>
                    </div>

                    <div class="col-xl-8">
                        <div class="card">
                            <div class="card-body">
                                <ul class="nav nav-tabs nav-tabs-custom nav-justified" role="tablist">
                                    <li class="nav-item">
                                        <a class="nav-link active" data-bs-toggle="tab" href="#profileTab" role="tab">
                                            <span class="d-block d-sm-none"><i class="fas fa-user"></i></span>
                                            <span class="d-none d-sm-block">Update Profile</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link" data-bs-toggle="tab" href="#passwordTab" role="tab">
                                            <span class="d-block d-sm-none"><i class="fas fa-lock"></i></span>
                                            <span class="d-none d-sm-block">Change Password</span>
                                        </a>
                                    </li>
                                </ul>

                                <div class="tab-content p-3 text-muted">
                                    <div class="tab-pane active" id="profileTab" role="tabpanel">
                                        <form method="post">
                                            <input type="hidden" name="action" value="update_profile">

                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label class="form-label">Full Name</label>
                                                        <input type="text" name="full_name" class="form-control"
                                                            value="<?php echo h($admin['full_name']); ?>" required>
                                                    </div>
                                                </div>

                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label class="form-label">Username</label>
                                                        <input type="text" class="form-control"
                                                            value="<?php echo h($admin['username']); ?>" readonly>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label class="form-label">Email</label>
                                                        <input type="email" name="email" class="form-control"
                                                            value="<?php echo h($admin['email']); ?>">
                                                    </div>
                                                </div>

                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label class="form-label">Mobile</label>
                                                        <input type="text" name="mobile" class="form-control"
                                                            value="<?php echo h($admin['mobile']); ?>">
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="mb-3">
                                                <label class="form-label">Role</label>
                                                <input type="text" class="form-control"
                                                    value="<?php echo h(ucwords(str_replace('_', ' ', $admin['role']))); ?>" readonly>
                                            </div>

                                            <div class="text-end">
                                                <button type="submit" class="btn btn-primary">Update Profile</button>
                                            </div>
                                        </form>
                                    </div>

                                    <div class="tab-pane" id="passwordTab" role="tabpanel">
                                        <form method="post">
                                            <input type="hidden" name="action" value="change_password">

                                            <div class="mb-3">
                                                <label class="form-label">Current Password</label>
                                                <input type="password" name="current_password" class="form-control" required>
                                            </div>

                                            <div class="mb-3">
                                                <label class="form-label">New Password</label>
                                                <input type="password" name="new_password" class="form-control" required>
                                            </div>

                                            <div class="mb-3">
                                                <label class="form-label">Confirm New Password</label>
                                                <input type="password" name="confirm_password" class="form-control" required>
                                            </div>

                                            <div class="text-end">
                                                <button type="submit" class="btn btn-danger">Change Password</button>
                                            </div>
                                        </form>
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