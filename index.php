<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DNS Management Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
        }
        .navbar-brand {
            font-size: 1.5rem;
            font-weight: bold;
        }
        .nav-link {
            color: ##0a0a0a !important;
        }
        .nav-item {
            margin: 5px 0;
        }
        @media (max-width: 576px) {
            .navbar-nav {
                text-align: center;
            }
            .nav-item {
                margin: 10px 0;
            }
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="#">DNS Dashboard</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                <li class="nav-item">
                        <a class="nav-link btn btn-primary" href="orders.php">Order</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link btn btn-primary" href="add_materials.php">Add Raw Materials</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link btn btn-primary" href="add_products.php">Add Products</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link btn btn-primary" href="ingredients.php">Manage Ingredients</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link btn btn-primary" href="production.php">Production</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link btn btn-primary" href="stock.php">View Stock</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link btn btn-success" href="grn.php">Manage GRN</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link btn btn-info" href="suppliers.php">Manage Suppliers</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link btn btn-warning" href="sell.php">Sell Products</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link btn btn-primary" href="customers.php">Manage Customers</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link btn btn-primary" href="view_pdfs.php"> Reports</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link btn btn-primary" href="stock_adjustment.php"> stock adjustments</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link btn btn-primary" href="petty_cash.php"> petty cash</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container mt-4">

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>