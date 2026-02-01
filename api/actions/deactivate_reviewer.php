<?php
/**
 * Deactivate Reviewer Action
 * Deactivates a reviewer (soft delete)
 */

class DeactivateReviewerAction {
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
            
            // Validate reviewer ID
            if (!$this->validator->validateReviewerId($data['reviewer_id'])) {
                return [
                    'success' => false,
                    'error' => 'invalid_reviewer_id',
                    'message' => 'Invalid reviewer ID',
                    'code' => 400
                ];
            }
            
            // Check if reviewer exists and is active
            $stmt = $this->db->prepare("SELECT id, name, status FROM reviewers WHERE id = ?");
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
            
            if ($reviewer['status'] === 'inactive') {
                return [
                    'success' => false,
                    'error' => 'reviewer_already_inactive',
                    'message' => 'Reviewer is already inactive',
                    'code' => 409
                ];
            }
            
            // Deactivate reviewer
            $stmt = $this->db->prepare("
                UPDATE reviewers 
                SET status = 'inactive', updated_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$data['reviewer_id']]);
            
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
                'action' => 'deactivate_reviewer',
                'data' => [
                    'reviewer_id' => $updatedReviewer['id'],
                    'name' => $updatedReviewer['name'],
                    'status' => $updatedReviewer['status'],
                    'updated_at' => $updatedReviewer['updated_at']
                ],
                'message' => 'Reviewer deactivated successfully'
            ];
            
        } catch (Exception $e) {
            error_log("Deactivate reviewer error: " . $e->getMessage());
            
            return [
                'success' => false,
                'error' => 'database_error',
                'message' => 'Failed to deactivate reviewer',
                'code' => 500
            ];
        }
    }
}
?> 