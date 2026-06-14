# FSL Spider Chart Voting API — Full Specification
**Version:** 1.0  
**Last Updated:** April 2026  
**Status:** Pre-implementation design doc

---

## 1. Overview

The FSL Spider Chart system scores players across six skill attributes (Micro, Macro, Clutch, Creativity, Aggression, Strategy). Scores are built from votes cast by human reviewers watching matches and, via this API, by a Twitch chat bot that collects live audience votes during a timed voting window.

This document covers:
- Database schema (existing + new)
- Score aggregation formula
- API endpoint reference
- Bot integration guide

---

## 2. Core Concepts

### 2.1 Attributes
Six axes, always the same set:

| Key | Display Name |
|---|---|
| `micro` | Micro |
| `macro` | Macro |
| `clutch` | Clutch |
| `creativity` | Creativity |
| `aggression` | Aggression |
| `strategy` | Strategy |

### 2.2 Vote Values (per attribute)
| Value | Meaning |
|---|---|
| `1` | player1 won this attribute |
| `2` | player2 won this attribute |
| `0` | Tie |

`player1` = `winner_player_id` from `fsl_matches`.  
`player2` = `loser_player_id` from `fsl_matches`.

This ordering is fixed and must be consistent between what the bot reads from `GET /matches/{fsl_match_id}` and what it submits to `POST /votes`.

### 2.3 Voting Window (Session)
A voting session is a short-lived, globally-scoped window tied to one match. Only one session can be active at a time. TTL is **5 minutes** from the time of enabling.

### 2.4 The Bot Reviewer
The Twitch bot has exactly **one row in the `reviewers` table**. Its `weight` field (default `1.00`) scales all chat tallies. Adjusting this single field tunes how much chat influence counts relative to human expert reviewers — no code changes required.

---

## 3. Database Schema

### 3.1 Existing Tables (reference)

#### `Players`
| Column | Type | Notes |
|---|---|---|
| `Player_ID` | INT PK | Used in all vote/score FKs |
| `Real_Name` | VARCHAR(255) UNIQUE | Display name |
| `Status` | ENUM('active','inactive','banned','other') | Only active players appear in charts |

#### `fsl_matches`
| Column | Type | Notes |
|---|---|---|
| `fsl_match_id` | INT PK | Primary key passed to all API calls |
| `season` | INT | e.g. `10` |
| `t_code` | VARCHAR(50) | Division code: `'S'`, `'A'`, `'B'` |
| `winner_player_id` | INT FK → Players | Becomes `player1` in API responses |
| `loser_player_id` | INT FK → Players | Becomes `player2` in API responses |
| `notes` | VARCHAR(255) | Optional label |
| `vod` | VARCHAR(255) | VOD URL |

#### `reviewers`
| Column | Type | Notes |
|---|---|---|
| `id` | INT PK | Referenced as `reviewer_id` in votes |
| `name` | VARCHAR(255) | e.g. `"TwitchChat"` for the bot |
| `unique_url` | VARCHAR(255) UNIQUE | Auth token for human reviewers |
| `weight` | DECIMAL(3,2) | Default `1.00`; **the tuning lever for chat influence** |
| `status` | ENUM('active','inactive') | Inactive reviewers excluded from aggregation |

#### `Player_Attributes`
Aggregated scores. Fully recomputed by `aggregate_scores.php` on demand. Do not write directly.

| Column | Type | Notes |
|---|---|---|
| `player_id` | INT FK | |
| `division` | ENUM('A','B','S') | Derived from `fsl_matches.t_code` |
| `micro` … `strategy` | DECIMAL(4,2) | Score range: **5.00–10.00** |
| `last_updated` | TIMESTAMP | Auto-set on write |

Unique constraint: `(player_id, division)`.

---

### 3.2 New Tables

#### `voting_sessions`
Tracks the active (or recently closed) voting window.

```sql
CREATE TABLE voting_sessions (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    fsl_match_id  INT NOT NULL,
    enabled_by    VARCHAR(255) NULL,       -- audit: twitch login of mod or bot account
    channel       VARCHAR(255) NULL,       -- audit: twitch channel name
    enabled_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at    TIMESTAMP NOT NULL,      -- enabled_at + 5 minutes
    closed_at     TIMESTAMP NULL,          -- NULL = still open or expired naturally
    status        ENUM('open','closed','expired') DEFAULT 'open',
    FOREIGN KEY (fsl_match_id) REFERENCES fsl_matches(fsl_match_id) ON DELETE CASCADE,
    INDEX idx_vs_status (status),
    INDEX idx_vs_match  (fsl_match_id),
    INDEX idx_vs_expires (expires_at)
);
```

A session is considered **active** when `status = 'open'` AND `expires_at > NOW()`.

---

### 3.3 Schema Changes to Existing Tables

#### `Player_Attribute_Votes` — add tally columns

```sql
ALTER TABLE Player_Attribute_Votes
    ADD COLUMN tally_player1 INT NULL AFTER vote,
    ADD COLUMN tally_player2 INT NULL AFTER tally_player1,
    ADD COLUMN tally_tie     INT NULL AFTER tally_player2;
```

| Column | Type | Notes |
|---|---|---|
| `tally_player1` | INT NULL | Chat votes for player1 on this attribute |
| `tally_player2` | INT NULL | Chat votes for player2 on this attribute |
| `tally_tie` | INT NULL | Chat votes for tie |

- All three are `NULL` for human reviewer rows (existing behavior unchanged).
- All three are populated for bot-submitted rows.
- Total chat voters for this attribute = `tally_player1 + tally_player2 + tally_tie`.

---

## 4. Score Aggregation Formula

`aggregate_scores.php` runs on demand (CLI or triggered post-vote) and fully rebuilds `Player_Attributes`.

### 4.1 Per player, per attribute, per division:

Collect all `Player_Attribute_Votes` rows where this player appears as `player1_id` or `player2_id` and the match's `t_code` matches the division.

For each vote row:

**Human reviewer row** (`tally_*` all NULL):
```
reviewer_weight = reviewers.weight for this reviewer_id
if (this player is player1 AND vote == 1) OR (this player is player2 AND vote == 2):
    positive += reviewer_weight
elif (this player is player1 AND vote == 2) OR (this player is player2 AND vote == 1):
    negative += reviewer_weight
# vote == 0 (tie): no positive/negative, but still adds to total
total_weight += reviewer_weight
```

**Bot/tally row** (`tally_*` populated):
```
reviewer_weight = reviewers.weight for bot reviewer_id
if this player is player1:
    positive += tally_player1 * reviewer_weight
    negative += tally_player2 * reviewer_weight
else (player2):
    positive += tally_player2 * reviewer_weight
    negative += tally_player1 * reviewer_weight
total_weight += (tally_player1 + tally_player2 + tally_tie) * reviewer_weight
```

### 4.2 Final score calculation:
```
if total_weight == 0:
    score = 5.00   (neutral baseline)
else:
    win_rate       = positive / total_weight        # 0.0 – 1.0
    normalized     = win_rate * 10                  # 0.0 – 10.0
    score          = (normalized / 2) + 5           # 5.0 – 10.0
    score          = round(clamp(score, 5.0, 10.0), 2)
```

Score range is always **5.00–10.00**. A player who loses every vote still scores 5.00. The chart displays the range 2–10 (configured in `config.php`).

### 4.3 Example scores

| Tally (P1 / P2 / Tie) | P1 Score | P2 Score | Note |
|---|---|---|---|
| 80 / 10 / 10 | 9.00 | 5.50 | Landslide |
| 48 / 45 / 7 | 7.40 | 7.25 | Close race |
| 40 / 40 / 20 | 7.00 | 7.00 | True tie |
| 10 / 2 / 0 | 9.00 | 6.00 | Small chat, clear winner |

### 4.4 Tuning chat influence

Adjust `reviewers.weight` for the bot reviewer:
- `1.00` (default) — each chat vote counts the same as one human reviewer vote
- `0.10` — 100 chat votes ≈ 10 human reviewer votes
- `2.00` — chat votes count double

No code changes needed.

---

## 5. API Reference

### Authentication

Every request must include:
```
Authorization: Bearer <FSL_VOTING_API_KEY>
```

The key is stored in `config.php` under `$config['service_api']['token']`.

`401 Unauthorized` if missing or invalid.

### Response Envelope

**Success:**
```json
{ "ok": true, "data": { ... } }
```

**Error:**
```json
{ "ok": false, "error": "ERROR_CODE", "message": "Human-readable detail" }
```

### Base URL
```
https://psistorm.com/fsl/api/voting.php
```

All endpoints are on this single file, routed by HTTP method + `action` query param.

---

### 5.1 `GET /api/voting.php?action=match&fsl_match_id={id}`

Read match details and player info. Use this before enabling a session to confirm the match and player order.

**Request:**
```
GET /api/voting.php?action=match&fsl_match_id=12345
Authorization: Bearer <key>
```

**Response 200:**
```json
{
  "ok": true,
  "data": {
    "fsl_match_id": 12345,
    "season": 10,
    "t_code": "S",
    "division": "S",
    "notes": "Week 4",
    "vod": "https://twitch.tv/videos/...",
    "player1": { "id": 101, "real_name": "DarkMenace" },
    "player2": { "id": 202, "real_name": "NukLeo" }
  }
}
```

`player1` is always `winner_player_id`, `player2` is always `loser_player_id`. This order must be used when interpreting and submitting votes.

**Errors:**

| HTTP | error | When |
|---|---|---|
| 400 | `MISSING_PARAM` | `fsl_match_id` not provided |
| 404 | `MATCH_NOT_FOUND` | No row with that ID |

---

### 5.2 `POST /api/voting.php?action=enable`

Open a 5-minute voting window for a match. Only one session can be active at a time.

**Request body (JSON):**
```json
{
  "fsl_match_id": 12345,
  "requested_by": "twitch_mod_login",
  "channel": "psistorm"
}
```

`requested_by` and `channel` are optional audit fields.

**Behavior:**
1. Verify `fsl_match_id` exists.
2. Check for any currently active session (`status = 'open'` AND `expires_at > NOW()`).
   - If one exists for the **same** `fsl_match_id`: return `200` with the existing session (idempotent).
   - If one exists for a **different** match: return `409 VOTING_ALREADY_OPEN`.
3. Insert a new `voting_sessions` row with `expires_at = NOW() + INTERVAL 5 MINUTE`.
4. Return session + match details.

**Response 200:**
```json
{
  "ok": true,
  "data": {
    "session_id": 88,
    "fsl_match_id": 12345,
    "expires_at": "2026-04-10T22:05:00Z",
    "season": 10,
    "t_code": "S",
    "player1": { "id": 101, "real_name": "DarkMenace" },
    "player2": { "id": 202, "real_name": "NukLeo" }
  }
}
```

**Errors:**

| HTTP | error | When |
|---|---|---|
| 400 | `MISSING_PARAM` | `fsl_match_id` not in body |
| 404 | `MATCH_NOT_FOUND` | No match with that ID |
| 409 | `VOTING_ALREADY_OPEN` | Different match session already active |

---

### 5.3 `GET /api/voting.php?action=active`

Check if a voting session is currently open.

**Request:**
```
GET /api/voting.php?action=active
Authorization: Bearer <key>
```

**Response 200 — session open:**
```json
{
  "ok": true,
  "data": {
    "active": true,
    "session_id": 88,
    "expires_at": "2026-04-10T22:05:00Z",
    "fsl_match_id": 12345,
    "player1": { "id": 101, "real_name": "DarkMenace" },
    "player2": { "id": 202, "real_name": "NukLeo" }
  }
}
```

**Response 200 — nothing open:**
```json
{ "ok": true, "data": { "active": false } }
```

---

### 5.4 `POST /api/voting.php?action=votes`

Submit the aggregated chat votes for all six attributes. Session must be active.

**Request body (JSON):**
```json
{
  "session_id": 88,
  "fsl_match_id": 12345,
  "reviewer_id": 7,
  "votes": {
    "micro":      { "vote": 1, "tally": { "player1": 42, "player2": 18, "tie": 3 } },
    "macro":      { "vote": 2, "tally": { "player1": 12, "player2": 55, "tie": 0 } },
    "clutch":     { "vote": 0, "tally": { "player1": 30, "player2": 31, "tie": 5 } },
    "creativity": { "vote": 1, "tally": { "player1": 60, "player2": 20, "tie": 5 } },
    "aggression": { "vote": 2, "tally": { "player1": 10, "player2": 45, "tie": 8 } },
    "strategy":   { "vote": 1, "tally": { "player1": 50, "player2": 30, "tie": 10 } }
  }
}
```

| Field | Required | Notes |
|---|---|---|
| `session_id` | Yes | From `enable` response |
| `fsl_match_id` | Yes | Cross-checked against session |
| `reviewer_id` | Yes | Must match an active reviewer in DB |
| `votes` | Yes | All 6 attributes required |
| `votes.*.vote` | Yes | `0`, `1`, or `2` |
| `votes.*.tally` | Yes | `player1`, `player2`, `tie` counts (INT ≥ 0) |

**Server behavior:**
- Verify session is still active (`status = 'open'` AND `expires_at > NOW()`).
- Verify `fsl_match_id` matches the session's match.
- Verify `reviewer_id` exists and is active.
- For each attribute: upsert into `Player_Attribute_Votes` using `ON DUPLICATE KEY UPDATE` (keyed on `reviewer_id, fsl_match_id, attribute`).
- Return `updated: true`.

**Response 200:**
```json
{ "ok": true, "data": { "updated": true, "rows_affected": 6 } }
```

**Errors:**

| HTTP | error | When |
|---|---|---|
| 400 | `MISSING_PARAM` | Required fields absent |
| 400 | `INVALID_VOTE_VALUE` | vote not in {0, 1, 2} |
| 400 | `INVALID_TALLY` | tally values negative or non-integer |
| 403 | `VOTING_CLOSED` | Session expired or doesn't exist |
| 403 | `MATCH_MISMATCH` | `fsl_match_id` doesn't match session |
| 403 | `INVALID_REVIEWER` | reviewer_id not found or inactive |

---

## 6. Bot Integration Guide

### 6.1 Setup (one-time)

1. Create a `reviewers` row for the bot:
   ```sql
   INSERT INTO reviewers (name, unique_url, weight, status)
   VALUES ('TwitchChat', 'bot-internal-not-for-web', 1.00, 'active');
   ```
   Note the `id` returned — this is your `reviewer_id` for all vote submissions.

2. Add the API key to the bot's environment/config. It's in `config.php` under `$config['service_api']['token']`.

3. Confirm the bot can reach `GET /api/voting.php?action=match&fsl_match_id=X` and get a valid response.

### 6.2 Per-match flow

```
Moderator command in chat: !allow_votes <fsl_match_id>
         │
         ▼
Bot calls GET /api/voting.php?action=match&fsl_match_id=<id>
  → Confirms player1 = <name>, player2 = <name>
  → Posts in chat: "Voting open for DarkMenace vs NukLeo! 5 minutes."
         │
         ▼
Bot calls POST /api/voting.php?action=enable
  → body: { fsl_match_id, requested_by, channel }
  → Stores session_id and expires_at locally
         │
         ▼
Bot announces voting instructions in chat (see Section 6.3)
         │
         ▼
Bot collects chat votes for ~5 minutes
  → Deduplicates per Twitch user per attribute (last vote wins)
  → Tallies player1/player2/tie counts per attribute
  → Determines majority winner per attribute (vote = 1, 2, or 0)
         │
         ▼
At expires_at (or when tally is ready):
Bot calls POST /api/voting.php?action=votes
  → Submits all 6 attributes with vote + tally
         │
         ▼
Bot posts results summary in chat (see Section 6.4)
```

### 6.3 Suggested Chat Announcement Format

```
🗳️ VOTING OPEN — DarkMenace vs NukLeo (5 minutes)

Vote who WON each attribute:
  A / B = Micro       (A=DarkMenace, B=NukLeo)
  AA / BB = Macro
  AAA / BBB = Clutch
  C / D = Creativity
  CC / DD = Aggression
  CCC / DDD = Strategy

Type TIE1, TIE2... for ties. You can change your vote anytime.
```

Notes:
- Keep it short enough for a chat announcement. Consider a `!vote` command that reposts instructions.
- The letter scheme (A/B, AA/BB, etc.) avoids ambiguity with other chat commands.
- "Last vote wins" per user per attribute — bot just overwrites the previous tally for that user.

### 6.4 Suggested Results Summary Format

After submitting votes:
```
✅ Votes submitted for DarkMenace vs NukLeo!
Micro: DarkMenace (42 vs 18, 3 ties)
Macro: NukLeo (12 vs 55, 0 ties)
Clutch: TIE (30 vs 31, 5 ties)
Creativity: DarkMenace (60 vs 20, 5 ties)
Aggression: NukLeo (10 vs 45, 8 ties)
Strategy: DarkMenace (50 vs 30, 10 ties)
```

### 6.5 Edge Cases the Bot Should Handle

| Situation | Recommended handling |
|---|---|
| Nobody votes on an attribute | Submit `vote: 0, tally: {player1: 0, player2: 0, tie: 0}` — aggregation will treat as neutral |
| Session expired before bot submits | API returns `403 VOTING_CLOSED`. Bot should log and notify mod. |
| `409 VOTING_ALREADY_OPEN` on enable | Same match: treat as success, use returned session. Different match: notify mod that another session is active. |
| `fsl_match_id` not found | Notify mod: "Match ID X not found — check and retry." |
| Network failure mid-session | Bot retries submission up to 3 times before giving up and logging locally. |

### 6.6 Checking Session Status

The bot can poll `GET /api/voting.php?action=active` to confirm the session is still open before submitting. Recommended: check once at ~30 seconds before `expires_at` as a safety check.

---

## 7. Configuration Reference

In `config.php`:

```php
$config['service_api'] = [
    'enabled'                  => true,
    'token'                    => '<FSL_VOTING_API_KEY>',
    'rate_limit'               => 100,   // requests/hour/IP
    'log_requests'             => true,
    'voting_session_ttl_mins'  => 5,     // voting window duration
];

$config['spider_chart'] = [
    'attribute_offset' => 5,   // score floor offset (keeps scores ≥ 5.00)
    'max_score'        => 10,
    'chart_min'        => 2,
    'chart_max'        => 10,
];
```

**To tune chat influence:** `UPDATE reviewers SET weight = 0.25 WHERE name = 'TwitchChat';`  
No deployment needed.

---

## 8. Migration Checklist

Before deployment, run in order:

```sql
-- 1. Add tally columns to existing votes table
ALTER TABLE Player_Attribute_Votes
    ADD COLUMN tally_player1 INT NULL AFTER vote,
    ADD COLUMN tally_player2 INT NULL AFTER tally_player1,
    ADD COLUMN tally_tie     INT NULL AFTER tally_player2;

-- 2. Create voting sessions table
CREATE TABLE voting_sessions (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    fsl_match_id  INT NOT NULL,
    enabled_by    VARCHAR(255) NULL,
    channel       VARCHAR(255) NULL,
    enabled_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at    TIMESTAMP NOT NULL,
    closed_at     TIMESTAMP NULL,
    status        ENUM('open','closed','expired') DEFAULT 'open',
    FOREIGN KEY (fsl_match_id) REFERENCES fsl_matches(fsl_match_id) ON DELETE CASCADE,
    INDEX idx_vs_status  (status),
    INDEX idx_vs_match   (fsl_match_id),
    INDEX idx_vs_expires (expires_at)
);

-- 3. Create bot reviewer entry
INSERT INTO reviewers (name, unique_url, weight, status)
VALUES ('TwitchChat', 'bot-internal-not-for-web', 1.00, 'active');

-- 4. Add voting_session_ttl_mins to config.php (manual edit)

-- 5. Add API file: /fsl/api/voting.php

-- 6. Update aggregate_scores.php to handle tally columns
```

Append steps 1–3 to `schema_changes.sql` before running.

---

## 9. What Is Out of Scope for This API

- **Score recalculation trigger** — `aggregate_scores.php` is still run manually or on a schedule. The API does not trigger it automatically (though a future endpoint could).
- **Human reviewer voting** — still handled by `spider_vote.php` with `unique_url` tokens.
- **Player name resolution** — the caller is responsible for knowing `fsl_match_id`. No fuzzy name lookup endpoint.
- **Multi-channel sessions** — one global active session at a time.
- **Vote deletion/correction** — resubmitting via `POST /votes` upserts (overwrites) existing bot votes for the same match.
