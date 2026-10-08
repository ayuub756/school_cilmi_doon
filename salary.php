<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$conn = new mysqli("localhost", "root", "", "student_management");

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

/* =========================
   UNREAD NOTIFICATIONS
========================= */

$unreadCount = getUnreadNotificationCount(
    $conn,
    (int) $_SESSION['user_id']
);

$message = "";
$messageType = "";

/* =========================
   DELETE SALARY
========================= */

if (isset($_GET['delete'])) {

    $id = intval($_GET['delete']);

    $stmt = $conn->prepare("
        DELETE FROM salary
        WHERE id = ?
    ");

    $stmt->bind_param("i", $id);

    if ($stmt->execute()) {

        $message = "Salary record deleted successfully.";
        $messageType = "success";

    } else {

        $message = "Failed to delete salary record.";
        $messageType = "error";
    }

    $stmt->close();
}


/* =========================
   ADD / UPDATE SALARY
========================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? "";

    $teacherId = intval($_POST['teacher_id'] ?? 0);

    $basicSalary = floatval($_POST['basic_salary'] ?? 0);
    $allowance   = floatval($_POST['allowance'] ?? 0);
    $deduction   = floatval($_POST['deduction'] ?? 0);

    $paymentDate = $_POST['payment_date'] ?? "";
    $status      = $_POST['status'] ?? "Pending";
    $note        = trim($_POST['note'] ?? "");

    /* Automatic Net Salary */

    $netSalary = $basicSalary + $allowance - $deduction;


    /* =========================
       ADD
    ========================= */

    if ($action === "add") {

        if ($teacherId <= 0) {

            $message = "Please select a teacher.";
            $messageType = "error";

        } elseif (
            $basicSalary < 0 ||
            $allowance < 0 ||
            $deduction < 0
        ) {

            $message = "Salary amounts cannot be negative.";
            $messageType = "error";

        } elseif ($netSalary < 0) {

            $message = "Deduction cannot be greater than total salary.";
            $messageType = "error";

        } elseif ($paymentDate === "") {

            $message = "Please select payment date.";
            $messageType = "error";

        } else {

            $stmt = $conn->prepare("
                INSERT INTO salary
                (
                    teacher_id,
                    basic_salary,
                    allowance,
                    deduction,
                    net_salary,
                    payment_date,
                    status,
                    note
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->bind_param(
                "iddddsss",
                $teacherId,
                $basicSalary,
                $allowance,
                $deduction,
                $netSalary,
                $paymentDate,
                $status,
                $note
            );

            if ($stmt->execute()) {

                $message = "Salary record added successfully.";
                $messageType = "success";

                /* =========================
                   AUTOMATIC NOTIFICATION
                ========================= */

                createNotification(
                    $conn,
                    (int) $_SESSION['user_id'],
                    "New Salary Added",
                    "A salary record of $" .
                    number_format($netSalary, 2) .
                    " has been successfully added.",
                    "success",
                    "salary.php"
                );

            } else {

                $message = "Failed to add salary record.";
                $messageType = "error";
            }

            $stmt->close();
        }
    }


    /* =========================
       UPDATE
    ========================= */

    if ($action === "update") {

        $id = intval($_POST['id'] ?? 0);

        if ($teacherId <= 0) {

            $message = "Please select a teacher.";
            $messageType = "error";

        } elseif (
            $basicSalary < 0 ||
            $allowance < 0 ||
            $deduction < 0
        ) {

            $message = "Salary amounts cannot be negative.";
            $messageType = "error";

        } elseif ($netSalary < 0) {

            $message = "Deduction cannot be greater than total salary.";
            $messageType = "error";

        } elseif ($paymentDate === "") {

            $message = "Please select payment date.";
            $messageType = "error";

        } else {

            $stmt = $conn->prepare("
                UPDATE salary
                SET
                    teacher_id = ?,
                    basic_salary = ?,
                    allowance = ?,
                    deduction = ?,
                    net_salary = ?,
                    payment_date = ?,
                    status = ?,
                    note = ?
                WHERE id = ?
            ");

            $stmt->bind_param(
                "iddddsssi",
                $teacherId,
                $basicSalary,
                $allowance,
                $deduction,
                $netSalary,
                $paymentDate,
                $status,
                $note,
                $id
            );

            if ($stmt->execute()) {

                $message = "Salary record updated successfully.";
                $messageType = "success";

            } else {

                $message = "Failed to update salary record.";
                $messageType = "error";
            }

            $stmt->close();
        }
    }
}


/* =========================
   SEARCH
========================= */

$search = trim($_GET['search'] ?? "");
$statusFilter = $_GET['status'] ?? "";

if ($search !== "" || $statusFilter !== "") {

    $sql = "
        SELECT
            sa.id,
            sa.teacher_id,
            sa.basic_salary,
            sa.allowance,
            sa.deduction,
            sa.net_salary,
            sa.payment_date,
            sa.status,
            sa.note,
            sa.created_at,
            t.full_name,
            t.teacher_id AS teacher_code
        FROM salary sa
        LEFT JOIN teachers t
            ON sa.teacher_id = t.id
        WHERE 1=1
    ";

    $params = [];
    $types = "";

    if ($search !== "") {

        $sql .= "
            AND (
                t.full_name LIKE ?
                OR t.teacher_id LIKE ?
                OR sa.note LIKE ?
            )
        ";

        $like = "%" . $search . "%";

        $params[] = $like;
        $params[] = $like;
        $params[] = $like;

        $types .= "sss";
    }

    if ($statusFilter !== "") {

        $sql .= " AND sa.status = ? ";

        $params[] = $statusFilter;

        $types .= "s";
    }

    $sql .= " ORDER BY sa.id DESC";

    $stmt = $conn->prepare($sql);

    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();

    $salaryRecords = $stmt->get_result();

} else {

    $salaryRecords = $conn->query("
        SELECT
            sa.id,
            sa.teacher_id,
            sa.basic_salary,
            sa.allowance,
            sa.deduction,
            sa.net_salary,
            sa.payment_date,
            sa.status,
            sa.note,
            sa.created_at,
            t.full_name,
            t.teacher_id AS teacher_code
        FROM salary sa
        LEFT JOIN teachers t
            ON sa.teacher_id = t.id
        ORDER BY sa.id DESC
    ");
}


/* =========================
   STATISTICS
========================= */

$totalSalaryResult = $conn->query("
    SELECT COALESCE(SUM(net_salary), 0) AS total
    FROM salary
");

$totalSalary = $totalSalaryResult->fetch_assoc()['total'];


$paidResult = $conn->query("
    SELECT COALESCE(SUM(net_salary), 0) AS total
    FROM salary
    WHERE status = 'Paid'
");

$totalPaid = $paidResult->fetch_assoc()['total'];


$pendingResult = $conn->query("
    SELECT COALESCE(SUM(net_salary), 0) AS total
    FROM salary
    WHERE status = 'Pending'
");

$totalPending = $pendingResult->fetch_assoc()['total'];


$recordsResult = $conn->query("
    SELECT COUNT(*) AS total
    FROM salary
");

$totalRecords = $recordsResult->fetch_assoc()['total'];


/* =========================
   TEACHERS
========================= */

$teachers = $conn->query("
    SELECT
        id,
        teacher_id,
        full_name
    FROM teachers
    ORDER BY full_name ASC
");

?>

<!DOCTYPE html>

<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport"
      content="width=device-width, initial-scale=1.0">

<title>Salary | School Management System</title>

<link rel="stylesheet"
      href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">


<style>

/* =====================================================
   ROOT / LIGHT MODE
===================================================== */

:root {

    --body-bg: #f8fafc;
    --text-color: #0f172a;
    --text-secondary: #64748b;

    --card-bg: #ffffff;
    --topbar-bg: #ffffff;

    --border-color: #e2e8f0;
    --input-border: #cbd5e1;

    --sidebar-bg: #0f172a;
    --sidebar-text: #cbd5e1;
    --sidebar-hover: #1e293b;

    --primary: #2563eb;
    --primary-hover: #1d4ed8;

    --table-head: #f8fafc;
    --hover-bg: #f8fafc;

    --icon-bg: #dbeafe;
    --icon-color: #2563eb;

    --modal-overlay: rgba(15,23,42,.55);

    --success-bg: #dcfce7;
    --success-text: #166534;
    --success-border: #bbf7d0;

    --error-bg: #fee2e2;
    --error-text: #991b1b;
    --error-border: #fecaca;

    --pending-bg: #fef3c7;
    --pending-text: #92400e;

    --net-bg: #eff6ff;
    --net-border: #bfdbfe;

    --secondary-bg: #e2e8f0;
    --secondary-text: #334155;

    --shadow: 0 4px 15px rgba(15,23,42,.06);
}


/* =====================================================
   DARK MODE
===================================================== */

body.dark-mode {

    --body-bg: #0b1120;
    --text-color: #e5e7eb;
    --text-secondary: #94a3b8;

    --card-bg: #111827;
    --topbar-bg: #111827;

    --border-color: #243244;
    --input-border: #334155;

    --sidebar-bg: #020617;
    --sidebar-text: #cbd5e1;
    --sidebar-hover: #172033;

    --primary: #3b82f6;
    --primary-hover: #60a5fa;

    --table-head: #172033;
    --hover-bg: #172033;

    --icon-bg: #172554;
    --icon-color: #60a5fa;

    --modal-overlay: rgba(0,0,0,.72);

    --success-bg: #052e1b;
    --success-text: #86efac;
    --success-border: #166534;

    --error-bg: #450a0a;
    --error-text: #fca5a5;
    --error-border: #991b1b;

    --pending-bg: #422006;
    --pending-text: #fcd34d;

    --net-bg: #172554;
    --net-border: #1e40af;

    --secondary-bg: #1e293b;
    --secondary-text: #cbd5e1;

    --shadow: 0 4px 20px rgba(0,0,0,.25);
}


/* =====================================================
   RESET
===================================================== */

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

html {
    scroll-behavior: smooth;
}

body {
    font-family: Arial, Helvetica, sans-serif;
    background: var(--body-bg);
    color: var(--text-color);
    transition:
        background-color .3s ease,
        color .3s ease;
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

button {
    transition: .2s ease;
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

    transition: .3s;
}

.logo {

    height: 70px;
    min-height: 70px;

    display: flex;
    align-items: center;

    gap: 12px;

    padding: 0 20px;

    border-bottom: 1px solid rgba(255,255,255,.08);
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
    font-size: 17px;
}

.logo-text span {
    font-size: 11px;
    color: #94a3b8;
}

.menu {

    list-style: none;
    padding: 0 12px;
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

    color: var(--sidebar-text);

    border-radius: 9px;

    margin-bottom: 4px;

    font-size: 14px;

    transition: .2s;
}

.menu a:hover {

    background: var(--sidebar-hover);
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

    margin-top: auto;

    padding: 15px 12px;

    border-top: 1px solid rgba(255,255,255,.08);
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

    transition: .3s;
}


/* =====================================================
   TOPBAR
===================================================== */

.topbar {

    height: 70px;

    background: var(--topbar-bg);

    border-bottom: 1px solid var(--border-color);

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

.menu-btn {

    display: none;

    border: none;

    background: transparent;

    font-size: 23px;

    color: var(--text-color);

    cursor: pointer;
}

.topbar-right {

    display: flex;
    align-items: center;

    gap: 12px;
}


/* =====================================================
   THEME TOGGLE
===================================================== */

.theme-toggle {

    width: 40px;
    height: 40px;

    border-radius: 50%;

    border: 1px solid var(--border-color);

    background: var(--card-bg);

    color: var(--text-color);

    display: flex;
    align-items: center;
    justify-content: center;

    cursor: pointer;

    font-size: 17px;

    transition: .25s ease;
}

.theme-toggle:hover {

    background: var(--hover-bg);

    color: var(--primary);

    transform: rotate(15deg);
}

.theme-toggle .fa-sun {
    display: none;
}

body.dark-mode .theme-toggle .fa-moon {
    display: none;
}

body.dark-mode .theme-toggle .fa-sun {
    display: block;
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

    border: 2px solid var(--topbar-bg);

    line-height: 1;
}


.user {

    display: flex;
    align-items: center;

    gap: 10px;

    margin-left: 5px;
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

    color: var(--text-secondary);

    font-size: 14px;
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
}

.btn-primary:hover {
    background: var(--primary-hover);
}


/* =====================================================
   ALERT
===================================================== */

.alert {

    padding: 13px 16px;

    border-radius: 9px;

    margin-bottom: 20px;

    font-size: 14px;
}

.alert.success {

    background: var(--success-bg);

    color: var(--success-text);

    border: 1px solid var(--success-border);
}

.alert.error {

    background: var(--error-bg);

    color: var(--error-text);

    border: 1px solid var(--error-border);
}


/* =====================================================
   STATISTICS
===================================================== */

.stats {

    display: grid;

    grid-template-columns: repeat(4, 1fr);

    gap: 18px;

    margin-bottom: 25px;
}

.stat-card {

    background: var(--card-bg);

    border: 1px solid var(--border-color);

    border-radius: 14px;

    padding: 20px;

    display: flex;
    align-items: center;

    gap: 15px;

    box-shadow: var(--shadow);

    transition:
        background-color .3s ease,
        border-color .3s ease,
        transform .2s ease;
}

.stat-card:hover {

    transform: translateY(-2px);
}

.stat-icon {

    width: 48px;
    height: 48px;

    border-radius: 12px;

    background: var(--icon-bg);

    color: var(--icon-color);

    display: flex;
    align-items: center;
    justify-content: center;

    font-size: 20px;
}

.stat-info span {

    display: block;

    color: var(--text-secondary);

    font-size: 12px;

    margin-bottom: 5px;
}

.stat-info strong {

    font-size: 20px;

    color: var(--text-color);
}


/* =====================================================
   CARD
===================================================== */

.card {

    background: var(--card-bg);

    border: 1px solid var(--border-color);

    border-radius: 14px;

    overflow: hidden;

    box-shadow: var(--shadow);

    transition:
        background-color .3s ease,
        border-color .3s ease;
}

.card-header {

    padding: 20px;

    border-bottom: 1px solid var(--border-color);

    display: flex;
    align-items: center;
    justify-content: space-between;

    gap: 15px;
}

.card-header h2 {

    font-size: 18px;

    color: var(--text-color);
}

.filters {

    display: flex;

    gap: 10px;

    align-items: center;
}

.search-box {

    position: relative;

    width: 270px;
}

.search-box i {

    position: absolute;

    left: 13px;
    top: 50%;

    transform: translateY(-50%);

    color: var(--text-secondary);
}

.search-box input {

    width: 100%;

    height: 40px;

    border: 1px solid var(--input-border);

    border-radius: 8px;

    padding: 0 12px 0 38px;

    outline: none;

    background: var(--card-bg);

    color: var(--text-color);
}

.search-box input::placeholder {

    color: var(--text-secondary);
}

.search-box input:focus {

    border-color: var(--primary);
}

.filters select {

    height: 40px;

    border: 1px solid var(--input-border);

    border-radius: 8px;

    padding: 0 10px;

    outline: none;

    background: var(--card-bg);

    color: var(--text-color);

    cursor: pointer;
}

.filters select:focus {

    border-color: var(--primary);
}


/* =====================================================
   TABLE
===================================================== */

.table-wrapper {
    overflow-x: auto;
}

table {

    width: 100%;

    border-collapse: collapse;

    min-width: 1100px;
}

th,
td {

    padding: 15px 18px;

    text-align: left;

    border-bottom: 1px solid var(--border-color);

    font-size: 13px;
}

th {

    background: var(--table-head);

    color: var(--text-secondary);

    font-size: 11px;

    text-transform: uppercase;
}

td {

    color: var(--text-color);
}

tbody tr {

    transition: .2s;
}

tbody tr:hover {

    background: var(--hover-bg);
}

.teacher {

    display: flex;

    align-items: center;

    gap: 10px;
}

.teacher-avatar {

    width: 36px;
    height: 36px;

    border-radius: 50%;

    background: var(--icon-bg);

    color: var(--icon-color);

    display: flex;
    align-items: center;
    justify-content: center;

    font-weight: bold;
}

.teacher-info strong {

    display: block;

    color: var(--text-color);
}

.teacher-info span {

    color: var(--text-secondary);

    font-size: 11px;
}

.money {

    font-weight: bold;
}

.net {

    color: var(--primary);

    font-weight: bold;
}

.badge {

    display: inline-block;

    padding: 5px 10px;

    border-radius: 20px;

    font-size: 11px;

    font-weight: bold;
}

.badge.paid {

    background: var(--success-bg);

    color: var(--success-text);
}

.badge.pending {

    background: var(--pending-bg);

    color: var(--pending-text);
}

.actions {

    display: flex;

    gap: 7px;
}

.action-btn {

    width: 34px;
    height: 34px;

    border: none;

    border-radius: 7px;

    cursor: pointer;

    display: flex;
    align-items: center;
    justify-content: center;
}

.edit-btn {

    background: var(--icon-bg);

    color: var(--icon-color);
}

.delete-btn {

    background: var(--error-bg);

    color: #dc2626;
}

body.dark-mode .delete-btn {

    color: #f87171;
}

.action-btn:hover {

    transform: translateY(-1px);
}

.empty {

    text-align: center;

    padding: 45px;

    color: var(--text-secondary);
}


/* =====================================================
   MODAL
===================================================== */

.modal {

    display: none;

    position: fixed;

    inset: 0;

    background: var(--modal-overlay);

    z-index: 2000;

    align-items: center;
    justify-content: center;

    padding: 20px;
}

.modal.active {

    display: flex;
}

.modal-content {

    width: 100%;

    max-width: 700px;

    max-height: 92vh;

    overflow-y: auto;

    background: var(--card-bg);

    border-radius: 14px;

    box-shadow: 0 20px 60px rgba(0,0,0,.25);
}

.modal-header {

    padding: 20px;

    border-bottom: 1px solid var(--border-color);

    display: flex;
    align-items: center;
    justify-content: space-between;
}

.modal-header h2 {

    font-size: 19px;

    color: var(--text-color);
}

.close {

    width: 35px;
    height: 35px;

    border: none;

    border-radius: 7px;

    background: var(--secondary-bg);

    color: var(--secondary-text);

    cursor: pointer;

    font-size: 18px;
}

.close:hover {

    background: var(--error-bg);

    color: #ef4444;
}

.modal-body {

    padding: 22px;
}

.form-grid {

    display: grid;

    grid-template-columns: repeat(2,1fr);

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

    font-size: 13px;

    font-weight: bold;

    color: var(--text-color);
}

.form-group input,
.form-group select,
.form-group textarea {

    width: 100%;

    border: 1px solid var(--input-border);

    border-radius: 8px;

    padding: 11px 12px;

    outline: none;

    font-size: 14px;

    background: var(--card-bg);

    color: var(--text-color);
}

.form-group input::placeholder,
.form-group textarea::placeholder {

    color: var(--text-secondary);
}

.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus {

    border-color: var(--primary);

    box-shadow: 0 0 0 3px rgba(37,99,235,.10);
}

.form-group textarea {

    resize: vertical;

    min-height: 80px;
}

.net-preview {

    background: var(--net-bg);

    border: 1px solid var(--net-border);

    border-radius: 10px;

    padding: 14px;
}

.net-preview span {

    display: block;

    font-size: 12px;

    color: var(--text-secondary);
}

.net-preview strong {

    display: block;

    font-size: 24px;

    color: var(--primary);

    margin-top: 4px;
}

.modal-footer {

    padding: 18px 22px;

    border-top: 1px solid var(--border-color);

    display: flex;

    justify-content: flex-end;

    gap: 10px;
}

.btn-secondary {

    border: none;

    background: var(--secondary-bg);

    color: var(--secondary-text);

    padding: 11px 18px;

    border-radius: 8px;

    cursor: pointer;

    font-weight: bold;
}

.btn-secondary:hover {

    opacity: .8;
}


/* =====================================================
   OVERLAY
===================================================== */

.overlay {

    display: none;

    position: fixed;

    inset: 0;

    background: rgba(0,0,0,.45);

    z-index: 999;
}

.overlay.active {

    display: block;
}


/* =====================================================
   SCROLLBAR
===================================================== */

::-webkit-scrollbar {

    width: 8px;
    height: 8px;
}

::-webkit-scrollbar-track {

    background: var(--body-bg);
}

::-webkit-scrollbar-thumb {

    background: #64748b;

    border-radius: 20px;
}

::-webkit-scrollbar-thumb:hover {

    background: #475569;
}


/* =====================================================
   RESPONSIVE
===================================================== */

@media (max-width: 1100px) {

    .stats {

        grid-template-columns: repeat(2,1fr);
    }

}


@media (max-width: 900px) {

    .sidebar {

        transform: translateX(-100%);
    }

    .sidebar.active {

        transform: translateX(0);
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

        justify-content: space-between;

        padding: 0 20px;
    }

    .card-header {

        align-items: flex-start;

        flex-direction: column;
    }

    .filters {

        width: 100%;
    }

    .search-box {

        width: 100%;
    }

}


@media (max-width: 650px) {

    .content {

        padding: 20px 15px;
    }

    .page-header {

        flex-direction: column;

        align-items: flex-start;
    }

    .stats {

        grid-template-columns: 1fr;
    }

    .filters {

        flex-direction: column;

        align-items: stretch;
    }

    .filters select {

        width: 100%;
    }

    .form-grid {

        grid-template-columns: 1fr;
    }

    .form-group.full {

        grid-column: auto;
    }

    .user-info {

        display: none;
    }

    .topbar {

        padding: 0 15px;
    }

    .topbar-right {

        gap: 5px;
    }

    .theme-toggle,
    .notification {

        width: 38px;
        height: 38px;
    }

}


/* =====================================================
   VERY SMALL DEVICES
===================================================== */

@media (max-width: 400px) {

    .page-header h1 {

        font-size: 23px;
    }

    .btn-primary {

        padding: 10px 14px;
    }

    .modal {

        padding: 10px;
    }

    .modal-body {

        padding: 16px;
    }

    .modal-footer {

        padding: 15px;
    }

}

</style>

</head>


<body>


<!-- =========================
     SIDEBAR
========================= -->

<aside class="sidebar" id="sidebar">

    <div class="logo">

        <div class="logo-icon">
            <i class="fas fa-school"></i>
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

            <a href="salary.php" class="active">

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

            <a href="settings.php">

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


<div class="overlay" id="overlay"></div>


<!-- =========================
     MAIN
========================= -->

<main class="main">


<header class="topbar">


    <button
        class="menu-btn"
        id="menuBtn"
        type="button">

        <i class="fas fa-bars"></i>

    </button>


    <div class="topbar-right">


        <!-- =========================
             DARK / LIGHT MODE
        ========================= -->

        <button
            type="button"
            class="theme-toggle"
            id="themeToggle"
            title="Toggle Dark / Light Mode"
            aria-label="Toggle Dark / Light Mode">

            <i class="fas fa-moon"></i>

            <i class="fas fa-sun"></i>

        </button>


        <!-- =========================
             NOTIFICATION
        ========================= -->

        <a
            href="notifications.php"
            class="notification"
            title="Notifications">

            <i class="fas fa-bell"></i>

            <?php if ($unreadCount > 0): ?>

                <span class="notification-badge">

                    <?= $unreadCount > 99
                        ? "99+"
                        : $unreadCount; ?>

                </span>

            <?php endif; ?>

        </a>


        <!-- =========================
             USER
        ========================= -->

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


<section class="content">


<!-- =========================
     PAGE HEADER
========================= -->

<div class="page-header">


    <div>

        <h1>
            Salary Management
        </h1>

        <p>
            Manage teacher salaries and payroll records.
        </p>

    </div>


    <button
        class="btn-primary"
        onclick="openAddModal()">

        <i class="fas fa-plus"></i>

        Add Salary

    </button>


</div>


<!-- =========================
     ALERT
========================= -->

<?php if ($message !== ""): ?>

<div class="alert <?php echo $messageType; ?>">

    <i class="fas
        <?php

        echo $messageType === "success"
            ? "fa-circle-check"
            : "fa-circle-exclamation";

        ?>">
    </i>

    <?php

    echo htmlspecialchars(
        $message
    );

    ?>

</div>

<?php endif; ?>


<!-- =========================
     STATISTICS
========================= -->

<div class="stats">


    <!-- TOTAL SALARY -->

    <div class="stat-card">

        <div class="stat-icon">

            <i class="fas fa-money-bill-wave"></i>

        </div>


        <div class="stat-info">

            <span>
                Total Salary
            </span>

            <strong>

                $<?php

                echo number_format(
                    $totalSalary,
                    2
                );

                ?>

            </strong>

        </div>

    </div>


    <!-- TOTAL PAID -->

    <div class="stat-card">

        <div class="stat-icon">

            <i class="fas fa-check-circle"></i>

        </div>


        <div class="stat-info">

            <span>
                Total Paid
            </span>

            <strong>

                $<?php

                echo number_format(
                    $totalPaid,
                    2
                );

                ?>

            </strong>

        </div>

    </div>


    <!-- TOTAL PENDING -->

    <div class="stat-card">

        <div class="stat-icon">

            <i class="fas fa-clock"></i>

        </div>


        <div class="stat-info">

            <span>
                Total Pending
            </span>

            <strong>

                $<?php

                echo number_format(
                    $totalPending,
                    2
                );

                ?>

            </strong>

        </div>

    </div>


    <!-- RECORDS -->

    <div class="stat-card">

        <div class="stat-icon">

            <i class="fas fa-file-invoice-dollar"></i>

        </div>


        <div class="stat-info">

            <span>
                Salary Records
            </span>

            <strong>

                <?php

                echo $totalRecords;

                ?>

            </strong>

        </div>

    </div>


</div>


<!-- =========================
     TABLE
========================= -->

<div class="card">


    <div class="card-header">


        <h2>
            Salary Records
        </h2>


        <form
            method="GET"
            class="filters">


            <div class="search-box">

                <i class="fas fa-search"></i>

                <input
                    type="text"
                    name="search"
                    placeholder="Search teacher..."
                    value="<?php

                    echo htmlspecialchars(
                        $search
                    );

                    ?>">

            </div>


            <select name="status">

                <option value="">
                    All Status
                </option>


                <option
                    value="Paid"
                    <?php

                    echo $statusFilter === "Paid"
                        ? "selected"
                        : "";

                    ?>>

                    Paid

                </option>


                <option
                    value="Pending"
                    <?php

                    echo $statusFilter === "Pending"
                        ? "selected"
                        : "";

                    ?>>

                    Pending

                </option>

            </select>


            <button
                type="submit"
                class="btn-primary"
                style="height:40px;padding:0 14px;">

                <i class="fas fa-filter"></i>

                Filter

            </button>


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
                        Teacher
                    </th>

                    <th>
                        Basic Salary
                    </th>

                    <th>
                        Allowance
                    </th>

                    <th>
                        Deduction
                    </th>

                    <th>
                        Net Salary
                    </th>

                    <th>
                        Payment Date
                    </th>

                    <th>
                        Status
                    </th>

                    <th>
                        Note
                    </th>

                    <th>
                        Actions
                    </th>

                </tr>

            </thead>


            <tbody>


            <?php if ($salaryRecords->num_rows > 0): ?>


                <?php

                $count = 1;

                ?>


                <?php while ($row = $salaryRecords->fetch_assoc()): ?>


                <tr>


                    <td>

                        <?php

                        echo $count++;

                        ?>

                    </td>


                    <td>


                        <div class="teacher">


                            <div class="teacher-avatar">

                                <?php

                                echo strtoupper(
                                    substr(
                                        $row['full_name'] ?? "T",
                                        0,
                                        1
                                    )
                                );

                                ?>

                            </div>


                            <div class="teacher-info">


                                <strong>

                                    <?php

                                    echo htmlspecialchars(
                                        $row['full_name'] ?? "Unknown"
                                    );

                                    ?>

                                </strong>


                                <span>

                                    <?php

                                    echo htmlspecialchars(
                                        $row['teacher_code'] ?? "-"
                                    );

                                    ?>

                                </span>


                            </div>


                        </div>


                    </td>


                    <td class="money">

                        $

                        <?php

                        echo number_format(
                            $row['basic_salary'],
                            2
                        );

                        ?>

                    </td>


                    <td>

                        $

                        <?php

                        echo number_format(
                            $row['allowance'],
                            2
                        );

                        ?>

                    </td>


                    <td>

                        $

                        <?php

                        echo number_format(
                            $row['deduction'],
                            2
                        );

                        ?>

                    </td>


                    <td class="net">

                        $

                        <?php

                        echo number_format(
                            $row['net_salary'],
                            2
                        );

                        ?>

                    </td>


                    <td>

                        <?php

                        echo date(
                            "d M Y",
                            strtotime(
                                $row['payment_date']
                            )
                        );

                        ?>

                    </td>


                    <td>


                        <?php if ($row['status'] === "Paid"): ?>


                            <span class="badge paid">
                                Paid
                            </span>


                        <?php else: ?>


                            <span class="badge pending">
                                Pending
                            </span>


                        <?php endif; ?>


                    </td>


                    <td>

                        <?php

                        echo htmlspecialchars(
                            $row['note'] ?: "-"
                        );

                        ?>

                    </td>


                    <td>


                        <div class="actions">


                            <button
                                class="action-btn edit-btn"
                                title="Edit"
                                onclick='editSalary(<?php echo json_encode($row); ?>)'>

                                <i class="fas fa-pen"></i>

                            </button>


                            <button
                                class="action-btn delete-btn"
                                title="Delete"
                                onclick="deleteSalary(<?php echo $row['id']; ?>)">

                                <i class="fas fa-trash"></i>

                            </button>


                        </div>


                    </td>


                </tr>


                <?php endwhile; ?>


            <?php else: ?>


                <tr>

                    <td
                        colspan="10"
                        class="empty">

                        <i
                            class="fas fa-wallet"
                            style="font-size:35px;margin-bottom:10px;">

                        </i>

                        <br>

                        No salary records found.

                    </td>

                </tr>


            <?php endif; ?>


            </tbody>


        </table>


    </div>


</div>


</section>

</main>


<!-- =====================================================
     ADD / EDIT MODAL
===================================================== -->

<div
    class="modal"
    id="salaryModal">


    <div class="modal-content">


        <div class="modal-header">


            <h2 id="modalTitle">
                Add Salary
            </h2>


            <button
                class="close"
                onclick="closeModal()"
                type="button">

                <i class="fas fa-times"></i>

            </button>


        </div>


        <form
            method="POST"
            id="salaryForm">


            <div class="modal-body">


                <input
                    type="hidden"
                    name="action"
                    id="action"
                    value="add">


                <input
                    type="hidden"
                    name="id"
                    id="salaryId">


                <div class="form-grid">


                    <!-- TEACHER -->

                    <div class="form-group full">

                        <label>
                            Teacher
                        </label>


                        <select
                            name="teacher_id"
                            id="teacherId"
                            required>


                            <option value="">
                                Select Teacher
                            </option>


                            <?php

                            $teachers->data_seek(0);

                            while (
                                $teacher =
                                $teachers->fetch_assoc()
                            ):

                            ?>


                                <option
                                    value="<?php echo $teacher['id']; ?>">

                                    <?php

                                    echo htmlspecialchars(
                                        $teacher['full_name']
                                    );

                                    ?>

                                    -

                                    <?php

                                    echo htmlspecialchars(
                                        $teacher['teacher_id']
                                    );

                                    ?>

                                </option>


                            <?php endwhile; ?>


                        </select>


                    </div>


                    <!-- BASIC SALARY -->

                    <div class="form-group">

                        <label>
                            Basic Salary
                        </label>


                        <input
                            type="number"
                            name="basic_salary"
                            id="basicSalary"
                            min="0"
                            step="0.01"
                            value="0"
                            required>

                    </div>


                    <!-- ALLOWANCE -->

                    <div class="form-group">

                        <label>
                            Allowance
                        </label>


                        <input
                            type="number"
                            name="allowance"
                            id="allowance"
                            min="0"
                            step="0.01"
                            value="0">

                    </div>


                    <!-- DEDUCTION -->

                    <div class="form-group">

                        <label>
                            Deduction
                        </label>


                        <input
                            type="number"
                            name="deduction"
                            id="deduction"
                            min="0"
                            step="0.01"
                            value="0">

                    </div>


                    <!-- PAYMENT DATE -->

                    <div class="form-group">

                        <label>
                            Payment Date
                        </label>


                        <input
                            type="date"
                            name="payment_date"
                            id="paymentDate"
                            value="<?php echo date('Y-m-d'); ?>"
                            required>

                    </div>


                    <!-- STATUS -->

                    <div class="form-group">

                        <label>
                            Status
                        </label>


                        <select
                            name="status"
                            id="status">


                            <option value="Pending">
                                Pending
                            </option>


                            <option value="Paid">
                                Paid
                            </option>


                        </select>

                    </div>


                    <!-- NET SALARY -->

                    <div class="form-group">

                        <label>
                            Net Salary
                        </label>


                        <div class="net-preview">


                            <span>
                                Basic + Allowance - Deduction
                            </span>


                            <strong id="netPreview">
                                $0.00
                            </strong>


                        </div>


                    </div>


                    <!-- NOTE -->

                    <div class="form-group full">

                        <label>
                            Note
                        </label>


                        <textarea
                            name="note"
                            id="note"
                            placeholder="Optional note..."></textarea>


                    </div>


                </div>


            </div>


            <div class="modal-footer">


                <button
                    type="button"
                    class="btn-secondary"
                    onclick="closeModal()">

                    Cancel

                </button>


                <button
                    type="submit"
                    class="btn-primary">

                    <i class="fas fa-save"></i>


                    <span id="saveText">
                        Save Salary
                    </span>


                </button>


            </div>


        </form>


    </div>


</div>


<script>

/* =====================================================
   DARK / LIGHT MODE
===================================================== */

const themeToggle =
    document.getElementById("themeToggle");


/*
   Check previously saved theme.
   If no theme exists, use light mode.
*/

const savedTheme =
    localStorage.getItem("schoolTheme");


if (savedTheme === "dark") {

    document.body.classList.add("dark-mode");

}


/*
   Toggle theme
*/

themeToggle.addEventListener("click", function () {

    document.body.classList.toggle("dark-mode");


    if (
        document.body.classList.contains("dark-mode")
    ) {

        localStorage.setItem(
            "schoolTheme",
            "dark"
        );

    } else {

        localStorage.setItem(
            "schoolTheme",
            "light"
        );

    }

});


/* =====================================================
   SIDEBAR
===================================================== */

const sidebar =
    document.getElementById("sidebar");

const overlay =
    document.getElementById("overlay");

const menuBtn =
    document.getElementById("menuBtn");


menuBtn.addEventListener(
    "click",
    function() {

        sidebar.classList.toggle("active");

        overlay.classList.toggle("active");

    }
);


overlay.addEventListener(
    "click",
    function() {

        sidebar.classList.remove("active");

        overlay.classList.remove("active");

    }
);


/* =====================================================
   MODAL
===================================================== */

const modal =
    document.getElementById("salaryModal");

const salaryForm =
    document.getElementById("salaryForm");

const actionInput =
    document.getElementById("action");

const salaryId =
    document.getElementById("salaryId");

const modalTitle =
    document.getElementById("modalTitle");

const saveText =
    document.getElementById("saveText");


/* =====================================================
   OPEN ADD MODAL
===================================================== */

function openAddModal() {

    salaryForm.reset();

    actionInput.value = "add";

    salaryId.value = "";

    modalTitle.textContent =
        "Add Salary";

    saveText.textContent =
        "Save Salary";


    document.getElementById(
        "paymentDate"
    ).value =
        new Date()
            .toISOString()
            .split("T")[0];


    calculateNet();


    modal.classList.add("active");

}


/* =====================================================
   CLOSE MODAL
===================================================== */

function closeModal() {

    modal.classList.remove("active");

}


/* =====================================================
   CLOSE MODAL BY CLICKING OUTSIDE
===================================================== */

window.addEventListener(
    "click",
    function(e) {

        if (e.target === modal) {

            closeModal();

        }

    }
);


/* =====================================================
   EDIT SALARY
===================================================== */

function editSalary(data) {

    actionInput.value =
        "update";


    salaryId.value =
        data.id;


    modalTitle.textContent =
        "Edit Salary";


    saveText.textContent =
        "Update Salary";


    document.getElementById(
        "teacherId"
    ).value =
        data.teacher_id;


    document.getElementById(
        "basicSalary"
    ).value =
        data.basic_salary;


    document.getElementById(
        "allowance"
    ).value =
        data.allowance;


    document.getElementById(
        "deduction"
    ).value =
        data.deduction;


    document.getElementById(
        "paymentDate"
    ).value =
        data.payment_date;


    document.getElementById(
        "status"
    ).value =
        data.status;


    document.getElementById(
        "note"
    ).value =
        data.note || "";


    calculateNet();


    modal.classList.add("active");

}


/* =====================================================
   DELETE
===================================================== */

function deleteSalary(id) {

    if (
        confirm(
            "Are you sure you want to delete this salary record?"
        )
    ) {

        window.location.href =
            "salary.php?delete=" + id;

    }

}


/* =====================================================
   CALCULATE NET SALARY
===================================================== */

const basicSalary =
    document.getElementById(
        "basicSalary"
    );

const allowance =
    document.getElementById(
        "allowance"
    );

const deduction =
    document.getElementById(
        "deduction"
    );

const netPreview =
    document.getElementById(
        "netPreview"
    );


function calculateNet() {

    const basic =
        parseFloat(
            basicSalary.value
        ) || 0;


    const allow =
        parseFloat(
            allowance.value
        ) || 0;


    const deduct =
        parseFloat(
            deduction.value
        ) || 0;


    const net =
        basic +
        allow -
        deduct;


    netPreview.textContent =
        "$" + net.toFixed(2);

}


basicSalary.addEventListener(
    "input",
    calculateNet
);


allowance.addEventListener(
    "input",
    calculateNet
);


deduction.addEventListener(
    "input",
    calculateNet
);


calculateNet();


/* =====================================================
   ESC KEY
===================================================== */

document.addEventListener(
    "keydown",
    function(e) {

        if (e.key === "Escape") {

            closeModal();

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