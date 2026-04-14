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
$current_page = 'financing';

// RBAC — financing (bank debts + supplier advances) is finance-only
$allowedRoles = ['Admin', 'Manager', 'Finance Officer'];
if (!in_array($role, $allowedRoles)) {
    header("Location: dashboard.php");
    exit();
}

$canCreate = in_array($role, ['Admin', 'Manager', 'Finance Officer']);
$canUpdate = in_array($role, ['Admin', 'Manager', 'Finance Officer']);
$canDelete = ($role === 'Admin');
$isReadOnly = false;
$supplierOnly = false;

// Handle AJAX requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    try {
        switch ($_GET['action']) {
            case 'getFinancing':
                $conn = getDBConnection();
                if ($supplierOnly) {
                    $stmt = $conn->prepare("SELECT * FROM financing WHERE counterpart_type = 'Supplier' AND source NOT IN ('Auto-Overpayment','Auto-Payable') ORDER BY financing_id DESC");
                } else {
                    $stmt = $conn->prepare("SELECT * FROM financing WHERE source NOT IN ('Auto-Overpayment','Auto-Payable') ORDER BY financing_id DESC");
                }
                $stmt->execute();
                $result = $stmt->get_result();

                $records = [];
                while ($row = $result->fetch_assoc()) {
                    $records[] = [
                        'financing_id' => $row['financing_id'],
                        'date' => date('M d, Y', strtotime($row['date'])),
                        'date_raw' => $row['date'],
                        'direction' => $row['direction'],
                        'counterpart_type' => $row['counterpart_type'],
                        'counterparty_id' => $row['counterparty_id'],
                        'counterpart_name' => $row['counterpart_name'],
                        'carried_over_balance' => $row['carried_over_balance'],
                        'amount' => $row['amount'],
                        'amount_repaid' => $row['amount_repaid'],
                        'current_market_price' => $row['current_market_price'],
                        'expected_volume_kg' => $row['expected_volume_kg'],
                        'delivered_volume_kg' => $row['delivered_volume_kg'],
                        'volume_remaining_kg' => $row['volume_remaining_kg'],
                        'interest_per_kg' => $row['interest_per_kg'],
                        'interest_amount' => $row['interest_amount'],
                        'balance_due' => $row['balance_due'],
                        'status' => $row['status'],
                        'reference_number' => $row['reference_number'],
                        'season' => $row['season'],
                        'notes' => $row['notes'],
                        'interest_rate_pct' => $row['interest_rate_pct'],
                        'monthly_payment' => $row['monthly_payment'],
                        'term_months' => $row['term_months'],
                        'start_date' => $row['start_date'] ? date('M d, Y', strtotime($row['start_date'])) : null,
                        'start_date_raw' => $row['start_date'],
                        'maturity_date' => $row['maturity_date'] ? date('M d, Y', strtotime($row['maturity_date'])) : null,
                        'maturity_date_raw' => $row['maturity_date'],
                        'source' => $row['source'] ?? 'Manual',
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

                // Active banks (only if banks table exists — graceful for un-migrated DBs)
                $banks = [];
                $banksTblRes = $conn->query("SHOW TABLES LIKE 'banks'");
                if ($banksTblRes && $banksTblRes->num_rows > 0) {
                    $stmt = $conn->prepare("SELECT bank_id, bank_name FROM banks WHERE status = 'Active' ORDER BY bank_name ASC");
                    $stmt->execute();
                    $result = $stmt->get_result();
                    while ($row = $result->fetch_assoc()) {
                        $banks[] = $row;
                    }
                    $stmt->close();
                }

                $conn->close();

                echo json_encode([
                    'success' => true,
                    'data' => [
                        'customers' => $customers,
                        'suppliers' => $suppliers,
                        'banks' => $banks
                    ]
                ]);
                exit();

            case 'getCounterpartyPrice':
                $cpId = isset($_GET['counterparty_id']) ? trim($_GET['counterparty_id']) : '';
                $cpType = isset($_GET['counterpart_type']) ? trim($_GET['counterpart_type']) : '';
                if (empty($cpId)) { echo json_encode(['success' => false]); exit(); }

                $conn = getDBConnection();
                $agreedPrice = 0;

                if ($cpType === 'Customer') {
                    $stmt = $conn->prepare("SELECT base_cost_per_kg FROM customer_pricing_agreements WHERE customer_id = ? AND status = 'Active' ORDER BY effective_date DESC LIMIT 1");
                    $stmt->bind_param("s", $cpId);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    if ($res->num_rows > 0) $agreedPrice = floatval($res->fetch_assoc()['base_cost_per_kg']);
                    $stmt->close();
                } elseif ($cpType === 'Supplier') {
                    $stmt = $conn->prepare("SELECT base_cost_per_kg FROM supplier_pricing_agreements WHERE supplier_id = ? AND status = 'Active' ORDER BY effective_date DESC LIMIT 1");
                    $stmt->bind_param("s", $cpId);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    if ($res->num_rows > 0) $agreedPrice = floatval($res->fetch_assoc()['base_cost_per_kg']);
                    $stmt->close();
                }

                $conn->close();
                echo json_encode(['success' => true, 'agreed_price' => $agreedPrice]);
                exit();

            case 'addFinancing':
                if (!$canCreate) {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $date = isset($_POST['date']) ? trim($_POST['date']) : '';
                $direction = isset($_POST['direction']) ? trim($_POST['direction']) : '';
                $counterpartType = isset($_POST['counterpart_type']) ? trim($_POST['counterpart_type']) : '';
                $counterpartyId = isset($_POST['counterparty_id']) ? trim($_POST['counterparty_id']) : '';
                $carriedOverBalance = isset($_POST['carried_over_balance']) ? floatval($_POST['carried_over_balance']) : 0;
                $amount = isset($_POST['amount']) ? floatval($_POST['amount']) : 0;
                $currentMarketPrice = isset($_POST['current_market_price']) ? floatval($_POST['current_market_price']) : 0;
                $expectedVolumeKg = isset($_POST['expected_volume_kg']) ? floatval($_POST['expected_volume_kg']) : 0;
                $interestPerKg = isset($_POST['interest_per_kg']) ? floatval($_POST['interest_per_kg']) : 0;
                $status = isset($_POST['status']) ? trim($_POST['status']) : 'Active';
                $referenceNumber = !empty($_POST['reference_number']) ? trim($_POST['reference_number']) : null;
                $season = isset($_POST['season']) ? trim($_POST['season']) : getActiveSeason();
                $notes = !empty($_POST['notes']) ? trim($_POST['notes']) : null;

                // Validation
                if (empty($date)) {
                    echo json_encode(['success' => false, 'message' => 'Date is required']);
                    exit();
                }
                if (empty($direction) || !in_array($direction, ['Incoming', 'Outgoing'])) {
                    echo json_encode(['success' => false, 'message' => 'Direction is required (Incoming/Outgoing)']);
                    exit();
                }
                if (empty($counterpartType) || !in_array($counterpartType, ['Customer', 'Supplier', 'Bank'])) {
                    echo json_encode(['success' => false, 'message' => 'Counterpart type is required (Customer/Supplier/Bank)']);
                    exit();
                }
                if (empty($counterpartyId)) {
                    echo json_encode(['success' => false, 'message' => 'Counterparty is required']);
                    exit();
                }
                if ($amount <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Amount must be greater than 0']);
                    exit();
                }
                if (empty($season)) {
                    echo json_encode(['success' => false, 'message' => 'Season is required']);
                    exit();
                }

                $conn = getDBConnection();

                // Auto-generate financing_id (FIN-YY-MMDD-XXXX-C)
                $newId = generateTransactionId($conn, 'FIN', 'financing', 'financing_id');

                // Resolve counterpart_name
                $counterpartName = '';
                if ($counterpartType === 'Customer') {
                    $stmt = $conn->prepare("SELECT customer_name FROM customers WHERE customer_id = ?");
                    $stmt->bind_param("s", $counterpartyId);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    if ($res->num_rows > 0) {
                        $counterpartName = $res->fetch_assoc()['customer_name'];
                    }
                    $stmt->close();
                } elseif ($counterpartType === 'Supplier') {
                    $stmt = $conn->prepare("SELECT first_name FROM suppliers WHERE supplier_id = ?");
                    $stmt->bind_param("s", $counterpartyId);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    if ($res->num_rows > 0) {
                        $counterpartName = $res->fetch_assoc()['first_name'];
                    }
                    $stmt->close();
                } else {
                    // Bank — counterpartyId is bank_id from banks table
                    $stmt = $conn->prepare("SELECT bank_name FROM banks WHERE bank_id = ?");
                    $stmt->bind_param("s", $counterpartyId);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    if ($res->num_rows > 0) {
                        $counterpartName = $res->fetch_assoc()['bank_name'];
                    } else {
                        // fallback for legacy free-text bank entries
                        $counterpartName = $counterpartyId;
                    }
                    $stmt->close();
                }

                // Computed fields (delivered_volume_kg=0, amount_repaid=0 on create)
                $deliveredVolumeKg = 0;
                $amountRepaid = 0;
                $volumeRemainingKg = round($expectedVolumeKg - $deliveredVolumeKg, 2);
                $interestAmount = round($interestPerKg * $deliveredVolumeKg, 2);
                $balanceDue = round($amount + $carriedOverBalance + $interestAmount - $amountRepaid, 2);

                // Bank-specific fields
                $interestRatePct = null;
                $monthlyPayment = null;
                $termMonths = null;
                $startDateVal = null;
                $maturityDate = null;

                if ($counterpartType === 'Bank') {
                    $interestRatePct = isset($_POST['interest_rate_pct']) && $_POST['interest_rate_pct'] !== '' ? floatval($_POST['interest_rate_pct']) : null;
                    $monthlyPayment = isset($_POST['monthly_payment']) && $_POST['monthly_payment'] !== '' ? floatval($_POST['monthly_payment']) : null;
                    $termMonths = isset($_POST['term_months']) && $_POST['term_months'] !== '' ? intval($_POST['term_months']) : null;
                    $startDateVal = !empty($_POST['start_date']) ? trim($_POST['start_date']) : null;
                    $maturityDate = !empty($_POST['maturity_date']) ? trim($_POST['maturity_date']) : null;
                    $interestPerKg = 0; // Banks don't use per-kg interest
                    $interestAmount = 0;
                    // For Bank: balance = amount + carried_over + (amount * rate/100) - repaid
                    $bankInterest = ($interestRatePct > 0) ? round($amount * ($interestRatePct / 100), 2) : 0;
                    $balanceDue = round($amount + $carriedOverBalance + $bankInterest - $amountRepaid, 2);
                }

                if ($counterpartType === 'Supplier') {
                    $interestPerKg = 0; // Suppliers don't use interest
                    $interestAmount = 0;
                    $balanceDue = round($amount + $carriedOverBalance - $amountRepaid, 2);
                }

                $stmt = $conn->prepare("INSERT INTO financing (financing_id, date, direction, counterpart_type, counterparty_id, counterpart_name, carried_over_balance, amount, amount_repaid, current_market_price, expected_volume_kg, delivered_volume_kg, volume_remaining_kg, interest_per_kg, interest_amount, balance_due, status, reference_number, season, notes, interest_rate_pct, monthly_payment, term_months, start_date, maturity_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("ssssssddddddddddssssddiss",
                    $newId, $date, $direction, $counterpartType, $counterpartyId, $counterpartName,
                    $carriedOverBalance, $amount, $amountRepaid, $currentMarketPrice,
                    $expectedVolumeKg, $deliveredVolumeKg, $volumeRemainingKg,
                    $interestPerKg, $interestAmount, $balanceDue, $status, $referenceNumber, $season, $notes,
                    $interestRatePct, $monthlyPayment, $termMonths, $startDateVal, $maturityDate
                );

                if ($stmt->execute()) {
                    logActivity($user_id, $username, 'Financing Created', "Created financing: $newId ($direction, $counterpartType: $counterpartName, Amount: $amount)");
                    $stmt->close();
                    if ($counterpartType === 'Supplier') reconcileSupplierAccount($conn, $counterpartyId);
                    $conn->close();
                    echo json_encode(['success' => true, 'message' => 'Financing record added successfully', 'financing_id' => $newId]);
                } else {
                    $error = $stmt->error;
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to add financing: ' . $error]);
                }
                exit();

            case 'updateFinancing':
                if (!$canUpdate) {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $financingId = isset($_POST['financing_id']) ? trim($_POST['financing_id']) : '';
                $date = isset($_POST['date']) ? trim($_POST['date']) : '';
                $direction = isset($_POST['direction']) ? trim($_POST['direction']) : '';
                $counterpartType = isset($_POST['counterpart_type']) ? trim($_POST['counterpart_type']) : '';
                $counterpartyId = isset($_POST['counterparty_id']) ? trim($_POST['counterparty_id']) : '';
                $carriedOverBalance = isset($_POST['carried_over_balance']) ? floatval($_POST['carried_over_balance']) : 0;
                $amount = isset($_POST['amount']) ? floatval($_POST['amount']) : 0;
                $currentMarketPrice = isset($_POST['current_market_price']) ? floatval($_POST['current_market_price']) : 0;
                $expectedVolumeKg = isset($_POST['expected_volume_kg']) ? floatval($_POST['expected_volume_kg']) : 0;
                $interestPerKg = isset($_POST['interest_per_kg']) ? floatval($_POST['interest_per_kg']) : 0;
                $status = isset($_POST['status']) ? trim($_POST['status']) : 'Active';
                $referenceNumber = !empty($_POST['reference_number']) ? trim($_POST['reference_number']) : null;
                $season = isset($_POST['season']) ? trim($_POST['season']) : getActiveSeason();
                $notes = !empty($_POST['notes']) ? trim($_POST['notes']) : null;

                // Validation
                if (empty($financingId)) {
                    echo json_encode(['success' => false, 'message' => 'Financing ID is required']);
                    exit();
                }
                if (empty($date)) {
                    echo json_encode(['success' => false, 'message' => 'Date is required']);
                    exit();
                }
                if (empty($direction) || !in_array($direction, ['Incoming', 'Outgoing'])) {
                    echo json_encode(['success' => false, 'message' => 'Direction is required (Incoming/Outgoing)']);
                    exit();
                }
                if (empty($counterpartType) || !in_array($counterpartType, ['Customer', 'Supplier', 'Bank'])) {
                    echo json_encode(['success' => false, 'message' => 'Counterpart type is required (Customer/Supplier/Bank)']);
                    exit();
                }
                if (empty($counterpartyId)) {
                    echo json_encode(['success' => false, 'message' => 'Counterparty is required']);
                    exit();
                }
                if ($amount <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Amount must be greater than 0']);
                    exit();
                }

                $conn = getDBConnection();

                // Get existing record for delivered_volume_kg and amount_repaid
                $stmt = $conn->prepare("SELECT delivered_volume_kg, amount_repaid FROM financing WHERE financing_id = ?");
                $stmt->bind_param("s", $financingId);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($res->num_rows === 0) {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Financing record not found']);
                    exit();
                }
                $existing = $res->fetch_assoc();
                $stmt->close();

                $deliveredVolumeKg = floatval($existing['delivered_volume_kg']);
                $amountRepaid = floatval($existing['amount_repaid']);

                // Resolve counterpart_name
                $counterpartName = '';
                if ($counterpartType === 'Customer') {
                    $stmt = $conn->prepare("SELECT customer_name FROM customers WHERE customer_id = ?");
                    $stmt->bind_param("s", $counterpartyId);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    if ($res->num_rows > 0) {
                        $counterpartName = $res->fetch_assoc()['customer_name'];
                    }
                    $stmt->close();
                } elseif ($counterpartType === 'Supplier') {
                    $stmt = $conn->prepare("SELECT first_name FROM suppliers WHERE supplier_id = ?");
                    $stmt->bind_param("s", $counterpartyId);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    if ($res->num_rows > 0) {
                        $counterpartName = $res->fetch_assoc()['first_name'];
                    }
                    $stmt->close();
                } else {
                    // Bank — counterpartyId is bank_id from banks table
                    $stmt = $conn->prepare("SELECT bank_name FROM banks WHERE bank_id = ?");
                    $stmt->bind_param("s", $counterpartyId);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    if ($res->num_rows > 0) {
                        $counterpartName = $res->fetch_assoc()['bank_name'];
                    } else {
                        $counterpartName = $counterpartyId;
                    }
                    $stmt->close();
                }

                // Computed fields
                $volumeRemainingKg = round($expectedVolumeKg - $deliveredVolumeKg, 2);
                $interestAmount = round($interestPerKg * $deliveredVolumeKg, 2);
                $balanceDue = round($amount + $carriedOverBalance + $interestAmount - $amountRepaid, 2);

                // Bank-specific fields
                $interestRatePct = null;
                $monthlyPayment = null;
                $termMonths = null;
                $startDateVal = null;
                $maturityDate = null;

                if ($counterpartType === 'Bank') {
                    $interestRatePct = isset($_POST['interest_rate_pct']) && $_POST['interest_rate_pct'] !== '' ? floatval($_POST['interest_rate_pct']) : null;
                    $monthlyPayment = isset($_POST['monthly_payment']) && $_POST['monthly_payment'] !== '' ? floatval($_POST['monthly_payment']) : null;
                    $termMonths = isset($_POST['term_months']) && $_POST['term_months'] !== '' ? intval($_POST['term_months']) : null;
                    $startDateVal = !empty($_POST['start_date']) ? trim($_POST['start_date']) : null;
                    $maturityDate = !empty($_POST['maturity_date']) ? trim($_POST['maturity_date']) : null;
                    $interestPerKg = 0; // Banks don't use per-kg interest
                    $interestAmount = 0;
                    // For Bank: balance = amount + carried_over + (amount * rate/100) - repaid
                    $bankInterest = ($interestRatePct > 0) ? round($amount * ($interestRatePct / 100), 2) : 0;
                    $balanceDue = round($amount + $carriedOverBalance + $bankInterest - $amountRepaid, 2);
                }

                if ($counterpartType === 'Supplier') {
                    $interestPerKg = 0; // Suppliers don't use interest
                    $interestAmount = 0;
                    $balanceDue = round($amount + $carriedOverBalance - $amountRepaid, 2);
                }

                $stmt = $conn->prepare("UPDATE financing SET date = ?, direction = ?, counterpart_type = ?, counterparty_id = ?, counterpart_name = ?, carried_over_balance = ?, amount = ?, current_market_price = ?, expected_volume_kg = ?, volume_remaining_kg = ?, interest_per_kg = ?, interest_amount = ?, balance_due = ?, status = ?, reference_number = ?, season = ?, notes = ?, interest_rate_pct = ?, monthly_payment = ?, term_months = ?, start_date = ?, maturity_date = ? WHERE financing_id = ?");
                $stmt->bind_param("sssssddddddddssssddisss",
                    $date, $direction, $counterpartType, $counterpartyId, $counterpartName,
                    $carriedOverBalance, $amount, $currentMarketPrice,
                    $expectedVolumeKg, $volumeRemainingKg,
                    $interestPerKg, $interestAmount, $balanceDue, $status, $referenceNumber, $season, $notes,
                    $interestRatePct, $monthlyPayment, $termMonths, $startDateVal, $maturityDate, $financingId
                );

                if ($stmt->execute()) {
                    logActivity($user_id, $username, 'Financing Updated', "Updated financing: $financingId (Status: $status, Balance Due: $balanceDue)");
                    $stmt->close();
                    if ($counterpartType === 'Supplier') reconcileSupplierAccount($conn, $counterpartyId);
                    $conn->close();
                    echo json_encode(['success' => true, 'message' => 'Financing record updated successfully']);
                } else {
                    $error = $stmt->error;
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to update financing: ' . $error]);
                }
                exit();

            case 'deleteFinancing':
                if (!$canDelete) {
                    echo json_encode(['success' => false, 'message' => 'Access denied. Only Admin can delete.']);
                    exit();
                }

                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $financingId = isset($_POST['financing_id']) ? trim($_POST['financing_id']) : '';
                if (empty($financingId)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid financing ID']);
                    exit();
                }

                $conn = getDBConnection();

                // Check for linked payments
                $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM payments WHERE linked_financing_id = ?");
                $stmt->bind_param("s", $financingId);
                $stmt->execute();
                $cnt = $stmt->get_result()->fetch_assoc()['cnt'];
                $stmt->close();

                if ($cnt > 0) {
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => "Cannot delete — financing has $cnt linked payment(s)"]);
                    exit();
                }

                // Get info for logging + sync
                $stmt = $conn->prepare("SELECT counterpart_type, counterparty_id, counterpart_name, amount, direction FROM financing WHERE financing_id = ?");
                $stmt->bind_param("s", $financingId);
                $stmt->execute();
                $info = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                $stmt = $conn->prepare("DELETE FROM financing WHERE financing_id = ?");
                $stmt->bind_param("s", $financingId);

                if ($stmt->execute()) {
                    logActivity($user_id, $username, 'Financing Deleted', "Deleted financing: $financingId (Counterparty: {$info['counterpart_name']}, Amount: {$info['amount']}, Direction: {$info['direction']})");
                    $stmt->close();
                    if ($info['counterpart_type'] === 'Supplier') reconcileSupplierAccount($conn, $info['counterparty_id']);
                    $conn->close();
                    echo json_encode(['success' => true, 'message' => 'Financing record deleted successfully']);
                } else {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to delete financing record']);
                }
                exit();

            case 'getLinkedPayments':
                $financingId = isset($_GET['financing_id']) ? trim($_GET['financing_id']) : '';
                if (empty($financingId)) {
                    echo json_encode(['success' => false, 'message' => 'Financing ID is required']);
                    exit();
                }

                $conn = getDBConnection();

                // get financing record
                $stmt = $conn->prepare("SELECT financing_id, date, direction, counterpart_type, counterpart_name, amount, carried_over_balance, amount_repaid, balance_due FROM financing WHERE financing_id = ?");
                $stmt->bind_param("s", $financingId);
                $stmt->execute();
                $fRes = $stmt->get_result();
                $fin = $fRes->num_rows > 0 ? $fRes->fetch_assoc() : null;
                $stmt->close();

                // get payments sorted chronologically
                $stmt = $conn->prepare("SELECT payment_id, date, direction, amount, payment_mode, reference_number, notes FROM payments WHERE linked_financing_id = ? ORDER BY date ASC, payment_id ASC");
                $stmt->bind_param("s", $financingId);
                $stmt->execute();
                $result = $stmt->get_result();

                $payments = [];
                while ($row = $result->fetch_assoc()) {
                    $payments[] = $row;
                }
                $stmt->close();

                $ci = getCompanyInfo();
                $conn->close();

                // build running balance transactions
                $transactions = [];
                $runningBal = 0;

                if ($fin) {
                    $finAmt = floatval($fin['amount']);
                    $carriedOver = floatval($fin['carried_over_balance']);
                    $initialAmt = $finAmt + $carriedOver;
                    $runningBal = $initialAmt;

                    // initial financing row
                    $desc = 'Initial financing';
                    if ($carriedOver > 0) $desc .= ' (incl. ' . number_format($carriedOver, 0) . ' carried over)';
                    $transactions[] = [
                        'date' => $fin['date'],
                        'description' => $desc,
                        'debit' => $initialAmt,
                        'credit' => 0,
                        'balance' => $runningBal
                    ];
                }

                // each payment
                foreach ($payments as $p) {
                    $amt = floatval($p['amount']);
                    $pDesc = $p['payment_id'] . ' (' . ($p['payment_mode'] ?? '') . ')';

                    if ($p['direction'] === 'Incoming') {
                        // repayment reduces balance
                        $runningBal -= $amt;
                        $transactions[] = [
                            'date' => $p['date'],
                            'description' => $pDesc,
                            'debit' => 0,
                            'credit' => $amt,
                            'balance' => $runningBal
                        ];
                    } else {
                        // additional disbursement
                        $runningBal += $amt;
                        $transactions[] = [
                            'date' => $p['date'],
                            'description' => $pDesc,
                            'debit' => $amt,
                            'credit' => 0,
                            'balance' => $runningBal
                        ];
                    }
                }

                // format payments for backward compat
                $formattedPayments = [];
                foreach ($payments as $p) {
                    $formattedPayments[] = [
                        'payment_id' => $p['payment_id'],
                        'date' => date('M d, Y', strtotime($p['date'])),
                        'direction' => $p['direction'],
                        'amount' => $p['amount'],
                        'payment_mode' => $p['payment_mode'],
                        'reference_number' => $p['reference_number'],
                        'notes' => $p['notes']
                    ];
                }

                echo json_encode([
                    'success' => true,
                    'data' => $formattedPayments,
                    'transactions' => $transactions,
                    'currency' => $ci['currency_symbol'] ?? 'FCFA',
                    'financing' => $fin
                ]);
                exit();

            case 'getFinancingReceipt':
                $fId = isset($_GET['financing_id']) ? trim($_GET['financing_id']) : '';
                if (empty($fId)) { echo json_encode(['success' => false, 'message' => 'Financing ID required']); exit(); }

                $conn = getDBConnection();
                $stmt = $conn->prepare("SELECT * FROM financing WHERE financing_id = ?");
                $stmt->bind_param("s", $fId);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($res->num_rows === 0) { $stmt->close(); $conn->close(); echo json_encode(['success' => false, 'message' => 'Record not found']); exit(); }
                $fin = $res->fetch_assoc();
                $stmt->close();

                // linked payments
                $stmt = $conn->prepare("SELECT payment_id, date, amount, payment_mode, reference_number FROM payments WHERE linked_financing_id = ? ORDER BY date ASC");
                $stmt->bind_param("s", $fId);
                $stmt->execute();
                $pRes = $stmt->get_result();
                $payments = [];
                while ($r = $pRes->fetch_assoc()) $payments[] = $r;
                $stmt->close();

                $ci = getCompanyInfo();
                $conn->close();

                echo json_encode(['success' => true, 'data' => ['financing' => $fin, 'payments' => $payments, 'companyInfo' => $ci]]);
                exit();

            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
                exit();
        }
    } catch (Exception $e) {
        error_log("financing.php error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Server error occurred']);
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
    <title>Financing - Dashboard</title>

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

        <div class="main-content">
            <div class="header">
                <h1><i class="fas fa-money-bill-transfer"></i> Financing</h1>
                <div>Welcome, <?php echo htmlspecialchars($username); ?></div>
            </div>

            <!-- Tab Navigation -->
            <div class="pricing-tabs">
                <button class="pricing-tab-btn active" id="supplierTabBtn" onclick="switchFinancingTab('supplier')">
                    <i class="fas fa-truck-field"></i> Supplier Financing
                </button>
                <?php if (!$supplierOnly): ?>
                <button class="pricing-tab-btn" id="customerTabBtn" onclick="switchFinancingTab('customer')">
                    <i class="fas fa-handshake"></i> Customer Financing
                </button>
                <button class="pricing-tab-btn" id="bankTabBtn" onclick="switchFinancingTab('bank')">
                    <i class="fas fa-building-columns"></i> Bank Financing
                </button>
                <?php endif; ?>
            </div>

            <!-- ==================== TAB 1: SUPPLIER FINANCING ==================== -->
            <div class="pricing-tab-content active" id="supplierTab">
                <div class="data-section">
                    <div class="section-header">
                        <h2><i class="fas fa-truck-field"></i> Supplier Financing Records</h2>
                        <div class="section-header-actions">
                            <button class="btn btn-primary" onclick="loadFinancing('supplier')">
                                <i class="fas fa-sync"></i> Refresh
                            </button>
                            <?php if ($canCreate): ?>
                            <button class="btn btn-success" onclick="openAddModal('Supplier')">
                                <i class="fas fa-plus"></i> Add Financing
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="filters-section" id="supFiltersSection" style="display: none;">
                        <div class="filters-header">
                            <h3><i class="fas fa-filter"></i> Filters</h3>
                            <button class="btn btn-secondary btn-sm" onclick="clearFilters('supplier')">
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
                                <label><i class="fas fa-arrow-right-arrow-left"></i> Direction</label>
                                <select id="supFilterDirection" class="filter-input">
                                    <option value="">All</option>
                                    <option value="Incoming">Incoming</option>
                                    <option value="Outgoing">Outgoing</option>
                                </select>
                            </div>
                            <div class="filter-group">
                                <label><i class="fas fa-info-circle"></i> Status</label>
                                <select id="supFilterStatus" class="filter-input">
                                    <option value="">All</option>
                                    <option value="Active">Active</option>
                                    <option value="Settled">Settled</option>
                                    <option value="Overdue">Overdue</option>
                                    <option value="Defaulted">Defaulted</option>
                                </select>
                            </div>
                            <div class="filter-group">
                                <label><i class="fas fa-leaf"></i> Season</label>
                                <select id="supFilterSeason" class="filter-input">
                                    <option value="">All Seasons</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div id="supSkeletonLoader">
                        <div class="skeleton-table">
                            <div class="skeleton-table-row"><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div></div>
                            <div class="skeleton-table-row"><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div></div>
                            <div class="skeleton-table-row"><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div></div>
                            <div class="skeleton-table-row"><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div></div>
                        </div>
                    </div>

                    <div id="supTableContainer" style="display: none;">
                        <div class="table-scroll-hint">
                            <i class="fas fa-arrows-alt-h"></i> Swipe left/right to see all columns
                        </div>
                        <div style="overflow-x: auto; -webkit-overflow-scrolling: touch;">
                            <table id="supFinancingTable" class="display" style="width:100%"></table>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (!$supplierOnly): ?>
            <!-- ==================== TAB 2: CUSTOMER FINANCING ==================== -->
            <div class="pricing-tab-content" id="customerTab">
                <div class="data-section">
                    <div class="section-header">
                        <h2><i class="fas fa-handshake"></i> Customer Financing Records</h2>
                        <div class="section-header-actions">
                            <button class="btn btn-primary" onclick="loadFinancing('customer')">
                                <i class="fas fa-sync"></i> Refresh
                            </button>
                            <?php if ($canCreate): ?>
                            <button class="btn btn-success" onclick="openAddModal('Customer')">
                                <i class="fas fa-plus"></i> Add Financing
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="filters-section" id="custFiltersSection" style="display: none;">
                        <div class="filters-header">
                            <h3><i class="fas fa-filter"></i> Filters</h3>
                            <button class="btn btn-secondary btn-sm" onclick="clearFilters('customer')">
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
                                <label><i class="fas fa-arrow-right-arrow-left"></i> Direction</label>
                                <select id="custFilterDirection" class="filter-input">
                                    <option value="">All</option>
                                    <option value="Incoming">Incoming</option>
                                    <option value="Outgoing">Outgoing</option>
                                </select>
                            </div>
                            <div class="filter-group">
                                <label><i class="fas fa-info-circle"></i> Status</label>
                                <select id="custFilterStatus" class="filter-input">
                                    <option value="">All</option>
                                    <option value="Active">Active</option>
                                    <option value="Settled">Settled</option>
                                    <option value="Overdue">Overdue</option>
                                    <option value="Defaulted">Defaulted</option>
                                </select>
                            </div>
                            <div class="filter-group">
                                <label><i class="fas fa-leaf"></i> Season</label>
                                <select id="custFilterSeason" class="filter-input">
                                    <option value="">All Seasons</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div id="custSkeletonLoader" style="display: none;">
                        <div class="skeleton-table">
                            <div class="skeleton-table-row"><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div></div>
                            <div class="skeleton-table-row"><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div></div>
                            <div class="skeleton-table-row"><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div></div>
                            <div class="skeleton-table-row"><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div></div>
                        </div>
                    </div>

                    <div id="custTableContainer" style="display: none;">
                        <div class="table-scroll-hint">
                            <i class="fas fa-arrows-alt-h"></i> Swipe left/right to see all columns
                        </div>
                        <div style="overflow-x: auto; -webkit-overflow-scrolling: touch;">
                            <table id="custFinancingTable" class="display" style="width:100%"></table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ==================== TAB 3: BANK FINANCING ==================== -->
            <div class="pricing-tab-content" id="bankTab">
                <div class="data-section">
                    <div class="section-header">
                        <h2><i class="fas fa-building-columns"></i> Bank Financing Records</h2>
                        <div class="section-header-actions">
                            <button class="btn btn-primary" onclick="loadFinancing('bank')">
                                <i class="fas fa-sync"></i> Refresh
                            </button>
                            <?php if ($canCreate): ?>
                            <button class="btn btn-success" onclick="openAddModal('Bank')">
                                <i class="fas fa-plus"></i> Add Financing
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="filters-section" id="bankFiltersSection" style="display: none;">
                        <div class="filters-header">
                            <h3><i class="fas fa-filter"></i> Filters</h3>
                            <button class="btn btn-secondary btn-sm" onclick="clearFilters('bank')">
                                <i class="fas fa-times-circle"></i> Clear All
                            </button>
                        </div>
                        <div class="filters-grid">
                            <div class="filter-group">
                                <label><i class="fas fa-calendar-alt"></i> Date From</label>
                                <input type="date" id="bankFilterDateFrom" class="filter-input">
                            </div>
                            <div class="filter-group">
                                <label><i class="fas fa-calendar-alt"></i> Date To</label>
                                <input type="date" id="bankFilterDateTo" class="filter-input">
                            </div>
                            <div class="filter-group">
                                <label><i class="fas fa-arrow-right-arrow-left"></i> Direction</label>
                                <select id="bankFilterDirection" class="filter-input">
                                    <option value="">All</option>
                                    <option value="Incoming">Incoming</option>
                                    <option value="Outgoing">Outgoing</option>
                                </select>
                            </div>
                            <div class="filter-group">
                                <label><i class="fas fa-info-circle"></i> Status</label>
                                <select id="bankFilterStatus" class="filter-input">
                                    <option value="">All</option>
                                    <option value="Active">Active</option>
                                    <option value="Settled">Settled</option>
                                    <option value="Overdue">Overdue</option>
                                    <option value="Defaulted">Defaulted</option>
                                </select>
                            </div>
                            <div class="filter-group">
                                <label><i class="fas fa-leaf"></i> Season</label>
                                <select id="bankFilterSeason" class="filter-input">
                                    <option value="">All Seasons</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div id="bankSkeletonLoader" style="display: none;">
                        <div class="skeleton-table">
                            <div class="skeleton-table-row"><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div></div>
                            <div class="skeleton-table-row"><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div></div>
                            <div class="skeleton-table-row"><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div></div>
                            <div class="skeleton-table-row"><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div></div>
                        </div>
                    </div>

                    <div id="bankTableContainer" style="display: none;">
                        <div class="table-scroll-hint">
                            <i class="fas fa-arrows-alt-h"></i> Swipe left/right to see all columns
                        </div>
                        <div style="overflow-x: auto; -webkit-overflow-scrolling: touch;">
                            <table id="bankFinancingTable" class="display" style="width:100%"></table>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

        </div>
    </div>

    <!-- ==================== ADD/EDIT MODAL ==================== -->
    <?php if ($canCreate || $canUpdate): ?>
    <div class="modal-overlay" id="financingModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 id="modalTitle"><i class="fas fa-money-bill-transfer"></i> Add Financing</h3>
                <button class="close-btn" onclick="closeModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <div id="financingIdInfo" class="form-id-info" style="display: none;">
                    <strong><i class="fas fa-id-badge"></i> Financing ID:</strong> <span id="financingIdDisplay"></span>
                </div>

                <form id="financingForm">
                    <input type="hidden" id="financingId" name="financing_id">

                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-calendar-day"></i> Date *</label>
                            <input type="date" id="financingDate" name="date" required>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-arrow-right-arrow-left"></i> Direction *</label>
                            <div class="radio-group">
                                <label class="radio-label">
                                    <input type="radio" name="direction" value="Incoming" required> Incoming
                                </label>
                                <label class="radio-label">
                                    <input type="radio" name="direction" value="Outgoing"> Outgoing
                                </label>
                            </div>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-users"></i> Counterpart Type *</label>
                            <div class="radio-group">
                                <label class="radio-label">
                                    <input type="radio" name="counterpart_type" value="Customer" required onchange="switchCounterpartySource('Customer')"> Customer
                                </label>
                                <label class="radio-label">
                                    <input type="radio" name="counterpart_type" value="Supplier" onchange="switchCounterpartySource('Supplier')"> Supplier
                                </label>
                                <label class="radio-label">
                                    <input type="radio" name="counterpart_type" value="Bank" onchange="switchCounterpartySource('Bank')"> Bank
                                </label>
                            </div>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-handshake"></i> Counterparty *</label>
                            <div class="searchable-dropdown" id="counterpartyDropdownWrapper">
                                <input type="text" class="searchable-dropdown-input" id="counterpartySearch" placeholder="Select counterpart type first..." autocomplete="off" disabled>
                                <input type="hidden" id="counterpartyId" name="counterparty_id" required>
                                <span class="searchable-dropdown-arrow" id="counterpartyArrow"><i class="fas fa-chevron-down"></i></span>
                                <div class="searchable-dropdown-list" id="counterpartyList" style="display:none;"></div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-money-bill-wave"></i> Carried Over Balance</label>
                            <input type="text" inputmode="decimal" id="carriedOverBalance" name="carried_over_balance" class="money-input" value="0">
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-dollar-sign"></i> Amount *</label>
                            <input type="text" inputmode="decimal" id="amount" name="amount" class="money-input" required placeholder="0.00" oninput="calcExpectedVolume()">
                        </div>

                        <div class="form-group" id="currentMarketPriceGroup">
                            <label><i class="fas fa-chart-line"></i> Current Market Price</label>
                            <input type="number" id="currentMarketPrice" name="current_market_price" step="0.01" min="0" placeholder="0.00" oninput="calcExpectedVolume()">
                        </div>

                        <div class="form-group" id="expectedVolumeKgGroup">
                            <label><i class="fas fa-weight-hanging"></i> Expected Volume (kg)</label>
                            <input type="number" id="expectedVolumeKg" name="expected_volume_kg" step="0.01" min="0" placeholder="Auto: Amount ÷ Price">
                        </div>

                        <div class="form-group" id="interestPerKgGroup">
                            <label><i class="fas fa-coins"></i> Interest per Kg (FCFA)</label>
                            <input type="number" id="interestPerKg" name="interest_per_kg" step="0.01" min="0" value="0" placeholder="e.g. 5">
                        </div>

                        <div class="form-group" id="interestAmountGroup" style="display: none;">
                            <label><i class="fas fa-calculator"></i> Interest Amount</label>
                            <div class="computed-field" id="interestAmountDisplay">0</div>
                        </div>
                    </div>

                    <!-- Bank-specific fields -->
                    <div id="bankFieldsGroup" style="display:none;">
                        <div class="form-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                            <div class="form-group">
                                <label><i class="fas fa-percentage"></i> Interest Rate (%)</label>
                                <input type="number" id="interestRatePct" name="interest_rate_pct" step="0.01" min="0" placeholder="e.g. 8.5">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-calendar-check"></i> Monthly Payment</label>
                                <input type="number" id="monthlyPayment" name="monthly_payment" step="0.01" min="0" placeholder="0.00">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-clock"></i> Term (months)</label>
                                <input type="number" id="termMonths" name="term_months" min="1" max="360" placeholder="e.g. 12">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-calendar-plus"></i> Start Date</label>
                                <input type="date" id="startDate" name="start_date">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-calendar-xmark"></i> Maturity Date</label>
                                <input type="date" id="maturityDate" name="maturity_date">
                            </div>
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-info-circle"></i> Status</label>
                            <select id="financingStatus" name="status">
                                <option value="Active">Active</option>
                                <option value="Settled">Settled</option>
                                <option value="Overdue">Overdue</option>
                                <option value="Defaulted">Defaulted</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-hashtag"></i> Reference Number</label>
                            <input type="text" id="referenceNumber" name="reference_number" placeholder="e.g. REF-001" maxlength="50">
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-leaf"></i> Season *</label>
                            <?php echo renderSeasonDropdown('season', 'season'); ?>
                        </div>

                        <div class="form-group" id="computedFieldsGroup" style="display: none;">
                            <label><i class="fas fa-box-open"></i> Volume Remaining (kg)</label>
                            <div class="computed-field" id="volumeRemainingDisplay">0</div>
                        </div>

                        <div class="form-group" id="balanceDueGroup" style="display: none;">
                            <label><i class="fas fa-calculator"></i> Balance Due</label>
                            <div class="computed-field" id="balanceDueDisplay">0</div>
                        </div>

                        <div class="form-group" id="deliveredVolumeGroup" style="display: none;">
                            <label><i class="fas fa-truck-loading"></i> Delivered Volume (kg)</label>
                            <div class="computed-field" id="deliveredVolumeDisplay">0</div>
                        </div>

                        <div class="form-group" id="amountRepaidGroup" style="display: none;">
                            <label><i class="fas fa-hand-holding-dollar"></i> Amount Repaid</label>
                            <div class="computed-field" id="amountRepaidDisplay">0</div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-sticky-note"></i> Notes</label>
                        <textarea id="notes" name="notes" rows="2" placeholder="Optional notes..."></textarea>
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

    <!-- ==================== LINKED PAYMENTS MODAL ==================== -->
    <div class="modal-overlay" id="paymentsModal">
        <div class="modal" onclick="event.stopPropagation()" style="max-width: 800px;">
            <div class="modal-header">
                <h3 id="paymentsModalTitle"><i class="fas fa-money-check"></i> Linked Payments</h3>
                <button class="close-btn" onclick="closePaymentsModal()"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <div id="paymentsLoading" style="text-align:center; padding:20px;">
                    <i class="fas fa-spinner fa-spin fa-2x"></i>
                </div>
                <div id="paymentsContent" style="display:none;">
                    <div id="paymentsEmpty" style="display:none; text-align:center; padding:30px; color:var(--text-muted);">
                        <i class="fas fa-receipt" style="font-size:48px; margin-bottom:10px;"></i>
                        <p>No payments linked to this financing record</p>
                    </div>
                    <!-- running balance -->
                    <div id="runningBalanceSection" style="display:none;">
                        <div id="runningBalanceSummary"></div>
                        <div style="overflow-x:auto;-webkit-overflow-scrolling:touch;">
                            <div id="runningBalanceTable"></div>
                        </div>
                    </div>
                    <!-- original datatable kept below -->
                    <div id="linkedPaymentsTableWrap" style="display:none; margin-top:18px;">
                        <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;">
                            <span style="font-weight:600;font-size:14px;color:var(--text-primary);"><i class="fas fa-list" style="margin-right:6px;color:var(--navy-accent);"></i>Payment Details</span>
                        </div>
                        <div style="overflow-x:auto;-webkit-overflow-scrolling:touch;">
                            <table id="linkedPaymentsTable" class="display" style="width:100%"></table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    // ==================== GLOBAL VARIABLES ====================
    var canUpdate = <?php echo $canUpdate ? 'true' : 'false'; ?>;
    var canDelete = <?php echo $canDelete ? 'true' : 'false'; ?>;
    var canCreate = <?php echo $canCreate ? 'true' : 'false'; ?>;
    var supplierOnly = <?php echo $supplierOnly ? 'true' : 'false'; ?>;
    var ACTIVE_SEASON = '<?php echo addslashes(getActiveSeason()); ?>';
    var isEditMode = false;
    var activeFinancingTab = 'supplier';

    // Per-tab DataTable instances and data
    var supFinancingTable = null, custFinancingTable = null, bankFinancingTable = null;
    var supFinancingData = [], custFinancingData = [], bankFinancingData = [];
    var supDataLoaded = false, custDataLoaded = false, bankDataLoaded = false;

    // Counterparty dropdown data
    var customersList = [];
    var suppliersList = [];
    var banksList = [];
    var activeCounterpartyList = [];
    var isBankMode = false; // legacy flag — banks now use the standard dropdown

    var directionBadgeMap = {
        'Incoming': 'status-delivered',
        'Outgoing': 'status-in-transit'
    };
    var statusBadgeMap = {
        'Active': 'status-active',
        'Settled': 'status-accepted',
        'Overdue': 'status-rejected',
        'Defaulted': 'status-rejected'
    };

    // ==================== TAB SWITCHING ====================
    function switchFinancingTab(tab) {
        // restrict to supplier tab only
        if (supplierOnly && tab !== 'supplier') return;
        activeFinancingTab = tab;

        document.getElementById('supplierTabBtn').classList.toggle('active', tab === 'supplier');
        if (!supplierOnly) {
            document.getElementById('customerTabBtn').classList.toggle('active', tab === 'customer');
            document.getElementById('bankTabBtn').classList.toggle('active', tab === 'bank');
            document.getElementById('customerTab').classList.toggle('active', tab === 'customer');
            document.getElementById('bankTab').classList.toggle('active', tab === 'bank');
        }

        document.getElementById('supplierTab').classList.toggle('active', tab === 'supplier');

        // Load data if not loaded yet
        if (tab === 'supplier' && !supDataLoaded) loadFinancing('supplier');
        if (!supplierOnly && tab === 'customer' && !custDataLoaded) loadFinancing('customer');
        if (!supplierOnly && tab === 'bank' && !bankDataLoaded) loadFinancing('bank');

        // Adjust DataTable columns
        var tableRef = (tab === 'supplier') ? supFinancingTable :
                       (tab === 'customer') ? custFinancingTable : bankFinancingTable;
        if (tableRef) {
            setTimeout(function() { tableRef.columns.adjust().responsive.recalc(); }, 100);
        }
    }

    // ==================== DATA LOADING ====================
    $(document).ready(function() {
        loadDropdowns();
        loadFinancing('supplier');
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
                    suppliersList = response.data.suppliers.map(function(s) {
                        return { id: s.supplier_id, name: s.first_name };
                    });
                    banksList = (response.data.banks || []).map(function(b) {
                        return { id: b.bank_id, name: b.bank_name };
                    });
                    initCounterpartyDropdown();
                }
            }
        });
    }

    function loadFinancing(type) {
        type = type || activeFinancingTab;
        var prefix = (type === 'supplier') ? 'sup' : (type === 'customer') ? 'cust' : 'bank';

        $('#' + prefix + 'SkeletonLoader').show();
        $('#' + prefix + 'TableContainer').hide();

        $.ajax({
            url: '?action=getFinancing',
            method: 'GET',
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    var typeMap = { 'supplier': 'Supplier', 'customer': 'Customer', 'bank': 'Bank' };
                    var filtered = response.data.filter(function(r) {
                        return r.counterpart_type === typeMap[type];
                    });

                    if (type === 'supplier') { supFinancingData = filtered; supDataLoaded = true; }
                    if (type === 'customer') { custFinancingData = filtered; custDataLoaded = true; }
                    if (type === 'bank') { bankFinancingData = filtered; bankDataLoaded = true; }

                    $('#' + prefix + 'FiltersSection').show();
                    populateSeasonFilter(filtered, type);
                    initializeDataTable(filtered, type);
                } else {
                    $('#' + prefix + 'SkeletonLoader').hide();
                    Swal.fire({ icon: 'error', title: 'Error', text: response.message || 'Failed to load financing records' });
                }
            },
            error: function() {
                $('#' + prefix + 'SkeletonLoader').hide();
                Swal.fire({ icon: 'error', title: 'Connection Error', text: 'Could not connect to server.' });
            }
        });
    }

    // ==================== DATATABLE ====================
    function initializeDataTable(data, type) {
        var prefix = (type === 'supplier') ? 'sup' : (type === 'customer') ? 'cust' : 'bank';
        var tableId = prefix + 'FinancingTable';

        // Destroy existing instance
        var existing = (type === 'supplier') ? supFinancingTable :
                       (type === 'customer') ? custFinancingTable : bankFinancingTable;
        if (existing) { existing.destroy(); $('#' + tableId).empty(); }

        // per-tab counterparty column label
        var counterpartyTitle = (type === 'bank') ? 'Bank Name' :
                                (type === 'customer') ? 'Customer Name' : 'Supplier Name';

        var columns = [
            { data: 'financing_id', title: 'ID' },
            { data: 'date', title: 'Date' },
            {
                data: 'direction', title: 'Direction',
                render: function(data) {
                    var cls = directionBadgeMap[data] || 'status-pending';
                    return '<span class="status-badge ' + cls + '">' + (data || '') + '</span>';
                }
            },
            {
                data: 'counterpart_name', title: counterpartyTitle,
                render: function(data, t, row) {
                    var name = data || '';
                    var init = name ? name.charAt(0).toUpperCase() : '?';
                    var palette = ['#0074D9','#1a9c6b','#e67e22','#9b59b6','#e74c3c','#16a085','#2c3e50'];
                    // deterministic color pick from name
                    var hash = 0; for (var i = 0; i < name.length; i++) hash = (hash + name.charCodeAt(i)) % palette.length;
                    var bg = palette[hash];
                    var avatar = '<div style="width:32px;height:32px;border-radius:50%;background:' + bg + ';color:#fff;display:inline-flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;flex-shrink:0;">' + init + '</div>';
                    var body = '<div style="font-weight:600;color:var(--navy-primary);line-height:1.2;">' + name + '</div>' +
                               (row.counterparty_id ? '<div style="font-size:11px;color:var(--text-muted);">' + row.counterparty_id + '</div>' : '');
                    return '<div style="display:flex;align-items:center;gap:10px;">' + avatar + '<div>' + body + '</div></div>';
                }
            },
            {
                data: 'amount', title: 'Amount (F)',
                render: function(data, t, row) {
                    var val = parseFloat(data) || 0;
                    var color = (row.direction === 'Outgoing') ? '#0074D9' : '#1a9c6b';
                    var icon = (row.direction === 'Outgoing') ? 'fa-arrow-right' : 'fa-arrow-left';
                    return '<div style="display:flex;align-items:center;gap:6px;justify-content:flex-end;"><i class="fas ' + icon + '" style="color:' + color + ';font-size:10px;"></i><span style="font-weight:700;color:' + color + ';">' + val.toLocaleString(undefined,{maximumFractionDigits: 0}) + '</span></div>';
                }
            }
        ];

        // Customer tab: add interest columns
        if (type === 'customer') {
            columns.push({
                data: 'interest_per_kg', title: 'Interest/Kg',
                render: function(data) { return data ? Math.round(parseFloat(data)).toLocaleString() + ' F' : '0'; }
            });
            columns.push({
                data: 'interest_amount', title: 'Interest Amt',
                render: function(data) {
                    return data ? parseFloat(data).toLocaleString(undefined, {maximumFractionDigits: 0}) : '0';
                }
            });
        }

        // Bank tab: add bank-specific columns
        if (type === 'bank') {
            columns.push({
                data: 'interest_rate_pct', title: 'Rate (%)',
                render: function(data) { return data ? parseFloat(data).toFixed(2) + '%' : '-'; }
            });
            columns.push({
                data: 'monthly_payment', title: 'Monthly Pmt',
                render: function(data) {
                    return data ? parseFloat(data).toLocaleString(undefined, {maximumFractionDigits: 0}) : '-';
                }
            });
            columns.push({
                data: 'term_months', title: 'Term (mo)',
                render: function(data) { return data ? data : '-'; }
            });
            columns.push({
                data: 'start_date', title: 'Start Date',
                render: function(data) { return data || '-'; }
            });
            columns.push({
                data: 'maturity_date', title: 'Maturity Date',
                render: function(data) { return data || '-'; }
            });
        }

        columns.push(
            {
                data: 'balance_due', title: 'Balance Due (F)',
                render: function(data, t, row) {
                    var val = parseFloat(data) || 0;
                    var amt = parseFloat(row.amount) || 0;
                    var repaid = parseFloat(row.amount_repaid) || 0;
                    var total = amt + (parseFloat(row.carried_over_balance) || 0) + (parseFloat(row.interest_amount) || 0);
                    var pct = total > 0 ? Math.max(0, Math.min(100, ((total - val) / total) * 100)) : 0;
                    var color = val <= 0.01 ? '#1a9c6b' : (pct >= 50 ? '#f39c12' : '#e74c3c');
                    var label = val <= 0.01
                        ? '<span style="display:inline-flex;align-items:center;gap:4px;color:#1a9c6b;font-weight:700;"><i class="fas fa-check-circle"></i> Settled</span>'
                        : '<span style="font-weight:700;color:' + color + ';">' + val.toLocaleString(undefined,{maximumFractionDigits: 0}) + '</span>';
                    var bar = '<div style="height:5px;background:#eef0f3;border-radius:4px;margin-top:4px;overflow:hidden;"><div style="height:100%;width:' + pct.toFixed(0) + '%;background:' + color + ';border-radius:4px;transition:width .3s;"></div></div>';
                    return '<div style="min-width:120px;">' + label + bar + '<div style="font-size:10px;color:var(--text-muted);margin-top:2px;">' + pct.toFixed(0) + '% repaid</div></div>';
                }
            },
            {
                data: 'status', title: 'Status',
                render: function(data) {
                    var cls = statusBadgeMap[data] || 'status-active';
                    return '<span class="status-badge ' + cls + '">' + (data || 'Active') + '</span>';
                }
            },
            { data: 'season', title: 'Season' }
        );

        // Actions column
        if (canUpdate || canDelete || true) {
            columns.push({
                data: null, title: 'Actions', orderable: false,
                render: function(data, t, row) {
                    var html = '<button class="action-icon" onclick="printFinancingReceipt(\'' + row.financing_id + '\')" title="Print Contract" style="color:#1a5c2a;"><i class="fas fa-print"></i></button> ';
                    // payments-to-financing only meaningful for bank loans — suppliers settle via deliveries, customer advances via sales drawdown
                    if (type === 'bank') {
                        html += '<button class="action-icon" onclick="viewLinkedPayments(\'' + row.financing_id + '\')" title="View Payments" style="color:var(--navy-accent);"><i class="fas fa-receipt"></i></button> ';
                    }
                    if (canUpdate) {
                        html += '<button class="action-icon edit-icon" onclick=\'editFinancing(' + JSON.stringify(row).replace(/'/g, "\\'") + ')\' title="Edit"><i class="fas fa-edit"></i></button> ';
                    }
                    if (canDelete) {
                        html += '<button class="action-icon delete-icon" onclick="deleteFinancing(\'' + row.financing_id + '\')" title="Delete"><i class="fas fa-trash"></i></button>';
                    }
                    return html;
                }
            });
        }

        setTimeout(function() {
            var dt = $('#' + tableId).DataTable({
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

            if (type === 'supplier') supFinancingTable = dt;
            if (type === 'customer') custFinancingTable = dt;
            if (type === 'bank') bankFinancingTable = dt;

            $('#' + prefix + 'SkeletonLoader').hide();
            $('#' + prefix + 'TableContainer').show();

            // Bind filter events
            var filterIds = ['DateFrom', 'DateTo', 'Direction', 'Status', 'Season'];
            filterIds.forEach(function(f) {
                $('#' + prefix + 'Filter' + f).off('change').on('change', function() {
                    applyFilters(type);
                });
            });
        }, 100);
    }

    // ==================== FILTERS ====================
    function populateSeasonFilter(data, type) {
        var prefix = (type === 'supplier') ? 'sup' : (type === 'customer') ? 'cust' : 'bank';
        var seasons = [...new Set(data.map(function(d) { return d.season; }).filter(Boolean))];
        var select = document.getElementById(prefix + 'FilterSeason');
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

    function applyFilters(type) {
        var prefix = (type === 'supplier') ? 'sup' : (type === 'customer') ? 'cust' : 'bank';
        var table = (type === 'supplier') ? supFinancingTable :
                    (type === 'customer') ? custFinancingTable : bankFinancingTable;
        var dataArr = (type === 'supplier') ? supFinancingData :
                      (type === 'customer') ? custFinancingData : bankFinancingData;
        if (!table) return;

        $.fn.dataTable.ext.search = [];

        var dateFrom = document.getElementById(prefix + 'FilterDateFrom').value;
        var dateTo = document.getElementById(prefix + 'FilterDateTo').value;
        var direction = document.getElementById(prefix + 'FilterDirection').value;
        var status = document.getElementById(prefix + 'FilterStatus').value;
        var season = document.getElementById(prefix + 'FilterSeason').value;

        if (dateFrom || dateTo) {
            $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
                var rawDate = dataArr[dataIndex]?.date_raw;
                if (!rawDate) return true;
                var recordDate = new Date(rawDate);
                var fromDate = dateFrom ? new Date(dateFrom) : null;
                var toDate = dateTo ? new Date(dateTo + 'T23:59:59') : null;
                if (fromDate && recordDate < fromDate) return false;
                if (toDate && recordDate > toDate) return false;
                return true;
            });
        }

        if (direction) {
            $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
                return dataArr[dataIndex]?.direction === direction;
            });
        }

        if (status) {
            $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
                return dataArr[dataIndex]?.status === status;
            });
        }

        if (season) {
            $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
                return dataArr[dataIndex]?.season === season;
            });
        }

        table.draw();
    }

    function clearFilters(type) {
        var prefix = (type === 'supplier') ? 'sup' : (type === 'customer') ? 'cust' : 'bank';
        document.getElementById(prefix + 'FilterDateFrom').value = '';
        document.getElementById(prefix + 'FilterDateTo').value = '';
        document.getElementById(prefix + 'FilterDirection').value = '';
        document.getElementById(prefix + 'FilterStatus').value = '';
        document.getElementById(prefix + 'FilterSeason').value = '';

        var table = (type === 'supplier') ? supFinancingTable :
                    (type === 'customer') ? custFinancingTable : bankFinancingTable;
        if (table) {
            $.fn.dataTable.ext.search = [];
            table.columns().search('').draw();
        }
    }

    // ==================== COUNTERPARTY DROPDOWN ====================
    function switchCounterpartySource(type) {
        document.getElementById('counterpartyId').value = '';
        document.getElementById('counterpartySearch').value = '';
        document.getElementById('counterpartySearch').disabled = false;
        isBankMode = false; // banks now use the same searchable dropdown as customers/suppliers
        document.getElementById('counterpartyArrow').style.display = '';
        document.getElementById('counterpartySearch').placeholder = 'Search ' + type.toLowerCase() + '...';

        if (type === 'Customer') activeCounterpartyList = customersList;
        else if (type === 'Supplier') activeCounterpartyList = suppliersList;
        else if (type === 'Bank') activeCounterpartyList = banksList;
        else activeCounterpartyList = [];

        renderCounterpartyList('');

        if (type === 'Bank' && banksList.length === 0) {
            // help text — no banks defined yet
            var list = document.getElementById('counterpartyList');
            list.innerHTML = '<div class="searchable-dropdown-item no-results">No banks found. <a href="banks.php" target="_blank">Add a bank</a> first.</div>';
        }

        // Show/hide fields based on counterpart type
        var interestPerKgGroup = document.getElementById('interestPerKgGroup');
        var bankFieldsGroup = document.getElementById('bankFieldsGroup');
        var currentMarketPriceGroup = document.getElementById('currentMarketPriceGroup');
        var expectedVolumeKgGroup = document.getElementById('expectedVolumeKgGroup');

        if (type === 'Supplier') {
            // Supplier: hide interest_per_kg, hide bank fields, hide volume fields
            if (interestPerKgGroup) interestPerKgGroup.style.display = 'none';
            if (bankFieldsGroup) bankFieldsGroup.style.display = 'none';
            if (currentMarketPriceGroup) currentMarketPriceGroup.style.display = 'none';
            if (expectedVolumeKgGroup) expectedVolumeKgGroup.style.display = 'none';
        } else if (type === 'Customer') {
            // Customer: show interest_per_kg, hide bank fields, show volume fields
            if (interestPerKgGroup) interestPerKgGroup.style.display = '';
            if (bankFieldsGroup) bankFieldsGroup.style.display = 'none';
            if (currentMarketPriceGroup) currentMarketPriceGroup.style.display = '';
            if (expectedVolumeKgGroup) expectedVolumeKgGroup.style.display = '';
        } else if (type === 'Bank') {
            // Bank: hide interest_per_kg, show bank fields, hide volume fields
            if (interestPerKgGroup) interestPerKgGroup.style.display = 'none';
            if (bankFieldsGroup) bankFieldsGroup.style.display = 'block';
            if (currentMarketPriceGroup) currentMarketPriceGroup.style.display = 'none';
            if (expectedVolumeKgGroup) expectedVolumeKgGroup.style.display = 'none';
        }
    }

    function initCounterpartyDropdown() {
        var input = document.getElementById('counterpartySearch');
        var hiddenInput = document.getElementById('counterpartyId');
        var list = document.getElementById('counterpartyList');
        var arrow = document.getElementById('counterpartyArrow');

        if (!input) return;

        input.addEventListener('focus', function() {
            renderCounterpartyList(this.value);
            list.style.display = 'block';
            arrow.classList.add('open');
        });

        input.addEventListener('input', function() {
            renderCounterpartyList(this.value);
            list.style.display = 'block';
        });

        document.addEventListener('click', function(e) {
            if (!document.getElementById('counterpartyDropdownWrapper').contains(e.target)) {
                list.style.display = 'none';
                arrow.classList.remove('open');
                var sel = activeCounterpartyList.find(function(c) { return c.id === hiddenInput.value; });
                if (sel) {
                    input.value = sel.id + ' — ' + sel.name;
                } else if (hiddenInput.value === '') {
                    input.value = '';
                }
            }
        });
    }

    function renderCounterpartyList(searchTerm) {
        var list = document.getElementById('counterpartyList');
        var hiddenInput = document.getElementById('counterpartyId');
        list.innerHTML = '';

        var filtered = activeCounterpartyList.filter(function(c) {
            var label = c.id + ' — ' + c.name;
            return label.toLowerCase().includes((searchTerm || '').toLowerCase());
        });

        if (filtered.length === 0) {
            list.innerHTML = '<div class="searchable-dropdown-item no-results">No results found</div>';
            return;
        }

        filtered.forEach(function(c) {
            var item = document.createElement('div');
            item.className = 'searchable-dropdown-item' + (hiddenInput.value === c.id ? ' selected' : '');
            item.textContent = c.id + ' — ' + c.name;
            item.addEventListener('click', function() {
                selectCounterparty(c);
            });
            list.appendChild(item);
        });
    }

    function selectCounterparty(counterparty) {
        document.getElementById('counterpartyId').value = counterparty.id;
        document.getElementById('counterpartySearch').value = counterparty.id + ' — ' + counterparty.name;
        document.getElementById('counterpartyList').style.display = 'none';
        document.getElementById('counterpartyArrow').classList.remove('open');

        // auto-fetch agreed price from pricing master
        var cpType = document.querySelector('input[name="counterpart_type"]:checked');
        if (cpType && (cpType.value === 'Customer' || cpType.value === 'Supplier')) {
            $.getJSON('?action=getCounterpartyPrice&counterparty_id=' + encodeURIComponent(counterparty.id) + '&counterpart_type=' + cpType.value, function(res) {
                if (res.success && res.agreed_price > 0) {
                    document.getElementById('currentMarketPrice').value = res.agreed_price;
                    calcExpectedVolume();
                }
            });
        }
    }

    // auto-calc expected volume = amount / market price
    function calcExpectedVolume() {
        var amtEl = document.getElementById('amount');
        var amt = amtEl ? parseFloat(amtEl.value.replace(/,/g, '')) || 0 : 0;
        var price = parseFloat(document.getElementById('currentMarketPrice').value) || 0;
        var volEl = document.getElementById('expectedVolumeKg');
        if (amt > 0 && price > 0) {
            volEl.value = (amt / price).toFixed(2);
        }
    }

    // ==================== MODAL: ADD / EDIT ====================
    function openAddModal(typeOverride) {
        isEditMode = false;
        document.getElementById('modalTitle').innerHTML = '<i class="fas fa-money-bill-transfer"></i> Add Financing';
        document.getElementById('financingForm').reset();
        document.getElementById('financingId').value = '';
        document.getElementById('financingIdInfo').style.display = 'none';
        document.getElementById('counterpartyId').value = '';
        document.getElementById('counterpartySearch').value = '';
        document.getElementById('counterpartySearch').disabled = true;
        document.getElementById('counterpartySearch').placeholder = 'Select counterpart type first...';
        document.getElementById('counterpartyArrow').style.display = '';
        document.getElementById('season').value = ACTIVE_SEASON;
        document.getElementById('carriedOverBalance').value = '0';
        document.getElementById('interestPerKg').value = '0';
        document.getElementById('interestRatePct').value = '';
        document.getElementById('monthlyPayment').value = '';
        document.getElementById('termMonths').value = '';
        document.getElementById('startDate').value = '';
        document.getElementById('maturityDate').value = '';
        document.getElementById('bankFieldsGroup').style.display = 'none';
        isBankMode = false;
        activeCounterpartyList = [];

        // Hide computed fields in add mode
        document.getElementById('computedFieldsGroup').style.display = 'none';
        document.getElementById('balanceDueGroup').style.display = 'none';
        document.getElementById('deliveredVolumeGroup').style.display = 'none';
        document.getElementById('amountRepaidGroup').style.display = 'none';
        document.getElementById('interestAmountGroup').style.display = 'none';

        var today = new Date().toISOString().split('T')[0];
        document.getElementById('financingDate').value = today;

        // Auto-set counterpart type based on tab or override
        var autoType = typeOverride || (activeFinancingTab === 'supplier' ? 'Supplier' :
                                        activeFinancingTab === 'customer' ? 'Customer' : 'Bank');
        var typeRadios = document.querySelectorAll('input[name="counterpart_type"]');
        typeRadios.forEach(function(r) { r.checked = (r.value === autoType); });

        // Enable counterparty field and set list
        switchCounterpartySource(autoType);

        document.getElementById('financingModal').classList.add('active');
    }

    function editFinancing(row) {
        isEditMode = true;
        document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit"></i> Edit Financing';
        document.getElementById('financingId').value = row.financing_id;
        document.getElementById('financingIdInfo').style.display = 'block';
        document.getElementById('financingIdDisplay').textContent = row.financing_id;
        document.getElementById('financingDate').value = row.date_raw;

        // Set direction radio
        var directionRadios = document.querySelectorAll('input[name="direction"]');
        directionRadios.forEach(function(r) { r.checked = (r.value === row.direction); });

        // Set counterpart type radio
        var typeRadios = document.querySelectorAll('input[name="counterpart_type"]');
        typeRadios.forEach(function(r) { r.checked = (r.value === row.counterpart_type); });

        // Set counterparty (banks now use the standard searchable dropdown like customers/suppliers)
        isBankMode = false;
        document.getElementById('counterpartyArrow').style.display = '';
        document.getElementById('counterpartySearch').disabled = false;
        document.getElementById('counterpartySearch').placeholder = 'Search ' + row.counterpart_type.toLowerCase() + '...';
        if (row.counterpart_type === 'Customer') activeCounterpartyList = customersList;
        else if (row.counterpart_type === 'Supplier') activeCounterpartyList = suppliersList;
        else if (row.counterpart_type === 'Bank') activeCounterpartyList = banksList;
        document.getElementById('counterpartyId').value = row.counterparty_id || '';
        document.getElementById('counterpartySearch').value = row.counterparty_id ? (row.counterparty_id + ' — ' + row.counterpart_name) : '';

        setMoneyVal('carriedOverBalance', row.carried_over_balance || 0);
        setMoneyVal('amount', row.amount);
        document.getElementById('currentMarketPrice').value = row.current_market_price || '';
        document.getElementById('expectedVolumeKg').value = row.expected_volume_kg || '';
        document.getElementById('interestPerKg').value = row.interest_per_kg || 0;
        document.getElementById('financingStatus').value = row.status || 'Active';
        document.getElementById('referenceNumber').value = row.reference_number || '';
        document.getElementById('season').value = row.season || ACTIVE_SEASON;
        document.getElementById('notes').value = row.notes || '';

        // Populate bank-specific fields
        document.getElementById('interestRatePct').value = row.interest_rate_pct || '';
        document.getElementById('monthlyPayment').value = row.monthly_payment || '';
        document.getElementById('termMonths').value = row.term_months || '';
        document.getElementById('startDate').value = row.start_date_raw || '';
        document.getElementById('maturityDate').value = row.maturity_date_raw || '';

        // Show/hide fields based on counterpart type (without resetting counterparty)
        var interestPerKgGroup = document.getElementById('interestPerKgGroup');
        var bankFieldsGroup = document.getElementById('bankFieldsGroup');
        var currentMarketPriceGroup = document.getElementById('currentMarketPriceGroup');
        var expectedVolumeKgGroup = document.getElementById('expectedVolumeKgGroup');

        if (row.counterpart_type === 'Supplier') {
            if (interestPerKgGroup) interestPerKgGroup.style.display = 'none';
            if (bankFieldsGroup) bankFieldsGroup.style.display = 'none';
            if (currentMarketPriceGroup) currentMarketPriceGroup.style.display = 'none';
            if (expectedVolumeKgGroup) expectedVolumeKgGroup.style.display = 'none';
        } else if (row.counterpart_type === 'Customer') {
            if (interestPerKgGroup) interestPerKgGroup.style.display = '';
            if (bankFieldsGroup) bankFieldsGroup.style.display = 'none';
            if (currentMarketPriceGroup) currentMarketPriceGroup.style.display = '';
            if (expectedVolumeKgGroup) expectedVolumeKgGroup.style.display = '';
        } else if (row.counterpart_type === 'Bank') {
            if (interestPerKgGroup) interestPerKgGroup.style.display = 'none';
            if (bankFieldsGroup) bankFieldsGroup.style.display = 'block';
            if (currentMarketPriceGroup) currentMarketPriceGroup.style.display = 'none';
            if (expectedVolumeKgGroup) expectedVolumeKgGroup.style.display = 'none';
        }

        // Show computed fields in edit mode
        document.getElementById('computedFieldsGroup').style.display = 'block';
        document.getElementById('balanceDueGroup').style.display = 'block';
        document.getElementById('deliveredVolumeGroup').style.display = 'block';
        document.getElementById('amountRepaidGroup').style.display = 'block';
        document.getElementById('interestAmountGroup').style.display = 'block';

        document.getElementById('volumeRemainingDisplay').textContent = parseFloat(row.volume_remaining_kg || 0).toLocaleString(undefined, {maximumFractionDigits: 0});
        document.getElementById('balanceDueDisplay').textContent = parseFloat(row.balance_due || 0).toLocaleString(undefined, {maximumFractionDigits: 0});
        document.getElementById('deliveredVolumeDisplay').textContent = parseFloat(row.delivered_volume_kg || 0).toLocaleString(undefined, {maximumFractionDigits: 0});
        document.getElementById('amountRepaidDisplay').textContent = parseFloat(row.amount_repaid || 0).toLocaleString(undefined, {maximumFractionDigits: 0});
        document.getElementById('interestAmountDisplay').textContent = parseFloat(row.interest_amount || 0).toLocaleString(undefined, {maximumFractionDigits: 0});

        document.getElementById('financingModal').classList.add('active');
    }

    function closeModal() {
        document.getElementById('financingModal').classList.remove('active');
        document.getElementById('financingForm').reset();
    }

    // Click outside to close
    document.getElementById('financingModal')?.addEventListener('click', function(e) {
        if (e.target === this) closeModal();
    });

    // Form submission
    document.getElementById('financingForm')?.addEventListener('submit', function(e) {
        e.preventDefault();

        if (!document.getElementById('counterpartyId').value) {
            Swal.fire({ icon: 'warning', title: 'Validation', text: 'Please select a counterparty' });
            return;
        }

        // Validate direction radio
        var directionChecked = document.querySelector('input[name="direction"]:checked');
        if (!directionChecked) {
            Swal.fire({ icon: 'warning', title: 'Validation', text: 'Please select a direction' });
            return;
        }

        // Validate counterpart type radio
        var typeChecked = document.querySelector('input[name="counterpart_type"]:checked');
        if (!typeChecked) {
            Swal.fire({ icon: 'warning', title: 'Validation', text: 'Please select a counterpart type' });
            return;
        }

        var formData = new FormData(this);
        formData.append('interest_rate_pct', document.getElementById('interestRatePct').value);
        formData.append('monthly_payment', document.getElementById('monthlyPayment').value);
        formData.append('term_months', document.getElementById('termMonths').value);
        formData.append('start_date', document.getElementById('startDate').value);
        formData.append('maturity_date', document.getElementById('maturityDate').value);
        var action = isEditMode ? 'updateFinancing' : 'addFinancing';

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
                    // Reload all tabs that have been loaded
                    supDataLoaded = false; custDataLoaded = false; bankDataLoaded = false;
                    loadFinancing(activeFinancingTab);
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: response.message });
                }
            },
            error: function(xhr, status, error) {
                Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error: ' + error });
            }
        });
    });

    // ==================== DELETE ====================
    function deleteFinancing(financingId) {
        Swal.fire({
            title: 'Delete Financing Record?',
            text: 'This action cannot be undone.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            confirmButtonText: 'Yes, delete it!'
        }).then(function(result) {
            if (result.isConfirmed) {
                var formData = new FormData();
                formData.append('financing_id', financingId);

                $.ajax({
                    url: '?action=deleteFinancing',
                    method: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            Swal.fire({ icon: 'success', title: 'Deleted!', text: response.message, timer: 2000, showConfirmButton: false });
                            supDataLoaded = false; custDataLoaded = false; bankDataLoaded = false;
                            loadFinancing(activeFinancingTab);
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

    // ==================== LINKED PAYMENTS ====================
    function viewLinkedPayments(financingId) {
        document.getElementById('paymentsModalTitle').innerHTML = '<i class="fas fa-money-check"></i> Payments for ' + financingId;
        document.getElementById('paymentsModal').classList.add('active');
        document.getElementById('paymentsLoading').style.display = 'block';
        document.getElementById('paymentsContent').style.display = 'none';

        $.ajax({
            url: '?action=getLinkedPayments&financing_id=' + encodeURIComponent(financingId),
            method: 'GET',
            dataType: 'json',
            success: function(response) {
                document.getElementById('paymentsLoading').style.display = 'none';
                document.getElementById('paymentsContent').style.display = 'block';

                if (response.success && response.data.length > 0) {
                    document.getElementById('paymentsEmpty').style.display = 'none';

                    // running balance
                    var txns = response.transactions || [];
                    var cur = response.currency || 'FCFA';
                    var fmtAmt = function(n) { return Number(n || 0).toLocaleString('fr-FR'); };
                    var fmtDt = function(d) { if (!d) return '-'; var p = d.split('-'); return p.length === 3 ? p[2] + '/' + p[1] + '/' + p[0] : d; };

                    if (txns.length > 0) {
                        // summary header
                        var lastBal = txns[txns.length - 1].balance;
                        var balLabel = lastBal > 0.01 ? 'Outstanding' : (lastBal < -0.01 ? 'Overpaid' : 'Settled');
                        var balColor = lastBal > 0.01 ? '#e74c3c' : (lastBal < -0.01 ? '#27ae60' : '#888');
                        var balIcon = lastBal > 0.01 ? 'fa-exclamation-circle' : (lastBal < -0.01 ? 'fa-check-circle' : 'fa-check-double');

                        var summaryHtml = '<div style="display:flex;justify-content:space-between;align-items:center;padding:12px 16px;margin-bottom:14px;border-radius:8px;background:var(--card-bg);border:1px solid var(--border-color);">';
                        summaryHtml += '<span style="font-weight:600;font-size:14px;color:var(--text-primary);"><i class="fas fa-chart-line" style="margin-right:6px;color:var(--navy-accent);"></i>Transaction History</span>';
                        summaryHtml += '<span style="font-weight:700;font-size:15px;color:' + balColor + ';"><i class="fas ' + balIcon + '" style="margin-right:4px;"></i>' + fmtAmt(Math.abs(lastBal)) + ' ' + cur + ' — ' + balLabel + '</span>';
                        summaryHtml += '</div>';
                        document.getElementById('runningBalanceSummary').innerHTML = summaryHtml;

                        // running balance table
                        var tbl = '<table style="width:100%;border-collapse:collapse;font-size:13px;">';
                        tbl += '<thead><tr style="background:var(--navy-primary);color:#fff;">';
                        tbl += '<th style="padding:9px 10px;text-align:left;font-weight:600;font-size:12px;">Date</th>';
                        tbl += '<th style="padding:9px 10px;text-align:left;font-weight:600;font-size:12px;">Description</th>';
                        tbl += '<th style="padding:9px 10px;text-align:right;font-weight:600;font-size:12px;">Debit</th>';
                        tbl += '<th style="padding:9px 10px;text-align:right;font-weight:600;font-size:12px;">Credit</th>';
                        tbl += '<th style="padding:9px 10px;text-align:right;font-weight:600;font-size:12px;">Balance</th>';
                        tbl += '</tr></thead><tbody>';

                        txns.forEach(function(t, i) {
                            var rowBg = i % 2 === 0 ? 'transparent' : 'rgba(0,0,0,0.02)';
                            var bColor = t.balance > 0.01 ? '#e74c3c' : (t.balance < -0.01 ? '#27ae60' : '#888');
                            tbl += '<tr style="background:' + rowBg + ';">';
                            tbl += '<td style="padding:8px 10px;border-bottom:1px solid var(--border-color);white-space:nowrap;">' + fmtDt(t.date) + '</td>';
                            tbl += '<td style="padding:8px 10px;border-bottom:1px solid var(--border-color);">' + t.description + '</td>';
                            tbl += '<td style="padding:8px 10px;border-bottom:1px solid var(--border-color);text-align:right;color:#e74c3c;">' + (t.debit > 0 ? fmtAmt(t.debit) : '-') + '</td>';
                            tbl += '<td style="padding:8px 10px;border-bottom:1px solid var(--border-color);text-align:right;color:#27ae60;">' + (t.credit > 0 ? fmtAmt(t.credit) : '-') + '</td>';
                            tbl += '<td style="padding:8px 10px;border-bottom:1px solid var(--border-color);text-align:right;font-weight:700;color:' + bColor + ';">' + fmtAmt(Math.abs(t.balance)) + '</td>';
                            tbl += '</tr>';
                        });

                        // totals row
                        var totalDebit = 0, totalCredit = 0;
                        txns.forEach(function(t) { totalDebit += t.debit; totalCredit += t.credit; });
                        tbl += '<tr style="background:var(--navy-primary);color:#fff;font-weight:700;">';
                        tbl += '<td style="padding:9px 10px;" colspan="2">Total</td>';
                        tbl += '<td style="padding:9px 10px;text-align:right;">' + fmtAmt(totalDebit) + '</td>';
                        tbl += '<td style="padding:9px 10px;text-align:right;">' + fmtAmt(totalCredit) + '</td>';
                        tbl += '<td style="padding:9px 10px;text-align:right;">' + fmtAmt(Math.abs(lastBal)) + '</td>';
                        tbl += '</tr>';

                        tbl += '</tbody></table>';
                        document.getElementById('runningBalanceTable').innerHTML = tbl;
                        document.getElementById('runningBalanceSection').style.display = 'block';
                    } else {
                        document.getElementById('runningBalanceSection').style.display = 'none';
                    }

                    // payments DataTable
                    document.getElementById('linkedPaymentsTableWrap').style.display = 'block';
                    if ($.fn.DataTable.isDataTable('#linkedPaymentsTable')) {
                        $('#linkedPaymentsTable').DataTable().destroy();
                        $('#linkedPaymentsTable').empty();
                    }

                    $('#linkedPaymentsTable').DataTable({
                        data: response.data,
                        columns: [
                            { data: 'payment_id', title: 'Payment ID' },
                            { data: 'date', title: 'Date' },
                            { data: 'direction', title: 'Direction' },
                            { data: 'amount', title: 'Amount (F)', render: function(d) {
                                return parseFloat(d).toLocaleString(undefined, {maximumFractionDigits: 0});
                            }},
                            { data: 'payment_mode', title: 'Mode' },
                            { data: 'reference_number', title: 'Reference', render: function(d) { return d || '-'; } }
                        ],
                        pageLength: 50,
                        responsive: true,
                        dom: 'rtip',
                        order: [[1, 'desc']]
                    });
                } else {
                    document.getElementById('paymentsEmpty').style.display = 'block';
                    document.getElementById('runningBalanceSection').style.display = 'none';
                    document.getElementById('linkedPaymentsTableWrap').style.display = 'none';
                    if ($.fn.DataTable.isDataTable('#linkedPaymentsTable')) {
                        $('#linkedPaymentsTable').DataTable().destroy();
                        $('#linkedPaymentsTable').empty();
                    }
                }
            },
            error: function() {
                document.getElementById('paymentsLoading').style.display = 'none';
                Swal.fire({ icon: 'error', title: 'Error', text: 'Failed to load payments' });
            }
        });
    }

    function closePaymentsModal() {
        document.getElementById('paymentsModal').classList.remove('active');
    }

    // Click outside to close payments modal
    document.getElementById('paymentsModal')?.addEventListener('click', function(e) {
        if (e.target === this) closePaymentsModal();
    });

    // ==================== PRINT FINANCING RECEIPT ====================
    function printFinancingReceipt(financingId) {
        Swal.fire({ title: 'Generating contract...', allowOutsideClick: false, didOpen: function() { Swal.showLoading(); } });

        $.getJSON('?action=getFinancingReceipt&financing_id=' + encodeURIComponent(financingId)).done(function(r) {
            Swal.close();
            if (!r.success) { Swal.fire({ icon: 'error', title: 'Error', text: r.message }); return; }

            var f = r.data.financing;
            var pmnts = r.data.payments;
            var ci = r.data.companyInfo || {};
            var company = ci.company_name || '7503 Canada';
            var companySub = ci.company_subtitle || 'Negoce de Noix de Cajou Brutes';
            var companyAddr = ci.company_address || 'Daloa, Cote d\'Ivoire';
            var currency = ci.currency_symbol || 'FCFA';
            var fmtN = function(n) { return Number(n || 0).toLocaleString('fr-FR'); };
            var fmtDate = function(d) { if (!d) return '-'; var p = d.split('-'); return p[2] + '/' + p[1] + '/' + p[0]; };
            var now = new Date();
            var genDate = now.toLocaleDateString('fr-FR') + ' à ' + now.toLocaleTimeString('fr-FR', {hour:'2-digit',minute:'2-digit'});

            var dirLabel = f.direction === 'Incoming' ? 'REÇU' : 'VERSÉ';
            var dirFull = f.direction === 'Incoming' ? 'Réception de Financement' : 'Versement de Financement';

            // payments rows
            var pmntHtml = '';
            if (pmnts.length > 0) {
                pmnts.forEach(function(p) {
                    pmntHtml += '<tr><td>' + p.payment_id + '</td><td>' + fmtDate(p.date) + '</td><td style="text-align:right;">' + fmtN(p.amount) + ' ' + currency + '</td><td>' + (p.payment_mode || '-') + '</td><td>' + (p.reference_number || '-') + '</td></tr>';
                });
            } else {
                pmntHtml = '<tr><td colspan="5" style="text-align:center;color:#999;padding:8px;">Aucun paiement enregistré</td></tr>';
            }

            // bank vs commodity details
            var isBank = f.counterpart_type === 'Bank';
            var detailRows = '';
            if (isBank) {
                detailRows = '<tr><td>Taux d\'intérêt</td><td style="text-align:right;">' + (f.interest_rate_pct || 0) + ' %</td></tr>' +
                    '<tr><td>Mensualité</td><td style="text-align:right;">' + fmtN(f.monthly_payment) + ' ' + currency + '</td></tr>' +
                    '<tr><td>Durée</td><td style="text-align:right;">' + (f.term_months || '-') + ' mois</td></tr>' +
                    '<tr><td>Date début</td><td style="text-align:right;">' + fmtDate(f.start_date) + '</td></tr>' +
                    '<tr><td>Date échéance</td><td style="text-align:right;">' + fmtDate(f.maturity_date) + '</td></tr>';
            } else {
                detailRows = '<tr><td>Prix marché actuel</td><td style="text-align:right;">' + fmtN(f.current_market_price) + ' ' + currency + '/kg</td></tr>' +
                    '<tr><td>Volume prévu</td><td style="text-align:right;">' + fmtN(f.expected_volume_kg) + ' kg</td></tr>' +
                    '<tr><td>Volume livré</td><td style="text-align:right;">' + fmtN(f.delivered_volume_kg) + ' kg</td></tr>' +
                    '<tr><td>Volume restant</td><td style="text-align:right;">' + fmtN(f.volume_remaining_kg) + ' kg</td></tr>' +
                    '<tr><td>Intérêt / kg</td><td style="text-align:right;">' + fmtN(f.interest_per_kg) + ' ' + currency + '</td></tr>';
            }

            // contract legalese
            var legalText = 'Je soussigné(e) <strong>' + (f.counterpart_name || '_______________') + '</strong>, ' +
                'reconnais avoir ' + (f.direction === 'Incoming' ? 'versé' : 'reçu') + ' la somme de <strong>' + fmtN(f.amount) + ' ' + currency + '</strong> ' +
                (f.direction === 'Incoming' ? 'à' : 'de') + ' <strong>' + company + '</strong> ' +
                'pour l\'achat de produits agricoles (noix de cajou brutes). ' +
                'Ce montant sera remboursé conformément aux conditions convenues entre les parties. ' +
                'En cas de non-respect des obligations, les parties conviennent de régler tout différend à l\'amiable. ' +
                'Fait à ' + companyAddr + ', le ' + fmtDate(f.date) + '.';

            function buildCopy(copyLabel, copyClass) {
                return '<div class="receipt-copy">' +
                    '<div class="receipt-header">' +
                        '<div><div class="company-name">' + company + '</div>' +
                        '<div class="company-sub">' + companySub + ' — ' + companyAddr + '</div></div>' +
                        '<div style="text-align:right;"><div class="receipt-title">CONTRAT DE FINANCEMENT</div>' +
                        '<div class="receipt-num">N° ' + f.financing_id + '</div></div>' +
                    '</div>' +
                    '<div class="copy-label ' + copyClass + '">' + copyLabel + '</div>' +
                    '<div class="info-grid">' +
                        '<div><div class="info-label">Date</div><div class="info-val">' + fmtDate(f.date) + '</div></div>' +
                        '<div><div class="info-label">Direction</div><div class="info-val">' + dirLabel + ' (' + dirFull + ')</div></div>' +
                        '<div><div class="info-label">Type</div><div class="info-val">' + (f.counterpart_type || '-') + '</div></div>' +
                        '<div><div class="info-label">Contrepartie</div><div class="info-val">' + (f.counterpart_name || '-') + '</div></div>' +
                        '<div><div class="info-label">Réf.</div><div class="info-val">' + (f.reference_number || '-') + '</div></div>' +
                        '<div><div class="info-label">Saison</div><div class="info-val">' + (f.season || '-') + '</div></div>' +
                    '</div>' +
                    '<div class="section-title">DÉTAILS FINANCIERS</div>' +
                    '<table class="receipt-table">' +
                        '<thead><tr><th style="text-align:left;">Description</th><th>Montant</th></tr></thead>' +
                        '<tbody>' +
                            '<tr><td>Solde reporté</td><td style="text-align:right;">' + fmtN(f.carried_over_balance) + ' ' + currency + '</td></tr>' +
                            '<tr><td><strong>Montant du financement</strong></td><td style="text-align:right;"><strong>' + fmtN(f.amount) + ' ' + currency + '</strong></td></tr>' +
                            detailRows +
                            '<tr class="total-row"><td><strong>Intérêts</strong></td><td style="text-align:right;"><strong>' + fmtN(f.interest_amount) + ' ' + currency + '</strong></td></tr>' +
                            '<tr class="total-row"><td><strong>Remboursé</strong></td><td style="text-align:right;"><strong>' + fmtN(f.amount_repaid) + ' ' + currency + '</strong></td></tr>' +
                            '<tr class="total-row"><td><strong>SOLDE DÛ</strong></td><td style="text-align:right;font-size:12px;"><strong>' + fmtN(f.balance_due) + ' ' + currency + '</strong></td></tr>' +
                        '</tbody>' +
                    '</table>' +
                    (pmnts.length > 0 ? '<div class="section-title">HISTORIQUE DES PAIEMENTS</div>' +
                    '<table class="receipt-table"><thead><tr><th style="text-align:left;">Réf.</th><th>Date</th><th>Montant</th><th>Mode</th><th>N° Réf.</th></tr></thead><tbody>' + pmntHtml + '</tbody></table>' : '') +
                    '<div class="legal-box">' +
                        '<div class="legal-title">RECONNAISSANCE DE DETTE</div>' +
                        '<p>' + legalText + '</p>' +
                    '</div>' +
                    '<div class="signatures">' +
                        '<div class="sig-block"><div class="sig-line"></div><div class="sig-label">Signature ' + company + '</div></div>' +
                        '<div class="sig-block"><div class="sig-line"></div><div class="sig-label">Signature Contrepartie</div>' +
                        '<div class="sig-name">' + (f.counterpart_name || '') + '</div>' +
                        '<div class="sig-note">(Précédé de la mention "lu et approuvé")</div></div>' +
                    '</div>' +
                    '<div class="receipt-footer"><span>' + company + ' — ' + companyAddr + '</span><span>Réf: ' + f.financing_id + ' | Généré le ' + genDate + '</span></div>' +
                '</div>';
            }

            var html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Contrat - ' + f.financing_id + '</title>' +
                '<style>' +
                    '@page { size: A4; margin: 6mm 10mm; }' +
                    '* { margin: 0; padding: 0; box-sizing: border-box; }' +
                    'body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; font-size: 10px; color: #333; }' +
                    '.receipt-copy { padding: 10px 14px 6px; page-break-inside: avoid; }' +
                    '.receipt-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 8px; }' +
                    '.company-name { font-size: 17px; font-weight: 800; color: #1a5c2a; }' +
                    '.company-sub { font-size: 9px; color: #555; margin-top: 1px; }' +
                    '.receipt-title { font-size: 13px; font-weight: 700; color: #1a5c2a; }' +
                    '.receipt-num { font-size: 10px; color: #555; }' +
                    '.copy-label { display: inline-block; padding: 2px 10px; border: 1.5px solid #1a5c2a; font-size: 9px; font-weight: 700; text-transform: uppercase; margin-bottom: 6px; letter-spacing: 0.5px; }' +
                    '.copy-supplier { color: #1a5c2a; }' +
                    '.copy-company { color: #fff; background: #1a5c2a; }' +
                    '.info-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 2px 14px; margin-bottom: 8px; border-bottom: 1px solid #e0e0e0; padding-bottom: 6px; }' +
                    '.info-label { font-size: 8px; color: #888; text-transform: uppercase; }' +
                    '.info-val { font-size: 11px; font-weight: 700; color: #222; }' +
                    '.section-title { font-size: 10px; font-weight: 700; color: #222; margin: 6px 0 3px; text-transform: uppercase; }' +
                    '.receipt-table { width: 100%; border-collapse: collapse; margin-bottom: 4px; font-size: 10px; }' +
                    '.receipt-table thead th { background: #1a5c2a; color: #fff; padding: 3px 6px; font-size: 9px; font-weight: 600; text-align: right; }' +
                    '.receipt-table thead th:first-child { text-align: left; }' +
                    '.receipt-table tbody td { padding: 3px 6px; border-bottom: 1px solid #eee; }' +
                    '.receipt-table .total-row td { border-top: 1px solid #1a5c2a; border-bottom: none; font-weight: 700; }' +
                    '.legal-box { margin: 8px 0; padding: 8px 10px; border: 1.5px solid #1a5c2a; border-radius: 4px; background: #f8fdf9; }' +
                    '.legal-title { font-size: 10px; font-weight: 700; color: #1a5c2a; text-transform: uppercase; margin-bottom: 4px; }' +
                    '.legal-box p { font-size: 10px; line-height: 1.5; color: #333; text-align: justify; }' +
                    '.signatures { display: flex; justify-content: space-between; margin-top: 14px; gap: 30px; }' +
                    '.sig-block { flex: 1; }' +
                    '.sig-line { border-bottom: 1px solid #333; margin-bottom: 4px; height: 28px; }' +
                    '.sig-label { font-size: 9px; color: #555; }' +
                    '.sig-name { font-size: 10px; font-weight: 700; }' +
                    '.sig-note { font-size: 8px; color: #888; font-style: italic; }' +
                    '.receipt-footer { display: flex; justify-content: space-between; font-size: 8px; color: #888; margin-top: 4px; padding-top: 4px; border-top: 1px solid #e0e0e0; }' +
                    '.cut-line { text-align: center; padding: 3px 0; font-size: 9px; color: #aaa; letter-spacing: 2px; border-top: 2px dashed #ccc; border-bottom: 2px dashed #ccc; margin: 2px 0; }' +
                '</style></head><body>' +
                    buildCopy('COPIE CONTREPARTIE — ' + (f.counterpart_name || ''), 'copy-supplier') +
                    '<div class="cut-line">- - - - - COUPER ICI / DÉTACHER - - - - -</div>' +
                    buildCopy('COPIE COOPÉRATIVE — ' + company, 'copy-company') +
                '</body></html>';

            var printWin = window.open('', '_blank', 'width=800,height=1100');
            printWin.document.write(html);
            printWin.document.close();
            printWin.onload = function() { printWin.focus(); printWin.print(); };
        }).fail(function() {
            Swal.close();
            Swal.fire({ icon: 'error', title: 'Error', text: 'Failed to load financing data' });
        });
    }
    </script>
</body>
</html>
