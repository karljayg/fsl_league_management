<?php
/**
 * API Validator Class
 * Handles input validation and sanitization
 */

class APIValidator {
    
    /**
     * Validate reviewer data
     */
    public function validateReviewerData($data) {
        $errors = [];
        
        // Required fields
        if (empty($data['name'])) {
            $errors[] = 'Name is required';
        }
        
        // Validate name length
        if (isset($data['name']) && strlen($data['name']) > 255) {
            $errors[] = 'Name must be 255 characters or less';
        }
        
        // Validate weight if provided
        if (isset($data['weight'])) {
            if (!is_numeric($data['weight'])) {
                $errors[] = 'Weight must be a number';
            } elseif ($data['weight'] < 0 || $data['weight'] > 10) {
                $errors[] = 'Weight must be between 0 and 10';
            }
        }
        
        // Validate email if provided
        if (isset($data['email']) && !empty($data['email'])) {
            if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Invalid email format';
            }
        }
        
        return $errors;
    }
    
    /**
     * Sanitize reviewer data
     */
    public function sanitizeReviewerData($data) {
        $sanitized = [];
        
        // Sanitize name
        if (isset($data['name'])) {
            $sanitized['name'] = trim(htmlspecialchars($data['name'], ENT_QUOTES, 'UTF-8'));
        }
        
        // Sanitize email
        if (isset($data['email'])) {
            $sanitized['email'] = filter_var(trim($data['email']), FILTER_SANITIZE_EMAIL);
        }
        
        // Sanitize weight
        if (isset($data['weight'])) {
            $sanitized['weight'] = floatval($data['weight']);
        }
        
        // Sanitize notes
        if (isset($data['notes'])) {
            $sanitized['notes'] = trim(htmlspecialchars($data['notes'], ENT_QUOTES, 'UTF-8'));
        }
        
        return $sanitized;
    }
    
    /**
     * Validate reviewer ID
     */
    public function validateReviewerId($id) {
        if (!is_numeric($id) || $id <= 0) {
            return false;
        }
        return true;
    }
    
    /**
     * Generate unique URL for reviewer
     */
    public function generateUniqueUrl($name) {
        // Generate a random 32-character hex token (like the original system)
        return bin2hex(random_bytes(16));
    }
}
?> 