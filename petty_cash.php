<?php
require_once 'fpdf/fpdf.php'; // Adjust path if needed

include('connection.php');
include('index.php');

// Function to generate GRN PDF
function generateGrnPdf($grn_id, $conn) {
    $pdf = new FPDF();
    $pdf->AddPage();
    $pdf->SetFont('Arial', 'B', 16);
    $pdf->Cell(0, 10, 'Petty Cash GRN Report', 0, 1, 'C');
    $pdf->Ln(10);

    $pdf->SetFont('Arial', '', 12);
    $grn = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM petty_cash_grn WHERE id = $grn_id"));
    if (!$grn) return false;
    $pdf->Cell(0, 10, "GRN ID: $grn_id", 0, 1);
    $pdf->Cell(0, 10, "Date: " . $grn['purchase_date'], 0, 1);
    $pdf->Ln(5);

    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(60, 10, 'Product', 1);
    $pdf->Cell(30, 10, 'Quantity', 1);
    $pdf->Cell(30, 10, 'Unit Price', 1);
    $pdf->Cell(40, 10, 'Subtotal', 1);
    $pdf->Ln();

    $pdf->SetFont('Arial', '', 12);
    $items = mysqli_query($conn, "SELECT p.name, gi.quantity, gi.unit_price 
                                  FROM petty_cash_grn_items gi 
                                  JOIN petty_cash_products p ON gi.product_id = p.id 
                                  WHERE gi.grn_id = $grn_id");
    $total = 0;
    while ($item = mysqli_fetch_assoc($items)) {
        $subtotal = $item['quantity'] * $item['unit_price'];
        $total += $subtotal;
        $pdf->Cell(60, 10, $item['name'], 1);
        $pdf->Cell(30, 10, $item['quantity'], 1);
        $pdf->Cell(30, 10, $item['unit_price'], 1);
        $pdf->Cell(40, 10, number_format($subtotal, 2), 1);
        $pdf->Ln();
    }

    $pdf->Ln(5);
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 10, "Total Amount: " . number_format($total, 2), 0, 1);

    $file_path = "report/petty_cash_grn/grn_$grn_id.pdf";
    $pdf->Output('F', $file_path);
    return $file_path;
}

// Function to generate Usage PDF
function generateUsagePdf($usage_id, $conn) {
    $usage = mysqli_fetch_assoc(mysqli_query($conn, "SELECT pu.*, p.name, p.unit_type 
                                                    FROM petty_cash_usage pu 
                                                    JOIN petty_cash_products p ON pu.product_id = p.id 
                                                    WHERE pu.id = $usage_id"));
    if (!$usage) return false;

    $pdf = new FPDF();
    $pdf->AddPage();
    $pdf->SetFont('Arial', 'B', 16);
    $pdf->Cell(0, 10, 'Petty Cash Usage Report', 0, 1, 'C');
    $pdf->Ln(10);

    $pdf->SetFont('Arial', '', 12);
    $pdf->Cell(0, 10, "Usage ID: $usage_id", 0, 1);
    $pdf->Cell(0, 10, "Product: " . $usage['name'], 0, 1);
    $pdf->Cell(0, 10, "Quantity Deducted: " . $usage['quantity'] . " " . $usage['unit_type'], 0, 1);
    $pdf->Cell(0, 10, "Reason: " . $usage['reason'], 0, 1);
    $pdf->Cell(0, 10, "Date: " . $usage['usage_date'], 0, 1);

    $file_path = "report/petty_cash_usage/usage_$usage_id.pdf";
    $pdf->Output('F', $file_path);
    return $file_path;
}

// Handle form submissions and PDF generation
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    session_start();
    $nonce = $_POST['nonce'] ?? '';
    $last_nonce = $_SESSION['last_nonce'] ?? '';

    if ($nonce === $last_nonce) {
        header("Location: petty_cash.php?error=Duplicate+submission+detected");
        exit;
    }
    $_SESSION['last_nonce'] = $nonce;

    if (isset($_POST['add_product'])) {
        $name = $_POST['product_name'];
        $unit_price = $_POST['unit_price'];
        $unit_type = $_POST['unit_type'];

        $check = mysqli_query($conn, "SELECT id FROM petty_cash_products WHERE name = '$name' AND unit_price = $unit_price AND unit_type = '$unit_type'");
        if (mysqli_num_rows($check) > 0) {
            header("Location: petty_cash.php?error=Product+already+exists");
            exit;
        }

        $sql = "INSERT INTO petty_cash_products (name, unit_price, unit_type) VALUES ('$name', $unit_price, '$unit_type')";
        if (mysqli_query($conn, $sql)) {
            header("Location: petty_cash.php?success=Product+added");
            exit;
        } else {
            header("Location: petty_cash.php?error=Failed+to+add+product");
            exit;
        }
    }

    if (isset($_POST['add_grn'])) {
        $products = $_POST['product_id'];
        $quantities = $_POST['quantity'];
        $total_amount = 0;

        mysqli_begin_transaction($conn);
        try {
            $sql = "INSERT INTO petty_cash_grn (total_amount) VALUES (0)";
            if (!mysqli_query($conn, $sql)) throw new Exception("GRN insert failed");
            $grn_id = mysqli_insert_id($conn);

            foreach ($products as $index => $product_id) {
                $quantity = $quantities[$index];
                $unit_price = mysqli_fetch_assoc(mysqli_query($conn, "SELECT unit_price FROM petty_cash_products WHERE id = $product_id"))['unit_price'];
                $subtotal = $quantity * $unit_price;
                $total_amount += $subtotal;

                $sql = "INSERT INTO petty_cash_grn_items (grn_id, product_id, quantity, unit_price) VALUES ($grn_id, $product_id, $quantity, $unit_price)";
                if (!mysqli_query($conn, $sql)) throw new Exception("GRN item insert failed");
                $sql = "UPDATE petty_cash_products SET stock = stock + $quantity WHERE id = $product_id";
                if (!mysqli_query($conn, $sql)) throw new Exception("Stock update failed");
            }

            $sql = "UPDATE petty_cash_grn SET total_amount = $total_amount WHERE id = $grn_id";
            if (!mysqli_query($conn, $sql)) throw new Exception("GRN update failed");

            mysqli_commit($conn);
            if (!generateGrnPdf($grn_id, $conn)) {
                header("Location: petty_cash.php?error=Failed+to+generate+GRN+PDF");
                exit;
            }
            header("Location: petty_cash.php?success=Petty+cash+GRN+added");
            exit;
        } catch (Exception $e) {
            mysqli_rollback($conn);
            header("Location: petty_cash.php?error=GRN+addition+failed:+" . urlencode($e->getMessage()));
            exit;
        }
    }

    if (isset($_POST['deduct_usage'])) {
        $product_id = $_POST['deduct_product_id'];
        $quantity = $_POST['deduct_quantity'];
        $reason = $_POST['deduct_reason'];

        $current_stock = mysqli_fetch_assoc(mysqli_query($conn, "SELECT stock FROM petty_cash_products WHERE id = $product_id"))['stock'];
        if ($current_stock >= $quantity) {
            $sql = "UPDATE petty_cash_products SET stock = stock - $quantity WHERE id = $product_id";
            if (!mysqli_query($conn, $sql)) {
                header("Location: petty_cash.php?error=Stock+update+failed");
                exit;
            }
            $sql = "INSERT INTO petty_cash_usage (product_id, quantity, reason) 
                    VALUES ($product_id, $quantity, '$reason')";
            if (!mysqli_query($conn, $sql)) {
                header("Location: petty_cash.php?error=Usage+insert+failed");
                exit;
            }
            $usage_id = mysqli_insert_id($conn);
            if ($usage_id > 0) {
                if (!generateUsagePdf($usage_id, $conn)) {
                    header("Location: petty_cash.php?error=Failed+to+generate+Usage+PDF");
                    exit;
                }
                header("Location: petty_cash.php?success=Stock+deducted");
                exit;
            } else {
                header("Location: petty_cash.php?error=Failed+to+get+usage+ID");
                exit;
            }
        } else {
            header("Location: petty_cash.php?error=Insufficient+stock");
            exit;
        }
    }
}

// Handle PDF download requests
if (isset($_GET['download_grn'])) {
    $grn_id = $_GET['download_grn'];
    $file_path = "report/petty_cash_grn/grn_$grn_id.pdf";
    if (file_exists($file_path)) {
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="grn_' . $grn_id . '.pdf"');
        readfile($file_path);
        exit;
    } else {
        header("Location: petty_cash.php?error=GRN+PDF+not+found");
        exit;
    }
}

if (isset($_GET['download_usage'])) {
    $usage_id = $_GET['download_usage'];
    $file_path = "report/petty_cash_usage/usage_$usage_id.pdf";
    if (file_exists($file_path)) {
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="usage_' . $usage_id . '.pdf"');
        readfile($file_path);
        exit;
    } else {
        header("Location: petty_cash.php?error=Usage+PDF+not+found");
        exit;
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Petty Cash Management - Bakery Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .tab-content { padding: 20px; }
    </style>
</head>
<body>
    <div class="container mt-4">
        <h1>Petty Cash Management</h1>
        

        <?php
        if (isset($_GET['success'])) {
            echo "<div class='alert alert-success'>" . htmlspecialchars($_GET['success']) . "</div>";
        }
        if (isset($_GET['error'])) {
            echo "<div class='alert alert-danger'>" . htmlspecialchars($_GET['error']) . "</div>";
        }
        ?>

        <ul class="nav nav-tabs" id="pettyCashTabs" role="tablist">
            <li class="nav-item"><a class="nav-link active" id="products-tab" data-bs-toggle="tab" href="#products" role="tab">Products</a></li>
            <li class="nav-item"><a class="nav-link" id="grn-tab" data-bs-toggle="tab" href="#grn" role="tab">GRN</a></li>
            <li class="nav-item"><a class="nav-link" id="usage-tab" data-bs-toggle="tab" href="#usage" role="tab">Deduct Usage</a></li>
            <li class="nav-item"><a class="nav-link" id="stock-tab" data-bs-toggle="tab" href="#stock" role="tab">Stock</a></li>
        </ul>

        <div class="tab-content" id="pettyCashTabsContent">
            <!-- Products Tab -->
            <div class="tab-pane fade show active" id="products" role="tabpanel">
                <h3>Add Petty Cash Product</h3>
                <form method="post" id="productForm">
                    <input type="hidden" name="nonce" value="<?php echo uniqid(); ?>">
                    <div class="mb-3">
                        <label class="form-label">Product Name</label>
                        <input type="text" name="product_name" class="form-control" placeholder="Product Name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Unit Price</label>
                        <input type="number" step="0.01" name="unit_price" class="form-control" placeholder="Unit Price" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Unit Type</label>
                        <select name="unit_type" class="form-control" required>
                            <option value="kg">kg (Kilograms)</option>
                            <option value="l">l (Liters)</option>
                            <option value="nos">nos (Numbers/Pieces)</option>
                        </select>
                    </div>
                    <button type="submit" name="add_product" class="btn btn-primary">Add Product</button>
                </form>
            </div>

            <!-- GRN Tab -->
            <div class="tab-pane fade" id="grn" role="tabpanel">
                <h3>Add Petty Cash GRN</h3>
                <form method="post" id="grnForm">
                    <input type="hidden" name="nonce" value="<?php echo uniqid(); ?>">
                    <div id="grnItems">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Product</label>
                                <select name="product_id[]" class="form-control" required>
                                    <?php
                                    $products = mysqli_query($conn, "SELECT * FROM petty_cash_products");
                                    while ($row = mysqli_fetch_assoc($products)) {
                                        echo "<option value='{$row['id']}'>{$row['name']} ({$row['unit_type']})</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Quantity</label>
                                <input type="number" step="0.01" name="quantity[]" class="form-control" placeholder="Quantity" required>
                            </div>
                            <div class="col-md-2">
                                <button type="button" class="btn btn-danger mt-4 remove-row">Remove</button>
                            </div>
                        </div>
                    </div>
                    <button type="button" id="addItem" class="btn btn-secondary mb-3">Add Another Item</button>
                    <button type="submit" name="add_grn" class="btn btn-primary">Add GRN</button>
                </form>
                <h3 class="mt-4">GRN History</h3>
                <table class="table table-striped">
                    <thead>
                        <tr><th>ID</th><th>Total Amount</th><th>Date</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                        <?php
                        $grns = mysqli_query($conn, "SELECT * FROM petty_cash_grn ORDER BY purchase_date DESC");
                        while ($grn = mysqli_fetch_assoc($grns)) {
                            echo "<tr>
                                <td>{$grn['id']}</td>
                                <td>{$grn['total_amount']}</td>
                                <td>{$grn['purchase_date']}</td>
                                <td><a href='petty_cash.php?download_grn={$grn['id']}' class='btn btn-sm btn-primary'>Download PDF</a></td>
                            </tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>

            <!-- Deduct Usage Tab -->
            <div class="tab-pane fade" id="usage" role="tabpanel">
                <h3>Deduct Petty Cash Product Usage</h3>
                <form method="post" id="usageForm">
                    <input type="hidden" name="nonce" value="<?php echo uniqid(); ?>">
                    <div class="mb-3">
                        <label class="form-label">Product</label>
                        <select name="deduct_product_id" class="form-control" required>
                            <?php
                            $products = mysqli_query($conn, "SELECT * FROM petty_cash_products");
                            while ($row = mysqli_fetch_assoc($products)) {
                                echo "<option value='{$row['id']}'>{$row['name']} (Stock: {$row['stock']} {$row['unit_type']})</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Quantity</label>
                        <input type="number" step="0.01" name="deduct_quantity" class="form-control" placeholder="Quantity" required min="0">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Reason</label>
                        <input type="text" name="deduct_reason" class="form-control" placeholder="Reason (e.g., Used, Damaged)" required>
                    </div>
                    <button type="submit" name="deduct_usage" class="btn btn-primary">Deduct Stock</button>
                </form>
                <h3 class="mt-4">Usage History</h3>
                <table class="table table-striped">
                    <thead>
                        <tr><th>ID</th><th>Product</th><th>Quantity</th><th>Reason</th><th>Date</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                        <?php
                        $usages = mysqli_query($conn, "SELECT pu.*, p.name 
                                                      FROM petty_cash_usage pu 
                                                      JOIN petty_cash_products p ON pu.product_id = p.id 
                                                      ORDER BY pu.usage_date DESC");
                        while ($usage = mysqli_fetch_assoc($usages)) {
                            echo "<tr>
                                <td>{$usage['id']}</td>
                                <td>{$usage['name']}</td>
                                <td>{$usage['quantity']}</td>
                                <td>{$usage['reason']}</td>
                                <td>{$usage['usage_date']}</td>
                                <td><a href='petty_cash.php?download_usage={$usage['id']}' class='btn btn-sm btn-primary'>Download PDF</a></td>
                            </tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>

            <!-- Stock Tab -->
            <div class="tab-pane fade" id="stock" role="tabpanel">
                <h3>Petty Cash Products Stock</h3>
                <table class="table table-striped">
                    <thead>
                        <tr><th>ID</th><th>Name</th><th>Stock</th><th>Unit Type</th><th>Unit Price</th></tr>
                    </thead>
                    <tbody>
                        <?php
                        $products = mysqli_query($conn, "SELECT * FROM petty_cash_products");
                        while ($row = mysqli_fetch_assoc($products)) {
                            echo "<tr>
                                <td>{$row['id']}</td>
                                <td>{$row['name']}</td>
                                <td>{$row['stock']}</td>
                                <td>{$row['unit_type']}</td>
                                <td>{$row['unit_price']}</td>
                            </tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        window.onload = function() {
            document.getElementById('productForm').reset();
            document.getElementById('grnForm').reset();
            document.getElementById('usageForm').reset();
        };

        document.getElementById('addItem').addEventListener('click', function() {
            const item = document.querySelector('#grnItems .row.mb-3').cloneNode(true);
            item.querySelector('input[name="quantity[]"]').value = '';
            document.getElementById('grnItems').appendChild(item);
        });

        document.getElementById('grnItems').addEventListener('click', function(e) {
            if (e.target.classList.contains('remove-row') && document.querySelectorAll('#grnItems .row.mb-3').length > 1) {
                e.target.closest('.row.mb-3').remove();
            }
        });

        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function() {
                setTimeout(() => this.reset(), 100);
            });
        });
    </script>
</body>
</html>