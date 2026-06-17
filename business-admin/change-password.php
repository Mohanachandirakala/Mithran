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

if ($businessUserId <= 0 || $businessId <= 0) {
    header('Location: login.php');
    exit;
}

/* -------------------------------------------------------
   HELPERS
------------------------------------------------------- */
function h($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function tableExists(mysqli $conn, string $table): bool
{
    $sql = "SELECT 1
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('s', $table);
    $stmt->execute();
    $res = $stmt->get_result();
    $exists = $res && $res->num_rows > 0;
    $stmt->close();

    return $exists;
}

/* -------------------------------------------------------
   VALIDATE USER
------------------------------------------------------- */
if (!tableExists($conn, 'business_users') || !tableExists($conn, 'businesses')) {
    die('Required tables not found.');
}

$loggedUser = null;

$stmt = $conn->prepare("SELECT
                            bu.id,
                            bu.business_id,
                            bu.full_name,
                            bu.username,
                            bu.password_hash,
                            bu.status,
                            bu.role,
                            b.business_name,
                            b.status AS business_status
                        FROM business_users bu
                        INNER JOIN businesses b ON b.id = bu.business_id
                        WHERE bu.id = ? AND bu.business_id = ?
                        LIMIT 1");
if ($stmt) {
    $stmt->bind_param('ii', $businessUserId, $businessId);
    $stmt->execute();
    $loggedUser = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if (
    !$loggedUser ||
    (int)($loggedUser['status'] ?? 0) !== 1 ||
    ($loggedUser['business_status'] ?? '') !== 'active'
) {
    session_destroy();
    header('Location: login.php');
    exit;
}

/* -------------------------------------------------------
   FORM
------------------------------------------------------- */
$success = '';
$error = '';

$form = [
    'current_password' => '',
    'new_password' => '',
    'confirm_password' => '',
];

/* -------------------------------------------------------
   SAVE
------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['current_password'] = (string)($_POST['current_password'] ?? '');
    $form['new_password'] = (string)($_POST['new_password'] ?? '');
    $form['confirm_password'] = (string)($_POST['confirm_password'] ?? '');

    $currentPassword = $form['current_password'];
    $newPassword = $form['new_password'];
    $confirmPassword = $form['confirm_password'];

    if ($currentPassword === '') {
        $error = 'Current password is required.';
    } elseif ($newPassword === '') {
        $error = 'New password is required.';
    } elseif ($confirmPassword === '') {
        $error = 'Confirm password is required.';
    } elseif (!password_verify($currentPassword, (string)$loggedUser['password_hash'])) {
        $error = 'Current password is incorrect.';
    } elseif (strlen($newPassword) < 6) {
        $error = 'New password must be at least 6 characters.';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'New password and confirm password do not match.';
    } elseif ($currentPassword === $newPassword) {
        $error = 'New password must be different from current password.';
    } else {
        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

        $stmt = $conn->prepare("UPDATE business_users
                                SET password_hash = ?
                                WHERE id = ? AND business_id = ?
                                LIMIT 1");
        if (!$stmt) {
            $error = 'Failed to prepare password update query.';
        } else {
            $stmt->bind_param('sii', $newHash, $businessUserId, $businessId);

            if ($stmt->execute()) {
                $success = 'Password changed successfully.';
                $form = [
                    'current_password' => '',
                    'new_password' => '',
                    'confirm_password' => '',
                ];
            } else {
                $error = 'Failed to update password.';
            }
            $stmt->close();
        }
    }
}

$pageTitle = 'Change Password';
$currentPage = 'change-password';
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

                <div class="row mb-3">
                    <div class="col-md-7">
                        <h4 class="mb-1">Change Password</h4>
                        <p class="text-muted mb-0">Update your login password securely</p>
                    </div>
                    <div class="col-md-5 text-md-end mt-3 mt-md-0">
                        <a href="index.php" class="btn btn-secondary">Back to Dashboard</a>
                    </div>
                </div>

                <?php if ($success !== ''): ?>
                    <div class="alert alert-success"><?php echo h($success); ?></div>
                <?php endif; ?>

                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger"><?php echo h($error); ?></div>
                <?php endif; ?>

                <div class="row">
                    <div class="col-12">

                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="mb-0">User Information</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-4 mb-2">
                                        <strong>Name:</strong> <?php echo h($loggedUser['full_name'] ?? '-'); ?>
                                    </div>
                                    <div class="col-md-4 mb-2">
                                        <strong>Username:</strong> <?php echo h($loggedUser['username'] ?? '-'); ?>
                                    </div>
                                    <div class="col-md-4 mb-2">
                                        <strong>Role:</strong> <?php echo h(ucwords(str_replace('_', ' ', (string)($loggedUser['role'] ?? '-')))); ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <form method="post" autocomplete="off">
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="mb-0">Change Password</h5>
                                </div>
                                <div class="card-body">
                                    <div class="row g-3">

                                        <div class="col-md-4">
                                            <label class="form-label">Current Password <span class="text-danger">*</span></label>
                                            <input
                                                type="password"
                                                name="current_password"
                                                class="form-control"
                                                value="<?php echo h($form['current_password']); ?>"
                                                required
                                            >
                                        </div>

                                        <div class="col-md-4">
                                            <label class="form-label">New Password <span class="text-danger">*</span></label>
                                            <input
                                                type="password"
                                                name="new_password"
                                                class="form-control"
                                                value="<?php echo h($form['new_password']); ?>"
                                                required
                                            >
                                            <div class="small text-muted mt-1">Minimum 6 characters.</div>
                                        </div>

                                        <div class="col-md-4">
                                            <label class="form-label">Confirm New Password <span class="text-danger">*</span></label>
                                            <input
                                                type="password"
                                                name="confirm_password"
                                                class="form-control"
                                                value="<?php echo h($form['confirm_password']); ?>"
                                                required
                                            >
                                        </div>

                                    </div>
                                </div>
                                <div class="card-footer d-flex gap-2">
                                    <button type="submit" class="btn btn-primary">Update Password</button>
                                    <a href="index.php" class="btn btn-light">Cancel</a>
                                </div>
                            </div>
                        </form>

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