<?php
session_start();

/* =========================================================
   LOGIN PROTECTION
========================================================= */
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
$userId   = (int)($_SESSION['user_id'] ?? 0);
$userName = $_SESSION['user_name'] ?? "Administrator";
$userRole = $_SESSION['user_role'] ?? "Admin";

/* =========================================================
   NOTIFICATIONS
========================================================= */
$unreadCount = getUnreadNotificationCount($conn, $userId);

$message = "";
$messageType = "";

/* =========================================================
   GRADE FUNCTION
========================================================= */
function calculateGrade($marks)
{
    if ($marks >= 90) return "A+";
    if ($marks >= 80) return "A";
    if ($marks >= 70) return "B";
    if ($marks >= 60) return "C";
    if ($marks >= 50) return "D";
    return "F";
}

/* =========================================================
   REMARKS FUNCTION
========================================================= */
function calculateRemarks($marks)
{
    if ($marks >= 90) return "Excellent";
    if ($marks >= 80) return "Very Good";
    if ($marks >= 70) return "Good";
    if ($marks >= 60) return "Satisfactory";
    if ($marks >= 50) return "Pass";
    return "Fail";
}

/* =========================================================
   ADD RESULT
========================================================= */
if (isset($_POST['add_result'])) {

    $exam_id    = (int)($_POST['exam_id'] ?? 0);
    $student_id = (int)($_POST['student_id'] ?? 0);
    $subject_id = (int)($_POST['subject_id'] ?? 0);
    $marks      = (float)($_POST['marks'] ?? 0);

    if (
        $exam_id <= 0 ||
        $student_id <= 0 ||
        $subject_id <= 0
    ) {

        $message =
            "Please select exam, student and subject.";

        $messageType = "error";

    } elseif ($marks < 0 || $marks > 100) {

        $message =
            "Marks must be between 0 and 100.";

        $messageType = "error";

    } else {

        $check = $conn->prepare("
            SELECT id
            FROM results
            WHERE exam_id = ?
            AND student_id = ?
            AND subject_id = ?
            LIMIT 1
        ");

        if ($check) {

            $check->bind_param(
                "iii",
                $exam_id,
                $student_id,
                $subject_id
            );

            $check->execute();

            $checkResult =
                $check->get_result();

            if ($checkResult->num_rows > 0) {

                $message =
                    "A result already exists for this student, exam and subject.";

                $messageType = "error";

            } else {

                $grade =
                    calculateGrade($marks);

                $remarks =
                    calculateRemarks($marks);

                $stmt = $conn->prepare("
                    INSERT INTO results
                    (
                        exam_id,
                        student_id,
                        subject_id,
                        marks,
                        grade,
                        remarks
                    )
                    VALUES (?, ?, ?, ?, ?, ?)
                ");

                if ($stmt) {

                    $stmt->bind_param(
                        "iiidss",
                        $exam_id,
                        $student_id,
                        $subject_id,
                        $marks,
                        $grade,
                        $remarks
                    );

                    if ($stmt->execute()) {

                        $studentName =
                            "Student";

                        $studentStmt =
                            $conn->prepare("
                                SELECT full_name
                                FROM students
                                WHERE id = ?
                                LIMIT 1
                            ");

                        if ($studentStmt) {

                            $studentStmt->bind_param(
                                "i",
                                $student_id
                            );

                            $studentStmt->execute();

                            $studentResult =
                                $studentStmt->get_result();

                            if (
                                $studentResult->num_rows === 1
                            ) {

                                $studentRow =
                                    $studentResult->fetch_assoc();

                                $studentName =
                                    $studentRow['full_name'];
                            }

                            $studentStmt->close();
                        }

                        $subjectName =
                            "Subject";

                        $subjectStmt =
                            $conn->prepare("
                                SELECT subject_name
                                FROM subjects
                                WHERE id = ?
                                LIMIT 1
                            ");

                        if ($subjectStmt) {

                            $subjectStmt->bind_param(
                                "i",
                                $subject_id
                            );

                            $subjectStmt->execute();

                            $subjectResult =
                                $subjectStmt->get_result();

                            if (
                                $subjectResult->num_rows === 1
                            ) {

                                $subjectRow =
                                    $subjectResult->fetch_assoc();

                                $subjectName =
                                    $subjectRow['subject_name'];
                            }

                            $subjectStmt->close();
                        }

                        createNotification(
                            $conn,
                            $userId,
                            "New Result Added",
                            $studentName .
                            " received " .
                            number_format($marks, 2) .
                            "/100 in " .
                            $subjectName .
                            " (" .
                            $grade .
                            ").",
                            "success",
                            "results.php"
                        );

                        $message =
                            "Result added successfully.";

                        $messageType =
                            "success";

                    } else {

                        $message =
                            "Failed to add result.";

                        $messageType =
                            "error";
                    }

                    $stmt->close();

                } else {

                    $message =
                        "Unable to prepare result statement.";

                    $messageType =
                        "error";
                }
            }

            $check->close();

        } else {

            $message =
                "Unable to check existing result.";

            $messageType =
                "error";
        }
    }
}

/* =========================================================
   UPDATE RESULT
========================================================= */
if (isset($_POST['update_result'])) {

    $id =
        (int)($_POST['id'] ?? 0);

    $exam_id =
        (int)($_POST['exam_id'] ?? 0);

    $student_id =
        (int)($_POST['student_id'] ?? 0);

    $subject_id =
        (int)($_POST['subject_id'] ?? 0);

    $marks =
        (float)($_POST['marks'] ?? 0);

    if ($id <= 0) {

        $message =
            "Invalid result ID.";

        $messageType =
            "error";

    } elseif (
        $exam_id <= 0 ||
        $student_id <= 0 ||
        $subject_id <= 0
    ) {

        $message =
            "Please select exam, student and subject.";

        $messageType =
            "error";

    } elseif ($marks < 0 || $marks > 100) {

        $message =
            "Marks must be between 0 and 100.";

        $messageType =
            "error";

    } else {

        $check =
            $conn->prepare("
                SELECT id
                FROM results
                WHERE exam_id = ?
                AND student_id = ?
                AND subject_id = ?
                AND id != ?
                LIMIT 1
            ");

        if ($check) {

            $check->bind_param(
                "iiii",
                $exam_id,
                $student_id,
                $subject_id,
                $id
            );

            $check->execute();

            $checkResult =
                $check->get_result();

            if ($checkResult->num_rows > 0) {

                $message =
                    "Another result already exists for this student, exam and subject.";

                $messageType =
                    "error";

            } else {

                $grade =
                    calculateGrade($marks);

                $remarks =
                    calculateRemarks($marks);

                $stmt =
                    $conn->prepare("
                        UPDATE results
                        SET
                            exam_id = ?,
                            student_id = ?,
                            subject_id = ?,
                            marks = ?,
                            grade = ?,
                            remarks = ?
                        WHERE id = ?
                    ");

                if ($stmt) {

                    $stmt->bind_param(
                        "iiidssi",
                        $exam_id,
                        $student_id,
                        $subject_id,
                        $marks,
                        $grade,
                        $remarks,
                        $id
                    );

                    if ($stmt->execute()) {

                        $message =
                            "Result updated successfully.";

                        $messageType =
                            "success";

                    } else {

                        $message =
                            "Failed to update result.";

                        $messageType =
                            "error";
                    }

                    $stmt->close();

                } else {

                    $message =
                        "Unable to prepare update statement.";

                    $messageType =
                        "error";
                }
            }

            $check->close();

        } else {

            $message =
                "Unable to check existing result.";

            $messageType =
                "error";
        }
    }
}

/* =========================================================
   DELETE RESULT
========================================================= */
if (isset($_GET['delete'])) {

    $deleteId =
        (int)$_GET['delete'];

    if ($deleteId > 0) {

        $stmt =
            $conn->prepare("
                DELETE FROM results
                WHERE id = ?
            ");

        if ($stmt) {

            $stmt->bind_param(
                "i",
                $deleteId
            );

            if ($stmt->execute()) {

                $message =
                    "Result deleted successfully.";

                $messageType =
                    "success";

            } else {

                $message =
                    "Failed to delete result.";

                $messageType =
                    "error";
            }

            $stmt->close();

        } else {

            $message =
                "Unable to prepare delete statement.";

            $messageType =
                "error";
        }
    }
}

/* =========================================================
   SEARCH AND FILTER
========================================================= */

$search =
    trim($_GET['search'] ?? "");

$filterExam =
    (int)($_GET['exam'] ?? 0);

$filterSubject =
    (int)($_GET['subject'] ?? 0);


/* =========================================================
   RESULTS QUERY
========================================================= */

$sql = "
    SELECT
        r.id,
        r.exam_id,
        r.student_id,
        r.subject_id,
        r.marks,
        r.grade,
        r.remarks,
        r.created_at,

        e.exam_name,
        e.exam_date,

        s.full_name,
        s.student_id AS student_code,

        sub.subject_name

    FROM results r

    LEFT JOIN exams e
        ON r.exam_id = e.id

    LEFT JOIN students s
        ON r.student_id = s.id

    LEFT JOIN subjects sub
        ON r.subject_id = sub.id

    WHERE 1 = 1
";

$params = [];
$types = "";


/* SEARCH */

if ($search !== "") {

    $sql .= "
        AND (
            s.full_name LIKE ?
            OR s.student_id LIKE ?
            OR e.exam_name LIKE ?
            OR sub.subject_name LIKE ?
        )
    ";

    $searchValue =
        "%" . $search . "%";

    $params[] =
        $searchValue;

    $params[] =
        $searchValue;

    $params[] =
        $searchValue;

    $params[] =
        $searchValue;

    $types .= "ssss";
}


/* EXAM FILTER */

if ($filterExam > 0) {

    $sql .= "
        AND r.exam_id = ?
    ";

    $params[] =
        $filterExam;

    $types .= "i";
}


/* SUBJECT FILTER */

if ($filterSubject > 0) {

    $sql .= "
        AND r.subject_id = ?
    ";

    $params[] =
        $filterSubject;

    $types .= "i";
}


/* ORDER */

$sql .= "
    ORDER BY r.id DESC
";


$stmt =
    $conn->prepare($sql);

if (!$stmt) {

    die(
        "Results query failed: " .
        htmlspecialchars(
            $conn->error
        )
    );
}


if (!empty($params)) {

    $stmt->bind_param(
        $types,
        ...$params
    );
}


$stmt->execute();

$results =
    $stmt->get_result();


/* =========================================================
   EXAMS
========================================================= */

$exams =
    $conn->query("
        SELECT
            id,
            exam_name,
            exam_date
        FROM exams
        ORDER BY
            exam_date DESC,
            exam_name ASC
    ");


/* =========================================================
   SUBJECTS
========================================================= */

$subjects =
    $conn->query("
        SELECT
            id,
            subject_name
        FROM subjects
        ORDER BY
            subject_name ASC
    ");


/* =========================================================
   STUDENTS
========================================================= */

$students =
    $conn->query("
        SELECT
            id,
            student_id,
            full_name
        FROM students
        ORDER BY
            full_name ASC
    ");


/* =========================================================
   STATISTICS
========================================================= */

$totalResults =
    $conn->query("
        SELECT COUNT(*) AS total
        FROM results
    ")->fetch_assoc()['total'] ?? 0;


$passedResults =
    $conn->query("
        SELECT COUNT(*) AS total
        FROM results
        WHERE marks >= 50
    ")->fetch_assoc()['total'] ?? 0;


$failedResults =
    $conn->query("
        SELECT COUNT(*) AS total
        FROM results
        WHERE marks < 50
    ")->fetch_assoc()['total'] ?? 0;


$averageMarks =
    $conn->query("
        SELECT AVG(marks) AS average
        FROM results
    ")->fetch_assoc()['average'] ?? 0;

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
        Results | School Management System
    </title>

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
    >

    <style>

/* =========================================================
   RESET
========================================================= */

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

html {
    scroll-behavior: smooth;
}

body {
    font-family:
        Arial,
        Helvetica,
        sans-serif;

    background: #f8fafc;

    color: #0f172a;

    transition:
        background-color .3s ease,
        color .3s ease;
}

button,
input,
select {
    font-family: inherit;
}

button {
    cursor: pointer;
}

a {
    text-decoration: none;
}


/* =========================================================
   SIDEBAR
========================================================= */

.sidebar {
    position: fixed;

    left: 0;
    top: 0;
    bottom: 0;

    width: 260px;

    background: #0f172a;

    color: white;

    z-index: 1000;

    overflow-y: auto;

    transition:
        transform .3s ease;
}


/* LOGO */

.sidebar-logo {
    height: 70px;

    display: flex;

    align-items: center;

    padding: 0 24px;

    border-bottom:
        1px solid
        rgba(255,255,255,.08);
}

.sidebar-logo h2 {
    font-size: 20px;

    font-weight: 800;

    color: white;
}

.sidebar-logo i {
    margin-right: 10px;

    color: #38bdf8;
}


/* MENU */

.sidebar-menu {
    padding: 18px 12px;
}

.sidebar-menu-title {
    color: #64748b;

    font-size: 11px;

    text-transform: uppercase;

    font-weight: 700;

    padding:
        10px 14px;
}

.sidebar-menu a {
    display: flex;

    align-items: center;

    gap: 12px;

    padding:
        12px 14px;

    margin-bottom: 4px;

    color: #cbd5e1;

    border-radius: 8px;

    font-size: 14px;

    transition:
        all .2s ease;
}

.sidebar-menu a i {
    width: 20px;

    text-align: center;

    font-size: 15px;
}

.sidebar-menu a:hover {
    background: #1e293b;

    color: white;
}

.sidebar-menu a.active {
    background: #2563eb;

    color: white;

    font-weight: 600;
}


/* =========================================================
   MAIN
========================================================= */

.main {
    margin-left: 260px;

    min-height: 100vh;

    transition:
        margin-left .3s ease;
}


/* =========================================================
   TOPBAR
========================================================= */

.topbar {
    height: 70px;

    background: white;

    border-bottom:
        1px solid #e2e8f0;

    display: flex;

    align-items: center;

    justify-content: flex-end;

    padding: 0 30px;

    position: sticky;

    top: 0;

    z-index: 900;

    transition:
        background-color .3s ease,
        border-color .3s ease;
}


/* TOPBAR RIGHT */

.topbar-right {
    display: flex;

    align-items: center;

    justify-content: flex-end;

    gap: 12px;

    margin-left: auto;
}


/* MENU BUTTON */

.menu-btn {
    display: none;

    width: 42px;
    height: 42px;

    border: none;

    border-radius: 8px;

    background: #f1f5f9;

    color: #0f172a;

    font-size: 20px;

    cursor: pointer;

    align-items: center;

    justify-content: center;

    transition:
        all .3s ease;
}

.menu-btn:hover {
    background: #e2e8f0;
}


/* THEME BUTTON */

.theme-btn {
    width: 42px;
    height: 42px;

    border: none;

    border-radius: 8px;

    background: #f1f5f9;

    color: #334155;

    display: flex;

    align-items: center;

    justify-content: center;

    cursor: pointer;

    font-size: 17px;

    transition:
        all .3s ease;
}

.theme-btn:hover {
    background: #e2e8f0;
}


/* NOTIFICATION */

.notification {
    width: 42px;
    height: 42px;

    display: flex;

    align-items: center;

    justify-content: center;

    position: relative;

    border-radius: 8px;

    color: #334155;

    background: #f1f5f9;

    text-decoration: none;

    font-size: 18px;

    transition:
        all .3s ease;
}

.notification:hover {
    background: #e2e8f0;
}


/* NOTIFICATION BADGE */

.notification-badge {
    position: absolute;

    top: -4px;

    right: -4px;

    min-width: 18px;

    height: 18px;

    padding: 0 5px;

    border-radius: 50px;

    background: #ef4444;

    color: white;

    font-size: 10px;

    font-weight: 700;

    display: flex;

    align-items: center;

    justify-content: center;
}


/* USER INFO */

.user-info {
    display: flex;

    align-items: center;

    gap: 10px;

    padding-left: 10px;
}


/* AVATAR */

.avatar {
    width: 42px;
    height: 42px;

    border-radius: 50%;

    background: #2563eb;

    color: white;

    display: flex;

    align-items: center;

    justify-content: center;

    font-size: 16px;

    font-weight: 700;

    text-transform: uppercase;
}


/* USER TEXT */

.user-text {
    display: flex;

    flex-direction: column;

    line-height: 1.3;
}

.user-text strong {
    font-size: 14px;

    color: #0f172a;

    white-space: nowrap;
}

.user-text span {
    font-size: 12px;

    color: #64748b;

    white-space: nowrap;
}


/* =========================================================
   CONTENT
========================================================= */

.content {
    padding: 30px;
}


/* PAGE HEADER */

.page-header {
    display: flex;

    align-items: center;

    justify-content: space-between;

    gap: 20px;

    margin-bottom: 25px;
}

.page-title h1 {
    font-size: 28px;

    font-weight: 800;

    color: #0f172a;

    margin-bottom: 5px;
}

.page-title p {
    font-size: 14px;

    color: #64748b;
}


/* =========================================================
   BUTTONS
========================================================= */

.btn-primary {
    display: inline-flex;

    align-items: center;

    justify-content: center;

    gap: 8px;

    border: none;

    background: #2563eb;

    color: white;

    padding:
        11px 17px;

    border-radius: 8px;

    font-size: 14px;

    font-weight: 600;

    transition:
        all .2s ease;
}

.btn-primary:hover {
    background: #1d4ed8;

    transform: translateY(-1px);
}


/* =========================================================
   ALERT
========================================================= */

.alert {
    display: flex;

    align-items: center;

    gap: 10px;

    padding:
        13px 16px;

    border-radius: 8px;

    margin-bottom: 20px;

    font-size: 14px;

    font-weight: 500;
}

.alert.success {
    background: #dcfce7;

    color: #166534;

    border:
        1px solid #bbf7d0;
}

.alert.error {
    background: #fee2e2;

    color: #991b1b;

    border:
        1px solid #fecaca;
}


/* =========================================================
   STATISTICS
========================================================= */

.stats-grid {
    display: grid;

    grid-template-columns:
        repeat(4, 1fr);

    gap: 18px;

    margin-bottom: 25px;
}

.stat-card {
    background: white;

    border:
        1px solid #e2e8f0;

    border-radius: 12px;

    padding: 20px;

    display: flex;

    align-items: center;

    gap: 15px;

    box-shadow:
        0 2px 8px
        rgba(15,23,42,.04);
}

.stat-icon {
    width: 48px;

    height: 48px;

    border-radius: 10px;

    display: flex;

    align-items: center;

    justify-content: center;

    font-size: 20px;
}

.stat-icon.blue {
    background: #dbeafe;

    color: #2563eb;
}

.stat-icon.green {
    background: #dcfce7;

    color: #16a34a;
}

.stat-icon.red {
    background: #fee2e2;

    color: #dc2626;
}

.stat-icon.orange {
    background: #ffedd5;

    color: #ea580c;
}

.stat-info span {
    display: block;

    color: #64748b;

    font-size: 12px;

    margin-bottom: 4px;
}

.stat-info strong {
    display: block;

    color: #0f172a;

    font-size: 23px;

    font-weight: 800;
}


/* =========================================================
   FILTER CARD
========================================================= */

.filter-card {
    background: white;

    border:
        1px solid #e2e8f0;

    border-radius: 12px;

    padding: 20px;

    margin-bottom: 20px;

    box-shadow:
        0 2px 8px
        rgba(15,23,42,.04);
}

.filter-form {
    display: grid;

    grid-template-columns:
        2fr 1fr 1fr auto auto;

    gap: 12px;

    align-items: end;
}

.form-group {
    display: flex;

    flex-direction: column;

    gap: 7px;
}

.form-group label {
    font-size: 12px;

    font-weight: 600;

    color: #475569;
}

.form-control {
    width: 100%;

    height: 42px;

    border:
        1px solid #cbd5e1;

    border-radius: 7px;

    padding:
        0 12px;

    outline: none;

    background: white;

    color: #0f172a;

    font-size: 13px;

    transition:
        border-color .2s ease,
        box-shadow .2s ease;
}

.form-control:focus {
    border-color: #2563eb;

    box-shadow:
        0 0 0 3px
        rgba(37,99,235,.10);
}


/* FILTER BUTTON */

.btn-filter {
    height: 42px;

    border: none;

    border-radius: 7px;

    padding:
        0 16px;

    background: #2563eb;

    color: white;

    font-size: 13px;

    font-weight: 600;
}

.btn-filter:hover {
    background: #1d4ed8;
}

.btn-reset {
    height: 42px;

    border:
        1px solid #cbd5e1;

    border-radius: 7px;

    padding:
        0 16px;

    background: white;

    color: #475569;

    font-size: 13px;

    font-weight: 600;

    display: inline-flex;

    align-items: center;

    justify-content: center;
}

.btn-reset:hover {
    background: #f8fafc;
}


/* =========================================================
   TABLE CARD
========================================================= */

.table-card {
    background: white;

    border:
        1px solid #e2e8f0;

    border-radius: 12px;

    overflow: hidden;

    box-shadow:
        0 2px 8px
        rgba(15,23,42,.04);
}

.table-header {
    padding:
        18px 20px;

    border-bottom:
        1px solid #e2e8f0;

    display: flex;

    align-items: center;

    justify-content: space-between;
}

.table-header h3 {
    font-size: 16px;

    font-weight: 700;

    color: #0f172a;
}

.table-header span {
    color: #64748b;

    font-size: 12px;
}

.table-wrapper {
    width: 100%;

    overflow-x: auto;
}

table {
    width: 100%;

    border-collapse: collapse;

    min-width: 900px;
}

thead {
    background: #f8fafc;
}

th {
    text-align: left;

    padding:
        13px 16px;

    color: #475569;

    font-size: 11px;

    text-transform: uppercase;

    letter-spacing: .4px;

    font-weight: 700;

    border-bottom:
        1px solid #e2e8f0;
}

td {
    padding:
        14px 16px;

    color: #334155;

    font-size: 13px;

    border-bottom:
        1px solid #f1f5f9;
}

tbody tr:hover {
    background: #f8fafc;
}


/* STUDENT */

.student-cell {
    display: flex;

    align-items: center;

    gap: 10px;
}

.student-avatar {
    width: 34px;

    height: 34px;

    border-radius: 50%;

    background: #dbeafe;

    color: #2563eb;

    display: flex;

    align-items: center;

    justify-content: center;

    font-size: 12px;

    font-weight: 700;
}

.student-name {
    font-weight: 600;

    color: #0f172a;
}

.student-code {
    color: #64748b;

    font-size: 11px;

    margin-top: 2px;
}


/* GRADE */

.grade-badge {
    display: inline-flex;

    align-items: center;

    justify-content: center;

    min-width: 38px;

    height: 27px;

    padding: 0 8px;

    border-radius: 6px;

    font-size: 12px;

    font-weight: 700;
}

.grade-a {
    background: #dcfce7;

    color: #166534;
}

.grade-b {
    background: #dbeafe;

    color: #1d4ed8;
}

.grade-c {
    background: #fef3c7;

    color: #92400e;
}

.grade-d {
    background: #ffedd5;

    color: #c2410c;
}

.grade-f {
    background: #fee2e2;

    color: #b91c1c;
}


/* MARKS */

.marks {
    font-weight: 700;

    color: #0f172a;
}


/* ACTIONS */

.actions {
    display: flex;

    align-items: center;

    gap: 6px;
}

.action-btn {
    width: 32px;

    height: 32px;

    border: none;

    border-radius: 6px;

    display: flex;

    align-items: center;

    justify-content: center;

    font-size: 12px;

    transition:
        all .2s ease;
}

.action-edit {
    background: #dbeafe;

    color: #2563eb;
}

.action-edit:hover {
    background: #bfdbfe;
}

.action-delete {
    background: #fee2e2;

    color: #dc2626;
}

.action-delete:hover {
    background: #fecaca;
}


/* EMPTY */

.empty-state {
    text-align: center;

    padding: 60px 20px;

    color: #64748b;
}

.empty-state i {
    font-size: 42px;

    margin-bottom: 12px;

    color: #cbd5e1;
}

.empty-state h3 {
    color: #475569;

    font-size: 16px;

    margin-bottom: 5px;
}

.empty-state p {
    font-size: 13px;
}


/* =========================================================
   MODAL
========================================================= */

.modal {
    position: fixed;

    inset: 0;

    background:
        rgba(15,23,42,.55);

    display: none;

    align-items: center;

    justify-content: center;

    padding: 20px;

    z-index: 2000;
}

.modal.active {
    display: flex;
}

.modal-content {
    width: 100%;

    max-width: 600px;

    max-height: 90vh;

    overflow-y: auto;

    background: white;

    border-radius: 12px;

    box-shadow:
        0 20px 50px
        rgba(15,23,42,.20);

    animation:
        modalShow .2s ease;
}

@keyframes modalShow {

    from {
        opacity: 0;

        transform:
            translateY(10px)
            scale(.98);
    }

    to {
        opacity: 1;

        transform:
            translateY(0)
            scale(1);
    }
}

.modal-header {
    padding:
        18px 20px;

    border-bottom:
        1px solid #e2e8f0;

    display: flex;

    align-items: center;

    justify-content: space-between;
}

.modal-header h3 {
    font-size: 17px;

    color: #0f172a;
}

.close-btn {
    width: 34px;

    height: 34px;

    border: none;

    border-radius: 7px;

    background: #f1f5f9;

    color: #475569;

    display: flex;

    align-items: center;

    justify-content: center;
}

.close-btn:hover {
    background: #e2e8f0;
}

.modal-body {
    padding: 20px;
}

.modal-body .form-grid {
    display: grid;

    grid-template-columns:
        repeat(2, 1fr);

    gap: 16px;
}

.modal-body .form-group label span {
    color: #dc2626;
}

.modal-footer {
    padding:
        16px 20px;

    border-top:
        1px solid #e2e8f0;

    display: flex;

    align-items: center;

    justify-content: flex-end;

    gap: 10px;
}

.btn-cancel {
    height: 40px;

    padding:
        0 16px;

    border:
        1px solid #cbd5e1;

    background: white;

    color: #475569;

    border-radius: 7px;

    font-size: 13px;

    font-weight: 600;
}

.btn-cancel:hover {
    background: #f8fafc;
}

.btn-save {
    height: 40px;

    padding:
        0 16px;

    border: none;

    background: #2563eb;

    color: white;

    border-radius: 7px;

    font-size: 13px;

    font-weight: 600;

    display: inline-flex;

    align-items: center;

    gap: 7px;
}

.btn-save:hover {
    background: #1d4ed8;
}


/* =========================================================
   OVERLAY
========================================================= */

.overlay {
    position: fixed;

    inset: 0;

    background:
        rgba(15,23,42,.50);

    z-index: 950;

    display: none;
}

.overlay.active {
    display: block;
}


/* =========================================================
   DARK MODE
========================================================= */

body.dark-mode {
    background: #0f172a;

    color: #e2e8f0;
}

body.dark-mode .topbar {
    background: #111827;

    border-color: #1f2937;
}

body.dark-mode .theme-btn,
body.dark-mode .notification,
body.dark-mode .menu-btn {
    background: #1f2937;

    color: #e5e7eb;
}

body.dark-mode .theme-btn:hover,
body.dark-mode .notification:hover,
body.dark-mode .menu-btn:hover {
    background: #374151;
}

body.dark-mode .user-text strong {
    color: #f8fafc;
}

body.dark-mode .user-text span {
    color: #94a3b8;
}

body.dark-mode .page-title h1 {
    color: #f8fafc;
}

body.dark-mode .page-title p {
    color: #94a3b8;
}

body.dark-mode .stat-card,
body.dark-mode .filter-card,
body.dark-mode .table-card,
body.dark-mode .modal-content {
    background: #111827;

    border-color: #1f2937;
}

body.dark-mode .stat-info strong {
    color: #f8fafc;
}

body.dark-mode .stat-info span {
    color: #94a3b8;
}

body.dark-mode .filter-card label {
    color: #cbd5e1;
}

body.dark-mode .form-control {
    background: #1f2937;

    color: #f8fafc;

    border-color: #374151;
}

body.dark-mode .form-control::placeholder {
    color: #64748b;
}

body.dark-mode .btn-reset {
    background: #1f2937;

    color: #cbd5e1;

    border-color: #374151;
}

body.dark-mode .btn-reset:hover {
    background: #374151;
}

body.dark-mode thead {
    background: #1f2937;
}

body.dark-mode th {
    color: #cbd5e1;

    border-color: #374151;
}

body.dark-mode td {
    color: #cbd5e1;

    border-color: #1f2937;
}

body.dark-mode tbody tr:hover {
    background: #1f2937;
}

body.dark-mode .student-name {
    color: #f8fafc;
}

body.dark-mode .student-code {
    color: #94a3b8;
}

body.dark-mode .marks {
    color: #f8fafc;
}

body.dark-mode .table-header {
    border-color: #1f2937;
}

body.dark-mode .table-header h3 {
    color: #f8fafc;
}

body.dark-mode .table-header span {
    color: #94a3b8;
}

body.dark-mode .modal-header,
body.dark-mode .modal-footer {
    border-color: #1f2937;
}

body.dark-mode .modal-header h3 {
    color: #f8fafc;
}

body.dark-mode .close-btn {
    background: #1f2937;

    color: #cbd5e1;
}

body.dark-mode .btn-cancel {
    background: #1f2937;

    color: #cbd5e1;

    border-color: #374151;
}


/* =========================================================
   RESPONSIVE 1200
========================================================= */

@media (max-width: 1200px) {

    .stats-grid {
        grid-template-columns:
            repeat(2, 1fr);
    }

    .filter-form {
        grid-template-columns:
            repeat(2, 1fr);
    }

}


/* =========================================================
   RESPONSIVE 900
========================================================= */

@media (max-width: 900px) {

    .sidebar {
        transform:
            translateX(-100%);
    }

    .sidebar.active {
        transform:
            translateX(0);
    }

    .main {
        margin-left: 0;
    }

    .topbar {
        justify-content: space-between;

        padding: 0 20px;
    }

    .menu-btn {
        display: flex;
    }

    .topbar-right {
        margin-left: auto;

        justify-content: flex-end;
    }

    .user-text {
        display: none;
    }

    .content {
        padding: 20px;
    }

    .page-header {
        align-items: flex-start;

        flex-direction: column;
    }

}


/* =========================================================
   RESPONSIVE 650
========================================================= */

@media (max-width: 650px) {

    .stats-grid {
        grid-template-columns:
            1fr;
    }

    .filter-form {
        grid-template-columns:
            1fr;
    }

    .modal-body .form-grid {
        grid-template-columns:
            1fr;
    }

    .page-title h1 {
        font-size: 23px;
    }

    .content {
        padding: 15px;
    }

}


/* =========================================================
   RESPONSIVE 480
========================================================= */

@media (max-width: 480px) {

    .topbar {
        height: 64px;

        padding: 0 12px;
    }

    .topbar-right {
        gap: 7px;
    }

    .menu-btn,
    .theme-btn,
    .notification {
        width: 38px;

        height: 38px;
    }

    .avatar {
        width: 38px;

        height: 38px;
    }

    .page-header .btn-primary {
        width: 100%;
    }

    .modal {
        padding: 10px;
    }

    .modal-body {
        padding: 15px;
    }

}


/* =========================================================
   SCROLLBAR
========================================================= */

::-webkit-scrollbar {
    width: 7px;

    height: 7px;
}

::-webkit-scrollbar-track {
    background: #f1f5f9;
}

::-webkit-scrollbar-thumb {
    background: #cbd5e1;

    border-radius: 10px;
}

::-webkit-scrollbar-thumb:hover {
    background: #94a3b8;
}

    </style>

</head>

<body>

<!-- =====================================================
     SIDEBAR
===================================================== -->

<aside class="sidebar" id="sidebar">

    <div class="sidebar-logo">

        <h2>
            <i class="fa-solid fa-school"></i>
            School MS
        </h2>

    </div>

    <nav class="sidebar-menu">

        <div class="sidebar-menu-title">
            Main Menu
        </div>

        <a href="dashboard.php">
            <i class="fa-solid fa-gauge"></i>
            <span>Dashboard</span>
        </a>

        <a href="students.php">
            <i class="fa-solid fa-user-graduate"></i>
            <span>Students</span>
        </a>

        <a href="teachers.php">
            <i class="fa-solid fa-chalkboard-user"></i>
            <span>Teachers</span>
        </a>

        <a href="classes.php">
            <i class="fa-solid fa-school"></i>
            <span>Classes</span>
        </a>

        <a href="subjects.php">
            <i class="fa-solid fa-book"></i>
            <span>Subjects</span>
        </a>

        <a href="attendance.php">
            <i class="fa-solid fa-calendar-check"></i>
            <span>Attendance</span>
        </a>

        <a href="exams.php">
            <i class="fa-solid fa-file-lines"></i>
            <span>Exams</span>
        </a>

        <a href="results.php" class="active">
            <i class="fa-solid fa-square-poll-vertical"></i>
            <span>Results</span>
        </a>

        <a href="fees.php">
            <i class="fa-solid fa-money-bill-wave"></i>
            <span>Fees</span>
        </a>

        <a href="payments.php">
            <i class="fa-solid fa-credit-card"></i>
            <span>Payments</span>
        </a>

        <a href="salary.php">
            <i class="fa-solid fa-money-check-dollar"></i>
            <span>Salary</span>
        </a>

        <a href="reports.php">
            <i class="fa-solid fa-chart-column"></i>
            <span>Reports</span>
        </a>

        <a href="parents.php">
            <i class="fa-solid fa-users"></i>
            <span>Parents</span>
        </a>

        <a href="settings.php">
            <i class="fa-solid fa-gear"></i>
            <span>Settings</span>
        </a>

        <a href="logout.php">
            <i class="fa-solid fa-right-from-bracket"></i>
            <span>Logout</span>
        </a>

    </nav>

</aside>


<!-- =====================================================
     OVERLAY
===================================================== -->

<div
    class="overlay"
    id="overlay"
></div>


<!-- =====================================================
     MAIN
===================================================== -->

<main class="main">

    <!-- TOPBAR -->

    <div class="topbar">

        <button
            class="menu-btn"
            id="menuBtn"
            type="button"
        >
            <i class="fa-solid fa-bars"></i>
        </button>

        <div class="topbar-right">

            <button
                type="button"
                class="theme-btn"
                id="themeBtn"
                title="Switch to Dark Mode"
            >
                <i
                    class="fa-solid fa-moon"
                    id="themeIcon"
                ></i>
            </button>

            <a
                href="notifications.php"
                class="notification"
                title="Notifications"
            >

                <i class="fa-regular fa-bell"></i>

                <?php if ($unreadCount > 0): ?>

                    <span class="notification-badge">

                        <?= $unreadCount > 99
                            ? '99+'
                            : $unreadCount
                        ?>

                    </span>

                <?php endif; ?>

            </a>

            <div class="user-info">

                <div class="avatar">

                    <?= strtoupper(
                        substr(
                            $userName,
                            0,
                            1
                        )
                    ) ?>

                </div>

                <div class="user-text">

                    <strong>
                        <?= htmlspecialchars(
                            $userName
                        ) ?>
                    </strong>

                    <span>
                        <?= htmlspecialchars(
                            $userRole
                        ) ?>
                    </span>

                </div>

            </div>

        </div>

    </div>


    <!-- =================================================
         CONTENT
    ================================================= -->

    <section class="content">

        <!-- PAGE HEADER -->

        <div class="page-header">

            <div class="page-title">

                <h1>
                    Results
                </h1>

                <p>
                    Manage student examination results
                    and academic performance.
                </p>

            </div>

            <button
                type="button"
                class="btn-primary"
                onclick="openAddModal()"
            >

                <i class="fa-solid fa-plus"></i>

                Add Result

            </button>

        </div>


        <!-- ALERT -->

        <?php if ($message !== ""): ?>

            <div
                class="alert <?= $messageType ?>"
            >

                <?php if ($messageType === "success"): ?>

                    <i class="fa-solid fa-circle-check"></i>

                <?php else: ?>

                    <i class="fa-solid fa-circle-exclamation"></i>

                <?php endif; ?>

                <span>
                    <?= htmlspecialchars($message) ?>
                </span>

            </div>

        <?php endif; ?>


        <!-- =================================================
             STATISTICS
        ================================================= -->

        <div class="stats-grid">

            <div class="stat-card">

                <div class="stat-icon blue">

                    <i class="fa-solid fa-file-lines"></i>

                </div>

                <div class="stat-info">

                    <span>
                        Total Results
                    </span>

                    <strong>
                        <?= number_format(
                            $totalResults
                        ) ?>
                    </strong>

                </div>

            </div>


            <div class="stat-card">

                <div class="stat-icon green">

                    <i class="fa-solid fa-circle-check"></i>

                </div>

                <div class="stat-info">

                    <span>
                        Passed
                    </span>

                    <strong>
                        <?= number_format(
                            $passedResults
                        ) ?>
                    </strong>

                </div>

            </div>


            <div class="stat-card">

                <div class="stat-icon red">

                    <i class="fa-solid fa-circle-xmark"></i>

                </div>

                <div class="stat-info">

                    <span>
                        Failed
                    </span>

                    <strong>
                        <?= number_format(
                            $failedResults
                        ) ?>
                    </strong>

                </div>

            </div>


            <div class="stat-card">

                <div class="stat-icon orange">

                    <i class="fa-solid fa-chart-line"></i>

                </div>

                <div class="stat-info">

                    <span>
                        Average Marks
                    </span>

                    <strong>
                        <?= number_format(
                            (float)$averageMarks,
                            2
                        ) ?>
                    </strong>

                </div>

            </div>

        </div>


        <!-- =================================================
             FILTER
        ================================================= -->

        <div class="filter-card">

            <form
                method="GET"
                class="filter-form"
            >

                <div class="form-group">

                    <label>
                        Search
                    </label>

                    <input
                        type="text"
                        name="search"
                        class="form-control"
                        placeholder="Search student, exam or subject..."
                        value="<?= htmlspecialchars(
                            $search
                        ) ?>"
                    >

                </div>


                <div class="form-group">

                    <label>
                        Exam
                    </label>

                    <select
                        name="exam"
                        class="form-control"
                    >

                        <option value="">
                            All Exams
                        </option>

                        <?php if ($exams): ?>

                            <?php
                            while (
                                $exam =
                                $exams->fetch_assoc()
                            ):
                            ?>

                                <option
                                    value="<?= $exam['id'] ?>"
                                    <?= $filterExam ==
                                        $exam['id']
                                        ? 'selected'
                                        : ''
                                    ?>
                                >

                                    <?= htmlspecialchars(
                                        $exam['exam_name']
                                    ) ?>

                                    <?php if (
                                        !empty(
                                            $exam['exam_date']
                                        )
                                    ): ?>

                                        -
                                        <?= htmlspecialchars(
                                            $exam['exam_date']
                                        ) ?>

                                    <?php endif; ?>

                                </option>

                            <?php endwhile; ?>

                        <?php endif; ?>

                    </select>

                </div>


                <div class="form-group">

                    <label>
                        Subject
                    </label>

                    <select
                        name="subject"
                        class="form-control"
                    >

                        <option value="">
                            All Subjects
                        </option>

                        <?php if ($subjects): ?>

                            <?php
                            while (
                                $subject =
                                $subjects->fetch_assoc()
                            ):
                            ?>

                                <option
                                    value="<?= $subject['id'] ?>"
                                    <?= $filterSubject ==
                                        $subject['id']
                                        ? 'selected'
                                        : ''
                                    ?>
                                >

                                    <?= htmlspecialchars(
                                        $subject['subject_name']
                                    ) ?>

                                </option>

                            <?php endwhile; ?>

                        <?php endif; ?>

                    </select>

                </div>


                <button
                    type="submit"
                    class="btn-filter"
                >

                    <i class="fa-solid fa-filter"></i>

                    Search

                </button>


                <a
                    href="results.php"
                    class="btn-reset"
                >

                    <i class="fa-solid fa-rotate-left"></i>

                    Reset

                </a>

            </form>

        </div>


        <!-- =================================================
             RESULTS TABLE
        ================================================= -->

        <div class="table-card">

            <div class="table-header">

                <h3>
                    Student Results
                </h3>

                <span>
                    <?= $results->num_rows ?>
                    result(s) found
                </span>

            </div>


            <div class="table-wrapper">

                <?php if ($results->num_rows > 0): ?>

                    <table>

                        <thead>

                            <tr>

                                <th>
                                    Student
                                </th>

                                <th>
                                    Exam
                                </th>

                                <th>
                                    Subject
                                </th>

                                <th>
                                    Marks
                                </th>

                                <th>
                                    Grade
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

                        <?php while (
                            $row =
                            $results->fetch_assoc()
                        ): ?>

                            <tr>

                                <!-- STUDENT -->

                                <td>

                                    <div class="student-cell">

                                        <div
                                            class="student-avatar"
                                        >

                                            <?= strtoupper(
                                                substr(
                                                    $row['full_name']
                                                    ?? 'S',
                                                    0,
                                                    1
                                                )
                                            ) ?>

                                        </div>

                                        <div>

                                            <div
                                                class="student-name"
                                            >

                                                <?= htmlspecialchars(
                                                    $row['full_name']
                                                    ?? 'Unknown Student'
                                                ) ?>

                                            </div>

                                            <div
                                                class="student-code"
                                            >

                                                <?= htmlspecialchars(
                                                    $row['student_code']
                                                    ?? ''
                                                ) ?>

                                            </div>

                                        </div>

                                    </div>

                                </td>


                                <!-- EXAM -->

                                <td>

                                    <strong>
                                        <?= htmlspecialchars(
                                            $row['exam_name']
                                            ?? 'N/A'
                                        ) ?>
                                    </strong>

                                    <?php if (
                                        !empty(
                                            $row['exam_date']
                                        )
                                    ): ?>

                                        <div
                                            style="
                                                color:#64748b;
                                                font-size:11px;
                                                margin-top:3px;
                                            "
                                        >

                                            <?= htmlspecialchars(
                                                $row['exam_date']
                                            ) ?>

                                        </div>

                                    <?php endif; ?>

                                </td>


                                <!-- SUBJECT -->

                                <td>

                                    <?= htmlspecialchars(
                                        $row['subject_name']
                                        ?? 'N/A'
                                    ) ?>

                                </td>


                                <!-- MARKS -->

                                <td>

                                    <span class="marks">

                                        <?= number_format(
                                            (float)$row['marks'],
                                            2
                                        ) ?>

                                        / 100

                                    </span>

                                </td>


                                <!-- GRADE -->

                                <td>

                                    <?php

                                    $gradeClass =
                                        'grade-f';

                                    if (
                                        $row['grade'] === 'A+' ||
                                        $row['grade'] === 'A'
                                    ) {

                                        $gradeClass =
                                            'grade-a';

                                    } elseif (
                                        $row['grade'] === 'B'
                                    ) {

                                        $gradeClass =
                                            'grade-b';

                                    } elseif (
                                        $row['grade'] === 'C'
                                    ) {

                                        $gradeClass =
                                            'grade-c';

                                    } elseif (
                                        $row['grade'] === 'D'
                                    ) {

                                        $gradeClass =
                                            'grade-d';
                                    }

                                    ?>

                                    <span
                                        class="
                                            grade-badge
                                            <?= $gradeClass ?>
                                        "
                                    >

                                        <?= htmlspecialchars(
                                            $row['grade']
                                        ) ?>

                                    </span>

                                </td>


                                <!-- REMARKS -->

                                <td>

                                    <?= htmlspecialchars(
                                        $row['remarks']
                                        ?? ''
                                    ) ?>

                                </td>


                                <!-- ACTIONS -->

                                <td>

                                    <div class="actions">

                                        <button
                                            type="button"
                                            class="
                                                action-btn
                                                action-edit
                                            "
                                            title="Edit Result"
                                            onclick='openEditModal(
                                                <?= json_encode(
                                                    $row,
                                                    JSON_HEX_TAG |
                                                    JSON_HEX_APOS |
                                                    JSON_HEX_AMP |
                                                    JSON_HEX_QUOT
                                                ) ?>
                                            )'
                                        >

                                            <i
                                                class="
                                                    fa-solid
                                                    fa-pen
                                                "
                                            ></i>

                                        </button>


                                        <button
                                            type="button"
                                            class="
                                                action-btn
                                                action-delete
                                            "
                                            title="Delete Result"
                                            onclick="
                                                deleteResult(
                                                    <?= (int)$row['id'] ?>
                                                )
                                            "
                                        >

                                            <i
                                                class="
                                                    fa-solid
                                                    fa-trash
                                                "
                                            ></i>

                                        </button>

                                    </div>

                                </td>

                            </tr>

                        <?php endwhile; ?>

                        </tbody>

                    </table>

                <?php else: ?>

                    <div class="empty-state">

                        <i
                            class="
                                fa-solid
                                fa-chart-column
                            "
                        ></i>

                        <h3>
                            No Results Found
                        </h3>

                        <p>
                            There are no student results
                            matching your search.
                        </p>

                    </div>

                <?php endif; ?>

            </div>

        </div>

    </section>

</main>


<!-- =====================================================
     ADD RESULT MODAL
===================================================== -->

<div
    class="modal"
    id="addModal"
>

    <div class="modal-content">

        <div class="modal-header">

            <h3>
                Add New Result
            </h3>

            <button
                type="button"
                class="close-btn"
                onclick="closeAddModal()"
            >

                <i class="fa-solid fa-xmark"></i>

            </button>

        </div>


        <form
            method="POST"
        >

            <div class="modal-body">

                <div class="form-grid">


                    <!-- EXAM -->

                    <div class="form-group">

                        <label>
                            Exam <span>*</span>
                        </label>

                        <select
                            name="exam_id"
                            class="form-control"
                            required
                        >

                            <option value="">
                                Select Exam
                            </option>

                            <?php

                            $addExams =
                                $conn->query("
                                    SELECT
                                        id,
                                        exam_name,
                                        exam_date
                                    FROM exams
                                    ORDER BY
                                        exam_date DESC,
                                        exam_name ASC
                                ");

                            if ($addExams):

                                while (
                                    $exam =
                                    $addExams->fetch_assoc()
                                ):

                            ?>

                                <option
                                    value="<?= $exam['id'] ?>"
                                >

                                    <?= htmlspecialchars(
                                        $exam['exam_name']
                                    ) ?>

                                    <?php if (
                                        !empty(
                                            $exam['exam_date']
                                        )
                                    ): ?>

                                        -
                                        <?= htmlspecialchars(
                                            $exam['exam_date']
                                        ) ?>

                                    <?php endif; ?>

                                </option>

                            <?php

                                endwhile;

                            endif;

                            ?>

                        </select>

                    </div>


                    <!-- STUDENT -->

                    <div class="form-group">

                        <label>
                            Student <span>*</span>
                        </label>

                        <select
                            name="student_id"
                            class="form-control"
                            required
                        >

                            <option value="">
                                Select Student
                            </option>

                            <?php

                            $addStudents =
                                $conn->query("
                                    SELECT
                                        id,
                                        student_id,
                                        full_name
                                    FROM students
                                    ORDER BY
                                        full_name ASC
                                ");

                            if ($addStudents):

                                while (
                                    $student =
                                    $addStudents->fetch_assoc()
                                ):

                            ?>

                                <option
                                    value="<?= $student['id'] ?>"
                                >

                                    <?= htmlspecialchars(
                                        $student['full_name']
                                    ) ?>

                                    (
                                    <?= htmlspecialchars(
                                        $student['student_id']
                                    ) ?>
                                    )

                                </option>

                            <?php

                                endwhile;

                            endif;

                            ?>

                        </select>

                    </div>


                    <!-- SUBJECT -->

                    <div class="form-group">

                        <label>
                            Subject <span>*</span>
                        </label>

                        <select
                            name="subject_id"
                            class="form-control"
                            required
                        >

                            <option value="">
                                Select Subject
                            </option>

                            <?php

                            $addSubjects =
                                $conn->query("
                                    SELECT
                                        id,
                                        subject_name
                                    FROM subjects
                                    ORDER BY
                                        subject_name ASC
                                ");

                            if ($addSubjects):

                                while (
                                    $subject =
                                    $addSubjects->fetch_assoc()
                                ):

                            ?>

                                <option
                                    value="<?= $subject['id'] ?>"
                                >

                                    <?= htmlspecialchars(
                                        $subject['subject_name']
                                    ) ?>

                                </option>

                            <?php

                                endwhile;

                            endif;

                            ?>

                        </select>

                    </div>


                    <!-- MARKS -->

                    <div class="form-group">

                        <label>
                            Marks <span>*</span>
                        </label>

                        <input
                            type="number"
                            name="marks"
                            class="form-control"
                            min="0"
                            max="100"
                            step="0.01"
                            placeholder="Enter marks"
                            required
                        >

                    </div>

                </div>

            </div>


            <div class="modal-footer">

                <button
                    type="button"
                    class="btn-cancel"
                    onclick="closeAddModal()"
                >
                    Cancel
                </button>

                <button
                    type="submit"
                    name="add_result"
                    class="btn-save"
                >

                    <i
                        class="
                            fa-solid
                            fa-floppy-disk
                        "
                    ></i>

                    Save Result

                </button>

            </div>

        </form>

    </div>

</div>


<!-- =====================================================
     EDIT RESULT MODAL
===================================================== -->

<div
    class="modal"
    id="editModal"
>

    <div class="modal-content">

        <div class="modal-header">

            <h3>
                Edit Result
            </h3>

            <button
                type="button"
                class="close-btn"
                onclick="closeEditModal()"
            >

                <i class="fa-solid fa-xmark"></i>

            </button>

        </div>


        <form
            method="POST"
        >

            <input
                type="hidden"
                name="id"
                id="edit_id"
            >


            <div class="modal-body">

                <div class="form-grid">


                    <!-- EXAM -->

                    <div class="form-group">

                        <label>
                            Exam <span>*</span>
                        </label>

                        <select
                            name="exam_id"
                            id="edit_exam_id"
                            class="form-control"
                            required
                        >

                            <option value="">
                                Select Exam
                            </option>

                            <?php

                            $editExams =
                                $conn->query("
                                    SELECT
                                        id,
                                        exam_name,
                                        exam_date
                                    FROM exams
                                    ORDER BY
                                        exam_date DESC,
                                        exam_name ASC
                                ");

                            if ($editExams):

                                while (
                                    $exam =
                                    $editExams->fetch_assoc()
                                ):

                            ?>

                                <option
                                    value="<?= $exam['id'] ?>"
                                >

                                    <?= htmlspecialchars(
                                        $exam['exam_name']
                                    ) ?>

                                    <?php if (
                                        !empty(
                                            $exam['exam_date']
                                        )
                                    ): ?>

                                        -
                                        <?= htmlspecialchars(
                                            $exam['exam_date']
                                        ) ?>

                                    <?php endif; ?>

                                </option>

                            <?php

                                endwhile;

                            endif;

                            ?>

                        </select>

                    </div>


                    <!-- STUDENT -->

                    <div class="form-group">

                        <label>
                            Student <span>*</span>
                        </label>

                        <select
                            name="student_id"
                            id="edit_student_id"
                            class="form-control"
                            required
                        >

                            <option value="">
                                Select Student
                            </option>

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

                            if ($editStudents):

                                while (
                                    $student =
                                    $editStudents->fetch_assoc()
                                ):

                            ?>

                                <option
                                    value="<?= $student['id'] ?>"
                                >

                                    <?= htmlspecialchars(
                                        $student['full_name']
                                    ) ?>

                                    (
                                    <?= htmlspecialchars(
                                        $student['student_id']
                                    ) ?>
                                    )

                                </option>

                            <?php

                                endwhile;

                            endif;

                            ?>

                        </select>

                    </div>


                    <!-- SUBJECT -->

                    <div class="form-group">

                        <label>
                            Subject <span>*</span>
                        </label>

                        <select
                            name="subject_id"
                            id="edit_subject_id"
                            class="form-control"
                            required
                        >

                            <option value="">
                                Select Subject
                            </option>

                            <?php

                            $editSubjects =
                                $conn->query("
                                    SELECT
                                        id,
                                        subject_name
                                    FROM subjects
                                    ORDER BY
                                        subject_name ASC
                                ");

                            if ($editSubjects):

                                while (
                                    $subject =
                                    $editSubjects->fetch_assoc()
                                ):

                            ?>

                                <option
                                    value="<?= $subject['id'] ?>"
                                >

                                    <?= htmlspecialchars(
                                        $subject['subject_name']
                                    ) ?>

                                </option>

                            <?php

                                endwhile;

                            endif;

                            ?>

                        </select>

                    </div>


                    <!-- MARKS -->

                    <div class="form-group">

                        <label>
                            Marks <span>*</span>
                        </label>

                        <input
                            type="number"
                            name="marks"
                            id="edit_marks"
                            class="form-control"
                            min="0"
                            max="100"
                            step="0.01"
                            required
                        >

                    </div>

                </div>

            </div>


            <div class="modal-footer">

                <button
                    type="button"
                    class="btn-cancel"
                    onclick="closeEditModal()"
                >
                    Cancel
                </button>

                <button
                    type="submit"
                    name="update_result"
                    class="btn-save"
                >

                    <i
                        class="
                            fa-solid
                            fa-floppy-disk
                        "
                    ></i>

                    Update Result

                </button>

            </div>

        </form>

    </div>

</div>


<script>

/* =========================================================
   SIDEBAR
========================================================= */

const sidebar =
    document.getElementById(
        "sidebar"
    );

const overlay =
    document.getElementById(
        "overlay"
    );

const menuBtn =
    document.getElementById(
        "menuBtn"
    );

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


/* =========================================================
   DARK / LIGHT MODE
========================================================= */

const themeBtn =
    document.getElementById(
        "themeBtn"
    );

const themeIcon =
    document.getElementById(
        "themeIcon"
    );

const savedTheme =
    localStorage.getItem(
        "schoolTheme"
    );


/* LOAD SAVED THEME */

if (savedTheme === "dark") {

    document.body.classList.add(
        "dark-mode"
    );

    themeIcon.classList.remove(
        "fa-moon"
    );

    themeIcon.classList.add(
        "fa-sun"
    );

    themeBtn.title =
        "Switch to Light Mode";

} else {

    themeBtn.title =
        "Switch to Dark Mode";
}


/* TOGGLE THEME */

themeBtn.addEventListener(
    "click",
    function () {

        document.body.classList.toggle(
            "dark-mode"
        );

        if (
            document.body.classList.contains(
                "dark-mode"
            )
        ) {

            localStorage.setItem(
                "schoolTheme",
                "dark"
            );

            themeIcon.classList.remove(
                "fa-moon"
            );

            themeIcon.classList.add(
                "fa-sun"
            );

            themeBtn.title =
                "Switch to Light Mode";

        } else {

            localStorage.setItem(
                "schoolTheme",
                "light"
            );

            themeIcon.classList.remove(
                "fa-sun"
            );

            themeIcon.classList.add(
                "fa-moon"
            );

            themeBtn.title =
                "Switch to Dark Mode";
        }

    }
);


/* =========================================================
   ADD MODAL
========================================================= */

const addModal =
    document.getElementById(
        "addModal"
    );

function openAddModal() {

    addModal.classList.add(
        "active"
    );
}

function closeAddModal() {

    addModal.classList.remove(
        "active"
    );
}


/* =========================================================
   EDIT MODAL
========================================================= */

const editModal =
    document.getElementById(
        "editModal"
    );

function openEditModal(result) {

    document.getElementById(
        "edit_id"
    ).value =
        result.id;

    document.getElementById(
        "edit_exam_id"
    ).value =
        result.exam_id;

    document.getElementById(
        "edit_student_id"
    ).value =
        result.student_id;

    document.getElementById(
        "edit_subject_id"
    ).value =
        result.subject_id;

    document.getElementById(
        "edit_marks"
    ).value =
        result.marks;

    editModal.classList.add(
        "active"
    );
}

function closeEditModal() {

    editModal.classList.remove(
        "active"
    );
}


/* =========================================================
   DELETE RESULT
========================================================= */

function deleteResult(id) {

    const confirmed =
        confirm(
            "Are you sure you want to delete this result?"
        );

    if (confirmed) {

        window.location.href =
            "results.php?delete=" +
            encodeURIComponent(id);
    }
}


/* =========================================================
   CLOSE MODAL OUTSIDE
========================================================= */

window.addEventListener(
    "click",
    function (event) {

        if (
            event.target ===
            addModal
        ) {

            closeAddModal();
        }

        if (
            event.target ===
            editModal
        ) {

            closeEditModal();
        }

    }
);


/* =========================================================
   ESC KEY
========================================================= */

document.addEventListener(
    "keydown",
    function (event) {

        if (
            event.key === "Escape"
        ) {

            closeAddModal();

            closeEditModal();

        }

    }
);


/* =========================================================
   AUTO HIDE ALERT
========================================================= */

setTimeout(
    function () {

        const alert =
            document.querySelector(
                ".alert"
            );

        if (alert) {

            alert.style.transition =
                "opacity .4s";

            alert.style.opacity =
                "0";

            setTimeout(
                function () {

                    alert.remove();

                },
                400
            );
        }

    },
    4000
);

</script>

</body>
</html>

<?php

/* =========================================================
   CLOSE CONNECTION
========================================================= */

if (isset($stmt) && $stmt) {
    $stmt->close();
}

$conn->close();

?>