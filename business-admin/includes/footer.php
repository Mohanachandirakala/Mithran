<?php
$footerBusinessName = 'Business';

if (isset($_SESSION['business_name']) && trim((string)$_SESSION['business_name']) !== '') {
    $footerBusinessName = $_SESSION['business_name'];
} elseif (isset($loggedUser['business_name']) && trim((string)$loggedUser['business_name']) !== '') {
    $footerBusinessName = $loggedUser['business_name'];
} else {
    $businessId = isset($_SESSION['business_id']) ? (int)$_SESSION['business_id'] : 0;

    if ($businessId > 0 && isset($conn) && $conn instanceof mysqli) {
        $stmt = $conn->prepare("SELECT business_name FROM businesses WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $businessId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!empty($row['business_name'])) {
                $footerBusinessName = $row['business_name'];
                $_SESSION['business_name'] = $row['business_name'];
            }
        }
    }
}
?>

<footer class="footer">
            <div class="container-fluid">
                <div class="row">
                    <div class="col-12">
                        ©
                        <script>document.write(new Date().getFullYear())</script> Mithran Bikes <span class="d-none d-sm-inline-block"> -
                            Crafted with <i class="mdi mdi-heart text-danger"></i> by <a href="ecommer.in">Ecommer</a></span>
                    </div>
                </div>
            </div>
        </footer>