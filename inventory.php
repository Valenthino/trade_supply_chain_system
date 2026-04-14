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
$current_page = 'inventory';

// RBAC
$allowedRoles = ['Admin', 'Manager', 'Warehouse Clerk', 'Procurement Officer'];
if (!in_array($role, $allowedRoles)) {
    header("Location: dashboard.php");
    exit();
}

// Handle AJAX requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    try {
        switch ($_GET['action']) {

            // ===================== GET STOCK SUMMARY =====================
            case 'getStockSummary':
                $conn = getDBConnection();
                $stmt = $conn->prepare("
                    SELECT
                        w.warehouse_id, w.warehouse_name, l.location_name,
                        COALESCE(pin.total_in, 0) as total_in,
                        COALESCE(pout.total_out, 0) as total_out,
                        COALESCE(pin.total_in, 0) - COALESCE(pout.total_out, 0) as current_stock,
                        COALESCE(pin.bags_in, 0) as bags_in,
                        COALESCE(pout.bags_out, 0) as bags_out
                    FROM settings_warehouses w
                    LEFT JOIN settings_locations l ON w.location_id = l.location_id
                    LEFT JOIN (
                        SELECT warehouse_id, SUM(weight_kg) as total_in, SUM(COALESCE(num_bags,0)) as bags_in
                        FROM purchases GROUP BY warehouse_id
                    ) pin ON w.warehouse_id = pin.warehouse_id
                    LEFT JOIN (
                        SELECT origin_warehouse_id, SUM(weight_kg) as total_out, SUM(COALESCE(num_bags,0)) as bags_out
                        FROM deliveries WHERE status NOT IN ('Rejected','Reassigned')
                        GROUP BY origin_warehouse_id
                    ) pout ON w.warehouse_id = pout.origin_warehouse_id
                    WHERE w.is_active = 1
                    ORDER BY w.warehouse_name
                ");
                $stmt->execute();
                $result = $stmt->get_result();

                $warehouses = [];
                $totalIn = 0;
                $totalOut = 0;
                $currentStock = 0;

                while ($row = $result->fetch_assoc()) {
                    $warehouses[] = [
                        'warehouse_id' => $row['warehouse_id'],
                        'warehouse_name' => $row['warehouse_name'],
                        'location_name' => $row['location_name'] ?? '',
                        'total_in' => round(floatval($row['total_in']), 2),
                        'total_out' => round(floatval($row['total_out']), 2),
                        'current_stock' => round(floatval($row['current_stock']), 2),
                        'bags_in' => intval($row['bags_in']),
                        'bags_out' => intval($row['bags_out'])
                    ];
                    $totalIn += floatval($row['total_in']);
                    $totalOut += floatval($row['total_out']);
                    $currentStock += floatval($row['current_stock']);
                }

                $stmt->close();
                $conn->close();

                echo json_encode([
                    'success' => true,
                    'data' => [
                        'warehouses' => $warehouses,
                        'totalIn' => round($totalIn, 2),
                        'totalOut' => round($totalOut, 2),
                        'currentStock' => round($currentStock, 2)
                    ]
                ]);
                exit();

            // ===================== GET STOCK LEDGER =====================
            case 'getStockLedger':
                $conn = getDBConnection();
                $warehouseId = isset($_GET['warehouse_id']) ? intval($_GET['warehouse_id']) : 0;

                $sql = "
                    SELECT * FROM (
                        SELECT
                            p.date, 'IN' as movement_type, p.purchase_id as reference_id,
                            CONCAT('Purchase from ', COALESCE(p.supplier_name, 'Unknown')) as description,
                            w.warehouse_name, p.warehouse_id,
                            p.weight_kg, COALESCE(p.num_bags, 0) as num_bags, p.season
                        FROM purchases p
                        LEFT JOIN settings_warehouses w ON p.warehouse_id = w.warehouse_id

                        UNION ALL

                        SELECT
                            d.date, 'OUT' as movement_type, d.delivery_id as reference_id,
                            CONCAT('Delivery to ', COALESCE(d.customer_name, 'Unknown')) as description,
                            w.warehouse_name, d.origin_warehouse_id as warehouse_id,
                            d.weight_kg, COALESCE(d.num_bags, 0) as num_bags, d.season
                        FROM deliveries d
                        LEFT JOIN settings_warehouses w ON d.origin_warehouse_id = w.warehouse_id
                        WHERE d.status NOT IN ('Rejected','Reassigned')
                    ) stock_movements
                    WHERE 1=1
                ";

                if ($warehouseId > 0) {
                    $sql .= " AND warehouse_id = ?";
                }

                $sql .= " ORDER BY date ASC, movement_type DESC";

                $stmt = $conn->prepare($sql);

                if ($warehouseId > 0) {
                    $stmt->bind_param("i", $warehouseId);
                }

                $stmt->execute();
                $result = $stmt->get_result();

                $movements = [];
                while ($row = $result->fetch_assoc()) {
                    $movements[] = $row;
                }

                // Compute running balance
                $runningBalance = 0;
                foreach ($movements as &$m) {
                    if ($m['movement_type'] === 'IN') {
                        $runningBalance += floatval($m['weight_kg']);
                    } else {
                        $runningBalance -= floatval($m['weight_kg']);
                    }
                    $m['running_balance'] = round($runningBalance, 2);
                }
                unset($m);

                // Format dates for display
                foreach ($movements as &$m) {
                    $m['date_display'] = date('M d, Y', strtotime($m['date']));
                    $m['date_raw'] = $m['date'];
                }
                unset($m);

                $stmt->close();
                $conn->close();

                echo json_encode(['success' => true, 'data' => $movements]);
                exit();

            // ===================== GET WAREHOUSES (dropdown) =====================
            case 'getWarehouses':
                $conn = getDBConnection();
                $stmt = $conn->prepare("SELECT warehouse_id, warehouse_name FROM settings_warehouses WHERE is_active = 1 ORDER BY warehouse_name");
                $stmt->execute();
                $result = $stmt->get_result();

                $warehouses = [];
                while ($row = $result->fetch_assoc()) {
                    $warehouses[] = $row;
                }

                $stmt->close();
                $conn->close();

                echo json_encode(['success' => true, 'data' => $warehouses]);
                exit();

            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
                exit();
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <title>Inventory - Dashboard System</title>

    <!-- CDN Dependencies -->
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

        <!-- Main Content -->
        <div class="main-content">
            <div class="header">
                <h1><i class="fas fa-boxes-stacked"></i> Inventory / Stock Ledger</h1>
                <div>Welcome, <?php echo htmlspecialchars($username); ?></div>
            </div>

            <!-- Filter Bar -->
            <div class="filters-section">
                <div class="filters-header">
                    <h3><i class="fas fa-filter"></i> Filter</h3>
                    <button class="btn btn-primary" onclick="refreshData()">
                        <i class="fas fa-sync-alt"></i> Refresh
                    </button>
                </div>
                <div class="filters-grid">
                    <div class="filter-group">
                        <label><i class="fas fa-warehouse"></i> Warehouse</label>
                        <select id="warehouseFilter" class="filter-input" onchange="loadStockLedger()">
                            <option value="0">All Warehouses</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Summary Cards -->
            <div class="dashboard-grid-3" id="stockSummaryCards">
                <div class="stat-card">
                    <div class="stat-card-icon" style="background:linear-gradient(135deg,#001f3f,#003366);">
                        <i class="fas fa-boxes-stacked"></i>
                    </div>
                    <div class="stat-card-value" id="kpiCurrentStock">
                        <div class="skeleton" style="height:30px;width:120px;"></div>
                    </div>
                    <div class="stat-card-label">Current Stock</div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-icon" style="background:linear-gradient(135deg,#34a853,#2d8f47);">
                        <i class="fas fa-arrow-down"></i>
                    </div>
                    <div class="stat-card-value" id="kpiTotalIn">
                        <div class="skeleton" style="height:30px;width:120px;"></div>
                    </div>
                    <div class="stat-card-label">Total IN (Purchases)</div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-icon" style="background:linear-gradient(135deg,#ea4335,#c5362b);">
                        <i class="fas fa-arrow-up"></i>
                    </div>
                    <div class="stat-card-value" id="kpiTotalOut">
                        <div class="skeleton" style="height:30px;width:120px;"></div>
                    </div>
                    <div class="stat-card-label">Total OUT (Deliveries)</div>
                </div>
            </div>

            <!-- Per-Warehouse Stock Cards -->
            <div id="warehouseCards" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;margin-bottom:20px;">
                <div class="stat-card"><div class="skeleton" style="height:80px;width:100%;"></div></div>
                <div class="stat-card"><div class="skeleton" style="height:80px;width:100%;"></div></div>
                <div class="stat-card"><div class="skeleton" style="height:80px;width:100%;"></div></div>
            </div>

            <!-- Stock Ledger DataTable -->
            <div class="data-section">
                <div class="section-header">
                    <h2><i class="fas fa-list-ol"></i> Stock Ledger</h2>
                </div>

                <!-- Skeleton Loader -->
                <div id="ledgerSkeleton">
                    <div class="skeleton-table">
                        <div class="skeleton-table-row">
                            <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                        </div>
                        <div class="skeleton-table-row">
                            <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                        </div>
                        <div class="skeleton-table-row">
                            <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                        </div>
                        <div class="skeleton-table-row">
                            <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                        </div>
                        <div class="skeleton-table-row">
                            <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                        </div>
                        <div class="skeleton-table-row">
                            <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                        </div>
                        <div class="skeleton-table-row">
                            <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                        </div>
                        <div class="skeleton-table-row">
                            <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex:1"></div>
                        </div>
                    </div>
                </div>

                <!-- DataTable Container -->
                <div id="tableContainer" style="display: none;">
                    <div class="table-scroll-hint">
                        <i class="fas fa-arrows-alt-h"></i> Swipe left/right to see all columns
                    </div>
                    <div style="overflow-x: auto; -webkit-overflow-scrolling: touch;">
                        <table id="stockLedgerTable" class="display responsive nowrap" style="width:100%">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Type</th>
                                    <th>Reference</th>
                                    <th>Description</th>
                                    <th>Warehouse</th>
                                    <th>Weight (kg)</th>
                                    <th>Bags</th>
                                    <th>Balance (kg)</th>
                                    <th>Season</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        let stockLedgerTable;
        let ledgerData = [];

        $(document).ready(function() {
            loadWarehouses();
            loadStockSummary();
            loadStockLedger();
        });

        // ===================== Format Numbers =====================
        function formatNumber(num) {
            return parseFloat(num).toLocaleString('en-US', { maximumFractionDigits: 0 });
        }

        function formatTons(kg) {
            return (parseFloat(kg) / 1000).toLocaleString('en-US', { maximumFractionDigits: 1 }) + ' T';
        }

        // ===================== Load Warehouses (dropdown) =====================
        function loadWarehouses() {
            $.ajax({
                url: '?action=getWarehouses',
                method: 'GET',
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        var select = document.getElementById('warehouseFilter');
                        var currentVal = select.value;
                        select.innerHTML = '<option value="0">All Warehouses</option>';
                        response.data.forEach(function(w) {
                            var opt = document.createElement('option');
                            opt.value = w.warehouse_id;
                            opt.textContent = w.warehouse_name;
                            select.appendChild(opt);
                        });
                        if (currentVal) select.value = currentVal;
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Failed to load warehouses:', error);
                }
            });
        }

        // ===================== Load Stock Summary =====================
        function loadStockSummary() {
            // Show skeleton in KPI cards
            $('#kpiCurrentStock').html('<div class="skeleton" style="height:30px;width:120px;"></div>');
            $('#kpiTotalIn').html('<div class="skeleton" style="height:30px;width:120px;"></div>');
            $('#kpiTotalOut').html('<div class="skeleton" style="height:30px;width:120px;"></div>');
            $('#warehouseCards').html(
                '<div class="stat-card"><div class="skeleton" style="height:80px;width:100%;"></div></div>' +
                '<div class="stat-card"><div class="skeleton" style="height:80px;width:100%;"></div></div>' +
                '<div class="stat-card"><div class="skeleton" style="height:80px;width:100%;"></div></div>'
            );

            $.ajax({
                url: '?action=getStockSummary',
                method: 'GET',
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        var d = response.data;

                        // Update KPI cards
                        $('#kpiCurrentStock').html(formatNumber(d.currentStock) + ' kg<br><small style="font-size:13px;color:var(--text-muted);">' + formatTons(d.currentStock) + '</small>');
                        $('#kpiTotalIn').html(formatNumber(d.totalIn) + ' kg<br><small style="font-size:13px;color:var(--text-muted);">' + formatTons(d.totalIn) + '</small>');
                        $('#kpiTotalOut').html(formatNumber(d.totalOut) + ' kg<br><small style="font-size:13px;color:var(--text-muted);">' + formatTons(d.totalOut) + '</small>');

                        // Build per-warehouse cards
                        var cardsHTML = '';
                        d.warehouses.forEach(function(w) {
                            var stockColor = w.current_stock > 0 ? '#34a853' : (w.current_stock < 0 ? '#ea4335' : '#666');
                            cardsHTML += '<div class="stat-card" style="border-left-color:' + stockColor + ';padding:16px;">' +
                                '<div style="font-weight:700;font-size:15px;color:var(--text-primary);margin-bottom:4px;">' + (w.warehouse_name || 'Unknown') + '</div>' +
                                '<div style="font-size:12px;color:var(--text-muted);margin-bottom:10px;">' + (w.location_name || '') + '</div>' +
                                '<div style="font-size:20px;font-weight:700;color:' + stockColor + ';">' + formatNumber(w.current_stock) + ' kg</div>' +
                                '<div style="font-size:11px;color:var(--text-muted);margin-top:4px;">' +
                                    '<span style="color:#34a853;">IN: ' + formatNumber(w.total_in) + '</span> &middot; ' +
                                    '<span style="color:#ea4335;">OUT: ' + formatNumber(w.total_out) + '</span>' +
                                '</div>' +
                                '<div style="font-size:11px;color:var(--text-muted);margin-top:2px;">' +
                                    'Bags: ' + w.bags_in + ' in / ' + w.bags_out + ' out' +
                                '</div>' +
                            '</div>';
                        });

                        if (d.warehouses.length === 0) {
                            cardsHTML = '<div style="grid-column:1/-1;text-align:center;color:var(--text-muted);padding:20px;">No warehouse data available</div>';
                        }

                        $('#warehouseCards').html(cardsHTML);
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: response.message || 'Failed to load stock summary'
                        });
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Stock summary error:', error);
                    Swal.fire({
                        icon: 'error',
                        title: 'Connection Error',
                        text: 'Could not connect to server. Please check console for details.'
                    });
                }
            });
        }

        // ===================== Load Stock Ledger =====================
        function loadStockLedger() {
            $('#ledgerSkeleton').show();
            $('#tableContainer').hide();

            var warehouseId = document.getElementById('warehouseFilter').value || 0;

            $.ajax({
                url: '?action=getStockLedger&warehouse_id=' + warehouseId,
                method: 'GET',
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        ledgerData = response.data;
                        initializeDataTable(response.data);
                    } else {
                        $('#ledgerSkeleton').hide();
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: response.message || 'Failed to load stock ledger'
                        });
                    }
                },
                error: function(xhr, status, error) {
                    $('#ledgerSkeleton').hide();
                    console.error('Stock ledger error:', error);
                    Swal.fire({
                        icon: 'error',
                        title: 'Connection Error',
                        text: 'Could not connect to server. Please check console for details.'
                    });
                }
            });
        }

        // ===================== DataTable =====================
        function initializeDataTable(data) {
            if (stockLedgerTable) {
                stockLedgerTable.destroy();
                $('#stockLedgerTable tbody').empty();
            }

            var columns = [
                {
                    data: 'date_display',
                    title: 'Date',
                    render: function(data, type, row) {
                        if (type === 'sort') return row.date_raw;
                        return data;
                    }
                },
                {
                    data: 'movement_type',
                    title: 'Type',
                    render: function(data) {
                        if (data === 'IN') {
                            return '<span class="status-badge" style="background:#d4edda;color:#155724;border:1px solid #c3e6cb;"><i class="fas fa-arrow-down"></i> IN</span>';
                        } else {
                            return '<span class="status-badge" style="background:#f8d7da;color:#721c24;border:1px solid #f5c6cb;"><i class="fas fa-arrow-up"></i> OUT</span>';
                        }
                    }
                },
                {
                    data: 'reference_id',
                    title: 'Reference',
                    render: function(data, type, row) {
                        var prefix = row.movement_type === 'IN' ? 'PUR-' : 'DEL-';
                        return '<span style="font-size:12px;font-family:monospace;">' + prefix + data + '</span>';
                    }
                },
                {
                    data: 'description',
                    title: 'Description'
                },
                {
                    data: 'warehouse_name',
                    title: 'Warehouse',
                    render: function(data) {
                        return data || '<span style="color:var(--text-muted);">N/A</span>';
                    }
                },
                {
                    data: 'weight_kg',
                    title: 'Weight (kg)',
                    render: function(data, type, row) {
                        var val = parseFloat(data);
                        var color = row.movement_type === 'IN' ? '#34a853' : '#ea4335';
                        var sign = row.movement_type === 'IN' ? '+' : '-';
                        return '<span style="color:' + color + ';font-weight:600;" title="' + formatTons(data) + '">' + sign + formatNumber(val) + '</span>';
                    }
                },
                {
                    data: 'num_bags',
                    title: 'Bags',
                    render: function(data) {
                        return parseInt(data) || 0;
                    }
                },
                {
                    data: 'running_balance',
                    title: 'Balance (kg)',
                    render: function(data) {
                        var val = parseFloat(data);
                        var color = val >= 0 ? 'var(--text-primary)' : '#ea4335';
                        return '<strong style="color:' + color + ';" title="' + formatTons(data) + '">' + formatNumber(val) + '</strong>';
                    }
                },
                {
                    data: 'season',
                    title: 'Season',
                    render: function(data) {
                        return data || '<span style="color:var(--text-muted);">-</span>';
                    }
                }
            ];

            setTimeout(function() {
                stockLedgerTable = $('#stockLedgerTable').DataTable({
                    data: data,
                    destroy: true,
                    columns: columns,
                    pageLength: 50,
                    lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
                    responsive: true,
                    dom: 'Blfrtip',
                    buttons: [
                        {
                            extend: 'csv',
                            text: '<i class="fas fa-file-csv"></i> CSV',
                            exportOptions: { columns: ':visible' }
                        },
                        {
                            extend: 'pdf',
                            text: '<i class="fas fa-file-pdf"></i> PDF',
                            exportOptions: { columns: ':visible' }
                        },
                        {
                            extend: 'print',
                            text: '<i class="fas fa-print"></i> Print',
                            exportOptions: { columns: ':visible' }
                        }
                    ],
                    order: [[0, 'asc']]
                });

                $('#ledgerSkeleton').hide();
                $('#tableContainer').show();
            }, 100);
        }

        // ===================== Refresh Data =====================
        function refreshData() {
            loadStockSummary();
            loadStockLedger();
        }
    </script>
</body>
</html>