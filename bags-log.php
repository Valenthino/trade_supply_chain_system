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
$current_page = 'bags-log';

// RBAC
$allowedRoles = ['Admin', 'Manager', 'Warehouse Clerk', 'Fleet Manager', 'Procurement Officer'];
if (!in_array($role, $allowedRoles)) {
    header("Location: dashboard.php");
    exit();
}

$canCreate = in_array($role, ['Admin', 'Manager', 'Warehouse Clerk', 'Fleet Manager', 'Procurement Officer']);
$canUpdate = in_array($role, ['Admin', 'Manager', 'Warehouse Clerk', 'Procurement Officer']);
$canDelete = ($role === 'Admin');

// Handle AJAX requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    try {
        switch ($_GET['action']) {
            case 'getBagsLog':
                $conn = getDBConnection();
                $stmt = $conn->prepare("SELECT bl.*, c.customer_name, s.first_name as supplier_name, bt.bag_type_name, fv.vehicle_registration
                    FROM bags_log bl
                    LEFT JOIN customers c ON bl.customer_id = c.customer_id
                    LEFT JOIN suppliers s ON bl.supplier_id = s.supplier_id
                    LEFT JOIN settings_bag_types bt ON bl.bag_type_id = bt.bag_type_id
                    LEFT JOIN fleet_vehicles fv ON bl.truck_id = fv.vehicle_id
                    ORDER BY bl.bag_log_id DESC");
                $stmt->execute();
                $result = $stmt->get_result();

                $entries = [];
                while ($row = $result->fetch_assoc()) {
                    $entries[] = [
                        'bag_log_id' => $row['bag_log_id'],
                        'date' => date('M d, Y', strtotime($row['date'])),
                        'date_raw' => $row['date'],
                        'customer_id' => $row['customer_id'],
                        'customer_name' => $row['customer_name'] ?? '',
                        'supplier_id' => $row['supplier_id'] ?? null,
                        'supplier_name' => $row['supplier_name'] ?? '',
                        'bag_type_id' => $row['bag_type_id'],
                        'bag_type_name' => $row['bag_type_name'] ?? '',
                        'description' => $row['description'],
                        'previous_balance' => $row['previous_balance'],
                        'qty_in' => $row['qty_in'],
                        'ref_number' => $row['ref_number'],
                        'qty_out' => $row['qty_out'],
                        'balance' => $row['balance'],
                        'truck_id' => $row['truck_id'],
                        'vehicle_registration' => $row['vehicle_registration'] ?? '',
                        'driver_name' => $row['driver_name'],
                        'season' => $row['season'],
                        'created_at' => $row['created_at']
                    ];
                }

                $stmt->close();
                $conn->close();

                echo json_encode(['success' => true, 'data' => $entries]);
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

                // Active bag types
                $bagTypes = [];
                $stmt = $conn->prepare("SELECT bag_type_id, bag_type_name FROM settings_bag_types WHERE is_active = 1 ORDER BY bag_type_name ASC");
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $bagTypes[] = $row;
                }
                $stmt->close();

                // Available vehicles
                $vehicles = [];
                $stmt = $conn->prepare("SELECT vehicle_id, vehicle_registration, driver_name FROM fleet_vehicles WHERE status = 'Available' ORDER BY vehicle_id ASC");
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $vehicles[] = $row;
                }
                $stmt->close();

                $conn->close();

                echo json_encode([
                    'success' => true,
                    'data' => [
                        'customers' => $customers,
                        'suppliers' => $suppliers,
                        'bagTypes' => $bagTypes,
                        'vehicles' => $vehicles
                    ]
                ]);
                exit();

            case 'addBagLog':
                if (!$canCreate) {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $date = isset($_POST['date']) ? trim($_POST['date']) : '';
                $customerId = !empty($_POST['customer_id']) ? trim($_POST['customer_id']) : null;
                $supplierId = !empty($_POST['supplier_id']) ? trim($_POST['supplier_id']) : null;
                $bagTypeId = !empty($_POST['bag_type_id']) ? intval($_POST['bag_type_id']) : null;
                $description = !empty($_POST['description']) ? trim($_POST['description']) : null;
                $qtyIn = isset($_POST['qty_in']) ? intval($_POST['qty_in']) : 0;
                $refNumber = !empty($_POST['ref_number']) ? trim($_POST['ref_number']) : null;
                $qtyOut = isset($_POST['qty_out']) ? intval($_POST['qty_out']) : 0;
                $truckId = !empty($_POST['truck_id']) ? trim($_POST['truck_id']) : null;
                $driverName = !empty($_POST['driver_name']) ? trim($_POST['driver_name']) : null;
                $season = isset($_POST['season']) ? trim($_POST['season']) : getActiveSeason();

                // Validation
                if (empty($date)) {
                    echo json_encode(['success' => false, 'message' => 'Date is required']);
                    exit();
                }
                if ($qtyIn == 0 && $qtyOut == 0) {
                    echo json_encode(['success' => false, 'message' => 'Qty In or Qty Out must be greater than 0']);
                    exit();
                }

                $conn = getDBConnection();

                // Get previous balance (from the last entry)
                $stmt = $conn->prepare("SELECT balance FROM bags_log ORDER BY bag_log_id DESC LIMIT 1");
                $stmt->execute();
                $res = $stmt->get_result();
                $previousBalance = ($res->num_rows > 0) ? intval($res->fetch_assoc()['balance']) : 0;
                $stmt->close();

                // Compute balance
                $balance = $previousBalance + $qtyIn - $qtyOut;

                // If truck_id is provided, get driver_name from fleet_vehicles
                if ($truckId && empty($driverName)) {
                    $stmt = $conn->prepare("SELECT driver_name FROM fleet_vehicles WHERE vehicle_id = ?");
                    $stmt->bind_param("s", $truckId);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    if ($res->num_rows > 0) {
                        $driverName = $res->fetch_assoc()['driver_name'];
                    }
                    $stmt->close();
                }

                // Get customer_name for logging
                $customerName = '';
                if ($customerId) {
                    $stmt = $conn->prepare("SELECT customer_name FROM customers WHERE customer_id = ?");
                    $stmt->bind_param("s", $customerId);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    if ($res->num_rows > 0) {
                        $customerName = $res->fetch_assoc()['customer_name'];
                    }
                    $stmt->close();
                }

                $stmt = $conn->prepare("INSERT INTO bags_log (date, customer_id, supplier_id, bag_type_id, description, previous_balance, qty_in, ref_number, qty_out, balance, truck_id, driver_name, season) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("sssisiisiisss", $date, $customerId, $supplierId, $bagTypeId, $description, $previousBalance, $qtyIn, $refNumber, $qtyOut, $balance, $truckId, $driverName, $season);

                if ($stmt->execute()) {
                    $newId = $conn->insert_id;
                    logActivity($user_id, $username, 'Bag Log Created', "Created bag log #$newId: In=$qtyIn, Out=$qtyOut, Balance=$balance" . ($customerName ? " for $customerName" : ""));
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => true, 'message' => 'Entry added successfully', 'bag_log_id' => $newId]);
                } else {
                    $error = $stmt->error;
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to add entry: ' . $error]);
                }
                exit();

            case 'updateBagLog':
                if (!$canUpdate) {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $bagLogId = isset($_POST['bag_log_id']) ? intval($_POST['bag_log_id']) : 0;
                $date = isset($_POST['date']) ? trim($_POST['date']) : '';
                $customerId = !empty($_POST['customer_id']) ? trim($_POST['customer_id']) : null;
                $supplierId = !empty($_POST['supplier_id']) ? trim($_POST['supplier_id']) : null;
                $bagTypeId = !empty($_POST['bag_type_id']) ? intval($_POST['bag_type_id']) : null;
                $description = !empty($_POST['description']) ? trim($_POST['description']) : null;
                $qtyIn = isset($_POST['qty_in']) ? intval($_POST['qty_in']) : 0;
                $refNumber = !empty($_POST['ref_number']) ? trim($_POST['ref_number']) : null;
                $qtyOut = isset($_POST['qty_out']) ? intval($_POST['qty_out']) : 0;
                $truckId = !empty($_POST['truck_id']) ? trim($_POST['truck_id']) : null;
                $driverName = !empty($_POST['driver_name']) ? trim($_POST['driver_name']) : null;
                $season = isset($_POST['season']) ? trim($_POST['season']) : getActiveSeason();

                // Validation
                if ($bagLogId <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid entry ID']);
                    exit();
                }
                if (empty($date)) {
                    echo json_encode(['success' => false, 'message' => 'Date is required']);
                    exit();
                }

                $conn = getDBConnection();

                // If truck_id is provided, get driver_name from fleet_vehicles
                if ($truckId && empty($driverName)) {
                    $stmt = $conn->prepare("SELECT driver_name FROM fleet_vehicles WHERE vehicle_id = ?");
                    $stmt->bind_param("s", $truckId);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    if ($res->num_rows > 0) {
                        $driverName = $res->fetch_assoc()['driver_name'];
                    }
                    $stmt->close();
                }

                // Update the entry fields (balance will be recomputed in cascade)
                $stmt = $conn->prepare("UPDATE bags_log SET date = ?, customer_id = ?, supplier_id = ?, bag_type_id = ?, description = ?, qty_in = ?, ref_number = ?, qty_out = ?, truck_id = ?, driver_name = ?, season = ? WHERE bag_log_id = ?");
                $stmt->bind_param("sssisisisssi", $date, $customerId, $supplierId, $bagTypeId, $description, $qtyIn, $refNumber, $qtyOut, $truckId, $driverName, $season, $bagLogId);

                if (!$stmt->execute()) {
                    $error = $stmt->error;
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to update entry: ' . $error]);
                    exit();
                }
                $stmt->close();

                // Recompute balances for all entries from the updated one onward
                $conn->begin_transaction();
                try {
                    // Get previous entry's balance (entry before the updated one)
                    $prevBalance = 0;
                    $prev = $conn->prepare("SELECT balance FROM bags_log WHERE bag_log_id < ? ORDER BY bag_log_id DESC LIMIT 1");
                    $prev->bind_param("i", $bagLogId);
                    $prev->execute();
                    $prevRes = $prev->get_result();
                    if ($prevRes->num_rows > 0) {
                        $prevBalance = intval($prevRes->fetch_assoc()['balance']);
                    }
                    $prev->close();

                    // Get all entries from the updated one onward
                    $stmt = $conn->prepare("SELECT bag_log_id, qty_in, qty_out FROM bags_log WHERE bag_log_id >= ? ORDER BY bag_log_id ASC");
                    $stmt->bind_param("i", $bagLogId);
                    $stmt->execute();
                    $result = $stmt->get_result();

                    $rows = [];
                    while ($row = $result->fetch_assoc()) {
                        $rows[] = $row;
                    }
                    $stmt->close();

                    $updateStmt = $conn->prepare("UPDATE bags_log SET previous_balance = ?, balance = ? WHERE bag_log_id = ?");
                    $runningBalance = $prevBalance;

                    foreach ($rows as $row) {
                        $entryPrevBalance = $runningBalance;
                        $runningBalance = $entryPrevBalance + intval($row['qty_in']) - intval($row['qty_out']);
                        $updateStmt->bind_param("iii", $entryPrevBalance, $runningBalance, $row['bag_log_id']);
                        $updateStmt->execute();
                    }
                    $updateStmt->close();

                    $conn->commit();
                } catch (Exception $e) {
                    $conn->rollback();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to recompute balances: ' . $e->getMessage()]);
                    exit();
                }

                logActivity($user_id, $username, 'Bag Log Updated', "Updated bag log #$bagLogId: In=$qtyIn, Out=$qtyOut");
                $conn->close();
                echo json_encode(['success' => true, 'message' => 'Entry updated successfully']);
                exit();

            case 'deleteBagLog':
                if (!$canDelete) {
                    echo json_encode(['success' => false, 'message' => 'Access denied. Only Admin can delete.']);
                    exit();
                }

                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $bagLogId = isset($_POST['bag_log_id']) ? intval($_POST['bag_log_id']) : 0;
                if ($bagLogId <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid entry ID']);
                    exit();
                }

                $conn = getDBConnection();

                // Get info for logging
                $stmt = $conn->prepare("SELECT qty_in, qty_out, balance FROM bags_log WHERE bag_log_id = ?");
                $stmt->bind_param("i", $bagLogId);
                $stmt->execute();
                $info = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$info) {
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Entry not found']);
                    exit();
                }

                // Delete the entry
                $stmt = $conn->prepare("DELETE FROM bags_log WHERE bag_log_id = ?");
                $stmt->bind_param("i", $bagLogId);

                if (!$stmt->execute()) {
                    $error = $stmt->error;
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to delete entry: ' . $error]);
                    exit();
                }
                $stmt->close();

                // Recompute balances for all remaining entries
                $conn->begin_transaction();
                try {
                    $stmt = $conn->prepare("SELECT bag_log_id, qty_in, qty_out FROM bags_log ORDER BY bag_log_id ASC");
                    $stmt->execute();
                    $result = $stmt->get_result();

                    $rows = [];
                    while ($row = $result->fetch_assoc()) {
                        $rows[] = $row;
                    }
                    $stmt->close();

                    $updateStmt = $conn->prepare("UPDATE bags_log SET previous_balance = ?, balance = ? WHERE bag_log_id = ?");
                    $runningBalance = 0;

                    foreach ($rows as $row) {
                        $entryPrevBalance = $runningBalance;
                        $runningBalance = $entryPrevBalance + intval($row['qty_in']) - intval($row['qty_out']);
                        $updateStmt->bind_param("iii", $entryPrevBalance, $runningBalance, $row['bag_log_id']);
                        $updateStmt->execute();
                    }
                    $updateStmt->close();

                    $conn->commit();
                } catch (Exception $e) {
                    $conn->rollback();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Deleted but failed to recompute balances: ' . $e->getMessage()]);
                    exit();
                }

                logActivity($user_id, $username, 'Bag Log Deleted', "Deleted bag log #$bagLogId: In={$info['qty_in']}, Out={$info['qty_out']}, Balance={$info['balance']}");
                $conn->close();
                echo json_encode(['success' => true, 'message' => 'Entry deleted successfully']);
                exit();

            case 'quickAddBagType':
                if (!$canCreate) {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $bagTypeName = isset($_POST['bag_type_name']) ? trim($_POST['bag_type_name']) : '';
                if (empty($bagTypeName)) {
                    echo json_encode(['success' => false, 'message' => 'Bag type name is required']);
                    exit();
                }

                $conn = getDBConnection();

                // Check uniqueness
                $stmt = $conn->prepare("SELECT bag_type_id FROM settings_bag_types WHERE bag_type_name = ?");
                $stmt->bind_param("s", $bagTypeName);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($res->num_rows > 0) {
                    $existing = $res->fetch_assoc();
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Bag type already exists', 'bag_type_id' => $existing['bag_type_id']]);
                    exit();
                }
                $stmt->close();

                $stmt = $conn->prepare("INSERT INTO settings_bag_types (bag_type_name, is_active) VALUES (?, 1)");
                $stmt->bind_param("s", $bagTypeName);

                if ($stmt->execute()) {
                    $newId = $conn->insert_id;
                    logActivity($user_id, $username, 'Bag Type Created', "Quick-added bag type: $bagTypeName (ID: $newId)");
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => true, 'message' => 'Bag type added', 'bag_type_id' => $newId, 'bag_type_name' => $bagTypeName]);
                } else {
                    $error = $stmt->error;
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to add bag type: ' . $error]);
                }
                exit();

            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
                exit();
        }
    } catch (Exception $e) {
        error_log("bags-log.php error: " . $e->getMessage());
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
  <title>Commodity Flow — Bag Journal</title>

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
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

  <!-- DataTables CSS -->
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/jquery.dataTables.min.css">
  <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.dataTables.min.css">
  <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.dataTables.min.css">

  <!-- App Styles -->
  <link rel="stylesheet" href="styles.css">

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

    /* Skeleton loader */
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

    /* Active nav link */
    .nav-link.active { background: rgba(45,157,153,0.12); color: #2d9d99; }
    .dark .nav-link.active { background: rgba(45,157,153,0.15); color: #4db8b4; }
    .nav-link.active .nav-icon { color: #2d9d99; }
    .dark .nav-link.active .nav-icon { color: #4db8b4; }
    .nav-link.active::before {
      content: '';
      position: absolute; left: 0; top: 15%; bottom: 15%;
      width: 3px; background: #2d9d99; border-radius: 0 3px 3px 0;
    }

    /* DataTable overrides */
    #bagsLogTable_wrapper .dataTables_filter input {
      background: transparent;
      border: 1px solid #e2e8f0;
      border-radius: 0.5rem;
      padding: 0.375rem 0.75rem;
      font-size: 0.8125rem;
      color: inherit;
      outline: none;
    }
    .dark #bagsLogTable_wrapper .dataTables_filter input {
      border-color: #475569;
      color: #e2e8f0;
    }
    #bagsLogTable_wrapper .dataTables_filter input:focus {
      border-color: #2d9d99;
      box-shadow: 0 0 0 2px rgba(45,157,153,0.2);
    }
    #bagsLogTable_wrapper .dataTables_length select {
      background: transparent;
      border: 1px solid #e2e8f0;
      border-radius: 0.5rem;
      padding: 0.375rem 0.5rem;
      font-size: 0.8125rem;
      color: inherit;
    }
    .dark #bagsLogTable_wrapper .dataTables_length select {
      border-color: #475569;
      color: #e2e8f0;
    }
    table.dataTable thead th {
      border-bottom: 2px solid #e2e8f0 !important;
      font-size: 0.75rem;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      color: #64748b;
      padding: 0.75rem 1rem !important;
    }
    .dark table.dataTable thead th { border-bottom-color: #334155 !important; color: #94a3b8; }
    table.dataTable tbody td {
      padding: 0.75rem 1rem !important;
      border-bottom: 1px solid #f1f5f9 !important;
      font-size: 0.8125rem;
      vertical-align: middle;
    }
    .dark table.dataTable tbody td { border-bottom-color: #1e293b !important; }
    table.dataTable tbody tr:hover { background: #f8fafc !important; }
    .dark table.dataTable tbody tr:hover { background: rgba(45,157,153,0.05) !important; }
    .dataTables_wrapper .dataTables_paginate .paginate_button.current {
      background: #2d9d99 !important;
      border-color: #2d9d99 !important;
      color: white !important;
      border-radius: 0.5rem;
    }
    .dataTables_wrapper .dataTables_paginate .paginate_button:hover {
      background: #f1f5f9 !important;
      border-color: #e2e8f0 !important;
      color: #334155 !important;
      border-radius: 0.5rem;
    }
    .dark .dataTables_wrapper .dataTables_paginate .paginate_button:hover {
      background: #1e293b !important;
      border-color: #334155 !important;
      color: #e2e8f0 !important;
    }
    .dataTables_wrapper .dataTables_info { font-size: 0.8125rem; color: #64748b; }
    .dark .dataTables_wrapper .dataTables_info { color: #94a3b8; }
    .dt-buttons .dt-button {
      background: transparent !important;
      border: 1px solid #e2e8f0 !important;
      border-radius: 0.5rem !important;
      padding: 0.375rem 0.875rem !important;
      font-size: 0.75rem !important;
      font-weight: 500 !important;
      color: #475569 !important;
      margin-right: 0.25rem !important;
      transition: all 150ms;
    }
    .dark .dt-buttons .dt-button { border-color: #475569 !important; color: #94a3b8 !important; }
    .dt-buttons .dt-button:hover { background: #f1f5f9 !important; border-color: #cbd5e1 !important; }
    .dark .dt-buttons .dt-button:hover { background: #1e293b !important; border-color: #64748b !important; }

    /* Action icon buttons */
    .action-icon {
      display: inline-flex; align-items: center; justify-content: center;
      width: 30px; height: 30px; border-radius: 0.375rem;
      border: none; cursor: pointer; transition: all 150ms;
      font-size: 12px; position: relative; background: transparent;
    }
    .edit-icon { color: #2d9d99; }
    .edit-icon:hover { background: rgba(45,157,153,0.1); }
    .delete-icon { color: #ef4444; }
    .delete-icon:hover { background: rgba(239,68,68,0.1); }

    /* Modal overlay */
    .modal-overlay {
      display: none; position: fixed; inset: 0;
      background: rgba(15,23,42,0.5); backdrop-filter: blur(4px);
      z-index: 1000; align-items: center; justify-content: center; padding: 1rem;
    }
    .modal-overlay.active { display: flex; }
    .modal {
      background: white; border-radius: 1rem; width: 100%; max-width: 680px;
      max-height: 90vh; overflow-y: auto; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25);
    }
    .dark .modal { background: #1e293b; }
    .modal-header {
      display: flex; align-items: center; justify-content: space-between;
      padding: 1rem 1.25rem; border-bottom: 1px solid #e2e8f0;
    }
    .dark .modal-header { border-bottom-color: #334155; }
    .modal-header h3 { font-size: 0.9375rem; font-weight: 700; color: #0f172a; }
    .dark .modal-header h3 { color: #f1f5f9; }
    .modal-body { padding: 1.25rem; }
    .close-btn {
      width: 32px; height: 32px; border-radius: 0.5rem; border: none;
      background: #f1f5f9; color: #64748b; cursor: pointer; display: flex;
      align-items: center; justify-content: center; transition: all 150ms;
    }
    .dark .close-btn { background: #334155; color: #94a3b8; }
    .close-btn:hover { background: #fee2e2; color: #ef4444; }

    /* Form styling */
    .form-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 1rem; }
    @media (max-width: 640px) { .form-grid { grid-template-columns: 1fr; } }
    .form-actions { display: flex; gap: 0.5rem; margin-top: 1.25rem; justify-content: flex-end; }

    /* Skeleton table */
    .skeleton-table { display: flex; flex-direction: column; gap: 12px; padding: 20px; }
    .skeleton-table-row { display: flex; gap: 12px; }
    .skeleton-table-cell { height: 18px; flex: 1; border-radius: 6px; }

    /* Filters grid */
    .filters-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 1rem; }

    /* Dropdown with add */
    .dropdown-with-add { display: flex; gap: 6px; align-items: center; }
    .dropdown-with-add select { flex: 1; }
    .btn-quick-add {
      width: 36px; height: 36px;
      border: 2px solid #2d9d99; background: transparent; color: #2d9d99;
      border-radius: 0.5rem; cursor: pointer;
      display: flex; align-items: center; justify-content: center;
      font-size: 14px; transition: all 0.2s ease; flex-shrink: 0;
    }
    .btn-quick-add:hover { background: #2d9d99; color: #fff; }

    /* Searchable dropdown styling for modal */
    .searchable-dropdown { position: relative; }
    .searchable-dropdown-input {
      width: 100%;
      background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 0.5rem;
      padding: 0.5rem 2rem 0.5rem 0.75rem; font-size: 0.875rem; color: #1e293b;
      outline: none; transition: all 150ms;
    }
    .dark .searchable-dropdown-input { background: #334155; border-color: #475569; color: #e2e8f0; }
    .searchable-dropdown-input:focus { border-color: #2d9d99; box-shadow: 0 0 0 2px rgba(45,157,153,0.2); }
    .searchable-dropdown-arrow {
      position: absolute; right: 10px; top: 50%; transform: translateY(-50%);
      color: #94a3b8; font-size: 11px; pointer-events: none; transition: transform 200ms;
    }
    .searchable-dropdown-arrow.open { transform: translateY(-50%) rotate(180deg); }
    .searchable-dropdown-list {
      position: absolute; top: 100%; left: 0; right: 0; z-index: 50;
      max-height: 200px; overflow-y: auto;
      background: white; border: 1px solid #e2e8f0; border-radius: 0.5rem;
      box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1); margin-top: 4px;
    }
    .dark .searchable-dropdown-list { background: #1e293b; border-color: #334155; }
    .searchable-dropdown-item {
      padding: 8px 12px; font-size: 0.8125rem; cursor: pointer; transition: background 100ms;
    }
    .searchable-dropdown-item:hover { background: #f1f5f9; }
    .dark .searchable-dropdown-item:hover { background: #334155; }
    .searchable-dropdown-item.selected { background: rgba(45,157,153,0.1); color: #2d9d99; font-weight: 600; }
    .searchable-dropdown-item.no-results { color: #94a3b8; cursor: default; }

    /* Counterpart toggle buttons */
    .cp-toggle-btn {
      flex: 1; font-size: 12px; padding: 6px 8px; border-radius: 0.375rem;
      cursor: pointer; transition: all 150ms; font-weight: 600; text-align: center;
    }
    .cp-toggle-btn.active { background: #2d9d99; color: #fff; border: 1px solid #2d9d99; }
    .cp-toggle-btn.inactive {
      background: #f8fafc; color: #64748b; border: 1px solid #e2e8f0;
    }
    .dark .cp-toggle-btn.inactive { background: #334155; color: #94a3b8; border-color: #475569; }

    /* Readonly field */
    .readonly-field { opacity: 0.7; cursor: not-allowed; }
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
        <i class="fas fa-boxes-stacked text-brand-500 text-sm"></i>
        <h1 class="text-base font-bold text-slate-800 dark:text-white">Bag Journal</h1>
      </div>

      <div class="ml-auto flex items-center gap-2">
        <span class="hidden sm:inline text-xs text-slate-400 dark:text-slate-500">Welcome, <?php echo htmlspecialchars($username); ?></span>
        <button class="bg-white dark:bg-slate-700 hover:bg-slate-50 dark:hover:bg-slate-600 text-slate-600 dark:text-slate-300 text-xs font-semibold px-3.5 py-2 rounded-lg border border-slate-200 dark:border-slate-600 transition-colors" onclick="loadBagsLog()" style="touch-action: manipulation;">
          <i class="fas fa-sync text-xs mr-1"></i> Refresh
        </button>
        <?php if ($canCreate): ?>
        <button class="bg-brand-500 hover:bg-brand-600 text-white text-xs font-semibold px-3.5 py-2 rounded-lg transition-colors shadow-sm" onclick="openAddModal()" style="touch-action: manipulation;">
          <i class="fas fa-plus text-xs mr-1"></i> Add Entry
        </button>
        <?php endif; ?>
      </div>
    </header>

    <!-- MAIN CONTENT -->
    <main class="flex-1 overflow-y-auto p-5">

      <!-- KPI Cards -->
      <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-5" id="kpiCardsSection">
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card overflow-hidden">
          <div class="h-0.5 bg-brand-500"></div>
          <div class="p-4 text-center">
            <div class="w-9 h-9 rounded-xl bg-brand-50 dark:bg-brand-900/20 flex items-center justify-center mx-auto mb-2">
              <i class="fas fa-hashtag text-brand-500 text-sm"></i>
            </div>
            <p class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Total Entries</p>
            <p class="mt-1 text-xl font-bold text-slate-800 dark:text-white tabular" id="kpiTotalEntries"><span class="skeleton inline-block h-6 w-16 rounded"></span></p>
          </div>
        </div>
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card overflow-hidden">
          <div class="h-0.5 bg-emerald-500"></div>
          <div class="p-4 text-center">
            <div class="w-9 h-9 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 flex items-center justify-center mx-auto mb-2">
              <i class="fas fa-arrow-down text-emerald-500 text-sm"></i>
            </div>
            <p class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Total In</p>
            <p class="mt-1 text-xl font-bold text-emerald-600 dark:text-emerald-400 tabular" id="kpiTotalIn"><span class="skeleton inline-block h-6 w-16 rounded"></span></p>
          </div>
        </div>
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card overflow-hidden">
          <div class="h-0.5 bg-rose-500"></div>
          <div class="p-4 text-center">
            <div class="w-9 h-9 rounded-xl bg-rose-50 dark:bg-rose-900/20 flex items-center justify-center mx-auto mb-2">
              <i class="fas fa-arrow-up text-rose-500 text-sm"></i>
            </div>
            <p class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Total Out</p>
            <p class="mt-1 text-xl font-bold text-rose-600 dark:text-rose-400 tabular" id="kpiTotalOut"><span class="skeleton inline-block h-6 w-16 rounded"></span></p>
          </div>
        </div>
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card overflow-hidden">
          <div class="h-0.5 bg-blue-500"></div>
          <div class="p-4 text-center">
            <div class="w-9 h-9 rounded-xl bg-blue-50 dark:bg-blue-900/20 flex items-center justify-center mx-auto mb-2">
              <i class="fas fa-scale-balanced text-blue-500 text-sm"></i>
            </div>
            <p class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Current Balance</p>
            <p class="mt-1 text-xl font-bold text-slate-800 dark:text-white tabular" id="kpiBalance"><span class="skeleton inline-block h-6 w-16 rounded"></span></p>
          </div>
        </div>
      </div>

      <!-- Filters -->
      <div id="filtersSection" style="display: none;" class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card p-4 mb-5">
        <div class="flex items-center justify-between mb-3">
          <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-300 flex items-center gap-2">
            <i class="fas fa-filter text-brand-500 text-xs"></i> Filters
          </h3>
          <button class="text-xs text-slate-400 hover:text-slate-600 dark:hover:text-slate-300 transition-colors" onclick="clearFilters()" style="touch-action: manipulation;">
            <i class="fas fa-times-circle mr-1"></i> Clear All
          </button>
        </div>
        <div class="filters-grid">
          <div>
            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">Date From</label>
            <input type="date" id="filterDateFrom" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
          </div>
          <div>
            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">Date To</label>
            <input type="date" id="filterDateTo" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
          </div>
          <div>
            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">Season</label>
            <select id="filterSeason" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
              <option value="">All Seasons</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">Customer</label>
            <select id="filterCustomer" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
              <option value="">All Customers</option>
            </select>
          </div>
        </div>
      </div>

      <!-- Data Card -->
      <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card">

        <!-- Skeleton Loader -->
        <div id="skeletonLoader" class="p-5">
          <div class="skeleton-table">
            <div class="skeleton-table-row"><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div></div>
            <div class="skeleton-table-row"><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div></div>
            <div class="skeleton-table-row"><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div></div>
            <div class="skeleton-table-row"><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div></div>
            <div class="skeleton-table-row"><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div></div>
            <div class="skeleton-table-row"><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div></div>
            <div class="skeleton-table-row"><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div></div>
            <div class="skeleton-table-row"><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div></div>
          </div>
        </div>

        <!-- Table Container -->
        <div id="tableContainer" style="display: none;" class="p-5">
          <div style="overflow-x: auto; -webkit-overflow-scrolling: touch;">
            <table id="bagsLogTable" class="display" style="width:100%"></table>
          </div>
        </div>

      </div><!-- end data card -->

    </main>
  </div><!-- end main-content -->
</div><!-- end appRoot -->

<!-- Add/Edit Modal -->
<?php if ($canCreate || $canUpdate): ?>
<div class="modal-overlay" id="bagLogModal">
  <div class="modal" onclick="event.stopPropagation()">
    <div class="modal-header">
      <h3 id="modalTitle"><i class="fas fa-boxes-stacked text-brand-500 mr-2"></i> Add Entry</h3>
      <button class="close-btn" onclick="closeModal()" style="touch-action: manipulation;">
        <i class="fas fa-times text-xs"></i>
      </button>
    </div>
    <div class="modal-body">
      <form id="bagLogForm">
        <input type="hidden" id="bagLogId" name="bag_log_id">

        <div class="form-grid">
          <div>
            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">Date *</label>
            <input type="date" id="bagLogDate" name="date" required class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">Counterpart</label>
            <div style="display:flex;gap:6px;margin-bottom:8px;">
              <button type="button" class="cp-toggle-btn active" id="cpTypeCustomer" onclick="setCounterpartType('customer')">Customer</button>
              <button type="button" class="cp-toggle-btn inactive" id="cpTypeSupplier" onclick="setCounterpartType('supplier')">Supplier</button>
            </div>
            <div id="customerDropdownGroup">
              <div class="searchable-dropdown" id="custDropdownWrapper">
                <input type="text" class="searchable-dropdown-input" id="custSearch" placeholder="Search customer..." autocomplete="off">
                <input type="hidden" id="custCustomerId" name="customer_id">
                <span class="searchable-dropdown-arrow" id="custArrow"><i class="fas fa-chevron-down"></i></span>
                <div class="searchable-dropdown-list" id="custList" style="display:none;"></div>
              </div>
            </div>
            <div id="supplierDropdownGroup" style="display:none;">
              <div class="searchable-dropdown" id="suppDropdownWrapper">
                <input type="text" class="searchable-dropdown-input" id="suppSearch" placeholder="Search supplier..." autocomplete="off">
                <input type="hidden" id="suppSupplierId" name="supplier_id">
                <span class="searchable-dropdown-arrow" id="suppArrow"><i class="fas fa-chevron-down"></i></span>
                <div class="searchable-dropdown-list" id="suppList" style="display:none;"></div>
              </div>
            </div>
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">Bag Type</label>
            <div class="dropdown-with-add">
              <select id="bagTypeId" name="bag_type_id" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
                <option value="">Select bag type...</option>
              </select>
              <button type="button" class="btn-quick-add" onclick="quickAddBagType()" title="Add new bag type">
                <i class="fas fa-plus"></i>
              </button>
            </div>
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">Description</label>
            <input type="text" id="bagLogDescription" name="description" maxlength="300" placeholder="Enter description..." class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">Qty In</label>
            <input type="number" id="bagLogQtyIn" name="qty_in" min="0" value="0" onchange="computeBalance()" oninput="computeBalance()" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">Ref Number</label>
            <input type="text" id="bagLogRefNumber" name="ref_number" maxlength="50" placeholder="REF-..." class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">Qty Out</label>
            <input type="number" id="bagLogQtyOut" name="qty_out" min="0" value="0" onchange="computeBalance()" oninput="computeBalance()" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">Previous Balance</label>
            <input type="number" id="bagLogPrevBalance" name="previous_balance" readonly class="readonly-field w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none transition-colors">
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">Balance</label>
            <input type="number" id="bagLogBalance" name="balance" readonly class="readonly-field w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none transition-colors">
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">Truck</label>
            <select id="bagLogTruckId" name="truck_id" onchange="onTruckChange()" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
              <option value="">Select truck...</option>
            </select>
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">Driver Name</label>
            <input type="text" id="bagLogDriverName" name="driver_name" readonly class="readonly-field w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none transition-colors" placeholder="Auto-filled from truck">
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">Season</label>
            <?php echo renderSeasonDropdown('bagLogSeason', 'season', null, false); ?>
          </div>
        </div>

        <div class="form-actions">
          <button type="button" class="bg-white dark:bg-slate-700 hover:bg-slate-50 dark:hover:bg-slate-600 text-slate-600 dark:text-slate-300 text-xs font-semibold px-3.5 py-2 rounded-lg border border-slate-200 dark:border-slate-600 transition-colors" onclick="closeModal()" style="touch-action: manipulation;">
            <i class="fas fa-times mr-1"></i> Cancel
          </button>
          <button type="submit" class="bg-brand-500 hover:bg-brand-600 text-white text-xs font-semibold px-3.5 py-2 rounded-lg transition-colors" style="touch-action: manipulation;">
            <i class="fas fa-save mr-1"></i> Save
          </button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
    // Global variables
    var ACTIVE_SEASON = '<?php echo addslashes(getActiveSeason()); ?>';
    var bagsLogTable;
    var isEditMode = false;
    var bagsLogData = [];
    var customersList = [];
    var vehiclesList = [];

    var canCreate = <?php echo $canCreate ? 'true' : 'false'; ?>;
    var canUpdate = <?php echo $canUpdate ? 'true' : 'false'; ?>;
    var canDelete = <?php echo $canDelete ? 'true' : 'false'; ?>;

    $(document).ready(function() {
        loadDropdowns();
        loadBagsLog();
    });

    // =====================================================
    // DROPDOWNS
    // =====================================================
    function loadDropdowns() {
        $.ajax({
            url: '?action=getDropdowns',
            method: 'GET',
            dataType: 'json',
            success: function(response) {
                if (response.success && response.data) {
                    // Customers
                    customersList = response.data.customers.map(function(c) {
                        return { id: c.customer_id, name: c.customer_name };
                    });
                    initCustomerDropdown();

                    // Populate customer filter
                    var filterCust = document.getElementById('filterCustomer');
                    if (filterCust) {
                        var currentVal = filterCust.value;
                        filterCust.innerHTML = '<option value="">All Customers</option>';
                        customersList.forEach(function(c) {
                            var opt = document.createElement('option');
                            opt.value = c.id;
                            opt.textContent = c.id + ' — ' + c.name;
                            filterCust.appendChild(opt);
                        });
                        if (currentVal) filterCust.value = currentVal;
                    }

                    // Suppliers
                    suppliersList = (response.data.suppliers || []).map(function(s) {
                        return { id: s.supplier_id, name: s.first_name };
                    });
                    initSupplierDropdown();

                    // Bag Types
                    var btSelect = document.getElementById('bagTypeId');
                    if (btSelect) {
                        btSelect.innerHTML = '<option value="">Select bag type...</option>';
                        response.data.bagTypes.forEach(function(bt) {
                            var opt = document.createElement('option');
                            opt.value = bt.bag_type_id;
                            opt.textContent = bt.bag_type_name;
                            btSelect.appendChild(opt);
                        });
                    }

                    // Vehicles
                    vehiclesList = response.data.vehicles;
                    var truckSelect = document.getElementById('bagLogTruckId');
                    if (truckSelect) {
                        truckSelect.innerHTML = '<option value="">Select truck...</option>';
                        vehiclesList.forEach(function(v) {
                            var opt = document.createElement('option');
                            opt.value = v.vehicle_id;
                            opt.textContent = v.vehicle_id + ' — ' + v.vehicle_registration + ' (' + v.driver_name + ')';
                            truckSelect.appendChild(opt);
                        });
                    }
                }
            }
        });
    }

    // =====================================================
    // SEARCHABLE CUSTOMER DROPDOWN
    // =====================================================
    function initCustomerDropdown() {
        var searchInput = document.getElementById('custSearch');
        var hiddenInput = document.getElementById('custCustomerId');
        var arrow = document.getElementById('custArrow');
        var listDiv = document.getElementById('custList');

        if (!searchInput) return;

        searchInput.addEventListener('focus', function() {
            renderCustomerList('');
            listDiv.style.display = 'block';
            arrow.classList.add('open');
        });

        searchInput.addEventListener('input', function() {
            renderCustomerList(this.value);
            listDiv.style.display = 'block';
        });

        document.addEventListener('click', function(e) {
            if (!document.getElementById('custDropdownWrapper').contains(e.target)) {
                listDiv.style.display = 'none';
                arrow.classList.remove('open');
                // Restore selected display
                if (hiddenInput.value) {
                    var sel = customersList.find(function(c) { return c.id === hiddenInput.value; });
                    if (sel) searchInput.value = sel.id + ' — ' + sel.name;
                }
            }
        });
    }

    function renderCustomerList(searchTerm) {
        var listDiv = document.getElementById('custList');
        var hiddenInput = document.getElementById('custCustomerId');
        listDiv.innerHTML = '';

        var filtered = customersList.filter(function(c) {
            var label = c.id + ' — ' + c.name;
            return label.toLowerCase().includes((searchTerm || '').toLowerCase());
        });

        if (filtered.length === 0) {
            listDiv.innerHTML = '<div class="searchable-dropdown-item no-results">No results found</div>';
            return;
        }

        filtered.forEach(function(c) {
            var div = document.createElement('div');
            div.className = 'searchable-dropdown-item' + (hiddenInput.value === c.id ? ' selected' : '');
            div.textContent = c.id + ' — ' + c.name;
            div.onclick = function() {
                document.getElementById('custSearch').value = c.id + ' — ' + c.name;
                document.getElementById('custCustomerId').value = c.id;
                listDiv.style.display = 'none';
                document.getElementById('custArrow').classList.remove('open');
            };
            listDiv.appendChild(div);
        });
    }

    // =====================================================
    // SUPPLIER SEARCHABLE DROPDOWN
    // =====================================================
    var suppliersList = [];

    function initSupplierDropdown() {
        var searchInput = document.getElementById('suppSearch');
        var hiddenInput = document.getElementById('suppSupplierId');
        var arrow = document.getElementById('suppArrow');
        var listDiv = document.getElementById('suppList');
        if (!searchInput) return;

        searchInput.addEventListener('focus', function() {
            renderSupplierList('');
            listDiv.style.display = 'block';
            arrow.classList.add('open');
        });
        searchInput.addEventListener('input', function() {
            renderSupplierList(this.value);
            listDiv.style.display = 'block';
        });
        document.addEventListener('click', function(e) {
            if (!document.getElementById('suppDropdownWrapper').contains(e.target)) {
                listDiv.style.display = 'none';
                arrow.classList.remove('open');
                if (hiddenInput.value) {
                    var sel = suppliersList.find(function(s) { return s.id === hiddenInput.value; });
                    if (sel) searchInput.value = sel.id + ' — ' + sel.name;
                }
            }
        });
    }

    function renderSupplierList(term) {
        var listDiv = document.getElementById('suppList');
        var hiddenInput = document.getElementById('suppSupplierId');
        listDiv.innerHTML = '';
        var filtered = suppliersList.filter(function(s) {
            return (s.id + ' — ' + s.name).toLowerCase().includes((term || '').toLowerCase());
        });
        if (filtered.length === 0) {
            listDiv.innerHTML = '<div class="searchable-dropdown-item no-results">No results found</div>';
            return;
        }
        filtered.forEach(function(s) {
            var div = document.createElement('div');
            div.className = 'searchable-dropdown-item' + (hiddenInput.value === s.id ? ' selected' : '');
            div.textContent = s.id + ' — ' + s.name;
            div.onclick = function() {
                document.getElementById('suppSearch').value = s.id + ' — ' + s.name;
                document.getElementById('suppSupplierId').value = s.id;
                listDiv.style.display = 'none';
                document.getElementById('suppArrow').classList.remove('open');
            };
            listDiv.appendChild(div);
        });
    }

    // =====================================================
    // COUNTERPART TYPE TOGGLE
    // =====================================================
    var currentCpType = 'customer';

    function setCounterpartType(type) {
        currentCpType = type;
        var custBtn = document.getElementById('cpTypeCustomer');
        var suppBtn = document.getElementById('cpTypeSupplier');
        var custGrp = document.getElementById('customerDropdownGroup');
        var suppGrp = document.getElementById('supplierDropdownGroup');

        if (type === 'customer') {
            custBtn.className = 'cp-toggle-btn active';
            suppBtn.className = 'cp-toggle-btn inactive';
            custGrp.style.display = '';
            suppGrp.style.display = 'none';
            // clear supplier
            document.getElementById('suppSupplierId').value = '';
            document.getElementById('suppSearch').value = '';
        } else {
            suppBtn.className = 'cp-toggle-btn active';
            custBtn.className = 'cp-toggle-btn inactive';
            custGrp.style.display = 'none';
            suppGrp.style.display = '';
            // clear customer
            document.getElementById('custCustomerId').value = '';
            document.getElementById('custSearch').value = '';
        }
    }

    // =====================================================
    // TRUCK CHANGE → AUTO-FILL DRIVER
    // =====================================================
    function onTruckChange() {
        var truckId = document.getElementById('bagLogTruckId').value;
        var driverField = document.getElementById('bagLogDriverName');

        if (truckId) {
            var vehicle = vehiclesList.find(function(v) { return v.vehicle_id === truckId; });
            if (vehicle) {
                driverField.value = vehicle.driver_name;
            } else {
                driverField.value = '';
            }
        } else {
            driverField.value = '';
        }
    }

    // =====================================================
    // AUTO-COMPUTE BALANCE
    // =====================================================
    function computeBalance() {
        var prevBalance = parseInt(document.getElementById('bagLogPrevBalance').value) || 0;
        var qtyIn = parseInt(document.getElementById('bagLogQtyIn').value) || 0;
        var qtyOut = parseInt(document.getElementById('bagLogQtyOut').value) || 0;
        var balance = prevBalance + qtyIn - qtyOut;
        document.getElementById('bagLogBalance').value = balance;
    }

    // =====================================================
    // UPDATE KPI CARDS
    // =====================================================
    function updateKpiCards(data) {
        var totalEntries = data.length;
        var totalIn = 0, totalOut = 0, balance = 0;
        data.forEach(function(d) {
            totalIn += parseInt(d.qty_in) || 0;
            totalOut += parseInt(d.qty_out) || 0;
        });
        if (data.length > 0) {
            balance = parseInt(data[0].balance) || 0;
        }
        document.getElementById('kpiTotalEntries').textContent = totalEntries.toLocaleString();
        document.getElementById('kpiTotalIn').textContent = '+' + totalIn.toLocaleString();
        document.getElementById('kpiTotalOut').textContent = '-' + totalOut.toLocaleString();
        document.getElementById('kpiBalance').textContent = balance.toLocaleString();
    }

    // =====================================================
    // LOAD BAGS LOG
    // =====================================================
    function loadBagsLog() {
        $('#skeletonLoader').show();
        $('#tableContainer').hide();

        $.ajax({
            url: '?action=getBagsLog',
            method: 'GET',
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    bagsLogData = response.data;
                    $('#filtersSection').show();
                    populateSeasonFilter(response.data);
                    updateKpiCards(response.data);
                    initializeDataTable(response.data);
                } else {
                    $('#skeletonLoader').hide();
                    Swal.fire({ icon: 'error', title: 'Error', text: response.message || 'Failed to load bags log' });
                }
            },
            error: function() {
                $('#skeletonLoader').hide();
                Swal.fire({ icon: 'error', title: 'Connection Error', text: 'Could not connect to server.' });
            }
        });
    }

    function populateSeasonFilter(data) {
        var seasons = [...new Set(data.map(function(d) { return d.season; }).filter(Boolean))];
        var select = document.getElementById('filterSeason');
        var currentVal = select.value;
        select.innerHTML = '<option value="">All Seasons</option>';
        seasons.sort().reverse().forEach(function(s) {
            var opt = document.createElement('option');
            opt.value = s;
            opt.textContent = s;
            select.appendChild(opt);
        });
        if (currentVal) select.value = currentVal;
    }

    // =====================================================
    // DATATABLE
    // =====================================================
    function initializeDataTable(data) {
        if (bagsLogTable) {
            bagsLogTable.destroy();
            $('#bagsLogTable').empty();
        }

        var columns = [
            { data: 'bag_log_id', title: '#' },
            { data: 'date', title: 'Date' },
            {
                data: null,
                title: 'Counterpart',
                render: function(data, type, row) {
                    if (row.supplier_id && row.supplier_name) {
                        return '<span style="color:#2d9d99;font-weight:600;">' + row.supplier_name + '</span><br><small style="color:#94a3b8;">' + row.supplier_id + ' <span style="background:rgba(45,157,153,0.1);padding:1px 6px;border-radius:3px;font-size:10px;color:#2d9d99;">Supplier</span></small>';
                    }
                    if (row.customer_id && row.customer_name) {
                        return row.customer_name + '<br><small style="color:#94a3b8;">' + row.customer_id + '</small>';
                    }
                    return '—';
                }
            },
            { data: 'bag_type_name', title: 'Bag Type', defaultContent: '—' },
            {
                data: 'description',
                title: 'Description',
                defaultContent: '',
                render: function(data) {
                    if (!data) return '';
                    return data.length > 30 ? '<span title="' + data.replace(/"/g, '&quot;') + '">' + data.substring(0, 30) + '...</span>' : data;
                }
            },
            { data: 'previous_balance', title: 'Prev Bal' },
            {
                data: 'qty_in',
                title: 'Qty In',
                render: function(data) {
                    var val = parseInt(data) || 0;
                    return val > 0 ? '<span style="color: #16a34a; font-weight: 600;">+' + val.toLocaleString() + '</span>' : '0';
                }
            },
            { data: 'ref_number', title: 'Ref#', defaultContent: '' },
            {
                data: 'qty_out',
                title: 'Qty Out',
                render: function(data) {
                    var val = parseInt(data) || 0;
                    return val > 0 ? '<span style="color: #ef4444; font-weight: 600;">-' + val.toLocaleString() + '</span>' : '0';
                }
            },
            {
                data: 'balance',
                title: 'Balance',
                render: function(data) {
                    return '<strong>' + (parseInt(data) || 0).toLocaleString() + '</strong>';
                }
            },
            {
                data: 'truck_id',
                title: 'Truck',
                render: function(data, type, row) {
                    if (!data) return '';
                    return data + (row.vehicle_registration ? '<br><small style="color:#94a3b8;">' + row.vehicle_registration + '</small>' : '');
                }
            },
            { data: 'driver_name', title: 'Driver', defaultContent: '' },
            { data: 'season', title: 'Season' }
        ];

        if (canUpdate || canDelete) {
            columns.push({
                data: null,
                title: 'Actions',
                orderable: false,
                render: function(data, type, row) {
                    var html = '';
                    if (canUpdate) {
                        html += '<button class="action-icon edit-icon" onclick=\'editEntry(' + JSON.stringify(row).replace(/'/g, "\\'") + ')\' title="Edit"><i class="fas fa-edit"></i></button> ';
                    }
                    if (canDelete) {
                        html += '<button class="action-icon delete-icon" onclick="deleteEntry(' + row.bag_log_id + ')" title="Delete"><i class="fas fa-trash"></i></button>';
                    }
                    return html;
                }
            });
        }

        setTimeout(function() {
            bagsLogTable = $('#bagsLogTable').DataTable({
                data: data,
                destroy: true,
                columns: columns,
                pageLength: 50,
                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
                responsive: true,
                dom: 'Blfrtip',
                buttons: [
                    { extend: 'csv', text: '<i class="fas fa-file-csv"></i> CSV', exportOptions: { columns: (canUpdate || canDelete) ? ':not(:last-child)' : ':visible' } },
                    { extend: 'pdf', text: '<i class="fas fa-file-pdf"></i> PDF', exportOptions: { columns: (canUpdate || canDelete) ? ':not(:last-child)' : ':visible' } },
                    { extend: 'print', text: '<i class="fas fa-print"></i> Print', exportOptions: { columns: (canUpdate || canDelete) ? ':not(:last-child)' : ':visible' } }
                ],
                order: [[0, 'desc']]
            });

            $('#skeletonLoader').hide();
            $('#tableContainer').show();

            $('#filterDateFrom, #filterDateTo, #filterSeason, #filterCustomer').on('change', function() {
                applyFilters();
            });
        }, 100);
    }

    // =====================================================
    // FILTERS
    // =====================================================
    function applyFilters() {
        if (!bagsLogTable) return;

        $.fn.dataTable.ext.search = [];

        var dateFrom = document.getElementById('filterDateFrom').value;
        var dateTo = document.getElementById('filterDateTo').value;
        var season = document.getElementById('filterSeason').value;
        var customer = document.getElementById('filterCustomer').value;

        if (dateFrom || dateTo) {
            $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
                var rawDate = bagsLogData[dataIndex]?.date_raw;
                if (!rawDate) return true;
                var recordDate = new Date(rawDate);
                var fromDate = dateFrom ? new Date(dateFrom) : null;
                var toDate = dateTo ? new Date(dateTo + 'T23:59:59') : null;
                if (fromDate && recordDate < fromDate) return false;
                if (toDate && recordDate > toDate) return false;
                return true;
            });
        }

        if (season) {
            $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
                return bagsLogData[dataIndex]?.season === season;
            });
        }

        if (customer) {
            $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
                return bagsLogData[dataIndex]?.customer_id === customer;
            });
        }

        bagsLogTable.draw();
    }

    function clearFilters() {
        document.getElementById('filterDateFrom').value = '';
        document.getElementById('filterDateTo').value = '';
        document.getElementById('filterSeason').value = '';
        document.getElementById('filterCustomer').value = '';

        if (bagsLogTable) {
            $.fn.dataTable.ext.search = [];
            bagsLogTable.columns().search('').draw();
        }
    }

    // =====================================================
    // MODAL: ADD
    // =====================================================
    function openAddModal() {
        isEditMode = false;
        document.getElementById('modalTitle').innerHTML = '<i class="fas fa-boxes-stacked text-brand-500 mr-2"></i> Add Entry';
        document.getElementById('bagLogForm').reset();
        document.getElementById('bagLogId').value = '';
        document.getElementById('custCustomerId').value = '';
        document.getElementById('custSearch').value = '';
        document.getElementById('suppSupplierId').value = '';
        document.getElementById('suppSearch').value = '';
        setCounterpartType('customer');
        document.getElementById('bagLogSeason').value = ACTIVE_SEASON;
        document.getElementById('bagLogQtyIn').value = '0';
        document.getElementById('bagLogQtyOut').value = '0';
        document.getElementById('bagLogDriverName').value = '';

        // Set today's date
        var today = new Date().toISOString().split('T')[0];
        document.getElementById('bagLogDate').value = today;

        // Compute previous balance from last entry in loaded data
        var prevBalance = 0;
        if (bagsLogData.length > 0) {
            // Data is ordered DESC, so index 0 is the latest entry
            prevBalance = parseInt(bagsLogData[0].balance) || 0;
        }
        document.getElementById('bagLogPrevBalance').value = prevBalance;
        document.getElementById('bagLogBalance').value = prevBalance;

        document.getElementById('bagLogModal').classList.add('active');
    }

    // =====================================================
    // MODAL: EDIT
    // =====================================================
    function editEntry(row) {
        isEditMode = true;
        document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit text-brand-500 mr-2"></i> Edit Entry #' + row.bag_log_id;
        document.getElementById('bagLogId').value = row.bag_log_id;
        document.getElementById('bagLogDate').value = row.date_raw;

        // Set counterpart type and dropdowns
        if (row.supplier_id) {
            setCounterpartType('supplier');
            document.getElementById('suppSupplierId').value = row.supplier_id;
            document.getElementById('suppSearch').value = row.supplier_id + ' — ' + row.supplier_name;
            document.getElementById('custCustomerId').value = '';
            document.getElementById('custSearch').value = '';
        } else {
            setCounterpartType('customer');
            document.getElementById('custCustomerId').value = row.customer_id || '';
            document.getElementById('custSearch').value = row.customer_id ? (row.customer_id + ' — ' + row.customer_name) : '';
            document.getElementById('suppSupplierId').value = '';
            document.getElementById('suppSearch').value = '';
        }

        document.getElementById('bagTypeId').value = row.bag_type_id || '';
        document.getElementById('bagLogDescription').value = row.description || '';
        document.getElementById('bagLogQtyIn').value = row.qty_in || 0;
        document.getElementById('bagLogRefNumber').value = row.ref_number || '';
        document.getElementById('bagLogQtyOut').value = row.qty_out || 0;
        document.getElementById('bagLogPrevBalance').value = row.previous_balance || 0;
        document.getElementById('bagLogBalance').value = row.balance || 0;
        document.getElementById('bagLogTruckId').value = row.truck_id || '';
        document.getElementById('bagLogDriverName').value = row.driver_name || '';
        document.getElementById('bagLogSeason').value = row.season || ACTIVE_SEASON;

        document.getElementById('bagLogModal').classList.add('active');
    }

    // =====================================================
    // MODAL: CLOSE
    // =====================================================
    function closeModal() {
        document.getElementById('bagLogModal').classList.remove('active');
        document.getElementById('bagLogForm').reset();
    }

    // Click outside to close
    document.getElementById('bagLogModal')?.addEventListener('click', function(e) {
        if (e.target === this) closeModal();
    });

    // =====================================================
    // FORM SUBMIT
    // =====================================================
    document.getElementById('bagLogForm')?.addEventListener('submit', function(e) {
        e.preventDefault();

        var formData = new FormData(this);
        var action = isEditMode ? 'updateBagLog' : 'addBagLog';

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
                    closeModal();
                    setTimeout(function() { loadBagsLog(); }, 100);
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: response.message });
                }
            },
            error: function(xhr, status, error) {
                Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error: ' + error });
            }
        });
    });

    // =====================================================
    // QUICK ADD BAG TYPE
    // =====================================================
    function quickAddBagType() {
        Swal.fire({
            title: 'Add New Bag Type',
            input: 'text',
            inputPlaceholder: 'e.g. Jute Bag 100kg',
            inputAttributes: { maxlength: 100 },
            showCancelButton: true,
            confirmButtonText: 'Add',
            inputValidator: function(value) {
                if (!value || !value.trim()) {
                    return 'Please enter a bag type name';
                }
            }
        }).then(function(result) {
            if (result.isConfirmed) {
                var formData = new FormData();
                formData.append('bag_type_name', result.value.trim());

                $.ajax({
                    url: '?action=quickAddBagType',
                    method: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            Swal.fire({ icon: 'success', title: 'Added!', text: response.message, timer: 1500, showConfirmButton: false });
                            // Reload dropdowns and select the new type
                            var newId = response.bag_type_id;
                            loadDropdowns();
                            setTimeout(function() {
                                document.getElementById('bagTypeId').value = newId;
                            }, 500);
                        } else {
                            Swal.fire({ icon: 'error', title: 'Error', text: response.message });
                        }
                    },
                    error: function() {
                        Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error' });
                    }
                });
            }
        });
    }

    // =====================================================
    // DELETE ENTRY
    // =====================================================
    function deleteEntry(id) {
        Swal.fire({
            title: 'Delete Entry #' + id + '?',
            text: 'This will delete the entry and recompute all subsequent balances. This action cannot be undone.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            confirmButtonText: 'Yes, delete it!'
        }).then(function(result) {
            if (result.isConfirmed) {
                var formData = new FormData();
                formData.append('bag_log_id', id);

                $.ajax({
                    url: '?action=deleteBagLog',
                    method: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            Swal.fire({ icon: 'success', title: 'Deleted!', text: response.message, timer: 2000, showConfirmButton: false });
                            loadBagsLog();
                        } else {
                            Swal.fire({ icon: 'error', title: 'Error', text: response.message });
                        }
                    },
                    error: function() {
                        Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error' });
                    }
                });
            }
        });
    }

    /* Theme init */
    (function() {
      var _store = {}; try { _store = window.localStorage; } catch(e) { _store = { getItem: function(){return null;}, setItem: function(){} }; }
      var html = document.documentElement;
      var dark = _store.getItem('cp_theme') === 'dark' || (_store.getItem('cp_theme') === null && window.matchMedia('(prefers-color-scheme: dark)').matches);
      html.classList.toggle('dark', dark);
    })();

    /* i18n loader */
    (function() {
      var _store = {}; try { _store = window.localStorage; } catch(e) { _store = { getItem: function(){return null;}, setItem: function(){} }; }
      var lang = _store.getItem('cp_lang') || 'en';
      document.documentElement.lang = lang;
    })();
</script>

</body>
</html>
