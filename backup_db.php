<?php
// Set Sri Lanka (Colombo) time zone
date_default_timezone_set('Asia/Colombo');

// Database connection details (same as connection.php)
$db_host = 'localhost';
$db_user = 'root'; // Adjust if your MySQL user is different
$db_pass = '';     // Adjust if you have a password
$db_name = 'bakery_db';

// Backup directory
$backup_dir = 'D:/autobackup';
if (!file_exists($backup_dir)) {
    mkdir($backup_dir, 0777, true);
}

// Backup file name with timestamp
$timestamp = date('Y-m-d_H-i-s');
$backup_file = "$backup_dir/{$db_name}_{$timestamp}.sql";

// Path to mysqldump (adjust based on your WAMP/XAMPP installation)
$mysqldump_path = '"C:/wamp64/bin/mysql/mysql8.0.30/bin/mysqldump.exe"'; // Example for WAMP

// Command to execute mysqldump
$command = "$mysqldump_path --host=$db_host --user=$db_user";
if ($db_pass) {
    $command .= " --password=$db_pass";
}
$command .= " $db_name > \"$backup_file\"";

// Execute the command
exec($command, $output, $return_var);

if ($return_var === 0) {
    echo "Database backup successful: $backup_file";
} else {
    echo "Backup failed. Error code: $return_var";
    print_r($output); // For debugging
}
?>