<?php
include('connection.php');
include('index.php');

// Handle form submission for adding raw material
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_raw_material'])) {
    session_start();
    $nonce = $_POST['nonce'] ?? '';
    $last_nonce = $_SESSION['last_nonce'] ?? '';

    if ($nonce === $last_nonce) {
        header("Location: http://localhost/bakey1/raw_materials.php?error=Duplicate+submission+detected");
        exit;
    }
    $_SESSION['last_nonce'] = $nonce;

    $name = mysqli_real_escape_string($conn, $_POST['material_name']);
    $unit_price = floatval($_POST['unit_price']);
    $unit_type = $_POST['unit_type'];

    $valid_units = ['lorry cube', 'concrete pan', 'bag', 'kg', 'nos'];
    if (!in_array($unit_type, $valid_units)) {
        header("Location: http://localhost/bakey1/raw_materials.php?error=Invalid+unit+type");
        exit;
    }

    $check = mysqli_query($conn, "SELECT id FROM raw_materials WHERE name = '$name' AND unit_price = $unit_price AND unit_type = '$unit_type'");
    if (mysqli_num_rows($check) > 0) {
        header("Location: add_materials.php?error=Material+already+exists");
        exit;
    }

    $sql = "INSERT INTO raw_materials (name, unit_price, unit_type) VALUES ('$name', $unit_price, '$unit_type')";
    if (mysqli_query($conn, $sql)) {
        header("Location: add_materials.php?success=Raw+material+added");
        exit;
    } else {
        echo "SQL Error: " . mysqli_error($conn) . "<br>Query: $sql";
        exit;
    }
}

// Delete Raw Material
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    $sql = "DELETE FROM raw_materials WHERE id = $id";
    if (mysqli_query($conn, $sql)) {
        header("Location: http://localhost/bakey1/raw_materials.php?success=Raw+material+deleted");
        exit;
    } else {
        echo "Error: " . mysqli_error($conn);
    }
}

// Edit Raw Material
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['edit_raw_material'])) {
    $id = $_POST['material_id'];
    $name = mysqli_real_escape_string($conn, $_POST['material_name']);
    $unit_price = floatval($_POST['unit_price']);
    $unit_type = $_POST['unit_type'];

    $valid_units = ['lorry cube', 'concrete pan', 'bag', 'kg', 'nos'];
    if (!in_array($unit_type, $valid_units)) {
        header("Location: http://localhost/bakey1/raw_materials.php?error=Invalid+unit+type");
        exit;
    }

    $sql = "UPDATE raw_materials SET name='$name', unit_price=$unit_price, unit_type='$unit_type' WHERE id=$id";
    if (mysqli_query($conn, $sql)) {
        header("Location: add_materials.php");
        exit;
    } else {
        echo "Error: " . mysqli_error($conn);
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Raw Materials Management - Bakery Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .tab-content { padding: 20px; }
    </style>
</head>
<body>
    <div class="container mt-4">
        <h1>Raw Materials Management</h1>
        

        <?php
        if (isset($_GET['success'])) {
            echo "<div class='alert alert-success'>" . htmlspecialchars($_GET['success']) . "</div>";
        }
        if (isset($_GET['error'])) {
            echo "<div class='alert alert-danger'>" . htmlspecialchars($_GET['error']) . "</div>";
        }
        ?>

        <ul class="nav nav-tabs" id="rawMaterialTabs" role="tablist">
            <li class="nav-item"><a class="nav-link active" id="add-tab" data-bs-toggle="tab" href="#add" role="tab">Add Raw Material</a></li>
            <li class="nav-item"><a class="nav-link" id="stock-tab" data-bs-toggle="tab" href="#stock" role="tab">Stock</a></li>
        </ul>

        <div class="tab-content" id="rawMaterialTabsContent">
            <!-- Add Raw Material Tab -->
            <div class="tab-pane fade show active" id="add" role="tabpanel">
                <h3>Add Raw Material</h3>
                <form method="post" id="rawMaterialForm">
                    <input type="hidden" name="nonce" value="<?php echo uniqid(); ?>">
                    <div class="mb-3">
                        <label class="form-label">Material Name</label>
                        <input type="text" name="material_name" class="form-control" placeholder="Material Name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Unit Price</label>
                        <input type="number" step="0.01" name="unit_price" class="form-control" placeholder="Unit Price" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Unit Type</label>
                        <select name="unit_type" class="form-control" required>
                            <option value="lorry cube">Lorry Cube</option>
                            <option value="concrete pan">Concrete Pan</option>
                            <option value="bag">Bag</option>
                            <option value="kg">kg (Kilograms)</option>
                            <option value="nos">nos (Numbers/Pieces)</option>
                        </select>
                    </div>
                    <button type="submit" name="add_raw_material" class="btn btn-primary">Add Raw Material</button>
                </form>
            </div>

            <!-- Stock Tab -->
            <div class="tab-pane fade" id="stock" role="tabpanel">
                <h3>Raw Materials Stock</h3>
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Stock</th>
                            <th>Unit Type</th>
                            <th>Unit Price</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $materials = mysqli_query($conn, "SELECT * FROM raw_materials");
                        while ($row = mysqli_fetch_assoc($materials)) {
                            echo "<tr>";
                            echo "<td>{$row['id']}</td>";
                            echo "<td>{$row['name']}</td>";
                            echo "<td>{$row['stock']}</td>";
                            echo "<td>{$row['unit_type']}</td>";
                            echo "<td>" . number_format($row['unit_price'], 2) . "</td>";
                            echo "<td>";
                            echo "<button class='btn btn-sm btn-warning me-2' onclick='editRawMaterial(" . json_encode($row) . ")'>Edit</button>";
                            echo "<a href='?delete=" . $row['id'] . "' class='btn btn-sm btn-danger' onclick='return confirm(\"Are you sure?\")'>Delete</a>";
                            echo "</td>";
                            echo "</tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Edit Modal -->
    <div class="modal fade" id="editModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Raw Material</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="post">
                    <div class="modal-body">
                        <input type="hidden" name="material_id" id="edit_id">
                        <div class="mb-3">
                            <label class="form-label">Material Name</label>
                            <input type="text" name="material_name" id="edit_name" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Unit Price</label>
                            <input type="number" step="0.01" name="unit_price" id="edit_price" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Unit Type</label>
                            <select name="unit_type" id="edit_unit_type" class="form-control" required>
                                <option value="lorry cube">Lorry Cube</option>
                                <option value="concrete pan">Concrete Pan</option>
                                <option value="bag">Bag</option>
                                <option value="kg">kg (Kilograms)</option>
                                <option value="nos">nos (Numbers/Pieces)</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" name="edit_raw_material" class="btn btn-primary">Save changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        window.onload = function() {
            document.getElementById('rawMaterialForm').reset();
        };

        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function() {
                setTimeout(() => this.reset(), 100);
            });
        });

        function editRawMaterial(material) {
            document.getElementById('edit_id').value = material.id;
            document.getElementById('edit_name').value = material.name;
            document.getElementById('edit_price').value = material.unit_price;
            document.getElementById('edit_unit_type').value = material.unit_type;
            new bootstrap.Modal(document.getElementById('editModal')).show();
        }
    </script>
</body>
</html>