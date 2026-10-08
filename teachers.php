```php
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
    die("Database connection failed: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");


/* =========================
   NOTIFICATION HELPER
========================= */

require_once "notification_helper.php";


$userName = $_SESSION['user_name'] ?? "Administrator";
$userRole = $_SESSION['user_role'] ?? "Admin";

$message = "";
$messageType = "";


/* =========================
   UNREAD NOTIFICATION COUNT
========================= */

$unreadCount = getUnreadNotificationCount(
    $conn,
    (int) $_SESSION['user_id']
);


/* =========================
   DELETE TEACHER
========================= */

if (isset($_GET['delete'])) {

    $deleteId = (int) $_GET['delete'];

    if ($deleteId > 0) {

        $stmt = $conn->prepare(
            "DELETE FROM teachers WHERE id = ?"
        );

        if ($stmt) {

            $stmt->bind_param("i", $deleteId);

            if ($stmt->execute()) {

                $stmt->close();

                header("Location: teachers.php?msg=deleted");
                exit();

            } else {

                $message = "Teacher deletion failed.";
                $messageType = "error";
            }

            $stmt->close();

        } else {

            $message = "Database query error.";
            $messageType = "error";
        }
    }
}


/* =========================
   ADD / UPDATE TEACHER
========================= */

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $action = $_POST["action"] ?? "";

    $teacherId = trim($_POST["teacher_id"] ?? "");
    $fullName  = trim($_POST["full_name"] ?? "");
    $gender    = trim($_POST["gender"] ?? "");
    $phone     = trim($_POST["phone"] ?? "");
    $email     = trim($_POST["email"] ?? "");
    $subject   = trim($_POST["subject"] ?? "");
    $address   = trim($_POST["address"] ?? "");


    /* =========================
       VALIDATION
    ========================= */

    if (
        $teacherId === "" ||
        $fullName === "" ||
        $gender === "" ||
        $phone === "" ||
        $email === "" ||
        $subject === "" ||
        $address === ""
    ) {

        $message = "Please fill in all fields.";
        $messageType = "error";

    } elseif (!in_array($gender, ["Male", "Female"], true)) {

        $message = "Please select a valid gender.";
        $messageType = "error";

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $message = "Please enter a valid email address.";
        $messageType = "error";

    } else {


        /* =========================
           UPDATE TEACHER
        ========================= */

        if ($action === "update") {

            $id = (int) ($_POST["id"] ?? 0);

            if ($id <= 0) {

                $message = "Invalid teacher ID.";
                $messageType = "error";

            } else {

                $stmt = $conn->prepare("
                    UPDATE teachers
                    SET
                        teacher_id = ?,
                        full_name = ?,
                        gender = ?,
                        phone = ?,
                        email = ?,
                        subject = ?,
                        address = ?
                    WHERE id = ?
                ");

                if ($stmt) {

                    $stmt->bind_param(
                        "sssssssi",
                        $teacherId,
                        $fullName,
                        $gender,
                        $phone,
                        $email,
                        $subject,
                        $address,
                        $id
                    );

                    if ($stmt->execute()) {

                        $stmt->close();

                        header(
                            "Location: teachers.php?msg=updated"
                        );

                        exit();

                    } else {

                        if ($stmt->errno == 1062) {

                            $message =
                                "Teacher ID already exists.";

                        } else {

                            $message =
                                "Teacher update failed.";
                        }

                        $messageType = "error";
                    }

                    $stmt->close();

                } else {

                    $message = "Database query error.";
                    $messageType = "error";
                }
            }


        /* =========================
           ADD TEACHER
        ========================= */

        } elseif ($action === "add") {

            $stmt = $conn->prepare("
                INSERT INTO teachers
                (
                    teacher_id,
                    full_name,
                    gender,
                    phone,
                    email,
                    subject,
                    address
                )
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");

            if ($stmt) {

                $stmt->bind_param(
                    "sssssss",
                    $teacherId,
                    $fullName,
                    $gender,
                    $phone,
                    $email,
                    $subject,
                    $address
                );

                if ($stmt->execute()) {


                    /* =========================
                       AUTOMATIC NOTIFICATION
                    ========================= */

                    createNotification(
                        $conn,
                        (int) $_SESSION["user_id"],
                        "New Teacher Registered",
                        $fullName . " has been successfully registered as a new teacher.",
                        "success",
                        "teachers.php"
                    );


                    $stmt->close();

                    header(
                        "Location: teachers.php?msg=added"
                    );

                    exit();

                } else {

                    if ($stmt->errno == 1062) {

                        $message =
                            "Teacher ID already exists.";

                    } else {

                        $message =
                            "Teacher registration failed.";
                    }

                    $messageType = "error";
                }

                $stmt->close();

            } else {

                $message = "Database query error.";
                $messageType = "error";
            }
        }
    }
}


/* =========================
   SUCCESS MESSAGES
========================= */

if (isset($_GET["msg"])) {

    switch ($_GET["msg"]) {

        case "added":

            $message =
                "Teacher registered successfully.";

            $messageType = "success";

            break;


        case "updated":

            $message =
                "Teacher updated successfully.";

            $messageType = "success";

            break;


        case "deleted":

            $message =
                "Teacher deleted successfully.";

            $messageType = "success";

            break;
    }
}


/* =========================
   EDIT TEACHER
========================= */

$editTeacher = null;

if (isset($_GET["edit"])) {

    $editId = (int) $_GET["edit"];

    if ($editId > 0) {

        $stmt = $conn->prepare("
            SELECT
                id,
                teacher_id,
                full_name,
                gender,
                phone,
                email,
                subject,
                address
            FROM teachers
            WHERE id = ?
        ");

        if ($stmt) {

            $stmt->bind_param(
                "i",
                $editId
            );

            $stmt->execute();

            $resultEdit =
                $stmt->get_result();

            if ($resultEdit->num_rows === 1) {

                $editTeacher =
                    $resultEdit->fetch_assoc();
            }

            $stmt->close();
        }
    }
}


/* =========================
   SEARCH
========================= */

$search = trim(
    $_GET["search"] ?? ""
);

if ($search !== "") {

    $searchTerm =
        "%" . $search . "%";

    $stmt = $conn->prepare("
        SELECT
            id,
            teacher_id,
            full_name,
            gender,
            phone,
            email,
            subject,
            address,
            created_at
        FROM teachers
        WHERE
            teacher_id LIKE ?
            OR full_name LIKE ?
            OR gender LIKE ?
            OR phone LIKE ?
            OR email LIKE ?
            OR subject LIKE ?
            OR address LIKE ?
        ORDER BY id DESC
    ");

    $stmt->bind_param(
        "sssssss",
        $searchTerm,
        $searchTerm,
        $searchTerm,
        $searchTerm,
        $searchTerm,
        $searchTerm,
        $searchTerm
    );

    $stmt->execute();

    $teachers =
        $stmt->get_result();

} else {

    $teachers = $conn->query("
        SELECT
            id,
            teacher_id,
            full_name,
            gender,
            phone,
            email,
            subject,
            address,
            created_at
        FROM teachers
        ORDER BY id DESC
    ");
}


/* =========================
   TOTAL TEACHERS
========================= */

$totalTeachers = 0;

$countResult =
    $conn->query(
        "SELECT COUNT(*) AS total FROM teachers"
    );

if ($countResult) {

    $countRow =
        $countResult->fetch_assoc();

    $totalTeachers =
        $countRow["total"];
}

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
        Teachers - School Management System
    </title>


    <!-- =========================
         APPLY THEME BEFORE PAGE LOAD
    ========================= -->

    <script>

        (function () {

            const savedTheme =
                localStorage.getItem("schoolTheme") || "light";

            document.documentElement.setAttribute(
                "data-theme",
                savedTheme
            );

        })();

    </script>


    <!-- FONT AWESOME -->

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
    >


    <style>

        /* =========================
           ROOT VARIABLES - LIGHT
        ========================= */

        :root {

            --primary: #2563eb;
            --primary-dark: #1d4ed8;

            --body-bg: #f8fafc;
            --text: #0f172a;
            --text-secondary: #64748b;

            --card-bg: #ffffff;

            --border: #e2e8f0;
            --input-border: #cbd5e1;
            --input-bg: #ffffff;

            --hover-bg: #f8fafc;

            --topbar-bg: #ffffff;

            --sidebar-bg: #0f172a;
            --sidebar-hover: #1e293b;
            --sidebar-text: #cbd5e1;

            --success-bg: #dcfce7;
            --success-text: #166534;
            --success-border: #bbf7d0;

            --error-bg: #fee2e2;
            --error-text: #991b1b;
            --error-border: #fecaca;

            --secondary-bg: #e2e8f0;
            --secondary-text: #334155;

            --search-icon: #94a3b8;

            --overlay: rgba(15,23,42,.5);

            --shadow:
                0 8px 25px rgba(15,23,42,.06);

            --focus-shadow:
                0 0 0 3px rgba(37,99,235,.12);

            --table-header: #f8fafc;

            --male-bg: #dbeafe;
            --male-text: #1d4ed8;

            --female-bg: #fce7f3;
            --female-text: #be185d;

            --edit-bg: #eff6ff;
            --edit-text: #2563eb;
            --edit-hover: #dbeafe;

            --delete-bg: #fef2f2;
            --delete-text: #dc2626;
            --delete-hover: #fee2e2;

            --mobile-menu-bg: #eff6ff;
            --mobile-menu-text: #2563eb;

            --notification-text: #475569;

            --active-green: #16a34a;

            --transition:
                .25s ease;
        }


        /* =========================
           DARK MODE
        ========================= */

        [data-theme="dark"] {

            --body-bg: #0b1120;
            --text: #f1f5f9;
            --text-secondary: #94a3b8;

            --card-bg: #111827;

            --border: #263244;
            --input-border: #334155;
            --input-bg: #0f172a;

            --hover-bg: #172033;

            --topbar-bg: #111827;

            --sidebar-bg: #020617;
            --sidebar-hover: #1e293b;
            --sidebar-text: #cbd5e1;

            --success-bg: #052e16;
            --success-text: #86efac;
            --success-border: #166534;

            --error-bg: #450a0a;
            --error-text: #fca5a5;
            --error-border: #991b1b;

            --secondary-bg: #1e293b;
            --secondary-text: #e2e8f0;

            --search-icon: #64748b;

            --overlay: rgba(0,0,0,.72);

            --shadow:
                0 10px 30px rgba(0,0,0,.28);

            --focus-shadow:
                0 0 0 3px rgba(59,130,246,.16);

            --table-header: #0f172a;

            --male-bg: #172554;
            --male-text: #93c5fd;

            --female-bg: #500724;
            --female-text: #f9a8d4;

            --edit-bg: #172554;
            --edit-text: #60a5fa;
            --edit-hover: #1e3a8a;

            --delete-bg: #450a0a;
            --delete-text: #f87171;
            --delete-hover: #7f1d1d;

            --mobile-menu-bg: #172554;
            --mobile-menu-text: #60a5fa;

            --notification-text: #cbd5e1;

            --active-green: #4ade80;
        }


        /* =========================
           GLOBAL
        ========================= */

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

            background: var(--body-bg);

            color: var(--text);

            transition:
                background-color var(--transition),
                color var(--transition);
        }


        a {
            text-decoration: none;
            color: inherit;
        }


        button,
        input,
        select,
        textarea {
            font-family: inherit;
        }


        button,
        a,
        input,
        select,
        textarea {
            transition:
                background-color var(--transition),
                color var(--transition),
                border-color var(--transition),
                box-shadow var(--transition);
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

            background: var(--sidebar-bg);

            color: white;

            z-index: 1000;

            display: flex;

            flex-direction: column;

            overflow-y: auto;

            overflow-x: hidden;
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

            border-radius: 10px;

            background: var(--primary);

            display: flex;

            align-items: center;

            justify-content: center;

            font-size: 20px;

            margin-right: 12px;
        }


        .logo-text h2 {

            font-size: 18px;

            margin-bottom: 3px;
        }


        .logo-text span {

            font-size: 11px;

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

            color: var(--sidebar-text);

            font-size: 14px;

            transition: .2s;
        }


        .menu a:hover {

            background: var(--sidebar-hover);

            color: white;
        }


        .menu a.active {

            background: var(--primary);

            color: white;
        }


        .menu a i {

            width: 20px;

            min-width: 20px;

            text-align: center;
        }


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


        /* =========================
           MAIN
        ========================= */

        .main {

            margin-left: 260px;

            min-height: 100vh;
        }


        /* =========================
           TOPBAR
        ========================= */

        .topbar {

            height: 70px;

            background: var(--topbar-bg);

            border-bottom:
                1px solid
                var(--border);

            display: flex;

            align-items: center;

            justify-content: space-between;

            padding: 0 28px;

            position: sticky;

            top: 0;

            z-index: 900;

            transition:
                background-color var(--transition),
                border-color var(--transition);
        }


        .mobile-menu {

            display: none;

            width: 40px;
            height: 40px;

            border: none;

            background: var(--mobile-menu-bg);

            color: var(--mobile-menu-text);

            border-radius: 8px;

            cursor: pointer;

            font-size: 18px;

            align-items: center;

            justify-content: center;
        }


        .mobile-menu:hover {

            transform: translateY(-1px);
        }


        .topbar-right {

            display: flex;

            align-items: center;

            gap: 12px;

            margin-left: auto;
        }


        /* =========================
           THEME BUTTON
        ========================= */

        .theme-toggle {

            width: 40px;
            height: 40px;

            border-radius: 50%;

            border: 1px solid var(--border);

            background: var(--card-bg);

            color: var(--text-secondary);

            display: flex;

            align-items: center;

            justify-content: center;

            cursor: pointer;

            font-size: 16px;
        }


        .theme-toggle:hover {

            background: var(--hover-bg);

            color: var(--primary);

            border-color: var(--primary);

            transform: rotate(10deg);
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

            color: var(--notification-text);

            font-size: 18px;

            transition: .2s;
        }


        .notification:hover {

            background: var(--hover-bg);

            color: var(--primary);
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

            border: 2px solid var(--topbar-bg);
        }


        /* =========================
           USER
        ========================= */

        .user-box {

            display: flex;

            align-items: center;

            gap: 10px;

            margin-left: 5px;
        }


        .avatar {

            width: 40px;
            height: 40px;

            border-radius: 50%;

            background: var(--primary);

            color: white;

            display: flex;

            align-items: center;

            justify-content: center;

            font-weight: bold;
        }


        .user-info strong {

            display: block;

            font-size: 13px;

            color: var(--text);
        }


        .user-info span {

            color: var(--text-secondary);

            font-size: 11px;
        }


        /* =========================
           CONTENT
        ========================= */

        .content {

            padding: 28px;
        }


        .page-header {

            display: flex;

            justify-content: space-between;

            align-items: center;

            gap: 15px;

            margin-bottom: 25px;
        }


        .page-header h1 {

            font-size: 25px;

            margin-bottom: 6px;

            color: var(--text);
        }


        .page-header p {

            color: var(--text-secondary);

            font-size: 14px;
        }


        /* =========================
           BUTTONS
        ========================= */

        .btn {

            border: none;

            cursor: pointer;

            border-radius: 8px;

            padding: 11px 16px;

            font-size: 14px;

            display: inline-flex;

            align-items: center;

            gap: 8px;

            transition: .2s;
        }


        .btn-primary {

            background: var(--primary);

            color: white;
        }


        .btn-primary:hover {

            background: var(--primary-dark);

            transform: translateY(-1px);
        }


        .btn-secondary {

            background: var(--secondary-bg);

            color: var(--secondary-text);
        }


        .btn-secondary:hover {

            opacity: .85;
        }


        .btn-edit {

            background: var(--edit-bg);

            color: var(--edit-text);

            padding: 8px 10px;
        }


        .btn-delete {

            background: var(--delete-bg);

            color: var(--delete-text);

            padding: 8px 10px;
        }


        .btn-edit:hover {

            background: var(--edit-hover);
        }


        .btn-delete:hover {

            background: var(--delete-hover);
        }


        /* =========================
           ALERT
        ========================= */

        .alert {

            padding: 13px 16px;

            border-radius: 8px;

            margin-bottom: 20px;

            font-size: 14px;

            display: flex;

            align-items: center;

            gap: 10px;
        }


        .alert-success {

            background: var(--success-bg);

            color: var(--success-text);

            border:
                1px solid
                var(--success-border);
        }


        .alert-error {

            background: var(--error-bg);

            color: var(--error-text);

            border:
                1px solid
                var(--error-border);
        }


        /* =========================
           STATS
        ========================= */

        .stats-card {

            background: var(--card-bg);

            border:
                1px solid
                var(--border);

            border-radius: 14px;

            padding: 20px;

            display: flex;

            align-items: center;

            gap: 15px;

            margin-bottom: 20px;

            box-shadow: var(--shadow);
        }


        .stats-icon {

            width: 48px;
            height: 48px;

            border-radius: 10px;

            background: var(--edit-bg);

            color: var(--edit-text);

            display: flex;

            align-items: center;

            justify-content: center;

            font-size: 20px;
        }


        .stats-card span {

            display: block;

            color: var(--text-secondary);

            font-size: 13px;

            margin-bottom: 4px;
        }


        .stats-card strong {

            font-size: 23px;

            color: var(--text);
        }


        /* =========================
           FORM
        ========================= */

        .form-card {

            background: var(--card-bg);

            border:
                1px solid
                var(--border);

            border-radius: 14px;

            padding: 24px;

            margin-bottom: 25px;

            display: none;

            box-shadow: var(--shadow);
        }


        .form-card.show {

            display: block;
        }


        .card-title {

            display: flex;

            align-items: center;

            justify-content: space-between;

            margin-bottom: 22px;

            gap: 15px;
        }


        .card-title h2 {

            font-size: 18px;

            color: var(--text);
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
        }


        .form-group.full {

            grid-column: 1 / -1;
        }


        .form-group label {

            font-size: 13px;

            font-weight: 600;

            margin-bottom: 7px;

            color: var(--text);
        }


        .form-group input,
        .form-group select,
        .form-group textarea {

            width: 100%;

            border:
                1px solid
                var(--input-border);

            border-radius: 8px;

            padding: 11px 12px;

            outline: none;

            font-size: 14px;

            color: var(--text);

            background: var(--input-bg);
        }


        .form-group input::placeholder,
        .form-group textarea::placeholder {

            color: var(--text-secondary);
        }


        .form-group textarea {

            min-height: 90px;

            resize: vertical;
        }


        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {

            border-color: var(--primary);

            box-shadow: var(--focus-shadow);
        }


        .form-actions {

            margin-top: 20px;

            display: flex;

            gap: 10px;
        }


        /* =========================
           TABLE
        ========================= */

        .table-card {

            background: var(--card-bg);

            border:
                1px solid
                var(--border);

            border-radius: 14px;

            overflow: hidden;

            box-shadow: var(--shadow);
        }


        .table-header {

            padding: 18px 20px;

            border-bottom:
                1px solid
                var(--border);

            display: flex;

            align-items: center;

            justify-content: space-between;

            gap: 15px;
        }


        .table-header h2 {

            font-size: 18px;

            color: var(--text);
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

            color: var(--search-icon);
        }


        .search-box input {

            width: 100%;

            padding:
                10px
                12px
                10px
                36px;

            border:
                1px solid
                var(--input-border);

            border-radius: 8px;

            outline: none;

            font-size: 13px;

            background: var(--input-bg);

            color: var(--text);
        }


        .search-box input::placeholder {

            color: var(--text-secondary);
        }


        .search-box input:focus {

            border-color: var(--primary);

            box-shadow: var(--focus-shadow);
        }


        .table-wrapper {

            width: 100%;

            overflow-x: auto;
        }


        table {

            width: 100%;

            border-collapse: collapse;

            min-width: 1050px;
        }


        th,
        td {

            padding: 14px 16px;

            text-align: left;

            border-bottom:
                1px solid
                var(--border);

            font-size: 13px;
        }


        th {

            background: var(--table-header);

            color: var(--text-secondary);

            font-size: 12px;

            text-transform: uppercase;

            letter-spacing: .3px;
        }


        td {

            color: var(--text-secondary);
        }


        tbody tr {

            transition:
                background-color .2s ease;
        }


        tbody tr:hover {

            background: var(--hover-bg);
        }


        .teacher-name {

            font-weight: 600;

            color: var(--text);
        }


        .teacher-id {

            color: var(--primary);

            font-weight: 600;

            font-size: 11px;
        }


        /* =========================
           BADGES
        ========================= */

        .badge {

            display: inline-block;

            padding: 5px 9px;

            border-radius: 20px;

            font-size: 11px;

            font-weight: 600;
        }


        .badge-male {

            background: var(--male-bg);

            color: var(--male-text);
        }


        .badge-female {

            background: var(--female-bg);

            color: var(--female-text);
        }


        /* =========================
           ACTIONS
        ========================= */

        .actions {

            display: flex;

            gap: 6px;
        }


        .empty {

            text-align: center;

            padding: 40px 20px;

            color: var(--text-secondary);
        }


        .empty i {

            font-size: 35px;

            margin-bottom: 10px;

            color: var(--search-icon);
        }


        /* =========================
           OVERLAY
        ========================= */

        .overlay {

            display: none;

            position: fixed;

            inset: 0;

            background: var(--overlay);

            z-index: 950;
        }


        .overlay.show {

            display: block;
        }


        /* =========================
           1000px
        ========================= */

        @media (max-width: 1000px) {

            .form-grid {

                grid-template-columns:
                    repeat(2, 1fr);
            }

        }


        /* =========================
           900px
        ========================= */

        @media (max-width: 900px) {

            .sidebar {

                left: -260px;

                transform: none;

                transition:
                    left .3s ease;
            }


            .sidebar.open {

                left: 0;
            }


            .main {

                margin-left: 0;
            }


            .mobile-menu {

                display: flex;
            }


            .topbar {

                padding: 0 18px;
            }


            .content {

                padding: 20px;
            }

        }


        /* =========================
           650px
        ========================= */

        @media (max-width: 650px) {

            .content {

                padding: 15px;
            }


            .page-header {

                align-items: flex-start;

                flex-direction: column;
            }


            .page-header .btn {

                width: 100%;

                justify-content: center;
            }


            .form-grid {

                grid-template-columns: 1fr;
            }


            .form-card {

                padding: 18px;
            }


            .table-header {

                align-items: flex-start;

                flex-direction: column;
            }


            .search-box {

                width: 100%;
            }


            .user-info {

                display: none;
            }


            .theme-toggle,
            .notification {

                width: 38px;

                height: 38px;
            }

        }


        /* =========================
           480px
        ========================= */

        @media (max-width: 480px) {

            .topbar {

                height: 62px;

                padding: 0 12px;
            }


            .logo-area {

                height: 62px;

                min-height: 62px;
            }


            .content {

                padding: 12px;
            }


            .page-header h1 {

                font-size: 21px;
            }


            .stats-card {

                padding: 16px;
            }


            .form-actions {

                flex-direction: column;
            }


            .form-actions .btn {

                width: 100%;

                justify-content: center;
            }


            .topbar-right {

                gap: 5px;
            }


            .mobile-menu {

                width: 38px;

                height: 38px;
            }


            .card-title {

                align-items: flex-start;

                flex-direction: column;
            }


            .card-title .btn {

                width: 100%;

                justify-content: center;
            }

        }

    </style>

</head>


<body>


<!-- =========================
     SIDEBAR
========================= -->

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


    <div class="menu-title">
        Main Menu
    </div>


    <ul class="menu">

        <li>
            <a href="dashboard.php">
                <i class="fas fa-chart-pie"></i>
                <span>Dashboard</span>
            </a>
        </li>


        <li>
            <a href="students.php">
                <i class="fas fa-user-graduate"></i>
                <span>Students</span>
            </a>
        </li>


        <li>
            <a href="classes.php">
                <i class="fas fa-school"></i>
                <span>Classes</span>
            </a>
        </li>

    </ul>


    <div class="menu-title">
        Academic
    </div>


    <ul class="menu">

        <li>
            <a
                href="teachers.php"
                class="active"
            >
                <i class="fas fa-chalkboard-teacher"></i>
                <span>Teachers</span>
            </a>
        </li>


        <li>
            <a href="parents.php">
                <i class="fas fa-users"></i>
                <span>Parents</span>
            </a>
        </li>


        <li>
            <a href="subjects.php">
                <i class="fas fa-book"></i>
                <span>Subjects</span>
            </a>
        </li>


        <li>
            <a href="attendance.php">
                <i class="fas fa-calendar-check"></i>
                <span>Attendance</span>
            </a>
        </li>


        <li>
            <a href="exams.php">
                <i class="fas fa-file-alt"></i>
                <span>Exams</span>
            </a>
        </li>


        <li>
            <a href="results.php">
                <i class="fas fa-chart-line"></i>
                <span>Results</span>
            </a>
        </li>

    </ul>


    <div class="menu-title">
        Finance
    </div>


    <ul class="menu">

        <li>
            <a href="fees.php">
                <i class="fas fa-money-bill-wave"></i>
                <span>Fees</span>
            </a>
        </li>


        <li>
            <a href="payments.php">
                <i class="fas fa-credit-card"></i>
                <span>Payments</span>
            </a>
        </li>


        <li>
            <a href="salary.php">
                <i class="fas fa-wallet"></i>
                <span>Salary</span>
            </a>
        </li>


        <li>
            <a href="reports.php">
                <i class="fas fa-chart-pie"></i>
                <span>Reports</span>
            </a>
        </li>

    </ul>


    <div class="menu-title">
        System
    </div>


    <ul class="menu">

        <li>
            <a href="users.php">
                <i class="fas fa-user-cog"></i>
                <span>Users</span>
            </a>
        </li>

    </ul>


    <div class="logout-link">

        <a href="logout.php">
            <i class="fas fa-sign-out-alt"></i>
            <span>Logout</span>
        </a>

    </div>

</aside>


<!-- OVERLAY -->

<div
    class="overlay"
    id="overlay"
></div>


<!-- =========================
     MAIN
========================= -->

<div class="main">


    <!-- TOPBAR -->

    <header class="topbar">

        <button
            type="button"
            class="mobile-menu"
            id="mobileMenu"
            aria-label="Open menu"
        >
            <i class="fas fa-bars"></i>
        </button>


        <div class="topbar-right">


            <!-- DARK / LIGHT MODE -->

            <button
                type="button"
                class="theme-toggle"
                id="themeToggle"
                title="Switch to Dark Mode"
                aria-label="Switch to Dark Mode"
            >

                <i
                    class="fas fa-moon"
                    id="themeIcon"
                ></i>

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
                            : $unreadCount
                        ?>

                    </span>

                <?php endif; ?>

            </a>


            <!-- USER -->

            <div class="user-box">

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

    </header>


    <!-- =========================
         CONTENT
    ========================= -->

    <main class="content">


        <div class="page-header">

            <div>

                <h1>
                    Teachers
                </h1>

                <p>
                    Manage school teachers and their information.
                </p>

            </div>


            <button
                type="button"
                class="btn btn-primary"
                onclick="showForm()"
            >

                <i class="fas fa-plus"></i>

                Add Teacher

            </button>

        </div>


        <!-- ALERT -->

        <?php if ($message !== ""): ?>

            <div
                class="alert
                <?php
                echo $messageType === "success"
                    ? "alert-success"
                    : "alert-error";
                ?>"
            >

                <i
                    class="fas
                    <?php
                    echo $messageType === "success"
                        ? "fa-circle-check"
                        : "fa-circle-exclamation";
                    ?>"
                ></i>


                <span>

                    <?php

                    echo htmlspecialchars(
                        $message
                    );

                    ?>

                </span>

            </div>

        <?php endif; ?>


        <!-- =========================
             STATISTICS
        ========================= -->

        <div class="stats-card">

            <div class="stats-icon">

                <i class="fas fa-chalkboard-teacher"></i>

            </div>


            <div>

                <span>
                    Total Teachers
                </span>

                <strong>
                    <?php echo $totalTeachers; ?>
                </strong>

            </div>

        </div>


        <!-- =========================
             FORM
        ========================= -->

        <div
            class="form-card
            <?php
            echo $editTeacher
                ? "show"
                : "";
            ?>"
            id="teacherForm"
        >


            <div class="card-title">

                <h2>

                    <?php

                    if ($editTeacher) {

                        echo '<i class="fas fa-user-pen"></i> Edit Teacher';

                    } else {

                        echo '<i class="fas fa-user-plus"></i> Add New Teacher';

                    }

                    ?>

                </h2>


                <?php if ($editTeacher): ?>

                    <a
                        href="teachers.php"
                        class="btn btn-secondary"
                    >

                        <i class="fas fa-xmark"></i>

                        Cancel

                    </a>

                <?php else: ?>

                    <button
                        type="button"
                        class="btn btn-secondary"
                        onclick="hideForm()"
                    >

                        <i class="fas fa-xmark"></i>

                        Close

                    </button>

                <?php endif; ?>

            </div>


            <form
                method="POST"
                action="teachers.php"
            >


                <?php if ($editTeacher): ?>

                    <input
                        type="hidden"
                        name="action"
                        value="update"
                    >

                    <input
                        type="hidden"
                        name="id"
                        value="<?php echo (int)$editTeacher["id"]; ?>"
                    >

                <?php else: ?>

                    <input
                        type="hidden"
                        name="action"
                        value="add"
                    >

                <?php endif; ?>


                <div class="form-grid">


                    <!-- TEACHER ID -->

                    <div class="form-group">

                        <label>
                            Teacher ID *
                        </label>

                        <input
                            type="text"
                            name="teacher_id"
                            placeholder="e.g. T001"
                            value="<?php

                            echo htmlspecialchars(
                                $editTeacher["teacher_id"]
                                ?? ""
                            );

                            ?>"
                            autocomplete="off"
                            required
                        >

                    </div>


                    <!-- FULL NAME -->

                    <div class="form-group">

                        <label>
                            Full Name *
                        </label>

                        <input
                            type="text"
                            name="full_name"
                            placeholder="Enter full name"
                            value="<?php

                            echo htmlspecialchars(
                                $editTeacher["full_name"]
                                ?? ""
                            );

                            ?>"
                            autocomplete="name"
                            required
                        >

                    </div>


                    <!-- GENDER -->

                    <div class="form-group">

                        <label>
                            Gender *
                        </label>

                        <select
                            name="gender"
                            required
                        >

                            <option value="">
                                Select Gender
                            </option>


                            <option
                                value="Male"
                                <?php

                                echo (
                                    ($editTeacher["gender"] ?? "")
                                    === "Male"
                                )
                                    ? "selected"
                                    : "";

                                ?>
                            >
                                Male
                            </option>


                            <option
                                value="Female"
                                <?php

                                echo (
                                    ($editTeacher["gender"] ?? "")
                                    === "Female"
                                )
                                    ? "selected"
                                    : "";

                                ?>
                            >
                                Female
                            </option>

                        </select>

                    </div>


                    <!-- PHONE -->

                    <div class="form-group">

                        <label>
                            Phone *
                        </label>

                        <input
                            type="text"
                            name="phone"
                            placeholder="e.g. 0612345678"
                            value="<?php

                            echo htmlspecialchars(
                                $editTeacher["phone"]
                                ?? ""
                            );

                            ?>"
                            autocomplete="tel"
                            required
                        >

                    </div>


                    <!-- EMAIL -->

                    <div class="form-group">

                        <label>
                            Email *
                        </label>

                        <input
                            type="email"
                            name="email"
                            placeholder="teacher@example.com"
                            value="<?php

                            echo htmlspecialchars(
                                $editTeacher["email"]
                                ?? ""
                            );

                            ?>"
                            autocomplete="email"
                            required
                        >

                    </div>


                    <!-- SUBJECT -->

                    <div class="form-group">

                        <label>
                            Subject *
                        </label>

                        <input
                            type="text"
                            name="subject"
                            placeholder="e.g. Mathematics"
                            value="<?php

                            echo htmlspecialchars(
                                $editTeacher["subject"]
                                ?? ""
                            );

                            ?>"
                            required
                        >

                    </div>


                    <!-- ADDRESS -->

                    <div class="form-group full">

                        <label>
                            Address *
                        </label>

                        <textarea
                            name="address"
                            placeholder="Enter teacher address"
                            autocomplete="street-address"
                            required
                        ><?php

                        echo htmlspecialchars(
                            $editTeacher["address"]
                            ?? ""
                        );

                        ?></textarea>

                    </div>

                </div>


                <!-- FORM ACTIONS -->

                <div class="form-actions">

                    <button
                        type="submit"
                        class="btn btn-primary"
                    >

                        <i
                            class="fas
                            <?php
                            echo $editTeacher
                                ? "fa-save"
                                : "fa-user-plus";
                            ?>"
                        ></i>


                        <?php

                        echo $editTeacher
                            ? "Update Teacher"
                            : "Register Teacher";

                        ?>

                    </button>


                    <?php if ($editTeacher): ?>

                        <a
                            href="teachers.php"
                            class="btn btn-secondary"
                        >
                            Cancel
                        </a>

                    <?php else: ?>

                        <button
                            type="button"
                            class="btn btn-secondary"
                            onclick="hideForm()"
                        >
                            Cancel
                        </button>

                    <?php endif; ?>

                </div>

            </form>

        </div>


        <!-- =========================
             TABLE
        ========================= -->

        <div class="table-card">


            <div class="table-header">

                <h2>
                    All Teachers
                </h2>


                <form
                    method="GET"
                    action="teachers.php"
                    class="search-box"
                >

                    <i class="fas fa-search"></i>


                    <input
                        type="text"
                        name="search"
                        placeholder="Search teachers..."
                        value="<?php

                        echo htmlspecialchars(
                            $search
                        );

                        ?>"
                    >

                </form>

            </div>


            <div class="table-wrapper">

                <table>

                    <thead>

                        <tr>

                            <th>#</th>

                            <th>Teacher</th>

                            <th>Gender</th>

                            <th>Subject</th>

                            <th>Phone</th>

                            <th>Email</th>

                            <th>Created Date</th>

                            <th>Actions</th>

                        </tr>

                    </thead>


                    <tbody>


                    <?php if (
                        $teachers &&
                        $teachers->num_rows > 0
                    ): ?>


                        <?php

                        $number = 1;

                        ?>


                        <?php while (
                            $teacher =
                            $teachers->fetch_assoc()
                        ): ?>


                            <tr>


                                <td>

                                    <?php

                                    echo $number++;

                                    ?>

                                </td>


                                <td>

                                    <div
                                        class="teacher-name"
                                    >

                                        <?php

                                        echo htmlspecialchars(
                                            $teacher["full_name"]
                                        );

                                        ?>

                                    </div>


                                    <small
                                        class="teacher-id"
                                    >

                                        ID:

                                        <?php

                                        echo htmlspecialchars(
                                            $teacher["teacher_id"]
                                        );

                                        ?>

                                    </small>

                                </td>


                                <td>

                                    <?php

                                    if (
                                        $teacher["gender"]
                                        === "Male"
                                    ):

                                    ?>

                                        <span
                                            class="
                                            badge
                                            badge-male"
                                        >
                                            Male
                                        </span>

                                    <?php else: ?>

                                        <span
                                            class="
                                            badge
                                            badge-female"
                                        >
                                            Female
                                        </span>

                                    <?php endif; ?>

                                </td>


                                <td>

                                    <?php

                                    echo htmlspecialchars(
                                        $teacher["subject"]
                                    );

                                    ?>

                                </td>


                                <td>

                                    <?php

                                    echo htmlspecialchars(
                                        $teacher["phone"]
                                    );

                                    ?>

                                </td>


                                <td>

                                    <?php

                                    echo htmlspecialchars(
                                        $teacher["email"]
                                    );

                                    ?>

                                </td>


                                <td>

                                    <?php

                                    echo !empty(
                                        $teacher["created_at"]
                                    )

                                        ? date(
                                            "d M Y",
                                            strtotime(
                                                $teacher["created_at"]
                                            )
                                        )

                                        : "-";

                                    ?>

                                </td>


                                <td>

                                    <div
                                        class="actions"
                                    >


                                        <a
                                            href="teachers.php?edit=<?php echo (int)$teacher["id"]; ?>"
                                            class="btn btn-edit"
                                            title="Edit Teacher"
                                            aria-label="Edit Teacher"
                                        >

                                            <i
                                                class="fas fa-pen"
                                            ></i>

                                        </a>


                                        <button
                                            type="button"
                                            class="btn btn-delete"
                                            title="Delete Teacher"
                                            aria-label="Delete Teacher"
                                            onclick='deleteTeacher(
                                                <?php echo (int)$teacher["id"]; ?>,
                                                <?php echo json_encode($teacher["full_name"]); ?>
                                            )'
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


                        <tr>

                            <td colspan="8">

                                <div class="empty">

                                    <i
                                        class="
                                        fas
                                        fa-chalkboard-teacher"
                                    ></i>

                                    <p>
                                        No teachers found.
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


<!-- =========================
     JAVASCRIPT
========================= -->

<script>


    /* =========================
       MOBILE SIDEBAR
    ========================= */

    const mobileMenu =
        document.getElementById(
            "mobileMenu"
        );


    const sidebar =
        document.getElementById(
            "sidebar"
        );


    const overlay =
        document.getElementById(
            "overlay"
        );


    mobileMenu.addEventListener(
        "click",
        function () {

            sidebar.classList.toggle(
                "open"
            );

            overlay.classList.toggle(
                "show"
            );

        }
    );


    overlay.addEventListener(
        "click",
        function () {

            sidebar.classList.remove(
                "open"
            );

            overlay.classList.remove(
                "show"
            );

        }
    );


    /* =========================
       DARK / LIGHT MODE
    ========================= */

    const themeToggle =
        document.getElementById(
            "themeToggle"
        );


    const themeIcon =
        document.getElementById(
            "themeIcon"
        );


    const root =
        document.documentElement;


    function updateThemeUI(theme) {

        if (theme === "dark") {

            themeIcon.className =
                "fas fa-sun";

            themeToggle.title =
                "Switch to Light Mode";

            themeToggle.setAttribute(
                "aria-label",
                "Switch to Light Mode"
            );

        } else {

            themeIcon.className =
                "fas fa-moon";

            themeToggle.title =
                "Switch to Dark Mode";

            themeToggle.setAttribute(
                "aria-label",
                "Switch to Dark Mode"
            );
        }
    }


    let currentTheme =
        localStorage.getItem(
            "schoolTheme"
        ) || "light";


    root.setAttribute(
        "data-theme",
        currentTheme
    );


    updateThemeUI(
        currentTheme
    );


    themeToggle.addEventListener(
        "click",
        function () {

            const current =
                root.getAttribute(
                    "data-theme"
                ) || "light";


            const next =
                current === "dark"
                    ? "light"
                    : "dark";


            root.setAttribute(
                "data-theme",
                next
            );


            localStorage.setItem(
                "schoolTheme",
                next
            );


            updateThemeUI(
                next
            );

        }
    );


    /* =========================
       SHOW FORM
    ========================= */

    function showForm() {

        const form =
            document.getElementById(
                "teacherForm"
            );


        form.classList.add(
            "show"
        );


        form.scrollIntoView({

            behavior: "smooth",

            block: "start"

        });

    }


    /* =========================
       HIDE FORM
    ========================= */

    function hideForm() {

        const form =
            document.getElementById(
                "teacherForm"
            );


        form.classList.remove(
            "show"
        );

    }


    /* =========================
       DELETE TEACHER
    ========================= */

    function deleteTeacher(
        id,
        name
    ) {

        const confirmed =
            confirm(
                "Are you sure you want to delete teacher: "
                + name
                + "?"
            );


        if (confirmed) {

            window.location.href =
                "teachers.php?delete="
                + encodeURIComponent(id);

        }

    }


    /* =========================
       AUTO HIDE ALERT
    ========================= */

    setTimeout(
        function () {

            const alert =
                document.querySelector(
                    ".alert"
                );


            if (alert) {

                alert.style.transition =
                    "opacity .5s";


                alert.style.opacity =
                    "0";


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


</script>


</body>

</html>

<?php

$conn->close();

?>
```