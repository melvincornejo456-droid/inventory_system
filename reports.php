<?php

require_once "auth.php";
require_once "db.php";

$start_date = $_GET["start_date"] ?? "";
$end_date = $_GET["end_date"] ?? "";
$selected_date = $_GET["selected_date"] ?? "";

$report_type = "daily";

$allowed_reports = ["summary", "daily", "monthly"];

if (!in_array($report_type, $allowed_reports, true)) {
    $report_type = "summary";
}

$valid_date = function ($date) {
    if (!is_string($date)) {
        return false;
    }

    $parsed_date = DateTime::createFromFormat("!Y-m-d", $date);

    return $parsed_date &&
           $parsed_date->format("Y-m-d") === $date;
};

if ($start_date !== "" && !$valid_date($start_date)) {
    exit("Invalid start date.");
}

if ($end_date !== "" && !$valid_date($end_date)) {
    exit("Invalid end date.");
}

if ($selected_date !== "" && !$valid_date($selected_date)) {
    exit("Invalid selected date.");
}

if (
    $start_date !== "" &&
    $end_date !== "" &&
    $start_date > $end_date
) {
    exit("Start date cannot be later than end date.");
}

// Get the sales summary
$sql = "SELECT
            COUNT(*) AS total_transactions,
            COALESCE(SUM(total), 0) AS total_revenue
        FROM sales
        WHERE (? = '' OR created_at >= ?)
          AND (? = '' OR created_at < DATE_ADD(?, INTERVAL 1 DAY))";

$stmt = mysqli_prepare($conn, $sql);

mysqli_stmt_bind_param(
    $stmt,
    "ssss",
    $start_date,
    $start_date,
    $end_date,
    $end_date
);

mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);
$summary = mysqli_fetch_assoc($result);

mysqli_stmt_close($stmt);

$daily_sql = "SELECT
                  DATE(created_at) AS sale_date,
                  COUNT(*) AS total_transactions,
                  SUM(total) AS total_revenue
              FROM sales
              WHERE created_at >= ?
                AND created_at < DATE_ADD(?, INTERVAL 1 DAY)
              GROUP BY DATE(created_at)
              ORDER BY sale_date DESC";

$daily_stmt = mysqli_prepare($conn, $daily_sql);

mysqli_stmt_bind_param(
    $daily_stmt,
    "ss",
    $selected_date,
    $selected_date
);

mysqli_stmt_execute($daily_stmt);

$daily_result = mysqli_stmt_get_result($daily_stmt);

mysqli_stmt_close($daily_stmt);


$monthly_sql = "SELECT
                    DATE_FORMAT(created_at, '%Y-%m') AS sale_month,
                    COUNT(*) AS total_transactions,
                    SUM(total) AS total_revenue
                FROM sales
                WHERE (? = '' OR created_at >= ?)
                  AND (? = '' OR created_at < DATE_ADD(?, INTERVAL 1 DAY))
                GROUP BY DATE_FORMAT(created_at, '%Y-%m')
                ORDER BY sale_month DESC";

$monthly_stmt = mysqli_prepare($conn, $monthly_sql);

mysqli_stmt_bind_param(
    $monthly_stmt,
    "ssss",
    $start_date,
    $start_date,
    $end_date,
    $end_date
);

mysqli_stmt_execute($monthly_stmt);

$monthly_result = mysqli_stmt_get_result($monthly_stmt);

mysqli_stmt_close($monthly_stmt);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Reports</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

<?php require_once "navbar.php"; ?>

<main>

<h1>Daily Summary Reports</h1>

<form method="GET" action="reports.php" class="daily-report-form">

<div class="date-filter-group">
    <label for="selected_date">Select Date:</label>

    <input
        type="date"
        id="selected_date"
        name="selected_date"
        value="<?php echo htmlspecialchars($selected_date, ENT_QUOTES, 'UTF-8'); ?>"
        required
    >
</div>

<button type="submit">Filter Daily Report</button>

</form>

    </form>

        <?php if ($report_type === "summary"): ?>

        <div class="summary-cards">

    <section class="report-card">
        <h2>Total Revenue</h2>
        <p>
            ₱<?php echo number_format(
                (float) $summary["total_revenue"],
                2
            ); ?>
        </p>
    </section>

    <section class="report-card">
        <h2>Total Transactions</h2>
        <p>
            <?php echo (int) $summary["total_transactions"]; ?>
        </p>
    </section>

    </div>

    <?php endif; ?>

<?php if ($selected_date !== ""): ?>

   <section class="table-card daily-report-table">

        <h2>Daily Summary Reports</h2>

        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Transactions</th>
                    <th>Revenue</th>
                </tr>
            </thead>

            <tbody>
                <?php
                $has_sales = false;

                while ($day = mysqli_fetch_assoc($daily_result)) {
                    $has_sales = true;
                ?>
                    <tr>
                        <td>
                            <?php echo htmlspecialchars($day["sale_date"]); ?>
                        </td>

                        <td>
                            <?php echo (int) $day["total_transactions"]; ?>
                        </td>

                        <td>
                            ₱<?php echo number_format(
                                (float) $day["total_revenue"],
                                2
                            ); ?>
                        </td>
                    </tr>
                <?php } ?>

                <?php if (!$has_sales): ?>
                    <tr>
                        <td colspan="3">
                            No sales found for this date.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

    </section>

<?php endif; ?>

    <?php if ($report_type === "monthly"): ?>

    <section class="table-card">

        <h2>Monthly Sales Summary</h2>
    
    </section>

    <table>
        <thead>
            <tr>
                <th>Month</th>
                <th>Transactions</th>
                <th>Revenue</th>
            </tr>
        </thead>
        <tbody>
            <?php while ($month = mysqli_fetch_assoc($monthly_result)) { ?>
                <tr>
                    <td>
                        <?php
                        echo htmlspecialchars(
                            date("F Y", strtotime($month["sale_month"] . "-01"))
                        );
                        ?>
                    </td>
                    <td>
                        <?php echo (int) $month["total_transactions"]; ?>
                    </td>
                    <td>
                        ₱<?php echo number_format(
                            (float) $month["total_revenue"],
                            2
                        );  ?>
                    </td>
                </tr>
            <?php } ?>
        </tbody>
    </table>

    <?php endif; ?>

</main>

</body>
</html>