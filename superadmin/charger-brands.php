<?php
date_default_timezone_set('Asia/Kolkata');
require_once 'includes/config.php';

if (!isset($_SESSION['platform_admin_id']) || (int)$_SESSION['platform_admin_id'] <= 0) {
    header("Location: login.php");
    exit;
}

function h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$success = '';
$error   = '';

/* -------------------------------------------------------
   CREATE TABLE IF NOT EXISTS
------------------------------------------------------- */
$conn->query("
    CREATE TABLE IF NOT EXISTS charger_brands (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        brand_name VARCHAR(100) NOT NULL UNIQUE,
        status TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

/* -------------------------------------------------------
   ADD / UPDATE BRAND
------------------------------------------------------- */
$editId = (int)($_GET['edit'] ?? 0);
$editBrand = null;

if ($editId > 0) {
    $stmt = $conn->prepare("SELECT id, brand_name, status FROM charger_brands WHERE id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("i", $editId);
        $stmt->execute();
        $result = $stmt->get_result();
        $editBrand = $result ? $result->fetch_assoc() : null;
        $stmt->close();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $brand_name = trim($_POST['brand_name'] ?? '');
    $status     = isset($_POST['status']) ? 1 : 0;
    $brand_id   = (int)($_POST['brand_id'] ?? 0);

    if ($brand_name === '') {
        $error = 'Charger brand name is required.';
    } else {
        if ($brand_id > 0) {
            $check = $conn->prepare("SELECT id FROM charger_brands WHERE brand_name = ? AND id != ? LIMIT 1");
            if ($check) {
                $check->bind_param("si", $brand_name, $brand_id);
                $check->execute();
                $res = $check->get_result();
                $exists = $res ? $res->fetch_assoc() : null;
                $check->close();

                if ($exists) {
                    $error = 'Charger brand name already exists.';
                } else {
                    $stmt = $conn->prepare("UPDATE charger_brands SET brand_name = ?, status = ? WHERE id = ? LIMIT 1");
                    if ($stmt) {
                        $stmt->bind_param("sii", $brand_name, $status, $brand_id);
                        if ($stmt->execute()) {
                            $success = 'Charger brand updated successfully.';
                            $editBrand = null;
                            $_GET['edit'] = null;
                        } else {
                            $error = 'Failed to update charger brand.';
                        }
                        $stmt->close();
                    } else {
                        $error = 'Database error while updating charger brand.';
                    }
                }
            } else {
                $error = 'Database error while checking charger brand.';
            }
        } else {
            $check = $conn->prepare("SELECT id FROM charger_brands WHERE brand_name = ? LIMIT 1");
            if ($check) {
                $check->bind_param("s", $brand_name);
                $check->execute();
                $res = $check->get_result();
                $exists = $res ? $res->fetch_assoc() : null;
                $check->close();

                if ($exists) {
                    $error = 'Charger brand name already exists.';
                } else {
                    $stmt = $conn->prepare("INSERT INTO charger_brands (brand_name, status, created_at) VALUES (?, ?, NOW())");
                    if ($stmt) {
                        $stmt->bind_param("si", $brand_name, $status);
                        if ($stmt->execute()) {
                            $success = 'Charger brand added successfully.';
                        } else {
                            $error = 'Failed to add charger brand.';
                        }
                        $stmt->close();
                    } else {
                        $error = 'Database error while adding charger brand.';
                    }
                }
            } else {
                $error = 'Database error while checking charger brand.';
            }
        }
    }
}

/* -------------------------------------------------------
   DELETE BRAND
------------------------------------------------------- */
if (isset($_GET['delete']) && $_GET['delete'] !== '') {
    $deleteId = (int)$_GET['delete'];

    if ($deleteId > 0) {
        $stmt = $conn->prepare("DELETE FROM charger_brands WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("i", $deleteId);
            if ($stmt->execute()) {
                $success = 'Charger brand deleted successfully.';
            } else {
                $error = 'Failed to delete charger brand.';
            }
            $stmt->close();
        } else {
            $error = 'Database error while deleting charger brand.';
        }
    }
}

/* -------------------------------------------------------
   FILTERS
------------------------------------------------------- */
$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status_filter'] ?? '');

$where = " WHERE 1=1 ";
$params = [];
$types = "";

if ($search !== '') {
    $where .= " AND brand_name LIKE ? ";
    $params[] = "%{$search}%";
    $types .= "s";
}

if ($statusFilter !== '' && in_array($statusFilter, ['1', '0'], true)) {
    $where .= " AND status = ? ";
    $params[] = (int)$statusFilter;
    $types .= "i";
}

/* -------------------------------------------------------
   FETCH BRANDS
------------------------------------------------------- */
$brands = [];

$sql = "SELECT id, brand_name, status, created_at FROM charger_brands {$where} ORDER BY brand_name ASC";
$stmt = $conn->prepare($sql);
if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $brands[] = $row;
        }
    }
    $stmt->close();
}

/* -------------------------------------------------------
   COUNTS
------------------------------------------------------- */
$totalBrands = 0;
$activeBrands = 0;
$inactiveBrands = 0;

$res = $conn->query("SELECT COUNT(*) AS total FROM charger_brands");
if ($res && $row = $res->fetch_assoc()) {
    $totalBrands = (int)$row['total'];
}

$res = $conn->query("SELECT COUNT(*) AS total FROM charger_brands WHERE status = 1");
if ($res && $row = $res->fetch_assoc()) {
    $activeBrands = (int)$row['total'];
}

$res = $conn->query("SELECT COUNT(*) AS total FROM charger_brands WHERE status = 0");
if ($res && $row = $res->fetch_assoc()) {
    $inactiveBrands = (int)$row['total'];
}

$pageTitle = 'Charger Brands';
$currentPage = 'charger-brands';
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


                <?php if ($success !== ''): ?>
                    <div class="row">
                        <div class="col-12">
                            <div class="alert alert-success alert-dismissible fade show" role="alert">
                                <?php echo h($success); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($error !== ''): ?>
                    <div class="row">
                        <div class="col-12">
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <?php echo h($error); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="row">
                    <div class="col-md-4 col-xl-4">
                        <div class="card text-center">
                            <div class="card-body">
                                <p class="text-muted mb-1">Total Charger Brands</p>
                                <h3 class="text-primary mt-2 mb-0"><?php echo number_format($totalBrands); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4 col-xl-4">
                        <div class="card text-center">
                            <div class="card-body">
                                <p class="text-muted mb-1">Active Brands</p>
                                <h3 class="text-success mt-2 mb-0"><?php echo number_format($activeBrands); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4 col-xl-4">
                        <div class="card text-center">
                            <div class="card-body">
                                <p class="text-muted mb-1">Inactive Brands</p>
                                <h3 class="text-danger mt-2 mb-0"><?php echo number_format($inactiveBrands); ?></h3>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <!-- Add / Edit Form -->
                    <div class="col-xl-4">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">
                                    <?php echo $editBrand ? 'Edit Charger Brand' : 'Add Charger Brand'; ?>
                                </h4>

                                <form method="post" action="">
                                    <input type="hidden" name="brand_id" value="<?php echo (int)($editBrand['id'] ?? 0); ?>">

                                    <div class="mb-3">
                                        <label class="form-label">Charger Brand Name <span class="text-danger">*</span></label>
                                        <input type="text" name="brand_name" class="form-control"
                                               value="<?php echo h($editBrand['brand_name'] ?? ''); ?>" required>
                                    </div>

                                    <div class="form-check mb-3">
                                        <input class="form-check-input" type="checkbox" id="status1" name="status"
                                            <?php echo (!isset($editBrand['status']) || (int)$editBrand['status'] === 1) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="status">Active Brand</label>
                                    </div>

                                    <div class="d-grid gap-2">
                                        <button type="submit" class="btn btn-primary">
                                            <?php echo $editBrand ? 'Update Charger Brand' : 'Add Charger Brand'; ?>
                                        </button>

                                        <?php if ($editBrand): ?>
                                            <a href="charger-brands.php" class="btn btn-secondary">Cancel Edit</a>
                                        <?php endif; ?>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Brand List -->
                    <div class="col-xl-8">
                        <div class="card">
                            <div class="card-body">
                                <form method="get" class="row g-3 align-items-end mb-4">
                                    <div class="col-md-8">
                                        <label class="form-label">Search</label>
                                        <input type="text" name="search" class="form-control"
                                               placeholder="Search charger brand name"
                                               value="<?php echo h($search); ?>">
                                    </div>

                                    <div class="col-md-4">
                                        <label class="form-label">Status</label>
                                        <select name="status_filter" class="form-select">
                                            <option value="">All Status</option>
                                            <option value="1" <?php echo $statusFilter === '1' ? 'selected' : ''; ?>>Active</option>
                                            <option value="0" <?php echo $statusFilter === '0' ? 'selected' : ''; ?>>Inactive</option>
                                        </select>
                                    </div>

                                    <div class="col-md-12">
                                        <div class="d-flex gap-2">
                                            <button type="submit" class="btn btn-primary">Filter</button>
                                            <a href="charger-brands.php" class="btn btn-secondary">Reset</a>
                                        </div>
                                    </div>
                                </form>

                                <div class="table-responsive">
                                    <table class="table table-bordered table-hover align-middle mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th style="width:60px;">#</th>
                                                <th>Charger Brand Name</th>
                                                <th>Status</th>
                                                <th>Created</th>
                                                <th style="width:180px;">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($brands)): ?>
                                                <?php $sno = 1; ?>
                                                <?php foreach ($brands as $row): ?>
                                                    <tr>
                                                        <td><?php echo $sno++; ?></td>
                                                        <td><?php echo h($row['brand_name']); ?></td>
                                                        <td>
                                                            <?php if ((int)$row['status'] === 1): ?>
                                                                <span class="badge bg-success">Active</span>
                                                            <?php else: ?>
                                                                <span class="badge bg-danger">Inactive</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td><?php echo h(date('d M Y', strtotime($row['created_at']))); ?></td>
                                                        <td>
                                                            <div class="d-flex flex-wrap gap-1">
                                                                <a href="charger-brands.php?edit=<?php echo (int)$row['id']; ?>" class="btn btn-primary btn-sm">Edit</a>
                                                                <a href="charger-brands.php?delete=<?php echo (int)$row['id']; ?>"
                                                                   class="btn btn-danger btn-sm"
                                                                   onclick="return confirm('Are you sure you want to delete this charger brand?');">
                                                                    Delete
                                                                </a>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="5" class="text-center text-muted py-4">No charger brands found.</td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>

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