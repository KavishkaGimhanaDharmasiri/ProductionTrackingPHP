<?php
// activation.php
$conn = mysqli_connect("localhost", "root", "2000", "bakery_db");

if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

$config_file = 'config.json';
$config = json_decode(file_get_contents($config_file), true);

// Set trial start date on first use
if ($config['trial_start'] === null) {
    $config['trial_start'] = time();
    file_put_contents($config_file, json_encode($config));
}

// Handle activation submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['activate'])) {
    $entered_key = $_POST['activation_key'];
    $valid_key = "BAKERY2025"; // Define your valid activation key here

    if ($entered_key === $valid_key) {
        $config['is_activated'] = true;
        $config['activation_key'] = $entered_key;
        file_put_contents($config_file, json_encode($config));
        header("Location: index.php");
        exit;
    } else {
        echo "<script>alert('Invalid activation key');</script>";
    }
}

// Function to check trial/activation status
function isSystemActive($config) {
    if ($config['is_activated']) {
        return true;
    }

    $trial_start = $config['trial_start'];
    $trial_period = 30 * 24 * 60 * 60; // 30 days in seconds
    $current_time = time();

    return ($current_time - $trial_start) <= $trial_period;
}

// Store status for use in HTML
$is_active = isSystemActive($config);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Activation - Bakery Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .popup { 
            display: none; 
            position: fixed; 
            top: 50%; 
            left: 50%; 
            transform: translate(-50%, -50%); 
            background: white; 
            padding: 20px; 
            border: 1px solid #ccc; 
            box-shadow: 0 0 10px rgba(0,0,0,0.5); 
            z-index: 1000; 
        }
        .overlay { 
            display: none; 
            position: fixed; 
            top: 0; 
            left: 0; 
            width: 100%; 
            height: 100%; 
            background: rgba(0,0,0,0.5); 
            z-index: 999; 
        }
    </style>
</head>
<body>
    <div class="container mt-4">
        <h1>Bakery Management System</h1>
        <?php if ($is_active): ?>
            <p>System is active. Redirecting to dashboard...</p>
            <script>window.location.href = 'index.php';</script>
        <?php else: ?>
            <div id="overlay" class="overlay" style="display: block;"></div>
            <div id="popup" class="popup" style="display: block;">
                <h3>Trial Period Ended</h3>
                <p>The trial period has expired. Please enter your activation key to continue using the system.</p>
                <form method="post">
                    <div class="mb-3">
                        <label class="form-label">Activation Key</label>
                        <input type="text" name="activation_key" class="form-control" placeholder="Enter activation key" required>
                    </div>
                    <button type="submit" name="activate" class="btn btn-primary">Activate</button>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>