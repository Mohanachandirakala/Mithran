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
   BRANCH ID
------------------------------------------------------- */
$branchId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($branchId <= 0) {
    die('Invalid branch id.');
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
   LOAD BRANCH
------------------------------------------------------- */
$branch = null;

if (tableExists($conn, 'branches')) {
    $stmt = $conn->prepare("SELECT *
                            FROM branches
                            WHERE id = ? AND business_id = ?
                            LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('ii', $branchId, $businessId);
        $stmt->execute();
        $branch = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}

if (!$branch) {
    die('Branch not found.');
}

/* -------------------------------------------------------
   FORM VALUES
------------------------------------------------------- */
$success = '';
$error = '';

$form = [
    'branch_name'      => $branch['branch_name'] ?? '',
    'branch_code'      => $branch['branch_code'] ?? '',
    'contact_person'   => $branch['contact_person'] ?? '',
    'email'            => $branch['email'] ?? '',
    'mobile'           => $branch['mobile'] ?? '',
    'alternate_mobile' => $branch['alternate_mobile'] ?? '',
    'gstin'            => $branch['gstin'] ?? '',
    'address_line1'    => $branch['address_line1'] ?? '',
    'address_line2'    => $branch['address_line2'] ?? '',
    'city'             => $branch['city'] ?? '',
    'district'         => $branch['district'] ?? '',
    'state'            => $branch['state'] ?? '',
    'pincode'          => $branch['pincode'] ?? '',
    'is_head_office'   => (int)($branch['is_head_office'] ?? 0),
    'status'           => $branch['status'] ?? 'active',
];

/* -------------------------------------------------------
   UPDATE
------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['branch_name']      = trim($_POST['branch_name'] ?? '');
    $form['branch_code']      = trim($_POST['branch_code'] ?? '');
    $form['contact_person']   = trim($_POST['contact_person'] ?? '');
    $form['email']            = trim($_POST['email'] ?? '');
    $form['mobile']           = trim($_POST['mobile'] ?? '');
    $form['alternate_mobile'] = trim($_POST['alternate_mobile'] ?? '');
    $form['gstin']            = trim($_POST['gstin'] ?? '');
    $form['address_line1']    = trim($_POST['address_line1'] ?? '');
    $form['address_line2']    = trim($_POST['address_line2'] ?? '');
    $form['city']             = trim($_POST['city'] ?? '');
    $form['district']         = trim($_POST['district'] ?? '');
    $form['state']            = trim($_POST['state'] ?? '');
    $form['pincode']          = trim($_POST['pincode'] ?? '');
    $form['is_head_office']   = isset($_POST['is_head_office']) ? 1 : 0;
    $form['status']           = trim($_POST['status'] ?? 'active');

    if ($form['branch_name'] === '') {
        $error = 'Branch name is required.';
    } elseif ($form['branch_code'] === '') {
        $error = 'Branch code is required.';
    } elseif (!in_array($form['status'], ['active', 'inactive'], true)) {
        $error = 'Invalid status selected.';
    } else {
        /* check duplicate branch code in same business except current row */
        $stmt = $conn->prepare("SELECT id
                                FROM branches
                                WHERE business_id = ?
                                  AND branch_code = ?
                                  AND id != ?
                                LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('isi', $businessId, $form['branch_code'], $branchId);
            $stmt->execute();
            $dup = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($dup) {
                $error = 'Branch code already exists.';
            }
        }
    }

    if ($error === '') {
        $conn->begin_transaction();

        try {
            if ($form['is_head_office'] === 1) {
                $stmtReset = $conn->prepare("UPDATE branches
                                             SET is_head_office = 0
                                             WHERE business_id = ? AND id != ?");
                if (!$stmtReset) {
                    throw new Exception('Failed to prepare head office reset query.');
                }
                $stmtReset->bind_param('ii', $businessId, $branchId);
                $stmtReset->execute();
                $stmtReset->close();
            }

            $sql = "UPDATE branches SET
                        branch_name = ?,
                        branch_code = ?,
                        contact_person = ?,
                        email = ?,
                        mobile = ?,
                        alternate_mobile = ?,
                        gstin = ?,
                        address_line1 = ?,
                        address_line2 = ?,
                        city = ?,
                        district = ?,
                        state = ?,
                        pincode = ?,
                        is_head_office = ?,
                        status = ?,
                        updated_at = NOW()
                    WHERE id = ? AND business_id = ?
                    LIMIT 1";

            $stmt = $conn->prepare($sql);

            if (!$stmt) {
                throw new Exception('Failed to prepare update query.');
            }

            // IMPORTANT: Count the variables being bound
            // 1. branch_name      (s)
            // 2. branch_code      (s)
            // 3. contact_person   (s)
            // 4. email            (s)
            // 5. mobile           (s)
            // 6. alternate_mobile (s)
            // 7. gstin            (s)
            // 8. address_line1    (s)
            // 9. address_line2    (s)
            // 10. city            (s)
            // 11. district        (s)
            // 12. state           (s)
            // 13. pincode         (s)
            // 14. is_head_office  (i)
            // 15. status          (s)
            // 16. id (WHERE)      (i)
            // 17. business_id (WHERE) (i)
            // Total: 17 variables

            $stmt->bind_param(
                'sssssssssssssisii', // 13 s + 1 i + 1 s + 2 i = 17 characters
                $form['branch_name'],
                $form['branch_code'],
                $form['contact_person'],
                $form['email'],
                $form['mobile'],
                $form['alternate_mobile'],
                $form['gstin'],
                $form['address_line1'],
                $form['address_line2'],
                $form['city'],
                $form['district'],
                $form['state'],
                $form['pincode'],
                $form['is_head_office'],  // 14: integer
                $form['status'],           // 15: string
                $branchId,                  // 16: integer
                $businessId                 // 17: integer
            );

            if (!$stmt->execute()) {
                throw new Exception('Failed to update branch: ' . $stmt->error);
            }
            $stmt->close();

            $conn->commit();
            $success = 'Branch updated successfully.';

            /* reload latest values */
            $stmt = $conn->prepare("SELECT *
                                    FROM branches
                                    WHERE id = ? AND business_id = ?
                                    LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('ii', $branchId, $businessId);
                $stmt->execute();
                $branch = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($branch) {
                    $form = [
                        'branch_name'      => $branch['branch_name'] ?? '',
                        'branch_code'      => $branch['branch_code'] ?? '',
                        'contact_person'   => $branch['contact_person'] ?? '',
                        'email'            => $branch['email'] ?? '',
                        'mobile'           => $branch['mobile'] ?? '',
                        'alternate_mobile' => $branch['alternate_mobile'] ?? '',
                        'gstin'            => $branch['gstin'] ?? '',
                        'address_line1'    => $branch['address_line1'] ?? '',
                        'address_line2'    => $branch['address_line2'] ?? '',
                        'city'             => $branch['city'] ?? '',
                        'district'         => $branch['district'] ?? '',
                        'state'            => $branch['state'] ?? '',
                        'pincode'          => $branch['pincode'] ?? '',
                        'is_head_office'   => (int)($branch['is_head_office'] ?? 0),
                        'status'           => $branch['status'] ?? 'active',
                    ];
                }
            }
        } catch (Exception $e) {
            $conn->rollback();
            $error = $e->getMessage();
        }
    }
}

$pageTitle = 'Edit Branch';
$currentPage = 'branches';
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
                        <h4 class="mb-1">Edit Branch</h4>
                        <p class="text-muted mb-0">Update branch details</p>
                    </div>
                    <div class="col-md-6 text-md-end mt-3 mt-md-0">
                        <a href="branch-view.php?id=<?php echo (int)$branchId; ?>" class="btn btn-info">View Branch</a>
                        <a href="branches.php" class="btn btn-secondary">Back</a>
                    </div>
                </div>

                <?php if ($success !== ''): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?php echo h($success); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?php echo h($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <div class="card">
                    <div class="card-body">
                        <form method="post">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Branch Name <span class="text-danger">*</span></label>
                                    <input type="text" name="branch_name" class="form-control" value="<?php echo h($form['branch_name']); ?>" required>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Branch Code <span class="text-danger">*</span></label>
                                    <input type="text" name="branch_code" class="form-control" value="<?php echo h($form['branch_code']); ?>" required>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Contact Person</label>
                                    <input type="text" name="contact_person" class="form-control" value="<?php echo h($form['contact_person']); ?>">
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Email</label>
                                    <input type="email" name="email" class="form-control" value="<?php echo h($form['email']); ?>">
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Mobile</label>
                                    <input type="text" name="mobile" class="form-control" value="<?php echo h($form['mobile']); ?>">
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Alternate Mobile</label>
                                    <input type="text" name="alternate_mobile" class="form-control" value="<?php echo h($form['alternate_mobile']); ?>">
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label class="form-label">GSTIN</label>
                                    <input type="text" name="gstin" class="form-control" value="<?php echo h($form['gstin']); ?>">
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Status</label>
                                    <select name="status" class="form-select">
                                        <option value="active" <?php echo ($form['status'] === 'active') ? 'selected' : ''; ?>>Active</option>
                                        <option value="inactive" <?php echo ($form['status'] === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                                    </select>
                                </div>

                                <div class="col-md-12 mb-3">
                                    <label class="form-label">Address Line 1</label>
                                    <input type="text" name="address_line1" class="form-control" value="<?php echo h($form['address_line1']); ?>">
                                </div>

                                <div class="col-md-12 mb-3">
                                    <label class="form-label">Address Line 2</label>
                                    <input type="text" name="address_line2" class="form-control" value="<?php echo h($form['address_line2']); ?>">
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">City</label>
                                    <input type="text" name="city" class="form-control" value="<?php echo h($form['city']); ?>">
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">District</label>
                                    <input type="text" name="district" class="form-control" value="<?php echo h($form['district']); ?>">
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">State</label>
                                    <input type="text" name="state" class="form-control" value="<?php echo h($form['state']); ?>">
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Pincode</label>
                                    <input type="text" name="pincode" class="form-control" value="<?php echo h($form['pincode']); ?>">
                                </div>

                                <div class="col-md-12 mb-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="is_head_office" id="is_head_office" value="1" <?php echo ((int)$form['is_head_office'] === 1) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="is_head_office">
                                            Mark as Head Office
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-2">
                                <button type="submit" class="btn btn-primary">Update Branch</button>
                                <a href="branch-view.php?id=<?php echo (int)$branchId; ?>" class="btn btn-light">Cancel</a>
                            </div>
                        </form>
                    </div>
                </div>

            </div>
        </div>

        <?php include('includes/footer.php'); ?>
    </div>
</div>

<?php include('includes/rightbar.php'); ?>
<?php include('includes/scripts.php'); ?>

<script>
// Auto-hide alerts after 5 seconds
setTimeout(function() {
    var alerts = document.querySelectorAll('.alert');
    alerts.forEach(function(alert) {
        var bsAlert = new bootstrap.Alert(alert);
        bsAlert.close();
    });
}, 5000);
</script>

</body>
</html>