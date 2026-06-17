<?php
session_start();
date_default_timezone_set('Asia/Kolkata');

require_once 'includes/config.php';

/*
|--------------------------------------------------------------------------
| Business Admin GST Report Print - gst-report-print.php
|--------------------------------------------------------------------------
| This page provides a print-friendly version of the GST report
|--------------------------------------------------------------------------
*/

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

function numf($amount): string
{
    return number_format((float)$amount, 2, '.', '');
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

function fetchAllAssoc(mysqli $conn, string $sql): array
{
    $rows = [];
    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
    }
    return $rows;
}

/* -------------------------------------------------------
   VALIDATE USER
------------------------------------------------------- */
if (!tableExists($conn, 'business_users') || !tableExists($conn, 'businesses')) {
    die('Required tables not found.');
}

$loggedUser = null;
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

if (
    !$loggedUser ||
    (int)($loggedUser['status'] ?? 0) !== 1 ||
    ($loggedUser['business_status'] ?? '') !== 'active'
) {
    session_destroy();
    header('Location: login.php');
    exit;
}

/* -------------------------------------------------------
   TABLE CHECKS
------------------------------------------------------- */
$hasBranches = tableExists($conn, 'branches');
$hasCustomers = tableExists($conn, 'customers');
$hasSalesInvoices = tableExists($conn, 'sales_invoices');
$hasServiceInvoices = tableExists($conn, 'service_invoices');

if (!$hasBranches) {
    die('branches table not found.');
}
if (!$hasSalesInvoices && !$hasServiceInvoices) {
    die('No invoice tables found.');
}

/* -------------------------------------------------------
   MASTER DATA
------------------------------------------------------- */
$branches = fetchAllAssoc(
    $conn,
    "SELECT id, branch_name, branch_code
     FROM branches
     WHERE business_id = {$businessId}
     ORDER BY branch_name ASC"
);

/* -------------------------------------------------------
   FILTERS (GET FROM URL PARAMETERS)
------------------------------------------------------- */
$search = trim($_GET['search'] ?? '');
$branchFilter = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : 0;
$reportType = trim($_GET['report_type'] ?? 'all'); // all, sales, service
$invoiceTypeFilter = trim($_GET['invoice_type'] ?? '');
$paymentStatusFilter = trim($_GET['payment_status'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? date('Y-m-01'));
$dateTo = trim($_GET['date_to'] ?? date('Y-m-t'));

$allowedReportTypes = ['all', 'sales', 'service'];
$allowedInvoiceTypes = ['vehicle_sale', 'product_sale', 'mixed_sale'];
$allowedPaymentStatuses = ['unpaid', 'partial', 'paid'];

if (!in_array($reportType, $allowedReportTypes, true)) {
    $reportType = 'all';
}
if ($invoiceTypeFilter !== '' && !in_array($invoiceTypeFilter, $allowedInvoiceTypes, true)) {
    $invoiceTypeFilter = '';
}
if ($paymentStatusFilter !== '' && !in_array($paymentStatusFilter, $allowedPaymentStatuses, true)) {
    $paymentStatusFilter = '';
}

/* -------------------------------------------------------
   BUILD SALES QUERY
------------------------------------------------------- */
$unionParts = [];

if ($hasSalesInvoices && ($reportType === 'all' || $reportType === 'sales')) {
    $salesWhere = ["si.business_id = {$businessId}"];

    if ($branchFilter > 0) {
        $salesWhere[] = "si.branch_id = {$branchFilter}";
    }
    if ($invoiceTypeFilter !== '') {
        $safe = $conn->real_escape_string($invoiceTypeFilter);
        $salesWhere[] = "si.invoice_type = '{$safe}'";
    }
    if ($paymentStatusFilter !== '') {
        $safe = $conn->real_escape_string($paymentStatusFilter);
        $salesWhere[] = "si.payment_status = '{$safe}'";
    }
    if ($dateFrom !== '') {
        $safe = $conn->real_escape_string($dateFrom);
        $salesWhere[] = "DATE(si.invoice_date) >= '{$safe}'";
    }
    if ($dateTo !== '') {
        $safe = $conn->real_escape_string($dateTo);
        $salesWhere[] = "DATE(si.invoice_date) <= '{$safe}'";
    }
    if ($search !== '') {
        $safe = $conn->real_escape_string($search);
        $salesWhere[] = "(
            si.invoice_no LIKE '%{$safe}%'
            OR c.full_name LIKE '%{$safe}%'
            OR c.mobile LIKE '%{$safe}%'
            OR br.branch_name LIKE '%{$safe}%'
        )";
    }

    $salesWhereSql = implode(' AND ', $salesWhere);

    $unionParts[] = "
        SELECT
            'Sales' AS report_source,
            si.id,
            si.invoice_no,
            si.invoice_date,
            br.branch_name,
            br.branch_code,
            c.full_name AS customer_name,
            c.mobile AS customer_mobile,
            si.invoice_type,
            si.payment_status,
            si.subtotal,
            si.discount_amount,
            (si.subtotal - si.discount_amount) AS taxable_value,
            si.cgst_amount,
            si.sgst_amount,
            si.igst_amount,
            si.cess_amount,
            si.round_off,
            si.grand_total
        FROM sales_invoices si
        LEFT JOIN branches br ON br.id = si.branch_id
        LEFT JOIN customers c ON c.id = si.customer_id
        WHERE {$salesWhereSql}
    ";
}

/* -------------------------------------------------------
   BUILD SERVICE QUERY
------------------------------------------------------- */
if ($hasServiceInvoices && ($reportType === 'all' || $reportType === 'service')) {
    $serviceWhere = ["si.business_id = {$businessId}"];

    if ($branchFilter > 0) {
        $serviceWhere[] = "si.branch_id = {$branchFilter}";
    }
    if ($paymentStatusFilter !== '') {
        $safe = $conn->real_escape_string($paymentStatusFilter);
        $serviceWhere[] = "si.payment_status = '{$safe}'";
    }
    if ($dateFrom !== '') {
        $safe = $conn->real_escape_string($dateFrom);
        $serviceWhere[] = "DATE(si.invoice_date) >= '{$safe}'";
    }
    if ($dateTo !== '') {
        $safe = $conn->real_escape_string($dateTo);
        $serviceWhere[] = "DATE(si.invoice_date) <= '{$safe}'";
    }
    if ($search !== '') {
        $safe = $conn->real_escape_string($search);
        $serviceWhere[] = "(
            si.invoice_no LIKE '%{$safe}%'
            OR c.full_name LIKE '%{$safe}%'
            OR c.mobile LIKE '%{$safe}%'
            OR br.branch_name LIKE '%{$safe}%'
        )";
    }

    $serviceWhereSql = implode(' AND ', $serviceWhere);

    $unionParts[] = "
        SELECT
            'Service' AS report_source,
            si.id,
            si.invoice_no,
            si.invoice_date,
            br.branch_name,
            br.branch_code,
            c.full_name AS customer_name,
            c.mobile AS customer_mobile,
            'service_invoice' AS invoice_type,
            si.payment_status,
            si.subtotal,
            si.discount_amount,
            (si.subtotal - si.discount_amount) AS taxable_value,
            si.cgst_amount,
            si.sgst_amount,
            si.igst_amount,
            0.00 AS cess_amount,
            0.00 AS round_off,
            si.grand_total
        FROM service_invoices si
        LEFT JOIN branches br ON br.id = si.branch_id
        LEFT JOIN customers c ON c.id = si.customer_id
        WHERE {$serviceWhereSql}
    ";
}

$rows = [];
if (!empty($unionParts)) {
    $mainSql = implode(" UNION ALL ", $unionParts) . " ORDER BY invoice_date DESC, id DESC";
    $rows = fetchAllAssoc($conn, $mainSql);
}

/* -------------------------------------------------------
   SUMMARY
------------------------------------------------------- */
$totalInvoices = count($rows);
$totalSubtotal = 0.00;
$totalDiscount = 0.00;
$totalTaxable = 0.00;
$totalCgst = 0.00;
$totalSgst = 0.00;
$totalIgst = 0.00;
$totalCess = 0.00;
$totalRoundOff = 0.00;
$totalGrand = 0.00;

foreach ($rows as $row) {
    $totalSubtotal += (float)($row['subtotal'] ?? 0);
    $totalDiscount += (float)($row['discount_amount'] ?? 0);
    $totalTaxable += (float)($row['taxable_value'] ?? 0);
    $totalCgst += (float)($row['cgst_amount'] ?? 0);
    $totalSgst += (float)($row['sgst_amount'] ?? 0);
    $totalIgst += (float)($row['igst_amount'] ?? 0);
    $totalCess += (float)($row['cess_amount'] ?? 0);
    $totalRoundOff += (float)($row['round_off'] ?? 0);
    $totalGrand += (float)($row['grand_total'] ?? 0);
}

/* -------------------------------------------------------
   GET BUSINESS DETAILS FOR PRINT HEADER
------------------------------------------------------- */
$businessDetails = null;
if (tableExists($conn, 'businesses')) {
    $stmt = $conn->prepare("SELECT business_name, gstin, address_line1, city, state, pincode, mobile, email FROM businesses WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param('i', $businessId);
        $stmt->execute();
        $businessDetails = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}

/* -------------------------------------------------------
   GET BRANCH NAME FOR FILTER
------------------------------------------------------- */
$branchName = 'All Branches';
if ($branchFilter > 0) {
    foreach ($branches as $branch) {
        if ($branch['id'] == $branchFilter) {
            $branchName = $branch['branch_name'];
            break;
        }
    }
}

/* -------------------------------------------------------
   PAGE
------------------------------------------------------- */
$pageTitle = 'GST Report Print';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo h($businessDetails['business_name'] ?? 'Business'); ?> - GST Report</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: Arial, sans-serif;
            line-height: 1.5;
            color: #333;
            background: #fff;
            padding: 20px;
        }
        .print-container {
            max-width: 1400px;
            margin: 0 auto;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #333;
        }
        .header h1 {
            font-size: 28px;
            margin-bottom: 5px;
            color: #000;
        }
        .header h3 {
            font-size: 18px;
            font-weight: normal;
            margin-bottom: 10px;
            color: #555;
        }
        .business-details {
            margin-bottom: 15px;
            font-size: 14px;
        }
        .business-details p {
            margin: 3px 0;
        }
        .report-info {
            margin: 20px 0;
            padding: 15px;
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 5px;
            font-size: 14px;
        }
        .report-info table {
            width: 100%;
            border-collapse: collapse;
        }
        .report-info td {
            padding: 5px 10px;
        }
        .report-info td:first-child {
            font-weight: bold;
            width: 150px;
        }
        .summary-cards {
            margin: 20px 0;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        .card {
            flex: 1 1 calc(16.666% - 10px);
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 5px;
            padding: 10px;
            text-align: center;
        }
        .card p {
            font-size: 12px;
            color: #6c757d;
            margin-bottom: 5px;
        }
        .card h4 {
            font-size: 16px;
            color: #333;
            margin: 0;
        }
        .card .primary { color: #007bff; }
        .card .success { color: #28a745; }
        .card .danger { color: #dc3545; }
        .card .warning { color: #ffc107; }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
            font-size: 12px;
        }
        th {
            background: #343a40;
            color: white;
            padding: 10px 5px;
            text-align: left;
            font-weight: bold;
        }
        td {
            padding: 8px 5px;
            border: 1px solid #dee2e6;
        }
        tr:nth-child(even) {
            background: #f8f9fa;
        }
        .total-row {
            background: #343a40 !important;
            color: white;
            font-weight: bold;
        }
        .total-row td {
            border-color: #454d55;
        }
        .badge {
            display: inline-block;
            padding: 3px 6px;
            border-radius: 3px;
            font-size: 10px;
            font-weight: bold;
        }
        .badge-primary { background: #007bff; color: white; }
        .badge-success { background: #28a745; color: white; }
        .badge-warning { background: #ffc107; color: #333; }
        .badge-danger { background: #dc3545; color: white; }
        .badge-secondary { background: #6c757d; color: white; }
        .footer {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #dee2e6;
            text-align: center;
            font-size: 12px;
            color: #6c757d;
        }
        .footer p {
            margin: 3px 0;
        }
        @media print {
            body {
                padding: 0;
            }
            .no-print {
                display: none !important;
            }
            .print-container {
                max-width: 100%;
            }
            th {
                background: #333 !important;
                color: white !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .badge-primary, .badge-success, .badge-warning, .badge-danger, .badge-secondary {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }
    </style>
</head>
<body>
    <div class="print-container">
        <!-- Header -->
        <div class="header">
            <h1><?php echo h($businessDetails['business_name'] ?? 'Business Name'); ?></h1>
            <h3>GST Report</h3>
            <div class="business-details">
                <p><?php echo h($businessDetails['address_line1'] ?? ''); ?>, <?php echo h($businessDetails['city'] ?? ''); ?> - <?php echo h($businessDetails['pincode'] ?? ''); ?></p>
                <p><?php echo h($businessDetails['state'] ?? ''); ?> | GSTIN: <?php echo h($businessDetails['gstin'] ?? 'N/A'); ?></p>
                <p>Tel: <?php echo h($businessDetails['mobile'] ?? 'N/A'); ?> | Email: <?php echo h($businessDetails['email'] ?? 'N/A'); ?></p>
            </div>
        </div>

        <!-- Report Information -->
        <div class="report-info">
            <table>
                <tr>
                    <td>Report Period:</td>
                    <td><?php echo date('d M Y', strtotime($dateFrom)); ?> to <?php echo date('d M Y', strtotime($dateTo)); ?></td>
                    <td>Branch:</td>
                    <td><?php echo h($branchName); ?></td>
                </tr>
                <tr>
                    <td>Report Type:</td>
                    <td><?php echo ucfirst($reportType); ?></td>
                    <td>Generated On:</td>
                    <td><?php echo date('d M Y h:i A'); ?></td>
                </tr>
                <?php if (!empty($invoiceTypeFilter)): ?>
                <tr>
                    <td>Invoice Type:</td>
                    <td><?php echo ucwords(str_replace('_', ' ', $invoiceTypeFilter)); ?></td>
                    <td>Payment Status:</td>
                    <td><?php echo ucfirst($paymentStatusFilter); ?></td>
                </tr>
                <?php endif; ?>
                <?php if (!empty($search)): ?>
                <tr>
                    <td>Search:</td>
                    <td colspan="3"><?php echo h($search); ?></td>
                </tr>
                <?php endif; ?>
            </table>
        </div>

        <!-- Summary Cards -->
        <div class="summary-cards">
            <div class="card">
                <p>Total Invoices</p>
                <h4><?php echo number_format($totalInvoices); ?></h4>
            </div>
            <div class="card">
                <p>Taxable Value</p>
                <h4 class="primary"><?php echo money($totalTaxable); ?></h4>
            </div>
            <div class="card">
                <p>CGST</p>
                <h4 class="success"><?php echo money($totalCgst); ?></h4>
            </div>
            <div class="card">
                <p>SGST</p>
                <h4 class="success"><?php echo money($totalSgst); ?></h4>
            </div>
            <div class="card">
                <p>IGST</p>
                <h4 class="warning"><?php echo money($totalIgst); ?></h4>
            </div>
            <div class="card">
                <p>CESS</p>
                <h4 class="danger"><?php echo money($totalCess); ?></h4>
            </div>
        </div>

        <div class="summary-cards">
            <div class="card">
                <p>Subtotal</p>
                <h4><?php echo money($totalSubtotal); ?></h4>
            </div>
            <div class="card">
                <p>Discount</p>
                <h4 class="danger"><?php echo money($totalDiscount); ?></h4>
            </div>
            <div class="card">
                <p>Grand Total</p>
                <h4 class="primary"><?php echo money($totalGrand); ?></h4>
            </div>
        </div>

        <!-- GST Report Table -->
        <h3 style="margin: 20px 0 10px;">GST Invoice Details</h3>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Source</th>
                    <th>Invoice No</th>
                    <th>Date</th>
                    <th>Branch</th>
                    <th>Customer</th>
                    <th>Type</th>
                    <th>Payment</th>
                    <th>Taxable (₹)</th>
                    <th>CGST (₹)</th>
                    <th>SGST (₹)</th>
                    <th>IGST (₹)</th>
                    <th>CESS (₹)</th>
                    <th>Total (₹)</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($rows)): ?>
                    <?php $i = 1; foreach ($rows as $row): ?>
                        <tr>
                            <td><?php echo $i++; ?></td>
                            <td>
                                <?php if (($row['report_source'] ?? '') === 'Sales'): ?>
                                    <span class="badge badge-primary">Sales</span>
                                <?php else: ?>
                                    <span class="badge badge-success">Service</span>
                                <?php endif; ?>
                            </td>
                            <td><strong><?php echo h($row['invoice_no'] ?? '-'); ?></strong></td>
                            <td><?php echo !empty($row['invoice_date']) ? h(date('d M Y', strtotime($row['invoice_date']))) : '-'; ?></td>
                            <td><?php echo h($row['branch_name'] ?? '-'); ?></td>
                            <td><?php echo h($row['customer_name'] ?? '-'); ?></td>
                            <td><?php echo h(ucwords(str_replace('_', ' ', (string)($row['invoice_type'] ?? '-')))); ?></td>
                            <td>
                                <?php
                                $payBadge = 'badge-secondary';
                                if (($row['payment_status'] ?? '') === 'paid') {
                                    $payBadge = 'badge-success';
                                } elseif (($row['payment_status'] ?? '') === 'partial') {
                                    $payBadge = 'badge-warning';
                                } elseif (($row['payment_status'] ?? '') === 'unpaid') {
                                    $payBadge = 'badge-danger';
                                }
                                ?>
                                <span class="badge <?php echo $payBadge; ?>">
                                    <?php echo h(ucfirst((string)($row['payment_status'] ?? '-'))); ?>
                                </span>
                            </td>
                            <td><?php echo numf($row['taxable_value'] ?? 0); ?></td>
                            <td><?php echo numf($row['cgst_amount'] ?? 0); ?></td>
                            <td><?php echo numf($row['sgst_amount'] ?? 0); ?></td>
                            <td><?php echo numf($row['igst_amount'] ?? 0); ?></td>
                            <td><?php echo numf($row['cess_amount'] ?? 0); ?></td>
                            <td><strong><?php echo numf($row['grand_total'] ?? 0); ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                    
                    <!-- Total Row -->
                    <tr class="total-row">
                        <td colspan="8" style="text-align: right;"><strong>TOTAL</strong></td>
                        <td><strong><?php echo numf($totalTaxable); ?></strong></td>
                        <td><strong><?php echo numf($totalCgst); ?></strong></td>
                        <td><strong><?php echo numf($totalSgst); ?></strong></td>
                        <td><strong><?php echo numf($totalIgst); ?></strong></td>
                        <td><strong><?php echo numf($totalCess); ?></strong></td>
                        <td><strong><?php echo numf($totalGrand); ?></strong></td>
                    </tr>
                <?php else: ?>
                    <tr>
                        <td colspan="14" style="text-align: center; padding: 20px;">No GST records found for the selected criteria.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- Summary in Words -->
        <?php if (!empty($rows)): ?>
        <div style="margin: 20px 0; font-size: 12px;">
            <p><strong>Summary:</strong> Total Taxable Value: <?php echo money($totalTaxable); ?>, 
            Total Tax (CGST+SGST+IGST+CESS): <?php echo money($totalCgst + $totalSgst + $totalIgst + $totalCess); ?>, 
            Grand Total: <?php echo money($totalGrand); ?></p>
        </div>
        <?php endif; ?>

        <!-- Footer -->
        <div class="footer">
            <p>This is a computer generated GST report - valid without signature</p>
            <p>Generated by <?php echo h($loggedUser['full_name'] ?? 'System'); ?> on <?php echo date('d M Y h:i A'); ?></p>
            <p><?php echo h($businessDetails['business_name'] ?? ''); ?> - GST Report</p>
        </div>

        <!-- Print Button (only visible on screen, hidden when printing) -->
        <div class="no-print" style="text-align: center; margin: 30px 0;">
            <button onclick="window.print();" style="padding: 10px 30px; background: #007bff; color: white; border: none; border-radius: 5px; cursor: pointer; font-size: 16px;">
                <i class="ri-printer-line" style="margin-right: 5px;"></i> Print Report
            </button>
            <button onclick="window.close();" style="padding: 10px 30px; background: #6c757d; color: white; border: none; border-radius: 5px; cursor: pointer; font-size: 16px; margin-left: 10px;">
                <i class="ri-close-line" style="margin-right: 5px;"></i> Close
            </button>
        </div>
    </div>

    <!-- Auto-trigger print dialog if requested -->
    <script>
    <?php if (isset($_GET['auto_print']) && $_GET['auto_print'] === '1'): ?>
    window.onload = function() {
        setTimeout(function() {
            window.print();
        }, 500);
    };
    <?php endif; ?>
    </script>
</body>
</html>