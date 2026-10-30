<?php

require_once "auth.php";
require_once "db.php";


$start_date = $_GET["start_date"] ?? "";
$end_date = $_GET["end_date"] ?? "";

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

if (
    $start_date !== "" &&
    $end_date !== "" &&
    $start_date > $end_date
) {
    exit("Start date cannot be later than end date.");
}

$sql = "SELECT
            DATE_FORMAT(created_at, '%Y-%m') AS sale_month,
            COUNT(*) AS total_transactions,
            COALESCE(SUM(total), 0) AS total_revenue
        FROM sales
        WHERE (? = '' OR created_at >= ?)
          AND (? = '' OR created_at < DATE_ADD(?, INTERVAL 1 DAY))
        GROUP BY DATE_FORMAT(created_at, '%Y-%m')
        ORDER BY sale_month DESC";

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

mysqli_stmt_close($stmt);

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monthly Sales Reports</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

<?php require_once "navbar.php"; ?>

<main>

    <h1>Monthly Sales Reports</h1>

    <form method="GET" action="monthly_reports.php" class="monthly-report-form">

        <div class="date-filter-group">
            <label for="start_date">Start Date:</label>
            <input
                type="date"
                id="start_date"
                name="start_date"
                value="<?php echo htmlspecialchars($start_date, ENT_QUOTES, 'UTF-8'); ?>"
                required
            >
        </div>

        <div class="date-filter-group">
            <label for="end_date">End Date:</label>
            <input
                type="date"
                id="end_date"
                name="end_date"
                value="<?php echo htmlspecialchars($end_date, ENT_QUOTES, 'UTF-8'); ?>"
                required
            >
        </div>

        <button type="submit">Filter Monthly Reports</button>

    </form>

        <?php if ($start_date !== "" && $end_date !== ""): ?>

         <section class="table-card">

        <h2 class="monthly-summary-title">Monthly Sales Summary</h2>

        <table>
            <thead>
                <tr>
                    <th>Month</th>
                    <th>Transactions</th>
                    <th>Revenue</th>
                </tr>
            </thead>

            <tbody>
                <?php while ($month = mysqli_fetch_assoc($result)) { ?>
                    <tr>
                        <td>
                            <?php
                            echo htmlspecialchars(
                                date(
                                    "F Y",
                                    strtotime($month["sale_month"] . "-01")
                                ),
                                ENT_QUOTES,
                                "UTF-8"
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
                            ); ?>
                        </td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>

    </section>

    <?php endif; ?>

</main>

</body>
</html>