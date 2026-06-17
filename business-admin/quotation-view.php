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

function qtyf($qty): string
{
    $qty = (float)$qty;
    if ((int)$qty == $qty) {
        return number_format($qty, 0);
    }
    return number_format($qty, 2);
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
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $exists;
}

/* -------------------------------------------------------
   GET QUOTATION ID
------------------------------------------------------- */
$quotationId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($quotationId <= 0) {
    header('Location: quotations.php');
    exit;
}

/* -------------------------------------------------------
   VALIDATE USER
------------------------------------------------------- */
$loggedUser = null;
if (tableExists($conn, 'business_users') && tableExists($conn, 'businesses')) {
    $stmt = $conn->prepare("SELECT
                                bu.id, bu.full_name, bu.role, bu.status,
                                b.business_name, b.status AS business_status
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
   FETCH QUOTATION DETAILS
------------------------------------------------------- */
$quotation = null;
$items = [];

if (tableExists($conn, 'quotations') && tableExists($conn, 'quotation_items')) {
    // Fetch quotation header
    $sql = "SELECT 
                q.*,
                b.branch_name,
                b.branch_code,
                c.full_name as customer_name,
                c.mobile as customer_mobile,
                c.email as customer_email,
                c.address_line1,
                c.address_line2,
                c.city,
                c.district,
                c.state,
                c.pincode,
                c.gstin as customer_gstin
            FROM quotations q
            LEFT JOIN branches b ON b.id = q.branch_id
            LEFT JOIN customers c ON c.id = q.customer_id
            WHERE q.id = ? AND q.business_id = ?
            LIMIT 1";
    
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('ii', $quotationId, $businessId);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $quotation = $row;
        }
        $stmt->close();
    }
    
    // Fetch quotation items
    if ($quotation) {
        $itemSql = "SELECT 
                        qi.*,
                        CASE 
                            WHEN qi.item_type = 'product' THEN p.product_name
                            WHEN qi.item_type = 'vehicle' THEN CONCAT(vb.brand_name, ' ', vm.model_name, IFNULL(CONCAT(' - ', vm.variant_name), ''))
                            ELSE qi.description
                        END as item_name,
                        p.product_code,
                        p.item_code,
                        vb.brand_name as vehicle_brand,
                        vm.model_name as vehicle_model,
                        vm.variant_name as vehicle_variant,
                        vs.color as vehicle_color,
                        vs.chassis_no as vehicle_chassis
                    FROM quotation_items qi
                    LEFT JOIN products p ON p.id = qi.product_id AND qi.item_type = 'product'
                    LEFT JOIN vehicle_stock vs ON vs.id = qi.vehicle_stock_id AND qi.item_type = 'vehicle'
                    LEFT JOIN vehicle_models vm ON vm.id = vs.model_id
                    LEFT JOIN vehicle_brands vb ON vb.id = vm.brand_id
                    WHERE qi.quotation_id = ?
                    ORDER BY qi.id ASC";
        
        $stmt = $conn->prepare($itemSql);
        if ($stmt) {
            $stmt->bind_param('i', $quotationId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $items[] = $row;
            }
            $stmt->close();
        }
    }
}

// If quotation not found, redirect
if (!$quotation) {
    header('Location: quotations.php');
    exit;
}

// Get business settings for invoice display
$businessSettings = [];
if (tableExists($conn, 'business_settings')) {
    $sql = "SELECT * FROM business_settings WHERE business_id = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('i', $businessId);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $businessSettings = $row;
        }
        $stmt->close();
    }
}

// Get business details
$businessDetails = [];
if (tableExists($conn, 'businesses')) {
    $sql = "SELECT * FROM businesses WHERE id = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('i', $businessId);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $businessDetails = $row;
        }
        $stmt->close();
    }
}

$pageTitle = 'View Quotation - ' . h($quotation['quotation_no']);
$currentPage = 'quotations';
?>
<!doctype html>
<html lang="en">

<?php include('includes/head.php'); ?>

<body data-sidebar="dark">

<style>
@media print {
    .no-print {
        display: none !important;
    }
    .card {
        box-shadow: none !important;
        border: 1px solid #ddd !important;
    }
    .main-content {
        margin: 0 !important;
        padding: 0 !important;
    }
    .container-fluid {
        padding: 0 !important;
    }
}
.status-badge {
    padding: 5px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 500;
}
.status-draft { background: #e2e3e5; color: #383d41; }
.status-sent { background: #d1ecf1; color: #0c5460; }
.status-approved { background: #d4edda; color: #155724; }
.status-rejected { background: #f8d7da; color: #721c24; }
.status-converted { background: #d4edda; color: #155724; }
.status-expired { background: #f8d7da; color: #721c24; }
</style>

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
                        <h4 class="mb-1">Quotation Details</h4>
                        <p class="text-muted mb-0">View quotation information</p>
                    </div>
                    <div class="col-md-6 text-md-end mt-3 mt-md-0 no-print">
                        <div class="btn-group">
                            <a href="quotation-print.php?id=<?php echo $quotationId; ?>" class="btn btn-secondary" target="_blank">
                                <i class="mdi mdi-printer"></i> Print
                            </a>
                            <?php if ($quotation['status'] == 'draft'): ?>
                                <a href="quotation-edit.php?id=<?php echo $quotationId; ?>" class="btn btn-warning">
                                    <i class="mdi mdi-pencil"></i> Edit
                                </a>
                            <?php endif; ?>
                            <?php if ($quotation['status'] != 'converted' && $quotation['status'] != 'rejected'): ?>
                                <a href="sales-invoice-add.php?source_type=quotation&source_id=<?php echo $quotationId; ?>" class="btn btn-success">
                                    <i class="mdi mdi-file-document"></i> Convert to Invoice
                                </a>
                            <?php endif; ?>
                            <a href="quotations.php" class="btn btn-primary">
                                <i class="mdi mdi-arrow-left"></i> Back to List
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Quotation Header -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-sm-6">
                                        <div class="mb-4">
                                            <h5 class="mb-3"><?php echo h($businessDetails['business_name'] ?? 'Your Business'); ?></h5>
                                            <?php if (!empty($businessDetails['address_line1'])): ?>
                                                <p class="mb-1"><?php echo h($businessDetails['address_line1']); ?></p>
                                            <?php endif; ?>
                                            <?php if (!empty($businessDetails['address_line2'])): ?>
                                                <p class="mb-1"><?php echo h($businessDetails['address_line2']); ?></p>
                                            <?php endif; ?>
                                            <?php if (!empty($businessDetails['city']) || !empty($businessDetails['state'])): ?>
                                                <p class="mb-1"><?php echo h($businessDetails['city'] ?? '') . (($businessDetails['city'] && $businessDetails['state']) ? ', ' : '') . h($businessDetails['state'] ?? ''); ?></p>
                                            <?php endif; ?>
                                            <?php if (!empty($businessDetails['pincode'])): ?>
                                                <p class="mb-1">Pincode: <?php echo h($businessDetails['pincode']); ?></p>
                                            <?php endif; ?>
                                            <?php if (!empty($businessDetails['gstin'])): ?>
                                                <p class="mb-1">GSTIN: <?php echo h($businessDetails['gstin']); ?></p>
                                            <?php endif; ?>
                                            <?php if (!empty($businessDetails['email'])): ?>
                                                <p class="mb-1">Email: <?php echo h($businessDetails['email']); ?></p>
                                            <?php endif; ?>
                                            <?php if (!empty($businessDetails['mobile'])): ?>
                                                <p class="mb-1">Mobile: <?php echo h($businessDetails['mobile']); ?></p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="col-sm-6 text-sm-end">
                                        <div class="mb-4">
                                            <h5 class="mb-3">Quotation Details</h5>
                                            <p class="mb-1"><strong>Quotation No:</strong> <?php echo h($quotation['quotation_no']); ?></p>
                                            <p class="mb-1"><strong>Date:</strong> <?php echo date('d-m-Y', strtotime($quotation['quotation_date'])); ?></p>
                                            <?php if (!empty($quotation['valid_until'])): ?>
                                                <p class="mb-1"><strong>Valid Until:</strong> <?php echo date('d-m-Y', strtotime($quotation['valid_until'])); ?></p>
                                            <?php endif; ?>
                                            <p class="mb-1"><strong>Status:</strong> 
                                                <span class="status-badge status-<?php echo $quotation['status']; ?>">
                                                    <?php echo ucfirst($quotation['status']); ?>
                                                </span>
                                            </p>
                                            <p class="mb-1"><strong>Branch:</strong> <?php echo h($quotation['branch_name'] ?? '-'); ?></p>
                                        </div>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-12">
                                        <div class="mb-4">
                                            <h5 class="mb-3">Customer Details</h5>
                                            <p class="mb-1"><strong>Name:</strong> <?php echo h($quotation['customer_name'] ?? '-'); ?></p>
                                            <?php if (!empty($quotation['customer_mobile'])): ?>
                                                <p class="mb-1"><strong>Mobile:</strong> <?php echo h($quotation['customer_mobile']); ?></p>
                                            <?php endif; ?>
                                            <?php if (!empty($quotation['customer_email'])): ?>
                                                <p class="mb-1"><strong>Email:</strong> <?php echo h($quotation['customer_email']); ?></p>
                                            <?php endif; ?>
                                            <?php if (!empty($quotation['address_line1']) || !empty($quotation['address_line2'])): ?>
                                                <p class="mb-1"><strong>Address:</strong> <?php echo h($quotation['address_line1'] ?? '') . ' ' . h($quotation['address_line2'] ?? ''); ?></p>
                                            <?php endif; ?>
                                            <?php if (!empty($quotation['city']) || !empty($quotation['state'])): ?>
                                                <p class="mb-1"><?php echo h($quotation['city'] ?? '') . (($quotation['city'] && $quotation['state']) ? ', ' : '') . h($quotation['state'] ?? ''); ?></p>
                                            <?php endif; ?>
                                            <?php if (!empty($quotation['customer_gstin'])): ?>
                                                <p class="mb-1"><strong>GSTIN:</strong> <?php echo h($quotation['customer_gstin']); ?></p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quotation Items -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Quotation Items</h4>

                                <div class="table-responsive">
                                    <table class="table table-bordered">
                                        <thead class="table-light">
                                            <tr>
                                                <th>#</th>
                                                <th>Item</th>
                                                <th>Description</th>
                                                <th>Qty</th>
                                                <th>Unit Price (₹)</th>
                                                <th>Discount (₹)</th>
                                                <th>Taxable Value (₹)</th>
                                                <th>CGST (%)</th>
                                                <th>SGST (%)</th>
                                                <th>CGST (₹)</th>
                                                <th>SGST (₹)</th>
                                                <th>Total (₹)</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($items)): ?>
                                                <?php $sno = 1; foreach ($items as $item): ?>
                                                    <tr>
                                                        <td><?php echo $sno++; ?></div>
                                                        <td>
                                                            <strong><?php echo h($item['item_name'] ?? $item['description']); ?></strong>
                                                            <?php if ($item['item_type'] == 'product' && !empty($item['product_code'])): ?>
                                                                <br><small class="text-muted">Code: <?php echo h($item['product_code']); ?></small>
                                                            <?php endif; ?>
                                                            <?php if ($item['item_type'] == 'vehicle' && !empty($item['vehicle_chassis'])): ?>
                                                                <br><small class="text-muted">Chassis: <?php echo h($item['vehicle_chassis']); ?></small>
                                                            <?php endif; ?>
                                                        </div>
                                                        <td><?php echo h($item['description'] ?: '-'); ?></div>
                                                        <td><?php echo qtyf($item['qty']); ?></div>
                                                        <td><?php echo money($item['unit_price']); ?></div>
                                                        <td><?php echo money($item['discount_amount']); ?></div>
                                                        <td><?php echo money($item['taxable_value']); ?></div>
                                                        <td><?php echo number_format($item['cgst_percent'], 2); ?>%</div>
                                                        <td><?php echo number_format($item['sgst_percent'], 2); ?>%</div>
                                                        <td><?php echo money($item['cgst_amount']); ?></div>
                                                        <td><?php echo money($item['sgst_amount']); ?></div>
                                                        <td><strong><?php echo money($item['line_total']); ?></strong></div>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="12" class="text-center text-muted">No items found.</div>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                        <tfoot class="table-light">
                                            <tr>
                                                <th colspan="11" class="text-end">Subtotal:</th>
                                                <th><?php echo money($quotation['subtotal']); ?></th>
                                              </tr>
                                            <tr>
                                                <th colspan="11" class="text-end">Discount:</th>
                                                <th><?php echo money($quotation['discount_amount']); ?></th>
                                              </tr>
                                            <tr>
                                                <th colspan="11" class="text-end">CGST:</th>
                                                <th><?php echo money($quotation['cgst_amount']); ?></th>
                                              </tr>
                                            <tr>
                                                <th colspan="11" class="text-end">SGST:</th>
                                                <th><?php echo money($quotation['sgst_amount']); ?></th>
                                              </tr>
                                            <?php if ($quotation['igst_amount'] > 0): ?>
                                            <tr>
                                                <th colspan="11" class="text-end">IGST:</th>
                                                <th><?php echo money($quotation['igst_amount']); ?></th>
                                              </tr>
                                            <?php endif; ?>
                                            <?php if ($quotation['cess_amount'] > 0): ?>
                                            <tr>
                                                <th colspan="11" class="text-end">Cess:</th>
                                                <th><?php echo money($quotation['cess_amount']); ?></th>
                                              </tr>
                                            <?php endif; ?>
                                            <?php if ($quotation['round_off'] != 0): ?>
                                            <tr>
                                                <th colspan="11" class="text-end">Round Off:</th>
                                                <th><?php echo money($quotation['round_off']); ?></th>
                                              </tr>
                                            <?php endif; ?>
                                            <tr class="table-active">
                                                <th colspan="11" class="text-end">Grand Total:</th>
                                                <th><strong><?php echo money($quotation['grand_total']); ?></strong></th>
                                              </tr>
                                        </tfoot>
                                    </table>
                                </div>

                                <?php if (!empty($quotation['customer_note']) || !empty($quotation['terms_conditions'])): ?>
                                    <div class="row mt-4">
                                        <?php if (!empty($quotation['customer_note'])): ?>
                                            <div class="col-md-6">
                                                <h6 class="fw-bold">Customer Note:</h6>
                                                <p class="text-muted"><?php echo nl2br(h($quotation['customer_note'])); ?></p>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($quotation['terms_conditions'])): ?>
                                            <div class="col-md-6">
                                                <h6 class="fw-bold">Terms & Conditions:</h6>
                                                <p class="text-muted"><?php echo nl2br(h($quotation['terms_conditions'])); ?></p>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Action Buttons Footer -->
                <div class="row no-print">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body text-center">
                                <?php if ($quotation['status'] == 'draft'): ?>
                                    <a href="quotation-edit.php?id=<?php echo $quotationId; ?>" class="btn btn-warning">
                                        <i class="mdi mdi-pencil"></i> Edit Quotation
                                    </a>
                                    <a href="quotation-status-update.php?id=<?php echo $quotationId; ?>&status=sent" class="btn btn-info" onclick="return confirm('Send this quotation to customer?');">
                                        <i class="mdi mdi-email-send"></i> Send to Customer
                                    </a>
                                <?php endif; ?>
                                
                                <?php if ($quotation['status'] != 'converted' && $quotation['status'] != 'rejected' && $quotation['status'] != 'expired'): ?>
                                    <a href="sales-invoice-add.php?source_type=quotation&source_id=<?php echo $quotationId; ?>" class="btn btn-success">
                                        <i class="mdi mdi-file-document"></i> Convert to Invoice
                                    </a>
                                <?php endif; ?>
                                
                                <?php if ($quotation['status'] == 'sent'): ?>
                                    <a href="quotation-status-update.php?id=<?php echo $quotationId; ?>&status=approved" class="btn btn-success" onclick="return confirm('Approve this quotation?');">
                                        <i class="mdi mdi-check-circle"></i> Approve
                                    </a>
                                    <a href="quotation-status-update.php?id=<?php echo $quotationId; ?>&status=rejected" class="btn btn-danger" onclick="return confirm('Reject this quotation?');">
                                        <i class="mdi mdi-close-circle"></i> Reject
                                    </a>
                                <?php endif; ?>
                                
                                <a href="quotation-print.php?id=<?php echo $quotationId; ?>" class="btn btn-secondary" target="_blank">
                                    <i class="mdi mdi-printer"></i> Print Quotation
                                </a>
                                
                                <a href="quotations.php" class="btn btn-primary">
                                    <i class="mdi mdi-arrow-left"></i> Back to List
                                </a>
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