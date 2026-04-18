<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/map_veto.php';

$token = $_GET['t'] ?? '';
$hit = $token !== '' ? map_veto_find_by_token($token) : null;

if ($hit === null || (($hit['role'] ?? '') !== 'public')) {
    http_response_code(403);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Map veto — Watch</title>
        <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
        <style>
            body { font-family: 'Inter', sans-serif; background: linear-gradient(135deg, #0f0c29, #302b63, #24243e); color: #e0e0e0; min-height: 100vh; }
            h1 { color: #00d4ff; text-shadow: 0 0 15px rgba(0, 212, 255, 0.45); font-size: 1.35rem; }
        </style>
    </head>
    <body>
    <div class="container py-5" style="max-width: 520px;">
        <h1 class="mb-3">Invalid watch link</h1>
        <p class="text-muted mb-0">Use the public watch URL from the admin (different from player links).</p>
    </div>
    </body>
    </html>
    <?php
    exit;
}

$session = map_veto_refresh_session((string) ($hit['session']['id'] ?? '')) ?? $hit['session'];
$pa = is_array($session['player_a'] ?? null) ? (string) ($session['player_a']['display_name'] ?? 'Player A') : 'Player A';
$pb = is_array($session['player_b'] ?? null) ? (string) ($session['player_b']['display_name'] ?? 'Player B') : 'Player B';
$mvWatchTitle = trim((string) ($session['match_title'] ?? ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Map veto — Live<?= htmlspecialchars(' — ' . $pa . ' vs ' . $pb, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        html { height: 100%; }
        body.mv-broadcast-root { font-family: 'Inter', sans-serif; background: linear-gradient(145deg, #0a0820 0%, #1a1740 45%, #12102a 100%); color: #e8ecf1; margin: 0; line-height: 1.35; height: 100%; overflow: hidden; }
        .mv-wrap {
            max-width: 900px; margin: 0 auto; padding: 0.45rem 0.65rem 0.5rem;
            height: 100vh; height: 100dvh;
            box-sizing: border-box;
            display: flex; flex-direction: column;
            gap: 0.35rem;
            overflow: hidden;
        }
        .mv-live-pill { display: inline-block; font-size: 0.62rem; font-weight: 800; letter-spacing: 0.12em; color: #1a1a2e; background: #ff5c5c; padding: 0.12rem 0.38rem; border-radius: 3px; vertical-align: middle; margin-right: 0.25rem; }
        .mv-banner { font-weight: 800; letter-spacing: .02em; color: #7ee8ff; text-shadow: 0 0 12px rgba(0, 212, 255, 0.35); font-size: clamp(0.95rem, 3.8vw, 1.35rem); line-height: 1.15; }
        .mv-subtle { color: #98a4b3; font-size: clamp(0.68rem, 2.4vw, 0.82rem); font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .mv-head-row { flex-shrink: 0; display: flex; align-items: flex-start; justify-content: space-between; gap: 0.5rem; }
        .mv-meta-bar {
            flex-shrink: 0;
            display: flex; flex-wrap: wrap; align-items: center; gap: 0.35rem 0.65rem;
            padding: 0.35rem 0.5rem;
            border-radius: 8px;
            background: rgba(0, 0, 0, 0.35);
            border: 1px solid rgba(255, 255, 255, 0.12);
            font-size: clamp(0.72rem, 2.2vw, 0.82rem);
        }
        .mv-meta-bar .badge { font-size: 0.68rem; padding: 0.2rem 0.45rem; font-weight: 700; }
        #phaseBadge { font-size: 0.72rem !important; padding: 0.22rem 0.45rem !important; }
        #timerRow { font-size: 0.78rem !important; margin: 0 !important; }
        .mv-phase-inline {
            font-weight: 700; color: #cfd8e3; flex: 1 1 140px; min-width: 0;
            line-height: 1.25;
            max-height: 2.55em;
            overflow: hidden;
            display: -webkit-box;
            -webkit-box-orient: vertical;
            -webkit-line-clamp: 2;
        }
        .mv-players-row { flex-shrink: 0; display: grid; grid-template-columns: 1fr 1fr; gap: 0.45rem; }
        .mv-broadcast-player {
            padding: 0.38rem 0.45rem; border-radius: 8px;
            background: rgba(255,255,255,0.06); border: 2px solid rgba(255,255,255,0.12);
            text-align: center; min-height: 0;
            display: flex; flex-direction: column; justify-content: center;
        }
        .mv-broadcast-player--on { border-color: #2ecfff; box-shadow: 0 0 14px rgba(46, 207, 255, 0.35); background: rgba(0, 212, 255, 0.12); }
        .mv-broadcast-player-name { font-weight: 800; font-size: clamp(0.78rem, 3.2vw, 1.05rem); line-height: 1.15; word-break: break-word; }
        .mv-broadcast-player-cap { font-size: 0.58rem; text-transform: uppercase; letter-spacing: 0.06em; color: #9aa8b8; margin-top: 0.15rem; font-weight: 700; }
        .mv-section-label { flex-shrink: 0; font-size: 0.58rem; text-transform: uppercase; letter-spacing: 0.1em; color: #5eb8ff; font-weight: 800; margin: 0 0 0.15rem 0; }
        .mv-grid-summary {
            flex-shrink: 0;
            font-size: clamp(0.62rem, 2.3vw, 0.74rem);
            color: #a8b6c4;
            font-weight: 600;
            padding: 0 0 0.25rem 0;
            line-height: 1.3;
        }
        .mv-grid-summary strong { color: #e8ecf1; font-weight: 700; }
        .mv-mid {
            flex: 1 1 0;
            min-height: 0;
            display: flex;
            flex-direction: column;
            gap: 0.35rem;
            overflow: hidden;
        }
        .mv-spotlight {
            flex: 0 0 auto;
            min-height: 0;
            display: flex;
            flex-direction: column;
            gap: 0.35rem;
            overflow: visible;
        }
        .mv-spot-kicker {
            font-size: 0.52rem;
            font-weight: 900;
            letter-spacing: 0.16em;
            color: #7a93a8;
            margin-bottom: 0.2rem;
        }
        .mv-spot-now-card {
            border-radius: 10px;
            padding: 0.5rem 0.6rem;
            background: rgba(0, 0, 0, 0.4);
            border: 2px solid rgba(255, 255, 255, 0.14);
            box-shadow: 0 6px 24px rgba(0, 0, 0, 0.35);
        }
        .mv-spot-now-card.mv-spot--veto { border-color: rgba(255, 200, 100, 0.55); background: rgba(255, 193, 7, 0.08); }
        .mv-spot-now-card.mv-spot--order { border-color: rgba(46, 207, 255, 0.55); background: rgba(0, 212, 255, 0.07); }
        .mv-spot-now-card.mv-spot--idle { border-color: rgba(255, 255, 255, 0.12); }
        .mv-spot-now-main {
            font-size: clamp(0.95rem, 4.2vw, 1.25rem);
            font-weight: 900;
            line-height: 1.2;
            color: #fff;
            text-shadow: 0 0 20px rgba(255, 255, 255, 0.12);
        }
        .mv-spot-now-sub { font-size: clamp(0.68rem, 2.6vw, 0.82rem); color: #aeb8c4; font-weight: 600; margin-top: 0.25rem; }
        .mv-spot-timer-big {
            margin-top: 0.35rem;
            font-size: clamp(1.35rem, 6vw, 1.95rem);
            font-weight: 900;
            letter-spacing: 0.08em;
            color: #7ef0ff;
            text-shadow: 0 0 18px rgba(126, 240, 255, 0.35);
        }
        .mv-grid-section {
            flex: 1 1 0;
            min-height: 0;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        .mv-map-grid-scroll {
            flex: 1 1 0;
            min-height: 0;
            overflow-y: auto;
            overflow-x: hidden;
            scrollbar-width: thin;
            scrollbar-color: rgba(255,255,255,0.28) transparent;
            padding-right: 2px;
        }
        .mv-map-grid-scroll::-webkit-scrollbar { width: 5px; }
        .mv-map-grid-scroll::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.22); border-radius: 5px; }
        .mv-map-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
            gap: 0.4rem;
            align-content: start;
        }
        .mv-grid-cell {
            border-radius: 8px;
            overflow: hidden;
            background: rgba(255, 255, 255, 0.07);
            border: 2px solid rgba(255, 255, 255, 0.14);
            display: flex;
            flex-direction: column;
            transition: box-shadow 0.2s ease, border-color 0.2s ease, opacity 0.2s ease, filter 0.2s ease;
            cursor: pointer;
        }
        .mv-grid-cell:hover {
            border-color: rgba(126, 240, 255, 0.45);
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.35);
        }
        .mv-grid-cell.mv-grid-cell--assigned {
            border-color: rgba(56, 198, 232, 0.85);
            box-shadow: 0 0 14px rgba(56, 198, 232, 0.22);
        }
        .mv-grid-cell.mv-grid-cell--available.mv-grid-cell--live {
            border-color: rgba(255, 200, 100, 0.55);
        }
        .mv-grid-cell.mv-grid-cell--vetoed {
            filter: grayscale(1);
            opacity: 0.58;
            border-color: rgba(255, 255, 255, 0.1);
        }
        .mv-grid-cell.mv-grid-cell--flash {
            animation: mvGridFlash 1s ease-out;
        }
        @keyframes mvGridFlash {
            0% { box-shadow: 0 0 0 0 rgba(126, 240, 255, 0.75); border-color: rgba(126, 240, 255, 0.95); }
            100% { box-shadow: 0 0 0 10px rgba(126, 240, 255, 0); border-color: rgba(255, 255, 255, 0.14); }
        }
        .mv-grid-thumb {
            height: 58px;
            background: rgba(0, 0, 0, 0.42);
            position: relative;
            flex-shrink: 0;
        }
        .mv-grid-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .mv-grid-thumb.mv-grid-thumb--empty {
            display: flex; align-items: center; justify-content: center;
            color: #697887; font-size: 0.58rem; text-align: center; padding: 0.2rem;
        }
        .mv-grid-badge {
            position: absolute;
            top: 3px;
            left: 3px;
            font-size: 0.52rem;
            font-weight: 900;
            letter-spacing: 0.06em;
            padding: 0.12rem 0.28rem;
            border-radius: 4px;
            background: rgba(10, 12, 28, 0.88);
            color: #e8fbff;
        }
        .mv-grid-badge.mv-grid-badge--g { background: rgba(0, 120, 160, 0.92); color: #fff; }
        .mv-grid-badge.mv-grid-badge--out { background: rgba(80, 80, 90, 0.92); color: #dce4ec; }
        .mv-grid-badge.mv-grid-badge--pool { background: rgba(180, 130, 40, 0.9); color: #1a1420; }
        .mv-grid-meta {
            padding: 0.28rem 0.32rem 0.35rem;
            flex: 1 1 auto;
            display: flex;
            flex-direction: column;
            gap: 0.08rem;
            min-height: 2.35rem;
        }
        .mv-grid-name {
            font-size: 0.62rem;
            font-weight: 700;
            color: #eef2f7;
            line-height: 1.2;
            display: -webkit-box;
            -webkit-box-orient: vertical;
            -webkit-line-clamp: 2;
            overflow: hidden;
        }
        .mv-grid-sub {
            font-size: 0.52rem;
            font-weight: 600;
            color: #9aa8b8;
            line-height: 1.2;
        }
        .mv-feed {
            flex-shrink: 0;
            max-height: 3.6rem;
            overflow: hidden;
            font-size: 0.62rem;
            color: #a8b6c4;
            line-height: 1.35;
            padding: 0.28rem 0.4rem;
            border-radius: 6px;
            background: rgba(0, 0, 0, 0.32);
            border: 1px solid rgba(255,255,255,0.06);
        }
        .mv-feed-line { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; border-left: 2px solid rgba(0, 212, 255, 0.35); padding-left: 0.35rem; margin-bottom: 0.12rem; }
        .mv-feed-line:last-child { margin-bottom: 0; }
        .mono { font-variant-numeric: tabular-nums; letter-spacing: .04em; color: #5ee6ff; }

        .mv-watch-overlay-backdrop {
            position: fixed; inset: 0; background: rgba(10,10,22,0.88); display: none; align-items: center; justify-content: center;
            padding: 1rem; overflow-y: auto; z-index: 1050;
        }
        .mv-watch-overlay-backdrop.show { display: flex; }
        .mv-watch-overlay-box {
            background: #151528; border: 1px solid rgba(56, 198, 232, 0.45); border-radius: 12px; padding: 1rem 1.1rem;
            max-width: min(560px, 96vw); max-height: calc(100vh - 2rem); overflow-y: auto; width: 100%;
            box-shadow: 0 16px 48px rgba(0,0,0,0.55); color: #e8ecf1; position: relative;
        }
        .mv-watch-detail-close {
            position: absolute; top: 0.55rem; right: 0.55rem; background: transparent; border: none; color: #9aa8b8;
            font-size: 1.45rem; line-height: 1; padding: 0.2rem 0.5rem; cursor: pointer; border-radius: 6px;
        }
        .mv-watch-detail-close:hover { color: #fff; background: rgba(255,255,255,0.08); }
        .mv-watch-detail-title { color: #7ee8ff; font-size: 1.15rem; font-weight: 800; margin-right: 2rem; word-break: break-word; }
        .mv-watch-detail-id { font-size: 0.72rem; color: #8899ab; font-family: ui-monospace, monospace; margin-top: 0.2rem; }
        .mv-watch-detail-img { margin: 0.45rem 0 0.85rem; border-radius: 10px; overflow: hidden; background: rgba(0,0,0,0.45); text-align: center; }
        .mv-watch-detail-img img { width: 100%; max-height: min(52vh, 420px); object-fit: contain; display: block; margin: 0 auto; }
    </style>
</head>
<body class="mv-broadcast-root">
<div class="mv-wrap">
    <div class="mv-head-row">
        <div class="min-w-0" style="flex:1;">
            <div class="mb-0"><span class="mv-live-pill">LIVE</span><span style="font-size:0.58rem;letter-spacing:0.12em;color:#8899a8;">Broadcast</span></div>
            <?php if ($mvWatchTitle !== ''): ?>
                <div class="mv-subtle"><?= htmlspecialchars($mvWatchTitle, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <div class="mv-banner"><?= htmlspecialchars($pa, ENT_QUOTES, 'UTF-8') ?> <span style="color:#8fa0b0;font-weight:700;">vs</span> <?= htmlspecialchars($pb, ENT_QUOTES, 'UTF-8') ?></div>
            <div class="mv-subtle"><?= htmlspecialchars((string) ($session['season_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?> · BO<?= (int) ($session['best_of'] ?? 1) ?></div>
        </div>
    </div>

    <div id="metaBar" class="mv-meta-bar">
        <span id="phaseBadge" class="badge badge-secondary">…</span>
        <span id="phaseInline" class="mv-phase-inline"></span>
        <div id="timerRow" class="text-light d-none ml-auto"><span class="mono" id="timerVal">—</span></div>
    </div>

    <div class="mv-players-row">
        <div id="watchPillA" class="mv-broadcast-player">
            <div id="watchNameA" class="mv-broadcast-player-name"><?= htmlspecialchars($pa, ENT_QUOTES, 'UTF-8') ?></div>
            <div id="watchCapA" class="mv-broadcast-player-cap"></div>
        </div>
        <div id="watchPillB" class="mv-broadcast-player">
            <div id="watchNameB" class="mv-broadcast-player-name"><?= htmlspecialchars($pb, ENT_QUOTES, 'UTF-8') ?></div>
            <div id="watchCapB" class="mv-broadcast-player-cap"></div>
        </div>
    </div>

    <div class="mv-mid">
        <div id="spotlight" class="mv-spotlight">
            <div id="spotNowCard" class="mv-spot-now-card mv-spot--idle">
                <div id="spotNowKicker" class="mv-spot-kicker">Right now</div>
                <div id="spotNowMain" class="mv-spot-now-main">…</div>
                <div id="spotNowSub" class="mv-spot-now-sub"></div>
                <div id="spotNowTimerWrap" class="mv-spot-timer-big d-none"><span class="mono" id="timerValSpot">—</span></div>
            </div>
        </div>
        <div class="mv-grid-section">
            <div class="mv-section-label">All maps — series · pool · vetoed</div>
            <div id="mapGridSummary" class="mv-grid-summary"></div>
            <div id="mapGridScroll" class="mv-map-grid-scroll">
                <div id="mapGrid" class="mv-map-grid"></div>
            </div>
        </div>
    </div>

    <div id="history" class="mv-feed" aria-live="polite"></div>
</div>

<div id="mapDetailBackdrop" class="mv-watch-overlay-backdrop" role="dialog" aria-modal="true" aria-labelledby="watchMapDetailTitle">
    <div class="mv-watch-overlay-box">
        <button type="button" class="mv-watch-detail-close" id="watchMapDetailCloseX" aria-label="Close">&times;</button>
        <h2 id="watchMapDetailTitle" class="mv-watch-detail-title mb-0">Map</h2>
        <div id="watchMapDetailId" class="mv-watch-detail-id"></div>
        <div id="watchMapDetailImg" class="mv-watch-detail-img"></div>
        <div id="watchMapDetailDesc" class="small text-muted mb-2" style="white-space: pre-wrap;"></div>
        <div id="watchMapDetailMeta" class="small mb-2" style="color:#cfd8e3;"></div>
        <div id="watchMapDetailActions" class="d-flex justify-content-end"><button type="button" class="btn btn-sm btn-outline-light" id="watchMapDetailCloseBtn">Close</button></div>
    </div>
</div>

<script>
(function () {
    const token = <?= json_encode($token) ?>;
    const apiState = 'api/state.php?t=' + encodeURIComponent(token);
    window.__lastState = null;
    window.__watchPrevActionStep = undefined;

    var watchMapDetailBackdrop = document.getElementById('mapDetailBackdrop');

    function watchMapDetailShow(show) {
        if (!watchMapDetailBackdrop) return;
        watchMapDetailBackdrop.classList.toggle('show', !!show);
    }

    function openWatchMapModal(m, status, paName, pbName) {
        document.getElementById('watchMapDetailTitle').textContent = m.name || m.map_id || 'Map';
        document.getElementById('watchMapDetailId').textContent = m.map_id ? ('ID ' + m.map_id) : '';

        var iw = document.getElementById('watchMapDetailImg');
        iw.innerHTML = '';
        if (m.image_url) {
            var im = document.createElement('img');
            im.src = m.image_url;
            im.alt = '';
            iw.appendChild(im);
        } else {
            iw.innerHTML = '<div class="text-muted small py-4 px-2">No image</div>';
        }

        var descRaw = (m.description != null && String(m.description).trim() !== '') ? String(m.description).trim() : '';
        var dEl = document.getElementById('watchMapDetailDesc');
        dEl.textContent = descRaw;
        dEl.classList.toggle('d-none', !descRaw);

        var meta = document.getElementById('watchMapDetailMeta');
        meta.innerHTML = '';
        var st = m.state || '';
        var lines = [];
        if (st === 'assigned') {
            lines.push('Scheduled for Game ' + (m.game_number || '?') + ' of the series.');
        } else if (st === 'available') {
            if (status === 'live_veto') lines.push('Still in the pool — can be vetoed.');
            else if (status === 'live_order') lines.push('Still in the pool — waiting for a game assignment.');
            else lines.push('In pool.');
        } else if (st === 'vetoed') {
            var vbn = '';
            var vbs = m.vetoed_by || '';
            if (vbs === 'a') vbn = paName;
            else if (vbs === 'b') vbn = pbName;
            lines.push(vbn ? ('Vetoed by ' + vbn + '.') : 'Vetoed out of the pool.');
        }
        lines.forEach(function (line) {
            var row = document.createElement('div');
            row.className = 'mb-1';
            row.textContent = line;
            meta.appendChild(row);
        });

        watchMapDetailShow(true);
    }

    if (watchMapDetailBackdrop) {
        watchMapDetailBackdrop.addEventListener('click', function (ev) {
            if (ev.target === watchMapDetailBackdrop) watchMapDetailShow(false);
        });
    }
    var wx = document.getElementById('watchMapDetailCloseX');
    var wb = document.getElementById('watchMapDetailCloseBtn');
    if (wx) wx.addEventListener('click', function () { watchMapDetailShow(false); });
    if (wb) wb.addEventListener('click', function () { watchMapDetailShow(false); });

    document.addEventListener('keydown', function (ev) {
        if (ev.key !== 'Escape') return;
        if (watchMapDetailBackdrop && watchMapDetailBackdrop.classList.contains('show')) watchMapDetailShow(false);
    });

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

    function render(state) {
        window.__lastState = state;
        const status = state.status;

        let badgeClass = 'badge-secondary';
        let label = status;
        if (status === 'pending') { label = 'WAIT'; }
        if (status === 'live_veto') { label = 'VETO'; badgeClass = 'badge-warning'; }
        if (status === 'live_order') { label = 'ORDER'; badgeClass = 'badge-primary'; }
        if (status === 'completed') { label = 'DONE'; badgeClass = 'badge-success'; }
        if (status === 'cancelled') { label = 'OFF'; badgeClass = 'badge-dark'; }

        const badgeEl = document.getElementById('phaseBadge');
        badgeEl.className = 'badge ' + badgeClass;
        badgeEl.textContent = label;

        const timerRow = document.getElementById('timerRow');
        if ((status === 'live_veto' || status === 'live_order') && state.turn_expires_at) {
            timerRow.classList.remove('d-none');
        } else {
            timerRow.classList.add('d-none');
        }
        var rem = fmtRemaining(state.turn_expires_at);
        document.getElementById('timerVal').textContent = rem;
        var spotT = document.getElementById('timerValSpot');
        if (spotT) spotT.textContent = rem;

        const pa = (state.player_a && state.player_a.display_name) ? state.player_a.display_name : 'Player A';
        const pbName = (state.player_b && state.player_b.display_name) ? state.player_b.display_name : 'Player B';
        const acting = (status === 'live_veto' || status === 'live_order') ? (state.current_turn_side || '') : '';
        const actingName = acting === 'b' ? pbName : (acting === 'a' ? pa : '');

        const mapsToPlay = Number(state.maps_to_play) || 1;
        const mapsAll = state.session_maps || [];
        let nAvail = 0;
        let nVetoed = 0;
        let nAssigned = 0;
        mapsAll.forEach(function (x) {
            if (x.state === 'available') nAvail++;
            else if (x.state === 'vetoed') nVetoed++;
            else if (x.state === 'assigned') nAssigned++;
        });

        let phaseLine = '';
        if (status === 'live_veto') {
            const vp = state.veto_progress;
            const prog = vp && vp.total ? (vp.current + '/' + vp.total + ' vetoes · ') : '';
            phaseLine = prog + nAvail + ' in pool · stop at ' + mapsToPlay + ' · ';
            phaseLine += actingName ? ('Now: ' + actingName + ' vetoes') : 'Turn pending';
        } else if (status === 'live_order') {
            const op = state.order_progress;
            const gn = Number(state.game_number) || 1;
            const og = op && op.total_games ? ('Pick ' + (op.current_game || gn) + '/' + op.total_games + ' · ') : '';
            phaseLine = og + nAvail + ' maps left · ';
            phaseLine += actingName ? ('Now: ' + actingName + ' → Game ' + gn) : 'Turn pending';
        } else if (status === 'pending') {
            phaseLine = 'Waiting for admin to start';
        } else if (status === 'completed') {
            phaseLine = 'Series order locked';
        } else if (status === 'cancelled') {
            phaseLine = 'Cancelled';
        } else {
            phaseLine = 'Stand by';
        }
        document.getElementById('phaseInline').textContent = phaseLine;

        function sortMapsWatch(mapsAll) {
            var assigned = []; var avail = []; var vetoed = [];
            mapsAll.forEach(function (m) {
                var st = m.state || '';
                if (st === 'assigned') assigned.push(m);
                else if (st === 'available') avail.push(m);
                else if (st === 'vetoed') vetoed.push(m);
            });
            assigned.sort(function (a, b) {
                return (Number(a.game_number) || 0) - (Number(b.game_number) || 0);
            });
            avail.sort(function (a, b) {
                return (a.name || a.map_id || '').localeCompare(b.name || b.map_id || '');
            });
            vetoed.sort(function (a, b) {
                return (a.name || a.map_id || '').localeCompare(b.name || b.map_id || '');
            });
            return assigned.concat(avail).concat(vetoed);
        }

        var spotCard = document.getElementById('spotNowCard');
        spotCard.classList.remove('mv-spot--veto', 'mv-spot--order', 'mv-spot--idle');
        if (status === 'live_veto') spotCard.classList.add('mv-spot--veto');
        else if (status === 'live_order') spotCard.classList.add('mv-spot--order');
        else spotCard.classList.add('mv-spot--idle');

        var gnSpot = Number(state.game_number) || 1;
        var nowMain = 'Stand by';
        if (status === 'pending') nowMain = 'Waiting to start';
        else if (status === 'completed') nowMain = 'Series complete';
        else if (status === 'cancelled') nowMain = 'Cancelled';
        else if (status === 'live_veto') nowMain = actingName ? (actingName + ' removes a map') : 'Veto phase — next turn';
        else if (status === 'live_order') nowMain = actingName ? (actingName + ' picks Game ' + gnSpot + ' map') : 'Map order — next turn';

        var nowSubBits = [];
        if (status === 'live_veto' || status === 'live_order') {
            nowSubBits.push(nAvail + ' maps still in pool');
        } else if (status === 'pending') {
            nowSubBits.push('Admin starts the veto from the manager');
        } else if (status === 'completed') {
            nowSubBits.push(mapsToPlay + '-game order is set');
        }
        document.getElementById('spotNowMain').textContent = nowMain;
        document.getElementById('spotNowSub').textContent = nowSubBits.join(' · ');

        var tSpot = document.getElementById('spotNowTimerWrap');
        if ((status === 'live_veto' || status === 'live_order') && state.turn_expires_at) {
            tSpot.classList.remove('d-none');
            document.getElementById('timerValSpot').textContent = rem;
        } else {
            tSpot.classList.add('d-none');
        }

        document.getElementById('watchNameA').textContent = pa;
        document.getElementById('watchNameB').textContent = pbName;
        if (acting === 'a' || acting === 'b') {
            document.getElementById('watchCapA').textContent = acting === 'a' ? 'Acts now' : '';
            document.getElementById('watchCapB').textContent = acting === 'b' ? 'Acts now' : '';
        } else {
            document.getElementById('watchCapA').textContent = '\u00a0';
            document.getElementById('watchCapB').textContent = '\u00a0';
        }
        document.getElementById('watchPillA').classList.toggle('mv-broadcast-player--on', acting === 'a');
        document.getElementById('watchPillB').classList.toggle('mv-broadcast-player--on', acting === 'b');

        var actionsList = state.actions || [];
        var lastAct = actionsList.length ? actionsList[actionsList.length - 1] : null;
        var lastStep = lastAct && lastAct.step != null ? Number(lastAct.step) : actionsList.length;
        var flashId = '';
        if (typeof window.__watchPrevActionStep === 'number' && lastStep > window.__watchPrevActionStep && lastAct && lastAct.map_id) {
            flashId = String(lastAct.map_id);
        }
        window.__watchPrevActionStep = lastStep;

        var liveActive = status === 'live_veto' || status === 'live_order';
        var sumEl = document.getElementById('mapGridSummary');
        sumEl.innerHTML = '<strong>Series</strong> ' + nAssigned + '/' + mapsToPlay + ' maps placed · <strong>Pool</strong> ' + nAvail + ' · <strong>Out</strong> ' + nVetoed;

        var gridHost = document.getElementById('mapGrid');
        gridHost.innerHTML = '';
        var ordered = sortMapsWatch(mapsAll.slice());
        if (!ordered.length) {
            var emp = document.createElement('div');
            emp.className = 'small text-muted py-2';
            emp.textContent = 'No map data.';
            gridHost.appendChild(emp);
        } else {
            ordered.forEach(function (m) {
                var st = m.state || '';
                var cell = document.createElement('div');
                cell.className = 'mv-grid-cell';
                cell.dataset.mapId = m.map_id || '';
                if (st === 'assigned') cell.classList.add('mv-grid-cell--assigned');
                else if (st === 'available') {
                    cell.classList.add('mv-grid-cell--available');
                    if (liveActive) cell.classList.add('mv-grid-cell--live');
                } else if (st === 'vetoed') cell.classList.add('mv-grid-cell--vetoed');
                if (flashId && (m.map_id || '') === flashId) cell.classList.add('mv-grid-cell--flash');

                var th = document.createElement('div');
                th.className = 'mv-grid-thumb';
                if (m.image_url) {
                    var im = document.createElement('img');
                    im.src = m.image_url;
                    im.alt = '';
                    im.loading = 'lazy';
                    th.appendChild(im);
                } else {
                    th.classList.add('mv-grid-thumb--empty');
                    var ph = document.createElement('span');
                    ph.textContent = 'No img';
                    th.appendChild(ph);
                }

                var bd = document.createElement('div');
                bd.className = 'mv-grid-badge';
                if (st === 'assigned') {
                    bd.classList.add('mv-grid-badge--g');
                    bd.textContent = 'G' + (m.game_number || '?');
                } else if (st === 'available') {
                    bd.classList.add('mv-grid-badge--pool');
                    bd.textContent = 'POOL';
                } else if (st === 'vetoed') {
                    bd.classList.add('mv-grid-badge--out');
                    bd.textContent = 'OUT';
                } else {
                    bd.textContent = st ? st.toUpperCase() : '—';
                }
                th.appendChild(bd);

                var meta = document.createElement('div');
                meta.className = 'mv-grid-meta';
                var nm = document.createElement('div');
                nm.className = 'mv-grid-name';
                nm.textContent = m.name || m.map_id || '';
                var sub = document.createElement('div');
                sub.className = 'mv-grid-sub';
                if (st === 'assigned') {
                    sub.textContent = 'Game ' + (m.game_number || '?') + ' of series';
                } else if (st === 'available') {
                    sub.textContent = status === 'live_veto' ? 'Still in pool · veto' : (status === 'live_order' ? 'Still in pool · pick' : 'In pool');
                } else if (st === 'vetoed') {
                    var vbs = m.vetoed_by || '';
                    var vbn = vbs === 'a' ? pa : (vbs === 'b' ? pbName : '');
                    sub.textContent = vbn ? ('Vetoed · ' + vbn) : 'Vetoed';
                } else {
                    sub.textContent = '';
                }
                meta.appendChild(nm);
                meta.appendChild(sub);

                cell.appendChild(th);
                cell.appendChild(meta);
                cell.addEventListener('click', function (ev) {
                    ev.preventDefault();
                    openWatchMapModal(m, status, pa, pbName);
                });
                gridHost.appendChild(cell);
            });
        }

        const hist = document.getElementById('history');
        hist.innerHTML = '';
        const actions = state.actions || [];
        if (!actions.length) {
            hist.textContent = 'No actions yet.';
        } else {
            const recent = actions.slice().reverse().slice(0, 3);
            recent.forEach(function (a) {
                const row = document.createElement('div');
                row.className = 'mv-feed-line';
                let who = 'System';
                if (a.acting_side === 'a') who = pa;
                if (a.acting_side === 'b') who = pbName;
                let line = '#' + (a.step || '') + ' · ' + who + ' · ' + (a.action_type || '') + ' · ' + (a.map_id || '');
                if (a.game_number) line += ' · G' + a.game_number;
                if (a.was_timeout) line += ' · timeout';
                row.textContent = line;
                hist.appendChild(row);
            });
        }

        var mgScroll = document.getElementById('mapGridScroll');
        if (mgScroll && flashId) {
            var esc = typeof CSS !== 'undefined' && CSS.escape ? CSS.escape(flashId) : String(flashId).replace(/\\/g, '\\\\').replace(/"/g, '\\"');
            var hit = mgScroll.querySelector('[data-map-id="' + esc + '"]');
            if (hit && typeof hit.scrollIntoView === 'function') hit.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
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
        const st = window.__lastState;
        if (!st || !st.turn_expires_at) return;
        const t = fmtRemaining(st.turn_expires_at);
        const el = document.getElementById('timerVal');
        if (el) el.textContent = t;
        const sp = document.getElementById('timerValSpot');
        if (sp) sp.textContent = t;
    }, 250);
})();
</script>
</body>
</html>
