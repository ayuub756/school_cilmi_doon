<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

/* =========================
   DATABASE CONNECTION
========================= */

$conn = new mysqli(
    "localhost",
    "root",
    "",
    "student_management"
);

if ($conn->connect_error) {
    die("Database Connection Failed: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");


/* =========================
   NOTIFICATION HELPER
========================= */

require_once "notification_helper.php";


/* =========================
   USER INFORMATION
========================= */

$userName = $_SESSION['user_name'] ?? "Administrator";
$userRole = $_SESSION['user_role'] ?? "Admin";

$message = "";
$messageType = "success";


/* =========================
   UNREAD NOTIFICATION COUNT
========================= */

$unreadCount = getUnreadNotificationCount(
    $conn,
    (int) $_SESSION['user_id']
);


/* =========================
   DELETE CLASS
========================= */

if (isset($_GET['delete'])) {

    $id = intval($_GET['delete']);

    if ($id > 0) {

        $stmt = $conn->prepare(
            "DELETE FROM classes WHERE id = ?"
        );

        if ($stmt === false) {

            $message = "SQL Error: " . $conn->error;
            $messageType = "error";

        } else {

            $stmt->bind_param("i", $id);

            if ($stmt->execute()) {

                $stmt->close();

                header("Location: classes.php?msg=deleted");
                exit();

            } else {

                $message = "Unable to delete class.";
                $messageType = "error";

                $stmt->close();
            }
        }
    }
}


/* =========================
   SUCCESS MESSAGES
========================= */

if (isset($_GET['msg'])) {

    switch ($_GET['msg']) {

        case 'added':
            $message = "Class registered successfully.";
            $messageType = "success";
            break;

        case 'updated':
            $message = "Class updated successfully.";
            $messageType = "success";
            break;

        case 'deleted':
            $message = "Class deleted successfully.";
            $messageType = "success";
            break;
    }
}


/* =========================
   ADD / UPDATE CLASS
========================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';

    $className = trim($_POST['class_name'] ?? '');
    $section   = trim($_POST['section'] ?? '');
    $room      = trim($_POST['room'] ?? '');


    /* =========================
       VALIDATION
    ========================= */

    if ($className === '') {

        $message = "Class name is required.";
        $messageType = "error";

    } else {


        /* =========================
           UPDATE CLASS
        ========================= */

        if ($action === 'update') {

            $id = intval($_POST['id'] ?? 0);

            if ($id <= 0) {

                $message = "Invalid class ID.";
                $messageType = "error";

            } else {

                $stmt = $conn->prepare("
                    UPDATE classes
                    SET
                        class_name = ?,
                        section = ?,
                        room = ?
                    WHERE id = ?
                ");

                if ($stmt === false) {

                    $message = "SQL Prepare Error: " . $conn->error;
                    $messageType = "error";

                } else {

                    $stmt->bind_param(
                        "sssi",
                        $className,
                        $section,
                        $room,
                        $id
                    );

                    if ($stmt->execute()) {

                        $stmt->close();

                        header("Location: classes.php?msg=updated");
                        exit();

                    } else {

                        if ($stmt->errno == 1062) {

                            $message = "This class already exists.";

                        } else {

                            $message =
                                "Unable to update class: " .
                                $stmt->error;
                        }

                        $messageType = "error";

                        $stmt->close();
                    }
                }
            }


        /* =========================
           ADD CLASS
        ========================= */

        } else {

            $stmt = $conn->prepare("
                INSERT INTO classes
                (
                    class_name,
                    section,
                    room
                )
                VALUES (?, ?, ?)
            ");

            if ($stmt === false) {

                $message = "SQL Prepare Error: " . $conn->error;
                $messageType = "error";

            } else {

                $stmt->bind_param(
                    "sss",
                    $className,
                    $section,
                    $room
                );

                if ($stmt->execute()) {

                    /* AUTOMATIC NOTIFICATION */

                    createNotification(
                        $conn,
                        (int) $_SESSION['user_id'],
                        "New Class Registered",
                        $className . " class has been successfully registered.",
                        "success",
                        "classes.php"
                    );

                    $stmt->close();

                    header("Location: classes.php?msg=added");
                    exit();

                } else {

                    if ($stmt->errno == 1062) {

                        $message = "This class already exists.";

                    } else {

                        $message =
                            "Unable to register class: " .
                            $stmt->error;
                    }

                    $messageType = "error";

                    $stmt->close();
                }
            }
        }
    }
}


/* =========================
   EDIT CLASS
========================= */

$editClass = null;

if (isset($_GET['edit'])) {

    $editId = intval($_GET['edit']);

    if ($editId > 0) {

        $stmt = $conn->prepare("
            SELECT
                id,
                class_name,
                section,
                room
            FROM classes
            WHERE id = ?
        ");

        if ($stmt) {

            $stmt->bind_param("i", $editId);

            $stmt->execute();

            $resultEdit = $stmt->get_result();

            if ($resultEdit->num_rows === 1) {

                $editClass = $resultEdit->fetch_assoc();
            }

            $stmt->close();
        }
    }
}


/* =========================
   SEARCH CLASSES
========================= */

$search = trim($_GET['search'] ?? '');

if ($search !== '') {

    $searchTerm = "%" . $search . "%";

    $stmt = $conn->prepare("
        SELECT
            c.id,
            c.class_name,
            c.section,
            c.room,
            c.created_at,
            COUNT(s.id) AS total_students

        FROM classes c

        LEFT JOIN students s
            ON s.class_id = c.id

        WHERE
            c.class_name LIKE ?
            OR c.section LIKE ?
            OR c.room LIKE ?

        GROUP BY
            c.id,
            c.class_name,
            c.section,
            c.room,
            c.created_at

        ORDER BY c.id DESC
    ");

    $stmt->bind_param(
        "sss",
        $searchTerm,
        $searchTerm,
        $searchTerm
    );

    $stmt->execute();

    $classes = $stmt->get_result();

} else {

    $classes = $conn->query("
        SELECT
            c.id,
            c.class_name,
            c.section,
            c.room,
            c.created_at,
            COUNT(s.id) AS total_students

        FROM classes c

        LEFT JOIN students s
            ON s.class_id = c.id

        GROUP BY
            c.id,
            c.class_name,
            c.section,
            c.room,
            c.created_at

        ORDER BY c.id DESC
    ");
}


/* =========================
   TOTAL CLASSES
========================= */

$totalClasses = 0;

$totalClassesResult = $conn->query("
    SELECT COUNT(*) AS total
    FROM classes
");

if ($totalClassesResult) {

    $row = $totalClassesResult->fetch_assoc();

    $totalClasses = $row['total'];
}

?>

<!DOCTYPE html>

<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport"
      content="width=device-width, initial-scale=1.0">

<title>Classes | School Management</title>


<!-- FONT AWESOME -->

<link rel="stylesheet"
      href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">


<!-- =========================
     PREVENT DARK MODE FLASH
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


<style>

/* =========================
   RESET
========================= */

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
        background-color .25s ease,
        color .25s ease;
}

a {
    text-decoration: none;
}

button,
input {
    font-family: inherit;
}


/* =========================
   SIDEBAR
========================= */

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


/* =========================
   LOGO
========================= */

.logo {

    height: 70px;

    min-height: 70px;

    display: flex;

    align-items: center;

    gap: 12px;

    padding: 0 20px;

    border-bottom:
        1px solid rgba(255,255,255,.08);
}

.logo-icon {

    width: 45px;
    height: 45px;

    background: #2563eb;

    border-radius: 12px;

    display: flex;

    align-items: center;
    justify-content: center;

    font-size: 20px;

    flex-shrink: 0;
}

.logo-text h2 {

    font-size: 17px;
}

.logo-text span {

    font-size: 11px;

    color: #94a3b8;
}


/* =========================
   MENU
========================= */

.menu {

    list-style: none;

    padding: 0 12px;

    margin: 0;
}

.menu-title {

    color: #64748b;

    font-size: 11px;

    font-weight: bold;

    margin: 20px 8px 8px;

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


/* =========================
   LOGOUT
========================= */

.logout {

    position: static;

    margin-top: auto;

    padding: 15px 12px;

    border-top:
        1px solid rgba(255,255,255,.08);
}

.logout a {

    display: flex;

    align-items: center;

    gap: 13px;

    padding: 12px 13px;

    color: #fca5a5;

    border-radius: 9px;

    font-size: 14px;
}

.logout a:hover {

    background: #7f1d1d;

    color: white;
}


/* =========================
   MAIN
========================= */

.main {

    margin-left: 260px;

    min-height: 100vh;

    transition: .25s ease;
}


/* =========================
   TOPBAR
========================= */

.topbar {

    height: 70px;

    background: white;

    border-bottom:
        1px solid #e2e8f0;

    display: flex;

    align-items: center;

    justify-content: flex-end;

    padding: 0 30px;

    gap: 15px;

    position: sticky;

    top: 0;

    z-index: 900;

    transition:
        background-color .25s ease,
        border-color .25s ease;
}


/* =========================
   MENU BUTTON
========================= */

.menu-btn {

    display: none;

    border: 1px solid #e2e8f0;

    background: #2563eb;

    color: white;

    width: 40px;
    height: 40px;

    border-radius: 8px;

    cursor: pointer;

    font-size: 18px;

    align-items: center;
    justify-content: center;
}


/* =========================
   THEME TOGGLE
========================= */

.theme-toggle {

    width: 40px;
    height: 40px;

    border: 1px solid #e2e8f0;

    border-radius: 50%;

    background: #ffffff;

    color: #475569;

    display: flex;

    align-items: center;
    justify-content: center;

    cursor: pointer;

    font-size: 17px;

    transition: .25s;
}

.theme-toggle:hover {

    background: #f1f5f9;

    color: #2563eb;

    transform: rotate(15deg);
}


/* =========================
   NOTIFICATION
========================= */

.notification {

    width: 40px;
    height: 40px;

    border-radius: 50%;

    display: flex;

    align-items: center;
    justify-content: center;

    position: relative;

    color: #475569;

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


/* =========================
   USER
========================= */

.user-info {

    display: flex;

    align-items: center;

    gap: 10px;
}

.avatar {

    width: 38px;
    height: 38px;

    border-radius: 50%;

    background: #2563eb;

    color: white;

    display: flex;

    align-items: center;
    justify-content: center;

    font-weight: bold;

    flex-shrink: 0;
}

.user-text strong {

    display: block;

    font-size: 13px;

    color: #0f172a;
}

.user-text span {

    font-size: 11px;

    color: #64748b;
}


/* =========================
   CONTENT
========================= */

.content {

    padding: 30px;
}


/* =========================
   PAGE HEADER
========================= */

.page-header {

    display: flex;

    align-items: center;

    justify-content: space-between;

    gap: 20px;

    margin-bottom: 25px;
}

.page-header h1 {

    font-size: 27px;

    margin-bottom: 6px;
}

.page-header p {

    color: #64748b;

    font-size: 14px;
}


/* =========================
   BUTTON
========================= */

.btn {

    border: none;

    background: #2563eb;

    color: white;

    padding: 11px 17px;

    border-radius: 8px;

    cursor: pointer;

    font-size: 13px;

    display: inline-flex;

    align-items: center;

    justify-content: center;

    gap: 8px;

    transition: .2s;
}

.btn:hover {

    background: #1d4ed8;

    transform: translateY(-1px);
}


/* =========================
   ALERT
========================= */

.alert {

    padding: 13px 15px;

    border-radius: 8px;

    margin-bottom: 20px;

    font-size: 14px;
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


/* =========================
   FORM CARD
========================= */

.form-card {

    background: white;

    border:
        1px solid #e2e8f0;

    border-radius: 14px;

    padding: 25px;

    margin-bottom: 25px;

    transition:
        background-color .25s ease,
        border-color .25s ease;
}

.form-title {

    font-size: 18px;

    font-weight: bold;

    margin-bottom: 20px;
}

.form-grid {

    display: grid;

    grid-template-columns:
        repeat(3, 1fr);

    gap: 18px;
}

.form-group {

    display: flex;

    flex-direction: column;

    gap: 7px;
}

.form-group label {

    font-size: 13px;

    font-weight: 600;

    color: #334155;
}

.form-group input {

    width: 100%;

    padding: 12px;

    border:
        1px solid #cbd5e1;

    border-radius: 8px;

    outline: none;

    font-size: 13px;

    background: white;

    color: #0f172a;

    transition: .2s;
}

.form-group input:focus {

    border-color: #2563eb;

    box-shadow:
        0 0 0 3px
        rgba(37,99,235,.1);
}

.form-actions {

    margin-top: 20px;

    display: flex;

    gap: 10px;
}

.btn-secondary {

    background: #e2e8f0;

    color: #334155;
}

.btn-secondary:hover {

    background: #cbd5e1;
}


/* =========================
   TABLE CARD
========================= */

.table-card {

    background: white;

    border:
        1px solid #e2e8f0;

    border-radius: 14px;

    overflow: hidden;

    transition:
        background-color .25s ease,
        border-color .25s ease;
}

.table-top {

    padding: 20px;

    display: flex;

    align-items: center;

    justify-content: space-between;

    gap: 15px;

    border-bottom:
        1px solid #e2e8f0;
}

.table-top h3 {

    font-size: 17px;
}


/* =========================
   SEARCH
========================= */

.search-box {

    display: flex;

    gap: 8px;
}

.search-box input {

    width: 230px;

    padding: 10px 12px;

    border:
        1px solid #cbd5e1;

    border-radius: 8px;

    outline: none;

    font-size: 13px;

    background: white;

    color: #0f172a;

    transition: .2s;
}

.search-box input:focus {

    border-color: #2563eb;

    box-shadow:
        0 0 0 3px
        rgba(37,99,235,.08);
}

.search-box input::placeholder {

    color: #94a3b8;
}

.search-btn {

    border: none;

    background: #2563eb;

    color: white;

    padding: 10px 13px;

    border-radius: 8px;

    cursor: pointer;

    transition: .2s;
}

.search-btn:hover {

    background: #1d4ed8;
}


/* =========================
   TABLE
========================= */

.table-wrapper {

    overflow-x: auto;

    -webkit-overflow-scrolling: touch;
}

table {

    width: 100%;

    border-collapse: collapse;

    min-width: 750px;
}

th,
td {

    padding: 14px 18px;

    text-align: left;

    border-bottom:
        1px solid #f1f5f9;

    font-size: 13px;
}

th {

    background: #f8fafc;

    color: #475569;

    font-size: 12px;

    text-transform: uppercase;

    white-space: nowrap;
}

td {

    color: #334155;
}

tbody tr {

    transition: .15s;
}

tbody tr:hover td {

    background: #f8fafc;
}


/* =========================
   BADGES
========================= */

.class-badge {

    display: inline-block;

    background: #f1f5f9;

    color: #475569;

    padding: 5px 9px;

    border-radius: 6px;

    font-size: 12px;
}

.students-badge {

    display: inline-block;

    background: #dbeafe;

    color: #1d4ed8;

    padding: 5px 9px;

    border-radius: 6px;

    font-size: 12px;

    font-weight: 600;
}


/* =========================
   ACTIONS
========================= */

.actions {

    display: flex;

    gap: 7px;
}

.action-btn {

    width: 34px;
    height: 34px;

    border-radius: 7px;

    display: flex;

    align-items: center;
    justify-content: center;

    border: none;

    cursor: pointer;

    transition: .2s;
}

.edit-btn {

    background: #dbeafe;

    color: #2563eb;
}

.delete-btn {

    background: #fee2e2;

    color: #dc2626;
}

.action-btn:hover {

    transform: translateY(-1px);
}


/* =========================
   EMPTY
========================= */

.empty {

    text-align: center;

    padding: 45px 20px;

    color: #64748b;
}

.empty i {

    font-size: 35px;

    margin-bottom: 10px;

    color: #94a3b8;

    display: block;
}

.empty p {

    font-size: 14px;
}


/* =========================
   OVERLAY
========================= */

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


/* THEME BUTTON */

html.dark-mode .theme-toggle {

    background: #334155;

    border-color: #475569;

    color: #f8fafc;
}

html.dark-mode .theme-toggle:hover {

    background: #475569;

    color: #facc15;
}


/* MENU BUTTON */

html.dark-mode .menu-btn {

    background: #1e293b;

    border-color: #475569;

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


/* PAGE HEADER */

html.dark-mode .page-header h1 {

    color: #f8fafc;
}

html.dark-mode .page-header p {

    color: #94a3b8;
}


/* FORM */

html.dark-mode .form-card {

    background: #1e293b;

    border-color: #334155;
}

html.dark-mode .form-title {

    color: #f8fafc;
}

html.dark-mode .form-group label {

    color: #cbd5e1;
}

html.dark-mode .form-group input {

    background: #0f172a;

    color: #e2e8f0;

    border-color: #475569;
}

html.dark-mode .form-group input::placeholder {

    color: #64748b;
}

html.dark-mode .form-group input:focus {

    border-color: #3b82f6;

    box-shadow:
        0 0 0 3px
        rgba(59,130,246,.15);
}


/* SECONDARY BUTTON */

html.dark-mode .btn-secondary {

    background: #334155;

    color: #e2e8f0;
}

html.dark-mode .btn-secondary:hover {

    background: #475569;
}


/* TABLE CARD */

html.dark-mode .table-card {

    background: #1e293b;

    border-color: #334155;
}


/* TABLE HEADER */

html.dark-mode .table-top {

    border-color: #334155;
}

html.dark-mode .table-top h3 {

    color: #f8fafc;
}


/* SEARCH */

html.dark-mode .search-box input {

    background: #0f172a;

    color: #e2e8f0;

    border-color: #475569;
}

html.dark-mode .search-box input::placeholder {

    color: #64748b;
}

html.dark-mode .search-box input:focus {

    border-color: #3b82f6;
}


/* TABLE */

html.dark-mode th {

    background: #0f172a;

    color: #94a3b8;

    border-color: #334155;
}

html.dark-mode td {

    color: #cbd5e1;

    border-color: #334155;
}

html.dark-mode tbody tr:hover td {

    background: #263449;
}


/* CLASS BADGE */

html.dark-mode .class-badge {

    background: #334155;

    color: #cbd5e1;
}


/* STUDENT BADGE */

html.dark-mode .students-badge {

    background: rgba(37,99,235,.18);

    color: #60a5fa;
}


/* EMPTY */

html.dark-mode .empty {

    color: #94a3b8;
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


/* ==================================================
   RESPONSIVE
================================================== */


/* LARGE TABLET */

@media (max-width: 1000px) {

    .form-grid {

        grid-template-columns:
            repeat(2, 1fr);
    }

}


/* TABLET */

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

        align-items:
            flex-start;

        flex-direction:
            column;

        gap: 14px;
    }

    .page-header h1 {

        font-size: 23px;
    }

    .form-grid {

        grid-template-columns:
            1fr;
    }

    .form-card {

        padding: 18px;
    }

    .table-top {

        flex-direction:
            column;

        align-items:
            stretch;
    }

    .search-box {

        width: 100%;
    }

    .search-box input {

        width: 100%;
        flex: 1;
    }

    .table-wrapper {

        overflow-x: auto;
    }

}


/* MOBILE */

@media (max-width: 480px) {

    .topbar {

        height: 62px;

        padding:
            0 12px;

        gap: 7px;
    }

    .menu-btn,
    .theme-toggle,
    .notification {

        width: 37px;
        height: 37px;
    }

    .user-text {

        display: none;
    }

    .notification {

        margin-left: auto;
    }

    .content {

        padding:
            15px 12px;
    }

    .page-header h1 {

        font-size: 21px;
    }

    .page-header p {

        font-size: 12px;
    }

    .btn {

        width: 100%;

        justify-content:
            center;
    }

    .form-actions {

        flex-direction:
            column;
    }

    .form-card {

        border-radius: 12px;

        padding: 16px;
    }

    .table-card {

        border-radius: 12px;
    }

    .table-top {

        padding: 16px;
    }

}


/* VERY SMALL MOBILE */

@media (max-width: 380px) {

    .topbar {

        padding: 0 9px;

        gap: 4px;
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

    .content {

        padding: 10px;
    }

    .page-header h1 {

        font-size: 19px;
    }

    .form-card {

        padding: 14px;
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


<!-- =========================
     SIDEBAR
========================= -->

<aside class="sidebar" id="sidebar">


    <!-- LOGO -->

    <div class="logo">

        <div class="logo-icon">

            <i class="fas fa-graduation-cap"></i>

        </div>

        <div class="logo-text">

            <h2>Cilmi_Doon</h2>

            <span>
                School Management
            </span>

        </div>

    </div>


    <!-- MENU -->

    <div class="menu">


        <!-- MAIN MENU -->

        <div class="menu-title">
            Main Menu
        </div>


        <a href="dashboard.php">

            <i class="fas fa-chart-pie"></i>

            <span>
                Dashboard
            </span>

        </a>


        <a href="students.php">

            <i class="fas fa-user-graduate"></i>

            <span>
                Students
            </span>

        </a>


        <!-- ACADEMIC -->

        <div class="menu-title">
            Academic
        </div>


        <a href="teachers.php">

            <i class="fas fa-chalkboard-user"></i>

            <span>
                Teachers
            </span>

        </a>


        <!-- ACTIVE CLASSES -->

        <a href="classes.php"
           class="active">

            <i class="fas fa-school"></i>

            <span>
                Classes
            </span>

        </a>


        <a href="parents.php">

            <i class="fas fa-users"></i>

            <span>
                Parents
            </span>

        </a>


        <a href="subjects.php">

            <i class="fas fa-book"></i>

            <span>
                Subjects
            </span>

        </a>


        <a href="attendance.php">

            <i class="fas fa-calendar-check"></i>

            <span>
                Attendance
            </span>

        </a>


        <a href="exams.php">

            <i class="fas fa-file-lines"></i>

            <span>
                Examinations
            </span>

        </a>


        <a href="results.php">

            <i class="fas fa-square-poll-vertical"></i>

            <span>
                Results
            </span>

        </a>


        <!-- FINANCE -->

        <div class="menu-title">
            Finance
        </div>


        <a href="fees.php">

            <i class="fas fa-money-bill-wave"></i>

            <span>
                Fees
            </span>

        </a>


        <a href="payments.php">

            <i class="fas fa-credit-card"></i>

            <span>
                Payments
            </span>

        </a>


        <a href="salary.php">

            <i class="fas fa-wallet"></i>

            <span>
                Salary
            </span>

        </a>


        <a href="reports.php">

            <i class="fas fa-chart-pie"></i>

            <span>
                Reports
            </span>

        </a>


        <!-- SYSTEM -->

        <div class="menu-title">
            System
        </div>


        <a href="users.php">

            <i class="fas fa-user-shield"></i>

            <span>
                Users
            </span>

        </a>


    </div>


    <!-- LOGOUT -->

    <div class="logout">

        <a href="logout.php">

            <i class="fas fa-right-from-bracket"></i>

            <span>
                Logout
            </span>

        </a>

    </div>


</aside>


<!-- =========================
     OVERLAY
========================= -->

<div class="overlay"
     id="overlay">
</div>


<!-- =========================
     MAIN
========================= -->

<div class="main">


    <!-- =========================
         TOPBAR
    ========================= -->

    <div class="topbar">


        <!-- MOBILE MENU -->

        <button
            class="menu-btn"
            id="menuBtn"
            type="button"
            title="Open Menu"
        >

            <i class="fas fa-bars"></i>

        </button>


        <!-- THEME TOGGLE -->

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

            <i class="fas fa-bell"></i>

            <?php if ($unreadCount > 0): ?>

                <span class="notification-badge">

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
                    substr($userName, 0, 1)
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


    <!-- =========================
         CONTENT
    ========================= -->

    <main class="content">


        <!-- PAGE HEADER -->

        <div class="page-header">


            <div>

                <h1>
                    Classes
                </h1>

                <p>
                    Manage all classes in the school.
                </p>

            </div>


            <button
                class="btn"
                type="button"
                onclick="showForm()"
            >

                <i class="fas fa-plus"></i>

                Add Class

            </button>


        </div>


        <!-- =========================
             ALERT
        ========================= -->

        <?php if ($message !== ""): ?>

            <div class="alert
                <?php echo htmlspecialchars($messageType); ?>">

                <?php

                echo htmlspecialchars(
                    $message
                );

                ?>

            </div>

        <?php endif; ?>


        <!-- =========================
             FORM
        ========================= -->

        <div
            class="form-card"
            id="classForm"
            style="<?php

                echo $editClass
                    ? ''
                    : 'display:none;';

            ?>"
        >


            <div class="form-title">

                <?php

                echo $editClass
                    ? 'Edit Class'
                    : 'Add New Class';

                ?>

            </div>


            <form
                method="POST"
                action="classes.php"
            >


                <input
                    type="hidden"
                    name="action"
                    value="<?php

                        echo $editClass
                            ? 'update'
                            : 'add';

                    ?>"
                >


                <?php if ($editClass): ?>

                    <input
                        type="hidden"
                        name="id"
                        value="<?php
                            echo $editClass['id'];
                        ?>"
                    >

                <?php endif; ?>


                <div class="form-grid">


                    <!-- CLASS NAME -->

                    <div class="form-group">

                        <label>
                            Class Name *
                        </label>

                        <input
                            type="text"
                            name="class_name"
                            placeholder="e.g. Form 1"
                            required
                            value="<?php

                                echo htmlspecialchars(
                                    $editClass['class_name']
                                    ?? ''
                                );

                            ?>"
                        >

                    </div>


                    <!-- SECTION -->

                    <div class="form-group">

                        <label>
                            Section
                        </label>

                        <input
                            type="text"
                            name="section"
                            placeholder="e.g. A"
                            value="<?php

                                echo htmlspecialchars(
                                    $editClass['section']
                                    ?? ''
                                );

                            ?>"
                        >

                    </div>


                    <!-- ROOM -->

                    <div class="form-group">

                        <label>
                            Room
                        </label>

                        <input
                            type="text"
                            name="room"
                            placeholder="e.g. Room 101"
                            value="<?php

                                echo htmlspecialchars(
                                    $editClass['room']
                                    ?? ''
                                );

                            ?>"
                        >

                    </div>


                </div>


                <!-- FORM ACTIONS -->

                <div class="form-actions">


                    <button
                        type="submit"
                        class="btn"
                    >

                        <i class="fas fa-save"></i>

                        <?php

                        echo $editClass
                            ? 'Update Class'
                            : 'Save Class';

                        ?>

                    </button>


                    <button
                        type="button"
                        class="btn btn-secondary"
                        onclick="hideForm()"
                    >

                        Cancel

                    </button>


                </div>


            </form>


        </div>


        <!-- =========================
             TABLE
        ========================= -->

        <div class="table-card">


            <!-- TABLE TOP -->

            <div class="table-top">


                <h3>

                    All Classes
                    (<?php echo $totalClasses; ?>)

                </h3>


                <!-- SEARCH -->

                <form
                    method="GET"
                    class="search-box"
                >

                    <input
                        type="text"
                        name="search"
                        placeholder="Search class..."
                        value="<?php

                            echo htmlspecialchars(
                                $search
                            );

                        ?>"
                    >


                    <button
                        type="submit"
                        class="search-btn"
                        title="Search"
                    >

                        <i class="fas fa-search"></i>

                    </button>


                </form>


            </div>


            <!-- TABLE -->

            <div class="table-wrapper">


                <table>


                    <thead>

                        <tr>

                            <th>
                                #
                            </th>

                            <th>
                                Class Name
                            </th>

                            <th>
                                Section
                            </th>

                            <th>
                                Room
                            </th>

                            <th>
                                Students
                            </th>

                            <th>
                                Created Date
                            </th>

                            <th>
                                Actions
                            </th>

                        </tr>

                    </thead>


                    <tbody>


                    <?php if (
                        $classes &&
                        $classes->num_rows > 0
                    ): ?>


                        <?php $number = 1; ?>


                        <?php while (
                            $class =
                            $classes->fetch_assoc()
                        ): ?>


                            <tr>


                                <!-- NUMBER -->

                                <td>

                                    <?php
                                    echo $number++;
                                    ?>

                                </td>


                                <!-- CLASS NAME -->

                                <td>

                                    <strong>

                                        <?php

                                        echo htmlspecialchars(
                                            $class['class_name']
                                        );

                                        ?>

                                    </strong>

                                </td>


                                <!-- SECTION -->

                                <td>

                                    <?php if (
                                        !empty(
                                            $class['section']
                                        )
                                    ): ?>

                                        <span
                                            class="class-badge"
                                        >

                                            <?php

                                            echo htmlspecialchars(
                                                $class['section']
                                            );

                                            ?>

                                        </span>

                                    <?php else: ?>

                                        <span
                                            style="color:#94a3b8;"
                                        >
                                            —
                                        </span>

                                    <?php endif; ?>

                                </td>


                                <!-- ROOM -->

                                <td>

                                    <?php

                                    echo !empty(
                                        $class['room']
                                    )
                                    ? htmlspecialchars(
                                        $class['room']
                                    )
                                    : '—';

                                    ?>

                                </td>


                                <!-- STUDENTS -->

                                <td>

                                    <span
                                        class="students-badge"
                                    >

                                        <?php

                                        $studentCount =
                                            intval(
                                                $class[
                                                    'total_students'
                                                ]
                                            );

                                        echo $studentCount;

                                        echo $studentCount == 1
                                            ? ' Student'
                                            : ' Students';

                                        ?>

                                    </span>

                                </td>


                                <!-- CREATED DATE -->

                                <td>

                                    <?php

                                    if (
                                        !empty(
                                            $class['created_at']
                                        )
                                    ) {

                                        echo date(
                                            'd M Y',
                                            strtotime(
                                                $class['created_at']
                                            )
                                        );

                                    } else {

                                        echo '—';

                                    }

                                    ?>

                                </td>


                                <!-- ACTIONS -->

                                <td>

                                    <div class="actions">


                                        <!-- EDIT -->

                                        <a
                                            href="classes.php?edit=<?php
                                                echo $class['id'];
                                            ?>"
                                            class="action-btn edit-btn"
                                            title="Edit"
                                        >

                                            <i
                                                class="fas fa-pen"
                                            ></i>

                                        </a>


                                        <!-- DELETE -->

                                        <button
                                            type="button"
                                            class="action-btn delete-btn"
                                            title="Delete"
                                            onclick="deleteClass(
                                                <?php
                                                echo $class['id'];
                                                ?>,
                                                <?php
                                                echo htmlspecialchars(
                                                    json_encode(
                                                        $class[
                                                            'class_name'
                                                        ]
                                                    ),
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                );
                                                ?>
                                            )"
                                        >

                                            <i
                                                class="fas fa-trash"
                                            ></i>

                                        </button>


                                    </div>

                                </td>


                            </tr>


                        <?php endwhile; ?>


                    <?php else: ?>


                        <!-- EMPTY -->

                        <tr>

                            <td colspan="7">

                                <div class="empty">

                                    <i
                                        class="fas fa-school"
                                    ></i>

                                    <p>
                                        No classes found.
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


<script>

/* =========================================================
   MOBILE SIDEBAR
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

            sidebar.classList.toggle("active");

            overlay.classList.toggle("active");

        }
    );

}


if (overlay) {

    overlay.addEventListener(
        "click",
        function () {

            sidebar.classList.remove("active");

            overlay.classList.remove("active");

        }
    );

}


/* =========================================================
   SHOW FORM
========================================================= */

function showForm() {

    const form =
        document.getElementById("classForm");

    form.style.display = "block";

    form.scrollIntoView({
        behavior: "smooth",
        block: "start"
    });

}


/* =========================================================
   HIDE FORM
========================================================= */

function hideForm() {

    const form =
        document.getElementById("classForm");

    form.style.display = "none";

}


/* =========================================================
   DELETE CLASS
========================================================= */

function deleteClass(id, className) {

    const confirmDelete = confirm(
        'Are you sure you want to delete "' +
        className +
        '"?'
    );

    if (confirmDelete) {

        window.location.href =
            "classes.php?delete=" +
            encodeURIComponent(id);

    }

}


/* =========================================================
   DARK / LIGHT MODE
========================================================= */

const themeToggle =
    document.getElementById("themeToggle");

const themeIcon =
    themeToggle
        ? themeToggle.querySelector("i")
        : null;


/* APPLY THEME */

function applyTheme(theme) {

    if (theme === "dark") {

        document.documentElement
            .classList.add("dark-mode");

        document.body
            .classList.add("dark-mode");

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
            .classList.remove("dark-mode");

        document.body
            .classList.remove("dark-mode");

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
    localStorage.getItem("theme")
    || "light";

applyTheme(savedTheme);


/* TOGGLE */

if (themeToggle) {

    themeToggle.addEventListener(
        "click",
        function () {

            const isDark =
                document.documentElement
                    .classList
                    .contains("dark-mode");

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

$conn->close();

?>