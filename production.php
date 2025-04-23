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

require 'fpdf.php';
$report_dir = 'report/production';
$polish_report_dir = 'report/polish';
if (!file_exists($report_dir)) {
    mkdir($report_dir, 0777, true);
}
if (!file_exists($polish_report_dir)) {
    mkdir($polish_report_dir, 0777, true);
}

// Function to move wet to dry stock after 24 hours (aligned with products.php)
function moveWetToDry($conn) {
    $stmt = $conn->prepare("SELECT id, wet_stock, created_at FROM products WHERE wet_stock > 0 AND created_at <= NOW() - INTERVAL 24 HOUR");
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $id = $row['id'];
        $wet_stock = (int)$row['wet_stock'];
        
        $update_stmt = $conn->prepare("UPDATE products SET wet_stock = 0, dry_stock = dry_stock + ? WHERE id = ?");
        $update_stmt->bind_param("ii", $wet_stock, $id);
        $update_stmt->execute();
        $update_stmt->close();
    }
    
    $stmt->close();
}

// Run wet-to-dry check on page load
moveWetToDry($conn);

// Batch-Based Production
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['produce_batch'])) {
    $product_id = (int)$_POST['produce_product_id'];
    $batches = floatval($_POST['batches']);

    $stmt = $conn->prepare("SELECT batch_yield, name FROM products WHERE id = ?");
    $stmt->bind_param("i", $product_id);
    $stmt->execute();
    $product = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $batch_yield = $product['batch_yield'];
    if ($batch_yield == 0) {
        echo "<script>alert('Batch yield not defined for this product. Define it in Product Ingredients.');</script>";
        exit;
    }

    $quantity = $batch_yield * $batches;

    $stmt = $conn->prepare("SELECT pi.quantity AS required_qty, rm.name, rm.stock, rm.unit_type, rm.unit_price 
        FROM product_ingredients pi 
        JOIN raw_materials rm ON pi.raw_material_id = rm.id 
        WHERE pi.product_id = ?");
    $stmt->bind_param("i", $product_id);
    $stmt->execute();
    $ingredients = $stmt->get_result();
    $insufficient_materials = [];
    
    while ($ing = $ingredients->fetch_assoc()) {
        $required = $ing['required_qty'] * $batches;
        $display_quantity = toDisplayQuantity($required, $ing['unit_type']);
        $display_unit = getDisplayUnit($ing['unit_type']);
        if ($ing['stock'] < $required) {
            $insufficient_materials[] = "Raw material '{$ing['name']}' has {$ing['stock']} {$ing['unit_type']}, which is not enough (required: $display_quantity $display_unit)";
        }
    }

    if (!empty($insufficient_materials)) {
        $error_message = implode("; ", $insufficient_materials);
        echo "<script>alert('$error_message');</script>";
    } else {
        $conn->begin_transaction();
        try {
            // Add to wet stock with current timestamp
            $stmt = $conn->prepare("UPDATE products SET wet_stock = wet_stock + ?, created_at = NOW() WHERE id = ?");
            $stmt->bind_param("ii", $quantity, $product_id);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare("SELECT pi.quantity, rm.id, rm.name, rm.unit_type, rm.unit_price 
                FROM product_ingredients pi 
                JOIN raw_materials rm ON pi.raw_material_id = rm.id 
                WHERE pi.product_id = ?");
            $stmt->bind_param("i", $product_id);
            $stmt->execute();
            $ingredients = $stmt->get_result();
            
            while ($ing = $ingredients->fetch_assoc()) {
                $deduct = $ing['quantity'] * $batches;
                $update_stmt = $conn->prepare("UPDATE raw_materials SET stock = stock - ? WHERE id = ?");
                $update_stmt->bind_param("di", $deduct, $ing['id']);
                $update_stmt->execute();
                $update_stmt->close();
            }
            $stmt->close();

            $stmt = $conn->prepare("INSERT INTO production_history (product_id, quantity, production_date) VALUES (?, ?, NOW())");
            $stmt->bind_param("ii", $product_id, $quantity);
            $stmt->execute();
            $production_id = $conn->insert_id;
            $stmt->close();

            $pdf = new FPDF();
            $pdf->AddPage();
            $pdf->SetFont('Arial', 'B', 16);
            $pdf->Cell(0, 10, 'Production Report (Wet Level)', 0, 1, 'C');
            $pdf->Ln(10);

            $pdf->SetFont('Arial', '', 12);
            $pdf->Cell(0, 10, "Production ID: $production_id", 0, 1);
            $pdf->Cell(0, 10, "Product: " . $product['name'], 0, 1);
            $pdf->Cell(0, 10, "Batches Produced: $batches", 0, 1);
            $pdf->Cell(0, 10, "Quantity Produced: $quantity (Batch Yield: $batch_yield)", 0, 1);
            $pdf->Cell(0, 10, "Date & Time: " . date('Y-m-d H:i:s'), 0, 1);
            $pdf->Cell(0, 10, "Status: Wet (Will move to Dry after 24 hours)", 0, 1);
            $pdf->Ln(10);

            $pdf->SetFont('Arial', 'B', 12);
            $pdf->Cell(80, 10, 'Raw Material', 1);
            $pdf->Cell(40, 10, 'Quantity Used', 1);
            $pdf->Cell(40, 10, 'Unit Price', 1);
            $pdf->Ln();

            $pdf->SetFont('Arial', '', 12);
            $stmt = $conn->prepare("SELECT pi.quantity, rm.name, rm.unit_type, rm.unit_price 
                FROM product_ingredients pi 
                JOIN raw_materials rm ON pi.raw_material_id = rm.id 
                WHERE pi.product_id = ?");
            $stmt->bind_param("i", $product_id);
            $stmt->execute();
            $ingredients = $stmt->get_result();
            
            while ($ing = $ingredients->fetch_assoc()) {
                $used_qty = $ing['quantity'] * $batches;
                $display_qty = toDisplayQuantity($used_qty, $ing['unit_type']);
                $display_unit = getDisplayUnit($ing['unit_type']);
                $pdf->Cell(80, 10, $ing['name'], 1);
                $pdf->Cell(40, 10, number_format($display_qty, 2) . " $display_unit", 1);
                $pdf->Cell(40, 10, number_format($ing['unit_price'], 2), 1);
                $pdf->Ln();
            }
            $stmt->close();

            $pdf_file = "$report_dir/production_$production_id.pdf";
            $pdf->Output('F', $pdf_file);

            $conn->commit();
            header("Location: production.php?pdf=" . urlencode($pdf_file));
            exit;
        } catch (Exception $e) {
            $conn->rollback();
            echo "<script>alert('Batch production failed: " . addslashes($e->getMessage()) . "');</script>";
        }
    }
}

// Manual Dry to Polish/Damage
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['polish'])) {
    $product_id = (int)$_POST['polish_product_id'];
    $quantity = floatval($_POST['polish_quantity']);
    $stock_type = $_POST['stock_type']; // polish-cut, polish-jagged, or damage

    $stmt = $conn->prepare("SELECT name, dry_stock FROM products WHERE id = ?");
    $stmt->bind_param("i", $product_id);
    $stmt->execute();
    $product = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($product['dry_stock'] < $quantity) {
        echo "<script>alert('Insufficient dry stock for {$product['name']}. Available: {$product['dry_stock']}, Requested: $quantity');</script>";
    } else {
        $conn->begin_transaction();
        try {
            // Replace match with switch for PHP 7.x compatibility
            switch ($stock_type) {
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
                    throw new Exception("Invalid stock type");
            }

            $stmt = $conn->prepare("UPDATE products SET dry_stock = dry_stock - ?, $stock_column = $stock_column + ? WHERE id = ?");
            $stmt->bind_param("iii", $quantity, $quantity, $product_id);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare("INSERT INTO polish_history (product_id, quantity, stock_type, polish_date) VALUES (?, ?, ?, NOW())");
            $stmt->bind_param("iis", $product_id, $quantity, $stock_type);
            $stmt->execute();
            $polish_id = $conn->insert_id;
            $stmt->close();

            $pdf = new FPDF();
            $pdf->AddPage();
            $pdf->SetFont('Arial', 'B', 16);
            $pdf->Cell(0, 10, 'Stock Transition Report', 0, 1, 'C');
            $pdf->Ln(10);

            $pdf->SetFont('Arial', '', 12);
            $pdf->Cell(0, 10, "Transition ID: $polish_id", 0, 1);
            $pdf->Cell(0, 10, "Product: " . $product['name'], 0, 1);
            $pdf->Cell(0, 10, "Quantity Moved: $quantity", 0, 1);
            $pdf->Cell(0, 10, "From: Dry Stock", 0, 1);
            $pdf->Cell(0, 10, "To: " . ucwords(str_replace('-', ' ', $stock_type)) . " Stock", 0, 1);
            $pdf->Cell(0, 10, "Date & Time: " . date('Y-m-d H:i:s'), 0, 1);
            $pdf->Ln(10);

            $pdf_file = "$polish_report_dir/transition_$polish_id.pdf";
            $pdf->Output('F', $pdf_file);

            $conn->commit();
            header("Location: production.php?polish_pdf=" . urlencode($pdf_file));
            exit;
        } catch (Exception $e) {
            $conn->rollback();
            echo "<script>alert('Transition failed: " . addslashes($e->getMessage()) . "');</script>";
        }
    }
}

// Unit conversion functions (from ingredients.php)
function toDisplayQuantity($quantity, $unit_type) {
    switch ($unit_type) {
        case 'lorry cube':
            return $quantity * 300; // lorry cube to concrete pans
        case 'kg':
            return $quantity * 1000; // kg to grams
        default:
            return $quantity; // bag, nos unchanged
    }
}

function getDisplayUnit($unit_type) {
    switch ($unit_type) {
        case 'lorry cube':
            return 'concrete pan';
        case 'kg':
            return 'grams';
        default:
            return $unit_type;
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Production Management</title>
    <link href="assets/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div class="container mt-4">
        <h1>Production Management</h1>
        
        
        <?php if (isset($_GET['pdf'])): ?>
            <div class='alert alert-success'>Production completed! <a href='<?php echo urldecode($_GET['pdf']); ?>' download>Download Report</a></div>
        <?php endif; ?>
        <?php if (isset($_GET['polish_pdf'])): ?>
            <div class='alert alert-success'>Stock transition completed! <a href='<?php echo urldecode($_GET['polish_pdf']); ?>' download>Download Transition Report</a></div>
        <?php endif; ?>

        <!-- Produce Product (By Batch) -->
        <div class="card mb-4">
            <div class="card-header">Produce Product (By Batch)</div>
            <div class="card-body">
                <form method="post">
                    <div class="mb-3">
                        <label class="form-label">Product</label>
                        <select name="produce_product_id" class="form-control" required>
                            <?php
                            $stmt = $conn->prepare("SELECT id, name, batch_yield FROM products");
                            $stmt->execute();
                            $products = $stmt->get_result();
                            while ($row = $products->fetch_assoc()) {
                                echo "<option value='{$row['id']}'>{$row['name']} (Batch Yield: {$row['batch_yield']})</option>";
                            }
                            $stmt->close();
                            ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Number of Batches</label>
                        <input type="number" step="1" name="batches" class="form-control" placeholder="Batches" required min="1">
                    </div>
                    <button type="submit" name="produce_batch" class="btn btn-success">Produce Batch</button>
                </form>
            </div>
        </div>

        <!-- Manual Dry to Polish/Damage -->
        <div class="card mb-4">
            <div class="card-header">Move Dry Stock to Polish/Damage</div>
            <div class="card-body">
                <form method="post">
                    <div class="mb-3">
                        <label class="form-label">Product</label>
                        <select name="polish_product_id" class="form-control" required>
                            <?php
                            $stmt = $conn->prepare("SELECT id, name, dry_stock FROM products");
                            $stmt->execute();
                            $products = $stmt->get_result();
                            while ($row = $products->fetch_assoc()) {
                                echo "<option value='{$row['id']}'>{$row['name']} (Dry Stock: {$row['dry_stock']})</option>";
                            }
                            $stmt->close();
                            ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Quantity to Move</label>
                        <input type="number" step="1" name="polish_quantity" class="form-control" placeholder="Quantity" required min="1">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Move To</label>
                        <select name="stock_type" class="form-control" required>
                            <option value="polish-cut">Polish-Cut Stock</option>
                            <option value="polish-jagged">Polish-Jagged Stock</option>
                            <option value="damage">Damage Stock</option>
                        </select>
                    </div>
                    <button type="submit" name="polish" class="btn btn-primary">Move Stock</button>
                </form>
            </div>
        </div>

        <h3>Stock Levels</h3>
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>Wet Stock</th>
                    <th>Dry Stock</th>
                    <th>Polish-Cut Stock</th>
                    <th>Polish-Jagged Stock</th>
                    <th>Damage Stock</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $stmt = $conn->prepare("SELECT name, wet_stock, dry_stock, polish_cut_stock, polish_jagged_stock, damage_stock FROM products");
                $stmt->execute();
                $products = $stmt->get_result();
                while ($row = $products->fetch_assoc()) {
                    echo "<tr>
                        <td>{$row['name']}</td>
                        <td>{$row['wet_stock']}</td>
                        <td>{$row['dry_stock']}</td>
                        <td>{$row['polish_cut_stock']}</td>
                        <td>{$row['polish_jagged_stock']}</td>
                        <td>{$row['damage_stock']}</td>
                    </tr>";
                }
                $stmt->close();
                ?>
            </tbody>
        </table>

        <h3>Production History</h3>
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Product</th>
                    <th>Quantity</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $stmt = $conn->prepare("SELECT ph.*, p.name FROM production_history ph JOIN products p ON ph.product_id = p.id");
                $stmt->execute();
                $history = $stmt->get_result();
                while ($row = $history->fetch_assoc()) {
                    echo "<tr>
                        <td>{$row['id']}</td>
                        <td>{$row['name']}</td>
                        <td>{$row['quantity']}</td>
                        <td>{$row['production_date']}</td>
                    </tr>";
                }
                $stmt->close();
                ?>
            </tbody>
        </table>

        <h3>Stock Transition History</h3>
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Product</th>
                    <th>Quantity</th>
                    <th>Type</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $stmt = $conn->prepare("SELECT ph.*, p.name FROM polish_history ph JOIN products p ON ph.product_id = p.id");
                $stmt->execute();
                $polish_history = $stmt->get_result();
                while ($row = $polish_history->fetch_assoc()) {
                    echo "<tr>
                        <td>{$row['id']}</td>
                        <td>{$row['name']}</td>
                        <td>{$row['quantity']}</td>
                        <td>" . ucwords(str_replace('-', ' ', $row['stock_type'])) . "</td>
                        <td>{$row['polish_date']}</td>
                    </tr>";
                }
                $stmt->close();
                ?>
            </tbody>
        </table>
    </div>

    <script src="assets/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>