<?php
////Start the session to track the logged-in user
session_start();
//Ensure database connection and module colours pattern
require "db.php";
require "module_colours.php";

// Redirect if user not logged in
if (!isset($_SESSION["user_id"])) {
    header("Location: index.php");
    exit();
}

$user_id = $_SESSION["user_id"];

// Empty arrays to store the plan sessions and error messages
$study_plan = [];
$error_message = "";

//Retrieve session message when editing study sessions
$edit_error_message = $_SESSION["edit_error_message"] ?? [];
$edit_success_message = $_SESSION["edit_success_message"] ?? null;

// Remove session messages after fetching them
unset($_SESSION["edit_error_message"]);
unset($_SESSION["edit_success_message"]);

//Retrieve the most recent plan generation time
$query = $conn->prepare("
    SELECT MAX(sp.plan_generated_at) AS latest
    FROM study_plan sp
    JOIN tasks t ON sp.task_id = t.task_id
    JOIN modules m ON t.module_id = m.module_id
    WHERE m.user_id = ?
");
$query->bind_param("i", $user_id);
$query->execute();
$latest_plan_timestamp = $query->get_result()->fetch_assoc()['latest'];

//If no previous plan exists, the plan start date is the current date
if ($latest_plan_timestamp) {
    $plan_start_date = date("Y-m-d", strtotime($latest_plan_timestamp));
} else {
    $plan_start_date = date("Y-m-d");
}


//Generate new study plan
if (isset($_POST["generate_plan"])) {
    
    // The plan start date is the current day the user generates the plan.
    $today = new DateTime();
    $plan_start_date = $today->format("Y-m-d");
    $plan_generated_at = date("Y-m-d H:i:s");

    // Delete any previous plan sessions
    $query = $conn->prepare("
        DELETE sp
        FROM study_plan sp
        JOIN tasks t ON sp.task_id = t.task_id
        JOIN modules m ON t.module_id = m.module_id
        WHERE m.user_id = ?
    ");
    $query->bind_param("i", $user_id);
    $query->execute();

    //Retrieve user's preferences
    $query = $conn->prepare("SELECT * FROM study_preferences WHERE user_id=?");
    $query->bind_param("i", $user_id);
    $query->execute();
    $preferences = $query->get_result()->fetch_assoc();
    
    //Send error message if no preferences
    if (!$preferences) {
        $error_message = "Please, set your study preferences.";
    } else {
        //if preferences are set, retrieve its values
        $start_time = $preferences['start_time'];
        $end_time = $preferences['end_time'];
        $max_hours = $preferences['max_hours'];
        $session_length = $preferences['session_length'];

        //Retrieve only the tasks with status in pending and in progress.
        //Sort the tasks by their earliest deadline and then from the highest priority
        $query = $conn->prepare("
            SELECT t.*,
                CASE 
                    WHEN t.priority='High' THEN 3
                    WHEN t.priority='Medium' THEN 2
                    ELSE 1 
                END AS priority_value
            FROM tasks t
            JOIN modules m ON t.module_id = m.module_id
            WHERE m.user_id = ?
            AND t.status IN ('pending','in_progress')
            ORDER BY t.deadline ASC, priority_value DESC
        ");
        $query->bind_param("i", $user_id);
        $query->execute();
        $tasks = $query->get_result()->fetch_all(MYSQLI_ASSOC);

        // Retrieve the timetable event to use them as busy slots
        $query = $conn->prepare("
            SELECT te.*, m.module_name
            FROM timetable_events te
            JOIN modules m ON te.module_id = m.module_id
            WHERE m.user_id = ?
        ");
        $query->bind_param("i", $user_id);
        $query->execute();

        $busy_slots = [];
        $timetable_slots = $query->get_result();
        while ($slot = $timetable_slots->fetch_assoc()) {
            $busy_slots[$slot['day_of_week']][] = $slot;
        }

        //Generate study sessions
        //Record the day and date for each day in the weekly study plan
        $plan_dates = [];
        for ($i = 0; $i < 7; $i++) {
            $date = (clone new DateTime($plan_start_date))->modify("+$i day");
            $plan_dates[] = [
                "day" => $date->format("l"),
                "date" => $date->format("Y-m-d")
            ];
        }
        //Track the next available time and how many hours have been scheduled
        $available_time = [];
        $hours_allocated = [];
        
        //Each day's available time starts with the 
        foreach ($plan_dates as $d) {
            $available_time[$d['date']] = $start_time;
            $hours_allocated[$d['date']] = 0;
        }

        foreach ($tasks as $task) {

            $hours_remaining = $task['estimated_time'];
            $day_index = 0;
            $loop_attempts = 0;
            //Continue until all the required hours for a task are allocated
            while ($hours_remaining > 0 && $loop_attempts < 1000) {

                $loop_attempts++;
                //Cycle through the days of the plan and extract the date and time
                $d = $plan_dates[$day_index % 7];
                $date = $d['date'];
                $day = $d['day'];
                
                //
                $start_time_slot = $available_time[$date];

                $session_start_ts = strtotime($start_time_slot);
                $max_end_ts = strtotime($end_time);
                //
                $duration = min($session_length, $hours_remaining);
                $session_end_ts = $session_start_ts + ($duration * 3600);

                if ($session_start_ts >= $max_end_ts || $session_end_ts > $max_end_ts) {
                    $day_index++;
                    continue;
                }

                $end = date("H:i", $session_end_ts);

                if ($hours_allocated[$date] + $duration > $max_hours) {
                    $day_index++;
                    continue;
                }

                $conflict = false;

                if (!empty($busy_slots[$day])) {
                    foreach ($busy_slots[$day] as $b) {
                        if ($start_time_slot < $b['end_time'] && $end > $b['start_time']) {
                            $conflict = true;
                            break;
                        }
                    }
                }

                if ($conflict) {
                    $available_time[$date] = date("H:i", strtotime($start_time_slot . " +30 minutes"));
                    continue;
                }

                // INSERT NEW SESSION (NO user_id)
                $query = $conn->prepare("
                    INSERT INTO study_plan
                    (task_id, day_of_week, study_date, start_time, end_time, session_duration, plan_generated_at)
                    VALUES (?,?,?,?,?,?,?)
                ");

                $query->bind_param(
                    "issssis",
                    $task['task_id'],
                    $day,
                    $date,
                    $start_time_slot,
                    $end,
                    $duration,
                    $plan_generated_at
                );

                $query->execute();

                $available_time[$date] = date("H:i", strtotime($end . " +30 minutes"));
                $hours_allocated[$date] += $duration;

                $hours_remaining -= $duration;
                $day_index++;
            }
        }
    }
}

/* ============================================================
   7. LOAD SESSIONS FOR LATEST PLAN
   ============================================================ */

$query = $conn->prepare("
    SELECT MAX(sp.plan_generated_at) AS latest
    FROM study_plan sp
    JOIN tasks t ON sp.task_id = t.task_id
    JOIN modules m ON t.module_id = m.module_id
    WHERE m.user_id = ?
");
$query->bind_param("i", $user_id);
$query->execute();
$latest_plan_timestamp = $query->get_result()->fetch_assoc()['latest'];

if (!$latest_plan_timestamp) {
    $latest_plan_timestamp = date("Y-m-d H:i:s");
}

$query = $conn->prepare("
    SELECT sp.*, t.module_id
    FROM study_plan sp
    JOIN tasks t ON sp.task_id = t.task_id
    JOIN modules m ON t.module_id = m.module_id
    WHERE m.user_id = ?
    AND sp.plan_generated_at = ?
    ORDER BY sp.study_date, sp.start_time
");
$query->bind_param("is", $user_id, $latest_plan_timestamp);
$query->execute();

$result = $query->get_result();
while ($row = $result->fetch_assoc()) {
    $study_plan[$row['study_date']][] = $row;
}

/* ============================================================
   LOAD TASK TITLES
   ============================================================ */
$task_titles = [];
$query = $conn->prepare("
    SELECT t.task_id, t.title
    FROM tasks t
    JOIN modules m ON t.module_id = m.module_id
    WHERE m.user_id = ?
");
$query->bind_param("i", $user_id);
$query->execute();
$result = $query->get_result();
while ($task_row = $result->fetch_assoc()) {
    $task_titles[$task_row['task_id']] = $task_row['title'];
}

/* ============================================================
   LOAD TIMETABLE EVENTS
   ============================================================ */
$query = $conn->prepare("
    SELECT te.*, m.module_name
    FROM timetable_events te
    JOIN modules m ON te.module_id = m.module_id
    WHERE m.user_id = ?
");
$query->bind_param("i", $user_id);
$query->execute();

$result = $query->get_result();
$timetable = [];

while ($row = $result->fetch_assoc()) {
    $timetable[$row['day_of_week']][] = $row;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Planner</title>
    <link rel="stylesheet" href="assets/app.css">
    <link rel="stylesheet" href="assets/planner.css">
    <link rel="stylesheet" href="assets/timetable.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>

<body>

<div class="container">

<div class="navbar">
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

<h2>7-day Study Plan</h2>
<p class="page-description">
This planner generates your personalised weekly study plan based on your timetable, tasks, and study preferences.
</p>

<form method="POST">
    <button name="generate_plan"
        onclick="return confirm('Your new study plan will start from today. Make sure your timetable, tasks, and preferences are up to date.')">
        Generate / Regenerate Plan
    </button>
</form>

<?php if ($error_message): ?>
<p style="color:red;"><?= $error_message ?></p>
<?php endif; ?>

<div class="calendar">

<?php for ($i = 0; $i < 7; $i++): ?>
<?php
$date = (clone new DateTime($plan_start_date))->modify("+$i day");
$d = $date->format("Y-m-d");
$dow = $date->format("l");

$sessions = $study_plan[$d] ?? [];
$classes = $timetable[$dow] ?? [];

$day_items = [];

foreach ($classes as $c) {
    $day_items[] = [
        "type" => "timetable",
        "title" => $c['module_name'],
        "module_id" => $c['module_id'],
        "start" => $c['start_time'],
        "end" => $c['end_time']
    ];
}

foreach ($sessions as $s) {
    $day_items[] = [
        "type" => "session",
        "session_id" => $s['session_id'],
        "module_id" => $s['module_id'],
        "title" => $task_titles[$s['task_id']] ?? "Task",
        "start" => $s['start_time'],
        "end" => $s['end_time']
    ];
}

usort($day_items, fn($a, $b) =>
    strtotime($a['start']) - strtotime($b['start'])
);
?>

<div class="day-column">
<div class="header"><?= $date->format("D d M") ?></div>

<?php if (!empty($day_items)): ?>

<?php foreach ($day_items as $item): ?>

    <?php if ($item['type'] == 'timetable'): ?>
        <?php $module_colour = getModuleColour($item['module_id']); ?>
        <div class="event timetable" style="--module-colour: <?= $module_colour ?>;">
            <i class="fas fa-chalkboard-teacher"></i>
            <?= htmlspecialchars($item['title']) ?>
            <div>
    <?= date("H:i", strtotime($item['start'])) ?>
    -
    <?= date("H:i", strtotime($item['end'])) ?>
</div>

        </div>

    <?php else: ?>
        <?php $module_colour = getModuleColour($item['module_id']); ?>

        <form method="POST" action="update_session.php" class="event session"
              style="--module-colour: <?= $module_colour ?>;">

            <div>
                <i class="fas fa-book-open"></i>
                <?= htmlspecialchars($item['title']) ?>
            </div>

            <input type="hidden" name="session_id" value="<?= $item['session_id'] ?>">
            <input type="date" name="study_date" value="<?= $d ?>">

            <div class="time-row">
    <input type="time" name="start_time" value="<?= date('H:i', strtotime($item['start'])) ?>">
    <span class="dash">—</span>
    <input type="time" name="end_time" value="<?= date('H:i', strtotime($item['end'])) ?>">
</div>


            <button>Edit</button>

            <?php if (!empty($edit_error_message[$item['session_id']])): ?>
                <div class="error-message">
                    <?= htmlspecialchars($edit_error_message[$item['session_id']]) ?>
                </div>
            <?php endif; ?>

            <?php if ($edit_success_message == $item['session_id']): ?>
                <div class="success-message">
                    Updated successfully
                </div>
            <?php endif; ?>

        </form>

    <?php endif; ?>

<?php endforeach; ?>

<?php else: ?>
    <p class="empty-day">No study sessions or classes</p>
<?php endif; ?>

</div>

<?php endfor; ?>

</div>

</div>
</div>

</body>
</html>
