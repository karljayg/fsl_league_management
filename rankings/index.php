<?php
/**
 * Player Rankings Page
 * Display and manage player power rankings
 */

ob_start();
session_start();
require_once __DIR__ . '/../includes/db.php';

$rankingsFile = __DIR__ . '/rankings.json';
$configFile = __DIR__ . '/rankings_config.json';

// Check if user has edit permission
$canEdit = false;
if (isset($_SESSION['user_id'])) {
    try {
        $stmt = $db->prepare("
            SELECT COUNT(*) as cnt
            FROM ws_user_roles ur
            JOIN ws_role_permissions rp ON ur.role_id = rp.role_id
            JOIN ws_permissions p ON rp.permission_id = p.permission_id
            WHERE ur.user_id = ? AND p.permission_name = ?
        ");
        $stmt->execute([$_SESSION['user_id'], 'edit player, team, stats']);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $canEdit = $result && $result['cnt'] > 0;
    } catch (PDOException $e) {
        // Silent fail
    }
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_end_clean();
    if (!$canEdit) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Permission denied']);
        exit;
    }
    header('Content-Type: application/json');
    
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $input['action'] ?? '';
    
    if ($action === 'move') {
        $fromIndex = $input['from'] ?? -1;
        $toIndex = $input['to'] ?? -1;
        
        $rankings = json_decode(file_get_contents($rankingsFile), true);
        
        if ($fromIndex >= 0 && $fromIndex < count($rankings) && 
            $toIndex >= 0 && $toIndex < count($rankings)) {
            
            // Remove player from old position
            $player = array_splice($rankings, $fromIndex, 1)[0];
            // When dragging down, indices shift after removal
            $insertIndex = ($fromIndex < $toIndex) ? $toIndex - 1 : $toIndex;
            array_splice($rankings, $insertIndex, 0, [$player]);
            
            // Re-number ranks
            foreach ($rankings as $i => &$p) {
                $p['rank'] = $i + 1;
            }
            
            file_put_contents($rankingsFile, json_encode($rankings, JSON_PRETTY_PRINT));
            echo json_encode(['success' => true]);
            exit;
        }
        
        echo json_encode(['success' => false, 'error' => 'Invalid indices']);
        exit;
    }
    
    if ($action === 'update') {
        $rankings = $input['rankings'] ?? [];
        if (!empty($rankings)) {
            file_put_contents($rankingsFile, json_encode($rankings, JSON_PRETTY_PRINT));
            echo json_encode(['success' => true]);
            exit;
        }
        echo json_encode(['success' => false, 'error' => 'No rankings provided']);
        exit;
    }
    
    if ($action === 'save_code_tiers') {
        $config = [
            'codeS' => ['minRank' => (int)($input['codeS_min'] ?? 1), 'maxRank' => (int)($input['codeS_max'] ?? 24)],
            'codeA' => ['minRank' => (int)($input['codeA_min'] ?? 25), 'maxRank' => (int)($input['codeA_max'] ?? 36)],
            'codeB' => ['minRank' => (int)($input['codeB_min'] ?? 37), 'maxRank' => (int)($input['codeB_max'] ?? 46)]
        ];
        $result = @file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT));
        if ($result === false) {
            echo json_encode(['success' => false, 'error' => 'Could not save config. Check that rankings folder is writable.']);
            exit;
        }
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'update_name') {
        $index = isset($input['index']) ? (int) $input['index'] : -1;
        $name = isset($input['name']) ? trim((string) $input['name']) : '';
        $rankings = json_decode(file_get_contents($rankingsFile), true);
        if (!is_array($rankings) || $index < 0 || $index >= count($rankings)) {
            echo json_encode(['success' => false, 'error' => 'Invalid index']);
            exit;
        }
        if ($name === '') {
            echo json_encode(['success' => false, 'error' => 'Name cannot be empty']);
            exit;
        }
        $rankings[$index]['name'] = $name;
        file_put_contents($rankingsFile, json_encode($rankings, JSON_PRETTY_PRINT));
        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Unknown action']);
    exit;
}

// Load rankings
$rankings = [];
if (file_exists($rankingsFile)) {
    $rankings = json_decode(file_get_contents($rankingsFile), true) ?? [];
}

// Load code tier config (Code S, A, B rank ranges)
$totalPlayers = count($rankings);
$third = max(1, (int) ceil($totalPlayers / 3));
$codeTiers = [
    'codeS' => ['minRank' => 1, 'maxRank' => $third],
    'codeA' => ['minRank' => $third + 1, 'maxRank' => 2 * $third],
    'codeB' => ['minRank' => 2 * $third + 1, 'maxRank' => $totalPlayers]
];
if (file_exists($configFile)) {
    $loaded = json_decode(file_get_contents($configFile), true);
    if ($loaded) {
        foreach (['codeS', 'codeA', 'codeB'] as $tier) {
            if (isset($loaded[$tier]['minRank'], $loaded[$tier]['maxRank'])) {
                $codeTiers[$tier] = ['minRank' => (int)$loaded[$tier]['minRank'], 'maxRank' => (int)$loaded[$tier]['maxRank']];
            } elseif (isset($loaded[$tier]['minGroup'], $loaded[$tier]['maxGroup'])) {
                $codeTiers[$tier] = ['minRank' => ($loaded[$tier]['minGroup'] - 1) * 4 + 1, 'maxRank' => $loaded[$tier]['maxGroup'] * 4];
            }
        }
    }
}

$raceIcons = [
    'T' => 'terran_icon.png',
    'P' => 'protoss_icon.png', 
    'Z' => 'zerg_icon.png',
    'R' => 'random_icon.png'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="../images/favicon.png" type="image/png">
    <title>Player Rankings - FSL</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@500;600;700&family=Exo+2:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/styles.css">
    <style>
        body {
            background: linear-gradient(135deg, #0a0a0f 0%, #1a1a2e 50%, #0a0a0f 100%);
            min-height: 100vh;
            color: #fff;
        }
        
        .rankings-container {
            max-width: 800px;
            margin: 0 auto;
            padding: 2rem;
        }
        
        .rankings-header {
            text-align: center;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid rgba(108, 92, 231, 0.3);
        }
        
        .rankings-header h1 {
            font-family: 'Rajdhani', sans-serif;
            font-size: 2.5rem;
            font-weight: 700;
            background: linear-gradient(135deg, #6c5ce7, #a29bfe);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            text-transform: uppercase;
            letter-spacing: 3px;
            margin: 0;
        }
        
        .rankings-header p {
            color: #888;
            margin-top: 0.5rem;
        }
        
        .rankings-list {
            background: rgba(0, 0, 0, 0.3);
            border-radius: 10px;
            padding: 1rem;
        }
        
        .player-row {
            display: flex;
            align-items: center;
            padding: 0.75rem 1rem;
            background: rgba(0, 0, 0, 0.2);
            border-radius: 8px;
            margin-bottom: 0.5rem;
            transition: all 0.2s ease;
            position: relative;
            overflow: hidden;
        }
        
        .player-row:hover {
            background: rgba(108, 92, 231, 0.15);
        }
        
        /* Skill tier band - left edge indicator */
        .skill-band {
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 10px;
            border-radius: 8px 0 0 8px;
        }
        
        /* Group colors - gradient from bright to dark */
        .skill-band.g1  { background: linear-gradient(180deg, #00d4aa, #00b894); }
        .skill-band.g2  { background: linear-gradient(180deg, #00b894, #00a187); }
        .skill-band.g3  { background: linear-gradient(180deg, #00a187, #008f7a); }
        .skill-band.g4  { background: linear-gradient(180deg, #55a3ff, #4a90e2); }
        .skill-band.g5  { background: linear-gradient(180deg, #4a90e2, #3d7bc7); }
        .skill-band.g6  { background: linear-gradient(180deg, #3d7bc7, #3066ac); }
        .skill-band.g7  { background: linear-gradient(180deg, #9b7fd4, #8b6fc4); }
        .skill-band.g8  { background: linear-gradient(180deg, #8b6fc4, #7b5fb4); }
        .skill-band.g9  { background: linear-gradient(180deg, #7b5fb4, #6b4fa4); }
        .skill-band.g10 { background: linear-gradient(180deg, #6b6b7b, #5b5b6b); }
        .skill-band.g11 { background: linear-gradient(180deg, #5b5b6b, #4b4b5b); }
        .skill-band.g12 { background: linear-gradient(180deg, #4b4b5b, #3b3b4b); }
        
        .player-row.dragging {
            opacity: 0.5;
            background: rgba(108, 92, 231, 0.3);
        }
        
        .player-row.drag-over {
            border-top: 2px solid #6c5ce7;
        }
        
        .rank-badge {
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #6c5ce7, #a29bfe);
            border-radius: 50%;
            font-family: 'Rajdhani', sans-serif;
            font-size: 1.1rem;
            font-weight: 700;
            color: #fff;
            margin-right: 1rem;
            flex-shrink: 0;
        }
        
        .player-name {
            font-family: 'Exo 2', sans-serif;
            font-size: 1.2rem;
            font-weight: 600;
            flex: 1;
        }
        
        .player-name a {
            color: #fff;
            text-decoration: none;
        }
        
        .player-name a:hover {
            color: #a29bfe;
        }
        
        .player-name a.edit-name-trigger {
            cursor: text;
        }
        
        .player-name input.edit-name-input {
            width: 100%;
            max-width: 220px;
            padding: 0.2rem 0.4rem;
            font-family: inherit;
            font-size: 1.2rem;
            font-weight: 600;
            background: rgba(108, 92, 231, 0.2);
            border: 1px solid rgba(108, 92, 231, 0.5);
            border-radius: 4px;
            color: #fff;
        }
        
        .race-icon {
            width: 28px;
            height: 28px;
            margin-right: 1rem;
        }
        
        .group-badge {
            font-size: 0.85rem;
            color: #6c5ce7;
            background: rgba(108, 92, 231, 0.2);
            padding: 0.25rem 0.75rem;
            border-radius: 15px;
            margin-right: 0.5rem;
        }
        
        .move-buttons {
            display: flex;
            gap: 0.25rem;
        }
        
        .move-btn {
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(108, 92, 231, 0.2);
            border: 1px solid rgba(108, 92, 231, 0.4);
            border-radius: 5px;
            color: #a29bfe;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .move-btn:hover {
            background: rgba(108, 92, 231, 0.4);
            color: #fff;
        }
        
        .move-btn:disabled {
            opacity: 0.3;
            cursor: not-allowed;
        }
        
        .drag-handle {
            cursor: grab;
            padding: 0 0.5rem;
            color: #555;
            margin-right: 0.5rem;
        }
        
        .drag-handle:active {
            cursor: grabbing;
        }
        
        .edit-mode-toggle {
            text-align: center;
            margin-bottom: 1rem;
        }
        
        .edit-btn {
            padding: 0.5rem 1.5rem;
            background: linear-gradient(135deg, #6c5ce7, #a29bfe);
            border: none;
            border-radius: 5px;
            color: #fff;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .edit-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(108, 92, 231, 0.4);
        }
        
        .rankings-with-indicator {
            display: flex;
            gap: 0;
            align-items: stretch;
        }
        
        .code-tier-strip {
            width: 28px;
            min-width: 28px;
            border-radius: 8px 0 0 8px;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }
        
        .code-zone {
            flex: 1;
            min-height: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
        }
        
        .code-zone.code-s { background: linear-gradient(180deg, #ffd700, #ffb347); }
        .code-zone.code-a { background: linear-gradient(180deg, #c0c0c0, #a8a8a8); }
        .code-zone.code-b { background: linear-gradient(180deg, #cd7f32, #a0522d); }
        
        .code-zone-label {
            display: block;
            text-align: center;
            font-size: 1rem;
            font-weight: 800;
            color: rgba(0,0,0,0.85);
            line-height: 1.15;
            letter-spacing: 0.5px;
        }
        
        .code-tier-edit-btn {
            margin-left: 0.5rem;
            padding: 0.25rem 0.5rem;
            font-size: 0.85rem;
            background: rgba(108, 92, 231, 0.2);
            border: 1px solid rgba(108, 92, 231, 0.4);
            border-radius: 4px;
            color: #a29bfe;
            cursor: pointer;
        }
        
        .code-tier-edit-btn:hover { background: rgba(108, 92, 231, 0.4); color: #fff; }
        
        .code-tier-modal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.6);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        
        .code-tier-modal.show { display: flex; }
        
        .code-tier-modal-inner {
            background: #1a1a2e;
            padding: 1.5rem;
            border-radius: 10px;
            border: 1px solid rgba(108, 92, 231, 0.3);
            min-width: 320px;
        }
        
        .code-tier-modal h3 { margin-top: 0; color: #a29bfe; }
        
        .code-tier-row { display: flex; align-items: center; gap: 0.5rem; margin-bottom: 1rem; }
        
        .code-tier-row label { min-width: 70px; color: #ccc; }
        
        .code-tier-row input { width: 50px; padding: 0.35rem; background: #0a0a0f; border: 1px solid #333; color: #fff; border-radius: 4px; }
        
        .code-tier-modal-actions { margin-top: 1.5rem; display: flex; gap: 0.5rem; justify-content: flex-end; }
        
        .player-row[data-code="S"] { border-left: 3px solid #ffd700; }
        .player-row[data-code="A"] { border-left: 3px solid #c0c0c0; }
        .player-row[data-code="B"] { border-left: 3px solid #cd7f32; }
        
        .save-indicator {
            position: fixed;
            bottom: 1rem;
            right: 1rem;
            padding: 0.5rem 1rem;
            background: rgba(0, 184, 148, 0.9);
            border-radius: 5px;
            color: #fff;
            display: none;
        }
        
        .save-indicator.show {
            display: block;
            animation: fadeInOut 2s forwards;
        }
        
        @keyframes fadeInOut {
            0% { opacity: 0; transform: translateY(10px); }
            20% { opacity: 1; transform: translateY(0); }
            80% { opacity: 1; }
            100% { opacity: 0; }
        }
        
        
        @media (max-width: 768px) {
            .rankings-container { padding: 1rem; }
            .player-row { padding: 0.5rem; }
            .rank-badge { width: 32px; height: 32px; font-size: 0.9rem; }
            .player-name { font-size: 1rem; }
            .move-buttons { display: none; }
        }
    </style>
</head>
<body>
    <?php include_once '../includes/nav.php'; ?>
    
    <div class="rankings-container">
        <div class="rankings-header">
            <h1>KJ's Power Rankings</h1>
            <p>Code S · Code A · Code B
                <?php if ($canEdit): ?>
                <button type="button" class="code-tier-edit-btn" onclick="openCodeTierModal()" title="Edit Code tier ranges"><i class="fas fa-cog"></i> Edit ranges</button>
                <?php endif; ?>
            </p>
        </div>
        
        <?php if ($canEdit): ?>
        <div class="edit-mode-toggle">
            <button class="edit-btn" id="editModeBtn" onclick="toggleEditMode()">
                <i class="fas fa-edit"></i> Enable Edit Mode
            </button>
        </div>
        <?php endif; ?>
        
        <?php
        $sRows = $aRows = $bRows = 0;
        foreach ($rankings as $player) {
            $rank = (int) $player['rank'];
            if ($rank >= $codeTiers['codeS']['minRank'] && $rank <= $codeTiers['codeS']['maxRank']) $sRows++;
            elseif ($rank >= $codeTiers['codeA']['minRank'] && $rank <= $codeTiers['codeA']['maxRank']) $aRows++;
            else $bRows++;
        }
        ?>
        <div class="rankings-with-indicator">
            <div class="code-tier-strip" title="Code S: #<?= $codeTiers['codeS']['minRank'] ?>–<?= $codeTiers['codeS']['maxRank'] ?> · Code A: #<?= $codeTiers['codeA']['minRank'] ?>–<?= $codeTiers['codeA']['maxRank'] ?> · Code B: #<?= $codeTiers['codeB']['minRank'] ?>–<?= $codeTiers['codeB']['maxRank'] ?>">
                <div class="code-zone code-s" style="flex: <?= $sRows ?>"><span class="code-zone-label"><?= implode('<br>', str_split('Code')) ?><br><br>S</span></div>
                <div class="code-zone code-a" style="flex: <?= $aRows ?>"><span class="code-zone-label"><?= implode('<br>', str_split('Code')) ?><br><br>A</span></div>
                <div class="code-zone code-b" style="flex: <?= $bRows ?>"><span class="code-zone-label"><?= implode('<br>', str_split('Code')) ?><br><br>B</span></div>
            </div>
        <div class="rankings-list" id="rankingsList">
            <?php 
            foreach ($rankings as $index => $player): 
                $rank = (int) $player['rank'];
                $group = (int) ceil($rank / 4);
                $code = ($rank >= $codeTiers['codeS']['minRank'] && $rank <= $codeTiers['codeS']['maxRank']) ? 'S' : 
                    (($rank >= $codeTiers['codeA']['minRank'] && $rank <= $codeTiers['codeA']['maxRank']) ? 'A' : 'B');
            ?>
                <div class="player-row" data-index="<?= $index ?>" data-code="<?= $code ?>" draggable="false">
                    <div class="skill-band g<?= $group ?>"></div>
                    <?php if ($canEdit): ?>
                    <span class="drag-handle edit-only" style="display: none;"><i class="fas fa-grip-vertical"></i></span>
                    <?php endif; ?>
                    <span class="rank-badge"><?= $player['rank'] ?></span>
                    <img src="../images/<?= $raceIcons[$player['race']] ?? 'random_icon.png' ?>" alt="<?= $player['race'] ?>" class="race-icon">
                    <span class="player-name" data-index="<?= $index ?>">
                        <a href="../view_player.php?name=<?= urlencode($player['name']) ?>" class="player-name-link"><?= htmlspecialchars($player['name']) ?></a>
                    </span>
                    <span class="group-badge">G<?= $group ?></span>
                    <?php if ($canEdit): ?>
                    <div class="move-buttons edit-only" style="display: none;">
                        <button class="move-btn move-up" data-index="<?= $index ?>" <?= $index === 0 ? 'disabled' : '' ?>>
                            <i class="fas fa-chevron-up"></i>
                        </button>
                        <button class="move-btn move-down" data-index="<?= $index ?>" <?= $index === count($rankings) - 1 ? 'disabled' : '' ?>>
                            <i class="fas fa-chevron-down"></i>
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        </div>
    </div>
    
    <div class="code-tier-modal" id="codeTierModal">
        <div class="code-tier-modal-inner">
            <h3>Code Tier Ranges</h3>
            <p class="text-muted" style="font-size: 0.9rem; margin-bottom: 1rem;">Use player rank numbers (1–<?= $totalPlayers ?>). Code S ends at rank 24 = players ranked 1–24.</p>
            <div class="code-tier-row">
                <label>Code S:</label>
                <span>rank</span><input type="number" id="codeS_min" min="1" max="<?= $totalPlayers ?>" value="<?= $codeTiers['codeS']['minRank'] ?>">
                <span>to</span><input type="number" id="codeS_max" min="1" max="<?= $totalPlayers ?>" value="<?= $codeTiers['codeS']['maxRank'] ?>">
            </div>
            <div class="code-tier-row">
                <label>Code A:</label>
                <span>rank</span><input type="number" id="codeA_min" min="1" max="<?= $totalPlayers ?>" value="<?= $codeTiers['codeA']['minRank'] ?>">
                <span>to</span><input type="number" id="codeA_max" min="1" max="<?= $totalPlayers ?>" value="<?= $codeTiers['codeA']['maxRank'] ?>">
            </div>
            <div class="code-tier-row">
                <label>Code B:</label>
                <span>rank</span><input type="number" id="codeB_min" min="1" max="<?= $totalPlayers ?>" value="<?= $codeTiers['codeB']['minRank'] ?>">
                <span>to</span><input type="number" id="codeB_max" min="1" max="<?= $totalPlayers ?>" value="<?= $codeTiers['codeB']['maxRank'] ?>">
            </div>
            <div class="code-tier-modal-actions">
                <button type="button" class="edit-btn" style="background: rgba(255,255,255,0.1);" onclick="closeCodeTierModal()">Cancel</button>
                <button type="button" class="edit-btn" onclick="saveCodeTiers()"><i class="fas fa-save"></i> Save</button>
            </div>
        </div>
    </div>
    
    <div class="save-indicator" id="saveIndicator">
        <i class="fas fa-check"></i> Saved
    </div>
    
    <script>
        let editMode = false;
        const codeTiers = <?= json_encode($codeTiers) ?>;
        
        function getCodeForRank(rank) {
            if (rank >= codeTiers.codeS.minRank && rank <= codeTiers.codeS.maxRank) return 'S';
            if (rank >= codeTiers.codeA.minRank && rank <= codeTiers.codeA.maxRank) return 'A';
            return 'B';
        }
        
        function toggleEditMode() {
            editMode = !editMode;
            const btn = document.getElementById('editModeBtn');
            const editElements = document.querySelectorAll('.edit-only');
            const rows = document.querySelectorAll('.player-row');
            
            if (editMode) {
                btn.innerHTML = '<i class="fas fa-eye"></i> View Mode';
                btn.style.background = 'linear-gradient(135deg, #00b894, #55efc4)';
                editElements.forEach(el => { el.style.display = el.classList.contains('move-buttons') ? 'flex' : 'inline-block'; });
                rows.forEach(row => {
                    row.draggable = true;
                    row.addEventListener('dragstart', handleDragStart);
                    row.addEventListener('dragend', handleDragEnd);
                    row.addEventListener('dragover', handleDragOver);
                    row.addEventListener('drop', handleDrop);
                    row.addEventListener('dragleave', handleDragLeave);
                });
                document.getElementById('rankingsList').addEventListener('click', handleMoveButtonClick);
                document.getElementById('rankingsList').addEventListener('click', handleNameClick);
            } else {
                btn.innerHTML = '<i class="fas fa-edit"></i> Enable Edit Mode';
                btn.style.background = 'linear-gradient(135deg, #6c5ce7, #a29bfe)';
                editElements.forEach(el => el.style.display = 'none');
                rows.forEach(row => {
                    row.draggable = false;
                    row.removeEventListener('dragstart', handleDragStart);
                    row.removeEventListener('dragend', handleDragEnd);
                    row.removeEventListener('dragover', handleDragOver);
                    row.removeEventListener('drop', handleDrop);
                    row.removeEventListener('dragleave', handleDragLeave);
                });
                document.getElementById('rankingsList').removeEventListener('click', handleMoveButtonClick);
                document.getElementById('rankingsList').removeEventListener('click', handleNameClick);
            }
        }
        
        function handleNameClick(e) {
            if (!editMode) return;
            const link = e.target.closest('.player-name-link');
            if (!link) return;
            e.preventDefault();
            const row = link.closest('.player-row');
            const nameSpan = row.querySelector('.player-name');
            if (!nameSpan || nameSpan.querySelector('.edit-name-input')) return;
            const index = parseInt(nameSpan.dataset.index, 10);
            const currentName = link.textContent.trim();
            const input = document.createElement('input');
            input.type = 'text';
            input.className = 'edit-name-input';
            input.value = currentName;
            input.dataset.index = index;
            nameSpan.innerHTML = '';
            nameSpan.appendChild(input);
            input.focus();
            input.select();
            function save() {
                if (!document.contains(input)) return;
                const newName = input.value.trim();
                if (newName === '') { input.value = currentName; return; }
                if (newName === currentName) {
                    replaceWithLink(nameSpan, index, currentName);
                    return;
                }
                fetch('', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ action: 'update_name', index: index, name: newName })
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        showSaveIndicator();
                        replaceWithLink(nameSpan, index, newName);
                    }
                });
            }
            input.addEventListener('blur', save, { once: true });
            input.addEventListener('keydown', function(ev) {
                if (ev.key === 'Enter') { ev.preventDefault(); input.blur(); }
                if (ev.key === 'Escape') { replaceWithLink(nameSpan, index, currentName); }
            });
        }
        
        function replaceWithLink(nameSpan, index, name) {
            const a = document.createElement('a');
            a.href = '../view_player.php?name=' + encodeURIComponent(name);
            a.className = 'player-name-link';
            a.textContent = name;
            nameSpan.innerHTML = '';
            nameSpan.appendChild(a);
        }
        
        function handleMoveButtonClick(e) {
            const upBtn = e.target.closest('.move-up');
            const downBtn = e.target.closest('.move-down');
            if (upBtn && !upBtn.disabled) movePlayer(parseInt(upBtn.dataset.index), -1);
            if (downBtn && !downBtn.disabled) movePlayer(parseInt(downBtn.dataset.index), 1);
        }
        
        let draggedIndex = null;
        
        function handleDragStart(e) {
            draggedIndex = parseInt(this.dataset.index);
            this.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', draggedIndex);
        }
        
        function handleDragEnd(e) {
            this.classList.remove('dragging');
            document.querySelectorAll('.player-row').forEach(row => {
                row.classList.remove('drag-over');
            });
        }
        
        function handleDragOver(e) {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            this.classList.add('drag-over');
        }
        
        function handleDragLeave(e) {
            this.classList.remove('drag-over');
        }
        
        function handleDrop(e) {
            e.preventDefault();
            const row = e.currentTarget;
            const toIndex = parseInt(row.dataset.index);
            if (draggedIndex !== null && draggedIndex !== toIndex) {
                movePlayerByIndex(draggedIndex, toIndex);
            }
            row.classList.remove('drag-over');
        }
        
        function movePlayer(fromIndex, direction) {
            movePlayerByIndex(fromIndex, fromIndex + direction);
        }
        
        function movePlayerByIndex(fromIndex, toIndex) {
            if (fromIndex === toIndex) return;
            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    action: 'move',
                    from: fromIndex,
                    to: toIndex
                })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showSaveIndicator();
                    updateDOMAfterMove(fromIndex, toIndex);
                }
            });
        }
        
        function updateDOMAfterMove(fromIndex, toIndex) {
            const list = document.getElementById('rankingsList');
            const rows = Array.from(list.querySelectorAll('.player-row'));
            const movedRow = rows[fromIndex];
            rows.splice(fromIndex, 1);
            rows.splice(toIndex, 0, movedRow);
            list.innerHTML = '';
            rows.forEach(row => list.appendChild(row));
            rows.forEach((row, i) => {
                row.dataset.index = i;
                const nameSpan = row.querySelector('.player-name');
                if (nameSpan) nameSpan.dataset.index = i;
                const rank = i + 1;
                const group = Math.ceil(rank / 4);
                row.querySelector('.rank-badge').textContent = rank;
                row.querySelector('.group-badge').textContent = 'G' + group;
                const band = row.querySelector('.skill-band');
                band.className = 'skill-band g' + group;
                row.dataset.code = getCodeForRank(rank);
                const moveBtns = row.querySelectorAll('.move-btn');
                if (moveBtns[0]) { moveBtns[0].disabled = (i === 0); moveBtns[0].dataset.index = i; }
                if (moveBtns[1]) { moveBtns[1].disabled = (i === rows.length - 1); moveBtns[1].dataset.index = i; }
            });
        }
        
        function showSaveIndicator() {
            const indicator = document.getElementById('saveIndicator');
            indicator.classList.remove('show');
            void indicator.offsetWidth; // Trigger reflow
            indicator.classList.add('show');
        }
        
        function openCodeTierModal() {
            document.getElementById('codeTierModal').classList.add('show');
        }
        
        function closeCodeTierModal() {
            document.getElementById('codeTierModal').classList.remove('show');
        }
        
        function saveCodeTiers() {
            const data = {
                action: 'save_code_tiers',
                codeS_min: parseInt(document.getElementById('codeS_min').value) || 1,
                codeS_max: parseInt(document.getElementById('codeS_max').value) || 24,
                codeA_min: parseInt(document.getElementById('codeA_min').value) || 25,
                codeA_max: parseInt(document.getElementById('codeA_max').value) || 36,
                codeB_min: parseInt(document.getElementById('codeB_min').value) || 37,
                codeB_max: parseInt(document.getElementById('codeB_max').value) || 46
            };
            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(data)
            })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    showSaveIndicator();
                    closeCodeTierModal();
                    location.reload();
                }
            });
        }
        
        document.getElementById('codeTierModal').addEventListener('click', function(e) {
            if (e.target === this) closeCodeTierModal();
        });
    </script>
</body>
</html>
