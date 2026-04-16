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
$current_page = 'profit-analysis';

// RBAC — profit figures are finance-only
$allowedRoles = ['Admin', 'Manager', 'Finance Officer'];
if (!in_array($role, $allowedRoles)) {
    header("Location: dashboard.php");
    exit();
}

$targetProfit = floatval(getSetting('target_profit_per_kg', '30'));

// Handle AJAX requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    try {
        switch ($_GET['action']) {
            case 'getProfitData':
                $conn = getDBConnection();
                $stmt = $conn->prepare("SELECT s.sale_id, s.customer_id, s.net_weight_kg, s.total_costs, s.gross_sale_amount, s.net_profit, s.profit_per_kg, c.customer_name
                    FROM sales s
                    LEFT JOIN customers c ON s.customer_id = c.customer_id
                    WHERE s.sale_status = 'Confirmed'
                    ORDER BY s.sale_id DESC");
                $stmt->execute();
                $result = $stmt->get_result();

                $records = [];
                $totalWeight = 0;
                $totalProfit = 0;

                while ($row = $result->fetch_assoc()) {
                    $weightKg = floatval($row['net_weight_kg']);
                    $totalCosts = floatval($row['total_costs']);
                    $netPayment = floatval($row['gross_sale_amount']);
                    $grossProfit = floatval($row['net_profit']);
                    $profitPerKg = floatval($row['profit_per_kg']);
                    $profitMarginPct = ($netPayment > 0) ? ($grossProfit / $netPayment) * 100 : 0;

                    // Target status
                    if ($profitPerKg >= ($targetProfit + 1)) {
                        $status = 'ABOVE TARGET';
                    } elseif ($profitPerKg <= ($targetProfit - 1)) {
                        $status = 'BELOW TARGET';
                    } else {
                        $status = 'AT TARGET';
                    }

                    $records[] = [
                        'sale_id' => $row['sale_id'],
                        'customer_name' => $row['customer_name'] ?? '',
                        'weight_kg' => $weightKg,
                        'total_costs' => $totalCosts,
                        'net_payment' => $netPayment,
                        'gross_profit' => $grossProfit,
                        'profit_per_kg' => $profitPerKg,
                        'profit_margin_pct' => $profitMarginPct,
                        'target' => $targetProfit,
                        'status' => $status
                    ];

                    $totalWeight += $weightKg;
                    $totalProfit += $grossProfit;
                }

                $stmt->close();
                $conn->close();

                $avgProfitPerKg = ($totalWeight > 0) ? $totalProfit / $totalWeight : 0;

                echo json_encode([
                    'success' => true,
                    'records' => $records,
                    'summary' => [
                        'total_weight' => $totalWeight,
                        'total_profit' => $totalProfit,
                        'avg_profit_per_kg' => $avgProfitPerKg
                    ]
                ]);
                exit();

            case 'getChartData':
                $conn = getDBConnection();

                // Per customer
                $stmt = $conn->prepare("SELECT s.customer_id, c.customer_name, AVG(s.profit_per_kg) as avg_profit_per_kg, SUM(s.net_profit) as total_profit
                    FROM sales s
                    LEFT JOIN customers c ON s.customer_id = c.customer_id
                    WHERE s.sale_status = 'Confirmed'
                    GROUP BY s.customer_id");
                $stmt->execute();
                $result = $stmt->get_result();

                $customers = [];
                while ($row = $result->fetch_assoc()) {
                    $customers[] = [
                        'customer_id' => $row['customer_id'],
                        'customer_name' => $row['customer_name'] ?? 'Unknown',
                        'avg_profit_per_kg' => round(floatval($row['avg_profit_per_kg']), 2),
                        'total_profit' => floatval($row['total_profit'])
                    ];
                }
                $stmt->close();

                // Over time
                $stmt = $conn->prepare("SELECT DATE_FORMAT(unloading_date, '%Y-%m') as month, SUM(net_profit) as total_profit, AVG(profit_per_kg) as avg_profit_per_kg
                    FROM sales
                    WHERE sale_status = 'Confirmed'
                    GROUP BY DATE_FORMAT(unloading_date, '%Y-%m')
                    ORDER BY month ASC");
                $stmt->execute();
                $result = $stmt->get_result();

                $timeline = [];
                while ($row = $result->fetch_assoc()) {
                    $timeline[] = [
                        'month' => $row['month'],
                        'total_profit' => floatval($row['total_profit']),
                        'avg_profit_per_kg' => round(floatval($row['avg_profit_per_kg']), 2)
                    ];
                }
                $stmt->close();

                $conn->close();

                echo json_encode([
                    'success' => true,
                    'customers' => $customers,
                    'timeline' => $timeline
                ]);
                exit();

            case 'getCashFlow':
                $conn = getDBConnection();
                $season = isset($_GET['season']) ? trim($_GET['season']) : getActiveSeason();
                $transactions = [];

                // outgoing payments
                $stmt = $conn->prepare("SELECT date, 'Payment Out' as category, counterpart_name as description, amount, payment_mode as mode, payment_id as ref FROM payments WHERE direction = 'Outgoing' AND season = ? ORDER BY date ASC");
                $stmt->bind_param("s", $season);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($r = $res->fetch_assoc()) {
                    $transactions[] = ['date' => $r['date'], 'category' => $r['category'], 'description' => $r['description'], 'cash_out' => floatval($r['amount']), 'cash_in' => 0, 'mode' => $r['mode'], 'ref' => $r['ref']];
                }
                $stmt->close();

                // incoming payments
                $stmt = $conn->prepare("SELECT date, 'Payment In' as category, counterpart_name as description, amount, payment_mode as mode, payment_id as ref FROM payments WHERE direction = 'Incoming' AND season = ? ORDER BY date ASC");
                $stmt->bind_param("s", $season);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($r = $res->fetch_assoc()) {
                    $transactions[] = ['date' => $r['date'], 'category' => $r['category'], 'description' => $r['description'], 'cash_out' => 0, 'cash_in' => floatval($r['amount']), 'mode' => $r['mode'], 'ref' => $r['ref']];
                }
                $stmt->close();

                // approved expenses
                $stmt = $conn->prepare("SELECT e.date, COALESCE(ec.category_name, 'Expense') as category, e.description, e.amount, 'Expense' as mode, e.expense_id as ref FROM expenses e LEFT JOIN settings_expense_categories ec ON e.category_id = ec.category_id WHERE e.season = ? AND e.status = 'Approved' ORDER BY e.date ASC");
                $stmt->bind_param("s", $season);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($r = $res->fetch_assoc()) {
                    $transactions[] = ['date' => $r['date'], 'category' => $r['category'], 'description' => $r['description'], 'cash_out' => floatval($r['amount']), 'cash_in' => 0, 'mode' => $r['mode'], 'ref' => $r['ref']];
                }
                $stmt->close();
                $conn->close();

                // sort by date asc
                usort($transactions, function($a, $b) { return strcmp($a['date'], $b['date']); });

                // running balance
                $balance = 0;
                $totalIn = 0;
                $totalOut = 0;
                foreach ($transactions as &$t) {
                    $totalIn += $t['cash_in'];
                    $totalOut += $t['cash_out'];
                    $balance += $t['cash_in'] - $t['cash_out'];
                    $t['balance'] = round($balance, 2);
                }
                unset($t);

                echo json_encode(['success' => true, 'data' => $transactions, 'summary' => ['total_in' => round($totalIn, 2), 'total_out' => round($totalOut, 2), 'net' => round($balance, 2)]]);
                exit();

            case 'getSimData':
                $conn = getDBConnection();
                $season = getActiveSeason();

                // unsold = purchased - delivered (excl rejected/reassigned)
                $stmt = $conn->prepare("SELECT COALESCE(SUM(weight_kg), 0) as t FROM purchases WHERE season = ?");
                $stmt->bind_param('s', $season);
                $stmt->execute();
                $totalIn = floatval($stmt->get_result()->fetch_assoc()['t']);
                $stmt->close();

                $stmt = $conn->prepare("SELECT COALESCE(SUM(weight_kg), 0) as t FROM deliveries WHERE season = ? AND status NOT IN ('Rejected','Reassigned')");
                $stmt->bind_param('s', $season);
                $stmt->execute();
                $totalOut = floatval($stmt->get_result()->fetch_assoc()['t']);
                $stmt->close();

                $unsold = max($totalIn - $totalOut, 0);

                // avg sell price from confirmed sales
                $stmt = $conn->prepare("SELECT AVG(selling_price_per_kg) as avg_price FROM sales WHERE season = ? AND sale_status IN ('Draft','Confirmed')");
                $stmt->bind_param('s', $season);
                $stmt->execute();
                $avgPrice = floatval($stmt->get_result()->fetch_assoc()['avg_price']);
                $stmt->close();

                // current season profit
                $stmt = $conn->prepare("SELECT COALESCE(SUM(net_profit), 0) as total_profit FROM sales WHERE season = ? AND sale_status IN ('Draft','Confirmed')");
                $stmt->bind_param('s', $season);
                $stmt->execute();
                $currentProfit = floatval($stmt->get_result()->fetch_assoc()['total_profit']);
                $stmt->close();

                $conn->close();
                echo json_encode(['success' => true, 'data' => [
                    'unsold_stock' => round($unsold, 2),
                    'avg_sell_price' => round($avgPrice, 2),
                    'current_profit' => round($currentProfit, 2),
                    'season' => $season
                ]]);
                exit();

            case 'getPnL':
                $conn = getDBConnection();
                $season = isset($_GET['season']) ? trim($_GET['season']) : getActiveSeason();

                // revenue
                $revStmt = $conn->prepare("SELECT COALESCE(SUM(gross_sale_amount), 0) as revenue, COALESCE(SUM(net_weight_kg), 0) as volume_sold FROM sales WHERE season = ? AND sale_status IN ('Draft','Confirmed')");
                $revStmt->bind_param("s", $season);
                $revStmt->execute();
                $rev = $revStmt->get_result()->fetch_assoc();
                $revStmt->close();

                // cogs
                $cogsStmt = $conn->prepare("SELECT COALESCE(SUM(total_costs), 0) as cogs FROM sales WHERE season = ? AND sale_status IN ('Draft','Confirmed')");
                $cogsStmt->bind_param("s", $season);
                $cogsStmt->execute();
                $cogsRow = $cogsStmt->get_result()->fetch_assoc();
                $cogsStmt->close();

                // transport
                $transStmt = $conn->prepare("SELECT COALESCE(SUM(transport_cost), 0) as transport FROM sales WHERE season = ? AND sale_status IN ('Draft','Confirmed')");
                $transStmt->bind_param("s", $season);
                $transStmt->execute();
                $transRow = $transStmt->get_result()->fetch_assoc();
                $transStmt->close();

                // operating expenses by category
                $expStmt = $conn->prepare("SELECT COALESCE(ec.category_name, 'Uncategorized') as category, SUM(e.amount) as total FROM expenses e LEFT JOIN settings_expense_categories ec ON e.category_id = ec.category_id WHERE e.season = ? AND e.status = 'Approved' GROUP BY ec.category_name ORDER BY total DESC");
                $expStmt->bind_param("s", $season);
                $expStmt->execute();
                $expenses = $expStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $expStmt->close();

                $totalExpenses = 0;
                foreach ($expenses as $e) $totalExpenses += floatval($e['total']);

                // interest/financing
                $intStmt = $conn->prepare("SELECT COALESCE(SUM(interest_fees), 0) as interest FROM sales WHERE season = ? AND sale_status IN ('Draft','Confirmed')");
                $intStmt->bind_param("s", $season);
                $intStmt->execute();
                $intRow = $intStmt->get_result()->fetch_assoc();
                $intStmt->close();

                // net profit from sales
                $npStmt = $conn->prepare("SELECT COALESCE(SUM(net_profit), 0) as net_profit FROM sales WHERE season = ? AND sale_status IN ('Draft','Confirmed')");
                $npStmt->bind_param("s", $season);
                $npStmt->execute();
                $npRow = $npStmt->get_result()->fetch_assoc();
                $npStmt->close();

                // monthly breakdown
                $monthlyStmt = $conn->prepare("SELECT DATE_FORMAT(unloading_date, '%Y-%m') as month, SUM(gross_sale_amount) as revenue, SUM(total_costs) as costs, SUM(net_profit) as profit FROM sales WHERE season = ? AND sale_status IN ('Draft','Confirmed') GROUP BY DATE_FORMAT(unloading_date, '%Y-%m') ORDER BY month ASC");
                $monthlyStmt->bind_param("s", $season);
                $monthlyStmt->execute();
                $monthly = $monthlyStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $monthlyStmt->close();

                // total purchases
                $purStmt = $conn->prepare("SELECT COALESCE(SUM(total_cost), 0) as total_purchases, COALESCE(SUM(weight_kg), 0) as total_volume FROM purchases WHERE season = ?");
                $purStmt->bind_param("s", $season);
                $purStmt->execute();
                $purRow = $purStmt->get_result()->fetch_assoc();
                $purStmt->close();

                $conn->close();

                $revenue = floatval($rev['revenue']);
                $cogs = floatval($cogsRow['cogs']);
                $grossProfit = $revenue - $cogs;
                $netProfit = $grossProfit - $totalExpenses;

                echo json_encode(['success' => true, 'data' => [
                    'revenue' => round($revenue, 2),
                    'volume_sold' => round(floatval($rev['volume_sold']), 2),
                    'cogs' => round($cogs, 2),
                    'transport' => round(floatval($transRow['transport']), 2),
                    'interest' => round(floatval($intRow['interest']), 2),
                    'gross_profit' => round($grossProfit, 2),
                    'expenses' => $expenses,
                    'total_expenses' => round($totalExpenses, 2),
                    'net_profit' => round($netProfit, 2),
                    'sales_net_profit' => round(floatval($npRow['net_profit']), 2),
                    'total_purchases' => round(floatval($purRow['total_purchases']), 2),
                    'total_volume' => round(floatval($purRow['total_volume']), 2),
                    'monthly' => $monthly
                ]]);
                exit();

            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
                exit();
        }
    } catch (Exception $e) {
        error_log("profit-analysis.php error: " . $e->getMessage());
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
  <title>Commodity Flow &mdash; P&amp;L Analysis</title>

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

  <!-- App Styles -->
  <link rel="stylesheet" href="styles.css?v=5.0">

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
  <!-- Chart.js -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

  <style>
    /* Scrollbar */
    ::-webkit-scrollbar { width: 5px; height: 5px; }
    ::-webkit-scrollbar-track { background: transparent; }
    ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
    .dark ::-webkit-scrollbar-thumb { background: #334155; }

    /* Skeleton animation */
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

    /* Sidebar transitions */
    #sidebar { transition: width 280ms cubic-bezier(.16,1,.3,1); }
    .sidebar-label { transition: opacity 200ms, width 200ms; }
    .app-collapsed #sidebar { width: 64px; }
    .app-collapsed .sidebar-label { opacity: 0; width: 0; overflow: hidden; }
    .app-collapsed .sidebar-section-label { opacity: 0; }
    .app-collapsed .logo-text { opacity: 0; width: 0; overflow: hidden; }

    /* Active nav link */
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
    .dataTables_wrapper { font-size: 13px; }
    .dataTables_wrapper .dataTables_length,
    .dataTables_wrapper .dataTables_filter,
    .dataTables_wrapper .dataTables_info,
    .dataTables_wrapper .dataTables_paginate { font-size: 12px; padding: 8px 0; }
    .dataTables_wrapper .dataTables_filter input {
      border: 1px solid #e2e8f0; border-radius: 8px; padding: 6px 12px; font-size: 13px;
      background: #f8fafc; outline: none; transition: border-color 0.2s;
    }
    .dark .dataTables_wrapper .dataTables_filter input {
      background: #334155; border-color: #475569; color: #e2e8f0;
    }
    .dataTables_wrapper .dataTables_filter input:focus { border-color: #2d9d99; box-shadow: 0 0 0 2px rgba(45,157,153,0.15); }
    table.dataTable thead th { background: #f8fafc; color: #475569; font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 2px solid #e2e8f0; }
    .dark table.dataTable thead th { background: #1e293b; color: #94a3b8; border-bottom-color: #334155; }
    table.dataTable tbody td { padding: 10px 14px; border-bottom: 1px solid #f1f5f9; font-size: 13px; }
    .dark table.dataTable tbody td { border-bottom-color: #1e293b; color: #cbd5e1; }
    table.dataTable tbody tr:hover { background: rgba(45,157,153,0.04) !important; }
    .dark table.dataTable tbody tr:hover { background: rgba(45,157,153,0.08) !important; }
    .dt-buttons .dt-button {
      background: #f8fafc !important; border: 1px solid #e2e8f0 !important; border-radius: 8px !important;
      padding: 6px 14px !important; font-size: 12px !important; font-weight: 500 !important; color: #475569 !important;
      transition: all 0.2s !important;
    }
    .dark .dt-buttons .dt-button { background: #334155 !important; border-color: #475569 !important; color: #cbd5e1 !important; }
    .dt-buttons .dt-button:hover { background: #f1f5f9 !important; border-color: #2d9d99 !important; color: #2d9d99 !important; }

    /* Status badges */
    .status-badge.status-above-target {
      background: rgba(16,185,129,0.1); color: #059669;
      padding: 4px 10px; border-radius: 12px; font-size: 0.75rem; font-weight: 600; white-space: nowrap;
    }
    .dark .status-badge.status-above-target { background: rgba(16,185,129,0.15); color: #34d399; }
    .status-badge.status-below-target {
      background: rgba(244,63,94,0.1); color: #e11d48;
      padding: 4px 10px; border-radius: 12px; font-size: 0.75rem; font-weight: 600; white-space: nowrap;
    }
    .dark .status-badge.status-below-target { background: rgba(244,63,94,0.15); color: #fb7185; }
    .status-badge.status-at-target {
      background: rgba(245,158,11,0.1); color: #d97706;
      padding: 4px 10px; border-radius: 12px; font-size: 0.75rem; font-weight: 600; white-space: nowrap;
    }
    .dark .status-badge.status-at-target { background: rgba(245,158,11,0.15); color: #fbbf24; }

    /* Tab styles */
    .profit-tab-btn {
      padding: 10px 18px; border: none; background: transparent; color: #64748b;
      font-size: 13px; font-weight: 600; cursor: pointer;
      border-bottom: 3px solid transparent; transition: all 0.2s; display: flex; align-items: center; gap: 7px; white-space: nowrap;
    }
    .profit-tab-btn:hover { color: #2d9d99; background: rgba(45,157,153,0.04); }
    .profit-tab-btn.active { color: #2d9d99; border-bottom-color: #2d9d99; }
    .dark .profit-tab-btn { color: #94a3b8; }
    .dark .profit-tab-btn:hover { color: #4db8b4; background: rgba(45,157,153,0.08); }
    .dark .profit-tab-btn.active { color: #4db8b4; border-bottom-color: #4db8b4; }

    /* Tab content */
    .profit-tab-content { display: none; }
    .profit-tab-content.active { display: block; }
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
          <i class="fas fa-chart-bar text-brand-500 text-sm"></i>
          <h1 class="text-base font-bold text-slate-800 dark:text-white">P&L Analysis</h1>
          <span class="hidden sm:inline-block text-xs text-slate-400 dark:text-slate-500 font-normal ml-1">
            Logged in as <strong><?php echo htmlspecialchars($role); ?></strong>
          </span>
        </div>

        <div class="ml-auto flex items-center gap-3">
          <button onclick="refreshAll()" class="bg-brand-500 hover:bg-brand-600 text-white text-xs font-semibold px-3.5 py-2 rounded-lg transition-colors">
            <i class="fas fa-sync"></i> Refresh
          </button>
        </div>
      </header>

      <!-- MAIN CONTENT -->
      <main class="flex-1 overflow-y-auto p-5 space-y-5">

        <!-- Tab Navigation -->
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card overflow-hidden">
          <div class="flex border-b border-slate-200 dark:border-slate-700 overflow-x-auto px-2">
            <button class="profit-tab-btn active" id="profitTabBtn" onclick="switchProfitTab('profit')">
              <i class="fas fa-chart-pie"></i> Profit Analysis
            </button>
            <button class="profit-tab-btn" id="pnlTabBtn" onclick="switchProfitTab('pnl')">
              <i class="fas fa-file-invoice-dollar"></i> Profit & Loss
            </button>
            <button class="profit-tab-btn" id="cashFlowTabBtn" onclick="switchProfitTab('cashflow')">
              <i class="fas fa-money-bill-wave"></i> Cash Flow
            </button>
            <button class="profit-tab-btn" id="simulationTabBtn" onclick="switchProfitTab('simulation')">
              <i class="fas fa-flask"></i> Simulation
            </button>
          </div>
        </div>

        <!-- ====== TAB 1: Profit Analysis ====== -->
        <div class="profit-tab-content active" id="profitTab">

          <!-- Summary Cards -->
          <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-5">
            <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card overflow-hidden">
              <div class="h-0.5 bg-brand-500"></div>
              <div class="p-4">
                <div class="flex items-start justify-between">
                  <div>
                    <p class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Total Weight Sold (kg)</p>
                    <p class="mt-2 text-2xl font-bold text-slate-800 dark:text-white" id="totalWeight">0</p>
                  </div>
                  <div class="w-10 h-10 rounded-xl bg-brand-50 dark:bg-brand-900/20 flex items-center justify-center flex-shrink-0">
                    <i class="fas fa-weight-hanging text-brand-500 text-sm"></i>
                  </div>
                </div>
              </div>
            </div>
            <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card overflow-hidden">
              <div class="h-0.5 bg-emerald-500"></div>
              <div class="p-4">
                <div class="flex items-start justify-between">
                  <div>
                    <p class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Total Net Profit</p>
                    <p class="mt-2 text-2xl font-bold text-slate-800 dark:text-white" id="totalProfit">0</p>
                  </div>
                  <div class="w-10 h-10 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 flex items-center justify-center flex-shrink-0">
                    <i class="fas fa-coins text-emerald-500 text-sm"></i>
                  </div>
                </div>
              </div>
            </div>
            <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card overflow-hidden">
              <div class="h-0.5 bg-blue-500"></div>
              <div class="p-4">
                <div class="flex items-start justify-between">
                  <div>
                    <p class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Avg Profit/kg (F)</p>
                    <p class="mt-2 text-2xl font-bold text-slate-800 dark:text-white" id="avgProfitKg">0</p>
                  </div>
                  <div class="w-10 h-10 rounded-xl bg-blue-50 dark:bg-blue-900/20 flex items-center justify-center flex-shrink-0">
                    <i class="fas fa-chart-line text-blue-500 text-sm"></i>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Info Note -->
          <div class="flex items-center gap-3 p-3.5 bg-amber-50 dark:bg-amber-900/10 border border-amber-200 dark:border-amber-800/30 rounded-lg mb-5">
            <i class="fas fa-info-circle text-amber-500 text-sm flex-shrink-0"></i>
            <p class="text-xs text-amber-800 dark:text-amber-300">
              Only <strong>Confirmed</strong> sales are included. Target margin: <strong><?php echo $targetProfit; ?> F/kg</strong>.
            </p>
          </div>

          <!-- Data Table Section -->
          <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card overflow-hidden">
            <div class="flex items-center justify-between px-5 py-4 border-b border-slate-200 dark:border-slate-700">
              <h2 class="text-sm font-bold text-slate-800 dark:text-white flex items-center gap-2">
                <i class="fas fa-table text-brand-500 text-xs"></i> Profit Breakdown
              </h2>
            </div>

            <div class="p-5">
              <div id="skeletonLoader">
                <div class="space-y-3">
                  <div class="skeleton h-8 w-full rounded"></div>
                  <div class="skeleton h-6 w-full rounded"></div>
                  <div class="skeleton h-6 w-full rounded"></div>
                  <div class="skeleton h-6 w-full rounded"></div>
                  <div class="skeleton h-6 w-full rounded"></div>
                  <div class="skeleton h-6 w-full rounded"></div>
                  <div class="skeleton h-6 w-full rounded"></div>
                  <div class="skeleton h-6 w-full rounded"></div>
                </div>
              </div>

              <div id="tableContainer" style="display: none;">
                <div class="flex items-center gap-2 mb-3 text-xs text-slate-400 dark:text-slate-500 sm:hidden">
                  <i class="fas fa-arrows-alt-h"></i> Swipe left/right to see all columns
                </div>
                <div style="overflow-x: auto; -webkit-overflow-scrolling: touch;">
                  <table id="profitTable" class="display" style="width:100%"></table>
                </div>
              </div>
            </div>
          </div>

          <!-- Charts Section -->
          <div class="grid grid-cols-1 lg:grid-cols-2 gap-5 mt-5">
            <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card p-5">
              <h3 class="text-sm font-bold text-slate-800 dark:text-white flex items-center gap-2 mb-4">
                <i class="fas fa-chart-bar text-brand-500 text-xs"></i> Profit/kg by Customer
              </h3>
              <canvas id="customerProfitChart"></canvas>
            </div>
            <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card p-5">
              <h3 class="text-sm font-bold text-slate-800 dark:text-white flex items-center gap-2 mb-4">
                <i class="fas fa-chart-line text-brand-500 text-xs"></i> Profit Trend Over Time
              </h3>
              <canvas id="profitTrendChart"></canvas>
            </div>
          </div>

        </div><!-- /profitTab -->

        <!-- ====== TAB: Profit & Loss ====== -->
        <div class="profit-tab-content" id="pnlTab">
          <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card overflow-hidden">
            <div class="flex items-center gap-2 px-5 py-4 border-b border-slate-200 dark:border-slate-700">
              <i class="fas fa-file-invoice-dollar text-brand-500 text-xs"></i>
              <h3 class="text-sm font-bold text-slate-800 dark:text-white">Profit & Loss Statement</h3>
            </div>
            <div class="p-5">
              <div id="pnlSkeleton" class="skeleton" style="height:200px;"></div>
              <div id="pnlContent" style="display:none;"></div>
            </div>
          </div>
        </div><!-- /pnlTab -->

        <!-- ====== TAB 2: Cash Flow ====== -->
        <div class="profit-tab-content" id="cashFlowTab">
          <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card overflow-hidden">
            <div class="flex items-center gap-2 px-5 py-4 border-b border-slate-200 dark:border-slate-700">
              <i class="fas fa-money-bill-wave text-brand-500 text-xs"></i>
              <h2 class="text-sm font-bold text-slate-800 dark:text-white">Cash Flow</h2>
            </div>

            <div class="p-5">
              <div id="cashFlowSummary" class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-5"></div>

              <div id="cashFlowSkeleton">
                <div class="space-y-3">
                  <div class="skeleton h-8 w-full rounded"></div>
                  <div class="skeleton h-6 w-full rounded"></div>
                  <div class="skeleton h-6 w-full rounded"></div>
                  <div class="skeleton h-6 w-full rounded"></div>
                  <div class="skeleton h-6 w-full rounded"></div>
                </div>
              </div>

              <div id="cashFlowTableContainer" style="display:none;">
                <div class="flex items-center gap-2 mb-3 text-xs text-slate-400 dark:text-slate-500 sm:hidden">
                  <i class="fas fa-arrows-alt-h"></i> Swipe left/right to see all columns
                </div>
                <div style="overflow-x:auto;-webkit-overflow-scrolling:touch;">
                  <table id="cashFlowDT" class="display" style="width:100%;"></table>
                </div>
              </div>
            </div>
          </div>
        </div><!-- /cashFlowTab -->

        <!-- ====== TAB 3: Simulation ====== -->
        <div class="profit-tab-content" id="simulationTab">
          <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card overflow-hidden">
            <div class="flex items-center gap-2 px-5 py-4 border-b border-slate-200 dark:border-slate-700">
              <i class="fas fa-flask text-brand-500 text-xs"></i>
              <h2 class="text-sm font-bold text-slate-800 dark:text-white">What-If Simulation</h2>
            </div>

            <div class="p-5">
              <p class="text-sm text-slate-500 dark:text-slate-400 mb-5">Simulate the impact of price changes on your unsold stock and season profit.</p>

              <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 max-w-2xl">
                <div>
                  <label class="block text-xs font-semibold text-slate-600 dark:text-slate-400 mb-1.5">
                    <i class="fas fa-arrow-up mr-1"></i> Price Change (FCFA/kg)
                  </label>
                  <input type="number" id="simPriceChange" value="0" step="1" placeholder="e.g. -50 or +100"
                    class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
                  <p class="text-xs text-slate-400 dark:text-slate-500 mt-1">Positive = price increase, Negative = price drop</p>
                </div>
                <div>
                  <label class="block text-xs font-semibold text-slate-600 dark:text-slate-400 mb-1.5">
                    <i class="fas fa-boxes-stacked mr-1"></i> Unsold Stock (kg)
                  </label>
                  <input type="number" id="simUnsoldStock" value="0" step="1" readonly
                    class="w-full bg-slate-100 dark:bg-slate-600 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none transition-colors cursor-not-allowed">
                  <p class="text-xs text-slate-400 dark:text-slate-500 mt-1">Auto-loaded from current inventory</p>
                </div>
                <div>
                  <label class="block text-xs font-semibold text-slate-600 dark:text-slate-400 mb-1.5">
                    <i class="fas fa-money-bill mr-1"></i> Current Avg Sell Price (FCFA/kg)
                  </label>
                  <input type="number" id="simCurrentPrice" value="0" step="0.01" readonly
                    class="w-full bg-slate-100 dark:bg-slate-600 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none transition-colors cursor-not-allowed">
                </div>
                <div>
                  <label class="block text-xs font-semibold text-slate-600 dark:text-slate-400 mb-1.5">
                    <i class="fas fa-chart-line mr-1"></i> Current Season Profit
                  </label>
                  <input type="number" id="simCurrentProfit" value="0" step="0.01" readonly
                    class="w-full bg-slate-100 dark:bg-slate-600 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none transition-colors cursor-not-allowed">
                </div>
              </div>

              <button onclick="runSimulation()" class="mt-5 bg-brand-500 hover:bg-brand-600 text-white text-xs font-semibold px-3.5 py-2 rounded-lg transition-colors">
                <i class="fas fa-play"></i> Run Simulation
              </button>

              <div id="simResult" style="display:none;" class="mt-5 bg-slate-50 dark:bg-slate-700/50 border border-slate-200 dark:border-slate-600 rounded-xl p-5">
                <h4 class="text-sm font-bold text-slate-800 dark:text-white flex items-center gap-2 mb-4">
                  <i class="fas fa-chart-bar text-brand-500 text-xs"></i> Simulation Result
                </h4>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                  <div class="bg-white dark:bg-slate-800 rounded-lg border border-slate-200 dark:border-slate-700 p-3 text-center">
                    <div class="text-lg font-bold text-slate-800 dark:text-white" id="simNewPrice">&mdash;</div>
                    <div class="text-xs text-slate-500 dark:text-slate-400 uppercase mt-1">New Sell Price</div>
                  </div>
                  <div class="bg-white dark:bg-slate-800 rounded-lg border border-slate-200 dark:border-slate-700 p-3 text-center">
                    <div class="text-lg font-bold text-slate-800 dark:text-white" id="simRevenueImpact">&mdash;</div>
                    <div class="text-xs text-slate-500 dark:text-slate-400 uppercase mt-1">Revenue Impact</div>
                  </div>
                  <div class="bg-white dark:bg-slate-800 rounded-lg border border-slate-200 dark:border-slate-700 p-3 text-center">
                    <div class="text-lg font-bold text-slate-800 dark:text-white" id="simNewProfit">&mdash;</div>
                    <div class="text-xs text-slate-500 dark:text-slate-400 uppercase mt-1">Projected Profit</div>
                  </div>
                  <div class="bg-white dark:bg-slate-800 rounded-lg border border-slate-200 dark:border-slate-700 p-3 text-center">
                    <div class="text-lg font-bold text-slate-800 dark:text-white" id="simProfitChange">&mdash;</div>
                    <div class="text-xs text-slate-500 dark:text-slate-400 uppercase mt-1">Profit Change</div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div><!-- /simulationTab -->

      </main>
    </div>
  </div>

  <script>
    /**
     * Developed by Rameez Scripts
     * Profit Analysis - Read-Only Page
     */
    let profitTable;
    let profitData = [];
    let customerChart = null;
    let trendChart = null;
    const TARGET_MARGIN = <?php echo $targetProfit; ?>;

    let cashFlowLoaded = false;

    $(document).ready(function() {
        loadProfitData();
        loadChartData();
        loadSimData();
        loadPnL();

        // auto-select tab from URL param
        var params = new URLSearchParams(window.location.search);
        var tab = params.get('tab');
        if (tab === 'pnl' || tab === 'cashflow' || tab === 'simulation') {
            switchProfitTab(tab);
        }
    });

    function refreshAll() {
        loadProfitData();
        loadChartData();
        loadSimData();
        loadPnL();
        if (cashFlowLoaded) loadCashFlow();
    }

    function switchProfitTab(tab) {
        var tabMap = {profit:'profitTabBtn', pnl:'pnlTabBtn', cashflow:'cashFlowTabBtn', simulation:'simulationTabBtn'};
        var paneMap = {profit:'profitTab', pnl:'pnlTab', cashflow:'cashFlowTab', simulation:'simulationTab'};
        Object.keys(tabMap).forEach(function(t) {
            var btn = document.getElementById(tabMap[t]);
            var pane = document.getElementById(paneMap[t]);
            if (btn) btn.classList.toggle('active', t === tab);
            if (pane) pane.classList.toggle('active', t === tab);
        });

        if (tab === 'cashflow' && !cashFlowLoaded) {
            cashFlowLoaded = true;
            loadCashFlow();
        }
        if (tab === 'cashflow' && cashFlowDT) {
            setTimeout(function() { cashFlowDT.columns.adjust().responsive.recalc(); }, 100);
        }
        if (tab === 'profit' && profitTable) {
            setTimeout(function() { profitTable.columns.adjust().responsive.recalc(); }, 100);
        }
    }

    function loadProfitData() {
        $('#skeletonLoader').show();
        $('#tableContainer').hide();

        $.ajax({
            url: '?action=getProfitData',
            method: 'GET',
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    profitData = response.records;

                    // Update summary cards
                    var summary = response.summary;
                    document.getElementById('totalWeight').textContent = parseFloat(summary.total_weight).toLocaleString();
                    document.getElementById('totalProfit').textContent = parseFloat(summary.total_profit).toLocaleString();
                    document.getElementById('avgProfitKg').textContent = Math.round(parseFloat(summary.avg_profit_per_kg)).toLocaleString();

                    initializeDataTable(response.records);
                } else {
                    $('#skeletonLoader').hide();
                    Swal.fire({ icon: 'error', title: 'Error', text: response.message || 'Failed to load profit data' });
                }
            },
            error: function() {
                $('#skeletonLoader').hide();
                Swal.fire({ icon: 'error', title: 'Connection Error', text: 'Could not connect to server.' });
            }
        });
    }

    function initializeDataTable(data) {
        if (profitTable) {
            profitTable.destroy();
            $('#profitTable').empty();
        }

        var columns = [
            { data: 'sale_id', title: 'Sale ID' },
            { data: 'customer_name', title: 'Customer', defaultContent: '' },
            {
                data: 'weight_kg',
                title: 'Weight (kg)',
                render: function(data) { return data ? parseFloat(data).toLocaleString() : '0'; }
            },
            {
                data: 'total_costs',
                title: 'Total Costs',
                render: function(data) { return data ? parseFloat(data).toLocaleString() : '0'; }
            },
            {
                data: 'net_payment',
                title: 'Net Payment',
                render: function(data) { return data ? parseFloat(data).toLocaleString() : '0'; }
            },
            {
                data: 'gross_profit',
                title: 'Gross Profit',
                render: function(data) { return data ? parseFloat(data).toLocaleString() : '0'; }
            },
            {
                data: 'profit_per_kg',
                title: 'Profit/kg',
                render: function(data) { return data ? Math.round(parseFloat(data)).toLocaleString() : '0'; }
            },
            {
                data: 'profit_margin_pct',
                title: 'Margin %',
                render: function(data) { return data ? parseFloat(data).toFixed(1) + '%' : '0.0%'; }
            },
            {
                data: 'status',
                title: 'Status',
                render: function(data) {
                    if (data === 'ABOVE TARGET') {
                        return '<span class="status-badge status-above-target"><i class="fas fa-arrow-up"></i> Above Target</span>';
                    } else if (data === 'BELOW TARGET') {
                        return '<span class="status-badge status-below-target"><i class="fas fa-arrow-down"></i> Below Target</span>';
                    } else {
                        return '<span class="status-badge status-at-target"><i class="fas fa-equals"></i> At Target</span>';
                    }
                }
            }
        ];

        setTimeout(function() {
            profitTable = $('#profitTable').DataTable({
                data: data,
                destroy: true,
                columns: columns,
                pageLength: 50,
                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
                responsive: true,
                dom: 'Blfrtip',
                buttons: [
                    { extend: 'csv', text: '<i class="fas fa-file-csv"></i> CSV', exportOptions: { columns: ':visible' } },
                    { extend: 'pdf', text: '<i class="fas fa-file-pdf"></i> PDF', exportOptions: { columns: ':visible' } },
                    { extend: 'print', text: '<i class="fas fa-print"></i> Print', exportOptions: { columns: ':visible' } }
                ],
                order: [[0, 'desc']]
            });

            $('#skeletonLoader').hide();
            $('#tableContainer').show();
        }, 100);
    }

    function loadChartData() {
        $.ajax({
            url: '?action=getChartData',
            method: 'GET',
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    renderCustomerChart(response.customers);
                    renderTrendChart(response.timeline);
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: response.message || 'Failed to load chart data' });
                }
            },
            error: function() {
                Swal.fire({ icon: 'error', title: 'Connection Error', text: 'Could not load chart data.' });
            }
        });
    }

    function renderCustomerChart(customers) {
        if (customerChart) {
            customerChart.destroy();
        }

        var customerNames = customers.map(function(c) { return c.customer_name; });
        var profitPerKg = customers.map(function(c) { return c.avg_profit_per_kg; });

        var ctx = document.getElementById('customerProfitChart').getContext('2d');
        customerChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: customerNames,
                datasets: [{
                    label: 'Avg Profit/kg (F)',
                    data: profitPerKg,
                    backgroundColor: 'rgba(0,116,217,0.7)',
                    borderColor: '#0074D9',
                    borderWidth: 1
                }, {
                    label: 'Target (' + TARGET_MARGIN + ' F/kg)',
                    data: Array(customerNames.length).fill(TARGET_MARGIN),
                    type: 'line',
                    borderColor: '#ea4335',
                    borderDash: [5, 5],
                    borderWidth: 2,
                    pointRadius: 0,
                    fill: false
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: { beginAtZero: true, title: { display: true, text: 'F/kg' } }
                }
            }
        });
    }

    function renderTrendChart(timeline) {
        if (trendChart) {
            trendChart.destroy();
        }

        var months = timeline.map(function(t) { return t.month; });
        var avgProfitPerKg = timeline.map(function(t) { return t.avg_profit_per_kg; });

        var ctx = document.getElementById('profitTrendChart').getContext('2d');
        trendChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: months,
                datasets: [{
                    label: 'Avg Profit/kg (F)',
                    data: avgProfitPerKg,
                    borderColor: '#0074D9',
                    backgroundColor: 'rgba(0,116,217,0.1)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.3
                }, {
                    label: 'Target (' + TARGET_MARGIN + ' F/kg)',
                    data: Array(months.length).fill(TARGET_MARGIN),
                    borderColor: '#ea4335',
                    borderDash: [5, 5],
                    borderWidth: 2,
                    pointRadius: 0,
                    fill: false
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: { beginAtZero: true, title: { display: true, text: 'F/kg' } }
                }
            }
        });
    }

    // cash flow
    let cashFlowDT = null;
    function loadCashFlow() {
        $('#cashFlowSkeleton').show();
        $('#cashFlowTableContainer').hide();
        $('#cashFlowSummary').empty();

        $.getJSON('?action=getCashFlow', function(res) {
            $('#cashFlowSkeleton').hide();
            if (!res.success) return;

            var s = res.summary;
            var fmt = function(n) { return Math.abs(n).toLocaleString('en-US', {minimumFractionDigits: 0}); };

            // summary cards
            var netColor = s.net >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400';
            var sumHtml = '';
            sumHtml += '<div class="bg-white dark:bg-slate-800 rounded-lg border border-slate-200 dark:border-slate-700 p-4 text-center">';
            sumHtml += '<div class="text-xl font-bold text-emerald-600 dark:text-emerald-400">' + fmt(s.total_in) + '</div>';
            sumHtml += '<div class="text-xs text-slate-500 dark:text-slate-400 uppercase mt-1">Total Cash In</div></div>';

            sumHtml += '<div class="bg-white dark:bg-slate-800 rounded-lg border border-slate-200 dark:border-slate-700 p-4 text-center">';
            sumHtml += '<div class="text-xl font-bold text-rose-600 dark:text-rose-400">' + fmt(s.total_out) + '</div>';
            sumHtml += '<div class="text-xs text-slate-500 dark:text-slate-400 uppercase mt-1">Total Cash Out</div></div>';

            sumHtml += '<div class="bg-white dark:bg-slate-800 rounded-lg border-2 ' + (s.net >= 0 ? 'border-emerald-400 dark:border-emerald-600' : 'border-rose-400 dark:border-rose-600') + ' p-4 text-center">';
            sumHtml += '<div class="text-xl font-bold ' + netColor + '">' + (s.net >= 0 ? '+' : '-') + fmt(s.net) + '</div>';
            sumHtml += '<div class="text-xs text-slate-500 dark:text-slate-400 uppercase mt-1">Net Cash Flow</div></div>';
            document.getElementById('cashFlowSummary').innerHTML = sumHtml;

            // destroy prev instance
            if (cashFlowDT) { cashFlowDT.destroy(); $('#cashFlowDT').empty(); }

            var html = '<thead><tr><th>Date</th><th>Category</th><th>Description</th><th>Cash In</th><th>Cash Out</th><th>Balance</th></tr></thead><tbody>';
            res.data.forEach(function(t) {
                var balColor = t.balance >= 0 ? '#059669' : '#e11d48';
                html += '<tr>';
                html += '<td>' + t.date + '</td>';
                html += '<td>' + t.category + '</td>';
                html += '<td>' + (t.description || '') + '</td>';
                html += '<td style="text-align:right;color:#059669;font-weight:600;">' + (t.cash_in > 0 ? fmt(t.cash_in) : '-') + '</td>';
                html += '<td style="text-align:right;color:#e11d48;font-weight:600;">' + (t.cash_out > 0 ? fmt(t.cash_out) : '-') + '</td>';
                html += '<td style="text-align:right;color:' + balColor + ';font-weight:700;">' + (t.balance >= 0 ? '' : '-') + fmt(t.balance) + '</td>';
                html += '</tr>';
            });
            html += '</tbody>';
            $('#cashFlowDT').html(html);
            $('#cashFlowTableContainer').show();

            cashFlowDT = $('#cashFlowDT').DataTable({
                pageLength: 25,
                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
                order: [[0, 'desc']],
                dom: 'Blfrtip',
                buttons: [
                    { extend: 'csv', text: '<i class="fas fa-file-csv"></i> CSV', exportOptions: { columns: ':visible' } },
                    { extend: 'pdf', text: '<i class="fas fa-file-pdf"></i> PDF', exportOptions: { columns: ':visible' } },
                    { extend: 'print', text: '<i class="fas fa-print"></i> Print', exportOptions: { columns: ':visible' } }
                ],
                responsive: true
            });
        });
    }

    // what-if sim
    function loadSimData() {
        $.getJSON('?action=getSimData', function(res) {
            if (!res.success) return;
            document.getElementById('simUnsoldStock').value = res.data.unsold_stock;
            document.getElementById('simCurrentPrice').value = res.data.avg_sell_price;
            document.getElementById('simCurrentProfit').value = res.data.current_profit;
        });
    }

    function runSimulation() {
        var change = parseFloat(document.getElementById('simPriceChange').value) || 0;
        var unsold = parseFloat(document.getElementById('simUnsoldStock').value) || 0;
        var curPrice = parseFloat(document.getElementById('simCurrentPrice').value) || 0;
        var curProfit = parseFloat(document.getElementById('simCurrentProfit').value) || 0;

        if (unsold <= 0) {
            Swal.fire({ icon: 'info', title: 'No Unsold Stock', text: 'There is no unsold stock to simulate against.' });
            return;
        }

        var newPrice = curPrice + change;
        var revenueImpact = change * unsold;
        var newProfit = curProfit + revenueImpact;
        var profitChange = curProfit !== 0 ? ((newProfit - curProfit) / Math.abs(curProfit) * 100) : 0;

        var fmt = function(n) { return Math.abs(n).toLocaleString('en-US', {minimumFractionDigits: 0, maximumFractionDigits: 0}); };

        document.getElementById('simNewPrice').textContent = fmt(newPrice) + ' F/kg';

        var impactColor = revenueImpact >= 0 ? '#059669' : '#e11d48';
        document.getElementById('simRevenueImpact').innerHTML = '<span style="color:' + impactColor + ';">' + (revenueImpact >= 0 ? '+' : '-') + fmt(revenueImpact) + ' F</span>';

        document.getElementById('simNewProfit').innerHTML = '<span style="color:' + (newProfit >= 0 ? '#059669' : '#e11d48') + ';">' + fmt(newProfit) + ' F</span>';

        document.getElementById('simProfitChange').innerHTML = '<span style="color:' + (profitChange >= 0 ? '#059669' : '#e11d48') + ';">' + (profitChange >= 0 ? '+' : '') + profitChange.toFixed(1) + '%</span>';

        document.getElementById('simResult').style.display = 'block';
    }

    // P&L statement
    let pnlChart = null;
    function loadPnL() {
        document.getElementById('pnlSkeleton').style.display = 'block';
        document.getElementById('pnlContent').style.display = 'none';

        $.getJSON('?action=getPnL', function(res) {
            document.getElementById('pnlSkeleton').style.display = 'none';
            if (!res.success) return;
            var d = res.data;
            var fmt = function(n) { return parseFloat(n).toLocaleString('en-US', {minimumFractionDigits: 0}); };

            function row(label, amount, bold, indent, color) {
                var style = '';
                if (bold) style += 'font-weight:700;';
                if (indent) style += 'padding-left:24px;';
                if (color) style += 'color:' + color + ';';
                var borderStyle = bold ? 'border-top:2px solid #2d9d99;border-bottom:2px solid #2d9d99;' : 'border-bottom:1px solid #e2e8f0;';
                return '<tr style="' + borderStyle + '"><td style="padding:8px 12px;' + style + '">' + label + '</td><td style="padding:8px 12px;text-align:right;' + style + '">' + fmt(amount) + ' F</td></tr>';
            }

            var html = '<table style="width:100%;max-width:700px;border-collapse:collapse;font-size:14px;">';
            html += '<tr style="background:#2d9d99;color:#fff;"><td style="padding:10px 12px;font-weight:700;" colspan="2">PROFIT & LOSS STATEMENT</td></tr>';
            html += row('Revenue (Gross Sales)', d.revenue, true);
            html += row('Cost of Goods Sold', d.cogs, false, true);
            html += row('Transport Costs', d.transport, false, true);
            html += row('Interest / Financing', d.interest, false, true);
            html += row('Gross Profit', d.gross_profit, true, false, d.gross_profit >= 0 ? '#059669' : '#e11d48');
            html += '<tr><td colspan="2" style="padding:6px;"></td></tr>';
            html += '<tr style="background:#f8fafc;"><td style="padding:8px 12px;font-weight:600;" colspan="2">Operating Expenses</td></tr>';

            if (d.expenses && d.expenses.length > 0) {
                d.expenses.forEach(function(e) {
                    html += row(e.category, e.total, false, true);
                });
            }
            html += row('Total Operating Expenses', d.total_expenses, true);
            html += '<tr><td colspan="2" style="padding:6px;"></td></tr>';
            html += row('NET PROFIT', d.net_profit, true, false, d.net_profit >= 0 ? '#059669' : '#e11d48');
            html += '<tr><td colspan="2" style="padding:6px;"></td></tr>';
            html += '<tr style="background:#f8fafc;"><td style="padding:8px 12px;font-weight:600;" colspan="2">Reference</td></tr>';
            html += row('Total Purchases', d.total_purchases, false, true);
            html += row('Volume Purchased (kg)', d.total_volume, false, true);
            html += row('Volume Sold (kg)', d.volume_sold, false, true);
            html += row('Net Profit (from Sales)', d.sales_net_profit, false, true);
            html += '</table>';

            // monthly chart
            if (d.monthly && d.monthly.length > 0) {
                html += '<h4 style="margin-top:24px;" class="text-sm font-bold text-slate-800 dark:text-white flex items-center gap-2"><i class="fas fa-chart-bar text-brand-500 text-xs"></i> Monthly P&L Breakdown</h4>';
                html += '<canvas id="pnlChart" style="max-height:300px;margin-top:12px;"></canvas>';
            }

            document.getElementById('pnlContent').innerHTML = html;
            document.getElementById('pnlContent').style.display = 'block';

            // render chart
            if (d.monthly && d.monthly.length > 0) {
                if (pnlChart) pnlChart.destroy();
                var ctx = document.getElementById('pnlChart').getContext('2d');
                pnlChart = new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: d.monthly.map(function(m) { return m.month; }),
                        datasets: [
                            { label: 'Revenue', data: d.monthly.map(function(m) { return parseFloat(m.revenue); }), backgroundColor: '#0074D9' },
                            { label: 'Costs', data: d.monthly.map(function(m) { return parseFloat(m.costs); }), backgroundColor: '#e74c3c' },
                            { label: 'Profit', data: d.monthly.map(function(m) { return parseFloat(m.profit); }), backgroundColor: '#27ae60' }
                        ]
                    },
                    options: {
                        responsive: true,
                        plugins: { legend: { position: 'top' } },
                        scales: { y: { beginAtZero: true } }
                    }
                });
            }
        });
    }
  </script>

  <!-- Theme Init -->
  <script>
  (function() {
    var html = document.documentElement;
    var stored = localStorage.getItem('cp_theme');
    var dark = stored === 'dark' || (stored === null && window.matchMedia('(prefers-color-scheme: dark)').matches);
    html.classList.toggle('dark', dark);

    var btn = document.getElementById('themeToggleBtn');
    var icon = document.getElementById('themeIcon');
    function apply() {
      html.classList.toggle('dark', dark);
      localStorage.setItem('cp_theme', dark ? 'dark' : 'light');
      if (icon) icon.className = dark ? 'fas fa-sun w-4 text-sm' : 'fas fa-moon w-4 text-sm';
      var lbl = document.getElementById('themeLabel');
      if (lbl) lbl.textContent = dark ? 'Light Mode' : 'Dark Mode';
    }
    apply();
    if (btn) btn.addEventListener('click', function() { dark = !dark; apply(); });
  })();

  /* Sidebar collapse */
  (function() {
    var appRoot = document.getElementById('appRoot');
    var collapseBtn = document.getElementById('sidebarCollapseBtn');
    if (collapseBtn) {
      collapseBtn.addEventListener('click', function() {
        appRoot.classList.toggle('app-collapsed');
        var ic = document.getElementById('collapseIcon');
        if (ic) ic.style.transform = appRoot.classList.contains('app-collapsed') ? 'rotate(180deg)' : '';
      });
    }
  })();
  </script>

  <!-- i18n Loader -->
  <script>
  (function() {
    var lang = localStorage.getItem('cp_lang') || 'en';
    document.documentElement.lang = lang;
  })();
  </script>

</body>
</html>
