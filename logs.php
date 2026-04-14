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

$username = $_SESSION['username'];
$role = isset($_SESSION['role']) ? $_SESSION['role'] : 'Warehouse Clerk';
$user_id = $_SESSION['user_id'];
$current_page = 'logs';

// Handle AJAX requests
if (isset($_GET['action']) && $_GET['action'] === 'getLogs') {
    header('Content-Type: application/json');

    try {
        $conn = getDBConnection();

        // Admin/Manager sees all logs, others see only their own
        if (in_array($role, ['Admin', 'Manager'])) {
            $stmt = $conn->prepare("SELECT * FROM activity_logs ORDER BY timestamp DESC");
            $stmt->execute();
        } else {
            $stmt = $conn->prepare("SELECT * FROM activity_logs WHERE user_id = ? ORDER BY timestamp DESC");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
        }

        $result = $stmt->get_result();
        $logs = [];

        while ($row = $result->fetch_assoc()) {
            $logs[] = [
                'id' => $row['id'],
                'user_id' => $row['user_id'],
                'username' => $row['username'],
                'action' => $row['action'],
                'details' => $row['details'],
                'ip_address' => $row['ip_address'],
                'timestamp' => date('M d, Y H:i:s', strtotime($row['timestamp'])),
                'timestamp_iso' => $row['timestamp']
            ];
        }

        $stmt->close();
        $conn->close();

        echo json_encode(['success' => true, 'data' => $logs]);
        exit();

    } catch (Exception $e) {
        error_log("Logs.php error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Error loading logs: ' . $e->getMessage()]);
        exit();
    }
}

// If we reach here, render the HTML page
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
    <title>Activity Logs - Dashboard System</title>

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
                <h1><i class="fas fa-history"></i> Activity Logs</h1>
                <div>Welcome, <?php echo htmlspecialchars($username); ?></div>
            </div>

            <div class="data-section">
                <div class="section-header">
                    <h2><i class="fas fa-table"></i> Activity History</h2>
                    <button class="btn btn-primary" onclick="loadLogs()">
                        <i class="fas fa-sync"></i> Refresh
                    </button>
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
                            <label><i class="fas fa-tag"></i> Action Type</label>
                            <select id="filterAction" class="filter-input">
                                <option value="">All Actions</option>
                            </select>
                        </div>
                        <?php if (in_array($role, ['Admin', 'Manager'])): ?>
                        <div class="filter-group">
                            <label><i class="fas fa-user"></i> Username</label>
                            <select id="filterUsername" class="filter-input">
                                <option value="">All Users</option>
                            </select>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Loading Skeleton -->
                <div id="loadingSkeleton">
                    <div class="skeleton-table">
                        <div class="skeleton-table-row">
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 2"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 2"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                        </div>
                        <?php for ($i = 0; $i < 8; $i++): ?>
                        <div class="skeleton-table-row">
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 2"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 2"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                        </div>
                        <?php endfor; ?>
                    </div>
                </div>

                <!-- DataTable -->
                <div id="tableContainer" style="display: none;">
                    <div class="table-scroll-hint">
                        <i class="fas fa-arrows-alt-h"></i> Swipe left/right to see all columns
                    </div>
                    <div style="overflow-x: auto; -webkit-overflow-scrolling: touch;">
                        <table id="logsTable" class="display" style="width:100%"></table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        let logsTable;
        let logsData = [];
        let uniqueActions = [];
        let uniqueUsernames = [];

        $(document).ready(function() {
            loadLogs();
        });

        function loadLogs() {
            $('#loadingSkeleton').show();
            $('#tableContainer').hide();
            $('#filtersSection').hide();

            $.ajax({
                url: '?action=getLogs',
                method: 'GET',
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        logsData = response.data;

                        // Extract unique values for filters
                        uniqueActions = [...new Set(response.data.map(r => r.action).filter(Boolean))];
                        uniqueUsernames = [...new Set(response.data.map(r => r.username).filter(Boolean))];

                        // Populate filter dropdowns
                        populateFilters();

                        $('#loadingSkeleton').hide();
                        $('#tableContainer').show();
                        $('#filtersSection').show();

                        setTimeout(() => initializeDataTable(response.data), 100);
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: response.message || 'Failed to load logs'
                        });
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', error);
                    $('#loadingSkeleton').hide();
                    Swal.fire({
                        icon: 'error',
                        title: 'Connection Error',
                        text: 'Could not connect to server. Please check console for details.'
                    });
                }
            });
        }

        function populateFilters() {
            // Populate action filter
            const actionSelect = document.getElementById('filterAction');
            actionSelect.innerHTML = '<option value="">All Actions</option>';
            uniqueActions.forEach(action => {
                const option = document.createElement('option');
                option.value = action;
                option.textContent = action;
                actionSelect.appendChild(option);
            });

            // Populate username filter (Admin only)
            <?php if (in_array($role, ['Admin', 'Manager'])): ?>
            const usernameSelect = document.getElementById('filterUsername');
            usernameSelect.innerHTML = '<option value="">All Users</option>';
            uniqueUsernames.forEach(username => {
                const option = document.createElement('option');
                option.value = username;
                option.textContent = username;
                usernameSelect.appendChild(option);
            });
            <?php endif; ?>
        }

        function initializeDataTable(data) {
            if (logsTable) {
                logsTable.destroy();
                $('#logsTable').empty();
            }

            setTimeout(() => {
                logsTable = $('#logsTable').DataTable({
                    data: data,
                    destroy: true,
                    columns: [
                        { data: 'id', title: 'ID', width: '60px' },
                        { data: 'username', title: 'Username' },
                        { data: 'action', title: 'Action' },
                        {
                            data: 'details',
                            title: 'Details',
                            render: function(data) {
                                if (!data) return '<em class="text-muted">No details</em>';
                                return data.length > 100 ? data.substring(0, 100) + '...' : data;
                            }
                        },
                        { data: 'ip_address', title: 'IP Address' },
                        { data: 'timestamp', title: 'Timestamp' }
                    ],
                    pageLength: 50,
                    lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
                    responsive: true,
                    dom: 'Blfrtip',
                    buttons: [
                        {
                            extend: 'csv',
                            text: '<i class="fas fa-file-csv"></i> CSV',
                            exportOptions: { columns: ':visible' }
                        },
                        {
                            extend: 'pdf',
                            text: '<i class="fas fa-file-pdf"></i> PDF',
                            exportOptions: { columns: ':visible' }
                        },
                        {
                            extend: 'print',
                            text: '<i class="fas fa-print"></i> Print',
                            exportOptions: { columns: ':visible' }
                        }
                    ],
                    order: [[0, 'desc']]
                });

                // Apply filters on change
                $('#filterDateFrom, #filterDateTo, #filterAction, #filterUsername').on('change', function() {
                    applyFilters();
                });

            }, 100);
        }

        function applyFilters() {
            if (!logsTable) return;

            // Clear previous custom filters
            $.fn.dataTable.ext.search = [];

            const dateFrom = document.getElementById('filterDateFrom').value;
            const dateTo = document.getElementById('filterDateTo').value;
            const action = document.getElementById('filterAction').value;
            <?php if (in_array($role, ['Admin', 'Manager'])): ?>
            const username = document.getElementById('filterUsername').value;
            <?php endif; ?>

            // Date range filter
            if (dateFrom || dateTo) {
                $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
                    const timestamp = logsData[dataIndex]?.timestamp_iso;
                    if (!timestamp) return true;

                    const recordDate = new Date(timestamp);
                    const fromDate = dateFrom ? new Date(dateFrom) : null;
                    const toDate = dateTo ? new Date(dateTo + 'T23:59:59') : null;

                    if (fromDate && recordDate < fromDate) return false;
                    if (toDate && recordDate > toDate) return false;
                    return true;
                });
            }

            // Column filters
            logsTable.columns().search('');
            if (action) logsTable.column(2).search(action);
            <?php if (in_array($role, ['Admin', 'Manager'])): ?>
            if (username) logsTable.column(1).search(username);
            <?php endif; ?>

            logsTable.draw();
        }

        function clearFilters() {
            document.getElementById('filterDateFrom').value = '';
            document.getElementById('filterDateTo').value = '';
            document.getElementById('filterAction').value = '';
            <?php if (in_array($role, ['Admin', 'Manager'])): ?>
            document.getElementById('filterUsername').value = '';
            <?php endif; ?>

            if (logsTable) {
                $.fn.dataTable.ext.search = [];
                logsTable.columns().search('').draw();
            }
        }
    </script>
</body>
</html>
