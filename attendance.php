<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

/* =========================================================
   DATABASE CONNECTION
========================================================= */

$conn = new mysqli(
    "localhost",
    "root",
    "",
    "student_management"
);

if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");


/* =========================================================
   NOTIFICATION HELPER
========================================================= */

require_once "notification_helper.php";


/* =========================================================
   USER SESSION
========================================================= */

$userId = (int)($_SESSION['user_id'] ?? 0);

$userName = $_SESSION['user_name'] ?? "Administrator";
$userRole = $_SESSION['user_role'] ?? "Admin";


/* =========================================================
   UNREAD NOTIFICATIONS
========================================================= */

$unreadCount = getUnreadNotificationCount(
    $conn,
    $userId
);


/* =========================================================
   VARIABLES
========================================================= */

$message = "";
$messageType = "";

$selectedDate = $_GET['date'] ?? date("Y-m-d");
$selectedClass = $_GET['class_id'] ?? "";
$search = trim($_GET['search'] ?? "");

$editMode = false;

$editAttendance = [
    "id" => "",
    "student_id" => "",
    "attendance_date" => date("Y-m-d"),
    "status" => "Present",
    "remarks" => ""
];


/* =========================================================
   DELETE ATTENDANCE
========================================================= */

if (isset($_GET['delete'])) {

    $deleteId = intval($_GET['delete']);

    if ($deleteId > 0) {

        $stmt = $conn->prepare("
            DELETE FROM attendance
            WHERE id = ?
        ");

        $stmt->bind_param("i", $deleteId);

        if ($stmt->execute()) {

            $message = "Attendance record deleted successfully.";
            $messageType = "success";

        } else {

            $message = "Unable to delete attendance record.";
            $messageType = "error";
        }

        $stmt->close();
    }
}


/* =========================================================
   EDIT ATTENDANCE
========================================================= */

if (isset($_GET['edit'])) {

    $editId = intval($_GET['edit']);

    if ($editId > 0) {

        $stmt = $conn->prepare("
            SELECT
                id,
                student_id,
                attendance_date,
                status,
                remarks
            FROM attendance
            WHERE id = ?
        ");

        $stmt->bind_param("i", $editId);
        $stmt->execute();

        $result = $stmt->get_result();

        if ($result->num_rows > 0) {

            $editAttendance = $result->fetch_assoc();
            $editMode = true;
        }

        $stmt->close();
    }
}


/* =========================================================
   UPDATE SINGLE ATTENDANCE
========================================================= */

if (isset($_POST['update_attendance'])) {

    $attendanceId = intval($_POST['attendance_id'] ?? 0);
    $studentId = intval($_POST['student_id'] ?? 0);
    $attendanceDate = $_POST['attendance_date'] ?? "";
    $status = $_POST['status'] ?? "";
    $remarks = trim($_POST['remarks'] ?? "");

    $allowedStatuses = [
        "Present",
        "Absent",
        "Late"
    ];

    if (
        $attendanceId <= 0 ||
        $studentId <= 0 ||
        empty($attendanceDate) ||
        !in_array($status, $allowedStatuses, true)
    ) {

        $message = "Please fill all required fields.";
        $messageType = "error";

    } else {

        $stmt = $conn->prepare("
            UPDATE attendance
            SET
                student_id = ?,
                attendance_date = ?,
                status = ?,
                remarks = ?
            WHERE id = ?
        ");

        $stmt->bind_param(
            "isssi",
            $studentId,
            $attendanceDate,
            $status,
            $remarks,
            $attendanceId
        );

        if ($stmt->execute()) {

            $message = "Attendance updated successfully.";
            $messageType = "success";

            createNotification(
                $conn,
                $userId,
                "Attendance Updated",
                "An attendance record has been successfully updated.",
                "success",
                "attendance.php"
            );

            $editMode = false;

            $editAttendance = [
                "id" => "",
                "student_id" => "",
                "attendance_date" => date("Y-m-d"),
                "status" => "Present",
                "remarks" => ""
            ];

        } else {

            $message = "Unable to update attendance.";
            $messageType = "error";
        }

        $stmt->close();
    }
}


/* =========================================================
   SAVE ALL ATTENDANCE
========================================================= */

if (isset($_POST['save_all_attendance'])) {

    $attendanceDate =
        $_POST['attendance_date']
        ?? date("Y-m-d");

    $studentStatuses =
        $_POST['status']
        ?? [];

    $studentRemarks =
        $_POST['remarks']
        ?? [];

    if (!empty($studentStatuses)) {

        $conn->begin_transaction();

        try {

            $savedCount = 0;
            $presentCount = 0;
            $absentCount = 0;
            $lateCount = 0;

            foreach (
                $studentStatuses
                as $studentId => $studentStatus
            ) {

                $studentId = intval($studentId);

                if ($studentId <= 0) {
                    continue;
                }

                $allowedStatuses = [
                    "Present",
                    "Absent",
                    "Late"
                ];

                if (
                    !in_array(
                        $studentStatus,
                        $allowedStatuses,
                        true
                    )
                ) {

                    $studentStatus = "Present";
                }

                $remark =
                    trim(
                        $studentRemarks[$studentId]
                        ?? ""
                    );


                /* STATUS COUNT */

                if ($studentStatus === "Present") {
                    $presentCount++;
                }

                elseif ($studentStatus === "Absent") {
                    $absentCount++;
                }

                elseif ($studentStatus === "Late") {
                    $lateCount++;
                }


                /* CHECK EXISTING */

                $check = $conn->prepare("
                    SELECT id
                    FROM attendance
                    WHERE student_id = ?
                    AND attendance_date = ?
                    LIMIT 1
                ");

                $check->bind_param(
                    "is",
                    $studentId,
                    $attendanceDate
                );

                $check->execute();

                $checkResult =
                    $check->get_result();


                if ($checkResult->num_rows > 0) {

                    /* UPDATE */

                    $existing =
                        $checkResult->fetch_assoc();

                    $attendanceId =
                        intval(
                            $existing['id']
                        );

                    $update =
                        $conn->prepare("
                            UPDATE attendance
                            SET
                                status = ?,
                                remarks = ?
                            WHERE id = ?
                        ");

                    $update->bind_param(
                        "ssi",
                        $studentStatus,
                        $remark,
                        $attendanceId
                    );

                    if (!$update->execute()) {

                        throw new Exception(
                            "Failed to update attendance."
                        );
                    }

                    $update->close();

                } else {

                    /* INSERT */

                    $insert =
                        $conn->prepare("
                            INSERT INTO attendance
                            (
                                student_id,
                                attendance_date,
                                status,
                                remarks
                            )
                            VALUES (?, ?, ?, ?)
                        ");

                    $insert->bind_param(
                        "isss",
                        $studentId,
                        $attendanceDate,
                        $studentStatus,
                        $remark
                    );

                    if (!$insert->execute()) {

                        throw new Exception(
                            "Failed to save attendance."
                        );
                    }

                    $insert->close();
                }

                $check->close();

                $savedCount++;
            }


            $conn->commit();


            /* AUTOMATIC NOTIFICATION */

            createNotification(
                $conn,
                $userId,
                "Attendance Saved",
                "Attendance for " .
                $savedCount .
                " student(s) has been saved successfully. Present: " .
                $presentCount .
                ", Absent: " .
                $absentCount .
                ", Late: " .
                $lateCount . ".",
                "success",
                "attendance.php"
            );


            $message =
                "Attendance saved successfully for " .
                $savedCount .
                " student(s).";

            $messageType = "success";

            $selectedDate =
                $attendanceDate;

        }

        catch (Exception $e) {

            $conn->rollback();

            $message =
                "Attendance could not be saved. Please try again.";

            $messageType = "error";
        }

    } else {

        $message =
            "No students were selected.";

        $messageType = "error";
    }
}


/* =========================================================
   REFRESH NOTIFICATION COUNT
========================================================= */

$unreadCount =
    getUnreadNotificationCount(
        $conn,
        $userId
    );


/* =========================================================
   GET CLASSES
========================================================= */

$classes =
    $conn->query("
        SELECT
            id,
            class_name,
            section
        FROM classes
        ORDER BY class_name ASC, section ASC
    ");


/* =========================================================
   TOTAL STUDENTS
========================================================= */

$totalStudents = 0;

$result =
    $conn->query("
        SELECT COUNT(*) AS total
        FROM students
    ");

if ($result) {

    $row =
        $result->fetch_assoc();

    $totalStudents =
        intval($row['total']);
}


/* =========================================================
   ATTENDANCE STATISTICS
========================================================= */

$presentToday = 0;
$absentToday = 0;
$lateToday = 0;
$markedToday = 0;

$stmt =
    $conn->prepare("
        SELECT
            COUNT(*) AS total_marked,
            COALESCE(
                SUM(status = 'Present'),
                0
            ) AS present_count,
            COALESCE(
                SUM(status = 'Absent'),
                0
            ) AS absent_count,
            COALESCE(
                SUM(status = 'Late'),
                0
            ) AS late_count
        FROM attendance
        WHERE attendance_date = ?
    ");

$stmt->bind_param(
    "s",
    $selectedDate
);

$stmt->execute();

$statsResult =
    $stmt->get_result();

if ($statsResult->num_rows > 0) {

    $stats =
        $statsResult->fetch_assoc();

    $markedToday =
        intval(
            $stats['total_marked']
        );

    $presentToday =
        intval(
            $stats['present_count']
        );

    $absentToday =
        intval(
            $stats['absent_count']
        );

    $lateToday =
        intval(
            $stats['late_count']
        );
}

$stmt->close();


/* =========================================================
   ATTENDANCE RATE
========================================================= */

$attendanceRate = 0;

if ($markedToday > 0) {

    $attendanceRate =
        round(
            ($presentToday / $markedToday)
            * 100
        );
}


/* =========================================================
   STUDENT LIST
========================================================= */

$studentSql = "
    SELECT
        s.id,
        s.student_id,
        s.full_name,
        s.gender,
        s.class_id,
        c.class_name,
        c.section
    FROM students s
    LEFT JOIN classes c
        ON s.class_id = c.id
    WHERE 1 = 1
";

$params = [];
$types = "";


/* CLASS FILTER */

if ($selectedClass !== "") {

    $studentSql .= "
        AND s.class_id = ?
    ";

    $params[] =
        intval($selectedClass);

    $types .= "i";
}


/* SEARCH */

if ($search !== "") {

    $studentSql .= "
        AND (
            s.full_name LIKE ?
            OR s.student_id LIKE ?
        )
    ";

    $searchValue =
        "%" . $search . "%";

    $params[] =
        $searchValue;

    $params[] =
        $searchValue;

    $types .= "ss";
}


$studentSql .= "
    ORDER BY s.full_name ASC
";


$stmt =
    $conn->prepare(
        $studentSql
    );

if (!empty($params)) {

    $stmt->bind_param(
        $types,
        ...$params
    );
}

$stmt->execute();

$studentsResult =
    $stmt->get_result();


/* =========================================================
   EXISTING ATTENDANCE
========================================================= */

$existingAttendance = [];

$attendanceStmt =
    $conn->prepare("
        SELECT
            student_id,
            status,
            remarks
        FROM attendance
        WHERE attendance_date = ?
    ");

$attendanceStmt->bind_param(
    "s",
    $selectedDate
);

$attendanceStmt->execute();

$attendanceDataResult =
    $attendanceStmt->get_result();

while (
    $attendance =
        $attendanceDataResult->fetch_assoc()
) {

    $existingAttendance[
        $attendance['student_id']
    ] = [
        "status" =>
            $attendance['status'],

        "remarks" =>
            $attendance['remarks']
    ];
}

$attendanceStmt->close();


/* =========================================================
   ATTENDANCE HISTORY
========================================================= */

$historySql = "
    SELECT
        a.id,
        a.student_id,
        a.attendance_date,
        a.status,
        a.remarks,
        s.full_name,
        s.student_id AS student_code,
        c.class_name,
        c.section
    FROM attendance a
    INNER JOIN students s
        ON a.student_id = s.id
    LEFT JOIN classes c
        ON s.class_id = c.id
    WHERE 1 = 1
";

$historyParams = [];
$historyTypes = "";


if ($selectedClass !== "") {

    $historySql .= "
        AND s.class_id = ?
    ";

    $historyParams[] =
        intval($selectedClass);

    $historyTypes .= "i";
}


$historySql .= "
    ORDER BY
        a.attendance_date DESC,
        a.id DESC
    LIMIT 100
";


$historyStmt =
    $conn->prepare(
        $historySql
    );

if (!empty($historyParams)) {

    $historyStmt->bind_param(
        $historyTypes,
        ...$historyParams
    );
}

$historyStmt->execute();

$historyResult =
    $historyStmt->get_result();

?>

<!DOCTYPE html>

<html lang="en">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>
    Attendance Management | Cilmi_Doon
</title>


<!-- =====================================================
     PREVENT THEME FLASH
===================================================== -->

<script>

(function () {

    const theme =
        localStorage.getItem("theme");

    if (theme === "dark") {

        document.documentElement
            .classList
            .add("dark-mode");

    }

})();

</script>


<!-- FONT AWESOME -->

<link
    rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
>


<style>

/* =========================================================
   GLOBAL
========================================================= */

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
    font-family: Arial, Helvetica, sans-serif;
}

html {
    scroll-behavior: smooth;
}

body {
    background: #f8fafc;
    color: #0f172a;
    transition:
        background .25s ease,
        color .25s ease;
}

button,
input,
select,
textarea {
    font-family: inherit;
}

button,
a {
    -webkit-tap-highlight-color: transparent;
}


/* =========================================================
   SIDEBAR
========================================================= */

.sidebar {

    position: fixed;

    left: 0;
    top: 0;

    width: 260px;
    height: 100vh;

    background: #0f172a;

    color: white;

    z-index: 1000;

    display: flex;

    flex-direction: column;

    overflow-y: auto;
    overflow-x: hidden;

    transition:
        transform .3s ease,
        box-shadow .3s ease;

}

.logo {

    height: 70px;
    min-height: 70px;

    display: flex;

    align-items: center;

    gap: 12px;

    padding: 0 20px;

    border-bottom:
        1px solid
        rgba(255,255,255,.08);

}

.logo-icon {

    width: 45px;
    height: 45px;

    display: flex;

    align-items: center;
    justify-content: center;

    background: #2563eb;

    border-radius: 10px;

    font-size: 20px;

}

.logo-text {

    font-size: 18px;

    font-weight: 700;

    white-space: nowrap;

}

.menu {

    list-style: none;

    padding: 0 12px;

    margin: 0;

}

.menu-title {

    color: #64748b;

    font-size: 11px;

    font-weight: bold;

    margin:
        20px 8px 8px;

    text-transform: uppercase;

    letter-spacing: .5px;

}

.menu a {

    display: flex;

    align-items: center;

    gap: 13px;

    padding: 12px 13px;

    color: #cbd5e1;

    border-radius: 9px;

    margin-bottom: 4px;

    font-size: 14px;

    text-decoration: none;

    transition: .2s;

}

.menu a:hover {

    background: #1e293b;

    color: white;

}

.menu a.active {

    background: #2563eb;

    color: white;

}

.menu a i {

    width: 20px;

    min-width: 20px;

    text-align: center;

}

.logout {

    position: static;

    margin-top: auto;

    padding: 15px 12px;

    border-top:
        1px solid
        rgba(255,255,255,.08);

}

.logout a {

    display: flex;

    align-items: center;

    gap: 13px;

    padding: 12px 13px;

    color: #fca5a5;

    border-radius: 9px;

    font-size: 14px;

    text-decoration: none;

}

.logout a:hover {

    background: #7f1d1d;

    color: white;

}


/* =========================================================
   MAIN
========================================================= */

.main {

    margin-left: 260px;

    min-height: 100vh;

}


/* =========================================================
   TOPBAR
========================================================= */

.topbar {

    height: 70px;

    background: white;

    border-bottom:
        1px solid
        #e2e8f0;

    display: flex;

    align-items: center;

    justify-content: flex-end;

    padding: 0 30px;

    gap: 12px;

    position: sticky;

    top: 0;

    z-index: 100;

    transition:
        background .25s ease,
        border-color .25s ease;

}

.menu-btn,
.theme-toggle {

    width: 40px;
    height: 40px;

    border:
        1px solid
        #e2e8f0;

    border-radius: 50%;

    display: flex;

    align-items: center;

    justify-content: center;

    cursor: pointer;

    background: white;

    color: #475569;

    font-size: 17px;

    transition: .2s;

}

.menu-btn {

    display: none;

    border-radius: 8px;

}

.menu-btn:hover,
.theme-toggle:hover {

    background: #f1f5f9;

    color: #2563eb;

}


/* =========================================================
   NOTIFICATION
========================================================= */

.notification {

    width: 40px;
    height: 40px;

    border-radius: 50%;

    display: flex;

    align-items: center;
    justify-content: center;

    position: relative;

    color: #475569;

    font-size: 19px;

    text-decoration: none;

    cursor: pointer;

    transition: .2s;

}

.notification:hover {

    background: #f1f5f9;

    color: #2563eb;

}

.notification-badge {

    position: absolute;

    top: -3px;
    right: -3px;

    min-width: 19px;
    height: 19px;

    padding: 0 5px;

    background: #ef4444;

    color: white;

    border-radius: 20px;

    font-size: 10px;

    font-weight: bold;

    display: flex;

    align-items: center;

    justify-content: center;

    border: 2px solid white;

    line-height: 1;

}


/* =========================================================
   USER
========================================================= */

.user {

    display: flex;

    align-items: center;

    gap: 10px;

    margin-left: 4px;

}

.avatar {

    width: 40px;
    height: 40px;

    border-radius: 50%;

    background: #2563eb;

    color: white;

    display: flex;

    align-items: center;

    justify-content: center;

    font-weight: bold;

}

.user-info strong {

    display: block;

    font-size: 14px;

}

.user-info small {

    color: #64748b;

    font-size: 12px;

}


/* =========================================================
   CONTENT
========================================================= */

.content {

    padding: 30px;

}

.page-header {

    display: flex;

    align-items: center;

    justify-content: space-between;

    gap: 20px;

    margin-bottom: 25px;

}

.page-title h1 {

    font-size: 27px;

    margin-bottom: 6px;

}

.page-title p {

    color: #64748b;

    font-size: 14px;

}


/* =========================================================
   ALERT
========================================================= */

.alert {

    padding: 14px 17px;

    border-radius: 10px;

    margin-bottom: 22px;

    display: flex;

    align-items: center;

    gap: 10px;

    font-size: 14px;

}

.alert.success {

    background: #dcfce7;

    border:
        1px solid
        #bbf7d0;

    color: #166534;

}

.alert.error {

    background: #fee2e2;

    border:
        1px solid
        #fecaca;

    color: #991b1b;

}


/* =========================================================
   STATISTICS
========================================================= */

.stats-grid {

    display: grid;

    grid-template-columns:
        repeat(5, minmax(0, 1fr));

    gap: 16px;

    margin-bottom: 25px;

}

.stat-card {

    background: white;

    border:
        1px solid
        #e2e8f0;

    border-radius: 14px;

    padding: 20px;

    position: relative;

    overflow: hidden;

    transition:
        background .25s ease,
        border-color .25s ease,
        transform .2s ease;

}

.stat-card:hover {

    transform: translateY(-2px);

}

.stat-card::after {

    content: "";

    position: absolute;

    width: 70px;
    height: 70px;

    border-radius: 50%;

    right: -25px;
    bottom: -25px;

    background: #f1f5f9;

}

.stat-icon {

    width: 43px;
    height: 43px;

    border-radius: 10px;

    display: flex;

    align-items: center;
    justify-content: center;

    margin-bottom: 14px;

    font-size: 18px;

}

.blue {

    background: #dbeafe;
    color: #2563eb;

}

.green {

    background: #dcfce7;
    color: #16a34a;

}

.red {

    background: #fee2e2;
    color: #dc2626;

}

.yellow {

    background: #fef3c7;
    color: #d97706;

}

.purple {

    background: #ede9fe;
    color: #7c3aed;

}

.stat-card h3 {

    font-size: 25px;

    margin-bottom: 5px;

}

.stat-card p {

    color: #64748b;

    font-size: 13px;

}


/* =========================================================
   ATTENDANCE PANEL
========================================================= */

.attendance-panel {

    background: white;

    border:
        1px solid
        #e2e8f0;

    border-radius: 15px;

    margin-bottom: 25px;

    overflow: hidden;

}

.panel-header {

    padding: 20px 22px;

    border-bottom:
        1px solid
        #e2e8f0;

    display: flex;

    align-items: center;

    justify-content: space-between;

    gap: 20px;

}

.panel-title h2 {

    font-size: 18px;

    margin-bottom: 5px;

}

.panel-title p {

    font-size: 13px;

    color: #64748b;

}

.panel-actions {

    display: flex;

    gap: 9px;

    flex-wrap: wrap;

}


/* =========================================================
   BUTTONS
========================================================= */

.btn {

    border: none;

    padding: 10px 15px;

    border-radius: 8px;

    font-size: 13px;

    font-weight: 600;

    display: inline-flex;

    align-items: center;

    justify-content: center;

    gap: 8px;

    cursor: pointer;

    text-decoration: none;

    transition: .2s;

}

.btn-primary {

    background: #2563eb;

    color: white;

}

.btn-primary:hover {

    background: #1d4ed8;

}

.btn-success {

    background: #16a34a;

    color: white;

}

.btn-success:hover {

    background: #15803d;

}

.btn-light {

    background: #f1f5f9;

    color: #334155;

    border:
        1px solid
        #e2e8f0;

}

.btn-light:hover {

    background: #e2e8f0;

}

.btn-danger {

    background: #fee2e2;

    color: #dc2626;

}

.btn-warning {

    background: #fef3c7;

    color: #b45309;

}


/* =========================================================
   FILTERS
========================================================= */

.filters {

    padding: 18px 22px;

    background: #f8fafc;

    border-bottom:
        1px solid
        #e2e8f0;

    display: grid;

    grid-template-columns:
        180px
        220px
        minmax(180px, 1fr)
        auto;

    gap: 12px;

    align-items: end;

}

.filter-group label {

    display: block;

    font-size: 11px;

    font-weight: 700;

    color: #475569;

    margin-bottom: 6px;

    text-transform: uppercase;

}

.filter-group input,
.filter-group select {

    width: 100%;

    height: 40px;

    border:
        1px solid
        #cbd5e1;

    border-radius: 8px;

    background: white;

    padding: 0 11px;

    outline: none;

    font-size: 13px;

}

.filter-group input:focus,
.filter-group select:focus {

    border-color: #2563eb;

    box-shadow:
        0 0 0 3px
        rgba(37,99,235,.10);

}

.search-wrapper {

    position: relative;

}

.search-wrapper i {

    position: absolute;

    left: 12px;

    top: 50%;

    transform:
        translateY(-50%);

    color: #94a3b8;

}

.search-wrapper input {

    padding-left: 35px;

}


/* =========================================================
   TABLE
========================================================= */

.attendance-table-wrapper {

    overflow-x: auto;

    -webkit-overflow-scrolling: touch;

}

.attendance-table {

    width: 100%;

    border-collapse: collapse;

    min-width: 1000px;

}

.attendance-table thead {

    background: #f8fafc;

}

.attendance-table th {

    padding: 13px 16px;

    text-align: left;

    font-size: 11px;

    text-transform: uppercase;

    letter-spacing: .4px;

    color: #64748b;

    border-bottom:
        1px solid
        #e2e8f0;

}

.attendance-table td {

    padding: 12px 16px;

    border-bottom:
        1px solid
        #f1f5f9;

    font-size: 13px;

    vertical-align: middle;

}

.attendance-table tbody tr:hover {

    background: #f8fafc;

}

.student-info {

    display: flex;

    align-items: center;

    gap: 11px;

}

.student-avatar {

    width: 38px;
    height: 38px;

    border-radius: 9px;

    background: #dbeafe;

    color: #2563eb;

    display: flex;

    align-items: center;
    justify-content: center;

    font-weight: 700;

    font-size: 13px;

    flex-shrink: 0;

}

.student-name {

    font-weight: 600;

    color: #0f172a;

    margin-bottom: 3px;

}

.student-code {

    font-size: 11px;

    color: #94a3b8;

}

.class-badge {

    display: inline-block;

    padding: 5px 9px;

    border-radius: 6px;

    background: #f1f5f9;

    color: #475569;

    font-size: 11px;

    font-weight: 600;

}


/* =========================================================
   STATUS
========================================================= */

.status-select {

    min-width: 125px;

    height: 36px;

    border-radius: 7px;

    padding: 0 10px;

    border:
        1px solid
        #cbd5e1;

    outline: none;

    font-size: 12px;

    font-weight: 600;

    cursor: pointer;

    background: white;

}

.status-select.present {

    background: #f0fdf4;

    color: #15803d;

    border-color: #bbf7d0;

}

.status-select.absent {

    background: #fef2f2;

    color: #dc2626;

    border-color: #fecaca;

}

.status-select.late {

    background: #fffbeb;

    color: #b45309;

    border-color: #fde68a;

}

.remark-input {

    width: 180px;

    height: 36px;

    border:
        1px solid
        #cbd5e1;

    border-radius: 7px;

    padding: 0 10px;

    outline: none;

    font-size: 12px;

    background: white;

    color: #0f172a;

}

.remark-input:focus {

    border-color: #2563eb;

}


/* =========================================================
   TABLE FOOTER
========================================================= */

.table-footer {

    padding: 16px 22px;

    background: #f8fafc;

    border-top:
        1px solid
        #e2e8f0;

    display: flex;

    align-items: center;

    justify-content: space-between;

    gap: 15px;

}

.selected-count {

    font-size: 13px;

    color: #64748b;

}

.selected-count strong {

    color: #0f172a;

}


/* =========================================================
   HISTORY
========================================================= */

.history-card {

    background: white;

    border:
        1px solid
        #e2e8f0;

    border-radius: 15px;

    overflow: hidden;

}

.history-header {

    padding: 20px 22px;

    border-bottom:
        1px solid
        #e2e8f0;

}

.history-header h2 {

    font-size: 18px;

}

.history-header p {

    color: #64748b;

    font-size: 13px;

    margin-top: 4px;

}

.history-wrapper {

    overflow-x: auto;

    -webkit-overflow-scrolling: touch;

}

.history-table {

    width: 100%;

    border-collapse: collapse;

    min-width: 900px;

}

.history-table th,
.history-table td {

    padding: 13px 16px;

    border-bottom:
        1px solid
        #e2e8f0;

    text-align: left;

    font-size: 13px;

}

.history-table th {

    background: #f8fafc;

    color: #64748b;

    font-size: 11px;

    text-transform: uppercase;

}


/* =========================================================
   STATUS BADGES
========================================================= */

.status-badge {

    display: inline-flex;

    align-items: center;

    gap: 6px;

    padding: 6px 10px;

    border-radius: 20px;

    font-size: 11px;

    font-weight: 700;

}

.status-present {

    background: #dcfce7;

    color: #15803d;

}

.status-absent {

    background: #fee2e2;

    color: #dc2626;

}

.status-late {

    background: #fef3c7;

    color: #b45309;

}


/* =========================================================
   ACTION BUTTONS
========================================================= */

.action-buttons {

    display: flex;

    gap: 6px;

}

.action-btn {

    width: 32px;
    height: 32px;

    display: flex;

    align-items: center;
    justify-content: center;

    border-radius: 7px;

    text-decoration: none;

    font-size: 12px;

}

.action-edit {

    background: #dbeafe;

    color: #2563eb;

}

.action-delete {

    background: #fee2e2;

    color: #dc2626;

}

.action-edit:hover {

    background: #bfdbfe;

}

.action-delete:hover {

    background: #fecaca;

}


/* =========================================================
   MODAL
========================================================= */

.modal {

    position: fixed;

    inset: 0;

    background:
        rgba(15,23,42,.55);

    display: flex;

    align-items: center;

    justify-content: center;

    padding: 20px;

    z-index: 2000;

    overflow-y: auto;

}

.modal-box {

    width: 100%;

    max-width: 520px;

    background: white;

    border-radius: 15px;

    box-shadow:
        0 20px 50px
        rgba(0,0,0,.2);

    overflow: hidden;

}

.modal-header {

    padding: 18px 20px;

    border-bottom:
        1px solid
        #e2e8f0;

    display: flex;

    align-items: center;

    justify-content: space-between;

}

.modal-header h3 {

    font-size: 18px;

}

.close-modal {

    text-decoration: none;

    color: #64748b;

    font-size: 18px;

}

.modal-body {

    padding: 22px;

}

.form-group {

    margin-bottom: 17px;

}

.form-group label {

    display: block;

    font-size: 12px;

    font-weight: 700;

    color: #475569;

    margin-bottom: 7px;

}

.form-control {

    width: 100%;

    height: 42px;

    border:
        1px solid
        #cbd5e1;

    border-radius: 8px;

    padding: 0 12px;

    outline: none;

    font-size: 13px;

    background: white;

    color: #0f172a;

}

textarea.form-control {

    height: 90px;

    padding-top: 11px;

    resize: vertical;

}

.form-control:focus {

    border-color: #2563eb;

    box-shadow:
        0 0 0 3px
        rgba(37,99,235,.10);

}

.modal-footer {

    padding: 16px 20px;

    border-top:
        1px solid
        #e2e8f0;

    display: flex;

    justify-content: flex-end;

    gap: 8px;

}


/* =========================================================
   EMPTY STATE
========================================================= */

.empty-state {

    text-align: center;

    padding: 50px 20px;

    color: #64748b;

}

.empty-state i {

    font-size: 38px;

    margin-bottom: 15px;

    color: #cbd5e1;

}

.empty-state h3 {

    color: #475569;

    margin-bottom: 5px;

}

.empty-state p {

    font-size: 13px;

}


/* =========================================================
   DARK MODE
========================================================= */

html.dark-mode body {

    background: #0f172a;

    color: #e2e8f0;

}


/* TOPBAR */

html.dark-mode .topbar {

    background: #1e293b;

    border-color: #334155;

}


/* THEME BUTTON */

html.dark-mode .theme-toggle {

    background: #334155;

    color: #f8fafc;

    border-color: #475569;

}

html.dark-mode .theme-toggle:hover {

    background: #475569;

    color: #facc15;

}


/* MENU BUTTON */

html.dark-mode .menu-btn {

    background: #1e293b;

    color: #f8fafc;

    border-color: #475569;

}


/* NOTIFICATION */

html.dark-mode .notification {

    color: #cbd5e1;

}

html.dark-mode .notification:hover {

    background: #334155;

    color: #60a5fa;

}

html.dark-mode .notification-badge {

    border-color: #1e293b;

}


/* USER */

html.dark-mode .user-info strong {

    color: #f8fafc;

}

html.dark-mode .user-info small {

    color: #94a3b8;

}


/* PAGE */

html.dark-mode .page-title h1 {

    color: #f8fafc;

}

html.dark-mode .page-title p {

    color: #94a3b8;

}


/* STAT CARDS */

html.dark-mode .stat-card {

    background: #1e293b;

    border-color: #334155;

}

html.dark-mode .stat-card::after {

    background: #334155;

}

html.dark-mode .stat-card h3 {

    color: #f8fafc;

}

html.dark-mode .stat-card p {

    color: #94a3b8;

}


/* STAT COLORS */

html.dark-mode .blue {

    background:
        rgba(37,99,235,.18);

    color: #60a5fa;

}

html.dark-mode .green {

    background:
        rgba(22,163,74,.18);

    color: #4ade80;

}

html.dark-mode .red {

    background:
        rgba(220,38,38,.18);

    color: #f87171;

}

html.dark-mode .yellow {

    background:
        rgba(217,119,6,.18);

    color: #fbbf24;

}

html.dark-mode .purple {

    background:
        rgba(124,58,237,.18);

    color: #a78bfa;

}


/* PANELS */

html.dark-mode .attendance-panel,
html.dark-mode .history-card {

    background: #1e293b;

    border-color: #334155;

}


/* PANEL HEADER */

html.dark-mode .panel-header,
html.dark-mode .history-header {

    border-color: #334155;

}

html.dark-mode .panel-title h2,
html.dark-mode .history-header h2 {

    color: #f8fafc;

}

html.dark-mode .panel-title p,
html.dark-mode .history-header p {

    color: #94a3b8;

}


/* FILTER */

html.dark-mode .filters {

    background: #172033;

    border-color: #334155;

}

html.dark-mode .filter-group label {

    color: #cbd5e1;

}

html.dark-mode .filter-group input,
html.dark-mode .filter-group select {

    background: #0f172a;

    color: #e2e8f0;

    border-color: #475569;

}


/* TABLE */

html.dark-mode .attendance-table thead,
html.dark-mode .history-table th {

    background: #0f172a;

}

html.dark-mode .attendance-table th,
html.dark-mode .history-table th {

    color: #94a3b8;

    border-color: #334155;

}

html.dark-mode .attendance-table td,
html.dark-mode .history-table td {

    color: #cbd5e1;

    border-color: #334155;

}

html.dark-mode .attendance-table tbody tr:hover {

    background: #263449;

}


/* STUDENT */

html.dark-mode .student-name {

    color: #f8fafc;

}

html.dark-mode .student-code {

    color: #94a3b8;

}

html.dark-mode .student-avatar {

    background:
        rgba(37,99,235,.18);

    color: #60a5fa;

}

html.dark-mode .class-badge {

    background: #334155;

    color: #cbd5e1;

}


/* STATUS SELECT */

html.dark-mode .status-select {

    background: #0f172a;

    color: #e2e8f0;

    border-color: #475569;

}

html.dark-mode .status-select.present {

    background:
        rgba(22,163,74,.15);

    color: #4ade80;

    border-color: #166534;

}

html.dark-mode .status-select.absent {

    background:
        rgba(220,38,38,.15);

    color: #f87171;

    border-color: #991b1b;

}

html.dark-mode .status-select.late {

    background:
        rgba(217,119,6,.15);

    color: #fbbf24;

    border-color: #92400e;

}


/* REMARK */

html.dark-mode .remark-input {

    background: #0f172a;

    color: #e2e8f0;

    border-color: #475569;

}


/* TABLE FOOTER */

html.dark-mode .table-footer {

    background: #172033;

    border-color: #334155;

}

html.dark-mode .selected-count {

    color: #94a3b8;

}

html.dark-mode .selected-count strong {

    color: #f8fafc;

}


/* LIGHT BUTTON */

html.dark-mode .btn-light {

    background: #334155;

    color: #e2e8f0;

    border-color: #475569;

}

html.dark-mode .btn-light:hover {

    background: #475569;

}


/* STATUS BADGES */

html.dark-mode .status-present {

    background:
        rgba(22,163,74,.18);

    color: #4ade80;

}

html.dark-mode .status-absent {

    background:
        rgba(220,38,38,.18);

    color: #f87171;

}

html.dark-mode .status-late {

    background:
        rgba(217,119,6,.18);

    color: #fbbf24;

}


/* ACTION BUTTONS */

html.dark-mode .action-edit {

    background:
        rgba(37,99,235,.18);

    color: #60a5fa;

}

html.dark-mode .action-delete {

    background:
        rgba(220,38,38,.18);

    color: #f87171;

}


/* MODAL */

html.dark-mode .modal {

    background:
        rgba(0,0,0,.72);

}

html.dark-mode .modal-box {

    background: #1e293b;

    border:
        1px solid
        #334155;

}

html.dark-mode .modal-header {

    border-color: #334155;

}

html.dark-mode .modal-header h3 {

    color: #f8fafc;

}

html.dark-mode .close-modal {

    color: #94a3b8;

}

html.dark-mode .form-group label {

    color: #cbd5e1;

}

html.dark-mode .form-control {

    background: #0f172a;

    color: #e2e8f0;

    border-color: #475569;

}

html.dark-mode .modal-footer {

    border-color: #334155;

}


/* EMPTY */

html.dark-mode .empty-state {

    color: #94a3b8;

}

html.dark-mode .empty-state i {

    color: #475569;

}

html.dark-mode .empty-state h3 {

    color: #cbd5e1;

}


/* ALERT */

html.dark-mode .alert.success {

    background:
        rgba(22,163,74,.15);

    border-color: #166534;

    color: #86efac;

}

html.dark-mode .alert.error {

    background:
        rgba(220,38,38,.15);

    border-color: #991b1b;

    color: #fca5a5;

}


/* =========================================================
   OVERLAY
========================================================= */

.overlay {

    display: none;

    position: fixed;

    inset: 0;

    background:
        rgba(15,23,42,.55);

    z-index: 900;

}

.overlay.active {

    display: block;

}


/* =========================================================
   RESPONSIVE
========================================================= */

/* LARGE LAPTOP */

@media (max-width: 1250px) {

    .stats-grid {

        grid-template-columns:
            repeat(3, minmax(0,1fr));

    }

    .filters {

        grid-template-columns:
            1fr 1fr;

    }

}


/* TABLET */

@media (max-width: 1000px) {

    .stats-grid {

        grid-template-columns:
            repeat(2, minmax(0,1fr));

    }

}


/* MOBILE SIDEBAR */

@media (max-width: 900px) {

    .sidebar {

        transform:
            translateX(-100%);

        box-shadow:
            10px 0 30px
            rgba(0,0,0,.15);

    }

    .sidebar.active {

        transform:
            translateX(0);

    }

    .main {

        margin-left: 0;

        width: 100%;

    }

    .menu-btn {

        display: flex;

    }

    .topbar {

        justify-content:
            space-between;

        padding:
            0 20px;

    }

    .content {

        padding: 20px;

    }

}


/* SMALL TABLET */

@media (max-width: 700px) {

    .topbar {

        height: 65px;

        padding:
            0 15px;

    }

    .content {

        padding: 16px;

    }

    .page-header {

        align-items:
            flex-start;

        flex-direction:
            column;

        gap: 10px;

    }

    .page-title h1 {

        font-size: 23px;

    }

    .stats-grid {

        grid-template-columns:
            repeat(2, minmax(0,1fr));

        gap: 12px;

    }

    .stat-card {

        padding: 16px;

    }

    .stat-card h3 {

        font-size: 22px;

    }

    .panel-header {

        align-items:
            flex-start;

        flex-direction:
            column;

    }

    .panel-actions {

        width: 100%;

    }

    .panel-actions .btn {

        flex: 1;

    }

    .filters {

        grid-template-columns:
            1fr;

    }

    .table-footer {

        align-items:
            flex-start;

        flex-direction:
            column;

    }

    .table-footer .btn {

        width: 100%;

    }

    .user-info {

        display: none;

    }

}


/* MOBILE */

@media (max-width: 550px) {

    .topbar {

        height: 62px;

        padding:
            0 12px;

    }

    .menu-btn,
    .theme-toggle,
    .notification {

        width: 37px;

        height: 37px;

    }

    .avatar {

        width: 35px;

        height: 35px;

    }

    .content {

        padding: 13px;

    }

    .page-title h1 {

        font-size: 20px;

    }

    .page-title p {

        font-size: 12px;

    }

    .stats-grid {

        grid-template-columns:
            1fr;

        gap: 12px;

    }

    .stat-card {

        min-height: 100px;

    }

    .stat-card h3 {

        font-size: 24px;

    }

    .attendance-panel,
    .history-card {

        border-radius: 12px;

    }

    .panel-header,
    .history-header {

        padding: 16px;

    }

    .filters {

        padding: 15px;

    }

    .table-footer {

        padding: 15px;

    }

    .modal {

        padding: 12px;

        align-items:
            flex-start;

    }

    .modal-box {

        margin-top: 30px;

    }

    .modal-body {

        padding: 17px;

    }

    .modal-footer {

        padding: 14px;

        flex-direction:
            column-reverse;

    }

    .modal-footer .btn {

        width: 100%;

    }

}


/* VERY SMALL PHONE */

@media (max-width: 380px) {

    .content {

        padding: 10px;

    }

    .topbar {

        padding:
            0 9px;

    }

    .topbar {

        gap: 5px;

    }

    .menu-btn,
    .theme-toggle,
    .notification {

        width: 34px;

        height: 34px;

        font-size: 14px;

    }

    .avatar {

        width: 32px;

        height: 32px;

        font-size: 12px;

    }

    .page-title h1 {

        font-size: 18px;

    }

    .stat-card {

        padding: 14px;

    }

    .stat-card h3 {

        font-size: 21px;

    }

}


/* DARK MOBILE SIDEBAR */

@media (max-width: 900px) {

    html.dark-mode .sidebar {

        box-shadow:
            10px 0 30px
            rgba(0,0,0,.55);

    }

}

</style>

</head>


<body>


<!-- =====================================================
     SIDEBAR
===================================================== -->

<aside
    class="sidebar"
    id="sidebar"
>

    <div class="logo">

        <div class="logo-icon">

            <i class="fa-solid fa-graduation-cap"></i>

        </div>

        <div class="logo-text">
            Cilmi_Doon
        </div>

    </div>


    <ul class="menu">

        <li class="menu-title">
            Main Menu
        </li>

        <li>
            <a href="dashboard.php">

                <i class="fa-solid fa-gauge"></i>

                Dashboard

            </a>
        </li>


        <li class="menu-title">
            Academic
        </li>


        <li>
            <a href="students.php">

                <i class="fa-solid fa-user-graduate"></i>

                Students

            </a>
        </li>


        <li>
            <a href="teachers.php">

                <i class="fa-solid fa-chalkboard-user"></i>

                Teachers

            </a>
        </li>


        <li>
            <a href="classes.php">

                <i class="fa-solid fa-school"></i>

                Classes

            </a>
        </li>


        <li>
            <a href="parents.php">

                <i class="fa-solid fa-users"></i>

                Parents

            </a>
        </li>


        <li>
            <a href="subjects.php">

                <i class="fa-solid fa-book"></i>

                Subjects

            </a>
        </li>


        <li>
            <a
                href="attendance.php"
                class="active"
            >

                <i class="fa-solid fa-calendar-check"></i>

                Attendance

            </a>
        </li>


        <li>
            <a href="exams.php">

                <i class="fa-solid fa-file-lines"></i>

                Exams

            </a>
        </li>


        <li>
            <a href="results.php">

                <i class="fa-solid fa-chart-column"></i>

                Results

            </a>
        </li>


        <li class="menu-title">
            Finance
        </li>


        <li>
            <a href="fees.php">

                <i class="fa-solid fa-money-bill"></i>

                Fees

            </a>
        </li>


        <li>
            <a href="salary.php">

                <i class="fa-solid fa-wallet"></i>

                Salary

            </a>
        </li>


        <li class="menu-title">
            Reports
        </li>


        <li>
            <a href="reports.php">

                <i class="fa-solid fa-chart-pie"></i>

                Reports

            </a>
        </li>


        <li>
            <a href="payments.php">

                <i class="fa-solid fa-credit-card"></i>

                Payments

            </a>
        </li>


        <li class="menu-title">
            System
        </li>


        <li>
            <a href="users.php">

                <i class="fa-solid fa-users-gear"></i>

                Users

            </a>
        </li>

    </ul>


    <div class="logout">

        <a href="logout.php">

            <i class="fa-solid fa-right-from-bracket"></i>

            Logout

        </a>

    </div>

</aside>


<!-- OVERLAY -->

<div
    class="overlay"
    id="overlay"
></div>


<!-- =====================================================
     MAIN
===================================================== -->

<div class="main">


    <!-- TOPBAR -->

    <div class="topbar">


        <!-- MENU -->

        <button
            class="menu-btn"
            id="menuBtn"
            type="button"
            title="Menu"
        >

            <i class="fa-solid fa-bars"></i>

        </button>


        <!-- THEME -->

        <button
            class="theme-toggle"
            id="themeToggle"
            type="button"
            title="Toggle Dark / Light Mode"
        >

            <i class="fa-solid fa-moon"></i>

        </button>


        <!-- NOTIFICATION -->

        <a
            href="notifications.php"
            class="notification"
            title="Notifications"
        >

            <i class="fa-regular fa-bell"></i>

            <?php if ($unreadCount > 0): ?>

                <span class="notification-badge">

                    <?php

                    echo
                        $unreadCount > 99
                        ? "99+"
                        : $unreadCount;

                    ?>

                </span>

            <?php endif; ?>

        </a>


        <!-- USER -->

        <div class="user">

            <div class="avatar">

                <?php

                echo strtoupper(
                    substr(
                        $userName,
                        0,
                        1
                    )
                );

                ?>

            </div>


            <div class="user-info">

                <strong>

                    <?php

                    echo htmlspecialchars(
                        $userName
                    );

                    ?>

                </strong>


                <small>

                    <?php

                    echo htmlspecialchars(
                        $userRole
                    );

                    ?>

                </small>

            </div>

        </div>

    </div>


    <!-- =================================================
         CONTENT
    ================================================= -->

    <main class="content">


        <!-- HEADER -->

        <div class="page-header">

            <div class="page-title">

                <h1>
                    Attendance Management
                </h1>

                <p>
                    Track and manage daily student attendance
                </p>

            </div>

        </div>


        <!-- ALERT -->

        <?php if ($message !== ""): ?>

            <div
                class="alert <?php
                    echo htmlspecialchars(
                        $messageType
                    );
                ?>"
            >

                <?php if (
                    $messageType === "success"
                ): ?>

                    <i
                        class="fa-solid fa-circle-check"
                    ></i>

                <?php else: ?>

                    <i
                        class="fa-solid fa-circle-exclamation"
                    ></i>

                <?php endif; ?>


                <?php

                echo htmlspecialchars(
                    $message
                );

                ?>

            </div>

        <?php endif; ?>


        <!-- =================================================
             STATISTICS
        ================================================= -->

        <div class="stats-grid">


            <!-- TOTAL -->

            <div class="stat-card">

                <div class="stat-icon blue">

                    <i
                        class="fa-solid fa-user-graduate"
                    ></i>

                </div>

                <h3>

                    <?php
                    echo $totalStudents;
                    ?>

                </h3>

                <p>
                    Total Students
                </p>

            </div>


            <!-- PRESENT -->

            <div class="stat-card">

                <div class="stat-icon green">

                    <i
                        class="fa-solid fa-user-check"
                    ></i>

                </div>

                <h3>

                    <?php
                    echo $presentToday;
                    ?>

                </h3>

                <p>
                    Present Today
                </p>

            </div>


            <!-- ABSENT -->

            <div class="stat-card">

                <div class="stat-icon red">

                    <i
                        class="fa-solid fa-user-xmark"
                    ></i>

                </div>

                <h3>

                    <?php
                    echo $absentToday;
                    ?>

                </h3>

                <p>
                    Absent Today
                </p>

            </div>


            <!-- LATE -->

            <div class="stat-card">

                <div class="stat-icon yellow">

                    <i
                        class="fa-solid fa-clock"
                    ></i>

                </div>

                <h3>

                    <?php
                    echo $lateToday;
                    ?>

                </h3>

                <p>
                    Late Today
                </p>

            </div>


            <!-- RATE -->

            <div class="stat-card">

                <div class="stat-icon purple">

                    <i
                        class="fa-solid fa-chart-pie"
                    ></i>

                </div>

                <h3>

                    <?php
                    echo $attendanceRate;
                    ?>%

                </h3>

                <p>
                    Attendance Rate
                </p>

            </div>


        </div>


        <!-- =================================================
             ATTENDANCE PANEL
        ================================================= -->

        <form
            method="POST"
            class="attendance-panel"
            id="attendanceForm"
        >


            <!-- HEADER -->

            <div class="panel-header">

                <div class="panel-title">

                    <h2>
                        Daily Attendance
                    </h2>

                    <p>
                        Mark attendance for students
                    </p>

                </div>


                <div class="panel-actions">

                    <button
                        type="button"
                        class="btn btn-light"
                        id="markAllPresent"
                    >

                        <i
                            class="fa-solid fa-check-double"
                        ></i>

                        Mark All Present

                    </button>


                    <button
                        type="submit"
                        name="save_all_attendance"
                        class="btn btn-success"
                    >

                        <i
                            class="fa-solid fa-floppy-disk"
                        ></i>

                        Save Attendance

                    </button>

                </div>

            </div>


            <!-- FILTERS -->

            <div class="filters">


                <!-- DATE -->

                <div class="filter-group">

                    <label>
                        Attendance Date
                    </label>

                    <input
                        type="date"
                        name="attendance_date"
                        value="<?php
                            echo htmlspecialchars(
                                $selectedDate
                            );
                        ?>"
                        id="attendanceDate"
                        onchange="changeFilter()"
                    >

                </div>


                <!-- CLASS -->

                <div class="filter-group">

                    <label>
                        Class
                    </label>

                    <select
                        id="classFilter"
                        onchange="changeFilter()"
                    >

                        <option value="">
                            All Classes
                        </option>


                        <?php if ($classes): ?>

                            <?php while (
                                $class =
                                $classes->fetch_assoc()
                            ): ?>

                                <option
                                    value="<?php
                                        echo $class['id'];
                                    ?>"
                                    <?php

                                    echo
                                        (
                                            $selectedClass ==
                                            $class['id']
                                        )
                                        ? "selected"
                                        : "";

                                    ?>
                                >

                                    <?php

                                    echo htmlspecialchars(
                                        $class['class_name']
                                    );

                                    ?>

                                    <?php if (
                                        !empty(
                                            $class['section']
                                        )
                                    ): ?>

                                        -
                                        <?php

                                        echo htmlspecialchars(
                                            $class['section']
                                        );

                                        ?>

                                    <?php endif; ?>

                                </option>

                            <?php endwhile; ?>

                        <?php endif; ?>

                    </select>

                </div>


                <!-- SEARCH -->

                <div class="filter-group">

                    <label>
                        Search Student
                    </label>

                    <div class="search-wrapper">

                        <i
                            class="fa-solid fa-search"
                        ></i>

                        <input
                            type="text"
                            id="studentSearch"
                            placeholder="Search name or student ID..."
                            value="<?php
                                echo htmlspecialchars(
                                    $search
                                );
                            ?>"
                            onkeyup="searchStudents()"
                        >

                    </div>

                </div>


                <!-- RESET -->

                <div>

                    <a
                        href="attendance.php"
                        class="btn btn-light"
                    >

                        <i
                            class="fa-solid fa-rotate-left"
                        ></i>

                        Reset

                    </a>

                </div>

            </div>


            <!-- =================================================
                 STUDENT TABLE
            ================================================= -->

            <div class="attendance-table-wrapper">

                <table
                    class="attendance-table"
                    id="attendanceTable"
                >

                    <thead>

                        <tr>

                            <th>
                                #
                            </th>

                            <th>
                                Student
                            </th>

                            <th>
                                Class
                            </th>

                            <th>
                                Status
                            </th>

                            <th>
                                Remarks
                            </th>

                        </tr>

                    </thead>


                    <tbody>

                        <?php if (
                            $studentsResult->num_rows > 0
                        ): ?>

                            <?php $number = 1; ?>


                            <?php while (
                                $student =
                                $studentsResult->fetch_assoc()
                            ): ?>


                                <?php

                                $studentId =
                                    intval(
                                        $student['id']
                                    );

                                $studentName =
                                    $student['full_name']
                                    ?? "Unknown Student";

                                $studentCode =
                                    $student['student_id']
                                    ?? "-";


                                $existingStatus =
                                    $existingAttendance[
                                        $studentId
                                    ]['status']
                                    ?? "Present";


                                $existingRemark =
                                    $existingAttendance[
                                        $studentId
                                    ]['remarks']
                                    ?? "";

                                ?>


                                <tr
                                    class="student-row"
                                    data-name="<?php
                                        echo strtolower(
                                            $studentName
                                        );
                                    ?>"
                                    data-code="<?php
                                        echo strtolower(
                                            $studentCode
                                        );
                                    ?>"
                                >


                                    <!-- NUMBER -->

                                    <td>

                                        <?php
                                        echo $number++;
                                        ?>

                                    </td>


                                    <!-- STUDENT -->

                                    <td>

                                        <div class="student-info">

                                            <div
                                                class="student-avatar"
                                            >

                                                <?php

                                                echo strtoupper(
                                                    substr(
                                                        $studentName,
                                                        0,
                                                        1
                                                    )
                                                );

                                                ?>

                                            </div>


                                            <div>

                                                <div
                                                    class="student-name"
                                                >

                                                    <?php

                                                    echo htmlspecialchars(
                                                        $studentName
                                                    );

                                                    ?>

                                                </div>


                                                <div
                                                    class="student-code"
                                                >

                                                    ID:

                                                    <?php

                                                    echo htmlspecialchars(
                                                        $studentCode
                                                    );

                                                    ?>

                                                </div>

                                            </div>

                                        </div>

                                    </td>


                                    <!-- CLASS -->

                                    <td>

                                        <span
                                            class="class-badge"
                                        >

                                            <?php

                                            if (
                                                !empty(
                                                    $student['class_name']
                                                )
                                            ) {

                                                echo htmlspecialchars(
                                                    $student['class_name']
                                                );

                                                if (
                                                    !empty(
                                                        $student['section']
                                                    )
                                                ) {

                                                    echo
                                                        " - " .
                                                        htmlspecialchars(
                                                            $student['section']
                                                        );

                                                }

                                            } else {

                                                echo
                                                    "Not Assigned";

                                            }

                                            ?>

                                        </span>

                                    </td>


                                    <!-- STATUS -->

                                    <td>

                                        <select
                                            name="status[<?php
                                                echo $studentId;
                                            ?>]"
                                            class="status-select <?php
                                                echo strtolower(
                                                    $existingStatus
                                                );
                                            ?>"
                                            onchange="updateStatusColor(this)"
                                        >

                                            <option
                                                value="Present"
                                                <?php

                                                echo
                                                    $existingStatus ===
                                                    "Present"
                                                    ? "selected"
                                                    : "";

                                                ?>
                                            >
                                                Present
                                            </option>


                                            <option
                                                value="Absent"
                                                <?php

                                                echo
                                                    $existingStatus ===
                                                    "Absent"
                                                    ? "selected"
                                                    : "";

                                                ?>
                                            >
                                                Absent
                                            </option>


                                            <option
                                                value="Late"
                                                <?php

                                                echo
                                                    $existingStatus ===
                                                    "Late"
                                                    ? "selected"
                                                    : "";

                                                ?>
                                            >
                                                Late
                                            </option>

                                        </select>

                                    </td>


                                    <!-- REMARKS -->

                                    <td>

                                        <input
                                            type="text"
                                            name="remarks[<?php
                                                echo $studentId;
                                            ?>]"
                                            class="remark-input"
                                            placeholder="Optional..."
                                            value="<?php
                                                echo htmlspecialchars(
                                                    $existingRemark
                                                );
                                            ?>"
                                        >

                                    </td>


                                </tr>


                            <?php endwhile; ?>


                        <?php else: ?>


                            <tr>

                                <td colspan="5">

                                    <div
                                        class="empty-state"
                                    >

                                        <i
                                            class="fa-solid fa-user-slash"
                                        ></i>

                                        <h3>
                                            No Students Found
                                        </h3>

                                        <p>
                                            No students match the selected filters.
                                        </p>

                                    </div>

                                </td>

                            </tr>


                        <?php endif; ?>

                    </tbody>

                </table>

            </div>


            <!-- FOOTER -->

            <div class="table-footer">

                <div
                    class="selected-count"
                >

                    <i
                        class="fa-solid fa-users"
                    ></i>

                    <strong
                        id="visibleStudents"
                    >

                        <?php
                        echo $studentsResult->num_rows;
                        ?>

                    </strong>

                    students displayed

                </div>


                <div>

                    <button
                        type="submit"
                        name="save_all_attendance"
                        class="btn btn-success"
                    >

                        <i
                            class="fa-solid fa-floppy-disk"
                        ></i>

                        Save All Attendance

                    </button>

                </div>

            </div>


        </form>


        <!-- =================================================
             HISTORY
        ================================================= -->

        <div class="history-card">


            <div class="history-header">

                <div>

                    <h2>
                        Attendance History
                    </h2>

                    <p>
                        Recent attendance records
                    </p>

                </div>

            </div>


            <div class="history-wrapper">

                <table class="history-table">

                    <thead>

                        <tr>

                            <th>
                                Student
                            </th>

                            <th>
                                Class
                            </th>

                            <th>
                                Date
                            </th>

                            <th>
                                Status
                            </th>

                            <th>
                                Remarks
                            </th>

                            <th>
                                Actions
                            </th>

                        </tr>

                    </thead>


                    <tbody>

                        <?php if (
                            $historyResult->num_rows > 0
                        ): ?>


                            <?php while (
                                $history =
                                $historyResult->fetch_assoc()
                            ): ?>


                                <?php

                                $historyStatus =
                                    $history['status'];

                                $statusClass =
                                    strtolower(
                                        $historyStatus
                                    );

                                ?>


                                <tr>


                                    <!-- STUDENT -->

                                    <td>

                                        <div
                                            class="student-info"
                                        >

                                            <div
                                                class="student-avatar"
                                            >

                                                <?php

                                                echo strtoupper(
                                                    substr(
                                                        $history['full_name'],
                                                        0,
                                                        1
                                                    )
                                                );

                                                ?>

                                            </div>


                                            <div>

                                                <div
                                                    class="student-name"
                                                >

                                                    <?php

                                                    echo htmlspecialchars(
                                                        $history['full_name']
                                                    );

                                                    ?>

                                                </div>


                                                <div
                                                    class="student-code"
                                                >

                                                    ID:

                                                    <?php

                                                    echo htmlspecialchars(
                                                        $history['student_code']
                                                    );

                                                    ?>

                                                </div>

                                            </div>

                                        </div>

                                    </td>


                                    <!-- CLASS -->

                                    <td>

                                        <span
                                            class="class-badge"
                                        >

                                            <?php

                                            if (
                                                !empty(
                                                    $history['class_name']
                                                )
                                            ) {

                                                echo htmlspecialchars(
                                                    $history['class_name']
                                                );

                                                if (
                                                    !empty(
                                                        $history['section']
                                                    )
                                                ) {

                                                    echo
                                                        " - " .
                                                        htmlspecialchars(
                                                            $history['section']
                                                        );

                                                }

                                            } else {

                                                echo
                                                    "Not Assigned";

                                            }

                                            ?>

                                        </span>

                                    </td>


                                    <!-- DATE -->

                                    <td>

                                        <?php

                                        echo date(
                                            "d M Y",
                                            strtotime(
                                                $history[
                                                    'attendance_date'
                                                ]
                                            )
                                        );

                                        ?>

                                    </td>


                                    <!-- STATUS -->

                                    <td>

                                        <span
                                            class="status-badge status-<?php
                                                echo $statusClass;
                                            ?>"
                                        >

                                            <?php if (
                                                $statusClass ===
                                                "present"
                                            ): ?>

                                                <i
                                                    class="fa-solid fa-check"
                                                ></i>

                                            <?php elseif (
                                                $statusClass ===
                                                "absent"
                                            ): ?>

                                                <i
                                                    class="fa-solid fa-xmark"
                                                ></i>

                                            <?php else: ?>

                                                <i
                                                    class="fa-solid fa-clock"
                                                ></i>

                                            <?php endif; ?>


                                            <?php

                                            echo htmlspecialchars(
                                                $historyStatus
                                            );

                                            ?>

                                        </span>

                                    </td>


                                    <!-- REMARKS -->

                                    <td>

                                        <?php

                                        if (
                                            !empty(
                                                $history['remarks']
                                            )
                                        ) {

                                            echo htmlspecialchars(
                                                $history['remarks']
                                            );

                                        } else {

                                            echo
                                                '<span style="color:#94a3b8;">—</span>';

                                        }

                                        ?>

                                    </td>


                                    <!-- ACTIONS -->

                                    <td>

                                        <div
                                            class="action-buttons"
                                        >


                                            <a
                                                href="attendance.php?edit=<?php
                                                    echo $history['id'];
                                                ?>"
                                                class="action-btn action-edit"
                                                title="Edit"
                                            >

                                                <i
                                                    class="fa-solid fa-pen"
                                                ></i>

                                            </a>


                                            <a
                                                href="attendance.php?delete=<?php
                                                    echo $history['id'];
                                                ?>"
                                                class="action-btn action-delete"
                                                title="Delete"
                                                onclick="return confirm('Are you sure you want to delete this attendance record?');"
                                            >

                                                <i
                                                    class="fa-solid fa-trash"
                                                ></i>

                                            </a>


                                        </div>

                                    </td>


                                </tr>


                            <?php endwhile; ?>


                        <?php else: ?>


                            <tr>

                                <td colspan="6">

                                    <div
                                        class="empty-state"
                                    >

                                        <i
                                            class="fa-solid fa-calendar-xmark"
                                        ></i>

                                        <h3>
                                            No Attendance Records
                                        </h3>

                                        <p>
                                            Attendance history will appear here.
                                        </p>

                                    </div>

                                </td>

                            </tr>


                        <?php endif; ?>


                    </tbody>

                </table>

            </div>

        </div>


    </main>

</div>


<!-- =====================================================
     EDIT MODAL
===================================================== -->

<?php if ($editMode): ?>


<div class="modal">


    <div class="modal-box">


        <div class="modal-header">

            <h3>
                Edit Attendance
            </h3>


            <a
                href="attendance.php"
                class="close-modal"
            >

                <i
                    class="fa-solid fa-xmark"
                ></i>

            </a>

        </div>


        <form method="POST">


            <div class="modal-body">


                <input
                    type="hidden"
                    name="attendance_id"
                    value="<?php
                        echo $editAttendance['id'];
                    ?>"
                >


                <!-- STUDENT -->

                <div class="form-group">

                    <label>
                        Student
                    </label>

                    <select
                        name="student_id"
                        class="form-control"
                        required
                    >

                        <?php

                        $editStudents =
                            $conn->query("
                                SELECT
                                    id,
                                    student_id,
                                    full_name
                                FROM students
                                ORDER BY
                                    full_name ASC
                            ");

                        ?>


                        <?php while (
                            $editStudent =
                            $editStudents->fetch_assoc()
                        ): ?>


                            <option
                                value="<?php
                                    echo $editStudent['id'];
                                ?>"
                                <?php

                                echo
                                    (
                                        $editAttendance[
                                            'student_id'
                                        ] ==
                                        $editStudent['id']
                                    )
                                    ? "selected"
                                    : "";

                                ?>
                            >

                                <?php

                                echo htmlspecialchars(
                                    $editStudent[
                                        'full_name'
                                    ]
                                );

                                ?>

                                -

                                <?php

                                echo htmlspecialchars(
                                    $editStudent[
                                        'student_id'
                                    ]
                                );

                                ?>

                            </option>


                        <?php endwhile; ?>

                    </select>

                </div>


                <!-- DATE -->

                <div class="form-group">

                    <label>
                        Attendance Date
                    </label>

                    <input
                        type="date"
                        name="attendance_date"
                        class="form-control"
                        value="<?php

                            echo htmlspecialchars(
                                $editAttendance[
                                    'attendance_date'
                                ]
                            );

                        ?>"
                        required
                    >

                </div>


                <!-- STATUS -->

                <div class="form-group">

                    <label>
                        Status
                    </label>

                    <select
                        name="status"
                        class="form-control"
                        required
                    >

                        <option
                            value="Present"
                            <?php

                            echo
                                $editAttendance[
                                    'status'
                                ] === "Present"
                                ? "selected"
                                : "";

                            ?>
                        >
                            Present
                        </option>


                        <option
                            value="Absent"
                            <?php

                            echo
                                $editAttendance[
                                    'status'
                                ] === "Absent"
                                ? "selected"
                                : "";

                            ?>
                        >
                            Absent
                        </option>


                        <option
                            value="Late"
                            <?php

                            echo
                                $editAttendance[
                                    'status'
                                ] === "Late"
                                ? "selected"
                                : "";

                            ?>
                        >
                            Late
                        </option>

                    </select>

                </div>


                <!-- REMARKS -->

                <div class="form-group">

                    <label>
                        Remarks
                    </label>

                    <textarea
                        name="remarks"
                        class="form-control"
                        placeholder="Add optional remarks..."
                    ><?php

                    echo htmlspecialchars(
                        $editAttendance[
                            'remarks'
                        ]
                    );

                    ?></textarea>

                </div>


            </div>


            <div class="modal-footer">


                <a
                    href="attendance.php"
                    class="btn btn-light"
                >
                    Cancel
                </a>


                <button
                    type="submit"
                    name="update_attendance"
                    class="btn btn-primary"
                >

                    <i
                        class="fa-solid fa-save"
                    ></i>

                    Update Attendance

                </button>


            </div>


        </form>


    </div>

</div>


<?php endif; ?>


<!-- =====================================================
     JAVASCRIPT
===================================================== -->

<script>

/* =========================================================
   SIDEBAR
========================================================= */

const sidebar =
    document.getElementById("sidebar");

const overlay =
    document.getElementById("overlay");

const menuBtn =
    document.getElementById("menuBtn");


if (menuBtn) {

    menuBtn.addEventListener(
        "click",
        function () {

            sidebar.classList.toggle(
                "active"
            );

            overlay.classList.toggle(
                "active"
            );

        }
    );

}


if (overlay) {

    overlay.addEventListener(
        "click",
        function () {

            sidebar.classList.remove(
                "active"
            );

            overlay.classList.remove(
                "active"
            );

        }
    );

}


/* =========================================================
   CLOSE MOBILE MENU WHEN LINK CLICKED
========================================================= */

document
    .querySelectorAll(".menu a")
    .forEach(function (link) {

        link.addEventListener(
            "click",
            function () {

                if (
                    window.innerWidth <= 900
                ) {

                    sidebar.classList.remove(
                        "active"
                    );

                    overlay.classList.remove(
                        "active"
                    );

                }

            }
        );

    });


/* =========================================================
   MARK ALL PRESENT
========================================================= */

const markAllPresent =
    document.getElementById(
        "markAllPresent"
    );


if (markAllPresent) {

    markAllPresent.addEventListener(
        "click",
        function () {

            const selects =
                document.querySelectorAll(
                    ".status-select"
                );

            selects.forEach(
                function (select) {

                    select.value =
                        "Present";

                    updateStatusColor(
                        select
                    );

                }
            );

        }
    );

}


/* =========================================================
   STATUS COLOR
========================================================= */

function updateStatusColor(select) {

    select.classList.remove(
        "present",
        "absent",
        "late"
    );

    select.classList.add(
        select.value.toLowerCase()
    );

}


/* =========================================================
   SEARCH STUDENTS
========================================================= */

function searchStudents() {

    const searchInput =
        document.getElementById(
            "studentSearch"
        );

    const search =
        searchInput.value
            .toLowerCase()
            .trim();


    const rows =
        document.querySelectorAll(
            ".student-row"
        );


    let visible = 0;


    rows.forEach(
        function (row) {

            const name =
                row.dataset.name || "";

            const code =
                row.dataset.code || "";


            if (
                name.includes(search) ||
                code.includes(search)
            ) {

                row.style.display =
                    "";

                visible++;

            } else {

                row.style.display =
                    "none";

            }

        }
    );


    const visibleStudents =
        document.getElementById(
            "visibleStudents"
        );

    if (visibleStudents) {

        visibleStudents.textContent =
            visible;

    }

}


/* =========================================================
   CHANGE FILTER
========================================================= */

function changeFilter() {

    const date =
        document.getElementById(
            "attendanceDate"
        ).value;


    const classId =
        document.getElementById(
            "classFilter"
        ).value;


    const url =
        new URL(
            window.location.href
        );


    url.searchParams.set(
        "date",
        date
    );


    if (classId !== "") {

        url.searchParams.set(
            "class_id",
            classId
        );

    } else {

        url.searchParams.delete(
            "class_id"
        );

    }


    const search =
        document.getElementById(
            "studentSearch"
        ).value.trim();


    if (search !== "") {

        url.searchParams.set(
            "search",
            search
        );

    } else {

        url.searchParams.delete(
            "search"
        );

    }


    window.location.href =
        url.toString();

}


/* =========================================================
   INITIAL STATUS COLORS
========================================================= */

document
    .querySelectorAll(
        ".status-select"
    )
    .forEach(
        function (select) {

            updateStatusColor(
                select
            );

        }
    );


/* =========================================================
   DARK / LIGHT MODE
========================================================= */

const themeToggle =
    document.getElementById(
        "themeToggle"
    );

const themeIcon =
    themeToggle
        ? themeToggle.querySelector("i")
        : null;


function applyTheme(theme) {

    if (theme === "dark") {

        document.documentElement
            .classList
            .add("dark-mode");

        document.body
            .classList
            .add("dark-mode");


        if (themeIcon) {

            themeIcon.classList.remove(
                "fa-moon"
            );

            themeIcon.classList.add(
                "fa-sun"
            );

        }


        if (themeToggle) {

            themeToggle.title =
                "Switch to Light Mode";

        }

    } else {

        document.documentElement
            .classList
            .remove("dark-mode");

        document.body
            .classList
            .remove("dark-mode");


        if (themeIcon) {

            themeIcon.classList.remove(
                "fa-sun"
            );

            themeIcon.classList.add(
                "fa-moon"
            );

        }


        if (themeToggle) {

            themeToggle.title =
                "Switch to Dark Mode";

        }

    }


    localStorage.setItem(
        "theme",
        theme
    );

}


/* LOAD SAVED THEME */

const savedTheme =
    localStorage.getItem(
        "theme"
    ) || "light";


applyTheme(savedTheme);


/* TOGGLE */

if (themeToggle) {

    themeToggle.addEventListener(
        "click",
        function () {

            const isDark =
                document.documentElement
                    .classList
                    .contains(
                        "dark-mode"
                    );


            applyTheme(
                isDark
                    ? "light"
                    : "dark"
            );

        }
    );

}


/* =========================================================
   ESC KEY CLOSE SIDEBAR
========================================================= */

document.addEventListener(
    "keydown",
    function (event) {

        if (event.key === "Escape") {

            sidebar.classList.remove(
                "active"
            );

            overlay.classList.remove(
                "active"
            );

        }

    }
);

</script>


</body>

</html>