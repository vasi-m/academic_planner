<?php
session_start();
require "db.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: index.php");
    exit();
}

$user_id = $_SESSION["user_id"];
$module_id = $_GET["id"] ?? null;

if (!$module_id) {
    header("Location: dashboard.php");
    exit();
}

/* ============================================================
   1. VERIFY MODULE BELONGS TO USER
   ============================================================ */
$stmt = $conn->prepare("
    SELECT module_id 
    FROM modules 
    WHERE module_id = ? AND user_id = ?
");
$stmt->bind_param("ii", $module_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header("Location: dashboard.php?msg=" . urlencode("Invalid module.") . "&type=error");
    exit();
}

/* ============================================================
   2. DELETE STUDY PLAN SESSIONS FOR TASKS IN THIS MODULE
   ============================================================ */
$stmt = $conn->prepare("
    DELETE sp 
    FROM study_plan sp
    JOIN tasks t ON sp.task_id = t.task_id
    WHERE t.module_id = ?
");
$stmt->bind_param("i", $module_id);
$stmt->execute();

/* ============================================================
   3. DELETE TASKS FOR THIS MODULE
   ============================================================ */
$stmt = $conn->prepare("
    DELETE FROM tasks
    WHERE module_id = ?
");
$stmt->bind_param("i", $module_id);
$stmt->execute();

/* ============================================================
   4. DELETE TIMETABLE EVENTS FOR THIS MODULE
   ============================================================ */
$stmt = $conn->prepare("
    DELETE FROM timetable_events
    WHERE module_id = ?
");
$stmt->bind_param("i", $module_id);
$stmt->execute();

/* ============================================================
   5. DELETE THE MODULE ITSELF
   ============================================================ */
$stmt = $conn->prepare("
    DELETE FROM modules
    WHERE module_id = ? AND user_id = ?
");
$stmt->bind_param("ii", $module_id, $user_id);
$stmt->execute();

/* ============================================================
   6. REDIRECT WITH SUCCESS MESSAGE
   ============================================================ */
header("Location: dashboard.php?msg=" . urlencode("Module deleted successfully.") . "&type=success");
exit();
