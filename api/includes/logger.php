<?php
/**
 * API Logger Class
 * Handles logging of API requests for audit purposes
 */

class APILogger {
    private $db;
    
    public function __construct($db) {
        $this->db = $db;
    }
    
    /**
     * Log API request
     */
    public function logRequest($method, $uri, $ip) {
        try {
            // Ensure log table exists
            $this->ensureLogTable();
            
            // Get request body for logging
            $body = file_get_contents('php://input');
            $body = substr($body, 0, 1000); // Limit body size for logging
            
            $stmt = $this->db->prepare("
                INSERT INTO api_request_log 
                (method, uri, ip_address, request_body, user_agent, timestamp) 
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $method,
                $uri,
                $ip,
                $body,
                $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            ]);
            
        } catch (Exception $e) {
            // Don't fail the request if logging fails
            error_log("API logging error: " . $e->getMessage());
        }
    }
    
    /**
     * Log API response
     */
    public function logResponse($requestId, $response, $statusCode) {
        try {
            $stmt = $this->db->prepare("
                UPDATE api_request_log 
                SET response_body = ?, status_code = ?, response_time = NOW() 
                WHERE id = ?
            ");
            
            $responseBody = is_array($response) ? json_encode($response) : $response;
            $responseBody = substr($responseBody, 0, 1000); // Limit response size
            
            $stmt->execute([$responseBody, $statusCode, $requestId]);
            
        } catch (Exception $e) {
            error_log("API response logging error: " . $e->getMessage());
        }
    }
    
    /**
     * Ensure log table exists
     */
    private function ensureLogTable() {
        $sql = "CREATE TABLE IF NOT EXISTS api_request_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            method VARCHAR(10) NOT NULL,
            uri VARCHAR(500) NOT NULL,
            ip_address VARCHAR(45) NOT NULL,
            request_body TEXT,
            response_body TEXT,
            status_code INT,
            user_agent VARCHAR(500),
            timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            response_time TIMESTAMP NULL,
            INDEX idx_timestamp (timestamp),
            INDEX idx_ip (ip_address)
        )";
        
        $this->db->exec($sql);
    }
}
?> 