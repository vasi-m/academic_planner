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
   ADD / UPDATE TASK
========================= */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $id = $_POST["task_id"] ?? "";

    $title = $_POST["title"];
    $desc = $_POST["description"];
    $difficulty = $_POST["difficulty"];
    $priority = $_POST["priority"];
    $time = $_POST["estimated_time"];
    $deadline = $_POST["deadline"];

    if ($id == "") {

        $stmt = $conn->prepare("
            INSERT INTO tasks 
            (user_id, title, description, difficulty, priority, estimated_time, deadline, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')
        ");
        $stmt->bind_param("issssis", $user_id, $title, $desc, $difficulty, $priority, $time, $deadline);
        $stmt->execute();

    } else {

        $stmt = $conn->prepare("
            UPDATE tasks 
            SET title=?, description=?, difficulty=?, priority=?, estimated_time=?, deadline=?
            WHERE task_id=? AND user_id=?
        ");
        $stmt->bind_param("ssssssii", $title, $desc, $difficulty, $priority, $time, $deadline, $id, $user_id);
        $stmt->execute();
    }

    header("Location: tasks.php");
    exit();
}

/* =========================
   DELETE TASK
========================= */
if (isset($_GET["delete"])) {

    $id = $_GET["delete"];

    $stmt = $conn->prepare("DELETE FROM tasks WHERE task_id=? AND user_id=?");
    $stmt->bind_param("ii", $id, $user_id);
    $stmt->execute();

    header("Location: tasks.php");
    exit();
}

/* =========================
   STATUS CYCLE
========================= */
if (isset($_GET["status"])) {

    $id = $_GET["status"];

    $stmt = $conn->prepare("SELECT status FROM tasks WHERE task_id=? AND user_id=?");
    $stmt->bind_param("ii", $id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $task = $result->fetch_assoc();

    if ($task['status'] == "pending") {
        $new_status = "in_progress";
    } elseif ($task['status'] == "in_progress") {
        $new_status = "completed";
    } else {
        $new_status = "pending";
    }

    $stmt = $conn->prepare("
        UPDATE tasks 
        SET status=?, completed_time = IF(?='completed', estimated_time, 0)
        WHERE task_id=? AND user_id=?
    ");
    $stmt->bind_param("ssii", $new_status, $new_status, $id, $user_id);
    $stmt->execute();

    header("Location: tasks.php");
    exit();
}

/* =========================
   LOAD TASKS (ONLY NOT COMPLETED)
========================= */
$stmt = $conn->prepare("
    SELECT * FROM tasks 
    WHERE user_id=? AND status != 'completed'
    ORDER BY deadline ASC
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
?>

<!DOCTYPE html>
<html>
<head>
    <title>Tasks</title>
    <link rel="stylesheet" href="assets/app.css">
</head>

<body>

<div class="container">

    <!-- NAVBAR -->
    <div class="topbar">
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

        <!-- LEFT FORM -->
        <div class="card">

            <h2>Add / Edit Task</h2>

            <form method="POST">

                <input type="hidden" name="task_id" id="task_id">

                <label>Title</label>
                <input type="text" name="title" id="title" required>

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

        <!-- RIGHT TASK LIST -->
        <div class="card">

            <h2>Your Tasks</h2>

            <?php if ($result->num_rows == 0): ?>
                <p class="empty">No tasks available</p>
            <?php endif; ?>

            <?php while ($row = $result->fetch_assoc()): ?>

                <div class="event <?php echo $row['status']; ?>">

                    <strong><?php echo $row['title']; ?></strong><br>

                    <?php echo $row['difficulty']; ?> |
                    <?php echo $row['priority']; ?><br>

                    ⏱ <?php echo $row['estimated_time']; ?>h |
                    📅 <?php echo $row['deadline']; ?><br>

                    Status: <b><?php echo $row['status']; ?></b>

                    <div class="actions">

                        <!-- EDIT -->
                        <button type="button"
                            class="edit"
                            onclick="editTask(
                                '<?php echo $row['task_id']; ?>',
                                '<?php echo htmlspecialchars($row['title'], ENT_QUOTES); ?>',
                                '<?php echo htmlspecialchars($row['description'], ENT_QUOTES); ?>',
                                '<?php echo $row['difficulty']; ?>',
                                '<?php echo $row['priority']; ?>',
                                '<?php echo $row['estimated_time']; ?>',
                                '<?php echo $row['deadline']; ?>'
                            )">
                            Edit
                        </button>

                        <!-- DELETE -->
                        <a class="delete" href="?delete=<?php echo $row['task_id']; ?>"
                           onclick="return confirm('Delete task?')">
                            Delete
                        </a>

                        <!-- STATUS -->
                        <a class="edit" href="?status=<?php echo $row['task_id']; ?>">

                            <?php if ($row['status'] == "pending"): ?>
                                Start
                            <?php else: ?>
                                Finish
                            <?php endif; ?>

                        </a>

                    </div>

                </div>

            <?php endwhile; ?>

        </div>

    </div>

</div>

<script>
function editTask(id, title, desc, difficulty, priority, time, deadline) {

    document.getElementById("task_id").value = id;
    document.getElementById("title").value = title;
    document.getElementById("description").value = desc;
    document.getElementById("difficulty").value = difficulty;
    document.getElementById("priority").value = priority;
    document.getElementById("estimated_time").value = time;
    document.getElementById("deadline").value = deadline;

    document.getElementById("btn").innerText = "Update";
}
</script>

</body>
</html>
