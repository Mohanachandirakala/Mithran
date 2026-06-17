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

if ($businessUserId <= 0 || $businessId <= 0) {
    header('Location: login.php');
    exit;
}

function h($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}
function money($amount): string {
    return '₹' . number_format((float)$amount, 2);
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
        while ($row = $res->fetch_assoc()) $rows[] = $row;
        $res->free();
    }
    return $rows;
}
function parseDecimal($value): float {
    $value = preg_replace('/[^0-9.\-]/', '', (string)($value ?? 0));
    return round(is_numeric($value) ? (float)$value : 0, 2);
}
function calculateLine(float $qty, float $unitPrice, float $discount, float $cgstP, float $sgstP, float $igstP, float $cessP, string $gstType): array {
    $lineAmount = round($qty * $unitPrice, 2);
    $discount = min($discount, $lineAmount);
    $afterDiscount = round(max(0, $lineAmount - $discount), 2);
    $taxPercent = $cgstP + $sgstP + $igstP + $cessP;

    if ($gstType === 'inclusive' && $taxPercent > 0) {
        $taxable = round($afterDiscount / (1 + ($taxPercent / 100)), 2);
    } else {
        $taxable = $afterDiscount;
    }

    $cgst = round($taxable * $cgstP / 100, 2);
    $sgst = round($taxable * $sgstP / 100, 2);
    $igst = round($taxable * $igstP / 100, 2);
    $cess = round($taxable * $cessP / 100, 2);

    if ($gstType === 'inclusive') {
        $total = $afterDiscount;
    } else {
        $total = round($taxable + $cgst + $sgst + $igst + $cess, 2);
    }

    return compact('lineAmount', 'discount', 'taxable', 'cgst', 'sgst', 'igst', 'cess', 'total');
}

$requiredTables = ['business_users', 'businesses', 'branches', 'customers', 'quotations', 'quotation_items'];
foreach ($requiredTables as $tbl) {
    if (!tableExists($conn, $tbl)) die($tbl . ' table not found.');
}

if (!columnExists($conn, 'quotation_items', 'gst_type')) {
    $conn->query("ALTER TABLE quotation_items ADD COLUMN gst_type ENUM('inclusive','exclusive') NOT NULL DEFAULT 'exclusive' AFTER discount_amount");
}

$quotationId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($quotationId <= 0) {
    header('Location: quotations.php');
    exit;
}

$stmt = $conn->prepare("
    SELECT bu.id, bu.status, b.status AS business_status
    FROM business_users bu
    INNER JOIN businesses b ON b.id = bu.business_id
    WHERE bu.id = ? AND bu.business_id = ?
    LIMIT 1
");
$stmt->bind_param('ii', $businessUserId, $businessId);
$stmt->execute();
$loggedUser = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$loggedUser || (int)$loggedUser['status'] !== 1 || ($loggedUser['business_status'] ?? '') !== 'active') {
    session_destroy();
    header('Location: login.php');
    exit;
}

$stmt = $conn->prepare("
    SELECT q.*, c.full_name AS customer_name, c.mobile AS customer_mobile, c.email AS customer_email,
           c.gstin AS customer_gstin, c.address_line1 AS customer_address_line1,
           c.address_line2 AS customer_address_line2, c.city AS customer_city,
           c.state AS customer_state, c.pincode AS customer_pincode
    FROM quotations q
    INNER JOIN customers c ON c.id = q.customer_id
    WHERE q.id = ? AND q.business_id = ?
    LIMIT 1
");
$stmt->bind_param('ii', $quotationId, $businessId);
$stmt->execute();
$quotation = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$quotation) {
    header('Location: quotations.php?error=' . urlencode('Quotation not found.'));
    exit;
}

$branches = fetchAllAssoc($conn, "SELECT id, branch_name, branch_code FROM branches WHERE business_id = {$businessId} ORDER BY branch_name ASC");
$customers = fetchAllAssoc($conn, "SELECT id, full_name, mobile FROM customers WHERE business_id = {$businessId} ORDER BY full_name ASC");

// Fixed: Remove gst_type from products query since it doesn't exist
$products = tableExists($conn, 'products') ? fetchAllAssoc($conn, "
    SELECT id, product_name, product_code, selling_price, gst_percent, unit
    FROM products
    WHERE business_id = {$businessId} AND status = 1
    ORDER BY product_name ASC
") : [];

$vehicles = [];
if (tableExists($conn, 'vehicle_stock')) {
    $vehicles = fetchAllAssoc($conn, "
        SELECT vs.id, vs.sale_price, vs.chassis_no, vs.color,
               COALESCE(vm.model_name,'') AS model_name,
               COALESCE(vm.variant_name,'') AS variant_name,
               COALESCE(vm.gst_percent,0) AS gst_percent,
               COALESCE(vb.brand_name,'Vehicle') AS brand_name
        FROM vehicle_stock vs
        LEFT JOIN vehicle_models vm ON vm.id = vs.model_id
        LEFT JOIN vehicle_brands vb ON vb.id = vm.brand_id
        WHERE vs.business_id = {$businessId}
          AND vs.stock_status IN ('in_stock','reserved','demo')
        ORDER BY vs.id DESC
    ");
}

function loadQuotationItems(mysqli $conn, int $quotationId): array {
    $rows = [];
    $stmt = $conn->prepare("SELECT * FROM quotation_items WHERE quotation_id = ? ORDER BY id ASC");
    $stmt->bind_param('i', $quotationId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $rows[] = $row;
    $stmt->close();
    return $rows;
}

$quotationItems = loadQuotationItems($conn, $quotationId);

$form = [
    'branch_id' => (int)$quotation['branch_id'],
    'quotation_no' => $quotation['quotation_no'],
    'quotation_date' => date('Y-m-d\TH:i', strtotime($quotation['quotation_date'])),
    'valid_until' => !empty($quotation['valid_until']) ? date('Y-m-d', strtotime($quotation['valid_until'])) : '',
    'quotation_type' => $quotation['quotation_type'],
    'customer_id' => (int)$quotation['customer_id'],
    'customer_note' => $quotation['customer_note'] ?? '',
    'terms_conditions' => $quotation['terms_conditions'] ?? '',
    'items' => []
];

foreach ($quotationItems as $item) {
    $form['items'][] = [
        'item_type' => $item['item_type'],
        'vehicle_stock_id' => $item['vehicle_stock_id'] ?? '',
        'product_id' => $item['product_id'] ?? '',
        'description' => $item['description'],
        'qty' => $item['qty'],
        'unit_price' => $item['unit_price'],
        'discount_amount' => $item['discount_amount'],
        'gst_type' => $item['gst_type'] ?? 'exclusive',
        'cgst_percent' => $item['cgst_percent'],
        'sgst_percent' => $item['sgst_percent'],
        'igst_percent' => $item['igst_percent'],
        'cess_percent' => $item['cess_percent']
    ];
}

if (empty($form['items'])) {
    $form['items'][] = [
        'item_type' => 'product',
        'vehicle_stock_id' => '',
        'product_id' => '',
        'description' => '',
        'qty' => '1',
        'unit_price' => '0',
        'discount_amount' => '0',
        'gst_type' => 'exclusive',
        'cgst_percent' => '0',
        'sgst_percent' => '0',
        'igst_percent' => '0',
        'cess_percent' => '0'
    ];
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_quotation') {
    $form['branch_id'] = (int)($_POST['branch_id'] ?? 0);
    $form['quotation_no'] = trim($_POST['quotation_no'] ?? '');
    $form['quotation_date'] = trim($_POST['quotation_date'] ?? '');
    $form['valid_until'] = trim($_POST['valid_until'] ?? '');
    $form['quotation_type'] = trim($_POST['quotation_type'] ?? 'mixed');
    $form['customer_id'] = (int)($_POST['customer_id'] ?? 0);
    $form['customer_note'] = trim($_POST['customer_note'] ?? '');
    $form['terms_conditions'] = trim($_POST['terms_conditions'] ?? '');
    $form['items'] = $_POST['items'] ?? [];

    if ($form['branch_id'] <= 0) $error = 'Please select branch.';
    elseif ($form['quotation_no'] === '') $error = 'Quotation number is required.';
    elseif ($form['quotation_date'] === '') $error = 'Quotation date is required.';
    elseif ($form['customer_id'] <= 0) $error = 'Please select customer.';

    $cleanItems = [];
    $subtotal = $discountAmount = $cgstAmount = $sgstAmount = $igstAmount = $cessAmount = $grandTotal = 0.00;
    $roundOff = 0.00;

    if ($error === '') {
        foreach ($form['items'] as $item) {
            $itemType = trim($item['item_type'] ?? 'product');
            $vehicleStockId = (int)($item['vehicle_stock_id'] ?? 0);
            $productId = (int)($item['product_id'] ?? 0);
            $description = trim($item['description'] ?? '');
            $qty = parseDecimal($item['qty'] ?? 0);
            $unitPrice = parseDecimal($item['unit_price'] ?? 0);
            $discount = parseDecimal($item['discount_amount'] ?? 0);
            $gstType = (($item['gst_type'] ?? 'exclusive') === 'inclusive') ? 'inclusive' : 'exclusive';
            $cgstP = parseDecimal($item['cgst_percent'] ?? 0);
            $sgstP = parseDecimal($item['sgst_percent'] ?? 0);
            $igstP = parseDecimal($item['igst_percent'] ?? 0);
            $cessP = parseDecimal($item['cess_percent'] ?? 0);

            if (!in_array($itemType, ['vehicle','product','charge'], true)) continue;
            if ($qty <= 0) continue;
            if ($itemType === 'product' && $productId <= 0 && $description === '') continue;
            if ($itemType === 'vehicle' && $vehicleStockId <= 0 && $description === '') continue;
            if ($itemType === 'charge' && $description === '') continue;

            $calc = calculateLine($qty, $unitPrice, $discount, $cgstP, $sgstP, $igstP, $cessP, $gstType);

            $subtotal += $calc['lineAmount'];
            $discountAmount += $calc['discount'];
            $cgstAmount += $calc['cgst'];
            $sgstAmount += $calc['sgst'];
            $igstAmount += $calc['igst'];
            $cessAmount += $calc['cess'];
            $grandTotal += $calc['total'];

            $cleanItems[] = [
                'item_type' => $itemType,
                'vehicle_stock_id' => $vehicleStockId > 0 ? $vehicleStockId : null,
                'product_id' => $productId > 0 ? $productId : null,
                'description' => $description,
                'qty' => $qty,
                'unit_price' => $unitPrice,
                'discount_amount' => $calc['discount'],
                'gst_type' => $gstType,
                'taxable_value' => $calc['taxable'],
                'cgst_percent' => $cgstP,
                'sgst_percent' => $sgstP,
                'igst_percent' => $igstP,
                'cess_percent' => $cessP,
                'cgst_amount' => $calc['cgst'],
                'sgst_amount' => $calc['sgst'],
                'igst_amount' => $calc['igst'],
                'cess_amount' => $calc['cess'],
                'line_total' => $calc['total']
            ];
        }

        if (empty($cleanItems)) $error = 'Please enter at least one valid item.';
    }

    if ($error === '') {
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("
                UPDATE quotations SET
                    branch_id=?, quotation_no=?, quotation_date=?, valid_until=?, quotation_type=?,
                    customer_id=?, subtotal=?, discount_amount=?, cgst_amount=?, sgst_amount=?,
                    igst_amount=?, cess_amount=?, round_off=?, grand_total=?,
                    customer_note=?, terms_conditions=?
                WHERE id=? AND business_id=?
            ");
            $stmt->bind_param(
                'issssiddddddddssii',
                $form['branch_id'], $form['quotation_no'], $form['quotation_date'], $form['valid_until'],
                $form['quotation_type'], $form['customer_id'], $subtotal, $discountAmount,
                $cgstAmount, $sgstAmount, $igstAmount, $cessAmount, $roundOff, $grandTotal,
                $form['customer_note'], $form['terms_conditions'], $quotationId, $businessId
            );
            if (!$stmt->execute()) throw new Exception($stmt->error);
            $stmt->close();

            $stmt = $conn->prepare("DELETE FROM quotation_items WHERE quotation_id=?");
            $stmt->bind_param('i', $quotationId);
            if (!$stmt->execute()) throw new Exception($stmt->error);
            $stmt->close();

            $stmtItem = $conn->prepare("
                INSERT INTO quotation_items (
                    quotation_id, item_type, vehicle_stock_id, product_id, description,
                    qty, unit_price, discount_amount, gst_type, taxable_value,
                    cgst_percent, sgst_percent, igst_percent, cess_percent,
                    cgst_amount, sgst_amount, igst_amount, cess_amount, line_total
                ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ");

            foreach ($cleanItems as $ci) {
                $stmtItem->bind_param(
                    'isiisdddsdddddddddd',
                    $quotationId,
                    $ci['item_type'],
                    $ci['vehicle_stock_id'],
                    $ci['product_id'],
                    $ci['description'],
                    $ci['qty'],
                    $ci['unit_price'],
                    $ci['discount_amount'],
                    $ci['gst_type'],
                    $ci['taxable_value'],
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
                if (!$stmtItem->execute()) throw new Exception($stmtItem->error);
            }
            $stmtItem->close();

            $conn->commit();
            header('Location: quotation-edit.php?id=' . $quotationId . '&success=1');
            exit;
        } catch (Throwable $e) {
            $conn->rollback();
            $error = $e->getMessage();
        }
    }
}

if (isset($_GET['success'])) {
    $success = 'Quotation updated successfully.';
}

$pageTitle = 'Edit Quotation';
$currentPage = 'quotation-edit';
?>
<!doctype html>
<html lang="en">
<?php include('includes/head.php'); ?>
<body data-sidebar="dark">

<style>
.page-content { padding-bottom:90px!important; }
.item-row { border:1px solid #e9ecef; border-radius:.5rem; padding:14px; margin-bottom:14px; background:#fbfbfb; }
</style>

<?php include('includes/pre-loader.php'); ?>

<div id="layout-wrapper">
<?php include('includes/topbar.php'); ?>
<div class="vertical-menu"><div data-simplebar class="h-100"><?php include('includes/sidebar.php'); ?></div></div>

<div class="main-content">
<div class="page-content">
<div class="container-fluid">

<div class="row mb-3">
    <div class="col-md-7">
        <h4 class="mb-1">Edit Quotation</h4>
        <p class="text-muted mb-0">Edit quotation #<?php echo h($quotation['quotation_no']); ?></p>
    </div>
    <div class="col-md-5 text-md-end mt-3 mt-md-0">
        <a href="quotation-print.php?id=<?php echo (int)$quotationId; ?>" target="_blank" class="btn btn-info">Print</a>
        <a href="quotations.php" class="btn btn-secondary">Back</a>
    </div>
</div>

<?php if ($error): ?><div class="alert alert-danger"><?php echo h($error); ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><?php echo h($success); ?></div><?php endif; ?>

<form method="post" id="quotationForm">
<input type="hidden" name="action" value="update_quotation">

<div class="row">
<div class="col-xl-8">

<div class="card">
<div class="card-header"><h5 class="mb-0">Quotation Details</h5></div>
<div class="card-body">
<div class="row g-3">

<div class="col-md-4">
<label class="form-label">Branch</label>
<select name="branch_id" class="form-select" required>
<option value="">Select Branch</option>
<?php foreach ($branches as $b): ?>
<option value="<?php echo (int)$b['id']; ?>" <?php echo ((int)$form['branch_id'] === (int)$b['id']) ? 'selected' : ''; ?>>
<?php echo h($b['branch_name'] . ' (' . $b['branch_code'] . ')'); ?>
</option>
<?php endforeach; ?>
</select>
</div>

<div class="col-md-4">
<label class="form-label">Quotation No</label>
<input type="text" name="quotation_no" class="form-control" value="<?php echo h($form['quotation_no']); ?>" required>
</div>

<div class="col-md-4">
<label class="form-label">Quotation Date</label>
<input type="datetime-local" name="quotation_date" class="form-control" value="<?php echo h($form['quotation_date']); ?>" required>
</div>

<div class="col-md-4">
<label class="form-label">Valid Until</label>
<input type="date" name="valid_until" class="form-control" value="<?php echo h($form['valid_until']); ?>">
</div>

<div class="col-md-4">
<label class="form-label">Quotation Type</label>
<select name="quotation_type" class="form-select">
<option value="vehicle" <?php echo $form['quotation_type']==='vehicle'?'selected':''; ?>>Vehicle</option>
<option value="product" <?php echo $form['quotation_type']==='product'?'selected':''; ?>>Product</option>
<option value="mixed" <?php echo $form['quotation_type']==='mixed'?'selected':''; ?>>Mixed</option>
</select>
</div>

<div class="col-md-12">
<label class="form-label">Customer</label>
<select name="customer_id" class="form-select" required>
<option value="0">Select Customer</option>
<?php foreach ($customers as $c): ?>
<option value="<?php echo (int)$c['id']; ?>" <?php echo ((int)$form['customer_id'] === (int)$c['id']) ? 'selected' : ''; ?>>
<?php echo h($c['full_name'] . ' - ' . $c['mobile']); ?>
</option>
<?php endforeach; ?>
</select>
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
<div class="card-body"><div id="itemRows"></div></div>
</div>

</div>

<div class="col-xl-4">
<div class="card">
<div class="card-header"><h5 class="mb-0">Summary</h5></div>
<div class="card-body">
<div class="d-flex justify-content-between mb-2"><span>Subtotal:</span><span id="summary_subtotal">₹0.00</span></div>
<div class="d-flex justify-content-between mb-2"><span>Discount:</span><span id="summary_discount">₹0.00</span></div>
<div class="d-flex justify-content-between mb-2"><span>Taxable:</span><span id="summary_taxable">₹0.00</span></div>
<div class="d-flex justify-content-between mb-2"><span>CGST:</span><span id="summary_cgst">₹0.00</span></div>
<div class="d-flex justify-content-between mb-2"><span>SGST:</span><span id="summary_sgst">₹0.00</span></div>
<div class="d-flex justify-content-between mb-2"><span>IGST:</span><span id="summary_igst">₹0.00</span></div>
<div class="d-flex justify-content-between mb-2"><span>CESS:</span><span id="summary_cess">₹0.00</span></div>
<hr>
<div class="d-flex justify-content-between fw-bold"><span>Grand Total:</span><span id="summary_grand_total">₹0.00</span></div>
</div>
</div>

<div class="card">
<div class="card-body">
<button type="submit" class="btn btn-success w-100">Update Quotation</button>
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
    return String(str ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
}
function moneyFmt(v) {
    return '₹' + Number(v || 0).toFixed(2);
}
function productOptionHtml(selectedId = '') {
    let html = '<option value="">Select Product</option>';
    productOptions.forEach(p => {
        const sel = String(selectedId) === String(p.id) ? 'selected' : '';
        // For products, default gst_type is 'exclusive' since products table doesn't have gst_type field
        html += `<option value="${p.id}" data-price="${p.selling_price || 0}" data-gst="${p.gst_percent || 0}" data-gst-type="exclusive" ${sel}>${escapeHtml(p.product_name)} (${escapeHtml(p.product_code || '')})</option>`;
    });
    return html;
}
function vehicleOptionHtml(selectedId = '') {
    let html = '<option value="">Select Vehicle</option>';
    vehicleOptions.forEach(v => {
        const name = `${v.brand_name || 'Vehicle'} - ${v.model_name || '-'} ${v.variant_name || ''} (${v.chassis_no || ''})`;
        const sel = String(selectedId) === String(v.id) ? 'selected' : '';
        // For vehicles, default gst_type is 'inclusive' as per your database
        html += `<option value="${v.id}" data-price="${v.sale_price || 0}" data-gst="${v.gst_percent || 0}" data-gst-type="inclusive" ${sel}>${escapeHtml(name)}</option>`;
    });
    return html;
}
function addItemRow(data = {}) {
    const idx = itemIndex++;
    const itemType = data.item_type || 'product';
    const gstType = data.gst_type || 'exclusive';

    const row = document.createElement('div');
    row.className = 'item-row';
    row.innerHTML = `
    <div class="row g-3">
        <div class="col-md-2">
            <label class="form-label">Item Type</label>
            <select name="items[${idx}][item_type]" class="form-select item-type" onchange="toggleItemType(this); updateSummary();">
                <option value="product" ${itemType==='product'?'selected':''}>Product</option>
                <option value="vehicle" ${itemType==='vehicle'?'selected':''}>Vehicle</option>
                <option value="charge" ${itemType==='charge'?'selected':''}>Charge</option>
            </select>
        </div>

        <div class="col-md-4 product-col">
            <label class="form-label">Product</label>
            <select name="items[${idx}][product_id]" class="form-select" onchange="fillProduct(this); updateSummary();">${productOptionHtml(data.product_id || '')}</select>
        </div>

        <div class="col-md-4 vehicle-col" style="display:none;">
            <label class="form-label">Vehicle</label>
            <select name="items[${idx}][vehicle_stock_id]" class="form-select" onchange="fillVehicle(this); updateSummary();">${vehicleOptionHtml(data.vehicle_stock_id || '')}</select>
        </div>

        <div class="col-md-6">
            <label class="form-label">Description</label>
            <input type="text" name="items[${idx}][description]" class="form-control" value="${escapeHtml(data.description || '')}">
        </div>

        <div class="col-md-2">
            <label class="form-label">Qty</label>
            <input type="number" step="0.01" min="0" name="items[${idx}][qty]" class="form-control calc-field" value="${escapeHtml(data.qty || '1')}">
        </div>

        <div class="col-md-2">
            <label class="form-label">Unit Price</label>
            <input type="number" step="0.01" min="0" name="items[${idx}][unit_price]" class="form-control calc-field" value="${escapeHtml(data.unit_price || '0')}">
        </div>

        <div class="col-md-2">
            <label class="form-label">Discount</label>
            <input type="number" step="0.01" min="0" name="items[${idx}][discount_amount]" class="form-control calc-field" value="${escapeHtml(data.discount_amount || '0')}">
        </div>

        <div class="col-md-2">
            <label class="form-label">GST Type</label>
            <select name="items[${idx}][gst_type]" class="form-select calc-field">
                <option value="exclusive" ${gstType==='exclusive'?'selected':''}>Exclusive</option>
                <option value="inclusive" ${gstType==='inclusive'?'selected':''}>Inclusive</option>
            </select>
        </div>

        <div class="col-md-2">
            <label class="form-label">CGST %</label>
            <input type="number" step="0.01" min="0" name="items[${idx}][cgst_percent]" class="form-control calc-field" value="${escapeHtml(data.cgst_percent || '0')}">
        </div>

        <div class="col-md-2">
            <label class="form-label">SGST %</label>
            <input type="number" step="0.01" min="0" name="items[${idx}][sgst_percent]" class="form-control calc-field" value="${escapeHtml(data.sgst_percent || '0')}">
        </div>

        <div class="col-md-2">
            <label class="form-label">IGST %</label>
            <input type="number" step="0.01" min="0" name="items[${idx}][igst_percent]" class="form-control calc-field" value="${escapeHtml(data.igst_percent || '0')}">
        </div>

        <div class="col-md-2">
            <label class="form-label">CESS %</label>
            <input type="number" step="0.01" min="0" name="items[${idx}][cess_percent]" class="form-control calc-field" value="${escapeHtml(data.cess_percent || '0')}">
        </div>

        <div class="col-md-2 d-flex align-items-end">
            <button type="button" class="btn btn-danger w-100" onclick="this.closest('.item-row').remove(); updateSummary();">Remove</button>
        </div>
    </div>`;

    document.getElementById('itemRows').appendChild(row);
    row.querySelectorAll('.calc-field').forEach(el => el.addEventListener('input', updateSummary));
    toggleItemType(row.querySelector('.item-type'));
    updateSummary();
}
function toggleItemType(selectEl) {
    const row = selectEl.closest('.item-row');
    const type = selectEl.value;
    const productCol = row.querySelector('.product-col');
    const vehicleCol = row.querySelector('.vehicle-col');
    
    if (productCol) productCol.style.display = type === 'product' ? '' : 'none';
    if (vehicleCol) vehicleCol.style.display = type === 'vehicle' ? '' : 'none';
}
function fillProduct(selectEl) {
    const opt = selectEl.options[selectEl.selectedIndex];
    const row = selectEl.closest('.item-row');
    if (!opt || !opt.value) return;
    
    const priceInput = row.querySelector('input[name*="[unit_price]"]');
    if (priceInput) priceInput.value = parseFloat(opt.dataset.price || 0).toFixed(2);
    
    const gstTypeSelect = row.querySelector('select[name*="[gst_type]"]');
    if (gstTypeSelect) gstTypeSelect.value = opt.dataset.gstType || 'exclusive';
    
    const gst = parseFloat(opt.dataset.gst || 0);
    const cgstInput = row.querySelector('input[name*="[cgst_percent]"]');
    const sgstInput = row.querySelector('input[name*="[sgst_percent]"]');
    const igstInput = row.querySelector('input[name*="[igst_percent]"]');
    
    if (cgstInput) cgstInput.value = (gst / 2).toFixed(2);
    if (sgstInput) sgstInput.value = (gst / 2).toFixed(2);
    if (igstInput) igstInput.value = '0.00';
}
function fillVehicle(selectEl) {
    const opt = selectEl.options[selectEl.selectedIndex];
    const row = selectEl.closest('.item-row');
    if (!opt || !opt.value) return;
    
    const priceInput = row.querySelector('input[name*="[unit_price]"]');
    if (priceInput) priceInput.value = parseFloat(opt.dataset.price || 0).toFixed(2);
    
    const gstTypeSelect = row.querySelector('select[name*="[gst_type]"]');
    if (gstTypeSelect) gstTypeSelect.value = opt.dataset.gstType || 'inclusive';
    
    const descInput = row.querySelector('input[name*="[description]"]');
    if (descInput) descInput.value = opt.textContent.trim();
    
    const gst = parseFloat(opt.dataset.gst || 0);
    const cgstInput = row.querySelector('input[name*="[cgst_percent]"]');
    const sgstInput = row.querySelector('input[name*="[sgst_percent]"]');
    const igstInput = row.querySelector('input[name*="[igst_percent]"]');
    
    if (cgstInput) cgstInput.value = (gst / 2).toFixed(2);
    if (sgstInput) sgstInput.value = (gst / 2).toFixed(2);
    if (igstInput) igstInput.value = '0.00';
}
function updateSummary() {
    let subtotal = 0, discount = 0, taxableTotal = 0, cgst = 0, sgst = 0, igst = 0, cess = 0, grand = 0;

    document.querySelectorAll('.item-row').forEach(row => {
        const qty = parseFloat(row.querySelector('input[name*="[qty]"]')?.value) || 0;
        const price = parseFloat(row.querySelector('input[name*="[unit_price]"]')?.value) || 0;
        let disc = parseFloat(row.querySelector('input[name*="[discount_amount]"]')?.value) || 0;
        const gstType = row.querySelector('select[name*="[gst_type]"]')?.value || 'exclusive';
        const cgstP = parseFloat(row.querySelector('input[name*="[cgst_percent]"]')?.value) || 0;
        const sgstP = parseFloat(row.querySelector('input[name*="[sgst_percent]"]')?.value) || 0;
        const igstP = parseFloat(row.querySelector('input[name*="[igst_percent]"]')?.value) || 0;
        const cessP = parseFloat(row.querySelector('input[name*="[cess_percent]"]')?.value) || 0;

        const lineAmount = qty * price;
        disc = Math.min(disc, lineAmount);
        const afterDiscount = Math.max(0, lineAmount - disc);
        const taxPercent = cgstP + sgstP + igstP + cessP;

        let taxable = afterDiscount;
        if (gstType === 'inclusive' && taxPercent > 0) {
            taxable = afterDiscount / (1 + taxPercent / 100);
        }

        const lineCgst = taxable * cgstP / 100;
        const lineSgst = taxable * sgstP / 100;
        const lineIgst = taxable * igstP / 100;
        const lineCess = taxable * cessP / 100;
        const lineTotal = gstType === 'inclusive' ? afterDiscount : taxable + lineCgst + lineSgst + lineIgst + lineCess;

        subtotal += lineAmount;
        discount += disc;
        taxableTotal += taxable;
        cgst += lineCgst;
        sgst += lineSgst;
        igst += lineIgst;
        cess += lineCess;
        grand += lineTotal;
    });

    document.getElementById('summary_subtotal').innerText = moneyFmt(subtotal);
    document.getElementById('summary_discount').innerText = moneyFmt(discount);
    document.getElementById('summary_taxable').innerText = moneyFmt(taxableTotal);
    document.getElementById('summary_cgst').innerText = moneyFmt(cgst);
    document.getElementById('summary_sgst').innerText = moneyFmt(sgst);
    document.getElementById('summary_igst').innerText = moneyFmt(igst);
    document.getElementById('summary_cess').innerText = moneyFmt(cess);
    document.getElementById('summary_grand_total').innerText = moneyFmt(grand);
}

<?php foreach ($form['items'] as $item): ?>
addItemRow(<?php echo json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>);
<?php endforeach; ?>
updateSummary();
</script>

</body>
</html>