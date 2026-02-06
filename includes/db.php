<?php
/**
 * Database connection file
 * This file provides database connection variables for the application
 */

// Include the configuration file
require_once dirname(__DIR__) . '/config.php';

// Extract database connection variables
$db_host = $config['db_host'];
$db_name = $config['db_name'];
$db_user = $config['db_user'];
$db_pass = $config['db_pass'];

// Create database connection
try {
    $db = new PDO("mysql:host={$db_host};dbname={$db_name}", $db_user, $db_pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $GLOBALS['db'] = $db;
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
} 