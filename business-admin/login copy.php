<?php
date_default_timezone_set('Asia/Kolkata');
require_once 'includes/config.php';
// echo $_SESSION['business_user_id'];die;

if (isset($_SESSION['platform_admin_id']) && (int)$_SESSION['platform_admin_id'] > 0) {
    header("Location: index.php");
    exit;
}

$error = '';

function h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'Please enter username and password.';
    } else {
        $stmt = $conn->prepare("SELECT id, full_name, username, password_hash, status FROM platform_admins WHERE username = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();
            $admin = $result ? $result->fetch_assoc() : null;
            $stmt->close();

            if ($admin && (int)$admin['status'] === 1 && password_verify($password, $admin['password_hash'])) {
                $_SESSION['platform_admin_id'] = (int)$admin['id'];
                $_SESSION['platform_admin_name'] = $admin['full_name'];
                $_SESSION['platform_admin_username'] = $admin['username'];

                $up = $conn->prepare("UPDATE platform_admins SET last_login_at = NOW() WHERE id = ?");
                if ($up) {
                    $up->bind_param("i", $admin['id']);
                    $up->execute();
                    $up->close();
                }

                header("Location: index.php");
                exit;
            } else {
                $error = 'Invalid username or password.';
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
    <title>Platform Admin Login</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="assets/css/bootstrap.min.css" rel="stylesheet" type="text/css">
    <link href="assets/css/icons.min.css" rel="stylesheet" type="text/css">
    <link href="assets/css/app.min.css" rel="stylesheet" type="text/css">

    <style>
        body.login-centered-page {
            min-height: 100vh;
            margin: 0;
            background: #f8f9fa;
        }

        .login-wrapper {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }

        .login-card {
            width: 100%;
            max-width: 420px;
        }
    </style>
</head>

<body class="login-centered-page">

    <div class="login-wrapper">
        <div class="login-card">
            <div class="card shadow-sm mb-0">
                <div class="card-body p-4 p-sm-5">
                    <div class="text-center mb-4">
                        <h4 class="mb-1">Platform Admin Login</h4>
                        <p class="text-muted mb-0">Sign in to your control panel</p>
                    </div>

                    <?php if ($error !== ''): ?>
                        <div class="alert alert-danger"><?php echo h($error); ?></div>
                    <?php endif; ?>

                    <form method="post" action="">
                        <div class="mb-3">
                            <label class="form-label">Username</label>
                            <input type="text" name="username" class="form-control" value="<?php echo h($_POST['username'] ?? ''); ?>" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Password</label>
                            <input type="password" name="password" class="form-control" required>
                        </div>

                        <div class="d-grid mt-4">
                            <button class="btn btn-primary" type="submit">Login</button>
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