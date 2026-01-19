<?php
/**
 * Reviewer Database Functions
 * Replaces CSV-based reviewer management with database operations
 */

require_once __DIR__ . '/db.php';

/**
 * Get all reviewers from database
 * @param string $status Filter by status ('active', 'inactive', 'all')
 * @return array Array of reviewer records
 */
function getReviewers($status = 'all') {
    global $db;
    
    try {
        if ($status === 'all') {
            $query = "SELECT * FROM reviewers ORDER BY name";
            $stmt = $db->prepare($query);
            $stmt->execute();
        } else {
            $query = "SELECT * FROM reviewers WHERE status = ? ORDER BY name";
            $stmt = $db->prepare($query);
            $stmt->execute([$status]);
        }
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching reviewers: " . $e->getMessage());
        return [];
    }
}

/**
 * Get reviewer by ID
 * @param int $id Reviewer ID
 * @return array|null Reviewer record or null if not found
 */
function getReviewerById($id) {
    global $db;
    
    try {
        $stmt = $db->prepare("SELECT * FROM reviewers WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (PDOException $e) {
        error_log("Error fetching reviewer by ID: " . $e->getMessage());
        return null;
    }
}

/**
 * Get reviewer by unique URL token
 * @param string $token Unique URL token
 * @return array|null Reviewer record or null if not found
 */
function getReviewerByToken($token) {
    global $db;
    
    try {
        $stmt = $db->prepare("SELECT * FROM reviewers WHERE unique_url = ? AND status = 'active'");
        $stmt->execute([$token]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (PDOException $e) {
        error_log("Error fetching reviewer by token: " . $e->getMessage());
        return null;
    }
}

/**
 * Get reviewer by name
 * @param string $name Reviewer name
 * @return array|null Reviewer record or null if not found
 */
function getReviewerByName($name) {
    global $db;
    
    try {
        $stmt = $db->prepare("SELECT * FROM reviewers WHERE name = ?");
        $stmt->execute([$name]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (PDOException $e) {
        error_log("Error fetching reviewer by name: " . $e->getMessage());
        return null;
    }
}

/**
 * Create new reviewer
 * @param string $name Reviewer name
 * @param string $unique_url Unique URL token
 * @param float $weight Vote weight
 * @param string $status Status (active/inactive)
 * @return int|false New reviewer ID or false on failure
 */
function createReviewer($name, $unique_url, $weight = 1.0, $status = 'active') {
    global $db;
    
    try {
        $stmt = $db->prepare("
            INSERT INTO reviewers (name, unique_url, weight, status) 
            VALUES (?, ?, ?, ?)
        ");
        $result = $stmt->execute([$name, $unique_url, $weight, $status]);
        
        if ($result) {
            return $db->lastInsertId();
        }
        return false;
    } catch (PDOException $e) {
        error_log("Error creating reviewer: " . $e->getMessage());
        return false;
    }
}

/**
 * Update reviewer
 * @param int $id Reviewer ID
 * @param array $data Associative array of fields to update
 * @return bool Success status
 */
function updateReviewer($id, $data) {
    global $db;
    
    try {
        $allowed_fields = ['name', 'unique_url', 'weight', 'status'];
        $update_fields = [];
        $values = [];
        
        foreach ($data as $field => $value) {
            if (in_array($field, $allowed_fields)) {
                $update_fields[] = "$field = ?";
                $values[] = $value;
            }
        }
        
        if (empty($update_fields)) {
            return false;
        }
        
        $values[] = $id; // Add ID for WHERE clause
        
        $stmt = $db->prepare("
            UPDATE reviewers 
            SET " . implode(', ', $update_fields) . " 
            WHERE id = ?
        ");
        
        return $stmt->execute($values);
    } catch (PDOException $e) {
        error_log("Error updating reviewer: " . $e->getMessage());
        return false;
    }
}

/**
 * Delete reviewer (soft delete by setting status to inactive)
 * @param int $id Reviewer ID
 * @return bool Success status
 */
function deleteReviewer($id) {
    global $db;
    
    try {
        $stmt = $db->prepare("UPDATE reviewers SET status = 'inactive' WHERE id = ?");
        return $stmt->execute([$id]);
    } catch (PDOException $e) {
        error_log("Error deleting reviewer: " . $e->getMessage());
        return false;
    }
}

/**
 * Count reviewers by status
 * @param string $status Status to count ('active', 'inactive', 'all')
 * @return int Count of reviewers
 */
function countReviewers($status = 'active') {
    global $db;
    
    try {
        if ($status === 'all') {
            $stmt = $db->prepare("SELECT COUNT(*) FROM reviewers");
            $stmt->execute();
        } else {
            $stmt = $db->prepare("SELECT COUNT(*) FROM reviewers WHERE status = ?");
            $stmt->execute([$status]);
        }
        
        return (int) $stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("Error counting reviewers: " . $e->getMessage());
        return 0;
    }
}

/**
 * Generate unique URL token
 * @return string Unique token
 */
function generateReviewerToken() {
    return md5(uniqid(rand(), true));
}

/**
 * Check if unique URL token exists
 * @param string $token Token to check
 * @param int|null $exclude_id Reviewer ID to exclude from check (for updates)
 * @return bool True if token exists
 */
function tokenExists($token, $exclude_id = null) {
    global $db;
    
    try {
        if ($exclude_id !== null) {
            $stmt = $db->prepare("SELECT COUNT(*) FROM reviewers WHERE unique_url = ? AND id != ?");
            $stmt->execute([$token, $exclude_id]);
        } else {
            $stmt = $db->prepare("SELECT COUNT(*) FROM reviewers WHERE unique_url = ?");
            $stmt->execute([$token]);
        }
        
        return $stmt->fetchColumn() > 0;
    } catch (PDOException $e) {
        error_log("Error checking token existence: " . $e->getMessage());
        return true; // Assume exists on error for safety
    }
}

/**
 * Legacy function - maintains compatibility with existing CSV-based code
 * Returns reviewers in the same format as the old readReviewers() function
 * @param string $status Filter by status ('active', 'inactive', 'all')  
 * @return array Array in legacy format
 */
function readReviewers($status = 'active') {
    $reviewers = getReviewers($status);
    
    // Convert database format to legacy CSV format for backward compatibility
    $legacy_format = [];
    foreach ($reviewers as $reviewer) {
        $legacy_format[] = [
            'id' => $reviewer['id'],
            'name' => $reviewer['name'],
            'unique_url' => $reviewer['unique_url'],
            'weight' => (float) $reviewer['weight'],
            'status' => $reviewer['status']
        ];
    }
    
    return $legacy_format;
}
?>