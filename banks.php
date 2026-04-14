<?php
/**
 * Developed by Rameez Scripts
 * WhatsApp: https://wa.me/923224083545 (For Custom Projects)
 * YouTube: https://www.youtube.com/@rameezimdad (Subscribe for more!)
 *
 * Bank Master — manages bank counterparties used in Bank Financing.
 */
require_once 'config.php';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }
if (!checkSessionTimeout()) { header("Location: login.php"); exit(); }

$username = $_SESSION['username'];
$role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];
$current_page = 'banks';

// RBAC — bank debts are restricted to finance roles only
$allowedRoles = ['Admin', 'Manager', 'Finance Officer'];
if (!in_array($role, $allowedRoles)) { header("Location: dashboard.php"); exit(); }

$canCreate = in_array($role, ['Admin', 'Manager', 'Finance Officer']);
$canUpdate = in_array($role, ['Admin', 'Manager', 'Finance Officer']);
$canDelete = ($role === 'Admin');
$isReadOnly = !$canCreate && !$canUpdate;

if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    try {
        switch ($_GET['action']) {

            case 'getBanks':
                $conn = getDBConnection();

                // batch: outstanding bank debt per bank (we owe = incoming financing balance_due)
                $debtMap = [];
                $debtRes = $conn->query("SELECT counterparty_id, COALESCE(SUM(balance_due),0) as t, COUNT(*) as c FROM financing WHERE counterpart_type='Bank' AND direction='Incoming' AND status='Active' GROUP BY counterparty_id");
                if ($debtRes) { while ($r = $debtRes->fetch_assoc()) $debtMap[$r['counterparty_id']] = ['debt' => floatval($r['t']), 'cnt' => intval($r['c'])]; }

                // batch: total borrowed (lifetime)
                $totMap = [];
                $totRes = $conn->query("SELECT counterparty_id, COALESCE(SUM(amount),0) as t FROM financing WHERE counterpart_type='Bank' AND direction='Incoming' GROUP BY counterparty_id");
                if ($totRes) { while ($r = $totRes->fetch_assoc()) $totMap[$r['counterparty_id']] = floatval($r['t']); }

                $rows = [];
                $res = $conn->query("SELECT * FROM banks ORDER BY bank_id DESC");
                if ($res) {
                    while ($row = $res->fetch_assoc()) {
                        $bid = $row['bank_id'];
                        $rows[] = [
                            'bank_id' => $bid,
                            'bank_name' => $row['bank_name'],
                            'branch' => $row['branch'] ?? '',
                            'contact_person' => $row['contact_person'] ?? '',
                            'phone' => $row['phone'] ?? '',
                            'email' => $row['email'] ?? '',
                            'account_number' => $row['account_number'] ?? '',
                            'address' => $row['address'] ?? '',
                            'notes' => $row['notes'] ?? '',
                            'status' => $row['status'],
                            'created_at' => date('M d, Y', strtotime($row['created_at'])),
                            'outstanding_debt' => round($debtMap[$bid]['debt'] ?? 0, 2),
                            'active_loans' => $debtMap[$bid]['cnt'] ?? 0,
                            'total_borrowed' => round($totMap[$bid] ?? 0, 2)
                        ];
                    }
                }
                $conn->close();
                echo json_encode(['success' => true, 'data' => $rows]);
                exit();

            case 'addBank':
                if (!$canCreate) { echo json_encode(['success' => false, 'message' => 'Access denied']); exit(); }
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success' => false, 'message' => 'Invalid request']); exit(); }

                $name = trim($_POST['bank_name'] ?? '');
                $branch = trim($_POST['branch'] ?? '');
                $contact = trim($_POST['contact_person'] ?? '');
                $phone = trim($_POST['phone'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $acct = trim($_POST['account_number'] ?? '');
                $addr = trim($_POST['address'] ?? '');
                $notes = trim($_POST['notes'] ?? '');

                if (empty($name)) { echo json_encode(['success' => false, 'message' => 'Bank name is required']); exit(); }
                if (strlen($name) > 200) { echo json_encode(['success' => false, 'message' => 'Bank name too long']); exit(); }

                $conn = getDBConnection();

                // auto id BANK-001
                $r = $conn->query("SELECT bank_id FROM banks WHERE bank_id LIKE 'BANK-%' ORDER BY bank_id DESC LIMIT 1");
                $next = 1;
                if ($r && $r->num_rows > 0) {
                    $maxId = $r->fetch_assoc()['bank_id'];
                    $next = intval(substr($maxId, 5)) + 1;
                }
                $newId = 'BANK-' . str_pad($next, 3, '0', STR_PAD_LEFT);

                $stmt = $conn->prepare("INSERT INTO banks (bank_id, bank_name, branch, contact_person, phone, email, account_number, address, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("sssssssss", $newId, $name, $branch, $contact, $phone, $email, $acct, $addr, $notes);

                if ($stmt->execute()) {
                    logActivity($user_id, $username, 'Bank Created', "Created bank: $name ($newId)");
                    $stmt->close(); $conn->close();
                    echo json_encode(['success' => true, 'message' => 'Bank added successfully', 'bank_id' => $newId]);
                } else {
                    $err = $stmt->error;
                    $stmt->close(); $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to add bank: ' . $err]);
                }
                exit();

            case 'updateBank':
                if (!$canUpdate) { echo json_encode(['success' => false, 'message' => 'Access denied']); exit(); }
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success' => false, 'message' => 'Invalid request']); exit(); }

                $bankId = trim($_POST['bank_id'] ?? '');
                $name = trim($_POST['bank_name'] ?? '');
                $branch = trim($_POST['branch'] ?? '');
                $contact = trim($_POST['contact_person'] ?? '');
                $phone = trim($_POST['phone'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $acct = trim($_POST['account_number'] ?? '');
                $addr = trim($_POST['address'] ?? '');
                $notes = trim($_POST['notes'] ?? '');

                if (empty($bankId)) { echo json_encode(['success' => false, 'message' => 'Bank ID required']); exit(); }
                if (empty($name)) { echo json_encode(['success' => false, 'message' => 'Bank name required']); exit(); }

                $conn = getDBConnection();
                $stmt = $conn->prepare("UPDATE banks SET bank_name = ?, branch = ?, contact_person = ?, phone = ?, email = ?, account_number = ?, address = ?, notes = ? WHERE bank_id = ?");
                $stmt->bind_param("sssssssss", $name, $branch, $contact, $phone, $email, $acct, $addr, $notes, $bankId);

                if ($stmt->execute()) {
                    // also update counterpart_name on existing financing rows so the financing list stays in sync
                    $upd = $conn->prepare("UPDATE financing SET counterpart_name = ? WHERE counterpart_type='Bank' AND counterparty_id = ?");
                    $upd->bind_param("ss", $name, $bankId);
                    $upd->execute();
                    $upd->close();

                    logActivity($user_id, $username, 'Bank Updated', "Updated bank: $name ($bankId)");
                    $stmt->close(); $conn->close();
                    echo json_encode(['success' => true, 'message' => 'Bank updated successfully']);
                } else {
                    $err = $stmt->error;
                    $stmt->close(); $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to update bank: ' . $err]);
                }
                exit();

            case 'toggleStatus':
                if (!$canUpdate) { echo json_encode(['success' => false, 'message' => 'Access denied']); exit(); }
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success' => false, 'message' => 'Invalid request']); exit(); }

                $bankId = trim($_POST['bank_id'] ?? '');
                if (empty($bankId)) { echo json_encode(['success' => false, 'message' => 'Invalid bank ID']); exit(); }

                $conn = getDBConnection();
                $stmt = $conn->prepare("SELECT bank_name, status FROM banks WHERE bank_id = ?");
                $stmt->bind_param("s", $bankId);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($res->num_rows == 0) { $stmt->close(); $conn->close(); echo json_encode(['success' => false, 'message' => 'Bank not found']); exit(); }
                $bank = $res->fetch_assoc();
                $stmt->close();

                $newStatus = ($bank['status'] === 'Active') ? 'Inactive' : 'Active';
                $stmt = $conn->prepare("UPDATE banks SET status = ? WHERE bank_id = ?");
                $stmt->bind_param("ss", $newStatus, $bankId);

                if ($stmt->execute()) {
                    logActivity($user_id, $username, 'Bank Status Changed', "{$bank['bank_name']} ($bankId) → $newStatus");
                    $stmt->close(); $conn->close();
                    echo json_encode(['success' => true, 'message' => "Bank status changed to $newStatus"]);
                } else {
                    $stmt->close(); $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to update status']);
                }
                exit();

            case 'deleteBank':
                if (!$canDelete) { echo json_encode(['success' => false, 'message' => 'Only Admin can delete']); exit(); }
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success' => false, 'message' => 'Invalid request']); exit(); }

                $bankId = trim($_POST['bank_id'] ?? '');
                if (empty($bankId)) { echo json_encode(['success' => false, 'message' => 'Invalid bank ID']); exit(); }

                $conn = getDBConnection();

                // block delete if any financing exists for this bank
                $chk = $conn->prepare("SELECT COUNT(*) as c FROM financing WHERE counterpart_type='Bank' AND counterparty_id = ?");
                $chk->bind_param("s", $bankId);
                $chk->execute();
                $cnt = intval($chk->get_result()->fetch_assoc()['c']);
                $chk->close();

                if ($cnt > 0) {
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => "Cannot delete — $cnt financing record(s) reference this bank. Deactivate instead."]);
                    exit();
                }

                $stmt = $conn->prepare("SELECT bank_name FROM banks WHERE bank_id = ?");
                $stmt->bind_param("s", $bankId);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($res->num_rows == 0) { $stmt->close(); $conn->close(); echo json_encode(['success' => false, 'message' => 'Bank not found']); exit(); }
                $bank = $res->fetch_assoc();
                $stmt->close();

                $stmt = $conn->prepare("DELETE FROM banks WHERE bank_id = ?");
                $stmt->bind_param("s", $bankId);
                if ($stmt->execute()) {
                    logActivity($user_id, $username, 'Bank Deleted', "{$bank['bank_name']} ($bankId)");
                    $stmt->close(); $conn->close();
                    echo json_encode(['success' => true, 'message' => 'Bank deleted successfully']);
                } else {
                    $stmt->close(); $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to delete bank']);
                }
                exit();

            case 'getBankReport':
                $bankId = $_GET['bank_id'] ?? '';
                if (empty($bankId)) { echo json_encode(['success' => false, 'message' => 'Bank ID required']); exit(); }

                $conn = getDBConnection();

                $stmt = $conn->prepare("SELECT * FROM banks WHERE bank_id = ?");
                $stmt->bind_param("s", $bankId);
                $stmt->execute();
                $bank = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$bank) { $conn->close(); echo json_encode(['success' => false, 'message' => 'Bank not found']); exit(); }

                // financing rows
                $financing = [];
                $stmt = $conn->prepare("SELECT financing_id, date, direction, amount, amount_repaid, balance_due, interest_rate_pct, term_months, monthly_payment, start_date, maturity_date, status, season FROM financing WHERE counterpart_type='Bank' AND counterparty_id = ? ORDER BY date DESC, financing_id DESC");
                $stmt->bind_param("s", $bankId);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($r = $res->fetch_assoc()) $financing[] = $r;
                $stmt->close();

                // payments tied to those financings
                $payments = [];
                $stmt = $conn->prepare("SELECT p.payment_id, p.date, p.direction, p.amount, p.payment_mode, p.reference_number, p.linked_financing_id, p.notes FROM payments p INNER JOIN financing f ON p.linked_financing_id = f.financing_id WHERE f.counterpart_type='Bank' AND f.counterparty_id = ? ORDER BY p.date DESC, p.payment_id DESC");
                $stmt->bind_param("s", $bankId);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($r = $res->fetch_assoc()) $payments[] = $r;
                $stmt->close();

                $conn->close();
                echo json_encode(['success' => true, 'data' => [
                    'bank' => $bank,
                    'financing' => $financing,
                    'payments' => $payments
                ]]);
                exit();

            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
                exit();
        }
    } catch (\Throwable $e) {
        error_log("banks.php error: " . $e->getMessage());
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
    <title>Bank Master - Dashboard System</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.dataTables.min.css">
    <link rel="stylesheet" href="styles.css?v=4.0">

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js"></script>
</head>
<body>
    <?php include 'mobile-menu.php'; ?>

    <div class="app-container">
        <?php include 'sidebar.php'; ?>

        <div class="main-content">
            <div class="header">
                <h1><i class="fas fa-building-columns"></i> Bank Master</h1>
                <div>Welcome, <?php echo htmlspecialchars($username); ?></div>
            </div>

            <div class="data-section">
                <div class="section-header">
                    <h2><i class="fas fa-table"></i> Banks</h2>
                    <div style="display: flex; gap: 10px;">
                        <button class="btn btn-primary" onclick="loadBanks()">
                            <i class="fas fa-sync"></i> Refresh
                        </button>
                        <?php if ($canCreate): ?>
                        <button class="btn btn-success" onclick="openAddModal()">
                            <i class="fas fa-plus"></i> Add Bank
                        </button>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- KPI strip -->
                <div id="bankKpiStrip" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:16px;">
                    <div class="report-summary-card"><div class="val" id="kpiBankCount">-</div><div class="lbl">Total Banks</div></div>
                    <div class="report-summary-card"><div class="val" id="kpiBankActive" style="color:#27ae60;">-</div><div class="lbl">Active</div></div>
                    <div class="report-summary-card"><div class="val" id="kpiTotalDebt" style="color:#e0a800;">-</div><div class="lbl">Total Owed</div></div>
                    <div class="report-summary-card"><div class="val" id="kpiActiveLoans" style="color:#0074D9;">-</div><div class="lbl">Active Loans</div></div>
                </div>

                <div class="filters-section" id="filtersSection" style="display:none;">
                    <div class="filters-header">
                        <h3><i class="fas fa-filter"></i> Filters</h3>
                        <button class="btn btn-secondary btn-sm" onclick="clearFilters()"><i class="fas fa-times-circle"></i> Clear All</button>
                    </div>
                    <div class="filters-grid">
                        <div class="filter-group">
                            <label><i class="fas fa-toggle-on"></i> Status</label>
                            <select id="filterStatus" class="filter-input">
                                <option value="">All</option>
                                <option value="Active">Active</option>
                                <option value="Inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div id="loadingSkeleton">
                    <div class="skeleton-table">
                        <?php for ($i = 0; $i < 6; $i++): ?>
                        <div class="skeleton-table-row">
                            <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex:2"></div>
                            <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                        </div>
                        <?php endfor; ?>
                    </div>
                </div>

                <div id="tableContainer" style="display:none;">
                    <div class="table-scroll-hint">
                        <i class="fas fa-arrows-alt-h"></i> Swipe left/right to see all columns
                    </div>
                    <div style="overflow-x:auto;-webkit-overflow-scrolling:touch;">
                        <table id="banksTable" class="display" style="width:100%"></table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($canCreate || $canUpdate): ?>
    <div class="modal-overlay" id="bankModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 id="modalTitle"><i class="fas fa-building-columns"></i> Add Bank</h3>
                <button class="close-btn" onclick="closeModal()"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <form id="bankForm">
                    <input type="hidden" id="bankId" name="bank_id">

                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-building-columns"></i> Bank Name *</label>
                            <input type="text" id="bankName" name="bank_name" required maxlength="200">
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-code-branch"></i> Branch</label>
                            <input type="text" id="branch" name="branch" maxlength="150">
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-user"></i> Contact Person</label>
                            <input type="text" id="contactPerson" name="contact_person" maxlength="150">
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-phone"></i> Phone</label>
                            <input type="tel" id="phone" name="phone" maxlength="20">
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-envelope"></i> Email</label>
                            <input type="email" id="email" name="email" maxlength="200">
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-hashtag"></i> Account Number</label>
                            <input type="text" id="accountNumber" name="account_number" maxlength="50">
                        </div>

                        <div class="form-group" style="grid-column:1 / -1;">
                            <label><i class="fas fa-location-dot"></i> Address</label>
                            <textarea id="address" name="address" rows="2"></textarea>
                        </div>

                        <div class="form-group" style="grid-column:1 / -1;">
                            <label><i class="fas fa-note-sticky"></i> Notes</label>
                            <textarea id="notes" name="notes" rows="2"></textarea>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save</button>
                        <button type="button" class="btn btn-secondary" onclick="closeModal()"><i class="fas fa-times"></i> Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Bank Report Modal -->
    <style>
        .report-tabs { display:flex;border-bottom:2px solid var(--border-color);background:var(--bg-card);padding:0 20px;gap:0;overflow-x:auto; }
        .report-tab { padding:12px 20px;border:none;background:transparent;color:var(--text-muted);font-size:13px;font-weight:600;cursor:pointer;border-bottom:3px solid transparent;transition:all .3s;font-family:inherit;display:flex;align-items:center;gap:6px;white-space:nowrap; }
        .report-tab:hover { color:var(--navy-accent);background:rgba(0,116,217,.05); }
        .report-tab.active { color:var(--navy-accent);border-bottom-color:var(--navy-accent); }
        .report-summary { display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:16px; }
        .report-summary-card { background:var(--bg-primary);border-radius:8px;padding:14px;text-align:center; }
        .report-summary-card .val { font-size:22px;font-weight:700;color:var(--navy-accent); }
        .report-summary-card .lbl { font-size:11px;color:var(--text-muted);text-transform:uppercase;margin-top:4px; }
        .report-table { width:100%;border-collapse:collapse;font-size:12px; }
        .report-table thead th { background:var(--navy-primary);color:white;padding:8px 10px;text-align:left;font-size:11px;font-weight:600; }
        .report-table tbody td { padding:8px 10px;border-bottom:1px solid var(--border-light);color:var(--text-primary); }
        .report-table tbody tr:hover { background:var(--table-hover); }
    </style>

    <div id="reportModal" class="modal-overlay">
        <div class="modal" style="max-width:80%;width:80%;" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 id="reportTitle"><i class="fas fa-file-alt"></i> Bank Report</h3>
                <div style="display:flex;gap:8px;align-items:center;">
                    <button class="btn btn-primary btn-sm" onclick="printReport()"><i class="fas fa-print"></i> Print</button>
                    <button class="close-btn" onclick="closeReportModal()"><i class="fas fa-times"></i></button>
                </div>
            </div>
            <div class="modal-body" style="padding:0;">
                <div id="reportTabs" class="report-tabs">
                    <button class="report-tab active" onclick="switchReportTab('overview', this)"><i class="fas fa-circle-info"></i> Overview</button>
                    <button class="report-tab" onclick="switchReportTab('financing', this)"><i class="fas fa-money-bill-transfer"></i> Loans</button>
                    <button class="report-tab" onclick="switchReportTab('payments', this)"><i class="fas fa-credit-card"></i> Repayments</button>
                </div>
                <div id="reportContent" style="padding:20px;">
                    <div class="skeleton" style="height:200px;"></div>
                </div>
            </div>
        </div>
    </div>

    <script>
        let banksTable;
        let isEditMode = false;
        let banksData = [];
        const isReadOnly = <?php echo $isReadOnly ? 'true' : 'false'; ?>;
        const canCreate = <?php echo $canCreate ? 'true' : 'false'; ?>;
        const canUpdate = <?php echo $canUpdate ? 'true' : 'false'; ?>;
        const canDelete = <?php echo $canDelete ? 'true' : 'false'; ?>;

        function fmt(n) { return Number(n || 0).toLocaleString('en-US', {maximumFractionDigits: 0}); }
        function fmtR(n) { return Number(n || 0).toLocaleString(); }
        function fmtDate(d) { if (!d) return '-'; var p = String(d).split('-'); return p.length === 3 ? p[2].substring(0,2) + '/' + p[1] + '/' + p[0] : d; }

        $(document).ready(function() { loadBanks(); });

        function loadBanks() {
            $('#loadingSkeleton').show();
            $('#tableContainer').hide();

            $.ajax({
                url: '?action=getBanks&_=' + Date.now(),
                method: 'GET',
                dataType: 'json',
                success: function(r) {
                    if (!r.success) {
                        $('#loadingSkeleton').hide();
                        Swal.fire({icon:'error', title:'Error', text: r.message || 'Failed to load banks'});
                        return;
                    }
                    banksData = r.data;
                    $('#loadingSkeleton').hide();
                    $('#tableContainer').show();
                    $('#filtersSection').show();
                    renderKpis(banksData);
                    initializeDataTable(banksData);
                },
                error: function(xhr, status, err) {
                    $('#loadingSkeleton').hide();
                    Swal.fire({icon:'error', title:'Connection Error', text: err});
                }
            });
        }

        function renderKpis(data) {
            var totalDebt = 0, activeLoans = 0, active = 0;
            data.forEach(function(b) {
                totalDebt += parseFloat(b.outstanding_debt || 0);
                activeLoans += parseInt(b.active_loans || 0);
                if (b.status === 'Active') active++;
            });
            document.getElementById('kpiBankCount').textContent = data.length;
            document.getElementById('kpiBankActive').textContent = active;
            document.getElementById('kpiTotalDebt').textContent = fmt(totalDebt) + ' F';
            document.getElementById('kpiActiveLoans').textContent = activeLoans;
        }

        function initializeDataTable(data) {
            if (banksTable) { banksTable.destroy(); $('#banksTable').empty(); }

            const columns = [
                { data: 'bank_id', title: 'ID' },
                { data: 'bank_name', title: 'Bank Name' },
                { data: 'branch', title: 'Branch', render: function(d) { return d || '-'; } },
                { data: 'contact_person', title: 'Contact', render: function(d) { return d || '-'; } },
                { data: 'phone', title: 'Phone', render: function(d) { return d || '-'; } },
                { data: 'account_number', title: 'Account #', render: function(d) { return d || '-'; } },
                {
                    data: 'outstanding_debt', title: 'Owed (F)',
                    render: function(d, type, row) {
                        var v = parseFloat(d) || 0;
                        if (v > 0.01) return '<span style="color:#e0a800;font-weight:600;">' + fmt(v) + ' F</span>';
                        return '<span style="color:#27ae60;font-weight:600;">0.00</span>';
                    }
                },
                {
                    data: 'active_loans', title: 'Loans',
                    render: function(d) {
                        var c = parseInt(d) || 0;
                        if (c > 0) return '<span style="background:#0074D9;color:white;padding:2px 8px;border-radius:10px;font-weight:600;">' + c + '</span>';
                        return '<span style="color:var(--text-muted);">0</span>';
                    }
                },
                {
                    data: 'status', title: 'Status',
                    render: function(d) {
                        return d === 'Active'
                            ? '<span class="status-badge status-active"><i class="fas fa-check-circle"></i> Active</span>'
                            : '<span class="status-badge status-inactive"><i class="fas fa-times-circle"></i> Inactive</span>';
                    }
                },
                {
                    data: null, title: 'Actions', orderable: false,
                    render: function(d, t, row) {
                        var html = '';
                        html += '<button class="action-icon" onclick="showBankReport(\'' + row.bank_id + '\', \'' + (row.bank_name || '').replace(/'/g, "\\'") + '\')" title="Report" style="color:#0074D9"><i class="fas fa-file-alt"></i></button> ';
                        if (canUpdate) {
                            html += '<button class="action-icon edit-icon" onclick=\'editBank(' + JSON.stringify(row) + ')\' title="Edit"><i class="fas fa-edit"></i></button> ';
                            var toggleIcon = row.status === 'Active' ? 'fa-toggle-on' : 'fa-toggle-off';
                            var toggleColor = row.status === 'Active' ? 'style="color:#34a853"' : 'style="color:#ea4335"';
                            var toggleTitle = row.status === 'Active' ? 'Deactivate' : 'Activate';
                            html += '<button class="action-icon" onclick="toggleStatus(\'' + row.bank_id + '\')" title="' + toggleTitle + '" ' + toggleColor + '><i class="fas ' + toggleIcon + '"></i></button> ';
                        }
                        if (canDelete) {
                            html += '<button class="action-icon delete-icon" onclick="deleteBank(\'' + row.bank_id + '\')" title="Delete" style="color:#ea4335"><i class="fas fa-trash"></i></button>';
                        }
                        return html;
                    }
                }
            ];

            setTimeout(function() {
                banksTable = $('#banksTable').DataTable({
                    data: data,
                    destroy: true,
                    columns: columns,
                    pageLength: 50,
                    lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
                    responsive: true,
                    dom: 'Blfrtip',
                    buttons: [
                        { extend: 'csv', text: '<i class="fas fa-file-csv"></i> CSV', exportOptions: { columns: ':not(:last-child)' } },
                        { extend: 'pdf', text: '<i class="fas fa-file-pdf"></i> PDF', exportOptions: { columns: ':not(:last-child)' } },
                        { extend: 'print', text: '<i class="fas fa-print"></i> Print', exportOptions: { columns: ':not(:last-child)' } }
                    ],
                    order: [[0, 'desc']]
                });

                $('#filterStatus').on('change', applyFilters);
            }, 100);
        }

        function applyFilters() {
            if (!banksTable) return;
            $.fn.dataTable.ext.search = [];
            var status = document.getElementById('filterStatus').value;
            if (status) {
                $.fn.dataTable.ext.search.push(function(settings, data, idx) {
                    return banksData[idx]?.status === status;
                });
            }
            banksTable.draw();
        }

        function clearFilters() {
            document.getElementById('filterStatus').value = '';
            $.fn.dataTable.ext.search = [];
            if (banksTable) banksTable.draw();
        }

        <?php if ($canCreate || $canUpdate): ?>
        function openAddModal() {
            isEditMode = false;
            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-building-columns"></i> Add Bank';
            document.getElementById('bankForm').reset();
            document.getElementById('bankId').value = '';
            document.getElementById('bankModal').classList.add('active');
        }

        function editBank(row) {
            if (!canUpdate) return;
            isEditMode = true;
            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit"></i> Edit Bank';
            document.getElementById('bankId').value = row.bank_id;
            document.getElementById('bankName').value = row.bank_name || '';
            document.getElementById('branch').value = row.branch || '';
            document.getElementById('contactPerson').value = row.contact_person || '';
            document.getElementById('phone').value = row.phone || '';
            document.getElementById('email').value = row.email || '';
            document.getElementById('accountNumber').value = row.account_number || '';
            document.getElementById('address').value = row.address || '';
            document.getElementById('notes').value = row.notes || '';
            document.getElementById('bankModal').classList.add('active');
        }

        function closeModal() {
            document.getElementById('bankModal').classList.remove('active');
            document.getElementById('bankForm').reset();
        }

        document.getElementById('bankModal').addEventListener('click', function(e) {
            if (e.target === this) closeModal();
        });

        document.getElementById('bankForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            const action = isEditMode ? 'updateBank' : 'addBank';

            Swal.fire({title:'Processing...', allowOutsideClick:false, didOpen:()=>Swal.showLoading()});

            $.ajax({
                url: '?action=' + action,
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(r) {
                    if (r.success) {
                        Swal.fire({icon:'success', title:'Success!', text: r.message, timer:1800, showConfirmButton:false});
                        closeModal();
                        setTimeout(loadBanks, 100);
                    } else {
                        Swal.fire({icon:'error', title:'Error', text: r.message});
                    }
                },
                error: function(xhr, status, err) {
                    Swal.fire({icon:'error', title:'Error', text:'Connection error: ' + err});
                }
            });
        });
        <?php endif; ?>

        <?php if ($canUpdate): ?>
        function toggleStatus(bankId) {
            Swal.fire({
                icon:'question', title:'Toggle Bank Status?',
                showCancelButton:true, confirmButtonText:'Yes', cancelButtonText:'Cancel'
            }).then(function(res) {
                if (!res.isConfirmed) return;
                var fd = new FormData();
                fd.append('bank_id', bankId);
                $.ajax({
                    url:'?action=toggleStatus', method:'POST', data:fd,
                    processData:false, contentType:false, dataType:'json',
                    success: function(r) {
                        if (r.success) {
                            Swal.fire({icon:'success', text:r.message, timer:1500, showConfirmButton:false});
                            setTimeout(loadBanks, 100);
                        } else Swal.fire({icon:'error', title:'Error', text:r.message});
                    }
                });
            });
        }
        <?php endif; ?>

        <?php if ($canDelete): ?>
        function deleteBank(bankId) {
            Swal.fire({
                icon:'warning', title:'Delete Bank?',
                text:'This action cannot be undone.',
                showCancelButton:true, confirmButtonColor:'#ea4335',
                confirmButtonText:'Yes, delete it', cancelButtonText:'Cancel'
            }).then(function(res) {
                if (!res.isConfirmed) return;
                var fd = new FormData();
                fd.append('bank_id', bankId);
                $.ajax({
                    url:'?action=deleteBank', method:'POST', data:fd,
                    processData:false, contentType:false, dataType:'json',
                    success: function(r) {
                        if (r.success) {
                            Swal.fire({icon:'success', text:r.message, timer:1500, showConfirmButton:false});
                            setTimeout(loadBanks, 100);
                        } else Swal.fire({icon:'error', title:'Cannot Delete', text:r.message});
                    }
                });
            });
        }
        <?php endif; ?>

        // ===== Bank Report =====
        var reportData = null;

        function showBankReport(bankId, bankName) {
            document.getElementById('reportTitle').innerHTML = '<i class="fas fa-file-alt"></i> ' + bankName + ' — Report';
            document.getElementById('reportContent').innerHTML = '<div class="skeleton" style="height:200px;"></div>';
            document.getElementById('reportModal').classList.add('active');
            document.querySelectorAll('.report-tab').forEach(t => t.classList.remove('active'));
            document.querySelector('.report-tab').classList.add('active');

            $.getJSON('?action=getBankReport&bank_id=' + encodeURIComponent(bankId) + '&_=' + Date.now(), function(r) {
                if (!r.success) {
                    document.getElementById('reportContent').innerHTML = '<p style="color:var(--danger);padding:20px;">' + (r.message || 'Failed') + '</p>';
                    return;
                }
                reportData = r.data;
                renderReportTab('overview');
            }).fail(function() {
                document.getElementById('reportContent').innerHTML = '<p style="color:var(--danger);padding:20px;">Connection error</p>';
            });
        }

        function closeReportModal() {
            document.getElementById('reportModal').classList.remove('active');
            reportData = null;
        }

        document.getElementById('reportModal').addEventListener('click', function(e) {
            if (e.target === this) closeReportModal();
        });

        function switchReportTab(tab, btn) {
            if (btn) {
                document.querySelectorAll('.report-tab').forEach(t => t.classList.remove('active'));
                btn.classList.add('active');
            }
            renderReportTab(tab);
        }

        function renderReportTab(tab) {
            var d = reportData;
            if (!d) return;
            var html = '';

            if (tab === 'overview') {
                var totalBorrowed = 0, totalRepaid = 0, totalDue = 0, activeCount = 0;
                d.financing.forEach(function(f) {
                    totalBorrowed += parseFloat(f.amount || 0);
                    totalRepaid += parseFloat(f.amount_repaid || 0);
                    if (f.status === 'Active') {
                        totalDue += parseFloat(f.balance_due || 0);
                        activeCount++;
                    }
                });

                html += '<div class="report-summary">';
                html += '<div class="report-summary-card"><div class="val">' + d.financing.length + '</div><div class="lbl">Loans</div></div>';
                html += '<div class="report-summary-card"><div class="val">' + activeCount + '</div><div class="lbl">Active</div></div>';
                html += '<div class="report-summary-card"><div class="val">' + fmtR(totalBorrowed) + ' F</div><div class="lbl">Total Borrowed</div></div>';
                html += '<div class="report-summary-card"><div class="val" style="color:var(--success);">' + fmtR(totalRepaid) + ' F</div><div class="lbl">Total Repaid</div></div>';
                html += '<div class="report-summary-card"><div class="val" style="color:#e0a800;">' + fmtR(totalDue) + ' F</div><div class="lbl">Outstanding</div></div>';
                html += '</div>';

                html += '<table class="report-table"><thead><tr><th>Field</th><th>Value</th></tr></thead><tbody>';
                html += '<tr><td><strong>Bank ID</strong></td><td>' + (d.bank.bank_id || '-') + '</td></tr>';
                html += '<tr><td><strong>Branch</strong></td><td>' + (d.bank.branch || '-') + '</td></tr>';
                html += '<tr><td><strong>Contact</strong></td><td>' + (d.bank.contact_person || '-') + '</td></tr>';
                html += '<tr><td><strong>Phone</strong></td><td>' + (d.bank.phone || '-') + '</td></tr>';
                html += '<tr><td><strong>Email</strong></td><td>' + (d.bank.email || '-') + '</td></tr>';
                html += '<tr><td><strong>Account #</strong></td><td>' + (d.bank.account_number || '-') + '</td></tr>';
                html += '<tr><td><strong>Address</strong></td><td>' + (d.bank.address || '-') + '</td></tr>';
                html += '<tr><td><strong>Notes</strong></td><td>' + (d.bank.notes || '-') + '</td></tr>';
                html += '</tbody></table>';
            }

            if (tab === 'financing') {
                var totalAmt = 0, totalRep = 0, totalBal = 0;
                d.financing.forEach(function(f) {
                    totalAmt += parseFloat(f.amount || 0);
                    totalRep += parseFloat(f.amount_repaid || 0);
                    totalBal += parseFloat(f.balance_due || 0);
                });

                html += '<div class="report-summary">';
                html += '<div class="report-summary-card"><div class="val">' + d.financing.length + '</div><div class="lbl">Loans</div></div>';
                html += '<div class="report-summary-card"><div class="val">' + fmtR(totalAmt) + ' F</div><div class="lbl">Borrowed</div></div>';
                html += '<div class="report-summary-card"><div class="val" style="color:var(--success);">' + fmtR(totalRep) + ' F</div><div class="lbl">Repaid</div></div>';
                html += '<div class="report-summary-card"><div class="val" style="color:#e0a800;">' + fmtR(totalBal) + ' F</div><div class="lbl">Outstanding</div></div>';
                html += '</div>';

                html += '<table class="report-table"><thead><tr><th>Loan ID</th><th>Date</th><th>Amount</th><th>Rate %</th><th>Term</th><th>Repaid</th><th>Balance</th><th>Maturity</th><th>Status</th></tr></thead><tbody>';
                if (d.financing.length === 0) html += '<tr><td colspan="9" style="text-align:center;padding:20px;color:var(--text-muted);">No loans found</td></tr>';
                d.financing.forEach(function(f) {
                    html += '<tr><td>' + f.financing_id + '</td><td>' + fmtDate(f.date) + '</td><td>' + fmtR(f.amount) + ' F</td><td>' + (f.interest_rate_pct || '0') + '%</td><td>' + (f.term_months || '-') + ' mo</td><td>' + fmtR(f.amount_repaid) + ' F</td><td>' + fmtR(f.balance_due) + ' F</td><td>' + fmtDate(f.maturity_date) + '</td><td><span class="status-badge">' + f.status + '</span></td></tr>';
                });
                html += '</tbody></table>';
            }

            if (tab === 'payments') {
                var totalIn = 0, totalOut = 0;
                d.payments.forEach(function(p) {
                    if (p.direction === 'Outgoing') totalOut += parseFloat(p.amount);
                    else totalIn += parseFloat(p.amount);
                });

                html += '<div class="report-summary">';
                html += '<div class="report-summary-card"><div class="val">' + d.payments.length + '</div><div class="lbl">Payments</div></div>';
                html += '<div class="report-summary-card"><div class="val" style="color:var(--success);">' + fmtR(totalIn) + ' F</div><div class="lbl">Received from Bank</div></div>';
                html += '<div class="report-summary-card"><div class="val" style="color:var(--danger);">' + fmtR(totalOut) + ' F</div><div class="lbl">Paid to Bank</div></div>';
                html += '</div>';

                html += '<table class="report-table"><thead><tr><th>Payment ID</th><th>Date</th><th>Direction</th><th>Loan ID</th><th>Amount</th><th>Mode</th><th>Reference</th></tr></thead><tbody>';
                if (d.payments.length === 0) html += '<tr><td colspan="7" style="text-align:center;padding:20px;color:var(--text-muted);">No payments found</td></tr>';
                d.payments.forEach(function(p) {
                    var dirCls = p.direction === 'Incoming' ? 'color:var(--success);' : 'color:var(--danger);';
                    html += '<tr><td>' + p.payment_id + '</td><td>' + fmtDate(p.date) + '</td><td style="' + dirCls + 'font-weight:600;">' + p.direction + '</td><td>' + (p.linked_financing_id || '-') + '</td><td>' + fmtR(p.amount) + ' F</td><td>' + (p.payment_mode || '-') + '</td><td>' + (p.reference_number || '-') + '</td></tr>';
                });
                html += '</tbody></table>';
            }

            document.getElementById('reportContent').innerHTML = html;
        }

        function printReport() {
            var content = document.getElementById('reportContent').innerHTML;
            var title = document.getElementById('reportTitle').textContent;
            var win = window.open('', '_blank', 'width=900,height=700');
            win.document.write('<!DOCTYPE html><html><head><meta charset="UTF-8"><title>' + title + '</title><style>');
            win.document.write('body { font-family:-apple-system,sans-serif;font-size:12px;color:#333;padding:20px; }');
            win.document.write('h2 { color:#001f3f;margin-bottom:16px;font-size:18px; }');
            win.document.write('.report-summary { display:flex;gap:12px;margin-bottom:16px; }');
            win.document.write('.report-summary-card { background:#f5f5f5;border-radius:6px;padding:12px;text-align:center;flex:1; }');
            win.document.write('.report-summary-card .val { font-size:18px;font-weight:700;color:#001f3f; }');
            win.document.write('.report-summary-card .lbl { font-size:10px;color:#666;text-transform:uppercase; }');
            win.document.write('.report-table { width:100%;border-collapse:collapse; }');
            win.document.write('.report-table th { background:#001f3f;color:white;padding:6px 8px;font-size:10px;text-align:left; }');
            win.document.write('.report-table td { padding:6px 8px;border-bottom:1px solid #eee;font-size:11px; }');
            win.document.write('.status-badge { padding:2px 8px;border-radius:4px;font-size:10px;font-weight:600; }');
            win.document.write('@media print { body { padding:0; } }');
            win.document.write('</style></head><body><h2>' + title + '</h2>' + content + '</body></html>');
            win.document.close();
            win.onload = function() { win.focus(); win.print(); };
        }
    </script>
</body>
</html>
