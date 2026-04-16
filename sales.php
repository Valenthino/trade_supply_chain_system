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
$current_page = 'sales';

// RBAC
$allowedRoles = ['Admin', 'Manager', 'Sales Officer', 'Finance Officer'];
if (!in_array($role, $allowedRoles)) {
    header("Location: dashboard.php");
    exit();
}

$canCreate = in_array($role, ['Admin', 'Manager', 'Sales Officer']);
$canUpdate = in_array($role, ['Admin', 'Manager', 'Sales Officer']);
$canDelete = ($role === 'Admin');
$isReadOnly = ($role === 'Finance Officer');

// Handle AJAX requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    try {
        switch ($_GET['action']) {
            case 'getSales':
                $conn = getDBConnection();
                $stmt = $conn->prepare("SELECT s.*, c.customer_name as cust_display_name,
                    d.transport_cost as delivery_transport_cost, d.total_cost as delivery_total_cost,
                    d.procurement_cost_per_kg as delivery_proc_cost_per_kg,
                    d.weight_kg as delivery_weight_kg,
                    d.customer_name as delivery_customer_name,
                    l.location_name
                    FROM sales s
                    LEFT JOIN deliveries d ON s.delivery_id = d.delivery_id
                    LEFT JOIN customers c ON s.customer_id = c.customer_id
                    LEFT JOIN settings_locations l ON s.location_id = l.location_id
                    ORDER BY s.sale_id DESC");
                $stmt->execute();
                $result = $stmt->get_result();

                $sales = [];
                while ($row = $result->fetch_assoc()) {
                    $sales[] = [
                        'sale_id' => $row['sale_id'],
                        'delivery_id' => $row['delivery_id'],
                        'customer_id' => $row['customer_id'],
                        'customer_name' => $row['cust_display_name'] ?? $row['delivery_customer_name'] ?? '',
                        'delivery_customer_name' => $row['delivery_customer_name'] ?? '',
                        'delivery_transport_cost' => $row['delivery_transport_cost'] ?? 0,
                        'delivery_total_cost' => $row['delivery_total_cost'] ?? 0,
                        'delivery_proc_cost_per_kg' => $row['delivery_proc_cost_per_kg'] ?? 0,
                        'delivery_weight_kg' => $row['delivery_weight_kg'] ?? 0,
                        'sale_status' => $row['sale_status'],
                        'unloading_date' => date('M d, Y', strtotime($row['unloading_date'])),
                        'unloading_date_raw' => $row['unloading_date'],
                        'location_id' => $row['location_id'],
                        'location_name' => $row['location_name'] ?? '',
                        'first_weight_kg' => $row['first_weight_kg'],
                        'second_weight_kg' => $row['second_weight_kg'],
                        'gross_weight_kg' => $row['gross_weight_kg'],
                        'empty_bags_qty' => $row['empty_bags_qty'],
                        'refraction_quality_kg' => $row['refraction_quality_kg'],
                        'penalty_defective_bags_kg' => $row['penalty_defective_bags_kg'],
                        'net_weight_kg' => $row['net_weight_kg'],
                        'purchase_weight_kg' => $row['purchase_weight_kg'] ?? 0,
                        'kor_at_sale' => $row['kor_at_sale'],
                        'humidity_at_sale' => $row['humidity_at_sale'],
                        'selling_price_per_kg' => $row['selling_price_per_kg'],
                        'gross_sale_amount' => $row['gross_sale_amount'],
                        'total_costs' => $row['total_costs'],
                        'gross_margin' => $row['gross_margin'],
                        'transport_cost' => $row['transport_cost'],
                        'other_expenses' => $row['other_expenses'],
                        'interest_fees' => $row['interest_fees'],
                        'net_revenue' => $row['net_revenue'],
                        'net_profit' => $row['net_profit'],
                        'profit_per_kg' => $row['profit_per_kg'],
                        'season' => $row['season'],
                        'receipt_file' => $row['receipt_file'] ?? null
                    ];
                }

                $stmt->close();
                $conn->close();

                echo json_encode(['success' => true, 'data' => $sales]);
                exit();

            case 'getDropdowns':
                $conn = getDBConnection();

                // Eligible deliveries (all except Rejected/Reassigned)
                $deliveries = [];
                $stmt = $conn->prepare("SELECT delivery_id, customer_id, customer_name, weight_kg, transport_cost, total_cost, status FROM deliveries WHERE status NOT IN ('Rejected','Reassigned') ORDER BY delivery_id DESC");
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $deliveries[] = $row;
                }
                $stmt->close();

                // Active locations
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
                        'deliveries' => $deliveries,
                        'locations' => $locations
                    ]
                ]);
                exit();

            case 'getDeliveryDetails':
                $deliveryId = isset($_GET['delivery_id']) ? trim($_GET['delivery_id']) : '';
                if (empty($deliveryId)) {
                    echo json_encode(['success' => false, 'message' => 'Delivery ID required']);
                    exit();
                }

                $conn = getDBConnection();
                $stmt = $conn->prepare("SELECT customer_id, customer_name, transport_cost, weight_kg, total_cost, procurement_cost_per_kg FROM deliveries WHERE delivery_id = ?");
                $stmt->bind_param("s", $deliveryId);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows > 0) {
                    $row = $result->fetch_assoc();
                    $stmt->close();

                    // pull actual purchase weight + cost from delivery_items (the weight bought, not customer-measured)
                    $diStmt = $conn->prepare("SELECT COALESCE(SUM(di.quantity_kg),0) as qty, COALESCE(SUM(di.total_cost),0) as cost FROM delivery_items di JOIN purchases p ON p.purchase_id = di.purchase_id WHERE di.delivery_id = ?");
                    $diStmt->bind_param("s", $deliveryId);
                    $diStmt->execute();
                    $di = $diStmt->get_result()->fetch_assoc();
                    $diStmt->close();

                    $diQty = floatval($di['qty'] ?? 0);
                    $diCost = floatval($di['cost'] ?? 0);
                    // legacy fallback: no delivery_items -> use delivery's loaded weight x proc cost
                    if ($diQty <= 0) {
                        $diQty = floatval($row['weight_kg']);
                        $diCost = round($diQty * floatval($row['procurement_cost_per_kg']), 2);
                    }
                    $row['purchase_weight_kg'] = $diQty;
                    $row['product_cost'] = $diCost;

                    $conn->close();
                    echo json_encode(['success' => true, 'data' => $row]);
                } else {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Delivery not found']);
                }
                exit();

            case 'addSale':
                if (!$canCreate) {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $deliveryId = isset($_POST['delivery_id']) ? trim($_POST['delivery_id']) : '';
                $unloadingDate = isset($_POST['unloading_date']) ? trim($_POST['unloading_date']) : '';
                $locationId = !empty($_POST['location_id']) ? intval($_POST['location_id']) : null;
                $firstWeightKg = isset($_POST['first_weight_kg']) && $_POST['first_weight_kg'] !== '' ? floatval($_POST['first_weight_kg']) : null;
                $secondWeightKg = isset($_POST['second_weight_kg']) && $_POST['second_weight_kg'] !== '' ? floatval($_POST['second_weight_kg']) : null;
                $grossWeightKg = isset($_POST['gross_weight_kg']) ? floatval($_POST['gross_weight_kg']) : 0;
                $emptyBagsQty = isset($_POST['empty_bags_qty']) ? intval($_POST['empty_bags_qty']) : 0;
                $refractionQualityKg = isset($_POST['refraction_quality_kg']) ? floatval($_POST['refraction_quality_kg']) : 0;
                $penaltyDefectiveBagsKg = isset($_POST['penalty_defective_bags_kg']) ? floatval($_POST['penalty_defective_bags_kg']) : 0;
                $korAtSale = !empty($_POST['kor_at_sale']) ? floatval($_POST['kor_at_sale']) : null;
                $humidityAtSale = !empty($_POST['humidity_at_sale']) ? floatval($_POST['humidity_at_sale']) : null;
                $sellingPricePerKg = isset($_POST['selling_price_per_kg']) ? floatval($_POST['selling_price_per_kg']) : 0;
                $otherExpenses = isset($_POST['other_expenses']) ? floatval($_POST['other_expenses']) : 0;
                $interestFees = isset($_POST['interest_fees']) ? floatval($_POST['interest_fees']) : 0;
                $saleStatus = isset($_POST['sale_status']) ? trim($_POST['sale_status']) : 'Draft';
                $season = isset($_POST['season']) ? trim($_POST['season']) : getActiveSeason();

                // gross = first - second (if both provided), otherwise use posted gross
                if ($firstWeightKg !== null && $secondWeightKg !== null) {
                    $grossWeightKg = round($firstWeightKg - $secondWeightKg, 2);
                }
                // net = gross - bags - refraction - penalty
                $netWeightKg = round($grossWeightKg - $emptyBagsQty - $refractionQualityKg - $penaltyDefectiveBagsKg, 2);

                // Validation
                if (empty($deliveryId)) {
                    echo json_encode(['success' => false, 'message' => 'Delivery is required']);
                    exit();
                }
                if (empty($unloadingDate)) {
                    echo json_encode(['success' => false, 'message' => 'Unloading date is required']);
                    exit();
                }
                if ($grossWeightKg <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Gross weight must be greater than 0']);
                    exit();
                }
                if ($netWeightKg <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Net weight must be greater than 0']);
                    exit();
                }
                if ($sellingPricePerKg <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Selling price per kg must be greater than 0']);
                    exit();
                }
                if (empty($season)) {
                    echo json_encode(['success' => false, 'message' => 'Season is required']);
                    exit();
                }

                $conn = getDBConnection();

                // Auto-generate sale_id (VTE-YY-MMDD-XXXX-C)
                $newId = generateTransactionId($conn, 'VTE', 'sales', 'sale_id');

                // Get delivery details (incl procurement cost)
                $stmt = $conn->prepare("SELECT customer_id, customer_name, transport_cost, loading_cost, other_cost, total_cost, procurement_cost_per_kg, weight_kg FROM deliveries WHERE delivery_id = ?");
                $stmt->bind_param("s", $deliveryId);
                $stmt->execute();
                $delResult = $stmt->get_result();
                if ($delResult->num_rows === 0) {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Delivery not found']);
                    exit();
                }
                $delivery = $delResult->fetch_assoc();
                $stmt->close();

                $customerId = $delivery['customer_id'];
                $deliveryCost = floatval($delivery['total_cost']); // transport + loading + other
                $procCostPerKg = floatval($delivery['procurement_cost_per_kg']);

                // product cost = sum of delivery_items (actual purchase weight x purchase cost)
                // this uses the weight bought/collected -- NOT loaded/delivered weight -- protects from scale drift
                $diStmt = $conn->prepare("SELECT COALESCE(SUM(di.quantity_kg),0) as qty, COALESCE(SUM(di.total_cost),0) as cost FROM delivery_items di JOIN purchases p ON p.purchase_id = di.purchase_id WHERE di.delivery_id = ?");
                $diStmt->bind_param("s", $deliveryId);
                $diStmt->execute();
                $diRow = $diStmt->get_result()->fetch_assoc();
                $diStmt->close();
                $purchaseWeightKg = floatval($diRow['qty'] ?? 0);
                $productCost = round(floatval($diRow['cost'] ?? 0), 2);
                // legacy fallback for deliveries with no delivery_items
                if ($purchaseWeightKg <= 0) {
                    $purchaseWeightKg = floatval($delivery['weight_kg']);
                    $productCost = round($procCostPerKg * $purchaseWeightKg, 2);
                }

                $grossSaleAmount = round($netWeightKg * $sellingPricePerKg, 2);
                // total = product + delivery logistics + sale expenses + interest
                $totalCosts = round($productCost + $deliveryCost + $otherExpenses + $interestFees, 2);
                $grossMargin = round($grossSaleAmount - $totalCosts, 2);
                $netRevenue = round($grossSaleAmount - $otherExpenses - $interestFees, 2);
                $netProfit = round($grossSaleAmount - $totalCosts, 2);
                $profitPerKg = ($netWeightKg > 0) ? round($netProfit / $netWeightKg, 2) : 0;

                // Null-safe for bind_param (PHP 8.2+)
                $locationIdSafe = $locationId !== null ? strval($locationId) : null;
                $firstWeightSafe = $firstWeightKg !== null ? strval($firstWeightKg) : null;
                $secondWeightSafe = $secondWeightKg !== null ? strval($secondWeightKg) : null;
                $korAtSaleSafe = $korAtSale !== null ? strval($korAtSale) : null;
                $humidityAtSaleSafe = $humidityAtSale !== null ? strval($humidityAtSale) : null;

                $stmt = $conn->prepare("INSERT INTO sales (sale_id, delivery_id, customer_id, sale_status, unloading_date, location_id, first_weight_kg, second_weight_kg, gross_weight_kg, empty_bags_qty, refraction_quality_kg, penalty_defective_bags_kg, net_weight_kg, purchase_weight_kg, kor_at_sale, humidity_at_sale, selling_price_per_kg, gross_sale_amount, total_costs, gross_margin, transport_cost, other_expenses, interest_fees, net_revenue, net_profit, profit_per_kg, season) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                // types: s s s s s s d d d i d d d d s s d d d d d d d d d d s
                $stmt->bind_param("ssssssdddiddddssdddddddddds",
                    $newId, $deliveryId, $customerId, $saleStatus, $unloadingDate, $locationIdSafe,
                    $firstWeightSafe, $secondWeightSafe, $grossWeightKg,
                    $emptyBagsQty, $refractionQualityKg, $penaltyDefectiveBagsKg, $netWeightKg, $purchaseWeightKg,
                    $korAtSaleSafe, $humidityAtSaleSafe, $sellingPricePerKg,
                    $grossSaleAmount, $totalCosts, $grossMargin,
                    $deliveryCost, $otherExpenses, $interestFees,
                    $netRevenue, $netProfit, $profitPerKg, $season
                );

                if ($stmt->execute()) {
                    $stmt->close();

                    // auto-update delivery status to Delivered
                    if ($deliveryId) {
                        $upDel = $conn->prepare("UPDATE deliveries SET status = 'Delivered' WHERE delivery_id = ? AND status IN ('Pending','In Transit')");
                        $upDel->bind_param("s", $deliveryId);
                        $upDel->execute();
                        $upDel->close();
                    }

                    logActivity($user_id, $username, 'Sale Created', "Created sale: $newId for delivery $deliveryId, {$netWeightKg}kg @ {$sellingPricePerKg}/kg");
                    $conn->close();
                    echo json_encode(['success' => true, 'message' => 'Sale added successfully', 'sale_id' => $newId]);
                } else {
                    $error = $stmt->error;
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to add sale: ' . $error]);
                }
                exit();

            case 'updateSale':
                if (!$canUpdate) {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $saleId = isset($_POST['sale_id']) ? trim($_POST['sale_id']) : '';
                $deliveryId = isset($_POST['delivery_id']) ? trim($_POST['delivery_id']) : '';
                $unloadingDate = isset($_POST['unloading_date']) ? trim($_POST['unloading_date']) : '';
                $locationId = !empty($_POST['location_id']) ? intval($_POST['location_id']) : null;
                $firstWeightKg = isset($_POST['first_weight_kg']) && $_POST['first_weight_kg'] !== '' ? floatval($_POST['first_weight_kg']) : null;
                $secondWeightKg = isset($_POST['second_weight_kg']) && $_POST['second_weight_kg'] !== '' ? floatval($_POST['second_weight_kg']) : null;
                $grossWeightKg = isset($_POST['gross_weight_kg']) ? floatval($_POST['gross_weight_kg']) : 0;
                $emptyBagsQty = isset($_POST['empty_bags_qty']) ? intval($_POST['empty_bags_qty']) : 0;
                $refractionQualityKg = isset($_POST['refraction_quality_kg']) ? floatval($_POST['refraction_quality_kg']) : 0;
                $penaltyDefectiveBagsKg = isset($_POST['penalty_defective_bags_kg']) ? floatval($_POST['penalty_defective_bags_kg']) : 0;
                $korAtSale = !empty($_POST['kor_at_sale']) ? floatval($_POST['kor_at_sale']) : null;
                $humidityAtSale = !empty($_POST['humidity_at_sale']) ? floatval($_POST['humidity_at_sale']) : null;
                $sellingPricePerKg = isset($_POST['selling_price_per_kg']) ? floatval($_POST['selling_price_per_kg']) : 0;
                $otherExpenses = isset($_POST['other_expenses']) ? floatval($_POST['other_expenses']) : 0;
                $interestFees = isset($_POST['interest_fees']) ? floatval($_POST['interest_fees']) : 0;
                $saleStatus = isset($_POST['sale_status']) ? trim($_POST['sale_status']) : 'Draft';
                $season = isset($_POST['season']) ? trim($_POST['season']) : getActiveSeason();

                // gross = first - second (if both provided), otherwise use posted gross
                if ($firstWeightKg !== null && $secondWeightKg !== null) {
                    $grossWeightKg = round($firstWeightKg - $secondWeightKg, 2);
                }
                // net = gross - bags - refraction - penalty
                $netWeightKg = round($grossWeightKg - $emptyBagsQty - $refractionQualityKg - $penaltyDefectiveBagsKg, 2);

                // Validation
                if (empty($saleId)) {
                    echo json_encode(['success' => false, 'message' => 'Sale ID is required']);
                    exit();
                }
                if (empty($deliveryId)) {
                    echo json_encode(['success' => false, 'message' => 'Delivery is required']);
                    exit();
                }
                if (empty($unloadingDate)) {
                    echo json_encode(['success' => false, 'message' => 'Unloading date is required']);
                    exit();
                }
                if ($grossWeightKg <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Gross weight must be greater than 0']);
                    exit();
                }
                if ($netWeightKg <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Net weight must be greater than 0']);
                    exit();
                }
                if ($sellingPricePerKg <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Selling price per kg must be greater than 0']);
                    exit();
                }

                $conn = getDBConnection();

                // Check sale exists
                $stmt = $conn->prepare("SELECT sale_id FROM sales WHERE sale_id = ?");
                $stmt->bind_param("s", $saleId);
                $stmt->execute();
                if ($stmt->get_result()->num_rows === 0) {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Sale not found']);
                    exit();
                }
                $stmt->close();

                // Business rule: before confirming, check no other confirmed sale for same delivery
                if ($saleStatus === 'Confirmed') {
                    $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM sales WHERE delivery_id = ? AND sale_status = 'Confirmed' AND sale_id != ?");
                    $stmt->bind_param("ss", $deliveryId, $saleId);
                    $stmt->execute();
                    $cnt = $stmt->get_result()->fetch_assoc()['cnt'];
                    $stmt->close();

                    if ($cnt > 0) {
                        $conn->close();
                        echo json_encode(['success' => false, 'message' => 'Another confirmed sale already exists for this delivery. Only one confirmed sale per delivery is allowed.']);
                        exit();
                    }
                }

                // Get delivery details (incl procurement cost)
                $stmt = $conn->prepare("SELECT customer_id, customer_name, transport_cost, loading_cost, other_cost, total_cost, procurement_cost_per_kg, weight_kg FROM deliveries WHERE delivery_id = ?");
                $stmt->bind_param("s", $deliveryId);
                $stmt->execute();
                $delResult = $stmt->get_result();
                if ($delResult->num_rows === 0) {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Delivery not found']);
                    exit();
                }
                $delivery = $delResult->fetch_assoc();
                $stmt->close();

                $customerId = $delivery['customer_id'];
                $deliveryCost = floatval($delivery['total_cost']); // transport + loading + other
                $procCostPerKg = floatval($delivery['procurement_cost_per_kg']);

                // product cost = sum of delivery_items (actual purchase weight x purchase cost)
                // this uses the weight bought/collected -- NOT loaded/delivered weight -- protects from scale drift
                $diStmt = $conn->prepare("SELECT COALESCE(SUM(di.quantity_kg),0) as qty, COALESCE(SUM(di.total_cost),0) as cost FROM delivery_items di JOIN purchases p ON p.purchase_id = di.purchase_id WHERE di.delivery_id = ?");
                $diStmt->bind_param("s", $deliveryId);
                $diStmt->execute();
                $diRow = $diStmt->get_result()->fetch_assoc();
                $diStmt->close();
                $purchaseWeightKg = floatval($diRow['qty'] ?? 0);
                $productCost = round(floatval($diRow['cost'] ?? 0), 2);
                // legacy fallback for deliveries with no delivery_items
                if ($purchaseWeightKg <= 0) {
                    $purchaseWeightKg = floatval($delivery['weight_kg']);
                    $productCost = round($procCostPerKg * $purchaseWeightKg, 2);
                }

                $grossSaleAmount = round($netWeightKg * $sellingPricePerKg, 2);
                // total = product + delivery logistics + sale expenses + interest
                $totalCosts = round($productCost + $deliveryCost + $otherExpenses + $interestFees, 2);
                $grossMargin = round($grossSaleAmount - $totalCosts, 2);
                $netRevenue = round($grossSaleAmount - $otherExpenses - $interestFees, 2);
                $netProfit = round($grossSaleAmount - $totalCosts, 2);
                $profitPerKg = ($netWeightKg > 0) ? round($netProfit / $netWeightKg, 2) : 0;

                // Null-safe for bind_param (PHP 8.2+)
                $locationIdSafe = $locationId !== null ? strval($locationId) : null;
                $firstWeightSafe = $firstWeightKg !== null ? strval($firstWeightKg) : null;
                $secondWeightSafe = $secondWeightKg !== null ? strval($secondWeightKg) : null;
                $korAtSaleSafe = $korAtSale !== null ? strval($korAtSale) : null;
                $humidityAtSaleSafe = $humidityAtSale !== null ? strval($humidityAtSale) : null;

                $stmt = $conn->prepare("UPDATE sales SET delivery_id = ?, customer_id = ?, sale_status = ?, unloading_date = ?, location_id = ?, first_weight_kg = ?, second_weight_kg = ?, gross_weight_kg = ?, empty_bags_qty = ?, refraction_quality_kg = ?, penalty_defective_bags_kg = ?, net_weight_kg = ?, purchase_weight_kg = ?, kor_at_sale = ?, humidity_at_sale = ?, selling_price_per_kg = ?, gross_sale_amount = ?, total_costs = ?, gross_margin = ?, transport_cost = ?, other_expenses = ?, interest_fees = ?, net_revenue = ?, net_profit = ?, profit_per_kg = ?, season = ? WHERE sale_id = ?");
                // types: s s s s s d d d i d d d d s s d d d d d d d d d d s s
                $stmt->bind_param("sssssdddiddddssddddddddddss",
                    $deliveryId, $customerId, $saleStatus, $unloadingDate, $locationIdSafe,
                    $firstWeightSafe, $secondWeightSafe, $grossWeightKg,
                    $emptyBagsQty, $refractionQualityKg, $penaltyDefectiveBagsKg, $netWeightKg, $purchaseWeightKg,
                    $korAtSaleSafe, $humidityAtSaleSafe, $sellingPricePerKg,
                    $grossSaleAmount, $totalCosts, $grossMargin,
                    $deliveryCost, $otherExpenses, $interestFees,
                    $netRevenue, $netProfit, $profitPerKg, $season, $saleId
                );

                if ($stmt->execute()) {
                    logActivity($user_id, $username, 'Sale Updated', "Updated sale: $saleId (Status: $saleStatus)");
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => true, 'message' => 'Sale updated successfully']);
                } else {
                    $error = $stmt->error;
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to update sale: ' . $error]);
                }
                exit();

            case 'deleteSale':
                if (!$canDelete) {
                    echo json_encode(['success' => false, 'message' => 'Access denied. Only Admin can delete.']);
                    exit();
                }

                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $saleId = isset($_POST['sale_id']) ? trim($_POST['sale_id']) : '';
                if (empty($saleId)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid sale ID']);
                    exit();
                }

                $conn = getDBConnection();

                // Check for linked payments
                $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM payments WHERE linked_sale_id = ?");
                $stmt->bind_param("s", $saleId);
                $stmt->execute();
                $cnt = $stmt->get_result()->fetch_assoc()['cnt'];
                $stmt->close();

                if ($cnt > 0) {
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => "Cannot delete — sale has $cnt linked payment(s)"]);
                    exit();
                }

                // Get info for logging
                $stmt = $conn->prepare("SELECT delivery_id, net_weight_kg, gross_sale_amount FROM sales WHERE sale_id = ?");
                $stmt->bind_param("s", $saleId);
                $stmt->execute();
                $info = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$info) {
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Sale not found']);
                    exit();
                }

                $stmt = $conn->prepare("DELETE FROM sales WHERE sale_id = ?");
                $stmt->bind_param("s", $saleId);

                if ($stmt->execute()) {
                    logActivity($user_id, $username, 'Sale Deleted', "Deleted sale: $saleId (Delivery: {$info['delivery_id']}, {$info['net_weight_kg']}kg, Gross: {$info['gross_sale_amount']})");
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => true, 'message' => 'Sale deleted successfully']);
                } else {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to delete sale']);
                }
                exit();

            case 'getSaleReceipt':
                $saleId = isset($_GET['sale_id']) ? trim($_GET['sale_id']) : '';
                if (empty($saleId)) {
                    echo json_encode(['success' => false, 'message' => 'Sale ID required']);
                    exit();
                }

                $conn = getDBConnection();

                // Get sale with customer name, location, warehouse info
                $stmt = $conn->prepare("SELECT s.*, c.customer_name AS cust_display_name,
                    d.customer_name AS delivery_customer_name,
                    l.location_name,
                    w.warehouse_name
                    FROM sales s
                    LEFT JOIN customers c ON s.customer_id = c.customer_id
                    LEFT JOIN deliveries d ON s.delivery_id = d.delivery_id
                    LEFT JOIN settings_locations l ON s.location_id = l.location_id
                    LEFT JOIN settings_warehouses w ON d.origin_warehouse_id = w.warehouse_id
                    WHERE s.sale_id = ?");
                $stmt->bind_param("s", $saleId);
                $stmt->execute();
                $result = $stmt->get_result();
                $sale = $result ? $result->fetch_assoc() : null;
                $stmt->close();

                if (!$sale) {
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Sale not found']);
                    exit();
                }

                // Get active financing for this customer
                $financing = [];
                if ($sale['customer_id']) {
                    $stmt = $conn->prepare("SELECT financing_id, amount, amount_repaid, balance_due, status
                        FROM financing
                        WHERE counterparty_id = ? AND counterpart_type = 'Customer' AND status IN ('Active','Overdue')
                        ORDER BY date DESC");
                    $stmt->bind_param("s", $sale['customer_id']);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    if ($result) {
                        while ($row = $result->fetch_assoc()) $financing[] = $row;
                    }
                    $stmt->close();
                }

                $conn->close();

                $companyInfo = getCompanyInfo();
                $companyName = $companyInfo['company_name'] ?? '7503 Canada';

                echo json_encode(['success' => true, 'data' => [
                    'sale' => [
                        'sale_id' => $sale['sale_id'],
                        'delivery_id' => $sale['delivery_id'],
                        'customer_id' => $sale['customer_id'],
                        'customer_name' => $sale['cust_display_name'] ?? $sale['delivery_customer_name'] ?? '',
                        'unloading_date' => $sale['unloading_date'],
                        'location_name' => $sale['location_name'] ?? '',
                        'warehouse_name' => $sale['warehouse_name'] ?? '',
                        'first_weight_kg' => $sale['first_weight_kg'],
                        'second_weight_kg' => $sale['second_weight_kg'],
                        'gross_weight_kg' => $sale['gross_weight_kg'],
                        'empty_bags_qty' => $sale['empty_bags_qty'],
                        'refraction_quality_kg' => $sale['refraction_quality_kg'],
                        'penalty_defective_bags_kg' => $sale['penalty_defective_bags_kg'],
                        'net_weight_kg' => $sale['net_weight_kg'],
                        'kor_at_sale' => $sale['kor_at_sale'],
                        'humidity_at_sale' => $sale['humidity_at_sale'],
                        'selling_price_per_kg' => $sale['selling_price_per_kg'],
                        'gross_sale_amount' => $sale['gross_sale_amount'],
                        'total_costs' => $sale['total_costs'],
                        'transport_cost' => $sale['transport_cost'],
                        'other_expenses' => $sale['other_expenses'],
                        'interest_fees' => $sale['interest_fees'],
                        'net_revenue' => $sale['net_revenue'],
                        'season' => $sale['season']
                    ],
                    'financing' => $financing,
                    'companyName' => $companyName,
                    'companyInfo' => $companyInfo
                ]]);
                exit();

            case 'getSaleDetail':
                $saleId = isset($_GET['sale_id']) ? trim($_GET['sale_id']) : '';
                if (empty($saleId)) { echo json_encode(['success' => false, 'message' => 'Sale ID required']); exit(); }

                $conn = getDBConnection();
                $stmt = $conn->prepare("SELECT s.*, c.customer_name AS cust_name,
                    d.origin_warehouse_id, d.weight_kg AS delivery_weight, d.num_bags AS delivery_bags,
                    d.procurement_cost_per_kg, d.transport_cost AS del_transport, d.loading_cost AS del_loading,
                    d.other_cost AS del_other, d.total_cost AS del_total_cost,
                    d.customer_name AS del_customer_name, d.date AS delivery_date,
                    w.warehouse_name AS origin_warehouse,
                    wl.location_name AS origin_location,
                    cl.location_name AS dest_location
                    FROM sales s
                    LEFT JOIN deliveries d ON s.delivery_id = d.delivery_id
                    LEFT JOIN customers c ON s.customer_id = c.customer_id
                    LEFT JOIN settings_warehouses w ON d.origin_warehouse_id = w.warehouse_id
                    LEFT JOIN settings_locations wl ON w.location_id = wl.location_id
                    LEFT JOIN settings_locations cl ON s.location_id = cl.location_id
                    WHERE s.sale_id = ?");
                $stmt->bind_param("s", $saleId);
                $stmt->execute();
                $sale = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$sale) { $conn->close(); echo json_encode(['success' => false, 'message' => 'Sale not found']); exit(); }

                // total buying = procurement cost x purchase weight (snapshot, fallback to delivery loaded weight)
                $procCost = floatval($sale['procurement_cost_per_kg'] ?? 0);
                $netWt = floatval($sale['net_weight_kg']);
                $purchaseWt = floatval($sale['purchase_weight_kg'] ?? 0);
                if ($purchaseWt <= 0) $purchaseWt = floatval($sale['delivery_weight'] ?? 0);
                $totalBuying = round($procCost * $purchaseWt, 2);
                $avgBuyPrice = $procCost;
                $sellPrice = floatval($sale['selling_price_per_kg']);
                $margin = round($sellPrice - $procCost, 2);
                $marginPct = ($procCost > 0) ? round(($margin / $procCost) * 100, 1) : 0;

                $conn->close();
                echo json_encode(['success' => true, 'data' => [
                    'sale_id' => $sale['sale_id'],
                    'delivery_id' => $sale['delivery_id'],
                    'customer_name' => $sale['cust_name'] ?? $sale['del_customer_name'] ?? '',
                    'customer_id' => $sale['customer_id'],
                    'unloading_date' => $sale['unloading_date'],
                    'delivery_date' => $sale['delivery_date'] ?? null,
                    'origin_warehouse' => $sale['origin_warehouse'] ?? 'N/A',
                    'origin_location' => $sale['origin_location'] ?? '',
                    'dest_location' => $sale['dest_location'] ?? $sale['origin_location'] ?? '',
                    'delivery_weight' => $sale['delivery_weight'],
                    'delivery_bags' => $sale['delivery_bags'],
                    'gross_weight_kg' => $sale['gross_weight_kg'],
                    'net_weight_kg' => $sale['net_weight_kg'],
                    'purchase_weight_kg' => $purchaseWt,
                    'selling_price_per_kg' => $sellPrice,
                    'gross_sale_amount' => $sale['gross_sale_amount'],
                    'total_buying' => $totalBuying,
                    'avg_buy_price_per_kg' => $avgBuyPrice,
                    'margin_per_kg' => $margin,
                    'margin_pct' => $marginPct,
                    'transport_cost' => $sale['transport_cost'],
                    'other_expenses' => $sale['other_expenses'],
                    'interest_fees' => $sale['interest_fees'],
                    'total_costs' => $sale['total_costs'],
                    'net_profit' => $sale['net_profit'],
                    'profit_per_kg' => $sale['profit_per_kg'],
                    'kor_at_sale' => $sale['kor_at_sale'],
                    'humidity_at_sale' => $sale['humidity_at_sale'],
                    'sale_status' => $sale['sale_status'],
                    'season' => $sale['season'],
                    'receipt_file' => $sale['receipt_file'] ?? null
                ]]);
                exit();

            case 'uploadReceipt':
                if (!$canUpdate) { echo json_encode(['success' => false, 'message' => 'Access denied']); exit(); }

                $saleId = isset($_POST['sale_id']) ? trim($_POST['sale_id']) : '';
                if (empty($saleId) || !isset($_FILES['receipt_file'])) {
                    echo json_encode(['success' => false, 'message' => 'Sale ID and file required']);
                    exit();
                }

                $file = $_FILES['receipt_file'];
                $allowed = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime = finfo_file($finfo, $file['tmp_name']);
                finfo_close($finfo);

                if (!in_array($mime, $allowed)) {
                    echo json_encode(['success' => false, 'message' => 'Only JPG, PNG, WebP and PDF allowed']);
                    exit();
                }
                if ($file['size'] > 10 * 1024 * 1024) {
                    echo json_encode(['success' => false, 'message' => 'File too large (max 10MB)']);
                    exit();
                }

                $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
                $filename = 'receipt_' . $saleId . '_' . time() . '.' . $ext;
                $uploadDir = __DIR__ . '/uploads/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

                if (!move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
                    echo json_encode(['success' => false, 'message' => 'Upload failed']);
                    exit();
                }

                $conn = getDBConnection();
                // delete old file if exists
                $stmt = $conn->prepare("SELECT receipt_file FROM sales WHERE sale_id = ?");
                $stmt->bind_param("s", $saleId);
                $stmt->execute();
                $old = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($old && $old['receipt_file'] && file_exists($uploadDir . $old['receipt_file'])) {
                    unlink($uploadDir . $old['receipt_file']);
                }

                $stmt = $conn->prepare("UPDATE sales SET receipt_file = ? WHERE sale_id = ?");
                $stmt->bind_param("ss", $filename, $saleId);
                $stmt->execute();
                $stmt->close();

                logActivity($user_id, $username, 'Receipt Uploaded', "Uploaded receipt for sale $saleId");
                $conn->close();
                echo json_encode(['success' => true, 'message' => 'Receipt uploaded', 'filename' => $filename]);
                exit();

            case 'deleteReceipt':
                if (!$canUpdate) { echo json_encode(['success' => false, 'message' => 'Access denied']); exit(); }
                $saleId = isset($_POST['sale_id']) ? trim($_POST['sale_id']) : '';
                if (empty($saleId)) { echo json_encode(['success' => false, 'message' => 'Sale ID required']); exit(); }

                $conn = getDBConnection();
                $stmt = $conn->prepare("SELECT receipt_file FROM sales WHERE sale_id = ?");
                $stmt->bind_param("s", $saleId);
                $stmt->execute();
                $old = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($old && $old['receipt_file']) {
                    $path = __DIR__ . '/uploads/' . $old['receipt_file'];
                    if (file_exists($path)) unlink($path);
                }

                $stmt = $conn->prepare("UPDATE sales SET receipt_file = NULL WHERE sale_id = ?");
                $stmt->bind_param("s", $saleId);
                $stmt->execute();
                $stmt->close();

                logActivity($user_id, $username, 'Receipt Deleted', "Deleted receipt for sale $saleId");
                $conn->close();
                echo json_encode(['success' => true, 'message' => 'Receipt removed']);
                exit();

            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
                exit();
        }
    } catch (\Throwable $e) {
        error_log("sales.php error: " . $e->getMessage());
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
  <title>Commodity Flow — Sales</title>

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
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

  <!-- Font Awesome -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

  <!-- DataTables CSS -->
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/jquery.dataTables.min.css">
  <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.dataTables.min.css">
  <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.dataTables.min.css">

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
    ::-webkit-scrollbar { width: 5px; height: 5px; }
    ::-webkit-scrollbar-track { background: transparent; }
    ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
    .dark ::-webkit-scrollbar-thumb { background: #334155; }

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
    .nav-link.active::before {
      content: '';
      position: absolute; left: 0; top: 15%; bottom: 15%;
      width: 3px; background: #2d9d99; border-radius: 0 3px 3px 0;
    }

    /* DataTables styling */
    .dataTables_wrapper { font-size: 13px; }
    table.dataTable thead th {
      background: transparent;
      border-bottom: 1px solid #e2e8f0;
      color: #64748b;
      font-size: 11px;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      padding: 10px 12px;
    }
    .dark table.dataTable thead th {
      border-bottom-color: #334155;
      color: #94a3b8;
    }
    table.dataTable tbody tr:hover { background: #f8fafc; }
    .dark table.dataTable tbody tr:hover { background: #1e293b; }
    table.dataTable tbody td {
      padding: 10px 12px;
      border-bottom: 1px solid #f1f5f9;
    }
    .dark table.dataTable tbody td { border-bottom-color: #1e293b; }
    .dark table.dataTable { color: #e2e8f0; }
    .dark .dataTables_wrapper .dataTables_length,
    .dark .dataTables_wrapper .dataTables_filter,
    .dark .dataTables_wrapper .dataTables_info,
    .dark .dataTables_wrapper .dataTables_paginate { color: #94a3b8; }
    .dark .dataTables_wrapper .dataTables_filter input,
    .dark .dataTables_wrapper .dataTables_length select {
      background: #1e293b; color: #e2e8f0; border-color: #334155;
    }
    .dataTables_wrapper .dataTables_paginate .paginate_button.current {
      background: #2d9d99 !important; color: #fff !important; border-color: #2d9d99 !important; border-radius: 6px;
    }
    .dataTables_wrapper .dataTables_paginate .paginate_button:hover {
      background: #d9f2f0 !important; color: #247f7c !important; border-color: #d9f2f0 !important; border-radius: 6px;
    }
    .dark .dataTables_wrapper .dataTables_paginate .paginate_button:hover {
      background: #164342 !important; color: #4db8b4 !important; border-color: #164342 !important;
    }
    .dt-buttons .dt-button {
      background: #f8fafc !important; border: 1px solid #e2e8f0 !important;
      border-radius: 6px !important; color: #475569 !important;
      font-size: 12px !important; font-weight: 500 !important;
      padding: 6px 12px !important; margin-right: 4px !important;
    }
    .dark .dt-buttons .dt-button {
      background: #1e293b !important; border-color: #334155 !important; color: #94a3b8 !important;
    }
    .dt-buttons .dt-button:hover {
      background: #e2e8f0 !important; color: #1e293b !important;
    }

    /* Status badges */
    .status-badge {
      display: inline-flex; align-items: center; gap: 4px;
      padding: 3px 10px; border-radius: 9999px;
      font-size: 11px; font-weight: 600; letter-spacing: 0.02em;
    }
    .status-draft { background: #fef3c7; color: #92400e; }
    .dark .status-draft { background: rgba(251,191,36,0.15); color: #fbbf24; }
    .status-confirmed { background: #d1fae5; color: #065f46; }
    .dark .status-confirmed { background: rgba(16,185,129,0.15); color: #34d399; }
    .status-cancelled { background: #fee2e2; color: #991b1b; }
    .dark .status-cancelled { background: rgba(244,63,94,0.15); color: #fb7185; }

    /* Action icons */
    .action-icon {
      display: inline-flex; align-items: center; justify-content: center;
      width: 30px; height: 30px; border-radius: 6px; border: none;
      background: transparent; cursor: pointer; transition: background 150ms;
      font-size: 13px;
    }
    .action-icon:hover { background: #f1f5f9; }
    .dark .action-icon:hover { background: #334155; }
    .action-icon.edit-icon { color: #2d9d99; }
    .action-icon.delete-icon { color: #f43f5e; }

    /* Computed field (read-only display) */
    .computed-field {
      padding: 8px 12px; border-radius: 8px; font-size: 14px; font-weight: 600;
      background: #f8fafc; border: 1px solid #e2e8f0; color: #1e293b; tabular-nums: lining-nums;
    }
    .dark .computed-field {
      background: #0f172a; border-color: #334155; color: #e2e8f0;
    }

    /* Modal overlay */
    .modal-overlay {
      display: none; position: fixed; inset: 0; z-index: 50;
      background: rgba(15,23,42,0.5); backdrop-filter: blur(4px);
      align-items: flex-start; justify-content: center;
      padding: 24px 16px; overflow-y: auto;
    }
    .modal-overlay.active { display: flex; }
    .modal-panel {
      background: #fff; border-radius: 16px; width: 100%; max-width: 720px;
      box-shadow: 0 20px 60px rgba(0,0,0,0.15); margin-top: 32px;
    }
    .dark .modal-panel {
      background: #1e293b; box-shadow: 0 20px 60px rgba(0,0,0,0.4);
    }

    /* Skeleton rows */
    .skeleton-row { height: 14px; border-radius: 6px; margin-bottom: 12px; }
    .skeleton-table { display: flex; flex-direction: column; gap: 8px; padding: 16px; }
    .skeleton-table-row { display: flex; gap: 12px; }
    .skeleton-table-cell { height: 18px; flex: 1; border-radius: 4px; }

    /* Season dropdown from renderSeasonDropdown() */
    #season {
      width: 100%;
      background-color: rgb(248 250 252);
      border: 1px solid rgb(226 232 240);
      border-radius: 0.5rem;
      padding: 0.5rem 0.75rem;
      font-size: 0.875rem;
      color: rgb(30 41 59);
      outline: none;
      transition: box-shadow 150ms, border-color 150ms;
    }
    #season:focus { box-shadow: 0 0 0 2px rgba(77,184,180,0.5); border-color: #4db8b4; }
    .dark #season {
      background-color: rgb(51 65 85);
      border-color: rgb(71 85 105);
      color: rgb(226 232 240);
    }

    /* Scroll hint */
    .table-scroll-hint {
      display: none; text-align: center; padding: 6px; font-size: 11px;
      color: #94a3b8;
    }
    @media (max-width: 768px) {
      .table-scroll-hint { display: block; }
    }
  </style>
</head>

<body class="h-full bg-slate-50 text-slate-800 font-sans antialiased dark:bg-slate-900 dark:text-slate-200">

  <?php include 'mobile-menu.php'; ?>

  <div class="flex h-full overflow-hidden" id="appRoot">

    <?php include 'sidebar.php'; ?>

    <!-- MAIN CONTENT -->
    <div class="flex-1 flex flex-col min-w-0 overflow-hidden">

      <!-- HEADER -->
      <header class="h-14 bg-white dark:bg-slate-800 border-b border-slate-200 dark:border-slate-700 flex items-center gap-4 px-5 flex-shrink-0">
        <button id="mobileSidebarBtn" class="lg:hidden text-slate-500 hover:text-slate-700 dark:hover:text-slate-300">
          <i class="fas fa-bars text-sm"></i>
        </button>

        <div class="flex items-center gap-2">
          <i class="fas fa-tag text-brand-500 text-sm"></i>
          <h1 class="text-base font-bold text-slate-800 dark:text-white">Sales</h1>
        </div>

        <div class="ml-auto flex items-center gap-3">
          <button class="flex items-center gap-1.5 text-slate-400 hover:text-slate-600 dark:hover:text-slate-300 text-xs transition-colors" onclick="loadSales()">
            <i class="fas fa-sync-alt text-xs"></i>
            <span class="hidden sm:inline">Refresh</span>
          </button>
          <?php if ($canCreate): ?>
          <button onclick="openAddModal()" class="flex items-center gap-2 bg-brand-500 hover:bg-brand-600 text-white text-xs font-semibold px-3.5 py-2 rounded-lg shadow-sm transition-colors">
            <i class="fas fa-plus text-xs"></i>
            <span>Add Sale</span>
          </button>
          <?php endif; ?>
        </div>
      </header>

      <!-- MAIN SCROLLABLE -->
      <main class="flex-1 overflow-y-auto p-5">

        <!-- FILTERS CARD -->
        <div id="filtersSection" class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card mb-5" style="display:none;">
          <div class="flex items-center justify-between px-5 py-3 border-b border-slate-100 dark:border-slate-700">
            <div class="flex items-center gap-2 text-sm font-semibold text-slate-700 dark:text-slate-200">
              <i class="fas fa-filter text-brand-500 text-xs"></i> Filters
            </div>
            <button class="text-xs text-slate-400 hover:text-rose-500 transition-colors" onclick="clearFilters()">
              <i class="fas fa-times-circle mr-1"></i>Clear All
            </button>
          </div>
          <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 p-5">
            <div>
              <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">Date From</label>
              <input type="date" id="filterDateFrom" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
            </div>
            <div>
              <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">Date To</label>
              <input type="date" id="filterDateTo" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
            </div>
            <div>
              <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">Sale Status</label>
              <select id="filterStatus" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
                <option value="">All</option>
                <option value="Draft">Draft</option>
                <option value="Confirmed">Confirmed</option>
                <option value="Cancelled">Cancelled</option>
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

        <!-- TABLE CARD -->
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card">

          <!-- Skeleton Loader -->
          <div id="skeletonLoader" class="p-5">
            <div class="skeleton-table">
              <div class="skeleton-table-row"><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div></div>
              <div class="skeleton-table-row"><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div></div>
              <div class="skeleton-table-row"><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div></div>
              <div class="skeleton-table-row"><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div></div>
              <div class="skeleton-table-row"><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div></div>
              <div class="skeleton-table-row"><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div></div>
              <div class="skeleton-table-row"><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div></div>
              <div class="skeleton-table-row"><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div></div>
            </div>
          </div>

          <!-- Table Container -->
          <div id="tableContainer" class="p-5" style="display: none;">
            <div class="table-scroll-hint">
              <i class="fas fa-arrows-alt-h"></i> Swipe left/right to see all columns
            </div>
            <div style="overflow-x: auto; -webkit-overflow-scrolling: touch;">
              <table id="salesTable" class="display" style="width:100%"></table>
            </div>
          </div>

        </div>

      </main>
    </div>
  </div>

  <!-- ===================== ADD/EDIT SALE MODAL ===================== -->
  <?php if ($canCreate || $canUpdate): ?>
  <div class="modal-overlay" id="saleModal">
    <div class="modal-panel" onclick="event.stopPropagation()">
      <!-- Modal Header -->
      <div class="flex items-center justify-between px-6 py-4 border-b border-slate-200 dark:border-slate-700">
        <h3 id="modalTitle" class="text-base font-bold text-slate-800 dark:text-white flex items-center gap-2">
          <i class="fas fa-tag text-brand-500"></i> Add Sale
        </h3>
        <button class="w-8 h-8 rounded-lg flex items-center justify-center text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-700 transition-colors" onclick="closeModal()">
          <i class="fas fa-times text-sm"></i>
        </button>
      </div>
      <!-- Modal Body -->
      <div class="px-6 py-5 max-h-[calc(100vh-180px)] overflow-y-auto">
        <div id="saleIdInfo" class="mb-4 px-4 py-2.5 bg-brand-50 dark:bg-brand-900/20 rounded-lg text-sm font-medium text-brand-700 dark:text-brand-300" style="display: none;">
          <i class="fas fa-id-badge mr-1"></i> Sale ID: <span id="saleIdDisplay" class="font-bold"></span>
        </div>

        <form id="saleForm">
          <input type="hidden" id="saleId" name="sale_id">
          <input type="hidden" id="customerId" name="customer_id">
          <input type="hidden" id="deliveryTotalCostHidden" value="0">
          <input type="hidden" id="procCostPerKgHidden" value="0">

          <!-- AI Receipt Reader -->
          <div class="mb-5 p-4 bg-slate-50 dark:bg-slate-700/50 rounded-xl border border-dashed border-slate-300 dark:border-slate-600">
            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-2"><i class="fas fa-robot mr-1 text-brand-500"></i> AI Receipt Reader</label>
            <div class="flex gap-2 items-center flex-wrap">
              <input type="file" id="aiReceiptFile" accept="image/*,.pdf" class="text-sm flex-1 min-w-[160px] text-slate-600 dark:text-slate-300 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-brand-50 file:text-brand-600 dark:file:bg-brand-900/30 dark:file:text-brand-300 hover:file:bg-brand-100">
              <button type="button" class="bg-brand-500 hover:bg-brand-600 text-white text-xs font-semibold px-3.5 py-2 rounded-lg transition-colors whitespace-nowrap" onclick="readReceiptAI()">
                <i class="fas fa-magic mr-1"></i> Read with AI
              </button>
            </div>
            <div id="aiReceiptStatus" class="mt-2 text-xs text-slate-500 dark:text-slate-400" style="display:none;"></div>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-5 gap-y-4">
            <!-- Delivery ID -->
            <div>
              <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1"><i class="fas fa-truck-fast mr-1"></i> Delivery ID *</label>
              <select id="deliveryId" name="delivery_id" required onchange="onDeliveryChange()" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
                <option value="">Select delivery...</option>
              </select>
            </div>

            <!-- Customer -->
            <div>
              <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1"><i class="fas fa-handshake mr-1"></i> Customer</label>
              <input type="text" id="customerDisplay" readonly class="w-full bg-slate-100 dark:bg-slate-600 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-500 dark:text-slate-400 cursor-not-allowed" placeholder="Auto-filled from delivery">
            </div>

            <!-- Unloading Date -->
            <div>
              <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1"><i class="fas fa-calendar-day mr-1"></i> Unloading Date *</label>
              <input type="date" id="unloadingDate" name="unloading_date" required class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
            </div>

            <!-- Location -->
            <div>
              <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1"><i class="fas fa-map-marker-alt mr-1"></i> Location</label>
              <select id="locationId" name="location_id" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
                <option value="">Select location...</option>
              </select>
            </div>

            <!-- First Weight -->
            <div>
              <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1"><i class="fas fa-weight-hanging mr-1"></i> First Weight (kg)</label>
              <input type="number" id="firstWeightKg" name="first_weight_kg" step="0.01" min="0" placeholder="Truck + product" oninput="computeSaleFields()" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
            </div>

            <!-- Second Weight -->
            <div>
              <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1"><i class="fas fa-weight-hanging mr-1"></i> Second Weight (kg)</label>
              <input type="number" id="secondWeightKg" name="second_weight_kg" step="0.01" min="0" placeholder="Empty truck" oninput="computeSaleFields()" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
            </div>

            <!-- Gross Weight -->
            <div>
              <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1"><i class="fas fa-balance-scale mr-1"></i> Gross Weight (kg) *</label>
              <input type="number" id="grossWeightKg" name="gross_weight_kg" step="0.01" min="0.01" required placeholder="Auto or manual" oninput="computeSaleFields()" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
            </div>

            <!-- Empty Bags Qty -->
            <div>
              <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1"><i class="fas fa-box-open mr-1"></i> Empty Bags Qty</label>
              <input type="number" id="emptyBagsQty" name="empty_bags_qty" step="1" min="0" value="0" placeholder="0" oninput="computeSaleFields()" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
            </div>

            <!-- Refraction Quality -->
            <div>
              <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1"><i class="fas fa-flask mr-1"></i> Refraction Quality (kg)</label>
              <input type="number" id="refractionQualityKg" name="refraction_quality_kg" step="0.01" min="0" value="0" placeholder="0.00" oninput="computeSaleFields()" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
            </div>

            <!-- Penalty Defective Bags -->
            <div>
              <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1"><i class="fas fa-exclamation-triangle mr-1"></i> Penalty Defective Bags (kg)</label>
              <input type="number" id="penaltyDefectiveBagsKg" name="penalty_defective_bags_kg" step="0.01" min="0" value="0" placeholder="0.00" oninput="computeSaleFields()" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
            </div>

            <!-- Net Weight -->
            <div>
              <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1"><i class="fas fa-weight-scale mr-1"></i> Net Weight (kg)</label>
              <input type="number" id="netWeightKg" name="net_weight_kg" step="0.01" readonly class="w-full bg-slate-100 dark:bg-slate-600 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-500 dark:text-slate-400 cursor-not-allowed" placeholder="Auto-calculated" value="0">
            </div>

            <!-- KOR at Sale -->
            <div>
              <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1"><i class="fas fa-vial mr-1"></i> KOR at Sale</label>
              <input type="number" id="korAtSale" name="kor_at_sale" step="0.01" min="0" placeholder="0.00" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
            </div>

            <!-- Humidity at Sale -->
            <div>
              <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1"><i class="fas fa-droplet mr-1"></i> Humidity at Sale</label>
              <input type="number" id="humidityAtSale" name="humidity_at_sale" step="0.01" min="0" placeholder="0.00" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
            </div>

            <!-- Selling Price/Kg -->
            <div>
              <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1"><i class="fas fa-tag mr-1"></i> Selling Price/Kg *</label>
              <input type="text" inputmode="decimal" id="sellingPricePerKg" name="selling_price_per_kg" class="money-input w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors" required placeholder="0.00" oninput="computeSaleFields()">
            </div>

            <!-- Gross Sale Amount -->
            <div>
              <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1"><i class="fas fa-calculator mr-1"></i> Gross Sale Amount</label>
              <div class="computed-field tabular" id="grossSaleAmountDisplay">0</div>
            </div>

            <!-- Product Cost -->
            <div>
              <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1"><i class="fas fa-box mr-1"></i> Product Cost</label>
              <div class="computed-field tabular" id="productCostDisplay" title="Sum of purchase cost for purchases loaded into this delivery (weight bought, not customer-measured)">0</div>
              <input type="hidden" id="purchaseWeightKgHidden" name="purchase_weight_kg_hidden" value="0">
              <input type="hidden" id="productCostHidden" name="product_cost_hidden" value="0">
            </div>

            <!-- Delivery Cost -->
            <div>
              <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1"><i class="fas fa-truck-moving mr-1"></i> Delivery Cost</label>
              <div class="computed-field tabular" id="transportCostDisplay" title="Transport + Loading + Other from delivery">0</div>
            </div>

            <!-- Additional Expenses -->
            <div>
              <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1"><i class="fas fa-receipt mr-1"></i> Additional Expenses</label>
              <input type="text" inputmode="decimal" id="otherExpenses" name="other_expenses" class="money-input w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors" value="0" placeholder="Road expenses, incidents..." oninput="computeSaleFields()">
            </div>

            <!-- Interest Amount -->
            <div>
              <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1"><i class="fas fa-coins mr-1"></i> Interest Amount (FCFA)</label>
              <input type="text" inputmode="decimal" id="interestFees" name="interest_fees" class="money-input w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors" value="0" oninput="computeSaleFields()">
            </div>

            <!-- Total Costs -->
            <div>
              <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1"><i class="fas fa-sigma mr-1"></i> Total Costs</label>
              <div class="computed-field tabular" id="totalCostsDisplay" style="color:#f43f5e;" title="Product + Delivery + Other + Interest">0</div>
            </div>

            <!-- Net Profit -->
            <div>
              <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1"><i class="fas fa-chart-line mr-1"></i> Net Profit</label>
              <div class="computed-field tabular" id="netProfitDisplay">0</div>
            </div>

            <!-- Sale Status -->
            <div>
              <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1"><i class="fas fa-info-circle mr-1"></i> Sale Status</label>
              <select id="saleStatus" name="sale_status" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
                <option value="Draft">Draft</option>
                <option value="Confirmed">Confirmed</option>
                <option value="Cancelled">Cancelled</option>
              </select>
            </div>

            <!-- Season -->
            <div>
              <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1"><i class="fas fa-leaf mr-1"></i> Season *</label>
              <?php echo renderSeasonDropdown('season', 'season'); ?>
            </div>
          </div>

          <!-- Form Actions -->
          <div class="flex items-center justify-end gap-3 mt-6 pt-5 border-t border-slate-200 dark:border-slate-700">
            <button type="button" class="px-4 py-2 text-sm font-medium text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-700 rounded-lg transition-colors" onclick="closeModal()">
              Cancel
            </button>
            <button type="submit" class="bg-brand-500 hover:bg-brand-600 text-white text-xs font-semibold px-5 py-2.5 rounded-lg transition-colors shadow-sm">
              <i class="fas fa-save mr-1"></i> Save
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- ===================== SALE DETAIL MODAL ===================== -->
  <div class="modal-overlay" id="saleDetailModal">
    <div class="modal-panel" onclick="event.stopPropagation()" style="max-width:640px;">
      <div class="flex items-center justify-between px-6 py-4 border-b border-slate-200 dark:border-slate-700">
        <h3 class="text-base font-bold text-slate-800 dark:text-white flex items-center gap-2">
          <i class="fas fa-info-circle text-brand-500"></i> Sale Details
        </h3>
        <button class="w-8 h-8 rounded-lg flex items-center justify-center text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-700 transition-colors" onclick="closeSaleDetail()">
          <i class="fas fa-times text-sm"></i>
        </button>
      </div>
      <div class="px-6 py-5 max-h-[calc(100vh-180px)] overflow-y-auto" id="saleDetailBody">
        <div class="skeleton-row" style="height:200px;"></div>
      </div>
    </div>
  </div>

  <script>
  // Global variables
  let salesTable;
  let isEditMode = false;
  let salesData = [];
  let deliveriesList = [];
  let locationsList = [];

  const isReadOnly = <?php echo $isReadOnly ? 'true' : 'false'; ?>;
  const canCreate = <?php echo $canCreate ? 'true' : 'false'; ?>;
  const canUpdate = <?php echo $canUpdate ? 'true' : 'false'; ?>;
  const canDelete = <?php echo $canDelete ? 'true' : 'false'; ?>;
  var ACTIVE_SEASON = '<?php echo addslashes(getActiveSeason()); ?>';

  const statusBadgeMap = {
      'Draft': 'status-draft',
      'Confirmed': 'status-confirmed',
      'Cancelled': 'status-cancelled'
  };

  $(document).ready(function() {
      loadDropdowns();
      loadSales();
  });

  function loadDropdowns() {
      $.ajax({
          url: '?action=getDropdowns',
          method: 'GET',
          dataType: 'json',
          success: function(response) {
              if (response.success && response.data) {
                  deliveriesList = response.data.deliveries;

                  var delSelect = document.getElementById('deliveryId');
                  if (delSelect) {
                      delSelect.innerHTML = '<option value="">Select delivery...</option>';
                      deliveriesList.forEach(function(d) {
                          var opt = document.createElement('option');
                          opt.value = d.delivery_id;
                          opt.textContent = d.delivery_id + ' — ' + d.customer_name + ' (' + parseFloat(d.weight_kg).toLocaleString() + 'kg) [' + (d.status || '') + ']';
                          delSelect.appendChild(opt);
                      });
                  }

                  var locSelect = document.getElementById('locationId');
                  if (locSelect) {
                      locSelect.innerHTML = '<option value="">Select location...</option>';
                      response.data.locations.forEach(function(l) {
                          var opt = document.createElement('option');
                          opt.value = l.location_id;
                          opt.textContent = l.location_name;
                          locSelect.appendChild(opt);
                      });
                  }

                  locationsList = response.data.locations;
              }
          }
      });
  }

  function onDeliveryChange() {
      var deliveryId = document.getElementById('deliveryId').value;
      if (!deliveryId) {
          document.getElementById('customerId').value = '';
          document.getElementById('customerDisplay').value = '';
          document.getElementById('transportCostDisplay').textContent = '0';
          document.getElementById('deliveryTotalCostHidden').value = '0';
          document.getElementById('procCostPerKgHidden').value = '0';
          document.getElementById('purchaseWeightKgHidden').value = '0';
          document.getElementById('productCostHidden').value = '0';
          computeSaleFields();
          return;
      }

      $.ajax({
          url: '?action=getDeliveryDetails&delivery_id=' + encodeURIComponent(deliveryId),
          method: 'GET',
          dataType: 'json',
          success: function(response) {
              if (response.success && response.data) {
                  document.getElementById('customerId').value = response.data.customer_id || '';
                  document.getElementById('customerDisplay').value = response.data.customer_name || '';
                  var delTotal = parseFloat(response.data.total_cost || 0);
                  document.getElementById('transportCostDisplay').textContent = delTotal.toLocaleString();
                  document.getElementById('deliveryTotalCostHidden').value = delTotal;
                  document.getElementById('procCostPerKgHidden').value = response.data.procurement_cost_per_kg || 0;
                  // purchase weight + cost come from delivery_items (actual bought weight, not loaded/customer)
                  document.getElementById('purchaseWeightKgHidden').value = response.data.purchase_weight_kg || 0;
                  document.getElementById('productCostHidden').value = response.data.product_cost || 0;
                  computeSaleFields();
              } else {
                  Swal.fire({ icon: 'error', title: 'Error', text: response.message || 'Failed to load delivery details' });
              }
          },
          error: function() {
              Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error loading delivery details' });
          }
      });
  }

  function computeSaleFields() {
      var firstW = parseFloat(document.getElementById('firstWeightKg').value) || 0;
      var secondW = parseFloat(document.getElementById('secondWeightKg').value) || 0;
      var grossEl = document.getElementById('grossWeightKg');

      // auto-calc gross from first-second if both filled
      if (document.getElementById('firstWeightKg').value && document.getElementById('secondWeightKg').value) {
          var autoGross = firstW - secondW;
          if (autoGross < 0) autoGross = 0;
          grossEl.value = autoGross.toFixed(2);
      }

      var gross = parseFloat(grossEl.value) || 0;
      var bags = parseInt(document.getElementById('emptyBagsQty').value) || 0;
      var refQuality = parseFloat(document.getElementById('refractionQualityKg').value) || 0;
      var penalty = parseFloat(document.getElementById('penaltyDefectiveBagsKg').value) || 0;

      // net = gross - bags - refraction - penalty
      var net = gross - bags - refQuality - penalty;
      if (net < 0) net = 0;
      document.getElementById('netWeightKg').value = net.toFixed(2);

      var price = moneyVal('sellingPricePerKg');
      var otherExp = moneyVal('otherExpenses');
      var interest = moneyVal('interestFees');

      // delivery total cost (transport + loading + other logistics)
      var deliveryTotalCost = parseFloat(document.getElementById('deliveryTotalCostHidden').value) || 0;
      var procCostPerKg = parseFloat(document.getElementById('procCostPerKgHidden').value) || 0;
      // product cost = sum of purchase costs from delivery_items (weight bought, not customer-measured)
      var purchaseWt = parseFloat(document.getElementById('purchaseWeightKgHidden').value) || 0;
      var productCost = parseFloat(document.getElementById('productCostHidden').value) || 0;
      // fallback for legacy deliveries: proc cost x purchase weight
      if (productCost <= 0) productCost = procCostPerKg * purchaseWt;

      var grossSale = net * price;
      var totalCosts = productCost + deliveryTotalCost + otherExp + interest;
      var netProfit = grossSale - totalCosts;

      var fmt2 = function(v) { return v.toLocaleString(undefined, {maximumFractionDigits: 0}); };
      document.getElementById('grossSaleAmountDisplay').textContent = fmt2(grossSale);
      document.getElementById('productCostDisplay').textContent = fmt2(productCost);
      document.getElementById('totalCostsDisplay').textContent = fmt2(totalCosts);
      var profitEl = document.getElementById('netProfitDisplay');
      profitEl.textContent = fmt2(netProfit);
      profitEl.style.color = netProfit >= 0 ? '#10b981' : '#f43f5e';
  }

  function loadSales() {
      $('#skeletonLoader').show();
      $('#tableContainer').hide();

      $.ajax({
          url: '?action=getSales',
          method: 'GET',
          dataType: 'json',
          success: function(response) {
              if (response.success) {
                  salesData = response.data;
                  $('#filtersSection').show();
                  populateSeasonFilter(response.data);
                  initializeDataTable(response.data);
              } else {
                  $('#skeletonLoader').hide();
                  Swal.fire({ icon: 'error', title: 'Error', text: response.message || 'Failed to load sales' });
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
      if (salesTable) {
          salesTable.destroy();
          $('#salesTable').empty();
      }

      var columns = [
          { data: 'sale_id', title: 'ID' },
          { data: 'delivery_id', title: 'Delivery', defaultContent: '' },
          {
              data: 'customer_name',
              title: 'Customer',
              render: function(data, type, row) {
                  var html = data || '';
                  if (row.customer_id) html += '<br><small class="text-slate-400 text-xs">' + row.customer_id + '</small>';
                  return html;
              }
          },
          { data: 'unloading_date', title: 'Date' },
          {
              data: 'gross_weight_kg',
              title: 'Gross(kg)',
              render: function(data) { return data ? parseFloat(data).toLocaleString() : '0'; }
          },
          {
              data: 'net_weight_kg',
              title: 'Net(kg)',
              render: function(data) { return data ? parseFloat(data).toLocaleString() : '0'; }
          },
          {
              data: 'selling_price_per_kg',
              title: 'Price/Kg',
              render: function(data) { return data ? parseFloat(data).toLocaleString() : '0'; }
          },
          {
              data: 'gross_sale_amount',
              title: 'Gross Sale',
              render: function(data) { return data ? parseFloat(data).toLocaleString() : '0'; }
          },
          {
              data: 'net_profit',
              title: 'Net Profit',
              render: function(data) {
                  var val = parseFloat(data) || 0;
                  var color = val >= 0 ? '#10b981' : '#f43f5e';
                  return '<span style="color:' + color + ';font-weight:600" class="tabular">' + val.toLocaleString() + '</span>';
              }
          },
          {
              data: 'sale_status',
              title: 'Status',
              render: function(data) {
                  var cls = statusBadgeMap[data] || 'status-draft';
                  return '<span class="status-badge ' + cls + '">' + (data || 'Draft') + '</span>';
              }
          }
      ];

      columns.push({
          data: null,
          title: 'Actions',
          orderable: false,
          render: function(data, type, row) {
              var html = '';
              html += '<button class="action-icon" onclick="viewSaleDetail(\'' + row.sale_id + '\')" title="View Details" style="color:#0ea5e9;"><i class="fas fa-eye"></i></button> ';
              html += '<button class="action-icon" onclick="printSaleReceipt(\'' + row.sale_id + '\')" title="Print Receipt" style="color:#475569;"><i class="fas fa-print"></i></button> ';
              if (canUpdate) {
                  html += '<button class="action-icon edit-icon" onclick=\'editSale(' + JSON.stringify(row).replace(/'/g, "\\'") + ')\' title="Edit"><i class="fas fa-edit"></i></button> ';
              }
              if (canDelete) {
                  html += '<button class="action-icon delete-icon" onclick="deleteSale(\'' + row.sale_id + '\')" title="Delete"><i class="fas fa-trash"></i></button>';
              }
              return html;
          }
      });

      setTimeout(function() {
          salesTable = $('#salesTable').DataTable({
              data: data,
              destroy: true,
              columns: columns,
              pageLength: 50,
              lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
              responsive: true,
              dom: 'Blfrtip',
              buttons: [
                  { extend: 'csv', text: '<i class="fas fa-file-csv"></i> CSV', exportOptions: { columns: ':not(:last-child)' } },
                  { extend: 'pdf', text: '<i class="fas fa-file-pdf"></i> PDF', exportOptions: { columns: ':not(:last-child)' } },
                  { extend: 'print', text: '<i class="fas fa-print"></i> Print', exportOptions: { columns: ':not(:last-child)' } }
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
      if (!salesTable) return;

      $.fn.dataTable.ext.search = [];

      var dateFrom = document.getElementById('filterDateFrom').value;
      var dateTo = document.getElementById('filterDateTo').value;
      var status = document.getElementById('filterStatus').value;
      var season = document.getElementById('filterSeason').value;

      if (dateFrom || dateTo) {
          $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
              var rawDate = salesData[dataIndex]?.unloading_date_raw;
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
              return salesData[dataIndex]?.sale_status === status;
          });
      }

      if (season) {
          $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
              return salesData[dataIndex]?.season === season;
          });
      }

      salesTable.draw();
  }

  function clearFilters() {
      document.getElementById('filterDateFrom').value = '';
      document.getElementById('filterDateTo').value = '';
      document.getElementById('filterStatus').value = '';
      document.getElementById('filterSeason').value = '';

      if (salesTable) {
          $.fn.dataTable.ext.search = [];
          salesTable.columns().search('').draw();
      }
  }

  function openAddModal() {
      isEditMode = false;
      document.getElementById('modalTitle').innerHTML = '<i class="fas fa-tag text-brand-500"></i> Add Sale';
      document.getElementById('saleForm').reset();
      document.getElementById('saleId').value = '';
      document.getElementById('saleIdInfo').style.display = 'none';
      document.getElementById('customerId').value = '';
      document.getElementById('customerDisplay').value = '';
      document.getElementById('deliveryTotalCostHidden').value = '0';
      document.getElementById('procCostPerKgHidden').value = '0';
      document.getElementById('purchaseWeightKgHidden').value = '0';
      document.getElementById('productCostHidden').value = '0';
      document.getElementById('season').value = ACTIVE_SEASON;
      document.getElementById('otherExpenses').value = '0';
      document.getElementById('interestFees').value = '0';
      document.getElementById('firstWeightKg').value = '';
      document.getElementById('secondWeightKg').value = '';
      document.getElementById('grossWeightKg').value = '';
      document.getElementById('emptyBagsQty').value = '0';
      document.getElementById('refractionQualityKg').value = '0';
      document.getElementById('penaltyDefectiveBagsKg').value = '0';
      document.getElementById('netWeightKg').value = '0';
      document.getElementById('grossSaleAmountDisplay').textContent = '0';
      document.getElementById('productCostDisplay').textContent = '0';
      document.getElementById('transportCostDisplay').textContent = '0';
      document.getElementById('totalCostsDisplay').textContent = '0';
      document.getElementById('netProfitDisplay').textContent = '0';
      document.getElementById('saleStatus').value = 'Draft';

      var today = new Date().toISOString().split('T')[0];
      document.getElementById('unloadingDate').value = today;

      // reset AI receipt reader
      var aiStatus = document.getElementById('aiReceiptStatus');
      if (aiStatus) { aiStatus.style.display = 'none'; aiStatus.innerHTML = ''; }
      var aiFile = document.getElementById('aiReceiptFile');
      if (aiFile) aiFile.value = '';

      // Re-enable delivery dropdown for add mode
      document.getElementById('deliveryId').disabled = false;

      document.getElementById('saleModal').classList.add('active');
  }

  function editSale(row) {
      isEditMode = true;
      document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit text-brand-500"></i> Edit Sale';
      document.getElementById('saleId').value = row.sale_id;
      document.getElementById('saleIdInfo').style.display = 'block';
      document.getElementById('saleIdDisplay').textContent = row.sale_id;

      // Set delivery dropdown
      var delSelect = document.getElementById('deliveryId');
      delSelect.value = row.delivery_id || '';
      // If delivery_id not in dropdown (e.g. status changed), add it temporarily
      if (row.delivery_id && !delSelect.value) {
          var opt = document.createElement('option');
          opt.value = row.delivery_id;
          opt.textContent = row.delivery_id;
          delSelect.appendChild(opt);
          delSelect.value = row.delivery_id;
      }

      document.getElementById('customerId').value = row.customer_id || '';
      document.getElementById('customerDisplay').value = row.customer_name || '';
      document.getElementById('unloadingDate').value = row.unloading_date_raw;
      document.getElementById('locationId').value = row.location_id || '';
      document.getElementById('firstWeightKg').value = row.first_weight_kg || '';
      document.getElementById('secondWeightKg').value = row.second_weight_kg || '';
      document.getElementById('grossWeightKg').value = row.gross_weight_kg || '';
      document.getElementById('emptyBagsQty').value = row.empty_bags_qty || 0;
      document.getElementById('refractionQualityKg').value = row.refraction_quality_kg || 0;
      document.getElementById('penaltyDefectiveBagsKg').value = row.penalty_defective_bags_kg || 0;
      document.getElementById('netWeightKg').value = row.net_weight_kg || 0;
      document.getElementById('korAtSale').value = row.kor_at_sale || '';
      document.getElementById('humidityAtSale').value = row.humidity_at_sale || '';
      setMoneyVal('sellingPricePerKg', row.selling_price_per_kg);
      setMoneyVal('otherExpenses', row.other_expenses || 0);
      setMoneyVal('interestFees', row.interest_fees || 0);
      document.getElementById('saleStatus').value = row.sale_status || 'Draft';
      document.getElementById('season').value = row.season || ACTIVE_SEASON;

      // Set delivery costs from row data -- show full delivery cost (transport+loading+other)
      var delTotal = parseFloat(row.delivery_total_cost || 0);
      document.getElementById('transportCostDisplay').textContent = delTotal.toLocaleString();
      document.getElementById('deliveryTotalCostHidden').value = delTotal;
      document.getElementById('procCostPerKgHidden').value = row.delivery_proc_cost_per_kg || 0;
      // preload snapshot so the UI has something while we fetch fresh delivery_items cost
      var snapshotWt = parseFloat(row.purchase_weight_kg || 0);
      if (snapshotWt <= 0) snapshotWt = parseFloat(row.delivery_weight_kg || 0);
      document.getElementById('purchaseWeightKgHidden').value = snapshotWt;
      document.getElementById('productCostHidden').value = 0;

      computeSaleFields();

      // pull authoritative purchase weight + cost from delivery_items (weight bought)
      if (row.delivery_id) {
          $.get('?action=getDeliveryDetails&delivery_id=' + encodeURIComponent(row.delivery_id), function(resp) {
              if (resp && resp.success && resp.data) {
                  document.getElementById('purchaseWeightKgHidden').value = resp.data.purchase_weight_kg || snapshotWt;
                  document.getElementById('productCostHidden').value = resp.data.product_cost || 0;
                  computeSaleFields();
              }
          }, 'json');
      }

      document.getElementById('saleModal').classList.add('active');
  }

  function closeModal() {
      document.getElementById('saleModal').classList.remove('active');
      document.getElementById('saleForm').reset();
  }

  // Click outside to close
  document.getElementById('saleModal')?.addEventListener('click', function(e) {
      if (e.target === this) closeModal();
  });

  // Form submission
  document.getElementById('saleForm')?.addEventListener('submit', function(e) {
      e.preventDefault();

      if (!document.getElementById('deliveryId').value) {
          Swal.fire({ icon: 'warning', title: 'Validation', text: 'Please select a delivery' });
          return;
      }

      var formData = new FormData(this);
      var action = isEditMode ? 'updateSale' : 'addSale';

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
                  setTimeout(function() { loadSales(); }, 100);
              } else {
                  Swal.fire({ icon: 'error', title: 'Error', text: response.message });
              }
          },
          error: function(xhr, status, error) {
              Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error: ' + error });
          }
      });
  });

  // ===================== Print Sale Receipt =====================
  function printSaleReceipt(saleId) {
      Swal.fire({ title: 'Generating receipt...', allowOutsideClick: false, didOpen: function() { Swal.showLoading(); } });

      $.getJSON('?action=getSaleReceipt&sale_id=' + encodeURIComponent(saleId)).done(function(r) {
          Swal.close();
          if (!r.success) { Swal.fire({ icon: 'error', title: 'Erreur', text: r.message }); return; }

          var s = r.data.sale;
          var fin = r.data.financing;
          var company = r.data.companyName || '7503 Canada';
          var fmtN = function(n) { return Number(n).toLocaleString('fr-FR'); };
          var fmtDate = function(d) {
              if (!d) return '-';
              var parts = d.split('-');
              return parts[2] + '/' + parts[1] + '/' + parts[0];
          };
          var now = new Date();
          var genDate = now.toLocaleDateString('fr-FR') + ' \u00e0 ' + now.toLocaleTimeString('fr-FR', {hour:'2-digit',minute:'2-digit'});

          // Calculate net amount
          var grossAmount = parseFloat(s.gross_sale_amount) || 0;
          var totalCosts = parseFloat(s.total_costs) || 0;
          var netRevenue = parseFloat(s.net_revenue) || 0;
          var transportCost = parseFloat(s.transport_cost) || 0;
          var otherExpenses = parseFloat(s.other_expenses) || 0;
          var interestFees = parseFloat(s.interest_fees) || 0;

          // Financing rows HTML
          var finHtml = '';
          var totalBalanceDue = 0;
          if (fin.length > 0) {
              fin.forEach(function(f) {
                  totalBalanceDue += parseFloat(f.balance_due);
                  finHtml += '<tr>' +
                      '<td>' + f.financing_id + '</td>' +
                      '<td style="text-align:right;">' + fmtN(f.amount) + ' FCFA</td>' +
                      '<td style="text-align:right;">' + fmtN(f.amount_repaid) + ' FCFA</td>' +
                      '<td style="text-align:right;">' + fmtN(f.balance_due) + ' FCFA</td>' +
                  '</tr>';
              });
          }

          // Build one copy of the receipt
          function buildCopy(copyLabel, copyClass) {
              var finSection = '';
              if (fin.length > 0) {
                  finSection = '<div class="section-title">SITUATION FINANCI\u00c8RE \u2014 PR\u00c9-FINANCEMENT CLIENT</div>' +
                      '<table class="receipt-table">' +
                          '<thead><tr><th style="text-align:left;">R\u00e9f.</th><th>Montant</th><th>Rembours\u00e9</th><th>Solde</th></tr></thead>' +
                          '<tbody>' + finHtml + '</tbody>' +
                      '</table>';
              }

              return '<div class="receipt-copy">' +
                  // Header
                  '<div class="receipt-header">' +
                      '<div><div class="company-name">' + company + '</div>' +
                      '<div class="company-sub">N\u00e9goce de Noix de Cajou Brutes \u2014 C\u00f4te d\'Ivoire</div></div>' +
                      '<div style="text-align:right;"><div class="receipt-title">RE\u00c7U DE VENTE</div>' +
                      '<div class="receipt-num">N\u00b0 ' + s.sale_id + '</div></div>' +
                  '</div>' +
                  // Copy label
                  '<div class="copy-label ' + copyClass + '">' + copyLabel + '</div>' +
                  // Info grid
                  '<div class="info-grid-3">' +
                      '<div><div class="info-label">Date</div><div class="info-val">' + fmtDate(s.unloading_date) + '</div></div>' +
                      '<div><div class="info-label">Client</div><div class="info-val">' + (s.customer_name || '-') + '</div></div>' +
                      '<div><div class="info-label">ID Client</div><div class="info-val">' + (s.customer_id || '-') + '</div></div>' +
                      '<div><div class="info-label">Lieu</div><div class="info-val">' + (s.location_name || '-') + '</div></div>' +
                      '<div><div class="info-label">Entrep\u00f4t</div><div class="info-val">' + (s.warehouse_name || '-') + '</div></div>' +
                      '<div><div class="info-label">R\u00e9f. Vente</div><div class="info-val">' + s.sale_id + '</div></div>' +
                  '</div>' +
                  // Sale details table
                  '<div class="section-title">D\u00c9TAILS DE LA VENTE</div>' +
                  '<table class="receipt-table">' +
                      '<thead><tr><th style="text-align:left;">Produit</th><th>Poids (kg)</th><th>Prix/kg</th><th>Montant Brut</th></tr></thead>' +
                      '<tbody>' +
                          '<tr><td>Anacarde (Noix de Cajou Brutes)</td>' +
                          '<td style="text-align:right;">' + fmtN(s.net_weight_kg) + '</td>' +
                          '<td style="text-align:right;">' + fmtN(s.selling_price_per_kg) + ' FCFA</td>' +
                          '<td style="text-align:right;">' + fmtN(grossAmount) + ' FCFA</td></tr>' +
                      '</tbody>' +
                  '</table>' +
                  // Cost breakdown table
                  '<div class="section-title">VENTILATION DES CO\u00dbTS</div>' +
                  '<table class="receipt-table">' +
                      '<thead><tr><th style="text-align:left;">D\u00e9signation</th><th>Montant</th></tr></thead>' +
                      '<tbody>' +
                          '<tr><td>Transport</td><td style="text-align:right;">' + fmtN(transportCost) + ' FCFA</td></tr>' +
                          '<tr><td>Chargement</td><td style="text-align:right;">' + fmtN(interestFees) + ' FCFA</td></tr>' +
                          '<tr><td>Autres</td><td style="text-align:right;">' + fmtN(otherExpenses) + ' FCFA</td></tr>' +
                          '<tr class="total-row"><td><strong>Total Co\u00fbts</strong></td><td style="text-align:right;"><strong>' + fmtN(totalCosts) + ' FCFA</strong></td></tr>' +
                      '</tbody>' +
                  '</table>' +
                  // Net amounts summary
                  '<div class="net-summary">' +
                      '<div class="net-row"><span>Montant Brut</span><span>' + fmtN(grossAmount) + ' FCFA</span></div>' +
                      '<div class="net-row"><span>Total Co\u00fbts</span><span>- ' + fmtN(totalCosts) + ' FCFA</span></div>' +
                      '<div class="net-row" style="font-weight:700;border-top:1px solid #ccc;padding-top:6px;"><span>Montant Net</span><span>' + fmtN(netRevenue) + ' FCFA</span></div>' +
                  '</div>' +
                  // Financing section (only if exists)
                  finSection +
                  // Signatures
                  '<div class="signatures">' +
                      '<div class="sig-block">' +
                          '<div class="sig-line"></div>' +
                          '<div class="sig-label">Signature ' + company + '</div>' +
                      '</div>' +
                      '<div class="sig-block">' +
                          '<div class="sig-line"></div>' +
                          '<div class="sig-label">Signature Client</div>' +
                          '<div class="sig-name">' + (s.customer_name || '') + '</div>' +
                          '<div class="sig-note">(Pr\u00e9c\u00e9d\u00e9 de la mention "lu et approuv\u00e9")</div>' +
                      '</div>' +
                  '</div>' +
                  // Footer
                  '<div class="receipt-footer">' +
                      '<span>' + company + ' \u2014 Daloa, C\u00f4te d\'Ivoire</span>' +
                      '<span>R\u00e9f: ' + s.sale_id + ' | G\u00e9n\u00e9r\u00e9 le ' + genDate + '</span>' +
                  '</div>' +
              '</div>';
          }

          var receiptHTML = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Re\u00e7u Vente - ' + s.sale_id + '</title>' +
          '<style>' +
              '@page { size: A4; margin: 10mm 12mm; }' +
              '* { margin: 0; padding: 0; box-sizing: border-box; }' +
              'body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; font-size: 11px; color: #333; }' +
              '.receipt-copy { padding: 18px 22px 12px; page-break-inside: avoid; }' +
              '.receipt-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 14px; }' +
              '.company-name { font-size: 22px; font-weight: 800; color: #1a5c2a; }' +
              '.company-sub { font-size: 10px; color: #555; margin-top: 2px; }' +
              '.receipt-title { font-size: 16px; font-weight: 700; color: #1a5c2a; }' +
              '.receipt-num { font-size: 11px; color: #555; margin-top: 2px; }' +
              '.copy-label { display: inline-block; padding: 4px 14px; border: 2px solid #1a5c2a; font-size: 10px; font-weight: 700; text-transform: uppercase; margin-bottom: 12px; letter-spacing: 0.5px; }' +
              '.copy-client { color: #1a5c2a; }' +
              '.copy-company { color: #fff; background: #1a5c2a; }' +
              '.info-grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 6px 20px; margin-bottom: 14px; border-bottom: 1px solid #e0e0e0; padding-bottom: 10px; }' +
              '.info-label { font-size: 9px; color: #888; text-transform: uppercase; letter-spacing: 0.3px; }' +
              '.info-val { font-size: 13px; font-weight: 700; color: #222; }' +
              '.section-title { font-size: 11px; font-weight: 700; color: #222; margin: 12px 0 6px; text-transform: uppercase; letter-spacing: 0.3px; }' +
              '.receipt-table { width: 100%; border-collapse: collapse; margin-bottom: 10px; font-size: 11px; }' +
              '.receipt-table thead th { background: #1a5c2a; color: #fff; padding: 6px 8px; font-size: 10px; font-weight: 600; text-align: right; }' +
              '.receipt-table thead th:first-child { text-align: left; }' +
              '.receipt-table tbody td { padding: 6px 8px; border-bottom: 1px solid #eee; }' +
              '.receipt-table .total-row td { border-top: 2px solid #1a5c2a; border-bottom: none; font-weight: 700; }' +
              '.net-summary { margin: 10px 0 4px; padding: 8px 12px; background: #f9f9f9; border-radius: 4px; }' +
              '.net-row { display: flex; justify-content: space-between; padding: 3px 0; font-size: 12px; }' +
              '.balance-box { text-align: center; margin: 14px auto; max-width: 340px; }' +
              '.balance-label { font-size: 9px; color: #555; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px; }' +
              '.balance-amount { font-size: 24px; font-weight: 800; color: #1a5c2a; border: 3px solid #1a5c2a; border-radius: 6px; padding: 8px 20px; background: rgba(26,92,42,0.04); }' +
              '.signatures { display: flex; justify-content: space-between; margin-top: 30px; gap: 40px; }' +
              '.sig-block { flex: 1; }' +
              '.sig-line { border-bottom: 1px solid #333; margin-bottom: 6px; height: 40px; }' +
              '.sig-label { font-size: 10px; color: #555; }' +
              '.sig-name { font-size: 12px; font-weight: 700; }' +
              '.sig-note { font-size: 9px; color: #888; font-style: italic; }' +
              '.receipt-footer { display: flex; justify-content: space-between; font-size: 9px; color: #888; margin-top: 10px; padding-top: 6px; border-top: 1px solid #e0e0e0; }' +
              '.cut-line { text-align: center; padding: 6px 0; font-size: 10px; color: #aaa; letter-spacing: 2px; border-top: 2px dashed #ccc; border-bottom: 2px dashed #ccc; margin: 4px 0; }' +
          '</style></head><body>' +
              buildCopy('COPIE CLIENT', 'copy-client') +
              '<div class="cut-line">- - - - - COUPER ICI / D\u00c9TACHER - - - - -</div>' +
              buildCopy('COPIE COOP\u00c9RATIVE \u2014 ' + company, 'copy-company') +
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
          Swal.fire({ icon: 'error', title: 'Erreur', text: 'Impossible de charger les donn\u00e9es du re\u00e7u' });
      });
  }

  // AI receipt reader
  function readReceiptAI() {
      var fileInput = document.getElementById('aiReceiptFile');
      if (!fileInput.files.length) {
          Swal.fire({icon:'warning', title:'No File', text:'Please select a receipt image or PDF.'});
          return;
      }

      var statusEl = document.getElementById('aiReceiptStatus');
      statusEl.style.display = 'block';
      statusEl.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Reading receipt with AI...';

      var fd = new FormData();
      fd.append('receipt_image', fileInput.files[0]);

      $.ajax({
          url: 'ai-helper.php?action=readReceipt',
          method: 'POST',
          data: fd,
          processData: false,
          contentType: false,
          dataType: 'json',
          success: function(res) {
              if (res.success && res.data) {
                  var d = res.data;
                  statusEl.innerHTML = '<i class="fas fa-check-circle" style="color:#10b981;"></i> Fields extracted! Review and adjust below.';

                  // auto-fill form fields
                  if (d.weight_kg && parseFloat(d.weight_kg) > 0) {
                      var grossEl = document.getElementById('grossWeightKg');
                      if (grossEl && !grossEl.value) grossEl.value = d.weight_kg;
                  }
                  if (d.price_per_kg && parseFloat(d.price_per_kg) > 0) {
                      var priceEl = document.getElementById('sellingPricePerKg');
                      if (priceEl) priceEl.value = d.price_per_kg;
                  }
                  if (d.humidity) {
                      var humEl = document.getElementById('humidityAtSale');
                      if (humEl) humEl.value = d.humidity;
                  }
                  if (d.kor) {
                      var korEl = document.getElementById('korAtSale');
                      if (korEl) korEl.value = d.kor;
                  }
                  if (d.date) {
                      var dateEl = document.getElementById('unloadingDate');
                      if (dateEl) dateEl.value = d.date;
                  }

                  // trigger recalculation
                  if (typeof computeSaleFields === 'function') computeSaleFields();

                  // show extracted data summary
                  var html = '<div style="text-align:left;font-size:13px;">';
                  if (d.date) html += '<p><strong>Date:</strong> ' + d.date + '</p>';
                  if (d.reference_number) html += '<p><strong>Reference:</strong> ' + d.reference_number + '</p>';
                  if (d.customer_name) html += '<p><strong>Customer:</strong> ' + d.customer_name + '</p>';
                  if (d.weight_kg && parseFloat(d.weight_kg) > 0) html += '<p><strong>Weight:</strong> ' + d.weight_kg + ' kg</p>';
                  if (d.num_bags && parseInt(d.num_bags) > 0) html += '<p><strong>Bags:</strong> ' + d.num_bags + '</p>';
                  if (d.price_per_kg && parseFloat(d.price_per_kg) > 0) html += '<p><strong>Price/kg:</strong> ' + parseFloat(d.price_per_kg).toLocaleString() + '</p>';
                  if (d.total_amount && parseFloat(d.total_amount) > 0) html += '<p><strong>Total:</strong> ' + parseFloat(d.total_amount).toLocaleString() + '</p>';
                  if (d.quality_grade) html += '<p><strong>Quality:</strong> ' + d.quality_grade + '</p>';
                  if (d.humidity) html += '<p><strong>Humidity:</strong> ' + d.humidity + '%</p>';
                  if (d.kor) html += '<p><strong>KOR:</strong> ' + d.kor + '</p>';
                  if (d.notes) html += '<p><strong>Notes:</strong> ' + d.notes + '</p>';
                  html += '</div>';

                  Swal.fire({
                      icon: 'success',
                      title: 'AI Extracted Data',
                      html: html,
                      confirmButtonText: 'OK'
                  });
              } else {
                  statusEl.innerHTML = '<i class="fas fa-times-circle" style="color:#f43f5e;"></i> ' + (res.message || 'Failed to read receipt');
              }
          },
          error: function() {
              statusEl.innerHTML = '<i class="fas fa-times-circle" style="color:#f43f5e;"></i> Connection error';
          }
      });
  }

  function deleteSale(saleId) {
      Swal.fire({
          title: 'Delete Sale?',
          text: 'This action cannot be undone.',
          icon: 'warning',
          showCancelButton: true,
          confirmButtonColor: '#d33',
          confirmButtonText: 'Yes, delete it!'
      }).then(function(result) {
          if (result.isConfirmed) {
              var formData = new FormData();
              formData.append('sale_id', saleId);

              $.ajax({
                  url: '?action=deleteSale',
                  method: 'POST',
                  data: formData,
                  processData: false,
                  contentType: false,
                  dataType: 'json',
                  success: function(response) {
                      if (response.success) {
                          Swal.fire({ icon: 'success', title: 'Deleted!', text: response.message, timer: 2000, showConfirmButton: false });
                          loadSales();
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

  // Sale Detail Modal
  function viewSaleDetail(saleId) {
      document.getElementById('saleDetailModal').classList.add('active');
      document.getElementById('saleDetailBody').innerHTML = '<div class="skeleton-row skeleton" style="height:200px;"></div>';

      $.getJSON('?action=getSaleDetail&sale_id=' + encodeURIComponent(saleId), function(res) {
          if (!res.success) {
              document.getElementById('saleDetailBody').innerHTML = '<p class="text-rose-500">Error: ' + (res.message || 'Failed') + '</p>';
              return;
          }
          var d = res.data;
          var fmt = function(n) { return n ? Number(n).toLocaleString() : '0'; };
          var fmtD = function(dt) { if (!dt) return 'N/A'; var p = dt.split('-'); return p[2] + '/' + p[1] + '/' + p[0]; };

          var origin = d.origin_warehouse || 'N/A';
          if (d.origin_location) origin += ' (' + d.origin_location + ')';
          var dest = d.customer_name || 'N/A';
          if (d.dest_location) dest += ' (' + d.dest_location + ')';

          var profitColor = parseFloat(d.net_profit) >= 0 ? '#10b981' : '#f43f5e';
          var marginColor = parseFloat(d.margin_per_kg) >= 0 ? '#10b981' : '#f43f5e';

          var html = '';

          // Route
          html += '<div class="bg-slate-50 dark:bg-slate-700/50 rounded-xl p-4 mb-4 text-center">';
          html += '<div class="text-[11px] uppercase text-slate-400 mb-1.5 tracking-wide font-medium">Route</div>';
          html += '<div class="text-sm font-semibold text-slate-800 dark:text-slate-200">';
          html += '<i class="fas fa-warehouse text-sky-500"></i> ' + origin;
          html += ' <i class="fas fa-long-arrow-alt-right text-slate-300 mx-2"></i> ';
          html += '<i class="fas fa-user-tie text-emerald-500"></i> ' + dest;
          html += '</div></div>';

          // KPI cards
          html += '<div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-4">';

          html += '<div class="bg-slate-50 dark:bg-slate-700/50 rounded-lg p-3 text-center">';
          html += '<div class="text-[10px] uppercase text-slate-400 tracking-wide font-medium">Total Buying</div>';
          html += '<div class="text-base font-bold text-slate-800 dark:text-slate-200 mt-1 tabular">' + fmt(d.total_buying) + ' F</div></div>';

          html += '<div class="bg-slate-50 dark:bg-slate-700/50 rounded-lg p-3 text-center">';
          html += '<div class="text-[10px] uppercase text-slate-400 tracking-wide font-medium">Avg Buy/Kg</div>';
          html += '<div class="text-base font-bold text-slate-800 dark:text-slate-200 mt-1 tabular">' + fmt(d.avg_buy_price_per_kg) + ' F</div></div>';

          html += '<div class="bg-slate-50 dark:bg-slate-700/50 rounded-lg p-3 text-center">';
          html += '<div class="text-[10px] uppercase text-slate-400 tracking-wide font-medium">Sell/Kg</div>';
          html += '<div class="text-base font-bold text-sky-500 mt-1 tabular">' + fmt(d.selling_price_per_kg) + ' F</div></div>';

          html += '<div class="bg-slate-50 dark:bg-slate-700/50 rounded-lg p-3 text-center">';
          html += '<div class="text-[10px] uppercase text-slate-400 tracking-wide font-medium">Margin/Kg</div>';
          html += '<div class="text-base font-bold mt-1 tabular" style="color:' + marginColor + ';">' + fmt(d.margin_per_kg) + ' F (' + d.margin_pct + '%)</div></div>';

          html += '</div>';

          // Detail rows
          html += '<div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl mb-4 overflow-hidden shadow-card">';

          var row = function(label, value, extra) {
              extra = extra || '';
              return '<div class="flex justify-between items-center px-4 py-3 border-b border-slate-100 dark:border-slate-700/50 ' + extra + '">' +
                  '<span class="text-xs uppercase tracking-wide text-slate-400 font-semibold">' + label + '</span>' +
                  '<span class="text-sm font-semibold text-slate-800 dark:text-slate-200 text-right tabular">' + value + '</span></div>';
          };

          html += row('Sale ID', '<span class="font-mono text-xs">' + d.sale_id + '</span>');
          html += row('Delivery', '<span class="font-mono text-xs">' + (d.delivery_id || 'N/A') + '</span>');
          html += row('Date', fmtD(d.unloading_date));
          html += row('Net Weight', fmt(d.net_weight_kg) + ' <span class="text-slate-400 text-xs font-normal">kg</span>');
          html += row('Gross Sale', '<span class="text-sky-500 text-base">' + fmt(d.gross_sale_amount) + '</span> <span class="text-slate-400 text-xs font-normal">F</span>');
          html += row('Total Costs', '<span class="text-slate-500">' + fmt(d.total_costs) + '</span> <span class="text-slate-400 text-xs font-normal">F</span>');
          html += '<div class="flex justify-between items-center px-4 py-3 border-b border-slate-100 dark:border-slate-700/50 bg-emerald-50/50 dark:bg-emerald-900/10">' +
              '<span class="text-xs uppercase tracking-wide text-slate-400 font-semibold">Net Profit</span>' +
              '<span class="text-lg font-bold tabular" style="color:' + profitColor + ';">' + fmt(d.net_profit) + ' <span class="text-slate-400 text-xs font-normal">F</span></span></div>';
          if (d.kor_at_sale) html += row('KOR', d.kor_at_sale);
          if (d.humidity_at_sale) html += row('Humidity', d.humidity_at_sale + '%');
          html += '<div class="flex justify-between items-center px-4 py-3">' +
              '<span class="text-xs uppercase tracking-wide text-slate-400 font-semibold">Status</span>' +
              '<span class="status-badge ' + (statusBadgeMap[d.sale_status] || '') + '">' + d.sale_status + '</span></div>';

          html += '</div>';

          // Receipt section
          html += '<div class="border-t border-slate-200 dark:border-slate-700 pt-4">';
          html += '<div class="text-sm font-semibold text-slate-700 dark:text-slate-300 mb-3"><i class="fas fa-file-image text-brand-500 mr-1"></i> Delivery Receipt</div>';

          if (d.receipt_file) {
              var isImg = /\.(jpg|jpeg|png|webp)$/i.test(d.receipt_file);
              if (isImg) {
                  html += '<div class="mb-3"><img src="uploads/' + d.receipt_file + '" class="max-w-full max-h-[300px] rounded-lg border border-slate-200 dark:border-slate-600 cursor-pointer" onclick="window.open(\'uploads/' + d.receipt_file + '\',\'_blank\')"></div>';
              } else {
                  html += '<div class="mb-3"><a href="uploads/' + d.receipt_file + '" target="_blank" class="bg-brand-500 hover:bg-brand-600 text-white text-xs font-semibold px-3.5 py-2 rounded-lg transition-colors inline-flex items-center gap-1"><i class="fas fa-file-pdf"></i> View Receipt PDF</a></div>';
              }
              if (canUpdate) {
                  html += '<button class="bg-rose-500 hover:bg-rose-600 text-white text-xs font-semibold px-3.5 py-2 rounded-lg transition-colors" onclick="deleteReceiptFile(\'' + d.sale_id + '\')"><i class="fas fa-trash mr-1"></i> Remove</button>';
              }
          } else if (canUpdate) {
              html += '<div class="flex gap-2 items-center flex-wrap">';
              html += '<input type="file" id="receiptFileInput" accept="image/*,.pdf" class="text-sm flex-1 min-w-[160px] text-slate-600 dark:text-slate-300 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-brand-50 file:text-brand-600 dark:file:bg-brand-900/30 dark:file:text-brand-300 hover:file:bg-brand-100">';
              html += '<button class="bg-brand-500 hover:bg-brand-600 text-white text-xs font-semibold px-3.5 py-2 rounded-lg transition-colors whitespace-nowrap" onclick="uploadReceiptFile(\'' + d.sale_id + '\')"><i class="fas fa-upload mr-1"></i> Upload</button>';
              html += '</div>';
              html += '<div class="text-[11px] text-slate-400 mt-1.5">JPG, PNG, WebP or PDF (max 10MB)</div>';
          } else {
              html += '<div class="text-slate-400 text-sm">No receipt uploaded</div>';
          }
          html += '</div>';

          document.getElementById('saleDetailBody').innerHTML = html;
      }).fail(function() {
          document.getElementById('saleDetailBody').innerHTML = '<p class="text-rose-500">Connection error</p>';
      });
  }

  function closeSaleDetail() {
      document.getElementById('saleDetailModal').classList.remove('active');
  }
  document.getElementById('saleDetailModal')?.addEventListener('click', function(e) {
      if (e.target === this) closeSaleDetail();
  });

  function uploadReceiptFile(saleId) {
      var input = document.getElementById('receiptFileInput');
      if (!input || !input.files.length) {
          Swal.fire({ icon: 'warning', title: 'No file', text: 'Please select a file first' });
          return;
      }
      var fd = new FormData();
      fd.append('sale_id', saleId);
      fd.append('receipt_file', input.files[0]);
      Swal.fire({ title: 'Uploading...', allowOutsideClick: false, didOpen: function() { Swal.showLoading(); } });
      $.ajax({
          url: '?action=uploadReceipt', method: 'POST', data: fd, processData: false, contentType: false, dataType: 'json',
          success: function(res) {
              if (res.success) {
                  Swal.fire({ icon: 'success', title: 'Uploaded', text: res.message, timer: 1500, showConfirmButton: false });
                  viewSaleDetail(saleId);
              } else {
                  Swal.fire({ icon: 'error', title: 'Error', text: res.message });
              }
          },
          error: function() { Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error' }); }
      });
  }

  function deleteReceiptFile(saleId) {
      Swal.fire({
          title: 'Remove receipt?', icon: 'warning', showCancelButton: true,
          confirmButtonColor: '#d33', confirmButtonText: 'Remove'
      }).then(function(result) {
          if (result.isConfirmed) {
              var fd = new FormData();
              fd.append('sale_id', saleId);
              $.ajax({
                  url: '?action=deleteReceipt', method: 'POST', data: fd, processData: false, contentType: false, dataType: 'json',
                  success: function(res) {
                      if (res.success) {
                          Swal.fire({ icon: 'success', title: 'Removed', timer: 1500, showConfirmButton: false });
                          viewSaleDetail(saleId);
                      } else {
                          Swal.fire({ icon: 'error', title: 'Error', text: res.message });
                      }
                  }
              });
          }
      });
  }
  </script>

  <!-- Theme init & i18n -->
  <script>
  (function() {
    var _store = {};
    try { _store = window.localStorage; } catch(e) { _store = { getItem: function(){return null;}, setItem: function(){} }; }

    // Theme
    var html = document.documentElement;
    var dark = _store.getItem('cp_theme') === 'dark' || (_store.getItem('cp_theme') === null && window.matchMedia('(prefers-color-scheme: dark)').matches);
    html.classList.toggle('dark', dark);

    // Sidebar collapse
    var appRoot = document.getElementById('appRoot');
    var collapseBtn = document.getElementById('sidebarCollapseBtn');
    if (collapseBtn) {
      collapseBtn.addEventListener('click', function() {
        appRoot.classList.toggle('app-collapsed');
        var ic = document.getElementById('collapseIcon');
        if (ic) ic.style.transform = appRoot.classList.contains('app-collapsed') ? 'rotate(180deg)' : '';
      });
    }

    // i18n
    var currentLang = _store.getItem('cp_lang') || 'en';
    function applyTranslations() {
      document.querySelectorAll('[data-t]').forEach(function(el) {
        var key = el.getAttribute('data-t');
        if (key) el.textContent = key;
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

    // Theme toggle
    var themeBtn = document.getElementById('themeToggleBtn');
    var themeIcon = document.getElementById('themeIcon');
    function applyThemeUI() {
      if (themeIcon) themeIcon.className = dark ? 'fas fa-sun w-4 text-sm' : 'fas fa-moon w-4 text-sm';
      var lbl = document.getElementById('themeLabel');
      if (lbl) lbl.textContent = dark ? 'Light Mode' : 'Dark Mode';
    }
    applyThemeUI();
    if (themeBtn) {
      themeBtn.addEventListener('click', function() {
        dark = !dark;
        html.classList.toggle('dark', dark);
        _store.setItem('cp_theme', dark ? 'dark' : 'light');
        applyThemeUI();
      });
    }
  })();
  </script>

</body>
</html>
