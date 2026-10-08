<?php
session_start();
require_once "config.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

$user_id   = (int) $_SESSION["user_id"];
$user_name = $_SESSION["user_name"] ?? "User";
$user_role = $_SESSION["user_role"] ?? "Admin";

$message = "";
$message_type = "";

/* =========================
   MARK ONE AS READ
========================= */

if (
    $_SERVER["REQUEST_METHOD"] === "POST"
    && isset($_POST["mark_read"])
) {

    $notification_id = (int) $_POST["notification_id"];

    $stmt = $conn->prepare("
        UPDATE notifications
        SET is_read = 1
        WHERE id = ?
        AND (user_id = ? OR user_id IS NULL)
    ");

    $stmt->bind_param(
        "ii",
        $notification_id,
        $user_id
    );

    $stmt->execute();
    $stmt->close();

    header("Location: notifications.php");
    exit;
}

/* =========================
   MARK ALL AS READ
========================= */

if (
    $_SERVER["REQUEST_METHOD"] === "POST"
    && isset($_POST["mark_all_read"])
) {

    $stmt = $conn->prepare("
        UPDATE notifications
        SET is_read = 1
        WHERE user_id = ?
        OR user_id IS NULL
    ");

    $stmt->bind_param(
        "i",
        $user_id
    );

    $stmt->execute();
    $stmt->close();

    header("Location: notifications.php");
    exit;
}

/* =========================
   DELETE ONE
========================= */

if (
    $_SERVER["REQUEST_METHOD"] === "POST"
    && isset($_POST["delete_notification"])
) {

    $notification_id =
        (int) $_POST["notification_id"];

    $stmt = $conn->prepare("
        DELETE FROM notifications
        WHERE id = ?
        AND (user_id = ? OR user_id IS NULL)
    ");

    $stmt->bind_param(
        "ii",
        $notification_id,
        $user_id
    );

    $stmt->execute();
    $stmt->close();

    header("Location: notifications.php");
    exit;
}

/* =========================
   DELETE ALL READ
========================= */

if (
    $_SERVER["REQUEST_METHOD"] === "POST"
    && isset($_POST["delete_read"])
) {

    $stmt = $conn->prepare("
        DELETE FROM notifications
        WHERE is_read = 1
        AND (user_id = ? OR user_id IS NULL)
    ");

    $stmt->bind_param(
        "i",
        $user_id
    );

    $stmt->execute();
    $stmt->close();

    header("Location: notifications.php");
    exit;
}

/* =========================
   GET NOTIFICATIONS
========================= */

$stmt = $conn->prepare("
    SELECT
        id,
        user_id,
        title,
        message,
        type,
        link,
        is_read,
        created_at
    FROM notifications
    WHERE user_id = ?
    OR user_id IS NULL
    ORDER BY created_at DESC
");

$stmt->bind_param(
    "i",
    $user_id
);

$stmt->execute();

$result = $stmt->get_result();

$notifications = [];

while ($row = $result->fetch_assoc()) {

    $notifications[] = $row;
}

$stmt->close();

/* =========================
   STATISTICS
========================= */

$total_notifications =
    count($notifications);

$unread_count = 0;
$read_count = 0;

foreach ($notifications as $notification) {

    if (
        (int)$notification["is_read"] === 0
    ) {

        $unread_count++;

    } else {

        $read_count++;
    }
}

/* =========================
   NOTIFICATION ICON
========================= */

function notificationIcon($type)
{
    switch ($type) {

        case "success":
            return "fa-check-circle";

        case "warning":
            return "fa-exclamation-triangle";

        case "danger":
            return "fa-times-circle";

        case "error":
            return "fa-times-circle";

        default:
            return "fa-info-circle";
    }
}

/* =========================
   NOTIFICATION CLASS
========================= */

function notificationClass($type)
{
    switch ($type) {

        case "success":
            return "success";

        case "warning":
            return "warning";

        case "danger":
        case "error":
            return "danger";

        default:
            return "info";
    }
}
?>

<!DOCTYPE html>

<html lang="en">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0">

<title>
    Notifications - Student Management
</title>

<link
    rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

<!-- =========================
     EARLY THEME
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

    font-family:
        Arial,
        Helvetica,
        sans-serif;
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

    transition: .3s;
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

    min-width: 45px;

    border-radius: 10px;

    background: #2563eb;

    display: flex;

    align-items: center;

    justify-content: center;

    font-size: 20px;
}

.logo-text {

    font-size: 17px;

    font-weight: bold;
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

/* =========================
   LOGOUT
========================= */

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

    transition: .2s;
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
}

/* =========================
   TOPBAR
========================= */

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

    transition:
        background .25s ease,
        border-color .25s ease;
}

.menu-btn {

    display: none;

    width: 40px;
    height: 40px;

    border:
        1px solid
        #e2e8f0;

    background: #f1f5f9;

    border-radius: 8px;

    cursor: pointer;

    align-items: center;

    justify-content: center;

    font-size: 18px;

    color: #0f172a;

    transition: .25s;
}

.menu-btn:hover {

    background: #e2e8f0;
}

/* =========================
   THEME TOGGLE
========================= */

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

/* =========================
   TOPBAR RIGHT
========================= */

.topbar-right {

    display: flex;

    align-items: center;

    gap: 15px;
}

/* =========================
   NOTIFICATION BELL
========================= */

.notification-bell {

    width: 40px;
    height: 40px;

    display: flex;

    align-items: center;

    justify-content: center;

    position: relative;

    color: #334155;

    font-size: 19px;

    text-decoration: none;

    border-radius: 50%;

    transition: .2s;
}

.notification-bell:hover {

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

    display: flex;

    align-items: center;

    justify-content: center;

    font-weight: bold;

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

    width: 40px;
    height: 40px;

    min-width: 40px;

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

    font-size: 14px;
}

.user-text span {

    display: block;

    font-size: 12px;

    color: #64748b;

    margin-top: 2px;
}

/* =========================
   CONTENT
========================= */

.content {

    padding: 30px;
}

.page-header {

    display: flex;

    justify-content: space-between;

    align-items: center;

    margin-bottom: 25px;

    gap: 15px;
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

    padding: 11px 16px;

    border-radius: 8px;

    cursor: pointer;

    font-size: 13px;

    display: inline-flex;

    align-items: center;

    justify-content: center;

    gap: 8px;

    text-decoration: none;

    transition: .2s;
}

.btn-blue {

    background: #2563eb;

    color: white;
}

.btn-blue:hover {

    background: #1d4ed8;
}

.btn-gray {

    background: #e2e8f0;

    color: #334155;
}

.btn-danger {

    background: #fee2e2;

    color: #dc2626;
}

.btn-danger:hover {

    background: #dc2626;

    color: white;
}

/* =========================
   STATS
========================= */

.stats {

    display: grid;

    grid-template-columns:
        repeat(3, 1fr);

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

    transition:
        background .25s ease,
        border-color .25s ease;
}

.stat-icon {

    width: 48px;
    height: 48px;

    min-width: 48px;

    border-radius: 10px;

    display: flex;

    align-items: center;

    justify-content: center;

    font-size: 19px;
}

.stat-icon.blue {

    background: #dbeafe;

    color: #2563eb;
}

.stat-icon.orange {

    background: #ffedd5;

    color: #ea580c;
}

.stat-icon.green {

    background: #dcfce7;

    color: #16a34a;
}

.stat-card h3 {

    font-size: 22px;

    margin-bottom: 4px;
}

.stat-card p {

    color: #64748b;

    font-size: 13px;
}

/* =========================
   NOTIFICATION CONTAINER
========================= */

.notification-container {

    background: white;

    border:
        1px solid
        #e2e8f0;

    border-radius: 14px;

    overflow: hidden;

    transition:
        background .25s ease,
        border-color .25s ease;
}

.notification-header {

    padding: 18px 20px;

    border-bottom:
        1px solid
        #e2e8f0;

    display: flex;

    justify-content: space-between;

    align-items: center;

    gap: 15px;
}

.notification-header h2 {

    font-size: 17px;
}

.notification-actions {

    display: flex;

    gap: 8px;

    flex-wrap: wrap;
}

.notification-list {

    padding: 0;
}

/* =========================
   NOTIFICATION ITEM
========================= */

.notification-item {

    display: flex;

    gap: 15px;

    padding: 18px 20px;

    border-bottom:
        1px solid
        #e2e8f0;

    transition: .2s;
}

.notification-item:last-child {

    border-bottom: none;
}

.notification-item:hover {

    background: #f8fafc;
}

.notification-item.unread {

    background: #eff6ff;
}

.notification-icon {

    width: 44px;
    height: 44px;

    min-width: 44px;

    border-radius: 50%;

    display: flex;

    align-items: center;

    justify-content: center;

    font-size: 17px;
}

.notification-icon.info {

    background: #dbeafe;

    color: #2563eb;
}

.notification-icon.success {

    background: #dcfce7;

    color: #16a34a;
}

.notification-icon.warning {

    background: #fef3c7;

    color: #d97706;
}

.notification-icon.danger {

    background: #fee2e2;

    color: #dc2626;
}

.notification-content {

    flex: 1;

    min-width: 0;
}

.notification-content h3 {

    font-size: 15px;

    margin-bottom: 6px;

    color: #0f172a;
}

.notification-content p {

    color: #64748b;

    font-size: 13px;

    line-height: 1.5;

    margin-bottom: 7px;

    word-break: break-word;
}

.notification-time {

    color: #94a3b8;

    font-size: 11px;
}

.notification-actions-right {

    display: flex;

    align-items: center;

    gap: 6px;

    flex-shrink: 0;
}

.small-btn {

    width: 34px;
    height: 34px;

    border: none;

    border-radius: 7px;

    cursor: pointer;

    display: flex;

    align-items: center;

    justify-content: center;

    text-decoration: none;

    transition: .2s;
}

.read-btn {

    background: #dcfce7;

    color: #16a34a;
}

.read-btn:hover {

    background: #16a34a;

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

.open-btn {

    background: #dbeafe;

    color: #2563eb;
}

.open-btn:hover {

    background: #2563eb;

    color: white;
}

/* =========================
   EMPTY
========================= */

.empty {

    padding: 70px 20px;

    text-align: center;

    color: #64748b;
}

.empty i {

    font-size: 50px;

    color: #cbd5e1;

    margin-bottom: 15px;
}

.empty h3 {

    color: #334155;

    margin-bottom: 6px;
}

/* =========================
   OVERLAY
========================= */

.overlay {

    display: none;

    position: fixed;

    inset: 0;

    background:
        rgba(0,0,0,.55);

    z-index: 999;
}

.overlay.active {

    display: block;
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

html.dark-mode .theme-toggle {

    background: #334155;

    border-color: #475569;

    color: #f8fafc;
}

html.dark-mode .theme-toggle:hover {

    background: #475569;

    color: #facc15;
}

html.dark-mode .menu-btn {

    background: #1e293b;

    border-color: #475569;

    color: #f8fafc;
}

html.dark-mode .menu-btn:hover {

    background: #334155;
}

html.dark-mode .notification-bell {

    color: #cbd5e1;
}

html.dark-mode .notification-bell:hover {

    background: #334155;

    color: #60a5fa;
}

html.dark-mode .notification-badge {

    border-color: #1e293b;
}

html.dark-mode .user-text strong {

    color: #f8fafc;
}

html.dark-mode .user-text span {

    color: #94a3b8;
}

html.dark-mode .page-header h1 {

    color: #f8fafc;
}

html.dark-mode .page-header p {

    color: #94a3b8;
}

/* CARDS */

html.dark-mode .stat-card,
html.dark-mode .notification-container {

    background: #1e293b;

    border-color: #334155;
}

html.dark-mode .stat-card h3 {

    color: #f8fafc;
}

html.dark-mode .stat-card p {

    color: #94a3b8;
}

/* STAT ICONS */

html.dark-mode .stat-icon.blue {

    background:
        rgba(37,99,235,.18);

    color: #60a5fa;
}

html.dark-mode .stat-icon.orange {

    background:
        rgba(234,88,12,.18);

    color: #fb923c;
}

html.dark-mode .stat-icon.green {

    background:
        rgba(22,163,74,.18);

    color: #4ade80;
}

/* HEADER */

html.dark-mode .notification-header {

    border-color: #334155;
}

html.dark-mode .notification-header h2 {

    color: #f8fafc;
}

/* NOTIFICATION */

html.dark-mode .notification-item {

    border-color: #334155;
}

html.dark-mode .notification-item:hover {

    background: #263449;
}

html.dark-mode .notification-item.unread {

    background:
        rgba(37,99,235,.12);
}

html.dark-mode .notification-content h3 {

    color: #f8fafc;
}

html.dark-mode .notification-content p {

    color: #94a3b8;
}

html.dark-mode .notification-time {

    color: #64748b;
}

/* ICONS */

html.dark-mode .notification-icon.info {

    background:
        rgba(37,99,235,.18);

    color: #60a5fa;
}

html.dark-mode .notification-icon.success {

    background:
        rgba(22,163,74,.18);

    color: #4ade80;
}

html.dark-mode .notification-icon.warning {

    background:
        rgba(217,119,6,.18);

    color: #fbbf24;
}

html.dark-mode .notification-icon.danger {

    background:
        rgba(220,38,38,.18);

    color: #f87171;
}

/* ACTION BUTTONS */

html.dark-mode .read-btn {

    background:
        rgba(22,163,74,.18);

    color: #4ade80;
}

html.dark-mode .delete-btn {

    background:
        rgba(220,38,38,.18);

    color: #f87171;
}

html.dark-mode .open-btn {

    background:
        rgba(37,99,235,.18);

    color: #60a5fa;
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

/* GRAY BUTTON */

html.dark-mode .btn-gray {

    background: #334155;

    color: #e2e8f0;
}

/* DANGER BUTTON */

html.dark-mode .btn-danger {

    background:
        rgba(220,38,38,.18);

    color: #f87171;
}

html.dark-mode .btn-danger:hover {

    background: #dc2626;

    color: white;
}

/* ==================================================
   RESPONSIVE
================================================== */

@media (max-width: 1000px) {

    .stats {

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

    html.dark-mode .sidebar {

        box-shadow:
            10px 0 30px
            rgba(0,0,0,.5);
    }
}

/* SMALL TABLET */

@media (max-width: 650px) {

    .content {

        padding: 18px;
    }

    .stats {

        grid-template-columns: 1fr;

        gap: 12px;
    }

    .page-header {

        align-items: flex-start;

        flex-direction: column;

        gap: 14px;
    }

    .page-header h1 {

        font-size: 23px;
    }

    .page-header p {

        font-size: 13px;
    }

    .page-header > form {

        width: 100%;
    }

    .page-header > form .btn {

        width: 100%;
    }

    .notification-item {

        flex-wrap: wrap;

        padding: 16px;
    }

    .notification-content {

        width:
            calc(100% - 59px);
    }

    .notification-actions-right {

        width: 100%;

        margin-left: 59px;

        justify-content: flex-start;
    }

    .user-text {

        display: none;
    }

    .theme-toggle,
    .menu-btn,
    .notification-bell {

        width: 38px;
        height: 38px;
    }

    .avatar {

        width: 38px;
        height: 38px;

        min-width: 38px;
    }
}

/* MOBILE */

@media (max-width: 480px) {

    .topbar {

        height: 62px;

        padding: 0 12px;
    }

    .topbar-right {

        gap: 6px;
    }

    .content {

        padding: 14px;
    }

    .page-header h1 {

        font-size: 21px;
    }

    .stat-card {

        padding: 16px;
    }

    .stat-card h3 {

        font-size: 20px;
    }

    .notification-header {

        align-items: flex-start;

        flex-direction: column;

        padding: 16px;
    }

    .notification-header h2 {

        font-size: 16px;
    }

    .notification-header form {

        width: 100%;
    }

    .notification-header .btn {

        width: 100%;
    }

    .notification-item {

        padding: 15px;
    }

    .notification-icon {

        width: 40px;
        height: 40px;

        min-width: 40px;
    }

    .notification-content {

        width:
            calc(100% - 55px);
    }

    .notification-actions-right {

        margin-left: 55px;
    }

    .theme-toggle,
    .menu-btn,
    .notification-bell {

        width: 36px;
        height: 36px;

        font-size: 15px;
    }

    .avatar {

        width: 36px;
        height: 36px;

        min-width: 36px;

        font-size: 13px;
    }
}

/* VERY SMALL MOBILE */

@media (max-width: 360px) {

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
    .menu-btn,
    .notification-bell {

        width: 34px;
        height: 34px;
    }

    .avatar {

        width: 34px;
        height: 34px;

        min-width: 34px;
    }

    .notification-item {

        gap: 10px;
    }

    .notification-actions-right {

        margin-left: 50px;
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
    id="sidebar">

    <div class="logo">

        <div class="logo-icon">

            <i class="fas fa-school"></i>

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

                <i class="fas fa-home"></i>

                Dashboard

            </a>

        </li>

        <li>

            <a href="students.php">

                <i class="fas fa-user-graduate"></i>

                Students

            </a>

        </li>

        <li>

            <a href="teachers.php">

                <i class="fas fa-chalkboard-teacher"></i>

                Teachers

            </a>

        </li>


        <li class="menu-title">
            Academic
        </li>

        <li>

            <a href="classes.php">

                <i class="fas fa-door-open"></i>

                Classes

            </a>

        </li>

        <li>

            <a href="subjects.php">

                <i class="fas fa-book"></i>

                Subjects

            </a>

        </li>

        <li>

            <a href="attendance.php">

                <i class="fas fa-calendar-check"></i>

                Attendance

            </a>

        </li>

        <li>

            <a href="exams.php">

                <i class="fas fa-file-alt"></i>

                Exams

            </a>

        </li>

        <li>

            <a href="results.php">

                <i class="fas fa-chart-line"></i>

                Results

            </a>

        </li>


        <li class="menu-title">
            Finance
        </li>

        <li>

            <a href="fees.php">

                <i class="fas fa-money-bill-wave"></i>

                Fees

            </a>

        </li>

        <li>

            <a href="payments.php">

                <i class="fas fa-credit-card"></i>

                Payments

            </a>

        </li>

        <li>

            <a href="salary.php">

                <i class="fas fa-wallet"></i>

                Salary

            </a>

        </li>

        <li>

            <a href="reports.php">

                <i class="fas fa-chart-pie"></i>

                Reports

            </a>

        </li>


        <li class="menu-title">
            System
        </li>

        <li>

            <a href="parents.php">

                <i class="fas fa-users"></i>

                Parents

            </a>

        </li>

        <li>

            <a
                href="notifications.php"
                class="active">

                <i class="fas fa-bell"></i>

                Notifications

                <?php if ($unread_count > 0): ?>

                    <span
                        style="
                            margin-left:auto;
                            background:#ef4444;
                            color:white;
                            width:20px;
                            height:20px;
                            border-radius:50%;
                            display:flex;
                            align-items:center;
                            justify-content:center;
                            font-size:10px;
                        ">

                        <?= $unread_count > 99
                            ? "99+"
                            : $unread_count ?>

                    </span>

                <?php endif; ?>

            </a>

        </li>

        <li>

            <a href="settings.php">

                <i class="fas fa-cog"></i>

                Settings

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
            ">

            <i class="fas fa-sign-out-alt"></i>

            Logout

        </a>

    </div>

</aside>


<div
    class="overlay"
    id="overlay">
</div>


<!-- =========================
     MAIN
========================= -->

<main class="main">

    <header class="topbar">

        <button
            type="button"
            class="menu-btn"
            id="menuBtn">

            <i class="fas fa-bars"></i>

        </button>


        <div></div>


        <div class="topbar-right">

            <!-- THEME -->

            <button
                type="button"
                class="theme-toggle"
                id="themeToggle"
                title="Toggle Dark / Light Mode">

                <i class="fas fa-moon"></i>

            </button>


            <!-- NOTIFICATION -->

            <a
                href="notifications.php"
                class="notification-bell"
                title="Notifications">

                <i class="fas fa-bell"></i>

                <?php if ($unread_count > 0): ?>

                    <span
                        class="notification-badge">

                        <?= $unread_count > 99
                            ? "99+"
                            : $unread_count ?>

                    </span>

                <?php endif; ?>

            </a>


            <!-- USER -->

            <div class="user-info">

                <div class="avatar">

                    <?= strtoupper(
                        substr(
                            $user_name,
                            0,
                            1
                        )
                    ) ?>

                </div>

                <div class="user-text">

                    <strong>

                        <?= htmlspecialchars(
                            $user_name
                        ) ?>

                    </strong>

                    <span>

                        <?= htmlspecialchars(
                            $user_role
                        ) ?>

                    </span>

                </div>

            </div>

        </div>

    </header>


    <!-- =========================
         CONTENT
    ========================= -->

    <section class="content">

        <div class="page-header">

            <div>

                <h1>
                    Notifications
                </h1>

                <p>
                    Manage your system notifications and alerts.
                </p>

            </div>


            <form method="POST">

                <button
                    type="submit"
                    name="mark_all_read"
                    class="btn btn-blue">

                    <i class="fas fa-check-double"></i>

                    Mark All as Read

                </button>

            </form>

        </div>


        <!-- =========================
             STATISTICS
        ========================= -->

        <div class="stats">

            <div class="stat-card">

                <div class="stat-icon blue">

                    <i class="fas fa-bell"></i>

                </div>

                <div>

                    <h3>
                        <?= $total_notifications ?>
                    </h3>

                    <p>
                        Total Notifications
                    </p>

                </div>

            </div>


            <div class="stat-card">

                <div class="stat-icon orange">

                    <i class="fas fa-envelope"></i>

                </div>

                <div>

                    <h3>
                        <?= $unread_count ?>
                    </h3>

                    <p>
                        Unread Notifications
                    </p>

                </div>

            </div>


            <div class="stat-card">

                <div class="stat-icon green">

                    <i class="fas fa-envelope-open"></i>

                </div>

                <div>

                    <h3>
                        <?= $read_count ?>
                    </h3>

                    <p>
                        Read Notifications
                    </p>

                </div>

            </div>

        </div>


        <!-- =========================
             NOTIFICATION CONTAINER
        ========================= -->

        <div class="notification-container">

            <div class="notification-header">

                <h2>
                    Recent Notifications
                </h2>


                <?php if ($read_count > 0): ?>

                    <form method="POST">

                        <button
                            type="submit"
                            name="delete_read"
                            class="btn btn-danger"
                            onclick="
                                return confirm(
                                    'Delete all read notifications?'
                                );
                            ">

                            <i class="fas fa-trash"></i>

                            Delete Read

                        </button>

                    </form>

                <?php endif; ?>

            </div>


            <div class="notification-list">

                <?php if (empty($notifications)): ?>

                    <div class="empty">

                        <i
                            class="far fa-bell-slash">
                        </i>

                        <h3>
                            No Notifications
                        </h3>

                        <p>
                            You don't have any notifications yet.
                        </p>

                    </div>

                <?php else: ?>


                    <?php foreach (
                        $notifications
                        as $notification
                    ): ?>

                        <?php

                        $type =
                            notificationClass(
                                $notification["type"]
                            );

                        $icon =
                            notificationIcon(
                                $notification["type"]
                            );

                        ?>


                        <div
                            class="
                                notification-item
                                <?= (int)
                                    $notification["is_read"]
                                    === 0
                                    ? "unread"
                                    : ""
                                ?>
                            ">

                            <!-- ICON -->

                            <div
                                class="
                                    notification-icon
                                    <?= $type ?>
                                ">

                                <i
                                    class="
                                        fas
                                        <?= $icon ?>
                                    ">
                                </i>

                            </div>


                            <!-- CONTENT -->

                            <div
                                class="
                                    notification-content
                                ">

                                <h3>

                                    <?= htmlspecialchars(
                                        $notification["title"]
                                    ) ?>


                                    <?php if (
                                        (int)
                                        $notification["is_read"]
                                        === 0
                                    ): ?>

                                        <span
                                            style="
                                                display:inline-block;
                                                width:7px;
                                                height:7px;
                                                background:#2563eb;
                                                border-radius:50%;
                                                margin-left:5px;
                                                vertical-align:middle;
                                            ">
                                        </span>

                                    <?php endif; ?>

                                </h3>


                                <p>

                                    <?= htmlspecialchars(
                                        $notification["message"]
                                    ) ?>

                                </p>


                                <div
                                    class="
                                        notification-time
                                    ">

                                    <i
                                        class="far fa-clock">
                                    </i>

                                    <?= date(
                                        "M d, Y - h:i A",
                                        strtotime(
                                            $notification["created_at"]
                                        )
                                    ) ?>

                                </div>

                            </div>


                            <!-- ACTIONS -->

                            <div
                                class="
                                    notification-actions-right
                                ">


                                <?php if (
                                    !empty(
                                        $notification["link"]
                                    )
                                ): ?>

                                    <a
                                        href="<?= htmlspecialchars(
                                            $notification["link"]
                                        ) ?>"
                                        class="
                                            small-btn
                                            open-btn
                                        "
                                        title="Open">

                                        <i
                                            class="
                                                fas
                                                fa-external-link-alt
                                            ">
                                        </i>

                                    </a>

                                <?php endif; ?>


                                <?php if (
                                    (int)
                                    $notification["is_read"]
                                    === 0
                                ): ?>

                                    <form method="POST">

                                        <input
                                            type="hidden"
                                            name="notification_id"
                                            value="<?= $notification["id"] ?>">

                                        <button
                                            type="submit"
                                            name="mark_read"
                                            class="
                                                small-btn
                                                read-btn
                                            "
                                            title="Mark as read">

                                            <i
                                                class="
                                                    fas
                                                    fa-check
                                                ">
                                            </i>

                                        </button>

                                    </form>

                                <?php endif; ?>


                                <form method="POST">

                                    <input
                                        type="hidden"
                                        name="notification_id"
                                        value="<?= $notification["id"] ?>">

                                    <button
                                        type="submit"
                                        name="delete_notification"
                                        class="
                                            small-btn
                                            delete-btn
                                        "
                                        title="Delete"
                                        onclick="
                                            return confirm(
                                                'Delete this notification?'
                                            );
                                        ">

                                        <i
                                            class="
                                                fas
                                                fa-trash
                                            ">
                                        </i>

                                    </button>

                                </form>

                            </div>

                        </div>

                    <?php endforeach; ?>


                <?php endif; ?>

            </div>

        </div>

    </section>

</main>


<!-- =========================
     JAVASCRIPT
========================= -->

<script>

/* =========================
   SIDEBAR
========================= */

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

/* =========================
   DARK / LIGHT MODE
========================= */

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


/* =========================
   LOAD SAVED THEME
========================= */

const savedTheme =
    localStorage.getItem("theme")
    || "light";

applyTheme(savedTheme);


/* =========================
   TOGGLE THEME
========================= */

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