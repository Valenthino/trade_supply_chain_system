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
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Commodity Flow — Price Grid</title>

  <!-- Tailwind CSS CDN -->
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      darkMode: 'class',
      theme: {
        extend: {
          fontFamily: { sans: ['Inter', 'system-ui', 'sans-serif'] },
          colors: {
            brand: {
              50:  '#f0f9f9',
              100: '#d9f2f0',
              200: '#b5e6e3',
              300: '#82d3cf',
              400: '#4db8b4',
              500: '#2d9d99',
              600: '#247f7c',
              700: '#1d6462',
              800: '#185150',
              900: '#164342',
            },
            slate: { 850: '#172032' }
          },
          boxShadow: {
            'card': '0 1px 3px 0 rgba(0,0,0,0.06), 0 1px 2px -1px rgba(0,0,0,0.04)',
            'card-hover': '0 4px 12px 0 rgba(0,0,0,0.08)',
          }
        }
      }
    }
  </script>

  <!-- Google Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet" />

  <!-- Font Awesome -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />

  <!-- DataTables CSS -->
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/jquery.dataTables.min.css">
  <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.dataTables.min.css">
  <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.dataTables.min.css">

  <!-- Legacy styles -->
  <link rel="stylesheet" href="styles.css?v=5.0">

  <!-- jQuery -->
  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <!-- SweetAlert2 -->
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <!-- DataTables JS -->
  <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
  <script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
  <script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
  <script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
  <script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js"></script>

  <style>
    /* Scrollbar */
    ::-webkit-scrollbar { width: 5px; height: 5px; }
    ::-webkit-scrollbar-track { background: transparent; }
    ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
    .dark ::-webkit-scrollbar-thumb { background: #334155; }

    /* Skeleton shimmer */
    @keyframes shimmer { 0%{background-position:-400px 0} 100%{background-position:400px 0} }
    .skeleton {
      background: linear-gradient(90deg, #f1f5f9 25%, #e2e8f0 50%, #f1f5f9 75%);
      background-size: 400px 100%;
      animation: shimmer 1.4s ease infinite;
      border-radius: 6px;
    }
    .dark .skeleton {
      background: linear-gradient(90deg, #1e293b 25%, #273349 50%, #1e293b 75%);
      background-size: 400px 100%;
    }

    /* Sidebar transitions */
    #sidebar { transition: width 280ms cubic-bezier(.16,1,.3,1); }
    .sidebar-label { transition: opacity 200ms, width 200ms; }
    .app-collapsed #sidebar { width: 64px; }
    .app-collapsed .sidebar-label { opacity: 0; width: 0; overflow: hidden; }
    .app-collapsed .sidebar-section-label { opacity: 0; }
    .app-collapsed .logo-text { opacity: 0; width: 0; overflow: hidden; }

    /* Nav link active */
    .nav-link.active { background: rgba(45,157,153,0.12); color: #2d9d99; }
    .dark .nav-link.active { background: rgba(45,157,153,0.15); color: #4db8b4; }
    .nav-link.active .nav-icon { color: #2d9d99; }
    .dark .nav-link.active .nav-icon { color: #4db8b4; }
    .nav-link.active::before {
      content: '';
      position: absolute; left: 0; top: 15%; bottom: 15%;
      width: 3px; background: #2d9d99; border-radius: 0 3px 3px 0;
    }

    /* DataTable overrides for Tailwind */
    .dataTables_wrapper { font-family: 'Inter', system-ui, sans-serif; }
    .dataTables_wrapper .dataTables_length,
    .dataTables_wrapper .dataTables_filter,
    .dataTables_wrapper .dataTables_info,
    .dataTables_wrapper .dataTables_paginate { font-size: 13px; color: #64748b; padding: 10px 0; }
    .dark .dataTables_wrapper .dataTables_length,
    .dark .dataTables_wrapper .dataTables_filter,
    .dark .dataTables_wrapper .dataTables_info,
    .dark .dataTables_wrapper .dataTables_paginate { color: #94a3b8; }
    .dataTables_wrapper .dataTables_filter input {
      border: 1px solid #e2e8f0; border-radius: 0.5rem; padding: 6px 12px; font-size: 13px;
      background: #f8fafc; outline: none; transition: border-color 0.2s, box-shadow 0.2s;
    }
    .dataTables_wrapper .dataTables_filter input:focus {
      border-color: #4db8b4; box-shadow: 0 0 0 2px rgba(45,157,153,0.15);
    }
    .dark .dataTables_wrapper .dataTables_filter input {
      background: #334155; border-color: #475569; color: #e2e8f0;
    }
    .dataTables_wrapper .dataTables_length select {
      border: 1px solid #e2e8f0; border-radius: 0.5rem; padding: 4px 8px; font-size: 13px;
      background: #f8fafc; outline: none;
    }
    .dark .dataTables_wrapper .dataTables_length select {
      background: #334155; border-color: #475569; color: #e2e8f0;
    }
    table.dataTable { border-collapse: collapse !important; }
    table.dataTable thead th {
      background: #f8fafc !important; color: #475569 !important; font-weight: 600 !important;
      font-size: 11px !important; text-transform: uppercase; letter-spacing: 0.05em;
      border-bottom: 2px solid #e2e8f0 !important; padding: 10px 14px !important;
    }
    .dark table.dataTable thead th {
      background: #1e293b !important; color: #94a3b8 !important;
      border-bottom: 2px solid #334155 !important;
    }
    table.dataTable tbody td {
      padding: 10px 14px !important; font-size: 13px; border-bottom: 1px solid #f1f5f9 !important;
      color: #334155; vertical-align: middle;
    }
    .dark table.dataTable tbody td { color: #e2e8f0; border-bottom: 1px solid #1e293b !important; }
    table.dataTable tbody tr:hover td { background: #f0fdf4 !important; }
    .dark table.dataTable tbody tr:hover td { background: rgba(45,157,153,0.06) !important; }
    .dataTables_wrapper .dataTables_paginate .paginate_button {
      border: 1px solid #e2e8f0 !important; border-radius: 6px !important; margin: 0 2px;
      font-size: 12px !important; padding: 4px 10px !important;
    }
    .dataTables_wrapper .dataTables_paginate .paginate_button.current {
      background: #2d9d99 !important; color: white !important;
      border-color: #2d9d99 !important; font-weight: 600;
    }
    .dataTables_wrapper .dataTables_paginate .paginate_button:hover {
      background: #f0f9f9 !important; color: #2d9d99 !important; border-color: #2d9d99 !important;
    }
    .dark .dataTables_wrapper .dataTables_paginate .paginate_button {
      border-color: #475569 !important; color: #94a3b8 !important;
    }
    .dark .dataTables_wrapper .dataTables_paginate .paginate_button.current {
      background: #2d9d99 !important; color: white !important; border-color: #2d9d99 !important;
    }
    .dt-buttons .dt-button {
      background: #f8fafc !important; border: 1px solid #e2e8f0 !important; border-radius: 0.5rem !important;
      font-size: 12px !important; padding: 6px 14px !important; color: #475569 !important;
      font-weight: 500; transition: all 0.2s;
    }
    .dt-buttons .dt-button:hover { background: #f0f9f9 !important; border-color: #2d9d99 !important; color: #2d9d99 !important; }
    .dark .dt-buttons .dt-button { background: #1e293b !important; border-color: #475569 !important; color: #94a3b8 !important; }
    .dark .dt-buttons .dt-button:hover { background: #164342 !important; border-color: #2d9d99 !important; color: #4db8b4 !important; }

    /* Tab styling */
    .pricing-tab-content { display: none; }
    .pricing-tab-content.active { display: block; }

    /* Skeleton table */
    .skeleton-table { display: flex; flex-direction: column; gap: 8px; padding: 12px 0; }
    .skeleton-table-row { display: flex; gap: 12px; }
    .skeleton-table-cell { height: 18px; border-radius: 4px; }

    /* Status badges */
    .status-badge { display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px; border-radius: 9999px; font-size: 11px; font-weight: 600; }
    .status-active { background: #ecfdf5; color: #059669; }
    .dark .status-active { background: rgba(5,150,105,0.15); color: #34d399; }
    .status-expired { background: #fef2f2; color: #dc2626; }
    .dark .status-expired { background: rgba(220,38,38,0.15); color: #f87171; }
    .status-superseded { background: #fefce8; color: #ca8a04; }
    .dark .status-superseded { background: rgba(202,138,4,0.15); color: #facc15; }

    /* Action icons */
    .action-icon { background: none; border: none; cursor: pointer; padding: 4px 6px; border-radius: 6px; font-size: 13px; transition: all 0.15s; }
    .edit-icon { color: #3b82f6; }
    .edit-icon:hover { background: #eff6ff; color: #2563eb; }
    .dark .edit-icon:hover { background: rgba(59,130,246,0.15); }
    .delete-icon { color: #ef4444; }
    .delete-icon:hover { background: #fef2f2; color: #dc2626; }
    .dark .delete-icon:hover { background: rgba(239,68,68,0.15); }

    /* Modal overlay */
    .modal-overlay { display: none; position: fixed; inset: 0; z-index: 50; background: rgba(0,0,0,0.4); backdrop-filter: blur(4px); justify-content: center; align-items: center; }
    .modal-overlay.active { display: flex; }
    .modal-overlay .modal { background: white; border-radius: 1rem; width: 95%; max-width: 640px; max-height: 90vh; overflow-y: auto; box-shadow: 0 20px 60px rgba(0,0,0,0.15); }
    .dark .modal-overlay .modal { background: #1e293b; }

    /* Searchable dropdown */
    .searchable-dropdown { position: relative; }
    .searchable-dropdown-input { width: 100%; }
    .searchable-dropdown-arrow { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); color: #94a3b8; pointer-events: none; transition: transform 0.2s; }
    .searchable-dropdown-arrow.open { transform: translateY(-50%) rotate(180deg); }
    .searchable-dropdown-list { position: absolute; top: 100%; left: 0; right: 0; z-index: 60; background: white; border: 1px solid #e2e8f0; border-radius: 0.5rem; max-height: 200px; overflow-y: auto; box-shadow: 0 8px 24px rgba(0,0,0,0.1); }
    .dark .searchable-dropdown-list { background: #1e293b; border-color: #475569; }
    .searchable-dropdown-item { padding: 8px 12px; font-size: 13px; cursor: pointer; transition: background 0.1s; }
    .searchable-dropdown-item:hover { background: #f0f9f9; }
    .dark .searchable-dropdown-item:hover { background: #164342; }
    .searchable-dropdown-item.selected { background: #d9f2f0; font-weight: 600; }
    .dark .searchable-dropdown-item.selected { background: rgba(45,157,153,0.2); }
    .searchable-dropdown-item.no-results { color: #94a3b8; cursor: default; }

    /* Scroll hint */
    .table-scroll-hint { display: none; text-align: center; color: #94a3b8; font-size: 12px; padding: 6px 0; }
    @media (max-width: 768px) { .table-scroll-hint { display: block; } }
  </style>
</head>

<body class="h-full bg-slate-50 text-slate-800 font-sans antialiased dark:bg-slate-900 dark:text-slate-200">

<?php include 'mobile-menu.php'; ?>

<div class="flex h-full overflow-hidden" id="appRoot">

  <?php include 'sidebar.php'; ?>

  <!-- MAIN -->
  <div class="flex-1 flex flex-col min-w-0 overflow-hidden">

    <!-- HEADER -->
    <header class="h-14 bg-white dark:bg-slate-800 border-b border-slate-200 dark:border-slate-700 flex items-center gap-4 px-5 flex-shrink-0">
      <button id="mobileSidebarBtn" class="lg:hidden text-slate-500 hover:text-slate-700 dark:hover:text-slate-300">
        <i class="fas fa-bars text-sm"></i>
      </button>

      <div class="flex items-center gap-2">
        <i class="fas fa-tag text-brand-500 text-sm"></i>
        <h1 class="text-base font-bold text-slate-800 dark:text-white">Price Grid</h1>
      </div>

      <div class="ml-auto flex items-center gap-2">
        <!-- Tab Navigation -->
        <div class="flex items-center gap-0.5 bg-slate-100 dark:bg-slate-700 rounded-lg p-1">
          <?php if ($canSeeSupTab): ?>
          <button class="px-3 py-1.5 rounded-md text-xs font-semibold bg-white dark:bg-slate-600 text-slate-700 dark:text-white shadow-sm transition-colors" id="supTabBtn" onclick="switchTab('supplier')">
            <i class="fas fa-user mr-1"></i> Supplier
          </button>
          <?php endif; ?>
          <?php if ($canSeeCustTab): ?>
          <button class="px-3 py-1.5 rounded-md text-xs font-medium text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 transition-colors <?php echo !$canSeeSupTab ? 'bg-white dark:bg-slate-600 text-slate-700 dark:text-white shadow-sm !font-semibold' : ''; ?>" id="custTabBtn" onclick="switchTab('customer')">
            <i class="fas fa-building mr-1"></i> Customer
          </button>
          <?php endif; ?>
        </div>
      </div>
    </header>

    <!-- MAIN CONTENT -->
    <main class="flex-1 overflow-y-auto p-5">

      <!-- ===================== TAB 1: Customer Pricing ===================== -->
      <?php if ($canSeeCustTab): ?>
      <div class="pricing-tab-content <?php echo !$canSeeSupTab ? 'active' : ''; ?>" id="customerTab">
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card">
          <!-- Section header -->
          <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 p-5 border-b border-slate-200 dark:border-slate-700">
            <h2 class="text-sm font-bold text-slate-800 dark:text-white flex items-center gap-2">
              <i class="fas fa-building text-brand-500"></i> Customer Pricing Agreements
            </h2>
            <div class="flex items-center gap-2">
              <button class="bg-slate-100 hover:bg-slate-200 dark:bg-slate-700 dark:hover:bg-slate-600 text-slate-600 dark:text-slate-300 text-xs font-semibold px-3.5 py-2 rounded-lg transition-colors" onclick="loadCustPricing()">
                <i class="fas fa-sync mr-1"></i> Refresh
              </button>
              <?php if ($canCreateCust): ?>
              <button class="bg-brand-500 hover:bg-brand-600 text-white text-xs font-semibold px-3.5 py-2 rounded-lg transition-colors" onclick="openAddCustModal()">
                <i class="fas fa-plus mr-1"></i> Add Agreement
              </button>
              <?php endif; ?>
            </div>
          </div>

          <!-- Filters -->
          <div id="custFiltersSection" style="display: none;" class="px-5 py-3 border-b border-slate-100 dark:border-slate-700/50 bg-slate-50/50 dark:bg-slate-800/50">
            <div class="flex items-center justify-between mb-3">
              <h3 class="text-xs font-semibold text-slate-500 uppercase tracking-wide flex items-center gap-1.5">
                <i class="fas fa-filter text-brand-400"></i> Filters
              </h3>
              <button class="text-xs text-slate-400 hover:text-red-500 transition-colors" onclick="clearCustFilters()">
                <i class="fas fa-times-circle mr-1"></i> Clear All
              </button>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
              <div>
                <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">Date From</label>
                <input type="date" id="custFilterDateFrom" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
              </div>
              <div>
                <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">Date To</label>
                <input type="date" id="custFilterDateTo" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
              </div>
              <div>
                <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">Status</label>
                <select id="custFilterStatus" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
                  <option value="">All</option>
                  <option value="Active">Active</option>
                  <option value="Expired">Expired</option>
                  <option value="Superseded">Superseded</option>
                </select>
              </div>
            </div>
          </div>

          <!-- Skeleton Loader -->
          <div id="custSkeletonLoader" class="p-5">
            <div class="skeleton-table">
              <?php for ($i = 0; $i < 8; $i++): ?>
              <div class="skeleton-table-row">
                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
              </div>
              <?php endfor; ?>
            </div>
          </div>

          <!-- DataTable Container -->
          <div id="custTableContainer" style="display: none;" class="p-5">
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
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card">
          <!-- Section header -->
          <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 p-5 border-b border-slate-200 dark:border-slate-700">
            <h2 class="text-sm font-bold text-slate-800 dark:text-white flex items-center gap-2">
              <i class="fas fa-user text-brand-500"></i> Supplier Pricing Agreements
            </h2>
            <div class="flex items-center gap-2">
              <button class="bg-slate-100 hover:bg-slate-200 dark:bg-slate-700 dark:hover:bg-slate-600 text-slate-600 dark:text-slate-300 text-xs font-semibold px-3.5 py-2 rounded-lg transition-colors" onclick="loadSupPricing()">
                <i class="fas fa-sync mr-1"></i> Refresh
              </button>
              <?php if ($canCreateSup): ?>
              <button class="bg-brand-500 hover:bg-brand-600 text-white text-xs font-semibold px-3.5 py-2 rounded-lg transition-colors" onclick="openAddSupModal()">
                <i class="fas fa-plus mr-1"></i> Add Agreement
              </button>
              <?php endif; ?>
            </div>
          </div>

          <!-- Filters -->
          <div id="supFiltersSection" style="display: none;" class="px-5 py-3 border-b border-slate-100 dark:border-slate-700/50 bg-slate-50/50 dark:bg-slate-800/50">
            <div class="flex items-center justify-between mb-3">
              <h3 class="text-xs font-semibold text-slate-500 uppercase tracking-wide flex items-center gap-1.5">
                <i class="fas fa-filter text-brand-400"></i> Filters
              </h3>
              <button class="text-xs text-slate-400 hover:text-red-500 transition-colors" onclick="clearSupFilters()">
                <i class="fas fa-times-circle mr-1"></i> Clear All
              </button>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
              <div>
                <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">Date From</label>
                <input type="date" id="supFilterDateFrom" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
              </div>
              <div>
                <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">Date To</label>
                <input type="date" id="supFilterDateTo" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
              </div>
              <div>
                <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">Status</label>
                <select id="supFilterStatus" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
                  <option value="">All</option>
                  <option value="Active">Active</option>
                  <option value="Expired">Expired</option>
                  <option value="Superseded">Superseded</option>
                </select>
              </div>
            </div>
          </div>

          <!-- Skeleton Loader -->
          <div id="supSkeletonLoader" class="p-5">
            <div class="skeleton-table">
              <?php for ($i = 0; $i < 8; $i++): ?>
              <div class="skeleton-table-row">
                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                <div class="skeleton skeleton-table-cell" style="flex:1"></div>
              </div>
              <?php endfor; ?>
            </div>
          </div>

          <!-- DataTable Container -->
          <div id="supTableContainer" style="display: none;" class="p-5">
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

    </main>
  </div>
</div>

<!-- ===================== Customer Pricing Modal ===================== -->
<?php if ($canCreateCust || $canUpdateCust): ?>
<div class="modal-overlay" id="custPricingModal">
  <div class="modal" onclick="event.stopPropagation()">
    <div class="flex items-center justify-between px-6 py-4 border-b border-slate-200 dark:border-slate-700">
      <h3 id="custModalTitle" class="text-sm font-bold text-slate-800 dark:text-white flex items-center gap-2"><i class="fas fa-plus text-brand-500"></i> Add Customer Pricing Agreement</h3>
      <button class="w-8 h-8 rounded-lg flex items-center justify-center text-slate-400 hover:text-slate-600 hover:bg-slate-100 dark:hover:bg-slate-700 dark:hover:text-slate-300 transition-colors" onclick="closeCustModal()">
        <i class="fas fa-times text-sm"></i>
      </button>
    </div>
    <div class="px-6 py-5">
      <div id="custIdInfo" style="display: none;" class="bg-brand-50 dark:bg-brand-900/20 border border-brand-200 dark:border-brand-800 rounded-lg px-4 py-3 mb-5 text-sm text-brand-700 dark:text-brand-300">
        <strong><i class="fas fa-id-badge mr-1"></i> Agreement ID:</strong> <span id="custIdDisplay"></span>
      </div>

      <form id="custPricingForm">
        <input type="hidden" id="custAgreementId" name="price_agreement_id">

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">Effective Date *</label>
            <input type="date" id="custEffectiveDate" name="effective_date" required class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">Customer *</label>
            <div class="searchable-dropdown" id="custDropdownWrapper">
              <input type="text" class="searchable-dropdown-input w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors pr-8" id="custSearch" placeholder="Search customer..." autocomplete="off">
              <input type="hidden" id="custCustomerId" name="customer_id" required>
              <span class="searchable-dropdown-arrow" id="custArrow"><i class="fas fa-chevron-down text-xs"></i></span>
              <div class="searchable-dropdown-list" id="custList" style="display:none;"></div>
            </div>
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">Contract Type</label>
            <select id="custContractType" name="contract_type_id" onchange="toggleBaseCostRequired()" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
              <option value="">Select Contract Type</option>
            </select>
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">Base Cost/Kg <span id="baseCostReqStar">*</span></label>
            <input type="number" id="custBaseCost" name="base_cost_per_kg" step="0.01" min="0.01" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">Status</label>
            <select id="custStatus" name="status" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
              <option value="Active">Active</option>
              <option value="Expired">Expired</option>
              <option value="Superseded">Superseded</option>
            </select>
          </div>
        </div>

        <div class="mt-4">
          <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">Pricing Notes <span class="normal-case font-normal text-slate-400">(quality tiers, reference pricing, etc.)</span></label>
          <textarea id="custPricingNotes" name="pricing_notes" rows="4" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors font-mono resize-y" placeholder="e.g.&#10;460 F for 45 lbs/80kg&#10;475 F for 46 lbs/80kg&#10;485 F for 47 lbs/80kg&#10;500 F for 48+ lbs/80kg"></textarea>
        </div>

        <div class="flex items-center justify-end gap-3 mt-6 pt-4 border-t border-slate-200 dark:border-slate-700">
          <button type="button" class="bg-slate-100 hover:bg-slate-200 dark:bg-slate-700 dark:hover:bg-slate-600 text-slate-600 dark:text-slate-300 text-xs font-semibold px-3.5 py-2 rounded-lg transition-colors" onclick="closeCustModal()">
            <i class="fas fa-times mr-1"></i> Cancel
          </button>
          <button type="submit" class="bg-brand-500 hover:bg-brand-600 text-white text-xs font-semibold px-3.5 py-2 rounded-lg transition-colors">
            <i class="fas fa-save mr-1"></i> Save
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
    <div class="flex items-center justify-between px-6 py-4 border-b border-slate-200 dark:border-slate-700">
      <h3 id="supModalTitle" class="text-sm font-bold text-slate-800 dark:text-white flex items-center gap-2"><i class="fas fa-plus text-brand-500"></i> Add Supplier Pricing Agreement</h3>
      <button class="w-8 h-8 rounded-lg flex items-center justify-center text-slate-400 hover:text-slate-600 hover:bg-slate-100 dark:hover:bg-slate-700 dark:hover:text-slate-300 transition-colors" onclick="closeSupModal()">
        <i class="fas fa-times text-sm"></i>
      </button>
    </div>
    <div class="px-6 py-5">
      <div id="supIdInfo" style="display: none;" class="bg-brand-50 dark:bg-brand-900/20 border border-brand-200 dark:border-brand-800 rounded-lg px-4 py-3 mb-5 text-sm text-brand-700 dark:text-brand-300">
        <strong><i class="fas fa-id-badge mr-1"></i> Agreement ID:</strong> <span id="supIdDisplay"></span>
      </div>

      <form id="supPricingForm">
        <input type="hidden" id="supAgreementId" name="price_agreement_id">

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">Effective Date *</label>
            <input type="date" id="supEffectiveDate" name="effective_date" required class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">Supplier *</label>
            <div class="searchable-dropdown" id="supDropdownWrapper">
              <input type="text" class="searchable-dropdown-input w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors pr-8" id="supSearch" placeholder="Search supplier..." autocomplete="off">
              <input type="hidden" id="supSupplierId" name="supplier_id" required>
              <span class="searchable-dropdown-arrow" id="supArrow"><i class="fas fa-chevron-down text-xs"></i></span>
              <div class="searchable-dropdown-list" id="supList" style="display:none;"></div>
            </div>
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">Base Cost/Kg *</label>
            <input type="number" id="supBaseCost" name="base_cost_per_kg" step="0.01" min="0.01" required class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">Status</label>
            <select id="supStatus" name="status" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
              <option value="Active">Active</option>
              <option value="Expired">Expired</option>
              <option value="Superseded">Superseded</option>
            </select>
          </div>
        </div>

        <div class="flex items-center justify-end gap-3 mt-6 pt-4 border-t border-slate-200 dark:border-slate-700">
          <button type="button" class="bg-slate-100 hover:bg-slate-200 dark:bg-slate-700 dark:hover:bg-slate-600 text-slate-600 dark:text-slate-300 text-xs font-semibold px-3.5 py-2 rounded-lg transition-colors" onclick="closeSupModal()">
            <i class="fas fa-times mr-1"></i> Cancel
          </button>
          <button type="submit" class="bg-brand-500 hover:bg-brand-600 text-white text-xs font-semibold px-3.5 py-2 rounded-lg transition-colors">
            <i class="fas fa-save mr-1"></i> Save
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
        var activeClasses = ['bg-white','dark:bg-slate-600','text-slate-700','dark:text-white','shadow-sm','font-semibold'];
        var inactiveClasses = ['text-slate-500','dark:text-slate-400','font-medium'];

        function setTabActive(btn, isActive) {
            if (!btn) return;
            if (isActive) {
                inactiveClasses.forEach(function(c) { btn.classList.remove(c); });
                activeClasses.forEach(function(c) { btn.classList.add(c); });
            } else {
                activeClasses.forEach(function(c) { btn.classList.remove(c); });
                inactiveClasses.forEach(function(c) { btn.classList.add(c); });
            }
        }

        function switchTab(tab) {
            activeTab = tab;

            // Update tab buttons
            var custBtn = document.getElementById('custTabBtn');
            var supBtn = document.getElementById('supTabBtn');
            setTabActive(custBtn, tab === 'customer');
            setTabActive(supBtn, tab === 'supplier');

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

    <!-- Theme init + i18n loader -->
    <script>
    (function() {
      var _store = {}; try { _store = window.localStorage; } catch(e) { _store = { getItem: function(){return null;}, setItem: function(){} }; }
      var html = document.documentElement;
      var dark = _store.getItem('cp_theme') === 'dark' || (_store.getItem('cp_theme') === null && window.matchMedia('(prefers-color-scheme: dark)').matches);
      html.classList.toggle('dark', dark);

      /* Sidebar collapse */
      var appRoot = document.getElementById('appRoot');
      var collapseBtn = document.getElementById('sidebarCollapseBtn');
      if (collapseBtn) {
        collapseBtn.addEventListener('click', function() {
          appRoot.classList.toggle('app-collapsed');
          var ic = document.getElementById('collapseIcon');
          if (ic) ic.style.transform = appRoot.classList.contains('app-collapsed') ? 'rotate(180deg)' : '';
        });
      }

      /* Theme toggle */
      var btn = document.getElementById('themeToggleBtn');
      var icon = document.getElementById('themeIcon');
      var apply = function() {
        html.classList.toggle('dark', dark);
        _store.setItem('cp_theme', dark ? 'dark' : 'light');
        if (icon) icon.className = dark ? 'fas fa-sun w-4 text-sm' : 'fas fa-moon w-4 text-sm';
        var lbl = document.getElementById('themeLabel');
        if (lbl) lbl.textContent = dark ? 'Light Mode' : 'Dark Mode';
      };
      apply();
      if (btn) btn.addEventListener('click', function() { dark = !dark; apply(); });

      /* Language */
      var currentLang = _store.getItem('cp_lang') || 'en';
      var langBtn = document.getElementById('langToggleBtn');
      if (langBtn) {
        langBtn.addEventListener('click', function() {
          currentLang = (currentLang === 'en') ? 'fr' : 'en';
          _store.setItem('cp_lang', currentLang);
          document.documentElement.lang = currentLang;
        });
      }
    })();
    </script>
</body>
</html>
