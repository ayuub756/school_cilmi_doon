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

/* =========================================================
   NOTIFICATION HELPER
========================================================= */

require_once "notification_helper.php";

/* =========================================================
   USER
========================================================= */

$userName = $_SESSION['user_name'] ?? "Administrator";
$userRole = $_SESSION['user_role'] ?? "Admin";

$unreadCount = getUnreadNotificationCount(
    $conn,
    (int) $_SESSION['user_id']
);

$message = "";
$messageType = "";

/* =========================================================
   DELETE PAYMENT
========================================================= */

if (isset($_GET['delete'])) {

    $id = intval($_GET['delete']);

    if ($id > 0) {

        $stmt = $conn->prepare(
            "DELETE FROM payments WHERE id = ?"
        );

        $stmt->bind_param("i", $id);

        if ($stmt->execute()) {

            $message = "Payment deleted successfully.";
            $messageType = "success";

        } else {

            $message = "Failed to delete payment.";
            $messageType = "error";
        }

        $stmt->close();
    }
}

/* =========================================================
   ADD / UPDATE PAYMENT
========================================================= */

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $action = $_POST['action'] ?? "";

    $fee_id = intval($_POST['fee_id'] ?? 0);
    $amount = floatval($_POST['amount'] ?? 0);
    $payment_date = $_POST['payment_date'] ?? "";
    $method = trim($_POST['method'] ?? "Cash");
    $note = trim($_POST['note'] ?? "");

    /* =====================================================
       ADD PAYMENT
    ===================================================== */

    if ($action === "add") {

        if (
            $fee_id <= 0 ||
            $amount <= 0 ||
            empty($payment_date)
        ) {

            $message = "Please fill in all required fields.";
            $messageType = "error";

        } else {

            $stmt = $conn->prepare("
                INSERT INTO payments
                (fee_id, amount, payment_date, method, note)
                VALUES (?, ?, ?, ?, ?)
            ");

            $stmt->bind_param(
                "idsss",
                $fee_id,
                $amount,
                $payment_date,
                $method,
                $note
            );

            if ($stmt->execute()) {

                $message = "Payment added successfully.";
                $messageType = "success";

                createNotification(
                    $conn,
                    (int) $_SESSION['user_id'],
                    "New Payment Added",
                    "A payment of $" .
                    number_format($amount, 2) .
                    " has been successfully recorded.",
                    "success",
                    "payments.php"
                );

            } else {

                $message = "Failed to add payment.";
                $messageType = "error";
            }

            $stmt->close();
        }
    }

    /* =====================================================
       UPDATE PAYMENT
    ===================================================== */

    if ($action === "update") {

        $id = intval($_POST['id'] ?? 0);

        if (
            $id <= 0 ||
            $fee_id <= 0 ||
            $amount <= 0 ||
            empty($payment_date)
        ) {

            $message = "Please fill in all required fields.";
            $messageType = "error";

        } else {

            $stmt = $conn->prepare("
                UPDATE payments
                SET
                    fee_id = ?,
                    amount = ?,
                    payment_date = ?,
                    method = ?,
                    note = ?
                WHERE id = ?
            ");

            $stmt->bind_param(
                "idsssi",
                $fee_id,
                $amount,
                $payment_date,
                $method,
                $note,
                $id
            );

            if ($stmt->execute()) {

                $message = "Payment updated successfully.";
                $messageType = "success";

            } else {

                $message = "Failed to update payment.";
                $messageType = "error";
            }

            $stmt->close();
        }
    }
}

/* =========================================================
   EDIT PAYMENT
========================================================= */

$editPayment = null;

if (isset($_GET['edit'])) {

    $id = intval($_GET['edit']);

    $stmt = $conn->prepare("
        SELECT
            id,
            fee_id,
            amount,
            payment_date,
            method,
            note
        FROM payments
        WHERE id = ?
    ");

    $stmt->bind_param("i", $id);
    $stmt->execute();

    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $editPayment = $result->fetch_assoc();
    }

    $stmt->close();
}

/* =========================================================
   STATISTICS
========================================================= */

$totalPayments = 0;
$totalPaidAmount = 0;
$todayPayments = 0;
$todayAmount = 0;

$result = $conn->query("
    SELECT
        COUNT(*) AS total_payments,

        COALESCE(
            SUM(amount),
            0
        ) AS total_amount,

        SUM(
            payment_date = CURDATE()
        ) AS today_payments,

        COALESCE(
            SUM(
                CASE
                    WHEN payment_date = CURDATE()
                    THEN amount
                    ELSE 0
                END
            ),
            0
        ) AS today_amount

    FROM payments
");

if ($result) {

    $stats = $result->fetch_assoc();

    $totalPayments =
        $stats['total_payments'] ?? 0;

    $totalPaidAmount =
        $stats['total_amount'] ?? 0;

    $todayPayments =
        $stats['today_payments'] ?? 0;

    $todayAmount =
        $stats['today_amount'] ?? 0;
}

/* =========================================================
   SEARCH & FILTER
========================================================= */

$search = trim(
    $_GET['search'] ?? ""
);

$filterMethod =
    $_GET['method'] ?? "";

$sql = "
    SELECT

        p.id,
        p.fee_id,
        p.amount,
        p.payment_date,
        p.method,
        p.note,
        p.created_at,

        f.fee_type,
        f.amount AS fee_amount,
        f.status AS fee_status,

        s.full_name,
        s.student_id AS student_code

    FROM payments p

    LEFT JOIN fees f
        ON p.fee_id = f.id

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
            OR p.note LIKE ?
        )
    ";

    $searchValue =
        "%" . $search . "%";

    $params[] = $searchValue;
    $params[] = $searchValue;
    $params[] = $searchValue;
    $params[] = $searchValue;

    $types .= "ssss";
}

if ($filterMethod !== "") {

    $sql .= "
        AND p.method = ?
    ";

    $params[] =
        $filterMethod;

    $types .= "s";
}

$sql .= "
    ORDER BY p.id DESC
";

$stmt = $conn->prepare($sql);

if (!empty($params)) {

    $stmt->bind_param(
        $types,
        ...$params
    );
}

$stmt->execute();

$paymentsResult =
    $stmt->get_result();

/* =========================================================
   FEES DROPDOWN
========================================================= */

$fees = $conn->query("
    SELECT
        f.id,
        f.fee_type,
        f.amount,
        f.status,

        s.full_name,
        s.student_id

    FROM fees f

    LEFT JOIN students s
        ON f.student_id = s.id

    ORDER BY s.full_name ASC
");

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0">

<title>
    Payments Management | School Management
</title>

<link
    rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

<!-- =====================================================
     LOAD SAVED THEME BEFORE PAGE SHOWS
===================================================== -->

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

/* =========================================================
   RESET
========================================================= */

*{
    margin:0;
    padding:0;
    box-sizing:border-box;
    font-family:Arial, Helvetica, sans-serif;
}

body{
    background:#f8fafc;
    color:#0f172a;
    transition:
        background .25s ease,
        color .25s ease;
}

/* =========================================================
   SIDEBAR
========================================================= */

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

    border-bottom:
        1px solid rgba(255,255,255,.08);
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

    margin:
        20px 8px 8px;

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

.logout{
    position:static;

    margin-top:auto;

    padding:15px 12px;

    border-top:
        1px solid rgba(255,255,255,.08);
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

/* =========================================================
   MAIN
========================================================= */

.main{
    margin-left:260px;
    min-height:100vh;
}

/* =========================================================
   TOPBAR
========================================================= */

.topbar{
    height:70px;

    background:white;

    border-bottom:
        1px solid #e2e8f0;

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

/* =========================================================
   MENU BUTTON
========================================================= */

.menu-btn{
    display:none;

    width:40px;
    height:40px;

    border:1px solid #e2e8f0;

    border-radius:8px;

    background:white;

    font-size:20px;

    color:#334155;

    cursor:pointer;

    align-items:center;
    justify-content:center;
}

/* =========================================================
   THEME BUTTON
========================================================= */

.theme-toggle{
    width:40px;
    height:40px;

    border:1px solid #e2e8f0;

    border-radius:50%;

    background:white;

    color:#475569;

    display:flex;

    align-items:center;
    justify-content:center;

    cursor:pointer;

    font-size:17px;

    transition:.25s;
}

.theme-toggle:hover{
    background:#f1f5f9;
    color:#2563eb;
}

/* =========================================================
   NOTIFICATION
========================================================= */

.notification{
    width:40px;
    height:40px;

    border-radius:50%;

    display:flex;

    align-items:center;
    justify-content:center;

    position:relative;

    margin-right:5px;

    color:#475569;

    font-size:19px;

    text-decoration:none;

    cursor:pointer;

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
}

.user-info strong{
    display:block;
    font-size:14px;
}

.user-info small{
    color:#64748b;
    font-size:12px;
}

/* =========================================================
   CONTENT
========================================================= */

.content{
    padding:30px;
}

.page-header{
    display:flex;

    justify-content:space-between;

    align-items:center;

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

/* =========================================================
   ALERT
========================================================= */

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

/* =========================================================
   STATS
========================================================= */

.stats{
    display:grid;

    grid-template-columns:
        repeat(4,1fr);

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

.stat-icon{
    width:48px;
    height:48px;

    border-radius:10px;

    display:flex;

    align-items:center;
    justify-content:center;

    background:#eff6ff;

    color:#2563eb;

    font-size:20px;
}

.stat-card h3{
    font-size:21px;

    margin-bottom:3px;
}

.stat-card p{
    color:#64748b;

    font-size:13px;
}

/* =========================================================
   CARD
========================================================= */

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

    border-bottom:
        1px solid #e2e8f0;

    display:flex;

    justify-content:space-between;

    align-items:center;
}

.card-header h2{
    font-size:17px;
}

/* =========================================================
   FILTERS
========================================================= */

.filters{
    padding:18px 20px;

    display:flex;

    gap:12px;

    border-bottom:
        1px solid #e2e8f0;
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

    padding:
        11px 12px 11px 38px;

    border:1px solid #cbd5e1;

    border-radius:8px;

    outline:none;

    font-size:14px;

    background:white;

    color:#0f172a;
}

.search-box input:focus{
    border-color:#2563eb;
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

/* =========================================================
   TABLE
========================================================= */

.table-container{
    overflow-x:auto;
}

table{
    width:100%;

    border-collapse:collapse;

    min-width:1050px;
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

    border-bottom:
        1px solid #e2e8f0;
}

td{
    padding:14px 18px;

    border-bottom:
        1px solid #f1f5f9;

    font-size:13px;
}

tbody tr:hover{
    background:#f8fafc;
}

/* =========================================================
   STUDENT
========================================================= */

.student{
    display:flex;

    align-items:center;

    gap:10px;
}

.student-avatar{
    width:36px;
    height:36px;

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

/* =========================================================
   METHOD
========================================================= */

.method{
    display:inline-flex;

    align-items:center;

    gap:6px;

    padding:5px 9px;

    border-radius:20px;

    background:#f1f5f9;

    color:#475569;

    font-size:11px;

    font-weight:bold;
}

/* =========================================================
   ACTIONS
========================================================= */

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

.edit-btn{
    background:#eff6ff;
    color:#2563eb;
}

.edit-btn:hover{
    background:#dbeafe;
}

.delete-btn{
    background:#fef2f2;
    color:#dc2626;
}

.delete-btn:hover{
    background:#fee2e2;
}

/* =========================================================
   EMPTY
========================================================= */

.empty{
    text-align:center;

    padding:50px 20px;

    color:#64748b;
}

.empty i{
    font-size:40px;

    margin-bottom:12px;

    color:#cbd5e1;
}

/* =========================================================
   MODAL
========================================================= */

.modal{
    display:none;

    position:fixed;

    inset:0;

    background:
        rgba(15,23,42,.65);

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

    max-width:650px;

    border-radius:14px;

    overflow:hidden;

    max-height:
        calc(100vh - 40px);

    overflow-y:auto;
}

.modal-header{
    padding:18px 20px;

    border-bottom:
        1px solid #e2e8f0;

    display:flex;

    justify-content:space-between;

    align-items:center;
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

    text-decoration:none;
}

.close:hover{
    color:#ef4444;
}

.modal-body{
    padding:20px;
}

.modal-grid{
    display:grid;

    grid-template-columns:
        1fr 1fr;

    gap:17px;
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
}

.form-control:focus{
    border-color:#2563eb;

    box-shadow:
        0 0 0 3px
        rgba(37,99,235,.1);
}

.modal-footer{
    padding:15px 20px;

    border-top:
        1px solid #e2e8f0;

    display:flex;

    justify-content:flex-end;

    gap:10px;
}

.btn-cancel{
    background:#f1f5f9;

    color:#334155;

    border:1px solid #e2e8f0;
}

.btn-cancel:hover{
    background:#e2e8f0;
}

/* =========================================================
   OVERLAY
========================================================= */

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

/* =========================================================
   DARK MODE
========================================================= */

html.dark-mode body{
    background:#0f172a;

    color:#e2e8f0;
}

html.dark-mode .topbar{
    background:#1e293b;

    border-color:#334155;
}

html.dark-mode .menu-btn{
    background:#1e293b;

    border-color:#475569;

    color:#f8fafc;
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

html.dark-mode .stat-card{
    background:#1e293b;

    border-color:#334155;
}

html.dark-mode .stat-card h3{
    color:#f8fafc;
}

html.dark-mode .stat-card p{
    color:#94a3b8;
}

html.dark-mode .stat-icon{
    background:#334155;

    color:#60a5fa;
}

html.dark-mode .card{
    background:#1e293b;

    border-color:#334155;
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

html.dark-mode .search-box input{
    background:#0f172a;

    color:#e2e8f0;

    border-color:#475569;
}

html.dark-mode .search-box input::placeholder{
    color:#64748b;
}

html.dark-mode .filters select{
    background:#0f172a;

    color:#e2e8f0;

    border-color:#475569;
}

html.dark-mode select option{
    background:#0f172a;

    color:#e2e8f0;
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
    background:
        rgba(37,99,235,.18);

    color:#60a5fa;
}

html.dark-mode .method{
    background:#334155;

    color:#cbd5e1;
}

html.dark-mode .edit-btn{
    background:
        rgba(37,99,235,.15);

    color:#60a5fa;
}

html.dark-mode .edit-btn:hover{
    background:
        rgba(37,99,235,.25);
}

html.dark-mode .delete-btn{
    background:
        rgba(220,38,38,.15);

    color:#f87171;
}

html.dark-mode .delete-btn:hover{
    background:
        rgba(220,38,38,.25);
}

html.dark-mode .empty{
    color:#94a3b8;
}

html.dark-mode .empty i{
    color:#475569;
}

html.dark-mode .modal-content{
    background:#1e293b;

    border:1px solid #334155;
}

html.dark-mode .modal-header{
    border-color:#334155;
}

html.dark-mode .modal-header h2{
    color:#f8fafc;
}

html.dark-mode .close{
    color:#cbd5e1;
}

html.dark-mode .close:hover{
    color:#f87171;
}

html.dark-mode .form-group label{
    color:#cbd5e1;
}

html.dark-mode .form-control{
    background:#0f172a;

    color:#e2e8f0;

    border-color:#475569;
}

html.dark-mode .form-control::placeholder{
    color:#64748b;
}

html.dark-mode .modal-footer{
    border-color:#334155;
}

html.dark-mode .btn-cancel{
    background:#334155;

    color:#e2e8f0;

    border-color:#475569;
}

html.dark-mode .btn-cancel:hover{
    background:#475569;
}

html.dark-mode .alert-success{
    background:
        rgba(22,163,74,.15);

    color:#86efac;

    border-color:#166534;
}

html.dark-mode .alert-error{
    background:
        rgba(220,38,38,.15);

    color:#fca5a5;

    border-color:#991b1b;
}

/* =========================================================
   RESPONSIVE
========================================================= */

@media(max-width:1100px){

    .stats{
        grid-template-columns:
            repeat(2,1fr);
    }

}

@media(max-width:900px){

    .sidebar{
        transform:
            translateX(-100%);
    }

    .sidebar.active{
        transform:
            translateX(0);
    }

    .main{
        margin-left:0;
    }

    .menu-btn{
        display:flex;
    }

    .topbar{
        justify-content:
            space-between;

        padding:0 20px;
    }

}

@media(max-width:650px){

    .content{
        padding:
            20px 15px;
    }

    .page-header{
        flex-direction:column;

        align-items:
            flex-start;

        gap:15px;
    }

    .page-header h1{
        font-size:21px;
    }

    .page-header .btn{
        width:100%;
    }

    .stats{
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

    .theme-toggle,
    .menu-btn,
    .notification{
        width:38px;
        height:38px;
    }

}

@media(max-width:480px){

    .topbar{
        padding:0 12px;
    }

    .user-area{
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
        padding:
            18px 12px;
    }

    .stat-card{
        padding:16px;
    }

    .modal{
        padding:10px;
    }

    .modal-content{
        max-height:
            calc(100vh - 20px);
    }

}

/* =========================================================
   SCROLLBAR
========================================================= */

.sidebar::-webkit-scrollbar{
    width:5px;
}

.sidebar::-webkit-scrollbar-thumb{
    background:#334155;

    border-radius:10px;
}

.table-container::-webkit-scrollbar{
    height:7px;
}

.table-container::-webkit-scrollbar-thumb{
    background:#cbd5e1;

    border-radius:10px;
}

html.dark-mode
.table-container::-webkit-scrollbar-thumb{
    background:#475569;
}

</style>

</head>

<body>

<!-- =========================================================
     SIDEBAR
========================================================= -->

<aside
    class="sidebar"
    id="sidebar">

    <div class="logo">

        <div class="logo-icon">

            <i class="fas fa-graduation-cap"></i>

        </div>

        <h2>
            Cilmi_Doon
        </h2>

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
            <a href="fees.php">

                <i class="fas fa-money-bill-wave"></i>

                Fees

            </a>
        </li>

        <li>
            <a
                href="payments.php"
                class="active">

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

<div
    class="overlay"
    id="overlay">
</div>

<!-- =========================================================
     MAIN
========================================================= -->

<div class="main">

    <header class="topbar">

        <button
            class="menu-btn"
            id="menuBtn"
            type="button"
            title="Menu">

            <i class="fas fa-bars"></i>

        </button>

        <div class="user-area">

            <!-- DARK / LIGHT MODE -->

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

            <div class="user-info">

                <strong>

                    <?= htmlspecialchars(
                        $userName
                    ); ?>

                </strong>

                <small>

                    <?= htmlspecialchars(
                        $userRole
                    ); ?>

                </small>

            </div>

        </div>

    </header>

    <main class="content">

        <!-- =================================================
             PAGE HEADER
        ================================================= -->

        <div class="page-header">

            <div>

                <h1>
                    Payments Management
                </h1>

                <p>
                    Manage student fee payments and transactions
                </p>

            </div>

            <button
                class="btn btn-primary"
                type="button"
                onclick="openAddModal()">

                <i class="fas fa-plus"></i>

                Add Payment

            </button>

        </div>

        <!-- =================================================
             ALERT
        ================================================= -->

        <?php if ($message): ?>

            <div
                class="alert alert-<?= htmlspecialchars($messageType); ?>">

                <?php if ($messageType === "success"): ?>

                    <i class="fas fa-circle-check"></i>

                <?php else: ?>

                    <i class="fas fa-circle-exclamation"></i>

                <?php endif; ?>

                <?= htmlspecialchars($message); ?>

            </div>

        <?php endif; ?>

        <!-- =================================================
             STATISTICS
        ================================================= -->

        <div class="stats">

            <!-- TOTAL PAYMENTS -->

            <div class="stat-card">

                <div class="stat-icon">

                    <i class="fas fa-receipt"></i>

                </div>

                <div>

                    <h3>

                        <?= number_format(
                            $totalPayments
                        ); ?>

                    </h3>

                    <p>
                        Total Payments
                    </p>

                </div>

            </div>

            <!-- TOTAL COLLECTED -->

            <div class="stat-card">

                <div class="stat-icon">

                    <i class="fas fa-money-bill-transfer"></i>

                </div>

                <div>

                    <h3>

                        $<?= number_format(
                            $totalPaidAmount,
                            2
                        ); ?>

                    </h3>

                    <p>
                        Total Collected
                    </p>

                </div>

            </div>

            <!-- TODAY PAYMENTS -->

            <div class="stat-card">

                <div class="stat-icon">

                    <i class="fas fa-calendar-day"></i>

                </div>

                <div>

                    <h3>

                        <?= number_format(
                            $todayPayments
                        ); ?>

                    </h3>

                    <p>
                        Today's Payments
                    </p>

                </div>

            </div>

            <!-- TODAY COLLECTION -->

            <div class="stat-card">

                <div class="stat-icon">

                    <i class="fas fa-wallet"></i>

                </div>

                <div>

                    <h3>

                        $<?= number_format(
                            $todayAmount,
                            2
                        ); ?>

                    </h3>

                    <p>
                        Today's Collection
                    </p>

                </div>

            </div>

        </div>

        <!-- =================================================
             PAYMENT RECORDS
        ================================================= -->

        <div class="card">

            <div class="card-header">

                <h2>
                    Payment Records
                </h2>

                <span
                    style="
                        color:#64748b;
                        font-size:13px;
                    ">

                    <?= number_format(
                        $totalPayments
                    ); ?>

                    payments

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
                        placeholder="Search student, fee type..."
                        value="<?= htmlspecialchars(
                            $search
                        ); ?>">

                </div>

                <select name="method">

                    <option value="">
                        All Methods
                    </option>

                    <option
                        value="Cash"
                        <?= $filterMethod === "Cash"
                            ? "selected"
                            : ""; ?>>

                        Cash

                    </option>

                    <option
                        value="Mobile Money"
                        <?= $filterMethod === "Mobile Money"
                            ? "selected"
                            : ""; ?>>

                        Mobile Money

                    </option>

                    <option
                        value="Bank"
                        <?= $filterMethod === "Bank"
                            ? "selected"
                            : ""; ?>>

                        Bank

                    </option>

                    <option
                        value="Card"
                        <?= $filterMethod === "Card"
                            ? "selected"
                            : ""; ?>>

                        Card

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

                            <th>Student</th>

                            <th>Fee Type</th>

                            <th>Amount</th>

                            <th>Payment Date</th>

                            <th>Method</th>

                            <th>Note</th>

                            <th>Actions</th>

                        </tr>

                    </thead>

                    <tbody>

                    <?php if (
                        $paymentsResult->num_rows > 0
                    ): ?>

                        <?php $number = 1; ?>

                        <?php while (
                            $row =
                            $paymentsResult->fetch_assoc()
                        ): ?>

                            <tr>

                                <td>

                                    <?= $number++; ?>

                                </td>

                                <td>

                                    <div class="student">

                                        <div
                                            class="student-avatar">

                                            <?= strtoupper(
                                                substr(
                                                    $row['full_name']
                                                    ?? 'S',
                                                    0,
                                                    1
                                                )
                                            ); ?>

                                        </div>

                                        <div>

                                            <div
                                                class="student-name">

                                                <?= htmlspecialchars(
                                                    $row['full_name']
                                                    ??
                                                    'Unknown Student'
                                                ); ?>

                                            </div>

                                            <div
                                                class="student-code">

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
                                        ?? '—'
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

                                    <?= date(
                                        "M d, Y",
                                        strtotime(
                                            $row['payment_date']
                                        )
                                    ); ?>

                                </td>

                                <td>

                                    <?php

                                    $methodIcon =
                                        "fa-money-bill";

                                    if (
                                        strtolower(
                                            $row['method']
                                        ) === "bank"
                                    ) {

                                        $methodIcon =
                                            "fa-building-columns";
                                    }

                                    if (
                                        strtolower(
                                            $row['method']
                                        ) === "card"
                                    ) {

                                        $methodIcon =
                                            "fa-credit-card";
                                    }

                                    if (
                                        strtolower(
                                            $row['method']
                                        ) === "mobile money"
                                    ) {

                                        $methodIcon =
                                            "fa-mobile-screen";
                                    }

                                    ?>

                                    <span class="method">

                                        <i
                                            class="fas <?= $methodIcon; ?>">
                                        </i>

                                        <?= htmlspecialchars(
                                            $row['method']
                                            ?? 'Cash'
                                        ); ?>

                                    </span>

                                </td>

                                <td>

                                    <?= !empty(
                                        $row['note']
                                    )

                                        ? htmlspecialchars(
                                            $row['note']
                                        )

                                        : "—";
                                    ?>

                                </td>

                                <td>

                                    <div class="actions">

                                        <!-- EDIT -->

                                        <a
                                            href="?edit=<?= $row['id']; ?>"
                                            class="action-btn edit-btn"
                                            title="Edit">

                                            <i
                                                class="fas fa-edit">
                                            </i>

                                        </a>

                                        <!-- DELETE -->

                                        <a
                                            href="?delete=<?= $row['id']; ?>"
                                            class="action-btn delete-btn"
                                            title="Delete"
                                            onclick="
                                                return confirm(
                                                    'Are you sure you want to delete this payment?'
                                                );
                                            ">

                                            <i
                                                class="fas fa-trash">
                                            </i>

                                        </a>

                                    </div>

                                </td>

                            </tr>

                        <?php endwhile; ?>

                    <?php else: ?>

                        <tr>

                            <td colspan="8">

                                <div class="empty">

                                    <i
                                        class="fas fa-receipt">
                                    </i>

                                    <p>
                                        No payment records found.
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

<!-- =========================================================
     ADD PAYMENT MODAL
========================================================= -->

<div
    class="modal"
    id="addModal">

    <div class="modal-content">

        <div class="modal-header">

            <h2>
                Add New Payment
            </h2>

            <button
                class="close"
                type="button"
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

                    <!-- FEE -->

                    <div class="form-group">

                        <label>
                            Fee / Student *
                        </label>

                        <select
                            name="fee_id"
                            class="form-control"
                            required>

                            <option value="">
                                Select Fee
                            </option>

                            <?php if ($fees): ?>

                                <?php

                                $fees->data_seek(0);

                                while (
                                    $fee =
                                    $fees->fetch_assoc()
                                ):

                                ?>

                                    <option
                                        value="<?= $fee['id']; ?>">

                                        <?= htmlspecialchars(
                                            $fee['full_name']
                                            ?? 'Unknown'
                                        ); ?>

                                        —

                                        <?= htmlspecialchars(
                                            $fee['fee_type']
                                        ); ?>

                                        —

                                        $<?= number_format(
                                            $fee['amount'],
                                            2
                                        ); ?>

                                    </option>

                                <?php endwhile; ?>

                            <?php endif; ?>

                        </select>

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

                    <!-- DATE -->

                    <div class="form-group">

                        <label>
                            Payment Date *
                        </label>

                        <input
                            type="date"
                            name="payment_date"
                            class="form-control"
                            value="<?= date(
                                'Y-m-d'
                            ); ?>"
                            required>

                    </div>

                    <!-- METHOD -->

                    <div class="form-group">

                        <label>
                            Payment Method
                        </label>

                        <select
                            name="method"
                            class="form-control">

                            <option value="Cash">
                                Cash
                            </option>

                            <option value="Mobile Money">
                                Mobile Money
                            </option>

                            <option value="Bank">
                                Bank
                            </option>

                            <option value="Card">
                                Card
                            </option>

                        </select>

                    </div>

                    <!-- NOTE -->

                    <div
                        class="form-group"
                        style="grid-column:1/-1;">

                        <label>
                            Note
                        </label>

                        <input
                            type="text"
                            name="note"
                            class="form-control"
                            placeholder="Optional note...">

                    </div>

                </div>

            </div>

            <div class="modal-footer">

                <button
                    type="button"
                    class="btn btn-cancel"
                    onclick="closeAddModal()">

                    Cancel

                </button>

                <button
                    type="submit"
                    class="btn btn-primary">

                    <i class="fas fa-save"></i>

                    Save Payment

                </button>

            </div>

        </form>

    </div>

</div>

<!-- =========================================================
     EDIT PAYMENT MODAL
========================================================= -->

<?php if ($editPayment): ?>

<div
    class="modal active"
    id="editModal">

    <div class="modal-content">

        <div class="modal-header">

            <h2>
                Edit Payment
            </h2>

            <a
                href="payments.php"
                class="close">

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
                value="<?= $editPayment['id']; ?>">

            <div class="modal-body">

                <div class="modal-grid">

                    <!-- FEE -->

                    <div class="form-group">

                        <label>
                            Fee / Student *
                        </label>

                        <select
                            name="fee_id"
                            class="form-control"
                            required>

                            <option value="">
                                Select Fee
                            </option>

                            <?php if ($fees): ?>

                                <?php

                                $fees->data_seek(0);

                                while (
                                    $fee =
                                    $fees->fetch_assoc()
                                ):

                                ?>

                                    <option
                                        value="<?= $fee['id']; ?>"
                                        <?= $editPayment['fee_id']
                                            == $fee['id']
                                            ? 'selected'
                                            : ''; ?>>

                                        <?= htmlspecialchars(
                                            $fee['full_name']
                                            ?? 'Unknown'
                                        ); ?>

                                        —

                                        <?= htmlspecialchars(
                                            $fee['fee_type']
                                        ); ?>

                                        —

                                        $<?= number_format(
                                            $fee['amount'],
                                            2
                                        ); ?>

                                    </option>

                                <?php endwhile; ?>

                            <?php endif; ?>

                        </select>

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
                                $editPayment['amount']
                            ); ?>"
                            required>

                    </div>

                    <!-- DATE -->

                    <div class="form-group">

                        <label>
                            Payment Date *
                        </label>

                        <input
                            type="date"
                            name="payment_date"
                            class="form-control"
                            value="<?= htmlspecialchars(
                                $editPayment[
                                    'payment_date'
                                ]
                            ); ?>"
                            required>

                    </div>

                    <!-- METHOD -->

                    <div class="form-group">

                        <label>
                            Payment Method
                        </label>

                        <select
                            name="method"
                            class="form-control">

                            <option
                                value="Cash"
                                <?= $editPayment['method']
                                    === 'Cash'
                                    ? 'selected'
                                    : ''; ?>>

                                Cash

                            </option>

                            <option
                                value="Mobile Money"
                                <?= $editPayment['method']
                                    === 'Mobile Money'
                                    ? 'selected'
                                    : ''; ?>>

                                Mobile Money

                            </option>

                            <option
                                value="Bank"
                                <?= $editPayment['method']
                                    === 'Bank'
                                    ? 'selected'
                                    : ''; ?>>

                                Bank

                            </option>

                            <option
                                value="Card"
                                <?= $editPayment['method']
                                    === 'Card'
                                    ? 'selected'
                                    : ''; ?>>

                                Card

                            </option>

                        </select>

                    </div>

                    <!-- NOTE -->

                    <div
                        class="form-group"
                        style="grid-column:1/-1;">

                        <label>
                            Note
                        </label>

                        <input
                            type="text"
                            name="note"
                            class="form-control"
                            value="<?= htmlspecialchars(
                                $editPayment['note']
                                ?? ''
                            ); ?>"
                            placeholder="Optional note...">

                    </div>

                </div>

            </div>

            <div class="modal-footer">

                <a
                    href="payments.php"
                    class="btn btn-cancel"
                    style="text-decoration:none;">

                    Cancel

                </a>

                <button
                    type="submit"
                    class="btn btn-primary">

                    <i class="fas fa-save"></i>

                    Update Payment

                </button>

            </div>

        </form>

    </div>

</div>

<?php endif; ?>

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

/* =========================================================
   ADD MODAL
========================================================= */

function openAddModal() {

    const modal =
        document.getElementById(
            "addModal"
        );

    if (modal) {

        modal.classList.add(
            "active"
        );
    }

}

function closeAddModal() {

    const modal =
        document.getElementById(
            "addModal"
        );

    if (modal) {

        modal.classList.remove(
            "active"
        );
    }

}

/* =========================================================
   CLOSE ADD MODAL WHEN CLICKING OUTSIDE
========================================================= */

const addModal =
    document.getElementById(
        "addModal"
    );

if (addModal) {

    addModal.addEventListener(
        "click",
        function (event) {

            if (
                event.target === this
            ) {

                closeAddModal();

            }

        }
    );

}

/* =========================================================
   DARK / LIGHT MODE
========================================================= */

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

            themeIcon.classList
                .remove(
                    "fa-moon"
                );

            themeIcon.classList
                .add(
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

            themeIcon.classList
                .remove(
                    "fa-sun"
                );

            themeIcon.classList
                .add(
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

/* =========================================================
   APPLY SAVED THEME
========================================================= */

const savedTheme =
    localStorage.getItem(
        "theme"
    ) || "light";

applyTheme(
    savedTheme
);

/* =========================================================
   TOGGLE THEME
========================================================= */

if (themeToggle) {

    themeToggle.addEventListener(
        "click",
        function () {

            const isDark =
                document.documentElement
                    .classList
                    .contains(
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

        }

    }
);

</script>

</body>
</html>