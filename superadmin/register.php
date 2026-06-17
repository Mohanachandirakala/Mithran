<?php
date_default_timezone_set('Asia/Kolkata');
require_once 'includes/config.php';

if (isset($_SESSION['platform_admin_id']) && (int)$_SESSION['platform_admin_id'] > 0) {
    header("Location: index.php");
    exit;
}

$success = '';
$error = '';

function h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $username  = trim($_POST['username'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $mobile    = trim($_POST['mobile'] ?? '');
    $password  = (string)($_POST['password'] ?? '');
    $confirm   = (string)($_POST['confirm_password'] ?? '');

    if ($full_name === '' || $username === '' || $password === '' || $confirm === '') {
        $error = 'Please fill all required fields.';
    } elseif ($password !== $confirm) {
        $error = 'Password and confirm password do not match.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } else {
        $check = $conn->prepare("SELECT id FROM platform_admins WHERE username = ? OR email = ? LIMIT 1");
        if ($check) {
            $check->bind_param("ss", $username, $email);
            $check->execute();
            $res = $check->get_result();
            $exists = $res ? $res->fetch_assoc() : null;
            $check->close();

            if ($exists) {
                $error = 'Username or email already exists.';
            } else {
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                $role = 'super_admin';
                $status = 1;

                $stmt = $conn->prepare("INSERT INTO platform_admins (full_name, username, password_hash, email, mobile, role, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
                if ($stmt) {
                    $stmt->bind_param("ssssssi", $full_name, $username, $password_hash, $email, $mobile, $role, $status);

                    if ($stmt->execute()) {
                        $success = 'Super admin created successfully. You can login now.';
                        $_POST = [];
                    } else {
                        $error = 'Failed to create super admin.';
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
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title>Super Admin Register</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="assets/css/bootstrap.min.css" rel="stylesheet" type="text/css">
    <link href="assets/css/icons.min.css" rel="stylesheet" type="text/css">
    <link href="assets/css/app.min.css" rel="stylesheet" type="text/css">

    <style>
        body.register-page {
            min-height: 100vh;
            margin: 0;
            background: #f8f9fa;
        }

        .register-wrapper {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }

        .register-card {
            width: 100%;
            max-width: 520px;
        }
    </style>
</head>

<body class="register-page">

    <div class="register-wrapper">
        <div class="register-card">
            <div class="card shadow-sm mb-0">
                <div class="card-body p-4 p-sm-5">
                    <div class="text-center mb-4">
                        <h4 class="mb-1">Create Super Admin</h4>
                        <p class="text-muted mb-0">Register platform administrator account</p>
                    </div>

                    <?php if ($success !== ''): ?>
                        <div class="alert alert-success"><?php echo h($success); ?></div>
                    <?php endif; ?>

                    <?php if ($error !== ''): ?>
                        <div class="alert alert-danger"><?php echo h($error); ?></div>
                    <?php endif; ?>

                    <form method="post" action="">
                        <div class="mb-3">
                            <label class="form-label">Full Name <span class="text-danger">*</span></label>
                            <input type="text" name="full_name" class="form-control" value="<?php echo h($_POST['full_name'] ?? ''); ?>" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Username <span class="text-danger">*</span></label>
                            <input type="text" name="username" class="form-control" value="<?php echo h($_POST['username'] ?? ''); ?>" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control" value="<?php echo h($_POST['email'] ?? ''); ?>">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Mobile</label>
                            <input type="text" name="mobile" class="form-control" value="<?php echo h($_POST['mobile'] ?? ''); ?>">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Password <span class="text-danger">*</span></label>
                            <input type="password" name="password" class="form-control" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Confirm Password <span class="text-danger">*</span></label>
                            <input type="password" name="confirm_password" class="form-control" required>
                        </div>

                        <div class="d-grid mt-4">
                            <button class="btn btn-primary" type="submit">Create Super Admin</button>
                        </div>

                        <div class="text-center mt-3">
                            <a href="login.php" class="text-primary">Back to Login</a>
                        </div>
                    </form>

                    <div class="text-center mt-4 text-muted">
                        © <?php echo date('Y'); ?> Platform Admin
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="assets/libs/jquery/jquery.min.js"></script>
    <script src="assets/libs/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>

</html>