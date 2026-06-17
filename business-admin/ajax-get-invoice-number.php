<?php
session_start();
require_once 'includes/config.php';

header('Content-Type: application/json');

if (!isset($conn) || !($conn instanceof mysqli)) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$businessId = isset($_GET['business_id']) ? (int)$_GET['business_id'] : 0;
$branchId = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : 0;

if ($businessId <= 0 || $branchId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
    exit;
}

// Function to generate invoice number (same as in main file)
function generateInvoiceNo($conn, $businessId, $branchId) {
    $prefix = 'INV';
    
    // Get prefix from business settings
    $stmt = $conn->prepare("SELECT invoice_prefix FROM business_settings WHERE business_id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $businessId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!empty($row['invoice_prefix'])) {
            $prefix = trim($row['invoice_prefix']);
        }
    }
    
    $today = date('Ymd');
    $pattern = $prefix . '-' . $today . '-%';
    
    $stmt = $conn->prepare("SELECT invoice_no FROM sales_invoices WHERE business_id = ? AND branch_id = ? AND invoice_no LIKE ? ORDER BY id DESC LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('iis', $businessId, $branchId, $pattern);
        $stmt->execute();
        $result = $stmt->get_result();
        $lastInvoice = $result->fetch_assoc();
        $stmt->close();
        
        if ($lastInvoice && isset($lastInvoice['invoice_no'])) {
            $parts = explode('-', $lastInvoice['invoice_no']);
            $lastSeq = end($parts);
            $nextSeq = (int)$lastSeq + 1;
            return $prefix . '-' . $today . '-' . str_pad($nextSeq, 4, '0', STR_PAD_LEFT);
        }
    }
    
    return $prefix . '-' . $today . '-0001';
}

$invoiceNo = generateInvoiceNo($conn, $businessId, $branchId);
echo json_encode(['success' => true, 'invoice_no' => $invoiceNo]);
?>