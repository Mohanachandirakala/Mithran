<?php
error_reporting(0);
ini_set('display_errors', 0);

session_start();
date_default_timezone_set('Asia/Kolkata');

require_once 'includes/config.php';
require_once 'lib/fpdf.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not available.');
}
$conn->set_charset('utf8mb4');

function safeText($text) {
    return iconv('UTF-8', 'ISO-8859-1//TRANSLIT', (string)$text);
}

function formatInvoiceDate($dateValue) {
    if (empty($dateValue) || $dateValue == '0000-00-00' || $dateValue == '0000-00-00 00:00:00') {
        return date('d-m-Y');
    }
    $ts = strtotime($dateValue);
    return $ts ? date('d-m-Y', $ts) : date('d-m-Y');
}

function money($amount) {
    return number_format((float)$amount, 2);
}

function columnExists(mysqli $conn, string $table, string $column): bool {
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
    $ok = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $ok;
}

/* AUTH CHECK */
$businessUserId = isset($_SESSION['business_user_id']) ? (int)$_SESSION['business_user_id'] : 0;
if ($businessUserId <= 0 && isset($_SESSION['user_id'])) {
    $businessUserId = (int)$_SESSION['user_id'];
}

$businessId = isset($_SESSION['business_id']) ? (int)$_SESSION['business_id'] : 0;

if ($businessUserId <= 0 || $businessId <= 0) {
    header('Location: login.php');
    exit;
}

/* INPUT */
$invoiceId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($invoiceId <= 0) {
    die('Invalid invoice ID.');
}

/* FETCH INVOICE */
$stmt = $conn->prepare("
    SELECT 
        si.*, 
        br.branch_name, br.branch_code, br.address_line1 AS br_addr1, br.city AS br_city, 
        br.state AS br_state, br.pincode AS br_pin, br.mobile AS br_mob, 
        br.email AS br_email, br.gstin AS br_gst,

        c.full_name AS cust_name, c.mobile AS cust_mob, c.alternate_mobile AS cust_alt_mob, 
        c.email AS cust_email, c.address_line1 AS cust_addr1, c.address_line2 AS cust_addr2, 
        c.city AS cust_city, c.district AS cust_dist, c.state AS cust_state, 
        c.pincode AS cust_pin, c.gstin AS cust_gst
    FROM sales_invoices si 
    LEFT JOIN branches br ON br.id = si.branch_id 
    LEFT JOIN customers c ON c.id = si.customer_id 
    WHERE si.id = ? AND si.business_id = ? 
    LIMIT 1
");
$stmt->bind_param("ii", $invoiceId, $businessId);
$stmt->execute();
$invoice = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$invoice) {
    die('Invoice not found.');
}

$paidAmount = (float)($invoice['paid_amount'] ?? 0);
$pendingAmount = (float)($invoice['balance_amount'] ?? 0);
if ($pendingAmount <= 0) {
    $pendingAmount = max(0, (float)($invoice['grand_total'] ?? 0) - $paidAmount);
}

/* FETCH ITEMS */
$hasItemGstType = columnExists($conn, 'sales_invoice_items', 'gst_type');

$items = [];
$vehicleItems = [];
$productItems = [];

$itemStmt = $conn->prepare("
    SELECT 
        sii.*,
        " . ($hasItemGstType ? "sii.gst_type AS item_gst_type," : "'exclusive' AS item_gst_type,") . "
        p.product_name, p.product_code, p.hsn_code, p.unit, 
        p.gst_percent AS prod_gst, p.selling_price AS prod_sell_price,

        vs.id AS stock_id, vs.chassis_no, vs.engine_no, vs.motor_no, vs.battery_no, 
        vs.charger_no, vs.color, vs.key_no, vs.manufacture_year, 
        vs.battery_brand, vs.battery_capacity, vs.battery_warranty_upto, 
        vs.charger_brand, vs.charger_type, vs.charger_warranty_upto, 
        vs.ex_showroom_price AS stock_ex, vs.rto_charge AS stock_rto, 
        vs.registration_price AS stock_reg, vs.road_tax AS stock_rt, 
        vs.insurance_price AS stock_ins, vs.purchase_cost, vs.sale_price AS stock_sale,

        vm.model_name, vm.variant_name, vm.vehicle_type, vm.fuel_type, vm.engine_cc, 
        vm.mileage, vm.gst_percent AS model_gst, vm.ex_showroom_price AS model_ex,

        vb.brand_name
    FROM sales_invoice_items sii 
    LEFT JOIN products p ON p.id = sii.product_id 
    LEFT JOIN vehicle_stock vs ON vs.id = sii.vehicle_stock_id 
    LEFT JOIN vehicle_models vm ON vm.id = vs.model_id 
    LEFT JOIN vehicle_brands vb ON vb.id = vm.brand_id 
    WHERE sii.invoice_id = ? 
    ORDER BY sii.id ASC
");
$itemStmt->bind_param("i", $invoiceId);
$itemStmt->execute();
$res = $itemStmt->get_result();

while ($row = $res->fetch_assoc()) {
    $items[] = $row;
    if (($row['item_type'] ?? '') === 'vehicle') $vehicleItems[] = $row;
    if (($row['item_type'] ?? '') === 'product') $productItems[] = $row;
}
$itemStmt->close();

$hasVehicle = !empty($vehicleItems);
$hasProduct = !empty($productItems);
$v = $hasVehicle ? $vehicleItems[0] : null;

/* GST / VEHICLE AMOUNTS */
$cgstAmt = (float)($invoice['cgst_amount'] ?? 0);
$sgstAmt = (float)($invoice['sgst_amount'] ?? 0);
$igstAmt = (float)($invoice['igst_amount'] ?? 0);
$gstPct  = (float)($invoice['gst_percent'] ?? 0);

if ($gstPct == 0 && $cgstAmt > 0 && $sgstAmt > 0 && (float)$invoice['subtotal'] > 0) {
    $gstPct = (($cgstAmt + $sgstAmt) / (float)$invoice['subtotal']) * 100;
}

if ($gstPct == 0 && $v) {
    $gstPct = (float)($v['model_gst'] ?? $v['gst_percent'] ?? $v['prod_gst'] ?? 0);
}

$cgstPct = $gstPct / 2;
$sgstPct = $gstPct / 2;

$exShowroom = 0;
$rtoCharge = 0;
$regPrice = 0;
$roadTax = 0;
$insPrice = 0;

if ($hasVehicle && $v) {
    $exShowroom = (float)($v['stock_ex'] ?? $v['model_ex'] ?? $v['unit_price'] ?? 0);
    $rtoCharge  = (float)($v['stock_rto'] ?? $v['rto_charge'] ?? 0);
    $regPrice   = (float)($v['stock_reg'] ?? $v['registration_price'] ?? 0);
    $roadTax    = (float)($v['stock_rt'] ?? $v['road_tax_amount'] ?? 0);
    $insPrice   = (float)($v['stock_ins'] ?? $v['insurance_price'] ?? 0);
}

$onRoadPrice = (float)($invoice['grand_total'] ?? 0);

/* VEHICLE DETAILS */
$chassisNo = (!empty($v['chassis_no']) && $v['chassis_no'] != '0') ? $v['chassis_no'] : '-';
$motorNo = (!empty($v['motor_no']) && $v['motor_no'] != '0') ? $v['motor_no'] : '-';
$batteryNo = (!empty($v['battery_no']) && $v['battery_no'] != '0') ? $v['battery_no'] : '-';
$chargerNo = (!empty($v['charger_no']) && $v['charger_no'] != '0') ? $v['charger_no'] : '-';
$engineNo = (!empty($v['engine_no']) && $v['engine_no'] != '0') ? $v['engine_no'] : '-';

$batteryBrand = $v['battery_brand'] ?? '-';
$batteryCap = $v['battery_capacity'] ?? '-';
$chargerBrand = $v['charger_brand'] ?? '-';
$chargerType = $v['charger_type'] ?? '-';
$color = $v['color'] ?? '-';
$keyNo = $v['key_no'] ?? '-';
$mfgYear = $v['manufacture_year'] ?? '-';

$batteryWarranty = (!empty($v['battery_warranty_upto']) && $v['battery_warranty_upto'] != '0000-00-00')
    ? date('d-m-Y', strtotime($v['battery_warranty_upto']))
    : '-';

$chargerWarranty = (!empty($v['charger_warranty_upto']) && $v['charger_warranty_upto'] != '0000-00-00')
    ? date('d-m-Y', strtotime($v['charger_warranty_upto']))
    : '-';

$bikeName = trim(($v['brand_name'] ?? '') . ' ' . ($v['model_name'] ?? '') . ' ' . ($v['variant_name'] ?? ''));
if ($bikeName === '') $bikeName = 'Vehicle';



/* BUSINESS */
$bStmt = $conn->prepare("
    SELECT business_name, gstin, address_line1, address_line2, city, district, state, 
           pincode, mobile, email, logo_path 
    FROM businesses 
    WHERE id = ?
");
$bStmt->bind_param("i", $businessId);
$bStmt->execute();
$biz = $bStmt->get_result()->fetch_assoc();
$bStmt->close();

if (!$biz) {
    $biz = [
        'business_name' => 'Business',
        'gstin' => '',
        'address_line1' => '',
        'address_line2' => '',
        'city' => '',
        'district' => '',
        'state' => '',
        'pincode' => '',
        'mobile' => '',
        'email' => '',
        'logo_path' => ''
    ];
}

$logoPath = '';
$hasLogo = false;

if (!empty($biz['logo_path'])) {
    $logoPath = ltrim($biz['logo_path'], '/');
    if (file_exists($logoPath)) {
        $hasLogo = true;
    } else {
        $alt = 'uploads/business/' . basename($biz['logo_path']);
        if (file_exists($alt)) {
            $logoPath = $alt;
            $hasLogo = true;
        }
    }
}

$rawInvoiceDate = !empty($invoice['invoice_date']) ? $invoice['invoice_date'] : ($invoice['created_at'] ?? date('Y-m-d H:i:s'));
$invDate = formatInvoiceDate($rawInvoiceDate);

/* PDF CLASS */
class InvPDF extends FPDF {
    public $biz;
    public $inv;
    public $logoPath;
    public $hasLogo;

    function Header() {
        $this->SetAutoPageBreak(false);
        $this->Rect(10, 8, 190, 270);

        $this->SetFont('Arial', '', 8);
        $this->SetXY(12, 9);
        $gst = !empty($this->biz['gstin']) ? 'GSTIN: ' . $this->biz['gstin'] : '';
        $this->Cell(58, 5, safeText($gst), 0, 0, 'L');

        $this->SetFont('Arial', 'B', 10);
        $this->SetXY(70, 9);

        $title = (
            (float)($this->inv['cgst_amount'] ?? 0) > 0 ||
            (float)($this->inv['sgst_amount'] ?? 0) > 0 ||
            (float)($this->inv['igst_amount'] ?? 0) > 0
        ) ? 'TAX INVOICE' : 'SALES INVOICE';

        $this->Cell(70, 5, $title, 0, 0, 'C');

        $this->SetFont('Arial', '', 8);
        $this->SetXY(140, 9);
        $mob = !empty($this->biz['mobile']) ? 'Mobile: ' . $this->biz['mobile'] : '';
        $this->Cell(58, 5, safeText($mob), 0, 0, 'R');

        if ($this->hasLogo && file_exists($this->logoPath)) {
            $this->Image($this->logoPath, 174, 16, 15);
        }

        $this->SetFont('Arial', 'B', 15);
        $this->SetXY(12, 17);
        $this->Cell(186, 7, safeText(strtoupper($this->biz['business_name'] ?? 'BUSINESS')), 0, 1, 'C');

        $this->SetFont('Arial', '', 8);
        $this->SetXY(12, 25);
        $addr = trim(($this->biz['address_line1'] ?? '') . ' ' . ($this->biz['address_line2'] ?? ''));
        $this->Cell(186, 4, safeText($addr), 0, 1, 'C');

        $this->SetXY(12, 29);
        $cityLine = trim(($this->biz['city'] ?? '') . ', ' . ($this->biz['state'] ?? '') . ' - ' . ($this->biz['pincode'] ?? ''));
        $this->Cell(186, 4, safeText($cityLine), 0, 1, 'C');

        $this->Line(10, 35, 200, 35);
    }
}

/* START PDF */
if (ob_get_length()) ob_clean();

$pdf = new InvPDF('P', 'mm', 'A4');
$pdf->SetMargins(10, 8, 10);
$pdf->SetAutoPageBreak(false);

$pdf->biz = $biz;
$pdf->inv = $invoice;
$pdf->logoPath = $logoPath;
$pdf->hasLogo = $hasLogo;

$pdf->AddPage();
$pdf->SetFont('Arial', '', 8);

/* TOP DETAILS */
$pdf->SetXY(12, 38);
$pdf->SetFont('Arial', 'B', 8);
$pdf->Cell(94, 5, safeText('Invoice No: ' . ($invoice['invoice_no'] ?? '')), 0, 0, 'L');
$pdf->Cell(92, 5, safeText('Date: ' . $invDate), 0, 1, 'R');

$custAddrParts = [];
if (!empty($invoice['cust_addr1'])) $custAddrParts[] = $invoice['cust_addr1'];
if (!empty($invoice['cust_addr2'])) $custAddrParts[] = $invoice['cust_addr2'];
if (!empty($invoice['cust_city'])) $custAddrParts[] = $invoice['cust_city'];
if (!empty($invoice['cust_state'])) $custAddrParts[] = $invoice['cust_state'];
if (!empty($invoice['cust_pin'])) $custAddrParts[] = $invoice['cust_pin'];

$pdf->SetFont('Arial', '', 8);
$pdf->SetXY(12, 44);
$pdf->Cell(186, 5, safeText('Customer Name: ' . ($invoice['cust_name'] ?? '')), 0, 1, 'L');

$pdf->SetXY(12, 49);
$pdf->Cell(186, 5, safeText('Address: ' . implode(', ', $custAddrParts)), 0, 1, 'L');

$pdf->SetXY(12, 54);
$pdf->Cell(93, 5, safeText('Mobile: ' . ($invoice['cust_mob'] ?? '')), 0, 0, 'L');
$pdf->Cell(93, 5, safeText('Customer GST: ' . ($invoice['cust_gst'] ?? '')), 0, 1, 'R');

$pdf->Line(10, 61, 200, 61);

$pdf->SetXY(150, 38);
$pdf->SetFont('Arial', 'I', 7);


$currentY = 64;

/* VEHICLE SECTION */
if ($hasVehicle && $v) {
    $y = $currentY;

    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetXY(10, $y);
    $pdf->Cell(95, 6, 'Manufacturing Details', 1, 0, 'C');
    $pdf->Cell(95, 6, 'On-Road Price Details', 1, 1, 'C');

    $pdf->SetFont('Arial', '', 7);

    $leftRows = [
        ['Model Price', money($exShowroom)],
        ['Ex-Showroom Price', money($exShowroom)]
    ];

    if ($cgstAmt > 0) $leftRows[] = ['CGST (' . number_format($cgstPct, 2) . '%)', money($cgstAmt)];
    if ($sgstAmt > 0) $leftRows[] = ['SGST (' . number_format($sgstPct, 2) . '%)', money($sgstAmt)];
    if ($igstAmt > 0) $leftRows[] = ['IGST (' . number_format($gstPct, 2) . '%)', money($igstAmt)];

    $rightRows = [
        ['Registration Charges', money($regPrice)],
        ['Insurance', money($insPrice)],
        ['Road Tax', money($roadTax)],
        ['Other Charges', money($rtoCharge)],
        ['On Road Price', money($onRoadPrice)]
    ];

    $maxRows = max(count($leftRows), count($rightRows));
    $rowY = $y + 6;

    for ($i = 0; $i < $maxRows; $i++) {
        $lLabel = $leftRows[$i][0] ?? '';
        $lVal = $leftRows[$i][1] ?? '';
        $rLabel = $rightRows[$i][0] ?? '';
        $rVal = $rightRows[$i][1] ?? '';

        $pdf->SetXY(10, $rowY);
        $pdf->Cell(60, 5, safeText($lLabel), 1, 0, 'L');
        $pdf->Cell(35, 5, safeText($lVal), 1, 0, 'R');
        $pdf->Cell(60, 5, safeText($rLabel), 1, 0, 'L');

        if ($rLabel === 'On Road Price') {
            $pdf->SetFont('Arial', 'B', 7);
            $pdf->SetFillColor(240, 248, 255);
            $pdf->Cell(35, 5, safeText($rVal), 1, 1, 'R', true);
            $pdf->SetFont('Arial', '', 7);
        } else {
            $pdf->Cell(35, 5, safeText($rVal), 1, 1, 'R');
        }

        $rowY += 5;
    }

    $currentY = $rowY + 3;

    $pdf->SetFont('Arial', 'B', 7);
    $pdf->SetXY(10, $currentY);
    $pdf->Cell(38, 6, 'Model No.', 1, 0, 'C');
    $pdf->Cell(72, 6, 'Bike Name', 1, 0, 'C');
    $pdf->Cell(40, 6, 'Ex-Showroom', 1, 0, 'C');
    $pdf->Cell(40, 6, 'On Road Price', 1, 1, 'C');

    $pdf->SetFont('Arial', '', 7);
    $pdf->SetXY(10, $currentY + 6);
    $pdf->Cell(38, 7, safeText($v['model_name'] ?? ''), 1, 0, 'L');
    $pdf->Cell(72, 7, safeText(substr($bikeName, 0, 42)), 1, 0, 'L');
    $pdf->Cell(40, 7, money($exShowroom), 1, 0, 'R');

    $pdf->SetFont('Arial', 'B', 7);
    $pdf->SetFillColor(240, 248, 255);
    $pdf->Cell(40, 7, money($onRoadPrice), 1, 1, 'R', true);
    $pdf->SetFont('Arial', '', 7);

    $currentY += 16;

    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetXY(10, $currentY);
    $pdf->Cell(190, 6, 'Bike Details', 1, 1, 'C');

    $bikeFields = [
        ['Chassis No', $chassisNo, 'Engine No', $engineNo],
        ['Motor No', $motorNo, 'Battery No', $batteryNo],
        ['Charger No', $chargerNo, 'Key No', $keyNo],
        ['Color', $color, 'Mfg Year', $mfgYear],
        ['Battery Brand', $batteryBrand, 'Battery Cap', $batteryCap],
        ['Charger Brand', $chargerBrand, 'Charger Type', $chargerType],
        ['Battery Warranty', $batteryWarranty, 'Charger Warranty', $chargerWarranty]
    ];

    $pdf->SetFont('Arial', '', 7);
    $bdY = $currentY + 6;

    foreach ($bikeFields as $bf) {
        $pdf->SetXY(10, $bdY);
        $pdf->Cell(95, 5, safeText($bf[0] . ': ' . $bf[1]), 1, 0, 'L');
        $pdf->Cell(95, 5, safeText($bf[2] . ': ' . $bf[3]), 1, 1, 'L');
        $bdY += 5;
    }

    $currentY = $bdY + 3;

    $totalX = 112;
    $totalY = $currentY;
    $labelW = 48;
    $valueW = 40;

    $tlabels = ['Sub Total', 'Other Charges'];

    if ($cgstAmt > 0) $tlabels[] = 'CGST (' . number_format($cgstPct, 2) . '%)';
    if ($sgstAmt > 0) $tlabels[] = 'SGST (' . number_format($sgstPct, 2) . '%)';
    if ($igstAmt > 0) $tlabels[] = 'IGST (' . number_format($gstPct, 2) . '%)';

    $tlabels[] = 'Discount';
    $tlabels[] = 'GRAND TOTAL';
    $tlabels[] = 'Paid Amount';
    $tlabels[] = 'Pending Amount';

    $tvals = [(float)($invoice['subtotal'] ?? 0), 0];

    if ($cgstAmt > 0) $tvals[] = $cgstAmt;
    if ($sgstAmt > 0) $tvals[] = $sgstAmt;
    if ($igstAmt > 0) $tvals[] = $igstAmt;

    $tvals[] = (float)($invoice['discount_amount'] ?? 0);
    $tvals[] = $onRoadPrice;
    $tvals[] = $paidAmount;
    $tvals[] = $pendingAmount;

    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetXY($totalX, $totalY);
    $pdf->Cell($labelW + $valueW, 6, 'Invoice Total', 1, 1, 'C');

    $trY = $totalY + 6;

    foreach ($tlabels as $idx => $label) {
        $fill = false;
        if ($label === 'GRAND TOTAL' || $label === 'Pending Amount') {
            $pdf->SetFont('Arial', 'B', 8);
            if ($label === 'GRAND TOTAL') {
                $pdf->SetFillColor(240, 248, 255);
                $fill = true;
            }
        } else {
            $pdf->SetFont('Arial', '', 7);
        }

        $pdf->SetXY($totalX, $trY);
        $pdf->Cell($labelW, 5, safeText($label), 1, 0, 'L');
        $pdf->Cell($valueW, 5, money($tvals[$idx]), 1, 1, 'R', $fill);
        $trY += 5;
    }

    $leftInfoY = $totalY;

    $pdf->SetXY(10, $leftInfoY);
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell(95, 5, 'Warranty:', 0, 1, 'L');

    $pdf->SetFont('Arial', '', 7);
    $pdf->SetXY(10, $leftInfoY + 5);
    $pdf->MultiCell(95, 4, safeText("BATTERY 3 YEAR (OR) 30000KM\nMOTOR-30000 KM | ECU-1 YEAR\nCLUSTER-1 YEAR | THROTTLE 6000KM"));

    $termsY = $pdf->GetY() + 2;

    $pdf->SetXY(10, $termsY);
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell(95, 5, 'Terms & Conditions:', 0, 1, 'L');

    $pdf->SetFont('Arial', '', 7);
    $pdf->SetXY(10, $termsY + 5);
    $pdf->MultiCell(95, 4, safeText("1. Dealer not responsible for transit damage.\n2. Subject to " . ($biz['city'] ?? 'Local') . " jurisdiction.\n3. No return / refund."));

    $currentY = max($trY + 3, $pdf->GetY() + 3);
}

/* PRODUCT SECTION */
if ($hasProduct && $productItems) {
    $py = $hasVehicle ? $currentY + 2 : 64;
    if ($py > 205) $py = 205;

    $pdf->SetFont('Arial', 'B', 7);
    $pdf->SetXY(10, $py);
    $pdf->Cell(8, 5, 'Sl', 1, 0, 'C');
    $pdf->Cell(58, 5, 'Product Name', 1, 0, 'C');
    $pdf->Cell(12, 5, 'Qty', 1, 0, 'C');
    $pdf->Cell(22, 5, 'Unit Price', 1, 0, 'C');
    $pdf->Cell(16, 5, 'Disc', 1, 0, 'C');
    $pdf->Cell(18, 5, 'GST Type', 1, 0, 'C');
    $pdf->Cell(14, 5, 'GST%', 1, 0, 'C');
    $pdf->Cell(20, 5, 'GST Amt', 1, 0, 'C');
    $pdf->Cell(22, 5, 'Total', 1, 1, 'C');

    $pdf->SetFont('Arial', '', 6.2);

    $rowY = $py + 5;
    $sl = 1;

    foreach ($productItems as $it) {
        if ($rowY > 238) break;

        $qty = (float)($it['qty'] ?? 1);
        $unitPrice = (float)($it['unit_price'] ?? 0);
        $discount = (float)($it['discount_amount'] ?? 0);

        $gst = 0;
        if ((float)($it['cgst_percent'] ?? 0) > 0 || (float)($it['sgst_percent'] ?? 0) > 0) {
            $gst = (float)($it['cgst_percent'] ?? 0) + (float)($it['sgst_percent'] ?? 0);
        } elseif ((float)($it['igst_percent'] ?? 0) > 0) {
            $gst = (float)$it['igst_percent'];
        } elseif (isset($it['prod_gst'])) {
            $gst = (float)$it['prod_gst'];
        }

        $productGstType = strtolower((string)($it['item_gst_type'] ?? 'exclusive'));

        $lineAmount = round($qty * $unitPrice, 2);
        $discountAmount = min($discount, $lineAmount);
        $afterDiscount = max(0, $lineAmount - $discountAmount);

        if (isset($it['taxable_value']) && (float)$it['taxable_value'] > 0) {
            $taxable = (float)$it['taxable_value'];
        } elseif ($productGstType === 'inclusive' && $gst > 0) {
            $taxable = round($afterDiscount / (1 + ($gst / 100)), 2);
        } else {
            $taxable = $afterDiscount;
        }

        $tax = (float)($it['cgst_amount'] ?? 0)
             + (float)($it['sgst_amount'] ?? 0)
             + (float)($it['igst_amount'] ?? 0)
             + (float)($it['cess_amount'] ?? 0);

        if ($tax <= 0 && $gst > 0) {
            if ($productGstType === 'inclusive') {
                $tax = round($afterDiscount - $taxable, 2);
            } else {
                $tax = round($taxable * $gst / 100, 2);
            }
        }

        if (isset($it['line_total']) && (float)$it['line_total'] > 0) {
            $total = (float)$it['line_total'];
        } else {
            $total = ($productGstType === 'inclusive')
                ? $afterDiscount
                : round($taxable + $tax, 2);
        }

        $productName = $it['product_name'] ?? $it['description'] ?? 'Product';
        $productName = substr($productName, 0, 36);

        $pdf->SetXY(10, $rowY);
        $pdf->Cell(8, 5, $sl++, 1, 0, 'C');
        $pdf->Cell(58, 5, safeText($productName), 1, 0, 'L');
        $pdf->Cell(12, 5, money($qty), 1, 0, 'R');
        $pdf->Cell(22, 5, money($unitPrice), 1, 0, 'R');
        $pdf->Cell(16, 5, money($discountAmount), 1, 0, 'R');
        $pdf->Cell(18, 5, ucfirst($productGstType), 1, 0, 'C');
        $pdf->Cell(14, 5, number_format($gst, 2), 1, 0, 'C');
        $pdf->Cell(20, 5, money($tax), 1, 0, 'R');
        $pdf->Cell(22, 5, money($total), 1, 1, 'R');

        $rowY += 5;
    }

    if (!$hasVehicle) {
        $pty = $rowY + 3;

        $pdf->SetFont('Arial', 'B', 8);
        $pdf->SetXY(130, $pty);
        $pdf->Cell(35, 5, 'Sub Total:', 0, 0, 'R');
        $pdf->Cell(30, 5, money($invoice['subtotal'] ?? 0), 0, 1, 'R');
        $pty += 5;

        if ($cgstAmt > 0) {
            $pdf->SetFont('Arial', '', 8);
            $pdf->SetXY(130, $pty);
            $pdf->Cell(35, 5, 'CGST:', 0, 0, 'R');
            $pdf->Cell(30, 5, money($cgstAmt), 0, 1, 'R');
            $pty += 5;
        }

        if ($sgstAmt > 0) {
            $pdf->SetXY(130, $pty);
            $pdf->Cell(35, 5, 'SGST:', 0, 0, 'R');
            $pdf->Cell(30, 5, money($sgstAmt), 0, 1, 'R');
            $pty += 5;
        }

        if ($igstAmt > 0) {
            $pdf->SetXY(130, $pty);
            $pdf->Cell(35, 5, 'IGST:', 0, 0, 'R');
            $pdf->Cell(30, 5, money($igstAmt), 0, 1, 'R');
            $pty += 5;
        }

        if ((float)($invoice['discount_amount'] ?? 0) > 0) {
            $pdf->SetXY(130, $pty);
            $pdf->Cell(35, 5, 'Discount:', 0, 0, 'R');
            $pdf->Cell(30, 5, money($invoice['discount_amount'] ?? 0), 0, 1, 'R');
            $pty += 5;
        }

        $pdf->SetFont('Arial', 'B', 9);
        $pdf->SetXY(130, $pty);
        $pdf->Cell(35, 6, 'GRAND TOTAL:', 0, 0, 'R');
        $pdf->SetFillColor(240, 248, 255);
        $pdf->Cell(30, 6, money($invoice['grand_total'] ?? 0), 0, 1, 'R', true);
        $pty += 6;

        $pdf->SetFont('Arial', '', 8);
        $pdf->SetXY(130, $pty);
        $pdf->Cell(35, 5, 'Paid Amount:', 0, 0, 'R');
        $pdf->Cell(30, 5, money($paidAmount), 0, 1, 'R');
        $pty += 5;

        $pdf->SetFont('Arial', 'B', 8);
        $pdf->SetXY(130, $pty);
        $pdf->Cell(35, 5, 'Pending Amount:', 0, 0, 'R');
        $pdf->Cell(30, 5, money($pendingAmount), 0, 1, 'R');
    }
}

/* BANK + SIGNATURE */
$bottomY = 247;

$pdf->SetXY(10, $bottomY);
$pdf->SetFont('Arial', 'B', 8);
$pdf->Cell(30, 5, 'Bank Details:', 0, 1, 'L');
$pdf->Line(10, $bottomY + 5, 42, $bottomY + 5);

$pdf->SetFont('Arial', '', 7);

$bank = [
    ['Bank Name', 'INDIAN BANK'],
    ['Branch', 'Somanahalli'],
    ['A/C No', '7517859603'],
    ['IFSC', 'IDIB000S273']
];

$bkY = $bottomY + 7;

foreach ($bank as $b) {
    $pdf->SetXY(10, $bkY);
    $pdf->Cell(22, 4, safeText($b[0]), 0, 0, 'L');
    $pdf->Cell(4, 4, ':', 0, 0, 'C');
    $pdf->SetFont('Arial', 'B', 7);
    $pdf->Cell(60, 4, safeText($b[1]), 0, 0, 'L');
    $pdf->SetFont('Arial', '', 7);
    $bkY += 4;
}

$sx = 128;
$sy = $bottomY;

$pdf->Rect($sx, $sy, 70, 28);

$pdf->SetFont('Arial', 'B', 8);
$pdf->SetXY($sx, $sy + 2);
$pdf->Cell(70, 5, safeText('For ' . strtoupper($biz['business_name'] ?? 'COMPANY')), 0, 1, 'C');

$pdf->SetFont('Arial', '', 8);
$pdf->SetXY($sx, $sy + 21);
$pdf->Cell(70, 5, 'Authorised Signature', 0, 1, 'C');

/* OUTPUT */
if (ob_get_length()) ob_clean();

$fileInvoiceNo = preg_replace('/[^A-Za-z0-9_-]/', '_', $invoice['invoice_no'] ?? $invoiceId);
$pdf->Output('I', 'Invoice_' . $fileInvoiceNo . '.pdf');
exit;
?>