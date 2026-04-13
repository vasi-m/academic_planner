<?php
session_start();
require "db.php";

$message = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $action = $_POST["action"];

    // ---------------- REGISTER ----------------
    if ($action == "register") {

    $full_name = $_POST["full_name"];
    $email = $_POST["email"];
    $password = $_POST["password"];

    // EMAIL VALIDATION
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "Invalid email format!";
    } else {

        // DNS CHECK
        $domain = substr(strrchr($email, "@"), 1);

        if (!checkdnsrr($domain, "MX")) {
            $message = "Email domain does not exist!";
        } else {

            if (strlen($password) < 8) {
                $message = "Password must be at least 8 characters!";
            } else {

                // CHECK EMAIL (kept simple as you had it)
                $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
                $stmt->bind_param("s", $email);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows > 0) {
                    $message = "Email already exists!";
                } else {

                    // HASH PASSWORD
                    $hashed = password_hash($password, PASSWORD_DEFAULT);

                    // INSERT USER
                    $stmt = $conn->prepare("
                        INSERT INTO users (full_name, email, password, is_active)
                        VALUES (?, ?, ?, 0)
                    ");
                    $stmt->bind_param("sss", $full_name, $email, $hashed);
                    $stmt->execute();

                    // GET USER ID
                    $user_id = $conn->insert_id;

                    // GENERATE TOKEN
                    $token = bin2hex(random_bytes(32));

                    // DELETE OLD TOKENS (IMPORTANT FIX)
                    $stmt = $conn->prepare("DELETE FROM email_tokens WHERE user_id = ?");
                    $stmt->bind_param("i", $user_id);
                    $stmt->execute();

                    // INSERT NEW TOKEN (FIXED)
                    $stmt = $conn->prepare("
                        INSERT INTO email_tokens (user_id, token)
                        VALUES (?, ?)
                    ");
                    $stmt->bind_param("is", $user_id, $token);
                    $stmt->execute();

                    // ACTIVATION LINK
                    $message = "
                    <div>
                        <p>Activation link (demo):</p>
                        <a href='http://localhost/academic_planner/activate.php?token=$token' target='_blank'>
                            Click here to activate account
                        </a>
                    </div>";
                }
            }
        }
    }
}

    // ---------------- LOGIN ----------------
    if ($action == "login") {

        $email = $_POST["email"];
        $password = $_POST["password"];

        $result = $conn->query("SELECT * FROM users WHERE email='$email'");

        if ($result->num_rows == 1) {

            $user = $result->fetch_assoc();

            if (!$user["is_active"]) {
                $message = "Please activate your account first!";
            } else {

                if (password_verify($password, $user["password"])) {

                    $_SESSION["user_id"] = $user["user_id"];
                    $_SESSION["full_name"] = $user["full_name"];

                    header("Location: dashboard.php");
                    exit();

                } else {
                    $message = "Incorrect password!";
                }
            }

        } else {
            $message = "User not found!";
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Academic Planner</title>
    <link rel="stylesheet" href="assets/style.css">
</head>

<body>

<div class="container">

    <!-- LEFT -->
    <div class="left">
        <h1>Academic Planner</h1>
        <p>Manage your study schedule efficiently</p>
    </div>

    <!-- RIGHT -->
    <div class="right">

        <div class="box">

            <!-- TABS -->
            <div class="tabs">
                <button class="tab active" onclick="showForm('register')">Register</button>
                <button class="tab" onclick="showForm('login')">Login</button>
            </div>

            <p class="msg"><?php echo $message; ?></p>

            <!-- REGISTER FORM -->
            <form id="registerForm" method="POST">
                <input type="hidden" name="action" value="register">

                <input type="text" name="full_name" placeholder="Full Name" required>
                <input type="email" name="email" placeholder="Email" required>

                <input type="password" id="password" name="password" placeholder="Password" required>

                <!-- PASSWORD STRENGTH -->
                <div id="strengthContainer" style="display:none;">

                    <div>Password Strength: <span id="strengthLabel">Weak</span></div>

                    <div class="strength-bar">
                        <div id="strength"></div>
                    </div>

                    <!-- CHECKLIST (PRO VERSION) -->
                    <ul class="checklist">

                        <li id="lengthCheck">
                            <span class="icon">❌</span> At least 8 characters
                        </li>

                        <li id="upperCheck">
                            <span class="icon">❌</span> One uppercase letter
                        </li>

                        <li id="numberCheck">
                            <span class="icon">❌</span> One number
                        </li>

                        <li id="specialCheck">
                            <span class="icon">❌</span> One special character (@$!%*?&.) 
                        </li>

                    </ul>

                </div>

                <button type="submit">Register</button>
            </form>

            <!-- LOGIN FORM -->
            <form id="loginForm" method="POST" style="display:none;">
                <input type="hidden" name="action" value="login">

                <input type="email" name="email" placeholder="Email" required>
                <input type="password" name="password" placeholder="Password" required>

                <button type="submit">Login</button>
            </form>

        </div>

    </div>

</div>

<script>
// TAB SWITCH
function showForm(type) {

    const login = document.getElementById("loginForm");
    const register = document.getElementById("registerForm");

    const tabs = document.querySelectorAll(".tab");

    if (type === "login") {
        login.style.display = "block";
        register.style.display = "none";

        tabs[1].classList.add("active");
        tabs[0].classList.remove("active");
    } else {
        login.style.display = "none";
        register.style.display = "block";

        tabs[0].classList.add("active");
        tabs[1].classList.remove("active");
    }
}

// PASSWORD CHECKLIST SYSTEM
document.addEventListener("DOMContentLoaded", function () {

    const password = document.getElementById("password");
    const strengthBar = document.getElementById("strength");
    const strengthLabel = document.getElementById("strengthLabel");
    const strengthContainer = document.getElementById("strengthContainer");

    const lengthCheck = document.getElementById("lengthCheck");
    const upperCheck = document.getElementById("upperCheck");
    const numberCheck = document.getElementById("numberCheck");
    const specialCheck = document.getElementById("specialCheck");

    if (!password) return;

    password.addEventListener("input", function () {

        let value = password.value;

        // SHOW/HIDE
        if (value.length > 0) {
            strengthContainer.style.display = "block";
        } else {
            strengthContainer.style.display = "none";
            strengthBar.style.width = "0%";
            return;
        }

        // RULES
        let hasLength = value.length >= 8;
        let hasUpper = /[A-Z]/.test(value);
        let hasNumber = /[0-9]/.test(value);
        let hasSpecial = /[@$!%*?&.]/.test(value);

        updateRule(lengthCheck, hasLength);
        updateRule(upperCheck, hasUpper);
        updateRule(numberCheck, hasNumber);
        updateRule(specialCheck, hasSpecial);

        let strength = 0;
        if (hasLength) strength++;
        if (hasUpper) strength++;
        if (hasNumber) strength++;
        if (hasSpecial) strength++;

        // BAR
        if (strength <= 1) {
            strengthBar.style.width = "25%";
            strengthBar.style.background = "red";
            strengthLabel.innerText = "Weak";
        } else if (strength == 2) {
            strengthBar.style.width = "50%";
            strengthBar.style.background = "orange";
            strengthLabel.innerText = "Medium";
        } else if (strength == 3) {
            strengthBar.style.width = "75%";
            strengthBar.style.background = "green";
            strengthLabel.innerText = "Good";
        } else {
            strengthBar.style.width = "100%";
            strengthBar.style.background = "darkgreen";
            strengthLabel.innerText = "Strong";
        }

    });

    // CLEAN ICON UPDATE METHOD
    function updateRule(element, condition) {

        const icon = element.querySelector(".icon");

        if (condition) {
            icon.textContent = "✔";
            element.classList.add("valid");
        } else {
            icon.textContent = "❌";
            element.classList.remove("valid");
        }
    }

});
</script>

</body>
</html>