<?php

require_once "auth.php";
require_once "db.php";

$error_message = "";

// Default values for the form
$product_name = "";
$price_input = "";
$stock_input = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

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

    // 2. Read the submitted values
    $name_input = $_POST["product_name"] ?? "";
    $price_input = $_POST["price"] ?? "";
    $stock_input = $_POST["stock"] ?? "";

    $product_name = is_string($name_input)
        ? trim($name_input)
        : "";

    // 3. Validate product name
    if ($product_name === "") {
        $error_message = "Product name cannot be empty.";
    }

    // 4. Validate price
    elseif (
        !is_scalar($price_input) ||
        !is_numeric($price_input) ||
        !is_finite((float) $price_input) ||
        (float) $price_input < 0
    ) {
        $error_message = "Price must be a valid, non-negative number.";
    }

    // 5. Validate stock
    elseif (
        !is_scalar($stock_input) ||
        filter_var($stock_input, FILTER_VALIDATE_INT) === false ||
        (int) $stock_input < 0
    ) {
        $error_message = "Stock must be a whole number of zero or greater.";
    }

    // 6. Validate and prepare the optional image
    $image_name = null;
    $temporary_image_path = null;
    $saved_image_path = null;

    if ($error_message === "" && isset($_FILES["product_image"])) {

        $upload = $_FILES["product_image"];

        if (!is_array($upload) || !isset($upload["error"])) {
            $error_message = "Invalid image upload.";
        } elseif ($upload["error"] !== UPLOAD_ERR_NO_FILE) {

            if ($upload["error"] !== UPLOAD_ERR_OK) {
                $error_message = "Image upload failed. Please try again.";
            } elseif (
                !isset($upload["tmp_name"], $upload["size"]) ||
                !is_string($upload["tmp_name"]) ||
                !is_numeric($upload["size"]) ||
                (int) $upload["size"] <= 0 ||
                (int) $upload["size"] > 5 * 1024 * 1024 ||
                !is_uploaded_file($upload["tmp_name"])
            ) {
                $error_message = "Choose a valid image up to 5 MB.";
            } else {

                // Detect the actual file type, not just its extension
                $image_info = @getimagesize($upload["tmp_name"]);

                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime_type = $finfo->file($upload["tmp_name"]);

                $allowed_types = [
                    "image/jpeg" => "jpg",
                    "image/png"  => "png",
                    "image/webp" => "webp"
                ];

                if (
                    $image_info === false ||
                    !isset($allowed_types[$mime_type]) ||
                    $image_info["mime"] !== $mime_type
                ) {
                    $error_message =
                        "Only valid JPG, PNG, or WebP images are allowed.";
                } else {

                    // Generate a unique filename
                    $image_name = bin2hex(random_bytes(16))
                        . "." . $allowed_types[$mime_type];

                    $upload_directory = __DIR__ . DIRECTORY_SEPARATOR . "uploads";

                    if (
                        !is_dir($upload_directory) ||
                        !is_writable($upload_directory)
                    ) {
                        $error_message =
                            "The uploads folder is unavailable or not writable.";
                    } else {
                        $temporary_image_path = $upload["tmp_name"];
                        $saved_image_path = $upload_directory
                            . DIRECTORY_SEPARATOR . $image_name;
                    }
                }
            }
        }
    }

    // 7. Insert the product after validation
    if ($error_message === "") {

        $price = (float) $price_input;
        $stock = (int) $stock_input;
        $image_saved = false;

        try {

            // Save the image first, if one was selected
            if ($saved_image_path !== null) {
                if (!move_uploaded_file(
                    $temporary_image_path,
                    $saved_image_path
                )) {
                    throw new RuntimeException("Image could not be saved.");
                }

                $image_saved = true;
            }

            // Save the filename in the database
            $stmt = mysqli_prepare(
                $conn,
                "INSERT INTO products
                    (product_name, price, stock, image)
                 VALUES (?, ?, ?, ?)"
            );

            mysqli_stmt_bind_param(
                $stmt,
                "sdis",
                $product_name,
                $price,
                $stock,
                $image_name
            );

            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            $_SESSION["success_message"] =
                "Product added successfully!";

            header("Location: products.php");
            exit;

        } catch (Throwable $e) {

            error_log("Product creation failed: " . $e->getMessage());

            // Remove the image if the database insert fails
            if (
                $image_saved &&
                $saved_image_path !== null &&
                is_file($saved_image_path)
            ) {
                @unlink($saved_image_path);
            }

            $error_message =
                "Unable to add the product. Please try again.";
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Add Product</title>

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
        nav {
            background-color: #ffffff;
            padding: 18px 6%;
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            border-bottom: 1px solid #e2e8f0;
            box-shadow: 0 2px 8px rgba(15, 23, 42, 0.04);
        }

        nav a {
            color: #475569;
            text-decoration: none;
            font-size: 14px;
            font-weight: bold;
            padding: 10px 14px;
            border-radius: 7px;
            transition: 0.2s;
        }

        nav a:hover {
            color: #1d4ed8;
            background-color: #eff6ff;
        }

        nav a.active {
            color: #1d4ed8;
            background-color: #dbeafe;
        }

        /* Main content */
        main {
            max-width: 760px;
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

        /* Form card */
        .form-card {
            background-color: #ffffff;
            padding: 30px;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            box-shadow: 0 5px 20px rgba(15, 23, 42, 0.05);
        }

        .form-card h2 {
            margin: 0 0 8px;
            font-size: 20px;
            color: #0f172a;
        }

        .card-description {
            margin: 0 0 25px;
            color: #64748b;
            font-size: 14px;
            line-height: 1.6;
        }

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

        input {
            display: block;
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

        input:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
        }

        .error-message {
            padding: 14px 16px;
            margin-bottom: 22px;
            border-radius: 8px;
            background-color: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
            font-size: 14px;
            line-height: 1.5;
        }

        .button-group {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 28px;
        }

        .btn {
            display: inline-block;
            padding: 13px 18px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: bold;
            text-align: center;
            text-decoration: none;
            cursor: pointer;
            transition: background-color 0.2s;
        }

        .btn-primary {
            flex: 1;
            background-color: #2563eb;
            color: #ffffff;
        }

        .btn-primary:hover {
            background-color: #1d4ed8;
        }

        .btn-secondary {
            background-color: #f1f5f9;
            color: #475569;
        }

        .btn-secondary:hover {
            background-color: #e2e8f0;
        }

        .btn:focus-visible,
        nav a:focus-visible {
            outline: 3px solid #93c5fd;
            outline-offset: 3px;
        }

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

            .form-card {
                padding: 22px 18px;
            }

            .button-group {
                flex-direction: column;
            }

            .btn-primary {
                flex: auto;
            }
        }
    </style>
</head>

<body>

    <nav>
        <a href="dashboard.php">Dashboard</a>
        <a href="products.php" class="active">Products</a>
        <a href="sales.php">Make a Sale</a>
        <a href="sales_history.php">Sales History</a>
        <a href="logout.php">Logout</a>
    </nav>

    <main>

        <div class="page-heading">
            <h1>Add Product</h1>
            <p>Add a new product to your inventory.</p>
        </div>

        <section class="form-card">

            <h2>Product Details</h2>

            <p class="card-description">
                Enter the product name, selling price, and available stock.
            </p>

            <?php if ($error_message !== ""): ?>
                <div class="error-message" role="alert">
                    <?php
                    echo htmlspecialchars(
                        $error_message,
                        ENT_QUOTES,
                        "UTF-8"
                    );
                    ?>
                </div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data">

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

                <div class="form-group">
                    <label for="product_name">Product Name</label>

                    <input
                        type="text"
                        id="product_name"
                        name="product_name"
                        value="<?php
                        echo htmlspecialchars(
                            $product_name,
                            ENT_QUOTES,
                            "UTF-8"
                        );
                        ?>"
                        placeholder="e.g. Ballpen"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="price">Price (₱)</label>

                    <input
                        type="number"
                        id="price"
                        name="price"
                        min="0"
                        step="0.01"
                        value="<?php
                        echo htmlspecialchars(
                            (string) $price_input,
                            ENT_QUOTES,
                            "UTF-8"
                        );
                        ?>"
                        placeholder="e.g. 25.50"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="stock">Stock Quantity</label>

                    <input
                        type="number"
                        id="stock"
                        name="stock"
                        min="0"
                        step="1"
                        value="<?php
                        echo htmlspecialchars(
                            (string) $stock_input,
                            ENT_QUOTES,
                            "UTF-8"
                        );
                        ?>"
                        placeholder="e.g. 100"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="product_image">Product Picture</label>

                    <input
                        type="file"
                        id="product_image"
                        name="product_image"
                        accept="image/jpeg, image/png, image/webp"
                    >

                    <small>
                        Accepted formats: JPG, PNG, or WebP.
                    </small>
                </div>


                <div class="button-group">

                    <button type="submit" class="btn btn-primary">
                        Add Product
                    </button>

                    <a href="products.php" class="btn btn-secondary">
                        Cancel
                    </a>

                </div>

            </form>

        </section>

    </main>

</body>
</html>