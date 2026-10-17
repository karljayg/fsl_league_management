# FSL Rankings / Individual Championships

Voting, live bracket UI (`tournament.php`), and D2 diagram export (`tournament_diagram.php`).

## Broadcast schedule

`tournament_broadcast.php` packs contested matches into shows (default **4**/show) with **Code B → A → S** phasing so Code S is held for anticipation. Status (ready / waiting / done) updates from live results; broadcast ordinals stay stable for diagram labels like `(4th broadcast)`.

Helpers in `tournament_logic.php`:

- `tournament_broadcast_schedule($bracket, $opts)`
- `tournament_annotate_broadcasts($bracket, $opts)` — stamps `broadcast` / `broadcast_label` / `slot` on matches
- `tournament_broadcast_label(4)` → `"4th broadcast"`
- Optional per-show dates in `tournament_state.json` → `broadcast_dates` (`"1": "2026-08-22T19:00"`, US Eastern wall time). Admins set them on `tournament.php`. Displayed as `8/22/2026 7:00 PM ET` on bracket tags, diagram labels, and the schedule page.
- Manual overrides in `broadcast_assignments` (`match_id` → broadcast#). Admins move matches between **adjacent** shows on `tournament_broadcast.php` (← / →). Target shows may exceed the default cap; empty shows are compacted/renumbered (re-check dates after moves). Moves are blocked if they would place a match before its dependencies or leave a dependent in an earlier show — winner-chain order is preserved; parallel stated (ready/live/done) side-board matches can slide.

Query: `?cap=4|5|6`, `?format=json`, `?phase=0` (allow mixing codes in one show). Admin actions: `move_broadcast_match`, `reset_broadcast_assignments` on `tournament.php`.

## Bracket diagram dependencies

PNG export renders D2 → SVG with the `d2` CLI, then converts SVG → PNG with **rsvg-convert** (preferred) or ImageMagick. Do **not** rely on d2’s built-in Playwright PNG path under Apache — the `httpd`/`apache` user cannot install drivers into `/usr/share/httpd/.cache`.

### Amazon Linux 2023 (production)

```bash
# d2 CLI → /usr/local/bin/d2
curl -fsSL https://d2lang.com/install.sh | sudo sh -s -- --prefix /usr/local

# SVG → PNG (recommended)
sudo dnf install -y librsvg2-tools

# Optional alternative to rsvg-convert
# sudo dnf install -y ImageMagick

# Writable diagram output (Apache must write here when regenerating)
sudo mkdir -p /var/www/html/psistorm/fsl/rankings/diagrams
sudo chown apache:apache /var/www/html/psistorm/fsl/rankings/diagrams
# If your PHP pool runs as ec2-user or another user, match that owner instead.
```

Verify as the web user if possible:

```bash
command -v d2 && d2 --version
command -v rsvg-convert && rsvg-convert --version
```

Regenerate from an admin session: open **Update diagram** on the live bracket, or  
`tournament_diagram.php` → **Update & open**. Public users only view already-generated files.

### macOS (local)

```bash
brew install d2 librsvg
# optional: brew install imagemagick
```

### Output files

Generated under `rankings/diagrams/` (gitignored except `.gitignore`):

- `individual_championships_bracket_s{100|50|25}.svg`
- `individual_championships_bracket_s{100|50|25}.png`
- matching `.d2` sources (legacy `challenger_bracket_s*` files still load until regenerated)

Default public view: `tournament_diagram.php?action=view&format=png&scale=50`

### Permission

Regenerate / export / `.d2` source require the same rankings super permission as tournament admin edits (`RANKINGS_PERM_SUPER`). Viewing published diagrams does not.
