<?php

require_once "auth.php";

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

include "db.php";

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // 1. Validate the CSRF token
    $submitted_token = $_POST["csrf_token"] ?? "";

    if (
        !isset($_SESSION["csrf_token"]) ||
        !is_string($_SESSION["csrf_token"]) ||
        !is_string($submitted_token) ||
        !hash_equals($_SESSION["csrf_token"], $submitted_token)
    ) {
        http_response_code(403);
        exit("Invalid request. Please reload the page and try again.");
    }

    // 2. Read and validate the shopping cart
    $cartData = $_POST["cart_data"] ?? "";

    if (!is_string($cartData) || $cartData === "") {
        $_SESSION["sale_error"] = "Your shopping cart is empty.";
        header("Location: sales.php");
        exit;
    }

    $decodedCart = json_decode($cartData, true);

    if (
        !is_array($decodedCart) ||
        count($decodedCart) < 1 ||
        count($decodedCart) > 100
    ) {
        $_SESSION["sale_error"] =
            "Invalid shopping cart. Please review your items.";
        header("Location: sales.php");
        exit;
    }

    // Validate every product ID and quantity.
    // Reject duplicate product IDs.
    $cartItems = [];
    $seenProducts = [];

    foreach ($decodedCart as $item) {

        if (
            !is_array($item) ||
            !isset($item["id"], $item["quantity"]) ||
            !(is_int($item["id"]) || is_string($item["id"])) ||
            !(is_int($item["quantity"]) || is_string($item["quantity"]))
        ) {
            $_SESSION["sale_error"] =
                "Invalid cart item. Please review your shopping cart.";
            header("Location: sales.php");
            exit;
        }

        $productId = filter_var(
            $item["id"],
            FILTER_VALIDATE_INT
        );

        $quantity = filter_var(
            $item["quantity"],
            FILTER_VALIDATE_INT
        );

        if (
            $productId === false ||
            $productId < 1 ||
            $quantity === false ||
            $quantity < 1
        ) {
            $_SESSION["sale_error"] =
                "Invalid product or quantity in your cart.";
            header("Location: sales.php");
            exit;
        }

        if (isset($seenProducts[$productId])) {
            $_SESSION["sale_error"] =
                "A product appears more than once in your cart.";
            header("Location: sales.php");
            exit;
        }

        $seenProducts[$productId] = true;

        $cartItems[] = [
            "id" => $productId,
            "quantity" => $quantity
        ];
    }

    // Lock products in a consistent order to reduce deadlock risk.
    usort($cartItems, function ($a, $b) {
        return $a["id"] <=> $b["id"];
    });

    // 3. Prepare transaction handling
    $transactionStarted = false;
    $safeError = "Unable to complete the sale. Please try again.";

    try {

        // Start the database transaction
        if (!mysqli_begin_transaction($conn)) {
            throw new Exception("Transaction could not start.");
        }

        $transactionStarted = true;

        // Prepare the statement that locks product rows.
        $selectStmt = mysqli_prepare(
            $conn,
            "SELECT price, stock
             FROM products
             WHERE id = ?
             FOR UPDATE"
        );

        // Prepare the statement that records each sale.
        $insertStmt = mysqli_prepare(
            $conn,
            "INSERT INTO sales (product_id, quantity, total)
             VALUES (?, ?, ?)"
        );

        // Prepare the safe stock update.
        $updateStmt = mysqli_prepare(
            $conn,
            "UPDATE products
             SET stock = stock - ?
             WHERE id = ? AND stock >= ?"
        );

        $lockedItems = [];

        // 4. Lock and validate every product before changing anything.
        foreach ($cartItems as $item) {

            $productId = $item["id"];
            $quantity = $item["quantity"];

            mysqli_stmt_bind_param(
                $selectStmt,
                "i",
                $productId
            );

            mysqli_stmt_execute($selectStmt);

            $result = mysqli_stmt_get_result($selectStmt);
            $product = mysqli_fetch_assoc($result);
            mysqli_free_result($result);

            if (!$product) {
                $safeError = "A product in your cart no longer exists.";
                throw new Exception("Cart product not found.");
            }

            if ($quantity > (int) $product["stock"]) {
                $safeError =
                    "Not enough stock for a product in your cart. Please review the quantities.";

                throw new Exception("Insufficient stock.");
            }

            // Always use the price retrieved from the database.
            $total = (float) $product["price"] * $quantity;

            $lockedItems[] = [
                "id" => $productId,
                "quantity" => $quantity,
                "total" => $total
            ];
        }

        // 5. Record each item and reduce its stock.
        // All changes remain inside the same transaction.
        foreach ($lockedItems as $item) {

            $productId = $item["id"];
            $quantity = $item["quantity"];
            $total = $item["total"];

            // Insert one sales record per cart item.
            mysqli_stmt_bind_param(
                $insertStmt,
                "iid",
                $productId,
                $quantity,
                $total
            );

            mysqli_stmt_execute($insertStmt);

            // Reduce stock only if enough stock remains.
            mysqli_stmt_bind_param(
                $updateStmt,
                "iii",
                $quantity,
                $productId,
                $quantity
            );

            mysqli_stmt_execute($updateStmt);

            if (mysqli_stmt_affected_rows($updateStmt) !== 1) {
                throw new Exception("Stock update failed.");
            }
        }

        // Close prepared statements before committing.
        mysqli_stmt_close($selectStmt);
        mysqli_stmt_close($insertStmt);
        mysqli_stmt_close($updateStmt);

        // 6. Commit all items together.
        if (!mysqli_commit($conn)) {
            throw new Exception("Database transaction commit failed.");
        }

        // Clear the browser cart after a successful cart checkout.
        if (($_POST["checkout_mode"] ?? "") === "cart") {
            $_SESSION["clear_pos_cart"] = true;
        }

        $_SESSION["sale_message"] =
            "Sale completed successfully!";

        header("Location: sales.php");
        exit;

    } catch (Throwable $e) {

        // Log technical details for debugging.
        error_log("POS checkout failed: " . $e->getMessage());

        // Undo all database changes if anything fails.
        if ($transactionStarted) {
            mysqli_rollback($conn);
        }

        $_SESSION["sale_error"] = $safeError;

        header("Location: sales.php");
        exit;
    }
}

 // 10. Load products for the form
$sql = "SELECT id, product_name, price, stock, image
        FROM products
        WHERE stock > 0
        ORDER BY product_name ASC";
try {
    $result = mysqli_query($conn, $sql);

    $products = mysqli_fetch_all($result, MYSQLI_ASSOC);

    mysqli_free_result($result);

} catch (mysqli_sql_exception $e) {
    error_log($e->getMessage());

    exit("Unable to load products. Please try again later.");
}
?>

<!DOCTYPE html> 
<html>

<head>

    <link rel="stylesheet" href="style.css">

    <title>Make Sale</title>

    <style>
    * {
    box-sizing: border-box;
}


body {
    margin: 0;
    font-family: Arial, Helvetica, sans-serif;
    background-color: #f5f5f5;
    color: #222222;
}


/* Main POS content */
main {
    width: calc(100% - 40px);
    max-width: 1440px;
    margin: 28px auto 48px;
    padding: 0;
}


/* POS page heading */

.page-heading {
    margin-bottom: 32px;
    padding: 4px 0 4px 18px;
    border-left: 5px solid #2563eb;
}

.page-heading h1 {
    margin: 0 0 8px;

    color: #172554;
    font-size: 34px;
    font-weight: 750;
    line-height: 1.25;
    letter-spacing: -0.6px;
}

.page-heading p {
    margin: 0;
    color: #64748b;
    font-size: 15px;
    line-height: 1.6;
}

/* Form card */

.sale-card {
    background-color: #ffffff;
    padding: 32px;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    box-shadow: 0 6px 24px rgba(15, 23, 42, 0.05);
}

.sale-card h2 {
    margin: 0 0 10px;
    color: #172554;
    font-size: 23px;
    font-weight: 700;
    line-height: 1.4;
}

.card-description {
    margin: 0 0 28px;
    color: #64748b;
    font-size: 14px;
    line-height: 1.7;
}

/* Form fields */
.form-group {
    margin-bottom: 22px;
}

label {
    display: block;
    margin-bottom: 8px;
    font-size: 14px;
    font-weight: bold;
    color: #334155;
}

select,
input {
    width: 100%;
    padding: 13px 14px;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    background-color: #ffffff;
    color: #1e293b;
    font-size: 15px;
    outline: none;
    transition: border-color 0.2s, box-shadow 0.2s;
}

select:focus,
input:focus {
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
}

/* Submit button */

.sale-card button {
    width: 100%;
    padding: 14px 18px;
    margin-top: 5px;
    background-color: #2563eb;
    color: #ffffff;
    border: none;
    border-radius: 8px;
    font-size: 15px;
    font-weight: bold;
    cursor: pointer;
    transition: background-color 0.2s, opacity 0.2s;
}

.sale-card button:hover:not(:disabled) {
    background-color: #1d4ed8;
}

.sale-card button:disabled {
    background-color: #94a3b8;
    color: #f8fafc;
    cursor: not-allowed;
    opacity: 0.8;
}

/* Status messages */
.success-message,
.error-message {
    padding: 14px 16px;
    margin: 0 0 20px;
    border-radius: 8px;
    font-size: 14px;
    line-height: 1.5;
}

.success-message {
    background-color: #dcfce7;
    color: #166534;
    border: 1px solid #bbf7d0;
}

.error-message {
    background-color: #fee2e2;
    color: #991b1b;
    border: 1px solid #fecaca;
}

/* Smaller screens */
@media (max-width: 700px) {

    main {
        margin: 25px auto;
        padding: 0 15px;
    }

    .page-heading h1 {
        font-size: 25px;
    }

    .sale-card {
        padding: 22px 18px;
    }
}

/* POS price summary */
.sale-summary {
    margin: 10px 0 22px;
    padding: 20px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
}

.summary-row,
.summary-total {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
}

.summary-row {
    margin-bottom: 14px;
    color: #475569;
    font-size: 14px;
}

.summary-total {
    margin-top: 8px;
    padding: 18px 16px;
    border: 1px solid #bfdbfe;
    border-radius: 10px;
    background-color: #eff6ff;
    color: #1e3a8a;
    font-size: 16px;
    font-weight: 700;
}

.summary-total strong {
    color: #1d4ed8;
    font-size: 28px;
    font-weight: 800;
    font-variant-numeric: tabular-nums;
}

/* POS stock warning */
#stock-warning {
    margin: 14px 0 0;
    padding: 12px 14px;
    border: 1px solid #fecaca;
    border-radius: 8px;
    background-color: #fee2e2;
    color: #991b1b;
    font-size: 14px;
    font-weight: 600;
    line-height: 1.5;
}

#stock-warning[hidden] {
    display: none !important;
}

/* Compact desktop product catalog */
.pos-product-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 12px;
    width: 100%;
    margin: 16px 0 24px;
    align-items: stretch;
}

.pos-product-card {
    display: flex;
    flex-direction: column;
    min-width: 0;
    height: 100%;
    box-sizing: border-box;
    padding: 8px;
    border: 1px solid #eeeeee;
    border-radius: 4px;
    background: #ffffff;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    transition:
        border-color 0.2s ease,
        box-shadow 0.2s ease,
        transform 0.2s ease;
}

.pos-product-card:hover {
    border-color: #ee4d2d;
    box-shadow: 0 3px 10px rgba(238, 77, 45, 0.12);
    transform: translateY(-2px);
}

.pos-product-image {
    display: block;
    width: 100%;
    height: 180px;
    padding: 10px;

    object-fit: contain;
    object-position: center;

    flex-shrink: 0;

    background: #ffffff;
    border: none;
    border-radius: 4px;

    margin: 0 auto 12px;
}

@media (max-width: 768px) {
    .pos-product-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 400px) {
    .pos-product-grid {
        grid-template-columns: 1fr;
    }
}

/* Product information */

.pos-product-name {
    min-height: 45px;
    margin: 0 0 8px;

    color: #222222;
    font-size: 15px;
    font-weight: 500;
    line-height: 1.5;

    overflow-wrap: anywhere;
    word-break: normal;
}

.pos-product-price {
    margin: 0 0 10px;

    color: #ee4d2d;
    font-size: 22px;
    font-weight: 800;
    line-height: 1.4;

    font-variant-numeric: tabular-nums;
    overflow-wrap: anywhere;
}

.pos-product-stock {
    margin: 0 0 14px;

    color: #757575;
    font-size: 12px;
    font-weight: 500;
    line-height: 1.5;
}

.pos-add-button {
    display: block;
    width: 100%;
    margin-top: auto;
    padding: 11px 12px;

    border: 1px solid #ee4d2d;
    border-radius: 4px;

    background: #ee4d2d;
    color: #ffffff;

    font-size: 14px;
    font-weight: 700;

    cursor: pointer;

    transition:
        background-color 0.2s ease,
        border-color 0.2s ease;
}

.pos-add-button:hover {
    background: #d73211;
    border-color: #d73211;
}

.pos-add-button:active {
    transform: scale(0.98);
}

.pos-add-button:focus-visible {
    outline: 3px solid #93c5fd;
    outline-offset: 3px;
}

.pos-add-button:hover {
    background: #1d4ed8;
}

@media (max-width: 480px) {
    .pos-product-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;
    }

    .pos-product-image {
        height: 110px;
    }
}


/* Shopping Cart */

.pos-cart {
    margin-top: 28px;
    padding: 24px;

    background: #ffffff;
    border: 1px solid #eeeeee;
    border-radius: 8px;

    box-shadow: 0 1px 4px rgba(0, 0, 0, 0.04);
}

.pos-cart h2 {
    margin: 0 0 20px;
    padding-bottom: 14px;

    border-bottom: 1px solid #eeeeee;
    border-left: 4px solid #ee4d2d;
    padding-left: 12px;

    color: #222222;
    font-size: 21px;
    font-weight: 700;
    line-height: 1.4;
}

.pos-cart h2 {
    margin: 0 0 18px;
    color: #0f172a;
}

.pos-cart-empty {
    margin: 0;
    padding: 28px 16px;

    border: 1px dashed #cbd5e1;
    border-radius: 10px;

    background-color: #f8fafc;
    color: #64748b;

    font-size: 14px;
    line-height: 1.7;
    text-align: center;
}

.pos-cart-item {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    align-items: center;
    gap: 18px;

    padding: 18px 0;

    border-bottom: 1px solid #eeeeee;
}

.pos-cart-item-name {
    color: #222222;
    font-size: 15px;
    font-weight: 600;
    line-height: 1.5;
    overflow-wrap: anywhere;
}

.pos-cart-item-details {
    margin-top: 6px;
    color: #757575;
    font-size: 13px;
    line-height: 1.6;
}

.pos-cart-controls {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    flex-wrap: wrap;
    gap: 8px;
}

.pos-cart-quantity {
    width: 76px;
    min-height: 38px;
    padding: 8px;

    border: 1px solid #dddddd;
    border-radius: 4px;

    background: #ffffff;
    color: #222222;

    font-size: 14px;
    font-weight: 600;
    text-align: center;

    transition: border-color 0.2s ease;
}

.pos-cart-quantity:focus {
    border-color: #ee4d2d;
    outline: 2px solid rgba(238, 77, 45, 0.15);
    outline-offset: 1px;
}

.pos-cart-remove {
    min-height: 38px;
    padding: 8px 12px;

    border: 1px solid #f3c5bd;
    border-radius: 4px;

    background: #fff5f2;
    color: #d73211;

    font-size: 13px;
    font-weight: 600;

    cursor: pointer;

    transition:
        background-color 0.2s ease,
        border-color 0.2s ease;
}

.pos-cart-remove:hover {
    background: #ffe7e0;
    border-color: #ee4d2d;
}

.pos-cart-remove:focus-visible {
    outline: 3px solid #fca5a5;
    outline-offset: 2px;
}

.pos-cart-total {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px 20px;

    margin-top: 24px;
    padding: 20px;

    border: 1px solid #f5c6bb;
    border-radius: 6px;

    background: #fff5f2;
    color: #333333;

    font-size: 16px;
    font-weight: 700;
}

.pos-cart-total strong {
    color: #ee4d2d;
    font-size: 28px;
    font-weight: 800;
    line-height: 1.3;
    font-variant-numeric: tabular-nums;
    overflow-wrap: anywhere;
}

.pos-cart-total strong {
    color: #1d4ed8;
    font-size: 28px;
    font-weight: 800;
    font-variant-numeric: tabular-nums;
}

@media (max-width: 480px) {
    .pos-cart {
        padding: 16px;
    }

    .pos-cart-item {
        grid-template-columns: 1fr;
    }

    .pos-cart-controls {
        justify-content: flex-start;
    }
}

/* Temporarily hide the legacy single-product checkout fields */
.legacy-checkout-fields {
    display: none !important;
}

#complete-sale-btn {
    display: block;
    width: 100%;
    margin-top: 20px;
    padding: 15px 20px;

    border: 1px solid #ee4d2d;
    border-radius: 5px;

    background: #ee4d2d;
    color: #ffffff;

    font-size: 16px;
    font-weight: 700;

    cursor: pointer;

    transition:
        background-color 0.2s ease,
        border-color 0.2s ease,
        box-shadow 0.2s ease;
}

#complete-sale-btn:hover:not(:disabled) {
    background: #d73211;
    border-color: #d73211;
    box-shadow: 0 4px 10px rgba(238, 77, 45, 0.18);
}

#complete-sale-btn:focus-visible {
    outline: 3px solid #f7a695;
    outline-offset: 3px;
}

#complete-sale-btn:disabled {
    background: #bdbdbd;
    border-color: #bdbdbd;
    color: #ffffff;
    cursor: not-allowed;
    box-shadow: none;
}


/* POS page layout - Step 1 */

body {
    background-color: #f1f5f9;
}

.pos-page {
    width: calc(100% - 40px);
    max-width: 1440px;
    margin: 32px auto;
}

.pos-page h1 {
    color: #172554;
    font-size: 30px;
    font-weight: 700;
    margin-bottom: 8px;
}

.pos-page > p {
    color: #64748b;
    font-size: 15px;
    margin-top: 0;
    margin-bottom: 24px;
}

/* Responsive layout for tablets and mobile phones */
@media (max-width: 768px) {
    main {
        width: calc(100% - 24px);
        margin: 20px auto 32px;
    }

    .sale-card {
        padding: 18px;
    }

    .pos-product-grid {
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 18px;
    }

    .pos-product-image {
        height: 130px;
    }

    .pos-cart {
        padding: 16px;
    }
}

/* Smaller mobile screens */
@media (max-width: 480px) {
    main {
        width: calc(100% - 20px);
    }

    .sale-card {
        padding: 12px;
    }

    .pos-product-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px;
    }

    .pos-product-card {
        padding: 10px;
    }

    .pos-product-image {
        height: 100px;
        padding: 4px;
    }

    .pos-cart-item {
        grid-template-columns: 1fr;
    }

    .pos-cart-controls {
        justify-content: flex-start;
        flex-wrap: wrap;
    }

    .pos-cart-total {
        padding: 14px;
    }

    .pos-cart-total strong {
        font-size: 23px;
    }
}

/* Very narrow phones */
@media (max-width: 340px) {
    .pos-product-grid {
        grid-template-columns: 1fr;
    }
}

/* POS Product Catalog - Final Layout Fix */

.pos-product-grid {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 10px;
    width: 100%;
    margin: 16px 0 24px;
    align-items: stretch;
}

.pos-product-card {
    display: flex;
    flex-direction: column;
    min-width: 0;
    padding: 8px;
    border: 1px solid #eeeeee;
    border-radius: 4px;
    background: #ffffff;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    transition:
        border-color 0.2s ease,
        box-shadow 0.2s ease,
        transform 0.2s ease;
}

.pos-product-card:hover {
    border-color: #ee4d2d;
    box-shadow: 0 3px 10px rgba(238, 77, 45, 0.12);
    transform: translateY(-2px);
}

.pos-product-image {
    display: block;
    width: 100%;
    height: 150px;
    padding: 8px;
    margin: 0 auto 8px;

    object-fit: contain;
    object-position: center;

    background-color: #f8fafc;
    border-radius: 4px;
}

@media (max-width: 1100px) {
    .pos-product-image {
        height: 130px;
    }
}

@media (max-width: 768px) {
    .pos-product-image {
        height: 115px;
    }
}

.pos-product-name {
    min-height: 40px;
    margin: 0 0 8px;

    color: #222222;
    font-size: 14px;
    font-weight: 400;
    line-height: 1.45;

    overflow-wrap: anywhere;
}

.pos-product-price {
    margin: 0 0 8px;

    color: #ee4d2d;
    font-size: 20px;
    font-weight: 700;
    line-height: 1.35;

    font-variant-numeric: tabular-nums;
    overflow-wrap: anywhere;
}

.pos-product-stock {
    margin-bottom: 10px;
    font-size: 12px;
}

.pos-add-button {
    display: block;
    width: 100%;
    margin-top: auto;
    padding: 10px 6px;

    border: 1px solid #ee4d2d;
    border-radius: 4px;

    background: #fff5f2;
    color: #ee4d2d;

    font-size: 13px;
    font-weight: 700;

    cursor: pointer;

    transition:
        background-color 0.2s ease,
        color 0.2s ease,
        border-color 0.2s ease;
}

.pos-add-button:hover {
    background: #ee4d2d;
    border-color: #ee4d2d;
    color: #ffffff;
}

.pos-add-button:active {
    transform: scale(0.98);
}

.pos-add-button:focus-visible {
    outline: 3px solid #93c5fd;
    outline-offset: 2px;
}

@media (max-width: 1100px) {
    .pos-product-grid {
        grid-template-columns: repeat(4, minmax(0, 1fr));
    }

    .pos-product-image {
        height: 130px;
    }
}

@media (max-width: 768px) {
    .pos-product-grid {
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 10px;
    }

    .pos-product-image {
        height: 115px;
    }
}

@media (max-width: 540px) {
    .pos-product-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 8px;
    }

    .pos-product-card {
        padding: 7px;
    }

    .pos-product-image {
        height: 105px;
    }

    .pos-product-price {
        font-size: 17px;
    }

    .pos-add-button {
        padding: 9px 4px;
        font-size: 12px;
    }
}

@media (max-width: 340px) {
    .pos-product-image {
        height: 85px;
    }

    .pos-product-name {
        font-size: 13px;
    }

    .pos-product-price {
        font-size: 15px;
    }
}


/* POS Shopping Cart - Visual Upgrade */

.pos-cart {
    margin-top: 24px;
    padding: 20px;
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(15, 23, 42, 0.04);
}

.pos-cart h2 {
    margin: 0 0 18px;
    padding: 0 0 12px 12px;
    border-bottom: 1px solid #eeeeee;
    border-left: 4px solid #ee4d2d;
    color: #222222;
    font-size: 20px;
    font-weight: 700;
    line-height: 1.4;
}

.pos-cart-empty {
    padding: 24px 12px;
    background: #fafafa;
    border: 1px dashed #d1d5db;
    border-radius: 6px;
    color: #6b7280;
    text-align: center;
    font-size: 14px;
}

@media (max-width: 540px) {
    .pos-cart {
        padding: 14px;
        margin-top: 18px;
    }

    .pos-cart h2 {
        font-size: 18px;
    }
}   


/* POS Cart Total - Checkout Highlight */

.pos-cart-total {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px 16px;

    margin-top: 20px;
    padding: 16px;

    background: #fff5f2;
    border: 1px solid #f5c6bb;
    border-radius: 6px;

    color: #333333;
    font-size: 15px;
    font-weight: 700;
}

.pos-cart-total strong {
    color: #ee4d2d;
    font-size: 25px;
    font-weight: 800;
    line-height: 1.3;
    font-variant-numeric: tabular-nums;
    overflow-wrap: anywhere;
}

@media (max-width: 540px) {
    .pos-cart-total {
        padding: 12px;
        font-size: 14px;
    }

    .pos-cart-total strong {
        font-size: 21px;
    }
}


/* POS Checkout Button - Final Polish */

#complete-sale-btn {
    display: block;
    width: 100%;
    margin-top: 18px;
    padding: 14px 18px;

    border: 1px solid #ee4d2d;
    border-radius: 5px;

    background: #ee4d2d;
    color: #ffffff;

    font-size: 16px;
    font-weight: 700;
    letter-spacing: 0.2px;

    cursor: pointer;

    transition:
        background-color 0.2s ease,
        border-color 0.2s ease,
        box-shadow 0.2s ease;
}

#complete-sale-btn:hover:not(:disabled) {
    background: #d73211;
    border-color: #d73211;
    box-shadow: 0 3px 8px rgba(238, 77, 45, 0.2);
}

#complete-sale-btn:active:not(:disabled) {
    transform: scale(0.99);
}

#complete-sale-btn:focus-visible {
    outline: 3px solid #fdba74;
    outline-offset: 3px;
}

#complete-sale-btn:disabled {
    background: #d1d5db;
    border-color: #d1d5db;
    color: #6b7280;
    cursor: not-allowed;
    box-shadow: none;
}

@media (max-width: 540px) {
    #complete-sale-btn {
        padding: 13px 14px;
        font-size: 15px;
    }
}


/* Selected Product Card */

.pos-product-card.is-selected {
    border-color: #ee4d2d;
    box-shadow: 0 0 0 1px #ee4d2d;
}

.pos-product-card.is-selected .pos-add-button {
    background: #fff5f2;
    color: #ee4d2d;
    border-color: #ee4d2d;
}

.pos-product-card.is-selected .pos-add-button:hover {
    background: #ee4d2d;
    color: #ffffff;
}

/* Balanced Product Search Bar */
.pos-catalog-search {
    display: flex;
    align-items: center;
    gap: 8px;
    width: 100%;
    min-width: 0;
    box-sizing: border-box;
    margin: 0 0 14px;
}

.pos-catalog-search input {
    display: block;
    flex: 1 1 0%;
    min-width: 0;
    width: 0;
    height: 42px;
    min-height: 42px;
    box-sizing: border-box;
    padding: 0 12px;
}

#pos-clear-search {
    display: inline-flex;
    flex: 0 0 auto;
    align-items: center;
    justify-content: center;
    width: auto;
    min-width: 64px;
    height: 42px;
    min-height: 42px;
    box-sizing: border-box;
    padding: 0 12px;
    white-space: nowrap;
}

#pos-clear-search:hover {
    background: #f3f4f6;
    border-color: #9ca3af;
}

/* Hide products that do not match the search */
.pos-product-card[hidden] {
    display: none !important;
}

/* No Products Found Message */
#pos-no-products {
    box-sizing: border-box;
    width: 100%;
    margin: 16px 0;
    padding: 24px 12px;
    border: 1px dashed #d1d5db;
    border-radius: 6px;
    background: #f9fafb;
    color: #6b7280;
    text-align: center;
    font-size: 14px;
    clear: both;
}

#pos-no-products[hidden] {
    display: none !important;
}

/* POS Product Image Hover Effect */
.pos-product-image {
    transition: transform 0.25s ease;
}

.pos-product-card:hover .pos-product-image {
    transform: scale(1.04);
}

/* Highlight Products Added to Cart */
.pos-product-card.is-selected {
    border: 1px solid #ee4d2d;
    background: #fffaf8;
    box-shadow: 0 0 0 2px rgba(238, 77, 45, 0.08);
}

.pos-product-card.is-selected .pos-product-price {
    color: #d73211;
}

.pos-product-card.is-selected .pos-add-button {
    background: #fff0eb;
    border-color: #ee4d2d;
    color: #d73211;
}


/* Product Search with Clear Button */
.pos-catalog-search {
    display: flex;
    align-items: center;
    gap: 8px;
    width: 100%;
    margin: 0 0 14px;
}

.pos-catalog-search input {
    flex: 1;
    min-width: 0;
    width: 100%;
    min-height: 44px;
    box-sizing: border-box;
}

#pos-clear-search {
    flex-shrink: 0;
    min-height: 44px;
    padding: 0 16px;
    border: 1px solid #d1d5db;
    border-radius: 5px;
    background: #ffffff;
    color: #374151;
    font-size: 13px;
    cursor: pointer;
    transition: background 0.2s ease, border-color 0.2s ease;
}

#pos-clear-search:hover {
    background: #f3f4f6;
    border-color: #9ca3af;
}


/* Final POS Search Layout Fix */
.sale-card .pos-catalog-search {
    display: flex;
    flex-direction: row;
    align-items: stretch;
    gap: 8px;
    width: 100%;
    min-width: 0;
    max-width: 100%;
    box-sizing: border-box;
}

.sale-card .pos-catalog-search input#pos-product-search {
    display: block;
    flex: 1 1 0px;
    width: 0;
    min-width: 0;
    max-width: 100%;
    height: 42px;
    min-height: 42px;
    margin: 0;
    box-sizing: border-box;
}

.sale-card .pos-catalog-search #pos-clear-search {
    display: inline-flex;
    flex: 0 0 64px;
    align-items: center;
    justify-content: center;
    width: 64px;
    min-width: 64px;
    max-width: 64px;
    height: 42px;
    min-height: 42px;
    margin: 0;
    padding: 0 8px;
    box-sizing: border-box;
    white-space: nowrap;
}

/* Keep the POS checkout form at full available width */
main form.pos-sale-form {
    display: block;
    width: 100%;
    max-width: 100%;
    min-width: 0;
}

main form.pos-sale-form {
    display: block;
    width: 100%;
    max-width: 100%;
    min-width: 0;
    box-sizing: border-box;
}

/* Product Card Actions */
.pos-product-actions {
    display: flex;
    align-items: stretch;
    gap: 6px;
    width: 100%;
    margin-top: auto;
}

.pos-product-actions .pos-buy-button {
    flex: 1 1 auto;
    min-width: 0;
    padding: 10px 6px;
    border: 1px solid #ee4d2d;
    border-radius: 4px;
    background: #ee4d2d;
    color: #ffffff;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
}

.pos-product-actions .pos-buy-button:hover {
    background: #d73211;
    border-color: #d73211;
}

.pos-product-actions .pos-cart-icon-button {
    display: inline-flex;
    flex: 0 0 40px;
    align-items: center;
    justify-content: center;
    width: 40px;
    min-width: 40px;
    padding: 0;
    border: 1px solid #ee4d2d;
    border-radius: 4px;
    background: #fff5f2;
    color: #ee4d2d;
    cursor: pointer;
}

.pos-product-actions .pos-cart-icon-button:hover {
    background: #ee4d2d;
    color: #ffffff;
}

.pos-product-actions button:focus-visible {
    outline: 3px solid #93c5fd;
    outline-offset: 2px;
}

@media (max-width: 540px) {
    .pos-product-actions .pos-buy-button {
        padding: 9px 3px;
        font-size: 12px;
    }

    .pos-product-actions .pos-cart-icon-button {
        flex-basis: 36px;
        width: 36px;
        min-width: 36px;
    }
}


/* Checkout Modal */
.pos-checkout-modal[hidden] {
    display: none !important;
}

.pos-checkout-modal {
    position: fixed;
    inset: 0;
    z-index: 9999;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 16px;
}

.pos-checkout-backdrop {
    position: absolute;
    inset: 0;
    background: rgba(15, 23, 42, 0.6);
}

.pos-checkout-dialog {
    position: relative;
    z-index: 1;
    width: 100%;
    max-width: 440px;
    max-height: 90vh;
    overflow-y: auto;
    padding: 22px;
    box-sizing: border-box;
    background: #ffffff;
    border-radius: 12px;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.25);
}

.pos-checkout-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding-bottom: 14px;
    border-bottom: 1px solid #e5e7eb;
}

.pos-checkout-header h2 {
    margin: 0;
    font-size: 20px;
    color: #1f2937;
}

.pos-checkout-close {
    width: 36px !important;
    min-width: 36px;
    padding: 4px !important;
    background: transparent !important;
    color: #64748b !important;
    border: none !important;
    font-size: 28px !important;
    cursor: pointer;
}

.pos-checkout-body {
    padding: 20px 0;
}

.pos-checkout-body h3 {
    margin: 0 0 16px;
    font-size: 18px;
    overflow-wrap: anywhere;
}

.pos-checkout-detail {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    margin: 10px 0;
    color: #64748b;
    font-size: 14px;
}

.pos-checkout-detail strong {
    color: #1f2937;
}

.pos-checkout-body label {
    display: block;
    margin: 18px 0 8px;
    font-weight: 600;
}

.pos-checkout-body input {
    width: 100%;
    box-sizing: border-box;
    padding: 11px;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    font-size: 16px;
}

.pos-checkout-subtotal {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    margin-top: 22px;
    padding-top: 16px;
    border-top: 1px solid #e5e7eb;
    font-size: 16px;
}

.pos-checkout-subtotal strong {
    color: #ee4d2d;
    font-size: 20px;
}

.pos-checkout-actions {
    display: flex;
    gap: 10px;
}

.pos-checkout-actions button {
    flex: 1;
    width: auto !important;
    margin: 0 !important;
    padding: 12px 8px !important;
    border-radius: 6px;
    font-size: 14px;
    font-weight: 700;
    cursor: pointer;
}

.pos-checkout-actions .pos-checkout-cancel {
    background: #ffffff !important;
    color: #374151 !important;
    border: 1px solid #d1d5db !important;
}

.pos-checkout-actions .pos-checkout-confirm {
    background: #ee4d2d !important;
    color: #ffffff !important;
    border: 1px solid #ee4d2d !important;
}

@media (max-width: 480px) {
    .pos-checkout-dialog {
        padding: 18px;
    }
}


/* Floating Shopping Cart */
.pos-floating-cart {
    position: fixed;
    right: 24px;
    bottom: 24px;
    z-index: 1000;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 58px;
    height: 58px;
    padding: 0;
    border: none;
    border-radius: 50%;
    background: #ee4d2d;
    color: #ffffff;
    box-shadow: 0 5px 18px rgba(0, 0, 0, 0.25);
    cursor: pointer;
}

.pos-floating-cart:hover {
    background: #d73211;
    transform: translateY(-2px);
}

.pos-floating-cart-count {
    position: absolute;
    top: -5px;
    right: -5px;
    display: flex;
    align-items: center;
    justify-content: center;
    min-width: 22px;
    height: 22px;
    padding: 0 4px;
    box-sizing: border-box;
    border: 2px solid #ffffff;
    border-radius: 999px;
    background: #1d4ed8;
    color: #ffffff;
    font-size: 11px;
    font-weight: 700;
}

@media (max-width: 540px) {
    .pos-floating-cart {
        right: 16px;
        bottom: 16px;
        width: 52px;
        height: 52px;
    }
}


/* Shopping Cart Review Modal */
.pos-cart-modal[hidden] {
    display: none !important;
}

.pos-cart-modal {
    position: fixed;
    inset: 0;
    z-index: 1001;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 16px;
}

.pos-cart-backdrop {
    position: absolute;
    inset: 0;
    background: rgba(15, 23, 42, 0.6);
}

.pos-cart-dialog {
    position: relative;
    z-index: 1;
    display: flex;
    flex-direction: column;
    width: 100%;
    max-width: 540px;
    max-height: 85vh;
    padding: 20px;
    box-sizing: border-box;
    background: #ffffff;
    border-radius: 12px;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.25);
}

.pos-cart-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding-bottom: 14px;
    border-bottom: 1px solid #e5e7eb;
}

.pos-cart-header h2 {
    margin: 0;
    color: #1f2937;
    font-size: 20px;
}

.pos-cart-close {
    width: 36px !important;
    min-width: 36px;
    padding: 4px !important;
    background: transparent !important;
    color: #64748b !important;
    border: none !important;
    font-size: 28px !important;
    cursor: pointer;
}

.pos-cart-review-items {
    overflow-y: auto;
    padding: 8px 0;
}

.pos-cart-review-empty {
    padding: 24px 0;
    text-align: center;
    color: #64748b;
}

.pos-cart-review-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 14px 0;
    border-bottom: 1px solid #e5e7eb;
}

.pos-cart-review-product {
    flex: 1;
    min-width: 0;
}

.pos-cart-review-name {
    margin-bottom: 5px;
    overflow-wrap: anywhere;
    font-weight: 700;
    color: #1f2937;
}

.pos-cart-review-price {
    color: #64748b;
    font-size: 13px;
}

.pos-cart-review-controls {
    display: flex;
    align-items: center;
    gap: 6px;
}

.pos-cart-review-quantity {
    width: 58px;
    min-width: 0;
    padding: 8px 4px;
    box-sizing: border-box;
    border: 1px solid #d1d5db;
    border-radius: 5px;
    text-align: center;
}

.pos-cart-review-remove {
    padding: 7px !important;
    background: #fff1f2 !important;
    color: #be123c !important;
    border: 1px solid #fecdd3 !important;
    border-radius: 5px;
    font-size: 12px;
    cursor: pointer;
}

.pos-cart-review-footer {
    padding-top: 16px;
    border-top: 1px solid #e5e7eb;
}

.pos-cart-review-total {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 16px;
    font-size: 17px;
}

.pos-cart-review-total strong {
    color: #ee4d2d;
    font-size: 21px;
}

.pos-cart-review-footer .pos-cart-checkout-button {
    width: 100%;
    margin: 0;
    padding: 13px;
    border: 1px solid #ee4d2d;
    border-radius: 6px;
    background: #ee4d2d;
    color: #ffffff;
    font-weight: 700;
    cursor: pointer;
}

.pos-cart-review-footer .pos-cart-checkout-button:disabled {
    border-color: #d1d5db;
    background: #d1d5db;
    color: #6b7280;
    cursor: not-allowed;
}

@media (max-width: 480px) {
    .pos-cart-dialog {
        padding: 16px;
    }

    .pos-cart-review-item {
        align-items: flex-start;
        flex-direction: column;
    }

    .pos-cart-review-controls {
        width: 100%;
    }
}


/* Final Cart Confirmation */
.pos-cart-confirm-modal[hidden] {
    display: none !important;
}

.pos-cart-confirm-modal {
    position: fixed;
    inset: 0;
    z-index: 1002;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 16px;
}

.pos-cart-confirm-backdrop {
    position: absolute;
    inset: 0;
    background: rgba(15, 23, 42, 0.65);
}

.pos-cart-confirm-dialog {
    position: relative;
    z-index: 1;
    width: 100%;
    max-width: 480px;
    max-height: 85vh;
    overflow-y: auto;
    padding: 22px;
    box-sizing: border-box;
    background: #ffffff;
    border-radius: 12px;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.25);
}

.pos-cart-confirm-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding-bottom: 14px;
    border-bottom: 1px solid #e5e7eb;
}

.pos-cart-confirm-header h2 {
    margin: 0;
    font-size: 20px;
    color: #1f2937;
}

.pos-cart-confirm-message {
    margin: 16px 0;
    color: #64748b;
    font-size: 14px;
    line-height: 1.5;
}

.pos-cart-confirm-items {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.pos-cart-confirm-item {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    padding-bottom: 12px;
    border-bottom: 1px solid #e5e7eb;
}

.pos-cart-confirm-item-name {
    min-width: 0;
    overflow-wrap: anywhere;
    color: #374151;
    font-size: 14px;
}

.pos-cart-confirm-item-total {
    flex-shrink: 0;
    color: #1f2937;
    font-size: 14px;
    font-weight: 700;
}

.pos-cart-confirm-total {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    margin-top: 18px;
    padding-top: 16px;
    border-top: 1px solid #d1d5db;
    font-size: 17px;
}

.pos-cart-confirm-total strong {
    color: #ee4d2d;
    font-size: 21px;
}

.pos-cart-confirm-actions {
    display: flex;
    gap: 10px;
    margin-top: 22px;
}

.pos-cart-confirm-actions button {
    flex: 1;
    width: auto !important;
    margin: 0 !important;
    padding: 12px 8px !important;
    border-radius: 6px;
    font-weight: 700;
    cursor: pointer;
}

.pos-cart-confirm-cancel {
    background: #ffffff !important;
    color: #374151 !important;
    border: 1px solid #d1d5db !important;
}

.pos-cart-confirm-submit {
    background: #ee4d2d !important;
    color: #ffffff !important;
    border: 1px solid #ee4d2d !important;
}

.pos-cart-confirm-submit:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

@media (max-width: 480px) {
    .pos-cart-confirm-dialog {
        padding: 17px;
    }

    .pos-cart-confirm-actions {
        flex-direction: column;
    }
}

</style>
</head>

<body>

     <?php require_once "navbar.php"; ?>

    <main>

        <div class="page-heading">
            <h1>Make a Sale</h1>
            <p>Record a sale and automatically update your inventory stock.</p>
        </div>

        <?php if (isset($_SESSION["sale_message"])): ?>


            <div
                class="success-message"
                role="status"
                data-clear-pos-cart="<?php
                echo !empty($_SESSION["clear_pos_cart"]) ? "1" : "0";
                ?>"
            >

                <?php
                    echo htmlspecialchars(
                        $_SESSION["sale_message"],
                        ENT_QUOTES,
                        "UTF-8"
                    );
                    unset($_SESSION["sale_message"]);

                    unset($_SESSION["clear_pos_cart"]);
                ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION["sale_error"])): ?>
            <div class="error-message" role="alert">
                <?php
                    echo htmlspecialchars(
                        $_SESSION["sale_error"],
                        ENT_QUOTES,
                        "UTF-8"
                    );
                    unset($_SESSION["sale_error"]);
                ?>
            </div>
        <?php endif; ?>

        <section class="sale-card">

            <h2>Sale Details</h2>

            <p class="card-description">
                Select a product and enter the quantity you want to sell.
            </p>

           <form method="POST" class="pos-sale-form">

            <input
                type="hidden"
                name="csrf_token"
                    value="<?php
                                 echo htmlspecialchars(
                            $_SESSION["csrf_token"],
                        ENT_QUOTES,
                    "UTF-8"
                    );
                ?>"
                >
                
                <input type="hidden" name="cart_data" id="cart_data" value="">

                    <input
                        type="hidden"
                        name="checkout_mode"
                        id="checkout_mode"
                        value=""
                    >

                <div class="pos-catalog-search">
                    <input
                        type="search"
                        id="pos-product-search"
                        placeholder="Search products by name..."
                        aria-label="Search products by name"
                        autocomplete="off"
                    >

                        <button type="button" id="pos-clear-search">
                                Clear
                        </button>
                </div>


                <div class="pos-product-grid">

    <?php foreach ($products as $product): ?>

        <div class="pos-product-card">

        <?php
            $image_name = trim((string) ($product["image"] ?? ""));

            if ($image_name !== "") {
            $image_name = "uploads/" . basename($image_name);
            }

            echo "<!-- IMAGE PATH: " . htmlspecialchars($image_name, ENT_QUOTES, "UTF-8") . " -->";

        ?>

            <?php if ($image_name !== ""): ?>

<img
    src="<?php
        echo htmlspecialchars(
            $image_name,
            ENT_QUOTES,
            "UTF-8"
        );
    ?>"
    alt="<?php
        echo htmlspecialchars(
            $product["product_name"],
            ENT_QUOTES,
            "UTF-8"
        );
    ?>"
    class="pos-product-image"
    loading="lazy"
>

            <?php else: ?>

                <div class="pos-product-image">
                    No image available
                </div>

            <?php endif; ?>

            <div class="pos-product-name">
                <?php
                    echo htmlspecialchars(
                        $product["product_name"],
                        ENT_QUOTES,
                        "UTF-8"
                    );
                ?>
            </div>

            <div class="pos-product-price">
                ₱<?php echo number_format((float) $product["price"], 2); ?>
            </div>

            <div class="pos-product-stock">
                Stock: <?php echo (int) $product["stock"]; ?>
            </div>

<div class="pos-product-actions">

    <button
        type="button"
        class="pos-buy-button"
        data-product-id="<?php echo (int) $product["id"]; ?>"
    >
        Buy Item
    </button>

    <button
        type="button"
        class="pos-cart-icon-button"
        data-product-id="<?php echo (int) $product["id"]; ?>"
        aria-label="Add product to cart"
        title="Add to cart"
    >
        <svg
            viewBox="0 0 24 24"
            width="20"
            height="20"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
            aria-hidden="true"
        >
            <circle cx="9" cy="21" r="1"></circle>
            <circle cx="20" cy="21" r="1"></circle>
            <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path>
        </svg>
    </button>

</div>

        </div>

    <?php endforeach; ?>

</div>

    <p id="pos-no-products" hidden>
        No products found. Try another search.
    </p>

                <div class="form-group legacy-checkout-fields">

                    <label for="product_id">Select Product</label>

                    <select name="product_id" id="product_id">
                        <option value="">-- Select a product --</option>

                        <?php foreach ($products as $row): ?>
                            <option
                                    value="<?php echo (int) $row["id"]; ?>"
                                    data-price="<?php echo htmlspecialchars((string) $row["price"], ENT_QUOTES, "UTF-8"); ?>"
                                    data-stock="<?php echo (int) $row["stock"]; ?>"
                            >

                                <?php
                                    echo htmlspecialchars(
                                        $row["product_name"],
                                        ENT_QUOTES,
                                        "UTF-8"
                                    );
                                ?>
                                - ₱<?php echo number_format((float) $row["price"], 2); ?>
                                (Stock: <?php echo (int) $row["stock"]; ?>)
                            </option> 
                        <?php endforeach; ?>

                    </select>
                </div>

                <!-- Checkout Pop-up -->
<div class="pos-checkout-modal" id="pos-checkout-modal" hidden>
    <div class="pos-checkout-backdrop" id="pos-checkout-backdrop"></div>

    <section
        class="pos-checkout-dialog"
        role="dialog"
        aria-modal="true"
        aria-labelledby="pos-checkout-title"
    >
        <div class="pos-checkout-header">
            <h2 id="pos-checkout-title">Confirm Your Purchase</h2>

            <button
                type="button"
                id="pos-checkout-close"
                class="pos-checkout-close"
                aria-label="Close checkout"
            >&times;</button>
        </div>

        <div class="pos-checkout-body">
            <h3 id="checkout-product-name">Product name</h3>

            <p class="pos-checkout-detail">
                Price per item:
                <strong id="checkout-product-price">₱0.00</strong>
            </p>

            <p class="pos-checkout-detail">
                Available stock:
                <strong id="checkout-product-stock">0</strong>
            </p>

            <label for="checkout-quantity">Quantity</label>
            <input
                type="number"
                id="checkout-quantity"
                min="1"
                step="1"
                value="1"
                required
            >

            <div class="pos-checkout-subtotal">
                <span>Subtotal</span>
                <strong id="checkout-subtotal">₱0.00</strong>
            </div>
        </div>

        <div class="pos-checkout-actions">
            <button
                type="button"
                id="pos-checkout-cancel"
                class="pos-checkout-cancel"
            >Cancel</button>

            <button
                type="submit"
                id="complete-sale-btn"
                class="pos-checkout-confirm"
            >Confirm Purchase</button>
        </div>
    </section>
</div>

    </main>

                            <!-- Floating Cart Button -->
<button
    type="button"
    id="pos-floating-cart"
    class="pos-floating-cart"
    aria-label="Open shopping cart"
    title="Open shopping cart"
>
    <svg
        viewBox="0 0 24 24"
        width="26"
        height="26"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true"
    >
        <circle cx="9" cy="21" r="1"></circle>
        <circle cx="20" cy="21" r="1"></circle>
        <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path>
    </svg>

    <span id="pos-floating-cart-count" class="pos-floating-cart-count">0</span>
</button>


<!-- Cart Review Pop-up -->

<div class="pos-cart-modal" id="pos-cart-modal" hidden>
    <div class="pos-cart-backdrop" id="pos-cart-backdrop"></div>

    <section
        class="pos-cart-dialog"
        role="dialog"
        aria-modal="true"
        aria-labelledby="pos-cart-title"
    >
        <div class="pos-cart-header">
            <h2 id="pos-cart-title">My Shopping Cart</h2>

            <button
                type="button"
                id="pos-cart-close"
                class="pos-cart-close"
                aria-label="Close shopping cart"
            >&times;</button>
        </div>

        <div id="pos-cart-review-items" class="pos-cart-review-items">
            <p class="pos-cart-review-empty">Your cart is empty.</p>
        </div>

        <div class="pos-cart-review-footer">
            <div class="pos-cart-review-total">
                <span>Total</span>
                <strong id="pos-cart-review-total">₱0.00</strong>
            </div>

            <button
                type="button"
                id="pos-cart-checkout"
                class="pos-cart-checkout-button"
                disabled
            >Proceed to Checkout</button>
        </div>
    </section>
</div>


<!-- Final Cart Checkout Confirmation -->
<div class="pos-cart-confirm-modal" id="pos-cart-confirm-modal" hidden>
    <div
        class="pos-cart-confirm-backdrop"
        id="pos-cart-confirm-backdrop"
    ></div>

    <section
        class="pos-cart-confirm-dialog"
        role="dialog"
        aria-modal="true"
        aria-labelledby="pos-cart-confirm-title"
    >
        <div class="pos-cart-confirm-header">
            <h2 id="pos-cart-confirm-title">Confirm Your Order</h2>

            <button
                type="button"
                id="pos-cart-confirm-close"
                class="pos-cart-close"
                aria-label="Close confirmation"
            >&times;</button>
        </div>

        <p class="pos-cart-confirm-message">
            Please review your items before completing this sale.
        </p>

        <div
            id="pos-cart-confirm-items"
            class="pos-cart-confirm-items"
        ></div>

        <div class="pos-cart-confirm-total">
            <span>Order Total</span>
            <strong id="pos-cart-confirm-total">₱0.00</strong>
        </div>

        <div class="pos-cart-confirm-actions">
            <button
                type="button"
                id="pos-cart-confirm-cancel"
                class="pos-cart-confirm-cancel"
            >Cancel</button>

            <button
                type="button"
                id="pos-cart-confirm-submit"
                class="pos-cart-confirm-submit"
            >Confirm Purchase</button>
        </div>
    </section>
</div>


<script>
const money = amount =>
    "₱" + amount.toLocaleString("en-PH", {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });

/* Legacy sale summary elements */
const productSelect = document.getElementById("product_id");
const quantityInput = document.getElementById("quantity");
const unitPriceDisplay = document.getElementById("unit-price");
const quantityDisplay = document.getElementById("summary-quantity");
const totalDisplay = document.getElementById("estimated-total");
const stockDisplay = document.getElementById("available-stock");
const stockWarning = document.getElementById("stock-warning");

/* Checkout pop-up elements */
const checkoutModal = document.getElementById("pos-checkout-modal");
const checkoutBackdrop = document.getElementById("pos-checkout-backdrop");
const checkoutClose = document.getElementById("pos-checkout-close");
const checkoutCancel = document.getElementById("pos-checkout-cancel");
const checkoutProductName = document.getElementById("checkout-product-name");
const checkoutProductPrice = document.getElementById("checkout-product-price");
const checkoutProductStock = document.getElementById("checkout-product-stock");
const checkoutQuantity = document.getElementById("checkout-quantity");
const checkoutSubtotal = document.getElementById("checkout-subtotal");
const completeSaleButton = document.getElementById("complete-sale-btn");
const cartDataInput = document.getElementById("cart_data");

const saleForm = completeSaleButton.form;

let saleSubmissionInProgress = false;

/* Products added through the cart icon */

const CART_STORAGE_KEY = "inventory_system_pos_cart";

function loadSavedCart() {
    const savedCart = new Map();

    try {
        const savedData = localStorage.getItem(CART_STORAGE_KEY);

        if (!savedData) {
            return savedCart;
        }

        const items = JSON.parse(savedData);

        if (!Array.isArray(items)) {
            return savedCart;
        }

        items.forEach(item => {
            if (
                item &&
                Number.isSafeInteger(Number(item.id)) &&
                Number(item.id) > 0 &&
                typeof item.name === "string" &&
                Number.isFinite(item.price) &&
                item.price >= 0 &&
                Number.isSafeInteger(item.stock) &&
                item.stock > 0 &&
                Number.isSafeInteger(item.quantity) &&
                item.quantity >= 1 &&
                item.quantity <= item.stock
            ) {
                savedCart.set(String(item.id), {
                    id: String(item.id),
                    name: item.name,
                    price: item.price,
                    stock: item.stock,
                    quantity: item.quantity
                });
            }
        });
    } catch (error) {
        console.warn("Could not load the saved cart.", error);
    }

    return savedCart;
}

const cart = loadSavedCart();

function saveCart() {
    try {
        localStorage.setItem(
            CART_STORAGE_KEY,
            JSON.stringify(Array.from(cart.values()))
        );
    } catch (error) {
        console.warn("Could not save the cart.", error);
        alert("The cart could not be saved in this browser.");
    }
}

/* Currently selected Buy Item product */
let currentCheckoutItem = null;
let checkoutMode = null;
let previousFocus = null;

/* Read product details from its card */
function getProductFromCard(button) {
    const card = button.closest(".pos-product-card");

    if (!card) {
        return null;
    }

    const name = card.querySelector(".pos-product-name");
    const priceElement = card.querySelector(".pos-product-price");
    const stockElement = card.querySelector(".pos-product-stock");

    if (!name || !priceElement || !stockElement) {
        return null;
    }

    const price = Number(
        priceElement.textContent.replace(/[₱,\s]/g, "")
    );

    const stockMatch = stockElement.textContent.match(/\d+/);
    const stock = stockMatch ? Number(stockMatch[0]) : NaN;

    const item = {
        id: String(button.dataset.productId),
        name: name.textContent.trim(),
        price,
        stock
    };

    if (
        !item.id ||
        !Number.isFinite(item.price) ||
        item.price < 0 ||
        !Number.isSafeInteger(item.stock) ||
        item.stock < 0
    ) {
        alert("Unable to read this product's price or stock.");
        return null;
    }

    if (item.stock < 1) {
        alert("This product is out of stock.");
        return null;
    }

    return item;
}

/* Keep the existing legacy summary working when its elements exist */
function updateSaleSummary() {
    if (
        !productSelect ||
        !quantityInput ||
        !unitPriceDisplay ||
        !quantityDisplay ||
        !totalDisplay
    ) {
        return;
    }

    const selectedOption =
        productSelect.options[productSelect.selectedIndex];

    const price = Number(selectedOption?.dataset.price) || 0;
    const stock = Number(selectedOption?.dataset.stock) || 0;
    const quantity = Number(quantityInput.value) || 0;

    unitPriceDisplay.textContent = money(price);
    quantityDisplay.textContent = quantity;
    totalDisplay.textContent = money(price * quantity);

    if (!selectedOption || !selectedOption.value) {
        if (stockDisplay) stockDisplay.textContent = "—";
        if (stockWarning) {
            stockWarning.textContent = "";
            stockWarning.hidden = true;
        }
        return;
    }

    if (stockDisplay) {
        stockDisplay.textContent = stock;
    }

    if (stockWarning) {
        if (quantity > stock || quantity < 1) {
            stockWarning.textContent =
                "Enter a quantity between 1 and " + stock + ".";
            stockWarning.hidden = false;
        } else {
            stockWarning.textContent = "";
            stockWarning.hidden = true;
        }
    }
}

if (productSelect) {
    productSelect.addEventListener("change", updateSaleSummary);
}

if (quantityInput) {
    quantityInput.addEventListener("input", updateSaleSummary);
}

updateSaleSummary();

/* Refresh product-card states */


function renderCartStatus() {

    saveCart();

    const floatingCartCount =
        document.getElementById("pos-floating-cart-count");

    if (floatingCartCount) {
        let totalQuantity = 0;

        cart.forEach(item => {
            totalQuantity += item.quantity;
        });

        floatingCartCount.textContent = totalQuantity;
    }

    document.querySelectorAll(".pos-product-card").forEach(card => {
        const cartButton =
            card.querySelector(".pos-cart-icon-button");

        if (!cartButton) {
            return;
        }

        const id = String(cartButton.dataset.productId);
        const item = cart.get(id);
        const isInCart = Boolean(item);

        card.classList.toggle("is-selected", isInCart);
        cartButton.classList.toggle("is-in-cart", isInCart);

        cartButton.title = isInCart
            ? "In cart — quantity: " + item.quantity
            : "Add to cart";

        cartButton.setAttribute(
            "aria-label",
            isInCart
                ? "In cart: " + item.name +
                  ", quantity " + item.quantity
                : "Add " +
                  (card.querySelector(".pos-product-name")?.textContent.trim()
                    || "product") +
                  " to cart"
        );
    });

    renderCartReview();
}

const cartModal = document.getElementById("pos-cart-modal");
const cartBackdrop = document.getElementById("pos-cart-backdrop");
const cartCloseButton = document.getElementById("pos-cart-close");
const cartReviewItems = document.getElementById("pos-cart-review-items");
const cartReviewTotal = document.getElementById("pos-cart-review-total");
const cartCheckoutButton = document.getElementById("pos-cart-checkout");
const floatingCartButton = document.getElementById("pos-floating-cart");

const cartConfirmModal =
    document.getElementById("pos-cart-confirm-modal");

const cartConfirmBackdrop =
    document.getElementById("pos-cart-confirm-backdrop");

const cartConfirmClose =
    document.getElementById("pos-cart-confirm-close");

const cartConfirmCancel =
    document.getElementById("pos-cart-confirm-cancel");

const cartConfirmItems =
    document.getElementById("pos-cart-confirm-items");

const cartConfirmTotal =
    document.getElementById("pos-cart-confirm-total");

const cartConfirmSubmit =
    document.getElementById("pos-cart-confirm-submit");


function openCartConfirmation() {
    if (cart.size === 0) {
        alert("Your shopping cart is empty.");
        return;
    }

    cartConfirmItems.replaceChildren();

    let total = 0;

    cart.forEach(item => {
        const subtotal = item.price * item.quantity;
        total += subtotal;

        const row = document.createElement("div");
        row.className = "pos-cart-confirm-item";

        const details = document.createElement("div");
        details.className = "pos-cart-confirm-item-name";
        details.textContent =
            item.name + " × " + item.quantity +
            " (" + money(item.price) + " each)";

        const amount = document.createElement("div");
        amount.className = "pos-cart-confirm-item-total";
        amount.textContent = money(subtotal);

        row.append(details, amount);
        cartConfirmItems.appendChild(row);
    });

    cartConfirmTotal.textContent = money(total);

    cartModal.hidden = true;
    cartConfirmModal.hidden = false;
    document.body.style.overflow = "hidden";

    cartConfirmCancel.focus();
}

function closeCartConfirmation() {
    cartConfirmModal.hidden = true;
    checkoutMode = null;

    cartModal.hidden = false;
    document.body.style.overflow = "hidden";

    cartCloseButton.focus();
}

cartConfirmSubmit.addEventListener("click", () => {
    if (cart.size === 0) {
        alert("Your shopping cart is empty.");
        return;
    }

    checkoutMode = "cart";
    saleForm.requestSubmit();
});

cartCheckoutButton.addEventListener("click", openCartConfirmation);

cartConfirmClose.addEventListener("click", closeCartConfirmation);
cartConfirmCancel.addEventListener("click", closeCartConfirmation);
cartConfirmBackdrop.addEventListener("click", closeCartConfirmation);

document.addEventListener("keydown", event => {
    if (event.key === "Escape" && !cartConfirmModal.hidden) {
        closeCartConfirmation();
    }
});

function renderCartReview() {
    if (!cartReviewItems || !cartReviewTotal || !cartCheckoutButton) {
        return;
    }

    cartReviewItems.replaceChildren();

    let total = 0;

    if (cart.size === 0) {
        const emptyMessage = document.createElement("p");
        emptyMessage.className = "pos-cart-review-empty";
        emptyMessage.textContent = "Your cart is empty.";

        cartReviewItems.appendChild(emptyMessage);
    }

    cart.forEach(item => {
        const subtotal = item.price * item.quantity;
        total += subtotal;

        const row = document.createElement("div");
        row.className = "pos-cart-review-item";

        const productDetails = document.createElement("div");
        productDetails.className = "pos-cart-review-product";

        const name = document.createElement("div");
        name.className = "pos-cart-review-name";
        name.textContent = item.name;

        const price = document.createElement("div");
        price.className = "pos-cart-review-price";
        price.textContent =
            money(item.price) + " each · Subtotal: " + money(subtotal);

        productDetails.append(name, price);

        const controls = document.createElement("div");
        controls.className = "pos-cart-review-controls";

        const quantity = document.createElement("input");
        quantity.type = "number";
        quantity.min = "1";
        quantity.max = String(item.stock);
        quantity.step = "1";
        quantity.value = String(item.quantity);
        quantity.className = "pos-cart-review-quantity";
        quantity.setAttribute("aria-label", "Quantity for " + item.name);

        quantity.addEventListener("change", () => {
            const newQuantity = Number(quantity.value);

            if (
                !Number.isSafeInteger(newQuantity) ||
                newQuantity < 1 ||
                newQuantity > item.stock
            ) {
                quantity.value = String(item.quantity);

                alert(
                    "Please enter a quantity between 1 and " +
                    item.stock + "."
                );

                return;
            }

            item.quantity = newQuantity;
            renderCartStatus();
        });

        const removeButton = document.createElement("button");
        removeButton.type = "button";
        removeButton.className = "pos-cart-review-remove";
        removeButton.textContent = "Remove";

        removeButton.addEventListener("click", () => {
            cart.delete(item.id);
            renderCartStatus();
        });

        controls.append(quantity, removeButton);
        row.append(productDetails, controls);
        cartReviewItems.appendChild(row);
    });

    cartReviewTotal.textContent = money(total);
    cartCheckoutButton.disabled = cart.size === 0;
}

function openCartReview() {
    renderCartReview();

    cartModal.hidden = false;
    document.body.style.overflow = "hidden";

    cartCloseButton.focus();
}

function closeCartReview() {
    cartModal.hidden = true;
    document.body.style.overflow = "";

    floatingCartButton.focus();
}

floatingCartButton.addEventListener("click", openCartReview);
cartCloseButton.addEventListener("click", closeCartReview);
cartBackdrop.addEventListener("click", closeCartReview);

document.addEventListener("keydown", event => {
    if (event.key === "Escape" && !cartModal.hidden) {
        closeCartReview();
    }
});



/* Add one item through the cart icon */
document.querySelectorAll(".pos-cart-icon-button").forEach(button => {
    button.addEventListener("click", () => {
        const product = getProductFromCard(button);

        if (!product) {
            return;
        }

        const existingItem = cart.get(product.id);

        if (existingItem) {
            if (existingItem.quantity >= existingItem.stock) {
                alert(
                    "You have already added all available stock for " +
                    existingItem.name + "."
                );
                return;
            }

            existingItem.quantity++;
        } else {
            cart.set(product.id, {
                ...product,
                quantity: 1
            });
        }

        renderCartStatus();
    });
});

/* Update the checkout subtotal */
function updateCheckoutSubtotal() {
    if (!currentCheckoutItem) {
        return;
    }

    const quantity = Number(checkoutQuantity.value);

    checkoutSubtotal.textContent =
        Number.isSafeInteger(quantity) &&
        quantity >= 1 &&
        quantity <= currentCheckoutItem.stock
            ? money(currentCheckoutItem.price * quantity)
            : money(0);
}

/* Open Buy Item checkout for one specific product */
function openCheckout(product, button) {
    checkoutMode = "single";
    currentCheckoutItem = product;
    previousFocus = button;

    checkoutProductName.textContent = product.name;
    checkoutProductPrice.textContent = money(product.price);
    checkoutProductStock.textContent = product.stock;

    checkoutQuantity.min = "1";
    checkoutQuantity.max = String(product.stock);
    checkoutQuantity.value = "1";

    updateCheckoutSubtotal();

    checkoutModal.hidden = false;
    document.body.style.overflow = "hidden";

    checkoutQuantity.focus();
}

/* Close checkout without recording a sale */
function closeCheckout() {
    checkoutModal.hidden = true;
    document.body.style.overflow = "";

    currentCheckoutItem = null;

    checkoutMode = null;

    if (previousFocus && document.contains(previousFocus)) {
        previousFocus.focus();
    }
}

/* Buy Item buttons */
document.querySelectorAll(".pos-buy-button").forEach(button => {
    button.addEventListener("click", () => {
        const product = getProductFromCard(button);

        if (product) {
            openCheckout(product, button);
        }
    });
});

/* Checkout interactions */
checkoutQuantity.addEventListener("input", updateCheckoutSubtotal);

checkoutClose.addEventListener("click", closeCheckout);
checkoutCancel.addEventListener("click", closeCheckout);
checkoutBackdrop.addEventListener("click", closeCheckout);

document.addEventListener("keydown", event => {
    if (event.key === "Escape" && !checkoutModal.hidden) {
        closeCheckout();
    }
});

/* Prepare single-item or shopping-cart checkout */
const checkoutModeInput =
    document.getElementById("checkout_mode");

saleForm.addEventListener("submit", event => {

        if (saleSubmissionInProgress) {
        event.preventDefault();
        return;
    }

    // MULTI-ITEM CART CHECKOUT
    if (checkoutMode === "cart") {

        if (cart.size === 0) {
            event.preventDefault();
            alert("Your shopping cart is empty.");
            return;
        }

        cartDataInput.value = JSON.stringify(
            Array.from(cart.values(), item => ({
                id: Number(item.id),
                quantity: item.quantity
            }))
        );

        checkoutModeInput.value = "cart";

        saleSubmissionInProgress = true;

        cartConfirmSubmit.disabled = true;
        cartConfirmSubmit.textContent = "Processing...";

        return;

    }

    // SINGLE-ITEM BUY ITEM CHECKOUT
    if (
        checkoutMode !== "single" ||
        !currentCheckoutItem ||
        checkoutModal.hidden
    ) {
        event.preventDefault();
        alert("Please select Buy Item or checkout your cart.");
        return;
    }

    const quantity = Number(checkoutQuantity.value);

    if (
        !Number.isSafeInteger(quantity) ||
        quantity < 1 ||
        quantity > currentCheckoutItem.stock
    ) {
        event.preventDefault();

        alert(
            "Please enter a quantity between 1 and " +
            currentCheckoutItem.stock + "."
        );

        checkoutQuantity.focus();
        return;
    }

    if (productSelect) {
        productSelect.value = currentCheckoutItem.id;
    }

    if (quantityInput) {
        quantityInput.value = String(quantity);
    }

cartDataInput.value = JSON.stringify([{
    id: Number(currentCheckoutItem.id),
    quantity: quantity
}]);

checkoutModeInput.value = "single";

saleSubmissionInProgress = true;

completeSaleButton.disabled = true;
completeSaleButton.textContent = "Processing...";
});

/* Live Product Search */
const productSearch = document.getElementById("pos-product-search");
const noProductsMessage = document.getElementById("pos-no-products");
const clearSearchButton = document.getElementById("pos-clear-search");

if (productSearch) {
    productSearch.addEventListener("input", function () {
        const searchTerm = this.value.trim().toLowerCase();
        let visibleProducts = 0;

        document.querySelectorAll(".pos-product-card").forEach(card => {
            const nameElement = card.querySelector(".pos-product-name");
            const productName = nameElement
                ? nameElement.textContent.trim().toLowerCase()
                : "";

            const matches = productName.includes(searchTerm);

            card.hidden = !matches;

            if (matches) {
                visibleProducts++;
            }
        });

        if (noProductsMessage) {
            noProductsMessage.hidden = visibleProducts > 0;
        }
    });
}

/* Clear Product Search */
if (productSearch && clearSearchButton) {
    clearSearchButton.addEventListener("click", () => {
        productSearch.value = "";
        productSearch.dispatchEvent(new Event("input"));
        productSearch.focus();
    });
}

/* Clear saved cart only after a successful cart checkout */
const successMessage = document.querySelector(".success-message");

if (successMessage?.dataset.clearPosCart === "1") {
    cart.clear();
    localStorage.removeItem(CART_STORAGE_KEY);
}

/* Initial cart-icon states */
renderCartStatus();

</script>

</body>

</html>