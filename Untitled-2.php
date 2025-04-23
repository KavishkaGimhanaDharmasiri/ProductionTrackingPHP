<?php
// Start output buffering to prevent header issues
ob_start();

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reportin

g(E_ALL);

// Set Sri Lanka (Colombo) time zone
date_default_timezone_set('Asia/Colombo');

// Include necessary files
include('connection.php');
include('index.php');

// Include FPDF library (ensure fpdf.php is in the same directory)
require('fpdf.php');

// Debug PHP and MySQL versions
echo "PHP Version: " . phpversion() . "<br>";
echo "MySQL Version: " . mysqli_get_server_info($conn) . "<br>";

// Ensure report/bill directory exists and is writable
$bill_dir = 'report/bill';
if (!file_exists($bill_dir)) {
    mkdir($bill_dir, 0777, true) or die("Cannot create $bill_dir");
}
if (!is_writable($bill_dir)) {
    die("Directory $bill_dir is not writable. Please grant write permissions.");
}

// Log POST data for debugging
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    file_put_contents('debug.log', "POST received at " . date('Y-m-d H:i:s') . "\n" . print_r($_POST, true) . "\n", FILE_APPEND);
}

// Handle product sale
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['sell_products'])) {
    file_put_contents('debug.log', "Sell products triggered at " . date('Y-m-d H:i:s') . "\n", FILE_APPEND);
    
    $customer_id = $_POST['customer_id'];
    $payment_type = $_POST['payment_type'];
    $products = $_POST['products'];
    $quantities = $_POST['quantities'];
    $stock_levels = $_POST['stock_levels'];
    $total_amount = 0;

    mysqli_begin_transaction($conn);

    try {
        // Insert sale with initial total_amount of 0
        $sql = "INSERT INTO sales (customer_id, total_amount) VALUES ($customer_id, 0)";
        mysqli_query($conn, $sql) or die("Insert sales failed: " . mysqli_error($conn));
        $sale_id = mysqli_insert_id($conn);

        // Process each product
        for ($i = 0; $i < count($products); $i++) {
            $product_id = $products[$i];
            $quantity = $quantities[$i];
            $stock_level = $stock_levels[$i];

            // Fetch product details
            $product = mysqli_fetch_assoc(mysqli_query($conn, "SELECT wet_stock, dry_stock, polish_stock, wet_price, dry_price, polish_price FROM products WHERE id = $product_id"));
            if (!$product) die("Product $product_id not found");

            $available_stock = 0;
            $stock_column = '';
            $unit_price = 0;
            switch ($stock_level) {
                case 'wet':
                    $available_stock = $product['wet_stock'];
                    $stock_column = 'wet_stock';
                    $unit_price = $product['wet_price'];
                    break;
                case 'dry':
                    $available_stock = $product['dry_stock'];
                    $stock_column = 'dry_stock';
                    $unit_price = $product['dry_price'];
                    break;
                case 'polish':
                    $available_stock = $product['polish_stock'];
                    $stock_column = 'polish_stock';
                    $unit_price = $product['polish_price'];
                    break;
            }

            if ($available_stock < $quantity) {
                throw new Exception("Not enough $stock_level stock for product ID: $product_id. Available: $available_stock, Requested: $quantity");
            }

            // Update stock
            $sql = "UPDATE products SET $stock_column = $stock_column - $quantity WHERE id = $product_id";
            mysqli_query($conn, $sql) or die("Update stock failed: " . mysqli_error($conn));

            $subtotal = $quantity * $unit_price;
            $total_amount += $subtotal;

            // Insert sale item
            $sql = "INSERT INTO sales_items (sale_id, product_id, quantity, unit_price, stock_level) 
                    VALUES ($sale_id, $product_id, $quantity, $unit_price, '$stock_level')";
            mysqli_query($conn, $sql) or die("Insert sales_items failed: " . mysqli_error($conn));
        }

        // Update total amount in sales table
        $sql = "UPDATE sales SET total_amount = $total_amount WHERE id = $sale_id";
        mysqli_query($conn, $sql) or die("Update sales failed: " . mysqli_error($conn));

        $cash_given = 0;
        $balance = 0;

        // Fetch customer details
        $customer = mysqli_fetch_assoc(mysqli_query($conn, "SELECT name, outstanding_balance FROM customers WHERE id = $customer_id"));
        $current_balance = $customer['outstanding_balance'];

        // Fetch sale items for PDF
        $items = mysqli_query($conn, "SELECT si.*, p.name AS product_name 
            FROM sales_items si 
            JOIN products p ON si.product_id = p.id 
            WHERE si.sale_id = $sale_id");

        // Generate PDF
        $pdf = new FPDF();
        $pdf->AddPage();
        $pdf->SetFont('Arial', 'B', 16);
        $pdf->Cell(0, 10, 'Bakery Bill', 0, 1, 'C');
        $pdf->Ln(10);

        $pdf->SetFont('Arial', '', 12);
        $pdf->Cell(0, 10, "Bill Number: $sale_id", 0, 1);
        $pdf->Cell(0, 10, "Customer Name: " . $customer['name'], 0, 1);
        $pdf->Cell(0, 10, "Date & Time: " . date('Y-m-d H:i:s'), 0, 1);
        $pdf->Cell(0, 10, "Payment Type: " . ucfirst($payment_type), 0, 1);
        $pdf->Ln(10);

        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(60, 10, 'Item', 1);
        $pdf->Cell(30, 10, 'Stock Level', 1);
        $pdf->Cell(30, 10, 'Unit Price', 1);
        $pdf->Cell(30, 10, 'Quantity', 1);
        $pdf->Cell(30, 10, 'Amount', 1);
        $pdf->Ln();

        $pdf->SetFont('Arial', '', 12);
        while ($item = mysqli_fetch_assoc($items)) {
            $amount = $item['quantity'] * $item['unit_price'];
            $pdf->Cell(60, 10, $item['product_name'], 1);
            $pdf->Cell(30, 10, ucfirst($item['stock_level']), 1);
            $pdf->Cell(30, 10, number_format($item['unit_price'], 2), 1);
            $pdf->Cell(30, 10, $item['quantity'], 1);
            $pdf->Cell(30, 10, number_format($amount, 2), 1);
            $pdf->Ln();
        }

        $pdf->Ln(10);
        $pdf->Cell(120, 10, 'Subtotal:', 0);
        $pdf->Cell(30, 10, number_format($total_amount, 2), 0, 1);

        if ($payment_type == 'credit') {
            $sql = "UPDATE customers SET outstanding_balance = outstanding_balance + $total_amount WHERE id = $customer_id";
            mysqli_query($conn, $sql);
            $current_balance += $total_amount;
            $pdf->Cell(120, 10, 'Amount Due:', 0);
            $pdf->Cell(30, 10, number_format($total_amount, 2), 0, 1);
        } elseif ($payment_type == 'cash') {
            $cash_given = floatval($_POST['cash_given']);
            $balance = $cash_given - $total_amount;

            if ($balance < 0) {
                $sql = "UPDATE customers SET outstanding_balance = outstanding_balance + " . abs($balance) . " WHERE id = $customer_id";
                mysqli_query($conn, $sql);
                $current_balance += abs($balance);
            }

            $pdf->Cell(120, 10, 'Cash Given:', 0);
            $pdf->Cell(30, 10, number_format($cash_given, 2), 0, 1);
            $pdf->Cell(120, 10, 'Balance:', 0);
            $pdf->Cell(30, 10, number_format($balance, 2), 0, 1);
        }

        $pdf->Cell(120, 10, 'Current Outstanding Balance:', 0);
        $pdf->Cell(30, 10, number_format($current_balance, 2), 0, 1);

        $pdf_file = "$bill_dir/bill_$sale_id.pdf";
        $pdf->Output('F', $pdf_file);

        mysqli_commit($conn);
        ob_end_flush();
        header("Location: sell.php?pdf=" . urlencode($pdf_file));
        exit;
    } catch (Exception $e) {
        mysqli_rollback($conn);
        echo "<script>alert('Sale failed: " . $e->getMessage() . "');</script>";
    }
}

// Handle customer payment
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['make_payment'])) {
    file_put_contents('debug.log', "Make payment triggered at " . date('Y-m-d H:i:s') . "\n", FILE_APPEND);
    
    $customer_id = $_POST['customer_id'];
    $payment_amount = floatval($_POST['payment_amount']);

    mysqli_begin_transaction($conn);

    try {
        $customer = mysqli_fetch_assoc(mysqli_query($conn, "SELECT name, outstanding_balance FROM customers WHERE id = $customer_id"));
        $old_balance = $customer['outstanding_balance'];
        $new_balance = $old_balance - $payment_amount;

        $sql = "UPDATE customers SET outstanding_balance = outstanding_balance - $payment_amount WHERE id = $customer_id";
        mysqli_query($conn, $sql) or die("Update balance failed: " . mysqli_error($conn));

        $pdf = new FPDF();
        $pdf->AddPage();
        $pdf->SetFont('Arial', 'B', 16);
        $pdf->Cell(0, 10, 'Payment Receipt', 0, 1, 'C');
        $pdf->Ln(10);

        $pdf->SetFont('Arial', '', 12);
        $pdf->Cell(0, 10, "Receipt Number: " . time(), 0, 1);
        $pdf->Cell(0, 10, "Customer Name: " . $customer['name'], 0, 1);
        $pdf->Cell(0, 10, "Date & Time: " . date('Y-m-d H:i:s'), 0, 1);
        $pdf->Ln(10);

        $pdf->Cell(120, 10, 'Payment Amount:', 0);
        $pdf->Cell(30, 10, number_format($payment_amount, 2), 0, 1);
        $pdf->Cell(120, 10, 'Previous Outstanding Balance:', 0);
        $pdf->Cell(30, 10, number_format($old_balance, 2), 0, 1);
        $pdf->Cell(120, 10, 'New Outstanding Balance:', 0);
        $pdf->Cell(30, 10, number_format($new_balance, 2), 0, 1);

        $pdf_file = "$bill_dir/payment_" . time() . ".pdf";
        $pdf->Output('F', $pdf_file);

        mysqli_commit($conn);
        ob_end_flush();
        header("Location: sell.php?payment_pdf=" . urlencode($pdf_file));
        exit;
    } catch (Exception $e) {
        mysqli_rollback($conn);
        echo "<script>alert('Payment failed: " . $e->getMessage() . "');</script>";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Sell Products - Bakery Management</title>
    <!-- Offline Bootstrap CSS -->
    <link href="assets/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div class="container mt-4">
        <h1>Sell Finished Products</h1>
        
        <!-- Display current time in Colombo -->
        <p>Current Time in Colombo: <?php echo date('Y-m-d H:i:s'); ?></p>

        <?php
        if (isset($_GET['pdf'])) {
            $pdf_file = urldecode($_GET['pdf']);
            echo "<div class='alert alert-success'>Sale completed! <a href='$pdf_file' download>Download Bill PDF</a></div>";
        }
        if (isset($_GET['payment_pdf'])) {
            $pdf_file = urldecode($_GET['payment_pdf']);
            echo "<div class='alert alert-success'>Payment completed! <a href='$pdf_file' download>Download Payment Receipt</a></div>";
        }
        ?>

        <!-- Sell Products Form -->
        <div class="card mb-4">
            <div class="card-header">Sell Products</div>
            <div class="card-body">
                <form method="post" id="sellForm" action="sell.php">
                    <div class="mb-3">
                        <label class="form-label">Customer</label>
                        <select name="customer_id" class="form-control" required>
                            <?php
                            $customers = mysqli_query($conn, "SELECT * FROM customers");
                            while ($row = mysqli_fetch_assoc($customers)) {
                                echo "<option value='{$row['id']}'>{$row['name']} (Balance: {$row['outstanding_balance']})</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Payment Type</label>
                        <select name="payment_type" id="paymentType" class="form-control" required onchange="toggleCashFields()">
                            <option value="cash">Cash</option>
                            <option value="credit">Credit</option>
                        </select>
                    </div>
                    <div id="productRows">
                        <div class="row mb-3 product-row">
                            <div class="col-md-4">
                                <label class="form-label">Product</label>
                                <select name="products[]" class="form-control product-select" required onchange="updateSubtotal()">
                                    <?php
                                    $products = mysqli_query($conn, "SELECT * FROM products WHERE wet_stock > 0 OR dry_stock > 0 OR polish_stock > 0");
                                    while ($row = mysqli_fetch_assoc($products)) {
                                        echo "<option value='{$row['id']}' data-wet-price='{$row['wet_price']}' data-dry-price='{$row['dry_price']}' data-polish-price='{$row['polish_price']}' data-wet='{$row['wet_stock']}' data-dry='{$row['dry_stock']}' data-polish='{$row['polish_stock']}'>{$row['name']} (Wet: {$row['wet_stock']} @ {$row['wet_price']}, Dry: {$row['dry_stock']} @ {$row['dry_price']}, Polish: {$row['polish_stock']} @ {$row['polish_price']})</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Stock Level</label>
                                <select name="stock_levels[]" class="form-control stock-level-select" required onchange="updateSubtotal()">
                                    <option value="wet">Wet</option>
                                    <option value="dry">Dry</option>
                                    <option value="polish">Polish</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Quantity</label>
                                <input type="number" name="quantities[]" class="form-control quantity-input" placeholder="Quantity" required min="1" oninput="updateSubtotal()">
                            </div>
                            <div class="col-md-2">
                                <button type="button" class="btn btn-danger mt-4 remove-row">Remove</button>
                            </div>
                        </div>
                    </div>
                    <button type="button" id="addProduct" class="btn btn-secondary mb-3">Add Another Product</button>

                    <div id="cashFields" style="display: none;">
                        <div class="mb-3">
                            <label class="form-label">Subtotal (LKR)</label>
                            <input type="number" step="0.01" id="subtotal" class="form-control" readonly>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Cash Given (LKR)</label>
                            <input type="number" step="0.01" name="cash_given" id="cashGiven" class="form-control" oninput="updateBalance()">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Balance (LKR)</label>
                            <input type="number" step="0.01" id="balance" class="form-control" readonly>
                        </div>
                    </div>

                    <button type="submit" name="sell_products" class="btn btn-warning">Sell Products</button>
                    <a href="index.php" class="btn btn-secondary">Back to Dashboard</a>
                </form>
            </div>
        </div>

        <!-- Make Customer Payment Form -->
        <div class="card mb-4">
            <div class="card-header">Make Customer Payment</div>
            <div class="card-body">
                <form method="post" action="sell.php">
                    <div class="mb-3">
                        <label class="form-label">Customer</label>
                        <select name="customer_id" class="form-control" required>
                            <?php
                            $customers = mysqli_query($conn, "SELECT * FROM customers");
                            while ($row = mysqli_fetch_assoc($customers)) {
                                echo "<option value='{$row['id']}'>{$row['name']} (Balance: {$row['outstanding_balance']})</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Payment Amount (LKR)</label>
                        <input type="number" step="0.01" name="payment_amount" class="form-control" placeholder="Payment Amount" required min="0">
                    </div>
                    <button type="submit" name="make_payment" class="btn btn-success">Make Payment</button>
                </form>
            </div>
        </div>

        <!-- Sales History -->
        <div class="card mb-4">
            <div class="card-header">Sales History</div>
            <div class="card-body">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Sale ID</th>
                            <th>Customer</th>
                            <th>Products</th>
                            <th>Total Amount</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $sales = mysqli_query($conn, "SELECT s.*, c.name AS customer_name 
                            FROM sales s 
                            JOIN customers c ON s.customer_id = c.id 
                            ORDER BY s.sale_date DESC");
                        while ($sale = mysqli_fetch_assoc($sales)) {
                            $items = mysqli_query($conn, "SELECT si.*, p.name AS product_name 
                                FROM sales_items si 
                                JOIN products p ON si.product_id = p.id 
                                WHERE si.sale_id = {$sale['id']}");
                            $items_list = [];
                            while ($item = mysqli_fetch_assoc($items)) {
                                $items_list[] = "{$item['product_name']} (Qty: {$item['quantity']}, Price: {$item['unit_price']}, Level: {$item['stock_level']})";
                            }
                            $items_display = implode(", ", $items_list);
                            echo "<tr>
                                <td>{$sale['id']}</td>
                                <td>{$sale['customer_name']}</td>
                                <td>{$items_display}</td>
                                <td>{$sale['total_amount']}</td>
                                <td>{$sale['sale_date']}</td>
                            </tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Offline Bootstrap JS -->
    <script src="assets/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.getElementById('sellForm');
        const productRows = document.getElementById('productRows');
        const addProductBtn = document.getElementById('addProduct');
        const paymentType = document.getElementById('paymentType');
        const cashFields = document.getElementById('cashFields');
        const subtotalInput = document.getElementById('subtotal');
        const cashGivenInput = document.getElementById('cashGiven');
        const balanceInput = document.getElementById('balance');

        function toggleCashFields() {
            cashFields.style.display = paymentType.value === 'cash' ? 'block' : 'none';
            if (paymentType.value === 'cash') updateSubtotal();
        }
        paymentType.addEventListener('change', toggleCashFields);
        toggleCashFields();

        addProductBtn.addEventListener('click', function() {
            const newRow = productRows.children[0].cloneNode(true);
            newRow.querySelector('input[name="quantities[]"]').value = '';
            newRow.querySelector('.product-select').addEventListener('change', updateSubtotal);
            newRow.querySelector('.stock-level-select').addEventListener('change', updateSubtotal);
            newRow.querySelector('.quantity-input').addEventListener('input', updateSubtotal);
            productRows.appendChild(newRow);
            updateSubtotal();
        });

        productRows.addEventListener('click', function(e) {
            if (e.target.classList.contains('remove-row') && productRows.children.length > 1) {
                e.target.closest('.product-row').remove();
                updateSubtotal();
            }
        });

        function updateSubtotal() {
            if (paymentType.value !== 'cash') return;
            let total = 0;
            const rows = productRows.getElementsByClassName('product-row');
            for (let row of rows) {
                const select = row.querySelector('.product-select');
                const stockLevelSelect = row.querySelector('.stock-level-select');
                const quantity = parseFloat(row.querySelector('.quantity-input').value) || 0;
                const stockLevel = stockLevelSelect.value;
                let price = 0;
                switch (stockLevel) {
                    case 'wet': price = parseFloat(select.options[select.selectedIndex].dataset.wetPrice); break;
                    case 'dry': price = parseFloat(select.options[select.selectedIndex].dataset.dryPrice); break;
                    case 'polish': price = parseFloat(select.options[select.selectedIndex].dataset.polishPrice); break;
                }
                total += price * quantity;
            }
            subtotalInput.value = total.toFixed(2);
            updateBalance();
        }

        function updateBalance() {
            const subtotal = parseFloat(subtotalInput.value) || 0;
            const cashGiven = parseFloat(cashGivenInput.value) || 0;
            const balance = cashGiven - subtotal;
            balanceInput.value = balance.toFixed(2);
        }

        productRows.querySelector('.product-select').addEventListener('change', updateSubtotal);
        productRows.querySelector('.stock-level-select').addEventListener('change', updateSubtotal);
        productRows.querySelector('.quantity-input').addEventListener('input', updateSubtotal);
        cashGivenInput.addEventListener('input', updateBalance);

        form.addEventListener('submit', function(e) {
            const quantities = document.getElementsByName('quantities[]');
            const stockLevels = document.getElementsByName('stock_levels[]');
            const productSelects = document.getElementsByName('products[]');
            let errorMsg = '';
            for (let i = 0; i < quantities.length; i++) {
                const qty = parseFloat(quantities[i].value) || 0;
                const stockLevel = stockLevels[i].value;
                const select = productSelects[i];
                const option = select.options[select.selectedIndex];
                const wetStock = parseFloat(option.dataset.wet);
                const dryStock = parseFloat(option.dataset.dry);
                const polishStock = parseFloat(option.dataset.polish);

                if (qty <= 0) {
                    errorMsg = 'Please enter a positive quantity';
                    break;
                }

                let availableStock = 0;
                switch (stockLevel) {
                    case 'wet': availableStock = wetStock; break;
                    case 'dry': availableStock = dryStock; break;
                    case 'polish': availableStock = polishStock; break;
                }

                if (qty > availableStock) {
                    errorMsg = `Not enough ${stockLevel} stock for ${option.text}. Available: ${availableStock}`;
                    break;
                }
            }
            if (paymentType.value === 'cash' && (!cashGivenInput.value || parseFloat(cashGivenInput.value) < 0)) {
                errorMsg = 'Please enter a valid cash amount';
            }
            if (errorMsg) {
                e.preventDefault();
                alert(errorMsg);
                console.log("Form submission blocked: " + errorMsg);
            } else {
                console.log("Form submitted successfully");
            }
        });
    });
    </script>
</body>
</html>