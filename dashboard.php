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
    <title>Dashboard - Cashew Business Management</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="styles.css?v=4.0">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns@3.0.0/dist/chartjs-adapter-date-fns.bundle.min.js"></script>
</head>
<body>
    <?php include 'mobile-menu.php'; ?>

    <div class="app-container">
        <?php include 'sidebar.php'; ?>

        <div class="main-content">
            <div class="header">
                <h1><i class="fas fa-chart-line"></i> Dashboard</h1>
                <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                    <span style="font-size:13px;color:var(--text-muted);"><?php echo date('l, d M Y'); ?></span>
                    <span style="background:var(--navy-accent);color:#fff;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:600;"><?php echo htmlspecialchars(getActiveSeason()); ?></span>
                </div>
            </div>

            <!-- ===================== TIME FILTERS ===================== -->
            <div style="display:flex;justify-content:flex-end;margin-bottom:16px;">
                <div class="time-filter-tabs" id="timeFilterTabs">
                    <button onclick="switchPeriod('today', this)">Today</button>
                    <button onclick="switchPeriod('week', this)">Week</button>
                    <button onclick="switchPeriod('month', this)">Month</button>
                    <button onclick="switchPeriod('quarter', this)">Quarter</button>
                    <button class="active" onclick="switchPeriod('year', this)">Year</button>
                </div>
            </div>

            <!-- ===================== ROW 1: KPI STAT CARDS ===================== -->
            <?php
            // KPI visibility per role — bank debts / sales revenue / profit are finance-only
            $showSales = in_array($role, ['Admin','Manager','Finance Officer']);
            $showProfit = in_array($role, ['Admin','Manager','Finance Officer']);
            $showFinancing = in_array($role, ['Admin','Manager','Finance Officer']);
            $showSupplierDebt = in_array($role, ['Admin','Manager','Finance Officer','Procurement Officer']);
            $showCashOnHand = in_array($role, ['Admin','Manager','Finance Officer']);
            ?>
            <!-- PRIMARY KPIs -->
            <div class="dashboard-primary-kpi" id="kpiCardsRow">
                <div class="kpi-card">
                    <div class="kpi-card-top">
                        <div class="kpi-card-icon" style="background:linear-gradient(135deg,#001f3f,#003366);"><i class="fas fa-warehouse"></i></div>
                    </div>
                    <div class="kpi-card-value" id="kpiStock"><div class="skeleton" style="height:32px;width:120px;"></div></div>
                    <div class="kpi-card-label">Total Stock on Hand</div>
                    <div class="kpi-card-sub" id="kpiStockSub"><div class="skeleton" style="height:14px;width:70px;"></div></div>
                    <div class="kpi-card-accent kpi-accent-navy"></div>
                </div>

                <?php if ($showSales): ?>
                <div class="kpi-card">
                    <div class="kpi-card-top">
                        <div class="kpi-card-icon" style="background:linear-gradient(135deg,#0074D9,#005bb5);"><i class="fas fa-chart-line"></i></div>
                    </div>
                    <div class="kpi-card-value" id="kpiSales"><div class="skeleton" style="height:32px;width:140px;"></div></div>
                    <div class="kpi-card-label">Revenue</div>
                    <div class="kpi-card-sub" id="kpiSalesSub"><div class="skeleton" style="height:14px;width:70px;"></div></div>
                    <div class="kpi-card-accent kpi-accent-blue"></div>
                </div>
                <?php endif; ?>

                <?php if ($showProfit): ?>
                <div class="kpi-card">
                    <div class="kpi-card-top">
                        <div class="kpi-card-icon" style="background:linear-gradient(135deg,#34a853,#2d8f47);"><i class="fas fa-coins"></i></div>
                    </div>
                    <div class="kpi-card-value" id="kpiProfit"><div class="skeleton" style="height:32px;width:140px;"></div></div>
                    <div class="kpi-card-label">Season Net Profit</div>
                    <div class="kpi-card-sub" id="kpiProfitSub">Confirmed sales</div>
                    <div class="kpi-card-accent kpi-accent-green"></div>
                </div>
                <?php endif; ?>
            </div>

            <!-- SECONDARY KPIs: Financing -->
            <?php if ($showFinancing || $showSupplierDebt || $showCashOnHand): ?>
            <div class="dashboard-kpi-grid" style="margin-bottom:20px;">
                <?php if ($showFinancing): ?>
                <div class="kpi-card">
                    <div class="kpi-card-top">
                        <div class="kpi-card-icon" style="background:linear-gradient(135deg,#fbbc04,#e0a800);"><i class="fas fa-building-columns"></i></div>
                    </div>
                    <div class="kpi-card-value" id="kpiBankDebt"><div class="skeleton" style="height:30px;width:120px;"></div></div>
                    <div class="kpi-card-label">Bank Debt</div>
                    <div class="kpi-card-sub" id="kpiBankDebtSub"><div class="skeleton" style="height:14px;width:70px;"></div></div>
                    <div class="kpi-card-accent kpi-accent-orange"></div>
                </div>

                <div class="kpi-card">
                    <div class="kpi-card-top">
                        <div class="kpi-card-icon" style="background:linear-gradient(135deg,#9b59b6,#8e44ad);"><i class="fas fa-handshake-angle"></i></div>
                    </div>
                    <div class="kpi-card-value" id="kpiCustAdv"><div class="skeleton" style="height:30px;width:120px;"></div></div>
                    <div class="kpi-card-label">Customer Advances</div>
                    <div class="kpi-card-sub" id="kpiCustAdvSub"><div class="skeleton" style="height:14px;width:70px;"></div></div>
                    <div class="kpi-card-accent" style="background:linear-gradient(90deg,#9b59b6,#8e44ad);"></div>
                </div>

                <div class="kpi-card" id="kpiCoverageCard">
                    <div class="kpi-card-top">
                        <div class="kpi-card-icon" id="kpiCoverageIcon" style="background:linear-gradient(135deg,#7f8c8d,#636e72);"><i class="fas fa-shield-halved"></i></div>
                    </div>
                    <div class="kpi-card-value" id="kpiCoverage"><div class="skeleton" style="height:30px;width:120px;"></div></div>
                    <div class="kpi-card-label">Customer Advance Coverage</div>
                    <div class="kpi-card-sub" id="kpiCoverageSub"><div class="skeleton" style="height:14px;width:100px;"></div></div>
                    <div class="kpi-card-accent" id="kpiCoverageAccent" style="background:#7f8c8d;"></div>
                </div>

                <div class="kpi-card">
                    <div class="kpi-card-top">
                        <div class="kpi-card-icon" style="background:linear-gradient(135deg,#e67e22,#d35400);"><i class="fas fa-hand-holding-dollar"></i></div>
                    </div>
                    <div class="kpi-card-value" id="kpiSupFinOwed"><div class="skeleton" style="height:30px;width:120px;"></div></div>
                    <div class="kpi-card-label">Suppliers Owe Us</div>
                    <div class="kpi-card-sub" id="kpiSupFinOwedSub"><div class="skeleton" style="height:14px;width:70px;"></div></div>
                    <div class="kpi-card-accent" style="background:linear-gradient(90deg,#e67e22,#d35400);"></div>
                </div>

                <div class="kpi-card">
                    <div class="kpi-card-top">
                        <div class="kpi-card-icon" style="background:linear-gradient(135deg,#00b894,#00a381);"><i class="fas fa-truck-field"></i></div>
                    </div>
                    <div class="kpi-card-value" id="kpiExpectedVol"><div class="skeleton" style="height:30px;width:120px;"></div></div>
                    <div class="kpi-card-label">Expected Volume</div>
                    <div class="kpi-card-sub" id="kpiExpectedVolSub">From financed suppliers</div>
                    <div class="kpi-card-accent" style="background:linear-gradient(90deg,#00b894,#00a381);"></div>
                </div>
                <?php endif; ?>

                <?php if ($showSupplierDebt): ?>
                <div class="kpi-card">
                    <div class="kpi-card-top">
                        <div class="kpi-card-icon" style="background:linear-gradient(135deg,#ea4335,#c5362b);"><i class="fas fa-hand-holding-dollar"></i></div>
                    </div>
                    <div class="kpi-card-value" id="kpiSupplierDebt"><div class="skeleton" style="height:30px;width:120px;"></div></div>
                    <div class="kpi-card-label">Supplier Debt</div>
                    <div class="kpi-card-sub">Owed to suppliers</div>
                    <div class="kpi-card-accent kpi-accent-red"></div>
                </div>
                <?php endif; ?>

                <?php if ($showCashOnHand): ?>
                <div class="kpi-card">
                    <div class="kpi-card-top">
                        <div class="kpi-card-icon" style="background:linear-gradient(135deg,#6f42c1,#5a32a3);"><i class="fas fa-vault"></i></div>
                    </div>
                    <div class="kpi-card-value" id="kpiFinancingPower"><div class="skeleton" style="height:30px;width:120px;"></div></div>
                    <div class="kpi-card-label">Cash on Hand</div>
                    <div class="kpi-card-sub">Financing power</div>
                    <div class="kpi-card-accent" style="background:linear-gradient(90deg,#6f42c1,#5a32a3);"></div>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- ===================== ROW 2: CATEGORY CARDS + NOTIFICATIONS ===================== -->
            <div class="overview-row-2">
                <div class="category-cards-wrapper" id="categoryCardsRow">
                    <div class="category-card" onclick="window.location='purchases.php'">
                        <div class="category-card-icon icon-blue">
                            <i class="fas fa-cart-shopping"></i>
                            <span class="badge-count badge-count-blue" id="badgePurchases">-</span>
                        </div>
                        <div class="category-card-count" id="countPurchases"><div class="skeleton" style="height:20px;width:30px;margin:0 auto;"></div></div>
                        <div class="category-card-label">Purchases</div>
                    </div>
                    <div class="category-card" onclick="window.location='deliveries.php'">
                        <div class="category-card-icon icon-green">
                            <i class="fas fa-truck-fast"></i>
                            <span class="badge-count badge-count-orange" id="badgeDeliveries">-</span>
                        </div>
                        <div class="category-card-count" id="countDeliveries"><div class="skeleton" style="height:20px;width:30px;margin:0 auto;"></div></div>
                        <div class="category-card-label">Deliveries</div>
                    </div>
                    <div class="category-card" onclick="window.location='payments.php'">
                        <div class="category-card-icon icon-purple">
                            <i class="fas fa-credit-card"></i>
                        </div>
                        <div class="category-card-count" id="countPayments"><div class="skeleton" style="height:20px;width:30px;margin:0 auto;"></div></div>
                        <div class="category-card-label">Payments</div>
                    </div>
                    <div class="category-card" onclick="window.location='suppliers.php'">
                        <div class="category-card-icon icon-teal">
                            <i class="fas fa-people-group"></i>
                        </div>
                        <div class="category-card-count" id="countSuppliers"><div class="skeleton" style="height:20px;width:30px;margin:0 auto;"></div></div>
                        <div class="category-card-label">Total Suppliers</div>
                    </div>
                    <div class="category-card" onclick="window.location='customers.php'">
                        <div class="category-card-icon icon-orange">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="category-card-count" id="countCustomers"><div class="skeleton" style="height:20px;width:30px;margin:0 auto;"></div></div>
                        <div class="category-card-label">Total Customers</div>
                    </div>
                </div>

            </div>

            <!-- ===================== CHARTS ROW ===================== -->
            <div class="dashboard-charts-row">
                <?php if ($showSales): ?>
                <div class="prices-chart-card" style="margin:0;">
                    <h3 style="margin:0 0 12px;"><i class="fas fa-chart-bar"></i> Monthly P&L Breakdown</h3>
                    <div style="position:relative;height:320px;">
                        <canvas id="salesPurchasesChart"></canvas>
                    </div>
                </div>
                <?php endif; ?>

                <div class="prices-chart-card" style="margin:0;">
                    <div class="prices-chart-header">
                        <h3 style="margin:0;"><i class="fas fa-chart-area"></i> Avg Purchase Price (F/kg)</h3>
                        <div class="price-trend-tabs" id="priceTrendTabs">
                            <button type="button" class="ptt-btn active" data-grain="daily">Daily</button>
                            <button type="button" class="ptt-btn" data-grain="weekly">Weekly</button>
                            <button type="button" class="ptt-btn" data-grain="monthly">Monthly</button>
                        </div>
                    </div>
                    <div style="position:relative;height:300px;margin-top:12px;">
                        <canvas id="pricesEvolutionChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- ===================== ROW 4: STOCK EVOLUTION + EXPENSES + REVENUE ===================== -->
            <div class="overview-bottom-row">
                <div class="overview-chart-card">
                    <h3><i class="fas fa-warehouse"></i> Stock Evolution</h3>
                    <div style="position:relative;height:250px;">
                        <canvas id="stockEvolutionChart"></canvas>
                    </div>
                </div>
                <?php if ($showFinancing): ?>
                <div class="overview-chart-card">
                    <h3><i class="fas fa-chart-bar"></i> Expenses by Category</h3>
                    <div style="position:relative;height:250px;">
                        <canvas id="expCategoryChart"></canvas>
                    </div>
                </div>
                <?php endif; ?>
                <?php if ($showSales): ?>
                <div class="revenue-highlight" id="revenueCard">
                    <div>
                        <div class="revenue-highlight-label">Total Revenue</div>
                        <div class="revenue-highlight-value" id="revenueValue"><div class="skeleton" style="height:36px;width:160px;background:rgba(255,255,255,0.15);"></div></div>
                        <div class="revenue-highlight-sub" id="revenueSub">
                            Quarter <i class="fas fa-arrow-trend-up"></i>
                        </div>
                    </div>
                    <div class="revenue-sparkline-container">
                        <canvas id="revenueSparkline"></canvas>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- ===================== AI INSIGHTS WIDGET — finance roles only (uses revenue/profit) ===================== -->
            <?php if ($showProfit): ?>
            <div class="prices-chart-card" style="border-left:4px solid var(--navy-accent);">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                    <h3 style="margin:0;"><i class="fas fa-brain" style="color:var(--navy-accent);"></i> AI Business Insights</h3>
                    <button class="btn btn-primary btn-sm" onclick="generateAIInsights()" id="aiInsightBtn">
                        <i class="fas fa-wand-magic-sparkles"></i> Generate
                    </button>
                </div>
                <div id="aiInsightsContent" style="min-height:60px;">
                    <div style="text-align:center;padding:20px;color:var(--text-muted);font-size:13px;">
                        <i class="fas fa-brain" style="font-size:24px;opacity:0.3;display:block;margin-bottom:8px;"></i>
                        Click "Generate" for AI-powered business insights
                    </div>
                </div>
            </div>
            <?php endif; ?>

        </div>
    </div>

    <script>
        var fmt = function(n) { return Number(n).toLocaleString(); };

        // Color palette
        var COLORS = {
            navy: '#001f3f', accent: '#0074D9', success: '#34a853',
            warning: '#fbbc04', danger: '#ea4335', blue: '#0074D9',
            orange: '#fd7e14', purple: '#6f42c1', teal: '#20c997',
            pink: '#e83e8c'
        };

        // ===================== DASHBOARD OVERVIEW (ALL ROLES) =====================
        var currentPeriod = 'year';
        var overviewCharts = {};

        function switchPeriod(period, btn) {
            currentPeriod = period;
            var tabs = document.querySelectorAll('#timeFilterTabs button');
            tabs.forEach(function(tab) { tab.classList.remove('active'); });
            btn.classList.add('active');
            loadDashboardOverview(period);
        }

        function destroyChart(key) {
            if (overviewCharts[key]) {
                overviewCharts[key].destroy();
                overviewCharts[key] = null;
            }
        }

        // ── Prices Evolution: render the smooth single-line chart at chosen granularity ──
        window._currentGrain = 'daily';
        function renderPriceTrendChart(grain) {
            var rows = (window._priceTrendData && window._priceTrendData[grain]) || [];
            window._currentGrain = grain;

            // sync toggle button states
            var btns = document.querySelectorAll('#priceTrendTabs .ptt-btn');
            btns.forEach(function(b) { b.classList.toggle('active', b.getAttribute('data-grain') === grain); });

            destroyChart('pricesEvolution');
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

            var grad = ctx.createLinearGradient(0, 0, 0, 300);
            grad.addColorStop(0, 'rgba(0, 116, 217, 0.28)');
            grad.addColorStop(1, 'rgba(0, 116, 217, 0)');

            // pick a sensible x-axis time unit per granularity
            var unit = grain === 'daily' ? 'day' : (grain === 'weekly' ? 'week' : 'month');
            var fmtTip = grain === 'monthly' ? 'MMM yyyy' : 'MMM d, yyyy';

            overviewCharts['pricesEvolution'] = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Avg Price (F/kg)',
                        data: prices,
                        borderColor: COLORS.accent,
                        backgroundColor: grad,
                        borderWidth: 2.5,
                        fill: true,
                        tension: 0.4,
                        pointRadius: 3,
                        pointHoverRadius: 6,
                        pointBackgroundColor: COLORS.accent,
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
                            time: { unit: unit, tooltipFormat: fmtTip, displayFormats: { day: 'MMM d', week: 'MMM d', month: 'MMM yyyy' } },
                            grid: { display: false },
                            ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 8 }
                        },
                        y: {
                            beginAtZero: false,
                            title: { display: true, text: 'F/kg' },
                            grid: { color: 'rgba(0,0,0,0.05)' }
                        }
                    },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function(ctx) {
                                    var i = ctx.dataIndex;
                                    var lines = [
                                        'Avg: ' + Number(prices[i]).toLocaleString('en-US', {maximumFractionDigits: 0}) + ' F/kg',
                                        'Volume: ' + Number(volumes[i]).toLocaleString('en-US') + ' kg',
                                        'Purchases: ' + counts[i]
                                    ];
                                    return lines;
                                }
                            }
                        }
                    }
                }
            });
        }

        // toggle wiring — delegate so it survives re-renders
        document.addEventListener('click', function(e) {
            var btn = e.target.closest && e.target.closest('#priceTrendTabs .ptt-btn');
            if (!btn) return;
            var grain = btn.getAttribute('data-grain');
            if (grain) renderPriceTrendChart(grain);
        });

        function timeAgo(dateStr) {
            var now = new Date();
            var d = new Date(dateStr);
            var diff = Math.floor((now - d) / 1000);
            if (diff < 60) return diff + 's ago';
            if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
            if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
            return Math.floor(diff / 86400) + 'd ago';
        }

        function getNotifIcon(action) {
            if (action.indexOf('Created') !== -1 || action.indexOf('INSERT') !== -1) return { cls: 'notif-icon-green', icon: 'fa-plus' };
            if (action.indexOf('Updated') !== -1 || action.indexOf('UPDATE') !== -1) return { cls: 'notif-icon-blue', icon: 'fa-pen' };
            if (action.indexOf('Deleted') !== -1 || action.indexOf('DELETE') !== -1) return { cls: 'notif-icon-red', icon: 'fa-trash' };
            if (action.indexOf('Login') !== -1) return { cls: 'notif-icon-yellow', icon: 'fa-sign-in-alt' };
            return { cls: 'notif-icon-blue', icon: 'fa-bell' };
        }

        function generateAIInsights() {
            var btn = document.getElementById('aiInsightBtn');
            var content = document.getElementById('aiInsightsContent');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Analyzing...';
            content.innerHTML = '<div class="skeleton" style="height:16px;width:90%;margin-bottom:8px;"></div><div class="skeleton" style="height:16px;width:75%;margin-bottom:8px;"></div><div class="skeleton" style="height:16px;width:80%;"></div>';

            $.getJSON('?action=getAIInsights').done(function(r) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-wand-magic-sparkles"></i> Generate';
                if (r.success) {
                    var text = r.text.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>').replace(/^\- /gm, '&bull; ').replace(/^\* /gm, '&bull; ').replace(/\n/g, '<br>');
                    content.innerHTML = '<div style="font-size:13px;line-height:1.7;color:var(--text-primary);">' + text + '</div>' +
                        '<div style="font-size:11px;color:var(--text-muted);margin-top:10px;"><i class="fas fa-clock"></i> ' + new Date().toLocaleTimeString() + ' | <a href="ai-reports.php" style="color:var(--navy-accent);">View Full Reports</a></div>';
                } else {
                    content.innerHTML = '<div style="color:var(--danger);font-size:13px;"><i class="fas fa-exclamation-triangle"></i> ' + r.message + '</div>';
                }
            }).fail(function() {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-wand-magic-sparkles"></i> Generate';
                content.innerHTML = '<div style="color:var(--danger);font-size:13px;">Connection error</div>';
            });
        }

        function loadDashboardOverview(period) {
            $.getJSON('?action=getDashboardOverview&period=' + (period || 'year') + '&_=' + Date.now()).done(function(r) {
                if (!r.success) { console.error('Dashboard API error:', r.message); return; }
                var d = r.data;

                // KPI Cards — guarded: elements only exist if role has access
                var el;
                document.getElementById('kpiStock').textContent = fmt(d.stock.total) + ' kg';
                var evoClass = d.stock.evolution >= 0 ? 'evolution-up' : 'evolution-down';
                var evoIcon = d.stock.evolution >= 0 ? '<i class="fas fa-arrow-up" style="font-size:10px;"></i>' : '<i class="fas fa-arrow-down" style="font-size:10px;"></i>';
                document.getElementById('kpiStockSub').innerHTML = '<span class="' + evoClass + '">' + evoIcon + ' ' + Math.abs(d.stock.evolution) + '% vs last month</span>';

                if ((el = document.getElementById('kpiSales'))) {
                    el.textContent = fmt(Math.round(d.sales.revenue)) + ' F';
                    var tons = (d.sales.volume / 1000).toFixed(1);
                    var salesEvoClass = d.sales.evolution >= 0 ? 'evolution-up' : 'evolution-down';
                    var salesEvoIcon = d.sales.evolution >= 0 ? '<i class="fas fa-arrow-up" style="font-size:10px;"></i>' : '<i class="fas fa-arrow-down" style="font-size:10px;"></i>';
                    document.getElementById('kpiSalesSub').innerHTML = tons + ' Tons sold <span class="' + salesEvoClass + '" style="margin-left:6px;">' + salesEvoIcon + ' ' + Math.abs(d.sales.evolution) + '%</span>';
                }

                if ((el = document.getElementById('kpiProfit'))) {
                    var profitVal = Math.round(d.sales.profit);
                    el.textContent = fmt(profitVal) + ' F';
                    el.style.color = profitVal >= 0 ? 'var(--success)' : 'var(--danger)';
                }

                if ((el = document.getElementById('kpiBankDebt'))) {
                    el.textContent = fmt(Math.round(d.bankDebt.total)) + ' F';
                    el.style.color = d.bankDebt.total > 0 ? '#e0a800' : 'var(--success)';
                    document.getElementById('kpiBankDebtSub').textContent = d.bankDebt.count + ' active loan' + (d.bankDebt.count !== 1 ? 's' : '') + ' · Repay in cash';
                }

                if ((el = document.getElementById('kpiCustAdv'))) {
                    el.textContent = fmt(Math.round(d.custAdvances.total)) + ' F';
                    el.style.color = d.custAdvances.total > 0 ? '#9b59b6' : 'var(--success)';
                    var volRem = d.custAdvances.volume_remaining;
                    var volTxt = volRem >= 1000 ? (volRem / 1000).toFixed(1) + ' T' : fmt(Math.round(volRem)) + ' kg';
                    document.getElementById('kpiCustAdvSub').innerHTML = d.custAdvances.count + ' active · <strong>' + volTxt + '</strong> to deliver';
                }

                // Customer Advance Coverage — customer advances / stock value
                if ((el = document.getElementById('kpiCoverage'))) {
                    var cov = d.advanceCoverage || {};
                    var ratio = cov.ratio;
                    var color, grad, label;
                    if (ratio === null || ratio === undefined) {
                        color = '#7f8c8d'; grad = 'linear-gradient(135deg,#7f8c8d,#636e72)'; label = 'No stock';
                        el.textContent = '—';
                    } else if (ratio >= 1.00) {
                        color = '#1a9c6b'; grad = 'linear-gradient(135deg,#1a9c6b,#0d7a4f)'; label = 'Fully covered';
                        el.textContent = ratio.toFixed(3);
                    } else if (ratio >= 0.80) {
                        color = '#f39c12'; grad = 'linear-gradient(135deg,#f39c12,#d4820b)'; label = 'Partial cover';
                        el.textContent = ratio.toFixed(3);
                    } else {
                        color = '#e74c3c'; grad = 'linear-gradient(135deg,#e74c3c,#b8341a)'; label = 'Low coverage';
                        el.textContent = ratio.toFixed(3);
                    }
                    el.style.color = color;
                    document.getElementById('kpiCoverageIcon').style.background = grad;
                    document.getElementById('kpiCoverageAccent').style.background = color;
                    document.getElementById('kpiCoverageSub').innerHTML = '<strong>' + label + '</strong> · ' + fmt(Math.round(cov.advances)) + ' / ' + fmt(Math.round(cov.unsold_value)) + ' F';
                }

                if ((el = document.getElementById('kpiSupFinOwed'))) {
                    el.textContent = fmt(Math.round(d.supplierFinancing.owed)) + ' F';
                    el.style.color = d.supplierFinancing.owed > 0 ? '#e67e22' : 'var(--text-muted)';
                    document.getElementById('kpiSupFinOwedSub').textContent = d.supplierFinancing.count + ' active agreement' + (d.supplierFinancing.count !== 1 ? 's' : '');
                }

                if ((el = document.getElementById('kpiExpectedVol'))) {
                    var volKg = d.supplierFinancing.volume_remaining;
                    if (volKg >= 1000) {
                        el.textContent = (volKg / 1000).toFixed(1) + ' T';
                    } else {
                        el.textContent = fmt(Math.round(volKg)) + ' kg';
                    }
                    el.style.color = volKg > 0 ? '#00b894' : 'var(--text-muted)';
                }

                if ((el = document.getElementById('kpiSupplierDebt'))) {
                    el.textContent = fmt(Math.round(d.supplierDebt)) + ' F';
                    el.style.color = d.supplierDebt > 0 ? 'var(--danger)' : 'var(--success)';
                }

                if ((el = document.getElementById('kpiFinancingPower'))) {
                    el.textContent = fmt(Math.round(d.financingPower)) + ' F';
                    el.style.color = d.financingPower >= 0 ? 'var(--success)' : 'var(--danger)';
                }

                // Category counts
                document.getElementById('countPurchases').textContent = d.counts.purchases;
                document.getElementById('badgePurchases').textContent = d.counts.purchases;
                document.getElementById('countDeliveries').textContent = d.counts.deliveries;
                document.getElementById('badgeDeliveries').textContent = d.counts.pendingDeliveries;
                document.getElementById('countPayments').textContent = d.counts.payments;
                document.getElementById('countSuppliers').textContent = d.counts.suppliers;
                document.getElementById('countCustomers').textContent = d.counts.customers;

                // Prices Evolution Chart — single smooth line, weighted avg purchase price, daily/weekly/monthly toggle
                try {
                    window._priceTrendData = d.priceTrend || { daily: [], weekly: [], monthly: [] };
                    renderPriceTrendChart(window._currentGrain || 'daily');
                } catch(e) { console.error('Prices chart error:', e); }

                // Stock Evolution Chart
                try {
                    destroyChart('stockEvolution');
                    var seCanvas = document.getElementById('stockEvolutionChart');
                    if (seCanvas && d.monthlyStock.length > 0) {
                        var seCtx = seCanvas.getContext('2d');
                        var seGrad = seCtx.createLinearGradient(0, 0, 0, 250);
                        seGrad.addColorStop(0, 'rgba(0, 31, 63, 0.3)');
                        seGrad.addColorStop(1, 'rgba(0, 31, 63, 0)');
                        overviewCharts['stockEvolution'] = new Chart(seCtx, {
                            type: 'line',
                            data: {
                                labels: d.monthlyStock.map(function(m) { return m.month; }),
                                datasets: [{
                                    label: 'Stock (kg)',
                                    data: d.monthlyStock.map(function(m) { return m.stock; }),
                                    borderColor: COLORS.navy,
                                    backgroundColor: seGrad,
                                    fill: true,
                                    tension: 0.4,
                                    pointRadius: 3,
                                    pointBackgroundColor: COLORS.navy
                                }]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                scales: { y: { beginAtZero: true }, x: { grid: { display: false } } },
                                plugins: { legend: { display: false } }
                            }
                        });
                    }
                } catch(e) { console.error('Stock chart error:', e); }

                // Expenses by Category Chart
                try {
                    var ecCanvas = document.getElementById('expCategoryChart');
                    if (ecCanvas) {
                        destroyChart('expCategory');
                        if (d.expenseByCategory.length > 0) {
                            var ecColors = [COLORS.accent, COLORS.danger, COLORS.warning, COLORS.success, COLORS.purple, COLORS.teal, COLORS.orange, COLORS.pink];
                            overviewCharts['expCategory'] = new Chart(ecCanvas, {
                                type: 'bar',
                                data: {
                                    labels: d.expenseByCategory.map(function(c) { return c.category; }),
                                    datasets: [{
                                        label: 'Amount (F)',
                                        data: d.expenseByCategory.map(function(c) { return parseFloat(c.total); }),
                                        backgroundColor: d.expenseByCategory.map(function(c, i) { return ecColors[i % ecColors.length]; }),
                                        borderRadius: 6,
                                        borderSkipped: false
                                    }]
                                },
                                options: {
                                    responsive: true,
                                    maintainAspectRatio: false,
                                    scales: { y: { beginAtZero: true }, x: { grid: { display: false } } },
                                    plugins: { legend: { display: false } }
                                }
                            });
                        }
                    }
                } catch(e) { console.error('Expenses chart error:', e); }

                // Revenue Highlight + Sparkline
                try {
                    if (document.getElementById('revenueValue'))
                        document.getElementById('revenueValue').textContent = fmt(Math.round(d.totalRevenue)) + ' F';

                    var rsCanvas = document.getElementById('revenueSparkline');
                    destroyChart('revenueSparkline');
                    if (d.monthlyRevenue.length > 0 && rsCanvas) {
                        var rsCtx = rsCanvas.getContext('2d');
                        var rsGrad = rsCtx.createLinearGradient(0, 0, 0, 80);
                        rsGrad.addColorStop(0, 'rgba(255, 255, 255, 0.3)');
                        rsGrad.addColorStop(1, 'rgba(255, 255, 255, 0.02)');
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
                                scales: { x: { display: false }, y: { display: false } },
                                plugins: { legend: { display: false }, tooltip: { enabled: false } }
                            }
                        });
                    }
                } catch(e) { console.error('Revenue chart error:', e); }
            }).fail(function(jqXHR, textStatus, errorThrown) {
                console.error('Dashboard AJAX failed:', textStatus, errorThrown);
                console.error('Response:', jqXHR.responseText);
            });
        }

        // Sales vs Purchases chart
        var spChart = null;

        function loadSalesPurchasesChart(period) {
            period = period || 'monthly';

            $.getJSON('?action=getSalesPurchasesChart&period=' + period, function(res) {
                if (!res.success) return;
                var d = res.data;
                var canvas = document.getElementById('salesPurchasesChart');
                if (!canvas) return;

                var ctx = canvas.getContext('2d');
                if (spChart) spChart.destroy();

                spChart = new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: d.map(function(r) { return r.period; }),
                        datasets: [
                            {
                                label: 'Revenue',
                                data: d.map(function(r) { return r.revenue; }),
                                backgroundColor: '#0074D9',
                                borderRadius: 4
                            },
                            {
                                label: 'Costs',
                                data: d.map(function(r) { return r.costs; }),
                                backgroundColor: '#e74c3c',
                                borderRadius: 4
                            },
                            {
                                label: 'Profit',
                                data: d.map(function(r) { return r.profit; }),
                                backgroundColor: d.map(function(r) { return r.profit >= 0 ? '#27ae60' : '#ff6b6b'; }),
                                borderRadius: 4
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: { intersect: false, mode: 'index' },
                        plugins: {
                            legend: { position: 'top' },
                            tooltip: {
                                callbacks: {
                                    label: function(ctx) {
                                        return ctx.dataset.label + ': ' + ctx.parsed.y.toLocaleString() + ' F';
                                    }
                                }
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    callback: function(val) {
                                        if (Math.abs(val) >= 1000000) return (val / 1000000).toFixed(1) + 'M';
                                        if (Math.abs(val) >= 1000) return (val / 1000).toFixed(0) + 'K';
                                        return val;
                                    }
                                }
                            },
                            x: { grid: { display: false } }
                        }
                    }
                });
            });
        }

        // Load overview on page load
        loadDashboardOverview('year');

        // Load sales vs purchases chart after slight delay
        setTimeout(function() { loadSalesPurchasesChart('monthly'); }, 800);

    </script>
</body>
</html>
