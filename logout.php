<?php
// Start the session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Clear the auth token cookie
setcookie('authToken', '', time() - 3600, '/');

// Destroy the session
session_destroy();

// Redirect to home page
header('Location: index.php');
exit; 