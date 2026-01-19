<?php

/**
 * Processes the championship JSON and returns formatted output.
 *
 * Expected JSON format:
 * {
 *   "records": [
 *     {"notes": "some notes", "result": 1, "season": 8, "division": "S"},
 *     {"notes": "some notes", "result": 2, "season": 4, "division": "S"},
 *     ...
 *   ]
 * }
 *
 * @param string $json The JSON string.
 * @param int $outputMode 1 for plain text, 2 for HTML with medal images.
 * @return string The formatted championship records.
 */
function processChampionshipJSON($json, $outputMode = 1) {
    // Decode JSON with associative arrays.
    $data = json_decode($json, true);
    if (!$data || !isset($data['records'])) {
        return '';
    }
    
    $outputLines = [];
    
    foreach ($data['records'] as $record) {
        // Ensure required keys exist.
        if (!isset($record['result']) || !isset($record['season'])) {
            continue;
        }
        $result = intval($record['result']);
        $season = $record['season'];
        $division = isset($record['division']) ? $record['division'] : '';
        
        // Determine the output based on the result.
        if ($outputMode == 1) {
            // Output Mode 1: Plain text.
            if ($result === 1) {
                $line = ($division != '')
                    ? "Season {$season} Code {$division} Champion"
                    : "Season {$season} Champion";
            } else {
                $ordinal = convertToOrdinal($result);
                $line = "Season {$season} {$ordinal} place";
            }
            $outputLines[] = $line;
        } else if ($outputMode == 2) {
            // Output Mode 2: HTML with medal images.
            $medalHTML = '';
            $text = '';
            if ($result === 1) {
                // Gold medal for 1st.
                $text = ($division != '')
                    ? "Season {$season} Code {$division} Champion"
                    : "Season {$season} Champion";
                $medalHTML = "<img src='images/gold_medal_icon.png' height='50px' alt='Gold Medal' title='" . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . "' style='margin-right:5px;'>";
            } else if ($result === 2) {
                // Silver medal for 2nd.
                $ordinal = convertToOrdinal($result); // "2nd"
                $text = "Season {$season} Code {$division} {$ordinal} place";
                $medalHTML = "<img src='images/silver_medal_icon.png' height='50px' alt='Silver Medal' title='" . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . "' style='margin-right:5px;'>";
            } else if ($result === 3) {
                // Bronze medal for 3rd.
                $ordinal = convertToOrdinal($result); // "3rd"
                $text = "Season {$season} {$ordinal} place";
                $medalHTML = "<img src='images/bronze_medal_icon.png' height='50px' alt='Bronze Medal' title='" . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . "' style='margin-right:5px;'>";
            } else {
                // For results beyond 3, no medal image.
                $ordinal = convertToOrdinal($result);
                $text = "Season {$season} {$ordinal} place";
            }
            // Wrap in a div with flex display for proper alignment.
            //$line = "<div style='display: flex; align-items: center; margin-bottom: 5px;'>{$medalHTML}<span>{$text}</span></div>";
            $line = "<span style='display: inline-flex; align-items: center; margin-right: 0px;'>{$medalHTML}</span>";

            $outputLines[] = $line;
        }
    }
    
    // Return the records separated by a newline.
    //return implode("\n", $outputLines);
    return implode(" ", $outputLines);

}

/**
 * Converts a number to its ordinal representation.
 *
 * @param int $number The number to convert.
 * @return string The number with its ordinal suffix.
 */
function convertToOrdinal($number) {
    $absNumber = abs($number);
    $lastTwoDigits = $absNumber % 100;
    $lastDigit = $absNumber % 10;

    if ($lastTwoDigits >= 11 && $lastTwoDigits <= 13) {
        $suffix = 'th';
    } elseif ($lastDigit === 1) {
        $suffix = 'st';
    } elseif ($lastDigit === 2) {
        $suffix = 'nd';
    } elseif ($lastDigit === 3) {
        $suffix = 'rd';
    } else {
        $suffix = 'th';
    }
    return $number . $suffix;
}

?>
