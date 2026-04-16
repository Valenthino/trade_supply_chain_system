<?php
/**
 * Developed by Rameez Scripts
 * WhatsApp: https://wa.me/923224083545 (For Custom Projects)
 * YouTube: https://www.youtube.com/@rameezimdad (Subscribe for more!)
 */
require_once 'config.php';
require_once 'ai-helper.php';

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
$current_page = 'ai-reports';

// RBAC
$allowedRoles = ['Admin', 'Manager', 'Finance Officer', 'Sales Officer'];
if (!in_array($role, $allowedRoles)) {
    header("Location: dashboard.php");
    exit();
}

// Handle AJAX requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    try {
        switch ($_GET['action']) {

            case 'getAIReport':
                $reportType = isset($_GET['report_type']) ? trim($_GET['report_type']) : '';
                $dateFrom = isset($_GET['date_from']) ? trim($_GET['date_from']) : date('Y-m-01');
                $dateTo = isset($_GET['date_to']) ? trim($_GET['date_to']) : date('Y-m-t');

                $validTypes = ['monthly_summary', 'profit_analysis', 'supplier_performance', 'customer_risk', 'price_trends'];
                if (!in_array($reportType, $validTypes)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid report type']);
                    exit();
                }

                $periodLabel = date('d/m/Y', strtotime($dateFrom)) . ' — ' . date('d/m/Y', strtotime($dateTo));
                $conn = getDBConnection();
                $prompt = '';
                $tableInstruction = "\n\nIMPORTANT FORMATTING: Use markdown tables with | headers | for all data summaries. Use ## for section headers. Use **bold** for key numbers. Keep it structured and professional.";

                switch ($reportType) {

                    case 'monthly_summary':
                        $stmt = $conn->prepare("SELECT COUNT(*) as count, COALESCE(SUM(weight_kg),0) as total_weight, COALESCE(SUM(total_cost),0) as total_cost, COALESCE(AVG(final_price_per_kg),0) as avg_price, COALESCE(AVG(kor_out_turn),0) as avg_kor FROM purchases WHERE date BETWEEN ? AND ?");
                        $stmt->bind_param("ss", $dateFrom, $dateTo);
                        $stmt->execute();
                        $purchases = $stmt->get_result()->fetch_assoc();
                        $stmt->close();

                        $stmt = $conn->prepare("SELECT COUNT(*) as count, COALESCE(SUM(net_weight_kg),0) as total_weight, COALESCE(SUM(gross_sale_amount),0) as total_revenue, COALESCE(SUM(net_profit),0) as total_profit, COALESCE(AVG(selling_price_per_kg),0) as avg_price FROM sales WHERE sale_status='Confirmed' AND unloading_date BETWEEN ? AND ?");
                        $stmt->bind_param("ss", $dateFrom, $dateTo);
                        $stmt->execute();
                        $sales = $stmt->get_result()->fetch_assoc();
                        $stmt->close();

                        $stmt = $conn->prepare("SELECT COUNT(*) as count, COALESCE(SUM(weight_kg),0) as total_weight FROM deliveries WHERE date BETWEEN ? AND ?");
                        $stmt->bind_param("ss", $dateFrom, $dateTo);
                        $stmt->execute();
                        $deliveries = $stmt->get_result()->fetch_assoc();
                        $stmt->close();

                        $stmt = $conn->prepare("SELECT direction, COUNT(*) as count, COALESCE(SUM(amount),0) as total FROM payments WHERE date BETWEEN ? AND ? GROUP BY direction");
                        $stmt->bind_param("ss", $dateFrom, $dateTo);
                        $stmt->execute();
                        $paymentsResult = $stmt->get_result();
                        $payments = [];
                        if ($paymentsResult) { while ($row = $paymentsResult->fetch_assoc()) $payments[] = $row; }
                        $stmt->close();

                        $stmt = $conn->prepare("SELECT COUNT(*) as count, COALESCE(SUM(amount),0) as total FROM expenses WHERE date BETWEEN ? AND ?");
                        $stmt->bind_param("ss", $dateFrom, $dateTo);
                        $stmt->execute();
                        $expenses = $stmt->get_result()->fetch_assoc();
                        $stmt->close();

                        $financing = $conn->query("SELECT COUNT(*) as count, COALESCE(SUM(amount),0) as total_amount, COALESCE(SUM(balance_due),0) as total_balance FROM financing WHERE status = 'Active'")->fetch_assoc();

                        $data = json_encode(compact('purchases', 'sales', 'deliveries', 'payments', 'expenses', 'financing'), JSON_PRETTY_PRINT);
                        $prompt = "Generate a detailed executive business summary IN FRENCH for the period {$periodLabel}. Data:\n{$data}\n\nStructure: 1) Vue d'ensemble with a summary table of all KPIs, 2) Analyse des achats, 3) Analyse des ventes, 4) Situation financiere, 5) Recommandations." . $tableInstruction;
                        break;

                    case 'profit_analysis':
                        $stmt = $conn->prepare("SELECT s.sale_id, c.customer_name, s.unloading_date, s.net_weight_kg, s.selling_price_per_kg, s.gross_sale_amount, s.total_costs, s.net_profit, s.profit_per_kg, s.sale_status FROM sales s LEFT JOIN customers c ON s.customer_id = c.customer_id WHERE s.unloading_date BETWEEN ? AND ? ORDER BY s.net_profit DESC");
                        $stmt->bind_param("ss", $dateFrom, $dateTo);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        $salesData = [];
                        if ($result) { while ($row = $result->fetch_assoc()) $salesData[] = $row; }
                        $stmt->close();

                        $stmt = $conn->prepare("SELECT COALESCE(SUM(amount),0) as total_expenses FROM expenses WHERE date BETWEEN ? AND ?");
                        $stmt->bind_param("ss", $dateFrom, $dateTo);
                        $stmt->execute();
                        $expenseTotal = $stmt->get_result()->fetch_assoc();
                        $stmt->close();

                        $data = json_encode(['sales' => $salesData, 'total_expenses' => $expenseTotal['total_expenses']], JSON_PRETTY_PRINT);
                        $prompt = "Analyze profit data for period {$periodLabel}. Create: 1) Summary table of all sales with profit/kg, 2) Top and bottom performers, 3) Margin analysis, 4) 3 specific recommendations. Data:\n{$data}" . $tableInstruction;
                        break;

                    case 'supplier_performance':
                        $stmt = $conn->prepare("SELECT supplier_id, supplier_name, COUNT(*) as transactions, COALESCE(AVG(kor_out_turn),0) as avg_kor, COALESCE(AVG(final_price_per_kg),0) as avg_price, COALESCE(SUM(weight_kg),0) as total_weight, COALESCE(SUM(total_cost),0) as total_cost, COALESCE(AVG(grainage),0) as avg_grainage FROM purchases WHERE date BETWEEN ? AND ? GROUP BY supplier_id, supplier_name ORDER BY total_weight DESC");
                        $stmt->bind_param("ss", $dateFrom, $dateTo);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        $supplierData = [];
                        if ($result) { while ($row = $result->fetch_assoc()) $supplierData[] = $row; }
                        $stmt->close();

                        $data = json_encode($supplierData, JSON_PRETTY_PRINT);
                        $prompt = "Rank and evaluate supplier performance for period {$periodLabel}. Create: 1) Ranking table with all metrics (KOR, price, volume, grainage), 2) Detailed analysis per supplier, 3) Recommendations. KOR: higher=better (42-52 lbs typical). Data:\n{$data}" . $tableInstruction;
                        break;

                    case 'customer_risk':
                        $customers = [];
                        $result = $conn->query("SELECT customer_id, customer_name, COALESCE(financing_provided,0) as financing_provided FROM customers ORDER BY customer_name");
                        if ($result) { while ($row = $result->fetch_assoc()) $customers[$row['customer_id']] = $row; }

                        $result = $conn->query("SELECT counterparty_id, COALESCE(SUM(balance_due),0) as total_balance, COUNT(*) as fin_count FROM financing WHERE counterpart_type='Customer' GROUP BY counterparty_id");
                        if ($result) { while ($row = $result->fetch_assoc()) { if (isset($customers[$row['counterparty_id']])) { $customers[$row['counterparty_id']]['financing_balance'] = $row['total_balance']; $customers[$row['counterparty_id']]['financing_count'] = $row['fin_count']; } } }

                        $stmt = $conn->prepare("SELECT customer_id, COUNT(*) as del_count, COALESCE(SUM(weight_kg),0) as total_kg FROM deliveries WHERE date BETWEEN ? AND ? GROUP BY customer_id");
                        $stmt->bind_param("ss", $dateFrom, $dateTo);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        if ($result) { while ($row = $result->fetch_assoc()) { if (isset($customers[$row['customer_id']])) { $customers[$row['customer_id']]['deliveries'] = $row['del_count']; $customers[$row['customer_id']]['delivered_kg'] = $row['total_kg']; } } }
                        $stmt->close();

                        $stmt = $conn->prepare("SELECT counterpart_id, COUNT(*) as pay_count, COALESCE(SUM(amount),0) as total_paid FROM payments WHERE direction='Incoming' AND date BETWEEN ? AND ? GROUP BY counterpart_id");
                        $stmt->bind_param("ss", $dateFrom, $dateTo);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        if ($result) { while ($row = $result->fetch_assoc()) { if (isset($customers[$row['counterpart_id']])) { $customers[$row['counterpart_id']]['payments'] = $row['pay_count']; $customers[$row['counterpart_id']]['total_paid'] = $row['total_paid']; } } }
                        $stmt->close();

                        $data = json_encode(array_values($customers), JSON_PRETTY_PRINT);
                        $prompt = "Assess customer risk for period {$periodLabel}. Create: 1) Risk summary table rating each customer Low/Medium/High, 2) Detailed analysis per customer, 3) Action items for high-risk customers. Data:\n{$data}" . $tableInstruction;
                        break;

                    case 'price_trends':
                        $agreements = [];
                        $result = $conn->query("SELECT price_agreement_id, supplier_id, supplier_name, base_cost_per_kg, effective_date, status FROM supplier_pricing_agreements ORDER BY effective_date DESC LIMIT 20");
                        if ($result) { while ($row = $result->fetch_assoc()) $agreements[] = $row; }

                        $priceHistory = [];
                        $result = $conn->query("SELECT DATE_FORMAT(date, '%Y-%m') as month, COALESCE(AVG(final_price_per_kg),0) as avg_price, COALESCE(SUM(weight_kg),0) as total_volume, COUNT(*) as transactions FROM purchases GROUP BY DATE_FORMAT(date, '%Y-%m') ORDER BY month DESC LIMIT 12");
                        if ($result) { while ($row = $result->fetch_assoc()) $priceHistory[] = $row; }

                        $data = json_encode(['agreements' => $agreements, 'monthly_prices' => $priceHistory], JSON_PRETTY_PRINT);
                        $prompt = "Analyze price trends and predict movements for the next quarter based on period {$periodLabel}. Create: 1) Price history table by month, 2) Agreement comparison table, 3) Trend analysis, 4) Price prediction with reasoning. Data:\n{$data}" . $tableInstruction;
                        break;
                }

                $conn->close();

                $result = callGemini($prompt, getBusinessSystemPrompt());
                if ($result['success']) {
                    echo json_encode(['success' => true, 'text' => $result['text'], 'model' => $result['model'] ?? 'gemini', 'period' => $periodLabel]);
                } else {
                    echo json_encode(['success' => false, 'message' => $result['message'] ?? 'AI generation failed']);
                }
                exit();

            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
                exit();
        }

    } catch (\Throwable $e) {
        error_log("ai-reports.php error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
        exit();
    }
}
?>
<!-- Developed by Rameez Scripts — https://www.youtube.com/@rameezimdad -->
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Commodity Flow &mdash; AI Reports</title>

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

  <!-- App Stylesheet -->
  <link rel="stylesheet" href="styles.css" />

  <!-- JS Libraries -->
  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

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

    /* Report Tabs */
    .report-tabs { display:flex; border-bottom:2px solid #e2e8f0; background:white; padding:0 16px; gap:0; overflow-x:auto; border-radius:0; }
    .dark .report-tabs { border-bottom-color:#334155; background:#1e293b; }
    .report-tab { padding:13px 18px; border:none; background:transparent; color:#64748b; font-size:13px; font-weight:600; cursor:pointer; border-bottom:3px solid transparent; transition:all .3s; font-family:inherit; display:flex; align-items:center; gap:7px; white-space:nowrap; }
    .report-tab:hover { color:#7c3aed; background:rgba(124,58,237,.05); }
    .report-tab.active { color:#7c3aed; border-bottom-color:#7c3aed; }

    /* AI Response Card */
    .ai-response-card { background:white; border-radius:12px; border:1px solid #e2e8f0; border-left:4px solid #7c3aed; box-shadow:0 1px 3px rgba(0,0,0,0.06); overflow:hidden; margin:20px 0; }
    .dark .ai-response-card { background:#1e293b; border-color:#334155; border-left-color:#7c3aed; }
    .ai-response-header { display:flex; justify-content:space-between; align-items:center; padding:12px 20px; background:rgba(124,58,237,.04); border-bottom:1px solid #f1f5f9; font-weight:600; font-size:14px; color:#1e293b; }
    .dark .ai-response-header { background:rgba(124,58,237,.08); border-bottom-color:#334155; color:#e2e8f0; }
    .ai-response-body { padding:28px; line-height:1.75; color:#334155; font-size:14px; }
    .dark .ai-response-body { color:#cbd5e1; }
    .ai-response-body h1 { font-size:20px; color:#7c3aed; margin:20px 0 10px; padding-bottom:6px; border-bottom:2px solid #7c3aed; }
    .dark .ai-response-body h1 { color:#a78bfa; border-bottom-color:#a78bfa; }
    .ai-response-body h2 { font-size:16px; color:#1e293b; margin:18px 0 8px; padding-bottom:4px; border-bottom:1px solid #f1f5f9; }
    .dark .ai-response-body h2 { color:#e2e8f0; border-bottom-color:#334155; }
    .ai-response-body h3 { font-size:14px; color:#334155; margin:14px 0 6px; font-weight:700; }
    .dark .ai-response-body h3 { color:#e2e8f0; }
    .ai-response-body p { margin:6px 0; }
    .ai-response-body ul, .ai-response-body ol { margin:8px 0 12px; padding-left:24px; }
    .ai-response-body li { margin:5px 0; line-height:1.6; }
    .ai-response-body strong { color:#1e293b; }
    .dark .ai-response-body strong { color:#e2e8f0; }

    /* AI tables */
    .ai-response-body table, .ai-response-body .ai-table { width:100%; border-collapse:collapse; margin:14px 0 18px; font-size:12px; border:1px solid #e2e8f0; border-radius:4px; overflow:hidden; }
    .dark .ai-response-body table, .dark .ai-response-body .ai-table { border-color:#334155; }
    .ai-response-body table th, .ai-response-body .ai-table th { background:#7c3aed; color:white; padding:9px 12px; text-align:left; font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:.4px; border:1px solid rgba(255,255,255,.1); }
    .ai-response-body table td, .ai-response-body .ai-table td { padding:8px 12px; border:1px solid #e2e8f0; font-size:12px; }
    .dark .ai-response-body table td, .dark .ai-response-body .ai-table td { border-color:#334155; color:#cbd5e1; }
    .ai-response-body table tbody tr:nth-child(even) { background:rgba(124,58,237,.02); }
    .dark .ai-response-body table tbody tr:nth-child(even) { background:rgba(124,58,237,.05); }
    .ai-response-body table tbody tr:hover { background:rgba(124,58,237,.06); }
    .dark .ai-response-body table tbody tr:hover { background:rgba(124,58,237,.1); }

    .ai-response-footer { display:flex; justify-content:space-between; align-items:center; padding:10px 20px; font-size:11px; color:#64748b; border-top:1px solid #f1f5f9; background:rgba(0,0,0,.01); }
    .dark .ai-response-footer { border-top-color:#334155; color:#94a3b8; }
    .ai-model-badge { background:rgba(124,58,237,.1); color:#7c3aed; padding:3px 10px; border-radius:12px; font-size:11px; font-weight:600; }
    .ai-status-badge { display:inline-flex; align-items:center; gap:6px; background:rgba(16,185,129,.08); padding:6px 14px; border-radius:20px; font-size:12px; font-weight:600; color:#059669; border:1px solid rgba(16,185,129,.2); }

    /* Responsive */
    @media (max-width: 768px) {
      .report-tabs { padding: 0 6px; }
      .report-tab { padding: 10px 12px; font-size: 11px; gap: 5px; }
      .ai-response-body { padding: 16px; font-size: 13px; }
      .ai-response-header { flex-direction: column; gap: 10px; align-items: flex-start; }
      .ai-response-footer { flex-direction: column; gap: 4px; }
    }
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
        <i class="fas fa-brain text-violet-500 text-sm"></i>
        <h1 class="text-base font-bold text-slate-800 dark:text-white">AI Reports</h1>
      </div>

      <div class="ml-auto flex items-center gap-2">
        <div class="ai-status-badge">
          <i class="fas fa-circle text-emerald-500" style="font-size:8px;"></i>
          <span id="aiModelName"><?php echo htmlspecialchars(getSetting('gemini_model', 'gemini-2.0-flash')); ?></span>
        </div>
        <button class="bg-violet-500 hover:bg-violet-600 text-white text-xs font-semibold px-3.5 py-2 rounded-lg transition-colors" onclick="generateReport()">
          <i class="fas fa-wand-magic-sparkles mr-1"></i> Generate Report
        </button>
      </div>
    </header>

    <!-- MAIN SCROLLABLE AREA -->
    <main class="flex-1 overflow-y-auto p-5">

      <!-- Filters Card -->
      <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card p-4 mb-4">
        <div class="flex items-center justify-between mb-3">
          <div class="flex items-center gap-2">
            <i class="fas fa-calendar-days text-violet-500 text-xs"></i>
            <h3 class="text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase tracking-wide">Report Period</h3>
          </div>
          <div class="flex gap-2">
            <button class="bg-slate-100 hover:bg-slate-200 text-slate-700 dark:bg-slate-700 dark:hover:bg-slate-600 dark:text-slate-300 text-xs font-semibold px-3 py-1.5 rounded-lg transition-colors" onclick="setQuickRange('month')">This Month</button>
            <button class="bg-slate-100 hover:bg-slate-200 text-slate-700 dark:bg-slate-700 dark:hover:bg-slate-600 dark:text-slate-300 text-xs font-semibold px-3 py-1.5 rounded-lg transition-colors" onclick="setQuickRange('quarter')">Quarter</button>
            <button class="bg-slate-100 hover:bg-slate-200 text-slate-700 dark:bg-slate-700 dark:hover:bg-slate-600 dark:text-slate-300 text-xs font-semibold px-3 py-1.5 rounded-lg transition-colors" onclick="setQuickRange('season')">Full Season</button>
          </div>
        </div>
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">Date From</label>
            <input type="date" id="dateFrom" value="<?php echo date('Y-m-01'); ?>" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-violet-400 transition-colors">
          </div>
          <div>
            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">Date To</label>
            <input type="date" id="dateTo" value="<?php echo date('Y-m-t'); ?>" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-violet-400 transition-colors">
          </div>
        </div>
      </div>

      <!-- Report Type Tabs Card -->
      <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card overflow-hidden">
        <div class="report-tabs" id="reportTabs">
          <button class="report-tab active" onclick="switchTab('monthly_summary', this)"><i class="fas fa-chart-pie"></i> Monthly Summary</button>
          <button class="report-tab" onclick="switchTab('profit_analysis', this)"><i class="fas fa-coins"></i> Profit Analysis</button>
          <button class="report-tab" onclick="switchTab('supplier_performance', this)"><i class="fas fa-truck-field"></i> Suppliers</button>
          <button class="report-tab" onclick="switchTab('customer_risk', this)"><i class="fas fa-shield-halved"></i> Customer Risk</button>
          <button class="report-tab" onclick="switchTab('price_trends', this)"><i class="fas fa-chart-line"></i> Price Trends</button>
        </div>

        <!-- Report Content Area -->
        <div id="reportArea" class="min-h-[200px]">
          <div class="text-center py-12 px-5">
            <div class="w-20 h-20 mx-auto mb-4 rounded-full bg-violet-50 dark:bg-violet-900/20 flex items-center justify-center">
              <i class="fas fa-brain text-3xl text-violet-500"></i>
            </div>
            <h3 class="text-base font-semibold text-slate-800 dark:text-white mb-1.5">Generate AI Report</h3>
            <p class="text-slate-500 dark:text-slate-400 text-sm max-w-sm mx-auto mb-5">Select a report type, set your date range, and click Generate to create an AI-powered business analysis.</p>
            <button class="btn btn-primary" onclick="generateReport()" style="padding:12px 30px;font-size:14px;">
              <i class="fas fa-wand-magic-sparkles"></i> Generate Report
            </button>
          </div>
        </div>
      </div>

    </main>
  </div>
</div>

<script>
var currentTab = 'monthly_summary';
var lastPeriod = '';

function setQuickRange(range) {
    var now = new Date();
    var from, to;
    if (range === 'month') {
        from = new Date(now.getFullYear(), now.getMonth(), 1);
        to = new Date(now.getFullYear(), now.getMonth() + 1, 0);
    } else if (range === 'quarter') {
        var q = Math.floor(now.getMonth() / 3) * 3;
        from = new Date(now.getFullYear(), q, 1);
        to = new Date(now.getFullYear(), q + 3, 0);
    } else if (range === 'season') {
        from = new Date(2025, 3, 1);  // Apr 2025
        to = new Date(2026, 2, 31);   // Mar 2026
    }
    document.getElementById('dateFrom').value = from.toISOString().split('T')[0];
    document.getElementById('dateTo').value = to.toISOString().split('T')[0];
}

function switchTab(tab, btn) {
    currentTab = tab;
    document.querySelectorAll('.report-tab').forEach(function(t) { t.classList.remove('active'); });
    btn.classList.add('active');
    var tabName = btn.textContent.trim();
    document.getElementById('reportArea').innerHTML = '<div style="text-align:center;padding:50px 20px;">' +
        '<div style="width:80px;height:80px;margin:0 auto 16px;border-radius:50%;background:linear-gradient(135deg,rgba(0,116,217,0.1),rgba(0,116,217,0.1));display:flex;align-items:center;justify-content:center;">' +
        '<i class="fas fa-brain" style="font-size:32px;color:var(--navy-accent);"></i></div>' +
        '<h3 style="color:var(--text-primary);font-size:16px;margin-bottom:6px;">' + tabName + '</h3>' +
        '<p style="color:var(--text-muted);font-size:13px;max-width:400px;margin:0 auto 20px;">Set your date range above and click Generate to create this report.</p>' +
        '<button class="btn btn-primary" onclick="generateReport()" style="padding:12px 30px;font-size:14px;">' +
        '<i class="fas fa-wand-magic-sparkles"></i> Generate Report</button></div>';
}

function generateReport() {
    var dateFrom = document.getElementById('dateFrom').value;
    var dateTo = document.getElementById('dateTo').value;
    if (!dateFrom || !dateTo) {
        Swal.fire({ icon: 'warning', title: 'Select Period', text: 'Please select both From and To dates.' });
        return;
    }

    var area = document.getElementById('reportArea');
    var activeTabName = document.querySelector('.report-tab.active').textContent.trim();
    area.innerHTML = '<div class="ai-response-card">' +
        '<div class="ai-response-header"><div><i class="fas fa-spinner fa-spin" style="color:var(--navy-accent);margin-right:8px;"></i>Generating: ' + activeTabName + '</div><span class="ai-model-badge">' + (document.getElementById('aiModelName').textContent || 'gemini') + '</span></div>' +
        '<div class="ai-response-body" style="text-align:center;padding:50px 20px;">' +
        '<div class="skeleton" style="height:20px;width:60%;margin:0 auto 14px;"></div>' +
        '<div class="skeleton" style="height:14px;width:90%;margin:0 auto 10px;"></div>' +
        '<div class="skeleton" style="height:14px;width:75%;margin:0 auto 10px;"></div>' +
        '<div class="skeleton" style="height:14px;width:85%;margin:0 auto 10px;"></div>' +
        '<div class="skeleton" style="height:100px;width:100%;margin:16px auto 10px;"></div>' +
        '<div class="skeleton" style="height:14px;width:70%;margin:0 auto 10px;"></div>' +
        '<div class="skeleton" style="height:14px;width:80%;margin:0 auto;"></div>' +
        '<p style="margin-top:24px;color:var(--text-muted);font-size:13px;">' +
        '<i class="fas fa-brain" style="margin-right:6px;color:var(--navy-accent);"></i>AI is analyzing your data... This may take 10-30 seconds</p>' +
        '</div></div>';

    $.getJSON('?action=getAIReport&report_type=' + encodeURIComponent(currentTab) + '&date_from=' + dateFrom + '&date_to=' + dateTo)
    .done(function(r) {
        if (!r.success) {
            area.innerHTML = '<div class="ai-response-card"><div class="ai-response-body" style="text-align:center;padding:40px;">' +
                '<div style="width:60px;height:60px;margin:0 auto 14px;border-radius:50%;background:rgba(234,67,53,0.1);display:flex;align-items:center;justify-content:center;">' +
                '<i class="fas fa-exclamation-triangle" style="font-size:24px;color:var(--danger);"></i></div>' +
                '<p style="color:var(--text-primary);font-weight:600;margin-bottom:6px;">Report Generation Failed</p>' +
                '<p style="color:var(--text-muted);font-size:13px;margin-bottom:16px;">' + escapeHtml(r.message) + '</p>' +
                '<button class="btn btn-primary" onclick="generateReport()" style=""><i class="fas fa-sync"></i> Try Again</button>' +
                '</div></div>';
            return;
        }
        lastPeriod = r.period || '';
        var genTime = new Date().toLocaleDateString('fr-FR') + ' ' + new Date().toLocaleTimeString('fr-FR', {hour:'2-digit',minute:'2-digit'});
        var html = '<div class="ai-response-card">';
        html += '<div class="ai-response-header"><div><i class="fas fa-brain" style="color:var(--navy-accent);margin-right:8px;"></i>' + activeTabName + ' — ' + escapeHtml(lastPeriod) + '</div>';
        html += '<div style="display:flex;gap:6px;align-items:center;">';
        html += '<span class="ai-model-badge">' + escapeHtml(r.model || 'gemini') + '</span>';
        html += '<button class="btn btn-secondary btn-sm" onclick="printAIReport()" style="padding:5px 12px;" title="Print"><i class="fas fa-print"></i></button>';
        html += '<button class="btn btn-primary btn-sm" onclick="generateReport()" style="padding:5px 12px;"><i class="fas fa-sync"></i> Regenerate</button>';
        html += '</div></div>';
        html += '<div class="ai-response-body" id="aiResponseBody">' + markdownToHtml(r.text) + '</div>';
        html += '<div class="ai-response-footer"><span><i class="fas fa-clock" style="margin-right:4px;"></i>' + genTime + '</span><span>Period: ' + escapeHtml(lastPeriod) + '</span></div>';
        html += '</div>';
        area.innerHTML = html;
    })
    .fail(function() {
        area.innerHTML = '<div class="ai-response-card"><div class="ai-response-body" style="text-align:center;padding:40px;">' +
            '<div style="width:60px;height:60px;margin:0 auto 14px;border-radius:50%;background:rgba(234,67,53,0.1);display:flex;align-items:center;justify-content:center;">' +
            '<i class="fas fa-wifi" style="font-size:24px;color:var(--danger);"></i></div>' +
            '<p style="color:var(--text-primary);font-weight:600;margin-bottom:6px;">Connection Error</p>' +
            '<p style="color:var(--text-muted);font-size:13px;margin-bottom:16px;">Check your internet connection and try again.</p>' +
            '<button class="btn btn-primary" onclick="generateReport()" style=""><i class="fas fa-sync"></i> Try Again</button>' +
            '</div></div>';
    });
}

function markdownToHtml(text) {
    if (!text) return '';

    // First handle markdown tables
    var lines = text.split('\n');
    var html = '';
    var inTable = false;
    var tableRows = [];

    for (var i = 0; i < lines.length; i++) {
        var line = lines[i].trim();

        // Detect table row (starts and ends with |)
        if (line.match(/^\|(.+)\|$/)) {
            // Skip separator rows (|---|---|)
            if (line.match(/^\|[\s\-:]+\|$/)) continue;

            var cells = line.split('|').filter(function(c, idx, arr) { return idx > 0 && idx < arr.length - 1; });
            cells = cells.map(function(c) { return c.trim(); });

            if (!inTable) {
                inTable = true;
                tableRows = [];
            }
            tableRows.push(cells);
        } else {
            if (inTable) {
                html += buildTable(tableRows);
                inTable = false;
                tableRows = [];
            }
            // Process non-table lines
            html += line + '\n';
        }
    }
    if (inTable) html += buildTable(tableRows);

    // Now process markdown formatting
    html = html
        .replace(/^### (.*$)/gm, '<h3>$1</h3>')
        .replace(/^## (.*$)/gm, '<h2>$1</h2>')
        .replace(/^# (.*$)/gm, '<h1>$1</h1>')
        .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
        .replace(/\*(.*?)\*/g, '<em>$1</em>')
        .replace(/^\- (.*$)/gm, '<li>$1</li>')
        .replace(/^\* (.*$)/gm, '<li>$1</li>')
        .replace(/^\d+\.\s+(.*$)/gm, '<li>$1</li>');

    html = html.replace(/(<li>.*?<\/li>\n?)+/g, '<ul>$&</ul>');
    html = html.replace(/\n\n/g, '</p><p>');
    html = html.replace(/\n/g, '<br>');

    return '<div>' + html + '</div>';
}

function buildTable(rows) {
    if (rows.length === 0) return '';
    var html = '<table class="ai-table"><thead><tr>';
    rows[0].forEach(function(h) { html += '<th>' + h + '</th>'; });
    html += '</tr></thead><tbody>';
    for (var i = 1; i < rows.length; i++) {
        html += '<tr>';
        rows[i].forEach(function(c) { html += '<td>' + c + '</td>'; });
        html += '</tr>';
    }
    html += '</tbody></table>';
    return html;
}

function escapeHtml(str) {
    if (!str) return '';
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(str));
    return div.innerHTML;
}

function printAIReport() {
    var body = document.getElementById('aiResponseBody');
    if (!body) { Swal.fire({ icon: 'info', text: 'Generate a report first.' }); return; }
    var activeTab = document.querySelector('.report-tab.active');
    var title = activeTab ? activeTab.textContent.trim() : 'AI Report';
    var company = '7503 Canada';
    var period = lastPeriod || '';

    var printWin = window.open('', '_blank', 'width=900,height=700');
    printWin.document.write('<!DOCTYPE html><html><head><meta charset="UTF-8"><title>' + title + '</title>');
    printWin.document.write('<style>');
    printWin.document.write('@page { size: A4; margin: 15mm 18mm; }');
    printWin.document.write('* { box-sizing: border-box; margin: 0; padding: 0; }');
    printWin.document.write('body { font-family: "Segoe UI", -apple-system, BlinkMacSystemFont, Roboto, sans-serif; color: #222; line-height: 1.6; font-size: 12px; padding: 0; }');
    // Print header
    printWin.document.write('.print-header { display: flex; justify-content: space-between; align-items: flex-end; border-bottom: 3px solid #001f3f; padding-bottom: 12px; margin-bottom: 20px; }');
    printWin.document.write('.print-header .company { font-size: 20px; font-weight: 800; color: #001f3f; }');
    printWin.document.write('.print-header .subtitle { font-size: 10px; color: #666; }');
    printWin.document.write('.print-header .report-info { text-align: right; }');
    printWin.document.write('.print-header .report-title { font-size: 16px; font-weight: 700; color: #001f3f; }');
    printWin.document.write('.print-header .report-period { font-size: 11px; color: #555; margin-top: 2px; }');
    // Content
    printWin.document.write('h1 { font-size: 18px; color: #001f3f; margin: 18px 0 8px; border-bottom: 1px solid #ccc; padding-bottom: 4px; }');
    printWin.document.write('h2 { font-size: 15px; color: #001f3f; margin: 16px 0 6px; }');
    printWin.document.write('h3 { font-size: 13px; color: #333; margin: 12px 0 4px; }');
    printWin.document.write('p { margin: 6px 0; }');
    printWin.document.write('strong { color: #001f3f; }');
    printWin.document.write('ul, ol { padding-left: 20px; margin: 6px 0; }');
    printWin.document.write('li { margin: 3px 0; }');
    // Tables — professional with borders
    printWin.document.write('table, .ai-table { width: 100%; border-collapse: collapse; margin: 10px 0 14px; font-size: 11px; }');
    printWin.document.write('table th, .ai-table th { background: #001f3f; color: #fff; padding: 7px 10px; text-align: left; font-weight: 600; font-size: 10px; text-transform: uppercase; letter-spacing: 0.3px; border: 1px solid #001f3f; }');
    printWin.document.write('table td, .ai-table td { padding: 6px 10px; border: 1px solid #ddd; color: #333; }');
    printWin.document.write('table tbody tr:nth-child(even), .ai-table tbody tr:nth-child(even) { background: #f8f9fa; }');
    printWin.document.write('table tbody tr:hover, .ai-table tbody tr:hover { background: #eef2f7; }');
    // Footer
    printWin.document.write('.print-footer { display: flex; justify-content: space-between; margin-top: 30px; padding-top: 10px; border-top: 2px solid #001f3f; font-size: 9px; color: #888; }');
    printWin.document.write('@media print { body { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }');
    printWin.document.write('</style></head><body>');
    // Header
    printWin.document.write('<div class="print-header">');
    printWin.document.write('<div><div class="company">' + company + '</div><div class="subtitle">AI-Powered Business Intelligence Report</div></div>');
    printWin.document.write('<div class="report-info"><div class="report-title">' + title + '</div><div class="report-period">' + period + '</div></div>');
    printWin.document.write('</div>');
    // Body
    printWin.document.write(body.innerHTML);
    // Footer
    printWin.document.write('<div class="print-footer">');
    printWin.document.write('<span>' + company + ' — Daloa, Cote d\'Ivoire</span>');
    printWin.document.write('<span>Generated: ' + new Date().toLocaleDateString('fr-FR') + ' ' + new Date().toLocaleTimeString('fr-FR', {hour:'2-digit',minute:'2-digit'}) + ' | AI Analysis</span>');
    printWin.document.write('</div>');
    printWin.document.write('</body></html>');
    printWin.document.close();
    printWin.onload = function() { printWin.focus(); printWin.print(); };
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
