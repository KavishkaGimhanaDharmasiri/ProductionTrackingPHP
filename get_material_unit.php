<?php
include 'connection.php';
header('Content-Type: application/json');

$material_id = (int)$_GET['material_id'];
$result = mysqli_query($conn, "SELECT unit_type FROM raw_materials WHERE id = $material_id");
$material = mysqli_fetch_assoc($result);

$display_unit = $material['unit_type'];
switch ($material['unit_type']) {
    case 'lorry cube':
        $display_unit = 'concrete pan';
        break;
    case 'kg':
        $display_unit = 'grams';
        break;
}
echo json_encode(['displayUnit' => $display_unit, 'stockUnit' => $material['unit_type']]);
?>