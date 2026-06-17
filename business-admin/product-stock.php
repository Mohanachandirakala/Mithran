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

function qtyf($qty): string
{
    $qty = (float)$qty;
    if ((int)$qty == $qty) {
        return number_format($qty, 0);
    }
    return number_format($qty, 2);
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
$requiredTables = ['product_stock', 'products', 'branches'];
foreach ($requiredTables as $tbl) {
    if (!tableExists($conn, $tbl)) {
        die($tbl . ' table not found.');
    }
}

$hasProductCategories = tableExists($conn, 'product_categories');
$hasHsnCodes = tableExists($conn, 'hsn_codes');
$hasTaxSettings = tableExists($conn, 'tax_settings');

/* -------------------------------------------------------
   MASTER DATA
------------------------------------------------------- */
// Get all branches for dropdowns
$branches = fetchAllAssoc($conn, "SELECT id, branch_name, branch_code
                                  FROM branches
                                  WHERE business_id = {$businessId} AND status = 'active'
                                  ORDER BY branch_name ASC");

// Get current branch name for display
$currentBranchName = '';
foreach ($branches as $b) {
    if ((int)$b['id'] === $currentBranchId) {
        $currentBranchName = $b['branch_name'];
        break;
    }
}

// Get all active products for dropdowns with complete details
$products = fetchAllAssoc($conn, "SELECT 
                                      p.id,
                                      p.product_name,
                                      p.product_code,
                                      p.item_code,
                                      p.brand_name,
                                      p.part_number,
                                      p.selling_price,
                                      p.purchase_price,
                                      p.mrp,
                                      p.unit,
                                      p.unit_name,
                                      p.min_stock_qty,
                                      p.category_id,
                                      p.product_type,
                                      p.status,
                                      p.gst_percent,
                                      p.hsn_code,
                                      p.description,
                                      " . ($hasProductCategories ? "pc.category_name" : "NULL AS category_name") . "
                                  FROM products p
                                  " . ($hasProductCategories ? "LEFT JOIN product_categories pc ON pc.id = p.category_id" : "") . "
                                  WHERE p.business_id = {$businessId} AND p.status = 1
                                  ORDER BY p.product_name ASC");

// Get product categories for filter
$categories = [];
if ($hasProductCategories) {
    $categories = fetchAllAssoc($conn, "SELECT id, category_name FROM product_categories ORDER BY category_name ASC");
}

/* -------------------------------------------------------
   ACTIONS
------------------------------------------------------- */
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');

    if ($action === 'add' || $action === 'edit') {
        $id                  = (int)($_POST['id'] ?? 0);
        $branch_id           = (int)($_POST['branch_id'] ?? 0);
        $product_id          = (int)($_POST['product_id'] ?? 0);
        $qty_available       = (float)($_POST['qty_available'] ?? 0);
        $last_purchase_price = (float)($_POST['last_purchase_price'] ?? 0);

        if ($branch_id <= 0) {
            $error = 'Please select branch.';
        } elseif ($product_id <= 0) {
            $error = 'Please select product.';
        } elseif ($qty_available < 0) {
            $error = 'Quantity cannot be negative.';
        } elseif ($last_purchase_price < 0) {
            $error = 'Last purchase price cannot be negative.';
        } else {
            // Verify branch belongs to business
            $stmt = $conn->prepare("SELECT id FROM branches WHERE id = ? AND business_id = ? AND status = 'active' LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('ii', $branch_id, $businessId);
                $stmt->execute();
                $branchCheck = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$branchCheck) {
                    $error = 'Invalid branch selected.';
                }
            }

            if ($error === '') {
                // Verify product belongs to business and is active
                $stmt = $conn->prepare("SELECT id, purchase_price, selling_price, product_name FROM products WHERE id = ? AND business_id = ? AND status = 1 LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param('ii', $product_id, $businessId);
                    $stmt->execute();
                    $productCheck = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    if (!$productCheck) {
                        $error = 'Invalid or inactive product selected.';
                    } else {
                        // If last purchase price is 0, use product's purchase price
                        if ($last_purchase_price == 0 && $productCheck['purchase_price'] > 0) {
                            $last_purchase_price = (float)$productCheck['purchase_price'];
                        }
                    }
                }
            }

            if ($error === '' && $action === 'add') {
                // Check for duplicate
                $stmt = $conn->prepare("SELECT id FROM product_stock WHERE branch_id = ? AND product_id = ? LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param('ii', $branch_id, $product_id);
                    $stmt->execute();
                    $dup = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    if ($dup) {
                        $error = 'This product already exists in the selected branch stock. Please edit existing stock instead.';
                    }
                }
            }

            if ($error === '' && $action === 'edit') {
                // Check for duplicate excluding current record
                $stmt = $conn->prepare("SELECT id FROM product_stock WHERE branch_id = ? AND product_id = ? AND id != ? LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param('iii', $branch_id, $product_id, $id);
                    $stmt->execute();
                    $dup = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    if ($dup) {
                        $error = 'This product already exists in the selected branch stock.';
                    }
                }
            }
        }

        if ($error === '' && $action === 'add') {
            $stmt = $conn->prepare("INSERT INTO product_stock (
                                        business_id,
                                        branch_id,
                                        product_id,
                                        qty_available,
                                        last_purchase_price,
                                        updated_at
                                    ) VALUES (
                                        ?, ?, ?, ?, ?, NOW()
                                    )");
            if ($stmt) {
                $stmt->bind_param(
                    'iiidd',
                    $businessId,
                    $branch_id,
                    $product_id,
                    $qty_available,
                    $last_purchase_price
                );

                if ($stmt->execute()) {
                    $success = 'Product stock added successfully.';
                    
                    // Log to audit
                    if (tableExists($conn, 'audit_logs')) {
                        $newId = $stmt->insert_id;
                        $productName = $productCheck['product_name'] ?? 'Unknown';
                        $auditSql = "INSERT INTO audit_logs (business_id, branch_id, user_id, action, module_name, ref_table, ref_id, description) 
                                     VALUES (?, ?, ?, 'Add Stock', 'Product Stock', 'product_stock', ?, ?)";
                        $auditStmt = $conn->prepare($auditSql);
                        if ($auditStmt) {
                            $desc = "Added product stock for product: " . $productName . " (ID: " . $product_id . ", Qty: " . $qty_available . ")";
                            $auditStmt->bind_param("iiiis", $businessId, $branch_id, $businessUserId, $newId, $desc);
                            $auditStmt->execute();
                            $auditStmt->close();
                        }
                    }
                    
                    // Record stock movement
                    if (tableExists($conn, 'stock_movements') && $qty_available > 0) {
                        $movementSql = "INSERT INTO stock_movements (business_id, branch_id, item_type, item_id, movement_type, qty, unit_price, movement_date, created_by, created_at) 
                                        VALUES (?, ?, 'product', ?, 'purchase', ?, ?, NOW(), ?, NOW())";
                        $movementStmt = $conn->prepare($movementSql);
                        if ($movementStmt) {
                            $movementStmt->bind_param("iiidii", $businessId, $branch_id, $product_id, $qty_available, $last_purchase_price, $businessUserId);
                            $movementStmt->execute();
                            $movementStmt->close();
                        }
                    }
                } else {
                    $error = 'Failed to add product stock: ' . $conn->error;
                }
                $stmt->close();
            } else {
                $error = 'Unable to prepare insert query.';
            }
        }

        if ($error === '' && $action === 'edit') {
            if ($id <= 0) {
                $error = 'Invalid stock id.';
            } else {
                // Get old quantity for movement tracking
                $oldQty = 0;
                $oldStmt = $conn->prepare("SELECT qty_available FROM product_stock WHERE id = ? AND business_id = ? LIMIT 1");
                if ($oldStmt) {
                    $oldStmt->bind_param('ii', $id, $businessId);
                    $oldStmt->execute();
                    $oldResult = $oldStmt->get_result()->fetch_assoc();
                    if ($oldResult) {
                        $oldQty = (float)$oldResult['qty_available'];
                    }
                    $oldStmt->close();
                }
                
                $stmt = $conn->prepare("UPDATE product_stock SET
                                            branch_id = ?,
                                            product_id = ?,
                                            qty_available = ?,
                                            last_purchase_price = ?,
                                            updated_at = NOW()
                                        WHERE id = ? AND business_id = ?
                                        LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param(
                        'iiddii',
                        $branch_id,
                        $product_id,
                        $qty_available,
                        $last_purchase_price,
                        $id,
                        $businessId
                    );

                    if ($stmt->execute()) {
                        $success = 'Product stock updated successfully.';
                        
                        // Log to audit
                        if (tableExists($conn, 'audit_logs')) {
                            $productName = $productCheck['product_name'] ?? 'Unknown';
                            $auditSql = "INSERT INTO audit_logs (business_id, branch_id, user_id, action, module_name, ref_table, ref_id, description) 
                                         VALUES (?, ?, ?, 'Edit Stock', 'Product Stock', 'product_stock', ?, ?)";
                            $auditStmt = $conn->prepare($auditSql);
                            if ($auditStmt) {
                                $desc = "Updated product stock ID: " . $id . " (Product: " . $productName . ", Qty: " . $oldQty . " → " . $qty_available . ")";
                                $auditStmt->bind_param("iiiis", $businessId, $branch_id, $businessUserId, $id, $desc);
                                $auditStmt->execute();
                                $auditStmt->close();
                            }
                        }
                        
                        // Record stock movement if quantity changed
                        if (tableExists($conn, 'stock_movements') && $qty_available != $oldQty) {
                            $diffQty = $qty_available - $oldQty;
                            $movementType = $diffQty > 0 ? 'adjustment' : 'adjustment';
                            $movementSql = "INSERT INTO stock_movements (business_id, branch_id, item_type, item_id, movement_type, qty, unit_price, movement_date, notes, created_by, created_at) 
                                            VALUES (?, ?, 'product', ?, ?, ?, ?, NOW?, ?, NOW())";
                            $movementStmt = $conn->prepare($movementSql);
                            if ($movementStmt) {
                                $notes = "Stock adjustment from " . $oldQty . " to " . $qty_available;
                                $movementStmt->bind_param("iiisddiss", $businessId, $branch_id, $product_id, $movementType, abs($diffQty), $last_purchase_price, $notes, $businessUserId);
                                $movementStmt->execute();
                                $movementStmt->close();
                            }
                        }
                    } else {
                        $error = 'Failed to update product stock: ' . $conn->error;
                    }
                    $stmt->close();
                } else {
                    $error = 'Unable to prepare update query.';
                }
            }
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);

        if ($id <= 0) {
            $error = 'Invalid stock id.';
        } else {
            // Get stock info before deletion
            $stockInfo = null;
            $infoStmt = $conn->prepare("SELECT ps.*, p.product_name FROM product_stock ps INNER JOIN products p ON p.id = ps.product_id WHERE ps.id = ? AND ps.business_id = ? LIMIT 1");
            if ($infoStmt) {
                $infoStmt->bind_param('ii', $id, $businessId);
                $infoStmt->execute();
                $stockInfo = $infoStmt->get_result()->fetch_assoc();
                $infoStmt->close();
            }
            
            // Check if stock has been used in any transactions
            $hasTransactions = false;
            if (tableExists($conn, 'stock_movements')) {
                $checkSql = "SELECT id FROM stock_movements WHERE item_type = 'product' AND item_id = ? LIMIT 1";
                $checkStmt = $conn->prepare($checkSql);
                if ($checkStmt) {
                    $productId = $stockInfo['product_id'] ?? 0;
                    $checkStmt->bind_param('i', $productId);
                    $checkStmt->execute();
                    $hasTransactions = $checkStmt->get_result()->num_rows > 0;
                    $checkStmt->close();
                }
            }
            
            // Also check sales invoice items
            if (!$hasTransactions && tableExists($conn, 'sales_invoice_items')) {
                $checkSql = "SELECT id FROM sales_invoice_items WHERE product_id = ? LIMIT 1";
                $checkStmt = $conn->prepare($checkSql);
                if ($checkStmt) {
                    $productId = $stockInfo['product_id'] ?? 0;
                    $checkStmt->bind_param('i', $productId);
                    $checkStmt->execute();
                    $hasTransactions = $checkStmt->get_result()->num_rows > 0;
                    $checkStmt->close();
                }
            }
            
            // Check service job part items
            if (!$hasTransactions && tableExists($conn, 'service_job_part_items')) {
                $checkSql = "SELECT id FROM service_job_part_items WHERE product_id = ? LIMIT 1";
                $checkStmt = $conn->prepare($checkSql);
                if ($checkStmt) {
                    $productId = $stockInfo['product_id'] ?? 0;
                    $checkStmt->bind_param('i', $productId);
                    $checkStmt->execute();
                    $hasTransactions = $checkStmt->get_result()->num_rows > 0;
                    $checkStmt->close();
                }
            }
            
            if ($hasTransactions) {
                $error = 'Cannot delete stock that has transaction history. You can only set quantity to 0.';
            } else {
                $stmt = $conn->prepare("DELETE FROM product_stock WHERE id = ? AND business_id = ? LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param('ii', $id, $businessId);
                    if ($stmt->execute()) {
                        $success = 'Product stock deleted successfully.';
                        
                        // Log to audit
                        if (tableExists($conn, 'audit_logs')) {
                            $auditSql = "INSERT INTO audit_logs (business_id, branch_id, user_id, action, module_name, ref_table, ref_id, description) 
                                         VALUES (?, ?, ?, 'Delete Stock', 'Product Stock', 'product_stock', ?, ?)";
                            $auditStmt = $conn->prepare($auditSql);
                            if ($auditStmt) {
                                $productName = $stockInfo['product_name'] ?? 'Unknown';
                                $desc = "Deleted product stock for: " . $productName . " (ID: " . $id . ")";
                                $auditStmt->bind_param("iiiis", $businessId, $currentBranchId, $businessUserId, $id, $desc);
                                $auditStmt->execute();
                                $auditStmt->close();
                            }
                        }
                    } else {
                        $error = 'Failed to delete product stock.';
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
$branchFilter = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : $currentBranchId;
$productFilter = (int)($_GET['product_id'] ?? 0);
$categoryFilter = (int)($_GET['category_id'] ?? 0);
$productTypeFilter = trim($_GET['product_type'] ?? '');
$lowStockFilter = trim($_GET['low_stock'] ?? '');

$where = ["ps.business_id = {$businessId}"];

if ($search !== '') {
    $safe = $conn->real_escape_string($search);
    $where[] = "(
        p.product_name LIKE '%{$safe}%'
        OR p.item_code LIKE '%{$safe}%'
        OR p.product_code LIKE '%{$safe}%'
        OR p.brand_name LIKE '%{$safe}%'
        OR p.part_number LIKE '%{$safe}%'
        OR p.hsn_code LIKE '%{$safe}%'
        " . ($hasProductCategories ? "OR pc.category_name LIKE '%{$safe}%'" : "") . "
        OR br.branch_name LIKE '%{$safe}%'
    )";
}

if ($branchFilter > 0) {
    $where[] = "ps.branch_id = {$branchFilter}";
}

if ($productFilter > 0) {
    $where[] = "ps.product_id = {$productFilter}";
}

if ($categoryFilter > 0 && $hasProductCategories) {
    $where[] = "p.category_id = {$categoryFilter}";
}

if ($productTypeFilter !== '') {
    $safeType = $conn->real_escape_string($productTypeFilter);
    $where[] = "p.product_type = '{$safeType}'";
}

if ($lowStockFilter === 'yes') {
    $where[] = "ps.qty_available <= p.min_stock_qty AND ps.qty_available > 0";
} elseif ($lowStockFilter === 'out') {
    $where[] = "ps.qty_available <= 0";
} elseif ($lowStockFilter === 'positive') {
    $where[] = "ps.qty_available > 0";
}

$whereSql = implode(' AND ', $where);

/* -------------------------------------------------------
   SUMMARY (based on selected branch or all branches)
------------------------------------------------------- */
$summaryWhere = "business_id = {$businessId}";
if ($branchFilter > 0) {
    $summaryWhere .= " AND branch_id = {$branchFilter}";
}

$totalStockRows = getCount($conn, 'product_stock', $summaryWhere);

$lowStockRows = 0;
$res = $conn->query("SELECT COUNT(*) AS total
                     FROM product_stock ps
                     INNER JOIN products p ON p.id = ps.product_id
                     WHERE ps.business_id = {$businessId}
                       " . ($branchFilter > 0 ? "AND ps.branch_id = {$branchFilter}" : "") . "
                       AND ps.qty_available <= p.min_stock_qty
                       AND ps.qty_available > 0");
if ($res) {
    $row = $res->fetch_assoc();
    $lowStockRows = (int)($row['total'] ?? 0);
}

$outOfStockRows = 0;
$res = $conn->query("SELECT COUNT(*) AS total
                     FROM product_stock
                     WHERE business_id = {$businessId}
                       " . ($branchFilter > 0 ? "AND branch_id = {$branchFilter}" : "") . "
                       AND qty_available <= 0");
if ($res) {
    $row = $res->fetch_assoc();
    $outOfStockRows = (int)($row['total'] ?? 0);
}

$positiveStockRows = 0;
$res = $conn->query("SELECT COUNT(*) AS total
                     FROM product_stock
                     WHERE business_id = {$businessId}
                       " . ($branchFilter > 0 ? "AND branch_id = {$branchFilter}" : "") . "
                       AND qty_available > 0");
if ($res) {
    $row = $res->fetch_assoc();
    $positiveStockRows = (int)($row['total'] ?? 0);
}

$totalStockValue = 0;
$res = $conn->query("SELECT SUM(ps.qty_available * COALESCE(ps.last_purchase_price, p.purchase_price, 0)) AS total_value
                     FROM product_stock ps
                     INNER JOIN products p ON p.id = ps.product_id
                     WHERE ps.business_id = {$businessId}
                     " . ($branchFilter > 0 ? "AND ps.branch_id = {$branchFilter}" : ""));
if ($res) {
    $row = $res->fetch_assoc();
    $totalStockValue = (float)($row['total_value'] ?? 0);
}

/* -------------------------------------------------------
   FETCH STOCK
------------------------------------------------------- */
$stockRows = [];

$sql = "SELECT
            ps.id,
            ps.business_id,
            ps.branch_id,
            ps.product_id,
            ps.qty_available,
            ps.last_purchase_price,
            ps.updated_at,
            p.product_name,
            p.item_code,
            p.product_code,
            p.brand_name,
            p.part_number,
            p.unit,
            p.unit_name,
            p.min_stock_qty,
            p.selling_price,
            p.purchase_price,
            p.mrp,
            p.product_type,
            p.status AS product_status,
            p.gst_percent,
            p.hsn_code,
            p.description,
            " . ($hasProductCategories ? "pc.category_name" : "NULL AS category_name") . ",
            br.branch_name,
            br.branch_code
        FROM product_stock ps
        INNER JOIN products p ON p.id = ps.product_id
        " . ($hasProductCategories ? "LEFT JOIN product_categories pc ON pc.id = p.category_id" : "") . "
        LEFT JOIN branches br ON br.id = ps.branch_id
        WHERE {$whereSql}
        ORDER BY 
            CASE 
                WHEN ps.qty_available <= 0 THEN 1
                WHEN ps.qty_available <= p.min_stock_qty THEN 2
                ELSE 3
            END,
            p.product_name ASC";

$res = $conn->query($sql);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $row['stock_value'] = (float)$row['qty_available'] * (float)($row['last_purchase_price'] ?: $row['purchase_price'] ?: 0);
        $stockRows[] = $row;
    }
}

$pageTitle = 'Product Stock';
$currentPage = 'product-stock';
?>
<!doctype html>
<html lang="en">

<?php include('includes/head.php'); ?>

<body data-sidebar="dark">

<style>
    .page-content {
        padding-bottom: 90px !important;
    }
    .card {
        margin-bottom: 24px;
    }
    .table-responsive {
        overflow-x: auto;
    }
    .main-content {
        min-height: calc(100vh - 70px);
    }
    .stock-badge {
        font-size: 0.75rem;
        padding: 3px 8px;
    }
    .summary-card {
        transition: transform 0.2s;
        cursor: pointer;
    }
    .summary-card:hover {
        transform: translateY(-2px);
    }
    .summary-card.active {
        border: 2px solid #fff;
        box-shadow: 0 0 0 2px rgba(255,255,255,0.5);
    }
    .product-type-badge {
        font-size: 0.7rem;
        padding: 2px 6px;
    }
    .stock-value-cell {
        font-weight: 600;
        color: #2c7da0;
    }
    .filter-section {
        background: #f8f9fa;
        border-radius: 8px;
        padding: 15px;
    }
    .action-buttons {
        white-space: nowrap;
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

                <!-- Page Header -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="d-flex flex-wrap align-items-center justify-content-between">
                            <div>
                                <h4 class="mb-1">Product Stock Management</h4>
                                <p class="text-muted mb-0">
                                    Manage product stock across all branches
                                    <?php if ($branchFilter > 0 && !empty($currentBranchName)): ?>
                                        <span class="badge bg-info ms-2">Current Branch: <?php echo h($currentBranchName); ?></span>
                                    <?php endif; ?>
                                </p>
                            </div>
                            <div class="mt-3 mt-sm-0">
                                <a href="products.php" class="btn btn-outline-primary me-2">
                                    <i class="ri-shopping-bag-line me-1"></i> Manage Products
                                </a>
                                <a href="stock-movements.php" class="btn btn-outline-secondary">
                                    <i class="ri-history-line me-1"></i> Stock Movements
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Alert Messages -->
                <?php if ($success !== ''): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="ri-checkbox-circle-line me-2"></i> <?php echo h($success); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="ri-error-warning-line me-2"></i> <?php echo h($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <!-- SUMMARY CARDS -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card summary-card bg-primary text-white" onclick="filterByStock('all')">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0 me-3">
                                        <i class="ri-stack-line font-size-32"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="mb-1 opacity-75">Total Stock Items</p>
                                        <h3 class="mb-0"><?php echo number_format($totalStockRows); ?></h3>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card summary-card bg-success text-white" onclick="filterByStock('positive')">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0 me-3">
                                        <i class="ri-checkbox-circle-line font-size-32"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="mb-1 opacity-75">In Stock</p>
                                        <h3 class="mb-0"><?php echo number_format($positiveStockRows); ?></h3>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card summary-card bg-warning text-white" onclick="filterByStock('low')">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0 me-3">
                                        <i class="ri-alert-line font-size-32"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="mb-1 opacity-75">Low Stock</p>
                                        <h3 class="mb-0"><?php echo number_format($lowStockRows); ?></h3>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card summary-card bg-danger text-white" onclick="filterByStock('out')">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0 me-3">
                                        <i class="ri-close-circle-line font-size-32"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="mb-1 opacity-75">Out of Stock</p>
                                        <h3 class="mb-0"><?php echo number_format($outOfStockRows); ?></h3>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- STOCK VALUE CARD -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card bg-light">
                            <div class="card-body">
                                <div class="d-flex align-items-center justify-content-between flex-wrap">
                                    <div>
                                        <h5 class="mb-1">Total Stock Value</h5>
                                        <p class="text-muted mb-0">
                                            Based on last purchase price 
                                            <?php echo $branchFilter > 0 ? '(' . h($currentBranchName) . ' Branch)' : '(All Branches)'; ?>
                                        </p>
                                    </div>
                                    <div class="mt-2 mt-sm-0">
                                        <h2 class="mb-0 text-primary"><?php echo money($totalStockValue); ?></h2>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ADD STOCK FORM -->
                <div class="card mb-4">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0">
                            <i class="ri-add-circle-line me-2"></i>Add New Product Stock
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="post" class="row g-3">
                            <input type="hidden" name="action" value="add">

                            <div class="col-md-3">
                                <label class="form-label">Branch <span class="text-danger">*</span></label>
                                <select name="branch_id" class="form-select" required>
                                    <option value="">Select Branch</option>
                                    <?php foreach ($branches as $b): ?>
                                        <option value="<?php echo (int)$b['id']; ?>" <?php echo ($branchFilter === (int)$b['id']) ? 'selected' : ''; ?>>
                                            <?php echo h($b['branch_name'] . ' (' . $b['branch_code'] . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label">Product <span class="text-danger">*</span></label>
                                <select name="product_id" class="form-select" required>
                                    <option value="">Select Product</option>
                                    <?php foreach ($products as $p): ?>
                                        <option value="<?php echo (int)$p['id']; ?>">
                                            <?php
                                            echo h(
                                                $p['product_name'] .
                                                (!empty($p['product_code']) ? ' [' . $p['product_code'] . ']' : '') .
                                                (!empty($p['brand_name']) ? ' - ' . $p['brand_name'] : '')
                                            );
                                            ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">Quantity</label>
                                <input type="number" step="0.01" min="0" name="qty_available" class="form-control" value="0" placeholder="0.00">
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">Last Purchase Price (₹)</label>
                                <input type="number" step="0.01" min="0" name="last_purchase_price" class="form-control" value="0.00" placeholder="0.00">
                                <small class="text-muted">Leave 0 to use product's purchase price</small>
                            </div>

                            <div class="col-12">
                                <button type="submit" class="btn btn-primary">
                                    <i class="ri-save-line me-1"></i> Add Stock
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- FILTERS SECTION -->
                <div class="card mb-4">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0">
                            <i class="ri-filter-line me-2"></i>Filter Stock
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="get" class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label">Search</label>
                                <input type="text" name="search" class="form-control" placeholder="Product, code, brand..." value="<?php echo h($search); ?>">
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">Branch</label>
                                <select name="branch_id" class="form-select">
                                    <option value="0">All Branches</option>
                                    <?php foreach ($branches as $b): ?>
                                        <option value="<?php echo (int)$b['id']; ?>" <?php echo ($branchFilter === (int)$b['id']) ? 'selected' : ''; ?>>
                                            <?php echo h($b['branch_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <?php if ($hasProductCategories && !empty($categories)): ?>
                            <div class="col-md-2">
                                <label class="form-label">Category</label>
                                <select name="category_id" class="form-select">
                                    <option value="0">All Categories</option>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?php echo (int)$cat['id']; ?>" <?php echo ($categoryFilter === (int)$cat['id']) ? 'selected' : ''; ?>>
                                            <?php echo h($cat['category_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>

                            <div class="col-md-2">
                                <label class="form-label">Product Type</label>
                                <select name="product_type" class="form-select">
                                    <option value="">All Types</option>
                                    <option value="spare_part" <?php echo ($productTypeFilter === 'spare_part') ? 'selected' : ''; ?>>Spare Part</option>
                                    <option value="accessory" <?php echo ($productTypeFilter === 'accessory') ? 'selected' : ''; ?>>Accessory</option>
                                    <option value="consumable" <?php echo ($productTypeFilter === 'consumable') ? 'selected' : ''; ?>>Consumable</option>
                                    <option value="lubricant" <?php echo ($productTypeFilter === 'lubricant') ? 'selected' : ''; ?>>Lubricant</option>
                                    <option value="battery" <?php echo ($productTypeFilter === 'battery') ? 'selected' : ''; ?>>Battery</option>
                                    <option value="tyre" <?php echo ($productTypeFilter === 'tyre') ? 'selected' : ''; ?>>Tyre</option>
                                    <option value="helmet" <?php echo ($productTypeFilter === 'helmet') ? 'selected' : ''; ?>>Helmet</option>
                                    <option value="other" <?php echo ($productTypeFilter === 'other') ? 'selected' : ''; ?>>Other</option>
                                </select>
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">Stock Level</label>
                                <select name="low_stock" class="form-select">
                                    <option value="">All Stock Levels</option>
                                    <option value="positive" <?php echo ($lowStockFilter === 'positive') ? 'selected' : ''; ?>>In Stock Only</option>
                                    <option value="yes" <?php echo ($lowStockFilter === 'yes') ? 'selected' : ''; ?>>Low Stock Only</option>
                                    <option value="out" <?php echo ($lowStockFilter === 'out') ? 'selected' : ''; ?>>Out of Stock</option>
                                </select>
                            </div>

                            <div class="col-md-1 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="ri-filter-line"></i> Filter
                                </button>
                            </div>
                            
                            <?php if ($search !== '' || $branchFilter > 0 || $categoryFilter > 0 || $productTypeFilter !== '' || $lowStockFilter !== ''): ?>
                            <div class="col-md-12 mt-2">
                                <a href="product-stock.php" class="btn btn-sm btn-outline-secondary">
                                    <i class="ri-close-line me-1"></i> Clear All Filters
                                </a>
                            </div>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>

                <!-- STOCK LIST TABLE -->
                <div class="card">
                    <div class="card-header bg-light d-flex justify-content-between align-items-center flex-wrap">
                        <h5 class="card-title mb-0">
                            <i class="ri-list-check me-2"></i>Product Stock List
                        </h5>
                        <span class="badge bg-info mt-2 mt-sm-0"><?php echo count($stockRows); ?> items found</span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 40px;">#</th>
                                        <th>Product Details</th>
                                        <th>Branch</th>
                                        <th>Category</th>
                                        <th>Type</th>
                                        <th>Stock Info</th>
                                        <th>Price Info</th>
                                        <th>Stock Value</th>
                                        <th style="min-width: 350px;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($stockRows)): ?>
                                        <?php $i = 1; foreach ($stockRows as $row): ?>
                                            <?php 
                                            $stockStatus = 'normal';
                                            $statusBadge = '';
                                            $rowClass = '';
                                            if ((float)$row['qty_available'] <= 0) {
                                                $stockStatus = 'out';
                                                $statusBadge = '<span class="badge bg-danger stock-badge ms-1">Out of Stock</span>';
                                                $rowClass = 'table-danger';
                                            } elseif ((float)$row['qty_available'] <= (float)$row['min_stock_qty']) {
                                                $stockStatus = 'low';
                                                $statusBadge = '<span class="badge bg-warning stock-badge ms-1">Low Stock</span>';
                                                $rowClass = 'table-warning';
                                            } else {
                                                $statusBadge = '<span class="badge bg-success stock-badge ms-1">In Stock</span>';
                                            }
                                            ?>
                                            <tr class="<?php echo $rowClass; ?>">
                                                <td><?php echo $i++; ?></td>

                                                <td>
                                                    <strong><?php echo h($row['product_name']); ?></strong>
                                                    <?php if (!empty($row['product_code'])): ?>
                                                        <div class="small text-muted">Code: <?php echo h($row['product_code']); ?></div>
                                                    <?php endif; ?>
                                                    <?php if (!empty($row['item_code'])): ?>
                                                        <div class="small text-muted">Item: <?php echo h($row['item_code']); ?></div>
                                                    <?php endif; ?>
                                                    <?php if (!empty($row['brand_name'])): ?>
                                                        <div class="small text-muted">Brand: <?php echo h($row['brand_name']); ?></div>
                                                    <?php endif; ?>
                                                    <?php if (!empty($row['part_number'])): ?>
                                                        <div class="small text-muted">Part No: <?php echo h($row['part_number']); ?></div>
                                                    <?php endif; ?>
                                                    <?php if (!empty($row['hsn_code'])): ?>
                                                        <div class="small text-muted">HSN: <?php echo h($row['hsn_code']); ?></div>
                                                    <?php endif; ?>
                                                    <?php if ((int)$row['product_status'] !== 1): ?>
                                                        <span class="badge bg-secondary stock-badge mt-1">Product Inactive</span>
                                                    <?php endif; ?>
                                                </td>

                                                <td>
                                                    <div><i class="ri-store-line me-1"></i><strong><?php echo h($row['branch_name'] ?: '-'); ?></strong></div>
                                                    <div class="small text-muted">Code: <?php echo h($row['branch_code'] ?: ''); ?></div>
                                                </td>

                                                <td><?php echo h($row['category_name'] ?: '-'); ?></td>

                                                <td>
                                                    <span class="badge product-type-badge bg-secondary">
                                                        <?php echo h(ucwords(str_replace('_', ' ', $row['product_type'] ?: '-'))); ?>
                                                    </span>
                                                </td>

                                                <td>
                                                    <div class="d-flex align-items-center flex-wrap">
                                                        <strong class="fs-5"><?php echo qtyf($row['qty_available']); ?></strong>
                                                        <span class="ms-1"><?php echo h($row['unit'] ?: 'pcs'); ?></span>
                                                        <?php echo $statusBadge; ?>
                                                    </div>
                                                    <div class="small text-muted mt-1">
                                                        <i class="ri-alert-line me-1"></i> Min: <?php echo qtyf($row['min_stock_qty']); ?> <?php echo h($row['unit'] ?: 'pcs'); ?>
                                                    </div>
                                                    <?php if (!empty($row['unit_name'])): ?>
                                                        <div class="small text-muted">Unit: <?php echo h($row['unit_name']); ?></div>
                                                    <?php endif; ?>
                                                </td>

                                                <td class="small">
                                                    <div><strong>Purchase:</strong> <?php echo money($row['purchase_price']); ?></div>
                                                    <div><strong>Last Purchase:</strong> <?php echo money($row['last_purchase_price']); ?></div>
                                                    <div><strong>Selling:</strong> <?php echo money($row['selling_price']); ?></div>
                                                    <?php if ($row['mrp'] > 0): ?>
                                                        <div><strong>MRP:</strong> <?php echo money($row['mrp']); ?></div>
                                                    <?php endif; ?>
                                                    <?php if ($row['gst_percent'] > 0): ?>
                                                        <div><strong>GST:</strong> <?php echo qtyf($row['gst_percent']); ?>%</div>
                                                    <?php endif; ?>
                                                </td>

                                                <td class="stock-value-cell">
                                                    <strong><?php echo money($row['stock_value']); ?></strong>
                                                    <div class="small text-muted">
                                                        <?php echo qtyf($row['qty_available']); ?> × <?php echo money($row['last_purchase_price'] ?: $row['purchase_price']); ?>
                                                    </div>
                                                </td>

                                                <td class="action-buttons">
                                                    <form method="post" class="row g-2 align-items-end">
                                                        <input type="hidden" name="action" value="edit">
                                                        <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">

                                                        <div class="col-md-3">
                                                            <label class="form-label small">Branch</label>
                                                            <select name="branch_id" class="form-select form-select-sm">
                                                                <?php foreach ($branches as $b): ?>
                                                                    <option value="<?php echo (int)$b['id']; ?>" <?php echo ((int)$row['branch_id'] === (int)$b['id']) ? 'selected' : ''; ?>>
                                                                        <?php echo h(substr($b['branch_name'], 0, 15)); ?>
                                                                    </option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>

                                                        <div class="col-md-4">
                                                            <label class="form-label small">Product</label>
                                                            <select name="product_id" class="form-select form-select-sm">
                                                                <?php foreach ($products as $p): ?>
                                                                    <option value="<?php echo (int)$p['id']; ?>" <?php echo ((int)$row['product_id'] === (int)$p['id']) ? 'selected' : ''; ?>>
                                                                        <?php echo h(substr($p['product_name'], 0, 20) . (strlen($p['product_name']) > 20 ? '...' : '')); ?>
                                                                    </option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>

                                                        <div class="col-md-2">
                                                            <label class="form-label small">Qty</label>
                                                            <input type="number" step="0.01" min="0" name="qty_available" class="form-control form-control-sm" value="<?php echo h($row['qty_available']); ?>">
                                                        </div>

                                                        <div class="col-md-3">
                                                            <label class="form-label small">Last Price</label>
                                                            <input type="number" step="0.01" min="0" name="last_purchase_price" class="form-control form-control-sm" value="<?php echo h($row['last_purchase_price']); ?>">
                                                        </div>

                                                        <div class="col-md-12 mt-2">
                                                            <div class="d-flex gap-1 justify-content-end">
                                                                <button type="submit" class="btn btn-sm btn-primary" title="Update">
                                                                    <i class="ri-save-line"></i> Update
                                                                </button>
                                                                <button type="button" class="btn btn-sm btn-danger" title="Delete" onclick="confirmDelete(<?php echo (int)$row['id']; ?>, '<?php echo h(addslashes($row['product_name'])); ?>')">
                                                                    <i class="ri-delete-bin-line"></i> Delete
                                                                </button>
                                                                <a href="product-view.php?id=<?php echo (int)$row['product_id']; ?>" class="btn btn-sm btn-info" title="View Product">
                                                                    <i class="ri-eye-line"></i> View
                                                                </a>
                                                            </div>
                                                        </div>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="9" class="text-center text-muted py-5">
                                                <i class="ri-inbox-line font-size-48 mb-3 d-block"></i>
                                                <h5>No product stock found</h5>
                                                <p class="mb-0">Add stock using the form above or adjust your filters.</p>
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

<!-- Delete Form -->
<form method="post" id="deleteForm" style="display: none;">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id" id="deleteId">
</form>

<?php include('includes/rightbar.php'); ?>
<?php include('includes/scripts.php'); ?>

<script>
function confirmDelete(id, productName) {
    if (confirm('Are you sure you want to delete stock for "' + productName + '"?\n\nThis action cannot be undone and will be logged.')) {
        document.getElementById('deleteId').value = id;
        document.getElementById('deleteForm').submit();
    }
}

function filterByStock(type) {
    var url = new URL(window.location.href);
    if (type === 'all') {
        url.searchParams.delete('low_stock');
    } else if (type === 'positive') {
        url.searchParams.set('low_stock', 'positive');
    } else if (type === 'low') {
        url.searchParams.set('low_stock', 'yes');
    } else if (type === 'out') {
        url.searchParams.set('low_stock', 'out');
    }
    window.location.href = url.toString();
}

// Auto-hide alerts after 5 seconds
setTimeout(function() {
    var alerts = document.querySelectorAll('.alert');
    alerts.forEach(function(alert) {
        var bsAlert = new bootstrap.Alert(alert);
        bsAlert.close();
    });
}, 5000);

// Add visual feedback for summary cards
document.querySelectorAll('.summary-card').forEach(function(card) {
    card.addEventListener('click', function() {
        document.querySelectorAll('.summary-card').forEach(function(c) {
            c.style.opacity = '0.8';
        });
        this.style.opacity = '1';
    });
});
</script>

</body>
</html>