<?php
session_start();
require "db.php";

/* DEBUG */
ini_set('display_errors', 1);
error_reporting(E_ALL);

if (!isset($_SESSION["user_id"])) {
    header("Location: index.php");
    exit();
}

$user_id = $_SESSION["user_id"];
$plan = [];
$error = "";

/* update errors */
$update_errors = $_SESSION["update_errors"] ?? [];
unset($_SESSION["update_errors"]);

/* =========================================================
   GET PLAN START DATE FROM DB (SAFE FIX)
========================================================= */
$stmt = $conn->prepare("
    SELECT plan_start_date FROM study_plan_info WHERE user_id=?
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();

$row = $res->fetch_assoc();

/* ✅ FIX: prevent crash if no row */
if ($row && isset($row['plan_start_date'])) {
    $start = $row['plan_start_date'];
} else {
    $start = date("Y-m-d");
}

/* =========================================================
   GENERATE / REGENERATE PLAN
========================================================= */
if (isset($_POST["generate_plan"])) {

    $today = new DateTime();
    $plan_start = $today->format("Y-m-d");

    // ✅ SAVE PLAN START DATE IN DB
    $stmt = $conn->prepare("
        REPLACE INTO study_plan_info (user_id, plan_start_date)
        VALUES (?, ?)
    ");
    $stmt->bind_param("is", $user_id, $plan_start);
    $stmt->execute();

    $week_start = $plan_start;
    $week_end = (new DateTime($week_start))->modify("+6 days")->format("Y-m-d");

    // delete old plan
    // delete ALL old sessions (FIX)
    $stmt = $conn->prepare("
    DELETE FROM study_plan
    WHERE user_id=?
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();

    // preferences
    $stmt = $conn->prepare("SELECT * FROM study_preferences WHERE user_id=?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $pref = $stmt->get_result()->fetch_assoc();

    if (!$pref) {
        $error = "Set preferences first.";
    } else {

        $start_time = $pref['start_time'];
        $end_time = $pref['end_time'];
        $max_hours = $pref['max_hours'];
        $session_length = $pref['session_length'];

        // tasks
        $stmt = $conn->prepare("
            SELECT *,
            CASE WHEN priority='High' THEN 3
                 WHEN priority='Medium' THEN 2
                 ELSE 1 END AS priority_value
            FROM tasks
            WHERE user_id=? AND status IN ('pending','in_progress')
            ORDER BY deadline ASC, priority_value DESC
        ");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();

        $tasks = [];
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $tasks[] = $row;
        }

        // timetable
        $stmt = $conn->prepare("SELECT * FROM timetable WHERE user_id=?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();

        $busy = [];
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $busy[$row['day_of_week']][] = $row;
        }

        // dates
        $dates = [];
        for ($i = 0; $i < 7; $i++) {
            $date = (clone new DateTime($plan_start))->modify("+$i day");
            $dates[] = [
                "day" => $date->format("l"),
                "full" => $date->format("Y-m-d")
            ];
        }

        // tracking
        $day_time = [];
        $day_usage = [];

        foreach ($dates as $d) {
            $day_time[$d['full']] = $start_time;
            $day_usage[$d['full']] = 0;
        }

        // existing plan
        $stmt = $conn->prepare("
            SELECT study_date, start_time, end_time
            FROM study_plan
            WHERE user_id=?
            AND study_date BETWEEN ? AND ?
        ");
        $stmt->bind_param("iss", $user_id, $week_start, $week_end);
        $stmt->execute();

        $res = $stmt->get_result();
        $existing = [];

        while ($row = $res->fetch_assoc()) {
            $existing[$row['study_date']][] = $row;
        }

        // generation loop
        foreach ($tasks as $task) {

            $remaining = $task['estimated_time'];
            $dayIndex = 0;
            $attempts = 0;

            while ($remaining > 0 && $attempts < 1000) {

                $attempts++;

                $d = $dates[$dayIndex % 7];
                $date = $d['full'];
                $day = $d['day'];

                $start_time_slot = $day_time[$date];

                $dur = min($session_length, $remaining);

                $end_ts = strtotime("+$dur hours", strtotime($start_time_slot));
                $end = date("H:i", $end_ts);

                if ($end > $end_time) {
                    $dayIndex++;
                    continue;
                }

                if ($day_usage[$date] + $dur > $max_hours) {
                    $dayIndex++;
                    continue;
                }

                $conflict = false;

                if (!empty($busy[$day])) {
                    foreach ($busy[$day] as $b) {
                        if ($start_time_slot < $b['end_time'] && $end > $b['start_time']) {
                            $conflict = true;
                            break;
                        }
                    }
                }

                if (!empty($existing[$date])) {
                    foreach ($existing[$date] as $s) {
                        if ($start_time_slot < $s['end_time'] && $end > $s['start_time']) {
                            $conflict = true;
                            break;
                        }
                    }
                }

                if ($conflict) {
                    $day_time[$date] = date("H:i", strtotime($start_time_slot . " +30 minutes"));
                    continue;
                }

                // insert
                $stmt = $conn->prepare("
                    INSERT INTO study_plan
                    (user_id, task_id, task_title, day_of_week, study_date, start_time, end_time, session_duration)
                    VALUES (?,?,?,?,?,?,?,?)
                ");

                $stmt->bind_param(
                    "iisssssi",
                    $user_id,
                    $task['task_id'],
                    $task['title'],
                    $day,
                    $date,
                    $start_time_slot,
                    $end,
                    $dur
                );

                $stmt->execute();

                $day_time[$date] = date("H:i", strtotime($end . " +30 minutes"));
                $day_usage[$date] += $dur;

                $remaining -= $dur;
                $dayIndex++;
            }
        }

        // refresh start
        $start = $plan_start;
    }
}

/* =========================================================
   LOAD PLAN
========================================================= */
$end = (new DateTime($start))->modify("+6 days")->format("Y-m-d");

$stmt = $conn->prepare("
    SELECT * FROM study_plan
    WHERE user_id=?
    AND study_date BETWEEN ? AND ?
    ORDER BY study_date, start_time
");
$stmt->bind_param("iss", $user_id, $start, $end);
$stmt->execute();

$res = $stmt->get_result();

while ($row = $res->fetch_assoc()) {
    $plan[$row['study_date']][] = $row;
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Planner</title>
    <link rel="stylesheet" href="assets/app.css">
</head>

<body>

<div class="container">

<div class="topbar">
    <h2>Academic Planner</h2>
    <a href="dashboard.php">Dashboard</a>
    <a href="timetable.php">Timetable</a>
    <a href="tasks.php">Tasks</a>
    <a href="study_preferences.php">Preferences</a>
    <a href="planner.php">Planner</a>
</div>

<div class="card">

<h2>Study Planner</h2>

<form method="POST">
    <button name="generate_plan"
        onclick="return confirm('This will replace your current plan. Continue?')">
        Generate / Regenerate Plan
    </button>
</form>

<?php if ($error): ?>
<p style="color:red;"><?= $error ?></p>
<?php endif; ?>

<div class="calendar">

<?php
for ($i = 0; $i < 7; $i++) {
    $date = (clone new DateTime($start))->modify("+$i day");
    $d = $date->format("Y-m-d");
    $sessions = $plan[$d] ?? [];
?>

<div class="day-column">
<div class="header"><?= $date->format("D d M") ?></div>

<?php foreach ($sessions as $s): ?>

<form method="POST" action="update_session.php" class="event">

<input type="hidden" name="id" value="<?= $s['id'] ?>">

<div class="task-title">
    <?= htmlspecialchars($s['task_title']) ?>
</div>

<input type="date" name="study_date" value="<?= $s['study_date'] ?>">

<input type="time" name="start_time" value="<?= $s['start_time'] ?>">

<input type="time" name="end_time" value="<?= $s['end_time'] ?>">

<button type="submit">Update</button>

<?php if (!empty($update_errors[$s['id']])): ?>
    <p style="color:red; margin:5px 0;">
        <?= $update_errors[$s['id']] ?>
    </p>
<?php endif; ?>

</form>

<?php endforeach; ?>

</div>

<?php } ?>

</div>

</div>
</div>

</body>
</html>
