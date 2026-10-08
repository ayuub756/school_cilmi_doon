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
   DELETE FEE
========================= */
if (isset($_GET['delete'])) {

    $id = intval($_GET['delete']);

    $stmt = $conn->prepare("DELETE FROM fees WHERE id = ?");
    $stmt->bind_param("i", $id);

    if ($stmt->execute()) {

        $message = "Fee deleted successfully.";
        $messageType = "success";

    } else {

        $message = "Failed to delete fee.";
        $messageType = "error";
    }

    $stmt->close();
}

/* =========================
   ADD / UPDATE FEE
========================= */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $action = $_POST['action'] ?? "";

    $student_id = intval($_POST['student_id'] ?? 0);
    $fee_type = trim($_POST['fee_type'] ?? "");
    $amount = floatval($_POST['amount'] ?? 0);

    $due_date = !empty($_POST['due_date'])
        ? $_POST['due_date']
        : null;

    $status = $_POST['status'] ?? "Unpaid";

    /* =========================
       ADD FEE
    ========================= */
    if ($action === "add") {

        if (
            $student_id <= 0 ||
            $fee_type === "" ||
            $amount <= 0
        ) {

            $message = "Please fill in all required fields.";
            $messageType = "error";

        } else {

            $stmt = $conn->prepare("
                INSERT INTO fees
                (
                    student_id,
                    fee_type,
                    amount,
                    due_date,
                    status
                )
                VALUES (?, ?, ?, ?, ?)
            ");

            $stmt->bind_param(
                "isdss",
                $student_id,
                $fee_type,
                $amount,
                $due_date,
                $status
            );

            if ($stmt->execute()) {

                $message = "Fee added successfully.";
                $messageType = "success";

                createNotification(
                    $conn,
                    (int) $_SESSION['user_id'],
                    "New Fee Added",
                    "A new student fee has been successfully added.",
                    "success",
                    "fees.php"
                );

            } else {

                $message = "Failed to add fee.";
                $messageType = "error";
            }

            $stmt->close();
        }
    }

    /* =========================
       UPDATE FEE
    ========================= */
    if ($action === "update") {

        $id = intval($_POST['id'] ?? 0);

        if (
            $id <= 0 ||
            $student_id <= 0 ||
            $fee_type === "" ||
            $amount <= 0
        ) {

            $message = "Please fill in all required fields.";
            $messageType = "error";

        } else {

            $stmt = $conn->prepare("
                UPDATE fees
                SET
                    student_id = ?,
                    fee_type = ?,
                    amount = ?,
                    due_date = ?,
                    status = ?
                WHERE id = ?
            ");

            $stmt->bind_param(
                "isdssi",
                $student_id,
                $fee_type,
                $amount,
                $due_date,
                $status,
                $id
            );

            if ($stmt->execute()) {

                $message = "Fee updated successfully.";
                $messageType = "success";

            } else {

                $message = "Failed to update fee.";
                $messageType = "error";
            }

            $stmt->close();
        }
    }
}

/* =========================
   EDIT DATA
========================= */

$editFee = null;

if (isset($_GET['edit'])) {

    $id = intval($_GET['edit']);

    $stmt = $conn->prepare("
        SELECT
            id,
            student_id,
            fee_type,
            amount,
            due_date,
            status
        FROM fees
        WHERE id = ?
    ");

    $stmt->bind_param("i", $id);
    $stmt->execute();

    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $editFee = $result->fetch_assoc();
    }

    $stmt->close();
}

/* =========================
   STATISTICS
========================= */

$totalFees = 0;
$totalAmount = 0;
$totalPaid = 0;
$totalPartial = 0;
$totalUnpaid = 0;

$result = $conn->query("
    SELECT
        COUNT(*) AS total,
        COALESCE(SUM(amount), 0) AS amount,
        COALESCE(SUM(status = 'Paid'), 0) AS paid,
        COALESCE(SUM(status = 'Partial'), 0) AS partial,
        COALESCE(SUM(status = 'Unpaid'), 0) AS unpaid
    FROM fees
");

if ($result) {

    $stats = $result->fetch_assoc();

    $totalFees = $stats['total'] ?? 0;
    $totalAmount = $stats['amount'] ?? 0;
    $totalPaid = $stats['paid'] ?? 0;
    $totalPartial = $stats['partial'] ?? 0;
    $totalUnpaid = $stats['unpaid'] ?? 0;
}

/* =========================
   SEARCH & FILTER
========================= */

$search = trim($_GET['search'] ?? "");
$filterStatus = $_GET['status'] ?? "";

$sql = "
    SELECT
        f.id,
        f.student_id,
        f.fee_type,
        f.amount,
        f.due_date,
        f.status,
        f.created_at,
        s.full_name,
        s.student_id AS student_code
    FROM fees f
    LEFT JOIN students s
        ON f.student_id = s.id
    WHERE 1=1
";

$params = [];
$types = "";

if ($search !== "") {

    $sql .= "
        AND (
            s.full_name LIKE ?
            OR s.student_id LIKE ?
            OR f.fee_type LIKE ?
        )
    ";

    $searchValue = "%" . $search . "%";

    $params[] = $searchValue;
    $params[] = $searchValue;
    $params[] = $searchValue;

    $types .= "sss";
}

if ($filterStatus !== "") {

    $sql .= " AND f.status = ?";

    $params[] = $filterStatus;
    $types .= "s";
}

$sql .= " ORDER BY f.id DESC";

$stmt = $conn->prepare($sql);

if (!empty($params)) {

    $stmt->bind_param(
        $types,
        ...$params
    );
}

$stmt->execute();

$feesResult = $stmt->get_result();

/* =========================
   STUDENTS
========================= */

$students = $conn->query("
    SELECT
        id,
        student_id,
        full_name
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
    content="width=device-width, initial-scale=1.0">

<title>Fees Management | School Management</title>

<!-- =========================
     EARLY THEME
========================= -->

<script>
(function () {

    const theme = localStorage.getItem("theme");

    if (theme === "dark") {
        document.documentElement.classList.add("dark-mode");
    }

})();
</script>

<link
    rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

<style>

/* =========================
   RESET
========================= */

*{
    margin:0;
    padding:0;
    box-sizing:border-box;
    font-family:Arial, Helvetica, sans-serif;
}

html{
    scroll-behavior:smooth;
}

body{
    background:#f8fafc;
    color:#0f172a;
    transition:
        background .25s ease,
        color .25s ease;
}

/* =========================
   SIDEBAR
========================= */

.sidebar{
    position:fixed;
    left:0;
    top:0;
    width:260px;
    height:100vh;
    background:#0f172a;
    color:white;
    z-index:1000;
    display:flex;
    flex-direction:column;
    overflow-y:auto;
    overflow-x:hidden;
    transition:.3s;
}

.logo{
    height:70px;
    min-height:70px;
    display:flex;
    align-items:center;
    gap:12px;
    padding:0 20px;
    border-bottom:1px solid rgba(255,255,255,.08);
}

.logo-icon{
    width:45px;
    height:45px;
    border-radius:10px;
    background:#2563eb;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:20px;
}

.logo h2{
    font-size:17px;
}

.menu{
    list-style:none;
    padding:0 12px;
    margin:0;
}

.menu-title{
    color:#64748b;
    font-size:11px;
    font-weight:bold;
    margin:20px 8px 8px;
    text-transform:uppercase;
    letter-spacing:.5px;
}

.menu a{
    display:flex;
    align-items:center;
    gap:13px;
    padding:12px 13px;
    color:#cbd5e1;
    border-radius:9px;
    margin-bottom:4px;
    font-size:14px;
    text-decoration:none;
    transition:.2s;
}

.menu a:hover{
    background:#1e293b;
    color:white;
}

.menu a.active{
    background:#2563eb;
    color:white;
}

.menu a i{
    width:20px;
    min-width:20px;
    text-align:center;
}

/* =========================
   LOGOUT
========================= */

.logout{
    position:static;
    margin-top:auto;
    padding:15px 12px;
    border-top:1px solid rgba(255,255,255,.08);
}

.logout a{
    display:flex;
    align-items:center;
    gap:13px;
    padding:12px 13px;
    color:#fca5a5;
    border-radius:9px;
    font-size:14px;
    text-decoration:none;
}

.logout a:hover{
    background:#7f1d1d;
    color:white;
}

/* =========================
   MAIN
========================= */

.main{
    margin-left:260px;
    min-height:100vh;
    transition:.3s;
}

/* =========================
   TOPBAR
========================= */

.topbar{
    height:70px;
    background:white;
    border-bottom:1px solid #e2e8f0;
    display:flex;
    align-items:center;
    justify-content:flex-end;
    padding:0 30px;
    position:sticky;
    top:0;
    z-index:900;
    transition:.25s;
}

.user-area{
    display:flex;
    align-items:center;
    gap:12px;
}

/* =========================
   MENU BUTTON
========================= */

.menu-btn{
    display:none;
    width:40px;
    height:40px;
    align-items:center;
    justify-content:center;
    background:white;
    border:1px solid #e2e8f0;
    border-radius:50%;
    font-size:18px;
    color:#0f172a;
    cursor:pointer;
}

/* =========================
   THEME TOGGLE
========================= */

.theme-toggle{
    width:40px;
    height:40px;
    border:1px solid #e2e8f0;
    border-radius:50%;
    background:#ffffff;
    color:#475569;
    display:flex;
    align-items:center;
    justify-content:center;
    cursor:pointer;
    font-size:17px;
    transition:all .25s ease;
}

.theme-toggle:hover{
    background:#f1f5f9;
    color:#2563eb;
}

/* =========================
   NOTIFICATION
========================= */

.notification{
    width:40px;
    height:40px;
    border-radius:50%;
    display:flex;
    align-items:center;
    justify-content:center;
    position:relative;
    margin-right:2px;
    color:#475569;
    font-size:19px;
    cursor:pointer;
    text-decoration:none;
    transition:.2s;
}

.notification:hover{
    background:#f1f5f9;
    color:#2563eb;
}

.notification-badge{
    position:absolute;
    top:-3px;
    right:-3px;
    min-width:19px;
    height:19px;
    padding:0 5px;
    background:#ef4444;
    color:white;
    border-radius:20px;
    font-size:10px;
    font-weight:bold;
    display:flex;
    align-items:center;
    justify-content:center;
    border:2px solid white;
    line-height:1;
}

/* =========================
   AVATAR
========================= */

.avatar{
    width:40px;
    height:40px;
    border-radius:50%;
    background:#2563eb;
    color:white;
    display:flex;
    align-items:center;
    justify-content:center;
    font-weight:bold;
    flex-shrink:0;
}

.user-info strong{
    display:block;
    font-size:14px;
}

.user-info small{
    color:#64748b;
    font-size:12px;
}

/* =========================
   CONTENT
========================= */

.content{
    padding:30px;
    transition:.25s;
}

.page-header{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:20px;
    margin-bottom:25px;
}

.page-header h1{
    font-size:25px;
    margin-bottom:5px;
}

.page-header p{
    color:#64748b;
    font-size:14px;
}

/* =========================
   BUTTON
========================= */

.btn{
    border:none;
    padding:11px 17px;
    border-radius:8px;
    cursor:pointer;
    font-weight:600;
    font-size:14px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:8px;
    transition:.2s;
}

.btn-primary{
    background:#2563eb;
    color:white;
}

.btn-primary:hover{
    background:#1d4ed8;
}

.btn:active{
    transform:scale(.98);
}

/* =========================
   ALERT
========================= */

.alert{
    padding:13px 16px;
    border-radius:9px;
    margin-bottom:20px;
    font-size:14px;
}

.alert-success{
    background:#dcfce7;
    color:#166534;
    border:1px solid #bbf7d0;
}

.alert-error{
    background:#fee2e2;
    color:#991b1b;
    border:1px solid #fecaca;
}

/* =========================
   STATS
========================= */

.stats{
    display:grid;
    grid-template-columns:repeat(4,1fr);
    gap:18px;
    margin-bottom:25px;
}

.stat-card{
    background:white;
    border:1px solid #e2e8f0;
    border-radius:14px;
    padding:20px;
    display:flex;
    align-items:center;
    gap:15px;
    transition:.25s;
}

.stat-card:hover{
    transform:translateY(-2px);
    box-shadow:0 8px 20px rgba(15,23,42,.06);
}

.stat-icon{
    width:48px;
    height:48px;
    min-width:48px;
    border-radius:10px;
    display:flex;
    align-items:center;
    justify-content:center;
    background:#eff6ff;
    color:#2563eb;
    font-size:20px;
}

.stat-card:nth-child(2) .stat-icon{
    background:#ecfdf5;
    color:#059669;
}

.stat-card:nth-child(3) .stat-icon{
    background:#fff7ed;
    color:#ea580c;
}

.stat-card:nth-child(4) .stat-icon{
    background:#fef2f2;
    color:#dc2626;
}

.stat-card h3{
    font-size:21px;
    margin-bottom:3px;
}

.stat-card p{
    color:#64748b;
    font-size:13px;
}

/* =========================
   CARD
========================= */

.card{
    background:white;
    border:1px solid #e2e8f0;
    border-radius:14px;
    margin-bottom:25px;
    overflow:hidden;
    transition:.25s;
}

.card-header{
    padding:18px 20px;
    border-bottom:1px solid #e2e8f0;
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:15px;
}

.card-header h2{
    font-size:17px;
}

/* =========================
   FORM
========================= */

.form-grid{
    padding:20px;
    display:grid;
    grid-template-columns:repeat(3,1fr);
    gap:18px;
}

.form-group label{
    display:block;
    font-size:13px;
    font-weight:600;
    margin-bottom:7px;
}

.form-control{
    width:100%;
    padding:11px 12px;
    border:1px solid #cbd5e1;
    border-radius:8px;
    outline:none;
    font-size:14px;
    background:white;
    color:#0f172a;
    transition:.2s;
}

.form-control:focus{
    border-color:#2563eb;
    box-shadow:0 0 0 3px rgba(37,99,235,.1);
}

.form-actions{
    padding:0 20px 20px;
    display:flex;
    gap:10px;
}

/* =========================
   FILTER
========================= */

.filters{
    padding:18px 20px;
    display:flex;
    gap:12px;
    border-bottom:1px solid #e2e8f0;
}

.search-box{
    flex:1;
    position:relative;
}

.search-box i{
    position:absolute;
    left:13px;
    top:13px;
    color:#94a3b8;
}

.search-box input{
    width:100%;
    padding:11px 12px 11px 38px;
    border:1px solid #cbd5e1;
    border-radius:8px;
    outline:none;
    background:white;
    color:#0f172a;
    transition:.2s;
}

.search-box input:focus{
    border-color:#2563eb;
    box-shadow:0 0 0 3px rgba(37,99,235,.1);
}

.filters select{
    width:180px;
    padding:11px;
    border:1px solid #cbd5e1;
    border-radius:8px;
    outline:none;
    background:white;
    color:#0f172a;
}

/* =========================
   TABLE
========================= */

.table-container{
    width:100%;
    overflow-x:auto;
    -webkit-overflow-scrolling:touch;
}

table{
    width:100%;
    border-collapse:collapse;
    min-width:900px;
}

thead{
    background:#f8fafc;
}

th{
    text-align:left;
    padding:13px 18px;
    font-size:12px;
    color:#64748b;
    text-transform:uppercase;
    border-bottom:1px solid #e2e8f0;
    white-space:nowrap;
}

td{
    padding:14px 18px;
    border-bottom:1px solid #f1f5f9;
    font-size:13px;
    white-space:nowrap;
}

tbody tr{
    transition:.15s;
}

tbody tr:hover{
    background:#f8fafc;
}

/* =========================
   STUDENT
========================= */

.student{
    display:flex;
    align-items:center;
    gap:10px;
}

.student-avatar{
    width:36px;
    height:36px;
    min-width:36px;
    background:#dbeafe;
    color:#2563eb;
    border-radius:50%;
    display:flex;
    align-items:center;
    justify-content:center;
    font-weight:bold;
}

.student-name{
    font-weight:600;
}

.student-code{
    font-size:11px;
    color:#64748b;
    margin-top:2px;
}

/* =========================
   BADGES
========================= */

.badge{
    padding:5px 9px;
    border-radius:20px;
    font-size:11px;
    font-weight:bold;
    display:inline-block;
}

.badge-paid{
    background:#dcfce7;
    color:#166534;
}

.badge-partial{
    background:#fef3c7;
    color:#92400e;
}

.badge-unpaid{
    background:#fee2e2;
    color:#991b1b;
}

/* =========================
   ACTIONS
========================= */

.actions{
    display:flex;
    gap:7px;
}

.action-btn{
    width:32px;
    height:32px;
    border:none;
    border-radius:7px;
    cursor:pointer;
    display:flex;
    align-items:center;
    justify-content:center;
    text-decoration:none;
    transition:.2s;
}

.action-btn:hover{
    transform:translateY(-1px);
}

.edit-btn{
    background:#eff6ff;
    color:#2563eb;
}

.delete-btn{
    background:#fef2f2;
    color:#dc2626;
}

/* =========================
   EMPTY
========================= */

.empty{
    text-align:center;
    padding:50px 20px;
    color:#64748b;
}

.empty i{
    display:block;
    font-size:40px;
    margin-bottom:12px;
    color:#cbd5e1;
}

/* =========================
   MODAL
========================= */

.modal{
    display:none;
    position:fixed;
    inset:0;
    background:rgba(15,23,42,.6);
    z-index:2000;
    align-items:center;
    justify-content:center;
    padding:20px;
}

.modal.active{
    display:flex;
}

.modal-content{
    background:white;
    width:100%;
    max-width:600px;
    max-height:90vh;
    overflow-y:auto;
    border-radius:14px;
    overflow-x:hidden;
}

.modal-header{
    padding:18px 20px;
    border-bottom:1px solid #e2e8f0;
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:15px;
}

.modal-header h2{
    font-size:18px;
}

.close{
    background:none;
    border:none;
    font-size:22px;
    cursor:pointer;
    color:#64748b;
    transition:.2s;
}

.close:hover{
    color:#dc2626;
}

.modal-body{
    padding:20px;
}

.modal-grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:17px;
}

.modal-footer{
    padding:15px 20px;
    border-top:1px solid #e2e8f0;
    display:flex;
    justify-content:flex-end;
    gap:10px;
}

/* =========================
   OVERLAY
========================= */

.overlay{
    display:none;
    position:fixed;
    inset:0;
    background:rgba(0,0,0,.5);
    z-index:999;
}

.overlay.active{
    display:block;
}

/* ==================================================
   DARK MODE
================================================== */

html.dark-mode body{
    background:#0f172a;
    color:#e2e8f0;
}

html.dark-mode .topbar{
    background:#1e293b;
    border-color:#334155;
}

html.dark-mode .theme-toggle{
    background:#334155;
    border-color:#475569;
    color:#f8fafc;
}

html.dark-mode .theme-toggle:hover{
    background:#475569;
    color:#facc15;
}

html.dark-mode .menu-btn{
    background:#1e293b;
    border-color:#475569;
    color:#f8fafc;
}

html.dark-mode .notification{
    color:#cbd5e1;
}

html.dark-mode .notification:hover{
    background:#334155;
    color:#60a5fa;
}

html.dark-mode .notification-badge{
    border-color:#1e293b;
}

html.dark-mode .user-info strong{
    color:#f8fafc;
}

html.dark-mode .user-info small{
    color:#94a3b8;
}

html.dark-mode .page-header h1{
    color:#f8fafc;
}

html.dark-mode .page-header p{
    color:#94a3b8;
}

html.dark-mode .stat-card,
html.dark-mode .card,
html.dark-mode .modal-content{
    background:#1e293b;
    border-color:#334155;
}

html.dark-mode .stat-card:hover{
    box-shadow:0 8px 20px rgba(0,0,0,.25);
}

html.dark-mode .stat-card p{
    color:#94a3b8;
}

html.dark-mode .stat-card h3{
    color:#f8fafc;
}

html.dark-mode .stat-icon{
    background:#334155;
    color:#60a5fa;
}

html.dark-mode .stat-card:nth-child(2) .stat-icon{
    background:rgba(5,150,105,.15);
    color:#34d399;
}

html.dark-mode .stat-card:nth-child(3) .stat-icon{
    background:rgba(234,88,12,.15);
    color:#fb923c;
}

html.dark-mode .stat-card:nth-child(4) .stat-icon{
    background:rgba(220,38,38,.15);
    color:#f87171;
}

html.dark-mode .form-control,
html.dark-mode .search-box input,
html.dark-mode .filters select{
    background:#0f172a;
    color:#e2e8f0;
    border-color:#475569;
}

html.dark-mode .form-control::placeholder,
html.dark-mode .search-box input::placeholder{
    color:#64748b;
}

html.dark-mode .form-control:focus,
html.dark-mode .search-box input:focus{
    border-color:#3b82f6;
    box-shadow:0 0 0 3px rgba(37,99,235,.15);
}

html.dark-mode .form-group label{
    color:#cbd5e1;
}

html.dark-mode select option{
    background:#0f172a;
    color:#e2e8f0;
}

html.dark-mode .card-header{
    border-color:#334155;
}

html.dark-mode .card-header h2{
    color:#f8fafc;
}

html.dark-mode .filters{
    border-color:#334155;
}

html.dark-mode thead{
    background:#0f172a;
}

html.dark-mode th{
    background:#0f172a;
    color:#94a3b8;
    border-color:#334155;
}

html.dark-mode td{
    color:#cbd5e1;
    border-color:#334155;
}

html.dark-mode tbody tr:hover{
    background:#263449;
}

html.dark-mode .student-name{
    color:#f8fafc;
}

html.dark-mode .student-code{
    color:#94a3b8;
}

html.dark-mode .student-avatar{
    background:rgba(37,99,235,.18);
    color:#60a5fa;
}

html.dark-mode .badge-paid{
    background:rgba(22,163,74,.15);
    color:#86efac;
}

html.dark-mode .badge-partial{
    background:rgba(234,179,8,.15);
    color:#fde68a;
}

html.dark-mode .badge-unpaid{
    background:rgba(220,38,38,.15);
    color:#fca5a5;
}

html.dark-mode .edit-btn{
    background:rgba(37,99,235,.18);
    color:#60a5fa;
}

html.dark-mode .delete-btn{
    background:rgba(220,38,38,.18);
    color:#f87171;
}

html.dark-mode .empty{
    color:#94a3b8;
}

html.dark-mode .empty i{
    color:#475569;
}

html.dark-mode .modal-header,
html.dark-mode .modal-footer{
    border-color:#334155;
}

html.dark-mode .modal-header h2{
    color:#f8fafc;
}

html.dark-mode .close{
    color:#cbd5e1;
}

html.dark-mode .close:hover{
    color:#fca5a5;
}

html.dark-mode .alert-success{
    background:rgba(22,163,74,.15);
    color:#86efac;
    border-color:#166534;
}

html.dark-mode .alert-error{
    background:rgba(220,38,38,.15);
    color:#fca5a5;
    border-color:#991b1b;
}

html.dark-mode .modal-footer .btn:not(.btn-primary){
    background:#334155;
    color:#e2e8f0;
}

html.dark-mode .modal-footer .btn:not(.btn-primary):hover{
    background:#475569;
}

/* ==================================================
   RESPONSIVE
================================================== */

/* Large Laptop */

@media(max-width:1200px){

    .content{
        padding:25px;
    }

    .stats{
        grid-template-columns:repeat(2,1fr);
    }

    .form-grid{
        grid-template-columns:repeat(2,1fr);
    }
}

/* Tablet */

@media(max-width:900px){

    .sidebar{
        transform:translateX(-100%);
        box-shadow:10px 0 30px rgba(0,0,0,.15);
    }

    .sidebar.active{
        transform:translateX(0);
    }

    .main{
        margin-left:0;
        width:100%;
    }

    .menu-btn{
        display:flex;
    }

    .topbar{
        justify-content:flex-start;
        gap:10px;
        padding:0 20px;
    }

    .topbar .user-area{
        margin-left:auto;
    }

    .overlay{
        display:none;
        position:fixed;
        inset:0;
        background:rgba(0,0,0,.55);
        z-index:950;
    }

    .overlay.active{
        display:block;
    }
}

/* Small Tablet */

@media(max-width:700px){

    .content{
        padding:20px 15px;
    }

    .page-header{
        flex-direction:column;
        align-items:flex-start;
        gap:15px;
    }

    .page-header h1{
        font-size:21px;
    }

    .page-header .btn{
        width:100%;
    }

    .stats{
        grid-template-columns:1fr 1fr;
        gap:12px;
    }

    .stat-card{
        padding:16px;
    }

    .stat-card h3{
        font-size:19px;
    }

    .form-grid{
        grid-template-columns:1fr;
    }

    .filters{
        flex-direction:column;
    }

    .filters select{
        width:100%;
    }

    .filters .btn{
        width:100%;
    }

    .modal-grid{
        grid-template-columns:1fr;
    }

    .user-info{
        display:none;
    }

    .notification{
        margin-right:0;
    }

    .theme-toggle,
    .menu-btn,
    .notification{
        width:38px;
        height:38px;
    }
}

/* Mobile */

@media(max-width:480px){

    .topbar{
        height:62px;
        padding:0 12px;
    }

    .topbar .user-area{
        gap:6px;
    }

    .theme-toggle,
    .menu-btn,
    .notification{
        width:36px;
        height:36px;
    }

    .avatar{
        width:36px;
        height:36px;
    }

    .content{
        padding:15px 12px;
    }

    .page-header{
        margin-bottom:18px;
    }

    .page-header h1{
        font-size:20px;
    }

    .page-header p{
        font-size:12px;
    }

    .stats{
        grid-template-columns:1fr;
        gap:12px;
    }

    .stat-card{
        padding:17px;
    }

    .stat-card h3{
        font-size:22px;
    }

    .card{
        border-radius:12px;
    }

    .card-header{
        padding:15px;
    }

    .card-header h2{
        font-size:15px;
    }

    .filters{
        padding:15px;
    }

    .table-container{
        overflow-x:auto;
    }

    table{
        min-width:850px;
    }

    .modal{
        padding:12px;
    }

    .modal-content{
        max-height:94vh;
        border-radius:12px;
    }

    .modal-body{
        padding:15px;
    }

    .modal-footer{
        padding:12px 15px;
    }

    .modal-footer .btn{
        flex:1;
    }
}

/* Very Small Phones */

@media(max-width:380px){

    .content{
        padding:10px;
    }

    .topbar{
        padding:0 9px;
    }

    .topbar .user-area{
        gap:3px;
    }

    .theme-toggle,
    .menu-btn,
    .notification{
        width:34px;
        height:34px;
        font-size:14px;
    }

    .avatar{
        width:32px;
        height:32px;
        font-size:12px;
    }

    .page-header h1{
        font-size:18px;
    }

    .stat-card{
        padding:14px;
    }

    .stat-card h3{
        font-size:21px;
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
            <i class="fas fa-graduation-cap"></i>
        </div>

        <h2>Cilmi_Doon</h2>

    </div>

    <ul class="menu">

        <li class="menu-title">
            Main Menu
        </li>

        <li>
            <a href="dashboard.php">
                <i class="fas fa-chart-pie"></i>
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
                <i class="fas fa-school"></i>
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
                <i class="fas fa-square-poll-vertical"></i>
                Results
            </a>
        </li>

        <li class="menu-title">
            Finance
        </li>

        <li>
            <a href="fees.php" class="active">
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

        <li>
            <a href="settings.php">
                <i class="fas fa-cog"></i>
                Settings
            </a>
        </li>

    </ul>

    <div class="logout">

        <a href="logout.php">
            <i class="fas fa-sign-out-alt"></i>
            Logout
        </a>

    </div>

</aside>

<div class="overlay" id="overlay"></div>

<!-- =========================
     MAIN
========================= -->

<div class="main">

    <header class="topbar">

        <!-- MOBILE MENU -->

        <button
            type="button"
            class="menu-btn"
            id="menuBtn"
            title="Open Menu">

            <i class="fas fa-bars"></i>

        </button>

        <div class="user-area">

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

            <!-- AVATAR -->

            <div class="avatar">

                <?= strtoupper(
                    substr($userName, 0, 1)
                ); ?>

            </div>

            <!-- USER -->

            <div class="user-info">

                <strong>
                    <?= htmlspecialchars($userName); ?>
                </strong>

                <small>
                    <?= htmlspecialchars($userRole); ?>
                </small>

            </div>

        </div>

    </header>

    <main class="content">

        <!-- PAGE HEADER -->

        <div class="page-header">

            <div>

                <h1>
                    Fees Management
                </h1>

                <p>
                    Manage student fees and payment status
                </p>

            </div>

            <button
                type="button"
                class="btn btn-primary"
                onclick="openAddModal()">

                <i class="fas fa-plus"></i>

                Add Fee

            </button>

        </div>

        <!-- ALERT -->

        <?php if ($message): ?>

            <div
                class="alert alert-<?= htmlspecialchars($messageType); ?>">

                <i class="fas fa-circle-info"></i>

                <?= htmlspecialchars($message); ?>

            </div>

        <?php endif; ?>

        <!-- STATISTICS -->

        <div class="stats">

            <div class="stat-card">

                <div class="stat-icon">

                    <i class="fas fa-file-invoice-dollar"></i>

                </div>

                <div>

                    <h3>
                        <?= number_format($totalFees); ?>
                    </h3>

                    <p>
                        Total Fees
                    </p>

                </div>

            </div>

            <div class="stat-card">

                <div class="stat-icon">

                    <i class="fas fa-money-bill-wave"></i>

                </div>

                <div>

                    <h3>
                        $<?= number_format($totalAmount, 2); ?>
                    </h3>

                    <p>
                        Total Amount
                    </p>

                </div>

            </div>

            <div class="stat-card">

                <div class="stat-icon">

                    <i class="fas fa-circle-check"></i>

                </div>

                <div>

                    <h3>
                        <?= number_format($totalPaid); ?>
                    </h3>

                    <p>
                        Paid
                    </p>

                </div>

            </div>

            <div class="stat-card">

                <div class="stat-icon">

                    <i class="fas fa-clock"></i>

                </div>

                <div>

                    <h3>
                        <?= number_format(
                            $totalUnpaid + $totalPartial
                        ); ?>
                    </h3>

                    <p>
                        Pending
                    </p>

                </div>

            </div>

        </div>

        <!-- FEES TABLE -->

        <div class="card">

            <div class="card-header">

                <h2>
                    Fee Records
                </h2>

                <span style="color:#64748b;font-size:13px;">

                    <?= number_format($totalFees); ?> records

                </span>

            </div>

            <!-- FILTER -->

            <form
                method="GET"
                class="filters">

                <div class="search-box">

                    <i class="fas fa-search"></i>

                    <input
                        type="text"
                        name="search"
                        placeholder="Search student or fee type..."
                        value="<?= htmlspecialchars($search); ?>">

                </div>

                <select name="status">

                    <option value="">
                        All Status
                    </option>

                    <option
                        value="Paid"
                        <?= $filterStatus === "Paid"
                            ? "selected"
                            : ""; ?>>

                        Paid

                    </option>

                    <option
                        value="Partial"
                        <?= $filterStatus === "Partial"
                            ? "selected"
                            : ""; ?>>

                        Partial

                    </option>

                    <option
                        value="Unpaid"
                        <?= $filterStatus === "Unpaid"
                            ? "selected"
                            : ""; ?>>

                        Unpaid

                    </option>

                </select>

                <button
                    class="btn btn-primary"
                    type="submit">

                    <i class="fas fa-filter"></i>

                    Filter

                </button>

            </form>

            <!-- TABLE -->

            <div class="table-container">

                <table>

                    <thead>

                        <tr>

                            <th>#</th>

                            <th>
                                Student
                            </th>

                            <th>
                                Fee Type
                            </th>

                            <th>
                                Amount
                            </th>

                            <th>
                                Due Date
                            </th>

                            <th>
                                Status
                            </th>

                            <th>
                                Actions
                            </th>

                        </tr>

                    </thead>

                    <tbody>

                    <?php if ($feesResult->num_rows > 0): ?>

                        <?php $number = 1; ?>

                        <?php while (
                            $row = $feesResult->fetch_assoc()
                        ): ?>

                            <tr>

                                <td>
                                    <?= $number++; ?>
                                </td>

                                <td>

                                    <div class="student">

                                        <div class="student-avatar">

                                            <?= strtoupper(
                                                substr(
                                                    $row['full_name'] ?? 'S',
                                                    0,
                                                    1
                                                )
                                            ); ?>

                                        </div>

                                        <div>

                                            <div class="student-name">

                                                <?= htmlspecialchars(
                                                    $row['full_name']
                                                    ?? 'Unknown Student'
                                                ); ?>

                                            </div>

                                            <div class="student-code">

                                                <?= htmlspecialchars(
                                                    $row['student_code']
                                                    ?? ''
                                                ); ?>

                                            </div>

                                        </div>

                                    </div>

                                </td>

                                <td>

                                    <?= htmlspecialchars(
                                        $row['fee_type']
                                    ); ?>

                                </td>

                                <td>

                                    <strong>

                                        $<?= number_format(
                                            $row['amount'],
                                            2
                                        ); ?>

                                    </strong>

                                </td>

                                <td>

                                    <?= $row['due_date']
                                        ? date(
                                            "M d, Y",
                                            strtotime(
                                                $row['due_date']
                                            )
                                        )
                                        : "—";
                                    ?>

                                </td>

                                <td>

                                    <?php if (
                                        $row['status'] === 'Paid'
                                    ): ?>

                                        <span class="badge badge-paid">
                                            Paid
                                        </span>

                                    <?php elseif (
                                        $row['status'] === 'Partial'
                                    ): ?>

                                        <span class="badge badge-partial">
                                            Partial
                                        </span>

                                    <?php else: ?>

                                        <span class="badge badge-unpaid">
                                            Unpaid
                                        </span>

                                    <?php endif; ?>

                                </td>

                                <td>

                                    <div class="actions">

                                        <a
                                            href="?edit=<?= $row['id']; ?>"
                                            class="action-btn edit-btn"
                                            title="Edit">

                                            <i class="fas fa-edit"></i>

                                        </a>

                                        <a
                                            href="?delete=<?= $row['id']; ?>"
                                            class="action-btn delete-btn"
                                            title="Delete"
                                            onclick="return confirm('Are you sure you want to delete this fee?');">

                                            <i class="fas fa-trash"></i>

                                        </a>

                                    </div>

                                </td>

                            </tr>

                        <?php endwhile; ?>

                    <?php else: ?>

                        <tr>

                            <td colspan="7">

                                <div class="empty">

                                    <i class="fas fa-file-invoice-dollar"></i>

                                    <p>
                                        No fee records found.
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
     ADD MODAL
========================= -->

<div
    class="modal"
    id="addModal">

    <div class="modal-content">

        <div class="modal-header">

            <h2>
                Add New Fee
            </h2>

            <button
                type="button"
                class="close"
                onclick="closeAddModal()">

                &times;

            </button>

        </div>

        <form method="POST">

            <input
                type="hidden"
                name="action"
                value="add">

            <div class="modal-body">

                <div class="modal-grid">

                    <!-- STUDENT -->

                    <div class="form-group">

                        <label>
                            Student *
                        </label>

                        <select
                            name="student_id"
                            class="form-control"
                            required>

                            <option value="">
                                Select Student
                            </option>

                            <?php

                            if ($students) {

                                $students->data_seek(0);

                                while (
                                    $student =
                                    $students->fetch_assoc()
                                ):

                            ?>

                                <option
                                    value="<?= $student['id']; ?>">

                                    <?= htmlspecialchars(
                                        $student['full_name']
                                    ); ?>

                                    —

                                    <?= htmlspecialchars(
                                        $student['student_id']
                                    ); ?>

                                </option>

                            <?php

                                endwhile;
                            }

                            ?>

                        </select>

                    </div>

                    <!-- FEE TYPE -->

                    <div class="form-group">

                        <label>
                            Fee Type *
                        </label>

                        <input
                            type="text"
                            name="fee_type"
                            class="form-control"
                            placeholder="e.g. Tuition Fee"
                            required>

                    </div>

                    <!-- AMOUNT -->

                    <div class="form-group">

                        <label>
                            Amount *
                        </label>

                        <input
                            type="number"
                            name="amount"
                            class="form-control"
                            step="0.01"
                            min="0.01"
                            placeholder="0.00"
                            required>

                    </div>

                    <!-- DUE DATE -->

                    <div class="form-group">

                        <label>
                            Due Date
                        </label>

                        <input
                            type="date"
                            name="due_date"
                            class="form-control">

                    </div>

                    <!-- STATUS -->

                    <div class="form-group">

                        <label>
                            Status
                        </label>

                        <select
                            name="status"
                            class="form-control">

                            <option value="Unpaid">
                                Unpaid
                            </option>

                            <option value="Partial">
                                Partial
                            </option>

                            <option value="Paid">
                                Paid
                            </option>

                        </select>

                    </div>

                </div>

            </div>

            <div class="modal-footer">

                <button
                    type="button"
                    class="btn"
                    onclick="closeAddModal()">

                    Cancel

                </button>

                <button
                    type="submit"
                    class="btn btn-primary">

                    <i class="fas fa-save"></i>

                    Save Fee

                </button>

            </div>

        </form>

    </div>

</div>

<!-- =========================
     EDIT MODAL
========================= -->

<?php if ($editFee): ?>

<div
    class="modal active"
    id="editModal">

    <div class="modal-content">

        <div class="modal-header">

            <h2>
                Edit Fee
            </h2>

            <a
                href="fees.php"
                class="close"
                style="text-decoration:none;">

                &times;

            </a>

        </div>

        <form method="POST">

            <input
                type="hidden"
                name="action"
                value="update">

            <input
                type="hidden"
                name="id"
                value="<?= $editFee['id']; ?>">

            <div class="modal-body">

                <div class="modal-grid">

                    <!-- STUDENT -->

                    <div class="form-group">

                        <label>
                            Student *
                        </label>

                        <select
                            name="student_id"
                            class="form-control"
                            required>

                            <option value="">
                                Select Student
                            </option>

                            <?php

                            if ($students) {

                                $students->data_seek(0);

                                while (
                                    $student =
                                    $students->fetch_assoc()
                                ):

                            ?>

                                <option
                                    value="<?= $student['id']; ?>"
                                    <?= $editFee['student_id']
                                        == $student['id']
                                        ? 'selected'
                                        : ''; ?>>

                                    <?= htmlspecialchars(
                                        $student['full_name']
                                    ); ?>

                                    —

                                    <?= htmlspecialchars(
                                        $student['student_id']
                                    ); ?>

                                </option>

                            <?php

                                endwhile;
                            }

                            ?>

                        </select>

                    </div>

                    <!-- FEE TYPE -->

                    <div class="form-group">

                        <label>
                            Fee Type *
                        </label>

                        <input
                            type="text"
                            name="fee_type"
                            class="form-control"
                            value="<?= htmlspecialchars(
                                $editFee['fee_type']
                            ); ?>"
                            required>

                    </div>

                    <!-- AMOUNT -->

                    <div class="form-group">

                        <label>
                            Amount *
                        </label>

                        <input
                            type="number"
                            name="amount"
                            class="form-control"
                            step="0.01"
                            min="0.01"
                            value="<?= htmlspecialchars(
                                $editFee['amount']
                            ); ?>"
                            required>

                    </div>

                    <!-- DUE DATE -->

                    <div class="form-group">

                        <label>
                            Due Date
                        </label>

                        <input
                            type="date"
                            name="due_date"
                            class="form-control"
                            value="<?= htmlspecialchars(
                                $editFee['due_date'] ?? ''
                            ); ?>">

                    </div>

                    <!-- STATUS -->

                    <div class="form-group">

                        <label>
                            Status
                        </label>

                        <select
                            name="status"
                            class="form-control">

                            <option
                                value="Unpaid"
                                <?= $editFee['status'] === 'Unpaid'
                                    ? 'selected'
                                    : ''; ?>>

                                Unpaid

                            </option>

                            <option
                                value="Partial"
                                <?= $editFee['status'] === 'Partial'
                                    ? 'selected'
                                    : ''; ?>>

                                Partial

                            </option>

                            <option
                                value="Paid"
                                <?= $editFee['status'] === 'Paid'
                                    ? 'selected'
                                    : ''; ?>>

                                Paid

                            </option>

                        </select>

                    </div>

                </div>

            </div>

            <div class="modal-footer">

                <a
                    href="fees.php"
                    class="btn"
                    style="
                        text-decoration:none;
                        color:#334155;
                    ">

                    Cancel

                </a>

                <button
                    type="submit"
                    class="btn btn-primary">

                    <i class="fas fa-save"></i>

                    Update Fee

                </button>

            </div>

        </form>

    </div>

</div>

<?php endif; ?>

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
        function(){

            sidebar.classList.toggle("active");

            overlay.classList.toggle("active");

        }
    );

}

if (overlay) {

    overlay.addEventListener(
        "click",
        function(){

            sidebar.classList.remove("active");

            overlay.classList.remove("active");

        }
    );

}

/* =========================
   CLOSE SIDEBAR
   AFTER CLICKING LINK
========================= */

document
    .querySelectorAll(".menu a")
    .forEach(function(link){

        link.addEventListener(
            "click",
            function(){

                if(window.innerWidth <= 900){

                    sidebar.classList.remove("active");

                    overlay.classList.remove("active");

                }

            }
        );

    });

/* =========================
   ADD MODAL
========================= */

function openAddModal(){

    const modal =
        document.getElementById("addModal");

    if(modal){

        modal.classList.add("active");

    }

}

function closeAddModal(){

    const modal =
        document.getElementById("addModal");

    if(modal){

        modal.classList.remove("active");

    }

}

/* =========================
   CLOSE ADD MODAL
   OUTSIDE CLICK
========================= */

const addModal =
    document.getElementById("addModal");

if(addModal){

    addModal.addEventListener(
        "click",
        function(e){

            if(e.target === this){

                closeAddModal();

            }

        }
    );

}

/* =========================
   ESC CLOSE MODAL
========================= */

document.addEventListener(
    "keydown",
    function(e){

        if(e.key === "Escape"){

            closeAddModal();

        }

    }
);

/* =========================
   DARK / LIGHT MODE
========================= */

const themeToggle =
    document.getElementById("themeToggle");

const themeIcon =
    themeToggle
        ? themeToggle.querySelector("i")
        : null;

function applyTheme(theme){

    if(theme === "dark"){

        document.documentElement
            .classList
            .add("dark-mode");

        document.body
            .classList
            .add("dark-mode");

        if(themeIcon){

            themeIcon.classList.remove(
                "fa-moon"
            );

            themeIcon.classList.add(
                "fa-sun"
            );

        }

        if(themeToggle){

            themeToggle.title =
                "Switch to Light Mode";

        }

    }else{

        document.documentElement
            .classList
            .remove("dark-mode");

        document.body
            .classList
            .remove("dark-mode");

        if(themeIcon){

            themeIcon.classList.remove(
                "fa-sun"
            );

            themeIcon.classList.add(
                "fa-moon"
            );

        }

        if(themeToggle){

            themeToggle.title =
                "Switch to Dark Mode";

        }

    }

    localStorage.setItem(
        "theme",
        theme
    );

}

const savedTheme =
    localStorage.getItem("theme")
    || "light";

applyTheme(savedTheme);

if(themeToggle){

    themeToggle.addEventListener(
        "click",
        function(){

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
$stmt->close();
$conn->close();
?>