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
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function tableExists(mysqli $conn, string $table): bool
{
    $sql = "SELECT 1
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return false;
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
    if (!$res) return 0;
    $row = $res->fetch_assoc();
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
   LOAD BRANCHES
------------------------------------------------------- */
$branches = [];
if (tableExists($conn, 'branches')) {
    $res = $conn->query("SELECT id, branch_name, branch_code
                         FROM branches
                         WHERE business_id = {$businessId} AND status = 'active'
                         ORDER BY branch_name ASC");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $branches[] = $row;
        }
    }
}

/* -------------------------------------------------------
   LOAD PRODUCTS
------------------------------------------------------- */
$products = [];
if (tableExists($conn, 'products')) {
    $res = $conn->query("SELECT id, product_name, item_code, stock_qty
                         FROM products
                         WHERE business_id = {$businessId}
                         ORDER BY product_name ASC");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $products[] = $row;
        }
    }
}

/* -------------------------------------------------------
   LOAD VEHICLE STOCK
------------------------------------------------------- */
$vehicles = [];
if (tableExists($conn, 'vehicle_stock') && tableExists($conn, 'vehicle_models')) {
    $sql = "SELECT 
                vs.id,
                vs.chassis_no,
                vs.engine_no,
                vs.stock_status,
                vm.model_name
            FROM vehicle_stock vs
            LEFT JOIN vehicle_models vm ON vm.id = vs.model_id
            WHERE vs.business_id = {$businessId}
            ORDER BY vs.id DESC";
    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $vehicles[] = $row;
        }
    }
}

/* -------------------------------------------------------
   ADD TRANSFER
------------------------------------------------------- */
$success = '';
$error = '';

$form = [
    'from_branch_id' => '',
    'to_branch_id'   => '',
    'item_type'      => 'product',
    'item_id'        => '',
    'qty'            => '1',
    'transfer_date'  => date('Y-m-d\TH:i'),
    'status'         => 'completed',
    'notes'          => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['from_branch_id'] = trim($_POST['from_branch_id'] ?? '');
    $form['to_branch_id']   = trim($_POST['to_branch_id'] ?? '');
    $form['item_type']      = trim($_POST['item_type'] ?? 'product');
    $form['item_id']        = trim($_POST['item_id'] ?? '');
    $form['qty']            = trim($_POST['qty'] ?? '1');
    $form['transfer_date']  = trim($_POST['transfer_date'] ?? date('Y-m-d\TH:i'));
    $form['status']         = trim($_POST['status'] ?? 'completed');
    $form['notes']          = trim($_POST['notes'] ?? '');

    $fromBranchId = (int)$form['from_branch_id'];
    $toBranchId   = (int)$form['to_branch_id'];
    $itemId       = (int)$form['item_id'];
    $qty          = (float)$form['qty'];

    if ($fromBranchId <= 0) {
        $error = 'Please select from branch.';
    } elseif ($toBranchId <= 0) {
        $error = 'Please select to branch.';
    } elseif ($fromBranchId === $toBranchId) {
        $error = 'From branch and to branch cannot be same.';
    } elseif (!in_array($form['item_type'], ['vehicle', 'product'], true)) {
        $error = 'Invalid item type.';
    } elseif ($itemId <= 0) {
        $error = 'Please select item.';
    } elseif ($qty <= 0) {
        $error = 'Quantity must be greater than 0.';
    } elseif (!in_array($form['status'], ['pending','approved','completed','cancelled'], true)) {
        $error = 'Invalid status selected.';
    } else {
        $stmt = $conn->prepare("SELECT id FROM branches WHERE id = ? AND business_id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('ii', $fromBranchId, $businessId);
            $stmt->execute();
            $fromExists = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$fromExists) $error = 'Invalid from branch.';
        }

        if ($error === '') {
            $stmt = $conn->prepare("SELECT id FROM branches WHERE id = ? AND business_id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('ii', $toBranchId, $businessId);
                $stmt->execute();
                $toExists = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$toExists) $error = 'Invalid to branch.';
            }
        }

        if ($error === '' && $form['item_type'] === 'product' && tableExists($conn, 'product_stock')) {
            $stmt = $conn->prepare("SELECT id, qty_available
                                    FROM product_stock
                                    WHERE business_id = ? AND branch_id = ? AND product_id = ?
                                    LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('iii', $businessId, $fromBranchId, $itemId);
                $stmt->execute();
                $stockRow = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$stockRow) {
                    $error = 'Selected product not found in from branch stock.';
                } elseif ((float)$stockRow['qty_available'] < $qty) {
                    $error = 'Insufficient product stock in from branch.';
                }
            }
        }

        if ($error === '' && $form['item_type'] === 'vehicle' && tableExists($conn, 'vehicle_stock')) {
            $stmt = $conn->prepare("SELECT id, stock_status
                                    FROM vehicle_stock
                                    WHERE id = ? AND business_id = ? AND branch_id = ?
                                    LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('iii', $itemId, $businessId, $fromBranchId);
                $stmt->execute();
                $vehicleRow = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$vehicleRow) {
                    $error = 'Selected vehicle not found in from branch.';
                } elseif (($vehicleRow['stock_status'] ?? '') !== 'in_stock') {
                    $error = 'Selected vehicle is not available for transfer.';
                } elseif ($qty != 1) {
                    $error = 'Vehicle transfer quantity must be 1.';
                }
            }
        }
    }

    if ($error === '' && tableExists($conn, 'stock_transfers')) {
        $transferDate = date('Y-m-d H:i:s', strtotime($form['transfer_date']));
        $conn->begin_transaction();

        try {
            $stmt = $conn->prepare("INSERT INTO stock_transfers (
                                        business_id,
                                        from_branch_id,
                                        to_branch_id,
                                        item_type,
                                        item_id,
                                        qty,
                                        transfer_date,
                                        status,
                                        notes,
                                        created_by,
                                        created_at
                                    ) VALUES (
                                        ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()
                                    )");
            if (!$stmt) {
                throw new Exception('Failed to prepare transfer insert.');
            }

            $stmt->bind_param(
                'iiisidsssi',
                $businessId,
                $fromBranchId,
                $toBranchId,
                $form['item_type'],
                $itemId,
                $qty,
                $transferDate,
                $form['status'],
                $form['notes'],
                $businessUserId
            );

            if (!$stmt->execute()) {
                throw new Exception('Failed to save transfer.');
            }
            $transferId = (int)$stmt->insert_id;
            $stmt->close();

            if ($form['status'] === 'completed') {
                if ($form['item_type'] === 'product' && tableExists($conn, 'product_stock')) {
                    $stmt = $conn->prepare("UPDATE product_stock
                                            SET qty_available = qty_available - ?, updated_at = NOW()
                                            WHERE business_id = ? AND branch_id = ? AND product_id = ?");
                    if (!$stmt) throw new Exception('Failed to update from branch stock.');
                    $stmt->bind_param('diii', $qty, $businessId, $fromBranchId, $itemId);
                    if (!$stmt->execute()) throw new Exception('Failed to reduce from branch stock.');
                    $stmt->close();

                    $stmt = $conn->prepare("SELECT id FROM product_stock
                                            WHERE business_id = ? AND branch_id = ? AND product_id = ?
                                            LIMIT 1");
                    if (!$stmt) throw new Exception('Failed to check to branch stock.');
                    $stmt->bind_param('iii', $businessId, $toBranchId, $itemId);
                    $stmt->execute();
                    $toStock = $stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    if ($toStock) {
                        $stmt = $conn->prepare("UPDATE product_stock
                                                SET qty_available = qty_available + ?, updated_at = NOW()
                                                WHERE business_id = ? AND branch_id = ? AND product_id = ?");
                        if (!$stmt) throw new Exception('Failed to update to branch stock.');
                        $stmt->bind_param('diii', $qty, $businessId, $toBranchId, $itemId);
                        if (!$stmt->execute()) throw new Exception('Failed to add to branch stock.');
                        $stmt->close();
                    } else {
                        $stmt = $conn->prepare("INSERT INTO product_stock (
                                                    business_id, branch_id, product_id, qty_available, last_purchase_price, updated_at
                                                ) VALUES (?, ?, ?, ?, 0.00, NOW())");
                        if (!$stmt) throw new Exception('Failed to create to branch stock.');
                        $stmt->bind_param('iiid', $businessId, $toBranchId, $itemId, $qty);
                        if (!$stmt->execute()) throw new Exception('Failed to insert to branch stock.');
                        $stmt->close();
                    }
                }

                if ($form['item_type'] === 'vehicle' && tableExists($conn, 'vehicle_stock')) {
                    $stmt = $conn->prepare("UPDATE vehicle_stock
                                            SET branch_id = ?, stock_status = 'transferred'
                                            WHERE id = ? AND business_id = ?");
                    if (!$stmt) throw new Exception('Failed to transfer vehicle.');
                    $stmt->bind_param('iii', $toBranchId, $itemId, $businessId);
                    if (!$stmt->execute()) throw new Exception('Failed to update vehicle branch.');
                    $stmt->close();
                }

                if (tableExists($conn, 'stock_movements')) {
                    $movementDate = $transferDate;

                    $stmt = $conn->prepare("INSERT INTO stock_movements (
                                                business_id, branch_id, item_type, item_id, movement_type,
                                                ref_table, ref_id, qty, unit_price, movement_date, notes, created_by, created_at
                                            ) VALUES (
                                                ?, ?, ?, ?, 'transfer_out',
                                                'stock_transfers', ?, ?, 0.00, ?, ?, ?, NOW()
                                            )");
                    if ($stmt) {
                        $noteOut = 'Transfer to branch ID ' . $toBranchId;
                        $stmt->bind_param(
                            'iisiddssi',
                            $businessId,
                            $fromBranchId,
                            $form['item_type'],
                            $itemId,
                            $transferId,
                            $qty,
                            $movementDate,
                            $noteOut,
                            $businessUserId
                        );
                        $stmt->execute();
                        $stmt->close();
                    }

                    $stmt = $conn->prepare("INSERT INTO stock_movements (
                                                business_id, branch_id, item_type, item_id, movement_type,
                                                ref_table, ref_id, qty, unit_price, movement_date, notes, created_by, created_at
                                            ) VALUES (
                                                ?, ?, ?, ?, 'transfer_in',
                                                'stock_transfers', ?, ?, 0.00, ?, ?, ?, NOW()
                                            )");
                    if ($stmt) {
                        $noteIn = 'Transfer from branch ID ' . $fromBranchId;
                        $stmt->bind_param(
                            'iisiddssi',
                            $businessId,
                            $toBranchId,
                            $form['item_type'],
                            $itemId,
                            $transferId,
                            $qty,
                            $movementDate,
                            $noteIn,
                            $businessUserId
                        );
                        $stmt->execute();
                        $stmt->close();
                    }
                }
            }

            $conn->commit();
            $success = 'Branch transfer saved successfully.';

            $form = [
                'from_branch_id' => '',
                'to_branch_id'   => '',
                'item_type'      => 'product',
                'item_id'        => '',
                'qty'            => '1',
                'transfer_date'  => date('Y-m-d\TH:i'),
                'status'         => 'completed',
                'notes'          => '',
            ];
        } catch (Exception $e) {
            $conn->rollback();
            $error = $e->getMessage();
        }
    }
}

/* -------------------------------------------------------
   FILTERS FOR LIST
------------------------------------------------------- */
$searchItemType = trim($_GET['item_type'] ?? '');
$searchStatus   = trim($_GET['status'] ?? '');

$where = "st.business_id = {$businessId}";

if ($searchItemType !== '' && in_array($searchItemType, ['vehicle', 'product'], true)) {
    $safe = $conn->real_escape_string($searchItemType);
    $where .= " AND st.item_type = '{$safe}'";
}

if ($searchStatus !== '' && in_array($searchStatus, ['pending','approved','completed','cancelled'], true)) {
    $safe = $conn->real_escape_string($searchStatus);
    $where .= " AND st.status = '{$safe}'";
}

/* -------------------------------------------------------
   SUMMARY
------------------------------------------------------- */
$totalTransfers = tableExists($conn, 'stock_transfers')
    ? getCount($conn, 'stock_transfers', "business_id = {$businessId}")
    : 0;

$completedTransfers = tableExists($conn, 'stock_transfers')
    ? getCount($conn, 'stock_transfers', "business_id = {$businessId} AND status = 'completed'")
    : 0;

$pendingTransfers = tableExists($conn, 'stock_transfers')
    ? getCount($conn, 'stock_transfers', "business_id = {$businessId} AND status = 'pending'")
    : 0;

$cancelledTransfers = tableExists($conn, 'stock_transfers')
    ? getCount($conn, 'stock_transfers', "business_id = {$businessId} AND status = 'cancelled'")
    : 0;

/* -------------------------------------------------------
   FETCH TRANSFERS
------------------------------------------------------- */
$transfers = [];

if (tableExists($conn, 'stock_transfers')) {
    $sql = "SELECT
                st.*,
                fb.branch_name AS from_branch_name,
                tb.branch_name AS to_branch_name
            FROM stock_transfers st
            LEFT JOIN branches fb ON fb.id = st.from_branch_id
            LEFT JOIN branches tb ON tb.id = st.to_branch_id
            WHERE {$where}
            ORDER BY st.id DESC";
    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $itemName = '-';

            if (($row['item_type'] ?? '') === 'product' && tableExists($conn, 'products')) {
                $pid = (int)$row['item_id'];
                $r2 = $conn->query("SELECT product_name, item_code FROM products WHERE id = {$pid} LIMIT 1");
                if ($r2 && $p = $r2->fetch_assoc()) {
                    $itemName = $p['product_name'];
                    if (!empty($p['item_code'])) {
                        $itemName .= ' (' . $p['item_code'] . ')';
                    }
                }
            }

            if (($row['item_type'] ?? '') === 'vehicle' && tableExists($conn, 'vehicle_stock') && tableExists($conn, 'vehicle_models')) {
                $vid = (int)$row['item_id'];
                $r2 = $conn->query("SELECT vm.model_name, vs.chassis_no
                                    FROM vehicle_stock vs
                                    LEFT JOIN vehicle_models vm ON vm.id = vs.model_id
                                    WHERE vs.id = {$vid}
                                    LIMIT 1");
                if ($r2 && $v = $r2->fetch_assoc()) {
                    $itemName = trim(($v['model_name'] ?? 'Vehicle') . ' / ' . ($v['chassis_no'] ?? ''));
                }
            }

            $row['item_name'] = $itemName;
            $transfers[] = $row;
        }
    }
}

$pageTitle = 'Branch Transfers';
$currentPage = 'branch-transfers';
?>
<!doctype html>
<html lang="en">

<?php include('includes/head.php'); ?>

<body data-sidebar="dark">

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
                        <h4 class="mb-1">Branch Transfers</h4>
                        <p class="text-muted mb-0">Manage stock transfer between branches</p>
                    </div>
                </div>

                <?php if ($success !== ''): ?>
                    <div class="alert alert-success"><?php echo h($success); ?></div>
                <?php endif; ?>

                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger"><?php echo h($error); ?></div>
                <?php endif; ?>

                <div class="row">
                    <div class="col-md-6 col-xl-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Total Transfers</p>
                                <h3 class="mb-0"><?php echo number_format($totalTransfers); ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Completed</p>
                                <h3 class="mb-0 text-success"><?php echo number_format($completedTransfers); ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Pending</p>
                                <h3 class="mb-0 text-warning"><?php echo number_format($pendingTransfers); ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Cancelled</p>
                                <h3 class="mb-0 text-danger"><?php echo number_format($cancelledTransfers); ?></h3>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- add transfer -->
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4">Add Branch Transfer</h4>

                        <form method="post">
                            <div class="row">
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">From Branch</label>
                                    <select name="from_branch_id" class="form-select" required>
                                        <option value="">Select</option>
                                        <?php foreach ($branches as $b): ?>
                                            <option value="<?php echo (int)$b['id']; ?>" <?php echo ((string)$form['from_branch_id'] === (string)$b['id']) ? 'selected' : ''; ?>>
                                                <?php echo h($b['branch_name'] . ' (' . $b['branch_code'] . ')'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">To Branch</label>
                                    <select name="to_branch_id" class="form-select" required>
                                        <option value="">Select</option>
                                        <?php foreach ($branches as $b): ?>
                                            <option value="<?php echo (int)$b['id']; ?>" <?php echo ((string)$form['to_branch_id'] === (string)$b['id']) ? 'selected' : ''; ?>>
                                                <?php echo h($b['branch_name'] . ' (' . $b['branch_code'] . ')'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-md-2 mb-3">
                                    <label class="form-label">Item Type</label>
                                    <select name="item_type" id="item_type" class="form-select" required onchange="toggleItemDropdowns()">
                                        <option value="product" <?php echo ($form['item_type'] === 'product') ? 'selected' : ''; ?>>Product</option>
                                        <option value="vehicle" <?php echo ($form['item_type'] === 'vehicle') ? 'selected' : ''; ?>>Vehicle</option>
                                    </select>
                                </div>

                                <div class="col-md-4 mb-3" id="product_box">
                                    <label class="form-label">Product</label>
                                    <select name="item_id" id="product_item_id" class="form-select">
                                        <option value="">Select Product</option>
                                        <?php foreach ($products as $p): ?>
                                            <option value="<?php echo (int)$p['id']; ?>" <?php echo ($form['item_type'] === 'product' && (string)$form['item_id'] === (string)$p['id']) ? 'selected' : ''; ?>>
                                                <?php echo h($p['product_name'] . (!empty($p['item_code']) ? ' (' . $p['item_code'] . ')' : '')); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-md-4 mb-3" id="vehicle_box" style="display:none;">
                                    <label class="form-label">Vehicle</label>
                                    <select id="vehicle_item_id" class="form-select">
                                        <option value="">Select Vehicle</option>
                                        <?php foreach ($vehicles as $v): ?>
                                            <option value="<?php echo (int)$v['id']; ?>" <?php echo ($form['item_type'] === 'vehicle' && (string)$form['item_id'] === (string)$v['id']) ? 'selected' : ''; ?>>
                                                <?php echo h(($v['model_name'] ?: 'Vehicle') . ' / ' . ($v['chassis_no'] ?: '-')); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <input type="hidden" name="item_id" id="final_item_id" value="<?php echo h($form['item_id']); ?>">

                                <div class="col-md-2 mb-3">
                                    <label class="form-label">Qty</label>
                                    <input type="number" step="0.01" min="0.01" name="qty" id="qty" class="form-control" value="<?php echo h($form['qty']); ?>" required>
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Transfer Date</label>
                                    <input type="datetime-local" name="transfer_date" class="form-control" value="<?php echo h($form['transfer_date']); ?>" required>
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Status</label>
                                    <select name="status" class="form-select" required>
                                        <option value="pending" <?php echo ($form['status'] === 'pending') ? 'selected' : ''; ?>>Pending</option>
                                        <option value="approved" <?php echo ($form['status'] === 'approved') ? 'selected' : ''; ?>>Approved</option>
                                        <option value="completed" <?php echo ($form['status'] === 'completed') ? 'selected' : ''; ?>>Completed</option>
                                        <option value="cancelled" <?php echo ($form['status'] === 'cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                                    </select>
                                </div>

                                <div class="col-md-12 mb-3">
                                    <label class="form-label">Notes</label>
                                    <textarea name="notes" class="form-control" rows="3"><?php echo h($form['notes']); ?></textarea>
                                </div>

                                <div class="col-md-12">
                                    <button type="submit" class="btn btn-primary">Save Transfer</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- filters -->
                <div class="card">
                    <div class="card-body">
                        <form method="get" class="row g-3">
                            <div class="col-md-5">
                                <label class="form-label">Item Type</label>
                                <select name="item_type" class="form-select">
                                    <option value="">All</option>
                                    <option value="product" <?php echo ($searchItemType === 'product') ? 'selected' : ''; ?>>Product</option>
                                    <option value="vehicle" <?php echo ($searchItemType === 'vehicle') ? 'selected' : ''; ?>>Vehicle</option>
                                </select>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label">Status</label>
                                <select name="status" class="form-select">
                                    <option value="">All</option>
                                    <option value="pending" <?php echo ($searchStatus === 'pending') ? 'selected' : ''; ?>>Pending</option>
                                    <option value="approved" <?php echo ($searchStatus === 'approved') ? 'selected' : ''; ?>>Approved</option>
                                    <option value="completed" <?php echo ($searchStatus === 'completed') ? 'selected' : ''; ?>>Completed</option>
                                    <option value="cancelled" <?php echo ($searchStatus === 'cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                                </select>
                            </div>
                            <div class="col-md-2 d-flex align-items-end">
                                <button type="submit" class="btn btn-secondary w-100">Filter</button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- list -->
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4">Transfer List</h4>

                        <div class="table-responsive">
                            <table class="table table-bordered table-striped align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Date</th>
                                        <th>Type</th>
                                        <th>Item</th>
                                        <th>From Branch</th>
                                        <th>To Branch</th>
                                        <th>Qty</th>
                                        <th>Status</th>
                                        <th>Notes</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($transfers)): ?>
                                        <?php $i = 1; foreach ($transfers as $t): ?>
                                            <tr>
                                                <td><?php echo $i++; ?></td>
                                                <td><?php echo !empty($t['transfer_date']) ? h(date('d M Y h:i A', strtotime($t['transfer_date']))) : '-'; ?></td>
                                                <td><?php echo h(ucfirst($t['item_type'])); ?></td>
                                                <td><?php echo h($t['item_name']); ?></td>
                                                <td><?php echo h($t['from_branch_name'] ?: '-'); ?></td>
                                                <td><?php echo h($t['to_branch_name'] ?: '-'); ?></td>
                                                <td><?php echo h($t['qty']); ?></td>
                                                <td>
                                                    <?php
                                                    $badge = 'secondary';
                                                    if ($t['status'] === 'completed') $badge = 'success';
                                                    elseif ($t['status'] === 'pending') $badge = 'warning';
                                                    elseif ($t['status'] === 'approved') $badge = 'info';
                                                    elseif ($t['status'] === 'cancelled') $badge = 'danger';
                                                    ?>
                                                    <span class="badge bg-<?php echo $badge; ?>">
                                                        <?php echo h(ucfirst($t['status'])); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo h($t['notes'] ?: '-'); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="9" class="text-center text-muted">No branch transfers found.</td>
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

<?php include('includes/rightbar.php'); ?>
<?php include('includes/scripts.php'); ?>

<script>
function toggleItemDropdowns() {
    var type = document.getElementById('item_type').value;
    var productBox = document.getElementById('product_box');
    var vehicleBox = document.getElementById('vehicle_box');
    var productSelect = document.getElementById('product_item_id');
    var vehicleSelect = document.getElementById('vehicle_item_id');
    var finalItem = document.getElementById('final_item_id');
    var qty = document.getElementById('qty');

    if (type === 'product') {
        productBox.style.display = '';
        vehicleBox.style.display = 'none';
        finalItem.value = productSelect.value;
        qty.readOnly = false;
    } else {
        productBox.style.display = 'none';
        vehicleBox.style.display = '';
        finalItem.value = vehicleSelect.value;
        qty.value = '1';
        qty.readOnly = true;
    }
}

document.addEventListener('DOMContentLoaded', function () {
    toggleItemDropdowns();

    var productSelect = document.getElementById('product_item_id');
    var vehicleSelect = document.getElementById('vehicle_item_id');
    var finalItem = document.getElementById('final_item_id');

    if (productSelect) {
        productSelect.addEventListener('change', function() {
            if (document.getElementById('item_type').value === 'product') {
                finalItem.value = this.value;
            }
        });
    }

    if (vehicleSelect) {
        vehicleSelect.addEventListener('change', function() {
            if (document.getElementById('item_type').value === 'vehicle') {
                finalItem.value = this.value;
            }
        });
    }

    document.getElementById('item_type').addEventListener('change', function() {
        toggleItemDropdowns();
    });
});
</script>

</body>
</html>