<?php
/**
 * Developed by Rameez Scripts
 * WhatsApp: https://wa.me/923224083545 (For Custom Projects)
 * YouTube: https://www.youtube.com/@rameezimdad (Subscribe for more!)
 */
require_once 'config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Check session timeout
if (!checkSessionTimeout()) {
    header("Location: login.php");
    exit();
}

$username = $_SESSION['username'];
$role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];
$current_page = 'pricing';

// RBAC
$allowedRoles = ['Admin', 'Manager', 'Sales Officer', 'Procurement Officer', 'Finance Officer'];
if (!in_array($role, $allowedRoles)) {
    header("Location: dashboard.php");
    exit();
}

// Tab visibility
$canSeeCustTab = in_array($role, ['Admin', 'Manager', 'Sales Officer', 'Finance Officer']);
$canSeeSupTab = in_array($role, ['Admin', 'Manager', 'Procurement Officer', 'Finance Officer']);

// Customer Pricing RBAC
$canCreateCust = in_array($role, ['Admin', 'Manager', 'Sales Officer']);
$canUpdateCust = in_array($role, ['Admin', 'Manager', 'Sales Officer']);
$canDeleteCust = ($role === 'Admin');

// Supplier Pricing RBAC
$canCreateSup = in_array($role, ['Admin', 'Manager', 'Procurement Officer']);
$canUpdateSup = in_array($role, ['Admin', 'Manager', 'Procurement Officer']);
$canDeleteSup = ($role === 'Admin');

$isReadOnly = ($role === 'Finance Officer');

// Handle AJAX requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    try {
        switch ($_GET['action']) {

            // ===================== Customer Pricing =====================
            case 'getCustPricing':
                if (!$canSeeCustTab) {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                $conn = getDBConnection();
                $stmt = $conn->prepare("SELECT cpa.*, ct.contract_type_name
                    FROM customer_pricing_agreements cpa
                    LEFT JOIN settings_contract_types ct ON cpa.contract_type_id = ct.contract_type_id
                    ORDER BY cpa.price_agreement_id DESC");
                $stmt->execute();
                $result = $stmt->get_result();

                $records = [];
                while ($row = $result->fetch_assoc()) {
                    $records[] = [
                        'price_agreement_id' => $row['price_agreement_id'],
                        'effective_date' => date('M d, Y', strtotime($row['effective_date'])),
                        'effective_date_raw' => $row['effective_date'],
                        'customer_id' => $row['customer_id'],
                        'customer_name' => $row['customer_name'],
                        'contract_type_id' => $row['contract_type_id'],
                        'contract_type_name' => $row['contract_type_name'] ?? '',
                        'base_cost_per_kg' => $row['base_cost_per_kg'],
                        'pricing_notes' => $row['pricing_notes'] ?? '',
                        'status' => $row['status']
                    ];
                }

                $stmt->close();
                $conn->close();

                echo json_encode(['success' => true, 'data' => $records]);
                exit();

            case 'getSupPricing':
                if (!$canSeeSupTab) {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                $conn = getDBConnection();
                $stmt = $conn->prepare("SELECT * FROM supplier_pricing_agreements ORDER BY price_agreement_id DESC");
                $stmt->execute();
                $result = $stmt->get_result();

                $records = [];
                while ($row = $result->fetch_assoc()) {
                    $records[] = [
                        'price_agreement_id' => $row['price_agreement_id'],
                        'effective_date' => date('M d, Y', strtotime($row['effective_date'])),
                        'effective_date_raw' => $row['effective_date'],
                        'supplier_id' => $row['supplier_id'],
                        'supplier_name' => $row['supplier_name'],
                        'base_cost_per_kg' => $row['base_cost_per_kg'],
                        'status' => $row['status']
                    ];
                }

                $stmt->close();
                $conn->close();

                echo json_encode(['success' => true, 'data' => $records]);
                exit();

            case 'getDropdowns':
                $conn = getDBConnection();

                // Active customers
                $customers = [];
                $stmt = $conn->prepare("SELECT customer_id, customer_name FROM customers WHERE status = 'Active' ORDER BY customer_name ASC");
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $customers[] = $row;
                }
                $stmt->close();

                // Active suppliers
                $suppliers = [];
                $stmt = $conn->prepare("SELECT supplier_id, first_name FROM suppliers WHERE status = 'Active' ORDER BY first_name ASC");
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $suppliers[] = $row;
                }
                $stmt->close();

                // Active contract types
                $contractTypes = [];
                $stmt = $conn->prepare("SELECT contract_type_id, contract_type_name FROM settings_contract_types WHERE is_active = 1 ORDER BY contract_type_name ASC");
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $contractTypes[] = $row;
                }
                $stmt->close();

                $conn->close();

                echo json_encode([
                    'success' => true,
                    'data' => [
                        'customers' => $customers,
                        'suppliers' => $suppliers,
                        'contractTypes' => $contractTypes
                    ]
                ]);
                exit();

            case 'addCustPricing':
                if (!$canCreateCust) {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $effectiveDate = isset($_POST['effective_date']) ? trim($_POST['effective_date']) : '';
                $customerId = isset($_POST['customer_id']) ? trim($_POST['customer_id']) : '';
                $contractTypeId = !empty($_POST['contract_type_id']) ? intval($_POST['contract_type_id']) : null;
                $baseCostPerKg = !empty($_POST['base_cost_per_kg']) ? floatval($_POST['base_cost_per_kg']) : null;
                $pricingNotes = isset($_POST['pricing_notes']) ? trim($_POST['pricing_notes']) : '';
                $status = isset($_POST['status']) ? trim($_POST['status']) : 'Active';

                if (empty($effectiveDate)) {
                    echo json_encode(['success' => false, 'message' => 'Effective date is required']);
                    exit();
                }
                if (empty($customerId)) {
                    echo json_encode(['success' => false, 'message' => 'Customer is required']);
                    exit();
                }

                $conn = getDBConnection();

                $newId = generateTransactionId($conn, 'APC', 'customer_pricing_agreements', 'price_agreement_id');

                $customerName = '';
                $stmt = $conn->prepare("SELECT customer_name FROM customers WHERE customer_id = ?");
                $stmt->bind_param("s", $customerId);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($res->num_rows > 0) {
                    $customerName = $res->fetch_assoc()['customer_name'];
                }
                $stmt->close();

                if ($status === 'Active') {
                    $stmt = $conn->prepare("UPDATE customer_pricing_agreements SET status = 'Superseded' WHERE customer_id = ? AND status = 'Active'");
                    $stmt->bind_param("s", $customerId);
                    $stmt->execute();
                    $stmt->close();
                }

                $stmt = $conn->prepare("INSERT INTO customer_pricing_agreements (price_agreement_id, effective_date, customer_id, customer_name, contract_type_id, base_cost_per_kg, pricing_notes, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("ssssidss", $newId, $effectiveDate, $customerId, $customerName, $contractTypeId, $baseCostPerKg, $pricingNotes, $status);

                if ($stmt->execute()) {
                    logActivity($user_id, $username, 'Customer Pricing Created', "Created agreement: $newId for $customerName ($customerId)");
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => true, 'message' => 'Customer pricing agreement added successfully', 'id' => $newId]);
                } else {
                    $error = $stmt->error;
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to add agreement: ' . $error]);
                }
                exit();

            case 'updateCustPricing':
                if (!$canUpdateCust) {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $agreementId = isset($_POST['price_agreement_id']) ? trim($_POST['price_agreement_id']) : '';
                $effectiveDate = isset($_POST['effective_date']) ? trim($_POST['effective_date']) : '';
                $customerId = isset($_POST['customer_id']) ? trim($_POST['customer_id']) : '';
                $contractTypeId = !empty($_POST['contract_type_id']) ? intval($_POST['contract_type_id']) : null;
                $baseCostPerKg = !empty($_POST['base_cost_per_kg']) ? floatval($_POST['base_cost_per_kg']) : null;
                $pricingNotes = isset($_POST['pricing_notes']) ? trim($_POST['pricing_notes']) : '';
                $status = isset($_POST['status']) ? trim($_POST['status']) : 'Active';

                if (empty($agreementId)) {
                    echo json_encode(['success' => false, 'message' => 'Agreement ID is required']);
                    exit();
                }
                if (empty($effectiveDate)) {
                    echo json_encode(['success' => false, 'message' => 'Effective date is required']);
                    exit();
                }
                if (empty($customerId)) {
                    echo json_encode(['success' => false, 'message' => 'Customer is required']);
                    exit();
                }

                $conn = getDBConnection();

                $customerName = '';
                $stmt = $conn->prepare("SELECT customer_name FROM customers WHERE customer_id = ?");
                $stmt->bind_param("s", $customerId);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($res->num_rows > 0) {
                    $customerName = $res->fetch_assoc()['customer_name'];
                }
                $stmt->close();

                if ($status === 'Active') {
                    $stmt = $conn->prepare("UPDATE customer_pricing_agreements SET status = 'Superseded' WHERE customer_id = ? AND status = 'Active' AND price_agreement_id != ?");
                    $stmt->bind_param("ss", $customerId, $agreementId);
                    $stmt->execute();
                    $stmt->close();
                }

                $stmt = $conn->prepare("UPDATE customer_pricing_agreements SET effective_date = ?, customer_id = ?, customer_name = ?, contract_type_id = ?, base_cost_per_kg = ?, pricing_notes = ?, status = ? WHERE price_agreement_id = ?");
                $stmt->bind_param("sssidsss", $effectiveDate, $customerId, $customerName, $contractTypeId, $baseCostPerKg, $pricingNotes, $status, $agreementId);

                if ($stmt->execute()) {
                    logActivity($user_id, $username, 'Customer Pricing Updated', "Updated agreement: $agreementId for $customerName ($customerId)");
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => true, 'message' => 'Customer pricing agreement updated successfully']);
                } else {
                    $error = $stmt->error;
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to update agreement: ' . $error]);
                }
                exit();

            case 'deleteCustPricing':
                if (!$canDeleteCust) {
                    echo json_encode(['success' => false, 'message' => 'Access denied. Only Admin can delete agreements.']);
                    exit();
                }

                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $agreementId = isset($_POST['price_agreement_id']) ? trim($_POST['price_agreement_id']) : '';
                if (empty($agreementId)) {
                    echo json_encode(['success' => false, 'message' => 'Agreement ID is required']);
                    exit();
                }

                $conn = getDBConnection();

                // Check for linked sales
                $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM sales WHERE price_agreement_id = ?");
                $stmt->bind_param("s", $agreementId);
                $stmt->execute();
                $linkedCount = $stmt->get_result()->fetch_assoc()['cnt'];
                $stmt->close();

                if ($linkedCount > 0) {
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => "Cannot delete: $linkedCount sale(s) are linked to this agreement"]);
                    exit();
                }

                // Get info for logging
                $stmt = $conn->prepare("SELECT customer_name, customer_id FROM customer_pricing_agreements WHERE price_agreement_id = ?");
                $stmt->bind_param("s", $agreementId);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows == 0) {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Agreement not found']);
                    exit();
                }

                $info = $result->fetch_assoc();
                $stmt->close();

                $stmt = $conn->prepare("DELETE FROM customer_pricing_agreements WHERE price_agreement_id = ?");
                $stmt->bind_param("s", $agreementId);

                if ($stmt->execute()) {
                    logActivity($user_id, $username, 'Customer Pricing Deleted', "Deleted agreement: $agreementId (Customer: {$info['customer_name']})");
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => true, 'message' => 'Customer pricing agreement deleted successfully']);
                } else {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to delete agreement']);
                }
                exit();

            // ===================== Supplier Pricing =====================
            case 'addSupPricing':
                if (!$canCreateSup) {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $effectiveDate = isset($_POST['effective_date']) ? trim($_POST['effective_date']) : '';
                $supplierId = isset($_POST['supplier_id']) ? trim($_POST['supplier_id']) : '';
                $baseCostPerKg = isset($_POST['base_cost_per_kg']) ? floatval($_POST['base_cost_per_kg']) : 0;
                $status = isset($_POST['status']) ? trim($_POST['status']) : 'Active';

                if (empty($effectiveDate)) {
                    echo json_encode(['success' => false, 'message' => 'Effective date is required']);
                    exit();
                }
                if (empty($supplierId)) {
                    echo json_encode(['success' => false, 'message' => 'Supplier is required']);
                    exit();
                }
                if ($baseCostPerKg <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Base cost per kg must be greater than 0']);
                    exit();
                }

                $conn = getDBConnection();

                // Auto-generate ID (APF-YY-MMDD-XXXX-C)
                $newId = generateTransactionId($conn, 'APF', 'supplier_pricing_agreements', 'price_agreement_id');

                // Get supplier name
                $supplierName = '';
                $stmt = $conn->prepare("SELECT first_name FROM suppliers WHERE supplier_id = ?");
                $stmt->bind_param("s", $supplierId);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($res->num_rows > 0) {
                    $supplierName = $res->fetch_assoc()['first_name'];
                }
                $stmt->close();

                // Auto-supersede previous Active agreements for same supplier
                if ($status === 'Active') {
                    $stmt = $conn->prepare("UPDATE supplier_pricing_agreements SET status = 'Superseded' WHERE supplier_id = ? AND status = 'Active'");
                    $stmt->bind_param("s", $supplierId);
                    $stmt->execute();
                    $stmt->close();
                }

                $stmt = $conn->prepare("INSERT INTO supplier_pricing_agreements (price_agreement_id, effective_date, supplier_id, supplier_name, base_cost_per_kg, status) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("ssssds", $newId, $effectiveDate, $supplierId, $supplierName, $baseCostPerKg, $status);

                if ($stmt->execute()) {
                    // sync typical_price_per_kg on supplier if Active
                    if ($status === 'Active') {
                        $upd = $conn->prepare("UPDATE suppliers SET typical_price_per_kg = ? WHERE supplier_id = ?");
                        $upd->bind_param("ds", $baseCostPerKg, $supplierId);
                        $upd->execute();
                        $upd->close();
                    }
                    logActivity($user_id, $username, 'Supplier Pricing Created', "Created agreement: $newId for $supplierName ($supplierId), {$baseCostPerKg}/kg");
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => true, 'message' => 'Supplier pricing agreement added successfully', 'id' => $newId]);
                } else {
                    $error = $stmt->error;
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to add agreement: ' . $error]);
                }
                exit();

            case 'updateSupPricing':
                if (!$canUpdateSup) {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $agreementId = isset($_POST['price_agreement_id']) ? trim($_POST['price_agreement_id']) : '';
                $effectiveDate = isset($_POST['effective_date']) ? trim($_POST['effective_date']) : '';
                $supplierId = isset($_POST['supplier_id']) ? trim($_POST['supplier_id']) : '';
                $baseCostPerKg = isset($_POST['base_cost_per_kg']) ? floatval($_POST['base_cost_per_kg']) : 0;
                $status = isset($_POST['status']) ? trim($_POST['status']) : 'Active';

                if (empty($agreementId)) {
                    echo json_encode(['success' => false, 'message' => 'Agreement ID is required']);
                    exit();
                }
                if (empty($effectiveDate)) {
                    echo json_encode(['success' => false, 'message' => 'Effective date is required']);
                    exit();
                }
                if (empty($supplierId)) {
                    echo json_encode(['success' => false, 'message' => 'Supplier is required']);
                    exit();
                }
                if ($baseCostPerKg <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Base cost per kg must be greater than 0']);
                    exit();
                }

                $conn = getDBConnection();

                // Get supplier name
                $supplierName = '';
                $stmt = $conn->prepare("SELECT first_name FROM suppliers WHERE supplier_id = ?");
                $stmt->bind_param("s", $supplierId);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($res->num_rows > 0) {
                    $supplierName = $res->fetch_assoc()['first_name'];
                }
                $stmt->close();

                // Auto-supersede previous Active agreements if this one is being set to Active
                if ($status === 'Active') {
                    $stmt = $conn->prepare("UPDATE supplier_pricing_agreements SET status = 'Superseded' WHERE supplier_id = ? AND status = 'Active' AND price_agreement_id != ?");
                    $stmt->bind_param("ss", $supplierId, $agreementId);
                    $stmt->execute();
                    $stmt->close();
                }

                $stmt = $conn->prepare("UPDATE supplier_pricing_agreements SET effective_date = ?, supplier_id = ?, supplier_name = ?, base_cost_per_kg = ?, status = ? WHERE price_agreement_id = ?");
                $stmt->bind_param("sssdss", $effectiveDate, $supplierId, $supplierName, $baseCostPerKg, $status, $agreementId);

                if ($stmt->execute()) {
                    // sync typical_price_per_kg on supplier if Active
                    if ($status === 'Active') {
                        $upd = $conn->prepare("UPDATE suppliers SET typical_price_per_kg = ? WHERE supplier_id = ?");
                        $upd->bind_param("ds", $baseCostPerKg, $supplierId);
                        $upd->execute();
                        $upd->close();
                    }
                    logActivity($user_id, $username, 'Supplier Pricing Updated', "Updated agreement: $agreementId for $supplierName ($supplierId)");
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => true, 'message' => 'Supplier pricing agreement updated successfully']);
                } else {
                    $error = $stmt->error;
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to update agreement: ' . $error]);
                }
                exit();

            case 'deleteSupPricing':
                if (!$canDeleteSup) {
                    echo json_encode(['success' => false, 'message' => 'Access denied. Only Admin can delete agreements.']);
                    exit();
                }

                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $agreementId = isset($_POST['price_agreement_id']) ? trim($_POST['price_agreement_id']) : '';
                if (empty($agreementId)) {
                    echo json_encode(['success' => false, 'message' => 'Agreement ID is required']);
                    exit();
                }

                $conn = getDBConnection();

                // Check for linked purchases
                $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM purchases WHERE price_agreement_id = ?");
                $stmt->bind_param("s", $agreementId);
                $stmt->execute();
                $linkedCount = $stmt->get_result()->fetch_assoc()['cnt'];
                $stmt->close();

                if ($linkedCount > 0) {
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => "Cannot delete: $linkedCount purchase(s) are linked to this agreement"]);
                    exit();
                }

                // Get info for logging
                $stmt = $conn->prepare("SELECT supplier_name, supplier_id FROM supplier_pricing_agreements WHERE price_agreement_id = ?");
                $stmt->bind_param("s", $agreementId);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows == 0) {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Agreement not found']);
                    exit();
                }

                $info = $result->fetch_assoc();
                $stmt->close();

                $stmt = $conn->prepare("DELETE FROM supplier_pricing_agreements WHERE price_agreement_id = ?");
                $stmt->bind_param("s", $agreementId);

                if ($stmt->execute()) {
                    logActivity($user_id, $username, 'Supplier Pricing Deleted', "Deleted agreement: $agreementId (Supplier: {$info['supplier_name']})");
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => true, 'message' => 'Supplier pricing agreement deleted successfully']);
                } else {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to delete agreement']);
                }
                exit();

            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
                exit();
        }
    } catch (Exception $e) {
        error_log("Pricing.php error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
        exit();
    }
}
?>
<!--
  Developed by Rameez Scripts
  WhatsApp: https://wa.me/923224083545 (For Custom Projects)
  YouTube: https://www.youtube.com/@rameezimdad (Subscribe for more!)
-->
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <title>Pricing Master - Dashboard System</title>

    <!-- CDN Dependencies -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.dataTables.min.css">
    <link rel="stylesheet" href="styles.css?v=5.0">

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js"></script>
</head>
<body>
    <?php include 'mobile-menu.php'; ?>

    <div class="app-container">
        <?php include 'sidebar.php'; ?>

        <!-- Main Content -->
        <div class="main-content">
            <div class="header">
                <h1><i class="fas fa-tags"></i> Pricing Master</h1>
                <div>Welcome, <?php echo htmlspecialchars($username); ?></div>
            </div>

            <!-- Tab Navigation -->
            <div class="pricing-tabs">
                <?php if ($canSeeSupTab): ?>
                <button class="pricing-tab-btn active" id="supTabBtn" onclick="switchTab('supplier')">
                    <i class="fas fa-user"></i> Supplier Pricing
                </button>
                <?php endif; ?>
                <?php if ($canSeeCustTab): ?>
                <button class="pricing-tab-btn <?php echo !$canSeeSupTab ? 'active' : ''; ?>" id="custTabBtn" onclick="switchTab('customer')">
                    <i class="fas fa-building"></i> Customer Pricing
                </button>
                <?php endif; ?>
            </div>

            <!-- ===================== TAB 1: Customer Pricing ===================== -->
            <?php if ($canSeeCustTab): ?>
            <div class="pricing-tab-content <?php echo !$canSeeSupTab ? 'active' : ''; ?>" id="customerTab">
                <div class="data-section">
                    <div class="section-header">
                        <h2><i class="fas fa-building"></i> Customer Pricing Agreements</h2>
                        <div class="section-header-actions">
                            <button class="btn btn-primary" onclick="loadCustPricing()">
                                <i class="fas fa-sync"></i> Refresh
                            </button>
                            <?php if ($canCreateCust): ?>
                            <button class="btn btn-success" onclick="openAddCustModal()">
                                <i class="fas fa-plus"></i> Add Agreement
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Filters -->
                    <div class="filters-section" id="custFiltersSection" style="display: none;">
                        <div class="filters-header">
                            <h3><i class="fas fa-filter"></i> Filters</h3>
                            <button class="btn btn-secondary btn-sm" onclick="clearCustFilters()">
                                <i class="fas fa-times-circle"></i> Clear All
                            </button>
                        </div>
                        <div class="filters-grid">
                            <div class="filter-group">
                                <label><i class="fas fa-calendar-alt"></i> Date From</label>
                                <input type="date" id="custFilterDateFrom" class="filter-input">
                            </div>
                            <div class="filter-group">
                                <label><i class="fas fa-calendar-alt"></i> Date To</label>
                                <input type="date" id="custFilterDateTo" class="filter-input">
                            </div>
                            <div class="filter-group">
                                <label><i class="fas fa-toggle-on"></i> Status</label>
                                <select id="custFilterStatus" class="filter-input">
                                    <option value="">All</option>
                                    <option value="Active">Active</option>
                                    <option value="Expired">Expired</option>
                                    <option value="Superseded">Superseded</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Skeleton Loader -->
                    <div id="custSkeletonLoader">
                        <div class="skeleton-table">
                            <div class="skeleton-table-row">
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                            </div>
                            <div class="skeleton-table-row">
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                            </div>
                            <div class="skeleton-table-row">
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                            </div>
                            <div class="skeleton-table-row">
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                            </div>
                            <div class="skeleton-table-row">
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                            </div>
                            <div class="skeleton-table-row">
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                            </div>
                            <div class="skeleton-table-row">
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                            </div>
                            <div class="skeleton-table-row">
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                            </div>
                        </div>
                    </div>

                    <!-- DataTable Container -->
                    <div id="custTableContainer" style="display: none;">
                        <div class="table-scroll-hint">
                            <i class="fas fa-arrows-alt-h"></i> Swipe left/right to see all columns
                        </div>
                        <div style="overflow-x: auto; -webkit-overflow-scrolling: touch;">
                            <table id="custPricingTable" class="display" style="width:100%"></table>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- ===================== TAB 2: Supplier Pricing ===================== -->
            <?php if ($canSeeSupTab): ?>
            <div class="pricing-tab-content active" id="supplierTab">
                <div class="data-section">
                    <div class="section-header">
                        <h2><i class="fas fa-user"></i> Supplier Pricing Agreements</h2>
                        <div class="section-header-actions">
                            <button class="btn btn-primary" onclick="loadSupPricing()">
                                <i class="fas fa-sync"></i> Refresh
                            </button>
                            <?php if ($canCreateSup): ?>
                            <button class="btn btn-success" onclick="openAddSupModal()">
                                <i class="fas fa-plus"></i> Add Agreement
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Filters -->
                    <div class="filters-section" id="supFiltersSection" style="display: none;">
                        <div class="filters-header">
                            <h3><i class="fas fa-filter"></i> Filters</h3>
                            <button class="btn btn-secondary btn-sm" onclick="clearSupFilters()">
                                <i class="fas fa-times-circle"></i> Clear All
                            </button>
                        </div>
                        <div class="filters-grid">
                            <div class="filter-group">
                                <label><i class="fas fa-calendar-alt"></i> Date From</label>
                                <input type="date" id="supFilterDateFrom" class="filter-input">
                            </div>
                            <div class="filter-group">
                                <label><i class="fas fa-calendar-alt"></i> Date To</label>
                                <input type="date" id="supFilterDateTo" class="filter-input">
                            </div>
                            <div class="filter-group">
                                <label><i class="fas fa-toggle-on"></i> Status</label>
                                <select id="supFilterStatus" class="filter-input">
                                    <option value="">All</option>
                                    <option value="Active">Active</option>
                                    <option value="Expired">Expired</option>
                                    <option value="Superseded">Superseded</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Skeleton Loader -->
                    <div id="supSkeletonLoader">
                        <div class="skeleton-table">
                            <div class="skeleton-table-row">
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                            </div>
                            <div class="skeleton-table-row">
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                            </div>
                            <div class="skeleton-table-row">
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                            </div>
                            <div class="skeleton-table-row">
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                            </div>
                            <div class="skeleton-table-row">
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                            </div>
                            <div class="skeleton-table-row">
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                            </div>
                            <div class="skeleton-table-row">
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                            </div>
                            <div class="skeleton-table-row">
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                            </div>
                        </div>
                    </div>

                    <!-- DataTable Container -->
                    <div id="supTableContainer" style="display: none;">
                        <div class="table-scroll-hint">
                            <i class="fas fa-arrows-alt-h"></i> Swipe left/right to see all columns
                        </div>
                        <div style="overflow-x: auto; -webkit-overflow-scrolling: touch;">
                            <table id="supPricingTable" class="display" style="width:100%"></table>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ===================== Customer Pricing Modal ===================== -->
    <?php if ($canCreateCust || $canUpdateCust): ?>
    <div class="modal-overlay" id="custPricingModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 id="custModalTitle"><i class="fas fa-plus"></i> Add Customer Pricing Agreement</h3>
                <button class="close-btn" onclick="closeCustModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <div id="custIdInfo" style="display: none; background: #f8f9fa; border: 1px solid #dee2e6; border-left: 4px solid var(--navy-primary); padding: 12px 16px; border-radius: 3px; margin-bottom: 20px;">
                    <strong><i class="fas fa-id-badge"></i> Agreement ID:</strong> <span id="custIdDisplay"></span>
                </div>

                <form id="custPricingForm">
                    <input type="hidden" id="custAgreementId" name="price_agreement_id">

                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-calendar-day"></i> Effective Date *</label>
                            <input type="date" id="custEffectiveDate" name="effective_date" required>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-building"></i> Customer *</label>
                            <div class="searchable-dropdown" id="custDropdownWrapper">
                                <input type="text" class="searchable-dropdown-input" id="custSearch" placeholder="Search customer..." autocomplete="off">
                                <input type="hidden" id="custCustomerId" name="customer_id" required>
                                <span class="searchable-dropdown-arrow" id="custArrow"><i class="fas fa-chevron-down"></i></span>
                                <div class="searchable-dropdown-list" id="custList" style="display:none;"></div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-file-contract"></i> Contract Type</label>
                            <select id="custContractType" name="contract_type_id" onchange="toggleBaseCostRequired()">
                                <option value="">Select Contract Type</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-money-bill-wave"></i> Base Cost/Kg <span id="baseCostReqStar">*</span></label>
                            <input type="number" id="custBaseCost" name="base_cost_per_kg" step="0.01" min="0.01">
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-toggle-on"></i> Status</label>
                            <select id="custStatus" name="status">
                                <option value="Active">Active</option>
                                <option value="Expired">Expired</option>
                                <option value="Superseded">Superseded</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group" style="margin-top:4px;">
                        <label><i class="fas fa-clipboard-list"></i> Pricing Notes <small style="color:var(--text-muted);font-weight:400;">(quality tiers, reference pricing, etc.)</small></label>
                        <textarea id="custPricingNotes" name="pricing_notes" rows="4" style="width:100%;resize:vertical;font-family:monospace;font-size:13px;padding:10px;border:1px solid var(--border-color);border-radius:4px;background:var(--bg-secondary);color:var(--text-primary);" placeholder="e.g.&#10;460 F for 45 lbs/80kg&#10;475 F for 46 lbs/80kg&#10;485 F for 47 lbs/80kg&#10;500 F for 48+ lbs/80kg"></textarea>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="closeCustModal()">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ===================== Supplier Pricing Modal ===================== -->
    <?php if ($canCreateSup || $canUpdateSup): ?>
    <div class="modal-overlay" id="supPricingModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 id="supModalTitle"><i class="fas fa-plus"></i> Add Supplier Pricing Agreement</h3>
                <button class="close-btn" onclick="closeSupModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <div id="supIdInfo" style="display: none; background: #f8f9fa; border: 1px solid #dee2e6; border-left: 4px solid var(--navy-primary); padding: 12px 16px; border-radius: 3px; margin-bottom: 20px;">
                    <strong><i class="fas fa-id-badge"></i> Agreement ID:</strong> <span id="supIdDisplay"></span>
                </div>

                <form id="supPricingForm">
                    <input type="hidden" id="supAgreementId" name="price_agreement_id">

                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-calendar-day"></i> Effective Date *</label>
                            <input type="date" id="supEffectiveDate" name="effective_date" required>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-user"></i> Supplier *</label>
                            <div class="searchable-dropdown" id="supDropdownWrapper">
                                <input type="text" class="searchable-dropdown-input" id="supSearch" placeholder="Search supplier..." autocomplete="off">
                                <input type="hidden" id="supSupplierId" name="supplier_id" required>
                                <span class="searchable-dropdown-arrow" id="supArrow"><i class="fas fa-chevron-down"></i></span>
                                <div class="searchable-dropdown-list" id="supList" style="display:none;"></div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-money-bill-wave"></i> Base Cost/Kg *</label>
                            <input type="number" id="supBaseCost" name="base_cost_per_kg" step="0.01" min="0.01" required>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-toggle-on"></i> Status</label>
                            <select id="supStatus" name="status">
                                <option value="Active">Active</option>
                                <option value="Expired">Expired</option>
                                <option value="Superseded">Superseded</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="closeSupModal()">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script>
        // ===================== Global Variables =====================
        let custDataTable, supDataTable;
        let custIsEditMode = false, supIsEditMode = false;
        let custPricingData = [], supPricingData = [];
        let customersList = [], suppliersList = [];
        let activeTab = '<?php echo $canSeeSupTab ? 'supplier' : 'customer'; ?>';

        const canSeeCustTab = <?php echo $canSeeCustTab ? 'true' : 'false'; ?>;
        const canSeeSupTab = <?php echo $canSeeSupTab ? 'true' : 'false'; ?>;
        const canCreateCust = <?php echo $canCreateCust ? 'true' : 'false'; ?>;
        const canUpdateCust = <?php echo $canUpdateCust ? 'true' : 'false'; ?>;
        const canDeleteCust = <?php echo $canDeleteCust ? 'true' : 'false'; ?>;
        const canCreateSup = <?php echo $canCreateSup ? 'true' : 'false'; ?>;
        const canUpdateSup = <?php echo $canUpdateSup ? 'true' : 'false'; ?>;
        const canDeleteSup = <?php echo $canDeleteSup ? 'true' : 'false'; ?>;

        const statusBadgeMap = {
            'Active': 'status-active',
            'Expired': 'status-expired',
            'Superseded': 'status-superseded'
        };

        // ===================== Tab Switching =====================
        function switchTab(tab) {
            activeTab = tab;

            // Update tab buttons
            var custBtn = document.getElementById('custTabBtn');
            var supBtn = document.getElementById('supTabBtn');
            if (custBtn) custBtn.classList.toggle('active', tab === 'customer');
            if (supBtn) supBtn.classList.toggle('active', tab === 'supplier');

            // Update tab content
            var custTab = document.getElementById('customerTab');
            var supTab = document.getElementById('supplierTab');
            if (custTab) custTab.classList.toggle('active', tab === 'customer');
            if (supTab) supTab.classList.toggle('active', tab === 'supplier');

            // Load data for tab if not loaded yet
            if (tab === 'customer' && custPricingData.length === 0 && canSeeCustTab) {
                loadCustPricing();
            }
            if (tab === 'supplier' && supPricingData.length === 0 && canSeeSupTab) {
                loadSupPricing();
            }

            // Adjust DataTable columns when switching tabs
            if (tab === 'customer' && custDataTable) {
                setTimeout(function() { custDataTable.columns.adjust().responsive.recalc(); }, 100);
            }
            if (tab === 'supplier' && supDataTable) {
                setTimeout(function() { supDataTable.columns.adjust().responsive.recalc(); }, 100);
            }
        }

        // ===================== Initialize =====================
        $(document).ready(function() {
            loadDropdowns();

            // Load initial tab data — supplier first
            if (canSeeSupTab) {
                loadSupPricing();
            }
            if (!canSeeSupTab && canSeeCustTab) {
                loadCustPricing();
            }
        });

        function loadDropdowns() {
            $.ajax({
                url: '?action=getDropdowns',
                method: 'GET',
                dataType: 'json',
                success: function(response) {
                    if (response.success && response.data) {
                        // Store customers for searchable dropdown
                        customersList = response.data.customers.map(function(c) {
                            return { id: c.customer_id, name: c.customer_name };
                        });

                        // Store suppliers for searchable dropdown
                        suppliersList = response.data.suppliers.map(function(s) {
                            return { id: s.supplier_id, name: s.first_name };
                        });

                        // Populate contract types
                        var ctSelect = document.getElementById('custContractType');
                        if (ctSelect) {
                            ctSelect.innerHTML = '<option value="">Select Contract Type</option>';
                            response.data.contractTypes.forEach(function(ct) {
                                var opt = document.createElement('option');
                                opt.value = ct.contract_type_id;
                                opt.textContent = ct.contract_type_name;
                                ctSelect.appendChild(opt);
                            });
                        }

                        initCustomerDropdown();
                        initSupplierDropdown();
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Failed to load dropdowns:', error);
                }
            });
        }

        // ===================== Customer Searchable Dropdown =====================
        function initCustomerDropdown() {
            var input = document.getElementById('custSearch');
            var hiddenInput = document.getElementById('custCustomerId');
            var list = document.getElementById('custList');
            var arrow = document.getElementById('custArrow');

            if (!input) return;

            input.addEventListener('focus', function() {
                renderCustomerList(this.value);
                list.style.display = 'block';
                arrow.classList.add('open');
            });

            input.addEventListener('input', function() {
                renderCustomerList(this.value);
                list.style.display = 'block';
            });

            document.addEventListener('click', function(e) {
                if (!document.getElementById('custDropdownWrapper').contains(e.target)) {
                    list.style.display = 'none';
                    arrow.classList.remove('open');
                    var selected = customersList.find(function(c) { return c.id === hiddenInput.value; });
                    if (selected) {
                        input.value = selected.id + ' — ' + selected.name;
                    } else if (hiddenInput.value === '') {
                        input.value = '';
                    }
                }
            });
        }

        function renderCustomerList(searchTerm) {
            var list = document.getElementById('custList');
            var hiddenInput = document.getElementById('custCustomerId');
            list.innerHTML = '';

            var filtered = customersList.filter(function(c) {
                var label = c.id + ' — ' + c.name;
                return label.toLowerCase().includes((searchTerm || '').toLowerCase());
            });

            if (filtered.length === 0) {
                list.innerHTML = '<div class="searchable-dropdown-item no-results">No customers found</div>';
                return;
            }

            filtered.forEach(function(c) {
                var item = document.createElement('div');
                item.className = 'searchable-dropdown-item' + (hiddenInput.value === c.id ? ' selected' : '');
                item.textContent = c.id + ' — ' + c.name;
                item.addEventListener('click', function() {
                    hiddenInput.value = c.id;
                    document.getElementById('custSearch').value = c.id + ' — ' + c.name;
                    list.style.display = 'none';
                    document.getElementById('custArrow').classList.remove('open');
                });
                list.appendChild(item);
            });
        }

        // ===================== Supplier Searchable Dropdown =====================
        function initSupplierDropdown() {
            var input = document.getElementById('supSearch');
            var hiddenInput = document.getElementById('supSupplierId');
            var list = document.getElementById('supList');
            var arrow = document.getElementById('supArrow');

            if (!input) return;

            input.addEventListener('focus', function() {
                renderSupplierList(this.value);
                list.style.display = 'block';
                arrow.classList.add('open');
            });

            input.addEventListener('input', function() {
                renderSupplierList(this.value);
                list.style.display = 'block';
            });

            document.addEventListener('click', function(e) {
                if (!document.getElementById('supDropdownWrapper').contains(e.target)) {
                    list.style.display = 'none';
                    arrow.classList.remove('open');
                    var selected = suppliersList.find(function(s) { return s.id === hiddenInput.value; });
                    if (selected) {
                        input.value = selected.id + ' — ' + selected.name;
                    } else if (hiddenInput.value === '') {
                        input.value = '';
                    }
                }
            });
        }

        function renderSupplierList(searchTerm) {
            var list = document.getElementById('supList');
            var hiddenInput = document.getElementById('supSupplierId');
            list.innerHTML = '';

            var filtered = suppliersList.filter(function(s) {
                var label = s.id + ' — ' + s.name;
                return label.toLowerCase().includes((searchTerm || '').toLowerCase());
            });

            if (filtered.length === 0) {
                list.innerHTML = '<div class="searchable-dropdown-item no-results">No suppliers found</div>';
                return;
            }

            filtered.forEach(function(s) {
                var item = document.createElement('div');
                item.className = 'searchable-dropdown-item' + (hiddenInput.value === s.id ? ' selected' : '');
                item.textContent = s.id + ' — ' + s.name;
                item.addEventListener('click', function() {
                    hiddenInput.value = s.id;
                    document.getElementById('supSearch').value = s.id + ' — ' + s.name;
                    list.style.display = 'none';
                    document.getElementById('supArrow').classList.remove('open');
                });
                list.appendChild(item);
            });
        }

        // ===================== Load Customer Pricing =====================
        function loadCustPricing() {
            $('#custSkeletonLoader').show();
            $('#custTableContainer').hide();

            $.ajax({
                url: '?action=getCustPricing',
                method: 'GET',
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        custPricingData = response.data;
                        $('#custFiltersSection').show();
                        initCustDataTable(response.data);
                    } else {
                        $('#custSkeletonLoader').hide();
                        Swal.fire({ icon: 'error', title: 'Error', text: response.message || 'Failed to load customer pricing' });
                    }
                },
                error: function(xhr, status, error) {
                    $('#custSkeletonLoader').hide();
                    console.error('AJAX Error:', error);
                    Swal.fire({ icon: 'error', title: 'Connection Error', text: 'Could not connect to server.' });
                }
            });
        }

        function initCustDataTable(data) {
            if (custDataTable) {
                custDataTable.destroy();
                $('#custPricingTable').empty();
            }

            var columns = [
                { data: 'price_agreement_id', title: 'ID' },
                { data: 'effective_date', title: 'Effective Date' },
                {
                    data: 'customer_name',
                    title: 'Customer',
                    render: function(data, type, row) {
                        return data + '<br><small class="text-muted">' + row.customer_id + '</small>';
                    }
                },
                { data: 'contract_type_name', title: 'Contract Type' },
                {
                    data: 'base_cost_per_kg',
                    title: 'Base Cost/Kg',
                    render: function(data) {
                        return data ? parseFloat(data).toLocaleString() : '<span style="color:var(--text-muted);">—</span>';
                    }
                },
                {
                    data: 'pricing_notes',
                    title: 'Pricing Notes',
                    render: function(data, type, row) {
                        if (!data) return '<span style="color:var(--text-muted);">—</span>';
                        var escaped = $('<div>').text(data).html();
                        var short = data.length > 50 ? $('<div>').text(data.substring(0, 50) + '...').html() : escaped;
                        return '<span class="pricing-note-peek" data-id="' + row.price_agreement_id + '" style="cursor:pointer;white-space:pre-line;font-size:12px;color:var(--navy-accent);">' + short + '</span>';
                    }
                },
                {
                    data: 'status',
                    title: 'Status',
                    render: function(data) {
                        var cls = statusBadgeMap[data] || 'status-active';
                        return '<span class="status-badge ' + cls + '">' + (data || 'Active') + '</span>';
                    }
                }
            ];

            if (canUpdateCust || canDeleteCust) {
                columns.push({
                    data: null,
                    title: 'Actions',
                    orderable: false,
                    render: function(data, type, row) {
                        var html = '';
                        if (canUpdateCust) {
                            html += '<button class="action-icon edit-icon" onclick=\'editCustPricing(' + JSON.stringify(row).replace(/'/g, "\\'") + ')\' title="Edit"><i class="fas fa-edit"></i></button> ';
                        }
                        if (canDeleteCust) {
                            html += '<button class="action-icon delete-icon" onclick="deleteCustPricing(\'' + row.price_agreement_id + '\')" title="Delete"><i class="fas fa-trash"></i></button>';
                        }
                        return html;
                    }
                });
            }

            setTimeout(function() {
                custDataTable = $('#custPricingTable').DataTable({
                    data: data,
                    destroy: true,
                    columns: columns,
                    pageLength: 50,
                    lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
                    responsive: true,
                    dom: 'Blfrtip',
                    buttons: [
                        {
                            extend: 'csv',
                            text: '<i class="fas fa-file-csv"></i> CSV',
                            exportOptions: { columns: (canUpdateCust || canDeleteCust) ? ':not(:last-child)' : ':visible' }
                        },
                        {
                            extend: 'pdf',
                            text: '<i class="fas fa-file-pdf"></i> PDF',
                            exportOptions: { columns: (canUpdateCust || canDeleteCust) ? ':not(:last-child)' : ':visible' }
                        },
                        {
                            extend: 'print',
                            text: '<i class="fas fa-print"></i> Print',
                            exportOptions: { columns: (canUpdateCust || canDeleteCust) ? ':not(:last-child)' : ':visible' }
                        }
                    ],
                    order: [[0, 'desc']]
                });

                $('#custSkeletonLoader').hide();
                $('#custTableContainer').show();

                $('#custFilterDateFrom, #custFilterDateTo, #custFilterStatus').on('change', function() {
                    applyCustFilters();
                });
            }, 100);
        }

        // ===================== Load Supplier Pricing =====================
        function loadSupPricing() {
            $('#supSkeletonLoader').show();
            $('#supTableContainer').hide();

            $.ajax({
                url: '?action=getSupPricing',
                method: 'GET',
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        supPricingData = response.data;
                        $('#supFiltersSection').show();
                        initSupDataTable(response.data);
                    } else {
                        $('#supSkeletonLoader').hide();
                        Swal.fire({ icon: 'error', title: 'Error', text: response.message || 'Failed to load supplier pricing' });
                    }
                },
                error: function(xhr, status, error) {
                    $('#supSkeletonLoader').hide();
                    console.error('AJAX Error:', error);
                    Swal.fire({ icon: 'error', title: 'Connection Error', text: 'Could not connect to server.' });
                }
            });
        }

        function initSupDataTable(data) {
            if (supDataTable) {
                supDataTable.destroy();
                $('#supPricingTable').empty();
            }

            var columns = [
                { data: 'price_agreement_id', title: 'ID' },
                { data: 'effective_date', title: 'Effective Date' },
                {
                    data: 'supplier_name',
                    title: 'Supplier',
                    render: function(data, type, row) {
                        return data + '<br><small class="text-muted">' + row.supplier_id + '</small>';
                    }
                },
                {
                    data: 'base_cost_per_kg',
                    title: 'Base Cost/Kg',
                    render: function(data) {
                        return data ? parseFloat(data).toLocaleString() : '0';
                    }
                },
                {
                    data: 'status',
                    title: 'Status',
                    render: function(data) {
                        var cls = statusBadgeMap[data] || 'status-active';
                        return '<span class="status-badge ' + cls + '">' + (data || 'Active') + '</span>';
                    }
                }
            ];

            if (canUpdateSup || canDeleteSup) {
                columns.push({
                    data: null,
                    title: 'Actions',
                    orderable: false,
                    render: function(data, type, row) {
                        var html = '';
                        if (canUpdateSup) {
                            html += '<button class="action-icon edit-icon" onclick=\'editSupPricing(' + JSON.stringify(row).replace(/'/g, "\\'") + ')\' title="Edit"><i class="fas fa-edit"></i></button> ';
                        }
                        if (canDeleteSup) {
                            html += '<button class="action-icon delete-icon" onclick="deleteSupPricing(\'' + row.price_agreement_id + '\')" title="Delete"><i class="fas fa-trash"></i></button>';
                        }
                        return html;
                    }
                });
            }

            setTimeout(function() {
                supDataTable = $('#supPricingTable').DataTable({
                    data: data,
                    destroy: true,
                    columns: columns,
                    pageLength: 50,
                    lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
                    responsive: true,
                    dom: 'Blfrtip',
                    buttons: [
                        {
                            extend: 'csv',
                            text: '<i class="fas fa-file-csv"></i> CSV',
                            exportOptions: { columns: (canUpdateSup || canDeleteSup) ? ':not(:last-child)' : ':visible' }
                        },
                        {
                            extend: 'pdf',
                            text: '<i class="fas fa-file-pdf"></i> PDF',
                            exportOptions: { columns: (canUpdateSup || canDeleteSup) ? ':not(:last-child)' : ':visible' }
                        },
                        {
                            extend: 'print',
                            text: '<i class="fas fa-print"></i> Print',
                            exportOptions: { columns: (canUpdateSup || canDeleteSup) ? ':not(:last-child)' : ':visible' }
                        }
                    ],
                    order: [[0, 'desc']]
                });

                $('#supSkeletonLoader').hide();
                $('#supTableContainer').show();

                $('#supFilterDateFrom, #supFilterDateTo, #supFilterStatus').on('change', function() {
                    applySupFilters();
                });
            }, 100);
        }

        // ===================== Customer Pricing Filters =====================
        function applyCustFilters() {
            if (!custDataTable) return;

            $.fn.dataTable.ext.search = [];

            var dateFrom = document.getElementById('custFilterDateFrom').value;
            var dateTo = document.getElementById('custFilterDateTo').value;
            var status = document.getElementById('custFilterStatus').value;

            if (dateFrom || dateTo) {
                $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
                    if (settings.nTable.id !== 'custPricingTable') return true;
                    var rawDate = custPricingData[dataIndex]?.effective_date_raw;
                    if (!rawDate) return true;
                    var recordDate = new Date(rawDate);
                    var fromDate = dateFrom ? new Date(dateFrom) : null;
                    var toDate = dateTo ? new Date(dateTo + 'T23:59:59') : null;
                    if (fromDate && recordDate < fromDate) return false;
                    if (toDate && recordDate > toDate) return false;
                    return true;
                });
            }

            if (status) {
                $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
                    if (settings.nTable.id !== 'custPricingTable') return true;
                    return custPricingData[dataIndex]?.status === status;
                });
            }

            custDataTable.draw();
        }

        function clearCustFilters() {
            document.getElementById('custFilterDateFrom').value = '';
            document.getElementById('custFilterDateTo').value = '';
            document.getElementById('custFilterStatus').value = '';

            if (custDataTable) {
                $.fn.dataTable.ext.search = [];
                custDataTable.columns().search('').draw();
            }
        }

        // ===================== Supplier Pricing Filters =====================
        function applySupFilters() {
            if (!supDataTable) return;

            $.fn.dataTable.ext.search = [];

            var dateFrom = document.getElementById('supFilterDateFrom').value;
            var dateTo = document.getElementById('supFilterDateTo').value;
            var status = document.getElementById('supFilterStatus').value;

            if (dateFrom || dateTo) {
                $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
                    if (settings.nTable.id !== 'supPricingTable') return true;
                    var rawDate = supPricingData[dataIndex]?.effective_date_raw;
                    if (!rawDate) return true;
                    var recordDate = new Date(rawDate);
                    var fromDate = dateFrom ? new Date(dateFrom) : null;
                    var toDate = dateTo ? new Date(dateTo + 'T23:59:59') : null;
                    if (fromDate && recordDate < fromDate) return false;
                    if (toDate && recordDate > toDate) return false;
                    return true;
                });
            }

            if (status) {
                $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
                    if (settings.nTable.id !== 'supPricingTable') return true;
                    return supPricingData[dataIndex]?.status === status;
                });
            }

            supDataTable.draw();
        }

        function clearSupFilters() {
            document.getElementById('supFilterDateFrom').value = '';
            document.getElementById('supFilterDateTo').value = '';
            document.getElementById('supFilterStatus').value = '';

            if (supDataTable) {
                $.fn.dataTable.ext.search = [];
                supDataTable.columns().search('').draw();
            }
        }

        // ===================== Customer Pricing Modal =====================
        <?php if ($canCreateCust || $canUpdateCust): ?>
        function toggleBaseCostRequired() {
            var sel = document.getElementById('custContractType');
            var txt = sel.options[sel.selectedIndex]?.textContent || '';
            var star = document.getElementById('baseCostReqStar');
            var inp = document.getElementById('custBaseCost');
            if (txt === 'Fixed Price') {
                star.style.display = '';
                inp.required = true;
            } else {
                star.style.display = 'none';
                inp.required = false;
            }
        }

        // event delegation for pricing notes click
        $(document).on('click', '.pricing-note-peek', function() {
            var id = $(this).data('id');
            var row = custPricingData.find(function(r) { return r.price_agreement_id === id; });
            if (row && row.pricing_notes) {
                Swal.fire({ title: 'Pricing Notes', html: '<pre style="text-align:left;white-space:pre-wrap;font-size:14px;margin:0;">' + $('<div>').text(row.pricing_notes).html() + '</pre>', width: 500, confirmButtonColor: '#0074D9' });
            }
        });

        function openAddCustModal() {
            custIsEditMode = false;
            document.getElementById('custModalTitle').innerHTML = '<i class="fas fa-plus"></i> Add Customer Pricing Agreement';
            document.getElementById('custPricingForm').reset();
            document.getElementById('custAgreementId').value = '';
            document.getElementById('custIdInfo').style.display = 'none';
            document.getElementById('custCustomerId').value = '';
            document.getElementById('custSearch').value = '';
            document.getElementById('custStatus').value = 'Active';
            document.getElementById('custPricingNotes').value = '';

            var today = new Date().toISOString().split('T')[0];
            document.getElementById('custEffectiveDate').value = today;

            toggleBaseCostRequired();
            document.getElementById('custPricingModal').classList.add('active');
        }

        function editCustPricing(row) {
            custIsEditMode = true;
            document.getElementById('custModalTitle').innerHTML = '<i class="fas fa-edit"></i> Edit Customer Pricing Agreement';
            document.getElementById('custAgreementId').value = row.price_agreement_id;
            document.getElementById('custIdInfo').style.display = 'block';
            document.getElementById('custIdDisplay').textContent = row.price_agreement_id;
            document.getElementById('custEffectiveDate').value = row.effective_date_raw;

            document.getElementById('custCustomerId').value = row.customer_id;
            document.getElementById('custSearch').value = row.customer_id + ' — ' + row.customer_name;

            document.getElementById('custContractType').value = row.contract_type_id || '';
            document.getElementById('custBaseCost').value = row.base_cost_per_kg || '';
            document.getElementById('custPricingNotes').value = row.pricing_notes || '';
            document.getElementById('custStatus').value = row.status || 'Active';

            toggleBaseCostRequired();
            document.getElementById('custPricingModal').classList.add('active');
        }

        function closeCustModal() {
            document.getElementById('custPricingModal').classList.remove('active');
            document.getElementById('custPricingForm').reset();
        }

        document.getElementById('custPricingModal').addEventListener('click', function(e) {
            if (e.target === this) closeCustModal();
        });

        document.getElementById('custPricingForm').addEventListener('submit', function(e) {
            e.preventDefault();

            if (!document.getElementById('custCustomerId').value) {
                Swal.fire({ icon: 'warning', title: 'Validation', text: 'Please select a customer' });
                return;
            }

            var formData = new FormData(this);
            var action = custIsEditMode ? 'updateCustPricing' : 'addCustPricing';

            Swal.fire({
                title: 'Processing...',
                allowOutsideClick: false,
                didOpen: function() { Swal.showLoading(); }
            });

            $.ajax({
                url: '?action=' + action,
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        Swal.fire({ icon: 'success', title: 'Success!', text: response.message, timer: 2000, showConfirmButton: false });
                        closeCustModal();
                        setTimeout(function() { loadCustPricing(); }, 100);
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: response.message });
                    }
                },
                error: function(xhr, status, error) {
                    Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error: ' + error });
                }
            });
        });
        <?php endif; ?>

        <?php if ($canDeleteCust): ?>
        function deleteCustPricing(agreementId) {
            Swal.fire({
                icon: 'warning',
                title: 'Delete Agreement?',
                text: 'Are you sure you want to delete agreement ' + agreementId + '? This action cannot be undone.',
                showCancelButton: true,
                confirmButtonColor: '#ea4335',
                confirmButtonText: 'Yes, delete it',
                cancelButtonText: 'Cancel'
            }).then(function(result) {
                if (result.isConfirmed) {
                    var formData = new FormData();
                    formData.append('price_agreement_id', agreementId);

                    $.ajax({
                        url: '?action=deleteCustPricing',
                        method: 'POST',
                        data: formData,
                        processData: false,
                        contentType: false,
                        dataType: 'json',
                        success: function(response) {
                            if (response.success) {
                                Swal.fire({ icon: 'success', text: response.message, timer: 2000, showConfirmButton: false });
                                setTimeout(function() { loadCustPricing(); }, 100);
                            } else {
                                Swal.fire({ icon: 'error', title: 'Error', text: response.message });
                            }
                        },
                        error: function(xhr, status, error) {
                            Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error: ' + error });
                        }
                    });
                }
            });
        }
        <?php endif; ?>

        // ===================== Supplier Pricing Modal =====================
        <?php if ($canCreateSup || $canUpdateSup): ?>
        function openAddSupModal() {
            supIsEditMode = false;
            document.getElementById('supModalTitle').innerHTML = '<i class="fas fa-plus"></i> Add Supplier Pricing Agreement';
            document.getElementById('supPricingForm').reset();
            document.getElementById('supAgreementId').value = '';
            document.getElementById('supIdInfo').style.display = 'none';
            document.getElementById('supSupplierId').value = '';
            document.getElementById('supSearch').value = '';
            document.getElementById('supStatus').value = 'Active';

            var today = new Date().toISOString().split('T')[0];
            document.getElementById('supEffectiveDate').value = today;

            document.getElementById('supPricingModal').classList.add('active');
        }

        function editSupPricing(row) {
            supIsEditMode = true;
            document.getElementById('supModalTitle').innerHTML = '<i class="fas fa-edit"></i> Edit Supplier Pricing Agreement';
            document.getElementById('supAgreementId').value = row.price_agreement_id;
            document.getElementById('supIdInfo').style.display = 'block';
            document.getElementById('supIdDisplay').textContent = row.price_agreement_id;
            document.getElementById('supEffectiveDate').value = row.effective_date_raw;

            document.getElementById('supSupplierId').value = row.supplier_id;
            document.getElementById('supSearch').value = row.supplier_id + ' — ' + row.supplier_name;

            document.getElementById('supBaseCost').value = row.base_cost_per_kg;
            document.getElementById('supStatus').value = row.status || 'Active';

            document.getElementById('supPricingModal').classList.add('active');
        }

        function closeSupModal() {
            document.getElementById('supPricingModal').classList.remove('active');
            document.getElementById('supPricingForm').reset();
        }

        document.getElementById('supPricingModal').addEventListener('click', function(e) {
            if (e.target === this) closeSupModal();
        });

        document.getElementById('supPricingForm').addEventListener('submit', function(e) {
            e.preventDefault();

            if (!document.getElementById('supSupplierId').value) {
                Swal.fire({ icon: 'warning', title: 'Validation', text: 'Please select a supplier' });
                return;
            }

            var formData = new FormData(this);
            var action = supIsEditMode ? 'updateSupPricing' : 'addSupPricing';

            Swal.fire({
                title: 'Processing...',
                allowOutsideClick: false,
                didOpen: function() { Swal.showLoading(); }
            });

            $.ajax({
                url: '?action=' + action,
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        Swal.fire({ icon: 'success', title: 'Success!', text: response.message, timer: 2000, showConfirmButton: false });
                        closeSupModal();
                        setTimeout(function() { loadSupPricing(); }, 100);
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: response.message });
                    }
                },
                error: function(xhr, status, error) {
                    Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error: ' + error });
                }
            });
        });
        <?php endif; ?>

        <?php if ($canDeleteSup): ?>
        function deleteSupPricing(agreementId) {
            Swal.fire({
                icon: 'warning',
                title: 'Delete Agreement?',
                text: 'Are you sure you want to delete agreement ' + agreementId + '? This action cannot be undone.',
                showCancelButton: true,
                confirmButtonColor: '#ea4335',
                confirmButtonText: 'Yes, delete it',
                cancelButtonText: 'Cancel'
            }).then(function(result) {
                if (result.isConfirmed) {
                    var formData = new FormData();
                    formData.append('price_agreement_id', agreementId);

                    $.ajax({
                        url: '?action=deleteSupPricing',
                        method: 'POST',
                        data: formData,
                        processData: false,
                        contentType: false,
                        dataType: 'json',
                        success: function(response) {
                            if (response.success) {
                                Swal.fire({ icon: 'success', text: response.message, timer: 2000, showConfirmButton: false });
                                setTimeout(function() { loadSupPricing(); }, 100);
                            } else {
                                Swal.fire({ icon: 'error', title: 'Error', text: response.message });
                            }
                        },
                        error: function(xhr, status, error) {
                            Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error: ' + error });
                        }
                    });
                }
            });
        }
        <?php endif; ?>
    </script>
</body>
</html>
