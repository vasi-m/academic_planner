<?php
session_start();
require "db.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: index.php");
    exit();
}

$user_id = $_SESSION["user_id"];

$plan = [];
$error = "";
$generated = false;

/* =========================
   DELETE SESSION
========================= */
if (isset($_GET["delete"])) {

    $id = $_GET["delete"];

    $stmt = $conn->prepare("DELETE FROM study_plan WHERE id=? AND user_id=?");
    $stmt->bind_param("ii", $id, $user_id);
    $stmt->execute();

    header("Location: planner.php");
    exit();
}

/* =========================
   UPDATE SESSION
========================= */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["update"])) {

    $id = $_POST["plan_id"];
    $start = $_POST["start_time"];
    $end = $_POST["end_time"];

    $stmt = $conn->prepare("
        UPDATE study_plan
        SET start_time=?, end_time=?
        WHERE id=? AND user_id=?
    ");
    $stmt->bind_param("ssii", $start, $end, $id, $user_id);
    $stmt->execute();

    header("Location: planner.php");
    exit();
}

/* =========================
   GENERATE PLAN
========================= */
if (isset($_POST["generate"])) {

    $generated = true;

    $stmt = $conn->prepare("DELETE FROM study_plan WHERE user_id=?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();

    /* GET PREFERENCES */
    $stmt = $conn->prepare("SELECT * FROM study_preferences WHERE user_id=?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $pref = $stmt->get_result()->fetch_assoc();

    if (!$pref) {
        $error = "Please set preferences first.";
    } else {

        $start_time = $pref['start_time'];
        $end_time = $pref['end_time'];
        $max_hours = $pref['max_hours'];
        $session_length = $pref['session_length'];

        /* GET TASKS */
        $stmt = $conn->prepare("
            SELECT *,
            CASE 
                WHEN priority = 'High' THEN 3
                WHEN priority = 'Medium' THEN 2
                WHEN priority = 'Low' THEN 1
            END AS priority_value
            FROM tasks
            WHERE user_id=? AND status IN ('pending','in_progress')
            ORDER BY deadline ASC, priority_value DESC
        ");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $tasks = [];
        while ($row = $result->fetch_assoc()) {
            $row['remaining_time'] = $row['estimated_time'];
            $tasks[] = $row;
        }

        if (empty($tasks)) {
            $error = "Add tasks first.";
        } else {

            /* GET TIMETABLE */
            $stmt = $conn->prepare("SELECT * FROM timetable WHERE user_id=?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $res = $stmt->get_result();

            $busy = [];
            while ($row = $res->fetch_assoc()) {
                $busy[$row['day_of_week']][] = $row;
            }

            /* GENERATE DATES (7 DAYS) */
            $dates = [];
            $today = new DateTime();

            for ($i = 0; $i < 7; $i++) {
                $date = clone $today;
                $date->modify("+$i day");

                $dates[] = [
                    "day" => $date->format("l"),
                    "display" => $date->format("D d M"),
                    "full" => $date->format("Y-m-d")
                ];
            }

            /* HELPERS */
            function addHours($t, $h) {
                return date("H:i", strtotime("+$h hours", strtotime($t)));
            }

            function addMinutes($t, $m) {
                return date("H:i", strtotime("+$m minutes", strtotime($t)));
            }

            function isBusy($day, $start, $end, $busy) {
                if (!isset($busy[$day])) return false;
                foreach ($busy[$day] as $b) {
                    if ($start < $b['end_time'] && $end > $b['start_time']) {
                        return true;
                    }
                }
                return false;
            }

            /* GENERATE */
            $task_index = 0;
            $total_tasks = count($tasks);

            foreach ($dates as $d) {

                $day = $d['day'];
                $study_date = $d['full'];

                $current_time = $start_time;
                $used_hours = 0;
                $loop_guard = 0;

                while ($used_hours < $max_hours && $total_tasks > 0) {

                    $loop_guard++;
                    if ($loop_guard > 500) break;

                    $task = &$tasks[$task_index];

                    if ($task['remaining_time'] <= 0) {
                        $task_index = ($task_index + 1) % $total_tasks;
                        continue;
                    }

                    $duration = min($session_length, $task['remaining_time']);
                    $end = addHours($current_time, $duration);

                    if ($end > $end_time) break;

                    if (isBusy($day, $current_time, $end, $busy)) {
                        $current_time = addMinutes($current_time, 30);
                        continue;
                    }

                    /* SAVE */
                    $stmt = $conn->prepare("
                        INSERT INTO study_plan
                        (user_id, task_id, task_title, day_of_week, study_date, start_time, end_time, session_duration)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->bind_param(
                        "iisssssi",
                        $user_id,
                        $task['task_id'],
                        $task['title'],
                        $day,
                        $study_date,
                        $current_time,
                        $end,
                        $duration
                    );
                    $stmt->execute();

                    $task['remaining_time'] -= $duration;
                    $used_hours += $duration;

                    $current_time = addMinutes($end, 30);
                    $task_index = ($task_index + 1) % $total_tasks;
                }
            }
        }
    }
}

/* =========================
   LOAD PLAN FROM DB
========================= */
$stmt = $conn->prepare("SELECT * FROM study_plan WHERE user_id=? ORDER BY study_date, start_time");
$stmt->bind_param("i", $user_id);
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
        <div>
            <a href="dashboard.php">Dashboard</a>
            <a href="timetable.php">Timetable</a>
            <a href="tasks.php">Tasks</a>
            <a href="study_preferences.php">Preferences</a>
            <a href="planner.php" class="active">Planner</a>
            <a href="logout.php">Logout</a>
        </div>
    </div>

    <div class="card">

        <h2>Study Planner</h2>

        <form method="POST">
            <button name="generate">Generate Study Plan</button>
        </form>

        <br>

        <?php if ($error): ?>
            <p style="color:red;"><?php echo $error; ?></p>
        <?php endif; ?>

        <div class="calendar">

        <?php foreach ($plan as $date => $sessions): ?>

            <div class="day-column">

                <div class="header">
                    <?php echo date("D d M", strtotime($date)); ?>
                </div>

                <?php foreach ($sessions as $s): ?>

                    <div class="event">

                        <strong><?php echo $s['task_title']; ?></strong><br>
                        <?php echo $s['start_time']; ?> - <?php echo $s['end_time']; ?>

                        <div class="actions">

                            <!-- EDIT -->
                            <form method="POST" style="display:flex; gap:5px;">
                                <input type="hidden" name="plan_id" value="<?php echo $s['id']; ?>">
                                <input type="time" name="start_time" value="<?php echo $s['start_time']; ?>">
                                <input type="time" name="end_time" value="<?php echo $s['end_time']; ?>">
                                <button name="update" class="edit">Save</button>
                            </form>

                            <!-- DELETE -->
                            <a class="delete" href="?delete=<?php echo $s['id']; ?>">Delete</a>

                        </div>

                    </div>

                <?php endforeach; ?>

            </div>

        <?php endforeach; ?>

        </div>

    </div>

</div>

</body>
</html>