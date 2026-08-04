<?php
/**
 * FSL Individual Championships — D2 bracket diagram (image only).
 * Renders PNG (default) or SVG at 100/50/25% scale via d2.
 */

ob_start();
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/voting_logic.php';
require_once __DIR__ . '/tournament_logic.php';
require_once __DIR__ . '/tournament_d2.php';

$canEdit = false;
$uid = '';
if (isset($_SESSION['user_id'])) {
    $uid = trim((string) $_SESSION['user_id']);
    if ($uid !== '') {
        $canEdit = rankings_user_has_permission($db, $uid, RANKINGS_PERM_SUPER);
    }
}

$rankingsFile = __DIR__ . '/rankings.json';
$outDir = __DIR__ . '/diagrams';

$format = tournament_d2_normalize_format($_GET['format'] ?? $_POST['format'] ?? 'png');
$scalePct = tournament_d2_normalize_scale_pct($_GET['scale'] ?? $_POST['scale'] ?? 100);
$exportName = tournament_d2_export_basename($format, $scalePct);
$exportFs = $outDir . '/' . $exportName;
$exportWeb = 'diagrams/' . $exportName;

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
$updatedAt = is_file($exportFs) ? date('Y-m-d H:i:s', (int) filemtime($exportFs)) : null;

$viewQs = 'action=view&format=' . rawurlencode($format) . '&scale=' . (int) $scalePct;

$action = trim((string) ($_GET['action'] ?? $_POST['action'] ?? ''));

if ($action === 'svg' || $action === 'render' || $action === 'export') {
    if (!$canEdit) {
        if ($action === 'render') {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Permission denied'], JSON_UNESCAPED_SLASHES);
            exit;
        }
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Permission denied';
        exit;
    }
    // Allow override on render POST/GET
    $format = tournament_d2_normalize_format($_GET['format'] ?? $_POST['format'] ?? $format);
    $scalePct = tournament_d2_normalize_scale_pct($_GET['scale'] ?? $_POST['scale'] ?? $scalePct);
    $exportName = tournament_d2_export_basename($format, $scalePct);
    $exportFs = $outDir . '/' . $exportName;

    $d2 = tournament_bracket_to_d2($bracket, 'FSL Individual Championships');
    $err = [];
    $path = tournament_d2_render($d2, $outDir, ['format' => $format, 'scale' => $scalePct], $err);
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
    $url = '?action=view&format=' . rawurlencode($format) . '&scale=' . (int) $scalePct;
    if ($action === 'render') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => true,
            'url' => $url,
            'format' => $format,
            'scale' => $scalePct,
            'file' => basename($path),
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }
    header('Location: ' . $url, true, 302);
    exit;
}

if ($action === 'view') {
    if (!is_file($exportFs)) {
        if ($canEdit) {
            header(
                'Location: ?action=export&format=' . rawurlencode($format) . '&scale=' . (int) $scalePct,
                true,
                302
            );
            exit;
        }
        http_response_code(404);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"/><title>FSL Bracket</title></head><body style="background:#070b14;color:#edf2ff;font-family:system-ui;padding:48px;text-align:center">'
            . '<p>Bracket diagram not generated yet for this format/size.</p>'
            . '<p style="color:#9aa7bd">Ask an admin to update the diagram.</p>'
            . '</body></html>';
        exit;
    }
    $src = htmlspecialchars($exportWeb . '?v=' . (string) filemtime($exportFs), ENT_QUOTES, 'UTF-8');
    $pct = (int) $scalePct;
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"/>'
        . '<meta name="viewport" content="width=device-width, initial-scale=1"/>'
        . '<title>FSL Bracket</title><style>'
        . 'html,body{margin:0;padding:0;background:#070b14;}'
        . 'body{min-height:100vh;}'
        . '.wrap{padding:12px 0 48px;text-align:center;overflow-x:auto;}'
        . 'img{display:inline-block;width:auto;height:auto;max-width:none;vertical-align:top;}'
        . '.bar{position:sticky;top:0;z-index:2;display:flex;flex-wrap:wrap;gap:8px;justify-content:center;'
        . 'align-items:center;padding:8px 12px;background:rgba(7,11,20,.92);border-bottom:1px solid rgba(255,255,255,.08);'
        . 'font:600 13px/1.2 Inter,ui-sans-serif,system-ui,sans-serif;}'
        . '.bar a,.bar span{color:#c8d0e0;text-decoration:none;padding:6px 10px;border-radius:999px;'
        . 'border:1px solid rgba(255,255,255,.12);}'
        . '.bar a:hover{color:#fff;border-color:rgba(162,155,254,.5);}'
        . '.bar .on{color:#fff;background:rgba(108,92,231,.35);border-color:rgba(162,155,254,.55);}'
        . '</style></head><body><div class="bar">';
    foreach (['png' => 'PNG', 'svg' => 'SVG'] as $f => $label) {
        $cls = $f === $format ? ' class="on"' : '';
        echo '<a' . $cls . ' href="?action=view&amp;format=' . $f . '&amp;scale=' . $pct . '">' . $label . '</a>';
    }
    echo '<span aria-hidden="true">·</span>';
    foreach ([100, 50, 25] as $s) {
        $cls = $s === $pct ? ' class="on"' : '';
        echo '<a' . $cls . ' href="?action=view&amp;format=' . htmlspecialchars($format) . '&amp;scale=' . $s . '">' . $s . '%</a>';
    }
    if ($canEdit) {
        echo '<a href="?action=export&amp;format=' . htmlspecialchars($format) . '&amp;scale=' . $pct . '">Regenerate</a>';
    }
    echo '</div><div class="wrap">'
        . '<img src="' . $src . '" alt="FSL Individual Championships bracket"/>'
        . '</div></body></html>';
    exit;
}

if ($action === 'd2') {
    if (!$canEdit) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Permission denied';
        exit;
    }
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
  .row { display: flex; flex-wrap: wrap; gap: 10px 18px; color: var(--muted); font-size: .92rem; align-items: center; }
  .row strong { color: var(--text); }
  .ok { color: var(--ready); }
  .bad { color: #ff8f9a; }
  .actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 18px; align-items: center; }
  label.opt {
    display: inline-flex; align-items: center; gap: 6px;
    color: var(--muted); font-size: .9rem;
  }
  select {
    background: #0d1320;
    color: var(--text);
    border: 1px solid var(--line);
    border-radius: 8px;
    padding: 8px 10px;
  }
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
      <?php if ($canEdit): ?>
      Renders the live Challenger field to a PNG (default) or SVG at 100% / 50% / 25% scale.
      Regenerating is admin-only (it can take a while).
      <?php else: ?>
      View the latest published bracket diagram. Format/size switches use already-generated images.
      <?php endif; ?>
    </p>

    <div class="card">
      <div class="row">
        <span><strong>Field:</strong> <?= (int) $inCount ?> / <?= (int) $totalCount ?> in</span>
        <?php if ($canEdit): ?>
        <span><strong>d2 CLI:</strong>
          <?php if ($d2Ok): ?>
            <span class="ok">ready</span>
          <?php else: ?>
            <span class="bad">missing</span>
          <?php endif; ?>
        </span>
        <?php endif; ?>
        <span><strong>Last file:</strong> <?= $updatedAt ? htmlspecialchars($exportName . ' · ' . $updatedAt) : 'not generated yet' ?></span>
      </div>

      <div class="actions">
        <label class="opt">Format
          <select id="fmtSelect">
            <option value="png" <?= $format === 'png' ? 'selected' : '' ?>>PNG</option>
            <option value="svg" <?= $format === 'svg' ? 'selected' : '' ?>>SVG</option>
          </select>
        </label>
        <label class="opt">Size
          <select id="scaleSelect">
            <option value="100" <?= $scalePct === 100 ? 'selected' : '' ?>>100%</option>
            <option value="50" <?= $scalePct === 50 ? 'selected' : '' ?>>50%</option>
            <option value="25" <?= $scalePct === 25 ? 'selected' : '' ?>>25%</option>
          </select>
        </label>
        <?php if ($canEdit): ?>
        <button type="button" class="btn" id="openBtn" <?= $d2Ok ? '' : 'disabled' ?>>
          Update &amp; open
        </button>
        <?php endif; ?>
        <a class="btn secondary" href="tournament.php">Live bracket UI</a>
        <a class="btn secondary" id="openLatest" href="?<?= htmlspecialchars($viewQs) ?>" target="_blank" rel="noopener noreferrer">Open latest</a>
        <?php if ($canEdit): ?>
        <a class="btn secondary" href="?action=d2" target="_blank" rel="noopener noreferrer">View .d2 source</a>
        <?php endif; ?>
      </div>
      <div class="err" id="errBox"></div>
      <p class="note">
        Share link (PNG @ 50%):
        <a class="link" href="?action=view&amp;format=png&amp;scale=50" target="_blank" rel="noopener noreferrer">?action=view</a>
      </p>
    </div>
  </div>

<script>
(function () {
  const btn = document.getElementById('openBtn');
  const err = document.getElementById('errBox');
  const fmt = document.getElementById('fmtSelect');
  const scale = document.getElementById('scaleSelect');
  const latest = document.getElementById('openLatest');

  function qs() {
    return 'format=' + encodeURIComponent(fmt.value) + '&scale=' + encodeURIComponent(scale.value);
  }
  function syncLatest() {
    if (latest) latest.href = '?action=view&' + qs();
  }
  if (fmt) fmt.addEventListener('change', syncLatest);
  if (scale) scale.addEventListener('change', syncLatest);
  syncLatest();

  if (!btn) return;
  btn.addEventListener('click', async function () {
    err.style.display = 'none';
    err.textContent = '';
    btn.disabled = true;
    const label = btn.textContent;
    btn.textContent = 'Rendering…';
    try {
      const res = await fetch('?action=render&' + qs(), { credentials: 'same-origin' });
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
