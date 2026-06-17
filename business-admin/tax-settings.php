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
   TABLE CHECK
------------------------------------------------------- */
if (!tableExists($conn, 'tax_settings')) {
    die('tax_settings table not found.');
}

/* -------------------------------------------------------
   ACTIONS
------------------------------------------------------- */
$success = '';
$error = '';

$allowedTaxTypes = ['gst', 'cgst', 'sgst', 'igst', 'cess', 'other'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');

    if ($action === 'add') {
        $tax_name = trim($_POST['tax_name'] ?? '');
        $tax_type = trim($_POST['tax_type'] ?? 'gst');
        $tax_percent = (float)($_POST['tax_percent'] ?? 0);
        $hsn_code = trim($_POST['hsn_code'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $status = isset($_POST['status']) ? (int)$_POST['status'] : 1;

        if ($tax_name === '') {
            $error = 'Tax name is required.';
        } elseif (!in_array($tax_type, $allowedTaxTypes, true)) {
            $error = 'Invalid tax type.';
        } elseif ($tax_percent < 0) {
            $error = 'Tax percent cannot be negative.';
        } else {
            $stmt = $conn->prepare("SELECT id 
                                    FROM tax_settings 
                                    WHERE tax_name = ? AND tax_type = ?
                                    LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('ss', $tax_name, $tax_type);
                $stmt->execute();
                $dup = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($dup) {
                    $error = 'Tax name with this tax type already exists.';
                }
            }
        }

        if ($error === '') {
            $stmt = $conn->prepare("INSERT INTO tax_settings (
                                        tax_name, tax_type, tax_percent, hsn_code, description, status, created_at
                                    ) VALUES (?, ?, ?, ?, ?, ?, NOW())");
            if ($stmt) {
                $stmt->bind_param('ssdssi', $tax_name, $tax_type, $tax_percent, $hsn_code, $description, $status);
                if ($stmt->execute()) {
                    $success = 'Tax setting added successfully.';
                } else {
                    $error = 'Failed to add tax setting.';
                }
                $stmt->close();
            } else {
                $error = 'Unable to prepare insert query.';
            }
        }
    }

    if ($action === 'edit') {
        $id = (int)($_POST['id'] ?? 0);
        $tax_name = trim($_POST['tax_name'] ?? '');
        $tax_type = trim($_POST['tax_type'] ?? 'gst');
        $tax_percent = (float)($_POST['tax_percent'] ?? 0);
        $hsn_code = trim($_POST['hsn_code'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $status = isset($_POST['status']) ? (int)$_POST['status'] : 1;

        if ($id <= 0) {
            $error = 'Invalid tax setting id.';
        } elseif ($tax_name === '') {
            $error = 'Tax name is required.';
        } elseif (!in_array($tax_type, $allowedTaxTypes, true)) {
            $error = 'Invalid tax type.';
        } elseif ($tax_percent < 0) {
            $error = 'Tax percent cannot be negative.';
        } else {
            $stmt = $conn->prepare("SELECT id 
                                    FROM tax_settings 
                                    WHERE tax_name = ? AND tax_type = ? AND id != ?
                                    LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('ssi', $tax_name, $tax_type, $id);
                $stmt->execute();
                $dup = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($dup) {
                    $error = 'Tax name with this tax type already exists.';
                }
            }
        }

        if ($error === '') {
            $stmt = $conn->prepare("UPDATE tax_settings
                                    SET tax_name = ?, tax_type = ?, tax_percent = ?, hsn_code = ?, description = ?, status = ?
                                    WHERE id = ?
                                    LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('ssdssii', $tax_name, $tax_type, $tax_percent, $hsn_code, $description, $status, $id);
                if ($stmt->execute()) {
                    $success = 'Tax setting updated successfully.';
                } else {
                    $error = 'Failed to update tax setting.';
                }
                $stmt->close();
            } else {
                $error = 'Unable to prepare update query.';
            }
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);

        if ($id <= 0) {
            $error = 'Invalid tax setting id.';
        } else {
            $stmt = $conn->prepare("DELETE FROM tax_settings WHERE id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('i', $id);
                if ($stmt->execute()) {
                    $success = 'Tax setting deleted successfully.';
                } else {
                    $error = 'Failed to delete tax setting.';
                }
                $stmt->close();
            } else {
                $error = 'Unable to prepare delete query.';
            }
        }
    }
}

/* -------------------------------------------------------
   FILTER
------------------------------------------------------- */
$search = trim($_GET['search'] ?? '');
$typeFilter = trim($_GET['tax_type'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');

$where = "1=1";

if ($search !== '') {
    $safe = $conn->real_escape_string($search);
    $where .= " AND (
        tax_name LIKE '%{$safe}%'
        OR hsn_code LIKE '%{$safe}%'
        OR description LIKE '%{$safe}%'
    )";
}

if ($typeFilter !== '' && in_array($typeFilter, $allowedTaxTypes, true)) {
    $safeType = $conn->real_escape_string($typeFilter);
    $where .= " AND tax_type = '{$safeType}'";
}

if ($statusFilter !== '') {
    if ($statusFilter === 'active') {
        $where .= " AND status = 1";
    } elseif ($statusFilter === 'inactive') {
        $where .= " AND status = 0";
    }
}

/* -------------------------------------------------------
   SUMMARY
------------------------------------------------------- */
$totalTaxSettings = getCount($conn, 'tax_settings');
$activeTaxSettings = getCount($conn, 'tax_settings', 'status = 1');
$inactiveTaxSettings = getCount($conn, 'tax_settings', 'status = 0');

/* -------------------------------------------------------
   FETCH TAX SETTINGS
------------------------------------------------------- */
$taxRows = [];

$sql = "SELECT id, tax_name, tax_type, tax_percent, hsn_code, description, status, created_at
        FROM tax_settings
        WHERE {$where}
        ORDER BY id DESC";
$res = $conn->query($sql);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $taxRows[] = $row;
    }
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

                <div class="row mb-3">
                    <div class="col-md-6">
                        <h4 class="mb-1">Tax Settings</h4>
                        <p class="text-muted mb-0">Manage GST, CGST, SGST, IGST, CESS and other tax rules</p>
                    </div>
                    <div class="col-md-6 text-md-end mt-3 mt-md-0">
                        <a href="products.php" class="btn btn-secondary">Back to Products</a>
                    </div>
                </div>

                <?php if ($success !== ''): ?>
                    <div class="alert alert-success"><?php echo h($success); ?></div>
                <?php endif; ?>

                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger"><?php echo h($error); ?></div>
                <?php endif; ?>

                <div class="row">
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Total Tax Rules</p>
                                <h3 class="mb-0"><?php echo number_format($totalTaxSettings); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Active</p>
                                <h3 class="mb-0 text-success"><?php echo number_format($activeTaxSettings); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Inactive</p>
                                <h3 class="mb-0 text-warning"><?php echo number_format($inactiveTaxSettings); ?></h3>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <!-- add form -->
                    <div class="col-xl-4">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Add Tax Setting</h4>

                                <form method="post">
                                    <input type="hidden" name="action" value="add">

                                    <div class="mb-3">
                                        <label class="form-label">Tax Name</label>
                                        <input type="text" name="tax_name" class="form-control" placeholder="Example: GST 18%" required>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Tax Type</label>
                                        <select name="tax_type" class="form-select" required>
                                            <option value="gst">GST</option>
                                            <option value="cgst">CGST</option>
                                            <option value="sgst">SGST</option>
                                            <option value="igst">IGST</option>
                                            <option value="cess">CESS</option>
                                            <option value="other">Other</option>
                                        </select>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Tax Percent</label>
                                        <input type="number" step="0.01" min="0" name="tax_percent" class="form-control" value="0.00">
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">HSN Code</label>
                                        <input type="text" name="hsn_code" class="form-control" placeholder="Optional HSN code">
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Description</label>
                                        <textarea name="description" class="form-control" rows="3"></textarea>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Status</label>
                                        <select name="status" class="form-select">
                                            <option value="1">Active</option>
                                            <option value="0">Inactive</option>
                                        </select>
                                    </div>

                                    <button type="submit" class="btn btn-primary">Save Tax Setting</button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- list -->
                    <div class="col-xl-8">
                        <div class="card">
                            <div class="card-body">

                                <form method="get" class="row g-3 mb-4">
                                    <div class="col-md-5">
                                        <input
                                            type="text"
                                            name="search"
                                            class="form-control"
                                            placeholder="Search tax name, HSN or description..."
                                            value="<?php echo h($search); ?>"
                                        >
                                    </div>

                                    <div class="col-md-3">
                                        <select name="tax_type" class="form-select">
                                            <option value="">All Types</option>
                                            <option value="gst" <?php echo ($typeFilter === 'gst') ? 'selected' : ''; ?>>GST</option>
                                            <option value="cgst" <?php echo ($typeFilter === 'cgst') ? 'selected' : ''; ?>>CGST</option>
                                            <option value="sgst" <?php echo ($typeFilter === 'sgst') ? 'selected' : ''; ?>>SGST</option>
                                            <option value="igst" <?php echo ($typeFilter === 'igst') ? 'selected' : ''; ?>>IGST</option>
                                            <option value="cess" <?php echo ($typeFilter === 'cess') ? 'selected' : ''; ?>>CESS</option>
                                            <option value="other" <?php echo ($typeFilter === 'other') ? 'selected' : ''; ?>>Other</option>
                                        </select>
                                    </div>

                                    <div class="col-md-2">
                                        <select name="status" class="form-select">
                                            <option value="">All</option>
                                            <option value="active" <?php echo ($statusFilter === 'active') ? 'selected' : ''; ?>>Active</option>
                                            <option value="inactive" <?php echo ($statusFilter === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                                        </select>
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
                                                <th>Tax Name</th>
                                                <th>Type</th>
                                                <th>%</th>
                                                <th>HSN</th>
                                                <th>Status</th>
                                                <th style="width:420px;">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($taxRows)): ?>
                                                <?php $i = 1; foreach ($taxRows as $row): ?>
                                                    <tr>
                                                        <td><?php echo $i++; ?></td>
                                                        <td>
                                                            <strong><?php echo h($row['tax_name']); ?></strong>
                                                            <?php if (!empty($row['description'])): ?>
                                                                <div class="small text-muted"><?php echo h($row['description']); ?></div>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td><?php echo h(strtoupper($row['tax_type'])); ?></td>
                                                        <td><?php echo number_format((float)$row['tax_percent'], 2); ?>%</td>
                                                        <td><?php echo h($row['hsn_code'] ?: '-'); ?></td>
                                                        <td>
                                                            <?php if ((int)$row['status'] === 1): ?>
                                                                <span class="badge bg-success">Active</span>
                                                            <?php else: ?>
                                                                <span class="badge bg-warning">Inactive</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <form method="post" class="row g-2 align-items-center">
                                                                <input type="hidden" name="action" value="edit">
                                                                <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">

                                                                <div class="col-md-3">
                                                                    <input type="text" name="tax_name" class="form-control form-control-sm" value="<?php echo h($row['tax_name']); ?>" required>
                                                                </div>

                                                                <div class="col-md-2">
                                                                    <select name="tax_type" class="form-select form-select-sm">
                                                                        <?php foreach ($allowedTaxTypes as $type): ?>
                                                                            <option value="<?php echo h($type); ?>" <?php echo ($row['tax_type'] === $type) ? 'selected' : ''; ?>>
                                                                                <?php echo h(strtoupper($type)); ?>
                                                                            </option>
                                                                        <?php endforeach; ?>
                                                                    </select>
                                                                </div>

                                                                <div class="col-md-2">
                                                                    <input type="number" step="0.01" min="0" name="tax_percent" class="form-control form-control-sm" value="<?php echo h($row['tax_percent']); ?>">
                                                                </div>

                                                                <div class="col-md-2">
                                                                    <input type="text" name="hsn_code" class="form-control form-control-sm" value="<?php echo h($row['hsn_code']); ?>">
                                                                </div>

                                                                <div class="col-md-3">
                                                                    <input type="text" name="description" class="form-control form-control-sm" value="<?php echo h($row['description']); ?>">
                                                                </div>

                                                                <div class="col-md-2">
                                                                    <select name="status" class="form-select form-select-sm">
                                                                        <option value="1" <?php echo ((int)$row['status'] === 1) ? 'selected' : ''; ?>>Active</option>
                                                                        <option value="0" <?php echo ((int)$row['status'] === 0) ? 'selected' : ''; ?>>Inactive</option>
                                                                    </select>
                                                                </div>

                                                                <div class="col-md-10 d-flex gap-2">
                                                                    <button type="submit" class="btn btn-sm btn-primary">Update</button>
                                                            </form>

                                                            <form method="post" onsubmit="return confirm('Delete this tax setting?');">
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