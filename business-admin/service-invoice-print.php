<?php
/*
 * service-invoice-print.php
 * Service Invoice PDF Print - GST Inclusive
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);

session_start();
date_default_timezone_set('Asia/Kolkata');

require_once __DIR__ . '/includes/config.php';

/* ---------------------------------------------------------
   FPDF LOADER
--------------------------------------------------------- */
$fpdfPaths = [
    __DIR__ . '/lib/fpdf.php',
    __DIR__ . '/fpdf/fpdf.php',
    __DIR__ . '/libs/fpdf/fpdf.php',
    __DIR__ . '/vendor/fpdf/fpdf.php',
];

$fpdfLoaded = false;
foreach ($fpdfPaths as $fpdfPath) {
    if (is_file($fpdfPath)) {
        require_once $fpdfPath;
        $fpdfLoaded = true;
        break;
    }
}

if (!$fpdfLoaded || !class_exists('FPDF')) {
    die('FPDF file not found. Checked: ' . implode(', ', $fpdfPaths));
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not available.');
}
$conn->set_charset('utf8mb4');

/* ---------------------------------------------------------
   HELPERS
--------------------------------------------------------- */
function h($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function safeText($value): string
{
    $text = (string)($value ?? '');
    $converted = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $text);
    return $converted !== false ? $converted : preg_replace('/[^\x20-\x7E]/', '', $text);
}

function moneyPdf($amount): string
{
    return number_format((float)$amount, 2, '.', ',');
}

function qtyPdf($qty): string
{
    $qty = (float)$qty;
    if (abs($qty - round($qty)) < 0.00001) {
        return number_format($qty, 0);
    }
    return rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.');
}

function formatDateTimePdf($dateValue): string
{
    if (empty($dateValue) || $dateValue === '0000-00-00' || $dateValue === '0000-00-00 00:00:00') {
        return '-';
    }
    $ts = strtotime((string)$dateValue);
    return $ts ? date('d-m-Y h:i A', $ts) : '-';
}

function formatDatePdf($dateValue): string
{
    if (empty($dateValue) || $dateValue === '0000-00-00' || $dateValue === '0000-00-00 00:00:00') {
        return '-';
    }
    $ts = strtotime((string)$dateValue);
    return $ts ? date('d-m-Y', $ts) : '-';
}

function tableExists(mysqli $conn, string $table): bool
{
    $stmt = $conn->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1");
    if (!$stmt) return false;
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $ok = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $ok;
}

function columnExists(mysqli $conn, string $table, string $column): bool
{
    $stmt = $conn->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
    if (!$stmt) return false;
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $ok = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $ok;
}

function valueOrDash($value): string
{
    $value = trim((string)($value ?? ''));
    return ($value === '' || $value === '0') ? '-' : $value;
}

function calcInclusiveLine(float $qty, float $rateIncl, float $discount, float $gstPercent): array
{
    $gross = round($qty * $rateIncl, 2);
    $discount = min(max(0, $discount), $gross);
    $lineTotal = round($gross - $discount, 2);

    if ($gstPercent > 0 && $lineTotal > 0) {
        $taxable = round($lineTotal / (1 + ($gstPercent / 100)), 2);
        $tax = round($lineTotal - $taxable, 2);
    } else {
        $taxable = $lineTotal;
        $tax = 0.00;
    }

    return [
        'gross' => $gross,
        'discount' => $discount,
        'taxable' => $taxable,
        'tax' => $tax,
        'cgst' => round($tax / 2, 2),
        'sgst' => round($tax / 2, 2),
        'total' => $lineTotal,
    ];
}

function getLogoPath(array $biz): array
{
    $path = trim((string)($biz['logo_path'] ?? ''));
    if ($path === '') return ['', false];

    $candidates = [
        __DIR__ . '/' . ltrim($path, '/'),
        dirname(__DIR__) . '/' . ltrim($path, '/'),
        __DIR__ . '/uploads/business/' . basename($path),
    ];

    foreach ($candidates as $candidate) {
        if (is_file($candidate)) return [$candidate, true];
    }

    return ['', false];
}

/* ---------------------------------------------------------
   AUTH CHECK
--------------------------------------------------------- */
$businessUserId = isset($_SESSION['business_user_id']) ? (int)$_SESSION['business_user_id'] : 0;
if ($businessUserId <= 0 && isset($_SESSION['user_id'])) {
    $businessUserId = (int)$_SESSION['user_id'];
}

$businessId = isset($_SESSION['business_id']) ? (int)$_SESSION['business_id'] : 0;

if ($businessUserId <= 0 || $businessId <= 0) {
    header('Location: login.php');
    exit;
}

$invoiceId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($invoiceId <= 0) {
    die('Invalid service invoice ID.');
}

$requiredTables = ['service_invoices', 'service_job_cards', 'customers', 'branches'];
foreach ($requiredTables as $table) {
    if (!tableExists($conn, $table)) {
        die($table . ' table not found.');
    }
}

/* ---------------------------------------------------------
   FETCH BUSINESS / SETTINGS
--------------------------------------------------------- */
$biz = [];
$stmt = $conn->prepare("SELECT business_name, owner_name, gstin, pan_no, address_line1, address_line2, city, district, state, pincode, mobile, email, logo_path FROM businesses WHERE id = ? LIMIT 1");
if ($stmt) {
    $stmt->bind_param('i', $businessId);
    $stmt->execute();
    $biz = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
}

if (!$biz) {
    $biz = [
        'business_name' => 'Business',
        'gstin' => '',
        'pan_no' => '',
        'address_line1' => '',
        'address_line2' => '',
        'city' => '',
        'district' => '',
        'state' => '',
        'pincode' => '',
        'mobile' => '',
        'email' => '',
        'logo_path' => '',
    ];
}

[$logoPath, $hasLogo] = getLogoPath($biz);

/* ---------------------------------------------------------
   FETCH SERVICE INVOICE
--------------------------------------------------------- */
$stmt = $conn->prepare("
    SELECT
        si.*,
        br.branch_name,
        br.branch_code,
        br.mobile AS branch_mobile,
        br.email AS branch_email,
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
        c.district AS customer_district,
        c.state AS customer_state,
        c.pincode AS customer_pincode,

        sj.jobcard_no,
        sj.service_date,
        sj.opening_km,
        sj.fuel_level,
        sj.battery_percentage,
        sj.job_status,
        sj.customer_voice,
        sj.technician_observation,
        sj.recommendation,
        sj.washing_required,
        sj.road_test_required,
        sj.customer_vehicle_id,

        cv.registration_no,
        cv.chassis_no,
        cv.engine_no,
        cv.motor_no,
        cv.color,
        cv.battery_brand,
        cv.battery_no,
        cv.battery_capacity,
        cv.charger_brand,
        cv.charger_no,
        cv.charger_type,
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
    LIMIT 1
");

if (!$stmt) {
    die('Service invoice query prepare failed: ' . h($conn->error));
}
$stmt->bind_param('ii', $invoiceId, $businessId);
$stmt->execute();
$invoice = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$invoice) {
    die('Service invoice not found.');
}

$jobcardId = (int)($invoice['jobcard_id'] ?? 0);

/* ---------------------------------------------------------
   FETCH COMPLAINTS
--------------------------------------------------------- */
$complaints = [];
if ($jobcardId > 0 && tableExists($conn, 'service_complaints')) {
    $stmt = $conn->prepare("SELECT complaint_text, priority, status FROM service_complaints WHERE jobcard_id = ? ORDER BY id ASC");
    if ($stmt) {
        $stmt->bind_param('i', $jobcardId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) $complaints[] = $row;
        $stmt->close();
    }
}

/* ---------------------------------------------------------
   FETCH PARTS - ALL GST INCLUSIVE
--------------------------------------------------------- */
$parts = [];
if ($jobcardId > 0 && tableExists($conn, 'service_job_part_items')) {
    $stmt = $conn->prepare("
        SELECT
            spi.*,
            p.product_name,
            p.product_code,
            p.item_code,
            p.hsn_code,
            p.unit,
            p.brand_name,
            COALESCE(p.gst_percent, spi.gst_percent, 0) AS product_gst_percent
        FROM service_job_part_items spi
        LEFT JOIN products p ON p.id = spi.product_id
        WHERE spi.jobcard_id = ?
        ORDER BY spi.id ASC
    ");
    if ($stmt) {
        $stmt->bind_param('i', $jobcardId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $qty = (float)($row['qty'] ?? 0);
            $rateIncl = (float)($row['unit_price'] ?? 0);
            $discount = (float)($row['discount_amount'] ?? 0);
            $gstPercent = (float)($row['gst_percent'] ?? $row['product_gst_percent'] ?? 0);

            // IMPORTANT: service page saves/uses all rates as GST inclusive.
            // So tax must be extracted from line_total, not added again.
            $calc = calcInclusiveLine($qty, $rateIncl, $discount, $gstPercent);

            $row['print_type'] = 'Product';
            $row['print_description'] = trim((string)($row['product_name'] ?: 'Product'));
            $code = trim((string)($row['product_code'] ?: $row['item_code'] ?: ''));
            if ($code !== '') $row['print_description'] .= ' (' . $code . ')';
            $row['print_qty'] = $qty;
            $row['print_rate'] = $rateIncl;
            $row['print_gst_percent'] = $gstPercent;
            $row['print_discount'] = $calc['discount'];
            $row['print_taxable'] = $calc['taxable'];
            $row['print_tax'] = $calc['tax'];
            $row['print_cgst'] = $calc['cgst'];
            $row['print_sgst'] = $calc['sgst'];
            $row['print_total'] = $calc['total'];
            $parts[] = $row;
        }
        $stmt->close();
    }
}

/* ---------------------------------------------------------
   FETCH LABOUR - ALL GST INCLUSIVE
--------------------------------------------------------- */
$labours = [];
if ($jobcardId > 0 && tableExists($conn, 'service_job_labor_items')) {
    $stmt = $conn->prepare("
        SELECT
            sli.*,
            slm.labor_name,
            slm.service_type
        FROM service_job_labor_items sli
        LEFT JOIN service_labor_master slm ON slm.id = sli.labor_id
        WHERE sli.jobcard_id = ?
        ORDER BY sli.id ASC
    ");
    if ($stmt) {
        $stmt->bind_param('i', $jobcardId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $qty = (float)($row['qty'] ?? 0);
            $rateIncl = (float)($row['unit_price'] ?? 0);
            $discount = (float)($row['discount_amount'] ?? 0);
            $gstPercent = (float)($row['gst_percent'] ?? 0);

            // IMPORTANT: labour amount is GST inclusive.
            $calc = calcInclusiveLine($qty, $rateIncl, $discount, $gstPercent);

            $desc = trim((string)($row['description'] ?? ''));
            if ($desc === '') $desc = trim((string)($row['labor_name'] ?? 'Labour'));
            if ($desc === '') $desc = 'Labour';

            $row['print_type'] = 'Labour';
            $row['print_description'] = $desc;
            $row['print_qty'] = $qty;
            $row['print_rate'] = $rateIncl;
            $row['print_gst_percent'] = $gstPercent;
            $row['print_discount'] = $calc['discount'];
            $row['print_taxable'] = $calc['taxable'];
            $row['print_tax'] = $calc['tax'];
            $row['print_cgst'] = $calc['cgst'];
            $row['print_sgst'] = $calc['sgst'];
            $row['print_total'] = $calc['total'];
            $labours[] = $row;
        }
        $stmt->close();
    }
}

$printItems = array_merge($parts, $labours);

/* ---------------------------------------------------------
   RECALCULATE SUMMARY FROM ITEMS - GST INCLUSIVE
--------------------------------------------------------- */
$taxableTotal = 0.0;
$totalGst = 0.0;
$cgstTotal = 0.0;
$sgstTotal = 0.0;
$itemsTotal = 0.0;

foreach ($printItems as $it) {
    $taxableTotal += (float)$it['print_taxable'];
    $totalGst += (float)$it['print_tax'];
    $cgstTotal += (float)$it['print_cgst'];
    $sgstTotal += (float)$it['print_sgst'];
    $itemsTotal += (float)$it['print_total'];
}

$invoiceDiscount = (float)($invoice['discount_amount'] ?? 0);
$grandTotal = round(max(0, $itemsTotal - $invoiceDiscount), 2);
$paidAmount = (float)($invoice['paid_amount'] ?? 0);
if ($paidAmount > $grandTotal) $paidAmount = $grandTotal;
$balanceAmount = round(max(0, $grandTotal - $paidAmount), 2);

// When invoice table totals are correct and items are empty, fallback to table values.
if (empty($printItems)) {
    $taxableTotal = (float)($invoice['subtotal'] ?? 0);
    $cgstTotal = (float)($invoice['cgst_amount'] ?? 0);
    $sgstTotal = (float)($invoice['sgst_amount'] ?? 0);
    $totalGst = $cgstTotal + $sgstTotal + (float)($invoice['igst_amount'] ?? 0);
    $grandTotal = (float)($invoice['grand_total'] ?? 0);
    $paidAmount = (float)($invoice['paid_amount'] ?? 0);
    $balanceAmount = (float)($invoice['balance_amount'] ?? max(0, $grandTotal - $paidAmount));
}

/* ---------------------------------------------------------
   FETCH PAYMENTS
--------------------------------------------------------- */
$payments = [];
if (tableExists($conn, 'payments')) {
    $stmt = $conn->prepare("
        SELECT p.*, pm.method_name
        FROM payments p
        LEFT JOIN payment_methods pm ON pm.id = p.payment_method_id
        WHERE p.business_id = ? AND p.payment_for = 'service' AND p.ref_id = ?
        ORDER BY p.payment_date ASC, p.id ASC
    ");
    if ($stmt) {
        $stmt->bind_param('ii', $businessId, $invoiceId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) $payments[] = $row;
        $stmt->close();
    }
}

/* ---------------------------------------------------------
   PDF CLASS
--------------------------------------------------------- */
class ServiceInvoicePDF extends FPDF
{
    public array $biz = [];
    public array $invoice = [];
    public string $logoPath = '';
    public bool $hasLogo = false;

    function Header()
    {
        $this->SetDrawColor(30, 41, 59);
        $this->SetLineWidth(0.2);
        $this->Rect(8, 8, 194, 281);

        $this->SetFont('Arial', '', 8);
        $this->SetXY(10, 10);
        $gst = trim((string)($this->biz['gstin'] ?? ''));
        $this->Cell(58, 5, safeText($gst !== '' ? 'GSTIN: ' . $gst : ''), 0, 0, 'L');

        $this->SetFont('Arial', 'B', 10);
        $this->SetXY(70, 10);
        $this->Cell(70, 5, 'SERVICE TAX INVOICE', 0, 0, 'C');

        $this->SetFont('Arial', '', 8);
        $this->SetXY(140, 10);
        $mobile = trim((string)($this->biz['mobile'] ?? ''));
        $this->Cell(60, 5, safeText($mobile !== '' ? 'Mobile: ' . $mobile : ''), 0, 0, 'R');

        if ($this->hasLogo && is_file($this->logoPath)) {
            $ext = strtolower(pathinfo($this->logoPath, PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
                $this->Image($this->logoPath, 181, 17, 14);
            }
        }

        $this->SetFont('Arial', 'B', 15);
        $this->SetXY(10, 18);
        $this->Cell(190, 7, safeText(strtoupper((string)($this->biz['business_name'] ?? 'BUSINESS'))), 0, 1, 'C');

        $branch = trim((string)($this->invoice['branch_name'] ?? ''));
        $branchCode = trim((string)($this->invoice['branch_code'] ?? ''));
        if ($branch !== '') {
            $this->SetFont('Arial', 'B', 8);
            $this->SetXY(10, 26);
            $this->Cell(190, 4, safeText('Branch: ' . $branch . ($branchCode !== '' ? ' (' . $branchCode . ')' : '')), 0, 1, 'C');
        }

        $this->SetFont('Arial', '', 8);
        $addr1 = trim((string)($this->invoice['branch_address_line1'] ?? ''));
        $addr2 = trim((string)($this->invoice['branch_address_line2'] ?? ''));
        if ($addr1 === '') $addr1 = trim((string)($this->biz['address_line1'] ?? ''));
        if ($addr2 === '') $addr2 = trim((string)($this->biz['address_line2'] ?? ''));
        $addrLine = trim($addr1 . ' ' . $addr2);
        $this->SetXY(10, 31);
        $this->Cell(190, 4, safeText($addrLine), 0, 1, 'C');

        $city = trim((string)($this->invoice['branch_city'] ?? $this->biz['city'] ?? ''));
        $state = trim((string)($this->invoice['branch_state'] ?? $this->biz['state'] ?? ''));
        $pin = trim((string)($this->invoice['branch_pincode'] ?? $this->biz['pincode'] ?? ''));
        $cityLine = trim($city . ($state !== '' ? ', ' . $state : '') . ($pin !== '' ? ' - ' . $pin : ''));
        $this->SetXY(10, 35);
        $this->Cell(190, 4, safeText($cityLine), 0, 1, 'C');

        $this->Line(8, 41, 202, 41);
    }

    function Footer()
    {
        $this->SetY(-12);
        $this->SetFont('Arial', 'I', 7);
        $this->Cell(0, 4, safeText('This is a computer generated service invoice. Page ' . $this->PageNo()), 0, 0, 'C');
    }

    function labelValue($x, $y, $label, $value, $labelW = 28, $valueW = 65, $h = 5)
    {
        $this->SetXY($x, $y);
        $this->SetFont('Arial', 'B', 7.5);
        $this->Cell($labelW, $h, safeText($label), 0, 0, 'L');
        $this->SetFont('Arial', '', 7.5);
        $this->Cell($valueW, $h, safeText((string)$value), 0, 0, 'L');
    }

    function checkPageBreak($neededHeight)
    {
        if ($this->GetY() + $neededHeight > 276) {
            $this->AddPage();
            $this->SetY(44);
        }
    }
}

if (ob_get_length()) ob_clean();

$pdf = new ServiceInvoicePDF('P', 'mm', 'A4');
$pdf->SetMargins(8, 8, 8);
$pdf->SetAutoPageBreak(false);
$pdf->biz = $biz;
$pdf->invoice = $invoice;
$pdf->logoPath = $logoPath;
$pdf->hasLogo = $hasLogo;
$pdf->AddPage();
$pdf->SetFont('Arial', '', 8);

/* ---------------------------------------------------------
   INVOICE + CUSTOMER DETAILS
--------------------------------------------------------- */
$y = 44;
$pdf->SetFillColor(248, 250, 252);
$pdf->SetFont('Arial', 'B', 8.5);
$pdf->SetXY(10, $y);
$pdf->Cell(95, 6, 'Invoice Details', 1, 0, 'C', true);
$pdf->Cell(95, 6, 'Customer Details', 1, 1, 'C', true);
$y += 7;

$customerAddressParts = array_filter([
    $invoice['customer_address_line1'] ?? '',
    $invoice['customer_address_line2'] ?? '',
    $invoice['customer_city'] ?? '',
    $invoice['customer_state'] ?? '',
    $invoice['customer_pincode'] ?? '',
]);
$customerAddress = trim(implode(' ', $customerAddressParts));
if ($customerAddress === '') $customerAddress = '-';

$pdf->labelValue(12, $y, 'Invoice No', valueOrDash($invoice['invoice_no'] ?? ''), 28, 64);
$pdf->labelValue(107, $y, 'Customer', valueOrDash($invoice['customer_name'] ?? ''), 24, 67);
$y += 5;
$pdf->labelValue(12, $y, 'Invoice Date', formatDateTimePdf($invoice['invoice_date'] ?? ''), 28, 64);
$pdf->labelValue(107, $y, 'Mobile', valueOrDash($invoice['customer_mobile'] ?? ''), 24, 67);
$y += 5;
$pdf->labelValue(12, $y, 'Job Card No', valueOrDash($invoice['jobcard_no'] ?? ''), 28, 64);
$pdf->labelValue(107, $y, 'GSTIN', valueOrDash($invoice['customer_gstin'] ?? ''), 24, 67);
$y += 5;
$pdf->labelValue(12, $y, 'Service Date', formatDatePdf($invoice['service_date'] ?? ''), 28, 64);
$pdf->labelValue(107, $y, 'Address', substr($customerAddress, 0, 62), 24, 67);
$y += 5;
$pdf->labelValue(12, $y, 'Status', ucfirst((string)($invoice['invoice_status'] ?? '-')), 28, 64);
$pdf->labelValue(107, $y, 'Payment', ucfirst((string)($invoice['payment_status'] ?? '-')), 24, 67);
$y += 8;

$pdf->Line(8, $y, 202, $y);
$y += 3;

/* ---------------------------------------------------------
   VEHICLE DETAILS
--------------------------------------------------------- */
$pdf->SetFont('Arial', 'B', 8.5);
$pdf->SetXY(10, $y);
$pdf->Cell(190, 6, 'Vehicle / Service Details', 1, 1, 'C', true);
$y += 7;

$vehicleName = trim((string)($invoice['brand_name'] ?? '') . ' ' . (string)($invoice['model_name'] ?? '') . ' ' . (string)($invoice['variant_name'] ?? ''));
if ($vehicleName === '') $vehicleName = 'Vehicle';

$vehicleRows = [
    ['Vehicle', $vehicleName, 'Reg No', valueOrDash($invoice['registration_no'] ?? '')],
    ['Chassis', valueOrDash($invoice['chassis_no'] ?? ''), 'Engine', valueOrDash($invoice['engine_no'] ?? '')],
    ['Motor', valueOrDash($invoice['motor_no'] ?? ''), 'Color', valueOrDash($invoice['color'] ?? '')],
    ['Battery', trim(valueOrDash($invoice['battery_brand'] ?? '') . ' / ' . valueOrDash($invoice['battery_no'] ?? '')), 'Charger', trim(valueOrDash($invoice['charger_brand'] ?? '') . ' / ' . valueOrDash($invoice['charger_no'] ?? ''))],
    ['Opening KM', valueOrDash($invoice['opening_km'] ?? ''), 'Fuel/Battery', valueOrDash($invoice['fuel_level'] ?? '') . ' / ' . valueOrDash($invoice['battery_percentage'] ?? '')],
];

$pdf->SetFont('Arial', '', 7.5);
foreach ($vehicleRows as $r) {
    $pdf->SetXY(10, $y);
    $pdf->SetFont('Arial', 'B', 7.5);
    $pdf->Cell(24, 5, safeText($r[0]), 1, 0, 'L');
    $pdf->SetFont('Arial', '', 7.5);
    $pdf->Cell(71, 5, safeText(substr((string)$r[1], 0, 42)), 1, 0, 'L');
    $pdf->SetFont('Arial', 'B', 7.5);
    $pdf->Cell(24, 5, safeText($r[2]), 1, 0, 'L');
    $pdf->SetFont('Arial', '', 7.5);
    $pdf->Cell(71, 5, safeText(substr((string)$r[3], 0, 42)), 1, 1, 'L');
    $y += 5;
}
$y += 3;

/* ---------------------------------------------------------
   COMPLAINTS / OBSERVATIONS
--------------------------------------------------------- */
$pdf->checkPageBreak(35);
$pdf->SetFont('Arial', 'B', 8.5);
$pdf->SetXY(10, $y);
$pdf->Cell(190, 6, 'Complaints / Observations', 1, 1, 'C', true);
$y += 7;

$pdf->SetFont('Arial', '', 7.5);
if (!empty($complaints)) {
    $i = 1;
    foreach ($complaints as $c) {
        $line = $i . '. ' . ($c['complaint_text'] ?? '') . ' [' . ucfirst((string)($c['priority'] ?? 'Medium')) . ']';
        $pdf->SetXY(12, $y);
        $pdf->MultiCell(186, 4, safeText($line), 0, 'L');
        $y = $pdf->GetY();
        $i++;
    }
} else {
    $pdf->SetXY(12, $y);
    $pdf->Cell(186, 4, 'No complaints recorded.', 0, 1, 'L');
    $y += 4;
}

$observationText = trim((string)($invoice['customer_voice'] ?? ''));
$technicianText = trim((string)($invoice['technician_observation'] ?? ''));
$recommendationText = trim((string)($invoice['recommendation'] ?? ''));

if ($observationText !== '') {
    $pdf->SetXY(12, $y + 1);
    $pdf->SetFont('Arial', 'B', 7.5);
    $pdf->Cell(30, 4, 'Customer Voice:', 0, 0, 'L');
    $pdf->SetFont('Arial', '', 7.5);
    $pdf->MultiCell(156, 4, safeText($observationText), 0, 'L');
    $y = $pdf->GetY();
}
if ($technicianText !== '') {
    $pdf->SetXY(12, $y + 1);
    $pdf->SetFont('Arial', 'B', 7.5);
    $pdf->Cell(38, 4, 'Technician Note:', 0, 0, 'L');
    $pdf->SetFont('Arial', '', 7.5);
    $pdf->MultiCell(148, 4, safeText($technicianText), 0, 'L');
    $y = $pdf->GetY();
}
if ($recommendationText !== '') {
    $pdf->SetXY(12, $y + 1);
    $pdf->SetFont('Arial', 'B', 7.5);
    $pdf->Cell(34, 4, 'Recommendation:', 0, 0, 'L');
    $pdf->SetFont('Arial', '', 7.5);
    $pdf->MultiCell(152, 4, safeText($recommendationText), 0, 'L');
    $y = $pdf->GetY();
}
$y += 3;

/* ---------------------------------------------------------
   ITEM TABLE
--------------------------------------------------------- */
$pdf->checkPageBreak(65);
$pdf->SetY($y);
$pdf->SetFont('Arial', 'B', 8.5);
$pdf->SetX(10);
$pdf->Cell(190, 6, 'Products Used & Labour Charges (All Rates GST Inclusive)', 1, 1, 'C', true);

$headerY = $pdf->GetY();
$pdf->SetFont('Arial', 'B', 6.8);
$pdf->SetX(10);
$pdf->Cell(8, 6, 'Sl', 1, 0, 'C');
$pdf->Cell(17, 6, 'Type', 1, 0, 'C');
$pdf->Cell(58, 6, 'Description', 1, 0, 'C');
$pdf->Cell(12, 6, 'Qty', 1, 0, 'C');
$pdf->Cell(22, 6, 'Rate Incl.', 1, 0, 'C');
$pdf->Cell(15, 6, 'GST %', 1, 0, 'C');
$pdf->Cell(20, 6, 'GST Amt', 1, 0, 'C');
$pdf->Cell(20, 6, 'Taxable', 1, 0, 'C');
$pdf->Cell(18, 6, 'Total', 1, 1, 'C');

$pdf->SetFont('Arial', '', 6.8);
$sl = 1;
if (!empty($printItems)) {
    foreach ($printItems as $it) {
        if ($pdf->GetY() > 245) {
            $pdf->AddPage();
            $pdf->SetY(44);
            $pdf->SetFont('Arial', 'B', 6.8);
            $pdf->SetX(10);
            $pdf->Cell(8, 6, 'Sl', 1, 0, 'C');
            $pdf->Cell(17, 6, 'Type', 1, 0, 'C');
            $pdf->Cell(58, 6, 'Description', 1, 0, 'C');
            $pdf->Cell(12, 6, 'Qty', 1, 0, 'C');
            $pdf->Cell(22, 6, 'Rate Incl.', 1, 0, 'C');
            $pdf->Cell(15, 6, 'GST %', 1, 0, 'C');
            $pdf->Cell(20, 6, 'GST Amt', 1, 0, 'C');
            $pdf->Cell(20, 6, 'Taxable', 1, 0, 'C');
            $pdf->Cell(18, 6, 'Total', 1, 1, 'C');
            $pdf->SetFont('Arial', '', 6.8);
        }

        $desc = substr((string)$it['print_description'], 0, 42);
        $pdf->SetX(10);
        $pdf->Cell(8, 5, (string)$sl++, 1, 0, 'C');
        $pdf->Cell(17, 5, safeText((string)$it['print_type']), 1, 0, 'C');
        $pdf->Cell(58, 5, safeText($desc), 1, 0, 'L');
        $pdf->Cell(12, 5, qtyPdf((float)$it['print_qty']), 1, 0, 'R');
        $pdf->Cell(22, 5, moneyPdf((float)$it['print_rate']), 1, 0, 'R');
        $pdf->Cell(15, 5, number_format((float)$it['print_gst_percent'], 2) . '%', 1, 0, 'C');
        $pdf->Cell(20, 5, moneyPdf((float)$it['print_tax']), 1, 0, 'R');
        $pdf->Cell(20, 5, moneyPdf((float)$it['print_taxable']), 1, 0, 'R');
        $pdf->Cell(18, 5, moneyPdf((float)$it['print_total']), 1, 1, 'R');
    }
} else {
    $pdf->SetX(10);
    $pdf->Cell(190, 8, 'No product or labour items found.', 1, 1, 'C');
}

$y = $pdf->GetY() + 4;

/* ---------------------------------------------------------
   PAYMENT + SUMMARY
--------------------------------------------------------- */
$pdf->checkPageBreak(50);
$y = $pdf->GetY() + 3;
$boxY = $y;

$pdf->SetFont('Arial', 'B', 8.5);
$pdf->SetXY(10, $boxY);
$pdf->Cell(92, 6, 'Payment Details', 1, 0, 'C', true);
$pdf->Cell(98, 6, 'GST Inclusive Summary', 1, 1, 'C', true);

$pdf->SetFont('Arial', '', 7);
$payY = $boxY + 7;
if (!empty($payments)) {
    foreach ($payments as $p) {
        if ($payY > $boxY + 35) break;
        $line = formatDatePdf($p['payment_date'] ?? '') . ' | ' . ($p['method_name'] ?? 'Payment') . ' | ' . moneyPdf($p['amount'] ?? 0);
        if (!empty($p['reference_no'])) $line .= ' | Ref: ' . $p['reference_no'];
        $pdf->SetXY(12, $payY);
        $pdf->Cell(88, 4, safeText(substr($line, 0, 62)), 0, 1, 'L');
        $payY += 4;
    }
} else {
    $pdf->SetXY(12, $payY);
    $pdf->Cell(88, 5, 'No payment history found.', 0, 1, 'L');
}

$summaryX = 112;
$summaryY = $boxY + 7;
$summaryRows = [
    ['Taxable Amount', $taxableTotal],
    ['CGST', $cgstTotal],
    ['SGST', $sgstTotal],
    ['Total GST', $totalGst],
    ['Discount', $invoiceDiscount],
    ['Grand Total', $grandTotal],
    ['Paid Amount', $paidAmount],
    ['Balance Amount', $balanceAmount],
];

foreach ($summaryRows as $sr) {
    $isBold = in_array($sr[0], ['Grand Total', 'Balance Amount'], true);
    $pdf->SetFont('Arial', $isBold ? 'B' : '', 7.5);
    $pdf->SetXY($summaryX, $summaryY);
    $pdf->Cell(48, 5, safeText($sr[0]), 1, 0, 'L');
    $pdf->Cell(40, 5, moneyPdf($sr[1]), 1, 1, 'R');
    $summaryY += 5;
}

$y = max($payY, $summaryY) + 5;

/* ---------------------------------------------------------
   TERMS + SIGNATURE
--------------------------------------------------------- */
$pdf->checkPageBreak(38);
$y = $pdf->GetY() + 5;
if ($y < max($payY, $summaryY) + 3) $y = max($payY, $summaryY) + 3;
if ($y > 245) {
    $pdf->AddPage();
    $y = 44;
}

$pdf->SetXY(10, $y);
$pdf->SetFont('Arial', 'B', 8.5);
$pdf->Cell(95, 6, 'Terms & Notes', 1, 0, 'C', true);
$pdf->Cell(95, 6, 'Authorised Signatory', 1, 1, 'C', true);
$y += 7;

$pdf->SetFont('Arial', '', 7.2);
$pdf->SetXY(12, $y);
$pdf->MultiCell(90, 4, safeText("1. All service product and labour rates are GST inclusive.\n2. Goods once used in service will not be taken back.\n3. Warranty is subject to manufacturer / service policy."), 0, 'L');

$pdf->SetFont('Arial', 'B', 8);
$pdf->SetXY(107, $y + 4);
$pdf->Cell(90, 5, safeText('For ' . strtoupper((string)($biz['business_name'] ?? 'BUSINESS'))), 0, 1, 'C');
$pdf->SetFont('Arial', '', 8);
$pdf->SetXY(107, $y + 24);
$pdf->Cell(90, 5, 'Authorised Signature', 0, 1, 'C');

/* ---------------------------------------------------------
   OUTPUT
--------------------------------------------------------- */
if (ob_get_length()) ob_clean();
$fileInvoiceNo = preg_replace('/[^A-Za-z0-9_-]/', '_', (string)($invoice['invoice_no'] ?? $invoiceId));
$pdf->Output('I', 'Service_Invoice_' . $fileInvoiceNo . '.pdf');
exit;
