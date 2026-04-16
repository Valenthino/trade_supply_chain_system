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
$role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];
$current_page = 'fleet';

// RBAC
$allowedRoles = ['Admin', 'Manager', 'Fleet Manager', 'Procurement Officer'];
if (!in_array($role, $allowedRoles)) {
    header("Location: dashboard.php");
    exit();
}

$canCreate = in_array($role, ['Admin', 'Manager', 'Procurement Officer']);
$canUpdate = in_array($role, ['Admin', 'Manager', 'Fleet Manager', 'Procurement Officer']);
$canDelete = ($role === 'Admin');

// Handle AJAX requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    try {
        switch ($_GET['action']) {
            case 'getFleet':
                $conn = getDBConnection();
                $stmt = $conn->prepare("SELECT fv.*,
                    (SELECT COUNT(*) FROM deliveries d WHERE d.vehicle_id = fv.vehicle_id) AS total_trips_ytd,
                    (SELECT COALESCE(SUM(d.weight_kg), 0) FROM deliveries d WHERE d.vehicle_id = fv.vehicle_id) AS total_weight_hauled_kg,
                    (SELECT COUNT(*) FROM fleet_paperworks fp WHERE fp.vehicle_id = fv.vehicle_id AND fp.expiry_date < CURDATE()) AS expired_papers,
                    (SELECT COUNT(*) FROM fleet_paperworks fp WHERE fp.vehicle_id = fv.vehicle_id AND fp.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)) AS expiring_papers,
                    (SELECT COUNT(*) FROM fleet_paperworks fp WHERE fp.vehicle_id = fv.vehicle_id) AS total_papers
                    FROM fleet_vehicles fv
                    ORDER BY fv.vehicle_id DESC");
                $stmt->execute();
                $result = $stmt->get_result();

                $fleet = [];
                while ($row = $result->fetch_assoc()) {
                    $fleet[] = [
                        'vehicle_id' => $row['vehicle_id'],
                        'vehicle_registration' => $row['vehicle_registration'],
                        'vehicle_model' => $row['vehicle_model'] ?? '',
                        'driver_name' => $row['driver_name'],
                        'phone_number' => $row['phone_number'] ?? '',
                        'driver_license_no' => $row['driver_license_no'] ?? '',
                        'license_expiry' => $row['license_expiry'] ? date('M d, Y', strtotime($row['license_expiry'])) : '',
                        'license_expiry_raw' => $row['license_expiry'] ?? '',
                        'vehicle_acquisition_date' => $row['vehicle_acquisition_date'] ?? '',
                        'status' => $row['status'] ?? 'Available',
                        'driver_salary' => $row['driver_salary'] ?? 0,
                        'alert_threshold' => $row['alert_threshold'] ?? 5.00,
                        'season' => $row['season'] ?? '',
                        'total_trips_ytd' => $row['total_trips_ytd'],
                        'total_weight_hauled_kg' => $row['total_weight_hauled_kg'],
                        'expired_papers' => intval($row['expired_papers']),
                        'expiring_papers' => intval($row['expiring_papers']),
                        'total_papers' => intval($row['total_papers']),
                        'created_at' => $row['created_at'] ? date('M d, Y', strtotime($row['created_at'])) : ''
                    ];
                }

                $stmt->close();
                $conn->close();

                echo json_encode(['success' => true, 'data' => $fleet]);
                exit();

            case 'addVehicle':
                if (!$canCreate) {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $vehicleRegistration = isset($_POST['vehicle_registration']) ? trim($_POST['vehicle_registration']) : '';
                $vehicleModel = isset($_POST['vehicle_model']) ? trim($_POST['vehicle_model']) : '';
                $driverName = isset($_POST['driver_name']) ? trim($_POST['driver_name']) : '';
                $phoneNumber = isset($_POST['phone_number']) ? trim($_POST['phone_number']) : '';
                $driverLicenseNo = isset($_POST['driver_license_no']) ? trim($_POST['driver_license_no']) : '';
                $licenseExpiry = !empty($_POST['license_expiry']) ? trim($_POST['license_expiry']) : null;
                $vehicleAcquisitionDate = !empty($_POST['vehicle_acquisition_date']) ? trim($_POST['vehicle_acquisition_date']) : null;
                $status = 'Available';
                $driverSalary = isset($_POST['driver_salary']) ? floatval($_POST['driver_salary']) : 0;
                $alertThreshold = isset($_POST['alert_threshold']) ? floatval($_POST['alert_threshold']) : 5.00;
                $season = isset($_POST['season']) ? trim($_POST['season']) : getActiveSeason();

                // Validation
                if (empty($vehicleRegistration)) {
                    echo json_encode(['success' => false, 'message' => 'Vehicle registration is required']);
                    exit();
                }
                if (empty($driverName)) {
                    echo json_encode(['success' => false, 'message' => 'Driver name is required']);
                    exit();
                }

                $conn = getDBConnection();

                // Check unique registration
                $stmt = $conn->prepare("SELECT vehicle_id FROM fleet_vehicles WHERE vehicle_registration = ?");
                $stmt->bind_param("s", $vehicleRegistration);
                $stmt->execute();
                if ($stmt->get_result()->num_rows > 0) {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Vehicle registration already exists']);
                    exit();
                }
                $stmt->close();

                // Auto-generate vehicle_id
                $result = $conn->query("SELECT vehicle_id FROM fleet_vehicles ORDER BY vehicle_id DESC LIMIT 1");
                $num = ($result->num_rows > 0) ? intval(substr($result->fetch_assoc()['vehicle_id'], 4)) + 1 : 1;
                $newId = 'VEH-' . str_pad($num, 3, '0', STR_PAD_LEFT);

                $stmt = $conn->prepare("INSERT INTO fleet_vehicles (vehicle_id, vehicle_registration, vehicle_model, driver_name, phone_number, driver_license_no, license_expiry, vehicle_acquisition_date, status, driver_salary, alert_threshold, season) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("sssssssssdds",
                    $newId, $vehicleRegistration, $vehicleModel, $driverName, $phoneNumber,
                    $driverLicenseNo, $licenseExpiry, $vehicleAcquisitionDate,
                    $status, $driverSalary, $alertThreshold, $season
                );

                if ($stmt->execute()) {
                    logActivity($user_id, $username, 'Vehicle Created', "Created vehicle: $newId ($vehicleRegistration), Driver: $driverName");
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => true, 'message' => 'Vehicle added successfully', 'vehicle_id' => $newId]);
                } else {
                    $error = $stmt->error;
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to add vehicle: ' . $error]);
                }
                exit();

            case 'updateVehicle':
                if (!$canUpdate) {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $vehicleId = isset($_POST['vehicle_id']) ? trim($_POST['vehicle_id']) : '';
                $vehicleRegistration = isset($_POST['vehicle_registration']) ? trim($_POST['vehicle_registration']) : '';
                $vehicleModel = isset($_POST['vehicle_model']) ? trim($_POST['vehicle_model']) : '';
                $driverName = isset($_POST['driver_name']) ? trim($_POST['driver_name']) : '';
                $phoneNumber = isset($_POST['phone_number']) ? trim($_POST['phone_number']) : '';
                $driverLicenseNo = isset($_POST['driver_license_no']) ? trim($_POST['driver_license_no']) : '';
                $licenseExpiry = !empty($_POST['license_expiry']) ? trim($_POST['license_expiry']) : null;
                $vehicleAcquisitionDate = !empty($_POST['vehicle_acquisition_date']) ? trim($_POST['vehicle_acquisition_date']) : null;
                $status = isset($_POST['status']) ? trim($_POST['status']) : 'Available';
                $driverSalary = isset($_POST['driver_salary']) ? floatval($_POST['driver_salary']) : 0;
                $alertThreshold = isset($_POST['alert_threshold']) ? floatval($_POST['alert_threshold']) : 5.00;
                $season = isset($_POST['season']) ? trim($_POST['season']) : getActiveSeason();

                // Validation
                if (empty($vehicleId)) {
                    echo json_encode(['success' => false, 'message' => 'Vehicle ID is required']);
                    exit();
                }
                if (empty($vehicleRegistration)) {
                    echo json_encode(['success' => false, 'message' => 'Vehicle registration is required']);
                    exit();
                }
                if (empty($driverName)) {
                    echo json_encode(['success' => false, 'message' => 'Driver name is required']);
                    exit();
                }

                $conn = getDBConnection();

                // Check unique registration (exclude current)
                $stmt = $conn->prepare("SELECT vehicle_id FROM fleet_vehicles WHERE vehicle_registration = ? AND vehicle_id != ?");
                $stmt->bind_param("ss", $vehicleRegistration, $vehicleId);
                $stmt->execute();
                if ($stmt->get_result()->num_rows > 0) {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Vehicle registration already exists for another vehicle']);
                    exit();
                }
                $stmt->close();

                $stmt = $conn->prepare("UPDATE fleet_vehicles SET vehicle_registration = ?, vehicle_model = ?, driver_name = ?, phone_number = ?, driver_license_no = ?, license_expiry = ?, vehicle_acquisition_date = ?, status = ?, driver_salary = ?, alert_threshold = ?, season = ? WHERE vehicle_id = ?");
                $stmt->bind_param("ssssssssddss",
                    $vehicleRegistration, $vehicleModel, $driverName, $phoneNumber,
                    $driverLicenseNo, $licenseExpiry, $vehicleAcquisitionDate,
                    $status, $driverSalary, $alertThreshold, $season, $vehicleId
                );

                if ($stmt->execute()) {
                    logActivity($user_id, $username, 'Vehicle Updated', "Updated vehicle: $vehicleId ($vehicleRegistration), Status: $status");
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => true, 'message' => 'Vehicle updated successfully']);
                } else {
                    $error = $stmt->error;
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to update vehicle: ' . $error]);
                }
                exit();

            case 'deleteVehicle':
                if (!$canDelete) {
                    echo json_encode(['success' => false, 'message' => 'Access denied. Only Admin can delete.']);
                    exit();
                }

                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $vehicleId = isset($_POST['vehicle_id']) ? trim($_POST['vehicle_id']) : '';
                if (empty($vehicleId)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid vehicle ID']);
                    exit();
                }

                $conn = getDBConnection();

                // Check linked deliveries
                $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM deliveries WHERE vehicle_id = ?");
                $stmt->bind_param("s", $vehicleId);
                $stmt->execute();
                $cnt = $stmt->get_result()->fetch_assoc()['cnt'];
                $stmt->close();

                if ($cnt > 0) {
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => "Cannot delete — vehicle has $cnt linked delivery(ies)"]);
                    exit();
                }

                // Check linked bags_log
                $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM bags_log WHERE truck_id = ?");
                $stmt->bind_param("s", $vehicleId);
                $stmt->execute();
                $cntBags = $stmt->get_result()->fetch_assoc()['cnt'];
                $stmt->close();

                if ($cntBags > 0) {
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => "Cannot delete — vehicle has $cntBags linked bag log(s)"]);
                    exit();
                }

                // Get info for logging
                $stmt = $conn->prepare("SELECT vehicle_registration, driver_name FROM fleet_vehicles WHERE vehicle_id = ?");
                $stmt->bind_param("s", $vehicleId);
                $stmt->execute();
                $info = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                $stmt = $conn->prepare("DELETE FROM fleet_vehicles WHERE vehicle_id = ?");
                $stmt->bind_param("s", $vehicleId);

                if ($stmt->execute()) {
                    logActivity($user_id, $username, 'Vehicle Deleted', "Deleted vehicle: $vehicleId ({$info['vehicle_registration']}, Driver: {$info['driver_name']})");
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => true, 'message' => 'Vehicle deleted successfully']);
                } else {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to delete vehicle']);
                }
                exit();

            case 'getSalaryPayments':
                $vehicleId = isset($_GET['vehicle_id']) ? trim($_GET['vehicle_id']) : '';
                if (empty($vehicleId)) {
                    echo json_encode(['success' => false, 'message' => 'Vehicle ID is required']);
                    exit();
                }
                $conn = getDBConnection();
                $stmt = $conn->prepare("SELECT * FROM driver_salary_payments WHERE vehicle_id = ? ORDER BY payment_date DESC");
                $stmt->bind_param("s", $vehicleId);
                $stmt->execute();
                $result = $stmt->get_result();
                $payments = [];
                while ($row = $result->fetch_assoc()) {
                    $payments[] = [
                        'payment_id' => $row['payment_id'],
                        'vehicle_id' => $row['vehicle_id'],
                        'driver_name' => $row['driver_name'],
                        'payment_date' => date('M d, Y', strtotime($row['payment_date'])),
                        'payment_date_raw' => $row['payment_date'],
                        'amount' => $row['amount'],
                        'payment_mode' => $row['payment_mode'],
                        'reference_number' => $row['reference_number'] ?? '',
                        'month_for' => $row['month_for'] ?? '',
                        'notes' => $row['notes'] ?? '',
                        'season' => $row['season']
                    ];
                }
                $stmt->close();
                $conn->close();
                echo json_encode(['success' => true, 'data' => $payments]);
                exit();

            case 'addSalaryPayment':
                if (!in_array($role, ['Admin', 'Manager'])) {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }
                $vehicleId = isset($_POST['vehicle_id']) ? trim($_POST['vehicle_id']) : '';
                $driverName = isset($_POST['driver_name']) ? trim($_POST['driver_name']) : '';
                $paymentDate = isset($_POST['payment_date']) ? trim($_POST['payment_date']) : '';
                $amount = isset($_POST['amount']) ? floatval($_POST['amount']) : 0;
                $paymentMode = isset($_POST['payment_mode']) ? trim($_POST['payment_mode']) : 'Cash';
                $referenceNumber = isset($_POST['reference_number']) ? trim($_POST['reference_number']) : null;
                $monthFor = isset($_POST['month_for']) ? trim($_POST['month_for']) : null;
                $notes = isset($_POST['notes']) ? trim($_POST['notes']) : null;
                $season = isset($_POST['season']) ? trim($_POST['season']) : getActiveSeason();

                if (empty($vehicleId) || empty($driverName) || empty($paymentDate) || $amount <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Vehicle, driver, date, and amount are required']);
                    exit();
                }

                $conn = getDBConnection();
                $stmt = $conn->prepare("INSERT INTO driver_salary_payments (vehicle_id, driver_name, payment_date, amount, payment_mode, reference_number, month_for, notes, season) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("sssdsssss", $vehicleId, $driverName, $paymentDate, $amount, $paymentMode, $referenceNumber, $monthFor, $notes, $season);
                if ($stmt->execute()) {
                    logActivity($user_id, $username, 'Salary Payment Added', "Added salary payment of $amount for driver $driverName ($vehicleId)");
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => true, 'message' => 'Salary payment added successfully']);
                } else {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to add salary payment']);
                }
                exit();

            case 'deleteSalaryPayment':
                if ($role !== 'Admin') {
                    echo json_encode(['success' => false, 'message' => 'Access denied. Only Admin can delete.']);
                    exit();
                }
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }
                $paymentId = isset($_POST['payment_id']) ? intval($_POST['payment_id']) : 0;
                if ($paymentId <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid payment ID']);
                    exit();
                }
                $conn = getDBConnection();
                $stmt = $conn->prepare("SELECT driver_name, amount FROM driver_salary_payments WHERE payment_id = ?");
                $stmt->bind_param("i", $paymentId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$row) {
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Payment not found']);
                    exit();
                }
                $stmt = $conn->prepare("DELETE FROM driver_salary_payments WHERE payment_id = ?");
                $stmt->bind_param("i", $paymentId);
                if ($stmt->execute()) {
                    logActivity($user_id, $username, 'Salary Payment Deleted', "Deleted salary payment #{$paymentId} ({$row['amount']} for {$row['driver_name']})");
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => true, 'message' => 'Salary payment deleted']);
                } else {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to delete']);
                }
                exit();

            // ===================== PAPERWORK HANDLERS =====================

            case 'getPaperworkTypes':
                $conn = getDBConnection();
                $stmt = $conn->prepare("SELECT paperwork_type_id, paperwork_type_name FROM settings_paperwork_types WHERE is_active = 1 ORDER BY paperwork_type_name ASC");
                $stmt->execute();
                $result = $stmt->get_result();
                $types = [];
                while ($row = $result->fetch_assoc()) $types[] = $row;
                $stmt->close();
                $conn->close();
                echo json_encode(['success' => true, 'data' => $types]);
                exit();

            case 'getVehiclePaperworks':
                $vehicleId = isset($_GET['vehicle_id']) ? trim($_GET['vehicle_id']) : '';
                if (empty($vehicleId)) {
                    echo json_encode(['success' => false, 'message' => 'Vehicle ID required']);
                    exit();
                }
                $conn = getDBConnection();
                $stmt = $conn->prepare("SELECT fp.*, spt.paperwork_type_name FROM fleet_paperworks fp LEFT JOIN settings_paperwork_types spt ON fp.paperwork_type_id = spt.paperwork_type_id WHERE fp.vehicle_id = ? ORDER BY fp.expiry_date ASC");
                $stmt->bind_param("s", $vehicleId);
                $stmt->execute();
                $result = $stmt->get_result();
                $papers = [];
                while ($row = $result->fetch_assoc()) {
                    $papers[] = [
                        'paperwork_id' => $row['paperwork_id'],
                        'vehicle_id' => $row['vehicle_id'],
                        'paperwork_type_id' => $row['paperwork_type_id'],
                        'paperwork_type_name' => $row['paperwork_type_name'] ?? '',
                        'issue_date' => $row['issue_date'] ? date('M d, Y', strtotime($row['issue_date'])) : '',
                        'issue_date_raw' => $row['issue_date'] ?? '',
                        'expiry_date' => date('M d, Y', strtotime($row['expiry_date'])),
                        'expiry_date_raw' => $row['expiry_date'],
                        'reference_number' => $row['reference_number'] ?? '',
                        'notes' => $row['notes'] ?? '',
                        'season' => $row['season'] ?? ''
                    ];
                }
                $stmt->close();
                $conn->close();
                echo json_encode(['success' => true, 'data' => $papers]);
                exit();

            case 'addPaperwork':
                if (!$canUpdate) {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }
                $vehicleId = isset($_POST['vehicle_id']) ? trim($_POST['vehicle_id']) : '';
                $paperworkTypeId = isset($_POST['paperwork_type_id']) ? intval($_POST['paperwork_type_id']) : 0;
                $issueDate = !empty($_POST['issue_date']) ? trim($_POST['issue_date']) : null;
                $expiryDate = isset($_POST['expiry_date']) ? trim($_POST['expiry_date']) : '';
                $referenceNumber = isset($_POST['reference_number']) ? trim($_POST['reference_number']) : null;
                $notes = isset($_POST['notes']) ? trim($_POST['notes']) : null;
                $season = isset($_POST['season']) ? trim($_POST['season']) : getActiveSeason();

                if (empty($vehicleId) || $paperworkTypeId <= 0 || empty($expiryDate)) {
                    echo json_encode(['success' => false, 'message' => 'Vehicle, paperwork type, and expiry date are required']);
                    exit();
                }

                $conn = getDBConnection();
                $stmt = $conn->prepare("INSERT INTO fleet_paperworks (vehicle_id, paperwork_type_id, issue_date, expiry_date, reference_number, notes, season) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("sisssss", $vehicleId, $paperworkTypeId, $issueDate, $expiryDate, $referenceNumber, $notes, $season);
                if ($stmt->execute()) {
                    $typeStmt = $conn->prepare("SELECT paperwork_type_name FROM settings_paperwork_types WHERE paperwork_type_id = ?");
                    $typeStmt->bind_param("i", $paperworkTypeId);
                    $typeStmt->execute();
                    $typeName = $typeStmt->get_result()->fetch_assoc()['paperwork_type_name'] ?? '';
                    $typeStmt->close();
                    logActivity($user_id, $username, 'Paperwork Added', "Added $typeName for vehicle $vehicleId, expires $expiryDate");
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => true, 'message' => 'Paperwork added successfully']);
                } else {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to add paperwork']);
                }
                exit();

            case 'updatePaperwork':
                if (!$canUpdate) {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }
                $paperworkId = isset($_POST['paperwork_id']) ? intval($_POST['paperwork_id']) : 0;
                $paperworkTypeId = isset($_POST['paperwork_type_id']) ? intval($_POST['paperwork_type_id']) : 0;
                $issueDate = !empty($_POST['issue_date']) ? trim($_POST['issue_date']) : null;
                $expiryDate = isset($_POST['expiry_date']) ? trim($_POST['expiry_date']) : '';
                $referenceNumber = isset($_POST['reference_number']) ? trim($_POST['reference_number']) : null;
                $notes = isset($_POST['notes']) ? trim($_POST['notes']) : null;

                if ($paperworkId <= 0 || $paperworkTypeId <= 0 || empty($expiryDate)) {
                    echo json_encode(['success' => false, 'message' => 'Paperwork ID, type, and expiry date are required']);
                    exit();
                }

                $conn = getDBConnection();
                $stmt = $conn->prepare("UPDATE fleet_paperworks SET paperwork_type_id = ?, issue_date = ?, expiry_date = ?, reference_number = ?, notes = ? WHERE paperwork_id = ?");
                $stmt->bind_param("issssi", $paperworkTypeId, $issueDate, $expiryDate, $referenceNumber, $notes, $paperworkId);
                if ($stmt->execute()) {
                    logActivity($user_id, $username, 'Paperwork Updated', "Updated paperwork #$paperworkId, expires $expiryDate");
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => true, 'message' => 'Paperwork updated successfully']);
                } else {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to update paperwork']);
                }
                exit();

            case 'deletePaperwork':
                if (!$canDelete) {
                    echo json_encode(['success' => false, 'message' => 'Access denied. Only Admin can delete.']);
                    exit();
                }
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }
                $paperworkId = isset($_POST['paperwork_id']) ? intval($_POST['paperwork_id']) : 0;
                if ($paperworkId <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid paperwork ID']);
                    exit();
                }
                $conn = getDBConnection();
                $stmt = $conn->prepare("SELECT fp.vehicle_id, spt.paperwork_type_name FROM fleet_paperworks fp LEFT JOIN settings_paperwork_types spt ON fp.paperwork_type_id = spt.paperwork_type_id WHERE fp.paperwork_id = ?");
                $stmt->bind_param("i", $paperworkId);
                $stmt->execute();
                $info = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$info) {
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Paperwork not found']);
                    exit();
                }
                $stmt = $conn->prepare("DELETE FROM fleet_paperworks WHERE paperwork_id = ?");
                $stmt->bind_param("i", $paperworkId);
                if ($stmt->execute()) {
                    logActivity($user_id, $username, 'Paperwork Deleted', "Deleted {$info['paperwork_type_name']} for vehicle {$info['vehicle_id']}");
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => true, 'message' => 'Paperwork deleted']);
                } else {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to delete']);
                }
                exit();

            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
                exit();
        }
    } catch (Exception $e) {
        error_log("fleet.php error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
        exit();
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
  <title>Commodity Flow — Fleet & Drivers</title>

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
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />

  <!-- DataTables CSS -->
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/jquery.dataTables.min.css">
  <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.dataTables.min.css">
  <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.dataTables.min.css">

  <!-- App Styles -->
  <link rel="stylesheet" href="styles.css">

  <!-- jQuery -->
  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <!-- SweetAlert2 -->
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <!-- DataTables JS -->
  <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
  <script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
  <script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
  <script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
  <script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js"></script>

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
      background-size: 400px 100%;
      animation: shimmer 1.4s ease infinite;
      border-radius: 6px;
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

    /* Active nav link */
    .nav-link.active { background: rgba(45,157,153,0.12); color: #2d9d99; }
    .dark .nav-link.active { background: rgba(45,157,153,0.15); color: #4db8b4; }
    .nav-link.active .nav-icon { color: #2d9d99; }
    .dark .nav-link.active .nav-icon { color: #4db8b4; }
    .nav-link.active::before {
      content: '';
      position: absolute; left: 0; top: 15%; bottom: 15%;
      width: 3px; background: #2d9d99; border-radius: 0 3px 3px 0;
    }

    /* DataTable overrides */
    #fleetTable_wrapper .dataTables_filter input {
      background: transparent;
      border: 1px solid #e2e8f0;
      border-radius: 0.5rem;
      padding: 0.375rem 0.75rem;
      font-size: 0.8125rem;
      color: inherit;
      outline: none;
    }
    .dark #fleetTable_wrapper .dataTables_filter input {
      border-color: #475569;
      color: #e2e8f0;
    }
    #fleetTable_wrapper .dataTables_filter input:focus {
      border-color: #2d9d99;
      box-shadow: 0 0 0 2px rgba(45,157,153,0.2);
    }
    #fleetTable_wrapper .dataTables_length select {
      background: transparent;
      border: 1px solid #e2e8f0;
      border-radius: 0.5rem;
      padding: 0.375rem 0.5rem;
      font-size: 0.8125rem;
      color: inherit;
    }
    .dark #fleetTable_wrapper .dataTables_length select {
      border-color: #475569;
      color: #e2e8f0;
    }
    table.dataTable thead th {
      border-bottom: 2px solid #e2e8f0 !important;
      font-size: 0.75rem;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      color: #64748b;
      padding: 0.75rem 1rem !important;
    }
    .dark table.dataTable thead th { border-bottom-color: #334155 !important; color: #94a3b8; }
    table.dataTable tbody td {
      padding: 0.75rem 1rem !important;
      border-bottom: 1px solid #f1f5f9 !important;
      font-size: 0.8125rem;
      vertical-align: middle;
    }
    .dark table.dataTable tbody td { border-bottom-color: #1e293b !important; }
    table.dataTable tbody tr:hover { background: #f8fafc !important; }
    .dark table.dataTable tbody tr:hover { background: rgba(45,157,153,0.05) !important; }
    .dataTables_wrapper .dataTables_paginate .paginate_button.current {
      background: #2d9d99 !important;
      border-color: #2d9d99 !important;
      color: white !important;
      border-radius: 0.5rem;
    }
    .dataTables_wrapper .dataTables_paginate .paginate_button:hover {
      background: #f1f5f9 !important;
      border-color: #e2e8f0 !important;
      color: #334155 !important;
      border-radius: 0.5rem;
    }
    .dark .dataTables_wrapper .dataTables_paginate .paginate_button:hover {
      background: #1e293b !important;
      border-color: #334155 !important;
      color: #e2e8f0 !important;
    }
    .dataTables_wrapper .dataTables_info { font-size: 0.8125rem; color: #64748b; }
    .dark .dataTables_wrapper .dataTables_info { color: #94a3b8; }
    .dt-buttons .dt-button {
      background: transparent !important;
      border: 1px solid #e2e8f0 !important;
      border-radius: 0.5rem !important;
      padding: 0.375rem 0.875rem !important;
      font-size: 0.75rem !important;
      font-weight: 500 !important;
      color: #475569 !important;
      margin-right: 0.25rem !important;
      transition: all 150ms;
    }
    .dark .dt-buttons .dt-button { border-color: #475569 !important; color: #94a3b8 !important; }
    .dt-buttons .dt-button:hover { background: #f1f5f9 !important; border-color: #cbd5e1 !important; }
    .dark .dt-buttons .dt-button:hover { background: #1e293b !important; border-color: #64748b !important; }

    /* Status badges */
    .tw-badge { display:inline-flex; align-items:center; padding:2px 10px; border-radius:9999px; font-size:11px; font-weight:600; line-height:1.5; }
    .tw-badge-green { background:#dcfce7; color:#166534; }
    .dark .tw-badge-green { background:rgba(22,163,74,0.15); color:#4ade80; }
    .tw-badge-blue { background:#dbeafe; color:#1e40af; }
    .dark .tw-badge-blue { background:rgba(59,130,246,0.15); color:#60a5fa; }
    .tw-badge-amber { background:#fef3c7; color:#92400e; }
    .dark .tw-badge-amber { background:rgba(245,158,11,0.15); color:#fbbf24; }
    .tw-badge-red { background:#fee2e2; color:#991b1b; }
    .dark .tw-badge-red { background:rgba(239,68,68,0.15); color:#f87171; }
    .tw-badge-slate { background:#f1f5f9; color:#475569; }
    .dark .tw-badge-slate { background:rgba(100,116,139,0.15); color:#94a3b8; }

    /* License warnings */
    .license-expired { color:#ef4444; font-weight:600; }
    .license-warning { color:#f59e0b; font-weight:600; }

    /* Action icon buttons */
    .action-icon {
      display: inline-flex; align-items: center; justify-content: center;
      width: 30px; height: 30px; border-radius: 0.375rem;
      border: none; cursor: pointer; transition: all 150ms;
      font-size: 12px; position: relative; background: transparent;
    }
    .edit-icon { color: #2d9d99; }
    .edit-icon:hover { background: rgba(45,157,153,0.1); }
    .delete-icon { color: #ef4444; }
    .delete-icon:hover { background: rgba(239,68,68,0.1); }
    .salary-icon { color: #8b5cf6; }
    .salary-icon:hover { background: rgba(139,92,246,0.1); }
    .paperwork-icon { color: #6366f1; }
    .paperwork-icon:hover { background: rgba(99,102,241,0.1); }
    .paperwork-icon.has-expired { color: #ef4444; }
    .paperwork-icon.has-expiring { color: #f59e0b; }
    .expired-badge, .expiring-badge {
      position: absolute; top: -4px; right: -4px;
      font-size: 9px; font-weight: 700; color: #fff;
      width: 16px; height: 16px; border-radius: 50%;
      display: flex; align-items: center; justify-content: center;
    }
    .expired-badge { background: #ef4444; }
    .expiring-badge { background: #f59e0b; }

    /* Modal overlay */
    .modal-overlay {
      display: none; position: fixed; inset: 0;
      background: rgba(15,23,42,0.5); backdrop-filter: blur(4px);
      z-index: 1000; align-items: center; justify-content: center; padding: 1rem;
    }
    .modal-overlay.active { display: flex; }
    .modal {
      background: white; border-radius: 1rem; width: 100%; max-width: 640px;
      max-height: 90vh; overflow-y: auto; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25);
    }
    .dark .modal { background: #1e293b; }
    .modal-header {
      display: flex; align-items: center; justify-content: space-between;
      padding: 1rem 1.25rem; border-bottom: 1px solid #e2e8f0;
    }
    .dark .modal-header { border-bottom-color: #334155; }
    .modal-header h3 { font-size: 0.9375rem; font-weight: 700; color: #0f172a; }
    .dark .modal-header h3 { color: #f1f5f9; }
    .modal-body { padding: 1.25rem; }
    .close-btn {
      width: 32px; height: 32px; border-radius: 0.5rem; border: none;
      background: #f1f5f9; color: #64748b; cursor: pointer; display: flex;
      align-items: center; justify-content: center; transition: all 150ms;
    }
    .dark .close-btn { background: #334155; color: #94a3b8; }
    .close-btn:hover { background: #fee2e2; color: #ef4444; }

    /* Form styling */
    .form-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 1rem; }
    @media (max-width: 640px) { .form-grid { grid-template-columns: 1fr; } }
    .form-actions { display: flex; gap: 0.5rem; margin-top: 1.25rem; justify-content: flex-end; }

    /* Skeleton table */
    .skeleton-table { display: flex; flex-direction: column; gap: 12px; padding: 20px; }
    .skeleton-table-row { display: flex; gap: 12px; }
    .skeleton-table-cell { height: 18px; flex: 1; border-radius: 6px; }

    /* Filters */
    .filters-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 1rem; }
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
        <i class="fas fa-truck text-brand-500 text-sm"></i>
        <h1 class="text-base font-bold text-slate-800 dark:text-white">Fleet & Drivers</h1>
      </div>

      <div class="ml-auto flex items-center gap-3">
        <span class="hidden sm:inline text-xs text-slate-400 dark:text-slate-500">Welcome, <?php echo htmlspecialchars($username); ?></span>
      </div>
    </header>

    <!-- MAIN CONTENT -->
    <main class="flex-1 overflow-y-auto p-5">

      <!-- Section Header -->
      <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-5">
        <div>
          <h2 class="text-lg font-bold text-slate-800 dark:text-white flex items-center gap-2">
            <i class="fas fa-table text-brand-500 text-sm"></i>
            Fleet Vehicles
          </h2>
          <p class="text-xs text-slate-400 dark:text-slate-500 mt-0.5">Manage vehicles, drivers, and documentation</p>
        </div>
        <div class="flex items-center gap-2">
          <button class="bg-white dark:bg-slate-700 hover:bg-slate-50 dark:hover:bg-slate-600 text-slate-600 dark:text-slate-300 text-xs font-semibold px-3.5 py-2 rounded-lg border border-slate-200 dark:border-slate-600 transition-colors" onclick="loadFleet()" style="touch-action: manipulation;">
            <i class="fas fa-sync text-xs mr-1"></i> Refresh
          </button>
          <?php if ($canCreate): ?>
          <button class="bg-brand-500 hover:bg-brand-600 text-white text-xs font-semibold px-3.5 py-2 rounded-lg transition-colors shadow-sm" onclick="openAddModal()" style="touch-action: manipulation;">
            <i class="fas fa-plus text-xs mr-1"></i> Add Vehicle
          </button>
          <?php endif; ?>
        </div>
      </div>

      <!-- Filters -->
      <div id="filtersSection" style="display: none;" class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card p-4 mb-5">
        <div class="flex items-center justify-between mb-3">
          <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-300 flex items-center gap-2">
            <i class="fas fa-filter text-brand-500 text-xs"></i> Filters
          </h3>
          <button class="text-xs text-slate-400 hover:text-slate-600 dark:hover:text-slate-300 transition-colors" onclick="clearFilters()" style="touch-action: manipulation;">
            <i class="fas fa-times-circle mr-1"></i> Clear All
          </button>
        </div>
        <div class="filters-grid">
          <div>
            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">Status</label>
            <select id="filterStatus" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
              <option value="">All</option>
              <option value="Available">Available</option>
              <option value="On Trip">On Trip</option>
              <option value="Maintenance">Maintenance</option>
              <option value="Inactive">Inactive</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">License Expiry Before</label>
            <input type="date" id="filterLicenseExpiry" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
          </div>
        </div>
      </div>

      <!-- Data Card -->
      <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card">

        <!-- Skeleton Loader -->
        <div id="skeletonLoader" class="p-5">
          <div class="skeleton-table">
            <div class="skeleton-table-row"><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div></div>
            <div class="skeleton-table-row"><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div></div>
            <div class="skeleton-table-row"><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div></div>
            <div class="skeleton-table-row"><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div></div>
            <div class="skeleton-table-row"><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div></div>
            <div class="skeleton-table-row"><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div></div>
            <div class="skeleton-table-row"><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div></div>
            <div class="skeleton-table-row"><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div><div class="skeleton skeleton-table-cell"></div></div>
          </div>
        </div>

        <!-- Table Container -->
        <div id="tableContainer" style="display: none;" class="p-5">
          <div style="overflow-x: auto; -webkit-overflow-scrolling: touch;">
            <table id="fleetTable" class="display" style="width:100%"></table>
          </div>
        </div>

      </div><!-- end data card -->

    </main>
  </div><!-- end main-content -->
</div><!-- end appRoot -->

<!-- Vehicle Add/Edit Modal -->
<?php if ($canCreate || $canUpdate): ?>
<div class="modal-overlay" id="vehicleModal">
  <div class="modal" onclick="event.stopPropagation()">
    <div class="modal-header">
      <h3 id="modalTitle"><i class="fas fa-truck text-brand-500 mr-2"></i> Add Vehicle</h3>
      <button class="close-btn" onclick="closeModal()" style="touch-action: manipulation;">
        <i class="fas fa-times text-xs"></i>
      </button>
    </div>
    <div class="modal-body">
      <div id="vehicleIdInfo" class="mb-4 px-3 py-2 bg-brand-50 dark:bg-brand-900/20 border border-brand-200 dark:border-brand-800 rounded-lg text-sm font-medium text-brand-700 dark:text-brand-300" style="display: none;">
        <i class="fas fa-id-badge mr-1"></i> Vehicle ID: <span id="vehicleIdDisplay" class="font-bold"></span>
      </div>

      <form id="vehicleForm">
        <input type="hidden" id="vehicleId" name="vehicle_id">

        <div class="form-grid">
          <div>
            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">Vehicle Registration *</label>
            <input type="text" id="vehicleRegistration" name="vehicle_registration" required maxlength="50" placeholder="e.g. ABC-1234" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">Vehicle Model</label>
            <input type="text" id="vehicleModel" name="vehicle_model" maxlength="100" placeholder="e.g. Toyota Hilux" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">Driver Name *</label>
            <input type="text" id="driverName" name="driver_name" required maxlength="150" placeholder="Enter driver name" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">Phone Number</label>
            <input type="text" id="phoneNumber" name="phone_number" maxlength="20" placeholder="e.g. +1234567890" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">Driver License No</label>
            <input type="text" id="driverLicenseNo" name="driver_license_no" maxlength="50" placeholder="Enter license number" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">License Expiry</label>
            <input type="date" id="licenseExpiry" name="license_expiry" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">Vehicle Acquisition Date</label>
            <input type="date" id="vehicleAcquisitionDate" name="vehicle_acquisition_date" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
          </div>

          <div id="statusGroup" style="display: none;">
            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">Status</label>
            <select id="vehicleStatus" name="status" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
              <option value="Available">Available</option>
              <option value="On Trip">On Trip</option>
              <option value="Maintenance">Maintenance</option>
              <option value="Inactive">Inactive</option>
            </select>
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">Driver Salary</label>
            <input type="number" id="driverSalary" name="driver_salary" step="0.01" min="0" value="0" placeholder="0.00" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">Alert Threshold</label>
            <input type="number" id="alertThreshold" name="alert_threshold" step="0.01" min="0" value="5.00" placeholder="5.00" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">Season</label>
            <?php echo renderSeasonDropdown('season', 'season', null, false); ?>
          </div>
        </div>

        <div class="form-actions">
          <button type="button" class="bg-white dark:bg-slate-700 hover:bg-slate-50 dark:hover:bg-slate-600 text-slate-600 dark:text-slate-300 text-xs font-semibold px-3.5 py-2 rounded-lg border border-slate-200 dark:border-slate-600 transition-colors" onclick="closeModal()" style="touch-action: manipulation;">
            <i class="fas fa-times mr-1"></i> Cancel
          </button>
          <button type="submit" class="bg-brand-500 hover:bg-brand-600 text-white text-xs font-semibold px-3.5 py-2 rounded-lg transition-colors" style="touch-action: manipulation;">
            <i class="fas fa-save mr-1"></i> Save
          </button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Salary Payments Modal -->
<div class="modal-overlay" id="salaryModal">
  <div class="modal" onclick="event.stopPropagation()" style="max-width:750px;">
    <div class="modal-header">
      <h3 id="salaryModalTitle"><i class="fas fa-wallet text-violet-500 mr-2"></i> Salary Payments</h3>
      <button class="close-btn" onclick="closeSalaryModal()" style="touch-action: manipulation;">
        <i class="fas fa-times text-xs"></i>
      </button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="salaryVehicleId">
      <input type="hidden" id="salaryDriverName">

      <?php if (in_array($role, ['Admin', 'Manager'])): ?>
      <div class="bg-slate-50 dark:bg-slate-700/50 border border-slate-200 dark:border-slate-600 rounded-xl p-4 mb-4">
        <h4 class="text-sm font-bold text-slate-700 dark:text-slate-200 mb-3 flex items-center gap-2">
          <i class="fas fa-plus-circle text-brand-500 text-xs"></i> Add Payment
        </h4>
        <form id="salaryPaymentForm" onsubmit="addSalaryPayment(); return false;">
          <div class="form-grid">
            <div>
              <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">Payment Date *</label>
              <input type="date" id="spPaymentDate" required class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
            </div>
            <div>
              <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">Amount *</label>
              <input type="number" id="spAmount" step="0.01" min="0.01" required placeholder="0.00" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
            </div>
            <div>
              <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">Payment Mode</label>
              <select id="spPaymentMode" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
                <option value="Cash">Cash</option>
                <option value="Bank Transfer">Bank Transfer</option>
                <option value="Cheque">Cheque</option>
                <option value="Mobile Money">Mobile Money</option>
                <option value="Swap/Hawala">Swap/Hawala</option>
                <option value="Other">Other</option>
              </select>
            </div>
            <div>
              <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">Month For</label>
              <input type="month" id="spMonthFor" placeholder="e.g. 2026-03" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
            </div>
            <div>
              <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">Reference No.</label>
              <input type="text" id="spReferenceNumber" maxlength="100" placeholder="Optional" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
            </div>
            <div>
              <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">Notes</label>
              <input type="text" id="spNotes" maxlength="255" placeholder="Optional" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
            </div>
          </div>
          <div class="flex justify-end mt-3">
            <button type="submit" class="bg-brand-500 hover:bg-brand-600 text-white text-xs font-semibold px-3.5 py-2 rounded-lg transition-colors" style="touch-action: manipulation;">
              <i class="fas fa-plus mr-1"></i> Add Payment
            </button>
          </div>
        </form>
      </div>
      <?php endif; ?>

      <h4 class="text-sm font-bold text-slate-700 dark:text-slate-200 mb-3 flex items-center gap-2">
        <i class="fas fa-list text-brand-500 text-xs"></i> Payment History
      </h4>
      <div id="salaryPaymentsLoader" class="text-center py-5" style="display:none;">
        <i class="fas fa-spinner fa-spin fa-2x text-brand-500"></i>
      </div>
      <div id="salaryPaymentsTableWrapper" style="overflow-x:auto;-webkit-overflow-scrolling:touch;">
        <table id="salaryPaymentsTable" style="width:100%;border-collapse:collapse;"></table>
      </div>
    </div>
  </div>
</div>

<!-- Paperwork Modal -->
<div class="modal-overlay" id="paperworkModal">
  <div class="modal" onclick="event.stopPropagation()" style="max-width:850px;">
    <div class="modal-header">
      <h3 id="paperworkModalTitle"><i class="fas fa-scroll text-indigo-500 mr-2"></i> Vehicle Paperwork</h3>
      <button class="close-btn" onclick="closePaperworkModal()" style="touch-action: manipulation;">
        <i class="fas fa-times text-xs"></i>
      </button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="pwVehicleId">

      <?php if ($canUpdate): ?>
      <div class="bg-slate-50 dark:bg-slate-700/50 border border-slate-200 dark:border-slate-600 rounded-xl p-4 mb-4">
        <h4 class="text-sm font-bold text-slate-700 dark:text-slate-200 mb-3 flex items-center gap-2">
          <i class="fas fa-plus-circle text-brand-500 text-xs"></i> <span id="pwFormTitle">Add Paperwork</span>
        </h4>
        <form id="paperworkForm" onsubmit="savePaperwork(); return false;">
          <input type="hidden" id="pwEditId" value="">
          <div class="form-grid">
            <div>
              <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">Document Type *</label>
              <select id="pwType" required class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
                <option value="">Select type...</option>
              </select>
            </div>
            <div>
              <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">Issue Date</label>
              <input type="date" id="pwIssueDate" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
            </div>
            <div>
              <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">Expiry Date *</label>
              <input type="date" id="pwExpiryDate" required class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
            </div>
            <div>
              <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">Reference No.</label>
              <input type="text" id="pwRefNumber" maxlength="100" placeholder="Optional" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
            </div>
            <div style="grid-column:span 2;">
              <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">Notes</label>
              <input type="text" id="pwNotes" maxlength="255" placeholder="Optional" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
            </div>
          </div>
          <div class="flex items-center gap-2 justify-end mt-3">
            <a href="settings-data.php?type=paperwork-types" target="_blank" class="bg-white dark:bg-slate-700 hover:bg-slate-50 dark:hover:bg-slate-600 text-slate-600 dark:text-slate-300 text-xs font-semibold px-3.5 py-2 rounded-lg border border-slate-200 dark:border-slate-600 transition-colors" style="touch-action:manipulation;" title="Manage paperwork types">
              <i class="fas fa-cog mr-1"></i> Manage Types
            </a>
            <button type="button" class="bg-white dark:bg-slate-700 hover:bg-slate-50 dark:hover:bg-slate-600 text-slate-600 dark:text-slate-300 text-xs font-semibold px-3.5 py-2 rounded-lg border border-slate-200 dark:border-slate-600 transition-colors" id="pwCancelEdit" style="display:none;touch-action:manipulation;" onclick="cancelPaperworkEdit()">
              <i class="fas fa-times mr-1"></i> Cancel Edit
            </button>
            <button type="submit" class="bg-brand-500 hover:bg-brand-600 text-white text-xs font-semibold px-3.5 py-2 rounded-lg transition-colors" style="touch-action: manipulation;">
              <i class="fas fa-save mr-1"></i> <span id="pwSaveBtn">Add</span>
            </button>
          </div>
        </form>
      </div>
      <?php endif; ?>

      <h4 class="text-sm font-bold text-slate-700 dark:text-slate-200 mb-3 flex items-center gap-2">
        <i class="fas fa-list text-brand-500 text-xs"></i> Documents
      </h4>
      <div id="paperworkLoader" class="text-center py-5" style="display:none;">
        <i class="fas fa-spinner fa-spin fa-2x text-brand-500"></i>
      </div>
      <div id="paperworkTableWrapper" style="overflow-x:auto;-webkit-overflow-scrolling:touch;"></div>
    </div>
  </div>
</div>

<!-- Floating Action Button -->
<?php if ($canCreate): ?>
<button class="fixed bottom-20 right-6 z-50 w-14 h-14 rounded-full bg-brand-500 hover:bg-brand-600 text-white border-none text-2xl cursor-pointer shadow-lg flex items-center justify-center transition-colors" onclick="openAddModal()" title="Add Vehicle">
  <i class="fas fa-plus"></i>
</button>
<?php endif; ?>

<script>
// Global variables
var ACTIVE_SEASON = '<?php echo addslashes(getActiveSeason()); ?>';
let fleetTable;
let fleetData = [];
let isEditMode = false;

const canCreate = <?php echo $canCreate ? 'true' : 'false'; ?>;
const canUpdate = <?php echo $canUpdate ? 'true' : 'false'; ?>;
const canDelete = <?php echo $canDelete ? 'true' : 'false'; ?>;

const statusBadgeMap = {
    'Available': 'tw-badge tw-badge-green',
    'On Trip': 'tw-badge tw-badge-blue',
    'Maintenance': 'tw-badge tw-badge-amber',
    'Inactive': 'tw-badge tw-badge-slate'
};

$(document).ready(function() {
    loadFleet();
});

function loadFleet() {
    $('#skeletonLoader').show();
    $('#tableContainer').hide();

    $.ajax({
        url: '?action=getFleet',
        method: 'GET',
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                fleetData = response.data;
                $('#filtersSection').show();
                initializeDataTable(response.data);
            } else {
                $('#skeletonLoader').hide();
                Swal.fire({ icon: 'error', title: 'Error', text: response.message || 'Failed to load fleet data' });
            }
        },
        error: function() {
            $('#skeletonLoader').hide();
            Swal.fire({ icon: 'error', title: 'Connection Error', text: 'Could not connect to server.' });
        }
    });
}

function initializeDataTable(data) {
    if (fleetTable) {
        fleetTable.destroy();
        $('#fleetTable').empty();
    }

    var columns = [
        { data: 'vehicle_id', title: 'Vehicle ID' },
        { data: 'vehicle_registration', title: 'Registration' },
        { data: 'vehicle_model', title: 'Model', defaultContent: '' },
        { data: 'driver_name', title: 'Driver' },
        { data: 'phone_number', title: 'Phone', defaultContent: '' },
        {
            data: 'license_expiry',
            title: 'License Expiry',
            render: function(data, type, row) {
                if (!data || !row.license_expiry_raw) return '';
                var expiryDate = new Date(row.license_expiry_raw);
                var today = new Date();
                today.setHours(0, 0, 0, 0);
                var diffDays = Math.ceil((expiryDate - today) / (1000 * 60 * 60 * 24));

                if (diffDays < 0) {
                    return '<span class="license-expired" title="Expired">' + data + '</span>';
                } else if (diffDays <= 30) {
                    return '<span class="license-warning" title="Expiring in ' + diffDays + ' days">' + data + '</span>';
                }
                return data;
            }
        },
        {
            data: 'status',
            title: 'Status',
            render: function(data) {
                var cls = statusBadgeMap[data] || 'tw-badge tw-badge-green';
                return '<span class="' + cls + '">' + (data || 'Available') + '</span>';
            }
        },
        {
            data: 'driver_salary',
            title: 'Salary',
            render: function(data) { return data ? parseFloat(data).toLocaleString() : '0'; }
        },
        {
            data: 'total_trips_ytd',
            title: 'Trips YTD',
            render: function(data) { return data ? parseInt(data).toLocaleString() : '0'; }
        },
        {
            data: 'total_weight_hauled_kg',
            title: 'Weight Hauled (kg)',
            render: function(data) { return data ? parseFloat(data).toLocaleString() : '0'; }
        }
    ];

    if (canUpdate || canDelete) {
        columns.push({
            data: null,
            title: 'Actions',
            orderable: false,
            render: function(data, type, row) {
                var html = '';

                // paperwork icon with badge
                var pwClass = 'action-icon paperwork-icon';
                var pwTitle = 'Paperwork (' + (row.total_papers || 0) + ')';
                var badge = '';
                if (row.expired_papers > 0) { pwClass += ' has-expired'; pwTitle = row.expired_papers + ' expired document(s)'; badge = '<span class="expired-badge">' + row.expired_papers + '</span>'; }
                else if (row.expiring_papers > 0) { pwClass += ' has-expiring'; pwTitle = row.expiring_papers + ' expiring soon'; badge = '<span class="expiring-badge">' + row.expiring_papers + '</span>'; }
                html += '<button class="' + pwClass + '" onclick="openPaperworkModal(\'' + row.vehicle_id + '\', \'' + (row.vehicle_registration || '').replace(/'/g, "\\'") + '\')" title="' + pwTitle + '"><i class="fas fa-scroll"></i>' + badge + '</button>';

                // salary icon
                html += '<button class="action-icon salary-icon" onclick="openSalaryModal(\'' + row.vehicle_id + '\', \'' + (row.driver_name || '').replace(/'/g, "\\'") + '\')" title="Salary Payments"><i class="fas fa-wallet"></i></button>';

                if (canUpdate) {
                    html += '<button class="action-icon edit-icon" onclick=\'editVehicle(' + JSON.stringify(row).replace(/'/g, "\\'") + ')\' title="Edit"><i class="fas fa-edit"></i></button>';
                }
                if (canDelete) {
                    html += '<button class="action-icon delete-icon" onclick="deleteVehicle(\'' + row.vehicle_id + '\')" title="Delete"><i class="fas fa-trash"></i></button>';
                }

                return html;
            }
        });
    }

    setTimeout(function() {
        fleetTable = $('#fleetTable').DataTable({
            data: data,
            destroy: true,
            columns: columns,
            pageLength: 50,
            lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
            responsive: true,
            dom: 'Blfrtip',
            buttons: [
                { extend: 'csv', text: '<i class="fas fa-file-csv"></i> CSV', exportOptions: { columns: (canUpdate || canDelete) ? ':not(:last-child)' : ':visible' } },
                { extend: 'pdf', text: '<i class="fas fa-file-pdf"></i> PDF', exportOptions: { columns: (canUpdate || canDelete) ? ':not(:last-child)' : ':visible' } },
                { extend: 'print', text: '<i class="fas fa-print"></i> Print', exportOptions: { columns: (canUpdate || canDelete) ? ':not(:last-child)' : ':visible' } }
            ],
            order: [[0, 'desc']]
        });

        $('#skeletonLoader').hide();
        $('#tableContainer').show();

        $('#filterStatus, #filterLicenseExpiry').on('change', function() {
            applyFilters();
        });
    }, 100);
}

function applyFilters() {
    if (!fleetTable) return;

    $.fn.dataTable.ext.search = [];

    var status = document.getElementById('filterStatus').value;
    var licenseExpiry = document.getElementById('filterLicenseExpiry').value;

    if (status) {
        $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
            return fleetData[dataIndex]?.status === status;
        });
    }

    if (licenseExpiry) {
        $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
            var rawDate = fleetData[dataIndex]?.license_expiry_raw;
            if (!rawDate) return true;
            return rawDate <= licenseExpiry;
        });
    }

    fleetTable.draw();
}

function clearFilters() {
    document.getElementById('filterStatus').value = '';
    document.getElementById('filterLicenseExpiry').value = '';

    if (fleetTable) {
        $.fn.dataTable.ext.search = [];
        fleetTable.columns().search('').draw();
    }
}

function openAddModal() {
    isEditMode = false;
    document.getElementById('modalTitle').innerHTML = '<i class="fas fa-truck text-brand-500 mr-2"></i> Add Vehicle';
    document.getElementById('vehicleForm').reset();
    document.getElementById('vehicleId').value = '';
    document.getElementById('vehicleIdInfo').style.display = 'none';
    document.getElementById('statusGroup').style.display = 'none';
    document.getElementById('alertThreshold').value = '5.00';
    document.getElementById('season').value = ACTIVE_SEASON;
    document.getElementById('driverSalary').value = '0';

    document.getElementById('vehicleModal').classList.add('active');
}

function editVehicle(row) {
    isEditMode = true;
    document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit text-brand-500 mr-2"></i> Edit Vehicle';
    document.getElementById('vehicleId').value = row.vehicle_id;
    document.getElementById('vehicleIdInfo').style.display = 'block';
    document.getElementById('vehicleIdDisplay').textContent = row.vehicle_id;

    document.getElementById('vehicleRegistration').value = row.vehicle_registration || '';
    document.getElementById('vehicleModel').value = row.vehicle_model || '';
    document.getElementById('driverName').value = row.driver_name || '';
    document.getElementById('phoneNumber').value = row.phone_number || '';
    document.getElementById('driverLicenseNo').value = row.driver_license_no || '';
    document.getElementById('licenseExpiry').value = row.license_expiry_raw || '';
    document.getElementById('vehicleAcquisitionDate').value = row.vehicle_acquisition_date || '';
    document.getElementById('driverSalary').value = row.driver_salary || 0;
    document.getElementById('alertThreshold').value = row.alert_threshold || 5.00;
    document.getElementById('season').value = row.season || ACTIVE_SEASON;

    // Show status field in edit mode
    document.getElementById('statusGroup').style.display = 'block';
    document.getElementById('vehicleStatus').value = row.status || 'Available';

    document.getElementById('vehicleModal').classList.add('active');
}

function closeModal() {
    document.getElementById('vehicleModal').classList.remove('active');
    document.getElementById('vehicleForm').reset();
}

// Click outside to close modal
document.getElementById('vehicleModal')?.addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});

// Form submission
document.getElementById('vehicleForm')?.addEventListener('submit', function(e) {
    e.preventDefault();

    var formData = new FormData(this);
    var action = isEditMode ? 'updateVehicle' : 'addVehicle';

    Swal.fire({
        title: 'Processing...',
        allowOutsideClick: false,
        didOpen: function() { Swal.showLoading(); }
    });

    $.ajax({
        url: '?action=' + action,
        method: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                Swal.fire({ icon: 'success', title: 'Success!', text: response.message, timer: 2000, showConfirmButton: false });
                closeModal();
                setTimeout(function() { loadFleet(); }, 100);
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: response.message });
            }
        },
        error: function(xhr, status, error) {
            Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error: ' + error });
        }
    });
});

function deleteVehicle(vehicleId) {
    Swal.fire({
        title: 'Delete Vehicle?',
        text: 'This action cannot be undone.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        confirmButtonText: 'Yes, delete it!'
    }).then(function(result) {
        if (result.isConfirmed) {
            var formData = new FormData();
            formData.append('vehicle_id', vehicleId);

            $.ajax({
                url: '?action=deleteVehicle',
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        Swal.fire({ icon: 'success', title: 'Deleted!', text: response.message, timer: 2000, showConfirmButton: false });
                        loadFleet();
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: response.message });
                    }
                },
                error: function() {
                    Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error' });
                }
            });
        }
    });
}

// ===== Salary Payment Functions =====
var currentSalaryVehicleId = '';
var currentSalaryDriverName = '';

function openSalaryModal(vehicleId, driverName) {
    currentSalaryVehicleId = vehicleId;
    currentSalaryDriverName = driverName;
    document.getElementById('salaryVehicleId').value = vehicleId;
    document.getElementById('salaryDriverName').value = driverName;
    document.getElementById('salaryModalTitle').innerHTML = '<i class="fas fa-wallet text-violet-500 mr-2"></i> Salary Payments - ' + driverName;

    // Reset form if exists
    var form = document.getElementById('salaryPaymentForm');
    if (form) form.reset();

    document.getElementById('salaryModal').classList.add('active');
    loadSalaryPayments(vehicleId);
}

function closeSalaryModal() {
    document.getElementById('salaryModal').classList.remove('active');
}

document.getElementById('salaryModal')?.addEventListener('click', function(e) {
    if (e.target === this) closeSalaryModal();
});

function loadSalaryPayments(vehicleId) {
    var loader = document.getElementById('salaryPaymentsLoader');
    var wrapper = document.getElementById('salaryPaymentsTableWrapper');
    loader.style.display = 'block';
    wrapper.style.display = 'none';

    $.ajax({
        url: '?action=getSalaryPayments&vehicle_id=' + encodeURIComponent(vehicleId),
        method: 'GET',
        dataType: 'json',
        success: function(response) {
            loader.style.display = 'none';
            wrapper.style.display = 'block';

            if (response.success) {
                var payments = response.data;
                var html = '';

                if (payments.length === 0) {
                    html = '<p class="text-center text-slate-400 dark:text-slate-500 py-5 text-sm">No salary payments recorded yet.</p>';
                } else {
                    var totalPaid = 0;
                    html += '<table class="w-full text-sm border-collapse">';
                    html += '<thead><tr class="bg-slate-50 dark:bg-slate-700 text-slate-600 dark:text-slate-300 border-b-2 border-slate-200 dark:border-slate-600">';
                    html += '<th class="px-3 py-2.5 text-left text-xs font-semibold uppercase tracking-wide">Date</th>';
                    html += '<th class="px-3 py-2.5 text-left text-xs font-semibold uppercase tracking-wide">Amount</th>';
                    html += '<th class="px-3 py-2.5 text-left text-xs font-semibold uppercase tracking-wide">Mode</th>';
                    html += '<th class="px-3 py-2.5 text-left text-xs font-semibold uppercase tracking-wide">Month For</th>';
                    html += '<th class="px-3 py-2.5 text-left text-xs font-semibold uppercase tracking-wide">Ref#</th>';
                    html += '<th class="px-3 py-2.5 text-left text-xs font-semibold uppercase tracking-wide">Notes</th>';
                    if (canDelete) {
                        html += '<th class="px-3 py-2.5 text-center text-xs font-semibold uppercase tracking-wide">Action</th>';
                    }
                    html += '</tr></thead><tbody>';

                    for (var i = 0; i < payments.length; i++) {
                        var p = payments[i];
                        totalPaid += parseFloat(p.amount);
                        html += '<tr class="border-b border-slate-100 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-700/50 transition-colors">';
                        html += '<td class="px-3 py-2">' + p.payment_date + '</td>';
                        html += '<td class="px-3 py-2 font-semibold">' + parseFloat(p.amount).toLocaleString() + '</td>';
                        html += '<td class="px-3 py-2">' + (p.payment_mode || '') + '</td>';
                        html += '<td class="px-3 py-2">' + (p.month_for || '') + '</td>';
                        html += '<td class="px-3 py-2">' + (p.reference_number || '') + '</td>';
                        html += '<td class="px-3 py-2">' + (p.notes || '') + '</td>';
                        if (canDelete) {
                            html += '<td class="px-3 py-2 text-center"><button class="action-icon delete-icon" onclick="deleteSalaryPayment(' + p.payment_id + ', \'' + p.vehicle_id + '\')" title="Delete" style="touch-action:manipulation;"><i class="fas fa-trash"></i></button></td>';
                        }
                        html += '</tr>';
                    }

                    html += '</tbody>';
                    html += '<tfoot><tr class="bg-slate-50 dark:bg-slate-700 font-bold border-t-2 border-slate-200 dark:border-slate-600">';
                    html += '<td class="px-3 py-2.5">Total</td>';
                    html += '<td class="px-3 py-2.5 text-brand-600 dark:text-brand-400">' + totalPaid.toLocaleString() + '</td>';
                    html += '<td colspan="' + (canDelete ? 5 : 4) + '" class="px-3 py-2.5 text-slate-400">' + payments.length + ' payment(s)</td>';
                    html += '</tr></tfoot>';
                    html += '</table>';
                }

                document.getElementById('salaryPaymentsTableWrapper').innerHTML = html;
            } else {
                document.getElementById('salaryPaymentsTableWrapper').innerHTML = '<p class="text-center text-rose-500 py-5 text-sm">' + (response.message || 'Failed to load payments') + '</p>';
            }
        },
        error: function() {
            loader.style.display = 'none';
            wrapper.style.display = 'block';
            document.getElementById('salaryPaymentsTableWrapper').innerHTML = '<p class="text-center text-rose-500 py-5 text-sm">Connection error</p>';
        }
    });
}

function addSalaryPayment() {
    var vehicleId = document.getElementById('salaryVehicleId').value;
    var driverName = document.getElementById('salaryDriverName').value;
    var paymentDate = document.getElementById('spPaymentDate').value;
    var amount = document.getElementById('spAmount').value;
    var paymentMode = document.getElementById('spPaymentMode').value;
    var monthFor = document.getElementById('spMonthFor').value;
    var referenceNumber = document.getElementById('spReferenceNumber').value;
    var notes = document.getElementById('spNotes').value;

    if (!paymentDate || !amount || parseFloat(amount) <= 0) {
        Swal.fire({ icon: 'warning', title: 'Validation', text: 'Payment date and a valid amount are required.' });
        return;
    }

    var formData = new FormData();
    formData.append('vehicle_id', vehicleId);
    formData.append('driver_name', driverName);
    formData.append('payment_date', paymentDate);
    formData.append('amount', amount);
    formData.append('payment_mode', paymentMode);
    formData.append('month_for', monthFor);
    formData.append('reference_number', referenceNumber);
    formData.append('notes', notes);
    formData.append('season', ACTIVE_SEASON);

    Swal.fire({
        title: 'Processing...',
        allowOutsideClick: false,
        didOpen: function() { Swal.showLoading(); }
    });

    $.ajax({
        url: '?action=addSalaryPayment',
        method: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                Swal.fire({ icon: 'success', title: 'Success!', text: response.message, timer: 2000, showConfirmButton: false });
                var form = document.getElementById('salaryPaymentForm');
                if (form) form.reset();
                loadSalaryPayments(vehicleId);
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: response.message });
            }
        },
        error: function(xhr, status, error) {
            Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error: ' + error });
        }
    });
}

// ===== Paperwork Functions =====
var currentPwVehicleId = '';
var paperworkTypesCache = null;

function openPaperworkModal(vehicleId, registration) {
    currentPwVehicleId = vehicleId;
    document.getElementById('pwVehicleId').value = vehicleId;
    document.getElementById('paperworkModalTitle').innerHTML = '<i class="fas fa-scroll text-indigo-500 mr-2"></i> Paperwork — ' + registration;
    cancelPaperworkEdit();
    document.getElementById('paperworkModal').classList.add('active');
    loadPaperworkTypes();
    loadVehiclePaperworks(vehicleId);
}

function closePaperworkModal() {
    document.getElementById('paperworkModal').classList.remove('active');
}

document.getElementById('paperworkModal')?.addEventListener('click', function(e) {
    if (e.target === this) closePaperworkModal();
});

function loadPaperworkTypes() {
    if (paperworkTypesCache) {
        populatePwTypeDropdown(paperworkTypesCache);
        return;
    }
    $.getJSON('?action=getPaperworkTypes', function(r) {
        if (r.success) {
            paperworkTypesCache = r.data;
            populatePwTypeDropdown(r.data);
        }
    });
}

function populatePwTypeDropdown(types) {
    var sel = document.getElementById('pwType');
    if (!sel) return;
    var current = sel.value;
    sel.innerHTML = '<option value="">Select type...</option>';
    types.forEach(function(t) {
        var opt = document.createElement('option');
        opt.value = t.paperwork_type_id;
        opt.textContent = t.paperwork_type_name;
        sel.appendChild(opt);
    });
    if (current) sel.value = current;
}

function loadVehiclePaperworks(vehicleId) {
    var loader = document.getElementById('paperworkLoader');
    var wrapper = document.getElementById('paperworkTableWrapper');
    loader.style.display = 'block';
    wrapper.style.display = 'none';

    $.getJSON('?action=getVehiclePaperworks&vehicle_id=' + encodeURIComponent(vehicleId), function(r) {
        loader.style.display = 'none';
        wrapper.style.display = 'block';

        if (!r.success) {
            wrapper.innerHTML = '<p class="text-center text-rose-500 py-5 text-sm">' + (r.message || 'Failed') + '</p>';
            return;
        }

        var papers = r.data;
        if (papers.length === 0) {
            wrapper.innerHTML = '<p class="text-center text-slate-400 dark:text-slate-500 py-5 text-sm">No paperwork recorded yet. Add vehicle documents above.</p>';
            return;
        }

        var today = new Date(); today.setHours(0,0,0,0);
        var html = '<table class="w-full text-sm border-collapse">';
        html += '<thead><tr class="bg-slate-800 dark:bg-slate-900 text-white">';
        html += '<th class="px-3 py-2.5 text-left text-xs font-semibold uppercase tracking-wide">Document</th>';
        html += '<th class="px-3 py-2.5 text-left text-xs font-semibold uppercase tracking-wide">Issue Date</th>';
        html += '<th class="px-3 py-2.5 text-left text-xs font-semibold uppercase tracking-wide">Expiry Date</th>';
        html += '<th class="px-3 py-2.5 text-left text-xs font-semibold uppercase tracking-wide">Status</th>';
        html += '<th class="px-3 py-2.5 text-left text-xs font-semibold uppercase tracking-wide">Ref#</th>';
        html += '<th class="px-3 py-2.5 text-left text-xs font-semibold uppercase tracking-wide">Notes</th>';
        if (canUpdate || canDelete) html += '<th class="px-3 py-2.5 text-center text-xs font-semibold uppercase tracking-wide">Actions</th>';
        html += '</tr></thead><tbody>';

        papers.forEach(function(p) {
            var exp = new Date(p.expiry_date_raw);
            var diff = Math.ceil((exp - today) / 86400000);
            var statusBadge, statusText;
            if (diff < 0) {
                statusBadge = 'tw-badge tw-badge-red';
                statusText = 'Expired';
            } else if (diff <= 30) {
                statusBadge = 'tw-badge tw-badge-amber';
                statusText = diff + 'd left';
            } else {
                statusBadge = 'tw-badge tw-badge-green';
                statusText = 'Valid';
            }

            html += '<tr class="border-b border-slate-100 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-700/50 transition-colors">';
            html += '<td class="px-3 py-2 font-semibold">' + (p.paperwork_type_name || '') + '</td>';
            html += '<td class="px-3 py-2">' + (p.issue_date || '—') + '</td>';
            html += '<td class="px-3 py-2">' + p.expiry_date + '</td>';
            html += '<td class="px-3 py-2"><span class="' + statusBadge + '">' + statusText + '</span></td>';
            html += '<td class="px-3 py-2">' + (p.reference_number || '') + '</td>';
            html += '<td class="px-3 py-2">' + (p.notes || '') + '</td>';

            if (canUpdate || canDelete) {
                html += '<td class="px-3 py-2 text-center whitespace-nowrap">';
                if (canUpdate) {
                    html += '<button class="action-icon edit-icon" onclick=\'editPaperwork(' + JSON.stringify(p).replace(/'/g, "\\'") + ')\' title="Edit" style="touch-action:manipulation;"><i class="fas fa-edit"></i></button> ';
                }
                if (canDelete) {
                    html += '<button class="action-icon delete-icon" onclick="deletePaperwork(' + p.paperwork_id + ')" title="Delete" style="touch-action:manipulation;"><i class="fas fa-trash"></i></button>';
                }
                html += '</td>';
            }
            html += '</tr>';
        });

        html += '</tbody></table>';
        wrapper.innerHTML = html;
    }).fail(function() {
        loader.style.display = 'none';
        wrapper.style.display = 'block';
        wrapper.innerHTML = '<p class="text-center text-rose-500 py-5 text-sm">Connection error</p>';
    });
}

function savePaperwork() {
    var vehicleId = document.getElementById('pwVehicleId').value;
    var editId = document.getElementById('pwEditId').value;
    var typeId = document.getElementById('pwType').value;
    var issueDate = document.getElementById('pwIssueDate').value;
    var expiryDate = document.getElementById('pwExpiryDate').value;
    var refNumber = document.getElementById('pwRefNumber').value;
    var notes = document.getElementById('pwNotes').value;

    if (!typeId || !expiryDate) {
        Swal.fire({ icon: 'warning', title: 'Validation', text: 'Document type and expiry date are required.' });
        return;
    }

    var formData = new FormData();
    formData.append('vehicle_id', vehicleId);
    formData.append('paperwork_type_id', typeId);
    formData.append('issue_date', issueDate);
    formData.append('expiry_date', expiryDate);
    formData.append('reference_number', refNumber);
    formData.append('notes', notes);
    formData.append('season', ACTIVE_SEASON);

    var action = 'addPaperwork';
    if (editId) {
        action = 'updatePaperwork';
        formData.append('paperwork_id', editId);
    }

    Swal.fire({ title: 'Processing...', allowOutsideClick: false, didOpen: function() { Swal.showLoading(); } });

    $.ajax({
        url: '?action=' + action,
        method: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        dataType: 'json',
        success: function(r) {
            if (r.success) {
                Swal.fire({ icon: 'success', title: 'Success!', text: r.message, timer: 2000, showConfirmButton: false });
                cancelPaperworkEdit();
                loadVehiclePaperworks(vehicleId);
                // refresh main table to update badge counts
                setTimeout(function() { loadFleet(); }, 500);
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: r.message });
            }
        },
        error: function() { Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error' }); }
    });
}

function editPaperwork(p) {
    document.getElementById('pwEditId').value = p.paperwork_id;
    document.getElementById('pwType').value = p.paperwork_type_id;
    document.getElementById('pwIssueDate').value = p.issue_date_raw || '';
    document.getElementById('pwExpiryDate').value = p.expiry_date_raw || '';
    document.getElementById('pwRefNumber').value = p.reference_number || '';
    document.getElementById('pwNotes').value = p.notes || '';
    document.getElementById('pwFormTitle').textContent = 'Edit Paperwork';
    document.getElementById('pwSaveBtn').textContent = 'Update';
    document.getElementById('pwCancelEdit').style.display = 'inline-flex';
}

function cancelPaperworkEdit() {
    var form = document.getElementById('paperworkForm');
    if (form) form.reset();
    var editId = document.getElementById('pwEditId');
    if (editId) editId.value = '';
    var title = document.getElementById('pwFormTitle');
    if (title) title.textContent = 'Add Paperwork';
    var btn = document.getElementById('pwSaveBtn');
    if (btn) btn.textContent = 'Add';
    var cancel = document.getElementById('pwCancelEdit');
    if (cancel) cancel.style.display = 'none';
}

function deletePaperwork(paperworkId) {
    Swal.fire({
        title: 'Delete Paperwork?',
        text: 'This action cannot be undone.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        confirmButtonText: 'Yes, delete it!'
    }).then(function(result) {
        if (result.isConfirmed) {
            var formData = new FormData();
            formData.append('paperwork_id', paperworkId);
            $.ajax({
                url: '?action=deletePaperwork',
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(r) {
                    if (r.success) {
                        Swal.fire({ icon: 'success', title: 'Deleted!', text: r.message, timer: 2000, showConfirmButton: false });
                        loadVehiclePaperworks(currentPwVehicleId);
                        setTimeout(function() { loadFleet(); }, 500);
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: r.message });
                    }
                },
                error: function() { Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error' }); }
            });
        }
    });
}

function deleteSalaryPayment(paymentId, vehicleId) {
    Swal.fire({
        title: 'Delete Payment?',
        text: 'This action cannot be undone.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        confirmButtonText: 'Yes, delete it!'
    }).then(function(result) {
        if (result.isConfirmed) {
            var formData = new FormData();
            formData.append('payment_id', paymentId);

            $.ajax({
                url: '?action=deleteSalaryPayment',
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        Swal.fire({ icon: 'success', title: 'Deleted!', text: response.message, timer: 2000, showConfirmButton: false });
                        loadSalaryPayments(vehicleId);
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: response.message });
                    }
                },
                error: function() {
                    Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error' });
                }
            });
        }
    });
}

/* Theme init */
(function() {
  var _store = {}; try { _store = window.localStorage; } catch(e) { _store = { getItem: function(){return null;}, setItem: function(){} }; }
  var html = document.documentElement;
  var dark = _store.getItem('cp_theme') === 'dark' || (_store.getItem('cp_theme') === null && window.matchMedia('(prefers-color-scheme: dark)').matches);
  html.classList.toggle('dark', dark);
})();

/* i18n loader */
(function() {
  var _store = {}; try { _store = window.localStorage; } catch(e) { _store = { getItem: function(){return null;}, setItem: function(){} }; }
  var lang = _store.getItem('cp_lang') || 'en';
  document.documentElement.lang = lang;
})();
</script>

</body>
</html>
