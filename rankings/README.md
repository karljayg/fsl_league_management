# FSL Rankings / Challenger Bracket

Voting, live bracket UI (`tournament.php`), and D2 diagram export (`tournament_diagram.php`).

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

- `challenger_bracket_s{100|50|25}.svg`
- `challenger_bracket_s{100|50|25}.png`
- matching `.d2` sources

Default public view: `tournament_diagram.php?action=view&format=png&scale=50`

### Permission

Regenerate / export / `.d2` source require the same rankings super permission as tournament admin edits (`RANKINGS_PERM_SUPER`). Viewing published diagrams does not.
