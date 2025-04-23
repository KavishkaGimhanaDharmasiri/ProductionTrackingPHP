<?php
include('connection.php');
include('index.php');

// Include FPDF library
require('fpdf.php');

// Ensure report/grn directory exists
$bill_dir = 'report/grn';
if (!file_exists($bill_dir)) {
    mkdir($bill_dir, 0777, true);
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_grn'])) {
    $supplier_id = $_POST['supplier_id'];
    $payment_type = $_POST['payment_type'];
    $materials = $_POST['materials'];
    $quantities = $_POST['quantities'];
    $total_amount = 0;

    mysqli_begin_transaction($conn);

    try {
        $sql = "INSERT INTO grn (supplier_id, total_amount) VALUES ($supplier_id, 0)";
        mysqli_query($conn, $sql);
        $grn_id = mysqli_insert_id($conn);

        for ($i = 0; $i < count($materials); $i++) {
            $material_id = $materials[$i];
            $quantity = $quantities[$i];

            $material = mysqli_fetch_assoc(mysqli_query($conn, "SELECT unit_price FROM raw_materials WHERE id = $material_id"));
            $unit_price = $material['unit_price'];

            $subtotal = $quantity * $unit_price;
            $total_amount += $subtotal;

            $sql = "UPDATE raw_materials SET stock = stock + $quantity WHERE id = $material_id";
            mysqli_query($conn, $sql);

            $sql = "INSERT INTO grn_items (grn_id, material_id, quantity, unit_price) 
                    VALUES ($grn_id, $material_id, $quantity, $unit_price)";
            mysqli_query($conn, $sql);
        }

        $sql = "UPDATE grn SET total_amount = $total_amount WHERE id = $grn_id";
        mysqli_query($conn, $sql);

        $cash_given = 0;
        $balance = 0;

        // Fetch supplier details for PDF
        $supplier = mysqli_fetch_assoc(mysqli_query($conn, "SELECT name, outstanding_balance FROM suppliers WHERE id = $supplier_id"));
        $current_balance = $supplier['outstanding_balance'];
        $items = mysqli_query($conn, "SELECT gi.*, rm.name AS material_name 
            FROM grn_items gi 
            JOIN raw_materials rm ON gi.material_id = rm.id 
            WHERE gi.grn_id = $grn_id");

        // Generate PDF bill
        $pdf = new FPDF();
        $pdf->AddPage();
        $pdf->SetFont('Arial', 'B', 16);
        $pdf->Cell(0, 10, 'GRN Bill', 0, 1, 'C');
        $pdf->Ln(10);

        $pdf->SetFont('Arial', '', 12);
        $pdf->Cell(0, 10, "GRN Number: $grn_id", 0, 1);
        $pdf->Cell(0, 10, "Supplier Name: " . $supplier['name'], 0, 1);
        $pdf->Cell(0, 10, "Date & Time: " . date('Y-m-d H:i:s'), 0, 1);
        $pdf->Cell(0, 10, "Payment Type: " . ucfirst($payment_type), 0, 1);
        $pdf->Ln(10);

        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(60, 10, 'Raw Material', 1);
        $pdf->Cell(30, 10, 'Unit Price', 1);
        $pdf->Cell(30, 10, 'Quantity', 1);
        $pdf->Cell(30, 10, 'Amount', 1);
        $pdf->Ln();

        $pdf->SetFont('Arial', '', 12);
        while ($item = mysqli_fetch_assoc($items)) {
            $amount = $item['quantity'] * $item['unit_price'];
            $pdf->Cell(60, 10, $item['material_name'], 1);
            $pdf->Cell(30, 10, number_format($item['unit_price'], 2), 1);
            $pdf->Cell(30, 10, number_format($item['quantity'], 2), 1);
            $pdf->Cell(30, 10, number_format($amount, 2), 1);
            $pdf->Ln();
        }

        $pdf->Ln(10);
        $pdf->Cell(120, 10, 'Subtotal:', 0);
        $pdf->Cell(30, 10, number_format($total_amount, 2), 0, 1);

        if ($payment_type == 'credit') {
            $sql = "UPDATE suppliers SET outstanding_balance = outstanding_balance + $total_amount WHERE id = $supplier_id";
            mysqli_query($conn, $sql);
            $current_balance += $total_amount; // Update for PDF
            $pdf->Cell(120, 10, 'Amount Owed:', 0);
            $pdf->Cell(30, 10, number_format($total_amount, 2), 0, 1);
        } elseif ($payment_type == 'cash') {
            $cash_given = $_POST['cash_given'];
            $balance = $cash_given - $total_amount;

            if ($balance < 0) {
                $sql = "UPDATE suppliers SET outstanding_balance = outstanding_balance + " . abs($balance) . " WHERE id = $supplier_id";
                mysqli_query($conn, $sql);
                $current_balance += abs($balance); // Update for PDF
            }
            // Positive balance is not added to outstanding balance

            $pdf->Cell(120, 10, 'Cash Given:', 0);
            $pdf->Cell(30, 10, number_format($cash_given, 2), 0, 1);
            $pdf->Cell(120, 10, 'Balance:', 0);
            $pdf->Cell(30, 10, number_format($balance, 2), 0, 1);
        }

        $pdf->Cell(120, 10, 'Current Outstanding Balance:', 0);
        $pdf->Cell(30, 10, number_format($current_balance, 2), 0, 1);

        // Save PDF in report/grn folder
        $pdf_file = "$bill_dir/grn_$grn_id.pdf";
        $pdf->Output('F', $pdf_file);

        mysqli_commit($conn);

        header("Location: grn.php?pdf=" . urlencode($pdf_file));
        exit;
    } catch (Exception $e) {
        mysqli_rollback($conn);
        echo "<script>alert('GRN failed: " . $e->getMessage() . "');</script>";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>GRN - Bakery Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div class="container mt-4">
        <h1>Goods Received Note (GRN)</h1>
        
        <?php
        if (isset($_GET['pdf'])) {
            $pdf_file = urldecode($_GET['pdf']);
            echo "<div class='alert alert-success'>GRN completed! <a href='$pdf_file' download>Download GRN Bill</a></div>";
        }
        ?>

        <div class="card mb-4">
            <div class="card-header">Add New GRN</div>
            <div class="card-body">
                <form method="post" id="grnForm">
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
                        <label class="form-label">Payment Type</label>
                        <select name="payment_type" id="paymentType" class="form-control" required onchange="toggleCashFields()">
                            <option value="cash">Cash</option>
                            <option value="credit">Credit</option>
                        </select>
                    </div>
                    <div id="materialRows">
                        <div class="row mb-3 material-row">
                            <div class="col-md-6">
                                <label class="form-label">Raw Material</label>
                                <select name="materials[]" class="form-control material-select" required onchange="updateSubtotal()">
                                    <?php
                                    $materials = mysqli_query($conn, "SELECT * FROM raw_materials");
                                    while ($row = mysqli_fetch_assoc($materials)) {
                                        echo "<option value='{$row['id']}' data-price='{$row['unit_price']}'>{$row['name']} (Stock: {$row['stock']}, Price: {$row['unit_price']})</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Quantity (cube/bag)</label>
                                <input type="number" step="0.01" name="quantities[]" class="form-control quantity-input" placeholder="Quantity" required oninput="updateSubtotal()">
                            </div>
                            <div class="col-md-2">
                                <button type="button" class="btn btn-danger mt-4 remove-row">Remove</button>
                            </div>
                        </div>
                    </div>
                    <button type="button" id="addMaterial" class="btn btn-secondary mb-3">Add Another Material</button>

                    <div id="cashFields" style="display: none;">
                        <div class="mb-3">
                            <label class="form-label">Subtotal ($)</label>
                            <input type="number" step="0.01" id="subtotal" class="form-control" readonly>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Cash Given ($)</label>
                            <input type="number" step="0.01" name="cash_given" id="cashGiven" class="form-control" oninput="updateBalance()">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Balance ($)</label>
                            <input type="number" step="0.01" id="balance" class="form-control" readonly>
                        </div>
                    </div>

                    <button type="submit" name="add_grn" class="btn btn-primary">Add GRN</button>
                    <a href="index.php" class="btn btn-secondary">Back to Dashboard</a>
                </form>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header">GRN History</div>
            <div class="card-body">
                <table class="table">
                    <thead>
                        <tr>
                            <th>GRN ID</th>
                            <th>Supplier</th>
                            <th>Materials</th>
                            <th>Total Amount</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $grn_records = mysqli_query($conn, "SELECT g.*, s.name AS supplier_name 
                            FROM grn g 
                            JOIN suppliers s ON g.supplier_id = s.id 
                            ORDER BY g.supply_date DESC");
                        while ($grn = mysqli_fetch_assoc($grn_records)) {
                            $items = mysqli_query($conn, "SELECT gi.*, rm.name AS material_name 
                                FROM grn_items gi 
                                JOIN raw_materials rm ON gi.material_id = rm.id 
                                WHERE gi.grn_id = {$grn['id']}");
                            $items_list = [];
                            while ($item = mysqli_fetch_assoc($items)) {
                                $items_list[] = "{$item['material_name']} (Qty: {$item['quantity']}, Price: {$item['unit_price']})";
                            }
                            $items_display = implode(", ", $items_list);
                            echo "<tr>
                                <td>{$grn['id']}</td>
                                <td>{$grn['supplier_name']}</td>
                                <td>{$items_display}</td>
                                <td>{$grn['total_amount']}</td>
                                <td>{$grn['supply_date']}</td>
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
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.getElementById('grnForm');
        const materialRows = document.getElementById('materialRows');
        const addMaterialBtn = document.getElementById('addMaterial');
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

        addMaterialBtn.addEventListener('click', function() {
            const newRow = materialRows.children[0].cloneNode(true);
            newRow.querySelector('input[name="quantities[]"]').value = '';
            newRow.querySelector('.material-select').addEventListener('change', updateSubtotal);
            newRow.querySelector('.quantity-input').addEventListener('input', updateSubtotal);
            materialRows.appendChild(newRow);
            updateSubtotal();
        });

        materialRows.addEventListener('click', function(e) {
            if (e.target.classList.contains('remove-row') && materialRows.children.length > 1) {
                e.target.closest('.material-row').remove();
                updateSubtotal();
            }
        });

        function updateSubtotal() {
            if (paymentType.value !== 'cash') return;
            let total = 0;
            const rows = materialRows.getElementsByClassName('material-row');
            for (let row of rows) {
                const select = row.querySelector('.material-select');
                const quantity = parseFloat(row.querySelector('.quantity-input').value) || 0;
                const price = parseFloat(select.options[select.selectedIndex].dataset.price);
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

        materialRows.querySelector('.material-select').addEventListener('change', updateSubtotal);
        materialRows.querySelector('.quantity-input').addEventListener('input', updateSubtotal);
        cashGivenInput.addEventListener('input', updateBalance);

        form.addEventListener('submit', function(e) {
            const quantities = document.getElementsByName('quantities[]');
            for (let qty of quantities) {
                if (qty.value <= 0) {
                    e.preventDefault();
                    alert('Please enter a positive quantity');
                    return;
                }
            }
            if (paymentType.value === 'cash' && (!cashGivenInput.value || cashGivenInput.value < 0)) {
                e.preventDefault();
                alert('Please enter a valid cash amount');
                return;
            }
        });
    });
    </script>
</body>
</html>