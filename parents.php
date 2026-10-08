<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

require_once "config.php";
require_once "notification_helper.php";

$userName = $_SESSION['user_name'] ?? "Administrator";
$userRole = $_SESSION['user_role'] ?? "Admin";
$userId   = (int) $_SESSION['user_id'];

$message = "";
$messageType = "";

/* ==================================================
   UNREAD NOTIFICATIONS
================================================== */

$unreadCount = getUnreadNotificationCount(
    $conn,
    $userId
);


/* ==================================================
   DELETE PARENT
================================================== */

if (isset($_GET['delete'])) {

    $id = intval($_GET['delete']);

    $stmt = $conn->prepare("
        DELETE FROM parents
        WHERE id = ?
    ");

    if ($stmt) {

        $stmt->bind_param("i", $id);

        if ($stmt->execute()) {

            $message = "Parent deleted successfully.";
            $messageType = "success";

        } else {

            $message = "Unable to delete parent.";
            $messageType = "error";
        }

        $stmt->close();

    } else {

        $message = "Database error: " . $conn->error;
        $messageType = "error";
    }
}


/* ==================================================
   ADD / UPDATE PARENT
================================================== */

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $action = $_POST['action'] ?? '';

    $parent_name = trim($_POST['parent_name'] ?? '');
    $phone       = trim($_POST['phone'] ?? '');
    $email       = trim($_POST['email'] ?? '');
    $address     = trim($_POST['address'] ?? '');
    $student_id  = intval($_POST['student_id'] ?? 0);


    /* ==================================================
       ADD PARENT
    ================================================== */

    if ($action === "add") {

        if (
            $parent_name === "" ||
            $phone === "" ||
            $student_id <= 0
        ) {

            $message = "Please fill in all required fields.";
            $messageType = "error";

        } else {

            $stmt = $conn->prepare("
                INSERT INTO parents
                (
                    parent_name,
                    phone,
                    email,
                    address,
                    student_id
                )
                VALUES (?, ?, ?, ?, ?)
            ");

            if ($stmt) {

                $stmt->bind_param(
                    "ssssi",
                    $parent_name,
                    $phone,
                    $email,
                    $address,
                    $student_id
                );

                if ($stmt->execute()) {

                    /* AUTOMATIC NOTIFICATION */

                    createNotification(
                        $conn,
                        $userId,
                        "New Parent Registered",
                        $parent_name . " has been successfully registered.",
                        "success",
                        "parents.php"
                    );

                    $message = "Parent registered successfully.";
                    $messageType = "success";

                } else {

                    $message = "Error: " . $stmt->error;
                    $messageType = "error";
                }

                $stmt->close();

            } else {

                $message = "Database error: " . $conn->error;
                $messageType = "error";
            }
        }
    }


    /* ==================================================
       UPDATE PARENT
    ================================================== */

    if ($action === "update") {

        $id = intval($_POST['id'] ?? 0);

        if (
            $id <= 0 ||
            $parent_name === "" ||
            $phone === "" ||
            $student_id <= 0
        ) {

            $message = "Please fill in all required fields.";
            $messageType = "error";

        } else {

            $stmt = $conn->prepare("
                UPDATE parents
                SET
                    parent_name = ?,
                    phone = ?,
                    email = ?,
                    address = ?,
                    student_id = ?
                WHERE id = ?
            ");

            if ($stmt) {

                $stmt->bind_param(
                    "ssssii",
                    $parent_name,
                    $phone,
                    $email,
                    $address,
                    $student_id,
                    $id
                );

                if ($stmt->execute()) {

                    $message = "Parent updated successfully.";
                    $messageType = "success";

                } else {

                    $message = "Error: " . $stmt->error;
                    $messageType = "error";
                }

                $stmt->close();

            } else {

                $message = "Database error: " . $conn->error;
                $messageType = "error";
            }
        }
    }
}


/* ==================================================
   EDIT DATA
================================================== */

$editParent = null;

if (isset($_GET['edit'])) {

    $id = intval($_GET['edit']);

    $stmt = $conn->prepare("
        SELECT *
        FROM parents
        WHERE id = ?
    ");

    if ($stmt) {

        $stmt->bind_param("i", $id);

        $stmt->execute();

        $result = $stmt->get_result();

        if ($result->num_rows > 0) {

            $editParent = $result->fetch_assoc();
        }

        $stmt->close();
    }
}


/* ==================================================
   SEARCH
================================================== */

$search = trim($_GET['search'] ?? '');

if ($search !== "") {

    $searchTerm = "%" . $search . "%";

    $stmt = $conn->prepare("
        SELECT
            p.*,
            s.student_id AS student_code,
            s.full_name AS student_name,
            s.class_name
        FROM parents p
        LEFT JOIN students s
            ON p.student_id = s.id
        WHERE
            p.parent_name LIKE ?
            OR p.phone LIKE ?
            OR p.email LIKE ?
            OR p.address LIKE ?
            OR s.full_name LIKE ?
            OR s.student_id LIKE ?
        ORDER BY p.id DESC
    ");

    $stmt->bind_param(
        "ssssss",
        $searchTerm,
        $searchTerm,
        $searchTerm,
        $searchTerm,
        $searchTerm,
        $searchTerm
    );

    $stmt->execute();

    $parents = $stmt->get_result();

} else {

    $parents = $conn->query("
        SELECT
            p.*,
            s.student_id AS student_code,
            s.full_name AS student_name,
            s.class_name
        FROM parents p
        LEFT JOIN students s
            ON p.student_id = s.id
        ORDER BY p.id DESC
    ");
}


/* ==================================================
   TOTAL PARENTS
================================================== */

$totalParentsResult = $conn->query("
    SELECT COUNT(*) AS total
    FROM parents
");

$totalParents = 0;

if ($totalParentsResult) {

    $totalParents =
        $totalParentsResult->fetch_assoc()['total'];
}


/* ==================================================
   TOTAL STUDENTS
================================================== */

$studentCountResult = $conn->query("
    SELECT COUNT(*) AS total
    FROM students
");

$studentCount = 0;

if ($studentCountResult) {

    $studentCount =
        $studentCountResult->fetch_assoc()['total'];
}


/* ==================================================
   STUDENTS DROPDOWN
================================================== */

$students = $conn->query("
    SELECT
        id,
        student_id,
        full_name,
        class_name
    FROM students
    ORDER BY full_name ASC
");

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
        Parents - School Management System
    </title>


    <!-- ==================================================
         FONT AWESOME
    ================================================== -->

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
    >


    <!-- ==================================================
         EARLY THEME
    ================================================== -->

    <script>
    (function () {

        const theme = localStorage.getItem("theme");

        if (theme === "dark") {

            document.documentElement.classList.add("dark-mode");

        }

    })();
    </script>


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
                background .25s ease,
                color .25s ease;
        }


        a {
            text-decoration: none;
        }


        button,
        input,
        select,
        textarea {
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

            transition: .3s;
        }


        .sidebar::-webkit-scrollbar {
            width: 5px;
        }


        .sidebar::-webkit-scrollbar-thumb {

            background: #334155;

            border-radius: 10px;
        }


        .logo-area {

            height: 70px;

            min-height: 70px;

            display: flex;

            align-items: center;

            padding: 0 20px;

            border-bottom:
                1px solid
                rgba(255,255,255,.08);
        }


        .logo-icon {

            width: 45px;
            height: 45px;

            background: #2563eb;

            border-radius: 10px;

            display: flex;

            align-items: center;
            justify-content: center;

            margin-right: 12px;

            font-size: 20px;
        }


        .logo-text h2 {

            font-size: 17px;

            color: #fff;

            margin-bottom: 2px;
        }


        .logo-text span {

            font-size: 10px;

            color: #94a3b8;
        }


        .menu-title {

            padding:
                20px
                20px
                8px;

            color: #64748b;

            font-size: 11px;

            text-transform: uppercase;

            letter-spacing: .8px;

            font-weight: 700;
        }


        .menu {

            list-style: none;

            padding: 0 12px;

            margin: 0;
        }


        .menu li {
            margin-bottom: 4px;
        }


        .menu a {

            display: flex;

            align-items: center;

            gap: 12px;

            padding: 12px 14px;

            border-radius: 8px;

            color: #cbd5e1;

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

        .logout-link {

            position: static;

            margin-top: auto;

            padding: 15px 12px;

            border-top:
                1px solid
                rgba(255,255,255,.08);
        }


        .logout-link a {

            display: flex;

            align-items: center;

            gap: 12px;

            padding: 12px 14px;

            border-radius: 8px;

            color: #fca5a5;

            font-size: 14px;

            transition: .2s;
        }


        .logout-link a:hover {

            background: #7f1d1d;

            color: white;
        }


        /* ==================================================
           MAIN
        ================================================== */

        .main {

            margin-left: 260px;

            min-height: 100vh;
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

            justify-content: space-between;

            padding: 0 30px;

            position: sticky;

            top: 0;

            z-index: 900;

            transition: .25s;
        }


        .mobile-menu {

            display: none;

            width: 40px;

            height: 40px;

            border-radius: 8px;

            background: #f1f5f9;

            color: #0f172a;

            align-items: center;

            justify-content: center;

            font-size: 18px;

            cursor: pointer;

            transition: .2s;
        }


        .mobile-menu:hover {

            background: #e2e8f0;
        }


        /* ==================================================
           TOPBAR RIGHT
        ================================================== */

        .topbar-right {

            display: flex;

            align-items: center;

            gap: 14px;

            margin-left: auto;
        }


        /* ==================================================
           THEME BUTTON
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

            border: 2px solid white;

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

            width: 38px;

            height: 38px;

            background: #2563eb;

            color: white;

            border-radius: 50%;

            display: flex;

            align-items: center;

            justify-content: center;

            font-weight: bold;
        }


        .user-text strong {

            display: block;

            font-size: 13px;

            color: #0f172a;
        }


        .user-text small {

            color: #64748b;

            font-size: 11px;
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

            font-size: 25px;

            margin-bottom: 5px;
        }


        .page-title p {

            color: #64748b;

            font-size: 13px;
        }


        /* ==================================================
           BUTTONS
        ================================================== */

        .btn {

            border: none;

            padding: 11px 17px;

            border-radius: 8px;

            font-size: 13px;

            cursor: pointer;

            display: inline-flex;

            align-items: center;

            justify-content: center;

            gap: 8px;

            transition: .2s;
        }


        .btn-primary {

            background: #2563eb;

            color: white;
        }


        .btn-primary:hover {

            background: #1d4ed8;
        }


        .btn-secondary {

            background: #e2e8f0;

            color: #334155;
        }


        .btn-secondary:hover {

            background: #cbd5e1;
        }


        /* ==================================================
           ALERT
        ================================================== */

        .alert {

            padding: 13px 16px;

            border-radius: 8px;

            margin-bottom: 20px;

            font-size: 13px;

            display: flex;

            align-items: center;

            gap: 10px;

            transition: opacity .5s;
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
                repeat(2, 1fr);

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

            gap: 15px;

            transition: .25s;
        }


        .stat-card:hover {

            transform: translateY(-2px);

            box-shadow:
                0 8px 25px
                rgba(15,23,42,.06);
        }


        .stat-icon {

            width: 48px;

            height: 48px;

            border-radius: 10px;

            background: #dbeafe;

            color: #2563eb;

            display: flex;

            align-items: center;

            justify-content: center;

            font-size: 19px;

            flex-shrink: 0;
        }


        .stat-info span {

            display: block;

            color: #64748b;

            font-size: 12px;

            margin-bottom: 5px;
        }


        .stat-info strong {

            font-size: 23px;
        }


        /* ==================================================
           FORM CARD
        ================================================== */

        .form-card {

            background: white;

            border:
                1px solid
                #e2e8f0;

            border-radius: 14px;

            padding: 25px;

            margin-bottom: 25px;

            transition: .25s;
        }


        .form-header {

            display: flex;

            justify-content: space-between;

            align-items: center;

            margin-bottom: 20px;
        }


        .form-header h2 {

            font-size: 17px;
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


        .form-group.full {

            grid-column: 1 / -1;
        }


        .form-group label {

            font-size: 12px;

            font-weight: 600;

            color: #334155;
        }


        .required {

            color: #ef4444;
        }


        .form-group input,
        .form-group select,
        .form-group textarea {

            width: 100%;

            border:
                1px solid
                #cbd5e1;

            border-radius: 8px;

            padding: 11px 12px;

            font-size: 13px;

            outline: none;

            background: white;

            color: #0f172a;

            transition: .2s;
        }


        .form-group textarea {

            resize: vertical;

            min-height: 90px;
        }


        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {

            border-color: #2563eb;

            box-shadow:
                0 0 0 3px
                rgba(37,99,235,.1);
        }


        .form-actions {

            margin-top: 20px;

            display: flex;

            gap: 10px;

            flex-wrap: wrap;
        }


        /* ==================================================
           TABLE CARD
        ================================================== */

        .table-card {

            background: white;

            border:
                1px solid
                #e2e8f0;

            border-radius: 14px;

            overflow: hidden;

            transition: .25s;
        }


        .table-header {

            padding: 20px;

            border-bottom:
                1px solid
                #e2e8f0;

            display: flex;

            align-items: center;

            justify-content: space-between;

            gap: 20px;
        }


        .table-header h2 {

            font-size: 17px;
        }


        .search-box {

            position: relative;

            width: 280px;
        }


        .search-box i {

            position: absolute;

            left: 12px;

            top: 50%;

            transform:
                translateY(-50%);

            color: #94a3b8;

            font-size: 13px;

            pointer-events: none;
        }


        .search-box input {

            width: 100%;

            padding:
                10px
                12px
                10px
                35px;

            border:
                1px solid
                #cbd5e1;

            border-radius: 8px;

            outline: none;

            font-size: 12px;

            background: white;

            color: #0f172a;
        }


        .search-box input:focus {

            border-color: #2563eb;
        }


        .table-wrapper {

            width: 100%;

            overflow-x: auto;

            -webkit-overflow-scrolling: touch;
        }


        table {

            width: 100%;

            border-collapse: collapse;

            min-width: 850px;
        }


        th {

            background: #f8fafc;

            color: #64748b;

            font-size: 11px;

            text-transform: uppercase;

            letter-spacing: .4px;

            font-weight: 700;

            padding: 14px 18px;

            text-align: left;

            border-bottom:
                1px solid
                #e2e8f0;

            white-space: nowrap;
        }


        td {

            padding: 15px 18px;

            font-size: 12px;

            color: #334155;

            border-bottom:
                1px solid
                #f1f5f9;

            vertical-align: middle;
        }


        tbody tr {

            transition: .15s;
        }


        tbody tr:hover {

            background: #f8fafc;
        }


        .parent-name {

            font-weight: 600;

            color: #0f172a;
        }


        .student-name {

            font-weight: 600;

            color: #2563eb;
        }


        .student-class {

            display: block;

            font-size: 10px;

            color: #64748b;

            margin-top: 3px;
        }


        .badge {

            display: inline-block;

            padding: 5px 9px;

            border-radius: 20px;

            background: #dbeafe;

            color: #1d4ed8;

            font-size: 10px;

            font-weight: 600;
        }


        /* ==================================================
           ACTIONS
        ================================================== */

        .actions {

            display: flex;

            gap: 7px;
        }


        .action-btn {

            width: 32px;

            height: 32px;

            border-radius: 7px;

            border: none;

            display: flex;

            align-items: center;

            justify-content: center;

            cursor: pointer;

            font-size: 12px;

            transition: .2s;
        }


        .edit-btn {

            background: #dbeafe;

            color: #2563eb;
        }


        .edit-btn:hover {

            background: #2563eb;

            color: white;
        }


        .delete-btn {

            background: #fee2e2;

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

            padding: 45px 20px;

            color: #64748b;

            font-size: 13px;
        }


        .empty i {

            font-size: 35px;

            margin-bottom: 12px;

            color: #cbd5e1;
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


        html.dark-mode .topbar {

            background: #1e293b;

            border-color: #334155;
        }


        html.dark-mode .mobile-menu {

            background: #334155;

            color: #f8fafc;
        }


        html.dark-mode .mobile-menu:hover {

            background: #475569;
        }


        html.dark-mode .theme-toggle {

            background: #334155;

            border-color: #475569;

            color: #f8fafc;
        }


        html.dark-mode .theme-toggle:hover {

            background: #475569;

            color: #facc15;
        }


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


        html.dark-mode .user-text strong {

            color: #f8fafc;
        }


        html.dark-mode .user-text small {

            color: #94a3b8;
        }


        html.dark-mode .page-title h1 {

            color: #f8fafc;
        }


        html.dark-mode .page-title p {

            color: #94a3b8;
        }


        html.dark-mode .stat-card {

            background: #1e293b;

            border-color: #334155;
        }


        html.dark-mode .stat-card:hover {

            box-shadow:
                0 8px 25px
                rgba(0,0,0,.25);
        }


        html.dark-mode .stat-icon {

            background: rgba(37,99,235,.18);

            color: #60a5fa;
        }


        html.dark-mode .stat-info span {

            color: #94a3b8;
        }


        html.dark-mode .stat-info strong {

            color: #f8fafc;
        }


        html.dark-mode .form-card {

            background: #1e293b;

            border-color: #334155;
        }


        html.dark-mode .form-header h2 {

            color: #f8fafc;
        }


        html.dark-mode .form-group label {

            color: #cbd5e1;
        }


        html.dark-mode .form-group input,
        html.dark-mode .form-group select,
        html.dark-mode .form-group textarea {

            background: #0f172a;

            color: #e2e8f0;

            border-color: #475569;
        }


        html.dark-mode .form-group input::placeholder,
        html.dark-mode .form-group textarea::placeholder {

            color: #64748b;
        }


        html.dark-mode .form-group input:focus,
        html.dark-mode .form-group select:focus,
        html.dark-mode .form-group textarea:focus {

            border-color: #3b82f6;

            box-shadow:
                0 0 0 3px
                rgba(37,99,235,.15);
        }


        html.dark-mode select option {

            background: #0f172a;

            color: #e2e8f0;
        }


        html.dark-mode .btn-secondary {

            background: #334155;

            color: #e2e8f0;
        }


        html.dark-mode .btn-secondary:hover {

            background: #475569;
        }


        html.dark-mode .table-card {

            background: #1e293b;

            border-color: #334155;
        }


        html.dark-mode .table-header {

            border-color: #334155;
        }


        html.dark-mode .table-header h2 {

            color: #f8fafc;
        }


        html.dark-mode .search-box input {

            background: #0f172a;

            color: #e2e8f0;

            border-color: #475569;
        }


        html.dark-mode .search-box input::placeholder {

            color: #64748b;
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


        html.dark-mode .parent-name {

            color: #f8fafc;
        }


        html.dark-mode .student-name {

            color: #60a5fa;
        }


        html.dark-mode .student-class {

            color: #94a3b8;
        }


        html.dark-mode .badge {

            background: rgba(37,99,235,.18);

            color: #60a5fa;
        }


        html.dark-mode .edit-btn {

            background: rgba(37,99,235,.18);

            color: #60a5fa;
        }


        html.dark-mode .edit-btn:hover {

            background: #2563eb;

            color: white;
        }


        html.dark-mode .delete-btn {

            background: rgba(220,38,38,.18);

            color: #f87171;
        }


        html.dark-mode .delete-btn:hover {

            background: #dc2626;

            color: white;
        }


        html.dark-mode .empty {

            color: #94a3b8;
        }


        html.dark-mode .empty i {

            color: #475569;
        }


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
           RESPONSIVE 1100px
        ================================================== */

        @media (max-width: 1100px) {

            .content {

                padding: 25px;
            }


            .form-grid {

                grid-template-columns:
                    repeat(2, 1fr);
            }


            .stats {

                grid-template-columns:
                    repeat(2, 1fr);
            }
        }


        /* ==================================================
           RESPONSIVE 900px
        ================================================== */

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


            .mobile-menu {

                display: flex;
            }


            .topbar {

                justify-content: flex-start;

                gap: 10px;

                padding: 0 20px;
            }


            .topbar-right {

                margin-left: auto;

                gap: 8px;
            }


            .content {

                padding: 20px;
            }


            .overlay.active {

                display: block;
            }
        }


        /* ==================================================
           RESPONSIVE 650px
        ================================================== */

        @media (max-width: 650px) {

            .form-grid {

                grid-template-columns: 1fr;
            }


            .form-group.full {

                grid-column: auto;
            }


            .stats {

                grid-template-columns: 1fr;

                gap: 12px;
            }


            .page-header {

                align-items: flex-start;

                flex-direction: column;
            }


            .page-header .btn {

                width: 100%;
            }


            .table-header {

                flex-direction: column;

                align-items: stretch;

                padding: 16px;
            }


            .search-box {

                width: 100%;
            }


            .user-text {

                display: none;
            }


            .theme-toggle,
            .mobile-menu,
            .notification {

                width: 38px;

                height: 38px;
            }


            .form-card {

                padding: 20px;
            }


            .form-actions {

                flex-direction: column;
            }


            .form-actions .btn {

                width: 100%;
            }
        }


        /* ==================================================
           RESPONSIVE 480px
        ================================================== */

        @media (max-width: 480px) {

            .content {

                padding: 13px;
            }


            .topbar {

                padding: 0 12px;

                height: 62px;
            }


            .topbar-right {

                gap: 6px;
            }


            .theme-toggle,
            .mobile-menu,
            .notification {

                width: 36px;

                height: 36px;

                font-size: 15px;
            }


            .avatar {

                width: 36px;

                height: 36px;
            }


            .page-title h1 {

                font-size: 21px;
            }


            .page-title p {

                font-size: 12px;

                line-height: 1.5;
            }


            .stat-card {

                padding: 17px;
            }


            .stat-info strong {

                font-size: 22px;
            }


            .form-card {

                padding: 17px;

                border-radius: 12px;
            }


            .table-card {

                border-radius: 12px;
            }


            .table-header h2 {

                font-size: 15px;
            }
        }


        /* ==================================================
           VERY SMALL PHONE
        ================================================== */

        @media (max-width: 380px) {

            .content {

                padding: 10px;
            }


            .topbar {

                padding: 0 9px;
            }


            .topbar-right {

                gap: 4px;
            }


            .theme-toggle,
            .mobile-menu,
            .notification {

                width: 34px;

                height: 34px;

                font-size: 14px;
            }


            .avatar {

                width: 33px;

                height: 33px;

                font-size: 12px;
            }


            .page-title h1 {

                font-size: 19px;
            }


            .stat-card {

                padding: 14px;
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

    <div class="logo-area">

        <div class="logo-icon">

            <i class="fas fa-school"></i>

        </div>


        <div class="logo-text">

            <h2>
                Cilmi_Doon
            </h2>

            <span>
                School Management
            </span>

        </div>

    </div>


    <!-- MAIN MENU -->

    <div class="menu-title">
        Main Menu
    </div>


    <ul class="menu">

        <li>

            <a href="dashboard.php">

                <i class="fas fa-chart-pie"></i>

                <span>
                    Dashboard
                </span>

            </a>

        </li>


        <li>

            <a href="students.php">

                <i class="fas fa-user-graduate"></i>

                <span>
                    Students
                </span>

            </a>

        </li>


        <li>

            <a href="teachers.php">

                <i class="fas fa-chalkboard-teacher"></i>

                <span>
                    Teachers
                </span>

            </a>

        </li>


        <li>

            <a
                href="parents.php"
                class="active"
            >

                <i class="fas fa-users"></i>

                <span>
                    Parents
                </span>

            </a>

        </li>

    </ul>


    <!-- ACADEMIC -->

    <div class="menu-title">
        Academic
    </div>


    <ul class="menu">

        <li>

            <a href="classes.php">

                <i class="fas fa-school"></i>

                <span>
                    Classes
                </span>

            </a>

        </li>


        <li>

            <a href="subjects.php">

                <i class="fas fa-book"></i>

                <span>
                    Subjects
                </span>

            </a>

        </li>


        <li>

            <a href="attendance.php">

                <i class="fas fa-calendar-check"></i>

                <span>
                    Attendance
                </span>

            </a>

        </li>


        <li>

            <a href="exams.php">

                <i class="fas fa-file-alt"></i>

                <span>
                    Exams
                </span>

            </a>

        </li>


        <li>

            <a href="results.php">

                <i class="fas fa-chart-line"></i>

                <span>
                    Results
                </span>

            </a>

        </li>

    </ul>


    <!-- FINANCE -->

    <div class="menu-title">
        Finance
    </div>


    <ul class="menu">

        <li>

            <a href="fees.php">

                <i class="fas fa-money-bill-wave"></i>

                <span>
                    Fees
                </span>

            </a>

        </li>


        <li>

            <a href="payments.php">

                <i class="fas fa-credit-card"></i>

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

    </ul>


    <!-- SYSTEM -->

    <div class="menu-title">
        System
    </div>


    <ul class="menu">

        <li>

            <a href="users.php">

                <i class="fas fa-user-cog"></i>

                <span>
                    Users
                </span>

            </a>

        </li>


        <li>

            <a href="notifications.php">

                <i class="fas fa-bell"></i>

                <span>
                    Notifications
                </span>


                <?php if ($unreadCount > 0): ?>

                    <span
                        style="
                            margin-left:auto;
                            background:#ef4444;
                            color:white;
                            min-width:20px;
                            height:20px;
                            padding:0 5px;
                            border-radius:20px;
                            display:flex;
                            align-items:center;
                            justify-content:center;
                            font-size:10px;
                            font-weight:bold;
                        "
                    >

                        <?= $unreadCount > 99
                            ? "99+"
                            : $unreadCount ?>

                    </span>

                <?php endif; ?>

            </a>

        </li>


        <li>

            <a href="settings.php">

                <i class="fas fa-cog"></i>

                <span>
                    Settings
                </span>

            </a>

        </li>

    </ul>


    <!-- LOGOUT -->

    <div class="logout-link">

        <a
            href="logout.php"
            onclick="
                return confirm(
                    'Are you sure you want to logout?'
                );
            "
        >

            <i class="fas fa-sign-out-alt"></i>

            <span>
                Logout
            </span>

        </a>

    </div>

</aside>


<!-- ==================================================
     OVERLAY
================================================== -->

<div
    class="overlay"
    id="overlay"
></div>


<!-- ==================================================
     MAIN
================================================== -->

<div class="main">


    <!-- ==================================================
         TOPBAR
    ================================================== -->

    <header class="topbar">


        <button
            type="button"
            class="mobile-menu"
            id="mobileMenu"
            title="Open Menu"
        >

            <i class="fas fa-bars"></i>

        </button>


        <div class="topbar-right">


            <!-- THEME -->

            <button
                type="button"
                class="theme-toggle"
                id="themeToggle"
                title="Toggle Dark / Light Mode"
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

                        <?= $unreadCount > 99
                            ? "99+"
                            : $unreadCount ?>

                    </span>

                <?php endif; ?>

            </a>


            <!-- USER -->

            <div class="user-info">


                <div class="avatar">

                    <?= strtoupper(
                        substr($userName, 0, 1)
                    ) ?>

                </div>


                <div class="user-text">

                    <strong>

                        <?= htmlspecialchars(
                            $userName
                        ) ?>

                    </strong>


                    <small>

                        <?= htmlspecialchars(
                            $userRole
                        ) ?>

                    </small>

                </div>

            </div>

        </div>

    </header>


    <!-- ==================================================
         CONTENT
    ================================================== -->

    <main class="content">


        <!-- PAGE HEADER -->

        <div class="page-header">


            <div class="page-title">

                <h1>
                    Parents
                </h1>

                <p>
                    Manage student parents and guardian information.
                </p>

            </div>


            <button
                type="button"
                class="btn btn-primary"
                onclick="showForm()"
            >

                <i class="fas fa-plus"></i>

                Add Parent

            </button>

        </div>


        <!-- ==================================================
             ALERT
        ================================================== -->

        <?php if ($message !== ""): ?>

            <div
                class="alert <?= htmlspecialchars($messageType) ?>"
            >

                <?php if ($messageType === "success"): ?>

                    <i class="fas fa-circle-check"></i>

                <?php else: ?>

                    <i class="fas fa-circle-exclamation"></i>

                <?php endif; ?>


                <?= htmlspecialchars($message) ?>

            </div>

        <?php endif; ?>


        <!-- ==================================================
             STATISTICS
        ================================================== -->

        <div class="stats">


            <!-- TOTAL PARENTS -->

            <div class="stat-card">

                <div class="stat-icon">

                    <i class="fas fa-users"></i>

                </div>


                <div class="stat-info">

                    <span>
                        Total Parents
                    </span>

                    <strong>
                        <?= $totalParents ?>
                    </strong>

                </div>

            </div>


            <!-- TOTAL STUDENTS -->

            <div class="stat-card">

                <div class="stat-icon">

                    <i class="fas fa-user-graduate"></i>

                </div>


                <div class="stat-info">

                    <span>
                        Total Students
                    </span>

                    <strong>
                        <?= $studentCount ?>
                    </strong>

                </div>

            </div>

        </div>


        <!-- ==================================================
             FORM
        ================================================== -->

        <div
            class="form-card"
            id="parentForm"
            style="<?php

                echo $editParent
                    ? 'display:block;'
                    : 'display:none;';

            ?>"
        >


            <div class="form-header">

                <h2>

                    <?php if ($editParent): ?>

                        <i class="fas fa-user-pen"></i>

                        Edit Parent

                    <?php else: ?>

                        <i class="fas fa-user-plus"></i>

                        Add New Parent

                    <?php endif; ?>

                </h2>

            </div>


            <form
                method="POST"
                action="parents.php<?php

                    echo $editParent
                        ? '?edit=' . $editParent['id']
                        : '';

                ?>"
            >


                <input
                    type="hidden"
                    name="action"
                    value="<?php

                        echo $editParent
                            ? 'update'
                            : 'add';

                    ?>"
                >


                <?php if ($editParent): ?>

                    <input
                        type="hidden"
                        name="id"
                        value="<?= $editParent['id'] ?>"
                    >

                <?php endif; ?>


                <div class="form-grid">


                    <!-- PARENT NAME -->

                    <div class="form-group">

                        <label>

                            Parent Name

                            <span class="required">
                                *
                            </span>

                        </label>


                        <input
                            type="text"
                            name="parent_name"
                            placeholder="Enter parent name"
                            value="<?php

                                echo $editParent
                                    ? htmlspecialchars(
                                        $editParent['parent_name']
                                    )
                                    : '';

                            ?>"
                            required
                        >

                    </div>


                    <!-- PHONE -->

                    <div class="form-group">

                        <label>

                            Phone

                            <span class="required">
                                *
                            </span>

                        </label>


                        <input
                            type="text"
                            name="phone"
                            placeholder="Enter phone number"
                            value="<?php

                                echo $editParent
                                    ? htmlspecialchars(
                                        $editParent['phone']
                                    )
                                    : '';

                            ?>"
                            required
                        >

                    </div>


                    <!-- EMAIL -->

                    <div class="form-group">

                        <label>
                            Email
                        </label>


                        <input
                            type="email"
                            name="email"
                            placeholder="Enter email address"
                            value="<?php

                                echo $editParent
                                    ? htmlspecialchars(
                                        $editParent['email']
                                    )
                                    : '';

                            ?>"
                        >

                    </div>


                    <!-- STUDENT -->

                    <div class="form-group">

                        <label>

                            Student

                            <span class="required">
                                *
                            </span>

                        </label>


                        <select
                            name="student_id"
                            required
                        >

                            <option value="">
                                Select Student
                            </option>


                            <?php if (
                                $students &&
                                $students->num_rows > 0
                            ): ?>


                                <?php while (
                                    $student =
                                    $students->fetch_assoc()
                                ): ?>

                                    <option
                                        value="<?= $student['id'] ?>"
                                        <?php

                                        if (
                                            $editParent &&
                                            $editParent['student_id']
                                            ==
                                            $student['id']
                                        ) {

                                            echo 'selected';
                                        }

                                        ?>
                                    >

                                        <?= htmlspecialchars(
                                            $student['student_id']
                                        ) ?>

                                        -

                                        <?= htmlspecialchars(
                                            $student['full_name']
                                        ) ?>

                                        -

                                        <?= htmlspecialchars(
                                            $student['class_name']
                                        ) ?>

                                    </option>

                                <?php endwhile; ?>


                            <?php endif; ?>

                        </select>

                    </div>


                    <!-- ADDRESS -->

                    <div class="form-group full">

                        <label>
                            Address
                        </label>


                        <textarea
                            name="address"
                            placeholder="Enter parent address"
                        ><?php

                            echo $editParent
                                ? htmlspecialchars(
                                    $editParent['address']
                                )
                                : '';

                        ?></textarea>

                    </div>

                </div>


                <!-- FORM ACTIONS -->

                <div class="form-actions">


                    <button
                        type="submit"
                        class="btn btn-primary"
                    >

                        <?php if ($editParent): ?>

                            <i class="fas fa-save"></i>

                            Update Parent

                        <?php else: ?>

                            <i class="fas fa-plus"></i>

                            Register Parent

                        <?php endif; ?>

                    </button>


                    <?php if ($editParent): ?>

                        <a
                            href="parents.php"
                            class="btn btn-secondary"
                        >

                            <i class="fas fa-times"></i>

                            Cancel

                        </a>

                    <?php else: ?>

                        <button
                            type="button"
                            class="btn btn-secondary"
                            onclick="hideForm()"
                        >

                            <i class="fas fa-times"></i>

                            Cancel

                        </button>

                    <?php endif; ?>

                </div>

            </form>

        </div>


        <!-- ==================================================
             TABLE
        ================================================== -->

        <div class="table-card">


            <div class="table-header">

                <h2>
                    Parent List
                </h2>


                <form
                    method="GET"
                    action="parents.php"
                    class="search-box"
                >

                    <i class="fas fa-search"></i>


                    <input
                        type="text"
                        name="search"
                        placeholder="Search parent or student..."
                        value="<?= htmlspecialchars($search) ?>"
                    >

                </form>

            </div>


            <div class="table-wrapper">

                <table>

                    <thead>

                        <tr>

                            <th>
                                #
                            </th>

                            <th>
                                Parent
                            </th>

                            <th>
                                Phone
                            </th>

                            <th>
                                Email
                            </th>

                            <th>
                                Student
                            </th>

                            <th>
                                Address
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


                    <?php if (
                        $parents &&
                        $parents->num_rows > 0
                    ): ?>


                        <?php
                        $counter = 1;
                        ?>


                        <?php while (
                            $parent =
                            $parents->fetch_assoc()
                        ): ?>


                            <tr>


                                <!-- NUMBER -->

                                <td>

                                    <?= $counter++ ?>

                                </td>


                                <!-- PARENT -->

                                <td>

                                    <span class="parent-name">

                                        <?= htmlspecialchars(
                                            $parent['parent_name']
                                        ) ?>

                                    </span>

                                </td>


                                <!-- PHONE -->

                                <td>

                                    <?= htmlspecialchars(
                                        $parent['phone']
                                    ) ?>

                                </td>


                                <!-- EMAIL -->

                                <td>

                                    <?php if (
                                        !empty(
                                            $parent['email']
                                        )
                                    ): ?>

                                        <?= htmlspecialchars(
                                            $parent['email']
                                        ) ?>

                                    <?php else: ?>

                                        <span
                                            style="color:#94a3b8;"
                                        >
                                            N/A
                                        </span>

                                    <?php endif; ?>

                                </td>


                                <!-- STUDENT -->

                                <td>

                                    <?php if (
                                        !empty(
                                            $parent['student_name']
                                        )
                                    ): ?>

                                        <span class="student-name">

                                            <?= htmlspecialchars(
                                                $parent['student_name']
                                            ) ?>

                                        </span>


                                        <span class="student-class">

                                            ID:

                                            <?= htmlspecialchars(
                                                $parent['student_code']
                                            ) ?>

                                            •

                                            <?= htmlspecialchars(
                                                $parent['class_name']
                                            ) ?>

                                        </span>

                                    <?php else: ?>

                                        <span class="badge">
                                            No Student
                                        </span>

                                    <?php endif; ?>

                                </td>


                                <!-- ADDRESS -->

                                <td>

                                    <?php if (
                                        !empty(
                                            $parent['address']
                                        )
                                    ): ?>

                                        <?= htmlspecialchars(
                                            $parent['address']
                                        ) ?>

                                    <?php else: ?>

                                        <span
                                            style="color:#94a3b8;"
                                        >
                                            N/A
                                        </span>

                                    <?php endif; ?>

                                </td>


                                <!-- CREATED -->

                                <td>

                                    <?php

                                    echo !empty(
                                        $parent['created_at']
                                    )

                                        ? date(
                                            'M d, Y',
                                            strtotime(
                                                $parent['created_at']
                                            )
                                        )

                                        : 'N/A';

                                    ?>

                                </td>


                                <!-- ACTIONS -->

                                <td>

                                    <div class="actions">


                                        <!-- EDIT -->

                                        <a
                                            href="parents.php?edit=<?= $parent['id'] ?>"
                                            class="action-btn edit-btn"
                                            title="Edit"
                                        >

                                            <i
                                                class="fas fa-pen"
                                            ></i>

                                        </a>


                                        <!-- DELETE -->

                                        <a
                                            href="parents.php?delete=<?= $parent['id'] ?>"
                                            class="action-btn delete-btn"
                                            title="Delete"
                                            onclick="
                                                return confirm(
                                                    'Are you sure you want to delete this parent?'
                                                );
                                            "
                                        >

                                            <i
                                                class="fas fa-trash"
                                            ></i>

                                        </a>

                                    </div>

                                </td>

                            </tr>


                        <?php endwhile; ?>


                    <?php else: ?>


                        <tr>

                            <td
                                colspan="8"
                                class="empty"
                            >

                                <i
                                    class="fas fa-users"
                                ></i>

                                <br>

                                No parents found.

                            </td>

                        </tr>


                    <?php endif; ?>


                    </tbody>

                </table>

            </div>

        </div>

    </main>

</div>


<!-- ==================================================
     JAVASCRIPT
================================================== -->

<script>


/* ==================================================
   MOBILE SIDEBAR
================================================== */

const sidebar =
    document.getElementById("sidebar");

const mobileMenu =
    document.getElementById("mobileMenu");

const overlay =
    document.getElementById("overlay");


if (mobileMenu) {

    mobileMenu.addEventListener(
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


/* ==================================================
   CLOSE MOBILE MENU AFTER CLICKING LINK
================================================== */

const sidebarLinks =
    document.querySelectorAll(".sidebar a");

sidebarLinks.forEach(function (link) {

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


/* ==================================================
   SHOW FORM
================================================== */

function showForm() {

    const form =
        document.getElementById(
            "parentForm"
        );

    if (!form) {
        return;
    }

    form.style.display = "block";


    setTimeout(
        function () {

            form.scrollIntoView({

                behavior: "smooth",

                block: "start"

            });

        },
        50
    );

}


/* ==================================================
   HIDE FORM
================================================== */

function hideForm() {

    const form =
        document.getElementById(
            "parentForm"
        );

    if (!form) {
        return;
    }

    form.style.display = "none";

}


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

            alert.style.opacity = "0";


            setTimeout(
                function () {

                    alert.remove();

                },
                500
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


/* GET SAVED THEME */

const savedTheme =
    localStorage.getItem("theme")
    || "light";


applyTheme(savedTheme);


/* TOGGLE THEME */

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


/* ==================================================
   ESC KEY CLOSE SIDEBAR
================================================== */

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