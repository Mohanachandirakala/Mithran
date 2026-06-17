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

function money($amount)
{
    return '₹' . number_format((float)$amount, 2);
}

$success = '';
$error   = '';

/* -------------------------------------------------------
   CREATE TABLE IF NOT EXISTS
------------------------------------------------------- */
$conn->query("
    CREATE TABLE IF NOT EXISTS products (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        business_id INT UNSIGNED NOT NULL,
        category_id INT UNSIGNED DEFAULT NULL,
        product_name VARCHAR(150) NOT NULL,
        product_code VARCHAR(100) DEFAULT NULL,
        hsn_code VARCHAR(50) DEFAULT NULL,
        unit_name VARCHAR(50) DEFAULT NULL,
        brand_name VARCHAR(100) DEFAULT NULL,
        part_number VARCHAR(100) DEFAULT NULL,
        purchase_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        selling_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        gst_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
        stock_qty DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        min_stock_qty DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        description TEXT DEFAULT NULL,
        status TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

/* -------------------------------------------------------
   FETCH MASTER DATA
------------------------------------------------------- */
$businesses = [];
$res = $conn->query("SELECT id, business_name, business_code FROM businesses WHERE status = 'active' ORDER BY business_name ASC");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $businesses[] = $row;
    }
}

$productCategories = [];
$res = $conn->query("SELECT id, category_name FROM product_categories ORDER BY category_name ASC");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $productCategories[] = $row;
    }
}

/* -------------------------------------------------------
   ADD / UPDATE PRODUCT
------------------------------------------------------- */
$editId = (int)($_GET['edit'] ?? 0);
$editProduct = null;

if ($editId > 0) {
    $stmt = $conn->prepare("SELECT * FROM products WHERE id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("i", $editId);
        $stmt->execute();
        $result = $stmt->get_result();
        $editProduct = $result ? $result->fetch_assoc() : null;
        $stmt->close();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $product_id      = (int)($_POST['product_id'] ?? 0);
    $business_id     = (int)($_POST['business_id'] ?? 0);
    $category_id     = (int)($_POST['category_id'] ?? 0);
    $product_name    = trim($_POST['product_name'] ?? '');
    $product_code    = trim($_POST['product_code'] ?? '');
    $hsn_code        = trim($_POST['hsn_code'] ?? '');
    $unit_name       = trim($_POST['unit_name'] ?? '');
    $brand_name      = trim($_POST['brand_name'] ?? '');
    $part_number     = trim($_POST['part_number'] ?? '');
    $purchase_price  = (float)($_POST['purchase_price'] ?? 0);
    $selling_price   = (float)($_POST['selling_price'] ?? 0);
    $gst_percent     = (float)($_POST['gst_percent'] ?? 0);
    $stock_qty       = (float)($_POST['stock_qty'] ?? 0);
    $min_stock_qty   = (float)($_POST['min_stock_qty'] ?? 0);
    $description     = trim($_POST['description'] ?? '');
    $status          = isset($_POST['status']) ? 1 : 0;

    if ($business_id <= 0 || $product_name === '') {
        $error = 'Business and product name are required.';
    } else {
        $categoryValue = $category_id > 0 ? $category_id : null;

        if ($product_id > 0) {
            $check = $conn->prepare("
                SELECT id 
                FROM products 
                WHERE business_id = ? 
                  AND product_name = ? 
                  AND IFNULL(product_code,'') = IFNULL(?, '')
                  AND id != ?
                LIMIT 1
            ");
            if ($check) {
                $check->bind_param("issi", $business_id, $product_name, $product_code, $product_id);
                $check->execute();
                $res = $check->get_result();
                $exists = $res ? $res->fetch_assoc() : null;
                $check->close();

                if ($exists) {
                    $error = 'This product already exists for the selected business.';
                } else {
                    $stmt = $conn->prepare("
                        UPDATE products SET
                            business_id = ?,
                            category_id = ?,
                            product_name = ?,
                            product_code = ?,
                            hsn_code = ?,
                            unit_name = ?,
                            brand_name = ?,
                            part_number = ?,
                            purchase_price = ?,
                            selling_price = ?,
                            gst_percent = ?,
                            stock_qty = ?,
                            min_stock_qty = ?,
                            description = ?,
                            status = ?
                        WHERE id = ?
                        LIMIT 1
                    ");

                    if ($stmt) {
                        $stmt->bind_param(
                            "iissssssdddddsii",
                            $business_id,
                            $categoryValue,
                            $product_name,
                            $product_code,
                            $hsn_code,
                            $unit_name,
                            $brand_name,
                            $part_number,
                            $purchase_price,
                            $selling_price,
                            $gst_percent,
                            $stock_qty,
                            $min_stock_qty,
                            $description,
                            $status,
                            $product_id
                        );

                        if ($stmt->execute()) {
                            $success = 'Product updated successfully.';
                            $editProduct = null;
                            unset($_GET['edit']);
                        } else {
                            $error = 'Failed to update product.';
                        }
                        $stmt->close();
                    } else {
                        $error = 'Database error while updating product.';
                    }
                }
            } else {
                $error = 'Database error while checking product.';
            }
        } else {
            $check = $conn->prepare("
                SELECT id 
                FROM products 
                WHERE business_id = ? 
                  AND product_name = ? 
                  AND IFNULL(product_code,'') = IFNULL(?, '')
                LIMIT 1
            ");
            if ($check) {
                $check->bind_param("iss", $business_id, $product_name, $product_code);
                $check->execute();
                $res = $check->get_result();
                $exists = $res ? $res->fetch_assoc() : null;
                $check->close();

                if ($exists) {
                    $error = 'This product already exists for the selected business.';
                } else {
                    $stmt = $conn->prepare("
                        INSERT INTO products (
                            business_id,
                            category_id,
                            product_name,
                            product_code,
                            hsn_code,
                            unit_name,
                            brand_name,
                            part_number,
                            purchase_price,
                            selling_price,
                            gst_percent,
                            stock_qty,
                            min_stock_qty,
                            description,
                            status,
                            created_at
                        ) VALUES (
                            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()
                        )
                    ");

                    if ($stmt) {
                        $stmt->bind_param(
                            "iissssssdddddis",
                            $business_id,
                            $categoryValue,
                            $product_name,
                            $product_code,
                            $hsn_code,
                            $unit_name,
                            $brand_name,
                            $part_number,
                            $purchase_price,
                            $selling_price,
                            $gst_percent,
                            $stock_qty,
                            $min_stock_qty,
                            $description,
                            $status
                        );

                        if ($stmt->execute()) {
                            $success = 'Product added successfully.';
                        } else {
                            $error = 'Failed to add product.';
                        }
                        $stmt->close();
                    } else {
                        $error = 'Database error while adding product.';
                    }
                }
            } else {
                $error = 'Database error while checking product.';
            }
        }
    }
}

/* -------------------------------------------------------
   DELETE PRODUCT
------------------------------------------------------- */
if (isset($_GET['delete']) && $_GET['delete'] !== '') {
    $deleteId = (int)$_GET['delete'];

    if ($deleteId > 0) {
        $stmt = $conn->prepare("DELETE FROM products WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("i", $deleteId);
            if ($stmt->execute()) {
                $success = 'Product deleted successfully.';
            } else {
                $error = 'Failed to delete product.';
            }
            $stmt->close();
        } else {
            $error = 'Database error while deleting product.';
        }
    }
}

/* -------------------------------------------------------
   FILTERS
------------------------------------------------------- */
$search = trim($_GET['search'] ?? '');
$filter_business_id = (int)($_GET['filter_business_id'] ?? 0);
$filter_category_id = (int)($_GET['filter_category_id'] ?? 0);
$filter_status = trim($_GET['filter_status'] ?? '');

$where = " WHERE 1=1 ";
$params = [];
$types = "";

if ($search !== '') {
    $where .= " AND (
        p.product_name LIKE ?
        OR p.product_code LIKE ?
        OR p.brand_name LIKE ?
        OR p.part_number LIKE ?
        OR b.business_name LIKE ?
        OR pc.category_name LIKE ?
    ) ";
    $like = "%{$search}%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= "ssssss";
}

if ($filter_business_id > 0) {
    $where .= " AND p.business_id = ? ";
    $params[] = $filter_business_id;
    $types .= "i";
}

if ($filter_category_id > 0) {
    $where .= " AND p.category_id = ? ";
    $params[] = $filter_category_id;
    $types .= "i";
}

if ($filter_status !== '' && in_array($filter_status, ['1', '0'], true)) {
    $where .= " AND p.status = ? ";
    $params[] = (int)$filter_status;
    $types .= "i";
}

/* -------------------------------------------------------
   FETCH PRODUCTS
------------------------------------------------------- */
$products = [];

$sql = "
    SELECT 
        p.*,
        b.business_name,
        b.business_code,
        pc.category_name
    FROM products p
    INNER JOIN businesses b ON b.id = p.business_id
    LEFT JOIN product_categories pc ON pc.id = p.category_id
    {$where}
    ORDER BY p.id DESC
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
            $products[] = $row;
        }
    }
    $stmt->close();
}

/* -------------------------------------------------------
   COUNTS
------------------------------------------------------- */
$totalProducts = 0;
$activeProducts = 0;
$inactiveProducts = 0;
$lowStockProducts = 0;

$res = $conn->query("SELECT COUNT(*) AS total FROM products");
if ($res && $row = $res->fetch_assoc()) {
    $totalProducts = (int)$row['total'];
}

$res = $conn->query("SELECT COUNT(*) AS total FROM products WHERE status = 1");
if ($res && $row = $res->fetch_assoc()) {
    $activeProducts = (int)$row['total'];
}

$res = $conn->query("SELECT COUNT(*) AS total FROM products WHERE status = 0");
if ($res && $row = $res->fetch_assoc()) {
    $inactiveProducts = (int)$row['total'];
}

$res = $conn->query("SELECT COUNT(*) AS total FROM products WHERE stock_qty <= min_stock_qty");
if ($res && $row = $res->fetch_assoc()) {
    $lowStockProducts = (int)$row['total'];
}

$pageTitle = 'Products';
$currentPage = 'products';
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
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <p class="text-muted mb-1">Total Products</p>
                                <h3 class="text-primary mt-2 mb-0"><?php echo number_format($totalProducts); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <p class="text-muted mb-1">Active Products</p>
                                <h3 class="text-success mt-2 mb-0"><?php echo number_format($activeProducts); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <p class="text-muted mb-1">Inactive Products</p>
                                <h3 class="text-danger mt-2 mb-0"><?php echo number_format($inactiveProducts); ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <p class="text-muted mb-1">Low Stock</p>
                                <h3 class="text-warning mt-2 mb-0"><?php echo number_format($lowStockProducts); ?></h3>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Form -->
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4"><?php echo $editProduct ? 'Edit Product' : 'Add Product'; ?></h4>

                        <form method="post" action="">
                            <input type="hidden" name="product_id" value="<?php echo (int)($editProduct['id'] ?? 0); ?>">

                            <div class="row">
                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label class="form-label">Business <span class="text-danger">*</span></label>
                                        <select name="business_id" class="form-select" required>
                                            <option value="">Select Business</option>
                                            <?php foreach ($businesses as $business): ?>
                                                <option value="<?php echo (int)$business['id']; ?>" <?php echo ((int)($editProduct['business_id'] ?? 0) === (int)$business['id']) ? 'selected' : ''; ?>>
                                                    <?php echo h($business['business_name'] . ' (' . $business['business_code'] . ')'); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>

                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label class="form-label">Category</label>
                                        <select name="category_id" class="form-select">
                                            <option value="0">Select Category</option>
                                            <?php foreach ($productCategories as $category): ?>
                                                <option value="<?php echo (int)$category['id']; ?>" <?php echo ((int)($editProduct['category_id'] ?? 0) === (int)$category['id']) ? 'selected' : ''; ?>>
                                                    <?php echo h($category['category_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>

                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label class="form-label">Product Name <span class="text-danger">*</span></label>
                                        <input type="text" name="product_name" class="form-control" value="<?php echo h($editProduct['product_name'] ?? ''); ?>" required>
                                    </div>
                                </div>

                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label class="form-label">Product Code</label>
                                        <input type="text" name="product_code" class="form-control" value="<?php echo h($editProduct['product_code'] ?? ''); ?>">
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-2">
                                    <div class="mb-3">
                                        <label class="form-label">HSN Code</label>
                                        <input type="text" name="hsn_code" class="form-control" value="<?php echo h($editProduct['hsn_code'] ?? ''); ?>">
                                    </div>
                                </div>

                                <div class="col-md-2">
                                    <div class="mb-3">
                                        <label class="form-label">Unit</label>
                                        <input type="text" name="unit_name" class="form-control" placeholder="Nos / Pcs / Ltr" value="<?php echo h($editProduct['unit_name'] ?? ''); ?>">
                                    </div>
                                </div>

                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label class="form-label">Brand Name</label>
                                        <input type="text" name="brand_name" class="form-control" value="<?php echo h($editProduct['brand_name'] ?? ''); ?>">
                                    </div>
                                </div>

                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label class="form-label">Part Number</label>
                                        <input type="text" name="part_number" class="form-control" value="<?php echo h($editProduct['part_number'] ?? ''); ?>">
                                    </div>
                                </div>

                                <div class="col-md-2 d-flex align-items-center">
                                    <div class="form-check mt-3">
                                        <input class="form-check-input" type="checkbox" id="status1" name="status" <?php echo (!isset($editProduct['status']) || (int)$editProduct['status'] === 1) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="status">Active</label>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-2">
                                    <div class="mb-3">
                                        <label class="form-label">Purchase Price</label>
                                        <input type="number" step="0.01" name="purchase_price" class="form-control" value="<?php echo h($editProduct['purchase_price'] ?? '0.00'); ?>">
                                    </div>
                                </div>

                                <div class="col-md-2">
                                    <div class="mb-3">
                                        <label class="form-label">Selling Price</label>
                                        <input type="number" step="0.01" name="selling_price" class="form-control" value="<?php echo h($editProduct['selling_price'] ?? '0.00'); ?>">
                                    </div>
                                </div>

                                <div class="col-md-2">
                                    <div class="mb-3">
                                        <label class="form-label">GST %</label>
                                        <input type="number" step="0.01" name="gst_percent" class="form-control" value="<?php echo h($editProduct['gst_percent'] ?? '0.00'); ?>">
                                    </div>
                                </div>

                                <div class="col-md-2">
                                    <div class="mb-3">
                                        <label class="form-label">Stock Qty</label>
                                        <input type="number" step="0.01" name="stock_qty" class="form-control" value="<?php echo h($editProduct['stock_qty'] ?? '0.00'); ?>">
                                    </div>
                                </div>

                                <div class="col-md-2">
                                    <div class="mb-3">
                                        <label class="form-label">Min Stock Qty</label>
                                        <input type="number" step="0.01" name="min_stock_qty" class="form-control" value="<?php echo h($editProduct['min_stock_qty'] ?? '0.00'); ?>">
                                    </div>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Description</label>
                                <textarea name="description" rows="3" class="form-control"><?php echo h($editProduct['description'] ?? ''); ?></textarea>
                            </div>

                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary"><?php echo $editProduct ? 'Update Product' : 'Add Product'; ?></button>
                                <?php if ($editProduct): ?>
                                    <a href="products.php" class="btn btn-secondary">Cancel Edit</a>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Filters -->
                <div class="card">
                    <div class="card-body">
                        <form method="get" class="row g-3 align-items-end">
                            <div class="col-md-4">
                                <label class="form-label">Search</label>
                                <input type="text" name="search" class="form-control" placeholder="Product / code / brand / part no / business / category" value="<?php echo h($search); ?>">
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">Business</label>
                                <select name="filter_business_id" class="form-select">
                                    <option value="">All</option>
                                    <?php foreach ($businesses as $business): ?>
                                        <option value="<?php echo (int)$business['id']; ?>" <?php echo $filter_business_id === (int)$business['id'] ? 'selected' : ''; ?>>
                                            <?php echo h($business['business_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">Category</label>
                                <select name="filter_category_id" class="form-select">
                                    <option value="">All</option>
                                    <?php foreach ($productCategories as $category): ?>
                                        <option value="<?php echo (int)$category['id']; ?>" <?php echo $filter_category_id === (int)$category['id'] ? 'selected' : ''; ?>>
                                            <?php echo h($category['category_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-1">
                                <label class="form-label">Status</label>
                                <select name="filter_status" class="form-select">
                                    <option value="">All</option>
                                    <option value="1" <?php echo $filter_status === '1' ? 'selected' : ''; ?>>Active</option>
                                    <option value="0" <?php echo $filter_status === '0' ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                            </div>

                            <div class="col-md-1">
                                <button type="submit" class="btn btn-primary">Go</button>
                            </div>
                        </form>
                        <div class="mt-3">
                            <a href="products.php" class="btn btn-secondary btn-sm">Reset Filters</a>
                        </div>
                    </div>
                </div>

                <!-- Table -->
                <div class="card">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>#</th>
                                        <th>Business</th>
                                        <th>Category</th>
                                        <th>Product</th>
                                        <th>Code</th>
                                        <th>Brand</th>
                                        <th>Sell Price</th>
                                        <th>Stock</th>
                                        <th>Status</th>
                                        <th style="width:180px;">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($products)): ?>
                                        <?php $sno = 1; foreach ($products as $row): ?>
                                            <tr>
                                                <td><?php echo $sno++; ?></td>
                                                <td>
                                                    <div><?php echo h($row['business_name']); ?></div>
                                                    <small class="text-muted"><?php echo h($row['business_code']); ?></small>
                                                </td>
                                                <td><?php echo h($row['category_name'] ?: '-'); ?></td>
                                                <td>
                                                    <div><strong><?php echo h($row['product_name']); ?></strong></div>
                                                    <small class="text-muted"><?php echo h($row['unit_name'] ?: '-'); ?></small>
                                                </td>
                                                <td><?php echo h($row['product_code'] ?: '-'); ?></td>
                                                <td><?php echo h($row['brand_name'] ?: '-'); ?></td>
                                                <td><?php echo money($row['selling_price']); ?></td>
                                                <td>
                                                    <?php echo number_format((float)$row['stock_qty'], 2); ?>
                                                    <?php if ((float)$row['stock_qty'] <= (float)$row['min_stock_qty']): ?>
                                                        <span class="badge bg-warning ms-1">Low</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ((int)$row['status'] === 1): ?>
                                                        <span class="badge bg-success">Active</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-danger">Inactive</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="d-flex flex-wrap gap-1">
                                                        <a href="products.php?edit=<?php echo (int)$row['id']; ?>" class="btn btn-primary btn-sm">Edit</a>
                                                        <a href="products.php?delete=<?php echo (int)$row['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Are you sure you want to delete this product?');">Delete</a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="10" class="text-center text-muted py-4">No products found.</td>
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