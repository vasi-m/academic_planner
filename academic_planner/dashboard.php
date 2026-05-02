<?php
//Start the session to track the logged-in user
session_start();
//Ensure database connection and module colours pattern
require "db.php";
require "module_colours.php";

// Redirects the user to the login page if they are not logged in
if (!isset($_SESSION["user_id"])) {
    header("Location: index.php");
    exit();
}
//Store the user id
$user_id = $_SESSION["user_id"];

//A feedback message for adding a module
$message = "";
$message_type = "";

// Handles the add module form submission
if (isset($_POST["add_module"])) {

    $module_name = trim($_POST["module_name"]);

    // Only process the request if the module name is not empty
    if ($module_name !== "") {

        // Checks if the module already exists for this user
        $query = $conn->prepare("
            SELECT module_id 
            FROM modules 
            WHERE user_id=? AND module_name=?
        ");
        $query->bind_param("is", $user_id, $module_name);
        $query->execute();
        $result = $query->get_result();

        // If the module exists, show an error message 
        if ($result->num_rows > 0) {
            $message = "Module already exists.";
            $message_type = "error";
        } else {

            // If it does not exist, insert the new module
            $query = $conn->prepare("
                INSERT INTO modules (user_id, module_name)
                VALUES (?, ?)
            ");
            $query->bind_param("is", $user_id, $module_name);
            $query->execute();

            $message = "Module added successfully!";
            $message_type = "success";
        }
    }

    // Redirects back to the dashboard 
    header("Location: dashboard.php?msg=" . urlencode($message) . "&type=" . $message_type);
    exit();
}

if (isset($_GET["msg"])) $message = $_GET["msg"];
if (isset($_GET["type"])) $message_type = $_GET["type"];


//Load plan's start date

$query = $conn->prepare("
    SELECT MAX(sp.plan_generated_at) AS latest
    FROM study_plan sp
    JOIN tasks t ON sp.task_id = t.task_id
    JOIN modules m ON t.module_id = m.module_id
    WHERE m.user_id = ?
");
$query->bind_param("i", $user_id);
$query->execute();
$latest = $query->get_result()->fetch_assoc()['latest'];

//If a plan exists, calculate the 7 day range
if ($latest) {
    $plan_start = date("Y-m-d", strtotime($latest));
    $plan_end = date("Y-m-d", strtotime($plan_start . " +6 days"));
    //If no plan exists, leave values empty
} else {
    $plan_start = null;
    $plan_end = null;
}
//The week label
$week_label = "";
if ($plan_start && $plan_end) {
    $week_label = "Week: " . date("D j M", strtotime($plan_start)) . " → " . date("D j M", strtotime($plan_end));
}


//Today's schedule, class from the fixed timetable and study session from the study plan
$query = $conn->prepare("
    SELECT 
        'Class' AS item_type,
        m.module_name AS title,
        te.start_time,
        te.end_time
    FROM timetable_events te
    JOIN modules m ON te.module_id = m.module_id
    WHERE m.user_id = ?
      AND te.day_of_week = DAYNAME(CURDATE())

    UNION ALL

    SELECT 
        'Study Session' AS item_type,
        t.title AS title,
        sp.start_time,
        sp.end_time
    FROM study_plan sp
    JOIN tasks t ON sp.task_id = t.task_id
    JOIN modules m ON t.module_id = m.module_id
    WHERE m.user_id = ?
      AND sp.study_date = CURDATE()
      AND sp.plan_generated_at = ?
    
    ORDER BY start_time
");
$query->bind_param("iis", $user_id, $user_id, $latest);
$query->execute();
$today_schedule = $query->get_result();

//Find the closest upcoming deadline within the current plan week
$query = $conn->prepare("
    SELECT t.title, t.deadline
    FROM tasks t
    JOIN modules m ON t.module_id = m.module_id
    WHERE m.user_id = ?
      AND t.status != 'completed'
      AND t.deadline BETWEEN ? AND ?
    ORDER BY t.deadline ASC
    LIMIT 1
");

$query->bind_param("iss", $user_id, $plan_start, $plan_end);
$query->execute();
$next_deadline_tasks = $query->get_result();


//Count tasks by status for the latest study plan
$query = $conn->prepare("
    SELECT 
        SUM(t.status='pending') AS pending,
        SUM(t.status='in_progress') AS in_progress,
        SUM(t.status='completed') AS completed
    FROM tasks t
    JOIN modules m ON t.module_id = m.module_id
    WHERE m.user_id = ?
      AND t.task_id IN (
            SELECT DISTINCT sp.task_id
            FROM study_plan sp
            JOIN tasks tt ON sp.task_id = tt.task_id
            JOIN modules mm ON tt.module_id = mm.module_id
            WHERE mm.user_id = ?
              AND sp.plan_generated_at = ?
      )
");
$query->bind_param("iis", $user_id, $user_id, $latest);
$query->execute();
$task_stats = $query->get_result()->fetch_assoc();

//Calculate total tasks, and the percentage of completed tasks
$total_tasks = $task_stats['pending'] + $task_stats['in_progress'] + $task_stats['completed'];
$completed_tasks_percentage = $total_tasks > 0 ? round(($task_stats['completed'] / $total_tasks) * 100) : 0;

//Calculate the total hours that are scheduled in the latest plan
$query = $conn->prepare("
    SELECT SUM(TIMESTAMPDIFF(MINUTE, sp.start_time, sp.end_time)) / 60 AS hours
    FROM study_plan sp
    JOIN tasks t ON sp.task_id = t.task_id
    JOIN modules m ON t.module_id = m.module_id
    WHERE m.user_id = ?
      AND sp.plan_generated_at = ?
");
$query->bind_param("is", $user_id, $latest);
$query->execute();
$hours = $query->get_result()->fetch_assoc()['hours'] ?? 0;
?>
<!DOCTYPE html>
<html>
<head>
    <title>Dashboard</title>
    <!-- Main app and dashboard styles -->
    <link rel="stylesheet" href="assets/app.css">
    <link rel="stylesheet" href="assets/dashboard.css">
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>

<body>

<!-- Main page container -->
<div class="container">

<!-- Navigation bar -->
<div class="navbar">
    <h2>Academic Planner</h2>
    <div>
        <a href="dashboard.php" class="active">Dashboard</a>
        <a href="timetable.php">Timetable</a>
        <a href="tasks.php">Tasks</a>
        <a href="study_preferences.php">Preferences</a>
        <a href="planner.php">Planner</a>
        <a href="logout.php">Logout</a>
    </div>
</div>

<div class="card">

<!-- Welcome meesage -->
<h2>Welcome, <?= htmlspecialchars($_SESSION["full_name"]) ?> 👋</h2>

<!--Week label that is shown only if a plan exists-->
<?php if ($week_label): ?>
    <p style="font-weight:bold; margin-top:5px; color:#0B5E18;">
        <?= $week_label ?>
    </p>
<?php endif; ?>

<!-- Main grid that contains today's schedule and week overview-->
<div class="main-grid">

    <!-- Today's schedule container -->
    <div class="stat-container schedule-card">
        <h3><i class="fas fa-calendar-day"></i>Today's Schedule</h3>
        <!--If it's empty, display the text -->
        <?php if ($today_schedule->num_rows === 0): ?>
            <p>No classes or study sessions today</p>
        <?php else: ?>
            <!--If not empty, show the title, type and the time -->
            <?php while ($row = $today_schedule->fetch_assoc()): ?>
                <div class="schedule-item">
                    <strong><?= htmlspecialchars($row['title']) ?></strong><br>
                    <small>
    <?= $row['item_type'] ?> —
    <?= date("H:i", strtotime($row['start_time'])) ?>
    to
    <?= date("H:i", strtotime($row['end_time'])) ?>
</small>

                </div>
            <?php endwhile; ?>
        <?php endif; ?>
    </div>

    <!-- Week Overview container -->
    <div class="stat-container overview-card">
        <h3><i class="fas fa-layer-group"></i>Week Overview</h3>
        
            <!-- Task Progress with a bar and the completed tasks percentage -->
        <h4><i class="fas fa-tasks"></i>Task Progress</h4>
        <div class="progress-container">
            <div class="progress-bar" style="--progress: <?= $completed_tasks_percentage ?>%;"></div>
        </div>
        <p><?= $completed_tasks_percentage ?>% completed</p>
        
        <!-- Next deadline -->
        <h4><i class="fas fa-hourglass-half"></i>Next Deadline</h4>
            <!-- If there's no upcoming deadline, display the text -->
        <?php if ($next_deadline_tasks->num_rows === 0): ?>
            <p>No upcoming deadlines</p>
        <?php else: ?>
            <!--If there's upcoming deadline, show the date and the tasks -->
            <?php
            $deadline_date = null;
            while ($t = $next_deadline_tasks->fetch_assoc()):
                if ($deadline_date === null) {
                    $deadline_date = $t['deadline'];
                    echo "<p><strong>Date:</strong> " . htmlspecialchars($deadline_date) . "</p>";
                    echo "<p><strong>Tasks:</strong></p>";
                }
            ?>
                <p>- <?= htmlspecialchars($t['title']) ?></p>
            <?php endwhile; ?>
        <?php endif; ?>
        <!--  Study load -->
        <h4><i class="fas fa-book-open"></i>Study Load</h4>
        <p><strong><?= round($hours, 1) ?> hours</strong> planned this week</p>
    </div>

</div>

<!-- Modules container -->
<h3 style="margin-top:30px;"><i class="fas fa-layer-group"></i> Your Modules</h3>

<div class="modules">
<!-- Load all modules for the users-->
<?php
$query = $conn->prepare("
    SELECT module_id, module_name
    FROM modules
    WHERE user_id=?
");
$query->bind_param("i", $user_id);
$query->execute();
$modules = $query->get_result();
//If np modules were added, show the text
if ($modules->num_rows === 0):
?>
    <p>No modules yet. Add one below</p>
<?php endif; ?>

<?php while ($m = $modules->fetch_assoc()): ?>

    <?php $module_colour = getModuleColour($m['module_id']); ?>
    <!-- Module container -->
    <div class="module-card" style="--module-colour: <?= $module_colour ?>;">

        <div class="module-header">
            <h3><i class="fas fa-layer-group"></i><?= htmlspecialchars($m['module_name']) ?></h3>
            <!-- Delete module button -->
            <a href="delete_module.php?id=<?= $m['module_id'] ?>"
               class="delete-module"
               onclick="return confirm('Are you sure you want to delete this module?\n\nThis will remove:\n• All tasks\n• All study sessions\n• All timetable entries\n\nThis action cannot be undone.')">
                <i class="fas fa-trash"></i>
            </a>
        </div>

        <?php
        /* for each module,count tasks that are included in the study plan */
        $query = $conn->prepare("
            SELECT COUNT(*) AS total_tasks
            FROM tasks t
            JOIN modules m ON t.module_id = m.module_id
            WHERE m.user_id = ?
              AND t.module_id = ?
              AND t.task_id IN (
                    SELECT DISTINCT sp.task_id
                    FROM study_plan sp
                    JOIN tasks tt ON sp.task_id = tt.task_id
                    JOIN modules mm ON tt.module_id = mm.module_id
                    WHERE mm.user_id = ?
                      AND sp.plan_generated_at = ?
              )
        ");
        $query->bind_param("iiis", $user_id, $m['module_id'], $user_id, $latest);
        $query->execute();
        $task_count = $query->get_result()->fetch_assoc()['total_tasks'];

        /* Next deadline for this module */
        $query = $conn->prepare("
            SELECT t.title, t.deadline
            FROM tasks t
            JOIN modules m ON t.module_id = m.module_id
            WHERE m.user_id = ?
              AND t.module_id = ?
              AND t.status != 'completed'
              AND t.task_id IN (
                    SELECT DISTINCT sp.task_id
                    FROM study_plan sp
                    JOIN tasks tt ON sp.task_id = tt.task_id
                    JOIN modules mm ON tt.module_id = mm.module_id
                    WHERE mm.user_id = ?
                      AND sp.plan_generated_at = ?
              )
            ORDER BY t.deadline ASC
            LIMIT 1
        ");
        $query->bind_param("iiis", $user_id, $m['module_id'], $user_id, $latest);
        $query->execute();
        $next_deadline = $query->get_result()->fetch_assoc();
        ?>
        <!-- Display task count and next deadline-->
        <p><strong>Tasks:</strong> <?= $task_count ?></p>

        <p><strong>Next Deadline:</strong><br>
        <!-- If there's no deadline, display the text-->
        <?php if ($next_deadline): ?>
            <?= htmlspecialchars($next_deadline['title']) ?> —
            <small><?= htmlspecialchars($next_deadline['deadline']) ?></small>
        <?php else: ?>
            <em>No upcoming deadlines</em>
        <?php endif; ?>
        </p>

    </div>

<?php endwhile; ?>

</div>
<!-- Add module form-->
<form method="POST" class="add-module">
    <input type="text" name="module_name" placeholder="New module name" required>
    <!-- Add button -->
    <button type="submit" name="add_module">
        <i class="fas fa-plus-circle"></i>Add Module
    </button>
</form>

<!--Display the relevant feedback message -->
<?php if ($message): ?>
    <div class="message <?= htmlspecialchars($message_type) ?>">
        <?= htmlspecialchars($message) ?>
    </div>
<?php endif; ?>

</div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function () {
    const msg = document.querySelector(".message");
    if (msg) {
        msg.scrollIntoView({ behavior: "smooth", block: "center" });
    }
});
</script>

</body>
</html>
