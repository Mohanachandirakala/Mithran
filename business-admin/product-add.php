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

function tableExists(mysqli $conn, string $table): bool
{
    $sql = "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return false;

    $stmt->bind_param('s', $table);
    $stmt->execute();
    $res = $stmt->get_result();
    $exists = $res && $res->num_rows > 0;
    $stmt->close();

    return $exists;
}

function columnExists(mysqli $conn, string $table, string $column): bool
{
    $sql = "SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return false;
    
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $res = $stmt->get_result();
    $exists = $res && $res->num_rows > 0;
    $stmt->close();
    
    return $exists;
}

function cleanNumber($value): float
{
    $value = trim((string)$value);
    if ($value === '') return 0.00;

    $value = str_replace(',', '', $value);
    $value = preg_replace('/[^0-9.\-]/', '', $value);

    return is_numeric($value) ? round((float)$value, 2) : 0.00;
}

function generateProductCode(mysqli $conn, int $businessId): string
{
    $prefix = 'PR';
    $year = date('y');
    $count = 1;

    $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM products WHERE business_id = ?");
    if ($stmt) {
        $stmt->bind_param('i', $businessId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $count = (int)($row['total'] ?? 0) + 1;
        $stmt->close();
    }

    return $prefix . $year . str_pad((string)$count, 4, '0', STR_PAD_LEFT);
}

function findOrCreateCategory(mysqli $conn, string $categoryName): ?int
{
    $categoryName = trim($categoryName);
    if ($categoryName === '') return null;

    $lower = strtolower($categoryName);

    $stmt = $conn->prepare("SELECT id FROM product_categories WHERE LOWER(category_name) = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('s', $lower);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row) {
            return (int)$row['id'];
        }
    }

    $stmt = $conn->prepare("INSERT INTO product_categories (category_name) VALUES (?)");
    if ($stmt) {
        $stmt->bind_param('s', $categoryName);
        if ($stmt->execute()) {
            $newId = (int)$stmt->insert_id;
            $stmt->close();
            return $newId;
        }
        $stmt->close();
    }

    return null;
}

// Add GST type column to products table if it doesn't exist
if (tableExists($conn, 'products') && !columnExists($conn, 'products', 'gst_type')) {
    $conn->query("ALTER TABLE products ADD COLUMN gst_type ENUM('exclusive', 'inclusive') NOT NULL DEFAULT 'inclusive' AFTER gst_percent");
}

/**
 * Calculate Base Price (without GST) from Final Price (Inclusive GST)
 * Formula: Base = Final / (1 + GST%)
 */
function calculateBasePrice($finalPrice, $gstPercent): float
{
    $finalPrice = (float)$finalPrice;
    $gstPercent = (float)$gstPercent;

    if ($finalPrice <= 0 || $gstPercent <= 0) {
        return $finalPrice;
    }

    return round($finalPrice / (1 + ($gstPercent / 100)), 2);
}

/**
 * Calculate GST Amount from Final Price (Inclusive GST)
 * Formula: GST = Final - Base
 */
function calculateGstAmount($finalPrice, $gstPercent): float
{
    $finalPrice = (float)$finalPrice;
    $basePrice = calculateBasePrice($finalPrice, $gstPercent);
    return round($finalPrice - $basePrice, 2);
}

/**
 * Calculate Final Price from Base Price (Exclusive GST)
 * Formula: Final = Base * (1 + GST%)
 */
function calculateFinalPrice($basePrice, $gstPercent): float
{
    $basePrice = (float)$basePrice;
    $gstPercent = (float)$gstPercent;

    if ($basePrice <= 0 || $gstPercent <= 0) {
        return $basePrice;
    }

    return round($basePrice * (1 + ($gstPercent / 100)), 2);
}

/* -------------------------------------------------------
   VALIDATE USER
------------------------------------------------------- */
$loggedUser = null;

if (tableExists($conn, 'business_users') && tableExists($conn, 'businesses')) {
    $stmt = $conn->prepare("
        SELECT bu.id, bu.branch_id, bu.full_name, bu.role, bu.status, b.business_name, b.status AS business_status
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

if (!$loggedUser || (int)$loggedUser['status'] !== 1 || ($loggedUser['business_status'] ?? '') !== 'active') {
    session_destroy();
    header('Location: login.php');
    exit;
}

/* -------------------------------------------------------
   LOAD CATEGORIES
------------------------------------------------------- */
$categories = [];
$categoryMap = [];
$defaultCategoryId = null;

if (tableExists($conn, 'product_categories')) {
    $res = $conn->query("SELECT id, category_name FROM product_categories ORDER BY category_name ASC");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $categories[] = $row;
            $categoryMap[strtolower(trim($row['category_name']))] = (int)$row['id'];

            if ($defaultCategoryId === null) {
                $defaultCategoryId = (int)$row['id'];
            }
        }
        $res->free();
    }
}

/* -------------------------------------------------------
   LOAD HSN CODES
------------------------------------------------------- */
$hsnCodes = [];

if (tableExists($conn, 'hsn_codes')) {
    $res = $conn->query("SELECT id, hsn_code, description, tax_percent FROM hsn_codes WHERE status = 1 ORDER BY hsn_code ASC");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $hsnCodes[] = $row;
        }
        $res->free();
    }
}

/* -------------------------------------------------------
   LOAD BRANCHES / BRANCH ACCESS
------------------------------------------------------- */
$branches = [];
$defaultBranchId = 0;
$userRole = (string)($loggedUser['role'] ?? '');
$isSuperAdmin = ($userRole === 'super_admin');
$userAssignedBranchId = isset($loggedUser['branch_id']) ? (int)$loggedUser['branch_id'] : 0;
$sessionBranchId = isset($_SESSION['branch_id']) ? (int)$_SESSION['branch_id'] : 0;

if (tableExists($conn, 'branches')) {
    if ($isSuperAdmin) {
        $stmt = $conn->prepare("
            SELECT id, branch_name, branch_code, status
            FROM branches
            WHERE business_id = ?
              AND status = 'active'
            ORDER BY branch_name ASC
        " );
        if ($stmt) {
            $stmt->bind_param('i', $businessId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $branches[] = $row;
            }
            $stmt->close();
        }
    } else {
        $branchToLoad = $userAssignedBranchId > 0 ? $userAssignedBranchId : $sessionBranchId;

        if ($branchToLoad > 0) {
            $stmt = $conn->prepare("
                SELECT id, branch_name, branch_code, status
                FROM branches
                WHERE business_id = ?
                  AND id = ?
                  AND status = 'active'
                LIMIT 1
            " );
            if ($stmt) {
                $stmt->bind_param('ii', $businessId, $branchToLoad);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $branches[] = $row;
                }
                $stmt->close();
            }
        }
    }
}

foreach ($branches as $branch) {
    $bid = (int)$branch['id'];
    if ($defaultBranchId <= 0) {
        $defaultBranchId = $bid;
    }
    if ($sessionBranchId > 0 && $bid === $sessionBranchId) {
        $defaultBranchId = $bid;
        break;
    }
}

function branchIsAllowed(array $branches, int $branchId): bool
{
    foreach ($branches as $branch) {
        if ((int)$branch['id'] === $branchId) {
            return true;
        }
    }
    return false;
}

function branchDisplayName(array $branches, int $branchId): string
{
    foreach ($branches as $branch) {
        if ((int)$branch['id'] === $branchId) {
            $name = (string)($branch['branch_name'] ?? '');
            $code = (string)($branch['branch_code'] ?? '');
            return $code !== '' ? $name . ' (' . $code . ')' : $name;
        }
    }
    return '';
}

$branchId = $defaultBranchId;

/* -------------------------------------------------------
   BULK IMPORT
------------------------------------------------------- */
$bulkResults = null;
$importSuccess = '';
$importError = '';
$showBulkModal = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bulk_import') {
    $importSuccessCount = 0;
    $importSkipped = 0;
    $importErrors = [];
    $newCategoriesCreated = [];
    $bulkBranchId = (int)($_POST['bulk_branch_id'] ?? $defaultBranchId);

    if ($bulkBranchId <= 0 || !branchIsAllowed($branches, $bulkBranchId)) {
        $importError = 'Please select a valid branch for bulk import.';
        $showBulkModal = true;
    } elseif (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
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

            if ($handle === false) {
                $importError = 'Unable to read the CSV file.';
                $showBulkModal = true;
            } else {
                $firstLine = fgets($handle);
                rewind($handle);

                $delimiter = (substr_count($firstLine, ';') > substr_count($firstLine, ',')) ? ';' : ',';
                $headers = fgetcsv($handle, 0, $delimiter);

                if ($headers === false) {
                    $importError = 'CSV file is empty.';
                    $showBulkModal = true;
                } else {
                    $headers = array_map(function ($h) {
                        return strtolower(trim((string)$h));
                    }, $headers);

                    $headerMap = [
                        'product_name'   => ['product name', 'product_name', 'name', 'product', 'item name'],
                        'category'       => ['category', 'category name', 'category_name', 'cat'],
                        'product_type'   => ['product type', 'product_type', 'type'],
                        'brand_name'     => ['brand', 'brand name', 'brand_name', 'make'],
                        'part_number'    => ['part number', 'part_number', 'part no', 'part'],
                        'item_code'      => ['item code', 'item_code', 'sku', 'code', 'product code'],
                        'hsn_code'       => ['hsn code', 'hsn_code', 'hsn', 'sac'],
                        'unit'           => ['unit', 'uom', 'unit of measure'],
                        'unit_name'      => ['unit name', 'unit_name'],
                        'purchase_price' => ['purchase price', 'purchase_price', 'cost price', 'cost', 'buying price'],
                        'mrp'            => ['mrp', 'mrp price', 'max retail price'],
                        'selling_price'  => [
                            'selling price', 'selling_price', 'selling value', 'selling_value',
                            'selling rate', 'selling_rate', 'sale price', 'sale value',
                            'sales price', 'retail price', 'unit price', 'price'
                        ],
                        'gst_percent'    => ['gst %', 'gst_percent', 'gst', 'tax %', 'tax'],
                        'gst_type'       => ['gst type', 'gst_type', 'tax type'],
                        'stock_qty'      => ['stock qty', 'stock_qty', 'quantity', 'qty', 'stock', 'opening stock'],
                        'min_stock_qty'  => ['min stock', 'min_stock_qty', 'min qty', 'minimum stock'],
                        'description'    => ['description', 'desc', 'details'],
                    ];

                    $columnIndexes = [];

                    foreach ($headerMap as $field => $possibleNames) {
                        foreach ($headers as $idx => $header) {
                            $cleanHeader = preg_replace('/[^a-z0-9]/', '', $header);

                            foreach ($possibleNames as $possible) {
                                $cleanPossible = preg_replace('/[^a-z0-9]/', '', $possible);

                                if ($cleanHeader === $cleanPossible) {
                                    $columnIndexes[$field] = $idx;
                                    break 2;
                                }
                            }
                        }
                    }

                    if (!isset($columnIndexes['product_name']) && count($headers) > 0) {
                        $columnIndexes['product_name'] = 0;
                    }

                    $allowedTypes = ['spare_part','accessory','consumable','lubricant','battery','tyre','helmet','other'];
                    $allowedUnits = ['pcs','nos','box','set','ltr','ml','kg','gm','pair','unit'];
                    $allowedGstTypes = ['inclusive', 'exclusive'];

                    $rowNumber = 1;
                    $conn->begin_transaction();

                    try {
                        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
                            $rowNumber++;

                            $hasData = false;
                            foreach ($row as $cell) {
                                if (trim((string)$cell) !== '') {
                                    $hasData = true;
                                    break;
                                }
                            }

                            if (!$hasData) {
                                continue;
                            }

                            $data = [];
                            foreach ($columnIndexes as $field => $idx) {
                                $data[$field] = isset($row[$idx]) ? trim((string)$row[$idx]) : '';
                            }

                            if (empty($data['product_name'])) {
                                $importErrors[] = "Row {$rowNumber}: Product name is required.";
                                $importSkipped++;
                                continue;
                            }

                            $categoryId = null;
                            $categoryName = trim($data['category'] ?? '');

                            if ($categoryName !== '') {
                                $catKey = strtolower($categoryName);

                                if (isset($categoryMap[$catKey])) {
                                    $categoryId = $categoryMap[$catKey];
                                } else {
                                    $categoryId = findOrCreateCategory($conn, $categoryName);
                                    if ($categoryId) {
                                        $categoryMap[$catKey] = $categoryId;
                                        $newCategoriesCreated[] = $categoryName;
                                    }
                                }
                            }

                            if (!$categoryId) {
                                $categoryId = $defaultCategoryId;
                            }

                            if (!$categoryId) {
                                $importErrors[] = "Row {$rowNumber}: Could not determine category.";
                                $importSkipped++;
                                continue;
                            }

                            $productType = 'spare_part';
                            if (!empty($data['product_type'])) {
                                $type = strtolower(str_replace([' ', '-'], '_', $data['product_type']));
                                if (in_array($type, $allowedTypes, true)) {
                                    $productType = $type;
                                }
                            }

                            $unit = 'pcs';
                            if (!empty($data['unit'])) {
                                $u = strtolower(trim($data['unit']));
                                if (in_array($u, $allowedUnits, true)) {
                                    $unit = $u;
                                } elseif (strpos($u, 'piece') !== false) {
                                    $unit = 'pcs';
                                } elseif (strpos($u, 'number') !== false) {
                                    $unit = 'nos';
                                } elseif (strpos($u, 'litre') !== false || strpos($u, 'liter') !== false) {
                                    $unit = 'ltr';
                                }
                            }

                            // GST Type - default to 'inclusive'
                            $gstType = 'inclusive';
                            if (!empty($data['gst_type'])) {
                                $gstTypeLower = strtolower(trim($data['gst_type']));
                                if (in_array($gstTypeLower, $allowedGstTypes, true)) {
                                    $gstType = $gstTypeLower;
                                }
                            }

                            // Get the raw values from CSV
                            $purchasePriceRaw = cleanNumber($data['purchase_price'] ?? '0');
                            $mrpRaw = cleanNumber($data['mrp'] ?? '0');
                            $sellingPriceRaw = cleanNumber($data['selling_price'] ?? '0');
                            $gstPercent = cleanNumber($data['gst_percent'] ?? '0');
                            $stockQty = cleanNumber($data['stock_qty'] ?? '0');
                            $minStockQty = cleanNumber($data['min_stock_qty'] ?? '0');

                            // Store prices as they are in CSV (these are the final/selling prices)
                            $purchasePrice = $purchasePriceRaw;
                            $mrp = $mrpRaw;
                            // Selling price is the final rate used in service invoices.
                            // When the CSV selling-price column is blank/zero, use MRP,
                            // then purchase price as the final fallback.
                            $sellingPrice = $sellingPriceRaw > 0
                                ? $sellingPriceRaw
                                : ($mrpRaw > 0 ? $mrpRaw : $purchasePriceRaw);

                            $productCode = generateProductCode($conn, $businessId);
                            $itemCode = trim($data['item_code'] ?? '');

                            if ($itemCode !== '') {
                                $baseItemCode = $itemCode;
                                $candidateItemCode = $baseItemCode;
                                $suffix = 2;

                                while (true) {
                                    $checkStmt = $conn->prepare("SELECT id FROM products WHERE business_id = ? AND item_code = ? LIMIT 1");
                                    if (!$checkStmt) {
                                        throw new Exception('Unable to validate item code uniqueness: ' . $conn->error);
                                    }

                                    $checkStmt->bind_param('is', $businessId, $candidateItemCode);
                                    $checkStmt->execute();
                                    $dup = $checkStmt->get_result()->fetch_assoc();
                                    $checkStmt->close();

                                    if (!$dup) {
                                        break;
                                    }

                                    $candidateItemCode = $baseItemCode . '_' . $suffix;
                                    $suffix++;
                                }

                                $itemCode = $candidateItemCode;
                            } else {
                                $itemCode = null;
                            }

                            $brandName = trim($data['brand_name'] ?? '');
                            $partNumber = trim($data['part_number'] ?? '');
                            $hsnCode = trim($data['hsn_code'] ?? '');
                            $unitName = trim($data['unit_name'] ?? '');
                            $description = trim($data['description'] ?? '');

                            $stmt = $conn->prepare("
                                INSERT INTO products (
                                    business_id, category_id, product_type, product_name,
                                    product_code, brand_name, part_number, item_code,
                                    hsn_code, unit_name, unit, purchase_price, mrp,
                                    selling_price, gst_percent, gst_type, stock_qty, min_stock_qty,
                                    description, status, created_at
                                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())
                            ");

                            if (!$stmt) {
                                $importErrors[] = "Row {$rowNumber}: Database prepare error.";
                                $importSkipped++;
                                continue;
                            }

                            $stmt->bind_param(
                                'iisssssssssddddsdds',
                                $businessId,
                                $categoryId,
                                $productType,
                                $data['product_name'],
                                $productCode,
                                $brandName,
                                $partNumber,
                                $itemCode,
                                $hsnCode,
                                $unitName,
                                $unit,
                                $purchasePrice,
                                $mrp,
                                $sellingPrice,
                                $gstPercent,
                                $gstType,
                                $stockQty,
                                $minStockQty,
                                $description
                            );

                            if ($stmt->execute()) {
                                $newProductId = (int)$stmt->insert_id;
                                $stmt->close();

                                if ($stockQty > 0 && $bulkBranchId > 0 && tableExists($conn, 'product_stock')) {
                                    $stockStmt = $conn->prepare("
                                        INSERT INTO product_stock (
                                            business_id, branch_id, product_id, qty_available, last_purchase_price, updated_at
                                        ) VALUES (?, ?, ?, ?, ?, NOW())
                                        ON DUPLICATE KEY UPDATE
                                            qty_available = qty_available + VALUES(qty_available),
                                            last_purchase_price = VALUES(last_purchase_price),
                                            updated_at = NOW()
                                    ");

                                    if ($stockStmt) {
                                        $stockStmt->bind_param('iiidd', $businessId, $bulkBranchId, $newProductId, $stockQty, $purchasePrice);
                                        $stockStmt->execute();
                                        $stockStmt->close();
                                    }
                                }

                                $importSuccessCount++;
                            } else {
                                $importErrors[] = "Row {$rowNumber}: Failed to insert - " . $stmt->error;
                                $stmt->close();
                                $importSkipped++;
                            }
                        }

                        if (tableExists($conn, 'audit_logs')) {
                            $desc = "Bulk imported {$importSuccessCount} products";
                            if ($importSkipped > 0) {
                                $desc .= " ({$importSkipped} skipped)";
                            }
                            if (!empty($newCategoriesCreated)) {
                                $desc .= ". Created categories: " . implode(', ', array_unique($newCategoriesCreated));
                            }

                            $logStmt = $conn->prepare("
                                INSERT INTO audit_logs (
                                    business_id, branch_id, user_id, action, module_name, ref_table, description, created_at
                                ) VALUES (?, ?, ?, 'Bulk Import', 'Products', 'products', ?, NOW())
                            ");

                            if ($logStmt) {
                                $logStmt->bind_param('iiis', $businessId, $bulkBranchId, $businessUserId, $desc);
                                $logStmt->execute();
                                $logStmt->close();
                            }
                        }

                        $conn->commit();

                        $importSuccess = "Successfully imported {$importSuccessCount} products.";
                        if ($importSkipped > 0) {
                            $importSuccess .= " {$importSkipped} skipped.";
                        }

                    } catch (Exception $e) {
                        $conn->rollback();
                        $importError = 'Import failed: ' . $e->getMessage();
                        $showBulkModal = true;
                    }
                }

                fclose($handle);
            }
        }
    }

    $bulkResults = [
        'success' => $importSuccessCount,
        'errors' => $importErrors,
        'skipped' => $importSkipped,
        'newCategories' => $newCategoriesCreated
    ];
}

/* -------------------------------------------------------
   FORM DEFAULTS
------------------------------------------------------- */
$success = '';
$error = '';

$form = [
    'branch_id'      => (string)$defaultBranchId,
    'category_id'    => '',
    'product_type'   => 'spare_part',
    'product_name'   => '',
    'product_code'   => generateProductCode($conn, $businessId),
    'brand_name'     => '',
    'part_number'    => '',
    'item_code'      => '',
    'hsn_code'       => '',
    'unit_name'      => '',
    'unit'           => 'pcs',
    'purchase_price' => '0.00',
    'mrp'            => '0.00',
    'selling_price'  => '0.00',
    'gst_percent'    => '0.00',
    'gst_type'       => 'inclusive',
    'stock_qty'      => '0.00',
    'min_stock_qty'  => '0.00',
    'description'    => '',
    'status'         => '1',
];

/* -------------------------------------------------------
   SAVE SINGLE PRODUCT
------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'bulk_import') {
    $form['branch_id']      = trim($_POST['branch_id'] ?? (string)$defaultBranchId);
    $form['category_id']    = trim($_POST['category_id'] ?? '');
    $form['product_type']   = trim($_POST['product_type'] ?? 'spare_part');
    $form['product_name']   = trim($_POST['product_name'] ?? '');
    $form['product_code']   = trim($_POST['product_code'] ?? '');
    $form['brand_name']     = trim($_POST['brand_name'] ?? '');
    $form['part_number']    = trim($_POST['part_number'] ?? '');
    $form['item_code']      = trim($_POST['item_code'] ?? '');
    $form['hsn_code']       = trim($_POST['hsn_code'] ?? '');
    $form['unit_name']      = trim($_POST['unit_name'] ?? '');
    $form['unit']           = trim($_POST['unit'] ?? 'pcs');
    $form['purchase_price'] = trim($_POST['purchase_price'] ?? '0.00');
    $form['mrp']            = trim($_POST['mrp'] ?? '0.00');
    $form['selling_price']  = trim($_POST['selling_price'] ?? '0.00');
    $form['gst_percent']    = trim($_POST['gst_percent'] ?? '0.00');
    $form['gst_type']       = trim($_POST['gst_type'] ?? 'inclusive');
    $form['stock_qty']      = trim($_POST['stock_qty'] ?? '0.00');
    $form['min_stock_qty']  = trim($_POST['min_stock_qty'] ?? '0.00');
    $form['description']    = trim($_POST['description'] ?? '');
    $form['status']         = trim($_POST['status'] ?? '1');

    $branchId = (int)$form['branch_id'];
    $categoryId = (int)$form['category_id'];
    $purchasePrice = cleanNumber($form['purchase_price']);
    $mrp = cleanNumber($form['mrp']);
    $sellingPrice = cleanNumber($form['selling_price']);
    $gstPercent = cleanNumber($form['gst_percent']);
    $gstType = in_array($form['gst_type'], ['inclusive', 'exclusive']) ? $form['gst_type'] : 'inclusive';
    $stockQty = cleanNumber($form['stock_qty']);
    $minStockQty = cleanNumber($form['min_stock_qty']);
    $status = (int)$form['status'];

    $allowedTypes = ['spare_part','accessory','consumable','lubricant','battery','tyre','helmet','other'];

    if ($branchId <= 0 || !branchIsAllowed($branches, $branchId)) {
        $error = 'Please select a valid branch.';
    } elseif ($categoryId <= 0) {
        $error = 'Please select category.';
    } elseif ($form['product_name'] === '') {
        $error = 'Product name is required.';
    } elseif (!in_array($form['product_type'], $allowedTypes, true)) {
        $error = 'Invalid product type.';
    } elseif ($form['unit'] === '') {
        $error = 'Unit is required.';
    } else {
        $itemCodeToSave = ($form['item_code'] === '') ? null : $form['item_code'];

        if ($itemCodeToSave !== null) {
            $stmt = $conn->prepare("SELECT id FROM products WHERE business_id = ? AND item_code = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('is', $businessId, $itemCodeToSave);
                $stmt->execute();
                $dup = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($dup) {
                    $error = 'Item code already exists.';
                }
            }
        }
    }

    if ($error === '') {
        $conn->begin_transaction();

        try {
            $stmt = $conn->prepare("
                INSERT INTO products (
                    business_id, category_id, product_type, product_name,
                    product_code, brand_name, part_number, item_code,
                    hsn_code, unit_name, unit, purchase_price, mrp,
                    selling_price, gst_percent, gst_type, stock_qty, min_stock_qty,
                    description, status, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");

            if (!$stmt) {
                throw new Exception('Unable to prepare product insert query.');
            }

            $stmt->bind_param(
                'iisssssssssddddsddsi',
                $businessId,
                $categoryId,
                $form['product_type'],
                $form['product_name'],
                $form['product_code'],
                $form['brand_name'],
                $form['part_number'],
                $itemCodeToSave,
                $form['hsn_code'],
                $form['unit_name'],
                $form['unit'],
                $purchasePrice,
                $mrp,
                $sellingPrice,
                $gstPercent,
                $gstType,
                $stockQty,
                $minStockQty,
                $form['description'],
                $status
            );

            if (!$stmt->execute()) {
                throw new Exception($stmt->error);
            }

            $newProductId = (int)$stmt->insert_id;
            $stmt->close();

            if ($stockQty > 0 && $branchId > 0 && tableExists($conn, 'product_stock')) {
                $stockStmt = $conn->prepare("
                    INSERT INTO product_stock (
                        business_id, branch_id, product_id, qty_available, last_purchase_price, updated_at
                    ) VALUES (?, ?, ?, ?, ?, NOW())
                ");

                if ($stockStmt) {
                    $stockStmt->bind_param('iiidd', $businessId, $branchId, $newProductId, $stockQty, $purchasePrice);
                    $stockStmt->execute();
                    $stockStmt->close();
                }
            }

            if (tableExists($conn, 'audit_logs')) {
                $description = "Added product: " . $form['product_name'] . " (GST Type: " . ucfirst($gstType) . ", GST%: " . $gstPercent . "%)";
                $logStmt = $conn->prepare("
                    INSERT INTO audit_logs (
                        business_id, branch_id, user_id, action, module_name, ref_table, ref_id, description, created_at
                    ) VALUES (?, ?, ?, 'Add Product', 'Products', 'products', ?, ?, NOW())
                ");

                if ($logStmt) {
                    $logStmt->bind_param('iiiis', $businessId, $branchId, $businessUserId, $newProductId, $description);
                    $logStmt->execute();
                    $logStmt->close();
                }
            }

            $conn->commit();
            header('Location: product-view.php?id=' . $newProductId);
            exit;

        } catch (Exception $e) {
            $conn->rollback();
            $error = 'Failed to save product: ' . $e->getMessage();
        }
    }
}


/* -------------------------------------------------------
   PAGE SUMMARY COUNTS
------------------------------------------------------- */
$totalProducts = 0;
$activeProducts = 0;
$lowStockProducts = 0;
$totalCategories = count($categories);

if (tableExists($conn, 'products')) {
    $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM products WHERE business_id = ?");
    if ($stmt) {
        $stmt->bind_param('i', $businessId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $totalProducts = (int)($row['total'] ?? 0);
        $stmt->close();
    }

    $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM products WHERE business_id = ? AND status = 1");
    if ($stmt) {
        $stmt->bind_param('i', $businessId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $activeProducts = (int)($row['total'] ?? 0);
        $stmt->close();
    }

    $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM products WHERE business_id = ? AND stock_qty > 0 AND stock_qty <= min_stock_qty");
    if ($stmt) {
        $stmt->bind_param('i', $businessId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $lowStockProducts = (int)($row['total'] ?? 0);
        $stmt->close();
    }
}

$pageTitle = 'Add Product';
$currentPage = 'products';
?>
<!doctype html>
<html lang="en">

<?php include('includes/head.php'); ?>

<body data-sidebar="dark">

<style>
    .page-title-box-modern {
        background: linear-gradient(135deg, #0d6efd 0%, #2563eb 55%, #1e40af 100%);
        border-radius: 18px;
        padding: 22px;
        color: #fff;
        box-shadow: 0 12px 28px rgba(37, 99, 235, 0.20);
        position: relative;
        overflow: hidden;
    }
    .page-title-box-modern:after {
        content: "";
        position: absolute;
        width: 210px;
        height: 210px;
        right: -65px;
        top: -80px;
        border-radius: 50%;
        background: rgba(255,255,255,0.12);
    }
    .page-title-box-modern .subtitle {
        color: rgba(255,255,255,0.78);
        margin-bottom: 0;
    }
    .metric-card {
        border: 0;
        border-radius: 16px;
        box-shadow: 0 8px 22px rgba(15, 23, 42, 0.06);
        transition: all .18s ease;
        overflow: hidden;
    }
    .metric-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 12px 30px rgba(15, 23, 42, 0.10);
    }
    .metric-icon {
        width: 44px;
        height: 44px;
        border-radius: 13px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 23px;
    }
    .icon-primary { background: #e0edff; color: #0d6efd; }
    .icon-success { background: #dcfce7; color: #16a34a; }
    .icon-warning { background: #fef3c7; color: #d97706; }
    .icon-danger { background: #fee2e2; color: #dc2626; }
    .form-section-card {
        border: 0;
        border-radius: 18px;
        box-shadow: 0 8px 24px rgba(15, 23, 42, 0.06);
    }
    .form-section-card .card-header {
        border-bottom: 1px solid #edf2f7;
        background: #fff;
        border-radius: 18px 18px 0 0;
        padding: 18px 22px;
    }
    .section-title {
        display: flex;
        align-items: center;
        gap: 10px;
        font-weight: 700;
        margin-bottom: 0;
        color: #111827;
    }
    .section-title i {
        width: 34px;
        height: 34px;
        border-radius: 10px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: #eef2ff;
        color: #4f46e5;
        font-size: 18px;
    }
    .form-label {
        font-weight: 600;
        color: #374151;
        font-size: 13px;
    }
    .form-control, .form-select, .input-group-text {
        border-radius: 10px;
    }
    .input-group .input-group-text {
        border-top-right-radius: 0;
        border-bottom-right-radius: 0;
    }
    .input-group .form-control {
        border-top-left-radius: 0;
        border-bottom-left-radius: 0;
    }
    .hint-box {
        border-radius: 14px;
        padding: 14px 16px;
        background: #eff6ff;
        border-left: 4px solid #0d6efd;
        color: #1e3a8a;
    }
    .price-breakdown {
        display: block;
        min-height: 18px;
        margin-top: 6px;
        color: #64748b !important;
    }
    .import-box {
        border: 2px dashed #cbd5e1;
        border-radius: 16px;
        padding: 34px 20px;
        text-align: center;
        background: #f8fafc;
        cursor: pointer;
        transition: all 0.25s ease;
    }
    .import-box:hover {
        border-color: #0d6efd;
        background: #eff6ff;
    }
    .import-box i {
        font-size: 48px;
        color: #0d6efd;
        margin-bottom: 12px;
    }
    .sample-link {
        color: #0d6efd;
        font-weight: 600;
        text-decoration: none;
    }
    .sample-link:hover { text-decoration: underline; }
    .sticky-actions {
        position: sticky;
        bottom: 0;
        z-index: 5;
        background: rgba(255,255,255,0.92);
        backdrop-filter: blur(8px);
        border-top: 1px solid #edf2f7;
        padding: 16px 0 0;
        margin-top: 6px;
    }
    .badge-soft-primary {
        background: #e0edff;
        color: #0d6efd;
        border: 1px solid #bfdbfe;
    }
    .table-centered td,
    .table-centered th {
        vertical-align: middle;
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

                <div class="page-title-box-modern mb-4">
                    <div class="row align-items-center position-relative" style="z-index:1;">
                        <div class="col-lg-7">
                            <div class="d-flex align-items-center gap-3">
                                <div class="metric-icon" style="background:rgba(255,255,255,.18); color:#fff;">
                                    <i class="ri-box-3-line"></i>
                                </div>
                                <div>
                                    <h4 class="mb-1 text-white">Add Product</h4>
                                    <p class="subtitle">Create inventory items, select branch stock, set GST pricing, and update opening stock.</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-5 text-lg-end mt-3 mt-lg-0">
                            <button type="button" class="btn btn-light me-2" data-bs-toggle="modal" data-bs-target="#bulkImportModal">
                                <i class="ri-upload-cloud-2-line me-1"></i> Bulk Import
                            </button>
                            <a href="products.php" class="btn btn-outline-light">
                                <i class="ri-arrow-left-line me-1"></i> Back to List
                            </a>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6 col-xl-3">
                        <div class="card metric-card">
                            <div class="card-body d-flex justify-content-between align-items-center">
                                <div>
                                    <p class="text-muted mb-1">Total Products</p>
                                    <h4 class="mb-0"><?php echo number_format($totalProducts); ?></h4>
                                </div>
                                <span class="metric-icon icon-primary"><i class="ri-archive-line"></i></span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <div class="card metric-card">
                            <div class="card-body d-flex justify-content-between align-items-center">
                                <div>
                                    <p class="text-muted mb-1">Active Products</p>
                                    <h4 class="mb-0"><?php echo number_format($activeProducts); ?></h4>
                                </div>
                                <span class="metric-icon icon-success"><i class="ri-checkbox-circle-line"></i></span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <div class="card metric-card">
                            <div class="card-body d-flex justify-content-between align-items-center">
                                <div>
                                    <p class="text-muted mb-1">Categories</p>
                                    <h4 class="mb-0"><?php echo number_format($totalCategories); ?></h4>
                                </div>
                                <span class="metric-icon icon-warning"><i class="ri-price-tag-3-line"></i></span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <div class="card metric-card">
                            <div class="card-body d-flex justify-content-between align-items-center">
                                <div>
                                    <p class="text-muted mb-1">Low Stock</p>
                                    <h4 class="mb-0"><?php echo number_format($lowStockProducts); ?></h4>
                                </div>
                                <span class="metric-icon icon-danger"><i class="ri-alert-line"></i></span>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($importSuccess !== ''): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="ri-checkbox-circle-line me-1"></i><?php echo h($importSuccess); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <i class="ri-error-warning-line me-1"></i><?php echo h($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if (empty($branches)): ?>
                    <div class="alert alert-warning">
                        <i class="ri-map-pin-warning-line me-1"></i>No active branch is available for this business. Please create/activate a branch before adding stock.
                    </div>
                <?php endif; ?>

                <form method="post" id="productForm" autocomplete="off">
                    <div class="row">
                        <div class="col-xl-8">
                            <div class="card form-section-card mb-4">
                                <div class="card-header">
                                    <h5 class="section-title"><i class="ri-information-line"></i> Basic Details</h5>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Category <span class="text-danger">*</span></label>
                                            <select name="category_id" class="form-select" required>
                                                <option value="">Select Category</option>
                                                <?php foreach ($categories as $cat): ?>
                                                    <option value="<?php echo (int)$cat['id']; ?>" <?php echo ((string)$form['category_id'] === (string)$cat['id']) ? 'selected' : ''; ?>>
                                                        <?php echo h($cat['category_name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Product Type <span class="text-danger">*</span></label>
                                            <select name="product_type" class="form-select" required>
                                                <?php
                                                $productTypes = [
                                                    'spare_part' => 'Spare Part',
                                                    'accessory' => 'Accessory',
                                                    'consumable' => 'Consumable',
                                                    'lubricant' => 'Lubricant',
                                                    'battery' => 'Battery',
                                                    'tyre' => 'Tyre',
                                                    'helmet' => 'Helmet',
                                                    'other' => 'Other',
                                                ];
                                                foreach ($productTypes as $typeValue => $typeLabel):
                                                ?>
                                                    <option value="<?php echo h($typeValue); ?>" <?php echo ($form['product_type'] === $typeValue) ? 'selected' : ''; ?>><?php echo h($typeLabel); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="col-md-8 mb-3">
                                            <label class="form-label">Product Name <span class="text-danger">*</span></label>
                                            <input type="text" name="product_name" class="form-control" value="<?php echo h($form['product_name']); ?>" placeholder="Enter product name" required>
                                        </div>

                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">Product Code</label>
                                            <input type="text" name="product_code" class="form-control" value="<?php echo h($form['product_code']); ?>" readonly>
                                        </div>

                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">Brand Name</label>
                                            <input type="text" name="brand_name" class="form-control" value="<?php echo h($form['brand_name']); ?>" placeholder="Brand / Make">
                                        </div>

                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">Part Number</label>
                                            <input type="text" name="part_number" class="form-control" value="<?php echo h($form['part_number']); ?>" placeholder="Part no">
                                        </div>

                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">Item Code / SKU</label>
                                            <input type="text" name="item_code" class="form-control" value="<?php echo h($form['item_code']); ?>" placeholder="Unique item code">
                                        </div>

                                        <div class="col-md-12 mb-0">
                                            <label class="form-label">Description</label>
                                            <textarea name="description" class="form-control" rows="3" placeholder="Product notes or details"><?php echo h($form['description']); ?></textarea>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="card form-section-card mb-4">
                                <div class="card-header">
                                    <h5 class="section-title"><i class="ri-money-rupee-circle-line"></i> Pricing & GST</h5>
                                </div>
                                <div class="card-body">
                                    <div class="hint-box mb-3">
                                        <strong>GST Type:</strong> Inclusive means price already includes GST. Exclusive means GST is added on top of the entered base price.
                                    </div>

                                    <div class="row">
                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">Purchase Price</label>
                                            <div class="input-group">
                                                <span class="input-group-text">₹</span>
                                                <input type="number" step="0.01" min="0" name="purchase_price" class="form-control price-input" value="<?php echo h($form['purchase_price']); ?>" id="purchase_price">
                                            </div>
                                            <small class="price-breakdown" id="purchase_breakdown"></small>
                                        </div>

                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">MRP</label>
                                            <div class="input-group">
                                                <span class="input-group-text">₹</span>
                                                <input type="number" step="0.01" min="0" name="mrp" class="form-control price-input" value="<?php echo h($form['mrp']); ?>" id="mrp">
                                            </div>
                                            <small class="price-breakdown" id="mrp_breakdown"></small>
                                        </div>

                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">Selling Price</label>
                                            <div class="input-group">
                                                <span class="input-group-text">₹</span>
                                                <input type="number" step="0.01" min="0" name="selling_price" class="form-control price-input" value="<?php echo h($form['selling_price']); ?>" id="selling_price">
                                            </div>
                                            <small class="price-breakdown" id="selling_breakdown"></small>
                                        </div>

                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">GST %</label>
                                            <input type="number" step="0.01" min="0" name="gst_percent" class="form-control" id="gst_percent" value="<?php echo h($form['gst_percent']); ?>">
                                        </div>

                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">GST Type <span class="text-danger">*</span></label>
                                            <select name="gst_type" class="form-select" id="gst_type" required>
                                                <option value="inclusive" <?php echo ($form['gst_type'] === 'inclusive') ? 'selected' : ''; ?>>Inclusive</option>
                                                <option value="exclusive" <?php echo ($form['gst_type'] === 'exclusive') ? 'selected' : ''; ?>>Exclusive</option>
                                            </select>
                                        </div>

                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">HSN Code</label>
                                            <select name="hsn_code" class="form-select" id="hsn_code_select">
                                                <option value="">Select HSN Code</option>
                                                <?php foreach ($hsnCodes as $hsn): ?>
                                                    <option value="<?php echo h($hsn['hsn_code']); ?>" data-tax="<?php echo h($hsn['tax_percent']); ?>" <?php echo ((string)$form['hsn_code'] === (string)$hsn['hsn_code']) ? 'selected' : ''; ?>>
                                                        <?php echo h($hsn['hsn_code']); ?><?php echo ($hsn['description'] !== '') ? ' - ' . h($hsn['description']) : ''; ?> (<?php echo h($hsn['tax_percent']); ?>%)
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-xl-4">
                            <div class="card form-section-card mb-4">
                                <div class="card-header">
                                    <h5 class="section-title"><i class="ri-stack-line"></i> Stock & Unit</h5>
                                </div>
                                <div class="card-body">
                                    <div class="mb-3">
                                        <label class="form-label">Branch <span class="text-danger">*</span></label>
                                        <select name="branch_id" class="form-select" required>
                                            <option value="">Select Branch</option>
                                            <?php foreach ($branches as $branch): ?>
                                                <option value="<?php echo (int)$branch['id']; ?>" <?php echo ((string)$form['branch_id'] === (string)$branch['id']) ? 'selected' : ''; ?>>
                                                    <?php echo h($branch['branch_name']); ?><?php echo !empty($branch['branch_code']) ? ' - ' . h($branch['branch_code']) : ''; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="text-muted">Opening stock will be added to this branch.</small>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Unit Name</label>
                                        <input type="text" name="unit_name" class="form-control" value="<?php echo h($form['unit_name']); ?>" placeholder="Example: Pieces">
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Unit <span class="text-danger">*</span></label>
                                        <select name="unit" class="form-select" required>
                                            <?php
                                            $units = [
                                                'pcs' => 'Pieces (pcs)',
                                                'nos' => 'Numbers (nos)',
                                                'box' => 'Box',
                                                'set' => 'Set',
                                                'ltr' => 'Litre (ltr)',
                                                'ml' => 'Millilitre (ml)',
                                                'kg' => 'Kilogram (kg)',
                                                'gm' => 'Gram (gm)',
                                                'pair' => 'Pair',
                                                'unit' => 'Unit',
                                            ];
                                            foreach ($units as $unitValue => $unitLabel):
                                            ?>
                                                <option value="<?php echo h($unitValue); ?>" <?php echo ($form['unit'] === $unitValue) ? 'selected' : ''; ?>><?php echo h($unitLabel); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-6 col-xl-12 mb-3">
                                            <label class="form-label">Opening Stock Qty</label>
                                            <input type="number" step="0.01" min="0" name="stock_qty" class="form-control" value="<?php echo h($form['stock_qty']); ?>">
                                        </div>

                                        <div class="col-md-6 col-xl-12 mb-3">
                                            <label class="form-label">Min Stock Qty</label>
                                            <input type="number" step="0.01" min="0" name="min_stock_qty" class="form-control" value="<?php echo h($form['min_stock_qty']); ?>">
                                        </div>
                                    </div>

                                    <div class="mb-0">
                                        <label class="form-label">Status</label>
                                        <select name="status" class="form-select">
                                            <option value="1" <?php echo ($form['status'] === '1') ? 'selected' : ''; ?>>Active</option>
                                            <option value="0" <?php echo ($form['status'] === '0') ? 'selected' : ''; ?>>Inactive</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div class="card form-section-card mb-4">
                                <div class="card-header">
                                    <h5 class="section-title"><i class="ri-lightbulb-flash-line"></i> Quick Tips</h5>
                                </div>
                                <div class="card-body">
                                    <div class="d-flex gap-2 mb-3">
                                        <span class="badge badge-soft-primary rounded-pill">Stock</span>
                                        <span class="text-muted small">Opening stock will be inserted into the selected branch stock.</span>
                                    </div>
                                    <div class="d-flex gap-2 mb-3">
                                        <span class="badge badge-soft-primary rounded-pill">SKU</span>
                                        <span class="text-muted small">Item code is checked for duplicates inside your business.</span>
                                    </div>
                                    <div class="d-flex gap-2 mb-0">
                                        <span class="badge badge-soft-primary rounded-pill">Import</span>
                                        <span class="text-muted small">Use CSV import for large product lists.</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="sticky-actions">
                        <div class="d-flex flex-wrap gap-2 justify-content-end">
                            <a href="products.php" class="btn btn-light">
                                <i class="ri-close-line me-1"></i> Cancel
                            </a>
                            <button type="reset" class="btn btn-outline-secondary">
                                <i class="ri-refresh-line me-1"></i> Reset
                            </button>
                            <button type="submit" class="btn btn-primary px-4" <?php echo empty($branches) ? 'disabled' : ''; ?>>
                                <i class="ri-save-3-line me-1"></i> Save Product
                            </button>
                        </div>
                    </div>
                </form>

            </div>
        </div>

        <?php include('includes/footer.php'); ?>
    </div>
</div>

<!-- Bulk Import Modal -->
<div class="modal fade" id="bulkImportModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content" style="border-radius:18px; overflow:hidden;">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <i class="ri-upload-cloud-2-line me-2"></i>Bulk Import Products
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>

            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="bulk_import">

                <div class="modal-body">
                    <?php if ($importError !== ''): ?>
                        <div class="alert alert-danger"><?php echo h($importError); ?></div>
                    <?php endif; ?>

                    <?php if ($bulkResults !== null && !empty($bulkResults['errors'])): ?>
                        <div class="alert alert-warning">
                            <strong>Import warnings:</strong>
                            <ul class="mb-0 mt-2">
                                <?php foreach ($bulkResults['errors'] as $err): ?>
                                    <li><?php echo h($err); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <div class="hint-box mb-3">
                        Upload a CSV file. Category names not found will be created automatically. GST type should be <strong>inclusive</strong> or <strong>exclusive</strong>. Imported opening stock will be added to the selected branch below.
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-8">
                            <label class="form-label">Import Stock Branch <span class="text-danger">*</span></label>
                            <select name="bulk_branch_id" class="form-select" required>
                                <option value="">Select Branch</option>
                                <?php foreach ($branches as $branch): ?>
                                    <?php $selectedBulkBranch = (isset($_POST['bulk_branch_id']) ? (int)$_POST['bulk_branch_id'] : $defaultBranchId); ?>
                                    <option value="<?php echo (int)$branch['id']; ?>" <?php echo ($selectedBulkBranch === (int)$branch['id']) ? 'selected' : ''; ?>>
                                        <?php echo h($branch['branch_name']); ?><?php echo !empty($branch['branch_code']) ? ' - ' . h($branch['branch_code']) : ''; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">All imported products opening stock will be posted to this branch.</small>
                        </div>
                    </div>

                    <a href="#" onclick="downloadSample();return false;" class="sample-link">
                        <i class="ri-download-line me-1"></i>Download sample CSV
                    </a>

                    <div class="import-box mt-3" onclick="document.getElementById('csv_file').click()">
                        <i class="ri-file-excel-2-line"></i>
                        <h5 class="mb-1">Click to select CSV file</h5>
                        <p class="text-muted mb-0">CSV only. Maximum size 10MB.</p>
                        <input type="file" name="csv_file" id="csv_file" accept=".csv" style="display:none;" onchange="displayFileName(this)">
                    </div>

                    <div id="selectedFile" class="mt-3" style="display:none;">
                        <span class="badge bg-info p-2"><i class="ri-file-line me-1"></i><span id="fileName"></span></span>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="importBtn" disabled <?php echo empty($branches) ? 'disabled' : ''; ?>>
                        <i class="ri-upload-cloud-line me-1"></i>Import Products
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include('includes/rightbar.php'); ?>
<?php include('includes/scripts.php'); ?>

<script>
function displayFileName(input) {
    if (input.files && input.files[0]) {
        document.getElementById('fileName').textContent = input.files[0].name;
        document.getElementById('selectedFile').style.display = 'block';
        document.getElementById('importBtn').disabled = <?php echo empty($branches) ? 'true' : 'false'; ?>;
    }
}

function calculateBasePrice(finalPrice, gstPercent) {
    if (finalPrice <= 0 || gstPercent <= 0) return finalPrice;
    return finalPrice / (1 + (gstPercent / 100));
}

function calculateGstAmount(finalPrice, gstPercent) {
    if (finalPrice <= 0 || gstPercent <= 0) return 0;
    const basePrice = calculateBasePrice(finalPrice, gstPercent);
    return finalPrice - basePrice;
}

function calculateFinalPrice(basePrice, gstPercent) {
    if (basePrice <= 0 || gstPercent <= 0) return basePrice;
    return basePrice * (1 + (gstPercent / 100));
}

function setBreakdown(elementId, amount, gstPercent, gstType) {
    const el = document.getElementById(elementId);
    if (!el) return;

    if (amount > 0 && gstPercent > 0) {
        if (gstType === 'inclusive') {
            const basePrice = calculateBasePrice(amount, gstPercent);
            const gstAmount = calculateGstAmount(amount, gstPercent);
            el.innerHTML = `Base: ₹${basePrice.toFixed(2)} | GST: ₹${gstAmount.toFixed(2)} (${gstPercent}%)`;
        } else {
            const finalPrice = calculateFinalPrice(amount, gstPercent);
            const gstAmount = finalPrice - amount;
            el.innerHTML = `Base: ₹${amount.toFixed(2)} | GST: ₹${gstAmount.toFixed(2)} (${gstPercent}%) | Final: ₹${finalPrice.toFixed(2)}`;
        }
    } else {
        el.innerHTML = '';
    }
}

function updatePriceBreakdowns() {
    const gstPercent = parseFloat(document.getElementById('gst_percent')?.value) || 0;
    const gstType = document.getElementById('gst_type')?.value || 'inclusive';

    setBreakdown('purchase_breakdown', parseFloat(document.getElementById('purchase_price')?.value) || 0, gstPercent, gstType);
    setBreakdown('mrp_breakdown', parseFloat(document.getElementById('mrp')?.value) || 0, gstPercent, gstType);
    setBreakdown('selling_breakdown', parseFloat(document.getElementById('selling_price')?.value) || 0, gstPercent, gstType);
}

['purchase_price', 'mrp', 'selling_price', 'gst_percent'].forEach(function(id) {
    document.getElementById(id)?.addEventListener('input', updatePriceBreakdowns);
});
document.getElementById('gst_type')?.addEventListener('change', updatePriceBreakdowns);

document.getElementById('hsn_code_select')?.addEventListener('change', function() {
    const selected = this.options[this.selectedIndex];
    const tax = selected ? selected.getAttribute('data-tax') : '';
    const gstInput = document.getElementById('gst_percent');
    if (tax !== null && tax !== '' && gstInput) {
        gstInput.value = parseFloat(tax).toFixed(2);
        updatePriceBreakdowns();
    }
});

updatePriceBreakdowns();

function downloadSample() {
    let csvContent = "Product Name,Category,Product Type,Brand,Part Number,Item Code,HSN Code,Unit,Purchase Price,MRP,Selling Price,GST %,GST Type,Stock Qty,Min Stock Qty,Description\n";
    csvContent += "Brake Pad,Spare Parts,spare_part,Bosch,BP-123,SKU001,8708,pcs,500.00,750.00,650.00,18,inclusive,50,10,High quality brake pad\n";
    csvContent += "Engine Oil,Consumables,consumable,Castrol,,OIL001,2710,ltr,300.00,450.00,350.00,5,exclusive,100,20,Synthetic engine oil\n";
    csvContent += "Battery,Battery,battery,Exide,BAT-X,BT001,8507,unit,4500.00,6000.00,5000.00,28,inclusive,10,3,Maintenance free battery\n";

    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    const url = URL.createObjectURL(blob);

    link.setAttribute('href', url);
    link.setAttribute('download', 'sample_products.csv');
    link.style.visibility = 'hidden';

    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

<?php if ($showBulkModal): ?>
document.addEventListener('DOMContentLoaded', function() {
    new bootstrap.Modal(document.getElementById('bulkImportModal')).show();
});
<?php endif; ?>
</script>

</body>
</html>
