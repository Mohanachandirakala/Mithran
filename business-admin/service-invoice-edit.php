<?php
session_start();
date_default_timezone_set('Asia/Kolkata');

require_once __DIR__ . '/includes/config.php';

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

function moneyText($amount): string
{
    return '₹' . number_format((float)$amount, 2);
}

function tableExists(mysqli $conn, string $table): bool
{
    $stmt = $conn->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1");
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
    $stmt = $conn->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
    if (!$stmt) return false;
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

function getOrCreateVehicleBrand(mysqli $conn, string $brandName): int
{
    $brandName = trim($brandName);
    if ($brandName === '' || !tableExists($conn, 'vehicle_brands')) return 0;

    $stmt = $conn->prepare("SELECT id FROM vehicle_brands WHERE brand_name = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('s', $brandName);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) return (int)$row['id'];
    }

    $stmt = $conn->prepare("INSERT INTO vehicle_brands (brand_name, status, created_at) VALUES (?, 1, NOW())");
    if (!$stmt) return 0;
    $stmt->bind_param('s', $brandName);
    $stmt->execute();
    $id = (int)$conn->insert_id;
    $stmt->close();
    return $id;
}

function getOrCreateVehicleCategory(mysqli $conn): int
{
    if (!tableExists($conn, 'vehicle_categories')) return 0;
    $name = 'Service Vehicle';

    $stmt = $conn->prepare("SELECT id FROM vehicle_categories WHERE category_name = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) return (int)$row['id'];
    }

    $stmt = $conn->prepare("INSERT INTO vehicle_categories (category_name) VALUES (?)");
    if (!$stmt) return 0;
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $id = (int)$conn->insert_id;
    $stmt->close();
    return $id;
}

function getOrCreateVehicleModel(mysqli $conn, int $businessId, int $brandId, string $modelName, string $vehicleType): int
{
    $modelName = trim($modelName);
    if ($modelName === '' || !tableExists($conn, 'vehicle_models')) return 0;

    $stmt = $conn->prepare("SELECT id FROM vehicle_models WHERE business_id = ? AND brand_id = ? AND model_name = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('iis', $businessId, $brandId, $modelName);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) return (int)$row['id'];
    }

    $categoryId = getOrCreateVehicleCategory($conn);
    $variant = '';
    $fuelType = $vehicleType === 'electric' ? 'electric' : 'petrol';
    $transmission = 'automatic';
    $price = 0.0;
    $gst = 0.0;

    $stmt = $conn->prepare("INSERT INTO vehicle_models (business_id, brand_id, category_id, model_name, variant_name, vehicle_type, fuel_type, transmission, ex_showroom_price, gst_percent, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())");
    if (!$stmt) return 0;
    $stmt->bind_param('iiisssssdd', $businessId, $brandId, $categoryId, $modelName, $variant, $vehicleType, $fuelType, $transmission, $price, $gst);
    $stmt->execute();
    $id = (int)$conn->insert_id;
    $stmt->close();
    return $id;
}

function calculateInclusiveLine(float $qty, float $priceInclGst, float $discount, float $gstPercent): array
{
    $gross = $qty * $priceInclGst;
    $lineTotal = $gross - $discount;
    if ($lineTotal < 0) $lineTotal = 0;

    if ($gstPercent > 0) {
        $taxable = $lineTotal / (1 + ($gstPercent / 100));
    } else {
        $taxable = $lineTotal;
    }

    $tax = $lineTotal - $taxable;
    if ($tax < 0) $tax = 0;

    return [round($taxable, 2), round($tax, 2), round($lineTotal, 2)];
}

function addProductStock(mysqli $conn, int $businessId, int $branchId, int $productId, float $qty): void
{
    if ($productId <= 0 || $qty <= 0) return;
    $qtySql = (float)$qty;

    if (tableExists($conn, 'products')) {
        $conn->query("UPDATE products SET stock_qty = COALESCE(stock_qty,0) + {$qtySql} WHERE id = {$productId} AND business_id = {$businessId}");
    }

    if (tableExists($conn, 'product_stock')) {
        $conn->query("UPDATE product_stock SET qty_available = COALESCE(qty_available,0) + {$qtySql} WHERE product_id = {$productId} AND business_id = {$businessId} AND branch_id = {$branchId}");
    }
}

function deductProductStock(mysqli $conn, int $businessId, int $branchId, int $productId, float $qty, int $userId, string $refTable, int $refId): void
{
    if ($productId <= 0 || $qty <= 0) return;
    $qtySql = (float)$qty;

    if (tableExists($conn, 'products')) {
        $conn->query("UPDATE products SET stock_qty = GREATEST(COALESCE(stock_qty,0) - {$qtySql}, 0) WHERE id = {$productId} AND business_id = {$businessId}");
    }

    if (tableExists($conn, 'product_stock')) {
        $conn->query("UPDATE product_stock SET qty_available = GREATEST(COALESCE(qty_available,0) - {$qtySql}, 0) WHERE product_id = {$productId} AND business_id = {$businessId} AND branch_id = {$branchId}");
    }

    if (tableExists($conn, 'stock_movements')) {
        $type = 'product';
        $movement = 'service_use';
        $price = 0.0;
        $date = date('Y-m-d H:i:s');
        $notes = 'Used in updated service invoice';
        $stmt = $conn->prepare("INSERT INTO stock_movements (business_id, branch_id, item_type, item_id, movement_type, ref_table, ref_id, qty, unit_price, movement_date, notes, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        if ($stmt) {
            $stmt->bind_param('iisissiddssi', $businessId, $branchId, $type, $productId, $movement, $refTable, $refId, $qty, $price, $date, $notes, $userId);
            $stmt->execute();
            $stmt->close();
        }
    }
}

/* -------------------------------------------------------
   LOGIN VALIDATION
------------------------------------------------------- */
$loggedUser = null;
if (tableExists($conn, 'business_users') && tableExists($conn, 'businesses')) {
    $stmt = $conn->prepare("SELECT bu.id, bu.full_name, bu.role, bu.status, b.business_name, b.status AS business_status FROM business_users bu INNER JOIN businesses b ON b.id = bu.business_id WHERE bu.id = ? AND bu.business_id = ? LIMIT 1");
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

$requiredTables = ['service_invoices', 'service_job_cards', 'service_complaints', 'service_job_part_items', 'service_job_labor_items', 'customers', 'customer_vehicles'];
foreach ($requiredTables as $table) {
    if (!tableExists($conn, $table)) {
        die(h($table) . ' table not found.');
    }
}

$invoiceId = isset($_GET['id']) ? (int)$_GET['id'] : (int)($_POST['invoice_id'] ?? 0);
if ($invoiceId <= 0) {
    header('Location: service-invoices.php?error=invalid_invoice');
    exit;
}

/* -------------------------------------------------------
   MASTER DATA
------------------------------------------------------- */
$branches = fetchAllAssoc($conn, "SELECT id, branch_name, branch_code FROM branches WHERE business_id = {$businessId} AND status = 'active' ORDER BY branch_name ASC");
$customers = fetchAllAssoc($conn, "SELECT id, full_name, mobile FROM customers WHERE business_id = {$businessId} ORDER BY full_name ASC");
$vehicleBrands = tableExists($conn, 'vehicle_brands') ? fetchAllAssoc($conn, "SELECT id, brand_name FROM vehicle_brands WHERE status = 1 ORDER BY brand_name ASC") : [];
$serviceLabours = tableExists($conn, 'service_labor_master') ? fetchAllAssoc($conn, "SELECT id, labor_name, service_type, price, gst_percent FROM service_labor_master WHERE business_id = {$businessId} AND status = 1 ORDER BY labor_name ASC") : [];
$paymentMethods = tableExists($conn, 'payment_methods') ? fetchAllAssoc($conn, "SELECT id, method_name FROM payment_methods WHERE business_id = {$businessId} AND status = 1 ORDER BY method_name ASC") : [];

$customerVehicles = fetchAllAssoc($conn, "
    SELECT cv.*, c.full_name, c.mobile, vb.brand_name, vm.model_name
    FROM customer_vehicles cv
    INNER JOIN customers c ON c.id = cv.customer_id
    LEFT JOIN vehicle_brands vb ON vb.id = cv.brand_id
    LEFT JOIN vehicle_models vm ON vm.id = cv.model_id
    WHERE cv.business_id = {$businessId} AND cv.active_status = 1
    ORDER BY cv.id DESC
");

$hasProductStock = tableExists($conn, 'product_stock');
$stockProducts = [];
if (tableExists($conn, 'products')) {
    $stockExpr = $hasProductStock ? "COALESCE(ps.qty_available, p.stock_qty, 0)" : "COALESCE(p.stock_qty, 0)";
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
            {$stockExpr} AS stock_qty
        FROM products p
        " . ($hasProductStock ? "LEFT JOIN product_stock ps ON ps.product_id = p.id AND ps.business_id = p.business_id AND ps.branch_id = " . (int)$currentBranchId : "") . "
        WHERE p.business_id = {$businessId} AND p.status = 1
        ORDER BY p.product_name ASC
    ");
}

/* -------------------------------------------------------
   CURRENT INVOICE DATA
------------------------------------------------------- */
function loadServiceInvoice(mysqli $conn, int $businessId, int $invoiceId): ?array
{
    $stmt = $conn->prepare("
        SELECT
            si.*,
            sj.jobcard_no,
            sj.customer_vehicle_id,
            sj.service_date,
            sj.opening_km,
            sj.fuel_level,
            sj.battery_percentage,
            sj.job_status,
            sj.washing_required,
            sj.road_test_required,
            sj.estimated_amount,
            sj.final_amount,
            sj.customer_voice,
            sj.technician_observation,
            sj.recommendation
        FROM service_invoices si
        INNER JOIN service_job_cards sj ON sj.id = si.jobcard_id
        WHERE si.id = ? AND si.business_id = ?
        LIMIT 1
    ");
    if (!$stmt) return null;
    $stmt->bind_param('ii', $invoiceId, $businessId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

$invoice = loadServiceInvoice($conn, $businessId, $invoiceId);
if (!$invoice) {
    header('Location: service-invoices.php?error=invoice_not_found');
    exit;
}

$success = '';
$error = '';

/* -------------------------------------------------------
   UPDATE SAVE
------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form = [
        'branch_id' => (int)($_POST['branch_id'] ?? 0),
        'invoice_no' => trim($_POST['invoice_no'] ?? ''),
        'jobcard_no' => trim($_POST['jobcard_no'] ?? ''),
        'invoice_date' => trim($_POST['invoice_date'] ?? ''),
        'customer_id' => (int)($_POST['customer_id'] ?? 0),
        'customer_vehicle_id' => (int)($_POST['customer_vehicle_id'] ?? 0),
        'opening_km' => (int)($_POST['opening_km'] ?? 0),
        'fuel_level' => trim($_POST['fuel_level'] ?? ''),
        'battery_percentage' => trim($_POST['battery_percentage'] ?? ''),
        'customer_voice' => trim($_POST['customer_voice'] ?? ''),
        'technician_observation' => trim($_POST['technician_observation'] ?? ''),
        'recommendation' => trim($_POST['recommendation'] ?? ''),
        'discount_amount' => (float)($_POST['discount_amount'] ?? 0),
        'paid_amount' => (float)($_POST['paid_amount'] ?? 0),
        'payment_method_id' => (int)($_POST['payment_method_id'] ?? 0),
        'reference_no' => trim($_POST['reference_no'] ?? ''),
        'invoice_status' => trim($_POST['invoice_status'] ?? 'confirmed')
    ];

    $newCustomer = [
        'full_name' => trim($_POST['new_full_name'] ?? ''),
        'mobile' => trim($_POST['new_mobile'] ?? ''),
        'alternate_mobile' => trim($_POST['new_alternate_mobile'] ?? ''),
        'email' => trim($_POST['new_email'] ?? ''),
        'address_line1' => trim($_POST['new_address_line1'] ?? ''),
        'city' => trim($_POST['new_city'] ?? ''),
        'state' => trim($_POST['new_state'] ?? ''),
        'pincode' => trim($_POST['new_pincode'] ?? '')
    ];

    $newVehicle = [
        'vehicle_type' => trim($_POST['vehicle_type'] ?? 'electric'),
        'brand_name' => trim($_POST['vehicle_brand_name'] ?? ''),
        'model_name' => trim($_POST['vehicle_model_name'] ?? ''),
        'registration_no' => trim($_POST['registration_no'] ?? ''),
        'chassis_no' => trim($_POST['chassis_no'] ?? ''),
        'engine_no' => trim($_POST['engine_no'] ?? ''),
        'motor_no' => trim($_POST['motor_no'] ?? ''),
        'color' => trim($_POST['color'] ?? ''),
        'battery_brand' => trim($_POST['battery_brand'] ?? ''),
        'battery_no' => trim($_POST['battery_no'] ?? ''),
        'battery_capacity' => trim($_POST['battery_capacity'] ?? ''),
        'charger_brand' => trim($_POST['charger_brand'] ?? ''),
        'charger_no' => trim($_POST['charger_no'] ?? ''),
        'charger_type' => trim($_POST['charger_type'] ?? '')
    ];

    $complaints = [];
    foreach (($_POST['complaints'] ?? []) as $row) {
        $text = trim($row['text'] ?? '');
        if ($text === '') continue;
        $priority = trim($row['priority'] ?? 'medium');
        if (!in_array($priority, ['low', 'medium', 'high'], true)) $priority = 'medium';
        $status = trim($row['status'] ?? 'pending');
        if (!in_array($status, ['pending', 'checked', 'resolved', 'not_resolved'], true)) $status = 'pending';
        $complaints[] = ['text' => $text, 'priority' => $priority, 'status' => $status];
    }

    $partsByProduct = [];
    foreach (($_POST['parts'] ?? []) as $row) {
        $pid = (int)($row['product_id'] ?? 0);
        $qty = (float)($row['qty'] ?? 0);
        $price = (float)($row['unit_price'] ?? 0);
        $discount = (float)($row['discount_amount'] ?? 0);
        $gst = (float)($row['gst_percent'] ?? 0);
        if ($pid <= 0 || $qty <= 0 || $price < 0) continue;

        if (!isset($partsByProduct[$pid])) {
            $partsByProduct[$pid] = ['pid' => $pid, 'qty' => 0.0, 'price' => $price, 'discount' => 0.0, 'gst' => $gst, 'tax' => 0.0, 'lineTotal' => 0.0];
        }
        $partsByProduct[$pid]['qty'] += $qty;
        $partsByProduct[$pid]['discount'] += $discount;
        $partsByProduct[$pid]['price'] = $price;
        $partsByProduct[$pid]['gst'] = $gst;
    }

    $parts = [];
    foreach ($partsByProduct as $p) {
        [$taxable, $tax, $lineTotal] = calculateInclusiveLine((float)$p['qty'], (float)$p['price'], (float)$p['discount'], (float)$p['gst']);
        $p['taxable'] = $taxable;
        $p['tax'] = $tax;
        $p['lineTotal'] = $lineTotal;
        $parts[] = $p;
    }

    $laboursByKey = [];
    foreach (($_POST['labours'] ?? []) as $row) {
        $laborId = (int)($row['labor_id'] ?? 0);
        $desc = trim($row['description'] ?? '');
        $qty = (float)($row['qty'] ?? 0);
        $price = (float)($row['unit_price'] ?? 0);
        $discount = (float)($row['discount_amount'] ?? 0);
        $gst = (float)($row['gst_percent'] ?? 0);
        if ($desc === '' || $qty <= 0 || $price < 0) continue;
        $key = $laborId . '|' . strtolower($desc) . '|' . number_format($price, 2, '.', '') . '|' . number_format($gst, 2, '.', '');
        if (!isset($laboursByKey[$key])) {
            $laboursByKey[$key] = ['laborId' => $laborId, 'desc' => $desc, 'qty' => 0.0, 'price' => $price, 'discount' => 0.0, 'gst' => $gst, 'tax' => 0.0, 'lineTotal' => 0.0];
        }
        $laboursByKey[$key]['qty'] += $qty;
        $laboursByKey[$key]['discount'] += $discount;
    }

    $labours = [];
    foreach ($laboursByKey as $l) {
        [$taxable, $tax, $lineTotal] = calculateInclusiveLine((float)$l['qty'], (float)$l['price'], (float)$l['discount'], (float)$l['gst']);
        $l['taxable'] = $taxable;
        $l['tax'] = $tax;
        $l['lineTotal'] = $lineTotal;
        $labours[] = $l;
    }

    if ($form['branch_id'] <= 0) {
        $error = 'Please select active branch.';
    } elseif ($form['invoice_no'] === '') {
        $error = 'Service invoice number is required.';
    } elseif ($form['jobcard_no'] === '') {
        $error = 'Job card number is required.';
    } elseif ($form['invoice_date'] === '') {
        $error = 'Invoice date is required.';
    } elseif ($form['customer_id'] <= 0 && ($newCustomer['full_name'] === '' || $newCustomer['mobile'] === '')) {
        $error = 'Please select existing customer OR enter new customer name and mobile.';
    } elseif (empty($complaints)) {
        $error = 'Please enter at least one complaint.';
    } elseif (empty($parts) && empty($labours)) {
        $error = 'Please add at least one product or labour charge.';
    }

    if ($error === '') {
        $duplicateStmt = $conn->prepare("SELECT id FROM service_invoices WHERE business_id = ? AND invoice_no = ? AND id <> ? LIMIT 1");
        if ($duplicateStmt) {
            $duplicateStmt->bind_param('isi', $businessId, $form['invoice_no'], $invoiceId);
            $duplicateStmt->execute();
            $duplicate = $duplicateStmt->get_result()->fetch_assoc();
            $duplicateStmt->close();
            if ($duplicate) $error = 'This service invoice number already exists.';
        }
    }

    if ($error === '') {
        $subtotal = 0.0;
        $cgst = 0.0;
        $sgst = 0.0;

        foreach ($parts as $p) {
            $subtotal += (float)$p['taxable'];
            $cgst += ((float)$p['tax']) / 2;
            $sgst += ((float)$p['tax']) / 2;
        }
        foreach ($labours as $l) {
            $subtotal += (float)$l['taxable'];
            $cgst += ((float)$l['tax']) / 2;
            $sgst += ((float)$l['tax']) / 2;
        }

        $discountAmount = max(0, (float)$form['discount_amount']);
        $grandTotal = $subtotal + $cgst + $sgst - $discountAmount;
        if ($grandTotal < 0) $grandTotal = 0;

        $paidAmount = max(0, (float)$form['paid_amount']);
        if ($paidAmount > $grandTotal) $paidAmount = $grandTotal;
        $balance = $grandTotal - $paidAmount;
        $paymentStatus = ($grandTotal > 0 && $paidAmount >= $grandTotal) ? 'paid' : ($paidAmount > 0 ? 'partial' : 'unpaid');
        $invoiceStatus = in_array($form['invoice_status'], ['draft', 'confirmed', 'cancelled'], true) ? $form['invoice_status'] : 'confirmed';
        $invoiceDateDb = date('Y-m-d H:i:s', strtotime($form['invoice_date']));

        $conn->begin_transaction();
        try {
            $customerId = (int)$form['customer_id'];

            if ($customerId <= 0) {
                $customerCode = 'CUST' . str_pad((string)mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);
                $stmt = $conn->prepare("INSERT INTO customers (business_id, branch_id, customer_code, full_name, mobile, alternate_mobile, email, address_line1, city, state, pincode, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
                if (!$stmt) throw new Exception('Customer insert prepare failed: ' . $conn->error);
                $stmt->bind_param('iisssssssss', $businessId, $form['branch_id'], $customerCode, $newCustomer['full_name'], $newCustomer['mobile'], $newCustomer['alternate_mobile'], $newCustomer['email'], $newCustomer['address_line1'], $newCustomer['city'], $newCustomer['state'], $newCustomer['pincode']);
                if (!$stmt->execute()) throw new Exception('Customer save failed: ' . $stmt->error);
                $customerId = (int)$conn->insert_id;
                $stmt->close();
            }

            $customerVehicleId = (int)$form['customer_vehicle_id'];
            if ($customerVehicleId <= 0) {
                $brandId = getOrCreateVehicleBrand($conn, $newVehicle['brand_name']);
                $vehicleType = in_array($newVehicle['vehicle_type'], ['fuel', 'electric'], true) ? $newVehicle['vehicle_type'] : 'electric';
                $modelId = getOrCreateVehicleModel($conn, $businessId, $brandId, $newVehicle['model_name'], $vehicleType);
                $stmt = $conn->prepare("INSERT INTO customer_vehicles (business_id, customer_id, vehicle_stock_id, brand_id, model_id, vehicle_type, registration_no, chassis_no, engine_no, motor_no, battery_brand, battery_no, battery_capacity, charger_brand, charger_no, charger_type, color, current_km, active_status, created_at) VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())");
                if (!$stmt) throw new Exception('Vehicle insert prepare failed: ' . $conn->error);
                $stmt->bind_param('iiiissssssssssssi', $businessId, $customerId, $brandId, $modelId, $vehicleType, $newVehicle['registration_no'], $newVehicle['chassis_no'], $newVehicle['engine_no'], $newVehicle['motor_no'], $newVehicle['battery_brand'], $newVehicle['battery_no'], $newVehicle['battery_capacity'], $newVehicle['charger_brand'], $newVehicle['charger_no'], $newVehicle['charger_type'], $newVehicle['color'], $form['opening_km']);
                if (!$stmt->execute()) throw new Exception('Vehicle save failed: ' . $stmt->error);
                $customerVehicleId = (int)$conn->insert_id;
                $stmt->close();
            } else {
                $stmt = $conn->prepare("UPDATE customer_vehicles SET current_km = ? WHERE id = ? AND business_id = ? LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param('iii', $form['opening_km'], $customerVehicleId, $businessId);
                    $stmt->execute();
                    $stmt->close();
                }
            }

            /* Restore previous deducted product stock before replacing items */
            $oldParts = fetchAllAssoc($conn, "SELECT product_id, qty, stock_deducted FROM service_job_part_items WHERE jobcard_id = " . (int)$invoice['jobcard_id']);
            foreach ($oldParts as $oldPart) {
                if ((int)($oldPart['stock_deducted'] ?? 0) === 1) {
                    addProductStock($conn, $businessId, (int)$invoice['branch_id'], (int)$oldPart['product_id'], (float)$oldPart['qty']);
                }
            }

            $jobStatus = $invoiceStatus === 'cancelled' ? 'cancelled' : 'delivered';
            $washingRequired = isset($_POST['washing_required']) ? 1 : 0;
            $roadTestRequired = isset($_POST['road_test_required']) ? 1 : 0;

            $stmt = $conn->prepare("UPDATE service_job_cards SET branch_id = ?, jobcard_no = ?, customer_id = ?, customer_vehicle_id = ?, service_date = ?, opening_km = ?, fuel_level = ?, battery_percentage = ?, job_status = ?, washing_required = ?, road_test_required = ?, estimated_amount = ?, final_amount = ?, customer_voice = ?, technician_observation = ?, recommendation = ?, closed_at = NOW() WHERE id = ? AND business_id = ? LIMIT 1");
            if (!$stmt) throw new Exception('Job card update prepare failed: ' . $conn->error);
            $jobcardId = (int)$invoice['jobcard_id'];
            $stmt->bind_param('isiisisssiiddsssii', $form['branch_id'], $form['jobcard_no'], $customerId, $customerVehicleId, $invoiceDateDb, $form['opening_km'], $form['fuel_level'], $form['battery_percentage'], $jobStatus, $washingRequired, $roadTestRequired, $grandTotal, $grandTotal, $form['customer_voice'], $form['technician_observation'], $form['recommendation'], $jobcardId, $businessId);
            if (!$stmt->execute()) throw new Exception('Job card update failed: ' . $stmt->error);
            $stmt->close();

            $stmt = $conn->prepare("UPDATE service_invoices SET branch_id = ?, invoice_no = ?, customer_id = ?, invoice_date = ?, subtotal = ?, discount_amount = ?, cgst_amount = ?, sgst_amount = ?, igst_amount = 0, grand_total = ?, paid_amount = ?, balance_amount = ?, payment_status = ?, invoice_status = ? WHERE id = ? AND business_id = ? LIMIT 1");
            if (!$stmt) throw new Exception('Service invoice update prepare failed: ' . $conn->error);
            $stmt->bind_param('isisdddddddssii', $form['branch_id'], $form['invoice_no'], $customerId, $invoiceDateDb, $subtotal, $discountAmount, $cgst, $sgst, $grandTotal, $paidAmount, $balance, $paymentStatus, $invoiceStatus, $invoiceId, $businessId);
            if (!$stmt->execute()) throw new Exception('Service invoice update failed: ' . $stmt->error);
            $stmt->close();

            $conn->query("DELETE FROM service_complaints WHERE jobcard_id = " . $jobcardId);
            $conn->query("DELETE FROM service_job_part_items WHERE jobcard_id = " . $jobcardId);
            $conn->query("DELETE FROM service_job_labor_items WHERE jobcard_id = " . $jobcardId);

            $stmt = $conn->prepare("INSERT INTO service_complaints (jobcard_id, complaint_text, priority, status) VALUES (?, ?, ?, ?)");
            if (!$stmt) throw new Exception('Complaint insert prepare failed: ' . $conn->error);
            foreach ($complaints as $c) {
                $stmt->bind_param('isss', $jobcardId, $c['text'], $c['priority'], $c['status']);
                if (!$stmt->execute()) throw new Exception('Complaint save failed: ' . $stmt->error);
            }
            $stmt->close();

            $stmt = $conn->prepare("INSERT INTO service_job_part_items (jobcard_id, product_id, qty, unit_price, discount_amount, gst_percent, tax_amount, line_total, stock_deducted) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)");
            if (!$stmt) throw new Exception('Part item insert prepare failed: ' . $conn->error);
            foreach ($parts as $p) {
                $stmt->bind_param('iidddddd', $jobcardId, $p['pid'], $p['qty'], $p['price'], $p['discount'], $p['gst'], $p['tax'], $p['lineTotal']);
                if (!$stmt->execute()) throw new Exception('Part item save failed: ' . $stmt->error);
                deductProductStock($conn, $businessId, $form['branch_id'], (int)$p['pid'], (float)$p['qty'], $businessUserId, 'service_job_cards', $jobcardId);
            }
            $stmt->close();

            $stmt = $conn->prepare("INSERT INTO service_job_labor_items (jobcard_id, labor_id, description, qty, unit_price, discount_amount, gst_percent, tax_amount, line_total) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            if (!$stmt) throw new Exception('Labour item insert prepare failed: ' . $conn->error);
            foreach ($labours as $l) {
                $laborId = (int)$l['laborId'];
                $stmt->bind_param('iisdddddd', $jobcardId, $laborId, $l['desc'], $l['qty'], $l['price'], $l['discount'], $l['gst'], $l['tax'], $l['lineTotal']);
                if (!$stmt->execute()) throw new Exception('Labour item save failed: ' . $stmt->error);
            }
            $stmt->close();

            if (tableExists($conn, 'payments')) {
                $stmt = $conn->prepare("DELETE FROM payments WHERE business_id = ? AND payment_for = 'service' AND ref_id = ?");
                if ($stmt) {
                    $stmt->bind_param('ii', $businessId, $invoiceId);
                    $stmt->execute();
                    $stmt->close();
                }

                if ($paidAmount > 0 && $form['payment_method_id'] > 0) {
                    $paymentFor = 'service';
                    $notes = 'Updated service invoice payment: ' . $form['invoice_no'];
                    $stmt = $conn->prepare("INSERT INTO payments (business_id, branch_id, payment_date, customer_id, payment_for, ref_id, payment_method_id, amount, reference_no, transaction_no, notes, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, '', ?, ?, NOW())");
                    if ($stmt) {
                        $stmt->bind_param('iisisiidssi', $businessId, $form['branch_id'], $invoiceDateDb, $customerId, $paymentFor, $invoiceId, $form['payment_method_id'], $paidAmount, $form['reference_no'], $notes, $businessUserId);
                        $stmt->execute();
                        $stmt->close();
                    }
                }
            }

            if (tableExists($conn, 'audit_logs')) {
                $action = 'Update';
                $module = 'Service Invoice';
                $table = 'service_invoices';
                $desc = 'Updated service invoice: ' . $form['invoice_no'] . ' | Total: ₹' . number_format($grandTotal, 2);
                $ip = $_SERVER['REMOTE_ADDR'] ?? null;
                $stmt = $conn->prepare("INSERT INTO audit_logs (business_id, branch_id, user_id, action, module_name, ref_table, ref_id, description, ip_address, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                if ($stmt) {
                    $stmt->bind_param('iiisssiss', $businessId, $form['branch_id'], $businessUserId, $action, $module, $table, $invoiceId, $desc, $ip);
                    $stmt->execute();
                    $stmt->close();
                }
            }

            $conn->commit();
            header('Location: service-invoice-view.php?id=' . $invoiceId . '&success=' . urlencode('Service invoice updated successfully.'));
            exit;
        } catch (Throwable $e) {
            $conn->rollback();
            $error = $e->getMessage();
        }
    }

    $invoice = loadServiceInvoice($conn, $businessId, $invoiceId);
}

/* -------------------------------------------------------
   PRELOAD FORM DATA
------------------------------------------------------- */
$jobcardId = (int)$invoice['jobcard_id'];

$complaintsExisting = fetchAllAssoc($conn, "SELECT complaint_text, priority, status FROM service_complaints WHERE jobcard_id = {$jobcardId} ORDER BY id ASC");
$partsExisting = fetchAllAssoc($conn, "
    SELECT p.id AS product_id, p.product_name, sp.qty, sp.unit_price, sp.discount_amount, sp.gst_percent, sp.tax_amount, sp.line_total
    FROM service_job_part_items sp
    LEFT JOIN products p ON p.id = sp.product_id
    WHERE sp.jobcard_id = {$jobcardId}
    ORDER BY sp.id ASC
");
$laboursExisting = fetchAllAssoc($conn, "
    SELECT labor_id, description, qty, unit_price, discount_amount, gst_percent, tax_amount, line_total
    FROM service_job_labor_items
    WHERE jobcard_id = {$jobcardId}
    ORDER BY id ASC
");

$initialItems = [];
foreach ($partsExisting as $p) {
    $initialItems[] = [
        'type' => 'part',
        'product_id' => (int)$p['product_id'],
        'description' => (string)($p['product_name'] ?? 'Product'),
        'qty' => (float)$p['qty'],
        'unit_price' => (float)$p['unit_price'],
        'discount_amount' => (float)$p['discount_amount'],
        'gst_percent' => (float)$p['gst_percent'],
        'gst_type' => 'inclusive'
    ];
}
foreach ($laboursExisting as $l) {
    $initialItems[] = [
        'type' => 'labour',
        'labor_id' => (int)($l['labor_id'] ?? 0),
        'description' => (string)$l['description'],
        'qty' => (float)$l['qty'],
        'unit_price' => (float)$l['unit_price'],
        'discount_amount' => (float)$l['discount_amount'],
        'gst_percent' => (float)$l['gst_percent'],
        'gst_type' => 'inclusive'
    ];
}

$invoiceDateInput = !empty($invoice['invoice_date']) ? date('Y-m-d\TH:i', strtotime($invoice['invoice_date'])) : date('Y-m-d\TH:i');
$selectedPaymentMethodId = 0;
$selectedReferenceNo = '';
if (tableExists($conn, 'payments')) {
    $stmt = $conn->prepare("SELECT payment_method_id, reference_no FROM payments WHERE business_id = ? AND payment_for = 'service' AND ref_id = ? ORDER BY id DESC LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('ii', $businessId, $invoiceId);
        $stmt->execute();
        $payRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($payRow) {
            $selectedPaymentMethodId = (int)$payRow['payment_method_id'];
            $selectedReferenceNo = (string)($payRow['reference_no'] ?? '');
        }
    }
}

$pageTitle = 'Edit Service Invoice';
$currentPage = 'service-invoices';
?>
<!doctype html>
<html lang="en">
    
<?php include('includes/head.php'); ?>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Edit Service Invoice</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
<style>
:root{--primary:#f6ad22;--primary2:#ffc247;--dark:#0f172a;--muted:#64748b;--line:#dbe3ef;--bg:#f4f7fb;--card:#ffffff}
*{box-sizing:border-box}
html,body{height:100%;width:100%;margin:0;overflow:hidden}
body{background:var(--bg);font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#0f172a;font-size:14px;line-height:1.35}
.app-container{height:100vh;width:100%;display:flex;flex-direction:column;overflow:hidden;background:var(--bg)}
.app-header{flex:0 0 auto;min-height:74px;background:linear-gradient(135deg,#111827,#1f2937);color:#fff;padding:14px 22px;display:flex;justify-content:space-between;align-items:center;gap:14px;box-shadow:0 8px 24px rgba(15,23,42,.14);z-index:10}
.app-header h2{margin:0;font-size:23px;font-weight:850;line-height:1.15;letter-spacing:-.02em}.app-header small{display:block;margin-top:3px;font-size:13px;color:rgba(255,255,255,.78);line-height:1.25}
.app-body{flex:1 1 auto;min-height:0;padding:14px;overflow:hidden}#serviceInvoiceForm{height:100%;min-height:0}
.invoice-layout{height:100%;min-height:0;display:grid;grid-template-columns:minmax(360px,430px) minmax(0,1fr);gap:14px;align-items:stretch}
.left-panel,.right-panel{min-height:0;height:100%;overflow-y:auto;overflow-x:hidden;padding:0 4px 14px 0;scrollbar-width:thin}.left-panel{display:flex;flex-direction:column;gap:12px}.right-panel{display:flex;flex-direction:column;gap:12px;padding-right:2px}.left-panel::-webkit-scrollbar,.right-panel::-webkit-scrollbar{width:7px}.left-panel::-webkit-scrollbar-thumb,.right-panel::-webkit-scrollbar-thumb{background:#cbd5e1;border-radius:999px}
.card{width:100%;flex:0 0 auto;background:var(--card);border:1px solid var(--line)!important;border-radius:14px!important;box-shadow:0 6px 18px rgba(15,23,42,.055)!important;overflow:visible!important;margin:0!important}.card-header{background:#fff!important;font-weight:850;border-bottom:1px solid var(--line)!important;padding:11px 14px!important;border-radius:14px 14px 0 0!important;min-height:46px}.card-body{padding:14px!important;overflow:visible!important}
.section-label{display:block;font-weight:850;font-size:16px;margin:0 0 12px;color:#111827;line-height:1.25}.form-label{display:block;font-size:13px;font-weight:750;margin:0 0 5px;color:#334155;line-height:1.25}
.form-control,.form-select{width:100%;min-height:39px;font-size:14px!important;line-height:1.25!important;padding:8px 11px!important;border-radius:9px!important;border:1px solid #cbd5e1!important;background-color:#fff!important;box-shadow:none!important}textarea.form-control{min-height:74px;resize:vertical}.form-control:focus,.form-select:focus{border-color:var(--primary)!important;box-shadow:0 0 0 3px rgba(246,173,34,.18)!important}
.btn{font-size:13px;border-radius:8px;font-weight:750;min-height:36px;display:inline-flex;align-items:center;justify-content:center;gap:4px}.btn-sm{min-height:31px;font-size:12px;padding:5px 10px}.btn-lg{min-height:44px;font-size:15px}.btn-warning{background:var(--primary)!important;border-color:var(--primary)!important;color:#111827!important}.btn-warning:hover{background:var(--primary2)!important;border-color:var(--primary2)!important;color:#111827!important}
.grid2,.grid3{display:grid;gap:11px;align-items:start}.grid2{grid-template-columns:minmax(0,1fr) minmax(0,1fr)}.grid3{grid-template-columns:repeat(3,minmax(0,1fr))}.field-row{margin-bottom:11px}.small-help{font-size:12px;color:var(--muted);margin-top:5px;line-height:1.35}.form-check{font-size:13px;font-weight:600;color:#334155;display:flex;align-items:center;gap:6px;margin:0}.form-check-input{margin:0!important}
.product-picker{border:1px solid #bfdbfe;background:#eff6ff;border-radius:12px;padding:12px;overflow:visible}.product-list{height:245px;overflow:auto;border:1px solid #dbeafe;background:#fff;border-radius:10px;margin-top:9px}.product-option{display:grid;grid-template-columns:22px minmax(0,1fr);gap:8px;padding:9px 10px;border-bottom:1px solid #eff6ff;cursor:pointer;align-items:start}.product-option:hover{background:#f8fafc}.product-option:last-child{border-bottom:0}.product-option input{margin-top:3px;width:16px;height:16px}.product-title{font-weight:850;font-size:13px;color:#0f172a;line-height:1.25;word-break:break-word}.product-meta{font-size:12px;color:#64748b;margin-top:2px;line-height:1.35;word-break:break-word}
.complaint-row{border:1px dashed #cbd5e1;border-radius:11px;padding:10px;margin-bottom:9px;background:#fff;overflow:visible}.complaint-inner{display:grid;grid-template-columns:minmax(0,1fr) 110px 132px 44px;gap:9px;align-items:stretch}.complaint-inner textarea{min-height:70px}.complaint-inner .btn{height:70px;min-height:70px}
.cart-wrap{max-height:315px;overflow:auto;border-bottom:1px solid var(--line)}.table{font-size:12px;margin-bottom:0;background:#fff}.table th{white-space:nowrap;background:#f8fafc!important;position:sticky;top:0;z-index:2;font-weight:850;color:#334155}.table td,.table th{vertical-align:middle;padding:8px!important}.table td:nth-child(2){min-width:190px;word-break:break-word}.total-box{background:#fff7ed;border:1px solid #fed7aa;border-radius:12px;padding:12px;margin-top:2px}.total-row{display:flex;justify-content:space-between;gap:12px;margin-bottom:7px;font-size:13px}.total-row strong{font-size:16px}.empty-cart{text-align:center;color:#94a3b8;padding:30px 12px!important}.badge-soft{background:#fef3c7;color:#92400e;border:1px solid #fde68a;border-radius:999px;padding:4px 10px;font-size:12px;font-weight:850;white-space:nowrap}.alert{border-radius:12px;margin-bottom:12px}.toast-container{position:fixed;top:18px;right:18px;z-index:9999}.custom-toast{min-width:260px;border:0;border-radius:14px;box-shadow:0 12px 30px rgba(15,23,42,.22);overflow:hidden}.custom-toast .toast-body{font-size:13px;font-weight:700;padding:12px 14px}
@media(max-width:1250px){html,body{overflow:auto;height:auto}.app-container{height:auto;min-height:100vh;overflow:visible}.app-body{overflow:visible;padding:12px}#serviceInvoiceForm{height:auto}.invoice-layout{height:auto;display:block}.left-panel,.right-panel{height:auto;max-height:none;overflow:visible;padding:0;display:flex;flex-direction:column;gap:12px}.right-panel{margin-top:12px}.cart-wrap{max-height:none;overflow:auto}}
@media(max-width:768px){.app-header{padding:12px 14px;align-items:flex-start;flex-direction:column}.app-header h2{font-size:20px}.app-header .d-flex{width:100%}.app-header .btn{flex:1}.grid2,.grid3{grid-template-columns:1fr!important}.product-picker .grid3>div[style]{grid-column:auto!important}.complaint-inner{grid-template-columns:1fr}.complaint-inner .btn{height:40px;min-height:40px;width:100%}.product-list{height:230px}.card-body{padding:12px!important}}
</style>
</head>
<body>
<div class="toast-container" id="toastContainer"></div>
<div class="app-container">
    <div class="app-header">
        <div>
            <h2>✏️ Edit Service Invoice</h2>
            <small>Update service job card, complaints, GST inclusive products, labour and payment details</small>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="service-invoices.php" class="btn btn-light btn-sm"><i class="ri-arrow-left-line me-1"></i>Back</a>
            <a href="service-invoice-view.php?id=<?= (int)$invoiceId ?>" class="btn btn-outline-light btn-sm"><i class="ri-eye-line me-1"></i>View</a>
            <a href="service-invoice-print.php?id=<?= (int)$invoiceId ?>" target="_blank" class="btn btn-outline-light btn-sm"><i class="ri-printer-line me-1"></i>Print</a>
        </div>
    </div>

    <div class="app-body">
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show"><i class="ri-error-warning-line me-1"></i><?= h($error) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>

        <form method="post" id="serviceInvoiceForm">
            <input type="hidden" name="invoice_id" value="<?= (int)$invoiceId ?>">
            <div class="invoice-layout">
                <div class="left-panel">
                    <div class="card">
                        <div class="card-body">
                            <span class="section-label">📋 Invoice Details</span>
                            <div class="grid2">
                                <div>
                                    <label class="form-label">Active Branch *</label>
                                    <select name="branch_id" class="form-select" required>
                                        <option value="">-- Select Branch --</option>
                                        <?php foreach ($branches as $b): ?>
                                            <option value="<?= (int)$b['id'] ?>" <?= ((int)$invoice['branch_id'] === (int)$b['id']) ? 'selected' : '' ?>><?= h($b['branch_name'] . ' (' . $b['branch_code'] . ')') ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="form-label">Service Invoice No *</label>
                                    <input type="text" name="invoice_no" class="form-control" value="<?= h($invoice['invoice_no']) ?>" required>
                                </div>
                            </div>
                            <div class="grid2 mt-2">
                                <div>
                                    <label class="form-label">Job Card No *</label>
                                    <input type="text" name="jobcard_no" class="form-control" value="<?= h($invoice['jobcard_no']) ?>" required>
                                </div>
                                <div>
                                    <label class="form-label">Date & Time *</label>
                                    <input type="datetime-local" name="invoice_date" class="form-control" value="<?= h($invoiceDateInput) ?>" required>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-body">
                            <span class="section-label">👤 Customer Details</span>
                            <div class="field-row">
                                <label class="form-label">Existing Customer</label>
                                <select name="customer_id" id="customer_id" class="form-select" onchange="toggleNewCustomer(); refreshVehicleList();">
                                    <option value="0">-- Create New Customer --</option>
                                    <?php foreach ($customers as $c): ?>
                                        <option value="<?= (int)$c['id'] ?>" <?= ((int)$invoice['customer_id'] === (int)$c['id']) ? 'selected' : '' ?>><?= h($c['full_name'] . ' - ' . $c['mobile']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="small-help">Change customer or select create new customer.</div>
                            </div>
                            <div id="newCustomerBox">
                                <div class="grid2">
                                    <div><label class="form-label">New Customer Name *</label><input type="text" name="new_full_name" class="form-control"></div>
                                    <div><label class="form-label">Mobile *</label><input type="text" name="new_mobile" class="form-control"></div>
                                </div>
                                <div class="grid2 mt-2">
                                    <div><label class="form-label">Alternate Mobile</label><input type="text" name="new_alternate_mobile" class="form-control"></div>
                                    <div><label class="form-label">Email</label><input type="email" name="new_email" class="form-control"></div>
                                </div>
                                <div class="field-row mt-2"><label class="form-label">Address</label><input type="text" name="new_address_line1" class="form-control"></div>
                                <div class="grid3">
                                    <div><label class="form-label">City</label><input type="text" name="new_city" class="form-control"></div>
                                    <div><label class="form-label">State</label><input type="text" name="new_state" class="form-control"></div>
                                    <div><label class="form-label">Pincode</label><input type="text" name="new_pincode" class="form-control"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-body">
                            <span class="section-label">🏍️ Vehicle Details</span>
                            <div class="field-row">
                                <label class="form-label">Existing Vehicle</label>
                                <select name="customer_vehicle_id" id="customer_vehicle_id" class="form-select" onchange="toggleNewVehicle()">
                                    <option value="0">-- Enter New Vehicle --</option>
                                </select>
                                <div class="small-help">Vehicles are loaded based on selected customer.</div>
                            </div>
                            <div id="newVehicleBox">
                                <div class="grid2">
                                    <div><label class="form-label">Vehicle Type</label><select name="vehicle_type" class="form-select"><option value="electric">Electric</option><option value="fuel">Fuel</option></select></div>
                                    <div><label class="form-label">Registration No</label><input type="text" name="registration_no" class="form-control"></div>
                                </div>
                                <div class="grid2 mt-2">
                                    <div><label class="form-label">Brand</label><input type="text" name="vehicle_brand_name" class="form-control" list="vehicleBrandList"></div>
                                    <div><label class="form-label">Model</label><input type="text" name="vehicle_model_name" class="form-control"></div>
                                </div>
                                <div class="grid2 mt-2">
                                    <div><label class="form-label">Chassis No</label><input type="text" name="chassis_no" class="form-control"></div>
                                    <div><label class="form-label">Engine No</label><input type="text" name="engine_no" class="form-control"></div>
                                </div>
                                <div class="grid2 mt-2">
                                    <div><label class="form-label">Motor No</label><input type="text" name="motor_no" class="form-control"></div>
                                    <div><label class="form-label">Color</label><input type="text" name="color" class="form-control"></div>
                                </div>
                                <div class="grid3 mt-2">
                                    <div><label class="form-label">Battery Brand</label><input type="text" name="battery_brand" class="form-control"></div>
                                    <div><label class="form-label">Battery No</label><input type="text" name="battery_no" class="form-control"></div>
                                    <div><label class="form-label">Capacity</label><input type="text" name="battery_capacity" class="form-control"></div>
                                </div>
                                <div class="grid3 mt-2">
                                    <div><label class="form-label">Charger Brand</label><input type="text" name="charger_brand" class="form-control"></div>
                                    <div><label class="form-label">Charger No</label><input type="text" name="charger_no" class="form-control"></div>
                                    <div><label class="form-label">Charger Type</label><input type="text" name="charger_type" class="form-control"></div>
                                </div>
                            </div>
                            <div class="grid3 mt-2">
                                <div><label class="form-label">Opening KM</label><input type="number" name="opening_km" class="form-control" value="<?= h($invoice['opening_km']) ?>"></div>
                                <div><label class="form-label">Fuel Level</label><input type="text" name="fuel_level" class="form-control" value="<?= h($invoice['fuel_level']) ?>" placeholder="Half / Full"></div>
                                <div><label class="form-label">Battery %</label><input type="text" name="battery_percentage" class="form-control" value="<?= h($invoice['battery_percentage']) ?>" placeholder="80%"></div>
                            </div>
                            <div class="d-flex gap-3 mt-2">
                                <label class="form-check"><input type="checkbox" name="washing_required" class="form-check-input" <?= ((int)$invoice['washing_required'] === 1) ? 'checked' : '' ?>> Washing Required</label>
                                <label class="form-check"><input type="checkbox" name="road_test_required" class="form-check-input" <?= ((int)$invoice['road_test_required'] === 1) ? 'checked' : '' ?>> Road Test Required</label>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-body">
                            <span class="section-label">📝 Complaints</span>
                            <div id="complaintBox"></div>
                            <button type="button" class="btn btn-outline-primary btn-sm" onclick="addComplaint()"><i class="ri-add-line"></i> Add Complaint</button>
                            <div class="field-row mt-2"><label class="form-label">Customer Voice</label><textarea name="customer_voice" class="form-control" rows="2"><?= h($invoice['customer_voice']) ?></textarea></div>
                            <div class="field-row"><label class="form-label">Technician Observation</label><textarea name="technician_observation" class="form-control" rows="2"><?= h($invoice['technician_observation']) ?></textarea></div>
                            <div class="field-row"><label class="form-label">Recommendation</label><textarea name="recommendation" class="form-control" rows="2"><?= h($invoice['recommendation']) ?></textarea></div>
                        </div>
                    </div>
                </div>

                <div class="right-panel">
                    <div class="card">
                        <div class="card-body">
                            <span class="section-label">🔧 Products Used <span class="badge-soft">GST Inclusive</span></span>
                            <div class="product-picker">
                                <div class="grid3">
                                    <div style="grid-column:span 2"><label class="form-label">Search Product</label><input type="text" id="productSearch" class="form-control" placeholder="Search product / code / brand" oninput="renderProductList()"></div>
                                    <div><label class="form-label">Default Qty</label><input type="number" step="0.01" id="defaultPartQty" class="form-control" value="1"></div>
                                </div>
                                <div class="product-list" id="productList"></div>
                                <div class="d-flex justify-content-between align-items-center mt-2">
                                    <span class="badge-soft" id="selectedCount">0 selected</span>
                                    <div class="d-flex gap-2">
                                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="clearProductSelection()">Clear</button>
                                        <button type="button" class="btn btn-sm btn-warning" onclick="addSelectedProducts()"><i class="ri-add-line"></i> Add Selected Products</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-body">
                            <span class="section-label">👨‍🔧 Labour Charges <span class="badge-soft">GST Inclusive</span></span>
                            <div class="grid3 mb-2">
                                <div style="grid-column:span 2">
                                    <label class="form-label">Labour Master</label>
                                    <select id="laborMaster" class="form-select" onchange="fillLabourMaster()">
                                        <option value="">-- Select Labour / Manual --</option>
                                        <?php foreach ($serviceLabours as $l): ?>
                                            <option value="<?= (int)$l['id'] ?>" data-name="<?= h($l['labor_name']) ?>" data-price="<?= h($l['price']) ?>" data-gst="<?= h($l['gst_percent']) ?>"><?= h($l['labor_name'] . ' - ₹' . number_format((float)$l['price'], 2)) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div><label class="form-label">GST %</label><input type="number" step="0.01" id="laborGst" class="form-control" value="18"></div>
                            </div>
                            <div class="grid3">
                                <div><label class="form-label">Description</label><input type="text" id="laborDesc" class="form-control" placeholder="Labour charge"></div>
                                <div><label class="form-label">Qty</label><input type="number" step="0.01" id="laborQty" class="form-control" value="1"></div>
                                <div><label class="form-label">Amount Incl. GST</label><input type="number" step="0.01" id="laborPrice" class="form-control" value="0.00"></div>
                            </div>
                            <div class="grid3 mt-2">
                                <div><label class="form-label">Discount</label><input type="number" step="0.01" id="laborDisc" class="form-control" value="0.00"></div>
                                <div><label class="form-label">GST Type</label><input type="text" class="form-control" value="Inclusive" readonly></div>
                                <div class="d-flex align-items-end"><button type="button" class="btn btn-success w-100" onclick="addLabour()"><i class="ri-add-line"></i> Add Labour</button></div>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <span>🧾 Invoice Items</span>
                            <button type="button" class="btn btn-outline-danger btn-sm" onclick="clearItems()">Clear</button>
                        </div>
                        <div class="cart-wrap">
                            <table class="table table-bordered align-middle">
                                <thead><tr><th>Type</th><th>Description</th><th>Qty</th><th>Rate Incl. GST</th><th>GST</th><th>Total</th><th></th></tr></thead>
                                <tbody id="itemBody"><tr><td colspan="7" class="empty-cart">No products or labour added</td></tr></tbody>
                            </table>
                        </div>
                        <div class="card-body border-top">
                            <div class="grid3 mb-2">
                                <div><label class="form-label">Invoice Discount ₹</label><input type="number" step="0.01" name="discount_amount" id="invoiceDiscount" class="form-control" value="<?= h($invoice['discount_amount']) ?>" oninput="renderItems()"></div>
                                <div><label class="form-label">Paid Amount ₹</label><input type="number" step="0.01" name="paid_amount" id="paidAmount" class="form-control" value="<?= h($invoice['paid_amount']) ?>" oninput="renderItems()"></div>
                                <div><label class="form-label">Status</label><select name="invoice_status" class="form-select"><option value="confirmed" <?= ($invoice['invoice_status'] === 'confirmed') ? 'selected' : '' ?>>Confirmed</option><option value="draft" <?= ($invoice['invoice_status'] === 'draft') ? 'selected' : '' ?>>Draft</option><option value="cancelled" <?= ($invoice['invoice_status'] === 'cancelled') ? 'selected' : '' ?>>Cancelled</option></select></div>
                            </div>
                            <div class="grid2 mb-2">
                                <div>
                                    <label class="form-label">Payment Method</label>
                                    <select name="payment_method_id" class="form-select">
                                        <option value="0">-- Select if paid --</option>
                                        <?php foreach ($paymentMethods as $pm): ?>
                                            <option value="<?= (int)$pm['id'] ?>" <?= ($selectedPaymentMethodId === (int)$pm['id']) ? 'selected' : '' ?>><?= h($pm['method_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div><label class="form-label">Reference No</label><input type="text" name="reference_no" class="form-control" value="<?= h($selectedReferenceNo) ?>"></div>
                            </div>
                            <div class="total-box mb-3">
                                <div class="total-row"><span>Taxable Value</span><span id="subtotalText">₹0.00</span></div>
                                <div class="total-row"><span>CGST</span><span id="cgstText">₹0.00</span></div>
                                <div class="total-row"><span>SGST</span><span id="sgstText">₹0.00</span></div>
                                <div class="total-row"><span>Balance</span><span id="balanceText">₹0.00</span></div>
                                <div class="total-row mb-0"><strong>Grand Total</strong><strong id="grandText">₹0.00</strong></div>
                            </div>
                            <button type="submit" class="btn btn-warning btn-lg w-100"><i class="ri-save-3-line me-1"></i>Update Service Invoice</button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<datalist id="vehicleBrandList">
<?php foreach ($vehicleBrands as $vb): ?><option value="<?= h($vb['brand_name']) ?>"></option><?php endforeach; ?>
</datalist>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const products = <?= json_encode($stockProducts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const customerVehicles = <?= json_encode($customerVehicles, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const existingComplaints = <?= json_encode($complaintsExisting, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const initialItems = <?= json_encode($initialItems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const selectedCustomerId = <?= (int)$invoice['customer_id'] ?>;
const selectedVehicleId = <?= (int)$invoice['customer_vehicle_id'] ?>;

let selectedProductIds = new Set();
let items = [];
let complaintIndex = 0;

document.addEventListener('DOMContentLoaded', function(){
    items = normalizeItems(initialItems || []);
    if (existingComplaints.length) {
        existingComplaints.forEach(c => addComplaint(c.complaint_text || '', c.priority || 'medium', c.status || 'pending'));
    } else {
        addComplaint();
    }
    renderProductList();
    renderItems();
    toggleNewCustomer();
    refreshVehicleList();
});

function money(n){ return '₹' + (Number(n || 0)).toFixed(2); }
function num(id){ const e=document.getElementById(id); return e ? (parseFloat(e.value) || 0) : 0; }
function val(id){ const e=document.getElementById(id); return e ? e.value : ''; }
function escapeHtml(s){ return String(s ?? '').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c])); }

function showToast(message, type='success'){
    const container = document.getElementById('toastContainer');
    const bg = type === 'warning' ? 'text-bg-warning' : (type === 'danger' ? 'text-bg-danger' : 'text-bg-success');
    const toast = document.createElement('div');
    toast.className = `toast custom-toast align-items-center ${bg}`;
    toast.role = 'alert';
    toast.innerHTML = `<div class="d-flex"><div class="toast-body">${escapeHtml(message)}</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>`;
    container.appendChild(toast);
    const bsToast = new bootstrap.Toast(toast, {delay:2200});
    bsToast.show();
    toast.addEventListener('hidden.bs.toast', () => toast.remove());
}

function toggleNewCustomer(){
    const isNew = val('customer_id') === '0';
    document.getElementById('newCustomerBox').style.display = isNew ? 'block' : 'none';
}

function refreshVehicleList(){
    const cid = parseInt(val('customer_id') || '0');
    const sel = document.getElementById('customer_vehicle_id');
    sel.innerHTML = '<option value="0">-- Enter New Vehicle --</option>';
    customerVehicles.filter(v => parseInt(v.customer_id) === cid).forEach(v => {
        const label = [v.brand_name, v.model_name, v.registration_no || v.chassis_no || 'No Reg'].filter(Boolean).join(' / ');
        const opt = document.createElement('option');
        opt.value = v.id;
        opt.textContent = label;
        if (parseInt(v.id) === selectedVehicleId && cid === selectedCustomerId) opt.selected = true;
        sel.appendChild(opt);
    });
    toggleNewVehicle();
}

function toggleNewVehicle(){
    document.getElementById('newVehicleBox').style.display = val('customer_vehicle_id') === '0' ? 'block' : 'none';
}

function addComplaint(text='', priority='medium', status='pending'){
    const box = document.getElementById('complaintBox');
    const idx = complaintIndex++;
    const div = document.createElement('div');
    div.className = 'complaint-row';
    div.innerHTML = `
        <div class="complaint-inner">
            <textarea name="complaints[${idx}][text]" class="form-control" rows="2" placeholder="Enter complaint / issue" required>${escapeHtml(text)}</textarea>
            <select name="complaints[${idx}][priority]" class="form-select">
                <option value="low" ${priority==='low'?'selected':''}>Low</option>
                <option value="medium" ${priority==='medium'?'selected':''}>Medium</option>
                <option value="high" ${priority==='high'?'selected':''}>High</option>
            </select>
            <select name="complaints[${idx}][status]" class="form-select">
                <option value="pending" ${status==='pending'?'selected':''}>Pending</option>
                <option value="checked" ${status==='checked'?'selected':''}>Checked</option>
                <option value="resolved" ${status==='resolved'?'selected':''}>Resolved</option>
                <option value="not_resolved" ${status==='not_resolved'?'selected':''}>Not Resolved</option>
            </select>
            <button type="button" class="btn btn-outline-danger" onclick="this.closest('.complaint-row').remove();showToast('Complaint removed','warning')"><i class="ri-delete-bin-line"></i></button>
        </div>`;
    box.appendChild(div);
}

function renderProductList(){
    const q = val('productSearch').toLowerCase();
    const box = document.getElementById('productList');
    const filtered = products.filter(p => [p.product_name,p.product_code,p.item_code,p.brand_name,p.hsn_code].join(' ').toLowerCase().includes(q)).slice(0, 80);
    if (!filtered.length) { box.innerHTML = '<div class="empty-cart">No products found</div>'; updateSelectedCount(); return; }
    box.innerHTML = filtered.map(p => {
        const id = String(p.id);
        const checked = selectedProductIds.has(id) ? 'checked' : '';
        return `<label class="product-option">
            <input type="checkbox" value="${id}" ${checked} onchange="toggleProductSelect(this)">
            <div>
                <div class="product-title">${escapeHtml(p.product_name)} <span class="text-muted">${escapeHtml(p.product_code || p.item_code || '')}</span></div>
                <div class="product-meta">Brand: ${escapeHtml(p.brand_name || '-')} | Stock: ${Number(p.stock_qty || 0).toFixed(2)} | Rate Incl. GST: ${money(p.selling_price)} | GST: ${Number(p.gst_percent || 0).toFixed(2)}%</div>
            </div>
        </label>`;
    }).join('');
    updateSelectedCount();
}

function toggleProductSelect(cb){
    if (cb.checked) selectedProductIds.add(String(cb.value)); else selectedProductIds.delete(String(cb.value));
    updateSelectedCount();
}
function updateSelectedCount(){ document.getElementById('selectedCount').textContent = selectedProductIds.size + ' selected'; }
function clearProductSelection(){ selectedProductIds.clear(); renderProductList(); updateSelectedCount(); }

function normalizeItems(list){
    const output = [];
    list.forEach(it => mergeItem(output, it, false));
    return output;
}

function mergeItem(target, newItem, notify=true){
    newItem.gst_type = 'inclusive';
    if (newItem.type === 'part') {
        const existing = target.find(x => x.type === 'part' && String(x.product_id) === String(newItem.product_id));
        if (existing) {
            existing.qty = (Number(existing.qty)||0) + (Number(newItem.qty)||0);
            existing.unit_price = Number(newItem.unit_price)||0;
            existing.gst_percent = Number(newItem.gst_percent)||0;
            if (notify) showToast('Quantity Updated');
            return;
        }
    }
    if (newItem.type === 'labour') {
        const existing = target.find(x => x.type === 'labour' && String(x.description).toLowerCase() === String(newItem.description).toLowerCase() && Number(x.unit_price) === Number(newItem.unit_price) && Number(x.gst_percent) === Number(newItem.gst_percent));
        if (existing) {
            existing.qty = (Number(existing.qty)||0) + (Number(newItem.qty)||0);
            if (notify) showToast('Labour Quantity Updated');
            return;
        }
    }
    target.push(newItem);
    if (notify) showToast(newItem.type === 'part' ? 'Item Added' : 'Labour Added');
}

function addSelectedProducts(){
    if (selectedProductIds.size === 0) { showToast('Please select product first', 'warning'); return; }
    const qty = num('defaultPartQty') || 1;
    let added = 0;
    selectedProductIds.forEach(id => {
        const p = products.find(x => String(x.id) === String(id));
        if (!p) return;
        mergeItem(items, {type:'part', product_id:p.id, description:p.product_name, qty:qty, unit_price:Number(p.selling_price || 0), discount_amount:0, gst_percent:Number(p.gst_percent || 0), gst_type:'inclusive'}, false);
        added++;
    });
    clearProductSelection();
    renderItems();
    showToast(added + ' product(s) added / quantity updated');
}

function fillLabourMaster(){
    const opt = document.getElementById('laborMaster').selectedOptions[0];
    if (!opt || !opt.value) return;
    document.getElementById('laborDesc').value = opt.dataset.name || '';
    document.getElementById('laborPrice').value = opt.dataset.price || '0.00';
    document.getElementById('laborGst').value = opt.dataset.gst || '0';
}

function addLabour(){
    const desc = val('laborDesc').trim();
    if (!desc) { showToast('Enter labour description', 'warning'); return; }
    const qty = num('laborQty') || 1;
    const price = num('laborPrice');
    if (price <= 0) { showToast('Enter labour amount', 'warning'); return; }
    mergeItem(items, {type:'labour', labor_id:parseInt(val('laborMaster') || '0'), description:desc, qty:qty, unit_price:price, discount_amount:num('laborDisc'), gst_percent:num('laborGst'), gst_type:'inclusive'}, true);
    document.getElementById('laborMaster').value = '';
    document.getElementById('laborDesc').value = '';
    document.getElementById('laborQty').value = '1';
    document.getElementById('laborPrice').value = '0.00';
    document.getElementById('laborDisc').value = '0.00';
    renderItems();
}

function calcLine(it){
    const gross = Number(it.qty) * Number(it.unit_price);
    const total = Math.max(0, gross - Number(it.discount_amount || 0));
    const taxable = Number(it.gst_percent) > 0 ? total / (1 + (Number(it.gst_percent)/100)) : total;
    const tax = total - taxable;
    return { taxable, tax, total };
}

function renderItems(){
    items = normalizeItems(items);
    const body = document.getElementById('itemBody');
    document.querySelectorAll('.dyn-item-input').forEach(e => e.remove());
    if (!items.length) {
        body.innerHTML = '<tr><td colspan="7" class="empty-cart">No products or labour added</td></tr>';
    } else {
        body.innerHTML = items.map((it, i) => {
            const c = calcLine(it);
            return `<tr>
                <td><span class="badge ${it.type==='part'?'text-bg-primary':'text-bg-success'}">${it.type==='part'?'Product':'Labour'}</span></td>
                <td>${escapeHtml(it.description)}<div class="small text-muted">Inclusive</div></td>
                <td style="width:90px"><input type="number" step="0.01" class="form-control form-control-sm" value="${it.qty}" onchange="items[${i}].qty=parseFloat(this.value)||1;renderItems();"></td>
                <td style="width:130px"><input type="number" step="0.01" class="form-control form-control-sm" value="${it.unit_price}" onchange="items[${i}].unit_price=parseFloat(this.value)||0;renderItems();"></td>
                <td style="width:90px"><input type="number" step="0.01" class="form-control form-control-sm" value="${it.gst_percent}" onchange="items[${i}].gst_percent=parseFloat(this.value)||0;renderItems();"></td>
                <td>${money(c.total)}</td>
                <td><button type="button" class="btn btn-sm btn-outline-danger" onclick="removeItem(${i})"><i class="ri-delete-bin-line"></i></button></td>
            </tr>`;
        }).join('');
    }

    let subtotal = 0, cgst = 0, sgst = 0;
    items.forEach((it, i) => {
        const c = calcLine(it);
        subtotal += c.taxable;
        cgst += c.tax / 2;
        sgst += c.tax / 2;
        addHiddenFields(it, i);
    });
    const disc = num('invoiceDiscount');
    let grand = subtotal + cgst + sgst - disc;
    if (grand < 0) grand = 0;
    const paid = Math.min(num('paidAmount'), grand);
    const balance = grand - paid;
    document.getElementById('subtotalText').textContent = money(subtotal);
    document.getElementById('cgstText').textContent = money(cgst);
    document.getElementById('sgstText').textContent = money(sgst);
    document.getElementById('grandText').textContent = money(grand);
    document.getElementById('balanceText').textContent = money(balance);
}

function addHidden(name, value){
    const input = document.createElement('input');
    input.type = 'hidden'; input.name = name; input.value = value; input.className = 'dyn-item-input';
    document.getElementById('serviceInvoiceForm').appendChild(input);
}

function addHiddenFields(it, i){
    if (it.type === 'part') {
        addHidden(`parts[${i}][product_id]`, it.product_id);
        addHidden(`parts[${i}][qty]`, it.qty);
        addHidden(`parts[${i}][unit_price]`, it.unit_price);
        addHidden(`parts[${i}][discount_amount]`, it.discount_amount || 0);
        addHidden(`parts[${i}][gst_percent]`, it.gst_percent || 0);
        addHidden(`parts[${i}][gst_type]`, 'inclusive');
    } else {
        addHidden(`labours[${i}][labor_id]`, it.labor_id || 0);
        addHidden(`labours[${i}][description]`, it.description);
        addHidden(`labours[${i}][qty]`, it.qty);
        addHidden(`labours[${i}][unit_price]`, it.unit_price);
        addHidden(`labours[${i}][discount_amount]`, it.discount_amount || 0);
        addHidden(`labours[${i}][gst_percent]`, it.gst_percent || 0);
        addHidden(`labours[${i}][gst_type]`, 'inclusive');
    }
}

function removeItem(i){ items.splice(i,1); renderItems(); showToast('Item removed','warning'); }
function clearItems(){ if(confirm('Clear all invoice items?')){ items=[]; renderItems(); showToast('Cart Cleared','warning'); } }
</script>
</body>
</html>
