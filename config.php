<?php
/**
 * Database Configuration File
 */

// Error handling - disable display_errors in production
error_reporting(E_ALL);
ini_set('display_errors', 0);  // Changed to 0 for production security
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error_log.txt');

// Database credentials
define('DB_HOST', 'localhost');
define('DB_NAME', 'u247530633_Valenthino');
define('DB_USER', 'u247530633_Valenthino');
define('DB_PASS', 'U247530633_Valenthino');

// Security constants
define('SESSION_TIMEOUT', 1800); // 30 minutes
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_TIME', 900); // 15 minutes

// Secure session configuration
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_secure', 1);
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.use_strict_mode', 1);
    ini_set('session.sid_length', 48);
    ini_set('session.cookie_lifetime', 0);
    session_start();
}

// Set HTTP security headers
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Permissions-Policy: geolocation=(), microphone=(), camera=()");

// Valid roles constant
define('VALID_ROLES', ['Admin','Manager','Procurement Officer','Sales Officer','Finance Officer','Fleet Manager','Warehouse Clerk']);

// Create database connection
function getDBConnection() {
    try {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

        if ($conn->connect_error) {
            error_log("Database connection failed: " . $conn->connect_error);
            die("Database connection failed. Please try again later.");
        }

        $conn->set_charset("utf8mb4");
        return $conn;
    } catch (Exception $e) {
        error_log("Database error: " . $e->getMessage());
        die("Database error occurred. Please contact administrator.");
    }
}

// CSRF Protection Functions
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Session timeout check
function checkSessionTimeout() {
    if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY'] > SESSION_TIMEOUT)) {
        session_unset();
        session_destroy();
        return false;
    }
    $_SESSION['LAST_ACTIVITY'] = time();
    return true;
}

// Input validation functions
function validateEmail($email) {
    $email = trim($email);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    if (strlen($email) > 200) {
        return false;
    }
    return $email;
}

function validatePassword($password) {
    if (strlen($password) < 6 || strlen($password) > 255) {
        return false;
    }
    return $password;
}

// Rate limiting functions
function checkLoginAttempts($email) {
    $conn = getDBConnection();
    $stmt = $conn->prepare("CREATE TABLE IF NOT EXISTS login_attempts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(200) NOT NULL,
        attempt_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        ip_address VARCHAR(45) NOT NULL,
        INDEX idx_email (email),
        INDEX idx_attempt_time (attempt_time)
    )");
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("SELECT COUNT(*) as attempts FROM login_attempts
                           WHERE email = ? AND attempt_time > DATE_SUB(NOW(), INTERVAL ? SECOND)");
    $lockout = LOGIN_LOCKOUT_TIME;
    $stmt->bind_param("si", $email, $lockout);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    $conn->close();

    return $row['attempts'] >= MAX_LOGIN_ATTEMPTS;
}

function recordLoginAttempt($email) {
    $conn = getDBConnection();
    $ip = $_SERVER['REMOTE_ADDR'];
    $stmt = $conn->prepare("INSERT INTO login_attempts (email, ip_address) VALUES (?, ?)");
    $stmt->bind_param("ss", $email, $ip);
    $stmt->execute();
    $stmt->close();
    $conn->close();
}

function clearLoginAttempts($email) {
    $conn = getDBConnection();
    $stmt = $conn->prepare("DELETE FROM login_attempts WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $stmt->close();
    $conn->close();
}

// ============================================================
// Transaction ID Generation (PREFIX-YY-MMDD-XXXX-C with Luhn)
// ============================================================

/**
 * Compute Luhn check digit for a numeric string
 */
function luhnCheckDigit($number) {
    $digits = str_split(strval($number));
    $sum = 0;
    $parity = count($digits) % 2;
    foreach ($digits as $i => $digit) {
        $d = intval($digit);
        if ($i % 2 == $parity) {
            $d *= 2;
            if ($d > 9) $d -= 9;
        }
        $sum += $d;
    }
    return (10 - ($sum % 10)) % 10;
}

/**
 * Generate a transaction ID in format: PREFIX-YY-MMDD-XXXX-C
 * @param mysqli $conn  Active DB connection
 * @param string $prefix  French prefix (ACH, LIV, VTE, FIN, PAI, DEP, APC, APF)
 * @param string $table   Table name
 * @param string $idColumn  Primary key column name
 * @return string  Generated ID
 */
function generateTransactionId($conn, $prefix, $table, $idColumn) {
    $yy = date('y');
    $mmdd = date('md');

    // Find max sequence number across all existing records
    $maxSeq = 0;
    $result = $conn->query("SELECT $idColumn FROM $table");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $id = $row[$idColumn];
            $parts = explode('-', $id);
            $cnt = count($parts);
            if ($cnt >= 5) {
                // New format: PREFIX-YY-MMDD-XXXX-C → XXXX is second-to-last
                $seq = intval($parts[$cnt - 2]);
            } elseif ($cnt == 2) {
                // Old format: PREFIX-XXXX
                $seq = intval($parts[1]);
            } else {
                continue;
            }
            if ($seq > $maxSeq) $maxSeq = $seq;
        }
    }

    $seq = $maxSeq + 1;
    $seqStr = str_pad($seq, 4, '0', STR_PAD_LEFT);

    $numericPayload = $yy . $mmdd . $seqStr;
    $checkDigit = luhnCheckDigit($numericPayload);

    return $prefix . '-' . $yy . '-' . $mmdd . '-' . $seqStr . '-' . $checkDigit;
}

// Activity Logging Functions
function logActivity($user_id, $username, $action, $details = '') {
    try {
        $conn = getDBConnection();

        // Create activity_logs table if it doesn't exist
        $createTable = "CREATE TABLE IF NOT EXISTS activity_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            username VARCHAR(150) NOT NULL,
            action VARCHAR(100) NOT NULL,
            details TEXT,
            ip_address VARCHAR(45) NOT NULL,
            timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_id (user_id),
            INDEX idx_username (username),
            INDEX idx_action (action),
            INDEX idx_timestamp (timestamp)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $conn->query($createTable);

        // Insert log entry
        $ip = $_SERVER['REMOTE_ADDR'];
        $stmt = $conn->prepare("INSERT INTO activity_logs (user_id, username, action, details, ip_address) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("issss", $user_id, $username, $action, $details, $ip);
        $stmt->execute();
        $stmt->close();
        $conn->close();

        return true;
    } catch (Exception $e) {
        error_log("Activity logging error: " . $e->getMessage());
        return false;
    }
}

function getActivityLogs($user_id = null, $role = 'Warehouse Clerk', $limit = null) {
    try {
        $conn = getDBConnection();

        // Admin/Manager sees all logs, others see only their own
        if (in_array($role, ['Admin', 'Manager']) && $user_id === null) {
            $sql = "SELECT * FROM activity_logs ORDER BY timestamp DESC";
            if ($limit) {
                $sql .= " LIMIT ?";
            }
            $stmt = $conn->prepare($sql);
            if ($limit) {
                $stmt->bind_param("i", $limit);
            }
        } else {
            $sql = "SELECT * FROM activity_logs WHERE user_id = ? ORDER BY timestamp DESC";
            if ($limit) {
                $sql .= " LIMIT ?";
            }
            $stmt = $conn->prepare($sql);
            if ($limit) {
                $stmt->bind_param("ii", $user_id, $limit);
            } else {
                $stmt->bind_param("i", $user_id);
            }
        }

        $stmt->execute();
        $result = $stmt->get_result();

        $logs = [];
        while ($row = $result->fetch_assoc()) {
            $logs[] = $row;
        }

        $stmt->close();
        $conn->close();

        return $logs;
    } catch (Exception $e) {
        error_log("Get activity logs error: " . $e->getMessage());
        return [];
    }
}

// System Settings Functions
function getSetting($key, $default = null) {
    try {
        $conn = getDBConnection();
        $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $stmt->bind_param("s", $key);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $value = $row['setting_value'];
            $stmt->close();
            $conn->close();
            return $value;
        }

        $stmt->close();
        $conn->close();
        return $default;
    } catch (Exception $e) {
        error_log("Get setting error: " . $e->getMessage());
        return $default;
    }
}

function setSetting($key, $value) {
    try {
        $conn = getDBConnection();
        $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
                               ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->bind_param("sss", $key, $value, $value);
        $result = $stmt->execute();
        $stmt->close();
        $conn->close();
        return $result;
    } catch (Exception $e) {
        error_log("Set setting error: " . $e->getMessage());
        return false;
    }
}

// Batch fetch multiple settings in one query
function getSettingsBatch($keys) {
    try {
        $conn = getDBConnection();
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $types = str_repeat('s', count($keys));
        $stmt = $conn->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ($placeholders)");
        $stmt->bind_param($types, ...$keys);
        $stmt->execute();
        $result = $stmt->get_result();
        $settings = [];
        while ($row = $result->fetch_assoc()) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
        $stmt->close();
        $conn->close();
        return $settings;
    } catch (Exception $e) {
        error_log("Get settings batch error: " . $e->getMessage());
        return [];
    }
}

// Get the currently active season name
function getActiveSeason() {
    try {
        $conn = getDBConnection();
        $result = $conn->query("SELECT season_name FROM settings_seasons WHERE is_active = 1 LIMIT 1");
        if ($result && $row = $result->fetch_assoc()) {
            $season = $row['season_name'];
            $conn->close();
            return $season;
        }
        $conn->close();
        return '2025/2026'; // fallback
    } catch (Exception $e) {
        error_log("Get active season error: " . $e->getMessage());
        return '2025/2026';
    }
}

// Get all season names (active first, then by id desc) — for dropdowns
function getAllSeasons() {
    try {
        $conn = getDBConnection();
        $rows = [];
        $res = $conn->query("SELECT season_name, is_active FROM settings_seasons ORDER BY is_active DESC, season_id DESC");
        if ($res) { while ($r = $res->fetch_assoc()) $rows[] = $r['season_name']; }
        $conn->close();
        if (empty($rows)) $rows = ['2025/2026'];
        return $rows;
    } catch (Exception $e) {
        error_log("Get all seasons error: " . $e->getMessage());
        return ['2025/2026'];
    }
}

// Render a <select> for season fields — kept the same id/name as the old <input> so JS keeps working
function renderSeasonDropdown($id = 'season', $name = 'season', $selected = null, $required = true) {
    $seasons = getAllSeasons();
    if ($selected === null) $selected = getActiveSeason();
    $req = $required ? 'required' : '';
    $html  = '<select id="' . htmlspecialchars($id) . '" name="' . htmlspecialchars($name) . '" ' . $req . '>';
    foreach ($seasons as $s) {
        $sel = ($s === $selected) ? 'selected' : '';
        $html .= '<option value="' . htmlspecialchars($s) . '" ' . $sel . '>' . htmlspecialchars($s) . '</option>';
    }
    $html .= '</select>';
    return $html;
}

// Get company information from system_settings
function getCompanyInfo() {
    $keys = ['company_name', 'company_subtitle', 'company_address', 'company_phone', 'company_email', 'default_currency_symbol', 'receipt_logo_url'];
    $settings = getSettingsBatch($keys);
    return [
        'company_name' => $settings['company_name'] ?? '7503 Canada',
        'company_subtitle' => $settings['company_subtitle'] ?? 'Negoce de Noix de Cajou Brutes',
        'company_address' => $settings['company_address'] ?? 'Daloa, Cote d\'Ivoire',
        'company_phone' => $settings['company_phone'] ?? '',
        'company_email' => $settings['company_email'] ?? '',
        'currency_symbol' => $settings['default_currency_symbol'] ?? 'FCFA',
        'receipt_logo_url' => $settings['receipt_logo_url'] ?? ''
    ];
}

// Upload supplier photo (profile or ID)
function uploadSupplierPhoto($file, $supplier_id, $type = 'profile') {
    $upload_dir = __DIR__ . '/uploads/suppliers/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $max_size = 2 * 1024 * 1024; // 2MB

    if ($file['size'] > $max_size) {
        return ['success' => false, 'message' => 'File size must be less than 2MB'];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mime_type, $allowed_types)) {
        return ['success' => false, 'message' => 'Invalid file type. Only JPG, PNG, GIF, and WEBP allowed'];
    }

    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, $allowed_extensions)) {
        return ['success' => false, 'message' => 'Invalid file extension'];
    }

    $filename = 'supplier_' . $type . '_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $supplier_id) . '_' . time() . '.' . $extension;
    $filepath = $upload_dir . $filename;

    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        return ['success' => true, 'filename' => 'uploads/suppliers/' . $filename];
    }

    return ['success' => false, 'message' => 'Failed to upload file'];
}

// Profile Image Upload Functions
function uploadProfileImage($file, $user_id) {
    // Create uploads directory if it doesn't exist
    $upload_dir = __DIR__ . '/uploads/profiles/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    // Validate file
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $max_size = 2 * 1024 * 1024; // 2MB

    // Check file size
    if ($file['size'] > $max_size) {
        return ['success' => false, 'message' => 'File size must be less than 2MB'];
    }

    // Check file type by MIME
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mime_type, $allowed_types)) {
        return ['success' => false, 'message' => 'Invalid file type. Only JPG, PNG, GIF, and WEBP allowed'];
    }

    // Check extension
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, $allowed_extensions)) {
        return ['success' => false, 'message' => 'Invalid file extension'];
    }

    // Generate unique filename
    $filename = 'profile_' . $user_id . '_' . time() . '.' . $extension;
    $filepath = $upload_dir . $filename;

    // Move uploaded file
    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        return ['success' => true, 'filename' => 'uploads/profiles/' . $filename];
    }

    return ['success' => false, 'message' => 'Failed to upload file'];
}

function deleteProfileImage($filepath) {
    if ($filepath && file_exists(__DIR__ . '/' . $filepath)) {
        return unlink(__DIR__ . '/' . $filepath);
    }
    return false;
}

function getProfileImage($user_id) {
    try {
        $conn = getDBConnection();
        $stmt = $conn->prepare("SELECT profile_image FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $profile_image = $row['profile_image'];
            $stmt->close();
            $conn->close();
            return $profile_image;
        }

        $stmt->close();
        $conn->close();
        return null;
    } catch (Exception $e) {
        error_log("Get profile image error: " . $e->getMessage());
        return null;
    }
}

// Check if user account is active
function isUserActive($user_id) {
    try {
        $conn = getDBConnection();
        $stmt = $conn->prepare("SELECT is_active FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $active = (bool)$row['is_active'];
            $stmt->close();
            $conn->close();
            return $active;
        }

        $stmt->close();
        $conn->close();
        return false;
    } catch (Exception $e) {
        error_log("Check user active error: " . $e->getMessage());
        return false;
    }
}

// User Theme Preferences Functions
function getUserTheme($user_id) {
    $defaults = [
        'theme_primary' => '#001f3f',
        'theme_secondary' => '#003366',
        'theme_accent' => '#0074D9',
        'theme_mode' => 'light'
    ];

    try {
        $conn = getDBConnection();

        // First check if theme columns exist
        $check = $conn->query("SHOW COLUMNS FROM users LIKE 'theme_primary'");
        if (!$check || $check->num_rows == 0) {
            $conn->close();
            return $defaults;
        }

        $stmt = $conn->prepare("SELECT theme_primary, theme_secondary, theme_accent, theme_mode FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $stmt->close();
            $conn->close();
            return [
                'theme_primary' => $row['theme_primary'] ?? $defaults['theme_primary'],
                'theme_secondary' => $row['theme_secondary'] ?? $defaults['theme_secondary'],
                'theme_accent' => $row['theme_accent'] ?? $defaults['theme_accent'],
                'theme_mode' => $row['theme_mode'] ?? $defaults['theme_mode']
            ];
        }

        $stmt->close();
        $conn->close();
        return $defaults;
    } catch (Exception $e) {
        error_log("Get user theme error: " . $e->getMessage());
        return $defaults;
    }
}

function setUserTheme($user_id, $theme_primary, $theme_secondary, $theme_accent, $theme_mode) {
    try {
        $conn = getDBConnection();

        // Check if theme columns exist
        $check = $conn->query("SHOW COLUMNS FROM users LIKE 'theme_primary'");
        if (!$check || $check->num_rows == 0) {
            $conn->close();
            return false; // Columns don't exist, need to run setup.php first
        }

        $stmt = $conn->prepare("UPDATE users SET theme_primary = ?, theme_secondary = ?, theme_accent = ?, theme_mode = ? WHERE id = ?");
        $stmt->bind_param("ssssi", $theme_primary, $theme_secondary, $theme_accent, $theme_mode, $user_id);
        $result = $stmt->execute();
        $stmt->close();
        $conn->close();
        return $result;
    } catch (Exception $e) {
        error_log("Set user theme error: " . $e->getMessage());
        return false;
    }
}

// Generate CSS variables for user theme
function generateUserThemeCSS($user_id) {
    $theme = getUserTheme($user_id);

    // Calculate hover color (slightly lighter/darker)
    $primary = $theme['theme_primary'];

    $css = "<style id='user-theme-css'>\n";
    $css .= ":root {\n";
    $css .= "    --navy-primary: {$theme['theme_primary']};\n";
    $css .= "    --navy-light: {$theme['theme_secondary']};\n";
    $css .= "    --navy-accent: {$theme['theme_accent']};\n";
    $css .= "    --navy-dark: {$theme['theme_primary']};\n";
    $css .= "    --navy-hover: {$theme['theme_secondary']};\n";
    $css .= "}\n";
    $css .= "</style>\n";

    return $css;
}

/**
 * Canonical supplier reconciler — single source of truth.
 *
 * Treats every cash event for the supplier as one ledger:
 *   cashPool   = manual outgoing advances + direct outgoing payments (type=Purchase)
 *                - incoming repayments (type=Repayment)
 *   netBalance = cashPool - SUM(purchases.total_cost)
 *     positive = supplier owes us (Net Advance)
 *     negative = we owe supplier (Payable)
 *
 * Then it (a) walks purchases by date FIFO and sets payment_status from cashPool,
 * (b) walks manual advances by date FIFO and sets amount_repaid/balance_due/status
 * from goods+repayments consumed, (c) rebuilds the Auto-Overpayment / Auto-Payable
 * derived rows so SUM(Outgoing balance_due) - SUM(Incoming balance_due) = netBalance,
 * and (d) writes netBalance to suppliers.financing_balance.
 *
 * Call this after every mutation that touches a supplier's purchases, payments,
 * or financing rows. It is idempotent — running it twice yields the same result.
 */
function reconcileSupplierAccount($conn, $supplierId) {
    if (!$supplierId) return;

    // purchases for supplier, chronological
    $stmt = $conn->prepare("SELECT purchase_id, total_cost, linked_financing_id, payment_status FROM purchases WHERE supplier_id = ? ORDER BY date ASC, purchase_id ASC");
    $stmt->bind_param("s", $supplierId);
    $stmt->execute();
    $purchases = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // manual outgoing advances, chronological
    $stmt = $conn->prepare("SELECT financing_id, amount, carried_over_balance FROM financing WHERE counterparty_id = ? AND counterpart_type = 'Supplier' AND direction = 'Outgoing' AND source = 'Manual' ORDER BY date ASC, financing_id ASC");
    $stmt->bind_param("s", $supplierId);
    $stmt->execute();
    $advances = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // direct cash payments to supplier (Outgoing, type=Purchase)
    // we exclude payment_type='Financing' because addPayment auto-creates a financing row for those
    $stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE counterpart_id = ? AND direction = 'Outgoing' AND payment_type = 'Purchase'");
    $stmt->bind_param("s", $supplierId);
    $stmt->execute();
    $directOut = floatval($stmt->get_result()->fetch_row()[0]);
    $stmt->close();

    // repayments from supplier (Incoming, type=Repayment) — supplier paying back the advance
    $stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE counterpart_id = ? AND direction = 'Incoming' AND payment_type = 'Repayment'");
    $stmt->bind_param("s", $supplierId);
    $stmt->execute();
    $repayments = floatval($stmt->get_result()->fetch_row()[0]);
    $stmt->close();

    // totals
    $totalAdvances = 0.0;
    foreach ($advances as $a) {
        $totalAdvances += floatval($a['amount']) + floatval($a['carried_over_balance']);
    }
    $totalGoods = 0.0;
    foreach ($purchases as $p) {
        $totalGoods += floatval($p['total_cost']);
    }
    $cashPool   = round($totalAdvances + $directOut - $repayments, 2);
    $netBalance = round($cashPool - $totalGoods, 2);

    // (a) walk purchases FIFO, distribute cashPool, set payment_status
    $remain = $cashPool;
    foreach ($purchases as $p) {
        $cost = floatval($p['total_cost']);
        $hasFin = !empty($p['linked_financing_id']);
        if ($cost <= 0) {
            $newStatus = 'Pending';
        } elseif ($remain >= $cost - 0.005) {
            $newStatus = $hasFin ? 'Prefinanced' : 'Paid';
            $remain = round($remain - $cost, 2);
        } elseif ($remain > 0.005) {
            $newStatus = 'Partial';
            $remain = 0;
        } else {
            $newStatus = 'Pending';
        }
        if ($p['payment_status'] !== $newStatus) {
            $u = $conn->prepare("UPDATE purchases SET payment_status = ? WHERE purchase_id = ?");
            $u->bind_param("ss", $newStatus, $p['purchase_id']);
            $u->execute();
            $u->close();
        }
    }

    // (b) walk manual advances FIFO, distribute (goods + repayments)
    $consume = round($totalGoods + $repayments, 2);
    foreach ($advances as $a) {
        $advAmt = round(floatval($a['amount']) + floatval($a['carried_over_balance']), 2);
        if ($advAmt <= 0) continue;

        if ($consume >= $advAmt - 0.005) {
            $newRepaid = $advAmt;
            $newBal    = 0.0;
            $newSt     = 'Settled';
            $consume   = round($consume - $advAmt, 2);
        } elseif ($consume > 0.005) {
            $newRepaid = round($consume, 2);
            $newBal    = round($advAmt - $consume, 2);
            $newSt     = 'Active';
            $consume   = 0;
        } else {
            $newRepaid = 0.0;
            $newBal    = $advAmt;
            $newSt     = 'Active';
        }

        $u = $conn->prepare("UPDATE financing SET amount_repaid = ?, balance_due = ?, status = ? WHERE financing_id = ?");
        $u->bind_param("ddss", $newRepaid, $newBal, $newSt, $a['financing_id']);
        $u->execute();
        $u->close();
    }

    // (c) rebuild derived rows so financing-table balance equation matches netBalance
    //   remainingManual = max(0, totalAdvances - totalGoods - repayments)
    //   if netBalance > 0: autoOverpayment = netBalance - remainingManual (extra cash beyond advances)
    //   if netBalance < 0: autoPayable     = -netBalance (we owe supplier)
    $remainingManual = max(0, round($totalAdvances - $totalGoods - $repayments, 2));
    $autoOverpayment = ($netBalance > 0) ? max(0, round($netBalance - $remainingManual, 2)) : 0;
    $autoPayable     = ($netBalance < 0) ? round(-$netBalance, 2) : 0;

    upsertSupplierDerivedFinancing($conn, $supplierId, 'Outgoing', 'Auto-Overpayment', $autoOverpayment);
    upsertSupplierDerivedFinancing($conn, $supplierId, 'Incoming', 'Auto-Payable',     $autoPayable);

    // (d) write canonical net balance to suppliers row
    $us = $conn->prepare("UPDATE suppliers SET financing_balance = ? WHERE supplier_id = ?");
    $us->bind_param("ds", $netBalance, $supplierId);
    $us->execute();
    $us->close();
}

// helper for reconcileSupplierAccount — upsert/delete a derived (auto) financing row
function upsertSupplierDerivedFinancing($conn, $supplierId, $direction, $source, $amount) {
    $stmt = $conn->prepare("SELECT financing_id FROM financing WHERE counterparty_id = ? AND counterpart_type = 'Supplier' AND direction = ? AND source = ? ORDER BY created_at ASC LIMIT 1");
    $stmt->bind_param("sss", $supplierId, $direction, $source);
    $stmt->execute();
    $r = $stmt->get_result();
    $existing = $r->num_rows > 0 ? $r->fetch_assoc() : null;
    $stmt->close();

    if ($amount > 0.005) {
        if ($existing) {
            $st = 'Active'; $zero = 0.0;
            $u = $conn->prepare("UPDATE financing SET amount = ?, amount_repaid = ?, balance_due = ?, status = ? WHERE financing_id = ?");
            $u->bind_param("dddss", $amount, $zero, $amount, $st, $existing['financing_id']);
            $u->execute();
            $u->close();
        } else {
            // resolve supplier name
            $sn = '';
            $q = $conn->prepare("SELECT first_name FROM suppliers WHERE supplier_id = ?");
            $q->bind_param("s", $supplierId);
            $q->execute();
            $rs = $q->get_result();
            if ($rs->num_rows > 0) $sn = $rs->fetch_assoc()['first_name'];
            $q->close();

            $finId  = generateTransactionId($conn, 'FIN', 'financing', 'financing_id');
            $today  = date('Y-m-d');
            $ctype  = 'Supplier'; $zero = 0.0;
            $st     = 'Active';
            $note   = ($source === 'Auto-Overpayment') ? 'Auto: cash overpayment beyond advances' : 'Auto: supplier delivered excess over financing';
            $season = getActiveSeason();

            $ins = $conn->prepare("INSERT INTO financing (financing_id, date, direction, counterpart_type, counterparty_id, counterpart_name, carried_over_balance, amount, amount_repaid, current_market_price, expected_volume_kg, delivered_volume_kg, volume_remaining_kg, interest_per_kg, interest_amount, balance_due, status, season, notes, source) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $ins->bind_param("ssssssddddddddddssss",
                $finId, $today, $direction, $ctype, $supplierId, $sn,
                $zero, $amount, $zero, $zero,
                $zero, $zero, $zero,
                $zero, $zero, $amount, $st, $season, $note, $source
            );
            $ins->execute();
            $ins->close();
        }
    } else {
        if ($existing) {
            // settle (zero out) instead of deleting — keeps any payment links + audit trail intact
            $st = 'Settled'; $zero = 0.0;
            $d = $conn->prepare("UPDATE financing SET amount = ?, amount_repaid = ?, balance_due = ?, status = ? WHERE financing_id = ?");
            $d->bind_param("dddss", $zero, $zero, $zero, $st, $existing['financing_id']);
            $d->execute();
            $d->close();
        }
    }
}

// backward-compat wrapper — old call sites still work, now route through the canonical reconciler
function syncSupplierBalance($conn, $supplierId) {
    reconcileSupplierAccount($conn, $supplierId);
}

// customer overpayment → auto-create/update Incoming financing
// called when report opens or payment changes
function syncCustomerOverpayment($conn, $customerId, $customerName = '') {
    if (!$customerId) return;

    $stmt = $conn->prepare("SELECT COALESCE(SUM(gross_sale_amount), 0) FROM sales WHERE customer_id = ? AND sale_status IN ('Draft','Confirmed')");
    $stmt->bind_param("s", $customerId);
    $stmt->execute();
    $totalSales = floatval($stmt->get_result()->fetch_row()[0]);
    $stmt->close();

    $stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE counterpart_id = ? AND direction = 'Incoming'");
    $stmt->bind_param("s", $customerId);
    $stmt->execute();
    $totalPaid = floatval($stmt->get_result()->fetch_row()[0]);
    $stmt->close();

    $overpayment = round($totalPaid - $totalSales, 2);

    // check existing auto-overpayment
    $stmt = $conn->prepare("SELECT financing_id, amount FROM financing WHERE counterparty_id = ? AND counterpart_type = 'Customer' AND source = 'Auto-Overpayment' ORDER BY created_at DESC LIMIT 1");
    $stmt->bind_param("s", $customerId);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($overpayment > 0) {
        if ($existing) {
            $stmt = $conn->prepare("UPDATE financing SET amount = ?, balance_due = ?, status = 'Active' WHERE financing_id = ?");
            $stmt->bind_param("dds", $overpayment, $overpayment, $existing['financing_id']);
            $stmt->execute();
            $stmt->close();
        } else {
            if (!$customerName) {
                $stmt = $conn->prepare("SELECT customer_name FROM customers WHERE customer_id = ?");
                $stmt->bind_param("s", $customerId);
                $stmt->execute();
                $r = $stmt->get_result()->fetch_assoc();
                $customerName = $r ? $r['customer_name'] : '';
                $stmt->close();
            }
            $finId = generateTransactionId($conn, 'FIN', 'financing', 'financing_id');
            $today = date('Y-m-d');
            $dir = 'Incoming'; $ctype = 'Customer'; $zero = 0.0;
            $st = 'Active'; $src = 'Auto-Overpayment';
            $note = 'Auto-created from customer overpayment';
            $season = getActiveSeason();

            $stmt = $conn->prepare("INSERT INTO financing (financing_id, date, direction, counterpart_type, counterparty_id, counterpart_name, carried_over_balance, amount, amount_repaid, current_market_price, expected_volume_kg, delivered_volume_kg, volume_remaining_kg, interest_per_kg, interest_amount, balance_due, status, season, notes, source) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssssddddddddddssss",
                $finId, $today, $dir, $ctype, $customerId, $customerName,
                $zero, $overpayment, $zero, $zero,
                $zero, $zero, $zero,
                $zero, $zero, $overpayment, $st, $season, $note, $src
            );
            $stmt->execute();
            $stmt->close();
        }
    } else {
        // no overpayment — settle existing
        if ($existing) {
            $zero = 0.0; $settled = 'Settled';
            $stmt = $conn->prepare("UPDATE financing SET status = ?, balance_due = ?, amount = ?, amount_repaid = ? WHERE financing_id = ?");
            $stmt->bind_param("sddds", $settled, $zero, $zero, $zero, $existing['financing_id']);
            $stmt->execute();
            $stmt->close();
        }
    }
}
// ── Bag Log Helpers ──

// recompute all bag balances from scratch
function recomputeAllBagBalances($conn) {
    $stmt = $conn->prepare("SELECT bag_log_id, qty_in, qty_out FROM bags_log ORDER BY bag_log_id ASC");
    $stmt->execute();
    $rows = [];
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    $stmt->close();

    $upd = $conn->prepare("UPDATE bags_log SET previous_balance = ?, balance = ? WHERE bag_log_id = ?");
    $rb = 0;
    foreach ($rows as $r) {
        $prev = $rb;
        $rb = $prev + intval($r['qty_in']) - intval($r['qty_out']);
        $upd->bind_param("iii", $prev, $rb, $r['bag_log_id']);
        $upd->execute();
    }
    $upd->close();
}

// create a bag log entry, auto-compute balance
function logBagMovement($conn, $date, $customerId, $supplierId, $desc, $qtyIn, $qtyOut, $refNumber, $truckId, $driverName, $season) {
    // get last balance
    $stmt = $conn->prepare("SELECT balance FROM bags_log ORDER BY bag_log_id DESC LIMIT 1");
    $stmt->execute();
    $res = $stmt->get_result();
    $prev = ($res->num_rows > 0) ? intval($res->fetch_assoc()['balance']) : 0;
    $stmt->close();

    $bal = $prev + intval($qtyIn) - intval($qtyOut);

    // find jute bag type
    $bagTypeId = null;
    $bt = $conn->query("SELECT bag_type_id FROM settings_bag_types WHERE bag_type_name LIKE '%Jute%' LIMIT 1");
    if ($bt && $bt->num_rows > 0) $bagTypeId = intval($bt->fetch_assoc()['bag_type_id']);

    $stmt = $conn->prepare("INSERT INTO bags_log (date, customer_id, supplier_id, bag_type_id, description, previous_balance, qty_in, ref_number, qty_out, balance, truck_id, driver_name, season) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sssisiisissss", $date, $customerId, $supplierId, $bagTypeId, $desc, $prev, $qtyIn, $refNumber, $qtyOut, $bal, $truckId, $driverName, $season);
    $stmt->execute();
    $id = $conn->insert_id;
    $stmt->close();
    return $id;
}

// remove bag log entry by ref_number and recompute balances
function removeBagLogByRef($conn, $refNumber) {
    $stmt = $conn->prepare("SELECT bag_log_id FROM bags_log WHERE ref_number = ?");
    $stmt->bind_param("s", $refNumber);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows === 0) { $stmt->close(); return; }
    $stmt->close();

    $del = $conn->prepare("DELETE FROM bags_log WHERE ref_number = ?");
    $del->bind_param("s", $refNumber);
    $del->execute();
    $del->close();

    recomputeAllBagBalances($conn);
}

// update bag log qty for a ref_number, recompute balances
function updateBagLogByRef($conn, $refNumber, $qtyIn, $qtyOut) {
    $stmt = $conn->prepare("SELECT bag_log_id FROM bags_log WHERE ref_number = ?");
    $stmt->bind_param("s", $refNumber);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows === 0) { $stmt->close(); return; }
    $id = intval($res->fetch_assoc()['bag_log_id']);
    $stmt->close();

    $upd = $conn->prepare("UPDATE bags_log SET qty_in = ?, qty_out = ? WHERE bag_log_id = ?");
    $upd->bind_param("iii", $qtyIn, $qtyOut, $id);
    $upd->execute();
    $upd->close();

    recomputeAllBagBalances($conn);
}
?>
