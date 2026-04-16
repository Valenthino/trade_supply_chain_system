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
$current_page = 'inventory';

// RBAC
$allowedRoles = ['Admin', 'Manager', 'Warehouse Clerk', 'Procurement Officer'];
if (!in_array($role, $allowedRoles)) {
    header("Location: dashboard.php");
    exit();
}

// Handle AJAX requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    try {
        switch ($_GET['action']) {

            // ===================== GET STOCK SUMMARY =====================
            case 'getStockSummary':
                $conn = getDBConnection();
                $stmt = $conn->prepare("
                    SELECT
                        w.warehouse_id, w.warehouse_name, l.location_name,
                        COALESCE(pin.total_in, 0) as total_in,
                        COALESCE(pout.total_out, 0) as total_out,
                        COALESCE(pin.total_in, 0) - COALESCE(pout.total_out, 0) as current_stock,
                        COALESCE(pin.bags_in, 0) as bags_in,
                        COALESCE(pout.bags_out, 0) as bags_out
                    FROM settings_warehouses w
                    LEFT JOIN settings_locations l ON w.location_id = l.location_id
                    LEFT JOIN (
                        SELECT warehouse_id, SUM(weight_kg) as total_in, SUM(COALESCE(num_bags,0)) as bags_in
                        FROM purchases GROUP BY warehouse_id
                    ) pin ON w.warehouse_id = pin.warehouse_id
                    LEFT JOIN (
                        SELECT origin_warehouse_id, SUM(weight_kg) as total_out, SUM(COALESCE(num_bags,0)) as bags_out
                        FROM deliveries WHERE status NOT IN ('Rejected','Reassigned')
                        GROUP BY origin_warehouse_id
                    ) pout ON w.warehouse_id = pout.origin_warehouse_id
                    WHERE w.is_active = 1
                    ORDER BY w.warehouse_name
                ");
                $stmt->execute();
                $result = $stmt->get_result();

                $warehouses = [];
                $totalIn = 0;
                $totalOut = 0;
                $currentStock = 0;

                while ($row = $result->fetch_assoc()) {
                    $warehouses[] = [
                        'warehouse_id' => $row['warehouse_id'],
                        'warehouse_name' => $row['warehouse_name'],
                        'location_name' => $row['location_name'] ?? '',
                        'total_in' => round(floatval($row['total_in']), 2),
                        'total_out' => round(floatval($row['total_out']), 2),
                        'current_stock' => round(floatval($row['current_stock']), 2),
                        'bags_in' => intval($row['bags_in']),
                        'bags_out' => intval($row['bags_out'])
                    ];
                    $totalIn += floatval($row['total_in']);
                    $totalOut += floatval($row['total_out']);
                    $currentStock += floatval($row['current_stock']);
                }

                $stmt->close();
                $conn->close();

                echo json_encode([
                    'success' => true,
                    'data' => [
                        'warehouses' => $warehouses,
                        'totalIn' => round($totalIn, 2),
                        'totalOut' => round($totalOut, 2),
                        'currentStock' => round($currentStock, 2)
                    ]
                ]);
                exit();

            // ===================== GET STOCK LEDGER =====================
            case 'getStockLedger':
                $conn = getDBConnection();
                $warehouseId = isset($_GET['warehouse_id']) ? intval($_GET['warehouse_id']) : 0;

                $sql = "
                    SELECT * FROM (
                        SELECT
                            p.date, 'IN' as movement_type, p.purchase_id as reference_id,
                            CONCAT('Purchase from ', COALESCE(p.supplier_name, 'Unknown')) as description,
                            w.warehouse_name, p.warehouse_id,
                            p.weight_kg, COALESCE(p.num_bags, 0) as num_bags, p.season
                        FROM purchases p
                        LEFT JOIN settings_warehouses w ON p.warehouse_id = w.warehouse_id

                        UNION ALL

                        SELECT
                            d.date, 'OUT' as movement_type, d.delivery_id as reference_id,
                            CONCAT('Delivery to ', COALESCE(d.customer_name, 'Unknown')) as description,
                            w.warehouse_name, d.origin_warehouse_id as warehouse_id,
                            d.weight_kg, COALESCE(d.num_bags, 0) as num_bags, d.season
                        FROM deliveries d
                        LEFT JOIN settings_warehouses w ON d.origin_warehouse_id = w.warehouse_id
                        WHERE d.status NOT IN ('Rejected','Reassigned')
                    ) stock_movements
                    WHERE 1=1
                ";

                if ($warehouseId > 0) {
                    $sql .= " AND warehouse_id = ?";
                }

                $sql .= " ORDER BY date ASC, movement_type DESC";

                $stmt = $conn->prepare($sql);

                if ($warehouseId > 0) {
                    $stmt->bind_param("i", $warehouseId);
                }

                $stmt->execute();
                $result = $stmt->get_result();

                $movements = [];
                while ($row = $result->fetch_assoc()) {
                    $movements[] = $row;
                }

                // Compute running balance
                $runningBalance = 0;
                foreach ($movements as &$m) {
                    if ($m['movement_type'] === 'IN') {
                        $runningBalance += floatval($m['weight_kg']);
                    } else {
                        $runningBalance -= floatval($m['weight_kg']);
                    }
                    $m['running_balance'] = round($runningBalance, 2);
                }
                unset($m);

                // Format dates for display
                foreach ($movements as &$m) {
                    $m['date_display'] = date('M d, Y', strtotime($m['date']));
                    $m['date_raw'] = $m['date'];
                }
                unset($m);

                $stmt->close();
                $conn->close();

                echo json_encode(['success' => true, 'data' => $movements]);
                exit();

            // ===================== GET WAREHOUSES (dropdown) =====================
            case 'getWarehouses':
                $conn = getDBConnection();
                $stmt = $conn->prepare("SELECT warehouse_id, warehouse_name FROM settings_warehouses WHERE is_active = 1 ORDER BY warehouse_name");
                $stmt->execute();
                $result = $stmt->get_result();

                $warehouses = [];
                while ($row = $result->fetch_assoc()) {
                    $warehouses[] = $row;
                }

                $stmt->close();
                $conn->close();

                echo json_encode(['success' => true, 'data' => $warehouses]);
                exit();

            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
                exit();
        }
    } catch (Exception $e) {
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
  <title>Commodity Flow — Inventory</title>

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
          <i class="fas fa-warehouse text-brand-500 text-sm"></i>
          <h1 class="text-base font-bold text-slate-800 dark:text-white">Inventory</h1>
        </div>

        <div class="ml-auto flex items-center gap-3">
          <span class="text-xs text-slate-500 dark:text-slate-400">Welcome, <?php echo htmlspecialchars($username); ?></span>
          <button onclick="refreshData()" class="bg-brand-500 hover:bg-brand-600 text-white text-xs font-semibold px-3.5 py-2 rounded-lg transition-colors">
            <i class="fas fa-sync-alt mr-1"></i> Refresh
          </button>
        </div>
      </header>

      <!-- MAIN CONTENT -->
      <main class="flex-1 overflow-y-auto p-5 space-y-5">

        <!-- Filter Bar -->
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card p-4">
          <div class="flex items-center justify-between mb-3">
            <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-200"><i class="fas fa-filter mr-1 text-brand-500"></i> Filter</h3>
          </div>
          <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
            <div>
              <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1"><i class="fas fa-warehouse mr-1"></i> Warehouse</label>
              <select id="warehouseFilter" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors" onchange="loadStockLedger()">
                <option value="0">All Warehouses</option>
              </select>
            </div>
          </div>
        </div>

        <!-- Summary Cards -->
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4" id="stockSummaryCards">
          <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card overflow-hidden">
            <div class="h-0.5 bg-brand-500"></div>
            <div class="p-4">
              <div class="flex items-start justify-between">
                <div>
                  <p class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Current Stock</p>
                  <p class="mt-2 text-2xl font-bold text-slate-800 dark:text-white tabular" id="kpiCurrentStock"><span class="skeleton inline-block h-7 w-28 rounded"></span></p>
                </div>
                <div class="w-10 h-10 rounded-xl bg-brand-50 dark:bg-brand-900/20 flex items-center justify-center flex-shrink-0">
                  <i class="fas fa-boxes-stacked text-brand-500 text-sm"></i>
                </div>
              </div>
            </div>
          </div>
          <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card overflow-hidden">
            <div class="h-0.5 bg-emerald-500"></div>
            <div class="p-4">
              <div class="flex items-start justify-between">
                <div>
                  <p class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Total IN (Purchases)</p>
                  <p class="mt-2 text-2xl font-bold text-slate-800 dark:text-white tabular" id="kpiTotalIn"><span class="skeleton inline-block h-7 w-28 rounded"></span></p>
                </div>
                <div class="w-10 h-10 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 flex items-center justify-center flex-shrink-0">
                  <i class="fas fa-arrow-down text-emerald-500 text-sm"></i>
                </div>
              </div>
            </div>
          </div>
          <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card overflow-hidden">
            <div class="h-0.5 bg-rose-500"></div>
            <div class="p-4">
              <div class="flex items-start justify-between">
                <div>
                  <p class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Total OUT (Deliveries)</p>
                  <p class="mt-2 text-2xl font-bold text-slate-800 dark:text-white tabular" id="kpiTotalOut"><span class="skeleton inline-block h-7 w-28 rounded"></span></p>
                </div>
                <div class="w-10 h-10 rounded-xl bg-rose-50 dark:bg-rose-900/20 flex items-center justify-center flex-shrink-0">
                  <i class="fas fa-arrow-up text-rose-500 text-sm"></i>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Per-Warehouse Stock Cards -->
        <div id="warehouseCards" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-3">
          <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card p-4"><div class="skeleton h-20 w-full rounded"></div></div>
          <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card p-4"><div class="skeleton h-20 w-full rounded"></div></div>
          <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card p-4"><div class="skeleton h-20 w-full rounded"></div></div>
        </div>

        <!-- Stock Ledger DataTable -->
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card">
          <div class="flex items-center justify-between p-4 border-b border-slate-200 dark:border-slate-700">
            <h2 class="text-sm font-semibold text-slate-700 dark:text-slate-200"><i class="fas fa-list-ol mr-1 text-brand-500"></i> Stock Ledger</h2>
          </div>

          <!-- Skeleton Loader -->
          <div id="ledgerSkeleton" class="p-4 space-y-3">
            <div class="flex gap-3"><div class="skeleton h-4 flex-1 rounded"></div><div class="skeleton h-4 flex-1 rounded"></div><div class="skeleton h-4 flex-1 rounded"></div><div class="skeleton h-4 flex-1 rounded"></div><div class="skeleton h-4 flex-1 rounded"></div><div class="skeleton h-4 flex-1 rounded"></div></div>
            <div class="flex gap-3"><div class="skeleton h-4 flex-1 rounded"></div><div class="skeleton h-4 flex-1 rounded"></div><div class="skeleton h-4 flex-1 rounded"></div><div class="skeleton h-4 flex-1 rounded"></div><div class="skeleton h-4 flex-1 rounded"></div><div class="skeleton h-4 flex-1 rounded"></div></div>
            <div class="flex gap-3"><div class="skeleton h-4 flex-1 rounded"></div><div class="skeleton h-4 flex-1 rounded"></div><div class="skeleton h-4 flex-1 rounded"></div><div class="skeleton h-4 flex-1 rounded"></div><div class="skeleton h-4 flex-1 rounded"></div><div class="skeleton h-4 flex-1 rounded"></div></div>
            <div class="flex gap-3"><div class="skeleton h-4 flex-1 rounded"></div><div class="skeleton h-4 flex-1 rounded"></div><div class="skeleton h-4 flex-1 rounded"></div><div class="skeleton h-4 flex-1 rounded"></div><div class="skeleton h-4 flex-1 rounded"></div><div class="skeleton h-4 flex-1 rounded"></div></div>
            <div class="flex gap-3"><div class="skeleton h-4 flex-1 rounded"></div><div class="skeleton h-4 flex-1 rounded"></div><div class="skeleton h-4 flex-1 rounded"></div><div class="skeleton h-4 flex-1 rounded"></div><div class="skeleton h-4 flex-1 rounded"></div><div class="skeleton h-4 flex-1 rounded"></div></div>
            <div class="flex gap-3"><div class="skeleton h-4 flex-1 rounded"></div><div class="skeleton h-4 flex-1 rounded"></div><div class="skeleton h-4 flex-1 rounded"></div><div class="skeleton h-4 flex-1 rounded"></div><div class="skeleton h-4 flex-1 rounded"></div><div class="skeleton h-4 flex-1 rounded"></div></div>
            <div class="flex gap-3"><div class="skeleton h-4 flex-1 rounded"></div><div class="skeleton h-4 flex-1 rounded"></div><div class="skeleton h-4 flex-1 rounded"></div><div class="skeleton h-4 flex-1 rounded"></div><div class="skeleton h-4 flex-1 rounded"></div><div class="skeleton h-4 flex-1 rounded"></div></div>
            <div class="flex gap-3"><div class="skeleton h-4 flex-1 rounded"></div><div class="skeleton h-4 flex-1 rounded"></div><div class="skeleton h-4 flex-1 rounded"></div><div class="skeleton h-4 flex-1 rounded"></div><div class="skeleton h-4 flex-1 rounded"></div><div class="skeleton h-4 flex-1 rounded"></div></div>
          </div>

          <!-- DataTable Container -->
          <div id="tableContainer" style="display: none;" class="p-4">
            <p class="text-xs text-slate-400 dark:text-slate-500 mb-2 md:hidden"><i class="fas fa-arrows-alt-h mr-1"></i> Swipe left/right to see all columns</p>
            <div class="overflow-x-auto -webkit-overflow-scrolling-touch">
              <table id="stockLedgerTable" class="display responsive nowrap" style="width:100%">
                <thead>
                  <tr>
                    <th>Date</th>
                    <th>Type</th>
                    <th>Reference</th>
                    <th>Description</th>
                    <th>Warehouse</th>
                    <th>Weight (kg)</th>
                    <th>Bags</th>
                    <th>Balance (kg)</th>
                    <th>Season</th>
                  </tr>
                </thead>
                <tbody></tbody>
              </table>
            </div>
          </div>
        </div>

      </main><!-- /main -->
    </div><!-- /flex-1 -->
  </div><!-- /appRoot -->

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
        let stockLedgerTable;
        let ledgerData = [];

        $(document).ready(function() {
            loadWarehouses();
            loadStockSummary();
            loadStockLedger();
        });

        // ===================== Format Numbers =====================
        function formatNumber(num) {
            return parseFloat(num).toLocaleString('en-US', { maximumFractionDigits: 0 });
        }

        function formatTons(kg) {
            return (parseFloat(kg) / 1000).toLocaleString('en-US', { maximumFractionDigits: 1 }) + ' T';
        }

        // ===================== Load Warehouses (dropdown) =====================
        function loadWarehouses() {
            $.ajax({
                url: '?action=getWarehouses',
                method: 'GET',
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        var select = document.getElementById('warehouseFilter');
                        var currentVal = select.value;
                        select.innerHTML = '<option value="0">All Warehouses</option>';
                        response.data.forEach(function(w) {
                            var opt = document.createElement('option');
                            opt.value = w.warehouse_id;
                            opt.textContent = w.warehouse_name;
                            select.appendChild(opt);
                        });
                        if (currentVal) select.value = currentVal;
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Failed to load warehouses:', error);
                }
            });
        }

        // ===================== Load Stock Summary =====================
        function loadStockSummary() {
            // Show skeleton in KPI cards
            $('#kpiCurrentStock').html('<span class="skeleton inline-block h-7 w-28 rounded"></span>');
            $('#kpiTotalIn').html('<span class="skeleton inline-block h-7 w-28 rounded"></span>');
            $('#kpiTotalOut').html('<span class="skeleton inline-block h-7 w-28 rounded"></span>');
            $('#warehouseCards').html(
                '<div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card p-4"><div class="skeleton h-20 w-full rounded"></div></div>' +
                '<div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card p-4"><div class="skeleton h-20 w-full rounded"></div></div>' +
                '<div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card p-4"><div class="skeleton h-20 w-full rounded"></div></div>'
            );

            $.ajax({
                url: '?action=getStockSummary',
                method: 'GET',
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        var d = response.data;

                        // Update KPI cards
                        $('#kpiCurrentStock').html(formatNumber(d.currentStock) + ' kg<br><small class="text-xs text-slate-400 dark:text-slate-500">' + formatTons(d.currentStock) + '</small>');
                        $('#kpiTotalIn').html(formatNumber(d.totalIn) + ' kg<br><small class="text-xs text-slate-400 dark:text-slate-500">' + formatTons(d.totalIn) + '</small>');
                        $('#kpiTotalOut').html(formatNumber(d.totalOut) + ' kg<br><small class="text-xs text-slate-400 dark:text-slate-500">' + formatTons(d.totalOut) + '</small>');

                        // Build per-warehouse cards
                        var cardsHTML = '';
                        d.warehouses.forEach(function(w) {
                            var stockColor = w.current_stock > 0 ? 'text-emerald-500' : (w.current_stock < 0 ? 'text-rose-500' : 'text-slate-500');
                            var borderColor = w.current_stock > 0 ? 'border-l-emerald-500' : (w.current_stock < 0 ? 'border-l-rose-500' : 'border-l-slate-400');
                            cardsHTML += '<div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card border-l-4 ' + borderColor + ' p-4">' +
                                '<div class="font-bold text-sm text-slate-800 dark:text-white mb-1">' + (w.warehouse_name || 'Unknown') + '</div>' +
                                '<div class="text-xs text-slate-400 dark:text-slate-500 mb-2">' + (w.location_name || '') + '</div>' +
                                '<div class="text-xl font-bold ' + stockColor + '">' + formatNumber(w.current_stock) + ' kg</div>' +
                                '<div class="text-[11px] text-slate-400 dark:text-slate-500 mt-1">' +
                                    '<span class="text-emerald-500">IN: ' + formatNumber(w.total_in) + '</span> &middot; ' +
                                    '<span class="text-rose-500">OUT: ' + formatNumber(w.total_out) + '</span>' +
                                '</div>' +
                                '<div class="text-[11px] text-slate-400 dark:text-slate-500 mt-0.5">' +
                                    'Bags: ' + w.bags_in + ' in / ' + w.bags_out + ' out' +
                                '</div>' +
                            '</div>';
                        });

                        if (d.warehouses.length === 0) {
                            cardsHTML = '<div class="col-span-full text-center text-slate-400 dark:text-slate-500 py-5">No warehouse data available</div>';
                        }

                        $('#warehouseCards').html(cardsHTML);
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: response.message || 'Failed to load stock summary'
                        });
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Stock summary error:', error);
                    Swal.fire({
                        icon: 'error',
                        title: 'Connection Error',
                        text: 'Could not connect to server. Please check console for details.'
                    });
                }
            });
        }

        // ===================== Load Stock Ledger =====================
        function loadStockLedger() {
            $('#ledgerSkeleton').show();
            $('#tableContainer').hide();

            var warehouseId = document.getElementById('warehouseFilter').value || 0;

            $.ajax({
                url: '?action=getStockLedger&warehouse_id=' + warehouseId,
                method: 'GET',
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        ledgerData = response.data;
                        initializeDataTable(response.data);
                    } else {
                        $('#ledgerSkeleton').hide();
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: response.message || 'Failed to load stock ledger'
                        });
                    }
                },
                error: function(xhr, status, error) {
                    $('#ledgerSkeleton').hide();
                    console.error('Stock ledger error:', error);
                    Swal.fire({
                        icon: 'error',
                        title: 'Connection Error',
                        text: 'Could not connect to server. Please check console for details.'
                    });
                }
            });
        }

        // ===================== DataTable =====================
        function initializeDataTable(data) {
            if (stockLedgerTable) {
                stockLedgerTable.destroy();
                $('#stockLedgerTable tbody').empty();
            }

            var columns = [
                {
                    data: 'date_display',
                    title: 'Date',
                    render: function(data, type, row) {
                        if (type === 'sort') return row.date_raw;
                        return data;
                    }
                },
                {
                    data: 'movement_type',
                    title: 'Type',
                    render: function(data) {
                        if (data === 'IN') {
                            return '<span class="status-badge" style="background:#d4edda;color:#155724;border:1px solid #c3e6cb;"><i class="fas fa-arrow-down"></i> IN</span>';
                        } else {
                            return '<span class="status-badge" style="background:#f8d7da;color:#721c24;border:1px solid #f5c6cb;"><i class="fas fa-arrow-up"></i> OUT</span>';
                        }
                    }
                },
                {
                    data: 'reference_id',
                    title: 'Reference',
                    render: function(data, type, row) {
                        var prefix = row.movement_type === 'IN' ? 'PUR-' : 'DEL-';
                        return '<span style="font-size:12px;font-family:monospace;">' + prefix + data + '</span>';
                    }
                },
                {
                    data: 'description',
                    title: 'Description'
                },
                {
                    data: 'warehouse_name',
                    title: 'Warehouse',
                    render: function(data) {
                        return data || '<span style="color:var(--text-muted);">N/A</span>';
                    }
                },
                {
                    data: 'weight_kg',
                    title: 'Weight (kg)',
                    render: function(data, type, row) {
                        var val = parseFloat(data);
                        var color = row.movement_type === 'IN' ? '#34a853' : '#ea4335';
                        var sign = row.movement_type === 'IN' ? '+' : '-';
                        return '<span style="color:' + color + ';font-weight:600;" title="' + formatTons(data) + '">' + sign + formatNumber(val) + '</span>';
                    }
                },
                {
                    data: 'num_bags',
                    title: 'Bags',
                    render: function(data) {
                        return parseInt(data) || 0;
                    }
                },
                {
                    data: 'running_balance',
                    title: 'Balance (kg)',
                    render: function(data) {
                        var val = parseFloat(data);
                        var color = val >= 0 ? 'var(--text-primary)' : '#ea4335';
                        return '<strong style="color:' + color + ';" title="' + formatTons(data) + '">' + formatNumber(val) + '</strong>';
                    }
                },
                {
                    data: 'season',
                    title: 'Season',
                    render: function(data) {
                        return data || '<span style="color:var(--text-muted);">-</span>';
                    }
                }
            ];

            setTimeout(function() {
                stockLedgerTable = $('#stockLedgerTable').DataTable({
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
                    order: [[0, 'asc']]
                });

                $('#ledgerSkeleton').hide();
                $('#tableContainer').show();
            }, 100);
        }

        // ===================== Refresh Data =====================
        function refreshData() {
            loadStockSummary();
            loadStockLedger();
        }
    </script>
</body>
</html>