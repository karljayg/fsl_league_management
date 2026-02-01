<?php
/**
 * Season utilities for FSL
 * Shared helpers for current/latest season from fsl_schedule.
 */

function getCurrentSeason(PDO $db): int {
    try {
        $stmt = $db->query("SELECT MAX(season) as current_season FROM fsl_schedule");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int) ($result['current_season'] ?? 9);
    } catch (PDOException $e) {
        return 9;
    }
}
