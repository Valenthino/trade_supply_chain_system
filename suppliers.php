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

// RBAC
$username = $_SESSION['username'];
$role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];
$current_page = 'suppliers';

$allowedRoles = ['Admin', 'Manager', 'Procurement Officer', 'Finance Officer', 'Warehouse Clerk'];
if (!in_array($role, $allowedRoles)) {
    header("Location: dashboard.php");
    exit();
}

$canCreate = in_array($role, ['Admin', 'Manager', 'Procurement Officer']);
$canUpdate = in_array($role, ['Admin', 'Manager', 'Procurement Officer']);
$canDelete = ($role === 'Admin');
$isReadOnly = !$canCreate && !$canUpdate;

// Handle AJAX requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    try {
        switch ($_GET['action']) {
            case 'getSuppliers':
                $conn = getDBConnection();

                // batch: outgoing financing balance_due per supplier (they owe us)
                $owedMap = [];
                $owedRes = $conn->query("SELECT counterparty_id, COALESCE(SUM(balance_due), 0) as total FROM financing WHERE direction = 'Outgoing' AND counterpart_type = 'Supplier' GROUP BY counterparty_id");
                if ($owedRes) { while ($r = $owedRes->fetch_assoc()) $owedMap[$r['counterparty_id']] = floatval($r['total']); }

                // batch: incoming financing balance_due per supplier (we owe them)
                $payableMap = [];
                $payableRes = $conn->query("SELECT counterparty_id, COALESCE(SUM(balance_due), 0) as total FROM financing WHERE direction = 'Incoming' AND counterpart_type = 'Supplier' GROUP BY counterparty_id");
                if ($payableRes) { while ($r = $payableRes->fetch_assoc()) $payableMap[$r['counterparty_id']] = floatval($r['total']); }

                $stmt = $conn->prepare("SELECT s.*, l.location_name, st.supplier_type_name, w.warehouse_name,
                    (SELECT spa.base_cost_per_kg FROM supplier_pricing_agreements spa WHERE spa.supplier_id = s.supplier_id AND spa.status = 'Active' ORDER BY spa.effective_date DESC LIMIT 1) as agreed_price_per_kg
                    FROM suppliers s
                    LEFT JOIN settings_locations l ON s.location_id = l.location_id
                    LEFT JOIN settings_supplier_types st ON s.supplier_type_id = st.supplier_type_id
                    LEFT JOIN settings_warehouses w ON s.closer_warehouse_id = w.warehouse_id
                    ORDER BY s.supplier_id DESC");
                $stmt->execute();
                $result = $stmt->get_result();

                $suppliers = [];
                while ($row = $result->fetch_assoc()) {
                    $sid = $row['supplier_id'];
                    // Balance = Outgoing balance_due - Incoming balance_due
                    // positive = they owe us, negative = we owe them
                    $owed = $owedMap[$sid] ?? 0;
                    $payable = $payableMap[$sid] ?? 0;
                    $accountBalance = $owed - $payable;

                    $suppliers[] = [
                        'supplier_id' => $row['supplier_id'],
                        'first_name' => $row['first_name'],
                        'phone' => $row['phone'] ?? '',
                        'whatsapp_phone' => $row['whatsapp_phone'] ?? '',
                        'id_number' => $row['id_number'] ?? '',
                        'date_of_birth' => $row['date_of_birth'] ?? '',
                        'location_id' => $row['location_id'],
                        'location_name' => $row['location_name'] ?? '',
                        'supplier_type_id' => $row['supplier_type_id'],
                        'supplier_type_name' => $row['supplier_type_name'] ?? '',
                        'typical_price_per_kg' => $row['typical_price_per_kg'],
                        'agreed_price_per_kg' => $row['agreed_price_per_kg'] ?? null,
                        'financing_balance' => $row['financing_balance'] ?? 0,
                        'account_balance' => round($accountBalance, 2),
                        'bank_account' => $row['bank_account'] ?? '',
                        'closer_warehouse_id' => $row['closer_warehouse_id'],
                        'warehouse_name' => $row['warehouse_name'] ?? '',
                        'procurement_region' => $row['procurement_region'] ?? '',
                        'profile_photo' => $row['profile_photo'] ?? '',
                        'id_photo' => $row['id_photo'] ?? '',
                        'status' => $row['status'] ?? 'Active',
                        'created_at' => $row['created_at'] ? date('M d, Y', strtotime($row['created_at'])) : ''
                    ];
                }

                $stmt->close();
                $conn->close();

                echo json_encode(['success' => true, 'data' => $suppliers]);
                exit();

            case 'getDropdowns':
                $conn = getDBConnection();

                // Get active locations
                $locStmt = $conn->prepare("SELECT location_id, location_name FROM settings_locations WHERE is_active = 1 ORDER BY location_name ASC");
                $locStmt->execute();
                $locations = $locStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $locStmt->close();

                // Get active supplier types
                $stStmt = $conn->prepare("SELECT supplier_type_id, supplier_type_name FROM settings_supplier_types WHERE is_active = 1 ORDER BY supplier_type_name ASC");
                $stStmt->execute();
                $supplierTypes = $stStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stStmt->close();

                // Get active warehouses with location name
                $whStmt = $conn->prepare("SELECT w.warehouse_id, w.warehouse_name, l.location_name
                    FROM settings_warehouses w
                    LEFT JOIN settings_locations l ON w.location_id = l.location_id
                    WHERE w.is_active = 1
                    ORDER BY w.warehouse_name ASC");
                $whStmt->execute();
                $warehouses = $whStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $whStmt->close();

                $conn->close();

                echo json_encode([
                    'success' => true,
                    'data' => [
                        'locations' => $locations,
                        'supplier_types' => $supplierTypes,
                        'warehouses' => $warehouses
                    ]
                ]);
                exit();

            case 'addSupplier':
                if (!$canCreate) {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $firstName = isset($_POST['first_name']) ? trim($_POST['first_name']) : '';
                $phone = isset($_POST['phone']) ? trim($_POST['phone']) : null;
                $whatsappPhone = isset($_POST['whatsapp_phone']) ? trim($_POST['whatsapp_phone']) : null;
                $idNumber = isset($_POST['id_number']) ? trim($_POST['id_number']) : null;
                $dateOfBirth = !empty($_POST['date_of_birth']) ? trim($_POST['date_of_birth']) : null;
                $locationId = isset($_POST['location_id']) ? intval($_POST['location_id']) : 0;
                $supplierTypeId = isset($_POST['supplier_type_id']) && $_POST['supplier_type_id'] !== '' ? intval($_POST['supplier_type_id']) : null;
                $typicalPrice = isset($_POST['typical_price_per_kg']) && $_POST['typical_price_per_kg'] !== '' ? floatval($_POST['typical_price_per_kg']) : null;
                $bankAccount = isset($_POST['bank_account']) ? trim($_POST['bank_account']) : null;
                $closerWarehouseId = isset($_POST['closer_warehouse_id']) && $_POST['closer_warehouse_id'] !== '' ? intval($_POST['closer_warehouse_id']) : null;
                $procurementRegion = isset($_POST['procurement_region']) ? trim($_POST['procurement_region']) : null;

                if (empty($firstName)) {
                    echo json_encode(['success' => false, 'message' => 'First name is required']);
                    exit();
                }

                if (strlen($firstName) > 150) {
                    echo json_encode(['success' => false, 'message' => 'First name must not exceed 150 characters']);
                    exit();
                }

                if ($locationId <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Location is required']);
                    exit();
                }

                $conn = getDBConnection();

                // AUTO-GENERATE supplier_id using format F-YYMMDDLOC###
                $locationAbbreviations = [
                    'Daloa' => 'DA', 'Seguela' => 'SE', 'Aladjkro' => 'AL', 'Vavoua' => 'VA',
                    'Blolequin' => 'BL', 'Abidjan' => 'AB', 'Yamoussoukro' => 'YA', 'San Pedro' => 'SP'
                ];

                // Get location name from location_id
                $locStmt = $conn->prepare("SELECT location_name FROM settings_locations WHERE location_id = ?");
                $locStmt->bind_param("i", $locationId);
                $locStmt->execute();
                $locResult = $locStmt->get_result();

                if ($locResult->num_rows === 0) {
                    $locStmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Invalid location']);
                    exit();
                }

                $locName = $locResult->fetch_assoc()['location_name'];
                $locStmt->close();

                // Get abbreviation (fallback: first 2 chars uppercase)
                $locAbbr = isset($locationAbbreviations[$locName]) ? $locationAbbreviations[$locName] : strtoupper(substr($locName, 0, 2));

                // Build prefix: F-YYMMDDLOC
                $prefix = 'F-' . date('y') . date('m') . date('d') . $locAbbr;

                // seq is per-location (not per date+location) so LOC003 = 3rd supplier in that location
                $locPattern = 'F-______' . $locAbbr . '%';
                $seqStmt = $conn->prepare("SELECT CAST(SUBSTRING(supplier_id, -3) AS UNSIGNED) as seq FROM suppliers WHERE supplier_id LIKE ? ORDER BY seq DESC LIMIT 1");
                $seqStmt->bind_param("s", $locPattern);
                $seqStmt->execute();
                $seqResult = $seqStmt->get_result();

                $nextSeq = ($seqResult->num_rows > 0) ? intval($seqResult->fetch_assoc()['seq']) + 1 : 1;
                $seqStmt->close();

                $supplierId = $prefix . str_pad($nextSeq, 3, '0', STR_PAD_LEFT);

                $stmt = $conn->prepare("INSERT INTO suppliers (supplier_id, first_name, phone, whatsapp_phone, id_number, date_of_birth, location_id, supplier_type_id, typical_price_per_kg, bank_account, closer_warehouse_id, procurement_region) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("ssssssiidsis", $supplierId, $firstName, $phone, $whatsappPhone, $idNumber, $dateOfBirth, $locationId, $supplierTypeId, $typicalPrice, $bankAccount, $closerWarehouseId, $procurementRegion);

                if ($stmt->execute()) {
                    logActivity($user_id, $username, 'Supplier Created', "Created supplier: $firstName (ID: $supplierId)");
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => true, 'message' => "Supplier added successfully (ID: $supplierId)", 'supplier_id' => $supplierId]);
                } else {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to add supplier']);
                }
                exit();

            case 'updateSupplier':
                if (!$canUpdate) {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $supplierId = isset($_POST['supplier_id']) ? trim($_POST['supplier_id']) : '';
                $firstName = isset($_POST['first_name']) ? trim($_POST['first_name']) : '';
                $phone = isset($_POST['phone']) ? trim($_POST['phone']) : null;
                $whatsappPhone = isset($_POST['whatsapp_phone']) ? trim($_POST['whatsapp_phone']) : null;
                $idNumber = isset($_POST['id_number']) ? trim($_POST['id_number']) : null;
                $dateOfBirth = !empty($_POST['date_of_birth']) ? trim($_POST['date_of_birth']) : null;
                $locationId = isset($_POST['location_id']) ? intval($_POST['location_id']) : 0;
                $supplierTypeId = isset($_POST['supplier_type_id']) && $_POST['supplier_type_id'] !== '' ? intval($_POST['supplier_type_id']) : null;
                $typicalPrice = isset($_POST['typical_price_per_kg']) && $_POST['typical_price_per_kg'] !== '' ? floatval($_POST['typical_price_per_kg']) : null;
                $bankAccount = isset($_POST['bank_account']) ? trim($_POST['bank_account']) : null;
                $closerWarehouseId = isset($_POST['closer_warehouse_id']) && $_POST['closer_warehouse_id'] !== '' ? intval($_POST['closer_warehouse_id']) : null;
                $procurementRegion = isset($_POST['procurement_region']) ? trim($_POST['procurement_region']) : null;

                if (empty($supplierId)) {
                    echo json_encode(['success' => false, 'message' => 'Supplier ID is required']);
                    exit();
                }

                if (empty($firstName)) {
                    echo json_encode(['success' => false, 'message' => 'First name is required']);
                    exit();
                }

                if (strlen($firstName) > 150) {
                    echo json_encode(['success' => false, 'message' => 'First name must not exceed 150 characters']);
                    exit();
                }

                if ($locationId <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Location is required']);
                    exit();
                }

                $conn = getDBConnection();

                $stmt = $conn->prepare("UPDATE suppliers SET first_name = ?, phone = ?, whatsapp_phone = ?, id_number = ?, date_of_birth = ?, location_id = ?, supplier_type_id = ?, typical_price_per_kg = ?, bank_account = ?, closer_warehouse_id = ?, procurement_region = ? WHERE supplier_id = ?");
                $stmt->bind_param("sssssiidsiss", $firstName, $phone, $whatsappPhone, $idNumber, $dateOfBirth, $locationId, $supplierTypeId, $typicalPrice, $bankAccount, $closerWarehouseId, $procurementRegion, $supplierId);

                if ($stmt->execute()) {
                    logActivity($user_id, $username, 'Supplier Updated', "Updated supplier: $firstName (ID: $supplierId)");
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => true, 'message' => 'Supplier updated successfully']);
                } else {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to update supplier']);
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

                $supplierId = isset($_POST['supplier_id']) ? trim($_POST['supplier_id']) : '';

                if (empty($supplierId)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid supplier ID']);
                    exit();
                }

                $conn = getDBConnection();

                // Get current status and name
                $stmt = $conn->prepare("SELECT first_name, status FROM suppliers WHERE supplier_id = ?");
                $stmt->bind_param("s", $supplierId);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows == 0) {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Supplier not found']);
                    exit();
                }

                $supplier = $result->fetch_assoc();
                $stmt->close();

                $newStatus = ($supplier['status'] === 'Active') ? 'Inactive' : 'Active';
                $stmt = $conn->prepare("UPDATE suppliers SET status = ? WHERE supplier_id = ?");
                $stmt->bind_param("ss", $newStatus, $supplierId);

                if ($stmt->execute()) {
                    $statusText = ($newStatus === 'Active') ? 'activated' : 'deactivated';
                    logActivity($user_id, $username, 'Supplier Status Changed', "Supplier {$supplier['first_name']} (ID: $supplierId) $statusText");
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => true, 'message' => "Supplier $statusText successfully"]);
                } else {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to update supplier status']);
                }
                exit();

            case 'deleteSupplier':
                if (!$canDelete) {
                    echo json_encode(['success' => false, 'message' => 'Access denied. Only Admin can delete suppliers.']);
                    exit();
                }

                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $supplierId = isset($_POST['supplier_id']) ? trim($_POST['supplier_id']) : '';

                if (empty($supplierId)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid supplier ID']);
                    exit();
                }

                $conn = getDBConnection();

                // Check FK references in purchases table
                $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM purchases WHERE supplier_id = ?");
                $stmt->bind_param("s", $supplierId);
                $stmt->execute();
                $result = $stmt->get_result();
                $cnt = $result->fetch_assoc()['cnt'];
                $stmt->close();

                if ($cnt > 0) {
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => "Cannot delete — supplier has $cnt linked purchase(s)"]);
                    exit();
                }

                // Get supplier name for logging
                $stmt = $conn->prepare("SELECT first_name FROM suppliers WHERE supplier_id = ?");
                $stmt->bind_param("s", $supplierId);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows == 0) {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Supplier not found']);
                    exit();
                }

                $supplierName = $result->fetch_assoc()['first_name'];
                $stmt->close();

                $stmt = $conn->prepare("DELETE FROM suppliers WHERE supplier_id = ?");
                $stmt->bind_param("s", $supplierId);

                if ($stmt->execute()) {
                    logActivity($user_id, $username, 'Supplier Deleted', "Deleted supplier: $supplierName (ID: $supplierId)");
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => true, 'message' => 'Supplier deleted successfully']);
                } else {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to delete supplier']);
                }
                exit();

            case 'uploadSupplierPhoto':
                if (!$canUpdate) {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }
                $supplierId = isset($_POST['supplier_id']) ? trim($_POST['supplier_id']) : '';
                $photoType = isset($_POST['photo_type']) ? trim($_POST['photo_type']) : 'profile'; // 'profile' or 'id'
                if (empty($supplierId)) {
                    echo json_encode(['success' => false, 'message' => 'Supplier ID is required']);
                    exit();
                }
                if (!in_array($photoType, ['profile', 'id'])) {
                    echo json_encode(['success' => false, 'message' => 'Invalid photo type']);
                    exit();
                }
                if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
                    echo json_encode(['success' => false, 'message' => 'No file uploaded or upload error']);
                    exit();
                }
                $result = uploadSupplierPhoto($_FILES['photo'], $supplierId, $photoType);
                if ($result['success']) {
                    $conn = getDBConnection();
                    $column = ($photoType === 'profile') ? 'profile_photo' : 'id_photo';
                    // Delete old file
                    $stmt = $conn->prepare("SELECT $column FROM suppliers WHERE supplier_id = ?");
                    $stmt->bind_param("s", $supplierId);
                    $stmt->execute();
                    $oldRow = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    if ($oldRow && $oldRow[$column] && file_exists(__DIR__ . '/' . $oldRow[$column])) {
                        unlink(__DIR__ . '/' . $oldRow[$column]);
                    }
                    $stmt = $conn->prepare("UPDATE suppliers SET $column = ? WHERE supplier_id = ?");
                    $stmt->bind_param("ss", $result['filename'], $supplierId);
                    $stmt->execute();
                    $stmt->close();
                    $conn->close();
                    logActivity($user_id, $username, 'Supplier Photo Uploaded', "Uploaded $photoType photo for supplier $supplierId");
                    echo json_encode(['success' => true, 'message' => 'Photo uploaded successfully', 'filename' => $result['filename']]);
                } else {
                    echo json_encode($result);
                }
                exit();

            case 'getSupplierReport':
                $supplierId = isset($_GET['supplier_id']) ? trim($_GET['supplier_id']) : '';
                if (empty($supplierId)) {
                    echo json_encode(['success' => false, 'message' => 'Supplier ID is required']);
                    exit();
                }

                $conn = getDBConnection();

                // Fetch supplier details
                $stmt = $conn->prepare("SELECT s.*, l.location_name, st.supplier_type_name, w.warehouse_name
                    FROM suppliers s
                    LEFT JOIN settings_locations l ON s.location_id = l.location_id
                    LEFT JOIN settings_supplier_types st ON s.supplier_type_id = st.supplier_type_id
                    LEFT JOIN settings_warehouses w ON s.closer_warehouse_id = w.warehouse_id
                    WHERE s.supplier_id = ?");
                $stmt->bind_param("s", $supplierId);
                $stmt->execute();
                $result = $stmt->get_result();
                $supplier = null;
                if ($result && $result->num_rows > 0) {
                    $supplier = $result->fetch_assoc();
                }
                $stmt->close();

                if (!$supplier) {
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Supplier not found']);
                    exit();
                }

                // Fetch purchases for this supplier
                $purchases = [];
                $stmt = $conn->prepare("SELECT p.purchase_id, p.date, p.weight_kg, p.num_bags, p.final_price_per_kg, p.total_cost, p.payment_status, p.warehouse_id, p.season, w.warehouse_name
                    FROM purchases p
                    LEFT JOIN settings_warehouses w ON p.warehouse_id = w.warehouse_id
                    WHERE p.supplier_id = ?
                    ORDER BY p.date DESC");
                $stmt->bind_param("s", $supplierId);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($result) {
                    while ($row = $result->fetch_assoc()) {
                        $purchases[] = $row;
                    }
                }
                $stmt->close();

                // Fetch payments for this supplier
                $payments = [];
                $stmt = $conn->prepare("SELECT payment_id, date, direction, payment_type, amount, payment_mode, reference_number
                    FROM payments
                    WHERE counterpart_id = ?
                    ORDER BY date DESC");
                $stmt->bind_param("s", $supplierId);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($result) {
                    while ($row = $result->fetch_assoc()) {
                        $payments[] = $row;
                    }
                }
                $stmt->close();

                // Fetch financing for this supplier
                $financing = [];
                $stmt = $conn->prepare("SELECT financing_id, date, direction, amount, amount_repaid, balance_due, status, source
                    FROM financing
                    WHERE counterparty_id = ?
                    ORDER BY date DESC");
                $stmt->bind_param("s", $supplierId);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($result) {
                    while ($row = $result->fetch_assoc()) {
                        $financing[] = $row;
                    }
                }
                $stmt->close();

                // account balance = outgoing balance_due - incoming balance_due
                $owed = 0; $payable = 0;
                foreach ($financing as $f) {
                    if ($f['direction'] === 'Outgoing') $owed += floatval($f['balance_due']);
                    elseif ($f['direction'] === 'Incoming') $payable += floatval($f['balance_due']);
                }
                $accountBalance = round($owed - $payable, 2);

                // build unified transaction log (mirrors reconcileSupplierAccount)
                $txnLog = [];

                // advances (financing: Outgoing, Manual)
                $stmt = $conn->prepare("SELECT financing_id, date, amount, carried_over_balance FROM financing WHERE counterparty_id = ? AND counterpart_type = 'Supplier' AND direction = 'Outgoing' AND source = 'Manual' ORDER BY date ASC, financing_id ASC");
                $stmt->bind_param("s", $supplierId);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($r = $res->fetch_assoc()) {
                    $amt = floatval($r['amount']) + floatval($r['carried_over_balance']);
                    $txnLog[] = ['date' => $r['date'], 'desc' => 'Advance Financing', 'reference' => $r['financing_id'], 'weight_kg' => null, 'num_bags' => null, 'price_per_kg' => null, 'purchase_amt' => null, 'financing_amt' => round($amt, 2), 'payment_mode' => '', 'sk' => 1];
                }
                $stmt->close();

                // direct purchase payments (Outgoing, type=Purchase)
                $stmt = $conn->prepare("SELECT payment_id, date, amount, payment_mode FROM payments WHERE counterpart_id = ? AND direction = 'Outgoing' AND payment_type = 'Purchase' ORDER BY date ASC, payment_id ASC");
                $stmt->bind_param("s", $supplierId);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($r = $res->fetch_assoc()) {
                    $txnLog[] = ['date' => $r['date'], 'desc' => 'Direct Payment', 'reference' => $r['payment_id'], 'weight_kg' => null, 'num_bags' => null, 'price_per_kg' => null, 'purchase_amt' => null, 'financing_amt' => round(floatval($r['amount']), 2), 'payment_mode' => $r['payment_mode'] ?? '', 'sk' => 2];
                }
                $stmt->close();

                // purchases (goods received)
                $stmt = $conn->prepare("SELECT purchase_id, date, total_cost, weight_kg, num_bags, final_price_per_kg FROM purchases WHERE supplier_id = ? ORDER BY date ASC, purchase_id ASC");
                $stmt->bind_param("s", $supplierId);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($r = $res->fetch_assoc()) {
                    $txnLog[] = ['date' => $r['date'], 'desc' => 'Purchase / Delivery', 'reference' => $r['purchase_id'], 'weight_kg' => round(floatval($r['weight_kg']), 2), 'num_bags' => intval($r['num_bags']), 'price_per_kg' => round(floatval($r['final_price_per_kg']), 2), 'purchase_amt' => round(floatval($r['total_cost']), 2), 'financing_amt' => null, 'payment_mode' => '', 'sk' => 3];
                }
                $stmt->close();

                // repayments from supplier (Incoming, type=Repayment)
                $stmt = $conn->prepare("SELECT payment_id, date, amount, payment_mode FROM payments WHERE counterpart_id = ? AND direction = 'Incoming' AND payment_type = 'Repayment' ORDER BY date ASC, payment_id ASC");
                $stmt->bind_param("s", $supplierId);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($r = $res->fetch_assoc()) {
                    $txnLog[] = ['date' => $r['date'], 'desc' => 'Supplier Repayment', 'reference' => $r['payment_id'], 'weight_kg' => null, 'num_bags' => null, 'price_per_kg' => null, 'purchase_amt' => null, 'financing_amt' => -round(floatval($r['amount']), 2), 'payment_mode' => $r['payment_mode'] ?? '', 'sk' => 4];
                }
                $stmt->close();

                // sort: date ASC, then sk for same-date ordering
                usort($txnLog, function($a, $b) {
                    $d = strcmp($a['date'], $b['date']);
                    return $d !== 0 ? $d : $a['sk'] - $b['sk'];
                });

                // compute prev/new balance per row
                $rb = 0;
                foreach ($txnLog as &$t) {
                    $t['prev_balance'] = round($rb, 2);
                    $fin = floatval($t['financing_amt'] ?? 0);
                    $pur = floatval($t['purchase_amt'] ?? 0);
                    $rb += $fin - $pur;
                    $t['new_balance'] = round($rb, 2);
                    unset($t['sk']);
                }
                unset($t);

                $conn->close();

                echo json_encode([
                    'success' => true,
                    'data' => [
                        'supplier' => $supplier,
                        'purchases' => $purchases,
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
    } catch (Exception $e) {
        error_log("Suppliers.php error: " . $e->getMessage());
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
    <title>Supplier Master - Dashboard System</title>

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
            <div class="breadcrumb">
                <a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
                <span class="breadcrumb-sep">/</span>
                <span>Supplier Master</span>
            </div>

            <div class="header">
                <h1><i class="fas fa-truck-field"></i> Supplier Master</h1>
                <div>Welcome, <?php echo htmlspecialchars($username); ?></div>
            </div>

            <div class="data-section">
                <div class="section-header">
                    <h2><i class="fas fa-table"></i> Suppliers</h2>
                    <div style="display: flex; gap: 10px;">
                        <button class="btn btn-primary" onclick="loadSuppliers()">
                            <i class="fas fa-sync"></i> Refresh
                        </button>
                        <?php if ($canCreate): ?>
                        <button class="btn btn-success" onclick="openAddModal()">
                            <i class="fas fa-plus"></i> Add Supplier
                        </button>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Filters Section -->
                <div class="filters-section" id="filtersSection" style="display: none;">
                    <div class="filters-header">
                        <h3><i class="fas fa-filter"></i> Filters</h3>
                        <button class="btn btn-secondary btn-sm" onclick="clearFilters()">
                            <i class="fas fa-times-circle"></i> Clear All
                        </button>
                    </div>
                    <div class="filters-grid">
                        <div class="filter-group">
                            <label><i class="fas fa-toggle-on"></i> Status</label>
                            <select id="filterStatus" class="filter-input">
                                <option value="">All Status</option>
                                <option value="Active">Active</option>
                                <option value="Inactive">Inactive</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-map-marker-alt"></i> Location</label>
                            <select id="filterLocation" class="filter-input">
                                <option value="">All Locations</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-truck"></i> Supplier Type</label>
                            <select id="filterSupplierType" class="filter-input">
                                <option value="">All Types</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Skeleton Loader -->
                <div id="skeletonLoader">
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
                    </div>
                </div>

                <!-- DataTable Container -->
                <div id="tableContainer" style="display: none;">
                    <div class="table-scroll-hint">
                        <i class="fas fa-arrows-alt-h"></i> Swipe left/right to see all columns
                    </div>
                    <div style="overflow-x: auto; -webkit-overflow-scrolling: touch;">
                        <table id="suppliersTable" class="display" style="width:100%"></table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($canCreate || $canUpdate): ?>
    <!-- Supplier Modal -->
    <div class="modal-overlay" id="supplierModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 id="modalTitle"><i class="fas fa-truck-field"></i> Add Supplier</h3>
                <button class="close-btn" onclick="closeModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <!-- Supplier ID info (shown in edit mode) -->
                <div id="supplierIdInfo" style="display: none; background: #f8f9fa; border: 1px solid #dee2e6; border-left: 4px solid var(--navy-primary); padding: 12px 16px; border-radius: 3px; margin-bottom: 20px;">
                    <strong><i class="fas fa-id-badge"></i> Supplier ID:</strong> <span id="supplierIdDisplay"></span>
                </div>

                <form id="supplierForm">
                    <input type="hidden" id="supplierId" name="supplier_id">

                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-user"></i> First Name *</label>
                            <input type="text" id="firstName" name="first_name" required maxlength="150">
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-phone"></i> Phone</label>
                            <input type="tel" id="phone" name="phone" maxlength="20" placeholder="+2250505761440">
                        </div>

                        <div class="form-group">
                            <label><i class="fab fa-whatsapp" style="color:#25D366;"></i> WhatsApp</label>
                            <div style="display:flex;align-items:center;gap:8px;">
                                <label style="display:flex;align-items:center;gap:4px;font-size:13px;cursor:pointer;white-space:nowrap;">
                                    <input type="checkbox" id="whatsappSameAsPhone" onchange="toggleWhatsappField()" checked> Same as phone
                                </label>
                                <input type="tel" id="whatsappPhone" name="whatsapp_phone" maxlength="20" placeholder="Different WhatsApp #" style="display:none;">
                            </div>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-id-card"></i> ID Number</label>
                            <input type="text" id="idNumber" name="id_number" maxlength="50" placeholder="National ID">
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-birthday-cake"></i> Date of Birth</label>
                            <input type="date" id="dateOfBirth" name="date_of_birth">
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-map-marker-alt"></i> Location *</label>
                            <select id="locationId" name="location_id" required>
                                <option value="">Select Location</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-truck"></i> Supplier Type</label>
                            <select id="supplierTypeId" name="supplier_type_id">
                                <option value="">Select Type</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-money-bill-wave"></i> Typical Price/Kg</label>
                            <input type="number" id="typicalPrice" name="typical_price_per_kg" step="0.01" min="0">
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-university"></i> Bank Account</label>
                            <input type="text" id="bankAccount" name="bank_account" maxlength="100">
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-map-location-dot"></i> Procurement Region</label>
                            <input type="text" id="procurementRegion" name="procurement_region" maxlength="150" placeholder="e.g. Region de Daloa">
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-warehouse"></i> Closer Warehouse</label>
                            <select id="closerWarehouseId" name="closer_warehouse_id">
                                <option value="">Select Warehouse</option>
                            </select>
                        </div>
                    </div>

                    <!-- Photo Upload — visible in both add & edit mode -->
                    <div id="photoUploadSection" style="display: none; margin-top: 15px; padding: 15px; background: #f8f9fa; border-radius: 6px; border: 1px solid #dee2e6;">
                        <h4 style="margin: 0 0 12px 0; font-size: 14px; color: var(--navy-primary);"><i class="fas fa-camera"></i> Supplier Photos</h4>
                        <div id="photoAddHint" style="display:none;font-size:12px;color:#666;margin-bottom:10px;"><i class="fas fa-info-circle"></i> Pick the photos here — they'll be uploaded automatically after you click <strong>Save</strong>.</div>
                        <div class="form-grid">
                            <div class="form-group">
                                <label><i class="fas fa-user-circle"></i> Profile Photo</label>
                                <div style="display: flex; gap: 8px; align-items: center;">
                                    <input type="file" id="profilePhotoFile" accept="image/*" style="flex:1;">
                                    <button type="button" id="profilePhotoUploadBtn" class="btn btn-primary btn-sm" onclick="uploadPhoto('profile')" style="white-space:nowrap;">
                                        <i class="fas fa-upload"></i> Upload
                                    </button>
                                </div>
                                <div id="profilePhotoPreview" style="margin-top: 8px;"></div>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-id-card"></i> ID Photo</label>
                                <div style="display: flex; gap: 8px; align-items: center;">
                                    <input type="file" id="idPhotoFile" accept="image/*" style="flex:1;">
                                    <button type="button" id="idPhotoUploadBtn" class="btn btn-primary btn-sm" onclick="uploadPhoto('id')" style="white-space:nowrap;">
                                        <i class="fas fa-upload"></i> Upload
                                    </button>
                                </div>
                                <div id="idPhotoPreview" style="margin-top: 8px;"></div>
                            </div>
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
        let suppliersTable;
        let isEditMode = false;
        let suppliersData = [];
        const isReadOnly = <?php echo $isReadOnly ? 'true' : 'false'; ?>;
        const canCreate = <?php echo $canCreate ? 'true' : 'false'; ?>;
        const canUpdate = <?php echo $canUpdate ? 'true' : 'false'; ?>;
        const canDelete = <?php echo $canDelete ? 'true' : 'false'; ?>;

        function formatPhone(phone) {
            if (!phone) return '';
            var clean = phone.replace(/\s+/g, '');
            // format: +XXX XX XX XX XX XX
            if (clean.startsWith('+')) {
                var cc = clean.substring(0, 4);
                var rest = clean.substring(4);
                var pairs = rest.match(/.{1,2}/g) || [];
                return cc + ' ' + pairs.join(' ');
            }
            var pairs = clean.match(/.{1,2}/g) || [];
            return pairs.join(' ');
        }

        function toggleWhatsappField() {
            var cb = document.getElementById('whatsappSameAsPhone');
            var field = document.getElementById('whatsappPhone');
            field.style.display = cb.checked ? 'none' : 'block';
            if (cb.checked) field.value = '';
        }

        $(document).ready(function() {
            loadDropdowns();
            loadSuppliers();
        });

        function loadDropdowns() {
            $.ajax({
                url: '?action=getDropdowns',
                method: 'GET',
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        const data = response.data;

                        // Populate location dropdowns (form + filter)
                        let locOptions = '<option value="">Select Location</option>';
                        let filterLocOptions = '<option value="">All Locations</option>';
                        data.locations.forEach(function(loc) {
                            locOptions += '<option value="' + loc.location_id + '">' + loc.location_name + '</option>';
                            filterLocOptions += '<option value="' + loc.location_name + '">' + loc.location_name + '</option>';
                        });
                        $('#locationId').html(locOptions);
                        $('#filterLocation').html(filterLocOptions);

                        // Populate supplier type dropdowns (form + filter)
                        let stOptions = '<option value="">Select Type</option>';
                        let filterStOptions = '<option value="">All Types</option>';
                        data.supplier_types.forEach(function(st) {
                            stOptions += '<option value="' + st.supplier_type_id + '">' + st.supplier_type_name + '</option>';
                            filterStOptions += '<option value="' + st.supplier_type_name + '">' + st.supplier_type_name + '</option>';
                        });
                        $('#supplierTypeId').html(stOptions);
                        $('#filterSupplierType').html(filterStOptions);

                        // Populate warehouse dropdown (form only)
                        let whOptions = '<option value="">Select Warehouse</option>';
                        data.warehouses.forEach(function(wh) {
                            const label = wh.location_name ? wh.warehouse_name + ' (' + wh.location_name + ')' : wh.warehouse_name;
                            whOptions += '<option value="' + wh.warehouse_id + '">' + label + '</option>';
                        });
                        $('#closerWarehouseId').html(whOptions);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Failed to load dropdowns:', error);
                }
            });
        }

        function loadSuppliers() {
            $('#skeletonLoader').show();
            $('#tableContainer').hide();

            $.ajax({
                url: '?action=getSuppliers',
                method: 'GET',
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        suppliersData = response.data;
                        $('#skeletonLoader').hide();
                        $('#tableContainer').show();
                        $('#filtersSection').show();
                        initializeDataTable(response.data);
                    } else {
                        $('#skeletonLoader').hide();
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: response.message || 'Failed to load suppliers'
                        });
                    }
                },
                error: function(xhr, status, error) {
                    $('#skeletonLoader').hide();
                    console.error('AJAX Error:', error);
                    Swal.fire({
                        icon: 'error',
                        title: 'Connection Error',
                        text: 'Could not connect to server. Please check console for details.'
                    });
                }
            });
        }

        // expandable row detail
        function renderSupplierDetail(d) {
            var profileImg = d.profile_photo ? '<img src="' + d.profile_photo + '" style="width:60px;height:60px;border-radius:50%;object-fit:cover;border:2px solid var(--navy-accent);">' : '<div style="width:60px;height:60px;border-radius:50%;background:var(--navy-primary);display:flex;align-items:center;justify-content:center;color:#fff;font-size:22px;font-weight:700;">' + (d.first_name ? d.first_name.charAt(0).toUpperCase() : '?') + '</div>';

            var phone = d.phone || 'N/A';
            var phoneClean = (d.phone || '').replace(/\s+/g, '');
            var waNum = (d.whatsapp_phone || phoneClean).replace(/[^0-9]/g, '');

            var bal = parseFloat(d.account_balance) || 0;
            var balHtml = '';
            if (bal > 0.01) {
                // positive = net advance, they owe us product or refund
                balHtml = '<span style="color:#0074D9;font-weight:700;">+ ' + Math.abs(bal).toLocaleString('en-US', {maximumFractionDigits: 0}) + ' F</span> <small style="color:#0074D9;">Net advance</small>';
            } else if (bal < -0.01) {
                // negative = we owe them (payable)
                balHtml = '<span style="color:#e74c3c;font-weight:700;">- ' + Math.abs(bal).toLocaleString('en-US', {maximumFractionDigits: 0}) + ' F</span> <small style="color:#e74c3c;">Payable</small>';
            } else {
                balHtml = '<span style="color:#27ae60;font-weight:700;">0 F</span> <small style="color:#27ae60;">Settled</small>';
            }

            var priceHtml = 'N/A';
            var price = d.agreed_price_per_kg || d.typical_price_per_kg;
            if (price) {
                priceHtml = parseFloat(price).toLocaleString('en-US', {maximumFractionDigits: 0}) + ' F/kg';
                if (d.agreed_price_per_kg) priceHtml += ' <small style="color:var(--text-muted);">(Agreement)</small>';
            }

            return '<div class="supplier-detail-panel" style="padding:20px 30px;background:var(--bg-secondary);border-left:4px solid var(--navy-accent);">' +
                '<div style="display:flex;gap:20px;align-items:flex-start;flex-wrap:wrap;">' +
                    '<div style="flex-shrink:0;">' + profileImg + '</div>' +
                    '<div style="flex:1;min-width:200px;">' +
                        '<h4 style="margin:0 0 4px;color:var(--navy-primary);font-size:16px;">' + (d.first_name || '') + '</h4>' +
                        '<div style="color:var(--text-muted);font-size:12px;margin-bottom:12px;">' + d.supplier_id + ' · ' + (d.supplier_type_name || 'N/A') + ' · ' + (d.status === 'Active' ? '<span style="color:#27ae60;">Active</span>' : '<span style="color:#e74c3c;">Inactive</span>') + '</div>' +
                        '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px 24px;font-size:13px;">' +
                            '<div><strong style="color:var(--text-muted);font-size:11px;text-transform:uppercase;">Phone</strong><br>' + (phoneClean ? '<a href="tel:' + phoneClean + '" style="color:var(--text-primary);">' + formatPhone(d.phone) + '</a> <a href="https://wa.me/' + waNum + '" target="_blank" style="color:#25D366;"><i class="fab fa-whatsapp"></i></a>' : 'N/A') + '</div>' +
                            '<div><strong style="color:var(--text-muted);font-size:11px;text-transform:uppercase;">Location</strong><br>' + (d.location_name || 'N/A') + '</div>' +
                            '<div><strong style="color:var(--text-muted);font-size:11px;text-transform:uppercase;">Procurement Region</strong><br>' + (d.procurement_region || 'N/A') + '</div>' +
                            '<div><strong style="color:var(--text-muted);font-size:11px;text-transform:uppercase;">Price/Kg</strong><br>' + priceHtml + '</div>' +
                            '<div><strong style="color:var(--text-muted);font-size:11px;text-transform:uppercase;">Account Balance</strong><br>' + balHtml + '</div>' +
                            '<div><strong style="color:var(--text-muted);font-size:11px;text-transform:uppercase;">ID Number</strong><br>' + (d.id_number || 'N/A') + '</div>' +
                            '<div><strong style="color:var(--text-muted);font-size:11px;text-transform:uppercase;">Birthday</strong><br>' + (d.date_of_birth ? (function(dob){ var b = new Date(dob + 'T00:00:00'); var now = new Date(); var age = now.getFullYear() - b.getFullYear(); if (now.getMonth() < b.getMonth() || (now.getMonth() === b.getMonth() && now.getDate() < b.getDate())) age--; return b.toLocaleDateString('en-US', {month:'short',day:'numeric'}) + ' <small style="color:var(--text-muted);">(' + age + ' yrs)</small>'; })(d.date_of_birth) : 'N/A') + '</div>' +
                            '<div><strong style="color:var(--text-muted);font-size:11px;text-transform:uppercase;">Bank Account</strong><br>' + (d.bank_account || 'N/A') + '</div>' +
                            '<div><strong style="color:var(--text-muted);font-size:11px;text-transform:uppercase;">Warehouse</strong><br>' + (d.warehouse_name || 'N/A') + '</div>' +
                        '</div>' +
                    '</div>' +
                    '<div style="flex-shrink:0;display:flex;gap:8px;">' +
                        '<button class="btn btn-primary btn-sm" onclick="showSupplierReport(\'' + d.supplier_id + '\', \'' + (d.first_name || '').replace(/'/g, "\\'") + '\')" style="padding:6px 12px;font-size:12px;"><i class="fas fa-file-alt"></i> Report</button>' +
                        (canUpdate ? '<button class="btn btn-secondary btn-sm" onclick=\'editSupplier(' + JSON.stringify(d).replace(/'/g, "\\'") + ')\' style="padding:6px 12px;font-size:12px;"><i class="fas fa-edit"></i> Edit</button>' : '') +
                    '</div>' +
                '</div>' +
            '</div>';
        }

        function initializeDataTable(data) {
            if (suppliersTable) {
                suppliersTable.destroy();
                $('#suppliersTable').empty();
            }

            const columns = [
                { data: 'supplier_id', title: 'ID' },
                { data: 'first_name', title: 'Name' },
                {
                    data: 'phone',
                    title: 'Phone',
                    render: function(data, type, row) {
                        if (!data) return '<span style="color:#999">N/A</span>';
                        var clean = (data || '').replace(/\s+/g, '');
                        var display = formatPhone(data);
                        var html = '<a href="tel:' + clean + '" style="color:var(--text-primary);text-decoration:none;" title="Call">' + display + '</a>';
                        // wa icon
                        var waNum = row.whatsapp_phone || clean;
                        waNum = waNum.replace(/[^0-9]/g, '');
                        html += ' <a href="https://wa.me/' + waNum + '" target="_blank" title="WhatsApp" style="color:#25D366;font-size:14px;"><i class="fab fa-whatsapp"></i></a>';
                        return html;
                    }
                },
                { data: 'location_name', title: 'Location', render: function(data) { return data || '<span style="color:#999">N/A</span>'; } },
                { data: 'procurement_region', title: 'Procurement Region', render: function(data) { return data || '<span style="color:#999">N/A</span>'; } },
                { data: 'supplier_type_name', title: 'Supplier Type', render: function(data) { return data || '<span style="color:#999">N/A</span>'; } },
                {
                    data: 'agreed_price_per_kg',
                    title: 'Price/Kg',
                    render: function(data, type, row) {
                        var price = data || row.typical_price_per_kg;
                        if (price === null || price === '' || price === undefined) return '<span style="color:#999">N/A</span>';
                        var formatted = parseFloat(price).toLocaleString('en-US', { maximumFractionDigits: 0 });
                        if (data) {
                            return '<span title="From Price Agreement">' + formatted + '</span>';
                        }
                        return '<span style="opacity:.7;" title="Typical (no agreement)">' + formatted + '</span>';
                    }
                },
                {
                    data: 'account_balance',
                    title: 'Account Bal.',
                    render: function(data) {
                        var val = parseFloat(data) || 0;
                        var formatted = Math.abs(val).toLocaleString('en-US', {maximumFractionDigits: 0});
                        if (val > 0.01) {
                            // positive = net advance (they owe us deliveries) = BLUE, arrow IN (goods owed to us)
                            return '<span style="color:#0074D9;font-weight:600;" title="Net advance — they owe us"><i class="fas fa-caret-down" style="font-size:13px;margin-right:4px;"></i>+ ' + formatted + ' F</span>';
                        } else if (val < -0.01) {
                            // negative = payable (we owe them) = RED, arrow OUT (money leaving us)
                            return '<span style="color:#e74c3c;font-weight:600;" title="Payable — we owe them"><i class="fas fa-caret-up" style="font-size:13px;margin-right:4px;"></i>- ' + formatted + ' F</span>';
                        } else {
                            return '<span style="color:#27ae60;font-weight:600;" title="Settled"><i class="fas fa-check" style="font-size:11px;margin-right:4px;"></i>0.00</span>';
                        }
                    }
                },
                {
                    data: 'status',
                    title: 'Status',
                    render: function(data) {
                        if (data === 'Active') {
                            return '<span class="status-badge status-active"><i class="fas fa-check-circle"></i> Active</span>';
                        } else {
                            return '<span class="status-badge status-inactive"><i class="fas fa-times-circle"></i> Inactive</span>';
                        }
                    }
                }
            ];

            // Add actions column (report always visible, edit/delete based on permissions)
            columns.push({
                data: null,
                title: 'Actions',
                orderable: false,
                render: function(data, type, row) {
                    var actions = '';

                    actions += '<button class="action-icon" onclick="showSupplierReport(\'' + row.supplier_id + '\', \'' + (row.first_name || '').replace(/'/g, "\\'") + '\')" title="Report" style="color:#0074D9;"><i class="fas fa-file-alt"></i></button> ';

                    if (canUpdate) {
                        actions += '<button class="action-icon edit-icon" onclick=\'editSupplier(' + JSON.stringify(row).replace(/'/g, "\\'") + ')\' title="Edit"><i class="fas fa-edit"></i></button>';

                        var toggleIcon = row.status === 'Active' ? 'fa-toggle-on' : 'fa-toggle-off';
                        var toggleColor = row.status === 'Active' ? 'style="color:#34a853"' : 'style="color:#ea4335"';
                        var toggleTitle = row.status === 'Active' ? 'Deactivate' : 'Activate';
                        actions += '<button class="action-icon" onclick="toggleStatus(\'' + row.supplier_id + '\')" title="' + toggleTitle + '" ' + toggleColor + '><i class="fas ' + toggleIcon + '"></i></button>';
                    }

                    if (canDelete) {
                        actions += '<button class="action-icon delete-icon" onclick="deleteSupplier(\'' + row.supplier_id + '\')" title="Delete"><i class="fas fa-trash"></i></button>';
                    }

                    return actions;
                }
            });

            setTimeout(() => {
                suppliersTable = $('#suppliersTable').DataTable({
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
                            exportOptions: { columns: ':not(:last-child)' }
                        },
                        {
                            extend: 'pdf',
                            text: '<i class="fas fa-file-pdf"></i> PDF',
                            exportOptions: { columns: ':not(:last-child)' }
                        },
                        {
                            extend: 'print',
                            text: '<i class="fas fa-print"></i> Print',
                            exportOptions: { columns: ':not(:last-child)' }
                        }
                    ],
                    order: [[0, 'desc']]
                });

                // row click to expand details
                $('#suppliersTable tbody').on('click', 'tr td:not(:last-child)', function() {
                    var tr = $(this).closest('tr');
                    var row = suppliersTable.row(tr);
                    if (row.child.isShown()) {
                        row.child.hide();
                        tr.removeClass('shown');
                    } else {
                        // close other open rows
                        suppliersTable.rows('.shown').every(function() {
                            this.child.hide();
                            $(this.node()).removeClass('shown');
                        });
                        row.child(renderSupplierDetail(row.data())).show();
                        tr.addClass('shown');
                    }
                });

                // Apply filters on change
                $('#filterStatus, #filterLocation, #filterSupplierType').on('change', function() {
                    applyFilters();
                });
            }, 100);
        }

        function applyFilters() {
            if (!suppliersTable) return;

            $.fn.dataTable.ext.search = [];

            const status = document.getElementById('filterStatus').value;
            const location = document.getElementById('filterLocation').value;
            const supplierType = document.getElementById('filterSupplierType').value;

            // Status filter
            if (status) {
                $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
                    const rowStatus = suppliersData[dataIndex]?.status;
                    return rowStatus === status;
                });
            }

            // Location filter (column index 3)
            if (location) {
                suppliersTable.column(3).search('^' + location.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '$', true, false);
            } else {
                suppliersTable.column(3).search('');
            }

            // Supplier Type filter (column index 5)
            if (supplierType) {
                suppliersTable.column(5).search('^' + supplierType.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '$', true, false);
            } else {
                suppliersTable.column(5).search('');
            }

            suppliersTable.draw();
        }

        function clearFilters() {
            document.getElementById('filterStatus').value = '';
            document.getElementById('filterLocation').value = '';
            document.getElementById('filterSupplierType').value = '';

            if (suppliersTable) {
                $.fn.dataTable.ext.search = [];
                suppliersTable.columns().search('').draw();
            }
        }

        <?php if ($canCreate || $canUpdate): ?>
        function openAddModal() {
            isEditMode = false;
            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-truck-field"></i> Add Supplier';
            document.getElementById('supplierForm').reset();
            document.getElementById('supplierId').value = '';
            document.getElementById('procurementRegion').value = '';
            document.getElementById('supplierIdInfo').style.display = 'none';

            // show photo section in Add mode — files queued, uploaded after Save
            document.getElementById('photoUploadSection').style.display = 'block';
            document.getElementById('photoAddHint').style.display = 'block';
            document.getElementById('profilePhotoUploadBtn').style.display = 'none';
            document.getElementById('idPhotoUploadBtn').style.display = 'none';
            document.getElementById('profilePhotoFile').value = '';
            document.getElementById('idPhotoFile').value = '';
            document.getElementById('profilePhotoPreview').innerHTML = '<span style="color:#999;font-size:12px;">No photo</span>';
            document.getElementById('idPhotoPreview').innerHTML = '<span style="color:#999;font-size:12px;">No photo</span>';

            document.getElementById('supplierModal').classList.add('active');
        }

        function editSupplier(row) {
            isEditMode = true;
            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit"></i> Edit Supplier';
            document.getElementById('supplierId').value = row.supplier_id;
            document.getElementById('supplierIdDisplay').textContent = row.supplier_id;
            document.getElementById('supplierIdInfo').style.display = 'block';
            document.getElementById('firstName').value = row.first_name || '';
            document.getElementById('phone').value = row.phone || '';
            // WhatsApp
            var waPhone = row.whatsapp_phone || '';
            if (waPhone) {
                document.getElementById('whatsappSameAsPhone').checked = false;
                document.getElementById('whatsappPhone').style.display = 'block';
                document.getElementById('whatsappPhone').value = waPhone;
            } else {
                document.getElementById('whatsappSameAsPhone').checked = true;
                document.getElementById('whatsappPhone').style.display = 'none';
                document.getElementById('whatsappPhone').value = '';
            }
            document.getElementById('idNumber').value = row.id_number || '';
            document.getElementById('dateOfBirth').value = row.date_of_birth || '';
            document.getElementById('locationId').value = row.location_id || '';
            document.getElementById('supplierTypeId').value = row.supplier_type_id || '';
            document.getElementById('typicalPrice').value = row.typical_price_per_kg || '';
            document.getElementById('bankAccount').value = row.bank_account || '';
            document.getElementById('procurementRegion').value = row.procurement_region || '';
            document.getElementById('closerWarehouseId').value = row.closer_warehouse_id || '';

            // Show photo upload section in edit mode (with Upload buttons, no add-mode hint)
            document.getElementById('photoUploadSection').style.display = 'block';
            document.getElementById('photoAddHint').style.display = 'none';
            document.getElementById('profilePhotoUploadBtn').style.display = '';
            document.getElementById('idPhotoUploadBtn').style.display = '';
            document.getElementById('profilePhotoFile').value = '';
            document.getElementById('idPhotoFile').value = '';

            // Show photo previews if available
            var profilePreview = document.getElementById('profilePhotoPreview');
            var idPreview = document.getElementById('idPhotoPreview');
            profilePreview.innerHTML = row.profile_photo ? '<img src="' + row.profile_photo + '" style="max-width:100px;max-height:80px;border-radius:4px;border:1px solid #ddd;" alt="Profile">' : '<span style="color:#999;font-size:12px;">No photo</span>';
            idPreview.innerHTML = row.id_photo ? '<img src="' + row.id_photo + '" style="max-width:100px;max-height:80px;border-radius:4px;border:1px solid #ddd;" alt="ID">' : '<span style="color:#999;font-size:12px;">No photo</span>';

            document.getElementById('supplierModal').classList.add('active');
        }

        function closeModal() {
            document.getElementById('supplierModal').classList.remove('active');
            document.getElementById('supplierForm').reset();
            document.getElementById('procurementRegion').value = '';
            document.getElementById('photoUploadSection').style.display = 'none';
        }

        function uploadPhoto(photoType) {
            var fileInput = (photoType === 'profile') ? document.getElementById('profilePhotoFile') : document.getElementById('idPhotoFile');
            var supplierId = document.getElementById('supplierId').value;

            if (!supplierId) {
                Swal.fire({ icon: 'warning', title: 'Save First', text: 'Please save the supplier before uploading photos.' });
                return;
            }
            if (!fileInput.files || fileInput.files.length === 0) {
                Swal.fire({ icon: 'warning', title: 'No File', text: 'Please select a file to upload.' });
                return;
            }

            var formData = new FormData();
            formData.append('supplier_id', supplierId);
            formData.append('photo_type', photoType);
            formData.append('photo', fileInput.files[0]);

            Swal.fire({ title: 'Uploading...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });

            $.ajax({
                url: '?action=uploadSupplierPhoto',
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        Swal.fire({ icon: 'success', title: 'Uploaded!', text: response.message, timer: 2000, showConfirmButton: false });
                        var previewId = (photoType === 'profile') ? 'profilePhotoPreview' : 'idPhotoPreview';
                        document.getElementById(previewId).innerHTML = '<img src="' + response.filename + '?t=' + Date.now() + '" style="max-width:100px;max-height:80px;border-radius:4px;border:1px solid #ddd;" alt="' + photoType + '">';
                        fileInput.value = '';
                        setTimeout(() => loadSuppliers(), 100);
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: response.message });
                    }
                },
                error: function(xhr, status, error) {
                    Swal.fire({ icon: 'error', title: 'Error', text: 'Upload failed: ' + error });
                }
            });
        }

        document.getElementById('supplierModal').addEventListener('click', function(e) {
            if (e.target === this) closeModal();
        });

        document.getElementById('supplierForm').addEventListener('submit', function(e) {
            e.preventDefault();

            const formData = new FormData(this);
            const action = isEditMode ? 'updateSupplier' : 'addSupplier';

            // capture queued files BEFORE the form is reset on close
            var queuedProfile = isEditMode ? null : (document.getElementById('profilePhotoFile').files[0] || null);
            var queuedId = isEditMode ? null : (document.getElementById('idPhotoFile').files[0] || null);

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
                    if (!response.success) {
                        Swal.fire({ icon: 'error', title: 'Error', text: response.message });
                        return;
                    }

                    var newSupplierId = response.supplier_id || null;

                    // upload any queued photos using the freshly created supplier_id
                    var pending = [];
                    if (newSupplierId && queuedProfile) pending.push(uploadPhotoFile(newSupplierId, 'profile', queuedProfile));
                    if (newSupplierId && queuedId) pending.push(uploadPhotoFile(newSupplierId, 'id', queuedId));

                    if (pending.length === 0) {
                        Swal.fire({ icon:'success', title:'Success!', text:response.message, timer:2000, showConfirmButton:false });
                        closeModal();
                        setTimeout(() => loadSuppliers(), 100);
                        return;
                    }

                    Swal.fire({ title:'Uploading photos...', allowOutsideClick:false, didOpen:()=>Swal.showLoading() });
                    Promise.all(pending).then(function(results) {
                        var failed = results.filter(function(r) { return !r.success; });
                        if (failed.length > 0) {
                            Swal.fire({ icon:'warning', title:'Saved with photo issues', text:'Supplier saved, but some photos failed: ' + failed.map(f => f.message).join('; ') });
                        } else {
                            Swal.fire({ icon:'success', title:'Saved!', text:response.message + ' Photos uploaded.', timer:2000, showConfirmButton:false });
                        }
                        closeModal();
                        setTimeout(() => loadSuppliers(), 100);
                    });
                },
                error: function(xhr, status, error) {
                    Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error: ' + error });
                }
            });
        });

        // promise wrapper around the photo upload endpoint — used by post-save auto-upload
        function uploadPhotoFile(supplierId, photoType, file) {
            return new Promise(function(resolve) {
                var fd = new FormData();
                fd.append('supplier_id', supplierId);
                fd.append('photo_type', photoType);
                fd.append('photo', file);
                $.ajax({
                    url: '?action=uploadSupplierPhoto',
                    method: 'POST',
                    data: fd,
                    processData: false,
                    contentType: false,
                    dataType: 'json',
                    success: function(r) { resolve(r); },
                    error: function(xhr, status, err) { resolve({ success:false, message: photoType + ' upload failed: ' + err }); }
                });
            });
        }
        <?php endif; ?>

        <?php if ($canUpdate): ?>
        function toggleStatus(supplierId) {
            Swal.fire({
                icon: 'question',
                title: 'Toggle Supplier Status?',
                text: 'This will activate or deactivate the supplier',
                showCancelButton: true,
                confirmButtonText: 'Yes, proceed',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    const formData = new FormData();
                    formData.append('supplier_id', supplierId);

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
                                setTimeout(() => loadSuppliers(), 100);
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
        function deleteSupplier(supplierId) {
            Swal.fire({
                icon: 'warning',
                title: 'Delete Supplier?',
                text: 'This action cannot be undone. The supplier will be permanently removed.',
                showCancelButton: true,
                confirmButtonColor: '#ea4335',
                confirmButtonText: 'Yes, delete it',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    const formData = new FormData();
                    formData.append('supplier_id', supplierId);

                    $.ajax({
                        url: '?action=deleteSupplier',
                        method: 'POST',
                        data: formData,
                        processData: false,
                        contentType: false,
                        dataType: 'json',
                        success: function(response) {
                            if (response.success) {
                                Swal.fire({ icon: 'success', title: 'Deleted!', text: response.message, timer: 2000, showConfirmButton: false });
                                setTimeout(() => loadSuppliers(), 100);
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

<!-- Report Modal Styles -->
<style>
.report-tabs { display: flex; border-bottom: 2px solid var(--border-color); background: var(--bg-card); padding: 0 20px; gap: 0; overflow-x: auto; }
.report-tab { padding: 12px 20px; border: none; background: transparent; color: var(--text-muted); font-size: 13px; font-weight: 600; cursor: pointer; border-bottom: 3px solid transparent; transition: all 0.3s; font-family: inherit; display: flex; align-items: center; gap: 6px; white-space: nowrap; }
.report-tab:hover { color: var(--navy-accent); background: rgba(0,116,217,0.05); }
.report-tab.active { color: var(--navy-accent); border-bottom-color: var(--navy-accent); }
.report-summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin-bottom: 16px; }
.report-summary-card { background: var(--bg-primary); border-radius: 8px; padding: 14px; text-align: center; }
.report-summary-card .val { font-size: 22px; font-weight: 700; color: var(--navy-accent); }
.report-summary-card .lbl { font-size: 11px; color: var(--text-muted); text-transform: uppercase; margin-top: 4px; }
.report-table { width: 100%; border-collapse: collapse; font-size: 12px; }
.report-table thead th { background: var(--navy-primary); color: white; padding: 8px 10px; text-align: left; font-size: 11px; font-weight: 600; }
.report-table tbody td { padding: 8px 10px; border-bottom: 1px solid var(--border-light); color: var(--text-primary); }
.report-table tbody tr:hover { background: var(--table-hover); }
@media (max-width: 768px) {
    #reportModal .modal { max-width: 98% !important; width: 98% !important; margin: 10px auto; }
    .report-summary { grid-template-columns: repeat(2, 1fr); }
    .report-table { font-size: 11px; }
    .report-table thead th, .report-table tbody td { padding: 6px 6px; }
    .report-tab { padding: 10px 12px; font-size: 12px; }
}
#suppliersTable tbody tr { cursor: pointer; }
#suppliersTable tbody tr.shown { background: var(--bg-secondary) !important; }
#suppliersTable tbody tr.shown td { border-bottom: none; }
</style>

<!-- Report Modal HTML -->
<div id="reportModal" class="modal-overlay" onclick="if(event.target===this)closeReportModal()">
    <div class="modal" style="max-width:80%;width:80%;">
        <div class="modal-header">
            <h3 id="reportTitle"><i class="fas fa-file-alt"></i> Supplier Report</h3>
            <div style="display:flex;gap:8px;align-items:center;">
                <button class="btn btn-primary btn-sm" onclick="printReport()" style="padding:6px 14px;font-size:12px;"><i class="fas fa-print"></i> Print</button>
                <button class="close-btn" onclick="closeReportModal()">&times;</button>
            </div>
        </div>
        <div class="modal-body" style="padding:0;">
            <div id="reportTabs" class="report-tabs">
                <button class="report-tab active" onclick="switchReportTab('purchases', this)"><i class="fas fa-cart-shopping"></i> Purchases</button>
                <button class="report-tab" onclick="switchReportTab('payments', this)"><i class="fas fa-credit-card"></i> Payments</button>
                <button class="report-tab" onclick="switchReportTab('financing', this)"><i class="fas fa-money-bill-transfer"></i> Financing</button>
                <button class="report-tab" onclick="switchReportTab('transactions', this)"><i class="fas fa-scroll"></i> Transactions Log</button>
                <button class="report-tab" onclick="switchReportTab('performance', this)"><i class="fas fa-chart-bar"></i> Performance</button>
            </div>
            <div id="reportContent" style="padding:20px;">
                <div class="skeleton" style="height:200px;"></div>
            </div>
        </div>
    </div>
</div>

<script>
    // --- Supplier Report Functions ---
    var reportData = null;
    var currentReportTab = 'purchases';

    function fmtR(n) {
        var num = parseFloat(n) || 0;
        return num.toLocaleString('en-US', { maximumFractionDigits: 0 });
    }

    function fmtDate(d) {
        if (!d) return 'N/A';
        var dt = new Date(d);
        if (isNaN(dt.getTime())) return d;
        var day = ('0' + dt.getDate()).slice(-2);
        var mon = ('0' + (dt.getMonth() + 1)).slice(-2);
        var yr = dt.getFullYear();
        return day + '/' + mon + '/' + yr;
    }

    function showSupplierReport(supplierId, supplierName) {
        reportData = null;
        currentReportTab = 'purchases';
        document.getElementById('reportTitle').innerHTML = '<i class="fas fa-file-alt"></i> Supplier Report: ' + (supplierName || supplierId);
        document.getElementById('reportContent').innerHTML = '<div class="skeleton" style="height:200px;"></div>';

        // Reset tabs
        var tabs = document.querySelectorAll('#reportTabs .report-tab');
        tabs.forEach(function(t) { t.classList.remove('active'); });
        tabs[0].classList.add('active');

        document.getElementById('reportModal').classList.add('active');

        $.ajax({
            url: '?action=getSupplierReport&supplier_id=' + encodeURIComponent(supplierId),
            method: 'GET',
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    reportData = response.data;
                    renderReportTab('purchases');
                } else {
                    document.getElementById('reportContent').innerHTML = '<div style="padding:40px;text-align:center;color:#e74c3c;"><i class="fas fa-exclamation-triangle" style="font-size:32px;"></i><p style="margin-top:10px;">' + (response.message || 'Failed to load report') + '</p></div>';
                }
            },
            error: function(xhr, status, error) {
                document.getElementById('reportContent').innerHTML = '<div style="padding:40px;text-align:center;color:#e74c3c;"><i class="fas fa-exclamation-triangle" style="font-size:32px;"></i><p style="margin-top:10px;">Connection error: ' + error + '</p></div>';
            }
        });
    }

    function closeReportModal() {
        document.getElementById('reportModal').classList.remove('active');
        reportData = null;
    }

    function switchReportTab(tab, btn) {
        currentReportTab = tab;
        var tabs = document.querySelectorAll('#reportTabs .report-tab');
        tabs.forEach(function(t) { t.classList.remove('active'); });
        if (btn) btn.classList.add('active');
        if (reportData) {
            renderReportTab(tab);
        }
    }

    function renderReportTab(tab) {
        var html = '';

        if (tab === 'purchases') {
            var purchases = reportData.purchases || [];
            var totalPurchases = purchases.length;
            var totalCost = 0;
            var totalVolume = 0;
            purchases.forEach(function(p) {
                totalCost += parseFloat(p.total_cost) || 0;
                totalVolume += parseFloat(p.weight_kg) || 0;
            });

            // account balance from server: outgoing balance_due - incoming balance_due
            // positive = supplier owes us, negative = we owe supplier
            var balance = parseFloat(reportData.account_balance) || 0;
            var balColor = balance > 0.01 ? '#0074D9' : (balance < -0.01 ? '#e74c3c' : '#27ae60');
            var balLabel = balance > 0.01 ? 'Supplier Owes Us' : (balance < -0.01 ? 'We Owe Supplier' : 'Settled');
            var balIcon = balance > 0.01 ? '<i class="fas fa-caret-down" style="font-size:14px;margin-right:4px;"></i>' : (balance < -0.01 ? '<i class="fas fa-caret-up" style="font-size:14px;margin-right:4px;"></i>' : '<i class="fas fa-check-circle" style="font-size:12px;margin-right:4px;"></i>');

            html += '<div class="report-summary">';
            html += '<div class="report-summary-card"><div class="val">' + totalPurchases + '</div><div class="lbl">Total Purchases</div></div>';
            html += '<div class="report-summary-card"><div class="val">' + fmtR(totalCost) + '</div><div class="lbl">Total Cost</div></div>';
            html += '<div class="report-summary-card"><div class="val">' + fmtR(totalVolume) + '</div><div class="lbl">Total Volume (kg)</div></div>';
            html += '<div class="report-summary-card" style="border:2px solid ' + balColor + ';"><div class="val" style="color:' + balColor + ';font-weight:700;">' + balIcon + fmtR(Math.abs(balance)) + '</div><div class="lbl" style="color:' + balColor + ';font-weight:600;">' + balLabel + '</div></div>';
            html += '</div>';

            if (purchases.length === 0) {
                html += '<div style="text-align:center;padding:30px;color:var(--text-muted);"><i class="fas fa-inbox" style="font-size:32px;"></i><p style="margin-top:10px;">No purchases found for this supplier.</p></div>';
            } else {
                html += '<div style="overflow-x:auto;"><table class="report-table"><thead><tr>';
                html += '<th>Purchase ID</th><th>Date</th><th>Weight (kg)</th><th>Bags</th><th>Price/kg</th><th>Total Cost</th><th>Payment Status</th><th>Warehouse</th><th>Season</th>';
                html += '</tr></thead><tbody>';
                purchases.forEach(function(p) {
                    var statusClass = '';
                    var ps = (p.payment_status || '').toLowerCase();
                    if (ps === 'paid') statusClass = 'color:#27ae60;font-weight:600;';
                    else if (ps === 'partial') statusClass = 'color:#f39c12;font-weight:600;';
                    else if (ps === 'unpaid') statusClass = 'color:#e74c3c;font-weight:600;';

                    html += '<tr>';
                    html += '<td>' + (p.purchase_id || '') + '</td>';
                    html += '<td>' + fmtDate(p.date) + '</td>';
                    html += '<td>' + fmtR(p.weight_kg) + '</td>';
                    html += '<td>' + (p.num_bags || 0) + '</td>';
                    html += '<td>' + fmtR(p.final_price_per_kg) + '</td>';
                    html += '<td>' + fmtR(p.total_cost) + '</td>';
                    html += '<td style="' + statusClass + '">' + (p.payment_status || 'N/A') + '</td>';
                    html += '<td>' + (p.warehouse_name || 'N/A') + '</td>';
                    html += '<td>' + (p.season || 'N/A') + '</td>';
                    html += '</tr>';
                });
                html += '</tbody></table></div>';
            }
        } else if (tab === 'payments') {
            var payments = reportData.payments || [];
            var totalPayments = payments.length;
            var incoming = 0;
            var outgoing = 0;
            payments.forEach(function(p) {
                var amt = parseFloat(p.amount) || 0;
                if ((p.direction || '').toLowerCase() === 'incoming') incoming += amt;
                else outgoing += amt;
            });

            html += '<div class="report-summary">';
            html += '<div class="report-summary-card"><div class="val">' + totalPayments + '</div><div class="lbl">Total Payments</div></div>';
            html += '<div class="report-summary-card"><div class="val" style="color:#27ae60;">' + fmtR(incoming) + '</div><div class="lbl">Incoming</div></div>';
            html += '<div class="report-summary-card"><div class="val" style="color:#e74c3c;">' + fmtR(outgoing) + '</div><div class="lbl">Outgoing</div></div>';
            html += '</div>';

            if (payments.length === 0) {
                html += '<div style="text-align:center;padding:30px;color:var(--text-muted);"><i class="fas fa-inbox" style="font-size:32px;"></i><p style="margin-top:10px;">No payments found for this supplier.</p></div>';
            } else {
                html += '<div style="overflow-x:auto;"><table class="report-table"><thead><tr>';
                html += '<th>Payment ID</th><th>Date</th><th>Direction</th><th>Type</th><th>Amount</th><th>Mode</th><th>Reference</th>';
                html += '</tr></thead><tbody>';
                payments.forEach(function(p) {
                    var dirStyle = (p.direction || '').toLowerCase() === 'incoming' ? 'color:#27ae60;font-weight:600;' : 'color:#e74c3c;font-weight:600;';
                    html += '<tr>';
                    html += '<td>' + (p.payment_id || '') + '</td>';
                    html += '<td>' + fmtDate(p.date) + '</td>';
                    html += '<td style="' + dirStyle + '">' + (p.direction || 'N/A') + '</td>';
                    html += '<td>' + (p.payment_type || 'N/A') + '</td>';
                    html += '<td>' + fmtR(p.amount) + '</td>';
                    html += '<td>' + (p.payment_mode || 'N/A') + '</td>';
                    html += '<td>' + (p.reference_number || 'N/A') + '</td>';
                    html += '</tr>';
                });
                html += '</tbody></table></div>';
            }
        } else if (tab === 'financing') {
            var financing = reportData.financing || [];
            var totalAgreements = 0;
            var totalFinanced = 0; // Outgoing = we gave them
            var totalPayable = 0;  // Incoming = we owe them
            var outstandingOwed = 0; // active outgoing balance (they owe us)
            var outstandingPayable = 0; // active incoming balance (we owe them)

            financing.forEach(function(f) {
                var amt = parseFloat(f.amount) || 0;
                var bal = parseFloat(f.balance_due) || 0;
                if (f.direction === 'Outgoing') {
                    totalFinanced += amt;
                    outstandingOwed += bal;
                    totalAgreements++;
                } else if (f.direction === 'Incoming') {
                    totalPayable += amt;
                    outstandingPayable += bal;
                }
            });

            // total cost delivered (from purchases)
            var totalCostDelivered = 0;
            (reportData.purchases || []).forEach(function(p) {
                totalCostDelivered += parseFloat(p.total_cost) || 0;
            });

            // unified account balance: same as overview tab
            // positive = supplier owes us, negative = we owe them
            var netBalance = parseFloat(reportData.account_balance) || 0;
            var balColor = netBalance > 0.01 ? '#0074D9' : (netBalance < -0.01 ? '#e74c3c' : '#27ae60');
            var balLabel = netBalance > 0.01 ? 'Supplier Owes Us' : (netBalance < -0.01 ? 'We Owe Supplier' : 'Settled');
            var balIcon = netBalance > 0.01 ? '<i class="fas fa-caret-down" style="font-size:14px;margin-right:4px;"></i>' : (netBalance < -0.01 ? '<i class="fas fa-caret-up" style="font-size:14px;margin-right:4px;"></i>' : '<i class="fas fa-check-circle" style="font-size:12px;margin-right:4px;"></i>');

            html += '<div class="report-summary">';
            html += '<div class="report-summary-card"><div class="val">' + fmtR(totalFinanced) + '</div><div class="lbl">Total Advances Given</div></div>';
            html += '<div class="report-summary-card"><div class="val">' + fmtR(totalCostDelivered) + '</div><div class="lbl">Total Cost Delivered</div></div>';
            html += '<div class="report-summary-card" style="border:2px solid ' + balColor + ';"><div class="val" style="color:' + balColor + ';font-weight:700;">' + balIcon + fmtR(Math.abs(netBalance)) + '</div><div class="lbl" style="color:' + balColor + ';font-weight:600;">' + balLabel + '</div></div>';
            html += '</div>';

            // filter: only show Outgoing financing in the table (the financing we gave)
            var outgoing = financing.filter(function(f) { return f.direction === 'Outgoing'; });

            if (outgoing.length === 0) {
                html += '<div style="text-align:center;padding:30px;color:var(--text-muted);"><i class="fas fa-inbox" style="font-size:32px;"></i><p style="margin-top:10px;">No financing history found for this supplier.</p></div>';
            } else {
                html += '<div style="overflow-x:auto;"><table class="report-table"><thead><tr>';
                html += '<th>Financing ID</th><th>Date</th><th>Amount</th><th>Balance</th><th>Status</th>';
                html += '</tr></thead><tbody>';
                outgoing.forEach(function(f) {
                    var statusStyle = '';
                    var st = (f.status || '').toLowerCase();
                    if (st === 'settled') statusStyle = 'color:#27ae60;font-weight:600;';
                    else if (st === 'active') statusStyle = 'color:#0074D9;font-weight:600;';
                    else if (st === 'overdue') statusStyle = 'color:#e74c3c;font-weight:600;';

                    var lineBal = parseFloat(f.balance_due) || 0;

                    html += '<tr>';
                    html += '<td>' + (f.financing_id || '') + '</td>';
                    html += '<td>' + fmtDate(f.date) + '</td>';
                    html += '<td>' + fmtR(f.amount) + '</td>';
                    html += '<td style="font-weight:600;">' + fmtR(lineBal) + '</td>';
                    html += '<td style="' + statusStyle + '">' + (f.status || 'N/A') + '</td>';
                    html += '</tr>';
                });
                html += '</tbody></table></div>';
            }

            // show payable section if we owe them
            var payable = financing.filter(function(f) { return f.direction === 'Incoming' && (parseFloat(f.balance_due) || 0) > 0; });
            if (payable.length > 0) {
                html += '<div style="margin-top:20px;padding:12px 16px;background:#fff5f5;border-left:4px solid #e74c3c;border-radius:4px;">';
                html += '<strong style="color:#e74c3c;"><i class="fas fa-exclamation-triangle"></i> Payable to Supplier</strong>';
                html += '<p style="margin:6px 0 0;color:#666;">We owe this supplier <strong style="color:#e74c3c;">' + fmtR(outstandingPayable) + '</strong> for excess deliveries beyond financing.</p>';
                html += '</div>';
            }
        } else if (tab === 'transactions') {
            var txns = reportData.transactions_log || [];
            var totalFin = 0, totalPur = 0;
            txns.forEach(function(t) {
                var fin = parseFloat(t.financing_amt) || 0;
                if (fin > 0) totalFin += fin;
                totalPur += parseFloat(t.purchase_amt) || 0;
            });
            var netBal = txns.length ? parseFloat(txns[txns.length - 1].new_balance) || 0 : 0;
            var balColor = netBal > 0.01 ? '#0074D9' : (netBal < -0.01 ? '#e74c3c' : '#27ae60');
            var balLabel = netBal > 0.01 ? 'Supplier Owes Us' : (netBal < -0.01 ? 'We Owe Supplier' : 'Settled');
            var balIcon = netBal > 0.01 ? '<i class="fas fa-caret-down" style="font-size:14px;margin-right:4px;"></i>' : (netBal < -0.01 ? '<i class="fas fa-caret-up" style="font-size:14px;margin-right:4px;"></i>' : '<i class="fas fa-check-circle" style="font-size:12px;margin-right:4px;"></i>');

            html += '<div class="report-summary">';
            html += '<div class="report-summary-card"><div class="val">' + txns.length + '</div><div class="lbl">Transactions</div></div>';
            html += '<div class="report-summary-card"><div class="val" style="color:#27ae60;">' + fmtR(totalFin) + '</div><div class="lbl">Total Financing Given</div></div>';
            html += '<div class="report-summary-card"><div class="val" style="color:#e74c3c;">' + fmtR(totalPur) + '</div><div class="lbl">Total Purchase Cost</div></div>';
            html += '<div class="report-summary-card" style="border:2px solid ' + balColor + ';"><div class="val" style="color:' + balColor + ';font-weight:700;">' + balIcon + fmtR(Math.abs(netBal)) + '</div><div class="lbl" style="color:' + balColor + ';font-weight:600;">' + balLabel + '</div></div>';
            html += '</div>';

            if (txns.length === 0) {
                html += '<div style="text-align:center;padding:30px;color:var(--text-muted);"><i class="fas fa-inbox" style="font-size:32px;"></i><p style="margin-top:10px;">No transactions found.</p></div>';
            } else {
                html += '<div style="overflow-x:auto;"><table class="report-table" style="font-size:11px;"><thead><tr>';
                html += '<th>#</th><th>Date</th><th>Description</th><th style="text-align:right;">Prev. Balance</th><th style="text-align:right;">Weight (kg)</th><th style="text-align:right;">Bags</th><th style="text-align:right;">Price/Kg</th><th style="text-align:right;">Purchase Amt</th><th style="text-align:right;">Financing Amt</th><th style="text-align:right;">New Balance</th><th>Mode</th>';
                html += '</tr></thead><tbody>';
                txns.forEach(function(t, i) {
                    var pb = parseFloat(t.prev_balance) || 0;
                    var nb = parseFloat(t.new_balance) || 0;
                    var pbC = pb > 0.01 ? '#0074D9' : (pb < -0.01 ? '#e74c3c' : '#666');
                    var nbC = nb > 0.01 ? '#0074D9' : (nb < -0.01 ? '#e74c3c' : '#27ae60');

                    var dC = '';
                    switch(t.desc) {
                        case 'Advance Financing': dC = '#27ae60'; break;
                        case 'Direct Payment': dC = '#0074D9'; break;
                        case 'Purchase / Delivery': dC = '#f39c12'; break;
                        case 'Supplier Repayment': dC = '#9b59b6'; break;
                        default: dC = '#666';
                    }

                    html += '<tr>';
                    html += '<td>' + (i + 1) + '</td>';
                    html += '<td style="white-space:nowrap;">' + fmtDate(t.date) + '</td>';
                    html += '<td style="font-weight:600;color:' + dC + ';white-space:nowrap;">' + t.desc + '</td>';
                    html += '<td style="text-align:right;color:' + pbC + ';">' + fmtR(pb) + '</td>';
                    html += '<td style="text-align:right;">' + (t.weight_kg ? fmtR(t.weight_kg) : '') + '</td>';
                    html += '<td style="text-align:right;">' + (t.num_bags ? t.num_bags : '') + '</td>';
                    html += '<td style="text-align:right;">' + (t.price_per_kg ? fmtR(t.price_per_kg) : '') + '</td>';

                    // purchase amt: shown in parentheses (reduces balance)
                    html += '<td style="text-align:right;color:#e74c3c;font-weight:600;">' + (t.purchase_amt ? '(' + fmtR(t.purchase_amt) + ')' : '') + '</td>';

                    // financing amt: positive = green, negative (repayment) = red parens
                    var fin = parseFloat(t.financing_amt) || 0;
                    var finHtml = '';
                    if (fin > 0.01) finHtml = '<span style="color:#27ae60;font-weight:600;">' + fmtR(fin) + '</span>';
                    else if (fin < -0.01) finHtml = '<span style="color:#e74c3c;font-weight:600;">(' + fmtR(Math.abs(fin)) + ')</span>';
                    html += '<td style="text-align:right;">' + finHtml + '</td>';

                    html += '<td style="text-align:right;font-weight:700;color:' + nbC + ';">' + (nb < -0.01 ? '(' + fmtR(Math.abs(nb)) + ')' : fmtR(nb)) + '</td>';
                    html += '<td style="font-size:10px;white-space:nowrap;">' + (t.payment_mode || '') + '</td>';
                    html += '</tr>';
                });
                html += '</tbody></table></div>';

                html += '<div style="margin-top:12px;padding:10px 14px;background:var(--bg-primary);border-radius:6px;border-left:3px solid var(--navy-accent);font-size:12px;color:var(--text-muted);">';
                html += '<i class="fas fa-info-circle" style="margin-right:6px;color:var(--navy-accent);"></i>';
                html += '<strong>Balance</strong> = Financing given minus purchase costs and repayments. Positive = supplier owes us. Negative (parentheses) = we owe supplier.';
                html += '</div>';
            }
        } else if (tab === 'performance') {
            var purchases = reportData.purchases || [];
            var payments = reportData.payments || [];
            var financing = reportData.financing || [];

            if (purchases.length === 0) {
                html += '<div style="text-align:center;padding:40px;color:var(--text-muted);"><i class="fas fa-chart-bar" style="font-size:32px;"></i><p style="margin-top:10px;">No transaction data to analyze.</p></div>';
            } else {
                // calc KPIs
                var totalPurchases = purchases.length;
                var totalVolume = 0;
                var totalCost = 0;
                var paidCount = 0;

                purchases.forEach(function(p) {
                    totalVolume += parseFloat(p.weight_kg) || 0;
                    totalCost += parseFloat(p.total_cost) || 0;
                    if (p.payment_status === 'Paid' || p.payment_status === 'Prefinanced') paidCount++;
                });

                var avgPricePerKg = totalVolume > 0 ? totalCost / totalVolume : 0;
                var fulfillmentRate = totalPurchases > 0 ? (paidCount / totalPurchases * 100) : 0;

                // financing efficiency
                var totalFinGiven = 0;
                var totalFinRepaid = 0;
                financing.forEach(function(f) {
                    if (f.direction === 'Outgoing') {
                        totalFinGiven += parseFloat(f.amount) || 0;
                        totalFinRepaid += parseFloat(f.amount_repaid) || 0;
                    }
                });
                var cashEfficiency = totalFinGiven > 0 ? Math.min((totalFinRepaid / totalFinGiven) * 100, 100) : 100;

                // volume consistency (CV-based)
                var volumes = purchases.map(function(p) { return parseFloat(p.weight_kg) || 0; });
                var avgVolume = totalVolume / totalPurchases;
                var variance = 0;
                volumes.forEach(function(v) { variance += Math.pow(v - avgVolume, 2); });
                var stdDev = Math.sqrt(variance / totalPurchases);
                var cv = avgVolume > 0 ? (stdDev / avgVolume) : 1;
                var consistencyScore = Math.max(0, Math.min(100, 100 - cv * 100));

                // payment compliance
                var outgoingPayments = 0;
                payments.forEach(function(p) {
                    if ((p.direction || '').toLowerCase() === 'outgoing') outgoingPayments += parseFloat(p.amount) || 0;
                });

                // weighted score
                var weights = { cashEff: 0.30, fulfillment: 0.10, consistency: 0.05, volume: 0.35, risk: 0.10, reliability: 0.10 };

                // volume score: 50T = 100
                var volumeScore = Math.min(100, (totalVolume / 50000) * 100);

                // risk: inverse of outstanding balance ratio
                var outstandingBal = totalFinGiven - totalFinRepaid;
                var riskScore = totalFinGiven > 0 ? Math.max(0, 100 - (outstandingBal / totalFinGiven) * 100) : 100;

                // reliability: based on purchase count (cap at 20)
                var reliabilityScore = Math.min(100, (totalPurchases / 20) * 100);

                var globalScore = Math.round(
                    weights.cashEff * cashEfficiency +
                    weights.fulfillment * fulfillmentRate +
                    weights.consistency * consistencyScore +
                    weights.volume * volumeScore +
                    weights.risk * riskScore +
                    weights.reliability * reliabilityScore
                );

                var scoreColor = globalScore >= 70 ? '#27ae60' : (globalScore >= 40 ? '#f39c12' : '#e74c3c');
                var scoreLabel = globalScore >= 70 ? 'Good' : (globalScore >= 40 ? 'Average' : 'At Risk');

                // score circle
                html += '<div style="text-align:center;padding:20px 0;">';
                html += '<div style="display:inline-flex;width:100px;height:100px;border-radius:50%;border:6px solid ' + scoreColor + ';align-items:center;justify-content:center;flex-direction:column;">';
                html += '<div style="font-size:28px;font-weight:800;color:' + scoreColor + ';line-height:1;">' + globalScore + '</div>';
                html += '<div style="font-size:11px;color:var(--text-muted);margin-top:2px;">' + scoreLabel + '</div>';
                html += '</div>';
                html += '</div>';

                // KPI breakdown table
                html += '<div style="overflow-x:auto;margin-top:16px;"><table class="report-table"><thead><tr>';
                html += '<th>KPI</th><th>Score</th><th>Weight</th><th>Weighted</th><th>Details</th>';
                html += '</tr></thead><tbody>';

                var kpis = [
                    { name: 'Cash Efficiency', score: cashEfficiency, weight: 30, detail: fmtR(totalFinRepaid) + ' / ' + fmtR(totalFinGiven) + ' repaid' },
                    { name: 'Volume', score: volumeScore, weight: 35, detail: fmtR(totalVolume) + ' kg total' },
                    { name: 'Fulfillment', score: fulfillmentRate, weight: 10, detail: paidCount + ' / ' + totalPurchases + ' settled' },
                    { name: 'Consistency', score: consistencyScore, weight: 5, detail: 'CV: ' + cv.toFixed(2) },
                    { name: 'Risk', score: riskScore, weight: 10, detail: fmtR(Math.max(0, outstandingBal)) + ' outstanding' },
                    { name: 'Reliability', score: reliabilityScore, weight: 10, detail: totalPurchases + ' purchases' }
                ];

                kpis.forEach(function(k) {
                    var barColor = k.score >= 70 ? '#27ae60' : (k.score >= 40 ? '#f39c12' : '#e74c3c');
                    var weighted = Math.round(k.score * k.weight / 100);
                    html += '<tr>';
                    html += '<td style="font-weight:600;">' + k.name + '</td>';
                    html += '<td><div style="display:flex;align-items:center;gap:8px;"><div style="flex:1;background:#e0e0e0;border-radius:4px;height:8px;min-width:80px;"><div style="width:' + Math.round(k.score) + '%;height:100%;background:' + barColor + ';border-radius:4px;"></div></div><span style="font-size:12px;font-weight:600;min-width:35px;">' + Math.round(k.score) + '</span></div></td>';
                    html += '<td>' + k.weight + '%</td>';
                    html += '<td style="font-weight:600;">' + weighted + '</td>';
                    html += '<td style="color:var(--text-muted);font-size:12px;">' + k.detail + '</td>';
                    html += '</tr>';
                });

                html += '</tbody></table></div>';

                // quick stats
                html += '<div class="report-summary" style="margin-top:16px;">';
                html += '<div class="report-summary-card"><div class="val">' + totalPurchases + '</div><div class="lbl">Total Purchases</div></div>';
                html += '<div class="report-summary-card"><div class="val">' + fmtR(totalVolume) + '</div><div class="lbl">Total Volume (kg)</div></div>';
                html += '<div class="report-summary-card"><div class="val">' + fmtR(avgPricePerKg) + '</div><div class="lbl">Avg Price/Kg</div></div>';
                html += '</div>';
            }
        }

        document.getElementById('reportContent').innerHTML = html;
    }

    function printReport() {
        var content = document.getElementById('reportContent').innerHTML;
        var title = document.getElementById('reportTitle').innerText;
        var tabName = currentReportTab.charAt(0).toUpperCase() + currentReportTab.slice(1);

        var printWin = window.open('', '_blank', 'width=900,height=700');
        printWin.document.write('<!DOCTYPE html><html><head><title>' + title + ' - ' + tabName + '</title>');
        printWin.document.write('<style>');
        printWin.document.write('body { font-family: Arial, sans-serif; padding: 20px; color: #333; }');
        printWin.document.write('h2 { color: #001f3f; border-bottom: 2px solid #001f3f; padding-bottom: 8px; }');
        printWin.document.write('.report-summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; margin-bottom: 16px; }');
        printWin.document.write('.report-summary-card { background: #f5f7fa; border-radius: 8px; padding: 14px; text-align: center; }');
        printWin.document.write('.report-summary-card .val { font-size: 20px; font-weight: 700; color: #001f3f; }');
        printWin.document.write('.report-summary-card .lbl { font-size: 11px; color: #666; text-transform: uppercase; margin-top: 4px; }');
        printWin.document.write('.report-table { width: 100%; border-collapse: collapse; font-size: 12px; }');
        printWin.document.write('.report-table thead th { background: #001f3f; color: white; padding: 8px 10px; text-align: left; font-size: 11px; }');
        printWin.document.write('.report-table tbody td { padding: 8px 10px; border-bottom: 1px solid #ddd; }');
        printWin.document.write('</style></head><body>');
        printWin.document.write('<h2>' + title + ' &mdash; ' + tabName + '</h2>');
        printWin.document.write(content);
        printWin.document.write('</body></html>');
        printWin.document.close();
        printWin.focus();
        setTimeout(function() { printWin.print(); }, 400);
    }
</script>

</body>
</html>
