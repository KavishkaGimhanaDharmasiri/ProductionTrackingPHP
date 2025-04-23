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

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_order'])) {
    $product_id = (int)$_POST['product_id'];
    $customer_id = (int)$_POST['customer_id'];
    $quantity = (int)$_POST['quantity'];
    $stock_state = $_POST['stock_state'];
    $due_date = $_POST['due_date'];
    $notes = $_POST['notes'];

    if ($quantity <= 0) {
        echo "<script>alert('Quantity must be a positive number');</script>";
    } else {
        $stmt = $conn->prepare("INSERT INTO orders (product_id, customer_id, quantity, stock_state, due_date, notes) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iiisss", $product_id, $customer_id, $quantity, $stock_state, $due_date, $notes);
        if ($stmt->execute()) {
            echo "<script>alert('Order added successfully'); window.location.href='orders.php';</script>";
        } else {
            echo "<script>alert('Failed to add order: " . addslashes($stmt->error) . "');</script>";
        }
        $stmt->close();
    }
}

// Handle status update
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_status'])) {
    $order_id = (int)$_POST['order_id'];
    $status = $_POST['status'];

    $stmt = $conn->prepare("UPDATE orders SET status = ? WHERE id = ?");
    $stmt->bind_param("si", $status, $order_id);
    if ($stmt->execute()) {
        echo "<script>alert('Order status updated successfully'); window.location.href='orders.php';</script>";
    } else {
        echo "<script>alert('Failed to update status: " . addslashes($stmt->error) . "');</script>";
    }
    $stmt->close();
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Orders - Cement Factory</title>
    <link href="assets/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="styles.css">
    <style>
        .order-table, .stock-table { margin-top: 20px; }
        .tab-content { padding: 20px; }
    </style>
</head>
<body>
    <div class="container mt-4">
        <h1>Manage Orders</h1>
 

        <!-- Tab Navigation -->
        <ul class="nav nav-tabs" id="orderTabs" role="tablist">
            <li class="nav-item">
                <a class="nav-link active" id="add-order-tab" data-bs-toggle="tab" href="#add-order" role="tab">Add Order</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" id="order-list-tab" data-bs-toggle="tab" href="#order-list" role="tab">Order List</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" id="stock-summary-tab" data-bs-toggle="tab" href="#stock-summary" role="tab">Stock Summary</a>
            </li>
        </ul>

        <!-- Tab Content -->
        <div class="tab-content" id="orderTabsContent">
            <!-- Add Order Tab -->
            <div class="tab-pane fade show active" id="add-order" role="tabpanel">
                <div class="card mb-4">
                    <div class="card-header">Add New Order</div>
                    <div class="card-body">
                        <form method="post">
                            <div class="mb-3">
                                <label class="form-label">Product</label>
                                <select name="product_id" class="form-control" required>
                                    <?php
                                    $stmt = $conn->prepare("SELECT id, name FROM products");
                                    $stmt->execute();
                                    $products = $stmt->get_result();
                                    while ($row = $products->fetch_assoc()) {
                                        echo "<option value='{$row['id']}'>{$row['name']}</option>";
                                    }
                                    $stmt->close();
                                    ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Customer</label>
                                <select name="customer_id" class="form-control" required>
                                    <?php
                                    $stmt = $conn->prepare("SELECT id, name FROM customers");
                                    $stmt->execute();
                                    $customers = $stmt->get_result();
                                    while ($row = $customers->fetch_assoc()) {
                                        echo "<option value='{$row['id']}'>{$row['name']}</option>";
                                    }
                                    $stmt->close();
                                    ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Quantity</label>
                                <input type="number" name="quantity" class="form-control" placeholder="Enter quantity" required min="1">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Stock State</label>
                                <select name="stock_state" class="form-control" required>
                                    <option value="dry">Dry</option>
                                    <option value="polish-cut">Polish-Cut</option>
                                    <option value="polish-jagged">Polish-Jagged</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Due Date</label>
                                <input type="datetime-local" name="due_date" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Notes</label>
                                <textarea name="notes" class="form-control" rows="3" placeholder="Additional details (e.g., special instructions)"></textarea>
                            </div>
                            <button type="submit" name="add_order" class="btn btn-primary">Add Order</button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Order List Tab -->
            <div class="tab-pane fade" id="order-list" role="tabpanel">
                <div class="card mb-4">
                    <div class="card-header">Order List</div>
                    <div class="card-body">
                        <table class="table table-striped order-table">
                            <thead>
                                <tr>
                                    <th>Order ID</th>
                                    <th>Product</th>
                                    <th>Customer</th>
                                    <th>Quantity</th>
                                    <th>Stock State</th>
                                    <th>Order Date</th>
                                    <th>Due Date</th>
                                    <th>Status</th>
                                    <th>Notes</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $stmt = $conn->prepare("
                                    SELECT o.*, p.name AS product_name, c.name AS customer_name 
                                    FROM orders o
                                    JOIN products p ON o.product_id = p.id
                                    JOIN customers c ON o.customer_id = c.id
                                    ORDER BY o.order_date DESC
                                ");
                                $stmt->execute();
                                $orders = $stmt->get_result();
                                while ($row = $orders->fetch_assoc()) {
                                    echo "<tr>
                                        <td>{$row['id']}</td>
                                        <td>{$row['product_name']}</td>
                                        <td>{$row['customer_name']}</td>
                                        <td>{$row['quantity']}</td>
                                        <td>" . ucwords(str_replace('-', ' ', $row['stock_state'])) . "</td>
                                        <td>{$row['order_date']}</td>
                                        <td>{$row['due_date']}</td>
                                        <td>
                                            <form method='post' style='display:inline;'>
                                                <input type='hidden' name='order_id' value='{$row['id']}'>
                                                <select name='status' class='form-control' onchange='this.form.submit()' style='width: auto; display: inline;'>
                                                    <option value='queue'" . ($row['status'] == 'queue' ? ' selected' : '') . ">Queue</option>
                                                    <option value='processing'" . ($row['status'] == 'processing' ? ' selected' : '') . ">Processing</option>
                                                    <option value='finished'" . ($row['status'] == 'finished' ? ' selected' : '') . ">Finished</option>
                                                </select>
                                                <input type='hidden' name='update_status' value='1'>
                                            </form>
                                        </td>
                                        <td>{$row['notes']}</td>
                                        <td>
                                            <a href='#' class='btn btn-sm btn-info'>Edit</a>
                                        </td>
                                    </tr>";
                                }
                                $stmt->close();
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Stock Summary Tab -->
            <div class="tab-pane fade" id="stock-summary" role="tabpanel">
                <div class="card mb-4">
                    <div class="card-header">Stock Summary</div>
                    <div class="card-body">
                        <table class="table table-striped stock-table">
                            <thead>
                                <tr>
                                    <th>Product Name</th>
                                    <th>State Level</th>
                                    <th>Current Stock Qty</th>
                                    <th>Ordered Stock Qty</th>
                                    <th>Short/Excess Stock</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                // Fetch all products
                                $stmt = $conn->prepare("SELECT id, name, dry_stock, polish_cut_stock, polish_jagged_stock FROM products");
                                $stmt->execute();
                                $products = $stmt->get_result();

                                while ($row = $products->fetch_assoc()) {
                                    $product_id = $row['id'];
                                    $states = [
                                        'dry' => $row['dry_stock'],
                                        'polish-cut' => $row['polish_cut_stock'],
                                        'polish-jagged' => $row['polish_jagged_stock']
                                    ];

                                    foreach ($states as $state => $current_stock) {
                                        // Fetch ordered quantity for this product and state (exclude finished orders)
                                        $order_stmt = $conn->prepare("
                                            SELECT SUM(quantity) AS ordered_qty 
                                            FROM orders 
                                            WHERE product_id = ? AND stock_state = ? AND status != 'finished'
                                        ");
                                        $order_stmt->bind_param("is", $product_id, $state);
                                        $order_stmt->execute();
                                        $order_result = $order_stmt->get_result()->fetch_assoc();
                                        $ordered_qty = $order_result['ordered_qty'] ?? 0;
                                        $order_stmt->close();

                                        // Calculate short/excess
                                        $difference = $current_stock - $ordered_qty;
                                        $status = $difference >= 0 ? "Can Sell ($difference excess)" : "Need to Produce (" . abs($difference) . " short)";

                                        echo "<tr>
                                            <td>{$row['name']}</td>
                                            <td>" . ucwords(str_replace('-', ' ', $state)) . "</td>
                                            <td>{$current_stock}</td>
                                            <td>{$ordered_qty}</td>
                                            <td>{$status}</td>
                                        </tr>";
                                    }
                                }
                                $stmt->close();
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="assets/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>