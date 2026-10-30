<?php

require_once "auth.php";
include "db.php";

$period = $_GET["period"] ?? "all";
$search = trim($_GET["search"] ?? "");
$start_date = $_GET["start_date"] ?? "";
$end_date = $_GET["end_date"] ?? "";

$allowed_periods = ["all", "today", "week", "month", "last_month"];

if (!in_array($period, $allowed_periods, true)) {
    $period = "all";
}

$is_valid_date = function ($date) {
    if (!is_string($date) || $date === "") {
        return false;
    }

    $parsed = DateTime::createFromFormat("!Y-m-d", $date);
    return $parsed && $parsed->format("Y-m-d") === $date;
};

if (!$is_valid_date($start_date)) {
    $start_date = "";
}

if (!$is_valid_date($end_date)) {
    $end_date = "";
}

$where = [];
$params = [];
$types = "";

switch ($period) {
    case "today":
        $where[] = "sales.created_at >= CURDATE()
                    AND sales.created_at < CURDATE() + INTERVAL 1 DAY";
        break;

    case "week":
        $where[] = "sales.created_at >= DATE_SUB(
                        CURDATE(),
                        INTERVAL WEEKDAY(CURDATE()) DAY
                    )
                    AND sales.created_at < CURDATE() + INTERVAL 1 DAY";
        break;

    case "month":
        $where[] = "sales.created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
                    AND sales.created_at < DATE_FORMAT(CURDATE(), '%Y-%m-01')
                        + INTERVAL 1 MONTH";
        break;

    case "last_month":
        $where[] = "sales.created_at >= DATE_FORMAT(
                        CURDATE() - INTERVAL 1 MONTH, '%Y-%m-01'
                    )
                    AND sales.created_at < DATE_FORMAT(
                        CURDATE(), '%Y-%m-01'
                    )";
        break;
}

if ($start_date !== "") {
    $where[] = "sales.created_at >= ?";
    $params[] = $start_date . " 00:00:00";
    $types .= "s";
}

if ($end_date !== "") {
    $where[] = "sales.created_at < DATE_ADD(?, INTERVAL 1 DAY)";
    $params[] = $end_date;
    $types .= "s";
}

if ($search !== "") {
    $where[] = "(CAST(sales.id AS CHAR) = ?
                 OR products.product_name LIKE ?)";
    $params[] = $search;
    $params[] = "%" . $search . "%";
    $types .= "ss";
}

$where_sql = count($where) > 0
    ? " WHERE " . implode(" AND ", $where)
    : "";

/* Count all records matching the current filters. */
$count_sql = "SELECT COUNT(*) AS total_records
              FROM sales
              INNER JOIN products ON sales.product_id = products.id
              $where_sql";

$count_stmt = mysqli_prepare($conn, $count_sql);

if (!$count_stmt) {
    error_log("Sales count prepare failed: " . mysqli_error($conn));
    die("Unable to load sales history. Please try again.");
}

if ($types !== "") {
    $count_bind = [$types];

    foreach ($params as $key => $value) {
        $count_bind[] = &$params[$key];
    }

    call_user_func_array(
        "mysqli_stmt_bind_param",
        array_merge([$count_stmt], $count_bind)
    );
}

if (!mysqli_stmt_execute($count_stmt)) {
    error_log("Sales count query failed: " . mysqli_stmt_error($count_stmt));
    die("Unable to load sales history. Please try again.");
}

$count_result = mysqli_stmt_get_result($count_stmt);
$count_row = mysqli_fetch_assoc($count_result);
$total_records = (int) $count_row["total_records"];

/* Entries per page: allow only the listed options. */
$allowed_per_page = [5, 10, 25, 50, 100];
$per_page = filter_var(
    $_GET["per_page"] ?? 10,
    FILTER_VALIDATE_INT
);

if (!in_array($per_page, $allowed_per_page, true)) {
    $per_page = 10;
}

/* Calculate and validate the current page. */
$total_pages = max(1, (int) ceil($total_records / $per_page));

$page = filter_var(
    $_GET["page"] ?? 1,
    FILTER_VALIDATE_INT
);

if ($page === false || $page < 1) {
    $page = 1;
}

$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

$showing_start = $total_records > 0 ? $offset + 1 : 0;
$showing_end = min($offset + $per_page, $total_records);

/* Load only the records for the selected page. */
$sql = "SELECT
            sales.id,
            products.product_name,
            sales.quantity,
            sales.total,
            sales.created_at
        FROM sales
        INNER JOIN products ON sales.product_id = products.id
        $where_sql
        ORDER BY sales.id DESC
        LIMIT $per_page OFFSET $offset";

$stmt = mysqli_prepare($conn, $sql);

if (!$stmt) {
    error_log("Sales history prepare failed: " . mysqli_error($conn));
    die("Unable to load sales history. Please try again.");
}

if ($types !== "") {
    $bind_params = [$types];

    foreach ($params as $key => $value) {
        $bind_params[] = &$params[$key];
    }

    call_user_func_array(
        "mysqli_stmt_bind_param",
        array_merge([$stmt], $bind_params)
    );
}

if (!mysqli_stmt_execute($stmt)) {
    error_log("Sales history query failed: " . mysqli_stmt_error($stmt));
    die("Unable to load sales history. Please try again.");
}

$result = mysqli_stmt_get_result($stmt);

if (!$result) {
    error_log("Sales history result failed: " . mysqli_stmt_error($stmt));
    die("Unable to load sales history. Please try again.");
}

/* Build links that preserve the active filters. */
$query_params = [
    "period" => $period,
    "per_page" => $per_page
];

if ($search !== "") {
    $query_params["search"] = $search;
}

if ($start_date !== "") {
    $query_params["start_date"] = $start_date;
}

if ($end_date !== "") {
    $query_params["end_date"] = $end_date;
}

$page_url = function ($target_page) use ($query_params) {
    $query = $query_params;
    $query["page"] = $target_page;

    return "sales_history.php?" . http_build_query($query);
};

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Sales History</title>

    <link rel="stylesheet" href="style.css">

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

        /* Main content */
        main {
            max-width: 1200px;
            margin: 40px auto;
            padding: 0 20px;
        }

        .page-heading {
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

        /* Summary card */
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
            font-size: 28px;
            font-weight: bold;
            color: #1d4ed8;
        }

        /* Table card */
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
            font-size: 19px;
            color: #0f172a;
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
            white-space: nowrap;
        }

        tbody tr:hover {
            background-color: #f8fafc;
        }

        .sale-id {
            color: #64748b;
            font-weight: bold;
        }

        .product-name {
            color: #0f172a;
            font-weight: bold;
        }

        .quantity {
            display: inline-block;
            padding: 5px 10px;
            background-color: #eff6ff;
            color: #1d4ed8;
            border-radius: 6px;
            font-weight: bold;
        }

        .total {
            color: #15803d;
            font-weight: bold;
        }

        .empty-state {
            padding: 40px 20px;
            text-align: center;
            color: #64748b;
            white-space: normal;
        }

        /* Mobile layout */
        @media (max-width: 600px) {
            nav {
                padding: 12px 4%;
                gap: 5px;
            }

            nav a {
                padding: 9px 10px;
                font-size: 13px;
            }

            main {
                margin: 25px auto;
                padding: 0 15px;
            }

            .page-heading h1 {
                font-size: 25px;
            }

            .summary-card {
                padding: 20px;
            }

            .table-heading {
                padding: 18px;
            }

            th,
            td {
                padding: 13px 15px;
            }
        }

        /* Sales history filters */

/* Professional Sales History Filters */
.filter-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 26px;
    margin-bottom: 28px;
    box-shadow: 0 4px 18px rgba(15, 23, 42, 0.04);
}

.filter-header {
    display: flex;
    align-items: center;
    gap: 14px;
    padding-bottom: 22px;
    margin-bottom: 24px;
    border-bottom: 1px solid #edf0f5;
}

.filter-title-icon {
    width: 46px;
    height: 46px;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 12px;
    background: #eff6ff;
    color: #2563eb;
}

.filter-symbol {
    font-size: 26px;
    font-style: normal;
    line-height: 1;
}

.filter-header h2 {
    margin: 0 0 5px;
    color: #0f172a;
    font-size: 18px;
    font-weight: 700;
}

.filter-header p {
    margin: 0;
    color: #64748b;
    font-size: 13px;
    line-height: 1.5;
}

.filter-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 22px;
}

.filter-field {
    display: flex;
    flex-direction: column;
    gap: 9px;
    min-width: 0;
}

.filter-field label {
    color: #334155;
    font-size: 13px;
    font-weight: 600;
}

.filter-field input,
.filter-field select {
    width: 100%;
    min-width: 0;
    min-height: 44px;
    padding: 11px 13px;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    background: #ffffff;
    color: #1e293b;
    font-family: inherit;
    font-size: 14px;
    transition: border-color 0.2s, box-shadow 0.2s;
}

.filter-field input::placeholder {
    color: #94a3b8;
}

.filter-field input:focus,
.filter-field select:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.12);
}

.filter-actions {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-top: 25px;
    padding-top: 20px;
    border-top: 1px solid #edf0f5;
}

.filter-btn,
.reset-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 43px;
    padding: 11px 20px;
    border-radius: 8px;
    font-family: inherit;
    font-size: 14px;
    font-weight: 600;
    text-decoration: none;
    cursor: pointer;
    transition: background 0.2s, transform 0.2s;
}

.filter-btn {
    border: 1px solid #2563eb;
    background: #2563eb;
    color: #ffffff;
}

.filter-btn:hover {
    background: #1d4ed8;
    transform: translateY(-1px);
}

.reset-btn {
    border: 1px solid #cbd5e1;
    background: #ffffff;
    color: #475569;
}

.reset-btn:hover {
    background: #f8fafc;
    border-color: #94a3b8;
}

@media (max-width: 600px) {
    .filter-card {
        padding: 19px;
    }

    .filter-grid {
        grid-template-columns: 1fr;
        gap: 18px;
    }

    .filter-actions {
        flex-wrap: wrap;
    }

    .filter-btn,
    .reset-btn {
        flex: 1;
    }
}

.summary-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 20px;
    margin-bottom: 25px;
}

.summary-grid .summary-card {
    margin-bottom: 0;
    min-width: 0;
}

.summary-number {
    overflow-wrap: anywhere;
}

@media (max-width: 850px) {
    .filter-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 500px) {
    .filter-grid,
    .summary-grid {
        grid-template-columns: 1fr;
    }

    .filter-actions {
        flex-wrap: wrap;
    }
}


/* Show entries */
.entries-control {
    display: flex;
    justify-content: flex-start;
    align-items: center;
    padding: 16px 24px;
}

.entries-control form {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

.entries-control label {
    color: #475569;
    font-size: 14px;
}

.entries-control select {
    padding: 8px 12px;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    background: #ffffff;
    color: #1e293b;
    font-size: 14px;
}

/* Pagination */
.pagination-container {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
    flex-wrap: wrap;
    padding: 18px 24px;
    border-top: 1px solid #e2e8f0;
}

.entries-info {
    color: #64748b;
    font-size: 14px;
}

.pagination {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
}

.pagination a,
.pagination span {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 36px;
    min-height: 36px;
    padding: 8px 11px;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    text-decoration: none;
    font-size: 13px;
}

.pagination a {
    color: #334155;
    background: #ffffff;
}

.pagination a:hover {
    background: #eff6ff;
    border-color: #93c5fd;
}

.pagination .current-page {
    color: #ffffff;
    background: #2563eb;
    border-color: #2563eb;
    font-weight: bold;
}

.pagination .disabled {
    color: #94a3b8;
    background: #f8fafc;
    cursor: not-allowed;
}

@media (max-width: 600px) {
    .entries-control {
        padding: 15px 18px;
    }

    .pagination-container {
        padding: 16px 18px;
    }
}

/* Toolbar: Show Entries + Filter Button */
.table-toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
    padding: 16px 24px;
    border-bottom: 1px solid #e2e8f0;
}

.table-toolbar .entries-control {
    padding: 0;
}

.open-filter-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 9px;
    min-height: 42px;
    padding: 10px 16px;
    border: 1px solid #2563eb;
    border-radius: 8px;
    background: #2563eb;
    color: #fff;
    font-family: inherit;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.2s;
}

.open-filter-btn:hover {
    background: #1d4ed8;
}

/* Popup Background */
.filter-modal {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 9999;
    padding: 20px;
    background: rgba(15, 23, 42, 0.55);
    align-items: center;
    justify-content: center;
    overflow-y: auto;
}

.filter-modal.is-open {
    display: flex;
}

/* Popup Panel */
.filter-modal-content {
    width: 100%;
    max-width: 620px;
    max-height: calc(100vh - 40px);
    overflow-y: auto;
    padding: 28px;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    background: #fff;
    box-shadow: 0 20px 60px rgba(15, 23, 42, 0.2);
    animation: filterPopupIn 0.2s ease-out;
}

@keyframes filterPopupIn {
    from {
        opacity: 0;
        transform: translateY(10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 16px;
    margin-bottom: 24px;
    padding-bottom: 20px;
    border-bottom: 1px solid #edf0f5;
}

.modal-header h2 {
    margin: 0 0 6px;
    color: #0f172a;
    font-size: 21px;
}

.modal-header p {
    margin: 0;
    color: #64748b;
    font-size: 13px;
    line-height: 1.5;
}

.close-filter-btn {
    width: 36px;
    height: 36px;
    flex-shrink: 0;
    border: none;
    border-radius: 8px;
    background: #f1f5f9;
    color: #475569;
    font-size: 27px;
    line-height: 1;
    cursor: pointer;
}

.close-filter-btn:hover {
    background: #e2e8f0;
}

.modal-filter-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 20px;
}

.modal-filter-grid .filter-field {
    display: flex;
    flex-direction: column;
    gap: 8px;
    min-width: 0;
}

.modal-filter-grid .filter-field label {
    color: #334155;
    font-size: 13px;
    font-weight: 600;
}

.modal-filter-grid .filter-field input,
.modal-filter-grid .filter-field select {
    width: 100%;
    min-width: 0;
    min-height: 44px;
    padding: 10px 12px;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    background: #fff;
    color: #1e293b;
    font-family: inherit;
    font-size: 14px;
    box-sizing: border-box;
}

.modal-filter-grid .filter-field input:focus,
.modal-filter-grid .filter-field select:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.12);
}

.modal-filter-actions {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 14px;
    margin-top: 26px;
    padding-top: 20px;
    border-top: 1px solid #edf0f5;
}

.modal-filter-actions .filter-btn,
.modal-filter-actions .reset-btn {
    flex: 0 1 160px;
    min-width: 140px;
    text-align: center;
}

body.filter-modal-open {
    overflow: hidden;
}

@media (max-width: 600px) {
    .table-toolbar {
        padding: 15px 18px;
    }

    .filter-modal {
        padding: 12px;
    }

    .filter-modal-content {
        padding: 20px;
        max-height: calc(100vh - 24px);
    }

    .modal-filter-grid {
        grid-template-columns: 1fr;
        gap: 16px;
    }

    .open-filter-btn {
        width: 100%;
    }
}

/* Center the popup action buttons */
#salesFilterModal .modal-filter-actions {
    display: flex !important;
    flex-direction: row !important;
    justify-content: center !important;
    align-items: center !important;
    gap: 14px !important;
    width: 100% !important;
    text-align: center !important;
}

#salesFilterModal .modal-filter-actions button.filter-btn,
#salesFilterModal .modal-filter-actions a.reset-btn {
    display: inline-flex !important;
    flex: 0 0 150px !important;
    width: 150px !important;
    min-width: 150px !important;
    margin: 0 !important;
    justify-content: center !important;
    align-items: center !important;
    text-align: center !important;
}


/* Fix compressed Sales History table */
main {
    width: 100%;
    max-width: 1400px;
    margin: 40px auto;
    padding: 0 24px;
}

.transaction-table-card {
    width: 100%;
    max-width: none;
    min-width: 0;
    margin: 0 0 25px;
}

.transaction-table-card .table-responsive {
    width: 100%;
    overflow-x: auto;
}

.transaction-table-card table {
    width: 100%;
    min-width: 1000px;
    table-layout: fixed;
}

.transaction-table-card th:nth-child(1),
.transaction-table-card td:nth-child(1) {
    width: 12%;
}

.transaction-table-card th:nth-child(2),
.transaction-table-card td:nth-child(2) {
    width: 20%;
}

.transaction-table-card th:nth-child(3),
.transaction-table-card td:nth-child(3) {
    width: 15%;
}

.transaction-table-card th:nth-child(4),
.transaction-table-card td:nth-child(4) {
    width: 20%;
}

.transaction-table-card th:nth-child(5),
.transaction-table-card td:nth-child(5) {
    width: 29%;
}

.transaction-table-card th,
.transaction-table-card td {
    padding: 18px 20px;
}

    </style>
</head>

<body>

    <?php require_once "navbar.php"; ?>

    <main>

        <div class="page-heading">
            <h1>Sales History</h1>
            <p>Review completed sales and track your transaction records.</p>
        </div>

<div id="salesFilterModal" class="filter-modal"
     onclick="closeSalesFilterOutside(event)">

    <div class="filter-modal-content">
        <div class="modal-header">
            <div>
                <h2>Filter Sales Records</h2>
                <p>Narrow down transactions by date or product.</p>
            </div>

            <button type="button" class="close-filter-btn"
                    onclick="closeSalesFilter()"
                    aria-label="Close filter">
                &times;
            </button>
        </div>

        <form method="GET" action="sales_history.php">
            <div class="modal-filter-grid">
                <div class="filter-field">
                    <label for="period">Quick Filter</label>
                    <select name="period" id="period">
                        <option value="all" <?= $period === "all" ? "selected" : "" ?>>All Time</option>
                        <option value="today" <?= $period === "today" ? "selected" : "" ?>>Today</option>
                        <option value="week" <?= $period === "week" ? "selected" : "" ?>>This Week</option>
                        <option value="month" <?= $period === "month" ? "selected" : "" ?>>This Month</option>
                        <option value="last_month" <?= $period === "last_month" ? "selected" : "" ?>>Last Month</option>
                    </select>
                </div>

                <div class="filter-field">
                    <label for="search">Search Sale ID or Product</label>
                    <input type="text" name="search" id="search"
                           placeholder="e.g. 25 or Rice"
                           value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
                </div>

                <div class="filter-field">
                    <label for="start_date">Start Date</label>
                    <input type="date" name="start_date" id="start_date"
                           value="<?= htmlspecialchars($start_date, ENT_QUOTES, 'UTF-8') ?>">
                </div>

                <div class="filter-field">
                    <label for="end_date">End Date</label>
                    <input type="date" name="end_date" id="end_date"
                           value="<?= htmlspecialchars($end_date, ENT_QUOTES, 'UTF-8') ?>">
                </div>
            </div>

            <div class="modal-filter-actions">
                <button type="submit" class="filter-btn">
                    Apply Filters
                </button>

                <a href="<?= htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8') ?>"
                   class="reset-btn">
                    Reset
                </a>
            </div>
        </form>
    </div>
</div>

 </section>

        <section class="table-card transaction-table-card">

            <div class="table-heading">
                <h2>Transaction Records</h2>
            </div>

           <div class="table-toolbar">

    <div class="entries-control">
        <form method="GET" action="sales_history.php">
            <label for="per_page">Show</label>

            <select name="per_page" id="per_page"
                    onchange="this.form.submit()">
                <option value="5" <?= $per_page === 5 ? "selected" : "" ?>>5</option>
                <option value="10" <?= $per_page === 10 ? "selected" : "" ?>>10</option>
                <option value="25" <?= $per_page === 25 ? "selected" : "" ?>>25</option>
                <option value="50" <?= $per_page === 50 ? "selected" : "" ?>>50</option>
                <option value="100" <?= $per_page === 100 ? "selected" : "" ?>>100</option>
            </select>

            <span>entries</span>

            <input type="hidden" name="period"
                   value="<?= htmlspecialchars($period, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="search"
                   value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="start_date"
                   value="<?= htmlspecialchars($start_date, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="end_date"
                   value="<?= htmlspecialchars($end_date, ENT_QUOTES, 'UTF-8') ?>">
        </form>
    </div>

    <button type="button" class="open-filter-btn"
            onclick="openSalesFilter()">
        <span>☷</span> Filter Sales Records
    </button>
</div>


            <div class="table-responsive">

                <table>
                    <thead>
                        <tr>
                            <th>Sale ID</th>
                            <th>Product</th>
                            <th>Quantity</th>
                            <th>Total Amount</th>
                            <th>Date &amp; Time</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php if ($total_records > 0): ?>

                            <?php while ($row = mysqli_fetch_assoc($result)): ?>

                                <tr>
                                    <td class="sale-id">
                                        #<?php echo (int) $row["id"]; ?>
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

                                    <td>
                                        <span class="quantity">
                                            <?php echo (int) $row["quantity"]; ?>
                                        </span>
                                    </td>

                                    <td class="total">
                                        ₱<?php echo number_format((float) $row["total"], 2); ?>
                                    </td>

                                    <td>
                                        <?php
                                        echo htmlspecialchars(
                                            $row["created_at"],
                                            ENT_QUOTES,
                                            "UTF-8"
                                        );
                                        ?>
                                    </td>
                                </tr>

                            <?php endwhile; ?>

                        <?php else: ?>

                            <tr>
                                <td colspan="5" class="empty-state">
                                    No sales records yet. Completed sales will appear here.
                                </td>
                            </tr>

                        <?php endif; ?>

                    </tbody>

             </table>

            </div>

            <!-- Pagination: placed below the table -->
            <div class="pagination-container">
                <div class="entries-info">
                    Showing <?= number_format($showing_start) ?>
                    – <?= number_format($showing_end) ?>
                    of <?= number_format($total_records) ?> entries
                </div>

                <nav class="pagination" aria-label="Sales history pages">
                    <?php if ($page > 1): ?>
                        <a href="<?= htmlspecialchars($page_url($page - 1), ENT_QUOTES, 'UTF-8') ?>">
                            Previous
                        </a>
                    <?php else: ?>
                        <span class="disabled">Previous</span>
                    <?php endif; ?>

                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <?php if ($i === $page): ?>
                            <span class="current-page" aria-current="page">
                                <?= $i ?>
                            </span>
                        <?php else: ?>
                            <a href="<?= htmlspecialchars($page_url($i), ENT_QUOTES, 'UTF-8') ?>">
                                <?= $i ?>
                            </a>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <?php if ($page < $total_pages): ?>
                        <a href="<?= htmlspecialchars($page_url($page + 1), ENT_QUOTES, 'UTF-8') ?>">
                            Next
                        </a>
                    <?php else: ?>
                        <span class="disabled">Next</span>
                    <?php endif; ?>
                </nav>
            </div>

        </section>

    </main>

<script>
function openSalesFilter() {
    const modal = document.getElementById("salesFilterModal");

    modal.classList.add("is-open");
    document.body.classList.add("filter-modal-open");
}

function closeSalesFilter() {
    const modal = document.getElementById("salesFilterModal");

    modal.classList.remove("is-open");
    document.body.classList.remove("filter-modal-open");
}

function closeSalesFilterOutside(event) {
    if (event.target.id === "salesFilterModal") {
        closeSalesFilter();
    }
}

document.addEventListener("keydown", function(event) {
    if (event.key === "Escape") {
        closeSalesFilter();
    }
});

</script>
</body>
</html>