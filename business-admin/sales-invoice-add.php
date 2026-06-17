<?php
session_start();
date_default_timezone_set('Asia/Kolkata');

require_once 'includes/config.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not available.');
}
$conn->set_charset('utf8mb4');

$businessUserId = isset($_SESSION['business_user_id']) ? (int)$_SESSION['business_user_id'] : 0;
if ($businessUserId <= 0 && isset($_SESSION['user_id'])) {
    $businessUserId = (int)$_SESSION['user_id'];
}

$businessId = isset($_SESSION['business_id']) ? (int)$_SESSION['business_id'] : 0;
$currentBranchId = isset($_SESSION['branch_id']) ? (int)$_SESSION['branch_id'] : 0;

if ($businessUserId <= 0 || $businessId <= 0) {
    header('Location: login.php');
    exit;
}

function h($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function tableExists(mysqli $conn, string $table): bool
{
    $stmt = $conn->prepare("
        SELECT 1 FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = ?
        LIMIT 1
    ");
    if (!$stmt) return false;

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
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = ?
        AND COLUMN_NAME = ?
        LIMIT 1
    ");
    if (!$stmt) return false;

    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $res = $stmt->get_result();
    $ok = $res && $res->num_rows > 0;
    $stmt->close();

    return $ok;
}


function ensureVehicleOptionalUniqueColumns(mysqli $conn): void
{
    if (!tableExists($conn, 'vehicle_stock')) {
        return;
    }

    /*
       Chassis / engine / battery / charger numbers are optional in this page.
       Unique indexes allow many NULL values, but they do NOT allow many empty strings.
       So optional blank values must be saved as NULL. Chassis was NOT NULL in the
       old database, therefore we make it nullable safely here.
    */
    if (columnExists($conn, 'vehicle_stock', 'chassis_no')) {
        $conn->query("ALTER TABLE vehicle_stock MODIFY chassis_no VARCHAR(100) NULL DEFAULT NULL");
    }

    foreach (['engine_no', 'motor_no', 'battery_no', 'charger_no'] as $optionalColumn) {
        if (columnExists($conn, 'vehicle_stock', $optionalColumn)) {
            $conn->query("ALTER TABLE vehicle_stock MODIFY {$optionalColumn} VARCHAR(100) NULL DEFAULT NULL");
        }
    }
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

function moneyText($amount): string
{
    return '₹' . number_format((float)$amount, 2);
}

function generateInvoiceNo(mysqli $conn, int $businessId, int $branchId): string
{
    // Get the business prefix from settings
    $prefix = 'INV';
    if (tableExists($conn, 'business_settings') && columnExists($conn, 'business_settings', 'invoice_prefix')) {
        $stmt = $conn->prepare("SELECT invoice_prefix FROM business_settings WHERE business_id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $businessId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!empty($row['invoice_prefix'])) {
                $prefix = trim($row['invoice_prefix']);
            }
        }
    }
    
    // Get the maximum invoice number for this business
    $stmt = $conn->prepare("
        SELECT invoice_no 
        FROM sales_invoices 
        WHERE business_id = ? 
        ORDER BY id DESC 
        LIMIT 1
    ");
    
    if ($stmt) {
        $stmt->bind_param('i', $businessId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row && !empty($row['invoice_no'])) {
            // Extract the numeric part from the invoice number
            // Pattern: INV00000001 -> extract 00000001
            $lastInvoice = $row['invoice_no'];
            if (preg_match('/(\d+)$/', $lastInvoice, $matches)) {
                $lastNumber = (int)$matches[1];
                $nextNumber = $lastNumber + 1;
                // Format as 8 digits with leading zeros
                $nextNumberFormatted = str_pad((string)$nextNumber, 8, '0', STR_PAD_LEFT);
                return $prefix . $nextNumberFormatted;
            }
        }
    }
    
    // First invoice - start from 1 with 8 digits (INV00000001)
    $firstNumber = str_pad('1', 8, '0', STR_PAD_LEFT);
    return $prefix . $firstNumber;
}


function getAllowedInvoiceTypeByBranch(int $branchId): string
{
    if ($branchId === 1) {
        return 'vehicle_sale';
    }

    if ($branchId === 2) {
        return 'product_sale';
    }

    return 'product_sale';
}

function getAllowedItemTypeByBranch(int $branchId): string
{
    if ($branchId === 1) {
        return 'vehicle';
    }

    if ($branchId === 2) {
        return 'product';
    }

    return 'product';
}

function getOrCreateProductCategory(mysqli $conn, string $categoryName): int
{
    $categoryName = trim($categoryName);
    if ($categoryName === '') return 0;

    if (!tableExists($conn, 'product_categories')) return 0;

    $stmt = $conn->prepare("
        SELECT id 
        FROM product_categories 
        WHERE category_name = ?
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param('s', $categoryName);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) return (int)$row['id'];
    }

    $stmt = $conn->prepare("
        INSERT INTO product_categories (category_name)
        VALUES (?)
    ");
    if ($stmt) {
        $stmt->bind_param('s', $categoryName);
        $stmt->execute();
        $id = (int)$conn->insert_id;
        $stmt->close();
        return $id;
    }

    return 0;
}

function getOrCreateManualProduct(mysqli $conn, int $businessId, array $item): int
{
    if (!tableExists($conn, 'products')) return 0;

    $productName = trim($item['product_name'] ?: $item['description']);
    if ($productName === '') return 0;

    $sku = trim($item['sku'] ?? '');
    $categoryId = getOrCreateProductCategory($conn, (string)($item['category'] ?? ''));

    if ($sku !== '') {
        $stmt = $conn->prepare("
            SELECT id FROM products
            WHERE business_id = ? AND (product_code = ? OR item_code = ?)
            LIMIT 1
        ");
        if ($stmt) {
            $stmt->bind_param('iss', $businessId, $sku, $sku);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) return (int)$row['id'];
        }
    }

    $productCode = $sku !== '' ? $sku : ('PRD' . date('ymdHis') . rand(10, 99));
    $itemCode = $sku;
    $brandName = trim($item['product_brand'] ?? '');
    $hsnCode = trim($item['hsn_code'] ?? '');
    $purchasePrice = (float)($item['purchase_price'] ?? 0);
    $sellingPrice = (float)($item['unit_price'] ?? 0);
    $gstPercent = (float)($item['gst_percent'] ?? 0);
    $stockQty = 0.00;
    $unit = 'pcs';
    $productType = 'other';
    $status = 1;

    $columns = [
        'business_id',
        'category_id',
        'product_name',
        'product_code',
        'item_code',
        'brand_name',
        'hsn_code',
        'product_type',
        'unit',
        'purchase_price',
        'selling_price',
        'mrp',
        'gst_percent',
        'stock_qty',
        'status',
        'created_at'
    ];

    $valuesSql = "?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()";

    $stmt = $conn->prepare("
        INSERT INTO products (" . implode(',', $columns) . ")
        VALUES ({$valuesSql})
    ");

    if (!$stmt) {
        return 0;
    }

    $stmt->bind_param(
        'iisssssssdddddi',
        $businessId,
        $categoryId,
        $productName,
        $productCode,
        $itemCode,
        $brandName,
        $hsnCode,
        $productType,
        $unit,
        $purchasePrice,
        $sellingPrice,
        $sellingPrice,
        $gstPercent,
        $stockQty,
        $status
    );

    if (!$stmt->execute()) {
        $stmt->close();
        return 0;
    }

    $id = (int)$conn->insert_id;
    $stmt->close();

    return $id;
}

function getOrCreateVehicleBrand(mysqli $conn, string $brandName): int
{
    $brandName = trim($brandName);
    if ($brandName === '') return 0;

    $stmt = $conn->prepare("SELECT id FROM vehicle_brands WHERE brand_name = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('s', $brandName);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) return (int)$row['id'];
    }

    $stmt = $conn->prepare("INSERT INTO vehicle_brands (brand_name, status, created_at) VALUES (?, 1, NOW())");
    if (!$stmt) {
        throw new Exception('Vehicle brand insert failed: ' . $conn->error);
    }

    $stmt->bind_param('s', $brandName);
    if (!$stmt->execute()) {
        throw new Exception('Vehicle brand save failed: ' . $stmt->error);
    }

    $id = (int)$conn->insert_id;
    $stmt->close();

    return $id;
}

function getOrCreateVehicleCategory(mysqli $conn): int
{
    $categoryName = 'ECO';

    $stmt = $conn->prepare("SELECT id FROM vehicle_categories WHERE category_name = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('s', $categoryName);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) return (int)$row['id'];
    }

    $stmt = $conn->prepare("INSERT INTO vehicle_categories (category_name) VALUES (?)");
    if (!$stmt) {
        throw new Exception('Vehicle category insert failed: ' . $conn->error);
    }

    $stmt->bind_param('s', $categoryName);
    if (!$stmt->execute()) {
        throw new Exception('Vehicle category save failed: ' . $stmt->error);
    }

    $id = (int)$conn->insert_id;
    $stmt->close();

    return $id;
}

function getOrCreateVehicleModel(mysqli $conn, int $businessId, string $brandName, string $modelName, float $exShowroom, float $gstPercent, string $vehicleType = 'electric'): int
{
    $modelName = trim($modelName);
    if ($modelName === '') $modelName = 'Vehicle';

    $brandId = getOrCreateVehicleBrand($conn, $brandName !== '' ? $brandName : 'General');
    $categoryId = getOrCreateVehicleCategory($conn);

    $stmt = $conn->prepare("
        SELECT id 
        FROM vehicle_models 
        WHERE business_id = ? AND brand_id = ? AND model_name = ? 
        LIMIT 1
    ");

    if ($stmt) {
        $stmt->bind_param('iis', $businessId, $brandId, $modelName);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row) return (int)$row['id'];
    }

    $vehicleType = in_array($vehicleType, ['fuel', 'electric'], true) ? $vehicleType : 'electric';
    $fuelType = $vehicleType === 'electric' ? 'electric' : 'petrol';
    $transmission = 'automatic';
    $variant = '';

    $stmt = $conn->prepare("
        INSERT INTO vehicle_models 
        (
            business_id, brand_id, category_id, model_name, variant_name,
            vehicle_type, fuel_type, transmission, ex_showroom_price,
            gst_percent, status, created_at
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())
    ");

    if (!$stmt) {
        throw new Exception('Vehicle model insert failed: ' . $conn->error);
    }

    $stmt->bind_param(
        'iiisssssdd',
        $businessId,
        $brandId,
        $categoryId,
        $modelName,
        $variant,
        $vehicleType,
        $fuelType,
        $transmission,
        $exShowroom,
        $gstPercent
    );

    if (!$stmt->execute()) {
        throw new Exception('Vehicle model save failed: ' . $stmt->error);
    }

    $id = (int)$conn->insert_id;
    $stmt->close();

    return $id;
}

function uniqueVehicleIdentifierForStock(mysqli $conn, int $businessId, string $column, $value, int $invoiceId): ?string
{
    $value = trim((string)($value ?? ''));

    /*
       These vehicle detail fields are optional.
       For unique columns, blank values must be NULL, not empty string.
       Empty string causes duplicate key errors like business_id + ''.
    */
    if ($value === '') {
        return null;
    }

    if (!tableExists($conn, 'vehicle_stock') || !columnExists($conn, 'vehicle_stock', $column)) {
        return $value;
    }

    $sql = "SELECT id FROM vehicle_stock WHERE business_id = ? AND {$column} = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return $value;
    }
    $stmt->bind_param('is', $businessId, $value);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$exists) {
        return $value;
    }

    $base = substr($value, 0, 48);
    for ($i = 1; $i <= 20; $i++) {
        $candidate = $base . '-INV' . $invoiceId . ($i > 1 ? '-' . $i : '');
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return $candidate;
        }
        $stmt->bind_param('is', $businessId, $candidate);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return $candidate;
        }
    }

    return $base . '-' . strtoupper(substr(md5((string)microtime(true)), 0, 6));
}

/* LOGIN VALIDATION */
$loggedUser = null;

if (tableExists($conn, 'business_users') && tableExists($conn, 'businesses')) {
    $stmt = $conn->prepare("
        SELECT 
            bu.id, bu.full_name, bu.role, bu.status,
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

ensureVehicleOptionalUniqueColumns($conn);

/* MASTER DATA */
$branches = fetchAllAssoc($conn, "
    SELECT id, branch_name, branch_code
    FROM branches 
    WHERE business_id = {$businessId} AND status = 'active' 
    ORDER BY branch_name ASC
");

$customers = fetchAllAssoc($conn, "
    SELECT id, full_name, mobile 
    FROM customers 
    WHERE business_id = {$businessId} 
    ORDER BY full_name ASC
");

$categories = tableExists($conn, 'product_categories')
    ? fetchAllAssoc($conn, "SELECT id, category_name FROM product_categories ORDER BY category_name ASC")
    : [];

$chargerBrands = tableExists($conn, 'charger_brands')
    ? fetchAllAssoc($conn, "SELECT id, brand_name FROM charger_brands WHERE status = 1 ORDER BY brand_name ASC")
    : [];

$batteryBrands = tableExists($conn, 'battery_brands')
    ? fetchAllAssoc($conn, "SELECT id, brand_name FROM battery_brands WHERE status = 1 ORDER BY brand_name ASC")
    : [];

$vehicleBrands = tableExists($conn, 'vehicle_brands')
    ? fetchAllAssoc($conn, "SELECT id, brand_name FROM vehicle_brands WHERE status = 1 ORDER BY brand_name ASC")
    : [];

$vehicleModels = [];
if (tableExists($conn, 'vehicle_models') && tableExists($conn, 'vehicle_brands')) {
    $vehicleModels = fetchAllAssoc($conn, "
        SELECT
            vm.id,
            vm.brand_id,
            vb.brand_name,
            vm.model_name,
            vm.variant_name,
            vm.vehicle_type,
            vm.fuel_type,
            vm.ex_showroom_price,
            COALESCE(vm.dealer_cost, 0) AS dealer_cost,
            vm.gst_percent,
            vm.hsn_code,
            COALESCE(vm.insurance_price, 0) AS insurance_price,
            COALESCE(vm.registration_price, 0) AS registration_price,
            COALESCE(vm.rto_charge, 0) AS rto_charge,
            COALESCE(vm.road_tax, 0) AS road_tax
        FROM vehicle_models vm
        LEFT JOIN vehicle_brands vb ON vb.id = vm.brand_id
        WHERE vm.business_id = {$businessId}
          AND vm.status = 1
        ORDER BY vb.brand_name ASC, vm.model_name ASC, vm.variant_name ASC
    ");
}

$stockProducts = [];
if (tableExists($conn, 'products')) {
    $hasProductStock = tableExists($conn, 'product_stock');

    $stockQtyExpr = $hasProductStock
        ? "COALESCE(ps.qty_available, p.stock_qty, 0)"
        : "COALESCE(p.stock_qty, 0)";

    /*
       IMPORTANT:
       Do not bind stock list to the logged-in session branch here.
       The invoice page allows branch selection, so products are loaded branch-wise
       and the browser filters them when Branch changes.
    */
    $stockProducts = fetchAllAssoc($conn, "
        SELECT
            p.id,
            p.product_name,
            p.product_code,
            p.item_code,
            p.brand_name,
            p.hsn_code,
            p.unit,
            p.purchase_price,
            COALESCE(p.selling_price, p.mrp, 0) AS selling_price,
            COALESCE(p.gst_percent, 0) AS gst_percent,
            {$stockQtyExpr} AS stock_qty,
            " . ($hasProductStock ? "COALESCE(ps.branch_id, 0) AS branch_id" : "0 AS branch_id") . ",
            " . (tableExists($conn, 'product_categories') ? "pc.category_name" : "'' AS category_name") . "
        FROM products p
        " . ($hasProductStock ? "LEFT JOIN product_stock ps ON ps.product_id = p.id AND ps.business_id = p.business_id" : "") . "
        " . (tableExists($conn, 'product_categories') ? "LEFT JOIN product_categories pc ON pc.id = p.category_id" : "") . "
        WHERE p.business_id = {$businessId}
          AND p.status = 1
        ORDER BY p.product_name ASC
    ");
}

$chargerTypes = [
    'Fast Charger',
    'Normal Charger',
    'Smart Charger',
    'Type-C Charger',
    'USB Charger',
    'Wireless Charger',
    'Portable Charger',
    'Wall Charger',
    'Car Charger'
];

$form = [
    'branch_id' => $currentBranchId > 0 ? $currentBranchId : ((int)($branches[0]['id'] ?? 0)),
    'invoice_no' => '',
    'customer_id' => 0,
    'invoice_date' => date('Y-m-d\TH:i'),
    'invoice_type' => getAllowedInvoiceTypeByBranch($currentBranchId > 0 ? $currentBranchId : ((int)($branches[0]['id'] ?? 0))),
    'discount_amount' => '0.00',
    'round_off' => '0.00',
    'paid_amount' => '0.00',
    'sale_status' => 'confirmed',
    'customer_note' => '',
    'gst_type' => 'inclusive'
];

$form['invoice_no'] = $form['branch_id'] > 0 ? generateInvoiceNo($conn, $businessId, $form['branch_id']) : '';

$success = '';
$error = '';

/* SAVE */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['branch_id'] = (int)($_POST['branch_id'] ?? 0);
    $form['invoice_no'] = trim($_POST['invoice_no'] ?? '');
    $form['customer_id'] = (int)($_POST['customer_id'] ?? 0);
    $form['invoice_date'] = trim($_POST['invoice_date'] ?? date('Y-m-d\TH:i'));
    $form['invoice_type'] = trim($_POST['invoice_type'] ?? getAllowedInvoiceTypeByBranch($form['branch_id']));
    if (!in_array($form['invoice_type'], ['product_sale', 'vehicle_sale', 'mixed_sale'], true)) {
        $form['invoice_type'] = getAllowedInvoiceTypeByBranch($form['branch_id']);
    }
    $form['discount_amount'] = trim($_POST['discount_amount'] ?? '0.00');
    $form['round_off'] = trim($_POST['round_off'] ?? '0.00');
    $form['paid_amount'] = trim($_POST['paid_amount'] ?? '0.00');
    $form['sale_status'] = trim($_POST['sale_status'] ?? 'confirmed');
    $form['customer_note'] = trim($_POST['customer_note'] ?? '');
    $form['gst_type'] = trim($_POST['gst_type'] ?? 'inclusive');

    $manualFullName = trim($_POST['manual_full_name'] ?? '');
    $manualMobile = trim($_POST['manual_mobile'] ?? '');

    $postedItems = $_POST['items'] ?? [];
    $items = [];

    if (is_array($postedItems)) {
        foreach ($postedItems as $row) {
            $item = [];

            $item['item_type'] = trim($row['item_type'] ?? 'product');
            $item['product_source'] = trim($row['product_source'] ?? 'manual');
            $item['stock_product_id'] = (int)($row['stock_product_id'] ?? 0);

            $item['description'] = trim($row['description'] ?? '');
            $item['qty'] = (float)($row['qty'] ?? 1);
            $item['unit_price'] = (float)($row['unit_price'] ?? 0);
            $item['purchase_price'] = (float)($row['purchase_price'] ?? 0);
            $item['discount_amount'] = (float)($row['discount_amount'] ?? 0);
            $item['gst_percent'] = (float)($row['gst_percent'] ?? 0);
            $item['gst_type'] = trim($row['gst_type'] ?? 'inclusive');

            $item['category'] = trim($row['category'] ?? '');
            $item['product_name'] = trim($row['product_name'] ?? '');
            $item['product_brand'] = trim($row['product_brand'] ?? '');
            $item['hsn_code'] = trim($row['hsn_code'] ?? '');
            $item['sku'] = trim($row['sku'] ?? '');

            $item['chassis_no'] = trim($row['chassis_no'] ?? '');
            $item['engine_no'] = '';
            $item['motor_no'] = trim($row['motor_no'] ?? '');
            $item['battery_brand'] = trim($row['battery_brand'] ?? '');
            $item['battery_no'] = trim($row['battery_no'] ?? '');
            $item['battery_capacity'] = trim($row['battery_capacity'] ?? '');
            $item['charger_brand'] = trim($row['charger_brand'] ?? '');
            $item['charger_no'] = trim($row['charger_no'] ?? '');
            $item['charger_type'] = trim($row['charger_type'] ?? '');

            $item['color'] = trim($row['color'] ?? '');
            $item['model_name'] = trim($row['model_name'] ?? '');
            $item['brand_name'] = trim($row['brand_name'] ?? '');
            $item['vehicle_model_id'] = (int)($row['vehicle_model_id'] ?? 0);
            $item['vehicle_type'] = trim($row['vehicle_type'] ?? 'electric');
            if (!in_array($item['vehicle_type'], ['fuel', 'electric'], true)) {
                $item['vehicle_type'] = 'electric';
            }

            $item['ex_showroom'] = (float)($row['ex_showroom'] ?? 0);
            $item['ex_showroom_cost'] = 0.00;
            $item['rto_charge'] = (float)($row['rto_charge'] ?? 0);
            $item['registration_price'] = (float)($row['registration_price'] ?? 0);
            $item['road_tax_amount'] = (float)($row['road_tax_amount'] ?? 0);
            $item['rto_mode'] = trim($row['rto_mode'] ?? 'non_rto');
            if ($item['rto_mode'] !== 'rto') {
                $item['rto_charge'] = 0.00;
                $item['registration_price'] = 0.00;
            } else {
                $item['rto_charge'] = 0.00;
            }
            $item['insurance_amount'] = (float)($row['insurance_amount'] ?? 0);

            if ($item['item_type'] === 'vehicle') {
                $item['qty'] = 1;
                $item['unit_price'] = $item['ex_showroom']
                    + $item['rto_charge']
                    + $item['registration_price']
                    + $item['road_tax_amount']
                    + $item['insurance_amount'];
            }

            $items[] = $item;
        }
    }

    if ($form['branch_id'] <= 0) {
        $error = 'Please select a branch.';
    } elseif ($form['invoice_no'] === '') {
        $error = 'Invoice number is required.';
    } elseif ($form['customer_id'] === 0 && ($manualFullName === '' || $manualMobile === '')) {
        $error = 'Please select customer OR enter new customer name and mobile.';
    } elseif (empty($items)) {
        $error = 'Please add at least one item.';
    }

    if ($error === '') {
        $checkStmt = $conn->prepare("
            SELECT id 
            FROM sales_invoices 
            WHERE business_id = ? AND branch_id = ? AND invoice_no = ?
            LIMIT 1
        ");

        if ($checkStmt) {
            $checkStmt->bind_param('iis', $businessId, $form['branch_id'], $form['invoice_no']);
            $checkStmt->execute();
            if ($checkStmt->get_result()->num_rows > 0) {
                $form['invoice_no'] = generateInvoiceNo($conn, $businessId, $form['branch_id']);
            }
            $checkStmt->close();
        }
    }

    if ($error === '') {
        $subtotal = 0.0;
        $cgstAmount = 0.0;
        $sgstAmount = 0.0;
        $totalProfit = 0.0;
        $validItems = [];

        foreach ($items as $item) {
            $qty = (float)$item['qty'];
            $unitPrice = (float)$item['unit_price'];
            $purchasePrice = (float)$item['purchase_price'];
            $discount = (float)$item['discount_amount'];
            $gstPercent = (float)$item['gst_percent'];
            $gstType = $item['gst_type'];

            if ($qty <= 0 || $unitPrice <= 0 || $item['description'] === '') {
                continue;
            }

            $lineAmount = $qty * $unitPrice;

            if ($gstType === 'inclusive' && $gstPercent > 0) {
                $taxableValue = $lineAmount / (1 + ($gstPercent / 100));
            } else {
                $taxableValue = $lineAmount;
            }

            $taxableValue -= $discount;
            if ($taxableValue < 0) $taxableValue = 0;

            $taxAmount = $taxableValue * ($gstPercent / 100);
            $itemCgst = $taxAmount / 2;
            $itemSgst = $taxAmount / 2;
            $lineTotal = $taxableValue + $taxAmount;

            if ($gstType === 'inclusive') {
                $lineTotal = max(0, $lineAmount - $discount);
            }

            if ($item['item_type'] === 'vehicle') {
                $itemProfit = $item['ex_showroom'] - $item['ex_showroom_cost'];
            } else {
                $itemProfit = ($unitPrice - $purchasePrice) * $qty;
            }

            $subtotal += $taxableValue;
            $cgstAmount += $itemCgst;
            $sgstAmount += $itemSgst;
            $totalProfit += $itemProfit;

            $validItems[] = array_merge($item, [
                'taxable_value' => $taxableValue,
                'cgst_amount' => $itemCgst,
                'sgst_amount' => $itemSgst,
                'line_total' => $lineTotal,
                'profit' => $itemProfit
            ]);
        }

        if (empty($validItems)) {
            $error = 'Please add valid item details.';
        }
    }

    if ($error === '') {
        $invoiceDiscount = (float)$form['discount_amount'];
        $roundOff = (float)$form['round_off'];

        $grandTotal = $subtotal + $cgstAmount + $sgstAmount - $invoiceDiscount + $roundOff;
        if ($grandTotal < 0) $grandTotal = 0;

        $paidAmount = (float)$form['paid_amount'];
        if ($paidAmount > $grandTotal) $paidAmount = $grandTotal;

        $balanceAmount = $grandTotal - $paidAmount;

        if ($paidAmount >= $grandTotal && $grandTotal > 0) {
            $paymentStatus = 'paid';
        } elseif ($paidAmount > 0) {
            $paymentStatus = 'partial';
        } else {
            $paymentStatus = 'unpaid';
        }

        $mf = trim($_POST['manual_full_name'] ?? '');
        $mm = trim($_POST['manual_mobile'] ?? '');
        $ma = trim($_POST['manual_alternate_mobile'] ?? '');
        $me = trim($_POST['manual_email'] ?? '');
        $mad = trim($_POST['manual_address_line1'] ?? '');
        $mc = trim($_POST['manual_city'] ?? '');
        $ms = trim($_POST['manual_state'] ?? '');
        $mp = trim($_POST['manual_pincode'] ?? '');

        $conn->begin_transaction();

        try {
            $invCustId = (int)$form['customer_id'];

            if ($invCustId === 0 && $mf !== '' && $mm !== '') {
                $customerCode = 'CUST' . str_pad((string)mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);

                $custStmt = $conn->prepare("
                    INSERT INTO customers 
                    (
                        business_id, customer_code, full_name, mobile, alternate_mobile,
                        email, address_line1, city, state, pincode, created_at, updated_at
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ");

                if (!$custStmt) {
                    throw new Exception('Customer insert failed: ' . $conn->error);
                }

                $custStmt->bind_param(
                    'isssssssss',
                    $businessId,
                    $customerCode,
                    $mf,
                    $mm,
                    $ma,
                    $me,
                    $mad,
                    $mc,
                    $ms,
                    $mp
                );

                if (!$custStmt->execute()) {
                    throw new Exception('Customer save failed: ' . $custStmt->error);
                }

                $invCustId = (int)$conn->insert_id;
                $custStmt->close();
            }

            $invoiceSql = "
                INSERT INTO sales_invoices 
                (
                    business_id, branch_id, invoice_no, customer_id, invoice_date,
                    invoice_type, subtotal, discount_amount, cgst_amount, sgst_amount,
                    igst_amount, cess_amount, round_off, grand_total, paid_amount,
                    balance_amount, payment_status, sale_status, customer_note,
                    created_by, created_at
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ";

            $stmt = $conn->prepare($invoiceSql);
            if (!$stmt) {
                throw new Exception('Invoice insert failed: ' . $conn->error);
            }

            $invoiceDateDb = date('Y-m-d H:i:s', strtotime($form['invoice_date']));
            $igstAmount = 0.0;
            $cessAmount = 0.0;

            $stmt->bind_param(
                'iisissddddddddddsssi',
                $businessId,
                $form['branch_id'],
                $form['invoice_no'],
                $invCustId,
                $invoiceDateDb,
                $form['invoice_type'],
                $subtotal,
                $invoiceDiscount,
                $cgstAmount,
                $sgstAmount,
                $igstAmount,
                $cessAmount,
                $roundOff,
                $grandTotal,
                $paidAmount,
                $balanceAmount,
                $paymentStatus,
                $form['sale_status'],
                $form['customer_note'],
                $businessUserId
            );

            if (!$stmt->execute()) {
                throw new Exception('Invoice save failed: ' . $stmt->error);
            }

            $invoiceId = (int)$conn->insert_id;
            $stmt->close();

            $itemSql = "
                INSERT INTO sales_invoice_items 
                (
                    invoice_id, item_type, vehicle_stock_id, product_id, description,
                    qty, unit_price, purchase_price, discount_amount, taxable_value,
                    cgst_percent, sgst_percent, igst_percent, cess_percent,
                    cgst_amount, sgst_amount, igst_amount, cess_amount,
                    line_total, profit_amount
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?, ?, 0, 0, ?, ?)
            ";

            $itemStmt = $conn->prepare($itemSql);
            if (!$itemStmt) {
                throw new Exception('Item insert failed: ' . $conn->error);
            }

            foreach ($validItems as $item) {
                $vehicleStockId = null;
                $productId = null;

                if ($item['item_type'] === 'vehicle') {
                    $modelId = (int)($item['vehicle_model_id'] ?? 0);
                    if ($modelId <= 0) {
                        $modelId = getOrCreateVehicleModel(
                            $conn,
                            $businessId,
                            $item['brand_name'],
                            $item['model_name'],
                            (float)$item['ex_showroom'],
                            (float)$item['gst_percent'],
                            (string)($item['vehicle_type'] ?? 'electric')
                        );
                    }

                    $purchaseDate = date('Y-m-d', strtotime($invoiceDateDb));
                    $salePrice = (float)$item['ex_showroom']
                        + (float)$item['rto_charge']
                        + (float)$item['registration_price']
                        + (float)$item['road_tax_amount']
                        + (float)$item['insurance_amount'];

                    $stockChassisNo = uniqueVehicleIdentifierForStock($conn, $businessId, 'chassis_no', $item['chassis_no'], $invoiceId);
                    $stockEngineNo = uniqueVehicleIdentifierForStock($conn, $businessId, 'engine_no', $item['engine_no'], $invoiceId);
                    $stockMotorNo = uniqueVehicleIdentifierForStock($conn, $businessId, 'motor_no', $item['motor_no'], $invoiceId);
                    $stockBatteryNo = uniqueVehicleIdentifierForStock($conn, $businessId, 'battery_no', $item['battery_no'], $invoiceId);
                    $stockChargerNo = uniqueVehicleIdentifierForStock($conn, $businessId, 'charger_no', $item['charger_no'], $invoiceId);

                    $vehicleStockSql = "
                        INSERT INTO vehicle_stock 
                        (
                            business_id, branch_id, model_id, color, chassis_no,
                            engine_no, motor_no, battery_brand, battery_no,
                            battery_capacity, charger_brand, charger_no, charger_type,
                            purchase_date, purchase_cost, ex_showroom_price,
                            rto_charge, registration_price, road_tax, insurance_price,
                            sale_price, stock_status, created_at
                        )
                        VALUES 
                        (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'sold', NOW())
                    ";

                    $vehicleStockStmt = $conn->prepare($vehicleStockSql);
                    if (!$vehicleStockStmt) {
                        throw new Exception('Vehicle stock insert failed: ' . $conn->error);
                    }

                    $vehicleStockStmt->bind_param(
                        'iiissssssssssdddddddd',
                        $businessId,
                        $form['branch_id'],
                        $modelId,
                        $item['color'],
                        $stockChassisNo,
                        $stockEngineNo,
                        $stockMotorNo,
                        $item['battery_brand'],
                        $stockBatteryNo,
                        $item['battery_capacity'],
                        $item['charger_brand'],
                        $stockChargerNo,
                        $item['charger_type'],
                        $purchaseDate,
                        $item['ex_showroom_cost'],
                        $item['ex_showroom'],
                        $item['rto_charge'],
                        $item['registration_price'],
                        $item['road_tax_amount'],
                        $item['insurance_amount'],
                        $salePrice
                    );

                    if (!$vehicleStockStmt->execute()) {
                        throw new Exception('Vehicle stock save failed: ' . $vehicleStockStmt->error);
                    }

                    $vehicleStockId = (int)$conn->insert_id;
                    $vehicleStockStmt->close();

                    if (tableExists($conn, 'vehicle_sales')) {
                        $saleDate = date('Y-m-d', strtotime($invoiceDateDb));
                        $vehicleProfit = (float)$item['ex_showroom'] - (float)$item['ex_showroom_cost'];

                        $vehicleSaleSql = "
                            INSERT INTO vehicle_sales 
                            (
                                business_id, branch_id, invoice_id, vehicle_stock_id,
                                customer_id, sale_date, ex_showroom_price,
                                ex_showroom_cost, insurance_amount, registration_amount,
                                rto_charge, road_tax_amount, total_vehicle_amount,
                                profit_amount, battery_brand, battery_no,
                                charger_brand, charger_no, charger_type,
                                created_at
                            )
                            VALUES 
                            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                            ON DUPLICATE KEY UPDATE
                                vehicle_stock_id = VALUES(vehicle_stock_id),
                                customer_id = VALUES(customer_id),
                                sale_date = VALUES(sale_date),
                                ex_showroom_price = VALUES(ex_showroom_price),
                                ex_showroom_cost = VALUES(ex_showroom_cost),
                                insurance_amount = VALUES(insurance_amount),
                                registration_amount = VALUES(registration_amount),
                                rto_charge = VALUES(rto_charge),
                                road_tax_amount = VALUES(road_tax_amount),
                                total_vehicle_amount = VALUES(total_vehicle_amount),
                                profit_amount = VALUES(profit_amount),
                                battery_brand = VALUES(battery_brand),
                                battery_no = VALUES(battery_no),
                                charger_brand = VALUES(charger_brand),
                                charger_no = VALUES(charger_no),
                                charger_type = VALUES(charger_type)
                        ";

                        $vehicleSaleStmt = $conn->prepare($vehicleSaleSql);
                        if ($vehicleSaleStmt) {
                            $vehicleSaleStmt->bind_param(
                                'iiiiisddddddddsssss',
                                $businessId,
                                $form['branch_id'],
                                $invoiceId,
                                $vehicleStockId,
                                $invCustId,
                                $saleDate,
                                $item['ex_showroom'],
                                $item['ex_showroom_cost'],
                                $item['insurance_amount'],
                                $item['registration_price'],
                                $item['rto_charge'],
                                $item['road_tax_amount'],
                                $salePrice,
                                $vehicleProfit,
                                $item['battery_brand'],
                                $item['battery_no'],
                                $item['charger_brand'],
                                $item['charger_no'],
                                $item['charger_type']
                            );
                            if (!$vehicleSaleStmt->execute()) {
                                throw new Exception('Vehicle sale save failed: ' . $vehicleSaleStmt->error);
                            }
                            $vehicleSaleStmt->close();
                        }
                    }
                } elseif ($item['item_type'] === 'product') {
                    if ($item['product_source'] === 'stock' && (int)$item['stock_product_id'] > 0) {
                        $productId = (int)$item['stock_product_id'];
                    } else {
                        $productId = getOrCreateManualProduct($conn, $businessId, $item);
                    }
                }

                $itPurchPrice = ($item['item_type'] === 'vehicle')
                    ? (float)$item['ex_showroom_cost']
                    : (float)$item['purchase_price'];

                $itCgstPct = ((float)$item['gst_percent']) / 2;
                $itSgstPct = ((float)$item['gst_percent']) / 2;

                $itemStmt->bind_param(
                    'isiisddddddddddd',
                    $invoiceId,
                    $item['item_type'],
                    $vehicleStockId,
                    $productId,
                    $item['description'],
                    $item['qty'],
                    $item['unit_price'],
                    $itPurchPrice,
                    $item['discount_amount'],
                    $item['taxable_value'],
                    $itCgstPct,
                    $itSgstPct,
                    $item['cgst_amount'],
                    $item['sgst_amount'],
                    $item['line_total'],
                    $item['profit']
                );

                if (!$itemStmt->execute()) {
                    throw new Exception('Item save failed: ' . $itemStmt->error);
                }

                if ($item['item_type'] === 'product' && $productId > 0 && tableExists($conn, 'products')) {
                    $qtySold = (float)$item['qty'];
                    $conn->query("
                        UPDATE products
                        SET stock_qty = GREATEST(COALESCE(stock_qty,0) - {$qtySold}, 0)
                        WHERE id = {$productId}
                          AND business_id = {$businessId}
                    ");
                }

                if ($item['item_type'] === 'product' && $productId > 0 && tableExists($conn, 'product_stock')) {
                    $qtySold = (float)$item['qty'];
                    $conn->query("
                        UPDATE product_stock
                        SET qty_available = GREATEST(COALESCE(qty_available,0) - {$qtySold}, 0)
                        WHERE product_id = {$productId}
                          AND business_id = {$businessId}
                          AND branch_id = {$form['branch_id']}
                    ");
                }
            }

            $itemStmt->close();

            $conn->commit();

            header('Location: sales-invoice-print.php?id=' . $invoiceId);
            exit;

            $success = "Invoice #" . h($form['invoice_no']) . " created successfully. Total: ₹" . number_format($grandTotal, 2);

            $form['invoice_no'] = generateInvoiceNo($conn, $businessId, $form['branch_id']);
            $form['customer_id'] = 0;
            $form['discount_amount'] = '0.00';
            $form['paid_amount'] = '0.00';
            $form['round_off'] = '0.00';
            $form['customer_note'] = '';
        } catch (Exception $e) {
            $conn->rollback();
            $error = 'Failed to save invoice: ' . $e->getMessage();
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Add Sales Invoice</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<?php include('includes/head.php'); ?>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/remixicon@4.0.0/fonts/remixicon.css" rel="stylesheet">

<style>
*{margin:0;padding:0;box-sizing:border-box}
html,body{height:100%;width:100%;overflow-x:hidden;font-family:system-ui,-apple-system,sans-serif;background:#f1f5f9}
.app-container{display:flex;flex-direction:column;height:100vh;width:100vw}
.app-header{background:linear-gradient(135deg,#1e40af,#3b82f6);color:#fff;padding:12px 25px;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;box-shadow:0 2px 10px rgba(0,0,0,.15);z-index:100;flex-wrap:wrap;gap:10px}
.app-header h2{font-size:20px;margin:0;font-weight:600}
.app-body{flex:1;overflow-y:auto;padding:15px;display:flex;flex-direction:column}
.invoice-layout{display:flex;gap:20px;flex:1;min-height:0}
.left-panel{flex:0 0 540px;display:flex;flex-direction:column;gap:15px;overflow-y:auto;padding-right:5px}
.right-panel{flex:1;display:flex;flex-direction:column;min-width:0;overflow-y:auto}
.card{border:none;border-radius:10px;box-shadow:0 1px 3px rgba(0,0,0,.08);background:#fff}
.card-header{background:#fff;border-bottom:1px solid #e2e8f0;padding:10px 15px;border-radius:10px 10px 0 0;font-weight:600;font-size:14px}
.card-body{padding:15px}
.manual-section{background:#f8fafc;border-radius:8px;padding:12px;margin-top:8px;border:1px dashed #cbd5e1}
.detail-section{background:#f8fafc;border-radius:8px;padding:15px;margin-top:10px;border:1px solid #e2e8f0;display:none}
.detail-section.product{border-color:#93c5fd;background:#eff6ff}
.detail-section.vehicle{border-color:#86efac;background:#f0fdf4}
.detail-section.charger{border-color:#fbbf24;background:#fffbeb}
.form-label{font-weight:500;font-size:13px;margin-bottom:4px;color:#374151}
.form-control,.form-select{font-size:13px;padding:8px 12px;border-radius:6px}
.form-control:focus,.form-select:focus{border-color:#3b82f6;box-shadow:0 0 0 3px rgba(59,130,246,.1)}
.table{font-size:12px;margin-bottom:0}
.table td,.table th{padding:8px 10px;vertical-align:middle}
.badge{font-size:10px}
.btn{font-size:13px;padding:6px 14px;border-radius:6px}
.btn-lg{font-size:15px;padding:10px 20px}
.cart-empty{text-align:center;padding:50px;color:#94a3b8}
.vehicle-row td{background:#f0fdf4!important}
.section-label{display:block;font-weight:600;font-size:14px;margin-bottom:10px;color:#1e293b}
.field-row{margin-bottom:12px}
.product-source-box{background:#fff;border:1px solid #bfdbfe;border-radius:8px;padding:10px;margin-bottom:12px}
.stock-info{font-size:12px;color:#475569;background:#fff;border:1px dashed #93c5fd;border-radius:6px;padding:8px;margin-top:8px}
.search-hint{font-size:11px;color:#6c757d;margin-top:4px;padding:4px 8px;background:#e9ecef;border-radius:4px}
.invoice-number-info{font-size:11px;color:#28a745;margin-top:4px;font-weight:500}

.stock-product-picker{background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:10px;margin-top:10px}
.stock-product-toolbar{display:flex;gap:8px;align-items:end;margin-bottom:8px;flex-wrap:wrap}
.stock-product-list{height:240px;overflow:auto;border:1px solid #dbeafe;border-radius:8px;background:#fff}
.stock-product-option{display:grid;grid-template-columns:24px minmax(0,1fr);gap:8px;align-items:start;padding:9px 10px;border-bottom:1px solid #eff6ff;cursor:pointer;margin:0}
.stock-product-option:hover{background:#f8fafc}
.stock-product-option:last-child{border-bottom:0}
.stock-product-option input{width:16px;height:16px;margin-top:3px}
.stock-product-title{font-weight:700;color:#0f172a;font-size:13px;line-height:1.25;word-break:break-word}
.stock-product-meta{font-size:11.5px;color:#64748b;margin-top:3px;line-height:1.35;word-break:break-word}
.stock-product-actions{display:flex;justify-content:space-between;align-items:center;gap:8px;margin-top:8px;flex-wrap:wrap}
.stock-selected-badge{background:#fef3c7;color:#92400e;border:1px solid #fde68a;border-radius:999px;padding:4px 10px;font-size:12px;font-weight:700;white-space:nowrap}
@media(max-width:1024px){.invoice-layout{flex-direction:column}.left-panel{flex:0 0 auto;max-height:none}}
</style>
</head>

<body>
<div class="app-container">

<div class="app-header">
    <div>
        <h2>📋 Add Sales Invoice</h2>
        <small style="opacity:.8">Create invoice with stock product, manual product, vehicle & charger entries</small>
    </div>
    <div style="display:flex;gap:10px;flex-wrap:wrap">
        <a href="index.php" class="btn btn-light btn-sm">
            <i class="ri-arrow-left-line me-1"></i>Dashboard
        </a>
        <a href="sales-invoices.php" class="btn btn-outline-light btn-sm">
            <i class="ri-file-list-line me-1"></i>All Invoices
        </a>
    </div>
</div>

<div class="app-body">

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show mb-3">
        <i class="ri-check-double-line me-2"></i><?= h($success) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show mb-3">
        <i class="ri-error-warning-line me-2"></i><?= h($error) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<form method="post" id="invoiceForm" style="flex:1;display:flex;flex-direction:column;min-height:0">

<div class="invoice-layout">

<div class="left-panel">

<div class="card">
<div class="card-body">
    <span class="section-label">📋 Invoice Details</span>

    <div style="display:flex;gap:10px;margin-bottom:12px">
        <div style="flex:1">
            <label class="form-label">Branch <span style="color:#ef4444">*</span></label>
            <select name="branch_id" id="branch_id" class="form-select" required onchange="applyBranchInvoiceRule(true)">
                <option value="">-- Select --</option>
                <?php foreach ($branches as $b): ?>
                    <option value="<?= (int)$b['id'] ?>" <?= ((int)$form['branch_id'] === (int)$b['id']) ? 'selected' : '' ?>>
                        <?= h($b['branch_name'] . ' (' . $b['branch_code'] . ')') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div style="flex:1">
            <label class="form-label">Invoice No <span style="color:#ef4444">*</span></label>
            <input type="text" name="invoice_no" id="invoice_no" class="form-control" value="<?= h($form['invoice_no']) ?>" required readonly style="background:#e9ecef;">
            <div class="invoice-number-info">✓ Auto-generated 8-digit sequential number (e.g., INV00000001)</div>
        </div>
    </div>

    <div style="display:flex;gap:10px;margin-bottom:12px">
        <div style="flex:1">
            <label class="form-label">Invoice Date</label>
            <input type="datetime-local" name="invoice_date" class="form-control" value="<?= h($form['invoice_date']) ?>" required>
        </div>

        <div style="flex:1">
            <label class="form-label">Invoice Type</label>
            <select name="invoice_type" id="invoice_type" class="form-select" onchange="syncItemTypeWithInvoiceType()">
                <option value="product_sale" <?= ($form['invoice_type'] === 'product_sale') ? 'selected' : '' ?>>🛍️ Product</option>
                <option value="vehicle_sale" <?= ($form['invoice_type'] === 'vehicle_sale') ? 'selected' : '' ?>>🏍️ Vehicle</option>
                <option value="mixed_sale" <?= ($form['invoice_type'] === 'mixed_sale') ? 'selected' : '' ?>>📦 Mixed</option>
            </select>
        </div>
    </div>

    <hr style="margin:15px 0">

    <span class="section-label">👤 Customer Information</span>

    <div class="field-row">
        <label class="form-label">Select Existing Customer</label>
        <select name="customer_id" class="form-select">
            <option value="0">-- New Customer --</option>
            <?php foreach ($customers as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= ((int)$form['customer_id'] === (int)$c['id']) ? 'selected' : '' ?>>
                    <?= h($c['full_name'] . ' - ' . $c['mobile']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="manual-section">
        <span style="font-weight:600;font-size:13px">OR Enter New Customer</span>

        <div style="display:flex;gap:10px;margin-top:10px;flex-wrap:wrap">
            <div style="flex:1;min-width:200px">
                <label class="form-label">Full Name</label>
                <input type="text" name="manual_full_name" class="form-control">
            </div>

            <div style="flex:1;min-width:200px">
                <label class="form-label">Mobile</label>
                <input type="text" name="manual_mobile" class="form-control">
            </div>
        </div>

        <div style="display:flex;gap:10px;margin-top:10px;flex-wrap:wrap">
            <div style="flex:1;min-width:200px">
                <label class="form-label">Alt Mobile</label>
                <input type="text" name="manual_alternate_mobile" class="form-control">
            </div>

            <div style="flex:1;min-width:200px">
                <label class="form-label">Email</label>
                <input type="email" name="manual_email" class="form-control">
            </div>
        </div>

        <div class="field-row" style="margin-top:10px">
            <label class="form-label">Address</label>
            <input type="text" name="manual_address_line1" class="form-control">
        </div>

        <div style="display:flex;gap:10px;flex-wrap:wrap">
            <div style="flex:1;min-width:120px">
                <label class="form-label">City</label>
                <input type="text" name="manual_city" class="form-control">
            </div>

            <div style="flex:1;min-width:120px">
                <label class="form-label">State</label>
                <input type="text" name="manual_state" class="form-control">
            </div>

            <div style="flex:1;min-width:120px">
                <label class="form-label">Pincode</label>
                <input type="text" name="manual_pincode" class="form-control">
            </div>
        </div>
    </div>

    <div style="display:flex;gap:10px;margin-top:12px">
        <div style="flex:1">
            <label class="form-label">Paid Amount ₹</label>
            <input type="number" step="0.01" name="paid_amount" id="paid_amount" class="form-control" value="<?= h($form['paid_amount']) ?>">
        </div>

        <div style="flex:1">
            <label class="form-label">Sale Status</label>
            <select name="sale_status" class="form-select">
                <option value="confirmed" selected>Confirmed</option>
                <option value="draft">Draft</option>
                <option value="delivered">Delivered</option>
                <option value="cancelled">Cancelled</option>
            </select>
        </div>
    </div>

    <div class="field-row">
        <label class="form-label">Customer Note</label>
        <textarea name="customer_note" class="form-control" rows="2"><?= h($form['customer_note']) ?></textarea>
    </div>

    <input type="hidden" name="gst_type" id="global_gst_type" value="inclusive">
</div>
</div>

<div class="card">
<div class="card-body">
    <span class="section-label">➕ Add Item</span>

    <div class="field-row">
        <label class="form-label">Select Item Type</label>
        <select id="item_type" class="form-select" onchange="toggleSection(); syncInvoiceTypeFromItemType();">
            <option value="product">🛍️ Product</option>
            <option value="vehicle">🏍️ Vehicle</option>
            <option value="charger">🔌 Charger</option>
        </select>
    </div>

    <div id="product_entry" class="detail-section product">
        <span class="section-label">🛍️ Product Entry</span>

        <div class="product-source-box">
            <label class="form-label">Product Source</label>
            <select id="product_source" class="form-select" onchange="toggleProductSource()">
                <option value="stock">Stock Product</option>
                <option value="manual">Manual Product</option>
            </select>
        </div>

        <div id="stockProductBox">
            <label class="form-label">Search Stock Product</label>
            <div style="position: relative;">
                <input type="text" id="stock_product_search" class="form-control" placeholder="Type to search products by name, code, brand, or HSN..." onkeyup="filterStockProducts()" autocomplete="off">
                <div class="search-hint">💡 Start typing to filter results (matches first two letters or contains text)</div>
            </div>

            <div class="stock-product-picker">
                <div class="stock-product-toolbar">
                    <div style="flex:1;min-width:220px">
                        <label class="form-label">Select Stock Product</label>
                        <div class="search-hint">✅ Tick the checkbox products. No Ctrl/Cmd selection needed.</div>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="selectVisibleStockProducts()">Select Visible</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="clearStockProductSelection()">Clear</button>
                </div>

                <div id="stock_product_list" class="stock-product-list">
                    <?php foreach ($stockProducts as $p): ?>
                        <?php
                        $productName = $p['product_name'] ?? '';
                        $productCode = $p['product_code'] ?? '';
                        $itemCode = $p['item_code'] ?? '';
                        $brandName = $p['brand_name'] ?? '';
                        $categoryName = $p['category_name'] ?? '';
                        $hsnCode = $p['hsn_code'] ?? '';
                        $sellingPrice = (float)($p['selling_price'] ?? 0);
                        $purchasePrice = (float)($p['purchase_price'] ?? 0);
                        $gstPercent = (float)($p['gst_percent'] ?? 0);
                        $stockQty = (float)($p['stock_qty'] ?? 0);
                        $searchText = trim($productName . ' ' . $productCode . ' ' . $itemCode . ' ' . $brandName . ' ' . $categoryName . ' ' . $hsnCode);
                        ?>
                        <label class="stock-product-option"
                               data-search="<?= h(strtolower($searchText)) ?>">
                            <input type="checkbox"
                                   class="stock-product-checkbox"
                                   value="<?= (int)$p['id'] ?>"
                                   data-name="<?= h($productName) ?>"
                                   data-code="<?= h($productCode) ?>"
                                   data-item-code="<?= h($itemCode) ?>"
                                   data-brand="<?= h($brandName) ?>"
                                   data-hsn="<?= h($hsnCode) ?>"
                                   data-category="<?= h($categoryName) ?>"
                                   data-purchase="<?= h($purchasePrice) ?>"
                                   data-selling="<?= h($sellingPrice) ?>"
                                   data-gst="<?= h($gstPercent) ?>"
                                   data-stock="<?= h($stockQty) ?>"
                                   data-branch-id="<?= h($p['branch_id'] ?? 0) ?>"
                                   onchange="fillStockProduct()">
                            <span>
                                <span class="stock-product-title">
                                    <?= h($productName) ?>
                                    <?php if ($productCode !== '' || $itemCode !== ''): ?>
                                        <small class="text-muted"><?= h($productCode ?: $itemCode) ?></small>
                                    <?php endif; ?>
                                </span>
                                <span class="stock-product-meta">
                                    Brand: <?= h($brandName ?: '-') ?> |
                                    Category: <?= h($categoryName ?: '-') ?> |
                                    Stock: <?= number_format($stockQty, 2) ?> |
                                    Rate: ₹<?= number_format($sellingPrice, 2) ?> |
                                    GST: <?= number_format($gstPercent, 2) ?>%
                                </span>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>

                <div class="stock-product-actions">
                    <span class="stock-selected-badge" id="stockSelectedCount">0 selected</span>
                    <small class="text-muted">Click Add to add selected products into cart.</small>
                </div>
            </div>

            <div id="stockProductInfo" class="stock-info" style="display:none;"></div>
        </div>

        <div class="field-row mt-3">
            <label class="form-label">Description *</label>
            <input type="text" id="prod_desc" class="form-control">
        </div>

        <div style="display:flex;gap:10px;margin-bottom:12px">
            <div style="flex:1">
                <label class="form-label">Qty</label>
                <input type="number" id="prod_qty" class="form-control" value="1" step="0.01">
            </div>
            <div style="flex:1">
                <label class="form-label">Purchase ₹</label>
                <input type="number" id="prod_purchase" class="form-control" value="0.00" step="0.01">
            </div>
            <div style="flex:1">
                <label class="form-label">Selling ₹</label>
                <input type="number" id="prod_price" class="form-control" value="0.00" step="0.01">
            </div>
        </div>

        <div style="display:flex;gap:10px;margin-bottom:12px;align-items:end">
            <div style="flex:1">
                <label class="form-label">Discount ₹</label>
                <input type="number" id="prod_disc" class="form-control" value="0.00" step="0.01">
            </div>
            <div style="flex:1">
                <label class="form-label">GST %</label>
                <input type="number" id="prod_gst" class="form-control" value="0.00" step="0.01">
            </div>
            <div style="flex:1">
                <label class="form-label">GST Type</label>
                <select id="prod_gst_type" class="form-select">
                    <option value="inclusive" selected>Inclusive</option>
                    <option value="exclusive">Exclusive</option>
                </select>
            </div>
            <button type="button" class="btn btn-success" onclick="addP()">➕ Add</button>
        </div>

        <div style="display:flex;gap:10px;margin-bottom:12px">
            <div style="flex:1">
                <label class="form-label">Product Name</label>
                <input type="text" id="prod_name" class="form-control">
            </div>
            <div style="flex:1">
                <label class="form-label">Brand</label>
                <input type="text" id="prod_brand" class="form-control">
            </div>
        </div>

        <div style="display:flex;gap:10px">
            <div style="flex:1">
                <label class="form-label">Category</label>
                <input type="text" id="prod_cat" class="form-control" list="catList">
                <datalist id="catList">
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= h($cat['category_name']) ?>">
                    <?php endforeach; ?>
                </datalist>
            </div>
            <div style="flex:1">
                <label class="form-label">HSN</label>
                <input type="text" id="prod_hsn" class="form-control">
            </div>
            <div style="flex:1">
                <label class="form-label">SKU / Code</label>
                <input type="text" id="prod_sku" class="form-control">
            </div>
        </div>
    </div>

    <div id="vehicle_entry" class="detail-section vehicle">
        <span class="section-label">🏍️ Vehicle Entry</span>

        <div class="field-row">
            <label class="form-label">Select Vehicle Brand / Model</label>
            <select id="veh_model_master" class="form-select" onchange="fillVehicleModelDetails()">
                <option value="">-- Select Vehicle Model --</option>
                <?php foreach ($vehicleModels as $vm): ?>
                    <?php
                    $vmLabel = trim(($vm['brand_name'] ?? '') . ' / ' . ($vm['model_name'] ?? '') . (!empty($vm['variant_name']) ? ' / ' . $vm['variant_name'] : ''));
                    ?>
                    <option
                        value="<?= (int)$vm['id'] ?>"
                        data-brand="<?= h($vm['brand_name'] ?? '') ?>"
                        data-model="<?= h($vm['model_name'] ?? '') ?>"
                        data-variant="<?= h($vm['variant_name'] ?? '') ?>"
                        data-type="<?= h($vm['vehicle_type'] ?? 'electric') ?>"
                        data-dealer="<?= h($vm['dealer_cost'] ?? 0) ?>"
                        data-exshow="<?= h($vm['ex_showroom_price'] ?? 0) ?>"
                        data-gst="<?= h($vm['gst_percent'] ?? 0) ?>"
                        data-rto="<?= h($vm['rto_charge'] ?? 0) ?>"
                        data-reg="<?= h($vm['registration_price'] ?? 0) ?>"
                        data-roadtax="<?= h($vm['road_tax'] ?? 0) ?>"
                        data-ins="<?= h($vm['insurance_price'] ?? 0) ?>"
                    ><?= h($vmLabel) ?></option>
                <?php endforeach; ?>
            </select>
            <div class="search-hint">Selecting model will auto fill Brand, Model Name, Type, Ex-Showroom Price and GST.</div>
        </div>

        <input type="hidden" id="veh_model_id" value="0">
        <input type="hidden" id="veh_vehicle_type" value="electric">

        <div class="field-row">
            <label class="form-label">Description *</label>
            <input type="text" id="veh_desc" class="form-control">
        </div>

        <div class="field-row">
            <label class="form-label">Ex-Showroom Selling ₹</label>
            <input type="number" id="veh_exshow" class="form-control" value="0.00" step="0.01" min="0">
        </div>

        <div style="display:flex;gap:10px;margin-bottom:12px">
            <div style="flex:1">
                <label class="form-label">Brand</label>
                <input type="text" id="veh_brand" class="form-control" list="brandList">
                <datalist id="brandList">
                    <?php foreach ($vehicleBrands as $vb): ?>
                        <option value="<?= h($vb['brand_name']) ?>">
                    <?php endforeach; ?>
                </datalist>
            </div>

            <div style="flex:1">
                <label class="form-label">Model</label>
                <input type="text" id="veh_model" class="form-control">
            </div>

            <div style="flex:1">
                <label class="form-label">Type</label>
                <input type="text" id="veh_type_display" class="form-control" value="Electric" readonly style="background:#e9ecef;">
            </div>
        </div>

        <div style="display:flex;gap:10px;margin-bottom:12px">
            <div style="flex:1">
                <label class="form-label">Color</label>
                <input type="text" id="veh_color" class="form-control">
            </div>
        </div>

        <div style="display:flex;gap:10px;margin-bottom:12px;flex-wrap:wrap">
            <div style="flex:1;min-width:200px">
                <label class="form-label">Chassis No</label>
                <input type="text" id="veh_chassis" class="form-control" autocomplete="off">
            </div>
            <div style="flex:1;min-width:200px">
                <label class="form-label">Motor No</label>
                <input type="text" id="veh_motor" class="form-control" autocomplete="off">
            </div>
        </div>

        <div style="display:flex;gap:10px;margin-bottom:12px">
            <div style="flex:1">
                <label class="form-label">Battery Brand</label>
                <input type="text" id="veh_battery" class="form-control" list="batteryList" autocomplete="off">
                <datalist id="batteryList">
                    <?php foreach ($batteryBrands as $bb): ?>
                        <option value="<?= h($bb['brand_name']) ?>">
                    <?php endforeach; ?>
                </datalist>
            </div>
            <div style="flex:1">
                <label class="form-label">Battery No</label>
                <input type="text" id="veh_battery_no" class="form-control" autocomplete="off">
            </div>
            <div style="flex:1">
                <label class="form-label">Battery Capacity</label>
                <input type="text" id="veh_battery_capacity" class="form-control" autocomplete="off">
            </div>
        </div>

        <div style="display:flex;gap:10px;margin-bottom:12px">
            <div style="flex:1">
                <label class="form-label">Charger Brand</label>
                <input type="text" id="veh_charger_brand" class="form-control" list="chargerList" autocomplete="off">
            </div>
            <div style="flex:1">
                <label class="form-label">Charger No</label>
                <input type="text" id="veh_charger_no" class="form-control" autocomplete="off">
            </div>
            <div style="flex:1">
                <label class="form-label">Charger Type</label>
                <input type="text" id="veh_charger_type" class="form-control" list="chTypeList" autocomplete="off">
            </div>
        </div>

        <datalist id="chargerList">
            <?php foreach ($chargerBrands as $cb): ?>
                <option value="<?= h($cb['brand_name']) ?>">
            <?php endforeach; ?>
        </datalist>

        <datalist id="chTypeList">
            <?php foreach ($chargerTypes as $ct): ?>
                <option value="<?= h($ct) ?>">
            <?php endforeach; ?>
        </datalist>

        <span class="section-label">🚗 On-Road Pricing</span>

        <div class="field-row">
            <label class="form-label">RTO / Non-RTO</label>
            <select id="veh_rto_mode" class="form-select" onchange="toggleRtoFields()">
                <option value="non_rto">Non-RTO</option>
                <option value="rto">RTO</option>
            </select>
        </div>

        <input type="hidden" id="veh_rto" value="0.00">

        <div id="veh_rto_fields" style="display:flex;gap:10px;margin-bottom:12px;flex-wrap:wrap">
            <div id="veh_registration_field" style="flex:1;min-width:180px;display:none">
                <label class="form-label">Registration ₹</label>
                <input type="number" step="0.01" min="0" id="veh_reg" class="form-control" value="0.00" disabled>
            </div>
            <div style="flex:1;min-width:180px">
                <label class="form-label">Road Tax ₹</label>
                <input type="number" step="0.01" min="0" id="veh_rt" class="form-control" value="0.00">
            </div>
        </div>

        <div style="display:flex;gap:10px;margin-bottom:12px;align-items:end">
            <div style="flex:1">
                <label class="form-label">Insurance ₹</label>
                <input type="number" step="0.01" id="veh_ins" class="form-control" value="0.00">
            </div>
            <div style="flex:1">
                <label class="form-label">GST %</label>
                <input type="number" id="veh_gst" class="form-control" value="0.00" step="0.01">
            </div>
            <div style="flex:1">
                <label class="form-label">GST Type</label>
                <select id="veh_gst_type" class="form-select">
                    <option value="inclusive" selected>Inclusive</option>
                    <option value="exclusive">Exclusive</option>
                </select>
            </div>
            <button type="button" class="btn btn-success" onclick="addV()">➕ Add</button>
        </div>

        <div class="field-row">
            <label class="form-label">Total On-Road</label>
            <input type="text" id="veh_total" class="form-control" value="₹0.00" readonly style="background:#e9ecef;font-weight:bold;text-align:center;font-size:15px">
        </div>
    </div>

    <div id="charger_entry" class="detail-section charger">
        <span class="section-label">🔌 Charger Entry</span>

        <div class="field-row">
            <label class="form-label">Description *</label>
            <input type="text" id="chg_desc" class="form-control">
        </div>

        <div style="display:flex;gap:10px;margin-bottom:12px">
            <div style="flex:1">
                <label class="form-label">Qty</label>
                <input type="number" id="chg_qty" class="form-control" value="1" step="0.01">
            </div>
            <div style="flex:1">
                <label class="form-label">Purchase ₹</label>
                <input type="number" id="chg_purchase" class="form-control" value="0.00" step="0.01">
            </div>
            <div style="flex:1">
                <label class="form-label">Selling ₹</label>
                <input type="number" id="chg_price" class="form-control" value="0.00" step="0.01">
            </div>
        </div>

        <div style="display:flex;gap:10px;margin-bottom:12px;align-items:end">
            <div style="flex:1">
                <label class="form-label">Discount ₹</label>
                <input type="number" id="chg_disc" class="form-control" value="0.00" step="0.01">
            </div>
            <div style="flex:1">
                <label class="form-label">GST %</label>
                <input type="number" id="chg_gst" class="form-control" value="0.00" step="0.01">
            </div>
            <div style="flex:1">
                <label class="form-label">GST Type</label>
                <select id="chg_gst_type" class="form-select">
                    <option value="inclusive" selected>Inclusive</option>
                    <option value="exclusive">Exclusive</option>
                </select>
            </div>
            <button type="button" class="btn btn-success" onclick="addC()">➕ Add</button>
        </div>

        <div style="display:flex;gap:10px">
            <div style="flex:1">
                <label class="form-label">Charger Brand</label>
                <input type="text" id="chg_brand" class="form-control" list="chargerList">
            </div>
            <div style="flex:1">
                <label class="form-label">Charger No</label>
                <input type="text" id="chg_no" class="form-control">
            </div>
            <div style="flex:1">
                <label class="form-label">Charger Type</label>
                <input type="text" id="chg_type" class="form-control" list="chTypeList">
            </div>
        </div>
    </div>
</div>
</div>

</div>

<div class="right-panel">
<div class="card" style="flex:1;display:flex;flex-direction:column;min-height:0">

<div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
    <span>🛒 Cart</span>
    <button type="button" class="btn btn-outline-danger btn-sm" onclick="clearCart()">Clear</button>
</div>

<div class="card-body" style="flex:1;overflow-y:auto;padding:0">
    <table class="table table-bordered align-middle mb-0">
        <thead class="table-light" style="position:sticky;top:0;z-index:1">
            <tr>
                <th>Type</th>
                <th>Description</th>
                <th>Qty</th>
                <th>Cost</th>
                <th>Price</th>
                <th>GST</th>
                <th>Profit</th>
                <th>Total</th>
                <th></th>
            </tr>
        </thead>
        <tbody id="cartBody">
            <tr>
                <td colspan="9" class="cart-empty">
                    <div style="font-size:40px;margin-bottom:10px">🛒</div>
                    No items added<br>
                    <small>Use the form to add items</small>
                </td>
            </tr>
        </tbody>
    </table>
</div>

<div class="card-body" style="border-top:1px solid #e2e8f0;background:#fafafa">
    <div style="display:flex;gap:10px;margin-bottom:12px">
        <div style="flex:1">
            <label class="form-label">Invoice Discount ₹</label>
            <input type="number" step="0.01" name="discount_amount" id="inv_disc" class="form-control" value="<?= h($form['discount_amount']) ?>">
        </div>
        <div style="flex:1">
            <label class="form-label">Round Off ₹</label>
            <input type="number" step="0.01" name="round_off" id="inv_round" class="form-control" value="<?= h($form['round_off']) ?>">
        </div>
    </div>

    <table class="table table-bordered table-sm mb-3 bg-white">
        <tr>
            <td style="width:50%">Subtotal</td>
            <td class="text-end" id="subtotal_v">0.00</td>
        </tr>
        <tr>
            <td>CGST</td>
            <td class="text-end" id="cgst_v">0.00</td>
        </tr>
        <tr>
            <td>SGST</td>
            <td class="text-end" id="sgst_v">0.00</td>
        </tr>
        <tr class="table-primary">
            <td><strong>Grand Total</strong></td>
            <td class="text-end" id="grand_v"><strong style="font-size:16px">₹0.00</strong></td>
        </tr>
    </table>

    <div style="display:flex;gap:10px">
        <button type="submit" class="btn btn-success btn-lg" style="flex:1">
            <i class="ri-save-line me-1"></i>Save Invoice
        </button>
        <a href="sales-invoices.php" class="btn btn-outline-secondary">Cancel</a>
    </div>
</div>

</div>
</div>

</div>
</form>

</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
let cart = [];
let allProductOptions = [];
const vehicleModels = <?= json_encode($vehicleModels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const BRANCH_INVOICE_RULES = {
    1: { invoiceType: 'vehicle_sale', itemType: 'vehicle', invoiceLabel: 'Vehicle', message: 'Branch 1 allows only Vehicle invoices.' },
    2: { invoiceType: 'product_sale', itemType: 'product', invoiceLabel: 'Product', message: 'Branch 2 allows only Product invoices.' }
};

document.addEventListener('DOMContentLoaded', function () {
    // Store all stock product checkbox cards for filtering
    allProductOptions = Array.from(document.querySelectorAll('.stock-product-checkbox'));
    updateStockSelectedCount();
    applyBranchInvoiceRule(false);
    
    syncItemTypeWithInvoiceType();
    toggleSection();
    toggleProductSource();
    clearOptionalVehicleFieldsOnPageLoad();
    toggleRtoFields();

    [
        'prod_qty','prod_purchase','prod_price',
        'chg_qty','chg_purchase','chg_price',
        'veh_exshow','veh_reg','veh_rt','veh_ins','veh_gst'
    ].forEach(function(id){
        const el = document.getElementById(id);
        if (el) {
            el.addEventListener('input', function(){
                calcPP();
                calcCP();
                calcVP();
                calcVOR();
            });
        }
    });

    const invDisc = document.getElementById('inv_disc');
    const invRound = document.getElementById('inv_round');
    if (invDisc) invDisc.addEventListener('input', calcAll);
    if (invRound) invRound.addEventListener('input', calcAll);

    const vehBrandEl = document.getElementById('veh_brand');
    if (vehBrandEl) {
        vehBrandEl.addEventListener('change', fillVehicleModelByBrandName);
    }
});


function getBranchInvoiceRule() {
    const branchId = parseInt(val('branch_id'), 10) || 0;
    return BRANCH_INVOICE_RULES[branchId] || { invoiceType: 'product_sale', itemType: 'product', invoiceLabel: 'Product', message: '' };
}

function applyBranchInvoiceRule(clearInvalidCart) {
    const rule = getBranchInvoiceRule();
    const invoiceTypeEl = document.getElementById('invoice_type');
    const itemTypeEl = document.getElementById('item_type');

    if (invoiceTypeEl) {
        // Keep all invoice type options visible in dropdown.
        Array.from(invoiceTypeEl.options).forEach(function(opt) {
            opt.hidden = false;
            opt.disabled = false;
        });

        // Branch-wise default selection only:
        // Branch 1 -> Vehicle, Branch 2 -> Product.
        invoiceTypeEl.disabled = false;
        invoiceTypeEl.value = rule.invoiceType;
    }

    if (itemTypeEl) {
        // Keep all item type options visible and selectable.
        Array.from(itemTypeEl.options).forEach(function(opt) {
            opt.hidden = false;
            opt.disabled = false;
        });

        itemTypeEl.disabled = false;
        itemTypeEl.value = rule.itemType;
    }

    filterStockProducts();
    toggleSection();
}

function ensureItemAllowedForSelectedBranch(itemType) {
    // All invoice type dropdown options are available, so all item types are allowed.
    return true;
}

function syncItemTypeWithInvoiceType() {
    const invoiceTypeEl = document.getElementById('invoice_type');
    const itemTypeEl = document.getElementById('item_type');
    if (!invoiceTypeEl || !itemTypeEl) return;

    // User can choose any invoice type. We only auto-open the matching entry section.
    if (invoiceTypeEl.value === 'vehicle_sale') {
        itemTypeEl.value = 'vehicle';
    } else if (invoiceTypeEl.value === 'product_sale') {
        itemTypeEl.value = 'product';
    }

    itemTypeEl.disabled = false;
    toggleSection();
}

function syncInvoiceTypeFromItemType() {
    applyBranchInvoiceRule(false);
    const invoiceTypeEl = document.getElementById('invoice_type');
    const itemTypeEl = document.getElementById('item_type');
    if (!invoiceTypeEl || !itemTypeEl) return;

    if (invoiceTypeEl.value === 'mixed_sale') return;

    if (itemTypeEl.value === 'vehicle') {
        invoiceTypeEl.value = 'vehicle_sale';
    } else if (itemTypeEl.value === 'product') {
        invoiceTypeEl.value = 'product_sale';
    }
}

function toggleSection() {
    const t = document.getElementById('item_type').value;
    document.getElementById('product_entry').style.display = t === 'product' ? 'block' : 'none';
    document.getElementById('vehicle_entry').style.display = t === 'vehicle' ? 'block' : 'none';
    document.getElementById('charger_entry').style.display = t === 'charger' ? 'block' : 'none';
}

function toggleProductSource() {
    const source = val('product_source');
    document.getElementById('stockProductBox').style.display = source === 'stock' ? 'block' : 'none';

    const isStock = source === 'stock';
    document.getElementById('prod_name').readOnly = isStock;
    document.getElementById('prod_brand').readOnly = isStock;
    document.getElementById('prod_cat').readOnly = isStock;
    document.getElementById('prod_hsn').readOnly = isStock;
    document.getElementById('prod_sku').readOnly = isStock;

    if (!isStock) {
        clearStockProductSelection();
        document.getElementById('stockProductInfo').style.display = 'none';
        clearProduct(false);
    }
}

function filterStockProducts() {
    const search = val('stock_product_search').toLowerCase().trim();
    const labels = Array.from(document.querySelectorAll('.stock-product-option'));
    let visibleCount = 0;

    labels.forEach(function(label) {
        const cb = label.querySelector('.stock-product-checkbox');
        if (!cb) return;

        const haystack = [
            label.getAttribute('data-search') || '',
            cb.getAttribute('data-name') || '',
            cb.getAttribute('data-code') || '',
            cb.getAttribute('data-item-code') || '',
            cb.getAttribute('data-brand') || '',
            cb.getAttribute('data-hsn') || '',
            cb.getAttribute('data-category') || ''
        ].join(' ').toLowerCase();

        const productName = (cb.getAttribute('data-name') || '').toLowerCase();
        const matchesFirstTwo = search.length <= 2
            ? productName.startsWith(search)
            : productName.startsWith(search.substring(0, 2));
        const containsSearch = haystack.includes(search);
        const selectedBranchId = parseInt(val('branch_id'), 10) || 0;
        const productBranchId = parseInt(cb.getAttribute('data-branch-id') || '0', 10) || 0;
        const branchMatches = productBranchId === 0 || selectedBranchId === 0 || productBranchId === selectedBranchId;
        const show = branchMatches && (search === '' || matchesFirstTwo || containsSearch);

        if (!show && cb.checked) cb.checked = false;
        label.style.display = show ? 'grid' : 'none';
        if (show) visibleCount++;
    });

    if (visibleCount === 1) {
        const onlyVisible = labels.find(function(label){ return label.style.display !== 'none'; });
        const cb = onlyVisible ? onlyVisible.querySelector('.stock-product-checkbox') : null;
        if (cb) {
            cb.checked = true;
            fillStockProduct();
        }
    } else {
        updateStockProductInfo();
        updateStockSelectedCount();
    }
}

function getSelectedStockProductOptions() {
    return Array.from(document.querySelectorAll('.stock-product-checkbox:checked')).filter(function(cb) {
        const label = cb ? cb.closest('.stock-product-option') : null;
        return cb && cb.value && (!label || label.style.display !== 'none');
    });
}

function getVisibleStockProductOptions() {
    return Array.from(document.querySelectorAll('.stock-product-option'))
        .filter(function(label){ return label.style.display !== 'none'; })
        .map(function(label){ return label.querySelector('.stock-product-checkbox'); })
        .filter(function(cb){ return cb && cb.value; });
}

function selectVisibleStockProducts() {
    const visible = getVisibleStockProductOptions();
    visible.forEach(function(cb){ cb.checked = true; });
    fillStockProduct();
}

function clearStockProductSelection() {
    document.querySelectorAll('.stock-product-checkbox').forEach(function(cb) {
        cb.checked = false;
    });
    updateStockProductInfo();
    updateStockSelectedCount();
}

function updateStockSelectedCount() {
    const el = document.getElementById('stockSelectedCount');
    if (!el) return;
    el.textContent = getSelectedStockProductOptions().length + ' selected';
}

function fillProductFieldsFromOption(opt) {
    if (!opt || !opt.value) return;

    const productName = opt.getAttribute('data-name') || '';
    const productCode = opt.getAttribute('data-code') || '';
    const itemCode = opt.getAttribute('data-item-code') || '';
    const brand = opt.getAttribute('data-brand') || '';
    const hsn = opt.getAttribute('data-hsn') || '';
    const category = opt.getAttribute('data-category') || '';
    const purchase = parseFloat(opt.getAttribute('data-purchase') || '0') || 0;
    const selling = parseFloat(opt.getAttribute('data-selling') || '0') || 0;
    const gst = parseFloat(opt.getAttribute('data-gst') || '0') || 0;

    setVal('prod_desc', productName);
    setVal('prod_name', productName);
    setVal('prod_brand', brand);
    setVal('prod_cat', category);
    setVal('prod_hsn', hsn);
    setVal('prod_sku', productCode || itemCode);
    setVal('prod_purchase', purchase.toFixed(2));
    setVal('prod_price', selling.toFixed(2));
    setVal('prod_gst', gst.toFixed(2));
}

function updateStockProductInfo() {
    updateStockSelectedCount();
    const selected = getSelectedStockProductOptions();
    const info = document.getElementById('stockProductInfo');
    if (!info) return;

    if (selected.length === 0) {
        info.style.display = 'none';
        info.innerHTML = '';
        return;
    }

    info.style.display = 'block';

    if (selected.length === 1) {
        const opt = selected[0];
        const productName = opt.getAttribute('data-name') || '';
        const productCode = opt.getAttribute('data-code') || '';
        const itemCode = opt.getAttribute('data-item-code') || '';
        const brand = opt.getAttribute('data-brand') || '';
        const gst = parseFloat(opt.getAttribute('data-gst') || '0') || 0;
        const stock = parseFloat(opt.getAttribute('data-stock') || '0') || 0;

        info.innerHTML = `
            <strong>Selected:</strong> ${escapeHtml(productName)}<br>
            <strong>Code:</strong> ${escapeHtml(productCode || itemCode || '-')} |
            <strong>Brand:</strong> ${escapeHtml(brand || '-')} |
            <strong>Stock:</strong> ${stock.toFixed(2)} |
            <strong>GST:</strong> ${gst.toFixed(2)}%
        `;
        return;
    }

    const names = selected.slice(0, 5).map(function(opt) {
        return escapeHtml(opt.getAttribute('data-name') || opt.textContent || 'Product');
    }).join('<br>');

    info.innerHTML = `
        <strong>${selected.length} stock products selected.</strong><br>
        <small>${names}${selected.length > 5 ? '<br>+' + (selected.length - 5) + ' more' : ''}</small><br>
        <small class="text-muted">Click Add to add selected products into the cart.</small>
    `;
}

function fillStockProduct() {
    const selected = getSelectedStockProductOptions();
    if (selected.length === 0) {
        updateStockProductInfo();
        return;
    }

    // Show details of the first selected product in the product input fields.
    fillProductFieldsFromOption(selected[0]);
    updateStockProductInfo();
}



function fillVehicleModelByBrandName() {
    const brandName = val('veh_brand').toLowerCase();
    if (!brandName) return;

    const match = vehicleModels.find(function(vm) {
        return String(vm.brand_name || '').toLowerCase() === brandName;
    });
    if (!match) return;

    const sel = document.getElementById('veh_model_master');
    if (sel) {
        sel.value = String(match.id || '');
        fillVehicleModelDetails();
    }
}

function fillVehicleModelDetails() {
    const sel = document.getElementById('veh_model_master');
    if (!sel || !sel.value) {
        setVal('veh_model_id', '0');
        return;
    }

    const opt = sel.selectedOptions[0];
    const brand = opt.getAttribute('data-brand') || '';
    const model = opt.getAttribute('data-model') || '';
    const variant = opt.getAttribute('data-variant') || '';
    const vehicleType = opt.getAttribute('data-type') || 'electric';
    const dealer = parseFloat(opt.getAttribute('data-dealer') || '0') || 0;
    const exshow = parseFloat(opt.getAttribute('data-exshow') || '0') || 0;
    const gst = parseFloat(opt.getAttribute('data-gst') || '0') || 0;
    const rto = parseFloat(opt.getAttribute('data-rto') || '0') || 0;
    const reg = parseFloat(opt.getAttribute('data-reg') || '0') || 0;
    const roadTax = parseFloat(opt.getAttribute('data-roadtax') || '0') || 0;
    const ins = parseFloat(opt.getAttribute('data-ins') || '0') || 0;
    const fullModel = [model, variant].filter(Boolean).join(' - ');
    const description = [brand, fullModel].filter(Boolean).join(' ');

    setVal('veh_model_id', sel.value);
    setVal('veh_vehicle_type', vehicleType);
    setVal('veh_type_display', vehicleType.charAt(0).toUpperCase() + vehicleType.slice(1));
    setVal('veh_brand', brand);
    setVal('veh_model', fullModel || model);
    setVal('veh_desc', description || fullModel || model || 'Vehicle');
    setVal('veh_exshow', exshow.toFixed(2));
    setVal('veh_gst', gst.toFixed(2));
    const selectedRtoMode = val('veh_rto_mode') || 'non_rto';
    setVal('veh_rto', '0.00');

    if (selectedRtoMode === 'rto') {
        setVal('veh_reg', reg.toFixed(2));
    } else {
        setVal('veh_reg', '0.00');
    }
    setVal('veh_rt', roadTax.toFixed(2));

    toggleRtoFields();
    setVal('veh_ins', ins.toFixed(2));

    calcVP();
    calcVOR();
}

function money(v) {
    return '₹' + Number(v || 0).toFixed(2);
}

function val(id) {
    const el = document.getElementById(id);
    return el ? el.value.trim() : '';
}

function num(id) {
    return parseFloat(val(id)) || 0;
}

function setVal(id, v) {
    const el = document.getElementById(id);
    if (el) el.value = v;
}

function calcPP() {
    const q = num('prod_qty') || 1;
    const p = num('prod_purchase');
    const s = num('prod_price');
    return (s - p) * q;
}

function calcCP() {
    const q = num('chg_qty') || 1;
    const p = num('chg_purchase');
    const s = num('chg_price');
    return (s - p) * q;
}

function calcVP() {
    return 0;
}

function toggleRtoFields() {
    const mode = val('veh_rto_mode') || 'non_rto';
    const isRto = mode === 'rto';
    const registrationField = document.getElementById('veh_registration_field');
    const registrationInput = document.getElementById('veh_reg');
    const roadTaxInput = document.getElementById('veh_rt');

    if (registrationField) {
        registrationField.style.display = isRto ? 'block' : 'none';
    }

    setVal('veh_rto', '0.00');

    if (registrationInput) {
        registrationInput.disabled = !isRto;
        if (!isRto) setVal('veh_reg', '0.00');
    }

    // Road Tax is visible and enabled for both RTO and Non-RTO.
    if (roadTaxInput) roadTaxInput.disabled = false;

    calcVOR();
}

function calcVOR() {
    const total = num('veh_exshow') + num('veh_rto') + num('veh_reg') + num('veh_rt') + num('veh_ins');
    setVal('veh_total', money(total));
    return total;
}

function makeStockProductCartItem(opt, qty, discount, gstType) {
    const productName = opt.getAttribute('data-name') || '';
    const productCode = opt.getAttribute('data-code') || '';
    const itemCode = opt.getAttribute('data-item-code') || '';
    const brand = opt.getAttribute('data-brand') || '';
    const hsn = opt.getAttribute('data-hsn') || '';
    const category = opt.getAttribute('data-category') || '';
    const purchase = parseFloat(opt.getAttribute('data-purchase') || '0') || 0;
    const selling = parseFloat(opt.getAttribute('data-selling') || '0') || 0;
    const gst = parseFloat(opt.getAttribute('data-gst') || '0') || 0;

    return {
        t: 'product',
        productSource: 'stock',
        stockProductId: opt.value,
        desc: productName,
        qty: qty,
        pPrice: purchase,
        price: selling,
        disc: discount,
        gst: gst,
        gType: gstType,
        profit: (selling - purchase) * qty,
        cat: category,
        pname: productName,
        pbrand: brand,
        hsn: hsn,
        sku: productCode || itemCode
    };
}

function addP() {
    if (!ensureItemAllowedForSelectedBranch('product')) return;
    const source = val('product_source');
    const qty = num('prod_qty') || 1;
    const discount = num('prod_disc');
    const gstType = val('prod_gst_type');

    if (source === 'stock') {
        const selected = getSelectedStockProductOptions();

        if (selected.length === 0) {
            alert('Select at least one stock product');
            return;
        }

        selected.forEach(function(opt) {
            const item = makeStockProductCartItem(opt, qty, discount, gstType);
            if (item.price > 0) {
                cart.push(item);
            }
        });

        renderCart();
        clearProduct(true);
        return;
    }

    const desc = val('prod_desc');

    if (!desc) {
        alert('Enter product description');
        return;
    }

    const selling = num('prod_price');

    if (selling <= 0) {
        alert('Enter selling price');
        return;
    }

    cart.push({
        t: 'product',
        productSource: source,
        stockProductId: '',
        desc: desc,
        qty: qty,
        pPrice: num('prod_purchase'),
        price: selling,
        disc: discount,
        gst: num('prod_gst'),
        gType: gstType,
        profit: calcPP(),
        cat: val('prod_cat'),
        pname: val('prod_name'),
        pbrand: val('prod_brand'),
        hsn: val('prod_hsn'),
        sku: val('prod_sku')
    });

    renderCart();
    clearProduct(true);
}

function addV() {
    if (!ensureItemAllowedForSelectedBranch('vehicle')) return;
    const desc = val('veh_desc');

    if (!desc) {
        alert('Enter vehicle description');
        return;
    }

    const ex = num('veh_exshow');
    if (ex <= 0) {
        alert('Enter ex-showroom selling price');
        return;
    }

    const total = calcVOR();

    cart.push({
        t: 'vehicle',
        desc: desc,
        qty: 1,
        pPrice: 0,
        price: total,
        disc: 0,
        gst: num('veh_gst'),
        gType: val('veh_gst_type'),
        profit: 0,
        brand: val('veh_brand'),
        model: val('veh_model'),
        modelId: val('veh_model_id'),
        vehicleType: val('veh_vehicle_type') || 'electric',
        color: val('veh_color'),
        chassis: val('veh_chassis'),
        engine: '',
        motor: val('veh_motor'),
        battery: val('veh_battery'),
        batteryNo: val('veh_battery_no'),
        batteryCapacity: val('veh_battery_capacity'),
        chargerBrand: val('veh_charger_brand'),
        chargerNo: val('veh_charger_no'),
        chargerType: val('veh_charger_type'),
        exshow: ex,
        excost: 0,
        rto: 0,
        rtoMode: val('veh_rto_mode') || 'non_rto',
        reg: (val('veh_rto_mode') === 'rto') ? num('veh_reg') : 0,
        roadTax: num('veh_rt'),
        ins: num('veh_ins')
    });

    renderCart();
    clearVehicle();
}

function addC() {
    if (!ensureItemAllowedForSelectedBranch('charger')) return;
    const desc = val('chg_desc');

    if (!desc) {
        alert('Enter charger description');
        return;
    }

    const qty = num('chg_qty') || 1;
    const selling = num('chg_price');

    if (selling <= 0) {
        alert('Enter selling price');
        return;
    }

    cart.push({
        t: 'charger',
        desc: desc,
        qty: qty,
        pPrice: num('chg_purchase'),
        price: selling,
        disc: num('chg_disc'),
        gst: num('chg_gst'),
        gType: val('chg_gst_type'),
        profit: calcCP(),
        cBrand: val('chg_brand'),
        cNo: val('chg_no'),
        cType: val('chg_type')
    });

    renderCart();
    clearCharger();
}

function calcItem(it) {
    let lineAmount = it.qty * it.price;
    let taxable;

    if (it.gType === 'inclusive' && it.gst > 0) {
        taxable = lineAmount / (1 + (it.gst / 100));
    } else {
        taxable = lineAmount;
    }

    taxable -= it.disc || 0;
    if (taxable < 0) taxable = 0;

    let tax = taxable * (it.gst / 100);
    let total = taxable + tax;

    if (it.gType === 'inclusive') {
        total = Math.max(0, lineAmount - (it.disc || 0));
    }

    return {
        taxable: taxable,
        tax: tax,
        total: total
    };
}

function renderCart() {
    const tb = document.getElementById('cartBody');
    tb.innerHTML = '';

    document.querySelectorAll('#invoiceForm input[name^="items["]').forEach(function(e){
        e.remove();
    });

    if (cart.length === 0) {
        tb.innerHTML = `
            <tr>
                <td colspan="9" class="cart-empty">
                    <div style="font-size:40px;margin-bottom:10px">🛒</div>
                    No items added<br>
                    <small>Use the form to add items</small>
                 </td>
             </tr>
        `;
        calcAll();
        return;
    }

    cart.forEach(function(it, i) {
        const c = calcItem(it);
        const badge = it.t === 'product' ? 'primary' : (it.t === 'vehicle' ? 'success' : 'warning');
        const ico = it.t === 'product' ? '🛍️' : (it.t === 'vehicle' ? '🏍️' : '🔌');

        let det = '';

        if (it.t === 'product') {
            det = `${it.productSource === 'stock' ? 'Stock Product' : 'Manual Product'} | ${it.pname || ''} ${it.pbrand || ''} ${it.cat ? '| ' + it.cat : ''}`;
        } else if (it.t === 'vehicle') {
            det = `${it.brand || ''} ${it.model || ''} ${it.color ? '(' + it.color + ')' : ''} ${it.chassis ? '| Ch: ' + it.chassis : ''}`;
        } else {
            det = `${it.cBrand || ''} ${it.cType ? '| ' + it.cType : ''} ${it.cNo ? '| No: ' + it.cNo : ''}`;
        }

        const tr = document.createElement('tr');
        if (it.t === 'vehicle') tr.classList.add('vehicle-row');

        tr.innerHTML = `
            <td><span class="badge bg-${badge}">${ico}</span></td>
            <td>
                <strong>${escapeHtml(it.desc)}</strong>
                ${det ? '<br><small class="text-muted">' + escapeHtml(det) + '</small>' : ''}
            </td>
            <td>${it.qty}</td>
            <td>${money(it.pPrice)}</td>
            <td>${money(it.price)}</td>
            <td>${it.gst}% (${it.gType === 'inclusive' ? 'In' : 'Ex'})</td>
            <td style="font-weight:600;color:${it.profit >= 0 ? '#166534' : '#dc2626'}">${money(it.profit)}</td>
            <td><strong>${money(c.total)}</strong></td>
            <td><button type="button" class="btn btn-danger btn-sm" onclick="removeItem(${i})">×</button></td>
        `;

        tb.appendChild(tr);

        const hiddenFields = {
            item_type: it.t,
            product_source: it.productSource || '',
            stock_product_id: it.stockProductId || 0,
            description: it.desc,
            qty: it.qty,
            unit_price: it.price,
            purchase_price: it.pPrice,
            discount_amount: it.disc || 0,
            gst_percent: it.gst || 0,
            gst_type: it.gType || 'inclusive',

            category: it.cat || '',
            product_name: it.pname || '',
            product_brand: it.pbrand || '',
            hsn_code: it.hsn || '',
            sku: it.sku || '',

            brand_name: it.brand || '',
            model_name: it.model || '',
            vehicle_model_id: it.modelId || 0,
            vehicle_type: it.vehicleType || 'electric',
            color: it.color || '',
            chassis_no: it.chassis || '',
            engine_no: it.engine || '',
            motor_no: it.motor || '',

            battery_brand: it.battery || '',
            battery_no: it.batteryNo || '',
            battery_capacity: it.batteryCapacity || '',

            charger_brand: it.chargerBrand || it.cBrand || '',
            charger_no: it.chargerNo || it.cNo || '',
            charger_type: it.chargerType || it.cType || '',

            ex_showroom: it.exshow || 0,
            ex_showroom_cost: it.excost || 0,
            rto_charge: it.rto || 0,
            registration_price: it.reg || 0,
            road_tax_amount: it.roadTax || 0,
            rto_mode: it.rtoMode || 'non_rto',
            insurance_amount: it.ins || 0
        };

        Object.keys(hiddenFields).forEach(function(k) {
            addHidden(`items[${i}][${k}]`, hiddenFields[k]);
        });
    });

    calcAll();
}

function addHidden(name, value) {
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = name;
    input.value = value;
    document.getElementById('invoiceForm').appendChild(input);
}

function removeItem(i) {
    cart.splice(i, 1);
    renderCart();
}

function clearCart() {
    if (confirm('Clear cart?')) {
        cart = [];
        renderCart();
    }
}

function calcAll() {
    let subtotal = 0;
    let cgst = 0;
    let sgst = 0;
    let profit = 0;

    cart.forEach(function(it) {
        const c = calcItem(it);
        subtotal += c.taxable;
        cgst += c.tax / 2;
        sgst += c.tax / 2;
        profit += it.profit || 0;
    });

    const disc = num('inv_disc');
    const round = num('inv_round');
    let grand = subtotal + cgst + sgst - disc + round;
    if (grand < 0) grand = 0;

    document.getElementById('subtotal_v').innerText = subtotal.toFixed(2);
    document.getElementById('cgst_v').innerText = cgst.toFixed(2);
    document.getElementById('sgst_v').innerText = sgst.toFixed(2);
    const profitEl = document.getElementById('profit_v');
    if (profitEl) {
        profitEl.innerText = money(profit);
        profitEl.style.color = profit >= 0 ? '#166534' : '#dc2626';
    }
    document.getElementById('grand_v').innerHTML = '<strong style="font-size:16px">' + money(grand) + '</strong>';
}

function clearProduct(resetSource = true) {
    ['prod_desc','prod_cat','prod_name','prod_brand','prod_hsn','prod_sku'].forEach(id => setVal(id, ''));
    setVal('prod_qty', '1');
    setVal('prod_purchase', '0.00');
    setVal('prod_price', '0.00');
    setVal('prod_disc', '0.00');
    setVal('prod_gst', '0.00');

    if (resetSource) {
        setVal('stock_product_search', '');
        document.querySelectorAll('.stock-product-option').forEach(function(label){
            label.style.display = 'grid';
        });
        clearStockProductSelection();
        document.getElementById('stockProductInfo').style.display = 'none';
    }
}

function clearVehicle() {
    [
        'veh_desc','veh_brand','veh_model','veh_color','veh_chassis',
        'veh_motor','veh_battery','veh_battery_no','veh_battery_capacity',
        'veh_charger_brand','veh_charger_no','veh_charger_type'
    ].forEach(id => setVal(id, ''));

    ['veh_exshow','veh_reg','veh_rt','veh_ins','veh_gst','veh_gst'].forEach(id => setVal(id, '0.00'));
    setVal('veh_rto_mode', 'non_rto');
    toggleRtoFields();
    setVal('veh_total', '₹0.00');
}

function clearCharger() {
    ['chg_desc','chg_brand','chg_no','chg_type'].forEach(id => setVal(id, ''));
    setVal('chg_qty', '1');
    setVal('chg_purchase', '0.00');
    setVal('chg_price', '0.00');
    setVal('chg_disc', '0.00');
    setVal('chg_gst', '0.00');
}

function escapeHtml(text) {
    return String(text || '').replace(/[&<>"']/g, function(m) {
        return ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        })[m];
    });
}


function clearOptionalVehicleFieldsOnPageLoad() {
    [
        'veh_chassis','veh_motor','veh_battery','veh_battery_no',
        'veh_battery_capacity','veh_charger_brand','veh_charger_no','veh_charger_type'
    ].forEach(function(id) {
        setVal(id, '');
        const el = document.getElementById(id);
        if (el) {
            el.setAttribute('autocomplete', 'off');
        }
    });
}

function visibleElement(id) {
    const el = document.getElementById(id);
    if (!el) return false;
    return !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length);
}

function getCurrentEntryTypeForSubmit() {
    const itemType = val('item_type');
    if (itemType === 'vehicle' && visibleElement('vehicle_entry')) return 'vehicle';
    if (itemType === 'product' && visibleElement('product_entry')) return 'product';
    if (itemType === 'charger' && visibleElement('charger_entry')) return 'charger';
    return itemType || 'product';
}

function autoAddCurrentItemIfCartEmpty() {
    if (cart.length > 0) {
        renderCart();
        return true;
    }

    const entryType = getCurrentEntryTypeForSubmit();

    if (entryType === 'vehicle') {
        if (!val('veh_desc')) {
            const autoDesc = [val('veh_brand'), val('veh_model')].filter(Boolean).join(' ').trim();
            if (autoDesc) setVal('veh_desc', autoDesc);
        }
        if (val('veh_desc') && num('veh_exshow') > 0) {
            addV();
            return cart.length > 0;
        }
    }

    if (entryType === 'product') {
        const source = val('product_source');
        if (source === 'stock' && getSelectedStockProductOptions().length > 0) {
            addP();
            return cart.length > 0;
        }
        if (!val('prod_desc')) {
            const autoDesc = [val('prod_brand'), val('prod_name')].filter(Boolean).join(' ').trim();
            if (autoDesc) setVal('prod_desc', autoDesc);
        }
        if (val('prod_desc') && num('prod_price') > 0) {
            addP();
            return cart.length > 0;
        }
    }

    if (entryType === 'charger') {
        if (!val('chg_desc')) {
            const autoDesc = [val('chg_brand'), val('chg_type')].filter(Boolean).join(' ').trim();
            if (autoDesc) setVal('chg_desc', autoDesc);
        }
        if (val('chg_desc') && num('chg_price') > 0) {
            addC();
            return cart.length > 0;
        }
    }

    return false;
}


const invoiceFormEl = document.getElementById('invoiceForm');
if (invoiceFormEl) {
    invoiceFormEl.addEventListener('submit', function(e) {
        const invoiceTypeEl = document.getElementById('invoice_type');
        if (invoiceTypeEl) {
            invoiceTypeEl.disabled = false;
        }

        if (cart.length === 0) {
            autoAddCurrentItemIfCartEmpty();
        }

        if (cart.length === 0) {
            e.preventDefault();
            alert('Please click Add after entering item details, then save the invoice.');
            return false;
        }

        renderCart();
    });
}
</script>
</body>
</html>