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

if ($businessUserId <= 0 || $businessId <= 0) {
    header('Location: login.php');
    exit;
}

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

function statusBadge(string $status, string $type = 'invoice'): string
{
    $status = strtolower(trim($status));
    $class = 'secondary';
    if (in_array($status, ['confirmed', 'paid', 'delivered', 'resolved', 'checked'], true)) $class = 'success';
    if (in_array($status, ['draft', 'partial', 'pending', 'open', 'in_progress', 'waiting_parts', 'ready'], true)) $class = 'warning';
    if (in_array($status, ['cancelled', 'unpaid', 'not_resolved'], true)) $class = 'danger';
    return '<span class="badge bg-' . $class . '">' . h(ucwords(str_replace('_', ' ', $status ?: '-'))) . '</span>';
}

function rateTaxableFromInclusive(float $incl, float $gst): float
{
    return ($gst > 0) ? ($incl / (1 + ($gst / 100))) : $incl;
}

function serviceInclusiveLine(array $row): array
{
    $qty = (float)($row['qty'] ?? 0);
    $rateIncl = (float)($row['unit_price'] ?? 0);
    $discount = (float)($row['discount_amount'] ?? 0);
    $gstPercent = (float)($row['gst_percent'] ?? 0);

    $grossBeforeDiscount = round($qty * $rateIncl, 2);
    $discount = min(max($discount, 0), $grossBeforeDiscount);
    $grossTotal = max(0, round($grossBeforeDiscount - $discount, 2));

    if ($gstPercent > 0) {
        $taxable = round($grossTotal / (1 + ($gstPercent / 100)), 2);
        $tax = round($grossTotal - $taxable, 2);
    } else {
        $taxable = $grossTotal;
        $tax = 0.00;
    }

    return [
        'qty' => $qty,
        'rate_incl' => $rateIncl,
        'discount' => $discount,
        'gst_percent' => $gstPercent,
        'taxable' => $taxable,
        'tax' => $tax,
        'cgst' => round($tax / 2, 2),
        'sgst' => round($tax / 2, 2),
        'total' => $grossTotal,
    ];
}

$requiredTables = ['service_invoices', 'service_job_cards', 'customers', 'branches'];
foreach ($requiredTables as $tbl) {
    if (!tableExists($conn, $tbl)) {
        die(h($tbl) . ' table not found.');
    }
}

/* User validation */
$loggedUser = null;
if (tableExists($conn, 'business_users') && tableExists($conn, 'businesses')) {
    $stmt = $conn->prepare("SELECT bu.id, bu.full_name, bu.role, bu.status, b.business_name, b.status AS business_status
        FROM business_users bu
        INNER JOIN businesses b ON b.id = bu.business_id
        WHERE bu.id = ? AND bu.business_id = ? LIMIT 1");
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

$invoiceId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($invoiceId <= 0) {
    header('Location: service-invoices.php?error=Invalid service invoice id');
    exit;
}

/* Main invoice query - NO remarks column used */
$sql = "SELECT
            si.id,
            si.business_id,
            si.branch_id,
            si.invoice_no,
            si.jobcard_id,
            si.customer_id,
            si.invoice_date,
            si.subtotal,
            si.discount_amount,
            si.cgst_amount,
            si.sgst_amount,
            si.igst_amount,
            si.grand_total,
            si.paid_amount,
            si.balance_amount,
            si.payment_status,
            si.invoice_status,
            si.created_at,

            br.branch_name,
            br.branch_code,
            br.contact_person AS branch_contact_person,
            br.email AS branch_email,
            br.mobile AS branch_mobile,
            br.gstin AS branch_gstin,
            br.address_line1 AS branch_address_line1,
            br.address_line2 AS branch_address_line2,
            br.city AS branch_city,
            br.district AS branch_district,
            br.state AS branch_state,
            br.pincode AS branch_pincode,

            c.full_name AS customer_name,
            c.mobile AS customer_mobile,
            c.alternate_mobile AS customer_alternate_mobile,
            c.email AS customer_email,
            c.gstin AS customer_gstin,
            c.address_line1 AS customer_address_line1,
            c.address_line2 AS customer_address_line2,
            c.city AS customer_city,
            c.state AS customer_state,
            c.pincode AS customer_pincode,

            sj.jobcard_no,
            sj.service_date,
            sj.opening_km,
            sj.fuel_level,
            sj.battery_percentage,
            sj.promised_delivery,
            sj.job_status,
            sj.washing_required,
            sj.road_test_required,
            sj.estimated_amount,
            sj.final_amount,
            sj.customer_voice,
            sj.technician_observation,
            sj.recommendation,
            sj.closed_at,

            cv.registration_no,
            cv.chassis_no,
            cv.engine_no,
            cv.motor_no,
            cv.battery_brand,
            cv.battery_no,
            cv.battery_capacity,
            cv.charger_brand,
            cv.charger_no,
            cv.charger_type,
            cv.color,
            cv.current_km,
            cv.vehicle_type,
            vb.brand_name,
            vm.model_name,
            vm.variant_name
        FROM service_invoices si
        LEFT JOIN branches br ON br.id = si.branch_id
        LEFT JOIN customers c ON c.id = si.customer_id
        LEFT JOIN service_job_cards sj ON sj.id = si.jobcard_id
        LEFT JOIN customer_vehicles cv ON cv.id = sj.customer_vehicle_id
        LEFT JOIN vehicle_brands vb ON vb.id = cv.brand_id
        LEFT JOIN vehicle_models vm ON vm.id = cv.model_id
        WHERE si.id = ? AND si.business_id = ?
        LIMIT 1";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    die('Invoice query prepare failed: ' . h($conn->error));
}
$stmt->bind_param('ii', $invoiceId, $businessId);
$stmt->execute();
$invoice = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$invoice) {
    header('Location: service-invoices.php?error=Service invoice not found');
    exit;
}

$jobcardId = (int)$invoice['jobcard_id'];

$complaints = [];
if (tableExists($conn, 'service_complaints')) {
    $complaints = fetchAllAssoc($conn, "SELECT id, complaint_text, priority, status FROM service_complaints WHERE jobcard_id = {$jobcardId} ORDER BY id ASC");
}

$parts = [];
if (tableExists($conn, 'service_job_part_items')) {
    $parts = fetchAllAssoc($conn, "SELECT
            spi.id,
            spi.product_id,
            spi.qty,
            spi.unit_price,
            spi.discount_amount,
            spi.gst_percent,
            spi.tax_amount,
            spi.line_total,
            spi.stock_deducted,
            p.product_name,
            p.product_code,
            p.item_code,
            p.hsn_code,
            p.unit,
            p.brand_name
        FROM service_job_part_items spi
        LEFT JOIN products p ON p.id = spi.product_id
        WHERE spi.jobcard_id = {$jobcardId}
        ORDER BY spi.id ASC");
}

$labours = [];
if (tableExists($conn, 'service_job_labor_items')) {
    $labours = fetchAllAssoc($conn, "SELECT
            sli.id,
            sli.labor_id,
            sli.description,
            sli.qty,
            sli.unit_price,
            sli.discount_amount,
            sli.gst_percent,
            sli.tax_amount,
            sli.line_total,
            slm.labor_name,
            slm.service_type
        FROM service_job_labor_items sli
        LEFT JOIN service_labor_master slm ON slm.id = sli.labor_id
        WHERE sli.jobcard_id = {$jobcardId}
        ORDER BY sli.id ASC");
}

$payments = [];
if (tableExists($conn, 'payments')) {
    $payments = fetchAllAssoc($conn, "SELECT
            p.id,
            p.payment_date,
            p.amount,
            p.reference_no,
            p.transaction_no,
            p.notes,
            pm.method_name
        FROM payments p
        LEFT JOIN payment_methods pm ON pm.id = p.payment_method_id
        WHERE p.business_id = {$businessId}
          AND p.payment_for = 'service'
          AND p.ref_id = {$invoiceId}
        ORDER BY p.payment_date DESC, p.id DESC");
}

/*
   IMPORTANT: This service invoice page is GST INCLUSIVE for every product and labour.
   Some old rows may have tax_amount/line_total saved as exclusive values.
   So the view recalculates display totals from qty × rate inclusive, then extracts GST.
*/
$taxableAmount = 0.00;
$cgstAmount = 0.00;
$sgstAmount = 0.00;
$igstAmount = 0.00;
$itemsGrossTotal = 0.00;

foreach ($parts as $idx => $p) {
    $calc = serviceInclusiveLine($p);
    $parts[$idx]['display_qty'] = $calc['qty'];
    $parts[$idx]['display_rate_incl'] = $calc['rate_incl'];
    $parts[$idx]['display_discount'] = $calc['discount'];
    $parts[$idx]['display_taxable'] = $calc['taxable'];
    $parts[$idx]['display_tax'] = $calc['tax'];
    $parts[$idx]['display_total'] = $calc['total'];
    $taxableAmount += $calc['taxable'];
    $cgstAmount += $calc['cgst'];
    $sgstAmount += $calc['sgst'];
    $itemsGrossTotal += $calc['total'];
}

foreach ($labours as $idx => $l) {
    $calc = serviceInclusiveLine($l);
    $labours[$idx]['display_qty'] = $calc['qty'];
    $labours[$idx]['display_rate_incl'] = $calc['rate_incl'];
    $labours[$idx]['display_discount'] = $calc['discount'];
    $labours[$idx]['display_taxable'] = $calc['taxable'];
    $labours[$idx]['display_tax'] = $calc['tax'];
    $labours[$idx]['display_total'] = $calc['total'];
    $taxableAmount += $calc['taxable'];
    $cgstAmount += $calc['cgst'];
    $sgstAmount += $calc['sgst'];
    $itemsGrossTotal += $calc['total'];
}

$taxableAmount = round($taxableAmount, 2);
$cgstAmount = round($cgstAmount, 2);
$sgstAmount = round($sgstAmount, 2);
$totalTax = round($cgstAmount + $sgstAmount + $igstAmount, 2);
$discountAmount = (float)$invoice['discount_amount'];
$grandTotal = max(0, round($itemsGrossTotal - $discountAmount, 2));
$paidAmount = (float)$invoice['paid_amount'];
$balanceAmount = max(0, round($grandTotal - $paidAmount, 2));

/* Fallback for very old invoices without part/labour rows */
if (empty($parts) && empty($labours)) {
    $taxableAmount = (float)$invoice['subtotal'];
    $cgstAmount = (float)$invoice['cgst_amount'];
    $sgstAmount = (float)$invoice['sgst_amount'];
    $igstAmount = (float)$invoice['igst_amount'];
    $totalTax = $cgstAmount + $sgstAmount + $igstAmount;
    $grandTotal = (float)$invoice['grand_total'];
    $balanceAmount = (float)$invoice['balance_amount'];
}

$pageTitle = 'View Service Invoice';
$currentPage = 'service-invoices';
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

                <div class="row mb-3 align-items-center">
                    <div class="col-md-7">
                        <h4 class="mb-1">Service Invoice View</h4>
                        <p class="text-muted mb-0">Invoice No: <strong><?= h($invoice['invoice_no']) ?></strong></p>
                    </div>
                    <div class="col-md-5 text-md-end mt-3 mt-md-0">
                        <a href="service-invoices.php" class="btn btn-light"><i class="ri-arrow-left-line me-1"></i>Back</a>
                        <a href="service-invoice-edit.php?id=<?= (int)$invoice['id'] ?>" class="btn btn-primary"><i class="ri-edit-line me-1"></i>Edit</a>
                        <a href="service-invoice-print.php?id=<?= (int)$invoice['id'] ?>" class="btn btn-secondary" target="_blank"><i class="ri-printer-line me-1"></i>Print</a>
                    </div>
                </div>

                <style>
                    .si-card{border:1px solid #e5e7eb;border-radius:14px;box-shadow:0 6px 18px rgba(15,23,42,.05)}
                    .si-title{font-size:14px;font-weight:800;color:#111827;margin-bottom:12px}
                    .info-line{display:flex;gap:10px;margin-bottom:7px;font-size:13px}
                    .info-line span:first-child{min-width:120px;color:#64748b;font-weight:700}
                    .info-line span:last-child{color:#111827;font-weight:600}
                    .summary-row{display:flex;justify-content:space-between;border-bottom:1px dashed #e5e7eb;padding:8px 0;font-size:14px}
                    .summary-row.total{font-size:18px;font-weight:900;color:#0f172a;border-bottom:0;padding-top:12px}
                    .badge-soft{background:#fff7ed;color:#9a3412;border:1px solid #fed7aa;border-radius:999px;padding:4px 9px;font-size:12px;font-weight:800}
                    .table th{background:#f8fafc;font-weight:800;color:#334155;white-space:nowrap}
                    .table td{vertical-align:middle}
                    @media print{.vertical-menu,.navbar-header,.page-title-box,.btn,.footer{display:none!important}.main-content{margin-left:0!important}.page-content{padding:0!important}.card{box-shadow:none!important}}
                </style>

                <div class="row">
                    <div class="col-lg-8">
                        <div class="card si-card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between flex-wrap gap-2 mb-3">
                                    <div>
                                        <div class="si-title mb-1">Invoice Information</div>
                                        <div class="h5 mb-0"><?= h($invoice['invoice_no']) ?></div>
                                    </div>
                                    <div class="text-end">
                                        <div class="mb-1"><?= statusBadge((string)$invoice['invoice_status']) ?></div>
                                        <div><?= statusBadge((string)$invoice['payment_status'], 'payment') ?></div>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="info-line"><span>Date</span><span><?= !empty($invoice['invoice_date']) ? h(date('d M Y h:i A', strtotime($invoice['invoice_date']))) : '-' ?></span></div>
                                        <div class="info-line"><span>Job Card</span><span><?= h($invoice['jobcard_no'] ?: '-') ?></span></div>
                                        <div class="info-line"><span>Service Date</span><span><?= !empty($invoice['service_date']) ? h(date('d M Y h:i A', strtotime($invoice['service_date']))) : '-' ?></span></div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="info-line"><span>Job Status</span><span><?= statusBadge((string)$invoice['job_status']) ?></span></div>
                                        <div class="info-line"><span>Created</span><span><?= !empty($invoice['created_at']) ? h(date('d M Y h:i A', strtotime($invoice['created_at']))) : '-' ?></span></div>
                                        <div class="info-line"><span>Closed</span><span><?= !empty($invoice['closed_at']) ? h(date('d M Y h:i A', strtotime($invoice['closed_at']))) : '-' ?></span></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="card si-card">
                                    <div class="card-body">
                                        <div class="si-title">Branch Details</div>
                                        <div class="info-line"><span>Name</span><span><?= h($invoice['branch_name'] ?: '-') ?></span></div>
                                        <div class="info-line"><span>Code</span><span><?= h($invoice['branch_code'] ?: '-') ?></span></div>
                                        <div class="info-line"><span>GSTIN</span><span><?= h($invoice['branch_gstin'] ?: '-') ?></span></div>
                                        <div class="info-line"><span>Mobile</span><span><?= h($invoice['branch_mobile'] ?: '-') ?></span></div>
                                        <div class="info-line"><span>Address</span><span><?= h(trim(($invoice['branch_address_line1'] ?? '') . ' ' . ($invoice['branch_address_line2'] ?? '') . ' ' . ($invoice['branch_city'] ?? '') . ' ' . ($invoice['branch_state'] ?? '') . ' ' . ($invoice['branch_pincode'] ?? '')) ?: '-') ?></span></div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card si-card">
                                    <div class="card-body">
                                        <div class="si-title">Customer Details</div>
                                        <div class="info-line"><span>Name</span><span><?= h($invoice['customer_name'] ?: '-') ?></span></div>
                                        <div class="info-line"><span>Mobile</span><span><?= h($invoice['customer_mobile'] ?: '-') ?></span></div>
                                        <div class="info-line"><span>Email</span><span><?= h($invoice['customer_email'] ?: '-') ?></span></div>
                                        <div class="info-line"><span>GSTIN</span><span><?= h($invoice['customer_gstin'] ?: '-') ?></span></div>
                                        <div class="info-line"><span>Address</span><span><?= h(trim(($invoice['customer_address_line1'] ?? '') . ' ' . ($invoice['customer_address_line2'] ?? '') . ' ' . ($invoice['customer_city'] ?? '') . ' ' . ($invoice['customer_state'] ?? '') . ' ' . ($invoice['customer_pincode'] ?? '')) ?: '-') ?></span></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card si-card">
                            <div class="card-body">
                                <div class="si-title">Vehicle Details</div>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="info-line"><span>Vehicle</span><span><?= h(trim(($invoice['brand_name'] ?: 'Vehicle') . ' ' . ($invoice['model_name'] ?: '') . ' ' . ($invoice['variant_name'] ?: ''))) ?></span></div>
                                        <div class="info-line"><span>Type</span><span><?= h(ucfirst((string)($invoice['vehicle_type'] ?: '-'))) ?></span></div>
                                        <div class="info-line"><span>Reg No</span><span><?= h($invoice['registration_no'] ?: '-') ?></span></div>
                                        <div class="info-line"><span>Chassis</span><span><?= h($invoice['chassis_no'] ?: '-') ?></span></div>
                                        <div class="info-line"><span>Engine</span><span><?= h($invoice['engine_no'] ?: '-') ?></span></div>
                                        <div class="info-line"><span>Motor</span><span><?= h($invoice['motor_no'] ?: '-') ?></span></div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="info-line"><span>Color</span><span><?= h($invoice['color'] ?: '-') ?></span></div>
                                        <div class="info-line"><span>Opening KM</span><span><?= h($invoice['opening_km'] ?: '0') ?></span></div>
                                        <div class="info-line"><span>Fuel Level</span><span><?= h($invoice['fuel_level'] ?: '-') ?></span></div>
                                        <div class="info-line"><span>Battery %</span><span><?= h($invoice['battery_percentage'] ?: '-') ?></span></div>
                                        <div class="info-line"><span>Washing</span><span><?= ((int)$invoice['washing_required'] === 1) ? 'Yes' : 'No' ?></span></div>
                                        <div class="info-line"><span>Road Test</span><span><?= ((int)$invoice['road_test_required'] === 1) ? 'Yes' : 'No' ?></span></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card si-card">
                            <div class="card-body">
                                <div class="si-title">Complaints & Service Notes</div>
                                <?php if (!empty($complaints)): ?>
                                    <div class="table-responsive mb-3">
                                        <table class="table table-bordered align-middle mb-0">
                                            <thead><tr><th>#</th><th>Complaint</th><th>Priority</th><th>Status</th></tr></thead>
                                            <tbody>
                                                <?php $ci = 1; foreach ($complaints as $c): ?>
                                                    <tr>
                                                        <td><?= $ci++ ?></td>
                                                        <td><?= h($c['complaint_text']) ?></td>
                                                        <td><span class="badge-soft"><?= h(ucfirst($c['priority'])) ?></span></td>
                                                        <td><?= statusBadge((string)$c['status']) ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <p class="text-muted">No complaints added.</p>
                                <?php endif; ?>
                                <div class="row">
                                    <div class="col-md-4"><strong>Customer Voice</strong><p class="text-muted mb-0"><?= nl2br(h($invoice['customer_voice'] ?: '-')) ?></p></div>
                                    <div class="col-md-4"><strong>Technician Observation</strong><p class="text-muted mb-0"><?= nl2br(h($invoice['technician_observation'] ?: '-')) ?></p></div>
                                    <div class="col-md-4"><strong>Recommendation</strong><p class="text-muted mb-0"><?= nl2br(h($invoice['recommendation'] ?: '-')) ?></p></div>
                                </div>
                            </div>
                        </div>

                        <div class="card si-card">
                            <div class="card-body">
                                <div class="si-title">Products Used <span class="text-muted small">(GST Inclusive)</span></div>
                                <div class="table-responsive">
                                    <table class="table table-bordered align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th>#</th><th>Product</th><th>HSN</th><th class="text-end">Qty</th><th class="text-end">Rate Incl. GST</th><th class="text-end">GST %</th><th class="text-end">Taxable</th><th class="text-end">GST Amt</th><th class="text-end">Total</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($parts)): ?>
                                                <?php $pi = 1; foreach ($parts as $p): ?>
                                                    <tr>
                                                        <td><?= $pi++ ?></td>
                                                        <td><strong><?= h($p['product_name'] ?: 'Product') ?></strong><br><small class="text-muted"><?= h(($p['product_code'] ?: $p['item_code'] ?: '-') . ' | ' . ($p['brand_name'] ?: '-')) ?></small></td>
                                                        <td><?= h($p['hsn_code'] ?: '-') ?></td>
                                                        <td class="text-end"><?= number_format((float)$p['display_qty'], 2) ?> <?= h($p['unit'] ?: '') ?></td>
                                                        <td class="text-end"><?= money($p['display_rate_incl']) ?></td>
                                                        <td class="text-end"><?= number_format((float)$p['gst_percent'], 2) ?>%</td>
                                                        <td class="text-end"><?= money($p['display_taxable']) ?></td>
                                                        <td class="text-end"><?= money($p['display_tax']) ?></td>
                                                        <td class="text-end"><strong><?= money($p['display_total']) ?></strong></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr><td colspan="9" class="text-center text-muted py-3">No products used.</td></tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <div class="card si-card">
                            <div class="card-body">
                                <div class="si-title">Labour Charges <span class="text-muted small">(GST Inclusive)</span></div>
                                <div class="table-responsive">
                                    <table class="table table-bordered align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th>#</th><th>Description</th><th>Service Type</th><th class="text-end">Qty</th><th class="text-end">Rate Incl. GST</th><th class="text-end">GST %</th><th class="text-end">Taxable</th><th class="text-end">GST Amt</th><th class="text-end">Total</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($labours)): ?>
                                                <?php $li = 1; foreach ($labours as $l): ?>
                                                    <tr>
                                                        <td><?= $li++ ?></td>
                                                        <td><strong><?= h($l['description']) ?></strong><br><small class="text-muted"><?= h($l['labor_name'] ?: '-') ?></small></td>
                                                        <td><?= h(ucwords(str_replace('_', ' ', (string)($l['service_type'] ?: '-')))) ?></td>
                                                        <td class="text-end"><?= number_format((float)$l['display_qty'], 2) ?></td>
                                                        <td class="text-end"><?= money($l['display_rate_incl']) ?></td>
                                                        <td class="text-end"><?= number_format((float)$l['gst_percent'], 2) ?>%</td>
                                                        <td class="text-end"><?= money($l['display_taxable']) ?></td>
                                                        <td class="text-end"><?= money($l['display_tax']) ?></td>
                                                        <td class="text-end"><strong><?= money($l['display_total']) ?></strong></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr><td colspan="9" class="text-center text-muted py-3">No labour charges.</td></tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-4">
                        <div class="card si-card">
                            <div class="card-body">
                                <div class="si-title">GST Inclusive Summary</div>
                                <div class="summary-row"><span>Taxable Amount</span><strong><?= money($taxableAmount) ?></strong></div>
                                <div class="summary-row"><span>CGST</span><strong><?= money($cgstAmount) ?></strong></div>
                                <div class="summary-row"><span>SGST</span><strong><?= money($sgstAmount) ?></strong></div>
                                <?php if ($igstAmount > 0): ?><div class="summary-row"><span>IGST</span><strong><?= money($igstAmount) ?></strong></div><?php endif; ?>
                                <div class="summary-row"><span>Total GST</span><strong><?= money($totalTax) ?></strong></div>
                                <div class="summary-row"><span>Total Incl. GST</span><strong><?= money($itemsGrossTotal) ?></strong></div>
                                <div class="summary-row"><span>Discount</span><strong>- <?= money($discountAmount) ?></strong></div>
                                <div class="summary-row total"><span>Grand Total</span><span><?= money($grandTotal) ?></span></div>
                                <div class="summary-row"><span>Paid</span><strong><?= money($paidAmount) ?></strong></div>
                                <div class="summary-row"><span>Balance</span><strong class="text-danger"><?= money($balanceAmount) ?></strong></div>
                            </div>
                        </div>

                        <div class="card si-card">
                            <div class="card-body">
                                <div class="si-title">Payment History</div>
                                <?php if (!empty($payments)): ?>
                                    <?php foreach ($payments as $pay): ?>
                                        <div class="border rounded p-2 mb-2">
                                            <div class="d-flex justify-content-between"><strong><?= money($pay['amount']) ?></strong><span><?= h($pay['method_name'] ?: '-') ?></span></div>
                                            <div class="small text-muted"><?= !empty($pay['payment_date']) ? h(date('d M Y h:i A', strtotime($pay['payment_date']))) : '-' ?></div>
                                            <div class="small">Ref: <?= h($pay['reference_no'] ?: $pay['transaction_no'] ?: '-') ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <p class="text-muted mb-0">No payment history found.</p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="card si-card">
                            <div class="card-body">
                                <div class="si-title">Battery / Charger</div>
                                <div class="info-line"><span>Battery Brand</span><span><?= h($invoice['battery_brand'] ?: '-') ?></span></div>
                                <div class="info-line"><span>Battery No</span><span><?= h($invoice['battery_no'] ?: '-') ?></span></div>
                                <div class="info-line"><span>Capacity</span><span><?= h($invoice['battery_capacity'] ?: '-') ?></span></div>
                                <div class="info-line"><span>Charger Brand</span><span><?= h($invoice['charger_brand'] ?: '-') ?></span></div>
                                <div class="info-line"><span>Charger No</span><span><?= h($invoice['charger_no'] ?: '-') ?></span></div>
                                <div class="info-line"><span>Charger Type</span><span><?= h($invoice['charger_type'] ?: '-') ?></span></div>
                            </div>
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
</body>
</html>
