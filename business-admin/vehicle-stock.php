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
$currentBranchId = isset($_SESSION['branch_id']) ? (int)$_SESSION['branch_id'] : 0;

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
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
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

function getCount(mysqli $conn, string $table, string $where = '1=1'): int
{
    $sql = "SELECT COUNT(*) AS total FROM {$table} WHERE {$where}";
    $res = $conn->query($sql);
    if (!$res) {
        return 0;
    }
    $row = $res->fetch_assoc();
    return (int)($row['total'] ?? 0);
}

function fetchAllAssoc(mysqli $conn, string $sql): array
{
    $data = [];
    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $data[] = $row;
        }
    }
    return $data;
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
    header('Location: login.php');
    exit;
}

/* -------------------------------------------------------
   TABLE CHECKS
------------------------------------------------------- */
$requiredTables = ['vehicle_stock', 'vehicle_models', 'branches'];
foreach ($requiredTables as $tbl) {
    if (!tableExists($conn, $tbl)) {
        die($tbl . ' table not found.');
    }
}

/* -------------------------------------------------------
   MASTER DATA
------------------------------------------------------- */
$branches = fetchAllAssoc($conn, "SELECT id, branch_name, branch_code
                                  FROM branches
                                  WHERE business_id = {$businessId}
                                  ORDER BY branch_name ASC");

$branchMap = [];
foreach ($branches as $b) {
    $branchMap[strtolower($b['branch_name'])] = $b['id'];
    $branchMap[strtolower($b['branch_code'])] = $b['id'];
}

$models = fetchAllAssoc($conn, "SELECT 
                                    vm.id,
                                    vm.model_name,
                                    vm.variant_name,
                                    vb.brand_name,
                                    vm.ex_showroom_price,
                                    vm.gst_percent
                                FROM vehicle_models vm
                                LEFT JOIN vehicle_brands vb ON vb.id = vm.brand_id
                                WHERE vm.business_id = {$businessId}
                                ORDER BY vb.brand_name ASC, vm.model_name ASC");

$modelMap = [];
$modelPrices = [];
foreach ($models as $m) {
    $key = strtolower(($m['brand_name'] ?? '') . ' ' . ($m['model_name'] ?? ''));
    $modelMap[$key] = $m['id'];
    $modelMap[strtolower($m['model_name'] ?? '')] = $m['id'];
    $modelPrices[$m['id']] = [
        'ex_showroom_price' => $m['ex_showroom_price'] ?? 0,
        'gst_percent' => $m['gst_percent'] ?? 0
    ];
}

$batteryBrands = [];
if (tableExists($conn, 'battery_brands')) {
    $batteryBrands = fetchAllAssoc($conn, "SELECT id, brand_name FROM battery_brands WHERE status = 1 ORDER BY brand_name ASC");
}

$chargerBrands = [];
if (tableExists($conn, 'charger_brands')) {
    $chargerBrands = fetchAllAssoc($conn, "SELECT id, brand_name FROM charger_brands WHERE status = 1 ORDER BY brand_name ASC");
}

$batteryBrandMap = [];
foreach ($batteryBrands as $b) {
    $batteryBrandMap[strtolower($b['brand_name'])] = $b['brand_name'];
}

$chargerBrandMap = [];
foreach ($chargerBrands as $c) {
    $chargerBrandMap[strtolower($c['brand_name'])] = $c['brand_name'];
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
    
    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $importError = 'Please select a valid CSV file.';
        $showBulkModal = true;
    } else {
        $file = $_FILES['csv_file'];
        $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if ($fileExt !== 'csv') {
            $importError = 'Only CSV files are allowed.';
            $showBulkModal = true;
        } elseif ($file['size'] > 10 * 1024 * 1024) {
            $importError = 'File size exceeds 10MB limit.';
            $showBulkModal = true;
        } else {
            $handle = fopen($file['tmp_name'], 'r');
            
            if ($handle !== false) {
                $firstLine = fgets($handle);
                rewind($handle);
                $delimiter = (substr_count($firstLine, ',') > substr_count($firstLine, ';')) ? ',' : ';';
                
                $headers = fgetcsv($handle, 0, $delimiter);
                
                if ($headers === false) {
                    $importError = 'CSV file is empty.';
                    $showBulkModal = true;
                } else {
                    $headers = array_map(function($h) {
                        return strtolower(trim($h));
                    }, $headers);
                    
                    $columnMap = [];
                    $patterns = [
                        'branch' => ['branch', 'branch name', 'location'],
                        'model' => ['model', 'model name', 'vehicle model', 'vehicle'],
                        'chassis_no' => ['chassis', 'chassis no', 'chassis number', 'chassis_no'],
                        'engine_no' => ['engine', 'engine no', 'engine number', 'engine_no'],
                        'motor_no' => ['motor', 'motor no', 'motor number', 'motor_no'],
                        'color' => ['color', 'colour'],
                        'vin_no' => ['vin', 'vin no', 'vin number'],
                        'battery_brand' => ['battery brand', 'battery_brand'],
                        'battery_no' => ['battery no', 'battery number', 'battery_no'],
                        'battery_capacity' => ['battery capacity', 'battery_capacity'],
                        'charger_brand' => ['charger brand', 'charger_brand'],
                        'charger_no' => ['charger no', 'charger number', 'charger_no'],
                        'charger_type' => ['charger type', 'charger_type'],
                        'key_no' => ['key no', 'key number', 'key_no'],
                        'manufacture_year' => ['manufacture year', 'year', 'manufacture_year'],
                        'purchase_date' => ['purchase date', 'purchase_date'],
                        'supplier_name' => ['supplier', 'supplier name', 'supplier_name'],
                        'purchase_cost' => ['purchase cost', 'purchase_cost', 'cost'],
                        'sale_price' => ['sale price', 'sale_price', 'price', 'total price', 'total'],
                        'stock_status' => ['status', 'stock status', 'stock_status']
                    ];
                    
                    foreach ($patterns as $field => $patternList) {
                        foreach ($headers as $idx => $header) {
                            $cleanHeader = preg_replace('/[^a-z]/', '', $header);
                            foreach ($patternList as $pattern) {
                                if (strpos($cleanHeader, $pattern) !== false) {
                                    $columnMap[$field] = $idx;
                                    break 2;
                                }
                            }
                        }
                    }
                    
                    // If no chassis column found, try first column
                    if (!isset($columnMap['chassis_no']) && count($headers) > 0) {
                        $columnMap['chassis_no'] = 0;
                    }
                    
                    // If no model column found, try second column
                    if (!isset($columnMap['model']) && count($headers) > 1) {
                        $columnMap['model'] = 1;
                    }
                    
                    $conn->begin_transaction();
                    
                    try {
                        $rowNumber = 1;
                        $usedBatteryNos = []; // Track battery numbers used in this import
                        $usedChargerNos = []; // Track charger numbers used in this import
                        $usedEngineNos = []; // Track engine numbers used in this import
                        
                        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
                            $rowNumber++;
                            
                            // Skip empty rows
                            $hasData = false;
                            foreach ($row as $cell) {
                                if (trim($cell) !== '') {
                                    $hasData = true;
                                    break;
                                }
                            }
                            if (!$hasData) {
                                continue;
                            }
                            
                            $chassisNo = isset($columnMap['chassis_no']) && isset($row[$columnMap['chassis_no']]) ? trim($row[$columnMap['chassis_no']]) : '';
                            
                            if ($chassisNo === '') {
                                $importErrors[] = "Row {$rowNumber}: Chassis number is required.";
                                $importSkipped++;
                                continue;
                            }
                            
                            // Get branch ID
                            $branchId = $currentBranchId;
                            if (isset($columnMap['branch']) && isset($row[$columnMap['branch']])) {
                                $branchName = trim($row[$columnMap['branch']]);
                                if (!empty($branchName) && isset($branchMap[strtolower($branchName)])) {
                                    $branchId = $branchMap[strtolower($branchName)];
                                }
                            }
                            
                            // Get model ID
                            $modelId = null;
                            $modelName = '';
                            if (isset($columnMap['model']) && isset($row[$columnMap['model']])) {
                                $modelName = trim($row[$columnMap['model']]);
                                if (!empty($modelName)) {
                                    $modelKey = strtolower($modelName);
                                    if (isset($modelMap[$modelKey])) {
                                        $modelId = $modelMap[$modelKey];
                                    }
                                }
                            }
                            
                            if (!$modelId && !empty($models)) {
                                $modelId = $models[0]['id'];
                            }
                            
                            if (!$modelId) {
                                $importErrors[] = "Row {$rowNumber}: Could not find model '{$modelName}'";
                                $importSkipped++;
                                continue;
                            }
                            
                            // Check for duplicate chassis in database
                            $checkStmt = $conn->prepare("SELECT id FROM vehicle_stock WHERE business_id = ? AND chassis_no = ? LIMIT 1");
                            $checkStmt->bind_param('is', $businessId, $chassisNo);
                            $checkStmt->execute();
                            $exists = $checkStmt->get_result()->fetch_assoc();
                            $checkStmt->close();
                            
                            if ($exists) {
                                $importErrors[] = "Row {$rowNumber}: Chassis '{$chassisNo}' already exists in database.";
                                $importSkipped++;
                                continue;
                            }
                            
                            // Get other fields with defaults
                            $color = isset($columnMap['color']) && isset($row[$columnMap['color']]) ? trim($row[$columnMap['color']]) : '';
                            $vinNo = isset($columnMap['vin_no']) && isset($row[$columnMap['vin_no']]) ? trim($row[$columnMap['vin_no']]) : '';
                            
                            // Engine number - set to NULL if empty to avoid duplicate empty string issues
                            $engineNo = isset($columnMap['engine_no']) && isset($row[$columnMap['engine_no']]) ? trim($row[$columnMap['engine_no']]) : '';
                            if ($engineNo === '') {
                                $engineNo = null;
                            } else {
                                // Check for duplicate engine in database
                                $checkStmt = $conn->prepare("SELECT id FROM vehicle_stock WHERE business_id = ? AND engine_no = ? AND engine_no IS NOT NULL LIMIT 1");
                                $checkStmt->bind_param('is', $businessId, $engineNo);
                                $checkStmt->execute();
                                $exists = $checkStmt->get_result()->fetch_assoc();
                                $checkStmt->close();
                                
                                if ($exists) {
                                    $importErrors[] = "Row {$rowNumber}: Engine number '{$engineNo}' already exists in database.";
                                    $importSkipped++;
                                    continue;
                                }
                                
                                // Check for duplicate in current import batch
                                if (isset($usedEngineNos[$engineNo])) {
                                    $importErrors[] = "Row {$rowNumber}: Engine number '{$engineNo}' already used in this import (Row {$usedEngineNos[$engineNo]}).";
                                    $importSkipped++;
                                    continue;
                                }
                                $usedEngineNos[$engineNo] = $rowNumber;
                            }
                            
                            $motorNo = isset($columnMap['motor_no']) && isset($row[$columnMap['motor_no']]) ? trim($row[$columnMap['motor_no']]) : '';
                            
                            // Battery brand
                            $batteryBrandInput = isset($columnMap['battery_brand']) && isset($row[$columnMap['battery_brand']]) ? trim($row[$columnMap['battery_brand']]) : '';
                            $batteryBrand = $batteryBrandInput;
                            if (!empty($batteryBrandInput) && isset($batteryBrandMap[strtolower($batteryBrandInput)])) {
                                $batteryBrand = $batteryBrandMap[strtolower($batteryBrandInput)];
                            }
                            
                            // Battery number - set to NULL if empty to avoid duplicate empty string issues
                            $batteryNo = isset($columnMap['battery_no']) && isset($row[$columnMap['battery_no']]) ? trim($row[$columnMap['battery_no']]) : '';
                            if ($batteryNo === '') {
                                $batteryNo = null;
                            } else {
                                // Check for duplicate battery in database
                                $checkStmt = $conn->prepare("SELECT id FROM vehicle_stock WHERE business_id = ? AND battery_no = ? AND battery_no IS NOT NULL LIMIT 1");
                                $checkStmt->bind_param('is', $businessId, $batteryNo);
                                $checkStmt->execute();
                                $exists = $checkStmt->get_result()->fetch_assoc();
                                $checkStmt->close();
                                
                                if ($exists) {
                                    $importErrors[] = "Row {$rowNumber}: Battery number '{$batteryNo}' already exists in database.";
                                    $importSkipped++;
                                    continue;
                                }
                                
                                // Check for duplicate in current import batch
                                if (isset($usedBatteryNos[$batteryNo])) {
                                    $importErrors[] = "Row {$rowNumber}: Battery number '{$batteryNo}' already used in this import (Row {$usedBatteryNos[$batteryNo]}).";
                                    $importSkipped++;
                                    continue;
                                }
                                $usedBatteryNos[$batteryNo] = $rowNumber;
                            }
                            
                            $batteryCapacity = isset($columnMap['battery_capacity']) && isset($row[$columnMap['battery_capacity']]) ? trim($row[$columnMap['battery_capacity']]) : '';
                            
                            // Charger brand
                            $chargerBrandInput = isset($columnMap['charger_brand']) && isset($row[$columnMap['charger_brand']]) ? trim($row[$columnMap['charger_brand']]) : '';
                            $chargerBrand = $chargerBrandInput;
                            if (!empty($chargerBrandInput) && isset($chargerBrandMap[strtolower($chargerBrandInput)])) {
                                $chargerBrand = $chargerBrandMap[strtolower($chargerBrandInput)];
                            }
                            
                            // Charger number - set to NULL if empty to avoid duplicate empty string issues
                            $chargerNo = isset($columnMap['charger_no']) && isset($row[$columnMap['charger_no']]) ? trim($row[$columnMap['charger_no']]) : '';
                            if ($chargerNo === '') {
                                $chargerNo = null;
                            } else {
                                // Check for duplicate charger in database
                                $checkStmt = $conn->prepare("SELECT id FROM vehicle_stock WHERE business_id = ? AND charger_no = ? AND charger_no IS NOT NULL LIMIT 1");
                                $checkStmt->bind_param('is', $businessId, $chargerNo);
                                $checkStmt->execute();
                                $exists = $checkStmt->get_result()->fetch_assoc();
                                $checkStmt->close();
                                
                                if ($exists) {
                                    $importErrors[] = "Row {$rowNumber}: Charger number '{$chargerNo}' already exists in database.";
                                    $importSkipped++;
                                    continue;
                                }
                                
                                // Check for duplicate in current import batch
                                if (isset($usedChargerNos[$chargerNo])) {
                                    $importErrors[] = "Row {$rowNumber}: Charger number '{$chargerNo}' already used in this import (Row {$usedChargerNos[$chargerNo]}).";
                                    $importSkipped++;
                                    continue;
                                }
                                $usedChargerNos[$chargerNo] = $rowNumber;
                            }
                            
                            $chargerType = isset($columnMap['charger_type']) && isset($row[$columnMap['charger_type']]) ? trim($row[$columnMap['charger_type']]) : '';
                            $keyNo = isset($columnMap['key_no']) && isset($row[$columnMap['key_no']]) ? trim($row[$columnMap['key_no']]) : '';
                            $manufactureYear = isset($columnMap['manufacture_year']) && isset($row[$columnMap['manufacture_year']]) ? trim($row[$columnMap['manufacture_year']]) : '';
                            $purchaseDate = isset($columnMap['purchase_date']) && isset($row[$columnMap['purchase_date']]) ? trim($row[$columnMap['purchase_date']]) : '';
                            $supplierName = isset($columnMap['supplier_name']) && isset($row[$columnMap['supplier_name']]) ? trim($row[$columnMap['supplier_name']]) : '';
                            
                            // Clean number function
                            $cleanNumber = function($value) {
                                if (empty($value)) return 0;
                                $value = preg_replace('/[^0-9.-]/', '', $value);
                                return is_numeric($value) ? (float)$value : 0;
                            };
                            
                            $purchaseCost = isset($columnMap['purchase_cost']) && isset($row[$columnMap['purchase_cost']]) ? $cleanNumber($row[$columnMap['purchase_cost']]) : 0;
                            $salePrice = isset($columnMap['sale_price']) && isset($row[$columnMap['sale_price']]) ? $cleanNumber($row[$columnMap['sale_price']]) : 0;
                            
                            $stockStatus = isset($columnMap['stock_status']) && isset($row[$columnMap['stock_status']]) ? strtolower(trim($row[$columnMap['stock_status']])) : 'in_stock';
                            $allowedStatus = ['in_stock', 'reserved', 'sold', 'demo', 'transferred'];
                            if (!in_array($stockStatus, $allowedStatus)) {
                                $stockStatus = 'in_stock';
                            }
                            
                            // Use INSERT with NULL handling for unique fields
                            $stmt = $conn->prepare("INSERT INTO vehicle_stock (
                                business_id, branch_id, model_id, color, vin_no, chassis_no, engine_no, motor_no,
                                battery_brand, battery_no, battery_capacity,
                                charger_brand, charger_no, charger_type,
                                key_no, manufacture_year, purchase_date, supplier_name, purchase_cost,
                                sale_price, stock_status, created_at
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                            
                            if ($stmt) {
                                $stmt->bind_param(
                                    'iiisssssssssssssssdds',
                                    $businessId, $branchId, $modelId, $color, $vinNo, $chassisNo, $engineNo, $motorNo,
                                    $batteryBrand, $batteryNo, $batteryCapacity,
                                    $chargerBrand, $chargerNo, $chargerType,
                                    $keyNo, $manufactureYear, $purchaseDate, $supplierName, $purchaseCost,
                                    $salePrice, $stockStatus
                                );
                                
                                if ($stmt->execute()) {
                                    $importSuccessCount++;
                                } else {
                                    $importErrors[] = "Row {$rowNumber}: Failed to insert - " . $stmt->error;
                                    $importSkipped++;
                                }
                                $stmt->close();
                            } else {
                                $importErrors[] = "Row {$rowNumber}: Database error - " . $conn->error;
                                $importSkipped++;
                            }
                        }
                        
                        $conn->commit();
                        
                        if ($importSuccessCount > 0) {
                            $importSuccess = "Successfully imported {$importSuccessCount} vehicle stock items.";
                        }
                        if ($importSkipped > 0) {
                            $importSuccess .= " ({$importSkipped} skipped - check errors below)";
                        }
                        if (empty($importSuccess)) {
                            $importSuccess = "No items were imported. Please check errors below.";
                        }
                        
                    } catch (Exception $e) {
                        $conn->rollback();
                        $importError = 'Import failed: ' . $e->getMessage();
                        $showBulkModal = true;
                    }
                }
                fclose($handle);
            } else {
                $importError = 'Unable to read the CSV file.';
                $showBulkModal = true;
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
   ACTIONS (ADD/DELETE)
------------------------------------------------------- */
$success = '';
$error = '';

$allowedStockStatus = ['in_stock', 'reserved', 'sold', 'demo', 'transferred'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!isset($_POST['action']) || $_POST['action'] !== 'bulk_import')) {
    $action = trim($_POST['action'] ?? '');

    if ($action === 'add') {
        $branch_id             = (int)($_POST['branch_id'] ?? 0);
        $model_id              = (int)($_POST['model_id'] ?? 0);
        $color                 = trim($_POST['color'] ?? '');
        $vin_no                = trim($_POST['vin_no'] ?? '');
        $chassis_no            = trim($_POST['chassis_no'] ?? '');
        $engine_no             = trim($_POST['engine_no'] ?? '');
        $motor_no              = trim($_POST['motor_no'] ?? '');
        $battery_brand         = trim($_POST['battery_brand'] ?? '');
        $battery_no            = trim($_POST['battery_no'] ?? '');
        $battery_capacity      = trim($_POST['battery_capacity'] ?? '');
        $battery_warranty_upto = trim($_POST['battery_warranty_upto'] ?? '');
        $charger_brand         = trim($_POST['charger_brand'] ?? '');
        $charger_no            = trim($_POST['charger_no'] ?? '');
        $charger_type          = trim($_POST['charger_type'] ?? '');
        $charger_warranty_upto = trim($_POST['charger_warranty_upto'] ?? '');
        $key_no                = trim($_POST['key_no'] ?? '');
        $manufacture_year      = trim($_POST['manufacture_year'] ?? '');
        $purchase_date         = trim($_POST['purchase_date'] ?? '');
        $supplier_name         = trim($_POST['supplier_name'] ?? '');
        $purchase_cost         = (float)($_POST['purchase_cost'] ?? 0);
        $ex_showroom_price     = (float)($_POST['ex_showroom_price'] ?? 0);
        $rto_charge            = (float)($_POST['rto_charge'] ?? 0);
        $registration_price    = (float)($_POST['registration_price'] ?? 0);
        $road_tax              = (float)($_POST['road_tax'] ?? 0);
        $insurance_price       = (float)($_POST['insurance_price'] ?? 0);
        $sale_price            = (float)($_POST['sale_price'] ?? 0);
        $stock_status          = trim($_POST['stock_status'] ?? 'in_stock');
        $remarks               = trim($_POST['remarks'] ?? '');

        // Set empty unique fields to NULL
        $engine_no = ($engine_no === '') ? null : $engine_no;
        $battery_no = ($battery_no === '') ? null : $battery_no;
        $charger_no = ($charger_no === '') ? null : $charger_no;

        if ($branch_id <= 0) {
            $error = 'Please select branch.';
        } elseif ($model_id <= 0) {
            $error = 'Please select vehicle model.';
        } elseif ($chassis_no === '') {
            $error = 'Chassis number is required.';
        } elseif (!in_array($stock_status, $allowedStockStatus, true)) {
            $error = 'Invalid stock status.';
        } elseif ($purchase_cost < 0 || $sale_price < 0) {
            $error = 'Price values cannot be negative.';
        } else {
            // Check for duplicate chassis
            $stmt = $conn->prepare("SELECT id FROM vehicle_stock WHERE business_id = ? AND chassis_no = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('is', $businessId, $chassis_no);
                $stmt->execute();
                $dup = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($dup) {
                    $error = 'Chassis number already exists.';
                }
            }

            // Check for duplicate engine (if provided)
            if ($error === '' && $engine_no !== null) {
                $stmt = $conn->prepare("SELECT id FROM vehicle_stock WHERE business_id = ? AND engine_no = ? AND engine_no IS NOT NULL LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param('is', $businessId, $engine_no);
                    $stmt->execute();
                    $dup = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    if ($dup) {
                        $error = 'Engine number already exists.';
                    }
                }
            }

            // Check for duplicate battery (if provided)
            if ($error === '' && $battery_no !== null) {
                $stmt = $conn->prepare("SELECT id FROM vehicle_stock WHERE business_id = ? AND battery_no = ? AND battery_no IS NOT NULL LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param('is', $businessId, $battery_no);
                    $stmt->execute();
                    $dup = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    if ($dup) {
                        $error = 'Battery number already exists.';
                    }
                }
            }

            // Check for duplicate charger (if provided)
            if ($error === '' && $charger_no !== null) {
                $stmt = $conn->prepare("SELECT id FROM vehicle_stock WHERE business_id = ? AND charger_no = ? AND charger_no IS NOT NULL LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param('is', $businessId, $charger_no);
                    $stmt->execute();
                    $dup = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    if ($dup) {
                        $error = 'Charger number already exists.';
                    }
                }
            }

            if ($sale_price == 0 && $ex_showroom_price > 0) {
                $sale_price = $ex_showroom_price + $rto_charge + $registration_price + $road_tax + $insurance_price;
            }
        }

        if ($error === '') {
            $stmt = $conn->prepare("INSERT INTO vehicle_stock (
                business_id, branch_id, model_id, color, vin_no, chassis_no, engine_no, motor_no,
                battery_brand, battery_no, battery_capacity, battery_warranty_upto,
                charger_brand, charger_no, charger_type, charger_warranty_upto,
                key_no, manufacture_year, purchase_date, supplier_name, purchase_cost,
                sale_price, stock_status, remarks, created_at
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()
            )");

            if ($stmt) {
                $stmt->bind_param(
                    'iiisssssssssssssssssddss',
                    $businessId, $branch_id, $model_id, $color, $vin_no, $chassis_no, $engine_no, $motor_no,
                    $battery_brand, $battery_no, $battery_capacity, $battery_warranty_upto,
                    $charger_brand, $charger_no, $charger_type, $charger_warranty_upto,
                    $key_no, $manufacture_year, $purchase_date, $supplier_name, $purchase_cost,
                    $sale_price, $stock_status, $remarks
                );

                if ($stmt->execute()) {
                    $success = 'Vehicle stock added successfully.';
                } else {
                    $error = 'Failed to add vehicle stock: ' . $stmt->error;
                }
                $stmt->close();
            } else {
                $error = 'Unable to prepare insert query: ' . $conn->error;
            }
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);

        if ($id <= 0) {
            $error = 'Invalid stock id.';
        } else {
            $usedInSales = 0;
            if (tableExists($conn, 'vehicle_sales')) {
                $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM vehicle_sales WHERE business_id = ? AND vehicle_stock_id = ?");
                if ($stmt) {
                    $stmt->bind_param('ii', $businessId, $id);
                    $stmt->execute();
                    $row = $stmt->get_result()->fetch_assoc();
                    $usedInSales = (int)($row['total'] ?? 0);
                    $stmt->close();
                }
            }

            if ($usedInSales > 0) {
                $error = 'Cannot delete this stock because vehicle sale exists.';
            } else {
                $stmt = $conn->prepare("DELETE FROM vehicle_stock WHERE id = ? AND business_id = ? LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param('ii', $id, $businessId);
                    if ($stmt->execute()) {
                        $success = 'Vehicle stock deleted successfully.';
                    } else {
                        $error = 'Failed to delete vehicle stock.';
                    }
                    $stmt->close();
                } else {
                    $error = 'Unable to prepare delete query.';
                }
            }
        }
    }
}

/* -------------------------------------------------------
   FILTERS
------------------------------------------------------- */
$search = trim($_GET['search'] ?? '');
$branchFilter = (int)($_GET['branch_id'] ?? 0);
$modelFilter = (int)($_GET['model_id'] ?? 0);
$statusFilter = trim($_GET['stock_status'] ?? '');

$where = ["vs.business_id = {$businessId}"];

if ($search !== '') {
    $safe = $conn->real_escape_string($search);
    $where[] = "(vm.model_name LIKE '%{$safe}%' OR vm.variant_name LIKE '%{$safe}%' OR vb.brand_name LIKE '%{$safe}%' OR vs.chassis_no LIKE '%{$safe}%' OR vs.engine_no LIKE '%{$safe}%')";
}

if ($branchFilter > 0) $where[] = "vs.branch_id = {$branchFilter}";
if ($modelFilter > 0) $where[] = "vs.model_id = {$modelFilter}";
if ($statusFilter !== '' && in_array($statusFilter, $allowedStockStatus, true)) {
    $safe = $conn->real_escape_string($statusFilter);
    $where[] = "vs.stock_status = '{$safe}'";
}

$whereSql = implode(' AND ', $where);

/* -------------------------------------------------------
   SUMMARY
------------------------------------------------------- */
$totalStock = getCount($conn, 'vehicle_stock', "business_id = {$businessId}");
$inStock = getCount($conn, 'vehicle_stock', "business_id = {$businessId} AND stock_status = 'in_stock'");
$reservedStock = getCount($conn, 'vehicle_stock', "business_id = {$businessId} AND stock_status = 'reserved'");
$soldStock = getCount($conn, 'vehicle_stock', "business_id = {$businessId} AND stock_status = 'sold'");

/* -------------------------------------------------------
   FETCH STOCK
------------------------------------------------------- */
$stockRows = [];
$sql = "SELECT vs.*, vm.model_name, vm.variant_name, vb.brand_name, br.branch_name, br.branch_code
        FROM vehicle_stock vs
        LEFT JOIN vehicle_models vm ON vm.id = vs.model_id
        LEFT JOIN vehicle_brands vb ON vb.id = vm.brand_id
        LEFT JOIN branches br ON br.id = vs.branch_id
        WHERE {$whereSql}
        ORDER BY vs.id DESC";

$res = $conn->query($sql);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $stockRows[] = $row;
    }
}

$pageTitle = 'Vehicle Stock';
$currentPage = 'vehicle-stock';
?>
<!doctype html>
<html lang="en">

<?php include('includes/head.php'); ?>

<body data-sidebar="dark">

<style>
    .page-content { padding-bottom: 90px !important; }
    .card { margin-bottom: 24px; }
    .table-responsive { overflow-x: auto; }
    .main-content { min-height: calc(100vh - 70px); }
    .import-box {
        border: 2px dashed #ccc; border-radius: 8px; padding: 25px;
        text-align: center; background: #fafafa; cursor: pointer; transition: all 0.3s;
    }
    .import-box:hover { border-color: #3b82f6; background: #f0f7ff; }
    .import-box i { font-size: 40px; color: #6b7280; margin-bottom: 10px; }
    .sample-link { color: #3b82f6; text-decoration: underline; cursor: pointer; }
    .pricing-section { background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 15px; }
    .pricing-title { font-weight: 600; margin-bottom: 15px; color: #495057; }
    .btn-group-actions { display: flex; flex-wrap: wrap; gap: 5px; }
    .error-list { max-height: 200px; overflow-y: auto; }
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
                        <h4 class="mb-1">Vehicle Stock</h4>
                        <p class="text-muted mb-0">Manage vehicle stock for your business</p>
                    </div>
                    <div class="col-md-5 text-md-end mt-3 mt-md-0">
                        <button type="button" class="btn btn-success me-2" data-bs-toggle="modal" data-bs-target="#bulkImportModal">
                            <i class="ri-upload-cloud-line me-1"></i>Bulk Import
                        </button>
                        <a href="vehicle-models.php" class="btn btn-secondary">
                            <i class="ri-car-line me-1"></i>Vehicle Models
                        </a>
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

                <!-- Summary Cards -->
                <div class="row">
                    <div class="col-md-3"><div class="card"><div class="card-body text-center"><p class="text-muted mb-1">Total Stock</p><h3 class="mb-0"><?php echo number_format($totalStock); ?></h3></div></div></div>
                    <div class="col-md-3"><div class="card"><div class="card-body text-center"><p class="text-muted mb-1">In Stock</p><h3 class="mb-0 text-success"><?php echo number_format($inStock); ?></h3></div></div></div>
                    <div class="col-md-3"><div class="card"><div class="card-body text-center"><p class="text-muted mb-1">Reserved</p><h3 class="mb-0 text-warning"><?php echo number_format($reservedStock); ?></h3></div></div></div>
                    <div class="col-md-3"><div class="card"><div class="card-body text-center"><p class="text-muted mb-1">Sold</p><h3 class="mb-0 text-danger"><?php echo number_format($soldStock); ?></h3></div></div></div>
                </div>

                <!-- ADD FORM -->
                <div class="card">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0"><i class="ri-add-line me-2"></i>Add Vehicle Stock</h5>
                    </div>
                    <div class="card-body">
                        <form method="post" id="stockForm">
                            <input type="hidden" name="action" value="add">

                            <div class="row">
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Branch</label>
                                    <select name="branch_id" class="form-select" required>
                                        <option value="">Select Branch</option>
                                        <?php foreach ($branches as $b): ?>
                                            <option value="<?php echo (int)$b['id']; ?>" <?php echo ($currentBranchId === (int)$b['id']) ? 'selected' : ''; ?>>
                                                <?php echo h($b['branch_name'] . ' (' . $b['branch_code'] . ')'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Vehicle Model</label>
                                    <select name="model_id" id="model_id" class="form-select" required onchange="updateModelPrices()">
                                        <option value="">Select Model</option>
                                        <?php foreach ($models as $m): ?>
                                            <option value="<?php echo (int)$m['id']; ?>" 
                                                data-ex-showroom="<?php echo $m['ex_showroom_price'] ?? 0; ?>">
                                                <?php echo h(($m['brand_name'] ?: '-') . ' - ' . ($m['model_name'] ?: '-') . (!empty($m['variant_name']) ? ' / ' . $m['variant_name'] : '')); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-md-2 mb-3">
                                    <label class="form-label">Color</label>
                                    <input type="text" name="color" class="form-control">
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Chassis No <span class="text-danger">*</span></label>
                                    <input type="text" name="chassis_no" class="form-control" required>
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Engine No</label>
                                    <input type="text" name="engine_no" class="form-control">
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Motor No</label>
                                    <input type="text" name="motor_no" class="form-control">
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">VIN No</label>
                                    <input type="text" name="vin_no" class="form-control">
                                </div>
                            </div>

                            <!-- Pricing Section -->
                            <div class="pricing-section">
                                <h5 class="pricing-title"><i class="ri-money-rupee-circle-line me-2"></i>Pricing Details</h5>
                                <div class="row">
                                    <div class="col-md-2 mb-3">
                                        <label class="form-label">Ex-Showroom</label>
                                        <input type="number" step="0.01" min="0" name="ex_showroom_price" id="ex_showroom_price" class="form-control" value="0.00" onchange="calculateTotal()">
                                    </div>
                                    <div class="col-md-2 mb-3">
                                        <label class="form-label">RTO Charge</label>
                                        <input type="number" step="0.01" min="0" name="rto_charge" id="rto_charge" class="form-control" value="0.00" onchange="calculateTotal()">
                                    </div>
                                    <div class="col-md-2 mb-3">
                                        <label class="form-label">Registration</label>
                                        <input type="number" step="0.01" min="0" name="registration_price" id="registration_price" class="form-control" value="0.00" onchange="calculateTotal()">
                                    </div>
                                    <div class="col-md-2 mb-3">
                                        <label class="form-label">Road Tax</label>
                                        <input type="number" step="0.01" min="0" name="road_tax" id="road_tax" class="form-control" value="0.00" onchange="calculateTotal()">
                                    </div>
                                    <div class="col-md-2 mb-3">
                                        <label class="form-label">Insurance</label>
                                        <input type="number" step="0.01" min="0" name="insurance_price" id="insurance_price" class="form-control" value="0.00" onchange="calculateTotal()">
                                    </div>
                                    <div class="col-md-2 mb-3">
                                        <label class="form-label">Total Price</label>
                                        <input type="number" step="0.01" min="0" name="sale_price" id="sale_price" class="form-control" value="0.00" readonly style="background:#e9ecef; font-weight:bold;">
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-3 mb-3">
                                        <label class="form-label">Purchase Cost</label>
                                        <input type="number" step="0.01" min="0" name="purchase_cost" class="form-control" value="0.00">
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <label class="form-label">Supplier Name</label>
                                        <input type="text" name="supplier_name" class="form-control">
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <label class="form-label">Purchase Date</label>
                                        <input type="date" name="purchase_date" class="form-control">
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <label class="form-label">Stock Status</label>
                                        <select name="stock_status" class="form-select">
                                            <option value="in_stock">In Stock</option>
                                            <option value="reserved">Reserved</option>
                                            <option value="demo">Demo</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Battery Brand</label>
                                    <select name="battery_brand" class="form-select">
                                        <option value="">Select Battery Brand</option>
                                        <?php foreach ($batteryBrands as $bb): ?>
                                            <option value="<?php echo h($bb['brand_name']); ?>"><?php echo h($bb['brand_name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Battery No</label>
                                    <input type="text" name="battery_no" class="form-control">
                                    <small class="text-muted">Leave empty if not available</small>
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Charger Brand</label>
                                    <select name="charger_brand" class="form-select">
                                        <option value="">Select Charger Brand</option>
                                        <?php foreach ($chargerBrands as $cb): ?>
                                            <option value="<?php echo h($cb['brand_name']); ?>"><?php echo h($cb['brand_name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Charger No</label>
                                    <input type="text" name="charger_no" class="form-control">
                                    <small class="text-muted">Leave empty if not available</small>
                                </div>

                                <div class="col-md-9 mb-3">
                                    <label class="form-label">Remarks</label>
                                    <textarea name="remarks" class="form-control" rows="2"></textarea>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-primary"><i class="ri-save-line me-1"></i>Save Vehicle Stock</button>
                        </form>
                    </div>
                </div>

                <!-- FILTERS -->
                <div class="card">
                    <div class="card-body">
                        <form method="get" class="row g-3">
                            <div class="col-md-4">
                                <input type="text" name="search" class="form-control" placeholder="Search model, chassis, engine..." value="<?php echo h($search); ?>">
                            </div>
                            <div class="col-md-2">
                                <select name="branch_id" class="form-select">
                                    <option value="0">All Branches</option>
                                    <?php foreach ($branches as $b): ?>
                                        <option value="<?php echo (int)$b['id']; ?>" <?php echo ($branchFilter === (int)$b['id']) ? 'selected' : ''; ?>><?php echo h($b['branch_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <select name="model_id" class="form-select">
                                    <option value="0">All Models</option>
                                    <?php foreach ($models as $m): ?>
                                        <option value="<?php echo (int)$m['id']; ?>" <?php echo ($modelFilter === (int)$m['id']) ? 'selected' : ''; ?>><?php echo h(($m['brand_name'] ?: '-') . ' - ' . ($m['model_name'] ?: '-')); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <select name="stock_status" class="form-select">
                                    <option value="">All Status</option>
                                    <option value="in_stock" <?php echo ($statusFilter === 'in_stock') ? 'selected' : ''; ?>>In Stock</option>
                                    <option value="reserved" <?php echo ($statusFilter === 'reserved') ? 'selected' : ''; ?>>Reserved</option>
                                    <option value="sold" <?php echo ($statusFilter === 'sold') ? 'selected' : ''; ?>>Sold</option>
                                    <option value="demo" <?php echo ($statusFilter === 'demo') ? 'selected' : ''; ?>>Demo</option>
                                </select>
                            </div>
                            <div class="col-md-1">
                                <button type="submit" class="btn btn-primary w-100"><i class="ri-filter-line"></i> Filter</button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- LIST -->
                <div class="card">
                    <div class="card-header bg-light d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0"><i class="ri-list-check me-2"></i>Vehicle Stock List</h5>
                        <span class="badge bg-info"><?php echo count($stockRows); ?> items</span>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>#</th>
                                        <th>Vehicle</th>
                                        <th>Branch</th>
                                        <th>Chassis/Engine</th>
                                        <th>Pricing</th>
                                        <th>Status</th>
                                        <th style="width: 160px;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($stockRows)): ?>
                                        <?php $i = 1; foreach ($stockRows as $row): ?>
                                            <tr>
                                                <td><?php echo $i++; ?></td>
                                                <td>
                                                    <strong><?php echo h(($row['brand_name'] ?: '-') . ' - ' . ($row['model_name'] ?: '-')); ?></strong>
                                                    <?php if (!empty($row['variant_name'])): ?>
                                                        <div class="small text-muted"><?php echo h($row['variant_name']); ?></div>
                                                    <?php endif; ?>
                                                    <div class="small">Color: <?php echo h($row['color'] ?: '-'); ?></div>
                                                </td>
                                                <td><?php echo h($row['branch_name'] ?: '-'); ?></td>
                                                <td class="small">
                                                    <div><strong>C:</strong> <?php echo h($row['chassis_no']); ?></div>
                                                    <div><strong>E:</strong> <?php echo h($row['engine_no'] ?: '-'); ?></div>
                                                </td>
                                                <td class="small">
                                                    <div class="fw-bold"><?php echo money($row['sale_price'] ?? 0); ?></div>
                                                </td>
                                                <td>
                                                    <?php
                                                    $badge = 'secondary';
                                                    if ($row['stock_status'] === 'in_stock') $badge = 'success';
                                                    elseif ($row['stock_status'] === 'reserved') $badge = 'warning';
                                                    elseif ($row['stock_status'] === 'sold') $badge = 'danger';
                                                    elseif ($row['stock_status'] === 'demo') $badge = 'info';
                                                    ?>
                                                    <span class="badge bg-<?php echo $badge; ?>"><?php echo h(ucwords(str_replace('_', ' ', $row['stock_status']))); ?></span>
                                                </td>
                                                <td>
                                                    <div class="btn-group-actions">
                                                        <a href="vehicle-stock-view.php?id=<?php echo (int)$row['id']; ?>" class="btn btn-sm btn-info" title="View">
                                                            <i class="ri-eye-line"></i> View
                                                        </a>
                                                        <a href="vehicle-stock-edit.php?id=<?php echo (int)$row['id']; ?>" class="btn btn-sm btn-primary" title="Edit">
                                                            <i class="ri-pencil-line"></i> Edit
                                                        </a>
                                                        <form method="post" onsubmit="return confirm('Delete this vehicle stock?');" style="display:inline;">
                                                            <input type="hidden" name="action" value="delete">
                                                            <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                                                            <button type="submit" class="btn btn-sm btn-danger" title="Delete">
                                                                <i class="ri-delete-bin-line"></i> Delete
                                                            </button>
                                                        </form>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="7" class="text-center text-muted py-5">
                                                <i class="ri-inbox-line font-size-40 mb-2 d-block"></i>
                                                No vehicle stock found.
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

<!-- Bulk Import Modal -->
<div class="modal fade" id="bulkImportModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="ri-upload-cloud-line me-2"></i>Bulk Import Vehicle Stock</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="bulk_import">
                <div class="modal-body">
                    <?php if ($importError !== ''): ?>
                        <div class="alert alert-danger"><?php echo h($importError); ?></div>
                    <?php endif; ?>
                    
                    <?php if ($bulkResults !== null): ?>
                        <?php if ($bulkResults['success'] > 0): ?>
                            <div class="alert alert-success">
                                <i class="ri-check-line me-1"></i><strong><?php echo $bulkResults['success']; ?></strong> stock items imported!
                                <?php if ($bulkResults['skipped'] > 0): ?><br><small><?php echo $bulkResults['skipped']; ?> skipped</small><?php endif; ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($bulkResults['errors'])): ?>
                            <div class="alert alert-warning">
                                <strong>Errors:</strong>
                                <ul class="mb-0 mt-2 error-list">
                                    <?php foreach ($bulkResults['errors'] as $err): ?>
                                        <li><?php echo h($err); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>

                    <p class="text-muted mb-3">
                        Upload CSV file. <a href="#" onclick="downloadSample()" class="sample-link"><i class="ri-download-line"></i> Download sample</a>
                    </p>
                    
                    <div class="alert alert-info">
                        <strong>Required:</strong> Chassis No, Model<br>
                        <strong>Note:</strong> Leave Battery No, Charger No, Engine No empty if not available (they must be unique if provided).
                    </div>

                    <div class="import-box" onclick="document.getElementById('csv_file').click()">
                        <i class="ri-file-excel-2-line"></i>
                        <h5>Click to select CSV file</h5>
                        <p class="text-muted mb-0">or drag and drop</p>
                        <input type="file" name="csv_file" id="csv_file" accept=".csv,.txt" style="display: none;" onchange="displayFileName(this)">
                    </div>
                    <div id="selectedFile" class="mt-3" style="display: none;">
                        <span class="badge bg-info p-2"><span id="fileName"></span> <button type="button" class="btn-close ms-2" onclick="clearFile()"></button></span>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success" id="importBtn" disabled><i class="ri-upload-cloud-line me-1"></i>Import Stock</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include('includes/rightbar.php'); ?>
<?php include('includes/scripts.php'); ?>

<script>
const modelPrices = <?php echo json_encode($modelPrices); ?>;

function updateModelPrices() {
    const modelSelect = document.getElementById('model_id');
    const selectedOption = modelSelect.options[modelSelect.selectedIndex];
    const exShowroom = selectedOption.getAttribute('data-ex-showroom') || 0;
    document.getElementById('ex_showroom_price').value = parseFloat(exShowroom).toFixed(2);
    calculateTotal();
}

function calculateTotal() {
    const exShowroom = parseFloat(document.getElementById('ex_showroom_price').value) || 0;
    const rto = parseFloat(document.getElementById('rto_charge').value) || 0;
    const reg = parseFloat(document.getElementById('registration_price').value) || 0;
    const roadTax = parseFloat(document.getElementById('road_tax').value) || 0;
    const insurance = parseFloat(document.getElementById('insurance_price').value) || 0;
    const total = exShowroom + rto + reg + roadTax + insurance;
    document.getElementById('sale_price').value = total.toFixed(2);
}

function displayFileName(input) {
    if (input.files && input.files[0]) {
        document.getElementById('fileName').textContent = input.files[0].name;
        document.getElementById('selectedFile').style.display = 'block';
        document.getElementById('importBtn').disabled = false;
    }
}

function clearFile() {
    document.getElementById('csv_file').value = '';
    document.getElementById('selectedFile').style.display = 'none';
    document.getElementById('importBtn').disabled = true;
}

function downloadSample() {
    var csvContent = "Branch,Model,Color,Chassis No,Engine No,Battery Brand,Battery No,Charger Brand,Charger No,Purchase Cost,Sale Price,Status\n";
    csvContent += "Main Branch,Honda Activa,Red,CH123456,EN654321,Exide,BAT001,Delta,CHG001,55000,96000,in_stock\n";
    csvContent += "Main Branch,Ather 450X,White,CH789012,EN987654,Ather,BAT002,Ather,CHG002,120000,145000,in_stock\n";
    csvContent += "Main Branch,TVS Jupiter,Blue,CH456789,,,,,,,60000,85000,in_stock\n";
    
    var blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    var link = document.createElement("a");
    link.href = URL.createObjectURL(blob);
    link.download = "sample_vehicle_stock.csv";
    link.click();
}

var importBox = document.querySelector('.import-box');
if (importBox) {
    importBox.addEventListener('dragover', (e) => { e.preventDefault(); importBox.style.borderColor = '#10b981'; });
    importBox.addEventListener('dragleave', () => { importBox.style.borderColor = '#ccc'; });
    importBox.addEventListener('drop', (e) => {
        e.preventDefault();
        importBox.style.borderColor = '#ccc';
        if (e.dataTransfer.files.length) {
            document.getElementById('csv_file').files = e.dataTransfer.files;
            displayFileName({ files: e.dataTransfer.files });
        }
    });
}

setTimeout(() => document.querySelectorAll('.alert').forEach(a => new bootstrap.Alert(a).close()), 5000);
</script>
</body>
</html>