<nav class="navbar">

    <div class="navbar-brand">
        <img src="logo/Inventory.png"
             alt="Inventory tools Logo"
             class="navbar-logo">

        <span class="navbar-business-name">
            Inventory tools
        </span>
    </div>


    <a href="dashboard.php"
       class="<?php echo basename($_SERVER['PHP_SELF']) === 'dashboard.php' ? 'active' : ''; ?>">
        Dashboard
    </a>

    <a href="products.php"
       class="<?php echo basename($_SERVER['PHP_SELF']) === 'products.php' ? 'active' : ''; ?>">
        Products
    </a>

    <a href="sales.php"
       class="<?php echo basename($_SERVER['PHP_SELF']) === 'sales.php' ? 'active' : ''; ?>">
        Make a Sale
    </a>

    <a href="sales_history.php"
       class="<?php echo basename($_SERVER['PHP_SELF']) === 'sales_history.php' ? 'active' : ''; ?>">
        Sales History
    </a>

<div class="dropdown">

    <button type="button" class="dropdown-button">
        Store Reports &#9662;
    </button>

    <div class="dropdown-content">

        <div class="submenu">

            <button type="button" class="submenu-button">
                Reports &#9656;
            </button>

            <div class="submenu-content">

                <a href="reports.php">
                    Daily Summary Reports
                </a>

                <a href="monthly_reports.php">
                    Monthly Sales Reports
                </a>

            </div>

        </div>

    </div>

</div>

<div class="nav-profile">

    <button type="button"
            class="nav-profile-button"
            id="navProfileButton"
            aria-expanded="false"
            aria-controls="navProfileMenu">

        <span class="nav-profile-icon">
            <svg xmlns="http://www.w3.org/2000/svg"
                 width="22"
                 height="22"
                 viewBox="0 0 24 24"
                 fill="none"
                 stroke="currentColor"
                 stroke-width="2"
                 stroke-linecap="round"
                 stroke-linejoin="round">
                <path d="M20 21a8 8 0 0 0-16 0"/>
                <circle cx="12" cy="8" r="4"/>
            </svg>
        </span>

        <span class="nav-profile-name">
            <strong>Administrator</strong>
            <small>
                <?php echo htmlspecialchars(
                    $_SESSION["username"],
                    ENT_QUOTES,
                    "UTF-8"
                ); ?>
            </small>
        </span>

        <span class="nav-profile-arrow">&#9662;</span>

    </button>

    <div class="nav-profile-menu"
         id="navProfileMenu"
         hidden>

        <a href="change_password.php">
            &#128274; Change Password
        </a>

        <form action="logout.php" method="POST">
            <input
                type="hidden"
                name="csrf_token"
                value="<?php echo htmlspecialchars(
                    $_SESSION["csrf_token"],
                    ENT_QUOTES,
                    "UTF-8"
                ); ?>"
            >

            <button type="submit" class="nav-logout-button">
                &#10162; Log Out
            </button>
        </form>

    </div>

</div>

</nav>

<script>

document.addEventListener("DOMContentLoaded", function () {
    const profileButton = document.getElementById("navProfileButton");
    const profileMenu = document.getElementById("navProfileMenu");

    if (!profileButton || !profileMenu) {
        return;
    }

    profileButton.addEventListener("click", function (event) {
        event.stopPropagation();

        profileMenu.hidden = !profileMenu.hidden;

        profileButton.setAttribute(
            "aria-expanded",
            String(!profileMenu.hidden)
        );
    });

    document.addEventListener("click", function (event) {
        if (!event.target.closest(".nav-profile")) {
            profileMenu.hidden = true;
            profileButton.setAttribute("aria-expanded", "false");
        }
    });

    document.addEventListener("keydown", function (event) {
        if (event.key === "Escape") {
            profileMenu.hidden = true;
            profileButton.setAttribute("aria-expanded", "false");
            profileButton.focus();
        }
    });
});

</script>
