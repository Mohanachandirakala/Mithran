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

function convertNumberToWords($number) {
    $ones = ["", "One", "Two", "Three", "Four", "Five", "Six", "Seven", "Eight", "Nine", "Ten", 
             "Eleven", "Twelve", "Thirteen", "Fourteen", "Fifteen", "Sixteen", "Seventeen", "Eighteen", "Nineteen"];
    $tens = ["", "", "Twenty", "Thirty", "Forty", "Fifty", "Sixty", "Seventy", "Eighty", "Ninety"];
    $thousands = ["", "Thousand", "Lakh", "Crore"];

    if ($number == 0) return 'Zero';

    $rupees = floor($number);
    
    $words = '';
    
    if ($rupees > 0) {
        $counter = 0;
        $num = $rupees;
        
        while ($num > 0) {
            $part = $num % 1000;
            if ($part > 0) {
                $partWords = '';
                if ($part >= 100) {
                    $partWords .= $ones[floor($part / 100)] . ' Hundred ';
                    $part %= 100;
                }
                if ($part >= 20) {
                    $partWords .= $tens[floor($part / 10)] . ' ';
                    $part %= 10;
                }
                if ($part > 0) {
                    $partWords .= $ones[$part] . ' ';
                }
                $words = $partWords . $thousands[$counter] . ' ' . $words;
            }
            $num = floor($num / 1000);
            $counter++;
        }
    } else {
        $words = 'Zero ';
    }
    
    return trim($words) . ' Rupees';
}

/* -------------------------------------------------------
   FETCH PRE BOOKING DETAILS
------------------------------------------------------- */
$bookingId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$referer = $_SERVER['HTTP_REFERER'] ?? 'pre-bookings.php';

if ($bookingId <= 0) {
    die('Invalid pre booking ID.');
}

// Fetch business details
$business_stmt = $conn->prepare("
    SELECT business_name, gstin, address_line1, address_line2, city, district, state, pincode, mobile, email 
    FROM businesses WHERE id = ?
");
if ($business_stmt) {
    $business_stmt->bind_param("i", $businessId);
    $business_stmt->execute();
    $business = $business_stmt->get_result()->fetch_assoc();
    $business_stmt->close();
} else {
    $business = null;
}

// Fetch branch details
$branch_stmt = $conn->prepare("
    SELECT b.branch_name, b.branch_code, b.address_line1, b.address_line2, b.city, b.district, b.state, b.pincode, b.mobile, b.email 
    FROM branches b
    INNER JOIN pre_bookings pb ON pb.branch_id = b.id
    WHERE pb.id = ? AND pb.business_id = ?
");
if ($branch_stmt) {
    $branch_stmt->bind_param("ii", $bookingId, $businessId);
    $branch_stmt->execute();
    $branch = $branch_stmt->get_result()->fetch_assoc();
    $branch_stmt->close();
} else {
    $branch = null;
}

// Fetch pre booking details
$stmt = $conn->prepare("
    SELECT pb.*, 
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
           pm.method_name
    FROM pre_bookings pb
    LEFT JOIN customers c ON c.id = pb.customer_id
    LEFT JOIN payment_methods pm ON pm.id = pb.payment_method_id
    WHERE pb.id = ? AND pb.business_id = ?
    LIMIT 1
");
if (!$stmt) {
    die('Failed to prepare query.');
}

$stmt->bind_param("ii", $bookingId, $businessId);
$stmt->execute();
$booking = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$booking) {
    die('Pre booking not found.');
}

// Prepare item details
$itemText = '';
if ($booking['booking_type'] == 'vehicle') {
    $itemText = trim(
        ($booking['brand_name'] ?? 'Brand') . ' - ' .
        ($booking['model_name'] ?? 'Model') .
        (!empty($booking['variant_name']) ? ' - ' . $booking['variant_name'] : '')
    );
} elseif ($booking['booking_type'] == 'product') {
    $itemText = trim($booking['product_name'] ?? 'Product');
}

$statusText = ucfirst($booking['status']);

// Default company info if not found
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

// Default branch info if not found
if (empty($branch)) {
    $branch = [
        'branch_name' => $booking['branch_id'] ? 'Branch ' . $booking['branch_id'] : 'Main Branch',
        'branch_code' => 'BR001',
        'address_line1' => $business['address_line1'],
        'city' => $business['city'],
        'state' => $business['state'],
        'pincode' => $business['pincode'],
        'mobile' => $business['mobile'],
        'email' => $business['email']
    ];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pre Booking - <?= h($booking['booking_no']) ?></title>
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
    <button onclick="window.print()">Print Pre Booking</button>
    <button onclick="goBack()">Back to Previous Page</button>
</div>

<!-- Watermark Logo -->
<div class="watermark">
    <img src="assets/images/brand-logo.png" alt="Logo" class="watermark-logo" 
         onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
    <div style="display:none; font-size: 80px; font-weight: bold; color: #ccc;">PRE BOOKING</div>
</div>

<!-- ================== PRE BOOKING SLIP ================== -->
<table class="mb-10 company-header">
     <tr>
        <td width="20%" class="no-border text-center">
            <img src="assets/images/brand-logo.png" alt="Logo" class="logo" 
                 onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
            <div style="display:none; width: 80px; height: 80px; border: 2px solid #000; text-align: center; line-height: 80px; font-weight: bold;">
                PB
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
            <h3 style="margin:5px 0; font-size:14px; text-decoration:underline;">PRE BOOKING SLIP</h3>
         </td>
     </tr>
</table>

<table class="mb-10">
     <tr>
        <td width="50%">
            <strong>Booking Details:</strong><br>
            Booking No: <strong><?= h($booking['booking_no']) ?></strong><br>
            Booking Date: <strong><?= !empty($booking['booking_date']) ? date('d-m-Y', strtotime($booking['booking_date'])) : '-' ?></strong><br>
            Booking Time: <strong><?= !empty($booking['booking_date']) ? date('h:i A', strtotime($booking['booking_date'])) : '-' ?></strong><br>
            Booking Type: <strong><?= ucfirst(h($booking['booking_type'])) ?></strong><br>
            Booking Status: <span class="status-badge" style="background: <?php 
                if ($booking['status'] == 'open') echo '#6c757d';
                elseif ($booking['status'] == 'confirmed') echo '#007bff';
                elseif ($booking['status'] == 'cancelled') echo '#dc3545';
                elseif ($booking['status'] == 'converted') echo '#ffc107';
                elseif ($booking['status'] == 'delivered') echo '#28a745';
                else echo '#6c757d';
            ?>;">
                <?= $statusText ?>
            </span>
         </td>
        <td width="50%">
            <strong>Branch Details:</strong><br>
            Branch: <?= h($branch['branch_name']) ?><br>
            Code: <?= h($branch['branch_code']) ?><br>
            Phone: <?= h($branch['mobile'] ?: '-') ?><br>
            Email: <?= h($branch['email'] ?: '-') ?>
         </td>
     </tr>
</table>

<table class="mb-10">
     <tr>
        <td width="50%">
            <strong>Customer Details:</strong><br>
            Name: <?= h($booking['customer_name'] ?: '-') ?><br>
            Mobile: <?= h($booking['customer_mobile'] ?: '-') ?>
            <?php if (!empty($booking['customer_alt_mobile'])): ?>
            <br>Alt Mobile: <?= h($booking['customer_alt_mobile']) ?>
            <?php endif; ?>
            <?php if (!empty($booking['customer_email'])): ?>
            <br>Email: <?= h($booking['customer_email']) ?>
            <?php endif; ?>
            <?php if (!empty($booking['customer_address1']) || !empty($booking['customer_city'])): ?>
            <br>Address: <?= h($booking['customer_address1'] ?: '') ?> <?= h($booking['customer_address2'] ?: '') ?>
            <br><?= h($booking['customer_city'] ?: '') ?><?= (!empty($booking['customer_city']) && !empty($booking['customer_state'])) ? ', ' : '' ?><?= h($booking['customer_state'] ?: '') ?>
            <?= h($booking['customer_pincode'] ?: '') ?>
            <?php endif; ?>
            <?php if (!empty($booking['customer_gstin'])): ?>
            <br>GSTIN: <?= h($booking['customer_gstin']) ?>
            <?php endif; ?>
         </td>
        <td width="50%">
            <strong>Item Details:</strong><br>
            <?php if ($booking['booking_type'] == 'vehicle'): ?>
            <strong>Vehicle:</strong> <?= h($itemText) ?><br>
            <?php if (!empty($booking['color_preference'])): ?>
            <strong>Color Preference:</strong> <?= h($booking['color_preference']) ?><br>
            <?php endif; ?>
            <?php else: ?>
            <strong>Product:</strong> <?= h($itemText) ?><br>
            <?php endif; ?>
            <strong>Booking Amount:</strong> <span style="color:#28a745; font-weight:bold;"><?= money($booking['booking_amount']) ?></span><br>
            <strong>Expected Delivery:</strong> <?= !empty($booking['expected_delivery_date']) ? date('d-m-Y', strtotime($booking['expected_delivery_date'])) : 'To be confirmed' ?><br>
            <strong>Payment Method:</strong> <?= h($booking['method_name'] ?: '-') ?>
            <?php if (!empty($booking['reference_no'])): ?>
            <br><strong>Reference No:</strong> <?= h($booking['reference_no']) ?>
            <?php endif; ?>
         </td>
     </tr>
</table>

<?php if (!empty($booking['notes'])): ?>
<table class="mb-10">
     <tr>
        <td width="100%">
            <strong>Notes / Remarks:</strong><br>
            <?= nl2br(h($booking['notes'])) ?>
         </td>
     </tr>
</table>
<?php endif; ?>

<table class="mb-10">
     <tr>
        <td width="50%">
            <strong>Amount in Words:</strong><br>
            INR <?= ucwords(convertNumberToWords($booking['booking_amount'])) ?> Only
         </td>
        <td width="50%">
            <strong>Terms & Conditions:</strong><br>
            1. Booking amount is non-refundable<br>
            2. Delivery date subject to availability<br>
            3. Final price may vary based on accessories<br>
            4. Valid for 30 days from booking date<br>
            5. Subject to Dharmapuri Jurisdiction
         </td>
     </tr>
</table>

<table class="mb-10">
     <tr>
        <td width="50%">
            <strong>Customer Declaration:</strong><br><br>
            I/We confirm that the above details are correct and agree to the terms and conditions mentioned above.
            <br><br><br>
            _________________________<br>
            <small>Customer Signature</small>
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
// No need to close $stmt here as it's already closed above
// Just close the database connection
if (isset($conn)) {
    mysqli_close($conn);
}
?>