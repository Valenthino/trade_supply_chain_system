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
$current_page = 'customers';

// RBAC
$allowedRoles = ['Admin', 'Manager', 'Sales Officer', 'Finance Officer'];
if (!in_array($role, $allowedRoles)) {
    header("Location: dashboard.php");
    exit();
}

$canCreate = in_array($role, ['Admin', 'Manager', 'Sales Officer']);
$canUpdate = in_array($role, ['Admin', 'Manager', 'Sales Officer']);
$canDelete = ($role === 'Admin');
$isReadOnly = !$canCreate && !$canUpdate;

// Handle AJAX requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    try {
        switch ($_GET['action']) {
            case 'getCustomers':
                $conn = getDBConnection();

                // batch: standalone payments received from customers (excluding ones tied to a financing entry — those amounts are already in financing.amount)
                $payMap = [];
                $payRes = $conn->query("SELECT counterpart_id, COALESCE(SUM(amount), 0) as total FROM payments WHERE direction = 'Incoming' AND linked_financing_id IS NULL GROUP BY counterpart_id");
                if ($payRes) { while ($r = $payRes->fetch_assoc()) $payMap[$r['counterpart_id']] = floatval($r['total']); }

                // batch: customer financing — cash given to us in advance for future delivery
                $finMap = [];
                $finRes = $conn->query("SELECT counterparty_id, COALESCE(SUM(amount), 0) as total FROM financing WHERE direction = 'Incoming' AND counterpart_type = 'Customer' GROUP BY counterparty_id");
                if ($finRes) { while ($r = $finRes->fetch_assoc()) $finMap[$r['counterparty_id']] = floatval($r['total']); }

                // batch: product value delivered to customers (sales)
                $saleMap = [];
                $saleRes = $conn->query("SELECT customer_id, COALESCE(SUM(gross_sale_amount), 0) as total FROM sales WHERE sale_status IN ('Draft','Confirmed') GROUP BY customer_id");
                if ($saleRes) { while ($r = $saleRes->fetch_assoc()) $saleMap[$r['customer_id']] = floatval($r['total']); }

                $stmt = $conn->prepare("SELECT c.*, l.location_name, ct.contract_type_name
                    FROM customers c
                    LEFT JOIN settings_locations l ON c.location_id = l.location_id
                    LEFT JOIN settings_contract_types ct ON c.contract_type_id = ct.contract_type_id
                    ORDER BY c.customer_id DESC");
                $stmt->execute();
                $result = $stmt->get_result();

                $customers = [];
                while ($row = $result->fetch_assoc()) {
                    $cid = $row['customer_id'];
                    // Balance = (Standalone Payments In + Financing In) - Sales Value
                    // positive = we owe them goods/refund, negative = they owe us
                    $paymentsIn = $payMap[$cid] ?? 0;
                    $financingIn = $finMap[$cid] ?? 0;
                    $productValue = $saleMap[$cid] ?? 0;
                    $accountBalance = ($paymentsIn + $financingIn) - $productValue;

                    $customers[] = [
                        'customer_id' => $row['customer_id'],
                        'customer_name' => $row['customer_name'],
                        'contact_person' => $row['contact_person'] ?? '',
                        'phone' => $row['phone'] ?? '',
                        'phone2' => $row['phone2'] ?? '',
                        'email' => $row['email'] ?? '',
                        'location_id' => $row['location_id'],
                        'latitude' => $row['latitude'] ?? '',
                        'longitude' => $row['longitude'] ?? '',
                        'location_name' => $row['location_name'] ?? 'N/A',
                        'contract_type_id' => $row['contract_type_id'],
                        'contract_type_name' => $row['contract_type_name'] ?? 'N/A',
                        'interest_rate' => $row['interest_rate'],
                        'payment_terms' => $row['payment_terms'] ?? '',
                        'quality_terms' => $row['quality_terms'] ?? '',
                        'financing_provided' => $row['financing_provided'],
                        'account_balance' => round($accountBalance, 2),
                        'status' => $row['status'],
                        'created_at' => date('M d, Y', strtotime($row['created_at']))
                    ];
                }

                $stmt->close();
                $conn->close();

                echo json_encode(['success' => true, 'data' => $customers]);
                exit();

            case 'getDropdowns':
                $conn = getDBConnection();

                // Get active locations
                $result = $conn->query("SELECT location_id, location_name FROM settings_locations WHERE is_active = 1 ORDER BY location_name ASC");
                $locations = [];
                if ($result) {
                    while ($row = $result->fetch_assoc()) {
                        $locations[] = ['id' => $row['location_id'], 'name' => $row['location_name']];
                    }
                }

                // Get active contract types
                $result = $conn->query("SELECT contract_type_id, contract_type_name FROM settings_contract_types WHERE is_active = 1 ORDER BY contract_type_name ASC");
                $contractTypes = [];
                if ($result) {
                    while ($row = $result->fetch_assoc()) {
                        $contractTypes[] = ['id' => $row['contract_type_id'], 'name' => $row['contract_type_name']];
                    }
                }

                $conn->close();

                echo json_encode(['success' => true, 'locations' => $locations, 'contract_types' => $contractTypes]);
                exit();

            case 'addCustomer':
                if (!$canCreate) {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $name = isset($_POST['customer_name']) ? trim($_POST['customer_name']) : '';
                $contactPerson = isset($_POST['contact_person']) && $_POST['contact_person'] !== '' ? trim($_POST['contact_person']) : '';
                $phone = isset($_POST['phone']) && $_POST['phone'] !== '' ? trim($_POST['phone']) : '';
                $phone2 = isset($_POST['phone2']) && $_POST['phone2'] !== '' ? trim($_POST['phone2']) : '';
                $email = isset($_POST['email']) && $_POST['email'] !== '' ? trim($_POST['email']) : '';
                $locationId = isset($_POST['location_id']) && $_POST['location_id'] !== '' ? intval($_POST['location_id']) : 0;
                $latitude = isset($_POST['latitude']) && $_POST['latitude'] !== '' ? floatval($_POST['latitude']) : null;
                $longitude = isset($_POST['longitude']) && $_POST['longitude'] !== '' ? floatval($_POST['longitude']) : null;
                $contractTypeId = isset($_POST['contract_type_id']) && $_POST['contract_type_id'] !== '' ? intval($_POST['contract_type_id']) : 0;
                $interestRate = isset($_POST['interest_rate']) && $_POST['interest_rate'] !== '' ? floatval($_POST['interest_rate']) : 0;
                $paymentTerms = isset($_POST['payment_terms']) && $_POST['payment_terms'] !== '' ? trim($_POST['payment_terms']) : '';
                $qualityTerms = isset($_POST['quality_terms']) && $_POST['quality_terms'] !== '' ? trim($_POST['quality_terms']) : '';
                $financingProvided = isset($_POST['financing_provided']) && $_POST['financing_provided'] !== '' ? floatval($_POST['financing_provided']) : 0;

                if (empty($name)) {
                    echo json_encode(['success' => false, 'message' => 'Customer name is required']);
                    exit();
                }

                if (strlen($name) > 200) {
                    echo json_encode(['success' => false, 'message' => 'Customer name must be less than 200 characters']);
                    exit();
                }

                $conn = getDBConnection();

                // Auto-generate customer_id: CUST-001, CUST-002, etc.
                $result = $conn->query("SELECT MAX(customer_id) as max_id FROM customers");
                $row = $result->fetch_assoc();
                $maxId = $row['max_id'];

                if ($maxId) {
                    $num = intval(substr($maxId, 5)); // Extract number after "CUST-"
                    $newNum = $num + 1;
                } else {
                    $newNum = 1;
                }
                $newId = 'CUST-' . str_pad($newNum, 3, '0', STR_PAD_LEFT);

                $locVal = $locationId > 0 ? strval($locationId) : null;
                $latVal = $latitude !== null ? strval($latitude) : null;
                $lngVal = $longitude !== null ? strval($longitude) : null;
                $ctVal = $contractTypeId > 0 ? strval($contractTypeId) : null;
                $irVal = strval($interestRate);
                $fpVal = strval($financingProvided);

                $stmt = $conn->prepare("INSERT INTO customers (customer_id, customer_name, contact_person, phone, phone2, email, location_id, latitude, longitude, contract_type_id, interest_rate, payment_terms, quality_terms, financing_provided) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("ssssssssssssss", $newId, $name, $contactPerson, $phone, $phone2, $email, $locVal, $latVal, $lngVal, $ctVal, $irVal, $paymentTerms, $qualityTerms, $fpVal);

                if ($stmt->execute()) {
                    logActivity($user_id, $username, 'Customer Created', "Created customer: $name ($newId)");
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => true, 'message' => 'Customer added successfully']);
                } else {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to add customer']);
                }
                exit();

            case 'updateCustomer':
                if (!$canUpdate) {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $customerId = isset($_POST['customer_id']) ? trim($_POST['customer_id']) : '';
                $name = isset($_POST['customer_name']) ? trim($_POST['customer_name']) : '';
                $contactPerson = isset($_POST['contact_person']) && $_POST['contact_person'] !== '' ? trim($_POST['contact_person']) : '';
                $phone = isset($_POST['phone']) && $_POST['phone'] !== '' ? trim($_POST['phone']) : '';
                $phone2 = isset($_POST['phone2']) && $_POST['phone2'] !== '' ? trim($_POST['phone2']) : '';
                $email = isset($_POST['email']) && $_POST['email'] !== '' ? trim($_POST['email']) : '';
                $locationId = isset($_POST['location_id']) && $_POST['location_id'] !== '' ? intval($_POST['location_id']) : 0;
                $latitude = isset($_POST['latitude']) && $_POST['latitude'] !== '' ? floatval($_POST['latitude']) : null;
                $longitude = isset($_POST['longitude']) && $_POST['longitude'] !== '' ? floatval($_POST['longitude']) : null;
                $contractTypeId = isset($_POST['contract_type_id']) && $_POST['contract_type_id'] !== '' ? intval($_POST['contract_type_id']) : 0;
                $interestRate = isset($_POST['interest_rate']) && $_POST['interest_rate'] !== '' ? floatval($_POST['interest_rate']) : 0;
                $paymentTerms = isset($_POST['payment_terms']) && $_POST['payment_terms'] !== '' ? trim($_POST['payment_terms']) : '';
                $qualityTerms = isset($_POST['quality_terms']) && $_POST['quality_terms'] !== '' ? trim($_POST['quality_terms']) : '';
                $financingProvided = isset($_POST['financing_provided']) && $_POST['financing_provided'] !== '' ? floatval($_POST['financing_provided']) : 0;

                if (empty($customerId)) {
                    echo json_encode(['success' => false, 'message' => 'Customer ID is required']);
                    exit();
                }

                if (empty($name)) {
                    echo json_encode(['success' => false, 'message' => 'Customer name is required']);
                    exit();
                }

                if (strlen($name) > 200) {
                    echo json_encode(['success' => false, 'message' => 'Customer name must be less than 200 characters']);
                    exit();
                }

                $conn = getDBConnection();

                // Verify customer exists
                $stmt = $conn->prepare("SELECT customer_id FROM customers WHERE customer_id = ?");
                $stmt->bind_param("s", $customerId);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows == 0) {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Customer not found']);
                    exit();
                }
                $stmt->close();

                $locVal = $locationId > 0 ? strval($locationId) : null;
                $latVal = $latitude !== null ? strval($latitude) : null;
                $lngVal = $longitude !== null ? strval($longitude) : null;
                $ctVal = $contractTypeId > 0 ? strval($contractTypeId) : null;
                $irVal = strval($interestRate);
                $fpVal = strval($financingProvided);

                $stmt = $conn->prepare("UPDATE customers SET customer_name = ?, contact_person = ?, phone = ?, phone2 = ?, email = ?, location_id = ?, latitude = ?, longitude = ?, contract_type_id = ?, interest_rate = ?, payment_terms = ?, quality_terms = ?, financing_provided = ? WHERE customer_id = ?");
                $stmt->bind_param("ssssssssssssss", $name, $contactPerson, $phone, $phone2, $email, $locVal, $latVal, $lngVal, $ctVal, $irVal, $paymentTerms, $qualityTerms, $fpVal, $customerId);

                if ($stmt->execute()) {
                    logActivity($user_id, $username, 'Customer Updated', "Updated customer: $name ($customerId)");
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => true, 'message' => 'Customer updated successfully']);
                } else {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to update customer']);
                }
                exit();

            case 'toggleStatus':
                if (!$canUpdate) {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $customerId = isset($_POST['customer_id']) ? trim($_POST['customer_id']) : '';

                if (empty($customerId)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid customer ID']);
                    exit();
                }

                $conn = getDBConnection();

                // Get current status and name
                $stmt = $conn->prepare("SELECT customer_name, status FROM customers WHERE customer_id = ?");
                $stmt->bind_param("s", $customerId);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows == 0) {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Customer not found']);
                    exit();
                }

                $customer = $result->fetch_assoc();
                $stmt->close();

                $newStatus = ($customer['status'] === 'Active') ? 'Inactive' : 'Active';
                $stmt = $conn->prepare("UPDATE customers SET status = ? WHERE customer_id = ?");
                $stmt->bind_param("ss", $newStatus, $customerId);

                if ($stmt->execute()) {
                    $statusText = strtolower($newStatus) === 'active' ? 'activated' : 'deactivated';
                    logActivity($user_id, $username, 'Customer Status Changed', "Customer {$customer['customer_name']} ($customerId) $statusText");
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => true, 'message' => "Customer $statusText successfully"]);
                } else {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to update customer status']);
                }
                exit();

            case 'deleteCustomer':
                if (!$canDelete) {
                    echo json_encode(['success' => false, 'message' => 'Access denied. Only Admin can delete.']);
                    exit();
                }

                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $customerId = isset($_POST['customer_id']) ? trim($_POST['customer_id']) : '';

                if (empty($customerId)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid customer ID']);
                    exit();
                }

                $conn = getDBConnection();

                // Get customer name for logging
                $stmt = $conn->prepare("SELECT customer_name FROM customers WHERE customer_id = ?");
                $stmt->bind_param("s", $customerId);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows == 0) {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Customer not found']);
                    exit();
                }

                $customer = $result->fetch_assoc();
                $stmt->close();

                $stmt = $conn->prepare("DELETE FROM customers WHERE customer_id = ?");
                $stmt->bind_param("s", $customerId);

                if ($stmt->execute()) {
                    logActivity($user_id, $username, 'Customer Deleted', "Deleted customer: {$customer['customer_name']} ($customerId)");
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => true, 'message' => 'Customer deleted successfully']);
                } else {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to delete customer']);
                }
                exit();

            case 'getCustomerReport':
                $customerId = $_GET['customer_id'] ?? '';
                $conn = getDBConnection();

                // Fetch customer details
                $stmt = $conn->prepare("SELECT * FROM customers WHERE customer_id = ?");
                $stmt->bind_param("s", $customerId);
                $stmt->execute();
                $result = $stmt->get_result();
                $customer = $result ? $result->fetch_assoc() : null;
                $stmt->close();

                if (!$customer) {
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Customer not found']);
                    exit();
                }

                // Fetch sales
                $sales = [];
                $stmt = $conn->prepare("SELECT sale_id, unloading_date, net_weight_kg, selling_price_per_kg, gross_sale_amount, net_profit, sale_status, season FROM sales WHERE customer_id = ? ORDER BY unloading_date DESC");
                $stmt->bind_param("s", $customerId);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($result) {
                    while ($row = $result->fetch_assoc()) {
                        $sales[] = $row;
                    }
                }
                $stmt->close();

                // Fetch deliveries
                $deliveries = [];
                $stmt = $conn->prepare("SELECT d.delivery_id, d.date, d.weight_kg, d.num_bags, d.status, w.warehouse_name FROM deliveries d LEFT JOIN settings_warehouses w ON d.origin_warehouse_id = w.warehouse_id WHERE d.customer_id = ? ORDER BY d.date DESC");
                $stmt->bind_param("s", $customerId);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($result) {
                    while ($row = $result->fetch_assoc()) {
                        $deliveries[] = $row;
                    }
                }
                $stmt->close();

                // Fetch payments
                $payments = [];
                $stmt = $conn->prepare("SELECT payment_id, date, direction, payment_type, amount, payment_mode, reference_number, linked_financing_id FROM payments WHERE counterpart_id = ? ORDER BY date DESC");
                $stmt->bind_param("s", $customerId);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($result) {
                    while ($row = $result->fetch_assoc()) {
                        $payments[] = $row;
                    }
                }
                $stmt->close();

                // sync auto-overpayment financing first
                syncCustomerOverpayment($conn, $customerId, $customer['customer_name'] ?? '');

                // Fetch financing (after sync)
                $financing = [];
                $stmt = $conn->prepare("SELECT financing_id, date, direction, amount, amount_repaid, balance_due, status FROM financing WHERE counterparty_id = ? ORDER BY date DESC");
                $stmt->bind_param("s", $customerId);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($result) {
                    while ($row = $result->fetch_assoc()) {
                        $financing[] = $row;
                    }
                }
                $stmt->close();

                // calc account balance = (standalone payments + financing in) - sales value
                // standalone payments = payments NOT linked to a financing record (those amounts are already in financing.amount)
                $paymentsIn = 0; $salesTotal = 0; $financingIn = 0;
                foreach ($payments as $p) {
                    if (strtolower($p['direction']) === 'incoming' && empty($p['linked_financing_id'])) {
                        $paymentsIn += floatval($p['amount']);
                    }
                }
                foreach ($sales as $s) {
                    if (in_array($s['sale_status'], ['Draft','Confirmed'])) $salesTotal += floatval($s['gross_sale_amount']);
                }
                foreach ($financing as $f) {
                    if (strtolower($f['direction']) === 'incoming') $financingIn += floatval($f['amount']);
                }
                $accountBalance = round(($paymentsIn + $financingIn) - $salesTotal, 2);

                // build unified transaction log
                $txnLog = [];

                // standalone payments received (Incoming, not linked to financing)
                $stmt = $conn->prepare("SELECT payment_id, date, amount, payment_mode FROM payments WHERE counterpart_id = ? AND direction = 'Incoming' AND (linked_financing_id IS NULL OR linked_financing_id = '') ORDER BY date ASC, payment_id ASC");
                $stmt->bind_param("s", $customerId);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($r = $res->fetch_assoc()) {
                    $txnLog[] = ['date' => $r['date'], 'desc' => 'Payment Received', 'reference' => $r['payment_id'], 'weight_kg' => null, 'price_per_kg' => null, 'sale_amt' => null, 'payment_amt' => round(floatval($r['amount']), 2), 'payment_mode' => $r['payment_mode'] ?? '', 'sk' => 1];
                }
                $stmt->close();

                // incoming financing from customer (Manual only)
                $stmt = $conn->prepare("SELECT financing_id, date, amount FROM financing WHERE counterparty_id = ? AND direction = 'Incoming' AND counterpart_type = 'Customer' AND source = 'Manual' ORDER BY date ASC, financing_id ASC");
                $stmt->bind_param("s", $customerId);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($r = $res->fetch_assoc()) {
                    $txnLog[] = ['date' => $r['date'], 'desc' => 'Financing Advance', 'reference' => $r['financing_id'], 'weight_kg' => null, 'price_per_kg' => null, 'sale_amt' => null, 'payment_amt' => round(floatval($r['amount']), 2), 'payment_mode' => '', 'sk' => 2];
                }
                $stmt->close();

                // sales (Draft/Confirmed)
                $stmt = $conn->prepare("SELECT sale_id, unloading_date, gross_sale_amount, net_weight_kg, selling_price_per_kg FROM sales WHERE customer_id = ? AND sale_status IN ('Draft','Confirmed') ORDER BY unloading_date ASC, sale_id ASC");
                $stmt->bind_param("s", $customerId);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($r = $res->fetch_assoc()) {
                    $txnLog[] = ['date' => $r['unloading_date'], 'desc' => 'Sale / Delivery', 'reference' => $r['sale_id'], 'weight_kg' => round(floatval($r['net_weight_kg']), 2), 'price_per_kg' => round(floatval($r['selling_price_per_kg']), 2), 'sale_amt' => round(floatval($r['gross_sale_amount']), 2), 'payment_amt' => null, 'payment_mode' => '', 'sk' => 3];
                }
                $stmt->close();

                // sort chronologically
                usort($txnLog, function($a, $b) {
                    $d = strcmp($a['date'], $b['date']);
                    return $d !== 0 ? $d : $a['sk'] - $b['sk'];
                });

                // compute prev/new balance per row
                $rb = 0;
                foreach ($txnLog as &$t) {
                    $t['prev_balance'] = round($rb, 2);
                    $pay = floatval($t['payment_amt'] ?? 0);
                    $sale = floatval($t['sale_amt'] ?? 0);
                    $rb += $pay - $sale;
                    $t['new_balance'] = round($rb, 2);
                    unset($t['sk']);
                }
                unset($t);

                $conn->close();

                echo json_encode([
                    'success' => true,
                    'data' => [
                        'customer' => $customer,
                        'sales' => $sales,
                        'deliveries' => $deliveries,
                        'payments' => $payments,
                        'financing' => $financing,
                        'account_balance' => $accountBalance,
                        'transactions_log' => $txnLog
                    ]
                ]);
                exit();

            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
                exit();
        }
    } catch (\Throwable $e) {
        error_log("customers.php error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="mobile-web-app-capable" content="yes">
  <title>Commodity Flow &mdash; Customers</title>

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

  <!-- App styles -->
  <link rel="stylesheet" href="styles.css?v=4.0">

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

    /* Nav-link active */
    .nav-link.active { background: rgba(45,157,153,0.12); color: #2d9d99; }
    .dark .nav-link.active { background: rgba(45,157,153,0.15); color: #4db8b4; }
    .nav-link.active .nav-icon { color: #2d9d99; }
    .dark .nav-link.active .nav-icon { color: #4db8b4; }
    .nav-link.active::before {
      content: '';
      position: absolute; left: 0; top: 15%; bottom: 15%;
      width: 3px; background: #2d9d99; border-radius: 0 3px 3px 0;
    }

    /* DataTable overrides for Tailwind dark mode */
    .dataTables_wrapper { color: inherit; }
    .dataTables_wrapper .dataTables_length,
    .dataTables_wrapper .dataTables_filter,
    .dataTables_wrapper .dataTables_info,
    .dataTables_wrapper .dataTables_paginate { color: inherit; font-size: 0.8rem; padding: 8px 0; }
    .dataTables_wrapper .dataTables_filter input,
    .dataTables_wrapper .dataTables_length select {
      background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 0.5rem; padding: 4px 10px; font-size: 0.8rem; color: inherit;
    }
    .dark .dataTables_wrapper .dataTables_filter input,
    .dark .dataTables_wrapper .dataTables_length select {
      background: #334155; border-color: #475569; color: #e2e8f0;
    }
    table.dataTable { border-collapse: collapse !important; width: 100% !important; }
    table.dataTable thead th { background: #f8fafc; color: #475569; font-size: 0.7rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; padding: 10px 14px; border-bottom: 2px solid #e2e8f0; white-space: nowrap; }
    .dark table.dataTable thead th { background: #1e293b; color: #94a3b8; border-bottom-color: #334155; }
    table.dataTable tbody td { padding: 10px 14px; font-size: 0.82rem; border-bottom: 1px solid #f1f5f9; color: #334155; vertical-align: middle; }
    .dark table.dataTable tbody td { border-bottom-color: #1e293b; color: #cbd5e1; }
    table.dataTable tbody tr:hover { background: #f0f9f9 !important; }
    .dark table.dataTable tbody tr:hover { background: rgba(45,157,153,0.06) !important; }
    table.dataTable.no-footer { border-bottom: none; }
    .dataTables_wrapper .dataTables_paginate .paginate_button { font-size: 0.78rem; border-radius: 6px; padding: 4px 10px; border: 1px solid #e2e8f0; margin: 0 2px; }
    .dataTables_wrapper .dataTables_paginate .paginate_button.current { background: #2d9d99 !important; color: #fff !important; border-color: #2d9d99 !important; }
    .dataTables_wrapper .dataTables_paginate .paginate_button:hover { background: #f0f9f9 !important; color: #2d9d99 !important; border-color: #2d9d99 !important; }
    .dark .dataTables_wrapper .dataTables_paginate .paginate_button { border-color: #475569; color: #94a3b8; }
    .dark .dataTables_wrapper .dataTables_paginate .paginate_button.current { background: #2d9d99 !important; color: #fff !important; border-color: #2d9d99 !important; }
    .dark .dataTables_wrapper .dataTables_paginate .paginate_button:hover { background: rgba(45,157,153,0.15) !important; color: #4db8b4 !important; border-color: #2d9d99 !important; }
    .dt-buttons .dt-button { font-size: 0.75rem; padding: 5px 14px; border-radius: 6px; border: 1px solid #e2e8f0; background: #fff; color: #475569; font-weight: 500; }
    .dt-buttons .dt-button:hover { background: #f0f9f9; border-color: #2d9d99; color: #2d9d99; }
    .dark .dt-buttons .dt-button { background: #1e293b; border-color: #475569; color: #94a3b8; }
    .dark .dt-buttons .dt-button:hover { background: rgba(45,157,153,0.15); color: #4db8b4; border-color: #2d9d99; }

    /* Report tab and table styles inside Tailwind context */
    .report-tabs { display: flex; border-bottom: 2px solid #e2e8f0; background: #fff; padding: 0 20px; gap: 0; overflow-x: auto; }
    .dark .report-tabs { border-bottom-color: #334155; background: #1e293b; }
    .report-tab { padding: 12px 20px; border: none; background: transparent; color: #94a3b8; font-size: 13px; font-weight: 600; cursor: pointer; border-bottom: 3px solid transparent; transition: all 0.3s; font-family: inherit; display: flex; align-items: center; gap: 6px; white-space: nowrap; }
    .report-tab:hover { color: #2d9d99; background: rgba(45,157,153,0.05); }
    .report-tab.active { color: #2d9d99; border-bottom-color: #2d9d99; }
    .report-summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin-bottom: 16px; }
    .report-summary-card { background: #f8fafc; border-radius: 8px; padding: 14px; text-align: center; }
    .dark .report-summary-card { background: #0f172a; }
    .report-summary-card .val { font-size: 22px; font-weight: 700; color: #2d9d99; }
    .report-summary-card .lbl { font-size: 11px; color: #94a3b8; text-transform: uppercase; margin-top: 4px; }
    .report-table { width: 100%; border-collapse: collapse; font-size: 12px; }
    .report-table thead th { background: #1e293b; color: white; padding: 8px 10px; text-align: left; font-size: 11px; font-weight: 600; }
    .report-table tbody td { padding: 8px 10px; border-bottom: 1px solid #f1f5f9; color: #334155; }
    .dark .report-table tbody td { border-bottom-color: #1e293b; color: #cbd5e1; }
    .report-table tbody tr:hover { background: #f0f9f9; }
    .dark .report-table tbody tr:hover { background: rgba(45,157,153,0.06); }

    /* Action icon buttons */
    .action-icon { display: inline-flex; align-items: center; justify-content: center; width: 30px; height: 30px; border-radius: 6px; border: none; background: transparent; cursor: pointer; transition: all 0.15s; font-size: 13px; }
    .action-icon:hover { background: #f1f5f9; }
    .dark .action-icon:hover { background: #334155; }

    /* Status badges */
    .status-badge { display: inline-flex; align-items: center; gap: 4px; padding: 2px 10px; border-radius: 9999px; font-size: 0.7rem; font-weight: 600; }
    .status-active { background: #dcfce7; color: #16a34a; }
    .dark .status-active { background: rgba(22,163,74,0.15); color: #4ade80; }
    .status-inactive { background: #fee2e2; color: #dc2626; }
    .dark .status-inactive { background: rgba(220,38,38,0.15); color: #f87171; }

    /* Modal overlay */
    .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); backdrop-filter: blur(4px); z-index: 1000; align-items: center; justify-content: center; padding: 16px; }
    .modal-overlay.active { display: flex; }
    .modal-dialog { background: #fff; border-radius: 16px; width: 100%; max-width: 700px; max-height: 90vh; overflow-y: auto; box-shadow: 0 24px 48px -12px rgba(0,0,0,0.25); }
    .dark .modal-dialog { background: #1e293b; }
    .modal-dialog-wide { max-width: 80%; width: 80%; }

    .tabular { font-variant-numeric: tabular-nums lining-nums; }
  </style>
</head>

<body class="h-full bg-slate-50 text-slate-800 font-sans antialiased dark:bg-slate-900 dark:text-slate-200">

  <?php include 'mobile-menu.php'; ?>

  <div class="flex h-full overflow-hidden" id="appRoot">

    <?php include 'sidebar.php'; ?>

    <!-- Main Content -->
    <div class="flex-1 flex flex-col min-w-0 overflow-hidden">

      <!-- Header -->
      <header class="h-14 bg-white dark:bg-slate-800 border-b border-slate-200 dark:border-slate-700 flex items-center gap-4 px-5 flex-shrink-0">
        <button id="mobileSidebarBtn" class="lg:hidden text-slate-500 hover:text-slate-700 dark:hover:text-slate-300">
          <i class="fas fa-bars text-sm"></i>
        </button>

        <div class="flex items-center gap-2">
          <i class="fas fa-users text-brand-500 text-sm"></i>
          <h1 class="text-base font-bold text-slate-800 dark:text-white">Customers</h1>
        </div>

        <div class="ml-auto flex items-center gap-3">
          <button class="flex items-center gap-1.5 text-xs font-medium text-slate-500 hover:text-brand-500 dark:text-slate-400 dark:hover:text-brand-400 transition-colors" onclick="loadCustomers()">
            <i class="fas fa-sync text-[10px]"></i>
            <span class="hidden sm:inline">Refresh</span>
          </button>
          <?php if ($canCreate): ?>
          <button onclick="openAddModal()" class="flex items-center gap-2 bg-brand-500 hover:bg-brand-600 text-white text-xs font-semibold px-3.5 py-2 rounded-lg shadow-sm transition-colors">
            <i class="fas fa-plus text-xs"></i>
            <span>Add Customer</span>
          </button>
          <?php endif; ?>
        </div>
      </header>

      <!-- Main scrollable area -->
      <main class="flex-1 overflow-y-auto p-5 space-y-5">

        <!-- Filters Card -->
        <div id="filtersSection" class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card p-4" style="display: none;">
          <div class="flex items-center justify-between mb-3">
            <div class="flex items-center gap-2">
              <i class="fas fa-filter text-brand-500 text-xs"></i>
              <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-200">Filters</h3>
            </div>
            <button class="text-xs font-medium text-slate-400 hover:text-rose-500 dark:hover:text-rose-400 transition-colors" onclick="clearFilters()">
              <i class="fas fa-times-circle mr-1"></i> Clear All
            </button>
          </div>
          <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <div>
              <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1"><i class="fas fa-toggle-on mr-1"></i> Status</label>
              <select id="filterStatus" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-700 dark:text-slate-200 focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none transition-colors">
                <option value="">All Status</option>
                <option value="Active">Active</option>
                <option value="Inactive">Inactive</option>
              </select>
            </div>
            <div>
              <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1"><i class="fas fa-map-marker-alt mr-1"></i> Location</label>
              <select id="filterLocation" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-700 dark:text-slate-200 focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none transition-colors">
                <option value="">All Locations</option>
              </select>
            </div>
            <div>
              <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1"><i class="fas fa-file-contract mr-1"></i> Contract Type</label>
              <select id="filterContractType" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-700 dark:text-slate-200 focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none transition-colors">
                <option value="">All Contract Types</option>
              </select>
            </div>
          </div>
        </div>

        <!-- DataTable Card -->
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card overflow-hidden">

          <!-- Loading Skeleton -->
          <div id="loadingSkeleton" class="p-5 space-y-3">
            <div class="flex gap-3">
              <div class="skeleton h-4 rounded" style="flex:1"></div>
              <div class="skeleton h-4 rounded" style="flex:2"></div>
              <div class="skeleton h-4 rounded" style="flex:1"></div>
              <div class="skeleton h-4 rounded" style="flex:1"></div>
              <div class="skeleton h-4 rounded" style="flex:1"></div>
              <div class="skeleton h-4 rounded" style="flex:1"></div>
            </div>
            <?php for ($i = 0; $i < 6; $i++): ?>
            <div class="flex gap-3">
              <div class="skeleton h-4 rounded" style="flex:1"></div>
              <div class="skeleton h-4 rounded" style="flex:2"></div>
              <div class="skeleton h-4 rounded" style="flex:1"></div>
              <div class="skeleton h-4 rounded" style="flex:1"></div>
              <div class="skeleton h-4 rounded" style="flex:1"></div>
              <div class="skeleton h-4 rounded" style="flex:1"></div>
            </div>
            <?php endfor; ?>
          </div>

          <!-- DataTable -->
          <div id="tableContainer" style="display: none;">
            <div class="px-4 py-2 text-xs text-slate-400 dark:text-slate-500 flex items-center gap-1 sm:hidden">
              <i class="fas fa-arrows-alt-h"></i> Swipe left/right to see all columns
            </div>
            <div class="overflow-x-auto" style="-webkit-overflow-scrolling: touch;">
              <table id="customersTable" class="display" style="width:100%"></table>
            </div>
          </div>
        </div>

      </main>
    </div>
  </div>

  <?php if ($canCreate || $canUpdate): ?>
  <!-- Customer Modal -->
  <div class="modal-overlay" id="customerModal">
    <div class="modal-dialog" onclick="event.stopPropagation()">
      <div class="flex items-center justify-between px-6 py-4 border-b border-slate-200 dark:border-slate-700">
        <h3 id="modalTitle" class="text-base font-bold text-slate-800 dark:text-white flex items-center gap-2">
          <i class="fas fa-user-plus text-brand-500"></i> Add Customer
        </h3>
        <button class="w-8 h-8 rounded-lg flex items-center justify-center text-slate-400 hover:text-slate-600 hover:bg-slate-100 dark:hover:text-slate-300 dark:hover:bg-slate-700 transition-colors" onclick="closeModal()">
          <i class="fas fa-times text-sm"></i>
        </button>
      </div>
      <div class="px-6 py-5">
        <form id="customerForm">
          <input type="hidden" id="customerId" name="customer_id">

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1"><i class="fas fa-user mr-1"></i> Customer Name *</label>
              <input type="text" id="customerName" name="customer_name" required maxlength="200" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-700 dark:text-slate-200 focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none transition-colors">
            </div>

            <div>
              <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1"><i class="fas fa-address-card mr-1"></i> Contact Person</label>
              <input type="text" id="contactPerson" name="contact_person" maxlength="150" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-700 dark:text-slate-200 focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none transition-colors">
            </div>

            <div>
              <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1"><i class="fas fa-phone mr-1"></i> Phone</label>
              <input type="tel" id="phone" name="phone" maxlength="20" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-700 dark:text-slate-200 focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none transition-colors">
            </div>

            <div>
              <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1"><i class="fas fa-phone-flip mr-1"></i> Phone 2</label>
              <input type="tel" id="phone2" name="phone2" maxlength="20" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-700 dark:text-slate-200 focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none transition-colors">
            </div>

            <div>
              <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1"><i class="fas fa-envelope mr-1"></i> Email</label>
              <input type="email" id="email" name="email" maxlength="200" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-700 dark:text-slate-200 focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none transition-colors">
            </div>

            <div>
              <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1"><i class="fas fa-map-marker-alt mr-1"></i> Location</label>
              <select id="locationSelect" name="location_id" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-700 dark:text-slate-200 focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none transition-colors">
                <option value="">Select Location</option>
              </select>
            </div>

            <div class="sm:col-span-2">
              <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1"><i class="fas fa-map-pin mr-1"></i> GPS Coordinates <span class="text-slate-400 dark:text-slate-500">(optional)</span></label>
              <div class="flex gap-2">
                <input type="text" id="latitude" name="latitude" placeholder="Latitude" inputmode="decimal" class="flex-1 bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-700 dark:text-slate-200 focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none transition-colors">
                <input type="text" id="longitude" name="longitude" placeholder="Longitude" inputmode="decimal" class="flex-1 bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-700 dark:text-slate-200 focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none transition-colors">
                <button type="button" class="px-3 py-2 rounded-lg border border-slate-200 dark:border-slate-600 bg-slate-50 dark:bg-slate-700 text-slate-500 hover:text-brand-500 hover:border-brand-500 transition-colors" onclick="getGPSLocation()" title="Get current location">
                  <i class="fas fa-crosshairs text-sm"></i>
                </button>
              </div>
            </div>

            <div>
              <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1"><i class="fas fa-file-contract mr-1"></i> Contract Type</label>
              <select id="contractTypeSelect" name="contract_type_id" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-700 dark:text-slate-200 focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none transition-colors">
                <option value="">Select Contract Type</option>
              </select>
            </div>

            <div>
              <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1"><i class="fas fa-percent mr-1"></i> Interest Rate %</label>
              <input type="number" id="interestRate" name="interest_rate" step="0.01" min="0" max="100" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-700 dark:text-slate-200 focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none transition-colors">
            </div>

            <div>
              <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1"><i class="fas fa-credit-card mr-1"></i> Payment Terms</label>
              <input type="text" id="paymentTerms" name="payment_terms" maxlength="200" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-700 dark:text-slate-200 focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none transition-colors">
            </div>

            <div>
              <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1"><i class="fas fa-dollar-sign mr-1"></i> Financing Provided</label>
              <input type="number" id="financingProvided" name="financing_provided" step="0.01" min="0" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-700 dark:text-slate-200 focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none transition-colors">
            </div>

            <div class="sm:col-span-2">
              <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1"><i class="fas fa-clipboard-check mr-1"></i> Quality Terms</label>
              <textarea id="qualityTerms" name="quality_terms" maxlength="300" rows="3" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-700 dark:text-slate-200 focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none transition-colors resize-none"></textarea>
            </div>
          </div>

          <div class="flex items-center justify-end gap-3 mt-6 pt-4 border-t border-slate-200 dark:border-slate-700">
            <button type="button" class="px-4 py-2 rounded-lg text-sm font-medium text-slate-600 dark:text-slate-300 border border-slate-200 dark:border-slate-600 hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors" onclick="closeModal()">
              <i class="fas fa-times mr-1"></i> Cancel
            </button>
            <button type="submit" class="px-5 py-2 rounded-lg text-sm font-semibold text-white bg-brand-500 hover:bg-brand-600 shadow-sm transition-colors">
              <i class="fas fa-save mr-1"></i> Save
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Customer Report Modal -->
  <div id="reportModal" class="modal-overlay">
    <div class="modal-dialog modal-dialog-wide" onclick="event.stopPropagation()">
      <div class="flex items-center justify-between px-6 py-4 border-b border-slate-200 dark:border-slate-700">
        <h3 id="reportTitle" class="text-base font-bold text-slate-800 dark:text-white flex items-center gap-2">
          <i class="fas fa-file-alt text-brand-500"></i> Customer Report
        </h3>
        <div class="flex items-center gap-2">
          <button class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold text-white bg-brand-500 hover:bg-brand-600 transition-colors" onclick="printReport()">
            <i class="fas fa-print text-xs"></i> Print
          </button>
          <button class="w-8 h-8 rounded-lg flex items-center justify-center text-slate-400 hover:text-slate-600 hover:bg-slate-100 dark:hover:text-slate-300 dark:hover:bg-slate-700 transition-colors" onclick="closeReportModal()">
            <i class="fas fa-times text-sm"></i>
          </button>
        </div>
      </div>
      <div>
        <!-- Tabs -->
        <div id="reportTabs" class="report-tabs">
          <button class="report-tab active" onclick="switchReportTab('sales', this)"><i class="fas fa-coins"></i> Sales</button>
          <button class="report-tab" onclick="switchReportTab('deliveries', this)"><i class="fas fa-truck-fast"></i> Deliveries</button>
          <button class="report-tab" onclick="switchReportTab('payments', this)"><i class="fas fa-credit-card"></i> Payments</button>
          <button class="report-tab" onclick="switchReportTab('financing', this)"><i class="fas fa-money-bill-transfer"></i> Financing</button>
          <button class="report-tab" onclick="switchReportTab('transactions', this)"><i class="fas fa-scroll"></i> Transactions Log</button>
        </div>
        <!-- Tab content -->
        <div id="reportContent" class="p-5">
          <div class="skeleton" style="height:200px;"></div>
        </div>
      </div>
    </div>
  </div>

  <script>
      let customersTable;
      let isEditMode = false;
      let customersData = [];
      const isReadOnly = <?php echo $isReadOnly ? 'true' : 'false'; ?>;
      const canCreate = <?php echo $canCreate ? 'true' : 'false'; ?>;
      const canUpdate = <?php echo $canUpdate ? 'true' : 'false'; ?>;
      const canDelete = <?php echo $canDelete ? 'true' : 'false'; ?>;

      // grab GPS from browser
      function getGPSLocation() {
          if (!navigator.geolocation) {
              Swal.fire({icon:'warning', title:'Not Supported', text:'Geolocation is not supported by your browser.'});
              return;
          }
          navigator.geolocation.getCurrentPosition(function(pos) {
              document.getElementById('latitude').value = pos.coords.latitude.toFixed(7);
              document.getElementById('longitude').value = pos.coords.longitude.toFixed(7);
              Swal.fire({icon:'success', title:'Location Set', text:'GPS coordinates captured.', timer:1500, showConfirmButton:false});
          }, function(err) {
              Swal.fire({icon:'error', title:'GPS Error', text: err.message});
          });
      }

      $(document).ready(function() {
          loadDropdowns();
          loadCustomers();
      });

      function loadDropdowns() {
          $.ajax({
              url: '?action=getDropdowns',
              method: 'GET',
              dataType: 'json',
              success: function(response) {
                  if (response.success) {
                      // Populate modal selects
                      const locSelect = document.getElementById('locationSelect');
                      const ctSelect = document.getElementById('contractTypeSelect');
                      const filterLoc = document.getElementById('filterLocation');
                      const filterCt = document.getElementById('filterContractType');

                      // Location selects
                      if (locSelect) {
                          locSelect.innerHTML = '<option value="">Select Location</option>';
                          response.locations.forEach(function(loc) {
                              const option = document.createElement('option');
                              option.value = loc.id;
                              option.textContent = loc.name;
                              locSelect.appendChild(option);
                          });
                      }

                      if (filterLoc) {
                          filterLoc.innerHTML = '<option value="">All Locations</option>';
                          response.locations.forEach(function(loc) {
                              const option = document.createElement('option');
                              option.value = loc.name;
                              option.textContent = loc.name;
                              filterLoc.appendChild(option);
                          });
                      }

                      // Contract type selects
                      if (ctSelect) {
                          ctSelect.innerHTML = '<option value="">Select Contract Type</option>';
                          response.contract_types.forEach(function(ct) {
                              const option = document.createElement('option');
                              option.value = ct.id;
                              option.textContent = ct.name;
                              ctSelect.appendChild(option);
                          });
                      }

                      if (filterCt) {
                          filterCt.innerHTML = '<option value="">All Contract Types</option>';
                          response.contract_types.forEach(function(ct) {
                              const option = document.createElement('option');
                              option.value = ct.name;
                              option.textContent = ct.name;
                              filterCt.appendChild(option);
                          });
                      }
                  }
              }
          });
      }

      function loadCustomers() {
          $('#loadingSkeleton').show();
          $('#tableContainer').hide();

          $.ajax({
              url: '?action=getCustomers',
              method: 'GET',
              dataType: 'json',
              success: function(response) {
                  if (response.success) {
                      customersData = response.data;
                      $('#loadingSkeleton').hide();
                      $('#tableContainer').show();
                      $('#filtersSection').show();
                      initializeDataTable(response.data);
                  } else {
                      $('#loadingSkeleton').hide();
                      Swal.fire({
                          icon: 'error',
                          title: 'Error',
                          text: response.message || 'Failed to load customers'
                      });
                  }
              },
              error: function(xhr, status, error) {
                  console.error('AJAX Error:', error);
                  $('#loadingSkeleton').hide();
                  Swal.fire({
                      icon: 'error',
                      title: 'Connection Error',
                      text: 'Could not connect to server. Please check console for details.'
                  });
              }
          });
      }

      function initializeDataTable(data) {
          if (customersTable) {
              customersTable.destroy();
              $('#customersTable').empty();
          }

          const columns = [
              { data: 'customer_id', title: 'ID' },
              { data: 'customer_name', title: 'Name' },
              { data: 'contact_person', title: 'Contact' },
              { data: 'phone', title: 'Phone' },
              {
                  data: 'location_name',
                  title: 'Location',
                  render: function(data, type, row) {
                      var html = data || 'N/A';
                      if (row.latitude && row.longitude) {
                          html += ' <a href="https://www.google.com/maps?q=' + row.latitude + ',' + row.longitude + '" target="_blank" title="View on Map" style="color:#2d9d99;"><i class="fas fa-map-marker-alt"></i></a>';
                      }
                      return html;
                  }
              },
              { data: 'contract_type_name', title: 'Contract Type' },
              {
                  data: 'interest_rate',
                  title: 'Interest %',
                  render: function(data) {
                      return (data !== null && data !== '' && data !== undefined) ? parseFloat(data).toFixed(2) + '%' : 'N/A';
                  }
              },
              {
                  data: 'account_balance',
                  title: 'Account Bal.',
                  render: function(data) {
                      var val = parseFloat(data) || 0;
                      var formatted = Math.abs(val).toLocaleString('en-US', {maximumFractionDigits: 0});
                      if (val > 0.01) {
                          // positive = we owe them product/refund (advance credit) = RED, arrow OUT (money/goods leaving us)
                          return '<span style="color:#e11d48;font-weight:600;" title="We owe — customer has advance credit"><i class="fas fa-caret-up" style="font-size:13px;margin-right:4px;"></i>+ ' + formatted + ' F</span>';
                      } else if (val < -0.01) {
                          // negative = they owe us money (receivable) = BLUE, arrow IN (money owed to us)
                          return '<span style="color:#2d9d99;font-weight:600;" title="Receivable — customer owes us"><i class="fas fa-caret-down" style="font-size:13px;margin-right:4px;"></i>- ' + formatted + ' F</span>';
                      } else {
                          return '<span style="color:#16a34a;font-weight:600;" title="Settled"><i class="fas fa-check" style="font-size:11px;margin-right:4px;"></i>0.00</span>';
                      }
                  }
              },
              {
                  data: 'status',
                  title: 'Status',
                  render: function(data) {
                      return data === 'Active'
                          ? '<span class="status-badge status-active"><i class="fas fa-check-circle"></i> Active</span>'
                          : '<span class="status-badge status-inactive"><i class="fas fa-times-circle"></i> Inactive</span>';
                  }
              }
          ];

          // Add actions column (always visible for report button)
          columns.push({
              data: null,
              title: 'Actions',
              orderable: false,
              render: function(data, type, row) {
                  let actions = '';

                  actions += `<button class="action-icon" onclick="showCustomerReport('${row.customer_id}', '${(row.customer_name || '').replace(/'/g, "\\'")}')" title="Report" style="color:#2d9d99"><i class="fas fa-file-alt"></i></button> `;

                  if (canUpdate) {
                      actions += `<button class="action-icon" onclick='editCustomer(${JSON.stringify(row)})' title="Edit" style="color:#2d9d99">
                          <i class="fas fa-edit"></i>
                      </button>`;

                      const toggleIcon = row.status === 'Active' ? 'fa-toggle-on' : 'fa-toggle-off';
                      const toggleColor = row.status === 'Active' ? 'style="color:#16a34a"' : 'style="color:#e11d48"';
                      const toggleTitle = row.status === 'Active' ? 'Deactivate' : 'Activate';

                      actions += `<button class="action-icon" onclick="toggleStatus('${row.customer_id}')" title="${toggleTitle}" ${toggleColor}>
                          <i class="fas ${toggleIcon}"></i>
                      </button>`;
                  }

                  if (canDelete) {
                      actions += `<button class="action-icon" onclick="deleteCustomer('${row.customer_id}')" title="Delete" style="color:#e11d48">
                          <i class="fas fa-trash"></i>
                      </button>`;
                  }

                  return actions;
              }
          });

          setTimeout(() => {
              customersTable = $('#customersTable').DataTable({
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
                          exportOptions: { columns: (canUpdate || canDelete) ? ':not(:last-child)' : ':visible' }
                      },
                      {
                          extend: 'pdf',
                          text: '<i class="fas fa-file-pdf"></i> PDF',
                          exportOptions: { columns: (canUpdate || canDelete) ? ':not(:last-child)' : ':visible' }
                      },
                      {
                          extend: 'print',
                          text: '<i class="fas fa-print"></i> Print',
                          exportOptions: { columns: (canUpdate || canDelete) ? ':not(:last-child)' : ':visible' }
                      }
                  ],
                  order: [[0, 'desc']]
              });

              // Apply filters on change
              $('#filterStatus, #filterLocation, #filterContractType').on('change', function() {
                  applyFilters();
              });
          }, 100);
      }

      function applyFilters() {
          if (!customersTable) return;

          $.fn.dataTable.ext.search = [];

          const status = document.getElementById('filterStatus').value;
          const location = document.getElementById('filterLocation').value;
          const contractType = document.getElementById('filterContractType').value;

          // Status filter
          if (status) {
              $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
                  const rowStatus = customersData[dataIndex]?.status;
                  return rowStatus === status;
              });
          }

          // Location filter (column index 4)
          if (location) {
              customersTable.column(4).search('^' + location.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '$', true, false);
          } else {
              customersTable.column(4).search('');
          }

          // Contract type filter (column index 5)
          if (contractType) {
              customersTable.column(5).search('^' + contractType.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '$', true, false);
          } else {
              customersTable.column(5).search('');
          }

          customersTable.draw();
      }

      function clearFilters() {
          document.getElementById('filterStatus').value = '';
          document.getElementById('filterLocation').value = '';
          document.getElementById('filterContractType').value = '';

          if (customersTable) {
              $.fn.dataTable.ext.search = [];
              customersTable.columns().search('').draw();
          }
      }

      <?php if ($canCreate || $canUpdate): ?>
      function openAddModal() {
          isEditMode = false;
          document.getElementById('modalTitle').innerHTML = '<i class="fas fa-user-plus text-brand-500"></i> Add Customer';
          document.getElementById('customerForm').reset();
          document.getElementById('customerId').value = '';
          document.getElementById('customerModal').classList.add('active');
      }

      function editCustomer(row) {
          if (!canUpdate) return;
          isEditMode = true;
          document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit text-brand-500"></i> Edit Customer';
          document.getElementById('customerId').value = row.customer_id;
          document.getElementById('customerName').value = row.customer_name;
          document.getElementById('contactPerson').value = row.contact_person || '';
          document.getElementById('phone').value = row.phone || '';
          document.getElementById('phone2').value = row.phone2 || '';
          document.getElementById('email').value = row.email || '';
          document.getElementById('locationSelect').value = row.location_id || '';
          document.getElementById('latitude').value = row.latitude || '';
          document.getElementById('longitude').value = row.longitude || '';
          document.getElementById('contractTypeSelect').value = row.contract_type_id || '';
          document.getElementById('interestRate').value = row.interest_rate || '';
          document.getElementById('paymentTerms').value = row.payment_terms || '';
          document.getElementById('qualityTerms').value = row.quality_terms || '';
          document.getElementById('financingProvided').value = row.financing_provided || '';
          document.getElementById('customerModal').classList.add('active');
      }

      function closeModal() {
          document.getElementById('customerModal').classList.remove('active');
          document.getElementById('customerForm').reset();
      }

      document.getElementById('customerModal').addEventListener('click', function(e) {
          if (e.target === this) closeModal();
      });

      document.getElementById('customerForm').addEventListener('submit', function(e) {
          e.preventDefault();

          const formData = new FormData(this);
          const action = isEditMode ? 'updateCustomer' : 'addCustomer';

          Swal.fire({
              title: 'Processing...',
              allowOutsideClick: false,
              didOpen: () => { Swal.showLoading(); }
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
                      Swal.fire({
                          icon: 'success',
                          title: 'Success!',
                          text: response.message,
                          timer: 2000,
                          showConfirmButton: false
                      });
                      closeModal();
                      setTimeout(() => loadCustomers(), 100);
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

      <?php if ($canUpdate): ?>
      function toggleStatus(customerId) {
          Swal.fire({
              icon: 'question',
              title: 'Toggle Customer Status?',
              text: 'This will activate or deactivate the customer',
              showCancelButton: true,
              confirmButtonText: 'Yes, proceed',
              cancelButtonText: 'Cancel'
          }).then((result) => {
              if (result.isConfirmed) {
                  const formData = new FormData();
                  formData.append('customer_id', customerId);

                  $.ajax({
                      url: '?action=toggleStatus',
                      method: 'POST',
                      data: formData,
                      processData: false,
                      contentType: false,
                      dataType: 'json',
                      success: function(response) {
                          if (response.success) {
                              Swal.fire({ icon: 'success', text: response.message, timer: 2000, showConfirmButton: false });
                              setTimeout(() => loadCustomers(), 100);
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

      <?php if ($canDelete): ?>
      function deleteCustomer(customerId) {
          Swal.fire({
              icon: 'warning',
              title: 'Delete Customer?',
              text: 'This action cannot be undone!',
              showCancelButton: true,
              confirmButtonColor: '#e11d48',
              confirmButtonText: 'Yes, delete it',
              cancelButtonText: 'Cancel'
          }).then((result) => {
              if (result.isConfirmed) {
                  const formData = new FormData();
                  formData.append('customer_id', customerId);

                  $.ajax({
                      url: '?action=deleteCustomer',
                      method: 'POST',
                      data: formData,
                      processData: false,
                      contentType: false,
                      dataType: 'json',
                      success: function(response) {
                          if (response.success) {
                              Swal.fire({ icon: 'success', text: response.message, timer: 2000, showConfirmButton: false });
                              setTimeout(() => loadCustomers(), 100);
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

      // ===== Customer Report Functions =====
      var reportData = null;

      function showCustomerReport(customerId, customerName) {
          document.getElementById('reportTitle').innerHTML = '<i class="fas fa-file-alt text-brand-500"></i> ' + customerName + ' — Report';
          document.getElementById('reportContent').innerHTML = '<div class="skeleton" style="height:200px;"></div>';
          document.getElementById('reportModal').classList.add('active');

          // Reset to first tab
          document.querySelectorAll('.report-tab').forEach(t => t.classList.remove('active'));
          document.querySelector('.report-tab').classList.add('active');

          $.getJSON('?action=getCustomerReport&customer_id=' + encodeURIComponent(customerId), function(r) {
              if (!r.success) {
                  document.getElementById('reportContent').innerHTML = '<p style="color:#e11d48;padding:20px;">' + (r.message || 'Failed to load') + '</p>';
                  return;
              }
              reportData = r.data;
              renderReportTab('sales');
          }).fail(function() {
              document.getElementById('reportContent').innerHTML = '<p style="color:#e11d48;padding:20px;">Connection error</p>';
          });
      }

      function closeReportModal() {
          document.getElementById('reportModal').classList.remove('active');
          reportData = null;
      }

      document.getElementById('reportModal').addEventListener('click', function(e) {
          if (e.target === this) closeReportModal();
      });

      function switchReportTab(tab, btn) {
          document.querySelectorAll('.report-tab').forEach(t => t.classList.remove('active'));
          btn.classList.add('active');
          renderReportTab(tab);
      }

      function fmtR(n) { return Number(n || 0).toLocaleString(); }
      function fmtDate(d) { if (!d) return '-'; var p = d.split('-'); return p.length === 3 ? p[2] + '/' + p[1] + '/' + p[0] : d; }

      function renderReportTab(tab) {
          var html = '';
          var d = reportData;
          if (!d) return;

          if (tab === 'sales') {
              var totalRev = 0, totalProfit = 0, totalKg = 0;
              d.sales.forEach(function(s) { totalRev += parseFloat(s.gross_sale_amount || 0); totalProfit += parseFloat(s.net_profit || 0); totalKg += parseFloat(s.net_weight_kg || 0); });

              html += '<div class="report-summary">';
              html += '<div class="report-summary-card"><div class="val">' + d.sales.length + '</div><div class="lbl">Total Sales</div></div>';
              html += '<div class="report-summary-card"><div class="val">' + fmtR(totalRev) + ' F</div><div class="lbl">Revenue</div></div>';
              html += '<div class="report-summary-card"><div class="val">' + fmtR(totalProfit) + ' F</div><div class="lbl">Net Profit</div></div>';
              html += '<div class="report-summary-card"><div class="val">' + fmtR(totalKg) + ' kg</div><div class="lbl">Volume</div></div>';
              html += '</div>';

              html += '<table class="report-table"><thead><tr><th>Sale ID</th><th>Date</th><th>Weight (kg)</th><th>Price/kg</th><th>Amount</th><th>Profit</th><th>Status</th></tr></thead><tbody>';
              if (d.sales.length === 0) html += '<tr><td colspan="7" style="text-align:center;padding:20px;color:#94a3b8;">No sales found</td></tr>';
              d.sales.forEach(function(s) {
                  var cls = s.sale_status === 'Confirmed' ? 'status-active' : s.sale_status === 'Cancelled' ? 'status-inactive' : '';
                  html += '<tr><td>' + s.sale_id + '</td><td>' + fmtDate(s.unloading_date) + '</td><td>' + fmtR(s.net_weight_kg) + '</td><td>' + fmtR(s.selling_price_per_kg) + '</td><td>' + fmtR(s.gross_sale_amount) + ' F</td><td>' + fmtR(s.net_profit) + ' F</td><td><span class="status-badge ' + cls + '">' + s.sale_status + '</span></td></tr>';
              });
              html += '</tbody></table>';
          }

          if (tab === 'deliveries') {
              var totalDel = d.deliveries.length, totalDelKg = 0;
              d.deliveries.forEach(function(dl) { totalDelKg += parseFloat(dl.weight_kg || 0); });

              html += '<div class="report-summary">';
              html += '<div class="report-summary-card"><div class="val">' + totalDel + '</div><div class="lbl">Total Deliveries</div></div>';
              html += '<div class="report-summary-card"><div class="val">' + fmtR(totalDelKg) + ' kg</div><div class="lbl">Total Weight</div></div>';
              html += '</div>';

              html += '<table class="report-table"><thead><tr><th>Delivery ID</th><th>Date</th><th>Weight (kg)</th><th>Bags</th><th>Warehouse</th><th>Status</th></tr></thead><tbody>';
              if (d.deliveries.length === 0) html += '<tr><td colspan="6" style="text-align:center;padding:20px;color:#94a3b8;">No deliveries found</td></tr>';
              d.deliveries.forEach(function(dl) {
                  html += '<tr><td>' + dl.delivery_id + '</td><td>' + fmtDate(dl.date) + '</td><td>' + fmtR(dl.weight_kg) + '</td><td>' + fmtR(dl.num_bags) + '</td><td>' + (dl.warehouse_name || '-') + '</td><td><span class="status-badge">' + dl.status + '</span></td></tr>';
              });
              html += '</tbody></table>';
          }

          if (tab === 'payments') {
              var totalIn = 0, totalOut = 0;
              d.payments.forEach(function(p) { if (p.direction === 'Incoming') totalIn += parseFloat(p.amount); else totalOut += parseFloat(p.amount); });

              html += '<div class="report-summary">';
              html += '<div class="report-summary-card"><div class="val">' + d.payments.length + '</div><div class="lbl">Total Payments</div></div>';
              html += '<div class="report-summary-card"><div class="val" style="color:#16a34a;">' + fmtR(totalIn) + ' F</div><div class="lbl">Incoming</div></div>';
              html += '<div class="report-summary-card"><div class="val" style="color:#e11d48;">' + fmtR(totalOut) + ' F</div><div class="lbl">Outgoing</div></div>';
              html += '</div>';

              html += '<table class="report-table"><thead><tr><th>Payment ID</th><th>Date</th><th>Direction</th><th>Type</th><th>Amount</th><th>Mode</th><th>Reference</th></tr></thead><tbody>';
              if (d.payments.length === 0) html += '<tr><td colspan="7" style="text-align:center;padding:20px;color:#94a3b8;">No payments found</td></tr>';
              d.payments.forEach(function(p) {
                  var dirCls = p.direction === 'Incoming' ? 'color:#16a34a;' : 'color:#e11d48;';
                  html += '<tr><td>' + p.payment_id + '</td><td>' + fmtDate(p.date) + '</td><td style="' + dirCls + 'font-weight:600;">' + p.direction + '</td><td>' + p.payment_type + '</td><td>' + fmtR(p.amount) + ' F</td><td>' + (p.payment_mode || '-') + '</td><td>' + (p.reference_number || '-') + '</td></tr>';
              });
              html += '</tbody></table>';
          }

          if (tab === 'financing') {
              var totalFin = 0, totalBal = 0;
              d.financing.forEach(function(f) { totalFin += parseFloat(f.amount); totalBal += parseFloat(f.balance_due || 0); });

              html += '<div class="report-summary">';
              html += '<div class="report-summary-card"><div class="val">' + d.financing.length + '</div><div class="lbl">Agreements</div></div>';
              html += '<div class="report-summary-card"><div class="val">' + fmtR(totalFin) + ' F</div><div class="lbl">Total Amount</div></div>';
              html += '<div class="report-summary-card"><div class="val" style="color:#f59e0b;">' + fmtR(totalBal) + ' F</div><div class="lbl">Balance Due</div></div>';
              html += '</div>';

              html += '<table class="report-table"><thead><tr><th>Financing ID</th><th>Date</th><th>Direction</th><th>Amount</th><th>Repaid</th><th>Balance</th><th>Status</th></tr></thead><tbody>';
              if (d.financing.length === 0) html += '<tr><td colspan="7" style="text-align:center;padding:20px;color:#94a3b8;">No financing found</td></tr>';
              d.financing.forEach(function(f) {
                  html += '<tr><td>' + f.financing_id + '</td><td>' + fmtDate(f.date) + '</td><td>' + f.direction + '</td><td>' + fmtR(f.amount) + ' F</td><td>' + fmtR(f.amount_repaid) + ' F</td><td>' + fmtR(f.balance_due) + ' F</td><td><span class="status-badge">' + f.status + '</span></td></tr>';
              });
              html += '</tbody></table>';
          }

          if (tab === 'transactions') {
              var txns = d.transactions_log || [];
              var totalPay = 0, totalSale = 0;
              txns.forEach(function(t) {
                  var pay = parseFloat(t.payment_amt) || 0;
                  if (pay > 0) totalPay += pay;
                  totalSale += parseFloat(t.sale_amt) || 0;
              });
              var netBal = txns.length ? parseFloat(txns[txns.length - 1].new_balance) || 0 : 0;
              var balColor = netBal > 0.01 ? '#e11d48' : (netBal < -0.01 ? '#2d9d99' : '#16a34a');
              var balLabel = netBal > 0.01 ? 'We Owe Customer' : (netBal < -0.01 ? 'Customer Owes Us' : 'Settled');
              var balIcon = netBal > 0.01 ? '<i class="fas fa-caret-up" style="font-size:14px;margin-right:4px;"></i>' : (netBal < -0.01 ? '<i class="fas fa-caret-down" style="font-size:14px;margin-right:4px;"></i>' : '<i class="fas fa-check-circle" style="font-size:12px;margin-right:4px;"></i>');

              html += '<div class="report-summary">';
              html += '<div class="report-summary-card"><div class="val">' + txns.length + '</div><div class="lbl">Transactions</div></div>';
              html += '<div class="report-summary-card"><div class="val" style="color:#16a34a;">' + fmtR(totalPay) + ' F</div><div class="lbl">Total Received</div></div>';
              html += '<div class="report-summary-card"><div class="val" style="color:#e11d48;">' + fmtR(totalSale) + ' F</div><div class="lbl">Total Sales Value</div></div>';
              html += '<div class="report-summary-card" style="border:2px solid ' + balColor + ';"><div class="val" style="color:' + balColor + ';font-weight:700;">' + balIcon + fmtR(Math.abs(netBal)) + ' F</div><div class="lbl" style="color:' + balColor + ';font-weight:600;">' + balLabel + '</div></div>';
              html += '</div>';

              if (txns.length === 0) {
                  html += '<div style="text-align:center;padding:30px;color:#94a3b8;"><i class="fas fa-inbox" style="font-size:32px;"></i><p style="margin-top:10px;">No transactions found.</p></div>';
              } else {
                  html += '<div style="overflow-x:auto;"><table class="report-table" style="font-size:11px;"><thead><tr>';
                  html += '<th>#</th><th>Date</th><th>Description</th><th style="text-align:right;">Prev. Balance</th><th style="text-align:right;">Weight (kg)</th><th style="text-align:right;">Price/Kg</th><th style="text-align:right;">Sale Amt</th><th style="text-align:right;">Payment Amt</th><th style="text-align:right;">New Balance</th><th>Mode</th>';
                  html += '</tr></thead><tbody>';
                  txns.forEach(function(t, i) {
                      var pb = parseFloat(t.prev_balance) || 0;
                      var nb = parseFloat(t.new_balance) || 0;
                      var pbC = pb > 0.01 ? '#e11d48' : (pb < -0.01 ? '#2d9d99' : '#64748b');
                      var nbC = nb > 0.01 ? '#e11d48' : (nb < -0.01 ? '#2d9d99' : '#16a34a');

                      var dC = '';
                      switch(t.desc) {
                          case 'Payment Received': dC = '#16a34a'; break;
                          case 'Financing Advance': dC = '#2d9d99'; break;
                          case 'Sale / Delivery': dC = '#f59e0b'; break;
                          default: dC = '#64748b';
                      }

                      html += '<tr>';
                      html += '<td>' + (i + 1) + '</td>';
                      html += '<td style="white-space:nowrap;">' + fmtDate(t.date) + '</td>';
                      html += '<td style="font-weight:600;color:' + dC + ';white-space:nowrap;">' + t.desc + '</td>';
                      html += '<td style="text-align:right;color:' + pbC + ';">' + fmtR(pb) + ' F</td>';
                      html += '<td style="text-align:right;">' + (t.weight_kg ? fmtR(t.weight_kg) : '') + '</td>';
                      html += '<td style="text-align:right;">' + (t.price_per_kg ? fmtR(t.price_per_kg) : '') + '</td>';

                      // sale amt: shown in parentheses (reduces balance)
                      html += '<td style="text-align:right;color:#e11d48;font-weight:600;">' + (t.sale_amt ? '(' + fmtR(t.sale_amt) + ') F' : '') + '</td>';

                      // payment amt: positive = green
                      var pay = parseFloat(t.payment_amt) || 0;
                      var payHtml = '';
                      if (pay > 0.01) payHtml = '<span style="color:#16a34a;font-weight:600;">' + fmtR(pay) + ' F</span>';
                      html += '<td style="text-align:right;">' + payHtml + '</td>';

                      html += '<td style="text-align:right;font-weight:700;color:' + nbC + ';">' + (nb < -0.01 ? '(' + fmtR(Math.abs(nb)) + ')' : fmtR(nb)) + ' F</td>';
                      html += '<td style="font-size:10px;white-space:nowrap;">' + (t.payment_mode || '') + '</td>';
                      html += '</tr>';
                  });
                  html += '</tbody></table></div>';

                  html += '<div style="margin-top:12px;padding:10px 14px;background:#f8fafc;border-radius:6px;border-left:3px solid #2d9d99;font-size:12px;color:#64748b;">';
                  html += '<i class="fas fa-info-circle" style="margin-right:6px;color:#2d9d99;"></i>';
                  html += '<strong>Balance</strong> = Payments received minus sales value. Positive = we owe customer. Negative (parentheses) = customer owes us.';
                  html += '</div>';
              }
          }

          document.getElementById('reportContent').innerHTML = html;
      }

      function printReport() {
          var content = document.getElementById('reportContent').innerHTML;
          var title = document.getElementById('reportTitle').textContent;
          var printWin = window.open('', '_blank', 'width=900,height=700');
          printWin.document.write('<!DOCTYPE html><html><head><meta charset="UTF-8"><title>' + title + '</title><style>');
          printWin.document.write('body { font-family: Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif; font-size: 12px; color: #333; padding: 20px; }');
          printWin.document.write('h2 { color: #1e293b; margin-bottom: 16px; font-size: 18px; }');
          printWin.document.write('.report-summary { display: flex; gap: 12px; margin-bottom: 16px; }');
          printWin.document.write('.report-summary-card { background: #f8fafc; border-radius: 6px; padding: 12px; text-align: center; flex: 1; }');
          printWin.document.write('.report-summary-card .val { font-size: 18px; font-weight: 700; color: #2d9d99; }');
          printWin.document.write('.report-summary-card .lbl { font-size: 10px; color: #64748b; text-transform: uppercase; }');
          printWin.document.write('.report-table { width: 100%; border-collapse: collapse; }');
          printWin.document.write('.report-table th { background: #1e293b; color: white; padding: 6px 8px; font-size: 10px; text-align: left; }');
          printWin.document.write('.report-table td { padding: 6px 8px; border-bottom: 1px solid #f1f5f9; font-size: 11px; }');
          printWin.document.write('.status-badge { padding: 2px 8px; border-radius: 4px; font-size: 10px; font-weight: 600; }');
          printWin.document.write('@media print { body { padding: 0; } }');
          printWin.document.write('</style></head><body>');
          printWin.document.write('<h2>' + title + '</h2>');
          printWin.document.write(content);
          printWin.document.write('</body></html>');
          printWin.document.close();
          printWin.onload = function() { printWin.focus(); printWin.print(); };
      }
  </script>

  <!-- Theme initialization -->
  <script>
  (function() {
    var _store = localStorage;
    var html = document.documentElement;
    var dark = _store.getItem('cp_theme') === 'dark' || (_store.getItem('cp_theme') === null && window.matchMedia('(prefers-color-scheme: dark)').matches);
    html.classList.toggle('dark', dark);
  })();
  </script>

  <!-- i18n loader stub (sidebar language/theme toggles) -->
  <script>
  (function() {
    var _store = localStorage;
    var currentLang = _store.getItem('cp_lang') || 'en';
    document.documentElement.lang = currentLang;

    var themeBtn = document.getElementById('themeToggleBtn');
    if (themeBtn) {
      themeBtn.addEventListener('click', function() {
        var html = document.documentElement;
        var isDark = html.classList.contains('dark');
        html.classList.toggle('dark', !isDark);
        _store.setItem('cp_theme', !isDark ? 'dark' : 'light');
        var icon = document.getElementById('themeIcon');
        if (icon) icon.className = !isDark ? 'fas fa-sun w-4 text-sm' : 'fas fa-moon w-4 text-sm';
      });
    }

    var langBtn = document.getElementById('langToggleBtn');
    if (langBtn) {
      langBtn.addEventListener('click', function() {
        currentLang = (currentLang === 'en') ? 'fr' : 'en';
        _store.setItem('cp_lang', currentLang);
        document.documentElement.lang = currentLang;
      });
    }

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

    /* Mobile sidebar */
    var mobileBtn = document.getElementById('mobileSidebarBtn');
    var sidebar = document.getElementById('sidebar');
    if (mobileBtn && sidebar) {
      mobileBtn.addEventListener('click', function() {
        sidebar.classList.toggle('open');
      });
    }
  })();
  </script>

</body>
</html>
