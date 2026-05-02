<?php
//Start the session to track the logged-in user
session_start();
//Ensure database connection
require "db.php";

$message = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $action = $_POST["action"];

    //if user wants to register
    if ($action === "register") {

        $full_name = trim($_POST["full_name"]);
        $email = trim($_POST["email"]);
        $password = $_POST["password"];

        //if email is not valid, show the error message
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = "Invalid email format!";
            $register_error = true;
        } 
        else if (strlen($password) < 8) {
            $message = "Password must be at least 8 characters!";
        } 
        else {

            //Check if email exists
            $query = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
            $query->bind_param("s", $email);
            $query->execute();
            $result = $query->get_result();

            if ($query->num_rows > 0) {
                $message = "Email already exists!";
            } else {

                // HASH PASSWORD
                $hashed = password_hash($password, PASSWORD_DEFAULT);

                // INSERT USER
                $query = $conn->prepare("
                    INSERT INTO users (full_name, email, password)
                    VALUES (?, ?, ?)
                ");
                $query->bind_param("sss", $full_name, $email, $hashed);
                $query->execute();

                $message = "Registration successful! You can now log in.";
                $registered = true;
            }
        }
    }

    //When user wants to log in
    if ($action === "login") {
        
        $email = trim($_POST["email"]);
        $password = $_POST["password"];
        //query to check if the user has an account
        $query = $conn->prepare("SELECT * FROM users WHERE email = ?");
        $query->bind_param("s", $email);
        $query->execute();
        $result = $query->get_result();

        if ($result->num_rows === 1) {

            $user = $result->fetch_assoc();
            //if the user has an account
            if (password_verify($password, $user["password"])) {

                $_SESSION["user_id"] = $user["user_id"];
                $_SESSION["full_name"] = $user["full_name"];
                
                //Load the dashboard page
                header("Location: dashboard.php");
                exit();
            //If the password is incorrect, show message
            } else {
                $message = "Incorrect password!";
                $login_error = true;
            }
        //If the account doesn't exist, show message
        } else {
            $message = "User not found!";
            $login_error = true;
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Academic Planner</title>
    <link rel="stylesheet" href="assets/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>

<body>

<div class="container">

    <!-- LEFT -->
    <div class="left">
        <h1>Academic Planner</h1>
        <p>Welcome to Academic Planner — your space to stay organised, balanced, and in control of your studies. Add your timetable, track your tasks, and let the planner build a weekly study plan that works around your fixed commitments. Create an account or log in to get started.</p>
    </div>

    <!-- RIGHT -->
    <div class="right">

        <div class="box">

            <!-- Register and Login Tabs -->
            <div class="tabs">
                <button class="tab active" onclick="showForm('register')">Register</button>
                <button class="tab" onclick="showForm('login')">Login</button>
            </div>

            <p class="msg"><?php echo $message; ?></p>

            <!-- Register Form -->
            <form id="registerForm" method="POST">
                <input type="hidden" name="action" value="register">

                <input type="text" name="full_name" placeholder="Full Name" required>
                <input type="email" name="email" placeholder="Email" required>

                <input type="password" id="password" name="password" placeholder="Password" required>

                <!--Password Strength meter -->
                <div id="strengthContainer" style="display:none;">

                    <div>Password Strength: <span id="strengthLabel">Weak</span></div>

                    <div class="strength-bar">
                        <div id="strength"></div>
                    </div>

                    <ul class="checklist">
                        <li id="lengthCheck"><i class="fa-solid fa-xmark icon"></i> At least 8 characters</li>
                        <li id="upperCheck"><i class="fa-solid fa-xmark icon"></i> One uppercase letter</li>
                        <li id="numberCheck"><i class="fa-solid fa-xmark icon"></i> One number</li>
                        <li id="specialCheck"><i class="fa-solid fa-xmark icon"></i> One special character (@$!%*?&.)</li>
                    </ul>

                </div>
                <!--Register button -->
                <button type="submit">Register</button>
            </form>

            <!-- Login Form -->
            <form id="loginForm" method="POST" style="display:none;">
                <input type="hidden" name="action" value="login">

                <input type="email" name="email" placeholder="Email" required>
                <input type="password" name="password" placeholder="Password" required>
                <!-- Login button--> 
                <button type="submit">Login</button>
            </form>

        </div>

    </div>

</div>

<script>
//Tab switch between Register and Login
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

//Password 
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

        //The rules for a strong password
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

        //The password strength bar
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
    //The icons in the rules updated as the user input the password
    function updateRule(element, condition) {

        const icon = element.querySelector(".icon");

        if (condition) {
            icon.classList.remove("fa-xmark");
            icon.classList.add("fa-check");
            element.classList.add("valid");
        } else {
            icon.classList.remove("fa-check");
            icon.classList.add("fa-xmark");
            element.classList.remove("valid");
        }
    }

});
</script>
<?php if (!empty($registered)): ?>
<script>
    // Switch to login tab after successful registration
    showForm('login');
</script>
<!-- If a user inputs wrong email or password, stay in login tab -->
<?php endif; ?>
<?php if (!empty($login_error)): ?>
<script>
    showForm('login');
</script>
<?php endif; ?>

<?php if (!empty($register_error)): ?>
<script>
    showForm('register');
</script>
<?php endif; ?>
</body>
</html>
