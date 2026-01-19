# StarCraft 2 Team League Draft – Technical Specifications

## 1. Overview

This document defines the complete functional and technical specifications for a **StarCraft II Team League Draft Application**.

The system supports a 4‑team draft with ranked players, enforced skill distribution via ranking buckets, snake draft order, timer‑based turns with skips, and strong admin control for flexibility in edge cases.

This spec is authoritative and intended to be directly used for implementation.

---

## 2. Core Concepts

### Draft Session
A single draft instance (e.g. *FSL Team League Season 10 Draft*).

### Teams
- Exactly **4 teams** per draft session
- Teams are assigned draft positions (1–4) by the admin
- Each team accesses the draft via a **unique tokenized URL** (no login)

### Players
- Expected pool size: **36–48 players** (no hard limit enforced)
- Each player has:
  - Ranking (integer)
  - Race (T / P / Z / R)
  - Notes (free text)
  - Status (`available | drafted | hidden | ineligible`)

### Ranking Buckets (Skill Groups)
Players are grouped into buckets of 4 based on ranking:

- Bucket 1: ranks 1–4
- Bucket 2: ranks 5–8
- Bucket 3: ranks 9–12
- etc.

**Rule:**
- A team may draft **at most one player per bucket**

This rule is enforced for team picks only. Admin overrides ignore this rule.

---

## 3. Draft Order

### Admin‑Defined Order
- Admin assigns each team a **draft position** (1–4)
- This defines the Round 1 order

### Snake Draft Algorithm

Let `N = 4` teams.

- Round 1: 1 → 2 → 3 → 4
- Round 2: 4 → 3 → 2 → 1
- Round 3: 1 → 2 → 3 → 4
- Continues alternating each round

#### Pick Calculation

```
round = floor((pick_number - 1) / N) + 1
index = (pick_number - 1) % N

if round is odd:
  team = round1_order[index]
else:
  team = round1_order[(N - 1) - index]
```

- Draft order is **locked once the draft starts**

---

## 4. Draft Lifecycle

### States
- `setup`
- `live`
- `paused`
- `completed`

### Transitions
- setup → live (admin starts draft)
- live ↔ paused (admin controlled)
- live / paused → completed (admin or exhaustion)

---

## 5. Timer and Skips

### Timer Model
- Each pick has a fixed time limit (`seconds_per_pick`)
- Store a **deadline timestamp**, not ticking seconds

### Timeout Behavior
- If a team does not pick before time expires:
  - The team is **skipped**
  - No player is drafted
  - Draft advances to next pick

### Skip Reasons
- `TIMEOUT`
- `NO_ELIGIBLE_PLAYERS`
- `ADMIN_SKIP`

---

## 6. No‑Eligible‑Pick Handling

If a team is on the clock and:
- Available players exist
- BUT all remaining players violate the bucket rule for that team

Then:
- The team is immediately skipped (`NO_ELIGIBLE_PLAYERS`)
- Draft advances automatically

This may chain across multiple teams.

### Important Constraint
- **The draft does NOT auto‑complete** due to bucket deadlocks
- The draft only auto‑completes when:

```
available_players_count == 0
```

Odd or deadlocked states are expected and resolved by admin intervention.

---

## 7. Draft Completion

### Automatic Completion
- Draft completes automatically **only when no players remain available**

### Manual Completion
- Admin may end the draft at any time
- Typically used when bucket rules cause unresolved leftovers

---

## 8. Admin Control (Authoritative)

Admin has full control over the draft.

### Admin Actions
- Start draft
- Pause draft
- Resume draft (timer resets to full duration)
- Restart timer
- Force skip current team
- Undo last event (pick or skip)
- End draft

### Undo Rules
- Only the **most recent event** may be undone
- Undo restores:
  - Player availability (if applicable)
  - Bucket usage
  - Pick number
  - Current team

---

## 9. Manual Player Assignment (Admin Override)

### Purpose
Allows flexibility when drafts end in odd or blocked states.

### Rules
- Admin may **manually assign players to teams**
- Only allowed when draft is **paused or completed**
- Bucket rules and turn order are **ignored**
- Assigned players are marked as `drafted`

### Assignment Representation
Manual assignments are stored as draft events:
- `result = ADMIN_ASSIGN`
- Included in history and audit log

---

## 10. Authentication Model

### Team Access
- Each team receives a **unique tokenized URL**
- No username/password
- Token grants access to **only that team**

### Token Rules
- High entropy random tokens
- Tokens can be rotated by admin at any time
- Old tokens immediately invalid

### Admin Access
- Separate admin token or auth mechanism
- Admin tokens never exposed publicly

---

## 11. Views

### Public View (Read‑Only)
- Draft status (live / paused / completed)
- On‑the‑clock team and countdown
- Team rosters
- Pick history (including skips)
- Available player list

### Team View
- All public data
- Highlighted "your team"
- Draft button enabled only on their turn
- Only legal players selectable

### Admin View
- Team management (names, tokens)
- Draft order configuration
- Player pool editing/import
- Draft controls (pause, resume, skip, undo)
- Manual assignment UI
- Audit log

---

## 12. Data Model (Relational)

### draft_sessions
- id
- name
- status
- seconds_per_pick
- current_pick_number
- current_team_id
- pick_deadline_at

### teams
- id
- draft_session_id
- name
- draft_position
- token_hash

### players
- id
- draft_session_id
- display_name
- ranking
- bucket_index
- race
- notes
- status

### picks / events
- id
- draft_session_id
- pick_number (nullable for admin assign)
- team_id
- player_id (nullable for skips)
- result (`PICK | SKIP | ADMIN_ASSIGN`)
- skip_reason (nullable)
- made_by (`TEAM | ADMIN | SYSTEM`)
- made_at
- note (optional)

### audit_log
- id
- draft_session_id
- actor_type
- action
- payload
- created_at

---

## 13. Concurrency and Integrity

- All pick/skip/undo operations must be atomic
- Draft session row must be locked during advancement
- Constraints:
  - One pick per pick number
  - One drafted instance per player
  - One bucket per team (team picks only)

---

## 14. Design Philosophy

- Deterministic draft logic
- Strong server‑side enforcement
- Flexible admin overrides
- Full auditability
- Simple, understandable rules for teams and spectators

---

## 15. File Structure

```
draft/
├── admin/          # Admin view: team/player management, draft controls, audit log
├── public/         # Public read-only view: spectator page
├── team/           # Team view: pick interface accessed via token URL
├── includes/       # Shared PHP: draft logic, data access, utilities
├── ajax/           # AJAX endpoints: picks, admin actions, state updates
├── css/            # Draft-specific stylesheets
├── js/             # Draft-specific JavaScript
└── data/           # JSON data files (see §16)
```

---

## 16. Data Storage (JSON Files)

Instead of database tables, draft data is stored in plain JSON files in `draft/data/`.

### File Structure

```
draft/data/
├── session.json        # Current draft session state
├── teams.json          # Team definitions and tokens
├── players.json        # Player pool
├── events.json         # Pick/skip history (append-only)
└── audit.json          # Audit log (append-only)
```

### session.json
```json
{
  "id": "fsl-s10",
  "name": "FSL Season 10 Draft",
  "status": "setup",
  "seconds_per_pick": 120,
  "current_pick_number": 1,
  "current_team_id": null,
  "pick_deadline_at": null,
  "draft_order": [1, 2, 3, 4]
}
```

### teams.json
```json
[
  { "id": 1, "name": "Team Alpha", "draft_position": 1, "token": "abc123..." },
  { "id": 2, "name": "Team Beta", "draft_position": 2, "token": "def456..." }
]
```

### players.json
```json
[
  { "id": 1, "display_name": "PlayerOne", "ranking": 1, "bucket_index": 1, "race": "T", "notes": "", "status": "available" }
]
```

### events.json
```json
[
  { "id": 1, "pick_number": 1, "team_id": 1, "player_id": 5, "result": "PICK", "made_by": "TEAM", "made_at": "2025-01-11T20:00:00Z" }
]
```

### Concurrency Note
- File locking required for writes
- Read-modify-write pattern with exclusive lock

---

## 17. Authentication

### Team Access
- Token in URL: `draft/team/?token=abc123`
- Token validated against `teams.json`

### Admin Access
- Simple token-based: `draft/admin/?token=admin-secret`
- Admin token stored in `session.json` or hardcoded
- Not public, but guessable is acceptable

---

**End of Specification**

