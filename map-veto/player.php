<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/map_veto.php';

$token = $_GET['t'] ?? '';
$hit = $token !== '' ? map_veto_find_by_token($token) : null;

if ($hit === null || (($hit['role'] ?? '') !== 'player_a' && ($hit['role'] ?? '') !== 'player_b')) {
    http_response_code(403);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Map veto — Access</title>
        <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
        <style>
            body { font-family: 'Inter', sans-serif; background: linear-gradient(135deg, #0f0c29, #302b63, #24243e); color: #e0e0e0; min-height: 100vh; }
            h1 { color: #00d4ff; text-shadow: 0 0 15px rgba(0, 212, 255, 0.45); font-size: 1.5rem; }
        </style>
    </head>
    <body>
    <div class="container py-5" style="max-width: 520px;">
        <h1 class="mb-3">Invalid or missing player link</h1>
        <p class="text-muted mb-0">Use the URL provided by the admin (includes your private token).</p>
    </div>
    </body>
    </html>
    <?php
    exit;
}

$role = (string) $hit['role'];
$session = map_veto_refresh_session((string) ($hit['session']['id'] ?? '')) ?? $hit['session'];
$mySide = $role === 'player_b' ? 'b' : 'a';
$labelSelf = map_veto_player_display_side($session, $mySide);
$labelOpp = map_veto_player_display_side($session, map_veto_opposite_side($mySide));
$seatLabel = $mySide === 'b' ? 'Player B' : 'Player A';
$oppSeatLabel = $mySide === 'b' ? 'Player A' : 'Player B';
$pageTitle = 'Map veto — ' . htmlspecialchars($labelSelf, ENT_QUOTES, 'UTF-8') . ' (' . $seatLabel . ')';
$mvMatchTitle = trim((string) ($session['match_title'] ?? ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?></title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Side A = warm gold, side B = cool cyan — instant “which link is this?” */
        body.mv-player--side-a {
            --mv-you-accent: #f4b942;
            --mv-you-accent-glow: rgba(244, 185, 66, 0.45);
            --mv-you-surface: rgba(244, 185, 66, 0.14);
            --mv-you-border: rgba(244, 185, 66, 0.85);
            --mv-you-ribbon-bg: linear-gradient(90deg, rgba(244, 185, 66, 0.22) 0%, rgba(20, 18, 40, 0.92) 55%);
        }
        body.mv-player--side-b {
            --mv-you-accent: #5ce1ff;
            --mv-you-accent-glow: rgba(92, 225, 255, 0.45);
            --mv-you-surface: rgba(92, 225, 255, 0.12);
            --mv-you-border: rgba(92, 225, 255, 0.85);
            --mv-you-ribbon-bg: linear-gradient(90deg, rgba(92, 225, 255, 0.2) 0%, rgba(20, 18, 40, 0.92) 55%);
        }
        body.mv-player { font-family: 'Inter', sans-serif; background: linear-gradient(135deg, #0f0c29, #302b63, #24243e); color: #e0e0e0; min-height: 100vh; margin: 0; line-height: 1.4; }
        .mv-you-ribbon {
            border-radius: 10px;
            padding: 0.65rem 0.9rem;
            margin-bottom: 0.85rem;
            border: 2px solid var(--mv-you-border);
            background: var(--mv-you-ribbon-bg);
            box-shadow: 0 0 24px var(--mv-you-accent-glow);
        }
        .mv-you-ribbon-top { display: flex; align-items: center; flex-wrap: wrap; gap: 0.35rem 0.65rem; }
        .mv-you-pill {
            display: inline-block;
            font-size: 0.62rem;
            font-weight: 900;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            padding: 0.2rem 0.45rem;
            border-radius: 4px;
            background: var(--mv-you-accent);
            color: #1a1428;
        }
        .mv-you-name { font-size: clamp(1.05rem, 4vw, 1.35rem); font-weight: 800; color: #fff; }
        .mv-you-seat { font-size: 0.78rem; font-weight: 700; color: var(--mv-you-accent); letter-spacing: 0.04em; }
        .mv-you-vs { font-size: 0.82rem; color: #aeb8c4; margin-top: 0.35rem; }
        .mv-you-vs strong { color: #e6edf5; font-weight: 700; }
        .mv-wrap { max-width: 920px; margin: 0 auto; padding: 1rem 1rem 2rem; }
        .mv-title { color: var(--mv-you-accent); text-shadow: 0 0 18px var(--mv-you-accent-glow); font-size: 1.35rem; font-weight: 700; margin-bottom: 0.15rem; }
        .mv-title-sub { font-size: 0.88rem; color: #b8c4d0; font-weight: 600; }
        .mv-panel { background: rgba(255, 255, 255, 0.1); border-radius: 10px; padding: 1rem 1.25rem; margin-bottom: 1rem; box-shadow: 0 5px 20px rgba(0, 0, 0, 0.4); }
        .mv-head { display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 0.75rem; }
        .mv-map-card { cursor: pointer; transition: transform 0.08s ease, box-shadow 0.08s ease; border-radius: 8px; overflow: hidden;
            background: rgba(255, 255, 255, 0.08); border: 1px solid rgba(255, 255, 255, 0.12); }
        .mv-map-card.disabled { opacity: 0.55; }
        .mv-map-card:not(.disabled):hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(0, 0, 0, 0.35); }
        .mv-map-card.disabled:hover { transform: none; box-shadow: none; }
        .mv-map-thumb { height: 120px; overflow: hidden; background: rgba(0,0,0,0.35); border-bottom: 1px solid rgba(255,255,255,0.08); }
        .mv-map-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .mv-card-body { padding: 0.5rem 0.65rem; }
        .mv-hist-line { font-size: 0.9rem; border-bottom: 1px solid rgba(255, 255, 255, 0.08); padding: 0.35rem 0; }
        .mv-timer { font-variant-numeric: tabular-nums; letter-spacing: 0.06em; color: #00d4ff; }
        #mapDetailBackdrop.mv-overlay-backdrop { z-index: 1050; }
        #confirmBackdrop.mv-overlay-backdrop { z-index: 1060; }
        .mv-overlay-backdrop { position: fixed; inset: 0; background: rgba(10,10,22,0.82); z-index: 1040; display: none; align-items: center; justify-content: center; padding: 1rem; overflow-y: auto; }
        .mv-overlay-backdrop.show { display: flex; }
        .mv-overlay-box { background: #1a1a2e; border: 1px solid rgba(0,212,255,0.35); border-radius: 10px; padding: 1.25rem; max-width: 420px; width: 100%; box-shadow: 0 10px 40px rgba(0,0,0,0.5); color: #e8eaed; position: relative; }
        .mv-overlay-box.mv-overlay-box--map-detail { max-width: min(560px, 96vw); max-height: calc(100vh - 2rem); overflow-y: auto; }
        .mv-detail-close { position: absolute; top: 0.65rem; right: 0.65rem; background: transparent; border: none; color: #aeb8c4; font-size: 1.5rem; line-height: 1; padding: 0.15rem 0.45rem; cursor: pointer; border-radius: 6px; }
        .mv-detail-close:hover { color: #fff; background: rgba(255,255,255,0.08); }
        .mv-detail-img-wrap { margin: 0.5rem 0 1rem; border-radius: 10px; overflow: hidden; background: rgba(0,0,0,0.4); text-align: center; }
        .mv-detail-img-wrap img { width: 100%; max-height: min(52vh, 420px); object-fit: contain; display: block; margin: 0 auto; }
        .map-detail-title { color: var(--mv-you-accent, #00d4ff); font-size: 1.2rem; font-weight: 700; margin-right: 2rem; word-break: break-word; }
        .map-detail-id { font-size: 0.75rem; color: #8899ab; font-family: ui-monospace, monospace; margin-top: 0.25rem; }
        h2.mv-h2 { color: #00d4ff; font-size: 1rem; margin-bottom: 0.5rem; }
        .mv-match-title-line { font-size: 0.95rem; color: #c8d0da; margin-top: 0.25rem; max-width: 42rem; }
        .mv-phase-hero { text-align: center; padding: 0.85rem 1rem 1rem; border-radius: 12px; border: 2px solid rgba(255, 255, 255, 0.18); background: rgba(0, 0, 0, 0.28); margin-bottom: 1rem; }
        .mv-phase-hero.mv-phase--idle { border-color: rgba(255, 255, 255, 0.18); }
        .mv-phase-hero.mv-phase--veto { border-color: rgba(255, 193, 7, 0.65); background: rgba(255, 193, 7, 0.07); }
        .mv-phase-hero.mv-phase--order { border-color: rgba(0, 212, 255, 0.65); background: rgba(0, 212, 255, 0.08); }
        .mv-phase-main { font-size: 1.35rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: #fff; line-height: 1.2; }
        .mv-phase-hero.mv-phase--veto .mv-phase-main { color: #ffc857; text-shadow: 0 0 14px rgba(255, 200, 87, 0.35); }
        .mv-phase-hero.mv-phase--order .mv-phase-main { color: #7fdfff; text-shadow: 0 0 14px rgba(0, 212, 255, 0.35); }
        .mv-phase-sub { font-size: 1rem; margin-top: 0.35rem; color: #aeb8c4 !important; font-weight: 600; }
        .mv-players-row { gap: 0.75rem 0; }
        .mv-player-pill { padding: 0.65rem 0.6rem; border-radius: 10px; background: rgba(255, 255, 255, 0.05); border: 2px solid rgba(255, 255, 255, 0.12); text-align: center; min-height: 4.25rem; display: flex; flex-direction: column; justify-content: center; transition: border-color 0.15s ease, box-shadow 0.15s ease, background 0.15s ease; opacity: 0.92; }
        .mv-player-pill--opp { opacity: 0.72; }
        .mv-player-pill--active:not(.mv-player-pill--me) { border-color: rgba(180, 195, 210, 0.55) !important; box-shadow: 0 0 14px rgba(180, 195, 210, 0.2); background: rgba(255, 255, 255, 0.07); }
        .mv-player-pill--active.mv-player-pill--me { border-color: var(--mv-you-border) !important; box-shadow: 0 0 22px var(--mv-you-accent-glow); background: var(--mv-you-surface); opacity: 1; }
        .mv-player-pill--me { border-width: 3px; border-color: var(--mv-you-border); background: var(--mv-you-surface); opacity: 1; }
        .mv-player-pill--me .mv-player-pill-name { color: #fff; }
        .mv-player-pill--me .mv-player-pill-cap { color: var(--mv-you-accent); font-weight: 800; }
        .mv-player-pill-name { font-weight: 700; font-size: 1.05rem; line-height: 1.25; word-break: break-word; }
        .mv-player-pill-cap { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.06em; color: #9aa7b5; margin-top: 0.35rem; }
        .mv-players-row--me-b { flex-direction: row-reverse; }
        .mv-map-card.mv-map-card--acting-turn { box-shadow: 0 0 0 3px var(--mv-you-border); }
    </style>
</head>
<body class="mv-player mv-player--side-<?= $mySide === 'b' ? 'b' : 'a' ?>">
<div class="mv-wrap">
    <div class="mv-you-ribbon" role="region" aria-label="Your player link">
        <div class="mv-you-ribbon-top">
            <span class="mv-you-pill">You</span>
            <span class="mv-you-name"><?= htmlspecialchars($labelSelf, ENT_QUOTES, 'UTF-8') ?></span>
            <span class="mv-you-seat">· <?= htmlspecialchars($seatLabel, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <div class="mv-you-vs mb-0">Opponent (<strong><?= htmlspecialchars($oppSeatLabel, ENT_QUOTES, 'UTF-8') ?></strong>): <strong><?= htmlspecialchars($labelOpp, ENT_QUOTES, 'UTF-8') ?></strong></div>
    </div>

    <div class="mv-panel mv-head">
        <div>
            <h1 class="mv-title mb-0">Map veto</h1>
            <div class="mv-title-sub mt-1">Use only this link for <strong><?= htmlspecialchars($seatLabel, ENT_QUOTES, 'UTF-8') ?></strong> — the other player has a different URL.</div>
            <?php if ($mvMatchTitle !== ''): ?>
                <div class="mv-match-title-line"><?= htmlspecialchars($mvMatchTitle, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <div class="small text-muted mt-2 mb-0"><?= htmlspecialchars((string) ($session['season_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?> · Best of <?= (int) ($session['best_of'] ?? 1) ?></div>
        </div>
        <div class="text-md-right">
            <span id="phaseBadge" class="badge badge-secondary" style="font-size:0.95rem;">…</span>
            <div id="timerRow" class="small text-muted mt-2 d-none">Timer <span id="timerVal" class="mv-timer">—</span></div>
        </div>
    </div>

    <div id="phaseHero" class="mv-phase-hero mv-phase--veto">
        <div id="phaseMainLabel" class="mv-phase-main">…</div>
        <div id="phaseSubLabel" class="mv-phase-sub"></div>
    </div>

    <div class="row mv-players-row mx-0 <?= $mySide === 'b' ? 'mv-players-row--me-b' : '' ?>">
        <div class="col-6 pl-0 pr-2">
            <div id="pillA" class="mv-player-pill">
                <div id="pillNameA" class="mv-player-pill-name"></div>
                <div id="pillCapA" class="mv-player-pill-cap"></div>
            </div>
        </div>
        <div class="col-6 pr-0 pl-2">
            <div id="pillB" class="mv-player-pill">
                <div id="pillNameB" class="mv-player-pill-name"></div>
                <div id="pillCapB" class="mv-player-pill-cap"></div>
            </div>
        </div>
    </div>

    <div id="banner" class="alert alert-info d-none py-2" role="alert"></div>

    <div id="turnBannerYour" class="alert alert-success py-3 mb-2 d-none" role="status">
        <div class="font-weight-bold mb-1">Your turn</div>
        <div id="turnBannerYourDetail" class="small mb-0"></div>
    </div>
    <div id="turnBannerWait" class="alert alert-secondary py-3 mb-3 d-none" role="status">
        <div class="font-weight-bold mb-1">Waiting</div>
        <div id="turnBannerWaitDetail" class="small mb-0"></div>
    </div>

    <div id="mapsRow" class="row"></div>

    <div class="mv-panel mt-3">
        <h2 class="mv-h2">History</h2>
        <div id="history"></div>
    </div>
</div>

<div id="confirmBackdrop" class="mv-overlay-backdrop" role="dialog" aria-modal="true">
    <div class="mv-overlay-box">
        <p id="confirmText" class="mb-3"></p>
        <div class="d-flex justify-content-end" style="gap:0.5rem;">
            <button type="button" class="btn btn-outline-secondary btn-sm" id="confirmCancel">Cancel</button>
            <button type="button" class="btn btn-primary btn-sm" id="confirmBtn">Confirm</button>
        </div>
    </div>
</div>

<div id="mapDetailBackdrop" class="mv-overlay-backdrop" role="dialog" aria-modal="true" aria-labelledby="mapDetailTitle">
    <div class="mv-overlay-box mv-overlay-box--map-detail">
        <button type="button" class="mv-detail-close" id="mapDetailCloseX" aria-label="Close">&times;</button>
        <h2 id="mapDetailTitle" class="map-detail-title mb-0">Map</h2>
        <div id="mapDetailId" class="map-detail-id"></div>
        <div id="mapDetailImgWrap" class="mv-detail-img-wrap"></div>
        <div id="mapDetailDesc" class="small text-muted mb-2" style="white-space: pre-wrap;"></div>
        <div id="mapDetailMeta" class="small mb-3" style="color:#cfd8e3;"></div>
        <div id="mapDetailActions" class="d-flex flex-wrap justify-content-end" style="gap:0.5rem;"></div>
    </div>
</div>

<script>
(function () {
    const token = <?= json_encode($token) ?>;
    const MY_SEAT = <?= json_encode($seatLabel) ?>;
    const OPP_SEAT = <?= json_encode($oppSeatLabel) ?>;
    const apiState = 'api/state.php?t=' + encodeURIComponent(token);
    const apiAction = 'api/action.php?t=' + encodeURIComponent(token);

    let pendingMapId = null;
    window.__lastState = null;

    const backdrop = document.getElementById('confirmBackdrop');
    const confirmBtn = document.getElementById('confirmBtn');
    const cancelBtn = document.getElementById('confirmCancel');

    function esc(s) {
        const d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    function fmtRemaining(iso) {
        if (!iso) return '—';
        const end = Date.parse(iso);
        if (Number.isNaN(end)) return '—';
        const ms = end - Date.now();
        const sec = Math.max(0, Math.floor(ms / 1000));
        const m = Math.floor(sec / 60);
        const r = sec % 60;
        return m + ':' + String(r).padStart(2, '0');
    }

    function fmtSecs(sec) {
        const n = Math.max(0, parseInt(sec, 10) || 0);
        const m = Math.floor(n / 60);
        const r = n % 60;
        return m + ':' + String(r).padStart(2, '0');
    }

    function timerDisplay(state) {
        if (state.paused) return 'Paused · ' + fmtSecs(state.pause_remaining_seconds || 0);
        return fmtRemaining(state.turn_expires_at);
    }

    function showConfirm(show) {
        backdrop.classList.toggle('show', !!show);
    }

    confirmBtn.addEventListener('click', async function () {
        if (!pendingMapId) return;
        try {
            const r = await fetch(apiAction, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({map_id: pendingMapId})
            });
            const j = await r.json();
            pendingMapId = null;
            showConfirm(false);
            if (j.ok) {
                render(j.state);
            } else {
                alert(j.error || 'Action failed');
                poll();
            }
        } catch (e) {
            alert('Network error');
            poll();
        }
    });
    cancelBtn.addEventListener('click', function () { pendingMapId = null; showConfirm(false); });

    const mapDetailBackdrop = document.getElementById('mapDetailBackdrop');

    function showMapDetailOpen(show) {
        if (!mapDetailBackdrop) return;
        mapDetailBackdrop.classList.toggle('show', !!show);
    }

    if (mapDetailBackdrop) {
        mapDetailBackdrop.addEventListener('click', function (ev) {
            if (ev.target === mapDetailBackdrop) showMapDetailOpen(false);
        });
    }
    var mapDetailCloseX = document.getElementById('mapDetailCloseX');
    if (mapDetailCloseX) mapDetailCloseX.addEventListener('click', function () { showMapDetailOpen(false); });

    document.addEventListener('keydown', function (ev) {
        if (ev.key !== 'Escape') return;
        if (mapDetailBackdrop && mapDetailBackdrop.classList.contains('show')) showMapDetailOpen(false);
    });

    function openPlayerMapModal(m, state, status, paName, pbName) {
        document.getElementById('mapDetailTitle').textContent = m.name || m.map_id || 'Map';
        document.getElementById('mapDetailId').textContent = m.map_id ? ('ID ' + m.map_id) : '';

        var wrap = document.getElementById('mapDetailImgWrap');
        wrap.innerHTML = '';
        if (m.image_url) {
            var im = document.createElement('img');
            im.src = m.image_url;
            im.alt = m.name || '';
            wrap.appendChild(im);
        } else {
            wrap.innerHTML = '<div class="text-muted small py-4 px-2">No image</div>';
        }

        var descRaw = (m.description != null && String(m.description).trim() !== '') ? String(m.description).trim() : '';
        var descEl = document.getElementById('mapDetailDesc');
        descEl.textContent = descRaw;
        descEl.classList.toggle('d-none', !descRaw);

        var meta = document.getElementById('mapDetailMeta');
        meta.innerHTML = '';
        var lines = [];
        var st = m.state || '';
        if (st === 'vetoed') {
            lines.push('Status: Removed from pool (vetoed)');
            if (m.vetoed_by === 'a' || m.vetoed_by === 'b') {
                lines.push('Vetoed by: ' + (m.vetoed_by === 'a' ? paName : pbName));
            }
        } else if (st === 'assigned') {
            lines.push('Status: Scheduled for Game ' + (m.game_number || '?') + ' of the series');
        } else if (st === 'available') {
            if (status === 'live_veto') lines.push('Status: In pool — can be vetoed');
            else if (status === 'live_order') lines.push('Status: In pool — can be assigned to a game');
            else lines.push('Status: In pool');
        } else if (st) {
            lines.push('Status: ' + st);
        }
        lines.forEach(function (line) {
            var row = document.createElement('div');
            row.className = 'mb-1';
            row.textContent = line;
            meta.appendChild(row);
        });

        var act = document.getElementById('mapDetailActions');
        act.innerHTML = '';

        var canAct = !!(state.is_my_turn && !state.paused && st === 'available' && (status === 'live_veto' || status === 'live_order'));
        if (canAct) {
            var go = document.createElement('button');
            go.type = 'button';
            go.className = 'btn btn-primary btn-sm';
            go.textContent = status === 'live_veto' ? 'Continue to veto…' : 'Continue to assign…';
            go.addEventListener('click', function () {
                showMapDetailOpen(false);
                pendingMapId = m.map_id;
                var phaseVerb = (status === 'live_veto')
                    ? ('VETO this map (veto ' + (state.veto_progress ? state.veto_progress.current : '?') + ' of ' + (state.veto_progress ? state.veto_progress.total : '?') + ')')
                    : ('Assign to Game ' + (state.game_number || 1) + ' (map ' + (state.order_progress ? state.order_progress.current_game : (state.game_number || 1)) + ' of ' + (state.order_progress ? state.order_progress.total_games : state.maps_to_play) + ')');
                document.getElementById('confirmText').innerHTML =
                    '<span class="d-block mb-2 small text-uppercase" style="letter-spacing:.06em;color:#9fe8ff;">' + esc(status === 'live_veto' ? 'Veto phase' : 'Map order') + '</span>' +
                    'You are about to <strong>' + esc(status === 'live_veto' ? 'veto (remove)' : ('pick for Game ' + (state.game_number || 1))) + '</strong>: <strong>' + esc(m.name || m.map_id) + '</strong>.<br><span class="small text-muted">' + esc(phaseVerb) + '</span>';
                showConfirm(true);
            });
            act.appendChild(go);
        }

        var cl = document.createElement('button');
        cl.type = 'button';
        cl.className = 'btn btn-outline-secondary btn-sm';
        cl.textContent = 'Close';
        cl.addEventListener('click', function () { showMapDetailOpen(false); });
        act.appendChild(cl);

        showMapDetailOpen(true);
    }

    function render(state) {
        window.__lastState = state;
        const status = state.status;

        let badgeClass = 'badge-secondary';
        let label = status;
        if (status === 'pending') { label = 'Waiting for start'; }
        if (status === 'live_veto') { label = 'Veto phase'; badgeClass = 'badge-warning'; }
        if (status === 'live_order') { label = 'Map pick'; badgeClass = 'badge-primary'; }
        if (status === 'completed') { label = 'Completed'; badgeClass = 'badge-success'; }
        if (status === 'cancelled') { label = 'Cancelled'; badgeClass = 'badge-dark'; }

        const phaseEl = document.getElementById('phaseBadge');
        phaseEl.className = 'badge ' + badgeClass;
        phaseEl.style.fontSize = '0.95rem';
        phaseEl.textContent = label;

        const timerRow = document.getElementById('timerRow');
        const livePhase = status === 'live_veto' || status === 'live_order';
        if (livePhase && (state.turn_expires_at || state.paused)) {
            timerRow.classList.remove('d-none');
        } else {
            timerRow.classList.add('d-none');
        }
        document.getElementById('timerVal').textContent = timerDisplay(state);

        const paName = (state.player_a && state.player_a.display_name) ? state.player_a.display_name : 'Player A';
        const pbName = (state.player_b && state.player_b.display_name) ? state.player_b.display_name : 'Player B';
        const acting = (status === 'live_veto' || status === 'live_order') ? (state.current_turn_side || '') : '';
        const actingName = acting === 'b' ? pbName : (acting === 'a' ? paName : '');
        const mySide = state.my_side || '';

        const phaseHero = document.getElementById('phaseHero');
        const phaseMain = document.getElementById('phaseMainLabel');
        const phaseSub = document.getElementById('phaseSubLabel');
        phaseHero.classList.remove('mv-phase--veto', 'mv-phase--order', 'mv-phase--idle');
        let mainText = 'Prepare';
        let subText = '';
        if (status === 'live_veto') {
            phaseHero.classList.add('mv-phase--veto');
            mainText = 'Veto phase';
            const vp = state.veto_progress;
            if (vp && vp.total) {
                subText = 'Veto ' + vp.current + ' of ' + vp.total + ' · remove maps until ' + state.maps_to_play + ' remain';
            } else {
                subText = 'Take turns removing maps until ' + state.maps_to_play + ' maps remain in the pool.';
            }
        } else if (status === 'live_order') {
            phaseHero.classList.add('mv-phase--order');
            mainText = 'Map order';
            const op = state.order_progress;
            if (op && op.total_games) {
                subText = 'Choosing Game ' + op.current_game + ' map (' + op.current_game + ' of ' + op.total_games + ' maps)';
            } else {
                subText = 'Take turns assigning each remaining map to Game 1…Game ' + state.maps_to_play + '.';
            }
        } else {
            phaseHero.classList.add('mv-phase--idle');
            mainText = status === 'pending' ? 'Not started' : (status === 'completed' ? 'Finished' : 'Idle');
            subText = '';
        }
        phaseMain.textContent = mainText;
        phaseSub.textContent = subText;

        document.getElementById('pillNameA').textContent = paName;
        document.getElementById('pillNameB').textContent = pbName;
        function pillCaption(side) {
            var seat = side === 'a' ? (mySide === 'a' ? MY_SEAT : OPP_SEAT) : (mySide === 'b' ? MY_SEAT : OPP_SEAT);
            if (mySide === side) {
                var y = ['You · ' + seat];
                if (acting === side) y.push('Choosing now');
                else y.push('Waiting');
                return y.join(' · ');
            }
            var o = [seat];
            if (acting === side) o.push('Choosing now');
            else o.push('Waiting');
            return o.join(' · ');
        }
        document.getElementById('pillCapA').textContent = pillCaption('a');
        document.getElementById('pillCapB').textContent = pillCaption('b');

        var pillA = document.getElementById('pillA');
        var pillB = document.getElementById('pillB');
        pillA.classList.toggle('mv-player-pill--active', acting === 'a');
        pillB.classList.toggle('mv-player-pill--active', acting === 'b');
        pillA.classList.toggle('mv-player-pill--me', mySide === 'a');
        pillB.classList.toggle('mv-player-pill--me', mySide === 'b');
        pillA.classList.toggle('mv-player-pill--opp', mySide !== 'a');
        pillB.classList.toggle('mv-player-pill--opp', mySide !== 'b');

        const bY = document.getElementById('turnBannerYour');
        const bW = document.getElementById('turnBannerWait');
        const live = status === 'live_veto' || status === 'live_order';
        if (live && state.paused) {
            bY.classList.add('d-none');
            bW.classList.remove('d-none');
            document.getElementById('turnBannerWaitDetail').textContent = 'Paused by admin — timer frozen until they resume.';
        } else if (live && state.is_my_turn) {
            bY.classList.remove('d-none');
            bW.classList.add('d-none');
            var yd = document.getElementById('turnBannerYourDetail');
            if (status === 'live_veto') {
                yd.textContent = 'Select a map below to veto (remove) from the pool. This is your action — your opponent will see they are waiting.';
            } else {
                yd.textContent = 'Select a map for Game ' + (state.game_number || 1) + '. This is your pick — your opponent is waiting.';
            }
        } else if (live && !state.is_my_turn && actingName) {
            bY.classList.add('d-none');
            bW.classList.remove('d-none');
            var wd = document.getElementById('turnBannerWaitDetail');
            if (status === 'live_veto') {
                wd.textContent = actingName + ' is vetoing a map. Maps are locked until they finish.';
            } else {
                wd.textContent = actingName + ' is choosing which map is played in Game ' + (state.game_number || 1) + '.';
            }
        } else {
            bY.classList.add('d-none');
            bW.classList.add('d-none');
        }

        const mapsRow = document.getElementById('mapsRow');
        mapsRow.innerHTML = '';

        const maps = state.session_maps || [];
        maps.forEach(function (m) {
            const col = document.createElement('div');
            col.className = 'col-6 col-md-4 mb-3';

            const card = document.createElement('div');
            card.className = 'mv-map-card';

            const thumb = document.createElement('div');
            thumb.className = 'mv-map-thumb';
            if (m.image_url) {
                const img = document.createElement('img');
                img.src = m.image_url;
                img.alt = m.name || m.map_id || '';
                img.loading = 'lazy';
                thumb.appendChild(img);
            } else {
                thumb.style.display = 'flex';
                thumb.style.alignItems = 'center';
                thumb.style.justifyContent = 'center';
                thumb.style.color = '#7d8590';
                thumb.style.fontSize = '0.85rem';
                thumb.textContent = 'No image';
            }

            const body = document.createElement('div');
            body.className = 'mv-card-body';
            const title = document.createElement('div');
            title.className = 'font-weight-bold small mb-1';
            title.textContent = m.name || m.map_id;

            let sub = '';
            if (m.state === 'vetoed') sub = 'Out (vetoed)';
            if (m.state === 'assigned') sub = 'Game ' + (m.game_number || '');
            if (m.state === 'available' && status === 'live_veto') sub = 'In pool — veto candidate';
            if (m.state === 'available' && status === 'live_order') sub = 'In pool — pick for a game';
            const meta = document.createElement('div');
            meta.className = 'small text-muted';
            meta.textContent = sub;

            body.appendChild(title);
            body.appendChild(meta);
            card.appendChild(thumb);
            card.appendChild(body);

            const canClick = state.is_my_turn && !state.paused && m.state === 'available' && (status === 'live_veto' || status === 'live_order');
            if (!canClick) {
                card.classList.add('disabled');
            } else {
                card.classList.add('mv-map-card--acting-turn');
            }

            card.addEventListener('click', function () {
                openPlayerMapModal(m, state, status, paName, pbName);
            });

            col.appendChild(card);
            mapsRow.appendChild(col);
        });

        const hist = document.getElementById('history');
        hist.innerHTML = '';
        const actions = state.actions || [];
        if (!actions.length) {
            hist.innerHTML = '<div class="small text-muted">No actions yet.</div>';
        } else {
            actions.slice().reverse().forEach(function (a) {
                const row = document.createElement('div');
                row.className = 'mv-hist-line';
                let who = 'System';
                if (a.acting_side === 'a') who = paName;
                if (a.acting_side === 'b') who = pbName;
                let line = '#' + (a.step || '') + ' — ' + who + ' — ' + (a.action_type || '') + ' — ' + (a.map_id || '');
                if (a.game_number) line += ' (G' + a.game_number + ')';
                if (a.was_timeout) line += ' <span style="color:#ffb347;">timeout</span>';
                row.innerHTML = line;
                hist.appendChild(row);
            });
        }

        const banner = document.getElementById('banner');
        if (livePhase && state.paused) {
            banner.classList.remove('d-none');
            banner.className = 'alert alert-info py-2';
            banner.textContent = 'Paused by admin — timer frozen.';
        } else if (status === 'pending') {
            banner.classList.remove('d-none');
            banner.className = 'alert alert-warning py-2';
            banner.textContent = 'Waiting for admin to start the veto.';
        } else if (status === 'completed' || status === 'cancelled') {
            banner.classList.remove('d-none');
            banner.className = 'alert alert-secondary py-2';
            banner.textContent = status === 'completed' ? 'Session completed.' : 'Session cancelled.';
        } else {
            banner.classList.add('d-none');
        }
    }

    async function poll() {
        try {
            const r = await fetch(apiState, {cache: 'no-store'});
            const j = await r.json();
            if (!j.ok) return;
            render(j.state);
        } catch (e) {}
    }

    poll();
    setInterval(poll, 2000);
    setInterval(function () {
        const el = document.getElementById('timerVal');
        const st = window.__lastState;
        if (!st) return;
        if (st.paused) {
            el.textContent = timerDisplay(st);
            return;
        }
        if (st.turn_expires_at) el.textContent = fmtRemaining(st.turn_expires_at);
    }, 250);
})();
</script>
</body>
</html>
