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

// Debug environment
include('index.php');
// Function to update wet stock to dry stock after 24 hours
function updateWetToDryStock($conn) {
    $stmt = $conn->prepare("SELECT id, wet_stock, created_at FROM products WHERE wet_stock > 0 AND created_at <= NOW() - INTERVAL 24 HOUR");
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $id = $row['id'];
        $wet_stock = (int)$row['wet_stock'];
        
        // Move wet_stock to dry_stock
        $update_stmt = $conn->prepare("UPDATE products SET wet_stock = 0, dry_stock = dry_stock + ? WHERE id = ?");
        $update_stmt->bind_param("ii", $wet_stock, $id);
        $update_stmt->execute();
        $update_stmt->close();
    }
    
    $stmt->close();
}

// Run the update function on page load
updateWetToDryStock($conn);

// Handle product insertion (no wet_stock input)
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['add_product'])) {
    $name = $_POST['name'];
    $dry_price = (float)$_POST['dry_price'];
    $polish_cut_price = (float)$_POST['polish_cut_price'];
    $polish_jagged_price = (float)$_POST['polish_jagged_price'];
    $damage_price = (float)$_POST['damage_price'];
    $wet_stock = 0; // Default wet_stock to 0 since user doesn't enter it

    try {
        $stmt = $conn->prepare("INSERT INTO products (name, wet_stock, dry_stock, polish_cut_stock, polish_jagged_stock, damage_stock, dry_price, polish_cut_price, polish_jagged_price, damage_price, created_at) VALUES (?, ?, 0, 0, 0, 0, ?, ?, ?, ?, NOW())");
        $stmt->bind_param("sidddd", $name, $wet_stock, $dry_price, $polish_cut_price, $polish_jagged_price, $damage_price);
        $stmt->execute();
        $stmt->close();
        echo "<script>alert('Product added successfully!');</script>";
    } catch (Exception $e) {
        echo "<script>alert('Failed to add product: " . addslashes($e->getMessage()) . "');</script>";
    }
}

// Handle product update (only name and prices editable)
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['update_product'])) {
    $id = (int)$_POST['id'];
    $name = $_POST['name'];
    $dry_price = (float)$_POST['dry_price'];
    $polish_cut_price = (float)$_POST['polish_cut_price'];
    $polish_jagged_price = (float)$_POST['polish_jagged_price'];
    $damage_price = (float)$_POST['damage_price'];

    try {
        $stmt = $conn->prepare("UPDATE products SET name = ?, dry_price = ?, polish_cut_price = ?, polish_jagged_price = ?, damage_price = ? WHERE id = ?");
        $stmt->bind_param("sddddi", $name, $dry_price, $polish_cut_price, $polish_jagged_price, $damage_price, $id);
        $stmt->execute();
        $stmt->close();
        echo "<script>alert('Product updated successfully!');</script>";
    } catch (Exception $e) {
        echo "<script>alert('Failed to update product: " . addslashes($e->getMessage()) . "');</script>";
    }
}

// Fetch product for editing if edit_id is set
$edit_product = null;
if (isset($_GET['edit_id'])) {
    $edit_id = (int)$_GET['edit_id'];
    $stmt = $conn->prepare("SELECT * FROM products WHERE id = ?");
    $stmt->bind_param("i", $edit_id);
    $stmt->execute();
    $edit_product = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Product Management - Bakery</title>
    <link href="assets/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div class="container mt-4">
        <h1>Product Management</h1>
      

        <!-- Add/Edit Product Form -->
        <div class="card mb-4">
            <div class="card-header"><?php echo $edit_product ? 'Edit Product' : 'Add New Product'; ?></div>
            <div class="card-body">
                <form method="post" action="add_products.php">
                    <?php if ($edit_product): ?>
                        <input type="hidden" name="id" value="<?php echo $edit_product['id']; ?>">
                    <?php endif; ?>
                    <div class="mb-3">
                        <label class="form-label">Product Name</label>
                        <input type="text" name="name" class="form-control" value="<?php echo $edit_product ? $edit_product['name'] : ''; ?>" required>
                    </div>
                    <?php if ($edit_product): ?>
                        <div class="row">
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Wet Stock (Not Editable)</label>
                                <input type="number" class="form-control" value="<?php echo $edit_product['wet_stock']; ?>" disabled>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Dry Stock (Not Editable)</label>
                                <input type="number" class="form-control" value="<?php echo $edit_product['dry_stock']; ?>" disabled>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Polish-Cut Stock (Not Editable)</label>
                                <input type="number" class="form-control" value="<?php echo $edit_product['polish_cut_stock']; ?>" disabled>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Polish-Jagged Stock (Not Editable)</label>
                                <input type="number" class="form-control" value="<?php echo $edit_product['polish_jagged_stock']; ?>" disabled>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Damage Stock (Not Editable)</label>
                                <input type="number" class="form-control" value="<?php echo $edit_product['damage_stock']; ?>" disabled>
                            </div>
                        </div>
                    <?php endif; ?>
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Dry Price (LKR)</label>
                            <input type="number" step="0.01" name="dry_price" class="form-control" value="<?php echo $edit_product ? $edit_product['dry_price'] : '0.00'; ?>" min="0" required>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Polish-Cut Price (LKR)</label>
                            <input type="number" step="0.01" name="polish_cut_price" class="form-control" value="<?php echo $edit_product ? $edit_product['polish_cut_price'] : '0.00'; ?>" min="0" required>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Polish-Jagged Price (LKR)</label>
                            <input type="number" step="0.01" name="polish_jagged_price" class="form-control" value="<?php echo $edit_product ? $edit_product['polish_jagged_price'] : '0.00'; ?>" min="0" required>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Damage Price (LKR)</label>
                            <input type="number" step="0.01" name="damage_price" class="form-control" value="<?php echo $edit_product ? $edit_product['damage_price'] : '0.00'; ?>" min="0" required>
                        </div>
                    </div>
                    <button type="submit" name="<?php echo $edit_product ? 'update_product' : 'add_product'; ?>" class="btn btn-primary">
                        <?php echo $edit_product ? 'Update Product' : 'Add Product'; ?>
                    </button>
                    <a href="add_products.php" class="btn btn-secondary">Cancel</a>
                    <a href="index.php" class="btn btn-secondary">Back to Dashboard</a>
                </form>
            </div>
        </div>

        <!-- Product List -->
        <div class="card mb-4">
            <div class="card-header">Product List</div>
            <div class="card-body">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Wet Stock</th>
                            <th>Dry Stock</th>
                            <th>Polish-Cut Stock</th>
                            <th>Polish-Jagged Stock</th>
                            <th>Damage Stock</th>
                            <th>Dry Price</th>
                            <th>Polish-Cut Price</th>
                            <th>Polish-Jagged Price</th>
                            <th>Damage Price</th>
                            <th>Created At</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $result = $conn->query("SELECT * FROM products ORDER BY created_at DESC");
                        while ($row = $result->fetch_assoc()) {
                            $wet_to_dry_time = strtotime($row['created_at']) + (24 * 3600); // 24 hours in seconds
                            $current_time = time();
                            $status = ($row['wet_stock'] > 0 && $current_time < $wet_to_dry_time) ? ' (Wet, converting in ' . round(($wet_to_dry_time - $current_time) / 3600, 1) . ' hours)' : '';
                            echo "<tr>
                                <td>{$row['id']}</td>
                                <td>{$row['name']}$status</td>
                                <td>{$row['wet_stock']}</td>
                                <td>{$row['dry_stock']}</td>
                                <td>{$row['polish_cut_stock']}</td>
                                <td>{$row['polish_jagged_stock']}</td>
                                <td>{$row['damage_stock']}</td>
                                <td>" . number_format($row['dry_price'], 2) . "</td>
                                <td>" . number_format($row['polish_cut_price'], 2) . "</td>
                                <td>" . number_format($row['polish_jagged_price'], 2) . "</td>
                                <td>" . number_format($row['damage_price'], 2) . "</td>
                                <td>{$row['created_at']}</td>
                                <td>
                                    <a href='add_products.php?edit_id={$row['id']}' class='btn btn-sm btn-warning'>Edit</a>
                                </td>
                            </tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script src="assets/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>