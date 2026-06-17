<?php
ob_start();
session_start();

require_once __DIR__ . '/includes/db.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection is not available. Check includes/db.php.');
}

$conn->set_charset('utf8mb4');
date_default_timezone_set('Asia/Kolkata');

/* -------------------------------------------------------
 | Login / business scope
 * ------------------------------------------------------- */
if (empty($_SESSION['business_user_id']) && empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId     = (int)($_SESSION['business_user_id'] ?? $_SESSION['user_id'] ?? 0);
$businessId = (int)($_SESSION['business_id'] ?? 0);
$branchId   = (int)($_SESSION['branch_id'] ?? 0);
$userRole   = strtolower(trim((string)($_SESSION['role'] ?? $_SESSION['user_role'] ?? '')));

/* Fallback: load scope from business_users when session does not contain it. */
if ($businessId <= 0 && $userId > 0) {
    $scopeStmt = $conn->prepare(
        "SELECT business_id, branch_id, role FROM business_users WHERE id = ? LIMIT 1"
    );
    $scopeStmt->bind_param('i', $userId);
    $scopeStmt->execute();
    $scope = $scopeStmt->get_result()->fetch_assoc();
    $scopeStmt->close();

    if ($scope) {
        $businessId = (int)$scope['business_id'];
        $branchId   = (int)($scope['branch_id'] ?? 0);
        $userRole   = strtolower((string)$scope['role']);
    }
}

if ($businessId <= 0) {
    die('Business session is missing. Please log in again.');
}

$isAllBranchUser = in_array($userRole, ['super_admin', 'owner', 'admin'], true) || $branchId <= 0;

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

/* -------------------------------------------------------
 | Helpers
 * ------------------------------------------------------- */
function ewb_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function ewb_flash(string $type, string $message): void
{
    $_SESSION['eway_flash'] = ['type' => $type, 'message' => $message];
}

function ewb_redirect(): void
{
    header('Location: eway-bills.php');
    exit;
}

function ewb_status_badge(string $status): string
{
    $map = [
        'draft'     => 'secondary',
        'generated' => 'success',
        'updated'   => 'info',
        'cancelled' => 'danger',
        'expired'   => 'warning',
    ];
    $class = $map[$status] ?? 'secondary';
    return '<span class="badge badge-' . $class . '">' . ewb_h(ucfirst($status)) . '</span>';
}

/* -------------------------------------------------------
 | Create E-Way Bill table when this module is opened first
 * ------------------------------------------------------- */
$createTableSql = <<<SQL
CREATE TABLE IF NOT EXISTS `eway_bills` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `business_id` BIGINT UNSIGNED NOT NULL,
    `branch_id` BIGINT UNSIGNED NOT NULL,
    `reference_type` ENUM('sales_invoice','stock_transfer','sales_return','purchase_return','delivery_challan','job_work') NOT NULL DEFAULT 'sales_invoice',
    `reference_id` BIGINT UNSIGNED NOT NULL,
    `eway_bill_no` VARCHAR(20) NOT NULL,
    `document_type` VARCHAR(50) NOT NULL DEFAULT 'Tax Invoice',
    `document_number` VARCHAR(100) NOT NULL,
    `document_date` DATE NOT NULL,
    `transaction_type` ENUM('outward','inward') NOT NULL DEFAULT 'outward',
    `transaction_subtype` VARCHAR(50) NOT NULL DEFAULT 'Supply',
    `from_gstin` VARCHAR(20) DEFAULT NULL,
    `to_gstin` VARCHAR(20) DEFAULT NULL,
    `transporter_id` VARCHAR(20) DEFAULT NULL,
    `transporter_name` VARCHAR(150) DEFAULT NULL,
    `transport_mode` ENUM('road','rail','air','ship') NOT NULL DEFAULT 'road',
    `vehicle_number` VARCHAR(30) DEFAULT NULL,
    `transport_document_no` VARCHAR(100) DEFAULT NULL,
    `transport_document_date` DATE DEFAULT NULL,
    `approximate_distance` INT UNSIGNED DEFAULT NULL,
    `consignment_value` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `generated_at` DATETIME DEFAULT NULL,
    `valid_upto` DATETIME DEFAULT NULL,
    `cancelled_at` DATETIME DEFAULT NULL,
    `cancellation_reason` VARCHAR(255) DEFAULT NULL,
    `status` ENUM('draft','generated','updated','cancelled','expired') NOT NULL DEFAULT 'generated',
    `notes` TEXT DEFAULT NULL,
    `created_by` BIGINT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_eway_bill_no` (`eway_bill_no`),
    UNIQUE KEY `uq_eway_reference` (`business_id`,`reference_type`,`reference_id`),
    KEY `idx_eway_business_branch` (`business_id`,`branch_id`),
    KEY `idx_eway_status` (`status`),
    KEY `idx_eway_document_date` (`document_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

if (!$conn->query($createTableSql)) {
    die('Unable to prepare E-Way Bill table: ' . ewb_h($conn->error));
}

/* Automatically mark elapsed generated bills as expired. */
$expireStmt = $conn->prepare(
    "UPDATE eway_bills
     SET status = 'expired'
     WHERE business_id = ?
       AND status IN ('generated','updated')
       AND valid_upto IS NOT NULL
       AND valid_upto < NOW()"
);
$expireStmt->bind_param('i', $businessId);
$expireStmt->execute();
$expireStmt->close();

/* -------------------------------------------------------
 | Form actions
 * ------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($csrfToken, $postedToken)) {
        ewb_flash('error', 'Invalid request token. Please try again.');
        ewb_redirect();
    }

    $action = trim((string)($_POST['action'] ?? ''));

    try {
        if ($action === 'save_eway_bill') {
            $invoiceId             = (int)($_POST['invoice_id'] ?? 0);
            $ewayBillNo            = preg_replace('/\D+/', '', (string)($_POST['eway_bill_no'] ?? ''));
            $transportMode         = strtolower(trim((string)($_POST['transport_mode'] ?? 'road')));
            $vehicleNumber         = strtoupper(trim((string)($_POST['vehicle_number'] ?? '')));
            $transporterId         = strtoupper(trim((string)($_POST['transporter_id'] ?? '')));
            $transporterName       = trim((string)($_POST['transporter_name'] ?? ''));
            $transportDocumentNo   = trim((string)($_POST['transport_document_no'] ?? ''));
            $transportDocumentDate = trim((string)($_POST['transport_document_date'] ?? ''));
            $distance              = (int)($_POST['approximate_distance'] ?? 0);
            $generatedAtInput      = trim((string)($_POST['generated_at'] ?? ''));
            $validUptoInput        = trim((string)($_POST['valid_upto'] ?? ''));
            $notes                 = trim((string)($_POST['notes'] ?? ''));

            if ($invoiceId <= 0) {
                throw new RuntimeException('Please select a sales invoice.');
            }
            if (!preg_match('/^\d{12}$/', $ewayBillNo)) {
                throw new RuntimeException('E-Way Bill number must contain exactly 12 digits.');
            }
            if (!in_array($transportMode, ['road', 'rail', 'air', 'ship'], true)) {
                throw new RuntimeException('Invalid transport mode selected.');
            }
            if ($distance <= 0) {
                throw new RuntimeException('Approximate distance must be greater than zero.');
            }
            if ($transportMode === 'road' && $vehicleNumber === '') {
                throw new RuntimeException('Vehicle number is required for road transport.');
            }

            $invoiceSql =
                "SELECT si.id, si.business_id, si.branch_id, si.invoice_no,
                        DATE(si.invoice_date) AS invoice_date, si.grand_total,
                        c.gstin AS customer_gstin, b.gstin AS business_gstin
                 FROM sales_invoices si
                 INNER JOIN customers c ON c.id = si.customer_id
                 INNER JOIN businesses b ON b.id = si.business_id
                 WHERE si.id = ? AND si.business_id = ?";
            $invoiceTypes = 'ii';
            $invoiceParams = [$invoiceId, $businessId];

            if (!$isAllBranchUser) {
                $invoiceSql .= " AND si.branch_id = ?";
                $invoiceTypes .= 'i';
                $invoiceParams[] = $branchId;
            }
            $invoiceSql .= " AND si.sale_status <> 'cancelled' LIMIT 1";

            $invoiceStmt = $conn->prepare($invoiceSql);
            $invoiceStmt->bind_param($invoiceTypes, ...$invoiceParams);
            $invoiceStmt->execute();
            $invoice = $invoiceStmt->get_result()->fetch_assoc();
            $invoiceStmt->close();

            if (!$invoice) {
                throw new RuntimeException('The selected invoice is not available in your business/branch.');
            }

            $generatedAt = $generatedAtInput !== ''
                ? date('Y-m-d H:i:s', strtotime($generatedAtInput))
                : date('Y-m-d H:i:s');
            $validUpto = $validUptoInput !== ''
                ? date('Y-m-d H:i:s', strtotime($validUptoInput))
                : null;
            $transportDocumentDate = $transportDocumentDate !== '' ? $transportDocumentDate : null;

            $insertStmt = $conn->prepare(
                "INSERT INTO eway_bills (
                    business_id, branch_id, reference_type, reference_id,
                    eway_bill_no, document_type, document_number, document_date,
                    transaction_type, transaction_subtype, from_gstin, to_gstin,
                    transporter_id, transporter_name, transport_mode, vehicle_number,
                    transport_document_no, transport_document_date,
                    approximate_distance, consignment_value,
                    generated_at, valid_upto, status, notes, created_by
                 ) VALUES (
                    ?, ?, 'sales_invoice', ?, ?, 'Tax Invoice', ?, ?,
                    'outward', 'Supply', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'generated', ?, ?
                 )"
            );

            $invoiceBranchId = (int)$invoice['branch_id'];
            $consignmentValue = (float)$invoice['grand_total'];
            $documentNumber = (string)$invoice['invoice_no'];
            $documentDate = (string)$invoice['invoice_date'];
            $fromGstin = (string)($invoice['business_gstin'] ?? '');
            $toGstin = (string)($invoice['customer_gstin'] ?? '');

            $insertStmt->bind_param(
                'iiisssssssssssidsssi',
                $businessId,
                $invoiceBranchId,
                $invoiceId,
                $ewayBillNo,
                $documentNumber,
                $documentDate,
                $fromGstin,
                $toGstin,
                $transporterId,
                $transporterName,
                $transportMode,
                $vehicleNumber,
                $transportDocumentNo,
                $transportDocumentDate,
                $distance,
                $consignmentValue,
                $generatedAt,
                $validUpto,
                $notes,
                $userId
            );
            $insertStmt->execute();
            $insertStmt->close();

            ewb_flash('success', 'E-Way Bill details saved successfully.');
            ewb_redirect();
        }

        if ($action === 'cancel_eway_bill') {
            $ewayId = (int)($_POST['eway_id'] ?? 0);
            $reason = trim((string)($_POST['cancellation_reason'] ?? ''));

            if ($ewayId <= 0 || $reason === '') {
                throw new RuntimeException('Cancellation reason is required.');
            }

            $sql = "UPDATE eway_bills
                    SET status = 'cancelled', cancelled_at = NOW(), cancellation_reason = ?
                    WHERE id = ? AND business_id = ? AND status <> 'cancelled'";
            $types = 'sii';
            $params = [$reason, $ewayId, $businessId];

            if (!$isAllBranchUser) {
                $sql .= " AND branch_id = ?";
                $types .= 'i';
                $params[] = $branchId;
            }

            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();

            if ($affected < 1) {
                throw new RuntimeException('E-Way Bill could not be cancelled or is already cancelled.');
            }

            ewb_flash('success', 'E-Way Bill marked as cancelled. Cancel it on the government portal also.');
            ewb_redirect();
        }
    } catch (mysqli_sql_exception $e) {
        if ((int)$e->getCode() === 1062) {
            ewb_flash('error', 'This E-Way Bill number or invoice has already been recorded.');
        } else {
            ewb_flash('error', 'Database error: ' . $e->getMessage());
        }
        ewb_redirect();
    } catch (Throwable $e) {
        ewb_flash('error', $e->getMessage());
        ewb_redirect();
    }
}

/* -------------------------------------------------------
 | Filters
 * ------------------------------------------------------- */
$statusFilter = trim((string)($_GET['status'] ?? ''));
$branchFilter = (int)($_GET['branch_id'] ?? 0);
$dateFrom     = trim((string)($_GET['date_from'] ?? ''));
$dateTo       = trim((string)($_GET['date_to'] ?? ''));

$allowedStatuses = ['draft', 'generated', 'updated', 'cancelled', 'expired'];
if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = '';
}
if (!$isAllBranchUser) {
    $branchFilter = $branchId;
}

/* Branch dropdown */
$branchSql = "SELECT id, branch_name, branch_code FROM branches WHERE business_id = ? AND status = 'active'";
$branchParams = [$businessId];
$branchTypes = 'i';
if (!$isAllBranchUser) {
    $branchSql .= " AND id = ?";
    $branchParams[] = $branchId;
    $branchTypes .= 'i';
}
$branchSql .= " ORDER BY branch_name";
$branchStmt = $conn->prepare($branchSql);
$branchStmt->bind_param($branchTypes, ...$branchParams);
$branchStmt->execute();
$branches = $branchStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$branchStmt->close();

/* Eligible invoices: normally > ₹50,000, but user may manually record others when legally required. */
$invoiceSql =
    "SELECT si.id, si.invoice_no, si.invoice_date, si.grand_total,
            c.full_name AS customer_name, br.branch_name
     FROM sales_invoices si
     INNER JOIN customers c ON c.id = si.customer_id
     INNER JOIN branches br ON br.id = si.branch_id
     LEFT JOIN eway_bills eb
       ON eb.business_id = si.business_id
      AND eb.reference_type = 'sales_invoice'
      AND eb.reference_id = si.id
     WHERE si.business_id = ?
       AND si.sale_status <> 'cancelled'
       AND eb.id IS NULL";
$invoiceParams = [$businessId];
$invoiceTypes = 'i';
if (!$isAllBranchUser) {
    $invoiceSql .= " AND si.branch_id = ?";
    $invoiceParams[] = $branchId;
    $invoiceTypes .= 'i';
}
$invoiceSql .= " ORDER BY si.invoice_date DESC, si.id DESC LIMIT 300";
$invoiceStmt = $conn->prepare($invoiceSql);
$invoiceStmt->bind_param($invoiceTypes, ...$invoiceParams);
$invoiceStmt->execute();
$eligibleInvoices = $invoiceStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$invoiceStmt->close();

/* Summary */
$summarySql =
    "SELECT COUNT(*) AS total_count,
            SUM(status = 'generated') AS generated_count,
            SUM(status = 'updated') AS updated_count,
            SUM(status = 'cancelled') AS cancelled_count,
            SUM(status = 'expired') AS expired_count,
            COALESCE(SUM(CASE WHEN status <> 'cancelled' THEN consignment_value ELSE 0 END), 0) AS total_value
     FROM eway_bills
     WHERE business_id = ?";
$summaryTypes = 'i';
$summaryParams = [$businessId];
if (!$isAllBranchUser) {
    $summarySql .= " AND branch_id = ?";
    $summaryTypes .= 'i';
    $summaryParams[] = $branchId;
}
$summaryStmt = $conn->prepare($summarySql);
$summaryStmt->bind_param($summaryTypes, ...$summaryParams);
$summaryStmt->execute();
$summary = $summaryStmt->get_result()->fetch_assoc();
$summaryStmt->close();

/* Main list */
$listSql =
    "SELECT eb.*, br.branch_name,
            si.invoice_no, si.invoice_date,
            c.full_name AS customer_name, c.mobile AS customer_mobile
     FROM eway_bills eb
     LEFT JOIN branches br ON br.id = eb.branch_id
     LEFT JOIN sales_invoices si
       ON eb.reference_type = 'sales_invoice'
      AND si.id = eb.reference_id
     LEFT JOIN customers c ON c.id = si.customer_id
     WHERE eb.business_id = ?";
$listTypes = 'i';
$listParams = [$businessId];

if (!$isAllBranchUser) {
    $listSql .= " AND eb.branch_id = ?";
    $listTypes .= 'i';
    $listParams[] = $branchId;
} elseif ($branchFilter > 0) {
    $listSql .= " AND eb.branch_id = ?";
    $listTypes .= 'i';
    $listParams[] = $branchFilter;
}
if ($statusFilter !== '') {
    $listSql .= " AND eb.status = ?";
    $listTypes .= 's';
    $listParams[] = $statusFilter;
}
if ($dateFrom !== '') {
    $listSql .= " AND eb.document_date >= ?";
    $listTypes .= 's';
    $listParams[] = $dateFrom;
}
if ($dateTo !== '') {
    $listSql .= " AND eb.document_date <= ?";
    $listTypes .= 's';
    $listParams[] = $dateTo;
}
$listSql .= " ORDER BY eb.id DESC";

$listStmt = $conn->prepare($listSql);
$listStmt->bind_param($listTypes, ...$listParams);
$listStmt->execute();
$ewayBills = $listStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$listStmt->close();

$flash = $_SESSION['eway_flash'] ?? null;
unset($_SESSION['eway_flash']);

$pageTitle = 'E-Way Bills';
?>
<!doctype html>
<html lang="en">
<head>
    <?php include __DIR__ . '/includes/head.php'; ?>
    <style>
        .summary-card { border: 0; box-shadow: 0 2px 10px rgba(0,0,0,.05); }
        .summary-label { font-size: 12px; color: #74788d; text-transform: uppercase; letter-spacing: .04em; }
        .summary-value { font-size: 23px; font-weight: 700; margin-top: 4px; }
        .table td, .table th { vertical-align: middle; }
        .nowrap { white-space: nowrap; }
        .eway-number { font-weight: 700; letter-spacing: .5px; }
        .small-muted { font-size: 12px; color: #74788d; }
        .action-btn { width: 32px; height: 32px; padding: 5px; display: inline-flex; align-items: center; justify-content: center; }
        @media print {
            .no-print, .vertical-menu, .navbar-header, .footer { display: none !important; }
            .main-content { margin-left: 0 !important; }
            .page-content { padding: 0 !important; }
            .card { border: 0 !important; box-shadow: none !important; }
        }
    </style>
</head>
<body data-sidebar="dark">
<div id="layout-wrapper">
    <?php include __DIR__ . '/includes/topbar.php'; ?>
    <div class="vertical-menu">
        <div data-simplebar class="h-100">
            <?php include __DIR__ . '/includes/sidebar.php'; ?>
        </div>
    </div>

    <div class="main-content">
        <div class="page-content">
            <div class="container-fluid">

                <div class="row align-items-center mb-3 no-print">
                    <div class="col-sm-6">
                        <h4 class="mb-1">E-Way Bills</h4>
                        <p class="text-muted mb-0">Record and track official E-Way Bills generated on the GST portal.</p>
                    </div>
                    <div class="col-sm-6 text-sm-right mt-2 mt-sm-0">
                        <button type="button" class="btn btn-light" onclick="window.print()">
                            <i class="dripicons-print mr-1"></i> Print
                        </button>
                        <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#addEwayModal">
                            <i class="dripicons-plus mr-1"></i> Record E-Way Bill
                        </button>
                    </div>
                </div>

                <?php if ($flash): ?>
                    <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?> alert-dismissible fade show no-print" role="alert">
                        <?= ewb_h($flash['message']) ?>
                        <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
                    </div>
                <?php endif; ?>

                <div class="row">
                    <div class="col-xl-3 col-md-6">
                        <div class="card summary-card"><div class="card-body">
                            <div class="summary-label">Total E-Way Bills</div>
                            <div class="summary-value"><?= number_format((int)($summary['total_count'] ?? 0)) ?></div>
                        </div></div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="card summary-card"><div class="card-body">
                            <div class="summary-label">Active / Updated</div>
                            <div class="summary-value text-success"><?= number_format((int)($summary['generated_count'] ?? 0) + (int)($summary['updated_count'] ?? 0)) ?></div>
                        </div></div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="card summary-card"><div class="card-body">
                            <div class="summary-label">Cancelled / Expired</div>
                            <div class="summary-value text-danger"><?= number_format((int)($summary['cancelled_count'] ?? 0) + (int)($summary['expired_count'] ?? 0)) ?></div>
                        </div></div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="card summary-card"><div class="card-body">
                            <div class="summary-label">Consignment Value</div>
                            <div class="summary-value">₹<?= number_format((float)($summary['total_value'] ?? 0), 2) ?></div>
                        </div></div>
                    </div>
                </div>

                <div class="card no-print">
                    <div class="card-body">
                        <form method="get" class="row align-items-end">
                            <?php if ($isAllBranchUser): ?>
                                <div class="col-lg-3 col-md-6 mb-2">
                                    <label>Branch</label>
                                    <select name="branch_id" class="form-control">
                                        <option value="0">All Branches</option>
                                        <?php foreach ($branches as $branch): ?>
                                            <option value="<?= (int)$branch['id'] ?>" <?= $branchFilter === (int)$branch['id'] ? 'selected' : '' ?>>
                                                <?= ewb_h($branch['branch_name']) ?> (<?= ewb_h($branch['branch_code']) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            <?php endif; ?>
                            <div class="col-lg-2 col-md-6 mb-2">
                                <label>Status</label>
                                <select name="status" class="form-control">
                                    <option value="">All Statuses</option>
                                    <?php foreach ($allowedStatuses as $status): ?>
                                        <option value="<?= ewb_h($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= ewb_h(ucfirst($status)) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-lg-2 col-md-6 mb-2">
                                <label>From Date</label>
                                <input type="date" name="date_from" class="form-control" value="<?= ewb_h($dateFrom) ?>">
                            </div>
                            <div class="col-lg-2 col-md-6 mb-2">
                                <label>To Date</label>
                                <input type="date" name="date_to" class="form-control" value="<?= ewb_h($dateTo) ?>">
                            </div>
                            <div class="col-lg-3 mb-2">
                                <button class="btn btn-primary" type="submit"><i class="dripicons-search mr-1"></i> Filter</button>
                                <a href="eway-bills.php" class="btn btn-light">Reset</a>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header bg-primary text-white d-flex align-items-center justify-content-between">
                        <h5 class="mb-0 text-white"><i class="dripicons-document mr-2"></i>E-Way Bill Register</h5>
                        <span class="badge badge-light"><?= count($ewayBills) ?> record(s)</span>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table id="ewayTable" class="table table-bordered table-striped mb-0">
                                <thead class="thead-light">
                                <tr>
                                    <th>#</th>
                                    <th>E-Way Bill</th>
                                    <th>Invoice / Customer</th>
                                    <th>Branch</th>
                                    <th>Document Date</th>
                                    <th>Transport</th>
                                    <th class="text-right">Consignment</th>
                                    <th>Validity</th>
                                    <th>Status</th>
                                    <th class="no-print text-center">Actions</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php if (!$ewayBills): ?>
                                    <tr><td colspan="10" class="text-center text-muted py-4">No E-Way Bills found.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($ewayBills as $index => $row): ?>
                                        <tr>
                                            <td><?= $index + 1 ?></td>
                                            <td>
                                                <div class="eway-number"><?= ewb_h($row['eway_bill_no']) ?></div>
                                                <div class="small-muted"><?= ewb_h(ucfirst(str_replace('_', ' ', $row['reference_type']))) ?></div>
                                            </td>
                                            <td>
                                                <div class="font-weight-bold"><?= ewb_h($row['document_number'] ?: $row['invoice_no']) ?></div>
                                                <div class="small-muted"><?= ewb_h($row['customer_name'] ?: '—') ?></div>
                                            </td>
                                            <td><?= ewb_h($row['branch_name'] ?: '—') ?></td>
                                            <td class="nowrap"><?= $row['document_date'] ? date('d-m-Y', strtotime($row['document_date'])) : '—' ?></td>
                                            <td>
                                                <div><?= ewb_h(ucfirst($row['transport_mode'])) ?></div>
                                                <div class="small-muted"><?= ewb_h($row['vehicle_number'] ?: $row['transporter_name'] ?: '—') ?></div>
                                            </td>
                                            <td class="text-right nowrap">₹<?= number_format((float)$row['consignment_value'], 2) ?></td>
                                            <td class="nowrap">
                                                <?php if ($row['valid_upto']): ?>
                                                    <?= date('d-m-Y', strtotime($row['valid_upto'])) ?><br>
                                                    <span class="small-muted"><?= date('h:i A', strtotime($row['valid_upto'])) ?></span>
                                                <?php else: ?>—<?php endif; ?>
                                            </td>
                                            <td><?= ewb_status_badge((string)$row['status']) ?></td>
                                            <td class="no-print text-center nowrap">
                                                <button type="button"
                                                        class="btn btn-info btn-sm action-btn view-eway"
                                                        title="View"
                                                        data-toggle="modal"
                                                        data-target="#viewEwayModal"
                                                        data-row='<?= ewb_h(json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>'>
                                                    <i class="dripicons-preview"></i>
                                                </button>
                                                <?php if ($row['status'] !== 'cancelled'): ?>
                                                    <button type="button"
                                                            class="btn btn-danger btn-sm action-btn cancel-eway"
                                                            title="Cancel"
                                                            data-toggle="modal"
                                                            data-target="#cancelEwayModal"
                                                            data-id="<?= (int)$row['id'] ?>"
                                                            data-number="<?= ewb_h($row['eway_bill_no']) ?>">
                                                        <i class="dripicons-cross"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="alert alert-info no-print">
                    <strong>Important:</strong> This page records and tracks the official E-Way Bill generated on the government portal. Marking a record cancelled here does not cancel it on the GST portal.
                </div>
            </div>
        </div>
        <?php include __DIR__ . '/includes/footer.php'; ?>
    </div>
</div>

<!-- Record E-Way Bill Modal -->
<div class="modal fade" id="addEwayModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <form method="post" class="modal-content" id="ewayForm">
            <input type="hidden" name="csrf_token" value="<?= ewb_h($csrfToken) ?>">
            <input type="hidden" name="action" value="save_eway_bill">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title text-white">Record Generated E-Way Bill</h5>
                <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-light border">
                    Generate the E-Way Bill on the official GST portal first, then save its issued details here.
                </div>
                <div class="form-row">
                    <div class="form-group col-md-8">
                        <label>Sales Invoice <span class="text-danger">*</span></label>
                        <select name="invoice_id" id="invoice_id" class="form-control" required>
                            <option value="">Select invoice</option>
                            <?php foreach ($eligibleInvoices as $invoice): ?>
                                <option value="<?= (int)$invoice['id'] ?>" data-value="<?= ewb_h($invoice['grand_total']) ?>">
                                    <?= ewb_h($invoice['invoice_no']) ?> — <?= ewb_h($invoice['customer_name']) ?> — ₹<?= number_format((float)$invoice['grand_total'], 2) ?> — <?= ewb_h($invoice['branch_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (!$eligibleInvoices): ?><small class="text-muted">No unlinked sales invoice is available.</small><?php endif; ?>
                    </div>
                    <div class="form-group col-md-4">
                        <label>Consignment Value</label>
                        <input type="text" id="display_consignment" class="form-control" value="₹0.00" readonly>
                    </div>
                    <div class="form-group col-md-4">
                        <label>E-Way Bill Number <span class="text-danger">*</span></label>
                        <input type="text" name="eway_bill_no" class="form-control" maxlength="12" pattern="[0-9]{12}" placeholder="12-digit EBN" required>
                    </div>
                    <div class="form-group col-md-4">
                        <label>Generated At <span class="text-danger">*</span></label>
                        <input type="datetime-local" name="generated_at" class="form-control" value="<?= date('Y-m-d\TH:i') ?>" required>
                    </div>
                    <div class="form-group col-md-4">
                        <label>Valid Upto</label>
                        <input type="datetime-local" name="valid_upto" class="form-control">
                    </div>
                    <div class="form-group col-md-4">
                        <label>Transport Mode <span class="text-danger">*</span></label>
                        <select name="transport_mode" id="transport_mode" class="form-control" required>
                            <option value="road">Road</option>
                            <option value="rail">Rail</option>
                            <option value="air">Air</option>
                            <option value="ship">Ship</option>
                        </select>
                    </div>
                    <div class="form-group col-md-4">
                        <label>Vehicle Number</label>
                        <input type="text" name="vehicle_number" id="vehicle_number" class="form-control text-uppercase" placeholder="TN29AB1234">
                    </div>
                    <div class="form-group col-md-4">
                        <label>Approx. Distance (KM) <span class="text-danger">*</span></label>
                        <input type="number" name="approximate_distance" class="form-control" min="1" required>
                    </div>
                    <div class="form-group col-md-4">
                        <label>Transporter ID / GSTIN</label>
                        <input type="text" name="transporter_id" class="form-control text-uppercase">
                    </div>
                    <div class="form-group col-md-4">
                        <label>Transporter Name</label>
                        <input type="text" name="transporter_name" class="form-control">
                    </div>
                    <div class="form-group col-md-4">
                        <label>Transport Document No.</label>
                        <input type="text" name="transport_document_no" class="form-control">
                    </div>
                    <div class="form-group col-md-4">
                        <label>Transport Document Date</label>
                        <input type="date" name="transport_document_date" class="form-control">
                    </div>
                    <div class="form-group col-md-8">
                        <label>Notes</label>
                        <input type="text" name="notes" class="form-control" placeholder="Optional notes">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-dismiss="modal">Close</button>
                <button type="submit" class="btn btn-primary" <?= !$eligibleInvoices ? 'disabled' : '' ?>>Save E-Way Bill</button>
            </div>
        </form>
    </div>
</div>

<!-- View Modal -->
<div class="modal fade" id="viewEwayModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title text-white">E-Way Bill Details</h5>
                <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body"><div class="row" id="viewEwayContent"></div></div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-dismiss="modal">Close</button></div>
        </div>
    </div>
</div>

<!-- Cancel Modal -->
<div class="modal fade" id="cancelEwayModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <form method="post" class="modal-content">
            <input type="hidden" name="csrf_token" value="<?= ewb_h($csrfToken) ?>">
            <input type="hidden" name="action" value="cancel_eway_bill">
            <input type="hidden" name="eway_id" id="cancel_eway_id">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title text-white">Cancel E-Way Bill</h5>
                <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <p>Mark E-Way Bill <strong id="cancel_eway_number"></strong> as cancelled?</p>
                <div class="form-group mb-0">
                    <label>Cancellation Reason <span class="text-danger">*</span></label>
                    <textarea name="cancellation_reason" class="form-control" rows="3" required></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-dismiss="modal">Close</button>
                <button type="submit" class="btn btn-danger">Confirm Cancel</button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/includes/rightbar.php'; ?>
<?php include __DIR__ . '/includes/scripts.php'; ?>
<script>
(function ($) {
    'use strict';

    if ($.fn.DataTable && $('#ewayTable tbody tr td').length && !$('#ewayTable tbody td[colspan]').length) {
        $('#ewayTable').DataTable({
            pageLength: 25,
            order: [[0, 'asc']],
            columnDefs: [{ orderable: false, targets: -1 }]
        });
    }

    $('#invoice_id').on('change', function () {
        var value = parseFloat($(this).find(':selected').data('value') || 0);
        $('#display_consignment').val('₹' + value.toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2}));
    });

    $('#transport_mode').on('change', function () {
        var isRoad = this.value === 'road';
        $('#vehicle_number').prop('required', isRoad);
    }).trigger('change');

    $('.cancel-eway').on('click', function () {
        $('#cancel_eway_id').val($(this).data('id'));
        $('#cancel_eway_number').text($(this).data('number'));
    });

    $('.view-eway').on('click', function () {
        var row = $(this).data('row');
        if (typeof row === 'string') {
            try { row = JSON.parse(row); } catch (e) { row = {}; }
        }

        function esc(value) {
            return $('<div>').text(value == null || value === '' ? '—' : value).html();
        }
        function field(label, value) {
            return '<div class="col-md-6 mb-3"><div class="small-muted">' + esc(label) + '</div><div class="font-weight-bold">' + esc(value) + '</div></div>';
        }

        var html = '';
        html += field('E-Way Bill Number', row.eway_bill_no);
        html += field('Status', row.status ? row.status.charAt(0).toUpperCase() + row.status.slice(1) : '—');
        html += field('Document Number', row.document_number);
        html += field('Document Date', row.document_date);
        html += field('Customer', row.customer_name);
        html += field('Branch', row.branch_name);
        html += field('Consignment Value', '₹' + parseFloat(row.consignment_value || 0).toLocaleString('en-IN', {minimumFractionDigits: 2}));
        html += field('Transport Mode', row.transport_mode);
        html += field('Vehicle Number', row.vehicle_number);
        html += field('Transporter', row.transporter_name);
        html += field('Transporter ID', row.transporter_id);
        html += field('Approximate Distance', row.approximate_distance ? row.approximate_distance + ' KM' : '—');
        html += field('Generated At', row.generated_at);
        html += field('Valid Upto', row.valid_upto);
        html += field('Cancellation Reason', row.cancellation_reason);
        html += field('Notes', row.notes);
        $('#viewEwayContent').html(html);
    });
})(jQuery);
</script>
</body>
</html>
<?php ob_end_flush(); ?>
