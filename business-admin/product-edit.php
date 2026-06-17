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

function qtyf($qty): string
{
    $qty = (float)$qty;
    if ((int)$qty == $qty) {
        return number_format($qty, 0);
    }
    return number_format($qty, 2);
}

function cleanNumber($value): float
{
    $value = trim((string)$value);
    if ($value === '') {
        return 0.00;
    }
    $value = str_replace(',', '', $value);
    $value = preg_replace('/[^0-9.\-]/', '', $value);
    return is_numeric($value) ? round((float)$value, 2) : 0.00;
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

function validateBranchId(mysqli $conn, int $businessId, int $branchId): ?int
{
    if ($branchId <= 0 || !tableExists($conn, 'branches')) {
        return null;
    }

    $stmt = $conn->prepare("SELECT id FROM branches WHERE id = ? AND business_id = ? LIMIT 1");
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('ii', $branchId, $businessId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ? (int)$row['id'] : null;
}

function firstActiveBranchId(mysqli $conn, int $businessId): ?int
{
    if (!tableExists($conn, 'branches')) {
        return null;
    }

    $stmt = $conn->prepare("SELECT id FROM branches WHERE business_id = ? AND status = 'active' ORDER BY id ASC LIMIT 1");
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $businessId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ? (int)$row['id'] : null;
}

function saveAuditLog(mysqli $conn, int $businessId, ?int $branchId, int $userId, int $productId, string $description): void
{
    if (!tableExists($conn, 'audit_logs')) {
        return;
    }

    /*
       IMPORTANT FIX:
       audit_logs.branch_id has a foreign key to branches.id.
       So we validate the branch. If it does not exist, we insert NULL.
       This prevents: Cannot add or update a child row fk_audit_logs_branch.
    */
    $safeBranchId = null;
    if ($branchId !== null && $branchId > 0) {
        $safeBranchId = validateBranchId($conn, $businessId, (int)$branchId);
    }

    $auditSql = "INSERT INTO audit_logs
                    (business_id, branch_id, user_id, action, module_name, ref_table, ref_id, description, created_at)
                 VALUES
                    (?, ?, ?, 'Edit Product', 'Products', 'products', ?, ?, NOW())";
    $auditStmt = $conn->prepare($auditSql);
    if (!$auditStmt) {
        return;
    }

    $auditStmt->bind_param('iiiis', $businessId, $safeBranchId, $userId, $productId, $description);
    $auditStmt->execute();
    $auditStmt->close();
}

function updateBranchStock(mysqli $conn, int $businessId, ?int $branchId, int $productId, float $qty, float $purchasePrice): void
{
    if ($branchId === null || $branchId <= 0 || !tableExists($conn, 'product_stock')) {
        return;
    }

    $safeBranchId = validateBranchId($conn, $businessId, $branchId);
    if ($safeBranchId === null) {
        return;
    }

    $existingId = 0;
    $stmt = $conn->prepare("SELECT id FROM product_stock WHERE business_id = ? AND branch_id = ? AND product_id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('iii', $businessId, $safeBranchId, $productId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $existingId = $row ? (int)$row['id'] : 0;
        $stmt->close();
    }

    if ($existingId > 0) {
        $stmt = $conn->prepare("UPDATE product_stock
                                SET qty_available = ?,
                                    last_purchase_price = ?,
                                    updated_at = NOW()
                                WHERE id = ? AND business_id = ?");
        if ($stmt) {
            $stmt->bind_param('ddii', $qty, $purchasePrice, $existingId, $businessId);
            $stmt->execute();
            $stmt->close();
        }
    } else {
        $stmt = $conn->prepare("INSERT INTO product_stock
                                    (business_id, branch_id, product_id, qty_available, last_purchase_price, updated_at)
                                VALUES
                                    (?, ?, ?, ?, ?, NOW())");
        if ($stmt) {
            $stmt->bind_param('iiidd', $businessId, $safeBranchId, $productId, $qty, $purchasePrice);
            $stmt->execute();
            $stmt->close();
        }
    }
}

/* -------------------------------------------------------
   OPTIONAL DB COMPATIBILITY
------------------------------------------------------- */
$hasGstTypeColumn = false;
if (tableExists($conn, 'products')) {
    $hasGstTypeColumn = columnExists($conn, 'products', 'gst_type');
    if (!$hasGstTypeColumn && columnExists($conn, 'products', 'gst_percent')) {
        @$conn->query("ALTER TABLE products ADD COLUMN gst_type ENUM('exclusive','inclusive') NOT NULL DEFAULT 'inclusive' AFTER gst_percent");
        $hasGstTypeColumn = columnExists($conn, 'products', 'gst_type');
    }
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
                                bu.branch_id,
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

$userRole = (string)($loggedUser['role'] ?? '');
$isSuperAdmin = ($userRole === 'super_admin');
$userBranchId = isset($loggedUser['branch_id']) ? (int)$loggedUser['branch_id'] : 0;
$sessionBranchId = isset($_SESSION['branch_id']) ? (int)$_SESSION['branch_id'] : 0;

/* -------------------------------------------------------
   LOAD BRANCHES
------------------------------------------------------- */
$branches = [];
if (tableExists($conn, 'branches')) {
    if ($isSuperAdmin) {
        $stmt = $conn->prepare("SELECT id, branch_name, branch_code, status
                                FROM branches
                                WHERE business_id = ? AND status = 'active'
                                ORDER BY branch_name ASC");
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
        $safeUserBranchId = validateBranchId($conn, $businessId, $userBranchId);
        if ($safeUserBranchId === null) {
            $safeUserBranchId = validateBranchId($conn, $businessId, $sessionBranchId);
        }
        if ($safeUserBranchId !== null) {
            $stmt = $conn->prepare("SELECT id, branch_name, branch_code, status
                                    FROM branches
                                    WHERE business_id = ? AND id = ?
                                    LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('ii', $businessId, $safeUserBranchId);
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

/* -------------------------------------------------------
   FETCH PRODUCT DETAILS
------------------------------------------------------- */
$productId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($productId <= 0) {
    header('Location: products.php');
    exit;
}

$stmt = $conn->prepare("SELECT * FROM products WHERE id = ? AND business_id = ? LIMIT 1");
$stmt->bind_param('ii', $productId, $businessId);
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$product) {
    header('Location: products.php');
    exit;
}

/* -------------------------------------------------------
   LOAD CATEGORIES
------------------------------------------------------- */
$categories = [];
if (tableExists($conn, 'product_categories')) {
    $res = $conn->query("SELECT id, category_name FROM product_categories ORDER BY category_name ASC");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $categories[] = $row;
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
   PRODUCT STOCK BRANCH DEFAULT
------------------------------------------------------- */
$currentBranchId = null;

if (tableExists($conn, 'product_stock')) {
    $stmt = $conn->prepare("SELECT branch_id FROM product_stock WHERE business_id = ? AND product_id = ? ORDER BY updated_at DESC, id DESC LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('ii', $businessId, $productId);
        $stmt->execute();
        $stockBranchRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($stockBranchRow) {
            $currentBranchId = validateBranchId($conn, $businessId, (int)$stockBranchRow['branch_id']);
        }
    }
}

if ($currentBranchId === null) {
    $currentBranchId = validateBranchId($conn, $businessId, $sessionBranchId);
}
if ($currentBranchId === null && $userBranchId > 0) {
    $currentBranchId = validateBranchId($conn, $businessId, $userBranchId);
}
if ($currentBranchId === null) {
    $currentBranchId = firstActiveBranchId($conn, $businessId);
}

/* -------------------------------------------------------
   UPDATE PRODUCT
------------------------------------------------------- */
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {
    $category_id    = (int)trim($_POST['category_id'] ?? 0);
    $product_type   = trim($_POST['product_type'] ?? 'spare_part');
    $product_name   = trim($_POST['product_name'] ?? '');
    $product_code   = trim($_POST['product_code'] ?? '');
    $brand_name     = trim($_POST['brand_name'] ?? '');
    $part_number    = trim($_POST['part_number'] ?? '');
    $item_code      = trim($_POST['item_code'] ?? '');
    $hsn_code       = trim($_POST['hsn_code'] ?? '');
    $unit_name      = trim($_POST['unit_name'] ?? '');
    $unit           = trim($_POST['unit'] ?? 'pcs');
    $purchase_price = cleanNumber($_POST['purchase_price'] ?? 0);
    $mrp            = cleanNumber($_POST['mrp'] ?? 0);
    $selling_price  = cleanNumber($_POST['selling_price'] ?? 0);
    $gst_percent    = cleanNumber($_POST['gst_percent'] ?? 0);
    $gst_type       = trim($_POST['gst_type'] ?? 'inclusive');
    $stock_qty      = cleanNumber($_POST['stock_qty'] ?? 0);
    $min_stock_qty  = cleanNumber($_POST['min_stock_qty'] ?? 0);
    $description    = trim($_POST['description'] ?? '');
    $status         = (int)trim($_POST['status'] ?? 1);
    $postedBranchId = (int)($_POST['branch_id'] ?? 0);

    if (!$isSuperAdmin) {
        $postedBranchId = $userBranchId > 0 ? $userBranchId : $sessionBranchId;
    }

    $selectedBranchId = validateBranchId($conn, $businessId, $postedBranchId);

    $allowedTypes = ['spare_part','accessory','consumable','lubricant','battery','tyre','helmet','other'];
    $allowedUnits = ['pcs','nos','box','set','ltr','ml','kg','gm','pair','unit'];
    $allowedStatus = [0, 1];
    $allowedGstTypes = ['inclusive', 'exclusive'];

    if ($category_id <= 0) {
        $error = 'Please select category.';
    } elseif ($product_name === '') {
        $error = 'Product name is required.';
    } elseif (!in_array($product_type, $allowedTypes, true)) {
        $error = 'Invalid product type.';
    } elseif (!in_array($unit, $allowedUnits, true)) {
        $error = 'Invalid unit selected.';
    } elseif (!in_array($gst_type, $allowedGstTypes, true)) {
        $error = 'Invalid GST type selected.';
    } elseif ($purchase_price < 0 || $mrp < 0 || $selling_price < 0 || $gst_percent < 0 || $stock_qty < 0 || $min_stock_qty < 0) {
        $error = 'Price, GST and stock values cannot be negative.';
    } elseif (!in_array($status, $allowedStatus, true)) {
        $error = 'Invalid status selected.';
    } elseif ($stock_qty > 0 && $selectedBranchId === null) {
        $error = 'Please select a valid branch for stock.';
    } else {
        if ($item_code === '') {
            $item_code = 'PROD' . $productId;

            $checkStmt = $conn->prepare("SELECT id FROM products WHERE business_id = ? AND item_code = ? AND id != ? LIMIT 1");
            if ($checkStmt) {
                $checkStmt->bind_param('isi', $businessId, $item_code, $productId);
                $checkStmt->execute();
                $dup = $checkStmt->get_result()->fetch_assoc();
                $checkStmt->close();

                if ($dup) {
                    $item_code = 'PROD' . $productId . '_' . rand(100, 999);
                }
            }
        } else {
            $checkStmt = $conn->prepare("SELECT id FROM products WHERE business_id = ? AND item_code = ? AND id != ? LIMIT 1");
            if ($checkStmt) {
                $checkStmt->bind_param('isi', $businessId, $item_code, $productId);
                $checkStmt->execute();
                $dup = $checkStmt->get_result()->fetch_assoc();
                $checkStmt->close();

                if ($dup) {
                    $error = 'Item code "' . h($item_code) . '" already exists for another product. Please use a different item code.';
                }
            }
        }
    }

    if ($error === '') {
        $conn->begin_transaction();

        try {
            if ($hasGstTypeColumn) {
                $stmt = $conn->prepare("UPDATE products SET
                                            category_id = ?,
                                            product_type = ?,
                                            product_name = ?,
                                            product_code = ?,
                                            brand_name = ?,
                                            part_number = ?,
                                            item_code = ?,
                                            hsn_code = ?,
                                            unit_name = ?,
                                            unit = ?,
                                            purchase_price = ?,
                                            mrp = ?,
                                            selling_price = ?,
                                            gst_percent = ?,
                                            gst_type = ?,
                                            stock_qty = ?,
                                            min_stock_qty = ?,
                                            description = ?,
                                            status = ?
                                        WHERE id = ? AND business_id = ?");
                if (!$stmt) {
                    throw new Exception('Unable to prepare update query: ' . $conn->error);
                }

                $stmt->bind_param(
                    'isssssssssddddsddsiii',
                    $category_id,
                    $product_type,
                    $product_name,
                    $product_code,
                    $brand_name,
                    $part_number,
                    $item_code,
                    $hsn_code,
                    $unit_name,
                    $unit,
                    $purchase_price,
                    $mrp,
                    $selling_price,
                    $gst_percent,
                    $gst_type,
                    $stock_qty,
                    $min_stock_qty,
                    $description,
                    $status,
                    $productId,
                    $businessId
                );
            } else {
                $stmt = $conn->prepare("UPDATE products SET
                                            category_id = ?,
                                            product_type = ?,
                                            product_name = ?,
                                            product_code = ?,
                                            brand_name = ?,
                                            part_number = ?,
                                            item_code = ?,
                                            hsn_code = ?,
                                            unit_name = ?,
                                            unit = ?,
                                            purchase_price = ?,
                                            mrp = ?,
                                            selling_price = ?,
                                            gst_percent = ?,
                                            stock_qty = ?,
                                            min_stock_qty = ?,
                                            description = ?,
                                            status = ?
                                        WHERE id = ? AND business_id = ?");
                if (!$stmt) {
                    throw new Exception('Unable to prepare update query: ' . $conn->error);
                }

                $stmt->bind_param(
                    'isssssssssddddddsiii',
                    $category_id,
                    $product_type,
                    $product_name,
                    $product_code,
                    $brand_name,
                    $part_number,
                    $item_code,
                    $hsn_code,
                    $unit_name,
                    $unit,
                    $purchase_price,
                    $mrp,
                    $selling_price,
                    $gst_percent,
                    $stock_qty,
                    $min_stock_qty,
                    $description,
                    $status,
                    $productId,
                    $businessId
                );
            }

            if (!$stmt->execute()) {
                throw new Exception($stmt->error);
            }
            $stmt->close();

            updateBranchStock($conn, $businessId, $selectedBranchId, $productId, $stock_qty, $purchase_price);

            $desc = 'Updated product: ' . $product_name;
            if ($selectedBranchId !== null) {
                $desc .= ' | Branch ID: ' . $selectedBranchId;
            }
            saveAuditLog($conn, $businessId, $selectedBranchId, $businessUserId, $productId, $desc);

            $conn->commit();

            $_SESSION['branch_id'] = $selectedBranchId ?? 0;
            $success = 'Product updated successfully.';
            $currentBranchId = $selectedBranchId;

            $stmt = $conn->prepare("SELECT * FROM products WHERE id = ? AND business_id = ? LIMIT 1");
            $stmt->bind_param('ii', $productId, $businessId);
            $stmt->execute();
            $product = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        } catch (Exception $e) {
            $conn->rollback();
            $error = 'Failed to update product: ' . $e->getMessage();
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

$pageTitle = 'Edit Product';
$currentPage = 'products';

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
    .info-row {
        padding: 12px 0;
        border-bottom: 1px solid #f1f5f9;
    }
    .info-row:last-child { border-bottom: 0; }
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
                                    <i class="ri-edit-box-line"></i>
                                </div>
                                <div>
                                    <h4 class="mb-1 text-white">Edit Product #<?php echo (int)$productId; ?></h4>
                                    <p class="subtitle">Update product details, GST pricing, branch stock, and status.</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-5 text-lg-end mt-3 mt-lg-0">
                            <a href="product-view.php?id=<?php echo (int)$productId; ?>" class="btn btn-light me-2">
                                <i class="ri-eye-line me-1"></i> View Product
                            </a>
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

                <?php if ($success !== ''): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="ri-checkbox-circle-line me-1"></i><?php echo h($success); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="ri-error-warning-line me-1"></i><?php echo h($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <form method="post" id="productForm" autocomplete="off">
                    <input type="hidden" name="action" value="update">

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
                                                    <option value="<?php echo (int)$cat['id']; ?>" <?php echo ((int)($product['category_id'] ?? 0) === (int)$cat['id']) ? 'selected' : ''; ?>>
                                                        <?php echo h($cat['category_name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Product Type <span class="text-danger">*</span></label>
                                            <select name="product_type" class="form-select" required>
                                                <?php foreach ($productTypes as $typeValue => $typeLabel): ?>
                                                    <option value="<?php echo h($typeValue); ?>" <?php echo (($product['product_type'] ?? '') === $typeValue) ? 'selected' : ''; ?>>
                                                        <?php echo h($typeLabel); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="col-md-8 mb-3">
                                            <label class="form-label">Product Name <span class="text-danger">*</span></label>
                                            <input type="text" name="product_name" class="form-control" value="<?php echo h($product['product_name'] ?? ''); ?>" required>
                                        </div>

                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">Product Code</label>
                                            <input type="text" name="product_code" class="form-control" value="<?php echo h($product['product_code'] ?? ''); ?>">
                                        </div>

                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">Brand Name</label>
                                            <input type="text" name="brand_name" class="form-control" value="<?php echo h($product['brand_name'] ?? ''); ?>">
                                        </div>

                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">Part Number</label>
                                            <input type="text" name="part_number" class="form-control" value="<?php echo h($product['part_number'] ?? ''); ?>">
                                        </div>

                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">Item Code / SKU</label>
                                            <input type="text" name="item_code" class="form-control" value="<?php echo h($product['item_code'] ?? ''); ?>" placeholder="Leave empty to auto-generate">
                                            <div class="form-text text-info">
                                                <i class="ri-information-line"></i> Leave empty to auto-generate PROD<?php echo (int)$productId; ?>.
                                            </div>
                                        </div>

                                        <div class="col-md-12 mb-0">
                                            <label class="form-label">Description</label>
                                            <textarea name="description" class="form-control" rows="3"><?php echo h($product['description'] ?? ''); ?></textarea>
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
                                                <input type="number" step="0.01" min="0" name="purchase_price" class="form-control price-input" value="<?php echo h($product['purchase_price'] ?? '0.00'); ?>" id="purchase_price">
                                            </div>
                                            <small class="price-breakdown" id="purchase_breakdown"></small>
                                        </div>

                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">MRP</label>
                                            <div class="input-group">
                                                <span class="input-group-text">₹</span>
                                                <input type="number" step="0.01" min="0" name="mrp" class="form-control price-input" value="<?php echo h($product['mrp'] ?? '0.00'); ?>" id="mrp">
                                            </div>
                                            <small class="price-breakdown" id="mrp_breakdown"></small>
                                        </div>

                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">Selling Price</label>
                                            <div class="input-group">
                                                <span class="input-group-text">₹</span>
                                                <input type="number" step="0.01" min="0" name="selling_price" class="form-control price-input" value="<?php echo h($product['selling_price'] ?? '0.00'); ?>" id="selling_price">
                                            </div>
                                            <small class="price-breakdown" id="selling_breakdown"></small>
                                        </div>

                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">GST %</label>
                                            <input type="number" step="0.01" min="0" name="gst_percent" class="form-control" id="gst_percent" value="<?php echo h($product['gst_percent'] ?? '0.00'); ?>">
                                        </div>

                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">GST Type</label>
                                            <?php $currentGstType = (string)($product['gst_type'] ?? 'inclusive'); ?>
                                            <select name="gst_type" class="form-select" id="gst_type">
                                                <option value="inclusive" <?php echo ($currentGstType === 'inclusive') ? 'selected' : ''; ?>>Inclusive</option>
                                                <option value="exclusive" <?php echo ($currentGstType === 'exclusive') ? 'selected' : ''; ?>>Exclusive</option>
                                            </select>
                                        </div>

                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">HSN Code</label>
                                            <?php if (!empty($hsnCodes)): ?>
                                                <select name="hsn_code" class="form-select" id="hsn_code_select">
                                                    <option value="">Select HSN Code</option>
                                                    <?php foreach ($hsnCodes as $hsn): ?>
                                                        <option value="<?php echo h($hsn['hsn_code']); ?>" data-tax="<?php echo h($hsn['tax_percent']); ?>" <?php echo ((string)($product['hsn_code'] ?? '') === (string)$hsn['hsn_code']) ? 'selected' : ''; ?>>
                                                            <?php echo h($hsn['hsn_code']); ?><?php echo ($hsn['description'] !== '') ? ' - ' . h($hsn['description']) : ''; ?> (<?php echo h($hsn['tax_percent']); ?>%)
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            <?php else: ?>
                                                <input type="text" name="hsn_code" class="form-control" value="<?php echo h($product['hsn_code'] ?? ''); ?>">
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-xl-4">
                            <div class="card form-section-card mb-4">
                                <div class="card-header">
                                    <h5 class="section-title"><i class="ri-stack-line"></i> Branch, Stock & Unit</h5>
                                </div>
                                <div class="card-body">
                                    <div class="mb-3">
                                        <label class="form-label">Branch <span class="text-danger">*</span></label>
                                        <select name="branch_id" class="form-select" <?php echo !$isSuperAdmin ? 'disabled' : ''; ?> required>
                                            <option value="">Select Branch</option>
                                            <?php foreach ($branches as $branch): ?>
                                                <option value="<?php echo (int)$branch['id']; ?>" <?php echo ((int)$currentBranchId === (int)$branch['id']) ? 'selected' : ''; ?>>
                                                    <?php echo h($branch['branch_name']); ?><?php echo !empty($branch['branch_code']) ? ' (' . h($branch['branch_code']) . ')' : ''; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <?php if (!$isSuperAdmin): ?>
                                            <input type="hidden" name="branch_id" value="<?php echo (int)($currentBranchId ?? 0); ?>">
                                            <div class="form-text">Your login is locked to this branch.</div>
                                        <?php else: ?>
                                            <div class="form-text">Stock update and audit log will use this branch.</div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Unit Name</label>
                                        <input type="text" name="unit_name" class="form-control" value="<?php echo h($product['unit_name'] ?? ''); ?>" placeholder="Example: Pieces">
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Unit <span class="text-danger">*</span></label>
                                        <select name="unit" class="form-select" required>
                                            <?php foreach ($units as $unitValue => $unitLabel): ?>
                                                <option value="<?php echo h($unitValue); ?>" <?php echo (($product['unit'] ?? '') === $unitValue) ? 'selected' : ''; ?>><?php echo h($unitLabel); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-6 col-xl-12 mb-3">
                                            <label class="form-label">Current Stock Qty</label>
                                            <input type="number" step="0.01" min="0" name="stock_qty" class="form-control" value="<?php echo h($product['stock_qty'] ?? '0.00'); ?>">
                                            <div class="form-text">This also updates selected branch stock.</div>
                                        </div>

                                        <div class="col-md-6 col-xl-12 mb-3">
                                            <label class="form-label">Min Stock Qty</label>
                                            <input type="number" step="0.01" min="0" name="min_stock_qty" class="form-control" value="<?php echo h($product['min_stock_qty'] ?? '0.00'); ?>">
                                        </div>
                                    </div>

                                    <div class="mb-0">
                                        <label class="form-label">Status</label>
                                        <select name="status" class="form-select">
                                            <option value="1" <?php echo ((int)($product['status'] ?? 1) === 1) ? 'selected' : ''; ?>>Active</option>
                                            <option value="0" <?php echo ((int)($product['status'] ?? 1) === 0) ? 'selected' : ''; ?>>Inactive</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div class="card form-section-card mb-4">
                                <div class="card-header">
                                    <h5 class="section-title"><i class="ri-information-line"></i> Product Information</h5>
                                </div>
                                <div class="card-body">
                                    <div class="info-row">
                                        <p class="text-muted mb-1">Created At</p>
                                        <strong><?php echo !empty($product['created_at']) ? date('d M Y h:i A', strtotime($product['created_at'])) : '-'; ?></strong>
                                    </div>
                                    <div class="info-row">
                                        <p class="text-muted mb-1">Product ID</p>
                                        <strong>#<?php echo (int)$productId; ?></strong>
                                    </div>
                                    <div class="info-row">
                                        <p class="text-muted mb-1">Current Item Code</p>
                                        <strong><?php echo h($product['item_code'] ?: 'Not set'); ?></strong>
                                    </div>
                                    <div class="info-row">
                                        <p class="text-muted mb-1">Status</p>
                                        <?php if ((int)($product['status'] ?? 1) === 1): ?>
                                            <span class="badge bg-success">Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning">Inactive</span>
                                        <?php endif; ?>
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
                            <a href="product-view.php?id=<?php echo (int)$productId; ?>" class="btn btn-info">
                                <i class="ri-eye-line me-1"></i> View Product
                            </a>
                            <button type="submit" class="btn btn-primary px-4">
                                <i class="ri-save-3-line me-1"></i> Update Product
                            </button>
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

setTimeout(function() {
    document.querySelectorAll('.alert').forEach(function(alert) {
        if (window.bootstrap && bootstrap.Alert) {
            const bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        }
    });
}, 5000);
</script>

</body>
</html>
