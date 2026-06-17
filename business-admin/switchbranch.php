<?php
session_start();
date_default_timezone_set('Asia/Kolkata');

require_once 'includes/config.php';

/*
|--------------------------------------------------------------------------
| Business Admin Switch Branch - switch-branch.php
|--------------------------------------------------------------------------
| Allows business users to switch between branches
|--------------------------------------------------------------------------
*/

if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not available.");
}
$conn->set_charset("utf8mb4");

/* -------------------------------------------------------
   AUTH CHECK
------------------------------------------------------- */
$businessUserId = isset($_SESSION['business_user_id']) ? (int)$_SESSION['business_user_id'] : 0;
if ($businessUserId <= 0 && isset($_SESSION['user_id'])) {
    $businessUserId = (int)$_SESSION['user_id'];
}

$businessId = isset($_SESSION['business_id']) ? (int)$_SESSION['business_id'] : 0;
$currentBranchId = isset($_SESSION['branch_id']) ? (int)$_SESSION['branch_id'] : 0;

if ($businessUserId <= 0 || $businessId <= 0) {
    header("Location: login.php");
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

    $stmt->bind_param("s", $table);
    $stmt->execute();
    $res = $stmt->get_result();
    $exists = $res && $res->num_rows > 0;
    $stmt->close();

    return $exists;
}

/* -------------------------------------------------------
   FETCH LOGGED-IN BUSINESS USER
------------------------------------------------------- */
$admin = null;
if (tableExists($conn, 'business_users') && tableExists($conn, 'businesses')) {
    $sql = "SELECT 
                bu.id,
                bu.business_id,
                bu.branch_id,
                bu.full_name,
                bu.username,
                bu.email,
                bu.mobile,
                bu.role,
                bu.status,
                b.business_name,
                b.business_code,
                b.gstin,
                b.status AS business_status
            FROM business_users bu
            INNER JOIN businesses b ON b.id = bu.business_id
            WHERE bu.id = ? AND bu.business_id = ?
            LIMIT 1";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("ii", $businessUserId, $businessId);
        $stmt->execute();
        $admin = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}

if (!$admin || (int)$admin['status'] !== 1 || ($admin['business_status'] ?? '') !== 'active') {
    session_destroy();
    header("Location: login.php");
    exit;
}

/* -------------------------------------------------------
   HANDLE BRANCH SWITCH
------------------------------------------------------- */
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'switch') {
    $newBranchId = (int)($_POST['branch_id'] ?? 0);
    
    if ($newBranchId <= 0) {
        $error = 'Please select a valid branch.';
    } else {
        // Verify branch belongs to this business
        $stmt = $conn->prepare("SELECT id, branch_name, branch_code, status FROM branches WHERE id = ? AND business_id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("ii", $newBranchId, $businessId);
            $stmt->execute();
            $result = $stmt->get_result();
            $branch = $result->fetch_assoc();
            $stmt->close();
            
            if ($branch) {
                // Clear old session data related to the previous branch
                unset($_SESSION['branch_id']);
                unset($_SESSION['branch_name']);
                unset($_SESSION['branch_code']);
                
                // Set new branch in session
                $_SESSION['branch_id'] = $newBranchId;
                $_SESSION['branch_name'] = $branch['branch_name'];
                $_SESSION['branch_code'] = $branch['branch_code'];
                
                // Update user's default branch in database
                $updateStmt = $conn->prepare("UPDATE business_users SET branch_id = ? WHERE id = ? AND business_id = ?");
                if ($updateStmt) {
                    $updateStmt->bind_param("iii", $newBranchId, $businessUserId, $businessId);
                    $updateStmt->execute();
                    $updateStmt->close();
                }
                
                // Log the action
                if (tableExists($conn, 'audit_logs')) {
                    $desc = "Switched to branch: " . $branch['branch_name'];
                    $logStmt = $conn->prepare("INSERT INTO audit_logs (business_id, branch_id, user_id, action, module_name, description, created_at) 
                                              VALUES (?, ?, ?, 'Switch Branch', 'Branches', ?, NOW())");
                    if ($logStmt) {
                        $logStmt->bind_param("iiis", $businessId, $newBranchId, $businessUserId, $desc);
                        $logStmt->execute();
                        $logStmt->close();
                    }
                }
                
                $success = 'Successfully switched to ' . $branch['branch_name'] . ' branch.';
                
                // Force session write and regenerate ID for security
                session_write_close();
                session_start();
                session_regenerate_id(true);
                
                // Redirect to dashboard after switching
                header("Location: index.php?branch_switched=1");
                exit;
            } else {
                $error = 'Branch not found or does not belong to this business.';
            }
        } else {
            $error = 'Database error. Please try again.';
        }
    }
}

/* -------------------------------------------------------
   FETCH ALL BRANCHES FOR THIS BUSINESS
------------------------------------------------------- */
$branches = [];
if (tableExists($conn, 'branches')) {
    $sql = "SELECT 
                id, 
                branch_name, 
                branch_code,
                contact_person,
                email,
                mobile,
                address_line1,
                city,
                state,
                is_head_office,
                status,
                created_at
            FROM branches 
            WHERE business_id = {$businessId} 
            ORDER BY 
                CASE WHEN id = {$currentBranchId} THEN 0 ELSE 1 END,
                is_head_office DESC,
                branch_name ASC";
    
    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $branches[] = $row;
        }
    }
}

// If no branches found, show helpful message
if (empty($branches)) {
    $error = 'No branches found for this business. Please contact your administrator.';
}

// Get current branch details
$currentBranch = null;
foreach ($branches as $b) {
    if ((int)$b['id'] === $currentBranchId) {
        $currentBranch = $b;
        break;
    }
}

// If current branch not set but branches exist, set first branch as default
if (!$currentBranch && !empty($branches)) {
    $currentBranch = $branches[0];
    $_SESSION['branch_id'] = $currentBranch['id'];
    $_SESSION['branch_name'] = $currentBranch['branch_name'];
    $_SESSION['branch_code'] = $currentBranch['branch_code'];
    $currentBranchId = $currentBranch['id'];
}

$pageTitle = 'Switch Branch';
$currentPage = 'switch-branch';
?>
<!doctype html>
<html lang="en">

<?php include('includes/head.php'); ?>

<body data-sidebar="dark">

<style>
    .branch-card {
        transition: all 0.2s ease;
        cursor: pointer;
        border: 2px solid transparent;
        height: 100%;
    }
    .branch-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 8px 20px rgba(0,0,0,0.1);
        border-color: #3b82f6;
    }
    .branch-card.active {
        border-color: #10b981;
        background: #f0fdf4;
    }
    .branch-card.inactive {
        opacity: 0.8;
        background: #fef2f2;
    }
    .branch-card .badge-head-office {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        font-size: 10px;
        padding: 3px 8px;
        border-radius: 20px;
    }
    .branch-icon {
        width: 50px;
        height: 50px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 24px;
        background: #f3f4f6;
    }
    .branch-card.active .branch-icon {
        background: #10b981;
        color: white;
    }
    .branch-card.inactive .branch-icon {
        background: #ef4444;
        color: white;
    }
    .current-branch-badge {
        background: #10b981;
        color: white;
        padding: 2px 10px;
        border-radius: 20px;
        font-size: 12px;
    }
    .warning-note {
        background: #fef3c7;
        border-left: 4px solid #f59e0b;
        padding: 15px;
        border-radius: 8px;
        margin-bottom: 20px;
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

                <div class="row">
                    <div class="col-12">
                        <div class="card bg-primary text-white">
                            <div class="card-body">
                                <div class="d-flex flex-wrap align-items-center justify-content-between">
                                    <div>
                                        <h4 class="text-white mb-1">
                                            <i class="ri-store-3-line me-2"></i>Switch Branch
                                        </h4>
                                        <p class="mb-0 opacity-75">
                                            <?php echo h($admin['business_name'] ?? ''); ?>
                                            <?php if ($currentBranch): ?>
                                                | Current: <strong><?php echo h($currentBranch['branch_name']); ?></strong>
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                    <div class="text-end">
                                        <a href="index.php" class="btn btn-light">
                                            <i class="ri-arrow-go-back-line me-1"></i>Back to Dashboard
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Important Note -->
                <div class="row">
                    <div class="col-12">
                        <div class="warning-note">
                            <div class="d-flex align-items-start">
                                <i class="ri-information-fill me-3" style="font-size: 24px; color: #f59e0b;"></i>
                                <div>
                                    <strong>Important:</strong> Switching branches will change the data you see across all pages (sales, customers, products, stock, etc.). 
                                    Each branch has its own separate data. After switching, you will only see data belonging to the selected branch.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($success !== ''): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="ri-check-line me-1"></i><?php echo h($success); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="ri-error-warning-line me-1"></i><?php echo h($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <!-- Current Branch Summary -->
                <?php if ($currentBranch): ?>
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header bg-light">
                                <h5 class="card-title mb-0">
                                    <i class="ri-map-pin-line me-2" style="color: #10b981;"></i>Current Branch
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row align-items-center">
                                    <div class="col-md-8">
                                        <div class="d-flex align-items-center">
                                            <div class="branch-icon" style="background: #10b981; color: white; margin-right: 20px;">
                                                <i class="ri-store-2-fill"></i>
                                            </div>
                                            <div>
                                                <h4 class="mb-1"><?php echo h($currentBranch['branch_name']); ?></h4>
                                                <p class="text-muted mb-1">
                                                    <i class="ri-barcode-line me-1"></i>Code: <?php echo h($currentBranch['branch_code']); ?>
                                                    <?php if ($currentBranch['is_head_office']): ?>
                                                        <span class="badge-head-office ms-2">Head Office</span>
                                                    <?php endif; ?>
                                                </p>
                                                <p class="text-muted mb-0">
                                                    <?php if (!empty($currentBranch['address_line1'])): ?>
                                                        <i class="ri-map-pin-line me-1"></i><?php echo h($currentBranch['address_line1']); ?>
                                                        <?php if (!empty($currentBranch['city'])): ?>
                                                            , <?php echo h($currentBranch['city']); ?>
                                                        <?php endif; ?>
                                                        <?php if (!empty($currentBranch['state'])): ?>
                                                            , <?php echo h($currentBranch['state']); ?>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-4 text-md-end mt-3 mt-md-0">
                                        <span class="current-branch-badge">
                                            <i class="ri-check-line me-1"></i>Currently Active
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Branch List -->
                <?php if (!empty($branches)): ?>
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header bg-light">
                                <h5 class="card-title mb-0">
                                    <i class="ri-building-line me-2"></i>Select Branch to Switch
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row g-4">
                                    <?php foreach ($branches as $branch): ?>
                                        <?php $isCurrent = ((int)$branch['id'] === $currentBranchId); ?>
                                        <?php $isActive = ($branch['status'] === 'active'); ?>
                                        <div class="col-md-6 col-xl-4">
                                            <div class="card branch-card <?php echo $isCurrent ? 'active' : ''; ?> <?php echo !$isActive ? 'inactive' : ''; ?>" 
                                                 <?php if (!$isCurrent): ?>
                                                 onclick="switchBranch(<?php echo (int)$branch['id']; ?>, '<?php echo h(addslashes($branch['branch_name'])); ?>')"
                                                 <?php endif; ?>>
                                                <div class="card-body">
                                                    <div class="d-flex align-items-start mb-3">
                                                        <div class="branch-icon me-3">
                                                            <?php if ($branch['is_head_office']): ?>
                                                                <i class="ri-vip-crown-fill"></i>
                                                            <?php else: ?>
                                                                <i class="ri-store-2-fill"></i>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="flex-grow-1">
                                                            <div class="d-flex align-items-center justify-content-between">
                                                                <h5 class="mb-1"><?php echo h($branch['branch_name']); ?></h5>
                                                                <?php if ($branch['is_head_office']): ?>
                                                                    <span class="badge-head-office">Head Office</span>
                                                                <?php endif; ?>
                                                            </div>
                                                            <p class="text-muted mb-1">
                                                                <i class="ri-barcode-line me-1"></i><?php echo h($branch['branch_code']); ?>
                                                            </p>
                                                        </div>
                                                    </div>
                                                    
                                                    <?php if (!empty($branch['address_line1'])): ?>
                                                    <p class="text-muted mb-2 small">
                                                        <i class="ri-map-pin-line me-1"></i>
                                                        <?php echo h($branch['address_line1']); ?>
                                                        <?php if (!empty($branch['city'])): ?>
                                                            <br><?php echo h($branch['city']); ?>
                                                            <?php if (!empty($branch['state'])): ?>
                                                                , <?php echo h($branch['state']); ?>
                                                            <?php endif; ?>
                                                        <?php endif; ?>
                                                    </p>
                                                    <?php endif; ?>
                                                    
                                                    <?php if (!empty($branch['contact_person'])): ?>
                                                    <p class="text-muted mb-1 small">
                                                        <i class="ri-user-line me-1"></i><?php echo h($branch['contact_person']); ?>
                                                    </p>
                                                    <?php endif; ?>
                                                    
                                                    <?php if (!empty($branch['mobile'])): ?>
                                                    <p class="text-muted mb-1 small">
                                                        <i class="ri-phone-line me-1"></i><?php echo h($branch['mobile']); ?>
                                                    </p>
                                                    <?php endif; ?>
                                                    
                                                    <?php if (!empty($branch['email'])): ?>
                                                    <p class="text-muted mb-2 small">
                                                        <i class="ri-mail-line me-1"></i><?php echo h($branch['email']); ?>
                                                    </p>
                                                    <?php endif; ?>
                                                    
                                                    <div class="d-flex align-items-center justify-content-between mt-3 pt-2 border-top">
                                                        <span class="badge bg-<?php echo $isActive ? 'success' : 'danger'; ?>">
                                                            <?php echo h(ucfirst($branch['status'])); ?>
                                                        </span>
                                                        
                                                        <?php if ($isCurrent): ?>
                                                            <span class="text-success">
                                                                <i class="ri-checkbox-circle-fill me-1"></i>Current Branch
                                                            </span>
                                                        <?php else: ?>
                                                            <button type="button" class="btn btn-sm btn-primary" 
                                                                    onclick="event.stopPropagation(); switchBranch(<?php echo (int)$branch['id']; ?>, '<?php echo h(addslashes($branch['branch_name'])); ?>')">
                                                                <i class="ri-swap-line me-1"></i>Switch to this branch
                                                            </button>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Branch Statistics -->
                <?php if (!empty($branches)): ?>
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header bg-light">
                                <h5 class="card-title mb-0">
                                    <i class="ri-bar-chart-2-line me-2"></i>Branch Summary
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row text-center">
                                    <div class="col-md-3 col-6">
                                        <div class="border rounded p-3">
                                            <p class="text-muted mb-1">Total Branches</p>
                                            <h3 class="mb-0 text-primary"><?php echo count($branches); ?></h3>
                                        </div>
                                    </div>
                                    <div class="col-md-3 col-6">
                                        <div class="border rounded p-3">
                                            <p class="text-muted mb-1">Active Branches</p>
                                            <h3 class="mb-0 text-success">
                                                <?php 
                                                $activeCount = 0;
                                                foreach ($branches as $b) {
                                                    if ($b['status'] === 'active') $activeCount++;
                                                }
                                                echo $activeCount;
                                                ?>
                                            </h3>
                                        </div>
                                    </div>
                                    <div class="col-md-3 col-6 mt-3 mt-md-0">
                                        <div class="border rounded p-3">
                                            <p class="text-muted mb-1">Head Office</p>
                                            <h3 class="mb-0 text-warning">
                                                <?php 
                                                $headOfficeCount = 0;
                                                foreach ($branches as $b) {
                                                    if ($b['is_head_office']) $headOfficeCount++;
                                                }
                                                echo $headOfficeCount;
                                                ?>
                                            </h3>
                                        </div>
                                    </div>
                                    <div class="col-md-3 col-6 mt-3 mt-md-0">
                                        <div class="border rounded p-3">
                                            <p class="text-muted mb-1">Inactive Branches</p>
                                            <h3 class="mb-0 text-danger">
                                                <?php 
                                                $inactiveCount = 0;
                                                foreach ($branches as $b) {
                                                    if ($b['status'] !== 'active') $inactiveCount++;
                                                }
                                                echo $inactiveCount;
                                                ?>
                                            </h3>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

            </div>
        </div>

        <?php include('includes/footer.php'); ?>
    </div>
</div>

<!-- Switch Confirmation Form -->
<form method="post" id="switchForm" style="display: none;">
    <input type="hidden" name="action" value="switch">
    <input type="hidden" name="branch_id" id="switchBranchId">
</form>

<?php include('includes/rightbar.php'); ?>
<?php include('includes/scripts.php'); ?>

<script>
function switchBranch(branchId, branchName) {
    if (confirm('Are you sure you want to switch to "' + branchName + '" branch?\n\nAll data displayed will change to show only this branch\'s information.\n\nYour current session will be updated.')) {
        document.getElementById('switchBranchId').value = branchId;
        document.getElementById('switchForm').submit();
    }
}

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