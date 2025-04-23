<?php
ob_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include('connection.php');
include('index.php');

// Debug versions
echo "PHP Version: " . phpversion() . "<br>";
echo "MySQL Version: " . mysqli_get_server_info($conn) . "<br>";

// Include FPDF library
require('fpdf.php');

// Ensure report/bill directory exists
$bill_dir = 'report/bill';
if (!file_exists($bill_dir)) {
    mkdir($bill_dir, 0777, true) or die("Cannot create $bill_dir");
}
if (!is_writable($bill_dir)) {
    die("Directory $bill_dir is not writable");
}

// Log POST data
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    file_put_contents('debug.log', "POST received at " . date('Y-m-d H:i:s') . "\n" . print_r($_POST, true) . "\n", FILE_APPEND);
}

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
        $sql = "INSERT INTO sales (customer_id, total_amount) VALUES ($customer_id, 0)";
        mysqli_query($conn, $sql) or die("Insert sales failed: " . mysqli_error($conn));
        $sale_id = mysqli_insert_id($conn);

        for ($i = 0; $i < count($products); $i++) {
            $product_id = $products[$i];
            $quantity = $quantities[$i];
            $stock_level = $stock_levels[$i];

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

            $sql = "UPDATE products SET $stock_column = $stock_column - $quantity WHERE id = $product_id";
            mysqli_query($conn, $sql) or die("Update stock failed: " . mysqli_error($conn));

            $subtotal = $quantity * $unit_price;
            $total_amount += $subtotal;

            $sql = "INSERT INTO sales_items (sale_id, product_id, quantity, unit_price, stock_level) 
                    VALUES ($sale_id, $product_id, $quantity, $unit_price, '$stock_level')";
            mysqli_query($conn, $sql) or die("Insert sales_items failed: " . mysqli_error($conn));
        }

        $sql = "UPDATE sales SET total_amount = $total_amount WHERE id = $sale_id";
        mysqli_query($conn, $sql) or die("Update sales failed: " . mysqli_error($conn));

        $cash_given = 0;
        $balance = 0;

        $customer = mysqli_fetch_assoc(mysqli_query($conn, "SELECT name, outstanding_balance FROM customers WHERE id = $customer_id"));
        $current_balance = $customer['outstanding_balance'];
        $items = mysqli_query($conn, "SELECT si.*, p.name AS product_name 
            FROM sales_items si 
            JOIN products p ON si.product_id = p.id 
            WHERE si.sale_id = $sale_id");

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
            $cash_given = $_POST['cash_given'];
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

// Rest of your code (make_payment and HTML) remains unchanged...
?>

<!DOCTYPE html>
<html>
<head>
    <title>Sell Products - Bakery Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
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
                <form method="post" id="sellForm" action="sell.php">
                    <!-- ... rest of your form unchanged ... -->
                    <button type="submit" name="sell_products" class="btn btn-warning">Sell Products</button>
                    <a href="index.php" class="btn btn-secondary">Back to Dashboard</a>
                </form>
            </div>
        </div>
        <!-- ... rest of your HTML unchanged ... -->
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- ... your JavaScript unchanged ... -->
</body>
</html>