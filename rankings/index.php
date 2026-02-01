<?php
/**
 * Player Rankings Page
 * Display and manage player power rankings
 */

session_start();
require_once __DIR__ . '/../includes/db.php';

$rankingsFile = __DIR__ . '/rankings.json';

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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {
    header('Content-Type: application/json');
    
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
    
    if ($action === 'move') {
        $fromIndex = $input['from'] ?? -1;
        $toIndex = $input['to'] ?? -1;
        
        $rankings = json_decode(file_get_contents($rankingsFile), true);
        
        if ($fromIndex >= 0 && $fromIndex < count($rankings) && 
            $toIndex >= 0 && $toIndex < count($rankings)) {
            
            // Remove player from old position
            $player = array_splice($rankings, $fromIndex, 1)[0];
            // Insert at new position
            array_splice($rankings, $toIndex, 0, [$player]);
            
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
    
    echo json_encode(['success' => false, 'error' => 'Unknown action']);
    exit;
}

// Load rankings
$rankings = [];
if (file_exists($rankingsFile)) {
    $rankings = json_decode(file_get_contents($rankingsFile), true) ?? [];
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
        </div>
        
        <?php if ($canEdit): ?>
        <div class="edit-mode-toggle">
            <button class="edit-btn" id="editModeBtn" onclick="toggleEditMode()">
                <i class="fas fa-edit"></i> Enable Edit Mode
            </button>
        </div>
        <?php endif; ?>
        
        <div class="rankings-list" id="rankingsList">
            <?php 
            foreach ($rankings as $index => $player): 
                $group = ceil($player['rank'] / 4);
            ?>
                <div class="player-row" data-index="<?= $index ?>" draggable="false">
                    <div class="skill-band g<?= $group ?>"></div>
                    <?php if ($canEdit): ?>
                    <span class="drag-handle edit-only" style="display: none;"><i class="fas fa-grip-vertical"></i></span>
                    <?php endif; ?>
                    <span class="rank-badge"><?= $player['rank'] ?></span>
                    <img src="../images/<?= $raceIcons[$player['race']] ?? 'random_icon.png' ?>" alt="<?= $player['race'] ?>" class="race-icon">
                    <span class="player-name">
                        <a href="../view_player.php?name=<?= urlencode($player['name']) ?>"><?= htmlspecialchars($player['name']) ?></a>
                    </span>
                    <span class="group-badge">G<?= $group ?></span>
                    <?php if ($canEdit): ?>
                    <div class="move-buttons edit-only" style="display: none;">
                        <button class="move-btn" onclick="movePlayer(<?= $index ?>, -1)" <?= $index === 0 ? 'disabled' : '' ?>>
                            <i class="fas fa-chevron-up"></i>
                        </button>
                        <button class="move-btn" onclick="movePlayer(<?= $index ?>, 1)" <?= $index === count($rankings) - 1 ? 'disabled' : '' ?>>
                            <i class="fas fa-chevron-down"></i>
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    
    <div class="save-indicator" id="saveIndicator">
        <i class="fas fa-check"></i> Saved
    </div>
    
    <script>
        let editMode = false;
        
        function toggleEditMode() {
            editMode = !editMode;
            const btn = document.getElementById('editModeBtn');
            const editElements = document.querySelectorAll('.edit-only');
            const rows = document.querySelectorAll('.player-row');
            
            if (editMode) {
                btn.innerHTML = '<i class="fas fa-eye"></i> View Mode';
                btn.style.background = 'linear-gradient(135deg, #00b894, #55efc4)';
                editElements.forEach(el => el.style.display = 'flex');
                rows.forEach(row => {
                    row.draggable = true;
                    row.addEventListener('dragstart', handleDragStart);
                    row.addEventListener('dragend', handleDragEnd);
                    row.addEventListener('dragover', handleDragOver);
                    row.addEventListener('drop', handleDrop);
                    row.addEventListener('dragleave', handleDragLeave);
                });
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
            }
        }
        
        let draggedIndex = null;
        
        function handleDragStart(e) {
            draggedIndex = parseInt(this.dataset.index);
            this.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
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
            const toIndex = parseInt(this.dataset.index);
            if (draggedIndex !== null && draggedIndex !== toIndex) {
                movePlayer(draggedIndex, toIndex - draggedIndex);
            }
            this.classList.remove('drag-over');
        }
        
        function movePlayer(fromIndex, direction) {
            const toIndex = fromIndex + direction;
            
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
                    location.reload();
                }
            });
        }
        
        function showSaveIndicator() {
            const indicator = document.getElementById('saveIndicator');
            indicator.classList.remove('show');
            void indicator.offsetWidth; // Trigger reflow
            indicator.classList.add('show');
        }
    </script>
</body>
</html>
