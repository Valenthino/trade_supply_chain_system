<?php
/**
 * Developed by Rameez Scripts
 * WhatsApp: https://wa.me/923224083545 (For Custom Projects)
 * YouTube: https://www.youtube.com/@rameezimdad (Subscribe for more!)
 *
 * Shared Sidebar Component
 * Include this file in all dashboard pages
 *
 * Required variables before including:
 * - $username: Current logged-in user's full name
 * - $role: Current user role
 * - $current_page: Current page identifier (dashboard/users/logs/account/settings)
 * - $user_id: Current user ID
 */

// notification AJAX handler — runs before sidebar renders
if (isset($_GET['notif_action'])) {
    if (!function_exists('getDBConnection')) require_once __DIR__ . '/config.php';
    if (!isset($_SESSION['user_id'])) { echo json_encode(['success' => false, 'error' => 'Not logged in']); exit(); }
    header('Content-Type: application/json');
    $conn = getDBConnection();
    $uid = $_SESSION['user_id'];
    $urole = isset($_SESSION['role']) ? $_SESSION['role'] : '';

    switch ($_GET['notif_action']) {
        case 'getUnread':
            // auto-gen: expiring fleet docs (within 14 days)
            $expiryCheck = $conn->query("SELECT fp.paperwork_id, fp.expiry_date, fv.vehicle_registration, fv.driver_name, pt.paperwork_type_name
                FROM fleet_paperworks fp
                JOIN fleet_vehicles fv ON fp.vehicle_id = fv.vehicle_id
                JOIN settings_paperwork_types pt ON fp.paperwork_type_id = pt.paperwork_type_id
                WHERE fp.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 14 DAY)");
            if ($expiryCheck) {
                while ($doc = $expiryCheck->fetch_assoc()) {
                    $title = $doc['paperwork_type_name'] . ' expiring';
                    $msg = $doc['vehicle_registration'] . ' (' . $doc['driver_name'] . ') — expires ' . date('M d', strtotime($doc['expiry_date']));
                    // check dupe within 1 day
                    $chk = $conn->prepare("SELECT id FROM notifications WHERE title = ? AND message = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY) LIMIT 1");
                    $chk->bind_param("ss", $title, $msg);
                    $chk->execute();
                    if ($chk->get_result()->num_rows === 0) {
                        $ins = $conn->prepare("INSERT INTO notifications (title, message, type, link, role_target) VALUES (?, ?, 'warning', 'fleet.php', 'Manager')");
                        $ins->bind_param("ss", $title, $msg);
                        $ins->execute();
                        $ins->close();
                        $ins2 = $conn->prepare("INSERT INTO notifications (title, message, type, link, role_target) VALUES (?, ?, 'warning', 'fleet.php', 'Fleet Manager')");
                        $ins2->bind_param("ss", $title, $msg);
                        $ins2->execute();
                        $ins2->close();
                    }
                    $chk->close();
                }
            }

            // auto-gen: supplier birthdays today or within 3 days
            $bdayCheck = $conn->query("SELECT supplier_id, first_name, date_of_birth FROM suppliers WHERE status = 'Active' AND date_of_birth IS NOT NULL AND (MONTH(date_of_birth) = MONTH(CURDATE()) AND DAY(date_of_birth) BETWEEN DAY(CURDATE()) AND DAY(CURDATE()) + 3)");
            if ($bdayCheck) {
                while ($s = $bdayCheck->fetch_assoc()) {
                    $dob = $s['date_of_birth'];
                    $bday = date('M d', strtotime($dob));
                    $isToday = (date('m-d', strtotime($dob)) === date('m-d'));
                    $title = $isToday ? '🎂 ' . $s['first_name'] . "'s birthday!" : $s['first_name'] . "'s birthday on " . $bday;
                    $msg = 'Supplier ' . $s['first_name'] . ($isToday ? ' celebrates today!' : ' — birthday coming up');
                    $chk = $conn->prepare("SELECT id FROM notifications WHERE title = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY) LIMIT 1");
                    $chk->bind_param("s", $title);
                    $chk->execute();
                    if ($chk->get_result()->num_rows === 0) {
                        $ins = $conn->prepare("INSERT INTO notifications (title, message, type, link) VALUES (?, ?, 'info', 'suppliers.php')");
                        $ins->bind_param("ss", $title, $msg);
                        $ins->execute();
                        $ins->close();
                    }
                    $chk->close();
                }
            }

            // fetch unread
            $stmt = $conn->prepare("SELECT id, title, message, type, link, created_at FROM notifications WHERE (user_id = ? OR role_target = ? OR (user_id IS NULL AND role_target IS NULL)) AND is_read = 0 ORDER BY created_at DESC LIMIT 20");
            $stmt->bind_param("is", $uid, $urole);
            $stmt->execute();
            $notifs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            $conn->close();
            echo json_encode(['success' => true, 'data' => $notifs, 'count' => count($notifs)]);
            exit();

        case 'markRead':
            $nid = isset($_POST['id']) ? intval($_POST['id']) : 0;
            if ($nid > 0) {
                $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE id = ?");
                $stmt->bind_param("i", $nid);
                $stmt->execute();
                $stmt->close();
            }
            $conn->close();
            echo json_encode(['success' => true]);
            exit();

        case 'markAllRead':
            $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE (user_id = ? OR role_target = ? OR (user_id IS NULL AND role_target IS NULL)) AND is_read = 0");
            $stmt->bind_param("is", $uid, $urole);
            $stmt->execute();
            $stmt->close();
            $conn->close();
            echo json_encode(['success' => true]);
            exit();
    }
}

if (!isset($username) || !isset($role) || !isset($current_page) || !isset($user_id)) {
    die('Sidebar requires $username, $role, $current_page, and $user_id variables');
}

// Get user's profile image, fallback to default logo
$profile_image = getProfileImage($user_id);
$default_logo = "https://blogger.googleusercontent.com/img/b/R29vZ2xl/AVvXsEiGXxCe0WNNedmFqSWeF761f7Kshhc-NP5ChRQKz9fr97cO8VaarvD0KlCwqHojJVBWv-RAxfOqMI5rD4H78KnARyOc6QgwL1nRRFWf5xNQ1d9F9HfAoLPPGlTyP0GwNl4n-INMEsWLQ4Y7zJtz5bOdAnc2ePH9-uCRgshlo6BsS6gJEz6fhrxL-5U5O3sX/s160/channels4_profile.jpg";
$image_src = $profile_image ? $profile_image : $default_logo;

// Check if any submenu item is active (for My Account parent active state)
$account_submenu_active = in_array($current_page, ['account', 'system-settings', 'logs']);

// Check if any settings data page is active (for Settings parent active state)
$settings_data_pages = ['settings-locations', 'settings-contract-types', 'settings-supplier-types', 'settings-warehouses', 'settings-expense-categories', 'settings-bag-types', 'settings-paperwork-types', 'settings-seasons'];
$settings_submenu_active = in_array($current_page, $settings_data_pages);

// profit analysis submenu active
$profit_submenu_active = ($current_page === 'profit-analysis');

// Get active season for sidebar display
if (!isset($activeSeason)) {
    $activeSeason = getActiveSeason();
}

// Get user's custom theme
$user_theme = getUserTheme($user_id);

// Output custom theme CSS
echo generateUserThemeCSS($user_id);
?>

<!-- Sidebar -->
<aside id="sidebar" class="w-60 flex-shrink-0 bg-white dark:bg-slate-800 border-r border-slate-200 dark:border-slate-700 flex flex-col overflow-hidden z-40">

  <!-- Logo -->
  <div class="flex items-center gap-3 px-4 h-14 border-b border-slate-200 dark:border-slate-700 flex-shrink-0">
    <div class="w-8 h-8 rounded-lg bg-brand-500 flex items-center justify-center flex-shrink-0">
      <svg width="18" height="18" viewBox="0 0 28 28" fill="none">
        <path d="M6 20 C4 14, 8 6, 16 5 C22 4, 25 9, 23 15 C21 21, 14 24, 9 22" stroke="white" stroke-width="2" stroke-linecap="round"/>
        <circle cx="6" cy="20" r="2.5" fill="white"/>
      </svg>
    </div>
    <div class="logo-text flex flex-col min-w-0">
      <span class="text-sm font-bold text-slate-800 dark:text-white leading-tight whitespace-nowrap">Commodity Flow</span>
      <span class="text-[10px] font-semibold text-brand-500 uppercase tracking-wider leading-tight" id="sidebarSeason"><?php echo htmlspecialchars($activeSeason ?? 'No Season'); ?></span>
    </div>
    <button id="sidebarCollapseBtn" class="ml-auto text-slate-400 hover:text-slate-600 dark:hover:text-slate-300 w-6 h-6 flex items-center justify-center rounded flex-shrink-0" data-t="tooltip-collapse">
      <i class="fas fa-chevron-left text-xs transition-transform" id="collapseIcon"></i>
    </button>
  </div>

  <!-- User -->
  <div class="px-3 py-3 border-b border-slate-100 dark:border-slate-700 flex-shrink-0">
    <div class="flex items-center gap-3 bg-slate-50 dark:bg-slate-750 rounded-xl px-3 py-2">
      <div class="relative flex-shrink-0">
        <img src="<?php echo htmlspecialchars($image_src); ?>" class="w-8 h-8 rounded-full" alt="User" onerror="this.src='<?php echo $default_logo; ?>'"/>
        <span class="absolute bottom-0 right-0 w-2 h-2 bg-emerald-400 rounded-full border-2 border-white dark:border-slate-800"></span>
      </div>
      <div class="sidebar-label min-w-0">
        <p class="text-xs font-semibold text-slate-700 dark:text-slate-200 truncate" id="sidebarUsername"><?php echo htmlspecialchars($_SESSION['username'] ?? 'User'); ?></p>
        <p class="text-[10px] text-slate-400 truncate" id="sidebarRole"><?php echo htmlspecialchars($_SESSION['role'] ?? ''); ?></p>
      </div>
    </div>
  </div>

  <!-- Season Selector -->
  <div class="px-3 py-2 border-b border-slate-100 dark:border-slate-700 flex-shrink-0" id="seasonSelectorWrap">
    <select id="globalSeasonSelect" class="w-full text-xs bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-2 py-1.5 text-slate-700 dark:text-slate-300 font-medium focus:outline-none focus:ring-2 focus:ring-brand-400">
      <?php
        if (function_exists('getAllSeasons')) {
          $allSeasons = getAllSeasons();
          $currentActiveSeason = function_exists('getActiveSeason') ? getActiveSeason() : '';
          foreach ($allSeasons as $s):
            $sel = ($s['season_name'] === $currentActiveSeason) ? 'selected' : '';
            echo '<option value="' . htmlspecialchars($s['season_name']) . '" ' . $sel . '>' . htmlspecialchars($s['season_name']) . '</option>';
          endforeach;
        }
      ?>
    </select>
  </div>

  <!-- Navigation -->
  <nav class="flex-1 overflow-y-auto py-3 px-2 space-y-4">

    <div>
      <p class="sidebar-section-label sidebar-label text-[10px] uppercase tracking-widest font-semibold text-slate-400 dark:text-slate-500 px-3 mb-1" data-t="nav-section-ops">Operations</p>
      <ul class="space-y-0.5">
        <li><a href="dashboard.php" class="nav-link <?php echo $current_page === 'dashboard' ? 'active' : ''; ?> relative flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors">
          <i class="nav-icon fas fa-table-columns w-4 text-slate-400 dark:text-slate-500 text-sm"></i>
          <span class="sidebar-label whitespace-nowrap" data-t="nav-dashboard">Dashboard</span>
        </a></li>

        <?php if (in_array($role, ['Admin', 'Manager', 'Procurement Officer', 'Warehouse Clerk', 'Finance Officer'])): ?>
        <li><a href="purchases.php" class="nav-link <?php echo $current_page === 'purchases' ? 'active' : ''; ?> relative flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors">
          <i class="nav-icon fas fa-cart-shopping w-4 text-slate-400 dark:text-slate-500 text-sm"></i>
          <span class="sidebar-label whitespace-nowrap" data-t="nav-purchases">Purchases</span>
          <span class="sidebar-label ml-auto bg-brand-100 dark:bg-brand-900 text-brand-600 dark:text-brand-400 text-[10px] font-bold px-1.5 py-0.5 rounded-full" id="navBadgePurchases">—</span>
        </a></li>
        <?php endif; ?>

        <?php if (in_array($role, ['Admin', 'Manager', 'Sales Officer', 'Finance Officer'])): ?>
        <li><a href="sales.php" class="nav-link <?php echo $current_page === 'sales' ? 'active' : ''; ?> relative flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors">
          <i class="nav-icon fas fa-chart-line w-4 text-slate-400 dark:text-slate-500 text-sm"></i>
          <span class="sidebar-label whitespace-nowrap" data-t="nav-sales">Sales</span>
        </a></li>
        <?php endif; ?>

        <?php if (in_array($role, ['Admin', 'Manager', 'Sales Officer', 'Warehouse Clerk', 'Fleet Manager', 'Procurement Officer'])): ?>
        <li><a href="deliveries.php" class="nav-link <?php echo $current_page === 'deliveries' ? 'active' : ''; ?> relative flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors">
          <i class="nav-icon fas fa-truck-fast w-4 text-slate-400 dark:text-slate-500 text-sm"></i>
          <span class="sidebar-label whitespace-nowrap" data-t="nav-deliveries">Deliveries</span>
          <span class="sidebar-label ml-auto bg-amber-100 dark:bg-amber-900/40 text-amber-600 dark:text-amber-400 text-[10px] font-bold px-1.5 py-0.5 rounded-full" id="navBadgeDeliveries">—</span>
        </a></li>
        <?php endif; ?>

        <?php if (in_array($role, ['Admin', 'Manager', 'Finance Officer', 'Procurement Officer', 'Sales Officer'])): ?>
        <li><a href="payments.php" class="nav-link <?php echo $current_page === 'payments' ? 'active' : ''; ?> relative flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors">
          <i class="nav-icon fas fa-credit-card w-4 text-slate-400 dark:text-slate-500 text-sm"></i>
          <span class="sidebar-label whitespace-nowrap" data-t="nav-payments">Payments</span>
        </a></li>
        <?php endif; ?>
      </ul>
    </div>

    <div>
      <p class="sidebar-section-label sidebar-label text-[10px] uppercase tracking-widest font-semibold text-slate-400 dark:text-slate-500 px-3 mb-1" data-t="nav-section-master">Master Data</p>
      <ul class="space-y-0.5">
        <?php if (in_array($role, ['Admin', 'Manager', 'Procurement Officer', 'Finance Officer', 'Warehouse Clerk'])): ?>
        <li><a href="suppliers.php" class="nav-link <?php echo $current_page === 'suppliers' ? 'active' : ''; ?> relative flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors">
          <i class="nav-icon fas fa-people-group w-4 text-slate-400 dark:text-slate-500 text-sm"></i>
          <span class="sidebar-label whitespace-nowrap" data-t="nav-suppliers">Suppliers</span>
        </a></li>
        <?php endif; ?>

        <?php if (in_array($role, ['Admin', 'Manager', 'Finance Officer', 'Procurement Officer'])): ?>
        <li><a href="supplier-ranking.php" class="nav-link <?php echo $current_page === 'supplier-ranking' ? 'active' : ''; ?> relative flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors">
          <i class="nav-icon fas fa-ranking-star w-4 text-slate-400 dark:text-slate-500 text-sm"></i>
          <span class="sidebar-label whitespace-nowrap">Supplier Ranking</span>
        </a></li>
        <?php endif; ?>

        <?php if (in_array($role, ['Admin', 'Manager', 'Sales Officer', 'Finance Officer'])): ?>
        <li><a href="customers.php" class="nav-link <?php echo $current_page === 'customers' ? 'active' : ''; ?> relative flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors">
          <i class="nav-icon fas fa-users w-4 text-slate-400 dark:text-slate-500 text-sm"></i>
          <span class="sidebar-label whitespace-nowrap" data-t="nav-customers">Customers</span>
        </a></li>
        <?php endif; ?>

        <?php if (in_array($role, ['Admin', 'Manager', 'Sales Officer', 'Procurement Officer', 'Finance Officer'])): ?>
        <li><a href="pricing.php" class="nav-link <?php echo $current_page === 'pricing' ? 'active' : ''; ?> relative flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors">
          <i class="nav-icon fas fa-tag w-4 text-slate-400 dark:text-slate-500 text-sm"></i>
          <span class="sidebar-label whitespace-nowrap" data-t="nav-pricing">Price Grid</span>
        </a></li>
        <?php endif; ?>

        <?php if (in_array($role, ['Admin', 'Manager', 'Finance Officer'])): ?>
        <li><a href="banks.php" class="nav-link <?php echo $current_page === 'banks' ? 'active' : ''; ?> relative flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors">
          <i class="nav-icon fas fa-building-columns w-4 text-slate-400 dark:text-slate-500 text-sm"></i>
          <span class="sidebar-label whitespace-nowrap" data-t="nav-banks">Banks</span>
        </a></li>
        <?php endif; ?>
      </ul>
    </div>

    <div>
      <p class="sidebar-section-label sidebar-label text-[10px] uppercase tracking-widest font-semibold text-slate-400 dark:text-slate-500 px-3 mb-1" data-t="nav-section-finance">Finance</p>
      <ul class="space-y-0.5">
        <?php if (in_array($role, ['Admin', 'Manager', 'Finance Officer'])): ?>
        <li><a href="financing.php" class="nav-link <?php echo $current_page === 'financing' ? 'active' : ''; ?> relative flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors">
          <i class="nav-icon fas fa-layer-group w-4 text-slate-400 dark:text-slate-500 text-sm"></i>
          <span class="sidebar-label whitespace-nowrap" data-t="nav-financing">Financing</span>
        </a></li>
        <?php endif; ?>

        <?php if (in_array($role, ['Admin', 'Manager', 'Finance Officer', 'Procurement Officer', 'Warehouse Clerk'])): ?>
        <li><a href="expenses.php" class="nav-link <?php echo $current_page === 'expenses' ? 'active' : ''; ?> relative flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors">
          <i class="nav-icon fas fa-circle-dollar-to-slot w-4 text-slate-400 dark:text-slate-500 text-sm"></i>
          <span class="sidebar-label whitespace-nowrap" data-t="nav-expenses">Expenses</span>
        </a></li>
        <?php endif; ?>

        <?php if (in_array($role, ['Admin', 'Manager', 'Finance Officer'])): ?>
        <li><a href="profit-analysis.php" class="nav-link <?php echo $current_page === 'profit-analysis' ? 'active' : ''; ?> relative flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors">
          <i class="nav-icon fas fa-chart-pie w-4 text-slate-400 dark:text-slate-500 text-sm"></i>
          <span class="sidebar-label whitespace-nowrap" data-t="nav-pl">P&L Analysis</span>
        </a></li>
        <?php endif; ?>
      </ul>
    </div>

    <?php if (in_array($role, ['Admin', 'Manager', 'Finance Officer', 'Sales Officer'])): ?>
    <div>
      <p class="sidebar-section-label sidebar-label text-[10px] uppercase tracking-widest font-semibold text-slate-400 dark:text-slate-500 px-3 mb-1" data-t="nav-section-ai">AI & Analytics</p>
      <ul class="space-y-0.5">
        <li><a href="ai-reports.php" class="nav-link <?php echo $current_page === 'ai-reports' ? 'active' : ''; ?> relative flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors">
          <i class="nav-icon fas fa-brain w-4 text-slate-400 dark:text-slate-500 text-sm"></i>
          <span class="sidebar-label whitespace-nowrap" data-t="nav-ai">AI Reports</span>
          <span class="sidebar-label ml-auto bg-violet-100 dark:bg-violet-900/30 text-violet-600 dark:text-violet-400 text-[10px] font-bold px-1.5 py-0.5 rounded-full" data-t="badge-new">New</span>
        </a></li>
      </ul>
    </div>
    <?php endif; ?>

    <div>
      <p class="sidebar-section-label sidebar-label text-[10px] uppercase tracking-widest font-semibold text-slate-400 dark:text-slate-500 px-3 mb-1" data-t="nav-section-logistics">Logistics</p>
      <ul class="space-y-0.5">
        <?php if (in_array($role, ['Admin', 'Manager', 'Fleet Manager', 'Procurement Officer'])): ?>
        <li><a href="fleet.php" class="nav-link <?php echo $current_page === 'fleet' ? 'active' : ''; ?> relative flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors">
          <i class="nav-icon fas fa-truck w-4 text-slate-400 dark:text-slate-500 text-sm"></i>
          <span class="sidebar-label whitespace-nowrap" data-t="nav-fleet">Fleet & Drivers</span>
        </a></li>
        <?php endif; ?>

        <?php if (in_array($role, ['Admin', 'Manager', 'Warehouse Clerk', 'Fleet Manager', 'Procurement Officer'])): ?>
        <li><a href="bags-log.php" class="nav-link <?php echo $current_page === 'bags-log' ? 'active' : ''; ?> relative flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors">
          <i class="nav-icon fas fa-boxes-packing w-4 text-slate-400 dark:text-slate-500 text-sm"></i>
          <span class="sidebar-label whitespace-nowrap" data-t="nav-bags">Bag Journal</span>
        </a></li>
        <?php endif; ?>

        <?php if (in_array($role, ['Admin', 'Manager', 'Warehouse Clerk', 'Procurement Officer'])): ?>
        <li><a href="inventory.php" class="nav-link <?php echo $current_page === 'inventory' ? 'active' : ''; ?> relative flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors">
          <i class="nav-icon fas fa-boxes-stacked w-4 text-slate-400 dark:text-slate-500 text-sm"></i>
          <span class="sidebar-label whitespace-nowrap" data-t="nav-inventory">Inventory</span>
        </a></li>
        <?php endif; ?>
      </ul>
    </div>

    <div>
      <p class="sidebar-section-label sidebar-label text-[10px] uppercase tracking-widest font-semibold text-slate-400 dark:text-slate-500 px-3 mb-1" data-t="nav-section-admin">Administration</p>
      <ul class="space-y-0.5">
        <?php if ($role === 'Admin'): ?>
        <li><a href="users.php" class="nav-link <?php echo $current_page === 'users' ? 'active' : ''; ?> relative flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors">
          <i class="nav-icon fas fa-shield-halved w-4 text-slate-400 dark:text-slate-500 text-sm"></i>
          <span class="sidebar-label whitespace-nowrap" data-t="nav-users">Users</span>
        </a></li>
        <?php endif; ?>

        <?php if (in_array($role, ['Admin', 'Manager'])): ?>
        <li><a href="settings.php" class="nav-link <?php echo $current_page === 'system-settings' ? 'active' : ''; ?> relative flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors">
          <i class="nav-icon fas fa-gear w-4 text-slate-400 dark:text-slate-500 text-sm"></i>
          <span class="sidebar-label whitespace-nowrap" data-t="nav-settings">Settings</span>
        </a></li>
        <?php endif; ?>

        <li><a href="account.php" class="nav-link <?php echo $current_page === 'account' ? 'active' : ''; ?> relative flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors">
          <i class="nav-icon fas fa-user-circle w-4 text-slate-400 dark:text-slate-500 text-sm"></i>
          <span class="sidebar-label whitespace-nowrap">My Profile</span>
        </a></li>

        <li><a href="logs.php" class="nav-link <?php echo $current_page === 'logs' ? 'active' : ''; ?> relative flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors">
          <i class="nav-icon fas fa-history w-4 text-slate-400 dark:text-slate-500 text-sm"></i>
          <span class="sidebar-label whitespace-nowrap">Activity Logs</span>
        </a></li>
      </ul>
    </div>

  </nav>

  <!-- Sidebar footer -->
  <div class="border-t border-slate-200 dark:border-slate-700 p-3 flex-shrink-0 space-y-0.5">
    <!-- Language toggle -->
    <button id="langToggleBtn" class="w-full flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium text-slate-500 dark:text-slate-400 hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors text-left">
      <i class="fas fa-globe w-4 text-sm"></i>
      <span class="sidebar-label whitespace-nowrap" id="langLabel">Fran&ccedil;ais</span>
    </button>
    <!-- Theme toggle -->
    <button id="themeToggleBtn" class="w-full flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium text-slate-500 dark:text-slate-400 hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors text-left">
      <i class="fas fa-moon w-4 text-sm" id="themeIcon"></i>
      <span class="sidebar-label whitespace-nowrap" id="themeLabel">Dark Mode</span>
    </button>
    <a href="logout.php" class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium text-slate-500 dark:text-slate-400 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-900/20 dark:hover:text-red-400 transition-colors">
      <i class="fas fa-right-from-bracket w-4 text-sm"></i>
      <span class="sidebar-label whitespace-nowrap" data-t="nav-logout">Sign Out</span>
    </a>
  </div>
</aside>

<!-- Notification Panel -->
<div id="notifBellWrap" style="display:none;position:relative;margin-right:16px;">
    <div id="notifBell" style="cursor:pointer;" onclick="toggleNotifPanel()">
        <i class="fas fa-bell" style="font-size:18px;"></i>
        <span id="notifBadge" style="display:none;position:absolute;top:-6px;right:-8px;background:#e74c3c;color:#fff;border-radius:50%;width:18px;height:18px;font-size:10px;font-weight:700;text-align:center;line-height:18px;">0</span>
    </div>
    <div id="notifPanel" style="display:none;position:absolute;top:calc(100% + 8px);right:-10px;width:360px;max-height:400px;background:var(--bg-card,#fff);border:1px solid rgba(0,0,0,0.1);border-radius:8px;box-shadow:0 8px 32px rgba(0,0,0,0.15);z-index:9999;overflow:hidden;">
        <div style="padding:12px 16px;border-bottom:1px solid rgba(0,0,0,0.08);display:flex;justify-content:space-between;align-items:center;">
            <strong style="font-size:14px;">Notifications</strong>
            <a href="#" onclick="markAllRead();return false;" style="font-size:12px;color:#2d9d99;text-decoration:none;">Mark all read</a>
        </div>
        <div id="notifList" style="overflow-y:auto;max-height:340px;"></div>
    </div>
</div>

<!-- Money input auto-formatter -->
<script>
(function() {
    function fmtMoney(raw) {
        raw = raw.replace(/[^0-9.]/g, '');
        var parts = raw.split('.');
        if (parts.length > 2) parts = [parts[0], parts.slice(1).join('')];
        parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        return parts.join('.');
    }
    document.addEventListener('input', function(e) {
        if (!e.target.classList.contains('money-input')) return;
        var el = e.target;
        var cur = el.selectionStart;
        var oldLen = el.value.length;
        var raw = el.value.replace(/,/g, '');
        if (raw === '' || raw === '-') return;
        var formatted = fmtMoney(raw);
        el.value = formatted;
        var newLen = el.value.length;
        el.setSelectionRange(cur + (newLen - oldLen), cur + (newLen - oldLen));
    });
    document.addEventListener('submit', function(e) {
        if (!e.target || e.target.tagName !== 'FORM') return;
        e.target.querySelectorAll('.money-input').forEach(function(el) {
            el.value = el.value.replace(/,/g, '');
        });
    }, true);
    window.moneyVal = function(id) {
        var el = document.getElementById(id);
        return el ? parseFloat(el.value.replace(/,/g, '')) || 0 : 0;
    };
    window.setMoneyVal = function(id, val) {
        var el = document.getElementById(id);
        if (!el) return;
        if (val === '' || val === null || val === undefined) { el.value = ''; return; }
        el.value = fmtMoney(String(val));
    };
})();
</script>

<!-- Notification System -->
<script>
(function() {
    function loadNotifications() {
        $.getJSON('sidebar.php?notif_action=getUnread', function(res) {
            if (!res.success) return;
            var badge = document.getElementById('notifBadge');
            var list = document.getElementById('notifList');
            if (!badge || !list) return;

            if (res.count > 0) {
                badge.style.display = 'block';
                badge.textContent = res.count > 9 ? '9+' : res.count;
            } else {
                badge.style.display = 'none';
            }

            if (res.data.length === 0) {
                list.innerHTML = '<div style="padding:30px;text-align:center;color:#94a3b8;"><i class="fas fa-check-circle" style="font-size:24px;"></i><p style="margin-top:8px;">All caught up!</p></div>';
                return;
            }

            var html = '';
            res.data.forEach(function(n) {
                var iconMap = {info:'fa-info-circle', warning:'fa-exclamation-triangle', danger:'fa-times-circle', success:'fa-check-circle'};
                var colorMap = {info:'#0074D9', warning:'#f39c12', danger:'#e74c3c', success:'#27ae60'};
                var icon = iconMap[n.type] || 'fa-bell';
                var color = colorMap[n.type] || '#666';
                var ago = _notifTimeAgo(n.created_at);

                html += '<div class="notif-item" style="padding:10px 16px;border-bottom:1px solid rgba(0,0,0,0.06);cursor:pointer;display:flex;gap:10px;align-items:flex-start;" onclick="openNotif(' + n.id + ', \'' + (n.link || '') + '\')">';
                html += '<i class="fas ' + icon + '" style="color:' + color + ';margin-top:3px;"></i>';
                html += '<div style="flex:1;min-width:0;"><div style="font-size:13px;font-weight:600;">' + _escHtml(n.title) + '</div>';
                html += '<div style="font-size:12px;color:#94a3b8;margin-top:2px;">' + _escHtml(n.message) + '</div>';
                html += '<div style="font-size:11px;color:#94a3b8;margin-top:4px;">' + ago + '</div></div></div>';
            });
            list.innerHTML = html;
        });
    }

    function _escHtml(s) {
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    function _notifTimeAgo(dateStr) {
        var d = new Date(dateStr);
        var now = new Date();
        var diff = Math.floor((now - d) / 1000);
        if (diff < 60) return 'Just now';
        if (diff < 3600) return Math.floor(diff/60) + 'm ago';
        if (diff < 86400) return Math.floor(diff/3600) + 'h ago';
        return Math.floor(diff/86400) + 'd ago';
    }

    window.toggleNotifPanel = function() {
        var panel = document.getElementById('notifPanel');
        if (!panel) return;
        panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
        if (panel.style.display === 'block') loadNotifications();
    };

    window.openNotif = function(id, link) {
        $.post('sidebar.php?notif_action=markRead', {id: id});
        document.getElementById('notifPanel').style.display = 'none';
        if (link) window.location.href = link;
        else loadNotifications();
    };

    window.markAllRead = function() {
        $.post('sidebar.php?notif_action=markAllRead', {}, function() { loadNotifications(); });
    };

    document.addEventListener('click', function(e) {
        var bell = document.getElementById('notifBell');
        var panel = document.getElementById('notifPanel');
        if (bell && panel && !bell.contains(e.target) && !panel.contains(e.target)) {
            panel.style.display = 'none';
        }
    });

    setInterval(function() {
        $.getJSON('sidebar.php?notif_action=getUnread', function(res) {
            if (!res.success) return;
            var badge = document.getElementById('notifBadge');
            if (!badge) return;
            if (res.count > 0) { badge.style.display = 'block'; badge.textContent = res.count > 9 ? '9+' : res.count; }
            else badge.style.display = 'none';
        });
    }, 60000);

    $(document).ready(function() {
        setTimeout(loadNotifications, 1000);
    });
})();
</script>

<!-- Season Selector JS -->
<script>
document.addEventListener('DOMContentLoaded', function() {
  var sel = document.getElementById('globalSeasonSelect');
  if (sel) {
    var url = new URL(window.location.href);
    var sp = url.searchParams.get('selected_season') || sessionStorage.getItem('selectedSeason');
    if (sp) { sel.value = sp; sessionStorage.setItem('selectedSeason', sp); }
    sel.addEventListener('change', function() {
      sessionStorage.setItem('selectedSeason', this.value);
      var u = new URL(window.location.href);
      u.searchParams.set('selected_season', this.value);
      window.location.href = u.toString();
    });
  }
});
</script>
