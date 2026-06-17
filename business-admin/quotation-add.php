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
$sessionBranchId = isset($_SESSION['branch_id']) ? (int)$_SESSION['branch_id'] : 0;

if ($businessUserId <= 0 || $businessId <= 0) {
    header('Location: login.php');
    exit;
}

function h($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function tableExists(mysqli $conn, string $table): bool {
    $stmt = $conn->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1");
    if (!$stmt) return false;
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $res = $stmt->get_result();
    $ok = $res && $res->num_rows > 0;
    $stmt->close();
    return $ok;
}

function columnExists(mysqli $conn, string $table, string $column): bool {
    $stmt = $conn->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
    if (!$stmt) return false;
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $res = $stmt->get_result();
    $ok = $res && $res->num_rows > 0;
    $stmt->close();
    return $ok;
}

function fetchAllAssoc(mysqli $conn, string $sql): array {
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

function parseDecimal($value): float {
    $value = preg_replace('/[^0-9.\-]/', '', (string)($value ?? 0));
    return round(is_numeric($value) ? (float)$value : 0, 2);
}

function nextQuotationNo(mysqli $conn, int $businessId, int $branchId): string {
    $prefix = 'QTN';

    if (tableExists($conn, 'business_settings') && columnExists($conn, 'business_settings', 'quotation_prefix')) {
        $stmt = $conn->prepare("SELECT quotation_prefix FROM business_settings WHERE business_id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $businessId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if (!empty($row['quotation_prefix'])) {
                $prefix = trim((string)$row['quotation_prefix']);
            }
            $stmt->close();
        }
    }

    $running = 1;
    $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM quotations WHERE business_id = ? AND branch_id = ?");
    if ($stmt) {
        $stmt->bind_param('ii', $businessId, $branchId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $running = ((int)($row['total'] ?? 0)) + 1;
        $stmt->close();
    }

    return $prefix . '-' . date('Ymd') . '-' . str_pad((string)$running, 4, '0', STR_PAD_LEFT);
}

$requiredTables = ['business_users', 'businesses', 'branches', 'customers', 'quotations', 'quotation_items'];
foreach ($requiredTables as $tbl) {
    if (!tableExists($conn, $tbl)) {
        die($tbl . ' table not found.');
    }
}

/* Required quotation item columns */
if (!columnExists($conn, 'quotation_items', 'gst_type')) {
    $conn->query("ALTER TABLE quotation_items ADD COLUMN gst_type ENUM('inclusive','exclusive') NOT NULL DEFAULT 'exclusive' AFTER taxable_value");
}
if (!columnExists($conn, 'quotation_items', 'manual_vehicle_json')) {
    $conn->query("ALTER TABLE quotation_items ADD COLUMN manual_vehicle_json TEXT NULL AFTER product_id");
}
if (!columnExists($conn, 'quotation_items', 'vehicle_model_id')) {
    $conn->query("ALTER TABLE quotation_items ADD COLUMN vehicle_model_id BIGINT(20) UNSIGNED NULL AFTER vehicle_stock_id");
    $conn->query("ALTER TABLE quotation_items ADD INDEX idx_quotation_items_vehicle_model (vehicle_model_id)");
}

$hasProducts = tableExists($conn, 'products');
$hasVehicleStock = tableExists($conn, 'vehicle_stock');
$hasVehicleModels = tableExists($conn, 'vehicle_models');
$hasVehicleBrands = tableExists($conn, 'vehicle_brands');

/* Login validation */
$loggedUser = null;
$stmt = $conn->prepare("
    SELECT bu.id, bu.full_name, bu.role, bu.status,
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

if (!$loggedUser || (int)($loggedUser['status'] ?? 0) !== 1 || ($loggedUser['business_status'] ?? '') !== 'active') {
    session_destroy();
    header('Location: login.php');
    exit;
}

/* Master data */
$branches = fetchAllAssoc($conn, "
    SELECT id, branch_name, branch_code
    FROM branches
    WHERE business_id = {$businessId}
      AND status = 'active'
    ORDER BY branch_name ASC
");

$customers = fetchAllAssoc($conn, "
    SELECT id, full_name, mobile
    FROM customers
    WHERE business_id = {$businessId}
    ORDER BY full_name ASC
");

$products = [];
if ($hasProducts) {
    $gstTypeCol = columnExists($conn, 'products', 'gst_type') ? "COALESCE(gst_type, 'exclusive')" : "'exclusive'";
    $products = fetchAllAssoc($conn, "
        SELECT
            id,
            product_name,
            product_code,
            item_code,
            COALESCE(selling_price, 0) AS selling_price,
            COALESCE(gst_percent, 0) AS gst_percent,
            {$gstTypeCol} AS gst_type,
            unit
        FROM products
        WHERE business_id = {$businessId}
          AND status = 1
        ORDER BY product_name ASC
    ");
}

$vehicles = [];
if ($hasVehicleModels) {
    $vehicleGstTypeExpr = columnExists($conn, 'vehicle_models', 'gst_type')
        ? "COALESCE(vm.gst_type, 'inclusive')"
        : "'inclusive'";

    $vehicles = fetchAllAssoc($conn, "
        SELECT
            vm.id,
            vm.brand_id,
            vm.category_id,
            vm.model_name,
            vm.variant_name,
            vm.vehicle_type,
            vm.fuel_type,
            vm.transmission,
            vm.engine_cc,
            vm.mileage,
            vm.battery_capacity,
            vm.motor_power,
            vm.range_km,
            vm.charging_time,
            vm.battery_brand,
            vm.charger_brand,
            vm.charger_type,
            vm.color_options,
            COALESCE(vm.ex_showroom_price, 0) AS sale_price,
            COALESCE(vm.gst_percent, 0) AS gst_percent,
            COALESCE(vm.cess_percent, 0) AS cess_percent,
            {$vehicleGstTypeExpr} AS gst_type,
            COALESCE(vm.insurance_price, 0) AS insurance_price,
            COALESCE(vm.registration_price, 0) AS registration_price,
            COALESCE(vm.rto_charge, 0) AS rto_charge,
            COALESCE(vm.road_tax, 0) AS road_tax,
            COALESCE(vm.hypothecation_charge, 0) AS hypothecation_charge,
            COALESCE(vm.other_charge, 0) AS other_charge,
            vm.hsn_code,
            vb.brand_name,
            vc.category_name
        FROM vehicle_models vm
        LEFT JOIN vehicle_brands vb ON vb.id = vm.brand_id
        LEFT JOIN vehicle_categories vc ON vc.id = vm.category_id
        WHERE vm.business_id = {$businessId}
          AND vm.status = 1
        ORDER BY vb.brand_name ASC, vm.model_name ASC, vm.variant_name ASC
    ");
}

$defaultBranchId = $sessionBranchId > 0 ? $sessionBranchId : 0;
if ($defaultBranchId <= 0 && !empty($branches)) {
    $defaultBranchId = (int)$branches[0]['id'];
}

$form = [
    'branch_id' => $defaultBranchId,
    'customer_mode' => 'existing',
    'customer_id' => 0,
    'new_full_name' => '',
    'new_mobile' => '',
    'new_alternate_mobile' => '',
    'new_email' => '',
    'new_gstin' => '',
    'new_address_line1' => '',
    'new_address_line2' => '',
    'new_city' => '',
    'new_district' => '',
    'new_state' => '',
    'new_pincode' => '',
    'quotation_no' => $defaultBranchId > 0 ? nextQuotationNo($conn, $businessId, $defaultBranchId) : '',
    'quotation_date' => date('Y-m-d\TH:i'),
    'valid_until' => date('Y-m-d', strtotime('+7 days')),
    'quotation_type' => 'vehicle',
    'customer_note' => '',
    'terms_conditions' => '',
    'items' => [
        [
            'item_type' => 'vehicle',
            'vehicle_mode' => 'stock',
            'vehicle_stock_id' => '',
            'vehicle_model_id' => '',
            'product_id' => '',
            'manual_brand' => '',
            'manual_model' => '',
            'manual_variant' => '',
            'manual_color' => '',
            'manual_chassis_no' => '',
            'manual_engine_no' => '',
            'manual_motor_no' => '',
            'manual_battery_no' => '',
            'description' => '',
            'qty' => '1',
            'unit_price' => '0',
            'discount_amount' => '0',
            'gst_type' => 'inclusive',
            'cgst_percent' => '0',
            'sgst_percent' => '0',
            'igst_percent' => '0',
            'cess_percent' => '0'
        ]
    ]
];

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['branch_id'] = (int)($_POST['branch_id'] ?? 0);
    $form['customer_mode'] = trim($_POST['customer_mode'] ?? 'existing');
    $form['customer_id'] = (int)($_POST['customer_id'] ?? 0);

    $form['new_full_name'] = trim($_POST['new_full_name'] ?? '');
    $form['new_mobile'] = trim($_POST['new_mobile'] ?? '');
    $form['new_alternate_mobile'] = trim($_POST['new_alternate_mobile'] ?? '');
    $form['new_email'] = trim($_POST['new_email'] ?? '');
    $form['new_gstin'] = trim($_POST['new_gstin'] ?? '');
    $form['new_address_line1'] = trim($_POST['new_address_line1'] ?? '');
    $form['new_address_line2'] = trim($_POST['new_address_line2'] ?? '');
    $form['new_city'] = trim($_POST['new_city'] ?? '');
    $form['new_district'] = trim($_POST['new_district'] ?? '');
    $form['new_state'] = trim($_POST['new_state'] ?? '');
    $form['new_pincode'] = trim($_POST['new_pincode'] ?? '');

    $form['quotation_no'] = trim($_POST['quotation_no'] ?? '');
    $form['quotation_date'] = trim($_POST['quotation_date'] ?? '');
    $form['valid_until'] = trim($_POST['valid_until'] ?? '');
    $form['quotation_type'] = 'vehicle';
    $form['customer_note'] = trim($_POST['customer_note'] ?? '');
    $form['terms_conditions'] = trim($_POST['terms_conditions'] ?? '');
    $form['items'] = $_POST['items'] ?? [];

    $allowedQuotationTypes = ['vehicle'];
    $allowedItemTypes = ['vehicle'];
    $allowedCustomerModes = ['existing', 'new'];
    $allowedGstTypes = ['inclusive', 'exclusive'];
    $allowedVehicleModes = ['stock', 'manual'];

    if ($form['branch_id'] <= 0) {
        $error = 'Please select branch.';
    } elseif (!in_array($form['customer_mode'], $allowedCustomerModes, true)) {
        $error = 'Invalid customer mode.';
    } elseif ($form['quotation_no'] === '') {
        $error = 'Quotation number is required.';
    } elseif ($form['quotation_date'] === '') {
        $error = 'Quotation date is required.';
    } elseif (!in_array($form['quotation_type'], $allowedQuotationTypes, true)) {
        $error = 'Invalid quotation type.';
    } elseif (!is_array($form['items']) || count($form['items']) === 0) {
        $error = 'At least one item is required.';
    }

    if ($error === '' && $form['customer_mode'] === 'existing') {
        if ($form['customer_id'] <= 0) {
            $error = 'Please select customer.';
        } else {
            $stmt = $conn->prepare("SELECT id FROM customers WHERE id = ? AND business_id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('ii', $form['customer_id'], $businessId);
                $stmt->execute();
                $exists = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$exists) $error = 'Selected customer is invalid.';
            }
        }
    }

    if ($error === '' && $form['customer_mode'] === 'new') {
        if ($form['new_full_name'] === '') {
            $error = 'Please enter customer full name.';
        } elseif ($form['new_mobile'] === '') {
            $error = 'Please enter customer mobile.';
        } else {
            $stmt = $conn->prepare("SELECT id FROM customers WHERE business_id = ? AND mobile = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('is', $businessId, $form['new_mobile']);
                $stmt->execute();
                $exists = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($exists) $error = 'A customer with this mobile already exists.';
            }
        }
    }

    $cleanItems = [];
    $subtotal = 0.00;
    $discountAmount = 0.00;
    $cgstAmount = 0.00;
    $sgstAmount = 0.00;
    $igstAmount = 0.00;
    $cessAmount = 0.00;
    $roundOff = 0.00;
    $grandTotal = 0.00;

    if ($error === '') {
        foreach ($form['items'] as $item) {
            $itemType = 'vehicle';
            $vehicleMode = trim((string)($item['vehicle_mode'] ?? 'stock'));
            $gstType = trim((string)($item['gst_type'] ?? 'inclusive'));

            if (!in_array($itemType, $allowedItemTypes, true)) continue;
            if (!in_array($vehicleMode, $allowedVehicleModes, true)) $vehicleMode = 'stock';
            if (!in_array($gstType, $allowedGstTypes, true)) $gstType = 'inclusive';

            $vehicleStockId = 0;
            $vehicleModelId = (int)($item['vehicle_model_id'] ?? 0);
            $productId = 0;

            $manualVehicle = [
                'brand' => trim((string)($item['manual_brand'] ?? '')),
                'model' => trim((string)($item['manual_model'] ?? '')),
                'variant' => trim((string)($item['manual_variant'] ?? '')),
                'color' => trim((string)($item['manual_color'] ?? '')),
                'chassis_no' => trim((string)($item['manual_chassis_no'] ?? '')),
                'engine_no' => trim((string)($item['manual_engine_no'] ?? '')),
                'motor_no' => trim((string)($item['manual_motor_no'] ?? '')),
                'battery_no' => trim((string)($item['manual_battery_no'] ?? '')),
            ];

            $description = trim((string)($item['description'] ?? ''));
            $qty = parseDecimal($item['qty'] ?? 0);
            $unitPrice = parseDecimal($item['unit_price'] ?? 0);
            $itemDiscount = parseDecimal($item['discount_amount'] ?? 0);
            $cgstPercent = parseDecimal($item['cgst_percent'] ?? 0);
            $sgstPercent = parseDecimal($item['sgst_percent'] ?? 0);
            $igstPercent = parseDecimal($item['igst_percent'] ?? 0);
            $cessPercent = parseDecimal($item['cess_percent'] ?? 0);

            if ($itemType === 'vehicle') {
                $productId = 0;

                if ($vehicleMode === 'stock') {
                    if ($vehicleModelId <= 0) continue;

                    $modelStmt = $conn->prepare("
                        SELECT vm.*, vb.brand_name, vc.category_name
                        FROM vehicle_models vm
                        LEFT JOIN vehicle_brands vb ON vb.id = vm.brand_id
                        LEFT JOIN vehicle_categories vc ON vc.id = vm.category_id
                        WHERE vm.id = ? AND vm.business_id = ? AND vm.status = 1
                        LIMIT 1
                    ");
                    if (!$modelStmt) continue;
                    $modelStmt->bind_param('ii', $vehicleModelId, $businessId);
                    $modelStmt->execute();
                    $selectedModel = $modelStmt->get_result()->fetch_assoc();
                    $modelStmt->close();
                    if (!$selectedModel) continue;

                    $manualVehicleJson = json_encode([
                        'source' => 'vehicle_models',
                        'vehicle_model_id' => $vehicleModelId,
                        'brand' => $selectedModel['brand_name'] ?? '',
                        'model' => $selectedModel['model_name'] ?? '',
                        'variant' => $selectedModel['variant_name'] ?? '',
                        'category' => $selectedModel['category_name'] ?? '',
                        'vehicle_type' => $selectedModel['vehicle_type'] ?? '',
                        'fuel_type' => $selectedModel['fuel_type'] ?? '',
                        'color_options' => $selectedModel['color_options'] ?? '',
                        'battery_capacity' => $selectedModel['battery_capacity'] ?? '',
                        'battery_brand' => $selectedModel['battery_brand'] ?? '',
                        'charger_brand' => $selectedModel['charger_brand'] ?? '',
                        'charger_type' => $selectedModel['charger_type'] ?? '',
                        'hsn_code' => $selectedModel['hsn_code'] ?? ''
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                    if ($description === '') {
                        $description = trim(
                            ($selectedModel['brand_name'] ?? '') . ' ' .
                            ($selectedModel['model_name'] ?? '') . ' ' .
                            ($selectedModel['variant_name'] ?? '')
                        );
                    }
                } else {
                    $vehicleStockId = 0;
                    if ($manualVehicle['brand'] === '' && $manualVehicle['model'] === '' && $description === '') continue;

                    $manualVehicleJson = json_encode($manualVehicle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                    if ($description === '') {
                        $description = trim($manualVehicle['brand'] . ' ' . $manualVehicle['model'] . ' ' . $manualVehicle['variant']);
                    }
                }
            } elseif ($itemType === 'product') {
                $vehicleStockId = 0;
                $productId = $productId > 0 ? $productId : 0;
                $manualVehicleJson = null;
                if ($productId <= 0) continue;
            } else {
                $vehicleStockId = 0;
                $productId = 0;
                $manualVehicleJson = null;
                if ($description === '') continue;
            }

            if ($qty <= 0 || $unitPrice < 0) continue;

            $lineBase = round($qty * $unitPrice, 2);
            $itemDiscount = min($itemDiscount, $lineBase);
            $afterDiscountTotal = round(max(0, $lineBase - $itemDiscount), 2);

            $totalTaxPercent = $cgstPercent + $sgstPercent + $igstPercent + $cessPercent;

            if ($gstType === 'inclusive' && $totalTaxPercent > 0) {
                $taxableValue = round($afterDiscountTotal / (1 + ($totalTaxPercent / 100)), 2);
                $lineCgst = round($taxableValue * $cgstPercent / 100, 2);
                $lineSgst = round($taxableValue * $sgstPercent / 100, 2);
                $lineIgst = round($taxableValue * $igstPercent / 100, 2);
                $lineCess = round($taxableValue * $cessPercent / 100, 2);
                $lineTotal = $afterDiscountTotal;
            } else {
                $taxableValue = $afterDiscountTotal;
                $lineCgst = round($taxableValue * $cgstPercent / 100, 2);
                $lineSgst = round($taxableValue * $sgstPercent / 100, 2);
                $lineIgst = round($taxableValue * $igstPercent / 100, 2);
                $lineCess = round($taxableValue * $cessPercent / 100, 2);
                $lineTotal = round($taxableValue + $lineCgst + $lineSgst + $lineIgst + $lineCess, 2);
            }

            $subtotal += $lineBase;
            $discountAmount += $itemDiscount;
            $cgstAmount += $lineCgst;
            $sgstAmount += $lineSgst;
            $igstAmount += $lineIgst;
            $cessAmount += $lineCess;
            $grandTotal += $lineTotal;

            $cleanItems[] = [
                'item_type' => $itemType,
                'vehicle_stock_id' => null,
                'vehicle_model_id' => $vehicleModelId,
                'product_id' => null,
                'manual_vehicle_json' => $manualVehicleJson,
                'description' => $description,
                'qty' => $qty,
                'unit_price' => $unitPrice,
                'discount_amount' => $itemDiscount,
                'taxable_value' => $taxableValue,
                'gst_type' => $gstType,
                'cgst_percent' => $cgstPercent,
                'sgst_percent' => $sgstPercent,
                'igst_percent' => $igstPercent,
                'cess_percent' => $cessPercent,
                'cgst_amount' => $lineCgst,
                'sgst_amount' => $lineSgst,
                'igst_amount' => $lineIgst,
                'cess_amount' => $lineCess,
                'line_total' => $lineTotal
            ];
        }

        if (empty($cleanItems)) {
            $error = 'Please enter at least one valid item.';
        }
    }

    if ($error === '') {
        $stmt = $conn->prepare("SELECT id FROM quotations WHERE business_id = ? AND branch_id = ? AND quotation_no = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('iis', $businessId, $form['branch_id'], $form['quotation_no']);
            $stmt->execute();
            $exists = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($exists) $error = 'Quotation number already exists.';
        }
    }

    if ($error === '') {
        $conn->begin_transaction();

        try {
            $finalCustomerId = 0;

            if ($form['customer_mode'] === 'existing') {
                $finalCustomerId = $form['customer_id'];
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO customers (
                        business_id, full_name, mobile, alternate_mobile, email, gstin,
                        address_line1, address_line2, city, district, state, pincode, created_at
                    ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW())
                ");
                if (!$stmt) throw new Exception('Failed to prepare customer insert.');

                $stmt->bind_param(
                    'isssssssssss',
                    $businessId,
                    $form['new_full_name'],
                    $form['new_mobile'],
                    $form['new_alternate_mobile'],
                    $form['new_email'],
                    $form['new_gstin'],
                    $form['new_address_line1'],
                    $form['new_address_line2'],
                    $form['new_city'],
                    $form['new_district'],
                    $form['new_state'],
                    $form['new_pincode']
                );

                if (!$stmt->execute()) throw new Exception('Failed to save new customer: ' . $stmt->error);
                $finalCustomerId = (int)$stmt->insert_id;
                $stmt->close();
            }

            $status = 'draft';

            $stmt = $conn->prepare("
                INSERT INTO quotations (
                    business_id, branch_id, quotation_no, customer_id,
                    quotation_date, valid_until, quotation_type,
                    subtotal, discount_amount, cgst_amount, sgst_amount, igst_amount,
                    cess_amount, round_off, grand_total,
                    status, customer_note, terms_conditions, created_by, created_at
                ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
            ");
            if (!$stmt) throw new Exception('Failed to prepare quotation insert.');

            $stmt->bind_param(
                'iisisssddddddddsssi',
                $businessId,
                $form['branch_id'],
                $form['quotation_no'],
                $finalCustomerId,
                $form['quotation_date'],
                $form['valid_until'],
                $form['quotation_type'],
                $subtotal,
                $discountAmount,
                $cgstAmount,
                $sgstAmount,
                $igstAmount,
                $cessAmount,
                $roundOff,
                $grandTotal,
                $status,
                $form['customer_note'],
                $form['terms_conditions'],
                $businessUserId
            );

            if (!$stmt->execute()) throw new Exception('Failed to save quotation: ' . $stmt->error);
            $quotationId = (int)$stmt->insert_id;
            $stmt->close();

            $stmtItem = $conn->prepare("
                INSERT INTO quotation_items (
                    quotation_id, item_type, vehicle_stock_id, vehicle_model_id, product_id, manual_vehicle_json,
                    description, qty, unit_price, discount_amount, taxable_value, gst_type,
                    cgst_percent, sgst_percent, igst_percent, cess_percent,
                    cgst_amount, sgst_amount, igst_amount, cess_amount, line_total
                ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ");
            if (!$stmtItem) throw new Exception('Failed to prepare quotation item insert: ' . $conn->error);

            foreach ($cleanItems as $ci) {
                $vehicleStockId = null;
                $vehicleModelId = $ci['vehicle_model_id'] > 0 ? $ci['vehicle_model_id'] : null;
                $productId = null;

                $stmtItem->bind_param(
                    'isiiissddddsddddddddd',
                    $quotationId,
                    $ci['item_type'],
                    $vehicleStockId,
                    $vehicleModelId,
                    $productId,
                    $ci['manual_vehicle_json'],
                    $ci['description'],
                    $ci['qty'],
                    $ci['unit_price'],
                    $ci['discount_amount'],
                    $ci['taxable_value'],
                    $ci['gst_type'],
                    $ci['cgst_percent'],
                    $ci['sgst_percent'],
                    $ci['igst_percent'],
                    $ci['cess_percent'],
                    $ci['cgst_amount'],
                    $ci['sgst_amount'],
                    $ci['igst_amount'],
                    $ci['cess_amount'],
                    $ci['line_total']
                );

                if (!$stmtItem->execute()) {
                    throw new Exception('Failed to save quotation item: ' . $stmtItem->error);
                }
            }

            $stmtItem->close();

            if (tableExists($conn, 'audit_logs')) {
                $desc = 'Created quotation: ' . $form['quotation_no'];
                $stmtLog = $conn->prepare("
                    INSERT INTO audit_logs (
                        business_id, branch_id, user_id, action, module_name,
                        ref_table, ref_id, description, created_at
                    ) VALUES (?, ?, ?, 'Create', 'Quotations', 'quotations', ?, ?, NOW())
                ");
                if ($stmtLog) {
                    $stmtLog->bind_param('iiiis', $businessId, $form['branch_id'], $businessUserId, $quotationId, $desc);
                    $stmtLog->execute();
                    $stmtLog->close();
                }
            }

            $conn->commit();
            header('Location: quotation-print.php?id=' . $quotationId);
            exit;

        } catch (Throwable $e) {
            $conn->rollback();
            $error = $e->getMessage();
        }
    }
}

$pageTitle = 'Create Quotation';
$currentPage = 'quotation-add';
?>
<!doctype html>
<html lang="en">
<?php include('includes/head.php'); ?>

<body data-sidebar="dark">

<style>
.page-content { padding-bottom: 90px !important; }
.main-content { min-height: calc(100vh - 70px); }
.item-row {
    border: 1px solid #e9ecef;
    border-radius: 0.5rem;
    padding: 14px;
    margin-bottom: 14px;
    background: #fbfbfb;
}
.customer-box, .manual-vehicle-box {
    border: 1px dashed #d6dbe3;
    border-radius: 0.5rem;
    padding: 16px;
    background: #fcfcfc;
}
.section-title {
    font-size: 14px;
    font-weight: 600;
    color: #495057;
    margin-bottom: 12px;
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

                <div class="row mb-3">
                    <div class="col-md-7">
                        <h4 class="mb-1">Create Quotation</h4>
                        <p class="text-muted mb-0">Create quotation with product, stock vehicle, manual vehicle and GST inclusive/exclusive calculation</p>
                    </div>
                    <div class="col-md-5 text-md-end mt-3 mt-md-0">
                        <a href="quotations.php" class="btn btn-secondary">Back to Quotations</a>
                    </div>
                </div>

                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger"><?php echo h($error); ?></div>
                <?php endif; ?>

                <form method="post" id="quotationForm">
                    <div class="row">
                        <div class="col-xl-8">

                            <div class="card">
                                <div class="card-header">
                                    <h5 class="mb-0">Quotation Details</h5>
                                </div>
                                <div class="card-body">
                                    <div class="row g-3">

                                        <div class="col-md-4">
                                            <label class="form-label">Branch <span class="text-danger">*</span></label>
                                            <select name="branch_id" id="branch_id" class="form-select" required>
                                                <option value="">Select Branch</option>
                                                <?php foreach ($branches as $b): ?>
                                                    <option value="<?php echo (int)$b['id']; ?>" <?php echo ((int)$form['branch_id'] === (int)$b['id']) ? 'selected' : ''; ?>>
                                                        <?php echo h($b['branch_name'] . ' (' . $b['branch_code'] . ')'); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="col-md-4">
                                            <label class="form-label">Quotation No <span class="text-danger">*</span></label>
                                            <input type="text" name="quotation_no" class="form-control" value="<?php echo h($form['quotation_no']); ?>" required>
                                        </div>

                                        <div class="col-md-4">
                                            <label class="form-label">Quotation Date <span class="text-danger">*</span></label>
                                            <input type="datetime-local" name="quotation_date" class="form-control" value="<?php echo h($form['quotation_date']); ?>" required>
                                        </div>

                                        <div class="col-md-4">
                                            <label class="form-label">Valid Until</label>
                                            <input type="date" name="valid_until" class="form-control" value="<?php echo h($form['valid_until']); ?>">
                                        </div>

                                        <div class="col-md-4">
                                            <label class="form-label">Quotation Type</label>
                                            <input type="text" class="form-control" value="Vehicle" readonly>
                                            <input type="hidden" name="quotation_type" value="vehicle">
                                        </div>

                                        <div class="col-md-4">
                                            <label class="form-label">Customer Mode</label>
                                            <select name="customer_mode" id="customer_mode" class="form-select" onchange="toggleCustomerMode()">
                                                <option value="existing" <?php echo ($form['customer_mode'] === 'existing') ? 'selected' : ''; ?>>Existing Customer</option>
                                                <option value="new" <?php echo ($form['customer_mode'] === 'new') ? 'selected' : ''; ?>>New Customer</option>
                                            </select>
                                        </div>

                                        <div class="col-md-12" id="existingCustomerWrap">
                                            <label class="form-label">Customer <span class="text-danger">*</span></label>
                                            <select name="customer_id" class="form-select">
                                                <option value="0">Select Customer</option>
                                                <?php foreach ($customers as $c): ?>
                                                    <option value="<?php echo (int)$c['id']; ?>" <?php echo ((int)$form['customer_id'] === (int)$c['id']) ? 'selected' : ''; ?>>
                                                        <?php echo h($c['full_name'] . ' - ' . $c['mobile']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="col-md-12" id="newCustomerWrap" style="display:none;">
                                            <div class="customer-box">
                                                <div class="section-title">New Customer Details</div>
                                                <div class="row g-3">
                                                    <div class="col-md-6">
                                                        <label class="form-label">Full Name</label>
                                                        <input type="text" name="new_full_name" class="form-control" value="<?php echo h($form['new_full_name']); ?>">
                                                    </div>
                                                    <div class="col-md-6">
                                                        <label class="form-label">Mobile</label>
                                                        <input type="text" name="new_mobile" class="form-control" value="<?php echo h($form['new_mobile']); ?>">
                                                    </div>
                                                    <div class="col-md-6">
                                                        <label class="form-label">Alternate Mobile</label>
                                                        <input type="text" name="new_alternate_mobile" class="form-control" value="<?php echo h($form['new_alternate_mobile']); ?>">
                                                    </div>
                                                    <div class="col-md-6">
                                                        <label class="form-label">Email</label>
                                                        <input type="email" name="new_email" class="form-control" value="<?php echo h($form['new_email']); ?>">
                                                    </div>
                                                    <div class="col-md-6">
                                                        <label class="form-label">GSTIN</label>
                                                        <input type="text" name="new_gstin" class="form-control" value="<?php echo h($form['new_gstin']); ?>">
                                                    </div>
                                                    <div class="col-md-6">
                                                        <label class="form-label">Address Line 1</label>
                                                        <input type="text" name="new_address_line1" class="form-control" value="<?php echo h($form['new_address_line1']); ?>">
                                                    </div>
                                                    <div class="col-md-6">
                                                        <label class="form-label">Address Line 2</label>
                                                        <input type="text" name="new_address_line2" class="form-control" value="<?php echo h($form['new_address_line2']); ?>">
                                                    </div>
                                                    <div class="col-md-3">
                                                        <label class="form-label">City</label>
                                                        <input type="text" name="new_city" class="form-control" value="<?php echo h($form['new_city']); ?>">
                                                    </div>
                                                    <div class="col-md-3">
                                                        <label class="form-label">District</label>
                                                        <input type="text" name="new_district" class="form-control" value="<?php echo h($form['new_district']); ?>">
                                                    </div>
                                                    <div class="col-md-3">
                                                        <label class="form-label">State</label>
                                                        <input type="text" name="new_state" class="form-control" value="<?php echo h($form['new_state']); ?>">
                                                    </div>
                                                    <div class="col-md-3">
                                                        <label class="form-label">Pincode</label>
                                                        <input type="text" name="new_pincode" class="form-control" value="<?php echo h($form['new_pincode']); ?>">
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="col-md-12">
                                            <label class="form-label">Customer Note</label>
                                            <textarea name="customer_note" class="form-control" rows="3"><?php echo h($form['customer_note']); ?></textarea>
                                        </div>

                                        <div class="col-md-12">
                                            <label class="form-label">Terms & Conditions</label>
                                            <textarea name="terms_conditions" class="form-control" rows="4"><?php echo h($form['terms_conditions']); ?></textarea>
                                        </div>

                                    </div>
                                </div>
                            </div>

                            <div class="card">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0">Quotation Items</h5>
                                    <button type="button" class="btn btn-primary btn-sm" onclick="addItemRow()">Add Item</button>
                                </div>
                                <div class="card-body">
                                    <div id="itemRows"></div>
                                </div>
                            </div>

                        </div>

                        <div class="col-xl-4">
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="mb-0">Quick Help</h5>
                                </div>
                                <div class="card-body small text-muted">
                                    <div class="mb-2"><strong>Stock Vehicle:</strong> selected from active records in vehicle_models.</div>
                                    <div class="mb-2"><strong>Manual Vehicle:</strong> enter vehicle details manually; it is saved in quotation_items.manual_vehicle_json.</div>
                                    <div class="mb-2"><strong>GST Inclusive:</strong> entered unit price is final amount including GST.</div>
                                    <div class="mb-0"><strong>GST Exclusive:</strong> GST is added above unit price.</div>
                                </div>
                            </div>

                            <div class="card">
                                <div class="card-body">
                                    <button type="submit" class="btn btn-success w-100">Save Quotation</button>
                                    <a href="quotations.php" class="btn btn-light w-100 mt-2">Cancel</a>
                                </div>
                            </div>
                        </div>

                    </div>
                </form>

            </div>
        </div>

        <?php include('includes/footer.php'); ?>
    </div>
</div>

<?php include('includes/rightbar.php'); ?>
<?php include('includes/scripts.php'); ?>

<script>
const productOptions = <?php echo json_encode($products, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const vehicleOptions = <?php echo json_encode($vehicles, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

let itemIndex = 0;

function escapeHtml(str) {
    return String(str ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function toggleCustomerMode() {
    const mode = document.getElementById('customer_mode').value;
    document.getElementById('existingCustomerWrap').style.display = mode === 'existing' ? '' : 'none';
    document.getElementById('newCustomerWrap').style.display = mode === 'new' ? '' : 'none';
}

function productOptionHtml(selectedId = '') {
    let html = '<option value="">Select Product</option>';
    productOptions.forEach(p => {
        const sel = String(selectedId) === String(p.id) ? 'selected' : '';
        const label = `${p.product_name || ''} (${p.product_code || p.item_code || ''})`;
        html += `<option value="${p.id}" data-price="${p.selling_price || 0}" data-gst="${p.gst_percent || 0}" data-gst-type="${p.gst_type || 'exclusive'}" ${sel}>${escapeHtml(label)}</option>`;
    });
    return html;
}

function vehicleOptionHtml(selectedId = '') {
    let html = '<option value="">Select Vehicle Model</option>';

    vehicleOptions.forEach(v => {
        const vehicleName = `${v.brand_name || 'Vehicle'} - ${v.model_name || '-'} ${v.variant_name || ''}`.trim();
        const details = [
            v.category_name ? `Category: ${v.category_name}` : '',
            v.vehicle_type ? `Type: ${v.vehicle_type}` : '',
            v.fuel_type ? `Fuel: ${v.fuel_type}` : '',
            v.color_options ? `Colors: ${v.color_options}` : '',
            `Price: ₹${parseFloat(v.sale_price || 0).toFixed(2)}`
        ].filter(Boolean).join(' | ');

        const label = `${vehicleName} (${details})`;
        const sel = String(selectedId) === String(v.id) ? 'selected' : '';

        html += `<option value="${v.id}"
            data-price="${v.sale_price || 0}"
            data-gst="${v.gst_percent || 0}"
            data-cess="${v.cess_percent || 0}"
            data-gst-type="${v.gst_type || 'inclusive'}"
            data-name="${escapeHtml(vehicleName)}"
            ${sel}>${escapeHtml(label)}</option>`;
    });

    if (vehicleOptions.length === 0) {
        html += '<option value="" disabled>No active vehicle models found</option>';
    }

    return html;
}

function addItemRow(data = {}) {
    const idx = itemIndex++;
    const itemType = 'vehicle';
    const vehicleMode = data.vehicle_mode || 'stock';
    const gstType = data.gst_type || 'inclusive';

    const row = document.createElement('div');
    row.className = 'item-row';
    row.innerHTML = `
        <div class="row g-3">
            <div class="col-md-2">
                <label class="form-label">Item Type</label>
                <select class="form-select item-type" disabled>
                    <option value="vehicle" selected>Vehicle</option>
                </select>
                <input type="hidden" name="items[${idx}][item_type]" value="vehicle">
            </div>

            <div class="col-md-2 vehicle-mode-col" style="display:none;">
                <label class="form-label">Vehicle Type</label>
                <select name="items[${idx}][vehicle_mode]" class="form-select vehicle-mode" onchange="toggleVehicleMode(this)">
                    <option value="stock" ${vehicleMode === 'stock' ? 'selected' : ''}>Stock Vehicle</option>
                    <option value="manual" ${vehicleMode === 'manual' ? 'selected' : ''}>Manual Vehicle</option>
                </select>
            </div>

            <div class="col-md-4 product-col" style="display:none;">
                <label class="form-label">Product</label>
                <select name="items[${idx}][product_id]" class="form-select product-select" onchange="fillProduct(this)">
                    ${productOptionHtml(data.product_id || '')}
                </select>
            </div>

            <div class="col-md-4 vehicle-col" style="display:none;">
                <label class="form-label">Nearby Stock Vehicle / Model</label>
                <select name="items[${idx}][vehicle_model_id]" class="form-select vehicle-select" onchange="fillVehicle(this)">
                    ${vehicleOptionHtml(data.vehicle_model_id || '')}
                </select>
            </div>

            <div class="col-md-12 manual-vehicle-col" style="display:none;">
                <div class="manual-vehicle-box">
                    <div class="section-title">Manual Vehicle Details</div>
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">Brand</label>
                            <input type="text" name="items[${idx}][manual_brand]" class="form-control" value="${escapeHtml(data.manual_brand || '')}">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Model</label>
                            <input type="text" name="items[${idx}][manual_model]" class="form-control" value="${escapeHtml(data.manual_model || '')}">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Variant</label>
                            <input type="text" name="items[${idx}][manual_variant]" class="form-control" value="${escapeHtml(data.manual_variant || '')}">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Color</label>
                            <input type="text" name="items[${idx}][manual_color]" class="form-control" value="${escapeHtml(data.manual_color || '')}">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Chassis No</label>
                            <input type="text" name="items[${idx}][manual_chassis_no]" class="form-control" value="${escapeHtml(data.manual_chassis_no || '')}">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Engine No</label>
                            <input type="text" name="items[${idx}][manual_engine_no]" class="form-control" value="${escapeHtml(data.manual_engine_no || '')}">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Motor No</label>
                            <input type="text" name="items[${idx}][manual_motor_no]" class="form-control" value="${escapeHtml(data.manual_motor_no || '')}">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Battery No</label>
                            <input type="text" name="items[${idx}][manual_battery_no]" class="form-control" value="${escapeHtml(data.manual_battery_no || '')}">
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <label class="form-label">Description</label>
                <input type="text" name="items[${idx}][description]" class="form-control" value="${escapeHtml(data.description || '')}">
            </div>

            <div class="col-md-2">
                <label class="form-label">Qty</label>
                <input type="number" step="0.01" min="0" name="items[${idx}][qty]" class="form-control" value="${escapeHtml(data.qty || '1')}">
            </div>

            <div class="col-md-2">
                <label class="form-label">Unit Price</label>
                <input type="number" step="0.01" min="0" name="items[${idx}][unit_price]" class="form-control" value="${escapeHtml(data.unit_price || '0')}">
            </div>

            <div class="col-md-2">
                <label class="form-label">Discount</label>
                <input type="number" step="0.01" min="0" name="items[${idx}][discount_amount]" class="form-control" value="${escapeHtml(data.discount_amount || '0')}">
            </div>

            <div class="col-md-2">
                <label class="form-label">GST Type</label>
                <select name="items[${idx}][gst_type]" class="form-select gst-type">
                    <option value="inclusive" ${gstType === 'inclusive' ? 'selected' : ''}>Inclusive</option>
                    <option value="exclusive" ${gstType === 'exclusive' ? 'selected' : ''}>Exclusive</option>
                </select>
            </div>

            <div class="col-md-2">
                <label class="form-label">CGST %</label>
                <input type="number" step="0.01" min="0" name="items[${idx}][cgst_percent]" class="form-control" value="${escapeHtml(data.cgst_percent || '0')}">
            </div>

            <div class="col-md-2">
                <label class="form-label">SGST %</label>
                <input type="number" step="0.01" min="0" name="items[${idx}][sgst_percent]" class="form-control" value="${escapeHtml(data.sgst_percent || '0')}">
            </div>

            <div class="col-md-2">
                <label class="form-label">IGST %</label>
                <input type="number" step="0.01" min="0" name="items[${idx}][igst_percent]" class="form-control" value="${escapeHtml(data.igst_percent || '0')}">
            </div>

            <div class="col-md-2">
                <label class="form-label">CESS %</label>
                <input type="number" step="0.01" min="0" name="items[${idx}][cess_percent]" class="form-control" value="${escapeHtml(data.cess_percent || '0')}">
            </div>

            <div class="col-md-2 d-flex align-items-end">
                <button type="button" class="btn btn-danger w-100" onclick="this.closest('.item-row').remove()">Remove</button>
            </div>
        </div>
    `;

    document.getElementById('itemRows').appendChild(row);
    toggleItemType(row.querySelector('.item-type'));
    toggleVehicleMode(row.querySelector('.vehicle-mode'));
}

function toggleItemType(selectEl) {
    const row = selectEl.closest('.item-row');
    const type = selectEl.value;

    row.querySelector('.product-col').style.display = type === 'product' ? '' : 'none';
    row.querySelector('.vehicle-mode-col').style.display = type === 'vehicle' ? '' : 'none';

    if (type === 'vehicle') {
        toggleVehicleMode(row.querySelector('.vehicle-mode'));
    } else {
        row.querySelector('.vehicle-col').style.display = 'none';
        row.querySelector('.manual-vehicle-col').style.display = 'none';
    }

    if (type === 'charge') {
        row.querySelector('input[name*="[description]"]').placeholder = 'Example: Registration, Insurance, Handling charge';
    } else {
        row.querySelector('input[name*="[description]"]').placeholder = '';
    }
}

function toggleVehicleMode(selectEl) {
    if (!selectEl) return;
    const row = selectEl.closest('.item-row');
    const itemType = row.querySelector('.item-type').value;
    const mode = selectEl.value;

    if (itemType !== 'vehicle') {
        row.querySelector('.vehicle-col').style.display = 'none';
        row.querySelector('.manual-vehicle-col').style.display = 'none';
        return;
    }

    row.querySelector('.vehicle-col').style.display = mode === 'stock' ? '' : 'none';
    row.querySelector('.manual-vehicle-col').style.display = mode === 'manual' ? '' : 'none';
}

function fillProduct(selectEl) {
    const opt = selectEl.options[selectEl.selectedIndex];
    const row = selectEl.closest('.item-row');
    if (!opt || !opt.value) return;

    row.querySelector('input[name*="[unit_price]"]').value = parseFloat(opt.getAttribute('data-price') || '0').toFixed(2);

    const gst = parseFloat(opt.getAttribute('data-gst') || '0');
    row.querySelector('select[name*="[gst_type]"]').value = opt.getAttribute('data-gst-type') || 'exclusive';
    row.querySelector('input[name*="[cgst_percent]"]').value = (gst / 2).toFixed(2);
    row.querySelector('input[name*="[sgst_percent]"]').value = (gst / 2).toFixed(2);
    row.querySelector('input[name*="[igst_percent]"]').value = '0.00';
    row.querySelector('input[name*="[cess_percent]"]').value = parseFloat(opt.getAttribute('data-cess') || '0').toFixed(2);
}

function fillVehicle(selectEl) {
    const opt = selectEl.options[selectEl.selectedIndex];
    const row = selectEl.closest('.item-row');
    if (!opt || !opt.value) return;

    row.querySelector('input[name*="[unit_price]"]').value = parseFloat(opt.getAttribute('data-price') || '0').toFixed(2);

    const name = opt.getAttribute('data-name') || '';
    row.querySelector('input[name*="[description]"]').value = name;

    const gst = parseFloat(opt.getAttribute('data-gst') || '0');
    row.querySelector('select[name*="[gst_type]"]').value = opt.getAttribute('data-gst-type') || 'exclusive';
    row.querySelector('input[name*="[cgst_percent]"]').value = (gst / 2).toFixed(2);
    row.querySelector('input[name*="[sgst_percent]"]').value = (gst / 2).toFixed(2);
    row.querySelector('input[name*="[igst_percent]"]').value = '0.00';
}

document.getElementById('branch_id')?.addEventListener('change', function () {
    document.querySelectorAll('.vehicle-select').forEach(select => {
        const selected = select.value;
        select.innerHTML = vehicleOptionHtml(selected);
    });
});

toggleCustomerMode();

<?php if (!empty($form['items']) && is_array($form['items'])): ?>
<?php foreach ($form['items'] as $item): ?>
addItemRow(<?php echo json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>);
<?php endforeach; ?>
<?php else: ?>
addItemRow();
<?php endif; ?>
</script>

</body>
</html>