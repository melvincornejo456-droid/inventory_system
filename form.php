<?php

require_once "auth.php";

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $name = "Product added successfully!";
    $product = $_POST["product"];
    $stock = $_POST["stock"];
    $status = checkStock($stock);
    

    function checkStock($stock) {

    if ($stock == 0) {
        return "Out of stock";
    } elseif ($stock < 10) {
        return "Low stock";
    } else {
        return "Stock available";
    }

    echo $name . "<br>";
    echo "Product: " . $product . "<br>";
    echo "Stock: " . $stock . "<br>";
    echo $status ."";
}



?>

<!DOCTYPE html>
<html>
<head>
    <title>Inventory System</title>
</head>
<body>

    <h1>Add Product</h1>

    <form method="POST">

        <label>Product Name:</label>
        <input type="text" name="product">

        <br><br>

        <label>Stock:</label>
        <input type="number" name="stock">

        <br><br>


        <button type="submit">Add Product</button>
        
    </form>

</body>
</html>