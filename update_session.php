<?php
session_start();
require "db.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: index.php");
    exit();
}

$user_id = $_SESSION["user_id"];

$id = (int)$_POST["id"];
$start = $_POST["start_time"];
$end = $_POST["end_time"];
$date = $_POST["study_date"];

/* =====================================================
   ERROR SESSION
===================================================== */
if (!isset($_SESSION["update_errors"])) {
    $_SESSION["update_errors"] = [];
}

$_SESSION["update_errors"][$id] = "";

/* =====================================================
   1. PREFERENCES CHECK
===================================================== */
$stmt = $conn->prepare("SELECT * FROM study_preferences WHERE user_id=?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$pref = $stmt->get_result()->fetch_assoc();

if (!$pref) {
    $_SESSION["update_errors"][$id] = "No preferences set";
    header("Location: planner.php");
    exit();
}

/* =====================================================
   2. STUDY HOURS CHECK
===================================================== */
if (
    strtotime($start) < strtotime($pref['start_time']) ||
    strtotime($end) > strtotime($pref['end_time'])
) {
    $_SESSION["update_errors"][$id] = "Outside study hours";
    header("Location: planner.php");
    exit();
}

/* =====================================================
   3. TIMETABLE CONFLICT CHECK
===================================================== */
$day = date("l", strtotime($date));

$stmt = $conn->prepare("
    SELECT 1 FROM timetable
    WHERE user_id=?
    AND day_of_week=?
    AND (start_time < ? AND end_time > ?)
");

$stmt->bind_param("isss", $user_id, $day, $end, $start);
$stmt->execute();

if ($stmt->get_result()->num_rows > 0) {
    $_SESSION["update_errors"][$id] = "Conflicts with timetable";
    header("Location: planner.php");
    exit();
}

/* =====================================================
   4. OVERLAP CHECK (OTHER SESSIONS)
===================================================== */
$stmt = $conn->prepare("
    SELECT 1 FROM study_plan
    WHERE user_id=?
    AND study_date=?
    AND id != ?
    AND (start_time < ? AND end_time > ?)
");

$stmt->bind_param("isiss", $user_id, $date, $id, $end, $start);
$stmt->execute();

if ($stmt->get_result()->num_rows > 0) {
    $_SESSION["update_errors"][$id] = "Overlaps with another session";
    header("Location: planner.php");
    exit();
}

/* =====================================================
   5. PLAN WEEK CHECK (FIXED PROPER TABLE)
===================================================== */
$stmt = $conn->prepare("
    SELECT plan_start_date FROM study_plan_info WHERE user_id=?
");
$stmt->bind_param("i", $user_id);
$stmt->execute();

$res = $stmt->get_result();
$row = $res->fetch_assoc();

if (!$row || !$row['plan_start_date']) {
    $_SESSION["update_errors"][$id] = "Plan not found";
    header("Location: planner.php");
    exit();
}

$plan_start = $row['plan_start_date'];
$plan_end = (new DateTime($plan_start))->modify("+6 days")->format("Y-m-d");

if ($date < $plan_start || $date > $plan_end) {
    $_SESSION["update_errors"][$id] = "Must stay within current plan week";
    header("Location: planner.php");
    exit();
}

/* =====================================================
   SUCCESS UPDATE
===================================================== */
$day = date("l", strtotime($date));

$stmt = $conn->prepare("
    UPDATE study_plan
    SET start_time=?, end_time=?, study_date=?, day_of_week=?
    WHERE id=? AND user_id=?
");

$stmt->bind_param("ssssii", $start, $end, $date, $day, $id, $user_id);
$stmt->execute();

$_SESSION["update_errors"][$id] = "Updated successfully";

header("Location: planner.php");
exit();
?>
