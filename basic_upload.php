<?php
// Display all errors
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Process the form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    echo '<h2>Form submitted</h2>';
    
    echo '<h3>POST Data:</h3>';
    echo '<pre>';
    print_r($_POST);
    echo '</pre>';
    
    echo '<h3>FILES Data:</h3>';
    echo '<pre>';
    print_r($_FILES);
    echo '</pre>';
    
    // Check if file was uploaded
    if (isset($_FILES['user_file']) && $_FILES['user_file']['error'] == 0) {
        $upload_dir = 'uploads/';
        
        // Create directory if it doesn't exist
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        $upload_file = $upload_dir . basename($_FILES['user_file']['name']);
        
        // Move the file
        if (move_uploaded_file($_FILES['user_file']['tmp_name'], $upload_file)) {
            echo '<p style="color: green;">File was successfully uploaded to: ' . $upload_file . '</p>';
            echo '<p><img src="' . $upload_file . '" style="max-width: 300px;"></p>';
        } else {
            echo '<p style="color: red;">Error uploading file!</p>';
        }
    } else {
        echo '<p style="color: red;">No file uploaded or error occurred.</p>';
        if (isset($_FILES['user_file'])) {
            echo '<p>Error code: ' . $_FILES['user_file']['error'] . '</p>';
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Basic File Upload</title>
</head>
<body>
    <h1>Basic File Upload Test</h1>
    
    <form action="basic_upload.php" method="post" enctype="multipart/form-data">
        <p>Select image to upload:</p>
        <input type="file" name="user_file">
        <br><br>
        <input type="submit" value="Upload Image" name="submit">
    </form>
    
    <h2>PHP Configuration</h2>
    <ul>
        <li>upload_max_filesize: <?php echo ini_get('upload_max_filesize'); ?></li>
        <li>post_max_size: <?php echo ini_get('post_max_size'); ?></li>
        <li>max_file_uploads: <?php echo ini_get('max_file_uploads'); ?></li>
        <li>file_uploads: <?php echo ini_get('file_uploads'); ?></li>
        <li>upload_tmp_dir: <?php echo ini_get('upload_tmp_dir'); ?></li>
    </ul>
</body>
</html> 