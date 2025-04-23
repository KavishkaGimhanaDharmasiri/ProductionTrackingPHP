<?php
    include('index.php');
    include('connection.php');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PDF Viewer</title>
    <style>


        .folder {
            margin-top: 10px;
            margin-bottom: 10px;
        }
        .folder-name {
            font-weight: bold;
            margin-bottom: 5px;
            font-size: 18px;
            color: #4CAF50;
        }
        .pdf-list {
            list-style-type: none;
            padding: 0;
        }
        .pdf-list li {
            margin-bottom: 5px;
            padding: 8px;
            background-color: #e7e7e7;
            border-radius: 5px;
            transition: background-color 0.3s;
        }
        .pdf-list li:hover {
            background-color: #ccc;
        }
        .pdf-link {
            text-decoration: none;
            color: #333;
            font-size: 16px;
        }
        .pdf-link:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>PDF Files Viewer</h1>
        <?php
        function listPdfFiles($dir) {
            // Open the directory
            if ($handle = opendir($dir)) {
                echo "<div class='folder'>";
                // Loop through the directory
                while (false !== ($entry = readdir($handle))) {
                    // Skip the special directories "." and ".."
                    if ($entry != "." && $entry != "..") {
                        $fullPath = $dir . '/' . $entry;
                        // If it's a directory, recursively list files
                        if (is_dir($fullPath)) {
                            echo "<div class='folder-name'>$entry</div>";
                            listPdfFiles($fullPath);
                        } elseif (pathinfo($entry, PATHINFO_EXTENSION) == 'pdf') {
                            // If it's a PDF, create a link
                            echo "<ul class='pdf-list'><li><a class='pdf-link' href='$fullPath' target='_blank'>$entry</a></li></ul>";
                        }
                    }
                }
                echo "</div>";
                closedir($handle);
            }
        }

        // Specify the directory to start from
        $reportDir = 'report';

        // List all PDF files in the directory and its subdirectories
        listPdfFiles($reportDir);
        ?>
    </div>
</body>
</html>
