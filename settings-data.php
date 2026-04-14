<?php
/**
 * Developed by Rameez Scripts
 * WhatsApp: https://wa.me/923224083545 (For Custom Projects)
 * YouTube: https://www.youtube.com/@rameezimdad (Subscribe for more!)
 *
 * Settings Data Management - Single page for all lookup tables
 * Usage: settings-data.php?type=locations|contract-types|supplier-types|warehouses|expense-categories|bag-types
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
$role = isset($_SESSION['role']) ? $_SESSION['role'] : 'Warehouse Clerk';
$user_id = $_SESSION['user_id'];

// ============================================================
// SETTINGS TYPE CONFIGURATION
// ============================================================
$SETTINGS_TYPES = [
    'locations' => [
        'table' => 'settings_locations',
        'id_col' => 'location_id',
        'name_col' => 'location_name',
        'name_label' => 'Location Name',
        'name_max' => 150,
        'title' => 'Locations',
        'icon' => 'fa-map-marker-alt',
        'page_id' => 'settings-locations',
        'extra_cols' => [],
        'log_entity' => 'Location'
    ],
    'contract-types' => [
        'table' => 'settings_contract_types',
        'id_col' => 'contract_type_id',
        'name_col' => 'contract_type_name',
        'name_label' => 'Contract Type Name',
        'name_max' => 100,
        'title' => 'Contract Types',
        'icon' => 'fa-file-contract',
        'page_id' => 'settings-contract-types',
        'extra_cols' => [],
        'log_entity' => 'Contract Type'
    ],
    'supplier-types' => [
        'table' => 'settings_supplier_types',
        'id_col' => 'supplier_type_id',
        'name_col' => 'supplier_type_name',
        'name_label' => 'Supplier Type Name',
        'name_max' => 100,
        'title' => 'Supplier Types',
        'icon' => 'fa-truck',
        'page_id' => 'settings-supplier-types',
        'extra_cols' => [],
        'log_entity' => 'Supplier Type'
    ],
    'warehouses' => [
        'table' => 'settings_warehouses',
        'id_col' => 'warehouse_id',
        'name_col' => 'warehouse_name',
        'name_label' => 'Warehouse Name',
        'name_max' => 150,
        'title' => 'Warehouses',
        'icon' => 'fa-warehouse',
        'page_id' => 'settings-warehouses',
        'extra_cols' => ['location_id', 'warehouse_code'],
        'log_entity' => 'Warehouse'
    ],
    'expense-categories' => [
        'table' => 'settings_expense_categories',
        'id_col' => 'category_id',
        'name_col' => 'category_name',
        'name_label' => 'Category Name',
        'name_max' => 100,
        'title' => 'Expense Categories',
        'icon' => 'fa-receipt',
        'page_id' => 'settings-expense-categories',
        'extra_cols' => [],
        'log_entity' => 'Expense Category'
    ],
    'bag-types' => [
        'table' => 'settings_bag_types',
        'id_col' => 'bag_type_id',
        'name_col' => 'bag_type_name',
        'name_label' => 'Bag Type Name',
        'name_max' => 100,
        'title' => 'Bag Types',
        'icon' => 'fa-box',
        'page_id' => 'settings-bag-types',
        'extra_cols' => [],
        'log_entity' => 'Bag Type'
    ],
    'paperwork-types' => [
        'table' => 'settings_paperwork_types',
        'id_col' => 'paperwork_type_id',
        'name_col' => 'paperwork_type_name',
        'name_label' => 'Paperwork Type Name',
        'name_max' => 100,
        'title' => 'Paperwork Types',
        'icon' => 'fa-scroll',
        'page_id' => 'settings-paperwork-types',
        'extra_cols' => [],
        'log_entity' => 'Paperwork Type'
    ],
    'seasons' => [
        'table' => 'settings_seasons',
        'id_col' => 'season_id',
        'name_col' => 'season_name',
        'name_label' => 'Season Name',
        'name_max' => 20,
        'title' => 'Seasons',
        'icon' => 'fa-calendar-alt',
        'page_id' => 'settings-seasons',
        'extra_cols' => ['start_date', 'end_date'],
        'log_entity' => 'Season'
    ]
];

// Validate type parameter
$type = isset($_GET['type']) ? $_GET['type'] : '';
if (!isset($SETTINGS_TYPES[$type])) {
    header("Location: settings-data.php?type=locations");
    exit();
}

$cfg = $SETTINGS_TYPES[$type];
$current_page = $cfg['page_id'];
$isWarehouse = ($type === 'warehouses');
$isSeason = ($type === 'seasons');

// RBAC permissions
$canCreate = in_array($role, ['Admin', 'Manager']);
$canUpdate = in_array($role, ['Admin', 'Manager']);
$canDelete = ($role === 'Admin');

// Only Admin and Manager can access this page
if (!in_array($role, ['Admin', 'Manager'])) {
    header("Location: dashboard.php");
    exit();
}

// ============================================================
// AJAX HANDLERS
// ============================================================
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    try {
        switch ($_GET['action']) {
            case 'getData':
                $conn = getDBConnection();

                if ($isWarehouse) {
                    $sql = "SELECT w.{$cfg['id_col']}, w.{$cfg['name_col']}, w.warehouse_code, w.location_id, w.is_active, w.created_at,
                            l.location_name
                            FROM {$cfg['table']} w
                            LEFT JOIN settings_locations l ON w.location_id = l.location_id
                            ORDER BY w.{$cfg['id_col']} DESC";
                } elseif ($isSeason) {
                    $sql = "SELECT season_id, season_name, start_date, end_date, is_active, created_at
                            FROM settings_seasons
                            ORDER BY season_id DESC";
                } else {
                    $sql = "SELECT {$cfg['id_col']}, {$cfg['name_col']}, is_active, created_at
                            FROM {$cfg['table']}
                            ORDER BY {$cfg['id_col']} DESC";
                }

                $result = $conn->query($sql);
                $items = [];

                if ($result) {
                    while ($row = $result->fetch_assoc()) {
                        $item = [
                            'id' => $row[$cfg['id_col']],
                            'name' => $row[$cfg['name_col']],
                            'is_active' => (bool)$row['is_active'],
                            'created_at' => date('M d, Y', strtotime($row['created_at']))
                        ];
                        if ($isWarehouse) {
                            $item['warehouse_code'] = $row['warehouse_code'] ?? '';
                            $item['location_id'] = $row['location_id'];
                            $item['location_name'] = $row['location_name'] ?? 'N/A';
                        }
                        if ($isSeason) {
                            $item['start_date'] = $row['start_date'] ?? '';
                            $item['end_date'] = $row['end_date'] ?? '';
                        }
                        $items[] = $item;
                    }
                }

                $conn->close();
                echo json_encode(['success' => true, 'data' => $items]);
                exit();

            case 'getLocations':
                if (!$isWarehouse) {
                    echo json_encode(['success' => false, 'message' => 'Not applicable']);
                    exit();
                }

                $conn = getDBConnection();
                $result = $conn->query("SELECT location_id, location_name FROM settings_locations WHERE is_active = 1 ORDER BY location_name ASC");
                $locations = [];

                if ($result) {
                    while ($row = $result->fetch_assoc()) {
                        $locations[] = [
                            'id' => $row['location_id'],
                            'name' => $row['location_name']
                        ];
                    }
                }

                $conn->close();
                echo json_encode(['success' => true, 'data' => $locations]);
                exit();

            case 'addItem':
                if (!$canCreate) {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $name = isset($_POST['name']) ? trim($_POST['name']) : '';

                if (empty($name)) {
                    echo json_encode(['success' => false, 'message' => $cfg['name_label'] . ' is required']);
                    exit();
                }

                if (strlen($name) > $cfg['name_max']) {
                    echo json_encode(['success' => false, 'message' => $cfg['name_label'] . ' must be less than ' . $cfg['name_max'] . ' characters']);
                    exit();
                }

                $conn = getDBConnection();

                // Check uniqueness
                $stmt = $conn->prepare("SELECT {$cfg['id_col']} FROM {$cfg['table']} WHERE {$cfg['name_col']} = ?");
                $stmt->bind_param("s", $name);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows > 0) {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => $cfg['name_label'] . ' already exists']);
                    exit();
                }
                $stmt->close();

                if ($isWarehouse) {
                    $locationId = isset($_POST['location_id']) ? intval($_POST['location_id']) : 0;
                    $warehouseCode = isset($_POST['warehouse_code']) ? strtoupper(trim($_POST['warehouse_code'])) : '';

                    if ($locationId <= 0) {
                        $conn->close();
                        echo json_encode(['success' => false, 'message' => 'Please select a location']);
                        exit();
                    }

                    if (empty($warehouseCode) || strlen($warehouseCode) < 2 || strlen($warehouseCode) > 5) {
                        $conn->close();
                        echo json_encode(['success' => false, 'message' => 'Warehouse code is required (2-5 letters)']);
                        exit();
                    }

                    // check code uniqueness
                    $stmt = $conn->prepare("SELECT warehouse_id FROM settings_warehouses WHERE warehouse_code = ?");
                    $stmt->bind_param("s", $warehouseCode);
                    $stmt->execute();
                    if ($stmt->get_result()->num_rows > 0) {
                        $stmt->close(); $conn->close();
                        echo json_encode(['success' => false, 'message' => 'Warehouse code already in use']);
                        exit();
                    }
                    $stmt->close();

                    // Validate location exists
                    $stmt = $conn->prepare("SELECT location_id FROM settings_locations WHERE location_id = ?");
                    $stmt->bind_param("i", $locationId);
                    $stmt->execute();
                    if ($stmt->get_result()->num_rows == 0) {
                        $stmt->close();
                        $conn->close();
                        echo json_encode(['success' => false, 'message' => 'Selected location does not exist']);
                        exit();
                    }
                    $stmt->close();

                    $stmt = $conn->prepare("INSERT INTO {$cfg['table']} ({$cfg['name_col']}, warehouse_code, location_id) VALUES (?, ?, ?)");
                    $stmt->bind_param("ssi", $name, $warehouseCode, $locationId);
                } elseif ($isSeason) {
                    $startDate = !empty($_POST['start_date']) ? trim($_POST['start_date']) : null;
                    $endDate = !empty($_POST['end_date']) ? trim($_POST['end_date']) : null;

                    $stmt = $conn->prepare("INSERT INTO settings_seasons (season_name, start_date, end_date) VALUES (?, ?, ?)");
                    $stmt->bind_param("sss", $name, $startDate, $endDate);
                } else {
                    $stmt = $conn->prepare("INSERT INTO {$cfg['table']} ({$cfg['name_col']}) VALUES (?)");
                    $stmt->bind_param("s", $name);
                }

                if ($stmt->execute()) {
                    logActivity($user_id, $username, $cfg['log_entity'] . ' Created', "Created {$cfg['log_entity']}: $name");
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => true, 'message' => $cfg['log_entity'] . ' added successfully']);
                } else {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to add ' . strtolower($cfg['log_entity'])]);
                }
                exit();

            case 'updateItem':
                if (!$canUpdate) {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $itemId = isset($_POST['id']) ? intval($_POST['id']) : 0;
                $name = isset($_POST['name']) ? trim($_POST['name']) : '';

                if ($itemId <= 0 || empty($name)) {
                    echo json_encode(['success' => false, 'message' => $cfg['name_label'] . ' is required']);
                    exit();
                }

                if (strlen($name) > $cfg['name_max']) {
                    echo json_encode(['success' => false, 'message' => $cfg['name_label'] . ' must be less than ' . $cfg['name_max'] . ' characters']);
                    exit();
                }

                $conn = getDBConnection();

                // Check uniqueness (exclude self)
                $stmt = $conn->prepare("SELECT {$cfg['id_col']} FROM {$cfg['table']} WHERE {$cfg['name_col']} = ? AND {$cfg['id_col']} != ?");
                $stmt->bind_param("si", $name, $itemId);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows > 0) {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => $cfg['name_label'] . ' already exists']);
                    exit();
                }
                $stmt->close();

                if ($isWarehouse) {
                    $locationId = isset($_POST['location_id']) ? intval($_POST['location_id']) : 0;
                    $warehouseCode = isset($_POST['warehouse_code']) ? strtoupper(trim($_POST['warehouse_code'])) : '';

                    if ($locationId <= 0) {
                        $conn->close();
                        echo json_encode(['success' => false, 'message' => 'Please select a location']);
                        exit();
                    }

                    if (empty($warehouseCode) || strlen($warehouseCode) < 2 || strlen($warehouseCode) > 5) {
                        $conn->close();
                        echo json_encode(['success' => false, 'message' => 'Warehouse code is required (2-5 letters)']);
                        exit();
                    }

                    // check code uniqueness (exclude self)
                    $stmt = $conn->prepare("SELECT warehouse_id FROM settings_warehouses WHERE warehouse_code = ? AND warehouse_id != ?");
                    $stmt->bind_param("si", $warehouseCode, $itemId);
                    $stmt->execute();
                    if ($stmt->get_result()->num_rows > 0) {
                        $stmt->close(); $conn->close();
                        echo json_encode(['success' => false, 'message' => 'Warehouse code already in use']);
                        exit();
                    }
                    $stmt->close();

                    // Validate location exists
                    $stmt = $conn->prepare("SELECT location_id FROM settings_locations WHERE location_id = ?");
                    $stmt->bind_param("i", $locationId);
                    $stmt->execute();
                    if ($stmt->get_result()->num_rows == 0) {
                        $stmt->close();
                        $conn->close();
                        echo json_encode(['success' => false, 'message' => 'Selected location does not exist']);
                        exit();
                    }
                    $stmt->close();

                    $stmt = $conn->prepare("UPDATE {$cfg['table']} SET {$cfg['name_col']} = ?, warehouse_code = ?, location_id = ? WHERE {$cfg['id_col']} = ?");
                    $stmt->bind_param("ssii", $name, $warehouseCode, $locationId, $itemId);
                } elseif ($isSeason) {
                    $startDate = !empty($_POST['start_date']) ? trim($_POST['start_date']) : null;
                    $endDate = !empty($_POST['end_date']) ? trim($_POST['end_date']) : null;

                    $stmt = $conn->prepare("UPDATE settings_seasons SET season_name = ?, start_date = ?, end_date = ? WHERE season_id = ?");
                    $stmt->bind_param("sssi", $name, $startDate, $endDate, $itemId);
                } else {
                    $stmt = $conn->prepare("UPDATE {$cfg['table']} SET {$cfg['name_col']} = ? WHERE {$cfg['id_col']} = ?");
                    $stmt->bind_param("si", $name, $itemId);
                }

                if ($stmt->execute()) {
                    logActivity($user_id, $username, $cfg['log_entity'] . ' Updated', "Updated {$cfg['log_entity']}: $name");
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => true, 'message' => $cfg['log_entity'] . ' updated successfully']);
                } else {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to update ' . strtolower($cfg['log_entity'])]);
                }
                exit();

            case 'toggleActive':
                if (!$canUpdate) {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $itemId = isset($_POST['id']) ? intval($_POST['id']) : 0;

                if ($itemId <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid ID']);
                    exit();
                }

                $conn = getDBConnection();

                // Get current status and name
                $stmt = $conn->prepare("SELECT {$cfg['name_col']}, is_active FROM {$cfg['table']} WHERE {$cfg['id_col']} = ?");
                $stmt->bind_param("i", $itemId);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows == 0) {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => $cfg['log_entity'] . ' not found']);
                    exit();
                }

                $item = $result->fetch_assoc();
                $stmt->close();

                $newStatus = $item['is_active'] ? 0 : 1;
                $stmt = $conn->prepare("UPDATE {$cfg['table']} SET is_active = ? WHERE {$cfg['id_col']} = ?");
                $stmt->bind_param("ii", $newStatus, $itemId);

                if ($stmt->execute()) {
                    $statusText = $newStatus ? 'activated' : 'deactivated';
                    logActivity($user_id, $username, $cfg['log_entity'] . ' Status Changed', "{$cfg['log_entity']} {$item[$cfg['name_col']]} $statusText");
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => true, 'message' => $cfg['log_entity'] . " $statusText successfully"]);
                } else {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to update status']);
                }
                exit();

            case 'deleteItem':
                if (!$canDelete) {
                    echo json_encode(['success' => false, 'message' => 'Access denied. Only Admin can delete.']);
                    exit();
                }

                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $itemId = isset($_POST['id']) ? intval($_POST['id']) : 0;

                if ($itemId <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid ID']);
                    exit();
                }

                $conn = getDBConnection();

                // Get item name for logging
                $stmt = $conn->prepare("SELECT {$cfg['name_col']} FROM {$cfg['table']} WHERE {$cfg['id_col']} = ?");
                $stmt->bind_param("i", $itemId);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows == 0) {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => $cfg['log_entity'] . ' not found']);
                    exit();
                }

                $item = $result->fetch_assoc();
                $stmt->close();

                // For locations, check if used by any warehouse
                if ($type === 'locations') {
                    $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM settings_warehouses WHERE location_id = ?");
                    $stmt->bind_param("i", $itemId);
                    $stmt->execute();
                    $countResult = $stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    if ($countResult['cnt'] > 0) {
                        $conn->close();
                        echo json_encode(['success' => false, 'message' => 'Cannot delete this location — it is assigned to ' . $countResult['cnt'] . ' warehouse(s). Remove or reassign them first.']);
                        exit();
                    }
                }

                $stmt = $conn->prepare("DELETE FROM {$cfg['table']} WHERE {$cfg['id_col']} = ?");
                $stmt->bind_param("i", $itemId);

                if ($stmt->execute()) {
                    logActivity($user_id, $username, $cfg['log_entity'] . ' Deleted', "Deleted {$cfg['log_entity']}: {$item[$cfg['name_col']]}");
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => true, 'message' => $cfg['log_entity'] . ' deleted successfully']);
                } else {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to delete ' . strtolower($cfg['log_entity'])]);
                }
                exit();

            case 'setActiveSeason':
                if (!in_array($role, ['Admin', 'Manager'])) {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }
                $seasonId = isset($_POST['item_id']) ? intval($_POST['item_id']) : 0;
                if ($seasonId <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid season ID']);
                    exit();
                }
                $conn = getDBConnection();
                // Deactivate all seasons
                $conn->query("UPDATE settings_seasons SET is_active = 0");
                // Activate selected season
                $stmt = $conn->prepare("UPDATE settings_seasons SET is_active = 1 WHERE season_id = ?");
                $stmt->bind_param("i", $seasonId);
                $stmt->execute();
                $stmt->close();
                // Get season name for logging
                $stmt = $conn->prepare("SELECT season_name FROM settings_seasons WHERE season_id = ?");
                $stmt->bind_param("i", $seasonId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $conn->close();
                logActivity($user_id, $username, 'Active Season Changed', "Set active season to: " . ($row['season_name'] ?? $seasonId));
                echo json_encode(['success' => true, 'message' => 'Active season updated successfully']);
                exit();

            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
                exit();
        }
    } catch (Exception $e) {
        error_log("settings-data.php error: " . $e->getMessage());
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
    <title><?php echo htmlspecialchars($cfg['title']); ?> - Dashboard System</title>

    <!-- CDN Dependencies -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.dataTables.min.css">
    <link rel="stylesheet" href="styles.css?v=4.0">

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
                <h1><i class="fas <?php echo htmlspecialchars($cfg['icon']); ?>"></i> <?php echo htmlspecialchars($cfg['title']); ?></h1>
                <div>Welcome, <?php echo htmlspecialchars($username); ?></div>
            </div>

            <div class="data-section">
                <div class="section-header">
                    <h2><i class="fas fa-table"></i> <?php echo htmlspecialchars($cfg['title']); ?></h2>
                    <div style="display: flex; gap: 10px;">
                        <button class="btn btn-primary" onclick="loadData()">
                            <i class="fas fa-sync"></i> Refresh
                        </button>
                        <?php if ($canCreate): ?>
                        <button class="btn btn-success" onclick="openAddModal()">
                            <i class="fas fa-plus"></i> Add <?php echo htmlspecialchars($cfg['log_entity']); ?>
                        </button>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Loading Skeleton -->
                <div id="loadingSkeleton">
                    <div class="skeleton-table">
                        <div class="skeleton-table-row">
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 2"></div>
                            <?php if ($isWarehouse): ?>
                            <div class="skeleton skeleton-table-cell" style="flex: 2"></div>
                            <?php endif; ?>
                            <?php if ($isSeason): ?>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <?php endif; ?>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                        </div>
                        <?php for ($i = 0; $i < 6; $i++): ?>
                        <div class="skeleton-table-row">
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 2"></div>
                            <?php if ($isWarehouse): ?>
                            <div class="skeleton skeleton-table-cell" style="flex: 2"></div>
                            <?php endif; ?>
                            <?php if ($isSeason): ?>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <?php endif; ?>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                        </div>
                        <?php endfor; ?>
                    </div>
                </div>

                <!-- DataTable -->
                <div id="tableContainer" style="display: none;">
                    <div class="table-scroll-hint">
                        <i class="fas fa-arrows-alt-h"></i> Swipe left/right to see all columns
                    </div>
                    <div style="overflow-x: auto; -webkit-overflow-scrolling: touch;">
                        <table id="dataTable" class="display" style="width:100%"></table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($canCreate || $canUpdate): ?>
    <!-- Item Modal -->
    <div class="modal-overlay" id="itemModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 id="modalTitle"><i class="fas fa-plus"></i> Add <?php echo htmlspecialchars($cfg['log_entity']); ?></h3>
                <button class="close-btn" onclick="closeModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <form id="itemForm">
                    <input type="hidden" id="itemId" name="id">

                    <div class="form-group">
                        <label><i class="fas <?php echo htmlspecialchars($cfg['icon']); ?>"></i> <?php echo htmlspecialchars($cfg['name_label']); ?> *</label>
                        <input type="text" id="itemName" name="name" required maxlength="<?php echo $cfg['name_max']; ?>">
                    </div>

                    <?php if ($isWarehouse): ?>
                    <div class="form-group">
                        <label><i class="fas fa-code"></i> Warehouse Code * <small style="color:var(--text-muted);">(2-5 letters, used as lot prefix)</small></label>
                        <input type="text" id="warehouseCode" name="warehouse_code" required maxlength="5" style="text-transform:uppercase;" placeholder="e.g. DAL, SEG, ALK">
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-map-marker-alt"></i> Location *</label>
                        <select id="locationSelect" name="location_id" required>
                            <option value="">Select Location</option>
                        </select>
                    </div>
                    <?php endif; ?>

                    <?php if ($isSeason): ?>
                    <div class="form-group">
                        <label><i class="fas fa-calendar-plus"></i> Start Date</label>
                        <input type="date" id="startDate" name="start_date">
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-calendar-minus"></i> End Date</label>
                        <input type="date" id="endDate" name="end_date">
                    </div>
                    <?php endif; ?>

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
        let dataTable;
        let isEditMode = false;
        const canCreate = <?php echo $canCreate ? 'true' : 'false'; ?>;
        const canUpdate = <?php echo $canUpdate ? 'true' : 'false'; ?>;
        const canDelete = <?php echo $canDelete ? 'true' : 'false'; ?>;
        const isWarehouse = <?php echo $isWarehouse ? 'true' : 'false'; ?>;
        const isSeason = <?php echo $isSeason ? 'true' : 'false'; ?>;
        const entityName = '<?php echo addslashes($cfg['log_entity']); ?>';
        const currentType = '<?php echo addslashes($type); ?>';

        $(document).ready(function() {
            if (isWarehouse) {
                loadLocations();
            }
            loadData();
        });

        function loadLocations() {
            $.ajax({
                url: '?type=' + currentType + '&action=getLocations',
                method: 'GET',
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        const select = document.getElementById('locationSelect');
                        if (!select) return;
                        select.innerHTML = '<option value="">Select Location</option>';
                        response.data.forEach(function(loc) {
                            const option = document.createElement('option');
                            option.value = loc.id;
                            option.textContent = loc.name;
                            select.appendChild(option);
                        });
                    }
                }
            });
        }

        function loadData() {
            $('#loadingSkeleton').show();
            $('#tableContainer').hide();

            $.ajax({
                url: '?type=' + currentType + '&action=getData',
                method: 'GET',
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        $('#loadingSkeleton').hide();
                        $('#tableContainer').show();
                        setTimeout(() => initializeDataTable(response.data), 100);
                    } else {
                        $('#loadingSkeleton').hide();
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: response.message || 'Failed to load data'
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
            if (dataTable) {
                dataTable.destroy();
                $('#dataTable').empty();
            }

            const columns = [
                { data: 'id', title: 'ID', width: '60px' },
                { data: 'name', title: '<?php echo addslashes($cfg['name_label']); ?>' }
            ];

            if (isWarehouse) {
                columns.push({ data: 'warehouse_code', title: 'Code', render: function(d) { return '<code style="background:#f0f4f8;padding:2px 6px;border-radius:3px;font-weight:600;">' + (d || '—') + '</code>'; } });
                columns.push({ data: 'location_name', title: 'Location' });
            }

            if (isSeason) {
                columns.push({
                    data: 'start_date',
                    title: 'Start Date',
                    render: function(data) {
                        return data ? new Date(data).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: '2-digit' }) : '';
                    }
                });
                columns.push({
                    data: 'end_date',
                    title: 'End Date',
                    render: function(data) {
                        return data ? new Date(data).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: '2-digit' }) : '';
                    }
                });
            }

            columns.push({
                data: 'is_active',
                title: 'Status',
                render: function(data) {
                    if (isSeason && data) {
                        return '<span class="status-badge status-active" style="background:#34a853;color:#fff;"><i class="fas fa-star"></i> Active Season</span>';
                    }
                    return data
                        ? '<span class="status-badge status-active"><i class="fas fa-check-circle"></i> Active</span>'
                        : '<span class="status-badge status-inactive"><i class="fas fa-times-circle"></i> Inactive</span>';
                }
            });

            // Actions column
            if (canUpdate || canDelete) {
                columns.push({
                    data: null,
                    title: 'Actions',
                    orderable: false,
                    render: function(data, type, row) {
                        let actions = '';

                        if (canUpdate) {
                            actions += `<button class="action-icon edit-icon" onclick='editItem(${JSON.stringify(row)})' title="Edit">
                                <i class="fas fa-edit"></i>
                            </button>`;

                            const toggleIcon = row.is_active ? 'fa-toggle-on' : 'fa-toggle-off';
                            const toggleColor = row.is_active ? 'style="color:#34a853"' : 'style="color:#ea4335"';
                            const toggleTitle = row.is_active ? 'Deactivate' : 'Activate';

                            actions += `<button class="action-icon" onclick="toggleActive(${row.id})" title="${toggleTitle}" ${toggleColor}>
                                <i class="fas ${toggleIcon}"></i>
                            </button>`;
                        }

                        if (isSeason && canUpdate && !row.is_active) {
                            actions += `<button class="action-icon" onclick="setActiveSeason(${row.id})" title="Set as Active Season" style="color:#f39c12">
                                <i class="fas fa-star"></i>
                            </button>`;
                        }

                        if (canDelete) {
                            actions += `<button class="action-icon delete-icon" onclick="deleteItem(${row.id})" title="Delete" style="color:#ea4335">
                                <i class="fas fa-trash"></i>
                            </button>`;
                        }

                        return actions;
                    }
                });
            }

            setTimeout(() => {
                dataTable = $('#dataTable').DataTable({
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
            }, 100);
        }

        <?php if ($canCreate || $canUpdate): ?>
        function openAddModal() {
            isEditMode = false;
            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-plus"></i> Add ' + entityName;
            document.getElementById('itemForm').reset();
            document.getElementById('itemId').value = '';
            document.getElementById('itemModal').classList.add('active');
        }

        function editItem(item) {
            if (!canUpdate) return;
            isEditMode = true;
            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit"></i> Edit ' + entityName;
            document.getElementById('itemId').value = item.id;
            document.getElementById('itemName').value = item.name;

            if (isWarehouse) {
                if (document.getElementById('warehouseCode')) {
                    document.getElementById('warehouseCode').value = item.warehouse_code || '';
                }
                if (document.getElementById('locationSelect')) {
                    document.getElementById('locationSelect').value = item.location_id || '';
                }
            }

            if (isSeason) {
                if (document.getElementById('startDate')) {
                    document.getElementById('startDate').value = item.start_date || '';
                }
                if (document.getElementById('endDate')) {
                    document.getElementById('endDate').value = item.end_date || '';
                }
            }

            document.getElementById('itemModal').classList.add('active');
        }

        function closeModal() {
            document.getElementById('itemModal').classList.remove('active');
            document.getElementById('itemForm').reset();
        }

        document.getElementById('itemModal').addEventListener('click', function(e) {
            if (e.target === this) closeModal();
        });

        document.getElementById('itemForm').addEventListener('submit', function(e) {
            e.preventDefault();

            const formData = new FormData(this);
            const action = isEditMode ? 'updateItem' : 'addItem';

            Swal.fire({
                title: 'Processing...',
                allowOutsideClick: false,
                didOpen: () => { Swal.showLoading(); }
            });

            $.ajax({
                url: '?type=' + currentType + '&action=' + action,
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
                        setTimeout(() => loadData(), 100);
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
        function toggleActive(itemId) {
            Swal.fire({
                icon: 'question',
                title: 'Toggle Status?',
                text: 'This will activate or deactivate this ' + entityName.toLowerCase(),
                showCancelButton: true,
                confirmButtonText: 'Yes, proceed',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    const formData = new FormData();
                    formData.append('id', itemId);

                    $.ajax({
                        url: '?type=' + currentType + '&action=toggleActive',
                        method: 'POST',
                        data: formData,
                        processData: false,
                        contentType: false,
                        dataType: 'json',
                        success: function(response) {
                            if (response.success) {
                                Swal.fire({ icon: 'success', text: response.message, timer: 2000, showConfirmButton: false });
                                setTimeout(() => loadData(), 100);
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
        function setActiveSeason(itemId) {
            Swal.fire({
                icon: 'question',
                title: 'Set Active Season?',
                text: 'This will set the selected season as the active season for the system.',
                showCancelButton: true,
                confirmButtonText: 'Yes, set active',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    const formData = new FormData();
                    formData.append('item_id', itemId);

                    $.ajax({
                        url: '?type=' + currentType + '&action=setActiveSeason',
                        method: 'POST',
                        data: formData,
                        processData: false,
                        contentType: false,
                        dataType: 'json',
                        success: function(response) {
                            if (response.success) {
                                Swal.fire({ icon: 'success', text: response.message, timer: 2000, showConfirmButton: false });
                                setTimeout(() => loadData(), 100);
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
        function deleteItem(itemId) {
            Swal.fire({
                icon: 'warning',
                title: 'Delete ' + entityName + '?',
                text: 'This action cannot be undone!',
                showCancelButton: true,
                confirmButtonColor: '#ea4335',
                confirmButtonText: 'Yes, delete it',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    const formData = new FormData();
                    formData.append('id', itemId);

                    $.ajax({
                        url: '?type=' + currentType + '&action=deleteItem',
                        method: 'POST',
                        data: formData,
                        processData: false,
                        contentType: false,
                        dataType: 'json',
                        success: function(response) {
                            if (response.success) {
                                Swal.fire({ icon: 'success', text: response.message, timer: 2000, showConfirmButton: false });
                                setTimeout(() => {
                                    loadData();
                                    if (isWarehouse) loadLocations();
                                }, 100);
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
