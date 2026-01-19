<?php
// Set error reporting to maximum
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    echo "<h2>Form Submitted</h2>";
    echo "<pre>";
    echo "POST data: ";
    print_r($_POST);
    echo "\n\nFILES data: ";
    print_r($_FILES);
    echo "</pre>";
    
    if (isset($_FILES['simple_file']) && $_FILES['simple_file']['error'] == 0) {
        echo "<p>File uploaded successfully!</p>";
        
        // Move the uploaded file
        $upload_dir = "images/test_uploads";
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        $filename = time() . '_' . $_FILES['simple_file']['name'];
        $upload_path = $upload_dir . '/' . $filename;
        
        if (move_uploaded_file($_FILES['simple_file']['tmp_name'], $upload_path)) {
            echo "<p>File moved to: $upload_path</p>";
            echo "<p><img src='$upload_path' alt='Uploaded Image' style='max-width: 300px;'></p>";
        } else {
            echo "<p>Failed to move uploaded file.</p>";
        }
    } else {
        echo "<p>No file uploaded or error occurred.</p>";
        if (isset($_FILES['simple_file'])) {
            echo "<p>Upload error code: " . $_FILES['simple_file']['error'] . "</p>";
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Simple File Upload</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        form {
            border: 1px solid #ddd;
            padding: 20px;
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <h1>Simple File Upload Test</h1>
    
    <form method="POST" action="simple_upload.php" enctype="multipart/form-data">
        <p>Select a file to upload:</p>
        <input type="file" name="simple_file">
        <br><br>
        <button type="submit">Upload File</button>
    </form>
    
    <h2>PHP Configuration</h2>
    <ul>
        <li>upload_max_filesize: <?php echo ini_get('upload_max_filesize'); ?></li>
        <li>post_max_size: <?php echo ini_get('post_max_size'); ?></li>
        <li>max_file_uploads: <?php echo ini_get('max_file_uploads'); ?></li>
    </ul>
</body>
</html> 