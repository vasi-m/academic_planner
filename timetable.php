<?php
require "db.php";

/* =========================
   ADD / UPDATE
========================= */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $id = $_POST["id"] ?? "";
    $day = $_POST["day_of_week"];
    $start = $_POST["start_time"];
    $end = $_POST["end_time"];
    $type = $_POST["activity_type"];
    $title = $_POST["title"];

    if ($id == "") {

        $stmt = $conn->prepare("
            INSERT INTO timetable (day_of_week, start_time, end_time, activity_type, title)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("sssss", $day, $start, $end, $type, $title);
        $stmt->execute();

    } else {

        $stmt = $conn->prepare("
            UPDATE timetable 
            SET day_of_week=?, start_time=?, end_time=?, activity_type=?, title=?
            WHERE id=?
        ");
        $stmt->bind_param("sssssi", $day, $start, $end, $type, $title, $id);
        $stmt->execute();
    }

    header("Location: timetable.php");
    exit();
}

/* =========================
   DELETE
========================= */
if (isset($_GET["delete"])) {

    $id = $_GET["delete"];

    $stmt = $conn->prepare("DELETE FROM timetable WHERE id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();

    header("Location: timetable.php");
    exit();
}

/* =========================
   DATA LOAD
========================= */
$days = ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday"];

$result = $conn->query("SELECT * FROM timetable ORDER BY start_time ASC");

$slots = [];

while ($row = $result->fetch_assoc()) {
    $slots[$row['day_of_week']][] = $row;
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Timetable</title>
    <link rel="stylesheet" href="assets/app.css">
</head>

<body>

<div class="container">

    <div class="topbar">
        <h2>Academic Planner</h2>
        <div>
            <a href="dashboard.php">Dashboard</a>
            <a href="logout.php">Logout</a>
        </div>
    </div>

    <div class="split">

        <!-- LEFT FORM -->
        <div class="card">

            <h2>Add / Edit Timetable</h2>

            <form method="POST">

                <input type="hidden" name="id" id="edit_id">

                <label>Day</label>
                <select name="day_of_week" id="day">
                    <?php foreach ($days as $day): ?>
                        <option value="<?php echo $day; ?>"><?php echo $day; ?></option>
                    <?php endforeach; ?>
                </select>

                <label>Start Time</label>
                <input type="time" name="start_time" id="start_time">

                <label>End Time</label>
                <input type="time" name="end_time" id="end_time">

                <label>Type</label>
                <select name="activity_type" id="type">
                    <option>Lecture</option>
                    <option>Practical</option>
                    <option>Workshop</option>
                </select>

                <label>Module Name</label>
                <input type="text" name="title" id="title">

                <button type="submit" id="btn">Add</button>

            </form>

        </div>

        <!-- RIGHT SIDE -->
        <div class="card">

            <h2>Weekly Timetable</h2>

            <div class="week">

                <?php foreach ($days as $day): ?>

                    <div class="day-column">

                        <div class="header"><?php echo $day; ?></div>

                        <?php if (!empty($slots[$day])): ?>
                            <?php foreach ($slots[$day] as $item): ?>

                                <div class="event">

                                    <strong><?php echo $item['title']; ?></strong><br>
                                    <?php echo $item['activity_type']; ?><br>
                                    <?php echo $item['start_time'] . " - " . $item['end_time']; ?>

                                    <div class="actions">

                                        <button type="button"
                                            class="edit"
                                            onclick="editItem(
                                                '<?php echo $item['id']; ?>',
                                                '<?php echo $item['day_of_week']; ?>',
                                                '<?php echo $item['start_time']; ?>',
                                                '<?php echo $item['end_time']; ?>',
                                                '<?php echo $item['activity_type']; ?>',
                                                '<?php echo htmlspecialchars($item['title'], ENT_QUOTES); ?>'
                                            )">
                                            Edit
                                        </button>

                                        <!-- FIXED DELETE -->
                                        <a class="delete"
                                           href="?delete=<?php echo $item['id']; ?>"
                                           onclick="return confirm('Delete?')">
                                            Delete
                                        </a>

                                    </div>

                                </div>

                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="empty">No events</p>
                        <?php endif; ?>

                    </div>

                <?php endforeach; ?>

            </div>

        </div>

    </div>

</div>

<script>
function editItem(id, day, start, end, type, title) {

    document.getElementById("edit_id").value = id;
    document.getElementById("day").value = day;
    document.getElementById("start_time").value = start;
    document.getElementById("end_time").value = end;
    document.getElementById("type").value = type;
    document.getElementById("title").value = title;

    document.getElementById("btn").innerText = "Update";
}
</script>

</body>
</html>