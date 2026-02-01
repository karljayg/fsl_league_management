<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// First check if the permission exists in the session (backward compatibility)
if (isset($_SESSION['user_permissions']) && in_array($required_permission, $_SESSION['user_permissions'])) {
    // User has the required permission in session, allow access
    return;
}

// Include the database connection file (which works on other pages).
require_once 'db.php';

// Determine the active DB connection variable.
if (isset($pdo)) {
    $db = $pdo;
} elseif (isset($conn)) {
    $db = $conn;
} elseif (isset($db)) {
    // $db is already set, use it as is
} else {
    die("Database connection error: no database connection variable is set.");
}

// Ensure the required permission parameter is set by the caller.
if (!isset($required_permission)) {
    die("Permission check error: no permission parameter set.");
}

// Check if the user is logged in.
if (!isset($_SESSION['user_id'])) {
    die("Permission denied: user not logged in.");
}
$user_id = $_SESSION['user_id'];

// Include permission functions
require_once __DIR__ . '/permission_functions.php';

// Check if user has permission through any of their roles
$hasPermission = false;

// Get all user roles from ws_user_roles table
$stmt = $db->prepare("
    SELECT ur.role_id
    FROM ws_user_roles ur
    WHERE ur.user_id = ?
");
$stmt->execute([$user_id]);
$userRoles = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($userRoles)) {
    die("Permission denied: user has no roles assigned.");
}

// Check each role for the required permission
foreach ($userRoles as $userRole) {
    if (hasPermission($db, $userRole['role_id'], $required_permission)) {
        $hasPermission = true;
        break;
    }
}

if (!$hasPermission) {
    die("Permission denied: user does not have the required permission '$required_permission'.");
}

// If the script reaches here, the user has the required permission.
// Your page can continue processing normally.
?>
