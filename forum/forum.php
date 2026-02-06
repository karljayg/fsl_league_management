<?php
require_once(__DIR__ . "/config.php");

// Create connection
$conn = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Function to display paginated list of threads
function displayThreads($page, $limit) {
    global $conn;
    
    $offset = ($page - 1) * $limit;
    $sql = "SELECT id, date, author, subject FROM forumthreads LIMIT $offset, $limit";
    
    $result = $conn->query($sql);
    
    if ($result->num_rows > 0) {
        while($row = $result->fetch_assoc()) {
            echo "<a href='viewPost.php?id={$row['id']}'>{$row['subject']}</a> by {$row['author']} on {$row['date']}<br>";
        }
    } else {
        echo "0 results";
    }
}

// Function to display individual post details with child posts
function displayPostDetails($id) {
    global $conn;

    $sql = "SELECT * FROM forumthreads WHERE id = $id";
    $result = $conn->query($sql);

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        echo "<h1>{$row['subject']}</h1> by {$row['author']} on {$row['date']}<br>";
        
        // Get child posts
        $child_sql = "SELECT * FROM forumbodies WHERE parent = $id";
        $child_result = $conn->query($child_sql);

        if ($child_result->num_rows > 0) {
            while($child_row = $child_result->fetch_assoc()) {
                echo "<div style='margin-left: 20px;'>Post ID: {$child_row['id']} - Content: {$child_row['body']}</div>";
            }
        }
    } else {
        echo "0 results";
    }
}

// Use these functions wherever necessary in your script, for example:
// displayThreads(1, 10);
// displayPostDetails(1);

$conn->close();
?>

