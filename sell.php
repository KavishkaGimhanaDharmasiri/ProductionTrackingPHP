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
$bill_dir = 'report/bill';
if (!file_exists($bill_dir)) {
    mkdir($bill_dir, 0777, true);
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['sell_products'])) {
    $customer_id = (int)$_POST['customer_id'];
    $payment_type = $_POST['payment_type'];
    $products = $_POST['products'];
    $quantities = $_POST['quantities'];
    $stock_levels = $_POST['stock_levels'];
    $discounts = $_POST['discounts']; // New: Discounts per product
    $total_amount = 0;

    $conn->begin_transaction();

    try {
        $stmt = $conn->prepare("INSERT INTO sales (customer_id, total_amount, sale_date) VALUES (?, 0, NOW())");
        $stmt->bind_param("i", $customer_id);
        $stmt->execute();
        $sale_id = $conn->insert_id;
        $stmt->close();

        for ($i = 0; $i < count($products); $i++) {
            $product_id = (int)$products[$i];
            $quantity = (int)$quantities[$i];
            $stock_level = $stock_levels[$i];
            $discount = floatval($discounts[$i] ?? 0); // Default to 0 if not provided

            // Fetch product details
            $stmt = $conn->prepare("SELECT wet_stock, dry_stock, polish_cut_stock, polish_jagged_stock, damage_stock, 
                dry_price, polish_cut_price, polish_jagged_price, damage_price FROM products WHERE id = ?");
            $stmt->bind_param("i", $product_id);
            $stmt->execute();
            $product = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            // Determine stock and price based on selected level
            $available_stock = 0;
            $stock_column = '';
            $unit_price = 0;
            switch ($stock_level) {
                case 'wet':
                    $available_stock = $product['wet_stock'];
                    $stock_column = 'wet_stock';
                    $unit_price = $product['dry_price']; // Wet uses dry_price as per schema
                    break;
                case 'dry':
                    $available_stock = $product['dry_stock'];
                    $stock_column = 'dry_stock';
                    $unit_price = $product['dry_price'];
                    break;
                case 'polish-cut':
                    $available_stock = $product['polish_cut_stock'];
                    $stock_column = 'polish_cut_stock';
                    $unit_price = $product['polish_cut_price'];
                    break;
                case 'polish-jagged':
                    $available_stock = $product['polish_jagged_stock'];
                    $stock_column = 'polish_jagged_stock';
                    $unit_price = $product['polish_jagged_price'];
                    break;
                case 'damage':
                    $available_stock = $product['damage_stock'];
                    $stock_column = 'damage_stock';
                    $unit_price = $product['damage_price'];
                    break;
                default:
                    throw new Exception("Invalid stock level: $stock_level");
            }

            if ($available_stock < $quantity) {
                throw new Exception("Not enough $stock_level stock for product ID: $product_id. Available: $available_stock, Requested: $quantity");
            }

            // Apply discount
            $discounted_price = $unit_price - $discount;
            if ($discounted_price < 0) {
                throw new Exception("Discount exceeds unit price for product ID: $product_id");
            }

            // Deduct from the selected stock level
            $stmt = $conn->prepare("UPDATE products SET $stock_column = $stock_column - ? WHERE id = ?");
            $stmt->bind_param("ii", $quantity, $product_id);
            $stmt->execute();
            $stmt->close();

            $subtotal = $quantity * $discounted_price;
            $total_amount += $subtotal;

            $stmt = $conn->prepare("INSERT INTO sales_items (sale_id, product_id, quantity, unit_price, discount, stock_level) 
                VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("iiidds", $sale_id, $product_id, $quantity, $unit_price, $discount, $stock_level);
            $stmt->execute();
            $stmt->close();
        }

        $stmt = $conn->prepare("UPDATE sales SET total_amount = ? WHERE id = ?");
        $stmt->bind_param("di", $total_amount, $sale_id);
        $stmt->execute();
        $stmt->close();

        $cash_given = 0;
        $balance = 0;

        $stmt = $conn->prepare("SELECT name, outstanding_balance FROM customers WHERE id = ?");
        $stmt->bind_param("i", $customer_id);
        $stmt->execute();
        $customer = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $current_balance = $customer['outstanding_balance'];

        $stmt = $conn->prepare("SELECT si.*, p.name AS product_name 
            FROM sales_items si 
            JOIN products p ON si.product_id = p.id 
            WHERE si.sale_id = ?");
        $stmt->bind_param("i", $sale_id);
        $stmt->execute();
        $items = $stmt->get_result();

        $pdf = new FPDF();
        $pdf->AddPage();
        $pdf->SetFont('Arial', 'B', 16);
        $pdf->Cell(0, 10, 'Cement Factory Bill', 0, 1, 'C');
        $pdf->Ln(10);

        $pdf->SetFont('Arial', '', 12);
        $pdf->Cell(0, 10, "Bill Number: $sale_id", 0, 1);
        $pdf->Cell(0, 10, "Customer Name: " . $customer['name'], 0, 1);
        $pdf->Cell(0, 10, "Date & Time: " . date('Y-m-d H:i:s'), 0, 1);
        $pdf->Cell(0, 10, "Payment Type: " . ucfirst($payment_type), 0, 1);
        $pdf->Ln(10);

        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(50, 10, 'Item', 1);
        $pdf->Cell(30, 10, 'Stock Level', 1);
        $pdf->Cell(25, 10, 'Unit Price', 1);
        $pdf->Cell(25, 10, 'Discount', 1);
        $pdf->Cell(25, 10, 'Quantity', 1);
        $pdf->Cell(35, 10, 'Amount', 1);
        $pdf->Ln();

        $pdf->SetFont('Arial', '', 12);
        while ($item = $items->fetch_assoc()) {
            $amount = $item['quantity'] * ($item['unit_price'] - $item['discount']);
            $pdf->Cell(50, 10, $item['product_name'], 1);
            $pdf->Cell(30, 10, ucwords(str_replace('-', ' ', $item['stock_level'])), 1);
            $pdf->Cell(25, 10, number_format($item['unit_price'], 2), 1);
            $pdf->Cell(25, 10, number_format($item['discount'], 2), 1);
            $pdf->Cell(25, 10, $item['quantity'], 1);
            $pdf->Cell(35, 10, number_format($amount, 2), 1);
            $pdf->Ln();
        }
        $stmt->close();

        $pdf->Ln(10);
        $pdf->Cell(130, 10, 'Subtotal:', 0);
        $pdf->Cell(35, 10, number_format($total_amount, 2), 0, 1);

        if ($payment_type == 'credit') {
            $stmt = $conn->prepare("UPDATE customers SET outstanding_balance = outstanding_balance + ? WHERE id = ?");
            $stmt->bind_param("di", $total_amount, $customer_id);
            $stmt->execute();
            $stmt->close();
            $current_balance += $total_amount;
            $pdf->Cell(130, 10, 'Amount Due:', 0);
            $pdf->Cell(35, 10, number_format($total_amount, 2), 0, 1);
        } elseif ($payment_type == 'cash') {
            $cash_given = floatval($_POST['cash_given']);
            $balance = $cash_given - $total_amount;

            if ($balance < 0) {
                $outstanding = abs($balance);
                $stmt = $conn->prepare("UPDATE customers SET outstanding_balance = outstanding_balance + ? WHERE id = ?");
                $stmt->bind_param("di", $outstanding, $customer_id);
                $stmt->execute();
                $stmt->close();
                $current_balance += $outstanding;
            }

            $pdf->Cell(130, 10, 'Cash Given:', 0);
            $pdf->Cell(35, 10, number_format($cash_given, 2), 0, 1);
            $pdf->Cell(130, 10, 'Balance:', 0);
            $pdf->Cell(35, 10, number_format($balance, 2), 0, 1);
        }

        $pdf->Cell(130, 10, 'Current Outstanding Balance:', 0);
        $pdf->Cell(35, 10, number_format($current_balance, 2), 0, 1);

        $pdf_file = "$bill_dir/bill_$sale_id.pdf";
        $pdf->Output('F', $pdf_file);

        $conn->commit();

        header("Location: sell.php?pdf=" . urlencode($pdf_file));
        exit;
    } catch (Exception $e) {
        $conn->rollback();
        echo "<script>alert('Sale failed: " . addslashes($e->getMessage()) . "');</script>";
    }
}

// Handle customer payment with PDF (unchanged)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['make_payment'])) {
    $customer_id = (int)$_POST['customer_id'];
    $payment_amount = floatval($_POST['payment_amount']);

    $conn->begin_transaction();

    try {
        $stmt = $conn->prepare("SELECT name, outstanding_balance FROM customers WHERE id = ?");
        $stmt->bind_param("i", $customer_id);
        $stmt->execute();
        $customer = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $old_balance = $customer['outstanding_balance'];
        $new_balance = $old_balance - $payment_amount;

        $stmt = $conn->prepare("UPDATE customers SET outstanding_balance = outstanding_balance - ? WHERE id = ?");
        $stmt->bind_param("di", $payment_amount, $customer_id);
        $stmt->execute();
        $stmt->close();

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

        $pdf->Cell(130, 10, 'Payment Amount:', 0);
        $pdf->Cell(35, 10, number_format($payment_amount, 2), 0, 1);
        $pdf->Cell(130, 10, 'Previous Outstanding Balance:', 0);
        $pdf->Cell(35, 10, number_format($old_balance, 2), 0, 1);
        $pdf->Cell(130, 10, 'New Outstanding Balance:', 0);
        $pdf->Cell(35, 10, number_format($new_balance, 2), 0, 1);

        $pdf_file = "$bill_dir/payment_" . time() . ".pdf";
        $pdf->Output('F', $pdf_file);

        $conn->commit();

        header("Location: sell.php?payment_pdf=" . urlencode($pdf_file));
        exit;
    } catch (Exception $e) {
        $conn->rollback();
        echo "<script>alert('Payment failed: " . addslashes($e->getMessage()) . "');</script>";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Sell Products - Cement Factory</title>
    <link href="assets/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div class="container mt-4">
        <h1>Sell Finished Products</h1>
        
        
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

        <div class="card mb-4">
            <div class="card-header">Sell Products</div>
            <div class="card-body">
                <form method="post" id="sellForm">
                    <div class="mb-3">
                        <label class="form-label">Customer</label>
                        <select name="customer_id" class="form-control" required>
                            <?php
                            $stmt = $conn->prepare("SELECT * FROM customers");
                            $stmt->execute();
                            $customers = $stmt->get_result();
                            while ($row = $customers->fetch_assoc()) {
                                echo "<option value='{$row['id']}'>{$row['name']} (Balance: {$row['outstanding_balance']})</option>";
                            }
                            $stmt->close();
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
                            <div class="col-md-3">
                                <label class="form-label">Product</label>
                                <select name="products[]" class="form-control product-select" required onchange="updateSubtotal()">
                                    <?php
                                    $stmt = $conn->prepare("SELECT * FROM products WHERE wet_stock > 0 OR dry_stock > 0 OR polish_cut_stock > 0 OR polish_jagged_stock > 0 OR damage_stock > 0");
                                    $stmt->execute();
                                    $products = $stmt->get_result();
                                    while ($row = $products->fetch_assoc()) {
                                        echo "<option value='{$row['id']}' 
                                            data-wet='{$row['wet_stock']}' 
                                            data-dry='{$row['dry_stock']}' 
                                            data-polish-cut='{$row['polish_cut_stock']}' 
                                            data-polish-jagged='{$row['polish_jagged_stock']}' 
                                            data-damage='{$row['damage_stock']}' 
                                            data-dry-price='{$row['dry_price']}' 
                                            data-polish-cut-price='{$row['polish_cut_price']}' 
                                            data-polish-jagged-price='{$row['polish_jagged_price']}' 
                                            data-damage-price='{$row['damage_price']}'>
                                            {$row['name']} (Wet: {$row['wet_stock']} @ {$row['dry_price']}, Dry: {$row['dry_stock']} @ {$row['dry_price']}, Polish-Cut: {$row['polish_cut_stock']} @ {$row['polish_cut_price']}, Polish-Jagged: {$row['polish_jagged_stock']} @ {$row['polish_jagged_price']}, Damage: {$row['damage_stock']} @ {$row['damage_price']})
                                        </option>";
                                    }
                                    $stmt->close();
                                    ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Stock Level</label>
                                <select name="stock_levels[]" class="form-control stock-level-select" required onchange="updateSubtotal()">
                                    <option value="wet">Wet</option>
                                    <option value="dry">Dry</option>
                                    <option value="polish-cut">Polish-Cut</option>
                                    <option value="polish-jagged">Polish-Jagged</option>
                                    <option value="damage">Damage</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Quantity</label>
                                <input type="number" name="quantities[]" class="form-control quantity-input" placeholder="Quantity" required min="1" oninput="updateSubtotal()">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Discount (LKR)</label>
                                <input type="number" step="0.01" name="discounts[]" class="form-control discount-input" placeholder="0.00" min="0" oninput="updateSubtotal()">
                            </div>
                            <div class="col-md-3">
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

        <div class="card mb-4">
            <div class="card-header">Make Customer Payment</div>
            <div class="card-body">
                <form method="post">
                    <div class="mb-3">
                        <label class="form-label">Customer</label>
                        <select name="customer_id" class="form-control" required>
                            <?php
                            $stmt = $conn->prepare("SELECT * FROM customers");
                            $stmt->execute();
                            $customers = $stmt->get_result();
                            while ($row = $customers->fetch_assoc()) {
                                echo "<option value='{$row['id']}'>{$row['name']} (Balance: {$row['outstanding_balance']})</option>";
                            }
                            $stmt->close();
                            ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Payment Amount (LKR)</label>
                        <input type="number" step="0.01" name="payment_amount" class="form-control" placeholder="Payment Amount" required>
                    </div>
                    <button type="submit" name="make_payment" class="btn btn-success">Make Payment</button>
                </form>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header">Sales History</div>
            <div class="card-body">
                <table class="table table-striped">
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
                        $stmt = $conn->prepare("SELECT s.*, c.name AS customer_name 
                            FROM sales s 
                            JOIN customers c ON s.customer_id = c.id 
                            ORDER BY s.sale_date DESC");
                        $stmt->execute();
                        $sales = $stmt->get_result();
                        while ($sale = $sales->fetch_assoc()) {
                            $stmt = $conn->prepare("SELECT si.*, p.name AS product_name 
                                FROM sales_items si 
                                JOIN products p ON si.product_id = p.id 
                                WHERE si.sale_id = ?");
                            $stmt->bind_param("i", $sale['id']);
                            $stmt->execute();
                            $items = $stmt->get_result();
                            $items_list = [];
                            while ($item = $items->fetch_assoc()) {
                                $items_list[] = "{$item['product_name']} (Qty: {$item['quantity']}, Price: {$item['unit_price']}, Discount: {$item['discount']}, Level: " . ucwords(str_replace('-', ' ', $item['stock_level'])) . ")";
                            }
                            $items_display = implode(", ", $items_list);
                            echo "<tr>
                                <td>{$sale['id']}</td>
                                <td>{$sale['customer_name']}</td>
                                <td>{$items_display}</td>
                                <td>{$sale['total_amount']}</td>
                                <td>{$sale['sale_date']}</td>
                            </tr>";
                            $stmt->close();
                        }
                        $sales->close();
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

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
            newRow.querySelector('input[name="discounts[]"]').value = '';
            newRow.querySelector('.product-select').addEventListener('change', updateSubtotal);
            newRow.querySelector('.stock-level-select').addEventListener('change', updateSubtotal);
            newRow.querySelector('.quantity-input').addEventListener('input', updateSubtotal);
            newRow.querySelector('.discount-input').addEventListener('input', updateSubtotal);
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
                const discount = parseFloat(row.querySelector('.discount-input').value) || 0;
                const stockLevel = stockLevelSelect.value;
                let price = 0;
                switch (stockLevel) {
                    case 'wet': price = parseFloat(select.options[select.selectedIndex].dataset.dryPrice); break;
                    case 'dry': price = parseFloat(select.options[select.selectedIndex].dataset.dryPrice); break;
                    case 'polish-cut': price = parseFloat(select.options[select.selectedIndex].dataset.polishCutPrice); break;
                    case 'polish-jagged': price = parseFloat(select.options[select.selectedIndex].dataset.polishJaggedPrice); break;
                    case 'damage': price = parseFloat(select.options[select.selectedIndex].dataset.damagePrice); break;
                }
                total += (price - discount) * quantity;
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
        productRows.querySelector('.discount-input').addEventListener('input', updateSubtotal);
        cashGivenInput.addEventListener('input', updateBalance);

        form.addEventListener('submit', function(e) {
            const quantities = document.getElementsByName('quantities[]');
            const stockLevels = document.getElementsByName('stock_levels[]');
            const productSelects = document.getElementsByName('products[]');
            const discounts = document.getElementsByName('discounts[]');
            for (let i = 0; i < quantities.length; i++) {
                const qty = parseFloat(quantities[i].value) || 0;
                const stockLevel = stockLevels[i].value;
                const discount = parseFloat(discounts[i].value) || 0;
                const select = productSelects[i];
                const option = select.options[select.selectedIndex];
                const wetStock = parseFloat(option.dataset.wet);
                const dryStock = parseFloat(option.dataset.dry);
                const polishCutStock = parseFloat(option.dataset.polishCut);
                const polishJaggedStock = parseFloat(option.dataset.polishJagged);
                const damageStock = parseFloat(option.dataset.damage);
                const price = parseFloat(option.dataset[`${stockLevel.replace('-', '')}Price`]) || 0;

                if (qty <= 0) {
                    e.preventDefault();
                    alert('Please enter a positive quantity');
                    return;
                }

                if (discount > price) {
                    e.preventDefault();
                    alert(`Discount exceeds unit price for ${option.text} (${stockLevel})`);
                    return;
                }

                let availableStock = 0;
                switch (stockLevel) {
                    case 'wet': availableStock = wetStock; break;
                    case 'dry': availableStock = dryStock; break;
                    case 'polish-cut': availableStock = polishCutStock; break;
                    case 'polish-jagged': availableStock = polishJaggedStock; break;
                    case 'damage': availableStock = damageStock; break;
                }

                if (qty > availableStock) {
                    e.preventDefault();
                    alert(`Not enough ${stockLevel} stock for ${option.text}. Available: ${availableStock}`);
                    return;
                }
            }
            if (paymentType.value === 'cash' && (!cashGivenInput.value || parseFloat(cashGivenInput.value) < 0)) {
                e.preventDefault();
                alert('Please enter a valid cash amount');
                return;
            }
        });
    });
    </script>
</body>
</html>