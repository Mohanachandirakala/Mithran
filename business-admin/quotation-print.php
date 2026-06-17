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

function h($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function tableExists(mysqli $conn, string $table): bool
{
    $stmt = $conn->prepare("
        SELECT 1 FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = ?
        LIMIT 1
    ");
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
    $stmt = $conn->prepare("
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = ?
        AND COLUMN_NAME = ?
        LIMIT 1
    ");
    if (!$stmt) return false;
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $res = $stmt->get_result();
    $ok = $res && $res->num_rows > 0;
    $stmt->close();
    return $ok;
}

function formatDate($date, $format = 'd-m-Y')
{
    if (empty($date) || $date === '0000-00-00') return '';
    return date($format, strtotime($date));
}

function formatDateTime($datetime, $format = 'd-m-Y h:i A')
{
    if (empty($datetime) || $datetime === '0000-00-00 00:00:00') return '';
    return date($format, strtotime($datetime));
}

function formatMoney($amount)
{
    return '₹ ' . number_format((float)$amount, 2);
}

function getStatusBadge($status)
{
    $badges = [
        'draft' => 'secondary',
        'sent' => 'info',
        'approved' => 'primary',
        'rejected' => 'danger',
        'converted' => 'success',
        'expired' => 'dark'
    ];
    $color = $badges[$status] ?? 'secondary';
    return '<span class="badge bg-' . $color . '">' . ucfirst((string)$status) . '</span>';
}

function logoSrc(string $path): string
{
    $path = trim($path);
    if ($path === '') return '';

    if (preg_match('/^https?:\/\//i', $path)) {
        return $path;
    }

    if (file_exists(__DIR__ . '/' . $path)) {
        return $path;
    }

    return '../' . ltrim($path, '/');
}

function numberToWords($num)
{
    $num = (int)$num;
    if ($num === 0) return 'Zero';

    $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
    $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

    $words = '';

    $crores = floor($num / 10000000);
    $num %= 10000000;

    $lakhs = floor($num / 100000);
    $num %= 100000;

    $thousands = floor($num / 1000);
    $num %= 1000;

    $hundreds = floor($num / 100);
    $num %= 100;

    $twoDigitWords = function ($n) use ($ones, $tens) {
        if ($n < 20) return $ones[$n];
        return trim($tens[floor($n / 10)] . ' ' . $ones[$n % 10]);
    };

    if ($crores > 0) $words .= $twoDigitWords($crores) . ' Crore ';
    if ($lakhs > 0) $words .= $twoDigitWords($lakhs) . ' Lakh ';
    if ($thousands > 0) $words .= $twoDigitWords($thousands) . ' Thousand ';
    if ($hundreds > 0) $words .= $ones[$hundreds] . ' Hundred ';
    if ($num > 0) $words .= $twoDigitWords($num);

    return trim($words);
}

$quotationId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($quotationId <= 0) {
    header('Location: quotations.php?error=' . urlencode('Invalid quotation ID.'));
    exit;
}

$branchLogoSelect = '';
$possibleBranchLogoColumns = ['logo_path', 'branch_logo', 'logo', 'image', 'branch_logo_path'];

foreach ($possibleBranchLogoColumns as $col) {
    if (columnExists($conn, 'branches', $col)) {
        $branchLogoSelect .= ", b.`{$col}` AS branch_{$col}";
    }
}

$quotation = null;
$stmt = $conn->prepare("
    SELECT 
        q.*,
        c.full_name AS customer_name,
        c.mobile AS customer_mobile,
        c.alternate_mobile AS customer_alternate_mobile,
        c.email AS customer_email,
        c.gstin AS customer_gstin,
        c.address_line1 AS customer_address_line1,
        c.address_line2 AS customer_address_line2,
        c.city AS customer_city,
        c.district AS customer_district,
        c.state AS customer_state,
        c.pincode AS customer_pincode,

        b.branch_name,
        b.branch_code,
        b.address_line1 AS branch_address_line1,
        b.address_line2 AS branch_address_line2,
        b.city AS branch_city,
        b.district AS branch_district,
        b.state AS branch_state,
        b.pincode AS branch_pincode,
        b.gstin AS branch_gstin,
        b.mobile AS branch_mobile,
        b.email AS branch_email
        {$branchLogoSelect},

        bu.full_name AS created_by_name
    FROM quotations q
    INNER JOIN customers c ON c.id = q.customer_id
    INNER JOIN branches b ON b.id = q.branch_id
    LEFT JOIN business_users bu ON bu.id = q.created_by
    WHERE q.id = ? AND q.business_id = ?
    LIMIT 1
");
if ($stmt) {
    $stmt->bind_param('ii', $quotationId, $businessId);
    $stmt->execute();
    $quotation = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if (!$quotation) {
    header('Location: quotations.php?error=' . urlencode('Quotation not found.'));
    exit;
}

$quotationItems = [];
$stmt = $conn->prepare("
    SELECT 
        qi.*,
        p.product_name,
        p.product_code,
        p.unit,
        vs.chassis_no,
        vs.engine_no,
        vs.motor_no,
        vs.battery_no,
        vs.color,
        vm.model_name,
        vm.variant_name,
        vb.brand_name
    FROM quotation_items qi
    LEFT JOIN products p ON p.id = qi.product_id
    LEFT JOIN vehicle_stock vs ON vs.id = qi.vehicle_stock_id
    LEFT JOIN vehicle_models vm ON vm.id = vs.model_id
    LEFT JOIN vehicle_brands vb ON vb.id = vm.brand_id
    WHERE qi.quotation_id = ?
    ORDER BY qi.id ASC
");
if ($stmt) {
    $stmt->bind_param('i', $quotationId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $quotationItems[] = $row;
    }
    $stmt->close();
}

$businessSettings = [];
if (tableExists($conn, 'business_settings')) {
    $stmt = $conn->prepare("SELECT * FROM business_settings WHERE business_id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $businessId);
        $stmt->execute();
        $businessSettings = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
    }
}

$showLogo = (int)($businessSettings['show_logo_on_invoice'] ?? 1);

$business = [];
$stmt = $conn->prepare("SELECT * FROM businesses WHERE id = ? LIMIT 1");
if ($stmt) {
    $stmt->bind_param('i', $businessId);
    $stmt->execute();
    $business = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
}

$logoPath = '';

if ($showLogo === 1) {
    foreach ($possibleBranchLogoColumns as $col) {
        $alias = 'branch_' . $col;
        if (!empty($quotation[$alias])) {
            $tryPath = trim((string)$quotation[$alias]);

            if (
                preg_match('/^https?:\/\//i', $tryPath) ||
                file_exists(__DIR__ . '/' . $tryPath) ||
                file_exists(__DIR__ . '/../' . $tryPath)
            ) {
                $logoPath = $tryPath;
                break;
            }
        }
    }

    if ($logoPath === '') {
        $possibleBusinessLogoColumns = ['logo_path', 'business_logo', 'logo', 'image'];

        foreach ($possibleBusinessLogoColumns as $col) {
            if (!empty($business[$col])) {
                $tryPath = trim((string)$business[$col]);

                if (
                    preg_match('/^https?:\/\//i', $tryPath) ||
                    file_exists(__DIR__ . '/' . $tryPath) ||
                    file_exists(__DIR__ . '/../' . $tryPath)
                ) {
                    $logoPath = $tryPath;
                    break;
                }
            }
        }
    }
}

$businessName = $business['business_name'] ?? ($quotation['branch_name'] ?? 'Business Name');

$branchAddr = [];
if (!empty($quotation['branch_address_line1'])) $branchAddr[] = $quotation['branch_address_line1'];
if (!empty($quotation['branch_address_line2'])) $branchAddr[] = $quotation['branch_address_line2'];
if (!empty($quotation['branch_city'])) $branchAddr[] = $quotation['branch_city'];
if (!empty($quotation['branch_district'])) $branchAddr[] = $quotation['branch_district'];
if (!empty($quotation['branch_state'])) $branchAddr[] = $quotation['branch_state'];
if (!empty($quotation['branch_pincode'])) $branchAddr[] = $quotation['branch_pincode'];

$customerAddr = [];
if (!empty($quotation['customer_address_line1'])) $customerAddr[] = $quotation['customer_address_line1'];
if (!empty($quotation['customer_address_line2'])) $customerAddr[] = $quotation['customer_address_line2'];
if (!empty($quotation['customer_city'])) $customerAddr[] = $quotation['customer_city'];
if (!empty($quotation['customer_state'])) $customerAddr[] = $quotation['customer_state'];
if (!empty($quotation['customer_pincode'])) $customerAddr[] = $quotation['customer_pincode'];

$grand = (float)$quotation['grand_total'];
$grandTotalWords = numberToWords(floor($grand));
$paise = round(($grand - floor($grand)) * 100);
if ($paise > 0) {
    $grandTotalWords .= ' and ' . $paise . ' Paise';
}
$grandTotalWords .= ' Only';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Quotation #<?php echo h($quotation['quotation_no']); ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<style>
*{margin:0;padding:0;box-sizing:border-box}
body{
    font-family:Arial, sans-serif;
    font-size:10px;
    color:#111827;
    background:#eef2f7;
    padding:10px;
}
.print-container{
    width:210mm;
    min-height:297mm;
    max-height:297mm;
    margin:0 auto;
    background:#fff;
    padding:8mm;
    overflow:hidden;
    border:1px solid #e5e7eb;
}
.header{
    border:2px solid #f6ad22;
    border-radius:10px;
    padding:8px 10px;
    margin-bottom:8px;
    background:linear-gradient(135deg,#fff8e7,#fff);
}
.header-row{
    display:flex;
    align-items:center;
    gap:10px;
}
.logo-box{
    width:70px;
    height:58px;
    border:1px solid #f6ad22;
    border-radius:8px;
    display:flex;
    align-items:center;
    justify-content:center;
    overflow:hidden;
    background:#fff;
    flex-shrink:0;
}
.logo-box img{
    max-width:66px;
    max-height:54px;
}
.company-box{
    flex:1;
    text-align:center;
}
.company-name{
    font-size:18px;
    font-weight:800;
    color:#111827;
    text-transform:uppercase;
    line-height:1.1;
}
.company-address{
    font-size:9px;
    color:#4b5563;
    margin-top:3px;
    line-height:1.25;
}
.company-contact{
    font-size:9px;
    color:#111827;
    margin-top:3px;
    font-weight:600;
}
.quote-box{
    width:130px;
    text-align:right;
    flex-shrink:0;
}
.quote-title{
    font-size:20px;
    font-weight:900;
    color:#d97706;
    letter-spacing:1px;
}
.quote-no{
    margin-top:5px;
    font-size:10px;
    font-weight:700;
}
.info-section{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:8px;
    margin-bottom:8px;
}
.info-box{
    border:1px solid #e5e7eb;
    border-radius:8px;
    overflow:hidden;
}
.info-box-title{
    background:#111827;
    color:#fff;
    padding:5px 8px;
    font-size:10px;
    font-weight:800;
}
.info-box.bill .info-box-title{
    background:#f6ad22;
    color:#111827;
}
.info-body{
    padding:6px 8px;
}
.info-row{
    display:grid;
    grid-template-columns:78px 1fr;
    gap:4px;
    margin-bottom:3px;
    line-height:1.25;
}
.info-label{
    font-weight:700;
    color:#6b7280;
}
.info-value{
    font-weight:600;
    color:#111827;
}
.badge{
    display:inline-block;
    padding:2px 7px;
    border-radius:12px;
    color:#fff;
    font-size:8px;
    font-weight:700;
}
.bg-secondary{background:#6c757d}
.bg-info{background:#0dcaf0}
.bg-primary{background:#0d6efd}
.bg-danger{background:#dc3545}
.bg-success{background:#198754}
.bg-dark{background:#212529}
.items-table{
    width:100%;
    border-collapse:collapse;
    margin-bottom:8px;
}
.items-table th{
    background:#111827;
    color:#fff;
    border:1px solid #111827;
    padding:5px 4px;
    font-size:9px;
    text-align:center;
}
.items-table td{
    border:1px solid #d1d5db;
    padding:4px;
    font-size:9px;
    text-align:center;
    vertical-align:top;
    line-height:1.2;
}
.items-table tbody tr:nth-child(even){
    background:#fafafa;
}
.text-left{text-align:left!important}
.desc-text{
    font-weight:700;
}
.desc-sub{
    color:#6b7280;
    font-size:8px;
    margin-top:2px;
}
.bottom-grid{
    display:grid;
    grid-template-columns:1fr 280px;
    gap:8px;
    align-items:start;
}
.amount-words{
    border:1px dashed #f6ad22;
    border-radius:8px;
    padding:7px;
    background:#fffbeb;
    font-size:9px;
    line-height:1.35;
}
.amount-words strong{
    display:block;
    color:#92400e;
    margin-bottom:3px;
}
.summary-table{
    width:100%;
    border-collapse:collapse;
    border:1px solid #d1d5db;
}
.summary-table td{
    padding:5px 7px;
    border-bottom:1px solid #e5e7eb;
    font-size:10px;
}
.summary-table .label{
    font-weight:700;
    text-align:right;
    color:#374151;
}
.summary-table .value{
    text-align:right;
    font-weight:800;
}
.summary-table .total-row td{
    background:#f6ad22;
    color:#111827;
    font-size:12px;
    font-weight:900;
}
.terms-row{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:8px;
    margin-top:8px;
}
.terms-section{
    border:1px solid #e5e7eb;
    border-radius:8px;
    padding:7px;
    font-size:9px;
    line-height:1.3;
    min-height:48px;
}
.terms-title{
    font-weight:800;
    color:#d97706;
    margin-bottom:4px;
}
.signature-row{
    display:grid;
    grid-template-columns:1fr 190px;
    gap:8px;
    margin-top:8px;
    align-items:end;
}
.footer{
    font-size:8.5px;
    color:#6b7280;
    line-height:1.35;
}
.sign-box{
    height:58px;
    border:1px solid #d1d5db;
    border-radius:8px;
    padding:6px;
    text-align:center;
    display:flex;
    flex-direction:column;
    justify-content:space-between;
}
.sign-title{
    font-size:9px;
    font-weight:800;
}
.sign-line{
    border-top:1px solid #9ca3af;
    padding-top:4px;
    font-size:9px;
    font-weight:700;
    color:#4b5563;
}
.print-btn,
.back-btn{
    position:fixed;
    bottom:16px;
    padding:10px 18px;
    border-radius:8px;
    color:#fff;
    border:0;
    font-weight:bold;
    cursor:pointer;
    text-decoration:none;
    box-shadow:0 2px 10px rgba(0,0,0,.2);
}
.print-btn{
    right:20px;
    background:#f6ad22;
    color:#111827;
}
.back-btn{
    left:20px;
    background:#111827;
}
@media print{
    @page{
        size:A4;
        margin:0;
    }
    html,body{
        width:210mm;
        height:297mm;
        background:#fff;
        padding:0;
        margin:0;
        overflow:hidden;
        -webkit-print-color-adjust:exact;
        print-color-adjust:exact;
    }
    .print-container{
        width:210mm;
        height:297mm;
        max-height:297mm;
        border:0;
        margin:0;
        padding:7mm;
        overflow:hidden;
    }
    .no-print{display:none!important}
    .header{margin-bottom:6px}
    .info-section{margin-bottom:6px}
    .items-table{margin-bottom:6px}
}
</style>
</head>

<body>

<div class="print-container">

    <div class="header">
        <div class="header-row">
            <div class="logo-box">
                <?php if ($showLogo === 1 && $logoPath !== ''): ?>
                    <img src="<?php echo h(logoSrc($logoPath)); ?>" alt="Logo">
                <?php else: ?>
                    <strong>LOGO</strong>
                <?php endif; ?>
            </div>

            <div class="company-box">
                <div class="company-name"><?php echo h($businessName); ?></div>
                <div class="company-address"><?php echo h(implode(', ', $branchAddr)); ?></div>
                <div class="company-contact">
                    <?php
                    $contacts = [];
                    if (!empty($quotation['branch_mobile'])) $contacts[] = 'Phone: ' . $quotation['branch_mobile'];
                    if (!empty($quotation['branch_email'])) $contacts[] = 'Email: ' . $quotation['branch_email'];
                    if (!empty($quotation['branch_gstin'])) $contacts[] = 'GSTIN: ' . $quotation['branch_gstin'];
                    echo h(implode(' | ', $contacts));
                    ?>
                </div>
            </div>

            <div class="quote-box">
                <div class="quote-title">QUOTATION</div>
                <div class="quote-no"><?php echo h($quotation['quotation_no']); ?></div>
            </div>
        </div>
    </div>

    <div class="info-section">
        <div class="info-box bill">
            <div class="info-box-title">BILL TO</div>
            <div class="info-body">
                <div class="info-row"><div class="info-label">Name</div><div class="info-value"><?php echo h($quotation['customer_name']); ?></div></div>
                <div class="info-row"><div class="info-label">Mobile</div><div class="info-value"><?php echo h($quotation['customer_mobile']); ?></div></div>
                <?php if (!empty($quotation['customer_email'])): ?>
                    <div class="info-row"><div class="info-label">Email</div><div class="info-value"><?php echo h($quotation['customer_email']); ?></div></div>
                <?php endif; ?>
                <?php if (!empty($quotation['customer_gstin'])): ?>
                    <div class="info-row"><div class="info-label">GSTIN</div><div class="info-value"><?php echo h($quotation['customer_gstin']); ?></div></div>
                <?php endif; ?>
                <div class="info-row"><div class="info-label">Address</div><div class="info-value"><?php echo h(!empty($customerAddr) ? implode(', ', $customerAddr) : '-'); ?></div></div>
            </div>
        </div>

        <div class="info-box">
            <div class="info-box-title">QUOTATION DETAILS</div>
            <div class="info-body">
                <div class="info-row"><div class="info-label">Date</div><div class="info-value"><?php echo h(formatDateTime($quotation['quotation_date'])); ?></div></div>
                <?php if (!empty($quotation['valid_until'])): ?>
                    <div class="info-row"><div class="info-label">Valid Until</div><div class="info-value"><?php echo h(formatDate($quotation['valid_until'])); ?></div></div>
                <?php endif; ?>
                <div class="info-row"><div class="info-label">Status</div><div class="info-value"><?php echo getStatusBadge($quotation['status']); ?></div></div>
                <div class="info-row"><div class="info-label">Type</div><div class="info-value"><?php echo h(ucfirst((string)$quotation['quotation_type'])); ?></div></div>
                <?php if (!empty($quotation['created_by_name'])): ?>
                    <div class="info-row"><div class="info-label">Created By</div><div class="info-value"><?php echo h($quotation['created_by_name']); ?></div></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <table class="items-table">
        <thead>
        <tr>
            <th width="4%">#</th>
            <th width="35%" class="text-left">Description</th>
            <th width="7%">Qty</th>
            <th width="12%">Rate</th>
            <th width="10%">Disc</th>
            <th width="12%">Taxable</th>
            <th width="9%">Tax</th>
            <th width="11%">Total</th>
        </tr>
        </thead>
        <tbody>
        <?php if (!empty($quotationItems)): ?>
            <?php $srNo = 1; foreach ($quotationItems as $item): ?>
                <?php
                $description = '';
                $sub = '';

                if (($item['item_type'] ?? '') === 'product') {
                    $description = $item['product_name'] ?: $item['description'];
                    if (!empty($item['product_code'])) $sub = 'Code: ' . $item['product_code'];
                } elseif (($item['item_type'] ?? '') === 'vehicle') {
                    $parts = [];
                    if (!empty($item['brand_name'])) $parts[] = $item['brand_name'];
                    if (!empty($item['model_name'])) $parts[] = $item['model_name'];
                    if (!empty($item['variant_name'])) $parts[] = $item['variant_name'];
                    $description = trim(implode(' ', $parts));
                    if ($description === '') $description = $item['description'];

                    $d = [];
                    if (!empty($item['chassis_no'])) $d[] = 'Ch: ' . $item['chassis_no'];
                    if (!empty($item['engine_no'])) $d[] = 'Eng: ' . $item['engine_no'];
                    if (!empty($item['motor_no'])) $d[] = 'Motor: ' . $item['motor_no'];
                    if (!empty($item['battery_no'])) $d[] = 'Bat: ' . $item['battery_no'];
                    if (!empty($item['color'])) $d[] = 'Color: ' . $item['color'];
                    $sub = implode(' | ', $d);
                } else {
                    $description = $item['description'];
                }

                $taxPercent = max(
                    ((float)$item['cgst_percent'] + (float)$item['sgst_percent']),
                    (float)$item['igst_percent']
                );

                $taxAmount =
                    (float)$item['cgst_amount'] +
                    (float)$item['sgst_amount'] +
                    (float)$item['igst_amount'] +
                    (float)$item['cess_amount'];
                ?>
                <tr>
                    <td><?php echo $srNo++; ?></td>
                    <td class="text-left">
                        <div class="desc-text"><?php echo h($description); ?></div>
                        <?php if ($sub !== ''): ?><div class="desc-sub"><?php echo h($sub); ?></div><?php endif; ?>
                    </td>
                    <td><?php echo number_format((float)$item['qty'], 2); ?></td>
                    <td><?php echo formatMoney($item['unit_price']); ?></td>
                    <td><?php echo formatMoney($item['discount_amount']); ?></td>
                    <td><?php echo formatMoney($item['taxable_value']); ?></td>
                    <td>
                        <?php echo $taxPercent > 0 ? number_format($taxPercent, 2) . '%<br>' : ''; ?>
                        <?php echo formatMoney($taxAmount); ?>
                    </td>
                    <td><strong><?php echo formatMoney($item['line_total']); ?></strong></td>
                </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr><td colspan="8">No items found.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <div class="bottom-grid">
        <div class="amount-words">
            <strong>Amount in Words</strong>
            <?php echo h($grandTotalWords); ?>
        </div>

        <table class="summary-table">
            <tr><td class="label">Subtotal</td><td class="value"><?php echo formatMoney($quotation['subtotal']); ?></td></tr>
            <tr><td class="label">Discount</td><td class="value"><?php echo formatMoney($quotation['discount_amount']); ?></td></tr>

            <?php if ((float)$quotation['cgst_amount'] > 0): ?>
                <tr><td class="label">CGST</td><td class="value"><?php echo formatMoney($quotation['cgst_amount']); ?></td></tr>
            <?php endif; ?>

            <?php if ((float)$quotation['sgst_amount'] > 0): ?>
                <tr><td class="label">SGST</td><td class="value"><?php echo formatMoney($quotation['sgst_amount']); ?></td></tr>
            <?php endif; ?>

            <?php if ((float)$quotation['igst_amount'] > 0): ?>
                <tr><td class="label">IGST</td><td class="value"><?php echo formatMoney($quotation['igst_amount']); ?></td></tr>
            <?php endif; ?>

            <?php if ((float)$quotation['cess_amount'] > 0): ?>
                <tr><td class="label">CESS</td><td class="value"><?php echo formatMoney($quotation['cess_amount']); ?></td></tr>
            <?php endif; ?>

            <?php if ((float)$quotation['round_off'] != 0): ?>
                <tr><td class="label">Round Off</td><td class="value"><?php echo formatMoney($quotation['round_off']); ?></td></tr>
            <?php endif; ?>

            <tr class="total-row"><td class="label">Grand Total</td><td class="value"><?php echo formatMoney($quotation['grand_total']); ?></td></tr>
        </table>
    </div>

    <div class="terms-row">
        <div class="terms-section">
            <div class="terms-title">Terms & Conditions</div>
            <?php echo !empty($quotation['terms_conditions']) ? nl2br(h($quotation['terms_conditions'])) : 'No terms added.'; ?>
        </div>

        <div class="terms-section">
            <div class="terms-title">Customer Note</div>
            <?php echo !empty($quotation['customer_note']) ? nl2br(h($quotation['customer_note'])) : 'No note added.'; ?>
        </div>
    </div>

    <div class="signature-row">
        <div class="footer">
            This is a computer generated quotation and does not require a physical signature.<br>
            Generated on: <?php echo date('d-m-Y h:i A'); ?><br>
            <?php echo h($businessName); ?> - Thank you for your business!
        </div>

        <div class="sign-box">
            <div class="sign-title">For <?php echo h(strtoupper($businessName)); ?></div>
            <div class="sign-line">Authorised Signature</div>
        </div>
    </div>

</div>

<button class="print-btn no-print" onclick="startAutoPrint();">Print</button>
<a href="quotations.php" class="back-btn no-print">Back</a>

<script>
(function () {
    var redirected = false;
    var fallbackTimer = null;
    var backUrl = 'quotations.php';

    function redirectBack() {
        if (redirected) return;
        redirected = true;
        window.location.replace(backUrl);
    }

    function scheduleRedirect(delay) {
        if (fallbackTimer) {
            clearTimeout(fallbackTimer);
        }
        fallbackTimer = setTimeout(redirectBack, delay || 300);
    }

    window.startAutoPrint = function () {
        try {
            setTimeout(function () {
                window.focus();
                window.print();

                // Fallback for browsers where afterprint is not fired.
                // In most browsers this timer resumes only after print is completed or cancelled.
                scheduleRedirect(1200);
            }, 350);
        } catch (e) {
            redirectBack();
        }
    };

    window.addEventListener('afterprint', function () {
        scheduleRedirect(150);
    });

    if (window.matchMedia) {
        var mediaQueryList = window.matchMedia('print');

        if (typeof mediaQueryList.addEventListener === 'function') {
            mediaQueryList.addEventListener('change', function (event) {
                if (!event.matches) {
                    scheduleRedirect(150);
                }
            });
        } else if (typeof mediaQueryList.addListener === 'function') {
            mediaQueryList.addListener(function (event) {
                if (!event.matches) {
                    scheduleRedirect(150);
                }
            });
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        startAutoPrint();
    });
})();
</script>

</body>
</html>