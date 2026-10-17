<?php
/**
 * Private !spider ballots.
 *
 * One Twitch login has one token. Sharing the URL overwrites that same vote.
 * Chat codes still roll up through POST ?action=votes. This file stores the
 * web choices and folds them into the TwitchChat tally rows.
 */

const SPIDER_ATTRIBUTES = ['micro', 'macro', 'clutch', 'creativity', 'aggression', 'strategy'];
const SPIDER_RECENT_MATCH_LIMIT = 8;

function spider_tables_ready(PDO $db): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    $stmt = $db->query("SHOW TABLES LIKE 'spider_ballots'");
    $ready = (bool) $stmt->fetch(PDO::FETCH_NUM);
    return $ready;
}

function spider_normalize_login(string $login): ?string
{
    $login = strtolower(trim($login));
    if (!preg_match('/^[a-z0-9_]{1,25}$/', $login)) {
        return null;
    }
    return $login;
}

function spider_iso(?string $mysql): ?string
{
    if ($mysql === null || $mysql === '') {
        return null;
    }
    $ts = strtotime($mysql);
    if ($ts === false) {
        return null;
    }
    return gmdate('c', $ts);
}

function spider_public_base(): string
{
    global $config;
    $base = rtrim((string) ($config['service_api']['base_url'] ?? ''), '/');
    if ($base === '') {
        $base = 'https://psistorm.com/fsl';
    }
    return $base;
}

function spider_majority(int $p1, int $p2, int $tie): int
{
    if ($p1 > $p2 && $p1 >= $tie) {
        return 1;
    }
    if ($p2 > $p1 && $p2 >= $tie) {
        return 2;
    }
    return 0;
}

function spider_twitchchat_id(PDO $db): int
{
    static $id = null;
    if ($id !== null) {
        return $id;
    }
    $stmt = $db->prepare("SELECT id FROM reviewers WHERE name = 'TwitchChat' AND status = 'active' LIMIT 1");
    $stmt->execute();
    $found = $stmt->fetchColumn();
    if (!$found) {
        throw new RuntimeException('TwitchChat reviewer is missing');
    }
    $id = (int) $found;
    return $id;
}

function spider_active_session(PDO $db): ?array
{
    $stmt = $db->query("
        SELECT vs.id, vs.fsl_match_id, vs.expires_at, vs.status
        FROM voting_sessions vs
        WHERE vs.status = 'open' AND vs.expires_at > NOW()
        ORDER BY vs.id DESC
        LIMIT 1
    ");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function spider_match_row(PDO $db, int $matchId): ?array
{
    $stmt = $db->prepare("
        SELECT fm.fsl_match_id, fm.t_code, fm.map_win, fm.map_loss,
               fm.winner_player_id, fm.loser_player_id,
               p1.Real_Name AS player1_name,
               p2.Real_Name AS player2_name
        FROM fsl_matches fm
        JOIN Players p1 ON p1.Player_ID = fm.winner_player_id
        JOIN Players p2 ON p2.Player_ID = fm.loser_player_id
        WHERE fm.fsl_match_id = ?
    ");
    $stmt->execute([$matchId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** Full row for ballot matchup header (intros, teams, VOD, schedule date). */
function spider_ballot_match_detail(PDO $db, int $matchId): ?array
{
    $stmt = $db->prepare("
        SELECT fm.fsl_match_id, fm.season, fm.season_extra_info, fm.notes, fm.t_code, fm.vod,
               fm.map_win, fm.map_loss, fm.best_of,
               fm.winner_player_id, fm.loser_player_id,
               fm.winner_race AS player1_race, fm.loser_race AS player2_race,
               p1.Real_Name AS player1_name, p2.Real_Name AS player2_name,
               p1.Intro_Url AS player1_intro, p2.Intro_Url AS player2_intro,
               t1.Team_Name AS player1_team, t2.Team_Name AS player2_team,
               (SELECT MAX(fs.match_date)
                FROM fsl_schedule_matches fsm
                JOIN fsl_schedule fs ON fs.schedule_id = fsm.schedule_id
                WHERE fsm.fsl_match_id = fm.fsl_match_id) AS match_date
        FROM fsl_matches fm
        JOIN Players p1 ON p1.Player_ID = fm.winner_player_id
        JOIN Players p2 ON p2.Player_ID = fm.loser_player_id
        LEFT JOIN Teams t1 ON t1.Team_ID = p1.Team_ID
        LEFT JOIN Teams t2 ON t2.Team_ID = p2.Team_ID
        WHERE fm.fsl_match_id = ?
    ");
    $stmt->execute([$matchId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * The 8 most recent league matches (newest fsl_match_id first).
 * Schedule date is for labels only; do not sort by broadcast date — S12 rows
 * often share a placeholder match_date that would bury real recent games.
 * $pinIds (e.g. live window) are kept even if they fall outside that eight.
 *
 * @param int[] $pinIds
 */
function spider_recent_matches(PDO $db, array $pinIds = []): array
{
    $limit = SPIDER_RECENT_MATCH_LIMIT;
    $stmt = $db->query("
        SELECT fm.fsl_match_id, fm.t_code, fm.map_win, fm.map_loss,
               fm.winner_player_id, fm.loser_player_id,
               p1.Real_Name AS player1_name,
               p2.Real_Name AS player2_name,
               MAX(fs.match_date) AS match_date
        FROM fsl_matches fm
        JOIN Players p1 ON p1.Player_ID = fm.winner_player_id
        JOIN Players p2 ON p2.Player_ID = fm.loser_player_id
        LEFT JOIN fsl_schedule_matches fsm ON fsm.fsl_match_id = fm.fsl_match_id
        LEFT JOIN fsl_schedule fs ON fs.schedule_id = fsm.schedule_id
        GROUP BY fm.fsl_match_id, fm.t_code, fm.map_win, fm.map_loss,
                 fm.winner_player_id, fm.loser_player_id,
                 p1.Real_Name, p2.Real_Name
        ORDER BY fm.fsl_match_id DESC
        LIMIT " . (int) $limit . "
    ");
    $byId = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $byId[(int) $row['fsl_match_id']] = $row;
    }

    $pins = [];
    foreach ($pinIds as $pinId) {
        $pinId = (int) $pinId;
        if ($pinId > 0) {
            $pins[$pinId] = true;
        }
    }

    foreach (array_keys($pins) as $pinId) {
        if (isset($byId[$pinId])) {
            continue;
        }
        $extra = spider_match_row($db, $pinId);
        if ($extra) {
            $detail = spider_ballot_match_detail($db, $pinId);
            $extra['match_date'] = $detail['match_date'] ?? null;
            $byId[$pinId] = $extra;
        }
    }

    $rows = array_values($byId);
    usort($rows, function ($a, $b) {
        return (int) $b['fsl_match_id'] - (int) $a['fsl_match_id'];
    });

    if (count($rows) <= $limit) {
        return $rows;
    }

    $keep = [];
    foreach ($rows as $row) {
        $id = (int) $row['fsl_match_id'];
        if (isset($pins[$id])) {
            $keep[$id] = $row;
        }
    }
    foreach ($rows as $row) {
        $id = (int) $row['fsl_match_id'];
        if (isset($keep[$id])) {
            continue;
        }
        if (count($keep) >= $limit) {
            break;
        }
        $keep[$id] = $row;
    }

    $out = array_values($keep);
    usort($out, function ($a, $b) {
        return (int) $b['fsl_match_id'] - (int) $a['fsl_match_id'];
    });

    return $out;
}

function spider_match_label(array $match): string
{
    $when = 'Recent match';
    if (!empty($match['match_date'])) {
        $when = date('D M j, g:i A', strtotime($match['match_date']));
    }
    $code = $match['t_code'] !== null && $match['t_code'] !== '' ? 'Code ' . $match['t_code'] : 'Match';
    $score = (int) $match['map_win'] . '-' . (int) $match['map_loss'];
    return $when . ' · ' . $code . ' · ' . $match['player1_name'] . ' ' . $score . ' ' . $match['player2_name'];
}

function spider_issue_token(PDO $db, string $login, int $matchId): array
{
    $stmt = $db->prepare("SELECT token FROM spider_ballot_tokens WHERE twitch_login = ?");
    $stmt->execute([$login]);
    $token = $stmt->fetchColumn();

    if ($token) {
        $upd = $db->prepare("
            UPDATE spider_ballot_tokens
            SET preselect_match_id = ?, last_issued_at = NOW()
            WHERE twitch_login = ?
        ");
        $upd->execute([$matchId, $login]);
    } else {
        $token = bin2hex(random_bytes(16));
        $ins = $db->prepare("
            INSERT INTO spider_ballot_tokens (twitch_login, token, preselect_match_id)
            VALUES (?, ?, ?)
        ");
        $ins->execute([$login, $token, $matchId]);
    }

    return [
        'token' => (string) $token,
        'url' => spider_public_base() . '/spider_ballot.php?t=' . $token,
        'twitch_login' => $login,
        'preselect_fsl_match_id' => $matchId,
    ];
}

function spider_token_row(PDO $db, string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
        return null;
    }
    $stmt = $db->prepare("SELECT twitch_login, token, preselect_match_id FROM spider_ballot_tokens WHERE token = ?");
    $stmt->execute([$token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function spider_choices_for(PDO $db, string $login, int $matchId): array
{
    $stmt = $db->prepare("
        SELECT attribute, side, in_tally, updated_at
        FROM spider_ballots
        WHERE twitch_login = ? AND fsl_match_id = ?
    ");
    $stmt->execute([$login, $matchId]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[$row['attribute']] = $row;
    }
    return $out;
}

/**
 * Move one viewer's side in the TwitchChat tally for a single attribute.
 * $remove and $add are '1', '2', '0', or null.
 */
function spider_shift_tally(PDO $db, int $matchId, int $player1Id, int $player2Id, string $attr, ?string $remove, ?string $add): void
{
    if ($remove === $add) {
        return;
    }
    $reviewerId = spider_twitchchat_id($db);
    $stmt = $db->prepare("
        SELECT tally_player1, tally_player2, tally_tie
        FROM Player_Attribute_Votes
        WHERE reviewer_id = ? AND fsl_match_id = ? AND attribute = ?
        FOR UPDATE
    ");
    $stmt->execute([$reviewerId, $matchId, $attr]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $counts = ['1' => 0, '2' => 0, '0' => 0];
    if ($row) {
        $counts['1'] = (int) $row['tally_player1'];
        $counts['2'] = (int) $row['tally_player2'];
        $counts['0'] = (int) $row['tally_tie'];
    }
    if ($remove !== null && $counts[$remove] > 0) {
        $counts[$remove]--;
    }
    if ($add !== null) {
        $counts[$add]++;
    }
    $vote = spider_majority($counts['1'], $counts['2'], $counts['0']);

    if ($row) {
        $upd = $db->prepare("
            UPDATE Player_Attribute_Votes
            SET vote = ?, tally_player1 = ?, tally_player2 = ?, tally_tie = ?
            WHERE reviewer_id = ? AND fsl_match_id = ? AND attribute = ?
        ");
        $upd->execute([$vote, $counts['1'], $counts['2'], $counts['0'], $reviewerId, $matchId, $attr]);
        return;
    }

    $ins = $db->prepare("
        INSERT INTO Player_Attribute_Votes
            (fsl_match_id, reviewer_id, attribute, vote,
             tally_player1, tally_player2, tally_tie,
             player1_id, player2_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $ins->execute([
        $matchId, $reviewerId, $attr, $vote,
        $counts['1'], $counts['2'], $counts['0'],
        $player1Id, $player2Id,
    ]);
}

/**
 * Save one page submit.
 * $choices keys are attributes. Value '0'|'1'|'2' sets that skill, 'skip' clears it.
 * Missing keys are left alone.
 *
 * The match that currently has an open chat window is stored only. Mathison
 * merges it at POST votes. Every other match updates the TwitchChat tally now.
 */
function spider_save_choices(PDO $db, string $login, int $matchId, array $choices): void
{
    $match = spider_match_row($db, $matchId);
    if (!$match) {
        throw new InvalidArgumentException('Match not found');
    }
    spider_fold_expired_sessions($db);
    $active = spider_active_session($db);
    $isLive = $active && (int) $active['fsl_match_id'] === $matchId;
    $existing = spider_choices_for($db, $login, $matchId);

    $db->beginTransaction();
    try {
        foreach (SPIDER_ATTRIBUTES as $attr) {
            if (!array_key_exists($attr, $choices)) {
                continue;
            }
            $raw = $choices[$attr];
            $newSide = null;
            if ($raw !== null && $raw !== '' && $raw !== 'skip') {
                if (!in_array((string) $raw, ['0', '1', '2'], true)) {
                    throw new InvalidArgumentException('Bad side');
                }
                $newSide = (string) $raw;
            }

            $prev = $existing[$attr] ?? null;
            if ($newSide === null && !$prev) {
                continue;
            }

            if ($isLive) {
                if ($prev && (int) $prev['in_tally'] === 1) {
                    spider_shift_tally(
                        $db,
                        $matchId,
                        (int) $match['winner_player_id'],
                        (int) $match['loser_player_id'],
                        $attr,
                        (string) $prev['side'],
                        null
                    );
                }
                if ($newSide === null) {
                    $del = $db->prepare("DELETE FROM spider_ballots WHERE twitch_login = ? AND fsl_match_id = ? AND attribute = ?");
                    $del->execute([$login, $matchId, $attr]);
                    continue;
                }
                $up = $db->prepare("
                    INSERT INTO spider_ballots (twitch_login, fsl_match_id, attribute, side, in_tally)
                    VALUES (?, ?, ?, ?, 0)
                    ON DUPLICATE KEY UPDATE side = VALUES(side), in_tally = 0, updated_at = NOW()
                ");
                $up->execute([$login, $matchId, $attr, $newSide]);
                continue;
            }

            $oldInTally = ($prev && (int) $prev['in_tally'] === 1) ? (string) $prev['side'] : null;
            spider_shift_tally(
                $db,
                $matchId,
                (int) $match['winner_player_id'],
                (int) $match['loser_player_id'],
                $attr,
                $oldInTally,
                $newSide
            );
            if ($newSide === null) {
                $del = $db->prepare("DELETE FROM spider_ballots WHERE twitch_login = ? AND fsl_match_id = ? AND attribute = ?");
                $del->execute([$login, $matchId, $attr]);
                continue;
            }
            $up = $db->prepare("
                INSERT INTO spider_ballots (twitch_login, fsl_match_id, attribute, side, in_tally)
                VALUES (?, ?, ?, ?, 1)
                ON DUPLICATE KEY UPDATE side = VALUES(side), in_tally = 1, updated_at = NOW()
            ");
            $up->execute([$login, $matchId, $attr, $newSide]);
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

/**
 * Ballots for the open session's match, grouped by login.
 * Per-attribute updated_at so Mathison can last-write against chat.
 */
function spider_ballots_for_session(PDO $db, int $sessionId): ?array
{
    $stmt = $db->prepare("
        SELECT id, fsl_match_id, expires_at
        FROM voting_sessions
        WHERE id = ? AND status = 'open' AND expires_at > NOW()
    ");
    $stmt->execute([$sessionId]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$session) {
        return null;
    }

    $votes = $db->prepare("
        SELECT twitch_login, attribute, side, updated_at
        FROM spider_ballots
        WHERE fsl_match_id = ?
        ORDER BY twitch_login, attribute
    ");
    $votes->execute([(int) $session['fsl_match_id']]);
    $grouped = [];
    foreach ($votes->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $login = $row['twitch_login'];
        if (!isset($grouped[$login])) {
            $grouped[$login] = [
                'twitch_login' => $login,
                'votes' => [],
            ];
        }
        $grouped[$login]['votes'][$row['attribute']] = [
            'side' => (string) $row['side'],
            'updated_at' => spider_iso($row['updated_at']),
        ];
    }

    return [
        'session_id' => (int) $session['id'],
        'fsl_match_id' => (int) $session['fsl_match_id'],
        'expires_at' => spider_iso($session['expires_at']),
        'ballots' => array_values($grouped),
    ];
}

/**
 * If a chat window ended without POST votes, add any web choices that are
 * not already in the TwitchChat tally, then mark that session expired.
 */
function spider_fold_expired_sessions(PDO $db): void
{
    if (!spider_tables_ready($db)) {
        return;
    }
    $sessions = $db->query("
        SELECT id, fsl_match_id
        FROM voting_sessions
        WHERE status = 'open' AND expires_at <= NOW()
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($sessions as $session) {
        $db->beginTransaction();
        try {
            $lock = $db->prepare("
                SELECT id, fsl_match_id
                FROM voting_sessions
                WHERE id = ? AND status = 'open' AND expires_at <= NOW()
                FOR UPDATE
            ");
            $lock->execute([(int) $session['id']]);
            $locked = $lock->fetch(PDO::FETCH_ASSOC);
            if (!$locked) {
                $db->commit();
                continue;
            }
            $matchId = (int) $locked['fsl_match_id'];
            $match = spider_match_row($db, $matchId);
            if ($match) {
                $pending = $db->prepare("
                    SELECT twitch_login, attribute, side
                    FROM spider_ballots
                    WHERE fsl_match_id = ? AND in_tally = 0
                    FOR UPDATE
                ");
                $pending->execute([$matchId]);
                $set = $db->prepare("
                    UPDATE spider_ballots
                    SET in_tally = 1, updated_at = updated_at
                    WHERE twitch_login = ? AND fsl_match_id = ? AND attribute = ?
                ");
                foreach ($pending->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    spider_shift_tally(
                        $db,
                        $matchId,
                        (int) $match['winner_player_id'],
                        (int) $match['loser_player_id'],
                        $row['attribute'],
                        null,
                        (string) $row['side']
                    );
                    $set->execute([$row['twitch_login'], $matchId, $row['attribute']]);
                }
            }
            $done = $db->prepare("UPDATE voting_sessions SET status = 'expired', closed_at = NOW() WHERE id = ?");
            $done->execute([(int) $locked['id']]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }
}

/**
 * $accepted is null when the bot did not send accepted_web (chat-only POST).
 * $accepted is login => attr => side when the new bot merged web ballots itself.
 */
function spider_reconcile_posted_votes(PDO $db, int $matchId, ?array $accepted): void
{
    if (!spider_tables_ready($db)) {
        return;
    }
    $match = spider_match_row($db, $matchId);
    if (!$match) {
        return;
    }
    $stmt = $db->prepare("
        SELECT twitch_login, attribute, side, in_tally
        FROM spider_ballots
        WHERE fsl_match_id = ?
        FOR UPDATE
    ");
    $stmt->execute([$matchId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $p1 = (int) $match['winner_player_id'];
    $p2 = (int) $match['loser_player_id'];

    $mark = $db->prepare("
        UPDATE spider_ballots
        SET in_tally = ?, side = ?, updated_at = updated_at
        WHERE twitch_login = ? AND fsl_match_id = ? AND attribute = ?
    ");

    if ($accepted === null) {
        foreach ($rows as $row) {
            spider_shift_tally($db, $matchId, $p1, $p2, $row['attribute'], null, (string) $row['side']);
            $mark->execute([1, $row['side'], $row['twitch_login'], $matchId, $row['attribute']]);
        }
        return;
    }

    foreach ($rows as $row) {
        $login = $row['twitch_login'];
        $attr = $row['attribute'];
        $stored = (string) $row['side'];
        $taken = $accepted[$login][$attr] ?? null;
        if ($taken !== null && $taken !== $stored) {
            spider_shift_tally($db, $matchId, $p1, $p2, $attr, $taken, $stored);
            $mark->execute([1, $stored, $login, $matchId, $attr]);
            continue;
        }
        if ($taken !== null) {
            $mark->execute([1, $stored, $login, $matchId, $attr]);
            continue;
        }
        $mark->execute([0, $stored, $login, $matchId, $attr]);
    }
}

/**
 * @return array<string, array<string, string>>|null|false
 *         null = key omitted, false = invalid, array = login => attr => side
 */
function spider_validate_accepted_web(mixed $value): array|null|false
{
    if ($value === null) {
        return null;
    }
    if (!is_array($value)) {
        return false;
    }
    $out = [];
    foreach ($value as $entry) {
        if (!is_array($entry)) {
            return false;
        }
        $login = spider_normalize_login((string) ($entry['twitch_login'] ?? ''));
        if ($login === null) {
            return false;
        }
        $votes = $entry['votes'] ?? null;
        if (!is_array($votes)) {
            return false;
        }
        foreach ($votes as $attr => $side) {
            if (!in_array((string) $attr, SPIDER_ATTRIBUTES, true)) {
                return false;
            }
            $side = (string) $side;
            if (!in_array($side, ['0', '1', '2'], true)) {
                return false;
            }
            $out[$login][(string) $attr] = $side;
        }
    }
    return $out;
}
