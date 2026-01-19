<?php
ob_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

class Database {
    private $conn;
    
    public function __construct() {
        try {
            $this->conn = new PDO(
                "mysql:host=localhost;dbname=psistorm;charset=utf8mb4",
                "root",
                "password",
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (PDOException $e) {
            throw new Exception("Connection failed: " . $e->getMessage());
        }
    }
    
    public function getConnection() {
        return $this->conn;
    }
}

class RegistrationSystem {
    private $db;
    
    public function __construct() {
        $this->db = new Database();
    }
    
    public function register($email, $username, $password, $role = 'user', $mmr = null, $race = null) {
        try {
            // Input validation
            $this->validateInput($email, $username, $password, $role, $mmr, $race);
            
            // Check if email or username already exists
            $this->checkDuplicates($email, $username);
            
            // Hash password
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            
            // Generate UUID for user
            $userId = $this->generateUUID();
            
            // Insert user
            $conn = $this->db->getConnection();
            $stmt = $conn->prepare("
                INSERT INTO users (
                    id, email, username, password, role, mmr, race_preference
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?
                )
            ");
            
            $stmt->execute([
                $userId,
                $email,
                $username,
                $hashedPassword,
                $role,
                $mmr,
                $race
            ]);
            
            // Return success response
            return json_encode([
                'success' => true,
                'data' => [
                    'id' => $userId,
                    'email' => $email,
                    'username' => $username,
                    'role' => $role,
                    'mmr' => $mmr,
                    'race_preference' => $race
                ]
            ]);
            
        } catch (Exception $e) {
            return json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    private function validateInput($email, $username, $password, $role, $mmr, $race) {
        // Validate email
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Invalid email format");
        }
        
        // Validate username (minimum 3 characters)
        if (strlen($username) < 3) {
            throw new Exception("Username must be at least 3 characters");
        }
        
        // Validate password (at least 8 chars, with numbers and letters)
        if (strlen($password) < 8 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
            throw new Exception("Password must be at least 8 characters and contain both letters and numbers");
        }
        
        // Validate role
        if (!in_array($role, ['user', 'pro'])) {
            throw new Exception("Invalid role. Must be 'user' or 'pro'");
        }
        
        // Validate MMR if provided
        if ($mmr !== null && (!is_numeric($mmr) || $mmr < 0 || $mmr > 8000)) {
            throw new Exception("Invalid MMR. Must be between 0 and 8000");
        }
        
        // Validate race if provided
        if ($race !== null && !in_array($race, ['Protoss', 'Terran', 'Zerg', 'Random'])) {
            throw new Exception("Invalid race preference");
        }
    }
    
    private function checkDuplicates($email, $username) {
        $conn = $this->db->getConnection();
        
        // Check email
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            throw new Exception("Email already registered");
        }
        
        // Check username
        $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            throw new Exception("Username already taken");
        }
    }
    
    private function generateUUID() {
        $stmt = $this->db->getConnection()->query("SELECT UUID()");
        return $stmt->fetchColumn();
    }
}

class BiddingSystem {
    private $db;
    
    public function __construct() {
        $this->db = new Database();
    }
    
    public function getAvailableMatches() {
        try {
            $conn = $this->db->getConnection();
            $stmt = $conn->query("
                SELECT 
                    m.id,
                    m.title,
                    m.description,
                    m.date,
                    m.time,
                    m.match_type,
                    m.min_bid,
                    m.current_bid,
                    u.username as pro_username,
                    u.avatar_url as pro_avatar,
                    u.mmr as pro_mmr,
                    u.race_preference as pro_race
                FROM matches m
                JOIN users u ON m.pro_id = u.id
                WHERE m.status = 'scheduled'
                AND m.date >= CURDATE()
                ORDER BY m.date ASC, m.time ASC
                LIMIT 10
            ");
            return json_encode([
                'success' => true,
                'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)
            ]);
        } catch (PDOException $e) {
            return json_encode([
                'success' => false,
                'error' => "Error fetching matches: " . $e->getMessage()
            ]);
        }
    }
    
    public function getCurrentBids($matchId) {
        try {
            $conn = $this->db->getConnection();
            $stmt = $conn->prepare("
                SELECT 
                    b.id,
                    b.amount,
                    b.status,
                    b.bid_time,
                    u.username,
                    u.avatar_url,
                    u.mmr
                FROM bids b
                JOIN users u ON b.user_id = u.id
                WHERE b.match_id = ?
                ORDER BY b.amount DESC
                LIMIT 10
            ");
            $stmt->execute([$matchId]);
            return json_encode([
                'success' => true,
                'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)
            ]);
        } catch (PDOException $e) {
            return json_encode([
                'success' => false,
                'error' => "Error fetching bids: " . $e->getMessage()
            ]);
        }
    }
    
    public function placeBid($matchId, $userId, $amount) {
        try {
            $conn = $this->db->getConnection();
            
            // Start transaction
            $conn->beginTransaction();
            
            // Verify match exists and is available
            $stmt = $conn->prepare("
                SELECT min_bid, current_bid, status 
                FROM matches 
                WHERE id = ?
            ");
            $stmt->execute([$matchId]);
            $match = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$match) {
                throw new Exception("Match not found");
            }
            
            if ($match['status'] !== 'scheduled') {
                throw new Exception("Match is no longer available for bidding");
            }
            
            if ($amount < $match['min_bid']) {
                throw new Exception("Bid amount must be at least " . $match['min_bid']);
            }
            
            if ($match['current_bid'] && $amount <= $match['current_bid']) {
                throw new Exception("Bid amount must be higher than current bid of " . $match['current_bid']);
            }
            
            // Check if user already has a bid for this match
            $stmt = $conn->prepare("
                SELECT id, amount 
                FROM bids 
                WHERE match_id = ? AND user_id = ?
            ");
            $stmt->execute([$matchId, $userId]);
            $existingBid = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $bidId = null;
            if ($existingBid) {
                // Update existing bid
                $stmt = $conn->prepare("
                    UPDATE bids 
                    SET amount = ?, status = 'pending'
                    WHERE id = ?
                ");
                $stmt->execute([$amount, $existingBid['id']]);
                $bidId = $existingBid['id'];
            } else {
                // Create new bid
                $bidId = $this->generateUUID();
                $stmt = $conn->prepare("
                    INSERT INTO bids (id, match_id, user_id, amount, status) 
                    VALUES (?, ?, ?, ?, 'pending')
                ");
                $stmt->execute([$bidId, $matchId, $userId, $amount]);
            }
            
            // Commit transaction
            $conn->commit();
            
            // Return the bid details
            $stmt = $conn->prepare("
                SELECT 
                    b.id,
                    b.amount,
                    b.status,
                    b.bid_time,
                    u.username,
                    m.title as match_title
                FROM bids b
                JOIN users u ON b.user_id = u.id
                JOIN matches m ON b.match_id = m.id
                WHERE b.id = ?
            ");
            $stmt->execute([$bidId]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            throw new Exception("Error placing bid: " . $e->getMessage());
        }
    }
    
    private function generateUUID() {
        $stmt = $this->db->getConnection()->query("SELECT UUID()");
        return $stmt->fetchColumn();
    }
}

class Auth {
    private $db;
    
    public function __construct() {
        $this->db = new Database();
    }
    
    public function login($email, $password) {
        try {
            $conn = $this->db->getConnection();
            $stmt = $conn->prepare("SELECT id, username, password, role FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user || !password_verify($password, $user['password'])) {
                return json_encode([
                    'success' => false,
                    'error' => 'Invalid email or password'
                ]);
            }
            
            // Generate auth token
            $authToken = bin2hex(random_bytes(32));
            
            // Store token in database
            $stmt = $conn->prepare("UPDATE users SET auth_token = ? WHERE id = ?");
            $stmt->execute([$authToken, $user['id']]);
            
            return json_encode([
                'success' => true,
                'data' => [
                    'id' => $user['id'],
                    'username' => $user['username'],
                    'role' => $user['role'],
                    'authToken' => $authToken
                ]
            ]);
        } catch (Exception $e) {
            return json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    public function validateToken($token) {
        try {
            $conn = $this->db->getConnection();
            $stmt = $conn->prepare("SELECT id, username, role FROM users WHERE auth_token = ?");
            $stmt->execute([$token]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return $user ? $user : false;
        } catch (Exception $e) {
            return false;
        }
    }
    
    public function logout($token) {
        try {
            $conn = $this->db->getConnection();
            $stmt = $conn->prepare("UPDATE users SET auth_token = NULL WHERE auth_token = ?");
            $stmt->execute([$token]);
            
            return json_encode([
                'success' => true
            ]);
        } catch (Exception $e) {
            return json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
    }
}

$biddingSystem = new BiddingSystem();

try {
    $method = $_SERVER['REQUEST_METHOD'];
    
    switch ($method) {
        case 'GET':
            $matchId = $_GET['match_id'] ?? null;
            if ($matchId) {
                $result = $biddingSystem->getCurrentBids($matchId);
            } else {
                $result = $biddingSystem->getAvailableMatches();
            }
            echo $result;
            break;
            
        case 'POST':
            // Check if this is a registration request
            if (isset($_GET['action'])) {
                if ($_GET['action'] === 'register') {
                    $registrationSystem = new RegistrationSystem();
                    $data = json_decode(file_get_contents('php://input'), true);
                    
                    if (!isset($data['email']) || !isset($data['username']) || !isset($data['password'])) {
                        throw new Exception('Missing required fields: email, username, and password are required');
                    }
                    
                    $result = $registrationSystem->register(
                        $data['email'],
                        $data['username'],
                        $data['password'],
                        $data['role'] ?? 'user',
                        $data['mmr'] ?? null,
                        $data['race_preference'] ?? null
                    );
                    echo $result;
                } else if ($_GET['action'] === 'login') {
                    $auth = new Auth();
                    $data = json_decode(file_get_contents('php://input'), true);
                    
                    if (!isset($data['email']) || !isset($data['password'])) {
                        throw new Exception('Missing required fields: email and password are required');
                    }
                    
                    $result = $auth->login($data['email'], $data['password']);
                    echo $result;
                }
            } else {
                // Handle bidding request
                $data = json_decode(file_get_contents('php://input'), true);
                
                if (!isset($data['match_id']) || !isset($data['user_id']) || !isset($data['amount'])) {
                    throw new Exception('Missing required fields: match_id, user_id, and amount are required');
                }
                
                $result = $biddingSystem->placeBid($data['match_id'], $data['user_id'], $data['amount']);
                echo $result;
            }
            break;
            
        default:
            throw new Exception('Method not allowed');
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
} 