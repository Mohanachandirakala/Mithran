<?php
session_start();
date_default_timezone_set('Asia/Kolkata');

require_once 'includes/config.php';

/*
|--------------------------------------------------------------------------
| Business Admin Notifications - notifications.php
|--------------------------------------------------------------------------
| This page displays:
| - System notifications
| - Low stock alerts
| - Pending job cards
| - Recent activities
| - Birthday/Follow-up reminders
| - Subscription/Plan alerts
|--------------------------------------------------------------------------
*/

if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not available.");
}
$conn->set_charset("utf8mb4");

/* -------------------------------------------------------
   AUTH CHECK
------------------------------------------------------- */
$businessUserId = isset($_SESSION['business_user_id']) ? (int)$_SESSION['business_user_id'] : 0;
if ($businessUserId <= 0 && isset($_SESSION['user_id'])) {
    $businessUserId = (int)$_SESSION['user_id'];
}

$businessId = isset($_SESSION['business_id']) ? (int)$_SESSION['business_id'] : 0;
$branchId   = isset($_SESSION['branch_id']) ? (int)$_SESSION['branch_id'] : 0;

if ($businessUserId <= 0 || $businessId <= 0) {
    header("Location: login.php");
    exit;
}

/* -------------------------------------------------------
   HELPERS
------------------------------------------------------- */
function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function money($amount): string
{
    return '₹' . number_format((float)$amount, 2);
}

function timeAgo($datetime): string
{
    $time = strtotime($datetime);
    $now = time();
    $diff = $now - $time;

    if ($diff < 60) {
        return $diff . ' seconds ago';
    } elseif ($diff < 3600) {
        $mins = floor($diff / 60);
        return $mins . ' minute' . ($mins > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 604800) {
        $days = floor($diff / 86400);
        return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
    } else {
        return date('d M Y', $time);
    }
}

function tableExists(mysqli $conn, string $table): bool
{
    $sql = "SELECT 1
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return false;

    $stmt->bind_param("s", $table);
    $stmt->execute();
    $res = $stmt->get_result();
    $exists = $res && $res->num_rows > 0;
    $stmt->close();

    return $exists;
}

/* -------------------------------------------------------
   FETCH LOGGED-IN BUSINESS USER
------------------------------------------------------- */
$admin = null;
if (tableExists($conn, 'business_users') && tableExists($conn, 'businesses')) {
    $sql = "SELECT 
                bu.id,
                bu.business_id,
                bu.branch_id,
                bu.full_name,
                bu.username,
                bu.email,
                bu.mobile,
                bu.role,
                bu.status,
                b.business_name,
                b.business_code,
                b.gstin,
                b.status AS business_status
            FROM business_users bu
            INNER JOIN businesses b ON b.id = bu.business_id
            WHERE bu.id = ? AND bu.business_id = ?
            LIMIT 1";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("ii", $businessUserId, $businessId);
        $stmt->execute();
        $admin = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}

if (!$admin || (int)$admin['status'] !== 1 || ($admin['business_status'] ?? '') !== 'active') {
    session_destroy();
    header("Location: login.php");
    exit;
}

/* -------------------------------------------------------
   COLLECT ALL NOTIFICATIONS (RAW DATA)
------------------------------------------------------- */
$allNotifications = [];

// Helper function to get product stock from product_stock table
function getProductStockQty($conn, $businessId, $productId) {
    $sql = "SELECT COALESCE(SUM(qty_available), 0) as total_qty 
            FROM product_stock 
            WHERE business_id = ? AND product_id = ?";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("ii", $businessId, $productId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        return (float)($row['total_qty'] ?? 0);
    }
    return 0;
}

// 1. LOW STOCK ALERTS (using product_stock table)
if (tableExists($conn, 'products') && tableExists($conn, 'product_stock')) {
    $sql = "SELECT 
                p.id,
                p.product_name,
                p.product_code,
                p.min_stock_qty,
                p.unit,
                p.brand_name,
                p.category_id,
                (SELECT COALESCE(SUM(ps.qty_available), 0) 
                 FROM product_stock ps 
                 WHERE ps.product_id = p.id AND ps.business_id = p.business_id) as total_stock_qty
            FROM products p
            WHERE p.business_id = {$businessId}
            AND p.status = 1
            HAVING total_stock_qty <= p.min_stock_qty
            ORDER BY (total_stock_qty / p.min_stock_qty) ASC
            LIMIT 20";
    
    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $stockQty = (float)$row['total_stock_qty'];
            $minQty = (float)$row['min_stock_qty'];
            $percentage = $minQty > 0 ? round(($stockQty / $minQty) * 100) : 0;
            
            $allNotifications[] = [
                'id' => 'stock_' . $row['id'],
                'type' => 'stock',
                'title' => 'Low Stock Alert',
                'message' => $row['product_name'] . ' (' . ($row['product_code'] ?: 'No Code') . ') has only ' . 
                            number_format($stockQty, 2) . ' ' . ($row['unit'] ?: 'pcs') . ' left. ' .
                            'Minimum required: ' . number_format($minQty, 2) . ' ' . ($row['unit'] ?: 'pcs'),
                'priority' => $percentage <= 25 ? 'high' : ($percentage <= 50 ? 'medium' : 'low'),
                'link' => 'product-edit.php?id=' . $row['id'],
                'link_text' => 'Reorder Now',
                'created_at' => date('Y-m-d H:i:s'),
                'icon' => 'ri-stack-line',
                'badge' => 'warning'
            ];
        }
    }
}

// 2. OPEN/IN-PROGRESS JOB CARDS
if (tableExists($conn, 'service_job_cards') && tableExists($conn, 'customers')) {
    $sql = "SELECT 
                jc.id,
                jc.jobcard_no,
                jc.service_date,
                jc.job_status,
                jc.promised_delivery,
                jc.estimated_amount,
                c.full_name AS customer_name,
                c.mobile
            FROM service_job_cards jc
            INNER JOIN customers c ON c.id = jc.customer_id
            WHERE jc.business_id = {$businessId}
            AND jc.job_status IN ('open', 'in_progress', 'waiting_parts', 'ready')
            ORDER BY 
                CASE jc.job_status
                    WHEN 'ready' THEN 1
                    WHEN 'waiting_parts' THEN 2
                    WHEN 'in_progress' THEN 3
                    WHEN 'open' THEN 4
                END,
                jc.promised_delivery ASC
            LIMIT 15";
    
    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $statusText = str_replace('_', ' ', $row['job_status']);
            $statusText = ucwords($statusText);
            
            $promisedText = '';
            $priority = 'medium';
            
            if (!empty($row['promised_delivery'])) {
                $promised = strtotime($row['promised_delivery']);
                $now = time();
                
                if ($promised < $now) {
                    $promisedText = ' (Overdue)';
                    $priority = 'high';
                } elseif ($promised <= strtotime('+2 hours')) {
                    $promisedText = ' (Due soon)';
                    $priority = 'high';
                } elseif ($promised <= strtotime('+24 hours')) {
                    $promisedText = ' (Due today)';
                    $priority = 'medium';
                }
            }
            
            $allNotifications[] = [
                'id' => 'job_' . $row['id'],
                'type' => 'job',
                'title' => 'Pending Job Card',
                'message' => $row['customer_name'] . ' | Job #' . $row['jobcard_no'] . 
                            ' | Status: ' . $statusText . $promisedText,
                'priority' => $priority,
                'link' => 'service-jobcard-view.php?id=' . $row['id'],
                'link_text' => 'View Job Card',
                'created_at' => $row['service_date'],
                'icon' => 'ri-tools-line',
                'badge' => $row['job_status'] == 'ready' ? 'success' : 
                          ($row['job_status'] == 'waiting_parts' ? 'warning' : 'info')
            ];
        }
    }
}

// 3. OPEN PRE-BOOKINGS
if (tableExists($conn, 'pre_bookings') && tableExists($conn, 'customers')) {
    $sql = "SELECT 
                pb.id,
                pb.booking_no,
                pb.booking_date,
                pb.booking_amount,
                pb.expected_delivery_date,
                pb.status,
                c.full_name AS customer_name,
                c.mobile
            FROM pre_bookings pb
            INNER JOIN customers c ON c.id = pb.customer_id
            WHERE pb.business_id = {$businessId}
            AND pb.status IN ('open', 'confirmed')
            ORDER BY 
                CASE WHEN pb.expected_delivery_date IS NOT NULL THEN 1 ELSE 2 END,
                pb.expected_delivery_date ASC,
                pb.booking_date DESC
            LIMIT 15";
    
    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $priority = 'medium';
            $message = $row['customer_name'] . ' - Booking #' . $row['booking_no'];
            
            if (!empty($row['expected_delivery_date'])) {
                $expected = strtotime($row['expected_delivery_date']);
                $today = strtotime(date('Y-m-d'));
                
                if ($expected < $today) {
                    $message .= ' (Delivery overdue)';
                    $priority = 'high';
                } elseif ($expected == $today) {
                    $message .= ' (Delivery today)';
                    $priority = 'high';
                } elseif ($expected <= strtotime('+3 days')) {
                    $message .= ' (Delivery soon)';
                    $priority = 'medium';
                }
            }
            
            $allNotifications[] = [
                'id' => 'booking_' . $row['id'],
                'type' => 'booking',
                'title' => 'Active Booking',
                'message' => $message,
                'priority' => $priority,
                'link' => 'pre-booking-view.php?id=' . $row['id'],
                'link_text' => 'View Booking',
                'created_at' => $row['booking_date'],
                'icon' => 'ri-calendar-check-line',
                'badge' => 'primary'
            ];
        }
    }
}

// 4. UNPAID/OVERDUE INVOICES
if (tableExists($conn, 'sales_invoices') && tableExists($conn, 'customers')) {
    $sql = "SELECT 
                si.id,
                si.invoice_no,
                si.invoice_date,
                si.grand_total,
                si.paid_amount,
                si.payment_status,
                c.full_name AS customer_name,
                c.mobile
            FROM sales_invoices si
            INNER JOIN customers c ON c.id = si.customer_id
            WHERE si.business_id = {$businessId}
            AND si.payment_status IN ('unpaid', 'partial')
            AND si.sale_status = 'confirmed'
            AND DATEDIFF(CURDATE(), DATE(si.invoice_date)) >= 7
            ORDER BY si.invoice_date ASC
            LIMIT 15";
    
    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $dueAmount = $row['grand_total'] - $row['paid_amount'];
            $daysOverdue = floor((time() - strtotime($row['invoice_date'])) / (60 * 60 * 24)) - 7;
            $daysOverdue = max(0, $daysOverdue);
            
            $priority = $daysOverdue > 15 ? 'high' : ($daysOverdue > 7 ? 'medium' : 'low');
            
            $allNotifications[] = [
                'id' => 'invoice_' . $row['id'],
                'type' => 'invoice',
                'title' => 'Payment Overdue',
                'message' => $row['customer_name'] . ' - Due: ' . money($dueAmount) . 
                            ' (' . $daysOverdue . ' days overdue)',
                'priority' => $priority,
                'link' => 'sales-invoice-view.php?id=' . $row['id'],
                'link_text' => 'Collect Payment',
                'created_at' => $row['invoice_date'],
                'icon' => 'ri-bank-card-line',
                'badge' => 'danger'
            ];
        }
    }
}

// 5. RECENT AUDIT LOGS (last 24 hours)
if (tableExists($conn, 'audit_logs')) {
    $yesterday = date('Y-m-d H:i:s', strtotime('-24 hours'));
    
    $sql = "SELECT 
                action,
                module_name,
                description,
                created_at
            FROM audit_logs
            WHERE business_id = {$businessId}
            AND created_at >= '{$yesterday}'
            ORDER BY id DESC
            LIMIT 10";
    
    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $allNotifications[] = [
                'id' => 'audit_' . uniqid(),
                'type' => 'audit',
                'title' => $row['action'],
                'message' => ($row['module_name'] ?: 'System') . ': ' . ($row['description'] ?? 'No description'),
                'priority' => 'low',
                'link' => '#',
                'link_text' => 'View Details',
                'created_at' => $row['created_at'],
                'icon' => 'ri-history-line',
                'badge' => 'secondary'
            ];
        }
    }
}

// 6. NEW CUSTOMERS (last 7 days)
if (tableExists($conn, 'customers')) {
    $sevenDaysAgo = date('Y-m-d', strtotime('-7 days'));
    
    $sql = "SELECT id, full_name, mobile, city, created_at
            FROM customers
            WHERE business_id = {$businessId}
            AND DATE(created_at) >= '{$sevenDaysAgo}'
            ORDER BY id DESC
            LIMIT 10";
    
    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $allNotifications[] = [
                'id' => 'new_cust_' . $row['id'],
                'type' => 'customer',
                'title' => 'New Customer Registered',
                'message' => $row['full_name'] . ' (' . $row['mobile'] . ') from ' . ($row['city'] ?? 'Unknown location'),
                'priority' => 'low',
                'link' => 'customer-view.php?id=' . $row['id'],
                'link_text' => 'View Profile',
                'created_at' => $row['created_at'],
                'icon' => 'ri-user-add-line',
                'badge' => 'success'
            ];
        }
    }
}

// Sort all notifications by priority and date
usort($allNotifications, function($a, $b) {
    $priorityOrder = ['high' => 1, 'medium' => 2, 'low' => 3];
    $aPriority = $priorityOrder[$a['priority']] ?? 4;
    $bPriority = $priorityOrder[$b['priority']] ?? 4;
    
    if ($aPriority !== $bPriority) {
        return $aPriority - $bPriority;
    }
    
    return strtotime($b['created_at']) - strtotime($a['created_at']);
});

/* -------------------------------------------------------
   APPLY FILTERS
------------------------------------------------------- */
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';
$priorityFilter = isset($_GET['priority']) ? $_GET['priority'] : 'all';

// Create a filtered copy of notifications
$filteredNotifications = $allNotifications;

// Apply type filter
if ($filter !== 'all') {
    $filteredNotifications = array_filter($filteredNotifications, function($n) use ($filter) {
        return $n['type'] === $filter;
    });
}

// Apply priority filter
if ($priorityFilter !== 'all') {
    $filteredNotifications = array_filter($filteredNotifications, function($n) use ($priorityFilter) {
        return $n['priority'] === $priorityFilter;
    });
}

// Re-index the array after filtering
$filteredNotifications = array_values($filteredNotifications);

/* -------------------------------------------------------
   CALCULATE COUNTS FOR STATS (Using ALL notifications)
------------------------------------------------------- */
$totalNotifications = count($allNotifications);
$highPriorityCount = count(array_filter($allNotifications, function($n) { return $n['priority'] === 'high'; }));
$mediumPriorityCount = count(array_filter($allNotifications, function($n) { return $n['priority'] === 'medium'; }));
$lowPriorityCount = count(array_filter($allNotifications, function($n) { return $n['priority'] === 'low'; }));

// Get today's count
$todayCount = count(array_filter($allNotifications, function($n) {
    return date('Y-m-d', strtotime($n['created_at'])) === date('Y-m-d');
}));

// Get counts by type for display
$typeCounts = [];
foreach ($allNotifications as $n) {
    $typeCounts[$n['type']] = ($typeCounts[$n['type']] ?? 0) + 1;
}

/* -------------------------------------------------------
   PAGE VARIABLES
------------------------------------------------------- */
$pageTitle = 'Notifications';
$currentPage = 'notifications';
?>
<!doctype html>
<html lang="en">

<?php include('includes/head.php'); ?>

<body data-sidebar="dark">

<style>
    .page-content {
        padding-bottom: 90px !important;
    }
    .card {
        margin-bottom: 24px;
    }
    .notification-item {
        transition: all 0.3s ease;
        cursor: pointer;
        border-radius: 8px;
        margin-bottom: 8px;
    }
    .notification-item:hover {
        background-color: #f8f9fa;
        transform: translateX(5px);
    }
    .notification-item.high-priority {
        background-color: rgba(244, 106, 106, 0.08);
        border-left: 3px solid #f46a6a;
    }
    .notification-item.medium-priority {
        background-color: rgba(251, 188, 5, 0.08);
        border-left: 3px solid #fbbc05;
    }
    .avatar-sm {
        width: 40px;
        height: 40px;
    }
    .avatar-title {
        width: 100%;
        height: 100%;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .btn-group-filter .btn {
        margin-right: 5px;
        margin-bottom: 5px;
    }
    .stat-card {
        transition: transform 0.2s;
    }
    .stat-card:hover {
        transform: translateY(-2px);
    }
    .filter-active {
        background-color: #e3f2fd;
        border-left: 3px solid #0d6efd;
    }
    .type-badge {
        font-size: 0.7rem;
        padding: 3px 8px;
    }
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

                <!-- Welcome Header (Same as index.php) -->
                <div class="row">
                    <div class="col-12">
                        <div class="card bg-primary text-white">
                            <div class="card-body">
                                <div class="d-flex flex-wrap align-items-center justify-content-between">
                                    <div>
                                        <h4 class="text-white mb-1">
                                            Notification Center
                                        </h4>
                                        <p class="mb-0 opacity-75">
                                            Stay updated with all important alerts and activities
                                        </p>
                                    </div>
                                    <div class="text-end">
                                        <div class="small">Last updated</div>
                                        <div class="fw-bold"><?php echo date('d M Y, h:i A'); ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Summary Cards (Similar to index.php style) -->
                <div class="row">
                    <div class="col-md-6 col-xl-3">
                        <div class="card text-center stat-card">
                            <div class="card-body">
                                <p class="text-muted mb-1">Total Notifications</p>
                                <h3 class="text-info mt-2 mb-0"><?php echo number_format($totalNotifications); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="card text-center stat-card">
                            <div class="card-body">
                                <p class="text-muted mb-1">High Priority</p>
                                <h3 class="text-danger mt-2 mb-0"><?php echo number_format($highPriorityCount); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="card text-center stat-card">
                            <div class="card-body">
                                <p class="text-muted mb-1">Medium Priority</p>
                                <h3 class="text-warning mt-2 mb-0"><?php echo number_format($mediumPriorityCount); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="card text-center stat-card">
                            <div class="card-body">
                                <p class="text-muted mb-1">Today's Updates</p>
                                <h3 class="text-success mt-2 mb-0"><?php echo number_format($todayCount); ?></h3>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Active Filters Indicator -->
                <?php if ($filter !== 'all' || $priorityFilter !== 'all'): ?>
                    <div class="alert alert-info alert-dismissible fade show" role="alert">
                        <i class="ri-filter-line me-2"></i>
                        <strong>Active Filters:</strong>
                        <?php if ($filter !== 'all'): ?>
                            <span class="badge bg-primary ms-1">Type: <?php echo ucfirst($filter); ?></span>
                        <?php endif; ?>
                        <?php if ($priorityFilter !== 'all'): ?>
                            <span class="badge bg-<?php echo $priorityFilter === 'high' ? 'danger' : ($priorityFilter === 'medium' ? 'warning' : 'info'); ?> ms-1">
                                Priority: <?php echo ucfirst($priorityFilter); ?>
                            </span>
                        <?php endif; ?>
                        <a href="notifications.php" class="float-end text-decoration-none">
                            <i class="ri-close-line"></i> Clear All
                        </a>
                    </div>
                <?php endif; ?>

                <!-- Filter Section (Similar to index.php filter style) -->
                <div class="card">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0">
                            <i class="ri-filter-line me-2"></i>Filter Notifications
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-8">
                                <div class="btn-group-filter" role="group">
                                    <a href="?filter=all&priority=<?php echo $priorityFilter; ?>" 
                                       class="btn btn-<?php echo $filter === 'all' ? 'primary' : 'light'; ?> mb-1">
                                        <i class="ri-notification-line me-1"></i> All (<?php echo $totalNotifications; ?>)
                                    </a>
                                    <a href="?filter=stock&priority=<?php echo $priorityFilter; ?>" 
                                       class="btn btn-<?php echo $filter === 'stock' ? 'warning' : 'light'; ?> mb-1">
                                        <i class="ri-stack-line me-1"></i> Stock (<?php echo $typeCounts['stock'] ?? 0; ?>)
                                    </a>
                                    <a href="?filter=job&priority=<?php echo $priorityFilter; ?>" 
                                       class="btn btn-<?php echo $filter === 'job' ? 'info' : 'light'; ?> mb-1">
                                        <i class="ri-tools-line me-1"></i> Job Cards (<?php echo $typeCounts['job'] ?? 0; ?>)
                                    </a>
                                    <a href="?filter=booking&priority=<?php echo $priorityFilter; ?>" 
                                       class="btn btn-<?php echo $filter === 'booking' ? 'primary' : 'light'; ?> mb-1">
                                        <i class="ri-calendar-check-line me-1"></i> Bookings (<?php echo $typeCounts['booking'] ?? 0; ?>)
                                    </a>
                                    <a href="?filter=invoice&priority=<?php echo $priorityFilter; ?>" 
                                       class="btn btn-<?php echo $filter === 'invoice' ? 'danger' : 'light'; ?> mb-1">
                                        <i class="ri-bank-card-line me-1"></i> Invoices (<?php echo $typeCounts['invoice'] ?? 0; ?>)
                                    </a>
                                    <a href="?filter=customer&priority=<?php echo $priorityFilter; ?>" 
                                       class="btn btn-<?php echo $filter === 'customer' ? 'success' : 'light'; ?> mb-1">
                                        <i class="ri-user-add-line me-1"></i> Customers (<?php echo $typeCounts['customer'] ?? 0; ?>)
                                    </a>
                                    <a href="?filter=audit&priority=<?php echo $priorityFilter; ?>" 
                                       class="btn btn-<?php echo $filter === 'audit' ? 'secondary' : 'light'; ?> mb-1">
                                        <i class="ri-history-line me-1"></i> Audit (<?php echo $typeCounts['audit'] ?? 0; ?>)
                                    </a>
                                </div>
                            </div>
                            <div class="col-md-4 mt-3 mt-md-0">
                                <div class="d-flex justify-content-md-end">
                                    <div class="btn-group" role="group">
                                        <a href="?filter=<?php echo $filter; ?>&priority=all" 
                                           class="btn btn-<?php echo $priorityFilter === 'all' ? 'secondary' : 'light'; ?>">
                                            All (<?php echo $totalNotifications; ?>)
                                        </a>
                                        <a href="?filter=<?php echo $filter; ?>&priority=high" 
                                           class="btn btn-<?php echo $priorityFilter === 'high' ? 'danger' : 'light'; ?>">
                                            High (<?php echo $highPriorityCount; ?>)
                                        </a>
                                        <a href="?filter=<?php echo $filter; ?>&priority=medium" 
                                           class="btn btn-<?php echo $priorityFilter === 'medium' ? 'warning' : 'light'; ?>">
                                            Medium (<?php echo $mediumPriorityCount; ?>)
                                        </a>
                                        <a href="?filter=<?php echo $filter; ?>&priority=low" 
                                           class="btn btn-<?php echo $priorityFilter === 'low' ? 'info' : 'light'; ?>">
                                            Low (<?php echo $lowPriorityCount; ?>)
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Notifications List -->
                <div class="card">
                    <div class="card-header bg-light d-flex justify-content-between align-items-center flex-wrap">
                        <h5 class="card-title mb-0">
                            <i class="ri-notification-3-line me-2"></i>
                            <?php 
                            if ($filter !== 'all') {
                                echo ucfirst($filter) . ' Notifications';
                            } else {
                                echo 'All Notifications';
                            }
                            ?>
                        </h5>
                        <div>
                            <span class="badge bg-info"><?php echo count($filteredNotifications); ?> items</span>
                            <?php if ($filter !== 'all' || $priorityFilter !== 'all'): ?>
                                <a href="notifications.php" class="btn btn-sm btn-outline-secondary ms-2">
                                    <i class="ri-refresh-line me-1"></i> Show All
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (empty($filteredNotifications)): ?>
                            <div class="text-center py-5">
                                <i class="ri-notification-off-line font-size-48 text-muted mb-3 d-block"></i>
                                <h5>No notifications found</h5>
                                <p class="text-muted">
                                    <?php if ($filter !== 'all' || $priorityFilter !== 'all'): ?>
                                        No notifications match your filter criteria.
                                    <?php else: ?>
                                        You're all caught up! No new notifications at the moment.
                                    <?php endif; ?>
                                </p>
                                <?php if ($filter !== 'all' || $priorityFilter !== 'all'): ?>
                                    <a href="notifications.php" class="btn btn-primary">
                                        <i class="ri-refresh-line me-1"></i> View All Notifications
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="notification-list">
                                <?php foreach ($filteredNotifications as $notification): ?>
                                    <div class="notification-item p-3 border-bottom <?php echo $notification['priority'] === 'high' ? 'high-priority' : ($notification['priority'] === 'medium' ? 'medium-priority' : ''); ?>">
                                        <div class="d-flex">
                                            <div class="flex-shrink-0 me-3">
                                                <div class="avatar-sm">
                                                    <span class="avatar-title bg-soft-<?php echo $notification['badge']; ?> text-<?php echo $notification['badge']; ?> rounded-circle">
                                                        <i class="<?php echo $notification['icon']; ?> font-size-18"></i>
                                                    </span>
                                                </div>
                                            </div>
                                            <div class="flex-grow-1">
                                                <div class="d-flex flex-wrap align-items-center justify-content-between mb-2">
                                                    <div>
                                                        <h5 class="mb-1">
                                                            <?php echo h($notification['title']); ?>
                                                            <?php if ($notification['priority'] === 'high'): ?>
                                                                <span class="badge bg-danger ms-2">High Priority</span>
                                                            <?php elseif ($notification['priority'] === 'medium'): ?>
                                                                <span class="badge bg-warning ms-2">Medium</span>
                                                            <?php endif; ?>
                                                        </h5>
                                                        <span class="badge bg-soft-<?php echo $notification['badge']; ?> text-<?php echo $notification['badge']; ?> type-badge">
                                                            <i class="<?php echo $notification['icon']; ?> me-1"></i>
                                                            <?php echo ucfirst($notification['type']); ?>
                                                        </span>
                                                    </div>
                                                    <small class="text-muted">
                                                        <i class="ri-time-line me-1"></i>
                                                        <?php echo timeAgo($notification['created_at']); ?>
                                                    </small>
                                                </div>
                                                <p class="text-muted mb-2">
                                                    <?php echo h($notification['message']); ?>
                                                </p>
                                                <?php if ($notification['link'] !== '#'): ?>
                                                    <a href="<?php echo $notification['link']; ?>" 
                                                       class="btn btn-sm btn-outline-<?php echo $notification['badge']; ?>">
                                                        <i class="ri-eye-line me-1"></i>
                                                        <?php echo $notification['link_text']; ?>
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Recent Activity Feed (Similar to index.php) -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-3">
                                    <i class="ri-history-line me-2"></i>Recent Activity Feed
                                </h4>
                                
                                <ol class="activity-feed mb-0">
                                    <?php 
                                    $recentActivities = array_slice($filteredNotifications, 0, 5);
                                    if (!empty($recentActivities)): 
                                        foreach ($recentActivities as $activity): 
                                    ?>
                                        <li class="feed-item">
                                            <span class="date">
                                                <?php echo h(date('d M', strtotime($activity['created_at']))); ?>
                                            </span>
                                            <span class="activity-text">
                                                <strong><?php echo h($activity['title']); ?></strong>
                                                <br>
                                                <small class="text-muted"><?php echo h(substr($activity['message'], 0, 100)) . (strlen($activity['message']) > 100 ? '...' : ''); ?></small>
                                            </span>
                                        </li>
                                    <?php 
                                        endforeach;
                                    else: 
                                    ?>
                                        <li class="feed-item">
                                            <span class="date"><?php echo date('d M'); ?></span>
                                            <span class="activity-text">No recent activity available.</span>
                                        </li>
                                    <?php endif; ?>
                                </ol>
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

<script>
$(document).ready(function() {
    // Click on notification to navigate
    $('.notification-item').click(function(e) {
        if (!$(e.target).closest('a').length && !$(e.target).closest('button').length) {
            var link = $(this).find('a').first().attr('href');
            if (link && link !== '#') {
                window.location.href = link;
            }
        }
    });

    // Auto-refresh notifications every 5 minutes (optional - uncomment if needed)
    // setTimeout(function() {
    //     location.reload();
    // }, 300000);
});
</script>

</body>
</html>