<?php
/**
 * Developed by Rameez Scripts
 * WhatsApp: https://wa.me/923224083545 (For Custom Projects)
 * YouTube: https://www.youtube.com/@rameezimdad (Subscribe for more!)
 */

// Show errors during setup (override config.php suppression)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Load DB credentials from config — must be before any HTML output
require_once __DIR__ . '/config.php';

// Re-enable error display (config.php disables it)
ini_set('display_errors', 1);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <title>Database Setup</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="styles.css?v=4.0">
</head>
<body>
    <div class="setup-wrapper">
    <div class="setup-container">
        <h2><i class="fas fa-database"></i> Database Setup <span style="color:#e74c3c;font-size:14px;">(FRESH INSTALL ONLY)</span></h2>
        <p class="subtitle">This will <strong style="color:#e74c3c;">DROP ALL TABLES</strong> and recreate from scratch with demo data.<br>
        <strong>For existing databases, use <a href="update.php" style="color:var(--navy-accent);">update.php</a> instead — it preserves your data.</strong></p>
        <hr>

        <?php
        $errors = [];
        $success = [];

        // Create connection using config constants
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

        // Check connection
        if ($conn->connect_error) {
            echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Connection failed: ' . $conn->connect_error . '</div>';
            die();
        }

        echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Database connection successful</div>';

        // ============================================================
        // DROP ALL TABLES (clean slate)
        // ============================================================
        echo '<br><div class="log-item log-info"><i class="fas fa-trash-alt"></i> <strong>Dropping all existing tables...</strong></div>';

        $conn->query("SET FOREIGN_KEY_CHECKS = 0");

        $tables_to_drop = [
            'notifications',
            'fleet_paperworks',
            'driver_salary_payments',
            'lots',
            'settings_currencies',
            'settings_seasons',
            'bags_log',
            'supplier_pricing_agreements',
            'customer_pricing_agreements',
            'expenses',
            'payments',
            'financing',
            'sales',
            'delivery_items',
            'deliveries',
            'purchases',
            'fleet_vehicles',
            'suppliers',
            'customers',
            'banks',
            'settings_paperwork_types',
            'settings_bag_types',
            'settings_expense_categories',
            'settings_warehouses',
            'settings_supplier_types',
            'settings_contract_types',
            'settings_locations',
            'system_settings',
            'activity_logs',
            'login_attempts',
            'users'
        ];

        foreach ($tables_to_drop as $tbl) {
            if ($conn->query("DROP TABLE IF EXISTS $tbl") === TRUE) {
                echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Dropped table "' . $tbl . '" (if existed)</div>';
            } else {
                echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Error dropping "' . $tbl . '": ' . $conn->error . '</div>';
            }
        }

        $conn->query("SET FOREIGN_KEY_CHECKS = 1");
        echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> All tables dropped successfully</div>';

        // ============================================================
        // TABLE: users
        // ============================================================
        echo '<br><div class="log-item log-info"><i class="fas fa-users"></i> <strong>Creating Core Tables...</strong></div>';

        $sql = "CREATE TABLE users (
            id INT(11) AUTO_INCREMENT PRIMARY KEY,
            full_name VARCHAR(150) NOT NULL,
            email VARCHAR(200) NOT NULL UNIQUE,
            phone VARCHAR(20) DEFAULT NULL,
            password_hash VARCHAR(255) NOT NULL,
            role ENUM('Admin','Manager','Procurement Officer','Sales Officer','Finance Officer','Fleet Manager','Warehouse Clerk') NOT NULL DEFAULT 'Warehouse Clerk',
            is_active BOOLEAN DEFAULT TRUE,
            profile_image VARCHAR(255) DEFAULT NULL,
            theme_primary VARCHAR(20) DEFAULT '#001f3f',
            theme_secondary VARCHAR(20) DEFAULT '#003366',
            theme_accent VARCHAR(20) DEFAULT '#0074D9',
            theme_mode VARCHAR(10) DEFAULT 'light',
            language_preference VARCHAR(5) DEFAULT 'en',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            last_login TIMESTAMP NULL DEFAULT NULL,
            INDEX idx_email (email),
            INDEX idx_role (role),
            INDEX idx_is_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if ($conn->query($sql) === TRUE) {
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Table "users" created successfully</div>';
        } else {
            echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Error creating users table: ' . $conn->error . '</div>';
        }

        // ============================================================
        // Default admin user
        // ============================================================
        echo '<br><div class="log-item log-info"><i class="fas fa-user-shield"></i> <strong>Setting up default admin...</strong></div>';

        $default_email = 'admin@example.com';
        $default_name = 'Admin User';
        $default_password = password_hash('admin123', PASSWORD_DEFAULT);
        $default_role = 'Admin';

        $stmt = $conn->prepare("INSERT INTO users (full_name, email, password_hash, role) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssss", $default_name, $default_email, $default_password, $default_role);

        if ($stmt->execute()) {
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Default admin user created successfully</div>';
            echo '<div class="credentials-box">';
            echo '<strong><i class="fas fa-key"></i> Login Credentials:</strong><br><br>';
            echo '<strong>Email:</strong> admin@example.com<br>';
            echo '<strong>Password:</strong> admin123<br>';
            echo '<strong>Role:</strong> Admin<br><br>';
            echo '<em style="color: var(--warning);"><i class="fas fa-exclamation-triangle"></i> Please change the password after first login!</em>';
            echo '</div>';
        } else {
            echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Error creating admin user: ' . $stmt->error . '</div>';
        }
        $stmt->close();

        // ============================================================
        // TABLE: activity_logs
        // ============================================================
        $activity_logs_sql = "CREATE TABLE activity_logs (
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

        if ($conn->query($activity_logs_sql) === TRUE) {
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Table "activity_logs" created successfully</div>';
        } else {
            echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Error creating activity_logs table: ' . $conn->error . '</div>';
        }

        // ============================================================
        // TABLE: system_settings
        // ============================================================
        $settings_sql = "CREATE TABLE system_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(100) NOT NULL UNIQUE,
            setting_value TEXT NOT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_setting_key (setting_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if ($conn->query($settings_sql) === TRUE) {
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Table "system_settings" created successfully</div>';
            $conn->query("INSERT INTO system_settings (setting_key, setting_value) VALUES ('allow_user_profile_uploads', '1')");
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Default setting "allow_user_profile_uploads" created</div>';
        } else {
            echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Error creating system_settings table: ' . $conn->error . '</div>';
        }

        // ============================================================
        // TABLE: login_attempts
        // ============================================================
        $login_attempts_sql = "CREATE TABLE login_attempts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(200) NOT NULL,
            attempt_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            ip_address VARCHAR(45) NOT NULL,
            INDEX idx_email (email),
            INDEX idx_attempt_time (attempt_time)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if ($conn->query($login_attempts_sql) === TRUE) {
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Table "login_attempts" created successfully</div>';
        } else {
            echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Error creating login_attempts table: ' . $conn->error . '</div>';
        }

        // ============================================================
        // SETTINGS LOOKUP TABLES
        // ============================================================
        echo '<br><div class="log-item log-info"><i class="fas fa-cog"></i> <strong>Setting up Settings Lookup Tables...</strong></div>';

        // TABLE: settings_locations
        $locations_sql = "CREATE TABLE settings_locations (
            location_id INT AUTO_INCREMENT PRIMARY KEY,
            location_name VARCHAR(150) NOT NULL UNIQUE,
            is_active BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_location_name (location_name),
            INDEX idx_is_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if ($conn->query($locations_sql) === TRUE) {
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Table "settings_locations" created successfully</div>';
        } else {
            echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Error creating settings_locations table: ' . $conn->error . '</div>';
        }

        // TABLE: settings_contract_types
        $contract_types_sql = "CREATE TABLE settings_contract_types (
            contract_type_id INT AUTO_INCREMENT PRIMARY KEY,
            contract_type_name VARCHAR(100) NOT NULL UNIQUE,
            is_active BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_contract_type_name (contract_type_name),
            INDEX idx_is_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if ($conn->query($contract_types_sql) === TRUE) {
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Table "settings_contract_types" created successfully</div>';
        } else {
            echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Error creating settings_contract_types table: ' . $conn->error . '</div>';
        }

        // TABLE: settings_supplier_types
        $supplier_types_sql = "CREATE TABLE settings_supplier_types (
            supplier_type_id INT AUTO_INCREMENT PRIMARY KEY,
            supplier_type_name VARCHAR(100) NOT NULL UNIQUE,
            is_active BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_supplier_type_name (supplier_type_name),
            INDEX idx_is_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if ($conn->query($supplier_types_sql) === TRUE) {
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Table "settings_supplier_types" created successfully</div>';
        } else {
            echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Error creating settings_supplier_types table: ' . $conn->error . '</div>';
        }

        // TABLE: settings_warehouses
        $warehouses_sql = "CREATE TABLE settings_warehouses (
            warehouse_id INT AUTO_INCREMENT PRIMARY KEY,
            warehouse_name VARCHAR(150) NOT NULL UNIQUE,
            warehouse_code VARCHAR(5) NOT NULL DEFAULT '',
            location_id INT NOT NULL,
            is_active BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_warehouse_name (warehouse_name),
            INDEX idx_warehouse_code (warehouse_code),
            INDEX idx_location_id (location_id),
            INDEX idx_is_active (is_active),
            FOREIGN KEY (location_id) REFERENCES settings_locations(location_id) ON UPDATE CASCADE ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if ($conn->query($warehouses_sql) === TRUE) {
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Table "settings_warehouses" created successfully</div>';
        } else {
            echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Error creating settings_warehouses table: ' . $conn->error . '</div>';
        }

        // TABLE: settings_expense_categories
        $expense_categories_sql = "CREATE TABLE settings_expense_categories (
            category_id INT AUTO_INCREMENT PRIMARY KEY,
            category_name VARCHAR(100) NOT NULL UNIQUE,
            is_active BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_category_name (category_name),
            INDEX idx_is_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if ($conn->query($expense_categories_sql) === TRUE) {
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Table "settings_expense_categories" created successfully</div>';
        } else {
            echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Error creating settings_expense_categories table: ' . $conn->error . '</div>';
        }

        // TABLE: settings_bag_types
        $bag_types_sql = "CREATE TABLE settings_bag_types (
            bag_type_id INT AUTO_INCREMENT PRIMARY KEY,
            bag_type_name VARCHAR(100) NOT NULL UNIQUE,
            is_active BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_bag_type_name (bag_type_name),
            INDEX idx_is_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if ($conn->query($bag_types_sql) === TRUE) {
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Table "settings_bag_types" created successfully</div>';
        } else {
            echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Error creating settings_bag_types table: ' . $conn->error . '</div>';
        }

        // TABLE: settings_paperwork_types
        $paperwork_types_sql = "CREATE TABLE settings_paperwork_types (
            paperwork_type_id INT AUTO_INCREMENT PRIMARY KEY,
            paperwork_type_name VARCHAR(100) NOT NULL UNIQUE,
            is_active BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_paperwork_type_name (paperwork_type_name),
            INDEX idx_is_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if ($conn->query($paperwork_types_sql) === TRUE) {
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Table "settings_paperwork_types" created successfully</div>';
        } else {
            echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Error creating settings_paperwork_types table: ' . $conn->error . '</div>';
        }

        // ============================================================
        // SEED DATA: Settings
        // ============================================================
        echo '<br><div class="log-item log-info"><i class="fas fa-seedling"></i> <strong>Inserting seed data...</strong></div>';

        // Seed AI Settings
        $conn->query("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES ('gemini_api_key', '')");
        $conn->query("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES ('gemini_model', 'gemini-2.0-flash')");
        $conn->query("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES ('ai_enabled', '1')");
        echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> AI settings seeded</div>';

        // Seed Company & Business Settings
        $conn->query("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES ('company_name', '7503 Canada')");
        $conn->query("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES ('company_subtitle', 'Negoce de Noix de Cajou Brutes')");
        $conn->query("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES ('company_address', 'Daloa, Cote d''Ivoire')");
        $conn->query("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES ('company_phone', '')");
        $conn->query("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES ('company_email', '')");
        $conn->query("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES ('default_currency_symbol', 'FCFA')");
        $conn->query("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES ('default_language', 'fr')");
        $conn->query("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES ('target_profit_per_kg', '30')");
        echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Company & business settings seeded</div>';

        $seed_locations = ['Daloa', 'Seguela', 'Aladjkro', 'Vavoua', 'Blolequin', 'Abidjan', 'Yamoussoukro', 'San Pedro'];
        foreach ($seed_locations as $loc) {
            $conn->query("INSERT IGNORE INTO settings_locations (location_name) VALUES ('$loc')");
        }
        echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Seed locations inserted</div>';

        // Seed Warehouses
        $warehouse_seeds = [
            ['Daloa Central Warehouse', 'Daloa', 'DAL'],
            ['Seguela Warehouse', 'Seguela', 'SEG'],
            ['Aladjkro Warehouse', 'Aladjkro', 'ALK'],
            ['Vavoua Warehouse', 'Vavoua', 'VAV'],
            ['Blolequin Warehouse', 'Blolequin', 'BLO']
        ];

        foreach ($warehouse_seeds as $wh) {
            $loc_result = $conn->query("SELECT location_id FROM settings_locations WHERE location_name = '{$wh[1]}' LIMIT 1");
            if ($loc_result && $loc_result->num_rows > 0) {
                $loc_row = $loc_result->fetch_assoc();
                $loc_id = $loc_row['location_id'];
                $wh_name = $conn->real_escape_string($wh[0]);
                $wh_code = $conn->real_escape_string($wh[2]);
                $conn->query("INSERT IGNORE INTO settings_warehouses (warehouse_name, warehouse_code, location_id) VALUES ('$wh_name', '$wh_code', $loc_id)");
            }
        }
        echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Seed warehouses inserted</div>';

        // Seed Contract Types
        $seed_contract_types = ['Market/Quality Related', 'Margin + Cost', 'Fixed Price', 'Exposition Sales'];
        foreach ($seed_contract_types as $ct) {
            $conn->query("INSERT IGNORE INTO settings_contract_types (contract_type_name) VALUES ('$ct')");
        }
        echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Seed contract types inserted</div>';

        // Seed Supplier Types
        $seed_supplier_types = ['Farmer', 'Cooperative', 'Trader', 'Pisteur'];
        foreach ($seed_supplier_types as $st) {
            $conn->query("INSERT IGNORE INTO settings_supplier_types (supplier_type_name) VALUES ('$st')");
        }
        echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Seed supplier types inserted</div>';

        // Seed Expense Categories
        $seed_expense_cats = ['Transport', 'Warehouse', 'Office', 'Salary', 'Loading', 'Miscellaneous'];
        foreach ($seed_expense_cats as $ec) {
            $conn->query("INSERT IGNORE INTO settings_expense_categories (category_name) VALUES ('$ec')");
        }
        echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Seed expense categories inserted</div>';

        // Seed Bag Types
        $seed_bag_types = ['Jute Bag 50kg', 'Jute Bag 80kg', 'PP Bag 50kg', 'PP Bag 80kg', 'Big Bag 1000kg'];
        foreach ($seed_bag_types as $bt) {
            $conn->query("INSERT IGNORE INTO settings_bag_types (bag_type_name) VALUES ('$bt')");
        }
        echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Seed bag types inserted</div>';

        // Seed Paperwork Types
        $seed_paperwork_types = ['Vehicle Registration', 'Insurance', 'Road Tax', 'Technical Inspection', 'Transit Permit', 'Goods in Transit Insurance', 'Driving License', 'Road Worthiness Certificate'];
        foreach ($seed_paperwork_types as $pt) {
            $conn->query("INSERT IGNORE INTO settings_paperwork_types (paperwork_type_name) VALUES ('$pt')");
        }
        echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Seed paperwork types inserted</div>';

        // NOTE: Seasons and Currencies seed data is inserted AFTER their tables are created (further below)

        // ============================================================
        // TABLE 19: fleet_vehicles
        // ============================================================
        echo '<br><div class="log-item log-info"><i class="fas fa-truck-moving"></i> <strong>Creating Fleet Vehicles Table...</strong></div>';

        $fleet_sql = "CREATE TABLE fleet_vehicles (
            vehicle_id VARCHAR(20) PRIMARY KEY,
            vehicle_registration VARCHAR(50) NOT NULL UNIQUE,
            vehicle_model VARCHAR(100) NULL,
            driver_name VARCHAR(150) NOT NULL,
            phone_number VARCHAR(20) NULL,
            driver_license_no VARCHAR(50) NULL,
            license_expiry DATE NULL,
            vehicle_acquisition_date DATE NULL,
            maintenance_cost DECIMAL(15,2) DEFAULT 0.00,
            status ENUM('Available','On Trip','Maintenance','Inactive') DEFAULT 'Available',
            driver_salary DECIMAL(12,2) DEFAULT 0.00,
            total_trips_ytd INT DEFAULT 0,
            total_weight_hauled_kg DECIMAL(15,2) DEFAULT 0.00,
            missing_weight_rate DECIMAL(5,2) DEFAULT 0.00,
            alert_threshold DECIMAL(5,2) DEFAULT 5.00,
            season VARCHAR(20) NOT NULL DEFAULT '2025/2026',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_vehicle_registration (vehicle_registration),
            INDEX idx_fleet_status (status),
            INDEX idx_driver_name (driver_name),
            INDEX idx_license_expiry (license_expiry)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if ($conn->query($fleet_sql) === TRUE) {
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Table "fleet_vehicles" created successfully</div>';
        } else {
            echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Error creating fleet_vehicles table: ' . $conn->error . '</div>';
        }

        // TABLE: fleet_paperworks
        echo '<br><div class="log-item log-info"><i class="fas fa-scroll"></i> <strong>Creating Fleet Paperworks Table...</strong></div>';

        $fleet_paperworks_sql = "CREATE TABLE fleet_paperworks (
            paperwork_id INT AUTO_INCREMENT PRIMARY KEY,
            vehicle_id VARCHAR(20) NOT NULL,
            paperwork_type_id INT NOT NULL,
            issue_date DATE NULL,
            expiry_date DATE NOT NULL,
            reference_number VARCHAR(100) NULL,
            notes TEXT NULL,
            season VARCHAR(20) NOT NULL DEFAULT '2025/2026',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_fp_vehicle_id (vehicle_id),
            INDEX idx_fp_paperwork_type_id (paperwork_type_id),
            INDEX idx_fp_expiry_date (expiry_date),
            INDEX idx_fp_season (season),
            FOREIGN KEY (vehicle_id) REFERENCES fleet_vehicles(vehicle_id) ON UPDATE CASCADE ON DELETE CASCADE,
            FOREIGN KEY (paperwork_type_id) REFERENCES settings_paperwork_types(paperwork_type_id) ON UPDATE CASCADE ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if ($conn->query($fleet_paperworks_sql) === TRUE) {
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Table "fleet_paperworks" created successfully</div>';
        } else {
            echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Error creating fleet_paperworks table: ' . $conn->error . '</div>';
        }

        // ============================================================
        // MASTER TABLES
        // ============================================================
        echo '<br><div class="log-item log-info"><i class="fas fa-handshake"></i> <strong>Setting up Master Tables...</strong></div>';

        // TABLE: customers
        $customers_sql = "CREATE TABLE customers (
            customer_id VARCHAR(20) PRIMARY KEY,
            customer_name VARCHAR(200) NOT NULL,
            contact_person VARCHAR(150) NULL,
            phone VARCHAR(20) NULL,
            phone2 VARCHAR(20) NULL,
            email VARCHAR(200) NULL,
            location_id INT NULL,
            latitude DECIMAL(10,7) NULL,
            longitude DECIMAL(10,7) NULL,
            contract_type_id INT NULL,
            interest_rate DECIMAL(5,2) DEFAULT 0.00,
            payment_terms VARCHAR(200) NULL,
            quality_terms VARCHAR(300) NULL,
            financing_provided DECIMAL(15,2) DEFAULT 0.00,
            status ENUM('Active','Inactive') DEFAULT 'Active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_customer_name (customer_name),
            INDEX idx_location_id (location_id),
            INDEX idx_contract_type_id (contract_type_id),
            INDEX idx_status (status),
            FOREIGN KEY (location_id) REFERENCES settings_locations(location_id) ON UPDATE CASCADE ON DELETE SET NULL,
            FOREIGN KEY (contract_type_id) REFERENCES settings_contract_types(contract_type_id) ON UPDATE CASCADE ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if ($conn->query($customers_sql) === TRUE) {
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Table "customers" created successfully</div>';
        } else {
            echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Error creating customers table: ' . $conn->error . '</div>';
        }

        // TABLE: suppliers
        $suppliers_sql = "CREATE TABLE suppliers (
            supplier_id VARCHAR(20) PRIMARY KEY,
            first_name VARCHAR(150) NOT NULL,
            phone VARCHAR(20) NULL,
            phone2 VARCHAR(20) NULL,
            whatsapp_phone VARCHAR(20) NULL,
            email VARCHAR(200) NULL,
            id_number VARCHAR(50) NULL,
            date_of_birth DATE NULL,
            location_id INT NULL,
            supplier_type_id INT NULL,
            typical_price_per_kg DECIMAL(10,2) NULL,
            bank_account VARCHAR(100) NULL,
            procurement_region VARCHAR(150) NULL,
            profile_photo VARCHAR(255) DEFAULT NULL,
            id_photo VARCHAR(255) DEFAULT NULL,
            status ENUM('Active','Inactive') DEFAULT 'Active',
            financing_balance DECIMAL(15,2) DEFAULT 0.00,
            closer_warehouse_id INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_first_name (first_name),
            INDEX idx_location_id (location_id),
            INDEX idx_supplier_type_id (supplier_type_id),
            INDEX idx_status (status),
            INDEX idx_closer_warehouse_id (closer_warehouse_id),
            FOREIGN KEY (location_id) REFERENCES settings_locations(location_id) ON UPDATE CASCADE ON DELETE SET NULL,
            FOREIGN KEY (supplier_type_id) REFERENCES settings_supplier_types(supplier_type_id) ON UPDATE CASCADE ON DELETE SET NULL,
            FOREIGN KEY (closer_warehouse_id) REFERENCES settings_warehouses(warehouse_id) ON UPDATE CASCADE ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if ($conn->query($suppliers_sql) === TRUE) {
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Table "suppliers" created successfully</div>';
        } else {
            echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Error creating suppliers table: ' . $conn->error . '</div>';
        }

        // TABLE: supplier_ranking_snapshots — daily rank history for movement tracking on supplier-ranking.php
        $snap_sql = "CREATE TABLE supplier_ranking_snapshots (
            id INT AUTO_INCREMENT PRIMARY KEY,
            snapshot_date DATE NOT NULL,
            supplier_id VARCHAR(20) NOT NULL,
            rank_position INT NOT NULL,
            total_score DECIMAL(5,1) NOT NULL,
            tier CHAR(1) NOT NULL,
            UNIQUE KEY uniq_day_supplier (snapshot_date, supplier_id),
            INDEX idx_snap_date (snapshot_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if ($conn->query($snap_sql) === TRUE) {
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Table "supplier_ranking_snapshots" created successfully</div>';
        } else {
            echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Error creating supplier_ranking_snapshots table: ' . $conn->error . '</div>';
        }

        // ============================================================
        // TRANSACTION TABLES
        // ============================================================

        // TABLE: lots
        echo '<br><div class="log-item log-info"><i class="fas fa-cubes"></i> <strong>Creating Lots Table...</strong></div>';

        $lots_sql = "CREATE TABLE lots (
            lot_id INT AUTO_INCREMENT PRIMARY KEY,
            lot_number VARCHAR(10) NOT NULL,
            warehouse_id INT NOT NULL,
            status ENUM('Open','Closed') DEFAULT 'Open',
            target_weight_kg DECIMAL(12,2) DEFAULT 50000.00,
            current_weight_kg DECIMAL(12,2) DEFAULT 0.00,
            total_cost DECIMAL(15,2) DEFAULT 0.00,
            avg_cost_per_kg DECIMAL(10,2) DEFAULT 0.00,
            purchase_count INT DEFAULT 0,
            season VARCHAR(20) NOT NULL,
            opened_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            closed_at TIMESTAMP NULL,
            INDEX idx_lot_warehouse (warehouse_id),
            INDEX idx_lot_status (status),
            INDEX idx_lot_season (season),
            INDEX idx_lot_number (lot_number),
            FOREIGN KEY (warehouse_id) REFERENCES settings_warehouses(warehouse_id) ON UPDATE CASCADE ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if ($conn->query($lots_sql) === TRUE) {
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Table "lots" created successfully</div>';
        } else {
            echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Error creating lots table: ' . $conn->error . '</div>';
        }

        // TABLE: purchases
        $purchases_sql = "CREATE TABLE purchases (
            purchase_id VARCHAR(20) PRIMARY KEY,
            date DATE NOT NULL,
            supplier_id VARCHAR(20) NULL,
            supplier_name VARCHAR(150) NULL,
            price_agreement_id VARCHAR(20) NULL,
            price_from_agreement DECIMAL(10,2) NULL,
            override_price_per_kg DECIMAL(10,2) NULL,
            origin_location_id INT NULL,
            final_price_per_kg DECIMAL(10,2) NULL,
            weight_kg DECIMAL(12,2) NOT NULL,
            total_cost DECIMAL(15,2) NULL,
            num_bags INT DEFAULT NULL,
            kor_out_turn DECIMAL(6,2) NULL,
            grainage DECIMAL(8,2) NULL,
            visual_quality VARCHAR(50) NULL,
            warehouse_id INT NULL,
            payment_status ENUM('Pending','Prefinanced','Partial','Paid') DEFAULT 'Pending',
            linked_financing_id VARCHAR(20) NULL,
            receipt_number VARCHAR(50) NULL,
            lot_id INT NULL,
            season VARCHAR(20) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_date (date),
            INDEX idx_supplier_id (supplier_id),
            INDEX idx_origin_location_id (origin_location_id),
            INDEX idx_warehouse_id (warehouse_id),
            INDEX idx_payment_status (payment_status),
            INDEX idx_season (season),
            INDEX idx_lot_id (lot_id),
            FOREIGN KEY (supplier_id) REFERENCES suppliers(supplier_id) ON UPDATE CASCADE ON DELETE SET NULL,
            FOREIGN KEY (origin_location_id) REFERENCES settings_locations(location_id) ON UPDATE CASCADE ON DELETE SET NULL,
            FOREIGN KEY (warehouse_id) REFERENCES settings_warehouses(warehouse_id) ON UPDATE CASCADE ON DELETE SET NULL,
            FOREIGN KEY (lot_id) REFERENCES lots(lot_id) ON UPDATE CASCADE ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if ($conn->query($purchases_sql) === TRUE) {
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Table "purchases" created successfully</div>';
        } else {
            echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Error creating purchases table: ' . $conn->error . '</div>';
        }

        // TABLE: deliveries
        echo '<br><div class="log-item log-info"><i class="fas fa-truck-fast"></i> <strong>Creating Deliveries Table...</strong></div>';

        $deliveries_sql = "CREATE TABLE deliveries (
            delivery_id VARCHAR(20) PRIMARY KEY,
            date DATE NOT NULL,
            customer_id VARCHAR(20) NULL,
            customer_name VARCHAR(200) NULL,
            origin_warehouse_id INT NULL,
            vehicle_id VARCHAR(20) NULL,
            driver_name VARCHAR(150) NULL,
            vehicle_type ENUM('Owned','Rental') DEFAULT 'Owned',
            rental_driver_name VARCHAR(150) NULL,
            rental_driver_phone VARCHAR(20) NULL,
            rental_vehicle_reg VARCHAR(50) NULL,
            weight_kg DECIMAL(12,2) NOT NULL,
            num_bags INT DEFAULT NULL,
            procurement_cost_per_kg DECIMAL(10,2) NULL,
            transport_cost DECIMAL(12,2) DEFAULT 0,
            road_fees DECIMAL(12,2) DEFAULT 0,
            loading_cost DECIMAL(12,2) DEFAULT 0,
            other_cost DECIMAL(12,2) DEFAULT 0,
            total_cost DECIMAL(15,2) DEFAULT 0,
            status ENUM('Pending','In Transit','Delivered','Accepted','Rejected','Reassigned') DEFAULT 'Pending',
            rejection_reason VARCHAR(300) NULL,
            reassigned_to VARCHAR(20) NULL,
            reassigned_from_delivery_id VARCHAR(20) NULL,
            weight_at_destination DECIMAL(12,2) NULL,
            season VARCHAR(20) NOT NULL,
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_del_date (date),
            INDEX idx_del_customer_id (customer_id),
            INDEX idx_del_vehicle_id (vehicle_id),
            INDEX idx_del_status (status),
            INDEX idx_del_season (season),
            FOREIGN KEY (customer_id) REFERENCES customers(customer_id) ON UPDATE CASCADE ON DELETE SET NULL,
            FOREIGN KEY (origin_warehouse_id) REFERENCES settings_warehouses(warehouse_id) ON UPDATE CASCADE ON DELETE SET NULL,
            FOREIGN KEY (vehicle_id) REFERENCES fleet_vehicles(vehicle_id) ON UPDATE CASCADE ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if ($conn->query($deliveries_sql) === TRUE) {
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Table "deliveries" created successfully</div>';
        } else {
            echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Error creating deliveries table: ' . $conn->error . '</div>';
        }


        // TABLE: delivery_items (tracks which purchases/lots go into each delivery)
        echo '<br><div class="log-item log-info"><i class="fas fa-cubes"></i> <strong>Creating Delivery Items Table...</strong></div>';

        $delivery_items_sql = "CREATE TABLE delivery_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            delivery_id VARCHAR(20) NOT NULL,
            purchase_id VARCHAR(20) NOT NULL,
            lot_id INT NULL,
            lot_number VARCHAR(10) NULL,
            supplier_name VARCHAR(150) NULL,
            quantity_kg DECIMAL(12,2) NOT NULL,
            cost_per_kg DECIMAL(10,2) NOT NULL,
            total_cost DECIMAL(15,2) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_di_delivery (delivery_id),
            INDEX idx_di_purchase (purchase_id),
            INDEX idx_di_lot (lot_id),
            FOREIGN KEY (delivery_id) REFERENCES deliveries(delivery_id) ON UPDATE CASCADE ON DELETE CASCADE,
            FOREIGN KEY (purchase_id) REFERENCES purchases(purchase_id) ON UPDATE CASCADE ON DELETE CASCADE,
            FOREIGN KEY (lot_id) REFERENCES lots(lot_id) ON UPDATE CASCADE ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if ($conn->query($delivery_items_sql) === TRUE) {
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Table "delivery_items" created successfully</div>';
        } else {
            echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Error creating delivery_items table: ' . $conn->error . '</div>';
        }

        // TABLE: sales
        echo '<br><div class="log-item log-info"><i class="fas fa-coins"></i> <strong>Creating Sales Table...</strong></div>';

        $sales_sql = "CREATE TABLE sales (
            sale_id VARCHAR(20) PRIMARY KEY,
            delivery_id VARCHAR(20) NULL,
            customer_id VARCHAR(20) NULL,
            price_agreement_id VARCHAR(20) NULL,
            sale_status ENUM('Draft','Confirmed','Cancelled') DEFAULT 'Draft',
            unloading_date DATE NOT NULL,
            location_id INT NULL,
            first_weight_kg DECIMAL(12,2) NULL,
            second_weight_kg DECIMAL(12,2) NULL,
            gross_weight_kg DECIMAL(12,2) NOT NULL,
            empty_bags_qty INT DEFAULT 0,
            refraction_quality_kg DECIMAL(8,2) DEFAULT 0,
            penalty_defective_bags_kg DECIMAL(8,2) DEFAULT 0,
            net_weight_kg DECIMAL(12,2) NOT NULL,
            purchase_weight_kg DECIMAL(12,2) DEFAULT 0,
            kor_at_sale DECIMAL(6,2) NULL,
            humidity_at_sale DECIMAL(5,2) NULL,
            selling_price_per_kg DECIMAL(10,2) NOT NULL,
            gross_sale_amount DECIMAL(15,2) DEFAULT 0,
            total_costs DECIMAL(15,2) DEFAULT 0,
            gross_margin DECIMAL(15,2) DEFAULT 0,
            transport_cost DECIMAL(12,2) DEFAULT 0,
            other_expenses DECIMAL(12,2) DEFAULT 0,
            interest_fees DECIMAL(12,2) DEFAULT 0,
            net_revenue DECIMAL(15,2) DEFAULT 0,
            net_profit DECIMAL(15,2) DEFAULT 0,
            profit_per_kg DECIMAL(10,2) DEFAULT 0,
            season VARCHAR(20) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_sale_delivery_id (delivery_id),
            INDEX idx_sale_customer_id (customer_id),
            INDEX idx_sale_status (sale_status),
            INDEX idx_sale_season (season),
            FOREIGN KEY (delivery_id) REFERENCES deliveries(delivery_id) ON UPDATE CASCADE ON DELETE SET NULL,
            FOREIGN KEY (customer_id) REFERENCES customers(customer_id) ON UPDATE CASCADE ON DELETE SET NULL,
            FOREIGN KEY (location_id) REFERENCES settings_locations(location_id) ON UPDATE CASCADE ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if ($conn->query($sales_sql) === TRUE) {
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Table "sales" created successfully</div>';
        } else {
            echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Error creating sales table: ' . $conn->error . '</div>';
        }

        // TABLE: banks
        echo '<br><div class="log-item log-info"><i class="fas fa-building-columns"></i> <strong>Creating Banks Table...</strong></div>';

        $banks_sql = "CREATE TABLE banks (
            bank_id VARCHAR(20) PRIMARY KEY,
            bank_name VARCHAR(200) NOT NULL,
            branch VARCHAR(150) DEFAULT NULL,
            contact_person VARCHAR(150) DEFAULT NULL,
            phone VARCHAR(20) DEFAULT NULL,
            email VARCHAR(200) DEFAULT NULL,
            account_number VARCHAR(50) DEFAULT NULL,
            address TEXT DEFAULT NULL,
            notes TEXT DEFAULT NULL,
            status ENUM('Active','Inactive') DEFAULT 'Active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_bank_name (bank_name),
            INDEX idx_bank_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if ($conn->query($banks_sql) === TRUE) {
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Table "banks" created successfully</div>';
        } else {
            echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Error creating banks table: ' . $conn->error . '</div>';
        }

        // seed a couple of demo banks
        $conn->query("INSERT INTO banks (bank_id, bank_name, branch, contact_person, phone, email, account_number, address, status) VALUES
            ('BANK-001', 'Sahel Trade Bank', 'Daloa Main', 'Awa Kone', '+225 27 32 78 00 01', 'awa.kone@sahel.bank', 'CI-001-78900-12', 'Boulevard de la République, Daloa', 'Active'),
            ('BANK-002', 'Atlantic Capital', 'Abidjan Plateau', 'Marc Diallo', '+225 27 20 31 22 50', 'marc.diallo@atlantic.ci', 'CI-002-41100-87', 'Avenue Chardy, Plateau, Abidjan', 'Active'),
            ('BANK-003', 'Cocoa Coast Credit', 'San Pedro', 'Grace Amegan', '+225 27 34 71 18 30', 'grace.amegan@coastcredit.ci', 'CI-003-56120-44', 'Quartier Lac, San Pedro', 'Active')");

        // TABLE: financing
        echo '<br><div class="log-item log-info"><i class="fas fa-money-bill-transfer"></i> <strong>Creating Financing Table...</strong></div>';

        $financing_sql = "CREATE TABLE financing (
            financing_id VARCHAR(20) PRIMARY KEY,
            date DATE NOT NULL,
            direction ENUM('Incoming','Outgoing') NOT NULL,
            counterpart_type ENUM('Customer','Supplier','Bank') NOT NULL,
            counterparty_id VARCHAR(20) NOT NULL,
            counterpart_name VARCHAR(200) NOT NULL,
            carried_over_balance DECIMAL(15,2) DEFAULT 0,
            amount DECIMAL(15,2) NOT NULL,
            current_market_price DECIMAL(10,2) NULL,
            expected_volume_kg DECIMAL(12,2) NULL,
            delivered_volume_kg DECIMAL(12,2) DEFAULT 0,
            volume_remaining_kg DECIMAL(12,2) DEFAULT 0,
            amount_repaid DECIMAL(15,2) DEFAULT 0,
            balance_due DECIMAL(15,2) DEFAULT 0,
            interest_per_kg DECIMAL(10,2) DEFAULT 0,
            interest_amount DECIMAL(15,2) DEFAULT 0,
            interest_rate_pct DECIMAL(5,2) DEFAULT NULL,
            monthly_payment DECIMAL(15,2) DEFAULT NULL,
            term_months INT DEFAULT NULL,
            start_date DATE DEFAULT NULL,
            maturity_date DATE DEFAULT NULL,
            source ENUM('Manual','Auto-Overpayment','Auto-Payable') DEFAULT 'Manual',
            status ENUM('Active','Settled','Overdue','Defaulted') DEFAULT 'Active',
            reference_number VARCHAR(50) NULL,
            season VARCHAR(20) NOT NULL,
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_fin_date (date),
            INDEX idx_fin_direction (direction),
            INDEX idx_fin_counterparty_id (counterparty_id),
            INDEX idx_fin_status (status),
            INDEX idx_fin_season (season)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if ($conn->query($financing_sql) === TRUE) {
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Table "financing" created successfully</div>';
        } else {
            echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Error creating financing table: ' . $conn->error . '</div>';
        }

        // TABLE: payments
        echo '<br><div class="log-item log-info"><i class="fas fa-credit-card"></i> <strong>Creating Payments Table...</strong></div>';

        $payments_sql = "CREATE TABLE payments (
            payment_id VARCHAR(20) PRIMARY KEY,
            date DATE NOT NULL,
            direction ENUM('Outgoing','Incoming') NOT NULL,
            counterpart_id VARCHAR(20) NOT NULL,
            counterpart_name VARCHAR(200) NOT NULL,
            payment_type VARCHAR(50) NOT NULL,
            amount DECIMAL(15,2) NOT NULL,
            payment_mode VARCHAR(50) NOT NULL,
            reference_number VARCHAR(100) NULL,
            linked_purchase_id VARCHAR(20) NULL,
            linked_sale_id VARCHAR(20) NULL,
            linked_financing_id VARCHAR(20) NULL,
            notes TEXT NULL,
            season VARCHAR(20) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_pay_date (date),
            INDEX idx_pay_direction (direction),
            INDEX idx_pay_counterpart_id (counterpart_id),
            INDEX idx_pay_payment_type (payment_type),
            INDEX idx_pay_season (season),
            INDEX idx_pay_linked_purchase (linked_purchase_id),
            INDEX idx_pay_linked_sale (linked_sale_id),
            INDEX idx_pay_linked_financing (linked_financing_id),
            FOREIGN KEY (linked_purchase_id) REFERENCES purchases(purchase_id) ON UPDATE CASCADE ON DELETE SET NULL,
            FOREIGN KEY (linked_sale_id) REFERENCES sales(sale_id) ON UPDATE CASCADE ON DELETE SET NULL,
            FOREIGN KEY (linked_financing_id) REFERENCES financing(financing_id) ON UPDATE CASCADE ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if ($conn->query($payments_sql) === TRUE) {
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Table "payments" created successfully</div>';
        } else {
            echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Error creating payments table: ' . $conn->error . '</div>';
        }

        // TABLE: expenses
        echo '<br><div class="log-item log-info"><i class="fas fa-file-invoice-dollar"></i> <strong>Creating Expenses Table...</strong></div>';

        $expenses_sql = "CREATE TABLE expenses (
            expense_id VARCHAR(20) PRIMARY KEY,
            date DATE NOT NULL,
            category_id INT NULL,
            description VARCHAR(500) NOT NULL,
            amount DECIMAL(15,2) NOT NULL,
            linked_delivery_id VARCHAR(20) NULL,
            linked_purchase_id VARCHAR(20) NULL,
            paid_to VARCHAR(200) NULL,
            receipt_number VARCHAR(50) NULL,
            season VARCHAR(20) NOT NULL,
            status ENUM('Pending','Approved','Rejected') DEFAULT 'Approved',
            submitted_by INT NULL,
            reviewed_by INT NULL,
            reviewed_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_exp_date (date),
            INDEX idx_exp_category_id (category_id),
            INDEX idx_exp_season (season),
            INDEX idx_exp_status (status),
            INDEX idx_exp_linked_delivery (linked_delivery_id),
            INDEX idx_exp_linked_purchase (linked_purchase_id),
            FOREIGN KEY (category_id) REFERENCES settings_expense_categories(category_id) ON UPDATE CASCADE ON DELETE SET NULL,
            FOREIGN KEY (linked_delivery_id) REFERENCES deliveries(delivery_id) ON UPDATE CASCADE ON DELETE SET NULL,
            FOREIGN KEY (linked_purchase_id) REFERENCES purchases(purchase_id) ON UPDATE CASCADE ON DELETE SET NULL,
            FOREIGN KEY (submitted_by) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
            FOREIGN KEY (reviewed_by) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if ($conn->query($expenses_sql) === TRUE) {
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Table "expenses" created successfully</div>';
        } else {
            echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Error creating expenses table: ' . $conn->error . '</div>';
        }

        // ============================================================
        // TABLE 16: Customer Pricing Agreements
        // ============================================================
        echo '<br><div class="log-item log-info"><i class="fas fa-tags"></i> <strong>Creating Pricing Agreements Tables...</strong></div>';

        $cust_pricing_sql = "CREATE TABLE customer_pricing_agreements (
            price_agreement_id VARCHAR(20) PRIMARY KEY,
            effective_date DATE NOT NULL,
            customer_id VARCHAR(20) NULL,
            customer_name VARCHAR(200) NULL,
            contract_type_id INT NULL,
            base_cost_per_kg DECIMAL(10,2) NULL,
            pricing_notes TEXT NULL,
            status ENUM('Active','Expired','Superseded') DEFAULT 'Active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_cpa_customer_id (customer_id),
            INDEX idx_cpa_status (status),
            INDEX idx_cpa_effective_date (effective_date),
            FOREIGN KEY (customer_id) REFERENCES customers(customer_id) ON UPDATE CASCADE ON DELETE SET NULL,
            FOREIGN KEY (contract_type_id) REFERENCES settings_contract_types(contract_type_id) ON UPDATE CASCADE ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if ($conn->query($cust_pricing_sql) === TRUE) {
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Table "customer_pricing_agreements" created successfully</div>';
        } else {
            echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Error creating customer_pricing_agreements table: ' . $conn->error . '</div>';
        }

        // TABLE 17: Supplier Pricing Agreements
        $sup_pricing_sql = "CREATE TABLE supplier_pricing_agreements (
            price_agreement_id VARCHAR(20) PRIMARY KEY,
            effective_date DATE NOT NULL,
            supplier_id VARCHAR(20) NULL,
            supplier_name VARCHAR(150) NULL,
            base_cost_per_kg DECIMAL(10,2) NOT NULL,
            status ENUM('Active','Expired','Superseded') DEFAULT 'Active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_spa_supplier_id (supplier_id),
            INDEX idx_spa_status (status),
            INDEX idx_spa_effective_date (effective_date),
            FOREIGN KEY (supplier_id) REFERENCES suppliers(supplier_id) ON UPDATE CASCADE ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if ($conn->query($sup_pricing_sql) === TRUE) {
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Table "supplier_pricing_agreements" created successfully</div>';
        } else {
            echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Error creating supplier_pricing_agreements table: ' . $conn->error . '</div>';
        }

        // Add missing FK: sales.price_agreement_id → customer_pricing_agreements
        if ($conn->query("ALTER TABLE sales ADD FOREIGN KEY (price_agreement_id) REFERENCES customer_pricing_agreements(price_agreement_id) ON UPDATE CASCADE ON DELETE SET NULL") === TRUE) {
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> FK added: sales.price_agreement_id → customer_pricing_agreements</div>';
        }

        // Add missing FK: purchases.price_agreement_id → supplier_pricing_agreements
        if ($conn->query("ALTER TABLE purchases ADD FOREIGN KEY (price_agreement_id) REFERENCES supplier_pricing_agreements(price_agreement_id) ON UPDATE CASCADE ON DELETE SET NULL") === TRUE) {
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> FK added: purchases.price_agreement_id → supplier_pricing_agreements</div>';
        }

        // ============================================================
        // TABLE 18: bags_log
        // ============================================================
        echo '<br><div class="log-item log-info"><i class="fas fa-boxes-packing"></i> <strong>Creating Bags Log Table...</strong></div>';

        $bags_log_sql = "CREATE TABLE bags_log (
            bag_log_id INT AUTO_INCREMENT PRIMARY KEY,
            date DATE NOT NULL,
            customer_id VARCHAR(20) NULL,
            supplier_id VARCHAR(20) NULL,
            bag_type_id INT NULL,
            description VARCHAR(300) NULL,
            previous_balance INT DEFAULT 0,
            qty_in INT DEFAULT 0,
            ref_number VARCHAR(50) NULL,
            qty_out INT DEFAULT 0,
            balance INT DEFAULT 0,
            truck_id VARCHAR(20) NULL,
            driver_name VARCHAR(150) NULL,
            season VARCHAR(20) NOT NULL DEFAULT '2025/2026',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_bl_date (date),
            INDEX idx_bl_customer_id (customer_id),
            INDEX idx_bl_supplier_id (supplier_id),
            INDEX idx_bl_bag_type_id (bag_type_id),
            INDEX idx_bl_truck_id (truck_id),
            INDEX idx_bl_season (season),
            FOREIGN KEY (customer_id) REFERENCES customers(customer_id) ON UPDATE CASCADE ON DELETE SET NULL,
            FOREIGN KEY (supplier_id) REFERENCES suppliers(supplier_id) ON UPDATE CASCADE ON DELETE SET NULL,
            FOREIGN KEY (bag_type_id) REFERENCES settings_bag_types(bag_type_id) ON UPDATE CASCADE ON DELETE SET NULL,
            FOREIGN KEY (truck_id) REFERENCES fleet_vehicles(vehicle_id) ON UPDATE CASCADE ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if ($conn->query($bags_log_sql) === TRUE) {
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Table "bags_log" created successfully</div>';
        } else {
            echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Error creating bags_log table: ' . $conn->error . '</div>';
        }

        // TABLE: settings_seasons
        echo '<br><div class="log-item log-info"><i class="fas fa-calendar-alt"></i> <strong>Creating Seasons Table...</strong></div>';

        $seasons_sql = "CREATE TABLE settings_seasons (
            season_id INT AUTO_INCREMENT PRIMARY KEY,
            season_name VARCHAR(20) NOT NULL UNIQUE,
            start_date DATE NULL,
            end_date DATE NULL,
            is_active BOOLEAN DEFAULT FALSE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_season_name (season_name),
            INDEX idx_is_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if ($conn->query($seasons_sql) === TRUE) {
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Table "settings_seasons" created successfully</div>';
        } else {
            echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Error creating settings_seasons table: ' . $conn->error . '</div>';
        }

        // TABLE: settings_currencies
        $currencies_sql = "CREATE TABLE settings_currencies (
            currency_id INT AUTO_INCREMENT PRIMARY KEY,
            currency_code VARCHAR(10) NOT NULL UNIQUE,
            currency_name VARCHAR(50) NOT NULL,
            currency_symbol VARCHAR(10) NOT NULL,
            exchange_rate_to_base DECIMAL(12,6) DEFAULT 1.000000,
            is_base BOOLEAN DEFAULT FALSE,
            is_active BOOLEAN DEFAULT TRUE,
            last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_currency_code (currency_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if ($conn->query($currencies_sql) === TRUE) {
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Table "settings_currencies" created successfully</div>';
        } else {
            echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Error creating settings_currencies table: ' . $conn->error . '</div>';
        }

        // TABLE: driver_salary_payments
        $salary_sql = "CREATE TABLE driver_salary_payments (
            payment_id INT AUTO_INCREMENT PRIMARY KEY,
            vehicle_id VARCHAR(20) NOT NULL,
            driver_name VARCHAR(150) NOT NULL,
            payment_date DATE NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            payment_mode VARCHAR(50) DEFAULT 'Cash',
            reference_number VARCHAR(100) NULL,
            month_for VARCHAR(20) NULL,
            notes TEXT NULL,
            season VARCHAR(20) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_dsp_vehicle_id (vehicle_id),
            INDEX idx_dsp_payment_date (payment_date),
            INDEX idx_dsp_season (season),
            FOREIGN KEY (vehicle_id) REFERENCES fleet_vehicles(vehicle_id) ON UPDATE CASCADE ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if ($conn->query($salary_sql) === TRUE) {
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Table "driver_salary_payments" created successfully</div>';
        } else {
            echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Error creating driver_salary_payments table: ' . $conn->error . '</div>';
        }

        // TABLE: notifications
        echo '<br><div class="log-item log-info"><i class="fas fa-bell"></i> <strong>Creating Notifications Table...</strong></div>';

        $notif_sql = "CREATE TABLE notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            role_target VARCHAR(50) NULL,
            title VARCHAR(200) NOT NULL,
            message TEXT NOT NULL,
            type ENUM('info','warning','danger','success') DEFAULT 'info',
            link VARCHAR(255) NULL,
            is_read BOOLEAN DEFAULT FALSE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_notif_user (user_id),
            INDEX idx_notif_role (role_target),
            INDEX idx_notif_read (is_read),
            INDEX idx_notif_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if ($conn->query($notif_sql) === TRUE) {
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Table "notifications" created successfully</div>';
        } else {
            echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Error creating notifications table: ' . $conn->error . '</div>';
        }

        // ============================================================
        // SEED DATA: Seasons & Currencies (tables created above)
        // ============================================================
        $conn->query("INSERT IGNORE INTO settings_seasons (season_name, start_date, end_date, is_active) VALUES ('2025/2026', '2025-04-01', '2026-03-31', 1)");
        $conn->query("INSERT IGNORE INTO settings_seasons (season_name, start_date, end_date, is_active) VALUES ('2026/2027', '2026-04-01', '2027-03-31', 0)");
        echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Seed seasons inserted</div>';

        $conn->query("INSERT IGNORE INTO settings_currencies (currency_code, currency_name, currency_symbol, exchange_rate_to_base, is_base) VALUES ('XOF', 'CFA Franc', 'FCFA', 1.000000, 1)");
        $conn->query("INSERT IGNORE INTO settings_currencies (currency_code, currency_name, currency_symbol, exchange_rate_to_base, is_base) VALUES ('USD', 'US Dollar', '\$', 0.001650, 0)");
        $conn->query("INSERT IGNORE INTO settings_currencies (currency_code, currency_name, currency_symbol, exchange_rate_to_base, is_base) VALUES ('EUR', 'Euro', '€', 0.001524, 0)");
        $conn->query("INSERT IGNORE INTO settings_currencies (currency_code, currency_name, currency_symbol, exchange_rate_to_base, is_base) VALUES ('CAD', 'Canadian Dollar', 'CAD \$', 0.002260, 0)");
        echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Seed currencies inserted</div>';

        // ============================================================
        // SEED DATA: Master Tables
        // ============================================================
        echo '<br><div class="log-item log-info"><i class="fas fa-seedling"></i> <strong>Inserting master table seed data...</strong></div>';

        // Seed Customers
        $loc_abidjan = $conn->query("SELECT location_id FROM settings_locations WHERE location_name = 'Abidjan' LIMIT 1");
        $loc_ab_id = ($loc_abidjan && $loc_abidjan->num_rows > 0) ? $loc_abidjan->fetch_assoc()['location_id'] : null;
        $loc_sanpedro = $conn->query("SELECT location_id FROM settings_locations WHERE location_name = 'San Pedro' LIMIT 1");
        $loc_sp_id = ($loc_sanpedro && $loc_sanpedro->num_rows > 0) ? $loc_sanpedro->fetch_assoc()['location_id'] : null;
        $loc_yamoussoukro = $conn->query("SELECT location_id FROM settings_locations WHERE location_name = 'Yamoussoukro' LIMIT 1");
        $loc_ya_id = ($loc_yamoussoukro && $loc_yamoussoukro->num_rows > 0) ? $loc_yamoussoukro->fetch_assoc()['location_id'] : null;

        $cust_seeds = [
            ['CUST-001', 'Customer 1', 'Contact 1', '0100000001', $loc_ab_id, 2.50, 50000.00],
            ['CUST-002', 'Customer 2', 'Contact 2', '0100000002', $loc_sp_id, 3.00, 75000.00],
            ['CUST-003', 'Customer 3', 'Contact 3', '0100000003', $loc_ya_id, 2.00, 100000.00],
            ['CUST-004', 'Customer 4', 'Contact 4', '0100000004', $loc_ab_id, 3.50, 0.00],
            ['CUST-005', 'Customer 5', 'Contact 5', '0100000005', $loc_sp_id, 2.75, 25000.00]
        ];

        $cust_stmt = $conn->prepare("INSERT IGNORE INTO customers (customer_id, customer_name, contact_person, phone, location_id, interest_rate, financing_provided) VALUES (?, ?, ?, ?, ?, ?, ?)");
        foreach ($cust_seeds as $c) {
            $cust_stmt->bind_param("ssssidd", $c[0], $c[1], $c[2], $c[3], $c[4], $c[5], $c[6]);
            $cust_stmt->execute();
        }
        $cust_stmt->close();
        echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Seed customers inserted</div>';

        // Seed Suppliers
        $loc_daloa = $conn->query("SELECT location_id FROM settings_locations WHERE location_name = 'Daloa' LIMIT 1");
        $loc_da_id = ($loc_daloa && $loc_daloa->num_rows > 0) ? $loc_daloa->fetch_assoc()['location_id'] : null;
        $loc_seguela = $conn->query("SELECT location_id FROM settings_locations WHERE location_name = 'Seguela' LIMIT 1");
        $loc_se_id = ($loc_seguela && $loc_seguela->num_rows > 0) ? $loc_seguela->fetch_assoc()['location_id'] : null;
        $loc_aladjkro = $conn->query("SELECT location_id FROM settings_locations WHERE location_name = 'Aladjkro' LIMIT 1");
        $loc_al_id = ($loc_aladjkro && $loc_aladjkro->num_rows > 0) ? $loc_aladjkro->fetch_assoc()['location_id'] : null;

        $wh_daloa = $conn->query("SELECT warehouse_id FROM settings_warehouses WHERE warehouse_name LIKE '%Daloa%' LIMIT 1");
        $wh_da_id = ($wh_daloa && $wh_daloa->num_rows > 0) ? $wh_daloa->fetch_assoc()['warehouse_id'] : null;
        $wh_seguela = $conn->query("SELECT warehouse_id FROM settings_warehouses WHERE warehouse_name LIKE '%Seguela%' LIMIT 1");
        $wh_se_id = ($wh_seguela && $wh_seguela->num_rows > 0) ? $wh_seguela->fetch_assoc()['warehouse_id'] : null;
        $wh_aladjkro = $conn->query("SELECT warehouse_id FROM settings_warehouses WHERE warehouse_name LIKE '%Aladjkro%' LIMIT 1");
        $wh_al_id = ($wh_aladjkro && $wh_aladjkro->num_rows > 0) ? $wh_aladjkro->fetch_assoc()['warehouse_id'] : null;

        $sup_seeds = [
            ['F-260313DA001', 'Supplier 1', '0200000001', 'ID-001', $loc_da_id, 500.00, $wh_da_id],
            ['F-260313DA002', 'Supplier 2', '0200000002', 'ID-002', $loc_da_id, 550.00, $wh_da_id],
            ['F-260313SE001', 'Supplier 3', '0200000003', 'ID-003', $loc_se_id, 480.00, $wh_se_id],
            ['F-260313SE002', 'Supplier 4', '0200000004', 'ID-004', $loc_se_id, 520.00, $wh_se_id],
            ['F-260313AL001', 'Supplier 5', '0200000005', 'ID-005', $loc_al_id, 490.00, $wh_al_id]
        ];

        $sup_stmt = $conn->prepare("INSERT IGNORE INTO suppliers (supplier_id, first_name, phone, id_number, location_id, typical_price_per_kg, closer_warehouse_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
        foreach ($sup_seeds as $s) {
            $sup_stmt->bind_param("sssssdi", $s[0], $s[1], $s[2], $s[3], $s[4], $s[5], $s[6]);
            $sup_stmt->execute();
        }
        $sup_stmt->close();
        echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Seed suppliers inserted</div>';

        // Seed Fleet Vehicles
        echo '<br><div class="log-item log-info"><i class="fas fa-truck-moving"></i> <strong>Inserting fleet vehicle seed data...</strong></div>';

        $fleet_seeds = [
            ['VEH-001', 'CI-1234-AB', 'Toyota Dyna', 'Driver 1', '0500000001', 'DL-001', '2026-12-31', '2024-01-15', 0, 'Available', 150000.00, 0, 0, 0, 5.00, '2025/2026'],
            ['VEH-002', 'CI-5678-CD', 'Mitsubishi Canter', 'Driver 2', '0500000002', 'DL-002', '2026-06-30', '2023-06-01', 0, 'Available', 175000.00, 0, 0, 0, 5.00, '2025/2026'],
            ['VEH-003', 'CI-9012-EF', 'Isuzu NPR', 'Driver 3', '0500000003', 'DL-003', '2025-03-01', '2022-09-20', 0, 'Maintenance', 200000.00, 0, 0, 0, 3.00, '2025/2026'],
            ['VEH-004', 'CI-3456-GH', 'Hino 300', 'Driver 4', '0500000004', 'DL-004', '2027-01-15', '2024-05-10', 0, 'Available', 160000.00, 0, 0, 0, 5.00, '2025/2026'],
            ['VEH-005', 'CI-7890-IJ', 'DAF LF', 'Driver 5', '0500000005', 'DL-005', '2026-09-15', '2023-11-01', 0, 'Inactive', 180000.00, 0, 0, 0, 5.00, '2025/2026']
        ];

        $fleet_stmt = $conn->prepare("INSERT IGNORE INTO fleet_vehicles (vehicle_id, vehicle_registration, vehicle_model, driver_name, phone_number, driver_license_no, license_expiry, vehicle_acquisition_date, maintenance_cost, status, driver_salary, total_trips_ytd, total_weight_hauled_kg, missing_weight_rate, alert_threshold, season) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($fleet_seeds as $f) {
            $fleet_stmt->bind_param("ssssssssdsdiddds", $f[0], $f[1], $f[2], $f[3], $f[4], $f[5], $f[6], $f[7], $f[8], $f[9], $f[10], $f[11], $f[12], $f[13], $f[14], $f[15]);
            $fleet_stmt->execute();
        }
        $fleet_stmt->close();
        echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Seed fleet vehicles inserted</div>';

        // Seed Fleet Paperworks
        echo '<br><div class="log-item log-info"><i class="fas fa-scroll"></i> <strong>Inserting fleet paperwork seed data...</strong></div>';

        // get paperwork type IDs
        $pt_map = [];
        $pt_res = $conn->query("SELECT paperwork_type_id, paperwork_type_name FROM settings_paperwork_types");
        if ($pt_res) { while ($r = $pt_res->fetch_assoc()) $pt_map[$r['paperwork_type_name']] = $r['paperwork_type_id']; }

        $pw_seeds = [
            // VEH-001
            ['VEH-001', $pt_map['Vehicle Registration'] ?? 1, '2025-01-15', '2026-01-15', 'REG-CI-1234-AB', '2025/2026'],
            ['VEH-001', $pt_map['Insurance'] ?? 2, '2025-03-01', '2026-03-01', 'INS-001-2025', '2025/2026'],
            ['VEH-001', $pt_map['Technical Inspection'] ?? 4, '2025-06-10', '2025-12-10', 'INSP-001', '2025/2026'],
            ['VEH-001', $pt_map['Road Tax'] ?? 3, '2025-04-01', '2026-03-31', 'TAX-001-2025', '2025/2026'],
            // VEH-002
            ['VEH-002', $pt_map['Vehicle Registration'] ?? 1, '2025-06-01', '2026-06-01', 'REG-CI-5678-CD', '2025/2026'],
            ['VEH-002', $pt_map['Insurance'] ?? 2, '2025-05-15', '2026-05-15', 'INS-002-2025', '2025/2026'],
            ['VEH-002', $pt_map['Transit Permit'] ?? 5, '2025-08-01', '2026-02-01', 'TP-002-2025', '2025/2026'],
            // VEH-003 - some expired
            ['VEH-003', $pt_map['Vehicle Registration'] ?? 1, '2024-09-20', '2025-09-20', 'REG-CI-9012-EF', '2025/2026'],
            ['VEH-003', $pt_map['Insurance'] ?? 2, '2024-10-01', '2025-10-01', 'INS-003-2024', '2025/2026'],
            ['VEH-003', $pt_map['Technical Inspection'] ?? 4, '2024-12-01', '2025-06-01', 'INSP-003', '2025/2026'],
            // VEH-004
            ['VEH-004', $pt_map['Vehicle Registration'] ?? 1, '2025-05-10', '2026-05-10', 'REG-CI-3456-GH', '2025/2026'],
            ['VEH-004', $pt_map['Insurance'] ?? 2, '2025-07-01', '2026-07-01', 'INS-004-2025', '2025/2026'],
            ['VEH-004', $pt_map['Goods in Transit Insurance'] ?? 6, '2025-09-01', '2026-09-01', 'GTI-004-2025', '2025/2026'],
            // VEH-005 - inactive, all expired
            ['VEH-005', $pt_map['Vehicle Registration'] ?? 1, '2023-11-01', '2024-11-01', 'REG-CI-7890-IJ', '2025/2026'],
            ['VEH-005', $pt_map['Insurance'] ?? 2, '2023-11-01', '2024-11-01', 'INS-005-2023', '2025/2026']
        ];

        $pw_stmt = $conn->prepare("INSERT IGNORE INTO fleet_paperworks (vehicle_id, paperwork_type_id, issue_date, expiry_date, reference_number, season) VALUES (?, ?, ?, ?, ?, ?)");
        foreach ($pw_seeds as $pw) {
            $pw_stmt->bind_param("sissss", $pw[0], $pw[1], $pw[2], $pw[3], $pw[4], $pw[5]);
            $pw_stmt->execute();
        }
        $pw_stmt->close();
        echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Seed fleet paperworks inserted (' . count($pw_seeds) . ' records)</div>';

        // ============================================================
        // SEED DATA: Transactions
        // ============================================================
        echo '<br><div class="log-item log-info"><i class="fas fa-seedling"></i> <strong>Inserting transaction seed data...</strong></div>';

        // ── Seed Purchases (25 records, April 2025 – March 2026) ──
        $pur_seeds = [
            // Apr 2025 – lighter early season
            ['ACH-25-0410-0001-C', '2025-04-10', 'F-260313DA001', 'Supplier 1', $loc_da_id ?? null, '460', '800', '368000', '16', '47.0', '195', 'Fair', $wh_da_id ?? null, '2025/2026'],
            ['ACH-25-0422-0002-C', '2025-04-22', 'F-260313SE001', 'Supplier 3', $loc_se_id ?? null, '455', '650', '295750', '13', '46.5', '198', 'Fair', $wh_se_id ?? null, '2025/2026'],
            // May 2025
            ['ACH-25-0508-0003-C', '2025-05-08', 'F-260313DA002', 'Supplier 2', $loc_da_id ?? null, '470', '1100', '517000', '22', '48.0', '190', 'Good', $wh_da_id ?? null, '2025/2026'],
            ['ACH-25-0520-0004-C', '2025-05-20', 'F-260313AL001', 'Supplier 5', $loc_al_id ?? null, '465', '900', '418500', '18', '47.5', '192', 'Good', $wh_al_id ?? null, '2025/2026'],
            // Jun 2025
            ['ACH-25-0612-0005-C', '2025-06-12', 'F-260313SE002', 'Supplier 4', $loc_se_id ?? null, '475', '750', '356250', '15', '48.5', '188', 'Good', $wh_se_id ?? null, '2025/2026'],
            // Jul 2025
            ['ACH-25-0715-0006-C', '2025-07-15', 'F-260313DA001', 'Supplier 1', $loc_da_id ?? null, '480', '600', '288000', '12', '49.0', '186', 'Good', $wh_da_id ?? null, '2025/2026'],
            // Aug 2025
            ['ACH-25-0818-0007-C', '2025-08-18', 'F-260313SE001', 'Supplier 3', $loc_se_id ?? null, '490', '1200', '588000', '24', '48.0', '190', 'Fair', $wh_se_id ?? null, '2025/2026'],
            // Sep 2025
            ['ACH-25-0905-0008-C', '2025-09-05', 'F-260313AL001', 'Supplier 5', $loc_al_id ?? null, '500', '1500', '750000', '30', '49.5', '185', 'Good', $wh_al_id ?? null, '2025/2026'],
            ['ACH-25-0922-0009-C', '2025-09-22', 'F-260313DA002', 'Supplier 2', $loc_da_id ?? null, '495', '1000', '495000', '20', '50.0', '184', 'Good', $wh_da_id ?? null, '2025/2026'],
            // Oct 2025 – peak season begins
            ['ACH-25-1003-0010-C', '2025-10-03', 'F-260313DA001', 'Supplier 1', $loc_da_id ?? null, '520', '2000', '1040000', '40', '50.5', '183', 'Good', $wh_da_id ?? null, '2025/2026'],
            ['ACH-25-1015-0011-C', '2025-10-15', 'F-260313SE002', 'Supplier 4', $loc_se_id ?? null, '530', '1800', '954000', '36', '49.0', '187', 'Good', $wh_se_id ?? null, '2025/2026'],
            ['ACH-25-1028-0012-C', '2025-10-28', 'F-260313AL001', 'Supplier 5', $loc_al_id ?? null, '525', '2200', '1155000', '44', '51.0', '182', 'Good', $wh_al_id ?? null, '2025/2026'],
            // Nov 2025
            ['ACH-25-1105-0013-C', '2025-11-05', 'F-260313DA002', 'Supplier 2', $loc_da_id ?? null, '540', '2500', '1350000', '50', '50.0', '185', 'Good', $wh_da_id ?? null, '2025/2026'],
            ['ACH-25-1118-0014-C', '2025-11-18', 'F-260313SE001', 'Supplier 3', $loc_se_id ?? null, '535', '1900', '1016500', '38', '48.5', '189', 'Fair', $wh_se_id ?? null, '2025/2026'],
            // Dec 2025
            ['ACH-25-1202-0015-C', '2025-12-02', 'F-260313DA001', 'Supplier 1', $loc_da_id ?? null, '560', '2800', '1568000', '56', '51.5', '181', 'Good', $wh_da_id ?? null, '2025/2026'],
            ['ACH-25-1215-0016-C', '2025-12-15', 'F-260313SE002', 'Supplier 4', $loc_se_id ?? null, '555', '2300', '1276500', '46', '50.0', '186', 'Good', $wh_se_id ?? null, '2025/2026'],
            ['ACH-25-1228-0017-C', '2025-12-28', 'F-260313AL001', 'Supplier 5', $loc_al_id ?? null, '550', '3000', '1650000', '60', '52.0', '180', 'Good', $wh_al_id ?? null, '2025/2026'],
            // Jan 2026
            ['ACH-26-0108-0018-C', '2026-01-08', 'F-260313DA002', 'Supplier 2', $loc_da_id ?? null, '570', '2600', '1482000', '52', '51.0', '183', 'Good', $wh_da_id ?? null, '2025/2026'],
            ['ACH-26-0120-0019-C', '2026-01-20', 'F-260313SE001', 'Supplier 3', $loc_se_id ?? null, '575', '2100', '1207500', '42', '49.5', '188', 'Fair', $wh_se_id ?? null, '2025/2026'],
            // Feb 2026
            ['ACH-26-0205-0020-C', '2026-02-05', 'F-260313DA001', 'Supplier 1', $loc_da_id ?? null, '590', '2400', '1416000', '48', '51.5', '182', 'Good', $wh_da_id ?? null, '2025/2026'],
            ['ACH-26-0218-0021-C', '2026-02-18', 'F-260313AL001', 'Supplier 5', $loc_al_id ?? null, '585', '1800', '1053000', '36', '50.0', '185', 'Good', $wh_al_id ?? null, '2025/2026'],
            ['ACH-26-0226-0022-C', '2026-02-26', 'F-260313SE002', 'Supplier 4', $loc_se_id ?? null, '580', '2000', '1160000', '40', '49.0', '190', 'Fair', $wh_se_id ?? null, '2025/2026'],
            // Mar 2026
            ['ACH-26-0305-0023-C', '2026-03-05', 'F-260313DA002', 'Supplier 2', $loc_da_id ?? null, '600', '1500', '900000', '30', '52.0', '180', 'Good', $wh_da_id ?? null, '2025/2026'],
            ['ACH-26-0312-0024-C', '2026-03-12', 'F-260313SE001', 'Supplier 3', $loc_se_id ?? null, '595', '1200', '714000', '24', '50.5', '184', 'Good', $wh_se_id ?? null, '2025/2026'],
            ['ACH-26-0314-0025-C', '2026-03-14', 'F-260313DA001', 'Supplier 1', $loc_da_id ?? null, '598', '1000', '598000', '20', '51.0', '183', 'Good', $wh_da_id ?? null, '2025/2026']
        ];

        $pur_stmt = $conn->prepare("INSERT IGNORE INTO purchases (purchase_id, date, supplier_id, supplier_name, origin_location_id, final_price_per_kg, weight_kg, total_cost, num_bags, kor_out_turn, grainage, visual_quality, warehouse_id, season) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($pur_seeds as $p) {
            $pur_stmt->bind_param("ssssssssssssss", $p[0], $p[1], $p[2], $p[3], $p[4], $p[5], $p[6], $p[7], $p[8], $p[9], $p[10], $p[11], $p[12], $p[13]);
            $pur_stmt->execute();
        }
        $pur_stmt->close();
        echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Seed purchases inserted (25 records)</div>';

        // ── Auto-assign lots to purchases ──
        echo '<br><div class="log-item log-info"><i class="fas fa-cubes"></i> <strong>Assigning lots to purchases...</strong></div>';
        $lotTarget = 50000;
        $wh_lot_result = $conn->query("SELECT DISTINCT warehouse_id FROM purchases WHERE warehouse_id IS NOT NULL");
        $lotCount = 0;
        while ($whRow = $wh_lot_result->fetch_assoc()) {
            $whIdLot = $whRow['warehouse_id'];
            $lp = $conn->prepare("SELECT purchase_id, weight_kg, total_cost FROM purchases WHERE warehouse_id = ? ORDER BY date ASC");
            $lp->bind_param("i", $whIdLot);
            $lp->execute();
            $lotPurchases = $lp->get_result()->fetch_all(MYSQLI_ASSOC);
            $lp->close();

            // get warehouse code for lot prefix
            $wcRes = $conn->query("SELECT warehouse_code FROM settings_warehouses WHERE warehouse_id = $whIdLot LIMIT 1");
            $whCode = ($wcRes && $wcRes->num_rows > 0) ? $wcRes->fetch_assoc()['warehouse_code'] : 'LOT';
            if (empty($whCode)) $whCode = 'LOT';

            $lotSeq = 1; $lotWt = 0; $lotCst = 0; $lotCnt = 0;
            $lotNum = $whCode . '-' . str_pad($lotSeq, 3, '0', STR_PAD_LEFT);
            $conn->query("INSERT INTO lots (lot_number, warehouse_id, season) VALUES ('$lotNum', $whIdLot, '2025/2026')");
            $curLotId = $conn->insert_id;

            foreach ($lotPurchases as $lpur) {
                $conn->query("UPDATE purchases SET lot_id = $curLotId WHERE purchase_id = '{$lpur['purchase_id']}'");
                $lotWt += floatval($lpur['weight_kg']);
                $lotCst += floatval($lpur['total_cost']);
                $lotCnt++;

                if ($lotWt >= $lotTarget) {
                    $lotAvg = round($lotCst / $lotWt, 2);
                    $conn->query("UPDATE lots SET current_weight_kg = $lotWt, total_cost = $lotCst, avg_cost_per_kg = $lotAvg, purchase_count = $lotCnt, status = 'Closed', closed_at = NOW() WHERE lot_id = $curLotId");
                    $lotSeq++; $lotWt = 0; $lotCst = 0; $lotCnt = 0;
                    $lotNum = $whCode . '-' . str_pad($lotSeq, 3, '0', STR_PAD_LEFT);
                    $conn->query("INSERT INTO lots (lot_number, warehouse_id, season) VALUES ('$lotNum', $whIdLot, '2025/2026')");
                    $curLotId = $conn->insert_id;
                }
            }
            if ($lotCnt > 0) {
                $lotAvg = round($lotCst / $lotWt, 2);
                $conn->query("UPDATE lots SET current_weight_kg = $lotWt, total_cost = $lotCst, avg_cost_per_kg = $lotAvg, purchase_count = $lotCnt WHERE lot_id = $curLotId");
            }
            $lotCount += $lotSeq;
        }
        echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> ' . $lotCount . ' lot(s) created and assigned</div>';

        // ── Seed Deliveries (18 records, spread across months) ──
        $del_seeds = [
            // May 2025
            ['LIV-25-0515-0001-C', '2025-05-15', 'CUST-001', 'Customer 1', $wh_da_id ?? null, 'VEH-001', 'Driver 1', '2000', '40', null, '80000', '25000', '10000', '115000', 'Accepted', null, null, null, '1980', '2025/2026', 'Early season delivery'],
            // Jun 2025
            ['LIV-25-0620-0002-C', '2025-06-20', 'CUST-002', 'Customer 2', $wh_se_id ?? null, 'VEH-002', 'Driver 2', '1500', '30', null, '60000', '20000', '8000', '88000', 'Accepted', null, null, null, '1490', '2025/2026', null],
            // Aug 2025
            ['LIV-25-0825-0003-C', '2025-08-25', 'CUST-003', 'Customer 3', $wh_al_id ?? null, 'VEH-004', 'Driver 4', '2500', '50', null, '100000', '35000', '12000', '147000', 'Delivered', null, null, null, '2470', '2025/2026', null],
            // Sep 2025
            ['LIV-25-0910-0004-C', '2025-09-10', 'CUST-001', 'Customer 1', $wh_da_id ?? null, 'VEH-001', 'Driver 1', '3000', '60', null, '120000', '40000', '15000', '175000', 'Accepted', null, null, null, '2980', '2025/2026', null],
            // Oct 2025
            ['LIV-25-1008-0005-C', '2025-10-08', 'CUST-004', 'Customer 4', $wh_da_id ?? null, 'VEH-002', 'Driver 2', '4000', '80', null, '150000', '45000', '18000', '213000', 'Accepted', null, null, null, '3960', '2025/2026', 'Peak season starts'],
            ['LIV-25-1022-0006-C', '2025-10-22', 'CUST-002', 'Customer 2', $wh_se_id ?? null, 'VEH-004', 'Driver 4', '3500', '70', null, '130000', '40000', '15000', '185000', 'Delivered', null, null, null, '3470', '2025/2026', null],
            // Nov 2025
            ['LIV-25-1105-0007-C', '2025-11-05', 'CUST-001', 'Customer 1', $wh_da_id ?? null, 'VEH-001', 'Driver 1', '5000', '100', null, '180000', '55000', '25000', '260000', 'Accepted', null, null, null, '4960', '2025/2026', 'Large batch'],
            ['LIV-25-1118-0008-C', '2025-11-18', 'CUST-005', 'Customer 5', $wh_al_id ?? null, 'VEH-002', 'Driver 2', '3000', '60', null, '110000', '35000', '12000', '157000', 'Rejected', 'Humidity above 10%', null, null, '2900', '2025/2026', 'Failed quality check'],
            ['LIV-25-1125-0009-C', '2025-11-25', 'CUST-003', 'Customer 3', $wh_se_id ?? null, 'VEH-004', 'Driver 4', '4500', '90', null, '160000', '50000', '20000', '230000', 'Accepted', null, null, null, '4470', '2025/2026', null],
            // Dec 2025
            ['LIV-25-1205-0010-C', '2025-12-05', 'CUST-002', 'Customer 2', $wh_da_id ?? null, 'VEH-001', 'Driver 1', '5500', '110', null, '200000', '60000', '28000', '288000', 'Delivered', null, null, null, '5450', '2025/2026', null],
            ['LIV-25-1218-0011-C', '2025-12-18', 'CUST-004', 'Customer 4', $wh_al_id ?? null, 'VEH-002', 'Driver 2', '6000', '120', null, '200000', '60000', '30000', '290000', 'In Transit', null, null, null, null, '2025/2026', 'Year-end shipment'],
            ['LIV-25-1228-0012-C', '2025-12-28', 'CUST-005', 'Customer 5', $wh_se_id ?? null, 'VEH-004', 'Driver 4', '2000', '40', null, '75000', '22000', '8000', '105000', 'Reassigned', null, 'LIV-26-0110-0014-C', null, null, '2025/2026', 'Reassigned to Jan delivery'],
            // Jan 2026
            ['LIV-26-0108-0013-C', '2026-01-08', 'CUST-001', 'Customer 1', $wh_da_id ?? null, 'VEH-001', 'Driver 1', '5500', '110', null, '190000', '55000', '25000', '270000', 'Accepted', null, null, null, '5460', '2025/2026', null],
            ['LIV-26-0110-0014-C', '2026-01-10', 'CUST-005', 'Customer 5', $wh_se_id ?? null, 'VEH-002', 'Driver 2', '2000', '40', null, '75000', '22000', '8000', '105000', 'Accepted', null, null, 'LIV-25-1228-0012-C', '1980', '2025/2026', 'Reassigned from Dec'],
            ['LIV-26-0122-0015-C', '2026-01-22', 'CUST-003', 'Customer 3', $wh_al_id ?? null, 'VEH-004', 'Driver 4', '4000', '80', null, '145000', '42000', '18000', '205000', 'Pending', null, null, null, null, '2025/2026', 'Awaiting dispatch'],
            // Feb 2026
            ['LIV-26-0210-0016-C', '2026-02-10', 'CUST-002', 'Customer 2', $wh_da_id ?? null, 'VEH-001', 'Driver 1', '4500', '90', null, '165000', '48000', '20000', '233000', 'Accepted', null, null, null, '4470', '2025/2026', null],
            ['LIV-26-0225-0017-C', '2026-02-25', 'CUST-004', 'Customer 4', $wh_se_id ?? null, 'VEH-002', 'Driver 2', '3500', '70', null, '130000', '38000', '15000', '183000', 'In Transit', null, null, null, null, '2025/2026', null],
            // Mar 2026
            ['LIV-26-0310-0018-C', '2026-03-10', 'CUST-001', 'Customer 1', $wh_al_id ?? null, 'VEH-004', 'Driver 4', '3000', '60', null, '110000', '32000', '12000', '154000', 'Pending', null, null, null, null, '2025/2026', 'End of season']
        ];
        $del_stmt = $conn->prepare("INSERT IGNORE INTO deliveries (delivery_id, date, customer_id, customer_name, origin_warehouse_id, vehicle_id, driver_name, weight_kg, num_bags, procurement_cost_per_kg, transport_cost, loading_cost, other_cost, total_cost, status, rejection_reason, reassigned_to, reassigned_from_delivery_id, weight_at_destination, season, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($del_seeds as $d) {
            $del_stmt->bind_param("sssssssssssssssssssss", $d[0], $d[1], $d[2], $d[3], $d[4], $d[5], $d[6], $d[7], $d[8], $d[9], $d[10], $d[11], $d[12], $d[13], $d[14], $d[15], $d[16], $d[17], $d[18], $d[19], $d[20]);
            $del_stmt->execute();
        }
        $del_stmt->close();
        echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Seed deliveries inserted (18 records)</div>';

        // ── Seed Sales (15 records, linked to deliveries) ──
        // new format: [0-6 same], [7=gross_weight_kg, 8=empty_bags_qty, 9=refraction_quality_kg, 10=penalty_defective_bags_kg, 11=net_weight_kg], [12=kor, 13=humidity, 14=price, 15-23=financials, 24=season]
        $sale_seeds = [
            ['VTE-25-0518-0001-C', 'LIV-25-0515-0001-C', 'CUST-001', null, 'Confirmed', '2025-05-18', $loc_ab_id ?? null, '2000', 10, '5', '5', '1980', '48.0', '8.5', '820', '1623600', '115000', '1508600', '80000', '10000', '5000', '1608600', '1493600', '754.34', '2025/2026'],
            ['VTE-25-0625-0002-C', 'LIV-25-0620-0002-C', 'CUST-002', null, 'Confirmed', '2025-06-25', $loc_sp_id ?? null, '1500', 5, '3', '2', '1490', '47.5', '9.0', '830', '1236700', '88000', '1148700', '60000', '8000', '3000', '1225700', '1137700', '763.56', '2025/2026'],
            ['VTE-25-0912-0003-C', 'LIV-25-0910-0004-C', 'CUST-001', null, 'Confirmed', '2025-09-12', $loc_ab_id ?? null, '3000', 8, '7', '5', '2980', '49.0', '8.0', '850', '2533000', '175000', '2358000', '120000', '15000', '8000', '2510000', '2335000', '783.22', '2025/2026'],
            ['VTE-25-1012-0004-C', 'LIV-25-1008-0005-C', 'CUST-004', null, 'Confirmed', '2025-10-12', $loc_ab_id ?? null, '4000', 15, '15', '10', '3960', '50.0', '8.5', '880', '3484800', '213000', '3271800', '150000', '18000', '10000', '3456800', '3243800', '819.14', '2025/2026'],
            ['VTE-25-1025-0005-C', 'LIV-25-1022-0006-C', 'CUST-002', null, 'Confirmed', '2025-10-25', $loc_sp_id ?? null, '3500', 12, '10', '8', '3470', '49.5', '9.0', '870', '3018900', '185000', '2833900', '130000', '15000', '7000', '2996900', '2811900', '810.34', '2025/2026'],
            ['VTE-25-1108-0006-C', 'LIV-25-1105-0007-C', 'CUST-001', null, 'Confirmed', '2025-11-08', $loc_ab_id ?? null, '5000', 15, '15', '10', '4960', '50.5', '8.0', '900', '4464000', '260000', '4204000', '180000', '25000', '12000', '4427000', '4167000', '840.12', '2025/2026'],
            ['VTE-25-1128-0007-C', 'LIV-25-1125-0009-C', 'CUST-003', null, 'Confirmed', '2025-11-28', $loc_ya_id ?? null, '4500', 12, '10', '8', '4470', '49.0', '8.5', '880', '3933600', '230000', '3703600', '160000', '20000', '10000', '3903600', '3673600', '821.83', '2025/2026'],
            ['VTE-25-1208-0008-C', 'LIV-25-1205-0010-C', 'CUST-002', null, 'Confirmed', '2025-12-08', $loc_sp_id ?? null, '5500', 18, '20', '12', '5450', '51.0', '8.0', '920', '5014000', '288000', '4726000', '200000', '28000', '15000', '4971000', '4683000', '859.08', '2025/2026'],
            ['VTE-26-0112-0009-C', 'LIV-26-0108-0013-C', 'CUST-001', null, 'Confirmed', '2026-01-12', $loc_ab_id ?? null, '5500', 15, '15', '10', '5460', '51.0', '8.5', '950', '5187000', '270000', '4917000', '190000', '25000', '12000', '5162000', '4892000', '896.00', '2025/2026'],
            ['VTE-26-0115-0010-C', 'LIV-26-0110-0014-C', 'CUST-005', null, 'Confirmed', '2026-01-15', $loc_sp_id ?? null, '2000', 8, '7', '5', '1980', '49.0', '9.0', '860', '1702800', '105000', '1597800', '75000', '8000', '4000', '1690800', '1585800', '801.21', '2025/2026'],
            ['VTE-26-0215-0011-C', 'LIV-26-0210-0016-C', 'CUST-002', null, 'Confirmed', '2026-02-15', $loc_sp_id ?? null, '4500', 12, '10', '8', '4470', '50.0', '8.0', '940', '4201800', '233000', '3968800', '165000', '20000', '10000', '4176800', '3943800', '882.28', '2025/2026'],
            // Draft sales
            ['VTE-25-0828-0012-C', 'LIV-25-0825-0003-C', 'CUST-003', null, 'Draft', '2025-08-28', $loc_ya_id ?? null, '2500', 10, '12', '8', '2470', '48.0', '9.0', '840', '2074800', '147000', '1927800', '100000', '12000', '5000', '2057800', '1910800', '773.81', '2025/2026'],
            ['VTE-26-0312-0013-C', 'LIV-26-0310-0018-C', 'CUST-001', null, 'Draft', '2026-03-12', $loc_ab_id ?? null, '3000', 10, '12', '8', '2970', '50.5', '8.5', '960', '2851200', '154000', '2697200', '110000', '12000', '6000', '2839200', '2685200', '904.11', '2025/2026'],
            // Cancelled sale (rejected delivery)
            ['VTE-25-1120-0014-C', 'LIV-25-1118-0008-C', 'CUST-005', null, 'Cancelled', '2025-11-20', $loc_sp_id ?? null, '3000', 30, '195', '65', '2710', '46.0', '10.5', '800', '2168000', '157000', '2011000', '110000', '12000', '5000', '2141000', '1984000', '732.10', '2025/2026'],
            // Sale with small loss
            ['VTE-25-1222-0015-C', 'LIV-25-1218-0011-C', 'CUST-004', null, 'Confirmed', '2025-12-22', $loc_ab_id ?? null, '6000', 25, '35', '20', '5920', '48.0', '9.5', '810', '4795200', '290000', '4505200', '200000', '30000', '25000', '4740200', '4450200', '751.72', '2025/2026']
        ];
        // cols: 0=sale_id, 1=delivery_id, 2=customer_id, 3=price_agreement_id, 4=sale_status, 5=unloading_date, 6=location_id
        // 7=gross_weight_kg, 8=empty_bags_qty, 9=refraction_quality_kg, 10=penalty_defective_bags_kg, 11=net_weight_kg
        // 12=kor_at_sale, 13=humidity_at_sale, 14=selling_price_per_kg
        // 15=gross_sale_amount, 16=total_costs, 17=gross_margin, 18=transport_cost, 19=other_expenses, 20=interest_fees
        // 21=net_revenue, 22=net_profit, 23=profit_per_kg, 24=season
        $sale_stmt = $conn->prepare("INSERT IGNORE INTO sales (sale_id, delivery_id, customer_id, price_agreement_id, sale_status, unloading_date, location_id, gross_weight_kg, empty_bags_qty, refraction_quality_kg, penalty_defective_bags_kg, net_weight_kg, kor_at_sale, humidity_at_sale, selling_price_per_kg, gross_sale_amount, total_costs, gross_margin, transport_cost, other_expenses, interest_fees, net_revenue, net_profit, profit_per_kg, season) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($sale_seeds as $s) {
            $sale_stmt->bind_param("ssssssssissssssssssssssss", $s[0], $s[1], $s[2], $s[3], $s[4], $s[5], $s[6], $s[7], $s[8], $s[9], $s[10], $s[11], $s[12], $s[13], $s[14], $s[15], $s[16], $s[17], $s[18], $s[19], $s[20], $s[21], $s[22], $s[23], $s[24]);
            $sale_stmt->execute();
        }
        $sale_stmt->close();
        echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Seed sales inserted (15 records)</div>';

        // ── Seed Financing (8 records, mix of Incoming/Outgoing) ──
        // interest_per_kg: FCFA per kg on delivered volume (mainly for Customer financing)
        // interest_amount = interest_per_kg * delivered_volume_kg
        // balance_due = amount + carried_over_balance + interest_amount - amount_repaid
        $fin_seeds = [
            // Incoming from customers (pre-financing) — interest applies as F/kg
            ['FIN-25-0415-0001-C', '2025-04-15', 'Incoming', 'Customer', 'CUST-001', 'Customer 1', '0', '5000000', '820', '6100', '2000', '4100', '1500000', '3510000', '5', '10000', 'Active', 'REF-FIN-001', '2025/2026', 'Customer 1 season pre-financing'],
            ['FIN-25-0901-0002-C', '2025-09-01', 'Incoming', 'Customer', 'CUST-002', 'Customer 2', '0', '8000000', '870', '9200', '3500', '5700', '2000000', '6024500', '7', '24500', 'Active', 'REF-FIN-002', '2025/2026', 'Customer 2 peak season financing'],
            ['FIN-25-1001-0003-C', '2025-10-01', 'Incoming', 'Customer', 'CUST-003', 'Customer 3', '0', '3000000', '880', '3400', '1200', '2200', '800000', '2204800', '4', '4800', 'Active', 'REF-FIN-003', '2025/2026', 'Customer 3 financing'],
            ['FIN-26-0110-0004-C', '2026-01-10', 'Incoming', 'Customer', 'CUST-004', 'Customer 4', '0', '4000000', '950', '4200', '0', '4200', '0', '4000000', '8', '0', 'Active', 'REF-FIN-004', '2025/2026', 'Customer 4 late season advance'],
            // Outgoing to suppliers (advances) — no interest
            ['FIN-25-0501-0005-C', '2025-05-01', 'Outgoing', 'Supplier', 'F-260313DA001', 'Supplier 1', '0', '2000000', '460', '4350', '2200', '2150', '2000000', '0', '0', '0', 'Settled', 'REF-FIN-005', '2025/2026', 'Supplier 1 advance - fully settled'],
            ['FIN-25-0815-0006-C', '2025-08-15', 'Outgoing', 'Supplier', 'F-260313SE001', 'Supplier 3', '0', '3000000', '490', '6120', '1800', '4320', '1200000', '1800000', '0', '0', 'Active', 'REF-FIN-006', '2025/2026', 'Supplier 3 advance'],
            ['FIN-25-1101-0007-C', '2025-11-01', 'Outgoing', 'Supplier', 'F-260313AL001', 'Supplier 5', '0', '1500000', '525', '2860', '800', '2060', '500000', '1000000', '0', '0', 'Active', 'REF-FIN-007', '2025/2026', 'Supplier 5 advance'],
            ['FIN-25-0701-0008-C', '2025-07-01', 'Outgoing', 'Supplier', 'F-260313DA002', 'Supplier 2', '500000', '2500000', '470', '5320', '2000', '3320', '1000000', '2000000', '0', '0', 'Overdue', 'REF-FIN-008', '2025/2026', 'Supplier 2 advance - overdue']
        ];
        $fin_stmt = $conn->prepare("INSERT IGNORE INTO financing (financing_id, date, direction, counterpart_type, counterparty_id, counterpart_name, carried_over_balance, amount, current_market_price, expected_volume_kg, delivered_volume_kg, volume_remaining_kg, amount_repaid, balance_due, interest_per_kg, interest_amount, status, reference_number, season, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($fin_seeds as $f) {
            $fin_stmt->bind_param("ssssssssssssssssssss", $f[0], $f[1], $f[2], $f[3], $f[4], $f[5], $f[6], $f[7], $f[8], $f[9], $f[10], $f[11], $f[12], $f[13], $f[14], $f[15], $f[16], $f[17], $f[18], $f[19]);
            $fin_stmt->execute();
        }
        $fin_stmt->close();
        echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Seed financing inserted (8 records)</div>';

        // ── Seed Payments (20 records, spread across months) ──
        $pay_seeds = [
            // Outgoing - Purchase payments
            ['PAI-25-0415-0001-C', '2025-04-15', 'Outgoing', 'F-260313DA001', 'Supplier 1', 'Purchase', '368000', 'Cash', 'REC-PAY-001', 'ACH-25-0410-0001-C', null, null, 'Payment for Apr purchase', '2025/2026'],
            ['PAI-25-0512-0002-C', '2025-05-12', 'Outgoing', 'F-260313DA002', 'Supplier 2', 'Purchase', '517000', 'Bank', 'TRF-2025-001', 'ACH-25-0508-0003-C', null, null, 'Payment for May purchase', '2025/2026'],
            ['PAI-25-1008-0003-C', '2025-10-08', 'Outgoing', 'F-260313DA001', 'Supplier 1', 'Purchase', '1040000', 'Bank', 'TRF-2025-002', 'ACH-25-1003-0010-C', null, null, 'Oct peak purchase payment', '2025/2026'],
            ['PAI-25-1110-0004-C', '2025-11-10', 'Outgoing', 'F-260313DA002', 'Supplier 2', 'Purchase', '1350000', 'Bank', 'TRF-2025-003', 'ACH-25-1105-0013-C', null, null, 'Nov purchase payment', '2025/2026'],
            ['PAI-25-1206-0005-C', '2025-12-06', 'Outgoing', 'F-260313DA001', 'Supplier 1', 'Purchase', '1568000', 'Bank', 'TRF-2025-004', 'ACH-25-1202-0015-C', null, null, 'Dec purchase payment', '2025/2026'],
            ['PAI-26-0112-0006-C', '2026-01-12', 'Outgoing', 'F-260313DA002', 'Supplier 2', 'Purchase', '1482000', 'Bank', 'TRF-2026-001', 'ACH-26-0108-0018-C', null, null, 'Jan purchase payment', '2025/2026'],
            ['PAI-26-0210-0007-C', '2026-02-10', 'Outgoing', 'F-260313DA001', 'Supplier 1', 'Purchase', '1416000', 'Mobile Money', 'MM-2026-001', 'ACH-26-0205-0020-C', null, null, 'Feb purchase payment', '2025/2026'],
            // Incoming - Sale payments
            ['PAI-25-0522-0008-C', '2025-05-22', 'Incoming', 'CUST-001', 'Customer 1', 'Sale', '1600000', 'Bank', 'TRF-2025-005', null, 'VTE-25-0518-0001-C', null, 'Customer 1 sale payment', '2025/2026'],
            ['PAI-25-0630-0009-C', '2025-06-30', 'Incoming', 'CUST-002', 'Customer 2', 'Sale', '1200000', 'Bank', 'TRF-2025-006', null, 'VTE-25-0625-0002-C', null, 'Customer 2 sale payment', '2025/2026'],
            ['PAI-25-1015-0010-C', '2025-10-15', 'Incoming', 'CUST-004', 'Customer 4', 'Sale', '3400000', 'Bank', 'TRF-2025-007', null, 'VTE-25-1012-0004-C', null, 'Customer 4 Oct sale payment', '2025/2026'],
            ['PAI-25-1112-0011-C', '2025-11-12', 'Incoming', 'CUST-001', 'Customer 1', 'Sale', '4400000', 'Bank', 'TRF-2025-008', null, 'VTE-25-1108-0006-C', null, 'Customer 1 Nov sale payment', '2025/2026'],
            ['PAI-25-1212-0012-C', '2025-12-12', 'Incoming', 'CUST-002', 'Customer 2', 'Sale', '5000000', 'Bank', 'TRF-2025-009', null, 'VTE-25-1208-0008-C', null, 'Customer 2 Dec sale payment', '2025/2026'],
            ['PAI-26-0118-0013-C', '2026-01-18', 'Incoming', 'CUST-001', 'Customer 1', 'Sale', '5100000', 'Bank', 'TRF-2026-002', null, 'VTE-26-0112-0009-C', null, 'Customer 1 Jan sale payment', '2025/2026'],
            ['PAI-26-0220-0014-C', '2026-02-20', 'Incoming', 'CUST-002', 'Customer 2', 'Sale', '4200000', 'Bank', 'TRF-2026-003', null, 'VTE-26-0215-0011-C', null, 'Customer 2 Feb sale payment', '2025/2026'],
            // Financing payments
            ['PAI-25-0420-0015-C', '2025-04-20', 'Incoming', 'CUST-001', 'Customer 1', 'Financing', '5000000', 'Bank', 'TRF-2025-010', null, null, 'FIN-25-0415-0001-C', 'Customer 1 financing received', '2025/2026'],
            ['PAI-25-0505-0016-C', '2025-05-05', 'Outgoing', 'F-260313DA001', 'Supplier 1', 'Financing', '2000000', 'Bank', 'TRF-2025-011', null, null, 'FIN-25-0501-0005-C', 'Supplier 1 advance disbursed', '2025/2026'],
            ['PAI-25-0905-0017-C', '2025-09-05', 'Incoming', 'CUST-002', 'Customer 2', 'Financing', '8000000', 'Bank', 'TRF-2025-012', null, null, 'FIN-25-0901-0002-C', 'Customer 2 peak season financing', '2025/2026'],
            // Advance payments
            ['PAI-25-0701-0018-C', '2025-07-01', 'Outgoing', 'F-260313SE001', 'Supplier 3', 'Advance', '500000', 'Mobile Money', 'MM-2025-001', null, null, null, 'Advance to Supplier 3', '2025/2026'],
            ['PAI-25-1201-0019-C', '2025-12-01', 'Outgoing', 'F-260313AL001', 'Supplier 5', 'Advance', '300000', 'Cash', 'REC-PAY-002', null, null, null, 'Small advance to Supplier 5', '2025/2026'],
            ['PAI-26-0305-0020-C', '2026-03-05', 'Incoming', 'CUST-003', 'Customer 3', 'Advance', '200000', 'Mobile Money', 'MM-2026-002', null, null, null, 'Customer 3 advance for next season', '2025/2026']
        ];
        $pay_stmt = $conn->prepare("INSERT IGNORE INTO payments (payment_id, date, direction, counterpart_id, counterpart_name, payment_type, amount, payment_mode, reference_number, linked_purchase_id, linked_sale_id, linked_financing_id, notes, season) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($pay_seeds as $p) {
            $pay_stmt->bind_param("ssssssssssssss", $p[0], $p[1], $p[2], $p[3], $p[4], $p[5], $p[6], $p[7], $p[8], $p[9], $p[10], $p[11], $p[12], $p[13]);
            $pay_stmt->execute();
        }
        $pay_stmt->close();
        echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Seed payments inserted (20 records)</div>';

        // ── Seed Expenses (12 records, categories 1-6) ──
        $exp_seeds = [
            // Category 1: Transport
            ['DEP-25-0520-0001-C', '2025-05-20', '1', 'Transport fuel for early season delivery', '75000', 'LIV-25-0515-0001-C', null, 'Transport Company 1', 'REC-DEP-001', '2025/2026'],
            ['DEP-25-1010-0002-C', '2025-10-10', '1', 'Fuel and toll for peak season transport', '120000', 'LIV-25-1008-0005-C', null, 'Transport Company 1', 'REC-DEP-002', '2025/2026'],
            // Category 2: Warehouse
            ['DEP-25-0615-0003-C', '2025-06-15', '2', 'Warehouse rent Daloa - June', '180000', null, null, 'Warehouse Owner 1', 'REC-DEP-003', '2025/2026'],
            ['DEP-25-1115-0004-C', '2025-11-15', '2', 'Warehouse handling and storage fees Nov', '250000', null, null, 'Warehouse Owner 1', 'REC-DEP-004', '2025/2026'],
            // Category 3: Office
            ['DEP-25-0801-0005-C', '2025-08-01', '3', 'Office supplies and internet subscription', '45000', null, null, 'Office Supplier 1', 'REC-DEP-005', '2025/2026'],
            ['DEP-26-0115-0006-C', '2026-01-15', '3', 'Office rent and utilities Jan 2026', '85000', null, null, 'Landlord 1', 'REC-DEP-006', '2025/2026'],
            // Category 4: Salary
            ['DEP-25-0930-0007-C', '2025-09-30', '4', 'Staff salaries September', '300000', null, null, 'Staff Payroll', 'REC-DEP-007', '2025/2026'],
            ['DEP-25-1231-0008-C', '2025-12-31', '4', 'Staff salaries December', '300000', null, null, 'Staff Payroll', 'REC-DEP-008', '2025/2026'],
            // Category 5: Loading
            ['DEP-25-1108-0009-C', '2025-11-08', '5', 'Loading crew for large Nov shipment', '55000', 'LIV-25-1105-0007-C', null, 'Loading Crew 1', 'REC-DEP-009', '2025/2026'],
            ['DEP-26-0212-0010-C', '2026-02-12', '5', 'Loading for Feb delivery', '42000', 'LIV-26-0210-0016-C', null, 'Loading Crew 1', 'REC-DEP-010', '2025/2026'],
            // Category 6: Miscellaneous
            ['DEP-25-0720-0011-C', '2025-07-20', '6', 'Vehicle repair - VEH-003 brake issue', '95000', null, null, 'Mechanic 1', 'REC-DEP-011', '2025/2026'],
            ['DEP-26-0301-0012-C', '2026-03-01', '6', 'End-of-season inventory audit', '35000', null, null, 'Auditor 1', 'REC-DEP-012', '2025/2026']
        ];
        $exp_stmt = $conn->prepare("INSERT IGNORE INTO expenses (expense_id, date, category_id, description, amount, linked_delivery_id, linked_purchase_id, paid_to, receipt_number, season) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($exp_seeds as $e) {
            $exp_stmt->bind_param("ssssssssss", $e[0], $e[1], $e[2], $e[3], $e[4], $e[5], $e[6], $e[7], $e[8], $e[9]);
            $exp_stmt->execute();
        }
        $exp_stmt->close();
        echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Seed expenses inserted (12 records)</div>';

        // Seed Customer Pricing Agreements
        echo '<br><div class="log-item log-info"><i class="fas fa-tags"></i> <strong>Inserting pricing seed data...</strong></div>';

        $ct_res = $conn->query("SELECT contract_type_id FROM settings_contract_types LIMIT 2");
        $ct_ids = [];
        while ($ct_row = $ct_res->fetch_assoc()) {
            $ct_ids[] = $ct_row['contract_type_id'];
        }
        $ct1 = $ct_ids[0] ?? null;
        $ct2 = $ct_ids[1] ?? $ct1;

        $cpa_seeds = [
            ['APC-25-1001-0001-7', '2025-10-01', 'CUST-001', 'Customer 1', $ct1, 850.00, 'Active'],
            ['APC-25-1015-0002-0', '2025-10-15', 'CUST-002', 'Customer 2', $ct2, 900.00, 'Active'],
            ['APC-25-0901-0003-8', '2025-09-01', 'CUST-001', 'Customer 1', $ct1, 800.00, 'Superseded'],
            ['APC-25-1101-0004-3', '2025-11-01', 'CUST-003', 'Customer 3', $ct1, 880.00, 'Active'],
            ['APC-25-0801-0005-7', '2025-08-01', 'CUST-004', 'Customer 4', $ct2, 820.00, 'Expired']
        ];
        $cpa_stmt = $conn->prepare("INSERT IGNORE INTO customer_pricing_agreements (price_agreement_id, effective_date, customer_id, customer_name, contract_type_id, base_cost_per_kg, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
        foreach ($cpa_seeds as $c) {
            $cpa_stmt->bind_param("ssssids", $c[0], $c[1], $c[2], $c[3], $c[4], $c[5], $c[6]);
            $cpa_stmt->execute();
        }
        $cpa_stmt->close();
        echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Seed customer pricing agreements inserted</div>';

        // Seed Supplier Pricing Agreements
        $spa_seeds = [
            ['APF-25-1001-0001-7', '2025-10-01', 'F-260313DA001', 'Supplier 1', 500.00, 'Active'],
            ['APF-25-1015-0002-0', '2025-10-15', 'F-260313DA002', 'Supplier 2', 550.00, 'Active'],
            ['APF-25-0901-0003-8', '2025-09-01', 'F-260313DA001', 'Supplier 1', 480.00, 'Superseded'],
            ['APF-25-1101-0004-3', '2025-11-01', 'F-260313SE001', 'Supplier 3', 480.00, 'Active'],
            ['APF-25-0801-0005-7', '2025-08-01', 'F-260313SE002', 'Supplier 4', 510.00, 'Expired']
        ];
        $spa_stmt = $conn->prepare("INSERT IGNORE INTO supplier_pricing_agreements (price_agreement_id, effective_date, supplier_id, supplier_name, base_cost_per_kg, status) VALUES (?, ?, ?, ?, ?, ?)");
        foreach ($spa_seeds as $s) {
            $spa_stmt->bind_param("ssssds", $s[0], $s[1], $s[2], $s[3], $s[4], $s[5]);
            $spa_stmt->execute();
        }
        $spa_stmt->close();
        echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Seed supplier pricing agreements inserted</div>';

        // ── Seed Bags Log (10 records, tracking bag movements across months) ──
        echo '<br><div class="log-item log-info"><i class="fas fa-boxes-packing"></i> <strong>Inserting bags log seed data...</strong></div>';

        // Get first bag type ID
        $bt_res = $conn->query("SELECT bag_type_id FROM settings_bag_types LIMIT 1");
        $bt_id = ($bt_res && $bt_res->num_rows > 0) ? $bt_res->fetch_assoc()['bag_type_id'] : null;

        $bags_seeds = [
            // Apr 2025 - Initial stock
            ['2025-04-05', 'CUST-001', $bt_id, 'Initial bag stock for season', '0', '1000', 'REF-BG-001', '0', '1000', null, null, '2025/2026'],
            // May 2025 - Bags out for delivery
            ['2025-05-14', 'CUST-001', $bt_id, 'Bags sent for Customer 1 delivery', '1000', '0', 'REF-BG-002', '40', '960', 'VEH-001', 'Driver 1', '2025/2026'],
            // Jul 2025 - Restock
            ['2025-07-10', 'CUST-002', $bt_id, 'Bag restock from supplier', '960', '500', 'REF-BG-003', '0', '1460', null, null, '2025/2026'],
            // Sep 2025 - Bags out
            ['2025-09-08', 'CUST-001', $bt_id, 'Bags dispatched for Sep delivery', '1460', '0', 'REF-BG-004', '60', '1400', 'VEH-001', 'Driver 1', '2025/2026'],
            // Oct 2025 - Heavy usage
            ['2025-10-06', 'CUST-004', $bt_id, 'Bags sent for Customer 4 Oct delivery', '1400', '0', 'REF-BG-005', '80', '1320', 'VEH-002', 'Driver 2', '2025/2026'],
            ['2025-10-20', 'CUST-002', $bt_id, 'Bags sent for Customer 2 Oct delivery', '1320', '0', 'REF-BG-006', '70', '1250', 'VEH-004', 'Driver 4', '2025/2026'],
            // Nov 2025 - Restock and dispatch
            ['2025-11-01', 'CUST-001', $bt_id, 'Bag restock for peak season', '1250', '800', 'REF-BG-007', '0', '2050', null, null, '2025/2026'],
            ['2025-11-04', 'CUST-001', $bt_id, 'Bags dispatched for large Nov delivery', '2050', '0', 'REF-BG-008', '100', '1950', 'VEH-001', 'Driver 1', '2025/2026'],
            // Jan 2026 - Dispatch
            ['2026-01-07', 'CUST-001', $bt_id, 'Bags sent for Jan delivery', '1950', '0', 'REF-BG-009', '110', '1840', 'VEH-001', 'Driver 1', '2025/2026'],
            // Mar 2026 - End of season count
            ['2026-03-10', 'CUST-001', $bt_id, 'End of season bag inventory', '1840', '0', 'REF-BG-010', '60', '1780', 'VEH-004', 'Driver 4', '2025/2026']
        ];
        $bags_stmt = $conn->prepare("INSERT IGNORE INTO bags_log (date, customer_id, bag_type_id, description, previous_balance, qty_in, ref_number, qty_out, balance, truck_id, driver_name, season) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($bags_seeds as $b) {
            $bags_stmt->bind_param("ssssssssssss", $b[0], $b[1], $b[2], $b[3], $b[4], $b[5], $b[6], $b[7], $b[8], $b[9], $b[10], $b[11]);
            $bags_stmt->execute();
        }
        $bags_stmt->close();
        echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Seed bags log inserted (10 records)</div>';

        // ============================================================
        // Upload directories
        // ============================================================
        echo '<br><div class="log-item log-info"><i class="fas fa-folder"></i> <strong>Creating Upload Directories...</strong></div>';

        $upload_base = __DIR__ . '/uploads';
        $upload_profiles = __DIR__ . '/uploads/profiles';

        if (!file_exists($upload_base)) {
            if (mkdir($upload_base, 0755, true)) {
                echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Directory "uploads/" created successfully</div>';
            } else {
                echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Failed to create "uploads/" directory</div>';
            }
        } else {
            echo '<div class="log-item log-info"><i class="fas fa-info-circle"></i> Directory "uploads/" already exists</div>';
        }

        if (!file_exists($upload_profiles)) {
            if (mkdir($upload_profiles, 0755, true)) {
                echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Directory "uploads/profiles/" created successfully</div>';
            } else {
                echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Failed to create "uploads/profiles/" directory</div>';
            }
        } else {
            echo '<div class="log-item log-info"><i class="fas fa-info-circle"></i> Directory "uploads/profiles/" already exists</div>';
        }

        $upload_suppliers = __DIR__ . '/uploads/suppliers';
        if (!file_exists($upload_suppliers)) {
            if (mkdir($upload_suppliers, 0755, true)) {
                echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Directory "uploads/suppliers/" created successfully</div>';
            } else {
                echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Failed to create "uploads/suppliers/" directory</div>';
            }
        } else {
            echo '<div class="log-item log-info"><i class="fas fa-info-circle"></i> Directory "uploads/suppliers/" already exists</div>';
        }

        $test_file = $upload_profiles . '/test.txt';
        if (file_put_contents($test_file, 'test') !== false) {
            unlink($test_file);
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Upload directory is writable (permissions OK)</div>';
        } else {
            echo '<div class="log-item log-error"><i class="fas fa-exclamation-triangle"></i> Warning: Upload directory may not be writable. Check permissions.</div>';
        }

        // Security .htaccess
        $htaccess_content = "# Protect uploads directory\n";
        $htaccess_content .= "<Files ~ \"\\.(php|php3|php4|php5|phtml|pl|py|jsp|asp|htm|shtml|sh|cgi)$\">\n";
        $htaccess_content .= "    deny from all\n";
        $htaccess_content .= "</Files>\n";
        $htaccess_content .= "\n# Allow image files only\n";
        $htaccess_content .= "<FilesMatch \"\\.(jpg|jpeg|png|gif|webp)$\">\n";
        $htaccess_content .= "    allow from all\n";
        $htaccess_content .= "</FilesMatch>\n";

        $htaccess_file = $upload_base . '/.htaccess';
        if (file_put_contents($htaccess_file, $htaccess_content) !== false) {
            echo '<div class="log-item log-success"><i class="fas fa-shield-alt"></i> Security .htaccess file created in uploads directory</div>';
        } else {
            echo '<div class="log-item log-error"><i class="fas fa-exclamation-triangle"></i> Warning: Could not create security .htaccess file</div>';
        }

        $conn->close();

        echo '<br><div class="log-item log-success">';
        echo '<i class="fas fa-check-circle"></i> <strong>Setup completed successfully!</strong>';
        echo '</div>';
        ?>

        <a href="login.php" class="btn">
            <i class="fas fa-sign-in-alt"></i> Go to Login Page
        </a>
    </div>

    <!-- Theme Toggle Button -->
    <button class="login-theme-toggle" onclick="toggleTheme()" title="Toggle Theme">
        <i class="fas fa-moon" id="themeIcon"></i>
    </button>
    </div>

    <script>
    // Theme Toggle for Setup Page
    function initTheme() {
        const savedTheme = localStorage.getItem('theme');
        const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

        if (savedTheme === 'dark' || (!savedTheme && prefersDark)) {
            document.body.classList.add('dark-mode');
            updateThemeIcon(true);
        }
    }

    function toggleTheme() {
        const isDark = document.body.classList.toggle('dark-mode');
        localStorage.setItem('theme', isDark ? 'dark' : 'light');
        updateThemeIcon(isDark);
    }

    function updateThemeIcon(isDark) {
        const icon = document.getElementById('themeIcon');
        if (icon) {
            icon.className = isDark ? 'fas fa-sun' : 'fas fa-moon';
        }
    }

    initTheme();
    </script>
</body>
</html>
