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
$current_page = 'system-settings';

// Only admins can access settings
if ($role !== 'Admin') {
    header("Location: dashboard.php");
    exit();
}

// Handle AJAX requests - GET/query string actions
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    if ($_GET['action'] === 'saveAISettings' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            if ($role !== 'Admin') {
                echo json_encode(['success' => false, 'message' => 'Unauthorized']);
                exit();
            }

            $geminiApiKey = isset($_POST['gemini_api_key']) ? $_POST['gemini_api_key'] : '';
            $geminiModel = isset($_POST['gemini_model']) ? $_POST['gemini_model'] : 'gemini-2.0-flash';
            $aiEnabled = isset($_POST['ai_enabled']) ? $_POST['ai_enabled'] : '0';

            // Only save API key if it's not the masked version
            if (!empty($geminiApiKey) && strpos($geminiApiKey, '***...') === false) {
                setSetting('gemini_api_key', $geminiApiKey);
            }
            setSetting('gemini_model', $geminiModel);
            setSetting('ai_enabled', $aiEnabled);

            logActivity($user_id, $username, 'Settings Updated', "AI settings updated - Model: $geminiModel, Enabled: $aiEnabled");

            echo json_encode(['success' => true, 'message' => 'AI settings saved successfully']);
            exit();
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error saving AI settings: ' . $e->getMessage()]);
            exit();
        }
    }

    if ($_GET['action'] === 'testAIConnection') {
        try {
            if ($role !== 'Admin') {
                echo json_encode(['success' => false, 'message' => 'Unauthorized']);
                exit();
            }

            require_once 'ai-helper.php';
            $result = testGeminiConnection();
            echo json_encode($result);
            exit();
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error testing connection: ' . $e->getMessage()]);
            exit();
        }
    }
}

// Handle AJAX requests - POST actions
if (isset($_POST['action'])) {
    header('Content-Type: application/json');

    if ($_POST['action'] === 'getSettings') {
        try {
            $allowUserUploads = getSetting('allow_user_profile_uploads', '1');

            // AI Settings
            $geminiApiKey = getSetting('gemini_api_key', '');
            $geminiModel = getSetting('gemini_model', 'gemini-2.0-flash');
            $aiEnabled = getSetting('ai_enabled', '1');

            // Mask API key for display - show only last 4 chars
            $maskedKey = '';
            if (!empty($geminiApiKey)) {
                $maskedKey = '***...' . substr($geminiApiKey, -4);
            }

            // Company & Business Settings
            $companyName = getSetting('company_name', '7503 Canada');
            $companySubtitle = getSetting('company_subtitle', 'Negoce de Noix de Cajou Brutes');
            $companyAddress = getSetting('company_address', 'Daloa, Cote d\'Ivoire');
            $companyPhone = getSetting('company_phone', '');
            $companyEmail = getSetting('company_email', '');
            $defaultCurrency = getSetting('default_currency_symbol', 'FCFA');
            $defaultLanguage = getSetting('default_language', 'fr');
            $targetProfit = getSetting('target_profit_per_kg', '30');
            $loginLogoUrl = getSetting('login_logo_url', '');
            $receiptLogoUrl = getSetting('receipt_logo_url', '');

            echo json_encode([
                'success' => true,
                'data' => [
                    'allow_user_profile_uploads' => $allowUserUploads,
                    'gemini_api_key' => $maskedKey,
                    'gemini_model' => $geminiModel,
                    'ai_enabled' => $aiEnabled,
                    'company_name' => $companyName,
                    'company_subtitle' => $companySubtitle,
                    'company_address' => $companyAddress,
                    'company_phone' => $companyPhone,
                    'company_email' => $companyEmail,
                    'default_currency_symbol' => $defaultCurrency,
                    'default_language' => $defaultLanguage,
                    'target_profit_per_kg' => $targetProfit,
                    'login_logo_url' => $loginLogoUrl,
                    'receipt_logo_url' => $receiptLogoUrl
                ]
            ]);
            exit();
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error loading settings: ' . $e->getMessage()]);
            exit();
        }
    }

    if ($_POST['action'] === 'saveCompanySettings') {
        if ($role !== 'Admin') {
            echo json_encode(['success' => false, 'message' => 'Access denied']);
            exit();
        }
        $fields = ['company_name', 'company_subtitle', 'company_address', 'company_phone', 'company_email', 'default_currency_symbol', 'default_language', 'target_profit_per_kg', 'login_logo_url', 'receipt_logo_url'];
        foreach ($fields as $field) {
            if (isset($_POST[$field])) {
                setSetting($field, trim($_POST[$field]));
            }
        }
        logActivity($user_id, $username, 'Settings Updated', 'Updated company & business settings');
        echo json_encode(['success' => true, 'message' => 'Settings saved successfully']);
        exit();
    }

    if ($_POST['action'] === 'saveSettings') {
        try {
            $allowUserUploads = isset($_POST['allow_user_profile_uploads']) ? $_POST['allow_user_profile_uploads'] : '0';

            // Save setting
            $result = setSetting('allow_user_profile_uploads', $allowUserUploads);

            if ($result) {
                // Log activity
                $settingText = $allowUserUploads === '1' ? 'enabled' : 'disabled';
                logActivity($user_id, $username, 'Settings Updated', "User profile uploads $settingText");

                echo json_encode([
                    'success' => true,
                    'message' => 'Settings saved successfully'
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'Failed to save settings'
                ]);
            }
            exit();
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error saving settings: ' . $e->getMessage()]);
            exit();
        }
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
  <title>Commodity Flow &mdash; Settings</title>

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
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

  <!-- Font Awesome -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

  <!-- App Styles -->
  <link rel="stylesheet" href="styles.css?v=4.0">

  <!-- jQuery + SweetAlert2 -->
  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

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

    /* DataTables overrides */
    .dataTables_wrapper .dataTables_filter input {
      @apply bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200;
    }
    .dataTables_wrapper .dataTables_length select {
      @apply bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-2 py-1 text-sm text-slate-800 dark:text-slate-200;
    }

    /* Toggle switch */
    .toggle-track { width: 44px; height: 24px; border-radius: 12px; background: #cbd5e1; position: relative; cursor: pointer; transition: background 200ms; }
    .toggle-track.on { background: #2d9d99; }
    .toggle-track .toggle-knob { width: 20px; height: 20px; border-radius: 50%; background: #fff; position: absolute; top: 2px; left: 2px; transition: left 200ms; box-shadow: 0 1px 3px rgba(0,0,0,0.15); }
    .toggle-track.on .toggle-knob { left: 22px; }
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
        <i class="fas fa-gear text-brand-500 text-sm"></i>
        <h1 class="text-base font-bold text-slate-800 dark:text-white">Settings</h1>
      </div>
      <div class="ml-auto flex items-center gap-3">
        <span class="text-xs text-slate-500 dark:text-slate-400 hidden sm:inline">Welcome, <?php echo htmlspecialchars($username); ?></span>
      </div>
    </header>

    <!-- MAIN CONTENT -->
    <main class="flex-1 overflow-y-auto p-5">

      <!-- Loading Skeleton -->
      <div id="loadingSkeleton">
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card p-5 mb-5">
          <div class="skeleton h-6 w-3/5 mb-4"></div>
          <div class="skeleton h-4 w-4/5 mb-3"></div>
          <div class="skeleton h-4 w-3/5"></div>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
          <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card p-5">
            <div class="skeleton h-10 w-10 rounded-lg mb-4"></div>
            <div class="skeleton h-4 w-3/5"></div>
          </div>
          <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card p-5">
            <div class="skeleton h-10 w-10 rounded-lg mb-4"></div>
            <div class="skeleton h-4 w-3/5"></div>
          </div>
          <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card p-5">
            <div class="skeleton h-10 w-10 rounded-lg mb-4"></div>
            <div class="skeleton h-4 w-3/5"></div>
          </div>
          <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card p-5">
            <div class="skeleton h-10 w-10 rounded-lg mb-4"></div>
            <div class="skeleton h-4 w-3/5"></div>
          </div>
        </div>
      </div>

      <!-- Settings Content -->
      <div id="settingsContent" style="display: none;">

        <!-- Quick Actions -->
        <div class="flex justify-end gap-2 mb-5">
          <button class="bg-brand-500 hover:bg-brand-600 text-white text-xs font-semibold px-3.5 py-2 rounded-lg transition-colors inline-flex items-center gap-1.5" onclick="saveSettings()">
            <i class="fas fa-save"></i> Save Settings
          </button>
          <button class="bg-slate-200 hover:bg-slate-300 dark:bg-slate-700 dark:hover:bg-slate-600 text-slate-700 dark:text-slate-200 text-xs font-semibold px-3.5 py-2 rounded-lg transition-colors inline-flex items-center gap-1.5" onclick="loadSettings()">
            <i class="fas fa-sync"></i> Reload
          </button>
        </div>

        <!-- 2x2 Grid Layout -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">

          <!-- Card 1: System Overview -->
          <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card p-5">
            <div class="flex items-center gap-3 mb-5">
              <div class="w-10 h-10 rounded-lg bg-gradient-to-br from-brand-600 to-brand-400 flex items-center justify-center text-white text-sm">
                <i class="fas fa-server"></i>
              </div>
              <div>
                <h3 class="text-sm font-bold text-slate-800 dark:text-white">System Overview</h3>
                <p class="text-xs text-slate-400">Core system configuration</p>
              </div>
            </div>
            <div class="space-y-3">
              <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-lg bg-emerald-50 dark:bg-emerald-900/30 flex items-center justify-center text-emerald-500 text-sm"><i class="fas fa-database"></i></div>
                <div class="flex-1 min-w-0">
                  <div class="text-xs text-slate-400 font-medium">Database</div>
                  <div class="text-sm font-semibold text-slate-800 dark:text-white truncate"><?php echo DB_NAME; ?></div>
                </div>
              </div>
              <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-lg bg-blue-50 dark:bg-blue-900/30 flex items-center justify-center text-blue-500 text-sm"><i class="fas fa-clock"></i></div>
                <div class="flex-1 min-w-0">
                  <div class="text-xs text-slate-400 font-medium">Session Timeout</div>
                  <div class="text-sm font-semibold text-slate-800 dark:text-white"><?php echo SESSION_TIMEOUT / 60; ?> minutes</div>
                </div>
              </div>
              <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-lg bg-amber-50 dark:bg-amber-900/30 flex items-center justify-center text-amber-500 text-sm"><i class="fas fa-shield-alt"></i></div>
                <div class="flex-1 min-w-0">
                  <div class="text-xs text-slate-400 font-medium">Max Login Attempts</div>
                  <div class="text-sm font-semibold text-slate-800 dark:text-white"><?php echo MAX_LOGIN_ATTEMPTS; ?> attempts</div>
                </div>
              </div>
              <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-lg bg-rose-50 dark:bg-rose-900/30 flex items-center justify-center text-rose-500 text-sm"><i class="fas fa-lock"></i></div>
                <div class="flex-1 min-w-0">
                  <div class="text-xs text-slate-400 font-medium">Lockout Duration</div>
                  <div class="text-sm font-semibold text-slate-800 dark:text-white"><?php echo LOGIN_LOCKOUT_TIME / 60; ?> minutes</div>
                </div>
              </div>
            </div>
          </div>

          <!-- Card 2: User Management -->
          <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card p-5">
            <div class="flex items-center gap-3 mb-5">
              <div class="w-10 h-10 rounded-lg bg-gradient-to-br from-emerald-500 to-emerald-700 flex items-center justify-center text-white text-sm">
                <i class="fas fa-users-cog"></i>
              </div>
              <div>
                <h3 class="text-sm font-bold text-slate-800 dark:text-white">User Management</h3>
                <p class="text-xs text-slate-400">Control user permissions</p>
              </div>
            </div>
            <div class="space-y-4">
              <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                  <div class="w-9 h-9 rounded-lg bg-violet-50 dark:bg-violet-900/30 flex items-center justify-center text-violet-500 text-sm"><i class="fas fa-image"></i></div>
                  <div>
                    <div class="text-sm font-semibold text-slate-800 dark:text-white">Profile Image Uploads</div>
                    <div class="text-xs text-slate-400">Allow users to upload profile pictures</div>
                  </div>
                </div>
                <div class="flex items-center gap-2">
                  <div class="toggle-track" id="uploadToggleTrack" onclick="document.getElementById('allowUserUploads').click();">
                    <div class="toggle-knob"></div>
                  </div>
                  <input type="checkbox" id="allowUserUploads" class="hidden">
                  <div id="uploadToggleStatus" class="text-xs font-semibold">
                    <span class="status-dot status-disabled hidden"></span>
                    <span class="status-text text-slate-400">Disabled</span>
                  </div>
                </div>
              </div>
              <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg px-3 py-2 text-xs text-blue-600 dark:text-blue-300 flex items-center gap-2">
                <i class="fas fa-info-circle"></i>
                <span>When disabled, only administrators can manage all profile images</span>
              </div>
            </div>
          </div>

          <!-- Card 3: Security Status -->
          <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card p-5">
            <div class="flex items-center gap-3 mb-5">
              <div class="w-10 h-10 rounded-lg bg-gradient-to-br from-blue-500 to-blue-700 flex items-center justify-center text-white text-sm">
                <i class="fas fa-shield-alt"></i>
              </div>
              <div>
                <h3 class="text-sm font-bold text-slate-800 dark:text-white">Security Status</h3>
                <p class="text-xs text-slate-400">Active security features</p>
              </div>
            </div>
            <div class="space-y-3">
              <div class="flex items-center justify-between">
                <div class="flex items-center gap-2.5">
                  <i class="fas fa-check-circle text-emerald-500 text-sm"></i>
                  <div>
                    <div class="text-sm font-medium text-slate-700 dark:text-slate-200">Session Security</div>
                    <div class="text-xs text-slate-400">HTTPOnly cookies &amp; Strict SameSite</div>
                  </div>
                </div>
                <span class="inline-flex items-center gap-1 bg-emerald-50 dark:bg-emerald-900/30 text-emerald-600 dark:text-emerald-400 text-xs font-semibold px-2 py-0.5 rounded-full"><i class="fas fa-shield-alt text-[10px]"></i> Active</span>
              </div>
              <div class="flex items-center justify-between">
                <div class="flex items-center gap-2.5">
                  <i class="fas fa-lock text-emerald-500 text-sm"></i>
                  <div>
                    <div class="text-sm font-medium text-slate-700 dark:text-slate-200">Password Encryption</div>
                    <div class="text-xs text-slate-400">Bcrypt (PASSWORD_DEFAULT)</div>
                  </div>
                </div>
                <span class="inline-flex items-center gap-1 bg-emerald-50 dark:bg-emerald-900/30 text-emerald-600 dark:text-emerald-400 text-xs font-semibold px-2 py-0.5 rounded-full"><i class="fas fa-shield-alt text-[10px]"></i> Active</span>
              </div>
              <div class="flex items-center justify-between">
                <div class="flex items-center gap-2.5">
                  <i class="fas fa-user-clock text-emerald-500 text-sm"></i>
                  <div>
                    <div class="text-sm font-medium text-slate-700 dark:text-slate-200">Rate Limiting</div>
                    <div class="text-xs text-slate-400">User &amp; IP tracking enabled</div>
                  </div>
                </div>
                <span class="inline-flex items-center gap-1 bg-emerald-50 dark:bg-emerald-900/30 text-emerald-600 dark:text-emerald-400 text-xs font-semibold px-2 py-0.5 rounded-full"><i class="fas fa-shield-alt text-[10px]"></i> Active</span>
              </div>
              <div class="flex items-center justify-between">
                <div class="flex items-center gap-2.5">
                  <i class="fas fa-code text-emerald-500 text-sm"></i>
                  <div>
                    <div class="text-sm font-medium text-slate-700 dark:text-slate-200">CSRF Protection</div>
                    <div class="text-xs text-slate-400">Token-based validation</div>
                  </div>
                </div>
                <span class="inline-flex items-center gap-1 bg-emerald-50 dark:bg-emerald-900/30 text-emerald-600 dark:text-emerald-400 text-xs font-semibold px-2 py-0.5 rounded-full"><i class="fas fa-shield-alt text-[10px]"></i> Active</span>
              </div>
            </div>
          </div>

          <!-- Card 4: Server Information -->
          <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card p-5">
            <div class="flex items-center gap-3 mb-5">
              <div class="w-10 h-10 rounded-lg bg-gradient-to-br from-amber-400 to-amber-600 flex items-center justify-center text-white text-sm">
                <i class="fas fa-server"></i>
              </div>
              <div>
                <h3 class="text-sm font-bold text-slate-800 dark:text-white">Server Information</h3>
                <p class="text-xs text-slate-400">Environment details</p>
              </div>
            </div>
            <div class="space-y-3">
              <div class="flex items-center justify-between">
                <div class="flex items-center gap-2 text-xs text-slate-500 dark:text-slate-400"><i class="fas fa-database w-4"></i><span>Database Host</span></div>
                <div class="text-sm font-semibold text-slate-800 dark:text-white"><?php echo DB_HOST; ?></div>
              </div>
              <div class="flex items-center justify-between">
                <div class="flex items-center gap-2 text-xs text-slate-500 dark:text-slate-400"><i class="fas fa-user w-4"></i><span>Database User</span></div>
                <div class="text-sm font-semibold text-slate-800 dark:text-white"><?php echo DB_USER; ?></div>
              </div>
              <div class="flex items-center justify-between">
                <div class="flex items-center gap-2 text-xs text-slate-500 dark:text-slate-400"><i class="fas fa-code w-4"></i><span>PHP Version</span></div>
                <div class="text-sm"><span class="bg-brand-50 dark:bg-brand-900/30 text-brand-600 dark:text-brand-400 font-semibold px-2 py-0.5 rounded"><?php echo phpversion(); ?></span></div>
              </div>
              <div class="flex items-center justify-between">
                <div class="flex items-center gap-2 text-xs text-slate-500 dark:text-slate-400"><i class="fas fa-hdd w-4"></i><span>Max Upload</span></div>
                <div class="text-sm"><span class="bg-amber-50 dark:bg-amber-900/30 text-amber-600 dark:text-amber-400 font-semibold px-2 py-0.5 rounded"><?php echo ini_get('upload_max_filesize'); ?></span></div>
              </div>
              <div class="flex items-center justify-between">
                <div class="flex items-center gap-2 text-xs text-slate-500 dark:text-slate-400"><i class="fas fa-clock w-4"></i><span>Server Time</span></div>
                <div class="text-sm font-semibold text-slate-800 dark:text-white"><?php echo date('Y-m-d H:i:s'); ?></div>
              </div>
            </div>
          </div>

          <!-- Card 5: AI Configuration (Admin Only) -->
          <?php if ($role === 'Admin'): ?>
          <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card p-5 lg:col-span-2">
            <div class="flex items-center gap-3 mb-5">
              <div class="w-10 h-10 rounded-lg bg-gradient-to-br from-brand-700 to-brand-900 flex items-center justify-center text-white text-sm">
                <i class="fas fa-brain"></i>
              </div>
              <div>
                <h3 class="text-sm font-bold text-slate-800 dark:text-white">AI Configuration</h3>
                <p class="text-xs text-slate-400">Configure Gemini AI for business analytics</p>
              </div>
            </div>

            <!-- Enable/Disable Toggle Card -->
            <div id="aiToggleCard" class="flex items-center justify-between p-4 rounded-lg mb-5 cursor-pointer transition-all" onclick="document.getElementById('aiEnabled').click();">
              <div class="flex items-center gap-3.5">
                <div id="aiToggleIcon" class="w-11 h-11 rounded-xl flex items-center justify-center text-xl transition-all">
                  <i class="fas fa-power-off"></i>
                </div>
                <div>
                  <div class="text-sm font-bold text-slate-800 dark:text-white" id="aiToggleLabel">AI Features Enabled</div>
                  <div class="text-xs text-slate-400 mt-0.5" id="aiToggleSub">All AI-powered insights and reports are active</div>
                </div>
              </div>
              <label class="toggle-switch" onclick="event.stopPropagation();">
                <input type="checkbox" id="aiEnabled" class="hidden" onchange="updateAIToggleUI(); saveAISettings();">
                <div class="toggle-track" id="aiToggleTrack"><div class="toggle-knob"></div></div>
              </label>
            </div>

            <!-- API Key Input -->
            <div class="mb-4">
              <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">
                <i class="fas fa-key text-brand-500 mr-1"></i>Gemini API Key
              </label>
              <div class="flex gap-2">
                <input type="password" id="geminiApiKey" placeholder="Enter your Gemini API key..." class="flex-1 bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
                <button type="button" class="bg-slate-200 hover:bg-slate-300 dark:bg-slate-700 dark:hover:bg-slate-600 text-slate-600 dark:text-slate-300 text-xs font-semibold px-3 py-2 rounded-lg transition-colors" onclick="toggleKeyVisibility()" id="toggleKeyBtn"><i class="fas fa-eye"></i></button>
              </div>
              <div id="apiKeyStatus" class="text-xs mt-1.5 text-slate-400"></div>
            </div>

            <!-- Model Selector -->
            <div class="mb-5">
              <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">
                <i class="fas fa-microchip text-brand-500 mr-1"></i>Gemini Model
              </label>
              <select id="geminiModel" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
                <optgroup label="Gemini 3.0 (Latest)">
                  <option value="gemini-3.0-flash">Gemini 3.0 Flash (Latest, Fast)</option>
                  <option value="gemini-3.0-pro">Gemini 3.0 Pro (Latest, Most Capable)</option>
                </optgroup>
                <optgroup label="Gemini 2.5">
                  <option value="gemini-2.5-pro">Gemini 2.5 Pro (Advanced Reasoning)</option>
                  <option value="gemini-2.5-flash">Gemini 2.5 Flash (Fast + Smart)</option>
                </optgroup>
                <optgroup label="Gemini 2.0">
                  <option value="gemini-2.0-flash" selected>Gemini 2.0 Flash (Recommended)</option>
                  <option value="gemini-2.0-flash-lite">Gemini 2.0 Flash Lite (Cheapest)</option>
                </optgroup>
                <optgroup label="Gemini 1.5">
                  <option value="gemini-1.5-pro">Gemini 1.5 Pro (Stable)</option>
                  <option value="gemini-1.5-flash">Gemini 1.5 Flash (Balanced)</option>
                </optgroup>
              </select>
              <div class="text-xs text-slate-400 mt-1.5 flex items-center gap-1"><i class="fas fa-info-circle"></i> Gemini 2.0 Flash is recommended for best speed/cost balance</div>
            </div>

            <!-- Action Buttons -->
            <div class="flex gap-2">
              <button class="bg-brand-500 hover:bg-brand-600 text-white text-xs font-semibold px-3.5 py-2 rounded-lg transition-colors inline-flex items-center gap-1.5" onclick="saveAISettings()"><i class="fas fa-save"></i> Save AI Settings</button>
              <button class="bg-slate-200 hover:bg-slate-300 dark:bg-slate-700 dark:hover:bg-slate-600 text-slate-700 dark:text-slate-200 text-xs font-semibold px-3.5 py-2 rounded-lg transition-colors inline-flex items-center gap-1.5" onclick="testAIConnection()" id="testBtn"><i class="fas fa-plug"></i> Test Connection</button>
            </div>

            <!-- Test Result -->
            <div id="aiTestResult" style="display:none;" class="mt-3 p-3 rounded-lg text-xs"></div>
          </div>
          <?php endif; ?>

          <!-- Card 6: Company Information -->
          <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card p-5 lg:col-span-2">
            <div class="flex items-center gap-3 mb-5">
              <div class="w-10 h-10 rounded-lg bg-gradient-to-br from-slate-500 to-slate-700 flex items-center justify-center text-white text-sm">
                <i class="fas fa-building"></i>
              </div>
              <div>
                <h3 class="text-sm font-bold text-slate-800 dark:text-white">Company / Cooperative Information</h3>
              </div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1"><i class="fas fa-signature mr-1"></i> Company Name</label>
                <input type="text" id="companyName" maxlength="200" placeholder="Company name" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
              </div>
              <div>
                <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1"><i class="fas fa-tag mr-1"></i> Subtitle / Description</label>
                <input type="text" id="companySubtitle" maxlength="200" placeholder="e.g. Negoce de Noix de Cajou" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
              </div>
              <div class="md:col-span-2">
                <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1"><i class="fas fa-map-marker-alt mr-1"></i> Address</label>
                <textarea id="companyAddress" rows="2" maxlength="500" placeholder="Company address" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors"></textarea>
              </div>
              <div>
                <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1"><i class="fas fa-phone mr-1"></i> Phone</label>
                <input type="text" id="companyPhone" maxlength="50" placeholder="Phone number" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
              </div>
              <div>
                <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1"><i class="fas fa-envelope mr-1"></i> Email</label>
                <input type="email" id="companyEmail" maxlength="200" placeholder="Email address" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
              </div>
              <div class="md:col-span-2">
                <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1"><i class="fas fa-image mr-1"></i> Login Page Logo URL</label>
                <div class="flex gap-2 items-center">
                  <input type="url" id="loginLogoUrl" name="login_logo_url" maxlength="500" placeholder="https://example.com/logo.png" class="flex-1 bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
                  <img id="loginLogoPreview" src="" alt="" class="w-10 h-10 rounded-full object-cover border-2 border-slate-200 dark:border-slate-600" style="display:none;">
                </div>
                <p class="text-xs text-slate-400 mt-1">Paste an image URL to use as the login page logo. Leave empty for default.</p>
              </div>
              <div class="md:col-span-2">
                <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1"><i class="fas fa-stamp mr-1"></i> Receipt / Contract Logo URL</label>
                <div class="flex gap-2 items-center">
                  <input type="url" id="receiptLogoUrl" name="receipt_logo_url" maxlength="500" placeholder="https://example.com/receipt-logo.png" class="flex-1 bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
                  <img id="receiptLogoPreview" src="" alt="" class="w-10 h-10 rounded object-contain border-2 border-slate-200 dark:border-slate-600" style="display:none;">
                </div>
                <p class="text-xs text-slate-400 mt-1">This logo appears on printed purchase receipts and financing contracts. Recommended: square or landscape, max 200px wide.</p>
              </div>
            </div>
          </div>

          <!-- Card 7: Business Parameters -->
          <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card p-5">
            <div class="flex items-center gap-3 mb-5">
              <div class="w-10 h-10 rounded-lg bg-gradient-to-br from-indigo-500 to-indigo-700 flex items-center justify-center text-white text-sm">
                <i class="fas fa-sliders"></i>
              </div>
              <div>
                <h3 class="text-sm font-bold text-slate-800 dark:text-white">Business Parameters</h3>
              </div>
            </div>
            <div class="space-y-4">
              <div>
                <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1"><i class="fas fa-bullseye mr-1"></i> Target Profit per Kg</label>
                <input type="number" id="targetProfitPerKg" step="0.01" min="0" placeholder="30" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
                <p class="text-xs text-slate-400 mt-1">Used in Profit Analysis to determine performance status</p>
              </div>
              <div>
                <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1"><i class="fas fa-money-bill mr-1"></i> Default Currency Symbol</label>
                <select id="defaultCurrency" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
                  <option value="FCFA">FCFA (CFA Franc)</option>
                  <option value="$">$ (US Dollar)</option>
                  <option value="&euro;">&euro; (Euro)</option>
                  <option value="CAD $">CAD $ (Canadian Dollar)</option>
                </select>
              </div>
              <div>
                <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1"><i class="fas fa-language mr-1"></i> Default Language</label>
                <select id="defaultLanguage" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
                  <option value="en">English</option>
                  <option value="fr">Fran&ccedil;ais</option>
                </select>
              </div>
            </div>
          </div>

          <!-- Save Company & Business Settings Button -->
          <div class="lg:col-span-2 flex justify-end mt-1">
            <button class="bg-brand-500 hover:bg-brand-600 text-white text-xs font-semibold px-3.5 py-2 rounded-lg transition-colors inline-flex items-center gap-1.5" onclick="saveCompanySettings()">
              <i class="fas fa-save"></i> Save Company &amp; Business Settings
            </button>
          </div>

        </div>
      </div>

    </main>
  </div>
</div>

<!-- Theme init -->
<script>
(function(){
  var t = localStorage.getItem('theme');
  if(t === 'dark' || (!t && window.matchMedia('(prefers-color-scheme: dark)').matches)){
    document.documentElement.classList.add('dark');
  }
})();
</script>

<script>
    $(document).ready(function() {
        loadSettings();
    });

    function loadSettings() {
        // Show skeleton, hide content
        $('#loadingSkeleton').show();
        $('#settingsContent').hide();

        $.ajax({
            url: '',
            method: 'POST',
            data: { action: 'getSettings' },
            dataType: 'json',
            success: function(response) {
                // Hide skeleton, show content
                setTimeout(() => {
                    $('#loadingSkeleton').hide();
                    $('#settingsContent').fadeIn(300);
                }, 500);

                if (response.success) {
                    const data = response.data;

                    // Set toggle states
                    const isEnabled = data.allow_user_profile_uploads === '1';
                    document.getElementById('allowUserUploads').checked = isEnabled;
                    updateToggleStatus(isEnabled);

                    // Populate AI settings if elements exist
                    if (document.getElementById('geminiApiKey')) {
                        if (data.gemini_api_key) {
                            document.getElementById('geminiApiKey').value = data.gemini_api_key;
                        }
                        if (data.gemini_model) {
                            document.getElementById('geminiModel').value = data.gemini_model;
                        }
                        document.getElementById('aiEnabled').checked = data.ai_enabled === '1';
                        updateAIToggleUI();
                    }

                    // Populate Company & Business settings
                    if (document.getElementById('companyName')) {
                        document.getElementById('companyName').value = data.company_name || '';
                        document.getElementById('companySubtitle').value = data.company_subtitle || '';
                        document.getElementById('companyAddress').value = data.company_address || '';
                        document.getElementById('companyPhone').value = data.company_phone || '';
                        document.getElementById('companyEmail').value = data.company_email || '';
                        if (data.default_currency_symbol) {
                            document.getElementById('defaultCurrency').value = data.default_currency_symbol;
                        }
                        if (data.default_language) {
                            document.getElementById('defaultLanguage').value = data.default_language;
                        }
                        if (data.target_profit_per_kg) {
                            document.getElementById('targetProfitPerKg').value = data.target_profit_per_kg;
                        }
                        if (document.getElementById('loginLogoUrl')) {
                            document.getElementById('loginLogoUrl').value = data.login_logo_url || '';
                            var preview = document.getElementById('loginLogoPreview');
                            if (data.login_logo_url) {
                                preview.src = data.login_logo_url;
                                preview.style.display = 'block';
                            } else {
                                preview.style.display = 'none';
                            }
                        }
                        if (document.getElementById('receiptLogoUrl')) {
                            document.getElementById('receiptLogoUrl').value = data.receipt_logo_url || '';
                            var rPreview = document.getElementById('receiptLogoPreview');
                            if (data.receipt_logo_url) {
                                rPreview.src = data.receipt_logo_url;
                                rPreview.style.display = 'block';
                            } else {
                                rPreview.style.display = 'none';
                            }
                        }
                    }
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: response.message || 'Failed to load settings'
                    });
                }
            },
            error: function(xhr, status, error) {
                $('#loadingSkeleton').hide();
                $('#settingsContent').show();

                console.error('AJAX Error:', error);
                Swal.fire({
                    icon: 'error',
                    title: 'Connection Error',
                    text: 'Could not connect to server. Please check console for details.'
                });
            }
        });
    }

    function saveSettings() {
        const allowUserUploads = document.getElementById('allowUserUploads').checked ? '1' : '0';

        Swal.fire({
            title: 'Saving Settings...',
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });

        $.ajax({
            url: '',
            method: 'POST',
            data: {
                action: 'saveSettings',
                allow_user_profile_uploads: allowUserUploads
            },
            dataType: 'json',
            success: function(response) {
                Swal.close();
                if (response.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Success!',
                        text: response.message,
                        timer: 2000,
                        showConfirmButton: false
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: response.message
                    });
                }
            },
            error: function(xhr, status, error) {
                Swal.close();
                console.error('AJAX Error:', error);
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'Failed to save settings. Please try again.'
                });
            }
        });
    }

    function updateToggleStatus(isEnabled) {
        var track = document.getElementById('uploadToggleTrack');
        var statusElement = document.getElementById('uploadToggleStatus');
        var statusText = statusElement.querySelector('.status-text');

        if (isEnabled) {
            track.classList.add('on');
            statusText.textContent = 'Enabled';
            statusText.className = 'status-text text-emerald-500 font-semibold';
        } else {
            track.classList.remove('on');
            statusText.textContent = 'Disabled';
            statusText.className = 'status-text text-slate-400';
        }
    }

    // Add event listener to toggle
    $(document).on('change', '#allowUserUploads', function() {
        updateToggleStatus(this.checked);
    });

    // live preview for login logo
    $(document).on('input', '#loginLogoUrl', function() {
        var url = this.value.trim();
        var preview = document.getElementById('loginLogoPreview');
        if (url) {
            preview.src = url;
            preview.style.display = 'block';
            preview.onerror = function() { this.style.display = 'none'; };
        } else {
            preview.style.display = 'none';
        }
    });

    function updateAIToggleUI() {
        var enabled = document.getElementById('aiEnabled').checked;
        var card = document.getElementById('aiToggleCard');
        var icon = document.getElementById('aiToggleIcon');
        var label = document.getElementById('aiToggleLabel');
        var sub = document.getElementById('aiToggleSub');
        var track = document.getElementById('aiToggleTrack');
        if (!card) return;
        if (enabled) {
            card.style.background = 'rgba(52,168,83,0.08)';
            card.style.border = '2px solid rgba(52,168,83,0.3)';
            icon.style.background = 'rgba(52,168,83,0.15)';
            icon.style.color = '#34a853';
            label.textContent = 'AI Features Enabled';
            sub.textContent = 'All AI-powered insights and reports are active';
            if (track) track.classList.add('on');
        } else {
            card.style.background = 'rgba(234,67,53,0.05)';
            card.style.border = '2px solid rgba(234,67,53,0.2)';
            icon.style.background = 'rgba(234,67,53,0.12)';
            icon.style.color = '#ea4335';
            label.textContent = 'AI Features Disabled';
            sub.textContent = 'AI insights and reports are turned off';
            if (track) track.classList.remove('on');
        }
    }

    function toggleKeyVisibility() {
        var input = document.getElementById('geminiApiKey');
        var btn = document.getElementById('toggleKeyBtn');
        if (input.type === 'password') {
            input.type = 'text';
            btn.innerHTML = '<i class="fas fa-eye-slash"></i>';
        } else {
            input.type = 'password';
            btn.innerHTML = '<i class="fas fa-eye"></i>';
        }
    }

    function saveAISettings() {
        var apiKey = document.getElementById('geminiApiKey').value;
        var model = document.getElementById('geminiModel').value;
        var enabled = document.getElementById('aiEnabled').checked ? '1' : '0';

        $.ajax({
            url: '?action=saveAISettings',
            method: 'POST',
            data: { gemini_api_key: apiKey, gemini_model: model, ai_enabled: enabled },
            dataType: 'json',
            success: function(r) {
                if (r.success) {
                    Swal.fire({ icon: 'success', title: 'Saved!', text: 'AI settings updated', timer: 2000, showConfirmButton: false });
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: r.message });
                }
            },
            error: function() {
                Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error' });
            }
        });
    }

    function testAIConnection() {
        var btn = document.getElementById('testBtn');
        var result = document.getElementById('aiTestResult');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Testing...';
        result.style.display = 'none';

        // Save first, then test
        var apiKey = document.getElementById('geminiApiKey').value;
        var model = document.getElementById('geminiModel').value;
        var enabled = document.getElementById('aiEnabled').checked ? '1' : '0';

        $.ajax({
            url: '?action=saveAISettings',
            method: 'POST',
            data: { gemini_api_key: apiKey, gemini_model: model, ai_enabled: enabled },
            dataType: 'json',
            success: function() {
                // Now test
                $.getJSON('?action=testAIConnection', function(r) {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-plug"></i> Test Connection';
                    result.style.display = 'block';
                    if (r.success) {
                        result.style.background = 'rgba(52,168,83,0.1)';
                        result.style.color = '#155724';
                        result.style.border = '1px solid rgba(52,168,83,0.3)';
                        result.innerHTML = '<i class="fas fa-check-circle"></i> ' + r.message;
                    } else {
                        result.style.background = 'rgba(234,67,53,0.1)';
                        result.style.color = '#721c24';
                        result.style.border = '1px solid rgba(234,67,53,0.3)';
                        result.innerHTML = '<i class="fas fa-times-circle"></i> ' + r.message;
                    }
                }).fail(function() {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-plug"></i> Test Connection';
                    result.style.display = 'block';
                    result.style.background = 'rgba(234,67,53,0.1)';
                    result.style.color = '#721c24';
                    result.innerHTML = '<i class="fas fa-times-circle"></i> Connection failed';
                });
            }
        });
    }

    function saveCompanySettings() {
        Swal.fire({title: 'Saving...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); }});
        var formData = new FormData();
        formData.append('action', 'saveCompanySettings');
        formData.append('company_name', document.getElementById('companyName').value);
        formData.append('company_subtitle', document.getElementById('companySubtitle').value);
        formData.append('company_address', document.getElementById('companyAddress').value);
        formData.append('company_phone', document.getElementById('companyPhone').value);
        formData.append('company_email', document.getElementById('companyEmail').value);
        formData.append('default_currency_symbol', document.getElementById('defaultCurrency').value);
        formData.append('default_language', document.getElementById('defaultLanguage').value);
        formData.append('target_profit_per_kg', document.getElementById('targetProfitPerKg').value);
        formData.append('login_logo_url', document.getElementById('loginLogoUrl').value);
        formData.append('receipt_logo_url', document.getElementById('receiptLogoUrl').value);
        $.ajax({
            url: '',
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(r) {
                if (r.success) {
                    Swal.fire({icon: 'success', title: 'Saved!', text: r.message, timer: 2000, showConfirmButton: false});
                } else {
                    Swal.fire({icon: 'error', title: 'Error', text: r.message});
                }
            },
            error: function() {
                Swal.fire({icon: 'error', title: 'Error', text: 'Connection error'});
            }
        });
    }
</script>
</body>
</html>
