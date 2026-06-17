<?php
session_start();
date_default_timezone_set('Asia/Kolkata');

require_once 'includes/config.php';

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
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function money($amount): string
{
    return '₹' . number_format((float)$amount, 2);
}

function safeDate($dateValue, string $format = 'd M Y'): string
{
    if (empty($dateValue) || $dateValue === '0000-00-00' || $dateValue === '0000-00-00 00:00:00') {
        return '-';
    }

    $ts = strtotime($dateValue);
    if (!$ts || $ts <= 0) {
        return '-';
    }

    return date($format, $ts);
}

function tableExists(mysqli $conn, string $table): bool
{
    $sql = "SELECT 1
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
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

function columnExists(mysqli $conn, string $table, string $column): bool
{
    $sql = "SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            AND COLUMN_NAME = ?
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return false;

    $stmt->bind_param("ss", $table, $column);
    $stmt->execute();
    $res = $stmt->get_result();
    $exists = $res && $res->num_rows > 0;
    $stmt->close();

    return $exists;
}

function fetchSingleFloat(mysqli $conn, string $sql): float
{
    $res = $conn->query($sql);
    if (!$res) return 0.00;
    $row = $res->fetch_assoc();
    $res->free();
    return (float)($row['total'] ?? 0);
}

function fetchSingleInt(mysqli $conn, string $sql): int
{
    $res = $conn->query($sql);
    if (!$res) return 0;
    $row = $res->fetch_assoc();
    $res->free();
    return (int)($row['total'] ?? 0);
}

function getPaymentStatus(float $grandTotal, float $paidAmount, float $balanceAmount, string $storedStatus = ''): array
{
    $grandTotal = max(0, $grandTotal);
    $paidAmount = max(0, $paidAmount);

    if ($balanceAmount < 0) {
        $balanceAmount = 0;
    }

    if ($grandTotal > 0) {
        $calculatedBalance = max($grandTotal - $paidAmount, 0);

        if ($balanceAmount <= 0 && $paidAmount < $grandTotal) {
            $balanceAmount = $calculatedBalance;
        }
    }

    $status = strtolower(trim($storedStatus));

    if ($grandTotal <= 0) {
        $status = 'unpaid';
    } elseif ($paidAmount >= $grandTotal || $balanceAmount <= 0) {
        $status = 'paid';
        $balanceAmount = 0;
    } elseif ($paidAmount > 0 && $paidAmount < $grandTotal) {
        $status = 'partial';
    } else {
        $status = 'unpaid';
        $balanceAmount = $grandTotal;
    }

    $badge = 'danger';
    if ($status === 'paid') {
        $badge = 'success';
    } elseif ($status === 'partial') {
        $badge = 'warning';
    }

    return [
        'status' => $status,
        'badge' => $badge,
        'balance' => $balanceAmount
    ];
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
            WHERE bu.id = ?
            AND bu.business_id = ?
            LIMIT 1";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("ii", $businessUserId, $businessId);
        $stmt->execute();
        $admin = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}

if (!$admin || (int)($admin['status'] ?? 0) !== 1 || ($admin['business_status'] ?? '') !== 'active') {
    session_destroy();
    header("Location: login.php");
    exit;
}

/* -------------------------------------------------------
   GET CUSTOMER
------------------------------------------------------- */
$customerId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($customerId <= 0) {
    header("Location: customers.php");
    exit;
}

$customer = null;
if (tableExists($conn, 'customers')) {
    $stmt = $conn->prepare("SELECT * FROM customers WHERE id = ? AND business_id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("ii", $customerId, $businessId);
        $stmt->execute();
        $customer = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}

if (!$customer) {
    header("Location: customers.php");
    exit;
}

/* -------------------------------------------------------
   TABLE / COLUMN FLAGS
------------------------------------------------------- */
$hasCustomerVehicles = tableExists($conn, 'customer_vehicles');
$hasVehicleBrands = tableExists($conn, 'vehicle_brands');
$hasVehicleModels = tableExists($conn, 'vehicle_models');
$hasServiceJobCards = tableExists($conn, 'service_job_cards');
$hasSalesInvoices = tableExists($conn, 'sales_invoices');
$hasServiceInvoices = tableExists($conn, 'service_invoices');
$hasPreBookings = tableExists($conn, 'pre_bookings');

$salesHasBalance = $hasSalesInvoices && columnExists($conn, 'sales_invoices', 'balance_amount');
$salesHasPaid = $hasSalesInvoices && columnExists($conn, 'sales_invoices', 'paid_amount');
$salesHasPaymentStatus = $hasSalesInvoices && columnExists($conn, 'sales_invoices', 'payment_status');

$serviceHasBalance = $hasServiceInvoices && columnExists($conn, 'service_invoices', 'balance_amount');
$serviceHasPaid = $hasServiceInvoices && columnExists($conn, 'service_invoices', 'paid_amount');
$serviceHasPaymentStatus = $hasServiceInvoices && columnExists($conn, 'service_invoices', 'payment_status');

$salesPaidSelect = $salesHasPaid ? "COALESCE(si.paid_amount,0) AS paid_amount" : "0 AS paid_amount";
$salesBalanceSelect = $salesHasBalance ? "COALESCE(si.balance_amount,0) AS balance_amount" : "GREATEST(COALESCE(si.grand_total,0) - " . ($salesHasPaid ? "COALESCE(si.paid_amount,0)" : "0") . ",0) AS balance_amount";
$salesStatusSelect = $salesHasPaymentStatus ? "COALESCE(si.payment_status,'') AS payment_status" : "'' AS payment_status";

$servicePaidSelect = $serviceHasPaid ? "COALESCE(si.paid_amount,0) AS paid_amount" : "0 AS paid_amount";
$serviceBalanceSelect = $serviceHasBalance ? "COALESCE(si.balance_amount,0) AS balance_amount" : "GREATEST(COALESCE(si.grand_total,0) - " . ($serviceHasPaid ? "COALESCE(si.paid_amount,0)" : "0") . ",0) AS balance_amount";
$serviceStatusSelect = $serviceHasPaymentStatus ? "COALESCE(si.payment_status,'') AS payment_status" : "'' AS payment_status";

/* -------------------------------------------------------
   CUSTOMER VEHICLES
------------------------------------------------------- */
$vehicles = [];

if ($hasCustomerVehicles) {
    $sql = "SELECT 
                cv.*,
                " . ($hasVehicleBrands ? "vb.brand_name" : "NULL AS brand_name") . ",
                " . ($hasVehicleModels ? "vm.model_name, vm.variant_name, vm.vehicle_type" : "NULL AS model_name, NULL AS variant_name, NULL AS vehicle_type") . "
            FROM customer_vehicles cv
            " . ($hasVehicleBrands ? "LEFT JOIN vehicle_brands vb ON vb.id = cv.brand_id" : "") . "
            " . ($hasVehicleModels ? "LEFT JOIN vehicle_models vm ON vm.id = cv.model_id" : "") . "
            WHERE cv.customer_id = ?
            AND cv.business_id = ?
            ORDER BY cv.id DESC";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("ii", $customerId, $businessId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $vehicles[] = $row;
        }
        $stmt->close();
    }
}

/* -------------------------------------------------------
   SERVICE HISTORY
------------------------------------------------------- */
$serviceHistory = [];

if ($hasServiceJobCards) {
    $sql = "SELECT 
                sjc.id,
                sjc.jobcard_no,
                sjc.service_date,
                sjc.job_status,
                sjc.final_amount,
                sjc.opening_km,
                " . ($hasCustomerVehicles ? "cv.registration_no, cv.id AS vehicle_id" : "NULL AS registration_no, NULL AS vehicle_id") . "
            FROM service_job_cards sjc
            " . ($hasCustomerVehicles ? "LEFT JOIN customer_vehicles cv ON cv.id = sjc.customer_vehicle_id" : "") . "
            WHERE sjc.customer_id = ?
            AND sjc.business_id = ?
            ORDER BY sjc.id DESC
            LIMIT 50";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("ii", $customerId, $businessId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $serviceHistory[] = $row;
        }
        $stmt->close();
    }
}

/* -------------------------------------------------------
   SALES INVOICE HISTORY
------------------------------------------------------- */
$invoiceHistory = [];

if ($hasSalesInvoices) {
    $sql = "SELECT 
                si.id,
                si.invoice_no,
                si.invoice_date,
                COALESCE(si.grand_total,0) AS grand_total,
                {$salesPaidSelect},
                {$salesBalanceSelect},
                {$salesStatusSelect},
                COALESCE(si.invoice_type,'sales') AS invoice_type
            FROM sales_invoices si
            WHERE si.customer_id = ?
            AND si.business_id = ?
            ORDER BY si.id DESC
            LIMIT 50";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("ii", $customerId, $businessId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $calc = getPaymentStatus(
                (float)$row['grand_total'],
                (float)$row['paid_amount'],
                (float)$row['balance_amount'],
                (string)$row['payment_status']
            );
            $row['payment_status'] = $calc['status'];
            $row['payment_badge'] = $calc['badge'];
            $row['balance_amount'] = $calc['balance'];
            $invoiceHistory[] = $row;
        }
        $stmt->close();
    }
}

/* -------------------------------------------------------
   SERVICE INVOICE HISTORY
------------------------------------------------------- */
$serviceInvoiceHistory = [];

if ($hasServiceInvoices) {
    $sql = "SELECT 
                si.id,
                si.invoice_no,
                si.invoice_date,
                COALESCE(si.grand_total,0) AS grand_total,
                {$servicePaidSelect},
                {$serviceBalanceSelect},
                {$serviceStatusSelect},
                " . ($hasServiceJobCards ? "sjc.jobcard_no" : "NULL AS jobcard_no") . "
            FROM service_invoices si
            " . ($hasServiceJobCards ? "LEFT JOIN service_job_cards sjc ON sjc.id = si.jobcard_id" : "") . "
            WHERE si.customer_id = ?
            AND si.business_id = ?
            ORDER BY si.id DESC
            LIMIT 50";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("ii", $customerId, $businessId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $calc = getPaymentStatus(
                (float)$row['grand_total'],
                (float)$row['paid_amount'],
                (float)$row['balance_amount'],
                (string)$row['payment_status']
            );
            $row['payment_status'] = $calc['status'];
            $row['payment_badge'] = $calc['badge'];
            $row['balance_amount'] = $calc['balance'];
            $serviceInvoiceHistory[] = $row;
        }
        $stmt->close();
    }
}

/* -------------------------------------------------------
   PRE-BOOKINGS
------------------------------------------------------- */
$preBookings = [];

if ($hasPreBookings) {
    $sql = "SELECT 
                pb.id,
                pb.booking_no,
                pb.booking_date,
                pb.booking_amount,
                pb.expected_delivery_date,
                pb.status,
                pb.booking_type,
                pb.model_name,
                pb.brand_name
            FROM pre_bookings pb
            WHERE pb.customer_id = ?
            AND pb.business_id = ?
            ORDER BY pb.id DESC
            LIMIT 50";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("ii", $customerId, $businessId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $preBookings[] = $row;
        }
        $stmt->close();
    }
}

/* -------------------------------------------------------
   TOTAL STATS - FROM DB, NOT ONLY LIMIT ROWS
------------------------------------------------------- */
$totalVehicles = $hasCustomerVehicles
    ? fetchSingleInt($conn, "SELECT COUNT(*) AS total FROM customer_vehicles WHERE customer_id = {$customerId} AND business_id = {$businessId}")
    : 0;

$totalServiceJobs = $hasServiceJobCards
    ? fetchSingleInt($conn, "SELECT COUNT(*) AS total FROM service_job_cards WHERE customer_id = {$customerId} AND business_id = {$businessId}")
    : 0;

$totalSalesInvoices = $hasSalesInvoices
    ? fetchSingleInt($conn, "SELECT COUNT(*) AS total FROM sales_invoices WHERE customer_id = {$customerId} AND business_id = {$businessId}")
    : 0;

$totalServiceInvoices = $hasServiceInvoices
    ? fetchSingleInt($conn, "SELECT COUNT(*) AS total FROM service_invoices WHERE customer_id = {$customerId} AND business_id = {$businessId}")
    : 0;

$totalPreBookings = $hasPreBookings
    ? fetchSingleInt($conn, "SELECT COUNT(*) AS total FROM pre_bookings WHERE customer_id = {$customerId} AND business_id = {$businessId}")
    : 0;

$totalInvoiceAmount = 0.00;
$totalPaidAmount = 0.00;
$totalBalanceAmount = 0.00;

if ($hasSalesInvoices) {
    $totalInvoiceAmount += fetchSingleFloat($conn, "SELECT COALESCE(SUM(grand_total),0) AS total FROM sales_invoices WHERE customer_id = {$customerId} AND business_id = {$businessId}");
    $totalPaidAmount += $salesHasPaid ? fetchSingleFloat($conn, "SELECT COALESCE(SUM(paid_amount),0) AS total FROM sales_invoices WHERE customer_id = {$customerId} AND business_id = {$businessId}") : 0.00;
    $totalBalanceAmount += $salesHasBalance
        ? fetchSingleFloat($conn, "SELECT COALESCE(SUM(GREATEST(balance_amount,0)),0) AS total FROM sales_invoices WHERE customer_id = {$customerId} AND business_id = {$businessId}")
        : fetchSingleFloat($conn, "SELECT COALESCE(SUM(GREATEST(grand_total - " . ($salesHasPaid ? "paid_amount" : "0") . ",0)),0) AS total FROM sales_invoices WHERE customer_id = {$customerId} AND business_id = {$businessId}");
}

if ($hasServiceInvoices) {
    $totalInvoiceAmount += fetchSingleFloat($conn, "SELECT COALESCE(SUM(grand_total),0) AS total FROM service_invoices WHERE customer_id = {$customerId} AND business_id = {$businessId}");
    $totalPaidAmount += $serviceHasPaid ? fetchSingleFloat($conn, "SELECT COALESCE(SUM(paid_amount),0) AS total FROM service_invoices WHERE customer_id = {$customerId} AND business_id = {$businessId}") : 0.00;
    $totalBalanceAmount += $serviceHasBalance
        ? fetchSingleFloat($conn, "SELECT COALESCE(SUM(GREATEST(balance_amount,0)),0) AS total FROM service_invoices WHERE customer_id = {$customerId} AND business_id = {$businessId}")
        : fetchSingleFloat($conn, "SELECT COALESCE(SUM(GREATEST(grand_total - " . ($serviceHasPaid ? "paid_amount" : "0") . ",0)),0) AS total FROM service_invoices WHERE customer_id = {$customerId} AND business_id = {$businessId}");
}

/* -------------------------------------------------------
   PAGE VARIABLES
------------------------------------------------------- */
$pageTitle = 'Customer Details - ' . h($customer['full_name']);
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

                <!-- Page Title -->
                <div class="row">
                    <div class="col-12">
                        <div class="page-title-box d-flex align-items-center justify-content-between">
                            <h4 class="mb-0">Customer Details</h4>
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
                                    <li class="breadcrumb-item"><a href="customers.php">Customers</a></li>
                                    <li class="breadcrumb-item active"><?php echo h($customer['full_name']); ?></li>
                                </ol>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Customer Profile Header -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex flex-wrap align-items-center justify-content-between">
                                    <div class="d-flex align-items-center mb-3 mb-sm-0">
                                        <div class="flex-shrink-0">
                                            <div class="avatar-lg rounded-circle bg-soft-primary text-primary d-flex align-items-center justify-content-center">
                                                <i class="ri-user-3-line" style="font-size: 3rem;"></i>
                                            </div>
                                        </div>
                                        <div class="flex-grow-1 ms-3">
                                            <h4 class="mb-1"><?php echo h($customer['full_name']); ?></h4>
                                            <p class="text-muted mb-2">
                                                <span class="badge bg-soft-info text-info me-2">
                                                    <?php echo h($customer['customer_code'] ?? 'No Code'); ?>
                                                </span>
                                                <span class="me-3">
                                                    <i class="ri-phone-line me-1"></i><?php echo h($customer['mobile']); ?>
                                                </span>
                                                <?php if (!empty($customer['email'])): ?>
                                                    <span><i class="ri-mail-line me-1"></i><?php echo h($customer['email']); ?></span>
                                                <?php endif; ?>
                                            </p>
                                            <p class="text-muted mb-0">
                                                <i class="ri-map-pin-line me-1"></i>
                                                <?php
                                                $location = [];
                                                if (!empty($customer['city'])) $location[] = $customer['city'];
                                                if (!empty($customer['district'])) $location[] = $customer['district'];
                                                if (!empty($customer['state'])) $location[] = $customer['state'];
                                                echo !empty($location) ? h(implode(', ', $location)) : 'Address not available';
                                                ?>
                                            </p>
                                        </div>
                                    </div>
                                    <div>
                                        <a href="customer-edit.php?id=<?php echo (int)$customer['id']; ?>" class="btn btn-warning">
                                            <i class="ri-pencil-line me-1"></i> Edit
                                        </a>
                                        <a href="customers.php" class="btn btn-secondary ms-2">
                                            <i class="ri-arrow-go-back-line me-1"></i> Back
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Financial Summary -->
                <div class="row">
                    <div class="col-xl-3 col-md-6">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Total Invoice Amount</p>
                                <h4 class="mb-0 text-primary"><?php echo money($totalInvoiceAmount); ?></h4>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Total Paid</p>
                                <h4 class="mb-0 text-success"><?php echo money($totalPaidAmount); ?></h4>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Total Balance</p>
                                <h4 class="mb-0 text-danger"><?php echo money($totalBalanceAmount); ?></h4>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">All Invoices</p>
                                <h4 class="mb-0"><?php echo number_format($totalSalesInvoices + $totalServiceInvoices); ?></h4>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Customer Details Tabs -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <ul class="nav nav-tabs nav-tabs-custom mb-4" role="tablist">
                                    <li class="nav-item">
                                        <a class="nav-link active" data-bs-toggle="tab" href="#personal-info" role="tab">
                                            <i class="ri-user-info-line me-1"></i> Personal Information
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link" data-bs-toggle="tab" href="#vehicles" role="tab">
                                            <i class="ri-motorbike-line me-1"></i> Vehicles 
                                            <span class="badge bg-secondary ms-1"><?php echo number_format($totalVehicles); ?></span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link" data-bs-toggle="tab" href="#service-history" role="tab">
                                            <i class="ri-tools-line me-1"></i> Service History
                                            <span class="badge bg-secondary ms-1"><?php echo number_format($totalServiceJobs); ?></span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link" data-bs-toggle="tab" href="#invoices" role="tab">
                                            <i class="ri-file-list-3-line me-1"></i> Invoices
                                            <span class="badge bg-secondary ms-1"><?php echo number_format($totalSalesInvoices + $totalServiceInvoices); ?></span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link" data-bs-toggle="tab" href="#bookings" role="tab">
                                            <i class="ri-calendar-check-line me-1"></i> Pre-Bookings
                                            <span class="badge bg-secondary ms-1"><?php echo number_format($totalPreBookings); ?></span>
                                        </a>
                                    </li>
                                </ul>

                                <div class="tab-content">

                                    <!-- Personal Information -->
                                    <div class="tab-pane active" id="personal-info" role="tabpanel">
                                        <div class="row">
                                            <div class="col-md-6">
                                                <h5 class="mb-3">Basic Information</h5>
                                                <table class="table table-bordered">
                                                    <tr><th style="width:40%;">Customer Code</th><td><?php echo h($customer['customer_code'] ?? '-'); ?></td></tr>
                                                    <tr><th>Full Name</th><td><?php echo h($customer['full_name']); ?></td></tr>
                                                    <tr><th>Mobile Number</th><td><?php echo h($customer['mobile']); ?></td></tr>
                                                    <tr><th>Alternate Mobile</th><td><?php echo h($customer['alternate_mobile'] ?? '-'); ?></td></tr>
                                                    <tr><th>Email Address</th><td><?php echo h($customer['email'] ?? '-'); ?></td></tr>
                                                    <tr>
                                                        <th>Date of Birth</th>
                                                        <td>
                                                            <?php
                                                            if (!empty($customer['dob']) && $customer['dob'] !== '0000-00-00') {
                                                                echo safeDate($customer['dob']);
                                                                $dobTs = strtotime($customer['dob']);
                                                                if ($dobTs) {
                                                                    $age = date('Y') - date('Y', $dobTs);
                                                                    echo ' <span class="text-muted">(' . (int)$age . ' years)</span>';
                                                                }
                                                            } else {
                                                                echo '-';
                                                            }
                                                            ?>
                                                        </td>
                                                    </tr>
                                                    <tr><th>Gender</th><td><?php echo h(!empty($customer['gender']) ? ucfirst($customer['gender']) : '-'); ?></td></tr>
                                                    <tr><th>Registered On</th><td><?php echo safeDate($customer['created_at'] ?? '', 'd M Y h:i A'); ?></td></tr>
                                                    <tr><th>Last Updated</th><td><?php echo safeDate($customer['updated_at'] ?? '', 'd M Y h:i A'); ?></td></tr>
                                                </table>
                                            </div>

                                            <div class="col-md-6">
                                                <h5 class="mb-3">Identity & Address Details</h5>
                                                <table class="table table-bordered">
                                                    <tr>
                                                        <th style="width:40%;">Aadhar Number</th>
                                                        <td>
                                                            <?php
                                                            if (!empty($customer['aadhar_no'])) {
                                                                $aadhar = preg_replace('/\D/', '', (string)$customer['aadhar_no']);
                                                                echo h(trim(chunk_split($aadhar, 4, ' ')));
                                                            } else {
                                                                echo '-';
                                                            }
                                                            ?>
                                                        </td>
                                                    </tr>
                                                    <tr><th>PAN Number</th><td><?php echo h($customer['pan_no'] ?? '-'); ?></td></tr>
                                                    <tr><th>Driving License</th><td><?php echo h($customer['driving_license_no'] ?? '-'); ?></td></tr>
                                                    <tr><th>GSTIN</th><td><?php echo h($customer['gstin'] ?? '-'); ?></td></tr>
                                                    <tr><th>Address Line 1</th><td><?php echo h($customer['address_line1'] ?? '-'); ?></td></tr>
                                                    <tr><th>Address Line 2</th><td><?php echo h($customer['address_line2'] ?? '-'); ?></td></tr>
                                                    <tr><th>City</th><td><?php echo h($customer['city'] ?? '-'); ?></td></tr>
                                                    <tr><th>District</th><td><?php echo h($customer['district'] ?? '-'); ?></td></tr>
                                                    <tr><th>State</th><td><?php echo h($customer['state'] ?? '-'); ?></td></tr>
                                                    <tr><th>Pincode</th><td><?php echo h($customer['pincode'] ?? '-'); ?></td></tr>
                                                </table>
                                            </div>
                                        </div>

                                        <?php if (!empty($customer['notes'])): ?>
                                            <div class="row mt-3">
                                                <div class="col-12">
                                                    <h5 class="mb-3">Notes</h5>
                                                    <div class="p-3 bg-light rounded">
                                                        <?php echo nl2br(h($customer['notes'])); ?>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Vehicles -->
                                    <div class="tab-pane" id="vehicles" role="tabpanel">
                                        <div class="d-flex justify-content-between align-items-center mb-3">
                                            <h5 class="mb-0">Registered Vehicles</h5>
                                            <a href="customer-vehicle-add.php?customer_id=<?php echo (int)$customer['id']; ?>" class="btn btn-primary btn-sm">
                                                <i class="ri-add-line me-1"></i> Add Vehicle
                                            </a>
                                        </div>

                                        <?php if (!empty($vehicles)): ?>
                                            <div class="table-responsive">
                                                <table class="table table-centered table-nowrap mb-0">
                                                    <thead>
                                                        <tr>
                                                            <th>#</th>
                                                            <th>Registration No</th>
                                                            <th>Brand/Model</th>
                                                            <th>Chassis No</th>
                                                            <th>Engine/Motor No</th>
                                                            <th>Purchase Date</th>
                                                            <th>Warranty Till</th>
                                                            <th>Status</th>
                                                            <th>Actions</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php $sno = 1; foreach ($vehicles as $vehicle): ?>
                                                            <tr>
                                                                <td><?php echo $sno++; ?></td>
                                                                <td><strong><?php echo h($vehicle['registration_no'] ?? 'Not Registered'); ?></strong></td>
                                                                <td>
                                                                    <?php
                                                                    echo h(trim(($vehicle['brand_name'] ?? '') . ' ' . ($vehicle['model_name'] ?? '')));
                                                                    if (!empty($vehicle['variant_name'])) {
                                                                        echo ' (' . h($vehicle['variant_name']) . ')';
                                                                    }
                                                                    ?>
                                                                    <br>
                                                                    <small class="text-muted"><?php echo h(ucfirst($vehicle['vehicle_type'] ?? '')); ?></small>
                                                                </td>
                                                                <td><?php echo h($vehicle['chassis_no'] ?? '-'); ?></td>
                                                                <td>
                                                                    <?php
                                                                    if (($vehicle['vehicle_type'] ?? '') === 'electric') {
                                                                        echo h($vehicle['motor_no'] ?? '-');
                                                                    } else {
                                                                        echo h($vehicle['engine_no'] ?? '-');
                                                                    }
                                                                    ?>
                                                                </td>
                                                                <td><?php echo safeDate($vehicle['purchase_date'] ?? ''); ?></td>
                                                                <td>
                                                                    <?php
                                                                    if (!empty($vehicle['warranty_end_date']) && $vehicle['warranty_end_date'] !== '0000-00-00') {
                                                                        $warranty = strtotime($vehicle['warranty_end_date']);
                                                                        if ($warranty && $warranty < time()) {
                                                                            echo '<span class="text-danger">Expired on ' . date('d M Y', $warranty) . '</span>';
                                                                        } else {
                                                                            echo date('d M Y', $warranty);
                                                                        }
                                                                    } else {
                                                                        echo '-';
                                                                    }
                                                                    ?>
                                                                </td>
                                                                <td>
                                                                    <?php if ((int)($vehicle['active_status'] ?? 1) === 1): ?>
                                                                        <span class="badge bg-success">Active</span>
                                                                    <?php else: ?>
                                                                        <span class="badge bg-danger">Inactive</span>
                                                                    <?php endif; ?>
                                                                </td>
                                                                <td>
                                                                    <div class="btn-group" role="group">
                                                                        <a href="customer-vehicle-view.php?id=<?php echo (int)$vehicle['id']; ?>" class="btn btn-sm btn-outline-primary">
                                                                            <i class="ri-eye-line"></i>
                                                                        </a>
                                                                        <a href="customer-vehicle-edit.php?id=<?php echo (int)$vehicle['id']; ?>" class="btn btn-sm btn-outline-warning">
                                                                            <i class="ri-pencil-line"></i>
                                                                        </a>
                                                                    </div>
                                                                </td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        <?php else: ?>
                                            <div class="text-center py-5">
                                                <i class="ri-motorbike-line" style="font-size:3rem;color:#ccc;"></i>
                                                <h5 class="mt-3">No Vehicles Registered</h5>
                                                <p class="text-muted">This customer has not registered any vehicles yet.</p>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Service History -->
                                    <div class="tab-pane" id="service-history" role="tabpanel">
                                        <h5 class="mb-3">Service History</h5>

                                        <?php if (!empty($serviceHistory)): ?>
                                            <div class="table-responsive">
                                                <table class="table table-centered table-nowrap mb-0">
                                                    <thead>
                                                        <tr>
                                                            <th>#</th>
                                                            <th>Job Card No</th>
                                                            <th>Vehicle</th>
                                                            <th>Service Date</th>
                                                            <th>Status</th>
                                                            <th>Amount</th>
                                                            <th>KM Reading</th>
                                                            <th>Actions</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php $sno = 1; foreach ($serviceHistory as $service): ?>
                                                            <?php
                                                            $status = strtolower((string)($service['job_status'] ?? ''));
                                                            $badge = 'secondary';
                                                            if ($status === 'delivered') $badge = 'success';
                                                            elseif ($status === 'in_progress') $badge = 'info';
                                                            elseif ($status === 'open') $badge = 'warning';
                                                            elseif ($status === 'cancelled') $badge = 'danger';
                                                            ?>
                                                            <tr>
                                                                <td><?php echo $sno++; ?></td>
                                                                <td><strong><?php echo h($service['jobcard_no']); ?></strong></td>
                                                                <td><?php echo h($service['registration_no'] ?? '-'); ?></td>
                                                                <td><?php echo safeDate($service['service_date']); ?></td>
                                                                <td>
                                                                    <span class="badge bg-<?php echo $badge; ?>">
                                                                        <?php echo h(ucwords(str_replace('_', ' ', $status ?: '-'))); ?>
                                                                    </span>
                                                                </td>
                                                                <td><?php echo money($service['final_amount'] ?? 0); ?></td>
                                                                <td><?php echo number_format((float)($service['opening_km'] ?? 0)); ?> km</td>
                                                                <td>
                                                                    <a href="service-jobcard-view.php?id=<?php echo (int)$service['id']; ?>" class="btn btn-sm btn-outline-primary">
                                                                        <i class="ri-eye-line"></i>
                                                                    </a>
                                                                </td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        <?php else: ?>
                                            <div class="text-center py-5">
                                                <i class="ri-tools-line" style="font-size:3rem;color:#ccc;"></i>
                                                <h5 class="mt-3">No Service History</h5>
                                                <p class="text-muted">This customer has not had any service jobs yet.</p>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Invoices -->
                                    <div class="tab-pane" id="invoices" role="tabpanel">
                                        <ul class="nav nav-pills nav-justified mb-3" role="tablist">
                                            <li class="nav-item" role="presentation">
                                                <a class="nav-link active" data-bs-toggle="pill" href="#sales-invoices" role="tab">
                                                    Sales Invoices (<?php echo number_format($totalSalesInvoices); ?>)
                                                </a>
                                            </li>
                                            <li class="nav-item" role="presentation">
                                                <a class="nav-link" data-bs-toggle="pill" href="#service-invoices" role="tab">
                                                    Service Invoices (<?php echo number_format($totalServiceInvoices); ?>)
                                                </a>
                                            </li>
                                        </ul>

                                        <div class="tab-content">

                                            <!-- Sales Invoices -->
                                            <div class="tab-pane active" id="sales-invoices" role="tabpanel">
                                                <?php if (!empty($invoiceHistory)): ?>
                                                    <div class="table-responsive">
                                                        <table class="table table-centered table-nowrap mb-0">
                                                            <thead>
                                                                <tr>
                                                                    <th>#</th>
                                                                    <th>Invoice No</th>
                                                                    <th>Date</th>
                                                                    <th>Type</th>
                                                                    <th>Total</th>
                                                                    <th>Paid</th>
                                                                    <th>Balance</th>
                                                                    <th>Status</th>
                                                                    <th>Actions</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody>
                                                                <?php $sno = 1; foreach ($invoiceHistory as $invoice): ?>
                                                                    <tr>
                                                                        <td><?php echo $sno++; ?></td>
                                                                        <td><strong><?php echo h($invoice['invoice_no']); ?></strong></td>
                                                                        <td><?php echo safeDate($invoice['invoice_date']); ?></td>
                                                                        <td><?php echo h(ucwords(str_replace('_', ' ', $invoice['invoice_type']))); ?></td>
                                                                        <td><?php echo money($invoice['grand_total']); ?></td>
                                                                        <td><?php echo money($invoice['paid_amount']); ?></td>
                                                                        <td><?php echo money($invoice['balance_amount']); ?></td>
                                                                        <td>
                                                                            <span class="badge bg-<?php echo h($invoice['payment_badge']); ?>">
                                                                                <?php echo h(ucfirst($invoice['payment_status'])); ?>
                                                                            </span>
                                                                        </td>
                                                                        <td>
                                                                            <a href="sales-invoice-view.php?id=<?php echo (int)$invoice['id']; ?>" class="btn btn-sm btn-outline-primary">
                                                                                <i class="ri-eye-line"></i>
                                                                            </a>
                                                                        </td>
                                                                    </tr>
                                                                <?php endforeach; ?>
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                <?php else: ?>
                                                    <p class="text-muted text-center py-4">No sales invoices found.</p>
                                                <?php endif; ?>
                                            </div>

                                            <!-- Service Invoices -->
                                            <div class="tab-pane" id="service-invoices" role="tabpanel">
                                                <?php if (!empty($serviceInvoiceHistory)): ?>
                                                    <div class="table-responsive">
                                                        <table class="table table-centered table-nowrap mb-0">
                                                            <thead>
                                                                <tr>
                                                                    <th>#</th>
                                                                    <th>Invoice No</th>
                                                                    <th>Job Card</th>
                                                                    <th>Date</th>
                                                                    <th>Total</th>
                                                                    <th>Paid</th>
                                                                    <th>Balance</th>
                                                                    <th>Status</th>
                                                                    <th>Actions</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody>
                                                                <?php $sno = 1; foreach ($serviceInvoiceHistory as $invoice): ?>
                                                                    <tr>
                                                                        <td><?php echo $sno++; ?></td>
                                                                        <td><strong><?php echo h($invoice['invoice_no']); ?></strong></td>
                                                                        <td><?php echo h($invoice['jobcard_no'] ?? '-'); ?></td>
                                                                        <td><?php echo safeDate($invoice['invoice_date']); ?></td>
                                                                        <td><?php echo money($invoice['grand_total']); ?></td>
                                                                        <td><?php echo money($invoice['paid_amount']); ?></td>
                                                                        <td><?php echo money($invoice['balance_amount']); ?></td>
                                                                        <td>
                                                                            <span class="badge bg-<?php echo h($invoice['payment_badge']); ?>">
                                                                                <?php echo h(ucfirst($invoice['payment_status'])); ?>
                                                                            </span>
                                                                        </td>
                                                                        <td>
                                                                            <a href="service-invoice-view.php?id=<?php echo (int)$invoice['id']; ?>" class="btn btn-sm btn-outline-primary">
                                                                                <i class="ri-eye-line"></i>
                                                                            </a>
                                                                        </td>
                                                                    </tr>
                                                                <?php endforeach; ?>
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                <?php else: ?>
                                                    <p class="text-muted text-center py-4">No service invoices found.</p>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Pre-Bookings -->
                                    <div class="tab-pane" id="bookings" role="tabpanel">
                                        <div class="d-flex justify-content-between align-items-center mb-3">
                                            <h5 class="mb-0">Pre-Bookings</h5>
                                            <a href="pre-booking-add.php?customer_id=<?php echo (int)$customer['id']; ?>" class="btn btn-primary btn-sm">
                                                <i class="ri-add-line me-1"></i> New Booking
                                            </a>
                                        </div>

                                        <?php if (!empty($preBookings)): ?>
                                            <div class="table-responsive">
                                                <table class="table table-centered table-nowrap mb-0">
                                                    <thead>
                                                        <tr>
                                                            <th>#</th>
                                                            <th>Booking No</th>
                                                            <th>Date</th>
                                                            <th>Type</th>
                                                            <th>Vehicle/Product</th>
                                                            <th>Booking Amount</th>
                                                            <th>Expected Delivery</th>
                                                            <th>Status</th>
                                                            <th>Actions</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php $sno = 1; foreach ($preBookings as $booking): ?>
                                                            <?php
                                                            $bookingStatus = strtolower((string)($booking['status'] ?? ''));
                                                            $badge = 'secondary';
                                                            if ($bookingStatus === 'confirmed') $badge = 'info';
                                                            elseif ($bookingStatus === 'delivered') $badge = 'success';
                                                            elseif ($bookingStatus === 'cancelled') $badge = 'danger';
                                                            elseif ($bookingStatus === 'converted') $badge = 'primary';
                                                            ?>
                                                            <tr>
                                                                <td><?php echo $sno++; ?></td>
                                                                <td><strong><?php echo h($booking['booking_no']); ?></strong></td>
                                                                <td><?php echo safeDate($booking['booking_date']); ?></td>
                                                                <td><?php echo h(ucfirst($booking['booking_type'] ?? '-')); ?></td>
                                                                <td><?php echo h(trim(($booking['brand_name'] ?? '') . ' ' . ($booking['model_name'] ?? '')) ?: '-'); ?></td>
                                                                <td><?php echo money($booking['booking_amount']); ?></td>
                                                                <td><?php echo safeDate($booking['expected_delivery_date']); ?></td>
                                                                <td>
                                                                    <span class="badge bg-<?php echo $badge; ?>">
                                                                        <?php echo h(ucfirst($bookingStatus ?: '-')); ?>
                                                                    </span>
                                                                </td>
                                                                <td>
                                                                    <a href="pre-booking-view.php?id=<?php echo (int)$booking['id']; ?>" class="btn btn-sm btn-outline-primary">
                                                                        <i class="ri-eye-line"></i>
                                                                    </a>
                                                                </td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        <?php else: ?>
                                            <div class="text-center py-5">
                                                <i class="ri-calendar-check-line" style="font-size:3rem;color:#ccc;"></i>
                                                <h5 class="mt-3">No Pre-Bookings</h5>
                                                <p class="text-muted">This customer has not made any pre-bookings yet.</p>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Stats Row -->
                <div class="row">
                    <div class="col-xl-3 col-md-6">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex">
                                    <div class="flex-shrink-0 me-3">
                                        <div class="avatar-sm">
                                            <span class="avatar-title bg-soft-primary text-primary rounded-circle">
                                                <i class="ri-motorbike-line font-size-20"></i>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-1">Total Vehicles</p>
                                        <h4><?php echo number_format($totalVehicles); ?></h4>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex">
                                    <div class="flex-shrink-0 me-3">
                                        <div class="avatar-sm">
                                            <span class="avatar-title bg-soft-success text-success rounded-circle">
                                                <i class="ri-tools-line font-size-20"></i>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-1">Service Jobs</p>
                                        <h4><?php echo number_format($totalServiceJobs); ?></h4>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex">
                                    <div class="flex-shrink-0 me-3">
                                        <div class="avatar-sm">
                                            <span class="avatar-title bg-soft-warning text-warning rounded-circle">
                                                <i class="ri-file-list-3-line font-size-20"></i>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-1">Total Invoices</p>
                                        <h4><?php echo number_format($totalSalesInvoices + $totalServiceInvoices); ?></h4>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex">
                                    <div class="flex-shrink-0 me-3">
                                        <div class="avatar-sm">
                                            <span class="avatar-title bg-soft-info text-info rounded-circle">
                                                <i class="ri-calendar-check-line font-size-20"></i>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-1">Pre-Bookings</p>
                                        <h4><?php echo number_format($totalPreBookings); ?></h4>
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
setTimeout(function() {
    var alerts = document.querySelectorAll('.alert');
    alerts.forEach(function(alert) {
        if (typeof bootstrap !== 'undefined') {
            var bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        }
    });
}, 5000);
</script>

</body>
</html>