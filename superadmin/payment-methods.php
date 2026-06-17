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
    CREATE TABLE IF NOT EXISTS payment_methods (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        method_name VARCHAR(100) NOT NULL UNIQUE,
        description VARCHAR(255) DEFAULT NULL,
        status TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$success = '';
$error   = '';

/* -------------------------------------------------------
   ADD / UPDATE PAYMENT METHOD
------------------------------------------------------- */
$editId = (int)($_GET['edit'] ?? 0);
$editMethod = null;

if ($editId > 0) {
    $stmt = $conn->prepare("SELECT id, method_name, description, status FROM payment_methods WHERE id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("i", $editId);
        $stmt->execute();
        $result = $stmt->get_result();
        $editMethod = $result ? $result->fetch_assoc() : null;
        $stmt->close();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id          = (int)($_POST['id'] ?? 0);
    $method_name = trim($_POST['method_name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $status      = isset($_POST['status']) ? 1 : 0;

    if ($method_name === '') {
        $error = 'Payment method name is required.';
    } else {
        if ($id > 0) {
            $check = $conn->prepare("SELECT id FROM payment_methods WHERE method_name = ? AND id != ? LIMIT 1");
            if ($check) {
                $check->bind_param("si", $method_name, $id);
                $check->execute();
                $res = $check->get_result();
                $exists = $res ? $res->fetch_assoc() : null;
                $check->close();

                if ($exists) {
                    $error = 'Payment method already exists.';
                } else {
                    $stmt = $conn->prepare("
                        UPDATE payment_methods
                        SET method_name = ?, description = ?, status = ?
                        WHERE id = ?
                        LIMIT 1
                    ");
                    if ($stmt) {
                        $stmt->bind_param("ssii", $method_name, $description, $status, $id);
                        if ($stmt->execute()) {
                            $success = 'Payment method updated successfully.';
                            $editMethod = null;
                            unset($_GET['edit']);
                        } else {
                            $error = 'Failed to update payment method.';
                        }
                        $stmt->close();
                    } else {
                        $error = 'Database error while updating payment method.';
                    }
                }
            } else {
                $error = 'Database error while checking payment method.';
            }
        } else {
            $check = $conn->prepare("SELECT id FROM payment_methods WHERE method_name = ? LIMIT 1");
            if ($check) {
                $check->bind_param("s", $method_name);
                $check->execute();
                $res = $check->get_result();
                $exists = $res ? $res->fetch_assoc() : null;
                $check->close();

                if ($exists) {
                    $error = 'Payment method already exists.';
                } else {
                    $stmt = $conn->prepare("
                        INSERT INTO payment_methods (method_name, description, status, created_at)
                        VALUES (?, ?, ?, NOW())
                    ");
                    if ($stmt) {
                        $stmt->bind_param("ssi", $method_name, $description, $status);
                        if ($stmt->execute()) {
                            $success = 'Payment method added successfully.';
                        } else {
                            $error = 'Failed to add payment method.';
                        }
                        $stmt->close();
                    } else {
                        $error = 'Database error while adding payment method.';
                    }
                }
            } else {
                $error = 'Database error while checking payment method.';
            }
        }
    }
}

/* -------------------------------------------------------
   DELETE PAYMENT METHOD
------------------------------------------------------- */
if (isset($_GET['delete']) && $_GET['delete'] !== '') {
    $deleteId = (int)$_GET['delete'];

    if ($deleteId > 0) {
        $stmt = $conn->prepare("DELETE FROM payment_methods WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("i", $deleteId);
            if ($stmt->execute()) {
                $success = 'Payment method deleted successfully.';
            } else {
                $error = 'Failed to delete payment method.';
            }
            $stmt->close();
        } else {
            $error = 'Database error while deleting payment method.';
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
    $where .= " AND (method_name LIKE ? OR description LIKE ?) ";
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
   FETCH PAYMENT METHODS
------------------------------------------------------- */
$methods = [];

$sql = "SELECT id, method_name, description, status, created_at FROM payment_methods {$where} ORDER BY method_name ASC";
$stmt = $conn->prepare($sql);
if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $methods[] = $row;
        }
    }
    $stmt->close();
}

/* -------------------------------------------------------
   COUNTS
------------------------------------------------------- */
$totalMethods = 0;
$activeMethods = 0;
$inactiveMethods = 0;

$res = $conn->query("SELECT COUNT(*) AS total FROM payment_methods");
if ($res && $row = $res->fetch_assoc()) {
    $totalMethods = (int)$row['total'];
}

$res = $conn->query("SELECT COUNT(*) AS total FROM payment_methods WHERE status = 1");
if ($res && $row = $res->fetch_assoc()) {
    $activeMethods = (int)$row['total'];
}

$res = $conn->query("SELECT COUNT(*) AS total FROM payment_methods WHERE status = 0");
if ($res && $row = $res->fetch_assoc()) {
    $inactiveMethods = (int)$row['total'];
}

$pageTitle = 'Payment Methods';
$currentPage = 'payment-methods';
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
                                <p class="text-muted mb-1">Total Methods</p>
                                <h3 class="text-primary mt-2 mb-0"><?php echo number_format($totalMethods); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4 col-xl-4">
                        <div class="card text-center">
                            <div class="card-body">
                                <p class="text-muted mb-1">Active Methods</p>
                                <h3 class="text-success mt-2 mb-0"><?php echo number_format($activeMethods); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4 col-xl-4">
                        <div class="card text-center">
                            <div class="card-body">
                                <p class="text-muted mb-1">Inactive Methods</p>
                                <h3 class="text-danger mt-2 mb-0"><?php echo number_format($inactiveMethods); ?></h3>
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
                                    <?php echo $editMethod ? 'Edit Payment Method' : 'Add Payment Method'; ?>
                                </h4>

                                <form method="post" action="">
                                    <input type="hidden" name="id" value="<?php echo (int)($editMethod['id'] ?? 0); ?>">

                                    <div class="mb-3">
                                        <label class="form-label">Method Name <span class="text-danger">*</span></label>
                                        <input type="text" name="method_name" class="form-control"
                                               value="<?php echo h($editMethod['method_name'] ?? ''); ?>" required>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Description</label>
                                        <input type="text" name="description" class="form-control"
                                               value="<?php echo h($editMethod['description'] ?? ''); ?>">
                                    </div>

                                    <div class="form-check mb-3">
                                        <input class="form-check-input" type="checkbox" id="status1" name="status"
                                            <?php echo (!isset($editMethod['status']) || (int)$editMethod['status'] === 1) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="status">Active Method</label>
                                    </div>

                                    <div class="d-grid gap-2">
                                        <button type="submit" class="btn btn-primary">
                                            <?php echo $editMethod ? 'Update Payment Method' : 'Add Payment Method'; ?>
                                        </button>

                                        <?php if ($editMethod): ?>
                                            <a href="payment-methods.php" class="btn btn-secondary">Cancel Edit</a>
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
                                               placeholder="Search payment method or description"
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
                                            <a href="payment-methods.php" class="btn btn-secondary">Reset</a>
                                        </div>
                                    </div>
                                </form>

                                <div class="table-responsive">
                                    <table class="table table-bordered table-hover align-middle mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th style="width:60px;">#</th>
                                                <th>Method Name</th>
                                                <th>Description</th>
                                                <th>Status</th>
                                                <th>Created</th>
                                                <th style="width:180px;">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($methods)): ?>
                                                <?php $sno = 1; ?>
                                                <?php foreach ($methods as $row): ?>
                                                    <tr>
                                                        <td><?php echo $sno++; ?></td>
                                                        <td><strong><?php echo h($row['method_name']); ?></strong></td>
                                                        <td><?php echo h($row['description'] ?: '-'); ?></td>
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
                                                                <a href="payment-methods.php?edit=<?php echo (int)$row['id']; ?>" class="btn btn-primary btn-sm">Edit</a>
                                                                <a href="payment-methods.php?delete=<?php echo (int)$row['id']; ?>"
                                                                   class="btn btn-danger btn-sm"
                                                                   onclick="return confirm('Are you sure you want to delete this payment method?');">
                                                                    Delete
                                                                </a>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="6" class="text-center text-muted py-4">No payment methods found.</td>
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