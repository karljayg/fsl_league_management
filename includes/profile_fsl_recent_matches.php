<?php
/**
 * FSL recent matches grid (same structure as view_player.php).
 * Expects: $fslRecentMatches (list of rows), $fslRealName (Players.Real_Name for highlight / links).
 */
if (!isset($fslRecentMatches) || !isset($fslRealName)) {
    return;
}
$fslRealName = (string) $fslRealName;
$fslNameForUrl = rawurlencode($fslRealName);
?>
<div class="recent-matches profile-fsl-recent-matches">
    <h2>Recent Matches</h2>
    <div class="matches-grid">
        <?php foreach ($fslRecentMatches as $match): ?>
            <div class="match-card">
                <div class="match-header">
                    <span class="season">Season <?php echo htmlspecialchars((string) ($match['season'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
                    <a href="view_match.php?id=<?php echo htmlspecialchars((string) ($match['fsl_match_id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" class="match-id-link">
                        #<?php echo htmlspecialchars((string) ($match['fsl_match_id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                    </a>
                    <?php if (!empty($match['notes'])): ?>
                        <div class="match-notes-subtitle"><?php echo htmlspecialchars((string) $match['notes'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php endif; ?>
                </div>
                <div class="match-content">
                    <?php
                    $isWinner = isset($match['winner_name']) && (string) $match['winner_name'] === $fslRealName;
                    $playerMatchInfo = [
                        'name' => $fslRealName,
                        'race' => $isWinner ? ($match['winner_race'] ?? null) : ($match['loser_race'] ?? null),
                        'score' => $isWinner ? ($match['map_win'] ?? '') : ($match['map_loss'] ?? ''),
                    ];
                    $opponentInfo = [
                        'name' => $isWinner ? ($match['loser_name'] ?? '') : ($match['winner_name'] ?? ''),
                        'race' => $isWinner ? ($match['loser_race'] ?? null) : ($match['winner_race'] ?? null),
                        'score' => $isWinner ? ($match['map_loss'] ?? '') : ($match['map_win'] ?? ''),
                    ];
                    ?>
                    <div class="player <?php echo $isWinner ? 'winner' : 'loser'; ?> highlight">
                        <a href="view_player.php?name=<?php echo rawurlencode($playerMatchInfo['name']); ?>" class="player-link">
                            <span class="name"><?php echo htmlspecialchars($playerMatchInfo['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                        </a>
                        <span class="race">
                            <?php
                            $mRace = $playerMatchInfo['race'] ?? null;
                            if ($mRace && ($mIcon = getRaceIconFromCode($mRace))): ?>
                                <img src="<?php echo htmlspecialchars($mIcon, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars((string) $mRace, ENT_QUOTES, 'UTF-8'); ?>" class="race-icon" title="<?php echo htmlspecialchars(getRaceNameFromCode($mRace), ENT_QUOTES, 'UTF-8'); ?>">
                            <?php else: ?>
                                <?php echo htmlspecialchars((string) ($mRace ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="score">
                        <?php echo htmlspecialchars((string) $playerMatchInfo['score'], ENT_QUOTES, 'UTF-8'); ?>-<?php echo htmlspecialchars((string) $opponentInfo['score'], ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <div class="player <?php echo $isWinner ? 'loser' : 'winner'; ?>">
                        <a href="view_player.php?name=<?php echo rawurlencode((string) $opponentInfo['name']); ?>" class="player-link">
                            <span class="name"><?php echo htmlspecialchars((string) $opponentInfo['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                        </a>
                        <span class="race">
                            <?php
                            $oRace = $opponentInfo['race'] ?? null;
                            if ($oRace && ($oIcon = getRaceIconFromCode($oRace))): ?>
                                <img src="<?php echo htmlspecialchars($oIcon, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars((string) $oRace, ENT_QUOTES, 'UTF-8'); ?>" class="race-icon" title="<?php echo htmlspecialchars(getRaceNameFromCode($oRace), ENT_QUOTES, 'UTF-8'); ?>">
                            <?php else: ?>
                                <?php echo htmlspecialchars((string) ($oRace ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                            <?php endif; ?>
                        </span>
                    </div>
                </div>
                <div class="match-footer">
                    <?php if (!empty($match['source'])): ?>
                        <a href="<?php echo htmlspecialchars((string) $match['source'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer" class="match-link">
                            <?php echo htmlspecialchars(getDomainFromUrl((string) $match['source']), ENT_QUOTES, 'UTF-8'); ?>
                        </a>
                    <?php endif; ?>
                    <?php if (!empty($match['vod'])): ?>
                        <a href="<?php echo htmlspecialchars((string) $match['vod'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer" class="match-link">
                            <?php echo htmlspecialchars(getDomainFromUrl((string) $match['vod']), ENT_QUOTES, 'UTF-8'); ?>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="view-all-matches profile-fsl-view-all-matches">
        <a href="fsl_matches.php?player=<?php echo $fslNameForUrl; ?>" class="view-all-link">View All Matches for <?php echo htmlspecialchars($fslRealName, ENT_QUOTES, 'UTF-8'); ?></a>
    </div>
</div>
