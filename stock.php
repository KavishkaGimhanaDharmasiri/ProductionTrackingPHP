<?php
// Start output buffering
ob_start();

// Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Set Sri Lanka (Colombo) time zone
date_default_timezone_set('Asia/Colombo');

// Include database connection and index
include 'connection.php';
include 'index.php'; // Ensure this doesn’t output content that breaks headers
?>

<!DOCTYPE html>
<html>
<head>
    <title>Stock Management - Cement Factory</title>
    <link href="assets/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div class="container mt-4">
        <h1>Stock Management</h1>
        
        <a href="index.php" class="btn btn-secondary mb-3">Back to Dashboard</a>

        <div class="row">
            <div class="col-md-6">
                <h3>Raw Materials Stock</h3>
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Stock</th>
                            <th>Unit Type</th>
                            <th>Unit Price (LKR)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $stmt = $conn->prepare("SELECT * FROM raw_materials");
                        $stmt->execute();
                        $materials = $stmt->get_result();
                        while ($row = $materials->fetch_assoc()) {
                            echo "<tr>
                                <td>{$row['name']}</td>
                                <td>" . number_format($row['stock'], 2) . "</td>
                                <td>{$row['unit_type']}</td>
                                <td>" . number_format($row['unit_price'], 2) . "</td>
                            </tr>";
                        }
                        $stmt->close();
                        ?>
                    </tbody>
                </table>
            </div>
            <div class="col-md-6">
                <h3>Products Stock & Cost</h3>
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Wet Stock</th>
                            <th>Dry Stock</th>
                            <th>Polish-Cut Stock</th>
                            <th>Polish-Jagged Stock</th>
                            <th>Damage Stock</th>
                            <th>Cost per Batch (LKR)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $stmt = $conn->prepare("SELECT * FROM products");
                        $stmt->execute();
                        $products = $stmt->get_result();
                        while ($prod = $products->fetch_assoc()) {
                            $cost = 0;
                            $stmt_ing = $conn->prepare("SELECT pi.quantity, rm.unit_price 
                                FROM product_ingredients pi 
                                JOIN raw_materials rm ON pi.raw_material_id = rm.id 
                                WHERE pi.product_id = ?");
                            $stmt_ing->bind_param("i", $prod['id']);
                            $stmt_ing->execute();
                            $ingredients = $stmt_ing->get_result();
                            while ($ing = $ingredients->fetch_assoc()) {
                                $cost += $ing['quantity'] * $ing['unit_price']; // Cost per batch
                            }
                            $stmt_ing->close();

                            echo "<tr>
                                <td>{$prod['name']}</td>
                                <td>{$prod['wet_stock']}</td>
                                <td>{$prod['dry_stock']}</td>
                                <td>{$prod['polish_cut_stock']}</td>
                                <td>{$prod['polish_jagged_stock']}</td>
                                <td>{$prod['damage_stock']}</td>
                                <td>" . number_format($cost, 2) . "</td>
                            </tr>";
                        }
                        $stmt->close();
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script src="assets/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>