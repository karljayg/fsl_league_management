<?php
/**
 * Create Reviewer Action
 * Handles creating new reviewers via API
 */

class CreateReviewerAction {
    private $db;
    private $config;
    private $validator;
    
    public function __construct($db, $config) {
        $this->db = $db;
        $this->config = $config;
        $this->validator = new APIValidator();
    }
    
    public function execute($data) {
        try {
            // Validate input data
            $errors = $this->validator->validateReviewerData($data);
            if (!empty($errors)) {
                return [
                    'success' => false,
                    'error' => 'validation_error',
                    'message' => 'Validation failed: ' . implode(', ', $errors),
                    'code' => 400
                ];
            }
            
            // Sanitize input data
            $sanitizedData = $this->validator->sanitizeReviewerData($data);
            
            // Check if reviewer already exists by name
            $stmt = $this->db->prepare("SELECT id, name, unique_url, weight, status, created_at FROM reviewers WHERE name = ?");
            $stmt->execute([$sanitizedData['name']]);
            $existingReviewer = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existingReviewer) {
                return [
                    'success' => true,
                    'action' => 'create_reviewer',
                    'data' => [
                        'reviewer_id' => $existingReviewer['id'],
                        'name' => $existingReviewer['name'],
                        'unique_url' => $existingReviewer['unique_url'],
                        'full_url' => $this->config['service_api']['base_url'] . '/score_match.php?token=' . $existingReviewer['unique_url'],
                        'weight' => $existingReviewer['weight'],
                        'status' => $existingReviewer['status'],
                        'created_at' => $existingReviewer['created_at']
                    ],
                    'message' => 'Reviewer already exists'
                ];
            }
            
            // Generate unique URL
            $uniqueUrl = $this->validator->generateUniqueUrl($sanitizedData['name']);
            
            // Ensure URL is unique
            $stmt = $this->db->prepare("SELECT id FROM reviewers WHERE unique_url = ?");
            $stmt->execute([$uniqueUrl]);
            
            $attempts = 0;
            while ($stmt->fetch() && $attempts < 10) {
                $uniqueUrl = $this->validator->generateUniqueUrl($sanitizedData['name']);
                $stmt->execute([$uniqueUrl]);
                $attempts++;
            }
            
            if ($attempts >= 10) {
                return [
                    'success' => false,
                    'error' => 'url_generation_failed',
                    'message' => 'Failed to generate unique URL after multiple attempts',
                    'code' => 500
                ];
            }
            
            // Set default weight if not provided
            $weight = $sanitizedData['weight'] ?? $this->config['service_api']['reviewer_default_weight'];
            
            // Insert new reviewer
            $stmt = $this->db->prepare("
                INSERT INTO reviewers (name, unique_url, weight, status, created_at) 
                VALUES (?, ?, ?, 'active', NOW())
            ");
            
            $stmt->execute([
                $sanitizedData['name'],
                $uniqueUrl,
                $weight
            ]);
            
            $reviewerId = $this->db->lastInsertId();
            
            // Get the created reviewer data
            $stmt = $this->db->prepare("
                SELECT id, name, unique_url, weight, status, created_at 
                FROM reviewers 
                WHERE id = ?
            ");
            $stmt->execute([$reviewerId]);
            $reviewer = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return [
                'success' => true,
                'action' => 'create_reviewer',
                'data' => [
                    'reviewer_id' => $reviewer['id'],
                    'name' => $reviewer['name'],
                    'unique_url' => $reviewer['unique_url'],
                    'full_url' => $this->config['service_api']['base_url'] . '/score_match.php?token=' . $reviewer['unique_url'],
                    'weight' => $reviewer['weight'],
                    'status' => $reviewer['status'],
                    'created_at' => $reviewer['created_at']
                ],
                'message' => 'Reviewer created successfully'
            ];
            
        } catch (Exception $e) {
            error_log("Create reviewer error: " . $e->getMessage());
            
            return [
                'success' => false,
                'error' => 'database_error',
                'message' => 'Failed to create reviewer',
                'code' => 500
            ];
        }
    }
}
?> 