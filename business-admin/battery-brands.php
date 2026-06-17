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
if (!tableExists($conn, 'battery_brands')) {
    die('battery_brands table not found.');
}

/* -------------------------------------------------------
   BULK IMPORT PROCESSING
------------------------------------------------------- */
$bulkResults = null;
$importSuccess = '';
$importError = '';
$showBulkModal = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bulk_import') {
    $importSuccessCount = 0;
    $importErrors = [];
    $importSkipped = 0;
    
    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $importError = 'Please select a valid CSV file.';
        $showBulkModal = true;
    } else {
        $file = $_FILES['csv_file'];
        $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if ($fileExt !== 'csv') {
            $importError = 'Only CSV files are allowed.';
            $showBulkModal = true;
        } elseif ($file['size'] > 5 * 1024 * 1024) {
            $importError = 'File size exceeds 5MB limit.';
            $showBulkModal = true;
        } else {
            $handle = fopen($file['tmp_name'], 'r');
            
            if ($handle !== false) {
                $firstLine = fgets($handle);
                rewind($handle);
                $delimiter = (substr_count($firstLine, ',') > substr_count($firstLine, ';')) ? ',' : ';';
                
                $headers = fgetcsv($handle, 0, $delimiter);
                
                if ($headers === false) {
                    $importError = 'CSV file is empty.';
                    $showBulkModal = true;
                } else {
                    $headers = array_map(function($h) {
                        return strtolower(trim($h));
                    }, $headers);
                    
                    $brandNameIndex = null;
                    $statusIndex = null;
                    
                    foreach ($headers as $idx => $header) {
                        $cleanHeader = preg_replace('/[^a-z]/', '', $header);
                        if (in_array($cleanHeader, ['brandname', 'brand', 'name', 'batteryname'])) {
                            $brandNameIndex = $idx;
                        }
                        if (in_array($cleanHeader, ['status', 'active'])) {
                            $statusIndex = $idx;
                        }
                    }
                    
                    if ($brandNameIndex === null && count($headers) > 0) {
                        $brandNameIndex = 0;
                    }
                    
                    if ($brandNameIndex === null) {
                        $importError = 'Could not find brand name column.';
                        $showBulkModal = true;
                    } else {
                        $conn->begin_transaction();
                        
                        try {
                            $rowNumber = 1;
                            
                            while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
                                $rowNumber++;
                                
                                $brandName = isset($row[$brandNameIndex]) ? trim($row[$brandNameIndex]) : '';
                                
                                if ($brandName === '') {
                                    continue;
                                }
                                
                                $status = 1;
                                if ($statusIndex !== null && isset($row[$statusIndex])) {
                                    $statusValue = strtolower(trim($row[$statusIndex]));
                                    if (in_array($statusValue, ['0', 'inactive', 'no', 'false'])) {
                                        $status = 0;
                                    }
                                }
                                
                                $checkStmt = $conn->prepare("SELECT id FROM battery_brands WHERE brand_name = ? LIMIT 1");
                                $checkStmt->bind_param('s', $brandName);
                                $checkStmt->execute();
                                $exists = $checkStmt->get_result()->fetch_assoc();
                                $checkStmt->close();
                                
                                if ($exists) {
                                    $importSkipped++;
                                    continue;
                                }
                                
                                $stmt = $conn->prepare("INSERT INTO battery_brands (brand_name, status, created_at) VALUES (?, ?, NOW())");
                                if ($stmt) {
                                    $stmt->bind_param('si', $brandName, $status);
                                    if ($stmt->execute()) {
                                        $importSuccessCount++;
                                    } else {
                                        $importErrors[] = "Row {$rowNumber}: Failed to insert '{$brandName}'";
                                        $importSkipped++;
                                    }
                                    $stmt->close();
                                }
                            }
                            
                            $conn->commit();
                            
                            $importSuccess = "Successfully imported {$importSuccessCount} battery brands.";
                            if ($importSkipped > 0) {
                                $importSuccess .= " ({$importSkipped} skipped - may already exist)";
                            }
                            
                        } catch (Exception $e) {
                            $conn->rollback();
                            $importError = 'Import failed: ' . $e->getMessage();
                            $showBulkModal = true;
                        }
                    }
                }
                fclose($handle);
            } else {
                $importError = 'Unable to read the CSV file.';
                $showBulkModal = true;
            }
        }
    }
    
    $bulkResults = [
        'success' => $importSuccessCount,
        'errors' => $importErrors,
        'skipped' => $importSkipped
    ];
}

/* -------------------------------------------------------
   ACTIONS (ADD/EDIT/DELETE)
------------------------------------------------------- */
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!isset($_POST['action']) || $_POST['action'] !== 'bulk_import')) {
    $action = trim($_POST['action'] ?? '');

    if ($action === 'add') {
        $brand_name = trim($_POST['brand_name'] ?? '');
        $status = isset($_POST['status']) ? (int)$_POST['status'] : 1;

        if ($brand_name === '') {
            $error = 'Battery brand name is required.';
        } else {
            $stmt = $conn->prepare("SELECT id FROM battery_brands WHERE brand_name = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('s', $brand_name);
                $stmt->execute();
                $dup = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($dup) {
                    $error = 'Battery brand already exists.';
                }
            }
        }

        if ($error === '') {
            $stmt = $conn->prepare("INSERT INTO battery_brands (brand_name, status, created_at) VALUES (?, ?, NOW())");
            if ($stmt) {
                $stmt->bind_param('si', $brand_name, $status);
                if ($stmt->execute()) {
                    $success = 'Battery brand added successfully.';
                } else {
                    $error = 'Failed to add battery brand.';
                }
                $stmt->close();
            } else {
                $error = 'Unable to prepare insert query.';
            }
        }
    }

    if ($action === 'edit') {
        $id = (int)($_POST['id'] ?? 0);
        $brand_name = trim($_POST['brand_name'] ?? '');
        $status = isset($_POST['status']) ? (int)$_POST['status'] : 1;

        if ($id <= 0) {
            $error = 'Invalid brand id.';
        } elseif ($brand_name === '') {
            $error = 'Battery brand name is required.';
        } else {
            $stmt = $conn->prepare("SELECT id FROM battery_brands WHERE brand_name = ? AND id != ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('si', $brand_name, $id);
                $stmt->execute();
                $dup = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($dup) {
                    $error = 'Battery brand already exists.';
                }
            }
        }

        if ($error === '') {
            $stmt = $conn->prepare("UPDATE battery_brands SET brand_name = ?, status = ? WHERE id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('sii', $brand_name, $status, $id);
                if ($stmt->execute()) {
                    $success = 'Battery brand updated successfully.';
                } else {
                    $error = 'Failed to update battery brand.';
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
            $error = 'Invalid brand id.';
        } else {
            $usedInModels = 0;
            $usedInStock = 0;

            if (tableExists($conn, 'vehicle_models')) {
                $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM vehicle_models WHERE battery_brand = (SELECT brand_name FROM battery_brands WHERE id = ? LIMIT 1)");
                if ($stmt) {
                    $stmt->bind_param('i', $id);
                    $stmt->execute();
                    $row = $stmt->get_result()->fetch_assoc();
                    $usedInModels = (int)($row['total'] ?? 0);
                    $stmt->close();
                }
            }

            if (tableExists($conn, 'vehicle_stock')) {
                $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM vehicle_stock WHERE battery_brand = (SELECT brand_name FROM battery_brands WHERE id = ? LIMIT 1)");
                if ($stmt) {
                    $stmt->bind_param('i', $id);
                    $stmt->execute();
                    $row = $stmt->get_result()->fetch_assoc();
                    $usedInStock = (int)($row['total'] ?? 0);
                    $stmt->close();
                }
            }

            if ($usedInModels > 0 || $usedInStock > 0) {
                $error = 'Cannot delete this battery brand because it is linked to vehicle models or stock.';
            } else {
                $stmt = $conn->prepare("DELETE FROM battery_brands WHERE id = ? LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param('i', $id);
                    if ($stmt->execute()) {
                        $success = 'Battery brand deleted successfully.';
                    } else {
                        $error = 'Failed to delete battery brand.';
                    }
                    $stmt->close();
                } else {
                    $error = 'Unable to prepare delete query.';
                }
            }
        }
    }
}

/* -------------------------------------------------------
   FILTER
------------------------------------------------------- */
$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');

$where = "1=1";

if ($search !== '') {
    $safe = $conn->real_escape_string($search);
    $where .= " AND bb.brand_name LIKE '%{$safe}%'";
}

if ($statusFilter !== '') {
    if ($statusFilter === 'active') {
        $where .= " AND bb.status = 1";
    } elseif ($statusFilter === 'inactive') {
        $where .= " AND bb.status = 0";
    }
}

/* -------------------------------------------------------
   SUMMARY
------------------------------------------------------- */
$totalBrands = getCount($conn, 'battery_brands');
$activeBrands = getCount($conn, 'battery_brands', 'status = 1');
$inactiveBrands = getCount($conn, 'battery_brands', 'status = 0');

/* -------------------------------------------------------
   FETCH BRANDS
------------------------------------------------------- */
$brands = [];

$sql = "SELECT 
            bb.id,
            bb.brand_name,
            bb.status,
            bb.created_at,
            (
                SELECT COUNT(*)
                FROM vehicle_models vm
                WHERE vm.battery_brand = bb.brand_name
            ) AS model_count,
            (
                SELECT COUNT(*)
                FROM vehicle_stock vs
                WHERE vs.battery_brand = bb.brand_name
            ) AS stock_count
        FROM battery_brands bb
        WHERE {$where}
        ORDER BY bb.brand_name ASC";

$res = $conn->query($sql);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $brands[] = $row;
    }
}

$pageTitle = 'Battery Brands';
$currentPage = 'battery-brands';
?>
<!doctype html>
<html lang="en">

<?php include('includes/head.php'); ?>

<body data-sidebar="dark">

<style>
    .import-box {
        border: 2px dashed #ccc;
        border-radius: 8px;
        padding: 25px;
        text-align: center;
        background: #fafafa;
        cursor: pointer;
        transition: all 0.3s;
    }
    .import-box:hover {
        border-color: #3b82f6;
        background: #f0f7ff;
    }
    .import-box i {
        font-size: 40px;
        color: #6b7280;
        margin-bottom: 10px;
    }
    .sample-link {
        color: #3b82f6;
        text-decoration: underline;
        cursor: pointer;
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

                <div class="row mb-3">
                    <div class="col-md-6">
                        <h4 class="mb-1">Battery Brands</h4>
                        <p class="text-muted mb-0">Manage battery brand master data</p>
                    </div>
                    <div class="col-md-6 text-md-end mt-3 mt-md-0">
                        <button type="button" class="btn btn-success me-2" data-bs-toggle="modal" data-bs-target="#bulkImportModal">
                            <i class="ri-upload-cloud-line me-1"></i>Bulk Import
                        </button>
                        <a href="vehicle-models.php" class="btn btn-secondary">Vehicle Models</a>
                    </div>
                </div>

                <?php if ($success !== ''): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?php echo h($success); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($importSuccess !== ''): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="ri-check-line me-1"></i><?php echo h($importSuccess); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?php echo h($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="row">
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Total Battery Brands</p>
                                <h3 class="mb-0"><?php echo number_format($totalBrands); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Active Brands</p>
                                <h3 class="mb-0 text-success"><?php echo number_format($activeBrands); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Inactive Brands</p>
                                <h3 class="mb-0 text-warning"><?php echo number_format($inactiveBrands); ?></h3>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <!-- add form -->
                    <div class="col-xl-4">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Add Battery Brand</h4>

                                <form method="post">
                                    <input type="hidden" name="action" value="add">

                                    <div class="mb-3">
                                        <label class="form-label">Brand Name</label>
                                        <input type="text" name="brand_name" class="form-control" placeholder="Enter battery brand name" required>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Status</label>
                                        <select name="status" class="form-select">
                                            <option value="1">Active</option>
                                            <option value="0">Inactive</option>
                                        </select>
                                    </div>

                                    <button type="submit" class="btn btn-primary">Save Brand</button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- list -->
                    <div class="col-xl-8">
                        <div class="card">
                            <div class="card-body">

                                <form method="get" class="row g-3 mb-4">
                                    <div class="col-md-8">
                                        <input
                                            type="text"
                                            name="search"
                                            class="form-control"
                                            placeholder="Search battery brand..."
                                            value="<?php echo h($search); ?>"
                                        >
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
                                                <th>Brand Name</th>
                                                <th>Vehicle Models</th>
                                                <th>Vehicle Stock</th>
                                                <th>Status</th>
                                                <th>Created</th>
                                                <th style="width:320px;">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($brands)): ?>
                                                <?php $i = 1; foreach ($brands as $row): ?>
                                                    <tr>
                                                        <td><?php echo $i++; ?></td>
                                                        <td><strong><?php echo h($row['brand_name']); ?></strong></td>
                                                        <td>
                                                            <span class="badge bg-info">
                                                                <?php echo number_format((int)$row['model_count']); ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <span class="badge bg-primary">
                                                                <?php echo number_format((int)$row['stock_count']); ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <?php if ((int)$row['status'] === 1): ?>
                                                                <span class="badge bg-success">Active</span>
                                                            <?php else: ?>
                                                                <span class="badge bg-warning">Inactive</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <?php echo !empty($row['created_at']) ? h(date('d M Y', strtotime($row['created_at']))) : '-'; ?>
                                                        </td>
                                                        <td>
                                                            <form method="post" class="d-inline-flex gap-2 flex-wrap align-items-center">
                                                                <input type="hidden" name="action" value="edit">
                                                                <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">

                                                                <input
                                                                    type="text"
                                                                    name="brand_name"
                                                                    class="form-control form-control-sm"
                                                                    style="width:150px;"
                                                                    value="<?php echo h($row['brand_name']); ?>"
                                                                    required
                                                                >

                                                                <select name="status" class="form-select form-select-sm" style="width:100px;">
                                                                    <option value="1" <?php echo ((int)$row['status'] === 1) ? 'selected' : ''; ?>>Active</option>
                                                                    <option value="0" <?php echo ((int)$row['status'] === 0) ? 'selected' : ''; ?>>Inactive</option>
                                                                </select>

                                                                <button type="submit" class="btn btn-sm btn-primary">Update</button>
                                                            </form>

                                                            <form method="post" class="d-inline" onsubmit="return confirm('Delete this battery brand?');">
                                                                <input type="hidden" name="action" value="delete">
                                                                <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                                                                <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                                                            </form>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="7" class="text-center text-muted py-4">No battery brands found.</td>
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

<!-- Bulk Import Modal -->
<div class="modal fade" id="bulkImportModal" tabindex="-1" aria-labelledby="bulkImportModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title" id="bulkImportModalLabel">
                    <i class="ri-upload-cloud-line me-2"></i>Bulk Import Battery Brands
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="bulk_import">
                
                <div class="modal-body">
                    <?php if ($importError !== ''): ?>
                        <div class="alert alert-danger"><?php echo h($importError); ?></div>
                    <?php endif; ?>

                    <?php if ($bulkResults !== null && $bulkResults['success'] > 0): ?>
                        <div class="alert alert-success">
                            <i class="ri-check-line me-1"></i>
                            <strong><?php echo $bulkResults['success']; ?></strong> battery brands imported successfully!
                            <?php if ($bulkResults['skipped'] > 0): ?>
                                <br><small><?php echo $bulkResults['skipped']; ?> skipped (already exist)</small>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <p class="text-muted mb-3">
                        Upload a CSV file with battery brand names.
                        <a href="#" onclick="downloadSample()" class="sample-link">
                            <i class="ri-download-line"></i> Download sample CSV
                        </a>
                    </p>
                    
                    <div class="alert alert-info">
                        <i class="ri-information-line me-1"></i>
                        <strong>CSV Format:</strong> One brand per line. First column should contain brand name.<br>
                        Optional second column: Status (1=Active, 0=Inactive). Default is Active.
                    </div>

                    <div class="import-box" onclick="document.getElementById('csv_file').click()">
                        <i class="ri-file-excel-2-line"></i>
                        <h5>Click to select CSV file</h5>
                        <p class="text-muted mb-0">or drag and drop file here</p>
                        <p class="text-muted small mt-2">Max size: 5MB</p>
                        <input type="file" name="csv_file" id="csv_file" accept=".csv,.txt" style="display: none;" onchange="displayFileName(this)">
                    </div>
                    
                    <div id="selectedFile" class="mt-3" style="display: none;">
                        <span class="badge bg-info p-2">
                            <i class="ri-file-line me-1"></i><span id="fileName"></span>
                            <button type="button" class="btn-close ms-2" style="font-size: 10px;" onclick="clearFile()"></button>
                        </span>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success" id="importBtn" disabled>
                        <i class="ri-upload-cloud-line me-1"></i>Import Brands
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include('includes/rightbar.php'); ?>
<?php include('includes/scripts.php'); ?>

<script>
function displayFileName(input) {
    if (input.files && input.files[0]) {
        document.getElementById('fileName').textContent = input.files[0].name;
        document.getElementById('selectedFile').style.display = 'block';
        document.getElementById('importBtn').disabled = false;
    }
}

function clearFile() {
    document.getElementById('csv_file').value = '';
    document.getElementById('selectedFile').style.display = 'none';
    document.getElementById('importBtn').disabled = true;
}

function downloadSample() {
    var csvContent = "Brand Name,Status\n";
    csvContent += "Exide,1\n";
    csvContent += "Amaron,1\n";
    csvContent += "Luminous,1\n";
    csvContent += "Okaya,1\n";
    csvContent += "SF Sonic,1\n";
    csvContent += "Tata Green,1\n";
    csvContent += "Livguard,1\n";
    csvContent += "Base,1\n";
    
    var blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    var link = document.createElement("a");
    var url = URL.createObjectURL(blob);
    link.setAttribute("href", url);
    link.setAttribute("download", "sample_battery_brands.csv");
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

// Drag and drop
var importBox = document.querySelector('.import-box');
if (importBox) {
    importBox.addEventListener('dragover', function(e) {
        e.preventDefault();
        this.style.borderColor = '#10b981';
        this.style.background = '#f0fdf4';
    });
    
    importBox.addEventListener('dragleave', function(e) {
        e.preventDefault();
        this.style.borderColor = '#ccc';
        this.style.background = '#fafafa';
    });
    
    importBox.addEventListener('drop', function(e) {
        e.preventDefault();
        this.style.borderColor = '#ccc';
        this.style.background = '#fafafa';
        
        var files = e.dataTransfer.files;
        if (files.length > 0) {
            document.getElementById('csv_file').files = files;
            displayFileName({ files: files });
        }
    });
}

// Auto-hide alerts
setTimeout(function() {
    var alerts = document.querySelectorAll('.alert');
    alerts.forEach(function(alert) {
        var bsAlert = new bootstrap.Alert(alert);
        bsAlert.close();
    });
}, 5000);

// Show modal if there was an import error
<?php if ($showBulkModal): ?>
document.addEventListener('DOMContentLoaded', function() {
    var modal = new bootstrap.Modal(document.getElementById('bulkImportModal'));
    modal.show();
});
<?php endif; ?>
</script>

</body>
</html>