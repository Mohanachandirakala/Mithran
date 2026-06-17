<?php
session_start();
date_default_timezone_set('Asia/Kolkata');

require_once 'includes/config.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not available.");
}
$conn->set_charset("utf8mb4");

$businessUserId = isset($_SESSION['business_user_id']) ? (int)$_SESSION['business_user_id'] : 0;
if ($businessUserId <= 0 && isset($_SESSION['user_id'])) {
    $businessUserId = (int)$_SESSION['user_id'];
}

$businessId = isset($_SESSION['business_id']) ? (int)$_SESSION['business_id'] : 0;
$sessionBranchId = isset($_SESSION['branch_id']) ? (int)$_SESSION['branch_id'] : 0;

if ($businessUserId <= 0 || $businessId <= 0) {
    header("Location: login.php");
    exit;
}

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

function tableExists(mysqli $conn, string $table): bool
{
    $sql = "SELECT 1 FROM information_schema.TABLES 
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return false;
    $stmt->bind_param("s", $table);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $exists;
}

function getCount(mysqli $conn, string $table, string $where = '1=1'): int
{
    $sql = "SELECT COUNT(*) AS total FROM {$table} WHERE {$where}";
    $res = $conn->query($sql);
    if (!$res) return 0;
    $row = $res->fetch_assoc();
    return (int)($row['total'] ?? 0);
}

function getSum(mysqli $conn, string $table, string $field, string $where = '1=1'): float
{
    $sql = "SELECT COALESCE(SUM({$field}),0) AS total FROM {$table} WHERE {$where}";
    $res = $conn->query($sql);
    if (!$res) return 0;
    $row = $res->fetch_assoc();
    return (float)($row['total'] ?? 0);
}

$selectedBranchId = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : $sessionBranchId;

if (isset($_GET['branch_id']) && (int)$_GET['branch_id'] !== $sessionBranchId) {
    $_SESSION['branch_id'] = $selectedBranchId;
}

$filterFromDate = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-01');
$filterToDate = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d');
$filterInvoiceNo = isset($_GET['invoice_no']) ? trim($_GET['invoice_no']) : '';
$filterStatus = isset($_GET['sale_status']) ? trim($_GET['sale_status']) : '';
$filterCustomer = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;
$filterPaymentStatus = isset($_GET['payment_status']) ? trim($_GET['payment_status']) : '';

$branches = [];
if (tableExists($conn, 'branches')) {
    $result = $conn->query("SELECT id, branch_name, branch_code FROM branches WHERE business_id = {$businessId} AND status = 'active' ORDER BY branch_name ASC");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $branches[] = $row;
        }
    }
}

$selectedBranchName = '';
$selectedBranchCode = '';
if ($selectedBranchId > 0) {
    foreach ($branches as $branch) {
        if ((int)$branch['id'] === $selectedBranchId) {
            $selectedBranchName = $branch['branch_name'];
            $selectedBranchCode = $branch['branch_code'];
            break;
        }
    }
}

$customers = [];
if (tableExists($conn, 'customers')) {
    $result = $conn->query("SELECT id, full_name, mobile FROM customers WHERE business_id = {$businessId} ORDER BY full_name ASC");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $customers[] = $row;
        }
    }
}

$loggedUser = null;
if (tableExists($conn, 'business_users') && tableExists($conn, 'businesses')) {
    $stmt = $conn->prepare("SELECT 
                                bu.id, bu.full_name, bu.role, bu.status,
                                b.business_name, b.status AS business_status
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
    header("Location: login.php");
    exit;
}

$whereConditions = ["si.business_id = {$businessId}"];
$params = [];
$paramTypes = '';

if ($selectedBranchId > 0) {
    $whereConditions[] = "si.branch_id = ?";
    $params[] = $selectedBranchId;
    $paramTypes .= 'i';
}

if (!empty($filterFromDate)) {
    $whereConditions[] = "DATE(si.invoice_date) >= ?";
    $params[] = $filterFromDate;
    $paramTypes .= 's';
}

if (!empty($filterToDate)) {
    $whereConditions[] = "DATE(si.invoice_date) <= ?";
    $params[] = $filterToDate;
    $paramTypes .= 's';
}

if (!empty($filterInvoiceNo)) {
    $whereConditions[] = "si.invoice_no LIKE ?";
    $params[] = "%{$filterInvoiceNo}%";
    $paramTypes .= 's';
}

if (!empty($filterStatus)) {
    $whereConditions[] = "si.sale_status = ?";
    $params[] = $filterStatus;
    $paramTypes .= 's';
}

if (!empty($filterPaymentStatus)) {
    $whereConditions[] = "si.payment_status = ?";
    $params[] = $filterPaymentStatus;
    $paramTypes .= 's';
}

if ($filterCustomer > 0) {
    $whereConditions[] = "si.customer_id = ?";
    $params[] = $filterCustomer;
    $paramTypes .= 'i';
}

$whereClause = implode(' AND ', $whereConditions);

$hasVehicleStock = tableExists($conn, 'vehicle_stock');

$sql = "
    SELECT 
        si.id,
        si.invoice_no,
        si.invoice_date,
        si.subtotal,
        si.discount_amount,
        si.cgst_amount,
        si.sgst_amount,
        si.igst_amount,
        si.cess_amount,
        si.round_off,
        si.grand_total,
        si.paid_amount,
        si.balance_amount,
        si.payment_status,
        si.sale_status,
        si.customer_note,
        si.created_at,

        c.id AS customer_id,
        c.full_name AS customer_name,
        c.mobile AS customer_mobile,
        c.city AS customer_city,
        c.district AS customer_district,

        b.id AS branch_id,
        b.branch_name,
        b.branch_code,

        sii.id AS item_id,
        sii.item_type,
        sii.vehicle_stock_id,
        sii.description,
        sii.qty,
        sii.unit_price,
        sii.discount_amount AS item_discount,
        sii.taxable_value,
        sii.cgst_percent,
        sii.sgst_percent,
        sii.cgst_amount AS item_cgst,
        sii.sgst_amount AS item_sgst,
        sii.igst_amount AS item_igst,
        sii.cess_amount AS item_cess,
        sii.line_total
    FROM sales_invoices si
    INNER JOIN customers c ON c.id = si.customer_id
    INNER JOIN branches b ON b.id = si.branch_id
    INNER JOIN sales_invoice_items sii ON sii.invoice_id = si.id
    WHERE {$whereClause}
      AND si.invoice_type IN ('vehicle_sale', 'mixed_sale')
      AND sii.item_type = 'vehicle'
    ORDER BY si.invoice_date DESC, si.id DESC
";

$vehicleSales = [];
$stmt = $conn->prepare($sql);
if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($paramTypes, ...$params);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        if (!empty($row['vehicle_stock_id']) && $hasVehicleStock) {
            $vehicleSql = "
                SELECT 
                    vs.id,
                    vs.chassis_no,
                    vs.engine_no,
                    vs.motor_no,
                    vs.color,
                    vs.battery_brand,
                    vs.battery_no,
                    vs.battery_capacity,
                    vs.battery_warranty_upto,
                    vs.charger_brand,
                    vs.charger_no,
                    vs.charger_type,
                    vs.charger_warranty_upto,
                    vs.key_no,
                    vs.manufacture_year,
                    vs.purchase_cost,
                    vs.sale_price,
                    vm.model_name,
                    vm.variant_name,
                    vm.vehicle_type,
                    vm.ex_showroom_price,
                    vb.brand_name
                FROM vehicle_stock vs
                LEFT JOIN vehicle_models vm ON vm.id = vs.model_id
                LEFT JOIN vehicle_brands vb ON vb.id = vm.brand_id
                WHERE vs.id = ?
                LIMIT 1
            ";

            $vehicleStmt = $conn->prepare($vehicleSql);
            if ($vehicleStmt) {
                $vehicleStmt->bind_param('i', $row['vehicle_stock_id']);
                $vehicleStmt->execute();
                $vehicleResult = $vehicleStmt->get_result();

                if ($vehicleData = $vehicleResult->fetch_assoc()) {
                    $row = array_merge($row, $vehicleData);
                }

                $vehicleStmt->close();
            }
        }

        $vehicleSales[] = $row;
    }

    $stmt->close();
}

$stats = [
    'total_invoices' => 0,
    'total_vehicles' => 0,
    'total_amount' => 0,
    'total_paid' => 0,
    'total_balance' => 0,
    'total_discount' => 0,
    'total_tax' => 0,
    'total_cgst' => 0,
    'total_sgst' => 0,
    'total_igst' => 0,
    'total_cess' => 0,
    'avg_invoice_value' => 0,
    'avg_vehicle_price' => 0,
    'collection_rate' => 0
];

$paymentBreakdown = [
    'paid' => ['count' => 0, 'amount' => 0],
    'partial' => ['count' => 0, 'amount' => 0],
    'unpaid' => ['count' => 0, 'amount' => 0]
];

$statusBreakdown = [
    'confirmed' => ['count' => 0, 'amount' => 0],
    'draft' => ['count' => 0, 'amount' => 0],
    'cancelled' => ['count' => 0, 'amount' => 0],
    'delivered' => ['count' => 0, 'amount' => 0]
];

$brandStats = [];
$branchStats = [];
$monthlyStats = [];
$customerStats = [];
$processedInvoices = [];

foreach ($vehicleSales as $sale) {
    $invoiceId = (int)($sale['id'] ?? 0);
    $qty = (float)($sale['qty'] ?? 1);
    if ($qty <= 0) {
        $qty = 1;
    }

    $lineTotal = (float)($sale['line_total'] ?? 0);

    $stats['total_vehicles'] += $qty;
    $stats['total_amount'] += $lineTotal;
    $stats['total_tax'] += (float)($sale['item_cgst'] ?? 0) + (float)($sale['item_sgst'] ?? 0) + (float)($sale['item_igst'] ?? 0) + (float)($sale['item_cess'] ?? 0);

    if (!isset($processedInvoices[$invoiceId])) {
        $processedInvoices[$invoiceId] = true;

        $invoiceTotal = (float)($sale['grand_total'] ?? 0);
        $paidAmount = (float)($sale['paid_amount'] ?? 0);
        $balanceAmount = (float)($sale['balance_amount'] ?? 0);

        if ($balanceAmount <= 0 && $invoiceTotal > $paidAmount) {
            $balanceAmount = $invoiceTotal - $paidAmount;
        }

        $stats['total_invoices']++;
        $stats['total_paid'] += $paidAmount;
        $stats['total_balance'] += $balanceAmount;
        $stats['total_discount'] += (float)($sale['discount_amount'] ?? 0);
        $stats['total_cgst'] += (float)($sale['cgst_amount'] ?? 0);
        $stats['total_sgst'] += (float)($sale['sgst_amount'] ?? 0);
        $stats['total_igst'] += (float)($sale['igst_amount'] ?? 0);
        $stats['total_cess'] += (float)($sale['cess_amount'] ?? 0);

        $paymentStatus = strtolower(trim((string)($sale['payment_status'] ?? '')));
        if ($paymentStatus === '') {
            if ($paidAmount >= $invoiceTotal && $invoiceTotal > 0) {
                $paymentStatus = 'paid';
            } elseif ($paidAmount > 0) {
                $paymentStatus = 'partial';
            } else {
                $paymentStatus = 'unpaid';
            }
        }

        if (!isset($paymentBreakdown[$paymentStatus])) {
            $paymentStatus = 'unpaid';
        }

        $paymentBreakdown[$paymentStatus]['count']++;
        $paymentBreakdown[$paymentStatus]['amount'] += $invoiceTotal;

        $saleStatus = strtolower(trim((string)($sale['sale_status'] ?? 'confirmed')));
        if (!isset($statusBreakdown[$saleStatus])) {
            $statusBreakdown[$saleStatus] = ['count' => 0, 'amount' => 0];
        }

        $statusBreakdown[$saleStatus]['count']++;
        $statusBreakdown[$saleStatus]['amount'] += $invoiceTotal;

        $branchName = $sale['branch_name'] ?? 'Unknown';
        if (!isset($branchStats[$branchName])) {
            $branchStats[$branchName] = ['invoices' => 0, 'vehicles' => 0, 'amount' => 0];
        }
        $branchStats[$branchName]['invoices']++;
        $branchStats[$branchName]['amount'] += $invoiceTotal;

        $invoiceDate = $sale['invoice_date'] ?? $sale['created_at'] ?? date('Y-m-d');
        $month = date('Y-m', strtotime($invoiceDate));
        if (!isset($monthlyStats[$month])) {
            $monthlyStats[$month] = ['invoices' => 0, 'vehicles' => 0, 'amount' => 0];
        }
        $monthlyStats[$month]['invoices']++;
        $monthlyStats[$month]['amount'] += $invoiceTotal;
    }

    $brandName = trim((string)($sale['brand_name'] ?? ''));
    if ($brandName !== '') {
        if (!isset($brandStats[$brandName])) {
            $brandStats[$brandName] = ['count' => 0, 'amount' => 0];
        }
        $brandStats[$brandName]['count'] += $qty;
        $brandStats[$brandName]['amount'] += $lineTotal;
    }

    $customerName = $sale['customer_name'] ?? 'Unknown';
    if (!isset($customerStats[$customerName])) {
        $customerStats[$customerName] = [
            'customer_id' => $sale['customer_id'] ?? 0,
            'mobile' => $sale['customer_mobile'] ?? '',
            'vehicles' => 0,
            'amount' => 0
        ];
    }
    $customerStats[$customerName]['vehicles'] += $qty;
    $customerStats[$customerName]['amount'] += $lineTotal;

    $invoiceDate = $sale['invoice_date'] ?? $sale['created_at'] ?? date('Y-m-d');
    $month = date('Y-m', strtotime($invoiceDate));
    if (isset($monthlyStats[$month])) {
        $monthlyStats[$month]['vehicles'] += $qty;
    }

    $branchName = $sale['branch_name'] ?? 'Unknown';
    if (isset($branchStats[$branchName])) {
        $branchStats[$branchName]['vehicles'] += $qty;
    }
}

$stats['avg_invoice_value'] = $stats['total_invoices'] > 0 ? $stats['total_amount'] / $stats['total_invoices'] : 0;
$stats['avg_vehicle_price'] = $stats['total_vehicles'] > 0 ? $stats['total_amount'] / $stats['total_vehicles'] : 0;
$stats['collection_rate'] = $stats['total_amount'] > 0 ? ($stats['total_paid'] / $stats['total_amount']) * 100 : 0;

arsort($brandStats);
arsort($branchStats);
krsort($monthlyStats);
arsort($customerStats);

$today = date('Y-m-d');
$whereAdditional = "business_id = {$businessId} AND invoice_type IN ('vehicle_sale', 'mixed_sale')";
if ($selectedBranchId > 0) {
    $whereAdditional .= " AND branch_id = {$selectedBranchId}";
}

$todaySalesCount = getCount($conn, 'sales_invoices', $whereAdditional . " AND DATE(invoice_date) = '{$today}'");
$todaySalesAmount = getSum($conn, 'sales_invoices', 'grand_total', $whereAdditional . " AND DATE(invoice_date) = '{$today}'");

$currentMonthStart = date('Y-m-01');
$currentMonthEnd = date('Y-m-t');
$monthSalesCount = getCount($conn, 'sales_invoices', $whereAdditional . " AND DATE(invoice_date) BETWEEN '{$currentMonthStart}' AND '{$currentMonthEnd}'");
$monthSalesAmount = getSum($conn, 'sales_invoices', 'grand_total', $whereAdditional . " AND DATE(invoice_date) BETWEEN '{$currentMonthStart}' AND '{$currentMonthEnd}'");

$pageTitle = 'Vehicle Sales Report';
$currentPage = 'vehicle-sales';
?>
<!doctype html>
<html lang="en">

<?php include('includes/head.php'); ?>

<body data-sidebar="dark">

<style>
.page-content { padding-bottom: 100px !important; }
.main-content { min-height: calc(100vh - 70px); }
.card { margin-bottom: 24px; border: none; box-shadow: 0 0 35px 0 rgba(154, 161, 171, 0.15); }
.status-badge {
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 600;
}
.status-paid { background: #d4edda; color: #155724; }
.status-partial { background: #fff3cd; color: #856404; }
.status-unpaid { background: #f8d7da; color: #721c24; }
.status-confirmed { background: #d1ecf1; color: #0c5460; }
.status-draft { background: #e2e3e5; color: #383d41; }
.status-cancelled { background: #f8d7da; color: #721c24; }
.status-delivered { background: #d4edda; color: #155724; }
.vehicle-details { font-size: 13px; line-height: 1.5; }
.vehicle-details strong { font-weight: 600; }
.progress { height: 25px; margin-bottom: 10px; }
.progress-bar { line-height: 25px; padding: 0 10px; }
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
                    <div class="col-md-6">
                        <h4 class="mb-1">Vehicle Sales Report</h4>
                        <p class="text-muted mb-0">View and analyze all vehicle sale transactions</p>
                    </div>
                    <div class="col-md-6 text-md-end mt-3 mt-md-0">
                        <div class="btn-group">
                            <a href="sales-invoice-add.php?invoice_type=vehicle_sale" class="btn btn-primary">
                                <i class="mdi mdi-plus"></i> New Vehicle Sale
                            </a>
                            <a href="sales-invoices.php" class="btn btn-secondary">
                                <i class="mdi mdi-arrow-left"></i> All Invoices
                            </a>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-body">
                        <form method="get" class="row g-3 align-items-end">
                            <div class="col-md-2">
                                <label class="form-label fw-bold">Branch</label>
                                <select name="branch_id" class="form-select">
                                    <option value="0" <?php echo $selectedBranchId == 0 ? 'selected' : ''; ?>>All Branches</option>
                                    <?php foreach ($branches as $branch): ?>
                                        <option value="<?php echo (int)$branch['id']; ?>" <?php echo $selectedBranchId == (int)$branch['id'] ? 'selected' : ''; ?>>
                                            <?php echo h($branch['branch_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">Customer</label>
                                <select name="customer_id" class="form-select">
                                    <option value="0">All Customers</option>
                                    <?php foreach ($customers as $c): ?>
                                        <option value="<?php echo (int)$c['id']; ?>" <?php echo $filterCustomer == (int)$c['id'] ? 'selected' : ''; ?>>
                                            <?php echo h($c['full_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">From Date</label>
                                <input type="date" name="from_date" class="form-control" value="<?php echo h($filterFromDate); ?>">
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">To Date</label>
                                <input type="date" name="to_date" class="form-control" value="<?php echo h($filterToDate); ?>">
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">Invoice No</label>
                                <input type="text" name="invoice_no" class="form-control" value="<?php echo h($filterInvoiceNo); ?>">
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">Payment</label>
                                <select name="payment_status" class="form-select">
                                    <option value="">All</option>
                                    <option value="paid" <?php echo $filterPaymentStatus === 'paid' ? 'selected' : ''; ?>>Paid</option>
                                    <option value="partial" <?php echo $filterPaymentStatus === 'partial' ? 'selected' : ''; ?>>Partial</option>
                                    <option value="unpaid" <?php echo $filterPaymentStatus === 'unpaid' ? 'selected' : ''; ?>>Unpaid</option>
                                </select>
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">Sale Status</label>
                                <select name="sale_status" class="form-select">
                                    <option value="">All Status</option>
                                    <option value="confirmed" <?php echo $filterStatus === 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                                    <option value="draft" <?php echo $filterStatus === 'draft' ? 'selected' : ''; ?>>Draft</option>
                                    <option value="cancelled" <?php echo $filterStatus === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                    <option value="delivered" <?php echo $filterStatus === 'delivered' ? 'selected' : ''; ?>>Delivered</option>
                                </select>
                            </div>

                            <div class="col-md-12">
                                <button type="submit" class="btn btn-primary">Filter</button>
                                <a href="vehicle-sales.php" class="btn btn-secondary">Reset</a>
                            </div>
                        </form>
                    </div>
                </div>

                <?php if ($selectedBranchId > 0 && !empty($selectedBranchName)): ?>
                    <div class="alert alert-info">
                        Showing vehicle sales for branch: <strong><?php echo h($selectedBranchName); ?></strong> (<?php echo h($selectedBranchCode); ?>)
                    </div>
                <?php else: ?>
                    <div class="alert alert-secondary">
                        Showing vehicle sales for <strong>All Branches</strong>.
                    </div>
                <?php endif; ?>

                <?php if (!empty($vehicleSales)): ?>

                <div class="row">
                    <div class="col-xl-3 col-md-6">
                        <div class="card">
                            <div class="card-body">
                                <p class="text-muted mb-1">Total Vehicles Sold</p>
                                <h3 class="mb-0"><?php echo number_format($stats['total_vehicles']); ?></h3>
                                <small><?php echo number_format($stats['total_invoices']); ?> Invoices</small>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6">
                        <div class="card">
                            <div class="card-body">
                                <p class="text-muted mb-1">Total Sales Value</p>
                                <h3 class="mb-0"><?php echo money($stats['total_amount']); ?></h3>
                                <small>Avg: <?php echo money($stats['avg_vehicle_price']); ?> / vehicle</small>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6">
                        <div class="card">
                            <div class="card-body">
                                <p class="text-muted mb-1">Total Received</p>
                                <h3 class="mb-0 text-success"><?php echo money($stats['total_paid']); ?></h3>
                                <small>Collection: <?php echo number_format($stats['collection_rate'], 1); ?>%</small>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6">
                        <div class="card">
                            <div class="card-body">
                                <p class="text-muted mb-1">Pending Amount</p>
                                <h3 class="mb-0 text-danger"><?php echo money($stats['total_balance']); ?></h3>
                                <small><?php echo number_format($paymentBreakdown['unpaid']['count'] + $paymentBreakdown['partial']['count']); ?> pending invoices</small>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body">
                                <p class="text-muted mb-2">Today's Sales</p>
                                <h4 class="mb-0"><?php echo number_format($todaySalesCount); ?></h4>
                                <small>Invoices</small>
                                <div><strong><?php echo money($todaySalesAmount); ?></strong></div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body">
                                <p class="text-muted mb-2">This Month Sales</p>
                                <h4 class="mb-0"><?php echo number_format($monthSalesCount); ?></h4>
                                <small>Invoices</small>
                                <div><strong><?php echo money($monthSalesAmount); ?></strong></div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body">
                                <p class="text-muted mb-2">Total Discount</p>
                                <h4 class="mb-0"><?php echo money($stats['total_discount']); ?></h4>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body">
                                <p class="text-muted mb-2">Total Tax</p>
                                <h4 class="mb-0"><?php echo money($stats['total_tax']); ?></h4>
                                <small>
                                    CGST: <?php echo money($stats['total_cgst']); ?> |
                                    SGST: <?php echo money($stats['total_sgst']); ?>
                                </small>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">Payment Status Breakdown</h5>
                            </div>
                            <div class="card-body">
                                <table class="table table-sm mb-0">
                                    <thead>
                                        <tr>
                                            <th>Status</th>
                                            <th>Invoices</th>
                                            <th>Amount</th>
                                            <th>%</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($paymentBreakdown as $status => $data): ?>
                                            <tr>
                                                <td><span class="status-badge status-<?php echo h($status); ?>"><?php echo ucfirst($status); ?></span></td>
                                                <td><?php echo number_format($data['count']); ?></td>
                                                <td><?php echo money($data['amount']); ?></td>
                                                <td><?php echo $stats['total_amount'] > 0 ? number_format(($data['amount'] / $stats['total_amount']) * 100, 1) : 0; ?>%</td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>

                                <div class="progress mt-3" style="height:30px;">
                                    <?php
                                    $paidPercent = $stats['total_amount'] > 0 ? ($paymentBreakdown['paid']['amount'] / $stats['total_amount']) * 100 : 0;
                                    $partialPercent = $stats['total_amount'] > 0 ? ($paymentBreakdown['partial']['amount'] / $stats['total_amount']) * 100 : 0;
                                    $unpaidPercent = $stats['total_amount'] > 0 ? ($paymentBreakdown['unpaid']['amount'] / $stats['total_amount']) * 100 : 0;
                                    ?>
                                    <div class="progress-bar bg-success" style="width: <?php echo $paidPercent; ?>%">Paid</div>
                                    <div class="progress-bar bg-warning" style="width: <?php echo $partialPercent; ?>%">Partial</div>
                                    <div class="progress-bar bg-danger" style="width: <?php echo $unpaidPercent; ?>%">Unpaid</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">Sale Status Breakdown</h5>
                            </div>
                            <div class="card-body">
                                <table class="table table-sm mb-0">
                                    <thead>
                                        <tr>
                                            <th>Status</th>
                                            <th>Invoices</th>
                                            <th>Amount</th>
                                            <th>%</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($statusBreakdown as $status => $data): ?>
                                            <?php if ((int)$data['count'] > 0): ?>
                                                <tr>
                                                    <td><span class="status-badge status-<?php echo h($status); ?>"><?php echo ucfirst($status); ?></span></td>
                                                    <td><?php echo number_format($data['count']); ?></td>
                                                    <td><?php echo money($data['amount']); ?></td>
                                                    <td><?php echo $stats['total_amount'] > 0 ? number_format(($data['amount'] / $stats['total_amount']) * 100, 1) : 0; ?>%</td>
                                                </tr>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <?php else: ?>
                    <div class="card">
                        <div class="card-body text-center py-5">
                            <h5>No Vehicle Sales Found</h5>
                            <p class="text-muted">There are no vehicle sales records matching your criteria.</p>
                            <a href="sales-invoice-add.php?invoice_type=vehicle_sale" class="btn btn-primary">Create First Vehicle Sale</a>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($vehicleSales)): ?>
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">Vehicle Sales Transactions</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Invoice No</th>
                                            <th>Date</th>
                                            <th>Branch</th>
                                            <th>Customer</th>
                                            <th>Vehicle Details</th>
                                            <th>Amount</th>
                                            <th>Payment Status</th>
                                            <th>Sale Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($vehicleSales as $sale): ?>
                                            <?php
                                            $paymentStatus = strtolower(trim((string)($sale['payment_status'] ?? 'unpaid')));
                                            if (!in_array($paymentStatus, ['paid', 'partial', 'unpaid'], true)) {
                                                $paymentStatus = 'unpaid';
                                            }

                                            $saleStatus = strtolower(trim((string)($sale['sale_status'] ?? 'confirmed')));
                                            ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo h($sale['invoice_no'] ?? '-'); ?></strong>
                                                    <br><small class="text-muted">#<?php echo (int)($sale['id'] ?? 0); ?></small>
                                                </td>

                                                <td><?php echo formatDisplayDate($sale['invoice_date'] ?? $sale['created_at'] ?? ''); ?></td>

                                                <td><?php echo h($sale['branch_name'] ?? '-'); ?></td>

                                                <td>
                                                    <strong><?php echo h($sale['customer_name'] ?? '-'); ?></strong>
                                                    <?php if (!empty($sale['customer_mobile'])): ?>
                                                        <br><small><?php echo h($sale['customer_mobile']); ?></small>
                                                    <?php endif; ?>
                                                </td>

                                                <td>
                                                    <div class="vehicle-details">
                                                        <strong>
                                                            <?php echo h(trim(($sale['brand_name'] ?? '') . ' ' . ($sale['model_name'] ?? '') . ' ' . ($sale['variant_name'] ?? '')) ?: ($sale['description'] ?? 'Vehicle')); ?>
                                                        </strong>

                                                        <?php if (!empty($sale['chassis_no'])): ?>
                                                            <div><strong>Chassis:</strong> <?php echo h($sale['chassis_no']); ?></div>
                                                        <?php endif; ?>

                                                        <?php if (!empty($sale['engine_no'])): ?>
                                                            <div><strong>Engine:</strong> <?php echo h($sale['engine_no']); ?></div>
                                                        <?php endif; ?>

                                                        <?php if (!empty($sale['motor_no'])): ?>
                                                            <div><strong>Motor:</strong> <?php echo h($sale['motor_no']); ?></div>
                                                        <?php endif; ?>

                                                        <?php if (!empty($sale['color'])): ?>
                                                            <div><strong>Color:</strong> <?php echo h($sale['color']); ?></div>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>

                                                <td class="text-end">
                                                    <strong><?php echo money($sale['line_total'] ?? 0); ?></strong>
                                                    <br><small>Qty: <?php echo number_format((float)($sale['qty'] ?? 1), 2); ?></small>
                                                </td>

                                                <td>
                                                    <span class="status-badge status-<?php echo h($paymentStatus); ?>">
                                                        <?php echo ucfirst($paymentStatus); ?>
                                                    </span>
                                                    <div class="mt-1 small">
                                                        Paid: <?php echo money($sale['paid_amount'] ?? 0); ?><br>
                                                        Pending: <?php echo money($sale['balance_amount'] ?? max(0, ((float)($sale['grand_total'] ?? 0) - (float)($sale['paid_amount'] ?? 0)))); ?>
                                                    </div>
                                                </td>

                                                <td>
                                                    <span class="status-badge status-<?php echo h($saleStatus); ?>">
                                                        <?php echo ucfirst($saleStatus); ?>
                                                    </span>
                                                </td>

                                                <td>
                                                    <div class="btn-group">
                                                        <a href="sales-invoice-view.php?id=<?php echo (int)$sale['id']; ?>" class="btn btn-sm btn-info" title="View">
                                                            <i class="mdi mdi-eye"></i>
                                                        </a>
                                                        <a href="sales-invoice-print.php?id=<?php echo (int)$sale['id']; ?>" class="btn btn-sm btn-secondary" title="Print" target="_blank">
                                                            <i class="mdi mdi-printer"></i>
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <div class="mt-3 text-muted small">
                                Showing <?php echo count($vehicleSales); ?> vehicle sale records.
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

            </div>
        </div>

        <?php include('includes/footer.php'); ?>
    </div>
</div>

<?php include('includes/rightbar.php'); ?>
<?php include('includes/scripts.php'); ?>

</body>
</html>