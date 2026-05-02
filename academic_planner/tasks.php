<?php
//Start the session to track the logged-in user
session_start();
//Ensure database connection and module colours pattern
require "db.php";
require "module_colours.php";

//If not logged in, redirect to Login page
if (!isset($_SESSION["user_id"])) {
    header("Location: index.php");
    exit();
}

$user_id = $_SESSION["user_id"];

//Load user's modules
$query = $conn->prepare("
    SELECT module_id, module_name
    FROM modules
    WHERE user_id = ?
");
$query->bind_param("i", $user_id);
$query->execute();
$modules = $query->get_result();

//Get latest plan date
$query = $conn->prepare("
    SELECT MAX(plan_generated_at) AS latest
    FROM study_plan
    WHERE task_id IN (
        SELECT task_id
        FROM tasks t
        JOIN modules m ON t.module_id = m.module_id
        WHERE m.user_id = ?
    )
");
$query->bind_param("i", $user_id);
$query->execute();
$latest = $query->get_result()->fetch_assoc()['latest'];

//Calculate plan week range if a plan exists
if ($latest) {
    $plan_start_date = date("Y-m-d", strtotime($latest));
    $plan_end = date("Y-m-d", strtotime($plan_start_date . " +6 days"));
} else {
    $plan_start_date = null;
    $plan_end = null;
}

//Add or update a task
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $task_id = $_POST["task_id"] ?? "";
    $title = $_POST["title"];
    $module_id = $_POST["module_name"];
    $description = $_POST["description"];
    $difficulty = $_POST["difficulty"];
    $priority = $_POST["priority"];
    $time = $_POST["estimated_time"];
    $deadline = $_POST["deadline"];

    // Validate deadline format
    if (!preg_match("/^\d{4}-\d{2}-\d{2}$/", $deadline)) {
        $_SESSION["error"] = "Invalid deadline format.";
        header("Location: tasks.php");
        exit();
    }
    //Add new task
    if ($task_id == "") {

        $query = $conn->prepare("
            INSERT INTO tasks
            (module_id, title, description, difficulty, priority, estimated_time, deadline, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')
        ");

        $query->bind_param(
            "issssis",
            $module_id,
            $title,
            $description,
            $difficulty,
            $priority,
            $time,
            $deadline
        );

        $query->execute();

    } else {
        //Update an existing task
        $query = $conn->prepare("
            UPDATE tasks
            SET title=?, module_id=?, description=?, difficulty=?, priority=?, estimated_time=?, deadline=?
            WHERE task_id=?
            AND module_id IN (
                SELECT module_id FROM modules WHERE user_id=?
            )
        ");

        $query->bind_param(
    "sisssissi",
    $title,
    $module_id,
    $description,
    $difficulty,
    $priority,
    $time,
    $deadline,
    $task_id,
    $user_id
);


        $query->execute();
    }
    header("Location: tasks.php");
    exit();
}

//Delete task
if (isset($_GET["delete"])) {

    $task_id = $_GET["delete"];

    $query = $conn->prepare("
        DELETE FROM tasks
        WHERE task_id = ?
        AND module_id IN (
            SELECT module_id FROM modules WHERE user_id=?
        )
    ");
    $query->bind_param("ii", $task_id, $user_id);
    $query->execute();

    header("Location: tasks.php");
    exit();
}

//Change task status
if (isset($_GET["status"])) {

    $task_id = $_GET["status"];
    //Load current status for this task
    $query = $conn->prepare("
        SELECT status
        FROM tasks
        WHERE task_id=?
        AND module_id IN (
            SELECT module_id FROM modules WHERE user_id=?
        )
    ");
    $query->bind_param("ii", $task_id, $user_id);
    $query->execute();
    $task = $query->get_result()->fetch_assoc();

    if (!$task) {
        header("Location: tasks.php");
        exit();
    }
    //Cycle through statuses
    if ($task['status'] == "pending") {
        $new_status = "in_progress";
    } elseif ($task['status'] == "in_progress") {
        $new_status = "completed";
    } else {
        $new_status = "pending";
    }
    //Update status and the completed time
    $query = $conn->prepare("
        UPDATE tasks
        SET status=?, completed_time = IF(?='completed', estimated_time, 0)
        WHERE task_id=?
        AND module_id IN (
            SELECT module_id FROM modules WHERE user_id=?
        )
    ");
    $query->bind_param("ssii", $new_status, $new_status, $task_id, $user_id);
    $query->execute();

    header("Location: tasks.php");
    exit();
}

//Restore a completed task
if (isset($_GET["restore"])) {

    $task_id = $_GET["restore"];

    $query = $conn->prepare("
        UPDATE tasks
        SET status='pending', completed_time=0
        WHERE task_id=?
        AND module_id IN (
            SELECT module_id FROM modules WHERE user_id=?
        )
    ");
    $query->bind_param("ii", $task_id, $user_id);
    $query->execute();

    header("Location: tasks.php?show_completed_task=1");
    exit();
}

// 
$show_completed_task = isset($_GET["show_completed_task"]) && $_GET["show_completed_task"] == "1";
//Load all tasks
if ($show_completed_task) {
     //Show all tasks except for the completed ones not in the current plan
    $query = $conn->prepare("
        SELECT t.*, m.module_name
        FROM tasks t
        JOIN modules m ON t.module_id = m.module_id
        WHERE m.user_id = ?
        AND (
            t.status != 'completed'
            OR t.task_id IN (
                SELECT DISTINCT task_id
                FROM study_plan
                WHERE plan_generated_at = ?
            )
        )
        ORDER BY 
            CASE 
                WHEN t.status='pending' THEN 1
                WHEN t.status='in_progress' THEN 2
                WHEN t.status='completed' THEN 3
            END,
            t.deadline ASC
    ");
    $query->bind_param("is", $user_id, $latest);

} else {
    //Show only tasks with status in pending and in progress
    $query = $conn->prepare("
        SELECT t.*, m.module_name
        FROM tasks t
        JOIN modules m ON t.module_id = m.module_id
        WHERE m.user_id = ?
        AND t.status != 'completed'
        ORDER BY 
            CASE 
                WHEN t.status='pending' THEN 1
                WHEN t.status='in_progress' THEN 2
            END,
            t.deadline ASC
    ");
    $query->bind_param("i", $user_id);
}

$query->execute();
$tasks = $query->get_result();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Tasks</title>
    <link rel="stylesheet" href="assets/app.css">
    <link rel="stylesheet" href="assets/tasks.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>

<body>

<div class="container">
<!-- Navigation bar-->
<div class="navbar">
    <h2>Academic Planner</h2>
    <div>
        <a href="dashboard.php">Dashboard</a>
        <a href="timetable.php">Timetable</a>
        <a href="tasks.php" class="active">Tasks</a>
        <a href="study_preferences.php">Preferences</a>
        <a href="planner.php">Planner</a>
        <a href="logout.php">Logout</a>
    </div>
</div>

<div class="split">

<!-- Task Form -->
<div class="card" id="task-form">

<h2>Add / Edit Task</h2>
<p class="page-description">
Add and manage academic tasks, including revisions, assignments, readings, and exam preparation. Any changes you make here will not appear in your study plan until you generate/regenerate it. Regenerating the plan creates a new 7-day schedule starting from today.
</p>

<form method="POST">
<!--Task form input fields -->
<input type="hidden" name="task_id" id="task_id">

<label>Title</label>
<input type="text" name="title" id="title" required>

<label>Module</label>
<select name="module_name" id="module_name" required>
    <option value="">Select Module</option>
    <?php while ($m = $modules->fetch_assoc()): ?>
        <option value="<?= $m['module_id'] ?>">
            <?= htmlspecialchars($m['module_name']) ?>
        </option>
    <?php endwhile; ?>
</select>

<label>Description</label>
<input type="text" name="description" id="description">

<label>Difficulty</label>
<select name="difficulty" id="difficulty">
    <option>Easy</option>
    <option>Medium</option>
    <option>Hard</option>
</select>

<label>Priority</label>
<select name="priority" id="priority">
    <option>Low</option>
    <option>Medium</option>
    <option>High</option>
</select>

<label>Estimated Time (hours)</label>
<input type="number" name="estimated_time" id="estimated_time" min="1" required>

<label>Deadline</label>
<input type="date" name="deadline" id="deadline" required>

<button type="submit" id="btn">Add</button>

</form>

</div>

<!-- Task list -->
<div class="card" id="completed-tasks">

<h2>Your Tasks</h2>

<div class="checkbox">
    <label>
        <input type="checkbox" id="show_completed_task" onchange="applyCheckboxFilter()">
        Show Completed Tasks
    </label>
</div>
<!--If there is no task, show the text -->
<?php if ($tasks->num_rows == 0): ?>
<p class="empty">No tasks available</p>
<?php endif; ?>

<?php while ($task = $tasks->fetch_assoc()): ?>
    

<?php $module_colour = getModuleColour($task['module_id']); ?>
<!--Task card with the module colour -->
<div class="event <?= $task['status'] ?> <?= $task['status'] === 'completed' ? 'completed-task' : '' ?>"
     style="--module-colour: <?= $module_colour ?>;">

<strong><?= htmlspecialchars($task['title']) ?></strong><br>

<i class="fa-solid fa-book"></i>
<?= htmlspecialchars($task['module_name']) ?><br>

Description: <?= htmlspecialchars($task['description']) ?><br>
<?= $task['difficulty'] ?> Task |
<?= $task['priority'] ?> Priority<br>

<i class="fa-solid fa-clock"></i>
<?= $task['estimated_time'] ?>h |

<i class="fa-solid fa-calendar"></i>
<?= $task['deadline'] ?><br>

Status: <b class="status-text <?= $task['status'] ?>">
    <?= $task['status'] ?>
</b>

<div class="actions">

<?php if ($task['status'] !== 'completed'): ?>
   <!--Edit button fills the form with its details -->
  <button type="button" class="edit"
onclick='editTask(
    <?= json_encode($task["task_id"]) ?>,
    <?= json_encode($task["title"]) ?>,
    <?= json_encode($task["description"]) ?>,
    <?= json_encode($task["difficulty"]) ?>,
    <?= json_encode($task["priority"]) ?>,
    <?= json_encode($task["estimated_time"]) ?>,
    <?= json_encode(date("Y-m-d", strtotime($task["deadline"]))) ?>,
    <?= json_encode($task["module_id"]) ?>
)'>
Edit
</button>

    <!-- Status Button that changes-->
    <a class="status" href="?status=<?= $task['task_id'] ?>">
        <?= $task['status'] == "pending" ? "Start" : "Finish" ?>
    </a>
    <!-- Delete task button -->
    <a class="delete" href="?delete=<?= $task['task_id'] ?>"
       onclick="return confirm('Delete this task?')">
       Delete
    </a>

<?php else: ?>
    <!-- Restore button for completed tasks-->
    <a class="restore" href="?restore=<?= $task['task_id'] ?>">
        Restore
    </a>

<?php endif; ?>

</div>

</div>

<?php endwhile; ?>
</div>

</div>

</div>

<script>
//Fill the form with the selected tasks details
function editTask(id, title, description, difficulty, priority, time, deadline, module) {

    document.getElementById("task_id").value = id;
    document.getElementById("title").value = title;
    document.getElementById("description").value = description;
    document.getElementById("difficulty").value = difficulty;
    document.getElementById("priority").value = priority;
    document.getElementById("estimated_time").value = time;

    // Ensure deadline is valid YYYY-MM-DD
    if (deadline && /^\d{4}-\d{2}-\d{2}$/.test(deadline)) {
        document.getElementById("deadline").value = deadline;
    }
    
    document.getElementById("module_name").value = module;
    //Switch button to Upfate
    document.getElementById("btn").innerText = "Update";
    //Scroll form into view on mobile screens
    if (window.innerWidth <= 900) {
        document.getElementById("task-form").scrollIntoView({
            behavior: "smooth",
            block: "start"
        });
    }
}
//Reload the page based on checkbox state
function applyCheckboxFilter() {
    const show = document.getElementById("show_completed_task").checked ? 1 : 0;
    window.location.href = "tasks.php?show_completed_task=" + show;
}

window.onload = function() {
    const urlParams = new URLSearchParams(window.location.search);
    //If completed tasks are displayed, keep the checkbox checked
    if (urlParams.get("show_completed_task") === "1") {
        document.getElementById("show_completed_task").checked = true;

        const firstCompleted = document.querySelector(".event.completed-task");
        //Scroll to the first completed task
        if (firstCompleted) {
            firstCompleted.scrollIntoView({ behavior: "smooth", block: "start" });
        }
    }
};
</script>

</body>
</html>


