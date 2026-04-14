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
    <title>System Settings - Dashboard System</title>

    <!-- CDN Dependencies -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="styles.css?v=4.0">

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
    <?php include 'mobile-menu.php'; ?>

    <div class="app-container">
        <?php include 'sidebar.php'; ?>

        <!-- Main Content -->
        <div class="main-content">
            <div class="header">
                <h1><i class="fas fa-cog"></i> System Settings</h1>
                <div>Welcome, <?php echo htmlspecialchars($username); ?></div>
            </div>

            <!-- Loading Skeleton -->
            <div id="loadingSkeleton">
                <div class="skeleton-card" style="margin-bottom: 20px;">
                    <div class="skeleton skeleton-text-large" style="width: 60%; margin-bottom: 16px;"></div>
                    <div class="skeleton skeleton-text" style="width: 80%; margin-bottom: 12px;"></div>
                    <div class="skeleton skeleton-text" style="width: 70%;"></div>
                </div>
                <div class="dashboard-grid-4" style="margin-bottom: 30px;">
                    <div class="skeleton-card">
                        <div class="skeleton skeleton-icon" style="margin-bottom: 16px;"></div>
                        <div class="skeleton skeleton-text" style="width: 60%;"></div>
                    </div>
                    <div class="skeleton-card">
                        <div class="skeleton skeleton-icon" style="margin-bottom: 16px;"></div>
                        <div class="skeleton skeleton-text" style="width: 60%;"></div>
                    </div>
                    <div class="skeleton-card">
                        <div class="skeleton skeleton-icon" style="margin-bottom: 16px;"></div>
                        <div class="skeleton skeleton-text" style="width: 60%;"></div>
                    </div>
                    <div class="skeleton-card">
                        <div class="skeleton skeleton-icon" style="margin-bottom: 16px;"></div>
                        <div class="skeleton skeleton-text" style="width: 60%;"></div>
                    </div>
                </div>
            </div>

            <!-- Settings Content -->
            <div id="settingsContent" style="display: none;">
                <!-- Quick Actions -->
                <div style="margin-bottom: 25px; display: flex; gap: 10px; justify-content: flex-end;">
                    <button class="btn btn-success" onclick="saveSettings()">
                        <i class="fas fa-save"></i> Save Settings
                    </button>
                    <button class="btn btn-secondary" onclick="loadSettings()">
                        <i class="fas fa-sync"></i> Reload
                    </button>
                </div>

                <!-- 2x2 Grid Layout -->
                <div class="settings-grid-2x2">

                    <!-- Card 1: System Overview -->
                    <div class="settings-mega-card">
                        <div class="settings-card-header">
                            <div class="settings-card-icon" style="background: linear-gradient(135deg, var(--navy-primary) 0%, var(--navy-light) 100%);">
                                <i class="fas fa-server"></i>
                            </div>
                            <div>
                                <h3 class="settings-card-title">System Overview</h3>
                                <p class="settings-card-subtitle">Core system configuration</p>
                            </div>
                        </div>
                        <div class="settings-card-body">
                            <div class="stat-item-inline">
                                <div class="stat-item-icon" style="background: rgba(52, 168, 83, 0.1); color: var(--success);">
                                    <i class="fas fa-database"></i>
                                </div>
                                <div class="stat-item-content">
                                    <div class="stat-item-label">Database</div>
                                    <div class="stat-item-value"><?php echo DB_NAME; ?></div>
                                </div>
                            </div>
                            <div class="stat-item-inline">
                                <div class="stat-item-icon" style="background: rgba(0, 116, 217, 0.1); color: var(--navy-accent);">
                                    <i class="fas fa-clock"></i>
                                </div>
                                <div class="stat-item-content">
                                    <div class="stat-item-label">Session Timeout</div>
                                    <div class="stat-item-value"><?php echo SESSION_TIMEOUT / 60; ?> minutes</div>
                                </div>
                            </div>
                            <div class="stat-item-inline">
                                <div class="stat-item-icon" style="background: rgba(251, 188, 4, 0.1); color: var(--warning);">
                                    <i class="fas fa-shield-alt"></i>
                                </div>
                                <div class="stat-item-content">
                                    <div class="stat-item-label">Max Login Attempts</div>
                                    <div class="stat-item-value"><?php echo MAX_LOGIN_ATTEMPTS; ?> attempts</div>
                                </div>
                            </div>
                            <div class="stat-item-inline">
                                <div class="stat-item-icon" style="background: rgba(234, 67, 53, 0.1); color: var(--danger);">
                                    <i class="fas fa-lock"></i>
                                </div>
                                <div class="stat-item-content">
                                    <div class="stat-item-label">Lockout Duration</div>
                                    <div class="stat-item-value"><?php echo LOGIN_LOCKOUT_TIME / 60; ?> minutes</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Card 2: User Management -->
                    <div class="settings-mega-card">
                        <div class="settings-card-header">
                            <div class="settings-card-icon" style="background: linear-gradient(135deg, #34a853 0%, #2d9148 100%);">
                                <i class="fas fa-users-cog"></i>
                            </div>
                            <div>
                                <h3 class="settings-card-title">User Management</h3>
                                <p class="settings-card-subtitle">Control user permissions</p>
                            </div>
                        </div>
                        <div class="settings-card-body">
                            <div class="control-group">
                                <div class="control-group-header">
                                    <div class="control-icon">
                                        <i class="fas fa-image"></i>
                                    </div>
                                    <div class="control-info">
                                        <div class="control-title">Profile Image Uploads</div>
                                        <div class="control-desc">Allow users to upload profile pictures</div>
                                    </div>
                                </div>
                                <div class="control-toggle-wrapper">
                                    <div class="toggle-switch-large">
                                        <input type="checkbox" id="allowUserUploads" class="toggle-input-large">
                                        <label for="allowUserUploads" class="toggle-label-large">
                                            <span class="toggle-slider-large"></span>
                                        </label>
                                    </div>
                                    <div class="toggle-status" id="uploadToggleStatus">
                                        <span class="status-dot status-disabled"></span>
                                        <span class="status-text">Disabled</span>
                                    </div>
                                </div>
                            </div>
                            <div class="info-banner">
                                <i class="fas fa-info-circle"></i>
                                <span>When disabled, only administrators can manage all profile images</span>
                            </div>
                        </div>
                    </div>

                    <!-- Card 3: Security Status -->
                    <div class="settings-mega-card">
                        <div class="settings-card-header">
                            <div class="settings-card-icon" style="background: linear-gradient(135deg, #0074D9 0%, #0056a8 100%);">
                                <i class="fas fa-shield-alt"></i>
                            </div>
                            <div>
                                <h3 class="settings-card-title">Security Status</h3>
                                <p class="settings-card-subtitle">Active security features</p>
                            </div>
                        </div>
                        <div class="settings-card-body">
                            <div class="security-feature">
                                <div class="security-feature-icon">
                                    <i class="fas fa-check-circle"></i>
                                </div>
                                <div class="security-feature-content">
                                    <div class="security-feature-name">Session Security</div>
                                    <div class="security-feature-desc">HTTPOnly cookies & Strict SameSite</div>
                                </div>
                                <div class="security-feature-badge active">
                                    <i class="fas fa-shield-alt"></i> Active
                                </div>
                            </div>
                            <div class="security-feature">
                                <div class="security-feature-icon">
                                    <i class="fas fa-lock"></i>
                                </div>
                                <div class="security-feature-content">
                                    <div class="security-feature-name">Password Encryption</div>
                                    <div class="security-feature-desc">Bcrypt (PASSWORD_DEFAULT)</div>
                                </div>
                                <div class="security-feature-badge active">
                                    <i class="fas fa-shield-alt"></i> Active
                                </div>
                            </div>
                            <div class="security-feature">
                                <div class="security-feature-icon">
                                    <i class="fas fa-user-clock"></i>
                                </div>
                                <div class="security-feature-content">
                                    <div class="security-feature-name">Rate Limiting</div>
                                    <div class="security-feature-desc">User & IP tracking enabled</div>
                                </div>
                                <div class="security-feature-badge active">
                                    <i class="fas fa-shield-alt"></i> Active
                                </div>
                            </div>
                            <div class="security-feature">
                                <div class="security-feature-icon">
                                    <i class="fas fa-code"></i>
                                </div>
                                <div class="security-feature-content">
                                    <div class="security-feature-name">CSRF Protection</div>
                                    <div class="security-feature-desc">Token-based validation</div>
                                </div>
                                <div class="security-feature-badge active">
                                    <i class="fas fa-shield-alt"></i> Active
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Card 4: Server Information -->
                    <div class="settings-mega-card">
                        <div class="settings-card-header">
                            <div class="settings-card-icon" style="background: linear-gradient(135deg, #fbbc04 0%, #e0a800 100%);">
                                <i class="fas fa-server"></i>
                            </div>
                            <div>
                                <h3 class="settings-card-title">Server Information</h3>
                                <p class="settings-card-subtitle">Environment details</p>
                            </div>
                        </div>
                        <div class="settings-card-body">
                            <div class="server-info-item">
                                <div class="server-info-label">
                                    <i class="fas fa-database"></i>
                                    <span>Database Host</span>
                                </div>
                                <div class="server-info-value"><?php echo DB_HOST; ?></div>
                            </div>
                            <div class="server-info-item">
                                <div class="server-info-label">
                                    <i class="fas fa-user"></i>
                                    <span>Database User</span>
                                </div>
                                <div class="server-info-value"><?php echo DB_USER; ?></div>
                            </div>
                            <div class="server-info-item">
                                <div class="server-info-label">
                                    <i class="fas fa-code"></i>
                                    <span>PHP Version</span>
                                </div>
                                <div class="server-info-value">
                                    <span class="version-badge"><?php echo phpversion(); ?></span>
                                </div>
                            </div>
                            <div class="server-info-item">
                                <div class="server-info-label">
                                    <i class="fas fa-hdd"></i>
                                    <span>Max Upload</span>
                                </div>
                                <div class="server-info-value">
                                    <span class="upload-badge"><?php echo ini_get('upload_max_filesize'); ?></span>
                                </div>
                            </div>
                            <div class="server-info-item">
                                <div class="server-info-label">
                                    <i class="fas fa-clock"></i>
                                    <span>Server Time</span>
                                </div>
                                <div class="server-info-value time-value"><?php echo date('Y-m-d H:i:s'); ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- Card 5: AI Configuration (Admin Only) -->
                    <?php if ($role === 'Admin'): ?>
                    <div class="settings-mega-card" style="grid-column: 1 / -1;">
                        <div class="settings-card-header">
                            <div class="settings-card-icon" style="background: linear-gradient(135deg, var(--navy-primary) 0%, var(--navy-dark) 100%);">
                                <i class="fas fa-brain"></i>
                            </div>
                            <div>
                                <h3 class="settings-card-title">AI Configuration</h3>
                                <p class="settings-card-subtitle">Configure Gemini AI for business analytics</p>
                            </div>
                        </div>
                        <div class="settings-card-body">
                            <!-- Enable/Disable Toggle — Prominent Card -->
                            <div id="aiToggleCard" style="display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-radius:8px;margin-bottom:20px;cursor:pointer;transition:all 0.3s;" onclick="document.getElementById('aiEnabled').click();">
                                <div style="display:flex;align-items:center;gap:14px;">
                                    <div id="aiToggleIcon" style="width:44px;height:44px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:20px;transition:all 0.3s;">
                                        <i class="fas fa-power-off"></i>
                                    </div>
                                    <div>
                                        <div style="font-size:15px;font-weight:700;color:var(--text-primary);" id="aiToggleLabel">AI Features Enabled</div>
                                        <div style="font-size:12px;color:var(--text-muted);margin-top:2px;" id="aiToggleSub">All AI-powered insights and reports are active</div>
                                    </div>
                                </div>
                                <label class="toggle-switch" onclick="event.stopPropagation();">
                                    <input type="checkbox" id="aiEnabled" onchange="updateAIToggleUI(); saveAISettings();">
                                    <span class="toggle-slider"></span>
                                </label>
                            </div>

                            <!-- API Key Input -->
                            <div class="control-group">
                                <label style="font-size:14px;font-weight:600;color:var(--text-primary);margin-bottom:8px;display:block;">
                                    <i class="fas fa-key" style="color:var(--navy-accent);margin-right:6px;"></i>Gemini API Key
                                </label>
                                <div style="display:flex;gap:8px;">
                                    <input type="password" id="geminiApiKey" placeholder="Enter your Gemini API key..." style="flex:1;padding:10px 14px;border:1px solid var(--input-border);border-radius:4px;font-size:14px;background:var(--input-bg);color:var(--text-primary);">
                                    <button type="button" class="btn btn-secondary btn-sm" onclick="toggleKeyVisibility()" id="toggleKeyBtn" style="min-width:40px;"><i class="fas fa-eye"></i></button>
                                </div>
                                <div id="apiKeyStatus" style="font-size:12px;margin-top:6px;color:var(--text-muted);"></div>
                            </div>

                            <!-- Model Selector -->
                            <div class="control-group" style="margin-top:16px;">
                                <label style="font-size:14px;font-weight:600;color:var(--text-primary);margin-bottom:8px;display:block;">
                                    <i class="fas fa-microchip" style="color:var(--navy-accent);margin-right:6px;"></i>Gemini Model
                                </label>
                                <select id="geminiModel" style="width:100%;padding:10px 14px;border:1px solid var(--input-border);border-radius:4px;font-size:14px;background:var(--input-bg);color:var(--text-primary);">
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
                                <div style="font-size:11px;color:var(--text-muted);margin-top:6px;"><i class="fas fa-info-circle"></i> Gemini 2.0 Flash is recommended for best speed/cost balance</div>
                            </div>

                            <!-- Action Buttons -->
                            <div style="display:flex;gap:10px;margin-top:20px;">
                                <button class="btn btn-primary" onclick="saveAISettings()" style=""><i class="fas fa-save"></i> Save AI Settings</button>
                                <button class="btn btn-secondary" onclick="testAIConnection()" id="testBtn"><i class="fas fa-plug"></i> Test Connection</button>
                            </div>

                            <!-- Test Result -->
                            <div id="aiTestResult" style="display:none;margin-top:12px;padding:12px 16px;border-radius:6px;font-size:13px;"></div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Card 6: Company Information -->
                    <div class="settings-mega-card" style="grid-column: 1 / -1;">
                        <div class="settings-card-header">
                            <i class="fas fa-building"></i>
                            <h3>Company / Cooperative Information</h3>
                        </div>
                        <div class="settings-card-body">
                            <div class="form-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                                <div class="form-group">
                                    <label><i class="fas fa-signature"></i> Company Name</label>
                                    <input type="text" id="companyName" maxlength="200" placeholder="Company name">
                                </div>
                                <div class="form-group">
                                    <label><i class="fas fa-tag"></i> Subtitle / Description</label>
                                    <input type="text" id="companySubtitle" maxlength="200" placeholder="e.g. Negoce de Noix de Cajou">
                                </div>
                                <div class="form-group" style="grid-column: 1 / -1;">
                                    <label><i class="fas fa-map-marker-alt"></i> Address</label>
                                    <textarea id="companyAddress" rows="2" maxlength="500" placeholder="Company address" style="font-size:16px !important;"></textarea>
                                </div>
                                <div class="form-group">
                                    <label><i class="fas fa-phone"></i> Phone</label>
                                    <input type="text" id="companyPhone" maxlength="50" placeholder="Phone number">
                                </div>
                                <div class="form-group">
                                    <label><i class="fas fa-envelope"></i> Email</label>
                                    <input type="email" id="companyEmail" maxlength="200" placeholder="Email address">
                                </div>
                                <div class="form-group" style="grid-column: 1 / -1;">
                                    <label><i class="fas fa-image"></i> Login Page Logo URL</label>
                                    <div style="display:flex;gap:8px;align-items:center;">
                                        <input type="url" id="loginLogoUrl" name="login_logo_url" maxlength="500" placeholder="https://example.com/logo.png" style="flex:1;">
                                        <img id="loginLogoPreview" src="" alt="" style="width:40px;height:40px;border-radius:50%;object-fit:cover;border:2px solid var(--border-color);display:none;">
                                    </div>
                                    <small style="color:var(--text-muted);">Paste an image URL to use as the login page logo. Leave empty for default.</small>
                                </div>
                                <div class="form-group" style="grid-column: 1 / -1;">
                                    <label><i class="fas fa-stamp"></i> Receipt / Contract Logo URL</label>
                                    <div style="display:flex;gap:8px;align-items:center;">
                                        <input type="url" id="receiptLogoUrl" name="receipt_logo_url" maxlength="500" placeholder="https://example.com/receipt-logo.png" style="flex:1;">
                                        <img id="receiptLogoPreview" src="" alt="" style="width:40px;height:40px;border-radius:4px;object-fit:contain;border:2px solid var(--border-color);display:none;">
                                    </div>
                                    <small style="color:var(--text-muted);">This logo appears on printed purchase receipts and financing contracts. Recommended: square or landscape, max 200px wide.</small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Card 7: Business Parameters -->
                    <div class="settings-mega-card">
                        <div class="settings-card-header">
                            <i class="fas fa-sliders"></i>
                            <h3>Business Parameters</h3>
                        </div>
                        <div class="settings-card-body">
                            <div class="form-group">
                                <label><i class="fas fa-bullseye"></i> Target Profit per Kg</label>
                                <input type="number" id="targetProfitPerKg" step="0.01" min="0" placeholder="30">
                                <small style="color:var(--text-muted);">Used in Profit Analysis to determine performance status</small>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-money-bill"></i> Default Currency Symbol</label>
                                <select id="defaultCurrency" style="font-size:16px !important;">
                                    <option value="FCFA">FCFA (CFA Franc)</option>
                                    <option value="$">$ (US Dollar)</option>
                                    <option value="€">€ (Euro)</option>
                                    <option value="CAD $">CAD $ (Canadian Dollar)</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-language"></i> Default Language</label>
                                <select id="defaultLanguage" style="font-size:16px !important;">
                                    <option value="en">English</option>
                                    <option value="fr">Fran&ccedil;ais</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div style="grid-column: 1 / -1; text-align: right; margin-top: 10px;">
                        <button class="btn btn-primary" onclick="saveCompanySettings()">
                            <i class="fas fa-save"></i> Save Company & Business Settings
                        </button>
                    </div>

                </div>
            </div>
        </div>
    </div>

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
            const statusElement = document.getElementById('uploadToggleStatus');
            const statusDot = statusElement.querySelector('.status-dot');
            const statusText = statusElement.querySelector('.status-text');

            if (isEnabled) {
                statusDot.classList.remove('status-disabled');
                statusDot.classList.add('status-enabled');
                statusText.textContent = 'Enabled';
                statusText.style.color = 'var(--success)';
            } else {
                statusDot.classList.remove('status-enabled');
                statusDot.classList.add('status-disabled');
                statusText.textContent = 'Disabled';
                statusText.style.color = 'var(--text-muted)';
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
            if (!card) return;
            if (enabled) {
                card.style.background = 'rgba(52,168,83,0.08)';
                card.style.border = '2px solid rgba(52,168,83,0.3)';
                icon.style.background = 'rgba(52,168,83,0.15)';
                icon.style.color = '#34a853';
                label.textContent = 'AI Features Enabled';
                sub.textContent = 'All AI-powered insights and reports are active';
            } else {
                card.style.background = 'rgba(234,67,53,0.05)';
                card.style.border = '2px solid rgba(234,67,53,0.2)';
                icon.style.background = 'rgba(234,67,53,0.12)';
                icon.style.color = '#ea4335';
                label.textContent = 'AI Features Disabled';
                sub.textContent = 'AI insights and reports are turned off';
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
