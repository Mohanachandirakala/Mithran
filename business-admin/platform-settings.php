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

// Fetch current platform settings
$stmt = $conn->prepare("SELECT * FROM platform_settings WHERE id = 1");
if ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();
    $settings = $result->fetch_assoc();
    $stmt->close();
    
    if (!$settings) {
        // Create default settings if not exists
        $insertStmt = $conn->prepare("
            INSERT INTO platform_settings (
                company_name, 
                company_email, 
                company_phone, 
                company_address, 
                timezone, 
                currency_symbol, 
                support_email, 
                support_phone, 
                updated_at
            ) VALUES (
                'Your Software Company', 
                'support@yourcompany.com', 
                '9999999999', 
                'Your Company Address', 
                'Asia/Kolkata', 
                '₹', 
                'support@yourcompany.com', 
                '9999999999', 
                NOW()
            )
        ");
        if ($insertStmt) {
            $insertStmt->execute();
            $insertStmt->close();
            
            // Fetch the newly created settings
            $stmt2 = $conn->prepare("SELECT * FROM platform_settings WHERE id = 1");
            if ($stmt2) {
                $stmt2->execute();
                $result2 = $stmt2->get_result();
                $settings = $result2->fetch_assoc();
                $stmt2->close();
            }
        }
    }
}

// Handle settings update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_settings') {
    $company_name = trim($_POST['company_name'] ?? '');
    $company_email = trim($_POST['company_email'] ?? '');
    $company_phone = trim($_POST['company_phone'] ?? '');
    $company_address = trim($_POST['company_address'] ?? '');
    $timezone = trim($_POST['timezone'] ?? 'Asia/Kolkata');
    $currency_symbol = trim($_POST['currency_symbol'] ?? '₹');
    $support_email = trim($_POST['support_email'] ?? '');
    $support_phone = trim($_POST['support_phone'] ?? '');
    
    if ($company_name === '') {
        $error = 'Company name is required.';
    } else {
        $updateStmt = $conn->prepare("
            UPDATE platform_settings SET 
                company_name = ?,
                company_email = ?,
                company_phone = ?,
                company_address = ?,
                timezone = ?,
                currency_symbol = ?,
                support_email = ?,
                support_phone = ?,
                updated_at = NOW()
            WHERE id = 1
        ");
        
        if ($updateStmt) {
            $updateStmt->bind_param(
                "ssssssss",
                $company_name,
                $company_email,
                $company_phone,
                $company_address,
                $timezone,
                $currency_symbol,
                $support_email,
                $support_phone
            );
            
            if ($updateStmt->execute()) {
                $success = 'Platform settings updated successfully.';
                // Refresh settings data
                $settings['company_name'] = $company_name;
                $settings['company_email'] = $company_email;
                $settings['company_phone'] = $company_phone;
                $settings['company_address'] = $company_address;
                $settings['timezone'] = $timezone;
                $settings['currency_symbol'] = $currency_symbol;
                $settings['support_email'] = $support_email;
                $settings['support_phone'] = $support_phone;
            } else {
                $error = 'Failed to update settings.';
            }
            $updateStmt->close();
        } else {
            $error = 'Database error. Please try again.';
        }
    }
}

// Handle logo upload (optional)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_logo') {
    if (isset($_FILES['company_logo']) && $_FILES['company_logo']['error'] == 0) {
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $file_type = $_FILES['company_logo']['type'];
        $file_size = $_FILES['company_logo']['size'];
        $max_size = 2 * 1024 * 1024; // 2MB
        
        if (!in_array($file_type, $allowed_types)) {
            $error = 'Only JPEG, PNG, GIF, and WEBP images are allowed.';
        } elseif ($file_size > $max_size) {
            $error = 'File size must be less than 2MB.';
        } else {
            $upload_dir = 'uploads/platform/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $file_extension = pathinfo($_FILES['company_logo']['name'], PATHINFO_EXTENSION);
            $file_name = 'platform_logo_' . time() . '.' . $file_extension;
            $file_path = $upload_dir . $file_name;
            
            if (move_uploaded_file($_FILES['company_logo']['tmp_name'], $file_path)) {
                // Update logo path in settings (you may need to add this column to platform_settings table)
                // For now, we'll just show success message
                $success = 'Company logo uploaded successfully.';
            } else {
                $error = 'Failed to upload logo.';
            }
        }
    } else {
        $error = 'Please select a file to upload.';
    }
}

$timezones = [
    'Asia/Kolkata' => 'India Time (IST)',
    'Asia/Dubai' => 'Dubai Time (GST)',
    'Asia/Singapore' => 'Singapore Time (SGT)',
    'Asia/Tokyo' => 'Japan Time (JST)',
    'America/New_York' => 'Eastern Time (ET)',
    'America/Los_Angeles' => 'Pacific Time (PT)',
    'Europe/London' => 'London Time (GMT)',
    'Europe/Paris' => 'Central European Time (CET)',
    'Australia/Sydney' => 'Australian Eastern Time (AET)'
];

$currency_symbols = [
    '₹' => 'Indian Rupee (₹)',
    '$' => 'US Dollar ($)',
    '€' => 'Euro (€)',
    '£' => 'British Pound (£)',
    '¥' => 'Japanese Yen (¥)',
    '₿' => 'Bitcoin (₿)',
    '₨' => 'Pakistani Rupee (₨)',
    '৳' => 'Bangladeshi Taka (৳)',
    '₩' => 'South Korean Won (₩)',
    '฿' => 'Thai Baht (฿)'
];

$pageTitle = 'Platform Settings';
$currentPage = 'platform-settings';
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
                    <div class="col-xl-12">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">General Settings</h4>

                                <form method="post" action="">
                                    <input type="hidden" name="action" value="update_settings">
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Company Name <span class="text-danger">*</span></label>
                                                <input type="text" name="company_name" class="form-control"
                                                    value="<?php echo h($settings['company_name'] ?? ''); ?>" required>
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Company Email</label>
                                                <input type="email" name="company_email" class="form-control"
                                                    value="<?php echo h($settings['company_email'] ?? ''); ?>">
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Company Phone</label>
                                                <input type="text" name="company_phone" class="form-control"
                                                    value="<?php echo h($settings['company_phone'] ?? ''); ?>">
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Currency Symbol</label>
                                                <select name="currency_symbol" class="form-select">
                                                    <?php foreach ($currency_symbols as $symbol => $name): ?>
                                                        <option value="<?php echo h($symbol); ?>" 
                                                            <?php echo (($settings['currency_symbol'] ?? '₹') === $symbol) ? 'selected' : ''; ?>>
                                                            <?php echo h($name); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Timezone</label>
                                                <select name="timezone" class="form-select">
                                                    <?php foreach ($timezones as $tz => $name): ?>
                                                        <option value="<?php echo h($tz); ?>" 
                                                            <?php echo (($settings['timezone'] ?? 'Asia/Kolkata') === $tz) ? 'selected' : ''; ?>>
                                                            <?php echo h($name); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Support Email</label>
                                                <input type="email" name="support_email" class="form-control"
                                                    value="<?php echo h($settings['support_email'] ?? ''); ?>">
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Support Phone</label>
                                                <input type="text" name="support_phone" class="form-control"
                                                    value="<?php echo h($settings['support_phone'] ?? ''); ?>">
                                            </div>
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Company Address</label>
                                        <textarea name="company_address" rows="3" class="form-control"><?php echo h($settings['company_address'] ?? ''); ?></textarea>
                                    </div>

                                    <div class="mb-0">
                                        <div class="d-grid gap-2">
                                            <button type="submit" class="btn btn-primary">Save Settings</button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-xl-12">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Company Branding</h4>

                                <form method="post" action="" enctype="multipart/form-data">
                                    <input type="hidden" name="action" value="upload_logo">
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Company Logo</label>
                                                <input type="file" name="company_logo" class="form-control" accept="image/*">
                                                <div class="form-text">Recommended size: 200x200px. Max size: 2MB. Allowed formats: JPEG, PNG, GIF, WEBP</div>
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">&nbsp;</label>
                                                <div class="d-grid gap-2">
                                                    <button type="submit" class="btn btn-secondary">Upload Logo</button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </form>

                                <?php if (!empty($settings['logo_path'])): ?>
                                <div class="mt-3">
                                    <label class="form-label">Current Logo:</label>
                                    <div>
                                        <img src="<?php echo h($settings['logo_path']); ?>" alt="Company Logo" style="max-height: 100px;">
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-xl-12">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">System Information</h4>

                                <div class="table-responsive">
                                    <table class="table table-bordered">
                                        <tbody>
                                            <tr>
                                                <th style="width: 250px;">PHP Version</th>
                                                <td><?php echo phpversion(); ?></td>
                                            </tr>
                                            <tr>
                                                <th>MySQL Version</th>
                                                <td><?php echo $conn->server_info; ?></td>
                                            </tr>
                                            <tr>
                                                <th>Server Software</th>
                                                <td><?php echo $_SERVER['SERVER_SOFTWARE'] ?? 'N/A'; ?></td>
                                            </tr>
                                            <tr>
                                                <th>Server Time</th>
                                                <td><?php echo date('d M Y, h:i:s A'); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Timezone</th>
                                                <td><?php echo date_default_timezone_get(); ?></td>
                                            </tr>
                                        </tbody>
                                    </table>
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