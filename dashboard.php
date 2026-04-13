<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: index.php");
    exit();
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Dashboard</title>
    <link rel="stylesheet" href="assets/app.css">
</head>

<body class="app">

<!-- NAVBAR -->
<div class="navbar">
    <div><strong>Academic Planner</strong></div>

    <div>
        Welcome, <?php echo $_SESSION["full_name"]; ?> |
        <a href="logout.php">Logout</a>
    </div>
</div>

<!-- MAIN -->
<div class="main">

    <div class="card">
        <h2>Dashboard</h2>
        <p>Quick actions:</p>

        <br>

        <a href="timetable.php"><button class="btn">Add Timetable</button></a>
        <a href="tasks.php"><button class="btn">Add Tasks</button></a>
        <button class="btn">Generate Plan</button>
    </div>

</div>

</body>
</html>