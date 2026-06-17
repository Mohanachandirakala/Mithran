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
    $stmt = $conn->prepare("
        SELECT 1
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
        LIMIT 1
    ");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $res = $stmt->get_result();
    $ok = $res && $res->num_rows > 0;
    $stmt->close();
    return $ok;
}

function columnExists(mysqli $conn, string $table, string $column): bool
{
    $stmt = $conn->prepare("
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
        LIMIT 1
    ");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $res = $stmt->get_result();
    $ok = $res && $res->num_rows > 0;
    $stmt->close();
    return $ok;
}

function fetchAllAssoc(mysqli $conn, string $sql): array
{
    $rows = [];
    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $res->free();
    }
    return $rows;
}

function nextPreBookingNo(mysqli $conn, int $businessId, int $branchId): string
{
    $prefix = 'PBK';

    if (tableExists($conn, 'business_settings') && columnExists($conn, 'business_settings', 'pre_booking_prefix')) {
        $stmt = $conn->prepare("SELECT pre_booking_prefix FROM business_settings WHERE business_id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $businessId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if (!empty($row['pre_booking_prefix'])) {
                $prefix = trim((string)$row['pre_booking_prefix']);
            }
            $stmt->close();
        }
    }

    $running = 1;
    $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM pre_bookings WHERE business_id = ? AND branch_id = ?");
    if ($stmt) {
        $stmt->bind_param('ii', $businessId, $branchId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $running = ((int)($row['total'] ?? 0)) + 1;
        $stmt->close();
    }

    return $prefix . '-' . date('Ymd') . '-' . str_pad((string)$running, 4, '0', STR_PAD_LEFT);
}

function parseDecimal($value): float
{
    return round((float)($value ?? 0), 2);
}

/* -------------------------------------------------------
   REQUIRED TABLES
------------------------------------------------------- */
$requiredTables = ['business_users', 'businesses', 'branches', 'customers', 'pre_bookings'];
foreach ($requiredTables as $tbl) {
    if (!tableExists($conn, $tbl)) {
        die($tbl . ' table not found.');
    }
}

$hasVehicleModels = tableExists($conn, 'vehicle_models');
$hasVehicleBrands = tableExists($conn, 'vehicle_brands');
$hasProducts = tableExists($conn, 'products');
$hasPaymentMethods = tableExists($conn, 'payment_methods');

$hasBrandNameColumn = columnExists($conn, 'pre_bookings', 'brand_name');
$hasModelNameColumn = columnExists($conn, 'pre_bookings', 'model_name');
$hasVariantNameColumn = columnExists($conn, 'pre_bookings', 'variant_name');

if (!$hasBrandNameColumn || !$hasModelNameColumn || !$hasVariantNameColumn) {
    die('Please add brand_name, model_name and variant_name columns in pre_bookings table first.');
}

/* -------------------------------------------------------
   VALIDATE LOGIN USER
------------------------------------------------------- */
$loggedUser = null;
$stmt = $conn->prepare("
    SELECT bu.id, bu.full_name, bu.role, bu.status,
           b.business_name, b.status AS business_status
    FROM business_users bu
    INNER JOIN businesses b ON b.id = bu.business_id
    WHERE bu.id = ? AND bu.business_id = ?
    LIMIT 1
");
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
   MASTER DATA
------------------------------------------------------- */
$branches = fetchAllAssoc(
    $conn,
    "SELECT id, branch_name, branch_code
     FROM branches
     WHERE business_id = {$businessId}
     ORDER BY branch_name ASC"
);

$customers = fetchAllAssoc(
    $conn,
    "SELECT id, full_name, mobile
     FROM customers
     WHERE business_id = {$businessId}
     ORDER BY full_name ASC"
);

$vehicleModels = [];
if ($hasVehicleModels) {
    $vehicleModels = fetchAllAssoc(
        $conn,
        "SELECT
            vm.id,
            vm.model_name,
            vm.variant_name,
            vm.ex_showroom_price,
            vm.vehicle_type,
            " . ($hasVehicleBrands ? "vb.brand_name" : "NULL AS brand_name") . "
         FROM vehicle_models vm
         " . ($hasVehicleBrands ? "LEFT JOIN vehicle_brands vb ON vb.id = vm.brand_id" : "") . "
         WHERE vm.business_id = {$businessId}
           AND vm.status = 1
         ORDER BY vm.model_name ASC, vm.variant_name ASC"
    );
}

$products = [];
if ($hasProducts) {
    $products = fetchAllAssoc(
        $conn,
        "SELECT id, product_name, product_code, selling_price
         FROM products
         WHERE business_id = {$businessId}
           AND status = 1
         ORDER BY product_name ASC"
    );
}

$paymentMethods = [];
if ($hasPaymentMethods) {
    $paymentMethods = fetchAllAssoc(
        $conn,
        "SELECT id, method_name
         FROM payment_methods
         WHERE business_id = {$businessId}
           AND status = 1
         ORDER BY method_name ASC"
    );
}

/* -------------------------------------------------------
   DEFAULTS
------------------------------------------------------- */
$defaultBranchId = !empty($branches) ? (int)$branches[0]['id'] : 0;
$defaultBookingNo = $defaultBranchId > 0 ? nextPreBookingNo($conn, $businessId, $defaultBranchId) : '';

$form = [
    'branch_id' => $defaultBranchId,
    'customer_mode' => 'existing',
    'customer_id' => 0,

    'new_full_name' => '',
    'new_mobile' => '',
    'new_alternate_mobile' => '',
    'new_email' => '',
    'new_gstin' => '',
    'new_address_line1' => '',
    'new_address_line2' => '',
    'new_city' => '',
    'new_district' => '',
    'new_state' => '',
    'new_pincode' => '',

    'booking_no' => $defaultBookingNo,
    'booking_date' => date('Y-m-d\TH:i'),
    'booking_type' => 'vehicle',
    'vehicle_entry_mode' => 'master',
    'vehicle_model_id' => 0,
    'brand_name' => '',
    'model_name' => '',
    'variant_name' => '',
    'product_id' => 0,
    'color_preference' => '',
    'booking_amount' => '0.00',
    'expected_delivery_date' => '',
    'status' => 'open',
    'payment_method_id' => 0,
    'reference_no' => '',
    'notes' => '',
];

$error = '';

/* -------------------------------------------------------
   SAVE
------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['branch_id'] = isset($_POST['branch_id']) ? (int)$_POST['branch_id'] : 0;

    $form['customer_mode'] = trim($_POST['customer_mode'] ?? 'existing');
    $form['customer_id'] = isset($_POST['customer_id']) ? (int)$_POST['customer_id'] : 0;

    $form['new_full_name'] = trim($_POST['new_full_name'] ?? '');
    $form['new_mobile'] = trim($_POST['new_mobile'] ?? '');
    $form['new_alternate_mobile'] = trim($_POST['new_alternate_mobile'] ?? '');
    $form['new_email'] = trim($_POST['new_email'] ?? '');
    $form['new_gstin'] = trim($_POST['new_gstin'] ?? '');
    $form['new_address_line1'] = trim($_POST['new_address_line1'] ?? '');
    $form['new_address_line2'] = trim($_POST['new_address_line2'] ?? '');
    $form['new_city'] = trim($_POST['new_city'] ?? '');
    $form['new_district'] = trim($_POST['new_district'] ?? '');
    $form['new_state'] = trim($_POST['new_state'] ?? '');
    $form['new_pincode'] = trim($_POST['new_pincode'] ?? '');

    $form['booking_no'] = trim($_POST['booking_no'] ?? '');
    $form['booking_date'] = trim($_POST['booking_date'] ?? '');
    $form['booking_type'] = trim($_POST['booking_type'] ?? 'vehicle');
    $form['vehicle_entry_mode'] = trim($_POST['vehicle_entry_mode'] ?? 'master');
    $form['vehicle_model_id'] = isset($_POST['vehicle_model_id']) ? (int)$_POST['vehicle_model_id'] : 0;
    $form['brand_name'] = trim($_POST['brand_name'] ?? '');
    $form['model_name'] = trim($_POST['model_name'] ?? '');
    $form['variant_name'] = trim($_POST['variant_name'] ?? '');
    $form['product_id'] = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;
    $form['color_preference'] = trim($_POST['color_preference'] ?? '');
    $form['booking_amount'] = trim($_POST['booking_amount'] ?? '0');
    $form['expected_delivery_date'] = trim($_POST['expected_delivery_date'] ?? '');
    $form['status'] = trim($_POST['status'] ?? 'open');
    $form['payment_method_id'] = isset($_POST['payment_method_id']) ? (int)$_POST['payment_method_id'] : 0;
    $form['reference_no'] = trim($_POST['reference_no'] ?? '');
    $form['notes'] = trim($_POST['notes'] ?? '');

    $allowedBookingTypes = ['vehicle', 'product'];
    $allowedStatuses = ['open', 'confirmed', 'cancelled', 'converted', 'delivered'];
    $allowedVehicleEntryModes = ['master', 'manual'];
    $allowedCustomerModes = ['existing', 'new'];

    if ($form['branch_id'] <= 0) {
        $error = 'Please select branch.';
    } elseif (!in_array($form['customer_mode'], $allowedCustomerModes, true)) {
        $error = 'Invalid customer mode.';
    } elseif ($form['booking_no'] === '') {
        $error = 'Booking number is required.';
    } elseif ($form['booking_date'] === '') {
        $error = 'Booking date is required.';
    } elseif (!in_array($form['booking_type'], $allowedBookingTypes, true)) {
        $error = 'Invalid booking type.';
    } elseif (!in_array($form['status'], $allowedStatuses, true)) {
        $error = 'Invalid status.';
    } elseif (!in_array($form['vehicle_entry_mode'], $allowedVehicleEntryModes, true)) {
        $error = 'Invalid vehicle entry mode.';
    } elseif (parseDecimal($form['booking_amount']) < 0) {
        $error = 'Booking amount must be valid.';
    }

    if ($error === '') {
        $stmt = $conn->prepare("SELECT id FROM branches WHERE id = ? AND business_id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('ii', $form['branch_id'], $businessId);
            $stmt->execute();
            $exists = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$exists) {
                $error = 'Selected branch is invalid.';
            }
        }
    }

    if ($error === '' && $form['customer_mode'] === 'existing') {
        if ($form['customer_id'] <= 0) {
            $error = 'Please select customer.';
        } else {
            $stmt = $conn->prepare("SELECT id FROM customers WHERE id = ? AND business_id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('ii', $form['customer_id'], $businessId);
                $stmt->execute();
                $exists = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$exists) {
                    $error = 'Selected customer is invalid.';
                }
            }
        }
    }

    if ($error === '' && $form['customer_mode'] === 'new') {
        if ($form['new_full_name'] === '') {
            $error = 'Please enter customer full name.';
        } elseif ($form['new_mobile'] === '') {
            $error = 'Please enter customer mobile.';
        } else {
            $stmt = $conn->prepare("SELECT id FROM customers WHERE business_id = ? AND mobile = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('is', $businessId, $form['new_mobile']);
                $stmt->execute();
                $exists = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($exists) {
                    $error = 'A customer with this mobile already exists.';
                }
            }
        }
    }

    if ($error === '' && $form['booking_type'] === 'vehicle') {
        if ($form['vehicle_entry_mode'] === 'master') {
            if ($form['vehicle_model_id'] <= 0) {
                $error = 'Please select vehicle model.';
            } else {
                $stmt = $conn->prepare("SELECT id FROM vehicle_models WHERE id = ? AND business_id = ? LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param('ii', $form['vehicle_model_id'], $businessId);
                    $stmt->execute();
                    $exists = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    if (!$exists) {
                        $error = 'Selected vehicle model is invalid.';
                    }
                }
            }
        } else {
            if ($form['brand_name'] === '') {
                $error = 'Please enter vehicle brand name.';
            } elseif ($form['model_name'] === '') {
                $error = 'Please enter vehicle model name.';
            }
        }
    }

    if ($error === '' && $form['booking_type'] === 'product') {
        if ($form['product_id'] <= 0) {
            $error = 'Please select product.';
        } else {
            $stmt = $conn->prepare("SELECT id FROM products WHERE id = ? AND business_id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('ii', $form['product_id'], $businessId);
                $stmt->execute();
                $exists = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$exists) {
                    $error = 'Selected product is invalid.';
                }
            }
        }
    }

    if ($error === '' && $form['payment_method_id'] > 0 && $hasPaymentMethods) {
        $stmt = $conn->prepare("SELECT id FROM payment_methods WHERE id = ? AND business_id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('ii', $form['payment_method_id'], $businessId);
            $stmt->execute();
            $exists = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$exists) {
                $error = 'Selected payment method is invalid.';
            }
        }
    }

    if ($error === '') {
        $stmt = $conn->prepare("
            SELECT id
            FROM pre_bookings
            WHERE business_id = ? AND branch_id = ? AND booking_no = ?
            LIMIT 1
        ");
        if ($stmt) {
            $stmt->bind_param('iis', $businessId, $form['branch_id'], $form['booking_no']);
            $stmt->execute();
            $exists = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($exists) {
                $error = 'Booking number already exists.';
            }
        }
    }

    if ($error === '') {
        $conn->begin_transaction();

        try {
            $finalCustomerId = 0;

            if ($form['customer_mode'] === 'existing') {
                $finalCustomerId = $form['customer_id'];
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO customers (
                        business_id,
                        full_name,
                        mobile,
                        alternate_mobile,
                        email,
                        gstin,
                        address_line1,
                        address_line2,
                        city,
                        district,
                        state,
                        pincode
                    ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
                ");
                if (!$stmt) {
                    throw new Exception('Failed to prepare customer insert.');
                }

                $stmt->bind_param(
                    'isssssssssss',
                    $businessId,
                    $form['new_full_name'],
                    $form['new_mobile'],
                    $form['new_alternate_mobile'],
                    $form['new_email'],
                    $form['new_gstin'],
                    $form['new_address_line1'],
                    $form['new_address_line2'],
                    $form['new_city'],
                    $form['new_district'],
                    $form['new_state'],
                    $form['new_pincode']
                );

                if (!$stmt->execute()) {
                    throw new Exception('Failed to save new customer.');
                }

                $finalCustomerId = (int)$stmt->insert_id;
                $stmt->close();
            }

            $bookingAmount = parseDecimal($form['booking_amount']);
            $vehicleModelId = null;
            $brandName = null;
            $modelName = null;
            $variantName = null;
            $productId = null;

            if ($form['booking_type'] === 'vehicle') {
                if ($form['vehicle_entry_mode'] === 'master') {
                    $vehicleModelId = $form['vehicle_model_id'];
                } else {
                    $brandName = $form['brand_name'];
                    $modelName = $form['model_name'];
                    $variantName = $form['variant_name'] !== '' ? $form['variant_name'] : null;
                }
            } else {
                $productId = $form['product_id'];
            }

            $paymentMethodId = $form['payment_method_id'] > 0 ? $form['payment_method_id'] : null;
            $expectedDeliveryDate = $form['expected_delivery_date'] !== '' ? $form['expected_delivery_date'] : null;

            $stmt = $conn->prepare("
                INSERT INTO pre_bookings (
                    business_id, branch_id, booking_no, customer_id, booking_date, booking_type,
                    vehicle_model_id, brand_name, model_name, variant_name, product_id,
                    color_preference, booking_amount, expected_delivery_date, status,
                    payment_method_id, reference_no, notes, created_by
                ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ");

            if (!$stmt) {
                throw new Exception('Failed to prepare booking insert.');
            }

            $stmt->bind_param(
                'iisisisssisdsissssi',
                $businessId,
                $form['branch_id'],
                $form['booking_no'],
                $finalCustomerId,
                $form['booking_date'],
                $form['booking_type'],
                $vehicleModelId,
                $brandName,
                $modelName,
                $variantName,
                $productId,
                $form['color_preference'],
                $bookingAmount,
                $expectedDeliveryDate,
                $form['status'],
                $paymentMethodId,
                $form['reference_no'],
                $form['notes'],
                $businessUserId
            );

            if (!$stmt->execute()) {
                throw new Exception('Failed to save pre booking.');
            }
            $stmt->close();

            $conn->commit();
            header('Location: pre-bookings.php?success=' . urlencode('Pre booking created successfully.'));
            exit;
        } catch (Throwable $e) {
            $conn->rollback();
            $error = $e->getMessage();
        }
    }
}

$pageTitle = 'Create Pre Booking';
$currentPage = 'pre-booking-add';
?>
<!doctype html>
<html lang="en">
<?php include('includes/head.php'); ?>

<body data-sidebar="dark">

<style>
.page-content { padding-bottom: 90px !important; }
.card { margin-bottom: 24px; }
.main-content { min-height: calc(100vh - 70px); }
.customer-box {
    border: 1px solid #e9ecef;
    padding: 14px;
    border-radius: 8px;
    background: #fafafa;
}
</style>

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
                        <h4 class="mb-1">Create Pre Booking</h4>
                        <p class="text-muted mb-0">Add a pre booking for existing or new customer</p>
                    </div>
                    <div class="col-md-5 text-md-end mt-3 mt-md-0">
                        <a href="pre-bookings.php" class="btn btn-secondary">Back to Pre Bookings</a>
                    </div>
                </div>

                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger"><?php echo h($error); ?></div>
                <?php endif; ?>

                <form method="post" id="preBookingForm">
                    <div class="row">
                        <div class="col-lg-8">

                            <div class="card">
                                <div class="card-header">
                                    <h5 class="mb-0">Booking Details</h5>
                                </div>
                                <div class="card-body">
                                    <div class="row g-3">

                                        <div class="col-md-4">
                                            <label class="form-label">Branch <span class="text-danger">*</span></label>
                                            <select name="branch_id" class="form-select" required>
                                                <option value="">Select Branch</option>
                                                <?php foreach ($branches as $b): ?>
                                                    <option value="<?php echo (int)$b['id']; ?>" <?php echo ((int)$form['branch_id'] === (int)$b['id']) ? 'selected' : ''; ?>>
                                                        <?php echo h($b['branch_name'] . ' (' . $b['branch_code'] . ')'); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="col-md-4">
                                            <label class="form-label">Booking No <span class="text-danger">*</span></label>
                                            <input type="text" name="booking_no" class="form-control" value="<?php echo h($form['booking_no']); ?>" required>
                                        </div>

                                        <div class="col-md-4">
                                            <label class="form-label">Booking Date <span class="text-danger">*</span></label>
                                            <input type="datetime-local" name="booking_date" class="form-control" value="<?php echo h($form['booking_date']); ?>" required>
                                        </div>

                                        <div class="col-md-4">
                                            <label class="form-label">Customer Mode <span class="text-danger">*</span></label>
                                            <select name="customer_mode" id="customer_mode" class="form-select" onchange="toggleCustomerMode()" required>
                                                <option value="existing" <?php echo ($form['customer_mode'] === 'existing') ? 'selected' : ''; ?>>Existing Customer</option>
                                                <option value="new" <?php echo ($form['customer_mode'] === 'new') ? 'selected' : ''; ?>>New Customer</option>
                                            </select>
                                        </div>

                                        <div class="col-md-8" id="existingCustomerWrap">
                                            <label class="form-label">Customer <span class="text-danger">*</span></label>
                                            <select name="customer_id" class="form-select">
                                                <option value="0">Select Customer</option>
                                                <?php foreach ($customers as $c): ?>
                                                    <option value="<?php echo (int)$c['id']; ?>" <?php echo ((int)$form['customer_id'] === (int)$c['id']) ? 'selected' : ''; ?>>
                                                        <?php echo h($c['full_name'] . ' - ' . $c['mobile']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="col-md-12" id="newCustomerWrap" style="display:none;">
                                            <div class="customer-box">
                                                <div class="row g-3">
                                                    <div class="col-md-6">
                                                        <label class="form-label">Full Name</label>
                                                        <input type="text" name="new_full_name" class="form-control" value="<?php echo h($form['new_full_name']); ?>">
                                                    </div>

                                                    <div class="col-md-6">
                                                        <label class="form-label">Mobile</label>
                                                        <input type="text" name="new_mobile" class="form-control" value="<?php echo h($form['new_mobile']); ?>">
                                                    </div>

                                                    <div class="col-md-6">
                                                        <label class="form-label">Alternate Mobile</label>
                                                        <input type="text" name="new_alternate_mobile" class="form-control" value="<?php echo h($form['new_alternate_mobile']); ?>">
                                                    </div>

                                                    <div class="col-md-6">
                                                        <label class="form-label">Email</label>
                                                        <input type="email" name="new_email" class="form-control" value="<?php echo h($form['new_email']); ?>">
                                                    </div>

                                                    <div class="col-md-6">
                                                        <label class="form-label">GSTIN</label>
                                                        <input type="text" name="new_gstin" class="form-control" value="<?php echo h($form['new_gstin']); ?>">
                                                    </div>

                                                    <div class="col-md-6">
                                                        <label class="form-label">Address Line 1</label>
                                                        <input type="text" name="new_address_line1" class="form-control" value="<?php echo h($form['new_address_line1']); ?>">
                                                    </div>

                                                    <div class="col-md-6">
                                                        <label class="form-label">Address Line 2</label>
                                                        <input type="text" name="new_address_line2" class="form-control" value="<?php echo h($form['new_address_line2']); ?>">
                                                    </div>

                                                    <div class="col-md-3">
                                                        <label class="form-label">City</label>
                                                        <input type="text" name="new_city" class="form-control" value="<?php echo h($form['new_city']); ?>">
                                                    </div>

                                                    <div class="col-md-3">
                                                        <label class="form-label">District</label>
                                                        <input type="text" name="new_district" class="form-control" value="<?php echo h($form['new_district']); ?>">
                                                    </div>

                                                    <div class="col-md-3">
                                                        <label class="form-label">State</label>
                                                        <input type="text" name="new_state" class="form-control" value="<?php echo h($form['new_state']); ?>">
                                                    </div>

                                                    <div class="col-md-3">
                                                        <label class="form-label">Pincode</label>
                                                        <input type="text" name="new_pincode" class="form-control" value="<?php echo h($form['new_pincode']); ?>">
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="col-md-4">
                                            <label class="form-label">Booking Type <span class="text-danger">*</span></label>
                                            <select name="booking_type" id="booking_type" class="form-select" onchange="toggleBookingType()" required>
                                                <option value="vehicle" <?php echo ($form['booking_type'] === 'vehicle') ? 'selected' : ''; ?>>Vehicle</option>
                                                <option value="product" <?php echo ($form['booking_type'] === 'product') ? 'selected' : ''; ?>>Product</option>
                                            </select>
                                        </div>

                                        <div class="col-md-4">
                                            <label class="form-label">Booking Amount</label>
                                            <input type="number" step="0.01" min="0" name="booking_amount" class="form-control" value="<?php echo h($form['booking_amount']); ?>">
                                        </div>

                                        <div class="col-md-4">
                                            <label class="form-label">Expected Delivery Date</label>
                                            <input type="date" name="expected_delivery_date" class="form-control" value="<?php echo h($form['expected_delivery_date']); ?>">
                                        </div>

                                        <div id="vehicleSection">
                                            <div class="row g-3">
                                                <div class="col-md-6">
                                                    <label class="form-label">Vehicle Entry Mode</label>
                                                    <select name="vehicle_entry_mode" id="vehicle_entry_mode" class="form-select" onchange="toggleVehicleEntryMode()">
                                                        <option value="master" <?php echo ($form['vehicle_entry_mode'] === 'master') ? 'selected' : ''; ?>>Select Existing Model</option>
                                                        <option value="manual" <?php echo ($form['vehicle_entry_mode'] === 'manual') ? 'selected' : ''; ?>>Enter New Vehicle Manually</option>
                                                    </select>
                                                </div>

                                                <div class="col-md-6" id="vehicleModelWrap">
                                                    <label class="form-label">Vehicle Model</label>
                                                    <select name="vehicle_model_id" class="form-select">
                                                        <option value="0">Select Vehicle Model</option>
                                                        <?php foreach ($vehicleModels as $vm): ?>
                                                            <option value="<?php echo (int)$vm['id']; ?>" <?php echo ((int)$form['vehicle_model_id'] === (int)$vm['id']) ? 'selected' : ''; ?>>
                                                                <?php echo h(($vm['brand_name'] ?: 'Brand') . ' - ' . ($vm['model_name'] ?: '') . ($vm['variant_name'] ? ' - ' . $vm['variant_name'] : '')); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>

                                                <div id="manualVehicleWrap" style="display:none;">
                                                    <div class="row g-3">
                                                        <div class="col-md-4">
                                                            <label class="form-label">Brand Name</label>
                                                            <input type="text" name="brand_name" class="form-control" value="<?php echo h($form['brand_name']); ?>" placeholder="Eg: Honda">
                                                        </div>

                                                        <div class="col-md-4">
                                                            <label class="form-label">Model Name</label>
                                                            <input type="text" name="model_name" class="form-control" value="<?php echo h($form['model_name']); ?>" placeholder="Eg: Activa EV">
                                                        </div>

                                                        <div class="col-md-4">
                                                            <label class="form-label">Variant Name</label>
                                                            <input type="text" name="variant_name" class="form-control" value="<?php echo h($form['variant_name']); ?>" placeholder="Eg: Top Variant">
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="col-md-6" id="productWrap" style="display:none;">
                                            <label class="form-label">Product</label>
                                            <select name="product_id" class="form-select">
                                                <option value="0">Select Product</option>
                                                <?php foreach ($products as $p): ?>
                                                    <option value="<?php echo (int)$p['id']; ?>" <?php echo ((int)$form['product_id'] === (int)$p['id']) ? 'selected' : ''; ?>>
                                                        <?php echo h($p['product_name'] . ($p['product_code'] ? ' (' . $p['product_code'] . ')' : '')); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label">Color Preference</label>
                                            <input type="text" name="color_preference" class="form-control" value="<?php echo h($form['color_preference']); ?>" placeholder="Eg: Red / Blue / Black">
                                        </div>

                                        <div class="col-md-4">
                                            <label class="form-label">Status</label>
                                            <select name="status" class="form-select">
                                                <option value="open" <?php echo ($form['status'] === 'open') ? 'selected' : ''; ?>>Open</option>
                                                <option value="confirmed" <?php echo ($form['status'] === 'confirmed') ? 'selected' : ''; ?>>Confirmed</option>
                                                <option value="cancelled" <?php echo ($form['status'] === 'cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                                                <option value="converted" <?php echo ($form['status'] === 'converted') ? 'selected' : ''; ?>>Converted</option>
                                                <option value="delivered" <?php echo ($form['status'] === 'delivered') ? 'selected' : ''; ?>>Delivered</option>
                                            </select>
                                        </div>

                                        <div class="col-md-4">
                                            <label class="form-label">Payment Method</label>
                                            <select name="payment_method_id" class="form-select">
                                                <option value="0">Select Payment Method</option>
                                                <?php foreach ($paymentMethods as $pm): ?>
                                                    <option value="<?php echo (int)$pm['id']; ?>" <?php echo ((int)$form['payment_method_id'] === (int)$pm['id']) ? 'selected' : ''; ?>>
                                                        <?php echo h($pm['method_name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="col-md-4">
                                            <label class="form-label">Reference No</label>
                                            <input type="text" name="reference_no" class="form-control" value="<?php echo h($form['reference_no']); ?>">
                                        </div>

                                        <div class="col-md-12">
                                            <label class="form-label">Notes</label>
                                            <textarea name="notes" class="form-control" rows="4"><?php echo h($form['notes']); ?></textarea>
                                        </div>

                                    </div>
                                </div>
                            </div>

                        </div>

                        <div class="col-lg-4">

                            <div class="card">
                                <div class="card-header">
                                    <h5 class="mb-0">Quick Help</h5>
                                </div>
                                <div class="card-body small text-muted">
                                    <div class="mb-2"><strong>Existing customer:</strong> select from list.</div>
                                    <div class="mb-2"><strong>New customer:</strong> enter details and it will be saved in customers table automatically.</div>
                                    <div class="mb-2"><strong>Existing model:</strong> choose master vehicle model.</div>
                                    <div class="mb-2"><strong>Upcoming vehicle:</strong> use manual vehicle entry.</div>
                                    <div class="mb-0"><strong>Pre booking does not require stock.</strong></div>
                                </div>
                            </div>

                            <div class="card">
                                <div class="card-body">
                                    <button type="submit" class="btn btn-success w-100">Save Pre Booking</button>
                                    <a href="pre-bookings.php" class="btn btn-light w-100 mt-2">Cancel</a>
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

<script>
function toggleCustomerMode() {
    var mode = document.getElementById('customer_mode').value;
    var existingWrap = document.getElementById('existingCustomerWrap');
    var newWrap = document.getElementById('newCustomerWrap');

    if (mode === 'existing') {
        existingWrap.style.display = '';
        newWrap.style.display = 'none';
    } else {
        existingWrap.style.display = 'none';
        newWrap.style.display = '';
    }
}

function toggleBookingType() {
    var type = document.getElementById('booking_type').value;
    var vehicleSection = document.getElementById('vehicleSection');
    var productWrap = document.getElementById('productWrap');

    if (type === 'vehicle') {
        vehicleSection.style.display = '';
        productWrap.style.display = 'none';
    } else {
        vehicleSection.style.display = 'none';
        productWrap.style.display = '';
    }

    toggleVehicleEntryMode();
}

function toggleVehicleEntryMode() {
    var type = document.getElementById('booking_type').value;
    var mode = document.getElementById('vehicle_entry_mode');
    var vehicleModelWrap = document.getElementById('vehicleModelWrap');
    var manualVehicleWrap = document.getElementById('manualVehicleWrap');

    if (!mode) return;

    if (type !== 'vehicle') {
        vehicleModelWrap.style.display = 'none';
        manualVehicleWrap.style.display = 'none';
        return;
    }

    if (mode.value === 'master') {
        vehicleModelWrap.style.display = '';
        manualVehicleWrap.style.display = 'none';
    } else {
        vehicleModelWrap.style.display = 'none';
        manualVehicleWrap.style.display = '';
    }
}

toggleCustomerMode();
toggleBookingType();
</script>

</body>
</html>