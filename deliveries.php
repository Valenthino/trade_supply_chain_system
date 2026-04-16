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
$current_page = 'deliveries';

// RBAC
$allowedRoles = ['Admin', 'Manager', 'Sales Officer', 'Warehouse Clerk', 'Fleet Manager', 'Procurement Officer'];
if (!in_array($role, $allowedRoles)) {
    header("Location: dashboard.php");
    exit();
}

$canCreate = in_array($role, ['Admin', 'Manager', 'Sales Officer', 'Warehouse Clerk', 'Procurement Officer']);
$canUpdate = in_array($role, ['Admin', 'Manager', 'Sales Officer', 'Warehouse Clerk', 'Procurement Officer']);
$canDelete = ($role === 'Admin');
$isReadOnly = ($role === 'Fleet Manager');

// auto-reduce customer advance financing when we deliver products
function autoReduceCustomerAdvance($conn, $customerId, $deliveryId, $weightKg) {
    if (!$customerId || $weightKg <= 0) return;

    // find active customer advances (Incoming from Customer, FIFO)
    $fq = $conn->prepare("SELECT financing_id, amount, carried_over_balance, amount_repaid, balance_due, delivered_volume_kg, current_market_price, expected_volume_kg, interest_per_kg FROM financing WHERE counterparty_id = ? AND counterpart_type = 'Customer' AND direction = 'Incoming' AND status = 'Active' AND balance_due > 0 ORDER BY date ASC");
    $fq->bind_param("s", $customerId);
    $fq->execute();
    $rows = $fq->get_result()->fetch_all(MYSQLI_ASSOC);
    $fq->close();

    if (empty($rows)) return;

    $remainKg = $weightKg;

    foreach ($rows as $fr) {
        if ($remainKg <= 0) break;
        $bal = floatval($fr['balance_due']);
        if ($bal <= 0) continue;

        $agreedPrice = floatval($fr['current_market_price']);
        if ($agreedPrice <= 0) continue;

        // how much kg this financing still needs
        $volRemaining = floatval($fr['expected_volume_kg']) - floatval($fr['delivered_volume_kg']);
        if ($volRemaining <= 0) $volRemaining = $bal / $agreedPrice;

        $kgToApply = min($remainKg, $volRemaining);
        $valueDelivered = round($kgToApply * $agreedPrice, 2);
        $valueDelivered = min($valueDelivered, $bal);

        $newRepaid = round(floatval($fr['amount_repaid']) + $valueDelivered, 2);
        $newDeliv = round(floatval($fr['delivered_volume_kg']) + $kgToApply, 2);
        $newVolRem = round(max(floatval($fr['expected_volume_kg']) - $newDeliv, 0), 2);

        // recalc interest based on new delivered volume
        $interestPerKg = floatval($fr['interest_per_kg'] ?? 0);
        $newInterest = round($interestPerKg * $newDeliv, 2);

        // balance = amount + carried_over + interest - repaid
        $amt = floatval($fr['amount']);
        $carried = floatval($fr['carried_over_balance'] ?? 0);
        $newBal = round($amt + $carried + $newInterest - $newRepaid, 2);
        if ($newBal < 0) $newBal = 0;
        $newSt = ($newBal <= 0) ? 'Settled' : 'Active';

        $uf = $conn->prepare("UPDATE financing SET amount_repaid = ?, balance_due = ?, delivered_volume_kg = ?, volume_remaining_kg = ?, interest_amount = ?, status = ? WHERE financing_id = ?");
        $uf->bind_param("dddddss", $newRepaid, $newBal, $newDeliv, $newVolRem, $newInterest, $newSt, $fr['financing_id']);
        $uf->execute();
        $uf->close();

        $remainKg -= $kgToApply;
    }
}

// sync customers.financing_balance
// Running Balance = Advances from Customer + Payments from Customer - Product Value Delivered
// positive = we owe them product, negative = they owe us money
function syncCustomerFinancingBalance($conn, $customerId) {
    if (!$customerId) return;

    // advances from customer (Incoming financing)
    $sb = $conn->prepare("SELECT COALESCE(SUM(amount), 0) as tb FROM financing WHERE counterparty_id = ? AND counterpart_type = 'Customer' AND direction = 'Incoming'");
    $sb->bind_param("s", $customerId);
    $sb->execute();
    $advances = floatval($sb->get_result()->fetch_assoc()['tb']);
    $sb->close();

    // payments received from customer
    $sb2 = $conn->prepare("SELECT COALESCE(SUM(amount), 0) as tb FROM payments WHERE counterpart_id = ? AND direction = 'Incoming'");
    $sb2->bind_param("s", $customerId);
    $sb2->execute();
    $paymentsIn = floatval($sb2->get_result()->fetch_assoc()['tb']);
    $sb2->close();

    // product value delivered (sales)
    $sb3 = $conn->prepare("SELECT COALESCE(SUM(gross_sale_amount), 0) as tb FROM sales WHERE customer_id = ? AND sale_status IN ('Draft','Confirmed')");
    $sb3->bind_param("s", $customerId);
    $sb3->execute();
    $productValue = floatval($sb3->get_result()->fetch_assoc()['tb']);
    $sb3->close();

    $netBal = round($advances + $paymentsIn - $productValue, 2);

    $chk = $conn->query("SHOW COLUMNS FROM customers LIKE 'financing_balance'");
    if ($chk && $chk->num_rows > 0) {
        $us = $conn->prepare("UPDATE customers SET financing_balance = ? WHERE customer_id = ?");
        $us->bind_param("ds", $netBal, $customerId);
        $us->execute();
        $us->close();
    }
}

// Handle AJAX requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    try {
        switch ($_GET['action']) {
            case 'getDeliveries':
                $conn = getDBConnection();
                $stmt = $conn->prepare("SELECT d.*, c.customer_name as cust_display_name, w.warehouse_name
                    FROM deliveries d
                    LEFT JOIN customers c ON d.customer_id = c.customer_id
                    LEFT JOIN settings_warehouses w ON d.origin_warehouse_id = w.warehouse_id
                    ORDER BY d.delivery_id DESC");
                $stmt->execute();
                $result = $stmt->get_result();

                $deliveries = [];
                while ($row = $result->fetch_assoc()) {
                    $deliveries[] = [
                        'delivery_id' => $row['delivery_id'],
                        'date' => date('M d, Y', strtotime($row['date'])),
                        'date_raw' => $row['date'],
                        'customer_id' => $row['customer_id'],
                        'customer_name' => $row['customer_name'],
                        'origin_warehouse_id' => $row['origin_warehouse_id'],
                        'warehouse_name' => $row['warehouse_name'] ?? '',
                        'vehicle_id' => $row['vehicle_id'],
                        'driver_name' => $row['driver_name'],
                        'vehicle_type' => $row['vehicle_type'] ?? 'Owned',
                        'rental_driver_name' => $row['rental_driver_name'] ?? null,
                        'rental_driver_phone' => $row['rental_driver_phone'] ?? null,
                        'rental_vehicle_reg' => $row['rental_vehicle_reg'] ?? null,
                        'weight_kg' => $row['weight_kg'],
                        'num_bags' => $row['num_bags'],
                        'procurement_cost_per_kg' => $row['procurement_cost_per_kg'],
                        'transport_cost' => $row['transport_cost'],
                        'road_fees' => $row['road_fees'] ?? 0,
                        'loading_cost' => $row['loading_cost'],
                        'other_cost' => $row['other_cost'],
                        'total_cost' => $row['total_cost'],
                        'status' => $row['status'],
                        'rejection_reason' => $row['rejection_reason'],
                        'rejection_date' => $row['rejection_date'] ?? null,
                        'reassigned_to' => $row['reassigned_to'],
                        'reassigned_from_delivery_id' => $row['reassigned_from_delivery_id'],
                        'weight_at_destination' => $row['weight_at_destination'],
                        'season' => $row['season'],
                        'notes' => $row['notes']
                    ];
                }

                $stmt->close();

                // batch-fetch delivery_items for all deliveries (avoid N+1)
                $itemsByDelivery = [];
                if (!empty($deliveries)) {
                    $itemRes = $conn->query("SELECT delivery_id, purchase_id, lot_id, lot_number, supplier_name, quantity_kg, cost_per_kg, total_cost FROM delivery_items ORDER BY id ASC");
                    if ($itemRes) {
                        while ($it = $itemRes->fetch_assoc()) {
                            $did = $it['delivery_id'];
                            if (!isset($itemsByDelivery[$did])) $itemsByDelivery[$did] = [];
                            $itemsByDelivery[$did][] = [
                                'purchase_id' => $it['purchase_id'],
                                'lot_id' => intval($it['lot_id']),
                                'lot_number' => $it['lot_number'],
                                'supplier_name' => $it['supplier_name'],
                                'quantity_kg' => floatval($it['quantity_kg']),
                                'cost_per_kg' => floatval($it['cost_per_kg']),
                                'total_cost' => floatval($it['total_cost'])
                            ];
                        }
                    }
                }
                foreach ($deliveries as &$d) {
                    $d['items'] = $itemsByDelivery[$d['delivery_id']] ?? [];
                }
                unset($d);

                $conn->close();

                echo json_encode(['success' => true, 'data' => $deliveries]);
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

                // Active warehouses
                $warehouses = [];
                $stmt = $conn->prepare("SELECT w.warehouse_id, w.warehouse_name, l.location_name
                    FROM settings_warehouses w
                    LEFT JOIN settings_locations l ON w.location_id = l.location_id
                    WHERE w.is_active = 1
                    ORDER BY w.warehouse_name ASC");
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $warehouses[] = $row;
                }
                $stmt->close();

                // Fleet vehicles
                $vehicles = [];
                $stmt = $conn->prepare("SELECT vehicle_id, vehicle_registration, vehicle_model, driver_name FROM fleet_vehicles WHERE status IN ('Available','On Trip') ORDER BY vehicle_registration ASC");
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $vehicles[] = $row;
                }
                $stmt->close();

                // locations for quick-add customer
                $locations = [];
                $stmt = $conn->prepare("SELECT location_id, location_name FROM settings_locations WHERE is_active = 1 ORDER BY location_name ASC");
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $locations[] = $row;
                }
                $stmt->close();

                $conn->close();

                echo json_encode([
                    'success' => true,
                    'data' => [
                        'customers' => $customers,
                        'warehouses' => $warehouses,
                        'vehicles' => $vehicles,
                        'locations' => $locations
                    ]
                ]);
                exit();

            case 'getAvailableLots':
                $warehouseId  = isset($_GET['warehouse_id']) ? intval($_GET['warehouse_id']) : 0;
                $excludeDelId = !empty($_GET['exclude_delivery_id']) ? trim($_GET['exclude_delivery_id']) : '';
                if ($warehouseId <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Warehouse ID required']);
                    exit();
                }

                $conn = getDBConnection();

                // ensure delivery_items table exists
                @$conn->query("CREATE TABLE IF NOT EXISTS delivery_items (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    delivery_id VARCHAR(20) NOT NULL,
                    purchase_id VARCHAR(20) NOT NULL,
                    lot_id INT NULL, lot_number VARCHAR(10) NULL, supplier_name VARCHAR(150) NULL,
                    quantity_kg DECIMAL(12,2) NOT NULL,
                    cost_per_kg DECIMAL(10,2) NOT NULL,
                    total_cost DECIMAL(15,2) NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_di_delivery (delivery_id), INDEX idx_di_purchase (purchase_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

                // get lots for this warehouse
                $lots = [];
                $stmt = $conn->prepare("SELECT lot_id, lot_number, status, current_weight_kg, total_cost, avg_cost_per_kg, purchase_count FROM lots WHERE warehouse_id = ? ORDER BY lot_id ASC");
                $stmt->bind_param("i", $warehouseId);
                $stmt->execute();
                $lotRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();

                foreach ($lotRows as $lot) {
                    // loaded_kg = total of all delivery_items linked to this purchase, except items belonging to the delivery we're editing
                    $pStmt = $conn->prepare("SELECT p.purchase_id, p.supplier_name, p.weight_kg, p.final_price_per_kg, p.date,
                        COALESCE((SELECT SUM(di.quantity_kg) FROM delivery_items di WHERE di.purchase_id = p.purchase_id AND (? = '' OR di.delivery_id <> ?)), 0) as loaded_kg
                        FROM purchases p WHERE p.lot_id = ? ORDER BY p.date ASC");
                    $pStmt->bind_param("ssi", $excludeDelId, $excludeDelId, $lot['lot_id']);
                    $pStmt->execute();
                    $purchases = $pStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                    $pStmt->close();

                    $items = [];
                    $lotAvailable = 0;
                    foreach ($purchases as $p) {
                        $avail = round(floatval($p['weight_kg']) - floatval($p['loaded_kg']), 2);
                        if ($avail <= 0) continue;
                        $lotAvailable += $avail;
                        $items[] = [
                            'purchase_id' => $p['purchase_id'],
                            'supplier_name' => $p['supplier_name'],
                            'date' => $p['date'],
                            'weight_kg' => floatval($p['weight_kg']),
                            'loaded_kg' => floatval($p['loaded_kg']),
                            'available_kg' => $avail,
                            'cost_per_kg' => floatval($p['final_price_per_kg'])
                        ];
                    }

                    if (empty($items)) continue; // skip lots with nothing available

                    $lots[] = [
                        'lot_id' => intval($lot['lot_id']),
                        'lot_number' => $lot['lot_number'],
                        'status' => $lot['status'],
                        'total_weight' => floatval($lot['current_weight_kg']),
                        'avg_cost' => floatval($lot['avg_cost_per_kg']),
                        'available_kg' => $lotAvailable,
                        'purchases' => $items
                    ];
                }

                $conn->close();
                echo json_encode(['success' => true, 'data' => $lots]);
                exit();

            case 'getCustomerDetails':
                $customerId = isset($_GET['customer_id']) ? trim($_GET['customer_id']) : '';
                if (empty($customerId)) {
                    echo json_encode(['success' => false, 'message' => 'Customer ID required']);
                    exit();
                }

                $conn = getDBConnection();
                $stmt = $conn->prepare("SELECT customer_name FROM customers WHERE customer_id = ?");
                $stmt->bind_param("s", $customerId);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows > 0) {
                    $row = $result->fetch_assoc();
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => true, 'data' => $row]);
                } else {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Customer not found']);
                }
                exit();

            case 'addDelivery':
                if (!$canCreate) {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $date = isset($_POST['date']) ? trim($_POST['date']) : '';
                $customerId = isset($_POST['customer_id']) ? trim($_POST['customer_id']) : '';
                $originWarehouseId = !empty($_POST['origin_warehouse_id']) ? intval($_POST['origin_warehouse_id']) : null;
                $vehicleId = !empty($_POST['vehicle_id']) ? trim($_POST['vehicle_id']) : null;
                $driverName = !empty($_POST['driver_name']) ? trim($_POST['driver_name']) : null;
                $vehicleType = isset($_POST['vehicle_type']) ? trim($_POST['vehicle_type']) : 'Owned';
                $rentalDriverName = !empty($_POST['rental_driver_name']) ? trim($_POST['rental_driver_name']) : null;
                $rentalDriverPhone = !empty($_POST['rental_driver_phone']) ? trim($_POST['rental_driver_phone']) : null;
                $rentalVehicleReg = !empty($_POST['rental_vehicle_reg']) ? trim($_POST['rental_vehicle_reg']) : null;
                $weightKg = isset($_POST['weight_kg']) ? floatval($_POST['weight_kg']) : 0;
                $numBags = isset($_POST['num_bags']) ? intval($_POST['num_bags']) : 0;
                $transportCost = isset($_POST['transport_cost']) ? floatval($_POST['transport_cost']) : 0;
                $roadFees = isset($_POST['road_fees']) ? floatval($_POST['road_fees']) : 0;
                $loadingCost = isset($_POST['loading_cost']) ? floatval($_POST['loading_cost']) : 0;
                $otherCost = isset($_POST['other_cost']) ? floatval($_POST['other_cost']) : 0;
                $season = isset($_POST['season']) ? trim($_POST['season']) : getActiveSeason();
                $notes = !empty($_POST['notes']) ? trim($_POST['notes']) : null;
                $selectedItems = !empty($_POST['selected_items']) ? json_decode($_POST['selected_items'], true) : [];

                // Validation
                if (empty($date)) {
                    echo json_encode(['success' => false, 'message' => 'Date is required']);
                    exit();
                }
                if (empty($customerId)) {
                    echo json_encode(['success' => false, 'message' => 'Customer is required']);
                    exit();
                }
                if ($weightKg <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Weight must be greater than 0']);
                    exit();
                }
                if (empty($season)) {
                    echo json_encode(['success' => false, 'message' => 'Season is required']);
                    exit();
                }

                $conn = getDBConnection();

                // Auto-generate delivery_id (LIV-YY-MMDD-XXXX-C)
                $newId = generateTransactionId($conn, 'LIV', 'deliveries', 'delivery_id');

                // Get customer name
                $customerName = '';
                $stmt = $conn->prepare("SELECT customer_name FROM customers WHERE customer_id = ?");
                $stmt->bind_param("s", $customerId);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($res->num_rows > 0) {
                    $customerName = $res->fetch_assoc()['customer_name'];
                }
                $stmt->close();

                // Compute total_cost (logistics only) — now includes road_fees
                $totalCost = round($transportCost + $roadFees + $loadingCost + $otherCost, 2);

                // For Rental vehicles ignore the owned-vehicle/driver dropdown values
                if ($vehicleType === 'Rental') { $vehicleId = null; $driverName = null; }
                else { $rentalDriverName = null; $rentalDriverPhone = null; $rentalVehicleReg = null; }

                // calc procurement cost: from lot picker items if provided, else warehouse avg
                $procCostPerKg = 0;
                if (!empty($selectedItems)) {
                    $itemTotalCost = 0; $itemTotalKg = 0;
                    foreach ($selectedItems as $it) {
                        $qty = floatval($it['quantity_kg'] ?? 0);
                        $cpk = floatval($it['cost_per_kg'] ?? 0);
                        $itemTotalCost += $qty * $cpk;
                        $itemTotalKg += $qty;
                    }
                    $procCostPerKg = $itemTotalKg > 0 ? round($itemTotalCost / $itemTotalKg, 2) : 0;
                    if ($itemTotalKg > 0) $weightKg = round($itemTotalKg, 2);
                } elseif ($originWarehouseId) {
                    $pq = $conn->prepare("SELECT AVG(final_price_per_kg) as avg_price FROM purchases WHERE warehouse_id = ? AND final_price_per_kg > 0");
                    $pq->bind_param("i", $originWarehouseId);
                    $pq->execute();
                    $pr = $pq->get_result()->fetch_assoc();
                    if ($pr && $pr['avg_price']) $procCostPerKg = round(floatval($pr['avg_price']), 2);
                    $pq->close();
                }

                $whVal = $originWarehouseId !== null ? strval($originWarehouseId) : null;
                $vehVal = $vehicleId;
                $drvVal = $driverName ?? '';
                $notesVal = $notes ?? '';
                $vtVal = in_array($vehicleType, ['Owned', 'Rental']) ? $vehicleType : 'Owned';

                $stmt = $conn->prepare("INSERT INTO deliveries (delivery_id, date, customer_id, customer_name, origin_warehouse_id, vehicle_id, driver_name, vehicle_type, rental_driver_name, rental_driver_phone, rental_vehicle_reg, weight_kg, num_bags, procurement_cost_per_kg, transport_cost, road_fees, loading_cost, other_cost, total_cost, status, season, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'In Transit', ?, ?)");
                // 21 params: 13 strings, 1 decimal (procurement), 7 strings (transport/road/loading/other/total/season/notes)
                $stmt->bind_param("sssssssssssssdsssssss",
                    $newId, $date, $customerId, $customerName, $whVal,
                    $vehVal, $drvVal, $vtVal, $rentalDriverName, $rentalDriverPhone, $rentalVehicleReg,
                    $weightKg, $numBags,
                    $procCostPerKg, $transportCost, $roadFees, $loadingCost, $otherCost, $totalCost,
                    $season, $notesVal
                );

                if ($stmt->execute()) {
                    $stmt->close();

                    // save delivery_items from lot picker
                    if (!empty($selectedItems)) {
                        $diStmt = $conn->prepare("INSERT INTO delivery_items (delivery_id, purchase_id, lot_id, lot_number, supplier_name, quantity_kg, cost_per_kg, total_cost) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                        foreach ($selectedItems as $it) {
                            $diPid = $it['purchase_id'];
                            $diLot = intval($it['lot_id'] ?? 0);
                            $diLotNr = $it['lot_number'] ?? '';
                            $diSupplier = $it['supplier_name'] ?? '';
                            $diQty = round(floatval($it['quantity_kg']), 2);
                            $diCpk = round(floatval($it['cost_per_kg']), 2);
                            $diTc = round($diQty * $diCpk, 2);
                            $diStmt->bind_param("sssissdd", $newId, $diPid, $diLot, $diLotNr, $diSupplier, $diQty, $diCpk, $diTc);
                            $diStmt->execute();
                        }
                        $diStmt->close();
                    }

                    // auto-log bags out
                    if ($numBags > 0) {
                        $bagDesc = "Delivery to $customerName";
                        $bagTruck = $vehicleType === 'Owned' ? $vehVal : null;
                        $bagDriver = $vehicleType === 'Owned' ? $drvVal : ($rentalDriverName ?? '');
                        logBagMovement($conn, $date, $customerId, null, $bagDesc, 0, $numBags, $newId, $bagTruck, $bagDriver, $season);
                    }

                    // auto-reduce customer advances on delivery + sync balance
                    autoReduceCustomerAdvance($conn, $customerId, $newId, $weightKg);
                    syncCustomerFinancingBalance($conn, $customerId);

                    logActivity($user_id, $username, 'Delivery Created', "Created delivery: $newId for customer $customerName ($customerId), {$weightKg}kg");
                    $conn->close();
                    echo json_encode(['success' => true, 'message' => 'Delivery added successfully', 'delivery_id' => $newId]);
                } else {
                    $error = $stmt->error;
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to add delivery: ' . $error]);
                }
                exit();

            case 'updateDelivery':
                if (!$canUpdate) {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $deliveryId = isset($_POST['delivery_id']) ? trim($_POST['delivery_id']) : '';
                $date = isset($_POST['date']) ? trim($_POST['date']) : '';
                $customerId = isset($_POST['customer_id']) ? trim($_POST['customer_id']) : '';
                $originWarehouseId = !empty($_POST['origin_warehouse_id']) ? intval($_POST['origin_warehouse_id']) : null;
                $vehicleId = !empty($_POST['vehicle_id']) ? trim($_POST['vehicle_id']) : null;
                $driverName = !empty($_POST['driver_name']) ? trim($_POST['driver_name']) : null;
                $vehicleType = isset($_POST['vehicle_type']) ? trim($_POST['vehicle_type']) : 'Owned';
                $rentalDriverName = !empty($_POST['rental_driver_name']) ? trim($_POST['rental_driver_name']) : null;
                $rentalDriverPhone = !empty($_POST['rental_driver_phone']) ? trim($_POST['rental_driver_phone']) : null;
                $rentalVehicleReg = !empty($_POST['rental_vehicle_reg']) ? trim($_POST['rental_vehicle_reg']) : null;
                $weightKg = isset($_POST['weight_kg']) ? floatval($_POST['weight_kg']) : 0;
                $numBags = isset($_POST['num_bags']) ? intval($_POST['num_bags']) : 0;
                $transportCost = isset($_POST['transport_cost']) ? floatval($_POST['transport_cost']) : 0;
                $roadFees = isset($_POST['road_fees']) ? floatval($_POST['road_fees']) : 0;
                $loadingCost = isset($_POST['loading_cost']) ? floatval($_POST['loading_cost']) : 0;
                $otherCost = isset($_POST['other_cost']) ? floatval($_POST['other_cost']) : 0;
                $status = isset($_POST['status']) ? trim($_POST['status']) : 'Pending';
                $rejectionReason = !empty($_POST['rejection_reason']) ? trim($_POST['rejection_reason']) : null;
                $weightAtDest = !empty($_POST['weight_at_destination']) ? floatval($_POST['weight_at_destination']) : null;
                $season = isset($_POST['season']) ? trim($_POST['season']) : getActiveSeason();
                $notes = !empty($_POST['notes']) ? trim($_POST['notes']) : null;
                $selectedItems = !empty($_POST['selected_items']) ? json_decode($_POST['selected_items'], true) : null;

                // Validation
                if (empty($deliveryId)) {
                    echo json_encode(['success' => false, 'message' => 'Delivery ID is required']);
                    exit();
                }
                if (empty($date)) {
                    echo json_encode(['success' => false, 'message' => 'Date is required']);
                    exit();
                }
                if (empty($customerId)) {
                    echo json_encode(['success' => false, 'message' => 'Customer is required']);
                    exit();
                }
                if ($weightKg <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Weight must be greater than 0']);
                    exit();
                }
                if ($status === 'Rejected' && empty($rejectionReason)) {
                    echo json_encode(['success' => false, 'message' => 'Rejection reason is required when status is Rejected']);
                    exit();
                }

                $conn = getDBConnection();

                // Validate status transition
                $stmt = $conn->prepare("SELECT status FROM deliveries WHERE delivery_id = ?");
                $stmt->bind_param("s", $deliveryId);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($res->num_rows === 0) {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Delivery not found']);
                    exit();
                }
                $currentStatus = $res->fetch_assoc()['status'];
                $stmt->close();

                $validTransitions = [
                    'Pending' => ['Pending', 'In Transit'],
                    'In Transit' => ['In Transit'],
                    'Delivered' => ['Delivered'],
                    'Rejected' => ['Rejected'],
                    'Reassigned' => ['Reassigned']
                ];

                if (!in_array($status, $validTransitions[$currentStatus] ?? [])) {
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => "Cannot change status from '$currentStatus' to '$status'"]);
                    exit();
                }

                // Get customer name
                $customerName = '';
                $stmt = $conn->prepare("SELECT customer_name FROM customers WHERE customer_id = ?");
                $stmt->bind_param("s", $customerId);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($res->num_rows > 0) {
                    $customerName = $res->fetch_assoc()['customer_name'];
                }
                $stmt->close();

                // Compute total_cost (logistics only) — now includes road_fees
                $totalCost = round($transportCost + $roadFees + $loadingCost + $otherCost, 2);

                // For Rental vehicles ignore the owned-vehicle/driver dropdown values (and vice versa)
                if ($vehicleType === 'Rental') { $vehicleId = null; $driverName = null; }
                else { $rentalDriverName = null; $rentalDriverPhone = null; $rentalVehicleReg = null; }

                // procurement cost: from selected_items if provided, else warehouse avg
                $procCostPerKg = 0;
                if (is_array($selectedItems) && !empty($selectedItems)) {
                    $itemTotalCost = 0; $itemTotalKg = 0;
                    foreach ($selectedItems as $it) {
                        $qty = floatval($it['quantity_kg'] ?? 0);
                        $cpk = floatval($it['cost_per_kg'] ?? 0);
                        $itemTotalCost += $qty * $cpk;
                        $itemTotalKg += $qty;
                    }
                    $procCostPerKg = $itemTotalKg > 0 ? round($itemTotalCost / $itemTotalKg, 2) : 0;
                    if ($itemTotalKg > 0) $weightKg = round($itemTotalKg, 2);
                } elseif ($originWarehouseId) {
                    $pq = $conn->prepare("SELECT AVG(final_price_per_kg) as avg_price FROM purchases WHERE warehouse_id = ? AND final_price_per_kg > 0");
                    $pq->bind_param("i", $originWarehouseId);
                    $pq->execute();
                    $pr = $pq->get_result()->fetch_assoc();
                    if ($pr && $pr['avg_price']) $procCostPerKg = round(floatval($pr['avg_price']), 2);
                    $pq->close();
                }

                $whVal = $originWarehouseId !== null ? strval($originWarehouseId) : null;
                $vehVal = $vehicleId;
                $drvVal = $driverName ?? '';
                $vtVal = in_array($vehicleType, ['Owned', 'Rental']) ? $vehicleType : 'Owned';
                $rejVal = $rejectionReason;
                $wadVal = $weightAtDest !== null ? strval($weightAtDest) : null;
                $notesVal = $notes ?? '';

                $stmt = $conn->prepare("UPDATE deliveries SET date = ?, customer_id = ?, customer_name = ?, origin_warehouse_id = ?, vehicle_id = ?, driver_name = ?, vehicle_type = ?, rental_driver_name = ?, rental_driver_phone = ?, rental_vehicle_reg = ?, weight_kg = ?, num_bags = ?, procurement_cost_per_kg = ?, transport_cost = ?, road_fees = ?, loading_cost = ?, other_cost = ?, total_cost = ?, status = ?, rejection_reason = ?, weight_at_destination = ?, season = ?, notes = ? WHERE delivery_id = ?");
                // 24 params: 12 strings, 1 decimal (proc), 11 strings (transport, road_fees, loading, other, total, status, rejection, weightAtDest, season, notes, deliveryId)
                $stmt->bind_param("ssssssssssssdsssssssssss",
                    $date, $customerId, $customerName, $whVal,
                    $vehVal, $drvVal, $vtVal, $rentalDriverName, $rentalDriverPhone, $rentalVehicleReg,
                    $weightKg, $numBags,
                    $procCostPerKg, $transportCost, $roadFees, $loadingCost, $otherCost, $totalCost,
                    $status, $rejVal, $wadVal, $season, $notesVal, $deliveryId
                );

                if ($stmt->execute()) {
                    $stmt->close();

                    // if the user touched the lot picker (selected_items present), rebuild the delivery_items rows
                    if (is_array($selectedItems)) {
                        $del = $conn->prepare("DELETE FROM delivery_items WHERE delivery_id = ?");
                        $del->bind_param("s", $deliveryId);
                        $del->execute();
                        $del->close();

                        if (!empty($selectedItems)) {
                            $diStmt = $conn->prepare("INSERT INTO delivery_items (delivery_id, purchase_id, lot_id, lot_number, supplier_name, quantity_kg, cost_per_kg, total_cost) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                            foreach ($selectedItems as $it) {
                                $diPid = $it['purchase_id'];
                                $diLot = intval($it['lot_id'] ?? 0);
                                $diLotNr = $it['lot_number'] ?? '';
                                $diSupplier = $it['supplier_name'] ?? '';
                                $diQty = round(floatval($it['quantity_kg']), 2);
                                $diCpk = round(floatval($it['cost_per_kg']), 2);
                                $diTc = round($diQty * $diCpk, 2);
                                $diStmt->bind_param("sssissdd", $deliveryId, $diPid, $diLot, $diLotNr, $diSupplier, $diQty, $diCpk, $diTc);
                                $diStmt->execute();
                            }
                            $diStmt->close();
                        }
                    }

                    // sync bag log: update qty or create if missing
                    if ($numBags > 0) {
                        $chk = $conn->prepare("SELECT bag_log_id FROM bags_log WHERE ref_number = ?");
                        $chk->bind_param("s", $deliveryId);
                        $chk->execute();
                        if ($chk->get_result()->num_rows > 0) {
                            updateBagLogByRef($conn, $deliveryId, 0, $numBags);
                        } else {
                            $custN = $customerName ?: '';
                            logBagMovement($conn, $date, $customerId, null, "Delivery to $custN", 0, $numBags, $deliveryId, ($vehicleType === 'Owned' ? $vehicleId : null), ($vehicleType === 'Owned' ? $driverName : $rentalDriverName), $season);
                        }
                        $chk->close();
                    } else {
                        removeBagLogByRef($conn, $deliveryId);
                    }

                    logActivity($user_id, $username, 'Delivery Updated', "Updated delivery: $deliveryId (Status: $status)");
                    $conn->close();
                    echo json_encode(['success' => true, 'message' => 'Delivery updated successfully']);
                } else {
                    $error = $stmt->error;
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to update delivery: ' . $error]);
                }
                exit();

            case 'reassignDelivery':
                if (!$canUpdate) {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $deliveryId = isset($_POST['delivery_id']) ? trim($_POST['delivery_id']) : '';
                $newCustomerId = isset($_POST['new_customer_id']) ? trim($_POST['new_customer_id']) : '';
                $reason = isset($_POST['reason']) ? trim($_POST['reason']) : '';

                if (empty($deliveryId) || empty($newCustomerId)) {
                    echo json_encode(['success' => false, 'message' => 'Delivery ID and new customer are required']);
                    exit();
                }

                $conn = getDBConnection();

                // Get original delivery
                $stmt = $conn->prepare("SELECT * FROM deliveries WHERE delivery_id = ?");
                $stmt->bind_param("s", $deliveryId);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($res->num_rows === 0) {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Delivery not found']);
                    exit();
                }
                $original = $res->fetch_assoc();
                $stmt->close();
                // allow reassign from In Transit or Rejected
                $reassignAllowed = ['In Transit', 'Rejected'];
                if (!in_array($original['status'], $reassignAllowed)) {
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => "Cannot reassign a delivery with status '{$original['status']}'"]);
                    exit();
                }

                // get new customer name
                $stmt = $conn->prepare("SELECT customer_name FROM customers WHERE customer_id = ?");
                $stmt->bind_param("s", $newCustomerId);
                $stmt->execute();
                $newCust = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$newCust) {
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'New customer not found']);
                    exit();
                }
                $newCustomerName = $newCust['customer_name'];

                // Generate new delivery ID (LIV-YY-MMDD-XXXX-C)
                $newDelId = generateTransactionId($conn, 'LIV', 'deliveries', 'delivery_id');

                // Set original delivery to Reassigned (implies rejection by original customer)
                $today = date('Y-m-d');
                $rejReason = $reason ?: 'Reassigned to another customer';
                $stmt = $conn->prepare("UPDATE deliveries SET status = 'Reassigned', reassigned_to = ?, rejection_reason = ?, rejection_date = ? WHERE delivery_id = ?");
                $stmt->bind_param("ssss", $newCustomerId, $rejReason, $today, $deliveryId);
                $stmt->execute();
                $stmt->close();

                // Create new delivery — use 's' types for all nullable fields to avoid PHP 8.2 null+int crash
                $oWhId = $original['origin_warehouse_id'] !== null ? strval($original['origin_warehouse_id']) : null;
                $oVehId = $original['vehicle_id'];
                $oDriver = $original['driver_name'] ?? '';
                $oWeight = strval($original['weight_kg']);
                $oBags = strval($original['num_bags']);
                $oTransport = strval($original['transport_cost'] ?? 0);
                $oLoading = strval($original['loading_cost'] ?? 0);
                $oOther = strval($original['other_cost'] ?? 0);
                $oTotal = strval($original['total_cost'] ?? 0);
                $oSeason = $original['season'];
                $reassignNote = "Reassigned from $deliveryId";
                if ($reason) $reassignNote .= " — Reason: $reason";

                $stmt = $conn->prepare("INSERT INTO deliveries (delivery_id, date, customer_id, customer_name, origin_warehouse_id, vehicle_id, driver_name, weight_kg, num_bags, transport_cost, loading_cost, other_cost, total_cost, status, reassigned_from_delivery_id, season, notes) VALUES (?, CURDATE(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'In Transit', ?, ?, ?)");
                $stmt->bind_param("sssssssssssssss",
                    $newDelId, $newCustomerId, $newCustomerName, $oWhId,
                    $oVehId, $oDriver,
                    $oWeight, $oBags,
                    $oTransport, $oLoading, $oOther, $oTotal,
                    $deliveryId, $oSeason, $reassignNote
                );

                if ($stmt->execute()) {
                    $logMsg = "Reassigned delivery $deliveryId from {$original['customer_name']} to $newCustomerName ($newCustomerId). New delivery: $newDelId";
                    if ($reason) $logMsg .= " — Reason: $reason";
                    logActivity($user_id, $username, 'Delivery Reassigned', $logMsg);
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => true, 'message' => "Delivery reassigned to $newCustomerName. New delivery: $newDelId", 'new_delivery_id' => $newDelId]);
                } else {
                    $error = $stmt->error;
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to create reassigned delivery: ' . $error]);
                }
                exit();

            case 'rejectDelivery':
                if (!$canUpdate) {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                $deliveryId = isset($_POST['delivery_id']) ? trim($_POST['delivery_id']) : '';
                $reason = isset($_POST['rejection_reason']) ? trim($_POST['rejection_reason']) : '';

                if (empty($deliveryId) || empty($reason)) {
                    echo json_encode(['success' => false, 'message' => 'Delivery ID and reason required']);
                    exit();
                }

                $conn = getDBConnection();

                // get delivery info for log
                $stmt = $conn->prepare("SELECT customer_id, customer_name FROM deliveries WHERE delivery_id = ?");
                $stmt->bind_param("s", $deliveryId);
                $stmt->execute();
                $delInfo = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                $today = date('Y-m-d');
                $stmt = $conn->prepare("UPDATE deliveries SET status = 'Rejected', rejection_reason = ?, rejection_date = ? WHERE delivery_id = ?");
                $stmt->bind_param("sss", $reason, $today, $deliveryId);

                if ($stmt->execute()) {
                    $custName = $delInfo ? $delInfo['customer_name'] : '';
                    logActivity($user_id, $username, 'Delivery Rejected', "Rejected delivery $deliveryId by $custName: $reason");
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => true, 'message' => 'Delivery rejected successfully']);
                } else {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to reject delivery']);
                }
                exit();

            case 'deleteDelivery':
                if (!$canDelete) {
                    echo json_encode(['success' => false, 'message' => 'Access denied. Only Admin can delete.']);
                    exit();
                }

                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $deliveryId = isset($_POST['delivery_id']) ? trim($_POST['delivery_id']) : '';
                if (empty($deliveryId)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid delivery ID']);
                    exit();
                }

                $conn = getDBConnection();

                // Check for linked sales
                $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM sales WHERE delivery_id = ?");
                $stmt->bind_param("s", $deliveryId);
                $stmt->execute();
                $cnt = $stmt->get_result()->fetch_assoc()['cnt'];
                $stmt->close();

                if ($cnt > 0) {
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => "Cannot delete — delivery has $cnt linked sale(s)"]);
                    exit();
                }

                // Check for linked expenses
                $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM expenses WHERE linked_delivery_id = ?");
                $stmt->bind_param("s", $deliveryId);
                $stmt->execute();
                $cntExp = $stmt->get_result()->fetch_assoc()['cnt'];
                $stmt->close();

                if ($cntExp > 0) {
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => "Cannot delete — delivery has $cntExp linked expense(s)"]);
                    exit();
                }

                // Get info for logging
                $stmt = $conn->prepare("SELECT customer_name, weight_kg FROM deliveries WHERE delivery_id = ?");
                $stmt->bind_param("s", $deliveryId);
                $stmt->execute();
                $info = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                // remove linked bag log entry
                removeBagLogByRef($conn, $deliveryId);

                $stmt = $conn->prepare("DELETE FROM deliveries WHERE delivery_id = ?");
                $stmt->bind_param("s", $deliveryId);

                if ($stmt->execute()) {
                    logActivity($user_id, $username, 'Delivery Deleted', "Deleted delivery: $deliveryId (Customer: {$info['customer_name']}, {$info['weight_kg']}kg)");
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => true, 'message' => 'Delivery deleted successfully']);
                } else {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to delete delivery']);
                }
                exit();

            case 'getDeliveryReceipt':
                $conn = getDBConnection();
                $delivery_id = $_GET['delivery_id'] ?? '';

                $stmt = $conn->prepare("SELECT d.*, c.customer_name as cust_display_name, w.warehouse_name,
                    fv.vehicle_registration, fv.vehicle_model, fv.driver_name as vehicle_driver_name, fv.phone_number as driver_phone
                    FROM deliveries d
                    LEFT JOIN customers c ON d.customer_id = c.customer_id
                    LEFT JOIN settings_warehouses w ON d.origin_warehouse_id = w.warehouse_id
                    LEFT JOIN fleet_vehicles fv ON d.vehicle_id = fv.vehicle_id
                    WHERE d.delivery_id = ?");
                $stmt->bind_param("s", $delivery_id);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result && $row = $result->fetch_assoc()) {
                    $stmt->close();
                    $conn->close();

                    $companyInfo = getCompanyInfo();

                    echo json_encode([
                        'success' => true,
                        'data' => [
                            'delivery_id' => $row['delivery_id'],
                            'date' => $row['date'],
                            'customer_id' => $row['customer_id'],
                            'customer_name' => $row['cust_display_name'] ?? $row['customer_name'],
                            'warehouse_name' => $row['warehouse_name'] ?? '',
                            'vehicle_id' => $row['vehicle_id'],
                            'vehicle_registration' => $row['vehicle_registration'] ?? '',
                            'vehicle_model' => $row['vehicle_model'] ?? '',
                            'driver_name' => $row['driver_name'],
                            'vehicle_driver_name' => $row['vehicle_driver_name'] ?? '',
                            'driver_phone' => $row['driver_phone'] ?? '',
                            'vehicle_type' => $row['vehicle_type'] ?? 'Owned',
                            'rental_driver_name' => $row['rental_driver_name'] ?? '',
                            'rental_driver_phone' => $row['rental_driver_phone'] ?? '',
                            'rental_vehicle_reg' => $row['rental_vehicle_reg'] ?? '',
                            'weight_kg' => $row['weight_kg'],
                            'num_bags' => $row['num_bags'],
                            'procurement_cost_per_kg' => $row['procurement_cost_per_kg'],
                            'transport_cost' => $row['transport_cost'],
                            'loading_cost' => $row['loading_cost'],
                            'other_cost' => $row['other_cost'],
                            'total_cost' => $row['total_cost'],
                            'status' => $row['status'],
                            'rejection_reason' => $row['rejection_reason'],
                            'weight_at_destination' => $row['weight_at_destination'],
                            'season' => $row['season'],
                            'notes' => $row['notes']
                        ],
                        'company_name' => $companyInfo['company_name'] ?? '7503 Canada',
                        'companyInfo' => $companyInfo
                    ]);
                } else {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Delivery not found']);
                }
                exit();

            case 'quickAddCustomer':
                if (!$canCreate) {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }
                $name = isset($_POST['customer_name']) ? trim($_POST['customer_name']) : '';
                $phone = isset($_POST['phone']) ? trim($_POST['phone']) : null;
                $contactPerson = isset($_POST['contact_person']) ? trim($_POST['contact_person']) : null;
                $locationId = isset($_POST['location_id']) ? intval($_POST['location_id']) : null;

                if (empty($name)) {
                    echo json_encode(['success' => false, 'message' => 'Customer name is required']);
                    exit();
                }

                $conn = getDBConnection();
                // generate customer_id: CUST-XXX
                $seqRes = $conn->query("SELECT COUNT(*) + 1 as n FROM customers");
                $seq = $seqRes->fetch_assoc()['n'];
                $customerId = 'CUST-' . str_pad($seq, 3, '0', STR_PAD_LEFT);
                // make sure unique
                while (true) {
                    $chk = $conn->prepare("SELECT customer_id FROM customers WHERE customer_id = ?");
                    $chk->bind_param("s", $customerId);
                    $chk->execute();
                    if ($chk->get_result()->num_rows === 0) { $chk->close(); break; }
                    $chk->close();
                    $seq++;
                    $customerId = 'CUST-' . str_pad($seq, 3, '0', STR_PAD_LEFT);
                }

                $locVal = $locationId > 0 ? $locationId : null;
                $stmt = $conn->prepare("INSERT INTO customers (customer_id, customer_name, phone, contact_person, location_id) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param("ssssi", $customerId, $name, $phone, $contactPerson, $locVal);

                if ($stmt->execute()) {
                    logActivity($user_id, $username, 'Customer Created', "Quick-added customer: $name ($customerId) from Delivery Out");
                    $stmt->close(); $conn->close();
                    echo json_encode(['success' => true, 'message' => 'Customer added', 'customer_id' => $customerId, 'customer_name' => $name]);
                } else {
                    $err = $stmt->error; $stmt->close(); $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed: ' . $err]);
                }
                exit();

            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
                exit();
        }
    } catch (\Throwable $e) {
        error_log("deliveries.php error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
        exit();
    }
}
?>
<!-- Developed by Rameez Scripts — https://www.youtube.com/@rameezimdad -->
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="mobile-web-app-capable" content="yes">
  <title>Commodity Flow — Deliveries</title>

  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      darkMode: 'class',
      theme: {
        extend: {
          fontFamily: { sans: ['Inter', 'system-ui', 'sans-serif'] },
          colors: {
            brand: { 50:'#f0f9f9',100:'#d9f2f0',200:'#b5e6e3',300:'#82d3cf',400:'#4db8b4',500:'#2d9d99',600:'#247f7c',700:'#1d6462',800:'#185150',900:'#164342' },
            slate: { 850: '#172032' }
          },
          boxShadow: { 'card':'0 1px 3px 0 rgba(0,0,0,0.06), 0 1px 2px -1px rgba(0,0,0,0.04)', 'card-hover':'0 4px 12px 0 rgba(0,0,0,0.08)' }
        }
      }
    }
  </script>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
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
    ::-webkit-scrollbar { width: 5px; height: 5px; }
    ::-webkit-scrollbar-track { background: transparent; }
    ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
    .dark ::-webkit-scrollbar-thumb { background: #334155; }
    @keyframes shimmer { 0%{background-position:-400px 0} 100%{background-position:400px 0} }
    .skeleton { background: linear-gradient(90deg, #f1f5f9 25%, #e2e8f0 50%, #f1f5f9 75%); background-size: 400px 100%; animation: shimmer 1.4s ease infinite; border-radius: 6px; }
    .dark .skeleton { background: linear-gradient(90deg, #1e293b 25%, #273349 50%, #1e293b 75%); background-size: 400px 100%; }
    .tabular { font-variant-numeric: tabular-nums lining-nums; }
    #sidebar { transition: width 280ms cubic-bezier(.16,1,.3,1); }
    .sidebar-label { transition: opacity 200ms, width 200ms; }
    .app-collapsed #sidebar { width: 64px; }
    .app-collapsed .sidebar-label { opacity: 0; width: 0; overflow: hidden; }
    .app-collapsed .sidebar-section-label { opacity: 0; }
    .app-collapsed .logo-text { opacity: 0; width: 0; overflow: hidden; }
    .nav-link.active { background: rgba(45,157,153,0.12); color: #2d9d99; }
    .dark .nav-link.active { background: rgba(45,157,153,0.15); color: #4db8b4; }
    .nav-link.active .nav-icon { color: #2d9d99; }
    .nav-link.active::before { content:''; position:absolute; left:0; top:15%; bottom:15%; width:3px; background:#2d9d99; border-radius:0 3px 3px 0; }
    /* DataTables overrides */
    .dataTables_wrapper { font-size: 13px; color: inherit; }
    .dataTables_wrapper .dataTables_length, .dataTables_wrapper .dataTables_filter, .dataTables_wrapper .dataTables_info, .dataTables_wrapper .dataTables_paginate { padding: 12px 16px; font-size: 13px; }
    .dataTables_wrapper .dataTables_filter input { border: 1px solid #e2e8f0; border-radius: 8px; padding: 6px 12px; font-size: 13px; background: #f8fafc; outline: none; transition: border-color 200ms; }
    .dark .dataTables_wrapper .dataTables_filter input { background: #1e293b; border-color: #334155; color: #e2e8f0; }
    .dataTables_wrapper .dataTables_filter input:focus { border-color: #2d9d99; box-shadow: 0 0 0 2px rgba(45,157,153,0.15); }
    table.dataTable { border-collapse: collapse !important; width: 100% !important; }
    table.dataTable thead th { background: #f8fafc; font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; color: #64748b; padding: 10px 14px; border-bottom: 2px solid #e2e8f0; }
    .dark table.dataTable thead th { background: #0f172a; color: #94a3b8; border-color: #334155; }
    table.dataTable tbody td { padding: 10px 14px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
    .dark table.dataTable tbody td { border-color: #1e293b; color: #e2e8f0; }
    table.dataTable tbody tr:hover { background: #f0fdf4 !important; }
    .dark table.dataTable tbody tr:hover { background: rgba(45,157,153,0.06) !important; }
    .dataTables_wrapper .dataTables_paginate .paginate_button.current { background: #2d9d99 !important; color: #fff !important; border-color: #2d9d99 !important; border-radius: 6px; }
    .dataTables_wrapper .dataTables_paginate .paginate_button { border-radius: 6px; }
    .dt-buttons .dt-button { background: #f8fafc !important; border: 1px solid #e2e8f0 !important; border-radius: 8px !important; font-size: 12px !important; font-weight: 500 !important; padding: 6px 14px !important; color: #475569 !important; transition: all 150ms !important; }
    .dark .dt-buttons .dt-button { background: #1e293b !important; border-color: #334155 !important; color: #94a3b8 !important; }
    .dt-buttons .dt-button:hover { background: #f1f5f9 !important; border-color: #2d9d99 !important; color: #2d9d99 !important; }
    /* Modal overlay */
    .modal-overlay { display: none; position: fixed; inset: 0; z-index: 100; background: rgba(15,23,42,0.5); backdrop-filter: blur(4px); justify-content: center; align-items: start; padding-top: 5vh; overflow-y: auto; }
    .modal-overlay.active { display: flex; }
    .modal-card { background: #fff; border-radius: 16px; width: 95%; max-width: 720px; box-shadow: 0 20px 60px rgba(0,0,0,0.15); margin-bottom: 5vh; animation: slideUp 250ms ease-out; }
    .dark .modal-card { background: #1e293b; }
    @keyframes slideUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
    /* Status badges */
    .status-badge { display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px; border-radius: 9999px; font-size: 11px; font-weight: 600; letter-spacing: 0.02em; }
    .status-in-transit { background: #dbeafe; color: #1e40af; }
    .dark .status-in-transit { background: rgba(59,130,246,0.15); color: #60a5fa; }
    .status-delivered { background: #d1fae5; color: #065f46; }
    .dark .status-delivered { background: rgba(16,185,129,0.15); color: #34d399; }
    .status-accepted { background: #d1fae5; color: #065f46; }
    .dark .status-accepted { background: rgba(16,185,129,0.15); color: #34d399; }
    .status-rejected { background: #fee2e2; color: #991b1b; }
    .dark .status-rejected { background: rgba(244,63,94,0.15); color: #fb7185; }
    .status-reassigned { background: #fef3c7; color: #92400e; }
    .dark .status-reassigned { background: rgba(251,191,36,0.15); color: #fbbf24; }
    .status-pending { background: #f1f5f9; color: #475569; }
    .dark .status-pending { background: rgba(100,116,139,0.15); color: #94a3b8; }
    /* Action icons */
    .action-icon { display: inline-flex; align-items: center; justify-content: center; width: 30px; height: 30px; border-radius: 0.375rem; border: none; cursor: pointer; transition: all 150ms; font-size: 12px; position: relative; background: transparent; }
    .edit-icon { color: #2d9d99; }
    .edit-icon:hover { background: rgba(45,157,153,0.1); }
    .delete-icon { color: #ef4444; }
    .delete-icon:hover { background: rgba(239,68,68,0.1); }
    /* Computed field */
    .computed-field { padding: 8px 12px; border-radius: 8px; font-size: 14px; font-weight: 600; background: #f8fafc; border: 1px solid #e2e8f0; color: #1e293b; }
    .dark .computed-field { background: #0f172a; border-color: #334155; color: #e2e8f0; }
    /* Skeleton rows */
    .skeleton-row { display: flex; gap: 12px; padding: 14px 16px; border-bottom: 1px solid #f1f5f9; }
    .dark .skeleton-row { border-color: #1e293b; }
    .skeleton-cell { height: 16px; border-radius: 4px; flex: 1; }
    /* Delivery section styling */
    .delivery-section { border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px; margin-bottom: 16px; }
    .dark .delivery-section { border-color: #334155; }
    .delivery-section-title { font-size: 13px; font-weight: 700; margin-bottom: 12px; display: flex; align-items: center; gap: 8px; }
    .section-customer { border-left: 4px solid #10b981; background: linear-gradient(135deg, rgba(16,185,129,0.03), transparent); }
    .dark .section-customer { background: linear-gradient(135deg, rgba(16,185,129,0.05), transparent); }
    .section-customer .delivery-section-title { color: #10b981; }
    .section-vehicle { border-left: 4px solid #3b82f6; background: linear-gradient(135deg, rgba(59,130,246,0.03), transparent); }
    .dark .section-vehicle { background: linear-gradient(135deg, rgba(59,130,246,0.05), transparent); }
    .section-vehicle .delivery-section-title { color: #3b82f6; }
    .section-expenses { border-left: 4px solid #f59e0b; background: linear-gradient(135deg, rgba(245,158,11,0.03), transparent); }
    .dark .section-expenses { background: linear-gradient(135deg, rgba(245,158,11,0.05), transparent); }
    .section-expenses .delivery-section-title { color: #f59e0b; }
    /* Searchable dropdown */
    .searchable-dropdown { position: relative; }
    .searchable-dropdown-input { width: 100%; }
    .searchable-dropdown-arrow { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); color: #94a3b8; pointer-events: none; transition: transform 200ms; }
    .searchable-dropdown-arrow.open { transform: translateY(-50%) rotate(180deg); }
    .searchable-dropdown-list { position: absolute; top: 100%; left: 0; right: 0; max-height: 250px; overflow-y: auto; background: #fff; border: 2px solid #2d9d99; border-top: none; border-radius: 0 0 8px 8px; z-index: 50; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
    .dark .searchable-dropdown-list { background: #1e293b; border-color: #4db8b4; }
    .searchable-dropdown-item { padding: 8px 12px; cursor: pointer; font-size: 13px; transition: background 100ms; }
    .searchable-dropdown-item:hover { background: #f0fdf4; }
    .dark .searchable-dropdown-item:hover { background: rgba(45,157,153,0.1); }
    .searchable-dropdown-item.selected { background: rgba(45,157,153,0.1); font-weight: 600; }
    .searchable-dropdown-item.no-results { color: #94a3b8; cursor: default; font-style: italic; }
    /* Radio group */
    .radio-group { display: flex; gap: 16px; }
    .radio-label { display: flex; align-items: center; gap: 6px; cursor: pointer; font-size: 14px; }
    /* Table scroll hint */
    .table-scroll-hint { display: none; text-align: center; font-size: 11px; color: #94a3b8; padding: 4px 0; }
    @media (max-width: 768px) { .table-scroll-hint { display: block; } }
    /* Form inputs inside modal */
    .modal-input { width: 100%; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 8px 12px; font-size: 14px; color: #1e293b; transition: border-color 200ms, box-shadow 200ms; outline: none; }
    .modal-input:focus { border-color: #2d9d99; box-shadow: 0 0 0 2px rgba(45,157,153,0.15); }
    .dark .modal-input { background: #0f172a; border-color: #334155; color: #e2e8f0; }
    .dark .modal-input:focus { border-color: #4db8b4; }
    .modal-label { display: block; font-size: 11px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 4px; }
  </style>
</head>
<body class="h-full bg-slate-50 text-slate-800 font-sans antialiased dark:bg-slate-900 dark:text-slate-200">
<?php include 'mobile-menu.php'; ?>
<div class="flex h-full overflow-hidden" id="appRoot">
  <?php include 'sidebar.php'; ?>
  <div class="flex-1 flex flex-col min-w-0 overflow-hidden">
    <!-- HEADER -->
    <header class="h-14 bg-white dark:bg-slate-800 border-b border-slate-200 dark:border-slate-700 flex items-center gap-4 px-5 flex-shrink-0">
      <button id="mobileSidebarBtn" class="lg:hidden text-slate-500 hover:text-slate-700 dark:hover:text-slate-300">
        <i class="fas fa-bars text-sm"></i>
      </button>
      <div class="flex items-center gap-2">
        <i class="fas fa-truck-fast text-brand-500 text-sm"></i>
        <h1 class="text-base font-bold text-slate-800 dark:text-white">Deliveries</h1>
      </div>
      <div class="ml-auto flex items-center gap-3">
        <span class="hidden sm:inline-block text-xs text-slate-400 dark:text-slate-500">Welcome, <?php echo htmlspecialchars($username); ?></span>
      </div>
    </header>

    <main class="flex-1 overflow-y-auto p-5">
      <!-- Section Header -->
      <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-5">
        <div>
          <h2 class="text-lg font-bold text-slate-800 dark:text-white flex items-center gap-2">
            <i class="fas fa-table text-brand-500 text-sm"></i> Deliveries
          </h2>
          <p class="text-xs text-slate-400 dark:text-slate-500 mt-0.5">Manage outbound deliveries and logistics</p>
        </div>
        <div class="flex items-center gap-2">
          <button class="bg-white dark:bg-slate-700 hover:bg-slate-50 dark:hover:bg-slate-600 text-slate-600 dark:text-slate-300 text-xs font-semibold px-3.5 py-2 rounded-lg border border-slate-200 dark:border-slate-600 transition-colors" onclick="loadDeliveries()">
            <i class="fas fa-sync text-xs mr-1"></i> Refresh
          </button>
          <?php if ($canCreate): ?>
          <button class="bg-brand-500 hover:bg-brand-600 text-white text-xs font-semibold px-3.5 py-2 rounded-lg transition-colors shadow-sm" onclick="openAddModal()">
            <i class="fas fa-plus text-xs mr-1"></i> Add Delivery
          </button>
          <?php endif; ?>
        </div>
      </div>

      <!-- Filters -->
      <div id="filtersSection" style="display: none;" class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card p-4 mb-5">
        <div class="flex items-center justify-between mb-3">
          <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-300 flex items-center gap-2">
            <i class="fas fa-filter text-brand-500 text-xs"></i> Filters
          </h3>
          <button class="text-xs text-slate-400 hover:text-slate-600 dark:hover:text-slate-300 transition-colors" onclick="clearFilters()">
            <i class="fas fa-times-circle mr-1"></i> Clear All
          </button>
        </div>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
          <div>
            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">Date From</label>
            <input type="date" id="filterDateFrom" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
          </div>
          <div>
            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">Date To</label>
            <input type="date" id="filterDateTo" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
          </div>
          <div>
            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">Status</label>
            <select id="filterStatus" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
              <option value="">All</option>
              <option value="In Transit">In Transit</option>
              <option value="Delivered">Delivered</option>
              <option value="Rejected">Rejected</option>
              <option value="Reassigned">Reassigned</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">Season</label>
            <select id="filterSeason" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
              <option value="">All Seasons</option>
            </select>
          </div>
        </div>
      </div>

      <!-- Skeleton Loader -->
      <div id="skeletonLoader" class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card p-5">
        <div class="skeleton-row"><div class="skeleton skeleton-cell"></div><div class="skeleton skeleton-cell"></div><div class="skeleton skeleton-cell"></div><div class="skeleton skeleton-cell"></div><div class="skeleton skeleton-cell"></div><div class="skeleton skeleton-cell"></div></div>
        <div class="skeleton-row"><div class="skeleton skeleton-cell"></div><div class="skeleton skeleton-cell"></div><div class="skeleton skeleton-cell"></div><div class="skeleton skeleton-cell"></div><div class="skeleton skeleton-cell"></div><div class="skeleton skeleton-cell"></div></div>
        <div class="skeleton-row"><div class="skeleton skeleton-cell"></div><div class="skeleton skeleton-cell"></div><div class="skeleton skeleton-cell"></div><div class="skeleton skeleton-cell"></div><div class="skeleton skeleton-cell"></div><div class="skeleton skeleton-cell"></div></div>
        <div class="skeleton-row"><div class="skeleton skeleton-cell"></div><div class="skeleton skeleton-cell"></div><div class="skeleton skeleton-cell"></div><div class="skeleton skeleton-cell"></div><div class="skeleton skeleton-cell"></div><div class="skeleton skeleton-cell"></div></div>
        <div class="skeleton-row"><div class="skeleton skeleton-cell"></div><div class="skeleton skeleton-cell"></div><div class="skeleton skeleton-cell"></div><div class="skeleton skeleton-cell"></div><div class="skeleton skeleton-cell"></div><div class="skeleton skeleton-cell"></div></div>
        <div class="skeleton-row"><div class="skeleton skeleton-cell"></div><div class="skeleton skeleton-cell"></div><div class="skeleton skeleton-cell"></div><div class="skeleton skeleton-cell"></div><div class="skeleton skeleton-cell"></div><div class="skeleton skeleton-cell"></div></div>
        <div class="skeleton-row"><div class="skeleton skeleton-cell"></div><div class="skeleton skeleton-cell"></div><div class="skeleton skeleton-cell"></div><div class="skeleton skeleton-cell"></div><div class="skeleton skeleton-cell"></div><div class="skeleton skeleton-cell"></div></div>
        <div class="skeleton-row"><div class="skeleton skeleton-cell"></div><div class="skeleton skeleton-cell"></div><div class="skeleton skeleton-cell"></div><div class="skeleton skeleton-cell"></div><div class="skeleton skeleton-cell"></div><div class="skeleton skeleton-cell"></div></div>
      </div>

      <!-- DataTable Card -->
      <div id="tableContainer" style="display:none;" class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card">
        <div class="p-2">
          <div class="table-scroll-hint"><i class="fas fa-arrows-alt-h"></i> Swipe left/right</div>
          <div style="overflow-x: auto; -webkit-overflow-scrolling: touch;">
            <table id="deliveriesTable" class="display" style="width:100%"></table>
          </div>
        </div>
      </div>
    </main>
  </div>
</div>

<?php if ($canCreate || $canUpdate): ?>
<div class="modal-overlay" id="deliveryModal">
  <div class="modal-card" onclick="event.stopPropagation()">
    <div class="flex items-center justify-between px-5 py-4 border-b border-slate-200 dark:border-slate-700">
      <h3 id="modalTitle" class="text-sm font-bold text-slate-800 dark:text-white flex items-center gap-2">
        <i class="fas fa-truck-fast text-brand-500"></i> Add Delivery
      </h3>
      <button class="w-8 h-8 rounded-lg flex items-center justify-center text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-700 transition-colors" onclick="closeModal()">
        <i class="fas fa-times text-sm"></i>
      </button>
    </div>
    <div class="px-5 py-4 max-h-[calc(100vh-180px)] overflow-y-auto">

      <div id="deliveryIdInfo" style="display: none;" class="flex items-center gap-2 mb-4 px-3 py-2 bg-brand-50 dark:bg-slate-700 rounded-lg border border-brand-200 dark:border-slate-600">
        <i class="fas fa-id-badge text-brand-500 text-xs"></i>
        <span class="text-xs font-semibold text-slate-600 dark:text-slate-300">Delivery ID:</span>
        <span id="deliveryIdDisplay" class="text-xs font-bold text-brand-600 dark:text-brand-400"></span>
      </div>

      <form id="deliveryForm">
        <input type="hidden" id="deliveryId" name="delivery_id">

        <!-- Section 1: Customer Info -->
        <div class="delivery-section section-customer">
          <div class="delivery-section-title"><i class="fas fa-handshake"></i> Customer Info</div>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-5 gap-y-4">
            <div>
              <label class="modal-label"><i class="fas fa-calendar-day mr-1"></i> Date *</label>
              <input type="date" id="deliveryDate" name="date" required class="modal-input">
            </div>
            <div>
              <label class="modal-label"><i class="fas fa-handshake mr-1"></i> Customer *</label>
              <div class="searchable-dropdown" id="customerDropdownWrapper">
                <input type="text" class="searchable-dropdown-input modal-input" id="customerSearch" placeholder="Search customer..." autocomplete="off">
                <input type="hidden" id="customerId" name="customer_id" required>
                <span class="searchable-dropdown-arrow" id="customerArrow"><i class="fas fa-chevron-down"></i></span>
                <div class="searchable-dropdown-list" id="customerList" style="display:none;"></div>
              </div>
              <div class="mt-1">
                <a href="#" onclick="openQuickAddCustomer(); return false;" class="text-xs text-brand-500 hover:text-brand-600 font-medium"><i class="fas fa-plus-circle mr-1"></i>Add New Customer</a>
              </div>
            </div>
            <div>
              <label class="modal-label"><i class="fas fa-warehouse mr-1"></i> Origin Warehouse</label>
              <select id="originWarehouse" name="origin_warehouse_id" onchange="onWarehouseChange()" class="modal-input">
                <option value="">Select warehouse...</option>
              </select>
            </div>
          </div>

          <!-- Lot Picker Section -->
          <div id="lotPickerSection" style="display:none; margin-top: 14px; border: 1px solid #2d9d99; border-radius: 10px; padding: 14px; background: rgba(45,157,153,0.03);">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
              <h4 style="margin:0; font-size:13px; font-weight:700; color:#2d9d99; display:flex; align-items:center; gap:6px;"><i class="fas fa-cubes"></i> Load from Lots</h4>
              <div id="lotPickerSummary" style="font-weight:600; color:#2d9d99; font-size:12px;"></div>
            </div>
            <div id="lotPickerContent"><div class="skeleton" style="height:60px;"></div></div>
            <input type="hidden" id="selectedItems" name="selected_items" value="">
          </div>
        </div>

        <!-- Section 2: Vehicle -->
        <div class="delivery-section section-vehicle">
          <div class="delivery-section-title"><i class="fas fa-truck"></i> Vehicle</div>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-5 gap-y-4">
            <div class="sm:col-span-2">
              <label class="modal-label"><i class="fas fa-car-side mr-1"></i> Vehicle Type</label>
              <div class="radio-group mt-1">
                <label class="radio-label">
                  <input type="radio" name="vehicle_type" value="Owned" id="vtOwned" checked onchange="onVehicleTypeChange()">
                  <span class="text-sm text-slate-700 dark:text-slate-300">Owned</span>
                </label>
                <label class="radio-label">
                  <input type="radio" name="vehicle_type" value="Rental" id="vtRental" onchange="onVehicleTypeChange()">
                  <span class="text-sm text-slate-700 dark:text-slate-300">Rental</span>
                </label>
              </div>
            </div>

            <!-- Owned-vehicle block -->
            <div id="ownedFields" class="sm:col-span-2">
              <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-5 gap-y-4">
                <div>
                  <label class="modal-label"><i class="fas fa-truck mr-1"></i> Vehicle</label>
                  <select id="vehicleId" name="vehicle_id" class="modal-input">
                    <option value="">Select vehicle...</option>
                  </select>
                </div>
                <div>
                  <label class="modal-label"><i class="fas fa-user mr-1"></i> Driver Name</label>
                  <input type="text" id="driverName" name="driver_name" placeholder="Enter driver name" maxlength="150" class="modal-input">
                </div>
              </div>
            </div>

            <!-- Rental block -->
            <div id="rentalFields" style="display:none;" class="sm:col-span-2">
              <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-5 gap-y-4 border border-dashed border-slate-300 dark:border-slate-600 rounded-lg p-3 bg-slate-50 dark:bg-slate-700/50">
                <div>
                  <label class="modal-label"><i class="fas fa-user mr-1"></i> Rental Driver Name</label>
                  <input type="text" id="rentalDriverName" name="rental_driver_name" maxlength="150" placeholder="Driver name" class="modal-input">
                </div>
                <div>
                  <label class="modal-label"><i class="fas fa-phone mr-1"></i> Rental Driver Phone</label>
                  <input type="tel" id="rentalDriverPhone" name="rental_driver_phone" maxlength="20" placeholder="+225..." class="modal-input">
                </div>
                <div>
                  <label class="modal-label"><i class="fas fa-truck mr-1"></i> Rental Vehicle Reg.</label>
                  <input type="text" id="rentalVehicleReg" name="rental_vehicle_reg" maxlength="50" placeholder="Registration #" class="modal-input">
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Section 3: Cargo & Expenses -->
        <div class="delivery-section section-expenses">
          <div class="delivery-section-title"><i class="fas fa-coins"></i> Cargo &amp; Expenses</div>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-5 gap-y-4">
            <div>
              <label class="modal-label"><i class="fas fa-weight-hanging mr-1"></i> Weight (kg) *</label>
              <input type="number" id="weightKg" name="weight_kg" step="0.01" min="0.01" required placeholder="0.00" class="modal-input">
            </div>
            <div>
              <label class="modal-label"><i class="fas fa-boxes-stacked mr-1"></i> Number of Bags</label>
              <input type="number" id="numBags" name="num_bags" min="0" placeholder="0" class="modal-input">
            </div>
            <div>
              <label class="modal-label"><i class="fas fa-tag mr-1"></i> Product Purchase Price (auto)</label>
              <div class="computed-field" id="productCostDisplay">0 F</div>
            </div>
            <div>
              <label class="modal-label"><i class="fas fa-truck-moving mr-1"></i> Transport Cost</label>
              <input type="number" id="transportCost" name="transport_cost" step="0.01" min="0" value="0" onchange="computeTotalCost()" class="modal-input">
            </div>
            <div>
              <label class="modal-label"><i class="fas fa-shield-halved mr-1"></i> Roads Fees</label>
              <input type="number" id="roadFees" name="road_fees" step="0.01" min="0" value="0" onchange="computeTotalCost()" class="modal-input">
            </div>
            <div>
              <label class="modal-label"><i class="fas fa-dolly mr-1"></i> Loading Cost</label>
              <input type="number" id="loadingCost" name="loading_cost" step="0.01" min="0" value="0" onchange="computeTotalCost()" class="modal-input">
            </div>
            <div>
              <label class="modal-label"><i class="fas fa-coins mr-1"></i> Other Cost</label>
              <input type="number" id="otherCost" name="other_cost" step="0.01" min="0" value="0" onchange="computeTotalCost()" class="modal-input">
            </div>
            <div>
              <label class="modal-label"><i class="fas fa-calculator mr-1"></i> Logistics Total</label>
              <div class="computed-field" id="totalCostDisplay">0</div>
            </div>
            <div id="statusGroup" style="display: none;">
              <label class="modal-label"><i class="fas fa-info-circle mr-1"></i> Status</label>
              <select id="deliveryStatus" name="status" onchange="toggleStatusFields()" class="modal-input">
                <option value="In Transit">In Transit</option>
              </select>
            </div>
            <div id="weightAtDestGroup" style="display: none;">
              <label class="modal-label"><i class="fas fa-weight-scale mr-1"></i> Weight at Destination</label>
              <input type="number" id="weightAtDest" name="weight_at_destination" step="0.01" min="0" placeholder="0.00" class="modal-input">
            </div>
            <div id="rejectionGroup" style="display: none;" class="sm:col-span-2">
              <label class="modal-label"><i class="fas fa-ban mr-1"></i> Rejection Reason *</label>
              <textarea id="rejectionReason" name="rejection_reason" maxlength="300" rows="2" placeholder="Enter reason for rejection..." class="modal-input" style="resize:vertical;"></textarea>
            </div>
            <div>
              <label class="modal-label"><i class="fas fa-leaf mr-1"></i> Season *</label>
              <?php echo renderSeasonDropdown('season', 'season'); ?>
            </div>
            <div class="sm:col-span-2">
              <label class="modal-label"><i class="fas fa-sticky-note mr-1"></i> Notes</label>
              <textarea id="notes" name="notes" rows="2" placeholder="Optional notes..." class="modal-input" style="resize:vertical;"></textarea>
            </div>
          </div>
        </div>

        <div class="flex items-center gap-3 pt-2">
          <button type="submit" class="bg-brand-500 hover:bg-brand-600 text-white text-sm font-semibold px-5 py-2.5 rounded-lg transition-colors shadow-sm flex items-center gap-2">
            <i class="fas fa-save text-xs"></i> Save
          </button>
          <button type="button" onclick="closeModal()" class="bg-slate-100 hover:bg-slate-200 dark:bg-slate-700 dark:hover:bg-slate-600 text-slate-600 dark:text-slate-300 text-sm font-semibold px-5 py-2.5 rounded-lg transition-colors flex items-center gap-2">
            <i class="fas fa-times text-xs"></i> Cancel
          </button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($canCreate): ?>
<!-- Quick Add Customer Modal -->
<div class="modal-overlay" id="quickCustomerModal" onclick="if(event.target===this)closeQuickCustomer()" style="z-index:10010;">
  <div class="modal-card" style="max-width:500px;" onclick="event.stopPropagation()">
    <div class="flex items-center justify-between px-5 py-4 border-b border-slate-200 dark:border-slate-700">
      <h3 class="text-sm font-bold text-slate-800 dark:text-white flex items-center gap-2">
        <i class="fas fa-handshake text-brand-500"></i> Quick Add Customer
      </h3>
      <button class="w-8 h-8 rounded-lg flex items-center justify-center text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-700 transition-colors" onclick="closeQuickCustomer()">
        <i class="fas fa-times text-sm"></i>
      </button>
    </div>
    <div class="px-5 py-4">
      <form id="quickCustomerForm">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-5 gap-y-4">
          <div>
            <label class="modal-label"><i class="fas fa-building mr-1"></i> Customer Name *</label>
            <input type="text" id="qcName" required maxlength="200" placeholder="Company or person name" class="modal-input">
          </div>
          <div>
            <label class="modal-label"><i class="fas fa-phone mr-1"></i> Phone</label>
            <input type="tel" id="qcPhone" maxlength="20" placeholder="+225..." class="modal-input">
          </div>
          <div>
            <label class="modal-label"><i class="fas fa-user mr-1"></i> Contact Person</label>
            <input type="text" id="qcContact" maxlength="150" class="modal-input">
          </div>
          <div>
            <label class="modal-label"><i class="fas fa-map-marker-alt mr-1"></i> Location</label>
            <select id="qcLocation" class="modal-input">
              <option value="">Select Location</option>
            </select>
          </div>
        </div>
        <div class="flex items-center gap-3 mt-5">
          <button type="submit" class="bg-brand-500 hover:bg-brand-600 text-white text-sm font-semibold px-5 py-2.5 rounded-lg transition-colors shadow-sm flex items-center gap-2">
            <i class="fas fa-save text-xs"></i> Save &amp; Select
          </button>
          <button type="button" onclick="closeQuickCustomer()" class="bg-slate-100 hover:bg-slate-200 dark:bg-slate-700 dark:hover:bg-slate-600 text-slate-600 dark:text-slate-300 text-sm font-semibold px-5 py-2.5 rounded-lg transition-colors flex items-center gap-2">
            <i class="fas fa-times text-xs"></i> Cancel
          </button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

    <script>
    // Global variables
    let deliveriesTable;
    let isEditMode = false;
    let deliveriesData = [];
    let customersList = [];
    let warehousesList = [];
    let locationsData = [];

    const isReadOnly = <?php echo $isReadOnly ? 'true' : 'false'; ?>;
    const canCreate = <?php echo $canCreate ? 'true' : 'false'; ?>;
    const canUpdate = <?php echo $canUpdate ? 'true' : 'false'; ?>;
    const canDelete = <?php echo $canDelete ? 'true' : 'false'; ?>;
    var ACTIVE_SEASON = '<?php echo addslashes(getActiveSeason()); ?>';

    const statusBadgeMap = {
        'Pending': 'status-pending',
        'In Transit': 'status-in-transit',
        'Delivered': 'status-delivered',
        'Accepted': 'status-accepted',
        'Rejected': 'status-rejected',
        'Reassigned': 'status-reassigned'
    };

    $(document).ready(function() {
        loadDropdowns();
        loadDeliveries();
    });

    function loadDropdowns() {
        $.ajax({
            url: '?action=getDropdowns',
            method: 'GET',
            dataType: 'json',
            success: function(response) {
                if (response.success && response.data) {
                    customersList = response.data.customers.map(function(c) {
                        return { id: c.customer_id, name: c.customer_name };
                    });
                    initCustomerDropdown();

                    var whSelect = document.getElementById('originWarehouse');
                    if (whSelect) {
                        whSelect.innerHTML = '<option value="">Select warehouse...</option>';
                        response.data.warehouses.forEach(function(w) {
                            var opt = document.createElement('option');
                            opt.value = w.warehouse_id;
                            opt.textContent = w.warehouse_name + (w.location_name ? ' (' + w.location_name + ')' : '');
                            whSelect.appendChild(opt);
                        });
                    }

                    warehousesList = response.data.warehouses;

                    // store locations for quick-add customer
                    if (response.data.locations) locationsData = response.data.locations;

                    // Populate vehicle dropdown
                    var vehSelect = document.getElementById('vehicleId');
                    if (vehSelect && response.data.vehicles) {
                        vehSelect.innerHTML = '<option value="">Select vehicle...</option>';
                        response.data.vehicles.forEach(function(v) {
                            var opt = document.createElement('option');
                            opt.value = v.vehicle_id;
                            opt.textContent = (v.vehicle_registration || v.vehicle_id) + (v.vehicle_model ? ' (' + v.vehicle_model + ')' : '');
                            if (v.driver_name) opt.setAttribute('data-driver', v.driver_name);
                            vehSelect.appendChild(opt);
                        });
                    }

                    // When vehicle is selected, auto-fill driver
                    $('#vehicleId').on('change', function() {
                        var selected = $(this).find('option:selected');
                        var driverName = selected.data('driver');
                        if (driverName) {
                            document.getElementById('driverName').value = driverName;
                        }
                    });
                }
            }
        });
    }

    function initCustomerDropdown() {
        var input = document.getElementById('customerSearch');
        var hiddenInput = document.getElementById('customerId');
        var list = document.getElementById('customerList');
        var arrow = document.getElementById('customerArrow');

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
            if (!document.getElementById('customerDropdownWrapper').contains(e.target)) {
                list.style.display = 'none';
                arrow.classList.remove('open');
                var sel = customersList.find(function(c) { return c.id === hiddenInput.value; });
                if (sel) {
                    input.value = sel.id + ' — ' + sel.name;
                } else if (hiddenInput.value === '') {
                    input.value = '';
                }
            }
        });
    }

    function renderCustomerList(searchTerm) {
        var list = document.getElementById('customerList');
        var hiddenInput = document.getElementById('customerId');
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
                selectCustomer(c);
            });
            list.appendChild(item);
        });
    }

    function selectCustomer(customer) {
        document.getElementById('customerId').value = customer.id;
        document.getElementById('customerSearch').value = customer.id + ' — ' + customer.name;
        document.getElementById('customerList').style.display = 'none';
        document.getElementById('customerArrow').classList.remove('open');
    }

    function loadDeliveries() {
        $('#skeletonLoader').show();
        $('#tableContainer').hide();

        $.ajax({
            url: '?action=getDeliveries',
            method: 'GET',
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    deliveriesData = response.data;
                    $('#filtersSection').show();
                    populateSeasonFilter(response.data);
                    initializeDataTable(response.data);
                } else {
                    $('#skeletonLoader').hide();
                    Swal.fire({ icon: 'error', title: 'Error', text: response.message || 'Failed to load deliveries' });
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

    function initializeDataTable(data) {
        if (deliveriesTable) {
            deliveriesTable.destroy();
            $('#deliveriesTable').empty();
        }

        var columns = [
            { data: 'delivery_id', title: 'ID' },
            { data: 'date', title: 'Date' },
            {
                data: 'customer_name',
                title: 'Customer',
                render: function(data, type, row) {
                    var html = data || '';
                    if (row.customer_id) html += '<br><small class="text-muted">' + row.customer_id + '</small>';
                    if (row.reassigned_from_delivery_id) html += '<br><small class="text-muted">From: ' + row.reassigned_from_delivery_id + '</small>';
                    return html;
                }
            },
            { data: 'warehouse_name', title: 'Warehouse', defaultContent: '' },
            {
                data: 'driver_name',
                title: 'Vehicle/Driver',
                render: function(data, type, row) {
                    var vt = row.vehicle_type || 'Owned';
                    var badge = vt === 'Rental'
                        ? '<span class="status-badge" style="background:#e74c3c;color:#fff;font-size:10px;padding:2px 6px;">Rental</span>'
                        : '<span class="status-badge" style="background:#27ae60;color:#fff;font-size:10px;padding:2px 6px;">Owned</span>';
                    var html = badge;
                    if (vt === 'Rental') {
                        if (row.rental_driver_name) html += '<br><small>' + row.rental_driver_name + '</small>';
                        if (row.rental_vehicle_reg) html += '<br><small class="text-muted">' + row.rental_vehicle_reg + '</small>';
                    } else {
                        if (data) html += '<br><small>' + data + '</small>';
                        if (row.vehicle_id) html += '<br><small class="text-muted">' + row.vehicle_id + '</small>';
                    }
                    return html;
                }
            },
            {
                data: 'weight_kg',
                title: 'Weight(kg)',
                render: function(data) { return data ? parseFloat(data).toLocaleString() : '0'; }
            },
            { data: 'num_bags', title: 'Bags' },
            {
                data: 'procurement_cost_per_kg',
                title: 'Avg Price/Kg',
                render: function(data) { return data ? parseFloat(data).toLocaleString() : '—'; }
            },
            {
                data: null,
                title: 'Total Cost',
                render: function(data, type, row) {
                    var wt = parseFloat(row.weight_kg) || 0;
                    var prc = parseFloat(row.procurement_cost_per_kg) || 0;
                    var expenses = parseFloat(row.total_cost) || 0;
                    var productCost = prc * wt;
                    var fullCost = productCost + expenses;
                    if (type === 'sort' || type === 'type') return fullCost;
                    var tip = 'Product: ' + productCost.toLocaleString() + ' + Expenses: ' + expenses.toLocaleString();
                    return '<span title="' + tip + '">' + fullCost.toLocaleString() + '</span>';
                }
            },
            {
                data: 'status',
                title: 'Status',
                render: function(data, type, row) {
                    var cls = statusBadgeMap[data] || 'status-pending';
                    if (data === 'Reassigned') {
                        return '<span class="status-badge" style="background:#f39c12;color:#fff;" title="Reassigned to ' + (row.reassigned_to || '') + '"><i class="fas fa-exchange-alt"></i> Reassigned</span>';
                    }
                    if (data === 'Rejected') {
                        var tip = row.rejection_reason || '';
                        if (row.rejection_date) tip += ' (' + row.rejection_date + ')';
                        return '<span class="status-badge ' + cls + '" title="' + tip.replace(/"/g, '&quot;') + '"><i class="fas fa-ban"></i> Rejected</span>';
                    }
                    return '<span class="status-badge ' + cls + '">' + (data || 'In Transit') + '</span>';
                }
            },
            { data: 'season', title: 'Season' }
        ];

        if (canUpdate || canDelete) {
            columns.push({
                data: null,
                title: 'Actions',
                orderable: false,
                render: function(data, type, row) {
                    var html = '';
                    html += '<button class="action-icon" onclick="printDeliveryReceipt(\'' + row.delivery_id + '\')" title="Print Receipt" style="color:#001f3f;"><i class="fas fa-print"></i></button> ';
                    if (canUpdate) {
                        html += '<button class="action-icon edit-icon" onclick=\'editDelivery(' + JSON.stringify(row).replace(/'/g, "\\'") + ')\' title="Edit"><i class="fas fa-edit"></i></button> ';
                    }
                    if (canUpdate && row.status === 'In Transit') {
                        html += '<button class="action-icon" style="color:#e74c3c;" onclick="rejectDelivery(\'' + row.delivery_id + '\')" title="Reject"><i class="fas fa-ban"></i></button> ';
                    }
                    if (canUpdate && (row.status === 'In Transit' || row.status === 'Rejected')) {
                        html += '<button class="action-icon" style="color:#f39c12;" onclick="reassignDelivery(\'' + row.delivery_id + '\', \'' + (row.customer_name || '').replace(/'/g, "\\'") + '\')" title="Reassign"><i class="fas fa-exchange-alt"></i></button> ';
                    }
                    if (canDelete) {
                        html += '<button class="action-icon delete-icon" onclick="deleteDelivery(\'' + row.delivery_id + '\')" title="Delete"><i class="fas fa-trash"></i></button>';
                    }
                    return html;
                }
            });
        }

        setTimeout(function() {
            deliveriesTable = $('#deliveriesTable').DataTable({
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

            $('#filterDateFrom, #filterDateTo, #filterStatus, #filterSeason').on('change', function() {
                applyFilters();
            });
        }, 100);
    }

    function applyFilters() {
        if (!deliveriesTable) return;

        $.fn.dataTable.ext.search = [];

        var dateFrom = document.getElementById('filterDateFrom').value;
        var dateTo = document.getElementById('filterDateTo').value;
        var status = document.getElementById('filterStatus').value;
        var season = document.getElementById('filterSeason').value;

        if (dateFrom || dateTo) {
            $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
                var rawDate = deliveriesData[dataIndex]?.date_raw;
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
                return deliveriesData[dataIndex]?.status === status;
            });
        }

        if (season) {
            $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
                return deliveriesData[dataIndex]?.season === season;
            });
        }

        deliveriesTable.draw();
    }

    function clearFilters() {
        document.getElementById('filterDateFrom').value = '';
        document.getElementById('filterDateTo').value = '';
        document.getElementById('filterStatus').value = '';
        document.getElementById('filterSeason').value = '';

        if (deliveriesTable) {
            $.fn.dataTable.ext.search = [];
            deliveriesTable.columns().search('').draw();
        }
    }

    function computeTotalCost() {
        var transport = parseFloat(document.getElementById('transportCost').value) || 0;
        var roadFees  = parseFloat(document.getElementById('roadFees').value) || 0;
        var loading   = parseFloat(document.getElementById('loadingCost').value) || 0;
        var other     = parseFloat(document.getElementById('otherCost').value) || 0;
        var total = transport + roadFees + loading + other;
        document.getElementById('totalCostDisplay').textContent = total.toLocaleString();
    }

    // ===================== Lot Picker =====================
    var availableLots = [];

    function onWarehouseChange() {
        var whId = document.getElementById('originWarehouse').value;
        var section = document.getElementById('lotPickerSection');
        if (!whId) {
            section.style.display = 'none';
            availableLots = [];
            return;
        }
        section.style.display = 'block';
        document.getElementById('lotPickerContent').innerHTML = '<div class="skeleton" style="height:60px;"></div>';

        // when editing, tell the server to NOT subtract this delivery's own items from availability
        var url = '?action=getAvailableLots&warehouse_id=' + whId;
        if (isEditMode) {
            var did = document.getElementById('deliveryId').value;
            if (did) url += '&exclude_delivery_id=' + encodeURIComponent(did);
        }

        $.ajax({
            url: url,
            method: 'GET',
            dataType: 'json',
            success: function(r) {
                if (r.success) {
                    availableLots = r.data;
                    renderLotPicker();
                    // if editing, restore the previously-loaded items
                    if (isEditMode && window._editPrefillItems && window._editPrefillItems.length) {
                        applyPrefillItems(window._editPrefillItems);
                        window._editPrefillItems = null; // consume once
                    }
                } else {
                    document.getElementById('lotPickerContent').innerHTML = '<div style="color:var(--text-muted);text-align:center;padding:16px;">No lots available</div>';
                }
            }
        });
    }

    // restore checkboxes + qty inputs from a previously-saved delivery's items
    function applyPrefillItems(items) {
        items.forEach(function(it) {
            var uid = it.lot_id + '_' + it.purchase_id;
            var cb = document.querySelector('.lot-item-check[data-uid="' + uid + '"]');
            var qtyInput = document.getElementById('qty_' + uid);
            if (cb && qtyInput) {
                cb.checked = true;
                qtyInput.disabled = false;
                qtyInput.value = parseFloat(it.quantity_kg) || 0;
                // lock cost to what was saved on the original delivery so edit stays accurate
                // even if the purchase price has shifted since
                if (it.cost_per_kg != null) cb.dataset.cost = parseFloat(it.cost_per_kg) || 0;
                // grow max so the saved qty always fits even if server availability rounds differently
                var savedQty = parseFloat(it.quantity_kg) || 0;
                var curMax = parseFloat(qtyInput.max) || 0;
                if (savedQty > curMax) { qtyInput.max = savedQty; cb.dataset.avail = savedQty; }
            }
        });
        recalcLotPicker();
    }

    function renderLotPicker() {
        var container = document.getElementById('lotPickerContent');
        if (!availableLots.length) {
            container.innerHTML = '<div style="color:#94a3b8;text-align:center;padding:16px;font-size:13px;"><i class="fas fa-inbox"></i> No available stock in this warehouse</div>';
            return;
        }

        var html = '';
        availableLots.forEach(function(lot) {
            html += '<div style="margin-bottom:12px; border:1px solid #e2e8f0; border-radius:8px; overflow:hidden;">';
            html += '<div style="background:#2d9d99; color:#fff; padding:8px 12px; font-size:13px; font-weight:600; display:flex; justify-content:space-between; align-items:center;">';
            html += '<span><i class="fas fa-cube" style="margin-right:6px;"></i>' + lot.lot_number + ' <span style="opacity:.75;font-weight:400;">(' + lot.status + ')</span></span>';
            html += '<span style="font-size:12px;font-weight:500;">' + lot.available_kg.toLocaleString() + ' kg avail &middot; Avg ' + lot.avg_cost.toLocaleString() + ' F/kg</span>';
            html += '</div>';
            html += '<table style="width:100%; font-size:12px; border-collapse:collapse;">';
            html += '<tr style="background:#f8fafc;"><th style="padding:7px 10px;text-align:left;font-size:11px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.04em;border-bottom:1px solid #e2e8f0;">Select</th><th style="padding:7px 8px;text-align:left;font-size:11px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.04em;border-bottom:1px solid #e2e8f0;">Purchase</th><th style="padding:7px 8px;text-align:left;font-size:11px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.04em;border-bottom:1px solid #e2e8f0;">Supplier</th><th style="padding:7px 8px;text-align:right;font-size:11px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.04em;border-bottom:1px solid #e2e8f0;">Avail (kg)</th><th style="padding:7px 8px;text-align:right;font-size:11px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.04em;border-bottom:1px solid #e2e8f0;">Cost/kg</th><th style="padding:7px 8px;text-align:right;font-size:11px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.04em;border-bottom:1px solid #e2e8f0;">Qty to Load</th></tr>';

            lot.purchases.forEach(function(p) {
                var uid = lot.lot_id + '_' + p.purchase_id;
                html += '<tr style="border-top:1px solid #f1f5f9;">';
                html += '<td style="padding:7px 10px;"><input type="checkbox" class="lot-item-check" data-uid="' + uid + '" data-lot="' + lot.lot_id + '" data-lotnr="' + lot.lot_number + '" data-pid="' + p.purchase_id + '" data-supplier="' + (p.supplier_name || '').replace(/"/g, '&quot;') + '" data-avail="' + p.available_kg + '" data-cost="' + p.cost_per_kg + '" onchange="onLotItemToggle(this)"></td>';
                html += '<td style="padding:7px 8px;font-size:11px;color:#64748b;">' + p.purchase_id + '</td>';
                html += '<td style="padding:7px 8px;color:#1e293b;">' + (p.supplier_name || '') + '</td>';
                html += '<td style="padding:7px 8px;text-align:right;font-weight:600;">' + p.available_kg.toLocaleString() + '</td>';
                html += '<td style="padding:7px 8px;text-align:right;color:#2d9d99;font-weight:600;">' + p.cost_per_kg.toLocaleString() + ' F</td>';
                html += '<td style="padding:7px 8px;text-align:right;"><input type="number" id="qty_' + uid + '" class="lot-qty-input" style="width:90px;padding:5px 7px;font-size:12px;text-align:right;border:1px solid #e2e8f0;border-radius:6px;background:#f8fafc;outline:none;" step="0.01" min="0" max="' + p.available_kg + '" value="' + p.available_kg + '" disabled oninput="recalcLotPicker()"></td>';
                html += '</tr>';
            });
            html += '</table></div>';
        });

        container.innerHTML = html;
        recalcLotPicker();
    }

    function onLotItemToggle(cb) {
        var uid = cb.dataset.uid;
        var qtyInput = document.getElementById('qty_' + uid);
        if (cb.checked) {
            qtyInput.disabled = false;
            if (!qtyInput.value || parseFloat(qtyInput.value) <= 0) qtyInput.value = cb.dataset.avail;
        } else {
            qtyInput.disabled = true;
        }
        recalcLotPicker();
    }

    function recalcLotPicker() {
        var checks = document.querySelectorAll('.lot-item-check:checked');
        var totalKg = 0, totalCost = 0;
        var items = [];

        checks.forEach(function(cb) {
            var uid = cb.dataset.uid;
            var qty = parseFloat(document.getElementById('qty_' + uid).value) || 0;
            var maxQty = parseFloat(cb.dataset.avail);
            if (qty > maxQty) { qty = maxQty; document.getElementById('qty_' + uid).value = maxQty; }
            if (qty <= 0) return;

            var cost = parseFloat(cb.dataset.cost);
            totalKg += qty;
            totalCost += qty * cost;
            items.push({
                purchase_id: cb.dataset.pid,
                lot_id: parseInt(cb.dataset.lot),
                lot_number: cb.dataset.lotnr,
                supplier_name: cb.dataset.supplier,
                quantity_kg: qty,
                cost_per_kg: cost
            });
        });

        // update summary inside picker
        var avgCost = totalKg > 0 ? (totalCost / totalKg) : 0;
        var summaryEl = document.getElementById('lotPickerSummary');
        if (items.length > 0) {
            summaryEl.innerHTML = '<i class="fas fa-check-circle" style="color:var(--success);"></i> ' + totalKg.toLocaleString() + ' kg · ' + Math.round(totalCost).toLocaleString() + ' F · Avg ' + Math.round(avgCost).toLocaleString() + ' F/kg';
        } else {
            summaryEl.textContent = '';
        }

        // mirror cost into the persistent Product Cost display next to weight
        var pcEl = document.getElementById('productCostDisplay');
        if (pcEl) {
            if (items.length > 0) {
                pcEl.textContent = Math.round(totalCost).toLocaleString() + ' F  (' + Math.round(avgCost).toLocaleString() + ' F/kg)';
            } else {
                pcEl.textContent = '0 F';
            }
        }

        // auto-fill weight + store items
        if (items.length > 0) {
            document.getElementById('weightKg').value = totalKg;
        }
        document.getElementById('selectedItems').value = JSON.stringify(items);
    }

    function onVehicleTypeChange() {
        var isRental = document.getElementById('vtRental').checked;
        var owned    = document.getElementById('ownedFields');
        var rental   = document.getElementById('rentalFields');
        if (owned) owned.style.display  = isRental ? 'none' : 'block';
        if (rental) rental.style.display = isRental ? 'block' : 'none';

        // when switching to Rental, clear owned-vehicle inputs so stale data doesn't get submitted
        if (isRental) {
            var v = document.getElementById('vehicleId'); if (v) v.value = '';
            var d = document.getElementById('driverName'); if (d) d.value = '';
        } else {
            // switching back to Owned: clear rental inputs
            ['rentalDriverName','rentalDriverPhone','rentalVehicleReg'].forEach(function(id){
                var el = document.getElementById(id); if (el) el.value = '';
            });
        }

        // vehicle dropdown is required only for Owned
        var vehSel = document.getElementById('vehicleId');
        if (vehSel) vehSel.required = !isRental;
    }

    function toggleStatusFields() {
        var status = document.getElementById('deliveryStatus').value;
        var weightAtDestGroup = document.getElementById('weightAtDestGroup');
        var rejectionGroup = document.getElementById('rejectionGroup');

        var showWeight = ['Delivered', 'Accepted', 'Rejected', 'Reassigned'].includes(status);
        weightAtDestGroup.style.display = showWeight ? 'block' : 'none';

        rejectionGroup.style.display = status === 'Rejected' ? 'block' : 'none';
        if (status === 'Rejected') {
            document.getElementById('rejectionReason').setAttribute('required', 'required');
        } else {
            document.getElementById('rejectionReason').removeAttribute('required');
        }
    }

    function openAddModal() {
        isEditMode = false;
        document.getElementById('modalTitle').innerHTML = '<i class="fas fa-truck-fast"></i> Add Delivery';
        document.getElementById('deliveryForm').reset();
        document.getElementById('deliveryId').value = '';
        document.getElementById('deliveryIdInfo').style.display = 'none';
        document.getElementById('customerId').value = '';
        document.getElementById('customerSearch').value = '';
        document.getElementById('season').value = ACTIVE_SEASON;
        document.getElementById('totalCostDisplay').textContent = '0';
        document.getElementById('productCostDisplay').textContent = '0 F';
        document.getElementById('statusGroup').style.display = 'none';
        document.getElementById('weightAtDestGroup').style.display = 'none';
        document.getElementById('rejectionGroup').style.display = 'none';
        document.getElementById('transportCost').value = '0';
        document.getElementById('roadFees').value = '0';
        document.getElementById('loadingCost').value = '0';
        document.getElementById('otherCost').value = '0';
        document.getElementById('selectedItems').value = '';
        window._editPrefillItems = null;

        // reset vehicle type → default Owned
        document.getElementById('vtOwned').checked = true;
        document.getElementById('rentalDriverName').value = '';
        document.getElementById('rentalDriverPhone').value = '';
        document.getElementById('rentalVehicleReg').value = '';
        onVehicleTypeChange();

        var today = new Date().toISOString().split('T')[0];
        document.getElementById('deliveryDate').value = today;

        document.getElementById('deliveryModal').classList.add('active');
    }

    function editDelivery(row) {
        isEditMode = true;
        document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit"></i> Edit Delivery';
        document.getElementById('deliveryId').value = row.delivery_id;
        document.getElementById('deliveryIdInfo').style.display = 'block';
        document.getElementById('deliveryIdDisplay').textContent = row.delivery_id;
        document.getElementById('deliveryDate').value = row.date_raw;

        document.getElementById('customerId').value = row.customer_id || '';
        document.getElementById('customerSearch').value = row.customer_id ? (row.customer_id + ' — ' + row.customer_name) : '';

        document.getElementById('originWarehouse').value = row.origin_warehouse_id || '';
        document.getElementById('vehicleId').value = row.vehicle_id || '';
        document.getElementById('driverName').value = row.driver_name || '';

        // vehicle type + rental fields
        if (row.vehicle_type === 'Rental') {
            document.getElementById('vtRental').checked = true;
        } else {
            document.getElementById('vtOwned').checked = true;
        }
        document.getElementById('rentalDriverName').value = row.rental_driver_name || '';
        document.getElementById('rentalDriverPhone').value = row.rental_driver_phone || '';
        document.getElementById('rentalVehicleReg').value = row.rental_vehicle_reg || '';
        onVehicleTypeChange();

        document.getElementById('weightKg').value = row.weight_kg;
        document.getElementById('numBags').value = row.num_bags;
        document.getElementById('transportCost').value = row.transport_cost || 0;
        document.getElementById('roadFees').value = row.road_fees || 0;
        document.getElementById('loadingCost').value = row.loading_cost || 0;
        document.getElementById('otherCost').value = row.other_cost || 0;
        document.getElementById('season').value = row.season;
        document.getElementById('notes').value = row.notes || '';

        // stash the saved items so onWarehouseChange can re-check them after the lot picker re-renders
        window._editPrefillItems = (row.items && row.items.length) ? row.items : null;
        // selectedItems hidden field starts as the raw saved set so a non-touched edit still posts them
        document.getElementById('selectedItems').value = window._editPrefillItems ? JSON.stringify(row.items) : '';

        // pre-fill product cost display from saved items (so it shows immediately, even before lots load)
        if (window._editPrefillItems) {
            var pcSum = 0, pcKg = 0;
            row.items.forEach(function(it){
                pcSum += parseFloat(it.total_cost) || 0;
                pcKg  += parseFloat(it.quantity_kg) || 0;
            });
            var pcAvg = pcKg > 0 ? (pcSum / pcKg) : 0;
            var pcEl = document.getElementById('productCostDisplay');
            if (pcEl) pcEl.textContent = Math.round(pcSum).toLocaleString() + ' F  (' + Math.round(pcAvg).toLocaleString() + ' F/kg)';
        }

        // trigger warehouse change which will fetch lots, then auto-prefill via applyPrefillItems
        if (row.origin_warehouse_id) onWarehouseChange();

        computeTotalCost();

        // Show status field in edit mode
        document.getElementById('statusGroup').style.display = 'block';
        document.getElementById('deliveryStatus').value = row.status;

        // Set status options based on current status
        var statusSelect = document.getElementById('deliveryStatus');
        var validTransitions = {
            'In Transit': ['In Transit'],
            'Delivered': ['Delivered'],
            'Rejected': ['Rejected'],
            'Reassigned': ['Reassigned']
        };

        var allowed = validTransitions[row.status] || [row.status];
        Array.from(statusSelect.options).forEach(function(opt) {
            opt.disabled = !allowed.includes(opt.value);
        });
        statusSelect.value = row.status;

        // Toggle conditional fields
        if (row.weight_at_destination) {
            document.getElementById('weightAtDest').value = row.weight_at_destination;
        }
        if (row.rejection_reason) {
            document.getElementById('rejectionReason').value = row.rejection_reason;
        }
        toggleStatusFields();

        document.getElementById('deliveryModal').classList.add('active');
    }

    function closeModal() {
        document.getElementById('deliveryModal').classList.remove('active');
        document.getElementById('deliveryForm').reset();
    }

    // Click outside to close
    document.getElementById('deliveryModal')?.addEventListener('click', function(e) {
        if (e.target === this) closeModal();
    });

    // Form submission
    document.getElementById('deliveryForm')?.addEventListener('submit', function(e) {
        e.preventDefault();

        if (!document.getElementById('customerId').value) {
            Swal.fire({ icon: 'warning', title: 'Validation', text: 'Please select a customer' });
            return;
        }

        var formData = new FormData(this);
        var action = isEditMode ? 'updateDelivery' : 'addDelivery';

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
                    setTimeout(function() { loadDeliveries(); }, 100);
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: response.message });
                }
            },
            error: function(xhr, status, error) {
                Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error: ' + error });
            }
        });
    });

    function reassignDelivery(deliveryId, currentCustomer) {
        var customerOptions = customersList.map(function(c) {
            return '<option value="' + c.id + '">' + c.id + ' — ' + c.name + '</option>';
        }).join('');

        Swal.fire({
            title: 'Reassign Delivery',
            html: '<div style="text-align:left;">' +
                '<p style="margin-bottom:8px;color:var(--text-muted);font-size:13px;">Rejected by: <strong>' + currentCustomer + '</strong></p>' +
                '<p style="margin-bottom:12px;color:#e74c3c;font-size:12px;"><i class="fas fa-info-circle"></i> This will record a rejection by the current customer</p>' +
                '<label style="font-size:13px;font-weight:600;">Reassign to *</label>' +
                '<select id="swalNewCustomer" class="swal2-select" style="margin-top:4px;"><option value="">Select customer...</option>' + customerOptions + '</select>' +
                '<label style="font-size:13px;font-weight:600;margin-top:12px;display:block;">Rejection / Reassign Reason</label>' +
                '<textarea id="swalReassignReason" class="swal2-textarea" placeholder="e.g. Quota full, humidity issue, quality rejected..." style="margin-top:4px;"></textarea>' +
                '</div>',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Reassign',
            confirmButtonColor: '#f39c12',
            preConfirm: function() {
                var newCust = document.getElementById('swalNewCustomer').value;
                if (!newCust) { Swal.showValidationMessage('Please select a customer'); return false; }
                return {
                    new_customer_id: newCust,
                    reason: document.getElementById('swalReassignReason').value.trim()
                };
            }
        }).then(function(result) {
            if (result.isConfirmed) {
                var fd = new FormData();
                fd.append('delivery_id', deliveryId);
                fd.append('new_customer_id', result.value.new_customer_id);
                fd.append('reason', result.value.reason);
                $.ajax({
                    url: '?action=reassignDelivery',
                    method: 'POST',
                    data: fd,
                    processData: false,
                    contentType: false,
                    dataType: 'json',
                    success: function(res) {
                        if (res.success) {
                            Swal.fire({icon:'success', title:'Reassigned', text: res.message, timer:2000, showConfirmButton:false});
                            loadDeliveries();
                        } else {
                            Swal.fire({icon:'error', title:'Error', text: res.message});
                        }
                    },
                    error: function() {
                        Swal.fire({icon:'error', title:'Error', text:'Connection error'});
                    }
                });
            }
        });
    }

    function printDeliveryReceipt(deliveryId) {
        Swal.fire({ title: 'Chargement...', allowOutsideClick: false, didOpen: function() { Swal.showLoading(); } });

        $.getJSON('?action=getDeliveryReceipt&delivery_id=' + encodeURIComponent(deliveryId), function(response) {
            Swal.close();
            if (!response.success) {
                Swal.fire({ icon: 'error', title: 'Erreur', text: response.message || 'Impossible de charger le bon' });
                return;
            }

            var d = response.data;
            var company = response.company_name || '7503 Canada';
            var companyInfo = response.companyInfo || {};

            // Format date as DD/MM/YYYY
            var dateParts = d.date.split('-');
            var dateFormatted = dateParts[2] + '/' + dateParts[1] + '/' + dateParts[0];

            // Format numbers
            function fmtNum(n) { return n ? Number(n).toLocaleString('fr-FR') : '0'; }
            function fmtMoney(n) { return n ? Number(n).toLocaleString('fr-FR') + ' FCFA' : '0 FCFA'; }

            // Status badge colors
            var statusColors = {
                'Pending': { bg: '#fff3cd', color: '#856404', label: 'En Attente' },
                'In Transit': { bg: '#cce5ff', color: '#004085', label: 'En Transit' },
                'Delivered': { bg: '#d4edda', color: '#155724', label: 'Livr\u00e9' },
                'Accepted': { bg: '#d4edda', color: '#155724', label: 'Accept\u00e9' },
                'Rejected': { bg: '#f8d7da', color: '#721c24', label: 'Rejet\u00e9' },
                'Reassigned': { bg: '#e2e3e5', color: '#383d41', label: 'R\u00e9assign\u00e9' }
            };
            var st = statusColors[d.status] || { bg: '#e2e3e5', color: '#383d41', label: d.status };

            // Vehicle info — rental vs owned
            var isRental = d.vehicle_type === 'Rental';
            var vehicleInfo, driverInfo;
            if (isRental) {
                vehicleInfo = (d.rental_vehicle_reg || 'N/A') + ' (Location)';
                driverInfo = d.rental_driver_name || 'N/A';
                if (d.rental_driver_phone) driverInfo += ' — ' + d.rental_driver_phone;
            } else {
                vehicleInfo = d.vehicle_id ? (d.vehicle_registration || d.vehicle_id) + (d.vehicle_model ? ' (' + d.vehicle_model + ')' : '') : 'N/A';
                driverInfo = d.driver_name || d.vehicle_driver_name || 'N/A';
            }

            // Check if costs exist
            var hasCosts = Number(d.transport_cost) > 0 || Number(d.loading_cost) > 0 || Number(d.other_cost) > 0 || Number(d.total_cost) > 0;

            // Check weight at destination
            var hasDestWeight = d.weight_at_destination && Number(d.weight_at_destination) > 0;

            // Build one copy
            function buildCopy(copyLabel) {
                var html = '';
                html += '<div class="receipt-copy">';

                // Header
                html += '<div class="receipt-header">';
                html += '<div class="header-left"><span class="company-name">' + company + '</span><br><span style="font-size:9px;color:#555;">Commerce de Noix de Cajou</span></div>';
                html += '<div class="header-right"><span class="doc-title">BON DE LIVRAISON</span><br><span class="doc-number">N\u00b0 ' + d.delivery_id + '</span></div>';
                html += '</div>';

                // Copy label badge
                html += '<div class="copy-badge">' + copyLabel + '</div>';

                // Info grid
                html += '<div class="info-grid">';
                html += '<div class="info-item"><span class="info-label">Date</span><span class="info-value">' + dateFormatted + '</span></div>';
                html += '<div class="info-item"><span class="info-label">Client</span><span class="info-value">' + (d.customer_name || 'N/A') + '</span></div>';
                html += '<div class="info-item"><span class="info-label">ID Client</span><span class="info-value">' + (d.customer_id || 'N/A') + '</span></div>';
                html += '<div class="info-item"><span class="info-label">Entrep\u00f4t d\'Origine</span><span class="info-value">' + (d.warehouse_name || 'N/A') + '</span></div>';
                html += '<div class="info-item"><span class="info-label">V\u00e9hicule</span><span class="info-value">' + vehicleInfo + '</span></div>';
                html += '<div class="info-item"><span class="info-label">Chauffeur</span><span class="info-value">' + driverInfo + '</span></div>';
                html += '</div>';

                // Delivery details table
                html += '<table class="detail-table">';
                html += '<thead><tr><th>Produit</th><th>Poids (kg)</th><th>Sacs</th><th>Statut</th></tr></thead>';
                html += '<tbody><tr>';
                html += '<td>Anacarde (Noix de Cajou Brutes)</td>';
                html += '<td style="text-align:right;">' + fmtNum(d.weight_kg) + '</td>';
                html += '<td style="text-align:right;">' + fmtNum(d.num_bags) + '</td>';
                html += '<td><span class="status-badge" style="background:' + st.bg + ';color:' + st.color + ';">' + st.label + '</span></td>';
                html += '</tr></tbody></table>';

                // Cost breakdown
                if (hasCosts) {
                    html += '<div class="section-title">D\u00e9tail des Co\u00fbts</div>';
                    html += '<table class="cost-table">';
                    if (Number(d.transport_cost) > 0) html += '<tr><td>Transport</td><td class="cost-val">' + fmtMoney(d.transport_cost) + '</td></tr>';
                    if (Number(d.loading_cost) > 0) html += '<tr><td>Chargement</td><td class="cost-val">' + fmtMoney(d.loading_cost) + '</td></tr>';
                    if (Number(d.other_cost) > 0) html += '<tr><td>Autres</td><td class="cost-val">' + fmtMoney(d.other_cost) + '</td></tr>';
                    html += '<tr class="cost-total"><td><strong>Total</strong></td><td class="cost-val"><strong>' + fmtMoney(d.total_cost) + '</strong></td></tr>';
                    html += '</table>';
                }

                // Weight at destination
                if (hasDestWeight) {
                    html += '<div class="section-title">Poids \u00e0 Destination</div>';
                    html += '<table class="cost-table">';
                    html += '<tr><td>Poids Exp\u00e9di\u00e9</td><td class="cost-val">' + fmtNum(d.weight_kg) + ' kg</td></tr>';
                    html += '<tr><td>Poids Re\u00e7u</td><td class="cost-val">' + fmtNum(d.weight_at_destination) + ' kg</td></tr>';
                    var diff = Number(d.weight_kg) - Number(d.weight_at_destination);
                    var diffColor = diff > 0 ? '#dc3545' : '#28a745';
                    html += '<tr class="cost-total"><td><strong>Diff\u00e9rence</strong></td><td class="cost-val" style="color:' + diffColor + ';"><strong>' + (diff > 0 ? '-' : '+') + fmtNum(Math.abs(diff)) + ' kg</strong></td></tr>';
                    html += '</table>';
                }

                // Notes
                if (d.notes) {
                    html += '<div class="section-title">Notes</div>';
                    html += '<div style="font-size:10px;padding:4px 0;color:#333;">' + d.notes + '</div>';
                }

                // Signatures
                html += '<div class="signatures">';
                html += '<div class="sig-block"><div class="sig-line"></div><span>Signature Exp\u00e9diteur</span></div>';
                html += '<div class="sig-block"><div class="sig-line"></div><span>Signature R\u00e9ceptionnaire</span></div>';
                html += '</div>';

                // Footer
                html += '<div class="receipt-footer">' + company + ' &mdash; G\u00e9n\u00e9r\u00e9 le ' + new Date().toLocaleDateString('fr-FR') + '</div>';

                html += '</div>';
                return html;
            }

            // Build full print content
            var printContent = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Bon de Livraison ' + d.delivery_id + '</title>';
            printContent += '<style>';
            printContent += '@page { size: A4; margin: 10mm; }';
            printContent += '* { box-sizing: border-box; margin: 0; padding: 0; }';
            printContent += 'body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; font-size: 11px; color: #222; background: #fff; padding: 0; }';
            printContent += '.receipt-copy { border: 2px solid #1a5c2a; border-radius: 6px; padding: 18px 20px 14px; margin-bottom: 0; page-break-inside: avoid; }';
            printContent += '.receipt-header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #1a5c2a; padding-bottom: 10px; margin-bottom: 10px; }';
            printContent += '.header-left .company-name { font-size: 18px; font-weight: 700; color: #1a5c2a; }';
            printContent += '.header-right { text-align: right; }';
            printContent += '.header-right .doc-title { font-size: 16px; font-weight: 700; color: #1a5c2a; }';
            printContent += '.header-right .doc-number { font-size: 13px; font-weight: 600; color: #333; }';
            printContent += '.copy-badge { display: inline-block; background: #1a5c2a; color: #fff; font-size: 10px; font-weight: 700; padding: 3px 12px; border-radius: 3px; margin-bottom: 10px; letter-spacing: 0.5px; text-transform: uppercase; }';
            printContent += '.info-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 6px 16px; margin-bottom: 12px; }';
            printContent += '.info-item { display: flex; flex-direction: column; }';
            printContent += '.info-label { font-size: 9px; color: #666; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; }';
            printContent += '.info-value { font-size: 11px; font-weight: 600; color: #222; }';
            printContent += '.detail-table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }';
            printContent += '.detail-table th { background: #1a5c2a; color: #fff; padding: 6px 10px; font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; text-align: left; }';
            printContent += '.detail-table td { padding: 7px 10px; border-bottom: 1px solid #ddd; font-size: 11px; }';
            printContent += '.section-title { font-size: 10px; font-weight: 700; color: #1a5c2a; text-transform: uppercase; letter-spacing: 0.5px; margin: 8px 0 4px; border-bottom: 1px solid #1a5c2a; padding-bottom: 2px; }';
            printContent += '.cost-table { width: 50%; margin-left: auto; border-collapse: collapse; margin-bottom: 8px; }';
            printContent += '.cost-table td { padding: 3px 8px; font-size: 10px; border-bottom: 1px solid #eee; }';
            printContent += '.cost-table .cost-val { text-align: right; font-weight: 600; }';
            printContent += '.cost-table .cost-total td { border-top: 2px solid #1a5c2a; border-bottom: none; }';
            printContent += '.status-badge { display: inline-block; padding: 2px 10px; border-radius: 3px; font-size: 10px; font-weight: 700; }';
            printContent += '.signatures { display: flex; justify-content: space-between; margin-top: 20px; padding-top: 10px; }';
            printContent += '.sig-block { text-align: center; width: 44%; }';
            printContent += '.sig-line { border-bottom: 1px solid #333; height: 40px; margin-bottom: 4px; }';
            printContent += '.sig-block span { font-size: 9px; color: #555; }';
            printContent += '.receipt-footer { text-align: center; font-size: 8px; color: #888; margin-top: 8px; padding-top: 4px; border-top: 1px solid #ddd; }';
            printContent += '.cut-line { display: flex; align-items: center; justify-content: center; margin: 14px 0; }';
            printContent += '.cut-line::before, .cut-line::after { content: ""; flex: 1; border-top: 2px dashed #999; }';
            printContent += '.cut-line span { padding: 0 12px; font-size: 9px; color: #999; font-weight: 600; letter-spacing: 1px; white-space: nowrap; }';
            printContent += '@media print { body { padding: 0; } .receipt-copy { border-width: 1.5px; } }';
            printContent += '</style></head><body>';

            // Copy 1: Client
            printContent += buildCopy('COPIE CLIENT');

            // Cut line
            printContent += '<div class="cut-line"><span>\u2702 COUPER ICI / D\u00c9TACHER</span></div>';

            // Copy 2: Cooperative
            printContent += buildCopy('COPIE COOP\u00c9RATIVE \u2014 ' + company);

            printContent += '</body></html>';

            // Open print window
            var printWin = window.open('', '_blank', 'width=800,height=1000');
            printWin.document.write(printContent);
            printWin.document.close();
            printWin.focus();
            setTimeout(function() { printWin.print(); }, 500);

        }).fail(function() {
            Swal.close();
            Swal.fire({ icon: 'error', title: 'Erreur', text: 'Erreur de connexion au serveur' });
        });
    }

    function rejectDelivery(deliveryId) {
        Swal.fire({
            title: 'Reject Delivery',
            html: '<div style="text-align:left;">' +
                '<label style="font-size:13px;font-weight:600;">Reason for rejection *</label>' +
                '<textarea id="swalRejectReason" class="swal2-textarea" placeholder="e.g. Humidity too high, quota full, quality issue..." style="margin-top:4px;"></textarea>' +
                '</div>',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#e74c3c',
            confirmButtonText: 'Reject',
            preConfirm: function() {
                var reason = document.getElementById('swalRejectReason').value.trim();
                if (!reason) { Swal.showValidationMessage('Rejection reason is required'); return false; }
                return { reason: reason };
            }
        }).then(function(result) {
            if (result.isConfirmed) {
                var formData = new FormData();
                formData.append('delivery_id', deliveryId);
                formData.append('rejection_reason', result.value.reason);
                $.ajax({
                    url: '?action=rejectDelivery',
                    method: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    dataType: 'json',
                    success: function(res) {
                        if (res.success) {
                            Swal.fire({icon:'success', title:'Rejected', text: res.message, timer:2000, showConfirmButton:false});
                            loadDeliveries();
                        } else {
                            Swal.fire({icon:'error', title:'Error', text: res.message});
                        }
                    }
                });
            }
        });
    }

    function deleteDelivery(deliveryId) {
        Swal.fire({
            title: 'Delete Delivery?',
            text: 'This action cannot be undone.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            confirmButtonText: 'Yes, delete it!'
        }).then(function(result) {
            if (result.isConfirmed) {
                var formData = new FormData();
                formData.append('delivery_id', deliveryId);

                $.ajax({
                    url: '?action=deleteDelivery',
                    method: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            Swal.fire({ icon: 'success', title: 'Deleted!', text: response.message, timer: 2000, showConfirmButton: false });
                            loadDeliveries();
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

    // quick-add customer
    function openQuickAddCustomer() {
        var modal = document.getElementById('quickCustomerModal');
        if (!modal) return;
        modal.classList.add('active');
        var locSel = document.getElementById('qcLocation');
        locSel.innerHTML = '<option value="">Select Location</option>';
        if (locationsData.length) {
            locationsData.forEach(function(l) {
                locSel.innerHTML += '<option value="' + l.location_id + '">' + l.location_name + '</option>';
            });
        }
    }

    function closeQuickCustomer() {
        document.getElementById('quickCustomerModal').classList.remove('active');
        document.getElementById('quickCustomerForm').reset();
    }

    document.getElementById('quickCustomerForm')?.addEventListener('submit', function(e) {
        e.preventDefault();
        var name = document.getElementById('qcName').value.trim();
        if (!name) { Swal.fire({icon:'warning', title:'Required', text:'Customer name is required'}); return; }

        var formData = new FormData();
        formData.append('customer_name', name);
        formData.append('phone', document.getElementById('qcPhone').value);
        formData.append('contact_person', document.getElementById('qcContact').value);
        formData.append('location_id', document.getElementById('qcLocation').value);

        Swal.fire({ title: 'Saving...', allowOutsideClick: false, didOpen: function() { Swal.showLoading(); } });

        $.ajax({
            url: '?action=quickAddCustomer',
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(res) {
                if (res.success) {
                    closeQuickCustomer();
                    Swal.fire({icon:'success', title:'Customer Added', text: res.customer_name + ' (' + res.customer_id + ')', timer:2000, showConfirmButton:false});
                    // add to customersList and select it
                    customersList.push({ id: res.customer_id, name: res.customer_name });
                    selectCustomer({ id: res.customer_id, name: res.customer_name });
                } else {
                    Swal.fire({icon:'error', title:'Error', text: res.message});
                }
            },
            error: function() {
                Swal.fire({icon:'error', title:'Error', text:'Connection error'});
            }
        });
    });
    </script>

  <script>
  (function(){
    var t = localStorage.getItem('cp_theme');
    if (t === 'dark' || (!t && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
      document.documentElement.classList.add('dark');
    }
  })();
  </script>
  <script>
  window._translations = {};
  function t(key) { return window._translations[key] || key; }
  function applyTranslations() {
    document.querySelectorAll('[data-t]').forEach(function(el) {
      var key = el.getAttribute('data-t');
      if (window._translations[key]) el.textContent = window._translations[key];
    });
  }
  document.addEventListener('DOMContentLoaded', function() {
    var lang = localStorage.getItem('cp_lang') || 'en';
    fetch('lang.php?lang=' + lang)
      .then(function(r){ return r.json(); })
      .then(function(data){ window._translations = data; applyTranslations(); })
      .catch(function(){});
  });
  </script>
</body>
</html>
