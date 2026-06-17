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
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function tableExists(mysqli $conn, string $table): bool
{
    $sql = "SELECT 1
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
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

function fetchAllAssoc(mysqli $conn, string $sql): array
{
    $rows = [];
    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $res->free();
    }
    return $rows;
}

/* -------------------------------------------------------
   VALIDATE TABLES
------------------------------------------------------- */
if (!tableExists($conn, 'business_users') || !tableExists($conn, 'businesses')) {
    die('Required tables not found.');
}

/* -------------------------------------------------------
   LOGGED USER
------------------------------------------------------- */
$loggedUser = null;

$stmt = $conn->prepare("SELECT
                            bu.id,
                            bu.full_name,
                            bu.username,
                            bu.role,
                            bu.status,
                            bu.branch_id,
                            b.business_name,
                            b.status AS business_status
                        FROM business_users bu
                        INNER JOIN businesses b ON b.id = bu.business_id
                        WHERE bu.id = ?
                          AND bu.business_id = ?
                        LIMIT 1");
if ($stmt) {
    $stmt->bind_param('ii', $businessUserId, $businessId);
    $stmt->execute();
    $loggedUser = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if (
    !$loggedUser ||
    (int)($loggedUser['status'] ?? 0) !== 1 ||
    ($loggedUser['business_status'] ?? '') !== 'active'
) {
    session_destroy();
    header('Location: login.php');
    exit;
}

$loggedRole = (string)($loggedUser['role'] ?? '');

/* -------------------------------------------------------
   BRANCHES
------------------------------------------------------- */
$hasBranches = tableExists($conn, 'branches');

$branches = [];
if ($hasBranches) {
    $branches = fetchAllAssoc(
        $conn,
        "SELECT id, branch_name, branch_code, status
         FROM branches
         WHERE business_id = {$businessId}
         ORDER BY branch_name ASC"
    );
}

/* -------------------------------------------------------
   ROLES
   super_admin = all branch access, saved as branch_id NULL
------------------------------------------------------- */
$allowedRoles = [
    'super_admin',
    'owner',
    'admin',
    'manager',
    'sales',
    'billing',
    'service',
    'store'
];

$roleLabels = [
    'super_admin' => 'Super Admin',
    'owner'       => 'Owner',
    'admin'       => 'Admin',
    'manager'     => 'Manager',
    'sales'       => 'Sales',
    'billing'     => 'Billing',
    'service'     => 'Service',
    'store'       => 'Store'
];

/* -------------------------------------------------------
   DEFAULTS
------------------------------------------------------- */
$success = '';
$error = '';

$form = [
    'full_name' => '',
    'username' => '',
    'email' => '',
    'mobile' => '',
    'role' => 'admin',
    'branch_id' => 0,
    'password' => '',
    'confirm_password' => '',
    'status' => 1,
];

/* -------------------------------------------------------
   SAVE
------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['full_name'] = trim($_POST['full_name'] ?? '');
    $form['username'] = trim($_POST['username'] ?? '');
    $form['email'] = trim($_POST['email'] ?? '');
    $form['mobile'] = trim($_POST['mobile'] ?? '');
    $form['role'] = trim($_POST['role'] ?? 'admin');
    $form['branch_id'] = isset($_POST['branch_id']) ? (int)$_POST['branch_id'] : 0;
    $form['password'] = (string)($_POST['password'] ?? '');
    $form['confirm_password'] = (string)($_POST['confirm_password'] ?? '');
    $form['status'] = isset($_POST['status']) ? 1 : 0;

    /*
       IMPORTANT:
       If role is super_admin, branch must be NULL.
       This means that user can access all branches.
    */
    if ($form['role'] === 'super_admin') {
        $form['branch_id'] = 0;
    }

    if ($form['full_name'] === '') {
        $error = 'Full name is required.';
    } elseif ($form['username'] === '') {
        $error = 'Username is required.';
    } elseif (!preg_match('/^[A-Za-z0-9_.-]+$/', $form['username'])) {
        $error = 'Username can contain only letters, numbers, underscore, dot and hyphen.';
    } elseif ($form['role'] === '' || !in_array($form['role'], $allowedRoles, true)) {
        $error = 'Please select a valid role.';
    } elseif ($form['password'] === '') {
        $error = 'Password is required.';
    } elseif (strlen($form['password']) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif ($form['password'] !== $form['confirm_password']) {
        $error = 'Password and confirm password do not match.';
    } else {

        /*
           Validate branch only for normal branch users.
           Super Admin does not need branch validation.
        */
        if ($form['role'] !== 'super_admin' && $hasBranches && $form['branch_id'] > 0) {
            $checkBranch = $conn->prepare("SELECT id
                                           FROM branches
                                           WHERE id = ?
                                             AND business_id = ?
                                           LIMIT 1");
            if ($checkBranch) {
                $checkBranch->bind_param('ii', $form['branch_id'], $businessId);
                $checkBranch->execute();
                $branchRow = $checkBranch->get_result()->fetch_assoc();
                $checkBranch->close();

                if (!$branchRow) {
                    $error = 'Selected branch is invalid.';
                }
            }
        }

        if ($error === '') {
            $stmt = $conn->prepare("SELECT id
                                    FROM business_users
                                    WHERE business_id = ?
                                      AND username = ?
                                    LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('is', $businessId, $form['username']);
                $stmt->execute();
                $exists = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($exists) {
                    $error = 'Username already exists for this business.';
                }
            }
        }

        if ($error === '' && $form['email'] !== '') {
            $stmt = $conn->prepare("SELECT id
                                    FROM business_users
                                    WHERE business_id = ?
                                      AND email = ?
                                    LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('is', $businessId, $form['email']);
                $stmt->execute();
                $exists = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($exists) {
                    $error = 'Email already exists for this business.';
                }
            }
        }

        if ($error === '') {
            $passwordHash = password_hash($form['password'], PASSWORD_DEFAULT);

            /*
               Final branch save logic:
               super_admin => NULL
               branch selected => branch id
               no branch selected => NULL
            */
            $branchIdToSave = null;

            if ($form['role'] !== 'super_admin' && $hasBranches && $form['branch_id'] > 0) {
                $branchIdToSave = $form['branch_id'];
            }

            $stmt = $conn->prepare("INSERT INTO business_users (
                                        business_id,
                                        branch_id,
                                        full_name,
                                        username,
                                        password_hash,
                                        email,
                                        mobile,
                                        role,
                                        status
                                    ) VALUES (?,?,?,?,?,?,?,?,?)");

            if (!$stmt) {
                $error = 'Failed to prepare insert query. Check role enum has super_admin.';
            } else {
                $stmt->bind_param(
                    'iissssssi',
                    $businessId,
                    $branchIdToSave,
                    $form['full_name'],
                    $form['username'],
                    $passwordHash,
                    $form['email'],
                    $form['mobile'],
                    $form['role'],
                    $form['status']
                );

                if ($stmt->execute()) {
                    header('Location: business-users.php?success=' . urlencode('Business user added successfully.'));
                    exit;
                } else {
                    $error = 'Failed to add business user. ' . $stmt->error;
                }

                $stmt->close();
            }
        }
    }
}

$pageTitle = 'Add Business User';
$currentPage = 'business-user-add';
?>
<!doctype html>
<html lang="en">

<?php include('includes/head.php'); ?>

<body data-sidebar="dark">

<style>
    .page-content {
        padding-bottom: 90px !important;
    }

    .card {
        margin-bottom: 24px;
    }

    .main-content {
        min-height: calc(100vh - 70px);
    }

    .role-help-box {
        border: 1px solid #e5e7eb;
        background: #f8fafc;
        border-radius: 8px;
        padding: 10px 12px;
        font-size: 13px;
        color: #475569;
    }

    .super-admin-note {
        display: none;
        margin-top: 6px;
        color: #0f766e;
        font-size: 13px;
        font-weight: 500;
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
                    <div class="col-md-7">
                        <h4 class="mb-1">Add Business User</h4>
                        <p class="text-muted mb-0">Create a new user account for this business</p>
                    </div>
                    <div class="col-md-5 text-md-end mt-3 mt-md-0">
                        <a href="business-users.php" class="btn btn-secondary">Back to Users</a>
                    </div>
                </div>

                <?php if ($success !== ''): ?>
                    <div class="alert alert-success"><?php echo h($success); ?></div>
                <?php endif; ?>

                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger"><?php echo h($error); ?></div>
                <?php endif; ?>

                <form method="post" autocomplete="off">
                    <div class="row">
                        <div class="col-lg-8">

                            <div class="card">
                                <div class="card-header">
                                    <h5 class="mb-0">User Details</h5>
                                </div>

                                <div class="card-body">
                                    <div class="row g-3">

                                        <div class="col-md-6">
                                            <label class="form-label">
                                                Full Name <span class="text-danger">*</span>
                                            </label>
                                            <input
                                                type="text"
                                                name="full_name"
                                                class="form-control"
                                                value="<?php echo h($form['full_name']); ?>"
                                                required
                                            >
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label">
                                                Username <span class="text-danger">*</span>
                                            </label>
                                            <input
                                                type="text"
                                                name="username"
                                                class="form-control"
                                                value="<?php echo h($form['username']); ?>"
                                                required
                                            >
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label">Email</label>
                                            <input
                                                type="email"
                                                name="email"
                                                class="form-control"
                                                value="<?php echo h($form['email']); ?>"
                                            >
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label">Mobile</label>
                                            <input
                                                type="text"
                                                name="mobile"
                                                class="form-control"
                                                value="<?php echo h($form['mobile']); ?>"
                                            >
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label">
                                                Role <span class="text-danger">*</span>
                                            </label>
                                            <select name="role" id="roleSelect" class="form-select" required>
                                                <?php foreach ($allowedRoles as $role): ?>
                                                    <option
                                                        value="<?php echo h($role); ?>"
                                                        <?php echo ($form['role'] === $role) ? 'selected' : ''; ?>
                                                    >
                                                        <?php echo h($roleLabels[$role] ?? ucwords(str_replace('_', ' ', $role))); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>

                                            <div id="superAdminNote" class="super-admin-note">
                                                Super Admin will get access to all branches.
                                            </div>
                                        </div>

                                        <?php if ($hasBranches): ?>
                                            <div class="col-md-6" id="branchBox">
                                                <label class="form-label">Branch</label>
                                                <select name="branch_id" id="branchSelect" class="form-select">
                                                    <option value="0">All Branches / No Branch Restriction</option>

                                                    <?php foreach ($branches as $branch): ?>
                                                        <option
                                                            value="<?php echo (int)$branch['id']; ?>"
                                                            <?php echo ((int)$form['branch_id'] === (int)$branch['id']) ? 'selected' : ''; ?>
                                                        >
                                                            <?php
                                                            $branchText = $branch['branch_name'] . ' (' . $branch['branch_code'] . ')';
                                                            if (($branch['status'] ?? '') !== 'active') {
                                                                $branchText .= ' - Inactive';
                                                            }
                                                            echo h($branchText);
                                                            ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>

                                                <div class="small text-muted mt-1" id="branchHelp">
                                                    Select a branch for normal users. Super Admin automatically gets all branches.
                                                </div>
                                            </div>
                                        <?php endif; ?>

                                        <div class="col-md-6">
                                            <label class="form-label">
                                                Password <span class="text-danger">*</span>
                                            </label>
                                            <input
                                                type="password"
                                                name="password"
                                                class="form-control"
                                                value="<?php echo h($form['password']); ?>"
                                                required
                                            >
                                            <div class="small text-muted mt-1">Minimum 6 characters.</div>
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label">
                                                Confirm Password <span class="text-danger">*</span>
                                            </label>
                                            <input
                                                type="password"
                                                name="confirm_password"
                                                class="form-control"
                                                value="<?php echo h($form['confirm_password']); ?>"
                                                required
                                            >
                                        </div>

                                        <div class="col-md-12">
                                            <div class="form-check mt-2">
                                                <input
                                                    class="form-check-input"
                                                    type="checkbox"
                                                    name="status"
                                                    id="status"
                                                    value="1"
                                                    <?php echo ((int)$form['status'] === 1) ? 'checked' : ''; ?>
                                                >
                                                <label class="form-check-label" for="status">
                                                    Active User
                                                </label>
                                            </div>
                                        </div>

                                    </div>
                                </div>
                            </div>

                        </div>

                        <div class="col-lg-4">

                            <div class="card">
                                <div class="card-header">
                                    <h5 class="mb-0">Current Login User</h5>
                                </div>
                                <div class="card-body small">
                                    <div class="mb-2">
                                        <strong>Name:</strong>
                                        <?php echo h($loggedUser['full_name'] ?? '-'); ?>
                                    </div>

                                    <div class="mb-2">
                                        <strong>Username:</strong>
                                        <?php echo h($loggedUser['username'] ?? '-'); ?>
                                    </div>

                                    <div class="mb-2">
                                        <strong>Role:</strong>
                                        <?php
                                        $currentRole = (string)($loggedUser['role'] ?? '-');
                                        echo h($roleLabels[$currentRole] ?? ucwords(str_replace('_', ' ', $currentRole)));
                                        ?>
                                    </div>

                                    <div class="mb-0">
                                        <strong>Business:</strong>
                                        <?php echo h($loggedUser['business_name'] ?? '-'); ?>
                                    </div>
                                </div>
                            </div>

                            <div class="card">
                                <div class="card-header">
                                    <h5 class="mb-0">Role Notes</h5>
                                </div>

                                <div class="card-body small text-muted">
                                    <div class="mb-2">
                                        <strong>Super Admin:</strong>
                                        Full access to all branches.
                                    </div>
                                    <div class="mb-2">
                                        <strong>Owner:</strong>
                                        Highest access for business.
                                    </div>
                                    <div class="mb-2">
                                        <strong>Admin:</strong>
                                        Full operations access.
                                    </div>
                                    <div class="mb-2">
                                        <strong>Manager:</strong>
                                        Supervisory access.
                                    </div>
                                    <div class="mb-2">
                                        <strong>Sales:</strong>
                                        Sales operations.
                                    </div>
                                    <div class="mb-2">
                                        <strong>Billing:</strong>
                                        Invoice and payment work.
                                    </div>
                                    <div class="mb-2">
                                        <strong>Service:</strong>
                                        Service and job card work.
                                    </div>
                                    <div class="mb-0">
                                        <strong>Store:</strong>
                                        Inventory and stock work.
                                    </div>
                                </div>
                            </div>

                            <div class="card">
                                <div class="card-body">
                                    <button type="submit" class="btn btn-primary w-100">
                                        Save User
                                    </button>
                                    <a href="business-users.php" class="btn btn-light w-100 mt-2">
                                        Cancel
                                    </a>
                                </div>
                            </div>

                        </div>
                    </div>
                </form>

            </div>
        </div>

        <?php include('includes/footer.php'); ?>
    </div>
</div>

<?php include('includes/rightbar.php'); ?>
<?php include('includes/scripts.php'); ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const roleSelect = document.getElementById('roleSelect');
    const branchSelect = document.getElementById('branchSelect');
    const superAdminNote = document.getElementById('superAdminNote');
    const branchHelp = document.getElementById('branchHelp');

    function handleRoleChange() {
        if (!roleSelect) {
            return;
        }

        const selectedRole = roleSelect.value;

        if (selectedRole === 'super_admin') {
            if (branchSelect) {
                branchSelect.value = '0';
                branchSelect.disabled = true;
            }

            if (superAdminNote) {
                superAdminNote.style.display = 'block';
            }

            if (branchHelp) {
                branchHelp.innerHTML = 'Branch is disabled because Super Admin has access to all branches.';
            }
        } else {
            if (branchSelect) {
                branchSelect.disabled = false;
            }

            if (superAdminNote) {
                superAdminNote.style.display = 'none';
            }

            if (branchHelp) {
                branchHelp.innerHTML = 'Select a branch for normal users. Super Admin automatically gets all branches.';
            }
        }
    }

    if (roleSelect) {
        roleSelect.addEventListener('change', handleRoleChange);
        handleRoleChange();
    }

    /*
       Disabled select value will not post.
       Before submit, enable branch field again.
       PHP will force branch_id = NULL for super_admin.
    */
    const form = document.querySelector('form');
    if (form) {
        form.addEventListener('submit', function () {
            if (branchSelect) {
                branchSelect.disabled = false;
            }
        });
    }
});
</script>

</body>
</html>