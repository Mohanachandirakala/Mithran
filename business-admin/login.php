<?php
session_start();
require_once 'includes/config.php';

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
// business_user_id
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
            header('Location: index.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login</title>
    <style>
        body{
            font-family: Arial, sans-serif;
            background:#f4f4f4;
            display:flex;
            justify-content:center;
            align-items:center;
            height:100vh;
            margin:0;
        }
        .box{
            background:#fff;
            padding:25px;
            width:320px;
            border:1px solid #ddd;
            border-radius:8px;
        }
        h2{
            margin:0 0 20px;
            text-align:center;
        }
        input{
            width:100%;
            padding:10px;
            margin-bottom:12px;
            border:1px solid #ccc;
            border-radius:4px;
            box-sizing:border-box;
        }
        button{
            width:100%;
            padding:10px;
            border:none;
            background:#007bff;
            color:#fff;
            border-radius:4px;
            cursor:pointer;
        }
        button:hover{
            background:#0056b3;
        }
        .error{
            color:red;
            margin-bottom:12px;
            text-align:center;
        }
    </style>
</head>
<body>
    <div class="box">
        <h2>Login</h2>

        <?php if ($error != ''): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="post">
            <input type="text" name="username" placeholder="Username" required>
            <input type="password" name="password" placeholder="Password" required>
            <button type="submit">Login</button>
        </form>
    </div>
</body>
</html>