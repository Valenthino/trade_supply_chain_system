<?php
/**
 * Developed by Rameez Scripts
 * WhatsApp: https://wa.me/923224083545 (For Custom Projects)
 * YouTube: https://www.youtube.com/@rameezimdad (Subscribe for more!)
 *
 * Supplier Ranking — scores suppliers across volume, quality, reliability, activity
 * and ranks them to help pick who to finance next.
 */
require_once 'config.php';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }
if (!checkSessionTimeout()) { header("Location: login.php"); exit(); }

$username = $_SESSION['username'];
$role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];
$current_page = 'supplier-ranking';

// RBAC — finance + procurement (they decide who gets funded)
$allowedRoles = ['Admin', 'Manager', 'Finance Officer', 'Procurement Officer'];
if (!in_array($role, $allowedRoles)) { header("Location: dashboard.php"); exit(); }

// weights for the 4 score components (must sum to 1.0)
$SCORE_WEIGHTS = [
    'volume'      => 0.40,  // how much they actually deliver
    'quality'     => 0.20,  // KOR out-turn + grainage
    'reliability' => 0.25,  // advance/delivery discipline
    'activity'    => 0.15,  // freshness — have they delivered recently
];

if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    try {
        if ($_GET['action'] === 'getRanking') {
            $conn = getDBConnection();

            // snapshot table: one row per supplier per day, lets us compare movement over time
            @$conn->query("CREATE TABLE IF NOT EXISTS supplier_ranking_snapshots (
                id INT AUTO_INCREMENT PRIMARY KEY,
                snapshot_date DATE NOT NULL,
                supplier_id VARCHAR(20) NOT NULL,
                rank_position INT NOT NULL,
                total_score DECIMAL(5,1) NOT NULL,
                tier CHAR(1) NOT NULL,
                UNIQUE KEY uniq_day_supplier (snapshot_date, supplier_id),
                INDEX idx_snap_date (snapshot_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            // ── 1. supplier base stats from purchases ──
            $baseSql = "SELECT s.supplier_id, s.first_name, s.procurement_region, s.status,
                        s.typical_price_per_kg, s.financing_balance,
                        COALESCE(SUM(p.weight_kg), 0) AS total_kg,
                        COALESCE(SUM(p.total_cost), 0) AS total_value,
                        COALESCE(AVG(NULLIF(p.kor_out_turn, 0)), 0) AS avg_kor,
                        COALESCE(AVG(NULLIF(p.grainage, 0)), 0) AS avg_grainage,
                        COUNT(p.purchase_id) AS purchase_count,
                        MAX(p.date) AS last_purchase_date,
                        SUM(CASE WHEN p.date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY) THEN p.weight_kg ELSE 0 END) AS recent_kg
                    FROM suppliers s
                    LEFT JOIN purchases p ON p.supplier_id = s.supplier_id
                    WHERE s.status = 'Active'
                    GROUP BY s.supplier_id";
            $res = $conn->query($baseSql);
            $suppliers = [];
            while ($r = $res->fetch_assoc()) $suppliers[$r['supplier_id']] = $r;

            // ── 2. advance discipline from financing (manual outgoing) ──
            $finMap = [];
            $fr = $conn->query("SELECT counterparty_id,
                    SUM(amount + carried_over_balance) AS total_advance,
                    SUM(amount_repaid) AS total_repaid,
                    SUM(balance_due) AS balance_due,
                    COUNT(*) AS advance_count,
                    SUM(CASE WHEN status = 'Settled' THEN 1 ELSE 0 END) AS settled_count
                FROM financing
                WHERE counterpart_type = 'Supplier' AND direction = 'Outgoing' AND source = 'Manual'
                GROUP BY counterparty_id");
            if ($fr) { while ($r = $fr->fetch_assoc()) $finMap[$r['counterparty_id']] = $r; }

            // ── 3. compute normalization maxima (needed for scoring) ──
            $maxKg = 0; $maxValue = 0; $maxRecentKg = 0;
            foreach ($suppliers as $s) {
                if ($s['total_kg'] > $maxKg)     $maxKg = floatval($s['total_kg']);
                if ($s['total_value'] > $maxValue) $maxValue = floatval($s['total_value']);
                if ($s['recent_kg'] > $maxRecentKg) $maxRecentKg = floatval($s['recent_kg']);
            }
            if ($maxKg <= 0) $maxKg = 1;
            if ($maxValue <= 0) $maxValue = 1;
            if ($maxRecentKg <= 0) $maxRecentKg = 1;

            // ── 4. score each supplier ──
            $w = $GLOBALS['SCORE_WEIGHTS'];
            $ranked = [];
            foreach ($suppliers as $sid => $s) {
                $totalKg  = floatval($s['total_kg']);
                $totalVal = floatval($s['total_value']);
                $recentKg = floatval($s['recent_kg']);
                $avgKor   = floatval($s['avg_kor']);
                $avgGrain = floatval($s['avg_grainage']);

                // volume: 70% of points by kg, 30% by value — rewards both ton-per-ton output and high-grade material
                $volScore = min(100, 70 * ($totalKg / $maxKg) + 30 * ($totalVal / $maxValue));

                // quality: KOR (higher = better, target ~50%) + grainage (lower = better, target ≤180)
                // if no quality data yet, gets 60 (neutral-positive so new suppliers aren't punished)
                if ($avgKor <= 0 && $avgGrain <= 0) {
                    $qualityScore = 60;
                } else {
                    $korPart = $avgKor > 0 ? min(100, ($avgKor / 50) * 100) : 50;
                    $grainPart = $avgGrain > 0 ? max(0, min(100, (220 - $avgGrain) / 40 * 100)) : 50;
                    $qualityScore = round(($korPart + $grainPart) / 2, 1);
                }

                // reliability: how well they've handled past advances
                // no advance history → 65 (neutral, lower than "proven settled")
                $fin = $finMap[$sid] ?? null;
                if (!$fin || floatval($fin['total_advance']) <= 0) {
                    $reliabilityScore = 65;
                } else {
                    $repayRatio = min(1, floatval($fin['total_repaid']) / floatval($fin['total_advance']));
                    $settledRatio = $fin['advance_count'] > 0 ? $fin['settled_count'] / $fin['advance_count'] : 0;
                    $reliabilityScore = round(($repayRatio * 0.6 + $settledRatio * 0.4) * 100, 1);
                }

                // activity: recent kg vs max recent kg, plus recency of last purchase
                $last = $s['last_purchase_date'];
                $daysSince = $last ? (int)((time() - strtotime($last)) / 86400) : 9999;
                $freshnessPart = max(0, 100 - $daysSince);      // 0 days = 100, 100+ days = 0
                $recentVolPart = ($recentKg / $maxRecentKg) * 100;
                $activityScore = round(($freshnessPart * 0.5 + $recentVolPart * 0.5), 1);

                $totalScore = round(
                    $w['volume']      * $volScore +
                    $w['quality']     * $qualityScore +
                    $w['reliability'] * $reliabilityScore +
                    $w['activity']    * $activityScore,
                    1
                );

                // tier — based on total score
                if ($totalScore >= 80)      $tier = 'A';
                elseif ($totalScore >= 65)  $tier = 'B';
                elseif ($totalScore >= 50)  $tier = 'C';
                else                        $tier = 'D';

                // outstanding advance exposure (to flag risk)
                $outstanding = $fin ? floatval($fin['balance_due']) : 0;

                $ranked[] = [
                    'supplier_id' => $sid,
                    'first_name'  => $s['first_name'],
                    'region'      => $s['procurement_region'] ?? '',
                    'total_kg'    => round($totalKg, 2),
                    'total_value' => round($totalVal, 2),
                    'purchase_count' => intval($s['purchase_count']),
                    'last_purchase_date' => $last,
                    'days_since_last' => $last ? $daysSince : null,
                    'avg_kor'     => round($avgKor, 2),
                    'avg_grainage'=> round($avgGrain, 2),
                    'total_advance' => $fin ? round(floatval($fin['total_advance']), 2) : 0,
                    'outstanding' => round($outstanding, 2),
                    'advance_count' => $fin ? intval($fin['advance_count']) : 0,
                    'score_volume' => round($volScore, 1),
                    'score_quality' => $qualityScore,
                    'score_reliability' => $reliabilityScore,
                    'score_activity' => $activityScore,
                    'total_score' => $totalScore,
                    'tier' => $tier,
                ];
            }

            // sort desc by total_score
            usort($ranked, function($a, $b) { return $b['total_score'] <=> $a['total_score']; });

            // assign rank numbers
            foreach ($ranked as $i => &$r) $r['rank'] = $i + 1;
            unset($r);

            // ── 5. movement vs last snapshot (for up/down indicators) ──
            // pick the most recent snapshot strictly before today so today's auto-snapshot never becomes its own baseline
            $prevDate = null;
            $pdRes = $conn->query("SELECT MAX(snapshot_date) AS d FROM supplier_ranking_snapshots WHERE snapshot_date < CURDATE()");
            if ($pdRes) {
                $pdRow = $pdRes->fetch_assoc();
                if ($pdRow && !empty($pdRow['d'])) $prevDate = $pdRow['d'];
            }

            $prevMap = [];
            if ($prevDate) {
                $ps = $conn->prepare("SELECT supplier_id, rank_position, total_score, tier FROM supplier_ranking_snapshots WHERE snapshot_date = ?");
                $ps->bind_param("s", $prevDate);
                $ps->execute();
                $pr = $ps->get_result();
                while ($row = $pr->fetch_assoc()) {
                    $prevMap[$row['supplier_id']] = [
                        'rank' => intval($row['rank_position']),
                        'score' => floatval($row['total_score']),
                        'tier' => $row['tier']
                    ];
                }
                $ps->close();
            }

            // attach movement to each current supplier
            foreach ($ranked as &$r) {
                $p = $prevMap[$r['supplier_id']] ?? null;
                if ($p) {
                    $r['prev_rank']  = $p['rank'];
                    $r['prev_score'] = $p['score'];
                    $r['prev_tier']  = $p['tier'];
                    $r['rank_delta'] = $p['rank'] - $r['rank'];      // +ve = went up, -ve = went down
                    $r['score_delta']= round($r['total_score'] - $p['score'], 1);
                    $r['movement']   = $r['rank_delta'] > 0 ? 'up' : ($r['rank_delta'] < 0 ? 'down' : 'same');
                } else {
                    $r['prev_rank']  = null;
                    $r['prev_score'] = null;
                    $r['prev_tier']  = null;
                    $r['rank_delta'] = null;
                    $r['score_delta']= null;
                    // 'new' only if a baseline existed — if there's no baseline at all, everyone is 'same'
                    $r['movement']   = $prevDate ? 'new' : 'same';
                }
            }
            unset($r);

            // ── 6. auto-snapshot today if not already taken ──
            $today = date('Y-m-d');
            $hasToday = false;
            $chk = $conn->prepare("SELECT 1 FROM supplier_ranking_snapshots WHERE snapshot_date = ? LIMIT 1");
            $chk->bind_param("s", $today);
            $chk->execute();
            $hasToday = ($chk->get_result()->num_rows > 0);
            $chk->close();

            if (!$hasToday) {
                $ins = $conn->prepare("INSERT INTO supplier_ranking_snapshots (snapshot_date, supplier_id, rank_position, total_score, tier) VALUES (?, ?, ?, ?, ?)");
                foreach ($ranked as $r) {
                    $sid = $r['supplier_id']; $rp = $r['rank']; $ts = $r['total_score']; $tr = $r['tier'];
                    $ins->bind_param("ssids", $today, $sid, $rp, $ts, $tr);
                    $ins->execute();
                }
                $ins->close();
            }

            // summary counts
            $summary = ['A'=>0,'B'=>0,'C'=>0,'D'=>0];
            $movementSummary = ['up'=>0,'down'=>0,'same'=>0,'new'=>0];
            foreach ($ranked as $r) {
                $summary[$r['tier']]++;
                $movementSummary[$r['movement']]++;
            }

            $conn->close();
            echo json_encode([
                'success' => true,
                'data' => $ranked,
                'summary' => $summary,
                'movement_summary' => $movementSummary,
                'weights' => $w,
                'baseline_date' => $prevDate
            ]);
            exit();
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
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
    <title>Supplier Ranking - Dashboard System</title>

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

    <style>
        /* tier badges */
        .tier-badge { display:inline-flex; align-items:center; justify-content:center; width:32px; height:32px; border-radius:50%; font-weight:800; font-size:14px; color:#fff; box-shadow:0 2px 4px rgba(0,0,0,.15); }
        .tier-A { background:linear-gradient(135deg,#1a9c6b,#0d7a4f); }
        .tier-B { background:linear-gradient(135deg,#0074D9,#0057a3); }
        .tier-C { background:linear-gradient(135deg,#f39c12,#d4820b); }
        .tier-D { background:linear-gradient(135deg,#e74c3c,#b8341a); }

        /* rank column */
        .rank-num { font-size:18px; font-weight:800; color:var(--navy-primary); min-width:32px; text-align:center; }
        .rank-1 { color:#f1c40f; }
        .rank-2 { color:#95a5a6; }
        .rank-3 { color:#cd7f32; }

        /* score bar */
        .score-bar { width:100%; height:10px; border-radius:6px; background:#eef0f3; overflow:hidden; position:relative; }
        .score-bar-fill { height:100%; border-radius:6px; transition:width .4s; }
        .score-text { font-weight:700; font-size:13px; margin-top:2px; display:block; }

        /* summary cards */
        .tier-summary-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:14px; margin-bottom:20px; }
        .tier-summary-card { background:#fff; border-radius:10px; padding:16px 18px; box-shadow:0 1px 3px rgba(0,0,0,0.06); border-left:5px solid transparent; display:flex; align-items:center; gap:14px; transition:transform .15s, box-shadow .15s; }
        .tier-summary-card:hover { transform:translateY(-2px); box-shadow:0 4px 12px rgba(0,0,0,0.1); }
        .tier-summary-card.tier-a-border { border-left-color:#1a9c6b; }
        .tier-summary-card.tier-b-border { border-left-color:#0074D9; }
        .tier-summary-card.tier-c-border { border-left-color:#f39c12; }
        .tier-summary-card.tier-d-border { border-left-color:#e74c3c; }
        .tier-summary-icon { width:48px; height:48px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:20px; color:#fff; flex-shrink:0; }
        .tier-summary-value { font-size:28px; font-weight:800; color:var(--navy-primary); line-height:1; }
        .tier-summary-label { font-size:11px; text-transform:uppercase; letter-spacing:.5px; color:var(--text-muted); margin-top:4px; font-weight:600; }

        /* movement indicators */
        .movement-pill { display:inline-flex; align-items:center; gap:3px; font-size:11px; font-weight:700; padding:2px 7px; border-radius:10px; line-height:1; }
        .mv-up   { background:rgba(26,156,107,0.14);  color:#0d7a4f; }
        .mv-down { background:rgba(231,76,60,0.14);   color:#b8341a; }
        .mv-same { background:rgba(127,140,141,0.14); color:#5c6a75; }
        .mv-new  { background:rgba(155,89,182,0.14);  color:#7b3f95; }
        .rank-stack { display:flex; flex-direction:column; align-items:center; gap:2px; }

        /* movement summary strip */
        .movement-strip { display:flex; flex-wrap:wrap; gap:10px; margin-bottom:18px; background:#fff; border-radius:8px; padding:10px 14px; box-shadow:0 1px 3px rgba(0,0,0,0.06); align-items:center; }
        .movement-strip-title { font-size:11px; text-transform:uppercase; letter-spacing:.5px; color:var(--text-muted); font-weight:600; margin-right:4px; }
        .movement-chip { display:inline-flex; align-items:center; gap:6px; padding:5px 11px; border-radius:20px; font-weight:700; font-size:13px; }
        .movement-chip i { font-size:11px; }
        .mc-up   { background:rgba(26,156,107,0.14);  color:#0d7a4f; }
        .mc-down { background:rgba(231,76,60,0.14);   color:#b8341a; }
        .mc-same { background:rgba(127,140,141,0.14); color:#5c6a75; }
        .mc-new  { background:rgba(155,89,182,0.14);  color:#7b3f95; }
        .baseline-note { font-size:11px; color:var(--text-muted); margin-left:auto; font-style:italic; }

        /* expanded row */
        .rank-detail { padding:16px 24px; background:#f8f9fc; }
        .rank-detail-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:12px; }
        .rank-metric { background:#fff; border:1px solid #e3e6ea; border-radius:6px; padding:10px 12px; }
        .rank-metric-label { font-size:11px; text-transform:uppercase; color:var(--text-muted); font-weight:600; letter-spacing:.3px; }
        .rank-metric-value { font-size:16px; font-weight:700; color:var(--navy-primary); margin-top:2px; }

        @media (max-width: 900px) {
            .tier-summary-grid { grid-template-columns:repeat(2,1fr); }
            .rank-detail-grid { grid-template-columns:repeat(2,1fr); }
        }
    </style>
</head>
<body>
    <?php include 'mobile-menu.php'; ?>

    <div class="app-container">
        <?php include 'sidebar.php'; ?>

        <div class="main-content">
            <div class="breadcrumb">
                <a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
                <span class="breadcrumb-sep">/</span>
                <span>Supplier Ranking</span>
            </div>

            <div class="header">
                <h1><i class="fas fa-ranking-star"></i> Supplier Ranking</h1>
                <div>Welcome, <?php echo htmlspecialchars($username); ?></div>
            </div>

            <!-- scoring explainer -->
            <div style="background:rgba(0,116,217,0.06); border-left:4px solid var(--navy-accent); padding:14px 18px; border-radius:6px; margin-bottom:18px; font-size:13px; color:#31414f;">
                <strong style="color:var(--navy-primary);"><i class="fas fa-info-circle"></i> How the score is calculated</strong>
                <div style="margin-top:6px; display:flex; gap:18px; flex-wrap:wrap;">
                    <span><b>Volume 40%</b> — kg delivered + monetary value</span>
                    <span><b>Quality 20%</b> — avg KOR out-turn + grainage</span>
                    <span><b>Reliability 25%</b> — how past advances were handled</span>
                    <span><b>Activity 15%</b> — recent deliveries (last 90 days)</span>
                </div>
                <div style="margin-top:6px; color:var(--text-muted); font-size:12px;">
                    Use this ranking to decide who gets pre-financed next — Tier A = safest bet, Tier D = avoid.
                </div>
            </div>

            <!-- movement strip — who went up, who went down since last snapshot -->
            <div class="movement-strip" id="movementStrip" style="display:none;">
                <span class="movement-strip-title"><i class="fas fa-chart-line"></i> Movement</span>
                <span class="movement-chip mc-up" id="mvUpChip"><i class="fas fa-arrow-up"></i> <span id="mvUpCount">0</span> climbed</span>
                <span class="movement-chip mc-down" id="mvDownChip"><i class="fas fa-arrow-down"></i> <span id="mvDownCount">0</span> dropped</span>
                <span class="movement-chip mc-same" id="mvSameChip"><i class="fas fa-equals"></i> <span id="mvSameCount">0</span> unchanged</span>
                <span class="movement-chip mc-new" id="mvNewChip"><i class="fas fa-sparkles"></i> <span id="mvNewCount">0</span> new</span>
                <span class="baseline-note" id="baselineNote"></span>
            </div>

            <!-- tier summary -->
            <div class="tier-summary-grid" id="tierSummary">
                <div class="tier-summary-card tier-a-border">
                    <div class="tier-summary-icon" style="background:linear-gradient(135deg,#1a9c6b,#0d7a4f);"><i class="fas fa-crown"></i></div>
                    <div><div class="tier-summary-value" id="tierACount">0</div><div class="tier-summary-label">Tier A · Preferred</div></div>
                </div>
                <div class="tier-summary-card tier-b-border">
                    <div class="tier-summary-icon" style="background:linear-gradient(135deg,#0074D9,#0057a3);"><i class="fas fa-medal"></i></div>
                    <div><div class="tier-summary-value" id="tierBCount">0</div><div class="tier-summary-label">Tier B · Reliable</div></div>
                </div>
                <div class="tier-summary-card tier-c-border">
                    <div class="tier-summary-icon" style="background:linear-gradient(135deg,#f39c12,#d4820b);"><i class="fas fa-triangle-exclamation"></i></div>
                    <div><div class="tier-summary-value" id="tierCCount">0</div><div class="tier-summary-label">Tier C · Caution</div></div>
                </div>
                <div class="tier-summary-card tier-d-border">
                    <div class="tier-summary-icon" style="background:linear-gradient(135deg,#e74c3c,#b8341a);"><i class="fas fa-ban"></i></div>
                    <div><div class="tier-summary-value" id="tierDCount">0</div><div class="tier-summary-label">Tier D · Avoid</div></div>
                </div>
            </div>

            <div class="data-section">
                <div class="section-header">
                    <h2><i class="fas fa-list-ol"></i> Ranked Suppliers</h2>
                    <button class="btn btn-primary" onclick="loadRanking()"><i class="fas fa-sync"></i> Refresh</button>
                </div>

                <div id="skeletonLoader">
                    <div class="skeleton-table">
                        <div class="skeleton-table-row"><div class="skeleton skeleton-table-cell" style="flex:1"></div><div class="skeleton skeleton-table-cell" style="flex:1"></div><div class="skeleton skeleton-table-cell" style="flex:1"></div><div class="skeleton skeleton-table-cell" style="flex:1"></div></div>
                        <div class="skeleton-table-row"><div class="skeleton skeleton-table-cell" style="flex:1"></div><div class="skeleton skeleton-table-cell" style="flex:1"></div><div class="skeleton skeleton-table-cell" style="flex:1"></div><div class="skeleton skeleton-table-cell" style="flex:1"></div></div>
                        <div class="skeleton-table-row"><div class="skeleton skeleton-table-cell" style="flex:1"></div><div class="skeleton skeleton-table-cell" style="flex:1"></div><div class="skeleton skeleton-table-cell" style="flex:1"></div><div class="skeleton skeleton-table-cell" style="flex:1"></div></div>
                    </div>
                </div>

                <div id="tableContainer" style="display:none;">
                    <table id="rankingTable" class="display responsive nowrap" style="width:100%;">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Move</th>
                                <th>Supplier</th>
                                <th>Tier</th>
                                <th>Score</th>
                                <th>Volume</th>
                                <th>Reliability</th>
                                <th>Activity</th>
                                <th>Outstanding</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

<script>
var rankingData = [];
var rankingTable = null;

$(document).ready(function() { loadRanking(); });

function fmtNum(n) { return Math.round(n || 0).toLocaleString('en-US'); }

function loadRanking() {
    $('#skeletonLoader').show();
    $('#tableContainer').hide();
    $.getJSON('?action=getRanking', function(res) {
        if (!res.success) {
            $('#skeletonLoader').hide();
            Swal.fire({icon:'error', title:'Error', text: res.message || 'Failed to load ranking'});
            return;
        }
        rankingData = res.data;
        $('#tierACount').text(res.summary.A);
        $('#tierBCount').text(res.summary.B);
        $('#tierCCount').text(res.summary.C);
        $('#tierDCount').text(res.summary.D);

        // movement strip (hidden until we have a baseline to compare against)
        var m = res.movement_summary || {up:0, down:0, same:0, new:0};
        if (res.baseline_date) {
            $('#mvUpCount').text(m.up);
            $('#mvDownCount').text(m.down);
            $('#mvSameCount').text(m.same);
            $('#mvNewCount').text(m['new']);
            $('#baselineNote').text('Compared to snapshot from ' + res.baseline_date);
            $('#movementStrip').css('display', 'flex');
        } else {
            $('#baselineNote').text('First snapshot saved — movement will show from next refresh');
            $('#mvUpCount').text(0); $('#mvDownCount').text(0); $('#mvSameCount').text(0); $('#mvNewCount').text(0);
            $('#movementStrip').css('display', 'flex');
        }

        $('#skeletonLoader').hide();
        $('#tableContainer').show();
        renderTable();
    }).fail(function() {
        $('#skeletonLoader').hide();
        Swal.fire({icon:'error', title:'Connection Error', text:'Could not load ranking.'});
    });
}

function scoreBar(value) {
    var color = value >= 80 ? '#1a9c6b' : value >= 65 ? '#0074D9' : value >= 50 ? '#f39c12' : '#e74c3c';
    return '<div class="score-bar"><div class="score-bar-fill" style="width:' + Math.min(100, value) + '%;background:' + color + ';"></div></div>' +
           '<span class="score-text" style="color:' + color + ';">' + value.toFixed(1) + '</span>';
}

function scoreBarWithDelta(row) {
    var base = scoreBar(row.total_score);
    if (row.score_delta == null) return base;
    var sd = row.score_delta;
    if (Math.abs(sd) < 0.05) return base;
    var color = sd > 0 ? '#0d7a4f' : '#b8341a';
    var arrow = sd > 0 ? '▲' : '▼';
    return base + '<span style="font-size:10px;color:' + color + ';font-weight:700;margin-left:4px;">' + arrow + ' ' + Math.abs(sd).toFixed(1) + '</span>';
}

function renderMovementPill(row) {
    var mv = row.movement || 'same';
    if (mv === 'up')   return '<span class="movement-pill mv-up"><i class="fas fa-arrow-up"></i> +' + row.rank_delta + '</span>';
    if (mv === 'down') return '<span class="movement-pill mv-down"><i class="fas fa-arrow-down"></i> ' + row.rank_delta + '</span>';
    if (mv === 'new')  return '<span class="movement-pill mv-new"><i class="fas fa-sparkles"></i> NEW</span>';
    return '<span class="movement-pill mv-same"><i class="fas fa-equals"></i> —</span>';
}

function renderTable() {
    if (rankingTable) { rankingTable.destroy(); $('#rankingTable tbody').empty(); }

    rankingTable = $('#rankingTable').DataTable({
        data: rankingData,
        responsive: true,
        pageLength: 50,
        order: [[0, 'asc']],
        dom: 'Bfrtip',
        buttons: ['copy','csv','pdf','print'],
        columns: [
            { data: 'rank', width: '50px', className: 'dt-center',
              render: function(d, t, row) {
                  var cls = d === 1 ? 'rank-1' : d === 2 ? 'rank-2' : d === 3 ? 'rank-3' : '';
                  var icon = d <= 3 ? '<i class="fas fa-trophy" style="font-size:11px;margin-right:3px;"></i>' : '';
                  var prev = row.prev_rank ? '<span style="font-size:10px;color:var(--text-muted);">was #' + row.prev_rank + '</span>' : '';
                  return '<div class="rank-stack"><span class="rank-num ' + cls + '">' + icon + '#' + d + '</span>' + prev + '</div>';
              }
            },
            { data: null, width: '70px', className: 'dt-center',
              orderable: true,
              render: function(row, t) {
                  if (t === 'sort' || t === 'type') return row.rank_delta == null ? -9999 : row.rank_delta;
                  return renderMovementPill(row);
              }
            },
            { data: null,
              render: function(row) {
                  return '<div style="font-weight:600;color:var(--navy-primary);">' + row.first_name + '</div>' +
                         '<div style="font-size:11px;color:var(--text-muted);">' + row.supplier_id + (row.region ? ' · ' + row.region : '') + '</div>';
              }
            },
            { data: 'tier', className: 'dt-center',
              render: function(t) { return '<span class="tier-badge tier-' + t + '">' + t + '</span>'; }
            },
            { data: 'total_score',
              render: function(v, t, row) { return scoreBarWithDelta(row); }
            },
            { data: null,
              render: function(row) {
                  return '<div style="font-weight:700;color:var(--navy-primary);">' + fmtNum(row.total_kg) + ' kg</div>' +
                         '<div style="font-size:11px;color:var(--text-muted);">' + fmtNum(row.total_value) + ' F · ' + row.purchase_count + ' runs</div>';
              }
            },
            { data: 'score_reliability',
              render: function(v) { return scoreBar(v); }
            },
            { data: null,
              render: function(row) {
                  if (!row.last_purchase_date) return '<span style="color:var(--text-muted);font-size:12px;">Never</span>';
                  var d = row.days_since_last;
                  var color = d <= 30 ? '#1a9c6b' : d <= 90 ? '#f39c12' : '#e74c3c';
                  return '<div style="color:' + color + ';font-weight:600;font-size:12px;">' + d + 'd ago</div>' +
                         '<div style="font-size:11px;color:var(--text-muted);">' + row.last_purchase_date + '</div>';
              }
            },
            { data: 'outstanding',
              render: function(v) {
                  if (v <= 0) return '<span style="color:var(--text-muted);font-size:12px;">—</span>';
                  return '<span style="color:#e74c3c;font-weight:700;">' + fmtNum(v) + ' F</span>';
              }
            }
        ],
        language: { search: '', searchPlaceholder: 'Search supplier...' }
    });

    // expandable row detail on click
    $('#rankingTable tbody').off('click').on('click', 'tr', function(e) {
        if ($(e.target).is('button, a, input')) return;
        var row = rankingTable.row(this);
        if (!row.data()) return;
        if (row.child.isShown()) { row.child.hide(); $(this).removeClass('shown'); }
        else { row.child(renderDetail(row.data())).show(); $(this).addClass('shown'); }
    });
}

function renderDetail(d) {
    return '<div class="rank-detail"><div class="rank-detail-grid">' +
        '<div class="rank-metric"><div class="rank-metric-label">Volume Score</div><div class="rank-metric-value" style="color:#0074D9;">' + d.score_volume.toFixed(1) + '</div></div>' +
        '<div class="rank-metric"><div class="rank-metric-label">Quality Score</div><div class="rank-metric-value" style="color:#9b59b6;">' + d.score_quality.toFixed(1) + '</div></div>' +
        '<div class="rank-metric"><div class="rank-metric-label">Reliability Score</div><div class="rank-metric-value" style="color:#1a9c6b;">' + d.score_reliability.toFixed(1) + '</div></div>' +
        '<div class="rank-metric"><div class="rank-metric-label">Activity Score</div><div class="rank-metric-value" style="color:#f39c12;">' + d.score_activity.toFixed(1) + '</div></div>' +
        '<div class="rank-metric"><div class="rank-metric-label">Avg KOR Out-Turn</div><div class="rank-metric-value">' + (d.avg_kor > 0 ? d.avg_kor.toFixed(1) + '%' : '—') + '</div></div>' +
        '<div class="rank-metric"><div class="rank-metric-label">Avg Grainage</div><div class="rank-metric-value">' + (d.avg_grainage > 0 ? d.avg_grainage.toFixed(1) : '—') + '</div></div>' +
        '<div class="rank-metric"><div class="rank-metric-label">Total Advances Given</div><div class="rank-metric-value">' + fmtNum(d.total_advance) + ' F</div></div>' +
        '<div class="rank-metric"><div class="rank-metric-label">Advance Count</div><div class="rank-metric-value">' + d.advance_count + '</div></div>' +
        '</div></div>';
}
</script>
</body>
</html>
