<?php
session_start();
date_default_timezone_set('Asia/Kolkata');

require_once 'includes/config.php';

/*
|--------------------------------------------------------------------------
| Business Admin Customers - customers.php
|--------------------------------------------------------------------------
| This page displays and manages customers with:
| - List view with search and filters
| - Add/Edit customer functionality
| - Link to separate customer details page
|--------------------------------------------------------------------------
*/

if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not available.");
}
$conn->set_charset("utf8mb4");

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
    header("Location: login.php");
    exit;
}

/* -------------------------------------------------------
   HELPERS
------------------------------------------------------- */
function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
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

    $stmt->bind_param("s", $table);
    $stmt->execute();
    $res = $stmt->get_result();
    $exists = $res && $res->num_rows > 0;
    $stmt->close();

    return $exists;
}

/* -------------------------------------------------------
   FETCH LOGGED-IN BUSINESS USER
------------------------------------------------------- */
$admin = null;
if (tableExists($conn, 'business_users') && tableExists($conn, 'businesses')) {
    $sql = "SELECT 
                bu.id,
                bu.business_id,
                bu.branch_id,
                bu.full_name,
                bu.username,
                bu.email,
                bu.mobile,
                bu.role,
                bu.status,
                b.business_name,
                b.business_code,
                b.gstin,
                b.status AS business_status
            FROM business_users bu
            INNER JOIN businesses b ON b.id = bu.business_id
            WHERE bu.id = ? AND bu.business_id = ?
            LIMIT 1";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("ii", $businessUserId, $businessId);
        $stmt->execute();
        $admin = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}

if (!$admin || (int)$admin['status'] !== 1 || ($admin['business_status'] ?? '') !== 'active') {
    session_destroy();
    header("Location: login.php");
    exit;
}

/* -------------------------------------------------------
   HANDLE POST REQUESTS (Add/Edit/Delete)
------------------------------------------------------- */
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        
        // ADD CUSTOMER
        if ($_POST['action'] === 'add' && tableExists($conn, 'customers')) {
            $fullName = trim($_POST['full_name'] ?? '');
            $mobile = trim($_POST['mobile'] ?? '');
            $alternateMobile = trim($_POST['alternate_mobile'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $dob = !empty($_POST['dob']) ? $_POST['dob'] : null;
            $gender = $_POST['gender'] ?? null;
            $aadharNo = trim($_POST['aadhar_no'] ?? '');
            $panNo = trim($_POST['pan_no'] ?? '');
            $drivingLicenseNo = trim($_POST['driving_license_no'] ?? '');
            $gstin = trim($_POST['gstin'] ?? '');
            $addressLine1 = trim($_POST['address_line1'] ?? '');
            $addressLine2 = trim($_POST['address_line2'] ?? '');
            $city = trim($_POST['city'] ?? '');
            $district = trim($_POST['district'] ?? '');
            $state = trim($_POST['state'] ?? '');
            $pincode = trim($_POST['pincode'] ?? '');
            $notes = trim($_POST['notes'] ?? '');

            if (empty($fullName) || empty($mobile)) {
                $message = 'Name and Mobile are required fields.';
                $messageType = 'danger';
            } else {
                // Generate customer code
                $customerCode = 'CUST' . str_pad(mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);
                
                $sql = "INSERT INTO customers (
                            business_id, customer_code, full_name, mobile, alternate_mobile, 
                            email, dob, gender, aadhar_no, pan_no, driving_license_no, gstin,
                            address_line1, address_line2, city, district, state, pincode, notes
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                
                $stmt = $conn->prepare($sql);
                if ($stmt) {
                    $stmt->bind_param(
                        "issssssssssssssssss",
                        $businessId, $customerCode, $fullName, $mobile, $alternateMobile,
                        $email, $dob, $gender, $aadharNo, $panNo, $drivingLicenseNo, $gstin,
                        $addressLine1, $addressLine2, $city, $district, $state, $pincode, $notes
                    );
                    
                    if ($stmt->execute()) {
                        $message = 'Customer added successfully.';
                        $messageType = 'success';
                        
                        // Add to audit log
                        if (tableExists($conn, 'audit_logs')) {
                            $auditSql = "INSERT INTO audit_logs (business_id, branch_id, user_id, action, module_name, ref_table, ref_id, description) 
                                         VALUES (?, ?, ?, 'Add Customer', 'Customers', 'customers', ?, ?)";
                            $auditStmt = $conn->prepare($auditSql);
                            if ($auditStmt) {
                                $newId = $stmt->insert_id;
                                $desc = "Added customer: " . $fullName . " (Mobile: " . $mobile . ")";
                                $auditStmt->bind_param("iiiis", $businessId, $branchId, $businessUserId, $newId, $desc);
                                $auditStmt->execute();
                                $auditStmt->close();
                            }
                        }
                    } else {
                        $message = 'Error adding customer: ' . $conn->error;
                        $messageType = 'danger';
                    }
                    $stmt->close();
                }
            }
        }
        
        // EDIT CUSTOMER
        elseif ($_POST['action'] === 'edit' && isset($_POST['customer_id'])) {
            $customerId = (int)$_POST['customer_id'];
            $fullName = trim($_POST['full_name'] ?? '');
            $mobile = trim($_POST['mobile'] ?? '');
            $alternateMobile = trim($_POST['alternate_mobile'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $dob = !empty($_POST['dob']) ? $_POST['dob'] : null;
            $gender = $_POST['gender'] ?? null;
            $aadharNo = trim($_POST['aadhar_no'] ?? '');
            $panNo = trim($_POST['pan_no'] ?? '');
            $drivingLicenseNo = trim($_POST['driving_license_no'] ?? '');
            $gstin = trim($_POST['gstin'] ?? '');
            $addressLine1 = trim($_POST['address_line1'] ?? '');
            $addressLine2 = trim($_POST['address_line2'] ?? '');
            $city = trim($_POST['city'] ?? '');
            $district = trim($_POST['district'] ?? '');
            $state = trim($_POST['state'] ?? '');
            $pincode = trim($_POST['pincode'] ?? '');
            $notes = trim($_POST['notes'] ?? '');

            if (empty($fullName) || empty($mobile)) {
                $message = 'Name and Mobile are required fields.';
                $messageType = 'danger';
            } else {
                $sql = "UPDATE customers SET 
                            full_name = ?, mobile = ?, alternate_mobile = ?, email = ?,
                            dob = ?, gender = ?, aadhar_no = ?, pan_no = ?, driving_license_no = ?, gstin = ?,
                            address_line1 = ?, address_line2 = ?, city = ?, district = ?, state = ?, pincode = ?,
                            notes = ?
                        WHERE id = ? AND business_id = ?";
                
                $stmt = $conn->prepare($sql);
                if ($stmt) {
                    $stmt->bind_param(
                        "ssssssssssssssssssii",
                        $fullName, $mobile, $alternateMobile, $email,
                        $dob, $gender, $aadharNo, $panNo, $drivingLicenseNo, $gstin,
                        $addressLine1, $addressLine2, $city, $district, $state, $pincode,
                        $notes, $customerId, $businessId
                    );
                    
                    if ($stmt->execute()) {
                        $message = 'Customer updated successfully.';
                        $messageType = 'success';
                        
                        // Add to audit log
                        if (tableExists($conn, 'audit_logs')) {
                            $auditSql = "INSERT INTO audit_logs (business_id, branch_id, user_id, action, module_name, ref_table, ref_id, description) 
                                         VALUES (?, ?, ?, 'Edit Customer', 'Customers', 'customers', ?, ?)";
                            $auditStmt = $conn->prepare($auditSql);
                            if ($auditStmt) {
                                $desc = "Updated customer: " . $fullName;
                                $auditStmt->bind_param("iiiis", $businessId, $branchId, $businessUserId, $customerId, $desc);
                                $auditStmt->execute();
                                $auditStmt->close();
                            }
                        }
                    } else {
                        $message = 'Error updating customer: ' . $conn->error;
                        $messageType = 'danger';
                    }
                    $stmt->close();
                }
            }
        }
        
        // DELETE CUSTOMER
        elseif ($_POST['action'] === 'delete' && isset($_POST['customer_id'])) {
            $customerId = (int)$_POST['customer_id'];
            
            // Check if customer has vehicles or transactions
            $hasVehicles = false;
            if (tableExists($conn, 'customer_vehicles')) {
                $checkSql = "SELECT id FROM customer_vehicles WHERE customer_id = ? AND business_id = ? LIMIT 1";
                $checkStmt = $conn->prepare($checkSql);
                if ($checkStmt) {
                    $checkStmt->bind_param("ii", $customerId, $businessId);
                    $checkStmt->execute();
                    $hasVehicles = $checkStmt->get_result()->num_rows > 0;
                    $checkStmt->close();
                }
            }
            
            if ($hasVehicles) {
                $message = 'Cannot delete customer with existing vehicles.';
                $messageType = 'danger';
            } else {
                $sql = "DELETE FROM customers WHERE id = ? AND business_id = ?";
                $stmt = $conn->prepare($sql);
                if ($stmt) {
                    $stmt->bind_param("ii", $customerId, $businessId);
                    
                    if ($stmt->execute()) {
                        $message = 'Customer deleted successfully.';
                        $messageType = 'success';
                        
                        // Add to audit log
                        if (tableExists($conn, 'audit_logs')) {
                            $auditSql = "INSERT INTO audit_logs (business_id, branch_id, user_id, action, module_name, ref_table, ref_id, description) 
                                         VALUES (?, ?, ?, 'Delete Customer', 'Customers', 'customers', ?, ?)";
                            $auditStmt = $conn->prepare($auditSql);
                            if ($auditStmt) {
                                $desc = "Deleted customer ID: " . $customerId;
                                $auditStmt->bind_param("iiiis", $businessId, $branchId, $businessUserId, $customerId, $desc);
                                $auditStmt->execute();
                                $auditStmt->close();
                            }
                        }
                    } else {
                        $message = 'Error deleting customer: ' . $conn->error;
                        $messageType = 'danger';
                    }
                    $stmt->close();
                }
            }
        }
    }
}

/* -------------------------------------------------------
   FETCH CUSTOMERS WITH FILTERS AND PAGINATION
------------------------------------------------------- */
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$filterCity = isset($_GET['city']) ? trim($_GET['city']) : '';
$filterState = isset($_GET['state']) ? trim($_GET['state']) : '';

$whereClause = "c.business_id = {$businessId}";
if (!empty($search)) {
    $searchEscaped = $conn->real_escape_string($search);
    $whereClause .= " AND (c.full_name LIKE '%{$searchEscaped}%' 
                        OR c.mobile LIKE '%{$searchEscaped}%' 
                        OR c.email LIKE '%{$searchEscaped}%'
                        OR c.customer_code LIKE '%{$searchEscaped}%'
                        OR c.aadhar_no LIKE '%{$searchEscaped}%'
                        OR c.pan_no LIKE '%{$searchEscaped}%')";
}
if (!empty($filterCity)) {
    $filterCityEscaped = $conn->real_escape_string($filterCity);
    $whereClause .= " AND c.city = '{$filterCityEscaped}'";
}
if (!empty($filterState)) {
    $filterStateEscaped = $conn->real_escape_string($filterState);
    $whereClause .= " AND c.state = '{$filterStateEscaped}'";
}

// Get total count for pagination
$totalCustomers = 0;
if (tableExists($conn, 'customers')) {
    $countSql = "SELECT COUNT(*) AS total FROM customers c WHERE {$whereClause}";
    $countRes = $conn->query($countSql);
    if ($countRes) {
        $totalCustomers = $countRes->fetch_assoc()['total'];
    }
}

$totalPages = ceil($totalCustomers / $limit);

// Fetch customers
$customers = [];
if (tableExists($conn, 'customers')) {
    // Get vehicle count for each customer
    $sql = "SELECT 
                c.*,
                (SELECT COUNT(*) FROM customer_vehicles cv WHERE cv.customer_id = c.id AND cv.business_id = c.business_id) AS vehicle_count
            FROM customers c
            WHERE {$whereClause}
            ORDER BY c.id DESC
            LIMIT {$limit} OFFSET {$offset}";
    
    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $customers[] = $row;
        }
    }
}

// Get unique cities and states for filters
$cities = [];
$states = [];
if (tableExists($conn, 'customers')) {
    $citySql = "SELECT DISTINCT city FROM customers WHERE business_id = {$businessId} AND city IS NOT NULL AND city != '' ORDER BY city";
    $cityRes = $conn->query($citySql);
    if ($cityRes) {
        while ($row = $cityRes->fetch_assoc()) {
            $cities[] = $row['city'];
        }
    }
    
    $stateSql = "SELECT DISTINCT state FROM customers WHERE business_id = {$businessId} AND state IS NOT NULL AND state != '' ORDER BY state";
    $stateRes = $conn->query($stateSql);
    if ($stateRes) {
        while ($row = $stateRes->fetch_assoc()) {
            $states[] = $row['state'];
        }
    }
}

// Get customer for editing if ID is provided
$editCustomer = null;
if (isset($_GET['edit']) && !empty($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    $editSql = "SELECT * FROM customers WHERE id = ? AND business_id = ?";
    $editStmt = $conn->prepare($editSql);
    if ($editStmt) {
        $editStmt->bind_param("ii", $editId, $businessId);
        $editStmt->execute();
        $editCustomer = $editStmt->get_result()->fetch_assoc();
        $editStmt->close();
    }
}

/* -------------------------------------------------------
   PAGE VARIABLES
------------------------------------------------------- */
$pageTitle = 'Customers';
$currentPage = 'customers';
?>
<!doctype html>
<html lang="en">

<?php include('includes/head.php'); ?>

<body data-sidebar="dark">

<style>
    .page-content {
        padding-bottom: 90px !important;
    }
    .report-last-row {
        margin-bottom: 40px;
    }
    .card {
        margin-bottom: 24px;
    }
    .table-responsive {
        overflow-x: auto;
    }
    .main-content {
        min-height: calc(100vh - 70px);
    }
    .btn-group-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 5px;
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

                <!-- Page Title -->
                <div class="row mb-3">
                    <div class="col-md-7">
                        <h4 class="mb-1">Customers</h4>
                        <p class="text-muted mb-0">Manage customer information and vehicle ownership</p>
                    </div>
                    <div class="col-md-5 text-md-end mt-3 mt-md-0">
                        <button type="button" class="btn btn-primary me-2" data-bs-toggle="modal" data-bs-target="#addCustomerModal">
                            <i class="ri-add-line align-middle me-1"></i> Add New Customer
                        </button>
                        <a href="customer-vehicles.php" class="btn btn-secondary">
                            <i class="ri-car-line me-1"></i> View Vehicles
                        </a>
                    </div>
                </div>

                <?php if (!empty($message)): ?>
                    <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
                        <?php echo h($message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <?php if ($editCustomer): ?>
                    <!-- Edit Customer Form -->
                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-body">
                                    <div class="d-flex align-items-center justify-content-between mb-4">
                                        <h4 class="card-title mb-0">Edit Customer</h4>
                                        <a href="customers.php" class="btn btn-secondary btn-sm">
                                            <i class="ri-arrow-go-back-line"></i> Back to List
                                        </a>
                                    </div>

                                    <form method="POST" action="customers.php">
                                        <input type="hidden" name="action" value="edit">
                                        <input type="hidden" name="customer_id" value="<?php echo $editCustomer['id']; ?>">
                                        
                                        <div class="row">
                                            <div class="col-md-4">
                                                <div class="mb-3">
                                                    <label class="form-label">Full Name <span class="text-danger">*</span></label>
                                                    <input type="text" class="form-control" name="full_name" 
                                                           value="<?php echo h($editCustomer['full_name']); ?>" required>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="mb-3">
                                                    <label class="form-label">Mobile <span class="text-danger">*</span></label>
                                                    <input type="text" class="form-control" name="mobile" 
                                                           value="<?php echo h($editCustomer['mobile']); ?>" required>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="mb-3">
                                                    <label class="form-label">Alternate Mobile</label>
                                                    <input type="text" class="form-control" name="alternate_mobile" 
                                                           value="<?php echo h($editCustomer['alternate_mobile'] ?? ''); ?>">
                                                </div>
                                            </div>
                                        </div>

                                        <div class="row">
                                            <div class="col-md-4">
                                                <div class="mb-3">
                                                    <label class="form-label">Email</label>
                                                    <input type="email" class="form-control" name="email" 
                                                           value="<?php echo h($editCustomer['email'] ?? ''); ?>">
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="mb-3">
                                                    <label class="form-label">Date of Birth</label>
                                                    <input type="date" class="form-control" name="dob" 
                                                           value="<?php echo h($editCustomer['dob'] ?? ''); ?>">
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="mb-3">
                                                    <label class="form-label">Gender</label>
                                                    <select class="form-control" name="gender">
                                                        <option value="">Select Gender</option>
                                                        <option value="male" <?php echo ($editCustomer['gender'] ?? '') === 'male' ? 'selected' : ''; ?>>Male</option>
                                                        <option value="female" <?php echo ($editCustomer['gender'] ?? '') === 'female' ? 'selected' : ''; ?>>Female</option>
                                                        <option value="other" <?php echo ($editCustomer['gender'] ?? '') === 'other' ? 'selected' : ''; ?>>Other</option>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="row">
                                            <div class="col-md-4">
                                                <div class="mb-3">
                                                    <label class="form-label">Aadhar Number</label>
                                                    <input type="text" class="form-control" name="aadhar_no" 
                                                           value="<?php echo h($editCustomer['aadhar_no'] ?? ''); ?>">
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="mb-3">
                                                    <label class="form-label">PAN Number</label>
                                                    <input type="text" class="form-control" name="pan_no" 
                                                           value="<?php echo h($editCustomer['pan_no'] ?? ''); ?>">
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="mb-3">
                                                    <label class="form-label">Driving License</label>
                                                    <input type="text" class="form-control" name="driving_license_no" 
                                                           value="<?php echo h($editCustomer['driving_license_no'] ?? ''); ?>">
                                                </div>
                                            </div>
                                        </div>

                                        <div class="row">
                                            <div class="col-md-4">
                                                <div class="mb-3">
                                                    <label class="form-label">GSTIN</label>
                                                    <input type="text" class="form-control" name="gstin" 
                                                           value="<?php echo h($editCustomer['gstin'] ?? ''); ?>">
                                                </div>
                                            </div>
                                            <div class="col-md-8">
                                                <div class="mb-3">
                                                    <label class="form-label">Address Line 1</label>
                                                    <input type="text" class="form-control" name="address_line1" 
                                                           value="<?php echo h($editCustomer['address_line1'] ?? ''); ?>">
                                                </div>
                                            </div>
                                        </div>

                                        <div class="row">
                                            <div class="col-md-8">
                                                <div class="mb-3">
                                                    <label class="form-label">Address Line 2</label>
                                                    <input type="text" class="form-control" name="address_line2" 
                                                           value="<?php echo h($editCustomer['address_line2'] ?? ''); ?>">
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="mb-3">
                                                    <label class="form-label">City</label>
                                                    <input type="text" class="form-control" name="city" 
                                                           value="<?php echo h($editCustomer['city'] ?? ''); ?>">
                                                </div>
                                            </div>
                                        </div>

                                        <div class="row">
                                            <div class="col-md-4">
                                                <div class="mb-3">
                                                    <label class="form-label">District</label>
                                                    <input type="text" class="form-control" name="district" 
                                                           value="<?php echo h($editCustomer['district'] ?? ''); ?>">
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="mb-3">
                                                    <label class="form-label">State</label>
                                                    <input type="text" class="form-control" name="state" 
                                                           value="<?php echo h($editCustomer['state'] ?? ''); ?>">
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="mb-3">
                                                    <label class="form-label">Pincode</label>
                                                    <input type="text" class="form-control" name="pincode" 
                                                           value="<?php echo h($editCustomer['pincode'] ?? ''); ?>">
                                                </div>
                                            </div>
                                        </div>

                                        <div class="row">
                                            <div class="col-12">
                                                <div class="mb-3">
                                                    <label class="form-label">Notes</label>
                                                    <textarea class="form-control" name="notes" rows="3"><?php echo h($editCustomer['notes'] ?? ''); ?></textarea>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="row">
                                            <div class="col-12">
                                                <button type="submit" class="btn btn-primary">Update Customer</button>
                                                <a href="customers.php" class="btn btn-secondary">Cancel</a>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>

                <?php else: ?>
                    <!-- Customers List View -->
                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-body">
                                    <!-- Filters -->
                                    <div class="row mb-3">
                                        <div class="col-md-4">
                                            <form method="GET" action="customers.php" id="filterForm">
                                                <div class="input-group">
                                                    <input type="text" class="form-control" name="search" 
                                                           placeholder="Search by name, mobile, email..." 
                                                           value="<?php echo h($search); ?>">
                                                    <button class="btn btn-primary" type="submit">
                                                        <i class="ri-search-line"></i>
                                                    </button>
                                                    <?php if (!empty($search) || !empty($filterCity) || !empty($filterState)): ?>
                                                        <a href="customers.php" class="btn btn-secondary">
                                                            <i class="ri-close-line"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                </div>
                                            </form>
                                        </div>
                                        <div class="col-md-3">
                                            <select class="form-select" name="city" form="filterForm" onchange="this.form.submit()">
                                                <option value="">All Cities</option>
                                                <?php foreach ($cities as $city): ?>
                                                    <option value="<?php echo h($city); ?>" <?php echo $filterCity === $city ? 'selected' : ''; ?>>
                                                        <?php echo h($city); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-3">
                                            <select class="form-select" name="state" form="filterForm" onchange="this.form.submit()">
                                                <option value="">All States</option>
                                                <?php foreach ($states as $state): ?>
                                                    <option value="<?php echo h($state); ?>" <?php echo $filterState === $state ? 'selected' : ''; ?>>
                                                        <?php echo h($state); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-2 text-end">
                                            <span class="text-muted">Total: <?php echo number_format($totalCustomers); ?> customers</span>
                                        </div>
                                    </div>

                                    <!-- Customers Table -->
                                    <div class="table-responsive">
                                        <table class="table table-bordered table-striped align-middle mb-0">
                                            <thead>
                                                <tr>
                                                    <th style="width: 50px;">#</th>
                                                    <th>Customer Code</th>
                                                    <th>Name</th>
                                                    <th>Mobile</th>
                                                    <th>Email</th>
                                                    <th>City</th>
                                                    <th style="width: 100px;">Vehicles</th>
                                                    <th style="width: 120px;">Registered On</th>
                                                    <th style="width: 260px;">Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (!empty($customers)): ?>
                                                    <?php $sno = $offset + 1; foreach ($customers as $customer): ?>
                                                        <tr>
                                                            <td><?php echo $sno++; ?></td>
                                                            <td>
                                                                <?php if (!empty($customer['customer_code'])): ?>
                                                                    <span class="badge bg-info">
                                                                        <?php echo h($customer['customer_code']); ?>
                                                                    </span>
                                                                <?php else: ?>
                                                                    <span class="text-muted">-</span>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td>
                                                                <strong><?php echo h($customer['full_name']); ?></strong>
                                                            </td>
                                                            <td><?php echo h($customer['mobile']); ?></td>
                                                            <td><?php echo !empty($customer['email']) ? h($customer['email']) : '-'; ?></td>
                                                            <td><?php echo !empty($customer['city']) ? h($customer['city']) : '-'; ?></td>
                                                            <td>
                                                                <span class="badge bg-<?php echo ($customer['vehicle_count'] ?? 0) > 0 ? 'success' : 'secondary'; ?>">
                                                                    <?php echo ($customer['vehicle_count'] ?? 0); ?> vehicles
                                                                </span>
                                                            </td>
                                                            <td><?php echo date('d M Y', strtotime($customer['created_at'])); ?></td>
                                                            <td>
                                                                <div class="d-flex flex-wrap gap-2">
                                                                    <a href="customer-view.php?id=<?php echo (int)$customer['id']; ?>" class="btn btn-sm btn-info">View</a>
                                                                    <a href="customers.php?edit=<?php echo (int)$customer['id']; ?>" class="btn btn-sm btn-primary">Edit</a>
                                                                    <?php if (($customer['vehicle_count'] ?? 0) > 0): ?>
                                                                        <a href="customer-vehicles.php?customer_id=<?php echo (int)$customer['id']; ?>" class="btn btn-sm btn-success">Vehicles</a>
                                                                    <?php endif; ?>
                                                                    <form method="post" style="display:inline;" onsubmit="return confirm('Delete this customer? This action cannot be undone.');">
                                                                        <input type="hidden" name="action" value="delete">
                                                                        <input type="hidden" name="customer_id" value="<?php echo (int)$customer['id']; ?>">
                                                                        <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                                                                    </form>
                                                                </div>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <tr>
                                                        <td colspan="9" class="text-center text-muted py-4">
                                                            No customers found. 
                                                            <a href="#" data-bs-toggle="modal" data-bs-target="#addCustomerModal">Add your first customer</a>
                                                        </td>
                                                    </tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>

                                    <!-- Pagination -->
                                    <?php if ($totalPages > 1): ?>
                                        <div class="row mt-4">
                                            <div class="col-12">
                                                <nav>
                                                    <ul class="pagination justify-content-center">
                                                        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                                            <a class="page-link" href="?page=<?php echo $page-1; ?>&search=<?php echo urlencode($search); ?>&city=<?php echo urlencode($filterCity); ?>&state=<?php echo urlencode($filterState); ?>" tabindex="-1">Previous</a>
                                                        </li>
                                                        
                                                        <?php 
                                                        $startPage = max(1, $page - 2);
                                                        $endPage = min($totalPages, $page + 2);
                                                        
                                                        for ($i = $startPage; $i <= $endPage; $i++): 
                                                        ?>
                                                            <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                                                <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&city=<?php echo urlencode($filterCity); ?>&state=<?php echo urlencode($filterState); ?>">
                                                                    <?php echo $i; ?>
                                                                </a>
                                                            </li>
                                                        <?php endfor; ?>
                                                        
                                                        <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                                                            <a class="page-link" href="?page=<?php echo $page+1; ?>&search=<?php echo urlencode($search); ?>&city=<?php echo urlencode($filterCity); ?>&state=<?php echo urlencode($filterState); ?>">Next</a>
                                                        </li>
                                                    </ul>
                                                </nav>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

            </div>
        </div>

        <?php include('includes/footer.php'); ?>
    </div>
</div>

<!-- Add Customer Modal -->
<div class="modal fade" id="addCustomerModal" tabindex="-1" aria-labelledby="addCustomerModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="customers.php">
                <input type="hidden" name="action" value="add">
                
                <div class="modal-header">
                    <h5 class="modal-title" id="addCustomerModalLabel">Add New Customer</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Full Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="full_name" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Mobile <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="mobile" required>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Alternate Mobile</label>
                                <input type="text" class="form-control" name="alternate_mobile">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" class="form-control" name="email">
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Date of Birth</label>
                                <input type="date" class="form-control" name="dob">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Gender</label>
                                <select class="form-control" name="gender">
                                    <option value="">Select Gender</option>
                                    <option value="male">Male</option>
                                    <option value="female">Female</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Aadhar Number</label>
                                <input type="text" class="form-control" name="aadhar_no">
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">PAN Number</label>
                                <input type="text" class="form-control" name="pan_no">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Driving License</label>
                                <input type="text" class="form-control" name="driving_license_no">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">GSTIN</label>
                                <input type="text" class="form-control" name="gstin">
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-8">
                            <div class="mb-3">
                                <label class="form-label">Address Line 1</label>
                                <input type="text" class="form-control" name="address_line1">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Address Line 2</label>
                                <input type="text" class="form-control" name="address_line2">
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label class="form-label">City</label>
                                <input type="text" class="form-control" name="city">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label class="form-label">District</label>
                                <input type="text" class="form-control" name="district">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label class="form-label">State</label>
                                <input type="text" class="form-control" name="state">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label class="form-label">Pincode</label>
                                <input type="text" class="form-control" name="pincode">
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-12">
                            <div class="mb-3">
                                <label class="form-label">Notes</label>
                                <textarea class="form-control" name="notes" rows="2"></textarea>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Customer</button>
                </div>
            </form>
        </div>
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
</script>

</body>
</html>