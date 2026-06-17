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

function columnExists(mysqli $conn, string $table, string $column): bool
{
    $sql = "SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
            LIMIT 1";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $res = $stmt->get_result();
    $exists = $res && $res->num_rows > 0;
    $stmt->close();

    return $exists;
}

function getCount(mysqli $conn, string $table, string $where = '1=1'): int
{
    $sql = "SELECT COUNT(*) AS total FROM {$table} WHERE {$where}";
    $res = $conn->query($sql);

    if (!$res) {
        return 0;
    }

    $row = $res->fetch_assoc();
    $res->free();

    return (int)($row['total'] ?? 0);
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
                            WHERE bu.id = ?
                              AND bu.business_id = ?
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
    header('Location: login.php');
    exit;
}

/* -------------------------------------------------------
   TABLE CHECKS
------------------------------------------------------- */
if (!tableExists($conn, 'vehicle_models')) {
    die('vehicle_models table not found.');
}

if (!tableExists($conn, 'vehicle_brands')) {
    die('vehicle_brands table not found.');
}

if (!tableExists($conn, 'vehicle_categories')) {
    die('vehicle_categories table not found.');
}

/* -------------------------------------------------------
   AUTO ADD DEALER COST COLUMN
------------------------------------------------------- */
if (!columnExists($conn, 'vehicle_models', 'dealer_cost')) {
    $conn->query("ALTER TABLE vehicle_models
                  ADD COLUMN dealer_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00
                  AFTER ex_showroom_price");
}

/* -------------------------------------------------------
   MASTER DATA
------------------------------------------------------- */
$brands = [];
$brandMap = [];

$res = $conn->query("SELECT id, brand_name, status 
                     FROM vehicle_brands 
                     WHERE status = 1 
                     ORDER BY brand_name ASC");

if ($res) {
    while ($row = $res->fetch_assoc()) {
        $brands[] = $row;
        $brandMap[strtolower($row['brand_name'])] = (int)$row['id'];
    }
    $res->free();
}

$categories = [];
$categoryMap = [];

$res = $conn->query("SELECT id, category_name 
                     FROM vehicle_categories 
                     ORDER BY category_name ASC");

if ($res) {
    while ($row = $res->fetch_assoc()) {
        $categories[] = $row;
        $categoryMap[strtolower($row['category_name'])] = (int)$row['id'];
    }
    $res->free();
}

/* -------------------------------------------------------
   BULK IMPORT PROCESSING
------------------------------------------------------- */
$bulkResults = null;
$importSuccess = '';
$importError = '';
$showBulkModal = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bulk_import') {
    $importSuccessCount = 0;
    $importErrors = [];
    $importSkipped = 0;
    $createdBrands = [];
    $createdCategories = [];

    $cleanCsvValue = function ($value): string {
        $value = (string)($value ?? '');
        $value = preg_replace('/^\xEF\xBB\xBF/', '', $value);
        return trim($value);
    };

    $normalizeKey = function ($value): string {
        $value = strtolower(trim((string)($value ?? '')));
        $value = preg_replace('/^\xEF\xBB\xBF/', '', $value);
        return preg_replace('/[^a-z0-9]/', '', $value);
    };

    $parseAmount = function ($value): float {
        $value = trim((string)($value ?? ''));
        if ($value === '') {
            return 0.00;
        }

        $value = str_replace(',', '', $value);
        $value = preg_replace('/[^0-9.\-]/', '', $value);

        if ($value === '' || $value === '-' || $value === '.') {
            return 0.00;
        }

        return max(0, (float)$value);
    };

    $getRowValue = function (array $row, array $columnMap, string $field) use ($cleanCsvValue): string {
        if (!isset($columnMap[$field])) {
            return '';
        }

        $idx = (int)$columnMap[$field];
        return isset($row[$idx]) ? $cleanCsvValue($row[$idx]) : '';
    };

    $getOrCreateBrandId = function (mysqli $conn, string $brandName) use (&$brandMap, &$brands, &$createdBrands): int {
        $key = strtolower(trim($brandName));

        if ($key === '') {
            return 0;
        }

        if (isset($brandMap[$key])) {
            return (int)$brandMap[$key];
        }

        $stmt = $conn->prepare("INSERT INTO vehicle_brands (brand_name, status, created_at) VALUES (?, 1, NOW())");
        if (!$stmt) {
            return 0;
        }

        $stmt->bind_param('s', $brandName);
        if (!$stmt->execute()) {
            $stmt->close();
            return 0;
        }

        $brandId = (int)$stmt->insert_id;
        $stmt->close();

        $brandMap[$key] = $brandId;
        $brands[] = [
            'id' => $brandId,
            'brand_name' => $brandName,
            'status' => 1
        ];
        $createdBrands[] = $brandName;

        return $brandId;
    };

    $getOrCreateCategoryId = function (mysqli $conn, string $categoryName) use (&$categoryMap, &$categories, &$createdCategories): int {
        $categoryName = trim($categoryName);
        if ($categoryName === '') {
            $categoryName = 'General';
        }

        $key = strtolower($categoryName);

        if (isset($categoryMap[$key])) {
            return (int)$categoryMap[$key];
        }

        $stmt = $conn->prepare("INSERT INTO vehicle_categories (category_name) VALUES (?)");
        if (!$stmt) {
            return (int)($categories[0]['id'] ?? 0);
        }

        $stmt->bind_param('s', $categoryName);
        if (!$stmt->execute()) {
            $stmt->close();
            return (int)($categories[0]['id'] ?? 0);
        }

        $categoryId = (int)$stmt->insert_id;
        $stmt->close();

        $categoryMap[$key] = $categoryId;
        $categories[] = [
            'id' => $categoryId,
            'category_name' => $categoryName
        ];
        $createdCategories[] = $categoryName;

        return $categoryId;
    };

    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $importError = 'Please select a valid CSV file.';
        $showBulkModal = true;
    } else {
        $file = $_FILES['csv_file'];
        $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if ($fileExt !== 'csv') {
            $importError = 'Only CSV files are allowed.';
            $showBulkModal = true;
        } elseif ((int)$file['size'] > 10 * 1024 * 1024) {
            $importError = 'File size exceeds 10MB limit.';
            $showBulkModal = true;
        } else {
            $handle = fopen($file['tmp_name'], 'r');

            if ($handle === false) {
                $importError = 'Unable to read uploaded CSV file.';
                $showBulkModal = true;
            } else {
                $firstLine = fgets($handle);
                rewind($handle);

                $delimiters = [',' => substr_count((string)$firstLine, ','), ';' => substr_count((string)$firstLine, ';'), "\t" => substr_count((string)$firstLine, "\t"), '|' => substr_count((string)$firstLine, '|')];
                arsort($delimiters);
                reset($delimiters);
                $delimiter = key($delimiters);
                if ($delimiter === null || ($delimiters[$delimiter] ?? 0) <= 0) {
                    $delimiter = ',';
                }

                $headers = fgetcsv($handle, 0, $delimiter);

                if ($headers === false || count($headers) === 0) {
                    $importError = 'CSV file is empty.';
                    $showBulkModal = true;
                } else {
                    $headers = array_map($cleanCsvValue, $headers);
                    $normalizedHeaders = array_map($normalizeKey, $headers);

                    $headerPatterns = [
                        'brand' => ['brand', 'brandname', 'make', 'company'],
                        'model_name' => ['modelname', 'model', 'vehiclename', 'vehiclemodel'],
                        'variant' => ['variantname', 'variant'],
                        'category' => ['categoryname', 'category', 'vehiclecategory', 'segment'],
                        'vehicle_type' => ['vehicletype', 'vehiclefueltype', 'modeltype'],
                        'fuel_type' => ['fueltype', 'fuel'],
                        'transmission' => ['transmission', 'gear'],
                        'engine_cc' => ['enginecc', 'cc', 'engine'],
                        'mileage' => ['mileage', 'mileagekmpl'],
                        'battery_capacity' => ['batterycapacity', 'battery'],
                        'motor_power' => ['motorpower', 'motor'],
                        'range_km' => ['rangekm', 'range'],
                        'charging_time' => ['chargingtime', 'chargehours'],
                        'battery_brand' => ['batterybrand'],
                        'charger_brand' => ['chargerbrand'],
                        'charger_type' => ['chargertype'],
                        'color_options' => ['coloroptions', 'colors', 'colour', 'colouroptions'],
                        'ex_showroom_price' => ['exshowroomprice', 'exshowroom', 'exshowroomcost', 'price'],
                        'dealer_cost' => ['dealercost', 'dealerprice', 'dealerrate', 'cost'],
                        'insurance_price' => ['insuranceprice', 'insurance'],
                        'registration_price' => ['registrationprice', 'registration'],
                        'rto_charge' => ['rtocharge', 'rto'],
                        'road_tax' => ['roadtax'],
                        'hypothecation_charge' => ['hypothecationcharge', 'hypothecation'],
                        'other_charge' => ['othercharge', 'othercharges'],
                        'gst_percent' => ['gstpercent', 'gstpercentage', 'gst'],
                        'cess_percent' => ['cesspercent', 'cess'],
                        'hsn_code' => ['hsncode', 'hsn'],
                        'status' => ['status']
                    ];

                    $columnMap = [];
                    foreach ($headerPatterns as $field => $patterns) {
                        foreach ($normalizedHeaders as $idx => $headerKey) {
                            if ($headerKey === '') {
                                continue;
                            }

                            foreach ($patterns as $pattern) {
                                if ($headerKey === $pattern) {
                                    $columnMap[$field] = $idx;
                                    break 2;
                                }
                            }
                        }
                    }

                    if (!isset($columnMap['brand']) || !isset($columnMap['model_name'])) {
                        $importError = 'CSV must contain Brand and Model Name columns.';
                        $showBulkModal = true;
                    } else {
                        $conn->begin_transaction();

                        try {
                            $rowNumber = 1;

                            while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
                                $rowNumber++;

                                if (count(array_filter($row, function ($value) {
                                    return trim((string)$value) !== '';
                                })) === 0) {
                                    continue;
                                }

                                $brandName = $getRowValue($row, $columnMap, 'brand');
                                $modelName = $getRowValue($row, $columnMap, 'model_name');

                                if ($brandName === '' || $modelName === '') {
                                    $importErrors[] = "Row {$rowNumber}: Brand and Model Name are required.";
                                    $importSkipped++;
                                    continue;
                                }

                                $brandId = $getOrCreateBrandId($conn, $brandName);
                                if ($brandId <= 0) {
                                    $importErrors[] = "Row {$rowNumber}: Unable to create/find brand '{$brandName}'.";
                                    $importSkipped++;
                                    continue;
                                }

                                $categoryName = $getRowValue($row, $columnMap, 'category');
                                if ($categoryName === '') {
                                    $categoryName = (string)($categories[0]['category_name'] ?? 'General');
                                }

                                $categoryId = $getOrCreateCategoryId($conn, $categoryName);
                                if ($categoryId <= 0) {
                                    $importErrors[] = "Row {$rowNumber}: Unable to create/find category '{$categoryName}'.";
                                    $importSkipped++;
                                    continue;
                                }

                                $variantName = $getRowValue($row, $columnMap, 'variant');

                                $vehicleType = strtolower($getRowValue($row, $columnMap, 'vehicle_type'));
                                if ($vehicleType === '') {
                                    $fuelTypeGuess = strtolower($getRowValue($row, $columnMap, 'fuel_type'));
                                    $vehicleType = ($fuelTypeGuess === 'electric') ? 'electric' : 'fuel';
                                }
                                if (!in_array($vehicleType, ['fuel', 'electric'], true)) {
                                    $vehicleType = 'fuel';
                                }

                                $fuelType = strtolower($getRowValue($row, $columnMap, 'fuel_type'));
                                if ($fuelType === '' && $vehicleType === 'electric') {
                                    $fuelType = 'electric';
                                }
                                if (!in_array($fuelType, ['petrol', 'diesel', 'electric', 'hybrid'], true)) {
                                    $fuelType = null;
                                }

                                $transmission = strtolower($getRowValue($row, $columnMap, 'transmission'));
                                if (!in_array($transmission, ['manual', 'automatic'], true)) {
                                    $transmission = null;
                                }

                                $engineCc = $getRowValue($row, $columnMap, 'engine_cc');
                                $mileage = $getRowValue($row, $columnMap, 'mileage');
                                $batteryCapacity = $getRowValue($row, $columnMap, 'battery_capacity');
                                $motorPower = $getRowValue($row, $columnMap, 'motor_power');
                                $rangeKm = $getRowValue($row, $columnMap, 'range_km');
                                $chargingTime = $getRowValue($row, $columnMap, 'charging_time');
                                $batteryBrand = $getRowValue($row, $columnMap, 'battery_brand');
                                $chargerBrand = $getRowValue($row, $columnMap, 'charger_brand');
                                $chargerType = $getRowValue($row, $columnMap, 'charger_type');
                                $colorOptions = $getRowValue($row, $columnMap, 'color_options');
                                $hsnCode = $getRowValue($row, $columnMap, 'hsn_code');

                                $exShowroomPrice = $parseAmount($getRowValue($row, $columnMap, 'ex_showroom_price'));
                                $dealerCost = $parseAmount($getRowValue($row, $columnMap, 'dealer_cost'));
                                $insurancePrice = $parseAmount($getRowValue($row, $columnMap, 'insurance_price'));
                                $registrationPrice = $parseAmount($getRowValue($row, $columnMap, 'registration_price'));
                                $rtoCharge = $parseAmount($getRowValue($row, $columnMap, 'rto_charge'));
                                $roadTax = $parseAmount($getRowValue($row, $columnMap, 'road_tax'));
                                $hypothecationCharge = $parseAmount($getRowValue($row, $columnMap, 'hypothecation_charge'));
                                $otherCharge = $parseAmount($getRowValue($row, $columnMap, 'other_charge'));
                                $gstPercent = $parseAmount($getRowValue($row, $columnMap, 'gst_percent'));
                                $cessPercent = $parseAmount($getRowValue($row, $columnMap, 'cess_percent'));

                                $statusText = strtolower($getRowValue($row, $columnMap, 'status'));
                                $status = in_array($statusText, ['0', 'inactive', 'no', 'disabled'], true) ? 0 : 1;

                                $checkStmt = $conn->prepare("SELECT id
                                                             FROM vehicle_models
                                                             WHERE business_id = ?
                                                               AND brand_id = ?
                                                               AND category_id = ?
                                                               AND model_name = ?
                                                               AND COALESCE(variant_name, '') = ?
                                                             LIMIT 1");

                                if (!$checkStmt) {
                                    throw new Exception('Unable to prepare duplicate check: ' . $conn->error);
                                }

                                $checkStmt->bind_param('iiiss', $businessId, $brandId, $categoryId, $modelName, $variantName);
                                $checkStmt->execute();
                                $exists = $checkStmt->get_result()->fetch_assoc();
                                $checkStmt->close();

                                if ($exists) {
                                    $importSkipped++;
                                    continue;
                                }

                                $stmt = $conn->prepare("INSERT INTO vehicle_models (
                                    business_id,
                                    brand_id,
                                    category_id,
                                    model_name,
                                    variant_name,
                                    vehicle_type,
                                    fuel_type,
                                    transmission,
                                    engine_cc,
                                    mileage,
                                    battery_capacity,
                                    motor_power,
                                    range_km,
                                    charging_time,
                                    battery_brand,
                                    charger_brand,
                                    charger_type,
                                    color_options,
                                    ex_showroom_price,
                                    dealer_cost,
                                    insurance_price,
                                    registration_price,
                                    rto_charge,
                                    road_tax,
                                    hypothecation_charge,
                                    other_charge,
                                    gst_percent,
                                    cess_percent,
                                    hsn_code,
                                    status,
                                    created_at
                                ) VALUES (
                                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()
                                )");

                                if (!$stmt) {
                                    throw new Exception('Unable to prepare insert query: ' . $conn->error);
                                }

                                $stmt->bind_param(
                                    'iiisssssssssssssssddddddddddsi',
                                    $businessId,
                                    $brandId,
                                    $categoryId,
                                    $modelName,
                                    $variantName,
                                    $vehicleType,
                                    $fuelType,
                                    $transmission,
                                    $engineCc,
                                    $mileage,
                                    $batteryCapacity,
                                    $motorPower,
                                    $rangeKm,
                                    $chargingTime,
                                    $batteryBrand,
                                    $chargerBrand,
                                    $chargerType,
                                    $colorOptions,
                                    $exShowroomPrice,
                                    $dealerCost,
                                    $insurancePrice,
                                    $registrationPrice,
                                    $rtoCharge,
                                    $roadTax,
                                    $hypothecationCharge,
                                    $otherCharge,
                                    $gstPercent,
                                    $cessPercent,
                                    $hsnCode,
                                    $status
                                );

                                if ($stmt->execute()) {
                                    $importSuccessCount++;
                                } else {
                                    $importErrors[] = "Row {$rowNumber}: Failed to insert '{$modelName}' - " . $stmt->error;
                                    $importSkipped++;
                                }

                                $stmt->close();
                            }

                            if ($importSuccessCount <= 0 && $importSkipped <= 0) {
                                $importError = 'No valid rows found in CSV file.';
                                $showBulkModal = true;
                                $conn->rollback();
                            } else {
                                if (tableExists($conn, 'audit_logs')) {
                                    $branchIdForLog = isset($_SESSION['branch_id']) && (int)$_SESSION['branch_id'] > 0 ? (int)$_SESSION['branch_id'] : null;
                                    $description = "Bulk imported {$importSuccessCount} vehicle models";

                                    if (!empty($createdBrands)) {
                                        $description .= '. Created brands: ' . implode(', ', array_unique($createdBrands));
                                    }

                                    if (!empty($createdCategories)) {
                                        $description .= '. Created categories: ' . implode(', ', array_unique($createdCategories));
                                    }

                                    if ($importSkipped > 0) {
                                        $description .= " ({$importSkipped} skipped)";
                                    }

                                    $logStmt = $conn->prepare("INSERT INTO audit_logs
                                        (business_id, branch_id, user_id, action, module_name, ref_table, ref_id, description, ip_address, created_at)
                                        VALUES (?, ?, ?, 'Bulk Import', 'Vehicle Models', 'vehicle_models', NULL, ?, ?, NOW())");

                                    if ($logStmt) {
                                        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
                                        $logStmt->bind_param('iiiss', $businessId, $branchIdForLog, $businessUserId, $description, $ipAddress);
                                        $logStmt->execute();
                                        $logStmt->close();
                                    }
                                }

                                $conn->commit();

                                if ($importSuccessCount > 0) {
                                    $importSuccess = "Successfully imported {$importSuccessCount} vehicle models.";
                                } else {
                                    $importError = 'No new vehicle models imported.';
                                    $showBulkModal = true;
                                }

                                if (!empty($createdBrands)) {
                                    $importSuccess .= ' Created brands: ' . implode(', ', array_unique($createdBrands)) . '.';
                                }

                                if (!empty($createdCategories)) {
                                    $importSuccess .= ' Created categories: ' . implode(', ', array_unique($createdCategories)) . '.';
                                }

                                if ($importSkipped > 0) {
                                    $importSuccess .= " ({$importSkipped} skipped)";
                                }
                            }
                        } catch (Exception $e) {
                            $conn->rollback();
                            $importError = 'Import failed: ' . $e->getMessage();
                            $showBulkModal = true;
                        }
                    }
                }

                fclose($handle);
            }
        }
    }

    $bulkResults = [
        'success' => $importSuccessCount,
        'errors' => $importErrors,
        'skipped' => $importSkipped
    ];
}

/* -------------------------------------------------------
   GET MODEL FOR EDITING
------------------------------------------------------- */
$editModel = null;
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;

if ($editId > 0) {
    $stmt = $conn->prepare("SELECT * 
                            FROM vehicle_models 
                            WHERE id = ?
                              AND business_id = ?
                            LIMIT 1");

    if ($stmt) {
        $stmt->bind_param('ii', $editId, $businessId);
        $stmt->execute();
        $editModel = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}

/* -------------------------------------------------------
   FORM ACTIONS
------------------------------------------------------- */
$success = '';
$error = '';

$allowedVehicleTypes = ['fuel', 'electric'];
$allowedFuelTypes = ['petrol', 'diesel', 'electric', 'hybrid', ''];
$allowedTransmission = ['manual', 'automatic', ''];
$allowedStatus = [0, 1];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!isset($_POST['action']) || $_POST['action'] !== 'bulk_import')) {
    $action = trim($_POST['action'] ?? '');

    $id                    = (int)($_POST['id'] ?? 0);
    $brand_id              = (int)($_POST['brand_id'] ?? 0);
    $category_id           = (int)($_POST['category_id'] ?? 0);
    $model_name            = trim($_POST['model_name'] ?? '');
    $variant_name          = trim($_POST['variant_name'] ?? '');
    $vehicle_type          = trim($_POST['vehicle_type'] ?? 'fuel');
    $fuel_type             = trim($_POST['fuel_type'] ?? '');
    $transmission          = trim($_POST['transmission'] ?? '');
    $engine_cc             = trim($_POST['engine_cc'] ?? '');
    $mileage               = trim($_POST['mileage'] ?? '');
    $battery_capacity      = trim($_POST['battery_capacity'] ?? '');
    $motor_power           = trim($_POST['motor_power'] ?? '');
    $range_km              = trim($_POST['range_km'] ?? '');
    $charging_time         = trim($_POST['charging_time'] ?? '');
    $battery_brand         = trim($_POST['battery_brand'] ?? '');
    $charger_brand         = trim($_POST['charger_brand'] ?? '');
    $charger_type          = trim($_POST['charger_type'] ?? '');
    $color_options         = trim($_POST['color_options'] ?? '');
    $ex_showroom_price     = (float)($_POST['ex_showroom_price'] ?? 0);
    $dealer_cost           = (float)($_POST['dealer_cost'] ?? 0);
    $insurance_price       = (float)($_POST['insurance_price'] ?? 0);
    $registration_price    = (float)($_POST['registration_price'] ?? 0);
    $rto_charge            = (float)($_POST['rto_charge'] ?? 0);
    $road_tax              = (float)($_POST['road_tax'] ?? 0);
    $hypothecation_charge  = (float)($_POST['hypothecation_charge'] ?? 0);
    $other_charge          = (float)($_POST['other_charge'] ?? 0);
    $gst_percent           = (float)($_POST['gst_percent'] ?? 0);
    $cess_percent          = (float)($_POST['cess_percent'] ?? 0);
    $hsn_code              = trim($_POST['hsn_code'] ?? '');
    $status                = isset($_POST['status']) ? (int)$_POST['status'] : 1;

    if ($action === 'add' || $action === 'edit') {
        if ($brand_id <= 0) {
            $error = 'Please select brand.';
        } elseif ($category_id <= 0) {
            $error = 'Please select category.';
        } elseif ($model_name === '') {
            $error = 'Model name is required.';
        } elseif (!in_array($vehicle_type, $allowedVehicleTypes, true)) {
            $error = 'Invalid vehicle type.';
        } elseif (!in_array($fuel_type, $allowedFuelTypes, true)) {
            $error = 'Invalid fuel type.';
        } elseif (!in_array($transmission, $allowedTransmission, true)) {
            $error = 'Invalid transmission.';
        } elseif (!in_array($status, $allowedStatus, true)) {
            $error = 'Invalid status.';
        } elseif (
            $ex_showroom_price < 0 ||
            $dealer_cost < 0 ||
            $insurance_price < 0 ||
            $registration_price < 0 ||
            $rto_charge < 0 ||
            $road_tax < 0 ||
            $hypothecation_charge < 0 ||
            $other_charge < 0 ||
            $gst_percent < 0 ||
            $cess_percent < 0
        ) {
            $error = 'Amounts and tax cannot be negative.';
        } else {
            $stmt = $conn->prepare("SELECT id
                                    FROM vehicle_models
                                    WHERE business_id = ?
                                      AND brand_id = ?
                                      AND category_id = ?
                                      AND model_name = ?
                                      AND COALESCE(variant_name, '') = ?
                                      AND id != ?
                                    LIMIT 1");

            if ($stmt) {
                $stmt->bind_param(
                    'iiissi',
                    $businessId,
                    $brand_id,
                    $category_id,
                    $model_name,
                    $variant_name,
                    $id
                );

                $stmt->execute();
                $dup = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($dup) {
                    $error = 'This vehicle model and variant already exists.';
                }
            }
        }
    }

    if ($action === 'add' && $error === '') {
        $stmt = $conn->prepare("INSERT INTO vehicle_models (
            business_id,
            brand_id,
            category_id,
            model_name,
            variant_name,
            vehicle_type,
            fuel_type,
            transmission,
            engine_cc,
            mileage,
            battery_capacity,
            motor_power,
            range_km,
            charging_time,
            battery_brand,
            charger_brand,
            charger_type,
            color_options,
            ex_showroom_price,
            dealer_cost,
            insurance_price,
            registration_price,
            rto_charge,
            road_tax,
            hypothecation_charge,
            other_charge,
            gst_percent,
            cess_percent,
            hsn_code,
            status,
            created_at
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()
        )");

        if ($stmt) {
            $stmt->bind_param(
                'iiisssssssssssssssddddddddddsi',
                $businessId,
                $brand_id,
                $category_id,
                $model_name,
                $variant_name,
                $vehicle_type,
                $fuel_type,
                $transmission,
                $engine_cc,
                $mileage,
                $battery_capacity,
                $motor_power,
                $range_km,
                $charging_time,
                $battery_brand,
                $charger_brand,
                $charger_type,
                $color_options,
                $ex_showroom_price,
                $dealer_cost,
                $insurance_price,
                $registration_price,
                $rto_charge,
                $road_tax,
                $hypothecation_charge,
                $other_charge,
                $gst_percent,
                $cess_percent,
                $hsn_code,
                $status
            );

            if ($stmt->execute()) {
                $success = 'Vehicle model added successfully.';
                $editModel = null;
                $editId = 0;
            } else {
                $error = 'Failed to add vehicle model: ' . $conn->error;
            }

            $stmt->close();
        } else {
            $error = 'Unable to prepare insert query.';
        }
    }

    if ($action === 'edit' && $error === '') {
        if ($id <= 0) {
            $error = 'Invalid model id.';
        } else {
            $stmt = $conn->prepare("UPDATE vehicle_models SET
                brand_id = ?,
                category_id = ?,
                model_name = ?,
                variant_name = ?,
                vehicle_type = ?,
                fuel_type = ?,
                transmission = ?,
                engine_cc = ?,
                mileage = ?,
                battery_capacity = ?,
                motor_power = ?,
                range_km = ?,
                charging_time = ?,
                battery_brand = ?,
                charger_brand = ?,
                charger_type = ?,
                color_options = ?,
                ex_showroom_price = ?,
                dealer_cost = ?,
                insurance_price = ?,
                registration_price = ?,
                rto_charge = ?,
                road_tax = ?,
                hypothecation_charge = ?,
                other_charge = ?,
                gst_percent = ?,
                cess_percent = ?,
                hsn_code = ?,
                status = ?
                WHERE id = ?
                  AND business_id = ?
                LIMIT 1");

            if ($stmt) {
                $stmt->bind_param(
                    'iisssssssssssssssddddddddddsiii',
                    $brand_id,
                    $category_id,
                    $model_name,
                    $variant_name,
                    $vehicle_type,
                    $fuel_type,
                    $transmission,
                    $engine_cc,
                    $mileage,
                    $battery_capacity,
                    $motor_power,
                    $range_km,
                    $charging_time,
                    $battery_brand,
                    $charger_brand,
                    $charger_type,
                    $color_options,
                    $ex_showroom_price,
                    $dealer_cost,
                    $insurance_price,
                    $registration_price,
                    $rto_charge,
                    $road_tax,
                    $hypothecation_charge,
                    $other_charge,
                    $gst_percent,
                    $cess_percent,
                    $hsn_code,
                    $status,
                    $id,
                    $businessId
                );

                if ($stmt->execute()) {
                    $success = 'Vehicle model updated successfully.';
                    $editModel = null;
                    $editId = 0;
                } else {
                    $error = 'Failed to update vehicle model: ' . $conn->error;
                }

                $stmt->close();
            } else {
                $error = 'Unable to prepare update query.';
            }
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);

        if ($id <= 0) {
            $error = 'Invalid model id.';
        } else {
            $conn->begin_transaction();

            try {
                if (tableExists($conn, 'vehicle_stock')) {
                    $stmt = $conn->prepare("DELETE FROM vehicle_stock
                                            WHERE business_id = ?
                                              AND model_id = ?");

                    if ($stmt) {
                        $stmt->bind_param('ii', $businessId, $id);
                        $stmt->execute();
                        $stmt->close();
                    }
                }

                $stmt = $conn->prepare("DELETE FROM vehicle_models
                                        WHERE id = ?
                                          AND business_id = ?
                                        LIMIT 1");

                if (!$stmt) {
                    throw new Exception('Unable to prepare delete query: ' . $conn->error);
                }

                $stmt->bind_param('ii', $id, $businessId);
                $stmt->execute();

                if ($stmt->affected_rows > 0) {
                    $success = 'Vehicle model deleted successfully.';
                } else {
                    $error = 'Vehicle model not found or already deleted.';
                }

                $stmt->close();
                $conn->commit();
            } catch (Exception $e) {
                $conn->rollback();
                $error = 'Failed to delete vehicle model: ' . $e->getMessage();
            }
        }
    }
}

/* -------------------------------------------------------
   FILTERS
------------------------------------------------------- */
$search = trim($_GET['search'] ?? '');
$brandFilter = (int)($_GET['brand_id'] ?? 0);
$categoryFilter = (int)($_GET['category_id'] ?? 0);
$typeFilter = trim($_GET['vehicle_type'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');

$where = ["vm.business_id = {$businessId}"];

if ($search !== '') {
    $safe = $conn->real_escape_string($search);

    $where[] = "(
        vm.model_name LIKE '%{$safe}%'
        OR vm.variant_name LIKE '%{$safe}%'
        OR vb.brand_name LIKE '%{$safe}%'
        OR vc.category_name LIKE '%{$safe}%'
        OR vm.hsn_code LIKE '%{$safe}%'
    )";
}

if ($brandFilter > 0) {
    $where[] = "vm.brand_id = {$brandFilter}";
}

if ($categoryFilter > 0) {
    $where[] = "vm.category_id = {$categoryFilter}";
}

if ($typeFilter !== '' && in_array($typeFilter, $allowedVehicleTypes, true)) {
    $safe = $conn->real_escape_string($typeFilter);
    $where[] = "vm.vehicle_type = '{$safe}'";
}

if ($statusFilter !== '') {
    if ($statusFilter === 'active') {
        $where[] = "vm.status = 1";
    } elseif ($statusFilter === 'inactive') {
        $where[] = "vm.status = 0";
    }
}

$whereSql = implode(' AND ', $where);

/* -------------------------------------------------------
   SUMMARY
------------------------------------------------------- */
$totalModels = getCount($conn, 'vehicle_models', "business_id = {$businessId}");
$activeModels = getCount($conn, 'vehicle_models', "business_id = {$businessId} AND status = 1");
$fuelModels = getCount($conn, 'vehicle_models', "business_id = {$businessId} AND vehicle_type = 'fuel'");
$electricModels = getCount($conn, 'vehicle_models', "business_id = {$businessId} AND vehicle_type = 'electric'");

/* -------------------------------------------------------
   FETCH MODELS
------------------------------------------------------- */
$models = [];

$sql = "SELECT
            vm.*,
            vb.brand_name,
            vc.category_name
        FROM vehicle_models vm
        LEFT JOIN vehicle_brands vb ON vb.id = vm.brand_id
        LEFT JOIN vehicle_categories vc ON vc.id = vm.category_id
        WHERE {$whereSql}
        ORDER BY vm.id DESC";

$res = $conn->query($sql);

if ($res) {
    while ($row = $res->fetch_assoc()) {
        $models[] = $row;
    }

    $res->free();
}

$pageTitle = 'Vehicle Models';
$currentPage = 'vehicle-models';
?>
<!doctype html>
<html lang="en">

<?php include('includes/head.php'); ?>
<link href="https://cdn.jsdelivr.net/npm/remixicon@4.6.0/fonts/remixicon.css" rel="stylesheet">

<body data-sidebar="dark">

<style>
    .page-content {
        padding-bottom: 90px !important;
    }
    .card {
        margin-bottom: 24px;
    }
    .edit-form-section {
        background: #f8f9fa;
        border-left: 4px solid #0d6efd;
    }
    .model-detail {
        font-size: 13px;
        color: #6c757d;
    }
    .import-box {
        border: 2px dashed #ccc;
        border-radius: 8px;
        padding: 25px;
        text-align: center;
        background: #fafafa;
        cursor: pointer;
        transition: all 0.3s;
    }
    .import-box:hover {
        border-color: #3b82f6;
        background: #f0f7ff;
    }
    .import-box i {
        font-size: 40px;
        color: #6b7280;
        margin-bottom: 10px;
    }
    .sample-link {
        color: #3b82f6;
        text-decoration: underline;
        cursor: pointer;
    }
    .vehicle-model-table-wrap {
        width: 100%;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
    .vehicle-model-table {
        min-width: 1080px;
    }
    .vehicle-model-table th:last-child,
    .vehicle-model-table td:last-child {
        width: 190px !important;
        min-width: 190px !important;
        text-align: center;
        white-space: nowrap;
    }
    .action-buttons {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        flex-wrap: nowrap;
        min-width: 170px;
    }
    .action-buttons .btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 4px;
        min-width: 74px;
        height: 34px;
        padding: 6px 10px;
        font-size: 12px;
        font-weight: 600;
        line-height: 1;
        border-radius: 6px;
    }
    .action-buttons i {
        font-size: 15px;
        line-height: 1;
    }
    .action-buttons form {
        margin: 0;
        display: inline-flex;
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
                    <div class="col-md-6">
                        <h4 class="mb-1">Vehicle Models</h4>
                        <p class="text-muted mb-0">Manage vehicle models for your business</p>
                    </div>

                    <div class="col-md-6 text-md-end mt-3 mt-md-0">
                        <button type="button" class="btn btn-success me-2" data-bs-toggle="modal" data-bs-target="#bulkImportModal">
                            <i class="ri-upload-cloud-line me-1"></i>Bulk Import
                        </button>
                    </div>
                </div>

                <?php if ($success !== ''): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?php echo h($success); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($importSuccess !== ''): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="ri-check-line me-1"></i><?php echo h($importSuccess); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?php echo h($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="row">
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Total Models</p>
                                <h3 class="mb-0"><?php echo number_format($totalModels); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Active Models</p>
                                <h3 class="mb-0 text-success"><?php echo number_format($activeModels); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Fuel Models</p>
                                <h3 class="mb-0 text-primary"><?php echo number_format($fuelModels); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Electric Models</p>
                                <h3 class="mb-0 text-info"><?php echo number_format($electricModels); ?></h3>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card <?php echo $editModel ? 'edit-form-section' : ''; ?>">
                    <div class="card-header bg-light">
                        <h4 class="card-title mb-0">
                            <?php if ($editModel): ?>
                                <i class="ri-edit-line me-2"></i>Edit Vehicle Model
                            <?php else: ?>
                                <i class="ri-add-line me-2"></i>Add New Vehicle Model
                            <?php endif; ?>
                        </h4>
                    </div>

                    <div class="card-body">
                        <?php if ($editModel): ?>
                            <div class="alert alert-info mb-3">
                                <i class="ri-information-line me-2"></i>
                                Editing model: <strong><?php echo h($editModel['model_name']); ?></strong>

                                <?php if (!empty($editModel['variant_name'])): ?>
                                    - <?php echo h($editModel['variant_name']); ?>
                                <?php endif; ?>

                                <a href="vehicle-models.php" class="float-end">Cancel Edit</a>
                            </div>
                        <?php endif; ?>

                        <form method="post">
                            <input type="hidden" name="action" value="<?php echo $editModel ? 'edit' : 'add'; ?>">

                            <?php if ($editModel): ?>
                                <input type="hidden" name="id" value="<?php echo (int)$editModel['id']; ?>">
                            <?php endif; ?>

                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Brand <span class="text-danger">*</span></label>
                                    <select name="brand_id" class="form-select" required>
                                        <option value="">Select Brand</option>

                                        <?php foreach ($brands as $b): ?>
                                            <option value="<?php echo (int)$b['id']; ?>"
                                                <?php echo ($editModel && (int)$editModel['brand_id'] === (int)$b['id']) ? 'selected' : ''; ?>>
                                                <?php echo h($b['brand_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Category <span class="text-danger">*</span></label>
                                    <select name="category_id" class="form-select" required>
                                        <option value="">Select Category</option>

                                        <?php foreach ($categories as $c): ?>
                                            <option value="<?php echo (int)$c['id']; ?>"
                                                <?php echo ($editModel && (int)$editModel['category_id'] === (int)$c['id']) ? 'selected' : ''; ?>>
                                                <?php echo h($c['category_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Vehicle Type <span class="text-danger">*</span></label>
                                    <select name="vehicle_type" class="form-select" required>
                                        <option value="fuel" <?php echo ($editModel && $editModel['vehicle_type'] === 'fuel') ? 'selected' : ''; ?>>Fuel</option>
                                        <option value="electric" <?php echo ($editModel && $editModel['vehicle_type'] === 'electric') ? 'selected' : ''; ?>>Electric</option>
                                    </select>
                                </div>

                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Model Name <span class="text-danger">*</span></label>
                                    <input type="text"
                                           name="model_name"
                                           class="form-control"
                                           required
                                           value="<?php echo $editModel ? h($editModel['model_name']) : ''; ?>">
                                </div>

                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Variant Name</label>
                                    <input type="text"
                                           name="variant_name"
                                           class="form-control"
                                           value="<?php echo $editModel ? h($editModel['variant_name']) : ''; ?>">
                                </div>

                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Fuel Type</label>
                                    <select name="fuel_type" class="form-select">
                                        <option value="">Select</option>
                                        <option value="petrol" <?php echo ($editModel && $editModel['fuel_type'] === 'petrol') ? 'selected' : ''; ?>>Petrol</option>
                                        <option value="diesel" <?php echo ($editModel && $editModel['fuel_type'] === 'diesel') ? 'selected' : ''; ?>>Diesel</option>
                                        <option value="electric" <?php echo ($editModel && $editModel['fuel_type'] === 'electric') ? 'selected' : ''; ?>>Electric</option>
                                        <option value="hybrid" <?php echo ($editModel && $editModel['fuel_type'] === 'hybrid') ? 'selected' : ''; ?>>Hybrid</option>
                                    </select>
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Ex Showroom Price</label>
                                    <input type="number"
                                           step="0.01"
                                           min="0"
                                           name="ex_showroom_price"
                                           class="form-control"
                                           value="<?php echo $editModel ? h($editModel['ex_showroom_price']) : '0.00'; ?>">
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Dealer Cost</label>
                                    <input type="number"
                                           step="0.01"
                                           min="0"
                                           name="dealer_cost"
                                           class="form-control"
                                           value="<?php echo $editModel ? h($editModel['dealer_cost'] ?? '0.00') : '0.00'; ?>">
                                </div>

                                <div class="col-md-2 mb-3">
                                    <label class="form-label">GST %</label>
                                    <input type="number"
                                           step="0.01"
                                           min="0"
                                           name="gst_percent"
                                           class="form-control"
                                           value="<?php echo $editModel ? h($editModel['gst_percent']) : '28.00'; ?>">
                                </div>

                                <div class="col-md-2 mb-3">
                                    <label class="form-label">HSN Code</label>
                                    <input type="text"
                                           name="hsn_code"
                                           class="form-control"
                                           value="<?php echo $editModel ? h($editModel['hsn_code']) : ''; ?>">
                                </div>

                                <div class="col-md-2 mb-3">
                                    <label class="form-label">Status</label>
                                    <select name="status" class="form-select">
                                        <option value="1" <?php echo ($editModel && (int)$editModel['status'] === 1) ? 'selected' : ''; ?>>Active</option>
                                        <option value="0" <?php echo ($editModel && (int)$editModel['status'] === 0) ? 'selected' : ''; ?>>Inactive</option>
                                    </select>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-12">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="ri-save-line me-1"></i>
                                        <?php echo $editModel ? 'Update Vehicle Model' : 'Save Vehicle Model'; ?>
                                    </button>

                                    <?php if ($editModel): ?>
                                        <a href="vehicle-models.php" class="btn btn-secondary ms-2">
                                            <i class="ri-close-line me-1"></i> Cancel
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0">Filter Models</h5>
                    </div>

                    <div class="card-body">
                        <form method="get" class="row g-3">
                            <div class="col-md-3">
                                <input type="text"
                                       name="search"
                                       class="form-control"
                                       placeholder="Search model, brand..."
                                       value="<?php echo h($search); ?>">
                            </div>

                            <div class="col-md-2">
                                <select name="brand_id" class="form-select">
                                    <option value="0">All Brands</option>

                                    <?php foreach ($brands as $b): ?>
                                        <option value="<?php echo (int)$b['id']; ?>" <?php echo ($brandFilter === (int)$b['id']) ? 'selected' : ''; ?>>
                                            <?php echo h($b['brand_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-2">
                                <select name="category_id" class="form-select">
                                    <option value="0">All Categories</option>

                                    <?php foreach ($categories as $c): ?>
                                        <option value="<?php echo (int)$c['id']; ?>" <?php echo ($categoryFilter === (int)$c['id']) ? 'selected' : ''; ?>>
                                            <?php echo h($c['category_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-2">
                                <select name="vehicle_type" class="form-select">
                                    <option value="">All Types</option>
                                    <option value="fuel" <?php echo ($typeFilter === 'fuel') ? 'selected' : ''; ?>>Fuel</option>
                                    <option value="electric" <?php echo ($typeFilter === 'electric') ? 'selected' : ''; ?>>Electric</option>
                                </select>
                            </div>

                            <div class="col-md-2">
                                <select name="status" class="form-select">
                                    <option value="">All Status</option>
                                    <option value="active" <?php echo ($statusFilter === 'active') ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo ($statusFilter === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                            </div>

                            <div class="col-md-1">
                                <button type="submit" class="btn btn-primary w-100">Filter</button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header bg-light">
                        <h4 class="card-title mb-0">Vehicle Model List</h4>
                    </div>

                    <div class="card-body p-0">
                        <div class="table-responsive vehicle-model-table-wrap">
                            <table class="table table-bordered table-striped align-middle mb-0 vehicle-model-table">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 40px;">#</th>
                                        <th>Brand / Model</th>
                                        <th>Category</th>
                                        <th>Type</th>
                                        <th>Price</th>
                                        <th>Dealer Cost</th>
                                        <th>GST</th>
                                        <th>Status</th>
                                        <th style="width: 190px; min-width: 190px;">Actions</th>
                                    </tr>
                                </thead>

                                <tbody>
                                    <?php if (!empty($models)): ?>
                                        <?php $i = 1; foreach ($models as $row): ?>
                                            <tr>
                                                <td><?php echo $i++; ?></td>

                                                <td>
                                                    <strong><?php echo h($row['brand_name']); ?></strong><br>
                                                    <?php echo h($row['model_name']); ?>

                                                    <?php if (!empty($row['variant_name'])): ?>
                                                        <div class="small text-muted"><?php echo h($row['variant_name']); ?></div>
                                                    <?php endif; ?>
                                                </td>

                                                <td><?php echo h($row['category_name']); ?></td>

                                                <td>
                                                    <span class="badge bg-<?php echo $row['vehicle_type'] === 'electric' ? 'info' : 'primary'; ?>">
                                                        <?php echo h(ucfirst($row['vehicle_type'])); ?>
                                                    </span>
                                                </td>

                                                <td><?php echo money($row['ex_showroom_price']); ?></td>

                                                <td>
                                                    <strong><?php echo money($row['dealer_cost'] ?? 0); ?></strong>
                                                </td>

                                                <td><?php echo number_format((float)$row['gst_percent'], 2); ?>%</td>

                                                <td>
                                                    <?php if ((int)$row['status'] === 1): ?>
                                                        <span class="badge bg-success">Active</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-danger">Inactive</span>
                                                    <?php endif; ?>
                                                </td>

                                                <td>
                                                    <div class="action-buttons">
                                                        <a href="?edit=<?php echo (int)$row['id']; ?>"
                                                           class="btn btn-sm btn-primary"
                                                           title="Edit Vehicle Model">
                                                            <i class="ri-edit-line"></i><span>Edit</span>
                                                        </a>

                                                        <form method="post" onsubmit="return confirm('Delete this vehicle model permanently? This will also remove related vehicle stock records.');">
                                                            <input type="hidden" name="action" value="delete">
                                                            <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">

                                                            <button type="submit"
                                                                    class="btn btn-sm btn-danger"
                                                                    title="Delete Vehicle Model">
                                                                <i class="ri-delete-bin-line"></i><span>Delete</span>
                                                            </button>
                                                        </form>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="9" class="text-center text-muted py-5">
                                                <i class="ri-car-line font-size-48 mb-3 d-block"></i>
                                                <h5>No vehicle models found</h5>
                                                <p class="mb-0">Add your first vehicle model using the form above.</p>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <?php include('includes/footer.php'); ?>
    </div>
</div>

<!-- Bulk Import Modal remains same -->
<div class="modal fade" id="bulkImportModal" tabindex="-1" aria-labelledby="bulkImportModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="bulk_import">

                <div class="modal-header">
                    <h5 class="modal-title" id="bulkImportModalLabel">
                        <i class="ri-upload-cloud-line me-1"></i>Bulk Import Vehicle Models
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <?php if ($importError !== ''): ?>
                        <div class="alert alert-danger mb-3">
                            <i class="ri-error-warning-line me-1"></i><?php echo h($importError); ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($bulkResults && !empty($bulkResults['errors'])): ?>
                        <div class="alert alert-warning mb-3">
                            <strong>Import notes:</strong>
                            <ul class="mb-0 mt-2">
                                <?php foreach (array_slice($bulkResults['errors'], 0, 10) as $importRowError): ?>
                                    <li><?php echo h($importRowError); ?></li>
                                <?php endforeach; ?>

                                <?php if (count($bulkResults['errors']) > 10): ?>
                                    <li><?php echo (int)(count($bulkResults['errors']) - 10); ?> more errors...</li>
                                <?php endif; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <div class="mb-3">
                        <label class="form-label">Select CSV File <span class="text-danger">*</span></label>
                        <input type="file" name="csv_file" class="form-control" accept=".csv,text/csv" required>
                        <div class="form-text">Only CSV files are allowed. Maximum file size: 10MB.</div>
                    </div>

                    <div class="alert alert-info mb-0">
                        <h6 class="mb-2"><i class="ri-information-line me-1"></i>CSV Format</h6>
                        <p class="mb-2">Required columns: <strong>Brand</strong>, <strong>Model Name</strong></p>
                        <p class="mb-2">Optional columns: Variant, Category, Vehicle Type, Fuel Type, Transmission, Engine CC, Mileage, Battery Capacity, Motor Power, Range KM, Charging Time, Battery Brand, Charger Brand, Charger Type, Colors, Ex Showroom Price, Dealer Cost, Insurance Price, Registration Price, RTO Charge, Road Tax, Hypothecation Charge, Other Charge, GST, CESS, HSN Code, Status.</p>
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered mb-0 bg-white">
                                <thead>
                                    <tr>
                                        <th>Brand</th>
                                        <th>Model Name</th>
                                        <th>Variant</th>
                                        <th>Category</th>
                                        <th>Vehicle Type</th>
                                        <th>Ex Showroom Price</th>
                                        <th>Dealer Cost</th>
                                        <th>GST</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>General</td>
                                        <td>Model X</td>
                                        <td>STD</td>
                                        <td>ECO</td>
                                        <td>electric</td>
                                        <td>85000</td>
                                        <td>76000</td>
                                        <td>5</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="ri-upload-cloud-line me-1"></i>Import Now
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include('includes/rightbar.php'); ?>
<?php include('includes/scripts.php'); ?>

<script>
<?php if ($showBulkModal): ?>
document.addEventListener('DOMContentLoaded', function() {
    var bulkModalEl = document.getElementById('bulkImportModal');
    if (bulkModalEl && typeof bootstrap !== 'undefined') {
        var bulkModal = new bootstrap.Modal(bulkModalEl);
        bulkModal.show();
    }
});
<?php endif; ?>

setTimeout(function() {
    var alerts = document.querySelectorAll('.alert');

    alerts.forEach(function(alert) {
        if (alert.closest('.modal')) {
            return;
        }

        try {
            var bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        } catch (e) {}
    });
}, 5000);
</script>

</body>
</html>