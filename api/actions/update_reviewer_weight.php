<?php
/**
 * Update Reviewer Weight Action
 * Updates a reviewer's weight
 */

class UpdateReviewerWeightAction {
    private $db;
    private $validator;
    
    public function __construct($db) {
        $this->db = $db;
        $this->validator = new APIValidator();
    }
    
    public function execute($data) {
        try {
            // Validate input data
            if (empty($data['reviewer_id'])) {
                return [
                    'success' => false,
                    'error' => 'missing_reviewer_id',
                    'message' => 'Reviewer ID is required',
                    'code' => 400
                ];
            }
            
            if (!isset($data['weight'])) {
                return [
                    'success' => false,
                    'error' => 'missing_weight',
                    'message' => 'Weight is required',
                    'code' => 400
                ];
            }
            
            // Validate reviewer ID
            if (!$this->validator->validateReviewerId($data['reviewer_id'])) {
                return [
                    'success' => false,
                    'error' => 'invalid_reviewer_id',
                    'message' => 'Invalid reviewer ID',
                    'code' => 400
                ];
            }
            
            // Validate weight
            if (!is_numeric($data['weight'])) {
                return [
                    'success' => false,
                    'error' => 'invalid_weight',
                    'message' => 'Weight must be a number',
                    'code' => 400
                ];
            }
            
            $weight = floatval($data['weight']);
            if ($weight < 0 || $weight > 10) {
                return [
                    'success' => false,
                    'error' => 'invalid_weight_range',
                    'message' => 'Weight must be between 0 and 10',
                    'code' => 400
                ];
            }
            
            // Check if reviewer exists
            $stmt = $this->db->prepare("SELECT id, name FROM reviewers WHERE id = ?");
            $stmt->execute([$data['reviewer_id']]);
            $reviewer = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$reviewer) {
                return [
                    'success' => false,
                    'error' => 'reviewer_not_found',
                    'message' => 'Reviewer not found',
                    'code' => 404
                ];
            }
            
            // Update reviewer weight
            $stmt = $this->db->prepare("
                UPDATE reviewers 
                SET weight = ?, updated_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$weight, $data['reviewer_id']]);
            
            // Get updated reviewer data
            $stmt = $this->db->prepare("
                SELECT id, name, unique_url, weight, status, created_at, updated_at 
                FROM reviewers 
                WHERE id = ?
            ");
            $stmt->execute([$data['reviewer_id']]);
            $updatedReviewer = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return [
                'success' => true,
                'action' => 'update_reviewer_weight',
                'data' => [
                    'reviewer_id' => $updatedReviewer['id'],
                    'name' => $updatedReviewer['name'],
                    'weight' => $updatedReviewer['weight'],
                    'updated_at' => $updatedReviewer['updated_at']
                ],
                'message' => 'Reviewer weight updated successfully'
            ];
            
        } catch (Exception $e) {
            error_log("Update reviewer weight error: " . $e->getMessage());
            
            return [
                'success' => false,
                'error' => 'database_error',
                'message' => 'Failed to update reviewer weight',
                'code' => 500
            ];
        }
    }
}
?> 