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

$pageTitle = 'User Roles & Permissions';
$currentPage = 'user-roles';

$roles = [
    [
        'role' => 'owner',
        'label' => 'Owner',
        'description' => 'Full business control with access to all branches and all modules.',
        'permissions' => [
            'Manage business profile',
            'Manage all branches',
            'Manage all business users',
            'View all sales, service, stock and accounts',
            'Full reports access',
            'Approve high-level operations'
        ]
    ],
    [
        'role' => 'admin',
        'label' => 'Admin',
        'description' => 'Manages daily business administration and operations.',
        'permissions' => [
            'Manage branches',
            'Manage business users',
            'Manage customers',
            'Manage sales and service records',
            'View reports',
            'Manage stock and payments'
        ]
    ],
    [
        'role' => 'manager',
        'label' => 'Manager',
        'description' => 'Operational management role for one or more branches.',
        'permissions' => [
            'View branch operations',
            'Approve sales and service activities',
            'View stock reports',
            'Monitor collections and expenses',
            'Manage staff performance'
        ]
    ],
    [
        'role' => 'sales',
        'label' => 'Sales',
        'description' => 'Handles customer enquiries, quotations, bookings and sales invoices.',
        'permissions' => [
            'Add customers',
            'Create quotations',
            'Create sales invoices',
            'View vehicle stock',
            'Track deliveries'
        ]
    ],
    [
        'role' => 'billing',
        'label' => 'Billing',
        'description' => 'Handles invoice generation, payment entry and receipts.',
        'permissions' => [
            'Generate invoices',
            'Record payments',
            'View invoice history',
            'Print bills and receipts'
        ]
    ],
    [
        'role' => 'service',
        'label' => 'Service',
        'description' => 'Handles job cards, complaints, service invoices and service follow-up.',
        'permissions' => [
            'Create service job cards',
            'Add complaints',
            'Create service invoices',
            'Track vehicle service history'
        ]
    ],
    [
        'role' => 'store',
        'label' => 'Store',
        'description' => 'Handles vehicle stock, spare parts stock and stock movements.',
        'permissions' => [
            'Manage vehicle stock',
            'Manage spare parts stock',
            'Update stock movements',
            'Monitor low stock items'
        ]
    ],
];
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


                <div class="row">
                    <?php foreach ($roles as $item): ?>
                        <div class="col-xl-6">
                            <div class="card">
                                <div class="card-body">
                                    <div class="d-flex align-items-center justify-content-between mb-3">
                                        <h4 class="card-title mb-0"><?php echo h($item['label']); ?></h4>
                                        <span class="badge bg-primary"><?php echo h($item['role']); ?></span>
                                    </div>

                                    <p class="text-muted"><?php echo h($item['description']); ?></p>

                                    <h6 class="mt-4 mb-3">Permissions</h6>
                                    <ul class="mb-0 ps-3">
                                        <?php foreach ($item['permissions'] as $permission): ?>
                                            <li class="mb-2"><?php echo h($permission); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-3">Notes</h4>
                                <div class="alert alert-info mb-0">
                                    These are default business roles used in the platform. 
                                    You can use them while creating business users in <strong>business-user-add.php</strong> 
                                    and editing them in <strong>business-user-edit.php</strong>.
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