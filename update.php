<?php
/**
 * Developed by Rameez Scripts
 * WhatsApp: https://wa.me/923224083545 (For Custom Projects)
 * YouTube: https://www.youtube.com/@rameezimdad (Subscribe for more!)
 *
 * SAFE DATABASE MIGRATION SCRIPT
 * - Never drops tables or deletes data
 * - Tracks which migrations already ran (schema_migrations table)
 * - Idempotent: safe to run multiple times
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once __DIR__ . '/config.php';
ini_set('display_errors', 1);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Database Update</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="styles.css?v=4.0">
</head>
<body>
    <div class="setup-wrapper">
    <div class="setup-container">
        <h2><i class="fas fa-arrow-up-right-dots"></i> Database Update</h2>
        <p class="subtitle">Safe incremental migrations — your data stays intact.</p>
        <hr>

        <?php
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if ($conn->connect_error) {
            echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Connection failed: ' . $conn->connect_error . '</div>';
            die();
        }
        echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Database connection OK</div>';

        // ── migration tracker table ──
        $conn->query("CREATE TABLE IF NOT EXISTS schema_migrations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            migration VARCHAR(100) NOT NULL UNIQUE,
            ran_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        function migrationRan($conn, $name) {
            $stmt = $conn->prepare("SELECT id FROM schema_migrations WHERE migration = ?");
            $stmt->bind_param("s", $name);
            $stmt->execute();
            $ran = $stmt->get_result()->num_rows > 0;
            $stmt->close();
            return $ran;
        }

        function markRan($conn, $name) {
            $stmt = $conn->prepare("INSERT IGNORE INTO schema_migrations (migration) VALUES (?)");
            $stmt->bind_param("s", $name);
            $stmt->execute();
            $stmt->close();
        }

        function colExists($conn, $table, $col) {
            $r = $conn->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$table' AND COLUMN_NAME = '$col' LIMIT 1");
            return $r && $r->num_rows > 0;
        }

        function tableExists($conn, $table) {
            $r = $conn->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$table' LIMIT 1");
            return $r && $r->num_rows > 0;
        }

        function idxExists($conn, $table, $idx) {
            $r = $conn->query("SHOW INDEX FROM `$table` WHERE Key_name = '$idx'");
            return $r && $r->num_rows > 0;
        }

        function fkExists($conn, $table, $fk) {
            $r = $conn->query("SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$table' AND CONSTRAINT_NAME = '$fk' AND CONSTRAINT_TYPE = 'FOREIGN KEY' LIMIT 1");
            return $r && $r->num_rows > 0;
        }

        $totalRan = 0;
        $totalSkipped = 0;

        // ============================================================
        // helper to run a migration
        // ============================================================
        function runMigration($conn, $name, $description, $callback) {
            global $totalRan, $totalSkipped;
            if (migrationRan($conn, $name)) {
                $totalSkipped++;
                return;
            }
            echo '<div class="log-item log-info"><i class="fas fa-play-circle"></i> <strong>' . $name . '</strong> — ' . htmlspecialchars($description) . '</div>';
            $err = $callback($conn);
            if ($err) {
                echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> FAILED: ' . htmlspecialchars($err) . '</div>';
            } else {
                markRan($conn, $name);
                echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Done</div>';
                $totalRan++;
            }
        }

        echo '<br><div class="log-item log-info"><i class="fas fa-list-check"></i> <strong>Running migrations...</strong></div><br>';

        // ============================================================
        // MIGRATIONS — add new ones at the bottom, never edit old ones
        // ============================================================

        // ── 001: warehouse_code column ──
        runMigration($conn, '001_warehouse_code', 'Add warehouse_code to settings_warehouses', function($c) {
            if (colExists($c, 'settings_warehouses', 'warehouse_code')) return null;
            if (!$c->query("ALTER TABLE settings_warehouses ADD COLUMN warehouse_code VARCHAR(5) NOT NULL DEFAULT '' AFTER warehouse_name")) {
                return $c->error;
            }
            $c->query("ALTER TABLE settings_warehouses ADD INDEX idx_warehouse_code (warehouse_code)");
            // auto-generate codes from warehouse names
            $res = $c->query("SELECT warehouse_id, warehouse_name FROM settings_warehouses WHERE warehouse_code = '' OR warehouse_code IS NULL");
            if ($res) {
                $usedCodes = [];
                while ($r = $res->fetch_assoc()) {
                    $name = $r['warehouse_name'];
                    // take first 3 consonant-heavy chars
                    $clean = preg_replace('/[^A-Za-z]/', '', $name);
                    $code = strtoupper(substr($clean, 0, 3));
                    if (empty($code)) $code = 'WH' . $r['warehouse_id'];
                    // avoid dups
                    $base = $code;
                    $i = 1;
                    while (in_array($code, $usedCodes)) {
                        $code = substr($base, 0, 2) . $i;
                        $i++;
                    }
                    $usedCodes[] = $code;
                    $stmt = $c->prepare("UPDATE settings_warehouses SET warehouse_code = ? WHERE warehouse_id = ?");
                    $stmt->bind_param("si", $code, $r['warehouse_id']);
                    $stmt->execute();
                    $stmt->close();
                }
            }
            return null;
        });

        // ── 002: expenses approval columns ──
        runMigration($conn, '002_expense_approval', 'Add status/submitted_by/reviewed_by/reviewed_at to expenses', function($c) {
            $errs = [];
            if (!colExists($c, 'expenses', 'status')) {
                if (!$c->query("ALTER TABLE expenses ADD COLUMN status ENUM('Pending','Approved','Rejected') DEFAULT 'Approved' AFTER season")) {
                    $errs[] = $c->error;
                }
                $c->query("ALTER TABLE expenses ADD INDEX idx_exp_status (status)");
            }
            if (!colExists($c, 'expenses', 'submitted_by')) {
                if (!$c->query("ALTER TABLE expenses ADD COLUMN submitted_by INT NULL AFTER status")) {
                    $errs[] = $c->error;
                }
            }
            if (!colExists($c, 'expenses', 'reviewed_by')) {
                if (!$c->query("ALTER TABLE expenses ADD COLUMN reviewed_by INT NULL AFTER submitted_by")) {
                    $errs[] = $c->error;
                }
            }
            if (!colExists($c, 'expenses', 'reviewed_at')) {
                if (!$c->query("ALTER TABLE expenses ADD COLUMN reviewed_at TIMESTAMP NULL AFTER reviewed_by")) {
                    $errs[] = $c->error;
                }
            }
            // FKs (ignore errors if they already exist or names collide)
            $c->query("ALTER TABLE expenses ADD CONSTRAINT fk_exp_submitted_by FOREIGN KEY (submitted_by) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL");
            $c->query("ALTER TABLE expenses ADD CONSTRAINT fk_exp_reviewed_by FOREIGN KEY (reviewed_by) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL");
            // mark existing expenses as Approved
            $c->query("UPDATE expenses SET status = 'Approved' WHERE status IS NULL OR status = ''");
            return empty($errs) ? null : implode('; ', $errs);
        });

        // ── 003: ensure all core tables exist (for partial setups) ──
        runMigration($conn, '003_ensure_core_tables', 'Create any missing core tables', function($c) {
            // schema_migrations already exists

            if (!tableExists($c, 'users')) {
                $c->query("CREATE TABLE users (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }

            if (!tableExists($c, 'activity_logs')) {
                $c->query("CREATE TABLE activity_logs (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }

            if (!tableExists($c, 'system_settings')) {
                $c->query("CREATE TABLE system_settings (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    setting_key VARCHAR(100) NOT NULL UNIQUE,
                    setting_value TEXT NOT NULL,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_setting_key (setting_key)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }

            if (!tableExists($c, 'login_attempts')) {
                $c->query("CREATE TABLE login_attempts (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    email VARCHAR(200) NOT NULL,
                    attempt_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    ip_address VARCHAR(45) NOT NULL,
                    INDEX idx_email (email),
                    INDEX idx_attempt_time (attempt_time)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }

            if (!tableExists($c, 'settings_locations')) {
                $c->query("CREATE TABLE settings_locations (
                    location_id INT AUTO_INCREMENT PRIMARY KEY,
                    location_name VARCHAR(150) NOT NULL UNIQUE,
                    is_active BOOLEAN DEFAULT TRUE,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_location_name (location_name),
                    INDEX idx_is_active (is_active)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }

            if (!tableExists($c, 'settings_contract_types')) {
                $c->query("CREATE TABLE settings_contract_types (
                    contract_type_id INT AUTO_INCREMENT PRIMARY KEY,
                    contract_type_name VARCHAR(100) NOT NULL UNIQUE,
                    is_active BOOLEAN DEFAULT TRUE,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_contract_type_name (contract_type_name),
                    INDEX idx_is_active (is_active)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }

            if (!tableExists($c, 'settings_supplier_types')) {
                $c->query("CREATE TABLE settings_supplier_types (
                    supplier_type_id INT AUTO_INCREMENT PRIMARY KEY,
                    supplier_type_name VARCHAR(100) NOT NULL UNIQUE,
                    is_active BOOLEAN DEFAULT TRUE,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_supplier_type_name (supplier_type_name),
                    INDEX idx_is_active (is_active)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }

            if (!tableExists($c, 'settings_warehouses')) {
                $c->query("CREATE TABLE settings_warehouses (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }

            if (!tableExists($c, 'settings_expense_categories')) {
                $c->query("CREATE TABLE settings_expense_categories (
                    category_id INT AUTO_INCREMENT PRIMARY KEY,
                    category_name VARCHAR(100) NOT NULL UNIQUE,
                    is_active BOOLEAN DEFAULT TRUE,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_category_name (category_name),
                    INDEX idx_is_active (is_active)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }

            if (!tableExists($c, 'settings_bag_types')) {
                $c->query("CREATE TABLE settings_bag_types (
                    bag_type_id INT AUTO_INCREMENT PRIMARY KEY,
                    bag_type_name VARCHAR(100) NOT NULL UNIQUE,
                    is_active BOOLEAN DEFAULT TRUE,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_bag_type_name (bag_type_name),
                    INDEX idx_is_active (is_active)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }

            if (!tableExists($c, 'settings_paperwork_types')) {
                $c->query("CREATE TABLE settings_paperwork_types (
                    paperwork_type_id INT AUTO_INCREMENT PRIMARY KEY,
                    paperwork_type_name VARCHAR(100) NOT NULL UNIQUE,
                    is_active BOOLEAN DEFAULT TRUE,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_paperwork_type_name (paperwork_type_name),
                    INDEX idx_is_active (is_active)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }

            if (!tableExists($c, 'settings_seasons')) {
                $c->query("CREATE TABLE settings_seasons (
                    season_id INT AUTO_INCREMENT PRIMARY KEY,
                    season_name VARCHAR(20) NOT NULL UNIQUE,
                    start_date DATE NULL,
                    end_date DATE NULL,
                    is_active BOOLEAN DEFAULT FALSE,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_season_name (season_name),
                    INDEX idx_is_active (is_active)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }

            if (!tableExists($c, 'settings_currencies')) {
                $c->query("CREATE TABLE settings_currencies (
                    currency_id INT AUTO_INCREMENT PRIMARY KEY,
                    currency_code VARCHAR(10) NOT NULL UNIQUE,
                    currency_name VARCHAR(50) NOT NULL,
                    currency_symbol VARCHAR(10) NOT NULL,
                    exchange_rate_to_base DECIMAL(12,6) DEFAULT 1.000000,
                    is_base BOOLEAN DEFAULT FALSE,
                    is_active BOOLEAN DEFAULT TRUE,
                    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_currency_code (currency_code)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }

            if (!tableExists($c, 'fleet_vehicles')) {
                $c->query("CREATE TABLE fleet_vehicles (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }

            if (!tableExists($c, 'fleet_paperworks')) {
                $c->query("CREATE TABLE fleet_paperworks (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }

            if (!tableExists($c, 'driver_salary_payments')) {
                $c->query("CREATE TABLE driver_salary_payments (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }

            if (!tableExists($c, 'customers')) {
                $c->query("CREATE TABLE customers (
                    customer_id VARCHAR(20) PRIMARY KEY,
                    customer_name VARCHAR(200) NOT NULL,
                    contact_person VARCHAR(150) NULL,
                    phone VARCHAR(20) NULL,
                    phone2 VARCHAR(20) NULL,
                    email VARCHAR(200) NULL,
                    location_id INT NULL,
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }

            if (!tableExists($c, 'suppliers')) {
                $c->query("CREATE TABLE suppliers (
                    supplier_id VARCHAR(20) PRIMARY KEY,
                    first_name VARCHAR(150) NOT NULL,
                    phone VARCHAR(20) NULL,
                    phone2 VARCHAR(20) NULL,
                    email VARCHAR(200) NULL,
                    id_number VARCHAR(50) NULL,
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }

            if (!tableExists($c, 'lots')) {
                $c->query("CREATE TABLE lots (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }

            if (!tableExists($c, 'purchases')) {
                $c->query("CREATE TABLE purchases (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }

            if (!tableExists($c, 'deliveries')) {
                $c->query("CREATE TABLE deliveries (
                    delivery_id VARCHAR(20) PRIMARY KEY,
                    date DATE NOT NULL,
                    customer_id VARCHAR(20) NULL,
                    customer_name VARCHAR(200) NULL,
                    origin_warehouse_id INT NULL,
                    vehicle_id VARCHAR(20) NULL,
                    driver_name VARCHAR(150) NULL,
                    weight_kg DECIMAL(12,2) NOT NULL,
                    num_bags INT DEFAULT NULL,
                    procurement_cost_per_kg DECIMAL(10,2) NULL,
                    transport_cost DECIMAL(12,2) DEFAULT 0,
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }

            if (!tableExists($c, 'delivery_items')) {
                $c->query("CREATE TABLE delivery_items (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }

            if (!tableExists($c, 'sales')) {
                $c->query("CREATE TABLE sales (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }

            if (!tableExists($c, 'financing')) {
                $c->query("CREATE TABLE financing (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }

            if (!tableExists($c, 'payments')) {
                $c->query("CREATE TABLE payments (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }

            if (!tableExists($c, 'expenses')) {
                $c->query("CREATE TABLE expenses (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }

            if (!tableExists($c, 'customer_pricing_agreements')) {
                $c->query("CREATE TABLE customer_pricing_agreements (
                    price_agreement_id VARCHAR(20) PRIMARY KEY,
                    effective_date DATE NOT NULL,
                    customer_id VARCHAR(20) NULL,
                    customer_name VARCHAR(200) NULL,
                    contract_type_id INT NULL,
                    base_cost_per_kg DECIMAL(10,2) NOT NULL,
                    status ENUM('Active','Expired','Superseded') DEFAULT 'Active',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_cpa_customer_id (customer_id),
                    INDEX idx_cpa_status (status),
                    INDEX idx_cpa_effective_date (effective_date),
                    FOREIGN KEY (customer_id) REFERENCES customers(customer_id) ON UPDATE CASCADE ON DELETE SET NULL,
                    FOREIGN KEY (contract_type_id) REFERENCES settings_contract_types(contract_type_id) ON UPDATE CASCADE ON DELETE SET NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }

            if (!tableExists($c, 'supplier_pricing_agreements')) {
                $c->query("CREATE TABLE supplier_pricing_agreements (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }

            if (!tableExists($c, 'bags_log')) {
                $c->query("CREATE TABLE bags_log (
                    bag_log_id INT AUTO_INCREMENT PRIMARY KEY,
                    date DATE NOT NULL,
                    customer_id VARCHAR(20) NULL,
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
                    INDEX idx_bl_bag_type_id (bag_type_id),
                    INDEX idx_bl_truck_id (truck_id),
                    INDEX idx_bl_season (season),
                    FOREIGN KEY (customer_id) REFERENCES customers(customer_id) ON UPDATE CASCADE ON DELETE SET NULL,
                    FOREIGN KEY (bag_type_id) REFERENCES settings_bag_types(bag_type_id) ON UPDATE CASCADE ON DELETE SET NULL,
                    FOREIGN KEY (truck_id) REFERENCES fleet_vehicles(vehicle_id) ON UPDATE CASCADE ON DELETE SET NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }

            return null;
        });

        // ── 004: add missing FKs for pricing agreements ──
        runMigration($conn, '004_pricing_fks', 'Add pricing agreement FKs to sales and purchases', function($c) {
            // only add if tables exist and FK doesn't
            if (tableExists($c, 'sales') && tableExists($c, 'customer_pricing_agreements')) {
                $c->query("ALTER TABLE sales ADD FOREIGN KEY (price_agreement_id) REFERENCES customer_pricing_agreements(price_agreement_id) ON UPDATE CASCADE ON DELETE SET NULL");
            }
            if (tableExists($c, 'purchases') && tableExists($c, 'supplier_pricing_agreements')) {
                $c->query("ALTER TABLE purchases ADD FOREIGN KEY (price_agreement_id) REFERENCES supplier_pricing_agreements(price_agreement_id) ON UPDATE CASCADE ON DELETE SET NULL");
            }
            return null;
        });


        // ── 005: whatsapp_phone column ──
        runMigration($conn, '005_whatsapp_phone', 'Add whatsapp_phone to suppliers', function($c) {
            if (colExists($c, 'suppliers', 'whatsapp_phone')) return null;
            if (!$c->query("ALTER TABLE suppliers ADD COLUMN whatsapp_phone VARCHAR(20) NULL AFTER phone2")) {
                return $c->error;
            }
            return null;
        });

        // ── 007: customer GPS coords ──
        runMigration($conn, '007_customer_gps', 'Add GPS coordinates to customers', function($c) {
            if (!colExists($c, 'customers', 'latitude')) {
                $c->query("ALTER TABLE customers ADD COLUMN latitude DECIMAL(10,7) NULL AFTER location_id");
            }
            if (!colExists($c, 'customers', 'longitude')) {
                $c->query("ALTER TABLE customers ADD COLUMN longitude DECIMAL(10,7) NULL AFTER latitude");
            }
            return null;
        });

        // ── 008: notifications table ──
        runMigration($conn, '008_notifications', 'Create notifications table', function($c) {
            if (tableExists($c, 'notifications')) return null;
            $sql = "CREATE TABLE notifications (
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
            if (!$c->query($sql)) return $c->error;
            return null;
        });

        runMigration($conn, '009_delivery_rental', 'Add rental vehicle fields to deliveries', function($c) {
            if (!colExists($c, 'deliveries', 'vehicle_type')) {
                $c->query("ALTER TABLE deliveries ADD COLUMN vehicle_type ENUM('Owned','Rental') DEFAULT 'Owned' AFTER driver_name");
            }
            if (!colExists($c, 'deliveries', 'rental_driver_name')) {
                $c->query("ALTER TABLE deliveries ADD COLUMN rental_driver_name VARCHAR(150) NULL AFTER vehicle_type");
            }
            if (!colExists($c, 'deliveries', 'rental_driver_phone')) {
                $c->query("ALTER TABLE deliveries ADD COLUMN rental_driver_phone VARCHAR(20) NULL AFTER rental_driver_name");
            }
            if (!colExists($c, 'deliveries', 'rental_vehicle_reg')) {
                $c->query("ALTER TABLE deliveries ADD COLUMN rental_vehicle_reg VARCHAR(50) NULL AFTER rental_driver_phone");
            }
            return null;
        });

        runMigration($conn, '010_supplier_dob', 'Add date_of_birth to suppliers', function($c) {
            if (colExists($c, 'suppliers', 'date_of_birth')) return null;
            if (!$c->query("ALTER TABLE suppliers ADD COLUMN date_of_birth DATE NULL AFTER id_number")) return $c->error;
            return null;
        });

        runMigration($conn, '011_customer_pricing_notes', 'Add pricing_notes, update contract types', function($c) {
            // add pricing_notes col
            if (!colExists($c, 'customer_pricing_agreements', 'pricing_notes')) {
                if (!$c->query("ALTER TABLE customer_pricing_agreements ADD COLUMN pricing_notes TEXT NULL AFTER base_cost_per_kg")) return $c->error;
            }
            // make base_cost_per_kg nullable
            if (!$c->query("ALTER TABLE customer_pricing_agreements MODIFY COLUMN base_cost_per_kg DECIMAL(10,2) NULL")) return $c->error;
            // deactivate old incoterm types
            $c->query("UPDATE settings_contract_types SET is_active = 0 WHERE contract_type_name IN ('FOB','CIF','CFR','EXW')");
            // insert new contract types
            $newTypes = ['Market/Quality Related', 'Margin + Cost', 'Fixed Price', 'Exposition Sales'];
            foreach ($newTypes as $t) {
                $c->query("INSERT IGNORE INTO settings_contract_types (contract_type_name) VALUES ('" . $c->real_escape_string($t) . "')");
            }
            return null;
        });

        runMigration($conn, '012_delivery_rejection_date', 'Add rejection_date to deliveries', function($c) {
            if (!colExists($c, 'deliveries', 'rejection_date')) {
                if (!$c->query("ALTER TABLE deliveries ADD COLUMN rejection_date DATE NULL AFTER rejection_reason")) return $c->error;
            }
            return null;
        });

        runMigration($conn, '013_sale_receipt_file', 'Add receipt_file to sales', function($c) {
            if (!colExists($c, 'sales', 'receipt_file')) {
                if (!$c->query("ALTER TABLE sales ADD COLUMN receipt_file VARCHAR(500) NULL AFTER season")) return $c->error;
            }
            return null;
        });

        runMigration($conn, '014_customer_financing_balance', 'Add financing_balance to customers', function($c) {
            if (!colExists($c, 'customers', 'financing_balance')) {
                if (!$c->query("ALTER TABLE customers ADD COLUMN financing_balance DECIMAL(15,2) DEFAULT 0.00 AFTER financing_provided")) return $c->error;
            }
            return null;
        });

        runMigration($conn, '015_delivery_items_cascade', 'Change delivery_items purchase FK to ON DELETE CASCADE', function($c) {
            if (!fkExists($c, 'delivery_items', 'delivery_items_ibfk_2')) return null;
            $c->query("ALTER TABLE delivery_items DROP FOREIGN KEY delivery_items_ibfk_2");
            if (!$c->query("ALTER TABLE delivery_items ADD CONSTRAINT delivery_items_ibfk_2 FOREIGN KEY (purchase_id) REFERENCES purchases(purchase_id) ON UPDATE CASCADE ON DELETE CASCADE")) {
                return $c->error;
            }
            return null;
        });

        runMigration($conn, '016_sale_purchase_weight', 'Snapshot purchase (loaded) weight on sales + recompute costs', function($c) {
            // add col
            if (!colExists($c, 'sales', 'purchase_weight_kg')) {
                if (!$c->query("ALTER TABLE sales ADD COLUMN purchase_weight_kg DECIMAL(12,2) DEFAULT 0 AFTER net_weight_kg")) return $c->error;
            }

            // backfill snapshot from delivery loaded weight
            $c->query("UPDATE sales s JOIN deliveries d ON s.delivery_id = d.delivery_id
                       SET s.purchase_weight_kg = d.weight_kg
                       WHERE (s.purchase_weight_kg IS NULL OR s.purchase_weight_kg = 0) AND d.weight_kg > 0");

            // recompute product cost / total_costs / margins for ALL sales using purchase weight
            // formulas mirror sales.php addSale/updateSale
            $sql = "UPDATE sales s
                    JOIN deliveries d ON s.delivery_id = d.delivery_id
                    SET
                        s.total_costs    = ROUND((d.procurement_cost_per_kg * s.purchase_weight_kg) + s.transport_cost + s.other_expenses + s.interest_fees, 2),
                        s.gross_margin   = ROUND(s.gross_sale_amount - ((d.procurement_cost_per_kg * s.purchase_weight_kg) + s.transport_cost + s.other_expenses + s.interest_fees), 2),
                        s.net_revenue    = ROUND(s.gross_sale_amount - s.other_expenses - s.interest_fees, 2),
                        s.net_profit     = ROUND(s.gross_sale_amount - ((d.procurement_cost_per_kg * s.purchase_weight_kg) + s.transport_cost + s.other_expenses + s.interest_fees), 2),
                        s.profit_per_kg  = CASE WHEN s.net_weight_kg > 0
                                                THEN ROUND((s.gross_sale_amount - ((d.procurement_cost_per_kg * s.purchase_weight_kg) + s.transport_cost + s.other_expenses + s.interest_fees)) / s.net_weight_kg, 2)
                                                ELSE 0 END
                    WHERE s.purchase_weight_kg > 0 AND d.procurement_cost_per_kg IS NOT NULL";
            if (!$c->query($sql)) return $c->error;

            return null;
        });

        // ── 017: banks master table ──
        runMigration($conn, '017_banks_table', 'Create banks master table for bank financing counterparties', function($c) {
            if (!tableExists($c, 'banks')) {
                $sql = "CREATE TABLE banks (
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
                if (!$c->query($sql)) return $c->error;
            }

            // backfill: any existing bank financing rows get a bank_id
            // group by counterpart_name, create bank rows, then update financing.counterparty_id
            $existing = $c->query("SELECT DISTINCT counterpart_name FROM financing WHERE counterpart_type = 'Bank' AND counterpart_name IS NOT NULL AND counterpart_name <> ''");
            if ($existing && $existing->num_rows > 0) {
                // find next bank seq
                $maxRes = $c->query("SELECT bank_id FROM banks WHERE bank_id LIKE 'BANK-%' ORDER BY bank_id DESC LIMIT 1");
                $maxNum = 0;
                if ($maxRes && $maxRes->num_rows > 0) {
                    $r = $maxRes->fetch_assoc();
                    $maxNum = intval(substr($r['bank_id'], 5));
                }
                while ($row = $existing->fetch_assoc()) {
                    $bn = $row['counterpart_name'];
                    // skip if already a bank with this name
                    $chk = $c->prepare("SELECT bank_id FROM banks WHERE bank_name = ? LIMIT 1");
                    $chk->bind_param("s", $bn);
                    $chk->execute();
                    $chkRes = $chk->get_result();
                    if ($chkRes->num_rows > 0) {
                        $bankId = $chkRes->fetch_assoc()['bank_id'];
                    } else {
                        $maxNum++;
                        $bankId = 'BANK-' . str_pad($maxNum, 3, '0', STR_PAD_LEFT);
                        $ins = $c->prepare("INSERT INTO banks (bank_id, bank_name, status) VALUES (?, ?, 'Active')");
                        $ins->bind_param("ss", $bankId, $bn);
                        $ins->execute();
                        $ins->close();
                    }
                    $chk->close();
                    // point all financing rows for this bank name at the new id
                    $upd = $c->prepare("UPDATE financing SET counterparty_id = ? WHERE counterpart_type = 'Bank' AND counterpart_name = ?");
                    $upd->bind_param("ss", $bankId, $bn);
                    $upd->execute();
                    $upd->close();
                }
            }
            return null;
        });

        // ── 019: road_fees on deliveries — informal fees handed to drivers
        runMigration($conn, '019_delivery_road_fees', 'Add road_fees to deliveries', function($c) {
            if (colExists($c, 'deliveries', 'road_fees')) return null;
            if (!$c->query("ALTER TABLE deliveries ADD COLUMN road_fees DECIMAL(12,2) DEFAULT 0 AFTER transport_cost")) return $c->error;
            return null;
        });

        // ── 020: daily snapshots of supplier ranking — lets the ranking page show who climbed / dropped
        runMigration($conn, '020_supplier_ranking_snapshots', 'Create supplier_ranking_snapshots table for movement tracking', function($c) {
            $sql = "CREATE TABLE IF NOT EXISTS supplier_ranking_snapshots (
                id INT AUTO_INCREMENT PRIMARY KEY,
                snapshot_date DATE NOT NULL,
                supplier_id VARCHAR(20) NOT NULL,
                rank_position INT NOT NULL,
                total_score DECIMAL(5,1) NOT NULL,
                tier CHAR(1) NOT NULL,
                UNIQUE KEY uniq_day_supplier (snapshot_date, supplier_id),
                INDEX idx_snap_date (snapshot_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            if (!$c->query($sql)) return $c->error;
            return null;
        });

        // ── 018: rebuild supplier ledger from canonical reconciler ──
        // one-time cleanup of stale Auto-Payable / Auto-Overpayment rows + wrong purchase statuses
        // left behind by the old fragmented reconciliation paths
        runMigration($conn, '018_reconcile_supplier_ledger', 'Rebuild every supplier account via reconcileSupplierAccount', function($c) {
            $r = $c->query("SELECT supplier_id FROM suppliers");
            if (!$r) return $c->error;
            $ids = [];
            while ($row = $r->fetch_assoc()) $ids[] = $row['supplier_id'];

            $count = 0;
            foreach ($ids as $sid) {
                try {
                    reconcileSupplierAccount($c, $sid);
                    $count++;
                } catch (\Throwable $e) {
                    error_log("018_reconcile_supplier_ledger: $sid → " . $e->getMessage());
                }
            }
            echo '<div class="log-item" style="padding-left:30px;"><i class="fas fa-info-circle"></i> Reconciled ' . $count . ' suppliers</div>';
            return null;
        });

        // ── 021: add supplier_id to bags_log for tracking bags given to suppliers ──
        runMigration($conn, '021_bags_log_supplier', 'Add supplier_id column to bags_log', function($c) {
            if (!colExists($c, 'bags_log', 'supplier_id')) {
                $sql = "ALTER TABLE bags_log ADD COLUMN supplier_id VARCHAR(20) NULL AFTER customer_id, ADD INDEX idx_bl_supplier_id (supplier_id), ADD FOREIGN KEY (supplier_id) REFERENCES suppliers(supplier_id) ON UPDATE CASCADE ON DELETE SET NULL";
                if (!$c->query($sql)) return $c->error;
            }
            return null;
        });

        // ============================================================
        // ADD NEW MIGRATIONS ABOVE THIS LINE
        // Format: runMigration($conn, '005_description', 'What it does', function($c) { ... });
        // ============================================================

        echo '<br>';
        if ($totalRan === 0 && $totalSkipped > 0) {
            echo '<div class="log-item log-success"><i class="fas fa-check-double"></i> <strong>Database is up to date!</strong> All ' . $totalSkipped . ' migrations already applied.</div>';
        } elseif ($totalRan > 0) {
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> <strong>Update complete!</strong> ' . $totalRan . ' migration(s) applied, ' . $totalSkipped . ' already up to date.</div>';
        } else {
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> <strong>All done!</strong></div>';
        }

        // show current schema version
        $vRes = $conn->query("SELECT migration, ran_at FROM schema_migrations ORDER BY id DESC LIMIT 5");
        if ($vRes && $vRes->num_rows > 0) {
            echo '<br><div class="log-item log-info"><i class="fas fa-clock-rotate-left"></i> <strong>Recent migrations:</strong></div>';
            while ($v = $vRes->fetch_assoc()) {
                echo '<div class="log-item" style="padding-left:30px;"><code>' . htmlspecialchars($v['migration']) . '</code> — ' . $v['ran_at'] . '</div>';
            }
        }

        $conn->close();
        ?>

        <br>
        <a href="login.php" class="btn">
            <i class="fas fa-sign-in-alt"></i> Go to Login Page
        </a>
        <a href="dashboard.php" class="btn" style="margin-left:8px;background:var(--navy-accent);">
            <i class="fas fa-chart-line"></i> Go to Dashboard
        </a>
    </div>

    <button class="login-theme-toggle" onclick="toggleTheme()" title="Toggle Theme">
        <i class="fas fa-moon" id="themeIcon"></i>
    </button>
    </div>

    <script>
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
        if (icon) icon.className = isDark ? 'fas fa-sun' : 'fas fa-moon';
    }
    initTheme();
    </script>
</body>
</html>
