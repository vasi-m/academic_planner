<?php
//Start the session to track the logged-in user
session_start();
//Ensure database connection
require "db.php";

// Redirect if user not logged in
if (!isset($_SESSION["user_id"])) {
    header("Location: index.php");
    exit();
}

$user_id = $_SESSION["user_id"];

// Load existing preferences if they are set before
$query = $conn->prepare("
    SELECT * 
    FROM study_preferences 
    WHERE user_id = ?
");
$query->bind_param("i", $user_id);
$query->execute();
$result = $query->get_result();
$preferences = $result->fetch_assoc();

//Handle form submission
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $start_time = $_POST["start_time"];
    $end_time = $_POST["end_time"];
    $max_hours = $_POST["max_hours"];
    $session_length = $_POST["session_length"];

    // Validate time range
    if (strtotime($start_time) >= strtotime($end_time)) {
        $_SESSION["error"] = "End time must be later than start time.";
        header("Location: study_preferences.php");
        exit();
    }

    if ($preferences) {
        // update existing preferences
        $query = $conn->prepare("
            UPDATE study_preferences
            SET start_time = ?, end_time = ?, max_hours = ?, session_length = ?
            WHERE user_id = ?
        ");
        $query->bind_param("ssiii", $start_time, $end_time, $max_hours, $session_length, $user_id);

    } else {
        // insert new preferences
        $query = $conn->prepare("
            INSERT INTO study_preferences
            (user_id, start_time, end_time, max_hours, session_length)
            VALUES (?, ?, ?, ?, ?)
        ");
        $query->bind_param("issii", $user_id, $start_time, $end_time, $max_hours, $session_length);
    }

    $query->execute();

    header("Location: study_preferences.php");
    exit();
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Study Preferences</title>
    <link rel="stylesheet" href="assets/app.css">
</head>

<body>
<div class="container">
    <!-- Navigation bar -->
    <div class="navbar">
        <h2>Academic Planner</h2>
        <div>
            <a href="dashboard.php">Dashboard</a>
            <a href="timetable.php">Timetable</a>
            <a href="tasks.php">Tasks</a>
            <a href="study_preferences.php" class="active">Preferences</a>
            <a href="planner.php">Planner</a>
            <a href="logout.php">Logout</a>
        </div>
    </div>
    <!-- Form container -->
    <div class="card" style="max-width:500px; margin:auto;">

        <h2>Study Preferences</h2>
        <p class="page-description">
            Set your preferred study hours and session length. These settings help the planner create a study schedule that fits your daily routine.
        </p>
        <!-- Form input fields -->
        <form method="POST">

            <label>Available Start Time</label>
            <input type="time" name="start_time"
                   value="<?= $preferences['start_time'] ?? '' ?>" required>

            <label>Available End Time</label>
            <input type="time" name="end_time"
                   value="<?= $preferences['end_time'] ?? '' ?>" required>

            <label>Max Study Hours Per Day</label>
            <input type="number" name="max_hours" min="1"
                   value="<?= $preferences['max_hours'] ?? '' ?>" required>

            <label>Session Length (hours)</label>
            <input type="number" name="session_length" min="1"
                   value="<?= $preferences['session_length'] ?? '' ?>" required>

            <button type="submit">Save Preferences</button>

            <?php if (isset($_SESSION["error"])): ?>
                <div class="error-message" style="color:#a30000; padding:10px; margin-top:10px; font-size:13px;">
                    <?= $_SESSION["error"]; ?>
                </div>
                <?php unset($_SESSION["error"]); ?>
            <?php endif; ?>

        </form>

    </div>

</div>
</body>
</html>
