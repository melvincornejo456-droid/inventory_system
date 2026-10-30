<?php

require_once "auth.php";
include "db.php";

$sql = "SELECT COUNT(*) AS total_products FROM products";

$result = mysqli_query($conn, $sql);

if ($result === false) {
    error_log("Dashboard query failed: " . mysqli_error($conn));
    exit("Sorry, the dashboard is temporarily unavailable. Please try again later.");
}

$row = mysqli_fetch_assoc($result);

$total_products = $row["total_products"];

$sql_sales = "SELECT
                  COALESCE(SUM(total), 0) AS total_revenue,
                  DATE_FORMAT(CURDATE(), '%M %Y') AS current_month_label
              FROM sales
              WHERE created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
                AND created_at < DATE_FORMAT(CURDATE(), '%Y-%m-01') + INTERVAL 1 MONTH";

$result_sales = mysqli_query($conn, $sql_sales);

if ($result_sales === false) {
    error_log("Sales revenue query failed: " . mysqli_error($conn));
    exit("Sorry, the dashboard is temporarily unavailable. Please try again later.");
}

$row_sales = mysqli_fetch_assoc($result_sales);

$total_revenue = (float) $row_sales["total_revenue"];
$current_month_label = $row_sales["current_month_label"];

$sql_count = "SELECT COUNT(*) AS total_sales
              FROM sales
              WHERE created_at >= CURDATE()
              AND created_at < CURDATE() + INTERVAL 1 DAY";

$result_count = mysqli_query($conn, $sql_count);

if ($result_count === false) {
    error_log("Today's sales count query failed: " . mysqli_error($conn));
    exit("Sorry, the dashboard is temporarily unavailable. Please try again later.");
}

$row_count = mysqli_fetch_assoc($result_count);

$total_sales = $row_count["total_sales"];

$sql_low_stock = "SELECT COUNT(*) AS low_stock_products
                  FROM products
                  WHERE stock < 10";

$result_low_stock = mysqli_query($conn, $sql_low_stock);

if ($result_low_stock === false) {
    error_log("Low-stock count query failed: " . mysqli_error($conn));
    exit("Sorry, the dashboard is temporarily unavailable. Please try again later.");
}

$row_low_stock = mysqli_fetch_assoc($result_low_stock);

$low_stock_products = $row_low_stock["low_stock_products"];

$sql_low_stock_list = "SELECT product_name, stock
                       FROM products    
                       WHERE stock < 10
                       ORDER BY stock ASC";

$result_low_stock_list = mysqli_query(
    $conn,
    $sql_low_stock_list
);

if ($result_low_stock_list === false) {
    error_log("Low-stock list query failed: " . mysqli_error($conn));
    exit("Sorry, the dashboard is temporarily unavailable. Please try again later.");
}

$sql_recent_sales = "SELECT
                        sales.id,
                        products.product_name,
                        sales.quantity,
                        sales.total,
                        sales.created_at
                     FROM sales
                     INNER JOIN products
                        ON sales.product_id = products.id
                     WHERE sales.created_at >= CURDATE()
                       AND sales.created_at < CURDATE() + INTERVAL 1 DAY
                     ORDER BY sales.created_at DESC
                     LIMIT 5";

$result_recent_sales = mysqli_query($conn, $sql_recent_sales);

$allowed_per_page = [5, 10, 25, 50, 100];

$per_page = filter_input(INPUT_GET, "per_page", FILTER_VALIDATE_INT);

if ($per_page === false || $per_page === null || !in_array($per_page, $allowed_per_page, true)) {
    $per_page = 5;
}

$current_page = filter_input(INPUT_GET, "page", FILTER_VALIDATE_INT);

if ($current_page === false || $current_page === null || $current_page < 1) {
    $current_page = 1;
}

// Count today's sales records for pagination
$sql_recent_sales_count = "SELECT COUNT(*) AS total
                           FROM sales
                           WHERE created_at >= CURDATE()
                             AND created_at < CURDATE() + INTERVAL 1 DAY";

$result_recent_sales_count = mysqli_query(
    $conn,
    $sql_recent_sales_count
);

if ($result_recent_sales_count === false) {
    error_log("Recent sales count query failed: " . mysqli_error($conn));
    exit("Sorry, the dashboard is temporarily unavailable. Please try again later.");
}

$row_recent_sales_count = mysqli_fetch_assoc($result_recent_sales_count);

$total_recent_sales = (int) $row_recent_sales_count["total"];

$total_pages = max(1, (int) ceil($total_recent_sales / $per_page));

// Keep the requested page within the available range
$current_page = min($current_page, $total_pages);

$offset = ($current_page - 1) * $per_page;

// Fetch only the rows needed for this page
$sql_recent_sales = "SELECT
                        sales.id,
                        products.product_name,
                        sales.quantity,
                        sales.total,
                        sales.created_at
                     FROM sales
                     INNER JOIN products
                        ON sales.product_id = products.id
                     WHERE sales.created_at >= CURDATE()
                       AND sales.created_at < CURDATE() + INTERVAL 1 DAY
                     ORDER BY sales.created_at DESC, sales.id DESC
                     LIMIT ? OFFSET ?";

$stmt_recent_sales = mysqli_prepare($conn, $sql_recent_sales);

if ($stmt_recent_sales === false) {
    error_log("Recent sales prepare failed: " . mysqli_error($conn));
    exit("Sorry, the dashboard is temporarily unavailable. Please try again later.");
}

mysqli_stmt_bind_param($stmt_recent_sales, "ii", $per_page, $offset);
mysqli_stmt_execute($stmt_recent_sales);

$result_recent_sales = mysqli_stmt_get_result($stmt_recent_sales);

if ($result_recent_sales === false) {
    error_log("Recent sales query failed.");
    exit("Sorry, the dashboard is temporarily unavailable. Please try again later.");
}


if ($result_recent_sales === false) {
    error_log("Today's sales query failed: " . mysqli_error($conn));
    exit("Sorry, the dashboard is temporarily unavailable. Please try again later.");
}


?>

<!DOCTYPE html>
<html lang="en">

<head>

    <link rel="stylesheet" href="style.css">

    <style>
body {
    font-family: Arial, sans-serif;
    background-color: #0F172A;
    margin: 0;
    padding: 0;
    color: #E2E8F0;
}

        .dashboard-content {
            padding: 30px;
        }

        h1 {
            color: #1D4ED8;
            font-size: 32px;
        }

        h2 {
            font-size: 18px;
            color: #64748B;
        }

        .dashboard-cards {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 20px;
            width: 100%;
            max-width: 1200px;
            margin: 25px 0;
        }

.card {
    background-color: #1E293B;
    padding: 24px;
    border-radius: 14px;
    border: 1px solid #334155;
    border-top: 3px solid #3B82F6;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.16);
    box-sizing: border-box;
    transition: transform 0.2s, box-shadow 0.2s;
}

.card:hover {
    transform: translateY(-3px);
    box-shadow: 0 12px 28px rgba(0, 0, 0, 0.25);
    border-color: #475569;
}   

.card h2 {
    margin-top: 0;
    margin-bottom: 18px;
    color: #94A3B8;
    font-size: 14px;
    line-height: 1.5;
    font-weight: 600;
}  

.card p {
    font-size: 32px;
    font-weight: bold;
    color: #F8FAFC;
    margin: 0;
    overflow-wrap: anywhere;
    letter-spacing: 0.5px;
}     

        @media (max-width: 1000px) {
            .dashboard-cards {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
        }

        @media (max-width: 600px) {
            .dashboard-content {
            padding: 15px;
        }

        .dashboard-cards {
            grid-template-columns: 1fr;
        }

        .card {
            padding: 20px;
        }
        }

.low-stock-table {
    width: 100%;
    border-collapse: collapse;
    background-color: #1E293B;
    color: #E2E8F0;
}

.low-stock-table th,
.low-stock-table td {
    padding: 14px 16px;
    text-align: left;
    border-bottom: 1px solid #334155;
}

.low-stock-table th {
    background-color: #263449;
    color: #93C5FD;
    font-size: 13px;
    font-weight: 700;
    letter-spacing: 0.3px;
}

.low-stock-table td {
    color: #CBD5E1;
    font-size: 14px;
}

.low-stock-table tbody tr:hover {
    background-color: #263449;
}

        .low-stock-label {
        background-color: #FEE2E2;
        color: #B91C1C;
        padding: 5px 10px;
        border-radius: 6px;
        font-weight: bold;
        font-size: 13px;
        }

.admin-profile {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 20px;
    padding: 24px;
    margin: 20px 0;
    background: #1E293B;
    border: 1px solid #334155;
    border-radius: 14px;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.16);
}

        .admin-info {
        display: flex;
        align-items: center;
        gap: 15px;
        }

.admin-avatar {
    width: 50px;
    height: 50px;
    flex-shrink: 0;
    border-radius: 14px;
    background: #2563EB;
    color: #FFFFFF;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    font-weight: bold;
    box-shadow: 0 4px 12px rgba(37, 99, 235, 0.25);
}

.admin-info h3 {
    margin: 0 0 6px;
    color: #F8FAFC;
    font-size: 18px;
    font-weight: 700;
}

.admin-info p {
    margin: 0;
    color: #94A3B8;
    font-size: 14px;
    line-height: 1.6;
}

/* Full-color dashboard summary cards */
.products-card {
    background: linear-gradient(135deg, #2563EB, #1D4ED8);
    border: 1px solid #3B82F6;
    border-top: 3px solid #60A5FA;
}

.revenue-card {
    background: linear-gradient(135deg, #059669, #047857);
    border: 1px solid #10B981;
    border-top: 3px solid #34D399;
}

.sales-card {
    background: linear-gradient(135deg, #7C3AED, #5B21B6);
    border: 1px solid #8B5CF6;
    border-top: 3px solid #A78BFA;
}

.low-stock-card {
    background: linear-gradient(135deg, #D97706, #B45309);
    border: 1px solid #F59E0B;
    border-top: 3px solid #FBBF24;
}

/* Keep all card text readable */
.products-card h2,
.revenue-card h2,
.sales-card h2,
.low-stock-card h2 {
    color: #FFFFFF;
}

.products-card p,
.revenue-card p,
.sales-card p,
.low-stock-card p {
    color: #FFFFFF;
}

        .stock-warning {
        color: #EA580C !important;
        }

        .stock-good {
        color: #16A34A !important;
        }

        .low-stock-card small {
        display: block;
        margin-top: 12px;
        color: #64748B;
        line-height: 1.5;
        }

        .stock-empty {
        display: inline-block;
        background-color: #fee2e2;
        color: #991b1b;
        padding: 5px 10px;
        border-radius: 6px;
        font-weight: bold;
        font-size: 13px;
        }

        .table-responsive {
        width: 100%;
        max-width: 100%;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        margin-bottom: 25px;
        }

        .table-responsive table {
        width: 100%;
        min-width: 600px;
        border-collapse: collapse;
        }

        .dashboard-toggle {
        cursor: pointer;
        }

        .dashboard-toggle:focus-visible {
        outline: 3px solid #1D4ED8;
        outline-offset: 3px;
        }


.recent-sales-controls {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
    margin: 16px 0;
}

.sales-filter-form {
    display: flex;
    align-items: center;
    gap: 10px;
    visibility: visible;
    opacity: 1;
}

.sales-filter-form label {
    display: inline-block;
    color: #64748B;
    font-size: 14px;
    visibility: visible;
}

.sales-filter-form select {
    display: block;
    width: 100px;
    min-width: 100px;
    height: 38px;
    padding: 6px 10px;
    border: 1px solid #CBD5E1;
    border-radius: 6px;
    background-color: #FFFFFF;
    color: #1E293B;
    font-size: 14px;
    cursor: pointer;
    appearance: auto;
    -webkit-appearance: menulist;
}

@media (max-width: 600px) {
    .recent-sales-controls {
        align-items: flex-start;
        flex-direction: column;
    }
}

.sales-pagination {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 14px;
    margin: 18px 0 25px;
}

.pagination-buttons {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
}

.pagination-buttons a {
    display: inline-block;
    padding: 8px 12px;
    border: 1px solid #CBD5E1;
    border-radius: 6px;
    color: #1D4ED8;
    background: #FFFFFF;
    text-decoration: none;
    font-size: 14px;
}

.pagination-ellipsis {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 24px;
    padding: 8px 4px;
    color: #64748B;
    font-size: 14px;
    user-select: none;
}

.pagination-buttons a:hover {
    background: #EFF6FF;
}

.pagination-buttons a.active {
    background: #1D4ED8;
    color: #FFFFFF;
    border-color: #1D4ED8;
}

.pagination-buttons a.disabled {
    color: #94A3B8;
    background: #F1F5F9;
    pointer-events: none;
}

@media (max-width: 600px) {
    .sales-pagination {
        align-items: flex-start;
        flex-direction: column;
    }
}

#low-stock-section,
#recent-sales-section {
    background-color: #1E293B;
    border: 1px solid #334155;
    border-radius: 14px;
    padding: 24px;
    margin: 24px 0;
    color: #E2E8F0;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.16);
}

#low-stock-section h2,
#recent-sales-section h2 {
    color: #F8FAFC;
    font-size: 20px;
    margin-top: 0;
    margin-bottom: 18px;
}

@media (max-width: 600px) {
    #low-stock-section,
    #recent-sales-section {
        padding: 16px;
    }
}

#low-stock-section[hidden],
#recent-sales-section[hidden] {
    display: none !important;
}


/* Dashboard text visibility fix */

.dashboard-content {
    color: #E2E8F0;
}

.dashboard-content h1 {
    color: #F8FAFC;
}

.dashboard-content h2 {
    color: #CBD5E1;
}

.dashboard-content p,
.dashboard-content label,
.dashboard-content .sales-entry-info {
    color: #CBD5E1;
}

/* Recent sales dropdown */
.sales-filter-form label {
    color: #CBD5E1;
}

.sales-filter-form select {
    background-color: #0F172A;
    color: #F8FAFC;
    border: 1px solid #475569;
}

/* Pagination buttons */
.pagination-buttons a {
    color: #93C5FD;
    background-color: #0F172A;
    border-color: #475569;
}

.pagination-buttons a:hover {
    color: #FFFFFF;
    background-color: #334155;
}

.pagination-buttons a.active {
    color: #FFFFFF;
    background-color: #2563EB;
    border-color: #2563EB;
}

.pagination-buttons a.disabled {
    color: #64748B;
    background-color: #1E293B;
    border-color: #334155;
}

/* Keyboard accessibility */
.dashboard-toggle:focus-visible {
    outline: 3px solid #60A5FA;
    outline-offset: 3px;
}


/* Recent Sales table visibility fix */
#recent-sales-section {
    color: #E2E8F0;
}

#recent-sales-section h2 {
    color: #F8FAFC !important;
}

#recent-sales-section label {
    color: #CBD5E1 !important;
}

#recent-sales-section table {
    width: 100%;
    border-collapse: collapse;
    background-color: #1E293B;
    color: #E2E8F0;
}

#recent-sales-section th {
    background-color: #263449;
    color: #93C5FD !important;
    padding: 14px 16px;
    border-bottom: 1px solid #475569;
    text-align: left;
}

#recent-sales-section td {
    background-color: #1E293B;
    color: #E2E8F0 !important;
    padding: 14px 16px;
    border-bottom: 1px solid #334155;
}

#recent-sales-section tbody tr:hover td {
    background-color: #263449;
    color: #FFFFFF !important;
}

/* Show entries dropdown */
#recent-sales-section select {
    background-color: #0F172A !important;
    color: #F8FAFC !important;
    border: 1px solid #475569;
    padding: 8px 10px;
    border-radius: 6px;
}

/* Date and sales information */
#recent-sales-section .sales-entry-info {
    color: #CBD5E1 !important;
}


/* Low Stock table visibility fix */
#low-stock-section {
    background-color: #1E293B;
    color: #E2E8F0;
}

#low-stock-section h2 {
    color: #F8FAFC !important;
}

#low-stock-section p,
#low-stock-section label {
    color: #CBD5E1 !important;
}

#low-stock-section table {
    width: 100%;
    border-collapse: collapse;
    background-color: #1E293B;
    color: #E2E8F0;
}

#low-stock-section th {
    background-color: #263449;
    color: #93C5FD !important;
    padding: 14px 16px;
    border-bottom: 1px solid #475569;
    text-align: left;
}

#low-stock-section td {
    background-color: #1E293B;
    color: #E2E8F0 !important;
    padding: 14px 16px;
    border-bottom: 1px solid #334155;
}

#low-stock-section tbody tr:hover td {
    background-color: #263449;
    color: #FFFFFF !important;
}


/* Dark theme for Show Entries dropdowns */
#recent-sales-section select,
#low-stock-section select {
    background-color: #0F172A !important;
    color: #F8FAFC !important;
    border: 1px solid #475569 !important;
    border-radius: 6px;
    padding: 8px 12px;
    color-scheme: dark;
}

#recent-sales-section select option,
#low-stock-section select option {
    background-color: #1E293B;
    color: #F8FAFC;
}


/* Fix the white Show Entries container */
#recent-sales-section .sales-filter-form,
#low-stock-section .sales-filter-form {
    background-color: #1E293B !important;
    color: #CBD5E1 !important;
}

#recent-sales-section .sales-filter-form *,
#low-stock-section .sales-filter-form * {
    color: #CBD5E1;
}

#recent-sales-section .sales-filter-form select,
#low-stock-section .sales-filter-form select {
    background-color: #0F172A !important;
    color: #F8FAFC !important;
    border: 1px solid #475569 !important;
}

/* Improve Low Stock card text visibility */
.low-stock-card h2 {
    color: #FFFFFF !important;
}

.low-stock-card p {
    color: #FFFFFF !important;
    font-weight: 800;
    text-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
}

.low-stock-card .card-description,
.low-stock-card .attention,
.low-stock-card small,
.low-stock-card span {
    color: #FFFFFF !important;
}

.navbar {
    display: flex;
    align-items: center;
    flex-wrap: nowrap;
    gap: 8px;
    width: 100%;
    box-sizing: border-box;
}

.navbar-brand {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 3px;
    margin-right: 12px;
    flex-shrink: 0;
}

.navbar-logo {
    width: 50px;
    height: 45px;
    object-fit: contain;
    border-radius: 6px;
}

.navbar-business-name {
    color: #FFFFFF;
    font-size: 11px;
    font-weight: 700;
    line-height: 1.2;
    text-align: center;
    white-space: nowrap;
}


/* Fix navbar logo size on every page */
.navbar .navbar-brand img.navbar-logo {
    display: block !important;
    width: 45px !important;
    height: 45px !important;
    min-width: 45px !important;
    min-height: 45px !important;
    max-width: 45px !important;
    max-height: 45px !important;
    object-fit: contain !important;
    position: static !important;
    transform: none !important;
    flex-shrink: 0 !important;
}

    </style>
</head>

<body>

    <?php require_once "navbar.php"; ?>

    <main class="dashboard-content">

    <h1>Inventory & Sales Dashboard</h1>

    <div class="admin-profile">

        <div class="admin-info">
            <div class="admin-avatar">
                <?php
                    echo strtoupper(
                    substr($_SESSION["username"], 0, 1)
                    );
                ?>
        </div>

            <div>
                <h3>Administrator</h3>

                <p>
                    Welcome back,
                    <?php
                        echo htmlspecialchars(
                        $_SESSION["username"],
                        ENT_QUOTES,
                        "UTF-8"
                        );
                    ?>!
                </p>
            </div>
        </div>

    </div>

    <div class="dashboard-cards">

    <div class="card products-card">

    <h2>📦 Total Products</h2>
        <p><?php echo $total_products; ?></p>
    </div>

    <div class="card revenue-card">
        <h2>💰 Total Sales Revenue</h2>
            <p>₱<?php echo number_format($total_revenue, 2); ?></p>
            <small>
                <?php echo htmlspecialchars($current_month_label, ENT_QUOTES, "UTF-8"); ?> Revenue
            </small>
    </div>

<div class="card sales-card dashboard-toggle"
     role="button"
     tabindex="0"
     aria-expanded="false"
     onclick="toggleSection('recent-sales-section', this)"
     onkeydown="if (event.key === 'Enter' || event.key === ' ') {
         event.preventDefault();
         toggleSection('recent-sales-section', this);
     }">

    <h2>🛒 Total Number of Sales</h2>

    <p><?php echo (int) $total_sales; ?></p>

    <small>Click to view recent sales.</small>

</div>

    <div class="card low-stock-card dashboard-toggle"
        role="button"
        tabindex="0"
        onclick="toggleSection('low-stock-section', this)"
        onkeydown="if (event.key === 'Enter' || event.key === ' ') {
         event.preventDefault();
         toggleSection('low-stock-section', this);
        }">

        <h2>⚠️ Low Stock Products</h2>

        <p class="<?php echo ($low_stock_products > 0) ? 'stock-warning' : 'stock-good'; ?>">
            <?php echo (int) $low_stock_products; ?>
        </p>

        <small>
            <?php
            if ($low_stock_products > 0) {
                echo "Attention: Some products need restocking.";
            } else {
                echo "Great! All products have sufficient stock.";
            }
            ?>
        </small>

    </div>

    </div>

    <section id="low-stock-section" hidden>

            <h2>Low Stock Products</h2>

            <table class="low-stock-table">

        <tr>
            <th>Product Name</th>
            <th>Remaining Stock</th>
            <th>Status</th>
        </tr>

        <?php
            if (mysqli_num_rows($result_low_stock_list) > 0) {

            while ($product = mysqli_fetch_assoc($result_low_stock_list)) {
        ?>

        <tr>
            <td>
                <?php echo htmlspecialchars($product["product_name"]); ?>
            </td>

            <td>
                <?php echo (int) $product["stock"]; ?>
            </td>

            <td>
                <?php if ((int) $product["stock"] === 0): ?>
                    <span class="stock-empty">Out of Stock</span>
                <?php else: ?>
                    <span class="low-stock-label">Low Stock</span>
                <?php endif; ?>
            </td>
        </tr>

    <?php
        }

    } else {
    ?>

        <tr>
            <td colspan="3">
                Great! No low-stock products right now.
            </td>
        </tr>

    <?php } ?>

</table>

</section>


<section id="recent-sales-section" hidden>

<h2>Recent Sales</h2>

<div class="recent-sales-controls">

    <form method="GET" action="dashboard.php" class="sales-filter-form">

        <input type="hidden" name="page" value="1">
        <input type="hidden" name="show_recent" value="1">

        <label for="per_page">Show entries:</label>

        <select
            name="per_page"
            id="per_page"
            onchange="this.form.submit()"
        >
            <?php foreach ([5, 10, 25, 50, 100] as $option): ?>
                <option
                    value="<?php echo $option; ?>"
                    <?php echo ($per_page === $option) ? "selected" : ""; ?>
                >
                    <?php echo $option; ?>
                </option>
            <?php endforeach; ?>
        </select>
        
    </form>

</div>

<div class="table-responsive">

<table class="low-stock-table">

    <tr>
        <th>Product Name</th>
        <th>Quantity</th>
        <th>Total Amount</th>
        <th>Date</th>
    </tr>

    <?php
    if (mysqli_num_rows($result_recent_sales) > 0) {

        while ($sale = mysqli_fetch_assoc($result_recent_sales)) {
    ?>

    <tr>
        <td>
            <?php echo htmlspecialchars($sale["product_name"]); ?>
        </td>

        <td>
            <?php echo (int) $sale["quantity"]; ?>
        </td>

        <td>
            ₱<?php echo number_format((float) $sale["total"], 2); ?>
        </td>

        <td>
            <?php
                 echo date(
                         "M d, Y — h:i A",
                 strtotime($sale["created_at"])
                );
            ?>
        </td>
    </tr>

    <?php
        }

    } else {
    ?>

    <tr>
        <td colspan="4">No sales recorded yet.</td>
    </tr>

    <?php } ?>

</table>

</div>

<div class="sales-pagination">

    <p class="sales-entry-info">
        <?php
        $showing_from = ($total_recent_sales === 0)
            ? 0
            : $offset + 1;

        $showing_to = min($offset + $per_page, $total_recent_sales);
        ?>

        Showing <?php echo $showing_from; ?>–<?php echo $showing_to; ?>
        of <?php echo $total_recent_sales; ?> entries
    </p>

    <div class="pagination-buttons">

        <?php
        $previous_page = $current_page - 1;
        $next_page = $current_page + 1;
        ?>

        <a
           href="?show_recent=1&per_page=<?php echo $per_page; ?>&page=<?php echo max(1, $previous_page); ?>"
            class="<?php echo ($current_page <= 1) ? 'disabled' : ''; ?>"
        >
            Previous
        </a>

<?php
$start_page = max(1, $current_page - 2);
$end_page = min($total_pages, $current_page + 2);
?>

<?php if ($start_page > 1): ?>
    <a href="?show_recent=1&per_page=<?php echo $per_page; ?>&page=1">1</a>

    <?php if ($start_page > 2): ?>
        <span class="pagination-ellipsis">…</span>
    <?php endif; ?>
<?php endif; ?>

<?php for ($page_number = $start_page; $page_number <= $end_page; $page_number++): ?>
    <a
        href="?show_recent=1&per_page=<?php echo $per_page; ?>&page=<?php echo $page_number; ?>"
        class="<?php echo ($page_number === $current_page) ? 'active' : ''; ?>"
    >
        <?php echo $page_number; ?>
    </a>
<?php endfor; ?>

<?php if ($end_page < $total_pages): ?>
    <?php if ($end_page < $total_pages - 1): ?>
        <span class="pagination-ellipsis">…</span>
    <?php endif; ?>

    <a href="?show_recent=1&per_page=<?php echo $per_page; ?>&page=<?php echo $total_pages; ?>">
        <?php echo $total_pages; ?>
    </a>
<?php endif; ?>

        <a
            href="?show_recent=1&per_page=<?php echo $per_page; ?>&page=<?php echo min($total_pages, $next_page); ?>"
            class="<?php echo ($current_page >= $total_pages) ? 'disabled' : ''; ?>"
        >
            Next
        </a>

    </div>

</div>

</section>

</main>

        <script>

            function toggleSection(sectionId, card) {
            const section = document.getElementById(sectionId);
            const isAlreadyOpen = !section.hidden;

    // Close both sections first
    document.getElementById("low-stock-section").hidden = true;
    document.getElementById("recent-sales-section").hidden = true;

    // Reset both cards' accessibility states
    document.querySelectorAll(".dashboard-toggle").forEach(function (item) {
        item.setAttribute("aria-expanded", "false");
    });

    // Open the clicked section only if it was previously closed
    if (!isAlreadyOpen) {
        section.hidden = false;
        card.setAttribute("aria-expanded", "true");
    }
}

window.addEventListener("DOMContentLoaded", function () {
    const params = new URLSearchParams(window.location.search);

    if (params.get("show_recent") === "1") {
        const recentSalesCard = document.querySelector(".sales-card");
        const recentSalesSection = document.getElementById("recent-sales-section");

        if (recentSalesCard && recentSalesSection) {
            recentSalesSection.hidden = false;
            recentSalesCard.setAttribute("aria-expanded", "true");
        }

        // Remove the query parameters so refreshing hides Recent Sales.
        window.history.replaceState(
            {},
            document.title,
            window.location.pathname
        );
    }
});

</script>

</body>

</html>