<?php
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

// RBAC: Admin = full CRUD, Manager = read-only, others = no access
$username = $_SESSION['username'];
$role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];
$current_page = 'users';

if (!in_array($role, ['Admin', 'Manager'])) {
    header("Location: dashboard.php");
    exit();
}

// Handle AJAX requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    try {
        switch ($_GET['action']) {
            case 'getUsers':
                $conn = getDBConnection();
                $stmt = $conn->prepare("SELECT id, full_name, email, phone, role, is_active, created_at, last_login FROM users ORDER BY id DESC");
                $stmt->execute();
                $result = $stmt->get_result();

                $users = [];
                while ($row = $result->fetch_assoc()) {
                    $users[] = [
                        'id' => $row['id'],
                        'full_name' => $row['full_name'],
                        'email' => $row['email'],
                        'phone' => $row['phone'] ?? '',
                        'role' => $row['role'],
                        'is_active' => (bool)$row['is_active'],
                        'created_at' => date('M d, Y', strtotime($row['created_at'])),
                        'last_login' => $row['last_login'] ? date('M d, Y H:i', strtotime($row['last_login'])) : 'Never'
                    ];
                }

                $stmt->close();
                $conn->close();

                echo json_encode(['success' => true, 'data' => $users]);
                exit();

            case 'addUser':
                if ($role !== 'Admin') {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $fullName = isset($_POST['full_name']) ? trim($_POST['full_name']) : '';
                $email = isset($_POST['email']) ? trim($_POST['email']) : '';
                $phone = isset($_POST['phone']) ? trim($_POST['phone']) : null;
                $password = isset($_POST['password']) ? $_POST['password'] : '';
                $newRole = isset($_POST['role']) ? $_POST['role'] : 'Warehouse Clerk';

                if (empty($fullName) || empty($email) || empty($password)) {
                    echo json_encode(['success' => false, 'message' => 'Full name, email, and password are required']);
                    exit();
                }

                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid email format']);
                    exit();
                }

                $validRoles = ['Admin','Manager','Procurement Officer','Sales Officer','Finance Officer','Fleet Manager','Warehouse Clerk'];
                if (!in_array($newRole, $validRoles)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid role']);
                    exit();
                }

                $conn = getDBConnection();

                // Check email uniqueness
                $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
                $stmt->bind_param("s", $email);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows > 0) {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Email already exists']);
                    exit();
                }
                $stmt->close();

                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("INSERT INTO users (full_name, email, phone, password_hash, role) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param("sssss", $fullName, $email, $phone, $hashedPassword, $newRole);

                if ($stmt->execute()) {
                    logActivity($user_id, $username, 'User Created', "Created user: $fullName ($newRole)");
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => true, 'message' => 'User added successfully']);
                } else {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to add user']);
                }
                exit();

            case 'updateUser':
                if ($role !== 'Admin') {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $userId = isset($_POST['id']) ? intval($_POST['id']) : 0;
                $fullName = isset($_POST['full_name']) ? trim($_POST['full_name']) : '';
                $email = isset($_POST['email']) ? trim($_POST['email']) : '';
                $phone = isset($_POST['phone']) ? trim($_POST['phone']) : null;
                $newRole = isset($_POST['role']) ? $_POST['role'] : 'Warehouse Clerk';
                $password = isset($_POST['password']) ? $_POST['password'] : '';

                if ($userId <= 0 || empty($fullName) || empty($email)) {
                    echo json_encode(['success' => false, 'message' => 'Full name and email are required']);
                    exit();
                }

                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid email format']);
                    exit();
                }

                $validRoles = ['Admin','Manager','Procurement Officer','Sales Officer','Finance Officer','Fleet Manager','Warehouse Clerk'];
                if (!in_array($newRole, $validRoles)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid role']);
                    exit();
                }

                $conn = getDBConnection();

                // Check email uniqueness (exclude current user)
                $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                $stmt->bind_param("si", $email, $userId);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows > 0) {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Email already in use by another user']);
                    exit();
                }
                $stmt->close();

                if (!empty($password)) {
                    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare("UPDATE users SET full_name = ?, email = ?, phone = ?, password_hash = ?, role = ? WHERE id = ?");
                    $stmt->bind_param("sssssi", $fullName, $email, $phone, $hashedPassword, $newRole, $userId);
                } else {
                    $stmt = $conn->prepare("UPDATE users SET full_name = ?, email = ?, phone = ?, role = ? WHERE id = ?");
                    $stmt->bind_param("ssssi", $fullName, $email, $phone, $newRole, $userId);
                }

                if ($stmt->execute()) {
                    $details = !empty($password) ? "Updated user: $fullName (password changed)" : "Updated user: $fullName";
                    logActivity($user_id, $username, 'User Updated', $details);

                    // Update session if editing own account
                    if ($userId == $user_id) {
                        $_SESSION['username'] = $fullName;
                        $_SESSION['email'] = $email;
                        $_SESSION['role'] = $newRole;
                    }

                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => true, 'message' => 'User updated successfully']);
                } else {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to update user']);
                }
                exit();

            case 'toggleActive':
                if ($role !== 'Admin') {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $userId = isset($_POST['id']) ? intval($_POST['id']) : 0;

                if ($userId <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid user ID']);
                    exit();
                }

                if ($userId == $_SESSION['user_id']) {
                    echo json_encode(['success' => false, 'message' => 'Cannot deactivate your own account']);
                    exit();
                }

                $conn = getDBConnection();

                // Get current status and name
                $stmt = $conn->prepare("SELECT full_name, is_active FROM users WHERE id = ?");
                $stmt->bind_param("i", $userId);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows == 0) {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'User not found']);
                    exit();
                }

                $targetUser = $result->fetch_assoc();
                $stmt->close();

                $newStatus = $targetUser['is_active'] ? 0 : 1;
                $stmt = $conn->prepare("UPDATE users SET is_active = ? WHERE id = ?");
                $stmt->bind_param("ii", $newStatus, $userId);

                if ($stmt->execute()) {
                    $statusText = $newStatus ? 'activated' : 'deactivated';
                    logActivity($user_id, $username, 'User Status Changed', "User {$targetUser['full_name']} $statusText");
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => true, 'message' => "User $statusText successfully"]);
                } else {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to update user status']);
                }
                exit();

            case 'resetPassword':
                if ($role !== 'Admin') {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $userId = isset($_POST['id']) ? intval($_POST['id']) : 0;

                if ($userId <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid user ID']);
                    exit();
                }

                $conn = getDBConnection();

                // Get user name
                $stmt = $conn->prepare("SELECT full_name FROM users WHERE id = ?");
                $stmt->bind_param("i", $userId);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows == 0) {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'User not found']);
                    exit();
                }

                $targetUser = $result->fetch_assoc();
                $stmt->close();

                // Reset to default password
                $defaultPassword = password_hash('password123', PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                $stmt->bind_param("si", $defaultPassword, $userId);

                if ($stmt->execute()) {
                    logActivity($user_id, $username, 'Password Reset', "Reset password for user: {$targetUser['full_name']}");
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => true, 'message' => "Password reset to 'password123' for {$targetUser['full_name']}"]);
                } else {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to reset password']);
                }
                exit();

            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
                exit();
        }
    } catch (Exception $e) {
        error_log("Users.php error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
        exit();
    }
}

// Determine if current user is read-only (Manager)
$isReadOnly = ($role === 'Manager');
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Commodity Flow — User Management</title>

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

  <!-- Font Awesome -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />

  <!-- DataTables CSS -->
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/jquery.dataTables.min.css">
  <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.dataTables.min.css">
  <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.dataTables.min.css">

  <!-- App Styles -->
  <link rel="stylesheet" href="styles.css?v=4.0">

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

    #sidebar { transition: width 280ms cubic-bezier(.16,1,.3,1); }
    .sidebar-label { transition: opacity 200ms, width 200ms; }
    .app-collapsed #sidebar { width: 64px; }
    .app-collapsed .sidebar-label { opacity: 0; width: 0; overflow: hidden; }
    .app-collapsed .sidebar-section-label { opacity: 0; }
    .app-collapsed .logo-text { opacity: 0; width: 0; overflow: hidden; }

    .nav-link.active { background: rgba(45,157,153,0.12); color: #2d9d99; }
    .dark .nav-link.active { background: rgba(45,157,153,0.15); color: #4db8b4; }
    .nav-link.active .nav-icon { color: #2d9d99; }
    .dark .nav-link.active .nav-icon { color: #4db8b4; }
    .nav-link.active::before {
      content: '';
      position: absolute; left: 0; top: 15%; bottom: 15%;
      width: 3px; background: #2d9d99; border-radius: 0 3px 3px 0;
    }

    /* DataTable overrides */
    table.dataTable thead th { background: transparent !important; border-bottom: 2px solid #e2e8f0 !important; font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; color: #64748b; font-weight: 600; }
    .dark table.dataTable thead th { border-bottom-color: #334155 !important; color: #94a3b8; }
    table.dataTable tbody td { border-bottom: 1px solid #f1f5f9 !important; font-size: 13px; padding: 10px 12px !important; }
    .dark table.dataTable tbody td { border-bottom-color: #1e293b !important; color: #e2e8f0; }
    table.dataTable tbody tr:hover { background: #f8fafc !important; }
    .dark table.dataTable tbody tr:hover { background: rgba(45,157,153,0.06) !important; }
    .dataTables_wrapper .dataTables_length select,
    .dataTables_wrapper .dataTables_filter input { border: 1px solid #e2e8f0; border-radius: 8px; padding: 6px 10px; font-size: 13px; background: white; }
    .dark .dataTables_wrapper .dataTables_length select,
    .dark .dataTables_wrapper .dataTables_filter input { background: #1e293b; border-color: #334155; color: #e2e8f0; }
    .dataTables_wrapper .dataTables_info,
    .dataTables_wrapper .dataTables_length label,
    .dataTables_wrapper .dataTables_filter label { font-size: 12px; color: #64748b; }
    .dark .dataTables_wrapper .dataTables_info,
    .dark .dataTables_wrapper .dataTables_length label,
    .dark .dataTables_wrapper .dataTables_filter label { color: #94a3b8; }
    .dataTables_wrapper .dataTables_paginate .paginate_button { border-radius: 6px !important; font-size: 12px; padding: 4px 10px !important; }
    .dataTables_wrapper .dataTables_paginate .paginate_button.current { background: #2d9d99 !important; color: white !important; border-color: #2d9d99 !important; }
    .dt-buttons .dt-button { background: white !important; border: 1px solid #e2e8f0 !important; border-radius: 8px !important; font-size: 12px !important; padding: 6px 14px !important; color: #334155 !important; }
    .dark .dt-buttons .dt-button { background: #1e293b !important; border-color: #334155 !important; color: #e2e8f0 !important; }
    .dt-buttons .dt-button:hover { background: #f8fafc !important; box-shadow: 0 1px 3px rgba(0,0,0,0.06) !important; }
    .dark .dt-buttons .dt-button:hover { background: #273349 !important; }
    table.dataTable { border-collapse: collapse !important; }

    /* Modal overlay */
    .modal-overlay { display: none; position: fixed; inset: 0; z-index: 100; background: rgba(0,0,0,0.5); backdrop-filter: blur(4px); align-items: center; justify-content: center; }
    .modal-overlay.active { display: flex; }
  </style>
</head>

<body class="h-full bg-slate-50 text-slate-800 font-sans antialiased dark:bg-slate-900 dark:text-slate-200">

  <?php include 'mobile-menu.php'; ?>

  <div class="flex h-full overflow-hidden" id="appRoot">

    <?php include 'sidebar.php'; ?>

    <!-- MAIN -->
    <div class="flex-1 flex flex-col min-w-0 overflow-hidden">

      <!-- HEADER -->
      <header class="h-14 bg-white dark:bg-slate-800 border-b border-slate-200 dark:border-slate-700 flex items-center gap-4 px-5 flex-shrink-0">
        <button id="mobileSidebarBtn" class="lg:hidden text-slate-500 hover:text-slate-700 dark:hover:text-slate-300">
          <i class="fas fa-bars text-sm"></i>
        </button>

        <div class="flex items-center gap-2">
          <i class="fas fa-users-gear text-brand-500 text-sm"></i>
          <h1 class="text-base font-bold text-slate-800 dark:text-white">User Management</h1>
        </div>

        <div class="ml-auto flex items-center gap-3">
          <span class="text-xs text-slate-500 dark:text-slate-400">Welcome, <?php echo htmlspecialchars($username); ?></span>
          <button onclick="loadUsers()" class="bg-brand-500 hover:bg-brand-600 text-white text-xs font-semibold px-3.5 py-2 rounded-lg transition-colors">
            <i class="fas fa-sync mr-1"></i> Refresh
          </button>
          <?php if (!$isReadOnly): ?>
          <button onclick="openAddModal()" class="bg-emerald-500 hover:bg-emerald-600 text-white text-xs font-semibold px-3.5 py-2 rounded-lg transition-colors">
            <i class="fas fa-plus mr-1"></i> Add User
          </button>
          <?php endif; ?>
        </div>
      </header>

      <!-- MAIN CONTENT -->
      <main class="flex-1 overflow-y-auto p-5 space-y-5">

        <!-- Filters Section -->
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card p-4" id="filtersSection" style="display: none;">
          <div class="flex items-center justify-between mb-3">
            <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-200"><i class="fas fa-filter mr-1 text-brand-500"></i> Filters</h3>
            <button onclick="clearFilters()" class="text-xs text-slate-500 hover:text-slate-700 dark:hover:text-slate-300 transition-colors">
              <i class="fas fa-times-circle mr-1"></i> Clear All
            </button>
          </div>
          <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4">
            <div>
              <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1"><i class="fas fa-calendar-alt mr-1"></i> Date From</label>
              <input type="date" id="filterDateFrom" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
            </div>
            <div>
              <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1"><i class="fas fa-calendar-alt mr-1"></i> Date To</label>
              <input type="date" id="filterDateTo" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
            </div>
            <div>
              <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1"><i class="fas fa-user-tag mr-1"></i> Role</label>
              <select id="filterRole" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
                <option value="">All Roles</option>
                <option value="Admin">Admin</option>
                <option value="Manager">Manager</option>
                <option value="Procurement Officer">Procurement Officer</option>
                <option value="Sales Officer">Sales Officer</option>
                <option value="Finance Officer">Finance Officer</option>
                <option value="Fleet Manager">Fleet Manager</option>
                <option value="Warehouse Clerk">Warehouse Clerk</option>
              </select>
            </div>
            <div>
              <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1"><i class="fas fa-toggle-on mr-1"></i> Status</label>
              <select id="filterStatus" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
                <option value="">All Status</option>
                <option value="Active">Active</option>
                <option value="Inactive">Inactive</option>
              </select>
            </div>
          </div>
        </div>

        <!-- Users DataTable Card -->
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card">
          <div class="flex items-center justify-between p-4 border-b border-slate-200 dark:border-slate-700">
            <h2 class="text-sm font-semibold text-slate-700 dark:text-slate-200"><i class="fas fa-table mr-1 text-brand-500"></i> Users</h2>
          </div>
          <div class="p-4">
            <p class="text-xs text-slate-400 dark:text-slate-500 mb-2 md:hidden"><i class="fas fa-arrows-alt-h mr-1"></i> Swipe left/right to see all columns</p>
            <div class="overflow-x-auto -webkit-overflow-scrolling-touch">
              <table id="usersTable" class="display" style="width:100%"></table>
            </div>
          </div>
        </div>

      </main><!-- /main -->
    </div><!-- /flex-1 -->
  </div><!-- /appRoot -->

  <?php if (!$isReadOnly): ?>
  <!-- User Modal -->
  <div class="modal-overlay" id="userModal">
    <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-xl border border-slate-200 dark:border-slate-700 w-full max-w-lg mx-4 max-h-[90vh] overflow-y-auto" onclick="event.stopPropagation()">
      <div class="flex items-center justify-between p-5 border-b border-slate-200 dark:border-slate-700">
        <h3 id="modalTitle" class="text-base font-bold text-slate-800 dark:text-white"><i class="fas fa-user-plus mr-2 text-brand-500"></i> Add User</h3>
        <button onclick="closeModal()" class="w-8 h-8 flex items-center justify-center rounded-lg text-slate-400 hover:text-slate-600 hover:bg-slate-100 dark:hover:bg-slate-700 transition-colors">
          <i class="fas fa-times"></i>
        </button>
      </div>
      <div class="p-5">
        <form id="userForm" class="space-y-4">
          <input type="hidden" id="userId" name="id">

          <div>
            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1"><i class="fas fa-user mr-1"></i> Full Name *</label>
            <input type="text" id="fullName" name="full_name" required maxlength="150" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1"><i class="fas fa-envelope mr-1"></i> Email *</label>
            <input type="email" id="email" name="email" required maxlength="200" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1"><i class="fas fa-phone mr-1"></i> Phone</label>
            <input type="tel" id="phone" name="phone" maxlength="20" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1"><i class="fas fa-lock mr-1"></i> Password <span id="passwordHint" style="display:none;" class="text-slate-400 normal-case tracking-normal">(Leave empty to keep current)</span></label>
            <input type="password" id="password" name="password" minlength="6" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1"><i class="fas fa-user-tag mr-1"></i> Role *</label>
            <select id="role" name="role" required class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
              <option value="Warehouse Clerk">Warehouse Clerk</option>
              <option value="Fleet Manager">Fleet Manager</option>
              <option value="Finance Officer">Finance Officer</option>
              <option value="Sales Officer">Sales Officer</option>
              <option value="Procurement Officer">Procurement Officer</option>
              <option value="Manager">Manager</option>
              <option value="Admin">Admin</option>
            </select>
          </div>

          <div class="flex items-center gap-3 pt-2">
            <button type="submit" class="bg-brand-500 hover:bg-brand-600 text-white text-xs font-semibold px-3.5 py-2 rounded-lg transition-colors">
              <i class="fas fa-save mr-1"></i> Save
            </button>
            <button type="button" onclick="closeModal()" class="bg-slate-200 hover:bg-slate-300 dark:bg-slate-600 dark:hover:bg-slate-500 text-slate-700 dark:text-slate-200 text-xs font-semibold px-3.5 py-2 rounded-lg transition-colors">
              <i class="fas fa-times mr-1"></i> Cancel
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Theme init -->
  <script>
  (function(){
    var s; try { s = localStorage; } catch(e) { s = { getItem:function(){return null;}, setItem:function(){} }; }
    var dark = s.getItem('cp_theme') === 'dark' || (s.getItem('cp_theme') === null && window.matchMedia('(prefers-color-scheme: dark)').matches);
    document.documentElement.classList.toggle('dark', dark);
    var btn = document.getElementById('themeToggleBtn');
    var icon = document.getElementById('themeIcon');
    if (btn) btn.addEventListener('click', function(){ dark = !dark; document.documentElement.classList.toggle('dark', dark); s.setItem('cp_theme', dark ? 'dark' : 'light'); if(icon) icon.className = dark ? 'fas fa-sun w-4 text-sm' : 'fas fa-moon w-4 text-sm'; });
  })();
  </script>

  <!-- i18n loader -->
  <script>
  (function(){
    var s; try { s = localStorage; } catch(e) { s = { getItem:function(){return null;}, setItem:function(){} }; }
    var lang = s.getItem('cp_lang') || 'en';
    document.querySelectorAll('[data-t]').forEach(function(el){
      var key = el.getAttribute('data-t');
      if (window.TRANSLATIONS && window.TRANSLATIONS[lang] && window.TRANSLATIONS[lang][key]) el.textContent = window.TRANSLATIONS[lang][key];
    });
  })();
  </script>

    <script>
        let usersTable;
        let isEditMode = false;
        let usersData = [];
        const isReadOnly = <?php echo $isReadOnly ? 'true' : 'false'; ?>;

        const roleBadgeMap = {
            'Admin':                'role-admin',
            'Manager':              'role-manager',
            'Procurement Officer':  'role-procurement',
            'Sales Officer':        'role-sales',
            'Finance Officer':      'role-finance',
            'Fleet Manager':        'role-fleet',
            'Warehouse Clerk':      'role-warehouse'
        };

        $(document).ready(function() {
            loadUsers();
        });

        function loadUsers() {
            $.ajax({
                url: '?action=getUsers',
                method: 'GET',
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        usersData = response.data;
                        $('#filtersSection').show();
                        initializeDataTable(response.data);
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: response.message || 'Failed to load users'
                        });
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', error);
                    Swal.fire({
                        icon: 'error',
                        title: 'Connection Error',
                        text: 'Could not connect to server. Please check console for details.'
                    });
                }
            });
        }

        function initializeDataTable(data) {
            if (usersTable) {
                usersTable.destroy();
                $('#usersTable').empty();
            }

            const columns = [
                { data: 'full_name', title: 'Name' },
                { data: 'email', title: 'Email' },
                {
                    data: 'role',
                    title: 'Role',
                    render: function(data) {
                        const cls = roleBadgeMap[data] || 'role-warehouse';
                        return `<span class="status-badge ${cls}">${data}</span>`;
                    }
                },
                {
                    data: 'is_active',
                    title: 'Status',
                    render: function(data) {
                        return data
                            ? '<span class="status-badge status-active"><i class="fas fa-check-circle"></i> Active</span>'
                            : '<span class="status-badge status-inactive"><i class="fas fa-times-circle"></i> Inactive</span>';
                    }
                },
                { data: 'last_login', title: 'Last Login' }
            ];

            // Add actions column for Admin
            if (!isReadOnly) {
                columns.push({
                    data: null,
                    title: 'Actions',
                    orderable: false,
                    render: function(data, type, row) {
                        const toggleIcon = row.is_active ? 'fa-toggle-on' : 'fa-toggle-off';
                        const toggleColor = row.is_active ? 'style="color:#34a853"' : 'style="color:#ea4335"';
                        const toggleTitle = row.is_active ? 'Deactivate' : 'Activate';

                        return `
                            <button class="action-icon edit-icon" onclick='editUser(${JSON.stringify(row)})' title="Edit">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button class="action-icon" onclick="toggleActive(${row.id})" title="${toggleTitle}" ${toggleColor}>
                                <i class="fas ${toggleIcon}"></i>
                            </button>
                            <button class="action-icon" onclick="resetPassword(${row.id})" title="Reset Password" style="color:#fbbc04">
                                <i class="fas fa-key"></i>
                            </button>
                        `;
                    }
                });
            }

            setTimeout(() => {
                usersTable = $('#usersTable').DataTable({
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
                            exportOptions: { columns: isReadOnly ? ':visible' : ':not(:last-child)' }
                        },
                        {
                            extend: 'pdf',
                            text: '<i class="fas fa-file-pdf"></i> PDF',
                            exportOptions: { columns: isReadOnly ? ':visible' : ':not(:last-child)' }
                        },
                        {
                            extend: 'print',
                            text: '<i class="fas fa-print"></i> Print',
                            exportOptions: { columns: isReadOnly ? ':visible' : ':not(:last-child)' }
                        }
                    ],
                    order: [[0, 'asc']]
                });

                // Apply filters on change
                $('#filterDateFrom, #filterDateTo, #filterRole, #filterStatus').on('change', function() {
                    applyFilters();
                });
            }, 100);
        }

        function applyFilters() {
            if (!usersTable) return;

            $.fn.dataTable.ext.search = [];

            const dateFrom = document.getElementById('filterDateFrom').value;
            const dateTo = document.getElementById('filterDateTo').value;
            const role = document.getElementById('filterRole').value;
            const status = document.getElementById('filterStatus').value;

            // Date range filter
            if (dateFrom || dateTo) {
                $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
                    const createdAt = usersData[dataIndex]?.created_at;
                    if (!createdAt) return true;
                    const recordDate = new Date(createdAt);
                    const fromDate = dateFrom ? new Date(dateFrom) : null;
                    const toDate = dateTo ? new Date(dateTo + 'T23:59:59') : null;
                    if (fromDate && recordDate < fromDate) return false;
                    if (toDate && recordDate > toDate) return false;
                    return true;
                });
            }

            // Status filter
            if (status) {
                $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
                    const isActive = usersData[dataIndex]?.is_active;
                    if (status === 'Active') return isActive;
                    if (status === 'Inactive') return !isActive;
                    return true;
                });
            }

            // Role filter (column index 2)
            if (role) {
                usersTable.column(2).search('^' + role.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '$', true, false);
            } else {
                usersTable.column(2).search('');
            }

            usersTable.draw();
        }

        function clearFilters() {
            document.getElementById('filterDateFrom').value = '';
            document.getElementById('filterDateTo').value = '';
            document.getElementById('filterRole').value = '';
            document.getElementById('filterStatus').value = '';

            if (usersTable) {
                $.fn.dataTable.ext.search = [];
                usersTable.columns().search('').draw();
            }
        }

        <?php if (!$isReadOnly): ?>
        function openAddModal() {
            isEditMode = false;
            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-user-plus"></i> Add User';
            document.getElementById('userForm').reset();
            document.getElementById('userId').value = '';
            document.getElementById('passwordHint').style.display = 'none';
            document.getElementById('password').required = true;
            document.getElementById('userModal').classList.add('active');
        }

        function editUser(user) {
            isEditMode = true;
            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit"></i> Edit User';
            document.getElementById('userId').value = user.id;
            document.getElementById('fullName').value = user.full_name;
            document.getElementById('email').value = user.email;
            document.getElementById('phone').value = user.phone || '';
            document.getElementById('role').value = user.role;
            document.getElementById('password').value = '';
            document.getElementById('passwordHint').style.display = 'inline';
            document.getElementById('password').required = false;
            document.getElementById('userModal').classList.add('active');
        }

        function closeModal() {
            document.getElementById('userModal').classList.remove('active');
            document.getElementById('userForm').reset();
        }

        document.getElementById('userModal').addEventListener('click', function(e) {
            if (e.target === this) closeModal();
        });

        document.getElementById('userForm').addEventListener('submit', function(e) {
            e.preventDefault();

            const formData = new FormData(this);
            const action = isEditMode ? 'updateUser' : 'addUser';

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
                    if (response.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Success!',
                            text: response.message,
                            timer: 2000,
                            showConfirmButton: false
                        });
                        closeModal();
                        setTimeout(() => loadUsers(), 100);
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: response.message });
                    }
                },
                error: function(xhr, status, error) {
                    Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error: ' + error });
                }
            });
        });

        function toggleActive(userId) {
            Swal.fire({
                icon: 'question',
                title: 'Toggle User Status?',
                text: 'This will activate or deactivate the user account',
                showCancelButton: true,
                confirmButtonText: 'Yes, proceed',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    const formData = new FormData();
                    formData.append('id', userId);

                    $.ajax({
                        url: '?action=toggleActive',
                        method: 'POST',
                        data: formData,
                        processData: false,
                        contentType: false,
                        dataType: 'json',
                        success: function(response) {
                            if (response.success) {
                                Swal.fire({ icon: 'success', text: response.message, timer: 2000, showConfirmButton: false });
                                setTimeout(() => loadUsers(), 100);
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

        function resetPassword(userId) {
            Swal.fire({
                icon: 'warning',
                title: 'Reset Password?',
                text: "This will reset the user's password to 'password123'",
                showCancelButton: true,
                confirmButtonColor: '#fbbc04',
                confirmButtonText: 'Yes, reset it',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    const formData = new FormData();
                    formData.append('id', userId);

                    $.ajax({
                        url: '?action=resetPassword',
                        method: 'POST',
                        data: formData,
                        processData: false,
                        contentType: false,
                        dataType: 'json',
                        success: function(response) {
                            if (response.success) {
                                Swal.fire({ icon: 'success', title: 'Password Reset', text: response.message });
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
