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

/* =========================
   CURRENT USER
========================= */

$userId   = intval($_SESSION['user_id']);
$userName = $_SESSION['user_name'] ?? "Administrator";
$userRole = $_SESSION['user_role'] ?? "Admin";

$message = "";
$messageType = "";

/* =========================
   GET CURRENT USER
========================= */

$stmt = $conn->prepare("
    SELECT id, name, email, role
    FROM users
    WHERE id = ?
");

$stmt->bind_param("i", $userId);
$stmt->execute();

$userResult = $stmt->get_result();
$currentUser = $userResult->fetch_assoc();

$stmt->close();

if (!$currentUser) {
    session_destroy();

    header("Location: login.php");
    exit();
}

/* =========================
   UPDATE PROFILE
========================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';

    /* =========================
       UPDATE PROFILE
    ========================= */

    if ($action === 'update_profile') {

        $name  = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');

        if ($name === '' || $email === '') {

            $message = "Name and email are required.";
            $messageType = "error";

        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

            $message = "Please enter a valid email address.";
            $messageType = "error";

        } else {

            /* Check email used by another account */

            $check = $conn->prepare("
                SELECT id
                FROM users
                WHERE email = ?
                AND id != ?
            ");

            $check->bind_param(
                "si",
                $email,
                $userId
            );

            $check->execute();

            $emailExists =
                $check->get_result()->num_rows > 0;

            $check->close();

            if ($emailExists) {

                $message =
                    "This email is already used by another account.";

                $messageType = "error";

            } else {

                $stmt = $conn->prepare("
                    UPDATE users
                    SET name = ?, email = ?
                    WHERE id = ?
                ");

                $stmt->bind_param(
                    "ssi",
                    $name,
                    $email,
                    $userId
                );

                if ($stmt->execute()) {

                    $_SESSION['user_name']  = $name;
                    $_SESSION['user_email'] = $email;

                    $userName = $name;

                    $message =
                        "Profile updated successfully.";

                    $messageType = "success";

                    /* =========================
                       CREATE NOTIFICATION
                    ========================= */

                    createNotification(
                        $conn,
                        $userId,
                        "Profile Updated",
                        "Your profile information has been successfully updated.",
                        "success",
                        "settings.php"
                    );

                } else {

                    $message =
                        "Failed to update profile.";

                    $messageType = "error";
                }

                $stmt->close();
            }
        }
    }

    /* =========================
       CHANGE PASSWORD
    ========================= */

    if ($action === 'change_password') {

        $currentPassword =
            $_POST['current_password'] ?? '';

        $newPassword =
            $_POST['new_password'] ?? '';

        $confirmPassword =
            $_POST['confirm_password'] ?? '';

        if (
            $currentPassword === '' ||
            $newPassword === '' ||
            $confirmPassword === ''
        ) {

            $message =
                "Please fill in all password fields.";

            $messageType = "error";

        } elseif (strlen($newPassword) < 6) {

            $message =
                "New password must contain at least 6 characters.";

            $messageType = "error";

        } elseif ($newPassword !== $confirmPassword) {

            $message =
                "New password and confirmation password do not match.";

            $messageType = "error";

        } else {

            /* Existing password */

            $stmt = $conn->prepare("
                SELECT password
                FROM users
                WHERE id = ?
            ");

            $stmt->bind_param(
                "i",
                $userId
            );

            $stmt->execute();

            $result = $stmt->get_result();

            $account = $result->fetch_assoc();

            $stmt->close();

            $currentHash = hash(
                "sha256",
                $currentPassword
            );

            if (
                !$account ||
                $currentHash !== $account['password']
            ) {

                $message =
                    "Current password is incorrect.";

                $messageType = "error";

            } else {

                $newHash = hash(
                    "sha256",
                    $newPassword
                );

                $stmt = $conn->prepare("
                    UPDATE users
                    SET password = ?
                    WHERE id = ?
                ");

                $stmt->bind_param(
                    "si",
                    $newHash,
                    $userId
                );

                if ($stmt->execute()) {

                    $message =
                        "Password changed successfully.";

                    $messageType = "success";

                    /* =========================
                       CREATE NOTIFICATION
                    ========================= */

                    createNotification(
                        $conn,
                        $userId,
                        "Password Changed",
                        "Your account password has been successfully changed.",
                        "success",
                        "settings.php"
                    );

                } else {

                    $message =
                        "Failed to change password.";

                    $messageType = "error";
                }

                $stmt->close();
            }
        }
    }
}

/* =========================
   REFRESH USER DATA
========================= */

$stmt = $conn->prepare("
    SELECT id, name, email, role
    FROM users
    WHERE id = ?
");

$stmt->bind_param(
    "i",
    $userId
);

$stmt->execute();

$currentUser =
    $stmt->get_result()->fetch_assoc();

$stmt->close();

$userName =
    $currentUser['name'] ?? "Administrator";

$userEmail =
    $currentUser['email'] ?? "";

$userRole =
    $currentUser['role'] ?? "Admin";

/* =========================
   UNREAD NOTIFICATIONS
========================= */

$unreadCount =
    getUnreadNotificationCount(
        $conn,
        $userId
    );

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
    Settings | School Management System
</title>

<link
    rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"
>

<style>

/* =====================================================
   GLOBAL
===================================================== */

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

:root {

    --primary: #2563eb;
    --primary-dark: #1d4ed8;

    --body-bg: #f8fafc;
    --text: #0f172a;
    --text-secondary: #64748b;

    --card-bg: #ffffff;
    --border: #e2e8f0;

    --sidebar-bg: #0f172a;
    --sidebar-text: #cbd5e1;
    --sidebar-hover: #1e293b;

    --input-bg: #ffffff;
    --input-border: #cbd5e1;

    --hover-bg: #f1f5f9;

    --success-bg: #dcfce7;
    --success-text: #166534;

    --error-bg: #fee2e2;
    --error-text: #991b1b;
}

/* =====================================================
   DARK MODE VARIABLES
===================================================== */

body.dark-mode {

    --body-bg: #0b1120;

    --text: #f1f5f9;

    --text-secondary: #94a3b8;

    --card-bg: #111827;

    --border: #263244;

    --sidebar-bg: #020617;

    --sidebar-text: #cbd5e1;

    --sidebar-hover: #1e293b;

    --input-bg: #0f172a;

    --input-border: #334155;

    --hover-bg: #1e293b;

    --success-bg: #052e16;

    --success-text: #86efac;

    --error-bg: #450a0a;

    --error-text: #fca5a5;
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
        background-color .3s ease,
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


/* =====================================================
   SIDEBAR
===================================================== */

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

    transition:
        transform .3s ease,
        background-color .3s ease;
}


/* =====================================================
   LOGO
===================================================== */

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

    border-radius: 10px;

    background: var(--primary);

    display: flex;

    align-items: center;
    justify-content: center;

    font-size: 20px;
}

.logo-text h2 {
    font-size: 17px;
}

.logo-text span {

    font-size: 11px;

    color: #94a3b8;
}


/* =====================================================
   MENU
===================================================== */

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

    color: var(--sidebar-text);

    border-radius: 9px;

    margin-bottom: 4px;

    font-size: 14px;

    transition:
        background .2s,
        color .2s;
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


/* =====================================================
   LOGOUT
===================================================== */

.logout {

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


/* =====================================================
   MAIN
===================================================== */

.main {

    margin-left: 260px;

    min-height: 100vh;
}


/* =====================================================
   TOPBAR
===================================================== */

.topbar {

    height: 70px;

    background: var(--card-bg);

    border-bottom:
        1px solid var(--border);

    display: flex;

    align-items: center;

    justify-content: space-between;

    padding: 0 30px;

    position: sticky;

    top: 0;

    z-index: 900;

    transition:
        background .3s,
        border-color .3s;
}


/* =====================================================
   MOBILE MENU BUTTON
===================================================== */

.menu-btn {

    display: none;

    border: none;

    background: transparent;

    font-size: 23px;

    color: var(--text);

    cursor: pointer;
}


/* =====================================================
   TOPBAR RIGHT
===================================================== */

.topbar-right {

    margin-left: auto;

    display: flex;

    align-items: center;

    gap: 22px;
}


/* =====================================================
   DARK MODE BUTTON
===================================================== */

.theme-toggle {

    width: 40px;
    height: 40px;

    border-radius: 50%;

    border: none;

    background: transparent;

    color: var(--text-secondary);

    display: flex;

    align-items: center;

    justify-content: center;

    font-size: 18px;

    cursor: pointer;

    transition:
        background .2s,
        color .2s,
        transform .2s;
}

.theme-toggle:hover {

    background: var(--hover-bg);

    color: var(--primary);

    transform: rotate(15deg);
}


/* =====================================================
   NOTIFICATION
===================================================== */

.notification {

    width: 40px;
    height: 40px;

    border-radius: 50%;

    display: flex;

    align-items: center;
    justify-content: center;

    position: relative;

    color: var(--text-secondary);

    font-size: 19px;

    cursor: pointer;

    text-decoration: none;

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

    border: 2px solid var(--card-bg);

    line-height: 1;
}


/* =====================================================
   USER
===================================================== */

.user {

    display: flex;

    align-items: center;

    gap: 10px;
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

    font-size: 14px;
}

.user-info span {

    color: var(--text-secondary);

    font-size: 12px;
}


/* =====================================================
   CONTENT
===================================================== */

.content {

    padding: 30px;
}

.page-header {

    margin-bottom: 25px;
}

.page-header h1 {

    font-size: 27px;

    margin-bottom: 6px;
}

.page-header p {

    color: var(--text-secondary);

    font-size: 14px;
}


/* =====================================================
   ALERT
===================================================== */

.alert {

    padding: 13px 16px;

    border-radius: 9px;

    margin-bottom: 20px;

    font-size: 14px;

    display: flex;

    align-items: center;

    gap: 8px;
}

.alert.success {

    background: var(--success-bg);

    color: var(--success-text);

    border:
        1px solid #86efac;
}

.alert.error {

    background: var(--error-bg);

    color: var(--error-text);

    border:
        1px solid #fca5a5;
}


/* =====================================================
   SETTINGS GRID
===================================================== */

.settings-grid {

    display: grid;

    grid-template-columns: 230px 1fr;

    gap: 22px;
}


/* =====================================================
   SETTINGS MENU
===================================================== */

.settings-menu {

    background: var(--card-bg);

    border:
        1px solid var(--border);

    border-radius: 14px;

    padding: 10px;

    height: fit-content;

    transition:
        background .3s,
        border-color .3s;
}

.settings-menu button {

    width: 100%;

    border: none;

    background: transparent;

    padding: 13px;

    border-radius: 8px;

    text-align: left;

    cursor: pointer;

    color: var(--text-secondary);

    font-size: 14px;

    display: flex;

    align-items: center;

    gap: 12px;

    margin-bottom: 4px;

    transition: .2s;
}

.settings-menu button:hover {

    background: var(--hover-bg);

    color: var(--text);
}

.settings-menu button.active {

    background: #dbeafe;

    color: var(--primary);

    font-weight: bold;
}

body.dark-mode
.settings-menu button.active {

    background: #172554;

    color: #60a5fa;
}

.settings-menu i {

    width: 20px;

    text-align: center;
}


/* =====================================================
   SETTINGS CONTENT
===================================================== */

.settings-content {

    min-width: 0;
}

.settings-card {

    display: none;

    background: var(--card-bg);

    border:
        1px solid var(--border);

    border-radius: 14px;

    overflow: hidden;

    transition:
        background .3s,
        border-color .3s;
}

.settings-card.active {

    display: block;
}


/* =====================================================
   SETTINGS HEADER
===================================================== */

.settings-header {

    padding: 22px;

    border-bottom:
        1px solid var(--border);
}

.settings-header h2 {

    font-size: 19px;

    margin-bottom: 5px;
}

.settings-header p {

    font-size: 13px;

    color: var(--text-secondary);
}


/* =====================================================
   SETTINGS BODY
===================================================== */

.settings-body {

    padding: 22px;
}


/* =====================================================
   FORM
===================================================== */

.form-grid {

    display: grid;

    grid-template-columns:
        repeat(2, 1fr);

    gap: 20px;
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

    font-size: 13px;

    font-weight: bold;

    color: var(--text);
}

.form-group input,
.form-group select {

    width: 100%;

    height: 44px;

    border:
        1px solid var(--input-border);

    border-radius: 8px;

    padding: 0 12px;

    outline: none;

    font-size: 14px;

    color: var(--text);

    background: var(--input-bg);

    transition:
        background .3s,
        color .3s,
        border-color .2s;
}

.form-group input:focus,
.form-group select:focus {

    border-color: var(--primary);

    box-shadow:
        0 0 0 3px rgba(37,99,235,.10);
}

.form-group input:disabled {

    opacity: .65;

    cursor: not-allowed;
}

.form-help {

    font-size: 11px;

    color: var(--text-secondary);
}


/* =====================================================
   PASSWORD
===================================================== */

.password-field {

    position: relative;
}

.password-field input {

    padding-right: 45px;
}

.password-toggle {

    position: absolute;

    right: 12px;

    top: 50%;

    transform:
        translateY(-50%);

    border: none;

    background: transparent;

    cursor: pointer;

    color: var(--text-secondary);
}

.password-toggle:hover {

    color: var(--primary);
}


/* =====================================================
   FORM BUTTON
===================================================== */

.form-actions {

    margin-top: 22px;

    display: flex;

    justify-content: flex-end;
}

.btn-primary {

    border: none;

    background: var(--primary);

    color: white;

    padding: 12px 18px;

    border-radius: 9px;

    font-weight: bold;

    cursor: pointer;

    display: flex;

    align-items: center;

    gap: 8px;

    transition: .2s;
}

.btn-primary:hover {

    background: var(--primary-dark);

    transform:
        translateY(-1px);
}


/* =====================================================
   SCHOOL INFORMATION
===================================================== */

.info-list {

    display: grid;

    gap: 0;
}

.info-item {

    display: flex;

    align-items: center;

    justify-content: space-between;

    gap: 20px;

    padding: 17px 0;

    border-bottom:
        1px solid var(--border);
}

.info-item:last-child {

    border-bottom: none;
}

.info-label {

    color: var(--text-secondary);

    font-size: 13px;
}

.info-value {

    font-weight: bold;

    font-size: 14px;

    text-align: right;

    color: var(--text);
}


/* =====================================================
   NOTIFICATION SETTINGS
===================================================== */

.setting-row {

    display: flex;

    align-items: center;

    justify-content: space-between;

    gap: 20px;

    padding: 18px 0;

    border-bottom:
        1px solid var(--border);
}

.setting-row:last-child {

    border-bottom: none;
}

.setting-row h4 {

    font-size: 14px;

    margin-bottom: 4px;
}

.setting-row p {

    color: var(--text-secondary);

    font-size: 12px;
}


/* =====================================================
   SWITCH
===================================================== */

.switch {

    position: relative;

    width: 45px;
    height: 24px;

    flex-shrink: 0;
}

.switch input {

    opacity: 0;

    width: 0;
    height: 0;
}

.slider {

    position: absolute;

    inset: 0;

    background: #cbd5e1;

    border-radius: 20px;

    cursor: pointer;

    transition: .2s;
}

.slider:before {

    content: "";

    position: absolute;

    width: 18px;
    height: 18px;

    left: 3px;
    top: 3px;

    background: white;

    border-radius: 50%;

    transition: .2s;
}

.switch input:checked + .slider {

    background: var(--primary);
}

.switch input:checked + .slider:before {

    transform:
        translateX(21px);
}


/* =====================================================
   OVERLAY
===================================================== */

.overlay {

    display: none;

    position: fixed;

    inset: 0;

    background:
        rgba(0,0,0,.45);

    z-index: 999;
}

.overlay.active {

    display: block;
}


/* =====================================================
   RESPONSIVE 900px
===================================================== */

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

    .menu-btn {

        display: flex;

        align-items: center;

        justify-content: center;
    }

    .topbar {

        padding: 0 20px;
    }

    .settings-grid {

        grid-template-columns: 1fr;
    }

    .settings-menu {

        display: grid;

        grid-template-columns:
            repeat(2, 1fr);

        gap: 5px;
    }

}


/* =====================================================
   RESPONSIVE 650px
===================================================== */

@media (max-width: 650px) {

    .content {

        padding:
            20px 15px;
    }

    .page-header h1 {

        font-size: 23px;
    }

    .form-grid {

        grid-template-columns: 1fr;
    }

    .form-group.full {

        grid-column: auto;
    }

    .settings-menu {

        grid-template-columns: 1fr;
    }

    .user-info {

        display: none;
    }

    .topbar-right {

        gap: 10px;
    }

}


/* =====================================================
   RESPONSIVE 480px
===================================================== */

@media (max-width: 480px) {

    .topbar {

        padding:
            0 15px;
    }

    .content {

        padding:
            18px 12px;
    }

    .settings-header,
    .settings-body {

        padding: 17px;
    }

    .info-item {

        align-items: flex-start;

        flex-direction: column;

        gap: 5px;
    }

    .info-value {

        text-align: left;
    }

    .theme-toggle,
    .notification {

        width: 36px;
        height: 36px;
    }

    .page-header h1 {

        font-size: 21px;
    }

    .settings-header h2 {

        font-size: 17px;
    }

    .btn-primary {

        width: 100%;

        justify-content: center;
    }

    .form-actions {

        justify-content: stretch;
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


    <ul class="menu">

        <li class="menu-title">
            Main Menu
        </li>


        <li>

            <a href="dashboard.php">

                <i class="fas fa-chart-line"></i>

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


        <li class="menu-title">
            Academic
        </li>


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

                <i class="fas fa-graduation-cap"></i>

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


        <li class="menu-title">
            System
        </li>


        <li>

            <a href="parents.php">

                <i class="fas fa-users"></i>

                <span>
                    Parents
                </span>

            </a>

        </li>


        <li>

            <a
                href="settings.php"
                class="active"
            >

                <i class="fas fa-cog"></i>

                <span>
                    Settings
                </span>

            </a>

        </li>

    </ul>


    <div class="logout">

        <a href="logout.php">

            <i class="fas fa-sign-out-alt"></i>

            <span>
                Logout
            </span>

        </a>

    </div>

</aside>


<div
    class="overlay"
    id="overlay"
></div>


<!-- =====================================================
     MAIN
===================================================== -->

<main class="main">


    <!-- =================================================
         TOPBAR
    ================================================= -->

    <header class="topbar">


        <button
            class="menu-btn"
            id="menuBtn"
        >

            <i class="fas fa-bars"></i>

        </button>


        <div class="topbar-right">


            <!-- DARK / LIGHT MODE -->

            <button
                class="theme-toggle"
                id="themeToggle"
                type="button"
                title="Toggle Dark Mode"
                aria-label="Toggle Dark Mode"
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

            <div class="user">

                <div class="avatar">

                    <?php

                    echo strtoupper(
                        substr($userName, 0, 1)
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


    <!-- =================================================
         CONTENT
    ================================================= -->

    <section class="content">


        <div class="page-header">

            <h1>
                Settings
            </h1>

            <p>
                Manage your account and system preferences.
            </p>

        </div>


        <!-- ALERT -->

        <?php if ($message !== ''): ?>

            <div
                class="alert <?php echo $messageType; ?>"
            >

                <i
                    class="fas
                    <?php

                    echo $messageType === 'success'
                        ? 'fa-circle-check'
                        : 'fa-circle-exclamation';

                    ?>"
                ></i>


                <?php

                echo htmlspecialchars(
                    $message
                );

                ?>

            </div>

        <?php endif; ?>


        <div class="settings-grid">


            <!-- =================================================
                 SETTINGS MENU
            ================================================= -->

            <div class="settings-menu">


                <button
                    class="settings-tab active"
                    data-tab="profile"
                >

                    <i class="fas fa-user"></i>

                    Profile

                </button>


                <button
                    class="settings-tab"
                    data-tab="password"
                >

                    <i class="fas fa-lock"></i>

                    Password

                </button>


                <button
                    class="settings-tab"
                    data-tab="school"
                >

                    <i class="fas fa-school"></i>

                    School Info

                </button>


                <button
                    class="settings-tab"
                    data-tab="notifications"
                >

                    <i class="fas fa-bell"></i>

                    Notifications

                </button>

            </div>


            <!-- =================================================
                 SETTINGS CONTENT
            ================================================= -->

            <div class="settings-content">


                <!-- =================================================
                     PROFILE
                ================================================= -->

                <div
                    class="settings-card active"
                    id="profile"
                >

                    <div class="settings-header">

                        <h2>
                            Profile Information
                        </h2>

                        <p>
                            Update your administrator account information.
                        </p>

                    </div>


                    <div class="settings-body">


                        <form method="POST">


                            <input
                                type="hidden"
                                name="action"
                                value="update_profile"
                            >


                            <div class="form-grid">


                                <div class="form-group">

                                    <label>
                                        Full Name
                                    </label>


                                    <input
                                        type="text"
                                        name="name"
                                        value="<?php echo htmlspecialchars($currentUser['name']); ?>"
                                        required
                                    >

                                </div>


                                <div class="form-group">

                                    <label>
                                        Email Address
                                    </label>


                                    <input
                                        type="email"
                                        name="email"
                                        value="<?php echo htmlspecialchars($currentUser['email']); ?>"
                                        required
                                    >

                                </div>


                                <div class="form-group">

                                    <label>
                                        Role
                                    </label>


                                    <input
                                        type="text"
                                        value="<?php echo htmlspecialchars($currentUser['role']); ?>"
                                        disabled
                                    >


                                    <span class="form-help">

                                        Your role cannot be changed here.

                                    </span>

                                </div>

                            </div>


                            <div class="form-actions">

                                <button
                                    type="submit"
                                    class="btn-primary"
                                >

                                    <i class="fas fa-save"></i>

                                    Save Changes

                                </button>

                            </div>

                        </form>

                    </div>

                </div>


                <!-- =================================================
                     PASSWORD
                ================================================= -->

                <div
                    class="settings-card"
                    id="password"
                >

                    <div class="settings-header">

                        <h2>
                            Change Password
                        </h2>

                        <p>
                            Keep your account secure by using a strong password.
                        </p>

                    </div>


                    <div class="settings-body">


                        <form method="POST">


                            <input
                                type="hidden"
                                name="action"
                                value="change_password"
                            >


                            <div class="form-grid">


                                <div
                                    class="form-group full"
                                >

                                    <label>
                                        Current Password
                                    </label>


                                    <div
                                        class="password-field"
                                    >

                                        <input
                                            type="password"
                                            name="current_password"
                                            id="currentPassword"
                                            required
                                        >


                                        <button
                                            type="button"
                                            class="password-toggle"
                                            onclick="togglePassword('currentPassword', this)"
                                        >

                                            <i
                                                class="fas fa-eye"
                                            ></i>

                                        </button>

                                    </div>

                                </div>


                                <div class="form-group">

                                    <label>
                                        New Password
                                    </label>


                                    <div
                                        class="password-field"
                                    >

                                        <input
                                            type="password"
                                            name="new_password"
                                            id="newPassword"
                                            minlength="6"
                                            required
                                        >


                                        <button
                                            type="button"
                                            class="password-toggle"
                                            onclick="togglePassword('newPassword', this)"
                                        >

                                            <i
                                                class="fas fa-eye"
                                            ></i>

                                        </button>

                                    </div>


                                    <span class="form-help">

                                        Minimum 6 characters.

                                    </span>

                                </div>


                                <div class="form-group">

                                    <label>
                                        Confirm New Password
                                    </label>


                                    <div
                                        class="password-field"
                                    >

                                        <input
                                            type="password"
                                            name="confirm_password"
                                            id="confirmPassword"
                                            minlength="6"
                                            required
                                        >


                                        <button
                                            type="button"
                                            class="password-toggle"
                                            onclick="togglePassword('confirmPassword', this)"
                                        >

                                            <i
                                                class="fas fa-eye"
                                            ></i>

                                        </button>

                                    </div>

                                </div>

                            </div>


                            <div class="form-actions">

                                <button
                                    type="submit"
                                    class="btn-primary"
                                >

                                    <i class="fas fa-key"></i>

                                    Change Password

                                </button>

                            </div>

                        </form>

                    </div>

                </div>


                <!-- =================================================
                     SCHOOL INFORMATION
                ================================================= -->

                <div
                    class="settings-card"
                    id="school"
                >

                    <div class="settings-header">

                        <h2>
                            School Information
                        </h2>

                        <p>
                            Basic information about the school management system.
                        </p>

                    </div>


                    <div class="settings-body">


                        <div class="info-list">


                            <div class="info-item">

                                <div class="info-label">
                                    School Name
                                </div>

                                <div class="info-value">
                                    Cilmi_Doon School
                                </div>

                            </div>


                            <div class="info-item">

                                <div class="info-label">
                                    System Name
                                </div>

                                <div class="info-value">
                                    School Management System
                                </div>

                            </div>


                            <div class="info-item">

                                <div class="info-label">
                                    Database
                                </div>

                                <div class="info-value">
                                    student_management
                                </div>

                            </div>


                            <div class="info-item">

                                <div class="info-label">
                                    User Role
                                </div>

                                <div class="info-value">

                                    <?php

                                    echo htmlspecialchars(
                                        $userRole
                                    );

                                    ?>

                                </div>

                            </div>


                            <div class="info-item">

                                <div class="info-label">
                                    System Status
                                </div>


                                <div
                                    class="info-value"
                                    style="color:#16a34a;"
                                >

                                    <i
                                        class="fas fa-circle"
                                        style="font-size:8px;"
                                    ></i>

                                    Active

                                </div>

                            </div>

                        </div>

                    </div>

                </div>


                <!-- =================================================
                     NOTIFICATIONS
                ================================================= -->

                <div
                    class="settings-card"
                    id="notifications"
                >

                    <div class="settings-header">

                        <h2>
                            Notifications
                        </h2>

                        <p>
                            Manage notification preferences.
                        </p>

                    </div>


                    <div class="settings-body">


                        <div class="setting-row">

                            <div>

                                <h4>
                                    Attendance Alerts
                                </h4>

                                <p>
                                    Receive alerts when attendance records are updated.
                                </p>

                            </div>


                            <label class="switch">

                                <input
                                    type="checkbox"
                                    checked
                                >

                                <span
                                    class="slider"
                                ></span>

                            </label>

                        </div>


                        <div class="setting-row">

                            <div>

                                <h4>
                                    Payment Notifications
                                </h4>

                                <p>
                                    Receive notifications when payments are recorded.
                                </p>

                            </div>


                            <label class="switch">

                                <input
                                    type="checkbox"
                                    checked
                                >

                                <span
                                    class="slider"
                                ></span>

                            </label>

                        </div>


                        <div class="setting-row">

                            <div>

                                <h4>
                                    Exam Notifications
                                </h4>

                                <p>
                                    Receive notifications about upcoming exams.
                                </p>

                            </div>


                            <label class="switch">

                                <input
                                    type="checkbox"
                                    checked
                                >

                                <span
                                    class="slider"
                                ></span>

                            </label>

                        </div>


                        <div class="setting-row">

                            <div>

                                <h4>
                                    System Notifications
                                </h4>

                                <p>
                                    Receive important system notifications.
                                </p>

                            </div>


                            <label class="switch">

                                <input
                                    type="checkbox"
                                    checked
                                >

                                <span
                                    class="slider"
                                ></span>

                            </label>

                        </div>

                    </div>

                </div>

            </div>

        </div>

    </section>

</main>


<script>

/* =====================================================
   MOBILE SIDEBAR
===================================================== */

const sidebar =
    document.getElementById("sidebar");

const overlay =
    document.getElementById("overlay");

const menuBtn =
    document.getElementById("menuBtn");


menuBtn.addEventListener(
    "click",
    function () {

        sidebar.classList.toggle("active");

        overlay.classList.toggle("active");

    }
);


overlay.addEventListener(
    "click",
    function () {

        sidebar.classList.remove("active");

        overlay.classList.remove("active");

    }
);


/* =====================================================
   SETTINGS TABS
===================================================== */

const tabs =
    document.querySelectorAll(
        ".settings-tab"
    );

const cards =
    document.querySelectorAll(
        ".settings-card"
    );


tabs.forEach(
    function(tab) {

        tab.addEventListener(
            "click",
            function() {

                const target =
                    this.dataset.tab;


                tabs.forEach(
                    function(item) {

                        item.classList.remove(
                            "active"
                        );

                    }
                );


                cards.forEach(
                    function(card) {

                        card.classList.remove(
                            "active"
                        );

                    }
                );


                this.classList.add(
                    "active"
                );


                document
                    .getElementById(target)
                    .classList.add(
                        "active"
                    );

            }
        );

    }
);


/* =====================================================
   PASSWORD SHOW / HIDE
===================================================== */

function togglePassword(
    id,
    button
) {

    const input =
        document.getElementById(id);

    const icon =
        button.querySelector("i");


    if (
        input.type === "password"
    ) {

        input.type = "text";

        icon.classList.remove(
            "fa-eye"
        );

        icon.classList.add(
            "fa-eye-slash"
        );

    } else {

        input.type = "password";

        icon.classList.remove(
            "fa-eye-slash"
        );

        icon.classList.add(
            "fa-eye"
        );

    }

}


/* =====================================================
   DARK MODE / LIGHT MODE
===================================================== */

const themeToggle =
    document.getElementById(
        "themeToggle"
    );

const themeIcon =
    document.getElementById(
        "themeIcon"
    );


/*
   Check saved theme
*/

const savedTheme =
    localStorage.getItem(
        "schoolTheme"
    );


if (
    savedTheme === "dark"
) {

    document.body.classList.add(
        "dark-mode"
    );

    themeIcon.classList.remove(
        "fa-moon"
    );

    themeIcon.classList.add(
        "fa-sun"
    );

    themeToggle.title =
        "Switch to Light Mode";

} else {

    document.body.classList.remove(
        "dark-mode"
    );

    themeIcon.classList.remove(
        "fa-sun"
    );

    themeIcon.classList.add(
        "fa-moon"
    );

    themeToggle.title =
        "Switch to Dark Mode";

}


/*
   Toggle theme
*/

themeToggle.addEventListener(
    "click",
    function () {

        document.body.classList.toggle(
            "dark-mode"
        );


        const isDark =
            document.body.classList.contains(
                "dark-mode"
            );


        if (isDark) {

            /*
               Dark Mode
            */

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


            themeToggle.title =
                "Switch to Light Mode";

        } else {

            /*
               Light Mode
            */

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


            themeToggle.title =
                "Switch to Dark Mode";

        }

    }
);


/* =====================================================
   CLOSE MOBILE SIDEBAR WHEN LINK IS CLICKED
===================================================== */

const sidebarLinks =
    document.querySelectorAll(
        ".sidebar a"
    );


sidebarLinks.forEach(
    function(link) {

        link.addEventListener(
            "click",
            function() {

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

    }
);

</script>


</body>

</html>