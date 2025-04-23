<?php
include 'index.php';
include 'connection.php';

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_customer'])) {
    $name = $_POST['customer_name'];
    $contact = $_POST['contact'];
    $sql = "INSERT INTO customers (name, contact) VALUES ('$name', '$contact')";
    mysqli_query($conn, $sql);
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Customers - Bakery Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div class="container mt-4">
        <h1>Customer Management</h1>
        
        <div class="card mb-4">
            <div class="card-header">Add New Customer</div>
            <div class="card-body">
                <form method="post">
                    <div class="mb-3">
                        <label class="form-label">Customer Name</label>
                        <input type="text" name="customer_name" class="form-control" placeholder="Customer Name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Contact Info</label>
                        <input type="text" name="contact" class="form-control" placeholder="Phone/Email" required>
                    </div>
                    <button type="submit" name="add_customer" class="btn btn-primary">Add Customer</button>
                    <a href="index.php" class="btn btn-secondary">Back to Dashboard</a>
                </form>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header">Customer List</div>
            <div class="card-body">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Contact</th>
                            <th>Outstanding Balance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $customers = mysqli_query($conn, "SELECT * FROM customers");
                        while ($row = mysqli_fetch_assoc($customers)) {
                            echo "<tr>
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
    <script src="script.js"></script>
</body>
</html>