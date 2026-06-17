<?php
session_start();
require_once 'config.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not available. Check config.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Enter username and password';
    } else {
        $sql = "SELECT bu.id, bu.business_id, bu.branch_id, bu.full_name, bu.username, bu.password_hash, bu.role, bu.status,
                       b.business_name, b.gstin, b.status AS business_status
                FROM business_users bu
                INNER JOIN businesses b ON b.id = bu.business_id
                WHERE bu.username = ?
                LIMIT 1";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            $error = 'Database error. Please try again.';
        } else {
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();
            $stmt->close();

            if (!$user) {
                $error = 'Invalid username or password';
            } elseif ((int)$user['status'] !== 1) {
                $error = 'User account inactive';
            } elseif ($user['business_status'] !== 'active') {
                $error = 'Business inactive';
            } elseif (!password_verify($password, $user['password_hash'])) {
                $error = 'Invalid username or password';
            } else {
                session_regenerate_id(true);

                $_SESSION['platform_admin_id'] = $user['id'];
                $_SESSION['business_user_id'] = $user['id'];
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['business_id'] = $user['business_id'];
                $_SESSION['branch_id'] = $user['branch_id'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['user_role'] = $user['role'];
                $_SESSION['business_name'] = $user['business_name'];
                $_SESSION['business_gstin'] = $user['gstin'];

                header('Location: business-admin/');
                exit;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bike Showroom ERP Login</title>

    <link rel="apple-touch-icon" sizes="180x180" href="/ebike/fav/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/ebike/fav/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/ebike/fav/favicon-16x16.png">
    <link rel="manifest" href="/ebike/fav/site.webmanifest">
    <meta name="theme-color" content="#2563eb">

    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: linear-gradient(135deg, #f4f6f9, #e9eef5);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }

        .login-wrapper {
            width: 100%;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .box {
            width: 100%;
            max-width: 380px;
            background: #fff;
            padding: 30px 24px;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
        }

        .brand-title {
            margin: 0 0 8px;
            text-align: center;
            font-size: 26px;
            font-weight: 700;
            color: #1e293b;
        }

        .subtitle {
            margin: 0 0 24px;
            text-align: center;
            font-size: 14px;
            color: #64748b;
        }

        .error {
            color: #dc2626;
            background: #fef2f2;
            border: 1px solid #fecaca;
            padding: 10px 12px;
            border-radius: 8px;
            margin-bottom: 14px;
            text-align: center;
            font-size: 14px;
        }

        .form-group {
            margin-bottom: 14px;
        }

        input {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            font-size: 14px;
            outline: none;
            transition: 0.2s ease;
            background: #fff;
        }

        input:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
        }

        button {
            width: 100%;
            padding: 12px;
            border: none;
            background: #2563eb;
            color: #fff;
            border-radius: 10px;
            cursor: pointer;
            font-size: 15px;
            font-weight: 600;
            transition: 0.2s ease;
        }

        button:hover {
            background: #1d4ed8;
        }

        #installAppBtn {
            display: none;
            margin-top: 10px;
            background: #16a34a;
        }

        #installAppBtn:hover {
            background: #15803d;
        }

        .install-help {
            margin-top: 12px;
            font-size: 13px;
            color: #64748b;
            text-align: center;
            line-height: 1.5;
        }
    </style>
</head>
<body>
    <div class="login-wrapper">
        <div class="box">
            <h1 class="brand-title">Bike Showroom ERP</h1>
            <p class="subtitle">Sign in to continue</p>

            <?php if ($error !== ''): ?>
                <div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <form method="post" autocomplete="off">
                <div class="form-group">
                    <input type="text" name="username" placeholder="Username" required>
                </div>

                <div class="form-group">
                    <input type="password" name="password" placeholder="Password" required>
                </div>

                <button type="submit">Login</button>
                <button type="button" id="installAppBtn">Install App</button>

                <div class="install-help" id="installHelp">
                    If install button is not shown, open Chrome menu <strong>(⋮)</strong> and tap
                    <strong>Install app</strong> or <strong>Add to Home screen</strong>.
                </div>
            </form>
        </div>
    </div>

    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function () {
                navigator.serviceWorker.register('/ebike/sw.js')
                    .then(function (registration) {
                        console.log('Service Worker registered:', registration.scope);
                    })
                    .catch(function (error) {
                        console.log('Service Worker registration failed:', error);
                    });
            });
        }

        let deferredPrompt = null;
        const installBtn = document.getElementById('installAppBtn');
        const installHelp = document.getElementById('installHelp');

        window.addEventListener('beforeinstallprompt', function (e) {
            e.preventDefault();
            deferredPrompt = e;
            installBtn.style.display = 'block';
            installHelp.style.display = 'none';
        });

        installBtn.addEventListener('click', async function () {
            if (!deferredPrompt) {
                alert('Install option is not available now. Please use Chrome menu (⋮) > Install app.');
                return;
            }

            deferredPrompt.prompt();
            const choiceResult = await deferredPrompt.userChoice;
            console.log('Install choice:', choiceResult.outcome);

            deferredPrompt = null;
            installBtn.style.display = 'none';
        });

        window.addEventListener('appinstalled', function () {
            installBtn.style.display = 'none';
            installHelp.style.display = 'none';
            console.log('App installed');
        });
    </script>
</body>
</html>