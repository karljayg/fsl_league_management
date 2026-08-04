<?php
/**
 * FSL Individual Championships — D2 bracket diagram (image only).
 * Reads live tournament data, renders SVG via d2, opens in a new tab.
 */

ob_start();
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/voting_logic.php';
require_once __DIR__ . '/tournament_logic.php';
require_once __DIR__ . '/tournament_d2.php';

$rankingsFile = __DIR__ . '/rankings.json';
$outDir = __DIR__ . '/diagrams';
$svgName = 'challenger_bracket.svg';
$svgFs = $outDir . '/' . $svgName;
$svgWeb = 'diagrams/' . $svgName;

$rankings = [];
if (is_readable($rankingsFile)) {
    $decoded = json_decode((string) file_get_contents($rankingsFile), true);
    if (is_array($decoded)) {
        $rankings = $decoded;
    }
}
foreach ($rankings as $i => $p) {
    $rank = (int) ($p['rank'] ?? $i + 1);
    $rankings[$i]['rank'] = $rank;
    $rankings[$i]['group'] = isset($p['group']) ? (int) $p['group'] : (int) ceil($rank / 4);
    $rankings[$i]['name'] = trim((string) ($p['name'] ?? ''));
}

$codeTiers = tournament_default_code_tiers(count($rankings));
$configFile = __DIR__ . '/rankings_config.json';
if (is_readable($configFile)) {
    $loaded = json_decode((string) file_get_contents($configFile), true);
    if (is_array($loaded)) {
        foreach (['codeS', 'codeA', 'codeB'] as $tier) {
            if (isset($loaded[$tier]['minRank'], $loaded[$tier]['maxRank'])) {
                $codeTiers[$tier] = [
                    'minRank' => (int) $loaded[$tier]['minRank'],
                    'maxRank' => (int) $loaded[$tier]['maxRank'],
                ];
            }
        }
    }
}

$state = tournament_load_state();
$bracket = tournament_build_bracket($rankings, $state, $codeTiers);
$d2Ok = tournament_d2_binary() !== '';
$inCount = (int) ($bracket['participant_count'] ?? 0);
$totalCount = count($rankings);
$updatedAt = is_file($svgFs) ? date('Y-m-d H:i:s', (int) filemtime($svgFs)) : null;

$action = trim((string) ($_GET['action'] ?? $_POST['action'] ?? ''));

if ($action === 'svg' || $action === 'render') {
    $d2 = tournament_bracket_to_d2($bracket, 'FSL Individual Championships');
    $err = [];
    $path = tournament_d2_render_svg($d2, $svgFs, $err);
    if ($path === null) {
        $msg = $err['error'] ?? 'Render failed';
        if ($action === 'render') {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_SLASHES);
            exit;
        }
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo $msg;
        exit;
    }
    // Stable share URL (cache-bust lives on the SVG src inside the viewer)
    $url = '?action=view';
    if ($action === 'render') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'url' => $url], JSON_UNESCAPED_SLASHES);
        exit;
    }
    header('Location: ' . $url, true, 302);
    exit;
}

if ($action === 'view') {
    if (!is_file($svgFs)) {
        header('Location: ?action=svg', true, 302);
        exit;
    }
    // Keep share link stable; bust browser cache on the image via mtime.
    $src = htmlspecialchars($svgWeb . '?v=' . (string) filemtime($svgFs), ENT_QUOTES, 'UTF-8');
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"/>'
        . '<meta name="viewport" content="width=device-width, initial-scale=1"/>'
        . '<title>FSL Bracket</title><style>'
        . 'html,body{margin:0;padding:0;background:#070b14;}'
        . 'body{overflow-x:hidden;}'
        . 'img{display:block;width:100vw;height:auto;max-width:none;}'
        . '</style></head><body>'
        . '<img src="' . $src . '" alt="FSL Individual Championships bracket" width="100%"/>'
        . '</body></html>';
    exit;
}

if ($action === 'd2') {
    header('Content-Type: text/plain; charset=utf-8');
    echo tournament_bracket_to_d2($bracket, 'FSL Individual Championships');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1"/>
<title>FSL Bracket Diagram (D2)</title>
<style>
  :root {
    --bg: #070b14;
    --panel: #111827;
    --text: #edf2ff;
    --muted: #9aa7bd;
    --line: rgba(255,255,255,.12);
    --accent: #a29bfe;
    --ready: #00d4aa;
  }
  * { box-sizing: border-box; }
  body {
    margin: 0;
    font-family: Inter, ui-sans-serif, system-ui, -apple-system, sans-serif;
    color: var(--text);
    background:
      radial-gradient(circle at 10% 0%, rgba(108,92,231,.18), transparent 35%),
      radial-gradient(circle at 90% 10%, rgba(0,212,170,.08), transparent 30%),
      var(--bg);
    min-height: 100vh;
  }
  .wrap { width: min(720px, calc(100% - 32px)); margin: 0 auto; padding: 48px 0 64px; }
  h1 { margin: 0 0 8px; font-size: 1.85rem; letter-spacing: -.03em; }
  .sub { color: var(--muted); margin: 0 0 28px; line-height: 1.5; }
  .card {
    background: rgba(255,255,255,.03);
    border: 1px solid var(--line);
    border-radius: 16px;
    padding: 20px;
    margin-bottom: 16px;
  }
  .row { display: flex; flex-wrap: wrap; gap: 10px 18px; color: var(--muted); font-size: .92rem; }
  .row strong { color: var(--text); }
  .ok { color: var(--ready); }
  .bad { color: #ff8f9a; }
  .actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 18px; }
  .btn {
    display: inline-flex; align-items: center; gap: 8px;
    border: 1px solid rgba(162,155,254,.45);
    background: linear-gradient(135deg, rgba(108,92,231,.35), rgba(0,212,170,.18));
    color: #fff;
    font-weight: 700;
    font-size: 1rem;
    padding: 12px 18px;
    border-radius: 999px;
    cursor: pointer;
    text-decoration: none;
  }
  .btn:hover { filter: brightness(1.08); }
  .btn.secondary {
    background: transparent;
    border-color: var(--line);
    font-weight: 600;
    color: var(--muted);
  }
  .btn:disabled { opacity: .5; cursor: wait; }
  .note { color: var(--muted); font-size: .85rem; margin-top: 14px; line-height: 1.45; }
  .err {
    display: none;
    margin-top: 12px;
    padding: 10px 12px;
    border-radius: 10px;
    border: 1px solid rgba(255,143,154,.4);
    background: rgba(255,118,117,.1);
    color: #ffc9ce;
    white-space: pre-wrap;
    font-size: .85rem;
  }
  a.link { color: var(--accent); }
</style>
</head>
<body>
  <div class="wrap">
    <h1>Bracket diagram</h1>
    <p class="sub">
      Reads the live Challenger / Individual Championships field and scores, renders a D2 SVG,
      then opens the image in a new tab. Not an interactive UI — just the picture.
    </p>

    <div class="card">
      <div class="row">
        <span><strong>Field:</strong> <?= (int) $inCount ?> / <?= (int) $totalCount ?> in</span>
        <span><strong>d2 CLI:</strong>
          <?php if ($d2Ok): ?>
            <span class="ok">ready</span>
          <?php else: ?>
            <span class="bad">missing</span> (install with <code>brew install d2</code>)
          <?php endif; ?>
        </span>
        <span><strong>Last SVG:</strong> <?= $updatedAt ? htmlspecialchars($updatedAt) : 'not generated yet' ?></span>
      </div>

      <div class="actions">
        <button type="button" class="btn" id="openBtn" <?= $d2Ok ? '' : 'disabled' ?>>
          Update &amp; open bracket image
        </button>
        <a class="btn secondary" href="tournament.php">Live bracket UI</a>
        <?php if (is_file($svgFs)): ?>
        <a class="btn secondary" href="?action=view" target="_blank" rel="noopener noreferrer">Open latest diagram</a>
        <?php endif; ?>
        <a class="btn secondary" href="?action=d2" target="_blank" rel="noopener noreferrer">View .d2 source</a>
      </div>
      <div class="err" id="errBox"></div>
      <p class="note">
        Opens a full-window viewer (SVG scaled to the browser) after a fresh render from
        <code>rankings.json</code> + <code>tournament_state.json</code>.
        Only Code B / A / S (overall) champs are labeled.
        Stable share link (always latest):
        <a class="link" href="?action=view" target="_blank" rel="noopener noreferrer">?action=view</a>
        · regenerate:
        <a class="link" href="?action=svg" target="_blank" rel="noopener noreferrer">?action=svg</a>
      </p>
    </div>
  </div>

<script>
(function () {
  const btn = document.getElementById('openBtn');
  const err = document.getElementById('errBox');
  if (!btn) return;
  btn.addEventListener('click', async function () {
    err.style.display = 'none';
    err.textContent = '';
    btn.disabled = true;
    const label = btn.textContent;
    btn.textContent = 'Rendering…';
    try {
      const res = await fetch('?action=render', { credentials: 'same-origin' });
      const text = await res.text();
      let data;
      try { data = JSON.parse(text); } catch (e) {
        throw new Error(text || ('HTTP ' + res.status));
      }
      if (!res.ok || !data.ok || !data.url) {
        throw new Error((data && data.error) || text || ('HTTP ' + res.status));
      }
      window.open(data.url, '_blank', 'noopener,noreferrer');
    } catch (e) {
      err.textContent = (e && e.message) ? e.message : String(e);
      err.style.display = 'block';
    } finally {
      btn.disabled = false;
      btn.textContent = label;
    }
  });
})();
</script>
</body>
</html>
