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

function generateCustomerCode(mysqli $conn, int $businessId): string
{
    $prefix = 'CUST';
    $nextNo = 1;

    $stmt = $conn->prepare("SELECT id FROM customers WHERE business_id = ? ORDER BY id DESC LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $businessId);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($res && isset($res['id'])) {
            $nextNo = ((int)$res['id']) + 1;
        }
    }

    return $prefix . str_pad((string)$nextNo, 5, '0', STR_PAD_LEFT);
}

/* -------------------------------------------------------
   VALIDATE USER
------------------------------------------------------- */
$loggedUser = null;

if (tableExists($conn, 'business_users') && tableExists($conn, 'businesses')) {
    $stmt = $conn->prepare("SELECT 
                                bu.id,
                                bu.full_name,
                                bu.role,
                                bu.status,
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
}

if (!$loggedUser || (int)$loggedUser['status'] !== 1 || ($loggedUser['business_status'] ?? '') !== 'active') {
    session_destroy();
    header('Location: login.php');
    exit;
}

/* -------------------------------------------------------
   TABLE CHECK
------------------------------------------------------- */
if (!tableExists($conn, 'customers')) {
    die('customers table not found.');
}

/* -------------------------------------------------------
   DEFAULT VALUES
------------------------------------------------------- */
$success = '';
$error = '';

$form = [
    'customer_code'        => generateCustomerCode($conn, $businessId),
    'full_name'            => '',
    'mobile'               => '',
    'alternate_mobile'     => '',
    'email'                => '',
    'dob'                  => '',
    'gender'               => '',
    'aadhar_no'            => '',
    'pan_no'               => '',
    'driving_license_no'   => '',
    'gstin'                => '',
    'address_line1'        => '',
    'address_line2'        => '',
    'city'                 => '',
    'district'             => '',
    'state'                => '',
    'pincode'              => '',
    'notes'                => ''
];

/* -------------------------------------------------------
   SAVE CUSTOMER
------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['customer_code']      = trim($_POST['customer_code'] ?? generateCustomerCode($conn, $businessId));
    $form['full_name']          = trim($_POST['full_name'] ?? '');
    $form['mobile']             = trim($_POST['mobile'] ?? '');
    $form['alternate_mobile']   = trim($_POST['alternate_mobile'] ?? '');
    $form['email']              = trim($_POST['email'] ?? '');
    $form['dob']                = trim($_POST['dob'] ?? '');
    $form['gender']             = trim($_POST['gender'] ?? '');
    $form['aadhar_no']          = trim($_POST['aadhar_no'] ?? '');
    $form['pan_no']             = trim($_POST['pan_no'] ?? '');
    $form['driving_license_no'] = trim($_POST['driving_license_no'] ?? '');
    $form['gstin']              = trim($_POST['gstin'] ?? '');
    $form['address_line1']      = trim($_POST['address_line1'] ?? '');
    $form['address_line2']      = trim($_POST['address_line2'] ?? '');
    $form['city']               = trim($_POST['city'] ?? '');
    $form['district']           = trim($_POST['district'] ?? '');
    $form['state']              = trim($_POST['state'] ?? '');
    $form['pincode']            = trim($_POST['pincode'] ?? '');
    $form['notes']              = trim($_POST['notes'] ?? '');

    if ($form['full_name'] === '') {
        $error = 'Customer name is required.';
    } elseif ($form['mobile'] === '') {
        $error = 'Mobile number is required.';
    } elseif (!in_array($form['gender'], ['', 'male', 'female', 'other'], true)) {
        $error = 'Invalid gender selected.';
    } else {
        $stmt = $conn->prepare("SELECT id 
                                FROM customers 
                                WHERE business_id = ? AND mobile = ?
                                LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('is', $businessId, $form['mobile']);
            $stmt->execute();
            $dup = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($dup) {
                $error = 'Customer with this mobile number already exists.';
            }
        }
    }

    if ($error === '' && $form['customer_code'] !== '') {
        $stmt = $conn->prepare("SELECT id 
                                FROM customers 
                                WHERE business_id = ? AND customer_code = ?
                                LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('is', $businessId, $form['customer_code']);
            $stmt->execute();
            $dupCode = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($dupCode) {
                $form['customer_code'] = generateCustomerCode($conn, $businessId);
            }
        }
    }

    if ($error === '') {
        $stmt = $conn->prepare("INSERT INTO customers (
                                    business_id,
                                    customer_code,
                                    full_name,
                                    mobile,
                                    alternate_mobile,
                                    email,
                                    dob,
                                    gender,
                                    aadhar_no,
                                    pan_no,
                                    driving_license_no,
                                    gstin,
                                    address_line1,
                                    address_line2,
                                    city,
                                    district,
                                    state,
                                    pincode,
                                    notes,
                                    created_at,
                                    updated_at
                                ) VALUES (
                                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW()
                                )");

        if ($stmt) {
            $stmt->bind_param(
                'issssssssssssssssss',
                $businessId,
                $form['customer_code'],
                $form['full_name'],
                $form['mobile'],
                $form['alternate_mobile'],
                $form['email'],
                $form['dob'],
                $form['gender'],
                $form['aadhar_no'],
                $form['pan_no'],
                $form['driving_license_no'],
                $form['gstin'],
                $form['address_line1'],
                $form['address_line2'],
                $form['city'],
                $form['district'],
                $form['state'],
                $form['pincode'],
                $form['notes']
            );

            if ($stmt->execute()) {
                header('Location: customers.php?success=Customer added successfully');
                exit;
            } else {
                $error = 'Failed to save customer.';
            }
            $stmt->close();
        } else {
            $error = 'Unable to prepare insert query.';
        }
    }
}

$pageTitle = 'Add Customer';
$currentPage = 'customers';
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
                    <div class="col-md-6">
                        <h4 class="mb-1">Add Customer</h4>
                        <p class="text-muted mb-0">Create a new customer</p>
                    </div>
                    <div class="col-md-6 text-md-end mt-3 mt-md-0">
                        <a href="customers.php" class="btn btn-secondary">Back</a>
                    </div>
                </div>

                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger"><?php echo h($error); ?></div>
                <?php endif; ?>

                <div class="card">
                    <div class="card-body">
                        <form method="post">
                            <div class="row">

                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Customer Code</label>
                                    <input type="text" name="customer_code" class="form-control" value="<?php echo h($form['customer_code']); ?>">
                                </div>

                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Full Name <span class="text-danger">*</span></label>
                                    <input type="text" name="full_name" class="form-control" value="<?php echo h($form['full_name']); ?>" required>
                                </div>

                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Mobile <span class="text-danger">*</span></label>
                                    <input type="text" name="mobile" class="form-control" value="<?php echo h($form['mobile']); ?>" required>
                                </div>

                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Alternate Mobile</label>
                                    <input type="text" name="alternate_mobile" class="form-control" value="<?php echo h($form['alternate_mobile']); ?>">
                                </div>

                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Email</label>
                                    <input type="email" name="email" class="form-control" value="<?php echo h($form['email']); ?>">
                                </div>

                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Date of Birth</label>
                                    <input type="date" name="dob" class="form-control" value="<?php echo h($form['dob']); ?>">
                                </div>

                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Gender</label>
                                    <select name="gender" class="form-select">
                                        <option value="">Select Gender</option>
                                        <option value="male" <?php echo ($form['gender'] === 'male') ? 'selected' : ''; ?>>Male</option>
                                        <option value="female" <?php echo ($form['gender'] === 'female') ? 'selected' : ''; ?>>Female</option>
                                        <option value="other" <?php echo ($form['gender'] === 'other') ? 'selected' : ''; ?>>Other</option>
                                    </select>
                                </div>

                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Aadhar No</label>
                                    <input type="text" name="aadhar_no" class="form-control" value="<?php echo h($form['aadhar_no']); ?>">
                                </div>

                                <div class="col-md-4 mb-3">
                                    <label class="form-label">PAN No</label>
                                    <input type="text" name="pan_no" class="form-control" value="<?php echo h($form['pan_no']); ?>">
                                </div>

                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Driving License No</label>
                                    <input type="text" name="driving_license_no" class="form-control" value="<?php echo h($form['driving_license_no']); ?>">
                                </div>

                                <div class="col-md-4 mb-3">
                                    <label class="form-label">GSTIN</label>
                                    <input type="text" name="gstin" class="form-control" value="<?php echo h($form['gstin']); ?>">
                                </div>

                                <div class="col-md-12 mb-3">
                                    <label class="form-label">Address Line 1</label>
                                    <input type="text" name="address_line1" class="form-control" value="<?php echo h($form['address_line1']); ?>">
                                </div>

                                <div class="col-md-12 mb-3">
                                    <label class="form-label">Address Line 2</label>
                                    <input type="text" name="address_line2" class="form-control" value="<?php echo h($form['address_line2']); ?>">
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">City</label>
                                    <input type="text" name="city" class="form-control" value="<?php echo h($form['city']); ?>">
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">District</label>
                                    <input type="text" name="district" class="form-control" value="<?php echo h($form['district']); ?>">
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">State</label>
                                    <input type="text" name="state" class="form-control" value="<?php echo h($form['state']); ?>">
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Pincode</label>
                                    <input type="text" name="pincode" class="form-control" value="<?php echo h($form['pincode']); ?>">
                                </div>

                                <div class="col-md-12 mb-3">
                                    <label class="form-label">Notes</label>
                                    <textarea name="notes" class="form-control" rows="3"><?php echo h($form['notes']); ?></textarea>
                                </div>

                            </div>

                            <div class="mt-2">
                                <button type="submit" class="btn btn-primary">Save Customer</button>
                                <a href="customers.php" class="btn btn-light">Cancel</a>
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