<?php
/**
 * API Authentication Class
 * Handles token validation and rate limiting
 */

class APIAuth {
    private $config;
    private $db;
    
    public function __construct($config) {
        $this->config = $config;
        global $db;
        $this->db = $db;
    }
    
    /**
     * Authenticate the API request
     */
    public function authenticate() {
        // Check if token is provided
        $token = $_GET['token'] ?? null;
        
        if (!$token) {
            return [
                'success' => false,
                'error' => 'missing_token',
                'message' => 'Authentication token is required',
                'code' => 401
            ];
        }
        
        // Validate token
        if ($token !== $this->config['token']) {
            return [
                'success' => false,
                'error' => 'invalid_token',
                'message' => 'Invalid authentication token',
                'code' => 401
            ];
        }
        
        // Check IP whitelist if configured
        if (!empty($this->config['allowed_ips'])) {
            $clientIP = $_SERVER['REMOTE_ADDR'];
            if (!in_array($clientIP, $this->config['allowed_ips'])) {
                return [
                    'success' => false,
                    'error' => 'ip_not_allowed',
                    'message' => 'IP address not allowed',
                    'code' => 403
                ];
            }
        }
        
        // Check rate limiting
        $rateLimitResult = $this->checkRateLimit();
        if (!$rateLimitResult['success']) {
            return $rateLimitResult;
        }
        
        return [
            'success' => true,
            'message' => 'Authentication successful'
        ];
    }
    
    /**
     * Check rate limiting
     */
    private function checkRateLimit() {
        if (!isset($this->config['rate_limit']) || $this->config['rate_limit'] <= 0) {
            return ['success' => true];
        }
        
        $clientIP = $_SERVER['REMOTE_ADDR'];
        $limit = $this->config['rate_limit'];
        $window = 3600; // 1 hour in seconds
        
        try {
            // Check if we have a rate limit table, create if not
            $this->ensureRateLimitTable();
            
            // Clean old entries
            $this->db->exec("DELETE FROM api_rate_limit WHERE timestamp < DATE_SUB(NOW(), INTERVAL 1 HOUR)");
            
            // Count recent requests
            $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM api_rate_limit WHERE ip_address = ? AND timestamp > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
            $stmt->execute([$clientIP]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result['count'] >= $limit) {
                return [
                    'success' => false,
                    'error' => 'rate_limit_exceeded',
                    'message' => 'Rate limit exceeded. Maximum ' . $limit . ' requests per hour.',
                    'code' => 429
                ];
            }
            
            // Log this request
            $stmt = $this->db->prepare("INSERT INTO api_rate_limit (ip_address, timestamp) VALUES (?, NOW())");
            $stmt->execute([$clientIP]);
            
            return ['success' => true];
            
        } catch (Exception $e) {
            // If rate limiting fails, allow the request but log the error
            error_log("Rate limiting error: " . $e->getMessage());
            return ['success' => true];
        }
    }
    
    /**
     * Ensure rate limit table exists
     */
    private function ensureRateLimitTable() {
        $sql = "CREATE TABLE IF NOT EXISTS api_rate_limit (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ip_address VARCHAR(45) NOT NULL,
            timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ip_timestamp (ip_address, timestamp)
        )";
        
        $this->db->exec($sql);
    }
}
?> 