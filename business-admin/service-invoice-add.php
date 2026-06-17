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

function moneyText($amount): string
{
    return '₹' . number_format((float)$amount, 2);
}

function getSettingPrefix(mysqli $conn, int $businessId, string $column, string $fallback): string
{
    if (!tableExists($conn, 'business_settings') || !columnExists($conn, 'business_settings', $column)) {
        return $fallback;
    }
    $stmt = $conn->prepare("SELECT {$column} FROM business_settings WHERE business_id = ? LIMIT 1");
    if (!$stmt) return $fallback;
    $stmt->bind_param('i', $businessId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $prefix = trim((string)($row[$column] ?? ''));
    return $prefix !== '' ? $prefix : $fallback;
}

function generateServiceInvoiceNo(mysqli $conn, int $businessId): string
{
    $prefix = getSettingPrefix($conn, $businessId, 'service_invoice_prefix', 'SRV');
    if (!tableExists($conn, 'service_invoices')) {
        return $prefix . date('Ymd') . '-0001';
    }
    $stmt = $conn->prepare("SELECT invoice_no FROM service_invoices WHERE business_id = ? ORDER BY id DESC LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $businessId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row && preg_match('/(\d+)$/', (string)$row['invoice_no'], $m)) {
            return $prefix . str_pad((string)(((int)$m[1]) + 1), max(4, strlen($m[1])), '0', STR_PAD_LEFT);
        }
    }
    return $prefix . '0001';
}

function generateJobcardNo(mysqli $conn, int $businessId): string
{
    $prefix = 'JOB-' . date('Ymd') . '-';
    if (!tableExists($conn, 'service_job_cards')) {
        return $prefix . '0001';
    }
    $like = $prefix . '%';
    $stmt = $conn->prepare("SELECT jobcard_no FROM service_job_cards WHERE business_id = ? AND jobcard_no LIKE ? ORDER BY id DESC LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('is', $businessId, $like);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row && preg_match('/(\d+)$/', (string)$row['jobcard_no'], $m)) {
            return $prefix . str_pad((string)(((int)$m[1]) + 1), 4, '0', STR_PAD_LEFT);
        }
    }
    return $prefix . '0001';
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

function calculateLine(float $qty, float $price, float $discount, float $gstPercent, string $gstType): array
{
    // Selling price is stored as the final customer rate.
    $grossTotal = round($qty * $price, 2);
    $lineTotal = round(max(0, $grossTotal - $discount), 2);

    if ($gstPercent > 0) {
        $taxable = round($lineTotal / (1 + ($gstPercent / 100)), 2);
    } else {
        $taxable = $lineTotal;
    }

    $tax = round(max(0, $lineTotal - $taxable), 2);
    return [$taxable, $tax, $lineTotal];
}

function deductProductStock(mysqli $conn, int $businessId, int $branchId, int $productId, float $qty, int $userId, string $refTable, int $refId): void
{
    if ($productId <= 0 || $qty <= 0) return;

    if (tableExists($conn, 'products')) {
        $qtySql = (float)$qty;
        $conn->query("UPDATE products SET stock_qty = GREATEST(COALESCE(stock_qty,0) - {$qtySql}, 0) WHERE id = {$productId} AND business_id = {$businessId}");
    }

    if (tableExists($conn, 'product_stock')) {
        $qtySql = (float)$qty;
        $conn->query("UPDATE product_stock SET qty_available = GREATEST(COALESCE(qty_available,0) - {$qtySql}, 0) WHERE product_id = {$productId} AND business_id = {$businessId} AND branch_id = {$branchId}");
    }

    if (tableExists($conn, 'stock_movements')) {
        $type = 'product';
        $movement = 'service_use';
        $price = 0.0;
        $date = date('Y-m-d H:i:s');
        $notes = 'Used in service invoice';
        $stmt = $conn->prepare("INSERT INTO stock_movements (business_id, branch_id, item_type, item_id, movement_type, ref_table, ref_id, qty, unit_price, movement_date, notes, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        if ($stmt) {
            $stmt->bind_param('iisissiddssi', $businessId, $branchId, $type, $productId, $movement, $refTable, $refId, $qty, $price, $date, $notes, $userId);
            $stmt->execute();
            $stmt->close();
        }
    }
}

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

$requiredTables = ['service_job_cards', 'service_invoices', 'service_complaints', 'service_job_part_items', 'service_job_labor_items', 'customers', 'customer_vehicles'];
$missingTables = [];
foreach ($requiredTables as $t) {
    if (!tableExists($conn, $t)) $missingTables[] = $t;
}

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
            COALESCE(
                NULLIF(p.selling_price, 0),
                NULLIF(p.mrp, 0),
                NULLIF(p.purchase_price, 0),
                0
            ) AS selling_price,
            COALESCE(p.gst_percent, 0) AS gst_percent,
            COALESCE(NULLIF(p.gst_type, ''), 'inclusive') AS gst_type,
            {$stockExpr} AS stock_qty
        FROM products p
        " . ($hasProductStock ? "LEFT JOIN product_stock ps ON ps.product_id = p.id AND ps.business_id = p.business_id AND ps.branch_id = " . (int)$currentBranchId : "") . "
        WHERE p.business_id = {$businessId} AND p.status = 1
        ORDER BY p.product_name ASC
    ");
}

$form = [
    'branch_id' => $currentBranchId > 0 ? $currentBranchId : (int)($branches[0]['id'] ?? 0),
    'invoice_no' => generateServiceInvoiceNo($conn, $businessId),
    'jobcard_no' => generateJobcardNo($conn, $businessId),
    'invoice_date' => date('Y-m-d\TH:i'),
    'customer_id' => 0,
    'customer_vehicle_id' => 0,
    'opening_km' => '',
    'fuel_level' => '',
    'battery_percentage' => '',
    'customer_voice' => '',
    'technician_observation' => '',
    'recommendation' => '',
    'discount_amount' => '0.00',
    'paid_amount' => '0.00',
    'payment_method_id' => 0,
    'reference_no' => '',
    'invoice_status' => 'confirmed'
];

$success = '';
$error = '';
$createdInvoiceId = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($form as $k => $v) {
        if (isset($_POST[$k])) $form[$k] = is_string($_POST[$k]) ? trim($_POST[$k]) : $_POST[$k];
    }

    $form['branch_id'] = (int)($form['branch_id'] ?? 0);
    $form['customer_id'] = (int)($form['customer_id'] ?? 0);
    $form['customer_vehicle_id'] = (int)($form['customer_vehicle_id'] ?? 0);
    $form['payment_method_id'] = (int)($form['payment_method_id'] ?? 0);

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
        'registration_no' => trim($_POST['registration_no'] ?? '')
    ];

    $complaints = [];
    foreach (($_POST['complaints'] ?? []) as $row) {
        $text = trim($row['text'] ?? '');
        if ($text !== '') {
            $priority = trim($row['priority'] ?? 'medium');
            if (!in_array($priority, ['low', 'medium', 'high'], true)) $priority = 'medium';
            $complaints[] = ['text' => $text, 'priority' => $priority];
        }
    }

    $parts = [];
    $partsByProduct = [];
    foreach (($_POST['parts'] ?? []) as $row) {
        $pid = (int)($row['product_id'] ?? 0);
        $qty = (float)($row['qty'] ?? 0);
        $price = (float)($row['unit_price'] ?? 0);
        $discount = (float)($row['discount_amount'] ?? 0);
        $gst = (float)($row['gst_percent'] ?? 0);
        $gstType = 'inclusive';
        if ($pid > 0 && $qty > 0 && $price >= 0) {
            if (!isset($partsByProduct[$pid])) {
                $partsByProduct[$pid] = [
                    'pid' => $pid,
                    'qty' => 0.0,
                    'price' => $price,
                    'discount' => 0.0,
                    'gst' => $gst,
                    'tax' => 0.0,
                    'lineTotal' => 0.0
                ];
            }

            // Same product selected again: increase quantity instead of creating duplicate row.
            $partsByProduct[$pid]['qty'] += $qty;
            $partsByProduct[$pid]['discount'] += $discount;
            $partsByProduct[$pid]['price'] = $price;
            $partsByProduct[$pid]['gst'] = $gst;

            [$taxable, $tax, $lineTotal] = calculateLine(
                (float)$partsByProduct[$pid]['qty'],
                (float)$partsByProduct[$pid]['price'],
                (float)$partsByProduct[$pid]['discount'],
                (float)$partsByProduct[$pid]['gst'],
                $gstType
            );
            $partsByProduct[$pid]['tax'] = $tax;
            $partsByProduct[$pid]['lineTotal'] = $lineTotal;
        }
    }
    $parts = array_values($partsByProduct);

    $labours = [];
    foreach (($_POST['labours'] ?? []) as $row) {
        $laborId = (int)($row['labor_id'] ?? 0);
        $desc = trim($row['description'] ?? '');
        $qty = (float)($row['qty'] ?? 0);
        $price = (float)($row['unit_price'] ?? 0);
        $discount = (float)($row['discount_amount'] ?? 0);
        $gst = (float)($row['gst_percent'] ?? 0);
        $gstType = 'inclusive';
        if ($desc !== '' && $qty > 0 && $price >= 0) {
            [$taxable, $tax, $lineTotal] = calculateLine($qty, $price, $discount, $gst, $gstType);
            $labours[] = compact('laborId', 'desc', 'qty', 'price', 'discount', 'gst', 'tax', 'lineTotal');
        }
    }

    if (!empty($missingTables)) {
        $error = 'Missing required table(s): ' . implode(', ', $missingTables);
    } elseif ($form['branch_id'] <= 0) {
        $error = 'Please select active branch.';
    } elseif ($form['customer_id'] <= 0 && ($newCustomer['full_name'] === '' || $newCustomer['mobile'] === '')) {
        $error = 'Please select existing customer OR enter new customer name and mobile.';
    } elseif (empty($parts) && empty($labours)) {
        $error = 'Please add at least one used product or labour charge.';
    }

    if ($error === '') {
        $subtotal = 0.0;
        $cgst = 0.0;
        $sgst = 0.0;
        foreach ($parts as $p) {
            $subtotal += max(0, $p['lineTotal'] - $p['tax']);
            $cgst += $p['tax'] / 2;
            $sgst += $p['tax'] / 2;
        }
        foreach ($labours as $l) {
            $subtotal += max(0, $l['lineTotal'] - $l['tax']);
            $cgst += $l['tax'] / 2;
            $sgst += $l['tax'] / 2;
        }

        $discountAmount = (float)$form['discount_amount'];
        $grandTotal = $subtotal + $cgst + $sgst - $discountAmount;
        if ($grandTotal < 0) $grandTotal = 0;
        $paidAmount = min((float)$form['paid_amount'], $grandTotal);
        $balance = $grandTotal - $paidAmount;
        $paymentStatus = ($grandTotal > 0 && $paidAmount >= $grandTotal) ? 'paid' : ($paidAmount > 0 ? 'partial' : 'unpaid');

        $conn->begin_transaction();
        try {
            $customerId = $form['customer_id'];

            if ($customerId <= 0) {
                $customerCode = 'CUST' . str_pad((string)mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);
                $stmt = $conn->prepare("INSERT INTO customers (business_id, branch_id, customer_code, full_name, mobile, alternate_mobile, email, address_line1, city, state, pincode, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
                if (!$stmt) throw new Exception('Customer insert prepare failed: ' . $conn->error);
                $stmt->bind_param('iisssssssss', $businessId, $form['branch_id'], $customerCode, $newCustomer['full_name'], $newCustomer['mobile'], $newCustomer['alternate_mobile'], $newCustomer['email'], $newCustomer['address_line1'], $newCustomer['city'], $newCustomer['state'], $newCustomer['pincode']);
                if (!$stmt->execute()) throw new Exception('Customer save failed: ' . $stmt->error);
                $customerId = (int)$conn->insert_id;
                $stmt->close();
            }

            $customerVehicleId = $form['customer_vehicle_id'];
            if ($customerVehicleId <= 0) {
                $brandId = getOrCreateVehicleBrand($conn, $newVehicle['brand_name']);
                $modelId = getOrCreateVehicleModel($conn, $businessId, $brandId, $newVehicle['model_name'], $newVehicle['vehicle_type']);
                $vehicleType = in_array($newVehicle['vehicle_type'], ['fuel', 'electric'], true) ? $newVehicle['vehicle_type'] : 'electric';
                $registrationNo = strtoupper(trim((string)$newVehicle['registration_no']));
                $registrationNo = preg_replace('/\s+/', '', $registrationNo);
                if ($registrationNo === '') {
                    $registrationNo = null;
                }

                if ($registrationNo !== null) {
                    $stmt = $conn->prepare("SELECT id FROM customer_vehicles WHERE business_id = ? AND customer_id = ? AND registration_no = ? AND active_status = 1 LIMIT 1");
                    if ($stmt) {
                        $stmt->bind_param('iis', $businessId, $customerId, $registrationNo);
                        $stmt->execute();
                        $row = $stmt->get_result()->fetch_assoc();
                        $stmt->close();
                        if ($row) {
                            $customerVehicleId = (int)$row['id'];
                        }
                    }
                }

                if ($customerVehicleId <= 0) {
                    $stmt = $conn->prepare("INSERT INTO customer_vehicles (business_id, customer_id, vehicle_stock_id, brand_id, model_id, vehicle_type, registration_no, current_km, active_status, created_at) VALUES (?, ?, NULL, ?, ?, ?, ?, ?, 1, NOW())");
                    if (!$stmt) throw new Exception('Vehicle insert prepare failed: ' . $conn->error);
                    $openingKm = (int)($form['opening_km'] ?: 0);
                    $stmt->bind_param('iiiissi', $businessId, $customerId, $brandId, $modelId, $vehicleType, $registrationNo, $openingKm);
                    if (!$stmt->execute()) {
                        if ($conn->errno === 1062 && $registrationNo !== null) {
                            $dupStmt = $conn->prepare("SELECT id FROM customer_vehicles WHERE business_id = ? AND registration_no = ? AND active_status = 1 LIMIT 1");
                            if ($dupStmt) {
                                $dupStmt->bind_param('is', $businessId, $registrationNo);
                                $dupStmt->execute();
                                $dupRow = $dupStmt->get_result()->fetch_assoc();
                                $dupStmt->close();
                                if ($dupRow) {
                                    $customerVehicleId = (int)$dupRow['id'];
                                }
                            }
                            if ($customerVehicleId <= 0) {
                                throw new Exception('Vehicle save failed: Registration number already exists.');
                            }
                        } else {
                            throw new Exception('Vehicle save failed: ' . $stmt->error);
                        }
                    } else {
                        $customerVehicleId = (int)$conn->insert_id;
                    }
                    $stmt->close();
                }
            }

            $invoiceDateDb = date('Y-m-d H:i:s', strtotime((string)$form['invoice_date']));
            $jobcardNo = trim((string)$form['jobcard_no']) ?: generateJobcardNo($conn, $businessId);
            $invoiceNo = trim((string)$form['invoice_no']) ?: generateServiceInvoiceNo($conn, $businessId);
            $openingKm = (int)($form['opening_km'] ?: 0);
            $washingRequired = isset($_POST['washing_required']) ? 1 : 0;
            $roadTestRequired = isset($_POST['road_test_required']) ? 1 : 0;
            $jobStatus = 'delivered';

            $stmt = $conn->prepare("INSERT INTO service_job_cards (business_id, branch_id, jobcard_no, customer_id, customer_vehicle_id, service_date, opening_km, fuel_level, battery_percentage, promised_delivery, job_status, washing_required, road_test_required, estimated_amount, final_amount, customer_voice, technician_observation, recommendation, created_by, created_at, closed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
            if (!$stmt) throw new Exception('Job card insert prepare failed: ' . $conn->error);
            $stmt->bind_param('iisiisisssiiddsssi', $businessId, $form['branch_id'], $jobcardNo, $customerId, $customerVehicleId, $invoiceDateDb, $openingKm, $form['fuel_level'], $form['battery_percentage'], $jobStatus, $washingRequired, $roadTestRequired, $grandTotal, $grandTotal, $form['customer_voice'], $form['technician_observation'], $form['recommendation'], $businessUserId);
            if (!$stmt->execute()) throw new Exception('Job card save failed: ' . $stmt->error);
            $jobcardId = (int)$conn->insert_id;
            $stmt->close();

            if (!empty($complaints)) {
                $stmt = $conn->prepare("INSERT INTO service_complaints (jobcard_id, complaint_text, priority, status) VALUES (?, ?, ?, 'pending')");
                if (!$stmt) throw new Exception('Complaint insert prepare failed: ' . $conn->error);
                foreach ($complaints as $c) {
                    $stmt->bind_param('iss', $jobcardId, $c['text'], $c['priority']);
                    if (!$stmt->execute()) throw new Exception('Complaint save failed: ' . $stmt->error);
                }
                $stmt->close();
            }

            $stmt = $conn->prepare("INSERT INTO service_job_part_items (jobcard_id, product_id, qty, unit_price, discount_amount, gst_percent, tax_amount, line_total, stock_deducted) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)");
            if (!$stmt) throw new Exception('Part item insert prepare failed: ' . $conn->error);
            foreach ($parts as $p) {
                $stmt->bind_param('iidddddd', $jobcardId, $p['pid'], $p['qty'], $p['price'], $p['discount'], $p['gst'], $p['tax'], $p['lineTotal']);
                if (!$stmt->execute()) throw new Exception('Part item save failed: ' . $stmt->error);
                deductProductStock($conn, $businessId, $form['branch_id'], $p['pid'], $p['qty'], $businessUserId, 'service_job_cards', $jobcardId);
            }
            $stmt->close();

            $stmt = $conn->prepare("INSERT INTO service_job_labor_items (jobcard_id, labor_id, description, qty, unit_price, discount_amount, gst_percent, tax_amount, line_total) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            if (!$stmt) throw new Exception('Labour item insert prepare failed: ' . $conn->error);
            foreach ($labours as $l) {
                $laborId = $l['laborId'] > 0 ? $l['laborId'] : null;
                $stmt->bind_param('iisdddddd', $jobcardId, $laborId, $l['desc'], $l['qty'], $l['price'], $l['discount'], $l['gst'], $l['tax'], $l['lineTotal']);
                if (!$stmt->execute()) throw new Exception('Labour item save failed: ' . $stmt->error);
            }
            $stmt->close();

            $invoiceStatus = in_array($form['invoice_status'], ['draft', 'confirmed', 'cancelled'], true) ? $form['invoice_status'] : 'confirmed';
            $igst = 0.0;
            $stmt = $conn->prepare("INSERT INTO service_invoices (business_id, branch_id, invoice_no, jobcard_id, customer_id, invoice_date, subtotal, discount_amount, cgst_amount, sgst_amount, igst_amount, grand_total, paid_amount, balance_amount, payment_status, invoice_status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            if (!$stmt) throw new Exception('Service invoice insert prepare failed: ' . $conn->error);
            $stmt->bind_param('iisiisddddddddss', $businessId, $form['branch_id'], $invoiceNo, $jobcardId, $customerId, $invoiceDateDb, $subtotal, $discountAmount, $cgst, $sgst, $igst, $grandTotal, $paidAmount, $balance, $paymentStatus, $invoiceStatus);
            if (!$stmt->execute()) throw new Exception('Service invoice save failed: ' . $stmt->error);
            $createdInvoiceId = (int)$conn->insert_id;
            $stmt->close();

            if ($paidAmount > 0 && tableExists($conn, 'payments') && $form['payment_method_id'] > 0) {
                $paymentFor = 'service';
                $referenceNo = trim((string)$form['reference_no']);
                $notes = 'Service invoice payment: ' . $invoiceNo;
                $stmt = $conn->prepare("INSERT INTO payments (business_id, branch_id, payment_date, customer_id, payment_for, ref_id, payment_method_id, amount, reference_no, transaction_no, notes, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, '', ?, ?, NOW())");
                if ($stmt) {
                    $stmt->bind_param('iisisiidssi', $businessId, $form['branch_id'], $invoiceDateDb, $customerId, $paymentFor, $createdInvoiceId, $form['payment_method_id'], $paidAmount, $referenceNo, $notes, $businessUserId);
                    $stmt->execute();
                    $stmt->close();
                }
            }

            if (tableExists($conn, 'audit_logs')) {
                $action = 'Create';
                $module = 'Service Invoice';
                $table = 'service_invoices';
                $desc = 'Created service invoice: ' . $invoiceNo . ' | Total: ₹' . number_format($grandTotal, 2);
                $ip = $_SERVER['REMOTE_ADDR'] ?? null;
                $stmt = $conn->prepare("INSERT INTO audit_logs (business_id, branch_id, user_id, action, module_name, ref_table, ref_id, description, ip_address, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                if ($stmt) {
                    $stmt->bind_param('iiisssiss', $businessId, $form['branch_id'], $businessUserId, $action, $module, $table, $createdInvoiceId, $desc, $ip);
                    $stmt->execute();
                    $stmt->close();
                }
            }

            $conn->commit();
            $success = 'Service invoice ' . h($invoiceNo) . ' created successfully. Total: ' . moneyText($grandTotal);
            $form['invoice_no'] = generateServiceInvoiceNo($conn, $businessId);
            $form['jobcard_no'] = generateJobcardNo($conn, $businessId);
        } catch (Throwable $e) {
            $conn->rollback();
            $error = $e->getMessage();
        }
    }
}
?>
<!doctype html>
<html lang="en">
    
<?php include('includes/head.php'); ?>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Add Service Invoice</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
<style>


:root{
    --primary:#f6ad22;
    --primary2:#ffc247;
    --dark:#0f172a;
    --muted:#64748b;
    --line:#dbe3ef;
    --bg:#f4f7fb;
    --card:#ffffff;
    --danger:#dc3545;
    --success:#198754;
}
*{box-sizing:border-box}
html,body{height:100%;width:100%;margin:0;overflow:hidden}
body{
    background:var(--bg);
    font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
    color:#0f172a;
    font-size:14px;
    line-height:1.35;
}
.app-container{
    height:100vh;
    width:100%;
    display:flex;
    flex-direction:column;
    overflow:hidden;
    background:var(--bg);
}
.app-header{
    flex:0 0 auto;
    min-height:74px;
    background:linear-gradient(135deg,#111827,#1f2937);
    color:#fff;
    padding:14px 22px;
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:14px;
    box-shadow:0 8px 24px rgba(15,23,42,.14);
    z-index:10;
}
.app-header h2{margin:0;font-size:23px;font-weight:850;line-height:1.15;letter-spacing:-.02em}
.app-header small{display:block;margin-top:3px;font-size:13px;color:rgba(255,255,255,.78);line-height:1.25}
.app-body{
    flex:1 1 auto;
    min-height:0;
    padding:14px;
    overflow:hidden;
}
#serviceInvoiceForm{height:100%;min-height:0}
.invoice-layout{
    height:100%;
    min-height:0;
    display:grid;
    grid-template-columns:minmax(360px, 430px) minmax(0, 1fr);
    gap:14px;
    align-items:stretch;
}
.left-panel,.right-panel{
    min-height:0;
    height:100%;
    overflow-y:auto;
    overflow-x:hidden;
    padding:0 4px 14px 0;
    scrollbar-width:thin;
}
.left-panel{display:flex;flex-direction:column;gap:12px}
.right-panel{display:flex;flex-direction:column;gap:12px;padding-right:2px}
.left-panel::-webkit-scrollbar,.right-panel::-webkit-scrollbar{width:7px}
.left-panel::-webkit-scrollbar-thumb,.right-panel::-webkit-scrollbar-thumb{background:#cbd5e1;border-radius:999px}
.card{
    width:100%;
    flex:0 0 auto;
    background:var(--card);
    border:1px solid var(--line)!important;
    border-radius:14px!important;
    box-shadow:0 6px 18px rgba(15,23,42,.055)!important;
    overflow:visible!important;
    margin:0!important;
}
.card-header{
    background:#fff!important;
    font-weight:850;
    border-bottom:1px solid var(--line)!important;
    padding:11px 14px!important;
    border-radius:14px 14px 0 0!important;
    min-height:46px;
}
.card-body{padding:14px!important;overflow:visible!important}
.section-label{display:block;font-weight:850;font-size:16px;margin:0 0 12px;color:#111827;line-height:1.25}
.form-label{display:block;font-size:13px;font-weight:750;margin:0 0 5px;color:#334155;line-height:1.25}
.form-control,.form-select{
    width:100%;
    min-height:39px;
    font-size:14px!important;
    line-height:1.25!important;
    padding:8px 11px!important;
    border-radius:9px!important;
    border:1px solid #cbd5e1!important;
    background-color:#fff!important;
    box-shadow:none!important;
}
textarea.form-control{min-height:74px;resize:vertical}
.form-control:focus,.form-select:focus{border-color:var(--primary)!important;box-shadow:0 0 0 3px rgba(246,173,34,.18)!important}
.btn{font-size:13px;border-radius:8px;font-weight:750;min-height:36px;display:inline-flex;align-items:center;justify-content:center;gap:4px}
.btn-sm{min-height:31px;font-size:12px;padding:5px 10px}
.btn-lg{min-height:44px;font-size:15px}
.btn-warning{background:var(--primary)!important;border-color:var(--primary)!important;color:#111827!important}
.btn-warning:hover{background:var(--primary2)!important;border-color:var(--primary2)!important;color:#111827!important}
.grid2,.grid3{display:grid;gap:11px;align-items:start}
.grid2{grid-template-columns:minmax(0,1fr) minmax(0,1fr)}
.grid3{grid-template-columns:repeat(3,minmax(0,1fr))}
.field-row{margin-bottom:11px}
.small-help{font-size:12px;color:var(--muted);margin-top:5px;line-height:1.35}
.form-check{font-size:13px;font-weight:600;color:#334155;display:flex;align-items:center;gap:6px;margin:0}
.form-check-input{margin:0!important}
.product-picker{border:1px solid #bfdbfe;background:#eff6ff;border-radius:12px;padding:12px;overflow:visible}
.product-list{height:245px;overflow:auto;border:1px solid #dbeafe;background:#fff;border-radius:10px;margin-top:9px}
.product-option{display:grid;grid-template-columns:22px minmax(0,1fr);gap:8px;padding:9px 10px;border-bottom:1px solid #eff6ff;cursor:pointer;align-items:start}
.product-option:hover{background:#f8fafc}
.product-option:last-child{border-bottom:0}
.product-option input{margin-top:3px;width:16px;height:16px}
.product-title{font-weight:850;font-size:13px;color:#0f172a;line-height:1.25;word-break:break-word}
.product-meta{font-size:12px;color:#64748b;margin-top:2px;line-height:1.35;word-break:break-word}
.complaint-row,.labour-row{border:1px dashed #cbd5e1;border-radius:11px;padding:10px;margin-bottom:9px;background:#fff;overflow:visible}
.complaint-inner{display:grid;grid-template-columns:minmax(0,1fr) 112px 44px;gap:9px;align-items:stretch}
.complaint-inner textarea{min-height:70px}
.complaint-inner .btn{height:70px;min-height:70px}
.cart-wrap{max-height:315px;overflow:auto;border-bottom:1px solid var(--line)}
.table{font-size:12px;margin-bottom:0;background:#fff}
.table th{white-space:nowrap;background:#f8fafc!important;position:sticky;top:0;z-index:2;font-weight:850;color:#334155}
.table td,.table th{vertical-align:middle;padding:8px!important}
.table td:nth-child(2){min-width:190px;word-break:break-word}
.total-box{background:#fff7ed;border:1px solid #fed7aa;border-radius:12px;padding:12px;margin-top:2px}
.total-row{display:flex;justify-content:space-between;gap:12px;margin-bottom:7px;font-size:13px}
.total-row strong{font-size:16px}
.empty-cart{text-align:center;color:#94a3b8;padding:30px 12px!important}
.badge-soft{background:#fef3c7;color:#92400e;border:1px solid #fde68a;border-radius:999px;padding:4px 10px;font-size:12px;font-weight:850;white-space:nowrap}
.alert{border-radius:12px;margin-bottom:12px}

.cart-toast-wrap{
    position:fixed;
    top:92px;
    right:18px;
    z-index:1080;
    width:min(360px, calc(100vw - 28px));
    pointer-events:none;
}
.cart-toast{
    pointer-events:auto;
    border:0!important;
    border-radius:14px!important;
    overflow:hidden;
    box-shadow:0 16px 40px rgba(15,23,42,.20)!important;
    background:#ffffff!important;
}
.cart-toast .toast-header{
    background:linear-gradient(135deg,#16a34a,#22c55e)!important;
    color:#fff!important;
    border-bottom:0!important;
    padding:10px 12px!important;
}
.cart-toast .toast-header .btn-close{filter:invert(1) grayscale(100%) brightness(200%);opacity:.9}
.cart-toast .toast-body{
    padding:12px 14px!important;
    font-size:13px;
    font-weight:650;
    color:#0f172a;
    background:#fff;
}
.cart-toast.warning .toast-header{background:linear-gradient(135deg,#f59e0b,#f6ad22)!important;color:#111827!important}
.cart-toast.warning .toast-header .btn-close{filter:none}
.cart-toast.info .toast-header{background:linear-gradient(135deg,#2563eb,#3b82f6)!important;color:#fff!important}
@media(max-width:768px){.cart-toast-wrap{top:auto;right:12px;left:12px;bottom:16px;width:auto}}

@media(max-width:1250px){
    html,body{overflow:auto;height:auto}
    .app-container{height:auto;min-height:100vh;overflow:visible}
    .app-body{overflow:visible;padding:12px}
    #serviceInvoiceForm{height:auto}
    .invoice-layout{height:auto;display:block}
    .left-panel,.right-panel{height:auto;max-height:none;overflow:visible;padding:0;display:flex;flex-direction:column;gap:12px}
    .right-panel{margin-top:12px}
    .cart-wrap{max-height:none;overflow:auto}
}
@media(max-width:768px){
    .app-header{padding:12px 14px;align-items:flex-start;flex-direction:column}
    .app-header h2{font-size:20px}
    .app-header .d-flex{width:100%}
    .app-header .btn{flex:1}
    .grid2,.grid3{grid-template-columns:1fr!important}
    .product-picker .grid3 > div[style]{grid-column:auto!important}
    .complaint-inner{grid-template-columns:1fr}
    .complaint-inner .btn{height:40px;min-height:40px;width:100%}
    .product-list{height:230px}
    .card-body{padding:12px!important}
}
</style>
</head>
<body>
<div class="app-container">
    <div class="app-header">
        <div>
            <h2>🛠️ Add Service Invoice</h2>
            <small style="opacity:.82">Create job card, complaints, used products, labour and service invoice directly</small>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="index.php" class="btn btn-light btn-sm"><i class="ri-arrow-left-line me-1"></i>Dashboard</a>
            <a href="service-invoices.php" class="btn btn-outline-light btn-sm"><i class="ri-file-list-line me-1"></i>Service Invoices</a>
        </div>
    </div>

    <div class="app-body">
        <?php if (!empty($missingTables)): ?>
            <div class="alert alert-warning"><strong>Missing table:</strong> <?= h(implode(', ', $missingTables)) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="ri-check-double-line me-1"></i><?= $success ?>
                <?php if ($createdInvoiceId > 0): ?><a class="ms-2" href="service-invoice-view.php?id=<?= (int)$createdInvoiceId ?>">View Invoice</a><?php endif; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show"><i class="ri-error-warning-line me-1"></i><?= h($error) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>

        <form method="post" id="serviceInvoiceForm">
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
                                            <option value="<?= (int)$b['id'] ?>" <?= ((int)$form['branch_id'] === (int)$b['id']) ? 'selected' : '' ?>><?= h($b['branch_name'] . ' (' . $b['branch_code'] . ')') ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="form-label">Service Invoice No *</label>
                                    <input type="text" name="invoice_no" class="form-control" value="<?= h($form['invoice_no']) ?>" required>
                                </div>
                            </div>
                            <div class="grid2 mt-2">
                                <div>
                                    <label class="form-label">Job Card No *</label>
                                    <input type="text" name="jobcard_no" class="form-control" value="<?= h($form['jobcard_no']) ?>" required>
                                </div>
                                <div>
                                    <label class="form-label">Date & Time *</label>
                                    <input type="datetime-local" name="invoice_date" class="form-control" value="<?= h($form['invoice_date']) ?>" required>
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
                                        <option value="<?= (int)$c['id'] ?>"><?= h($c['full_name'] . ' - ' . $c['mobile']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="small-help">Select existing customer or keep this blank and enter new customer below.</div>
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
                                <div class="small-help">After selecting customer, their vehicles will show here. Or enter new vehicle details below.</div>
                            </div>
                            <div id="newVehicleBox">
                                <div class="grid2">
                                    <div>
                                        <label class="form-label">Vehicle Type</label>
                                        <select name="vehicle_type" class="form-select">
                                            <option value="electric">Electric</option>
                                            <option value="fuel">Fuel</option>
                                        </select>
                                    </div>
                                    <div><label class="form-label">Registration No</label><input type="text" name="registration_no" class="form-control"></div>
                                </div>
                                <div class="grid2 mt-2">
                                    <div><label class="form-label">Brand</label><input type="text" name="vehicle_brand_name" class="form-control" list="vehicleBrandList"></div>
                                    <div><label class="form-label">Model</label><input type="text" name="vehicle_model_name" class="form-control"></div>
                                </div>
                            </div>
                            <div class="grid3 mt-2">
                                <div><label class="form-label">Opening KM</label><input type="number" name="opening_km" class="form-control" value="<?= h($form['opening_km']) ?>"></div>
                                <div><label class="form-label">Fuel Level</label><input type="text" name="fuel_level" class="form-control" placeholder="Half / Full"></div>
                                <div><label class="form-label">Battery %</label><input type="text" name="battery_percentage" class="form-control" placeholder="80%"></div>
                            </div>
                            <div class="d-flex gap-3 mt-2">
                                <label class="form-check"><input type="checkbox" name="washing_required" class="form-check-input"> Washing Required</label>
                                <label class="form-check"><input type="checkbox" name="road_test_required" class="form-check-input"> Road Test Required</label>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-body">
                            <span class="section-label">📝 Complaints</span>
                            <div id="complaintBox"></div>
                            <button type="button" class="btn btn-outline-primary btn-sm" onclick="addComplaint()"><i class="ri-add-line"></i> Add Complaint</button>
                            <div class="field-row mt-2"><label class="form-label">Customer Voice</label><textarea name="customer_voice" class="form-control" rows="2"></textarea></div>
                            <div class="field-row"><label class="form-label">Technician Observation</label><textarea name="technician_observation" class="form-control" rows="2"></textarea></div>
                            <div class="field-row"><label class="form-label">Recommendation</label><textarea name="recommendation" class="form-control" rows="2"></textarea></div>
                        </div>
                    </div>
                </div>

                <div class="right-panel">
                    <div class="card">
                        <div class="card-body">
  
                            <div class="product-picker">
                                <div class="grid3">
                                    <div style="grid-column:span 2"><label class="form-label">Search Product</label><input type="text" id="productSearch" class="form-control" placeholder="Search product / code / brand" oninput="renderProductList()"></div>
                                    <div><label class="form-label">Default Qty</label><input type="number" step="0.01" id="defaultPartQty" class="form-control" value="1"><div class="small-help">Product rate includes GST.</div></div>
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
                            <span class="section-label">👨‍🔧 Labour Charges</span><div class="alert alert-info py-2 mb-2"><strong>GST Inclusive:</strong> Labour amount is treated as final inclusive GST amount.</div>
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
                                <div><label class="form-label">Amount (Incl. GST)</label><input type="number" step="0.01" id="laborPrice" class="form-control" value="0.00"></div>
                            </div>
                            <div class="grid3 mt-2">
                                <div><label class="form-label">Discount</label><input type="number" step="0.01" id="laborDisc" class="form-control" value="0.00"></div>
                                <div><label class="form-label">GST Type</label><input type="text" id="laborGstType" class="form-control" value="Inclusive" readonly><div class="small-help">All service invoice rates are GST inclusive.</div></div>
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
                                <thead>
                                    <tr><th>Type</th><th>Description</th><th>Qty</th><th>Rate Incl. GST</th><th>GST</th><th>Total</th><th></th></tr>
                                </thead>
                                <tbody id="itemBody"><tr><td colspan="7" class="empty-cart">No products or labour added</td></tr></tbody>
                            </table>
                        </div>
                        <div class="card-body border-top">
                            <div class="grid3 mb-2">
                                <div><label class="form-label">Invoice Discount ₹</label><input type="number" step="0.01" name="discount_amount" id="invoiceDiscount" class="form-control" value="0.00" oninput="renderItems()"></div>
                                <div><label class="form-label">Paid Amount ₹</label><input type="number" step="0.01" name="paid_amount" id="paidAmount" class="form-control" value="0.00" oninput="renderItems()"></div>
                                <div><label class="form-label">Status</label><select name="invoice_status" class="form-select"><option value="confirmed">Confirmed</option><option value="draft">Draft</option></select></div>
                            </div>
                            <div class="grid2 mb-2">
                                <div>
                                    <label class="form-label">Payment Method</label>
                                    <select name="payment_method_id" class="form-select">
                                        <option value="0">-- Select if paid --</option>
                                        <?php foreach ($paymentMethods as $pm): ?>
                                            <option value="<?= (int)$pm['id'] ?>"><?= h($pm['method_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div><label class="form-label">Reference No</label><input type="text" name="reference_no" class="form-control"></div>
                            </div>
                            <div class="total-box mb-3">
                                <div class="total-row"><span>Subtotal</span><span id="subtotalText">₹0.00</span></div>
                                <div class="total-row"><span>CGST</span><span id="cgstText">₹0.00</span></div>
                                <div class="total-row"><span>SGST</span><span id="sgstText">₹0.00</span></div>
                                <div class="total-row"><span>Balance</span><span id="balanceText">₹0.00</span></div>
                                <div class="total-row mb-0"><strong>Grand Total</strong><strong id="grandText">₹0.00</strong></div>
                            </div>
                            <button type="submit" class="btn btn-warning btn-lg w-100"><i class="ri-save-3-line me-1"></i>Create Service Invoice</button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>


<div class="toast-container cart-toast-wrap">
    <div id="cartToast" class="toast cart-toast" role="alert" aria-live="assertive" aria-atomic="true" data-bs-delay="2300">
        <div class="toast-header">
            <i class="ri-shopping-cart-2-line me-2"></i>
            <strong class="me-auto" id="cartToastTitle">Cart Updated</strong>
            <small id="cartToastTime">Now</small>
            <button type="button" class="btn-close ms-2 mb-1" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
        <div class="toast-body" id="cartToastBody">Item added to cart.</div>
    </div>
</div>

<datalist id="vehicleBrandList">
<?php foreach ($vehicleBrands as $vb): ?><option value="<?= h($vb['brand_name']) ?>"></option><?php endforeach; ?>
</datalist>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const products = <?= json_encode($stockProducts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const customerVehicles = <?= json_encode($customerVehicles, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
let selectedProductIds = new Set();
let items = [];
let complaintIndex = 0;

document.addEventListener('DOMContentLoaded', function(){
    addComplaint();
    renderProductList();
    renderItems();
    toggleNewCustomer();
    refreshVehicleList();
});

function money(n){ return '₹' + (Number(n || 0)).toFixed(2); }
function num(id){ const e=document.getElementById(id); return e ? (parseFloat(e.value) || 0) : 0; }
function val(id){ const e=document.getElementById(id); return e ? e.value : ''; }
function escapeHtml(s){ return String(s ?? '').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c])); }

function showCartToast(message, title='Cart Updated', type='success'){
    const toastEl = document.getElementById('cartToast');
    const titleEl = document.getElementById('cartToastTitle');
    const bodyEl = document.getElementById('cartToastBody');
    const timeEl = document.getElementById('cartToastTime');
    if (!toastEl || !bodyEl) return;

    toastEl.classList.remove('warning','info');
    if (type === 'warning') toastEl.classList.add('warning');
    if (type === 'info') toastEl.classList.add('info');

    titleEl.textContent = title;
    bodyEl.textContent = message;
    timeEl.textContent = 'Now';

    if (window.bootstrap && bootstrap.Toast) {
        bootstrap.Toast.getOrCreateInstance(toastEl, {delay:2300, autohide:true}).show();
    } else {
        toastEl.classList.add('show');
        setTimeout(() => toastEl.classList.remove('show'), 2300);
    }
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
        const label = [v.brand_name, v.model_name, v.registration_no || 'No Reg'].filter(Boolean).join(' / ');
        const opt = document.createElement('option');
        opt.value = v.id;
        opt.textContent = label;
        sel.appendChild(opt);
    });
    toggleNewVehicle();
}

function toggleNewVehicle(){
    document.getElementById('newVehicleBox').style.display = val('customer_vehicle_id') === '0' ? 'block' : 'none';
}

function addComplaint(text='', priority='medium'){
    const box = document.getElementById('complaintBox');
    const idx = complaintIndex++;
    const div = document.createElement('div');
    div.className = 'complaint-row';
    div.innerHTML = `
        <div class="complaint-inner">
            <textarea name="complaints[${idx}][text]" class="form-control" rows="2" placeholder="Enter complaint / issue">${escapeHtml(text)}</textarea>
            <input type="hidden" name="complaints[${idx}][priority]" value="medium">
            <button type="button" class="btn btn-outline-danger" onclick="this.closest('.complaint-row').remove()"><i class="ri-delete-bin-line"></i></button>
        </div>`;
    box.appendChild(div);
}

function renderProductList(){
    const q = val('productSearch').toLowerCase();
    const box = document.getElementById('productList');
    const filtered = products.filter(p => {
        const hay = [p.product_name,p.product_code,p.item_code,p.brand_name,p.hsn_code].join(' ').toLowerCase();
        return hay.includes(q);
    }).slice(0, 80);
    if (!filtered.length) {
        box.innerHTML = '<div class="empty-cart">No products found</div>';
        return;
    }
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

function updateSelectedCount(){
    document.getElementById('selectedCount').textContent = selectedProductIds.size + ' selected';
}

function clearProductSelection(){
    selectedProductIds.clear();
    renderProductList();
    updateSelectedCount();
}

function addSelectedProducts(){
    if (selectedProductIds.size === 0) {
        showCartToast('Please select at least one product before adding.', 'No Product Selected', 'warning');
        return;
    }

    const qty = num('defaultPartQty') || 1;
    let addedCount = 0;
    let updatedCount = 0;
    let lastProductName = '';

    selectedProductIds.forEach(id => {
        const p = products.find(x => String(x.id) === String(id));
        if (!p) return;
        lastProductName = p.product_name || 'Product';

        const existingIndex = items.findIndex(it =>
            it.type === 'part' && String(it.product_id) === String(p.id)
        );

        if (existingIndex >= 0) {
            items[existingIndex].qty = Number(items[existingIndex].qty || 0) + qty;
            items[existingIndex].unit_price = Number(p.selling_price || items[existingIndex].unit_price || 0);
            items[existingIndex].gst_percent = Number(p.gst_percent || items[existingIndex].gst_percent || 0);
            items[existingIndex].gst_type = p.gst_type || 'inclusive';
            updatedCount++;
        } else {
            items.push({
                type:'part',
                product_id:p.id,
                description:p.product_name,
                qty:qty,
                unit_price:Number(p.selling_price || 0),
                discount_amount:0,
                gst_percent:Number(p.gst_percent || 0),
                gst_type:p.gst_type || 'inclusive'
            });
            addedCount++;
        }
    });

    clearProductSelection();
    renderItems();

    if (addedCount > 0 && updatedCount > 0) {
        showCartToast(`${addedCount} product(s) added and ${updatedCount} product qty updated.`, 'Cart Updated', 'success');
    } else if (updatedCount > 0) {
        showCartToast(updatedCount === 1 ? `${lastProductName} quantity updated in cart.` : `${updatedCount} product quantities updated in cart.`, 'Quantity Updated', 'info');
    } else if (addedCount > 0) {
        showCartToast(addedCount === 1 ? `${lastProductName} added to cart.` : `${addedCount} products added to cart.`, 'Item Added', 'success');
    }
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
    if (!desc) { alert('Enter labour description'); return; }
    const qty = num('laborQty') || 1;
    const price = num('laborPrice');
    if (price <= 0) { alert('Enter labour amount'); return; }

    const laborId = parseInt(val('laborMaster') || '0');
    const gst = num('laborGst');
    const disc = num('laborDisc');
    const existingIndex = items.findIndex(it =>
        it.type === 'labour' &&
        Number(it.labor_id || 0) === laborId &&
        String(it.description || '').trim().toLowerCase() === desc.toLowerCase() &&
        Number(it.unit_price || 0) === price &&
        Number(it.gst_percent || 0) === gst
    );

    let labourUpdated = false;
    if (existingIndex >= 0) {
        items[existingIndex].qty = Number(items[existingIndex].qty || 0) + qty;
        items[existingIndex].discount_amount = Number(items[existingIndex].discount_amount || 0) + disc;
        items[existingIndex].gst_type = 'inclusive';
        labourUpdated = true;
    } else {
        items.push({
            type:'labour',
            labor_id:laborId,
            description:desc,
            qty:qty,
            unit_price:price,
            discount_amount:disc,
            gst_percent:gst,
            gst_type:'inclusive'
        });
    }

    document.getElementById('laborMaster').value = '';
    document.getElementById('laborDesc').value = '';
    document.getElementById('laborQty').value = '1';
    document.getElementById('laborPrice').value = '0.00';
    document.getElementById('laborDisc').value = '0.00';
    renderItems();
    showCartToast(labourUpdated ? `${desc} quantity updated in cart.` : `${desc} added to cart.`, labourUpdated ? 'Quantity Updated' : 'Labour Added', labourUpdated ? 'info' : 'success');
}

function mergeDuplicateItems(){
    const merged = [];
    const map = new Map();

    items.forEach(it => {
        const key = it.type === 'part'
            ? `part:${it.product_id}`
            : `labour:${Number(it.labor_id || 0)}:${String(it.description || '').trim().toLowerCase()}:${Number(it.unit_price || 0)}:${Number(it.gst_percent || 0)}`;

        if (map.has(key)) {
            const existing = merged[map.get(key)];
            existing.qty = Number(existing.qty || 0) + Number(it.qty || 0);
            existing.discount_amount = Number(existing.discount_amount || 0) + Number(it.discount_amount || 0);
            existing.gst_type = 'inclusive';
        } else {
            const copy = Object.assign({}, it, { gst_type: 'inclusive' });
            map.set(key, merged.length);
            merged.push(copy);
        }
    });

    items = merged;
}

function calcLine(it){
    const qty = Number(it.qty || 0);
    const sellingPrice = Number(it.unit_price || 0);
    const discount = Number(it.discount_amount || 0);
    const gstPercent = Number(it.gst_percent || 0);

    // Product selling_price is treated as the final invoice rate.
    // Therefore Total must always be Qty × Selling Price − Discount.
    const grossTotal = qty * sellingPrice;
    const total = Math.max(0, grossTotal - discount);

    let taxable = total;
    if (gstPercent > 0) {
        taxable = total / (1 + (gstPercent / 100));
    }

    const tax = Math.max(0, total - taxable);
    return { taxable, tax, total };
}

function renderItems(){
    mergeDuplicateItems();
    const body = document.getElementById('itemBody');
    document.querySelectorAll('.dyn-item-input').forEach(e => e.remove());
    if (!items.length) {
        body.innerHTML = '<tr><td colspan="7" class="empty-cart">No products or labour added</td></tr>';
    } else {
        body.innerHTML = items.map((it, i) => {
            const c = calcLine(it);
            return `<tr>
                <td><span class="badge ${it.type==='part'?'text-bg-primary':'text-bg-success'}">${it.type==='part'?'Product':'Labour'}</span></td>
                <td>${escapeHtml(it.description)}</td>
                <td style="width:90px"><input type="number" step="0.01" class="form-control form-control-sm" value="${it.qty}" onchange="items[${i}].qty=parseFloat(this.value)||1;renderItems();"></td>
                <td style="width:120px"><input type="number" step="0.01" class="form-control form-control-sm" value="${it.unit_price}" onchange="items[${i}].unit_price=parseFloat(this.value)||0;renderItems();"></td>
                <td>${Number(it.gst_percent || 0).toFixed(2)}%<br><span class="badge-soft">Inclusive</span></td>
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
    input.type = 'hidden';
    input.name = name;
    input.value = value;
    input.className = 'dyn-item-input';
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

function removeItem(i){ items.splice(i,1); renderItems(); }
function clearItems(){ if(confirm('Clear all invoice items?')){ items=[]; renderItems(); showCartToast('All invoice items removed from cart.', 'Cart Cleared', 'warning'); } }
</script>
</body>
</html>
