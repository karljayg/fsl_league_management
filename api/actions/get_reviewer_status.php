<?php
/**
 * Get Reviewer Status Action
 * Checks if a user is already a reviewer
 */

class GetReviewerStatusAction {
    private $db;
    private $validator;
    
    public function __construct($db) {
        $this->db = $db;
        $this->validator = new APIValidator();
    }
    
    public function execute($data) {
        try {
            // Validate input data
            if (empty($data['name'])) {
                return [
                    'success' => false,
                    'error' => 'missing_name',
                    'message' => 'Name is required',
                    'code' => 400
                ];
            }
            
            // Sanitize name
            $name = trim(htmlspecialchars($data['name'], ENT_QUOTES, 'UTF-8'));
            
            // Check if reviewer exists
            $stmt = $this->db->prepare("
                SELECT id, name, unique_url, weight, status, created_at 
                FROM reviewers 
                WHERE name = ?
            ");
            $stmt->execute([$name]);
            $reviewer = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($reviewer) {
                return [
                    'success' => true,
                    'action' => 'get_reviewer_status',
                    'data' => [
                        'exists' => true,
                        'reviewer_id' => $reviewer['id'],
                        'name' => $reviewer['name'],
                        'unique_url' => $reviewer['unique_url'],
                        'weight' => $reviewer['weight'],
                        'status' => $reviewer['status'],
                        'created_at' => $reviewer['created_at']
                    ],
                    'message' => 'Reviewer found'
                ];
            } else {
                return [
                    'success' => true,
                    'action' => 'get_reviewer_status',
                    'data' => [
                        'exists' => false,
                        'name' => $name
                    ],
                    'message' => 'Reviewer not found'
                ];
            }
            
        } catch (Exception $e) {
            error_log("Get reviewer status error: " . $e->getMessage());
            
            return [
                'success' => false,
                'error' => 'database_error',
                'message' => 'Failed to check reviewer status',
                'code' => 500
            ];
        }
    }
}
?> 