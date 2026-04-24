<?php
session_start();

// Database connection
require_once 'includes/db.php';
require_once __DIR__ . '/includes/team_logo.php';
require_once __DIR__ . '/includes/formatting_utils.php';
require_once __DIR__ . '/includes/profile_bio_social.php';
require_once __DIR__ . '/includes/championship_json_processor.php';

// Connect to database
try {
    $db = new PDO("mysql:host={$db_host};dbname={$db_name}", $db_user, $db_pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Initialize login state
$isLoggedIn = isset($_SESSION['user_id']);
$loggedInUsername = $isLoggedIn ? $_SESSION['username'] : null;

// Determine which profile to show (?user= handles short URLs; ?username= kept for existing links)
$requestedUsername = $loggedInUsername;
if (!empty($_GET['user'])) {
    $requestedUsername = trim((string) $_GET['user']);
} elseif (!empty($_GET['username'])) {
    $requestedUsername = trim((string) $_GET['username']);
}

// If not logged in and no username specified, redirect to login
if (!$requestedUsername) {
    header('Location: login.php');
    exit;
}

// Get user data
$stmt = $db->prepare('SELECT id, username, email, role, mmr, race_preference, avatar_url, bio, social_links FROM users WHERE username = ?');
$stmt->execute([$requestedUsername]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// If user not found, show error
if (!$user) {
    $error = 'User not found';
}

// FSL league profile (Players.User_ID → users.id); teaser only — full detail on view_player.php
$linkedFslPlayer = null;
$fslStatTotals = null;
$fslPrimaryStat = null;
$fslAliases = [];
$fslRecentMatches = [];
$forumRecentPosts = [];

if ($user) {
    try {
        $stmt = $db->prepare(
            'SELECT p.Player_ID, p.Real_Name, p.Status, p.Team_ID,
                    p.Championship_Record,
                    p.TeamLeague_Championship_Record,
                    t.Team_Name,
                    CASE
                        WHEN t.Captain_ID = p.Player_ID THEN \'Captain\'
                        WHEN t.Co_Captain_ID = p.Player_ID THEN \'Co-Captain\'
                        ELSE NULL
                    END AS Team_Role
             FROM Players p
             LEFT JOIN Teams t ON p.Team_ID = t.Team_ID
             WHERE p.User_ID = ?
             LIMIT 1'
        );
        $stmt->execute([$user['id']]);
        $linkedFslPlayer = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($linkedFslPlayer) {
            $pid = (int) $linkedFslPlayer['Player_ID'];

            $sumStmt = $db->prepare(
                'SELECT COALESCE(SUM(fs.MapsW), 0) AS mw, COALESCE(SUM(fs.MapsL), 0) AS ml,
                        COALESCE(SUM(fs.SetsW), 0) AS sw, COALESCE(SUM(fs.SetsL), 0) AS sl
                 FROM FSL_STATISTICS fs
                 WHERE fs.Player_ID = ?'
            );
            $sumStmt->execute([$pid]);
            $fslStatTotals = $sumStmt->fetch(PDO::FETCH_ASSOC);

            $primStmt = $db->prepare(
                'SELECT fs.Division, fs.Race, (fs.MapsW + fs.MapsL) AS map_games
                 FROM FSL_STATISTICS fs
                 WHERE fs.Player_ID = ?
                 ORDER BY (fs.MapsW + fs.MapsL) DESC, FIELD(fs.Division, \'S\', \'A\', \'B\'), fs.Race
                 LIMIT 1'
            );
            $primStmt->execute([$pid]);
            $fslPrimaryStat = $primStmt->fetch(PDO::FETCH_ASSOC) ?: null;

            $aliasStmt = $db->prepare(
                'SELECT Alias_Name FROM Player_Aliases WHERE Player_ID = ?
                 ORDER BY Alias_Name ASC LIMIT 10'
            );
            $aliasStmt->execute([$pid]);
            $fslAliases = $aliasStmt->fetchAll(PDO::FETCH_COLUMN);

            try {
                $fslMatchStmt = $db->prepare(
                    'SELECT fm.*,
                            p_w.Real_Name AS winner_name,
                            p_l.Real_Name AS loser_name
                     FROM fsl_matches fm
                     JOIN Players p_w ON fm.winner_player_id = p_w.Player_ID
                     JOIN Players p_l ON fm.loser_player_id = p_l.Player_ID
                     WHERE fm.winner_player_id = ? OR fm.loser_player_id = ?
                     ORDER BY fm.fsl_match_id DESC
                     LIMIT 5'
                );
                $fslMatchStmt->execute([$pid, $pid]);
                $fslRecentMatches = $fslMatchStmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                error_log('profile.php FSL recent matches: ' . $e->getMessage());
                $fslRecentMatches = [];
            }
        }
    } catch (PDOException $e) {
        error_log('profile.php FSL link query: ' . $e->getMessage());
    }

    try {
        // Forum app uses forum/config.php → forumDB; main site uses psistorm. Query the forum schema.
        $forumDb = new PDO(
            "mysql:host={$db_host};dbname={$forum_db_name}",
            $db_user,
            $db_pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $forumStmt = $forumDb->prepare(
            'SELECT ft.id, ft.subject, ft.date, ft.parent,
                    COALESCE(NULLIF(ft.mainthread, 0), ft.id) AS thread_id,
                    tp.subject AS thread_root_subject,
                    f.title AS forum_title
             FROM forumthreads ft
             LEFT JOIN forumthreads tp ON tp.id = COALESCE(NULLIF(ft.mainthread, 0), ft.id)
             LEFT JOIN forums f ON ft.forum = f.id
             WHERE LOWER(TRIM(ft.author)) = LOWER(TRIM(?))
                OR CAST(ft.site_user_id AS CHAR) = CAST(? AS CHAR)
             ORDER BY ft.date DESC
             LIMIT 10'
        );
        $forumStmt->execute([$user['username'], (string) $user['id']]);
        $forumRecentPosts = $forumStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('profile.php forum posts: ' . $e->getMessage());
        $forumRecentPosts = [];
    }
}

// Get pro's scheduled matches (if they're a pro)
$scheduledMatches = [];
if ($user && $user['role'] === 'pro') {
    $stmt = $db->prepare('
        SELECT m.id, m.title, m.description, m.date, m.time, m.match_type, m.min_bid, m.status,
               COUNT(b.id) as bid_count
        FROM matches m
        LEFT JOIN bids b ON m.id = b.match_id
        WHERE m.pro_id = ?
        GROUP BY m.id
        ORDER BY m.date ASC, m.time ASC
    ');
    $stmt->execute([$user['id']]);
    $scheduledMatches = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Check if viewing own profile
$isOwnProfile = $isLoggedIn && $loggedInUsername === $requestedUsername;

// Set page title
$pageTitle = htmlspecialchars($requestedUsername) . "'s Profile";

// Include header
include_once 'includes/header.php';
?>

<section class="profile-section">
    <?php if (isset($error)): ?>
        <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
    <?php else: ?>
        <div class="profile-header">
            <div class="profile-avatar">
                <img src="<?php echo !empty($user['avatar_url']) ? htmlspecialchars($user['avatar_url']) : 'images/default-avatar-silhouette.svg'; ?>" alt="<?php echo htmlspecialchars($user['username']); ?>">
            </div>
            <div class="profile-info">
                <h1><?php echo htmlspecialchars($user['username']); ?></h1>
                <div class="profile-details">
                    <span class="profile-role <?php echo $user['role']; ?>"><?php echo ucfirst($user['role']); ?></span>
                    <?php if ($user['mmr']): ?>
                        <span class="profile-mmr">MMR: <?php echo htmlspecialchars($user['mmr']); ?></span>
                    <?php endif; ?>
                    <?php if ($user['race_preference']): ?>
                        <span class="profile-race">Race: <?php echo htmlspecialchars($user['race_preference']); ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($isOwnProfile): ?>
                <a href="edit_profile.php" class="edit-profile-btn">Edit Profile</a>
            <?php endif; ?>
        </div>

        <?php
        $rawSocialDisplay = $user['social_links'] ?? null;
        if (is_array($rawSocialDisplay)) {
            $rawSocialDisplay = json_encode($rawSocialDisplay, JSON_UNESCAPED_UNICODE);
        }
        $profileSocialRows = profile_parse_social_json(is_string($rawSocialDisplay) ? $rawSocialDisplay : null);
        $hasBio = trim((string) ($user['bio'] ?? '')) !== '';
        ?>
        <?php if ($hasBio || $profileSocialRows !== []): ?>
            <div class="profile-about card border-0 mb-4" style="background:rgba(255,255,255,0.08);border-radius:12px;padding:1.25rem 1.5rem;max-width:720px;margin-left:auto;margin-right:auto;">
                <?php if ($hasBio): ?>
                    <h2 class="h6 text-uppercase mb-2" style="color:#00d4ff;letter-spacing:.04em;">About</h2>
                    <div class="profile-bio-text" style="color:#e8e8e8;white-space:pre-wrap;line-height:1.5;"><?php echo nl2br(htmlspecialchars(trim((string) $user['bio']), ENT_QUOTES, 'UTF-8')); ?></div>
                <?php endif; ?>
                <?php if ($profileSocialRows !== []): ?>
                    <?php if ($hasBio): ?><hr style="border-color:rgba(255,255,255,0.12);margin:1rem 0;"><?php endif; ?>
                    <div class="profile-social-row">
                        <span class="profile-social-row-label">Social Media</span>
                        <div class="profile-social-icon-list">
                            <?php foreach ($profileSocialRows as $srow): ?>
                                <?php
                                $stype = $srow['type'];
                                $sval = $srow['value'];
                                $slabel = PROFILE_SOCIAL_TYPES[$stype] ?? $stype;
                                $shref = profile_social_href($stype, $sval);
                                $iconSrc = profile_social_icon_web_path($stype);
                                $a11y = profile_text_truncate($slabel . ': ' . $sval, 220);
                                ?>
                                <?php if ($shref !== ''): ?>
                                    <a href="<?php echo htmlspecialchars($shref, ENT_QUOTES, 'UTF-8'); ?>" class="profile-social-icon-link" rel="noopener noreferrer" target="_blank" aria-label="<?php echo htmlspecialchars($a11y, ENT_QUOTES, 'UTF-8'); ?>" title="<?php echo htmlspecialchars($a11y, ENT_QUOTES, 'UTF-8'); ?>">
                                        <img src="<?php echo htmlspecialchars($iconSrc, ENT_QUOTES, 'UTF-8'); ?>" alt="" width="29" height="29" loading="lazy" decoding="async" aria-hidden="true">
                                    </a>
                                <?php else: ?>
                                    <span class="profile-social-icon-static" aria-label="<?php echo htmlspecialchars($a11y, ENT_QUOTES, 'UTF-8'); ?>" title="<?php echo htmlspecialchars($a11y, ENT_QUOTES, 'UTF-8'); ?>">
                                        <img src="<?php echo htmlspecialchars($iconSrc, ENT_QUOTES, 'UTF-8'); ?>" alt="" width="29" height="29" loading="lazy" decoding="async" aria-hidden="true">
                                    </span>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($linkedFslPlayer): ?>
            <?php
            $mw = (int) ($fslStatTotals['mw'] ?? 0);
            $ml = (int) ($fslStatTotals['ml'] ?? 0);
            $sw = (int) ($fslStatTotals['sw'] ?? 0);
            $sl = (int) ($fslStatTotals['sl'] ?? 0);
            $mapPlayed = $mw + $ml;
            $setPlayed = $sw + $sl;
            $mapWinPct = $mapPlayed > 0 ? round(($mw / $mapPlayed) * 100, 1) : null;
            $setWinPct = $setPlayed > 0 ? round(($sw / $setPlayed) * 100, 1) : null;
            $vn = $linkedFslPlayer['Real_Name'];
            $aliasesForTeaser = array_values(array_filter(
                $fslAliases,
                static function ($a) use ($vn) {
                    return strcasecmp((string) $a, (string) $vn) !== 0;
                }
            ));
            $fslPlayerUrlName = rawurlencode($linkedFslPlayer['Real_Name']);
            $teamLogoPath = !empty($linkedFslPlayer['Team_Name']) ? getTeamLogo($linkedFslPlayer['Team_Name']) : null;
            $primaryRaceCode = $fslPrimaryStat['Race'] ?? null;
            $primaryRaceIcon = ($primaryRaceCode && getRaceIconFromCode($primaryRaceCode)) ? getRaceIconFromCode($primaryRaceCode) : '';
            $teamRoleSlug = !empty($linkedFslPlayer['Team_Role'])
                ? strtolower(str_replace(' ', '-', $linkedFslPlayer['Team_Role']))
                : '';
            $fslChampPersonalRaw = $linkedFslPlayer['Championship_Record'] ?? '';
            if (is_array($fslChampPersonalRaw)) {
                $fslChampPersonalRaw = json_encode($fslChampPersonalRaw, JSON_UNESCAPED_UNICODE) ?: '';
            }
            $fslChampPersonalRaw = (string) $fslChampPersonalRaw;
            $fslChampTeamRaw = $linkedFslPlayer['TeamLeague_Championship_Record'] ?? '';
            if (is_array($fslChampTeamRaw)) {
                $fslChampTeamRaw = json_encode($fslChampTeamRaw, JSON_UNESCAPED_UNICODE) ?: '';
            }
            $fslChampTeamRaw = (string) $fslChampTeamRaw;
            $fslChampPersonalShow = $fslChampPersonalRaw !== '' && $fslChampPersonalRaw !== 'None' && $fslChampPersonalRaw !== 'null';
            $fslChampTeamShow = $fslChampTeamRaw !== '' && $fslChampTeamRaw !== 'None' && $fslChampTeamRaw !== 'null';
            ?>
            <div class="profile-fsl-teaser">
                <div class="profile-container">
                    <div class="player-info">
                        <div class="fsl-teaser-heading">
                            <h2>FSL snapshot</h2>
                            <p class="fsl-teaser-sub">Division / race shown are the stat line with the most maps played (same idea as the full player page).</p>
                        </div>
                        <?php if ($fslChampPersonalShow || $fslChampTeamShow): ?>
                            <div class="profile-fsl-trophy-showcase">
                                <h3 class="profile-fsl-trophy-heading">Championships</h3>
                                <div class="championship-container">
                                    <?php if ($fslChampPersonalShow): ?>
                                        <div class="info-item championship-record">
                                            <label>Championship record</label>
                                            <span><?php echo processChampionshipJSON($fslChampPersonalRaw, 2); ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($fslChampTeamShow): ?>
                                        <div class="info-item championship-record">
                                            <label>Team championship record</label>
                                            <span><?php echo processChampionshipJSON($fslChampTeamRaw, 2); ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        <div class="info-grid">
                            <div class="info-item team-info-item">
                                <label>FSL name</label>
                                <span class="team-display">
                                    <a href="view_player.php?name=<?php echo $fslPlayerUrlName; ?>" class="player-link player-link--emphasis"><?php echo htmlspecialchars($linkedFslPlayer['Real_Name'], ENT_QUOTES, 'UTF-8'); ?></a>
                                    <?php if (!empty($linkedFslPlayer['Team_Role'])): ?>
                                        <span class="team-role-badge <?php echo htmlspecialchars($teamRoleSlug, ENT_QUOTES, 'UTF-8'); ?>">
                                            <?php echo htmlspecialchars($linkedFslPlayer['Team_Role'], ENT_QUOTES, 'UTF-8'); ?>
                                        </span>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div class="info-item team-info-item">
                                <label>Current Team</label>
                                <span class="team-display">
                                    <?php if (!empty($linkedFslPlayer['Team_Name'])): ?>
                                        <?php if ($teamLogoPath): ?>
                                            <a href="view_team.php?name=<?php echo urlencode($linkedFslPlayer['Team_Name']); ?>">
                                                <img src="<?php echo htmlspecialchars($teamLogoPath, ENT_QUOTES, 'UTF-8'); ?>" alt="" class="player-team-logo" width="48" height="48">
                                            </a>
                                        <?php endif; ?>
                                        <a href="view_team.php?name=<?php echo urlencode($linkedFslPlayer['Team_Name']); ?>" class="team-link"><?php echo htmlspecialchars($linkedFslPlayer['Team_Name'], ENT_QUOTES, 'UTF-8'); ?></a>
                                    <?php else: ?>
                                        <span class="fsl-teaser-muted">None</span>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div class="info-item stats-row">
                                <div class="stats-row-division">
                                    <div class="info-item">
                                        <label>Division</label>
                                        <span><?php echo ($fslPrimaryStat && !empty($fslPrimaryStat['Division'])) ? 'Code ' . htmlspecialchars($fslPrimaryStat['Division'], ENT_QUOTES, 'UTF-8') : 'N/A'; ?></span>
                                    </div>
                                    <?php if (!empty($linkedFslPlayer['Status'])): ?>
                                        <div class="info-item">
                                            <label>Status</label>
                                            <span><?php echo htmlspecialchars($linkedFslPlayer['Status'], ENT_QUOTES, 'UTF-8'); ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="stats-row-race">
                                    <div class="info-item">
                                        <label>Race</label>
                                        <span class="fsl-teaser-race-line">
                                            <?php if ($primaryRaceCode && $primaryRaceIcon): ?>
                                                <img src="<?php echo htmlspecialchars($primaryRaceIcon, ENT_QUOTES, 'UTF-8'); ?>" alt="" class="race-icon" title="<?php echo htmlspecialchars(getRaceNameFromCode($primaryRaceCode), ENT_QUOTES, 'UTF-8'); ?>">
                                                <?php echo htmlspecialchars(getRaceNameFromCode($primaryRaceCode), ENT_QUOTES, 'UTF-8'); ?>
                                            <?php elseif ($primaryRaceCode): ?>
                                                <?php echo htmlspecialchars(getRaceNameFromCode($primaryRaceCode), ENT_QUOTES, 'UTF-8'); ?>
                                            <?php else: ?>
                                                N/A
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <div class="info-item stats-row">
                                <div class="info-item stats-row-maps">
                                    <label>Total Games (maps)</label>
                                    <span><?php echo $mw; ?>-<?php echo $ml; ?><?php if ($mapWinPct !== null): ?> (<?php echo htmlspecialchars((string) $mapWinPct, ENT_QUOTES, 'UTF-8'); ?>%)<?php endif; ?></span>
                                </div>
                                <div class="info-item">
                                    <label>Total Sets</label>
                                    <span><?php echo $sw; ?>-<?php echo $sl; ?><?php if ($setWinPct !== null): ?> (<?php echo htmlspecialchars((string) $setWinPct, ENT_QUOTES, 'UTF-8'); ?>%)<?php endif; ?></span>
                                </div>
                            </div>
                            <?php if (!empty($aliasesForTeaser)): ?>
                                <div class="info-item">
                                    <label>Aliases</label>
                                    <span><?php echo htmlspecialchars(implode(', ', array_slice($aliasesForTeaser, 0, 10)), ENT_QUOTES, 'UTF-8'); ?><?php if (count($aliasesForTeaser) > 10): ?>…<?php endif; ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="view-all-matches fsl-teaser-footer">
                            <a href="view_player.php?name=<?php echo $fslPlayerUrlName; ?>" class="view-all-link">Full FSL profile</a>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($forumRecentPosts)): ?>
            <div class="profile-fsl-teaser profile-forum-teaser">
                <div class="profile-container">
                    <div class="player-info">
                        <div class="fsl-teaser-heading">
                            <h2>Recent forum posts</h2>
                            <p class="fsl-teaser-sub">Latest 10 posts by this account (new topics and replies).</p>
                        </div>
                        <ul class="profile-forum-list">
                            <?php foreach ($forumRecentPosts as $fp): ?>
                                <?php
                                $postId = (int) $fp['id'];
                                $subj = trim((string) ($fp['subject'] ?? ''));
                                if ($subj === '') {
                                    $subj = 'Post #' . $postId;
                                }
                                $isReply = isset($fp['parent']) && (int) $fp['parent'] !== -1;
                                $rootSubj = (string) ($fp['thread_root_subject'] ?? '');
                                if ($rootSubj !== '') {
                                    if (function_exists('mb_strlen') && mb_strlen($rootSubj) > 68) {
                                        $rootSubj = mb_substr($rootSubj, 0, 65) . '…';
                                    } elseif (strlen($rootSubj) > 68) {
                                        $rootSubj = substr($rootSubj, 0, 65) . '…';
                                    }
                                }
                                ?>
                                <li class="profile-forum-item">
                                    <a class="profile-forum-postlink" href="forum/index.php?postid=<?php echo $postId; ?>"><?php echo htmlspecialchars($subj, ENT_QUOTES, 'UTF-8'); ?></a>
                                    <div class="profile-forum-meta">
                                        <span class="profile-forum-date"><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($fp['date'])), ENT_QUOTES, 'UTF-8'); ?></span>
                                        <?php if (!empty($fp['forum_title'])): ?>
                                            <span class="profile-forum-sep">·</span>
                                            <span><?php echo htmlspecialchars($fp['forum_title'], ENT_QUOTES, 'UTF-8'); ?></span>
                                        <?php endif; ?>
                                        <?php if ($isReply && $rootSubj !== ''): ?>
                                            <span class="profile-forum-sep">·</span>
                                            <span class="profile-forum-thread">Thread: <?php echo htmlspecialchars($rootSubj, ENT_QUOTES, 'UTF-8'); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <div class="view-all-matches fsl-teaser-footer">
                            <a href="forum/index.php" class="view-all-link">Open forum</a>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($linkedFslPlayer || !empty($forumRecentPosts)): ?>
            <style>
                .profile-fsl-teaser {
                    margin-bottom: 1.5rem;
                    color: #e0e0e0;
                }
                .profile-fsl-teaser .profile-container {
                    display: grid;
                    gap: 2rem;
                    padding: 0;
                }
                .profile-fsl-teaser .player-info {
                    background: rgba(255, 255, 255, 0.1);
                    border-radius: 10px;
                    padding: 20px;
                    box-shadow: 0 5px 20px rgba(0, 0, 0, 0.4);
                }
                .profile-fsl-teaser .fsl-teaser-heading {
                    margin-bottom: 20px;
                }
                .profile-fsl-teaser .fsl-teaser-heading h2 {
                    color: #00d4ff;
                    margin: 0 0 8px 0;
                    font-size: 1.8em;
                }
                .profile-fsl-teaser .fsl-teaser-sub {
                    margin: 0;
                    font-size: 0.9em;
                    color: rgba(255, 255, 255, 0.65);
                    line-height: 1.4;
                }
                .profile-fsl-teaser .info-grid {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
                    gap: 1rem;
                }
                .profile-fsl-teaser .info-item {
                    padding: 10px;
                    background: rgba(0, 0, 0, 0.2);
                    border-radius: 8px;
                }
                .profile-fsl-teaser .info-item label {
                    display: block;
                    color: #00d4ff;
                    font-weight: 600;
                    margin-bottom: 5px;
                }
                .profile-fsl-teaser .info-item span,
                .profile-fsl-teaser .fsl-teaser-race-line {
                    color: #e0e0e0;
                }
                .profile-fsl-teaser .fsl-teaser-muted {
                    color: rgba(255, 255, 255, 0.5);
                }
                .profile-fsl-teaser .team-info-item {
                    grid-column: 1 / -1;
                }
                .profile-fsl-teaser .stats-row {
                    grid-column: 1 / -1;
                    display: flex;
                    align-items: center;
                    gap: 1.5rem;
                    flex-wrap: wrap;
                }
                .profile-fsl-teaser .stats-row-division,
                .profile-fsl-teaser .stats-row-race {
                    display: flex;
                    align-items: center;
                    gap: 8px;
                    flex-wrap: wrap;
                }
                .profile-fsl-teaser .stats-row-division .info-item label,
                .profile-fsl-teaser .stats-row-race .info-item label {
                    display: inline;
                    margin-right: 4px;
                    margin-bottom: 0;
                }
                .profile-fsl-teaser .stats-row-maps label {
                    display: block;
                    margin-bottom: 5px;
                }
                .profile-fsl-teaser .team-display {
                    display: flex;
                    align-items: center;
                    gap: 12px;
                    flex-wrap: wrap;
                }
                .profile-fsl-teaser .player-team-logo {
                    width: 48px;
                    height: 48px;
                    border-radius: 8px;
                    object-fit: cover;
                    border: 2px solid rgba(255, 111, 97, 0.5);
                }
                .profile-fsl-teaser .race-icon {
                    width: 24px;
                    height: 24px;
                    vertical-align: middle;
                    margin-right: 6px;
                }
                .profile-fsl-teaser .team-link {
                    color: #ff6f61;
                    text-decoration: none;
                    transition: all 0.3s ease;
                }
                .profile-fsl-teaser .team-link:hover {
                    color: #ff8577;
                    text-shadow: 0 0 5px #ff6f61;
                }
                .profile-fsl-teaser .player-link {
                    color: #e0e0e0;
                    text-decoration: none;
                    transition: all 0.3s ease;
                }
                .profile-fsl-teaser .player-link:hover {
                    color: #00d4ff;
                    text-shadow: 0 0 5px #00d4ff;
                }
                .profile-fsl-teaser .player-link--emphasis {
                    color: #00d4ff;
                    font-weight: 700;
                    font-size: 1.15em;
                }
                .profile-fsl-teaser .team-role-badge {
                    display: inline-block;
                    padding: 3px 8px;
                    margin-left: 4px;
                    border-radius: 4px;
                    font-size: 0.8em;
                    font-weight: bold;
                    color: white;
                }
                .profile-fsl-teaser .team-role-badge.captain {
                    background-color: #ffc107;
                    color: #000;
                }
                .profile-fsl-teaser .team-role-badge.co-captain {
                    background-color: #6c757d;
                }
                .profile-fsl-teaser .profile-fsl-trophy-showcase {
                    margin: 0 0 1.35rem 0;
                    padding: 1.1rem 1.15rem 1.2rem;
                    background: linear-gradient(165deg, rgba(0, 212, 255, 0.12) 0%, rgba(0, 0, 0, 0.35) 55%, rgba(255, 111, 97, 0.08) 100%);
                    border: 1px solid rgba(0, 212, 255, 0.28);
                    border-radius: 12px;
                    box-shadow: 0 4px 24px rgba(0, 0, 0, 0.35);
                }
                .profile-fsl-teaser .profile-fsl-trophy-heading {
                    margin: 0 0 0.75rem 0;
                    font-size: 1.15em;
                    font-weight: 700;
                    color: #00d4ff;
                    letter-spacing: 0.03em;
                    text-transform: uppercase;
                }
                .profile-fsl-teaser .championship-container {
                    display: flex;
                    gap: 1rem;
                    flex-wrap: wrap;
                    align-items: flex-start;
                    justify-content: flex-start;
                }
                .profile-fsl-teaser .championship-record {
                    flex: 1;
                    min-width: 260px;
                    max-width: calc(50% - 0.5rem);
                    padding: 15px;
                    font-size: 0.9em;
                    line-height: 1.4;
                    white-space: normal;
                    overflow-wrap: break-word;
                    background-color: rgba(0, 0, 0, 0.2);
                    border-radius: 8px;
                }
                .profile-fsl-teaser .championship-record label {
                    display: block;
                    color: #00d4ff;
                    font-weight: 600;
                    margin-bottom: 5px;
                }
                .profile-fsl-teaser .championship-record span {
                    display: block;
                    white-space: pre-line;
                    color: #e0e0e0;
                }
                @media (max-width: 768px) {
                    .profile-fsl-teaser .championship-record {
                        max-width: 100%;
                    }
                }
                .profile-fsl-teaser .view-all-matches {
                    margin-top: 20px;
                    text-align: center;
                    padding-top: 15px;
                    border-top: 1px solid rgba(255, 255, 255, 0.1);
                }
                .profile-fsl-teaser .view-all-link {
                    display: inline-block;
                    color: #00d4ff;
                    text-decoration: none;
                    padding: 12px 24px;
                    border: 2px solid #00d4ff;
                    border-radius: 8px;
                    font-weight: 600;
                    font-size: 1.05em;
                    transition: all 0.3s ease;
                    background: rgba(0, 212, 255, 0.1);
                    box-shadow: 0 2px 10px rgba(0, 212, 255, 0.2);
                }
                .profile-fsl-teaser .view-all-link:hover {
                    background: rgba(0, 212, 255, 0.2);
                    color: #ffffff;
                    text-shadow: 0 0 8px #00d4ff;
                    box-shadow: 0 4px 20px rgba(0, 212, 255, 0.4);
                }
                .profile-fsl-teaser .profile-forum-list {
                    list-style: none;
                    padding: 0;
                    margin: 0;
                }
                .profile-fsl-teaser .profile-forum-item {
                    padding: 14px 10px;
                    border-bottom: 1px solid rgba(255, 255, 255, 0.08);
                }
                .profile-fsl-teaser .profile-forum-item:last-child {
                    border-bottom: none;
                }
                .profile-fsl-teaser .profile-forum-postlink {
                    color: #ff6f61;
                    font-weight: 600;
                    text-decoration: none;
                    display: inline-block;
                    margin-bottom: 6px;
                    font-size: 1.05em;
                }
                .profile-fsl-teaser .profile-forum-postlink:hover {
                    color: #ff8577;
                    text-shadow: 0 0 5px #ff6f61;
                }
                .profile-fsl-teaser .profile-forum-meta {
                    font-size: 0.88em;
                    color: rgba(255, 255, 255, 0.55);
                    line-height: 1.45;
                }
                .profile-fsl-teaser .profile-forum-sep {
                    margin: 0 6px;
                    color: rgba(255, 255, 255, 0.35);
                }
                .profile-fsl-teaser .profile-forum-thread {
                    word-break: break-word;
                }
                @media (max-width: 768px) {
                    .profile-fsl-teaser .stats-row {
                        flex-direction: column;
                        align-items: stretch;
                    }
                    .profile-fsl-teaser .fsl-teaser-heading h2 {
                        font-size: 1.5em;
                    }
                }
            </style>
        <?php endif; ?>

        <?php if ($user['role'] === 'user'): ?>
            <?php if ($linkedFslPlayer): ?>
            <div class="profile-content">
                <?php
                $fslRealName = (string) $linkedFslPlayer['Real_Name'];
                require __DIR__ . '/includes/profile_fsl_recent_matches.php';
                ?>
            </div>
            <?php endif; ?>
        <?php else: ?>
            <!-- Pro Player Profile Content -->
            <div class="profile-content">
                <?php if ($linkedFslPlayer): ?>
                    <?php
                    $fslRealName = (string) $linkedFslPlayer['Real_Name'];
                    require __DIR__ . '/includes/profile_fsl_recent_matches.php';
                    ?>
                <?php endif; ?>
                <h2>Scheduled Matches</h2>
                <?php if (empty($scheduledMatches)): ?>
                    <p class="no-data">No scheduled matches found.</p>
                <?php else: ?>
                    <div class="scheduled-matches">
                        <?php foreach ($scheduledMatches as $match): ?>
                            <div class="match-card">
                                <div class="match-card-header">
                                    <div class="match-title"><?php echo htmlspecialchars($match['title']); ?></div>
                                    <div class="match-date">
                                        <?php echo date('M d, Y', strtotime($match['date'])); ?> at 
                                        <?php echo date('g:i A', strtotime($match['time'])); ?>
                                    </div>
                                </div>
                                <div class="match-details">
                                    <p><?php echo htmlspecialchars($match['description'] ?: 'No description available'); ?></p>
                                    <span class="match-type"><?php echo htmlspecialchars($match['match_type']); ?></span>
                                    <span class="match-status <?php echo $match['status']; ?>"><?php echo ucfirst($match['status']); ?></span>
                                </div>
                                <div class="match-stats">
                                    <div class="min-bid">Minimum Bid: $<?php echo htmlspecialchars($match['min_bid']); ?></div>
                                    <div class="bid-count">Bids: <?php echo $match['bid_count']; ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</section>

<?php
// Include footer
include_once 'includes/footer.php';
?> 