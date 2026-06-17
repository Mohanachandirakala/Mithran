<header id="page-topbar">
    <div class="navbar-header">
        <div class="d-flex align-items-center">
            <!-- LOGO -->
            <div class="navbar-brand-box">
                <a href="index.php" class="logo logo-dark">
                    <span class="logo-sm">
                        <img src="assets/logo1.png" alt="" height="26">
                    </span>
                    <span class="logo-lg">
                        <img src="assets/logo.png" alt="" height="48">
                    </span>
                </a>

                <a href="index.php" class="logo logo-light">
                    <span class="logo-sm">
                        <img src="assets/logo1.png" alt="" height="26">
                    </span>
                    <span class="logo-lg">
                        <img src="assets/logo.png" alt="" height="48">
                    </span>
                </a>
            </div>

            <button type="button" class="btn btn-sm px-3 font-size-24 header-item waves-effect" id="vertical-menu-btn">
                <i class="mdi mdi-menu"></i>
            </button>

            <div class="d-none d-sm-block ms-2">
                <h4 class="page-title font-size-18 mb-0">
                    <?php echo isset($pageTitle) && $pageTitle !== '' ? htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') : 'Dashboard'; ?>
                </h4>
            </div>
        </div>

        <div class="d-flex align-items-center">

            <!-- QUICK CREATE BUTTONS -->
            <div class="d-none d-xl-flex align-items-center gap-2 me-2 topbar-quick-actions">
                <a href="sales-invoice-add.php" class="btn btn-primary btn-sm topbar-action-btn">
                    <i class="mdi mdi-file-document-plus-outline me-1"></i>
                    Create Sale Invoice
                </a>

                <a href="service-invoice-add.php" class="btn btn-success btn-sm topbar-action-btn">
                    <i class="mdi mdi-tools me-1"></i>
                    Create Invoice
                </a>

                <a href="quotation-add.php" class="btn btn-warning btn-sm topbar-action-btn">
                    <i class="mdi mdi-file-outline me-1"></i>
                    Create Quotation
                </a>
            </div>

            <!-- QUICK CREATE DROPDOWN FOR TABLET / MOBILE -->
            <div class="dropdown d-inline-block d-xl-none me-2">
                <button type="button"
                        class="btn btn-primary btn-sm dropdown-toggle"
                        data-bs-toggle="dropdown"
                        aria-haspopup="true"
                        aria-expanded="false">
                    <i class="mdi mdi-plus-circle-outline me-1"></i>
                    Create
                </button>

                <div class="dropdown-menu dropdown-menu-end">
                    <a class="dropdown-item" href="sales-invoice-add.php">
                        <i class="mdi mdi-file-document-plus-outline me-2 text-primary"></i>
                        Create Sale Invoice
                    </a>

                    <a class="dropdown-item" href="service-invoice-add.php">
                        <i class="mdi mdi-tools me-2 text-success"></i>
                        Create Service Invoice
                    </a>

                    <a class="dropdown-item" href="quotation-add.php">
                        <i class="mdi mdi-file-outline me-2 text-warning"></i>
                        Create Quotation
                    </a>
                </div>
            </div>

            <!-- FULLSCREEN -->
            <div class="dropdown d-none d-lg-inline-block">
                <button type="button" class="btn header-item noti-icon waves-effect" data-bs-toggle="fullscreen">
                    <i class="mdi mdi-fullscreen"></i>
                </button>
            </div>

            <!-- NOTIFICATIONS -->
            <div class="dropdown d-inline-block ms-2">
                <button type="button"
                        class="btn header-item noti-icon waves-effect"
                        id="page-header-notifications-dropdown"
                        data-bs-toggle="dropdown"
                        aria-haspopup="true"
                        aria-expanded="false">
                    <i class="ion ion-md-notifications"></i>
                    <span class="badge bg-danger rounded-pill">3</span>
                </button>

                <div class="dropdown-menu dropdown-menu-lg dropdown-menu-end p-0"
                     aria-labelledby="page-header-notifications-dropdown">

                    <div class="p-3">
                        <div class="row align-items-center">
                            <div class="col">
                                <h5 class="m-0 font-size-16">Notification (3)</h5>
                            </div>
                        </div>
                    </div>

                    <div data-simplebar style="max-height: 230px;">
                        <a href="javascript:void(0);" class="text-reset notification-item">
                            <div class="media d-flex">
                                <div class="avatar-xs me-3">
                                    <span class="avatar-title bg-success rounded-circle font-size-16">
                                        <i class="mdi mdi-domain"></i>
                                    </span>
                                </div>
                                <div class="flex-1">
                                    <h6 class="mt-0 font-size-15 mb-1">New business created</h6>
                                    <div class="font-size-12 text-muted">
                                        <p class="mb-1">A new client business has been added to the platform.</p>
                                    </div>
                                </div>
                            </div>
                        </a>

                        <a href="javascript:void(0);" class="text-reset notification-item">
                            <div class="media d-flex">
                                <div class="avatar-xs me-3">
                                    <span class="avatar-title bg-warning rounded-circle font-size-16">
                                        <i class="mdi mdi-alert-outline"></i>
                                    </span>
                                </div>
                                <div class="flex-1">
                                    <h6 class="mt-0 font-size-15 mb-1">Subscription expiring soon</h6>
                                    <div class="font-size-12 text-muted">
                                        <p class="mb-1">One or more client subscriptions are near expiry.</p>
                                    </div>
                                </div>
                            </div>
                        </a>

                        <a href="javascript:void(0);" class="text-reset notification-item">
                            <div class="media d-flex">
                                <div class="avatar-xs me-3">
                                    <span class="avatar-title bg-info rounded-circle font-size-16">
                                        <i class="mdi mdi-account-multiple-outline"></i>
                                    </span>
                                </div>
                                <div class="flex-1">
                                    <h6 class="mt-0 font-size-15 mb-1">New business user added</h6>
                                    <div class="font-size-12 text-muted">
                                        <p class="mb-1">A new business user account was created successfully.</p>
                                    </div>
                                </div>
                            </div>
                        </a>
                    </div>

                    <div class="p-2 border-top text-center">
                        <a class="btn btn-sm btn-link font-size-14 w-100" href="notifications.php">
                            View all
                        </a>
                    </div>
                </div>
            </div>

            <!-- USER DROPDOWN -->
            <div class="dropdown d-inline-block ms-2">
                <button type="button"
                        class="btn header-item waves-effect"
                        id="page-header-user-dropdown"
                        data-bs-toggle="dropdown"
                        aria-haspopup="true"
                        aria-expanded="false">
                    <img class="rounded-circle header-profile-user"
                         src="assets/images/users/avatar-1.jpg"
                         alt="Header Avatar">
                </button>

                <div class="dropdown-menu dropdown-menu-end">
                    <a class="dropdown-item" href="business-profile.php">
                        <i class="dripicons-user font-size-16 align-middle me-2"></i> Profile
                    </a>

                    <a class="dropdown-item d-block" href="business-settings.php">
                        <i class="dripicons-gear font-size-16 align-middle me-2"></i> Settings
                    </a>

                    <a class="dropdown-item" href="change-password.php">
                        <i class="dripicons-lock font-size-16 align-middle me-2"></i> Change Password
                    </a>

                    <div class="dropdown-divider"></div>

                    <a class="dropdown-item" href="logout.php">
                        <i class="dripicons-exit font-size-16 align-middle me-2"></i> Logout
                    </a>
                </div>
            </div>

            <!-- RIGHT BAR -->
            <div class="dropdown d-inline-block">
                <button type="button" class="btn header-item noti-icon right-bar-toggle waves-effect">
                    <i class="mdi mdi-spin mdi-cog"></i>
                </button>
            </div>

        </div>
    </div>
</header>

<style>
    #page-topbar .navbar-header {
        gap: 12px;
    }

    .topbar-quick-actions {
        white-space: nowrap;
    }

    .topbar-action-btn {
        min-height: 34px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 12px;
        font-weight: 600;
        border-radius: 6px;
        padding: 6px 12px;
        box-shadow: 0 2px 6px rgba(15, 23, 42, 0.08);
    }

    .topbar-action-btn.btn-warning {
        color: #111827 !important;
    }

    @media (max-width: 1400px) {
        .topbar-action-btn {
            padding: 6px 9px;
            font-size: 11px;
        }
    }

    @media (max-width: 1199px) {
        .topbar-quick-actions {
            display: none !important;
        }
    }

    @media (max-width: 575px) {
        #page-topbar .page-title {
            display: none;
        }

        #page-topbar .navbar-header {
            padding-left: 8px;
            padding-right: 8px;
        }
    }
</style>