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

// Handle stock adjustments
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $conn->begin_transaction();
    try {
        if (isset($_POST['adjust_raw_material'])) {
            $material_id = (int)$_POST['material_id'];
            $adjustment_type = $_POST['adjustment_type'];
            $quantity = floatval($_POST['quantity']);
            $reason = $_POST['reason'];

            $stmt = $conn->prepare("UPDATE raw_materials SET stock = stock " . ($adjustment_type == 'increase' ? '+' : '-') . " ? WHERE id = ?");
            $stmt->bind_param("di", $quantity, $material_id);
            if (!$stmt->execute() || $stmt->affected_rows == 0) {
                throw new Exception("Failed to update raw material stock: " . $stmt->error);
            }
            $stmt->close();

            $stmt = $conn->prepare("INSERT INTO stock_adjustments (type, item_id, adjustment_type, quantity, reason, adjustment_date) 
                VALUES ('raw_material', ?, ?, ?, ?, NOW())");
            $stmt->bind_param("issd", $material_id, $adjustment_type, $quantity, $reason);
            if (!$stmt->execute()) {
                throw new Exception("Failed to log raw material adjustment: " . $stmt->error);
            }
            $stmt->close();
        }

        if (isset($_POST['adjust_product'])) {
            $product_id = (int)$_POST['product_id'];
            $stock_type = $_POST['stock_type'];
            $adjustment_type = $_POST['adjustment_type'];
            $quantity = (int)$_POST['quantity'];
            $reason = $_POST['reason'];

            // Map stock type to column name
            switch ($stock_type) {
                case 'wet':
                    $stock_column = 'wet_stock';
                    break;
                case 'dry':
                    $stock_column = 'dry_stock';
                    break;
                case 'polish-cut':
                    $stock_column = 'polish_cut_stock';
                    break;
                case 'polish-jagged':
                    $stock_column = 'polish_jagged_stock';
                    break;
                case 'damage':
                    $stock_column = 'damage_stock';
                    break;
                default:
                    throw new Exception("Invalid stock type: $stock_type");
            }

            // Check current stock
            $stmt = $conn->prepare("SELECT $stock_column FROM products WHERE id = ?");
            $stmt->bind_param("i", $product_id);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            $current_stock = $result[$stock_column];
            $stmt->close();

            if ($adjustment_type == 'decrease' && $quantity > $current_stock) {
                throw new Exception("Cannot decrease $stock_type stock below 0. Available: $current_stock, Requested: $quantity");
            }

            $stmt = $conn->prepare("UPDATE products SET $stock_column = $stock_column " . ($adjustment_type == 'increase' ? '+' : '-') . " ? WHERE id = ?");
            $stmt->bind_param("ii", $quantity, $product_id);
            if (!$stmt->execute() || $stmt->affected_rows == 0) {
                throw new Exception("Failed to update product stock ($stock_type): " . $stmt->error);
            }
            $stmt->close();

            $stmt = $conn->prepare("INSERT INTO stock_adjustments (type, item_id, adjustment_type, quantity, reason, adjustment_date, stock_type) 
                VALUES ('product', ?, ?, ?, ?, NOW(), ?)");
            $stmt->bind_param("issds", $product_id, $adjustment_type, $quantity, $reason, $stock_type);
            if (!$stmt->execute()) {
                throw new Exception("Failed to log product adjustment: " . $stmt->error);
            }
            $stmt->close();
        }

        $conn->commit();
        echo "<script>alert('Stock adjustment successful');</script>";
    } catch (Exception $e) {
        $conn->rollback();
        echo "<script>alert('Stock adjustment failed: " . addslashes($e->getMessage()) . "');</script>";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Stock Adjustment - Cement Factory</title>
    <link href="assets/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .tab-content { padding: 20px; }
    </style>
</head>
<body>
    <div class="container mt-4">
        <h1>Stock Adjustment</h1>
        <p>Current Time in Colombo: <?php echo date('Y-m-d H:i:s'); ?></p>
        <a href="index.php" class="btn btn-secondary mb-3">Back to Dashboard</a>

        <!-- Tab Navigation -->
        <ul class="nav nav-tabs" id="adjustmentTabs" role="tablist">
            <li class="nav-item">
                <a class="nav-link active" id="raw-material-tab" data-bs-toggle="tab" href="#raw-material" role="tab">Raw Materials</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" id="finished-goods-tab" data-bs-toggle="tab" href="#finished-goods" role="tab">Finished Goods</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" id="history-tab" data-bs-toggle="tab" href="#history" role="tab">Adjustment History</a>
            </li>
        </ul>

        <!-- Tab Content -->
        <div class="tab-content" id="adjustmentTabsContent">
            <!-- Raw Materials Tab -->
            <div class="tab-pane fade show active" id="raw-material" role="tabpanel">
                <h3>Adjust Raw Material Stock</h3>
                <form method="post">
                    <div class="mb-3">
                        <label class="form-label">Raw Material</label>
                        <select name="material_id" class="form-control" required>
                            <?php
                            $stmt = $conn->prepare("SELECT * FROM raw_materials");
                            $stmt->execute();
                            $materials = $stmt->get_result();
                            while ($row = $materials->fetch_assoc()) {
                                echo "<option value='{$row['id']}'>{$row['name']} (Stock: {$row['stock']} {$row['unit_type']})</option>";
                            }
                            $stmt->close();
                            ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Adjustment Type</label>
                        <select name="adjustment_type" class="form-control" required>
                            <option value="increase">Increase (e.g., Miscount Correction)</option>
                            <option value="decrease">Decrease (e.g., Damage)</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Quantity</label>
                        <input type="number" step="0.01" name="quantity" class="form-control" placeholder="Quantity" required min="0">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Reason</label>
                        <input type="text" name="reason" class="form-control" placeholder="Reason (e.g., Damaged, Miscount)" required>
                    </div>
                    <button type="submit" name="adjust_raw_material" class="btn btn-primary">Adjust Stock</button>
                </form>
            </div>

            <!-- Finished Goods Tab -->
            <div class="tab-pane fade" id="finished-goods" role="tabpanel">
                <h3>Adjust Finished Goods Stock</h3>
                <form method="post">
                    <div class="mb-3">
                        <label class="form-label">Product</label>
                        <select name="product_id" class="form-control" required>
                            <?php
                            $stmt = $conn->prepare("SELECT * FROM products");
                            $stmt->execute();
                            $products = $stmt->get_result();
                            while ($row = $products->fetch_assoc()) {
                                echo "<option value='{$row['id']}'>{$row['name']} (Wet: {$row['wet_stock']}, Dry: {$row['dry_stock']}, Polish-Cut: {$row['polish_cut_stock']}, Polish-Jagged: {$row['polish_jagged_stock']}, Damage: {$row['damage_stock']})</option>";
                            }
                            $stmt->close();
                            ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Stock Type</label>
                        <select name="stock_type" class="form-control" required>
                            <option value="wet">Wet Stock</option>
                            <option value="dry">Dry Stock</option>
                            <option value="polish-cut">Polish-Cut Stock</option>
                            <option value="polish-jagged">Polish-Jagged Stock</option>
                            <option value="damage">Damage Stock</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Adjustment Type</label>
                        <select name="adjustment_type" class="form-control" required>
                            <option value="increase">Increase (e.g., Miscount Correction)</option>
                            <option value="decrease">Decrease (e.g., Damage)</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Quantity</label>
                        <input type="number" step="1" name="quantity" class="form-control" placeholder="Quantity" required min="1">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Reason</label>
                        <input type="text" name="reason" class="form-control" placeholder="Reason (e.g., Damaged, Miscount)" required>
                    </div>
                    <button type="submit" name="adjust_product" class="btn btn-primary">Adjust Stock</button>
                </form>
            </div>

            <!-- Adjustment History Tab -->
            <div class="tab-pane fade" id="history" role="tabpanel">
                <h3>Stock Adjustment History</h3>
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Type</th>
                            <th>Item</th>
                            <th>Stock Type</th>
                            <th>Adjustment</th>
                            <th>Quantity</th>
                            <th>Reason</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $stmt = $conn->prepare("
                            SELECT sa.*, 
                                   CASE WHEN sa.type = 'raw_material' THEN rm.name ELSE p.name END AS item_name,
                                   CASE WHEN sa.type = 'raw_material' THEN rm.unit_type ELSE NULL END AS unit_type
                            FROM stock_adjustments sa
                            LEFT JOIN raw_materials rm ON sa.type = 'raw_material' AND sa.item_id = rm.id
                            LEFT JOIN products p ON sa.type = 'product' AND sa.item_id = p.id
                            ORDER BY sa.adjustment_date DESC
                        ");
                        $stmt->execute();
                        $adjustments = $stmt->get_result();
                        while ($row = $adjustments->fetch_assoc()) {
                            $quantity_display = $row['unit_type'] ? number_format($row['quantity'], 2) . " {$row['unit_type']}" : $row['quantity'];
                            $stock_type_display = $row['stock_type'] ? ucwords(str_replace('-', ' ', $row['stock_type'])) : '-';
                            echo "<tr>
                                <td>{$row['id']}</td>
                                <td>" . ucfirst(str_replace('_', ' ', $row['type'])) . "</td>
                                <td>{$row['item_name']}</td>
                                <td>{$stock_type_display}</td>
                                <td>" . ucfirst($row['adjustment_type']) . "</td>
                                <td>{$quantity_display}</td>
                                <td>{$row['reason']}</td>
                                <td>{$row['adjustment_date']}</td>
                            </tr>";
                        }
                        if ($adjustments->num_rows == 0) {
                            echo "<tr><td colspan='8'>No adjustments recorded</td></tr>";
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