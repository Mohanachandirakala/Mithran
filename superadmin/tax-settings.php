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
    CREATE TABLE IF NOT EXISTS tax_settings (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        tax_name VARCHAR(100) NOT NULL,
        tax_type ENUM('gst','cgst','sgst','igst','cess','other') NOT NULL DEFAULT 'gst',
        tax_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
        hsn_code VARCHAR(50) DEFAULT NULL,
        description VARCHAR(255) DEFAULT NULL,
        status TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_tax_name_type (tax_name, tax_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$success = '';
$error   = '';

/* -------------------------------------------------------
   ADD / UPDATE TAX
------------------------------------------------------- */
$editId = (int)($_GET['edit'] ?? 0);
$editTax = null;

if ($editId > 0) {
    $stmt = $conn->prepare("
        SELECT id, tax_name, tax_type, tax_percent, hsn_code, description, status
        FROM tax_settings
        WHERE id = ?
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param("i", $editId);
        $stmt->execute();
        $result = $stmt->get_result();
        $editTax = $result ? $result->fetch_assoc() : null;
        $stmt->close();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id          = (int)($_POST['id'] ?? 0);
    $tax_name    = trim($_POST['tax_name'] ?? '');
    $tax_type    = trim($_POST['tax_type'] ?? 'gst');
    $tax_percent = (float)($_POST['tax_percent'] ?? 0);
    $hsn_code    = trim($_POST['hsn_code'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $status      = isset($_POST['status']) ? 1 : 0;

    if ($tax_name === '') {
        $error = 'Tax name is required.';
    } elseif (!in_array($tax_type, ['gst', 'cgst', 'sgst', 'igst', 'cess', 'other'], true)) {
        $error = 'Invalid tax type.';
    } elseif ($tax_percent < 0) {
        $error = 'Tax percent cannot be negative.';
    } else {
        if ($id > 0) {
            $check = $conn->prepare("
                SELECT id
                FROM tax_settings
                WHERE tax_name = ? AND tax_type = ? AND id != ?
                LIMIT 1
            ");
            if ($check) {
                $check->bind_param("ssi", $tax_name, $tax_type, $id);
                $check->execute();
                $res = $check->get_result();
                $exists = $res ? $res->fetch_assoc() : null;
                $check->close();

                if ($exists) {
                    $error = 'This tax setting already exists.';
                } else {
                    $stmt = $conn->prepare("
                        UPDATE tax_settings
                        SET tax_name = ?, tax_type = ?, tax_percent = ?, hsn_code = ?, description = ?, status = ?
                        WHERE id = ?
                        LIMIT 1
                    ");
                    if ($stmt) {
                        $stmt->bind_param("ssdssii", $tax_name, $tax_type, $tax_percent, $hsn_code, $description, $status, $id);
                        if ($stmt->execute()) {
                            $success = 'Tax setting updated successfully.';
                            $editTax = null;
                            unset($_GET['edit']);
                        } else {
                            $error = 'Failed to update tax setting.';
                        }
                        $stmt->close();
                    } else {
                        $error = 'Database error while updating tax setting.';
                    }
                }
            } else {
                $error = 'Database error while checking tax setting.';
            }
        } else {
            $check = $conn->prepare("
                SELECT id
                FROM tax_settings
                WHERE tax_name = ? AND tax_type = ?
                LIMIT 1
            ");
            if ($check) {
                $check->bind_param("ss", $tax_name, $tax_type);
                $check->execute();
                $res = $check->get_result();
                $exists = $res ? $res->fetch_assoc() : null;
                $check->close();

                if ($exists) {
                    $error = 'This tax setting already exists.';
                } else {
                    $stmt = $conn->prepare("
                        INSERT INTO tax_settings (
                            tax_name, tax_type, tax_percent, hsn_code, description, status, created_at
                        ) VALUES (?, ?, ?, ?, ?, ?, NOW())
                    ");
                    if ($stmt) {
                        $stmt->bind_param("ssdssi", $tax_name, $tax_type, $tax_percent, $hsn_code, $description, $status);
                        if ($stmt->execute()) {
                            $success = 'Tax setting added successfully.';
                        } else {
                            $error = 'Failed to add tax setting.';
                        }
                        $stmt->close();
                    } else {
                        $error = 'Database error while adding tax setting.';
                    }
                }
            } else {
                $error = 'Database error while checking tax setting.';
            }
        }
    }
}

/* -------------------------------------------------------
   DELETE TAX
------------------------------------------------------- */
if (isset($_GET['delete']) && $_GET['delete'] !== '') {
    $deleteId = (int)$_GET['delete'];

    if ($deleteId > 0) {
        $stmt = $conn->prepare("DELETE FROM tax_settings WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("i", $deleteId);
            if ($stmt->execute()) {
                $success = 'Tax setting deleted successfully.';
            } else {
                $error = 'Failed to delete tax setting.';
            }
            $stmt->close();
        } else {
            $error = 'Database error while deleting tax setting.';
        }
    }
}

/* -------------------------------------------------------
   FILTERS
------------------------------------------------------- */
$search = trim($_GET['search'] ?? '');
$type_filter = trim($_GET['type_filter'] ?? '');
$status_filter = trim($_GET['status_filter'] ?? '');

$where  = " WHERE 1=1 ";
$params = [];
$types  = "";

if ($search !== '') {
    $where .= " AND (tax_name LIKE ? OR hsn_code LIKE ? OR description LIKE ?) ";
    $like = "%{$search}%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= "sss";
}

if ($type_filter !== '' && in_array($type_filter, ['gst', 'cgst', 'sgst', 'igst', 'cess', 'other'], true)) {
    $where .= " AND tax_type = ? ";
    $params[] = $type_filter;
    $types .= "s";
}

if ($status_filter !== '' && in_array($status_filter, ['1', '0'], true)) {
    $where .= " AND status = ? ";
    $params[] = (int)$status_filter;
    $types .= "i";
}

/* -------------------------------------------------------
   FETCH TAX SETTINGS
------------------------------------------------------- */
$taxRows = [];

$sql = "
    SELECT id, tax_name, tax_type, tax_percent, hsn_code, description, status, created_at
    FROM tax_settings
    {$where}
    ORDER BY tax_type ASC, tax_percent ASC, tax_name ASC
";

$stmt = $conn->prepare($sql);
if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $taxRows[] = $row;
        }
    }
    $stmt->close();
}

/* -------------------------------------------------------
   COUNTS
------------------------------------------------------- */
$totalTaxes = 0;
$activeTaxes = 0;
$inactiveTaxes = 0;
$gstTaxes = 0;

$res = $conn->query("SELECT COUNT(*) AS total FROM tax_settings");
if ($res && $row = $res->fetch_assoc()) {
    $totalTaxes = (int)$row['total'];
}

$res = $conn->query("SELECT COUNT(*) AS total FROM tax_settings WHERE status = 1");
if ($res && $row = $res->fetch_assoc()) {
    $activeTaxes = (int)$row['total'];
}

$res = $conn->query("SELECT COUNT(*) AS total FROM tax_settings WHERE status = 0");
if ($res && $row = $res->fetch_assoc()) {
    $inactiveTaxes = (int)$row['total'];
}

$res = $conn->query("SELECT COUNT(*) AS total FROM tax_settings WHERE tax_type IN ('gst','cgst','sgst','igst')");
if ($res && $row = $res->fetch_assoc()) {
    $gstTaxes = (int)$row['total'];
}

$pageTitle = 'Tax Settings';
$currentPage = 'tax-settings';
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
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <p class="text-muted mb-1">Total Taxes</p>
                                <h3 class="text-primary mt-2 mb-0"><?php echo number_format($totalTaxes); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <p class="text-muted mb-1">Active</p>
                                <h3 class="text-success mt-2 mb-0"><?php echo number_format($activeTaxes); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <p class="text-muted mb-1">Inactive</p>
                                <h3 class="text-danger mt-2 mb-0"><?php echo number_format($inactiveTaxes); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <p class="text-muted mb-1">GST Family</p>
                                <h3 class="text-info mt-2 mb-0"><?php echo number_format($gstTaxes); ?></h3>
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
                                    <?php echo $editTax ? 'Edit Tax Setting' : 'Add Tax Setting'; ?>
                                </h4>

                                <form method="post" action="">
                                    <input type="hidden" name="id" value="<?php echo (int)($editTax['id'] ?? 0); ?>">

                                    <div class="mb-3">
                                        <label class="form-label">Tax Name <span class="text-danger">*</span></label>
                                        <input type="text" name="tax_name" class="form-control"
                                               value="<?php echo h($editTax['tax_name'] ?? ''); ?>" required>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Tax Type <span class="text-danger">*</span></label>
                                        <select name="tax_type" class="form-select" required>
                                            <option value="gst" <?php echo (($editTax['tax_type'] ?? 'gst') === 'gst') ? 'selected' : ''; ?>>GST</option>
                                            <option value="cgst" <?php echo (($editTax['tax_type'] ?? '') === 'cgst') ? 'selected' : ''; ?>>CGST</option>
                                            <option value="sgst" <?php echo (($editTax['tax_type'] ?? '') === 'sgst') ? 'selected' : ''; ?>>SGST</option>
                                            <option value="igst" <?php echo (($editTax['tax_type'] ?? '') === 'igst') ? 'selected' : ''; ?>>IGST</option>
                                            <option value="cess" <?php echo (($editTax['tax_type'] ?? '') === 'cess') ? 'selected' : ''; ?>>Cess</option>
                                            <option value="other" <?php echo (($editTax['tax_type'] ?? '') === 'other') ? 'selected' : ''; ?>>Other</option>
                                        </select>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Tax Percent</label>
                                        <input type="number" step="0.01" min="0" name="tax_percent" class="form-control"
                                               value="<?php echo h($editTax['tax_percent'] ?? '0.00'); ?>">
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">HSN Code</label>
                                        <input type="text" name="hsn_code" class="form-control"
                                               value="<?php echo h($editTax['hsn_code'] ?? ''); ?>">
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Description</label>
                                        <textarea name="description" class="form-control" rows="3"><?php echo h($editTax['description'] ?? ''); ?></textarea>
                                    </div>

                                    <div class="form-check mb-3">
                                        <input class="form-check-input" type="checkbox" id="status1" name="status"
                                            <?php echo (!isset($editTax['status']) || (int)$editTax['status'] === 1) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="status">Active Tax</label>
                                    </div>

                                    <div class="d-grid gap-2">
                                        <button type="submit" class="btn btn-primary">
                                            <?php echo $editTax ? 'Update Tax Setting' : 'Add Tax Setting'; ?>
                                        </button>

                                        <?php if ($editTax): ?>
                                            <a href="tax-settings.php" class="btn btn-secondary">Cancel Edit</a>
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
                                    <div class="col-md-5">
                                        <label class="form-label">Search</label>
                                        <input type="text" name="search" class="form-control"
                                               placeholder="Search tax, HSN or description"
                                               value="<?php echo h($search); ?>">
                                    </div>

                                    <div class="col-md-3">
                                        <label class="form-label">Tax Type</label>
                                        <select name="type_filter" class="form-select">
                                            <option value="">All Types</option>
                                            <option value="gst" <?php echo $type_filter === 'gst' ? 'selected' : ''; ?>>GST</option>
                                            <option value="cgst" <?php echo $type_filter === 'cgst' ? 'selected' : ''; ?>>CGST</option>
                                            <option value="sgst" <?php echo $type_filter === 'sgst' ? 'selected' : ''; ?>>SGST</option>
                                            <option value="igst" <?php echo $type_filter === 'igst' ? 'selected' : ''; ?>>IGST</option>
                                            <option value="cess" <?php echo $type_filter === 'cess' ? 'selected' : ''; ?>>Cess</option>
                                            <option value="other" <?php echo $type_filter === 'other' ? 'selected' : ''; ?>>Other</option>
                                        </select>
                                    </div>

                                    <div class="col-md-2">
                                        <label class="form-label">Status</label>
                                        <select name="status_filter" class="form-select">
                                            <option value="">All</option>
                                            <option value="1" <?php echo $status_filter === '1' ? 'selected' : ''; ?>>Active</option>
                                            <option value="0" <?php echo $status_filter === '0' ? 'selected' : ''; ?>>Inactive</option>
                                        </select>
                                    </div>

                                    <div class="col-md-2">
                                        <div class="d-flex gap-2">
                                            <button type="submit" class="btn btn-primary">Filter</button>
                                            <a href="tax-settings.php" class="btn btn-secondary">Reset</a>
                                        </div>
                                    </div>
                                </form>

                                <div class="table-responsive">
                                    <table class="table table-bordered table-hover align-middle mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th style="width:60px;">#</th>
                                                <th>Tax Name</th>
                                                <th>Type</th>
                                                <th>Percent</th>
                                                <th>HSN</th>
                                                <th>Status</th>
                                                <th style="width:180px;">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($taxRows)): ?>
                                                <?php $sno = 1; ?>
                                                <?php foreach ($taxRows as $row): ?>
                                                    <tr>
                                                        <td><?php echo $sno++; ?></td>
                                                        <td>
                                                            <div><strong><?php echo h($row['tax_name']); ?></strong></div>
                                                            <small class="text-muted"><?php echo h($row['description'] ?: '-'); ?></small>
                                                        </td>
                                                        <td><?php echo h(strtoupper($row['tax_type'])); ?></td>
                                                        <td><?php echo number_format((float)$row['tax_percent'], 2); ?>%</td>
                                                        <td><?php echo h($row['hsn_code'] ?: '-'); ?></td>
                                                        <td>
                                                            <?php if ((int)$row['status'] === 1): ?>
                                                                <span class="badge bg-success">Active</span>
                                                            <?php else: ?>
                                                                <span class="badge bg-danger">Inactive</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <div class="d-flex flex-wrap gap-1">
                                                                <a href="tax-settings.php?edit=<?php echo (int)$row['id']; ?>" class="btn btn-primary btn-sm">Edit</a>
                                                                <a href="tax-settings.php?delete=<?php echo (int)$row['id']; ?>"
                                                                   class="btn btn-danger btn-sm"
                                                                   onclick="return confirm('Are you sure you want to delete this tax setting?');">
                                                                    Delete
                                                                </a>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="7" class="text-center text-muted py-4">No tax settings found.</td>
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