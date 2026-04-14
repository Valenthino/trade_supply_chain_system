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
$current_page = 'payments';

// RBAC
$allowedRoles = ['Admin', 'Manager', 'Finance Officer', 'Procurement Officer', 'Sales Officer'];
if (!in_array($role, $allowedRoles)) {
    header("Location: dashboard.php");
    exit();
}

$canCreate = in_array($role, ['Admin', 'Manager', 'Finance Officer', 'Procurement Officer', 'Sales Officer']);
$canUpdate = in_array($role, ['Admin', 'Manager', 'Finance Officer']);
$canDelete = ($role === 'Admin');
$isReadOnly = false;

/**
 * Update records linked from a payment row.
 *
 * Supplier-side: delegates entirely to reconcileSupplierAccount (config.php),
 * which is the canonical reconciler — it handles purchase status, manual
 * advance balances, and the derived Auto-Overpayment / Auto-Payable rows in
 * one consistent pass.
 *
 * Bank/Customer financing rows still need their own balance_due update because
 * they live outside the supplier ledger.
 */
function updateLinkedRecords($conn, $linkedPurchaseId, $linkedSaleId, $linkedFinancingId) {
    // purchase link → reconcile that purchase's supplier
    if ($linkedPurchaseId) {
        $stmt = $conn->prepare("SELECT supplier_id FROM purchases WHERE purchase_id = ?");
        $stmt->bind_param("s", $linkedPurchaseId);
        $stmt->execute();
        $r = $stmt->get_result();
        if ($r->num_rows > 0) {
            $row = $r->fetch_assoc();
            $stmt->close();
            if ($row['supplier_id']) reconcileSupplierAccount($conn, $row['supplier_id']);
        } else {
            $stmt->close();
        }
    }

    // financing link
    if ($linkedFinancingId) {
        $stmt = $conn->prepare("SELECT counterpart_type, counterparty_id, amount, carried_over_balance, interest_per_kg, delivered_volume_kg, interest_rate_pct, source FROM financing WHERE financing_id = ?");
        $stmt->bind_param("s", $linkedFinancingId);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res->num_rows > 0) {
            $fin = $res->fetch_assoc();
            $stmt->close();

            if ($fin['counterpart_type'] === 'Supplier') {
                // canonical reconciler handles everything for suppliers
                reconcileSupplierAccount($conn, $fin['counterparty_id']);
            } else {
                // Bank / Customer — keep the per-financing recalc
                $rp = $conn->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE linked_financing_id = ?");
                $rp->bind_param("s", $linkedFinancingId);
                $rp->execute();
                $totalRepaid = floatval($rp->get_result()->fetch_row()[0]);
                $rp->close();

                if ($fin['counterpart_type'] === 'Bank' && floatval($fin['interest_rate_pct']) > 0) {
                    $interestAmount = round(floatval($fin['amount']) * (floatval($fin['interest_rate_pct']) / 100), 2);
                } else {
                    $interestAmount = round(floatval($fin['interest_per_kg']) * floatval($fin['delivered_volume_kg']), 2);
                }
                $balanceDue = round(floatval($fin['amount']) + floatval($fin['carried_over_balance']) + $interestAmount - $totalRepaid, 2);
                $newStatus = ($balanceDue <= 0) ? 'Settled' : 'Active';

                $u = $conn->prepare("UPDATE financing SET amount_repaid = ?, balance_due = ?, interest_amount = ?, status = ? WHERE financing_id = ?");
                $u->bind_param("dddss", $totalRepaid, $balanceDue, $interestAmount, $newStatus, $linkedFinancingId);
                $u->execute();
                $u->close();
            }
        } else {
            $stmt->close();
        }
    }
}

// wrapper — uses shared function from config.php
function updateCustomerFinancingBalance($conn, $customerId, $customerName, $defaultSeason) {
    syncCustomerOverpayment($conn, $customerId, $customerName);
}

// Handle AJAX requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    try {
        switch ($_GET['action']) {
            case 'getPayments':
                $conn = getDBConnection();
                $stmt = $conn->prepare("SELECT * FROM payments ORDER BY payment_id DESC");
                $stmt->execute();
                $result = $stmt->get_result();

                $payments = [];
                while ($row = $result->fetch_assoc()) {
                    $payments[] = [
                        'payment_id' => $row['payment_id'],
                        'date' => date('M d, Y', strtotime($row['date'])),
                        'date_raw' => $row['date'],
                        'direction' => $row['direction'],
                        'payment_type' => $row['payment_type'],
                        'counterpart_id' => $row['counterpart_id'],
                        'counterpart_name' => $row['counterpart_name'],
                        'amount' => $row['amount'],
                        'payment_mode' => $row['payment_mode'],
                        'reference_number' => $row['reference_number'],
                        'linked_purchase_id' => $row['linked_purchase_id'],
                        'linked_sale_id' => $row['linked_sale_id'],
                        'linked_financing_id' => $row['linked_financing_id'],
                        'season' => $row['season'],
                        'notes' => $row['notes']
                    ];
                }

                $stmt->close();
                $conn->close();

                echo json_encode(['success' => true, 'data' => $payments]);
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

                // Purchases (include supplier_id for filtering)
                $purchases = [];
                $stmt = $conn->prepare("SELECT purchase_id, supplier_id, supplier_name, total_cost, payment_status FROM purchases ORDER BY purchase_id DESC");
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $purchases[] = $row;
                }
                $stmt->close();

                // Sales (include customer name for display)
                $sales = [];
                $stmt = $conn->prepare("SELECT s.sale_id, s.delivery_id, s.customer_id, c.customer_name, s.gross_sale_amount FROM sales s LEFT JOIN customers c ON s.customer_id = c.customer_id WHERE s.sale_status IN ('Draft','Confirmed') ORDER BY s.sale_id DESC");
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $sales[] = $row;
                }
                $stmt->close();

                // Financing records (include counterparty_id for filtering)
                $financing = [];
                $stmt = $conn->prepare("SELECT financing_id, counterparty_id, counterpart_name, direction, balance_due FROM financing WHERE status IN ('Active','Overdue') ORDER BY financing_id DESC");
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $financing[] = $row;
                }
                $stmt->close();

                $conn->close();

                echo json_encode([
                    'success' => true,
                    'data' => [
                        'customers' => $customers,
                        'suppliers' => $suppliers,
                        'purchases' => $purchases,
                        'sales' => $sales,
                        'financing' => $financing
                    ]
                ]);
                exit();

            case 'addPayment':
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
                $paymentType = isset($_POST['payment_type']) ? trim($_POST['payment_type']) : '';
                $counterpartId = isset($_POST['counterpart_id']) ? trim($_POST['counterpart_id']) : '';
                $amount = isset($_POST['amount']) ? floatval($_POST['amount']) : 0;
                $paymentMode = isset($_POST['payment_mode']) ? trim($_POST['payment_mode']) : '';
                $referenceNumber = !empty($_POST['reference_number']) ? trim($_POST['reference_number']) : null;
                $linkedPurchaseId = !empty($_POST['linked_purchase_id']) ? trim($_POST['linked_purchase_id']) : null;
                $linkedSaleId = !empty($_POST['linked_sale_id']) ? trim($_POST['linked_sale_id']) : null;
                $linkedFinancingId = !empty($_POST['linked_financing_id']) ? trim($_POST['linked_financing_id']) : null;
                $season = isset($_POST['season']) ? trim($_POST['season']) : '2025/2026';
                $notes = !empty($_POST['notes']) ? trim($_POST['notes']) : null;

                // Validation
                if (empty($date)) {
                    echo json_encode(['success' => false, 'message' => 'Date is required']);
                    exit();
                }
                if (empty($direction) || !in_array($direction, ['Outgoing', 'Incoming'])) {
                    echo json_encode(['success' => false, 'message' => 'Valid direction is required']);
                    exit();
                }
                if (empty($paymentType) || !in_array($paymentType, ['Purchase', 'Sale', 'Financing', 'Repayment'])) {
                    echo json_encode(['success' => false, 'message' => 'Valid payment type is required']);
                    exit();
                }
                if (empty($counterpartId)) {
                    echo json_encode(['success' => false, 'message' => 'Counterparty is required']);
                    exit();
                }
                if ($amount <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Amount must be greater than 0']);
                    exit();
                }
                if (empty($paymentMode)) {
                    echo json_encode(['success' => false, 'message' => 'Payment mode is required']);
                    exit();
                }
                if (empty($season)) {
                    echo json_encode(['success' => false, 'message' => 'Season is required']);
                    exit();
                }

                $conn = getDBConnection();

                // Auto-generate payment_id (PAI-YY-MMDD-XXXX-C)
                $newId = generateTransactionId($conn, 'PAI', 'payments', 'payment_id');

                // Resolve counterpart_name
                $counterpartName = '';
                if ($paymentType === 'Purchase') {
                    $stmt = $conn->prepare("SELECT first_name FROM suppliers WHERE supplier_id = ?");
                    $stmt->bind_param("s", $counterpartId);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    if ($res->num_rows > 0) {
                        $counterpartName = $res->fetch_assoc()['first_name'];
                    }
                    $stmt->close();
                } elseif ($paymentType === 'Sale') {
                    $stmt = $conn->prepare("SELECT customer_name FROM customers WHERE customer_id = ?");
                    $stmt->bind_param("s", $counterpartId);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    if ($res->num_rows > 0) {
                        $counterpartName = $res->fetch_assoc()['customer_name'];
                    }
                    $stmt->close();
                } else {
                    // Financing or Repayment — try customers first, then suppliers
                    $stmt = $conn->prepare("SELECT customer_name FROM customers WHERE customer_id = ?");
                    $stmt->bind_param("s", $counterpartId);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    if ($res->num_rows > 0) {
                        $counterpartName = $res->fetch_assoc()['customer_name'];
                    }
                    $stmt->close();

                    if (empty($counterpartName)) {
                        $stmt = $conn->prepare("SELECT first_name FROM suppliers WHERE supplier_id = ?");
                        $stmt->bind_param("s", $counterpartId);
                        $stmt->execute();
                        $res = $stmt->get_result();
                        if ($res->num_rows > 0) {
                            $counterpartName = $res->fetch_assoc()['first_name'];
                        }
                        $stmt->close();
                    }
                }

                $stmt = $conn->prepare("INSERT INTO payments (payment_id, date, direction, payment_type, counterpart_id, counterpart_name, amount, payment_mode, reference_number, linked_purchase_id, linked_sale_id, linked_financing_id, season, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("ssssssdsssssss",
                    $newId, $date, $direction, $paymentType, $counterpartId, $counterpartName,
                    $amount, $paymentMode, $referenceNumber,
                    $linkedPurchaseId, $linkedSaleId, $linkedFinancingId,
                    $season, $notes
                );

                if ($stmt->execute()) {
                    $stmt->close();

                    // Update linked records
                    updateLinkedRecords($conn, $linkedPurchaseId, $linkedSaleId, $linkedFinancingId);

                    // supplier-side reconcile for any supplier-touching payment (covers Purchase / Repayment paths even when no link)
                    if (in_array($paymentType, ['Purchase', 'Repayment'])) {
                        $chk = $conn->prepare("SELECT 1 FROM suppliers WHERE supplier_id = ?");
                        $chk->bind_param("s", $counterpartId);
                        $chk->execute();
                        if ($chk->get_result()->num_rows > 0) {
                            reconcileSupplierAccount($conn, $counterpartId);
                        }
                        $chk->close();
                    }

                    // auto-create financing when paying supplier as Financing with no existing link
                    if ($paymentType === 'Financing' && $direction === 'Outgoing' && empty($linkedFinancingId)) {
                        $isSupplier = false;
                        $chk = $conn->prepare("SELECT supplier_id FROM suppliers WHERE supplier_id = ?");
                        $chk->bind_param("s", $counterpartId);
                        $chk->execute();
                        if ($chk->get_result()->num_rows > 0) $isSupplier = true;
                        $chk->close();

                        if ($isSupplier) {
                            $finId = generateTransactionId($conn, 'FIN', 'financing', 'financing_id');
                            $finDir = 'Outgoing';
                            $finType = 'Supplier';
                            $zero = 0.0;
                            $finBal = $amount;
                            $finSt = 'Active';
                            $finSrc = 'Manual';
                            $finNote = "Auto-created from payment $newId";

                            $fStmt = $conn->prepare("INSERT INTO financing (financing_id, date, direction, counterpart_type, counterparty_id, counterpart_name, carried_over_balance, amount, amount_repaid, current_market_price, expected_volume_kg, delivered_volume_kg, volume_remaining_kg, interest_per_kg, interest_amount, balance_due, status, season, notes, source) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                            $fStmt->bind_param("ssssssddddddddddssss",
                                $finId, $date, $finDir, $finType, $counterpartId, $counterpartName,
                                $zero, $amount, $zero, $zero,
                                $zero, $zero, $zero,
                                $zero, $zero, $finBal, $finSt, $season, $finNote, $finSrc
                            );
                            $fStmt->execute();
                            $fStmt->close();

                            // link payment to new financing
                            $lnk = $conn->prepare("UPDATE payments SET linked_financing_id = ? WHERE payment_id = ?");
                            $lnk->bind_param("ss", $finId, $newId);
                            $lnk->execute();
                            $lnk->close();

                            // new advance just landed — reconcile so any pending purchases get covered
                            reconcileSupplierAccount($conn, $counterpartId);
                        }
                    }

                    // customer overpayment check
                    if ($paymentType === 'Sale' && $direction === 'Incoming') {
                        updateCustomerFinancingBalance($conn, $counterpartId, $counterpartName, $season);
                    }

                    logActivity($user_id, $username, 'Payment Created', "Created payment: $newId ($direction, $paymentType) - $counterpartName, Amount: $amount");
                    $conn->close();
                    echo json_encode(['success' => true, 'message' => 'Payment added successfully', 'payment_id' => $newId]);
                } else {
                    $error = $stmt->error;
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to add payment: ' . $error]);
                }
                exit();

            case 'updatePayment':
                if (!$canUpdate) {
                    echo json_encode(['success' => false, 'message' => 'Access denied. Only Admin, Manager, or Finance Officer can update.']);
                    exit();
                }

                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $paymentId = isset($_POST['payment_id']) ? trim($_POST['payment_id']) : '';
                $date = isset($_POST['date']) ? trim($_POST['date']) : '';
                $direction = isset($_POST['direction']) ? trim($_POST['direction']) : '';
                $paymentType = isset($_POST['payment_type']) ? trim($_POST['payment_type']) : '';
                $counterpartId = isset($_POST['counterpart_id']) ? trim($_POST['counterpart_id']) : '';
                $amount = isset($_POST['amount']) ? floatval($_POST['amount']) : 0;
                $paymentMode = isset($_POST['payment_mode']) ? trim($_POST['payment_mode']) : '';
                $referenceNumber = !empty($_POST['reference_number']) ? trim($_POST['reference_number']) : null;
                $linkedPurchaseId = !empty($_POST['linked_purchase_id']) ? trim($_POST['linked_purchase_id']) : null;
                $linkedSaleId = !empty($_POST['linked_sale_id']) ? trim($_POST['linked_sale_id']) : null;
                $linkedFinancingId = !empty($_POST['linked_financing_id']) ? trim($_POST['linked_financing_id']) : null;
                $season = isset($_POST['season']) ? trim($_POST['season']) : '2025/2026';
                $notes = !empty($_POST['notes']) ? trim($_POST['notes']) : null;

                // Validation
                if (empty($paymentId)) {
                    echo json_encode(['success' => false, 'message' => 'Payment ID is required']);
                    exit();
                }
                if (empty($date)) {
                    echo json_encode(['success' => false, 'message' => 'Date is required']);
                    exit();
                }
                if (empty($direction) || !in_array($direction, ['Outgoing', 'Incoming'])) {
                    echo json_encode(['success' => false, 'message' => 'Valid direction is required']);
                    exit();
                }
                if (empty($paymentType) || !in_array($paymentType, ['Purchase', 'Sale', 'Financing', 'Repayment'])) {
                    echo json_encode(['success' => false, 'message' => 'Valid payment type is required']);
                    exit();
                }
                if (empty($counterpartId)) {
                    echo json_encode(['success' => false, 'message' => 'Counterparty is required']);
                    exit();
                }
                if ($amount <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Amount must be greater than 0']);
                    exit();
                }
                if (empty($paymentMode)) {
                    echo json_encode(['success' => false, 'message' => 'Payment mode is required']);
                    exit();
                }

                $conn = getDBConnection();

                // Get old record for recalculation of previously linked records
                $stmt = $conn->prepare("SELECT * FROM payments WHERE payment_id = ?");
                $stmt->bind_param("s", $paymentId);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($res->num_rows === 0) {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Payment not found']);
                    exit();
                }
                $oldRecord = $res->fetch_assoc();
                $stmt->close();

                // Resolve counterpart_name
                $counterpartName = '';
                if ($paymentType === 'Purchase') {
                    $stmt = $conn->prepare("SELECT first_name FROM suppliers WHERE supplier_id = ?");
                    $stmt->bind_param("s", $counterpartId);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    if ($res->num_rows > 0) {
                        $counterpartName = $res->fetch_assoc()['first_name'];
                    }
                    $stmt->close();
                } elseif ($paymentType === 'Sale') {
                    $stmt = $conn->prepare("SELECT customer_name FROM customers WHERE customer_id = ?");
                    $stmt->bind_param("s", $counterpartId);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    if ($res->num_rows > 0) {
                        $counterpartName = $res->fetch_assoc()['customer_name'];
                    }
                    $stmt->close();
                } else {
                    $stmt = $conn->prepare("SELECT customer_name FROM customers WHERE customer_id = ?");
                    $stmt->bind_param("s", $counterpartId);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    if ($res->num_rows > 0) {
                        $counterpartName = $res->fetch_assoc()['customer_name'];
                    }
                    $stmt->close();

                    if (empty($counterpartName)) {
                        $stmt = $conn->prepare("SELECT first_name FROM suppliers WHERE supplier_id = ?");
                        $stmt->bind_param("s", $counterpartId);
                        $stmt->execute();
                        $res = $stmt->get_result();
                        if ($res->num_rows > 0) {
                            $counterpartName = $res->fetch_assoc()['first_name'];
                        }
                        $stmt->close();
                    }
                }

                $stmt = $conn->prepare("UPDATE payments SET date = ?, direction = ?, payment_type = ?, counterpart_id = ?, counterpart_name = ?, amount = ?, payment_mode = ?, reference_number = ?, linked_purchase_id = ?, linked_sale_id = ?, linked_financing_id = ?, season = ?, notes = ? WHERE payment_id = ?");
                $stmt->bind_param("sssssdssssssss",
                    $date, $direction, $paymentType, $counterpartId, $counterpartName,
                    $amount, $paymentMode, $referenceNumber,
                    $linkedPurchaseId, $linkedSaleId, $linkedFinancingId,
                    $season, $notes, $paymentId
                );

                if ($stmt->execute()) {
                    $stmt->close();

                    // Recalculate old linked records (in case link changed)
                    $oldPurchase = $oldRecord['linked_purchase_id'];
                    $oldSale = $oldRecord['linked_sale_id'];
                    $oldFinancing = $oldRecord['linked_financing_id'];
                    if ($oldPurchase && $oldPurchase !== $linkedPurchaseId) {
                        updateLinkedRecords($conn, $oldPurchase, null, null);
                    }
                    if ($oldFinancing && $oldFinancing !== $linkedFinancingId) {
                        updateLinkedRecords($conn, null, null, $oldFinancing);
                    }

                    // Recalculate new linked records
                    updateLinkedRecords($conn, $linkedPurchaseId, $linkedSaleId, $linkedFinancingId);

                    // supplier-side reconcile for any supplier-touching payment (covers Purchase / Repayment paths even when no link)
                    if (in_array($paymentType, ['Purchase', 'Repayment'])) {
                        $chk = $conn->prepare("SELECT 1 FROM suppliers WHERE supplier_id = ?");
                        $chk->bind_param("s", $counterpartId);
                        $chk->execute();
                        if ($chk->get_result()->num_rows > 0) {
                            reconcileSupplierAccount($conn, $counterpartId);
                        }
                        $chk->close();
                    }
                    // reconcile old supplier if counterpart changed
                    if (in_array($oldRecord['payment_type'], ['Purchase', 'Repayment']) && $oldRecord['counterpart_id'] !== $counterpartId) {
                        $chk = $conn->prepare("SELECT 1 FROM suppliers WHERE supplier_id = ?");
                        $chk->bind_param("s", $oldRecord['counterpart_id']);
                        $chk->execute();
                        if ($chk->get_result()->num_rows > 0) {
                            reconcileSupplierAccount($conn, $oldRecord['counterpart_id']);
                        }
                        $chk->close();
                    }

                    // customer overpayment check (current + old counterpart if changed)
                    if ($paymentType === 'Sale' && $direction === 'Incoming') {
                        updateCustomerFinancingBalance($conn, $counterpartId, $counterpartName, $season);
                    }
                    if ($oldRecord['payment_type'] === 'Sale' && $oldRecord['direction'] === 'Incoming' && $oldRecord['counterpart_id'] !== $counterpartId) {
                        updateCustomerFinancingBalance($conn, $oldRecord['counterpart_id'], '', $season);
                    }

                    logActivity($user_id, $username, 'Payment Updated', "Updated payment: $paymentId ($direction, $paymentType) - $counterpartName, Amount: $amount");
                    $conn->close();
                    echo json_encode(['success' => true, 'message' => 'Payment updated successfully']);
                } else {
                    $error = $stmt->error;
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to update payment: ' . $error]);
                }
                exit();

            case 'deletePayment':
                if (!$canDelete) {
                    echo json_encode(['success' => false, 'message' => 'Access denied. Only Admin can delete.']);
                    exit();
                }

                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $paymentId = isset($_POST['payment_id']) ? trim($_POST['payment_id']) : '';
                if (empty($paymentId)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid payment ID']);
                    exit();
                }

                $conn = getDBConnection();

                // Get payment info before deleting (for linked records recalculation + logging)
                $stmt = $conn->prepare("SELECT * FROM payments WHERE payment_id = ?");
                $stmt->bind_param("s", $paymentId);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($res->num_rows === 0) {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Payment not found']);
                    exit();
                }
                $info = $res->fetch_assoc();
                $stmt->close();

                $linkedPurchaseId = $info['linked_purchase_id'];
                $linkedSaleId = $info['linked_sale_id'];
                $linkedFinancingId = $info['linked_financing_id'];

                $stmt = $conn->prepare("DELETE FROM payments WHERE payment_id = ?");
                $stmt->bind_param("s", $paymentId);

                if ($stmt->execute()) {
                    $stmt->close();

                    // Recalculate linked records after deletion
                    updateLinkedRecords($conn, $linkedPurchaseId, $linkedSaleId, $linkedFinancingId);

                    // reconcile supplier if this was a supplier-touching payment
                    if (in_array($info['payment_type'], ['Purchase', 'Repayment', 'Financing'])) {
                        $chk = $conn->prepare("SELECT 1 FROM suppliers WHERE supplier_id = ?");
                        $chk->bind_param("s", $info['counterpart_id']);
                        $chk->execute();
                        if ($chk->get_result()->num_rows > 0) {
                            reconcileSupplierAccount($conn, $info['counterpart_id']);
                        }
                        $chk->close();
                    }

                    // recalc customer overpayment if was a sale payment
                    if ($info['payment_type'] === 'Sale' && $info['direction'] === 'Incoming') {
                        updateCustomerFinancingBalance($conn, $info['counterpart_id'], $info['counterpart_name'], $info['season'] ?? '');
                    }

                    logActivity($user_id, $username, 'Payment Deleted', "Deleted payment: $paymentId ({$info['direction']}, {$info['payment_type']}) - {$info['counterpart_name']}, Amount: {$info['amount']}");
                    $conn->close();
                    echo json_encode(['success' => true, 'message' => 'Payment deleted successfully']);
                } else {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to delete payment']);
                }
                exit();

            case 'getPaymentReceipt':
                $paymentId = isset($_GET['payment_id']) ? trim($_GET['payment_id']) : '';
                if (empty($paymentId)) {
                    echo json_encode(['success' => false, 'message' => 'Payment ID is required']);
                    exit();
                }

                $conn = getDBConnection();

                // Fetch payment record
                $stmt = $conn->prepare("SELECT * FROM payments WHERE payment_id = ?");
                $stmt->bind_param("s", $paymentId);
                $stmt->execute();
                $result = $stmt->get_result();
                if (!$result || $result->num_rows === 0) {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Payment not found']);
                    exit();
                }
                $payment = $result->fetch_assoc();
                $stmt->close();

                // Resolve counterpart name + phone from supplier or customer
                $resolvedName = $payment['counterpart_name'];
                $counterpartPhone = '';
                if (!empty($payment['counterpart_id'])) {
                    // Try suppliers first
                    $stmt = $conn->prepare("SELECT first_name, COALESCE(whatsapp_phone, phone) AS phone FROM suppliers WHERE supplier_id = ?");
                    $stmt->bind_param("s", $payment['counterpart_id']);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    if ($res && $res->num_rows > 0) {
                        $row = $res->fetch_assoc();
                        $resolvedName = $row['first_name'];
                        $counterpartPhone = $row['phone'] ?? '';
                    }
                    $stmt->close();

                    // Try customers
                    $stmt = $conn->prepare("SELECT customer_name, phone FROM customers WHERE customer_id = ?");
                    $stmt->bind_param("s", $payment['counterpart_id']);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    if ($res && $res->num_rows > 0) {
                        $row = $res->fetch_assoc();
                        $resolvedName = $row['customer_name'];
                        $counterpartPhone = $row['phone'] ?? '';
                    }
                    $stmt->close();
                }
                $payment['resolved_name'] = $resolvedName;
                $payment['counterpart_phone'] = $counterpartPhone;

                $companyInfo = getCompanyInfo();

                $conn->close();

                echo json_encode([
                    'success' => true,
                    'data' => $payment,
                    'companyInfo' => $companyInfo,
                    'company_name' => $companyInfo['company_name']
                ]);
                exit();

            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
                exit();
        }
    } catch (\Throwable $e) {
        error_log("payments.php error: " . $e->getMessage());
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
    <title>Payments - Dashboard</title>

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
                <h1><i class="fas fa-credit-card"></i> Payments</h1>
                <div>Welcome, <?php echo htmlspecialchars($username); ?></div>
            </div>

            <div class="data-section">
                <div class="section-header">
                    <h2><i class="fas fa-table"></i> Payments</h2>
                    <div class="section-header-actions">
                        <button class="btn btn-primary" onclick="loadPayments()">
                            <i class="fas fa-sync"></i> Refresh
                        </button>
                        <?php if ($canCreate): ?>
                        <button class="btn btn-success" onclick="openAddModal()">
                            <i class="fas fa-plus"></i> Add Payment
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
                            <label><i class="fas fa-exchange-alt"></i> Direction</label>
                            <select id="filterDirection" class="filter-input">
                                <option value="">All</option>
                                <option value="Outgoing">Outgoing</option>
                                <option value="Incoming">Incoming</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-tags"></i> Payment Type</label>
                            <select id="filterPaymentType" class="filter-input">
                                <option value="">All</option>
                                <option value="Purchase">Purchase</option>
                                <option value="Sale">Sale</option>
                                <option value="Financing">Financing</option>
                                <option value="Repayment">Repayment</option>
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

                <div id="skeletonLoader">
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

                <div id="tableContainer" style="display: none;">
                    <div class="table-scroll-hint">
                        <i class="fas fa-arrows-alt-h"></i> Swipe left/right to see all columns
                    </div>
                    <div style="overflow-x: auto; -webkit-overflow-scrolling: touch;">
                        <table id="paymentsTable" class="display" style="width:100%"></table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($canCreate || $canUpdate): ?>
    <div class="modal-overlay" id="paymentModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 id="modalTitle"><i class="fas fa-credit-card"></i> Add Payment</h3>
                <button class="close-btn" onclick="closeModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <div id="paymentIdInfo" class="form-id-info" style="display: none;">
                    <strong><i class="fas fa-id-badge"></i> Payment ID:</strong> <span id="paymentIdDisplay"></span>
                </div>

                <form id="paymentForm">
                    <input type="hidden" id="paymentId" name="payment_id">

                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-calendar-day"></i> Date *</label>
                            <input type="date" id="paymentDate" name="date" required>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-exchange-alt"></i> Direction *</label>
                            <div class="radio-group">
                                <label class="radio-label">
                                    <input type="radio" name="direction" value="Outgoing" id="dirOutgoing" required>
                                    <span>Outgoing</span>
                                </label>
                                <label class="radio-label">
                                    <input type="radio" name="direction" value="Incoming" id="dirIncoming">
                                    <span>Incoming</span>
                                </label>
                            </div>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-tags"></i> Payment Type *</label>
                            <select id="paymentType" name="payment_type" required onchange="onPaymentTypeChange()">
                                <option value="">Select type...</option>
                                <option value="Purchase">Purchase</option>
                                <option value="Sale">Sale</option>
                                <option value="Financing">Financing</option>
                                <option value="Repayment">Repayment</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-handshake"></i> Counterparty *</label>
                            <div class="searchable-dropdown" id="counterpartyDropdownWrapper">
                                <input type="text" class="searchable-dropdown-input" id="counterpartySearch" placeholder="Search counterparty..." autocomplete="off">
                                <input type="hidden" id="counterpartId" name="counterpart_id" required>
                                <span class="searchable-dropdown-arrow" id="counterpartyArrow"><i class="fas fa-chevron-down"></i></span>
                                <div class="searchable-dropdown-list" id="counterpartyList" style="display:none;"></div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-dollar-sign"></i> Amount *</label>
                            <input type="text" inputmode="decimal" id="paymentAmount" name="amount" class="money-input" required placeholder="0.00">
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-money-check-alt"></i> Payment Mode *</label>
                            <select id="paymentMode" name="payment_mode" required>
                                <option value="">Select mode...</option>
                                <option value="Cash">Cash</option>
                                <option value="Bank">Bank</option>
                                <option value="Mobile Money">Mobile Money</option>
                                <option value="Cheque">Cheque</option>
                                <option value="Swap/Hawala">Swap/Hawala</option>
                                <option value="Products Delivery">Products Delivery</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-hashtag"></i> Reference Number</label>
                            <input type="text" id="referenceNumber" name="reference_number" placeholder="Enter reference..." maxlength="100">
                        </div>

                        <div class="form-group" id="linkedPurchaseGroup" style="display: none;">
                            <label><i class="fas fa-shopping-cart"></i> Linked Purchase</label>
                            <select id="linkedPurchaseId" name="linked_purchase_id">
                                <option value="">None</option>
                            </select>
                        </div>

                        <div class="form-group" id="linkedSaleGroup" style="display: none;">
                            <label><i class="fas fa-receipt"></i> Linked Sale</label>
                            <select id="linkedSaleId" name="linked_sale_id">
                                <option value="">None</option>
                            </select>
                        </div>

                        <div class="form-group" id="linkedFinancingGroup" style="display: none;">
                            <label><i class="fas fa-hand-holding-usd"></i> Linked Financing</label>
                            <select id="linkedFinancingId" name="linked_financing_id">
                                <option value="">None</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-leaf"></i> Season *</label>
                            <?php echo renderSeasonDropdown('season', 'season'); ?>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-sticky-note"></i> Notes</label>
                            <textarea id="notes" name="notes" rows="2" placeholder="Optional notes..."></textarea>
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
    let paymentsTable;
    let isEditMode = false;
    let paymentsData = [];
    let customersList = [];
    let suppliersList = [];
    let purchasesList = [];
    let salesList = [];
    let financingList = [];
    let currentCounterpartySource = []; // active counterparty options

    const isReadOnly = <?php echo $isReadOnly ? 'true' : 'false'; ?>;
    const canCreate = <?php echo $canCreate ? 'true' : 'false'; ?>;
    const canUpdate = <?php echo $canUpdate ? 'true' : 'false'; ?>;
    const canDelete = <?php echo $canDelete ? 'true' : 'false'; ?>;

    const directionBadgeMap = {
        'Outgoing': 'status-outgoing',
        'Incoming': 'status-incoming'
    };

    $(document).ready(function() {
        loadDropdowns();
        loadPayments();
    });

    function loadDropdowns() {
        $.ajax({
            url: '?action=getDropdowns',
            method: 'GET',
            dataType: 'json',
            success: function(response) {
                if (response.success && response.data) {
                    customersList = response.data.customers.map(function(c) {
                        return { id: c.customer_id, name: c.customer_name, type: 'customer' };
                    });
                    suppliersList = response.data.suppliers.map(function(s) {
                        return { id: s.supplier_id, name: s.first_name, type: 'supplier' };
                    });
                    purchasesList = response.data.purchases || [];
                    salesList = response.data.sales || [];
                    financingList = response.data.financing || [];

                    initCounterpartyDropdown();
                    populateLinkedDropdowns();
                }
            }
        });
    }

    function populateLinkedDropdowns() {
        // initial populate (all) — will be re-filtered when counterpart is selected
        filterLinkedDropdowns('');
    }

    function filterLinkedDropdowns(counterpartId) {
        var type = document.getElementById('paymentType').value;

        // Purchases: filter by supplier_id, exclude Paid
        var purchaseSelect = document.getElementById('linkedPurchaseId');
        if (purchaseSelect) {
            var prevVal = purchaseSelect.value;
            purchaseSelect.innerHTML = '<option value="">None</option>';
            purchasesList.forEach(function(p) {
                // skip Paid purchases
                if (p.payment_status === 'Paid') return;
                // filter by supplier if counterpart selected and type is Purchase
                if (counterpartId && type === 'Purchase' && p.supplier_id !== counterpartId) return;

                var cost = parseFloat(p.total_cost).toLocaleString();
                var opt = document.createElement('option');
                opt.value = p.purchase_id;
                opt.textContent = p.purchase_id + ' — ' + p.supplier_name + ' (' + cost + ' F) [' + p.payment_status + ']';
                purchaseSelect.appendChild(opt);
            });
            if (prevVal) purchaseSelect.value = prevVal;
        }

        // Sales: filter by customer_id
        var saleSelect = document.getElementById('linkedSaleId');
        if (saleSelect) {
            var prevVal = saleSelect.value;
            saleSelect.innerHTML = '<option value="">None</option>';
            salesList.forEach(function(s) {
                // filter by customer if counterpart selected and type is Sale
                if (counterpartId && type === 'Sale' && s.customer_id !== counterpartId) return;

                var label = s.sale_id;
                if (s.customer_name) label += ' — ' + s.customer_name;
                if (s.gross_sale_amount) label += ' (' + parseFloat(s.gross_sale_amount).toLocaleString() + ' F)';
                var opt = document.createElement('option');
                opt.value = s.sale_id;
                opt.textContent = label;
                saleSelect.appendChild(opt);
            });
            if (prevVal) saleSelect.value = prevVal;
        }

        // Financing: filter by counterparty_id
        var finSelect = document.getElementById('linkedFinancingId');
        if (finSelect) {
            var prevVal = finSelect.value;
            finSelect.innerHTML = '<option value="">None</option>';
            financingList.forEach(function(f) {
                // filter by counterpart if selected
                if (counterpartId && f.counterparty_id !== counterpartId) return;

                var opt = document.createElement('option');
                opt.value = f.financing_id;
                opt.textContent = f.financing_id + ' — ' + f.counterpart_name + ' (' + f.direction + ', Due: ' + parseFloat(f.balance_due).toLocaleString() + ' F)';
                finSelect.appendChild(opt);
            });
            if (prevVal) finSelect.value = prevVal;
        }
    }

    function initCounterpartyDropdown() {
        var input = document.getElementById('counterpartySearch');
        var hiddenInput = document.getElementById('counterpartId');
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
                var sel = currentCounterpartySource.find(function(c) { return c.id === hiddenInput.value; });
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
        var hiddenInput = document.getElementById('counterpartId');
        list.innerHTML = '';

        var filtered = currentCounterpartySource.filter(function(c) {
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
        document.getElementById('counterpartId').value = counterparty.id;
        document.getElementById('counterpartySearch').value = counterparty.id + ' — ' + counterparty.name;
        document.getElementById('counterpartyList').style.display = 'none';
        document.getElementById('counterpartyArrow').classList.remove('open');

        // re-filter linked dropdowns for this counterpart
        filterLinkedDropdowns(counterparty.id);
    }

    function onPaymentTypeChange() {
        var type = document.getElementById('paymentType').value;

        // Hide all linked groups
        document.getElementById('linkedPurchaseGroup').style.display = 'none';
        document.getElementById('linkedSaleGroup').style.display = 'none';
        document.getElementById('linkedFinancingGroup').style.display = 'none';

        // Clear linked selections
        document.getElementById('linkedPurchaseId').value = '';
        document.getElementById('linkedSaleId').value = '';
        document.getElementById('linkedFinancingId').value = '';

        // Clear counterparty
        document.getElementById('counterpartId').value = '';
        document.getElementById('counterpartySearch').value = '';

        if (type === 'Purchase') {
            document.getElementById('linkedPurchaseGroup').style.display = 'block';
            currentCounterpartySource = suppliersList;
            // Auto-set direction to Outgoing
            document.getElementById('dirOutgoing').checked = true;
        } else if (type === 'Sale') {
            document.getElementById('linkedSaleGroup').style.display = 'block';
            currentCounterpartySource = customersList;
            // Auto-set direction to Incoming
            document.getElementById('dirIncoming').checked = true;
        } else if (type === 'Financing') {
            document.getElementById('linkedFinancingGroup').style.display = 'block';
            // Combine customers + suppliers
            currentCounterpartySource = customersList.concat(suppliersList);
        } else if (type === 'Repayment') {
            document.getElementById('linkedFinancingGroup').style.display = 'block';
            // Combine customers + suppliers
            currentCounterpartySource = customersList.concat(suppliersList);
        } else {
            currentCounterpartySource = [];
        }

        // reset linked dropdowns (no counterpart selected yet)
        filterLinkedDropdowns('');
    }

    function loadPayments() {
        $('#skeletonLoader').show();
        $('#tableContainer').hide();

        $.ajax({
            url: '?action=getPayments',
            method: 'GET',
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    paymentsData = response.data;
                    $('#filtersSection').show();
                    populateSeasonFilter(response.data);
                    initializeDataTable(response.data);
                } else {
                    $('#skeletonLoader').hide();
                    Swal.fire({ icon: 'error', title: 'Error', text: response.message || 'Failed to load payments' });
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
        if (paymentsTable) {
            paymentsTable.destroy();
            $('#paymentsTable').empty();
        }

        var columns = [
            { data: 'payment_id', title: 'ID' },
            { data: 'date', title: 'Date' },
            {
                data: 'direction',
                title: 'Direction',
                render: function(data) {
                    var cls = directionBadgeMap[data] || 'status-pending';
                    return '<span class="status-badge ' + cls + '">' + (data || '') + '</span>';
                }
            },
            { data: 'counterpart_name', title: 'Counterparty', defaultContent: '' },
            { data: 'payment_type', title: 'Type' },
            {
                data: 'amount',
                title: 'Amount',
                render: function(data) { return data ? parseFloat(data).toLocaleString() : '0'; }
            },
            { data: 'payment_mode', title: 'Mode' },
            { data: 'reference_number', title: 'Reference', defaultContent: '' },
            { data: 'season', title: 'Season' }
        ];

        columns.push({
            data: null,
            title: 'Actions',
            orderable: false,
            render: function(data, type, row) {
                var html = '';
                html += '<button class="action-icon" onclick="printPaymentReceipt(\'' + row.payment_id + '\')" title="Print Receipt" style="color:#001f3f;"><i class="fas fa-print"></i></button> ';
                if (canUpdate) {
                    html += '<button class="action-icon edit-icon" onclick=\'editPayment(' + JSON.stringify(row).replace(/'/g, "\\'") + ')\' title="Edit"><i class="fas fa-edit"></i></button> ';
                }
                if (canDelete) {
                    html += '<button class="action-icon delete-icon" onclick="deletePayment(\'' + row.payment_id + '\')" title="Delete"><i class="fas fa-trash"></i></button>';
                }
                return html;
            }
        });

        setTimeout(function() {
            paymentsTable = $('#paymentsTable').DataTable({
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

            $('#filterDateFrom, #filterDateTo, #filterDirection, #filterPaymentType, #filterSeason').on('change', function() {
                applyFilters();
            });
        }, 100);
    }

    function applyFilters() {
        if (!paymentsTable) return;

        $.fn.dataTable.ext.search = [];

        var dateFrom = document.getElementById('filterDateFrom').value;
        var dateTo = document.getElementById('filterDateTo').value;
        var direction = document.getElementById('filterDirection').value;
        var paymentType = document.getElementById('filterPaymentType').value;
        var season = document.getElementById('filterSeason').value;

        if (dateFrom || dateTo) {
            $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
                var rawDate = paymentsData[dataIndex]?.date_raw;
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
                return paymentsData[dataIndex]?.direction === direction;
            });
        }

        if (paymentType) {
            $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
                return paymentsData[dataIndex]?.payment_type === paymentType;
            });
        }

        if (season) {
            $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
                return paymentsData[dataIndex]?.season === season;
            });
        }

        paymentsTable.draw();
    }

    function clearFilters() {
        document.getElementById('filterDateFrom').value = '';
        document.getElementById('filterDateTo').value = '';
        document.getElementById('filterDirection').value = '';
        document.getElementById('filterPaymentType').value = '';
        document.getElementById('filterSeason').value = '';

        if (paymentsTable) {
            $.fn.dataTable.ext.search = [];
            paymentsTable.columns().search('').draw();
        }
    }

    function openAddModal() {
        isEditMode = false;
        document.getElementById('modalTitle').innerHTML = '<i class="fas fa-credit-card"></i> Add Payment';
        document.getElementById('paymentForm').reset();
        document.getElementById('paymentId').value = '';
        document.getElementById('paymentIdInfo').style.display = 'none';
        document.getElementById('counterpartId').value = '';
        document.getElementById('counterpartySearch').value = '';
        document.getElementById('season').value = '2025/2026';

        // Hide linked groups
        document.getElementById('linkedPurchaseGroup').style.display = 'none';
        document.getElementById('linkedSaleGroup').style.display = 'none';
        document.getElementById('linkedFinancingGroup').style.display = 'none';

        // Reset counterparty source
        currentCounterpartySource = [];

        // Set today's date
        var today = new Date().toISOString().split('T')[0];
        document.getElementById('paymentDate').value = today;

        document.getElementById('paymentModal').classList.add('active');
    }

    function editPayment(row) {
        isEditMode = true;
        document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit"></i> Edit Payment';
        document.getElementById('paymentId').value = row.payment_id;
        document.getElementById('paymentIdInfo').style.display = 'block';
        document.getElementById('paymentIdDisplay').textContent = row.payment_id;
        document.getElementById('paymentDate').value = row.date_raw;

        // Set payment type first (triggers counterparty source switch)
        document.getElementById('paymentType').value = row.payment_type || '';
        onPaymentTypeChange();

        // Set direction
        if (row.direction === 'Outgoing') {
            document.getElementById('dirOutgoing').checked = true;
        } else if (row.direction === 'Incoming') {
            document.getElementById('dirIncoming').checked = true;
        }

        // Set counterparty
        document.getElementById('counterpartId').value = row.counterpart_id || '';
        document.getElementById('counterpartySearch').value = row.counterpart_id ? (row.counterpart_id + ' — ' + row.counterpart_name) : '';

        // Set amount and mode
        setMoneyVal('paymentAmount', row.amount);
        document.getElementById('paymentMode').value = row.payment_mode || '';
        document.getElementById('referenceNumber').value = row.reference_number || '';

        // Set linked records
        if (row.linked_purchase_id) {
            document.getElementById('linkedPurchaseId').value = row.linked_purchase_id;
        }
        if (row.linked_sale_id) {
            document.getElementById('linkedSaleId').value = row.linked_sale_id;
        }
        if (row.linked_financing_id) {
            document.getElementById('linkedFinancingId').value = row.linked_financing_id;
        }

        document.getElementById('season').value = row.season || '2025/2026';
        document.getElementById('notes').value = row.notes || '';

        document.getElementById('paymentModal').classList.add('active');
    }

    function closeModal() {
        document.getElementById('paymentModal').classList.remove('active');
        document.getElementById('paymentForm').reset();
    }

    // Click outside to close
    document.getElementById('paymentModal')?.addEventListener('click', function(e) {
        if (e.target === this) closeModal();
    });

    // Form submission
    document.getElementById('paymentForm')?.addEventListener('submit', function(e) {
        e.preventDefault();

        if (!document.getElementById('counterpartId').value) {
            Swal.fire({ icon: 'warning', title: 'Validation', text: 'Please select a counterparty' });
            return;
        }

        // Check direction is selected
        var directionChecked = document.querySelector('input[name="direction"]:checked');
        if (!directionChecked) {
            Swal.fire({ icon: 'warning', title: 'Validation', text: 'Please select a direction (Outgoing/Incoming)' });
            return;
        }

        var formData = new FormData(this);
        var action = isEditMode ? 'updatePayment' : 'addPayment';

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
                    setTimeout(function() { loadPayments(); loadDropdowns(); }, 100);
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: response.message });
                }
            },
            error: function(xhr, status, error) {
                Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error: ' + error });
            }
        });
    });

    function printPaymentReceipt(paymentId) {
        Swal.fire({
            title: 'Chargement du reçu...',
            allowOutsideClick: false,
            didOpen: function() { Swal.showLoading(); }
        });

        $.getJSON('?action=getPaymentReceipt&payment_id=' + encodeURIComponent(paymentId), function(response) {
            Swal.close();

            if (!response.success) {
                Swal.fire({ icon: 'error', title: 'Erreur', text: response.message || 'Impossible de charger le reçu' });
                return;
            }

            var p = response.data;
            var ci = response.companyInfo || {};
            var companyName = ci.company_name || response.company_name || '7503 Canada';
            var companySubtitle = ci.company_subtitle || '';
            var companyAddress = ci.company_address || '';
            var companyPhone = ci.company_phone || '';
            var companyEmail = ci.company_email || '';
            var currencySymbol = ci.currency_symbol || 'FCFA';

            // Format date as DD/MM/YYYY
            function formatDateFR(dateStr) {
                if (!dateStr) return '-';
                var d = new Date(dateStr);
                var day = String(d.getDate()).padStart(2, '0');
                var month = String(d.getMonth() + 1).padStart(2, '0');
                var year = d.getFullYear();
                return day + '/' + month + '/' + year;
            }

            // Format number French locale
            function fmtNum(n) {
                return Number(n).toLocaleString('fr-FR');
            }

            var isIncoming = p.direction === 'Incoming';
            var directionLabel = isIncoming ? 'Entrant' : 'Sortant';
            var directionColor = isIncoming ? '#1a5c2a' : '#c0392b';
            var copyLabel1 = isIncoming ? 'COPIE PAYEUR' : 'COPIE BÉNÉFICIAIRE';
            var copyLabel2 = 'COPIE COOPÉRATIVE';
            var counterpartLabel = isIncoming ? 'Payeur' : 'Bénéficiaire';

            // Linked reference
            var linkedRef = '-';
            if (p.linked_purchase_id) linkedRef = 'Achat: ' + p.linked_purchase_id;
            else if (p.linked_sale_id) linkedRef = 'Vente: ' + p.linked_sale_id;
            else if (p.linked_financing_id) linkedRef = 'Financement: ' + p.linked_financing_id;

            var now = new Date();
            var genDate = formatDateFR(now.toISOString()) + ' ' + String(now.getHours()).padStart(2, '0') + ':' + String(now.getMinutes()).padStart(2, '0');

            // Notes section
            var notesHtml = '';
            if (p.notes) {
                notesHtml = '<div class="notes-section"><strong>Notes:</strong> ' + p.notes.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</div>';
            }

            // Build one copy
            function buildCopy(copyLabel) {
                return '' +
                '<div class="receipt-copy">' +
                '  <div class="receipt-header">' +
                '    <div class="company-info-block">' +
                '      <div class="company-name">' + companyName.replace(/</g, '&lt;') + '</div>' +
                (companySubtitle ? '      <div class="company-subtitle">' + companySubtitle.replace(/</g, '&lt;') + '</div>' : '') +
                (companyAddress ? '      <div class="company-detail">' + companyAddress.replace(/</g, '&lt;') + '</div>' : '') +
                (companyPhone ? '      <div class="company-detail">Tél: ' + companyPhone.replace(/</g, '&lt;') + '</div>' : '') +
                (companyEmail ? '      <div class="company-detail">' + companyEmail.replace(/</g, '&lt;') + '</div>' : '') +
                '    </div>' +
                '    <div class="receipt-title">' +
                '      <div class="receipt-title-text">REÇU DE PAIEMENT</div>' +
                '      <div class="receipt-number">N° ' + p.payment_id + '</div>' +
                '    </div>' +
                '  </div>' +
                '  <div class="copy-badge">' + copyLabel + '</div>' +
                '  <div class="info-grid">' +
                '    <div class="info-item"><span class="info-label">Date</span><span class="info-value">' + formatDateFR(p.date) + '</span></div>' +
                '    <div class="info-item"><span class="info-label">Direction</span><span class="info-value"><span class="direction-badge" style="background:' + directionColor + ';">' + directionLabel + '</span></span></div>' +
                '    <div class="info-item"><span class="info-label">Type de Paiement</span><span class="info-value">' + (p.payment_type || '-') + '</span></div>' +
                '    <div class="info-item"><span class="info-label">' + counterpartLabel + '</span><span class="info-value">' + (p.resolved_name || p.counterpart_name || '-').replace(/</g, '&lt;') + '</span></div>' +
                '    <div class="info-item"><span class="info-label">Réf. Liée</span><span class="info-value">' + linkedRef + '</span></div>' +
                '    <div class="info-item"><span class="info-label">Saison</span><span class="info-value">' + (p.season || '-') + '</span></div>' +
                '  </div>' +
                '  <table class="details-table">' +
                '    <thead><tr><th>Description</th><th style="text-align:right;">Montant</th></tr></thead>' +
                '    <tbody>' +
                '      <tr><td>Paiement ' + (p.payment_type || '') + ' — ' + (p.payment_mode || '') + (p.reference_number ? ' (Réf: ' + p.reference_number + ')' : '') + '</td><td style="text-align:right;">' + fmtNum(p.amount) + ' ' + currencySymbol + '</td></tr>' +
                '    </tbody>' +
                '  </table>' +
                '  <div class="total-line"><span class="total-label">MONTANT TOTAL:</span> <span class="total-value">' + fmtNum(p.amount) + ' ' + currencySymbol + '</span></div>' +
                notesHtml +
                '  <div class="signatures">' +
                '    <div class="sig-block"><div class="sig-line"></div><div class="sig-label">' + counterpartLabel + '</div></div>' +
                '    <div class="sig-block"><div class="sig-line"></div><div class="sig-label">Pour ' + companyName.replace(/</g, '&lt;') + '</div></div>' +
                '  </div>' +
                '  <div class="receipt-footer">' + companyName.replace(/</g, '&lt;') + ' — Généré le ' + genDate + '</div>' +
                '</div>';
            }

            // WhatsApp msg
            var waMsg = '\u{1F9FE} *Reçu de Paiement*\n\n';
            waMsg += '*N°:* ' + p.payment_id + '\n';
            waMsg += '*Date:* ' + formatDateFR(p.date) + '\n';
            waMsg += '*Direction:* ' + directionLabel + '\n';
            waMsg += '*Type:* ' + (p.payment_type || '') + '\n';
            waMsg += '*Montant:* ' + fmtNum(p.amount) + ' ' + currencySymbol + '\n';
            waMsg += '*Mode:* ' + (p.payment_mode || '') + '\n';
            if (p.reference_number) waMsg += '*Référence:* ' + p.reference_number + '\n';
            if (p.notes) waMsg += '*Notes:* ' + p.notes + '\n';
            waMsg += '\n_' + companyName + '_';

            var counterpartPhone = p.counterpart_phone || '';
            var waPhone = counterpartPhone.replace(/[^0-9]/g, '');
            var waUrl = 'https://wa.me/' + waPhone + '?text=' + encodeURIComponent(waMsg);

            var printContent = '' +
            '<!DOCTYPE html>' +
            '<html lang="fr">' +
            '<head>' +
            '<meta charset="UTF-8">' +
            '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">' +
            '<title>Reçu ' + p.payment_id + '</title>' +
            '<style>' +
            '@page { size: A4; margin: 10mm; }' +
            '* { margin: 0; padding: 0; box-sizing: border-box; }' +
            'body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; font-size: 11px; color: #222; line-height: 1.4; }' +
            '.receipt-copy { padding: 15px 20px; page-break-inside: avoid; }' +
            '.receipt-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px; padding-bottom: 8px; border-bottom: 2px solid #1a5c2a; }' +
            '.company-name { font-size: 18px; font-weight: 700; color: #1a5c2a; }' +
            '.receipt-title { text-align: right; }' +
            '.receipt-title-text { font-size: 14px; font-weight: 700; color: #1a5c2a; text-transform: uppercase; }' +
            '.receipt-number { font-size: 11px; color: #555; margin-top: 2px; }' +
            '.copy-badge { display: inline-block; background: #1a5c2a; color: #fff; padding: 3px 12px; border-radius: 3px; font-size: 10px; font-weight: 700; letter-spacing: 1px; margin-bottom: 10px; }' +
            '.info-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 6px 12px; margin-bottom: 12px; padding: 8px; background: #f8f9fa; border-radius: 4px; }' +
            '.info-item { display: flex; flex-direction: column; }' +
            '.info-label { font-size: 9px; color: #777; text-transform: uppercase; font-weight: 600; letter-spacing: 0.5px; }' +
            '.info-value { font-size: 11px; font-weight: 600; color: #222; }' +
            '.direction-badge { display: inline-block; color: #fff; padding: 1px 8px; border-radius: 3px; font-size: 10px; font-weight: 600; }' +
            '.details-table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }' +
            '.details-table thead th { background: #1a5c2a; color: #fff; padding: 6px 10px; font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; text-align: left; }' +
            '.details-table tbody td { padding: 6px 10px; border-bottom: 1px solid #ddd; font-size: 11px; }' +
            '.company-info-block { }' +
            '.company-subtitle { font-size: 10px; color: #555; font-style: italic; }' +
            '.company-detail { font-size: 9px; color: #666; }' +
            '.total-line { text-align: right; margin-bottom: 12px; padding: 6px 10px; border-top: 2px solid #1a5c2a; }' +
            '.total-label { font-size: 11px; font-weight: 700; color: #1a5c2a; text-transform: uppercase; }' +
            '.total-value { font-size: 13px; font-weight: 700; color: #1a5c2a; }' +
            '.notes-section { background: #f8f9fa; padding: 6px 10px; border-radius: 4px; margin-bottom: 10px; font-size: 10px; color: #444; }' +
            '.signatures { display: flex; justify-content: space-between; margin-top: 15px; margin-bottom: 8px; }' +
            '.sig-block { width: 40%; text-align: center; }' +
            '.sig-line { border-bottom: 1px solid #333; margin-bottom: 4px; height: 30px; }' +
            '.sig-label { font-size: 9px; color: #555; }' +
            '.receipt-footer { text-align: center; font-size: 8px; color: #999; padding-top: 5px; border-top: 1px solid #eee; }' +
            '.cut-line { border: none; border-top: 2px dashed #999; margin: 8px 0; position: relative; }' +
            '.cut-line-text { position: absolute; top: -8px; left: 50%; transform: translateX(-50%); background: #fff; padding: 0 10px; font-size: 8px; color: #999; letter-spacing: 1px; }' +
            '@media print { body { -webkit-print-color-adjust: exact; print-color-adjust: exact; } .no-print { display: none !important; } }' +
            '</style>' +
            '</head>' +
            '<body>' +
            '<div style="text-align:center;margin:20px 0;" class="no-print">' +
            (waPhone ? '<a href="' + waUrl + '" target="_blank" style="display:inline-block;background:#25D366;color:white;padding:10px 24px;border-radius:6px;text-decoration:none;font-weight:600;font-size:14px;margin-right:10px;"><i class="fab fa-whatsapp" style="margin-right:6px;"></i>Envoyer via WhatsApp</a>' : '') +
            '<button onclick="window.print()" style="display:inline-block;background:#001f3f;color:white;padding:10px 24px;border-radius:6px;border:none;cursor:pointer;font-weight:600;font-size:14px;"><i class="fas fa-print" style="margin-right:6px;"></i>Imprimer</button>' +
            '</div>' +
            buildCopy(copyLabel1) +
            '<div style="position:relative;margin:0 20px;">' +
            '  <hr class="cut-line">' +
            '  <span class="cut-line-text">✂ COUPER ICI / DÉTACHER</span>' +
            '</div>' +
            buildCopy(copyLabel2) +
            '</body>' +
            '</html>';

            var printWindow = window.open('', '_blank', 'width=800,height=1000');
            if (printWindow) {
                printWindow.document.write(printContent);
                printWindow.document.close();
                printWindow.focus();
            } else {
                Swal.fire({ icon: 'error', title: 'Erreur', text: 'Impossible d\'ouvrir la fenêtre d\'impression. Vérifiez le bloqueur de pop-ups.' });
            }
        }).fail(function() {
            Swal.close();
            Swal.fire({ icon: 'error', title: 'Erreur de connexion', text: 'Impossible de contacter le serveur.' });
        });
    }

    function deletePayment(paymentId) {
        Swal.fire({
            title: 'Delete Payment?',
            text: 'This action cannot be undone. Linked records will be recalculated.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            confirmButtonText: 'Yes, delete it!'
        }).then(function(result) {
            if (result.isConfirmed) {
                var formData = new FormData();
                formData.append('payment_id', paymentId);

                $.ajax({
                    url: '?action=deletePayment',
                    method: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            Swal.fire({ icon: 'success', title: 'Deleted!', text: response.message, timer: 2000, showConfirmButton: false });
                            loadPayments();
                            loadDropdowns();
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
