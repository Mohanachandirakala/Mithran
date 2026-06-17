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
   FETCH INVOICE DETAILS
------------------------------------------------------- */
$invoiceId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($invoiceId <= 0) {
    header('Location: sales-invoices.php');
    exit;
}

// Fetch invoice header with customer and branch details
$stmt = $conn->prepare("
    SELECT si.*, 
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
           bu.full_name AS created_by_name
    FROM sales_invoices si
    LEFT JOIN branches br ON br.id = si.branch_id
    LEFT JOIN customers c ON c.id = si.customer_id
    LEFT JOIN business_users bu ON bu.id = si.created_by
    WHERE si.id = ? AND si.business_id = ?
    LIMIT 1
");
$stmt->bind_param("ii", $invoiceId, $businessId);
$stmt->execute();
$invoice = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$invoice) {
    header('Location: sales-invoices.php');
    exit;
}

// Fetch invoice items
$items = [];
$itemStmt = $conn->prepare("
    SELECT sii.*,
           p.product_name,
           p.product_code,
           vs.chassis_no,
           vs.engine_no,
           vs.motor_no,
           vs.color,
           vm.model_name,
           vm.variant_name,
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
$itemResult = $itemStmt->get_result();
while ($row = $itemResult->fetch_assoc()) {
    $items[] = $row;
}
$itemStmt->close();

$statusBadge = '';
$paymentStatusText = ucfirst($invoice['payment_status']);
if ($invoice['payment_status'] == 'paid') $statusBadge = 'success';
elseif ($invoice['payment_status'] == 'partial') $statusBadge = 'warning';
else $statusBadge = 'danger';

$saleStatusBadge = '';
$saleStatusText = ucfirst($invoice['sale_status']);
if ($invoice['sale_status'] == 'confirmed') $saleStatusBadge = 'success';
elseif ($invoice['sale_status'] == 'draft') $saleStatusBadge = 'warning';
elseif ($invoice['sale_status'] == 'cancelled') $saleStatusBadge = 'danger';
elseif ($invoice['sale_status'] == 'delivered') $saleStatusBadge = 'info';

$pageTitle = 'View Sales Invoice';
$currentPage = 'sales-invoices';
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
                        <h4 class="mb-1">Sales Invoice Details</h4>
                        <p class="text-muted mb-0">View sales invoice information</p>
                    </div>
                    <div class="col-md-6 text-md-end mt-3 mt-md-0">
                  
                        <a href="sales-invoice-print.php?id=<?php echo $invoiceId; ?>" class="btn btn-secondary me-2" target="_blank">Print</a>
                        <a href="sales-invoices.php" class="btn btn-light">Back to List</a>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-body">
                                <div class="text-center mb-4">
                                    <h4 class="mb-1"><?php echo h($invoice['invoice_no']); ?></h4>
                                    <span class="badge bg-<?php echo $statusBadge; ?> me-2" style="font-size: 12px; padding: 5px 10px;">
                                        Payment: <?php echo $paymentStatusText; ?>
                                    </span>
                                    <span class="badge bg-<?php echo $saleStatusBadge; ?>" style="font-size: 12px; padding: 5px 10px;">
                                        Status: <?php echo $saleStatusText; ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Invoice Information</h5>
                            </div>
                            <div class="card-body">
                                <table class="table table-bordered table-sm">
                                    <tr>
                                        <th style="width: 40%;">Invoice No</th>
                                        <td><strong><?php echo h($invoice['invoice_no']); ?></strong>\\
                                    \\
                                     <tr>
                                        <th>Invoice Date</th>
                                        <td><?php echo !empty($invoice['invoice_date']) ? date('d M Y, h:i A', strtotime($invoice['invoice_date'])) : '-'; ?></td>
                                     </tr>
                                     <tr>
                                        <th>Invoice Type</th>
                                        <td><?php echo ucwords(str_replace('_', ' ', h($invoice['invoice_type']))); ?></td>
                                     </tr>
                                     <tr>
                                        <th>Created By</th>
                                        <td><?php echo h($invoice['created_by_name'] ?: 'System'); ?></td>
                                     </tr>
                                     <tr>
                                        <th>Created Date</th>
                                        <td><?php echo !empty($invoice['created_at']) ? date('d M Y, h:i A', strtotime($invoice['created_at'])) : '-'; ?></td>
                                     </tr>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Branch Information</h5>
                            </div>
                            <div class="card-body">
                                <table class="table table-bordered table-sm">
                                     <tr>
                                        <th style="width: 40%;">Branch Name</th>
                                        <td><?php echo h($invoice['branch_name'] ?: '-'); ?></td>
                                     </tr>
                                     <tr>
                                        <th>Branch Code</th>
                                        <td><?php echo h($invoice['branch_code'] ?: '-'); ?></td>
                                     </tr>
                                    <?php if (!empty($invoice['branch_address1'])): ?>
                                     <tr>
                                        <th>Address</th>
                                        <td>
                                            <?php echo h($invoice['branch_address1']); ?>
                                            <?php echo !empty($invoice['branch_address2']) ? ', ' . h($invoice['branch_address2']) : ''; ?><br>
                                            <?php echo h($invoice['branch_city'] ?: ''); ?>
                                            <?php echo !empty($invoice['branch_state']) ? ', ' . h($invoice['branch_state']) : ''; ?>
                                            <?php echo !empty($invoice['branch_pincode']) ? ' - ' . h($invoice['branch_pincode']) : ''; ?>
                                        </td>
                                     </tr>
                                    <?php endif; ?>
                                    <?php if (!empty($invoice['branch_mobile'])): ?>
                                     <tr>
                                        <th>Phone</th>
                                        <td><?php echo h($invoice['branch_mobile']); ?></td>
                                     </tr>
                                    <?php endif; ?>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Customer Information</h5>
                            </div>
                            <div class="card-body">
                                <table class="table table-bordered table-sm">
                                     <tr>
                                        <th style="width: 20%;">Customer Name</th>
                                        <td style="width: 30%;"><strong><?php echo h($invoice['customer_name'] ?: '-'); ?></strong></td>
                                        <th style="width: 20%;">Mobile</th>
                                        <td style="width: 30%;"><?php echo h($invoice['customer_mobile'] ?: '-'); ?></td>
                                     </tr>
                                    <?php if (!empty($invoice['customer_alt_mobile'])): ?>
                                     <tr>
                                        <th>Alternate Mobile</th>
                                        <td><?php echo h($invoice['customer_alt_mobile']); ?></td>
                                        <th>Email</th>
                                        <td><?php echo h($invoice['customer_email'] ?: '-'); ?></td>
                                     </tr>
                                    <?php endif; ?>
                                    <?php if (!empty($invoice['customer_address1']) || !empty($invoice['customer_city'])): ?>
                                     <tr>
                                        <th>Address</th>
                                        <td colspan="3">
                                            <?php echo h($invoice['customer_address1'] ?: ''); ?>
                                            <?php echo !empty($invoice['customer_address2']) ? ', ' . h($invoice['customer_address2']) : ''; ?><br>
                                            <?php echo h($invoice['customer_city'] ?: ''); ?>
                                            <?php echo !empty($invoice['customer_district']) ? ', ' . h($invoice['customer_district']) : ''; ?><br>
                                            <?php echo h($invoice['customer_state'] ?: ''); ?>
                                            <?php echo !empty($invoice['customer_pincode']) ? ' - ' . h($invoice['customer_pincode']) : ''; ?>
                                        </td>
                                     </tr>
                                    <?php endif; ?>
                                    <?php if (!empty($invoice['customer_gstin'])): ?>
                                     <tr>
                                        <th>GSTIN</th>
                                        <td colspan="3"><?php echo h($invoice['customer_gstin']); ?></td>
                                     </tr>
                                    <?php endif; ?>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Invoice Items</h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-bordered table-sm">
                                        <thead>
                                             <tr>
                                                <th width="5%">#</th>
                                                <th width="35%">Description</th>
                                                <th width="8%">Qty</th>
                                                <th width="15%">Unit Price</th>
                                                <th width="12%">Discount</th>
                                                <th width="12%">Taxable</th>
                                                <th width="13%">Total</th>
                                             </tr>
                                        </thead>
                                        <tbody>
                                            <?php $i = 1; foreach ($items as $item): ?>
                                             <tr>
                                                <td class="text-center"><?php echo $i++; ?></td>
                                                <td>
                                                    <?php echo h($item['description']); ?>
                                                    <?php if ($item['item_type'] == 'vehicle' && !empty($item['chassis_no'])): ?>
                                                        <div class="small text-muted">Chassis: <?php echo h($item['chassis_no']); ?></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center"><?php echo number_format($item['qty'], 2); ?></td>
                                                <td class="text-right"><?php echo money($item['unit_price']); ?></td>
                                                <td class="text-right"><?php echo money($item['discount_amount']); ?></td>
                                                <td class="text-right"><?php echo money($item['taxable_value']); ?></td>
                                                <td class="text-right"><?php echo money($item['line_total']); ?></td>
                                             </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Tax Summary</h5>
                            </div>
                            <div class="card-body">
                                <table class="table table-bordered table-sm">
                                     <tr>
                                        <th style="width: 50%;">Subtotal</th>
                                        <td class="text-right"><?php echo money($invoice['subtotal']); ?></td>
                                     </tr>
                                     <tr>
                                        <th>Discount</th>
                                        <td class="text-right text-danger">-<?php echo money($invoice['discount_amount']); ?></td>
                                     </tr>
                                     <tr>
                                        <th>CGST</th>
                                        <td class="text-right"><?php echo money($invoice['cgst_amount']); ?></td>
                                     </tr>
                                     <tr>
                                        <th>SGST</th>
                                        <td class="text-right"><?php echo money($invoice['sgst_amount']); ?></td>
                                     </tr>
                                     <tr>
                                        <th>IGST</th>
                                        <td class="text-right"><?php echo money($invoice['igst_amount']); ?></td>
                                     </tr>
                                     <tr>
                                        <th>Cess</th>
                                        <td class="text-right"><?php echo money($invoice['cess_amount']); ?></td>
                                     </tr>
                                     <tr>
                                        <th>Round Off</th>
                                        <td class="text-right"><?php echo money($invoice['round_off']); ?></td>
                                     </tr>
                                     <tr class="table-active">
                                        <th><strong>Grand Total</strong></th>
                                        <td class="text-right"><strong><?php echo money($invoice['grand_total']); ?></strong></td>
                                     </tr>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Payment Summary</h5>
                            </div>
                            <div class="card-body">
                                <table class="table table-bordered table-sm">
                                     <tr>
                                        <th style="width: 50%;">Grand Total</th>
                                        <td class="text-right"><?php echo money($invoice['grand_total']); ?></td>
                                     </tr>
                                     <tr>
                                        <th>Paid Amount</th>
                                        <td class="text-right text-success"><?php echo money($invoice['paid_amount']); ?></td>
                                     </tr>
                                     <tr class="table-danger">
                                        <th><strong>Balance Amount</strong></th>
                                        <td class="text-right"><strong><?php echo money($invoice['balance_amount']); ?></strong></td>
                                     </tr>
                                     <tr>
                                        <th>Payment Status</th>
                                        <td class="text-right">
                                            <span class="badge bg-<?php echo $statusBadge; ?>">
                                                <?php echo $paymentStatusText; ?>
                                            </span>
                                        </td>
                                     </tr>
                                     <tr>
                                        <th>Sale Status</th>
                                        <td class="text-right">
                                            <span class="badge bg-<?php echo $saleStatusBadge; ?>">
                                                <?php echo $saleStatusText; ?>
                                            </span>
                                        </td>
                                     </tr>
                                </table>
                            </div>
                        </div>

                        <?php if (!empty($invoice['customer_note'])): ?>
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Customer Note</h5>
                            </div>
                            <div class="card-body">
                                <p class="mb-0"><?php echo nl2br(h($invoice['customer_note'])); ?></p>
                            </div>
                        </div>
                        <?php endif; ?>
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