<?php

require_once "auth.php";

// Only allow POST requests to log out
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Allow: POST");
    http_response_code(405);
    exit("Method not allowed.");
}

// Validate the CSRF token
$submitted_token = $_POST["csrf_token"] ?? "";

if (
    !is_string($submitted_token) ||
    !hash_equals($_SESSION["csrf_token"], $submitted_token)
) {
    http_response_code(403);
    exit("Invalid form submission. Please refresh and try again.");
}

// Clear all session variables
$_SESSION = [];

// Remove the session cookie from the browser
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();

    setcookie(
        session_name(),
        "",
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// Destroy the server-side session
session_destroy();

// Redirect to login
header("Location: login.php");
exit;