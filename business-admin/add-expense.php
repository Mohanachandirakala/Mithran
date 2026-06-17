<?php
session_start();
date_default_timezone_set('Asia/Kolkata');

require_once 'includes/config.php';

/*
|--------------------------------------------------------------------------
| Business Admin Add Expense - add-expense.php
|--------------------------------------------------------------------------
| This page allows adding expenses to the expenses table
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
$branchId   = isset($_SESSION['branch_id']) ? (int)$_SESSION['branch_id'] : 0;

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

function money($amount): string
{
    return '₹' . number_format((float)$amount, 2);
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
   FETCH BRANCHES FOR DROPDOWN
------------------------------------------------------- */
$branches = [];
if (tableExists($conn, 'branches')) {
    $sql = "SELECT id, branch_name, branch_code 
            FROM branches 
            WHERE business_id = {$businessId} AND status = 'active'
            ORDER BY branch_name ASC";
    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $branches[] = $row;
        }
    }
}

/* -------------------------------------------------------
   FETCH OR CREATE PAYMENT METHODS
------------------------------------------------------- */
$paymentMethods = [];
$paymentMethodsNeedSetup = false;

if (tableExists($conn, 'payment_methods')) {
    // Check if payment methods exist for this business
    $sql = "SELECT id, method_name, description 
            FROM payment_methods 
            WHERE business_id = {$businessId} AND status = 1
            ORDER BY method_name ASC";
    $res = $conn->query($sql);
    
    if ($res && $res->num_rows > 0) {
        while ($row = $res->fetch_assoc()) {
            $paymentMethods[] = $row;
        }
    } else {
        // No payment methods found, create default ones
        $paymentMethodsNeedSetup = true;
        
        $defaultMethods = [
            ['Cash', 'Physical cash payment'],
            ['Bank Transfer', 'Payment via bank transfer / NEFT / RTGS'],
            ['Cheque', 'Payment by cheque'],
            ['Credit Card', 'Payment via credit card'],
            ['Debit Card', 'Payment via debit card'],
            ['UPI', 'Payment via UPI (Google Pay, PhonePe, etc.)'],
            ['Online Payment', 'Online payment gateway'],
            ['Other', 'Other payment methods']
        ];
        
        foreach ($defaultMethods as $method) {
            $insertStmt = $conn->prepare("INSERT INTO payment_methods (business_id, method_name, description, status, created_at) VALUES (?, ?, ?, 1, NOW())");
            if ($insertStmt) {
                $insertStmt->bind_param("iss", $businessId, $method[0], $method[1]);
                $insertStmt->execute();
                $insertStmt->close();
            }
        }
        
        // Fetch the newly created payment methods
        $res = $conn->query("SELECT id, method_name, description FROM payment_methods WHERE business_id = {$businessId} AND status = 1 ORDER BY method_name ASC");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $paymentMethods[] = $row;
            }
        }
    }
}

/* -------------------------------------------------------
   EXPENSE TYPES (Common expense categories)
------------------------------------------------------- */
$expenseTypes = [
    'Rent' => 'Rent / Lease',
    'Salary' => 'Employee Salary',
    'Electricity' => 'Electricity Bill',
    'Water' => 'Water Bill',
    'Internet' => 'Internet / Broadband',
    'Telephone' => 'Telephone / Mobile',
    'Maintenance' => 'Maintenance & Repairs',
    'Stationery' => 'Office Stationery',
    'Transport' => 'Transport / Fuel',
    'Marketing' => 'Marketing / Advertising',
    'Insurance' => 'Insurance',
    'Tax' => 'Tax / GST Payment',
    'Software' => 'Software Subscription',
    'Training' => 'Staff Training',
    'Travel' => 'Travel Expenses',
    'Miscellaneous' => 'Miscellaneous'
];

/* -------------------------------------------------------
   PROCESS EXPENSE FORM SUBMISSION
------------------------------------------------------- */
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $expense_date = trim($_POST['expense_date'] ?? '');
    $expense_type = trim($_POST['expense_type'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $amount = (float)($_POST['amount'] ?? 0);
    $payment_method_id = !empty($_POST['payment_method_id']) ? (int)$_POST['payment_method_id'] : null;
    $paid_to = trim($_POST['paid_to'] ?? '');
    $bill_no = trim($_POST['bill_no'] ?? '');
    $selected_branch_id = (int)($_POST['branch_id'] ?? $branchId);

    // Validation
    if (empty($expense_date)) {
        $error = 'Please select expense date.';
    } elseif (empty($expense_type)) {
        $error = 'Please select expense type.';
    } elseif ($amount <= 0) {
        $error = 'Please enter a valid amount greater than 0.';
    } elseif ($selected_branch_id <= 0) {
        $error = 'Please select a branch.';
    } else {
        // Verify branch belongs to business
        $branchCheck = $conn->prepare("SELECT id FROM branches WHERE id = ? AND business_id = ? AND status = 'active' LIMIT 1");
        if ($branchCheck) {
            $branchCheck->bind_param("ii", $selected_branch_id, $businessId);
            $branchCheck->execute();
            $branchResult = $branchCheck->get_result();
            if ($branchResult->num_rows === 0) {
                $error = 'Invalid branch selected.';
            }
            $branchCheck->close();
        }

        // Verify payment method if provided
        if (empty($error) && !empty($payment_method_id)) {
            $pmCheck = $conn->prepare("SELECT id FROM payment_methods WHERE id = ? AND business_id = ? AND status = 1 LIMIT 1");
            if ($pmCheck) {
                $pmCheck->bind_param("ii", $payment_method_id, $businessId);
                $pmCheck->execute();
                $pmResult = $pmCheck->get_result();
                if ($pmResult->num_rows === 0) {
                    $error = 'Invalid payment method selected.';
                }
                $pmCheck->close();
            }
        }
    }

    // Insert expense if no errors
    if (empty($error)) {
        $stmt = $conn->prepare("INSERT INTO expenses (
                                    business_id, 
                                    branch_id, 
                                    expense_date, 
                                    expense_type, 
                                    description, 
                                    amount, 
                                    payment_method_id, 
                                    paid_to, 
                                    bill_no, 
                                    created_by, 
                                    created_at
                                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");

        if ($stmt) {
            $stmt->bind_param(
                "iissdisiss",
                $businessId,
                $selected_branch_id,
                $expense_date,
                $expense_type,
                $description,
                $amount,
                $payment_method_id,
                $paid_to,
                $bill_no,
                $businessUserId
            );

            if ($stmt->execute()) {
                $expenseId = $stmt->insert_id;
                $success = 'Expense added successfully!';
                
                // Log to audit
                if (tableExists($conn, 'audit_logs')) {
                    $auditSql = "INSERT INTO audit_logs (business_id, branch_id, user_id, action, module_name, ref_table, ref_id, description) 
                                 VALUES (?, ?, ?, 'Add Expense', 'Expenses', 'expenses', ?, ?)";
                    $auditStmt = $conn->prepare($auditSql);
                    if ($auditStmt) {
                        $desc = "Added expense: " . $expense_type . " - Amount: " . money($amount);
                        $auditStmt->bind_param("iiiis", $businessId, $selected_branch_id, $businessUserId, $expenseId, $desc);
                        $auditStmt->execute();
                        $auditStmt->close();
                    }
                }
                
                // Clear form on success (redirect to prevent resubmission)
                header("Location: add-expense.php?success=1");
                exit;
            } else {
                $error = 'Failed to add expense: ' . $conn->error;
            }
            $stmt->close();
        } else {
            $error = 'Unable to prepare insert query.';
        }
    }
}

// Check for success message from redirect
if (isset($_GET['success']) && $_GET['success'] == 1) {
    $success = 'Expense added successfully!';
}

/* -------------------------------------------------------
   GET TODAY'S EXPENSE SUMMARY
------------------------------------------------------- */
$todayExpenses = 0;
$todayCount = 0;
$monthExpenses = 0;
$monthCount = 0;

if (tableExists($conn, 'expenses')) {
    $today = date('Y-m-d');
    $monthStart = date('Y-m-01');
    $monthEnd = date('Y-m-t');
    
    // Today's expenses
    $sqlToday = "SELECT COALESCE(SUM(amount), 0) as total, COUNT(*) as cnt 
                 FROM expenses 
                 WHERE business_id = {$businessId} AND DATE(expense_date) = '{$today}'";
    $resToday = $conn->query($sqlToday);
    if ($resToday) {
        $row = $resToday->fetch_assoc();
        $todayExpenses = (float)($row['total'] ?? 0);
        $todayCount = (int)($row['cnt'] ?? 0);
    }
    
    // Month expenses
    $sqlMonth = "SELECT COALESCE(SUM(amount), 0) as total, COUNT(*) as cnt 
                 FROM expenses 
                 WHERE business_id = {$businessId} 
                 AND expense_date BETWEEN '{$monthStart}' AND '{$monthEnd}'";
    $resMonth = $conn->query($sqlMonth);
    if ($resMonth) {
        $row = $resMonth->fetch_assoc();
        $monthExpenses = (float)($row['total'] ?? 0);
        $monthCount = (int)($row['cnt'] ?? 0);
    }
}

/* -------------------------------------------------------
   PAGE VARIABLES
------------------------------------------------------- */
$pageTitle = 'Add Expense';
$currentPage = 'add-expense';
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
    .summary-card {
        transition: transform 0.2s;
    }
    .summary-card:hover {
        transform: translateY(-2px);
    }
    .form-section {
        background: #f8f9fa;
        border-radius: 8px;
        padding: 20px;
    }
    .required-field::after {
        content: '*';
        color: red;
        margin-left: 4px;
    }
    .payment-method-info {
        background: #e8f0fe;
        border-radius: 8px;
        padding: 15px;
        margin-top: 10px;
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

                <!-- Welcome Header -->
                <div class="row">
                    <div class="col-12">
                        <div class="card bg-primary text-white">
                            <div class="card-body">
                                <div class="d-flex flex-wrap align-items-center justify-content-between">
                                    <div>
                                        <h4 class="text-white mb-1">
                                            Add Expense
                                        </h4>
                                        <p class="mb-0 opacity-75">
                                            Record and track business expenses
                                        </p>
                                    </div>
                                    <div class="text-end">
                                        <div class="small">Today</div>
                                        <div class="fw-bold"><?php echo date('d M Y, h:i A'); ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Summary Cards -->
                <div class="row">
                    <div class="col-md-6 col-xl-3">
                        <div class="card text-center summary-card">
                            <div class="card-body">
                                <p class="text-muted mb-1">Today's Expenses</p>
                                <h3 class="text-danger mt-2 mb-0"><?php echo money($todayExpenses); ?></h3>
                                <small class="text-muted"><?php echo $todayCount; ?> transactions</small>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="card text-center summary-card">
                            <div class="card-body">
                                <p class="text-muted mb-1">This Month Expenses</p>
                                <h3 class="text-warning mt-2 mb-0"><?php echo money($monthExpenses); ?></h3>
                                <small class="text-muted"><?php echo $monthCount; ?> transactions</small>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="card text-center summary-card">
                            <div class="card-body">
                                <p class="text-muted mb-1">Default Branch</p>
                                <h5 class="mt-2 mb-0"><?php 
                                    $defaultBranch = array_filter($branches, function($b) use ($branchId) { return $b['id'] == $branchId; });
                                    echo h(!empty($defaultBranch) ? reset($defaultBranch)['branch_name'] : 'Select Branch'); 
                                ?></h5>
                                <small class="text-muted">You can change below</small>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="card text-center summary-card">
                            <div class="card-body">
                                <p class="text-muted mb-1">Expense Types</p>
                                <h5 class="mt-2 mb-0"><?php echo count($expenseTypes); ?> Categories</h5>
                                <small class="text-muted">Select appropriate type</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Alert Messages -->
                <?php if ($success !== ''): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="ri-checkbox-circle-line me-2"></i> <?php echo h($success); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="ri-error-warning-line me-2"></i> <?php echo h($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <!-- Add Expense Form -->
                <div class="card">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0">
                            <i class="ri-add-circle-line me-2"></i>Expense Information
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="post" class="needs-validation" novalidate>
                            <div class="row">
                                <!-- Branch Selection -->
                                <div class="col-md-6 mb-3">
                                    <label class="form-label required-field">Branch</label>
                                    <select name="branch_id" class="form-select" required>
                                        <option value="">Select Branch</option>
                                        <?php foreach ($branches as $branch): ?>
                                            <option value="<?php echo $branch['id']; ?>" 
                                                <?php echo ($branch['id'] == $branchId) ? 'selected' : ''; ?>>
                                                <?php echo h($branch['branch_name'] . ' (' . $branch['branch_code'] . ')'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="invalid-feedback">Please select a branch.</div>
                                </div>

                                <!-- Expense Date -->
                                <div class="col-md-6 mb-3">
                                    <label class="form-label required-field">Expense Date</label>
                                    <input type="date" name="expense_date" class="form-control" 
                                           value="<?php echo date('Y-m-d'); ?>" required>
                                    <div class="invalid-feedback">Please select expense date.</div>
                                </div>

                                <!-- Expense Type -->
                                <div class="col-md-6 mb-3">
                                    <label class="form-label required-field">Expense Type</label>
                                    <select name="expense_type" class="form-select" required>
                                        <option value="">Select Expense Type</option>
                                        <?php foreach ($expenseTypes as $key => $value): ?>
                                            <option value="<?php echo h($key); ?>">
                                                <?php echo h($value); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="invalid-feedback">Please select expense type.</div>
                                </div>

                                <!-- Amount -->
                                <div class="col-md-6 mb-3">
                                    <label class="form-label required-field">Amount (₹)</label>
                                    <div class="input-group">
                                        <span class="input-group-text">₹</span>
                                        <input type="number" step="0.01" min="0.01" name="amount" 
                                               class="form-control" placeholder="0.00" required>
                                    </div>
                                    <div class="invalid-feedback">Please enter a valid amount.</div>
                                </div>

                                <!-- Paid To -->
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Paid To</label>
                                    <input type="text" name="paid_to" class="form-control" 
                                           placeholder="Vendor/Supplier/Employee name">
                                    <small class="text-muted">Name of the person or company paid to</small>
                                </div>

                                <!-- Bill/Invoice Number -->
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Bill/Invoice Number</label>
                                    <input type="text" name="bill_no" class="form-control" 
                                           placeholder="Bill or invoice reference number">
                                </div>

                                <!-- Payment Method -->
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Payment Method</label>
                                    <select name="payment_method_id" class="form-select">
                                        <option value="">Select Payment Method (Optional)</option>
                                        <?php foreach ($paymentMethods as $pm): ?>
                                            <option value="<?php echo $pm['id']; ?>">
                                                <?php echo h($pm['method_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="text-muted">How was this expense paid? (Optional)</small>
                                </div>

                                <!-- Description -->
                                <div class="col-12 mb-3">
                                    <label class="form-label">Description</label>
                                    <textarea name="description" class="form-control" rows="3" 
                                              placeholder="Enter detailed description of the expense..."></textarea>
                                    <small class="text-muted">Optional: Add any additional notes or details</small>
                                </div>
                            </div>

                            <!-- Payment Methods Info (if just created) -->
                            <?php if ($paymentMethodsNeedSetup): ?>
                                <div class="payment-method-info mb-3">
                                    <div class="d-flex align-items-center">
                                        <i class="ri-information-line text-info me-2 font-size-18"></i>
                                        <div>
                                            <strong>Payment Methods Added!</strong>
                                            <p class="mb-0 small">Default payment methods have been automatically added to your account. You can manage them in Settings.</p>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div class="row mt-3">
                                <div class="col-12">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="ri-save-line me-1"></i> Save Expense
                                    </button>
                                    <button type="reset" class="btn btn-secondary ms-2">
                                        <i class="ri-refresh-line me-1"></i> Reset
                                    </button>
                                    <a href="expenses-list.php" class="btn btn-outline-info ms-2">
                                        <i class="ri-list-view me-1"></i> View All Expenses
                                    </a>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Quick Tips Card -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="card-title mb-3">
                                    <i class="ri-lightbulb-line me-2"></i>Quick Tips
                                </h5>
                                <div class="row">
                                    <div class="col-md-4 mb-3 mb-md-0">
                                        <div class="d-flex">
                                            <div class="flex-shrink-0 me-3">
                                                <i class="ri-file-copy-line text-info font-size-20"></i>
                                            </div>
                                            <div>
                                                <h6 class="mb-1">Keep Bills/Invoices</h6>
                                                <p class="text-muted small mb-0">Always enter bill/invoice numbers for easy reference and auditing.</p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-4 mb-3 mb-md-0">
                                        <div class="d-flex">
                                            <div class="flex-shrink-0 me-3">
                                                <i class="ri-bank-card-line text-success font-size-20"></i>
                                            </div>
                                            <div>
                                                <h6 class="mb-1">Track Payment Methods</h6>
                                                <p class="text-muted small mb-0">Selecting payment method helps in reconciling accounts.</p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="d-flex">
                                            <div class="flex-shrink-0 me-3">
                                                <i class="ri-folder-chart-line text-warning font-size-20"></i>
                                            </div>
                                            <div>
                                                <h6 class="mb-1">Categorize Correctly</h6>
                                                <p class="text-muted small mb-0">Proper categorization helps in expense analysis and budgeting.</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recent Expenses Preview -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header bg-light d-flex justify-content-between align-items-center">
                                <h5 class="card-title mb-0">
                                    <i class="ri-history-line me-2"></i>Recent Expenses
                                </h5>
                                <a href="expenses-list.php" class="btn btn-sm btn-primary">View All</a>
                            </div>
                            <div class="card-body">
                                <?php
                                // Fetch recent expenses
                                $recentExpenses = [];
                                if (tableExists($conn, 'expenses')) {
                                    $sql = "SELECT e.*, b.branch_name, pm.method_name as payment_method_name
                                            FROM expenses e
                                            LEFT JOIN branches b ON b.id = e.branch_id
                                            LEFT JOIN payment_methods pm ON pm.id = e.payment_method_id
                                            WHERE e.business_id = {$businessId}
                                            ORDER BY e.id DESC
                                            LIMIT 5";
                                    $res = $conn->query($sql);
                                    if ($res) {
                                        while ($row = $res->fetch_assoc()) {
                                            $recentExpenses[] = $row;
                                        }
                                    }
                                }
                                ?>
                                
                                <?php if (!empty($recentExpenses)): ?>
                                    <div class="table-responsive">
                                        <table class="table table-bordered table-striped align-middle mb-0">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Date</th>
                                                    <th>Branch</th>
                                                    <th>Type</th>
                                                    <th>Description</th>
                                                    <th>Paid To</th>
                                                    <th>Payment Method</th>
                                                    <th>Amount</th>
                                                    <th>Bill No</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($recentExpenses as $exp): ?>
                                                    <tr>
                                                        <td><?php echo h(date('d M Y', strtotime($exp['expense_date']))); ?></td>
                                                        <td><?php echo h($exp['branch_name'] ?? '-'); ?></td>
                                                        <td><?php echo h($exp['expense_type']); ?></td>
                                                        <td><?php echo h(substr($exp['description'] ?? '', 0, 40)) . (strlen($exp['description'] ?? '') > 40 ? '...' : ''); ?></td>
                                                        <td><?php echo h($exp['paid_to'] ?? '-'); ?></td>
                                                        <td><?php echo h($exp['payment_method_name'] ?? '-'); ?></td>
                                                        <td><strong class="text-danger"><?php echo money($exp['amount']); ?></strong></td>
                                                        <td><?php echo h($exp['bill_no'] ?? '-'); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <div class="text-center py-4">
                                        <i class="ri-receipt-line font-size-48 text-muted mb-3 d-block"></i>
                                        <p class="text-muted mb-0">No expenses recorded yet. Add your first expense above.</p>
                                    </div>
                                <?php endif; ?>
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

<script>
// Form validation
(function() {
    'use strict';
    const forms = document.querySelectorAll('.needs-validation');
    Array.from(forms).forEach(form => {
        form.addEventListener('submit', event => {
            if (!form.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
            }
            form.classList.add('was-validated');
        }, false);
    });
})();

// Auto-hide alerts after 5 seconds
setTimeout(function() {
    var alerts = document.querySelectorAll('.alert');
    alerts.forEach(function(alert) {
        var bsAlert = new bootstrap.Alert(alert);
        bsAlert.close();
    });
}, 5000);

// Set max date to today for expense date
document.addEventListener('DOMContentLoaded', function() {
    var dateInput = document.querySelector('input[name="expense_date"]');
    if (dateInput) {
        dateInput.max = new Date().toISOString().split('T')[0];
    }
});
</script>

</body>
</html>