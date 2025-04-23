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
include 'index.php'; // Ensure this doesn’t break headers
?>

<!DOCTYPE html>
<html>
<head>
    <title>Summary Report - Cement Factory</title>
    <link href="assets/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="styles.css">
    <style>
        .summary-table { margin-top: 20px; }
        .card-header { background-color: #f8f9fa; }
    </style>
</head>
<body>
    <div class="container mt-4">
        <h1>Summary Report</h1>
        <p>Current Time in Colombo: <?php echo date('Y-m-d H:i:s'); ?></p>
        <a href="index.php" class="btn btn-secondary mb-3">Back to Dashboard</a>

        <!-- Raw Material Consumption Summary -->
        <div class="card mb-4">
            <div class="card-header">Raw Material Consumption Summary</div>
            <div class="card-body">
                <h3>Weekly Consumption</h3>
                <table class="table table-striped summary-table">
                    <thead>
                        <tr>
                            <th>Week</th>
                            <th>Raw Material</th>
                            <th>Quantity Consumed</th>
                            <th>Unit Type</th>
                            <th>Total Cost (LKR)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $stmt = $conn->prepare("
                            SELECT 
                                YEARWEEK(ph.production_date) AS week,
                                rm.name AS material_name,
                                rm.unit_type,
                                rm.unit_price,
                                SUM(pi.quantity * CEIL(ph.quantity / p.batch_yield)) AS total_quantity
                            FROM production_history ph
                            JOIN products p ON ph.product_id = p.id
                            JOIN product_ingredients pi ON p.id = pi.product_id
                            JOIN raw_materials rm ON pi.raw_material_id = rm.id
                            GROUP BY YEARWEEK(ph.production_date), rm.id
                            ORDER BY week DESC, material_name
                        ");
                        $stmt->execute();
                        $result = $stmt->get_result();
                        while ($row = $result->fetch_assoc()) {
                            $week_start = new DateTime();
                            $week_start->setISODate(substr($row['week'], 0, 4), substr($row['week'], 4));
                            $week_display = $week_start->format('Y-m-d') . " to " . $week_start->modify('+6 days')->format('Y-m-d');
                            $total_cost = $row['total_quantity'] * $row['unit_price'];
                            echo "<tr>
                                <td>{$week_display}</td>
                                <td>{$row['material_name']}</td>
                                <td>" . number_format($row['total_quantity'], 2) . "</td>
                                <td>{$row['unit_type']}</td>
                                <td>" . number_format($total_cost, 2) . "</td>
                            </tr>";
                        }
                        $stmt->close();
                        ?>
                    </tbody>
                </table>

                <h3>Monthly Consumption</h3>
                <table class="table table-striped summary-table">
                    <thead>
                        <tr>
                            <th>Month</th>
                            <th>Raw Material</th>
                            <th>Quantity Consumed</th>
                            <th>Unit Type</th>
                            <th>Total Cost (LKR)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $stmt = $conn->prepare("
                            SELECT 
                                DATE_FORMAT(ph.production_date, '%Y-%m') AS month,
                                rm.name AS material_name,
                                rm.unit_type,
                                rm.unit_price,
                                SUM(pi.quantity * CEIL(ph.quantity / p.batch_yield)) AS total_quantity
                            FROM production_history ph
                            JOIN products p ON ph.product_id = p.id
                            JOIN product_ingredients pi ON p.id = pi.product_id
                            JOIN raw_materials rm ON pi.raw_material_id = rm.id
                            GROUP BY DATE_FORMAT(ph.production_date, '%Y-%m'), rm.id
                            ORDER BY month DESC, material_name
                        ");
                        $stmt->execute();
                        $result = $stmt->get_result();
                        while ($row = $result->fetch_assoc()) {
                            $month_display = DateTime::createFromFormat('Y-m', $row['month'])->format('F Y');
                            $total_cost = $row['total_quantity'] * $row['unit_price'];
                            echo "<tr>
                                <td>{$month_display}</td>
                                <td>{$row['material_name']}</td>
                                <td>" . number_format($row['total_quantity'], 2) . "</td>
                                <td>{$row['unit_type']}</td>
                                <td>" . number_format($total_cost, 2) . "</td>
                            </tr>";
                        }
                        $stmt->close();
                        ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Sell Product Summary -->
        <div class="card mb-4">
            <div class="card-header">Sell Product Summary</div>
            <div class="card-body">
                <h3>Weekly Sales</h3>
                <table class="table table-striped summary-table">
                    <thead>
                        <tr>
                            <th>Week</th>
                            <th>Product</th>
                            <th>Stock Level</th>
                            <th>Quantity Sold</th>
                            <th>Total Amount (LKR)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $stmt = $conn->prepare("
                            SELECT 
                                YEARWEEK(s.sale_date) AS week,
                                p.name AS product_name,
                                si.stock_level,
                                SUM(si.quantity) AS total_quantity,
                                SUM(si.quantity * (si.unit_price - si.discount)) AS total_amount
                            FROM sales s
                            JOIN sales_items si ON s.id = si.sale_id
                            JOIN products p ON si.product_id = p.id
                            GROUP BY YEARWEEK(s.sale_date), p.id, si.stock_level
                            ORDER BY week DESC, product_name, stock_level
                        ");
                        $stmt->execute();
                        $result = $stmt->get_result();
                        while ($row = $result->fetch_assoc()) {
                            $week_start = new DateTime();
                            $week_start->setISODate(substr($row['week'], 0, 4), substr($row['week'], 4));
                            $week_display = $week_start->format('Y-m-d') . " to " . $week_start->modify('+6 days')->format('Y-m-d');
                            echo "<tr>
                                <td>{$week_display}</td>
                                <td>{$row['product_name']}</td>
                                <td>" . ucwords(str_replace('-', ' ', $row['stock_level'])) . "</td>
                                <td>{$row['total_quantity']}</td>
                                <td>" . number_format($row['total_amount'], 2) . "</td>
                            </tr>";
                        }
                        $stmt->close();
                        ?>
                    </tbody>
                </table>

                <h3>Monthly Sales</h3>
                <table class="table table-striped summary-table">
                    <thead>
                        <tr>
                            <th>Month</th>
                            <th>Product</th>
                            <th>Stock Level</th>
                            <th>Quantity Sold</th>
                            <th>Total Amount (LKR)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $stmt = $conn->prepare("
                            SELECT 
                                DATE_FORMAT(s.sale_date, '%Y-%m') AS month,
                                p.name AS product_name,
                                si.stock_level,
                                SUM(si.quantity) AS total_quantity,
                                SUM(si.quantity * (si.unit_price - si.discount)) AS total_amount
                            FROM sales s
                            JOIN sales_items si ON s.id = si.sale_id
                            JOIN products p ON si.product_id = p.id
                            GROUP BY DATE_FORMAT(s.sale_date, '%Y-%m'), p.id, si.stock_level
                            ORDER BY month DESC, product_name, stock_level
                        ");
                        $stmt->execute();
                        $result = $stmt->get_result();
                        while ($row = $result->fetch_assoc()) {
                            $month_display = DateTime::createFromFormat('Y-m', $row['month'])->format('F Y');
                            echo "<tr>
                                <td>{$month_display}</td>
                                <td>{$row['product_name']}</td>
                                <td>" . ucwords(str_replace('-', ' ', $row['stock_level'])) . "</td>
                                <td>{$row['total_quantity']}</td>
                                <td>" . number_format($row['total_amount'], 2) . "</td>
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