<?php

require_once "auth.php";
include "db.php";

// Get the one-time success message
$success_message = $_SESSION["success_message"] ?? "";
unset($_SESSION["success_message"]);

 // Get the search keyword from the URL
$search = trim($_GET["search"] ?? "");

// Prepare the search query
$sql = "SELECT * FROM products
        WHERE product_name LIKE ?
        ORDER BY id ASC";

$stmt = mysqli_prepare($conn, $sql);

if (!$stmt) {
    error_log("Products query preparation failed: " . mysqli_error($conn));
    die("Unable to load products. Please try again.");
}

// Add wildcards so partial names can match
$search_term = "%" . $search . "%";

mysqli_stmt_bind_param($stmt, "s", $search_term);

if (!mysqli_stmt_execute($stmt)) {
    error_log("Products query execution failed: " . mysqli_stmt_error($stmt));
    die("Unable to load products. Please try again.");
}

$result = mysqli_stmt_get_result($stmt);

if (!$result) {
    error_log("Unable to retrieve product results.");
    die("Unable to load products. Please try again.");
}

$total_products = mysqli_num_rows($result);

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <link rel="stylesheet" href="style.css">

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Products</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, Helvetica, sans-serif;
            background-color: #f3f6fc;
            color: #1e293b;
        }

        /* Navigation */

        nav a:focus-visible,
        .btn:focus-visible,
        button:focus-visible {
        outline: 3px solid #93c5fd;
        outline-offset: 3px;
        }

        /* Main content */
        main {
            max-width: 1200px;
            margin: 40px auto;
            padding: 0 20px;
        }

        .page-heading {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            flex-wrap: wrap;
            margin-bottom: 25px;
        }

        .page-heading h1 {
            margin: 0 0 8px;
            color: #0f172a;
            font-size: 30px;
        }

        .page-heading p {
            margin: 0;
            color: #64748b;
            line-height: 1.6;
        }

        /* Buttons */
        .btn {
            display: inline-block;
            padding: 11px 16px;
            border: none;
            border-radius: 8px;
            text-decoration: none;
            font-size: 14px;
            font-weight: bold;
            cursor: pointer;
            transition: background-color 0.2s;
        }

        .btn-primary {
            background-color: #2563eb;
            color: #ffffff;
        }

        .btn-primary:hover {
            background-color: #1d4ed8;
        }

        .btn-edit {
            background-color: #dbeafe;
            color: #1d4ed8;
        }

        .btn-edit:hover {
            background-color: #bfdbfe;
        }

        .btn-delete {
            background-color: #fee2e2;
            color: #b91c1c;
        }

        .btn-delete:hover {
            background-color: #fecaca;
        }

        /* Success message */
        .success-message {
            background-color: #dcfce7;
            color: #166534;
            border: 1px solid #bbf7d0;
            padding: 14px 16px;
            margin-bottom: 22px;
            border-radius: 8px;
            font-size: 14px;
        }

        /* Product search */
        .search-form {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .search-input {
            flex: 0 1 1010px;
            min-width: 0;
            width: 1010px;
            max-width: 100%;
            box-sizing: border-box;
            padding: 14px 18px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 14px;
        }

        .search-input:focus {
            outline: 3px solid #bfdbfe;
            border-color: #2563eb;
        }

        .btn-secondary {
            background-color: #e2e8f0;
            color: #334155;
        }

        .btn-secondary:hover {
            background-color: #cbd5e1;
        }


        /* Product summary */
        .summary-card {
            background-color: #ffffff;
            padding: 22px 25px;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            margin-bottom: 25px;
            box-shadow: 0 5px 20px rgba(15, 23, 42, 0.04);
        }

        .summary-label {
            display: block;
            color: #64748b;
            font-size: 14px;
            margin-bottom: 8px;
        }

        .summary-number {
            color: #1d4ed8;
            font-size: 28px;
            font-weight: bold;
        }

        /* Table */
        .table-card {
            background-color: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 5px 20px rgba(15, 23, 42, 0.04);
        }

        .table-heading {
            padding: 22px 24px;
            border-bottom: 1px solid #e2e8f0;
        }

        .table-heading h2 {
            margin: 0;
            color: #0f172a;
            font-size: 19px;
        }

        .table-responsive {
            width: 100%;
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
        }

        thead {
            background-color: #f8fafc;
        }

        th {
            padding: 15px 20px;
            color: #475569;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }

        td {
            padding: 16px 20px;
            border-top: 1px solid #e2e8f0;
            font-size: 14px;
            color: #334155;
            vertical-align: middle;
        }

        tbody tr:hover {
            background-color: #f8fafc;
        }

        .product-id {
            color: #64748b;
            font-weight: bold;
        }

        .product-name {
            color: #0f172a;
            font-weight: bold;
        }

        .price {
            font-weight: bold;
            color: #15803d;
            white-space: nowrap;
        }

        /* Stock status labels */
        .stock-badge {
            display: inline-block;
            padding: 7px 11px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: bold;
            white-space: nowrap;
            border: 1px solid transparent;
        }

        .stock-available {
            background-color: #dcfce7;
            color: #166534;
            border-color: #bbf7d0;
        }

        .stock-low {
            background-color: #fef3c7;
            color: #92400e;
            border-color: #fde68a;
        }

        .stock-empty {
            background-color: #fee2e2;
            color: #991b1b;
            border-color: #fecaca;
        }       

        /* Action buttons */
        .actions {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .actions form {
            margin: 0;
        }

        .actions form {
            display: inline-flex;
            align-items: center;
            margin: 0;
            padding: 0;
            background: transparent;
            border: none;
            border-radius: 0;
            box-shadow: none;
        }

        .empty-state {
            padding: 40px 20px;
            text-align: center;
            color: #64748b;
        }

        @media (max-width: 600px) {
            main {
                margin: 25px auto;
                padding: 0 15px;
            }

            .page-heading h1 {
                font-size: 25px;
            }

            .page-heading .btn {
                width: 100%;
                text-align: center;
            }

            .summary-card {
                padding: 20px;
            }

            th,
            td {
                padding: 13px 15px;
            }
        }

        /* Widen Inventory Records table */
.table-card {
    width: 100%;
    max-width: none;
    margin: 0;
}

.table-responsive {
    width: 100%;
    overflow-x: auto;
}

.table-card table {
    width: 100%;
    min-width: 1000px;
    table-layout: auto;
}

.table-card th,
.table-card td {
    padding: 16px 22px;
}



/* =========================================
   PREMIUM INVENTORY WORKSPACE
   Scoped to products.php
========================================= */

.inventory-page {
    width: calc(100% - 48px);
    max-width: 1240px;
    margin: 32px auto;
    padding: 0;
    color: #172033;
}

/* Page header */

.inventory-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 24px;
    flex-wrap: wrap;
    margin-bottom: 28px;
}

.inventory-title {
    flex: 1;
    min-width: 250px;
}

.inventory-eyebrow {
    display: block;
    margin-bottom: 9px;
    color: #5274b9;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 1.8px;
}

.inventory-title h1 {
    margin: 0 0 9px;
    color: #14213d;
    font-size: 32px;
    font-weight: 800;
    letter-spacing: -0.8px;
}

.inventory-title p {
    margin: 0;
    color: #738099;
    font-size: 14px;
    line-height: 1.7;
}

.inventory-add-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 9px;
    min-height: 46px;
    padding: 0 21px;
    background: #244b91;
    border-radius: 9px;
    box-shadow: 0 5px 12px rgba(36, 75, 145, 0.16);
    white-space: nowrap;
}

.inventory-add-btn:hover {
    background: #193b78;
}

.plus-icon {
    font-size: 22px;
    font-weight: 400;
    line-height: 1;
}

/* Summary card */

.inventory-summary {
    display: flex;
    align-items: center;
    gap: 19px;
    width: 100%;
    margin-bottom: 30px;
    padding: 24px 27px;
    background: linear-gradient(115deg, #ffffff 0%, #f5f8ff 100%);
    border: 1px solid #e0e8f6;
    border-radius: 14px;
    box-shadow: 0 7px 24px rgba(24, 47, 89, 0.045);
}

.summary-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 58px;
    width: 58px;
    height: 58px;
    background: #e7efff;
    color: #244b91;
    border-radius: 13px;
    font-size: 30px;
}

.summary-details {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 5px;
}

.inventory-summary .summary-label {
    margin: 0;
    color: #697792;
    font-size: 13px;
    font-weight: 600;
}

.inventory-summary .summary-number {
    color: #172d55;
    font-size: 31px;
    font-weight: 800;
    line-height: 1.2;
}

.summary-description {
    color: #8994a9;
    font-size: 12px;
}

/* Inventory records card */

.inventory-table-card {
    width: 100%;
    max-width: none;
    margin: 0;
    overflow: hidden;
    background: #ffffff;
    border: 1px solid #e2e8f2;
    border-radius: 14px;
    box-shadow: 0 8px 30px rgba(24, 47, 89, 0.055);
}

/* Search and table toolbar */

.inventory-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 24px;
    flex-wrap: wrap;
    padding: 25px 27px;
    border-bottom: 1px solid #edf0f6;
}

.inventory-table-title {
    flex: 1;
    min-width: 210px;
}

.inventory-table-title h2 {
    margin: 0 0 7px;
    color: #172d55;
    font-size: 20px;
    font-weight: 750;
}

.inventory-table-title p {
    margin: 0;
    color: #8490a5;
    font-size: 13px;
}

.inventory-search-form {
    display: flex;
    align-items: center;
    gap: 10px;
    flex: 0 1 440px;
    min-width: 280px;
    margin: 0;
    padding: 0;
    background: transparent;
    border: none;
    border-radius: 0;
    box-shadow: none;
}

.inventory-search-box {
    display: flex;
    align-items: center;
    gap: 9px;
    flex: 1;
    min-width: 0;
    height: 44px;
    padding: 0 12px;
    background: #f8faff;
    border: 1px solid #dce4f0;
    border-radius: 8px;
    transition: border-color 0.2s, box-shadow 0.2s;
}

.inventory-search-box:focus-within {
    background: #ffffff;
    border-color: #6689cc;
    box-shadow: 0 0 0 3px rgba(76, 119, 194, 0.11);
}

.inventory-search-box .search-icon {
    color: #71809b;
    font-size: 23px;
    line-height: 1;
}

.inventory-search-box .search-input {
    flex: 1;
    width: 100%;
    min-width: 0;
    max-width: none;
    padding: 0;
    background: transparent;
    border: none;
    border-radius: 0;
    outline: none;
    box-shadow: none;
    color: #263754;
    font-size: 13px;
}

.inventory-search-box .search-input:focus {
    outline: none;
    border: none;
    box-shadow: none;
}

.inventory-search-box .search-input::placeholder {
    color: #9aa5b8;
}

.search-clear {
    color: #74819a;
    font-size: 22px;
    line-height: 1;
    text-decoration: none;
}

.search-clear:hover {
    color: #b42318;
}

.inventory-search-btn {
    min-height: 44px;
    padding: 0 19px;
    border-radius: 8px;
    white-space: nowrap;
}

/* Results count */

.inventory-table-meta {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 15px 27px;
    color: #7b879c;
    font-size: 12px;
    border-bottom: 1px solid #edf0f6;
}

.inventory-table-meta strong {
    color: #344765;
}

/* Table */

.inventory-table-card .table-responsive {
    width: 100%;
    overflow-x: auto;
}

.inventory-table-card table.inventory-table {
    width: 100%;
    min-width: 0;
    margin: 0;
    border-collapse: separate;
    border-spacing: 0;
    text-align: left;
}

.inventory-table thead {
    background: #f5f7fc;
}

.inventory-table th {
    padding: 14px 13px;
    background: #f5f7fc;
    color: #64728a;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 0.7px;
    text-transform: uppercase;
    white-space: nowrap;
    border-bottom: 1px solid #e5eaf3;
}

.inventory-table td {
    padding: 14px 13px;
    color: #40506a;
    font-size: 13px;
    vertical-align: middle;
    border-top: none;
    border-bottom: 1px solid #edf1f7;
}

.inventory-table tbody tr:last-child td {
    border-bottom: none;
}

.inventory-table tbody tr {
    transition: background-color 0.15s ease;
}

.inventory-table tbody tr:hover {
    background: #f7f9fe;
}

.inventory-table .product-id {
    color: #7e8ba1;
    font-size: 12px;
    font-weight: 700;
}

.inventory-table .product-name {
    color: #1d2d49;
    font-weight: 700;
}

.inventory-table .price {
    color: #18734a;
    font-weight: 750;
    white-space: nowrap;
}

.inventory-image-cell {
    width: 90px;
}

.inventory-product-image {
    display: block;
    width: 52px;
    height: 52px;
    object-fit: cover;
    border: 1px solid #e3e9f2;
    border-radius: 10px;
    background: #f7f9fc;
}

.no-product-image {
    color: #a0aabd;
    font-size: 11px;
    white-space: nowrap;
}

.inventory-stock-number {
    display: inline-block;
    min-width: 25px;
    color: #263754;
    font-weight: 700;
}

/* Stock status badges */

.inventory-table .stock-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 7px 10px;
    border: 1px solid transparent;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 750;
    white-space: nowrap;
}

.inventory-table .stock-available {
    color: #187348;
    background: #e7f7ee;
    border-color: #c9ecd8;
}

.inventory-table .stock-low {
    color: #97640b;
    background: #fff5d9;
    border-color: #f4e2a8;
}

.inventory-table .stock-empty {
    color: #b42332;
    background: #ffeaec;
    border-color: #f8cfd4;
}

/* Action buttons */

.inventory-actions {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: nowrap;
}

.inventory-actions .btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 34px;
    padding: 0 12px;
    border-radius: 7px;
    font-size: 12px;
    white-space: nowrap;
}

.inventory-actions form {
    display: inline-flex;
    align-items: center;
    margin: 0;
    padding: 0;
    background: transparent;
    border: none;
    border-radius: 0;
    box-shadow: none;
}

.inventory-actions .btn-edit {
    color: #2854a1;
    background: #eaf1ff;
}

.inventory-actions .btn-edit:hover {
    background: #d9e6ff;
}

.inventory-actions .btn-delete {
    color: #b42332;
    background: #ffedef;
}

.inventory-actions .btn-delete:hover {
    background: #ffdade;
}

/* Empty state */

.inventory-table .empty-state {
    padding: 55px 20px;
    color: #7b879c;
    font-size: 14px;
    text-align: center;
}

/* Responsive layout */

@media (max-width: 760px) {
    .inventory-page {
        width: calc(100% - 28px);
        margin: 25px auto;
    }

    .inventory-header {
        align-items: stretch;
        gap: 18px;
    }

    .inventory-title h1 {
        font-size: 27px;
    }

    .inventory-add-btn {
        width: 100%;
    }

    .inventory-summary {
        padding: 20px;
    }

    .inventory-toolbar {
        align-items: stretch;
        padding: 21px 18px;
    }

    .inventory-search-form {
        flex: 1 1 100%;
        min-width: 0;
    }

    .inventory-table-meta {
        padding: 14px 18px;
    }

    .inventory-table th,
    .inventory-table td {
        padding: 14px 16px;
    }
}

@media (max-width: 420px) {
    .inventory-search-form {
        flex-wrap: wrap;
    }

    .inventory-search-box {
        flex: 1 1 100%;
    }

    .inventory-search-btn {
        width: 100%;
    }

    .inventory-summary {
        gap: 13px;
    }

    .summary-icon {
        flex-basis: 48px;
        width: 48px;
        height: 48px;
    }
}


/* Adjust Inventory Records column spacing */
.inventory-table-card table.inventory-table {
    width: 100%;
    min-width: 0;
    table-layout: auto;
}

/* Reduce excessive horizontal spacing */
.inventory-table th,
.inventory-table td {
    padding-left: 12px;
    padding-right: 12px;
}

/* Give each column a balanced width */
.inventory-table th:nth-child(1),
.inventory-table td:nth-child(1) {
    width: 8%;
}

.inventory-table th:nth-child(2),
.inventory-table td:nth-child(2) {
    width: 17%;
}

.inventory-table th:nth-child(3),
.inventory-table td:nth-child(3) {
    width: 13%;
}

.inventory-table th:nth-child(4),
.inventory-table td:nth-child(4) {
    width: 14%;
}

.inventory-table th:nth-child(5),
.inventory-table td:nth-child(5) {
    width: 13%;
}

.inventory-table th:nth-child(6),
.inventory-table td:nth-child(6) {
    width: 16%;
}

.inventory-table th:nth-child(7),
.inventory-table td:nth-child(7) {
    width: 22%;
}


/* Compact inventory toolbar */
.inventory-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    flex-wrap: wrap;
    padding: 22px 24px;
}

.inventory-table-title {
    flex: 0 0 auto;
    min-width: 180px;
}

.inventory-toolbar-actions {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 12px;
    flex: 1;
    min-width: 0;
}

.inventory-search-form {
    display: flex;
    align-items: center;
    gap: 8px;
    flex: 1;
    min-width: 220px;
    max-width: 500px;
    margin: 0;
}

.inventory-add-btn {
    flex: 0 0 auto;
    min-height: 44px;
    white-space: nowrap;
}

@media (max-width: 850px) {
    .inventory-toolbar-actions {
        flex: 1 1 100%;
        justify-content: flex-start;
        flex-wrap: wrap;
    }

    .inventory-search-form {
        flex: 1 1 280px;
    }
}

@media (max-width: 480px) {
    .inventory-toolbar-actions {
        align-items: stretch;
    }

    .inventory-search-form {
        flex: 1 1 100%;
    }

    .inventory-add-btn {
        justify-content: center;
        width: 100%;
    }
}

    </style>
</head>

<body>

    <?php require_once "navbar.php"; ?>

<main class="inventory-page">

    <!-- Success Message -->
    <?php if ($success_message !== ""): ?>
        <div class="success-message" role="status">
            <?php
            echo htmlspecialchars(
                $success_message,
                ENT_QUOTES,
                "UTF-8"
            );
            ?>
        </div>
    <?php endif; ?>

    <!-- Inventory Records -->
    <section class="table-card inventory-table-card">

<div class="inventory-toolbar">
    <div class="inventory-table-title">
        <h2>Inventory Records</h2>
        <p>View and manage your product catalog.</p>
    </div>

    <div class="inventory-toolbar-actions">
        <form action="products.php" method="GET" class="inventory-search-form">
            <div class="inventory-search-box">
                <span class="search-icon">⌕</span>

                <input
                    type="text"
                    name="search"
                    class="search-input"
                    placeholder="Search product name..."
                    value="<?php echo htmlspecialchars($search, ENT_QUOTES, "UTF-8"); ?>"
                    aria-label="Search product name"
                >

                <?php if ($search !== ""): ?>
                    <a href="products.php" class="search-clear" aria-label="Clear search">×</a>
                <?php endif; ?>
            </div>

            <button type="submit" class="btn btn-primary inventory-search-btn">
                Search
            </button>
        </form>

        <a href="add_product.php" class="btn btn-primary inventory-add-btn">
            <span class="plus-icon">+</span> Add Product
        </a>
    </div>
</div>

        <div class="inventory-table-meta">
            <span>
                Showing <strong><?php echo number_format($total_products); ?></strong>
                <?php echo $total_products === 1 ? "product" : "products"; ?>
            </span>
        </div>

        <div class="table-responsive">
            <table class="inventory-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Product</th>
                        <th>Image</th>
                        <th>Price</th>
                        <th>Stock</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if ($total_products > 0): ?>

                        <?php $display_number = 1; ?>

                        <?php while ($row = mysqli_fetch_assoc($result)): ?>

                            <?php
                            $stock = (int) $row["stock"];

                            if ($stock <= 0) {
                                $stock_status = "Out of Stock";
                                $stock_class = "stock-empty";
                            } elseif ($stock < 10) {
                                $stock_status = "Low Stock";
                                $stock_class = "stock-low";
                            } else {
                                $stock_status = "In Stock";
                                $stock_class = "stock-available";
                            }

                            $image_name = trim((string) ($row["image"] ?? ""));

                            if ($image_name !== "") {
                                $image_name = "uploads/" . basename($image_name);
                            }
                            ?>

                            <tr>
                                <td class="product-id">
                                    #<?php echo $display_number++; ?>
                                </td>

                                <td class="product-name">
                                    <?php
                                    echo htmlspecialchars(
                                        $row["product_name"],
                                        ENT_QUOTES,
                                        "UTF-8"
                                    );
                                    ?>
                                </td>

                                <td class="inventory-image-cell">
                                    <?php if ($image_name !== ""): ?>
                                        <img
                                            class="inventory-product-image"
                                            src="<?php echo htmlspecialchars($image_name, ENT_QUOTES, "UTF-8"); ?>"
                                            alt="<?php echo htmlspecialchars($row["product_name"], ENT_QUOTES, "UTF-8"); ?>"
                                        >
                                    <?php else: ?>
                                        <span class="no-product-image">No image</span>
                                    <?php endif; ?>
                                </td>

                                <td class="price">
                                    ₱<?php echo number_format((float) $row["price"], 2); ?>
                                </td>

                                <td>
                                    <span class="inventory-stock-number">
                                        <?php echo $stock; ?>
                                    </span>
                                </td>

                                <td>
                                    <span class="stock-badge <?php echo $stock_class; ?>">
                                        <?php echo $stock_status; ?>
                                    </span>
                                </td>

                                <td>
                                    <div class="actions inventory-actions">
                                        <a
                                            href="edit_product.php?id=<?php echo (int) $row["id"]; ?>"
                                            class="btn btn-edit"
                                        >Edit</a>

                                        <form
                                            action="delete_product.php"
                                            method="POST"
                                            onsubmit="return confirm('Are you sure you want to delete this product?');"
                                        >
                                            <input
                                                type="hidden"
                                                name="id"
                                                value="<?php echo (int) $row["id"]; ?>"
                                            >

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

                                            <button
                                                type="submit"
                                                class="btn btn-delete"
                                            >Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>

                        <?php endwhile; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="7" class="empty-state">
                                <?php if ($search !== ""): ?>
                                    No products match your search. Try another product name.
                                <?php else: ?>
                                    No products found. Click Add Product to create your first product.
                                <?php endif; ?>
                            </td>
                        </tr>

                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </section>

</main>

</body>
</html>