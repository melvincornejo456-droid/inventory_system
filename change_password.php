<?php

require_once "auth.php";
require_once "db.php";

$error = "";

$success = $_SESSION["password_change_success"] ?? "";

// Display the message only once
unset($_SESSION["password_change_success"]);

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $submitted_token = $_POST["csrf_token"] ?? "";

    if (
        !is_string($submitted_token) ||
        !hash_equals($_SESSION["csrf_token"], $submitted_token)
    ) {
        $error = "Invalid form submission. Please refresh the page.";
    } else {

        $current_password = $_POST["current_password"] ?? "";
        $new_password = $_POST["new_password"] ?? "";
        $confirm_password = $_POST["confirm_password"] ?? "";

        if (
            !is_string($current_password) ||
            !is_string($new_password) ||
            !is_string($confirm_password)
        ) {
            $error = "Invalid form input.";
        } elseif (
            $current_password === "" ||
            $new_password === "" ||
            $confirm_password === ""
        ) {
            $error = "Please fill in all password fields.";
        } elseif (strlen($new_password) < 12) {
            $error = "Your new password must be at least 12 characters.";
        } elseif ($new_password !== $confirm_password) {
            $error = "The new passwords do not match.";
        } elseif ($current_password === $new_password) {
            $error = "Your new password must be different from the current password.";
        } else {

            $sql = "SELECT password_hash FROM users WHERE id = ?";

            $stmt = mysqli_prepare($conn, $sql);

            if ($stmt) {

                mysqli_stmt_bind_param(
                    $stmt,
                    "i",
                    $_SESSION["user_id"]
                );

                mysqli_stmt_execute($stmt);

                $result = mysqli_stmt_get_result($stmt);
                $user = mysqli_fetch_assoc($result);

                mysqli_stmt_close($stmt);

                if (
                    !$user ||
                    !password_verify(
                        $current_password,
                        $user["password_hash"]
                    )
                ) {
                    $error = "Your current password is incorrect.";
                } else {

                    $new_hash = password_hash(
                        $new_password,
                        PASSWORD_DEFAULT
                    );

                    $update_sql = "UPDATE users
                                   SET password_hash = ?
                                   WHERE id = ?";

                    $update_stmt = mysqli_prepare($conn, $update_sql);

                    if ($update_stmt) {

                        mysqli_stmt_bind_param(
                            $update_stmt,
                            "si",
                            $new_hash,
                            $_SESSION["user_id"]
                        );

                    if (mysqli_stmt_execute($update_stmt)) {

                        $_SESSION["password_change_success"] =
                                "Password changed successfully!";

                // Generate a fresh CSRF token
                        $_SESSION["csrf_token"] = bin2hex(random_bytes(32));

                // Redirect to prevent form resubmission
                            header("Location: change_password.php");
                        exit;

                    } else {
                            $error = "Unable to change your password. Please try again.";
                            error_log(
                                "Password update failed: " .
                                mysqli_stmt_error($update_stmt)
                            );
                        }

                        mysqli_stmt_close($update_stmt);

                    } else {
                        $error = "Unable to process your request.";
                        error_log(
                            "Password update preparation failed: " .
                            mysqli_error($conn)
                        );
                    }
                }

            } else {
                $error = "Unable to process your request.";
                error_log(
                    "Password lookup preparation failed: " .
                    mysqli_error($conn)
                );
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password</title>

    <link rel="stylesheet" href="style.css">

    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f4f6f9;
            margin: 0;
            padding: 0;
        }

        .container {
            max-width: 420px;
            margin: 40px auto;
            padding: 25px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        h2 {
            text-align: center;
        }

        label {
            display: block;
            margin-top: 15px;
            margin-bottom: 6px;
        }

        input {
            width: 100%;
            box-sizing: border-box;
            padding: 11px;
            border: 1px solid #ccc;
            border-radius: 5px;
        }

        .container button {
            width: 100%;
            margin-top: 22px;
            padding: 12px;
            border: none;
            border-radius: 5px;
            background: #2563eb;
            color: white;
            cursor: pointer;
        }

        .container button:hover {
            background: #1d4ed8;
        }

        .message {
            padding: 10px;
            border-radius: 5px;
            margin: 15px 0;
        }

        .error {
            background: #fee2e2;
            color: #991b1b;
        }

        .success {
            background: #dcfce7;
            color: #166534;
        }

        .back-link {
            display: block;
            margin-top: 18px;
            text-align: center;
        }

    </style>
</head>

<body>

    <?php require_once "navbar.php"; ?>

    <div class="container">

    <h2>Change Password</h2>

    <?php if ($error !== ""): ?>
        <div class="message error">
            <?php echo htmlspecialchars($error, ENT_QUOTES, "UTF-8"); ?>
        </div>
    <?php endif; ?>

    <?php if ($success !== ""): ?>
        <div class="message success">
            <?php echo htmlspecialchars($success, ENT_QUOTES, "UTF-8"); ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="change_password.php">

        <input
            type="hidden"
            name="csrf_token"
            value="<?php echo htmlspecialchars($_SESSION["csrf_token"], ENT_QUOTES, "UTF-8"); ?>"
        >

        <label for="current_password">Current Password</label>
        <input
            type="password"
            id="current_password"
            name="current_password"
            required
            autocomplete="current-password"
        >

        <label for="new_password">New Password</label>
        <input
            type="password"
            id="new_password"
            name="new_password"
            minlength="12"
            required
            autocomplete="new-password"
        >

        <label for="confirm_password">Confirm New Password</label>
        <input
            type="password"
            id="confirm_password"
            name="confirm_password"
            minlength="12"
            required
            autocomplete="new-password"
        >

        <button type="submit">Change Password</button>

    </form>

    <a class="back-link" href="dashboard.php">Back to Dashboard</a>

</div>

</body>
</html>