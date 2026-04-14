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
<!-- Developed by Rameez Scripts — https://www.youtube.com/@rameezimdad -->
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <title>Bags Log - Dashboard</title>

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

    <style>
        .dropdown-with-add {
            display: flex;
            gap: 6px;
            align-items: center;
        }
        .dropdown-with-add select {
            flex: 1;
        }
        .btn-quick-add {
            width: 36px;
            height: 36px;
            border: 2px solid var(--navy-accent, #0074D9);
            background: transparent;
            color: var(--navy-accent, #0074D9);
            border-radius: 6px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            transition: all 0.2s ease;
            flex-shrink: 0;
        }
        .btn-quick-add:hover {
            background: var(--navy-accent, #0074D9);
            color: #fff;
        }
    </style>
</head>
<body>
    <?php include 'mobile-menu.php'; ?>

    <div class="app-container">
        <?php include 'sidebar.php'; ?>

        <div class="main-content">
            <div class="header">
                <h1><i class="fas fa-boxes-packing"></i> Bags Log</h1>
                <div>Welcome, <?php echo htmlspecialchars($username); ?></div>
            </div>

            <div class="data-section">
                <div class="section-header">
                    <h2><i class="fas fa-table"></i> Bag Inventory Movement</h2>
                    <div class="section-header-actions">
                        <button class="btn btn-primary" onclick="loadBagsLog()">
                            <i class="fas fa-sync"></i> Refresh
                        </button>
                        <?php if ($canCreate): ?>
                        <button class="btn btn-success" onclick="openAddModal()">
                            <i class="fas fa-plus"></i> Add Entry
                        </button>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="filters-section" id="filtersSection" style="display: none;">
                    <div class="filters-header">
                        <h3><i class="fas fa-filter"></i> Filters</h3>
                        <button class="btn btn-secondary btn-sm" onclick="clearFilters()">
                            <i class="fas fa-times-circle"></i> Clear All
                        </button>
                    </div>
                    <div class="filters-grid">
                        <div class="filter-group">
                            <label><i class="fas fa-calendar-alt"></i> Date From</label>
                            <input type="date" id="filterDateFrom" class="filter-input">
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-calendar-alt"></i> Date To</label>
                            <input type="date" id="filterDateTo" class="filter-input">
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-leaf"></i> Season</label>
                            <select id="filterSeason" class="filter-input">
                                <option value="">All Seasons</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-handshake"></i> Customer</label>
                            <select id="filterCustomer" class="filter-input">
                                <option value="">All Customers</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div id="skeletonLoader">
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

                <div id="tableContainer" style="display: none;">
                    <div class="table-scroll-hint">
                        <i class="fas fa-arrows-alt-h"></i> Swipe left/right to see all columns
                    </div>
                    <div style="overflow-x: auto; -webkit-overflow-scrolling: touch;">
                        <table id="bagsLogTable" class="display" style="width:100%"></table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($canCreate || $canUpdate): ?>
    <div class="modal-overlay" id="bagLogModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 id="modalTitle"><i class="fas fa-boxes-packing"></i> Add Entry</h3>
                <button class="close-btn" onclick="closeModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <form id="bagLogForm">
                    <input type="hidden" id="bagLogId" name="bag_log_id">

                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-calendar-day"></i> Date *</label>
                            <input type="date" id="bagLogDate" name="date" required>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-handshake"></i> Counterpart</label>
                            <div style="display:flex;gap:6px;margin-bottom:8px;">
                                <button type="button" class="btn btn-sm" id="cpTypeCustomer" onclick="setCounterpartType('customer')" style="flex:1;font-size:12px;padding:5px 8px;background:var(--navy-accent);color:#fff;border:none;border-radius:4px;cursor:pointer;">Customer</button>
                                <button type="button" class="btn btn-sm" id="cpTypeSupplier" onclick="setCounterpartType('supplier')" style="flex:1;font-size:12px;padding:5px 8px;background:var(--bg-secondary);color:var(--text-muted);border:1px solid var(--border-light);border-radius:4px;cursor:pointer;">Supplier</button>
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

                        <div class="form-group">
                            <label><i class="fas fa-box"></i> Bag Type</label>
                            <div class="dropdown-with-add">
                                <select id="bagTypeId" name="bag_type_id">
                                    <option value="">Select bag type...</option>
                                </select>
                                <button type="button" class="btn-quick-add" onclick="quickAddBagType()" title="Add new bag type">
                                    <i class="fas fa-plus"></i>
                                </button>
                            </div>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-align-left"></i> Description</label>
                            <input type="text" id="bagLogDescription" name="description" maxlength="300" placeholder="Enter description...">
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-arrow-down"></i> Qty In</label>
                            <input type="number" id="bagLogQtyIn" name="qty_in" min="0" value="0" onchange="computeBalance()" oninput="computeBalance()">
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-hashtag"></i> Ref Number</label>
                            <input type="text" id="bagLogRefNumber" name="ref_number" maxlength="50" placeholder="REF-...">
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-arrow-up"></i> Qty Out</label>
                            <input type="number" id="bagLogQtyOut" name="qty_out" min="0" value="0" onchange="computeBalance()" oninput="computeBalance()">
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-scale-balanced"></i> Previous Balance</label>
                            <input type="number" id="bagLogPrevBalance" name="previous_balance" readonly class="readonly-field">
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-calculator"></i> Balance</label>
                            <input type="number" id="bagLogBalance" name="balance" readonly class="readonly-field">
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-truck"></i> Truck</label>
                            <select id="bagLogTruckId" name="truck_id" onchange="onTruckChange()">
                                <option value="">Select truck...</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-user"></i> Driver Name</label>
                            <input type="text" id="bagLogDriverName" name="driver_name" readonly class="readonly-field" placeholder="Auto-filled from truck">
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-leaf"></i> Season</label>
                            <?php echo renderSeasonDropdown('bagLogSeason', 'season', null, false); ?>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="closeModal()">
                            <i class="fas fa-times"></i> Cancel
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
            custBtn.style.background = 'var(--navy-accent)';
            custBtn.style.color = '#fff';
            custBtn.style.border = 'none';
            suppBtn.style.background = 'var(--bg-secondary)';
            suppBtn.style.color = 'var(--text-muted)';
            suppBtn.style.border = '1px solid var(--border-light)';
            custGrp.style.display = '';
            suppGrp.style.display = 'none';
            // clear supplier
            document.getElementById('suppSupplierId').value = '';
            document.getElementById('suppSearch').value = '';
        } else {
            suppBtn.style.background = 'var(--navy-accent)';
            suppBtn.style.color = '#fff';
            suppBtn.style.border = 'none';
            custBtn.style.background = 'var(--bg-secondary)';
            custBtn.style.color = 'var(--text-muted)';
            custBtn.style.border = '1px solid var(--border-light)';
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
                        return '<span style="color:var(--navy-accent);font-weight:600;">' + row.supplier_name + '</span><br><small class="text-muted">' + row.supplier_id + ' <span style="background:#e8f4fd;padding:1px 6px;border-radius:3px;font-size:10px;">Supplier</span></small>';
                    }
                    if (row.customer_id && row.customer_name) {
                        return row.customer_name + '<br><small class="text-muted">' + row.customer_id + '</small>';
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
                    return val > 0 ? '<span style="color: var(--success, #34a853); font-weight: 600;">+' + val.toLocaleString() + '</span>' : '0';
                }
            },
            { data: 'ref_number', title: 'Ref#', defaultContent: '' },
            {
                data: 'qty_out',
                title: 'Qty Out',
                render: function(data) {
                    var val = parseInt(data) || 0;
                    return val > 0 ? '<span style="color: var(--danger, #ea4335); font-weight: 600;">-' + val.toLocaleString() + '</span>' : '0';
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
                    return data + (row.vehicle_registration ? '<br><small class="text-muted">' + row.vehicle_registration + '</small>' : '');
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
        document.getElementById('modalTitle').innerHTML = '<i class="fas fa-boxes-packing"></i> Add Entry';
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
        document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit"></i> Edit Entry #' + row.bag_log_id;
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
    </script>
</body>
</html>
