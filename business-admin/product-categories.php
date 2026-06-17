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
    if (!$stmt) return false;

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
    if (!$res) return 0;
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
if (!tableExists($conn, 'product_categories')) {
    die('product_categories table not found.');
}

/* -------------------------------------------------------
   ACTIONS
------------------------------------------------------- */
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');

    if ($action === 'add') {
        $categoryName = trim($_POST['category_name'] ?? '');

        if ($categoryName === '') {
            $error = 'Category name is required.';
        } else {
            $stmt = $conn->prepare("SELECT id FROM product_categories WHERE category_name = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('s', $categoryName);
                $stmt->execute();
                $dup = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($dup) {
                    $error = 'Category already exists.';
                }
            }

            if ($error === '') {
                $stmt = $conn->prepare("INSERT INTO product_categories (category_name) VALUES (?)");
                if ($stmt) {
                    $stmt->bind_param('s', $categoryName);
                    if ($stmt->execute()) {
                        $success = 'Category added successfully.';
                    } else {
                        $error = 'Failed to add category.';
                    }
                    $stmt->close();
                } else {
                    $error = 'Unable to prepare insert query.';
                }
            }
        }
    }

    if ($action === 'edit') {
        $categoryId = (int)($_POST['category_id'] ?? 0);
        $categoryName = trim($_POST['category_name'] ?? '');

        if ($categoryId <= 0) {
            $error = 'Invalid category id.';
        } elseif ($categoryName === '') {
            $error = 'Category name is required.';
        } else {
            $stmt = $conn->prepare("SELECT id 
                                    FROM product_categories 
                                    WHERE category_name = ? AND id != ?
                                    LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('si', $categoryName, $categoryId);
                $stmt->execute();
                $dup = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($dup) {
                    $error = 'Category name already exists.';
                }
            }

            if ($error === '') {
                $stmt = $conn->prepare("UPDATE product_categories SET category_name = ? WHERE id = ? LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param('si', $categoryName, $categoryId);
                    if ($stmt->execute()) {
                        $success = 'Category updated successfully.';
                    } else {
                        $error = 'Failed to update category.';
                    }
                    $stmt->close();
                } else {
                    $error = 'Unable to prepare update query.';
                }
            }
        }
    }

    if ($action === 'delete') {
        $categoryId = (int)($_POST['category_id'] ?? 0);

        if ($categoryId <= 0) {
            $error = 'Invalid category id.';
        } else {
            $usedCount = 0;
            if (tableExists($conn, 'products')) {
                $stmt = $conn->prepare("SELECT COUNT(*) AS total
                                        FROM products
                                        WHERE business_id = ? AND category_id = ?");
                if ($stmt) {
                    $stmt->bind_param('ii', $businessId, $categoryId);
                    $stmt->execute();
                    $row = $stmt->get_result()->fetch_assoc();
                    $usedCount = (int)($row['total'] ?? 0);
                    $stmt->close();
                }
            }

            if ($usedCount > 0) {
                $error = 'Cannot delete category because products are linked to it.';
            } else {
                $stmt = $conn->prepare("DELETE FROM product_categories WHERE id = ? LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param('i', $categoryId);
                    if ($stmt->execute()) {
                        $success = 'Category deleted successfully.';
                    } else {
                        $error = 'Failed to delete category.';
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
$where = "1=1";

if ($search !== '') {
    $safe = $conn->real_escape_string($search);
    $where .= " AND pc.category_name LIKE '%{$safe}%'";
}

/* -------------------------------------------------------
   SUMMARY
------------------------------------------------------- */
$totalCategories = getCount($conn, 'product_categories');
$totalProducts = tableExists($conn, 'products')
    ? getCount($conn, 'products', "business_id = {$businessId}")
    : 0;

/* -------------------------------------------------------
   FETCH CATEGORIES
------------------------------------------------------- */
$categories = [];

$sql = "SELECT 
            pc.id,
            pc.category_name,
            (
                SELECT COUNT(*) 
                FROM products p
                WHERE p.business_id = {$businessId}
                  AND p.category_id = pc.id
            ) AS product_count
        FROM product_categories pc
        WHERE {$where}
        ORDER BY pc.category_name ASC";

$res = $conn->query($sql);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $categories[] = $row;
    }
}

$pageTitle = 'Product Categories';
$currentPage = 'product-categories';
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
                        <h4 class="mb-1">Product Categories</h4>
                        <p class="text-muted mb-0">Manage product categories</p>
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
                    <div class="col-md-6 col-xl-6">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Total Categories</p>
                                <h3 class="mb-0"><?php echo number_format($totalCategories); ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-6">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted mb-1">Total Products</p>
                                <h3 class="mb-0"><?php echo number_format($totalProducts); ?></h3>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <!-- add category -->
                    <div class="col-xl-4">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Add Category</h4>

                                <form method="post">
                                    <input type="hidden" name="action" value="add">

                                    <div class="mb-3">
                                        <label class="form-label">Category Name</label>
                                        <input type="text" name="category_name" class="form-control" placeholder="Enter category name" required>
                                    </div>

                                    <button type="submit" class="btn btn-primary">Save Category</button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- list -->
                    <div class="col-xl-8">
                        <div class="card">
                            <div class="card-body">

                                <form method="get" class="row g-3 mb-4">
                                    <div class="col-md-10">
                                        <input
                                            type="text"
                                            name="search"
                                            class="form-control"
                                            placeholder="Search category name..."
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
                                                <th style="width:60px;">#</th>
                                                <th>Category Name</th>
                                                <th>Products</th>
                                                <th style="width:320px;">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($categories)): ?>
                                                <?php $i = 1; foreach ($categories as $cat): ?>
                                                    <tr>
                                                        <td><?php echo $i++; ?></td>
                                                        <td><?php echo h($cat['category_name']); ?></td>
                                                        <td>
                                                            <span class="badge bg-info">
                                                                <?php echo number_format((int)$cat['product_count']); ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <form method="post" class="d-inline-flex gap-2 flex-wrap align-items-center">
                                                                <input type="hidden" name="action" value="edit">
                                                                <input type="hidden" name="category_id" value="<?php echo (int)$cat['id']; ?>">
                                                                <input type="text" name="category_name" class="form-control form-control-sm" style="width:160px;" value="<?php echo h($cat['category_name']); ?>" required>
                                                                <button type="submit" class="btn btn-sm btn-primary">Update</button>
                                                            </form>

                                                            <form method="post" class="d-inline" onsubmit="return confirm('Delete this category?');">
                                                                <input type="hidden" name="action" value="delete">
                                                                <input type="hidden" name="category_id" value="<?php echo (int)$cat['id']; ?>">
                                                                <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                                                            </form>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="4" class="text-center text-muted py-4">No categories found.</td>
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