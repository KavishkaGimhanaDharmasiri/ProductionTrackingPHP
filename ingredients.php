<?php
// Start output buffering
ob_start();

// Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Set Sri Lanka (Colombo) time zone
date_default_timezone_set('Asia/Colombo');

// Include database connection
include 'connection.php';
include 'index.php'; // Ensure this doesn’t output content that breaks headers

// Add Ingredient
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_ingredient'])) {
    $product_id = (int)$_POST['product_id'];
    $material_id = (int)$_POST['material_id'];
    $quantity = floatval($_POST['quantity']); // Quantity in display units (bag, concrete pan, grams, nos)
    $batch_yield = isset($_POST['batch_yield']) ? (int)$_POST['batch_yield'] : 0; // Units produced per batch, optional

    // Get raw material unit type
    $material_query = mysqli_query($conn, "SELECT unit_type FROM raw_materials WHERE id = $material_id");
    $material = mysqli_fetch_assoc($material_query);
    if (!$material) {
        die("Error: Raw material not found.");
    }
    $unit_type = $material['unit_type'];

    // Convert quantity to base stock unit for storage
    $stored_quantity = $quantity;
    switch ($unit_type) {
        case 'lorry cube':
            $stored_quantity = $quantity / 300; // Convert concrete pans to lorry cubes
            break;
        case 'kg':
            $stored_quantity = $quantity / 1000; // Convert grams to kg
            break;
        // 'bag' and 'nos' remain unchanged
    }

    // Check if this product already has a batch yield
    $product_query = mysqli_query($conn, "SELECT batch_yield FROM products WHERE id = $product_id");
    $product = mysqli_fetch_assoc($product_query);
    if (!$product) {
        die("Error: Product not found.");
    }
    
    if ($product['batch_yield'] == 0 && $batch_yield > 0) {
        // Set batch yield if not already set and provided
        mysqli_query($conn, "UPDATE products SET batch_yield = $batch_yield WHERE id = $product_id");
    } elseif ($batch_yield > 0 && $batch_yield != $product['batch_yield']) {
        header("Location: ingredients.php?error=Batch+yield+already+set+to+{$product['batch_yield']}+for+this+product");
        exit;
    }

    $sql = "INSERT INTO product_ingredients (product_id, raw_material_id, quantity) 
            VALUES ($product_id, $material_id, $stored_quantity)";
    if (mysqli_query($conn, $sql)) {
        header("Location: ingredients.php?success=Ingredient+added");
        exit;
    } else {
        echo "Error: " . mysqli_error($conn);
    }
}

// Delete Ingredient
if (isset($_GET['delete_ingredient'])) {
    $id = (int)$_GET['delete_ingredient'];
    $sql = "DELETE FROM product_ingredients WHERE id = $id";
    if (mysqli_query($conn, $sql)) {
        header("Location: ingredients.php?success=Ingredient+deleted");
        exit;
    } else {
        echo "Error: " . mysqli_error($conn);
    }
}

// Unit conversion functions
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
    <title>Cement Factory - Product Ingredients</title>
    <link href="assets/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div class="container mt-4">
        <h1>Cement Factory - Product Ingredients</h1>
        
        <a href="index.php" class="btn btn-secondary mb-3">Back to Dashboard</a>
        <a href="production.php" class="btn btn-primary mb-3">Go to Production</a>

        <?php
        if (isset($_GET['success'])) {
            echo "<div class='alert alert-success'>" . htmlspecialchars($_GET['success']) . "</div>";
        }
        if (isset($_GET['error'])) {
            echo "<div class='alert alert-danger'>" . htmlspecialchars($_GET['error']) . "</div>";
        }
        ?>

        <div class="card mb-4">
            <div class="card-header">Define Product Ingredients</div>
            <div class="card-body">
                <form method="post" id="ingredientForm">
                    <div class="mb-3">
                        <label class="form-label">Finished Product</label>
                        <select name="product_id" class="form-control" required onchange="checkBatchYield(this)">
                            <?php
                            $products = mysqli_query($conn, "SELECT id, name, batch_yield FROM products");
                            while ($row = mysqli_fetch_assoc($products)) {
                                echo "<option value='{$row['id']}'>{$row['name']}</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Raw Material</label>
                        <select name="material_id" class="form-control" required onchange="updateQuantityLabel(this)">
                            <?php
                            $materials = mysqli_query($conn, "SELECT * FROM raw_materials");
                            while ($row = mysqli_fetch_assoc($materials)) {
                                echo "<option value='{$row['id']}'>{$row['name']} (" . getDisplayUnit($row['unit_type']) . ")</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" id="quantityLabel">Quantity (per Batch)</label>
                        <input type="number" step="0.01" name="quantity" class="form-control" placeholder="Quantity" required>
                        <small class="form-text text-muted" id="quantityHelp">Enter quantity in the displayed unit (e.g., concrete pans for lorry cube, grams for kg).</small>
                    </div>
                    <div class="mb-3" id="batchYieldField">
                        <label class="form-label">Finished Product Quantity per Batch</label>
                        <input type="number" step="1" name="batch_yield" class="form-control" placeholder="e.g., 10, 20" min="1">
                        <small class="form-text text-muted">Define how many units this batch produces (required only if not set).</small>
                    </div>
                    <button type="submit" name="add_ingredient" class="btn btn-primary">Add Ingredient</button>
                </form>
            </div>
        </div>

        <h3>Ingredients by Product</h3>
        <?php
        $products = mysqli_query($conn, "SELECT * FROM products");
        while ($prod = mysqli_fetch_assoc($products)) {
            echo "<h4>{$prod['name']} (Batch Yield: {$prod['batch_yield']})</h4>";
            echo "<table class='table table-striped'>
                <thead><tr><th>Raw Material</th><th>Quantity (per Batch)</th><th>Display Unit</th><th>Stock Unit</th><th>Actions</th></tr></thead>
                <tbody>";
            $ingredients = mysqli_query($conn, "SELECT pi.id, pi.quantity, rm.name, rm.unit_type 
                FROM product_ingredients pi 
                JOIN raw_materials rm ON pi.raw_material_id = rm.id 
                WHERE pi.product_id = {$prod['id']}");
            if (mysqli_num_rows($ingredients) > 0) {
                while ($ing = mysqli_fetch_assoc($ingredients)) {
                    $display_quantity = toDisplayQuantity($ing['quantity'], $ing['unit_type']);
                    $display_unit = getDisplayUnit($ing['unit_type']);
                    echo "<tr>
                        <td>{$ing['name']}</td>
                        <td>" . number_format($display_quantity, 2) . "</td>
                        <td>$display_unit</td>
                        <td>{$ing['unit_type']}</td>
                        <td><a href='?delete_ingredient={$ing['id']}' class='btn btn-sm btn-danger' onclick='return confirm(\"Are you sure?\")'>Delete</a></td>
                    </tr>";
                }
            } else {
                echo "<tr><td colspan='5'>No ingredients defined yet.</td></tr>";
            }
            echo "</tbody></table>";
        }
        ?>
    </div>

    <script src="assets/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script>
        function checkBatchYield(select) {
            const productId = select.value;
            fetch(`check_batch_yield.php?product_id=${productId}`)
                .then(response => response.json())
                .then(data => {
                    const batchYieldField = document.getElementById('batchYieldField');
                    const batchYieldInput = batchYieldField.querySelector('input[name="batch_yield"]');
                    if (data.batchYield > 0) {
                        batchYieldField.style.display = 'none';
                        batchYieldInput.removeAttribute('required');
                        batchYieldInput.value = '';
                    } else {
                        batchYieldField.style.display = 'block';
                        batchYieldInput.setAttribute('required', 'required');
                    }
                });
        }

        function updateQuantityLabel(select) {
            const materialId = select.value;
            fetch(`get_material_unit.php?material_id=${materialId}`)
                .then(response => response.json())
                .then(data => {
                    const unit = data.displayUnit;
                    document.getElementById('quantityLabel').textContent = `Quantity in ${unit} (per Batch)`;
                    document.getElementById('quantityHelp').textContent = `Enter quantity in ${unit}. Stock will be managed in ${data.stockUnit}.`;
                });
        }

        // Initial setup
        document.addEventListener('DOMContentLoaded', () => {
            checkBatchYield(document.querySelector('select[name="product_id"]'));
            updateQuantityLabel(document.querySelector('select[name="material_id"]'));
        });
    </script>
</body>
</html>




