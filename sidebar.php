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

// Get user's custom theme
$user_theme = getUserTheme($user_id);

// Output custom theme CSS
echo generateUserThemeCSS($user_id);
?>
<!-- Sidebar -->
<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="sidebar-title">
            <i class="fas fa-tachometer-alt"></i>
            <span class="sidebar-title-text">Dashboard</span>
        </div>
        <button class="sidebar-toggle-btn" onclick="toggleSidebar()" title="Toggle Sidebar">
            <i class="fas fa-chevron-left" id="sidebarToggleIcon"></i>
        </button>
    </div>
    <div class="sidebar-logo-section">
        <img src="<?php echo htmlspecialchars($image_src); ?>" alt="Profile" class="sidebar-logo" onerror="this.src='<?php echo $default_logo; ?>'">
    </div>
    <div class="sidebar-menu-section">
        <div class="sidebar-menu-title" data-section="operations" onclick="toggleSidebarSection(this)"><span>Operations</span><i class="fas fa-chevron-down section-chevron"></i></div>
        <ul class="sidebar-menu">
            <!-- Dashboard - visible to all roles -->
            <li data-tooltip="Dashboard">
                <a href="dashboard.php" class="<?php echo $current_page === 'dashboard' ? 'active' : ''; ?>">
                    <i class="fas fa-chart-line"></i>
                    <span>Dashboard</span>
                </a>
            </li>

            <?php if (in_array($role, ['Admin', 'Manager', 'Procurement Officer', 'Warehouse Clerk', 'Finance Officer'])): ?>
            <!-- Purchases -->
            <li data-tooltip="Purchases">
                <a href="purchases.php" class="<?php echo $current_page === 'purchases' ? 'active' : ''; ?>">
                    <i class="fas fa-cart-shopping"></i>
                    <span>Purchases</span>
                </a>
            </li>
            <?php endif; ?>

            <?php if (in_array($role, ['Admin', 'Manager', 'Sales Officer', 'Finance Officer'])): ?>
            <!-- Sales -->
            <li data-tooltip="Sales">
                <a href="sales.php" class="<?php echo $current_page === 'sales' ? 'active' : ''; ?>">
                    <i class="fas fa-coins"></i>
                    <span>Sales</span>
                </a>
            </li>
            <?php endif; ?>

            <?php if (in_array($role, ['Admin', 'Manager', 'Sales Officer', 'Warehouse Clerk', 'Fleet Manager', 'Procurement Officer'])): ?>
            <!-- Deliveries Out -->
            <li data-tooltip="Deliveries">
                <a href="deliveries.php" class="<?php echo $current_page === 'deliveries' ? 'active' : ''; ?>">
                    <i class="fas fa-truck-fast"></i>
                    <span>Deliveries Out</span>
                </a>
            </li>
            <?php endif; ?>

            <?php if (in_array($role, ['Admin', 'Manager', 'Finance Officer', 'Procurement Officer', 'Sales Officer'])): ?>
            <!-- Payments -->
            <li data-tooltip="Payments">
                <a href="payments.php" class="<?php echo $current_page === 'payments' ? 'active' : ''; ?>">
                    <i class="fas fa-credit-card"></i>
                    <span>Payments</span>
                </a>
            </li>
            <?php endif; ?>
        </ul>

        <div class="sidebar-menu-title" data-section="master-data" onclick="toggleSidebarSection(this)"><span>Master Data</span><i class="fas fa-chevron-down section-chevron"></i></div>
        <ul class="sidebar-menu">
            <?php if (in_array($role, ['Admin', 'Manager', 'Procurement Officer', 'Finance Officer', 'Warehouse Clerk'])): ?>
            <!-- Supplier Master -->
            <li data-tooltip="Suppliers">
                <a href="suppliers.php" class="<?php echo $current_page === 'suppliers' ? 'active' : ''; ?>">
                    <i class="fas fa-truck-field"></i>
                    <span>Supplier Master</span>
                </a>
            </li>
            <?php endif; ?>

            <?php if (in_array($role, ['Admin', 'Manager', 'Finance Officer', 'Procurement Officer'])): ?>
            <!-- Supplier Ranking — score-based shortlist for financing decisions -->
            <li data-tooltip="Supplier Ranking">
                <a href="supplier-ranking.php" class="<?php echo $current_page === 'supplier-ranking' ? 'active' : ''; ?>">
                    <i class="fas fa-ranking-star"></i>
                    <span>Supplier Ranking</span>
                </a>
            </li>
            <?php endif; ?>

            <?php if (in_array($role, ['Admin', 'Manager', 'Sales Officer', 'Finance Officer'])): ?>
            <!-- Customer Master -->
            <li data-tooltip="Customers">
                <a href="customers.php" class="<?php echo $current_page === 'customers' ? 'active' : ''; ?>">
                    <i class="fas fa-handshake"></i>
                    <span>Customer Master</span>
                </a>
            </li>
            <?php endif; ?>

            <?php if (in_array($role, ['Admin', 'Manager', 'Sales Officer', 'Procurement Officer', 'Finance Officer'])): ?>
            <!-- Pricing Master -->
            <li data-tooltip="Pricing">
                <a href="pricing.php" class="<?php echo $current_page === 'pricing' ? 'active' : ''; ?>">
                    <i class="fas fa-tags"></i>
                    <span>Pricing Master</span>
                </a>
            </li>
            <?php endif; ?>

            <?php if (in_array($role, ['Admin', 'Manager', 'Finance Officer'])): ?>
            <!-- Bank Master — restricted: bank debts are finance-only -->
            <li data-tooltip="Banks">
                <a href="banks.php" class="<?php echo $current_page === 'banks' ? 'active' : ''; ?>">
                    <i class="fas fa-building-columns"></i>
                    <span>Bank Master</span>
                </a>
            </li>
            <?php endif; ?>
        </ul>

        <div class="sidebar-menu-title" data-section="finance" onclick="toggleSidebarSection(this)"><span>Finance</span><i class="fas fa-chevron-down section-chevron"></i></div>
        <ul class="sidebar-menu">
            <?php if (in_array($role, ['Admin', 'Manager', 'Finance Officer'])): ?>
            <!-- Financing — restricted: bank debts + supplier advances are finance-only -->
            <li data-tooltip="Financing">
                <a href="financing.php" class="<?php echo $current_page === 'financing' ? 'active' : ''; ?>">
                    <i class="fas fa-money-bill-transfer"></i>
                    <span>Financing</span>
                </a>
            </li>
            <?php endif; ?>

            <?php if (in_array($role, ['Admin', 'Manager', 'Finance Officer', 'Procurement Officer', 'Warehouse Clerk'])): ?>
            <!-- Expenses -->
            <li data-tooltip="Expenses">
                <a href="expenses.php" class="<?php echo $current_page === 'expenses' ? 'active' : ''; ?>">
                    <i class="fas fa-file-invoice-dollar"></i>
                    <span>Expenses</span>
                </a>
            </li>
            <?php endif; ?>

            <?php if (in_array($role, ['Admin', 'Manager', 'Finance Officer'])): ?>
            <!-- Profit Analysis (submenu) — restricted: profit visibility is finance-only -->
            <li class="has-submenu" data-tooltip="Profit Analysis">
                <a href="#" class="submenu-toggle <?php echo $profit_submenu_active ? 'active' : ''; ?>" data-submenu="profit-submenu">
                    <i class="fas fa-chart-pie"></i>
                    <span>Profit Analysis</span>
                    <i class="fas fa-chevron-down submenu-arrow"></i>
                </a>
                <ul class="sidebar-submenu" id="profit-submenu">
                    <li data-tooltip="Profit & Loss">
                        <a href="profit-analysis.php?tab=pnl" class="<?php echo ($current_page === 'profit-analysis' && isset($_GET['tab']) && $_GET['tab'] === 'pnl') ? 'active' : ''; ?>">
                            <i class="fas fa-file-invoice-dollar"></i>
                            <span>Profit & Loss</span>
                        </a>
                    </li>
                    <li data-tooltip="Cash Flow">
                        <a href="profit-analysis.php?tab=cashflow" class="<?php echo ($current_page === 'profit-analysis' && isset($_GET['tab']) && $_GET['tab'] === 'cashflow') ? 'active' : ''; ?>">
                            <i class="fas fa-money-bill-wave"></i>
                            <span>Cash Flow</span>
                        </a>
                    </li>
                    <li data-tooltip="Simulation">
                        <a href="profit-analysis.php?tab=simulation" class="<?php echo ($current_page === 'profit-analysis' && isset($_GET['tab']) && $_GET['tab'] === 'simulation') ? 'active' : ''; ?>">
                            <i class="fas fa-flask"></i>
                            <span>Simulation</span>
                        </a>
                    </li>
                </ul>
            </li>
            <?php endif; ?>
        </ul>

        <?php if (in_array($role, ['Admin', 'Manager', 'Finance Officer', 'Sales Officer'])): ?>
        <div class="sidebar-menu-title" data-section="ai-analytics" onclick="toggleSidebarSection(this)"><span>AI Analytics</span><i class="fas fa-chevron-down section-chevron"></i></div>
        <ul class="sidebar-menu">
            <li data-tooltip="AI Reports">
                <a href="ai-reports.php" class="<?php echo $current_page === 'ai-reports' ? 'active' : ''; ?>">
                    <i class="fas fa-brain"></i>
                    <span>AI Reports</span>
                </a>
            </li>
        </ul>
        <?php endif; ?>

        <div class="sidebar-menu-title" data-section="logistics" onclick="toggleSidebarSection(this)"><span>Logistics</span><i class="fas fa-chevron-down section-chevron"></i></div>
        <ul class="sidebar-menu">
            <?php if (in_array($role, ['Admin', 'Manager', 'Fleet Manager', 'Procurement Officer'])): ?>
            <!-- Fleet & Drivers -->
            <li data-tooltip="Fleet">
                <a href="fleet.php" class="<?php echo $current_page === 'fleet' ? 'active' : ''; ?>">
                    <i class="fas fa-truck-moving"></i>
                    <span>Fleet & Drivers</span>
                </a>
            </li>
            <?php endif; ?>

            <?php if (in_array($role, ['Admin', 'Manager', 'Warehouse Clerk', 'Fleet Manager', 'Procurement Officer'])): ?>
            <!-- Bags Log -->
            <li data-tooltip="Bags Log">
                <a href="bags-log.php" class="<?php echo $current_page === 'bags-log' ? 'active' : ''; ?>">
                    <i class="fas fa-boxes-packing"></i>
                    <span>Bags Log</span>
                </a>
            </li>
            <?php endif; ?>

            <?php if (in_array($role, ['Admin', 'Manager', 'Warehouse Clerk', 'Procurement Officer'])): ?>
            <!-- Inventory / Stock Ledger -->
            <li data-tooltip="Inventory">
                <a href="inventory.php" class="<?php echo $current_page === 'inventory' ? 'active' : ''; ?>">
                    <i class="fas fa-boxes-stacked"></i>
                    <span>Inventory</span>
                </a>
            </li>
            <?php endif; ?>
        </ul>

        <div class="sidebar-menu-title" data-section="administration" onclick="toggleSidebarSection(this)"><span>Administration</span><i class="fas fa-chevron-down section-chevron"></i></div>
        <ul class="sidebar-menu">
            <?php if ($role === 'Admin'): ?>
            <!-- Users - Admin only -->
            <li data-tooltip="Users">
                <a href="users.php" class="<?php echo $current_page === 'users' ? 'active' : ''; ?>">
                    <i class="fas fa-users-cog"></i>
                    <span>User Management</span>
                </a>
            </li>
            <?php endif; ?>

            <?php if (in_array($role, ['Admin', 'Manager'])): ?>
            <!-- Settings Data (Parent with Submenu) - Admin + Manager -->
            <li class="has-submenu" data-tooltip="Settings">
                <a href="#" class="submenu-toggle <?php echo $settings_submenu_active ? 'active' : ''; ?>" data-submenu="settings-data-submenu">
                    <i class="fas fa-cog"></i>
                    <span>Settings</span>
                    <i class="fas fa-chevron-down submenu-arrow"></i>
                </a>
                <ul class="sidebar-submenu" id="settings-data-submenu">
                    <li data-tooltip="Locations">
                        <a href="settings-data.php?type=locations" class="<?php echo $current_page === 'settings-locations' ? 'active' : ''; ?>">
                            <i class="fas fa-map-marker-alt"></i>
                            <span>Locations</span>
                        </a>
                    </li>
                    <li data-tooltip="Contract Types">
                        <a href="settings-data.php?type=contract-types" class="<?php echo $current_page === 'settings-contract-types' ? 'active' : ''; ?>">
                            <i class="fas fa-file-contract"></i>
                            <span>Contract Types</span>
                        </a>
                    </li>
                    <li data-tooltip="Supplier Types">
                        <a href="settings-data.php?type=supplier-types" class="<?php echo $current_page === 'settings-supplier-types' ? 'active' : ''; ?>">
                            <i class="fas fa-truck"></i>
                            <span>Supplier Types</span>
                        </a>
                    </li>
                    <li data-tooltip="Warehouses">
                        <a href="settings-data.php?type=warehouses" class="<?php echo $current_page === 'settings-warehouses' ? 'active' : ''; ?>">
                            <i class="fas fa-warehouse"></i>
                            <span>Warehouses</span>
                        </a>
                    </li>
                    <li data-tooltip="Expense Categories">
                        <a href="settings-data.php?type=expense-categories" class="<?php echo $current_page === 'settings-expense-categories' ? 'active' : ''; ?>">
                            <i class="fas fa-receipt"></i>
                            <span>Expense Categories</span>
                        </a>
                    </li>
                    <li data-tooltip="Bag Types">
                        <a href="settings-data.php?type=bag-types" class="<?php echo $current_page === 'settings-bag-types' ? 'active' : ''; ?>">
                            <i class="fas fa-box"></i>
                            <span>Bag Types</span>
                        </a>
                    </li>
                    <li data-tooltip="Paperwork Types">
                        <a href="settings-data.php?type=paperwork-types" class="<?php echo $current_page === 'settings-paperwork-types' ? 'active' : ''; ?>">
                            <i class="fas fa-scroll"></i>
                            <span>Paperwork Types</span>
                        </a>
                    </li>
                    <li data-tooltip="Seasons">
                        <a href="settings-data.php?type=seasons" class="<?php echo $current_page === 'settings-seasons' ? 'active' : ''; ?>">
                            <i class="fas fa-calendar-alt"></i>
                            <span>Seasons</span>
                        </a>
                    </li>
                </ul>
            </li>
            <?php endif; ?>

            <!-- My Account (Parent with Submenu) -->
            <li class="has-submenu" data-tooltip="My Account">
                <a href="#" class="submenu-toggle <?php echo $account_submenu_active ? 'active' : ''; ?>" data-submenu="account-submenu">
                    <i class="fas fa-user-circle"></i>
                    <span>My Account</span>
                    <i class="fas fa-chevron-down submenu-arrow"></i>
                </a>
                <ul class="sidebar-submenu" id="account-submenu">
                    <li data-tooltip="My Profile">
                        <a href="account.php" class="<?php echo $current_page === 'account' ? 'active' : ''; ?>">
                            <i class="fas fa-user"></i>
                            <span>My Profile</span>
                        </a>
                    </li>
                    <li data-tooltip="System">
                        <a href="settings.php" class="<?php echo $current_page === 'system-settings' ? 'active' : ''; ?>">
                            <i class="fas fa-server"></i>
                            <span>System</span>
                        </a>
                    </li>
                    <li data-tooltip="Activity Logs">
                        <a href="logs.php" class="<?php echo $current_page === 'logs' ? 'active' : ''; ?>">
                            <i class="fas fa-history"></i>
                            <span>Activity Logs</span>
                        </a>
                    </li>
                </ul>
            </li>
        </ul>
    </div>
    <div class="sidebar-theme">
        <button onclick="toggleTheme()">
            <i class="fas fa-moon" id="themeIcon"></i>
            <span id="themeText">Dark Mode</span>
        </button>
    </div>
    <div class="sidebar-logout">
        <button onclick="window.location.href='logout.php'">
            <i class="fas fa-sign-out-alt"></i>
            <span>Logout</span>
        </button>
    </div>
</div>

<!-- Notification Panel (positioned via JS into .header) -->
<div id="notifBellWrap" style="display:none;position:relative;margin-right:16px;">
    <div id="notifBell" style="cursor:pointer;" onclick="toggleNotifPanel()">
        <i class="fas fa-bell" style="font-size:18px;color:var(--text-muted);"></i>
        <span id="notifBadge" style="display:none;position:absolute;top:-6px;right:-8px;background:#e74c3c;color:#fff;border-radius:50%;width:18px;height:18px;font-size:10px;font-weight:700;text-align:center;line-height:18px;">0</span>
    </div>
    <div id="notifPanel" style="display:none;position:absolute;top:calc(100% + 8px);right:-10px;width:360px;max-height:400px;background:var(--bg-card);border:1px solid var(--border-color);border-radius:8px;box-shadow:0 8px 32px rgba(0,0,0,0.15);z-index:9999;overflow:hidden;">
        <div style="padding:12px 16px;border-bottom:1px solid var(--border-color);display:flex;justify-content:space-between;align-items:center;">
            <strong style="font-size:14px;color:var(--text-primary);">Notifications</strong>
            <a href="#" onclick="markAllRead();return false;" style="font-size:12px;color:var(--navy-accent);text-decoration:none;">Mark all read</a>
        </div>
        <div id="notifList" style="overflow-y:auto;max-height:340px;"></div>
    </div>
</div>

<style>
.notif-item:hover { background: var(--bg-secondary, #f8f9fa); }
@media (max-width: 480px) {
    #notifPanel { width: 300px !important; right: -40px !important; }
}
</style>

<!-- Theme Toggle JavaScript -->
<script>
/**
 * Theme Toggle Functionality
 * Handles light/dark mode switching with localStorage persistence
 * Also respects user's saved theme preference from database
 */
function initTheme() {
    const savedTheme = localStorage.getItem('theme');
    const userThemeMode = '<?php echo $user_theme['theme_mode']; ?>';
    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

    // Priority: localStorage > database setting > system preference
    let isDark = false;
    if (savedTheme) {
        isDark = savedTheme === 'dark';
    } else if (userThemeMode) {
        isDark = userThemeMode === 'dark';
        // Save to localStorage so it persists
        localStorage.setItem('theme', userThemeMode);
    } else {
        isDark = prefersDark;
    }

    if (isDark) {
        document.body.classList.add('dark-mode');
        updateThemeButton(true);
    } else {
        document.body.classList.remove('dark-mode');
        updateThemeButton(false);
    }
}

function toggleTheme() {
    const isDark = document.body.classList.toggle('dark-mode');
    localStorage.setItem('theme', isDark ? 'dark' : 'light');
    updateThemeButton(isDark);
}

function updateThemeButton(isDark) {
    const icon = document.getElementById('themeIcon');
    const text = document.getElementById('themeText');

    if (icon && text) {
        if (isDark) {
            icon.className = 'fas fa-sun';
            text.textContent = 'Light Mode';
        } else {
            icon.className = 'fas fa-moon';
            text.textContent = 'Dark Mode';
        }
    }
}

// Initialize theme on page load
initTheme();

/**
 * Sidebar Collapse Functionality
 * Handles sidebar expand/collapse with localStorage persistence
 */
function initSidebar() {
    const savedState = localStorage.getItem('sidebarCollapsed');
    if (savedState === 'true') {
        document.getElementById('sidebar').classList.add('collapsed');
        updateSidebarIcon(true);
    }
}

function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const isCollapsed = sidebar.classList.toggle('collapsed');
    localStorage.setItem('sidebarCollapsed', isCollapsed);
    updateSidebarIcon(isCollapsed);
}

function updateSidebarIcon(isCollapsed) {
    const icon = document.getElementById('sidebarToggleIcon');
    if (icon) {
        icon.className = isCollapsed ? 'fas fa-chevron-right' : 'fas fa-chevron-left';
    }
}

// per-section collapse inside the sidebar — remembers state per section in localStorage
function toggleSidebarSection(el) {
    // ignore when sidebar is globally collapsed (icons-only mode)
    if (document.getElementById('sidebar').classList.contains('collapsed')) return;
    var key = 'sbSec:' + (el.dataset.section || el.textContent.trim());
    var ul = el.nextElementSibling;
    while (ul && ul.tagName !== 'UL') ul = ul.nextElementSibling;
    if (!ul) return;
    var nowHidden = !el.classList.contains('section-hidden');
    el.classList.toggle('section-hidden', nowHidden);
    ul.classList.toggle('section-hidden', nowHidden);
    localStorage.setItem(key, nowHidden ? '1' : '0');
}

function restoreSidebarSections() {
    document.querySelectorAll('.sidebar-menu-title[data-section]').forEach(function(el){
        var key = 'sbSec:' + el.dataset.section;
        if (localStorage.getItem(key) === '1') {
            var ul = el.nextElementSibling;
            while (ul && ul.tagName !== 'UL') ul = ul.nextElementSibling;
            el.classList.add('section-hidden');
            if (ul) ul.classList.add('section-hidden');
        }
    });
}

// Initialize sidebar on page load
initSidebar();
restoreSidebarSections();

// Listen for system theme changes
window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function(e) {
    if (!localStorage.getItem('theme')) {
        if (e.matches) {
            document.body.classList.add('dark-mode');
            updateThemeButton(true);
        } else {
            document.body.classList.remove('dark-mode');
            updateThemeButton(false);
        }
    }
});
</script>

<!-- Sidebar Submenu JavaScript -->
<script>
/**
 * Sidebar Submenu Toggle Functionality
 * Handles expanding/collapsing submenu items
 */
document.addEventListener('DOMContentLoaded', function() {
    const submenuToggles = document.querySelectorAll('.submenu-toggle');

    submenuToggles.forEach(function(toggle) {
        toggle.addEventListener('click', function(e) {
            e.preventDefault();

            const submenuId = this.getAttribute('data-submenu');
            const submenu = document.getElementById(submenuId);

            if (!submenu) return;

            // Toggle open class on parent link
            this.classList.toggle('open');

            // Toggle open class on submenu
            submenu.classList.toggle('open');

            // Close other submenus (optional - for accordion behavior)
            // Uncomment below to allow only one submenu open at a time
            /*
            submenuToggles.forEach(function(otherToggle) {
                if (otherToggle !== toggle) {
                    otherToggle.classList.remove('open');
                    const otherSubmenuId = otherToggle.getAttribute('data-submenu');
                    const otherSubmenu = document.getElementById(otherSubmenuId);
                    if (otherSubmenu) {
                        otherSubmenu.classList.remove('open');
                    }
                }
            });
            */
        });
    });

    // Auto-open submenu if current page is a submenu item
    <?php if ($account_submenu_active): ?>
    const accountToggle = document.querySelector('[data-submenu="account-submenu"]');
    const accountSubmenu = document.getElementById('account-submenu');
    if (accountToggle && accountSubmenu) {
        accountToggle.classList.add('open');
        accountSubmenu.classList.add('open');
    }
    <?php endif; ?>

    <?php if ($settings_submenu_active): ?>
    const settingsToggle = document.querySelector('[data-submenu="settings-data-submenu"]');
    const settingsSubmenu = document.getElementById('settings-data-submenu');
    if (settingsToggle && settingsSubmenu) {
        settingsToggle.classList.add('open');
        settingsSubmenu.classList.add('open');
    }
    <?php endif; ?>
});
</script>

<!-- Money input auto-formatter -->
<script>
// live thousand-separator for .money-input fields
(function() {
    // format number string with commas
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

    // strip commas before form submit so backend gets clean numbers
    document.addEventListener('submit', function(e) {
        if (!e.target || e.target.tagName !== 'FORM') return;
        e.target.querySelectorAll('.money-input').forEach(function(el) {
            el.value = el.value.replace(/,/g, '');
        });
    }, true);

    // global helper: get numeric value from money-input (strips commas)
    window.moneyVal = function(id) {
        var el = document.getElementById(id);
        return el ? parseFloat(el.value.replace(/,/g, '')) || 0 : 0;
    };

    // global helper: set money-input value with formatting
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
    // inject bell into .header on DOM ready
    function injectBell() {
        var header = document.querySelector('.main-content > .header');
        var bellWrap = document.getElementById('notifBellWrap');
        if (!header || !bellWrap) return;

        // find the welcome div (last child div in header)
        var welcomeDiv = header.querySelector('div');
        if (welcomeDiv) {
            // wrap welcome text + bell in a flex container
            var container = document.createElement('div');
            container.style.cssText = 'display:flex;align-items:center;position:relative;';
            bellWrap.style.display = 'block';
            header.insertBefore(container, welcomeDiv);
            container.appendChild(bellWrap);
            container.appendChild(welcomeDiv);
        }
    }

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
                list.innerHTML = '<div style="padding:30px;text-align:center;color:var(--text-muted);"><i class="fas fa-check-circle" style="font-size:24px;"></i><p style="margin-top:8px;">All caught up!</p></div>';
                return;
            }

            var html = '';
            res.data.forEach(function(n) {
                var iconMap = {info:'fa-info-circle', warning:'fa-exclamation-triangle', danger:'fa-times-circle', success:'fa-check-circle'};
                var colorMap = {info:'#0074D9', warning:'#f39c12', danger:'#e74c3c', success:'#27ae60'};
                var icon = iconMap[n.type] || 'fa-bell';
                var color = colorMap[n.type] || '#666';
                var ago = timeAgo(n.created_at);

                html += '<div class="notif-item" style="padding:10px 16px;border-bottom:1px solid var(--border-color);cursor:pointer;display:flex;gap:10px;align-items:flex-start;" onclick="openNotif(' + n.id + ', \'' + (n.link || '') + '\')">';
                html += '<i class="fas ' + icon + '" style="color:' + color + ';margin-top:3px;"></i>';
                html += '<div style="flex:1;min-width:0;"><div style="font-size:13px;font-weight:600;color:var(--text-primary);">' + escHtml(n.title) + '</div>';
                html += '<div style="font-size:12px;color:var(--text-muted);margin-top:2px;">' + escHtml(n.message) + '</div>';
                html += '<div style="font-size:11px;color:var(--text-muted);margin-top:4px;">' + ago + '</div></div></div>';
            });
            list.innerHTML = html;
        });
    }

    function escHtml(s) {
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    function timeAgo(dateStr) {
        var d = new Date(dateStr);
        var now = new Date();
        var diff = Math.floor((now - d) / 1000);
        if (diff < 60) return 'Just now';
        if (diff < 3600) return Math.floor(diff/60) + 'm ago';
        if (diff < 86400) return Math.floor(diff/3600) + 'h ago';
        return Math.floor(diff/86400) + 'd ago';
    }

    // expose globally
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

    // close on outside click
    document.addEventListener('click', function(e) {
        var bell = document.getElementById('notifBell');
        var panel = document.getElementById('notifPanel');
        if (bell && panel && !bell.contains(e.target) && !panel.contains(e.target)) {
            panel.style.display = 'none';
        }
    });

    // poll badge every 60s
    setInterval(function() {
        $.getJSON('sidebar.php?notif_action=getUnread', function(res) {
            if (!res.success) return;
            var badge = document.getElementById('notifBadge');
            if (!badge) return;
            if (res.count > 0) { badge.style.display = 'block'; badge.textContent = res.count > 9 ? '9+' : res.count; }
            else badge.style.display = 'none';
        });
    }, 60000);

    // init
    $(document).ready(function() {
        injectBell();
        setTimeout(loadNotifications, 1000);
    });
})();
</script>
