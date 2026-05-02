<?php
//Start the session to track the logged-in user
session_start();
//Ensure connection with the database
require "db.php";

//Redirect to login page if user not logged in
if (!isset($_SESSION["user_id"])) {
    header("Location: index.php");
    exit();
}

$user_id = $_SESSION["user_id"];

//Get submitted form values
$session_id = (int)$_POST["session_id"];
$start_time = $_POST["start_time"];
$end_time = $_POST["end_time"];
$date = $_POST["study_date"];

//Initialise error array if not set
if (!isset($_SESSION["edit_error_message"])) {
    $_SESSION["edit_error_message"] = [];
}

//Clear previous error for this session
unset($_SESSION["edit_error_message"][$session_id]);

//Load user study preferences
$query = $conn->prepare("
    SELECT * 
    FROM study_preferences 
    WHERE user_id = ?
");
$query->bind_param("i", $user_id);
$query->execute();
$preferences = $query->get_result()->fetch_assoc();

if (!$preferences) {
    $_SESSION["edit_error_message"][$session_id] = "No preferences set";
    header("Location: planner.php");
    exit();
}

//Check if the new time is within allowed study hours
if (
    strtotime($start_time) < strtotime($preferences['start_time']) ||
    strtotime($end_time) > strtotime($preferences['end_time'])
) {
    // If not, show error message
    $_SESSION["edit_error_message"][$session_id] = "Outside study hours";
    header("Location: planner.php");
    exit();
}

//Check for timetable conflicts on the selected day
$day = date("l", strtotime($date));

$query = $conn->prepare("
    SELECT 1
    FROM timetable_events te
    JOIN modules m ON te.module_id = m.module_id
    WHERE m.user_id = ?
      AND te.day_of_week = ?
      AND (te.start_time < ? AND te.end_time > ?)
");
$query->bind_param("isss", $user_id, $day, $end_time, $start_time);
$query->execute();

if ($query->get_result()->num_rows > 0) {
    $_SESSION["edit_error_message"][$session_id] = "Conflicts with timetable";
    header("Location: planner.php");
    exit();
}

//Check for conflicts with other study sessions
$query = $conn->prepare("
    SELECT 1
    FROM study_plan sp
    JOIN tasks t ON sp.task_id = t.task_id
    JOIN modules m ON t.module_id = m.module_id
    WHERE m.user_id = ?
      AND sp.study_date = ?
      AND sp.session_id != ?
      AND (sp.start_time < ? AND sp.end_time > ?)
");
$query->bind_param("isiss", $user_id, $date, $session_id, $end_time, $start_time);
$query->execute();

//If the session overlaps with another session, show error message
if ($query->get_result()->num_rows > 0) {
    $_SESSION["edit_error_message"][$session_id] = "Overlaps with another session";
    header("Location: planner.php");
    exit();
}

//Load latest generated plan date
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

if (!$latest) {
    $_SESSION["edit_error_message"][$session_id] = "Plan not found";
    header("Location: planner.php");
    exit();
}

//Calculate allowed plan week range
$start_plan = date("Y-m-d", strtotime($latest));
$end_plan = (new DateTime($start_plan))->modify("+6 days")->format("Y-m-d");

//If the new date is not within the current plan week, show error
if ($date < $start_plan || $date > $end_plan) {
    $_SESSION["edit_error_message"][$session_id] = "Must stay within current week";
    header("Location: planner.php");
    exit();
}

//Update the study session with new values
$day = date("l", strtotime($date));

$query = $conn->prepare("
    UPDATE study_plan sp
    JOIN tasks t ON sp.task_id = t.task_id
    JOIN modules m ON t.module_id = m.module_id
    SET sp.start_time = ?, 
        sp.end_time = ?, 
        sp.study_date = ?, 
        sp.day_of_week = ?
    WHERE sp.session_id = ?
      AND m.user_id = ?
");
$query->bind_param("ssssii", $start_time, $end_time, $date, $day, $session_id, $user_id);
$query->execute();

//Store success message for this session
$_SESSION["edit_success_message"] = $session_id;

//Redirect back to planner
header("Location: planner.php");
exit();
?>



