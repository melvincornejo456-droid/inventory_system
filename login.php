<?php

session_start();

if (empty($_SESSION["login_csrf_token"])) {
    $_SESSION["login_csrf_token"] = bin2hex(random_bytes(32));
}

include "db.php";

$error = "";
$username = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $submitted_token = $_POST["csrf_token"] ?? "";

    // Step 1: Validate the CSRF token
    if (
        !isset($_SESSION["login_csrf_token"]) ||
        !is_string($_SESSION["login_csrf_token"]) ||
        !is_string($submitted_token) ||
        !hash_equals($_SESSION["login_csrf_token"], $submitted_token)
    ) {
        $error = "Invalid form submission. Please refresh the page and try again.";

    } else {

        // Step 2: Read the submitted credentials
        $username = trim($_POST["username"] ?? "");
        $password = $_POST["password"] ?? "";

        // Step 3: Find the user using a prepared statement
        $sql = "SELECT id, username, password_hash
                FROM users
                WHERE username = ?";

        $stmt = mysqli_prepare($conn, $sql);

        if ($stmt) {

            mysqli_stmt_bind_param($stmt, "s", $username);
            mysqli_stmt_execute($stmt);

            $result = mysqli_stmt_get_result($stmt);
            $user = mysqli_fetch_assoc($result);

            // Step 4: Verify the password
            if (
                $user &&
                password_verify($password, $user["password_hash"])
            ) {

                // Step 5: Regenerate the session ID
                session_regenerate_id(true);

                $_SESSION["user_id"] = $user["id"];
                $_SESSION["username"] = $user["username"];

                header("Location: dashboard.php");
                exit;

            } else {
                $error = "Invalid username or password.";
            }

            mysqli_stmt_close($stmt);

        } else {

            $error = "Unable to process login right now.";

            error_log(
                "Login query preparation failed: " . mysqli_error($conn)
            );
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Login | Inventory & Sales Management System</title>

    <style>

        * {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

body {
    font-family: Arial, Helvetica, sans-serif;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 30px 16px;
    color: #f8fafc;

    background:
        linear-gradient(
            135deg,
            rgba(7, 16, 43, 0.88),
            rgba(20, 49, 112, 0.76),
            rgba(7, 16, 43, 0.91)
        ),
        url("images/login.jpg") center center / cover no-repeat fixed;
}

/* MAIN LOGIN CONTAINER */
.login-container {
    width: 100%;
    max-width: 420px;
    position: relative;
    z-index: 1;
}

/* BRANDING */
.brand {
    text-align: center;
    margin-bottom: 25px;
}

.brand h1 {
    font-size: clamp(30px, 6vw, 38px);
    font-weight: 800;
    letter-spacing: 0.5px;
    line-height: 1.25;
    margin-bottom: 10px;
    color: #ffffff;
    text-shadow: 0 3px 18px rgba(0, 0, 0, 0.35);
}

.brand-accent {
    color: #38bdf8;
}

.brand p {
    color: #dbeafe;
    font-size: 14px;
    font-weight: 500;
    line-height: 1.7;
    text-shadow: 0 2px 10px rgba(0, 0, 0, 0.4);
}

/* PREMIUM GLASS LOGIN CARD */
.login-card {
    padding: 34px;
    background: rgba(12, 25, 55, 0.88);
    border: 1px solid rgba(147, 197, 253, 0.30);
    border-radius: 20px;

    box-shadow:
        0 25px 70px rgba(0, 0, 0, 0.38),
        0 0 35px rgba(37, 99, 235, 0.14),
        inset 0 1px 0 rgba(255, 255, 255, 0.06);

    -webkit-backdrop-filter: blur(16px);
    backdrop-filter: blur(16px);
}

.login-card h2 {
    color: #ffffff;
    font-size: 25px;
    font-weight: 700;
    margin-bottom: 10px;
}

.login-description {
    color: #cbd5e1;
    font-size: 14px;
    line-height: 1.7;
    margin-bottom: 28px;
}

/* FORM FIELDS */
.form-group {
    margin-bottom: 21px;
}

label {
    display: block;
    color: #e2e8f0;
    font-size: 13px;
    font-weight: 700;
    margin-bottom: 9px;
}

input {
    display: block;
    width: 100%;
    min-height: 48px;
    padding: 13px 14px;

    background: rgba(5, 14, 35, 0.72);
    color: #ffffff;

    border: 1px solid #475569;
    border-radius: 10px;

    font-family: inherit;
    font-size: 14px;

    transition:
        border-color 0.2s ease,
        box-shadow 0.2s ease,
        background 0.2s ease;
}

input::placeholder {
    color: #94a3b8;
}

input:hover {
    border-color: #64748b;
}

input:focus {
    outline: none;
    border-color: #38bdf8;
    background: rgba(9, 23, 53, 0.96);
    box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.17);
}

/* PASSWORD SHOW/HIDE BUTTON */
.password-wrapper {
    position: relative;
}

.password-wrapper input {
    padding-right: 76px;
}

.toggle-password {
    position: absolute;
    top: 50%;
    right: 13px;
    transform: translateY(-50%);

    border: none;
    background: transparent;
    color: #7dd3fc;

    font-family: inherit;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
}

.toggle-password:hover {
    color: #ffffff;
}

.toggle-password:focus-visible {
    outline: 2px solid #38bdf8;
    outline-offset: 3px;
    border-radius: 3px;
}

/* LOGIN ERROR MESSAGE */
.error-message {
    background: rgba(127, 29, 29, 0.32);
    color: #fecaca;
    border: 1px solid rgba(248, 113, 113, 0.55);
    border-radius: 10px;
    padding: 13px;
    margin-bottom: 21px;
    font-size: 14px;
    line-height: 1.6;
}

/* LOGIN BUTTON */
.login-button {
    display: block;
    width: 100%;
    min-height: 49px;
    margin-top: 7px;
    padding: 14px;

    background: linear-gradient(135deg, #2563eb, #0ea5e9);
    color: #ffffff;

    border: 1px solid rgba(147, 197, 253, 0.3);
    border-radius: 10px;

    font-family: inherit;
    font-size: 15px;
    font-weight: 700;
    letter-spacing: 0.3px;

    cursor: pointer;

    box-shadow: 0 8px 22px rgba(37, 99, 235, 0.28);

    transition:
        background 0.2s ease,
        box-shadow 0.2s ease,
        transform 0.2s ease;
}

.login-button:hover {
    background: linear-gradient(135deg, #1d4ed8, #0284c7);
    box-shadow: 0 10px 28px rgba(14, 165, 233, 0.34);
    transform: translateY(-1px);
}

.login-button:active {
    transform: translateY(0);
}

.login-button:focus-visible {
    outline: 3px solid #7dd3fc;
    outline-offset: 4px;
}

/* FOOTER */
.footer {
    text-align: center;
    margin-top: 24px;
    color: #cbd5e1;
    font-size: 12px;
    line-height: 1.7;
    text-shadow: 0 2px 8px rgba(0, 0, 0, 0.4);
}

/* MOBILE RESPONSIVENESS */
@media (max-width: 480px) {
    body {
        padding: 24px 14px;
        background-attachment: scroll;
    }

    .brand {
        margin-bottom: 22px;
    }

    .brand p {
        font-size: 13px;
    }

    .login-card {
        padding: 26px 21px;
        border-radius: 16px;
    }

    .login-card h2 {
        font-size: 23px;
    }

    .login-description {
        margin-bottom: 23px;
    }
}

@media (prefers-reduced-motion: reduce) {
    *,
    *::before,
    *::after {
        transition-duration: 0.01ms !important;
    }

    .login-button:hover {
        transform: none;
    }
}

    </style>
</head>

<body>

    <main class="login-container">

        <div class="brand">

        <h1>Inventory <span class="brand-accent">System</span></h1>
        <p>Inventory &amp; Sales Management System</p>

        </div>

        <section class="login-card">

            <h2>Welcome Back!</h2>

            <p class="login-description">
                Please enter your administrator credentials to continue.
            </p>

            <?php if ($error !== "") { ?>
                <div class="error-message" role="alert">
                    <?php echo htmlspecialchars($error, ENT_QUOTES, "UTF-8"); ?>
                </div>
            <?php } ?>

            <form method="POST" action="login.php">

                <input
                        type="hidden"
                        name="csrf_token"
                        value="<?php echo htmlspecialchars($_SESSION["login_csrf_token"], ENT_QUOTES, "UTF-8"); ?>"
                    >

                <div class="form-group">
                    <label for="username">Username</label>

                    <input
                        type="text"
                        id="username"
                        name="username"
                        maxlength="50"
                        autocomplete="username"
                        placeholder="Enter your username"
                        value="<?php echo htmlspecialchars($username, ENT_QUOTES, "UTF-8"); ?>"
                        required
                        autofocus
                    >
                    </div>

                    <div class="form-group">
                        <label for="password">Password</label>

                        <div class="password-wrapper">
                    <input
                        type="password"
                        id="password"
                        name="password"
                        autocomplete="current-password"
                        placeholder="Enter your password"
                        required
                    >

                    <button
                        type="button"
                        id="togglePassword"
                        class="toggle-password"
                        aria-label="Show password"
                        aria-pressed="false"
                    >
                        Show
                    </button>
                </div>
            </div>

                <button type="submit" class="login-button">
                        Log In
                </button>

            </form>

        </section>

        <div class="footer">
            <div>Inventory &amp; Sales Management System</div>
            <div>&copy; 2026 By: Melvin Cornejo</div>
            <div class="version">v1.0.1</div>
            </div>

    </main>
            <script>
    const passwordInput = document.getElementById("password");
    const togglePassword = document.getElementById("togglePassword");

    togglePassword.addEventListener("click", function () {
        const isPasswordHidden = passwordInput.type === "password";

        passwordInput.type = isPasswordHidden ? "text" : "password";

        togglePassword.textContent = isPasswordHidden ? "Hide" : "Show";
        togglePassword.setAttribute(
            "aria-label",
            isPasswordHidden ? "Hide password" : "Show password"
        );
        togglePassword.setAttribute(
            "aria-pressed",
            String(isPasswordHidden)
        );
    });
    </script>

</body>

</html>