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
    <title>User Management - Dashboard System</title>

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
                <h1><i class="fas fa-users"></i> User Management</h1>
                <div>Welcome, <?php echo htmlspecialchars($username); ?></div>
            </div>

            <div class="data-section">
                <div class="section-header">
                    <h2><i class="fas fa-table"></i> Users</h2>
                    <div style="display: flex; gap: 10px;">
                        <button class="btn btn-primary" onclick="loadUsers()">
                            <i class="fas fa-sync"></i> Refresh
                        </button>
                        <?php if (!$isReadOnly): ?>
                        <button class="btn btn-success" onclick="openAddModal()">
                            <i class="fas fa-plus"></i> Add User
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
                            <label><i class="fas fa-user-tag"></i> Role</label>
                            <select id="filterRole" class="filter-input">
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
                        <div class="filter-group">
                            <label><i class="fas fa-toggle-on"></i> Status</label>
                            <select id="filterStatus" class="filter-input">
                                <option value="">All Status</option>
                                <option value="Active">Active</option>
                                <option value="Inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="table-scroll-hint">
                    <i class="fas fa-arrows-alt-h"></i> Swipe left/right to see all columns
                </div>
                <div style="overflow-x: auto; -webkit-overflow-scrolling: touch;">
                    <table id="usersTable" class="display" style="width:100%"></table>
                </div>
            </div>
        </div>
    </div>

    <?php if (!$isReadOnly): ?>
    <!-- User Modal -->
    <div class="modal-overlay" id="userModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 id="modalTitle"><i class="fas fa-user-plus"></i> Add User</h3>
                <button class="close-btn" onclick="closeModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <form id="userForm">
                    <input type="hidden" id="userId" name="id">

                    <div class="form-group">
                        <label><i class="fas fa-user"></i> Full Name *</label>
                        <input type="text" id="fullName" name="full_name" required maxlength="150">
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-envelope"></i> Email *</label>
                        <input type="email" id="email" name="email" required maxlength="200">
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-phone"></i> Phone</label>
                        <input type="tel" id="phone" name="phone" maxlength="20">
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-lock"></i> Password <span id="passwordHint" style="display:none;">(Leave empty to keep current)</span></label>
                        <input type="password" id="password" name="password" minlength="6">
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-user-tag"></i> Role *</label>
                        <select id="role" name="role" required>
                            <option value="Warehouse Clerk">Warehouse Clerk</option>
                            <option value="Fleet Manager">Fleet Manager</option>
                            <option value="Finance Officer">Finance Officer</option>
                            <option value="Sales Officer">Sales Officer</option>
                            <option value="Procurement Officer">Procurement Officer</option>
                            <option value="Manager">Manager</option>
                            <option value="Admin">Admin</option>
                        </select>
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
