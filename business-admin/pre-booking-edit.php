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
$branchId   = isset($_SESSION['branch_id']) ? (int)$_SESSION['branch_id'] : 0;

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

function money($amount): string
{
    return '₹' . number_format((float)$amount, 2);
}

function tableExists(mysqli $conn, string $table): bool
{
    $sql = "SELECT 1
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return false;

    $stmt->bind_param('s', $table);
    $stmt->execute();
    $res = $stmt->get_result();
    $exists = $res && $res->num_rows > 0;
    $stmt->close();
    return $exists;
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
    if (!$stmt) return false;
    
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $res = $stmt->get_result();
    $exists = $res && $res->num_rows > 0;
    $stmt->close();
    return $exists;
}

function fetchAllAssoc(mysqli $conn, string $sql): array
{
    $rows = [];
    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
    }
    return $rows;
}

/* -------------------------------------------------------
   VALIDATE USER
------------------------------------------------------- */
if (!tableExists($conn, 'business_users') || !tableExists($conn, 'businesses')) {
    die('Required tables not found.');
}

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
   GET PRE BOOKING ID
------------------------------------------------------- */
$bookingId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($bookingId <= 0) {
    header('Location: pre-bookings.php');
    exit;
}

/* -------------------------------------------------------
   TABLE CHECKS
------------------------------------------------------- */
$hasVehicleModels = tableExists($conn, 'vehicle_models');
$hasVehicleBrands = tableExists($conn, 'vehicle_brands');
$hasProducts = tableExists($conn, 'products');
$hasPaymentMethods = tableExists($conn, 'payment_methods');
$hasBranches = tableExists($conn, 'branches');

$hasBrandNameColumn = columnExists($conn, 'pre_bookings', 'brand_name');
$hasModelNameColumn = columnExists($conn, 'pre_bookings', 'model_name');
$hasVariantNameColumn = columnExists($conn, 'pre_bookings', 'variant_name');

/* -------------------------------------------------------
   FETCH PRE BOOKING DETAILS
------------------------------------------------------- */
$sql = "SELECT
            pb.*,
            b.branch_name,
            c.full_name AS customer_name,
            c.mobile AS customer_mobile,
            " . ($hasPaymentMethods ? "pm.method_name" : "NULL AS method_name") . "
        FROM pre_bookings pb
        LEFT JOIN branches b ON b.id = pb.branch_id
        LEFT JOIN customers c ON c.id = pb.customer_id
        " . ($hasPaymentMethods ? "LEFT JOIN payment_methods pm ON pm.id = pb.payment_method_id" : "") . "
        WHERE pb.id = ? AND pb.business_id = ?
        LIMIT 1";

$booking = null;
$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param('ii', $bookingId, $businessId);
    $stmt->execute();
    $booking = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if (!$booking) {
    header('Location: pre-bookings.php');
    exit;
}

/* -------------------------------------------------------
   FETCH MASTER DATA FOR DROPDOWNS
------------------------------------------------------- */
// Fetch branches
$branches = [];
if ($hasBranches) {
    $branches = fetchAllAssoc(
        $conn,
        "SELECT id, branch_name, branch_code
         FROM branches
         WHERE business_id = {$businessId} AND status = 'active'
         ORDER BY branch_name ASC"
    );
}

// Fetch customers
$customers = fetchAllAssoc(
    $conn,
    "SELECT id, full_name, mobile, email, city
     FROM customers
     WHERE business_id = {$businessId}
     ORDER BY full_name ASC"
);

// Fetch vehicle models for dropdown
$vehicleModels = [];
if ($hasVehicleModels) {
    $vehicleModels = fetchAllAssoc(
        $conn,
        "SELECT vm.id, vm.model_name, vm.variant_name, vm.brand_id,
                vb.brand_name
         FROM vehicle_models vm
         LEFT JOIN vehicle_brands vb ON vb.id = vm.brand_id
         WHERE vm.business_id = {$businessId} AND vm.status = 1
         ORDER BY vb.brand_name, vm.model_name"
    );
}

// Fetch products for dropdown
$products = [];
if ($hasProducts) {
    $products = fetchAllAssoc(
        $conn,
        "SELECT id, product_name, product_code, selling_price
         FROM products
         WHERE business_id = {$businessId} AND status = 1
         ORDER BY product_name ASC"
    );
}

// Fetch payment methods
$paymentMethods = [];
if ($hasPaymentMethods) {
    $paymentMethods = fetchAllAssoc(
        $conn,
        "SELECT id, method_name
         FROM payment_methods
         WHERE business_id = {$businessId} AND status = 1
         ORDER BY method_name ASC"
    );
}

/* -------------------------------------------------------
   FORM VALUES (initialize with existing data)
------------------------------------------------------- */
$success = '';
$error = '';

$formData = [
    'branch_id' => $booking['branch_id'] ?? 0,
    'customer_id' => $booking['customer_id'] ?? 0,
    'booking_type' => $booking['booking_type'] ?? 'vehicle',
    'vehicle_model_id' => $booking['vehicle_model_id'] ?? '',
    'product_id' => $booking['product_id'] ?? '',
    'brand_name' => $booking['brand_name'] ?? '',
    'model_name' => $booking['model_name'] ?? '',
    'variant_name' => $booking['variant_name'] ?? '',
    'color_preference' => $booking['color_preference'] ?? '',
    'booking_amount' => $booking['booking_amount'] ?? 0,
    'expected_delivery_date' => $booking['expected_delivery_date'] ?? '',
    'status' => $booking['status'] ?? 'open',
    'payment_method_id' => $booking['payment_method_id'] ?? '',
    'reference_no' => $booking['reference_no'] ?? '',
    'notes' => $booking['notes'] ?? '',
];

/* -------------------------------------------------------
   HANDLE FORM SUBMISSION
------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Collect form data
    $formData['branch_id'] = isset($_POST['branch_id']) ? (int)$_POST['branch_id'] : 0;
    $formData['customer_id'] = isset($_POST['customer_id']) ? (int)$_POST['customer_id'] : 0;
    $formData['booking_type'] = trim($_POST['booking_type'] ?? 'vehicle');
    $formData['vehicle_model_id'] = isset($_POST['vehicle_model_id']) && $_POST['vehicle_model_id'] !== '' ? (int)$_POST['vehicle_model_id'] : null;
    $formData['product_id'] = isset($_POST['product_id']) && $_POST['product_id'] !== '' ? (int)$_POST['product_id'] : null;
    $formData['brand_name'] = trim($_POST['brand_name'] ?? '');
    $formData['model_name'] = trim($_POST['model_name'] ?? '');
    $formData['variant_name'] = trim($_POST['variant_name'] ?? '');
    $formData['color_preference'] = trim($_POST['color_preference'] ?? '');
    $formData['booking_amount'] = isset($_POST['booking_amount']) ? (float)str_replace(',', '', $_POST['booking_amount']) : 0;
    $formData['expected_delivery_date'] = !empty($_POST['expected_delivery_date']) ? $_POST['expected_delivery_date'] : null;
    $formData['status'] = trim($_POST['status'] ?? 'open');
    $formData['payment_method_id'] = isset($_POST['payment_method_id']) && $_POST['payment_method_id'] !== '' ? (int)$_POST['payment_method_id'] : null;
    $formData['reference_no'] = trim($_POST['reference_no'] ?? '');
    $formData['notes'] = trim($_POST['notes'] ?? '');

    // Validate required fields
    if ($formData['branch_id'] <= 0) {
        $error = 'Please select a branch.';
    } elseif ($formData['customer_id'] <= 0) {
        $error = 'Please select a customer.';
    } elseif (!in_array($formData['booking_type'], ['vehicle', 'product'], true)) {
        $error = 'Invalid booking type.';
    } elseif ($formData['booking_type'] === 'vehicle' && empty($formData['vehicle_model_id']) && empty($formData['brand_name'])) {
        $error = 'Please select a vehicle model or enter brand/model details.';
    } elseif ($formData['booking_type'] === 'product' && empty($formData['product_id']) && empty($formData['model_name'])) {
        $error = 'Please select a product or enter product name.';
    } elseif ($formData['booking_amount'] < 0) {
        $error = 'Booking amount cannot be negative.';
    } elseif (!in_array($formData['status'], ['open', 'confirmed', 'cancelled', 'converted', 'delivered'], true)) {
        $error = 'Invalid status selected.';
    }

    // If no errors, update the record
    if ($error === '') {
        $conn->begin_transaction();

        try {
            // Base fields that are always updated
            $updates = [
                'branch_id = ?',
                'customer_id = ?',
                'booking_type = ?',
                'vehicle_model_id = ?',
                'product_id = ?',
                'color_preference = ?',
                'booking_amount = ?',
                'expected_delivery_date = ?',
                'status = ?',
                'payment_method_id = ?',
                'reference_no = ?',
                'notes = ?',
                'updated_at = NOW()'
            ];
            
            $params = [
                $formData['branch_id'],
                $formData['customer_id'],
                $formData['booking_type'],
                $formData['vehicle_model_id'],
                $formData['product_id'],
                $formData['color_preference'],
                $formData['booking_amount'],
                $formData['expected_delivery_date'],
                $formData['status'],
                $formData['payment_method_id'],
                $formData['reference_no'],
                $formData['notes']
            ];
            
            // Base types: i, i, s, i, i, s, d, s, s, i, s, s = 12 characters
            $types = 'iis i i s d s s i s s';
            $types = str_replace(' ', '', $types); // Remove spaces for clarity: 'iisiids ssiss'
            // Actually let's build it properly:
            $types = 'ii'; // branch_id, customer_id (2)
            $types .= 's'; // booking_type (3)
            $types .= 'i'; // vehicle_model_id (4)
            $types .= 'i'; // product_id (5)
            $types .= 's'; // color_preference (6)
            $types .= 'd'; // booking_amount (7)
            $types .= 's'; // expected_delivery_date (8)
            $types .= 's'; // status (9)
            $types .= 'i'; // payment_method_id (10)
            $types .= 's'; // reference_no (11)
            $types .= 's'; // notes (12)
            
            // Count base params: 12
            $baseParamCount = count($params);
            $baseTypeLength = strlen($types);
            
            // Add brand_name if column exists
            if ($hasBrandNameColumn) {
                $updates[] = 'brand_name = ?';
                $params[] = $formData['brand_name'];
                $types .= 's';
            }
            
            // Add model_name if column exists
            if ($hasModelNameColumn) {
                $updates[] = 'model_name = ?';
                $params[] = $formData['model_name'];
                $types .= 's';
            }
            
            // Add variant_name if column exists
            if ($hasVariantNameColumn) {
                $updates[] = 'variant_name = ?';
                $params[] = $formData['variant_name'];
                $types .= 's';
            }
            
            // Add id and business_id for WHERE clause
            $params[] = $bookingId;
            $params[] = $businessId;
            $types .= 'ii';
            
            // Final check
            $totalParams = count($params);
            $totalTypes = strlen($types);
            
            if ($totalParams !== $totalTypes) {
                throw new Exception("Parameter count mismatch: $totalParams parameters but $totalTypes type characters. Types: $types");
            }
            
            $sql = "UPDATE pre_bookings SET " . implode(', ', $updates) . " WHERE id = ? AND business_id = ?";
            
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new Exception('Failed to prepare update query: ' . $conn->error);
            }
            
            $stmt->bind_param($types, ...$params);
            
            if (!$stmt->execute()) {
                throw new Exception('Failed to update pre booking: ' . $stmt->error);
            }
            
            $stmt->close();

            // Add to audit log
            if (tableExists($conn, 'audit_logs')) {
                $auditSql = "INSERT INTO audit_logs (business_id, branch_id, user_id, action, 
                             module_name, ref_table, ref_id, description) 
                             VALUES (?, ?, ?, 'Edit Pre Booking', 'Pre Bookings', 
                             'pre_bookings', ?, ?)";
                $auditStmt = $conn->prepare($auditSql);
                if ($auditStmt) {
                    $desc = "Updated pre booking #" . $booking['booking_no'] . " for customer ID: " . $formData['customer_id'];
                    $auditStmt->bind_param('iiiis', $businessId, $branchId, $businessUserId, $bookingId, $desc);
                    $auditStmt->execute();
                    $auditStmt->close();
                }
            }

            $conn->commit();
            
            // Redirect back to view page with success message
            header('Location: pre-booking-view.php?id=' . $bookingId . '&success=' . urlencode('Pre booking updated successfully.'));
            exit;
            
        } catch (Exception $e) {
            $conn->rollback();
            $error = $e->getMessage();
        }
    }
}

$pageTitle = 'Edit Pre Booking - ' . $booking['booking_no'];
$currentPage = 'pre-bookings';
?>
<!doctype html>
<html lang="en">

<?php include('includes/head.php'); ?>

<style>
    .page-content {
        padding-bottom: 90px !important;
    }
    .card {
        margin-bottom: 24px;
    }
    .main-content {
        min-height: calc(100vh - 70px);
    }
    .required-field::after {
        content: " *";
        color: red;
    }
    .vehicle-fields, .product-fields, .manual-vehicle-fields {
        transition: all 0.3s ease;
    }
    .form-section {
        background-color: #f8f9fa;
        border-radius: 0.5rem;
        padding: 1.5rem;
        margin-bottom: 1.5rem;
    }
    .form-section h5 {
        margin-top: 0;
        margin-bottom: 1rem;
        color: #495057;
    }
</style>

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
                    <div class="col-md-8">
                        <h4 class="mb-1">Edit Pre Booking</h4>
                        <p class="text-muted mb-0">
                            Editing booking: <strong><?php echo h($booking['booking_no']); ?></strong>
                            <?php if (!empty($booking['customer_name'])): ?>
                                for <?php echo h($booking['customer_name']); ?>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="col-md-4 text-md-end mt-3 mt-md-0">
                        <a href="pre-booking-view.php?id=<?php echo $bookingId; ?>" class="btn btn-info">
                            <i class="ri-eye-line"></i> View
                        </a>
                        <a href="pre-bookings.php" class="btn btn-secondary">
                            <i class="ri-arrow-go-back-line"></i> Back
                        </a>
                    </div>
                </div>

                <?php if ($success !== ''): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?php echo h($success); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?php echo h($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <div class="card">
                    <div class="card-body">
                        <form method="post" id="bookingForm">
                            <!-- Basic Information -->
                            <div class="form-section">
                                <h5>Basic Information</h5>
                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label required-field">Branch</label>
                                        <select name="branch_id" class="form-select" required>
                                            <option value="">-- Select Branch --</option>
                                            <?php foreach ($branches as $branch): ?>
                                                <option value="<?php echo $branch['id']; ?>" 
                                                    <?php echo ($formData['branch_id'] == $branch['id']) ? 'selected' : ''; ?>>
                                                    <?php echo h($branch['branch_name']); ?> (<?php echo h($branch['branch_code']); ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="col-md-4 mb-3">
                                        <label class="form-label required-field">Customer</label>
                                        <select name="customer_id" class="form-select" required>
                                            <option value="">-- Select Customer --</option>
                                            <?php foreach ($customers as $cust): ?>
                                                <option value="<?php echo $cust['id']; ?>" 
                                                    <?php echo ($formData['customer_id'] == $cust['id']) ? 'selected' : ''; ?>>
                                                    <?php echo h($cust['full_name']); ?> (<?php echo h($cust['mobile']); ?>)
                                                    <?php echo !empty($cust['city']) ? ' - ' . h($cust['city']) : ''; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="form-text">
                                            <a href="customer-add.php" target="_blank">Add new customer</a> if not listed.
                                        </div>
                                    </div>

                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Reference Number</label>
                                        <input type="text" name="reference_no" class="form-control" 
                                               value="<?php echo h($formData['reference_no']); ?>" 
                                               placeholder="e.g., Quotation, Order ref">
                                    </div>
                                </div>
                            </div>

                            <!-- Booking Type -->
                            <div class="form-section">
                                <h5>Booking Type</h5>
                                <div class="row">
                                    <div class="col-md-12 mb-3">
                                        <div class="form-check form-check-inline">
                                            <input class="form-check-input" type="radio" name="booking_type" 
                                                   id="typeVehicle" value="vehicle" 
                                                   <?php echo $formData['booking_type'] === 'vehicle' ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="typeVehicle">Vehicle Booking</label>
                                        </div>
                                        <div class="form-check form-check-inline">
                                            <input class="form-check-input" type="radio" name="booking_type" 
                                                   id="typeProduct" value="product"
                                                   <?php echo $formData['booking_type'] === 'product' ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="typeProduct">Product Booking</label>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Vehicle Fields (shown when vehicle type selected) -->
                            <div class="form-section vehicle-fields" style="display: <?php echo $formData['booking_type'] === 'vehicle' ? 'block' : 'none'; ?>;">
                                <h5>Vehicle Details</h5>
                                
                                <?php if ($hasVehicleModels && !empty($vehicleModels)): ?>
                                <div class="row mb-3">
                                    <div class="col-md-8">
                                        <label class="form-label">Select Model from Master</label>
                                        <select name="vehicle_model_id" class="form-select" id="vehicleModelSelect">
                                            <option value="">-- Select Model (Optional) --</option>
                                            <?php foreach ($vehicleModels as $model): ?>
                                                <option value="<?php echo $model['id']; ?>" 
                                                    data-brand="<?php echo h($model['brand_name'] ?? ''); ?>"
                                                    data-model="<?php echo h($model['model_name']); ?>"
                                                    data-variant="<?php echo h($model['variant_name'] ?? ''); ?>"
                                                    <?php echo ($formData['vehicle_model_id'] == $model['id']) ? 'selected' : ''; ?>>
                                                    <?php 
                                                    echo h(($model['brand_name'] ?? '') . ' ' . $model['model_name']); 
                                                    if (!empty($model['variant_name'])) {
                                                        echo ' - ' . h($model['variant_name']);
                                                    }
                                                    ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="form-text">Selecting a model will auto-fill the fields below</div>
                                    </div>
                                    <div class="col-md-4 d-flex align-items-end">
                                        <button type="button" class="btn btn-outline-primary" id="fillFromModelBtn">
                                            <i class="ri-refresh-line"></i> Fill Details
                                        </button>
                                    </div>
                                </div>
                                <hr>
                                <?php endif; ?>

                                <div class="row manual-vehicle-fields">
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Brand Name</label>
                                        <input type="text" name="brand_name" class="form-control" 
                                               value="<?php echo h($formData['brand_name']); ?>" 
                                               placeholder="e.g., Hero, Honda, TVS">
                                    </div>

                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Model Name</label>
                                        <input type="text" name="model_name" class="form-control" 
                                               value="<?php echo h($formData['model_name']); ?>" 
                                               placeholder="e.g., Splendor, Activa">
                                    </div>

                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Variant</label>
                                        <input type="text" name="variant_name" class="form-control" 
                                               value="<?php echo h($formData['variant_name']); ?>" 
                                               placeholder="e.g., Deluxe, Standard">
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Color Preference</label>
                                        <input type="text" name="color_preference" class="form-control" 
                                               value="<?php echo h($formData['color_preference']); ?>" 
                                               placeholder="e.g., Red, Black, Blue">
                                    </div>
                                </div>
                            </div>

                            <!-- Product Fields (shown when product type selected) -->
                            <div class="form-section product-fields" style="display: <?php echo $formData['booking_type'] === 'product' ? 'block' : 'none'; ?>;">
                                <h5>Product Details</h5>
                                
                                <?php if ($hasProducts && !empty($products)): ?>
                                <div class="row mb-3">
                                    <div class="col-md-8">
                                        <label class="form-label">Select Product</label>
                                        <select name="product_id" class="form-select" id="productSelect">
                                            <option value="">-- Select Product (Optional) --</option>
                                            <?php foreach ($products as $product): ?>
                                                <option value="<?php echo $product['id']; ?>" 
                                                    data-name="<?php echo h($product['product_name']); ?>"
                                                    data-code="<?php echo h($product['product_code']); ?>"
                                                    data-price="<?php echo $product['selling_price']; ?>"
                                                    <?php echo ($formData['product_id'] == $product['id']) ? 'selected' : ''; ?>>
                                                    <?php echo h($product['product_name']); ?> 
                                                    (<?php echo h($product['product_code']); ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <?php else: ?>
                                <div class="row">
                                    <div class="col-md-8 mb-3">
                                        <label class="form-label">Product Name</label>
                                        <input type="text" name="model_name" class="form-control" 
                                               value="<?php echo h($formData['model_name']); ?>" 
                                               placeholder="Enter product name">
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>

                            <!-- Booking Details -->
                            <div class="form-section">
                                <h5>Booking Details</h5>
                                <div class="row">
                                    <div class="col-md-3 mb-3">
                                        <label class="form-label required-field">Booking Amount</label>
                                        <div class="input-group">
                                            <span class="input-group-text">₹</span>
                                            <input type="number" name="booking_amount" class="form-control" 
                                                   value="<?php echo h($formData['booking_amount']); ?>" 
                                                   step="0.01" min="0" required>
                                        </div>
                                    </div>

                                    <div class="col-md-3 mb-3">
                                        <label class="form-label">Payment Method</label>
                                        <select name="payment_method_id" class="form-select">
                                            <option value="">-- Select Method --</option>
                                            <?php foreach ($paymentMethods as $method): ?>
                                                <option value="<?php echo $method['id']; ?>" 
                                                    <?php echo ($formData['payment_method_id'] == $method['id']) ? 'selected' : ''; ?>>
                                                    <?php echo h($method['method_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="col-md-3 mb-3">
                                        <label class="form-label">Expected Delivery Date</label>
                                        <input type="date" name="expected_delivery_date" class="form-control" 
                                               value="<?php echo h($formData['expected_delivery_date']); ?>">
                                    </div>

                                    <div class="col-md-3 mb-3">
                                        <label class="form-label required-field">Status</label>
                                        <select name="status" class="form-select" required>
                                            <option value="open" <?php echo $formData['status'] === 'open' ? 'selected' : ''; ?>>Open</option>
                                            <option value="confirmed" <?php echo $formData['status'] === 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                                            <option value="cancelled" <?php echo $formData['status'] === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                            <option value="converted" <?php echo $formData['status'] === 'converted' ? 'selected' : ''; ?>>Converted</option>
                                            <option value="delivered" <?php echo $formData['status'] === 'delivered' ? 'selected' : ''; ?>>Delivered</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-12 mb-3">
                                        <label class="form-label">Notes</label>
                                        <textarea name="notes" class="form-control" rows="3"><?php echo h($formData['notes']); ?></textarea>
                                    </div>
                                </div>
                            </div>

                            <!-- Form Actions -->
                            <div class="row mt-4">
                                <div class="col-12">
                                    <button type="submit" class="btn btn-primary">Update Pre Booking</button>
                                    <a href="pre-booking-view.php?id=<?php echo $bookingId; ?>" class="btn btn-secondary ms-2">Cancel</a>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Info Card -->
                <div class="row">
                    <div class="col-12">
                        <div class="card bg-light">
                            <div class="card-body">
                                <div class="d-flex">
                                    <div class="flex-shrink-0 me-3">
                                        <i class="ri-information-line" style="font-size: 2rem; color: #6c757d;"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <h5 class="mb-2">About Editing Pre Bookings</h5>
                                        <p class="text-muted mb-0">
                                            Update pre booking details as needed. The booking number cannot be changed. 
                                            Changes to status will affect reporting and follow-up actions. Use "Convert" 
                                            from the view page to create a sales invoice from this booking.
                                        </p>
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

<script>
// Auto-hide alerts after 5 seconds
setTimeout(function() {
    var alerts = document.querySelectorAll('.alert');
    alerts.forEach(function(alert) {
        var bsAlert = new bootstrap.Alert(alert);
        bsAlert.close();
    });
}, 5000);

// Toggle between vehicle and product fields
document.querySelectorAll('input[name="booking_type"]').forEach(function(radio) {
    radio.addEventListener('change', function() {
        if (this.value === 'vehicle') {
            document.querySelector('.vehicle-fields').style.display = 'block';
            document.querySelector('.product-fields').style.display = 'none';
        } else {
            document.querySelector('.vehicle-fields').style.display = 'none';
            document.querySelector('.product-fields').style.display = 'block';
        }
    });
});

// Auto-fill vehicle details from selected model
<?php if ($hasVehicleModels && !empty($vehicleModels)): ?>
document.getElementById('fillFromModelBtn')?.addEventListener('click', function() {
    var select = document.getElementById('vehicleModelSelect');
    if (select.value) {
        var selected = select.options[select.selectedIndex];
        var brand = selected.dataset.brand || '';
        var model = selected.dataset.model || '';
        var variant = selected.dataset.variant || '';
        
        document.querySelector('input[name="brand_name"]').value = brand;
        document.querySelector('input[name="model_name"]').value = model;
        document.querySelector('input[name="variant_name"]').value = variant;
    } else {
        alert('Please select a model first.');
    }
});
<?php endif; ?>

// Auto-fill product details
<?php if ($hasProducts && !empty($products)): ?>
document.getElementById('productSelect')?.addEventListener('change', function() {
    if (this.value) {
        var selected = this.options[this.selectedIndex];
        document.querySelector('input[name="model_name"]').value = selected.dataset.name || '';
    }
});
<?php endif; ?>
</script>

</body>
</html>