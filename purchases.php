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
$current_page = 'purchases';

// RBAC
$allowedRoles = ['Admin', 'Manager', 'Procurement Officer', 'Warehouse Clerk', 'Finance Officer'];
if (!in_array($role, $allowedRoles)) {
    header("Location: dashboard.php");
    exit();
}

$canCreate = in_array($role, ['Admin', 'Manager', 'Procurement Officer', 'Warehouse Clerk']);
$canUpdate = in_array($role, ['Admin', 'Manager', 'Procurement Officer', 'Warehouse Clerk']);
$canDelete = ($role === 'Admin');
$isReadOnly = ($role === 'Finance Officer');

// lot helpers
function getOrCreateOpenLot($conn, $warehouseId, $season) {
    if (!$warehouseId) return null;
    $stmt = $conn->prepare("SELECT lot_id FROM lots WHERE warehouse_id = ? AND season = ? AND status = 'Open' LIMIT 1");
    $stmt->bind_param("is", $warehouseId, $season);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows > 0) { $id = $res->fetch_assoc()['lot_id']; $stmt->close(); return $id; }
    $stmt->close();

    // get warehouse code for prefix
    $stmt = $conn->prepare("SELECT warehouse_code FROM settings_warehouses WHERE warehouse_id = ?");
    $stmt->bind_param("i", $warehouseId);
    $stmt->execute();
    $wcRes = $stmt->get_result();
    $whCode = ($wcRes->num_rows > 0) ? $wcRes->fetch_assoc()['warehouse_code'] : '';
    $stmt->close();
    if (empty($whCode)) $whCode = 'LOT';
    $prefixLen = strlen($whCode) + 1; // code + dash

    // next sequential number for this warehouse+season
    $stmt = $conn->prepare("SELECT COALESCE(MAX(CAST(SUBSTRING(lot_number, ? + 1) AS UNSIGNED)), 0) + 1 as n FROM lots WHERE warehouse_id = ? AND season = ?");
    $stmt->bind_param("iis", $prefixLen, $warehouseId, $season);
    $stmt->execute();
    $next = $stmt->get_result()->fetch_assoc()['n'];
    $stmt->close();

    $num = $whCode . '-' . str_pad($next, 3, '0', STR_PAD_LEFT);
    $stmt = $conn->prepare("INSERT INTO lots (lot_number, warehouse_id, season) VALUES (?, ?, ?)");
    $stmt->bind_param("sis", $num, $warehouseId, $season);
    $stmt->execute();
    $id = $stmt->insert_id;
    $stmt->close();
    return $id;
}

function recalcLot($conn, $lotId) {
    if (!$lotId) return;
    $stmt = $conn->prepare("SELECT COUNT(*) as cnt, COALESCE(SUM(weight_kg),0) as tw, COALESCE(SUM(total_cost),0) as tc FROM purchases WHERE lot_id = ?");
    $stmt->bind_param("i", $lotId);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $cnt = intval($r['cnt']); $tw = round(floatval($r['tw']), 2); $tc = round(floatval($r['tc']), 2);
    if ($cnt === 0) { $st = $conn->prepare("DELETE FROM lots WHERE lot_id = ?"); $st->bind_param("i", $lotId); $st->execute(); $st->close(); return; }
    $avg = $tw > 0 ? round($tc / $tw, 2) : 0;
    $stmt = $conn->prepare("SELECT target_weight_kg, status FROM lots WHERE lot_id = ?");
    $stmt->bind_param("i", $lotId);
    $stmt->execute();
    $lot = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $target = floatval($lot['target_weight_kg']);
    $newSt = $tw >= $target ? 'Closed' : 'Open';
    if ($newSt === 'Closed' && $lot['status'] !== 'Closed') {
        $stmt = $conn->prepare("UPDATE lots SET current_weight_kg=?, total_cost=?, avg_cost_per_kg=?, purchase_count=?, status='Closed', closed_at=NOW() WHERE lot_id=?");
    } elseif ($newSt === 'Open' && $lot['status'] === 'Closed') {
        $stmt = $conn->prepare("UPDATE lots SET current_weight_kg=?, total_cost=?, avg_cost_per_kg=?, purchase_count=?, status='Open', closed_at=NULL WHERE lot_id=?");
    } else {
        $stmt = $conn->prepare("UPDATE lots SET current_weight_kg=?, total_cost=?, avg_cost_per_kg=?, purchase_count=? WHERE lot_id=?");
    }
    $stmt->bind_param("dddii", $tw, $tc, $avg, $cnt, $lotId);
    $stmt->execute();
    $stmt->close();
}

// ensure source ENUM has Auto-Payable (safe for existing DBs)
function ensureFinancingSourceEnum($conn) {
    static $done = false;
    if ($done) return;
    $done = true;
    @$conn->query("ALTER TABLE financing MODIFY COLUMN source ENUM('Manual','Auto-Overpayment','Auto-Payable') DEFAULT 'Manual'");
}

// link a brand new purchase to the oldest active manual outgoing financing (informational link only).
// the actual cash math is done by reconcileSupplierAccount, which is called by the caller.
function linkPurchaseToFinancing($conn, $supplierId, $purchaseId) {
    if (!$supplierId || !$purchaseId) return;
    ensureFinancingSourceEnum($conn);

    $q = $conn->prepare("SELECT financing_id FROM financing WHERE counterparty_id = ? AND counterpart_type = 'Supplier' AND direction = 'Outgoing' AND source = 'Manual' AND status = 'Active' AND balance_due > 0 ORDER BY date ASC, financing_id ASC LIMIT 1");
    $q->bind_param("s", $supplierId);
    $q->execute();
    $r = $q->get_result();
    if ($r->num_rows > 0) {
        $finId = $r->fetch_assoc()['financing_id'];
        $q->close();
        $u = $conn->prepare("UPDATE purchases SET linked_financing_id = ? WHERE purchase_id = ?");
        $u->bind_param("ss", $finId, $purchaseId);
        $u->execute();
        $u->close();
    } else {
        $q->close();
    }
}

// wrapper for backward compat — actual logic moved to config.php
function syncSupplierFinancingBalance($conn, $supplierId) {
    reconcileSupplierAccount($conn, $supplierId);
}

// Handle AJAX requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    try {
        switch ($_GET['action']) {
            case 'getPurchases':
                $conn = getDBConnection();
                $stmt = $conn->prepare("SELECT p.*, l.location_name as origin_location_name, w.warehouse_name,
                    lt.lot_number, lt.current_weight_kg as lot_weight, lt.target_weight_kg as lot_target, lt.status as lot_status
                    FROM purchases p
                    LEFT JOIN settings_locations l ON p.origin_location_id = l.location_id
                    LEFT JOIN settings_warehouses w ON p.warehouse_id = w.warehouse_id
                    LEFT JOIN lots lt ON p.lot_id = lt.lot_id
                    ORDER BY p.purchase_id DESC");
                $stmt->execute();
                $result = $stmt->get_result();

                $purchases = [];
                while ($row = $result->fetch_assoc()) {
                    $purchases[] = [
                        'purchase_id' => $row['purchase_id'],
                        'date' => date('M d, Y', strtotime($row['date'])),
                        'date_raw' => $row['date'],
                        'supplier_id' => $row['supplier_id'],
                        'supplier_name' => $row['supplier_name'],
                        'origin_location_id' => $row['origin_location_id'],
                        'origin_location_name' => $row['origin_location_name'] ?? '',
                        'weight_kg' => $row['weight_kg'],
                        'num_bags' => $row['num_bags'],
                        'override_price_per_kg' => $row['override_price_per_kg'],
                        'final_price_per_kg' => $row['final_price_per_kg'],
                        'total_cost' => $row['total_cost'],
                        'kor_out_turn' => $row['kor_out_turn'],
                        'grainage' => $row['grainage'],
                        'visual_quality' => $row['visual_quality'],
                        'warehouse_id' => $row['warehouse_id'],
                        'warehouse_name' => $row['warehouse_name'] ?? '',
                        'receipt_number' => $row['receipt_number'],
                        'season' => $row['season'],
                        'payment_status' => $row['payment_status'],
                        'lot_id' => $row['lot_id'],
                        'lot_number' => $row['lot_number'] ?? '',
                        'lot_weight' => $row['lot_weight'] ?? 0,
                        'lot_target' => $row['lot_target'] ?? 50000,
                        'lot_status' => $row['lot_status'] ?? '',
                        'bags_in' => false
                    ];
                }

                $stmt->close();

                // check which purchases have bag log entries (bags_in toggle was on)
                $refIds = array_column($purchases, 'purchase_id');
                if (!empty($refIds)) {
                    $placeholders = implode(',', array_fill(0, count($refIds), '?'));
                    $types = str_repeat('s', count($refIds));
                    $blStmt = $conn->prepare("SELECT ref_number FROM bags_log WHERE ref_number IN ($placeholders)");
                    $blStmt->bind_param($types, ...$refIds);
                    $blStmt->execute();
                    $res = $blStmt->get_result();
                    $bagsInRefs = [];
                    while ($r = $res->fetch_assoc()) $bagsInRefs[$r['ref_number']] = true;
                    $blStmt->close();
                    foreach ($purchases as &$p) {
                        if (isset($bagsInRefs[$p['purchase_id']])) $p['bags_in'] = true;
                    }
                    unset($p);
                }

                $conn->close();

                echo json_encode(['success' => true, 'data' => $purchases]);
                exit();

            case 'getDropdowns':
                $conn = getDBConnection();

                // Active locations
                $locations = [];
                $stmt = $conn->prepare("SELECT location_id, location_name FROM settings_locations WHERE is_active = 1 ORDER BY location_name ASC");
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $locations[] = $row;
                }
                $stmt->close();

                // Active warehouses with location name
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

                // Active suppliers
                $suppliers = [];
                $stmt = $conn->prepare("SELECT supplier_id, first_name FROM suppliers WHERE status = 'Active' ORDER BY first_name ASC");
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $suppliers[] = $row;
                }
                $stmt->close();

                $conn->close();

                echo json_encode([
                    'success' => true,
                    'data' => [
                        'locations' => $locations,
                        'warehouses' => $warehouses,
                        'suppliers' => $suppliers
                    ]
                ]);
                exit();

            case 'getSupplierDetails':
                $supplierId = isset($_GET['supplier_id']) ? trim($_GET['supplier_id']) : '';
                if (empty($supplierId)) {
                    echo json_encode(['success' => false, 'message' => 'Supplier ID required']);
                    exit();
                }

                $conn = getDBConnection();
                $stmt = $conn->prepare("SELECT first_name, typical_price_per_kg, location_id FROM suppliers WHERE supplier_id = ?");
                $stmt->bind_param("s", $supplierId);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows > 0) {
                    $row = $result->fetch_assoc();
                    $stmt->close();

                    // fetch latest active pricing agreement
                    $agStmt = $conn->prepare("SELECT price_agreement_id, base_cost_per_kg, effective_date FROM supplier_pricing_agreements WHERE supplier_id = ? AND status = 'Active' ORDER BY effective_date DESC LIMIT 1");
                    $agStmt->bind_param("s", $supplierId);
                    $agStmt->execute();
                    $agRes = $agStmt->get_result();
                    if ($agRes->num_rows > 0) {
                        $ag = $agRes->fetch_assoc();
                        $row['agreement_price'] = $ag['base_cost_per_kg'];
                        $row['price_agreement_id'] = $ag['price_agreement_id'];
                        $row['agreement_date'] = $ag['effective_date'];
                    }
                    $agStmt->close();

                    $conn->close();
                    echo json_encode(['success' => true, 'data' => $row]);
                } else {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Supplier not found']);
                }
                exit();

            case 'addPurchase':
                if (!$canCreate) {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $date = isset($_POST['date']) ? trim($_POST['date']) : '';
                $supplierId = isset($_POST['supplier_id']) ? trim($_POST['supplier_id']) : '';
                $originLocationId = !empty($_POST['origin_location_id']) ? intval($_POST['origin_location_id']) : null;
                $weightKg = isset($_POST['weight_kg']) ? floatval($_POST['weight_kg']) : 0;
                $numBags = isset($_POST['num_bags']) ? intval($_POST['num_bags']) : 0;
                $overridePrice = !empty($_POST['override_price_per_kg']) ? floatval($_POST['override_price_per_kg']) : null;
                $korOutTurn = !empty($_POST['kor_out_turn']) ? floatval($_POST['kor_out_turn']) : null;
                $grainage = !empty($_POST['grainage']) ? floatval($_POST['grainage']) : null;
                $visualQuality = isset($_POST['visual_quality']) ? trim($_POST['visual_quality']) : null;
                $warehouseId = isset($_POST['warehouse_id']) ? intval($_POST['warehouse_id']) : 0;
                $receiptNumber = isset($_POST['receipt_number']) ? trim($_POST['receipt_number']) : null;
                $season = isset($_POST['season']) ? trim($_POST['season']) : getActiveSeason();
                $bagsIn = !empty($_POST['bags_in']) ? true : false;

                // Validation
                if (empty($date)) {
                    echo json_encode(['success' => false, 'message' => 'Date is required']);
                    exit();
                }
                if (empty($supplierId)) {
                    echo json_encode(['success' => false, 'message' => 'Supplier is required']);
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

                // Auto-generate purchase_id
                $newId = generateTransactionId($conn, 'ACH', 'purchases', 'purchase_id');

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

                // fetch latest active pricing agreement for supplier
                $agreementPrice = null;
                $priceAgreementId = null;
                if ($supplierId) {
                    $stmt = $conn->prepare("SELECT price_agreement_id, base_cost_per_kg FROM supplier_pricing_agreements WHERE supplier_id = ? AND status = 'Active' ORDER BY effective_date DESC LIMIT 1");
                    $stmt->bind_param("s", $supplierId);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    if ($res->num_rows > 0) {
                        $ag = $res->fetch_assoc();
                        $agreementPrice = $ag['base_cost_per_kg'];
                        $priceAgreementId = $ag['price_agreement_id'];
                    }
                    $stmt->close();

                    // fallback to typical_price if no agreement
                    if (!$agreementPrice) {
                        $stmt = $conn->prepare("SELECT typical_price_per_kg FROM suppliers WHERE supplier_id = ?");
                        $stmt->bind_param("s", $supplierId);
                        $stmt->execute();
                        $res = $stmt->get_result();
                        if ($res->num_rows > 0) {
                            $agreementPrice = $res->fetch_assoc()['typical_price_per_kg'];
                        }
                        $stmt->close();
                    }
                }

                $finalPrice = $overridePrice ?? $agreementPrice;
                $totalCost = ($finalPrice && $weightKg) ? round($finalPrice * $weightKg, 2) : 0;

                // auto-assign lot
                $lotId = getOrCreateOpenLot($conn, $warehouseId, $season);

                $stmt = $conn->prepare("INSERT INTO purchases (purchase_id, date, supplier_id, supplier_name, origin_location_id, weight_kg, num_bags, override_price_per_kg, final_price_per_kg, total_cost, kor_out_turn, grainage, visual_quality, warehouse_id, receipt_number, lot_id, season, payment_status, price_agreement_id, price_from_agreement) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', ?, ?)");
                $stmt->bind_param("ssssididddddsissssd",
                    $newId, $date, $supplierId, $supplierName, $originLocationId,
                    $weightKg, $numBags, $overridePrice, $finalPrice, $totalCost,
                    $korOutTurn, $grainage, $visualQuality, $warehouseId,
                    $receiptNumber, $lotId, $season, $priceAgreementId, $agreementPrice
                );

                if ($stmt->execute()) {
                    recalcLot($conn, $lotId);
                    $stmt->close();

                    // link purchase to oldest active manual advance (informational), then reconcile
                    linkPurchaseToFinancing($conn, $supplierId, $newId);
                    reconcileSupplierAccount($conn, $supplierId);

                    // auto-log bags in if toggle checked
                    if ($bagsIn && $numBags > 0) {
                        logBagMovement($conn, $date, null, $supplierId, "Purchase from $supplierName", $numBags, 0, $newId, null, null, $season);
                    }

                    logActivity($user_id, $username, 'Purchase Created', "Created purchase: $newId for supplier $supplierName ($supplierId), {$weightKg}kg");
                    $conn->close();
                    echo json_encode(['success' => true, 'message' => 'Purchase added successfully', 'purchase_id' => $newId]);
                } else {
                    $error = $stmt->error;
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to add purchase: ' . $error]);
                }
                exit();

            case 'updatePurchase':
                if (!$canUpdate) {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $purchaseId = isset($_POST['purchase_id']) ? trim($_POST['purchase_id']) : '';
                $date = isset($_POST['date']) ? trim($_POST['date']) : '';
                $supplierId = isset($_POST['supplier_id']) ? trim($_POST['supplier_id']) : '';
                $originLocationId = !empty($_POST['origin_location_id']) ? intval($_POST['origin_location_id']) : null;
                $weightKg = isset($_POST['weight_kg']) ? floatval($_POST['weight_kg']) : 0;
                $numBags = isset($_POST['num_bags']) ? intval($_POST['num_bags']) : 0;
                $overridePrice = !empty($_POST['override_price_per_kg']) ? floatval($_POST['override_price_per_kg']) : null;
                $korOutTurn = !empty($_POST['kor_out_turn']) ? floatval($_POST['kor_out_turn']) : null;
                $grainage = !empty($_POST['grainage']) ? floatval($_POST['grainage']) : null;
                $visualQuality = isset($_POST['visual_quality']) ? trim($_POST['visual_quality']) : null;
                $warehouseId = isset($_POST['warehouse_id']) ? intval($_POST['warehouse_id']) : 0;
                $receiptNumber = isset($_POST['receipt_number']) ? trim($_POST['receipt_number']) : null;
                $season = isset($_POST['season']) ? trim($_POST['season']) : getActiveSeason();
                $bagsIn = !empty($_POST['bags_in']) ? true : false;

                // Validation
                if (empty($purchaseId)) {
                    echo json_encode(['success' => false, 'message' => 'Purchase ID is required']);
                    exit();
                }
                if (empty($date)) {
                    echo json_encode(['success' => false, 'message' => 'Date is required']);
                    exit();
                }
                if (empty($supplierId)) {
                    echo json_encode(['success' => false, 'message' => 'Supplier is required']);
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

                // fetch latest active pricing agreement for supplier
                $agreementPrice = null;
                $priceAgreementId = null;
                if ($supplierId) {
                    $stmt = $conn->prepare("SELECT price_agreement_id, base_cost_per_kg FROM supplier_pricing_agreements WHERE supplier_id = ? AND status = 'Active' ORDER BY effective_date DESC LIMIT 1");
                    $stmt->bind_param("s", $supplierId);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    if ($res->num_rows > 0) {
                        $ag = $res->fetch_assoc();
                        $agreementPrice = $ag['base_cost_per_kg'];
                        $priceAgreementId = $ag['price_agreement_id'];
                    }
                    $stmt->close();

                    // fallback to typical_price if no agreement
                    if (!$agreementPrice) {
                        $stmt = $conn->prepare("SELECT typical_price_per_kg FROM suppliers WHERE supplier_id = ?");
                        $stmt->bind_param("s", $supplierId);
                        $stmt->execute();
                        $res = $stmt->get_result();
                        if ($res->num_rows > 0) {
                            $agreementPrice = $res->fetch_assoc()['typical_price_per_kg'];
                        }
                        $stmt->close();
                    }
                }

                $finalPrice = $overridePrice ?? $agreementPrice;
                $totalCost = ($finalPrice && $weightKg) ? round($finalPrice * $weightKg, 2) : 0;

                // get old lot + warehouse before update
                $oldStmt = $conn->prepare("SELECT lot_id, warehouse_id FROM purchases WHERE purchase_id = ?");
                $oldStmt->bind_param("s", $purchaseId);
                $oldStmt->execute();
                $oldRow = $oldStmt->get_result()->fetch_assoc();
                $oldStmt->close();
                $oldLotId = $oldRow['lot_id'] ?? null;
                $oldWhId = $oldRow['warehouse_id'] ?? null;

                // reassign lot if warehouse changed
                $newLotId = $oldLotId;
                if ($warehouseId != $oldWhId) {
                    $newLotId = getOrCreateOpenLot($conn, $warehouseId, $season);
                }

                $stmt = $conn->prepare("UPDATE purchases SET date = ?, supplier_id = ?, supplier_name = ?, origin_location_id = ?, weight_kg = ?, num_bags = ?, override_price_per_kg = ?, final_price_per_kg = ?, total_cost = ?, kor_out_turn = ?, grainage = ?, visual_quality = ?, warehouse_id = ?, receipt_number = ?, lot_id = ?, season = ?, price_agreement_id = ?, price_from_agreement = ? WHERE purchase_id = ?");
                $stmt->bind_param("sssididddddsisissds",
                    $date, $supplierId, $supplierName, $originLocationId,
                    $weightKg, $numBags, $overridePrice, $finalPrice, $totalCost,
                    $korOutTurn, $grainage, $visualQuality, $warehouseId,
                    $receiptNumber, $newLotId, $season, $priceAgreementId, $agreementPrice, $purchaseId
                );

                if ($stmt->execute()) {
                    recalcLot($conn, $newLotId);
                    if ($oldLotId && $oldLotId != $newLotId) recalcLot($conn, $oldLotId);
                    $stmt->close();

                    // canonical reconcile after purchase change
                    reconcileSupplierAccount($conn, $supplierId);

                    // sync bag log based on toggle
                    if ($bagsIn && $numBags > 0) {
                        $chk = $conn->prepare("SELECT bag_log_id FROM bags_log WHERE ref_number = ?");
                        $chk->bind_param("s", $purchaseId);
                        $chk->execute();
                        if ($chk->get_result()->num_rows > 0) {
                            updateBagLogByRef($conn, $purchaseId, $numBags, 0);
                        } else {
                            logBagMovement($conn, $date, null, $supplierId, "Purchase from $supplierName", $numBags, 0, $purchaseId, null, null, $season);
                        }
                        $chk->close();
                    } else {
                        removeBagLogByRef($conn, $purchaseId);
                    }

                    logActivity($user_id, $username, 'Purchase Updated', "Updated purchase: $purchaseId for supplier $supplierName ($supplierId)");
                    $conn->close();
                    echo json_encode(['success' => true, 'message' => 'Purchase updated successfully']);
                } else {
                    $error = $stmt->error;
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to update purchase: ' . $error]);
                }
                exit();

            case 'deletePurchase':
                if (!$canDelete) {
                    echo json_encode(['success' => false, 'message' => 'Access denied. Only Admin can delete purchases.']);
                    exit();
                }

                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $purchaseId = isset($_POST['purchase_id']) ? trim($_POST['purchase_id']) : '';
                if (empty($purchaseId)) {
                    echo json_encode(['success' => false, 'message' => 'Purchase ID is required']);
                    exit();
                }

                $conn = getDBConnection();

                // Get purchase info for logging + lot
                $stmt = $conn->prepare("SELECT supplier_name, supplier_id, weight_kg, lot_id FROM purchases WHERE purchase_id = ?");
                $stmt->bind_param("s", $purchaseId);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows == 0) {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Purchase not found']);
                    exit();
                }

                $purchaseInfo = $result->fetch_assoc();
                $stmt->close();

                // check linked payments
                $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM payments WHERE linked_purchase_id = ?");
                $stmt->bind_param("s", $purchaseId);
                $stmt->execute();
                $payCnt = intval($stmt->get_result()->fetch_assoc()['cnt']);
                $stmt->close();
                if ($payCnt > 0) {
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => "Cannot delete — purchase has $payCnt linked payment(s). Delete them first."]);
                    exit();
                }

                // remove linked delivery_items (traceability only)
                $stmt = $conn->prepare("DELETE FROM delivery_items WHERE purchase_id = ?");
                $stmt->bind_param("s", $purchaseId);
                $stmt->execute();
                $stmt->close();

                // remove linked bag log entry
                removeBagLogByRef($conn, $purchaseId);

                $stmt = $conn->prepare("DELETE FROM purchases WHERE purchase_id = ?");
                $stmt->bind_param("s", $purchaseId);

                if ($stmt->execute()) {
                    if ($purchaseInfo['lot_id']) recalcLot($conn, $purchaseInfo['lot_id']);
                    if ($purchaseInfo['supplier_id']) reconcileSupplierAccount($conn, $purchaseInfo['supplier_id']);
                    logActivity($user_id, $username, 'Purchase Deleted', "Deleted purchase: $purchaseId (Supplier: {$purchaseInfo['supplier_name']}, {$purchaseInfo['weight_kg']}kg)");
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => true, 'message' => 'Purchase deleted successfully']);
                } else {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to delete purchase']);
                }
                exit();

            case 'getPurchaseReceipt':
                $purchaseId = isset($_GET['purchase_id']) ? trim($_GET['purchase_id']) : '';
                if (empty($purchaseId)) {
                    echo json_encode(['success' => false, 'message' => 'Purchase ID required']);
                    exit();
                }

                $conn = getDBConnection();

                // Get purchase with location + warehouse
                $stmt = $conn->prepare("SELECT p.*, l.location_name, w.warehouse_name
                    FROM purchases p
                    LEFT JOIN settings_locations l ON p.origin_location_id = l.location_id
                    LEFT JOIN settings_warehouses w ON p.warehouse_id = w.warehouse_id
                    WHERE p.purchase_id = ?");
                $stmt->bind_param("s", $purchaseId);
                $stmt->execute();
                $purchase = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$purchase) {
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Purchase not found']);
                    exit();
                }

                // Get active financing for this supplier
                $financing = [];
                if ($purchase['supplier_id']) {
                    $stmt = $conn->prepare("SELECT financing_id, amount, amount_repaid, balance_due, status
                        FROM financing
                        WHERE counterparty_id = ? AND counterpart_type = 'Supplier' AND status IN ('Active','Overdue')
                        ORDER BY date DESC");
                    $stmt->bind_param("s", $purchase['supplier_id']);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    while ($row = $result->fetch_assoc()) $financing[] = $row;
                    $stmt->close();
                }

                // Get company info from system settings
                $companyInfo = getCompanyInfo();
                $companyName = $companyInfo['company_name'];

                // account balance = outgoing balance_due - incoming balance_due
                $accountBalance = 0;
                if ($purchase['supplier_id']) {
                    $sb = $conn->prepare("SELECT COALESCE(SUM(balance_due), 0) FROM financing WHERE counterparty_id = ? AND counterpart_type = 'Supplier' AND direction = 'Outgoing'");
                    $sb->bind_param("s", $purchase['supplier_id']);
                    $sb->execute();
                    $owed = floatval($sb->get_result()->fetch_row()[0]);
                    $sb->close();

                    $sb2 = $conn->prepare("SELECT COALESCE(SUM(balance_due), 0) FROM financing WHERE counterparty_id = ? AND counterpart_type = 'Supplier' AND direction = 'Incoming'");
                    $sb2->bind_param("s", $purchase['supplier_id']);
                    $sb2->execute();
                    $payable = floatval($sb2->get_result()->fetch_row()[0]);
                    $sb2->close();

                    $accountBalance = round($owed - $payable, 2);
                }

                $conn->close();
                echo json_encode(['success' => true, 'data' => [
                    'purchase' => [
                        'purchase_id' => $purchase['purchase_id'],
                        'date' => $purchase['date'],
                        'supplier_id' => $purchase['supplier_id'],
                        'supplier_name' => $purchase['supplier_name'],
                        'location_name' => $purchase['location_name'] ?? '',
                        'warehouse_name' => $purchase['warehouse_name'] ?? '',
                        'weight_kg' => $purchase['weight_kg'],
                        'num_bags' => $purchase['num_bags'],
                        'final_price_per_kg' => $purchase['final_price_per_kg'],
                        'total_cost' => $purchase['total_cost'],
                        'receipt_number' => $purchase['receipt_number'],
                        'season' => $purchase['season']
                    ],
                    'financing' => $financing,
                    'account_balance' => $accountBalance,
                    'companyName' => $companyName,
                    'companyInfo' => $companyInfo
                ]]);
                exit();

            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
                exit();
        }
    } catch (Exception $e) {
        error_log("Purchases.php error: " . $e->getMessage());
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
    <title>Purchases - Dashboard System</title>

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
                <h1><i class="fas fa-cart-shopping"></i> Purchases</h1>
                <div>Welcome, <?php echo htmlspecialchars($username); ?></div>
            </div>

            <div class="data-section">
                <div class="section-header">
                    <h2><i class="fas fa-table"></i> Purchases</h2>
                    <div style="display: flex; gap: 10px;">
                        <button class="btn btn-primary" onclick="loadPurchases()">
                            <i class="fas fa-sync"></i> Refresh
                        </button>
                        <?php if ($canCreate): ?>
                        <button class="btn btn-success" onclick="openAddModal()">
                            <i class="fas fa-plus"></i> Add Purchase
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
                            <label><i class="fas fa-calendar-alt"></i> Date From</label>
                            <input type="date" id="filterDateFrom" class="filter-input">
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-calendar-alt"></i> Date To</label>
                            <input type="date" id="filterDateTo" class="filter-input">
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-credit-card"></i> Payment Status</label>
                            <select id="filterPaymentStatus" class="filter-input">
                                <option value="">All</option>
                                <option value="Pending">Pending</option>
                                <option value="Prefinanced">Prefinanced</option>
                                <option value="Partial">Partial</option>
                                <option value="Paid">Paid</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-leaf"></i> Season</label>
                            <select id="filterSeason" class="filter-input">
                                <option value="">All Seasons</option>
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
                        <table id="purchasesTable" class="display" style="width:100%"></table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($canCreate || $canUpdate): ?>
    <!-- Purchase Modal -->
    <div class="modal-overlay" id="purchaseModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 id="modalTitle"><i class="fas fa-cart-plus"></i> Add Purchase</h3>
                <button class="close-btn" onclick="closeModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <!-- Purchase ID info (shown in edit mode) -->
                <div id="purchaseIdInfo" style="display: none; background: #f8f9fa; border: 1px solid #dee2e6; border-left: 4px solid var(--navy-primary); padding: 12px 16px; border-radius: 3px; margin-bottom: 20px;">
                    <strong><i class="fas fa-id-badge"></i> Purchase ID:</strong> <span id="purchaseIdDisplay"></span>
                </div>

                <form id="purchaseForm">
                    <input type="hidden" id="purchaseId" name="purchase_id">

                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-calendar-day"></i> Date *</label>
                            <input type="date" id="purchaseDate" name="date" required>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-truck-field"></i> Supplier *</label>
                            <div class="searchable-dropdown" id="supplierDropdownWrapper">
                                <input type="text" class="searchable-dropdown-input" id="supplierSearch" placeholder="Search supplier..." autocomplete="off">
                                <input type="hidden" id="supplierId" name="supplier_id" required>
                                <span class="searchable-dropdown-arrow" id="supplierArrow"><i class="fas fa-chevron-down"></i></span>
                                <div class="searchable-dropdown-list" id="supplierList" style="display:none;"></div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-map-marker-alt"></i> Origin Location</label>
                            <select id="originLocation" name="origin_location_id">
                                <option value="">Select Location</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-weight-hanging"></i> Weight (kg) *</label>
                            <input type="number" id="weightKg" name="weight_kg" step="0.01" min="0.01" required>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-boxes-stacked"></i> Number of Bags</label>
                            <input type="number" id="numBags" name="num_bags" min="0" step="1" value="0">
                        </div>

                        <div class="form-group" style="display:flex;align-items:center;gap:10px;padding-top:22px;">
                            <label class="toggle-switch" style="margin:0;">
                                <input type="checkbox" id="bagsInToggle" name="bags_in" value="1">
                                <span class="toggle-slider"></span>
                            </label>
                            <label for="bagsInToggle" style="cursor:pointer;font-size:13px;font-weight:600;color:var(--text-primary);margin:0;">
                                <i class="fas fa-boxes-packing" style="color:var(--navy-accent);margin-right:4px;"></i> Jute Bags In
                            </label>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-money-bill-wave"></i> Override Price/Kg</label>
                            <input type="text" inputmode="decimal" id="overridePrice" name="override_price_per_kg" class="money-input" placeholder="Leave empty for supplier default">
                            <small id="priceHint" style="display:none; color: var(--navy-accent); margin-top: 4px;"></small>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-percent"></i> KOR Out-Turn</label>
                            <input type="number" id="korOutTurn" name="kor_out_turn" step="0.01">
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-seedling"></i> Grainage</label>
                            <input type="number" id="grainage" name="grainage" step="0.01">
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-eye"></i> Visual Quality</label>
                            <select id="visualQuality" name="visual_quality">
                                <option value="">Select Quality</option>
                                <option value="Good">Good</option>
                                <option value="Fair">Fair</option>
                                <option value="Poor">Poor</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-warehouse"></i> Warehouse *</label>
                            <select id="warehouseId" name="warehouse_id" required>
                                <option value="">Select Warehouse</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-leaf"></i> Season *</label>
                            <?php echo renderSeasonDropdown('season', 'season'); ?>
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
        var ACTIVE_SEASON = '<?php echo addslashes(getActiveSeason()); ?>';
        let purchasesTable;
        let isEditMode = false;
        let purchasesData = [];
        let suppliersList = [];
        const isReadOnly = <?php echo $isReadOnly ? 'true' : 'false'; ?>;
        const canCreate = <?php echo $canCreate ? 'true' : 'false'; ?>;
        const canUpdate = <?php echo $canUpdate ? 'true' : 'false'; ?>;
        const canDelete = <?php echo $canDelete ? 'true' : 'false'; ?>;

        const paymentBadgeMap = {
            'Pending': 'status-pending',
            'Prefinanced': 'status-prefinanced',
            'Partial': 'status-partial',
            'Paid': 'status-paid'
        };

        $(document).ready(function() {
            loadDropdowns();
            loadPurchases();
        });

        function loadDropdowns() {
            $.ajax({
                url: '?action=getDropdowns',
                method: 'GET',
                dataType: 'json',
                success: function(response) {
                    if (response.success && response.data) {
                        // Populate locations
                        const locationSelect = document.getElementById('originLocation');
                        if (locationSelect) {
                            locationSelect.innerHTML = '<option value="">Select Location</option>';
                            response.data.locations.forEach(function(loc) {
                                const opt = document.createElement('option');
                                opt.value = loc.location_id;
                                opt.textContent = loc.location_name;
                                locationSelect.appendChild(opt);
                            });
                        }

                        // Populate warehouses
                        const warehouseSelect = document.getElementById('warehouseId');
                        if (warehouseSelect) {
                            warehouseSelect.innerHTML = '<option value="">Select Warehouse</option>';
                            response.data.warehouses.forEach(function(wh) {
                                const opt = document.createElement('option');
                                opt.value = wh.warehouse_id;
                                opt.textContent = wh.warehouse_name + (wh.location_name ? ' (' + wh.location_name + ')' : '');
                                warehouseSelect.appendChild(opt);
                            });
                        }

                        // Store suppliers for searchable dropdown
                        suppliersList = response.data.suppliers.map(function(s) {
                            return { id: s.supplier_id, name: s.first_name };
                        });

                        initSupplierDropdown();
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Failed to load dropdowns:', error);
                }
            });
        }

        // ===================== Searchable Supplier Dropdown =====================
        function initSupplierDropdown() {
            const input = document.getElementById('supplierSearch');
            const hiddenInput = document.getElementById('supplierId');
            const list = document.getElementById('supplierList');
            const arrow = document.getElementById('supplierArrow');

            if (!input) return;

            // Show dropdown on focus/click
            input.addEventListener('focus', function() {
                renderSupplierList(this.value);
                list.style.display = 'block';
                arrow.classList.add('open');
            });

            // Filter on typing
            input.addEventListener('input', function() {
                renderSupplierList(this.value);
                list.style.display = 'block';
            });

            // Close on click outside
            document.addEventListener('click', function(e) {
                if (!document.getElementById('supplierDropdownWrapper').contains(e.target)) {
                    list.style.display = 'none';
                    arrow.classList.remove('open');
                    // Restore selected text if no valid selection
                    const selectedSupplier = suppliersList.find(function(s) { return s.id === hiddenInput.value; });
                    if (selectedSupplier) {
                        input.value = selectedSupplier.id + ' — ' + selectedSupplier.name;
                    } else if (hiddenInput.value === '') {
                        input.value = '';
                    }
                }
            });
        }

        function renderSupplierList(searchTerm) {
            const list = document.getElementById('supplierList');
            const hiddenInput = document.getElementById('supplierId');
            list.innerHTML = '';

            const filtered = suppliersList.filter(function(s) {
                const label = s.id + ' — ' + s.name;
                return label.toLowerCase().includes((searchTerm || '').toLowerCase());
            });

            if (filtered.length === 0) {
                list.innerHTML = '<div class="searchable-dropdown-item no-results">No suppliers found</div>';
                return;
            }

            filtered.forEach(function(s) {
                const item = document.createElement('div');
                item.className = 'searchable-dropdown-item' + (hiddenInput.value === s.id ? ' selected' : '');
                item.textContent = s.id + ' — ' + s.name;
                item.addEventListener('click', function() {
                    selectSupplier(s);
                });
                list.appendChild(item);
            });
        }

        function selectSupplier(supplier) {
            document.getElementById('supplierId').value = supplier.id;
            document.getElementById('supplierSearch').value = supplier.id + ' — ' + supplier.name;
            document.getElementById('supplierList').style.display = 'none';
            document.getElementById('supplierArrow').classList.remove('open');

            // Auto-fill from supplier details
            fetchSupplierDetails(supplier.id);
        }

        function fetchSupplierDetails(supplierId) {
            $.ajax({
                url: '?action=getSupplierDetails&supplier_id=' + encodeURIComponent(supplierId),
                method: 'GET',
                dataType: 'json',
                success: function(response) {
                    if (response.success && response.data) {
                        // Auto-fill origin location
                        if (response.data.location_id) {
                            document.getElementById('originLocation').value = response.data.location_id;
                        }
                        // price from latest agreement > typical_price fallback
                        const priceHint = document.getElementById('priceHint');
                        const overrideField = document.getElementById('overridePrice');
                        if (response.data.agreement_price) {
                            const agPrice = parseFloat(response.data.agreement_price);
                            if (priceHint) {
                                priceHint.innerHTML = '<i class="fas fa-file-contract"></i> Agreement price: <strong>' + agPrice.toLocaleString() + ' /kg</strong> <span style="opacity:.6">(from ' + response.data.price_agreement_id + ')</span>';
                                priceHint.style.display = 'block';
                            }
                            // auto-fill override field with agreement price so user sees it, can change
                            if (overrideField && !overrideField.value) {
                                overrideField.value = agPrice;
                            }
                        } else if (response.data.typical_price_per_kg) {
                            if (priceHint) {
                                priceHint.textContent = 'Supplier default: ' + parseFloat(response.data.typical_price_per_kg).toLocaleString() + '/kg';
                                priceHint.style.display = 'block';
                            }
                        } else if (priceHint) {
                            priceHint.style.display = 'none';
                        }
                    }
                }
            });
        }

        // ===================== Load Purchases =====================
        function loadPurchases() {
            $('#skeletonLoader').show();
            $('#tableContainer').hide();

            $.ajax({
                url: '?action=getPurchases',
                method: 'GET',
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        purchasesData = response.data;
                        $('#filtersSection').show();
                        populateSeasonFilter(response.data);
                        initializeDataTable(response.data);
                    } else {
                        $('#skeletonLoader').hide();
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: response.message || 'Failed to load purchases'
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

        function populateSeasonFilter(data) {
            const seasons = [...new Set(data.map(function(d) { return d.season; }).filter(Boolean))];
            const select = document.getElementById('filterSeason');
            const currentVal = select.value;
            select.innerHTML = '<option value="">All Seasons</option>';
            seasons.sort().reverse().forEach(function(s) {
                const opt = document.createElement('option');
                opt.value = s;
                opt.textContent = s;
                select.appendChild(opt);
            });
            if (currentVal) select.value = currentVal;
        }

        // ===================== DataTable =====================
        function initializeDataTable(data) {
            if (purchasesTable) {
                purchasesTable.destroy();
                $('#purchasesTable').empty();
            }

            const columns = [
                {
                    data: 'purchase_id',
                    title: 'ID',
                    render: function(data) {
                        return '<span style="font-size:12px;font-family:monospace;">' + data + '</span>';
                    }
                },
                { data: 'date', title: 'Date' },
                {
                    data: 'supplier_name',
                    title: 'Supplier',
                    render: function(data, type, row) {
                        return data + '<br><small class="text-muted">' + row.supplier_id + '</small>';
                    }
                },
                { data: 'origin_location_name', title: 'Origin' },
                {
                    data: 'weight_kg',
                    title: 'Weight(kg)',
                    render: function(data) {
                        var kg = parseFloat(data) || 0;
                        var tons = (kg / 1000).toFixed(1);
                        return '<span title="' + tons + ' T" style="cursor:help;">' + Number(kg).toLocaleString() + ' kg</span>';
                    }
                },
                { data: 'num_bags', title: 'Bags' },
                {
                    data: 'final_price_per_kg',
                    title: 'Price/Kg',
                    render: function(data) {
                        return data ? parseFloat(data).toLocaleString() : 'N/A';
                    }
                },
                {
                    data: 'total_cost',
                    title: 'Total Cost',
                    render: function(data) {
                        return data ? parseFloat(data).toLocaleString() : '0';
                    }
                },
                {
                    data: 'payment_status',
                    title: 'Payment',
                    render: function(data) {
                        const cls = paymentBadgeMap[data] || 'status-pending';
                        return '<span class="status-badge ' + cls + '">' + (data || 'Pending') + '</span>';
                    }
                },
                {
                    data: 'lot_number',
                    title: 'Lot',
                    render: function(data, type, row) {
                        if (!data) return '<span style="color:var(--text-muted);">—</span>';
                        var pct = row.lot_target > 0 ? Math.min(Math.round(row.lot_weight / row.lot_target * 100), 100) : 0;
                        var color = row.lot_status === 'Closed' ? 'var(--success)' : 'var(--navy-accent)';
                        return '<span title="' + parseFloat(row.lot_weight).toLocaleString() + ' / ' + parseFloat(row.lot_target).toLocaleString() + ' kg (' + pct + '%)" style="font-weight:600;color:' + color + ';">' + data + '</span>' +
                            '<div style="background:var(--border-color);border-radius:3px;height:4px;margin-top:3px;width:60px;"><div style="background:' + color + ';border-radius:3px;height:100%;width:' + pct + '%;"></div></div>';
                    }
                }
            ];

            // Add actions column (print always visible)
            columns.push({
                data: null,
                title: 'Actions',
                orderable: false,
                render: function(data, type, row) {
                    let html = '<button class="action-icon" onclick="printPurchaseReceipt(\'' + row.purchase_id + '\')" title="Print Receipt" style="color:#001f3f;"><i class="fas fa-print"></i></button> ';
                    if (canUpdate) {
                        html += '<button class="action-icon edit-icon" onclick=\'editPurchase(' + JSON.stringify(row).replace(/'/g, "\\'") + ')\' title="Edit"><i class="fas fa-edit"></i></button> ';
                    }
                    if (canDelete) {
                        html += '<button class="action-icon delete-icon" onclick="deletePurchase(\'' + row.purchase_id + '\')" title="Delete"><i class="fas fa-trash"></i></button>';
                    }
                    return html;
                }
            });

            setTimeout(function() {
                purchasesTable = $('#purchasesTable').DataTable({
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

                $('#skeletonLoader').hide();
                $('#tableContainer').show();

                // Apply filters on change
                $('#filterDateFrom, #filterDateTo, #filterPaymentStatus, #filterSeason').on('change', function() {
                    applyFilters();
                });
            }, 100);
        }

        // ===================== Filters =====================
        function applyFilters() {
            if (!purchasesTable) return;

            $.fn.dataTable.ext.search = [];

            const dateFrom = document.getElementById('filterDateFrom').value;
            const dateTo = document.getElementById('filterDateTo').value;
            const paymentStatus = document.getElementById('filterPaymentStatus').value;
            const season = document.getElementById('filterSeason').value;

            // Date range filter
            if (dateFrom || dateTo) {
                $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
                    const rawDate = purchasesData[dataIndex]?.date_raw;
                    if (!rawDate) return true;
                    const recordDate = new Date(rawDate);
                    const fromDate = dateFrom ? new Date(dateFrom) : null;
                    const toDate = dateTo ? new Date(dateTo + 'T23:59:59') : null;
                    if (fromDate && recordDate < fromDate) return false;
                    if (toDate && recordDate > toDate) return false;
                    return true;
                });
            }

            // Payment status filter
            if (paymentStatus) {
                $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
                    return purchasesData[dataIndex]?.payment_status === paymentStatus;
                });
            }

            // Season filter
            if (season) {
                $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
                    return purchasesData[dataIndex]?.season === season;
                });
            }

            purchasesTable.draw();
        }

        function clearFilters() {
            document.getElementById('filterDateFrom').value = '';
            document.getElementById('filterDateTo').value = '';
            document.getElementById('filterPaymentStatus').value = '';
            document.getElementById('filterSeason').value = '';

            if (purchasesTable) {
                $.fn.dataTable.ext.search = [];
                purchasesTable.columns().search('').draw();
            }
        }

        <?php if ($canCreate || $canUpdate): ?>
        // ===================== Modal Functions =====================
        function openAddModal() {
            isEditMode = false;
            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-cart-plus"></i> Add Purchase';
            document.getElementById('purchaseForm').reset();
            document.getElementById('purchaseId').value = '';
            document.getElementById('purchaseIdInfo').style.display = 'none';
            document.getElementById('supplierId').value = '';
            document.getElementById('supplierSearch').value = '';
            document.getElementById('season').value = ACTIVE_SEASON;

            // Set date to today
            var today = new Date().toISOString().split('T')[0];
            document.getElementById('purchaseDate').value = today;

            // Hide price hint
            var priceHint = document.getElementById('priceHint');
            if (priceHint) priceHint.style.display = 'none';

            document.getElementById('purchaseModal').classList.add('active');
        }

        function editPurchase(row) {
            isEditMode = true;
            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit"></i> Edit Purchase';
            document.getElementById('purchaseId').value = row.purchase_id;
            document.getElementById('purchaseIdInfo').style.display = 'block';
            document.getElementById('purchaseIdDisplay').textContent = row.purchase_id;
            document.getElementById('purchaseDate').value = row.date_raw;

            // Set supplier searchable dropdown
            document.getElementById('supplierId').value = row.supplier_id;
            document.getElementById('supplierSearch').value = row.supplier_id + ' — ' + row.supplier_name;

            document.getElementById('originLocation').value = row.origin_location_id || '';
            document.getElementById('weightKg').value = row.weight_kg;
            document.getElementById('numBags').value = row.num_bags;
            document.getElementById('bagsInToggle').checked = !!row.bags_in;
            setMoneyVal('overridePrice', row.override_price_per_kg);
            document.getElementById('korOutTurn').value = row.kor_out_turn || '';
            document.getElementById('grainage').value = row.grainage || '';
            document.getElementById('visualQuality').value = row.visual_quality || '';
            document.getElementById('warehouseId').value = row.warehouse_id || '';
            document.getElementById('season').value = row.season || ACTIVE_SEASON;

            // Fetch supplier details for price hint
            if (row.supplier_id) {
                fetchSupplierDetails(row.supplier_id);
            }

            document.getElementById('purchaseModal').classList.add('active');
        }

        function closeModal() {
            document.getElementById('purchaseModal').classList.remove('active');
            document.getElementById('purchaseForm').reset();
        }

        document.getElementById('purchaseModal').addEventListener('click', function(e) {
            if (e.target === this) closeModal();
        });

        document.getElementById('purchaseForm').addEventListener('submit', function(e) {
            e.preventDefault();

            // Validate supplier is selected
            if (!document.getElementById('supplierId').value) {
                Swal.fire({ icon: 'warning', title: 'Validation', text: 'Please select a supplier' });
                return;
            }

            const formData = new FormData(this);
            const action = isEditMode ? 'updatePurchase' : 'addPurchase';

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
                        Swal.fire({
                            icon: 'success',
                            title: 'Success!',
                            text: response.message,
                            timer: 2000,
                            showConfirmButton: false
                        });
                        closeModal();
                        setTimeout(function() { loadPurchases(); }, 100);
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

        // ===================== Print Purchase Receipt =====================
        function printPurchaseReceipt(purchaseId) {
            Swal.fire({ title: 'Generating receipt...', allowOutsideClick: false, didOpen: function() { Swal.showLoading(); } });

            $.getJSON('?action=getPurchaseReceipt&purchase_id=' + encodeURIComponent(purchaseId)).done(function(r) {
                Swal.close();
                if (!r.success) { Swal.fire({ icon: 'error', title: 'Error', text: r.message }); return; }

                var p = r.data.purchase;
                var fin = r.data.financing;
                var ci = r.data.companyInfo || {};
                var company = ci.company_name || r.data.companyName || '7503 Canada';
                var companySub = ci.company_subtitle || 'Negoce de Noix de Cajou Brutes';
                var companyAddr = ci.company_address || 'Daloa, Cote d\'Ivoire';
                var receiptLogo = ci.receipt_logo_url || '';
                var fmtN = function(n) { return Number(n).toLocaleString('fr-FR'); };
                var fmtDate = function(d) {
                    var parts = d.split('-');
                    return parts[2] + '/' + parts[1] + '/' + parts[0];
                };
                var now = new Date();
                var genDate = now.toLocaleDateString('fr-FR') + ' à ' + now.toLocaleTimeString('fr-FR', {hour:'2-digit',minute:'2-digit'});

                // Financing rows HTML
                var finHtml = '';
                var accountBalance = parseFloat(r.data.account_balance) || 0;
                if (fin.length > 0) {
                    fin.forEach(function(f) {
                        finHtml += '<tr>' +
                            '<td>' + f.financing_id + '</td>' +
                            '<td style="text-align:right;">' + fmtN(f.amount) + ' FCFA</td>' +
                            '<td style="text-align:right;">' + fmtN(f.amount_repaid) + ' FCFA</td>' +
                            '<td style="text-align:right;">' + fmtN(f.balance_due) + ' FCFA</td>' +
                        '</tr>';
                    });
                } else {
                    finHtml = '<tr><td colspan="4" style="text-align:center;color:#999;padding:12px;">Aucun pré-financement actif</td></tr>';
                }

                // Build one copy of the receipt
                function buildCopy(copyLabel, copyClass) {
                    var logoHtml = receiptLogo ? '<img src="' + receiptLogo + '" class="receipt-logo" alt="Logo">' : '';
                    return '<div class="receipt-copy">' +
                        // Header
                        '<div class="receipt-header">' +
                            '<div style="display:flex;align-items:center;gap:10px;">' + logoHtml +
                            '<div><div class="company-name">' + company + '</div>' +
                            '<div class="company-sub">' + companySub + ' — ' + companyAddr + '</div></div></div>' +
                            '<div style="text-align:right;"><div class="receipt-title">REÇU DE PAIEMENT</div>' +
                            '<div class="receipt-num">N° ' + p.purchase_id + '</div></div>' +
                        '</div>' +
                        // Copy label
                        '<div class="copy-label ' + copyClass + '">' + copyLabel + '</div>' +
                        // Info grid
                        '<div class="info-grid-3">' +
                            '<div><div class="info-label">Date</div><div class="info-val">' + fmtDate(p.date) + '</div></div>' +
                            '<div><div class="info-label">Fournisseur</div><div class="info-val">' + (p.supplier_name || '-') + '</div></div>' +
                            '<div><div class="info-label">ID Fournisseur</div><div class="info-val">' + (p.supplier_id || '-') + '</div></div>' +
                            '<div><div class="info-label">Lieu</div><div class="info-val">' + (p.location_name || '-') + '</div></div>' +
                            '<div><div class="info-label">Entrepôt</div><div class="info-val">' + (p.warehouse_name || '-') + '</div></div>' +
                            '<div><div class="info-label">Réf. Achat</div><div class="info-val">' + p.purchase_id + '</div></div>' +
                        '</div>' +
                        // Purchase details table
                        '<div class="section-title">DÉTAILS DE L\'ACHAT</div>' +
                        '<table class="receipt-table">' +
                            '<thead><tr><th style="text-align:left;">Produit</th><th>Poids (kg)</th><th>Sacs</th><th>Prix/kg</th><th>Total</th></tr></thead>' +
                            '<tbody>' +
                                '<tr><td>Anacarde (Noix de Cajou Brutes)</td><td style="text-align:right;">' + fmtN(p.weight_kg) + '</td>' +
                                '<td style="text-align:right;">' + fmtN(p.num_bags) + '</td>' +
                                '<td style="text-align:right;">' + fmtN(p.final_price_per_kg) + ' FCFA</td>' +
                                '<td style="text-align:right;">' + fmtN(p.total_cost) + ' FCFA</td></tr>' +
                                '<tr class="total-row"><td><strong>TOTAL</strong></td><td></td><td></td><td></td>' +
                                '<td style="text-align:right;"><strong>' + fmtN(p.total_cost) + ' FCFA</strong></td></tr>' +
                            '</tbody>' +
                        '</table>' +
                        // Financing section
                        '<div class="section-title">SITUATION FINANCIÈRE — PRÉ-FINANCEMENT</div>' +
                        '<table class="receipt-table">' +
                            '<thead><tr><th style="text-align:left;">Réf. Financement</th><th>Montant Avancé</th><th>Remboursé</th><th>Solde Restant</th></tr></thead>' +
                            '<tbody>' + finHtml + '</tbody>' +
                        '</table>' +
                        // Account balance (unified)
                        '<div style="margin:4px 0;padding:3px 8px;font-size:10px;border-top:1px solid #e0e0e0;">' +
                            '<strong>Solde du Compte:</strong> ' + (accountBalance > 0.01 ? fmtN(accountBalance) + ' FCFA (Fournisseur nous doit)' : accountBalance < -0.01 ? fmtN(Math.abs(accountBalance)) + ' FCFA (Nous devons au fournisseur)' : '0 FCFA (Soldé)') +
                        '</div>' +
                        // Signatures
                        '<div class="signatures">' +
                            '<div class="sig-block">' +
                                '<div class="sig-line"></div>' +
                                '<div class="sig-label">Signature ' + company + '</div>' +
                            '</div>' +
                            '<div class="sig-block">' +
                                '<div class="sig-line"></div>' +
                                '<div class="sig-label">Signature Fournisseur</div>' +
                                '<div class="sig-name">' + (p.supplier_name || '') + '</div>' +
                                '<div class="sig-note">(Précédé de la mention "lu et approuvé")</div>' +
                            '</div>' +
                        '</div>' +
                        // Footer
                        '<div class="receipt-footer">' +
                            '<span>' + company + ' — ' + companyAddr + '</span>' +
                            '<span>Réf: ' + p.purchase_id + ' | Généré le ' + genDate + '</span>' +
                        '</div>' +
                    '</div>';
                }

                var receiptHTML = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Reçu - ' + p.purchase_id + '</title>' +
                '<style>' +
                    '@page { size: A4; margin: 6mm 10mm; }' +
                    '* { margin: 0; padding: 0; box-sizing: border-box; }' +
                    'body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; font-size: 10px; color: #333; }' +
                    '.receipt-copy { padding: 10px 14px 6px; page-break-inside: avoid; }' +
                    '.receipt-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 8px; }' +
                    '.company-name { font-size: 17px; font-weight: 800; color: #1a5c2a; }' +
                    '.company-sub { font-size: 9px; color: #555; margin-top: 1px; }' +
                    '.receipt-title { font-size: 13px; font-weight: 700; color: #1a5c2a; }' +
                    '.receipt-num { font-size: 10px; color: #555; margin-top: 1px; }' +
                    '.copy-label { display: inline-block; padding: 2px 10px; border: 1.5px solid #1a5c2a; font-size: 9px; font-weight: 700; text-transform: uppercase; margin-bottom: 6px; letter-spacing: 0.5px; }' +
                    '.copy-supplier { color: #1a5c2a; }' +
                    '.copy-company { color: #fff; background: #1a5c2a; }' +
                    '.info-grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 2px 14px; margin-bottom: 8px; border-bottom: 1px solid #e0e0e0; padding-bottom: 6px; }' +
                    '.info-label { font-size: 8px; color: #888; text-transform: uppercase; letter-spacing: 0.3px; }' +
                    '.info-val { font-size: 11px; font-weight: 700; color: #222; }' +
                    '.section-title { font-size: 10px; font-weight: 700; color: #222; margin: 6px 0 3px; text-transform: uppercase; letter-spacing: 0.3px; }' +
                    '.receipt-table { width: 100%; border-collapse: collapse; margin-bottom: 4px; font-size: 10px; }' +
                    '.receipt-table thead th { background: #1a5c2a; color: #fff; padding: 3px 6px; font-size: 9px; font-weight: 600; text-align: right; }' +
                    '.receipt-table thead th:first-child { text-align: left; }' +
                    '.receipt-table tbody td { padding: 3px 6px; border-bottom: 1px solid #eee; }' +
                    '.receipt-table .total-row td { border-top: 2px solid #1a5c2a; border-bottom: none; font-weight: 700; }' +
                    '.signatures { display: flex; justify-content: space-between; margin-top: 14px; gap: 30px; }' +
                    '.sig-block { flex: 1; }' +
                    '.sig-line { border-bottom: 1px solid #333; margin-bottom: 4px; height: 28px; }' +
                    '.sig-label { font-size: 9px; color: #555; }' +
                    '.sig-name { font-size: 10px; font-weight: 700; }' +
                    '.sig-note { font-size: 8px; color: #888; font-style: italic; }' +
                    '.receipt-footer { display: flex; justify-content: space-between; font-size: 8px; color: #888; margin-top: 4px; padding-top: 4px; border-top: 1px solid #e0e0e0; }' +
                    '.cut-line { text-align: center; padding: 3px 0; font-size: 9px; color: #aaa; letter-spacing: 2px; border-top: 2px dashed #ccc; border-bottom: 2px dashed #ccc; margin: 2px 0; }' +
                '</style></head><body>' +
                    buildCopy('COPIE FOURNISSEUR', 'copy-supplier') +
                    '<div class="cut-line">- - - - - COUPER ICI / DÉTACHER - - - - -</div>' +
                    buildCopy('COPIE COOPÉRATIVE — ' + company, 'copy-company') +
                '</body></html>';

                var printWin = window.open('', '_blank', 'width=800,height=1100');
                printWin.document.write(receiptHTML);
                printWin.document.close();
                printWin.onload = function() {
                    printWin.focus();
                    printWin.print();
                };
            }).fail(function() {
                Swal.close();
                Swal.fire({ icon: 'error', title: 'Error', text: 'Failed to load receipt data' });
            });
        }

        <?php if ($canDelete): ?>
        function deletePurchase(purchaseId) {
            Swal.fire({
                icon: 'warning',
                title: 'Delete Purchase?',
                text: 'Are you sure you want to delete purchase ' + purchaseId + '? This action cannot be undone.',
                showCancelButton: true,
                confirmButtonColor: '#ea4335',
                confirmButtonText: 'Yes, delete it',
                cancelButtonText: 'Cancel'
            }).then(function(result) {
                if (result.isConfirmed) {
                    const formData = new FormData();
                    formData.append('purchase_id', purchaseId);

                    $.ajax({
                        url: '?action=deletePurchase',
                        method: 'POST',
                        data: formData,
                        processData: false,
                        contentType: false,
                        dataType: 'json',
                        success: function(response) {
                            if (response.success) {
                                Swal.fire({ icon: 'success', text: response.message, timer: 2000, showConfirmButton: false });
                                setTimeout(function() { loadPurchases(); }, 100);
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
