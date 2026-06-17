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

/* -------------------------------------------------------
   CREATE TABLE IF NOT EXISTS
------------------------------------------------------- */
$conn->query("
    CREATE TABLE IF NOT EXISTS hsn_codes (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        hsn_code VARCHAR(50) NOT NULL UNIQUE,
        description VARCHAR(255) DEFAULT NULL,
        tax_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
        cess_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
        status TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$success = '';
$error   = '';

/* -------------------------------------------------------
   ADD / UPDATE HSN
------------------------------------------------------- */
$editId = (int)($_GET['edit'] ?? 0);
$editHsn = null;

if ($editId > 0) {
    $stmt = $conn->prepare("SELECT id, hsn_code, description, tax_percent, cess_percent, status FROM hsn_codes WHERE id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("i", $editId);
        $stmt->execute();
        $result = $stmt->get_result();
        $editHsn = $result ? $result->fetch_assoc() : null;
        $stmt->close();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id          = (int)($_POST['id'] ?? 0);
    $hsn_code    = trim($_POST['hsn_code'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $tax_percent = (float)($_POST['tax_percent'] ?? 0);
    $cess_percent = (float)($_POST['cess_percent'] ?? 0);
    $status      = isset($_POST['status']) ? 1 : 0;

    if ($hsn_code === '') {
        $error = 'HSN code is required.';
    } else {
        if ($id > 0) {
            $check = $conn->prepare("SELECT id FROM hsn_codes WHERE hsn_code = ? AND id != ? LIMIT 1");
            if ($check) {
                $check->bind_param("si", $hsn_code, $id);
                $check->execute();
                $res = $check->get_result();
                $exists = $res ? $res->fetch_assoc() : null;
                $check->close();

                if ($exists) {
                    $error = 'HSN code already exists.';
                } else {
                    $stmt = $conn->prepare("
                        UPDATE hsn_codes 
                        SET hsn_code = ?, description = ?, tax_percent = ?, cess_percent = ?, status = ?
                        WHERE id = ?
                        LIMIT 1
                    ");
                    if ($stmt) {
                        $stmt->bind_param("ssddii", $hsn_code, $description, $tax_percent, $cess_percent, $status, $id);
                        if ($stmt->execute()) {
                            $success = 'HSN code updated successfully.';
                            $editHsn = null;
                            unset($_GET['edit']);
                        } else {
                            $error = 'Failed to update HSN code.';
                        }
                        $stmt->close();
                    } else {
                        $error = 'Database error while updating HSN code.';
                    }
                }
            }
        } else {
            $check = $conn->prepare("SELECT id FROM hsn_codes WHERE hsn_code = ? LIMIT 1");
            if ($check) {
                $check->bind_param("s", $hsn_code);
                $check->execute();
                $res = $check->get_result();
                $exists = $res ? $res->fetch_assoc() : null;
                $check->close();

                if ($exists) {
                    $error = 'HSN code already exists.';
                } else {
                    $stmt = $conn->prepare("
                        INSERT INTO hsn_codes (hsn_code, description, tax_percent, cess_percent, status, created_at)
                        VALUES (?, ?, ?, ?, ?, NOW())
                    ");
                    if ($stmt) {
                        $stmt->bind_param("ssddi", $hsn_code, $description, $tax_percent, $cess_percent, $status);
                        if ($stmt->execute()) {
                            $success = 'HSN code added successfully.';
                        } else {
                            $error = 'Failed to add HSN code.';
                        }
                        $stmt->close();
                    } else {
                        $error = 'Database error while adding HSN code.';
                    }
                }
            }
        }
    }
}

/* -------------------------------------------------------
   DELETE HSN
------------------------------------------------------- */
if (isset($_GET['delete']) && $_GET['delete'] !== '') {
    $deleteId = (int)$_GET['delete'];

    if ($deleteId > 0) {
        $stmt = $conn->prepare("DELETE FROM hsn_codes WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("i", $deleteId);
            if ($stmt->execute()) {
                $success = 'HSN code deleted successfully.';
            } else {
                $error = 'Failed to delete HSN code.';
            }
            $stmt->close();
        } else {
            $error = 'Database error while deleting HSN code.';
        }
    }
}

/* -------------------------------------------------------
   FILTERS
------------------------------------------------------- */
$search = trim($_GET['search'] ?? '');
$status_filter = trim($_GET['status_filter'] ?? '');

$where = " WHERE 1=1 ";
$params = [];
$types = "";

if ($search !== '') {
    $where .= " AND (hsn_code LIKE ? OR description LIKE ?) ";
    $like = "%{$search}%";
    $params[] = $like;
    $params[] = $like;
    $types .= "ss";
}

if ($status_filter !== '' && in_array($status_filter, ['1', '0'], true)) {
    $where .= " AND status = ? ";
    $params[] = (int)$status_filter;
    $types .= "i";
}

/* -------------------------------------------------------
   FETCH HSN CODES
------------------------------------------------------- */
$hsnCodes = [];

$sql = "SELECT id, hsn_code, description, tax_percent, cess_percent, status, created_at FROM hsn_codes {$where} ORDER BY hsn_code ASC";
$stmt = $conn->prepare($sql);
if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $hsnCodes[] = $row;
        }
    }
    $stmt->close();
}

/* -------------------------------------------------------
   COUNTS
------------------------------------------------------- */
$totalCodes = 0;
$activeCodes = 0;
$inactiveCodes = 0;

$res = $conn->query("SELECT COUNT(*) AS total FROM hsn_codes");
if ($res && $row = $res->fetch_assoc()) {
    $totalCodes = (int)$row['total'];
}

$res = $conn->query("SELECT COUNT(*) AS total FROM hsn_codes WHERE status = 1");
if ($res && $row = $res->fetch_assoc()) {
    $activeCodes = (int)$row['total'];
}

$res = $conn->query("SELECT COUNT(*) AS total FROM hsn_codes WHERE status = 0");
if ($res && $row = $res->fetch_assoc()) {
    $inactiveCodes = (int)$row['total'];
}

$pageTitle = 'HSN Codes';
$currentPage = 'hsn-codes';
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
                                <p class="text-muted mb-1">Total HSN Codes</p>
                                <h3 class="text-primary mt-2 mb-0"><?php echo number_format($totalCodes); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4 col-xl-4">
                        <div class="card text-center">
                            <div class="card-body">
                                <p class="text-muted mb-1">Active Codes</p>
                                <h3 class="text-success mt-2 mb-0"><?php echo number_format($activeCodes); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4 col-xl-4">
                        <div class="card text-center">
                            <div class="card-body">
                                <p class="text-muted mb-1">Inactive Codes</p>
                                <h3 class="text-danger mt-2 mb-0"><?php echo number_format($inactiveCodes); ?></h3>
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
                                    <?php echo $editHsn ? 'Edit HSN Code' : 'Add HSN Code'; ?>
                                </h4>

                                <form method="post" action="">
                                    <input type="hidden" name="id" value="<?php echo (int)($editHsn['id'] ?? 0); ?>">

                                    <div class="mb-3">
                                        <label class="form-label">HSN Code <span class="text-danger">*</span></label>
                                        <input type="text" name="hsn_code" class="form-control"
                                               value="<?php echo h($editHsn['hsn_code'] ?? ''); ?>" required>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Description</label>
                                        <input type="text" name="description" class="form-control"
                                               value="<?php echo h($editHsn['description'] ?? ''); ?>">
                                    </div>

                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Tax %</label>
                                                <input type="number" step="0.01" name="tax_percent" class="form-control"
                                                       value="<?php echo h($editHsn['tax_percent'] ?? '0.00'); ?>">
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Cess %</label>
                                                <input type="number" step="0.01" name="cess_percent" class="form-control"
                                                       value="<?php echo h($editHsn['cess_percent'] ?? '0.00'); ?>">
                                            </div>
                                        </div>
                                    </div>

                                    <div class="form-check mb-3">
                                        <input class="form-check-input" type="checkbox" id="status1" name="status"
                                            <?php echo (!isset($editHsn['status']) || (int)$editHsn['status'] === 1) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="status">Active HSN Code</label>
                                    </div>

                                    <div class="d-grid gap-2">
                                        <button type="submit" class="btn btn-primary">
                                            <?php echo $editHsn ? 'Update HSN Code' : 'Add HSN Code'; ?>
                                        </button>

                                        <?php if ($editHsn): ?>
                                            <a href="hsn-codes.php" class="btn btn-secondary">Cancel Edit</a>
                                        <?php endif; ?>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- List -->
                    <div class="col-xl-8">
                        <div class="card">
                            <div class="card-body">
                                <form method="get" class="row g-3 align-items-end mb-4">
                                    <div class="col-md-8">
                                        <label class="form-label">Search</label>
                                        <input type="text" name="search" class="form-control"
                                               placeholder="Search HSN code or description"
                                               value="<?php echo h($search); ?>">
                                    </div>

                                    <div class="col-md-4">
                                        <label class="form-label">Status</label>
                                        <select name="status_filter" class="form-select">
                                            <option value="">All Status</option>
                                            <option value="1" <?php echo $status_filter === '1' ? 'selected' : ''; ?>>Active</option>
                                            <option value="0" <?php echo $status_filter === '0' ? 'selected' : ''; ?>>Inactive</option>
                                        </select>
                                    </div>

                                    <div class="col-md-12">
                                        <div class="d-flex gap-2">
                                            <button type="submit" class="btn btn-primary">Filter</button>
                                            <a href="hsn-codes.php" class="btn btn-secondary">Reset</a>
                                        </div>
                                    </div>
                                </form>

                                <div class="table-responsive">
                                    <table class="table table-bordered table-hover align-middle mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th style="width:60px;">#</th>
                                                <th>HSN Code</th>
                                                <th>Description</th>
                                                <th>Tax %</th>
                                                <th>Cess %</th>
                                                <th>Status</th>
                                                <th style="width:180px;">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($hsnCodes)): ?>
                                                <?php $sno = 1; ?>
                                                <?php foreach ($hsnCodes as $row): ?>
                                                    <tr>
                                                        <td><?php echo $sno++; ?></td>
                                                        <td><strong><?php echo h($row['hsn_code']); ?></strong></td>
                                                        <td><?php echo h($row['description'] ?: '-'); ?></td>
                                                        <td><?php echo number_format((float)$row['tax_percent'], 2); ?>%</td>
                                                        <td><?php echo number_format((float)$row['cess_percent'], 2); ?>%</td>
                                                        <td>
                                                            <?php if ((int)$row['status'] === 1): ?>
                                                                <span class="badge bg-success">Active</span>
                                                            <?php else: ?>
                                                                <span class="badge bg-danger">Inactive</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <div class="d-flex flex-wrap gap-1">
                                                                <a href="hsn-codes.php?edit=<?php echo (int)$row['id']; ?>" class="btn btn-primary btn-sm">Edit</a>
                                                                <a href="hsn-codes.php?delete=<?php echo (int)$row['id']; ?>"
                                                                   class="btn btn-danger btn-sm"
                                                                   onclick="return confirm('Are you sure you want to delete this HSN code?');">
                                                                    Delete
                                                                </a>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="7" class="text-center text-muted py-4">No HSN codes found.</td>
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