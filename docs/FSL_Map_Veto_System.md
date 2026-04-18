# FSL Map Veto System — Specification

**Version:** 1.1  
**Last Updated:** April 2026  
**Status:** Implemented (v1); rules and JSON model below remain the design reference; §14 documents the shipped app layout and UX.

---

## 1. Overview

### 1.1 Purpose

Two players veto maps from an approved pool until the maps for a match **series** are finalized, then assign **play order** (Game 1 … Game X). This document is the authoritative spec for behavior, rules, and **file-based persistence** for v1.

### 1.2 Scope (this repo)

| Area | Decision |
|---|---|
| Persistence | **No database.** All map veto data lives as JSON files under **`map-veto/data/`** at the project root (inside the map veto app folder). |
| Player rankings | **`rankings/rankings.json`** is the source of truth for ladder position. Session setup resolves each player’s **rank_value** by matching **`name`** (case/normalization rules should be documented in code; see §3). |
| SQL / migrations | None for map veto data. |

### 1.3 Spec review (concise)

The supplied spec is internally consistent if these are treated as **locked**:

- **`maps_to_play = best_of`** — BO3 means **three maps remain and are ordered**, not “first-to-2-wins only.”
- **Higher rank vetoes first**; **lower rank picks Game 1 map first** in the order phase; strict alternation (no snake draft unless added later).
- **Session map pool snapshot** at creation so later edits to seasons/maps do not rewrite history.
- **Server-authoritative** turns, timers (`turn_started_at`, `turn_expires_at`), and timeout resolution (random among valid choices, recorded as **autoveto** / **autopick**).
- **Final single map** in order phase → **system_finalize** (distinct from timeouts).

**MVP simplifications** (recommended to match “first version” in the original spec):

- Initial pool: **full eligible season pool** for BO1–BO7; BO9 adds **overflow-eligible** maps per overflow rules (see §5.2). Defer configurable `maps_to_play + 2` trimming until after MVP unless needed.
- Realtime: **polling** acceptable; SSE/WebSocket optional.

**Risk to call out in UX:** bearer-style player tokens mean **anyone with the link acts as that player** in v1 — acceptable if documented.

---

## 2. Integration with `rankings/rankings.json`

### 2.1 File shape (reference)

Each row includes at least:

| Field | Use for map veto |
|---|---|
| `rank` | **rank_value** for veto/order rules (integer; **lower number = higher placement** on the ladder, consistent with typical “rank 1 is best”). |
| `name` | Primary key for resolving a player picked in admin UI / session creation. |
| `race`, `group`, stats | Optional display-only on player/results screens. |

### 2.2 Resolution rules

When admin selects **Player A** / **Player B**:

1. Look up each player by **`name`** in `rankings/rankings.json`.
2. Set **`player_*_rank`** = that row’s **`rank`**.
3. If a name is missing from the file: **block session creation** or require manual rank entry — **decide one behavior in UI** and document it (recommended: block with clear error unless “override rank” admin field is filled).

### 2.3 Tied ranks

If two players have the **same `rank`** value in JSON (uncommon but possible after manual edits):

- **Preferred:** admin selects who is treated as **higher seed** for veto/order.
- **Fallback:** system randomizes, **logs** the outcome on the session (audit).

---

## 3. JSON storage layout (`map-veto/data/`)

Recommended file split (adjust names if implementation prefers fewer files):

| Path | Contents |
|---|---|
| `map-veto/data/maps.json` | Array of **Map** objects (see §4). |
| `map-veto/data/seasons.json` | Array of **Season** objects + embedded or referenced **season_maps** (enabled map ids per season). |
| `map-veto/data/sessions/<session_id>.json` | One file per veto session: session header, snapshot pool, tokens, full action log, turn/timer fields. Reduces whole-file contention vs one giant `sessions.json`. |
| `map-veto/data/images/` | Map thumbnails/uploads referenced by URL in `image_url` (e.g. `/fsl/map-veto/data/images/…`). |

**Concurrency:** PHP should write session files atomically (write temp + rename) or use an exclusive lock when updating a session.

---

## 4. Entities (logical model)

Maps, seasons, and sessions can mirror the relational model from the original spec **as JSON shapes**, without DB tables.

### 4.1 Player (session-scoped)

| Field | Notes |
|---|---|
| `id` | Stable id for this session role, e.g. `player_a` / `player_b` or UUID. |
| `display_name` | From rankings name or admin override. |
| `rank_value` | From `rankings.json` → `rank`. |
| `avatar`, `team_name` | Optional. |

### 4.2 Map

| Field | Notes |
|---|---|
| `id` | String or int; stable. |
| `name` | Required. |
| `description` | Optional. |
| `image_url` or `image_path` | Required for production UX; optional in dev if documented. |
| `is_active` | Boolean. |
| `is_overflow_eligible` | For BO9+ overflow pool. |
| `created_at`, `updated_at` | ISO 8601 strings. |

### 4.3 Season

| Field | Notes |
|---|---|
| `id` | Stable id. |
| `name`, `description` | |
| `is_active` | |
| `minimum_required_maps` | Default **7** enabled maps before season can be used. |
| `created_at`, `updated_at` | |

### 4.4 Season map assignment

Either embedded per season (`enabled_map_ids: []`) or `season_maps.json` array of `{ season_id, map_id, is_enabled }`.

### 4.5 Match / veto session

| Field | Notes |
|---|---|
| `id` | UUID or slug. |
| `season_id` | |
| `player_a_id`, `player_b_id` | |
| `player_a_rank`, `player_b_rank` | Snapshot at creation. |
| `higher_ranked_player_id`, `lower_ranked_player_id` | Computed: **lower numeric rank = higher placement** (confirm in code comments once). |
| `best_of` | `1 | 3 | 5 | 7 | 9`. |
| `maps_to_play` | **equals `best_of`** (see §5.1). |
| `timer_seconds` | Default 60. |
| `status` | See §8. |
| `current_phase` | `veto \| order \| completed`. |
| `current_turn_player_id` | |
| `game_number` | Current slot in order phase (1-based). |
| `turn_started_at`, `turn_expires_at` | Server-side deadlines. |
| `tie_break_seed_player_id` | Optional; used when ranks tied. |
| `pool_mode` | MVP: `full_eligible` or future: `trimmed`, `custom`. |
| `session_maps` | **Snapshot**: list of map ids + metadata copies needed for UI/export. |
| `public_results_token` | Opaque. |
| `tokens` | Hashes or opaque strings for player A/B + public (see §10). |
| `started_at`, `completed_at` | |
| `actions[]` | Ordered **Veto / Pick Action** log. |

### 4.6 Veto / pick action

| Field | Notes |
|---|---|
| `id` | |
| `step_number` | Monotonic. |
| `phase` | `veto \| order`. |
| `acting_player_id` | Nullable for system. |
| `action_type` | `veto \| pick_order \| autopick \| autoveto \| system_finalize \| admin_override` (if overrides supported). |
| `map_id` | |
| `game_number` | Nullable during veto; set during order picks. |
| `was_timeout` | Boolean. |
| `created_at` | |
| `metadata` | Optional: option set size for RNG audit, admin note, etc. |

---

## 5. Business rules

### 5.1 Match format

| best_of | maps_to_play (remaining after veto) |
|---:|---:|
| 1 | 1 |
| 3 | 3 |
| 5 | 5 |
| 7 | 7 |
| 9 | 9 |

**Veto phase ends** when **count(available maps) = maps_to_play**.

### 5.2 Map pool

- **Season pool:** all maps assigned to the season with `is_enabled` (and map `is_active`).
- **Minimum:** season must have **≥ 7** enabled maps to be selectable for a session.
- **Overflow (e.g. BO9):** if **maps_to_play > 7**, include maps with **`is_overflow_eligible`** until the **effective** pool can support veto (see original spec: **effective_pool_size ≥ maps_to_play + 2** is recommended; MVP may use **full season + all overflow-eligible** for BO9 if simpler).
- **Session creation validation:** effective pool size must be **> maps_to_play** (you need at least one veto step unless pool equals maps_to_play exactly — typically **pool > maps_to_play**).

### 5.3 Veto order

- First veto: **higher-ranked** player (`rank_value` numeric comparison per §2).
- Then alternate **A, B, A, B…** where **A = higher seed**.

### 5.4 Play order (after veto)

- **Lower-ranked** player assigns **Game 1**.
- Alternate: lower → Game 1, higher → Game 2, lower → Game 3, …
- If exactly **one** map remains for the next slot: **system_finalize** immediately (no wait for timer).

### 5.5 Timers

- Default **60s** per action; configurable per session.
- On expiry: uniform random choice among **valid** maps; label **autoveto** / **autopick**; set **`was_timeout: true`**.
- Pause/resume: adjust `turn_expires_at` server-side.
- Background: cron/loop or **lazy resolution** on next read (MVP acceptable if documented) — production should **not** rely on the browser alone.

---

## 6. Session lifecycle (status)

The **implementation** uses these `status` strings in session JSON and APIs:

| Status | Meaning |
|---|---|
| `pending` | Session created; admin has not started play yet. |
| `live_veto` | Veto phase in progress (`current_phase`: `veto`). |
| `live_order` | Map order phase in progress (`current_phase`: `order`). |
| `completed` | All games assigned; final order locked. |
| `cancelled` | Aborted by admin. |

**Original spec aliases** (for readers mapping older docs): `ready` ≈ `pending`; `in_progress_veto` ≈ `live_veto`; `in_progress_order` ≈ `live_order`. `draft`, `expired` are not used in v1.

---

## 7. API shape (implementation-agnostic)

Map to PHP endpoints under this site as appropriate. Logical routes:

**Admin**

- Maps CRUD; season CRUD; assign maps to seasons.
- `POST /admin/sessions` → create session file + snapshot.
- `POST /admin/sessions/:id/start`, `pause`, `resume`, `override`, `regenerate-token`.
- `GET /admin/sessions/:id/results/html`

**Player**

- `GET /session/:token` → server state.
- `POST /session/:token/action` → `{ mapId, actionType: veto | pick_order }`

**Public**

- `GET /results/:publicToken`
- `GET /results/:publicToken/download-html` → standalone HTML.

**Realtime**

- `GET /session/:token/stream` (SSE) or polling `GET /session/:token` every N seconds.

---

## 8. Validation (summary)

Every action must verify: session exists; status allows play; token valid and role matches; correct phase and turn; map valid; timer/turn not already resolved; idempotent enough to reject double-submit on same turn.

---

## 9. HTML export (broadcast)

Standalone HTML with **inline CSS**; sections: header (title, season, date, format, players + ranks); **final map order** cards (image, name); **veto timeline** with tags (**AutoVeto**, **AutoPick**, **System Finalized**); footer (session id, generated time, public URL). Suitable for OBS browser source.

---

## 10. Security notes (v1)

- Tokens: long random strings; store **hash** server-side if feasible; optional expiry/revoke.
- Admin routes must be **behind existing site auth** (implement per deployment).
- Image uploads: validate type/size.

---

## 11. MVP checklist

Shipped items are tracked in **§14.7**. Original backlog items not listed there remain future work (e.g. standalone HTML export per §9).

**Defer:** accounts for players, heavy animation, localization, multi-admin locking, analytics.

---

## 12. Open decisions (explicit)

| Topic | Recommendation |
|---|---|
| Initial pool size | MVP: **full eligible pool**; later: `maps_to_play + 2` or admin trim. |
| Tied rank | Admin seed override → else random + log. |
| BO9 overflow | Auto-include all **overflow-eligible** maps unless admin override list specified later. |
| Live visibility | Both players see history + remaining maps in near-real-time (polling OK). |

---

## 13. References

- Player ladder file: `rankings/rankings.json`
- JSON data directory: **`map-veto/data/`** (implementation-created)

---

## 14. Implemented application design (shipped)

This section describes how the veto system is **actually arranged in this repo** for operators, players, and broadcast—without replacing §5–§8 rules.

### 14.1 Directory layout

| Path | Role |
|---|---|
| `map-veto/player.php` | Player UI (token `t`). |
| `map-veto/watch.php` | Public broadcast / OBS-style view (watch token `t`). |
| `map-veto/api/state.php` | JSON state for polling (`t`). |
| `map-veto/api/action.php` | POST player action (`t`, body `{ map_id }`). |
| `map-veto/data/` | **All persistent JSON and images** for the feature (not a separate top-level `map-vetoes/` tree). |
| `includes/map_veto*.php` | Constants, store, engine, rankings, views/payload, uploads. |
| `fsl_manager_map_veto.php` | Admin: seasons, maps, session create/start/cancel/**reset**, token regeneration, links. |

**Image URLs** stored in JSON and returned by APIs use the web prefix **`/fsl/map-veto/data/images/`** (see `MAP_VETO_PUBLIC_WEB_IMAGE_BASE` in `includes/map_veto_constants.php`).

### 14.2 Admin operations

- **Create session** issues three opaque tokens: **player A**, **player B**, **public watch** (same session id; URLs differ only by page + token).
- **Start** moves `pending` → live veto with timer.
- **Cancel** stops an in-progress session.
- **Reset to start** clears vetoes/picks, rebuilds the map pool from the **current** season catalog, sets **`pending`**, and **does not rotate tokens**—intended for rehearsal and QA.
- **New tokens** regenerates all three links (optional).

### 14.3 Player experience (`player.php`)

- **Seat clarity:** Page chrome is **side-colored** (Player A warm gold vs Player B cool cyan). A ribbon shows **You**, display name, **Player A/B**, and opponent line. The matchup row can **mirror column order** so “your” column is on the left for both seats.
- **Map grid:** Bootstrap-style cards with thumbnail, name, and short state line. **Every card is clickable** and opens a **map detail overlay**: large image, optional **description** (merged from `maps.json` into `session_maps` in the API payload), map id, and status copy.
- **Actions:** On your turn with an **available** map during veto or order, the overlay offers **Continue to veto / assign**, which closes the overlay and opens the existing **confirm** step (so accidental taps read details first).
- **Polling:** Client polls `api/state.php` on an interval; timers refresh client-side between polls.

### 14.4 Broadcast / watch experience (`watch.php`)

- **Goals:** Read **glanceable** state for OBS: phase, timer, who acts now, and **all maps at once**.
- **Layout:** Compact header (matchup, season, BO), meta line, two player strips. **Spotlight** shows the current headline (who acts, phase) and a large countdown when a turn timer is active.
- **Map grid:** A single responsive **grid** of every `session_maps` row: **assigned** maps first (sorted by `game_number`, badge **G1…Gn**), then **available** (POOL), then **vetoed** (OUT, grayscale treatment, vetoing player where known). This avoids older “ladder only” layouts that scrolled to the bottom and hid early series slots.
- **Detail overlay:** Clicking any tile opens the same style of modal as the player view (image + description + status). No game actions on watch.
- **Polling:** Same `api/state.php` pattern as the player page.

### 14.5 API payload notes

- **`session_maps`:** Each row includes session snapshot fields; the server **enriches** entries with **`description`** from `map-veto/data/maps.json` when `map_id` matches, so modals can show catalog text without a second request.
- **`final_order`:** Derived from assigned maps for convenience; the watch UI relies on **`session_maps`** for the full grid.

### 14.6 Tooling

- **`scripts/sync_liquipedia_lotv_maps.py`:** Updates `map-veto/data/maps.json` and downloads thumbnails into **`map-veto/data/images/`**; generated `image_url` values use the **`/fsl/map-veto/data/images/`** prefix.

### 14.7 MVP checklist (implementation status)

- [x] `map-veto/data/` persistence (maps, seasons, per-session JSON).
- [x] Rank hints from **`rankings/rankings.json`** at session create.
- [x] Session create + map snapshot; start / cancel / **reset to start**; optional token regen.
- [x] Veto + order phases + **system_finalize** when one map remains for a slot.
- [x] Timers + server-side timeout actions (**autoveto** / **autopick**).
- [x] Player + watch token URLs; polling APIs.
- [ ] Standalone downloadable HTML export (§9) — not required for core play.

---
