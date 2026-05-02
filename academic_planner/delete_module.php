<?php
//Start the session to track the logged-in user
session_start();
//Ensure database connection
require "db.php";

// Redirects the user to the login page if they are not logged in
if (!isset($_SESSION["user_id"])) {
    header("Location: index.php");
    exit();
}
//Store the user id
$user_id = $_SESSION["user_id"];
$module_id = $_GET["id"] ?? null;

if (!$module_id) {
    header("Location: dashboard.php");
    exit();
}

//Verify the module belongs to user
$query = $conn->prepare("
    SELECT module_id 
    FROM modules 
    WHERE module_id = ? AND user_id = ?
");
$query->bind_param("ii", $module_id, $user_id);
$query->execute();
$result = $query->get_result();

if ($result->num_rows === 0) {
    header("Location: dashboard.php?msg=" . urlencode("Invalid module.") . "&type=error");
    exit();
}

//Delete the study plan sessions for tasks in this module
$query = $conn->prepare("
    DELETE sp 
    FROM study_plan sp
    JOIN tasks t ON sp.task_id = t.task_id
    WHERE t.module_id = ?
");
$query->bind_param("i", $module_id);
$query->execute();

//Delete tasks for this module
$query = $conn->prepare("
    DELETE FROM tasks
    WHERE module_id = ?
");
$query->bind_param("i", $module_id);
$query->execute();

//Delete timetable event for this module
$query = $conn->prepare("
    DELETE FROM timetable_events
    WHERE module_id = ?
");
$query->bind_param("i", $module_id);
$query->execute();

//Delete the module
$query = $conn->prepare("
    DELETE FROM modules
    WHERE module_id = ? AND user_id = ?
");
$query->bind_param("ii", $module_id, $user_id);
$query->execute();

//Reload the page with a success message
header("Location: dashboard.php?msg=" . urlencode("Module deleted successfully.") . "&type=success");
exit();
