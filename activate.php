<?php
require "db.php";

$message = "";

if (!isset($_GET["token"])) {
    die("Invalid activation link.");
}

$token = $_GET["token"];

// FIND USER ID FROM TOKEN
$stmt = $conn->prepare("
    SELECT user_id FROM email_tokens WHERE token = ?
");
$stmt->bind_param("s", $token);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 1) {

    $row = $result->fetch_assoc();
    $user_id = $row["user_id"];

    // ACTIVATE USER
    $stmt = $conn->prepare("
        UPDATE users SET is_active = 1 WHERE user_id = ?
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();

    // DELETE TOKEN
    $stmt = $conn->prepare("
        DELETE FROM email_tokens WHERE token = ?
    ");
    $stmt->bind_param("s", $token);
    $stmt->execute();

    $message = "✅ Account successfully activated! You can now log in.";

} else {
    $message = "❌ Invalid or expired activation link.";
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Activate Account</title>
    <link rel="stylesheet" href="assets/style.css">
</head>

<body>

<div class="container">

    <div class="box" style="margin: 100px auto; text-align:center;">

        <h2>Account Activation</h2>

        <p><?php echo $message; ?></p>

        <br>

        <a href="index.php">
            <button type="button">Go to Login</button>
        </a>

    </div>

</div>

</body>
</html>