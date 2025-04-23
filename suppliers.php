<?php
include('connection.php');
include('index.php');

// Include FPDF library
require('fpdf.php');

// Ensure report/supplier_payment directory exists
$payment_dir = 'report/supplier_payment';
if (!file_exists($payment_dir)) {
    mkdir($payment_dir, 0777, true);
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Add Supplier
    if (isset($_POST['add_supplier'])) {
        $name = $_POST['name'];
        $contact = $_POST['contact'];
        $sql = "INSERT INTO suppliers (name, contact, outstanding_balance) VALUES ('$name', '$contact', 0)";
        mysqli_query($conn, $sql);
    }

    // Make Payment
    if (isset($_POST['make_payment'])) {
        $supplier_id = $_POST['supplier_id'];
        $payment_amount = $_POST['payment_amount'];

        mysqli_begin_transaction($conn);

        try {
            $supplier = mysqli_fetch_assoc(mysqli_query($conn, "SELECT name, outstanding_balance FROM suppliers WHERE id = $supplier_id"));
            $old_balance = $supplier['outstanding_balance'];
            $new_balance = $old_balance - $payment_amount;

            $sql = "UPDATE suppliers SET outstanding_balance = outstanding_balance - $payment_amount WHERE id = $supplier_id";
            mysqli_query($conn, $sql);

            // Generate PDF receipt for payment
            $pdf = new FPDF();
            $pdf->AddPage();
            $pdf->SetFont('Arial', 'B', 16);
            $pdf->Cell(0, 10, 'Supplier Payment Receipt', 0, 1, 'C');
            $pdf->Ln(10);

            $pdf->SetFont('Arial', '', 12);
            $pdf->Cell(0, 10, "Receipt Number: " . time(), 0, 1); // Using timestamp as a simple unique ID
            $pdf->Cell(0, 10, "Supplier Name: " . $supplier['name'], 0, 1);
            $pdf->Cell(0, 10, "Date & Time: " . date('Y-m-d H:i:s'), 0, 1);
            $pdf->Ln(10);

            $pdf->Cell(120, 10, 'Payment Amount:', 0);
            $pdf->Cell(30, 10, number_format($payment_amount, 2), 0, 1);
            $pdf->Cell(120, 10, 'Previous Outstanding Balance:', 0);
            $pdf->Cell(30, 10, number_format($old_balance, 2), 0, 1);
            $pdf->Cell(120, 10, 'New Outstanding Balance:', 0);
            $pdf->Cell(30, 10, number_format($new_balance, 2), 0, 1);

            $pdf_file = "$payment_dir/supplier_payment_" . time() . ".pdf"; // Unique filename with timestamp
            $pdf->Output('F', $pdf_file);

            mysqli_commit($conn);

            header("Location: suppliers.php?payment_pdf=" . urlencode($pdf_file));
            exit;
        } catch (Exception $e) {
            mysqli_rollback($conn);
            echo "<script>alert('Payment failed: " . $e->getMessage() . "');</script>";
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Suppliers - Bakery Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div class="container mt-4">
        <h1>Manage Suppliers</h1>

        <?php
        if (isset($_GET['payment_pdf'])) {
            $pdf_file = urldecode($_GET['payment_pdf']);
            echo "<div class='alert alert-success'>Payment completed! <a href='$pdf_file' download>Download Payment Receipt</a></div>";
        }
        ?>

        <div class="card mb-4">
            <div class="card-header">Add New Supplier</div>
            <div class="card-body">
                <form method="post">
                    <div class="mb-3">
                        <label class="form-label">Supplier Name</label>
                        <input type="text" name="name" class="form-control" placeholder="Supplier Name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Contact</label>
                        <input type="text" name="contact" class="form-control" placeholder="Contact Details">
                    </div>
                    <button type="submit" name="add_supplier" class="btn btn-primary">Add Supplier</button>
                    <a href="index.php" class="btn btn-secondary">Back to Dashboard</a>
                </form>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header">Make Supplier Payment</div>
            <div class="card-body">
                <form method="post">
                    <div class="mb-3">
                        <label class="form-label">Supplier</label>
                        <select name="supplier_id" class="form-control" required>
                            <?php
                            $suppliers = mysqli_query($conn, "SELECT * FROM suppliers");
                            while ($row = mysqli_fetch_assoc($suppliers)) {
                                echo "<option value='{$row['id']}'>{$row['name']} (Balance: {$row['outstanding_balance']})</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Payment Amount ($)</label>
                        <input type="number" step="0.01" name="payment_amount" class="form-control" placeholder="Payment Amount" required>
                    </div>
                    <button type="submit" name="make_payment" class="btn btn-success">Make Payment</button>
                </form>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header">Supplier List</div>
            <div class="card-body">
                <table class="table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Contact</th>
                            <th>Outstanding Balance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $suppliers = mysqli_query($conn, "SELECT * FROM suppliers");
                        while ($row = mysqli_fetch_assoc($suppliers)) {
                            echo "<tr>
                                <td>{$row['id']}</td>
                                <td>{$row['name']}</td>
                                <td>{$row['contact']}</td>
                                <td>{$row['outstanding_balance']}</td>
                            </tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>