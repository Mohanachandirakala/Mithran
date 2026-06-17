<?php
session_start();
date_default_timezone_set('Asia/Kolkata');

require_once 'includes/config.php';

if (!isset($conn) || !($conn instanceof mysqli)) die('Database connection not available.');
$conn->set_charset('utf8mb4');

$businessUserId = (int)($_SESSION['business_user_id'] ?? ($_SESSION['user_id'] ?? 0));
$businessId     = (int)($_SESSION['business_id'] ?? 0);
$currentBranchId = (int)($_SESSION['branch_id'] ?? 0);

if ($businessUserId <= 0 || $businessId <= 0) {
    header('Location: login.php');
    exit;
}

$invoiceId = (int)($_GET['id'] ?? 0);
if ($invoiceId <= 0) {
    header('Location: sales-invoices.php?error=' . urlencode('Invalid invoice ID.'));
    exit;
}

function h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }

function tableExists(mysqli $conn, string $table): bool {
    $stmt = $conn->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1");
    if (!$stmt) return false;
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $ok = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $ok;
}

function columnExists(mysqli $conn, string $table, string $column): bool {
    $stmt = $conn->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
    if (!$stmt) return false;
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $ok = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $ok;
}

function fetchAllAssoc(mysqli $conn, string $sql): array {
    $rows = [];
    $res = $conn->query($sql);
    if ($res) {
        while ($r = $res->fetch_assoc()) $rows[] = $r;
        $res->free();
    }
    return $rows;
}

function getOrCreateProductCategory(mysqli $conn, string $categoryName): int {
    $categoryName = trim($categoryName);
    if ($categoryName === '' || !tableExists($conn, 'product_categories')) return 0;

    $stmt = $conn->prepare("SELECT id FROM product_categories WHERE category_name = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('s', $categoryName);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) return (int)$row['id'];
    }

    $stmt = $conn->prepare("INSERT INTO product_categories (category_name) VALUES (?)");
    if (!$stmt) return 0;
    $stmt->bind_param('s', $categoryName);
    $stmt->execute();
    $id = (int)$conn->insert_id;
    $stmt->close();
    return $id;
}

function getOrCreateManualProduct(mysqli $conn, int $businessId, array $item): int {
    if (!tableExists($conn, 'products')) return 0;

    $productName = trim($item['product_name'] ?: $item['description']);
    if ($productName === '') return 0;

    $sku = trim($item['sku'] ?? '');
    $categoryId = getOrCreateProductCategory($conn, (string)($item['category'] ?? ''));

    if ($sku !== '') {
        $stmt = $conn->prepare("SELECT id FROM products WHERE business_id = ? AND (product_code = ? OR item_code = ?) LIMIT 1");
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

    $stmt = $conn->prepare("
        INSERT INTO products (
            business_id, category_id, product_name, product_code, item_code, brand_name,
            hsn_code, product_type, unit, purchase_price, selling_price, mrp,
            gst_percent, stock_qty, status, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    if (!$stmt) return 0;

    $stmt->bind_param(
        'iisssssssdddddi',
        $businessId, $categoryId, $productName, $productCode, $itemCode, $brandName,
        $hsnCode, $productType, $unit, $purchasePrice, $sellingPrice, $sellingPrice,
        $gstPercent, $stockQty, $status
    );

    if (!$stmt->execute()) {
        $stmt->close();
        return 0;
    }

    $id = (int)$conn->insert_id;
    $stmt->close();
    return $id;
}

function getOrCreateVehicleBrand(mysqli $conn, string $brandName): int {
    $brandName = trim($brandName);
    if ($brandName === '') $brandName = 'General';

    $stmt = $conn->prepare("SELECT id FROM vehicle_brands WHERE brand_name = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('s', $brandName);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) return (int)$row['id'];
    }

    $stmt = $conn->prepare("INSERT INTO vehicle_brands (brand_name, status, created_at) VALUES (?, 1, NOW())");
    if (!$stmt) throw new Exception('Vehicle brand insert failed: ' . $conn->error);
    $stmt->bind_param('s', $brandName);
    if (!$stmt->execute()) throw new Exception('Vehicle brand save failed: ' . $stmt->error);

    $id = (int)$conn->insert_id;
    $stmt->close();
    return $id;
}

function getOrCreateVehicleCategory(mysqli $conn): int {
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
    if (!$stmt) throw new Exception('Vehicle category insert failed: ' . $conn->error);
    $stmt->bind_param('s', $categoryName);
    if (!$stmt->execute()) throw new Exception('Vehicle category save failed: ' . $stmt->error);

    $id = (int)$conn->insert_id;
    $stmt->close();
    return $id;
}

function getOrCreateVehicleModel(mysqli $conn, int $businessId, string $brandName, string $modelName, float $exShowroom, float $gstPercent): int {
    $modelName = trim($modelName) ?: 'Vehicle';
    $brandId = getOrCreateVehicleBrand($conn, $brandName);
    $categoryId = getOrCreateVehicleCategory($conn);

    $stmt = $conn->prepare("SELECT id FROM vehicle_models WHERE business_id = ? AND brand_id = ? AND model_name = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('iis', $businessId, $brandId, $modelName);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) return (int)$row['id'];
    }

    $variant = '';
    $vehicleType = 'electric';
    $fuelType = 'electric';
    $transmission = 'automatic';

    $stmt = $conn->prepare("
        INSERT INTO vehicle_models (
            business_id, brand_id, category_id, model_name, variant_name,
            vehicle_type, fuel_type, transmission, ex_showroom_price,
            gst_percent, status, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())
    ");
    if (!$stmt) throw new Exception('Vehicle model insert failed: ' . $conn->error);

    $stmt->bind_param(
        'iiisssssdd',
        $businessId, $brandId, $categoryId, $modelName, $variant,
        $vehicleType, $fuelType, $transmission, $exShowroom, $gstPercent
    );

    if (!$stmt->execute()) throw new Exception('Vehicle model save failed: ' . $stmt->error);

    $id = (int)$conn->insert_id;
    $stmt->close();
    return $id;
}

/* Validate login */
$loggedUser = null;
if (tableExists($conn, 'business_users') && tableExists($conn, 'businesses')) {
    $stmt = $conn->prepare("
        SELECT bu.id, bu.full_name, bu.role, bu.status, b.business_name, b.status AS business_status
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

if (!$loggedUser || (int)($loggedUser['status'] ?? 0) !== 1 || ($loggedUser['business_status'] ?? '') !== 'active') {
    session_destroy();
    header('Location: login.php');
    exit;
}

/* Fetch invoice */
$stmt = $conn->prepare("
    SELECT si.*, c.full_name AS customer_name, c.mobile AS customer_mobile
    FROM sales_invoices si
    LEFT JOIN customers c ON c.id = si.customer_id
    WHERE si.id = ? AND si.business_id = ?
    LIMIT 1
");
$stmt->bind_param('ii', $invoiceId, $businessId);
$stmt->execute();
$invoice = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$invoice) {
    header('Location: sales-invoices.php?error=' . urlencode('Invoice not found.'));
    exit;
}

/* Master data */
$branches = fetchAllAssoc($conn, "
    SELECT id, branch_name 
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

$stockProducts = [];
if (tableExists($conn, 'products')) {
    $hasProductStock = tableExists($conn, 'product_stock');
    $branchForStock = (int)($invoice['branch_id'] ?? $currentBranchId);

    $stockQtyExpr = $hasProductStock
        ? "COALESCE(ps.qty_available, p.stock_qty, 0)"
        : "COALESCE(p.stock_qty, 0)";

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
            " . ($hasProductStock ? "ps.branch_id" : "0 AS branch_id") . ",
            " . (tableExists($conn, 'product_categories') ? "pc.category_name" : "'' AS category_name") . "
        FROM products p
        " . ($hasProductStock ? "LEFT JOIN product_stock ps ON ps.product_id = p.id AND ps.business_id = p.business_id AND ps.branch_id = {$branchForStock}" : "") . "
        " . (tableExists($conn, 'product_categories') ? "LEFT JOIN product_categories pc ON pc.id = p.category_id" : "") . "
        WHERE p.business_id = {$businessId}
          AND p.status = 1
        ORDER BY p.product_name ASC
    ");
}

$chargerTypes = [
    'Fast Charger','Normal Charger','Smart Charger','Type-C Charger','USB Charger',
    'Wireless Charger','Portable Charger','Wall Charger','Car Charger'
];

/* Fetch existing items */
$invoiceItems = [];
$stmt = $conn->prepare("
    SELECT
        sii.*,
        p.product_name,
        p.product_code,
        p.item_code,
        p.brand_name AS product_brand,
        p.hsn_code,
        pc.category_name,
        vs.color,
        vs.chassis_no,
        vs.engine_no,
        vs.motor_no,
        vs.battery_brand,
        vs.battery_no,
        vs.battery_capacity,
        vs.charger_brand,
        vs.charger_no,
        vs.charger_type,
        vs.purchase_cost,
        vs.ex_showroom_price,
        vs.rto_charge,
        vs.registration_price,
        vs.road_tax,
        vs.insurance_price,
        vm.model_name,
        vb.brand_name AS vehicle_brand
    FROM sales_invoice_items sii
    LEFT JOIN products p ON p.id = sii.product_id
    LEFT JOIN product_categories pc ON pc.id = p.category_id
    LEFT JOIN vehicle_stock vs ON vs.id = sii.vehicle_stock_id
    LEFT JOIN vehicle_models vm ON vm.id = vs.model_id
    LEFT JOIN vehicle_brands vb ON vb.id = vm.brand_id
    WHERE sii.invoice_id = ?
    ORDER BY sii.id ASC
");
$stmt->bind_param('i', $invoiceId);
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) $invoiceItems[] = $r;
$stmt->close();

// Initialize GST types array for this invoice if not exists
if (!isset($_SESSION['invoice_gst_types_' . $invoiceId])) {
    $_SESSION['invoice_gst_types_' . $invoiceId] = [];
}

// Ensure we have a GST type for each item, defaulting to 'exclusive'
$existingCart = [];
foreach ($invoiceItems as $index => $it) {
    $itemType = $it['item_type'] ?: 'product';
    $gstPercent = (float)$it['cgst_percent'] + (float)$it['sgst_percent'] + (float)$it['igst_percent'];
    
    // Get stored GST type or default to 'exclusive'
    $gstType = isset($_SESSION['invoice_gst_types_' . $invoiceId][$index]) 
        ? $_SESSION['invoice_gst_types_' . $invoiceId][$index] 
        : 'exclusive';

    if ($itemType === 'vehicle') {
        $existingCart[] = [
            't' => 'vehicle',
            'index' => $index,
            'desc' => $it['description'],
            'qty' => 1,
            'pPrice' => (float)($it['purchase_price'] ?? $it['purchase_cost'] ?? 0),
            'price' => (float)$it['unit_price'],
            'disc' => (float)$it['discount_amount'],
            'gst' => $gstPercent,
            'gType' => $gstType,
            'profit' => (float)$it['profit_amount'],
            'brand' => $it['vehicle_brand'] ?? '',
            'model' => $it['model_name'] ?? '',
            'color' => $it['color'] ?? '',
            'chassis' => $it['chassis_no'] ?? '',
            'engine' => $it['engine_no'] ?? '',
            'motor' => $it['motor_no'] ?? '',
            'battery' => $it['battery_brand'] ?? '',
            'batteryNo' => $it['battery_no'] ?? '',
            'batteryCapacity' => $it['battery_capacity'] ?? '',
            'chargerBrand' => $it['charger_brand'] ?? '',
            'chargerNo' => $it['charger_no'] ?? '',
            'chargerType' => $it['charger_type'] ?? '',
            'vehicleStockId' => (int)($it['vehicle_stock_id'] ?? 0),
            'exshow' => (float)($it['ex_showroom_price'] ?? $it['unit_price']),
            'excost' => (float)($it['purchase_cost'] ?? $it['purchase_price'] ?? 0),
            'rto' => (float)($it['rto_charge'] ?? 0),
            'reg' => (float)($it['registration_price'] ?? 0),
            'roadTax' => (float)($it['road_tax'] ?? 0),
            'ins' => (float)($it['insurance_price'] ?? 0),
        ];
    } elseif ($itemType === 'charger') {
        $existingCart[] = [
            't' => 'charger',
            'index' => $index,
            'desc' => $it['description'],
            'qty' => (float)$it['qty'],
            'pPrice' => (float)$it['purchase_price'],
            'price' => (float)$it['unit_price'],
            'disc' => (float)$it['discount_amount'],
            'gst' => $gstPercent,
            'gType' => $gstType,
            'profit' => (float)$it['profit_amount'],
            'cBrand' => '',
            'cNo' => '',
            'cType' => '',
        ];
    } else {
        $existingCart[] = [
            't' => 'product',
            'index' => $index,
            'productSource' => !empty($it['product_id']) ? 'stock' : 'manual',
            'stockProductId' => (int)($it['product_id'] ?? 0),
            'desc' => $it['description'],
            'qty' => (float)$it['qty'],
            'pPrice' => (float)$it['purchase_price'],
            'price' => (float)$it['unit_price'],
            'disc' => (float)$it['discount_amount'],
            'gst' => $gstPercent,
            'gType' => $gstType,
            'profit' => (float)$it['profit_amount'],
            'cat' => $it['category_name'] ?? '',
            'pname' => $it['product_name'] ?? $it['description'],
            'pbrand' => $it['product_brand'] ?? '',
            'hsn' => $it['hsn_code'] ?? '',
            'sku' => $it['product_code'] ?? $it['item_code'] ?? '',
        ];
    }
}

$form = [
    'branch_id' => (int)$invoice['branch_id'],
    'invoice_no' => $invoice['invoice_no'],
    'customer_id' => (int)$invoice['customer_id'],
    'invoice_date' => !empty($invoice['invoice_date']) ? date('Y-m-d\TH:i', strtotime($invoice['invoice_date'])) : date('Y-m-d\TH:i'),
    'invoice_type' => $invoice['invoice_type'] ?? 'product_sale',
    'discount_amount' => number_format((float)$invoice['discount_amount'], 2, '.', ''),
    'round_off' => number_format((float)$invoice['round_off'], 2, '.', ''),
    'paid_amount' => number_format((float)$invoice['paid_amount'], 2, '.', ''),
    'sale_status' => $invoice['sale_status'] ?? 'confirmed',
    'customer_note' => $invoice['customer_note'] ?? '',
];

$success = '';
$error = '';

/* UPDATE */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['branch_id'] = (int)($_POST['branch_id'] ?? 0);
    $form['invoice_no'] = trim($_POST['invoice_no'] ?? '');
    $form['customer_id'] = (int)($_POST['customer_id'] ?? 0);
    $form['invoice_date'] = trim($_POST['invoice_date'] ?? date('Y-m-d\TH:i'));
    $form['invoice_type'] = trim($_POST['invoice_type'] ?? 'product_sale');
    $form['discount_amount'] = trim($_POST['discount_amount'] ?? '0.00');
    $form['round_off'] = trim($_POST['round_off'] ?? '0.00');
    $form['paid_amount'] = trim($_POST['paid_amount'] ?? '0.00');
    $form['sale_status'] = trim($_POST['sale_status'] ?? 'confirmed');
    $form['customer_note'] = trim($_POST['customer_note'] ?? '');

    $postedItems = $_POST['items'] ?? [];
    $items = [];

    if (is_array($postedItems)) {
        foreach ($postedItems as $row) {
            $item = [];
            $item['index'] = isset($row['index']) ? (int)$row['index'] : null;
            $item['item_type'] = trim($row['item_type'] ?? 'product');
            $item['product_source'] = trim($row['product_source'] ?? 'manual');
            $item['stock_product_id'] = (int)($row['stock_product_id'] ?? 0);
            $item['vehicle_stock_id'] = (int)($row['vehicle_stock_id'] ?? 0);

            $item['description'] = trim($row['description'] ?? '');
            $item['qty'] = (float)($row['qty'] ?? 1);
            $item['unit_price'] = (float)($row['unit_price'] ?? 0);
            $item['purchase_price'] = (float)($row['purchase_price'] ?? 0);
            $item['discount_amount'] = (float)($row['discount_amount'] ?? 0);
            $item['gst_percent'] = (float)($row['gst_percent'] ?? 0);
            $item['gst_type'] = trim($row['gst_type'] ?? 'exclusive');

            $item['category'] = trim($row['category'] ?? '');
            $item['product_name'] = trim($row['product_name'] ?? '');
            $item['product_brand'] = trim($row['product_brand'] ?? '');
            $item['hsn_code'] = trim($row['hsn_code'] ?? '');
            $item['sku'] = trim($row['sku'] ?? '');

            $item['brand_name'] = trim($row['brand_name'] ?? '');
            $item['model_name'] = trim($row['model_name'] ?? '');
            $item['color'] = trim($row['color'] ?? '');
            $item['chassis_no'] = trim($row['chassis_no'] ?? '');
            $item['engine_no'] = trim($row['engine_no'] ?? '');
            $item['motor_no'] = trim($row['motor_no'] ?? '');
            $item['battery_brand'] = trim($row['battery_brand'] ?? '');
            $item['battery_no'] = trim($row['battery_no'] ?? '');
            $item['battery_capacity'] = trim($row['battery_capacity'] ?? '');
            $item['charger_brand'] = trim($row['charger_brand'] ?? '');
            $item['charger_no'] = trim($row['charger_no'] ?? '');
            $item['charger_type'] = trim($row['charger_type'] ?? '');

            $item['ex_showroom'] = (float)($row['ex_showroom'] ?? 0);
            $item['ex_showroom_cost'] = (float)($row['ex_showroom_cost'] ?? 0);
            $item['rto_charge'] = (float)($row['rto_charge'] ?? 0);
            $item['registration_price'] = (float)($row['registration_price'] ?? 0);
            $item['road_tax_amount'] = (float)($row['road_tax_amount'] ?? 0);
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

    if ($form['branch_id'] <= 0) $error = 'Please select a branch.';
    elseif ($form['invoice_no'] === '') $error = 'Invoice number is required.';
    elseif ($form['customer_id'] <= 0) $error = 'Please select customer.';
    elseif (empty($items)) $error = 'Please add at least one item.';

    if ($error === '') {
        $stmt = $conn->prepare("
            SELECT id FROM sales_invoices
            WHERE business_id = ? AND branch_id = ? AND invoice_no = ? AND id <> ?
            LIMIT 1
        ");
        $stmt->bind_param('iisi', $businessId, $form['branch_id'], $form['invoice_no'], $invoiceId);
        $stmt->execute();
        $dupe = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($dupe) $error = 'Invoice number already exists.';
    }

    $validItems = [];
    $subtotal = $cgstAmount = $sgstAmount = $totalProfit = 0.0;
    $gstTypesToStore = [];

    if ($error === '') {
        foreach ($items as $idx => $item) {
            $qty = (float)$item['qty'];
            $unitPrice = (float)$item['unit_price'];
            $purchasePrice = (float)$item['purchase_price'];
            $discount = (float)$item['discount_amount'];
            $gstPercent = (float)$item['gst_percent'];
            $gstType = $item['gst_type'];

            if ($qty <= 0 || $unitPrice <= 0 || $item['description'] === '') continue;

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
            
            if ($gstType === 'inclusive') {
                $lineTotal = max(0, $lineAmount - $discount);
            } else {
                $lineTotal = $taxableValue + $taxAmount;
            }

            $itemProfit = ($item['item_type'] === 'vehicle')
                ? ((float)$item['ex_showroom'] - (float)$item['ex_showroom_cost'])
                : (($unitPrice - $purchasePrice) * $qty);

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
            
            // Store GST type for this item
            $originalIndex = isset($item['index']) ? $item['index'] : $idx;
            $gstTypesToStore[$originalIndex] = $gstType;
        }

        if (empty($validItems)) $error = 'Please add valid item details.';
    }

    if ($error === '') {
        $invoiceDiscount = (float)$form['discount_amount'];
        $roundOff = (float)$form['round_off'];
        $grandTotal = $subtotal + $cgstAmount + $sgstAmount - $invoiceDiscount + $roundOff;
        if ($grandTotal < 0) $grandTotal = 0;

        $paidAmount = (float)$form['paid_amount'];
        if ($paidAmount > $grandTotal) $paidAmount = $grandTotal;

        $balanceAmount = $grandTotal - $paidAmount;
        $paymentStatus = ($paidAmount >= $grandTotal && $grandTotal > 0) ? 'paid' : (($paidAmount > 0) ? 'partial' : 'unpaid');

        $conn->begin_transaction();

        try {
            foreach ($invoiceItems as $old) {
                if (($old['item_type'] ?? '') === 'product' && (int)($old['product_id'] ?? 0) > 0) {
                    $oldProductId = (int)$old['product_id'];
                    $oldQty = (float)$old['qty'];

                    if (tableExists($conn, 'products')) {
                        $conn->query("
                            UPDATE products
                            SET stock_qty = COALESCE(stock_qty,0) + {$oldQty}
                            WHERE id = {$oldProductId} AND business_id = {$businessId}
                        ");
                    }

                    if (tableExists($conn, 'product_stock')) {
                        $oldBranch = (int)$invoice['branch_id'];
                        $conn->query("
                            UPDATE product_stock
                            SET qty_available = COALESCE(qty_available,0) + {$oldQty}
                            WHERE product_id = {$oldProductId}
                              AND business_id = {$businessId}
                              AND branch_id = {$oldBranch}
                        ");
                    }
                }
            }

            $invoiceDateDb = date('Y-m-d H:i:s', strtotime($form['invoice_date']));
            $igstAmount = 0.0;
            $cessAmount = 0.0;

            $stmt = $conn->prepare("
                UPDATE sales_invoices SET
                    branch_id = ?,
                    invoice_no = ?,
                    customer_id = ?,
                    invoice_date = ?,
                    invoice_type = ?,
                    subtotal = ?,
                    discount_amount = ?,
                    cgst_amount = ?,
                    sgst_amount = ?,
                    igst_amount = ?,
                    cess_amount = ?,
                    round_off = ?,
                    grand_total = ?,
                    paid_amount = ?,
                    balance_amount = ?,
                    payment_status = ?,
                    sale_status = ?,
                    customer_note = ?
                WHERE id = ? AND business_id = ?
            ");

            $stmt->bind_param(
                'isissddddddddddsssii',
                $form['branch_id'], $form['invoice_no'], $form['customer_id'], $invoiceDateDb,
                $form['invoice_type'], $subtotal, $invoiceDiscount, $cgstAmount, $sgstAmount,
                $igstAmount, $cessAmount, $roundOff, $grandTotal, $paidAmount, $balanceAmount,
                $paymentStatus, $form['sale_status'], $form['customer_note'], $invoiceId, $businessId
            );

            if (!$stmt->execute()) throw new Exception('Invoice update failed: ' . $stmt->error);
            $stmt->close();

            $stmt = $conn->prepare("DELETE FROM sales_invoice_items WHERE invoice_id = ?");
            $stmt->bind_param('i', $invoiceId);
            if (!$stmt->execute()) throw new Exception('Old items delete failed: ' . $stmt->error);
            $stmt->close();

            $itemStmt = $conn->prepare("
                INSERT INTO sales_invoice_items (
                    invoice_id, item_type, vehicle_stock_id, product_id, description,
                    qty, unit_price, purchase_price, discount_amount, taxable_value,
                    cgst_percent, sgst_percent, igst_percent, cess_percent,
                    cgst_amount, sgst_amount, igst_amount, cess_amount,
                    line_total, profit_amount
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?, ?, 0, 0, ?, ?)
            ");
            if (!$itemStmt) throw new Exception('Item insert prepare failed: ' . $conn->error);

            foreach ($validItems as $idx => $item) {
                $vehicleStockId = null;
                $productId = null;

                if ($item['item_type'] === 'vehicle') {
                    $modelId = getOrCreateVehicleModel(
                        $conn,
                        $businessId,
                        $item['brand_name'],
                        $item['model_name'],
                        (float)$item['ex_showroom'],
                        (float)$item['gst_percent']
                    );

                    $salePrice = (float)$item['ex_showroom']
                        + (float)$item['rto_charge']
                        + (float)$item['registration_price']
                        + (float)$item['road_tax_amount']
                        + (float)$item['insurance_amount'];

                    if ((int)$item['vehicle_stock_id'] > 0) {
                        $vehicleStockId = (int)$item['vehicle_stock_id'];

                        $vStmt = $conn->prepare("
                            UPDATE vehicle_stock SET
                                branch_id = ?, model_id = ?, color = ?, chassis_no = ?,
                                engine_no = ?, motor_no = ?, battery_brand = ?, battery_no = ?,
                                battery_capacity = ?, charger_brand = ?, charger_no = ?, charger_type = ?,
                                purchase_cost = ?, ex_showroom_price = ?, rto_charge = ?,
                                registration_price = ?, road_tax = ?, insurance_price = ?,
                                sale_price = ?, stock_status = 'sold'
                            WHERE id = ? AND business_id = ?
                        ");
                        if ($vStmt) {
                            $vStmt->bind_param(
                                'iissssssssssdddddddii',
                                $form['branch_id'], $modelId, $item['color'], $item['chassis_no'],
                                $item['engine_no'], $item['motor_no'], $item['battery_brand'], $item['battery_no'],
                                $item['battery_capacity'], $item['charger_brand'], $item['charger_no'], $item['charger_type'],
                                $item['ex_showroom_cost'], $item['ex_showroom'], $item['rto_charge'],
                                $item['registration_price'], $item['road_tax_amount'], $item['insurance_amount'],
                                $salePrice, $vehicleStockId, $businessId
                            );
                            $vStmt->execute();
                            $vStmt->close();
                        }
                    } else {
                        $purchaseDate = date('Y-m-d', strtotime($invoiceDateDb));

                        $vStmt = $conn->prepare("
                            INSERT INTO vehicle_stock (
                                business_id, branch_id, model_id, color, chassis_no,
                                engine_no, motor_no, battery_brand, battery_no, battery_capacity,
                                charger_brand, charger_no, charger_type, purchase_date,
                                purchase_cost, ex_showroom_price, rto_charge, registration_price,
                                road_tax, insurance_price, sale_price, stock_status, created_at
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'sold', NOW())
                        ");
                        if (!$vStmt) throw new Exception('Vehicle stock insert failed: ' . $conn->error);

                        $vStmt->bind_param(
                            'iiissssssssssdddddddd',
                            $businessId, $form['branch_id'], $modelId, $item['color'], $item['chassis_no'],
                            $item['engine_no'], $item['motor_no'], $item['battery_brand'], $item['battery_no'],
                            $item['battery_capacity'], $item['charger_brand'], $item['charger_no'], $item['charger_type'],
                            $purchaseDate, $item['ex_showroom_cost'], $item['ex_showroom'], $item['rto_charge'],
                            $item['registration_price'], $item['road_tax_amount'], $item['insurance_amount'], $salePrice
                        );

                        if (!$vStmt->execute()) throw new Exception('Vehicle stock save failed: ' . $vStmt->error);
                        $vehicleStockId = (int)$conn->insert_id;
                        $vStmt->close();
                    }
                } elseif ($item['item_type'] === 'product') {
                    if ($item['product_source'] === 'stock' && (int)$item['stock_product_id'] > 0) {
                        $productId = (int)$item['stock_product_id'];
                    } else {
                        $productId = getOrCreateManualProduct($conn, $businessId, $item);
                    }
                }

                $itPurchPrice = ($item['item_type'] === 'vehicle') ? (float)$item['ex_showroom_cost'] : (float)$item['purchase_price'];
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

                if (!$itemStmt->execute()) throw new Exception('Item save failed: ' . $itemStmt->error);

                if ($item['item_type'] === 'product' && $productId > 0) {
                    $qtySold = (float)$item['qty'];

                    if (tableExists($conn, 'products')) {
                        $conn->query("
                            UPDATE products
                            SET stock_qty = GREATEST(COALESCE(stock_qty,0) - {$qtySold}, 0)
                            WHERE id = {$productId} AND business_id = {$businessId}
                        ");
                    }

                    if (tableExists($conn, 'product_stock')) {
                        $conn->query("
                            UPDATE product_stock
                            SET qty_available = GREATEST(COALESCE(qty_available,0) - {$qtySold}, 0)
                            WHERE product_id = {$productId}
                              AND business_id = {$businessId}
                              AND branch_id = {$form['branch_id']}
                        ");
                    }
                }
            }

            $itemStmt->close();
            
            // Store GST types in session for this invoice
            $_SESSION['invoice_gst_types_' . $invoiceId] = $gstTypesToStore;

            $conn->commit();

            header('Location: sales-invoice-edit.php?id=' . $invoiceId . '&success=' . urlencode('Invoice updated successfully.'));
            exit;

        } catch (Exception $e) {
            $conn->rollback();
            $error = 'Failed to update invoice: ' . $e->getMessage();
        }
    }
}

if (isset($_GET['success'])) $success = trim($_GET['success']);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Edit Sales Invoice</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php include('includes/head.php'); ?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/remixicon@4.0.0/fonts/remixicon.css" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box}
html,body{height:100%;width:100%;overflow-x:hidden;font-family:system-ui,-apple-system,sans-serif;background:#f1f5f9}
.app-container{display:flex;flex-direction:column;height:100vh;width:100vw}
.app-header{background:linear-gradient(135deg,#0f766e,#14b8a6);color:#fff;padding:12px 25px;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;box-shadow:0 2px 10px rgba(0,0,0,.15);z-index:100;flex-wrap:wrap;gap:10px}
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
@media(max-width:1024px){.invoice-layout{flex-direction:column}.left-panel{flex:0 0 auto;max-height:none}}
</style>
</head>
<body>

<div class="app-container">
<div class="app-header">
    <div>
        <h2>✏️ Edit Sales Invoice</h2>
        <small style="opacity:.85">Invoice: <?= h($form['invoice_no']) ?> | Payment: <?= h(ucfirst($invoice['payment_status'] ?? '')) ?></small>
    </div>
    <div style="display:flex;gap:10px;flex-wrap:wrap">
        <a href="sales-invoice-view.php?id=<?= (int)$invoiceId ?>" class="btn btn-light btn-sm">View</a>
        <a href="sales-invoices.php" class="btn btn-outline-light btn-sm">All Invoices</a>
    </div>
</div>

<div class="app-body">

<?php if ($success): ?>
<div class="alert alert-success alert-dismissible fade show mb-3">
    <?= h($success) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show mb-3">
    <?= h($error) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button>
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
        <label class="form-label">Branch</label>
        <select name="branch_id" id="branch_id" class="form-select" required>
            <option value="">-- Select --</option>
            <?php foreach ($branches as $b): ?>
                <option value="<?= (int)$b['id'] ?>" <?= ((int)$form['branch_id'] === (int)$b['id']) ? 'selected' : '' ?>>
                    <?= h($b['branch_name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div style="flex:1">
        <label class="form-label">Invoice No</label>
        <input type="text" name="invoice_no" class="form-control" value="<?= h($form['invoice_no']) ?>" required>
    </div>
</div>

<div style="display:flex;gap:10px;margin-bottom:12px">
    <div style="flex:1">
        <label class="form-label">Invoice Date</label>
        <input type="datetime-local" name="invoice_date" class="form-control" value="<?= h($form['invoice_date']) ?>" required>
    </div>
    <div style="flex:1">
        <label class="form-label">Invoice Type</label>
        <select name="invoice_type" class="form-select">
            <option value="product_sale" <?= ($form['invoice_type'] === 'product_sale') ? 'selected' : '' ?>>🛍️ Product</option>
            <option value="vehicle_sale" <?= ($form['invoice_type'] === 'vehicle_sale') ? 'selected' : '' ?>>🏍️ Vehicle</option>
            <option value="mixed_sale" <?= ($form['invoice_type'] === 'mixed_sale') ? 'selected' : '' ?>>📦 Mixed</option>
        </select>
    </div>
</div>

<div class="field-row">
    <label class="form-label">Customer</label>
    <select name="customer_id" class="form-select" required>
        <option value="0">-- Select Customer --</option>
        <?php foreach ($customers as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= ((int)$form['customer_id'] === (int)$c['id']) ? 'selected' : '' ?>>
                <?= h($c['full_name'] . ' - ' . $c['mobile']) ?>
            </option>
        <?php endforeach; ?>
    </select>
</div>

<div style="display:flex;gap:10px;margin-top:12px">
    <div style="flex:1">
        <label class="form-label">Paid Amount ₹</label>
        <input type="number" step="0.01" name="paid_amount" id="paid_amount" class="form-control" value="<?= h($form['paid_amount']) ?>">
    </div>
    <div style="flex:1">
        <label class="form-label">Sale Status</label>
        <select name="sale_status" class="form-select">
            <?php foreach (['confirmed','draft','delivered','cancelled'] as $st): ?>
                <option value="<?= h($st) ?>" <?= ($form['sale_status'] === $st) ? 'selected' : '' ?>>
                    <?= h(ucfirst($st)) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
</div>

<div class="field-row">
    <label class="form-label">Customer Note</label>
    <textarea name="customer_note" class="form-control" rows="2"><?= h($form['customer_note']) ?></textarea>
</div>
</div>
</div>

<div class="card">
<div class="card-body">
<span class="section-label">➕ Add / Edit Item</span>

<div class="field-row">
    <label class="form-label">Select Item Type</label>
    <select id="item_type" class="form-select" onchange="toggleSection()">
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
        <input type="text" id="stock_product_search" class="form-control" placeholder="Search products..." onkeyup="filterStockProducts()" autocomplete="off">
        <div class="search-hint">Search by product name, code, brand or HSN.</div>

        <label class="form-label" style="margin-top:10px">Select Stock Product</label>
        <select id="stock_product_id" class="form-select" size="8" onchange="fillStockProduct()" style="height:auto;min-height:200px;">
            <option value="">-- Select Stock Product --</option>
            <?php foreach ($stockProducts as $p): ?>
                <?php
                $labelParts = [];
                $labelParts[] = $p['product_name'] ?? '';
                if (!empty($p['product_code'])) $labelParts[] = 'Code: ' . $p['product_code'];
                if (!empty($p['item_code'])) $labelParts[] = 'Item: ' . $p['item_code'];
                if (!empty($p['brand_name'])) $labelParts[] = 'Brand: ' . $p['brand_name'];
                if (!empty($p['category_name'])) $labelParts[] = 'Category: ' . $p['category_name'];
                $labelParts[] = 'Stock: ' . number_format((float)($p['stock_qty'] ?? 0), 2);
                ?>
                <option
                    value="<?= (int)$p['id'] ?>"
                    data-name="<?= h($p['product_name'] ?? '') ?>"
                    data-code="<?= h($p['product_code'] ?? '') ?>"
                    data-item-code="<?= h($p['item_code'] ?? '') ?>"
                    data-brand="<?= h($p['brand_name'] ?? '') ?>"
                    data-hsn="<?= h($p['hsn_code'] ?? '') ?>"
                    data-category="<?= h($p['category_name'] ?? '') ?>"
                    data-purchase="<?= h($p['purchase_price'] ?? 0) ?>"
                    data-selling="<?= h($p['selling_price'] ?? 0) ?>"
                    data-gst="<?= h($p['gst_percent'] ?? 0) ?>"
                    data-stock="<?= h($p['stock_qty'] ?? 0) ?>"
                ><?= h(implode(' | ', $labelParts)) ?></option>
            <?php endforeach; ?>
        </select>
        <div id="stockProductInfo" class="stock-info" style="display:none;"></div>
    </div>

    <div class="field-row mt-3">
        <label class="form-label">Description *</label>
        <input type="text" id="prod_desc" class="form-control">
    </div>

    <div style="display:flex;gap:10px;margin-bottom:12px">
        <div style="flex:1"><label class="form-label">Qty</label><input type="number" id="prod_qty" class="form-control" value="1" step="0.01"></div>
        <div style="flex:1"><label class="form-label">Purchase ₹</label><input type="number" id="prod_purchase" class="form-control" value="0.00" step="0.01"></div>
        <div style="flex:1"><label class="form-label">Selling ₹</label><input type="number" id="prod_price" class="form-control" value="0.00" step="0.01"></div>
    </div>

    <div style="display:flex;gap:10px;margin-bottom:12px;align-items:end">
        <div style="flex:1"><label class="form-label">Discount ₹</label><input type="number" id="prod_disc" class="form-control" value="0.00" step="0.01"></div>
        <div style="flex:1"><label class="form-label">GST %</label><input type="number" id="prod_gst" class="form-control" value="0.00" step="0.01"></div>
        <div style="flex:1">
            <label class="form-label">GST Type</label>
            <select id="prod_gst_type" class="form-select">
                <option value="exclusive">Exclusive</option>
                <option value="inclusive">Inclusive</option>
            </select>
        </div>
        <button type="button" class="btn btn-success" onclick="addP()">➕ Add</button>
    </div>

    <div style="display:flex;gap:10px;margin-bottom:12px">
        <div style="flex:1"><label class="form-label">Product Name</label><input type="text" id="prod_name" class="form-control"></div>
        <div style="flex:1"><label class="form-label">Brand</label><input type="text" id="prod_brand" class="form-control"></div>
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
        <div style="flex:1"><label class="form-label">HSN</label><input type="text" id="prod_hsn" class="form-control"></div>
        <div style="flex:1"><label class="form-label">SKU / Code</label><input type="text" id="prod_sku" class="form-control"></div>
    </div>
</div>

<div id="vehicle_entry" class="detail-section vehicle">
    <span class="section-label">🏍️ Vehicle Entry</span>

    <input type="hidden" id="veh_stock_id" value="0">

    <div class="field-row">
        <label class="form-label">Description *</label>
        <input type="text" id="veh_desc" class="form-control">
    </div>

    <div style="display:flex;gap:10px;margin-bottom:12px">
        <div style="flex:1"><label class="form-label">Dealer Cost ₹</label><input type="number" id="veh_cost" class="form-control" value="0.00" step="0.01"></div>
        <div style="flex:1"><label class="form-label">Ex-Showroom Selling ₹</label><input type="number" id="veh_exshow" class="form-control" value="0.00" step="0.01"></div>
        <div style="flex:1"><label class="form-label">Profit</label><input type="text" id="veh_profit" class="form-control" value="₹0.00" readonly style="background:#f0fdf4;font-weight:bold;text-align:center"></div>
    </div>

    <div style="display:flex;gap:10px;margin-bottom:12px">
        <div style="flex:1">
            <label class="form-label">Brand</label>
            <input type="text" id="veh_brand" class="form-control" list="brandList">
            <datalist id="brandList">
                <?php foreach ($vehicleBrands as $vb): ?><option value="<?= h($vb['brand_name']) ?>"><?php endforeach; ?>
            </datalist>
        </div>
        <div style="flex:1"><label class="form-label">Model</label><input type="text" id="veh_model" class="form-control"></div>
        <div style="flex:1"><label class="form-label">Color</label><input type="text" id="veh_color" class="form-control"></div>
    </div>

    <div style="display:flex;gap:10px;margin-bottom:12px">
        <div style="flex:1"><label class="form-label">Chassis No</label><input type="text" id="veh_chassis" class="form-control"></div>
        <div style="flex:1"><label class="form-label">Engine No</label><input type="text" id="veh_engine" class="form-control"></div>
        <div style="flex:1"><label class="form-label">Motor No</label><input type="text" id="veh_motor" class="form-control"></div>
    </div>

    <div style="display:flex;gap:10px;margin-bottom:12px">
        <div style="flex:1">
            <label class="form-label">Battery Brand</label>
            <input type="text" id="veh_battery" class="form-control" list="batteryList">
            <datalist id="batteryList">
                <?php foreach ($batteryBrands as $bb): ?><option value="<?= h($bb['brand_name']) ?>"><?php endforeach; ?>
            </datalist>
        </div>
        <div style="flex:1"><label class="form-label">Battery No</label><input type="text" id="veh_battery_no" class="form-control"></div>
        <div style="flex:1"><label class="form-label">Battery Capacity</label><input type="text" id="veh_battery_capacity" class="form-control"></div>
    </div>

    <div style="display:flex;gap:10px;margin-bottom:12px">
        <div style="flex:1">
            <label class="form-label">Charger Brand</label>
            <input type="text" id="veh_charger_brand" class="form-control" list="chargerList">
        </div>
        <div style="flex:1"><label class="form-label">Charger No</label><input type="text" id="veh_charger_no" class="form-control"></div>
        <div style="flex:1"><label class="form-label">Charger Type</label><input type="text" id="veh_charger_type" class="form-control" list="chTypeList"></div>
    </div>

    <datalist id="chargerList">
        <?php foreach ($chargerBrands as $cb): ?><option value="<?= h($cb['brand_name']) ?>"><?php endforeach; ?>
    </datalist>
    <datalist id="chTypeList">
        <?php foreach ($chargerTypes as $ct): ?><option value="<?= h($ct) ?>"><?php endforeach; ?>
    </datalist>

    <span class="section-label">🚗 On-Road Pricing</span>

    <div style="display:flex;gap:10px;margin-bottom:12px">
        <div style="flex:1"><label class="form-label">RTO ₹</label><input type="number" step="0.01" id="veh_rto" class="form-control" value="0.00"></div>
        <div style="flex:1"><label class="form-label">Registration ₹</label><input type="number" step="0.01" id="veh_reg" class="form-control" value="0.00"></div>
        <div style="flex:1"><label class="form-label">Road Tax ₹</label><input type="number" step="0.01" id="veh_rt" class="form-control" value="0.00"></div>
    </div>

    <div style="display:flex;gap:10px;margin-bottom:12px;align-items:end">
        <div style="flex:1"><label class="form-label">Insurance ₹</label><input type="number" step="0.01" id="veh_ins" class="form-control" value="0.00"></div>
        <div style="flex:1"><label class="form-label">GST %</label><input type="number" id="veh_gst" class="form-control" value="0.00" step="0.01"></div>
        <div style="flex:1">
            <label class="form-label">GST Type</label>
            <select id="veh_gst_type" class="form-select">
                <option value="exclusive">Exclusive</option>
                <option value="inclusive">Inclusive</option>
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
    <div class="field-row"><label class="form-label">Description *</label><input type="text" id="chg_desc" class="form-control"></div>
    <div style="display:flex;gap:10px;margin-bottom:12px">
        <div style="flex:1"><label class="form-label">Qty</label><input type="number" id="chg_qty" class="form-control" value="1" step="0.01"></div>
        <div style="flex:1"><label class="form-label">Purchase ₹</label><input type="number" id="chg_purchase" class="form-control" value="0.00" step="0.01"></div>
        <div style="flex:1"><label class="form-label">Selling ₹</label><input type="number" id="chg_price" class="form-control" value="0.00" step="0.01"></div>
    </div>
    <div style="display:flex;gap:10px;margin-bottom:12px;align-items:end">
        <div style="flex:1"><label class="form-label">Discount ₹</label><input type="number" id="chg_disc" class="form-control" value="0.00" step="0.01"></div>
        <div style="flex:1"><label class="form-label">GST %</label><input type="number" id="chg_gst" class="form-control" value="0.00" step="0.01"></div>
        <div style="flex:1">
            <label class="form-label">GST Type</label>
            <select id="chg_gst_type" class="form-select">
                <option value="exclusive">Exclusive</option>
                <option value="inclusive">Inclusive</option>
            </select>
        </div>
        <button type="button" class="btn btn-success" onclick="addC()">➕ Add</button>
    </div>
    <div style="display:flex;gap:10px">
        <div style="flex:1"><label class="form-label">Charger Brand</label><input type="text" id="chg_brand" class="form-control" list="chargerList"></div>
        <div style="flex:1"><label class="form-label">Charger No</label><input type="text" id="chg_no" class="form-control"></div>
        <div style="flex:1"><label class="form-label">Charger Type</label><input type="text" id="chg_type" class="form-control" list="chTypeList"></div>
    </div>
</div>

</div>
</div>

</div>

<div class="right-panel">
<div class="card" style="flex:1;display:flex;flex-direction:column;min-height:0">

<div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
    <span>🛒 Editable Cart</span>
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
<tbody id="cartBody"></tbody>
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
<tr><td style="width:50%">Subtotal</span><td class="text-end" id="subtotal_v">0.00</span></span>
<tr><td style="width:50%">CGST</span><td class="text-end" id="cgst_v">0.00</span></span>
<tr><td style="width:50%">SGST</span><td class="text-end" id="sgst_v">0.00</span></span>
<tr class="table-success"><td><strong>Profit</strong></span><td class="text-end" id="profit_v" style="color:#166534;font-weight:bold;">₹0.00</span></span>
<tr class="table-primary"><td><strong>Grand Total</strong></span><td class="text-end" id="grand_v"><strong style="font-size:16px">₹0.00</strong></span></span>
</table>

<div style="display:flex;gap:10px">
    <button type="submit" class="btn btn-success btn-lg" style="flex:1">
        <i class="ri-save-line me-1"></i>Update Invoice
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
let cart = <?= json_encode($existingCart, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
let allProductOptions = [];

document.addEventListener('DOMContentLoaded', function () {
    const select = document.getElementById('stock_product_id');
    allProductOptions = Array.from(select.options).slice(1);

    toggleSection();
    toggleProductSource();

    [
        'prod_qty','prod_purchase','prod_price',
        'chg_qty','chg_purchase','chg_price',
        'veh_cost','veh_exshow','veh_rto','veh_reg','veh_rt','veh_ins'
    ].forEach(function(id){
        const el = document.getElementById(id);
        if (el) {
            el.addEventListener('input', function(){
                calcPP(); calcCP(); calcVP(); calcVOR();
            });
        }
    });

    document.getElementById('inv_disc')?.addEventListener('input', calcAll);
    document.getElementById('inv_round')?.addEventListener('input', calcAll);

    renderCart();
});

function toggleSection() {
    const t = val('item_type');
    document.getElementById('product_entry').style.display = t === 'product' ? 'block' : 'none';
    document.getElementById('vehicle_entry').style.display = t === 'vehicle' ? 'block' : 'none';
    document.getElementById('charger_entry').style.display = t === 'charger' ? 'block' : 'none';
}

function toggleProductSource() {
    const source = val('product_source');
    document.getElementById('stockProductBox').style.display = source === 'stock' ? 'block' : 'none';

    const isStock = source === 'stock';
    ['prod_name','prod_brand','prod_cat','prod_hsn','prod_sku'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.readOnly = isStock;
    });

    if (!isStock) {
        setVal('stock_product_id', '');
        document.getElementById('stockProductInfo').style.display = 'none';
        clearProduct(false);
    }
}

function filterStockProducts() {
    const search = val('stock_product_search').toLowerCase();
    const select = document.getElementById('stock_product_id');

    select.innerHTML = '<option value="">-- Select Stock Product --</option>';

    const filtered = allProductOptions.filter(function(opt) {
        if (search === '') return true;
        const haystack = [
            opt.textContent,
            opt.getAttribute('data-name'),
            opt.getAttribute('data-code'),
            opt.getAttribute('data-item-code'),
            opt.getAttribute('data-brand'),
            opt.getAttribute('data-hsn'),
            opt.getAttribute('data-category')
        ].join(' ').toLowerCase();

        return haystack.includes(search);
    });

    filtered.forEach(opt => select.appendChild(opt.cloneNode(true)));

    if (filtered.length === 1 && search !== '') {
        select.value = filtered[0].value;
        fillStockProduct();
    }
}

function fillStockProduct() {
    const select = document.getElementById('stock_product_id');
    const opt = select.options[select.selectedIndex];
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
    const stock = parseFloat(opt.getAttribute('data-stock') || '0') || 0;

    setVal('prod_desc', productName);
    setVal('prod_name', productName);
    setVal('prod_brand', brand);
    setVal('prod_cat', category);
    setVal('prod_hsn', hsn);
    setVal('prod_sku', productCode || itemCode);
    setVal('prod_purchase', purchase.toFixed(2));
    setVal('prod_price', selling.toFixed(2));
    setVal('prod_gst', gst.toFixed(2));

    const info = document.getElementById('stockProductInfo');
    info.style.display = 'block';
    info.innerHTML = `
        <strong>Selected:</strong> ${escapeHtml(productName)}<br>
        <strong>Code:</strong> ${escapeHtml(productCode || itemCode || '-')} |
        <strong>Brand:</strong> ${escapeHtml(brand || '-')} |
        <strong>Stock:</strong> ${stock.toFixed(2)} |
        <strong>GST:</strong> ${gst.toFixed(2)}%
    `;
}

function val(id) {
    const el = document.getElementById(id);
    return el ? el.value.trim() : '';
}
function num(id) { return parseFloat(val(id)) || 0; }
function setVal(id, v) { const el = document.getElementById(id); if (el) el.value = v; }
function money(v) { return '₹' + Number(v || 0).toFixed(2); }

function calcPP() {
    const q = num('prod_qty') || 1;
    return (num('prod_price') - num('prod_purchase')) * q;
}
function calcCP() {
    const q = num('chg_qty') || 1;
    return (num('chg_price') - num('chg_purchase')) * q;
}
function calcVP() {
    const profit = num('veh_exshow') - num('veh_cost');
    setVal('veh_profit', money(profit));
    return profit;
}
function calcVOR() {
    const total = num('veh_exshow') + num('veh_rto') + num('veh_reg') + num('veh_rt') + num('veh_ins');
    setVal('veh_total', money(total));
    return total;
}

function addP() {
    const source = val('product_source');
    const desc = val('prod_desc');

    if (source === 'stock' && !val('stock_product_id')) {
        alert('Select stock product');
        return;
    }
    if (!desc) {
        alert('Enter product description');
        return;
    }
    if (num('prod_price') <= 0) {
        alert('Enter selling price');
        return;
    }

    cart.push({
        t: 'product',
        productSource: source,
        stockProductId: source === 'stock' ? val('stock_product_id') : '',
        desc: desc,
        qty: num('prod_qty') || 1,
        pPrice: num('prod_purchase'),
        price: num('prod_price'),
        disc: num('prod_disc'),
        gst: num('prod_gst'),
        gType: val('prod_gst_type'),
        profit: calcPP(),
        cat: val('prod_cat'),
        pname: val('prod_name'),
        pbrand: val('prod_brand'),
        hsn: val('prod_hsn'),
        sku: val('prod_sku'),
        index: cart.length
    });

    renderCart();
    clearProduct(true);
}

function addV() {
    const desc = val('veh_desc');
    if (!desc) {
        alert('Enter vehicle description');
        return;
    }
    if (num('veh_exshow') <= 0) {
        alert('Enter ex-showroom selling price');
        return;
    }

    cart.push({
        t: 'vehicle',
        desc: desc,
        qty: 1,
        pPrice: num('veh_cost'),
        price: calcVOR(),
        disc: 0,
        gst: num('veh_gst'),
        gType: val('veh_gst_type'),
        profit: calcVP(),
        brand: val('veh_brand'),
        model: val('veh_model'),
        color: val('veh_color'),
        chassis: val('veh_chassis'),
        engine: val('veh_engine'),
        motor: val('veh_motor'),
        battery: val('veh_battery'),
        batteryNo: val('veh_battery_no'),
        batteryCapacity: val('veh_battery_capacity'),
        chargerBrand: val('veh_charger_brand'),
        chargerNo: val('veh_charger_no'),
        chargerType: val('veh_charger_type'),
        vehicleStockId: val('veh_stock_id') || 0,
        exshow: num('veh_exshow'),
        excost: num('veh_cost'),
        rto: num('veh_rto'),
        reg: num('veh_reg'),
        roadTax: num('veh_rt'),
        ins: num('veh_ins'),
        index: cart.length
    });

    renderCart();
    clearVehicle();
}

function addC() {
    const desc = val('chg_desc');
    if (!desc) {
        alert('Enter charger description');
        return;
    }
    if (num('chg_price') <= 0) {
        alert('Enter selling price');
        return;
    }

    cart.push({
        t: 'charger',
        desc: desc,
        qty: num('chg_qty') || 1,
        pPrice: num('chg_purchase'),
        price: num('chg_price'),
        disc: num('chg_disc'),
        gst: num('chg_gst'),
        gType: val('chg_gst_type'),
        profit: calcCP(),
        cBrand: val('chg_brand'),
        cNo: val('chg_no'),
        cType: val('chg_type'),
        index: cart.length
    });

    renderCart();
    clearCharger();
}

function calcItem(it) {
    let lineAmount = Number(it.qty || 0) * Number(it.price || 0);
    let taxable = (it.gType === 'inclusive' && Number(it.gst || 0) > 0)
        ? lineAmount / (1 + (Number(it.gst) / 100))
        : lineAmount;

    taxable -= Number(it.disc || 0);
    if (taxable < 0) taxable = 0;

    let tax = taxable * (Number(it.gst || 0) / 100);
    let total = taxable + tax;

    if (it.gType === 'inclusive') {
        total = Math.max(0, lineAmount - Number(it.disc || 0));
    }

    return { taxable, tax, total };
}

function renderCart() {
    const tb = document.getElementById('cartBody');
    tb.innerHTML = '';

    document.querySelectorAll('#invoiceForm input[name^="items["]').forEach(e => e.remove());

    if (cart.length === 0) {
        tb.innerHTML = `
            <tr>
                <td colspan="9" class="cart-empty">
                    <div style="font-size:40px;margin-bottom:10px">🛒</div>
                    No items added<br><small>Use the form to add items</small>
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
            <td>${it.gst}% (${it.gType === 'inclusive' ? 'Inclusive' : 'Exclusive'})</span></td>
            <td style="font-weight:600;color:${it.profit >= 0 ? '#166534' : '#dc2626'}">${money(it.profit)}</td>
            <td><strong>${money(c.total)}</strong></td>
            <td>
                <button type="button" class="btn btn-info btn-sm mb-1" onclick="loadItemForEdit(${i})">Edit</button>
                <button type="button" class="btn btn-danger btn-sm" onclick="removeItem(${i})">×</button>
            </td>
        `;
        tb.appendChild(tr);

        const hiddenFields = {
            index: it.index !== undefined ? it.index : i,
            item_type: it.t,
            product_source: it.productSource || '',
            stock_product_id: it.stockProductId || 0,
            vehicle_stock_id: it.vehicleStockId || 0,
            description: it.desc,
            qty: it.qty,
            unit_price: it.price,
            purchase_price: it.pPrice,
            discount_amount: it.disc || 0,
            gst_percent: it.gst || 0,
            gst_type: it.gType || 'exclusive',

            category: it.cat || '',
            product_name: it.pname || '',
            product_brand: it.pbrand || '',
            hsn_code: it.hsn || '',
            sku: it.sku || '',

            brand_name: it.brand || '',
            model_name: it.model || '',
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
            insurance_amount: it.ins || 0
        };

        Object.keys(hiddenFields).forEach(k => addHidden(`items[${i}][${k}]`, hiddenFields[k]));
    });

    calcAll();
}

function loadItemForEdit(i) {
    const it = cart[i];
    cart.splice(i, 1);

    if (it.t === 'product') {
        setVal('item_type', 'product');
        toggleSection();

        setVal('product_source', it.productSource || 'manual');
        toggleProductSource();

        if (it.productSource === 'stock') {
            setVal('stock_product_id', it.stockProductId || '');
        }

        setVal('prod_desc', it.desc || '');
        setVal('prod_qty', it.qty || 1);
        setVal('prod_purchase', Number(it.pPrice || 0).toFixed(2));
        setVal('prod_price', Number(it.price || 0).toFixed(2));
        setVal('prod_disc', Number(it.disc || 0).toFixed(2));
        setVal('prod_gst', Number(it.gst || 0).toFixed(2));
        setVal('prod_gst_type', it.gType || 'exclusive');
        setVal('prod_cat', it.cat || '');
        setVal('prod_name', it.pname || '');
        setVal('prod_brand', it.pbrand || '');
        setVal('prod_hsn', it.hsn || '');
        setVal('prod_sku', it.sku || '');

    } else if (it.t === 'vehicle') {
        setVal('item_type', 'vehicle');
        toggleSection();

        setVal('veh_stock_id', it.vehicleStockId || 0);
        setVal('veh_desc', it.desc || '');
        setVal('veh_cost', Number(it.excost || it.pPrice || 0).toFixed(2));
        setVal('veh_exshow', Number(it.exshow || 0).toFixed(2));
        setVal('veh_brand', it.brand || '');
        setVal('veh_model', it.model || '');
        setVal('veh_color', it.color || '');
        setVal('veh_chassis', it.chassis || '');
        setVal('veh_engine', it.engine || '');
        setVal('veh_motor', it.motor || '');
        setVal('veh_battery', it.battery || '');
        setVal('veh_battery_no', it.batteryNo || '');
        setVal('veh_battery_capacity', it.batteryCapacity || '');
        setVal('veh_charger_brand', it.chargerBrand || '');
        setVal('veh_charger_no', it.chargerNo || '');
        setVal('veh_charger_type', it.chargerType || '');
        setVal('veh_rto', Number(it.rto || 0).toFixed(2));
        setVal('veh_reg', Number(it.reg || 0).toFixed(2));
        setVal('veh_rt', Number(it.roadTax || 0).toFixed(2));
        setVal('veh_ins', Number(it.ins || 0).toFixed(2));
        setVal('veh_gst', Number(it.gst || 0).toFixed(2));
        setVal('veh_gst_type', it.gType || 'exclusive');
        calcVP();
        calcVOR();

    } else {
        setVal('item_type', 'charger');
        toggleSection();

        setVal('chg_desc', it.desc || '');
        setVal('chg_qty', it.qty || 1);
        setVal('chg_purchase', Number(it.pPrice || 0).toFixed(2));
        setVal('chg_price', Number(it.price || 0).toFixed(2));
        setVal('chg_disc', Number(it.disc || 0).toFixed(2));
        setVal('chg_gst', Number(it.gst || 0).toFixed(2));
        setVal('chg_gst_type', it.gType || 'exclusive');
        setVal('chg_brand', it.cBrand || '');
        setVal('chg_no', it.cNo || '');
        setVal('chg_type', it.cType || '');
    }

    renderCart();
}

function addHidden(name, value) {
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = name;
    input.value = value ?? '';
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
    let subtotal = 0, cgst = 0, sgst = 0, profit = 0;

    cart.forEach(function(it) {
        const c = calcItem(it);
        subtotal += c.taxable;
        cgst += c.tax / 2;
        sgst += c.tax / 2;
        profit += Number(it.profit || 0);
    });

    const disc = num('inv_disc');
    const round = num('inv_round');
    let grand = subtotal + cgst + sgst - disc + round;
    if (grand < 0) grand = 0;

    document.getElementById('subtotal_v').innerText = subtotal.toFixed(2);
    document.getElementById('cgst_v').innerText = cgst.toFixed(2);
    document.getElementById('sgst_v').innerText = sgst.toFixed(2);
    document.getElementById('profit_v').innerText = money(profit);
    document.getElementById('profit_v').style.color = profit >= 0 ? '#166534' : '#dc2626';
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
        const select = document.getElementById('stock_product_id');
        select.innerHTML = '<option value="">-- Select Stock Product --</option>';
        allProductOptions.forEach(opt => select.appendChild(opt.cloneNode(true)));
        document.getElementById('stockProductInfo').style.display = 'none';
    }
}

function clearVehicle() {
    [
        'veh_stock_id','veh_desc','veh_brand','veh_model','veh_color','veh_chassis','veh_engine',
        'veh_motor','veh_battery','veh_battery_no','veh_battery_capacity',
        'veh_charger_brand','veh_charger_no','veh_charger_type'
    ].forEach(id => setVal(id, id === 'veh_stock_id' ? '0' : ''));

    ['veh_cost','veh_exshow','veh_rto','veh_reg','veh_rt','veh_ins','veh_gst'].forEach(id => setVal(id, '0.00'));
    setVal('veh_profit', '₹0.00');
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
        return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'})[m];
    });
}
</script>
</body>
</html>