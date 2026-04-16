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
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="mobile-web-app-capable" content="yes">
  <title>Commodity Flow — Activity Logs</title>
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
  <link rel="stylesheet" href="styles.css">
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
    .dataTables_wrapper { font-size: 13px; }
    table.dataTable thead th { background: transparent; border-bottom: 1px solid #e2e8f0; color: #64748b; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; padding: 10px 12px; }
    table.dataTable tbody tr:hover { background: #f8fafc; }
    .dark table.dataTable tbody tr:hover { background: #1e293b; }
    table.dataTable tbody td { padding: 10px 12px; border-bottom: 1px solid #f1f5f9; }
    .dark table.dataTable tbody td { border-bottom-color: #1e293b; }
  </style>
</head>
<body class="h-full bg-slate-50 text-slate-800 font-sans antialiased dark:bg-slate-900 dark:text-slate-200">
  <?php include 'mobile-menu.php'; ?>
  <div class="flex h-full overflow-hidden" id="appRoot">
    <?php include 'sidebar.php'; ?>
    <div class="flex-1 flex flex-col min-w-0 overflow-hidden">
      <header class="h-14 bg-white dark:bg-slate-800 border-b border-slate-200 dark:border-slate-700 flex items-center gap-4 px-5 flex-shrink-0">
        <div class="flex items-center gap-2">
          <i class="fas fa-clipboard-list text-brand-500 text-sm"></i>
          <h1 class="text-base font-bold text-slate-800 dark:text-white">Activity Logs</h1>
        </div>
        <div class="ml-auto flex items-center gap-2">
          <button class="bg-slate-100 hover:bg-slate-200 text-slate-700 dark:bg-slate-700 dark:hover:bg-slate-600 dark:text-slate-300 text-xs font-semibold px-3.5 py-2 rounded-lg transition-colors" onclick="loadLogs()">
            <i class="fas fa-sync mr-1"></i> Refresh
          </button>
        </div>
      </header>
      <main class="flex-1 overflow-y-auto p-5">
        <!-- Filters Card -->
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card p-4 mb-4" id="filtersSection" style="display:none;">
          <div class="flex items-center justify-between mb-3">
            <div class="flex items-center gap-2">
              <i class="fas fa-filter text-brand-500 text-xs"></i>
              <h3 class="text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase tracking-wide">Filters</h3>
            </div>
            <button class="bg-slate-100 hover:bg-slate-200 text-slate-700 dark:bg-slate-700 dark:hover:bg-slate-600 dark:text-slate-300 text-xs font-semibold px-3 py-1.5 rounded-lg transition-colors" onclick="clearFilters()">
              <i class="fas fa-times-circle mr-1"></i> Clear All
            </button>
          </div>
          <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            <div>
              <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">Date From</label>
              <input type="date" id="filterDateFrom" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
            </div>
            <div>
              <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">Date To</label>
              <input type="date" id="filterDateTo" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
            </div>
            <div>
              <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">Action Type</label>
              <select id="filterAction" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
                <option value="">All Actions</option>
              </select>
            </div>
            <?php if (in_array($role, ['Admin', 'Manager'])): ?>
            <div>
              <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">Username</label>
              <select id="filterUsername" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
                <option value="">All Users</option>
              </select>
            </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- Loading Skeleton -->
        <div id="loadingSkeleton">
          <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card p-5">
            <?php for ($i = 0; $i < 8; $i++): ?>
            <div class="flex gap-3 mb-3">
              <div class="skeleton h-4 flex-1"></div>
              <div class="skeleton h-4 flex-1"></div>
              <div class="skeleton h-4 flex-[2]"></div>
              <div class="skeleton h-4 flex-[2]"></div>
              <div class="skeleton h-4 flex-1"></div>
              <div class="skeleton h-4 flex-1"></div>
            </div>
            <?php endfor; ?>
          </div>
        </div>

        <!-- DataTable Card -->
        <div id="tableContainer" style="display:none;" class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card">
          <div class="p-5 overflow-x-auto">
            <table id="logsTable" class="display" style="width:100%"></table>
          </div>
        </div>
      </main>
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
