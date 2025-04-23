<?php
include 'connection.php';
header('Content-Type: application/json');

$product_id = (int)$_GET['product_id'];
$result = mysqli_query($conn, "SELECT batch_yield FROM products WHERE id = $product_id");
$product = mysqli_fetch_assoc($result);
echo json_encode(['batchYield' => $product['batch_yield']]);
?>