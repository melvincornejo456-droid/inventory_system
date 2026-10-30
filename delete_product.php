<?php

require_once "auth.php";
include "db.php";

// Only accept POST requests
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: products.php");
    exit;
}

// Validate the CSRF token
$submitted_token = $_POST["csrf_token"] ?? "";

if (
    !is_string($submitted_token) ||
    !hash_equals($_SESSION["csrf_token"], $submitted_token)
) {
    http_response_code(403);
    exit("Invalid request. Please return to the products page and try again.");
}

// Validate the product ID
$id = filter_input(INPUT_POST, "id", FILTER_VALIDATE_INT);

if ($id === false || $id === null || $id < 1) {
    header("Location: products.php");
    exit;
}

// Delete the product using a prepared statement
$stmt = mysqli_prepare(
    $conn,
    "DELETE FROM products WHERE id = ?"
);

if (!$stmt) {
    error_log("Product deletion statement preparation failed: " . mysqli_error($conn));

    http_response_code(500);
    exit("Unable to delete the product right now.");
}

mysqli_stmt_bind_param($stmt, "i", $id);

if (mysqli_stmt_execute($stmt)) {
    mysqli_stmt_close($stmt);

    header("Location: products.php");
    exit;
}

mysqli_stmt_close($stmt);

http_response_code(500);
exit("Unable to delete the product. It may be associated with existing sales.");


?>