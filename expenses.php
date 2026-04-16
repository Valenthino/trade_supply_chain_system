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
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Commodity Flow &mdash; Expenses</title>

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

  <!-- Font Awesome 6.4.0 -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />

  <!-- DataTables CSS -->
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/jquery.dataTables.min.css" />
  <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.dataTables.min.css" />
  <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.dataTables.min.css" />

  <!-- App Stylesheet -->
  <link rel="stylesheet" href="styles.css?v=5.0" />

  <!-- JS Libraries -->
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
    /* Scrollbar */
    ::-webkit-scrollbar { width: 5px; height: 5px; }
    ::-webkit-scrollbar-track { background: transparent; }
    ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
    .dark ::-webkit-scrollbar-thumb { background: #334155; }

    /* Skeleton loader */
    @keyframes shimmer { 0%{background-position:-400px 0} 100%{background-position:400px 0} }
    .skeleton {
      background: linear-gradient(90deg, #f1f5f9 25%, #e2e8f0 50%, #f1f5f9 75%);
      background-size: 400px 100%; animation: shimmer 1.4s ease infinite; border-radius: 6px;
    }
    .dark .skeleton {
      background: linear-gradient(90deg, #1e293b 25%, #273349 50%, #1e293b 75%);
      background-size: 400px 100%;
    }
    .skeleton-row { display: flex; gap: 12px; padding: 14px 16px; border-bottom: 1px solid #f1f5f9; }
    .dark .skeleton-row { border-color: #1e293b; }
    .skeleton-cell { height: 16px; border-radius: 4px; flex: 1; }

    /* Sidebar transitions */
    #sidebar { transition: width 280ms cubic-bezier(.16,1,.3,1); }
    .sidebar-label { transition: opacity 200ms, width 200ms; }
    .app-collapsed #sidebar { width: 64px; }
    .app-collapsed .sidebar-label { opacity: 0; width: 0; overflow: hidden; }
    .app-collapsed .sidebar-section-label { opacity: 0; }
    .app-collapsed .logo-text { opacity: 0; width: 0; overflow: hidden; }

    /* Nav active state */
    .nav-link.active { background: rgba(45,157,153,0.12); color: #2d9d99; }
    .dark .nav-link.active { background: rgba(45,157,153,0.15); color: #4db8b4; }
    .nav-link.active .nav-icon { color: #2d9d99; }
    .dark .nav-link.active .nav-icon { color: #4db8b4; }
    .nav-link.active::before {
      content: ''; position: absolute; left: 0; top: 15%; bottom: 15%;
      width: 3px; background: #2d9d99; border-radius: 0 3px 3px 0;
    }

    /* DataTables overrides */
    .dataTables_wrapper { font-size: 13px; color: inherit; }
    .dataTables_wrapper .dataTables_length,
    .dataTables_wrapper .dataTables_filter,
    .dataTables_wrapper .dataTables_info,
    .dataTables_wrapper .dataTables_paginate { padding: 12px 16px; font-size: 13px; }
    .dataTables_wrapper .dataTables_filter input {
      border: 1px solid #e2e8f0; border-radius: 8px; padding: 6px 12px; font-size: 13px;
      background: #f8fafc; outline: none; transition: border-color 200ms;
    }
    .dark .dataTables_wrapper .dataTables_filter input {
      background: #1e293b; border-color: #334155; color: #e2e8f0;
    }
    .dataTables_wrapper .dataTables_filter input:focus { border-color: #2d9d99; box-shadow: 0 0 0 2px rgba(45,157,153,0.15); }
    table.dataTable { border-collapse: collapse !important; width: 100% !important; }
    table.dataTable thead th {
      background: #f8fafc; font-weight: 600; font-size: 11px; text-transform: uppercase;
      letter-spacing: 0.05em; color: #64748b; padding: 10px 14px; border-bottom: 2px solid #e2e8f0;
    }
    .dark table.dataTable thead th { background: #0f172a; color: #94a3b8; border-color: #334155; }
    table.dataTable tbody td { padding: 10px 14px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
    .dark table.dataTable tbody td { border-color: #1e293b; color: #e2e8f0; }
    table.dataTable tbody tr:hover { background: #f0fdf4 !important; }
    .dark table.dataTable tbody tr:hover { background: rgba(45,157,153,0.06) !important; }
    .dataTables_wrapper .dataTables_paginate .paginate_button.current {
      background: #2d9d99 !important; color: #fff !important; border-color: #2d9d99 !important; border-radius: 6px;
    }
    .dataTables_wrapper .dataTables_paginate .paginate_button { border-radius: 6px; }
    .dt-buttons .dt-button {
      background: #f8fafc !important; border: 1px solid #e2e8f0 !important; border-radius: 8px !important;
      font-size: 12px !important; font-weight: 500 !important; padding: 6px 14px !important;
      color: #475569 !important; transition: all 150ms !important;
    }
    .dark .dt-buttons .dt-button { background: #1e293b !important; border-color: #334155 !important; color: #94a3b8 !important; }
    .dt-buttons .dt-button:hover { background: #f1f5f9 !important; border-color: #2d9d99 !important; color: #2d9d99 !important; }

    /* Modal overlay */
    .modal-overlay {
      display: none; position: fixed; inset: 0; z-index: 100;
      background: rgba(15,23,42,0.5); backdrop-filter: blur(4px);
      justify-content: center; align-items: start; padding-top: 5vh; overflow-y: auto;
    }
    .modal-overlay.active { display: flex; }
    .modal-card {
      background: #fff; border-radius: 16px; width: 95%; max-width: 680px;
      box-shadow: 0 20px 60px rgba(0,0,0,0.15); margin-bottom: 5vh;
      animation: slideUp 250ms ease-out;
    }
    .dark .modal-card { background: #1e293b; }
    @keyframes slideUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
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
        <i class="fas fa-receipt text-brand-500 text-sm"></i>
        <h1 class="text-base font-bold text-slate-800 dark:text-white">Expenses</h1>
      </div>

      <div class="ml-auto flex items-center gap-3">
        <span class="hidden sm:inline-block text-xs text-slate-400 dark:text-slate-500">
          Welcome, <?php echo htmlspecialchars($username); ?>
        </span>
      </div>
    </header>

    <!-- MAIN SCROLLABLE AREA -->
    <main class="flex-1 overflow-y-auto p-5">

      <!-- DATA CARD -->
      <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card">

        <!-- Card header -->
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 px-5 py-4 border-b border-slate-200 dark:border-slate-700">
          <div class="flex items-center gap-2">
            <i class="fas fa-table text-brand-500 text-sm"></i>
            <h2 class="text-sm font-bold text-slate-800 dark:text-white">Expenses</h2>
          </div>
          <div class="flex items-center gap-2 flex-wrap">
            <button class="bg-brand-500 hover:bg-brand-600 text-white text-xs font-semibold px-3.5 py-2 rounded-lg transition-colors" onclick="loadExpenses()">
              <i class="fas fa-sync text-xs mr-1"></i> Refresh
            </button>
            <?php if ($canCreate): ?>
            <button class="bg-emerald-500 hover:bg-emerald-600 text-white text-xs font-semibold px-3.5 py-2 rounded-lg transition-colors" onclick="openAddModal()">
              <i class="fas fa-plus text-xs mr-1"></i> Add Expense
            </button>
            <?php endif; ?>
          </div>
        </div>

        <!-- Filters -->
        <div class="px-5 py-3 border-b border-slate-100 dark:border-slate-700" id="filtersSection" style="display: none;">
          <div class="flex items-center justify-between mb-3">
            <h3 class="text-xs font-semibold text-slate-500 uppercase tracking-wide"><i class="fas fa-filter mr-1"></i> Filters</h3>
            <button class="text-xs text-slate-400 hover:text-rose-500 transition-colors" onclick="clearFilters()">
              <i class="fas fa-times-circle mr-1"></i> Clear All
            </button>
          </div>
          <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3">
            <div>
              <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">Date From</label>
              <input type="date" id="filterDateFrom" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
            </div>
            <div>
              <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">Date To</label>
              <input type="date" id="filterDateTo" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
            </div>
            <div>
              <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">Category</label>
              <select id="filterCategory" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
                <option value="">All Categories</option>
              </select>
            </div>
            <div>
              <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">Season</label>
              <select id="filterSeason" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
                <option value="">All Seasons</option>
              </select>
            </div>
            <div>
              <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">Status</label>
              <select id="filterStatus" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
                <option value="">All Statuses</option>
                <option value="Pending">Pending</option>
                <option value="Approved">Approved</option>
                <option value="Rejected">Rejected</option>
              </select>
            </div>
          </div>
        </div>

        <!-- Skeleton Loader -->
        <div id="skeletonLoader" class="p-4">
          <div class="skeleton-row"><div class="skeleton skeleton-cell"></div><div class="skeleton skeleton-cell"></div><div class="skeleton skeleton-cell"></div><div class="skeleton skeleton-cell"></div><div class="skeleton skeleton-cell"></div><div class="skeleton skeleton-cell"></div></div>
          <div class="skeleton-row"><div class="skeleton skeleton-cell"></div><div class="skeleton skeleton-cell"></div><div class="skeleton skeleton-cell"></div><div class="skeleton skeleton-cell"></div><div class="skeleton skeleton-cell"></div><div class="skeleton skeleton-cell"></div></div>
          <div class="skeleton-row"><div class="skeleton skeleton-cell"></div><div class="skeleton skeleton-cell"></div><div class="skeleton skeleton-cell"></div><div class="skeleton skeleton-cell"></div><div class="skeleton skeleton-cell"></div><div class="skeleton skeleton-cell"></div></div>
          <div class="skeleton-row"><div class="skeleton skeleton-cell"></div><div class="skeleton skeleton-cell"></div><div class="skeleton skeleton-cell"></div><div class="skeleton skeleton-cell"></div><div class="skeleton skeleton-cell"></div><div class="skeleton skeleton-cell"></div></div>
          <div class="skeleton-row"><div class="skeleton skeleton-cell"></div><div class="skeleton skeleton-cell"></div><div class="skeleton skeleton-cell"></div><div class="skeleton skeleton-cell"></div><div class="skeleton skeleton-cell"></div><div class="skeleton skeleton-cell"></div></div>
          <div class="skeleton-row"><div class="skeleton skeleton-cell"></div><div class="skeleton skeleton-cell"></div><div class="skeleton skeleton-cell"></div><div class="skeleton skeleton-cell"></div><div class="skeleton skeleton-cell"></div><div class="skeleton skeleton-cell"></div></div>
          <div class="skeleton-row"><div class="skeleton skeleton-cell"></div><div class="skeleton skeleton-cell"></div><div class="skeleton skeleton-cell"></div><div class="skeleton skeleton-cell"></div><div class="skeleton skeleton-cell"></div><div class="skeleton skeleton-cell"></div></div>
          <div class="skeleton-row"><div class="skeleton skeleton-cell"></div><div class="skeleton skeleton-cell"></div><div class="skeleton skeleton-cell"></div><div class="skeleton skeleton-cell"></div><div class="skeleton skeleton-cell"></div><div class="skeleton skeleton-cell"></div></div>
        </div>

        <!-- Table container -->
        <div id="tableContainer" style="display: none;" class="p-2">
          <div class="text-xs text-slate-400 dark:text-slate-500 text-center py-1 sm:hidden">
            <i class="fas fa-arrows-alt-h mr-1"></i> Swipe left/right to see all columns
          </div>
          <div style="overflow-x: auto; -webkit-overflow-scrolling: touch;">
            <table id="expensesTable" class="display" style="width:100%"></table>
          </div>
        </div>

      </div><!-- end data card -->

    </main>
  </div>
</div>

<!-- ==================== ADD/EDIT MODAL ==================== -->
<?php if ($canCreate || $canUpdate): ?>
<div class="modal-overlay" id="expenseModal">
  <div class="modal-card" onclick="event.stopPropagation()">
    <!-- Modal header -->
    <div class="flex items-center justify-between px-5 py-4 border-b border-slate-200 dark:border-slate-700">
      <h3 id="modalTitle" class="text-sm font-bold text-slate-800 dark:text-white flex items-center gap-2">
        <i class="fas fa-receipt text-brand-500"></i> Add Expense
      </h3>
      <button class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-300 transition-colors" onclick="closeModal()">
        <i class="fas fa-times"></i>
      </button>
    </div>

    <!-- Modal body -->
    <div class="px-5 py-4">
      <div id="expenseIdInfo" class="mb-3 px-3 py-2 bg-brand-50 dark:bg-brand-900/20 rounded-lg text-sm text-brand-700 dark:text-brand-300" style="display: none;">
        <strong><i class="fas fa-id-badge mr-1"></i> Expense ID:</strong> <span id="expenseIdDisplay"></span>
      </div>

      <form id="expenseForm">
        <input type="hidden" id="expenseId" name="expense_id">

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1"><i class="fas fa-calendar-day mr-1"></i> Date *</label>
            <input type="date" id="expenseDate" name="date" required class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1"><i class="fas fa-tags mr-1"></i> Category *</label>
            <select id="categoryId" name="category_id" required class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
              <option value="">Select category...</option>
            </select>
          </div>

          <div class="sm:col-span-2">
            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1"><i class="fas fa-align-left mr-1"></i> Description *</label>
            <textarea id="description" name="description" maxlength="500" rows="2" required placeholder="Enter expense description..." class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors resize-none"></textarea>
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1"><i class="fas fa-dollar-sign mr-1"></i> Amount *</label>
            <input type="text" inputmode="decimal" id="amount" name="amount" class="money-input w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors" required placeholder="0.00">
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1"><i class="fas fa-truck mr-1"></i> Linked Delivery</label>
            <select id="linkedDeliveryId" name="linked_delivery_id" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
              <option value="">None</option>
            </select>
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1"><i class="fas fa-shopping-cart mr-1"></i> Linked Purchase</label>
            <select id="linkedPurchaseId" name="linked_purchase_id" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
              <option value="">None</option>
            </select>
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1"><i class="fas fa-user mr-1"></i> Paid To</label>
            <input type="text" id="paidTo" name="paid_to" placeholder="Enter payee name" maxlength="200" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1"><i class="fas fa-receipt mr-1"></i> Receipt Number</label>
            <input type="text" id="receiptNumber" name="receipt_number" placeholder="Enter receipt #" maxlength="50" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1"><i class="fas fa-leaf mr-1"></i> Season *</label>
            <?php echo renderSeasonDropdown('season', 'season'); ?>
          </div>
        </div>

        <div class="flex items-center gap-3 mt-5 pt-4 border-t border-slate-200 dark:border-slate-700">
          <button type="submit" class="bg-brand-500 hover:bg-brand-600 text-white text-xs font-semibold px-3.5 py-2 rounded-lg transition-colors">
            <i class="fas fa-save mr-1"></i> Save
          </button>
          <button type="button" class="bg-slate-100 dark:bg-slate-700 hover:bg-slate-200 dark:hover:bg-slate-600 text-slate-600 dark:text-slate-300 text-xs font-semibold px-3.5 py-2 rounded-lg transition-colors" onclick="closeModal()">
            <i class="fas fa-times mr-1"></i> Cancel
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
    document.getElementById('modalTitle').innerHTML = '<i class="fas fa-receipt text-brand-500"></i> Add Expense';
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
    document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit text-brand-500"></i> Edit Expense';
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

<!-- Theme initializer -->
<script>
(function(){
  var theme = localStorage.getItem('theme');
  if (theme === 'dark' || (!theme && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
    document.documentElement.classList.add('dark');
  } else {
    document.documentElement.classList.remove('dark');
  }
})();
</script>

<!-- i18n loader -->
<script>
(function(){
  var lang = localStorage.getItem('language') || 'en';
  if (lang !== 'en' && typeof window.applyTranslations === 'function') {
    window.applyTranslations(lang);
  }
})();
</script>

</body>
</html>
