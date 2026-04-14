<?php
session_start();
require "db.php";

/* =========================
   PROTECT PAGE
========================= */
if (!isset($_SESSION["user_id"])) {
    header("Location: index.php");
    exit();
}

$user_id = $_SESSION["user_id"];

/* =========================
   LOAD EXISTING PREFERENCES
========================= */
$stmt = $conn->prepare("SELECT * FROM study_preferences WHERE user_id=?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$preferences = $result->fetch_assoc();

/* =========================
   SAVE (INSERT OR UPDATE)
========================= */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $start = $_POST["start_time"];
    $end = $_POST["end_time"];
    $max = $_POST["max_hours"];
    $session = $_POST["session_length"];
    $days = $_POST["study_days"];

    if ($preferences) {

        // UPDATE
        $stmt = $conn->prepare("
            UPDATE study_preferences
            SET start_time=?, end_time=?, max_hours=?, session_length=?, study_days=?
            WHERE user_id=?
        ");
        $stmt->bind_param("ssiisi", $start, $end, $max, $session, $days, $user_id);

    } else {

        // INSERT
        $stmt = $conn->prepare("
            INSERT INTO study_preferences
            (user_id, start_time, end_time, max_hours, session_length, study_days)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("isssis", $user_id, $start, $end, $max, $session, $days);
    }

    $stmt->execute();

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

    <div class="topbar">
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

    <div class="card" style="max-width:500px; margin:auto;">

        <h2>Study Preferences</h2>

        <form method="POST">

            <label>Available Start Time</label>
            <input type="time" name="start_time"
                value="<?php echo $preferences['start_time'] ?? ''; ?>" required>

            <label>Available End Time</label>
            <input type="time" name="end_time"
                value="<?php echo $preferences['end_time'] ?? ''; ?>" required>

            <label>Max Study Hours Per Day</label>
            <input type="number" name="max_hours" min="1"
                value="<?php echo $preferences['max_hours'] ?? ''; ?>" required>

            <label>Session Length (hours)</label>
            <input type="number" name="session_length" min="1"
                value="<?php echo $preferences['session_length'] ?? ''; ?>" required>

            <label>Study Days</label>
            <select name="study_days">
                <option value="all"
                    <?php if (($preferences['study_days'] ?? '') == "all") echo "selected"; ?>>
                    All Days
                </option>

                <option value="weekdays"
                    <?php if (($preferences['study_days'] ?? '') == "weekdays") echo "selected"; ?>>
                    Weekdays Only
                </option>

                <option value="weekends"
                    <?php if (($preferences['study_days'] ?? '') == "weekends") echo "selected"; ?>>
                    Weekends Only
                </option>
            </select>

            <button type="submit">Save Preferences</button>

        </form>

    </div>

</div>

</body>
</html>
