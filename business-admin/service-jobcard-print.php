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

/* -------------------------------------------------------
   FETCH BUSINESS DETAILS
------------------------------------------------------- */
$business_stmt = $conn->prepare("
    SELECT business_name, gstin, address_line1, address_line2, city, district, state, pincode, mobile, email 
    FROM businesses WHERE id = ?
");
$business_stmt->bind_param("i", $businessId);
$business_stmt->execute();
$business = $business_stmt->get_result()->fetch_assoc();
$business_stmt->close();

if (empty($business)) {
    $business = [
        'business_name' => 'Your Business Name',
        'gstin' => '33XXXXX0000X0Z',
        'address_line1' => 'Your Business Address',
        'city' => 'City',
        'state' => 'State',
        'pincode' => '000000',
        'mobile' => '0000000000',
        'email' => 'info@yourbusiness.com'
    ];
}

/* -------------------------------------------------------
   FETCH JOB CARD DETAILS
------------------------------------------------------- */
$jobcardId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$referer = $_SERVER['HTTP_REFERER'] ?? 'service-jobcards.php';

if ($jobcardId <= 0) {
    die('Invalid job card ID.');
}

// Fetch job card details with all related information
$stmt = $conn->prepare("
    SELECT sj.*, 
           br.branch_name,
           br.branch_code,
           br.address_line1 AS branch_address1,
           br.address_line2 AS branch_address2,
           br.city AS branch_city,
           br.state AS branch_state,
           br.pincode AS branch_pincode,
           br.mobile AS branch_mobile,
           br.email AS branch_email,
           c.full_name AS customer_name,
           c.mobile AS customer_mobile,
           c.alternate_mobile AS customer_alt_mobile,
           c.email AS customer_email,
           c.address_line1 AS customer_address1,
           c.address_line2 AS customer_address2,
           c.city AS customer_city,
           c.district AS customer_district,
           c.state AS customer_state,
           c.pincode AS customer_pincode,
           c.gstin AS customer_gstin,
           cv.vehicle_type,
           cv.registration_no,
           cv.chassis_no,
           cv.engine_no,
           cv.motor_no,
           cv.color,
           cv.current_km,
           cv.purchase_date,
           cv.battery_brand,
           cv.battery_no,
           cv.battery_capacity,
           cv.battery_warranty_upto,
           cv.charger_brand,
           cv.charger_no,
           cv.charger_type,
           cv.charger_warranty_upto,
           vb.brand_name,
           vm.model_name,
           vm.variant_name,
           bu.full_name AS created_by_name
    FROM service_job_cards sj
    LEFT JOIN branches br ON br.id = sj.branch_id
    LEFT JOIN customers c ON c.id = sj.customer_id
    LEFT JOIN customer_vehicles cv ON cv.id = sj.customer_vehicle_id
    LEFT JOIN vehicle_brands vb ON vb.id = cv.brand_id
    LEFT JOIN vehicle_models vm ON vm.id = cv.model_id
    LEFT JOIN business_users bu ON bu.id = sj.created_by
    WHERE sj.id = ? AND sj.business_id = ?
    LIMIT 1
");
$stmt->bind_param("ii", $jobcardId, $businessId);
$stmt->execute();
$jobcard = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$jobcard) {
    die('Job card not found.');
}

// Fetch complaints
$complaints = [];
$compStmt = $conn->prepare("
    SELECT id, complaint_text, priority, status 
    FROM service_complaints 
    WHERE jobcard_id = ?
    ORDER BY id ASC
");
$compStmt->bind_param("i", $jobcardId);
$compStmt->execute();
$compResult = $compStmt->get_result();
while ($row = $compResult->fetch_assoc()) {
    $complaints[] = $row;
}
$compStmt->close();

// Fetch part items if table exists
$partItems = [];
if (tableExists($conn, 'service_job_part_items')) {
    $partStmt = $conn->prepare("
        SELECT spi.*, p.product_name, p.product_code
        FROM service_job_part_items spi
        LEFT JOIN products p ON p.id = spi.product_id
        WHERE spi.jobcard_id = ?
        ORDER BY spi.id ASC
    ");
    $partStmt->bind_param("i", $jobcardId);
    $partStmt->execute();
    $partResult = $partStmt->get_result();
    while ($row = $partResult->fetch_assoc()) {
        $partItems[] = $row;
    }
    $partStmt->close();
}

// Fetch labor items if table exists
$laborItems = [];
if (tableExists($conn, 'service_job_labor_items')) {
    $laborStmt = $conn->prepare("
        SELECT sli.*, slm.labor_name
        FROM service_job_labor_items sli
        LEFT JOIN service_labor_master slm ON slm.id = sli.labor_id
        WHERE sli.jobcard_id = ?
        ORDER BY sli.id ASC
    ");
    $laborStmt->bind_param("i", $jobcardId);
    $laborStmt->execute();
    $laborResult = $laborStmt->get_result();
    while ($row = $laborResult->fetch_assoc()) {
        $laborItems[] = $row;
    }
    $laborStmt->close();
}

$statusText = ucwords(str_replace('_', ' ', $jobcard['job_status']));
$statusColor = '';
if ($jobcard['job_status'] == 'open') $statusColor = '#6c757d';
elseif ($jobcard['job_status'] == 'in_progress') $statusColor = '#ffc107';
elseif ($jobcard['job_status'] == 'waiting_parts') $statusColor = '#6c757d';
elseif ($jobcard['job_status'] == 'ready') $statusColor = '#17a2b8';
elseif ($jobcard['job_status'] == 'delivered') $statusColor = '#28a745';
elseif ($jobcard['job_status'] == 'cancelled') $statusColor = '#dc3545';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Job Card - <?= h($jobcard['jobcard_no']) ?></title>
    <style>
        @page { 
            margin: 0.5cm; 
            size: A4; 
        }
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 10px;
            font-size: 11px;
            line-height: 1.3;
        }
        table { 
            width: 100%; 
            border-collapse: collapse; 
        }
        td, th { 
            border: 1px solid #000; 
            padding: 5px; 
            vertical-align: top; 
        }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .text-left { text-align: left; }
        .text-bold { font-weight: bold; }
        .logo { 
            width: 80px; 
            height: auto; 
        }
        .no-border { border: none; }
        .page-break { 
            page-break-before: always; 
        }
        .mt-10 { margin-top: 10px; }
        .mb-10 { margin-bottom: 10px; }
        
        /* Watermark */
        .watermark {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            opacity: 0.08;
            z-index: -1;
            pointer-events: none;
        }
        
        .watermark-logo {
            width: 400px;
            height: auto;
        }
        
        .company-header {
            border: 2px solid #000;
            padding: 10px;
        }
        
        .signature-space {
            height: 80px;
            position: relative;
        }
        
        .status-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 11px;
            font-weight: bold;
            color: white;
            background: <?php echo $statusColor; ?>;
        }
        
        /* Print controls */
        .print-controls {
            position: fixed;
            top: 10px;
            right: 10px;
            background: white;
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 5px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            z-index: 1000;
        }
        
        .print-controls button {
            margin: 5px;
            padding: 8px 15px;
            background: #007bff;
            color: white;
            border: none;
            border-radius: 3px;
            cursor: pointer;
        }
        
        .print-controls button:hover {
            background: #0056b3;
        }
        
        @media print { 
            body { 
                -webkit-print-color-adjust: exact; 
            }
            .watermark { display: block; }
            .print-controls { display: none; }
        }
        @media screen {
            .watermark { display: none; }
        }
    </style>
</head>
<body>

<!-- Print Controls (Visible only on screen) -->
<div class="print-controls">
    <button onclick="window.print()">Print Job Card</button>
    <button onclick="goBack()">Back to List</button>
</div>

<!-- Watermark Logo -->
<div class="watermark">
    <img src="assets/images/brand-logo.png" alt="Logo" class="watermark-logo" 
         onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
    <div style="display:none; font-size: 80px; font-weight: bold; color: #ccc;">SERVICE JOB CARD</div>
</div>

<!-- ================== SERVICE JOB CARD ================== -->
<table class="mb-10 company-header">
    <tr>
        <td width="20%" class="no-border text-center">
            <img src="assets/images/brand-logo.png" alt="Logo" class="logo" 
                 onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
            <div style="display:none; width: 80px; height: 80px; border: 2px solid #000; text-align: center; line-height: 80px; font-weight: bold;">
                SJ
            </div>
        </td>
        <td width="80%" class="no-border text-left">
            <h2 style="margin:2px 0; font-size:18px;"><?= h($business['business_name']) ?></h2>
            <p style="margin:2px 0; font-size:11px;">
                <?= h($business['address_line1'] ?: '') ?>
                <?= h($business['address_line2'] ?: '') ?><br>
                <?= h($business['city'] ?: '') ?><?= (!empty($business['city']) && !empty($business['state'])) ? ', ' : '' ?><?= h($business['state'] ?: '') ?>
                <?= h($business['pincode'] ?: '') ?><br>
                Phone: <?= h($business['mobile'] ?: '') ?> | Email: <?= h($business['email'] ?: '') ?><br>
                GSTIN: <?= h($business['gstin'] ?: 'Not Available') ?>
            </p>
        </td>
    </tr>
</table>

<table class="mb-10">
    <tr>
        <td colspan="2" class="no-border text-center">
            <h3 style="margin:5px 0; font-size:14px; text-decoration:underline;">SERVICE JOB CARD</h3>
            <h4 style="margin:2px 0; font-size:12px;">Job Card No: <?= h($jobcard['jobcard_no']) ?></h4>
        </td>
    </tr>
</table>

<table class="mb-10">
    <tr>
        <td width="50%">
            <strong>Job Card Details:</strong><br>
            Date: <strong><?= !empty($jobcard['service_date']) ? date('d-m-Y', strtotime($jobcard['service_date'])) : '-' ?></strong><br>
            Time: <strong><?= !empty($jobcard['service_date']) ? date('h:i A', strtotime($jobcard['service_date'])) : '-' ?></strong><br>
            Promised Delivery: <strong><?= !empty($jobcard['promised_delivery']) ? date('d-m-Y h:i A', strtotime($jobcard['promised_delivery'])) : 'Not specified' ?></strong><br>
            Status: <strong><span class="status-badge"><?= $statusText ?></span></strong>
        </td>
        <td width="50%">
            <strong>Branch Details:</strong><br>
            Branch: <?= h($jobcard['branch_name'] ?: '-') ?><br>
            Code: <?= h($jobcard['branch_code'] ?: '-') ?><br>
            Phone: <?= h($jobcard['branch_mobile'] ?: '-') ?><br>
            Email: <?= h($jobcard['branch_email'] ?: '-') ?>
        </td>
    </tr>
</table>

<table class="mb-10">
    <tr>
        <td width="50%">
            <strong>Customer Details:</strong><br>
            Name: <?= h($jobcard['customer_name'] ?: '-') ?><br>
            Mobile: <?= h($jobcard['customer_mobile'] ?: '-') ?>
            <?php if (!empty($jobcard['customer_alt_mobile'])): ?>
            <br>Alt Mobile: <?= h($jobcard['customer_alt_mobile']) ?>
            <?php endif; ?>
            <?php if (!empty($jobcard['customer_email'])): ?>
            <br>Email: <?= h($jobcard['customer_email']) ?>
            <?php endif; ?>
            <?php if (!empty($jobcard['customer_address1']) || !empty($jobcard['customer_city'])): ?>
            <br>Address: <?= h($jobcard['customer_address1'] ?: '') ?> <?= h($jobcard['customer_address2'] ?: '') ?>
            <br><?= h($jobcard['customer_city'] ?: '') ?><?= (!empty($jobcard['customer_city']) && !empty($jobcard['customer_state'])) ? ', ' : '' ?><?= h($jobcard['customer_state'] ?: '') ?>
            <?= h($jobcard['customer_pincode'] ?: '') ?>
            <?php endif; ?>
        </td>
        <td width="50%">
            <strong>Vehicle Details:</strong><br>
            Vehicle: <strong><?= h($jobcard['brand_name'] ?: '-') ?> <?= h($jobcard['model_name'] ?: '') ?> <?= h($jobcard['variant_name'] ?: '') ?></strong><br>
            Registration No: <?= h($jobcard['registration_no'] ?: '-') ?><br>
            Chassis No: <?= h($jobcard['chassis_no'] ?: '-') ?><br>
            Engine No: <?= h($jobcard['engine_no'] ?: '-') ?><br>
            Motor No: <?= h($jobcard['motor_no'] ?: '-') ?><br>
            Color: <?= h($jobcard['color'] ?: '-') ?><br>
            Current KM: <?= number_format((int)($jobcard['current_km'] ?? 0)) ?>
        </td>
    </tr>
</table>

<table class="mb-10">
    <tr>
        <td width="33%">
            <strong>Service Details:</strong><br>
            Opening KM: <?= number_format((int)$jobcard['opening_km']) ?><br>
            Fuel Level: <?= h($jobcard['fuel_level'] ?: '-') ?><br>
            Battery %: <?= h($jobcard['battery_percentage'] ?: '-') ?><br>
            Washing: <?= ((int)$jobcard['washing_required'] === 1) ? 'Yes' : 'No' ?><br>
            Road Test: <?= ((int)$jobcard['road_test_required'] === 1) ? 'Yes' : 'No' ?>
        </td>
        <td width="33%">
            <strong>Battery Details:</strong><br>
            Brand: <?= h($jobcard['battery_brand'] ?: '-') ?><br>
            Number: <?= h($jobcard['battery_no'] ?: '-') ?><br>
            Capacity: <?= h($jobcard['battery_capacity'] ?: '-') ?><br>
            Warranty Upto: <?= !empty($jobcard['battery_warranty_upto']) ? date('d-m-Y', strtotime($jobcard['battery_warranty_upto'])) : '-' ?>
        </td>
        <td width="34%">
            <strong>Charger Details:</strong><br>
            Brand: <?= h($jobcard['charger_brand'] ?: '-') ?><br>
            Number: <?= h($jobcard['charger_no'] ?: '-') ?><br>
            Type: <?= h($jobcard['charger_type'] ?: '-') ?><br>
            Warranty Upto: <?= !empty($jobcard['charger_warranty_upto']) ? date('d-m-Y', strtotime($jobcard['charger_warranty_upto'])) : '-' ?>
        </td>
    </tr>
</table>

<?php if (!empty($complaints)): ?>
<table class="mb-10">
    <tr>
        <td colspan="3">
            <strong>Customer Complaints:</strong>
            <table style="width:100%; margin-top:5px;">
                <thead>
                    <tr>
                        <th width="5%">#</th>
                        <th width="70%">Complaint</th>
                        <th width="15%">Priority</th>
                        <th width="10%">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $i = 1; foreach ($complaints as $comp): ?>
                    <tr>
                        <td class="text-center"><?= $i++ ?></td>
                        <td><?= nl2br(h($comp['complaint_text'])) ?></td>
                        <td class="text-center"><?= ucfirst(h($comp['priority'])) ?></td>
                        <td class="text-center"><?= ucwords(str_replace('_', ' ', h($comp['status']))) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </td>
    </tr>
</table>
<?php endif; ?>

<?php if (!empty($partItems)): ?>
<table class="mb-10">
    <tr>
        <td colspan="6">
            <strong>Parts Used:</strong>
            <table style="width:100%; margin-top:5px;">
                <thead>
                    <tr>
                        <th width="5%">#</th>
                        <th width="35%">Product Name</th>
                        <th width="15%">Product Code</th>
                        <th width="10%">Qty</th>
                        <th width="20%">Unit Price</th>
                        <th width="15%">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $i = 1; foreach ($partItems as $item): ?>
                    <tr>
                        <td class="text-center"><?= $i++ ?></td>
                        <td><?= h($item['product_name'] ?: '-') ?></td>
                        <td><?= h($item['product_code'] ?: '-') ?></td>
                        <td class="text-center"><?= number_format($item['qty'], 2) ?></td>
                        <td class="text-right"><?= money($item['unit_price']) ?></td>
                        <td class="text-right"><?= money($item['line_total']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </td>
    </tr>
</table>
<?php endif; ?>

<?php if (!empty($laborItems)): ?>
<table class="mb-10">
    <tr>
        <td colspan="6">
            <strong>Labor Charges:</strong>
            <table style="width:100%; margin-top:5px;">
                <thead>
                    <tr>
                        <th width="5%">#</th>
                        <th width="35%">Labor Name</th>
                        <th width="35%">Description</th>
                        <th width="10%">Qty</th>
                        <th width="15%">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $i = 1; foreach ($laborItems as $item): ?>
                    <tr>
                        <td class="text-center"><?= $i++ ?></td>
                        <td><?= h($item['labor_name'] ?: '-') ?></td>
                        <td><?= nl2br(h($item['description'])) ?></td>
                        <td class="text-center"><?= number_format($item['qty'], 2) ?></td>
                        <td class="text-right"><?= money($item['line_total']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </td>
    </tr>
</table>
<?php endif; ?>

<?php if (!empty($jobcard['customer_voice']) || !empty($jobcard['technician_observation']) || !empty($jobcard['recommendation'])): ?>
<table class="mb-10">
    <?php if (!empty($jobcard['customer_voice'])): ?>
    <tr>
        <td>
            <strong>Customer Voice:</strong><br>
            <?= nl2br(h($jobcard['customer_voice'])) ?>
        </td>
    </tr>
    <?php endif; ?>
    <?php if (!empty($jobcard['technician_observation'])): ?>
    <tr>
        <td>
            <strong>Technician Observation:</strong><br>
            <?= nl2br(h($jobcard['technician_observation'])) ?>
        </td>
    </tr>
    <?php endif; ?>
    <?php if (!empty($jobcard['recommendation'])): ?>
    <tr>
        <td>
            <strong>Recommendation:</strong><br>
            <?= nl2br(h($jobcard['recommendation'])) ?>
        </td>
    </tr>
    <?php endif; ?>
</table>
<?php endif; ?>

<table class="mb-10">
    <tr>
        <td width="50%">
            <strong>Estimated Amount:</strong><br>
            <h4 style="margin:5px 0;"><?= money($jobcard['estimated_amount']) ?></h4>
        </td>
        <td width="50%">
            <strong>Final Amount:</strong><br>
            <h4 style="margin:5px 0; color:#28a745;"><?= money($jobcard['final_amount']) ?></h4>
        </td>
    </tr>
</table>

<table class="mb-10">
    <tr>
        <td width="50%">
            <strong>Technician Remarks:</strong><br><br><br>
            _________________________<br>
            <small>Technician Signature</small>
        </td>
        <td width="50%" class="text-right">
            <strong>For <?= h($business['business_name']) ?></strong><br>
            <div class="signature-space">
                _________________________<br>
                <small>Authorized Signatory</small>
            </div>
        </td>
    </tr>
</table>

<div class="text-center text-muted mt-10" style="font-size: 10px;">
    <small>This is a computer generated document and does not require a physical signature.</small><br>
    <small>Thank you for choosing <?= h($business['business_name']) ?>!</small>
</div>

<script>
// Function to go back to previous page
function goBack() {
    window.location.href = '<?= h($referer) ?>';
}

// Auto-print on load
window.onload = function() {
    // Auto-trigger print dialog
    window.print();
    
    // Automatically go back after print dialog closes (whether printed or canceled)
    if (window.matchMedia) {
        const mediaQueryList = window.matchMedia('print');
        mediaQueryList.addListener(function(mql) {
            if (!mql.matches) {
                // Print dialog was closed (canceled or printed)
                setTimeout(function() {
                    goBack();
                }, 500);
            }
        });
    }
    
    // Alternative fallback for browsers that don't support matchMedia
    window.onafterprint = function() {
        setTimeout(function() {
            goBack();
        }, 500);
    };
};

// Fallback: if no print events fire, go back after 10 seconds
setTimeout(function() {
    goBack();
}, 10000);

// Keyboard shortcut for going back (Esc key)
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        goBack();
    }
});
</script>

</body>
</html>

<?php
$stmt->close();
mysqli_close($conn);
?>