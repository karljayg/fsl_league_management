<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Set execution time and memory limits
set_time_limit(30);
ini_set('memory_limit', '128M');

echo "<h1>File Upload Processor</h1>";

// Display PHP configuration for file uploads
echo "<h2>PHP Upload Configuration</h2>";
echo "<pre>";
echo "file_uploads: " . ini_get('file_uploads') . "\n";
echo "upload_max_filesize: " . ini_get('upload_max_filesize') . "\n";
echo "max_file_uploads: " . ini_get('max_file_uploads') . "\n";
echo "post_max_size: " . ini_get('post_max_size') . "\n";
echo "upload_tmp_dir: " . ini_get('upload_tmp_dir') . "\n";
echo "</pre>";

// Check if a file was uploaded
if (!isset($_FILES['uploaded_file'])) {
    echo "<p style='color: red;'>Error: No file was submitted.</p>";
    echo "<p><a href='upload_form.html'>Go back to upload form</a></p>";
    exit;
}

// Display raw $_FILES data for debugging
echo "<h2>Raw \$_FILES Data</h2>";
echo "<pre>";
print_r($_FILES);
echo "</pre>";

// Check for upload errors
$upload_error = $_FILES['uploaded_file']['error'];
if ($upload_error !== UPLOAD_ERR_OK) {
    echo "<p style='color: red;'>Upload Error: ";
    switch ($upload_error) {
        case UPLOAD_ERR_INI_SIZE:
            echo "The uploaded file exceeds the upload_max_filesize directive in php.ini.";
            break;
        case UPLOAD_ERR_FORM_SIZE:
            echo "The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form.";
            break;
        case UPLOAD_ERR_PARTIAL:
            echo "The uploaded file was only partially uploaded.";
            break;
        case UPLOAD_ERR_NO_FILE:
            echo "No file was uploaded.";
            break;
        case UPLOAD_ERR_NO_TMP_DIR:
            echo "Missing a temporary folder.";
            break;
        case UPLOAD_ERR_CANT_WRITE:
            echo "Failed to write file to disk.";
            break;
        case UPLOAD_ERR_EXTENSION:
            echo "A PHP extension stopped the file upload.";
            break;
        default:
            echo "Unknown upload error.";
    }
    echo "</p>";
    echo "<p><a href='upload_form.html'>Go back to upload form</a></p>";
    exit;
}

// Create uploads directory if it doesn't exist
$upload_dir = 'uploads';
if (!file_exists($upload_dir)) {
    if (!mkdir($upload_dir, 0777, true)) {
        echo "<p style='color: red;'>Failed to create uploads directory.</p>";
        echo "<p><a href='upload_form.html'>Go back to upload form</a></p>";
        exit;
    }
}

// Get file information
$file_name = $_FILES['uploaded_file']['name'];
$file_tmp = $_FILES['uploaded_file']['tmp_name'];
$file_size = $_FILES['uploaded_file']['size'];
$file_type = $_FILES['uploaded_file']['type'];

// Generate a unique filename to prevent overwriting
$new_file_name = $upload_dir . '/' . uniqid() . '_' . $file_name;

// Try to move the uploaded file
if (move_uploaded_file($file_tmp, $new_file_name)) {
    echo "<p style='color: green;'>File successfully uploaded!</p>";
    echo "<p>File saved as: " . htmlspecialchars($new_file_name) . "</p>";
    echo "<p>File type: " . htmlspecialchars($file_type) . "</p>";
    echo "<p>File size: " . htmlspecialchars($file_size) . " bytes</p>";
    
    // If it's an image, display it
    if (strpos($file_type, 'image/') === 0) {
        echo "<p><img src='" . htmlspecialchars($new_file_name) . "' style='max-width: 500px;'></p>";
    }
} else {
    echo "<p style='color: red;'>Failed to move uploaded file.</p>";
    echo "<p>Temporary file: " . htmlspecialchars($file_tmp) . "</p>";
    echo "<p>Destination: " . htmlspecialchars($new_file_name) . "</p>";
    
    // Check if the temporary file exists and is readable
    if (file_exists($file_tmp)) {
        echo "<p>Temporary file exists.</p>";
        if (is_readable($file_tmp)) {
            echo "<p>Temporary file is readable.</p>";
        } else {
            echo "<p>Temporary file is not readable.</p>";
        }
    } else {
        echo "<p>Temporary file does not exist.</p>";
    }
    
    // Check if the destination directory is writable
    if (is_writable($upload_dir)) {
        echo "<p>Upload directory is writable.</p>";
    } else {
        echo "<p>Upload directory is not writable.</p>";
    }
}

echo "<p><a href='upload_form.html'>Go back to upload form</a></p>";
?> 