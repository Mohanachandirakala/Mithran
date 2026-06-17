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
$sessionBranchId = isset($_SESSION['branch_id']) ? (int)$_SESSION['branch_id'] : 0;

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

function formatDisplayDate($dateValue): string
{
    if (empty($dateValue) || $dateValue == '0000-00-00' || $dateValue == '0000-00-00 00:00:00') {
        return '-';
    }
    $timestamp = strtotime($dateValue);
    if (!$timestamp || $timestamp <= 0) {
        return '-';
    }
    return date('d-m-Y', $timestamp);
}

function formatDisplayDateTime($dateValue): string
{
    if (empty($dateValue) || $dateValue == '0000-00-00' || $dateValue == '0000-00-00 00:00:00') {
        return '-';
    }
    $timestamp = strtotime($dateValue);
    if (!$timestamp || $timestamp <= 0) {
        return '-';
    }
    return date('d-m-Y h:i A', $timestamp);
}

function tableExists(mysqli $conn, string $table): bool
{
    $sql = "SELECT 1
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
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
   GET SELECTED BRANCH (from GET or SESSION)
------------------------------------------------------- */
$selectedBranchId = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : $sessionBranchId;

// If branch changed via filter, update session
if (isset($_GET['branch_id']) && $_GET['branch_id'] != $sessionBranchId) {
    $_SESSION['branch_id'] = $selectedBranchId;
}

/* -------------------------------------------------------
   MASTER DATA
------------------------------------------------------- */
$branches = [];
$branchResult = $conn->query("SELECT id, branch_name, branch_code FROM branches WHERE business_id = {$businessId} AND status = 'active' ORDER BY branch_name ASC");
if ($branchResult) {
    while ($row = $branchResult->fetch_assoc()) {
        $branches[] = $row;
    }
}

// Get selected branch name
$selectedBranchName = '';
$selectedBranchCode = '';
if ($selectedBranchId > 0) {
    foreach ($branches as $branch) {
        if ($branch['id'] == $selectedBranchId) {
            $selectedBranchName = $branch['branch_name'];
            $selectedBranchCode = $branch['branch_code'];
            break;
        }
    }
}

$customers = [];
$customerResult = $conn->query("SELECT id, full_name, mobile FROM customers WHERE business_id = {$businessId} ORDER BY full_name ASC");
if ($customerResult) {
    while ($row = $customerResult->fetch_assoc()) {
        $customers[] = $row;
    }
}

/* -------------------------------------------------------
   FILTERS
------------------------------------------------------- */
$search = trim($_GET['search'] ?? '');
$branchFilter = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : $selectedBranchId;
$customerFilter = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;
$deliveryStatus = trim($_GET['delivery_status'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');

/* -------------------------------------------------------
   FETCH VEHICLE SALES FROM INVOICES, INVOICE ITEMS AND VEHICLE SALES
------------------------------------------------------- */
$whereConditions = ["si.business_id = {$businessId}"];
$whereConditions[] = "si.invoice_type IN ('vehicle_sale', 'mixed_sale')";
$whereConditions[] = "sii.item_type = 'vehicle'";

// Branch filter
if ($branchFilter > 0) {
    $whereConditions[] = "si.branch_id = {$branchFilter}";
}

// Date range filter
if ($dateFrom !== '') {
    $whereConditions[] = "DATE(si.invoice_date) >= '{$dateFrom}'";
}
if ($dateTo !== '') {
    $whereConditions[] = "DATE(si.invoice_date) <= '{$dateTo}'";
}

// Search filter
if ($search !== '') {
    $safe = $conn->real_escape_string($search);
    $whereConditions[] = "(
        si.invoice_no LIKE '%{$safe}%'
        OR c.full_name LIKE '%{$safe}%'
        OR c.mobile LIKE '%{$safe}%'
        OR br.branch_name LIKE '%{$safe}%'
        OR vstock.chassis_no LIKE '%{$safe}%'
        OR vstock.engine_no LIKE '%{$safe}%'
        OR vm.model_name LIKE '%{$safe}%'
        OR vb.brand_name LIKE '%{$safe}%'
        OR vs.registration_no LIKE '%{$safe}%'
    )";
}

// Customer filter
if ($customerFilter > 0) {
    $whereConditions[] = "si.customer_id = {$customerFilter}";
}

// Delivery status filter based on registration number from vehicle_sales table
if ($deliveryStatus === 'delivered') {
    $whereConditions[] = "vs.registration_no IS NOT NULL AND vs.registration_no != ''";
} elseif ($deliveryStatus === 'pending') {
    $whereConditions[] = "(vs.registration_no IS NULL OR vs.registration_no = '')";
}

$whereSql = implode(' AND ', $whereConditions);

// Main query to get vehicle sales from invoices
$sql = "SELECT
            si.id as invoice_id,
            si.invoice_no,
            si.invoice_date,
            si.payment_status,
            si.sale_status,
            si.grand_total,
            si.paid_amount,
            si.balance_amount,
            si.customer_note,

            br.id as branch_id,
            br.branch_name,
            br.branch_code,

            c.id as customer_id,
            c.full_name AS customer_name,
            c.mobile AS customer_mobile,
            c.alternate_mobile,
            c.email AS customer_email,
            c.address_line1,
            c.city AS customer_city,
            c.district AS customer_district,
            c.state AS customer_state,
            c.pincode AS customer_pincode,

            sii.id as item_id,
            sii.item_type,
            sii.vehicle_stock_id,
            sii.description,
            sii.qty,
            sii.unit_price,
            sii.purchase_price,
            sii.discount_amount as item_discount,
            sii.taxable_value,
            sii.cgst_percent,
            sii.sgst_percent,
            sii.cgst_amount,
            sii.sgst_amount,
            sii.line_total,
            sii.profit_amount,

            vs.id as vehicle_sale_id,
            vs.sale_date,
            vs.registration_no,
            vs.temp_registration_no,
            vs.rto_state,
            vs.rto_office,
            vs.rto_file_no,
            vs.rto_application_no,
            vs.insurance_policy_no,
            vs.insurance_company,
            vs.insurance_start_date,
            vs.insurance_end_date,
            vs.insurance_amount,
            vs.insurance_premium,
            vs.insurance_idv,
            vs.registration_amount,
            vs.rto_charge,
            vs.road_tax_amount,
            vs.hypothecation_charge,
            vs.handling_charge,
            vs.fastag_charge,
            vs.total_vehicle_amount,
            vs.battery_brand,
            vs.battery_no,
            vs.battery_capacity,
            vs.battery_warranty_upto,
            vs.charger_brand,
            vs.charger_no,
            vs.charger_type,
            vs.charger_warranty_upto,

            vstock.id as stock_id,
            vstock.chassis_no,
            vstock.engine_no,
            vstock.motor_no,
            vstock.color,
            vstock.manufacture_year,
            vstock.key_no,

            vm.model_name,
            vm.variant_name,
            vm.vehicle_type,
            vm.fuel_type,
            vm.ex_showroom_price as model_ex_price,

            vb.brand_name

        FROM sales_invoices si
        INNER JOIN customers c ON c.id = si.customer_id
        INNER JOIN branches br ON br.id = si.branch_id
        INNER JOIN sales_invoice_items sii ON sii.invoice_id = si.id
        LEFT JOIN vehicle_sales vs ON vs.invoice_id = si.id
        LEFT JOIN vehicle_stock vstock ON vstock.id = sii.vehicle_stock_id
        LEFT JOIN vehicle_models vm ON vm.id = vstock.model_id
        LEFT JOIN vehicle_brands vb ON vb.id = vm.brand_id

        WHERE {$whereSql}
        ORDER BY si.invoice_date DESC, si.id DESC";

$rows = [];
$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
}

/* -------------------------------------------------------
   SUMMARY STATISTICS
------------------------------------------------------- */
$totalSales = count($rows);
$totalSalesAmount = 0;
$deliveredCount = 0;
$pendingCount = 0;
$totalDeliveredAmount = 0;
$totalPendingAmount = 0;

foreach ($rows as $row) {
    $totalSalesAmount += (float)($row['grand_total'] ?? 0);
    
    // Check if vehicle has registration number (considered delivered)
    $isDelivered = !empty($row['registration_no']);
    
    if ($isDelivered) {
        $deliveredCount++;
        $totalDeliveredAmount += (float)($row['grand_total'] ?? 0);
    } else {
        $pendingCount++;
        $totalPendingAmount += (float)($row['grand_total'] ?? 0);
    }
}

// Today's delivered (based on invoice_date)
$today = date('Y-m-d');
$todayDelivered = 0;
$todayDeliveredAmount = 0;

foreach ($rows as $row) {
    $invoiceDate = date('Y-m-d', strtotime($row['invoice_date']));
    if ($invoiceDate == $today && !empty($row['registration_no'])) {
        $todayDelivered++;
        $todayDeliveredAmount += (float)($row['grand_total'] ?? 0);
    }
}

// This month delivered
$currentMonthStart = date('Y-m-01');
$currentMonthEnd = date('Y-m-t');
$monthDelivered = 0;
$monthDeliveredAmount = 0;

foreach ($rows as $row) {
    $invoiceDate = date('Y-m-d', strtotime($row['invoice_date']));
    if ($invoiceDate >= $currentMonthStart && $invoiceDate <= $currentMonthEnd && !empty($row['registration_no'])) {
        $monthDelivered++;
        $monthDeliveredAmount += (float)($row['grand_total'] ?? 0);
    }
}

// Delivery completion rate
$completionRate = $totalSales > 0 ? ($deliveredCount / $totalSales) * 100 : 0;

$pageTitle = 'Vehicle Deliveries';
$currentPage = 'vehicle-deliveries';
?>
<!doctype html>
<html lang="en">

<?php include('includes/head.php'); ?>

<body data-sidebar="dark">

<style>
    .quick-action-card {
        transition: all 0.2s ease;
        cursor: pointer;
        text-decoration: none;
        color: inherit;
        display: block;
    }
    .quick-action-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 8px 20px rgba(0,0,0,0.1);
        border-color: #3b82f6 !important;
    }
    .status-badge {
        padding: 4px 8px;
        border-radius: 4px;
        font-size: 11px;
        font-weight: 500;
        display: inline-block;
    }
    .status-paid { background: #d4edda; color: #155724; }
    .status-partial { background: #fff3cd; color: #856404; }
    .status-unpaid { background: #f8d7da; color: #721c24; }
    .status-confirmed { background: #d1ecf1; color: #0c5460; }
    .status-draft { background: #e2e3e5; color: #383d41; }
    .status-cancelled { background: #f8d7da; color: #721c24; }
    .status-delivered { background: #d4edda; color: #155724; }
    .delivered-badge { background: #d4edda; color: #155724; }
    .pending-badge { background: #fff3cd; color: #856404; }
    .small-text { font-size: 12px; }
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

                <!-- Page Header -->
                <div class="row">
                    <div class="col-12">
                        <div class="card bg-primary text-white">
                            <div class="card-body">
                                <div class="d-flex flex-wrap align-items-center justify-content-between">
                                    <div>
                                        <h4 class="text-white mb-1">Vehicle Deliveries</h4>
                                        <p class="mb-0 opacity-75">Track pending and completed vehicle deliveries</p>
                                    </div>
                                    <div class="text-end">
                                        <div class="btn-group">
                                            <a href="sales-invoice-add.php?invoice_type=vehicle_sale" class="btn btn-light">
                                                <i class="ri-add-line me-1"></i> New Vehicle Sale
                                            </a>
                                            <a href="vehicle-sales.php" class="btn btn-outline-light">
                                                <i class="ri-car-line me-1"></i> Vehicle Sales
                                            </a>
                                            <a href="sales-invoices.php" class="btn btn-outline-light">
                                                <i class="ri-arrow-left-line me-1"></i> All Invoices
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header bg-light">
                                <h5 class="card-title mb-0">
                                    <i class="ri-flashlight-fill me-2" style="color: #ffc107;"></i>Quick Actions
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-6 col-md-3 col-xl-2">
                                        <a href="sales-invoice-add.php?invoice_type=vehicle_sale" style="text-decoration: none; color: inherit;">
                                            <div class="border rounded p-3 text-center quick-action-card">
                                                <div class="mb-2"><span style="font-size: 28px;">🏍️</span></div>
                                                <h6 class="mb-1">New Vehicle Sale</h6>
                                                <small class="text-muted">Create vehicle invoice</small>
                                            </div>
                                        </a>
                                    </div>
                                    <div class="col-6 col-md-3 col-xl-2">
                                        <a href="vehicle-stock.php" style="text-decoration: none; color: inherit;">
                                            <div class="border rounded p-3 text-center quick-action-card">
                                                <div class="mb-2"><span style="font-size: 28px;">📦</span></div>
                                                <h6 class="mb-1">Vehicle Stock</h6>
                                                <small class="text-muted">Manage inventory</small>
                                            </div>
                                        </a>
                                    </div>
                                    <div class="col-6 col-md-3 col-xl-2">
                                        <a href="customers.php" style="text-decoration: none; color: inherit;">
                                            <div class="border rounded p-3 text-center quick-action-card">
                                                <div class="mb-2"><span style="font-size: 28px;">👤</span></div>
                                                <h6 class="mb-1">Customers</h6>
                                                <small class="text-muted">View customers</small>
                                            </div>
                                        </a>
                                    </div>
                                    <div class="col-6 col-md-3 col-xl-2">
                                        <a href="sales-invoices.php" style="text-decoration: none; color: inherit;">
                                            <div class="border rounded p-3 text-center quick-action-card">
                                                <div class="mb-2"><span style="font-size: 28px;">📋</span></div>
                                                <h6 class="mb-1">All Invoices</h6>
                                                <small class="text-muted">View all sales</small>
                                            </div>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Branch Filter Card -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <form method="get" class="row g-3 align-items-end">
                                    <div class="col-md-2">
                                        <label class="form-label fw-bold">Branch</label>
                                        <select name="branch_id" class="form-select" onchange="this.form.submit()">
                                            <option value="0" <?php echo $branchFilter == 0 ? 'selected' : ''; ?>>-- All Branches --</option>
                                            <?php foreach ($branches as $branch): ?>
                                                <option value="<?php echo $branch['id']; ?>" <?php echo $branchFilter == $branch['id'] ? 'selected' : ''; ?>>
                                                    <?php echo h($branch['branch_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Search</label>
                                        <input type="text" name="search" class="form-control" placeholder="Invoice, customer, chassis..." value="<?php echo h($search); ?>">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Customer</label>
                                        <select name="customer_id" class="form-select">
                                            <option value="0">All Customers</option>
                                            <?php foreach ($customers as $c): ?>
                                                <option value="<?php echo (int)$c['id']; ?>" <?php echo ($customerFilter === (int)$c['id']) ? 'selected' : ''; ?>>
                                                    <?php echo h($c['full_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Delivery Status</label>
                                        <select name="delivery_status" class="form-select">
                                            <option value="">All Status</option>
                                            <option value="pending" <?php echo ($deliveryStatus === 'pending') ? 'selected' : ''; ?>>Pending (No Reg No)</option>
                                            <option value="delivered" <?php echo ($deliveryStatus === 'delivered') ? 'selected' : ''; ?>>Delivered (Has Reg No)</option>
                                        </select>
                                    </div>
                                    <div class="col-md-1">
                                        <label class="form-label">From Date</label>
                                        <input type="date" name="date_from" class="form-control" value="<?php echo h($dateFrom); ?>">
                                    </div>
                                    <div class="col-md-1">
                                        <label class="form-label">To Date</label>
                                        <input type="date" name="date_to" class="form-control" value="<?php echo h($dateTo); ?>">
                                    </div>
                                    <div class="col-md-1">
                                        <button type="submit" class="btn btn-primary w-100">Filter</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Branch Info Alert -->
                <?php if ($branchFilter > 0 && !empty($selectedBranchName)): ?>
                    <div class="alert alert-info alert-dismissible fade show" role="alert">
                        <i class="ri-information-line me-2"></i>
                        Showing deliveries for branch: <strong><?php echo h($selectedBranchName); ?></strong>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php else: ?>
                    <div class="alert alert-secondary alert-dismissible fade show" role="alert">
                        <i class="ri-information-line me-2"></i>
                        Showing deliveries for <strong>All Branches</strong>. Select a branch above to filter by branch.
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Main Statistics Cards -->
                <div class="row">
                    <div class="col-md-6 col-xl-3">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <p class="text-muted mb-1">Total Vehicle Sales</p>
                                        <h3 class="mb-0"><?php echo number_format($totalSales); ?></h3>
                                        <small class="text-muted"><?php echo money($totalSalesAmount); ?></small>
                                    </div>
                                    <div class="avatar-sm">
                                        <div class="avatar-title bg-primary-subtle text-primary rounded-circle">
                                            <i class="ri-car-line fs-4"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <p class="text-muted mb-1">Delivered (Has Reg No)</p>
                                        <h3 class="mb-0 text-success"><?php echo number_format($deliveredCount); ?></h3>
                                        <small class="text-muted"><?php echo money($totalDeliveredAmount); ?></small>
                                    </div>
                                    <div class="avatar-sm">
                                        <div class="avatar-title bg-success-subtle text-success rounded-circle">
                                            <i class="ri-checkbox-circle-line fs-4"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <p class="text-muted mb-1">Pending (No Reg No)</p>
                                        <h3 class="mb-0 text-warning"><?php echo number_format($pendingCount); ?></h3>
                                        <small class="text-muted"><?php echo money($totalPendingAmount); ?></small>
                                    </div>
                                    <div class="avatar-sm">
                                        <div class="avatar-title bg-warning-subtle text-warning rounded-circle">
                                            <i class="ri-time-line fs-4"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <p class="text-muted mb-1">Completion Rate</p>
                                        <h3 class="mb-0"><?php echo number_format($completionRate, 1); ?>%</h3>
                                        <small class="text-muted"><?php echo $deliveredCount; ?> / <?php echo $totalSales; ?> delivered</small>
                                    </div>
                                    <div class="avatar-sm">
                                        <div class="avatar-title bg-info-subtle text-info rounded-circle">
                                            <i class="ri-percent-line fs-4"></i>
                                        </div>
                                    </div>
                                </div>
                                <div class="progress mt-2" style="height: 5px;">
                                    <div class="progress-bar bg-success" style="width: <?php echo $completionRate; ?>%"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Today & Month Stats -->
                <div class="row">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <p class="text-muted mb-2">Today's Sales (Invoice Date)</p>
                                        <h4 class="mb-0"><?php echo number_format($todayDelivered); ?> Vehicles</h4>
                                        <strong><?php echo money($todayDeliveredAmount); ?></strong>
                                    </div>
                                    <div class="avatar-sm">
                                        <div class="avatar-title bg-primary rounded-circle">
                                            <i class="ri-calendar-today-line fs-4 text-white"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <p class="text-muted mb-2">This Month Sales (Invoice Date)</p>
                                        <h4 class="mb-0"><?php echo number_format($monthDelivered); ?> Vehicles</h4>
                                        <strong><?php echo money($monthDeliveredAmount); ?></strong>
                                    </div>
                                    <div class="avatar-sm">
                                        <div class="avatar-title bg-success rounded-circle">
                                            <i class="ri-calendar-month-line fs-4 text-white"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Delivery List Table -->
                <div class="card">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0">
                            <i class="ri-truck-line me-2"></i>Vehicle Delivery List
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>#</th>
                                        <th>Invoice Details</th>
                                        <th>Customer Details</th>
                                        <th>Vehicle Details</th>
                                        <th>Vehicle Numbers</th>
                                        <th>Registration Status</th>
                                        <th>Amount</th>
                                        <th>Payment Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($rows)): ?>
                                        <?php $i = 1; foreach ($rows as $row): 
                                            $hasRegistration = !empty($row['registration_no']);
                                            $brandName = isset($row['brand_name']) && !empty($row['brand_name']) ? $row['brand_name'] : '-';
                                            $modelName = isset($row['model_name']) && !empty($row['model_name']) ? $row['model_name'] : '-';
                                        ?>
                                            <tr>
                                                <td><?php echo $i++; ?></tr>

                                                <!-- Invoice Details -->
                                                <td style="font-size: 12px;">
                                                    <strong><?php echo h($row['invoice_no']); ?></strong><br>
                                                    <span class="text-muted">Date: <?php echo formatDisplayDateTime($row['invoice_date']); ?></span><br>
                                                    <span class="text-muted">Branch: <?php echo h($row['branch_name']); ?></span>
                                                 </td>

                                                <!-- Customer Details -->
                                                <td style="font-size: 12px;">
                                                    <strong><?php echo h($row['customer_name']); ?></strong><br>
                                                    <span class="text-muted">Mobile: <?php echo h($row['customer_mobile'] ?: '-'); ?></span><br>
                                                    <span class="text-muted">City: <?php echo h($row['customer_city'] ?: '-'); ?></span>
                                                 </td>

                                                <!-- Vehicle Details -->
                                                <td style="font-size: 12px;">
                                                    <strong><?php echo h($brandName) . ' ' . h($modelName); ?></strong><br>
                                                    <span class="text-muted">Variant: <?php echo h($row['variant_name'] ?: '-'); ?></span><br>
                                                    <span class="text-muted">Color: <?php echo h($row['color'] ?: '-'); ?></span><br>
                                                    <span class="text-muted">Mfg Year: <?php echo h($row['manufacture_year'] ?: '-'); ?></span>
                                                    <?php if (!empty($row['battery_brand'])): ?>
                                                        <br><span class="text-muted">Battery: <?php echo h($row['battery_brand']); ?></span>
                                                    <?php endif; ?>
                                                 </td>

                                                <!-- Vehicle Numbers -->
                                                <td style="font-size: 12px;">
                                                    <div><strong>Chassis:</strong> <?php echo h($row['chassis_no'] ?: '-'); ?></div>
                                                    <div><strong>Engine:</strong> <?php echo h($row['engine_no'] ?: '-'); ?></div>
                                                    <div><strong>Motor:</strong> <?php echo h($row['motor_no'] ?: '-'); ?></div>
                                                    <div><strong>Key No:</strong> <?php echo h($row['key_no'] ?: '-'); ?></div>
                                                 </td>

                                                <!-- Registration Status -->
                                                <td class="text-center" style="font-size: 12px;">
                                                    <?php if ($hasRegistration): ?>
                                                        <span class="badge bg-success">Registered</span>
                                                        <div class="mt-1"><strong>Reg No:</strong> <?php echo h($row['registration_no']); ?></div>
                                                    <?php else: ?>
                                                        <span class="badge bg-warning">Registration Pending</span>
                                                    <?php endif; ?>
                                                 </td>

                                                <!-- Amount -->
                                                <td class="text-end" style="font-size: 12px;">
                                                    <div><strong>Total:</strong> <?php echo money($row['grand_total']); ?></div>
                                                    <div><strong>Paid:</strong> <?php echo money($row['paid_amount']); ?></div>
                                                    <div><strong>Balance:</strong> <?php echo money($row['balance_amount']); ?></div>
                                                 </td>

                                                <!-- Payment Status -->
                                                <td class="text-center" style="font-size: 12px;">
                                                    <?php
                                                    $payBadge = 'secondary';
                                                    $paymentStatus = strtolower($row['payment_status'] ?? 'unpaid');
                                                    if ($paymentStatus === 'paid') $payBadge = 'success';
                                                    elseif ($paymentStatus === 'partial') $payBadge = 'warning';
                                                    elseif ($paymentStatus === 'unpaid') $payBadge = 'danger';
                                                    ?>
                                                    <span class="status-badge status-<?php echo $payBadge; ?>">
                                                        <?php echo ucfirst($paymentStatus); ?>
                                                    </span>
                                                    <div class="mt-2">
                                                        <a href="sales-invoice-view.php?id=<?php echo $row['invoice_id']; ?>" class="btn btn-sm btn-info w-100" title="View Invoice">
                                                            <i class="ri-eye-line"></i> View
                                                        </a>
                                                        <a href="sales-invoice-print.php?id=<?php echo $row['invoice_id']; ?>" class="btn btn-sm btn-secondary w-100 mt-1" title="Print Invoice" target="_blank">
                                                            <i class="ri-printer-line"></i> Print
                                                        </a>
                                                    </div>
                                                 </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="8" class="text-center text-muted py-5">
                                                <i class="ri-car-off-line" style="font-size: 48px;"></i>
                                                <p class="mt-3 mb-0">No vehicle sales records found.</p>
                                                <p class="text-muted small-text">Try adjusting your filters or create a new vehicle sale.</p>
                                                <a href="sales-invoice-add.php?invoice_type=vehicle_sale" class="btn btn-primary mt-2">
                                                    <i class="ri-add-line me-1"></i> Create Vehicle Sale
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php if (!empty($rows)): ?>
                        <div class="mt-3 text-muted small-text">
                            <i class="ri-information-line"></i> Showing <?php echo count($rows); ?> vehicle sale records.
                        </div>
                        <?php endif; ?>
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