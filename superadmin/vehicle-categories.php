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
   ADD / UPDATE CATEGORY
------------------------------------------------------- */
$editId = (int)($_GET['edit'] ?? 0);
$editCategory = null;

if ($editId > 0) {
    $stmt = $conn->prepare("SELECT id, category_name FROM vehicle_categories WHERE id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("i", $editId);
        $stmt->execute();
        $result = $stmt->get_result();
        $editCategory = $result ? $result->fetch_assoc() : null;
        $stmt->close();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $category_name = trim($_POST['category_name'] ?? '');
    $category_id   = (int)($_POST['category_id'] ?? 0);

    if ($category_name === '') {
        $error = 'Category name is required.';
    } else {
        if ($category_id > 0) {
            $check = $conn->prepare("SELECT id FROM vehicle_categories WHERE category_name = ? AND id != ? LIMIT 1");
            if ($check) {
                $check->bind_param("si", $category_name, $category_id);
                $check->execute();
                $res = $check->get_result();
                $exists = $res ? $res->fetch_assoc() : null;
                $check->close();

                if ($exists) {
                    $error = 'Category name already exists.';
                } else {
                    $stmt = $conn->prepare("UPDATE vehicle_categories SET category_name = ? WHERE id = ? LIMIT 1");
                    if ($stmt) {
                        $stmt->bind_param("si", $category_name, $category_id);
                        if ($stmt->execute()) {
                            $success = 'Vehicle category updated successfully.';
                            $editCategory = null;
                            $_GET['edit'] = null;
                        } else {
                            $error = 'Failed to update vehicle category.';
                        }
                        $stmt->close();
                    } else {
                        $error = 'Database error while updating category.';
                    }
                }
            } else {
                $error = 'Database error while checking category.';
            }
        } else {
            $check = $conn->prepare("SELECT id FROM vehicle_categories WHERE category_name = ? LIMIT 1");
            if ($check) {
                $check->bind_param("s", $category_name);
                $check->execute();
                $res = $check->get_result();
                $exists = $res ? $res->fetch_assoc() : null;
                $check->close();

                if ($exists) {
                    $error = 'Category name already exists.';
                } else {
                    $stmt = $conn->prepare("INSERT INTO vehicle_categories (category_name) VALUES (?)");
                    if ($stmt) {
                        $stmt->bind_param("s", $category_name);
                        if ($stmt->execute()) {
                            $success = 'Vehicle category added successfully.';
                        } else {
                            $error = 'Failed to add vehicle category.';
                        }
                        $stmt->close();
                    } else {
                        $error = 'Database error while adding category.';
                    }
                }
            } else {
                $error = 'Database error while checking category.';
            }
        }
    }
}

/* -------------------------------------------------------
   DELETE CATEGORY
------------------------------------------------------- */
if (isset($_GET['delete']) && $_GET['delete'] !== '') {
    $deleteId = (int)$_GET['delete'];

    if ($deleteId > 0) {
        $check = $conn->prepare("SELECT COUNT(*) AS total FROM vehicle_models WHERE category_id = ?");
        if ($check) {
            $check->bind_param("i", $deleteId);
            $check->execute();
            $res = $check->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            $usedCount = (int)($row['total'] ?? 0);
            $check->close();

            if ($usedCount > 0) {
                $error = 'This category is already used in vehicle models, so it cannot be deleted.';
            } else {
                $stmt = $conn->prepare("DELETE FROM vehicle_categories WHERE id = ? LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param("i", $deleteId);
                    if ($stmt->execute()) {
                        $success = 'Vehicle category deleted successfully.';
                    } else {
                        $error = 'Failed to delete vehicle category.';
                    }
                    $stmt->close();
                } else {
                    $error = 'Database error while deleting category.';
                }
            }
        } else {
            $error = 'Database error while checking category usage.';
        }
    }
}

/* -------------------------------------------------------
   FILTERS
------------------------------------------------------- */
$search = trim($_GET['search'] ?? '');

$where = " WHERE 1=1 ";
$params = [];
$types = "";

if ($search !== '') {
    $where .= " AND vc.category_name LIKE ? ";
    $params[] = "%{$search}%";
    $types .= "s";
}

/* -------------------------------------------------------
   FETCH CATEGORIES
------------------------------------------------------- */
$categories = [];

$sql = "
    SELECT 
        vc.id,
        vc.category_name,
        (
            SELECT COUNT(*) 
            FROM vehicle_models vm 
            WHERE vm.category_id = vc.id
        ) AS model_count
    FROM vehicle_categories vc
    {$where}
    ORDER BY vc.category_name ASC
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
            $categories[] = $row;
        }
    }
    $stmt->close();
}

/* -------------------------------------------------------
   COUNTS
------------------------------------------------------- */
$totalCategories = 0;
$usedCategories = 0;
$unusedCategories = 0;

$res = $conn->query("SELECT COUNT(*) AS total FROM vehicle_categories");
if ($res && $row = $res->fetch_assoc()) {
    $totalCategories = (int)$row['total'];
}

$res = $conn->query("
    SELECT COUNT(*) AS total
    FROM vehicle_categories vc
    WHERE EXISTS (
        SELECT 1 FROM vehicle_models vm WHERE vm.category_id = vc.id
    )
");
if ($res && $row = $res->fetch_assoc()) {
    $usedCategories = (int)$row['total'];
}

$unusedCategories = $totalCategories - $usedCategories;

$pageTitle = 'Vehicle Categories';
$currentPage = 'vehicle-categories';
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

                <!-- Stats -->
                <div class="row">
                    <div class="col-md-4 col-xl-4">
                        <div class="card text-center">
                            <div class="card-body">
                                <p class="text-muted mb-1">Total Categories</p>
                                <h3 class="text-primary mt-2 mb-0"><?php echo number_format($totalCategories); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4 col-xl-4">
                        <div class="card text-center">
                            <div class="card-body">
                                <p class="text-muted mb-1">Used Categories</p>
                                <h3 class="text-success mt-2 mb-0"><?php echo number_format($usedCategories); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4 col-xl-4">
                        <div class="card text-center">
                            <div class="card-body">
                                <p class="text-muted mb-1">Unused Categories</p>
                                <h3 class="text-info mt-2 mb-0"><?php echo number_format($unusedCategories); ?></h3>
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
                                    <?php echo $editCategory ? 'Edit Category' : 'Add Category'; ?>
                                </h4>

                                <form method="post" action="">
                                    <input type="hidden" name="category_id" value="<?php echo (int)($editCategory['id'] ?? 0); ?>">

                                    <div class="mb-3">
                                        <label class="form-label">Category Name <span class="text-danger">*</span></label>
                                        <input type="text" name="category_name" class="form-control"
                                               value="<?php echo h($editCategory['category_name'] ?? ''); ?>" required>
                                    </div>

                                    <div class="d-grid gap-2">
                                        <button type="submit" class="btn btn-primary">
                                            <?php echo $editCategory ? 'Update Category' : 'Add Category'; ?>
                                        </button>

                                        <?php if ($editCategory): ?>
                                            <a href="vehicle-categories.php" class="btn btn-secondary">Cancel Edit</a>
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
                                               placeholder="Search category name"
                                               value="<?php echo h($search); ?>">
                                    </div>

                                    <div class="col-md-4">
                                        <div class="d-flex gap-2">
                                            <button type="submit" class="btn btn-primary">Filter</button>
                                            <a href="vehicle-categories.php" class="btn btn-secondary">Reset</a>
                                        </div>
                                    </div>
                                </form>

                                <div class="table-responsive">
                                    <table class="table table-bordered table-hover align-middle mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th style="width:60px;">#</th>
                                                <th>Category Name</th>
                                                <th>Used in Models</th>
                                                <th style="width:180px;">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($categories)): ?>
                                                <?php $sno = 1; ?>
                                                <?php foreach ($categories as $row): ?>
                                                    <tr>
                                                        <td><?php echo $sno++; ?></td>
                                                        <td><?php echo h($row['category_name']); ?></td>
                                                        <td><?php echo number_format((int)$row['model_count']); ?></td>
                                                        <td>
                                                            <div class="d-flex flex-wrap gap-1">
                                                                <a href="vehicle-categories.php?edit=<?php echo (int)$row['id']; ?>" class="btn btn-primary btn-sm">Edit</a>
                                                                <a href="vehicle-categories.php?delete=<?php echo (int)$row['id']; ?>"
                                                                   class="btn btn-danger btn-sm"
                                                                   onclick="return confirm('Are you sure you want to delete this category?');">
                                                                    Delete
                                                                </a>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="4" class="text-center text-muted py-4">No vehicle categories found.</td>
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