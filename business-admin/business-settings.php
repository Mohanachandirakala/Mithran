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
$sessionBranchId = isset($_SESSION['branch_id']) ? (int)$_SESSION['branch_id'] : 0;

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

function columnExists(mysqli $conn, string $table, string $column): bool
{
    $sql = "SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
            LIMIT 1";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();

    $res = $stmt->get_result();
    $exists = $res && $res->num_rows > 0;

    $stmt->close();

    return $exists;
}

function uploadLogoFile(array $file, string $folderName, string $prefix): array
{
    if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return ['success' => false, 'message' => 'No file uploaded.'];
    }

    if ((int)$file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'File upload failed.'];
    }

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/jpg'  => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    $mime = mime_content_type($file['tmp_name']);

    if (!isset($allowed[$mime])) {
        return ['success' => false, 'message' => 'Only JPG, PNG and WEBP files are allowed.'];
    }

    $ext = $allowed[$mime];

    $uploadDirFs = __DIR__ . '/uploads/' . $folderName . '/';
    $uploadDirDb = 'uploads/' . $folderName . '/';

    if (!is_dir($uploadDirFs)) {
        if (!mkdir($uploadDirFs, 0777, true) && !is_dir($uploadDirFs)) {
            return ['success' => false, 'message' => 'Unable to create upload directory.'];
        }
    }

    $safePrefix = preg_replace('/[^A-Za-z0-9_-]/', '_', $prefix);
    $fileName = $safePrefix . '_' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext;

    $targetFs = $uploadDirFs . $fileName;
    $targetDb = $uploadDirDb . $fileName;

    if (!move_uploaded_file($file['tmp_name'], $targetFs)) {
        return ['success' => false, 'message' => 'Failed to move uploaded file.'];
    }

    return ['success' => true, 'path' => $targetDb];
}

/* -------------------------------------------------------
   VALIDATE USER
------------------------------------------------------- */
$loggedUser = null;

if (tableExists($conn, 'business_users') && tableExists($conn, 'businesses')) {
    $stmt = $conn->prepare("SELECT
                                bu.id,
                                bu.full_name,
                                bu.username,
                                bu.role,
                                bu.branch_id,
                                bu.status,
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

/* -------------------------------------------------------
   ACCESS LOGIC
------------------------------------------------------- */
$isSuperAdmin = ((string)($loggedUser['role'] ?? '') === 'super_admin');
$loggedUserBranchId = isset($loggedUser['branch_id']) ? (int)$loggedUser['branch_id'] : 0;

if ($isSuperAdmin) {
    $selectedBranchId = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : 0;

    if ($selectedBranchId < 0) {
        $selectedBranchId = 0;
    }
} else {
    $selectedBranchId = $loggedUserBranchId > 0 ? $loggedUserBranchId : $sessionBranchId;
}

$isBranchMode = ($selectedBranchId > 0);

/* -------------------------------------------------------
   TABLE CHECKS
------------------------------------------------------- */
$requiredTables = ['businesses', 'business_settings'];

foreach ($requiredTables as $tbl) {
    if (!tableExists($conn, $tbl)) {
        die($tbl . ' table not found.');
    }
}

$hasBranches = tableExists($conn, 'branches');
$hasBranchLogoPath = $hasBranches && columnExists($conn, 'branches', 'logo_path');

/* -------------------------------------------------------
   LOAD BRANCH LIST
------------------------------------------------------- */
$branches = [];

if ($hasBranches) {
    if ($isSuperAdmin) {
        $branchSql = "
            SELECT id, branch_name, branch_code, status
            FROM branches
            WHERE business_id = {$businessId}
            ORDER BY branch_name ASC
        ";
    } else {
        $safeBranchId = (int)$selectedBranchId;

        $branchSql = "
            SELECT id, branch_name, branch_code, status
            FROM branches
            WHERE business_id = {$businessId}
              AND id = {$safeBranchId}
            ORDER BY branch_name ASC
        ";
    }

    $branchResult = $conn->query($branchSql);

    if ($branchResult) {
        while ($row = $branchResult->fetch_assoc()) {
            $branches[] = $row;
        }

        $branchResult->free();
    }
}

/* -------------------------------------------------------
   LOAD CURRENT DATA
------------------------------------------------------- */
$business = [];
$settings = [];
$branch = [];

$stmt = $conn->prepare("SELECT *
                        FROM businesses
                        WHERE id = ?
                        LIMIT 1");
if ($stmt) {
    $stmt->bind_param('i', $businessId);
    $stmt->execute();
    $business = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
}

if ($hasBranches && $selectedBranchId > 0) {
    $stmt = $conn->prepare("SELECT *
                            FROM branches
                            WHERE id = ?
                              AND business_id = ?
                            LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('ii', $selectedBranchId, $businessId);
        $stmt->execute();
        $branch = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
    }

    if (empty($branch) && $isSuperAdmin) {
        $selectedBranchId = 0;
        $isBranchMode = false;
    }
}

$stmt = $conn->prepare("SELECT *
                        FROM business_settings
                        WHERE business_id = ?
                        LIMIT 1");
if ($stmt) {
    $stmt->bind_param('i', $businessId);
    $stmt->execute();
    $settings = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
}

if (empty($business)) {
    die('Business not found.');
}

/* -------------------------------------------------------
   DEFAULT SETTINGS ROW IF NOT EXISTS
------------------------------------------------------- */
if (empty($settings)) {
    $stmt = $conn->prepare("INSERT INTO business_settings (
                                business_id,
                                invoice_prefix,
                                service_invoice_prefix,
                                quotation_prefix,
                                payment_receipt_prefix,
                                timezone,
                                currency_symbol,
                                tax_type,
                                invoice_terms,
                                service_terms,
                                show_logo_on_invoice
                            ) VALUES (?, 'INV', 'SRV', 'QTN', 'PAY', 'Asia/Kolkata', '₹', 'exclusive', NULL, NULL, 1)");

    if ($stmt) {
        $stmt->bind_param('i', $businessId);
        $stmt->execute();
        $stmt->close();
    }

    $stmt = $conn->prepare("SELECT *
                            FROM business_settings
                            WHERE business_id = ?
                            LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $businessId);
        $stmt->execute();
        $settings = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
    }
}

/* -------------------------------------------------------
   DEFAULT FORM
------------------------------------------------------- */
$success = '';
$error = '';

$form = [
    'business_name' => (string)($business['business_name'] ?? ''),
    'business_code' => (string)($business['business_code'] ?? ''),
    'owner_name' => (string)($business['owner_name'] ?? ''),
    'email' => (string)($business['email'] ?? ''),
    'mobile' => (string)($business['mobile'] ?? ''),
    'alternate_mobile' => (string)($business['alternate_mobile'] ?? ''),
    'gstin' => (string)($business['gstin'] ?? ''),
    'pan_no' => (string)($business['pan_no'] ?? ''),
    'address_line1' => (string)($business['address_line1'] ?? ''),
    'address_line2' => (string)($business['address_line2'] ?? ''),
    'city' => (string)($business['city'] ?? ''),
    'district' => (string)($business['district'] ?? ''),
    'state' => (string)($business['state'] ?? ''),
    'pincode' => (string)($business['pincode'] ?? ''),

    'branch_name' => (string)($branch['branch_name'] ?? ''),
    'branch_code' => (string)($branch['branch_code'] ?? ''),
    'contact_person' => (string)($branch['contact_person'] ?? ''),
    'branch_email' => (string)($branch['email'] ?? ''),
    'branch_mobile' => (string)($branch['mobile'] ?? ''),
    'branch_alternate_mobile' => (string)($branch['alternate_mobile'] ?? ''),
    'branch_gstin' => (string)($branch['gstin'] ?? ''),
    'branch_address_line1' => (string)($branch['address_line1'] ?? ''),
    'branch_address_line2' => (string)($branch['address_line2'] ?? ''),
    'branch_city' => (string)($branch['city'] ?? ''),
    'branch_district' => (string)($branch['district'] ?? ''),
    'branch_state' => (string)($branch['state'] ?? ''),
    'branch_pincode' => (string)($branch['pincode'] ?? ''),
    'branch_status' => (string)($branch['status'] ?? 'active'),
    'branch_logo_path' => (string)($branch['logo_path'] ?? ''),

    'invoice_prefix' => (string)($settings['invoice_prefix'] ?? 'INV'),
    'service_invoice_prefix' => (string)($settings['service_invoice_prefix'] ?? 'SRV'),
    'quotation_prefix' => (string)($settings['quotation_prefix'] ?? 'QTN'),
    'payment_receipt_prefix' => (string)($settings['payment_receipt_prefix'] ?? 'PAY'),
    'timezone' => (string)($settings['timezone'] ?? 'Asia/Kolkata'),
    'currency_symbol' => (string)($settings['currency_symbol'] ?? '₹'),
    'tax_type' => (string)($settings['tax_type'] ?? 'exclusive'),
    'invoice_terms' => (string)($settings['invoice_terms'] ?? ''),
    'service_terms' => (string)($settings['service_terms'] ?? ''),
    'show_logo_on_invoice' => (int)($settings['show_logo_on_invoice'] ?? 1),
];

$allowedTimezones = [
    'Asia/Kolkata',
    'Asia/Dubai',
    'Asia/Singapore',
    'Europe/London',
    'America/New_York',
];

$allowedTaxTypes = ['inclusive', 'exclusive'];
$allowedBranchStatuses = ['active', 'inactive'];

/* -------------------------------------------------------
   SAVE
------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedBranchId = isset($_POST['selected_branch_id']) ? (int)$_POST['selected_branch_id'] : 0;

    if ($isSuperAdmin) {
        $selectedBranchId = $postedBranchId > 0 ? $postedBranchId : 0;
    }

    $isBranchMode = ($selectedBranchId > 0);

    if ($isBranchMode) {
        $form['branch_name'] = trim($_POST['branch_name'] ?? '');
        $form['branch_code'] = strtoupper(trim($_POST['branch_code'] ?? ''));
        $form['contact_person'] = trim($_POST['contact_person'] ?? '');
        $form['branch_email'] = trim($_POST['branch_email'] ?? '');
        $form['branch_mobile'] = trim($_POST['branch_mobile'] ?? '');
        $form['branch_alternate_mobile'] = trim($_POST['branch_alternate_mobile'] ?? '');
        $form['branch_gstin'] = strtoupper(trim($_POST['branch_gstin'] ?? ''));
        $form['branch_address_line1'] = trim($_POST['branch_address_line1'] ?? '');
        $form['branch_address_line2'] = trim($_POST['branch_address_line2'] ?? '');
        $form['branch_city'] = trim($_POST['branch_city'] ?? '');
        $form['branch_district'] = trim($_POST['branch_district'] ?? '');
        $form['branch_state'] = trim($_POST['branch_state'] ?? '');
        $form['branch_pincode'] = trim($_POST['branch_pincode'] ?? '');
        $form['branch_status'] = trim($_POST['branch_status'] ?? 'active');
    } else {
        $form['business_name'] = trim($_POST['business_name'] ?? '');
        $form['business_code'] = strtoupper(trim($_POST['business_code'] ?? ''));
        $form['owner_name'] = trim($_POST['owner_name'] ?? '');
        $form['email'] = trim($_POST['email'] ?? '');
        $form['mobile'] = trim($_POST['mobile'] ?? '');
        $form['alternate_mobile'] = trim($_POST['alternate_mobile'] ?? '');
        $form['gstin'] = strtoupper(trim($_POST['gstin'] ?? ''));
        $form['pan_no'] = strtoupper(trim($_POST['pan_no'] ?? ''));
        $form['address_line1'] = trim($_POST['address_line1'] ?? '');
        $form['address_line2'] = trim($_POST['address_line2'] ?? '');
        $form['city'] = trim($_POST['city'] ?? '');
        $form['district'] = trim($_POST['district'] ?? '');
        $form['state'] = trim($_POST['state'] ?? '');
        $form['pincode'] = trim($_POST['pincode'] ?? '');
    }

    $form['invoice_prefix'] = strtoupper(trim($_POST['invoice_prefix'] ?? 'INV'));
    $form['service_invoice_prefix'] = strtoupper(trim($_POST['service_invoice_prefix'] ?? 'SRV'));
    $form['quotation_prefix'] = strtoupper(trim($_POST['quotation_prefix'] ?? 'QTN'));
    $form['payment_receipt_prefix'] = strtoupper(trim($_POST['payment_receipt_prefix'] ?? 'PAY'));
    $form['timezone'] = trim($_POST['timezone'] ?? 'Asia/Kolkata');
    $form['currency_symbol'] = trim($_POST['currency_symbol'] ?? '₹');
    $form['tax_type'] = trim($_POST['tax_type'] ?? 'exclusive');
    $form['invoice_terms'] = trim($_POST['invoice_terms'] ?? '');
    $form['service_terms'] = trim($_POST['service_terms'] ?? '');
    $form['show_logo_on_invoice'] = isset($_POST['show_logo_on_invoice']) ? 1 : 0;

    if ($isBranchMode) {
        if (!$hasBranches) {
            $error = 'Branches table not found.';
        } elseif (!$hasBranchLogoPath) {
            $error = 'branches.logo_path column missing. Please run ALTER TABLE branches ADD COLUMN logo_path varchar(255) DEFAULT NULL AFTER status;';
        } elseif ($form['branch_name'] === '') {
            $error = 'Branch name is required.';
        } elseif ($form['branch_code'] === '') {
            $error = 'Branch code is required.';
        } elseif (!in_array($form['branch_status'], $allowedBranchStatuses, true)) {
            $error = 'Please select a valid branch status.';
        }
    } else {
        if ($form['business_name'] === '') {
            $error = 'Business name is required.';
        } elseif ($form['business_code'] === '') {
            $error = 'Business code is required.';
        }
    }

    if ($error === '') {
        if ($form['timezone'] === '' || !in_array($form['timezone'], $allowedTimezones, true)) {
            $error = 'Please select a valid timezone.';
        } elseif (!in_array($form['tax_type'], $allowedTaxTypes, true)) {
            $error = 'Please select a valid tax type.';
        }
    }

    if ($error === '') {
        $logoPath = (string)($business['logo_path'] ?? '');
        $branchLogoPath = (string)($branch['logo_path'] ?? '');

        if (isset($_FILES['logo_path']) && !empty($_FILES['logo_path']['name'])) {
            if ($isBranchMode) {
                $upload = uploadLogoFile($_FILES['logo_path'], 'branches', 'branch_' . $selectedBranchId);
            } else {
                $upload = uploadLogoFile($_FILES['logo_path'], 'business', 'business');
            }

            if (!$upload['success']) {
                $error = $upload['message'];
            } else {
                if ($isBranchMode) {
                    $branchLogoPath = $upload['path'];
                } else {
                    $logoPath = $upload['path'];
                }
            }
        }

        if ($error === '') {
            $conn->begin_transaction();

            try {
                if ($isBranchMode) {
                    $stmt = $conn->prepare("UPDATE branches
                                            SET branch_name = ?,
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
                                                status = ?,
                                                logo_path = ?
                                            WHERE id = ?
                                              AND business_id = ?
                                            LIMIT 1");

                    if (!$stmt) {
                        throw new Exception('Failed to prepare branch update query.');
                    }

                    $stmt->bind_param(
                        'sssssssssssssssii',
                        $form['branch_name'],
                        $form['branch_code'],
                        $form['contact_person'],
                        $form['branch_email'],
                        $form['branch_mobile'],
                        $form['branch_alternate_mobile'],
                        $form['branch_gstin'],
                        $form['branch_address_line1'],
                        $form['branch_address_line2'],
                        $form['branch_city'],
                        $form['branch_district'],
                        $form['branch_state'],
                        $form['branch_pincode'],
                        $form['branch_status'],
                        $branchLogoPath,
                        $selectedBranchId,
                        $businessId
                    );

                    if (!$stmt->execute()) {
                        throw new Exception('Failed to update branch details.');
                    }

                    $stmt->close();
                } else {
                    $stmt = $conn->prepare("UPDATE businesses
                                            SET business_name = ?,
                                                business_code = ?,
                                                owner_name = ?,
                                                email = ?,
                                                mobile = ?,
                                                alternate_mobile = ?,
                                                gstin = ?,
                                                pan_no = ?,
                                                address_line1 = ?,
                                                address_line2 = ?,
                                                city = ?,
                                                district = ?,
                                                state = ?,
                                                pincode = ?,
                                                logo_path = ?
                                            WHERE id = ?
                                            LIMIT 1");

                    if (!$stmt) {
                        throw new Exception('Failed to prepare business update query.');
                    }

                    $stmt->bind_param(
                        'sssssssssssssssi',
                        $form['business_name'],
                        $form['business_code'],
                        $form['owner_name'],
                        $form['email'],
                        $form['mobile'],
                        $form['alternate_mobile'],
                        $form['gstin'],
                        $form['pan_no'],
                        $form['address_line1'],
                        $form['address_line2'],
                        $form['city'],
                        $form['district'],
                        $form['state'],
                        $form['pincode'],
                        $logoPath,
                        $businessId
                    );

                    if (!$stmt->execute()) {
                        throw new Exception('Failed to update business details.');
                    }

                    $stmt->close();
                }

                $stmt = $conn->prepare("UPDATE business_settings
                                        SET invoice_prefix = ?,
                                            service_invoice_prefix = ?,
                                            quotation_prefix = ?,
                                            payment_receipt_prefix = ?,
                                            timezone = ?,
                                            currency_symbol = ?,
                                            tax_type = ?,
                                            invoice_terms = ?,
                                            service_terms = ?,
                                            show_logo_on_invoice = ?
                                        WHERE business_id = ?
                                        LIMIT 1");

                if (!$stmt) {
                    throw new Exception('Failed to prepare business settings update query.');
                }

                $stmt->bind_param(
                    'sssssssssii',
                    $form['invoice_prefix'],
                    $form['service_invoice_prefix'],
                    $form['quotation_prefix'],
                    $form['payment_receipt_prefix'],
                    $form['timezone'],
                    $form['currency_symbol'],
                    $form['tax_type'],
                    $form['invoice_terms'],
                    $form['service_terms'],
                    $form['show_logo_on_invoice'],
                    $businessId
                );

                if (!$stmt->execute()) {
                    throw new Exception('Failed to update business settings.');
                }

                $stmt->close();

                $conn->commit();

                $success = $isBranchMode
                    ? 'Branch details, branch logo and business settings updated successfully.'
                    : 'Business settings updated successfully.';

                if ($isSuperAdmin) {
                    header('Location: business-settings.php?branch_id=' . (int)$selectedBranchId . '&success=' . urlencode($success));
                    exit;
                }

                header('Location: business-settings.php?success=' . urlencode($success));
                exit;

            } catch (Throwable $e) {
                $conn->rollback();
                $error = $e->getMessage();
            }
        }
    }
}

/* -------------------------------------------------------
   SUCCESS FROM REDIRECT
------------------------------------------------------- */
if ($success === '' && isset($_GET['success'])) {
    $success = trim($_GET['success']);
}

/* -------------------------------------------------------
   RELOAD DATA AFTER SAVE
------------------------------------------------------- */
$stmt = $conn->prepare("SELECT *
                        FROM businesses
                        WHERE id = ?
                        LIMIT 1");
if ($stmt) {
    $stmt->bind_param('i', $businessId);
    $stmt->execute();
    $business = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
}

$branch = [];

if ($hasBranches && $selectedBranchId > 0) {
    $stmt = $conn->prepare("SELECT *
                            FROM branches
                            WHERE id = ?
                              AND business_id = ?
                            LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('ii', $selectedBranchId, $businessId);
        $stmt->execute();
        $branch = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
    }
}

$stmt = $conn->prepare("SELECT *
                        FROM business_settings
                        WHERE business_id = ?
                        LIMIT 1");
if ($stmt) {
    $stmt->bind_param('i', $businessId);
    $stmt->execute();
    $settings = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
}

$isBranchMode = ($selectedBranchId > 0 && !empty($branch));

$form = [
    'business_name' => (string)($business['business_name'] ?? ''),
    'business_code' => (string)($business['business_code'] ?? ''),
    'owner_name' => (string)($business['owner_name'] ?? ''),
    'email' => (string)($business['email'] ?? ''),
    'mobile' => (string)($business['mobile'] ?? ''),
    'alternate_mobile' => (string)($business['alternate_mobile'] ?? ''),
    'gstin' => (string)($business['gstin'] ?? ''),
    'pan_no' => (string)($business['pan_no'] ?? ''),
    'address_line1' => (string)($business['address_line1'] ?? ''),
    'address_line2' => (string)($business['address_line2'] ?? ''),
    'city' => (string)($business['city'] ?? ''),
    'district' => (string)($business['district'] ?? ''),
    'state' => (string)($business['state'] ?? ''),
    'pincode' => (string)($business['pincode'] ?? ''),

    'branch_name' => (string)($branch['branch_name'] ?? ''),
    'branch_code' => (string)($branch['branch_code'] ?? ''),
    'contact_person' => (string)($branch['contact_person'] ?? ''),
    'branch_email' => (string)($branch['email'] ?? ''),
    'branch_mobile' => (string)($branch['mobile'] ?? ''),
    'branch_alternate_mobile' => (string)($branch['alternate_mobile'] ?? ''),
    'branch_gstin' => (string)($branch['gstin'] ?? ''),
    'branch_address_line1' => (string)($branch['address_line1'] ?? ''),
    'branch_address_line2' => (string)($branch['address_line2'] ?? ''),
    'branch_city' => (string)($branch['city'] ?? ''),
    'branch_district' => (string)($branch['district'] ?? ''),
    'branch_state' => (string)($branch['state'] ?? ''),
    'branch_pincode' => (string)($branch['pincode'] ?? ''),
    'branch_status' => (string)($branch['status'] ?? 'active'),
    'branch_logo_path' => (string)($branch['logo_path'] ?? ''),

    'invoice_prefix' => (string)($settings['invoice_prefix'] ?? 'INV'),
    'service_invoice_prefix' => (string)($settings['service_invoice_prefix'] ?? 'SRV'),
    'quotation_prefix' => (string)($settings['quotation_prefix'] ?? 'QTN'),
    'payment_receipt_prefix' => (string)($settings['payment_receipt_prefix'] ?? 'PAY'),
    'timezone' => (string)($settings['timezone'] ?? 'Asia/Kolkata'),
    'currency_symbol' => (string)($settings['currency_symbol'] ?? '₹'),
    'tax_type' => (string)($settings['tax_type'] ?? 'exclusive'),
    'invoice_terms' => (string)($settings['invoice_terms'] ?? ''),
    'service_terms' => (string)($settings['service_terms'] ?? ''),
    'show_logo_on_invoice' => (int)($settings['show_logo_on_invoice'] ?? 1),
];

$pageTitle = 'Business Settings';
$currentPage = 'business-settings';
?>
<!doctype html>
<html lang="en">

<?php include('includes/head.php'); ?>

<body data-sidebar="dark">

<style>
    .settings-mode-badge {
        display: inline-flex;
        align-items: center;
        padding: 5px 10px;
        border-radius: 999px;
        font-size: 12px;
        font-weight: 700;
    }

    .settings-mode-business {
        background: #eef2ff;
        color: #3730a3;
        border: 1px solid #c7d2fe;
    }

    .settings-mode-branch {
        background: #ecfdf5;
        color: #047857;
        border: 1px solid #a7f3d0;
    }

    .branch-filter-card {
        border-left: 4px solid #0d6efd;
    }

    .logo-preview-box {
        max-width: 100%;
        max-height: 180px;
        object-fit: contain;
        border: 1px solid #ddd;
        padding: 8px;
        border-radius: 8px;
        background: #fff;
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
                        <h4 class="mb-1">Business Settings</h4>
                        <p class="text-muted mb-0">
                            Manage business profile, branch details, separate branch logo and invoice settings
                        </p>
                    </div>

                    <div class="col-md-5 text-md-end mt-3 mt-md-0">
                        <a href="index.php" class="btn btn-secondary">Back to Dashboard</a>
                    </div>
                </div>

                <?php if ($isSuperAdmin && $hasBranches): ?>
                    <div class="row mb-3">
                        <div class="col-12">
                            <div class="card branch-filter-card">
                                <div class="card-body">
                                    <form method="get" class="row g-3 align-items-end">
                                        <div class="col-md-4">
                                            <label class="form-label fw-bold">Select Branch</label>

                                            <select name="branch_id" class="form-select" onchange="this.form.submit()">
                                                <option value="0" <?php echo ($selectedBranchId === 0) ? 'selected' : ''; ?>>
                                                    -- Main Business Details --
                                                </option>

                                                <?php foreach ($branches as $br): ?>
                                                    <?php
                                                    $branchId = (int)$br['id'];
                                                    $branchLabel = $br['branch_name'] . ' (' . $br['branch_code'] . ')';

                                                    if (($br['status'] ?? '') !== 'active') {
                                                        $branchLabel .= ' - Inactive';
                                                    }
                                                    ?>
                                                    <option value="<?php echo $branchId; ?>" <?php echo ($selectedBranchId === $branchId) ? 'selected' : ''; ?>>
                                                        <?php echo h($branchLabel); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>

                                            <div class="small text-muted mt-1">
                                                Super Admin can switch main business or any branch.
                                            </div>
                                        </div>

                                        <div class="col-md-8">
                                            <?php if ($isBranchMode): ?>
                                                <div class="alert alert-info mb-0">
                                                    <strong>Branch Mode:</strong>
                                                    Updating selected branch data and selected branch logo only.
                                                </div>
                                            <?php else: ?>
                                                <div class="alert alert-secondary mb-0">
                                                    <strong>Main Business Mode:</strong>
                                                    Updating main business data and main business logo.
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($success !== ''): ?>
                    <div class="alert alert-success"><?php echo h($success); ?></div>
                <?php endif; ?>

                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger"><?php echo h($error); ?></div>
                <?php endif; ?>

                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="selected_branch_id" value="<?php echo (int)$selectedBranchId; ?>">

                    <div class="row">

                        <div class="col-lg-8">

                            <?php if ($isBranchMode): ?>

                                <div class="card">
                                    <div class="card-header d-flex align-items-center justify-content-between">
                                        <h5 class="mb-0">Branch Details</h5>
                                        <span class="settings-mode-badge settings-mode-branch">Branch Mode</span>
                                    </div>

                                    <div class="card-body">
                                        <div class="row g-3">

                                            <div class="col-md-6">
                                                <label class="form-label">Branch Name <span class="text-danger">*</span></label>
                                                <input type="text" name="branch_name" class="form-control" value="<?php echo h($form['branch_name']); ?>" required>
                                            </div>

                                            <div class="col-md-6">
                                                <label class="form-label">Branch Code <span class="text-danger">*</span></label>
                                                <input type="text" name="branch_code" class="form-control" value="<?php echo h($form['branch_code']); ?>" required>
                                            </div>

                                            <div class="col-md-6">
                                                <label class="form-label">Contact Person</label>
                                                <input type="text" name="contact_person" class="form-control" value="<?php echo h($form['contact_person']); ?>">
                                            </div>

                                            <div class="col-md-6">
                                                <label class="form-label">Branch Status</label>
                                                <select name="branch_status" class="form-select">
                                                    <option value="active" <?php echo ($form['branch_status'] === 'active') ? 'selected' : ''; ?>>Active</option>
                                                    <option value="inactive" <?php echo ($form['branch_status'] === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                                                </select>
                                            </div>

                                            <div class="col-md-6">
                                                <label class="form-label">Email</label>
                                                <input type="email" name="branch_email" class="form-control" value="<?php echo h($form['branch_email']); ?>">
                                            </div>

                                            <div class="col-md-6">
                                                <label class="form-label">Mobile</label>
                                                <input type="text" name="branch_mobile" class="form-control" value="<?php echo h($form['branch_mobile']); ?>">
                                            </div>

                                            <div class="col-md-6">
                                                <label class="form-label">Alternate Mobile</label>
                                                <input type="text" name="branch_alternate_mobile" class="form-control" value="<?php echo h($form['branch_alternate_mobile']); ?>">
                                            </div>

                                            <div class="col-md-6">
                                                <label class="form-label">GSTIN</label>
                                                <input type="text" name="branch_gstin" class="form-control" value="<?php echo h($form['branch_gstin']); ?>">
                                            </div>

                                            <div class="col-md-6">
                                                <label class="form-label">Address Line 1</label>
                                                <input type="text" name="branch_address_line1" class="form-control" value="<?php echo h($form['branch_address_line1']); ?>">
                                            </div>

                                            <div class="col-md-6">
                                                <label class="form-label">Address Line 2</label>
                                                <input type="text" name="branch_address_line2" class="form-control" value="<?php echo h($form['branch_address_line2']); ?>">
                                            </div>

                                            <div class="col-md-3">
                                                <label class="form-label">City</label>
                                                <input type="text" name="branch_city" class="form-control" value="<?php echo h($form['branch_city']); ?>">
                                            </div>

                                            <div class="col-md-3">
                                                <label class="form-label">District</label>
                                                <input type="text" name="branch_district" class="form-control" value="<?php echo h($form['branch_district']); ?>">
                                            </div>

                                            <div class="col-md-3">
                                                <label class="form-label">State</label>
                                                <input type="text" name="branch_state" class="form-control" value="<?php echo h($form['branch_state']); ?>">
                                            </div>

                                            <div class="col-md-3">
                                                <label class="form-label">Pincode</label>
                                                <input type="text" name="branch_pincode" class="form-control" value="<?php echo h($form['branch_pincode']); ?>">
                                            </div>

                                        </div>
                                    </div>
                                </div>

                            <?php else: ?>

                                <div class="card">
                                    <div class="card-header d-flex align-items-center justify-content-between">
                                        <h5 class="mb-0">Business Details</h5>
                                        <span class="settings-mode-badge settings-mode-business">Main Business Mode</span>
                                    </div>

                                    <div class="card-body">
                                        <div class="row g-3">

                                            <div class="col-md-6">
                                                <label class="form-label">Business Name <span class="text-danger">*</span></label>
                                                <input type="text" name="business_name" class="form-control" value="<?php echo h($form['business_name']); ?>" required>
                                            </div>

                                            <div class="col-md-6">
                                                <label class="form-label">Business Code <span class="text-danger">*</span></label>
                                                <input type="text" name="business_code" class="form-control" value="<?php echo h($form['business_code']); ?>" required>
                                            </div>

                                            <div class="col-md-6">
                                                <label class="form-label">Owner Name</label>
                                                <input type="text" name="owner_name" class="form-control" value="<?php echo h($form['owner_name']); ?>">
                                            </div>

                                            <div class="col-md-6">
                                                <label class="form-label">Email</label>
                                                <input type="email" name="email" class="form-control" value="<?php echo h($form['email']); ?>">
                                            </div>

                                            <div class="col-md-6">
                                                <label class="form-label">Mobile</label>
                                                <input type="text" name="mobile" class="form-control" value="<?php echo h($form['mobile']); ?>">
                                            </div>

                                            <div class="col-md-6">
                                                <label class="form-label">Alternate Mobile</label>
                                                <input type="text" name="alternate_mobile" class="form-control" value="<?php echo h($form['alternate_mobile']); ?>">
                                            </div>

                                            <div class="col-md-6">
                                                <label class="form-label">GSTIN</label>
                                                <input type="text" name="gstin" class="form-control" value="<?php echo h($form['gstin']); ?>">
                                            </div>

                                            <div class="col-md-6">
                                                <label class="form-label">PAN No</label>
                                                <input type="text" name="pan_no" class="form-control" value="<?php echo h($form['pan_no']); ?>">
                                            </div>

                                            <div class="col-md-6">
                                                <label class="form-label">Address Line 1</label>
                                                <input type="text" name="address_line1" class="form-control" value="<?php echo h($form['address_line1']); ?>">
                                            </div>

                                            <div class="col-md-6">
                                                <label class="form-label">Address Line 2</label>
                                                <input type="text" name="address_line2" class="form-control" value="<?php echo h($form['address_line2']); ?>">
                                            </div>

                                            <div class="col-md-3">
                                                <label class="form-label">City</label>
                                                <input type="text" name="city" class="form-control" value="<?php echo h($form['city']); ?>">
                                            </div>

                                            <div class="col-md-3">
                                                <label class="form-label">District</label>
                                                <input type="text" name="district" class="form-control" value="<?php echo h($form['district']); ?>">
                                            </div>

                                            <div class="col-md-3">
                                                <label class="form-label">State</label>
                                                <input type="text" name="state" class="form-control" value="<?php echo h($form['state']); ?>">
                                            </div>

                                            <div class="col-md-3">
                                                <label class="form-label">Pincode</label>
                                                <input type="text" name="pincode" class="form-control" value="<?php echo h($form['pincode']); ?>">
                                            </div>

                                        </div>
                                    </div>
                                </div>

                            <?php endif; ?>

                            <div class="card">
                                <div class="card-header">
                                    <h5 class="mb-0">Invoice & Service Settings</h5>
                                </div>

                                <div class="card-body">
                                    <div class="row g-3">

                                        <div class="col-md-3">
                                            <label class="form-label">Invoice Prefix</label>
                                            <input type="text" name="invoice_prefix" maxlength="20" class="form-control" value="<?php echo h($form['invoice_prefix']); ?>">
                                        </div>

                                        <div class="col-md-3">
                                            <label class="form-label">Service Invoice Prefix</label>
                                            <input type="text" name="service_invoice_prefix" maxlength="20" class="form-control" value="<?php echo h($form['service_invoice_prefix']); ?>">
                                        </div>

                                        <div class="col-md-3">
                                            <label class="form-label">Quotation Prefix</label>
                                            <input type="text" name="quotation_prefix" maxlength="20" class="form-control" value="<?php echo h($form['quotation_prefix']); ?>">
                                        </div>

                                        <div class="col-md-3">
                                            <label class="form-label">Payment Receipt Prefix</label>
                                            <input type="text" name="payment_receipt_prefix" maxlength="20" class="form-control" value="<?php echo h($form['payment_receipt_prefix']); ?>">
                                        </div>

                                        <div class="col-md-4">
                                            <label class="form-label">Timezone</label>
                                            <select name="timezone" class="form-select">
                                                <?php foreach ($allowedTimezones as $tz): ?>
                                                    <option value="<?php echo h($tz); ?>" <?php echo ($form['timezone'] === $tz) ? 'selected' : ''; ?>>
                                                        <?php echo h($tz); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="col-md-4">
                                            <label class="form-label">Currency Symbol</label>
                                            <input type="text" name="currency_symbol" maxlength="10" class="form-control" value="<?php echo h($form['currency_symbol']); ?>">
                                        </div>

                                        <div class="col-md-4">
                                            <label class="form-label">Tax Type</label>
                                            <select name="tax_type" class="form-select">
                                                <option value="exclusive" <?php echo ($form['tax_type'] === 'exclusive') ? 'selected' : ''; ?>>Exclusive</option>
                                                <option value="inclusive" <?php echo ($form['tax_type'] === 'inclusive') ? 'selected' : ''; ?>>Inclusive</option>
                                            </select>
                                        </div>

                                        <div class="col-md-12">
                                            <label class="form-label">Invoice Terms</label>
                                            <textarea name="invoice_terms" rows="4" class="form-control"><?php echo h($form['invoice_terms']); ?></textarea>
                                        </div>

                                        <div class="col-md-12">
                                            <label class="form-label">Service Terms</label>
                                            <textarea name="service_terms" rows="4" class="form-control"><?php echo h($form['service_terms']); ?></textarea>
                                        </div>

                                        <div class="col-md-12">
                                            <div class="form-check mt-2">
                                                <input class="form-check-input" type="checkbox" name="show_logo_on_invoice" id="show_logo_on_invoice" value="1" <?php echo ((int)$form['show_logo_on_invoice'] === 1) ? 'checked' : ''; ?>>
                                                <label class="form-check-label" for="show_logo_on_invoice">
                                                    Show logo on invoice
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
                                    <h5 class="mb-0">
                                        <?php echo $isBranchMode ? 'Branch Logo' : 'Business Logo'; ?>
                                    </h5>
                                </div>

                                <div class="card-body text-center">
                                    <?php
                                    $displayLogoPath = '';

                                    if ($isBranchMode && !empty($branch['logo_path'])) {
                                        $displayLogoPath = $branch['logo_path'];
                                    } elseif (!$isBranchMode && !empty($business['logo_path'])) {
                                        $displayLogoPath = $business['logo_path'];
                                    }
                                    ?>

                                    <?php if (!empty($displayLogoPath)): ?>
                                        <div class="mb-3">
                                            <img src="<?php echo h($displayLogoPath); ?>" alt="Logo" class="logo-preview-box">
                                        </div>
                                    <?php else: ?>
                                        <div class="mb-3 text-muted">
                                            <?php echo $isBranchMode ? 'No branch logo uploaded.' : 'No business logo uploaded.'; ?>
                                        </div>
                                    <?php endif; ?>

                                    <div class="mb-3 text-start">
                                        <label class="form-label">
                                            Upload <?php echo $isBranchMode ? 'Branch Logo' : 'Business Logo'; ?>
                                        </label>

                                        <input type="file" name="logo_path" class="form-control" accept=".jpg,.jpeg,.png,.webp">

                                        <div class="small text-muted mt-1">
                                            <?php if ($isBranchMode): ?>
                                                Allowed: JPG, PNG, WEBP. This logo is saved only for selected branch.
                                            <?php else: ?>
                                                Allowed: JPG, PNG, WEBP. This logo is saved only for main business.
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <?php if ($isBranchMode): ?>
                                <div class="card">
                                    <div class="card-header">
                                        <h5 class="mb-0">Selected Branch</h5>
                                    </div>

                                    <div class="card-body small">
                                        <div class="mb-2">
                                            <strong>Branch ID:</strong>
                                            <?php echo (int)$selectedBranchId; ?>
                                        </div>

                                        <div class="mb-2">
                                            <strong>Name:</strong>
                                            <?php echo h($form['branch_name'] ?: '-'); ?>
                                        </div>

                                        <div class="mb-2">
                                            <strong>Code:</strong>
                                            <?php echo h($form['branch_code'] ?: '-'); ?>
                                        </div>

                                        <div class="mb-2">
                                            <strong>Status:</strong>
                                            <?php echo h(ucfirst($form['branch_status'] ?: '-')); ?>
                                        </div>

                                        <div class="mb-0 text-muted">
                                            Branch logo is saved in branches.logo_path.
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div class="card">
                                <div class="card-header">
                                    <h5 class="mb-0">Quick Info</h5>
                                </div>

                                <div class="card-body small">
                                    <div class="mb-2">
                                        <strong>Business ID:</strong>
                                        <?php echo (int)$businessId; ?>
                                    </div>

                                    <div class="mb-2">
                                        <strong>Business Status:</strong>
                                        <?php echo h($business['status'] ?? '-'); ?>
                                    </div>

                                    <div class="mb-2">
                                        <strong>Current Mode:</strong>
                                        <?php echo $isBranchMode ? 'Branch Details' : 'Main Business Details'; ?>
                                    </div>

                                    <div class="mb-2">
                                        <strong>Login Role:</strong>
                                        <?php echo h(ucwords(str_replace('_', ' ', (string)($loggedUser['role'] ?? '-')))); ?>
                                    </div>

                                    <div class="mb-2">
                                        <strong>Created At:</strong>
                                        <?php echo !empty($business['created_at']) ? h(date('d M Y h:i A', strtotime($business['created_at']))) : '-'; ?>
                                    </div>

                                    <div class="mb-2">
                                        <strong>Updated At:</strong>
                                        <?php echo !empty($business['updated_at']) ? h(date('d M Y h:i A', strtotime($business['updated_at']))) : '-'; ?>
                                    </div>
                                </div>
                            </div>

                            <div class="card">
                                <div class="card-body">
                                    <button type="submit" class="btn btn-primary w-100">
                                        <?php echo $isBranchMode ? 'Save Branch Settings' : 'Save Business Settings'; ?>
                                    </button>

                                    <a href="index.php" class="btn btn-light w-100 mt-2">
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

</body>
</html>