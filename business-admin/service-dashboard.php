<?php
session_start();
date_default_timezone_set('Asia/Kolkata');

require_once __DIR__ . '/includes/config.php';

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
$currentBranchId = isset($_SESSION['branch_id']) ? (int)$_SESSION['branch_id'] : 0;

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

function fetchAllAssoc(mysqli $conn, string $sql): array
{
    $rows = [];
    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $res->free();
    }
    return $rows;
}

function scalarValue(mysqli $conn, string $sql, string $field = 'val')
{
    $res = $conn->query($sql);
    if (!$res) return 0;
    $row = $res->fetch_assoc();
    $res->free();
    return $row[$field] ?? 0;
}

function badgeClass(string $status): string
{
    $status = strtolower($status);
    if (in_array($status, ['paid', 'confirmed', 'delivered', 'completed', 'closed', 'resolved'], true)) return 'success';
    if (in_array($status, ['partial', 'pending', 'in_progress', 'draft'], true)) return 'warning';
    if (in_array($status, ['unpaid', 'cancelled', 'rejected', 'open'], true)) return 'danger';
    return 'secondary';
}

function percentValue(float $value, float $total): float
{
    return $total > 0 ? min(100, max(0, ($value / $total) * 100)) : 0;
}

/* -------------------------------------------------------
   VALIDATE USER
------------------------------------------------------- */
$loggedUser = null;
if (tableExists($conn, 'business_users') && tableExists($conn, 'businesses')) {
    $stmt = $conn->prepare("SELECT bu.id, bu.full_name, bu.role, bu.status, b.business_name, b.status AS business_status FROM business_users bu INNER JOIN businesses b ON b.id = bu.business_id WHERE bu.id = ? AND bu.business_id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('ii', $businessUserId, $businessId);
        $stmt->execute();
        $loggedUser = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}

if (!$loggedUser || (int)($loggedUser['status'] ?? 0) !== 1 || ($loggedUser['business_status'] ?? '') !== 'active') {
    session_destroy();
    header('Location: login.php');
    exit;
}

/* -------------------------------------------------------
   REQUIRED TABLE CHECKS
------------------------------------------------------- */
$requiredTables = ['branches', 'customers', 'customer_vehicles', 'service_job_cards', 'service_invoices', 'service_complaints', 'service_job_part_items', 'service_job_labor_items'];
$missingTables = [];
foreach ($requiredTables as $tbl) {
    if (!tableExists($conn, $tbl)) {
        $missingTables[] = $tbl;
    }
}

/* -------------------------------------------------------
   FILTERS
------------------------------------------------------- */
$filterRange = trim($_GET['range'] ?? 'month');
$branchFilter = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : 0;
$fromDate = trim($_GET['from_date'] ?? '');
$toDate = trim($_GET['to_date'] ?? '');

if (!in_array($filterRange, ['today', 'week', 'month', 'year', 'custom'], true)) {
    $filterRange = 'month';
}

$today = date('Y-m-d');
if ($filterRange === 'today') {
    $fromDate = $today;
    $toDate = $today;
} elseif ($filterRange === 'week') {
    $fromDate = date('Y-m-d', strtotime('monday this week'));
    $toDate = date('Y-m-d', strtotime('sunday this week'));
} elseif ($filterRange === 'month') {
    $fromDate = date('Y-m-01');
    $toDate = date('Y-m-t');
} elseif ($filterRange === 'year') {
    $fromDate = date('Y-01-01');
    $toDate = date('Y-12-31');
} else {
    if ($fromDate === '') $fromDate = date('Y-m-01');
    if ($toDate === '') $toDate = date('Y-m-d');
}

$fromDateSafe = $conn->real_escape_string($fromDate);
$toDateSafe = $conn->real_escape_string($toDate);
$branchSql = $branchFilter > 0 ? " AND branch_id = {$branchFilter}" : '';
$branchJoinSql = $branchFilter > 0 ? " AND si.branch_id = {$branchFilter}" : '';
$dateSqlInvoices = "DATE(invoice_date) BETWEEN '{$fromDateSafe}' AND '{$toDateSafe}'";
$dateSqlJobs = "DATE(service_date) BETWEEN '{$fromDateSafe}' AND '{$toDateSafe}'";

$branches = tableExists($conn, 'branches') ? fetchAllAssoc($conn, "SELECT id, branch_name, branch_code FROM branches WHERE business_id = {$businessId} ORDER BY branch_name ASC") : [];

/* -------------------------------------------------------
   DASHBOARD DATA
------------------------------------------------------- */
$totalInvoices = 0;
$totalRevenue = 0.0;
$totalPaid = 0.0;
$totalBalance = 0.0;
$paidInvoices = 0;
$partialInvoices = 0;
$unpaidInvoices = 0;
$confirmedInvoices = 0;
$draftInvoices = 0;
$cancelledInvoices = 0;

$totalJobs = 0;
$openJobs = 0;
$inProgressJobs = 0;
$deliveredJobs = 0;
$completedComplaints = 0;
$pendingComplaints = 0;
$totalPartsUsed = 0;
$totalLabourItems = 0;
$avgInvoiceValue = 0.0;

$recentInvoices = [];
$recentJobs = [];
$branchRevenueRows = [];
$dailyRevenueRows = [];
$topProducts = [];
$topLabour = [];
$jobStatusRows = [];
$paymentStatusRows = [];

if (empty($missingTables)) {
    $invBaseWhere = "business_id = {$businessId}{$branchSql} AND {$dateSqlInvoices}";
    $jobBaseWhere = "business_id = {$businessId}{$branchSql} AND {$dateSqlJobs}";

    $totalInvoices = (int)scalarValue($conn, "SELECT COUNT(*) AS val FROM service_invoices WHERE {$invBaseWhere}");
    $totalRevenue = (float)scalarValue($conn, "SELECT COALESCE(SUM(grand_total),0) AS val FROM service_invoices WHERE {$invBaseWhere}");
    $totalPaid = (float)scalarValue($conn, "SELECT COALESCE(SUM(paid_amount),0) AS val FROM service_invoices WHERE {$invBaseWhere}");
    $totalBalance = (float)scalarValue($conn, "SELECT COALESCE(SUM(balance_amount),0) AS val FROM service_invoices WHERE {$invBaseWhere}");
    $paidInvoices = (int)scalarValue($conn, "SELECT COUNT(*) AS val FROM service_invoices WHERE {$invBaseWhere} AND payment_status = 'paid'");
    $partialInvoices = (int)scalarValue($conn, "SELECT COUNT(*) AS val FROM service_invoices WHERE {$invBaseWhere} AND payment_status = 'partial'");
    $unpaidInvoices = (int)scalarValue($conn, "SELECT COUNT(*) AS val FROM service_invoices WHERE {$invBaseWhere} AND payment_status = 'unpaid'");
    $confirmedInvoices = (int)scalarValue($conn, "SELECT COUNT(*) AS val FROM service_invoices WHERE {$invBaseWhere} AND invoice_status = 'confirmed'");
    $draftInvoices = (int)scalarValue($conn, "SELECT COUNT(*) AS val FROM service_invoices WHERE {$invBaseWhere} AND invoice_status = 'draft'");
    $cancelledInvoices = (int)scalarValue($conn, "SELECT COUNT(*) AS val FROM service_invoices WHERE {$invBaseWhere} AND invoice_status = 'cancelled'");
    $avgInvoiceValue = $totalInvoices > 0 ? ($totalRevenue / $totalInvoices) : 0;

    $totalJobs = (int)scalarValue($conn, "SELECT COUNT(*) AS val FROM service_job_cards WHERE {$jobBaseWhere}");
    $openJobs = (int)scalarValue($conn, "SELECT COUNT(*) AS val FROM service_job_cards WHERE {$jobBaseWhere} AND job_status IN ('open','pending')");
    $inProgressJobs = (int)scalarValue($conn, "SELECT COUNT(*) AS val FROM service_job_cards WHERE {$jobBaseWhere} AND job_status IN ('in_progress','working','assigned')");
    $deliveredJobs = (int)scalarValue($conn, "SELECT COUNT(*) AS val FROM service_job_cards WHERE {$jobBaseWhere} AND job_status IN ('delivered','completed','closed')");

    $pendingComplaints = (int)scalarValue($conn, "SELECT COUNT(*) AS val FROM service_complaints sc INNER JOIN service_job_cards sj ON sj.id = sc.jobcard_id WHERE sj.business_id = {$businessId}{$branchJoinSql} AND {$dateSqlJobs} AND sc.status IN ('pending','open','in_progress')");
    $completedComplaints = (int)scalarValue($conn, "SELECT COUNT(*) AS val FROM service_complaints sc INNER JOIN service_job_cards sj ON sj.id = sc.jobcard_id WHERE sj.business_id = {$businessId}{$branchJoinSql} AND {$dateSqlJobs} AND sc.status IN ('resolved','completed','closed')");

    $totalPartsUsed = (int)scalarValue($conn, "SELECT COALESCE(SUM(sp.qty),0) AS val FROM service_job_part_items sp INNER JOIN service_job_cards sj ON sj.id = sp.jobcard_id WHERE sj.business_id = {$businessId}{$branchJoinSql} AND {$dateSqlJobs}");
    $totalLabourItems = (int)scalarValue($conn, "SELECT COUNT(*) AS val FROM service_job_labor_items sl INNER JOIN service_job_cards sj ON sj.id = sl.jobcard_id WHERE sj.business_id = {$businessId}{$branchJoinSql} AND {$dateSqlJobs}");

    $recentInvoices = fetchAllAssoc($conn, "
        SELECT si.id, si.invoice_no, si.invoice_date, si.grand_total, si.paid_amount, si.balance_amount, si.payment_status, si.invoice_status,
               c.full_name AS customer_name, c.mobile AS customer_mobile, br.branch_name, sj.jobcard_no
        FROM service_invoices si
        LEFT JOIN customers c ON c.id = si.customer_id
        LEFT JOIN branches br ON br.id = si.branch_id
        LEFT JOIN service_job_cards sj ON sj.id = si.jobcard_id
        WHERE si.business_id = {$businessId}{$branchJoinSql} AND {$dateSqlInvoices}
        ORDER BY si.id DESC
        LIMIT 8
    ");

    $recentJobs = fetchAllAssoc($conn, "
        SELECT sj.id, sj.jobcard_no, sj.service_date, sj.job_status, sj.opening_km, sj.final_amount,
               c.full_name AS customer_name, c.mobile AS customer_mobile,
               cv.registration_no, cv.chassis_no, vb.brand_name, vm.model_name, br.branch_name
        FROM service_job_cards sj
        LEFT JOIN customers c ON c.id = sj.customer_id
        LEFT JOIN customer_vehicles cv ON cv.id = sj.customer_vehicle_id
        LEFT JOIN vehicle_brands vb ON vb.id = cv.brand_id
        LEFT JOIN vehicle_models vm ON vm.id = cv.model_id
        LEFT JOIN branches br ON br.id = sj.branch_id
        WHERE sj.business_id = {$businessId}{$branchSql} AND {$dateSqlJobs}
        ORDER BY sj.id DESC
        LIMIT 8
    ");

    $branchRevenueRows = fetchAllAssoc($conn, "
        SELECT br.branch_name, COUNT(si.id) AS invoice_count, COALESCE(SUM(si.grand_total),0) AS revenue, COALESCE(SUM(si.balance_amount),0) AS balance
        FROM service_invoices si
        LEFT JOIN branches br ON br.id = si.branch_id
        WHERE si.business_id = {$businessId} AND {$dateSqlInvoices}" . ($branchFilter > 0 ? " AND si.branch_id = {$branchFilter}" : '') . "
        GROUP BY si.branch_id, br.branch_name
        ORDER BY revenue DESC
        LIMIT 10
    ");

    $dailyRevenueRows = fetchAllAssoc($conn, "
        SELECT DATE(invoice_date) AS inv_date, COUNT(*) AS invoice_count, COALESCE(SUM(grand_total),0) AS revenue
        FROM service_invoices
        WHERE {$invBaseWhere}
        GROUP BY DATE(invoice_date)
        ORDER BY inv_date ASC
        LIMIT 31
    ");

    $topProducts = fetchAllAssoc($conn, "
        SELECT p.product_name, p.product_code, COALESCE(SUM(sp.qty),0) AS used_qty, COALESCE(SUM(sp.line_total),0) AS amount
        FROM service_job_part_items sp
        INNER JOIN service_job_cards sj ON sj.id = sp.jobcard_id
        LEFT JOIN products p ON p.id = sp.product_id
        WHERE sj.business_id = {$businessId}{$branchJoinSql} AND {$dateSqlJobs}
        GROUP BY sp.product_id, p.product_name, p.product_code
        ORDER BY used_qty DESC, amount DESC
        LIMIT 8
    ");

    $topLabour = fetchAllAssoc($conn, "
        SELECT sl.description, COUNT(*) AS count_used, COALESCE(SUM(sl.line_total),0) AS amount
        FROM service_job_labor_items sl
        INNER JOIN service_job_cards sj ON sj.id = sl.jobcard_id
        WHERE sj.business_id = {$businessId}{$branchJoinSql} AND {$dateSqlJobs}
        GROUP BY sl.description
        ORDER BY count_used DESC, amount DESC
        LIMIT 8
    ");

    $jobStatusRows = fetchAllAssoc($conn, "
        SELECT job_status, COUNT(*) AS total
        FROM service_job_cards
        WHERE {$jobBaseWhere}
        GROUP BY job_status
        ORDER BY total DESC
    ");

    $paymentStatusRows = fetchAllAssoc($conn, "
        SELECT payment_status, COUNT(*) AS total, COALESCE(SUM(grand_total),0) AS amount
        FROM service_invoices
        WHERE {$invBaseWhere}
        GROUP BY payment_status
        ORDER BY total DESC
    ");
}

$collectionRate = percentValue($totalPaid, $totalRevenue);
$balanceRate = percentValue($totalBalance, $totalRevenue);
$deliveredRate = percentValue($deliveredJobs, $totalJobs);

$pageTitle = 'Service Dashboard';
$currentPage = 'service-dashboard';
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

                <style>
                    .service-hero{background:linear-gradient(135deg,#0f172a,#1f2937);border-radius:18px;padding:22px;color:#fff;box-shadow:0 12px 30px rgba(15,23,42,.18);position:relative;overflow:hidden}
                    .service-hero:before{content:"";position:absolute;right:-80px;top:-80px;width:230px;height:230px;background:rgba(246,173,34,.18);border-radius:50%}
                    .service-hero:after{content:"";position:absolute;right:80px;bottom:-100px;width:180px;height:180px;background:rgba(255,194,71,.13);border-radius:50%}
                    .service-hero h3{font-weight:800;margin:0 0 5px;position:relative;z-index:1}
                    .service-hero p{margin:0;color:rgba(255,255,255,.75);position:relative;z-index:1}
                    .service-action{position:relative;z-index:1}
                    .stat-card{border:0;border-radius:16px;box-shadow:0 8px 24px rgba(15,23,42,.07);overflow:hidden}
                    .stat-card .card-body{padding:18px}
                    .stat-icon{width:44px;height:44px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:22px;background:#fff7ed;color:#f6ad22;flex:0 0 auto}
                    .stat-title{font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#64748b;font-weight:700;margin-bottom:5px}
                    .stat-value{font-size:24px;font-weight:850;color:#0f172a;margin:0;line-height:1.1}
                    .mini-text{font-size:12px;color:#64748b;margin-top:6px}
                    .dash-card{border:0;border-radius:16px;box-shadow:0 8px 24px rgba(15,23,42,.07)}
                    .dash-card .card-header{background:#fff;border-bottom:1px solid #eef2f7;border-radius:16px 16px 0 0;padding:14px 16px;font-weight:800;color:#0f172a}
                    .filter-card{border:0;border-radius:16px;box-shadow:0 8px 24px rgba(15,23,42,.07)}
                    .badge-soft-success{background:#dcfce7;color:#166534}.badge-soft-warning{background:#fef3c7;color:#92400e}.badge-soft-danger{background:#fee2e2;color:#991b1b}.badge-soft-secondary{background:#e2e8f0;color:#334155}
                    .progress{height:8px;border-radius:999px;background:#e2e8f0}.progress-bar{border-radius:999px}
                    .table-sm td,.table-sm th{padding:.55rem .55rem;vertical-align:middle}
                    .quick-link{border:1px solid #e2e8f0;border-radius:14px;padding:14px;text-decoration:none;color:#0f172a;display:flex;align-items:center;gap:12px;background:#fff;transition:.15s}
                    .quick-link:hover{transform:translateY(-2px);box-shadow:0 10px 20px rgba(15,23,42,.08);color:#0f172a}
                    .quick-link i{font-size:22px;color:#f6ad22}
                    .bar-row{display:grid;grid-template-columns:110px 1fr 90px;gap:10px;align-items:center;margin-bottom:11px}
                    @media(max-width:768px){.service-hero{padding:18px}.stat-value{font-size:20px}.bar-row{grid-template-columns:1fr}.service-action{margin-top:14px}}
                </style>

                <div class="service-hero mb-4">
                    <div class="row align-items-center">
                        <div class="col-lg-7">
                            <h3>🛠️ Service Dashboard</h3>
                            <p>Track service invoices, job cards, complaints, parts usage, labour revenue and pending collections.</p>
                        </div>
                        <div class="col-lg-5 text-lg-end service-action">
                            <a href="service-invoice-add.php" class="btn btn-warning me-2 mb-2"><i class="ri-add-circle-line me-1"></i>Add Service Invoice</a>
                            <a href="service-invoices.php" class="btn btn-light mb-2"><i class="ri-file-list-line me-1"></i>Service Invoices</a>
                        </div>
                    </div>
                </div>

                <?php if (!empty($missingTables)): ?>
                    <div class="alert alert-warning">
                        <strong>Missing required table(s):</strong> <?php echo h(implode(', ', $missingTables)); ?>
                    </div>
                <?php endif; ?>

                <div class="card filter-card mb-4">
                    <div class="card-body">
                        <form method="get" class="row g-3 align-items-end">
                            <div class="col-lg-2 col-md-4">
                                <label class="form-label">Range</label>
                                <select name="range" class="form-select" onchange="this.form.submit()">
                                    <option value="today" <?php echo $filterRange === 'today' ? 'selected' : ''; ?>>Today</option>
                                    <option value="week" <?php echo $filterRange === 'week' ? 'selected' : ''; ?>>This Week</option>
                                    <option value="month" <?php echo $filterRange === 'month' ? 'selected' : ''; ?>>This Month</option>
                                    <option value="year" <?php echo $filterRange === 'year' ? 'selected' : ''; ?>>This Year</option>
                                    <option value="custom" <?php echo $filterRange === 'custom' ? 'selected' : ''; ?>>Custom</option>
                                </select>
                            </div>
                            <div class="col-lg-2 col-md-4">
                                <label class="form-label">From</label>
                                <input type="date" name="from_date" class="form-control" value="<?php echo h($fromDate); ?>" onchange="document.querySelector('[name=range]').value='custom'">
                            </div>
                            <div class="col-lg-2 col-md-4">
                                <label class="form-label">To</label>
                                <input type="date" name="to_date" class="form-control" value="<?php echo h($toDate); ?>" onchange="document.querySelector('[name=range]').value='custom'">
                            </div>
                            <div class="col-lg-3 col-md-6">
                                <label class="form-label">Branch</label>
                                <select name="branch_id" class="form-select">
                                    <option value="0">All Branches</option>
                                    <?php foreach ($branches as $b): ?>
                                        <option value="<?php echo (int)$b['id']; ?>" <?php echo $branchFilter === (int)$b['id'] ? 'selected' : ''; ?>>
                                            <?php echo h($b['branch_name'] . ' (' . $b['branch_code'] . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-lg-3 col-md-6 d-flex gap-2">
                                <button type="submit" class="btn btn-primary flex-fill"><i class="ri-filter-3-line me-1"></i>Apply</button>
                                <a href="service-dashboard.php" class="btn btn-light flex-fill">Reset</a>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="row">
                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card stat-card h-100"><div class="card-body d-flex gap-3 align-items-center"><div class="stat-icon"><i class="ri-file-list-3-line"></i></div><div><div class="stat-title">Service Invoices</div><h4 class="stat-value"><?php echo number_format($totalInvoices); ?></h4><div class="mini-text">Confirmed: <?php echo number_format($confirmedInvoices); ?> | Draft: <?php echo number_format($draftInvoices); ?></div></div></div></div>
                    </div>
                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card stat-card h-100"><div class="card-body d-flex gap-3 align-items-center"><div class="stat-icon"><i class="ri-money-rupee-circle-line"></i></div><div><div class="stat-title">Service Revenue</div><h4 class="stat-value text-primary"><?php echo money($totalRevenue); ?></h4><div class="mini-text">Avg: <?php echo money($avgInvoiceValue); ?></div></div></div></div>
                    </div>
                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card stat-card h-100"><div class="card-body d-flex gap-3 align-items-center"><div class="stat-icon"><i class="ri-bank-card-line"></i></div><div class="w-100"><div class="stat-title">Collection</div><h4 class="stat-value text-success"><?php echo money($totalPaid); ?></h4><div class="progress mt-2"><div class="progress-bar bg-success" style="width:<?php echo number_format($collectionRate, 2); ?>%"></div></div><div class="mini-text"><?php echo number_format($collectionRate, 1); ?>% collected</div></div></div></div>
                    </div>
                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card stat-card h-100"><div class="card-body d-flex gap-3 align-items-center"><div class="stat-icon"><i class="ri-alert-line"></i></div><div class="w-100"><div class="stat-title">Pending Balance</div><h4 class="stat-value text-danger"><?php echo money($totalBalance); ?></h4><div class="progress mt-2"><div class="progress-bar bg-danger" style="width:<?php echo number_format($balanceRate, 2); ?>%"></div></div><div class="mini-text"><?php echo number_format($unpaidInvoices); ?> unpaid invoice(s)</div></div></div></div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-xl-3 col-md-6 mb-4"><div class="card stat-card h-100"><div class="card-body"><div class="stat-title">Job Cards</div><h4 class="stat-value"><?php echo number_format($totalJobs); ?></h4><div class="mini-text">Delivered/Closed: <?php echo number_format($deliveredJobs); ?></div><div class="progress mt-2"><div class="progress-bar bg-info" style="width:<?php echo number_format($deliveredRate, 2); ?>%"></div></div></div></div></div>
                    <div class="col-xl-3 col-md-6 mb-4"><div class="card stat-card h-100"><div class="card-body"><div class="stat-title">Pending Complaints</div><h4 class="stat-value text-warning"><?php echo number_format($pendingComplaints); ?></h4><div class="mini-text">Resolved: <?php echo number_format($completedComplaints); ?></div></div></div></div>
                    <div class="col-xl-3 col-md-6 mb-4"><div class="card stat-card h-100"><div class="card-body"><div class="stat-title">Parts Used Qty</div><h4 class="stat-value text-primary"><?php echo number_format($totalPartsUsed); ?></h4><div class="mini-text">From selected period</div></div></div></div>
                    <div class="col-xl-3 col-md-6 mb-4"><div class="card stat-card h-100"><div class="card-body"><div class="stat-title">Labour Entries</div><h4 class="stat-value text-success"><?php echo number_format($totalLabourItems); ?></h4><div class="mini-text">Labour lines billed</div></div></div></div>
                </div>

                <div class="row">
                    <div class="col-xl-8 mb-4">
                        <div class="card dash-card h-100">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <span>Daily Service Revenue</span>
                                <span class="badge bg-light text-dark"><?php echo h(date('d M Y', strtotime($fromDate)) . ' - ' . date('d M Y', strtotime($toDate))); ?></span>
                            </div>
                            <div class="card-body">
                                <?php if (!empty($dailyRevenueRows)): ?>
                                    <?php $maxDaily = max(array_map(function($r){ return (float)$r['revenue']; }, $dailyRevenueRows)); ?>
                                    <?php foreach ($dailyRevenueRows as $dr): ?>
                                        <?php $w = percentValue((float)$dr['revenue'], (float)$maxDaily); ?>
                                        <div class="bar-row">
                                            <div class="small fw-bold"><?php echo h(date('d M', strtotime($dr['inv_date']))); ?></div>
                                            <div><div class="progress"><div class="progress-bar bg-primary" style="width:<?php echo number_format($w, 2); ?>%"></div></div></div>
                                            <div class="text-end small fw-bold"><?php echo money($dr['revenue']); ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="text-center text-muted py-4">No revenue data found for selected period.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-4 mb-4">
                        <div class="card dash-card h-100">
                            <div class="card-header">Payment Status</div>
                            <div class="card-body">
                                <?php if (!empty($paymentStatusRows)): ?>
                                    <?php foreach ($paymentStatusRows as $ps): ?>
                                        <?php $pct = percentValue((float)$ps['total'], (float)$totalInvoices); $cls = badgeClass((string)$ps['payment_status']); ?>
                                        <div class="mb-3">
                                            <div class="d-flex justify-content-between mb-1">
                                                <span class="fw-bold text-capitalize"><?php echo h($ps['payment_status'] ?: '-'); ?></span>
                                                <span class="small"><?php echo number_format((int)$ps['total']); ?> invoice(s)</span>
                                            </div>
                                            <div class="progress"><div class="progress-bar bg-<?php echo h($cls); ?>" style="width:<?php echo number_format($pct, 2); ?>%"></div></div>
                                            <div class="small text-muted mt-1"><?php echo money($ps['amount']); ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="text-center text-muted py-4">No payment status data.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-xl-6 mb-4">
                        <div class="card dash-card h-100">
                            <div class="card-header">Recent Service Invoices</div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover mb-0">
                                        <thead class="table-light"><tr><th>Invoice</th><th>Customer</th><th>Total</th><th>Payment</th><th></th></tr></thead>
                                        <tbody>
                                        <?php if (!empty($recentInvoices)): foreach ($recentInvoices as $ri): ?>
                                            <tr>
                                                <td><strong><?php echo h($ri['invoice_no']); ?></strong><div class="small text-muted"><?php echo h(date('d M Y', strtotime($ri['invoice_date']))); ?></div></td>
                                                <td><?php echo h($ri['customer_name'] ?: '-'); ?><div class="small text-muted"><?php echo h($ri['customer_mobile'] ?: '-'); ?></div></td>
                                                <td><strong><?php echo money($ri['grand_total']); ?></strong><div class="small text-muted">Bal: <?php echo money($ri['balance_amount']); ?></div></td>
                                                <td><span class="badge bg-<?php echo badgeClass((string)$ri['payment_status']); ?>"><?php echo h(ucfirst((string)$ri['payment_status'])); ?></span></td>
                                                <td class="text-end"><a href="service-invoice-view.php?id=<?php echo (int)$ri['id']; ?>" class="btn btn-sm btn-light">View</a></td>
                                            </tr>
                                        <?php endforeach; else: ?>
                                            <tr><td colspan="5" class="text-center text-muted py-4">No invoices found.</td></tr>
                                        <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-6 mb-4">
                        <div class="card dash-card h-100">
                            <div class="card-header">Recent Job Cards</div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover mb-0">
                                        <thead class="table-light"><tr><th>Job Card</th><th>Customer</th><th>Vehicle</th><th>Status</th><th>Amount</th></tr></thead>
                                        <tbody>
                                        <?php if (!empty($recentJobs)): foreach ($recentJobs as $rj): ?>
                                            <tr>
                                                <td><strong><?php echo h($rj['jobcard_no']); ?></strong><div class="small text-muted"><?php echo h(date('d M Y', strtotime($rj['service_date']))); ?></div></td>
                                                <td><?php echo h($rj['customer_name'] ?: '-'); ?><div class="small text-muted"><?php echo h($rj['customer_mobile'] ?: '-'); ?></div></td>
                                                <td><?php echo h(trim(($rj['brand_name'] ?? '') . ' ' . ($rj['model_name'] ?? '')) ?: 'Vehicle'); ?><div class="small text-muted"><?php echo h($rj['registration_no'] ?: ($rj['chassis_no'] ?: '-')); ?></div></td>
                                                <td><span class="badge bg-<?php echo badgeClass((string)$rj['job_status']); ?>"><?php echo h(ucwords(str_replace('_', ' ', (string)$rj['job_status']))); ?></span></td>
                                                <td><?php echo money($rj['final_amount']); ?></td>
                                            </tr>
                                        <?php endforeach; else: ?>
                                            <tr><td colspan="5" class="text-center text-muted py-4">No job cards found.</td></tr>
                                        <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-xl-4 mb-4">
                        <div class="card dash-card h-100">
                            <div class="card-header">Branch-wise Service Revenue</div>
                            <div class="card-body">
                                <?php if (!empty($branchRevenueRows)): ?>
                                    <?php $maxBranch = max(array_map(function($r){ return (float)$r['revenue']; }, $branchRevenueRows)); ?>
                                    <?php foreach ($branchRevenueRows as $br): $pct = percentValue((float)$br['revenue'], (float)$maxBranch); ?>
                                        <div class="mb-3">
                                            <div class="d-flex justify-content-between"><strong><?php echo h($br['branch_name'] ?: 'Branch'); ?></strong><span><?php echo money($br['revenue']); ?></span></div>
                                            <div class="progress mt-1"><div class="progress-bar bg-primary" style="width:<?php echo number_format($pct, 2); ?>%"></div></div>
                                            <div class="small text-muted mt-1"><?php echo number_format((int)$br['invoice_count']); ?> invoice(s), Balance <?php echo money($br['balance']); ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="text-center text-muted py-4">No branch revenue data.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-4 mb-4">
                        <div class="card dash-card h-100">
                            <div class="card-header">Top Products Used</div>
                            <div class="card-body p-0">
                                <table class="table table-sm mb-0">
                                    <thead class="table-light"><tr><th>Product</th><th class="text-end">Qty</th><th class="text-end">Amount</th></tr></thead>
                                    <tbody>
                                    <?php if (!empty($topProducts)): foreach ($topProducts as $tp): ?>
                                        <tr><td><strong><?php echo h($tp['product_name'] ?: 'Product'); ?></strong><div class="small text-muted"><?php echo h($tp['product_code'] ?: '-'); ?></div></td><td class="text-end"><?php echo number_format((float)$tp['used_qty'], 2); ?></td><td class="text-end"><?php echo money($tp['amount']); ?></td></tr>
                                    <?php endforeach; else: ?>
                                        <tr><td colspan="3" class="text-center text-muted py-4">No parts used.</td></tr>
                                    <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-4 mb-4">
                        <div class="card dash-card h-100">
                            <div class="card-header">Top Labour Charges</div>
                            <div class="card-body p-0">
                                <table class="table table-sm mb-0">
                                    <thead class="table-light"><tr><th>Labour</th><th class="text-end">Count</th><th class="text-end">Amount</th></tr></thead>
                                    <tbody>
                                    <?php if (!empty($topLabour)): foreach ($topLabour as $tl): ?>
                                        <tr><td><strong><?php echo h($tl['description'] ?: 'Labour'); ?></strong></td><td class="text-end"><?php echo number_format((int)$tl['count_used']); ?></td><td class="text-end"><?php echo money($tl['amount']); ?></td></tr>
                                    <?php endforeach; else: ?>
                                        <tr><td colspan="3" class="text-center text-muted py-4">No labour data.</td></tr>
                                    <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row mb-2">
                    <div class="col-xl-3 col-md-6 mb-3"><a href="service-invoice-add.php" class="quick-link"><i class="ri-add-box-line"></i><div><strong>Create Service Invoice</strong><div class="small text-muted">Job card + invoice</div></div></a></div>
                    <div class="col-xl-3 col-md-6 mb-3"><a href="service-invoices.php?payment_status=unpaid" class="quick-link"><i class="ri-error-warning-line"></i><div><strong>Pending Collections</strong><div class="small text-muted">Unpaid service invoices</div></div></a></div>
                    <div class="col-xl-3 col-md-6 mb-3"><a href="service-invoices.php?invoice_status=draft" class="quick-link"><i class="ri-draft-line"></i><div><strong>Draft Invoices</strong><div class="small text-muted">Review pending invoices</div></div></a></div>
                    <div class="col-xl-3 col-md-6 mb-3"><a href="service-invoices.php" class="quick-link"><i class="ri-file-list-3-line"></i><div><strong>All Service Invoices</strong><div class="small text-muted">View, edit and print</div></div></a></div>
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
