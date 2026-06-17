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
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
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
    if (!$stmt) return false;

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

if (!$loggedUser || (int)$loggedUser['status'] !== 1 || ($loggedUser['business_status'] ?? '') !== 'active') {
    session_destroy();
    header('Location: login.php');
    exit;
}

/* -------------------------------------------------------
   FILTERS
------------------------------------------------------- */
$search = trim($_GET['search'] ?? '');
$where = "b.business_id = {$businessId}";

if ($search !== '') {
    $safeSearch = $conn->real_escape_string($search);
    $where .= " AND (
        b.branch_name LIKE '%{$safeSearch}%'
        OR b.branch_code LIKE '%{$safeSearch}%'
        OR b.city LIKE '%{$safeSearch}%'
        OR b.district LIKE '%{$safeSearch}%'
        OR b.state LIKE '%{$safeSearch}%'
        OR b.mobile LIKE '%{$safeSearch}%'
    )";
}

/* -------------------------------------------------------
   SUMMARY
------------------------------------------------------- */
$totalBranches = 0;
$activeBranches = 0;
$totalSalesInvoices = 0;
$totalServiceInvoices = 0;
$totalPayments = 0;
$totalExpenses = 0;

if (tableExists($conn, 'branches')) {
    $res = $conn->query("SELECT COUNT(*) AS total FROM branches WHERE business_id = {$businessId}");
    if ($res) {
        $row = $res->fetch_assoc();
        $totalBranches = (int)($row['total'] ?? 0);
    }

    $res = $conn->query("SELECT COUNT(*) AS total FROM branches WHERE business_id = {$businessId} AND status = 'active'");
    if ($res) {
        $row = $res->fetch_assoc();
        $activeBranches = (int)($row['total'] ?? 0);
    }
}

if (tableExists($conn, 'sales_invoices')) {
    $res = $conn->query("SELECT COUNT(*) AS total FROM sales_invoices WHERE business_id = {$businessId}");
    if ($res) {
        $row = $res->fetch_assoc();
        $totalSalesInvoices = (int)($row['total'] ?? 0);
    }
}

if (tableExists($conn, 'service_invoices')) {
    $res = $conn->query("SELECT COUNT(*) AS total FROM service_invoices WHERE business_id = {$businessId}");
    if ($res) {
        $row = $res->fetch_assoc();
        $totalServiceInvoices = (int)($row['total'] ?? 0);
    }
}

if (tableExists($conn, 'payments')) {
    $res = $conn->query("SELECT COALESCE(SUM(amount),0) AS total FROM payments WHERE business_id = {$businessId}");
    if ($res) {
        $row = $res->fetch_assoc();
        $totalPayments = (float)($row['total'] ?? 0);
    }
}

if (tableExists($conn, 'expenses')) {
    $res = $conn->query("SELECT COALESCE(SUM(amount),0) AS total FROM expenses WHERE business_id = {$businessId}");
    if ($res) {
        $row = $res->fetch_assoc();
        $totalExpenses = (float)($row['total'] ?? 0);
    }
}

/* -------------------------------------------------------
   FETCH BRANCH PERFORMANCE
------------------------------------------------------- */
$branchPerformance = [];

if (tableExists($conn, 'branches')) {
    $sql = "SELECT
                b.id,
                b.branch_name,
                b.branch_code,
                b.contact_person,
                b.mobile,
                b.city,
                b.district,
                b.state,
                b.is_head_office,
                b.status,
                b.created_at,

                (
                    SELECT COUNT(*)
                    FROM business_users bu
                    WHERE bu.business_id = b.business_id
                      AND bu.branch_id = b.id
                ) AS total_users,

                (
                    SELECT COUNT(*)
                    FROM sales_invoices si
                    WHERE si.business_id = b.business_id
                      AND si.branch_id = b.id
                ) AS sales_invoice_count,

                (
                    SELECT COUNT(*)
                    FROM service_invoices svi
                    WHERE svi.business_id = b.business_id
                      AND svi.branch_id = b.id
                ) AS service_invoice_count,

                (
                    SELECT COALESCE(SUM(p.amount),0)
                    FROM payments p
                    WHERE p.business_id = b.business_id
                      AND p.branch_id = b.id
                ) AS total_payments,

                (
                    SELECT COALESCE(SUM(e.amount),0)
                    FROM expenses e
                    WHERE e.business_id = b.business_id
                      AND e.branch_id = b.id
                ) AS total_expenses

            FROM branches b
            WHERE {$where}
            ORDER BY b.is_head_office DESC, b.branch_name ASC";

    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $row['balance'] = (float)$row['total_payments'] - (float)$row['total_expenses'];
            $branchPerformance[] = $row;
        }
    }
}

$pageTitle = 'Branch Performance';
$currentPage = 'branch-performance';
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
                        <h4 class="mb-1">Branch Performance</h4>
                        <p class="text-muted mb-0">View branch-wise business performance</p>
                    </div>
                    <div class="col-md-6 text-md-end mt-3 mt-md-0">
                        <a href="branches.php" class="btn btn-secondary">Back</a>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6 col-xl-2">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Branches</p>
                                <h3 class="mb-0"><?php echo number_format($totalBranches); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-2">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Active</p>
                                <h3 class="mb-0 text-success"><?php echo number_format($activeBranches); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-2">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Sales</p>
                                <h3 class="mb-0 text-primary"><?php echo number_format($totalSalesInvoices); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-2">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Service</p>
                                <h3 class="mb-0 text-info"><?php echo number_format($totalServiceInvoices); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-2">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Payments</p>
                                <h6 class="mb-0 text-success"><?php echo money($totalPayments); ?></h6>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-2">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Expenses</p>
                                <h6 class="mb-0 text-danger"><?php echo money($totalExpenses); ?></h6>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-body">

                        <form method="get" class="row g-3 mb-4">
                            <div class="col-md-10">
                                <input
                                    type="text"
                                    name="search"
                                    class="form-control"
                                    placeholder="Search by branch name, code, city, district, state, mobile..."
                                    value="<?php echo h($search); ?>"
                                >
                            </div>
                            <div class="col-md-2">
                                <button type="submit" class="btn btn-primary w-100">Search</button>
                            </div>
                        </form>

                        <div class="table-responsive">
                            <table class="table table-bordered table-striped align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Branch</th>
                                        <th>Users</th>
                                        <th>Sales Invoices</th>
                                        <th>Service Invoices</th>
                                        <th>Payments</th>
                                        <th>Expenses</th>
                                        <th>Balance</th>
                                        <th>Status</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($branchPerformance)): ?>
                                        <?php $i = 1; ?>
                                        <?php foreach ($branchPerformance as $row): ?>
                                            <tr>
                                                <td><?php echo $i++; ?></td>

                                                <td>
                                                    <div class="fw-bold"><?php echo h($row['branch_name']); ?></div>
                                                    <div class="text-muted small"><?php echo h($row['branch_code'] ?: '-'); ?></div>
                                                    <div class="text-muted small">
                                                        <?php
                                                        $loc = trim(
                                                            ($row['city'] ?? '') .
                                                            (($row['city'] ?? '') && ($row['district'] ?? '') ? ', ' : '') .
                                                            ($row['district'] ?? '') .
                                                            ((($row['city'] ?? '') || ($row['district'] ?? '')) && ($row['state'] ?? '') ? ', ' : '') .
                                                            ($row['state'] ?? '')
                                                        );
                                                        echo h($loc ?: '-');
                                                        ?>
                                                    </div>
                                                    <?php if ((int)$row['is_head_office'] === 1): ?>
                                                        <span class="badge bg-info mt-1">Head Office</span>
                                                    <?php endif; ?>
                                                </td>

                                                <td><?php echo number_format((int)$row['total_users']); ?></td>
                                                <td><?php echo number_format((int)$row['sales_invoice_count']); ?></td>
                                                <td><?php echo number_format((int)$row['service_invoice_count']); ?></td>
                                                <td class="text-success"><?php echo money($row['total_payments']); ?></td>
                                                <td class="text-danger"><?php echo money($row['total_expenses']); ?></td>
                                                <td class="<?php echo ((float)$row['balance'] >= 0) ? 'text-success' : 'text-danger'; ?>">
                                                    <?php echo money($row['balance']); ?>
                                                </td>

                                                <td>
                                                    <?php
                                                    $status = strtolower((string)($row['status'] ?? 'inactive'));
                                                    $badge = 'secondary';
                                                    if ($status === 'active') $badge = 'success';
                                                    elseif ($status === 'inactive') $badge = 'warning';
                                                    ?>
                                                    <span class="badge bg-<?php echo $badge; ?>">
                                                        <?php echo h(ucfirst($status)); ?>
                                                    </span>
                                                </td>

                                                <td>
                                                    <a href="branch-view.php?id=<?php echo (int)$row['id']; ?>" class="btn btn-sm btn-info">View</a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="10" class="text-center text-muted py-4">No branch data found.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
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