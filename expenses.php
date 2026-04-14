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
$current_page = 'expenses';

// RBAC
$allowedRoles = ['Admin', 'Manager', 'Finance Officer', 'Procurement Officer', 'Warehouse Clerk'];
if (!in_array($role, $allowedRoles)) {
    header("Location: dashboard.php");
    exit();
}

$canCreate = in_array($role, ['Admin', 'Manager', 'Finance Officer', 'Procurement Officer', 'Warehouse Clerk']);
$canUpdate = in_array($role, ['Admin', 'Manager', 'Finance Officer', 'Procurement Officer']);
$canDelete = ($role === 'Admin');
$canApprove = in_array($role, ['Admin', 'Manager', 'Procurement Officer']);
$isReadOnly = false;

// Handle AJAX requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    try {
        switch ($_GET['action']) {
            case 'getExpenses':
                $conn = getDBConnection();
                $stmt = $conn->prepare("SELECT e.*, ec.category_name, d.delivery_id as del_display, d.customer_name as del_customer, p.purchase_id as pur_display, p.supplier_name as pur_supplier, u.full_name as submitted_by_name, rv.full_name as reviewed_by_name
                    FROM expenses e
                    LEFT JOIN settings_expense_categories ec ON e.category_id = ec.category_id
                    LEFT JOIN deliveries d ON e.linked_delivery_id = d.delivery_id
                    LEFT JOIN purchases p ON e.linked_purchase_id = p.purchase_id
                    LEFT JOIN users u ON e.submitted_by = u.id
                    LEFT JOIN users rv ON e.reviewed_by = rv.id
                    ORDER BY e.expense_id DESC");
                $stmt->execute();
                $result = $stmt->get_result();

                $expenses = [];
                while ($row = $result->fetch_assoc()) {
                    $expenses[] = [
                        'expense_id' => $row['expense_id'],
                        'date' => date('M d, Y', strtotime($row['date'])),
                        'date_raw' => $row['date'],
                        'category_id' => $row['category_id'],
                        'category_name' => $row['category_name'] ?? '',
                        'description' => $row['description'],
                        'amount' => $row['amount'],
                        'linked_delivery_id' => $row['linked_delivery_id'],
                        'del_display' => $row['del_display'],
                        'del_customer' => $row['del_customer'],
                        'linked_purchase_id' => $row['linked_purchase_id'],
                        'pur_display' => $row['pur_display'],
                        'pur_supplier' => $row['pur_supplier'],
                        'paid_to' => $row['paid_to'],
                        'receipt_number' => $row['receipt_number'],
                        'season' => $row['season'],
                        'status' => $row['status'] ?? 'Approved',
                        'submitted_by' => $row['submitted_by'],
                        'submitted_by_name' => $row['submitted_by_name'] ?? '',
                        'reviewed_by_name' => $row['reviewed_by_name'] ?? '',
                        'reviewed_at' => $row['reviewed_at']
                    ];
                }

                $stmt->close();
                $conn->close();

                echo json_encode(['success' => true, 'data' => $expenses]);
                exit();

            case 'getDropdowns':
                $conn = getDBConnection();

                // Active expense categories
                $categories = [];
                $stmt = $conn->prepare("SELECT category_id, category_name FROM settings_expense_categories WHERE is_active = 1 ORDER BY category_name ASC");
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $categories[] = $row;
                }
                $stmt->close();

                // Deliveries
                $deliveries = [];
                $stmt = $conn->prepare("SELECT delivery_id, customer_name, date FROM deliveries ORDER BY delivery_id DESC");
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $deliveries[] = $row;
                }
                $stmt->close();

                // Purchases
                $purchases = [];
                $stmt = $conn->prepare("SELECT purchase_id, supplier_name, date FROM purchases ORDER BY purchase_id DESC");
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $purchases[] = $row;
                }
                $stmt->close();

                $conn->close();

                echo json_encode([
                    'success' => true,
                    'data' => [
                        'categories' => $categories,
                        'deliveries' => $deliveries,
                        'purchases' => $purchases
                    ]
                ]);
                exit();

            case 'addExpense':
                if (!$canCreate) {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $date = isset($_POST['date']) ? trim($_POST['date']) : '';
                $categoryId = isset($_POST['category_id']) ? intval($_POST['category_id']) : 0;
                $description = isset($_POST['description']) ? trim($_POST['description']) : '';
                $amount = isset($_POST['amount']) ? floatval($_POST['amount']) : 0;
                $linkedDeliveryId = !empty($_POST['linked_delivery_id']) ? trim($_POST['linked_delivery_id']) : null;
                $linkedPurchaseId = !empty($_POST['linked_purchase_id']) ? trim($_POST['linked_purchase_id']) : null;
                $paidTo = !empty($_POST['paid_to']) ? trim($_POST['paid_to']) : null;
                $receiptNumber = !empty($_POST['receipt_number']) ? trim($_POST['receipt_number']) : null;
                $season = isset($_POST['season']) ? trim($_POST['season']) : getActiveSeason();

                // Validation
                if (empty($date)) {
                    echo json_encode(['success' => false, 'message' => 'Date is required']);
                    exit();
                }
                if ($categoryId <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Category is required']);
                    exit();
                }
                if (empty($description)) {
                    echo json_encode(['success' => false, 'message' => 'Description is required']);
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

                // Auto-generate expense_id (DEP-YY-MMDD-XXXX-C)
                $newId = generateTransactionId($conn, 'DEP', 'expenses', 'expense_id');

                // Warehouse Clerk expenses need approval
                $expenseStatus = ($role === 'Warehouse Clerk') ? 'Pending' : 'Approved';

                $stmt = $conn->prepare("INSERT INTO expenses (expense_id, date, category_id, description, amount, linked_delivery_id, linked_purchase_id, paid_to, receipt_number, season, status, submitted_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("ssisdssssssi",
                    $newId, $date, $categoryId, $description, $amount,
                    $linkedDeliveryId, $linkedPurchaseId, $paidTo, $receiptNumber, $season, $expenseStatus, $user_id
                );

                if ($stmt->execute()) {
                    $statusMsg = ($expenseStatus === 'Pending') ? ' (Pending Approval)' : '';
                    logActivity($user_id, $username, 'Expense Created', "Created expense: $newId, Amount: $amount, Status: $expenseStatus, Description: $description");
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => true, 'message' => 'Expense added successfully' . $statusMsg, 'expense_id' => $newId]);
                } else {
                    $error = $stmt->error;
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to add expense: ' . $error]);
                }
                exit();

            case 'updateExpense':
                if (!$canUpdate) {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $expenseId = isset($_POST['expense_id']) ? trim($_POST['expense_id']) : '';
                $date = isset($_POST['date']) ? trim($_POST['date']) : '';
                $categoryId = isset($_POST['category_id']) ? intval($_POST['category_id']) : 0;
                $description = isset($_POST['description']) ? trim($_POST['description']) : '';
                $amount = isset($_POST['amount']) ? floatval($_POST['amount']) : 0;
                $linkedDeliveryId = !empty($_POST['linked_delivery_id']) ? trim($_POST['linked_delivery_id']) : null;
                $linkedPurchaseId = !empty($_POST['linked_purchase_id']) ? trim($_POST['linked_purchase_id']) : null;
                $paidTo = !empty($_POST['paid_to']) ? trim($_POST['paid_to']) : null;
                $receiptNumber = !empty($_POST['receipt_number']) ? trim($_POST['receipt_number']) : null;
                $season = isset($_POST['season']) ? trim($_POST['season']) : getActiveSeason();

                // Validation
                if (empty($expenseId)) {
                    echo json_encode(['success' => false, 'message' => 'Expense ID is required']);
                    exit();
                }
                if (empty($date)) {
                    echo json_encode(['success' => false, 'message' => 'Date is required']);
                    exit();
                }
                if ($categoryId <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Category is required']);
                    exit();
                }
                if (empty($description)) {
                    echo json_encode(['success' => false, 'message' => 'Description is required']);
                    exit();
                }
                if ($amount <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Amount must be greater than 0']);
                    exit();
                }

                $conn = getDBConnection();

                // Check if expense exists
                $stmt = $conn->prepare("SELECT expense_id FROM expenses WHERE expense_id = ?");
                $stmt->bind_param("s", $expenseId);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($res->num_rows === 0) {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Expense not found']);
                    exit();
                }
                $stmt->close();

                $stmt = $conn->prepare("UPDATE expenses SET date = ?, category_id = ?, description = ?, amount = ?, linked_delivery_id = ?, linked_purchase_id = ?, paid_to = ?, receipt_number = ?, season = ? WHERE expense_id = ?");
                $stmt->bind_param("sisdssssss",
                    $date, $categoryId, $description, $amount,
                    $linkedDeliveryId, $linkedPurchaseId, $paidTo, $receiptNumber, $season, $expenseId
                );

                if ($stmt->execute()) {
                    logActivity($user_id, $username, 'Expense Updated', "Updated expense: $expenseId, Amount: $amount");
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => true, 'message' => 'Expense updated successfully']);
                } else {
                    $error = $stmt->error;
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to update expense: ' . $error]);
                }
                exit();

            case 'deleteExpense':
                if (!$canDelete) {
                    echo json_encode(['success' => false, 'message' => 'Access denied. Only Admin can delete.']);
                    exit();
                }

                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $expenseId = isset($_POST['expense_id']) ? trim($_POST['expense_id']) : '';
                if (empty($expenseId)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid expense ID']);
                    exit();
                }

                $conn = getDBConnection();

                // Get info for logging
                $stmt = $conn->prepare("SELECT description, amount FROM expenses WHERE expense_id = ?");
                $stmt->bind_param("s", $expenseId);
                $stmt->execute();
                $info = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$info) {
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Expense not found']);
                    exit();
                }

                $stmt = $conn->prepare("DELETE FROM expenses WHERE expense_id = ?");
                $stmt->bind_param("s", $expenseId);

                if ($stmt->execute()) {
                    logActivity($user_id, $username, 'Expense Deleted', "Deleted expense: $expenseId (Description: {$info['description']}, Amount: {$info['amount']})");
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => true, 'message' => 'Expense deleted successfully']);
                } else {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to delete expense']);
                }
                exit();

            case 'reviewExpense':
                if (!$canApprove) {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $expenseId = isset($_POST['expense_id']) ? trim($_POST['expense_id']) : '';
                $newStatus = isset($_POST['status']) ? trim($_POST['status']) : '';

                if (empty($expenseId) || !in_array($newStatus, ['Approved', 'Rejected'])) {
                    echo json_encode(['success' => false, 'message' => 'Invalid expense ID or status']);
                    exit();
                }

                $conn = getDBConnection();

                // verify expense exists and is pending
                $stmt = $conn->prepare("SELECT expense_id, description, amount, status FROM expenses WHERE expense_id = ?");
                $stmt->bind_param("s", $expenseId);
                $stmt->execute();
                $exp = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$exp) {
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Expense not found']);
                    exit();
                }

                if ($exp['status'] !== 'Pending') {
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Expense is already ' . $exp['status']]);
                    exit();
                }

                $stmt = $conn->prepare("UPDATE expenses SET status = ?, reviewed_by = ?, reviewed_at = NOW() WHERE expense_id = ?");
                $stmt->bind_param("sis", $newStatus, $user_id, $expenseId);

                if ($stmt->execute()) {
                    logActivity($user_id, $username, 'Expense ' . $newStatus, "$newStatus expense: $expenseId, Amount: {$exp['amount']}, Description: {$exp['description']}");
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => true, 'message' => 'Expense ' . strtolower($newStatus) . ' successfully']);
                } else {
                    $error = $stmt->error;
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to update expense: ' . $error]);
                }
                exit();

            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
                exit();
        }
    } catch (Exception $e) {
        error_log("expenses.php error: " . $e->getMessage());
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
    <title>Expenses - Dashboard</title>

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
                <h1><i class="fas fa-file-invoice-dollar"></i> Expenses</h1>
                <div>Welcome, <?php echo htmlspecialchars($username); ?></div>
            </div>

            <div class="data-section">
                <div class="section-header">
                    <h2><i class="fas fa-table"></i> Expenses</h2>
                    <div class="section-header-actions">
                        <button class="btn btn-primary" onclick="loadExpenses()">
                            <i class="fas fa-sync"></i> Refresh
                        </button>
                        <?php if ($canCreate): ?>
                        <button class="btn btn-success" onclick="openAddModal()">
                            <i class="fas fa-plus"></i> Add Expense
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
                            <label><i class="fas fa-tags"></i> Category</label>
                            <select id="filterCategory" class="filter-input">
                                <option value="">All Categories</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-leaf"></i> Season</label>
                            <select id="filterSeason" class="filter-input">
                                <option value="">All Seasons</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-check-circle"></i> Status</label>
                            <select id="filterStatus" class="filter-input">
                                <option value="">All Statuses</option>
                                <option value="Pending">Pending</option>
                                <option value="Approved">Approved</option>
                                <option value="Rejected">Rejected</option>
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
                        <table id="expensesTable" class="display" style="width:100%"></table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($canCreate || $canUpdate): ?>
    <div class="modal-overlay" id="expenseModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 id="modalTitle"><i class="fas fa-file-invoice-dollar"></i> Add Expense</h3>
                <button class="close-btn" onclick="closeModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <div id="expenseIdInfo" class="form-id-info" style="display: none;">
                    <strong><i class="fas fa-id-badge"></i> Expense ID:</strong> <span id="expenseIdDisplay"></span>
                </div>

                <form id="expenseForm">
                    <input type="hidden" id="expenseId" name="expense_id">

                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-calendar-day"></i> Date *</label>
                            <input type="date" id="expenseDate" name="date" required>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-tags"></i> Category *</label>
                            <select id="categoryId" name="category_id" required>
                                <option value="">Select category...</option>
                            </select>
                        </div>

                        <div class="form-group" style="grid-column: 1 / -1;">
                            <label><i class="fas fa-align-left"></i> Description *</label>
                            <textarea id="description" name="description" maxlength="500" rows="2" required placeholder="Enter expense description..."></textarea>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-dollar-sign"></i> Amount *</label>
                            <input type="text" inputmode="decimal" id="amount" name="amount" class="money-input" required placeholder="0.00">
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-truck"></i> Linked Delivery</label>
                            <select id="linkedDeliveryId" name="linked_delivery_id">
                                <option value="">None</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-shopping-cart"></i> Linked Purchase</label>
                            <select id="linkedPurchaseId" name="linked_purchase_id">
                                <option value="">None</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-user"></i> Paid To</label>
                            <input type="text" id="paidTo" name="paid_to" placeholder="Enter payee name" maxlength="200">
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-receipt"></i> Receipt Number</label>
                            <input type="text" id="receiptNumber" name="receipt_number" placeholder="Enter receipt #" maxlength="50">
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
    // Global variables
    let expensesTable;
    let isEditMode = false;
    let expensesData = [];
    let categoriesList = [];
    let deliveriesList = [];
    let purchasesList = [];

    var ACTIVE_SEASON = '<?php echo addslashes(getActiveSeason()); ?>';
    const isReadOnly = <?php echo $isReadOnly ? 'true' : 'false'; ?>;
    const canCreate = <?php echo $canCreate ? 'true' : 'false'; ?>;
    const canUpdate = <?php echo $canUpdate ? 'true' : 'false'; ?>;
    const canDelete = <?php echo $canDelete ? 'true' : 'false'; ?>;
    const canApprove = <?php echo $canApprove ? 'true' : 'false'; ?>;

    $(document).ready(function() {
        loadDropdowns();
        loadExpenses();
    });

    function loadDropdowns() {
        $.ajax({
            url: '?action=getDropdowns',
            method: 'GET',
            dataType: 'json',
            success: function(response) {
                if (response.success && response.data) {
                    categoriesList = response.data.categories;
                    deliveriesList = response.data.deliveries;
                    purchasesList = response.data.purchases;

                    // Populate category dropdown
                    var catSelect = document.getElementById('categoryId');
                    if (catSelect) {
                        catSelect.innerHTML = '<option value="">Select category...</option>';
                        categoriesList.forEach(function(c) {
                            var opt = document.createElement('option');
                            opt.value = c.category_id;
                            opt.textContent = c.category_name;
                            catSelect.appendChild(opt);
                        });
                    }

                    // Populate category filter
                    var filterCat = document.getElementById('filterCategory');
                    if (filterCat) {
                        filterCat.innerHTML = '<option value="">All Categories</option>';
                        categoriesList.forEach(function(c) {
                            var opt = document.createElement('option');
                            opt.value = c.category_name;
                            opt.textContent = c.category_name;
                            filterCat.appendChild(opt);
                        });
                    }

                    // Populate linked delivery dropdown
                    var delSelect = document.getElementById('linkedDeliveryId');
                    if (delSelect) {
                        delSelect.innerHTML = '<option value="">None</option>';
                        deliveriesList.forEach(function(d) {
                            var opt = document.createElement('option');
                            opt.value = d.delivery_id;
                            opt.textContent = d.delivery_id + ' — ' + d.customer_name;
                            delSelect.appendChild(opt);
                        });
                    }

                    // Populate linked purchase dropdown
                    var purSelect = document.getElementById('linkedPurchaseId');
                    if (purSelect) {
                        purSelect.innerHTML = '<option value="">None</option>';
                        purchasesList.forEach(function(p) {
                            var opt = document.createElement('option');
                            opt.value = p.purchase_id;
                            opt.textContent = p.purchase_id + ' — ' + p.supplier_name;
                            purSelect.appendChild(opt);
                        });
                    }
                }
            }
        });
    }

    function loadExpenses() {
        $('#skeletonLoader').show();
        $('#tableContainer').hide();

        $.ajax({
            url: '?action=getExpenses',
            method: 'GET',
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    expensesData = response.data;
                    $('#filtersSection').show();
                    populateSeasonFilter(response.data);
                    initializeDataTable(response.data);
                } else {
                    $('#skeletonLoader').hide();
                    Swal.fire({ icon: 'error', title: 'Error', text: response.message || 'Failed to load expenses' });
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
        if (expensesTable) {
            expensesTable.destroy();
            $('#expensesTable').empty();
        }

        var columns = [
            { data: 'expense_id', title: 'ID' },
            { data: 'date', title: 'Date' },
            { data: 'category_name', title: 'Category', defaultContent: '' },
            {
                data: 'description',
                title: 'Description',
                render: function(data) {
                    if (!data) return '';
                    return data.length > 60 ? data.substring(0, 60) + '...' : data;
                }
            },
            {
                data: 'amount',
                title: 'Amount',
                render: function(data) { return data ? parseFloat(data).toLocaleString() : '0'; }
            },
            { data: 'paid_to', title: 'Paid To', defaultContent: '' },
            { data: 'receipt_number', title: 'Receipt #', defaultContent: '' },
            { data: 'season', title: 'Season' },
            {
                data: 'status',
                title: 'Status',
                render: function(data) {
                    if (!data) return '';
                    var colors = { 'Pending': '#e67e22', 'Approved': '#27ae60', 'Rejected': '#e74c3c' };
                    var icons = { 'Pending': 'clock', 'Approved': 'check-circle', 'Rejected': 'times-circle' };
                    return '<span style="background:' + colors[data] + ';color:#fff;padding:3px 10px;border-radius:12px;font-size:12px;font-weight:600;white-space:nowrap;"><i class="fas fa-' + icons[data] + '"></i> ' + data + '</span>';
                }
            }
        ];

        // show actions col if user can do anything
        if (canUpdate || canDelete || canApprove) {
            columns.push({
                data: null,
                title: 'Actions',
                orderable: false,
                render: function(data, type, row) {
                    var html = '';
                    if (canUpdate) {
                        html += '<button class="action-icon edit-icon" onclick=\'editExpense(' + JSON.stringify(row).replace(/'/g, "\\'") + ')\' title="Edit"><i class="fas fa-edit"></i></button> ';
                    }
                    if (canDelete) {
                        html += '<button class="action-icon delete-icon" onclick="deleteExpense(\'' + row.expense_id + '\')" title="Delete"><i class="fas fa-trash"></i></button> ';
                    }
                    // approve/reject buttons for pending expenses
                    if (canApprove && row.status === 'Pending') {
                        html += '<button class="action-icon" style="color:#27ae60;" onclick="reviewExpense(\'' + row.expense_id + '\', \'Approved\')" title="Approve"><i class="fas fa-check-circle"></i></button> ';
                        html += '<button class="action-icon" style="color:#e74c3c;" onclick="reviewExpense(\'' + row.expense_id + '\', \'Rejected\')" title="Reject"><i class="fas fa-times-circle"></i></button>';
                    }
                    return html;
                }
            });
        }

        setTimeout(function() {
            expensesTable = $('#expensesTable').DataTable({
                data: data,
                destroy: true,
                columns: columns,
                pageLength: 50,
                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
                responsive: true,
                dom: 'Blfrtip',
                buttons: [
                    { extend: 'csv', text: '<i class="fas fa-file-csv"></i> CSV', exportOptions: { columns: (canUpdate || canDelete || canApprove) ? ':not(:last-child)' : ':visible' } },
                    { extend: 'pdf', text: '<i class="fas fa-file-pdf"></i> PDF', exportOptions: { columns: (canUpdate || canDelete || canApprove) ? ':not(:last-child)' : ':visible' } },
                    { extend: 'print', text: '<i class="fas fa-print"></i> Print', exportOptions: { columns: (canUpdate || canDelete || canApprove) ? ':not(:last-child)' : ':visible' } }
                ],
                order: [[0, 'desc']]
            });

            $('#skeletonLoader').hide();
            $('#tableContainer').show();

            $('#filterDateFrom, #filterDateTo, #filterCategory, #filterSeason, #filterStatus').on('change', function() {
                applyFilters();
            });
        }, 100);
    }

    function applyFilters() {
        if (!expensesTable) return;

        $.fn.dataTable.ext.search = [];

        var dateFrom = document.getElementById('filterDateFrom').value;
        var dateTo = document.getElementById('filterDateTo').value;
        var category = document.getElementById('filterCategory').value;
        var season = document.getElementById('filterSeason').value;
        var status = document.getElementById('filterStatus').value;

        if (dateFrom || dateTo) {
            $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
                var rawDate = expensesData[dataIndex]?.date_raw;
                if (!rawDate) return true;
                var recordDate = new Date(rawDate);
                var fromDate = dateFrom ? new Date(dateFrom) : null;
                var toDate = dateTo ? new Date(dateTo + 'T23:59:59') : null;
                if (fromDate && recordDate < fromDate) return false;
                if (toDate && recordDate > toDate) return false;
                return true;
            });
        }

        if (category) {
            $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
                return expensesData[dataIndex]?.category_name === category;
            });
        }

        if (season) {
            $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
                return expensesData[dataIndex]?.season === season;
            });
        }

        if (status) {
            $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
                return expensesData[dataIndex]?.status === status;
            });
        }

        expensesTable.draw();
    }

    function clearFilters() {
        document.getElementById('filterDateFrom').value = '';
        document.getElementById('filterDateTo').value = '';
        document.getElementById('filterCategory').value = '';
        document.getElementById('filterSeason').value = '';
        document.getElementById('filterStatus').value = '';

        if (expensesTable) {
            $.fn.dataTable.ext.search = [];
            expensesTable.columns().search('').draw();
        }
    }

    function openAddModal() {
        isEditMode = false;
        document.getElementById('modalTitle').innerHTML = '<i class="fas fa-file-invoice-dollar"></i> Add Expense';
        document.getElementById('expenseForm').reset();
        document.getElementById('expenseId').value = '';
        document.getElementById('expenseIdInfo').style.display = 'none';
        document.getElementById('season').value = ACTIVE_SEASON;

        var today = new Date().toISOString().split('T')[0];
        document.getElementById('expenseDate').value = today;

        document.getElementById('expenseModal').classList.add('active');
    }

    function editExpense(row) {
        isEditMode = true;
        document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit"></i> Edit Expense';
        document.getElementById('expenseId').value = row.expense_id;
        document.getElementById('expenseIdInfo').style.display = 'block';
        document.getElementById('expenseIdDisplay').textContent = row.expense_id;
        document.getElementById('expenseDate').value = row.date_raw;
        document.getElementById('categoryId').value = row.category_id || '';
        document.getElementById('description').value = row.description || '';
        setMoneyVal('amount', row.amount);
        document.getElementById('linkedDeliveryId').value = row.linked_delivery_id || '';
        document.getElementById('linkedPurchaseId').value = row.linked_purchase_id || '';
        document.getElementById('paidTo').value = row.paid_to || '';
        document.getElementById('receiptNumber').value = row.receipt_number || '';
        document.getElementById('season').value = row.season || ACTIVE_SEASON;

        document.getElementById('expenseModal').classList.add('active');
    }

    function closeModal() {
        document.getElementById('expenseModal').classList.remove('active');
        document.getElementById('expenseForm').reset();
    }

    // Click outside to close
    document.getElementById('expenseModal')?.addEventListener('click', function(e) {
        if (e.target === this) closeModal();
    });

    // Form submission
    document.getElementById('expenseForm')?.addEventListener('submit', function(e) {
        e.preventDefault();

        var formData = new FormData(this);
        var action = isEditMode ? 'updateExpense' : 'addExpense';

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
                    setTimeout(function() { loadExpenses(); }, 100);
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: response.message });
                }
            },
            error: function(xhr, status, error) {
                Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error: ' + error });
            }
        });
    });

    function deleteExpense(expenseId) {
        Swal.fire({
            title: 'Delete Expense?',
            text: 'This action cannot be undone.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            confirmButtonText: 'Yes, delete it!'
        }).then(function(result) {
            if (result.isConfirmed) {
                var formData = new FormData();
                formData.append('expense_id', expenseId);

                $.ajax({
                    url: '?action=deleteExpense',
                    method: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            Swal.fire({ icon: 'success', title: 'Deleted!', text: response.message, timer: 2000, showConfirmButton: false });
                            loadExpenses();
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

    function reviewExpense(expenseId, newStatus) {
        var actionWord = newStatus === 'Approved' ? 'approve' : 'reject';
        var iconColor = newStatus === 'Approved' ? '#27ae60' : '#e74c3c';

        Swal.fire({
            title: newStatus === 'Approved' ? 'Approve Expense?' : 'Reject Expense?',
            text: 'Are you sure you want to ' + actionWord + ' this expense?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: iconColor,
            confirmButtonText: 'Yes, ' + actionWord + ' it!'
        }).then(function(result) {
            if (result.isConfirmed) {
                Swal.fire({ title: 'Processing...', allowOutsideClick: false, didOpen: function() { Swal.showLoading(); } });

                var formData = new FormData();
                formData.append('expense_id', expenseId);
                formData.append('status', newStatus);

                $.ajax({
                    url: '?action=reviewExpense',
                    method: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            Swal.fire({ icon: 'success', title: 'Done!', text: response.message, timer: 2000, showConfirmButton: false });
                            loadExpenses();
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
