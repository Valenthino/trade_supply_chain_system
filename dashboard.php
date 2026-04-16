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
$role = isset($_SESSION['role']) ? $_SESSION['role'] : 'Warehouse Clerk';
$user_id = $_SESSION['user_id'];
$current_page = 'dashboard';

// Handle AJAX requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');

    try {
        $conn = getDBConnection();

        switch ($_GET['action']) {

            // ===================== DASHBOARD OVERVIEW (ALL ROLES) =====================
            case 'getDashboardOverview':
                $period = isset($_GET['period']) ? $_GET['period'] : 'year';

                // Build date filter
                $dateFilter = '';
                $prevDateFilter = '';
                switch ($period) {
                    case 'today':
                        $dateFilter = "AND DATE(created_at) = CURDATE()";
                        $prevDateFilter = "AND DATE(created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
                        $saleDateCol = 'unloading_date';
                        $purDateCol = 'date';
                        $saleDateFilter = "AND DATE($saleDateCol) = CURDATE()";
                        $salePrevFilter = "AND DATE($saleDateCol) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
                        $purDateFilter = "AND DATE($purDateCol) = CURDATE()";
                        break;
                    case 'week':
                        $dateFilter = "AND YEARWEEK(created_at, 1) = YEARWEEK(CURDATE(), 1)";
                        $prevDateFilter = "AND YEARWEEK(created_at, 1) = YEARWEEK(DATE_SUB(CURDATE(), INTERVAL 1 WEEK), 1)";
                        $saleDateCol = 'unloading_date';
                        $purDateCol = 'date';
                        $saleDateFilter = "AND YEARWEEK($saleDateCol, 1) = YEARWEEK(CURDATE(), 1)";
                        $salePrevFilter = "AND YEARWEEK($saleDateCol, 1) = YEARWEEK(DATE_SUB(CURDATE(), INTERVAL 1 WEEK), 1)";
                        $purDateFilter = "AND YEARWEEK($purDateCol, 1) = YEARWEEK(CURDATE(), 1)";
                        break;
                    case 'month':
                        $dateFilter = "AND YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE())";
                        $prevDateFilter = "AND YEAR(created_at) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) AND MONTH(created_at) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))";
                        $saleDateCol = 'unloading_date';
                        $purDateCol = 'date';
                        $saleDateFilter = "AND YEAR($saleDateCol) = YEAR(CURDATE()) AND MONTH($saleDateCol) = MONTH(CURDATE())";
                        $salePrevFilter = "AND YEAR($saleDateCol) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) AND MONTH($saleDateCol) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))";
                        $purDateFilter = "AND YEAR($purDateCol) = YEAR(CURDATE()) AND MONTH($purDateCol) = MONTH(CURDATE())";
                        break;
                    case 'quarter':
                        $dateFilter = "AND QUARTER(created_at) = QUARTER(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())";
                        $prevDateFilter = "AND QUARTER(created_at) = QUARTER(DATE_SUB(CURDATE(), INTERVAL 1 QUARTER)) AND YEAR(created_at) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 QUARTER))";
                        $saleDateCol = 'unloading_date';
                        $purDateCol = 'date';
                        $saleDateFilter = "AND QUARTER($saleDateCol) = QUARTER(CURDATE()) AND YEAR($saleDateCol) = YEAR(CURDATE())";
                        $salePrevFilter = "AND QUARTER($saleDateCol) = QUARTER(DATE_SUB(CURDATE(), INTERVAL 1 QUARTER)) AND YEAR($saleDateCol) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 QUARTER))";
                        $purDateFilter = "AND QUARTER($purDateCol) = QUARTER(CURDATE()) AND YEAR($purDateCol) = YEAR(CURDATE())";
                        break;
                    default: // year
                        $dateFilter = "";
                        $prevDateFilter = "";
                        $saleDateCol = 'unloading_date';
                        $purDateCol = 'date';
                        $saleDateFilter = "";
                        $salePrevFilter = "";
                        $purDateFilter = "";
                        break;
                }

                $activeSeason = getActiveSeason();

                // 1. Total Stock (all time - stock is cumulative)
                $stockExclude = "('Rejected','Reassigned')";
                $totalIn = $conn->query("SELECT COALESCE(SUM(weight_kg), 0) as t FROM purchases")->fetch_assoc()['t'];
                $totalOut = $conn->query("SELECT COALESCE(SUM(weight_kg), 0) as t FROM deliveries WHERE status NOT IN $stockExclude")->fetch_assoc()['t'];
                $totalStock = round($totalIn - $totalOut, 2);

                // Stock last month for evolution
                $stockInLastMonth = $conn->query("SELECT COALESCE(SUM(weight_kg), 0) as t FROM purchases WHERE date < DATE_FORMAT(CURDATE(), '%Y-%m-01')")->fetch_assoc()['t'];
                $stockOutLastMonth = $conn->query("SELECT COALESCE(SUM(weight_kg), 0) as t FROM deliveries WHERE status NOT IN $stockExclude AND date < DATE_FORMAT(CURDATE(), '%Y-%m-01')")->fetch_assoc()['t'];
                $lastMonthStock = round($stockInLastMonth - $stockOutLastMonth, 2);
                $stockEvolution = $lastMonthStock > 0 ? round(($totalStock - $lastMonthStock) / $lastMonthStock * 100, 1) : 0;

                // 2. Sales Total + Volume (filtered by active season)
                $seasonFilter = $activeSeason ? "AND season = '" . $conn->real_escape_string($activeSeason) . "'" : "";
                $salesCurr = $conn->query("SELECT COALESCE(SUM(gross_sale_amount),0) as revenue, COALESCE(SUM(net_weight_kg),0) as volume, COALESCE(SUM(net_profit),0) as profit FROM sales WHERE sale_status = 'Confirmed' $seasonFilter $saleDateFilter")->fetch_assoc();
                $salesPrev = $conn->query("SELECT COALESCE(SUM(gross_sale_amount),0) as revenue FROM sales WHERE sale_status = 'Confirmed' $seasonFilter $salePrevFilter")->fetch_assoc();
                $salesEvolution = $salesPrev['revenue'] > 0 ? round(($salesCurr['revenue'] - $salesPrev['revenue']) / $salesPrev['revenue'] * 100, 1) : 0;

                // 3. Net Profit (from salesCurr above)

                // 4. Financing — split by type
                // Bank debt (we owe banks)
                $bankRow = $conn->query("SELECT COALESCE(SUM(balance_due),0) as total, COUNT(*) as cnt FROM financing WHERE status = 'Active' AND direction = 'Incoming' AND counterpart_type = 'Bank'")->fetch_assoc();
                $bankDebt = round(floatval($bankRow['total']), 2);
                $bankDebtCount = intval($bankRow['cnt']);

                // Customer advances = active incoming financing (we owe them goods/refund)
                // mirrors bank debt — pulls from financing table, includes manual + auto-overpayment records
                $custRow = $conn->query("SELECT COALESCE(SUM(balance_due),0) as total, COUNT(*) as cnt FROM financing WHERE status = 'Active' AND direction = 'Incoming' AND counterpart_type = 'Customer'")->fetch_assoc();
                $custAdvances = round(floatval($custRow['total']), 2);
                $custAdvCount = intval($custRow['cnt']);
                $cvRes = $conn->query("SELECT COALESCE(SUM(GREATEST(expected_volume_kg - delivered_volume_kg, 0)),0) as vol FROM financing WHERE status = 'Active' AND direction = 'Incoming' AND counterpart_type = 'Customer'");
                $custAdvVolRemaining = $cvRes ? round(floatval($cvRes->fetch_assoc()['vol']), 2) : 0;

                // Supplier running balance = outgoing balance_due - incoming balance_due
                $sOwedMap = []; $sPayableMap = [];
                $r4 = $conn->query("SELECT counterparty_id as id, SUM(balance_due) as t FROM financing WHERE direction = 'Outgoing' AND counterpart_type = 'Supplier' GROUP BY counterparty_id");
                if ($r4) { while ($x = $r4->fetch_assoc()) $sOwedMap[$x['id']] = floatval($x['t']); }
                $r5 = $conn->query("SELECT counterparty_id as id, SUM(balance_due) as t FROM financing WHERE direction = 'Incoming' AND counterpart_type = 'Supplier' GROUP BY counterparty_id");
                if ($r5) { while ($x = $r5->fetch_assoc()) $sPayableMap[$x['id']] = floatval($x['t']); }

                $allSupIds = array_unique(array_merge(array_keys($sOwedMap), array_keys($sPayableMap)));
                $supplierFinOwed = 0;
                $supplierDebt = 0;
                $supplierFinCount = 0;
                foreach ($allSupIds as $sid) {
                    $sbal = ($sOwedMap[$sid] ?? 0) - ($sPayableMap[$sid] ?? 0);
                    if ($sbal > 0) { $supplierFinOwed += $sbal; $supplierFinCount++; }
                    elseif ($sbal < 0) { $supplierDebt += abs($sbal); }
                }
                $supplierFinOwed = round($supplierFinOwed, 2);
                $supplierDebt = round($supplierDebt, 2);
                $svRes = $conn->query("SELECT COALESCE(SUM(GREATEST(expected_volume_kg - delivered_volume_kg, 0)),0) as vol FROM financing WHERE counterpart_type = 'Supplier' AND direction = 'Outgoing' AND status = 'Active'");
                $supplierFinVolRemaining = $svRes ? round(floatval($svRes->fetch_assoc()['vol']), 2) : 0;

                // 6. Financing Power (cash on hand from financing)
                $incomingFinancing = $conn->query("SELECT COALESCE(SUM(amount),0) as t FROM financing WHERE direction = 'Incoming'")->fetch_assoc()['t'];
                $outgoingFinancing = $conn->query("SELECT COALESCE(SUM(amount),0) as t FROM financing WHERE direction = 'Outgoing'")->fetch_assoc()['t'];
                $financingPower = round($incomingFinancing - $outgoingFinancing, 2);

                // 6b. Customer Advance Coverage — can we sell all stock if market drops?
                // ratio = customer advances / stock value
                //   >= 1.00 → green (advances cover all stock — customers will take it)
                //   0.50–0.99 → orange (partial coverage)
                //   < 0.50 → red (most stock not backed by customer money)
                $usvRes = $conn->query("SELECT COALESCE(SUM(current_weight_kg * avg_cost_per_kg), 0) as t FROM lots WHERE current_weight_kg > 0");
                $unsoldStockValue = $usvRes ? round(floatval($usvRes->fetch_assoc()['t']), 2) : 0;
                $advanceCoverage = $unsoldStockValue > 0 ? round($custAdvances / $unsoldStockValue, 3) : null;

                // 6. Counts
                $countPurchases = $conn->query("SELECT COUNT(*) as c FROM purchases")->fetch_assoc()['c'];
                $countDeliveries = $conn->query("SELECT COUNT(*) as c FROM deliveries")->fetch_assoc()['c'];
                $pendingDeliveries = $conn->query("SELECT COUNT(*) as c FROM deliveries WHERE status IN ('Pending','In Transit')")->fetch_assoc()['c'];
                $countPayments = $conn->query("SELECT COUNT(*) as c FROM payments")->fetch_assoc()['c'];
                $countSuppliers = $conn->query("SELECT COUNT(*) as c FROM suppliers")->fetch_assoc()['c'];
                $countCustomers = $conn->query("SELECT COUNT(*) as c FROM customers")->fetch_assoc()['c'];

                // 7. Recent Activity (last 5)
                $recentLogs = [];
                $result = $conn->query("SELECT action, details, timestamp FROM activity_logs ORDER BY timestamp DESC LIMIT 5");
                if ($result) {
                    while ($row = $result->fetch_assoc()) $recentLogs[] = $row;
                }

                // 8. Purchase price trend — weighted avg (SUM cost / SUM weight) at three granularities
                //    weighted, not simple AVG, so a 10t buy at 400 outweighs a 200kg buy at 500
                $priceTrend = ['daily' => [], 'weekly' => [], 'monthly' => []];

                // daily — last 90 days
                $result = $conn->query("SELECT DATE(date) as period, ROUND(SUM(total_cost)/SUM(weight_kg), 2) as avg_price, ROUND(SUM(weight_kg), 2) as volume_kg, COUNT(*) as cnt FROM purchases WHERE total_cost IS NOT NULL AND weight_kg > 0 AND date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY) GROUP BY period ORDER BY period ASC");
                if ($result) { while ($row = $result->fetch_assoc()) $priceTrend['daily'][] = $row; }

                // weekly — ISO week, last 26 weeks. period = Monday of that week so the chart axis lines up nicely
                $result = $conn->query("SELECT DATE_SUB(DATE(date), INTERVAL WEEKDAY(date) DAY) as period, ROUND(SUM(total_cost)/SUM(weight_kg), 2) as avg_price, ROUND(SUM(weight_kg), 2) as volume_kg, COUNT(*) as cnt FROM purchases WHERE total_cost IS NOT NULL AND weight_kg > 0 AND date >= DATE_SUB(CURDATE(), INTERVAL 26 WEEK) GROUP BY period ORDER BY period ASC");
                if ($result) { while ($row = $result->fetch_assoc()) $priceTrend['weekly'][] = $row; }

                // monthly — last 24 months
                $result = $conn->query("SELECT CONCAT(DATE_FORMAT(date, '%Y-%m'), '-01') as period, ROUND(SUM(total_cost)/SUM(weight_kg), 2) as avg_price, ROUND(SUM(weight_kg), 2) as volume_kg, COUNT(*) as cnt FROM purchases WHERE total_cost IS NOT NULL AND weight_kg > 0 AND date >= DATE_SUB(CURDATE(), INTERVAL 24 MONTH) GROUP BY period ORDER BY period ASC");
                if ($result) { while ($row = $result->fetch_assoc()) $priceTrend['monthly'][] = $row; }

                // 10. Monthly Stock Levels (cumulative)
                $monthlyStock = [];
                $result = $conn->query("
                    SELECT m.month,
                        COALESCE(pin.total_in, 0) as monthly_in,
                        COALESCE(pout.total_out, 0) as monthly_out
                    FROM (
                        SELECT DISTINCT DATE_FORMAT(date, '%Y-%m') as month FROM purchases
                        UNION SELECT DISTINCT DATE_FORMAT(date, '%Y-%m') as month FROM deliveries
                    ) m
                    LEFT JOIN (SELECT DATE_FORMAT(date, '%Y-%m') as month, SUM(weight_kg) as total_in FROM purchases GROUP BY month) pin ON m.month = pin.month
                    LEFT JOIN (SELECT DATE_FORMAT(date, '%Y-%m') as month, SUM(weight_kg) as total_out FROM deliveries WHERE status NOT IN ('Rejected','Reassigned') GROUP BY month) pout ON m.month = pout.month
                    ORDER BY m.month ASC
                ");
                $cumulative = 0;
                if ($result) {
                    while ($row = $result->fetch_assoc()) {
                        $cumulative += ($row['monthly_in'] - $row['monthly_out']);
                        $monthlyStock[] = ['month' => $row['month'], 'stock' => round($cumulative, 2)];
                    }
                }

                // 11. Expenses by Category
                $expByCategory = [];
                $result = $conn->query("SELECT COALESCE(ec.category_name, 'Other') as category, SUM(e.amount) as total FROM expenses e LEFT JOIN settings_expense_categories ec ON e.category_id = ec.category_id GROUP BY ec.category_name ORDER BY total DESC");
                if ($result) { while ($row = $result->fetch_assoc()) $expByCategory[] = $row; }

                // 12. Monthly Revenue (for sparkline)
                $monthlyRevenue = [];
                $result = $conn->query("SELECT DATE_FORMAT(unloading_date, '%Y-%m') as month, SUM(gross_sale_amount) as revenue FROM sales WHERE sale_status = 'Confirmed' GROUP BY month ORDER BY month ASC LIMIT 12");
                if ($result) { while ($row = $result->fetch_assoc()) $monthlyRevenue[] = $row; }

                // Total revenue
                $totalRevenue = $conn->query("SELECT COALESCE(SUM(gross_sale_amount),0) as t FROM sales WHERE sale_status = 'Confirmed'")->fetch_assoc()['t'];

                $conn->close();
                echo json_encode(['success' => true, 'data' => [
                    'stock' => ['total' => $totalStock, 'evolution' => $stockEvolution],
                    'sales' => ['revenue' => $salesCurr['revenue'], 'volume' => $salesCurr['volume'], 'profit' => $salesCurr['profit'], 'evolution' => $salesEvolution],
                    'bankDebt' => ['total' => $bankDebt, 'count' => $bankDebtCount],
                    'custAdvances' => ['total' => $custAdvances, 'volume_remaining' => $custAdvVolRemaining, 'count' => $custAdvCount],
                    'supplierFinancing' => ['owed' => $supplierFinOwed, 'volume_remaining' => $supplierFinVolRemaining, 'count' => $supplierFinCount],
                    'supplierDebt' => $supplierDebt,
                    'financingPower' => $financingPower,
                    'advanceCoverage' => ['ratio' => $advanceCoverage, 'unsold_value' => $unsoldStockValue, 'advances' => $custAdvances],
                    'counts' => [
                        'purchases' => $countPurchases,
                        'deliveries' => $countDeliveries,
                        'pendingDeliveries' => $pendingDeliveries,
                        'payments' => $countPayments,
                        'suppliers' => $countSuppliers,
                        'customers' => $countCustomers
                    ],
                    'recentLogs' => $recentLogs,
                    'priceTrend' => $priceTrend,
                    'monthlyStock' => $monthlyStock,
                    'expenseByCategory' => $expByCategory,
                    'monthlyRevenue' => $monthlyRevenue,
                    'totalRevenue' => $totalRevenue
                ]]);
                exit();

            case 'getAIInsights':
                require_once 'ai-helper.php';
                $conn = getDBConnection();

                // Gather current business snapshot
                $stockIn = $conn->query("SELECT COALESCE(SUM(weight_kg),0) as t FROM purchases")->fetch_assoc()['t'];
                $stockOut = $conn->query("SELECT COALESCE(SUM(weight_kg),0) as t FROM deliveries WHERE status NOT IN ('Rejected')")->fetch_assoc()['t'];
                $stock = round($stockIn - $stockOut, 2);
                $salesRev = $conn->query("SELECT COALESCE(SUM(gross_sale_amount),0) as t FROM sales WHERE sale_status='Confirmed'")->fetch_assoc()['t'];
                $profit = $conn->query("SELECT COALESCE(SUM(net_profit),0) as t FROM sales WHERE sale_status='Confirmed'")->fetch_assoc()['t'];
                $financing = $conn->query("SELECT COALESCE(SUM(balance_due),0) as t FROM financing WHERE status='Active'")->fetch_assoc()['t'];
                $expenses = $conn->query("SELECT COALESCE(SUM(amount),0) as t FROM expenses")->fetch_assoc()['t'];
                $pendingDel = $conn->query("SELECT COUNT(*) as c FROM deliveries WHERE status IN ('Pending','In Transit')")->fetch_assoc()['c'];
                $conn->close();

                $context = "Current stock: {$stock}kg, Total revenue: {$salesRev}F, Net profit: {$profit}F, " .
                           "Outstanding financing: {$financing}F, Total expenses: {$expenses}F, Pending deliveries: {$pendingDel}";

                $prompt = "Based on this business snapshot, give exactly 4 brief actionable insights (1-2 sentences each) as bullet points. " .
                          "Focus on: risks, opportunities, and immediate actions needed. Be specific with numbers. Data: " . $context;

                $result = callGemini($prompt, getBusinessSystemPrompt());
                echo json_encode($result);
                exit();

            case 'getSalesPurchasesChart':
                $season = getActiveSeason();
                $fmt = '%Y-%m';

                // revenue + costs + profit from confirmed sales (same source as KPI)
                $sStmt = $conn->prepare("SELECT DATE_FORMAT(unloading_date, '$fmt') as period, COALESCE(SUM(gross_sale_amount),0) as revenue, COALESCE(SUM(total_costs),0) as costs, COALESCE(SUM(net_profit),0) as profit FROM sales WHERE season = ? AND sale_status = 'Confirmed' GROUP BY DATE_FORMAT(unloading_date, '$fmt') ORDER BY period ASC");
                $sStmt->bind_param("s", $season);
                $sStmt->execute();
                $data = [];
                $res = $sStmt->get_result();
                while ($r = $res->fetch_assoc()) {
                    $data[] = [
                        'period' => $r['period'],
                        'revenue' => floatval($r['revenue']),
                        'costs' => floatval($r['costs']),
                        'profit' => floatval($r['profit'])
                    ];
                }
                $sStmt->close();
                $conn->close();

                echo json_encode(['success' => true, 'data' => $data]);
                exit();

            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
                exit();
        }
    } catch (\Throwable $e) {
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
        exit();
    }
}
?>
<?php
// KPI visibility per role
$showSales = in_array($role, ['Admin','Manager','Finance Officer']);
$showProfit = in_array($role, ['Admin','Manager','Finance Officer']);
$showFinancing = in_array($role, ['Admin','Manager','Finance Officer']);
$showSupplierDebt = in_array($role, ['Admin','Manager','Finance Officer','Procurement Officer']);
$showCashOnHand = in_array($role, ['Admin','Manager','Finance Officer']);
$activeSeason = getActiveSeason();
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Dashboard — Commodity Flow</title>

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
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />

  <!-- jQuery -->
  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <!-- SweetAlert2 -->
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <!-- Chart.js -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns@3.0.0/dist/chartjs-adapter-date-fns.bundle.min.js"></script>

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

    .nav-link.active { background: rgba(45,157,153,0.12); color: #2d9d99; }
    .dark .nav-link.active { background: rgba(45,157,153,0.15); color: #4db8b4; }
    .nav-link.active .nav-icon { color: #2d9d99; }
    .dark .nav-link.active .nav-icon { color: #4db8b4; }
    .nav-link.active::before {
      content: '';
      position: absolute; left: 0; top: 15%; bottom: 15%;
      width: 3px; background: #2d9d99; border-radius: 0 3px 3px 0;
    }

    .kpi-stripe { height: 3px; border-radius: 3px 3px 0 0; }
    tbody tr { transition: background 120ms; }

    #sidebar { transition: width 280ms cubic-bezier(.16,1,.3,1); }
    .sidebar-label { transition: opacity 200ms, width 200ms; }
    .app-collapsed #sidebar { width: 64px; }
    .app-collapsed .sidebar-label { opacity: 0; width: 0; overflow: hidden; }
    .app-collapsed .sidebar-section-label { opacity: 0; }
    .app-collapsed .logo-text { opacity: 0; width: 0; overflow: hidden; }

    .pulse-dot { animation: pulse 2s cubic-bezier(.4,0,.6,1) infinite; }
    @keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.5} }

    .chartjs-tooltip { pointer-events: none; }
    .tabular { font-variant-numeric: tabular-nums lining-nums; }
    .truncate-2 { display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
  </style>
</head>

<body class="h-full bg-slate-50 text-slate-800 font-sans antialiased dark:bg-slate-900 dark:text-slate-200">

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
        <i class="fas fa-chart-line text-brand-500 text-sm"></i>
        <h1 class="text-base font-bold text-slate-800 dark:text-white" data-t="header-title">Dashboard</h1>
        <span class="hidden sm:inline-block text-xs text-slate-400 dark:text-slate-500 font-normal ml-1" id="headerDate"></span>
      </div>

      <div class="ml-auto flex items-center gap-3">
        <!-- Period tabs -->
        <div class="hidden md:flex items-center gap-0.5 bg-slate-100 dark:bg-slate-700 rounded-lg p-1" id="timeFilterTabs">
          <button onclick="switchPeriod('today', this)" class="period-tab px-3 py-1 rounded-md text-xs font-medium text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 transition-colors" data-t="period-today">Today</button>
          <button onclick="switchPeriod('week', this)" class="period-tab px-3 py-1 rounded-md text-xs font-medium text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 transition-colors" data-t="period-week">Week</button>
          <button onclick="switchPeriod('month', this)" class="period-tab px-3 py-1 rounded-md text-xs font-medium text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 transition-colors" data-t="period-month">Month</button>
          <button onclick="switchPeriod('quarter', this)" class="period-tab px-3 py-1 rounded-md text-xs font-medium text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 transition-colors" data-t="period-quarter">Quarter</button>
          <button onclick="switchPeriod('year', this)" class="period-tab active px-3 py-1 rounded-md text-xs font-semibold bg-white dark:bg-slate-600 text-slate-700 dark:text-white shadow-sm transition-colors" data-t="period-year">Year</button>
        </div>

        <!-- ADD NEW button -->
        <div class="relative" id="addNewDropdown">
          <button onclick="toggleAddNew()" class="flex items-center gap-2 bg-brand-500 hover:bg-brand-600 text-white text-xs font-semibold px-3.5 py-2 rounded-lg shadow-sm transition-colors">
            <i class="fas fa-plus text-xs"></i>
            <span data-t="btn-add-new">Add New</span>
            <i class="fas fa-chevron-down text-[10px] opacity-70"></i>
          </button>
          <div id="addNewMenu" class="hidden absolute right-0 top-full mt-1 w-52 bg-white dark:bg-slate-800 rounded-xl shadow-lg border border-slate-200 dark:border-slate-700 py-1.5 z-50">
            <div class="px-3 py-1.5 text-[10px] uppercase font-semibold text-slate-400 tracking-wider" data-t="menu-create-new">Create New</div>
            <a href="purchases.php?action=new" class="flex items-center gap-3 px-3 py-2 text-sm text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors">
              <span class="w-6 h-6 rounded-md bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center"><i class="fas fa-cart-plus text-blue-600 dark:text-blue-400 text-xs"></i></span>
              <span data-t="menu-purchase">Purchase</span>
            </a>
            <a href="sales.php?action=new" class="flex items-center gap-3 px-3 py-2 text-sm text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors">
              <span class="w-6 h-6 rounded-md bg-emerald-100 dark:bg-emerald-900/30 flex items-center justify-center"><i class="fas fa-chart-line text-emerald-600 dark:text-emerald-400 text-xs"></i></span>
              <span data-t="menu-sale">Sale</span>
            </a>
            <a href="deliveries.php?action=new" class="flex items-center gap-3 px-3 py-2 text-sm text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors">
              <span class="w-6 h-6 rounded-md bg-amber-100 dark:bg-amber-900/30 flex items-center justify-center"><i class="fas fa-truck text-amber-600 dark:text-amber-400 text-xs"></i></span>
              <span data-t="menu-delivery">Delivery</span>
            </a>
            <a href="payments.php?action=new" class="flex items-center gap-3 px-3 py-2 text-sm text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors">
              <span class="w-6 h-6 rounded-md bg-violet-100 dark:bg-violet-900/30 flex items-center justify-center"><i class="fas fa-money-check text-violet-600 dark:text-violet-400 text-xs"></i></span>
              <span data-t="menu-payment">Payment</span>
            </a>
            <a href="expenses.php?action=new" class="flex items-center gap-3 px-3 py-2 text-sm text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors">
              <span class="w-6 h-6 rounded-md bg-rose-100 dark:bg-rose-900/30 flex items-center justify-center"><i class="fas fa-receipt text-rose-600 dark:text-rose-400 text-xs"></i></span>
              <span data-t="menu-expense">Expense</span>
            </a>
            <a href="suppliers.php?action=new" class="flex items-center gap-3 px-3 py-2 text-sm text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors">
              <span class="w-6 h-6 rounded-md bg-slate-100 dark:bg-slate-700 flex items-center justify-center"><i class="fas fa-person text-slate-600 dark:text-slate-400 text-xs"></i></span>
              <span data-t="menu-supplier">Supplier</span>
            </a>
          </div>
        </div>
      </div>
    </header>

    <!-- MAIN CONTENT -->
    <main class="flex-1 overflow-y-auto p-5 space-y-5">

      <!-- PRIMARY KPIs -->
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-4" id="primaryKpiRow">

        <!-- Stock -->
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card overflow-hidden">
          <div class="h-0.5 bg-brand-500"></div>
          <div class="p-4">
            <div class="flex items-start justify-between">
              <div>
                <p class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide" data-t="kpi-stock-label">Total Stock</p>
                <p class="mt-2 text-2xl font-bold text-slate-800 dark:text-white tabular" id="kpiStock"><span class="skeleton inline-block h-7 w-28 rounded"></span></p>
              </div>
              <div class="w-10 h-10 rounded-xl bg-brand-50 dark:bg-brand-900/20 flex items-center justify-center flex-shrink-0">
                <i class="fas fa-warehouse text-brand-500 text-sm"></i>
              </div>
            </div>
            <p class="mt-1.5 text-xs text-slate-500" id="kpiStockSub"><span class="skeleton inline-block h-3 w-20 rounded"></span></p>
            <div class="mt-3 h-1 bg-slate-100 dark:bg-slate-700 rounded-full overflow-hidden"><div class="h-full bg-brand-400 rounded-full" id="kpiStockBar" style="width:0%"></div></div>
          </div>
        </div>

        <!-- Revenue -->
        <?php if ($showSales): ?>
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card overflow-hidden" id="kpiSalesCard">
          <div class="h-0.5 bg-blue-500"></div>
          <div class="p-4">
            <div class="flex items-start justify-between">
              <div>
                <p class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide" data-t="kpi-revenue-label">Revenue</p>
                <p class="mt-2 text-2xl font-bold text-slate-800 dark:text-white tabular" id="kpiSales"><span class="skeleton inline-block h-7 w-28 rounded"></span></p>
              </div>
              <div class="w-10 h-10 rounded-xl bg-blue-50 dark:bg-blue-900/20 flex items-center justify-center flex-shrink-0">
                <i class="fas fa-chart-line text-blue-500 text-sm"></i>
              </div>
            </div>
            <p class="mt-1.5 text-xs text-slate-500" id="kpiSalesSub"><span class="skeleton inline-block h-3 w-24 rounded"></span></p>
            <div class="mt-3 h-1 bg-slate-100 dark:bg-slate-700 rounded-full overflow-hidden"><div class="h-full bg-blue-400 rounded-full" id="kpiSalesBar" style="width:0%"></div></div>
          </div>
        </div>
        <?php endif; ?>

        <!-- Profit -->
        <?php if ($showProfit): ?>
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card overflow-hidden" id="kpiProfitCard">
          <div class="h-0.5 bg-emerald-500"></div>
          <div class="p-4">
            <div class="flex items-start justify-between">
              <div>
                <p class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide" data-t="kpi-profit-label">Net Profit</p>
                <p class="mt-2 text-2xl font-bold text-slate-800 dark:text-white tabular" id="kpiProfit"><span class="skeleton inline-block h-7 w-28 rounded"></span></p>
              </div>
              <div class="w-10 h-10 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 flex items-center justify-center flex-shrink-0">
                <i class="fas fa-coins text-emerald-500 text-sm"></i>
              </div>
            </div>
            <p class="mt-1.5 text-xs text-slate-500" data-t="kpi-profit-sub">Confirmed sales</p>
            <div class="mt-3 h-1 bg-slate-100 dark:bg-slate-700 rounded-full overflow-hidden"><div class="h-full bg-emerald-400 rounded-full" id="kpiProfitBar" style="width:0%"></div></div>
          </div>
        </div>
        <?php endif; ?>

      </div>

      <!-- SECONDARY KPIs -->
      <?php if ($showFinancing || $showSupplierDebt || $showCashOnHand): ?>
      <div class="grid grid-cols-2 sm:grid-cols-4 xl:grid-cols-7 gap-3" id="secondaryKpiRow">

        <?php if ($showFinancing): ?>
        <!-- Bank Debt -->
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card p-4 col-span-1" id="kpiBankDebtCard">
          <div class="flex items-center gap-2.5 mb-2">
            <div class="w-7 h-7 rounded-lg bg-amber-100 dark:bg-amber-900/30 flex items-center justify-center flex-shrink-0">
              <i class="fas fa-building-columns text-amber-500 text-xs"></i>
            </div>
            <p class="text-[11px] font-semibold text-slate-500 dark:text-slate-400 leading-tight" data-t="kpi-bank-debt">Bank Debt</p>
          </div>
          <p class="text-sm font-bold tabular text-amber-500" id="kpiBankDebt"><span class="skeleton inline-block h-4 w-20 rounded"></span></p>
          <p class="text-[10px] text-slate-400 mt-0.5 leading-snug" id="kpiBankDebtSub"><span class="skeleton inline-block h-3 w-16 rounded"></span></p>
        </div>

        <!-- Customer Advances -->
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card p-4 col-span-1" id="kpiCustAdvCard">
          <div class="flex items-center gap-2.5 mb-2">
            <div class="w-7 h-7 rounded-lg bg-violet-100 dark:bg-violet-900/30 flex items-center justify-center flex-shrink-0">
              <i class="fas fa-handshake-angle text-violet-500 text-xs"></i>
            </div>
            <p class="text-[11px] font-semibold text-slate-500 dark:text-slate-400 leading-tight" data-t="kpi-cust-adv">Customer Advances</p>
          </div>
          <p class="text-sm font-bold tabular text-violet-500" id="kpiCustAdv"><span class="skeleton inline-block h-4 w-20 rounded"></span></p>
          <p class="text-[10px] text-slate-400 mt-0.5 leading-snug" id="kpiCustAdvSub"><span class="skeleton inline-block h-3 w-16 rounded"></span></p>
        </div>

        <!-- Coverage -->
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card p-4 col-span-1" id="kpiCoverageCard">
          <div class="flex items-center gap-2.5 mb-2">
            <div class="w-7 h-7 rounded-lg bg-slate-100 dark:bg-slate-700 flex items-center justify-center flex-shrink-0" id="kpiCoverageIconWrap">
              <i class="fas fa-shield-halved text-slate-500 text-xs" id="kpiCoverageIconEl"></i>
            </div>
            <p class="text-[11px] font-semibold text-slate-500 dark:text-slate-400 leading-tight" data-t="kpi-coverage">Coverage</p>
          </div>
          <p class="text-sm font-bold tabular" id="kpiCoverage"><span class="skeleton inline-block h-4 w-16 rounded"></span></p>
          <p class="text-[10px] text-slate-400 mt-0.5 leading-snug" id="kpiCoverageSub"><span class="skeleton inline-block h-3 w-20 rounded"></span></p>
        </div>

        <!-- Suppliers Owe Us -->
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card p-4 col-span-1" id="kpiSupFinCard">
          <div class="flex items-center gap-2.5 mb-2">
            <div class="w-7 h-7 rounded-lg bg-orange-100 dark:bg-orange-900/30 flex items-center justify-center flex-shrink-0">
              <i class="fas fa-hand-holding-dollar text-orange-500 text-xs"></i>
            </div>
            <p class="text-[11px] font-semibold text-slate-500 dark:text-slate-400 leading-tight" data-t="kpi-sup-owed">Suppliers Owe Us</p>
          </div>
          <p class="text-sm font-bold tabular text-orange-500" id="kpiSupFinOwed"><span class="skeleton inline-block h-4 w-20 rounded"></span></p>
          <p class="text-[10px] text-slate-400 mt-0.5 leading-snug" id="kpiSupFinOwedSub"><span class="skeleton inline-block h-3 w-14 rounded"></span></p>
        </div>

        <!-- Expected Volume -->
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card p-4 col-span-1" id="kpiExpVolCard">
          <div class="flex items-center gap-2.5 mb-2">
            <div class="w-7 h-7 rounded-lg bg-teal-100 dark:bg-teal-900/30 flex items-center justify-center flex-shrink-0">
              <i class="fas fa-truck-field text-teal-500 text-xs"></i>
            </div>
            <p class="text-[11px] font-semibold text-slate-500 dark:text-slate-400 leading-tight" data-t="kpi-exp-vol">Expected Volume</p>
          </div>
          <p class="text-sm font-bold tabular text-teal-500" id="kpiExpectedVol"><span class="skeleton inline-block h-4 w-14 rounded"></span></p>
          <p class="text-[10px] text-slate-400 mt-0.5" data-t="kpi-exp-vol-sub">Financed suppliers</p>
        </div>
        <?php endif; ?>

        <?php if ($showSupplierDebt): ?>
        <!-- Supplier Debt -->
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card p-4 col-span-1" id="kpiSupDebtCard">
          <div class="flex items-center gap-2.5 mb-2">
            <div class="w-7 h-7 rounded-lg bg-rose-100 dark:bg-rose-900/30 flex items-center justify-center flex-shrink-0">
              <i class="fas fa-circle-exclamation text-rose-500 text-xs"></i>
            </div>
            <p class="text-[11px] font-semibold text-slate-500 dark:text-slate-400 leading-tight" data-t="kpi-sup-debt">Supplier Debt</p>
          </div>
          <p class="text-sm font-bold tabular text-rose-500" id="kpiSupplierDebt"><span class="skeleton inline-block h-4 w-18 rounded"></span></p>
          <p class="text-[10px] text-slate-400 mt-0.5" data-t="kpi-sup-debt-sub">Owed to suppliers</p>
        </div>
        <?php endif; ?>

        <?php if ($showCashOnHand): ?>
        <!-- Cash on Hand -->
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card p-4 col-span-1" id="kpiCashCard">
          <div class="flex items-center gap-2.5 mb-2">
            <div class="w-7 h-7 rounded-lg bg-indigo-100 dark:bg-indigo-900/30 flex items-center justify-center flex-shrink-0">
              <i class="fas fa-vault text-indigo-500 text-xs"></i>
            </div>
            <p class="text-[11px] font-semibold text-slate-500 dark:text-slate-400 leading-tight" data-t="kpi-cash">Cash on Hand</p>
          </div>
          <p class="text-sm font-bold tabular" id="kpiFinancingPower"><span class="skeleton inline-block h-4 w-20 rounded"></span></p>
          <p class="text-[10px] text-slate-400 mt-0.5" data-t="kpi-cash-sub">Financing capacity</p>
        </div>
        <?php endif; ?>

      </div>
      <?php endif; ?>

      <!-- ACTIVITY COUNTERS -->
      <div class="grid grid-cols-5 gap-3">
        <a href="purchases.php" class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card p-4 flex flex-col items-center gap-2 hover:shadow-card-hover hover:-translate-y-0.5 transition-all">
          <div class="relative">
            <div class="w-11 h-11 rounded-xl bg-blue-50 dark:bg-blue-900/20 flex items-center justify-center">
              <i class="fas fa-cart-shopping text-blue-500"></i>
            </div>
            <span class="absolute -top-1 -right-1 min-w-[18px] h-[18px] bg-brand-500 text-white text-[10px] font-bold rounded-full flex items-center justify-center px-1" id="badgePurchases">—</span>
          </div>
          <p class="text-xl font-bold text-slate-800 dark:text-white tabular" id="countPurchases"><span class="skeleton inline-block h-5 w-6 rounded"></span></p>
          <p class="text-[11px] font-medium text-slate-500" data-t="count-purchases">Purchases</p>
        </a>
        <a href="deliveries.php" class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card p-4 flex flex-col items-center gap-2 hover:shadow-card-hover hover:-translate-y-0.5 transition-all">
          <div class="relative">
            <div class="w-11 h-11 rounded-xl bg-teal-50 dark:bg-teal-900/20 flex items-center justify-center">
              <i class="fas fa-truck-fast text-teal-500"></i>
            </div>
            <span class="absolute -top-1 -right-1 min-w-[18px] h-[18px] bg-amber-500 text-white text-[10px] font-bold rounded-full flex items-center justify-center px-1" id="badgeDeliveries">—</span>
          </div>
          <p class="text-xl font-bold text-slate-800 dark:text-white tabular" id="countDeliveries"><span class="skeleton inline-block h-5 w-6 rounded"></span></p>
          <p class="text-[11px] font-medium text-slate-500" data-t="count-deliveries">Deliveries</p>
        </a>
        <a href="payments.php" class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card p-4 flex flex-col items-center gap-2 hover:shadow-card-hover hover:-translate-y-0.5 transition-all">
          <div class="w-11 h-11 rounded-xl bg-violet-50 dark:bg-violet-900/20 flex items-center justify-center">
            <i class="fas fa-credit-card text-violet-500"></i>
          </div>
          <p class="text-xl font-bold text-slate-800 dark:text-white tabular" id="countPayments"><span class="skeleton inline-block h-5 w-6 rounded"></span></p>
          <p class="text-[11px] font-medium text-slate-500" data-t="count-payments">Payments</p>
        </a>
        <a href="suppliers.php" class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card p-4 flex flex-col items-center gap-2 hover:shadow-card-hover hover:-translate-y-0.5 transition-all">
          <div class="w-11 h-11 rounded-xl bg-sky-50 dark:bg-sky-900/20 flex items-center justify-center">
            <i class="fas fa-people-group text-sky-500"></i>
          </div>
          <p class="text-xl font-bold text-slate-800 dark:text-white tabular" id="countSuppliers"><span class="skeleton inline-block h-5 w-6 rounded"></span></p>
          <p class="text-[11px] font-medium text-slate-500" data-t="count-suppliers">Suppliers</p>
        </a>
        <a href="customers.php" class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card p-4 flex flex-col items-center gap-2 hover:shadow-card-hover hover:-translate-y-0.5 transition-all">
          <div class="w-11 h-11 rounded-xl bg-rose-50 dark:bg-rose-900/20 flex items-center justify-center">
            <i class="fas fa-users text-rose-500"></i>
          </div>
          <p class="text-xl font-bold text-slate-800 dark:text-white tabular" id="countCustomers"><span class="skeleton inline-block h-5 w-6 rounded"></span></p>
          <p class="text-[11px] font-medium text-slate-500" data-t="count-customers">Customers</p>
        </a>
      </div>

      <!-- CHARTS ROW -->
      <div class="grid grid-cols-1 lg:grid-cols-5 gap-4">

        <!-- P&L Chart -->
        <?php if ($showSales): ?>
        <div class="lg:col-span-3 bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card p-5">
          <div class="flex items-center justify-between mb-4">
            <div>
              <h3 class="text-sm font-bold text-slate-800 dark:text-white" data-t="chart-pl-title">Monthly P&L</h3>
              <p class="text-xs text-slate-400 mt-0.5" data-t="chart-pl-sub">Revenue · Costs · Profit</p>
            </div>
            <div class="flex items-center gap-3 text-xs text-slate-400">
              <span class="flex items-center gap-1.5"><span class="inline-block w-2.5 h-2.5 rounded-sm bg-brand-400"></span><span data-t="legend-revenue">Revenue</span></span>
              <span class="flex items-center gap-1.5"><span class="inline-block w-2.5 h-2.5 rounded-sm bg-rose-400"></span><span data-t="legend-costs">Costs</span></span>
              <span class="flex items-center gap-1.5"><span class="inline-block w-2.5 h-2.5 rounded-sm bg-emerald-400"></span><span data-t="legend-profit">Profit</span></span>
            </div>
          </div>
          <div class="relative h-56"><canvas id="salesPurchasesChart"></canvas></div>
        </div>
        <?php endif; ?>

        <!-- Price trend -->
        <div class="<?php echo $showSales ? 'lg:col-span-2' : 'lg:col-span-5'; ?> bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card p-5">
          <div class="flex items-center justify-between mb-4">
            <div>
              <h3 class="text-sm font-bold text-slate-800 dark:text-white" data-t="chart-price-title">Avg. Purchase Price</h3>
              <p class="text-xs text-slate-400 mt-0.5" data-t="chart-price-sub">Weighted F/kg</p>
            </div>
            <div class="flex gap-0.5 bg-slate-100 dark:bg-slate-700 rounded-lg p-0.5" id="priceTrendTabs">
              <button class="ptt-btn active px-2.5 py-1 rounded-md text-[11px] font-semibold bg-white dark:bg-slate-600 text-slate-700 dark:text-white shadow-sm" data-grain="daily" data-t="grain-day">Day</button>
              <button class="ptt-btn px-2.5 py-1 rounded-md text-[11px] font-medium text-slate-500 dark:text-slate-400" data-grain="weekly" data-t="grain-week">Wk.</button>
              <button class="ptt-btn px-2.5 py-1 rounded-md text-[11px] font-medium text-slate-500 dark:text-slate-400" data-grain="monthly" data-t="grain-month">Mo.</button>
            </div>
          </div>
          <div class="relative h-56"><canvas id="pricesEvolutionChart"></canvas></div>
        </div>

      </div>

      <!-- BOTTOM ROW -->
      <div class="grid grid-cols-1 lg:grid-cols-5 gap-4">

        <!-- Stock evolution -->
        <div class="lg:col-span-2 bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card p-5">
          <div class="flex items-center justify-between mb-4">
            <div>
              <h3 class="text-sm font-bold text-slate-800 dark:text-white" data-t="chart-stock-title">Stock Evolution</h3>
              <p class="text-xs text-slate-400 mt-0.5" data-t="chart-stock-sub">Monthly cumulative (kg)</p>
            </div>
            <span class="text-xs font-semibold tabular text-brand-500" id="currentStockLabel">—</span>
          </div>
          <div class="relative h-44"><canvas id="stockEvolutionChart"></canvas></div>
        </div>

        <!-- Expenses by category -->
        <?php if ($showFinancing): ?>
        <div class="lg:col-span-2 bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card p-5">
          <div class="flex items-center justify-between mb-4">
            <div>
              <h3 class="text-sm font-bold text-slate-800 dark:text-white" data-t="chart-exp-title">Expenses by Category</h3>
              <p class="text-xs text-slate-400 mt-0.5" data-t="chart-exp-sub">Season breakdown</p>
            </div>
          </div>
          <div class="relative h-44"><canvas id="expCategoryChart"></canvas></div>
        </div>
        <?php endif; ?>

        <!-- Revenue highlight -->
        <?php if ($showSales): ?>
        <div class="lg:col-span-1 bg-gradient-to-br from-slate-800 to-slate-900 rounded-xl shadow-card p-5 flex flex-col justify-between">
          <div>
            <p class="text-[10px] uppercase font-bold tracking-widest text-brand-400 mb-2" data-t="card-total-revenue">Total Revenue</p>
            <p class="text-2xl font-extrabold text-white tabular" id="revenueValue"><span class="inline-block h-7 w-24 rounded bg-white/10"></span></p>
            <p class="text-[11px] text-slate-400 mt-1" data-t="card-this-quarter">This quarter</p>
            <a href="sales.php" class="inline-flex items-center gap-1.5 text-[11px] font-semibold text-brand-400 hover:text-brand-300 mt-3 transition-colors">
              <span data-t="link-view-details">View details</span> <i class="fas fa-arrow-right text-[9px]"></i>
            </a>
          </div>
          <div class="h-14 mt-4"><canvas id="revenueSparkline"></canvas></div>
        </div>
        <?php endif; ?>

      </div>

      <!-- RECENT ACTIVITY -->
      <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card">
        <div class="flex items-center justify-between px-5 py-4 border-b border-slate-100 dark:border-slate-700">
          <div>
            <h3 class="text-sm font-bold text-slate-800 dark:text-white" data-t="section-activity">Recent Activity</h3>
            <p class="text-xs text-slate-400 mt-0.5" data-t="section-activity-sub">Latest recorded actions</p>
          </div>
          <a href="logs.php" class="text-xs font-semibold text-brand-500 hover:text-brand-600 transition-colors" data-t="link-view-all">View all →</a>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="border-b border-slate-100 dark:border-slate-700">
                <th class="text-left text-[11px] font-semibold text-slate-400 uppercase tracking-wide px-5 py-3 w-10" data-t="th-type">Type</th>
                <th class="text-left text-[11px] font-semibold text-slate-400 uppercase tracking-wide px-3 py-3" data-t="th-action">Action</th>
                <th class="text-left text-[11px] font-semibold text-slate-400 uppercase tracking-wide px-3 py-3 hidden sm:table-cell" data-t="th-details">Details</th>
                <th class="text-right text-[11px] font-semibold text-slate-400 uppercase tracking-wide px-5 py-3 whitespace-nowrap" data-t="th-when">When</th>
              </tr>
            </thead>
            <tbody id="recentActivityBody">
              <tr class="border-b border-slate-50 dark:border-slate-700/50">
                <td class="px-5 py-3"><span class="skeleton inline-block w-7 h-7 rounded-lg"></span></td>
                <td class="px-3 py-3"><span class="skeleton inline-block h-3.5 w-32 rounded"></span></td>
                <td class="px-3 py-3 hidden sm:table-cell"><span class="skeleton inline-block h-3 w-48 rounded"></span></td>
                <td class="px-5 py-3 text-right"><span class="skeleton inline-block h-3 w-12 rounded"></span></td>
              </tr>
              <tr class="border-b border-slate-50 dark:border-slate-700/50">
                <td class="px-5 py-3"><span class="skeleton inline-block w-7 h-7 rounded-lg"></span></td>
                <td class="px-3 py-3"><span class="skeleton inline-block h-3.5 w-40 rounded"></span></td>
                <td class="px-3 py-3 hidden sm:table-cell"><span class="skeleton inline-block h-3 w-56 rounded"></span></td>
                <td class="px-5 py-3 text-right"><span class="skeleton inline-block h-3 w-10 rounded"></span></td>
              </tr>
              <tr>
                <td class="px-5 py-3"><span class="skeleton inline-block w-7 h-7 rounded-lg"></span></td>
                <td class="px-3 py-3"><span class="skeleton inline-block h-3.5 w-36 rounded"></span></td>
                <td class="px-3 py-3 hidden sm:table-cell"><span class="skeleton inline-block h-3 w-44 rounded"></span></td>
                <td class="px-5 py-3 text-right"><span class="skeleton inline-block h-3 w-14 rounded"></span></td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- AI INSIGHTS -->
      <?php if ($showProfit): ?>
      <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card p-5" id="aiInsightsSection">
        <div class="flex items-center justify-between mb-4">
          <div class="flex items-center gap-3">
            <div class="w-8 h-8 rounded-lg bg-violet-50 dark:bg-violet-900/30 flex items-center justify-center">
              <i class="fas fa-brain text-violet-500 text-sm"></i>
            </div>
            <div>
              <h3 class="text-sm font-bold text-slate-800 dark:text-white" data-t="ai-title">AI Insights</h3>
              <p class="text-xs text-slate-400" data-t="ai-sub">Automated analysis of your activity</p>
            </div>
          </div>
          <button onclick="generateAIInsights()" id="aiInsightBtn" class="flex items-center gap-2 bg-violet-500 hover:bg-violet-600 text-white text-xs font-semibold px-3.5 py-2 rounded-lg transition-colors">
            <i class="fas fa-wand-magic-sparkles text-xs"></i> <span data-t="ai-btn-generate">Generate</span>
          </button>
        </div>
        <div id="aiInsightsContent" class="text-sm text-slate-500 dark:text-slate-400 text-center py-6">
          <i class="fas fa-brain text-3xl text-slate-200 dark:text-slate-700 block mb-2"></i>
          <span data-t="ai-placeholder">Click "Generate" to get AI insights</span>
        </div>
      </div>
      <?php endif; ?>

    </main>
  </div>
</div>


<script>
/* i18n Translation System */
const TRANSLATIONS = {
  en: {
    'sidebarSeason':       '<?php echo htmlspecialchars($activeSeason ?: "No Season"); ?>',
    'sidebarRole':         '<?php echo htmlspecialchars($_SESSION["role"] ?? ""); ?>',
    'nav-section-ops':     'Operations',
    'nav-dashboard':       'Dashboard',
    'nav-purchases':       'Purchases',
    'nav-sales':           'Sales',
    'nav-deliveries':      'Deliveries',
    'nav-payments':        'Payments',
    'nav-section-master':  'Master Data',
    'nav-suppliers':       'Suppliers',
    'nav-customers':       'Customers',
    'nav-pricing':         'Price Grid',
    'nav-banks':           'Banks',
    'nav-section-finance': 'Finance',
    'nav-financing':       'Financing',
    'nav-expenses':        'Expenses',
    'nav-pl':              'P&L Analysis',
    'nav-section-ai':      'AI & Analytics',
    'nav-ai':              'AI Reports',
    'badge-new':           'New',
    'nav-section-logistics':'Logistics',
    'nav-fleet':           'Fleet & Drivers',
    'nav-bags':            'Bag Journal',
    'nav-inventory':       'Inventory',
    'nav-section-admin':   'Administration',
    'nav-users':           'Users',
    'nav-settings':        'Settings',
    'nav-logout':          'Sign Out',
    'tooltip-collapse':    'Collapse',
    'header-title':        'Dashboard',
    'period-today':        'Today',
    'period-week':         'Week',
    'period-month':        'Month',
    'period-quarter':      'Quarter',
    'period-year':         'Year',
    'btn-add-new':         'Add New',
    'menu-create-new':     'Create New',
    'menu-purchase':       'Purchase',
    'menu-sale':           'Sale',
    'menu-delivery':       'Delivery',
    'menu-payment':        'Payment',
    'menu-expense':        'Expense',
    'menu-supplier':       'Supplier',
    'kpi-stock-label':     'Total Stock',
    'kpi-revenue-label':   'Revenue',
    'kpi-profit-label':    'Net Profit',
    'kpi-profit-sub':      'Confirmed sales',
    'kpi-bank-debt':       'Bank Debt',
    'kpi-cust-adv':        'Customer Advances',
    'kpi-coverage':        'Coverage',
    'kpi-sup-owed':        'Suppliers Owe Us',
    'kpi-exp-vol':         'Expected Volume',
    'kpi-exp-vol-sub':     'Financed suppliers',
    'kpi-sup-debt':        'Supplier Debt',
    'kpi-sup-debt-sub':    'Owed to suppliers',
    'kpi-cash':            'Cash on Hand',
    'kpi-cash-sub':        'Financing capacity',
    'count-purchases':     'Purchases',
    'count-deliveries':    'Deliveries',
    'count-payments':      'Payments',
    'count-suppliers':     'Suppliers',
    'count-customers':     'Customers',
    'chart-pl-title':      'Monthly P&L',
    'chart-pl-sub':        'Revenue \u00b7 Costs \u00b7 Profit',
    'legend-revenue':      'Revenue',
    'legend-costs':        'Costs',
    'legend-profit':       'Profit',
    'chart-price-title':   'Avg. Purchase Price',
    'chart-price-sub':     'Weighted F/kg',
    'grain-day':           'Day',
    'grain-week':          'Wk.',
    'grain-month':         'Mo.',
    'chart-stock-title':   'Stock Evolution',
    'chart-stock-sub':     'Monthly cumulative (kg)',
    'chart-exp-title':     'Expenses by Category',
    'chart-exp-sub':       'Season breakdown',
    'card-total-revenue':  'Total Revenue',
    'card-this-quarter':   'This quarter',
    'link-view-details':   'View details',
    'section-activity':    'Recent Activity',
    'section-activity-sub':'Latest recorded actions',
    'link-view-all':       'View all \u2192',
    'th-type':             'Type',
    'th-action':           'Action',
    'th-details':          'Details',
    'th-when':             'When',
    'no-activity':         'No recent activity',
    'ai-title':            'AI Insights',
    'ai-sub':              'Automated analysis of your activity',
    'ai-btn-generate':     'Generate',
    'ai-placeholder':      'Click "Generate" to get AI insights',
    'theme-dark':          'Dark Mode',
    'theme-light':         'Light Mode',
    'lang-switch':         'Fran\u00e7ais',
    'coverage-full':       'Full coverage',
    'coverage-partial':    'Partial coverage',
    'coverage-low':        'Low coverage',
    'coverage-none':       'No stock',
    'vs-last-month':       'vs last month',
    'tonnes-sold':         'T sold',
    'active-loans':        'active loan(s)',
    'active-to-deliver':   'active \u00b7 {0} to deliver',
    'agreements':          'agreement(s)',
    'time-ago-s':          '{0}s',
    'time-ago-m':          '{0}m',
    'time-ago-h':          '{0}h',
    'time-ago-d':          '{0}d',
    'ai-loading':          'Analyzing...',
    'ai-error-conn':       'Connection error',
    'ai-full-reports':     'Full reports \u2192',
  },
  fr: {
    'sidebarSeason':       '<?php echo htmlspecialchars($activeSeason ? "Saison " . $activeSeason : "Pas de saison"); ?>',
    'sidebarRole':         '<?php echo htmlspecialchars($_SESSION["role"] ?? ""); ?>',
    'nav-section-ops':     'Op\u00e9rations',
    'nav-dashboard':       'Dashboard',
    'nav-purchases':       'Achats',
    'nav-sales':           'Ventes',
    'nav-deliveries':      'Livraisons',
    'nav-payments':        'Paiements',
    'nav-section-master':  'Donn\u00e9es Ma\u00eetres',
    'nav-suppliers':       'Fournisseurs',
    'nav-customers':       'Clients',
    'nav-pricing':         'Grille Prix',
    'nav-banks':           'Banques',
    'nav-section-finance': 'Finance',
    'nav-financing':       'Financement',
    'nav-expenses':        'D\u00e9penses',
    'nav-pl':              'Analyse P&L',
    'nav-section-ai':      'IA & Analytique',
    'nav-ai':              'Rapports IA',
    'badge-new':           'Nouveau',
    'nav-section-logistics':'Logistique',
    'nav-fleet':           'Flotte & Chauffeurs',
    'nav-bags':            'Journal Sacs',
    'nav-inventory':       'Inventaire',
    'nav-section-admin':   'Administration',
    'nav-users':           'Utilisateurs',
    'nav-settings':        'Param\u00e8tres',
    'nav-logout':          'D\u00e9connexion',
    'tooltip-collapse':    'R\u00e9duire',
    'header-title':        'Tableau de bord',
    'period-today':        "Aujourd'hui",
    'period-week':         'Semaine',
    'period-month':        'Mois',
    'period-quarter':      'Trimestre',
    'period-year':         'Ann\u00e9e',
    'btn-add-new':         'Ajouter',
    'menu-create-new':     'Cr\u00e9er nouveau',
    'menu-purchase':       'Achat',
    'menu-sale':           'Vente',
    'menu-delivery':       'Livraison',
    'menu-payment':        'Paiement',
    'menu-expense':        'D\u00e9pense',
    'menu-supplier':       'Fournisseur',
    'kpi-stock-label':     'Stock Total',
    'kpi-revenue-label':   "Chiffre d'Affaires",
    'kpi-profit-label':    'B\u00e9n\u00e9fice Net',
    'kpi-profit-sub':      'Ventes confirm\u00e9es',
    'kpi-bank-debt':       'Dette Bancaire',
    'kpi-cust-adv':        'Avances Clients',
    'kpi-coverage':        'Couverture',
    'kpi-sup-owed':        'Fournisseurs Nous Doivent',
    'kpi-exp-vol':         'Volume Pr\u00e9vu',
    'kpi-exp-vol-sub':     'Fournisseurs financ\u00e9s',
    'kpi-sup-debt':        'Dette Fournisseurs',
    'kpi-sup-debt-sub':    'D\u00fb aux fournisseurs',
    'kpi-cash':            'Tr\u00e9sorerie',
    'kpi-cash-sub':        'Capacit\u00e9 financement',
    'count-purchases':     'Achats',
    'count-deliveries':    'Livraisons',
    'count-payments':      'Paiements',
    'count-suppliers':     'Fournisseurs',
    'count-customers':     'Clients',
    'chart-pl-title':      'P&L Mensuel',
    'chart-pl-sub':        'Revenus \u00b7 Co\u00fbts \u00b7 B\u00e9n\u00e9fice',
    'legend-revenue':      'Revenus',
    'legend-costs':        'Co\u00fbts',
    'legend-profit':       'B\u00e9n\u00e9fice',
    'chart-price-title':   'Prix Achat Moy.',
    'chart-price-sub':     'F/kg pond\u00e9r\u00e9',
    'grain-day':           'Jour',
    'grain-week':          'Sem.',
    'grain-month':         'Mois',
    'chart-stock-title':   '\u00c9volution du Stock',
    'chart-stock-sub':     'Cumul mensuel en kg',
    'chart-exp-title':     'D\u00e9penses par Cat\u00e9gorie',
    'chart-exp-sub':       'R\u00e9partition saisonni\u00e8re',
    'card-total-revenue':  'Revenu Total',
    'card-this-quarter':   'Ce trimestre',
    'link-view-details':   'Voir d\u00e9tails',
    'section-activity':    'Activit\u00e9 R\u00e9cente',
    'section-activity-sub':'Derni\u00e8res actions enregistr\u00e9es',
    'link-view-all':       'Voir tout \u2192',
    'th-type':             'Type',
    'th-action':           'Action',
    'th-details':          'D\u00e9tails',
    'th-when':             'Quand',
    'no-activity':         'Aucune activit\u00e9 r\u00e9cente',
    'ai-title':            'Insights IA',
    'ai-sub':              'Analyse automatique de votre activit\u00e9',
    'ai-btn-generate':     'G\u00e9n\u00e9rer',
    'ai-placeholder':      'Cliquez sur "G\u00e9n\u00e9rer" pour obtenir des insights IA',
    'theme-dark':          'Mode sombre',
    'theme-light':         'Mode clair',
    'lang-switch':         'English',
    'coverage-full':       'Couverture totale',
    'coverage-partial':    'Couverture partielle',
    'coverage-low':        'Faible couverture',
    'coverage-none':       'Pas de stock',
    'vs-last-month':       'vs mois dernier',
    'tonnes-sold':         'T vendues',
    'active-loans':        'pr\u00eat(s) actif(s)',
    'active-to-deliver':   'actif \u00b7 {0} \u00e0 livrer',
    'agreements':          'accord(s)',
    'time-ago-s':          '{0}s',
    'time-ago-m':          '{0}m',
    'time-ago-h':          '{0}h',
    'time-ago-d':          '{0}j',
    'ai-loading':          'Analyse...',
    'ai-error-conn':       'Erreur de connexion',
    'ai-full-reports':     'Rapports complets \u2192',
  }
};

var _store = {}; try { _store = window.localStorage; } catch(e) { _store = { getItem: function(){return null;}, setItem: function(){} }; }
var currentLang = _store.getItem('cp_lang') || 'en';

function t(key) {
  return (TRANSLATIONS[currentLang] && TRANSLATIONS[currentLang][key]) || (TRANSLATIONS['en'][key]) || key;
}

function applyTranslations() {
  document.querySelectorAll('[data-t]').forEach(function(el) {
    var key = el.getAttribute('data-t');
    el.textContent = t(key);
  });
  var seasonEl = document.getElementById('sidebarSeason');
  if (seasonEl) seasonEl.textContent = t('sidebarSeason');
  var roleEl = document.getElementById('sidebarRole');
  if (roleEl) roleEl.textContent = t('sidebarRole');
  var langLabel = document.getElementById('langLabel');
  if (langLabel) langLabel.textContent = t('lang-switch');
  var isDarkNow = document.documentElement.classList.contains('dark');
  var themeLabel = document.getElementById('themeLabel');
  if (themeLabel) themeLabel.textContent = isDarkNow ? t('theme-light') : t('theme-dark');
  var locale = currentLang === 'fr' ? 'fr-FR' : 'en-US';
  var hd = document.getElementById('headerDate');
  if (hd) hd.textContent = new Date().toLocaleDateString(locale, { weekday:'long', day:'numeric', month:'long', year:'numeric' });
}

/* Language toggle */
var langBtn = document.getElementById('langToggleBtn');
if (langBtn) {
  langBtn.addEventListener('click', function() {
    currentLang = (currentLang === 'en') ? 'fr' : 'en';
    _store.setItem('cp_lang', currentLang);
    document.documentElement.lang = currentLang;
    applyTranslations();
  });
}

/* Helpers */
function getLocale() { return currentLang === 'fr' ? 'fr-FR' : 'en-US'; }
var fmt  = function(n) { return Number(n).toLocaleString(getLocale()); };
var fmtK = function(n) { n = Math.abs(n); if (n >= 1e9) return (n/1e9).toFixed(1)+'G'; if (n >= 1e6) return (n/1e6).toFixed(1)+'M'; if (n >= 1e3) return (n/1e3).toFixed(0)+'k'; return n.toString(); };

/* Theme */
(function() {
  var html = document.documentElement;
  var btn  = document.getElementById('themeToggleBtn');
  var icon = document.getElementById('themeIcon');
  var dark = _store.getItem('cp_theme') === 'dark' || (_store.getItem('cp_theme') === null && window.matchMedia('(prefers-color-scheme: dark)').matches);
  var apply = function() {
    html.classList.toggle('dark', dark);
    _store.setItem('cp_theme', dark ? 'dark' : 'light');
    if (icon) icon.className = dark ? 'fas fa-sun w-4 text-sm' : 'fas fa-moon w-4 text-sm';
    var lbl = document.getElementById('themeLabel');
    if (lbl) lbl.textContent = dark ? t('theme-light') : t('theme-dark');
  };
  apply();
  if (btn) btn.addEventListener('click', function() { dark = !dark; apply(); });
})();

/* Sidebar collapse */
var appRoot = document.getElementById('appRoot');
var collapseBtn = document.getElementById('sidebarCollapseBtn');
if (collapseBtn) {
  collapseBtn.addEventListener('click', function() {
    appRoot.classList.toggle('app-collapsed');
    var ic = document.getElementById('collapseIcon');
    if (ic) ic.style.transform = appRoot.classList.contains('app-collapsed') ? 'rotate(180deg)' : '';
  });
}

/* Period tabs */
var currentPeriod = 'year';
function switchPeriod(period, btn) {
  currentPeriod = period;
  document.querySelectorAll('.period-tab').forEach(function(t) {
    t.classList.remove('bg-white','dark:bg-slate-600','text-slate-700','dark:text-white','shadow-sm','font-semibold');
    t.classList.add('text-slate-500','dark:text-slate-400');
  });
  btn.classList.add('bg-white','dark:bg-slate-600','text-slate-700','dark:text-white','shadow-sm','font-semibold');
  loadDashboardOverview(period);
}

/* Add New dropdown */
function toggleAddNew() {
  document.getElementById('addNewMenu').classList.toggle('hidden');
}
document.addEventListener('click', function(e) {
  var dd = document.getElementById('addNewDropdown');
  if (dd && !dd.contains(e.target)) {
    document.getElementById('addNewMenu').classList.add('hidden');
  }
});

/* Price trend tab wiring */
document.addEventListener('click', function(e) {
  var btn = e.target.closest && e.target.closest('#priceTrendTabs .ptt-btn');
  if (!btn) return;
  document.querySelectorAll('#priceTrendTabs .ptt-btn').forEach(function(b) {
    b.classList.remove('bg-white','dark:bg-slate-600','text-slate-700','dark:text-white','shadow-sm','font-semibold');
    b.classList.add('text-slate-500','dark:text-slate-400','font-medium');
  });
  btn.classList.add('bg-white','dark:bg-slate-600','text-slate-700','dark:text-white','shadow-sm','font-semibold');
  btn.classList.remove('text-slate-500','dark:text-slate-400','font-medium');
  renderPriceTrendChart(btn.getAttribute('data-grain'));
});

/* timeAgo */
function timeAgo(d) {
  var s = Math.floor((Date.now() - new Date(d)) / 1000);
  if (s < 60)  return t('time-ago-s').replace('{0}', s);
  if (s < 3600) return t('time-ago-m').replace('{0}', Math.floor(s/60));
  if (s < 86400) return t('time-ago-h').replace('{0}', Math.floor(s/3600));
  return t('time-ago-d').replace('{0}', Math.floor(s/86400));
}

function getActivityStyle(action) {
  if (/creat|insert|add|purchase|achat|vente|sale/i.test(action)) return { bg:'bg-emerald-50 dark:bg-emerald-900/20', color:'text-emerald-500', icon:'fa-plus' };
  if (/updat|edit|modif/i.test(action)) return { bg:'bg-blue-50 dark:bg-blue-900/20', color:'text-blue-500', icon:'fa-pen' };
  if (/delet|remov/i.test(action)) return { bg:'bg-rose-50 dark:bg-rose-900/20', color:'text-rose-500', icon:'fa-trash' };
  if (/login|sign|connect/i.test(action)) return { bg:'bg-amber-50 dark:bg-amber-900/20', color:'text-amber-500', icon:'fa-right-to-bracket' };
  if (/deliver|livr/i.test(action)) return { bg:'bg-teal-50 dark:bg-teal-900/20', color:'text-teal-500', icon:'fa-truck' };
  if (/pay|paiement/i.test(action)) return { bg:'bg-violet-50 dark:bg-violet-900/20', color:'text-violet-500', icon:'fa-credit-card' };
  return { bg:'bg-slate-100 dark:bg-slate-700', color:'text-slate-500', icon:'fa-bell' };
}

/* Chart defaults */
var isDark = function() { return document.documentElement.classList.contains('dark'); };
var gridColor  = function() { return isDark() ? 'rgba(255,255,255,0.05)' : 'rgba(0,0,0,0.04)'; };
var tickColor  = function() { return isDark() ? '#64748b' : '#94a3b8'; };
var tooltipBg  = function() { return isDark() ? '#1e293b' : '#fff'; };
var tooltipTxt = function() { return isDark() ? '#e2e8f0' : '#1e293b'; };

var overviewCharts = {};
function dc(key) { if (overviewCharts[key]) { overviewCharts[key].destroy(); overviewCharts[key] = null; } }

/* Price trend chart */
window._priceTrendData = { daily:[], weekly:[], monthly:[] };
window._currentGrain = 'daily';
function renderPriceTrendChart(grain) {
  grain = grain || 'daily';
  window._currentGrain = grain;
  var rows = window._priceTrendData[grain] || [];
  dc('pricesEvolution');
  var canvas = document.getElementById('pricesEvolutionChart');
  if (!canvas) return;
  var ctx = canvas.getContext('2d');
  if (!rows.length) {
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    ctx.font = '13px sans-serif';
    ctx.fillStyle = '#888';
    ctx.textAlign = 'center';
    ctx.fillText('No purchase data for this period', canvas.width / 2, canvas.height / 2);
    return;
  }
  var labels = rows.map(function(r) { return r.period; });
  var prices = rows.map(function(r) { return parseFloat(r.avg_price); });
  var volumes = rows.map(function(r) { return parseFloat(r.volume_kg); });
  var counts  = rows.map(function(r) { return parseInt(r.cnt, 10); });
  var grad = ctx.createLinearGradient(0, 0, 0, 240);
  grad.addColorStop(0, 'rgba(45,157,153,0.25)');
  grad.addColorStop(1, 'rgba(45,157,153,0)');
  var unit = grain === 'daily' ? 'day' : (grain === 'weekly' ? 'week' : 'month');
  var priceLbl = currentLang === 'fr' ? 'Prix F/kg' : 'Price F/kg';
  overviewCharts['pricesEvolution'] = new Chart(ctx, {
    type: 'line',
    data: {
      labels: labels,
      datasets: [{
        label: priceLbl,
        data: prices,
        borderColor: '#2d9d99',
        backgroundColor: grad,
        borderWidth: 2,
        fill: true,
        tension: 0.4,
        pointRadius: 2.5,
        pointBackgroundColor: '#2d9d99',
        pointBorderColor: '#fff',
        pointBorderWidth: 1.5
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      scales: {
        x: {
          type: 'time',
          time: { unit: unit, tooltipFormat: grain === 'monthly' ? 'MMM yyyy' : 'MMM d, yyyy', displayFormats: { day: 'd MMM', week: 'd MMM', month: 'MMM yy' } },
          grid: { display: false },
          ticks: { color: tickColor(), font: { size: 11 }, maxTicksLimit: 7 }
        },
        y: { beginAtZero: false, grid: { color: gridColor() }, ticks: { color: tickColor(), font: { size: 11 }, callback: function(v) { return v + ' F'; } } }
      },
      plugins: {
        legend: { display: false },
        tooltip: {
          backgroundColor: tooltipBg(), titleColor: tooltipTxt(), bodyColor: tickColor(), borderColor: 'rgba(0,0,0,0.06)', borderWidth: 1, padding: 10, cornerRadius: 8,
          callbacks: {
            label: function(c) {
              var i = c.dataIndex;
              var l = getLocale();
              return [
                (currentLang === 'fr' ? 'Prix' : 'Price') + ': ' + Number(prices[i]).toLocaleString(l) + ' F/kg',
                'Volume: ' + Number(volumes[i]).toLocaleString(l) + ' kg',
                (currentLang === 'fr' ? 'Achats' : 'Purchases') + ': ' + counts[i]
              ];
            }
          }
        }
      }
    }
  });
}

/* AI Insights */
function generateAIInsights() {
  var btn = document.getElementById('aiInsightBtn');
  var content = document.getElementById('aiInsightsContent');
  if (!btn || !content) return;
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin text-xs"></i> ' + t('ai-loading');
  content.innerHTML = '<div class="space-y-2"><div class="skeleton h-4 w-3/4 rounded"></div><div class="skeleton h-4 w-4/5 rounded"></div><div class="skeleton h-4 w-2/3 rounded"></div></div>';

  $.getJSON('?action=getAIInsights').done(function(r) {
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-wand-magic-sparkles text-xs"></i> ' + t('ai-btn-generate');
    if (r.success) {
      var text = r.text.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>').replace(/^\- /gm, '&bull; ').replace(/^\* /gm, '&bull; ').replace(/\n/g, '<br>');
      var now = new Date().toLocaleTimeString(getLocale());
      content.innerHTML = '<div class="text-sm leading-relaxed text-slate-600 dark:text-slate-300">' + text + '</div><p class="text-[11px] text-slate-400 mt-3"><i class="fas fa-clock mr-1"></i>' + now + ' &middot; <a href="ai-reports.php" class="text-brand-500 hover:underline">' + t('ai-full-reports') + '</a></p>';
    } else {
      content.innerHTML = '<p class="text-rose-500 text-sm"><i class="fas fa-exclamation-triangle mr-1"></i>' + r.message + '</p>';
    }
  }).fail(function() {
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-wand-magic-sparkles text-xs"></i> ' + t('ai-btn-generate');
    content.innerHTML = '<p class="text-rose-500 text-sm">' + t('ai-error-conn') + '</p>';
  });
}

/* loadDashboardOverview */
function loadDashboardOverview(period) {
  $.getJSON('?action=getDashboardOverview&period=' + (period || 'year') + '&_=' + Date.now()).done(function(r) {
    if (!r.success) { console.error('Dashboard API error:', r.message); return; }
    var d = r.data;
    var el;

    /* Stock */
    document.getElementById('kpiStock').textContent = fmt(d.stock.total) + ' kg';
    var evoUp = d.stock.evolution >= 0;
    document.getElementById('kpiStockSub').innerHTML =
      '<span class="' + (evoUp ? 'text-emerald-500' : 'text-rose-500') + '"><i class="fas fa-arrow-' + (evoUp ? 'up' : 'down') + ' text-[9px]"></i> ' + Math.abs(d.stock.evolution) + '% ' + t('vs-last-month') + '</span>';
    document.getElementById('kpiStockBar').style.width = Math.min(100, Math.abs(d.stock.evolution) * 2) + '%';
    el = document.getElementById('currentStockLabel');
    if (el) el.textContent = fmt(d.stock.total) + ' kg';

    /* Revenue */
    if ((el = document.getElementById('kpiSales'))) {
      el.textContent = fmt(Math.round(d.sales.revenue)) + ' F';
      var se = d.sales.evolution >= 0;
      document.getElementById('kpiSalesSub').innerHTML =
        (d.sales.volume / 1000).toFixed(1) + ' ' + t('tonnes-sold') + ' <span class="' + (se ? 'text-emerald-500' : 'text-rose-500') + ' ml-1"><i class="fas fa-arrow-' + (se ? 'up' : 'down') + ' text-[9px]"></i> ' + Math.abs(d.sales.evolution) + '%</span>';
    }

    /* Profit */
    if ((el = document.getElementById('kpiProfit'))) {
      var pv = Math.round(d.sales.profit);
      el.textContent = fmt(pv) + ' F';
      el.classList.toggle('text-emerald-500', pv >= 0);
      el.classList.toggle('text-rose-500', pv < 0);
    }

    /* Bank Debt */
    if ((el = document.getElementById('kpiBankDebt'))) {
      el.textContent = fmt(Math.round(d.bankDebt.total)) + ' F';
      document.getElementById('kpiBankDebtSub').textContent = d.bankDebt.count + ' ' + t('active-loans');
    }

    /* Customer Advances */
    if ((el = document.getElementById('kpiCustAdv'))) {
      el.textContent = fmt(Math.round(d.custAdvances.total)) + ' F';
      var vol = d.custAdvances.volume_remaining;
      var volStr = vol >= 1000 ? (vol / 1000).toFixed(1) + 'T' : fmt(Math.round(vol)) + 'kg';
      document.getElementById('kpiCustAdvSub').innerHTML = t('active-to-deliver').replace('{0}', '<strong>' + volStr + '</strong>');
    }

    /* Coverage */
    if ((el = document.getElementById('kpiCoverage'))) {
      var cov = d.advanceCoverage || {};
      var ratio = cov.ratio;
      var color, label, iconWrapBg;
      if (ratio === null || ratio === undefined) { color = 'text-slate-500'; label = t('coverage-none'); el.textContent = '\u2014'; iconWrapBg = 'bg-slate-100 dark:bg-slate-700'; }
      else if (ratio >= 1.00) { color = 'text-emerald-500'; label = t('coverage-full'); el.textContent = ratio.toFixed(3); iconWrapBg = 'bg-emerald-100 dark:bg-emerald-900/30'; }
      else if (ratio >= 0.80) { color = 'text-amber-500'; label = t('coverage-partial'); el.textContent = ratio.toFixed(3); iconWrapBg = 'bg-amber-100 dark:bg-amber-900/30'; }
      else { color = 'text-rose-500'; label = t('coverage-low'); el.textContent = ratio.toFixed(3); iconWrapBg = 'bg-rose-100 dark:bg-rose-900/30'; }
      el.className = 'text-sm font-bold tabular ' + color;
      document.getElementById('kpiCoverageIconWrap').className = 'w-7 h-7 rounded-lg flex items-center justify-center flex-shrink-0 ' + iconWrapBg;
      document.getElementById('kpiCoverageSub').innerHTML = '<strong>' + label + '</strong>';
    }

    /* Supplier Financing */
    if ((el = document.getElementById('kpiSupFinOwed'))) {
      el.textContent = fmt(Math.round(d.supplierFinancing.owed)) + ' F';
      document.getElementById('kpiSupFinOwedSub').textContent = d.supplierFinancing.count + ' ' + t('agreements');
    }

    /* Expected Volume */
    if ((el = document.getElementById('kpiExpectedVol'))) {
      var vk = d.supplierFinancing.volume_remaining;
      el.textContent = vk >= 1000 ? (vk / 1000).toFixed(1) + ' T' : fmt(Math.round(vk)) + ' kg';
    }

    /* Supplier Debt */
    if ((el = document.getElementById('kpiSupplierDebt'))) {
      el.textContent = fmt(Math.round(d.supplierDebt)) + ' F';
      el.classList.toggle('text-rose-500', d.supplierDebt > 0);
    }

    /* Cash on Hand */
    if ((el = document.getElementById('kpiFinancingPower'))) {
      var fp = Math.round(d.financingPower);
      el.textContent = fmt(fp) + ' F';
      el.className = 'text-sm font-bold tabular ' + (fp >= 0 ? 'text-emerald-500' : 'text-rose-500');
    }

    /* Nav badges */
    if ((el = document.getElementById('navBadgePurchases'))) el.textContent = d.counts.purchases;
    if ((el = document.getElementById('navBadgeDeliveries'))) el.textContent = d.counts.pendingDeliveries;

    /* Counts */
    document.getElementById('countPurchases').textContent = d.counts.purchases;
    document.getElementById('badgePurchases').textContent = d.counts.purchases;
    document.getElementById('countDeliveries').textContent = d.counts.deliveries;
    document.getElementById('badgeDeliveries').textContent = d.counts.pendingDeliveries;
    document.getElementById('countPayments').textContent = d.counts.payments;
    document.getElementById('countSuppliers').textContent = d.counts.suppliers;
    document.getElementById('countCustomers').textContent = d.counts.customers;

    /* Recent Activity */
    var tbody = document.getElementById('recentActivityBody');
    if (d.recentLogs && d.recentLogs.length) {
      tbody.innerHTML = d.recentLogs.map(function(log) {
        var s = getActivityStyle(log.action);
        return '<tr class="border-b border-slate-50 dark:border-slate-700/50 hover:bg-slate-50 dark:hover:bg-slate-750 transition-colors">' +
          '<td class="px-5 py-3"><div class="w-7 h-7 rounded-lg ' + s.bg + ' flex items-center justify-center flex-shrink-0"><i class="fas ' + s.icon + ' ' + s.color + ' text-xs"></i></div></td>' +
          '<td class="px-3 py-3 text-sm font-medium text-slate-700 dark:text-slate-300 whitespace-nowrap">' + log.action + '</td>' +
          '<td class="px-3 py-3 text-xs text-slate-400 hidden sm:table-cell max-w-xs truncate">' + (log.details || '\u2014') + '</td>' +
          '<td class="px-5 py-3 text-right text-[11px] text-slate-400 whitespace-nowrap">' + timeAgo(log.timestamp) + '</td></tr>';
      }).join('');
    } else {
      tbody.innerHTML = '<tr><td colspan="4" class="px-5 py-8 text-center text-sm text-slate-400"><i class="fas fa-inbox text-2xl block mb-2 text-slate-300"></i>' + t('no-activity') + '</td></tr>';
    }

    /* Price trend */
    window._priceTrendData = d.priceTrend || { daily: [], weekly: [], monthly: [] };
    renderPriceTrendChart(window._currentGrain);

    /* Stock Evolution Chart */
    try {
      dc('stockEvolution');
      var seCanvas = document.getElementById('stockEvolutionChart');
      if (seCanvas && d.monthlyStock.length > 0) {
        var seCtx = seCanvas.getContext('2d');
        var seGrad = seCtx.createLinearGradient(0, 0, 0, 200);
        seGrad.addColorStop(0, 'rgba(45,157,153,0.2)');
        seGrad.addColorStop(1, 'rgba(45,157,153,0)');
        overviewCharts['stockEvolution'] = new Chart(seCtx, {
          type: 'line',
          data: {
            labels: d.monthlyStock.map(function(m) { return m.month; }),
            datasets: [{
              label: 'Stock kg',
              data: d.monthlyStock.map(function(m) { return m.stock; }),
              borderColor: '#2d9d99',
              backgroundColor: seGrad,
              fill: true,
              tension: 0.4,
              pointRadius: 2,
              pointBackgroundColor: '#2d9d99',
              borderWidth: 2
            }]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false }, tooltip: { backgroundColor: tooltipBg(), titleColor: tooltipTxt(), bodyColor: tickColor(), borderColor: 'rgba(0,0,0,0.06)', borderWidth: 1, padding: 10, cornerRadius: 8 } },
            scales: { y: { beginAtZero: true, grid: { color: gridColor() }, ticks: { color: tickColor(), font: { size: 11 }, callback: function(v) { return fmtK(v); } } }, x: { grid: { display: false }, ticks: { color: tickColor(), font: { size: 11 } } } }
          }
        });
      }
    } catch(e) { console.error('Stock chart error:', e); }

    /* Expenses by Category Chart */
    try {
      var ecCanvas = document.getElementById('expCategoryChart');
      if (ecCanvas) {
        dc('expCategory');
        if (d.expenseByCategory.length > 0) {
          var ecColors = ['#2d9d99', '#60a5fa', '#f59e0b', '#10b981', '#8b5cf6', '#f97316'];
          overviewCharts['expCategory'] = new Chart(ecCanvas, {
            type: 'bar',
            data: {
              labels: d.expenseByCategory.map(function(c) { return c.category; }),
              datasets: [{
                label: currentLang === 'fr' ? 'Montant (F)' : 'Amount (F)',
                data: d.expenseByCategory.map(function(c) { return parseFloat(c.total); }),
                backgroundColor: d.expenseByCategory.map(function(c, i) { return ecColors[i % ecColors.length] + 'cc'; }),
                borderRadius: 5,
                borderSkipped: false
              }]
            },
            options: {
              responsive: true,
              maintainAspectRatio: false,
              plugins: { legend: { display: false }, tooltip: { backgroundColor: tooltipBg(), titleColor: tooltipTxt(), bodyColor: tickColor(), borderColor: 'rgba(0,0,0,0.06)', borderWidth: 1, padding: 10, cornerRadius: 8 } },
              scales: { y: { beginAtZero: true, grid: { color: gridColor() }, ticks: { color: tickColor(), font: { size: 11 }, callback: function(v) { return fmtK(v); } } }, x: { grid: { display: false }, ticks: { color: tickColor(), font: { size: 11 } } } }
            }
          });
        }
      }
    } catch(e) { console.error('Expenses chart error:', e); }

    /* Revenue Highlight + Sparkline */
    try {
      if (document.getElementById('revenueValue'))
        document.getElementById('revenueValue').textContent = fmt(Math.round(d.totalRevenue)) + ' F';

      var rsCanvas = document.getElementById('revenueSparkline');
      dc('revenueSparkline');
      if (d.monthlyRevenue.length > 0 && rsCanvas) {
        var rsCtx = rsCanvas.getContext('2d');
        var rsGrad = rsCtx.createLinearGradient(0, 0, 0, 60);
        rsGrad.addColorStop(0, 'rgba(255,255,255,0.3)');
        rsGrad.addColorStop(1, 'rgba(255,255,255,0.02)');
        overviewCharts['revenueSparkline'] = new Chart(rsCtx, {
          type: 'line',
          data: {
            labels: d.monthlyRevenue.map(function(m) { return m.month; }),
            datasets: [{
              data: d.monthlyRevenue.map(function(m) { return parseFloat(m.revenue); }),
              borderColor: 'rgba(255,255,255,0.8)',
              backgroundColor: rsGrad,
              fill: true,
              tension: 0.4,
              pointRadius: 0,
              borderWidth: 2
            }]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: { duration: 800 },
            scales: { x: { display: false }, y: { display: false } },
            plugins: { legend: { display: false }, tooltip: { enabled: false } }
          }
        });
      }
    } catch(e) { console.error('Revenue chart error:', e); }

  }).fail(function(jqXHR, textStatus, errorThrown) {
    console.error('Dashboard AJAX failed:', textStatus, errorThrown);
  });
}

/* Sales/P&L chart */
var spChart = null;

function loadSalesPurchasesChart() {
  $.getJSON('?action=getSalesPurchasesChart&period=monthly', function(res) {
    if (!res.success) return;
    renderPlChart(res.data);
  });
}

function renderPlChart(data) {
  var canvas = document.getElementById('salesPurchasesChart');
  if (!canvas) return;
  var ctx = canvas.getContext('2d');
  if (spChart) spChart.destroy();
  var revLbl  = t('legend-revenue');
  var costLbl = t('legend-costs');
  var profLbl = t('legend-profit');
  spChart = new Chart(ctx, {
    type: 'bar',
    data: {
      labels: data.map(function(r) { return r.period; }),
      datasets: [
        { label: revLbl,  data: data.map(function(r) { return r.revenue; }), backgroundColor: 'rgba(45,157,153,0.7)',  borderRadius: 4, borderSkipped: false },
        { label: costLbl, data: data.map(function(r) { return r.costs; }),   backgroundColor: 'rgba(244,63,94,0.65)', borderRadius: 4, borderSkipped: false },
        { label: profLbl, data: data.map(function(r) { return r.profit; }),  backgroundColor: data.map(function(r) { return r.profit >= 0 ? 'rgba(16,185,129,0.7)' : 'rgba(244,63,94,0.5)'; }), borderRadius: 4, borderSkipped: false }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { intersect: false, mode: 'index' },
      plugins: {
        legend: { display: false },
        tooltip: {
          backgroundColor: tooltipBg(), titleColor: tooltipTxt(), bodyColor: tickColor(), borderColor: 'rgba(0,0,0,0.06)', borderWidth: 1, padding: 10, cornerRadius: 8,
          callbacks: { label: function(c) { return c.dataset.label + ': ' + Number(c.parsed.y).toLocaleString(getLocale()) + ' F'; } }
        }
      },
      scales: {
        y: { beginAtZero: true, grid: { color: gridColor() }, ticks: { color: tickColor(), font: { size: 11 }, callback: function(v) { return fmtK(v) + ' F'; } } },
        x: { grid: { display: false }, ticks: { color: tickColor(), font: { size: 11 } } }
      }
    }
  });
}

/* Bootstrap */
applyTranslations();
loadDashboardOverview('year');
setTimeout(loadSalesPurchasesChart, 400);
</script>

</body>
</html>
