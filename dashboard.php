<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

/* =========================================================
   DATABASE
========================================================= */

$host = "localhost";
$user = "root";
$pass = "";
$db   = "student_management";

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");


/* =========================================================
   NOTIFICATION HELPER
========================================================= */

require_once "notification_helper.php";


$userId = (int) $_SESSION['user_id'];

$userName = $_SESSION['user_name'] ?? "Administrator";
$userRole = $_SESSION['user_role'] ?? "Admin";

$unreadCount = getUnreadNotificationCount($conn, $userId);


/* =========================================================
   COUNT FUNCTION
========================================================= */

function getTableCount($conn, $table)
{
    $allowed = [
        "students",
        "teachers",
        "parents",
        "classes",
        "subjects",
        "exams"
    ];

    if (!in_array($table, $allowed, true)) {
        return 0;
    }

    $result = $conn->query(
        "SELECT COUNT(*) AS total FROM `$table`"
    );

    if (!$result) {
        return 0;
    }

    $row = $result->fetch_assoc();

    return (int) $row["total"];
}


/* =========================================================
   MAIN COUNTS
========================================================= */

$totalStudents = getTableCount($conn, "students");
$totalTeachers = getTableCount($conn, "teachers");
$totalParents  = getTableCount($conn, "parents");
$totalClasses  = getTableCount($conn, "classes");
$totalSubjects = getTableCount($conn, "subjects");
$totalExams    = getTableCount($conn, "exams");


/* =========================================================
   ATTENDANCE TODAY
========================================================= */

$presentToday = 0;
$absentToday  = 0;
$lateToday    = 0;

$stmt = $conn->prepare("
    SELECT
        SUM(status = 'Present') AS present_count,
        SUM(status = 'Absent') AS absent_count,
        SUM(status = 'Late') AS late_count
    FROM attendance
    WHERE attendance_date = CURDATE()
");

if ($stmt) {

    $stmt->execute();

    $result = $stmt->get_result();

    $row = $result->fetch_assoc();

    $presentToday = (int) ($row["present_count"] ?? 0);
    $absentToday  = (int) ($row["absent_count"] ?? 0);
    $lateToday    = (int) ($row["late_count"] ?? 0);

    $stmt->close();
}


/* =========================================================
   FINANCE
========================================================= */

$totalFees     = 0;
$totalPayments = 0;
$totalSalary   = 0;


/* Fees */

$result = $conn->query("
    SELECT COALESCE(SUM(amount),0) AS total
    FROM fees
");

if ($result) {

    $row = $result->fetch_assoc();

    $totalFees = (float) $row["total"];
}


/* Payments */

$result = $conn->query("
    SELECT COALESCE(SUM(amount),0) AS total
    FROM payments
");

if ($result) {

    $row = $result->fetch_assoc();

    $totalPayments = (float) $row["total"];
}


/* Salary */

$result = $conn->query("
    SELECT COALESCE(SUM(net_salary),0) AS total
    FROM salary
    WHERE status = 'Paid'
");

if ($result) {

    $row = $result->fetch_assoc();

    $totalSalary = (float) $row["total"];
}


/* =========================================================
   TODAY PAYMENTS
========================================================= */

$todayPayments = 0;

$stmt = $conn->prepare("
    SELECT COALESCE(SUM(amount),0) AS total
    FROM payments
    WHERE payment_date = CURDATE()
");

if ($stmt) {

    $stmt->execute();

    $result = $stmt->get_result();

    $row = $result->fetch_assoc();

    $todayPayments = (float) ($row["total"] ?? 0);

    $stmt->close();
}


/* =========================================================
   FEE STATUS
========================================================= */

$paidFees    = 0;
$partialFees = 0;
$unpaidFees  = 0;

$result = $conn->query("
    SELECT
        SUM(status = 'Paid') AS paid_count,
        SUM(status = 'Partial') AS partial_count,
        SUM(status = 'Unpaid') AS unpaid_count
    FROM fees
");

if ($result) {

    $row = $result->fetch_assoc();

    $paidFees    = (int) ($row["paid_count"] ?? 0);
    $partialFees = (int) ($row["partial_count"] ?? 0);
    $unpaidFees  = (int) ($row["unpaid_count"] ?? 0);
}


/* =========================================================
   SALARY STATUS
========================================================= */

$salaryPaid    = 0;
$salaryPending = 0;

$result = $conn->query("
    SELECT
        SUM(status = 'Paid') AS paid_count,
        SUM(status = 'Pending') AS pending_count
    FROM salary
");

if ($result) {

    $row = $result->fetch_assoc();

    $salaryPaid    = (int) ($row["paid_count"] ?? 0);
    $salaryPending = (int) ($row["pending_count"] ?? 0);
}


/* =========================================================
   MONTHLY PAYMENT DATA
========================================================= */

$monthlyLabels = [];
$monthlyData   = [];

for ($i = 5; $i >= 0; $i--) {

    $monthDate = date(
        "Y-m-01",
        strtotime("-$i months")
    );

    $monthlyLabels[] = date(
        "M",
        strtotime($monthDate)
    );

    $amount = 0;

    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(amount),0) AS total
        FROM payments
        WHERE payment_date >= ?
        AND payment_date < DATE_ADD(?, INTERVAL 1 MONTH)
    ");

    if ($stmt) {

        $stmt->bind_param(
            "ss",
            $monthDate,
            $monthDate
        );

        $stmt->execute();

        $result = $stmt->get_result();

        $row = $result->fetch_assoc();

        $amount = (float) ($row["total"] ?? 0);

        $stmt->close();
    }

    $monthlyData[] = $amount;
}


/* =========================================================
   ATTENDANCE LAST 7 DAYS
========================================================= */

$attendanceLabels  = [];
$attendancePresent = [];
$attendanceAbsent  = [];
$attendanceLate    = [];

for ($i = 6; $i >= 0; $i--) {

    $date = date(
        "Y-m-d",
        strtotime("-$i days")
    );

    $attendanceLabels[] = date(
        "D",
        strtotime($date)
    );

    $present = 0;
    $absent  = 0;
    $late    = 0;

    $stmt = $conn->prepare("
        SELECT
            SUM(status = 'Present') AS present_count,
            SUM(status = 'Absent') AS absent_count,
            SUM(status = 'Late') AS late_count
        FROM attendance
        WHERE attendance_date = ?
    ");

    if ($stmt) {

        $stmt->bind_param(
            "s",
            $date
        );

        $stmt->execute();

        $result = $stmt->get_result();

        $row = $result->fetch_assoc();

        $present = (int) ($row["present_count"] ?? 0);
        $absent  = (int) ($row["absent_count"] ?? 0);
        $late    = (int) ($row["late_count"] ?? 0);

        $stmt->close();
    }

    $attendancePresent[] = $present;
    $attendanceAbsent[]  = $absent;
    $attendanceLate[]    = $late;
}


/* =========================================================
   RECENT PAYMENTS
========================================================= */

$recentPayments = [];

$result = $conn->query("
    SELECT
        p.id,
        p.amount,
        p.payment_date,
        p.method,
        f.fee_type,
        f.student_id
    FROM payments p
    LEFT JOIN fees f
        ON p.fee_id = f.id
    ORDER BY p.id DESC
    LIMIT 6
");

if ($result) {

    while ($row = $result->fetch_assoc()) {

        $recentPayments[] = $row;
    }
}

?>
<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport"
      content="width=device-width, initial-scale=1.0">

<title>Dashboard | Student Management System</title>


<!-- Font Awesome -->

<link rel="stylesheet"
href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">


<!-- Chart.js -->

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>


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
        background .3s ease,
        color .3s ease;
}

a {
    text-decoration: none;
    color: inherit;
}

button,
input,
select {
    font-family: inherit;
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
        transform .3s ease;
}


/* Logo */

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

    flex-shrink: 0;

    border-radius: 10px;

    background: #2563eb;

    display: flex;

    align-items: center;

    justify-content: center;

    font-size: 20px;
}

.logo-text {

    font-size: 18px;

    font-weight: 800;

    white-space: nowrap;
}

.logo-text span {

    display: block;

    color: #94a3b8;

    font-size: 10px;

    margin-top: 2px;
}


/* Menu */

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


/* Logout */

.logout {

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

    width: calc(100% - 260px);

    transition:
        margin .3s ease,
        width .3s ease;
}


/* =========================================================
   TOPBAR
========================================================= */

.topbar {

    height: 70px;

    width: 100%;

    background: white;

    border-bottom:
        1px solid
        #e2e8f0;

    display: flex;

    align-items: center;

    justify-content: space-between;

    padding: 0 28px;

    position: sticky;

    top: 0;

    z-index: 900;

    transition:
        background .3s ease,
        border-color .3s ease;
}

.topbar-left {

    display: flex;

    align-items: center;

    gap: 15px;
}

.topbar-title {

    font-size: 18px;

    font-weight: 700;
}


/* Hamburger */

.menu-btn {

    display: none;

    width: 40px;
    height: 40px;

    border: none;

    border-radius: 8px;

    background: #f1f5f9;

    color: #334155;

    cursor: pointer;

    align-items: center;

    justify-content: center;

    font-size: 18px;
}


/* Topbar Right */

.topbar-right {

    display: flex;

    align-items: center;

    gap: 10px;
}


/* =========================================================
   THEME BUTTON
========================================================= */

.theme-toggle {

    width: 40px;
    height: 40px;

    border: none;

    border-radius: 50%;

    background: #f1f5f9;

    color: #475569;

    display: flex;

    align-items: center;

    justify-content: center;

    cursor: pointer;

    font-size: 17px;

    transition: .3s;
}

.theme-toggle:hover {

    transform: rotate(15deg) scale(1.05);
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

    font-size: 18px;

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

.user-box {

    display: flex;

    align-items: center;

    gap: 10px;

    padding-left: 12px;

    border-left:
        1px solid
        #e2e8f0;
}

.avatar {

    width: 38px;
    height: 38px;

    flex-shrink: 0;

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

    font-size: 13px;
}

.user-info span {

    display: block;

    font-size: 11px;

    color: #64748b;

    margin-top: 2px;
}


/* =========================================================
   CONTENT
========================================================= */

.content {

    width: 100%;

    padding: 28px;

    max-width: 1800px;

    margin: auto;
}


/* =========================================================
   PAGE HEADER
========================================================= */

.page-header {

    display: flex;

    align-items: center;

    justify-content: space-between;

    gap: 20px;

    margin-bottom: 25px;
}

.page-header h1 {

    font-size: 26px;

    line-height: 1.3;

    margin-bottom: 6px;
}

.page-header p {

    color: #64748b;

    font-size: 13px;
}

.date-box {

    background: white;

    border:
        1px solid
        #e2e8f0;

    padding: 10px 15px;

    border-radius: 9px;

    color: #475569;

    font-size: 13px;

    white-space: nowrap;
}


/* =========================================================
   STATISTICS
========================================================= */

.stats-grid {

    display: grid;

    grid-template-columns:
        repeat(4, minmax(0, 1fr));

    gap: 18px;

    margin-bottom: 20px;
}

.stat-card {

    background: white;

    border:
        1px solid
        #e2e8f0;

    border-radius: 14px;

    padding: 20px;

    min-width: 0;

    display: flex;

    align-items: center;

    justify-content: space-between;

    gap: 15px;

    transition: .25s;
}

.stat-card:hover {

    transform: translateY(-3px);

    box-shadow:
        0 10px 30px
        rgba(15,23,42,.08);
}

.stat-info {

    min-width: 0;
}

.stat-info span {

    color: #64748b;

    font-size: 12px;

    font-weight: 600;
}

.stat-info h2 {

    font-size: 25px;

    margin-top: 8px;

    overflow-wrap: anywhere;
}

.stat-info small {

    display: block;

    margin-top: 7px;

    color: #94a3b8;

    font-size: 11px;
}

.stat-icon {

    width: 50px;
    height: 50px;

    flex-shrink: 0;

    border-radius: 12px;

    display: flex;

    align-items: center;

    justify-content: center;

    font-size: 20px;
}

.blue {

    background: #dbeafe;

    color: #2563eb;
}

.green {

    background: #dcfce7;

    color: #16a34a;
}

.orange {

    background: #ffedd5;

    color: #ea580c;
}

.purple {

    background: #f3e8ff;

    color: #9333ea;
}


/* =========================================================
   CHART GRID
========================================================= */

.chart-grid {

    display: grid;

    grid-template-columns:
        minmax(0, 2fr)
        minmax(300px, 1fr);

    gap: 20px;

    margin-bottom: 20px;
}

.chart-card {

    background: white;

    border:
        1px solid
        #e2e8f0;

    border-radius: 14px;

    padding: 20px;

    min-width: 0;

    overflow: hidden;
}

.chart-header {

    display: flex;

    align-items: center;

    justify-content: space-between;

    gap: 10px;

    margin-bottom: 18px;
}

.chart-header h3 {

    font-size: 15px;

    margin-bottom: 4px;
}

.chart-header span {

    color: #64748b;

    font-size: 11px;
}

.chart-header > i {

    color: #2563eb;

    font-size: 17px;
}

.chart-container {

    position: relative;

    width: 100%;

    height: 300px;
}

.chart-container canvas {

    max-width: 100% !important;
}


/* =========================================================
   QUICK GRID
========================================================= */

.quick-grid {

    display: grid;

    grid-template-columns:
        repeat(3, minmax(0, 1fr));

    gap: 18px;

    margin-bottom: 22px;
}

.quick-card {

    background: white;

    border:
        1px solid
        #e2e8f0;

    border-radius: 14px;

    padding: 20px;

    min-width: 0;
}

.quick-card h3 {

    font-size: 14px;

    margin-bottom: 18px;
}

.progress-row {

    margin-bottom: 15px;
}

.progress-row:last-child {

    margin-bottom: 0;
}

.progress-top {

    display: flex;

    justify-content: space-between;

    align-items: center;

    gap: 10px;

    font-size: 12px;

    margin-bottom: 7px;

    color: #64748b;
}

.progress-top strong {

    color: #0f172a;
}

.progress {

    height: 7px;

    width: 100%;

    background: #e2e8f0;

    border-radius: 20px;

    overflow: hidden;
}

.progress-bar {

    height: 100%;

    border-radius: 20px;

    background: #2563eb;

    max-width: 100%;
}


/* =========================================================
   TABLE
========================================================= */

.table-card {

    background: white;

    border:
        1px solid
        #e2e8f0;

    border-radius: 14px;

    overflow: hidden;
}

.table-header {

    min-height: 60px;

    padding: 18px 20px;

    display: flex;

    align-items: center;

    justify-content: space-between;

    gap: 15px;

    border-bottom:
        1px solid
        #e2e8f0;
}

.table-header h3 {

    font-size: 15px;
}

.view-all {

    color: #2563eb;

    font-size: 12px;

    font-weight: 600;

    white-space: nowrap;
}

.table-wrapper {

    width: 100%;

    overflow-x: auto;

    -webkit-overflow-scrolling: touch;
}

table {

    width: 100%;

    min-width: 650px;

    border-collapse: collapse;
}

th {

    background: #f8fafc;

    color: #64748b;

    font-size: 11px;

    text-align: left;

    padding: 13px 20px;

    text-transform: uppercase;

    letter-spacing: .3px;

    white-space: nowrap;
}

td {

    padding: 14px 20px;

    border-top:
        1px solid
        #f1f5f9;

    font-size: 12px;

    white-space: nowrap;
}

.amount {

    font-weight: 700;

    color: #16a34a;
}

.badge {

    display: inline-flex;

    align-items: center;

    justify-content: center;

    padding: 5px 9px;

    border-radius: 20px;

    font-size: 10px;

    font-weight: 600;
}

.badge-success {

    background: #dcfce7;

    color: #15803d;
}


/* =========================================================
   OVERLAY
========================================================= */

.overlay {

    display: none;

    position: fixed;

    inset: 0;

    background:
        rgba(0,0,0,.55);

    z-index: 950;
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

    background: #1e293b;

    border-color: #334155;
}

body.dark-mode .menu-btn,
body.dark-mode .theme-toggle {

    background: #334155;

    color: #f8fafc;
}

body.dark-mode .notification {

    color: #cbd5e1;
}

body.dark-mode .notification:hover {

    background: #334155;

    color: #60a5fa;
}

body.dark-mode .notification-badge {

    border-color: #1e293b;
}

body.dark-mode .user-box {

    border-color: #334155;
}

body.dark-mode .user-info span {

    color: #94a3b8;
}

body.dark-mode .page-header p {

    color: #94a3b8;
}

body.dark-mode .date-box {

    background: #1e293b;

    border-color: #334155;

    color: #cbd5e1;
}

body.dark-mode .stat-card,
body.dark-mode .chart-card,
body.dark-mode .quick-card,
body.dark-mode .table-card {

    background: #1e293b;

    border-color: #334155;
}

body.dark-mode .stat-card:hover {

    box-shadow:
        0 10px 30px
        rgba(0,0,0,.25);
}

body.dark-mode .stat-info span {

    color: #94a3b8;
}

body.dark-mode .stat-info small {

    color: #64748b;
}

body.dark-mode .chart-header span {

    color: #94a3b8;
}

body.dark-mode .table-header {

    border-color: #334155;
}

body.dark-mode th {

    background: #0f172a;

    color: #94a3b8;
}

body.dark-mode td {

    border-color: #334155;
}

body.dark-mode .progress {

    background: #334155;
}

body.dark-mode .progress-top {

    color: #94a3b8;
}

body.dark-mode .progress-top strong {

    color: #e2e8f0;
}


/* =========================================================
   1400+
========================================================= */

@media (min-width: 1400px) {

    .content {

        padding: 32px;
    }

    .stats-grid {

        grid-template-columns:
            repeat(4, minmax(0, 1fr));
    }

}


/* =========================================================
   1200
========================================================= */

@media (max-width: 1200px) {

    .content {

        padding: 24px;
    }

    .stats-grid {

        grid-template-columns:
            repeat(2, minmax(0, 1fr));
    }

    .chart-grid {

        grid-template-columns: 1fr;
    }

    .quick-grid {

        grid-template-columns:
            repeat(2, minmax(0, 1fr));
    }

}


/* =========================================================
   900 TABLET
========================================================= */

@media (max-width: 900px) {

    .sidebar {

        transform: translateX(-100%);

        box-shadow:
            10px 0 30px
            rgba(0,0,0,.18);
    }

    .sidebar.active {

        transform: translateX(0);
    }

    .main {

        margin-left: 0;

        width: 100%;
    }

    .menu-btn {

        display: flex;
    }

    .topbar {

        padding: 0 20px;
    }

    .content {

        padding: 20px;
    }

    .stats-grid {

        grid-template-columns:
            repeat(2, minmax(0,1fr));
    }

    .chart-grid {

        grid-template-columns: 1fr;
    }

    .chart-card {

        width: 100%;
    }

    .quick-grid {

        grid-template-columns:
            repeat(2, minmax(0,1fr));
    }

}


/* =========================================================
   700
========================================================= */

@media (max-width: 700px) {

    .topbar {

        height: 65px;

        padding: 0 15px;
    }

    .content {

        padding: 16px;
    }

    .page-header {

        flex-direction: column;

        align-items: flex-start;

        gap: 12px;
    }

    .page-header h1 {

        font-size: 22px;
    }

    .date-box {

        width: 100%;
    }

    .stats-grid {

        grid-template-columns:
            repeat(2, minmax(0,1fr));

        gap: 12px;
    }

    .stat-card {

        padding: 16px;
    }

    .stat-info h2 {

        font-size: 21px;
    }

    .stat-icon {

        width: 42px;
        height: 42px;

        font-size: 17px;
    }

    .chart-card {

        padding: 16px;
    }

    .chart-container {

        height: 280px;
    }

    .quick-grid {

        grid-template-columns: 1fr;
    }

}


/* =========================================================
   550 MOBILE
========================================================= */

@media (max-width: 550px) {

    .topbar {

        height: 62px;

        padding: 0 12px;
    }

    .topbar-right {

        gap: 5px;
    }

    .menu-btn,
    .theme-toggle,
    .notification {

        width: 37px;
        height: 37px;
    }

    .topbar-title {

        display: none;
    }

    .user-box {

        border-left: none;

        padding-left: 3px;
    }

    .user-info {

        display: none;
    }

    .avatar {

        width: 35px;
        height: 35px;
    }

    .content {

        padding: 13px;
    }

    .page-header h1 {

        font-size: 20px;
    }

    .stats-grid {

        grid-template-columns: 1fr;

        gap: 12px;
    }

    .stat-card {

        min-height: 100px;

        padding: 17px;
    }

    .stat-info h2 {

        font-size: 24px;
    }

    .stat-icon {

        width: 46px;
        height: 46px;
    }

    .chart-grid {

        gap: 12px;
    }

    .chart-card {

        padding: 14px;

        border-radius: 12px;
    }

    .chart-header {

        margin-bottom: 12px;
    }

    .chart-header h3 {

        font-size: 13px;
    }

    .chart-header span {

        font-size: 10px;
    }

    .chart-container {

        height: 250px;
    }

    .quick-grid {

        gap: 12px;
    }

    .quick-card {

        padding: 16px;
    }

    .table-card {

        border-radius: 12px;
    }

    .table-header {

        padding: 15px;
    }

}


/* =========================================================
   380 SMALL PHONE
========================================================= */

@media (max-width: 380px) {

    .content {

        padding: 10px;
    }

    .topbar {

        padding: 0 9px;
    }

    .topbar-right {

        gap: 3px;
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

    .page-header h1 {

        font-size: 18px;
    }

    .stat-card {

        padding: 14px;
    }

    .stat-info h2 {

        font-size: 21px;
    }

    .chart-container {

        height: 230px;
    }

}


/* =========================================================
   PRINT
========================================================= */

@media print {

    .sidebar,
    .topbar,
    .menu-btn,
    .theme-toggle,
    .notification,
    .logout,
    .date-box {

        display: none !important;
    }

    .main {

        margin-left: 0 !important;

        width: 100% !important;
    }

    .content {

        padding: 0 !important;
    }

    .stat-card,
    .chart-card,
    .quick-card,
    .table-card {

        break-inside: avoid;
    }

}

</style>

</head>


<body>


<!-- =========================================================
     SIDEBAR
========================================================= -->

<aside class="sidebar" id="sidebar">


    <div class="logo">

        <div class="logo-icon">

            <i class="fas fa-graduation-cap"></i>

        </div>

        <div class="logo-text">

            Cilmi_Doon

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
            <a href="dashboard.php"
               class="active">

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
            <a href="teachers.php">

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



        <li class="menu-title">
            Academic
        </li>


        <li>
            <a href="classes.php">

                <i class="fas fa-school"></i>

                <span>Classes</span>

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



        <li class="menu-title">
            Finance
        </li>


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



        <li class="menu-title">
            System
        </li>


        <li>
            <a href="reports.php">

                <i class="fas fa-file-invoice"></i>

                <span>Reports</span>

            </a>
        </li>


        <li>
            <a href="notifications.php">

                <i class="fas fa-bell"></i>

                <span>Notifications</span>

            </a>
        </li>


        <li>
            <a href="settings.php">

                <i class="fas fa-cog"></i>

                <span>Settings</span>

            </a>
        </li>


    </ul>


    <div class="logout">

        <a href="logout.php">

            <i class="fas fa-sign-out-alt"></i>

            <span>Logout</span>

        </a>

    </div>

</aside>


<!-- Overlay -->

<div class="overlay"
     id="overlay">
</div>


<!-- =========================================================
     MAIN
========================================================= -->

<main class="main">


    <!-- =====================================================
         TOPBAR
    ====================================================== -->

    <header class="topbar">


        <div class="topbar-left">


            <button
                type="button"
                class="menu-btn"
                id="menuBtn">

                <i class="fas fa-bars"></i>

            </button>


            <div class="topbar-title">

                Dashboard

            </div>


        </div>



        <div class="topbar-right">


            <!-- Theme -->

            <button
                type="button"
                class="theme-toggle"
                id="themeToggle"
                title="Toggle Dark / Light Mode">

                <i class="fas fa-moon"></i>

            </button>


            <!-- Notifications -->

            <a
                href="notifications.php"
                class="notification"
                title="Notifications">

                <i class="fas fa-bell"></i>


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


            <!-- User -->

            <div class="user-box">


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



    <!-- =====================================================
         CONTENT
    ====================================================== -->

    <section class="content">


        <!-- Page Header -->

        <div class="page-header">


            <div>

                <h1>

                    Welcome back,
                    <?php
                    echo htmlspecialchars(
                        $userName
                    );
                    ?>
                    👋

                </h1>


                <p>

                    Here's what's happening
                    in your school today.

                </p>

            </div>


            <div class="date-box">

                <i class="far fa-calendar"></i>

                &nbsp;

                <?php
                echo date("d M Y");
                ?>

            </div>


        </div>



        <!-- =================================================
             PEOPLE STATISTICS
        ================================================== -->

        <div class="stats-grid">


            <!-- Students -->

            <div class="stat-card">


                <div class="stat-info">

                    <span>
                        Total Students
                    </span>

                    <h2>
                        <?php
                        echo number_format(
                            $totalStudents
                        );
                        ?>
                    </h2>

                    <small>
                        Registered students
                    </small>

                </div>


                <div class="stat-icon blue">

                    <i class="fas fa-user-graduate"></i>

                </div>


            </div>



            <!-- Teachers -->

            <div class="stat-card">


                <div class="stat-info">

                    <span>
                        Total Teachers
                    </span>

                    <h2>
                        <?php
                        echo number_format(
                            $totalTeachers
                        );
                        ?>
                    </h2>

                    <small>
                        Teaching staff
                    </small>

                </div>


                <div class="stat-icon green">

                    <i class="fas fa-chalkboard-teacher"></i>

                </div>


            </div>



            <!-- Parents -->

            <div class="stat-card">


                <div class="stat-info">

                    <span>
                        Total Parents
                    </span>

                    <h2>
                        <?php
                        echo number_format(
                            $totalParents
                        );
                        ?>
                    </h2>

                    <small>
                        Registered parents
                    </small>

                </div>


                <div class="stat-icon orange">

                    <i class="fas fa-users"></i>

                </div>


            </div>



            <!-- Classes -->

            <div class="stat-card">


                <div class="stat-info">

                    <span>
                        Total Classes
                    </span>

                    <h2>
                        <?php
                        echo number_format(
                            $totalClasses
                        );
                        ?>
                    </h2>

                    <small>
                        Active classes
                    </small>

                </div>


                <div class="stat-icon purple">

                    <i class="fas fa-school"></i>

                </div>


            </div>


        </div>



        <!-- =================================================
             FINANCE STATISTICS
        ================================================== -->

        <div class="stats-grid">


            <!-- Fees -->

            <div class="stat-card">

                <div class="stat-info">

                    <span>
                        Total Fees
                    </span>

                    <h2>

                        $<?php
                        echo number_format(
                            $totalFees,
                            2
                        );
                        ?>

                    </h2>

                    <small>
                        Expected fees
                    </small>

                </div>


                <div class="stat-icon blue">

                    <i class="fas fa-file-invoice-dollar"></i>

                </div>

            </div>



            <!-- Payments -->

            <div class="stat-card">

                <div class="stat-info">

                    <span>
                        Total Payments
                    </span>

                    <h2>

                        $<?php
                        echo number_format(
                            $totalPayments,
                            2
                        );
                        ?>

                    </h2>

                    <small>
                        Collected payments
                    </small>

                </div>


                <div class="stat-icon green">

                    <i class="fas fa-money-check-dollar"></i>

                </div>

            </div>



            <!-- Today -->

            <div class="stat-card">

                <div class="stat-info">

                    <span>
                        Today's Collection
                    </span>

                    <h2>

                        $<?php
                        echo number_format(
                            $todayPayments,
                            2
                        );
                        ?>

                    </h2>

                    <small>
                        Today's payments
                    </small>

                </div>


                <div class="stat-icon orange">

                    <i class="fas fa-cash-register"></i>

                </div>

            </div>



            <!-- Salary -->

            <div class="stat-card">

                <div class="stat-info">

                    <span>
                        Paid Salary
                    </span>

                    <h2>

                        $<?php
                        echo number_format(
                            $totalSalary,
                            2
                        );
                        ?>

                    </h2>

                    <small>
                        Total paid salary
                    </small>

                </div>


                <div class="stat-icon purple">

                    <i class="fas fa-wallet"></i>

                </div>

            </div>


        </div>



        <!-- =================================================
             CHART 1 + CHART 2
        ================================================== -->

        <div class="chart-grid">


            <!-- Monthly Payments -->

            <div class="chart-card">


                <div class="chart-header">

                    <div>

                        <h3>
                            Monthly Payment Collection
                        </h3>

                        <span>
                            Last 6 months
                        </span>

                    </div>


                    <i class="fas fa-chart-line"></i>

                </div>


                <div class="chart-container">

                    <canvas
                        id="paymentChart">
                    </canvas>

                </div>


            </div>



            <!-- System Overview -->

            <div class="chart-card">


                <div class="chart-header">

                    <div>

                        <h3>
                            System Overview
                        </h3>

                        <span>
                            Current records
                        </span>

                    </div>


                    <i class="fas fa-chart-pie"></i>

                </div>


                <div class="chart-container">

                    <canvas
                        id="overviewChart">
                    </canvas>

                </div>


            </div>


        </div>



        <!-- =================================================
             CHART 3 + CHART 4
        ================================================== -->

        <div class="chart-grid">


            <!-- Attendance -->

            <div class="chart-card">


                <div class="chart-header">

                    <div>

                        <h3>
                            Attendance Overview
                        </h3>

                        <span>
                            Last 7 days
                        </span>

                    </div>


                    <i class="fas fa-calendar-check"></i>

                </div>


                <div class="chart-container">

                    <canvas
                        id="attendanceChart">
                    </canvas>

                </div>


            </div>



            <!-- Salary -->

            <div class="chart-card">


                <div class="chart-header">

                    <div>

                        <h3>
                            Salary Status
                        </h3>

                        <span>
                            Paid vs Pending
                        </span>

                    </div>


                    <i class="fas fa-wallet"></i>

                </div>


                <div class="chart-container">

                    <canvas
                        id="salaryChart">
                    </canvas>

                </div>


            </div>


        </div>



        <!-- =================================================
             QUICK STATUS
        ================================================== -->

        <div class="quick-grid">


            <!-- Attendance -->

            <div class="quick-card">

                <h3>
                    Today's Attendance
                </h3>


                <?php

                $attendanceTotal =
                    $presentToday +
                    $absentToday +
                    $lateToday;


                $presentPercent =
                    $attendanceTotal > 0
                    ? ($presentToday /
                        $attendanceTotal) * 100
                    : 0;


                $absentPercent =
                    $attendanceTotal > 0
                    ? ($absentToday /
                        $attendanceTotal) * 100
                    : 0;


                $latePercent =
                    $attendanceTotal > 0
                    ? ($lateToday /
                        $attendanceTotal) * 100
                    : 0;

                ?>


                <div class="progress-row">

                    <div class="progress-top">

                        <span>
                            Present
                        </span>

                        <strong>
                            <?php
                            echo $presentToday;
                            ?>
                        </strong>

                    </div>


                    <div class="progress">

                        <div
                            class="progress-bar"
                            style="
                            width:
                            <?php
                            echo $presentPercent;
                            ?>%;
                            ">

                        </div>

                    </div>

                </div>



                <div class="progress-row">

                    <div class="progress-top">

                        <span>
                            Absent
                        </span>

                        <strong>
                            <?php
                            echo $absentToday;
                            ?>
                        </strong>

                    </div>


                    <div class="progress">

                        <div
                            class="progress-bar"
                            style="
                            width:
                            <?php
                            echo $absentPercent;
                            ?>%;
                            background:#ef4444;
                            ">

                        </div>

                    </div>

                </div>



                <div class="progress-row">

                    <div class="progress-top">

                        <span>
                            Late
                        </span>

                        <strong>
                            <?php
                            echo $lateToday;
                            ?>
                        </strong>

                    </div>


                    <div class="progress">

                        <div
                            class="progress-bar"
                            style="
                            width:
                            <?php
                            echo $latePercent;
                            ?>%;
                            background:#f59e0b;
                            ">

                        </div>

                    </div>

                </div>


            </div>



            <!-- Fees -->

            <div class="quick-card">

                <h3>
                    Fee Status
                </h3>


                <?php

                $feeTotal =
                    $paidFees +
                    $partialFees +
                    $unpaidFees;


                $paidPercent =
                    $feeTotal > 0
                    ? ($paidFees /
                        $feeTotal) * 100
                    : 0;


                $partialPercent =
                    $feeTotal > 0
                    ? ($partialFees /
                        $feeTotal) * 100
                    : 0;


                $unpaidPercent =
                    $feeTotal > 0
                    ? ($unpaidFees /
                        $feeTotal) * 100
                    : 0;

                ?>


                <div class="progress-row">

                    <div class="progress-top">

                        <span>
                            Paid
                        </span>

                        <strong>
                            <?php
                            echo $paidFees;
                            ?>
                        </strong>

                    </div>


                    <div class="progress">

                        <div
                            class="progress-bar"
                            style="
                            width:
                            <?php
                            echo $paidPercent;
                            ?>%;
                            background:#16a34a;
                            ">

                        </div>

                    </div>

                </div>



                <div class="progress-row">

                    <div class="progress-top">

                        <span>
                            Partial
                        </span>

                        <strong>
                            <?php
                            echo $partialFees;
                            ?>
                        </strong>

                    </div>


                    <div class="progress">

                        <div
                            class="progress-bar"
                            style="
                            width:
                            <?php
                            echo $partialPercent;
                            ?>%;
                            background:#f59e0b;
                            ">

                        </div>

                    </div>

                </div>



                <div class="progress-row">

                    <div class="progress-top">

                        <span>
                            Unpaid
                        </span>

                        <strong>
                            <?php
                            echo $unpaidFees;
                            ?>
                        </strong>

                    </div>


                    <div class="progress">

                        <div
                            class="progress-bar"
                            style="
                            width:
                            <?php
                            echo $unpaidPercent;
                            ?>%;
                            background:#ef4444;
                            ">

                        </div>

                    </div>

                </div>


            </div>



            <!-- Academic -->

            <div class="quick-card">

                <h3>
                    Academic Overview
                </h3>


                <div class="progress-row">

                    <div class="progress-top">

                        <span>
                            Students
                        </span>

                        <strong>
                            <?php
                            echo $totalStudents;
                            ?>
                        </strong>

                    </div>


                    <div class="progress">

                        <div
                            class="progress-bar"
                            style="width:100%;">

                        </div>

                    </div>

                </div>



                <div class="progress-row">

                    <div class="progress-top">

                        <span>
                            Subjects
                        </span>

                        <strong>
                            <?php
                            echo $totalSubjects;
                            ?>
                        </strong>

                    </div>


                    <div class="progress">

                        <div
                            class="progress-bar"
                            style="
                            width:100%;
                            background:#9333ea;
                            ">

                        </div>

                    </div>

                </div>



                <div class="progress-row">

                    <div class="progress-top">

                        <span>
                            Exams
                        </span>

                        <strong>
                            <?php
                            echo $totalExams;
                            ?>
                        </strong>

                    </div>


                    <div class="progress">

                        <div
                            class="progress-bar"
                            style="
                            width:100%;
                            background:#ea580c;
                            ">

                        </div>

                    </div>

                </div>


            </div>


        </div>



        <!-- =================================================
             RECENT PAYMENTS
        ================================================== -->

        <div class="table-card">


            <div class="table-header">

                <h3>
                    Recent Payments
                </h3>


                <a
                    href="payments.php"
                    class="view-all">

                    View All

                    <i class="fas fa-arrow-right"></i>

                </a>

            </div>


            <div class="table-wrapper">


                <table>


                    <thead>

                        <tr>

                            <th>
                                ID
                            </th>

                            <th>
                                Student ID
                            </th>

                            <th>
                                Fee Type
                            </th>

                            <th>
                                Amount
                            </th>

                            <th>
                                Method
                            </th>

                            <th>
                                Date
                            </th>

                            <th>
                                Status
                            </th>

                        </tr>

                    </thead>


                    <tbody>


                    <?php if (
                        empty($recentPayments)
                    ): ?>


                        <tr>

                            <td
                                colspan="7"
                                style="
                                text-align:center;
                                color:#94a3b8;
                                padding:30px;
                                ">

                                No payments found.

                            </td>

                        </tr>


                    <?php else: ?>


                        <?php foreach (
                            $recentPayments
                            as $payment
                        ): ?>


                            <tr>


                                <td>

                                    #<?php
                                    echo
                                        $payment["id"];
                                    ?>

                                </td>


                                <td>

                                    <?php

                                    echo
                                        htmlspecialchars(
                                            $payment[
                                                "student_id"
                                            ] ?? "-"
                                        );

                                    ?>

                                </td>


                                <td>

                                    <?php

                                    echo
                                        htmlspecialchars(
                                            $payment[
                                                "fee_type"
                                            ] ?? "-"
                                        );

                                    ?>

                                </td>


                                <td class="amount">

                                    $<?php

                                    echo number_format(
                                        (float)
                                        $payment["amount"],
                                        2
                                    );

                                    ?>

                                </td>


                                <td>

                                    <?php

                                    echo
                                        htmlspecialchars(
                                            $payment[
                                                "method"
                                            ] ?? "Cash"
                                        );

                                    ?>

                                </td>


                                <td>

                                    <?php

                                    echo date(
                                        "d M Y",
                                        strtotime(
                                            $payment[
                                                "payment_date"
                                            ]
                                        )
                                    );

                                    ?>

                                </td>


                                <td>

                                    <span
                                        class="badge badge-success">

                                        Paid

                                    </span>

                                </td>


                            </tr>


                        <?php endforeach; ?>


                    <?php endif; ?>


                    </tbody>


                </table>


            </div>


        </div>


    </section>


</main>



<!-- =========================================================
     JAVASCRIPT
========================================================= -->

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


/* =========================================================
   CLOSE SIDEBAR WHEN MENU LINK CLICKED ON MOBILE
========================================================= */

document
    .querySelectorAll(".menu a")
    .forEach(function(link) {

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

    });


/* =========================================================
   DARK / LIGHT MODE
========================================================= */

const themeToggle =
    document.getElementById(
        "themeToggle"
    );

const themeIcon =
    themeToggle.querySelector("i");


function updateThemeIcon() {

    if (
        document.body.classList.contains(
            "dark-mode"
        )
    ) {

        themeIcon.classList.remove(
            "fa-moon"
        );

        themeIcon.classList.add(
            "fa-sun"
        );

        themeToggle.title =
            "Switch to Light Mode";

    } else {

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


/* Load saved mode */

if (
    localStorage.getItem("theme")
    === "dark"
) {

    document.body.classList.add(
        "dark-mode"
    );

}


updateThemeIcon();


/* Toggle */

themeToggle.addEventListener(
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
                "theme",
                "dark"
            );

        } else {

            localStorage.setItem(
                "theme",
                "light"
            );

        }


        updateThemeIcon();

        updateChartTheme();

    }
);


/* =========================================================
   CHART DATA FROM PHP
========================================================= */

const monthlyLabels =
    <?php
    echo json_encode(
        $monthlyLabels
    );
    ?>;


const monthlyData =
    <?php
    echo json_encode(
        $monthlyData
    );
    ?>;


const attendanceLabels =
    <?php
    echo json_encode(
        $attendanceLabels
    );
    ?>;


const attendancePresent =
    <?php
    echo json_encode(
        $attendancePresent
    );
    ?>;


const attendanceAbsent =
    <?php
    echo json_encode(
        $attendanceAbsent
    );
    ?>;


const attendanceLate =
    <?php
    echo json_encode(
        $attendanceLate
    );
    ?>;


/* =========================================================
   CHART VARIABLES
========================================================= */

let paymentChart = null;

let overviewChart = null;

let attendanceChart = null;

let salaryChart = null;


/* =========================================================
   CHART THEME
========================================================= */

function getTextColor() {

    return document.body.classList.contains(
        "dark-mode"
    )
        ? "#cbd5e1"
        : "#475569";

}


function getGridColor() {

    return document.body.classList.contains(
        "dark-mode"
    )
        ? "rgba(148,163,184,.12)"
        : "rgba(15,23,42,.08)";

}


/* =========================================================
   CREATE CHARTS
========================================================= */

function createCharts() {

    const textColor =
        getTextColor();

    const gridColor =
        getGridColor();


    /* =====================================================
       PAYMENT LINE CHART
    ===================================================== */

    paymentChart = new Chart(

        document.getElementById(
            "paymentChart"
        ),

        {

            type: "line",

            data: {

                labels:
                    monthlyLabels,

                datasets: [

                    {

                        label:
                            "Payment Collection",

                        data:
                            monthlyData,

                        borderColor:
                            "#2563eb",

                        backgroundColor:
                            "rgba(37,99,235,.12)",

                        borderWidth: 3,

                        fill: true,

                        tension: .4,

                        pointRadius: 4,

                        pointHoverRadius: 7,

                        pointBackgroundColor:
                            "#2563eb"

                    }

                ]

            },

            options: {

                responsive: true,

                maintainAspectRatio: false,

                interaction: {

                    mode: "index",

                    intersect: false

                },

                plugins: {

                    legend: {

                        display: false

                    },

                    tooltip: {

                        callbacks: {

                            label:
                                function(context) {

                                    return "$" +
                                        Number(
                                            context.raw
                                        ).toLocaleString(
                                            undefined,
                                            {
                                                minimumFractionDigits: 2
                                            }
                                        );

                                }

                        }

                    }

                },

                scales: {

                    x: {

                        grid: {

                            display: false

                        },

                        ticks: {

                            color:
                                textColor

                        }

                    },

                    y: {

                        beginAtZero: true,

                        grid: {

                            color:
                                gridColor

                        },

                        ticks: {

                            color:
                                textColor,

                            callback:
                                function(value) {

                                    return "$" +
                                        Number(
                                            value
                                        ).toLocaleString();

                                }

                        }

                    }

                }

            }

        }

    );



    /* =====================================================
       SYSTEM OVERVIEW DOUGHNUT
    ===================================================== */

    overviewChart = new Chart(

        document.getElementById(
            "overviewChart"
        ),

        {

            type: "doughnut",

            data: {

                labels: [

                    "Students",

                    "Teachers",

                    "Parents",

                    "Classes"

                ],

                datasets: [

                    {

                        data: [

                            <?php
                            echo $totalStudents;
                            ?>,

                            <?php
                            echo $totalTeachers;
                            ?>,

                            <?php
                            echo $totalParents;
                            ?>,

                            <?php
                            echo $totalClasses;
                            ?>

                        ],

                        backgroundColor: [

                            "#2563eb",

                            "#16a34a",

                            "#ea580c",

                            "#9333ea"

                        ],

                        borderWidth: 0,

                        hoverOffset: 8

                    }

                ]

            },

            options: {

                responsive: true,

                maintainAspectRatio: false,

                cutout: "68%",

                plugins: {

                    legend: {

                        position: "bottom",

                        labels: {

                            color:
                                textColor,

                            padding: 15,

                            usePointStyle: true,

                            pointStyle:
                                "circle"

                        }

                    }

                }

            }

        }

    );



    /* =====================================================
       ATTENDANCE BAR CHART
    ===================================================== */

    attendanceChart = new Chart(

        document.getElementById(
            "attendanceChart"
        ),

        {

            type: "bar",

            data: {

                labels:
                    attendanceLabels,

                datasets: [

                    {

                        label:
                            "Present",

                        data:
                            attendancePresent,

                        backgroundColor:
                            "#16a34a",

                        borderRadius: 6,

                        borderSkipped: false

                    },

                    {

                        label:
                            "Absent",

                        data:
                            attendanceAbsent,

                        backgroundColor:
                            "#ef4444",

                        borderRadius: 6,

                        borderSkipped: false

                    },

                    {

                        label:
                            "Late",

                        data:
                            attendanceLate,

                        backgroundColor:
                            "#f59e0b",

                        borderRadius: 6,

                        borderSkipped: false

                    }

                ]

            },

            options: {

                responsive: true,

                maintainAspectRatio: false,

                interaction: {

                    mode: "index",

                    intersect: false

                },

                plugins: {

                    legend: {

                        labels: {

                            color:
                                textColor,

                            usePointStyle: true

                        }

                    }

                },

                scales: {

                    x: {

                        grid: {

                            display: false

                        },

                        ticks: {

                            color:
                                textColor

                        }

                    },

                    y: {

                        beginAtZero: true,

                        grid: {

                            color:
                                gridColor

                        },

                        ticks: {

                            color:
                                textColor,

                            precision: 0

                        }

                    }

                }

            }

        }

    );



    /* =====================================================
       SALARY DOUGHNUT
    ===================================================== */

    salaryChart = new Chart(

        document.getElementById(
            "salaryChart"
        ),

        {

            type: "doughnut",

            data: {

                labels: [

                    "Paid",

                    "Pending"

                ],

                datasets: [

                    {

                        data: [

                            <?php
                            echo $salaryPaid;
                            ?>,

                            <?php
                            echo $salaryPending;
                            ?>

                        ],

                        backgroundColor: [

                            "#16a34a",

                            "#f59e0b"

                        ],

                        borderWidth: 0,

                        hoverOffset: 8

                    }

                ]

            },

            options: {

                responsive: true,

                maintainAspectRatio: false,

                cutout: "65%",

                plugins: {

                    legend: {

                        position: "bottom",

                        labels: {

                            color:
                                textColor,

                            padding: 18,

                            usePointStyle: true

                        }

                    }

                }

            }

        }

    );

}


/* =========================================================
   UPDATE CHART COLORS
========================================================= */

function updateChartTheme() {

    const textColor =
        getTextColor();

    const gridColor =
        getGridColor();


    if (paymentChart) {

        paymentChart.options
            .scales.x.ticks.color =
            textColor;

        paymentChart.options
            .scales.y.ticks.color =
            textColor;

        paymentChart.options
            .scales.y.grid.color =
            gridColor;

        paymentChart.update();

    }


    if (overviewChart) {

        overviewChart.options
            .plugins.legend.labels.color =
            textColor;

        overviewChart.update();

    }


    if (attendanceChart) {

        attendanceChart.options
            .scales.x.ticks.color =
            textColor;

        attendanceChart.options
            .scales.y.ticks.color =
            textColor;

        attendanceChart.options
            .scales.y.grid.color =
            gridColor;

        attendanceChart.options
            .plugins.legend.labels.color =
            textColor;

        attendanceChart.update();

    }


    if (salaryChart) {

        salaryChart.options
            .plugins.legend.labels.color =
            textColor;

        salaryChart.update();

    }

}


/* =========================================================
   INITIALIZE
========================================================= */

createCharts();


/* =========================================================
   WINDOW RESIZE
========================================================= */

window.addEventListener(
    "resize",
    function() {

        if (
            window.innerWidth > 900
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

</script>


</body>
</html>