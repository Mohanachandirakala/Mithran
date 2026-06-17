<?php
ob_start();
session_start();

require_once __DIR__ . '/includes/config.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection is not available. Check includes/config.php.');
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');
date_default_timezone_set('Asia/Kolkata');

/* -------------------------------------------------------
 | Authentication and business scope
 * ------------------------------------------------------- */
if (empty($_SESSION['business_user_id']) && empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId     = (int)($_SESSION['business_user_id'] ?? $_SESSION['user_id'] ?? 0);
$businessId = (int)($_SESSION['business_id'] ?? 0);
$branchId   = (int)($_SESSION['branch_id'] ?? 0);
$userRole   = strtolower(trim((string)($_SESSION['role'] ?? $_SESSION['user_role'] ?? '')));

if ($businessId <= 0 && $userId > 0) {
    $scopeStmt = $conn->prepare(
        "SELECT business_id, branch_id, role
         FROM business_users
         WHERE id = ?
         LIMIT 1"
    );
    $scopeStmt->bind_param('i', $userId);
    $scopeStmt->execute();
    $scope = $scopeStmt->get_result()->fetch_assoc();
    $scopeStmt->close();

    if ($scope) {
        $businessId = (int)$scope['business_id'];
        $branchId   = (int)($scope['branch_id'] ?? 0);
        $userRole   = strtolower(trim((string)$scope['role']));
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
    $_SESSION['eway_flash'] = [
        'type' => $type,
        'message' => $message
    ];
}

function ewb_redirect(string $query = ''): void
{
    header('Location: eway-bills.php' . ($query !== '' ? '?' . $query : ''));
    exit;
}

function ewb_bind(mysqli_stmt $stmt, string $types, array &$params): void
{
    if ($types === '' || !$params) {
        return;
    }

    $bind = [$types];
    foreach ($params as $key => &$value) {
        $bind[] = &$value;
    }
    unset($value);

    call_user_func_array([$stmt, 'bind_param'], $bind);
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

    return '<span class="badge badge-' . $class . '">' .
        ewb_h(ucfirst($status)) .
        '</span>';
}

function ewb_parse_datetime(string $value, string $label, bool $required = false): ?string
{
    $value = trim($value);

    if ($value === '') {
        if ($required) {
            throw new RuntimeException($label . ' is required.');
        }
        return null;
    }

    $timestamp = strtotime($value);

    if ($timestamp === false) {
        throw new RuntimeException('Invalid ' . $label . '.');
    }

    return date('Y-m-d H:i:s', $timestamp);
}

function ewb_clean_vehicle(string $vehicle): string
{
    $vehicle = strtoupper(trim($vehicle));
    return preg_replace('/[^A-Z0-9]/', '', $vehicle);
}

/* -------------------------------------------------------
 | Table and migration
 * ------------------------------------------------------- */
$createSql = <<<SQL
CREATE TABLE IF NOT EXISTS `eway_bills` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `business_id` BIGINT UNSIGNED NOT NULL,
    `branch_id` BIGINT UNSIGNED NOT NULL,
    `reference_type` ENUM(
        'sales_invoice',
        'stock_transfer',
        'sales_return',
        'purchase_return',
        'delivery_challan',
        'job_work'
    ) NOT NULL DEFAULT 'sales_invoice',
    `reference_id` BIGINT UNSIGNED NOT NULL,
    `draft_no` VARCHAR(40) DEFAULT NULL,
    `eway_bill_no` VARCHAR(20) DEFAULT NULL,
    `document_type` VARCHAR(50) NOT NULL DEFAULT 'Tax Invoice',
    `document_number` VARCHAR(100) NOT NULL,
    `document_date` DATE NOT NULL,
    `transaction_type` ENUM('outward','inward') NOT NULL DEFAULT 'outward',
    `transaction_subtype` VARCHAR(50) NOT NULL DEFAULT 'Supply',
    `from_gstin` VARCHAR(20) DEFAULT NULL,
    `to_gstin` VARCHAR(20) DEFAULT NULL,
    `supplier_name` VARCHAR(180) DEFAULT NULL,
    `recipient_name` VARCHAR(180) DEFAULT NULL,
    `dispatch_address` TEXT DEFAULT NULL,
    `delivery_address` TEXT DEFAULT NULL,
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
    `status` ENUM('draft','generated','updated','cancelled','expired') NOT NULL DEFAULT 'draft',
    `notes` TEXT DEFAULT NULL,
    `created_by` BIGINT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_eway_bill_no` (`eway_bill_no`),
    UNIQUE KEY `uq_eway_reference` (`business_id`,`reference_type`,`reference_id`),
    UNIQUE KEY `uq_eway_draft_no` (`draft_no`),
    KEY `idx_eway_business_branch` (`business_id`,`branch_id`),
    KEY `idx_eway_status` (`status`),
    KEY `idx_eway_document_date` (`document_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

$conn->query($createSql);

/* Safe migration for older table versions. */
$columnsResult = $conn->query("SHOW COLUMNS FROM eway_bills");
$columns = [];
while ($column = $columnsResult->fetch_assoc()) {
    $columns[$column['Field']] = $column;
}

if (!isset($columns['draft_no'])) {
    $conn->query("ALTER TABLE eway_bills ADD COLUMN draft_no VARCHAR(40) DEFAULT NULL AFTER reference_id");
    $conn->query("ALTER TABLE eway_bills ADD UNIQUE KEY uq_eway_draft_no (draft_no)");
}

if (isset($columns['eway_bill_no']) && strtoupper((string)$columns['eway_bill_no']['Null']) === 'NO') {
    $conn->query("ALTER TABLE eway_bills MODIFY eway_bill_no VARCHAR(20) DEFAULT NULL");
}

if (!isset($columns['supplier_name'])) {
    $conn->query("ALTER TABLE eway_bills ADD COLUMN supplier_name VARCHAR(180) DEFAULT NULL AFTER to_gstin");
}
if (!isset($columns['recipient_name'])) {
    $conn->query("ALTER TABLE eway_bills ADD COLUMN recipient_name VARCHAR(180) DEFAULT NULL AFTER supplier_name");
}
if (!isset($columns['dispatch_address'])) {
    $conn->query("ALTER TABLE eway_bills ADD COLUMN dispatch_address TEXT DEFAULT NULL AFTER recipient_name");
}
if (!isset($columns['delivery_address'])) {
    $conn->query("ALTER TABLE eway_bills ADD COLUMN delivery_address TEXT DEFAULT NULL AFTER dispatch_address");
}

/* Mark elapsed bills expired. */
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
 | Reusable invoice loader
 * ------------------------------------------------------- */
function ewb_load_invoice(
    mysqli $conn,
    int $invoiceId,
    int $businessId,
    int $branchId,
    bool $isAllBranchUser
): ?array {
    $sql =
        "SELECT
            si.id,
            si.business_id,
            si.branch_id,
            si.invoice_no,
            CASE
                WHEN si.invoice_date IS NULL
                  OR si.invoice_date = '0000-00-00 00:00:00'
                THEN DATE(si.created_at)
                ELSE DATE(si.invoice_date)
            END AS invoice_date,
            si.invoice_type,
            si.subtotal,
            si.discount_amount,
            si.cgst_amount,
            si.sgst_amount,
            si.igst_amount,
            si.cess_amount,
            si.round_off,
            si.grand_total,
            c.full_name AS customer_name,
            c.mobile AS customer_mobile,
            c.gstin AS customer_gstin,
            c.address_line1 AS customer_address_line1,
            c.address_line2 AS customer_address_line2,
            c.city AS customer_city,
            c.district AS customer_district,
            c.state AS customer_state,
            c.pincode AS customer_pincode,
            b.business_name,
            b.gstin AS business_gstin,
            b.address_line1 AS business_address_line1,
            b.address_line2 AS business_address_line2,
            b.city AS business_city,
            b.district AS business_district,
            b.state AS business_state,
            b.pincode AS business_pincode,
            br.branch_name,
            br.gstin AS branch_gstin,
            br.address_line1 AS branch_address_line1,
            br.address_line2 AS branch_address_line2,
            br.city AS branch_city,
            br.district AS branch_district,
            br.state AS branch_state,
            br.pincode AS branch_pincode
         FROM sales_invoices si
         LEFT JOIN customers c
           ON c.id = si.customer_id
          AND c.business_id = si.business_id
         INNER JOIN businesses b
           ON b.id = si.business_id
         INNER JOIN branches br
           ON br.id = si.branch_id
          AND br.business_id = si.business_id
         WHERE si.id = ?
           AND si.business_id = ?
           AND si.sale_status <> 'cancelled'";

    $types = 'ii';
    $params = [$invoiceId, $businessId];

    if (!$isAllBranchUser) {
        $sql .= " AND si.branch_id = ?";
        $types .= 'i';
        $params[] = $branchId;
    }

    $sql .= " LIMIT 1";

    $stmt = $conn->prepare($sql);
    ewb_bind($stmt, $types, $params);
    $stmt->execute();
    $invoice = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $invoice ?: null;
}

/* -------------------------------------------------------
 | Invoice details endpoint for auto-fill
 * ------------------------------------------------------- */
if (($_GET['ajax'] ?? '') === 'invoice_details') {
    header('Content-Type: application/json; charset=utf-8');

    try {
        $invoiceId = (int)($_GET['invoice_id'] ?? 0);

        if ($invoiceId <= 0) {
            throw new RuntimeException('Invalid invoice.');
        }

        $invoice = ewb_load_invoice(
            $conn,
            $invoiceId,
            $businessId,
            $branchId,
            $isAllBranchUser
        );

        if (!$invoice) {
            throw new RuntimeException('Invoice not found.');
        }

        $itemsStmt = $conn->prepare(
            "SELECT
                sii.id,
                sii.item_type,
                sii.description,
                sii.qty,
                sii.unit_price,
                sii.discount_amount,
                sii.taxable_value,
                sii.cgst_percent,
                sii.sgst_percent,
                sii.igst_percent,
                sii.cess_percent,
                sii.cgst_amount,
                sii.sgst_amount,
                sii.igst_amount,
                sii.cess_amount,
                sii.line_total,
                COALESCE(
                    NULLIF(p.hsn_code, ''),
                    NULLIF(vm.hsn_code, ''),
                    ''
                ) AS hsn_code,
                COALESCE(
                    NULLIF(p.unit, ''),
                    'NOS'
                ) AS unit_name,
                vs.chassis_no,
                vs.engine_no,
                vs.motor_no,
                vs.vin_no,
                vm.model_name,
                vm.variant_name
             FROM sales_invoice_items sii
             LEFT JOIN products p
               ON p.id = sii.product_id
              AND p.business_id = ?
             LEFT JOIN vehicle_stock vs
               ON vs.id = sii.vehicle_stock_id
              AND vs.business_id = ?
             LEFT JOIN vehicle_models vm
               ON vm.id = vs.model_id
              AND vm.business_id = ?
             WHERE sii.invoice_id = ?
             ORDER BY sii.id"
        );

        $itemsStmt->bind_param(
            'iiii',
            $businessId,
            $businessId,
            $businessId,
            $invoiceId
        );
        $itemsStmt->execute();
        $items = $itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $itemsStmt->close();

        $dispatchGstin = trim((string)($invoice['branch_gstin'] ?: $invoice['business_gstin']));

        $dispatchAddress = array_filter([
            $invoice['branch_address_line1'] ?: $invoice['business_address_line1'],
            $invoice['branch_address_line2'] ?: $invoice['business_address_line2'],
            $invoice['branch_city'] ?: $invoice['business_city'],
            $invoice['branch_district'] ?: $invoice['business_district'],
            $invoice['branch_state'] ?: $invoice['business_state'],
            $invoice['branch_pincode'] ?: $invoice['business_pincode'],
        ], static fn($value) => trim((string)$value) !== '');

        $deliveryAddress = array_filter([
            $invoice['customer_address_line1'],
            $invoice['customer_address_line2'],
            $invoice['customer_city'],
            $invoice['customer_district'],
            $invoice['customer_state'],
            $invoice['customer_pincode'],
        ], static fn($value) => trim((string)$value) !== '');

        echo json_encode([
            'success' => true,
            'invoice' => [
                'id' => (int)$invoice['id'],
                'invoice_no' => $invoice['invoice_no'],
                'invoice_date' => $invoice['invoice_date'],
                'invoice_type' => $invoice['invoice_type'],
                'supplier_name' => $invoice['business_name'],
                'branch_name' => $invoice['branch_name'],
                'from_gstin' => $dispatchGstin,
                'dispatch_address' => implode(', ', $dispatchAddress),
                'dispatch_state' => $invoice['branch_state'] ?: $invoice['business_state'],
                'dispatch_pincode' => $invoice['branch_pincode'] ?: $invoice['business_pincode'],
                'customer_name' => $invoice['customer_name'],
                'customer_mobile' => $invoice['customer_mobile'],
                'to_gstin' => $invoice['customer_gstin'],
                'delivery_address' => implode(', ', $deliveryAddress),
                'delivery_state' => $invoice['customer_state'],
                'delivery_pincode' => $invoice['customer_pincode'],
                'subtotal' => (float)$invoice['subtotal'],
                'discount_amount' => (float)$invoice['discount_amount'],
                'cgst_amount' => (float)$invoice['cgst_amount'],
                'sgst_amount' => (float)$invoice['sgst_amount'],
                'igst_amount' => (float)$invoice['igst_amount'],
                'cess_amount' => (float)$invoice['cess_amount'],
                'round_off' => (float)$invoice['round_off'],
                'grand_total' => (float)$invoice['grand_total'],
            ],
            'items' => $items,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
        ]);
    }

    exit;
}

/* -------------------------------------------------------
 | POST actions
 * ------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = (string)($_POST['csrf_token'] ?? '');

    if (!hash_equals($csrfToken, $postedToken)) {
        ewb_flash('error', 'Invalid request token. Please try again.');
        ewb_redirect();
    }

    $action = trim((string)($_POST['action'] ?? ''));

    try {
        if ($action === 'create_draft') {
            $invoiceId             = (int)($_POST['invoice_id'] ?? 0);
            $transportMode         = strtolower(trim((string)($_POST['transport_mode'] ?? 'road')));
            $vehicleNumber         = ewb_clean_vehicle((string)($_POST['vehicle_number'] ?? ''));
            $transporterId         = strtoupper(trim((string)($_POST['transporter_id'] ?? '')));
            $transporterName       = trim((string)($_POST['transporter_name'] ?? ''));
            $transportDocumentNo   = trim((string)($_POST['transport_document_no'] ?? ''));
            $transportDocumentDate = trim((string)($_POST['transport_document_date'] ?? ''));
            $distance              = (int)($_POST['approximate_distance'] ?? 0);
            $notes                 = trim((string)($_POST['notes'] ?? ''));
            $postedFromGstin       = strtoupper(trim((string)($_POST['from_gstin'] ?? '')));
            $postedToGstin         = strtoupper(trim((string)($_POST['to_gstin'] ?? '')));
            $postedSupplierName    = trim((string)($_POST['supplier_name'] ?? ''));
            $postedRecipientName   = trim((string)($_POST['recipient_name'] ?? ''));
            $postedDispatchAddress = trim((string)($_POST['dispatch_address'] ?? ''));
            $postedDeliveryAddress = trim((string)($_POST['delivery_address'] ?? ''));

            if ($invoiceId <= 0) {
                throw new RuntimeException('Please select a sales invoice.');
            }

            if (!in_array($transportMode, ['road', 'rail', 'air', 'ship'], true)) {
                throw new RuntimeException('Invalid transport mode.');
            }

            if ($distance <= 0) {
                throw new RuntimeException('Approximate distance must be greater than zero.');
            }

            if ($transportMode === 'road' && $vehicleNumber === '') {
                throw new RuntimeException('Vehicle number is required for road transport.');
            }

            if ($vehicleNumber !== '' && (strlen($vehicleNumber) < 4 || strlen($vehicleNumber) > 15)) {
                throw new RuntimeException('Enter a valid vehicle number.');
            }

            if ($transportDocumentDate !== '') {
                $dateObject = DateTime::createFromFormat('Y-m-d', $transportDocumentDate);
                if (!$dateObject || $dateObject->format('Y-m-d') !== $transportDocumentDate) {
                    throw new RuntimeException('Invalid transport document date.');
                }
            } else {
                $transportDocumentDate = null;
            }

            $invoice = ewb_load_invoice(
                $conn,
                $invoiceId,
                $businessId,
                $branchId,
                $isAllBranchUser
            );

            if (!$invoice) {
                throw new RuntimeException('Selected sales invoice is not available.');
            }

            $draftNo = 'EWD-' . date('ymdHis') . '-' . $invoiceId;
            $invoiceBranchId = (int)$invoice['branch_id'];
            $documentNumber = (string)$invoice['invoice_no'];
            $documentDate = (string)$invoice['invoice_date'];
            $fromGstin = $postedFromGstin !== ''
                ? $postedFromGstin
                : (string)($invoice['branch_gstin'] ?: $invoice['business_gstin'] ?? '');

            $toGstin = $postedToGstin !== ''
                ? $postedToGstin
                : (string)($invoice['customer_gstin'] ?? '');

            $supplierName = $postedSupplierName !== ''
                ? $postedSupplierName
                : trim(
                    (string)($invoice['business_name'] ?? '') .
                    (($invoice['branch_name'] ?? '') !== '' ? ' - ' . $invoice['branch_name'] : '')
                );

            $recipientName = $postedRecipientName !== ''
                ? $postedRecipientName
                : (string)($invoice['customer_name'] ?? '');

            $dispatchAddress = $postedDispatchAddress;
            if ($dispatchAddress === '') {
                $dispatchAddress = implode(', ', array_filter([
                    $invoice['branch_address_line1'] ?: $invoice['business_address_line1'],
                    $invoice['branch_address_line2'] ?: $invoice['business_address_line2'],
                    $invoice['branch_city'] ?: $invoice['business_city'],
                    $invoice['branch_district'] ?: $invoice['business_district'],
                    $invoice['branch_state'] ?: $invoice['business_state'],
                    $invoice['branch_pincode'] ?: $invoice['business_pincode'],
                ], static fn($value) => trim((string)$value) !== ''));
            }

            $deliveryAddress = $postedDeliveryAddress;
            if ($deliveryAddress === '') {
                $deliveryAddress = implode(', ', array_filter([
                    $invoice['customer_address_line1'],
                    $invoice['customer_address_line2'],
                    $invoice['customer_city'],
                    $invoice['customer_district'],
                    $invoice['customer_state'],
                    $invoice['customer_pincode'],
                ], static fn($value) => trim((string)$value) !== ''));
            }

            $consignmentValue = (float)$invoice['grand_total'];

            $stmt = $conn->prepare(
                "INSERT INTO eway_bills SET
                    business_id = ?,
                    branch_id = ?,
                    reference_type = 'sales_invoice',
                    reference_id = ?,
                    draft_no = ?,
                    eway_bill_no = NULL,
                    document_type = 'Tax Invoice',
                    document_number = ?,
                    document_date = ?,
                    transaction_type = 'outward',
                    transaction_subtype = 'Supply',
                    from_gstin = ?,
                    to_gstin = ?,
                    supplier_name = ?,
                    recipient_name = ?,
                    dispatch_address = ?,
                    delivery_address = ?,
                    transporter_id = ?,
                    transporter_name = ?,
                    transport_mode = ?,
                    vehicle_number = ?,
                    transport_document_no = ?,
                    transport_document_date = ?,
                    approximate_distance = ?,
                    consignment_value = ?,
                    generated_at = NULL,
                    valid_upto = NULL,
                    status = 'draft',
                    notes = ?,
                    created_by = ?"
            );

            $stmt->bind_param(
                'iiisssssssssssssssidssi',
                $businessId,
                $invoiceBranchId,
                $invoiceId,
                $draftNo,
                $documentNumber,
                $documentDate,
                $fromGstin,
                $toGstin,
                $supplierName,
                $recipientName,
                $dispatchAddress,
                $deliveryAddress,
                $transporterId,
                $transporterName,
                $transportMode,
                $vehicleNumber,
                $transportDocumentNo,
                $transportDocumentDate,
                $distance,
                $consignmentValue,
                $notes,
                $userId
            );

            $stmt->execute();
            $stmt->close();

            ewb_flash(
                'success',
                'E-Way Bill draft created. Now generate the official E-Way Bill on the GST portal, then record the 12-digit EBN here.'
            );
            ewb_redirect();
        }

        if ($action === 'record_generated') {
            $ewayId           = (int)($_POST['eway_id'] ?? 0);
            $ewayBillNo       = preg_replace('/\D+/', '', (string)($_POST['eway_bill_no'] ?? ''));
            $generatedAtInput = trim((string)($_POST['generated_at'] ?? ''));
            $validUptoInput   = trim((string)($_POST['valid_upto'] ?? ''));

            if ($ewayId <= 0) {
                throw new RuntimeException('Invalid E-Way Bill draft.');
            }

            if (!preg_match('/^\d{12}$/', $ewayBillNo)) {
                throw new RuntimeException('Official E-Way Bill number must contain exactly 12 digits.');
            }

            $generatedAt = ewb_parse_datetime($generatedAtInput, 'Generated At', true);
            $validUpto   = ewb_parse_datetime($validUptoInput, 'Valid Upto', true);

            if (strtotime((string)$validUpto) < strtotime((string)$generatedAt)) {
                throw new RuntimeException('Valid Upto must be after Generated At.');
            }

            $sql =
                "UPDATE eway_bills
                 SET eway_bill_no = ?,
                     generated_at = ?,
                     valid_upto = ?,
                     status = 'generated',
                     cancelled_at = NULL,
                     cancellation_reason = NULL
                 WHERE id = ?
                   AND business_id = ?
                   AND status = 'draft'";

            $types = 'sssii';
            $params = [
                $ewayBillNo,
                $generatedAt,
                $validUpto,
                $ewayId,
                $businessId
            ];

            if (!$isAllBranchUser) {
                $sql .= " AND branch_id = ?";
                $types .= 'i';
                $params[] = $branchId;
            }

            $stmt = $conn->prepare($sql);
            ewb_bind($stmt, $types, $params);
            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();

            if ($affected < 1) {
                throw new RuntimeException('Draft was not found or has already been recorded.');
            }

            ewb_flash('success', 'Official E-Way Bill number recorded successfully.');
            ewb_redirect();
        }

        if ($action === 'cancel_eway_bill') {
            $ewayId = (int)($_POST['eway_id'] ?? 0);
            $reason = trim((string)($_POST['cancellation_reason'] ?? ''));
            $reason = mb_substr($reason, 0, 255);

            if ($ewayId <= 0) {
                throw new RuntimeException('Invalid E-Way Bill.');
            }

            if ($reason === '') {
                throw new RuntimeException('Cancellation reason is required.');
            }

            $sql =
                "UPDATE eway_bills
                 SET status = 'cancelled',
                     cancelled_at = NOW(),
                     cancellation_reason = ?
                 WHERE id = ?
                   AND business_id = ?
                   AND status <> 'cancelled'";

            $types = 'sii';
            $params = [$reason, $ewayId, $businessId];

            if (!$isAllBranchUser) {
                $sql .= " AND branch_id = ?";
                $types .= 'i';
                $params[] = $branchId;
            }

            $stmt = $conn->prepare($sql);
            ewb_bind($stmt, $types, $params);
            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();

            if ($affected < 1) {
                throw new RuntimeException('E-Way Bill could not be cancelled.');
            }

            ewb_flash(
                'success',
                'E-Way Bill marked cancelled in this software. Cancel the official E-Way Bill on the GST portal separately.'
            );
            ewb_redirect();
        }
    } catch (mysqli_sql_exception $e) {
        if ((int)$e->getCode() === 1062) {
            ewb_flash(
                'error',
                'This invoice already has an E-Way Bill draft/record, or this official E-Way Bill number already exists.'
            );
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

/* Branch list */
$branchSql =
    "SELECT id, branch_name, branch_code
     FROM branches
     WHERE business_id = ?
       AND status = 'active'";

$branchTypes = 'i';
$branchParams = [$businessId];

if (!$isAllBranchUser) {
    $branchSql .= " AND id = ?";
    $branchTypes .= 'i';
    $branchParams[] = $branchId;
}

$branchSql .= " ORDER BY branch_name";

$branchStmt = $conn->prepare($branchSql);
ewb_bind($branchStmt, $branchTypes, $branchParams);
$branchStmt->execute();
$branches = $branchStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$branchStmt->close();

/* Invoices that do not yet have an E-Way Bill row */
$invoiceSql =
    "SELECT
        si.id,
        si.invoice_no,
        CASE
            WHEN si.invoice_date IS NULL
              OR si.invoice_date = '0000-00-00 00:00:00'
            THEN si.created_at
            ELSE si.invoice_date
        END AS invoice_date,
        si.grand_total,
        c.full_name AS customer_name,
        br.branch_name
     FROM sales_invoices si
     LEFT JOIN customers c
       ON c.id = si.customer_id
      AND c.business_id = si.business_id
     INNER JOIN branches br
       ON br.id = si.branch_id
      AND br.business_id = si.business_id
     LEFT JOIN eway_bills eb
       ON eb.business_id = si.business_id
      AND eb.reference_type = 'sales_invoice'
      AND eb.reference_id = si.id
     WHERE si.business_id = ?
       AND si.sale_status <> 'cancelled'
       AND eb.id IS NULL";

$invoiceTypes = 'i';
$invoiceParams = [$businessId];

if (!$isAllBranchUser) {
    $invoiceSql .= " AND si.branch_id = ?";
    $invoiceTypes .= 'i';
    $invoiceParams[] = $branchId;
}

$invoiceSql .= " ORDER BY si.id DESC";

$invoiceStmt = $conn->prepare($invoiceSql);
ewb_bind($invoiceStmt, $invoiceTypes, $invoiceParams);
$invoiceStmt->execute();
$eligibleInvoices = $invoiceStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$invoiceStmt->close();

/* Summary */
$summarySql =
    "SELECT
        COUNT(*) AS total_count,
        SUM(status = 'draft') AS draft_count,
        SUM(status = 'generated') AS generated_count,
        SUM(status = 'updated') AS updated_count,
        SUM(status = 'cancelled') AS cancelled_count,
        SUM(status = 'expired') AS expired_count,
        COALESCE(
            SUM(
                CASE
                    WHEN status <> 'cancelled'
                    THEN consignment_value
                    ELSE 0
                END
            ),
            0
        ) AS total_value
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
ewb_bind($summaryStmt, $summaryTypes, $summaryParams);
$summaryStmt->execute();
$summary = $summaryStmt->get_result()->fetch_assoc();
$summaryStmt->close();

/* Main list */
$listSql =
    "SELECT
        eb.*,
        br.branch_name,
        si.invoice_no,
        si.invoice_date,
        c.full_name AS customer_name,
        c.mobile AS customer_mobile
     FROM eway_bills eb
     LEFT JOIN branches br
       ON br.id = eb.branch_id
      AND br.business_id = eb.business_id
     LEFT JOIN sales_invoices si
       ON eb.reference_type = 'sales_invoice'
      AND si.id = eb.reference_id
      AND si.business_id = eb.business_id
     LEFT JOIN customers c
       ON c.id = si.customer_id
      AND c.business_id = eb.business_id
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
ewb_bind($listStmt, $listTypes, $listParams);
$listStmt->execute();
$ewayBills = $listStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$listStmt->close();

/* Selected records for normal page forms */
$createMode = ($_GET['action'] ?? '') === 'create';
$recordId   = (int)($_GET['record'] ?? 0);
$viewId     = (int)($_GET['view'] ?? 0);
$cancelId   = (int)($_GET['cancel'] ?? 0);

$selectedRecord = null;

if ($recordId > 0 || $viewId > 0 || $cancelId > 0) {
    $selectedId = $recordId > 0 ? $recordId : ($viewId > 0 ? $viewId : $cancelId);

    $selectedSql =
        "SELECT
            eb.*,
            br.branch_name,
            c.full_name AS customer_name,
            c.mobile AS customer_mobile
         FROM eway_bills eb
         LEFT JOIN branches br
           ON br.id = eb.branch_id
          AND br.business_id = eb.business_id
         LEFT JOIN sales_invoices si
           ON eb.reference_type = 'sales_invoice'
          AND si.id = eb.reference_id
          AND si.business_id = eb.business_id
         LEFT JOIN customers c
           ON c.id = si.customer_id
          AND c.business_id = eb.business_id
         WHERE eb.id = ?
           AND eb.business_id = ?";

    $selectedTypes = 'ii';
    $selectedParams = [$selectedId, $businessId];

    if (!$isAllBranchUser) {
        $selectedSql .= " AND eb.branch_id = ?";
        $selectedTypes .= 'i';
        $selectedParams[] = $branchId;
    }

    $selectedSql .= " LIMIT 1";

    $selectedStmt = $conn->prepare($selectedSql);
    ewb_bind($selectedStmt, $selectedTypes, $selectedParams);
    $selectedStmt->execute();
    $selectedRecord = $selectedStmt->get_result()->fetch_assoc();
    $selectedStmt->close();
}

$flash = $_SESSION['eway_flash'] ?? null;
unset($_SESSION['eway_flash']);

$pageTitle = 'E-Way Bills';
?>
<!doctype html>
<html lang="en">
<head>
    <?php include __DIR__ . '/includes/head.php'; ?>
    <style>
        .summary-card {
            border: 0;
            box-shadow: 0 2px 10px rgba(0, 0, 0, .05);
        }

        .summary-label {
            font-size: 12px;
            color: #74788d;
            text-transform: uppercase;
            letter-spacing: .04em;
        }

        .summary-value {
            font-size: 23px;
            font-weight: 700;
            margin-top: 4px;
        }

        .table td,
        .table th {
            vertical-align: middle;
        }

        .nowrap {
            white-space: nowrap;
        }

        .eway-number {
            font-weight: 700;
            letter-spacing: .4px;
        }

        .small-muted {
            font-size: 12px;
            color: #74788d;
        }

        .action-btn {
            width: 32px;
            height: 32px;
            padding: 5px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .form-section-card {
            border: 1px solid #e9ecef;
            box-shadow: 0 3px 12px rgba(0, 0, 0, .05);
        }


        .eway-create-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.05fr) minmax(340px, .95fr);
            gap: 18px;
            align-items: start;
        }

        .eway-box {
            border: 1px solid #e3e8ef;
            border-radius: 8px;
            background: #fff;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, .04);
        }

        .eway-box-header {
            padding: 12px 16px;
            background: #f5f8fb;
            border-bottom: 1px solid #e3e8ef;
            font-weight: 700;
        }

        .eway-box-body {
            padding: 16px;
        }

        .eway-create-grid .form-group {
            margin-bottom: 12px;
        }

        .eway-create-grid label {
            margin-bottom: 5px;
            font-size: 13px;
            font-weight: 600;
        }

        .eway-create-grid input:not([readonly]),
        .eway-create-grid select,
        .eway-create-grid textarea {
            position: relative !important;
            z-index: 20 !important;
            pointer-events: auto !important;
            opacity: 1 !important;
        }

        .eway-create-grid input[readonly] {
            background: #f5f7fa;
        }

        .eway-items-box {
            margin-top: 18px;
        }

        .eway-form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
        }

        @media (max-width: 991.98px) {
            .eway-create-grid {
                grid-template-columns: 1fr;
            }
        }

        @media print {
            .no-print,
            .vertical-menu,
            .navbar-header,
            .footer {
                display: none !important;
            }

            .main-content {
                margin-left: 0 !important;
            }

            .page-content {
                padding: 0 !important;
            }

            .card {
                border: 0 !important;
                box-shadow: none !important;
            }
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
                    <div class="col-sm-7">
                        <h4 class="mb-1">E-Way Bills</h4>
                        <p class="text-muted mb-0">
                            Create a draft first, generate the official E-Way Bill on the GST portal,
                            and then record the issued 12-digit EBN.
                        </p>
                    </div>

                    <div class="col-sm-5 text-sm-right mt-2 mt-sm-0">
                        <button type="button" class="btn btn-light" onclick="window.print()">
                            <i class="dripicons-print mr-1"></i> Print
                        </button>

                        <a href="eway-bills.php?action=create" class="btn btn-primary">
                            <i class="dripicons-plus mr-1"></i> Create E-Way Bill Draft
                        </a>
                    </div>
                </div>

                <?php if ($flash): ?>
                    <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?> alert-dismissible fade show no-print">
                        <?= ewb_h($flash['message']) ?>
                        <button type="button" class="close" data-dismiss="alert">
                            <span>&times;</span>
                        </button>
                    </div>
                <?php endif; ?>

                <?php if ($createMode): ?>
                    <div class="card form-section-card no-print">
                        <div class="card-header bg-primary text-white d-flex align-items-center justify-content-between">
                            <h5 class="mb-0 text-white">
                                <i class="dripicons-document-new mr-2"></i>
                                Create E-Way Bill Draft
                            </h5>

                            <a href="eway-bills.php" class="btn btn-sm btn-light">
                                Close
                            </a>
                        </div>

                        <form method="post" id="draftForm">
                            <input type="hidden" name="csrf_token" value="<?= ewb_h($csrfToken) ?>">
                            <input type="hidden" name="action" value="create_draft">

                            <div class="card-body">
                                <div class="alert alert-info py-2">
                                    Select an invoice. Supplier, branch, customer, GSTIN, address and item details will load automatically.
                                    You can correct the editable fields before saving.
                                </div>

                                <div class="eway-create-grid">
                                    <div class="eway-box">
                                        <div class="eway-box-header">
                                            Invoice, Supplier & Recipient
                                        </div>

                                        <div class="eway-box-body">
                                            <div class="form-group">
                                                <label>
                                                    Sales Invoice <span class="text-danger">*</span>
                                                </label>

                                                <select name="invoice_id" id="invoice_id" class="form-control" required>
                                                    <option value="">Select sales invoice</option>

                                                    <?php foreach ($eligibleInvoices as $invoice): ?>
                                                        <option
                                                            value="<?= (int)$invoice['id'] ?>"
                                                            data-value="<?= ewb_h($invoice['grand_total']) ?>"
                                                        >
                                                            <?= ewb_h($invoice['invoice_no']) ?>
                                                            —
                                                            <?= ewb_h($invoice['customer_name'] ?: 'Customer unavailable') ?>
                                                            —
                                                            ₹<?= number_format((float)$invoice['grand_total'], 2) ?>
                                                            —
                                                            <?= ewb_h($invoice['branch_name']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>

                                                <?php if (!$eligibleInvoices): ?>
                                                    <small class="text-danger">
                                                        No unlinked sales invoice is available.
                                                    </small>
                                                <?php endif; ?>
                                            </div>

                                            <div id="invoiceLoading" class="alert alert-light border d-none py-2">
                                                Loading invoice details...
                                            </div>

                                            <div class="form-row">
                                                <div class="form-group col-md-6">
                                                    <label>Document</label>
                                                    <input type="text" id="preview_document" class="form-control" readonly>
                                                </div>

                                                <div class="form-group col-md-6">
                                                    <label>Consignment Value</label>
                                                    <input type="text" id="display_consignment" class="form-control font-weight-bold" value="₹0.00" readonly>
                                                </div>

                                                <div class="form-group col-md-6">
                                                    <label>Supplier / Branch</label>
                                                    <input type="text" name="supplier_name" id="preview_supplier" class="form-control">
                                                </div>

                                                <div class="form-group col-md-6">
                                                    <label>From GSTIN</label>
                                                    <input type="text" name="from_gstin" id="preview_from_gstin" class="form-control text-uppercase" maxlength="15">
                                                </div>

                                                <div class="form-group col-md-6">
                                                    <label>Customer</label>
                                                    <input type="text" name="recipient_name" id="preview_customer" class="form-control">
                                                </div>

                                                <div class="form-group col-md-6">
                                                    <label>To GSTIN</label>
                                                    <input type="text" name="to_gstin" id="preview_to_gstin" class="form-control text-uppercase" maxlength="15">
                                                </div>

                                                <div class="form-group col-12">
                                                    <label>Dispatch Address</label>
                                                    <textarea name="dispatch_address" id="preview_from_address" class="form-control" rows="2"></textarea>
                                                </div>

                                                <div class="form-group col-12 mb-0">
                                                    <label>Delivery Address</label>
                                                    <textarea name="delivery_address" id="preview_to_address" class="form-control" rows="2"></textarea>
                                                </div>

                                                <input type="hidden" id="preview_total">
                                            </div>
                                        </div>
                                    </div>

                                    <div class="eway-box">
                                        <div class="eway-box-header">
                                            Transport Details
                                        </div>

                                        <div class="eway-box-body">
                                            <div class="form-row">
                                                <div class="form-group col-md-6">
                                                    <label>
                                                        Transport Mode <span class="text-danger">*</span>
                                                    </label>

                                                    <select name="transport_mode" id="transport_mode" class="form-control" required>
                                                        <option value="road">Road</option>
                                                        <option value="rail">Rail</option>
                                                        <option value="air">Air</option>
                                                        <option value="ship">Ship</option>
                                                    </select>
                                                </div>

                                                <div class="form-group col-md-6">
                                                    <label>
                                                        Distance (KM) <span class="text-danger">*</span>
                                                    </label>

                                                    <input type="number" name="approximate_distance" class="form-control" min="1" required>
                                                </div>

                                                <div class="form-group col-md-6" id="vehicle_group">
                                                    <label>Vehicle Number</label>
                                                    <input type="text" name="vehicle_number" id="vehicle_number" class="form-control text-uppercase" placeholder="TN29AB1234">
                                                </div>

                                                <div class="form-group col-md-6">
                                                    <label>Transporter ID / GSTIN</label>
                                                    <input type="text" name="transporter_id" class="form-control text-uppercase">
                                                </div>

                                                <div class="form-group col-md-6">
                                                    <label>Transporter Name</label>
                                                    <input type="text" name="transporter_name" class="form-control">
                                                </div>

                                                <div class="form-group col-md-6">
                                                    <label>Transport Document No.</label>
                                                    <input type="text" name="transport_document_no" class="form-control">
                                                </div>

                                                <div class="form-group col-md-6">
                                                    <label>Transport Document Date</label>
                                                    <input type="date" name="transport_document_date" class="form-control">
                                                </div>

                                                <div class="form-group col-md-6">
                                                    <label>Transaction</label>
                                                    <input type="text" class="form-control" value="Outward - Supply" readonly>
                                                </div>

                                                <div class="form-group col-12 mb-0">
                                                    <label>Notes</label>
                                                    <textarea name="notes" class="form-control" rows="3"></textarea>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="eway-box eway-items-box">
                                    <div class="eway-box-header">
                                        Invoice Item Details
                                    </div>

                                    <div class="eway-box-body">
                                        <div class="table-responsive">
                                            <table class="table table-sm table-bordered mb-0">
                                                <thead class="thead-light">
                                                    <tr>
                                                        <th>#</th>
                                                        <th>Description</th>
                                                        <th>HSN</th>
                                                        <th>Qty</th>
                                                        <th>Unit</th>
                                                        <th class="text-right">Taxable</th>
                                                        <th>GST</th>
                                                        <th class="text-right">Line Total</th>
                                                    </tr>
                                                </thead>

                                                <tbody id="preview_items">
                                                    <tr>
                                                        <td colspan="8" class="text-center text-muted py-3">
                                                            Select an invoice to load item details.
                                                        </td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>

                                        <div class="row mt-3">
                                            <div class="col-md-3 col-6">
                                                <div class="small-muted">Subtotal</div>
                                                <strong id="preview_subtotal">₹0.00</strong>
                                            </div>

                                            <div class="col-md-3 col-6">
                                                <div class="small-muted">CGST</div>
                                                <strong id="preview_cgst">₹0.00</strong>
                                            </div>

                                            <div class="col-md-3 col-6">
                                                <div class="small-muted">SGST</div>
                                                <strong id="preview_sgst">₹0.00</strong>
                                            </div>

                                            <div class="col-md-3 col-6">
                                                <div class="small-muted">IGST / Cess</div>
                                                <strong id="preview_igst_cess">₹0.00</strong>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="card-footer">
                                <div class="eway-form-actions">
                                    <a href="eway-bills.php" class="btn btn-light">
                                        Cancel
                                    </a>

                                    <button type="submit" class="btn btn-primary" <?= !$eligibleInvoices ? 'disabled' : '' ?>>
                                        Save Draft
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                <?php endif; ?>

                <?php if ($recordId > 0 && $selectedRecord): ?>
                    <div class="card form-section-card no-print">
                        <div class="card-header bg-success text-white">
                            <h5 class="mb-0 text-white">
                                <i class="dripicons-checkmark mr-2"></i>
                                Record Official E-Way Bill Number
                            </h5>
                        </div>

                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= ewb_h($csrfToken) ?>">
                            <input type="hidden" name="action" value="record_generated">
                            <input type="hidden" name="eway_id" value="<?= (int)$selectedRecord['id'] ?>">

                            <div class="card-body">
                                <?php if ($selectedRecord['status'] !== 'draft'): ?>
                                    <div class="alert alert-warning">
                                        This record is no longer in Draft status.
                                    </div>
                                <?php else: ?>
                                    <div class="alert alert-light border">
                                        <strong>Draft:</strong>
                                        <?= ewb_h($selectedRecord['draft_no']) ?><br>

                                        <strong>Invoice:</strong>
                                        <?= ewb_h($selectedRecord['document_number']) ?><br>

                                        <strong>Customer:</strong>
                                        <?= ewb_h($selectedRecord['customer_name'] ?: '—') ?><br>

                                        <strong>Consignment:</strong>
                                        ₹<?= number_format((float)$selectedRecord['consignment_value'], 2) ?>
                                    </div>

                                    <div class="form-row">
                                        <div class="form-group col-md-4">
                                            <label>
                                                Official 12-digit EBN
                                                <span class="text-danger">*</span>
                                            </label>

                                            <input
                                                type="text"
                                                name="eway_bill_no"
                                                class="form-control"
                                                maxlength="12"
                                                pattern="[0-9]{12}"
                                                placeholder="Enter official E-Way Bill number"
                                                required
                                            >
                                        </div>

                                        <div class="form-group col-md-4">
                                            <label>
                                                Generated At
                                                <span class="text-danger">*</span>
                                            </label>

                                            <input
                                                type="datetime-local"
                                                name="generated_at"
                                                class="form-control"
                                                value="<?= date('Y-m-d\TH:i') ?>"
                                                required
                                            >
                                        </div>

                                        <div class="form-group col-md-4">
                                            <label>
                                                Valid Upto
                                                <span class="text-danger">*</span>
                                            </label>

                                            <input
                                                type="datetime-local"
                                                name="valid_upto"
                                                class="form-control"
                                                required
                                            >
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="card-footer text-right">
                                <a href="eway-bills.php" class="btn btn-light">
                                    Close
                                </a>

                                <?php if ($selectedRecord['status'] === 'draft'): ?>
                                    <button type="submit" class="btn btn-success">
                                        Save Official EBN
                                    </button>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                <?php endif; ?>

                <?php if ($viewId > 0 && $selectedRecord): ?>
                    <div class="card form-section-card no-print">
                        <div class="card-header bg-info text-white">
                            <h5 class="mb-0 text-white">
                                <i class="dripicons-preview mr-2"></i>
                                E-Way Bill Details
                            </h5>
                        </div>

                        <div class="card-body">
                            <div class="row">
                                <?php
                                $details = [
                                    'Draft Number' => $selectedRecord['draft_no'],
                                    'Official EBN' => $selectedRecord['eway_bill_no'],
                                    'Status' => ucfirst((string)$selectedRecord['status']),
                                    'Invoice Number' => $selectedRecord['document_number'],
                                    'Document Date' => $selectedRecord['document_date'],
                                    'Customer' => $selectedRecord['customer_name'],
                                    'Customer Mobile' => $selectedRecord['customer_mobile'],
                                    'Branch' => $selectedRecord['branch_name'],
                                    'Consignment Value' => '₹' . number_format((float)$selectedRecord['consignment_value'], 2),
                                    'Transport Mode' => ucfirst((string)$selectedRecord['transport_mode']),
                                    'Vehicle Number' => $selectedRecord['vehicle_number'],
                                    'Transporter' => $selectedRecord['transporter_name'],
                                    'Transporter ID' => $selectedRecord['transporter_id'],
                                    'Transport Document No.' => $selectedRecord['transport_document_no'],
                                    'Transport Document Date' => $selectedRecord['transport_document_date'],
                                    'Distance' => $selectedRecord['approximate_distance']
                                        ? $selectedRecord['approximate_distance'] . ' KM'
                                        : null,
                                    'Generated At' => $selectedRecord['generated_at'],
                                    'Valid Upto' => $selectedRecord['valid_upto'],
                                    'Cancellation Reason' => $selectedRecord['cancellation_reason'],
                                    'Notes' => $selectedRecord['notes'],
                                ];
                                ?>

                                <?php foreach ($details as $label => $value): ?>
                                    <div class="col-md-6 mb-3">
                                        <div class="small-muted">
                                            <?= ewb_h($label) ?>
                                        </div>

                                        <div class="font-weight-bold">
                                            <?= ewb_h($value ?: '—') ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="card-footer text-right">
                            <a href="eway-bills.php" class="btn btn-light">
                                Close
                            </a>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($cancelId > 0 && $selectedRecord): ?>
                    <div class="card form-section-card no-print">
                        <div class="card-header bg-danger text-white">
                            <h5 class="mb-0 text-white">
                                <i class="dripicons-cross mr-2"></i>
                                Cancel E-Way Bill
                            </h5>
                        </div>

                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= ewb_h($csrfToken) ?>">
                            <input type="hidden" name="action" value="cancel_eway_bill">
                            <input type="hidden" name="eway_id" value="<?= (int)$selectedRecord['id'] ?>">

                            <div class="card-body">
                                <div class="alert alert-warning">
                                    Cancelling here updates only this software.
                                    Cancel the official E-Way Bill separately on the GST portal.
                                </div>

                                <p>
                                    Cancel:
                                    <strong>
                                        <?= ewb_h($selectedRecord['eway_bill_no'] ?: $selectedRecord['draft_no']) ?>
                                    </strong>
                                </p>

                                <div class="form-group mb-0">
                                    <label>
                                        Cancellation Reason
                                        <span class="text-danger">*</span>
                                    </label>

                                    <textarea
                                        name="cancellation_reason"
                                        class="form-control"
                                        rows="3"
                                        required
                                    ></textarea>
                                </div>
                            </div>

                            <div class="card-footer text-right">
                                <a href="eway-bills.php" class="btn btn-light">
                                    Close
                                </a>

                                <button type="submit" class="btn btn-danger">
                                    Confirm Cancel
                                </button>
                            </div>
                        </form>
                    </div>
                <?php endif; ?>

                <div class="row">
                    <div class="col-xl-3 col-md-6">
                        <div class="card summary-card">
                            <div class="card-body">
                                <div class="summary-label">Total Records</div>
                                <div class="summary-value">
                                    <?= number_format((int)($summary['total_count'] ?? 0)) ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6">
                        <div class="card summary-card">
                            <div class="card-body">
                                <div class="summary-label">Drafts</div>
                                <div class="summary-value text-secondary">
                                    <?= number_format((int)($summary['draft_count'] ?? 0)) ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6">
                        <div class="card summary-card">
                            <div class="card-body">
                                <div class="summary-label">Generated / Updated</div>
                                <div class="summary-value text-success">
                                    <?= number_format(
                                        (int)($summary['generated_count'] ?? 0) +
                                        (int)($summary['updated_count'] ?? 0)
                                    ) ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6">
                        <div class="card summary-card">
                            <div class="card-body">
                                <div class="summary-label">Consignment Value</div>
                                <div class="summary-value">
                                    ₹<?= number_format((float)($summary['total_value'] ?? 0), 2) ?>
                                </div>
                            </div>
                        </div>
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
                                            <option
                                                value="<?= (int)$branch['id'] ?>"
                                                <?= $branchFilter === (int)$branch['id'] ? 'selected' : '' ?>
                                            >
                                                <?= ewb_h($branch['branch_name']) ?>
                                                (<?= ewb_h($branch['branch_code']) ?>)
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
                                        <option
                                            value="<?= ewb_h($status) ?>"
                                            <?= $statusFilter === $status ? 'selected' : '' ?>
                                        >
                                            <?= ewb_h(ucfirst($status)) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-lg-2 col-md-6 mb-2">
                                <label>From Date</label>

                                <input
                                    type="date"
                                    name="date_from"
                                    class="form-control"
                                    value="<?= ewb_h($dateFrom) ?>"
                                >
                            </div>

                            <div class="col-lg-2 col-md-6 mb-2">
                                <label>To Date</label>

                                <input
                                    type="date"
                                    name="date_to"
                                    class="form-control"
                                    value="<?= ewb_h($dateTo) ?>"
                                >
                            </div>

                            <div class="col-lg-3 mb-2">
                                <button class="btn btn-primary" type="submit">
                                    <i class="dripicons-search mr-1"></i>
                                    Filter
                                </button>

                                <a href="eway-bills.php" class="btn btn-light">
                                    Reset
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header bg-primary text-white d-flex align-items-center justify-content-between">
                        <h5 class="mb-0 text-white">
                            <i class="dripicons-document mr-2"></i>
                            E-Way Bill Register
                        </h5>

                        <span class="badge badge-light">
                            <?= count($ewayBills) ?> record(s)
                        </span>
                    </div>

                    <div class="card-body">
                        <div class="table-responsive">
                            <table id="ewayTable" class="table table-bordered table-striped mb-0">
                                <thead class="thead-light">
                                    <tr>
                                        <th>#</th>
                                        <th>Draft / EBN</th>
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
                                        <tr>
                                            <td colspan="10" class="text-center text-muted py-4">
                                                No E-Way Bill records found.
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($ewayBills as $index => $row): ?>
                                            <tr>
                                                <td><?= $index + 1 ?></td>

                                                <td>
                                                    <?php if ($row['eway_bill_no']): ?>
                                                        <div class="eway-number">
                                                            <?= ewb_h($row['eway_bill_no']) ?>
                                                        </div>

                                                        <div class="small-muted">
                                                            Draft: <?= ewb_h($row['draft_no'] ?: '—') ?>
                                                        </div>
                                                    <?php else: ?>
                                                        <div class="eway-number">
                                                            <?= ewb_h($row['draft_no']) ?>
                                                        </div>

                                                        <div class="small-muted">
                                                            Official EBN not recorded
                                                        </div>
                                                    <?php endif; ?>
                                                </td>

                                                <td>
                                                    <div class="font-weight-bold">
                                                        <?= ewb_h($row['document_number'] ?: $row['invoice_no']) ?>
                                                    </div>

                                                    <div class="small-muted">
                                                        <?= ewb_h($row['customer_name'] ?: '—') ?>
                                                    </div>
                                                </td>

                                                <td>
                                                    <?= ewb_h($row['branch_name'] ?: '—') ?>
                                                </td>

                                                <td class="nowrap">
                                                    <?= ($row['document_date'] && $row['document_date'] !== '0000-00-00')
                                                        ? date('d-m-Y', strtotime($row['document_date']))
                                                        : '—' ?>
                                                </td>

                                                <td>
                                                    <div>
                                                        <?= ewb_h(ucfirst((string)$row['transport_mode'])) ?>
                                                    </div>

                                                    <div class="small-muted">
                                                        <?= ewb_h(
                                                            $row['vehicle_number']
                                                            ?: $row['transporter_name']
                                                            ?: '—'
                                                        ) ?>
                                                    </div>
                                                </td>

                                                <td class="text-right nowrap">
                                                    ₹<?= number_format((float)$row['consignment_value'], 2) ?>
                                                </td>

                                                <td class="nowrap">
                                                    <?php if ($row['valid_upto']): ?>
                                                        <?= date('d-m-Y', strtotime($row['valid_upto'])) ?><br>
                                                        <span class="small-muted">
                                                            <?= date('h:i A', strtotime($row['valid_upto'])) ?>
                                                        </span>
                                                    <?php else: ?>
                                                        —
                                                    <?php endif; ?>
                                                </td>

                                                <td>
                                                    <?= ewb_status_badge((string)$row['status']) ?>
                                                </td>

                                                <td class="no-print text-center nowrap">
                                                    <a
                                                        href="eway-bills.php?view=<?= (int)$row['id'] ?>"
                                                        class="btn btn-info btn-sm action-btn"
                                                        title="View"
                                                    >
                                                        <i class="dripicons-preview"></i>
                                                    </a>

                                                    <?php if ($row['status'] === 'draft'): ?>
                                                        <a
                                                            href="eway-bills.php?record=<?= (int)$row['id'] ?>"
                                                            class="btn btn-success btn-sm action-btn"
                                                            title="Record Official EBN"
                                                        >
                                                            <i class="dripicons-checkmark"></i>
                                                        </a>
                                                    <?php endif; ?>

                                                    <?php if ($row['status'] !== 'cancelled'): ?>
                                                        <a
                                                            href="eway-bills.php?cancel=<?= (int)$row['id'] ?>"
                                                            class="btn btn-danger btn-sm action-btn"
                                                            title="Cancel"
                                                        >
                                                            <i class="dripicons-cross"></i>
                                                        </a>
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
                    <strong>Important:</strong>
                    This software creates and tracks the E-Way Bill draft.
                    The official 12-digit E-Way Bill number must be generated through the GST E-Way Bill portal
                    or an authorised API integration.
                </div>

            </div>
        </div>

        <?php include __DIR__ . '/includes/footer.php'; ?>
    </div>
</div>

<?php include __DIR__ . '/includes/rightbar.php'; ?>
<?php include __DIR__ . '/includes/scripts.php'; ?>

<script>
(function () {
    'use strict';

    function byId(id) {
        return document.getElementById(id);
    }

    function money(value) {
        var amount = parseFloat(value || 0);

        return '₹' + amount.toLocaleString('en-IN', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function escapeHtml(value) {
        var div = document.createElement('div');
        div.textContent = value == null || value === '' ? '—' : String(value);
        return div.innerHTML;
    }

    function setText(id, value) {
        var element = byId(id);

        if (!element) {
            return;
        }

        var finalValue = value == null || value === '' ? '' : value;

        if ('value' in element) {
            element.value = finalValue;
        } else {
            element.textContent = finalValue || '—';
        }
    }

    var invoiceSelect = byId('invoice_id');
    var displayValue = byId('display_consignment');
    var detailsPanel = byId('invoiceDetailsPanel');
    var loadingBox = byId('invoiceLoading');

    function clearInvoicePreview() {
        if (displayValue) {
            displayValue.value = '₹0.00';
        }

        if (detailsPanel) {
            detailsPanel.classList.add('d-none');
        }
    }

    function loadInvoiceDetails(invoiceId) {
        clearInvoicePreview();

        if (!invoiceId) {
            return;
        }

        if (loadingBox) {
            loadingBox.classList.remove('d-none');
        }

        fetch(
            'eway-bills.php?ajax=invoice_details&invoice_id=' +
            encodeURIComponent(invoiceId),
            {
                credentials: 'same-origin',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            }
        )
        .then(function (response) {
            return response.json().then(function (data) {
                if (!response.ok || !data.success) {
                    throw new Error(data.message || 'Unable to load invoice.');
                }

                return data;
            });
        })
        .then(function (data) {
            var invoice = data.invoice || {};
            var items = data.items || [];

            if (displayValue) {
                displayValue.value = money(invoice.grand_total);
            }

            setText(
                'preview_document',
                (invoice.invoice_no || '—') + ' / ' + (invoice.invoice_date || '—')
            );

            setText(
                'preview_supplier',
                (invoice.supplier_name || '—') +
                (invoice.branch_name ? ' — ' + invoice.branch_name : '')
            );

            setText('preview_from_address', invoice.dispatch_address || '—');
            setText('preview_from_gstin', invoice.from_gstin || 'URP');

            setText(
                'preview_customer',
                (invoice.customer_name || '—') +
                (invoice.customer_mobile ? ' — ' + invoice.customer_mobile : '')
            );

            setText('preview_to_address', invoice.delivery_address || '—');
            setText('preview_to_gstin', invoice.to_gstin || 'URP');
            setText('preview_total', money(invoice.grand_total));
            setText('preview_subtotal', money(invoice.subtotal));
            setText('preview_cgst', money(invoice.cgst_amount));
            setText('preview_sgst', money(invoice.sgst_amount));
            setText(
                'preview_igst_cess',
                money(
                    parseFloat(invoice.igst_amount || 0) +
                    parseFloat(invoice.cess_amount || 0)
                )
            );

            var tbody = byId('preview_items');

            if (tbody) {
                if (!items.length) {
                    tbody.innerHTML =
                        '<tr><td colspan="8" class="text-center text-muted">' +
                        'No invoice items found.' +
                        '</td></tr>';
                } else {
                    tbody.innerHTML = items.map(function (item, index) {
                        var gstRate =
                            parseFloat(item.igst_percent || 0) > 0
                            ? parseFloat(item.igst_percent || 0)
                            : parseFloat(item.cgst_percent || 0) +
                              parseFloat(item.sgst_percent || 0);

                        var description = item.description || '';

                        if (item.model_name) {
                            description +=
                                ' (' +
                                item.model_name +
                                (item.variant_name ? ' - ' + item.variant_name : '') +
                                ')';
                        }

                        if (item.chassis_no) {
                            description += ' / Chassis: ' + item.chassis_no;
                        }

                        return '<tr>' +
                            '<td>' + (index + 1) + '</td>' +
                            '<td>' + escapeHtml(description) + '</td>' +
                            '<td>' + escapeHtml(item.hsn_code || '—') + '</td>' +
                            '<td>' + escapeHtml(item.qty) + '</td>' +
                            '<td>' + escapeHtml(item.unit_name || 'NOS') + '</td>' +
                            '<td class="text-right">' + money(item.taxable_value) + '</td>' +
                            '<td>' + escapeHtml(gstRate.toFixed(2) + '%') + '</td>' +
                            '<td class="text-right">' + money(item.line_total) + '</td>' +
                            '</tr>';
                    }).join('');
                }
            }

            if (detailsPanel) {
                detailsPanel.classList.remove('d-none');
            }
        })
        .catch(function (error) {
            alert(error.message || 'Unable to load invoice details.');
            clearInvoicePreview();
        })
        .finally(function () {
            if (loadingBox) {
                loadingBox.classList.add('d-none');
            }
        });
    }

    if (invoiceSelect) {
        invoiceSelect.addEventListener('change', function () {
            loadInvoiceDetails(invoiceSelect.value);
        });
    }

    var transportMode = byId('transport_mode');
    var vehicleNumber = byId('vehicle_number');
    var vehicleGroup = byId('vehicle_group');

    function updateVehicleField() {
        if (!transportMode || !vehicleNumber || !vehicleGroup) {
            return;
        }

        var isRoad = transportMode.value === 'road';
        vehicleNumber.required = isRoad;
        vehicleGroup.style.display = isRoad ? '' : 'none';

        if (!isRoad) {
            vehicleNumber.value = '';
        }
    }

    if (transportMode) {
        transportMode.addEventListener('change', updateVehicleField);
        updateVehicleField();
    }

    var draftForm = byId('draftForm');

    if (draftForm) {
        draftForm.addEventListener('submit', function (event) {
            if (!invoiceSelect || !invoiceSelect.value) {
                event.preventDefault();
                alert('Please select a sales invoice.');
                return;
            }

            if (
                transportMode &&
                transportMode.value === 'road' &&
                (!vehicleNumber || vehicleNumber.value.trim() === '')
            ) {
                event.preventDefault();
                alert('Vehicle number is required for road transport.');
                return;
            }

            var submitButton = draftForm.querySelector('button[type="submit"]');

            if (submitButton) {
                submitButton.disabled = true;
                submitButton.textContent = 'Saving Draft...';
            }
        });
    }

    document.querySelectorAll(
        '#draftForm input:not([type="hidden"]):not([readonly]), #draftForm select, #draftForm textarea'
    ).forEach(function (field) {
        field.disabled = false;
        field.removeAttribute('readonly');
        field.style.pointerEvents = 'auto';
    });

    if (
        window.jQuery &&
        window.jQuery.fn.DataTable &&
        document.querySelector('#ewayTable tbody td:not([colspan])')
    ) {
        if (!window.jQuery.fn.DataTable.isDataTable('#ewayTable')) {
            window.jQuery('#ewayTable').DataTable({
                pageLength: 25,
                order: [[0, 'asc']],
                columnDefs: [
                    {
                        orderable: false,
                        targets: -1
                    }
                ]
            });
        }
    }
})();
</script>

</body>
</html>
<?php ob_end_flush(); ?>
