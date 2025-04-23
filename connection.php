<?php
$conn = mysqli_connect("localhost", "root", "2000", "bakery_db");

if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

$config_file = 'config.json';
$config = json_decode(file_get_contents($config_file), true);

function isSystemActive($config) {
    if ($config['is_activated']) {
        return true;
    }

    $trial_start = $config['trial_start'];
    $trial_period = 30 * 24 * 60 * 60; // 30 days in seconds
    $current_time = time();

    return ($current_time - $trial_start) <= $trial_period;
}

if (!isSystemActive($config)) {
    header("Location: activation.php");
    exit;
}
?>

<!-- Rest of your existing PHP/HTML code follows -->