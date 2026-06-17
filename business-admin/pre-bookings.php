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
            $stmt = $conn->prepare("
                SELECT 1
                FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ?
                LIMIT 1
            ");
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param('s', $table);
            $stmt->execute();
            $res = $stmt->get_result();
            $ok = $res && $res->num_rows > 0;
            $stmt->close();
            return $ok;
        }

        function columnExists(mysqli $conn, string $table, string $column): bool
        {
            $stmt = $conn->prepare("
                SELECT 1
                FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ?
                AND COLUMN_NAME = ?
                LIMIT 1
            ");
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param('ss', $table, $column);
            $stmt->execute();
            $res = $stmt->get_result();
            $ok = $res && $res->num_rows > 0;
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

        function getCount(mysqli $conn, string $table, string $where = '1=1'): int
        {
            $sql = "SELECT COUNT(*) AS total FROM {$table} WHERE {$where}";
            $res = $conn->query($sql);
            if (!$res) {
                return 0;
            }
            $row = $res->fetch_assoc();
            return (int)($row['total'] ?? 0);
        }

        function getSum(mysqli $conn, string $table, string $field, string $where = '1=1'): float
        {
            $sql = "SELECT COALESCE(SUM({$field}),0) AS total FROM {$table} WHERE {$where}";
            $res = $conn->query($sql);
            if (!$res) {
                return 0;
            }
            $row = $res->fetch_assoc();
            return (float)($row['total'] ?? 0);
        }

        /* -------------------------------------------------------
        REQUIRED TABLES
        ------------------------------------------------------- */
        $requiredTables = ['business_users', 'businesses', 'branches', 'customers', 'pre_bookings'];
        foreach ($requiredTables as $tbl) {
            if (!tableExists($conn, $tbl)) {
                die($tbl . ' table not found.');
            }
        }

        $hasVehicleModels = tableExists($conn, 'vehicle_models');
        $hasVehicleBrands = tableExists($conn, 'vehicle_brands');
        $hasProducts = tableExists($conn, 'products');
        $hasPaymentMethods = tableExists($conn, 'payment_methods');

        $hasBrandNameColumn = columnExists($conn, 'pre_bookings', 'brand_name');
        $hasModelNameColumn = columnExists($conn, 'pre_bookings', 'model_name');
        $hasVariantNameColumn = columnExists($conn, 'pre_bookings', 'variant_name');

        /* -------------------------------------------------------
        VALIDATE LOGIN USER
        ------------------------------------------------------- */
        $loggedUser = null;
        $stmt = $conn->prepare("
            SELECT bu.id, bu.full_name, bu.role, bu.status,
                b.business_name, b.status AS business_status
            FROM business_users bu
            INNER JOIN businesses b ON b.id = bu.business_id
            WHERE bu.id = ? AND bu.business_id = ?
            LIMIT 1
        ");
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
        DELETE
        ------------------------------------------------------- */
        $success = '';
        $error = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
            $deleteId = isset($_POST['id']) ? (int)$_POST['id'] : 0;

            if ($deleteId <= 0) {
                $error = 'Invalid pre booking id.';
            } else {
                $stmt = $conn->prepare("DELETE FROM pre_bookings WHERE id = ? AND business_id = ? LIMIT 1");
                if (!$stmt) {
                    $error = 'Failed to prepare delete query.';
                } else {
                    $stmt->bind_param('ii', $deleteId, $businessId);
                    if ($stmt->execute()) {
                        if ($stmt->affected_rows > 0) {
                            $success = 'Pre booking deleted successfully.';
                        } else {
                            $error = 'Pre booking not found.';
                        }
                    } else {
                        $error = 'Failed to delete pre booking.';
                    }
                    $stmt->close();
                }
            }
        }

        if (isset($_GET['success']) && trim($_GET['success']) !== '') {
            $success = trim($_GET['success']);
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

        $customers = fetchAllAssoc(
            $conn,
            "SELECT id, full_name, mobile
            FROM customers
            WHERE business_id = {$businessId}
            ORDER BY full_name ASC"
        );

        /* -------------------------------------------------------
        FILTERS
        ------------------------------------------------------- */
        $search = trim($_GET['search'] ?? '');
        $branchFilter = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : 0;
        $customerFilter = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;
        $typeFilter = trim($_GET['booking_type'] ?? '');
        $statusFilter = trim($_GET['status'] ?? '');
        $dateFrom = trim($_GET['date_from'] ?? '');
        $dateTo = trim($_GET['date_to'] ?? '');

        $allowedTypes = ['vehicle', 'product'];
        $allowedStatuses = ['open', 'confirmed', 'cancelled', 'converted', 'delivered'];

        $where = ["pb.business_id = {$businessId}"];

        if ($search !== '') {
            $safe = $conn->real_escape_string($search);
            $manualVehicleSearch = '';
            if ($hasBrandNameColumn && $hasModelNameColumn && $hasVariantNameColumn) {
                $manualVehicleSearch = "
                    OR pb.brand_name LIKE '%{$safe}%'
                    OR pb.model_name LIKE '%{$safe}%'
                    OR pb.variant_name LIKE '%{$safe}%'
                ";
            }

            $where[] = "(
                pb.booking_no LIKE '%{$safe}%'
                OR c.full_name LIKE '%{$safe}%'
                OR c.mobile LIKE '%{$safe}%'
                OR br.branch_name LIKE '%{$safe}%'
                OR pb.reference_no LIKE '%{$safe}%'
                OR pb.notes LIKE '%{$safe}%'
                " . ($hasVehicleModels ? "OR vm.model_name LIKE '%{$safe}%' OR vm.variant_name LIKE '%{$safe}%'" : "") . "
                " . ($hasVehicleBrands && $hasVehicleModels ? "OR vb.brand_name LIKE '%{$safe}%'" : "") . "
                " . ($hasProducts ? "OR p.product_name LIKE '%{$safe}%'" : "") . "
                {$manualVehicleSearch}
            )";
        }

        if ($branchFilter > 0) {
            $where[] = "pb.branch_id = {$branchFilter}";
        }

        if ($customerFilter > 0) {
            $where[] = "pb.customer_id = {$customerFilter}";
        }

        if ($typeFilter !== '' && in_array($typeFilter, $allowedTypes, true)) {
            $safe = $conn->real_escape_string($typeFilter);
            $where[] = "pb.booking_type = '{$safe}'";
        }

        if ($statusFilter !== '' && in_array($statusFilter, $allowedStatuses, true)) {
            $safe = $conn->real_escape_string($statusFilter);
            $where[] = "pb.status = '{$safe}'";
        }

        if ($dateFrom !== '') {
            $safe = $conn->real_escape_string($dateFrom);
            $where[] = "DATE(pb.booking_date) >= '{$safe}'";
        }

        if ($dateTo !== '') {
            $safe = $conn->real_escape_string($dateTo);
            $where[] = "DATE(pb.booking_date) <= '{$safe}'";
        }

        $whereSql = implode(' AND ', $where);

        /* -------------------------------------------------------
        SUMMARY
        ------------------------------------------------------- */
        $totalBookings = getCount($conn, 'pre_bookings', "business_id = {$businessId}");
        $totalBookingAmount = getSum($conn, 'pre_bookings', 'booking_amount', "business_id = {$businessId}");

        $openCount = getCount($conn, 'pre_bookings', "business_id = {$businessId} AND status = 'open'");
        $confirmedCount = getCount($conn, 'pre_bookings', "business_id = {$businessId} AND status = 'confirmed'");
        $cancelledCount = getCount($conn, 'pre_bookings', "business_id = {$businessId} AND status = 'cancelled'");
        $convertedCount = getCount($conn, 'pre_bookings', "business_id = {$businessId} AND status = 'converted'");
        $deliveredCount = getCount($conn, 'pre_bookings', "business_id = {$businessId} AND status = 'delivered'");

        $vehicleBookingCount = getCount($conn, 'pre_bookings', "business_id = {$businessId} AND booking_type = 'vehicle'");
        $productBookingCount = getCount($conn, 'pre_bookings', "business_id = {$businessId} AND booking_type = 'product'");

        $today = date('Y-m-d');
        $todayCount = getCount($conn, 'pre_bookings', "business_id = {$businessId} AND DATE(booking_date) = '{$today}'");
        $todayAmount = getSum($conn, 'pre_bookings', 'booking_amount', "business_id = {$businessId} AND DATE(booking_date) = '{$today}'");

        /* -------------------------------------------------------
        FETCH BOOKINGS
        ------------------------------------------------------- */
        $manualBrandSelect = $hasBrandNameColumn ? "pb.brand_name," : "NULL AS brand_name,";
        $manualModelSelect = $hasModelNameColumn ? "pb.model_name," : "NULL AS model_name,";
        $manualVariantSelect = $hasVariantNameColumn ? "pb.variant_name," : "NULL AS variant_name,";

        $rows = fetchAllAssoc(
            $conn,
            "SELECT
                pb.*,
                {$manualBrandSelect}
                {$manualModelSelect}
                {$manualVariantSelect}
                br.branch_name,
                br.branch_code,
                c.full_name AS customer_name,
                c.mobile AS customer_mobile,
                " . ($hasVehicleModels ? "vm.model_name AS master_model_name, vm.variant_name AS master_variant_name" : "NULL AS master_model_name, NULL AS master_variant_name") . ",
                " . ($hasVehicleBrands && $hasVehicleModels ? "vb.brand_name AS master_brand_name" : "NULL AS master_brand_name") . ",
                " . ($hasProducts ? "p.product_name, p.product_code" : "NULL AS product_name, NULL AS product_code") . ",
                " . ($hasPaymentMethods ? "pm.method_name" : "NULL AS method_name") . "
            FROM pre_bookings pb
            LEFT JOIN branches br ON br.id = pb.branch_id
            LEFT JOIN customers c ON c.id = pb.customer_id
            " . ($hasVehicleModels ? "LEFT JOIN vehicle_models vm ON vm.id = pb.vehicle_model_id" : "") . "
            " . ($hasVehicleBrands && $hasVehicleModels ? "LEFT JOIN vehicle_brands vb ON vb.id = vm.brand_id" : "") . "
            " . ($hasProducts ? "LEFT JOIN products p ON p.id = pb.product_id" : "") . "
            " . ($hasPaymentMethods ? "LEFT JOIN payment_methods pm ON pm.id = pb.payment_method_id" : "") . "
            WHERE {$whereSql}
            ORDER BY pb.id DESC
            LIMIT 500"
        );

        $pageTitle = 'Pre Bookings';
        $currentPage = 'pre-bookings';
        ?>
        <!doctype html>
        <html lang="en">
        <?php include('includes/head.php'); ?>

        <body data-sidebar="dark">

        <style>
        .page-content { padding-bottom: 90px !important; }
        .card { margin-bottom: 24px; }
        .main-content { min-height: calc(100vh - 70px); }
        .report-last-row { margin-bottom: 40px; }
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
                            <div class="col-md-7">
                                <h4 class="mb-1">Pre Bookings</h4>
                                <p class="text-muted mb-0">Manage customer pre bookings for vehicles and products</p>
                            </div>
                            <div class="col-md-5 text-md-end mt-3 mt-md-0">
                                <a href="pre-booking-add.php" class="btn btn-primary me-2">Create Pre Booking</a>
                                <a href="sales-dashboard.php" class="btn btn-secondary">Back to Sales</a>
                            </div>
                        </div>

                        <?php if ($success !== ''): ?>
                            <div class="alert alert-success"><?php echo h($success); ?></div>
                        <?php endif; ?>

                        <?php if ($error !== ''): ?>
                            <div class="alert alert-danger"><?php echo h($error); ?></div>
                        <?php endif; ?>

                        <div class="row">
                            <div class="col-md-3">
                                <div class="card">
                                    <div class="card-body text-center">
                                        <p class="text-muted mb-1">Total Bookings</p>
                                        <h3 class="mb-0"><?php echo number_format($totalBookings); ?></h3>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-3">
                                <div class="card">
                                    <div class="card-body text-center">
                                        <p class="text-muted mb-1">Booking Amount</p>
                                        <h3 class="mb-0 text-primary"><?php echo money($totalBookingAmount); ?></h3>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-3">
                                <div class="card">
                                    <div class="card-body text-center">
                                        <p class="text-muted mb-1">Today Bookings</p>
                                        <h3 class="mb-0 text-info"><?php echo number_format($todayCount); ?></h3>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-3">
                                <div class="card">
                                    <div class="card-body text-center">
                                        <p class="text-muted mb-1">Today Amount</p>
                                        <h3 class="mb-0 text-success"><?php echo money($todayAmount); ?></h3>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-2">
                                <div class="card"><div class="card-body text-center"><p class="text-muted mb-1">Open</p><h5 class="mb-0"><?php echo number_format($openCount); ?></h5></div></div>
                            </div>
                            <div class="col-md-2">
                                <div class="card"><div class="card-body text-center"><p class="text-muted mb-1">Confirmed</p><h5 class="mb-0 text-primary"><?php echo number_format($confirmedCount); ?></h5></div></div>
                            </div>
                            <div class="col-md-2">
                                <div class="card"><div class="card-body text-center"><p class="text-muted mb-1">Cancelled</p><h5 class="mb-0 text-danger"><?php echo number_format($cancelledCount); ?></h5></div></div>
                            </div>
                            <div class="col-md-2">
                                <div class="card"><div class="card-body text-center"><p class="text-muted mb-1">Converted</p><h5 class="mb-0 text-warning"><?php echo number_format($convertedCount); ?></h5></div></div>
                            </div>
                            <div class="col-md-2">
                                <div class="card"><div class="card-body text-center"><p class="text-muted mb-1">Delivered</p><h5 class="mb-0 text-success"><?php echo number_format($deliveredCount); ?></h5></div></div>
                            </div>
                            <div class="col-md-2">
                                <div class="card"><div class="card-body text-center"><p class="text-muted mb-1">Vehicle/Product</p><h6 class="mb-0"><?php echo number_format($vehicleBookingCount); ?> / <?php echo number_format($productBookingCount); ?></h6></div></div>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-body">
                                <form method="get" class="row g-3">
                                    <div class="col-md-3">
                                        <label class="form-label">Search</label>
                                        <input
                                            type="text"
                                            name="search"
                                            class="form-control"
                                            placeholder="Booking no, customer, vehicle, product..."
                                            value="<?php echo h($search); ?>"
                                        >
                                    </div>

                                    <div class="col-md-2">
                                        <label class="form-label">Branch</label>
                                        <select name="branch_id" class="form-select">
                                            <option value="0">All Branches</option>
                                            <?php foreach ($branches as $b): ?>
                                                <option value="<?php echo (int)$b['id']; ?>" <?php echo ($branchFilter === (int)$b['id']) ? 'selected' : ''; ?>>
                                                    <?php echo h($b['branch_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="col-md-2">
                                        <label class="form-label">Customer</label>
                                        <select name="customer_id" class="form-select">
                                            <option value="0">All Customers</option>
                                            <?php foreach ($customers as $c): ?>
                                                <option value="<?php echo (int)$c['id']; ?>" <?php echo ($customerFilter === (int)$c['id']) ? 'selected' : ''; ?>>
                                                    <?php echo h($c['full_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="col-md-1">
                                        <label class="form-label">Type</label>
                                        <select name="booking_type" class="form-select">
                                            <option value="">All</option>
                                            <option value="vehicle" <?php echo ($typeFilter === 'vehicle') ? 'selected' : ''; ?>>Vehicle</option>
                                            <option value="product" <?php echo ($typeFilter === 'product') ? 'selected' : ''; ?>>Product</option>
                                        </select>
                                    </div>

                                    <div class="col-md-2">
                                        <label class="form-label">Status</label>
                                        <select name="status" class="form-select">
                                            <option value="">All</option>
                                            <option value="open" <?php echo ($statusFilter === 'open') ? 'selected' : ''; ?>>Open</option>
                                            <option value="confirmed" <?php echo ($statusFilter === 'confirmed') ? 'selected' : ''; ?>>Confirmed</option>
                                            <option value="cancelled" <?php echo ($statusFilter === 'cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                                            <option value="converted" <?php echo ($statusFilter === 'converted') ? 'selected' : ''; ?>>Converted</option>
                                            <option value="delivered" <?php echo ($statusFilter === 'delivered') ? 'selected' : ''; ?>>Delivered</option>
                                        </select>
                                    </div>

                                    <div class="col-md-1">
                                        <label class="form-label">From</label>
                                        <input type="date" name="date_from" class="form-control" value="<?php echo h($dateFrom); ?>">
                                    </div>

                                    <div class="col-md-1">
                                        <label class="form-label">To</label>
                                        <input type="date" name="date_to" class="form-control" value="<?php echo h($dateTo); ?>">
                                    </div>

                                    <div class="col-md-12 d-flex gap-2">
                                        <button type="submit" class="btn btn-primary">Filter</button>
                                        <a href="pre-bookings.php" class="btn btn-light">Reset</a>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <div class="card report-last-row">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Pre Booking List</h4>

                                <div class="table-responsive">
                                    <table class="table table-bordered table-striped align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th>#</th>
                                                <th>Booking</th>
                                                <th>Date</th>
                                                <th>Branch</th>
                                                <th>Customer</th>
                                                <th>Type / Item</th>
                                                <th>Color / Delivery</th>
                                                <th>Amount</th>
                                                <th>Status</th>
                                                <th style="width:340px;">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($rows)): ?>
                                                <?php $i = 1; foreach ($rows as $row): ?>
                                                    <?php
                                                    $statusBadge = 'secondary';
                                                    if (($row['status'] ?? '') === 'open') $statusBadge = 'secondary';
                                                    elseif (($row['status'] ?? '') === 'confirmed') $statusBadge = 'primary';
                                                    elseif (($row['status'] ?? '') === 'cancelled') $statusBadge = 'danger';
                                                    elseif (($row['status'] ?? '') === 'converted') $statusBadge = 'warning';
                                                    elseif (($row['status'] ?? '') === 'delivered') $statusBadge = 'success';

                                                    $itemText = '-';
                                                    if (($row['booking_type'] ?? '') === 'vehicle') {
                                                        if (!empty($row['master_model_name']) || !empty($row['master_brand_name'])) {
                                                            $itemText = trim(
                                                                ($row['master_brand_name'] ?? 'Brand') . ' - ' .
                                                                ($row['master_model_name'] ?? 'Model') .
                                                                (!empty($row['master_variant_name']) ? ' - ' . $row['master_variant_name'] : '')
                                                            );
                                                        } else {
                                                            $itemText = trim(
                                                                ($row['brand_name'] ?? 'Brand') . ' - ' .
                                                                ($row['model_name'] ?? 'Model') .
                                                                (!empty($row['variant_name']) ? ' - ' . $row['variant_name'] : '')
                                                            );
                                                        }
                                                    } elseif (($row['booking_type'] ?? '') === 'product') {
                                                        $itemText = trim(($row['product_name'] ?? 'Product') . (!empty($row['product_code']) ? ' (' . $row['product_code'] . ')' : ''));
                                                    }

                                                    $canConvert = in_array((string)($row['status'] ?? ''), ['open', 'confirmed'], true);
                                                    ?>
                                                    <tr>
                                                        <td><?php echo $i++; ?></td>

                                                        <td class="small">
                                                            <div><strong><?php echo h($row['booking_no']); ?></strong></div>
                                                            <?php if (!empty($row['reference_no'])): ?>
                                                                <div class="text-muted">Ref: <?php echo h($row['reference_no']); ?></div>
                                                            <?php endif; ?>
                                                        </td>

                                                        <td class="small">
                                                            <div><?php echo !empty($row['booking_date']) ? h(date('d M Y h:i A', strtotime($row['booking_date']))) : '-'; ?></div>
                                                            <div class="text-muted">Created: <?php echo !empty($row['created_at']) ? h(date('d M Y', strtotime($row['created_at']))) : '-'; ?></div>
                                                        </td>

                                                        <td class="small">
                                                            <div><?php echo h($row['branch_name'] ?: '-'); ?></div>
                                                            <div class="text-muted"><?php echo h($row['branch_code'] ?: '-'); ?></div>
                                                        </td>

                                                        <td class="small">
                                                            <div><strong><?php echo h($row['customer_name'] ?: '-'); ?></strong></div>
                                                            <div class="text-muted"><?php echo h($row['customer_mobile'] ?: '-'); ?></div>
                                                        </td>

                                                        <td class="small">
                                                            <div>
                                                                <span class="badge bg-info">
                                                                    <?php echo h(ucfirst((string)$row['booking_type'])); ?>
                                                                </span>
                                                            </div>
                                                            <div class="mt-1"><strong><?php echo h($itemText); ?></strong></div>
                                                        </td>

                                                        <td class="small">
                                                            <div><strong>Color:</strong> <?php echo h($row['color_preference'] ?: '-'); ?></div>
                                                            <div><strong>Delivery:</strong> <?php echo !empty($row['expected_delivery_date']) ? h(date('d M Y', strtotime($row['expected_delivery_date']))) : '-'; ?></div>
                                                            <div><strong>Payment:</strong> <?php echo h($row['method_name'] ?: '-'); ?></div>
                                                        </td>

                                                        <td class="small">
                                                            <div><strong>Booking Amount:</strong> <?php echo money($row['booking_amount']); ?></div>
                                                        </td>

                                                        <td>
                                                            <span class="badge bg-<?php echo $statusBadge; ?>">
                                                                <?php echo h(ucfirst((string)$row['status'])); ?>
                                                            </span>
                                                        </td>

                                                        <td>
                                                            <div class="d-flex flex-wrap gap-2">
                                                                <a href="pre-booking-view.php?id=<?php echo (int)$row['id']; ?>" class="btn btn-sm btn-info">View</a>
                                                                <a href="pre-booking-edit.php?id=<?php echo (int)$row['id']; ?>" class="btn btn-sm btn-primary">Edit</a>
                                                                <a href="pre-booking-print.php?id=<?php echo (int)$row['id']; ?>" class="btn btn-sm btn-secondary" target="_blank">Print</a>

                                                                <?php if ($canConvert): ?>
                                                                    <a href="sales-invoice-add.php?pre_booking_id=<?php echo (int)$row['id']; ?>" class="btn btn-sm btn-success">Convert</a>
                                                                <?php endif; ?>

                                                                <form method="post" style="display:inline;" onsubmit="return confirm('Delete this pre booking?');">
                                                                    <input type="hidden" name="action" value="delete">
                                                                    <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                                                                    <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                                                                </form>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="10" class="text-center text-muted py-4">No pre bookings found.</td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <div class="mt-3 text-muted small">
                                    Showing latest 500 pre booking records.
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