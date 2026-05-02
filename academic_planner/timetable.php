<?php
//Start the session to track the logged-in user
session_start();

//Load database connection and module colour helper
require "db.php";
require "module_colours.php";

//Redirect to login page if user not logged in
$user_id = $_SESSION["user_id"] ?? null;
if (!$user_id) {
    header("Location: index.php");
    exit();
}

// Retrieve modules created by the logged-in user
$query = $conn->prepare("
    SELECT module_id, module_name
    FROM modules
    WHERE user_id = ?
");
$query->bind_param("i", $user_id);
$query->execute();
$modules = $query->get_result();

//Add or update a timetable event//
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $event_id = $_POST["event_id"] ?? null;
    $event_id = ($event_id === "" || $event_id === null) ? 0 : intval($event_id);

    $day = trim($_POST["day_of_week"]);
    $start_time = $_POST["start_time"];
    $end_time = $_POST["end_time"];
    $event_type = $_POST["event_type"];
    $module_id = $_POST["module_id"];

    //Validate day of week
    $valid_days = [
        "Monday","Tuesday","Wednesday","Thursday","Friday","Saturday","Sunday"
    ];

    if (!in_array($day, $valid_days)) {
        $_SESSION["error"] = "Invalid day selected.";
        header("Location: timetable.php");
        exit();
    }

    //Validate time range
    if (strtotime($start_time) >= strtotime($end_time)) {
        $_SESSION["error"] = "End time must be later than start time.";
        header("Location: timetable.php");
        exit();
    }

    //Check for overlapping events
    $query = $conn->prepare("
        SELECT 1
        FROM timetable_events
        JOIN modules ON timetable_events.module_id = modules.module_id
        WHERE modules.user_id = ?
          AND day_of_week = ?
          AND event_id != ?
          AND start_time < ?
          AND end_time > ?
    ");

    $query->bind_param(
        "isiss",
        $user_id,
        $day,
        $event_id,
        $end_time,
        $start_time
    );

    $query->execute();
    $overlap = $query->get_result();
    //if events overlap, show error message
    if ($overlap->num_rows > 0) {
        $_SESSION['error'] = 'This event overlaps with an existing event.';
        header('Location: timetable.php');
        exit();
    }

    //Insert new event
    if ($event_id === 0) {

        $query = $conn->prepare("
            INSERT INTO timetable_events
            (module_id, day_of_week, start_time, end_time, event_type)
            VALUES (?, ?, ?, ?, ?)
        ");
        $query->bind_param("issss", $module_id, $day, $start_time, $end_time, $event_type);
        $query->execute();

    } else {
        //Update existing event
        $query = $conn->prepare("
            UPDATE timetable_events
            SET day_of_week=?, start_time=?, end_time=?, event_type=?, module_id=?
            WHERE event_id=?
        ");
        $query->bind_param("ssssii", $day, $start_time, $end_time, $event_type, $module_id, $event_id);
        $query->execute();
    }

    header("Location: timetable.php");
    exit();
}

/* Delete a timetable event */
if (isset($_GET["delete"])) {

    $event_id = intval($_GET["delete"]);

    $query = $conn->prepare("
        DELETE FROM timetable_events
        WHERE event_id = ?
    ");
    $query->bind_param("i", $event_id);
    $query->execute();

    header("Location: timetable.php");
    exit();
}

//Retrieve all timetable events for the logged-in user
$query = $conn->prepare("
    SELECT timetable_events.*, modules.module_name
    FROM timetable_events
    JOIN modules ON timetable_events.module_id = modules.module_id
    WHERE modules.user_id = ?
    ORDER BY FIELD(day_of_week,
        'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'
    ), start_time ASC
");
$query->bind_param("i", $user_id);
$query->execute();
$result = $query->get_result();

//Organise events by day for display
$days = ["Monday","Tuesday","Wednesday","Thursday","Friday","Saturday","Sunday"];
$slots = [];

while ($row = $result->fetch_assoc()) {
    $slots[$row["day_of_week"]][] = $row;
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>Timetable</title>
    <link rel="stylesheet" href="assets/app.css">
    <link rel="stylesheet" href="assets/timetable.css">
</head>

<body>

<div class="container">
<!--Navigation bar -->
<div class="navbar">
    <h2>Academic Planner</h2>
    <div>
        <a href="dashboard.php">Dashboard</a>
        <a href="timetable.php" class="active">Timetable</a>
        <a href="tasks.php">Tasks</a>
        <a href="study_preferences.php">Preferences</a>
        <a href="planner.php">Planner</a>
        <a href="logout.php">Logout</a>
    </div>
</div>

<div class="split">

<!-- Form container -->
<div class="card" id="timetable-form">

<!-- Form title and description -->
<h2>Add / Edit Timetable Event</h2>
<p class="page-description">
Add all your fixed weekly commitments here: lectures, practicals, meetings, and anything else that has a set time. These are the events the planner will never move, so keeping this timetable up to date ensures your study plan fits around your real week.
</p>

<form method="POST">
<!-- Form inpur fields -->
<input type="hidden" name="event_id" id="edit_id">

<label>Day</label>
<select name="day_of_week" id="day" required>
    <?php foreach ($days as $d): ?>
        <option value="<?= $d ?>"><?= $d ?></option>
    <?php endforeach; ?>
</select>

<label>Start Time</label>
<input type="time" name="start_time" id="start_time" required>

<label>End Time</label>
<input type="time" name="end_time" id="end_time" required>

<label>Type</label>
<select name="event_type" id="type">
    <option>Lecture</option>
    <option>Practical</option>
    <option>Meeting</option>
</select>

<label>Module</label>
<select name="module_id" id="module_id" required>
    <option value="">Select Module</option>
    <?php while ($m = $modules->fetch_assoc()): ?>
        <option value="<?= $m['module_id'] ?>">
            <?= htmlspecialchars($m['module_name']) ?>
        </option>
    <?php endwhile; ?>
</select>
<!-- Submit button -->
<button type="submit" id="btn">Add</button>

    <!--Error message -->
<?php if (isset($_SESSION["error"])): ?>
    <div class="error-message"><?= $_SESSION["error"]; ?></div>
    <?php unset($_SESSION["error"]); ?>
<?php endif; ?>

</form>

</div>

<!-- Weekly timetable -->
<div class="card">

<h2>Weekly Timetable</h2>

<div class="week">

<?php foreach ($days as $day): ?>
<div class="day-column">
    <div class="header"><?= $day ?></div>

    <?php if (!empty($slots[$day])): ?>
        <?php foreach ($slots[$day] as $item): ?>
            <?php $colour = getModuleColour($item["module_id"]); ?>

            <div class="event" style="--module-colour: <?= $colour ?>;">
                <strong><?= htmlspecialchars($item["module_name"]) ?></strong><br>
                <?= $item["event_type"] ?><br>
                <?= date("H:i", strtotime($item["start_time"])) ?>
 -
<?= date("H:i", strtotime($item["end_time"])) ?>

             
                <div class="actions">
                    <!-- Edit button -->
                    <button type="button" class="edit"
                        onclick="editItem(
                            '<?= $item['event_id'] ?>',
                            '<?= $item['day_of_week'] ?>',
                            '<?= $item['start_time'] ?>',
                            '<?= $item['end_time'] ?>',
                            '<?= $item['event_type'] ?>',
                            '<?= $item['module_id'] ?>'
                        )">
                        Edit
                    </button>
                    <!--Delete button --> 
                    <a class="delete"
                       href="?delete=<?= $item['event_id'] ?>"
                       onclick="return confirm('Delete?')">
                       Delete
                    </a>
                </div>
            </div>

        <?php endforeach; ?>
    <?php else: ?>
        <p class="empty-day">No events</p>
    <?php endif; ?>

</div>
<?php endforeach; ?>

</div>

</div>

</div>

</div>

<script>
//Fill the form with the selected event's details for editing
function editItem(id, day, start, end, type, module) {
    document.getElementById("edit_id").value = id;
    document.getElementById("day").value = day;
    document.getElementById("start_time").value = start;
    document.getElementById("end_time").value = end;
    document.getElementById("type").value = type;
    document.getElementById("module_id").value = module;
    //Change button text to indicate update mode
    document.getElementById("btn").innerText = "Update";
    
    //On mobile, scroll the form into view for easier editing
    if (window.innerWidth <= 900) {
        document.getElementById("timetable-form").scrollIntoView({
            behavior: "smooth",
            block: "start"
        });
    }
}
</script>

</body>
</html>
