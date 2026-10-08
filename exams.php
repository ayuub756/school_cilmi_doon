<?php
session_start();

/* =========================
   LOGIN PROTECTION
========================= */
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

/* =========================
   DATABASE CONNECTION
========================= */
$conn = new mysqli("localhost", "root", "", "student_management");

if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");

/* =========================
   NOTIFICATION HELPER
========================= */
require_once "notification_helper.php";

/* =========================
   USER SESSION
========================= */
$userName = $_SESSION['user_name'] ?? "Administrator";
$userRole = $_SESSION['user_role'] ?? "Admin";

/* =========================
   UNREAD NOTIFICATIONS
========================= */
$unreadCount = getUnreadNotificationCount(
    $conn,
    (int) $_SESSION['user_id']
);

/* =========================
   ALERT VARIABLES
========================= */
$message = "";
$messageType = "";

/* =========================
   ADD EXAM
========================= */
if (isset($_POST['add_exam'])) {

    $exam_name = trim($_POST['exam_name'] ?? "");

    $exam_date = !empty($_POST['exam_date'])
        ? $_POST['exam_date']
        : null;

    $class_id = !empty($_POST['class_id'])
        ? intval($_POST['class_id'])
        : null;

    if ($exam_name === "") {

        $message = "Please enter the exam name.";
        $messageType = "error";

    } else {

        /* CHECK DUPLICATE */

        $check = $conn->prepare("
            SELECT id
            FROM exams
            WHERE exam_name = ?
            AND class_id = ?
        ");

        $check->bind_param(
            "si",
            $exam_name,
            $class_id
        );

        $check->execute();

        $checkResult = $check->get_result();

        if ($checkResult->num_rows > 0) {

            $message =
                "This exam already exists for the selected class.";

            $messageType = "error";

        } else {

            /* INSERT EXAM */

            $stmt = $conn->prepare("
                INSERT INTO exams
                (exam_name, exam_date, class_id)
                VALUES (?, ?, ?)
            ");

            $stmt->bind_param(
                "ssi",
                $exam_name,
                $exam_date,
                $class_id
            );

            if ($stmt->execute()) {

                $message =
                    "Exam added successfully.";

                $messageType = "success";

                /* AUTOMATIC NOTIFICATION */

                createNotification(
                    $conn,
                    (int) $_SESSION['user_id'],
                    "New Exam Added",
                    $exam_name .
                    " has been successfully added.",
                    "success",
                    "exams.php"
                );

            } else {

                $message =
                    "Failed to add exam.";

                $messageType = "error";
            }

            $stmt->close();
        }

        $check->close();
    }
}

/* =========================
   UPDATE EXAM
========================= */
if (isset($_POST['update_exam'])) {

    $id = intval($_POST['id'] ?? 0);

    $exam_name =
        trim($_POST['exam_name'] ?? "");

    $exam_date =
        !empty($_POST['exam_date'])
            ? $_POST['exam_date']
            : null;

    $class_id =
        !empty($_POST['class_id'])
            ? intval($_POST['class_id'])
            : null;

    if ($exam_name === "") {

        $message =
            "Please enter the exam name.";

        $messageType = "error";

    } else {

        $check = $conn->prepare("
            SELECT id
            FROM exams
            WHERE exam_name = ?
            AND class_id = ?
            AND id != ?
        ");

        $check->bind_param(
            "sii",
            $exam_name,
            $class_id,
            $id
        );

        $check->execute();

        $checkResult =
            $check->get_result();

        if ($checkResult->num_rows > 0) {

            $message =
                "Another exam with the same name already exists for this class.";

            $messageType = "error";

        } else {

            $stmt = $conn->prepare("
                UPDATE exams
                SET exam_name = ?,
                    exam_date = ?,
                    class_id = ?
                WHERE id = ?
            ");

            $stmt->bind_param(
                "ssii",
                $exam_name,
                $exam_date,
                $class_id,
                $id
            );

            if ($stmt->execute()) {

                $message =
                    "Exam updated successfully.";

                $messageType =
                    "success";

            } else {

                $message =
                    "Failed to update exam.";

                $messageType =
                    "error";
            }

            $stmt->close();
        }

        $check->close();
    }
}

/* =========================
   DELETE EXAM
========================= */
if (isset($_GET['delete'])) {

    $id =
        intval($_GET['delete']);

    if ($id > 0) {

        $stmt = $conn->prepare("
            DELETE FROM exams
            WHERE id = ?
        ");

        $stmt->bind_param(
            "i",
            $id
        );

        if ($stmt->execute()) {

            $message =
                "Exam deleted successfully.";

            $messageType =
                "success";

        } else {

            $message =
                "Unable to delete exam.";

            $messageType =
                "error";
        }

        $stmt->close();
    }
}

/* =========================
   SEARCH & FILTER
========================= */

$search =
    isset($_GET['search'])
        ? trim($_GET['search'])
        : "";

$filterClass =
    isset($_GET['class_id'])
        ? intval($_GET['class_id'])
        : 0;

/* =========================
   BUILD EXAM QUERY
========================= */

$sql = "
    SELECT
        e.id,
        e.exam_name,
        e.exam_date,
        e.class_id,
        e.created_at,
        c.class_name,
        c.section
    FROM exams e
    LEFT JOIN classes c
        ON e.class_id = c.id
    WHERE 1=1
";

$params = [];
$types = "";

/* SEARCH */

if ($search !== "") {

    $sql .= "
        AND (
            e.exam_name LIKE ?
            OR c.class_name LIKE ?
            OR c.section LIKE ?
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

    $types .= "sss";
}

/* CLASS FILTER */

if ($filterClass > 0) {

    $sql .= "
        AND e.class_id = ?
    ";

    $params[] =
        $filterClass;

    $types .= "i";
}

/* ORDER */

$sql .= "
    ORDER BY
        e.exam_date IS NULL ASC,
        e.exam_date DESC,
        e.id DESC
";

/* EXECUTE */

$stmt =
    $conn->prepare($sql);

if (!empty($params)) {

    $stmt->bind_param(
        $types,
        ...$params
    );
}

$stmt->execute();

$exams =
    $stmt->get_result();

/* =========================
   GET CLASSES
========================= */

$classes =
    $conn->query("
        SELECT
            id,
            class_name,
            section
        FROM classes
        ORDER BY
            class_name ASC,
            section ASC
    ");

/* =========================
   STATISTICS
========================= */

/* TOTAL */

$totalExamsResult =
    $conn->query("
        SELECT COUNT(*) AS total
        FROM exams
    ");

$totalExams =
    $totalExamsResult
        ->fetch_assoc()['total'] ?? 0;

/* UPCOMING */

$upcomingResult =
    $conn->query("
        SELECT COUNT(*) AS total
        FROM exams
        WHERE exam_date >= CURDATE()
    ");

$upcomingExams =
    $upcomingResult
        ->fetch_assoc()['total'] ?? 0;

/* COMPLETED */

$completedResult =
    $conn->query("
        SELECT COUNT(*) AS total
        FROM exams
        WHERE exam_date < CURDATE()
    ");

$completedExams =
    $completedResult
        ->fetch_assoc()['total'] ?? 0;

/* WITHOUT DATE */

$undatedResult =
    $conn->query("
        SELECT COUNT(*) AS total
        FROM exams
        WHERE exam_date IS NULL
    ");

$undatedExams =
    $undatedResult
        ->fetch_assoc()['total'] ?? 0;

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
    Exams | School Management System
</title>

<!-- =========================
     PREVENT THEME FLASH
========================= -->

<script>
(function () {

    const theme =
        localStorage.getItem("theme");

    if (theme === "dark") {

        document.documentElement
            .classList.add("dark-mode");

    }

})();
</script>

<!-- FONT AWESOME -->

<link
    rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
>

<style>

/* ==================================================
   RESET
================================================== */

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
        background .3s ease,
        color .3s ease;
}

a {
    text-decoration: none;
}

button,
input,
select {
    font-family: inherit;
}

/* ==================================================
   SIDEBAR
================================================== */

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

    transition: .3s ease;
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

    border-radius: 10px;

    background: #2563eb;

    display: flex;
    align-items: center;
    justify-content: center;

    font-size: 20px;
}

.logo-text h2 {

    font-size: 16px;

    color: white;

    margin-bottom: 3px;
}

.logo-text span {

    font-size: 11px;

    color: #94a3b8;
}

/* ==================================================
   MENU
================================================== */

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

    padding:
        12px 13px;

    color: #cbd5e1;

    border-radius: 9px;

    margin-bottom: 4px;

    font-size: 14px;

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

/* ==================================================
   LOGOUT
================================================== */

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

    padding:
        12px 13px;

    color: #fca5a5;

    border-radius: 9px;

    font-size: 14px;
}

.logout a:hover {

    background: #7f1d1d;

    color: white;
}

/* ==================================================
   MAIN
================================================== */

.main {

    margin-left: 260px;

    min-height: 100vh;

    transition: .3s ease;
}

/* ==================================================
   TOPBAR
================================================== */

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

    position: sticky;

    top: 0;

    z-index: 900;

    transition: .3s ease;
}

.topbar-right {

    display: flex;

    align-items: center;

    gap: 12px;
}

/* ==================================================
   MENU BUTTON
================================================== */

.menu-btn {

    display: none;

    width: 40px;
    height: 40px;

    border: none;

    background: #eff6ff;

    color: #2563eb;

    border-radius: 8px;

    cursor: pointer;

    font-size: 18px;
}

/* ==================================================
   THEME TOGGLE
================================================== */

.theme-toggle {

    width: 40px;
    height: 40px;

    border:
        1px solid
        #e2e8f0;

    border-radius: 50%;

    background: #ffffff;

    color: #475569;

    display: flex;
    align-items: center;
    justify-content: center;

    cursor: pointer;

    font-size: 17px;

    transition: all .25s ease;
}

.theme-toggle:hover {

    background: #f1f5f9;

    color: #2563eb;

    transform:
        rotate(15deg);
}

/* ==================================================
   NOTIFICATION
================================================== */

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

    border:
        2px solid
        white;

    line-height: 1;
}

/* ==================================================
   USER
================================================== */

.user-info {

    display: flex;

    align-items: center;

    gap: 10px;
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

.user-text strong {

    display: block;

    font-size: 14px;

    color: #0f172a;
}

.user-text span {

    display: block;

    font-size: 12px;

    color: #64748b;

    margin-top: 2px;
}

/* ==================================================
   CONTENT
================================================== */

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

    margin-bottom: 7px;
}

.page-title p {

    color: #64748b;

    font-size: 14px;
}

.btn-primary {

    border: none;

    background: #2563eb;

    color: white;

    padding:
        12px 18px;

    border-radius: 8px;

    cursor: pointer;

    font-size: 14px;

    font-weight: 600;

    display: inline-flex;

    align-items: center;

    gap: 8px;

    transition: .2s;
}

.btn-primary:hover {

    background: #1d4ed8;

    transform:
        translateY(-1px);
}

/* ==================================================
   ALERT
================================================== */

.alert {

    padding:
        13px 16px;

    border-radius: 9px;

    margin-bottom: 20px;

    font-size: 14px;

    display: flex;

    align-items: center;

    gap: 10px;
}

.alert.success {

    background: #dcfce7;

    color: #166534;

    border:
        1px solid
        #bbf7d0;
}

.alert.error {

    background: #fee2e2;

    color: #991b1b;

    border:
        1px solid
        #fecaca;
}

/* ==================================================
   STATISTICS
================================================== */

.stats {

    display: grid;

    grid-template-columns:
        repeat(4, 1fr);

    gap: 18px;

    margin-bottom: 25px;
}

.stat-card {

    background: white;

    border:
        1px solid
        #e2e8f0;

    border-radius: 14px;

    padding: 20px;

    display: flex;

    align-items: center;

    justify-content: space-between;

    transition:
        background .3s ease,
        border .3s ease;
}

.stat-info span {

    color: #64748b;

    font-size: 13px;
}

.stat-info h2 {

    margin-top: 7px;

    font-size: 25px;
}

.stat-icon {

    width: 48px;
    height: 48px;

    border-radius: 10px;

    background: #eff6ff;

    color: #2563eb;

    display: flex;

    align-items: center;
    justify-content: center;

    font-size: 20px;
}

.stat-card:nth-child(2)
.stat-icon {

    background: #ecfdf5;

    color: #059669;
}

.stat-card:nth-child(3)
.stat-icon {

    background: #fff7ed;

    color: #ea580c;
}

.stat-card:nth-child(4)
.stat-icon {

    background: #fef2f2;

    color: #dc2626;
}

/* ==================================================
   FILTER CARD
================================================== */

.filter-card {

    background: white;

    border:
        1px solid
        #e2e8f0;

    border-radius: 14px;

    padding: 18px;

    margin-bottom: 20px;
}

.filter-form {

    display: grid;

    grid-template-columns:
        1fr 220px auto auto;

    gap: 12px;
}

.input-group {

    position: relative;
}

.input-group i {

    position: absolute;

    left: 13px;

    top: 50%;

    transform:
        translateY(-50%);

    color: #94a3b8;
}

.input,
.select {

    width: 100%;

    height: 44px;

    border:
        1px solid
        #cbd5e1;

    border-radius: 8px;

    padding:
        0 13px;

    outline: none;

    background: white;

    color: #0f172a;

    font-size: 14px;

    transition: .2s;
}

.input-group .input {

    padding-left: 40px;
}

.input:focus,
.select:focus {

    border-color: #2563eb;

    box-shadow:
        0 0 0 3px
        rgba(37,99,235,.1);
}

.btn-search,
.btn-reset {

    height: 44px;

    border-radius: 8px;

    padding:
        0 17px;

    cursor: pointer;

    font-size: 14px;

    font-weight: 600;
}

.btn-search {

    border: none;

    background: #2563eb;

    color: white;
}

.btn-search:hover {

    background: #1d4ed8;
}

.btn-reset {

    border:
        1px solid
        #cbd5e1;

    background: white;

    color: #475569;

    display: flex;

    align-items: center;

    justify-content: center;

    gap: 7px;
}

.btn-reset:hover {

    background: #f8fafc;
}

/* ==================================================
   TABLE
================================================== */

.table-card {

    background: white;

    border:
        1px solid
        #e2e8f0;

    border-radius: 14px;

    overflow: hidden;

    transition: .3s ease;
}

.table-header {

    padding:
        18px 20px;

    border-bottom:
        1px solid
        #e2e8f0;

    display: flex;

    align-items: center;

    justify-content: space-between;
}

.table-header h3 {

    font-size: 17px;
}

.table-header span {

    color: #64748b;

    font-size: 13px;
}

.table-wrapper {

    width: 100%;

    overflow-x: auto;
}

table {

    width: 100%;

    border-collapse: collapse;

    min-width: 800px;
}

th {

    background: #f8fafc;

    color: #64748b;

    font-size: 12px;

    text-transform: uppercase;

    letter-spacing: .4px;

    font-weight: 700;

    padding:
        14px 18px;

    text-align: left;

    border-bottom:
        1px solid
        #e2e8f0;
}

td {

    padding:
        15px 18px;

    border-bottom:
        1px solid
        #f1f5f9;

    font-size: 14px;

    color: #334155;
}

tbody tr {

    transition: .2s;
}

tbody tr:hover {

    background: #f8fafc;
}

tbody tr:last-child td {

    border-bottom: none;
}

.exam-name {

    font-weight: 600;

    color: #0f172a;
}

.class-badge {

    display: inline-flex;

    align-items: center;

    gap: 6px;

    padding:
        6px 10px;

    background: #eff6ff;

    color: #1d4ed8;

    border-radius: 20px;

    font-size: 12px;

    font-weight: 600;
}

.date-badge {

    display: inline-flex;

    align-items: center;

    gap: 7px;

    color: #475569;
}

.date-badge i {

    color: #2563eb;
}

.no-date {

    color: #94a3b8;

    font-style: italic;
}

/* ==================================================
   STATUS
================================================== */

.status {

    display: inline-flex;

    align-items: center;

    gap: 5px;

    padding:
        5px 9px;

    border-radius: 20px;

    font-size: 11px;

    font-weight: 700;
}

.status.upcoming {

    background: #dcfce7;

    color: #166534;
}

.status.completed {

    background: #f1f5f9;

    color: #64748b;
}

.status.no-date {

    background: #fff7ed;

    color: #c2410c;

    font-style: normal;
}

/* ==================================================
   ACTIONS
================================================== */

.actions {

    display: flex;

    gap: 7px;
}

.action-btn {

    width: 34px;
    height: 34px;

    border-radius: 7px;

    border: none;

    cursor: pointer;

    display: flex;

    align-items: center;

    justify-content: center;

    transition: .2s;
}

.edit-btn {

    background: #eff6ff;

    color: #2563eb;
}

.edit-btn:hover {

    background: #2563eb;

    color: white;
}

.delete-btn {

    background: #fef2f2;

    color: #dc2626;
}

.delete-btn:hover {

    background: #dc2626;

    color: white;
}

/* ==================================================
   EMPTY
================================================== */

.empty {

    text-align: center;

    padding:
        55px 20px;

    color: #64748b;
}

.empty i {

    font-size: 40px;

    color: #cbd5e1;

    margin-bottom: 12px;
}

.empty h3 {

    color: #475569;

    margin-bottom: 5px;
}

/* ==================================================
   MODAL
================================================== */

.modal {

    display: none;

    position: fixed;

    inset: 0;

    background:
        rgba(15,23,42,.55);

    z-index: 2000;

    align-items: center;

    justify-content: center;

    padding: 20px;
}

.modal.active {

    display: flex;
}

.modal-content {

    background: white;

    width: 100%;

    max-width: 520px;

    border-radius: 14px;

    overflow: hidden;

    box-shadow:
        0 20px 50px
        rgba(0,0,0,.2);

    animation:
        modalShow .2s ease;
}

@keyframes modalShow {

    from {

        opacity: 0;

        transform:
            translateY(-15px);
    }

    to {

        opacity: 1;

        transform:
            translateY(0);
    }
}

.modal-header {

    padding:
        18px 20px;

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

.close {

    width: 35px;
    height: 35px;

    border: none;

    background: #f1f5f9;

    color: #64748b;

    border-radius: 7px;

    cursor: pointer;

    font-size: 16px;
}

.close:hover {

    background: #fee2e2;

    color: #dc2626;
}

.modal-body {

    padding: 20px;
}

.form-group {

    margin-bottom: 17px;
}

.form-group label {

    display: block;

    margin-bottom: 7px;

    font-size: 13px;

    font-weight: 600;

    color: #334155;
}

.form-group label span {

    color: #dc2626;
}

.form-control {

    width: 100%;

    height: 44px;

    border:
        1px solid
        #cbd5e1;

    border-radius: 8px;

    padding:
        0 13px;

    outline: none;

    font-size: 14px;

    background: white;

    color: #0f172a;

    transition: .2s;
}

.form-control:focus {

    border-color: #2563eb;

    box-shadow:
        0 0 0 3px
        rgba(37,99,235,.1);
}

.modal-footer {

    padding:
        16px 20px;

    border-top:
        1px solid
        #e2e8f0;

    display: flex;

    justify-content: flex-end;

    gap: 10px;
}

.btn-cancel {

    height: 42px;

    padding:
        0 16px;

    border:
        1px solid
        #cbd5e1;

    background: white;

    color: #475569;

    border-radius: 8px;

    cursor: pointer;

    font-weight: 600;
}

.btn-save {

    height: 42px;

    padding:
        0 18px;

    border: none;

    background: #2563eb;

    color: white;

    border-radius: 8px;

    cursor: pointer;

    font-weight: 600;
}

.btn-save:hover {

    background: #1d4ed8;
}

/* ==================================================
   OVERLAY
================================================== */

.overlay {

    display: none;

    position: fixed;

    inset: 0;

    background:
        rgba(15,23,42,.5);

    z-index: 999;
}

/* ==================================================
   DARK MODE
================================================== */

html.dark-mode body {

    background: #0f172a;

    color: #e2e8f0;
}

/* TOPBAR */

html.dark-mode .topbar {

    background: #1e293b;

    border-color: #334155;
}

/* THEME */

html.dark-mode .theme-toggle {

    background: #334155;

    border-color: #475569;

    color: #f8fafc;
}

html.dark-mode .theme-toggle:hover {

    background: #475569;

    color: #facc15;
}

/* MENU */

html.dark-mode .menu-btn {

    background: #1e293b;

    border:
        1px solid
        #475569;

    color: #f8fafc;
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

html.dark-mode .user-text strong {

    color: #f8fafc;
}

html.dark-mode .user-text span {

    color: #94a3b8;
}

/* PAGE */

html.dark-mode .page-title h1 {

    color: #f8fafc;
}

html.dark-mode .page-title p {

    color: #94a3b8;
}

/* STATISTICS */

html.dark-mode .stat-card {

    background: #1e293b;

    border-color: #334155;
}

html.dark-mode .stat-info span {

    color: #94a3b8;
}

html.dark-mode .stat-info h2 {

    color: #f8fafc;
}

html.dark-mode .stat-icon {

    background: #334155;

    color: #60a5fa;
}

html.dark-mode
.stat-card:nth-child(2)
.stat-icon {

    background:
        rgba(5,150,105,.18);

    color: #34d399;
}

html.dark-mode
.stat-card:nth-child(3)
.stat-icon {

    background:
        rgba(234,88,12,.18);

    color: #fb923c;
}

html.dark-mode
.stat-card:nth-child(4)
.stat-icon {

    background:
        rgba(220,38,38,.18);

    color: #f87171;
}

/* FILTER */

html.dark-mode .filter-card {

    background: #1e293b;

    border-color: #334155;
}

/* INPUT */

html.dark-mode .input,
html.dark-mode .select {

    background: #0f172a;

    color: #e2e8f0;

    border-color: #475569;
}

html.dark-mode .input::placeholder {

    color: #64748b;
}

html.dark-mode .input:focus,
html.dark-mode .select:focus {

    border-color: #3b82f6;

    box-shadow:
        0 0 0 3px
        rgba(37,99,235,.15);
}

/* RESET */

html.dark-mode .btn-reset {

    background: #334155;

    border-color: #475569;

    color: #e2e8f0;
}

html.dark-mode .btn-reset:hover {

    background: #475569;
}

/* TABLE */

html.dark-mode .table-card {

    background: #1e293b;

    border-color: #334155;
}

html.dark-mode .table-header {

    border-color: #334155;
}

html.dark-mode .table-header h3 {

    color: #f8fafc;
}

html.dark-mode .table-header span {

    color: #94a3b8;
}

html.dark-mode th {

    background: #0f172a;

    color: #94a3b8;

    border-color: #334155;
}

html.dark-mode td {

    color: #cbd5e1;

    border-color: #334155;
}

html.dark-mode tbody tr:hover {

    background: #263449;
}

html.dark-mode .exam-name {

    color: #f8fafc;
}

/* CLASS */

html.dark-mode .class-badge {

    background:
        rgba(37,99,235,.18);

    color: #60a5fa;
}

/* DATE */

html.dark-mode .date-badge {

    color: #cbd5e1;
}

html.dark-mode .date-badge i {

    color: #60a5fa;
}

html.dark-mode .no-date {

    color: #94a3b8;
}

/* STATUS */

html.dark-mode .status.upcoming {

    background:
        rgba(22,163,74,.18);

    color: #86efac;
}

html.dark-mode .status.completed {

    background: #334155;

    color: #94a3b8;
}

html.dark-mode .status.no-date {

    background:
        rgba(234,88,12,.18);

    color: #fb923c;
}

/* ACTIONS */

html.dark-mode .edit-btn {

    background:
        rgba(37,99,235,.18);

    color: #60a5fa;
}

html.dark-mode .edit-btn:hover {

    background: #2563eb;

    color: white;
}

html.dark-mode .delete-btn {

    background:
        rgba(220,38,38,.18);

    color: #f87171;
}

html.dark-mode .delete-btn:hover {

    background: #dc2626;

    color: white;
}

/* EMPTY */

html.dark-mode .empty {

    color: #94a3b8;
}

html.dark-mode .empty h3 {

    color: #cbd5e1;
}

html.dark-mode .empty i {

    color: #475569;
}

/* ALERT */

html.dark-mode .alert.success {

    background:
        rgba(22,163,74,.15);

    color: #86efac;

    border-color: #166534;
}

html.dark-mode .alert.error {

    background:
        rgba(220,38,38,.15);

    color: #fca5a5;

    border-color: #991b1b;
}

/* MODAL */

html.dark-mode .modal {

    background:
        rgba(0,0,0,.72);
}

html.dark-mode .modal-content {

    background: #1e293b;

    box-shadow:
        0 20px 50px
        rgba(0,0,0,.5);
}

html.dark-mode .modal-header {

    border-color: #334155;
}

html.dark-mode .modal-header h3 {

    color: #f8fafc;
}

html.dark-mode .close {

    background: #334155;

    color: #cbd5e1;
}

html.dark-mode .close:hover {

    background: #7f1d1d;

    color: #fca5a5;
}

html.dark-mode .form-group label {

    color: #cbd5e1;
}

html.dark-mode .form-control {

    background: #0f172a;

    color: #e2e8f0;

    border-color: #475569;
}

html.dark-mode .form-control::placeholder {

    color: #64748b;
}

html.dark-mode .form-control:focus {

    border-color: #3b82f6;

    box-shadow:
        0 0 0 3px
        rgba(37,99,235,.15);
}

html.dark-mode .modal-footer {

    border-color: #334155;
}

html.dark-mode .btn-cancel {

    background: #334155;

    border-color: #475569;

    color: #e2e8f0;
}

html.dark-mode .btn-cancel:hover {

    background: #475569;
}

/* SIDEBAR SHADOW */

html.dark-mode .sidebar {

    box-shadow:
        10px 0 30px
        rgba(0,0,0,.35);
}

/* ==================================================
   RESPONSIVE
================================================== */

@media (max-width: 1100px) {

    .stats {

        grid-template-columns:
            repeat(2, 1fr);
    }

    .filter-form {

        grid-template-columns:
            1fr 1fr;
    }
}

/* TABLET */

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

        width: 100%;
    }

    .menu-btn {

        display: flex;

        align-items: center;

        justify-content: center;
    }

    .topbar {

        justify-content: flex-start;

        gap: 10px;

        padding: 0 20px;
    }

    .topbar-right {

        width: 100%;

        gap: 10px;
    }

    .notification {

        margin-left: auto;
    }

    .overlay.active {

        display: block;
    }
}

/* SMALL TABLET */

@media (max-width: 650px) {

    .content {

        padding:
            20px 15px;
    }

    .page-header {

        align-items: flex-start;

        flex-direction: column;
    }

    .page-header .btn-primary {

        width: 100%;

        justify-content: center;
    }

    .stats {

        grid-template-columns: 1fr;
    }

    .filter-form {

        grid-template-columns: 1fr;
    }

    .user-text {

        display: none;
    }

    .topbar {

        padding: 0 15px;
    }
}

/* MOBILE */

@media (max-width: 480px) {

    .page-title h1 {

        font-size: 23px;
    }

    .stat-card {

        padding: 16px;
    }

    .modal {

        padding: 12px;
    }

    .modal-footer {

        flex-direction: column;
    }

    .btn-cancel,
    .btn-save {

        width: 100%;
    }

    .theme-toggle,
    .menu-btn,
    .notification {

        width: 38px;
        height: 38px;
    }
}

/* VERY SMALL */

@media (max-width: 380px) {

    .content {

        padding: 12px 10px;
    }

    .topbar {

        padding: 0 10px;
    }

    .topbar-right {

        gap: 6px;
    }

    .theme-toggle,
    .menu-btn,
    .notification {

        width: 35px;
        height: 35px;

        font-size: 14px;
    }

    .avatar {

        width: 35px;
        height: 35px;

        font-size: 12px;
    }

    .page-title h1 {

        font-size: 21px;
    }
}

</style>

</head>

<body>

<!-- ==================================================
     SIDEBAR
================================================== -->

<aside
    class="sidebar"
    id="sidebar"
>

    <div class="logo">

        <div class="logo-icon">

            <i class="fa-solid fa-school"></i>

        </div>

        <div class="logo-text">

            <h2>Cilmi_Doon</h2>

            <span>
                School Management
            </span>

        </div>

    </div>


    <ul class="menu">

        <li class="menu-title">
            Main Menu
        </li>

        <li>
            <a href="dashboard.php">

                <i class="fa-solid fa-chart-line"></i>

                <span>
                    Dashboard
                </span>

            </a>
        </li>


        <li>
            <a href="students.php">

                <i class="fa-solid fa-user-graduate"></i>

                <span>
                    Students
                </span>

            </a>
        </li>


        <li>
            <a href="teachers.php">

                <i class="fa-solid fa-chalkboard-user"></i>

                <span>
                    Teachers
                </span>

            </a>
        </li>


        <li class="menu-title">
            Academic
        </li>


        <li>
            <a href="classes.php">

                <i class="fa-solid fa-school"></i>

                <span>
                    Classes
                </span>

            </a>
        </li>


        <li>
            <a href="subjects.php">

                <i class="fa-solid fa-book"></i>

                <span>
                    Subjects
                </span>

            </a>
        </li>


        <li>
            <a href="attendance.php">

                <i class="fa-solid fa-calendar-check"></i>

                <span>
                    Attendance
                </span>

            </a>
        </li>


        <li>
            <a
                href="exams.php"
                class="active"
            >

                <i class="fa-solid fa-file-pen"></i>

                <span>
                    Exams
                </span>

            </a>
        </li>


        <li>
            <a href="results.php">

                <i class="fa-solid fa-square-poll-vertical"></i>

                <span>
                    Results
                </span>

            </a>
        </li>


        <li class="menu-title">
            Finance
        </li>


        <li>
            <a href="fees.php">

                <i class="fa-solid fa-money-bill-wave"></i>

                <span>
                    Fees
                </span>

            </a>
        </li>


        <li>
            <a href="payments.php">

                <i class="fa-solid fa-credit-card"></i>

                <span>
                    Payments
                </span>

            </a>
        </li>


        <li>
            <a href="salary.php">

                <i class="fas fa-wallet"></i>

                <span>
                    Salary
                </span>

            </a>
        </li>


        <li>
            <a href="reports.php">

                <i class="fas fa-chart-pie"></i>

                <span>
                    Reports
                </span>

            </a>
        </li>


        <li class="menu-title">
            System
        </li>


        <li>
            <a href="parents.php">

                <i class="fa-solid fa-users"></i>

                <span>
                    Parents
                </span>

            </a>
        </li>


        <li>
            <a href="settings.php">

                <i class="fa-solid fa-gear"></i>

                <span>
                    Settings
                </span>

            </a>
        </li>

    </ul>


    <div class="logout">

        <a
            href="logout.php"
            onclick="
                return confirm(
                    'Are you sure you want to logout?'
                );
            "
        >

            <i
                class="
                    fa-solid
                    fa-right-from-bracket
                "
            ></i>

            <span>
                Logout
            </span>

        </a>

    </div>

</aside>


<!-- OVERLAY -->

<div
    class="overlay"
    id="overlay"
></div>


<!-- ==================================================
     MAIN
================================================== -->

<div class="main">

    <!-- TOPBAR -->

    <div class="topbar">

        <button
            class="menu-btn"
            id="menuBtn"
            type="button"
            title="Open Menu"
        >

            <i class="fa-solid fa-bars"></i>

        </button>


        <div class="topbar-right">

            <!-- THEME -->

            <button
                type="button"
                class="theme-toggle"
                id="themeToggle"
                title="Switch to Dark Mode"
            >

                <i class="fas fa-moon"></i>

            </button>


            <!-- NOTIFICATION -->

            <a
                href="notifications.php"
                class="notification"
                title="Notifications"
            >

                <i
                    class="fa-regular fa-bell"
                ></i>


                <?php if ($unreadCount > 0): ?>

                    <span
                        class="notification-badge"
                    >

                        <?php

                        echo $unreadCount > 99
                            ? "99+"
                            : $unreadCount;

                        ?>

                    </span>

                <?php endif; ?>

            </a>


            <!-- USER -->

            <div class="user-info">

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


                <div class="user-text">

                    <strong>

                        <?php

                        echo htmlspecialchars(
                            $userName
                        );

                        ?>

                    </strong>


                    <span>

                        <?php

                        echo htmlspecialchars(
                            $userRole
                        );

                        ?>

                    </span>

                </div>

            </div>

        </div>

    </div>


    <!-- ==================================================
         CONTENT
    ================================================== -->

    <main class="content">

        <!-- PAGE HEADER -->

        <div class="page-header">

            <div class="page-title">

                <h1>
                    Exams
                </h1>

                <p>
                    Manage school examinations
                    and schedules.
                </p>

            </div>


            <button
                class="btn-primary"
                onclick="openAddModal()"
                type="button"
            >

                <i
                    class="fa-solid fa-plus"
                ></i>

                Add New Exam

            </button>

        </div>


        <!-- ALERT -->

        <?php if ($message !== ""): ?>

            <div
                class="alert
                <?php echo $messageType; ?>"
            >

                <?php
                if (
                    $messageType ===
                    "success"
                ):
                ?>

                    <i
                        class="
                            fa-solid
                            fa-circle-check
                        "
                    ></i>

                <?php else: ?>

                    <i
                        class="
                            fa-solid
                            fa-circle-exclamation
                        "
                    ></i>

                <?php endif; ?>


                <span>

                    <?php

                    echo htmlspecialchars(
                        $message
                    );

                    ?>

                </span>

            </div>

        <?php endif; ?>


        <!-- ==================================================
             STATISTICS
        ================================================== -->

        <div class="stats">

            <!-- TOTAL -->

            <div class="stat-card">

                <div class="stat-info">

                    <span>
                        Total Exams
                    </span>

                    <h2>

                        <?php

                        echo number_format(
                            $totalExams
                        );

                        ?>

                    </h2>

                </div>


                <div class="stat-icon">

                    <i
                        class="
                            fa-solid
                            fa-file-lines
                        "
                    ></i>

                </div>

            </div>


            <!-- UPCOMING -->

            <div class="stat-card">

                <div class="stat-info">

                    <span>
                        Upcoming
                    </span>

                    <h2>

                        <?php

                        echo number_format(
                            $upcomingExams
                        );

                        ?>

                    </h2>

                </div>


                <div class="stat-icon">

                    <i
                        class="
                            fa-solid
                            fa-calendar-days
                        "
                    ></i>

                </div>

            </div>


            <!-- COMPLETED -->

            <div class="stat-card">

                <div class="stat-info">

                    <span>
                        Completed
                    </span>

                    <h2>

                        <?php

                        echo number_format(
                            $completedExams
                        );

                        ?>

                    </h2>

                </div>


                <div class="stat-icon">

                    <i
                        class="
                            fa-solid
                            fa-circle-check
                        "
                    ></i>

                </div>

            </div>


            <!-- WITHOUT DATE -->

            <div class="stat-card">

                <div class="stat-info">

                    <span>
                        Without Date
                    </span>

                    <h2>

                        <?php

                        echo number_format(
                            $undatedExams
                        );

                        ?>

                    </h2>

                </div>


                <div class="stat-icon">

                    <i
                        class="
                            fa-solid
                            fa-calendar-xmark
                        "
                    ></i>

                </div>

            </div>

        </div>


        <!-- ==================================================
             SEARCH & FILTER
        ================================================== -->

        <div class="filter-card">

            <form
                method="GET"
                action="exams.php"
                class="filter-form"
            >

                <!-- SEARCH -->

                <div class="input-group">

                    <i
                        class="
                            fa-solid
                            fa-magnifying-glass
                        "
                    ></i>

                    <input
                        type="text"
                        name="search"
                        class="input"
                        placeholder="
                            Search exam or class...
                        "
                        value="<?php
                        echo htmlspecialchars(
                            $search
                        );
                        ?>"
                    >

                </div>


                <!-- CLASS -->

                <select
                    name="class_id"
                    class="select"
                >

                    <option value="0">
                        All Classes
                    </option>


                    <?php
                    if (
                        $classes &&
                        $classes->num_rows > 0
                    ):
                    ?>

                        <?php

                        $classes->data_seek(0);

                        while (
                            $class =
                            $classes->fetch_assoc()
                        ):

                        ?>

                            <option
                                value="<?php
                                echo $class['id'];
                                ?>"
                                <?php

                                echo (
                                    $filterClass ==
                                    $class['id']
                                )
                                    ? 'selected'
                                    : '';

                                ?>
                            >

                                <?php

                                echo htmlspecialchars(
                                    $class['class_name']
                                );

                                if (
                                    !empty(
                                        $class['section']
                                    )
                                ) {

                                    echo " - " .
                                        htmlspecialchars(
                                            $class['section']
                                        );
                                }

                                ?>

                            </option>

                        <?php endwhile; ?>

                    <?php endif; ?>

                </select>


                <!-- FILTER -->

                <button
                    type="submit"
                    class="btn-search"
                >

                    <i
                        class="
                            fa-solid
                            fa-filter
                        "
                    ></i>

                    Filter

                </button>


                <!-- RESET -->

                <a
                    href="exams.php"
                    class="btn-reset"
                >

                    <i
                        class="
                            fa-solid
                            fa-rotate-left
                        "
                    ></i>

                    Reset

                </a>

            </form>

        </div>


        <!-- ==================================================
             EXAMS TABLE
        ================================================== -->

        <div class="table-card">

            <div class="table-header">

                <div>

                    <h3>
                        Examination List
                    </h3>

                    <span>

                        <?php

                        echo $exams->num_rows;

                        ?>

                        exam(s) found

                    </span>

                </div>

            </div>


            <div class="table-wrapper">

                <?php
                if (
                    $exams->num_rows > 0
                ):
                ?>

                    <table>

                        <thead>

                            <tr>

                                <th>
                                    #
                                </th>

                                <th>
                                    Exam Name
                                </th>

                                <th>
                                    Class
                                </th>

                                <th>
                                    Exam Date
                                </th>

                                <th>
                                    Status
                                </th>

                                <th>
                                    Created
                                </th>

                                <th>
                                    Actions
                                </th>

                            </tr>

                        </thead>


                        <tbody>

                            <?php
                            $counter = 1;
                            ?>


                            <?php

                            while (
                                $exam =
                                $exams->fetch_assoc()
                            ):

                            ?>

                                <?php

                                $examDate =
                                    $exam['exam_date'];

                                $status = "";

                                $statusClass = "";


                                if (
                                    empty($examDate)
                                ) {

                                    $status =
                                        "No Date";

                                    $statusClass =
                                        "no-date";

                                } elseif (
                                    $examDate >=
                                    date('Y-m-d')
                                ) {

                                    $status =
                                        "Upcoming";

                                    $statusClass =
                                        "upcoming";

                                } else {

                                    $status =
                                        "Completed";

                                    $statusClass =
                                        "completed";
                                }

                                ?>


                                <tr>

                                    <!-- NUMBER -->

                                    <td>

                                        <?php

                                        echo $counter++;

                                        ?>

                                    </td>


                                    <!-- EXAM NAME -->

                                    <td>

                                        <div
                                            class="exam-name"
                                        >

                                            <?php

                                            echo htmlspecialchars(
                                                $exam[
                                                    'exam_name'
                                                ]
                                            );

                                            ?>

                                        </div>

                                    </td>


                                    <!-- CLASS -->

                                    <td>

                                        <?php
                                        if (
                                            !empty(
                                                $exam[
                                                    'class_name'
                                                ]
                                            )
                                        ):
                                        ?>

                                            <span
                                                class="
                                                    class-badge
                                                "
                                            >

                                                <i
                                                    class="
                                                        fa-solid
                                                        fa-school
                                                    "
                                                ></i>

                                                <?php

                                                echo htmlspecialchars(
                                                    $exam[
                                                        'class_name'
                                                    ]
                                                );

                                                if (
                                                    !empty(
                                                        $exam[
                                                            'section'
                                                        ]
                                                    )
                                                ) {

                                                    echo " - " .
                                                        htmlspecialchars(
                                                            $exam[
                                                                'section'
                                                            ]
                                                        );
                                                }

                                                ?>

                                            </span>

                                        <?php else: ?>

                                            <span
                                                class="no-date"
                                            >

                                                No Class

                                            </span>

                                        <?php endif; ?>

                                    </td>


                                    <!-- DATE -->

                                    <td>

                                        <?php
                                        if (
                                            !empty(
                                                $examDate
                                            )
                                        ):
                                        ?>

                                            <span
                                                class="
                                                    date-badge
                                                "
                                            >

                                                <i
                                                    class="
                                                        fa-regular
                                                        fa-calendar
                                                    "
                                                ></i>

                                                <?php

                                                echo date(
                                                    'd M Y',
                                                    strtotime(
                                                        $examDate
                                                    )
                                                );

                                                ?>

                                            </span>

                                        <?php else: ?>

                                            <span
                                                class="no-date"
                                            >

                                                Not scheduled

                                            </span>

                                        <?php endif; ?>

                                    </td>


                                    <!-- STATUS -->

                                    <td>

                                        <span
                                            class="
                                                status
                                                <?php
                                                echo $statusClass;
                                                ?>
                                            "
                                        >

                                            <?php
                                            if (
                                                $status ===
                                                "Upcoming"
                                            ):
                                            ?>

                                                <i
                                                    class="
                                                        fa-solid
                                                        fa-clock
                                                    "
                                                ></i>

                                            <?php
                                            elseif (
                                                $status ===
                                                "Completed"
                                            ):
                                            ?>

                                                <i
                                                    class="
                                                        fa-solid
                                                        fa-check
                                                    "
                                                ></i>

                                            <?php else: ?>

                                                <i
                                                    class="
                                                        fa-solid
                                                        fa-minus
                                                    "
                                                ></i>

                                            <?php endif; ?>


                                            <?php

                                            echo $status;

                                            ?>

                                        </span>

                                    </td>


                                    <!-- CREATED -->

                                    <td>

                                        <?php

                                        echo date(
                                            'd M Y',
                                            strtotime(
                                                $exam[
                                                    'created_at'
                                                ]
                                            )
                                        );

                                        ?>

                                    </td>


                                    <!-- ACTIONS -->

                                    <td>

                                        <div
                                            class="actions"
                                        >

                                            <!-- EDIT -->

                                            <button
                                                class="
                                                    action-btn
                                                    edit-btn
                                                "
                                                title="Edit Exam"
                                                type="button"
                                                onclick='openEditModal(
                                                    <?php
                                                    echo json_encode([
                                                        "id" =>
                                                            $exam["id"],

                                                        "exam_name" =>
                                                            $exam[
                                                                "exam_name"
                                                            ],

                                                        "exam_date" =>
                                                            $exam[
                                                                "exam_date"
                                                            ],

                                                        "class_id" =>
                                                            $exam[
                                                                "class_id"
                                                            ]
                                                    ]);
                                                    ?>
                                                )'
                                            >

                                                <i
                                                    class="
                                                        fa-solid
                                                        fa-pen
                                                    "
                                                ></i>

                                            </button>


                                            <!-- DELETE -->

                                            <button
                                                class="
                                                    action-btn
                                                    delete-btn
                                                "
                                                title="Delete Exam"
                                                type="button"
                                                onclick="
                                                    deleteExam(
                                                        <?php
                                                        echo $exam['id'];
                                                        ?>
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

                    <div class="empty">

                        <i
                            class="
                                fa-solid
                                fa-file-circle-xmark
                            "
                        ></i>

                        <h3>
                            No Exams Found
                        </h3>

                        <p>
                            There are no examinations
                            matching your search.
                        </p>

                    </div>

                <?php endif; ?>

            </div>

        </div>

    </main>

</div>


<!-- ==================================================
     ADD EXAM MODAL
================================================== -->

<div
    class="modal"
    id="addModal"
>

    <div class="modal-content">

        <div class="modal-header">

            <h3>

                <i
                    class="fa-solid fa-plus"
                ></i>

                Add New Exam

            </h3>


            <button
                class="close"
                type="button"
                onclick="closeAddModal()"
            >

                <i
                    class="
                        fa-solid
                        fa-xmark
                    "
                ></i>

            </button>

        </div>


        <form method="POST">

            <div class="modal-body">

                <!-- EXAM NAME -->

                <div class="form-group">

                    <label>

                        Exam Name

                        <span>
                            *
                        </span>

                    </label>

                    <input
                        type="text"
                        name="exam_name"
                        class="form-control"
                        placeholder="
                            e.g. Midterm Examination
                        "
                        required
                    >

                </div>


                <!-- CLASS -->

                <div class="form-group">

                    <label>

                        Class

                        <span>
                            *
                        </span>

                    </label>

                    <select
                        name="class_id"
                        class="form-control"
                        required
                    >

                        <option value="">
                            Select Class
                        </option>


                        <?php

                        $classesAdd =
                            $conn->query("
                                SELECT
                                    id,
                                    class_name,
                                    section
                                FROM classes
                                ORDER BY
                                    class_name ASC,
                                    section ASC
                            ");

                        if ($classesAdd):

                            while (
                                $class =
                                $classesAdd->fetch_assoc()
                            ):

                        ?>

                            <option
                                value="<?php
                                echo $class['id'];
                                ?>"
                            >

                                <?php

                                echo htmlspecialchars(
                                    $class['class_name']
                                );

                                if (
                                    !empty(
                                        $class['section']
                                    )
                                ) {

                                    echo " - " .
                                        htmlspecialchars(
                                            $class['section']
                                        );
                                }

                                ?>

                            </option>

                        <?php

                            endwhile;

                        endif;

                        ?>

                    </select>

                </div>


                <!-- DATE -->

                <div class="form-group">

                    <label>
                        Exam Date
                    </label>

                    <input
                        type="date"
                        name="exam_date"
                        class="form-control"
                    >

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
                    name="add_exam"
                    class="btn-save"
                >

                    <i
                        class="
                            fa-solid
                            fa-floppy-disk
                        "
                    ></i>

                    Save Exam

                </button>

            </div>

        </form>

    </div>

</div>


<!-- ==================================================
     EDIT EXAM MODAL
================================================== -->

<div
    class="modal"
    id="editModal"
>

    <div class="modal-content">

        <div class="modal-header">

            <h3>

                <i
                    class="
                        fa-solid
                        fa-pen
                    "
                ></i>

                Edit Exam

            </h3>


            <button
                class="close"
                type="button"
                onclick="closeEditModal()"
            >

                <i
                    class="
                        fa-solid
                        fa-xmark
                    "
                ></i>

            </button>

        </div>


        <form method="POST">

            <input
                type="hidden"
                name="id"
                id="edit_id"
            >


            <div class="modal-body">

                <!-- NAME -->

                <div class="form-group">

                    <label>

                        Exam Name

                        <span>
                            *
                        </span>

                    </label>

                    <input
                        type="text"
                        name="exam_name"
                        id="edit_exam_name"
                        class="form-control"
                        required
                    >

                </div>


                <!-- CLASS -->

                <div class="form-group">

                    <label>

                        Class

                        <span>
                            *
                        </span>

                    </label>

                    <select
                        name="class_id"
                        id="edit_class_id"
                        class="form-control"
                        required
                    >

                        <option value="">
                            Select Class
                        </option>


                        <?php

                        $classesEdit =
                            $conn->query("
                                SELECT
                                    id,
                                    class_name,
                                    section
                                FROM classes
                                ORDER BY
                                    class_name ASC,
                                    section ASC
                            ");

                        if ($classesEdit):

                            while (
                                $class =
                                $classesEdit->fetch_assoc()
                            ):

                        ?>

                            <option
                                value="<?php
                                echo $class['id'];
                                ?>"
                            >

                                <?php

                                echo htmlspecialchars(
                                    $class['class_name']
                                );

                                if (
                                    !empty(
                                        $class['section']
                                    )
                                ) {

                                    echo " - " .
                                        htmlspecialchars(
                                            $class['section']
                                        );
                                }

                                ?>

                            </option>

                        <?php

                            endwhile;

                        endif;

                        ?>

                    </select>

                </div>


                <!-- DATE -->

                <div class="form-group">

                    <label>
                        Exam Date
                    </label>

                    <input
                        type="date"
                        name="exam_date"
                        id="edit_exam_date"
                        class="form-control"
                    >

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
                    name="update_exam"
                    class="btn-save"
                >

                    <i
                        class="
                            fa-solid
                            fa-floppy-disk
                        "
                    ></i>

                    Update Exam

                </button>

            </div>

        </form>

    </div>

</div>


<!-- ==================================================
     JAVASCRIPT
================================================== -->

<script>

/* ==================================================
   SIDEBAR
================================================== */

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


/* ==================================================
   ADD MODAL
================================================== */

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


/* ==================================================
   EDIT MODAL
================================================== */

const editModal =
    document.getElementById(
        "editModal"
    );


function openEditModal(exam) {

    document.getElementById(
        "edit_id"
    ).value = exam.id;


    document.getElementById(
        "edit_exam_name"
    ).value =
        exam.exam_name;


    document.getElementById(
        "edit_exam_date"
    ).value =
        exam.exam_date || "";


    document.getElementById(
        "edit_class_id"
    ).value =
        exam.class_id || "";


    editModal.classList.add(
        "active"
    );

}


function closeEditModal() {

    editModal.classList.remove(
        "active"
    );

}


/* ==================================================
   DELETE EXAM
================================================== */

function deleteExam(id) {

    const confirmed =
        confirm(
            "Are you sure you want to delete this exam?"
        );

    if (confirmed) {

        window.location.href =
            "exams.php?delete=" + id;

    }

}


/* ==================================================
   CLOSE MODAL OUTSIDE
================================================== */

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


/* ==================================================
   ESC KEY
================================================== */

document.addEventListener(
    "keydown",
    function (event) {

        if (
            event.key ===
            "Escape"
        ) {

            closeAddModal();

            closeEditModal();

        }

    }
);


/* ==================================================
   AUTO HIDE ALERT
================================================== */

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


/* ==================================================
   DARK / LIGHT MODE
================================================== */

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
            .classList.add(
                "dark-mode"
            );

        document.body
            .classList.add(
                "dark-mode"
            );


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
            .classList.remove(
                "dark-mode"
            );

        document.body
            .classList.remove(
                "dark-mode"
            );


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


/* GET SAVED THEME */

const savedTheme =
    localStorage.getItem(
        "theme"
    ) || "light";


applyTheme(
    savedTheme
);


/* THEME BUTTON */

if (themeToggle) {

    themeToggle.addEventListener(
        "click",
        function () {

            const isDark =
                document.documentElement
                    .classList.contains(
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

</script>

</body>

</html>

<?php

$stmt->close();

$conn->close();

?>