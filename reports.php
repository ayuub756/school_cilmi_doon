<?php
session_start();

/* =========================================================
   AUTHENTICATION
========================================================= */
if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

/* =========================================================
   DATABASE CONNECTION
========================================================= */
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

/* =========================================================
   USER INFORMATION
========================================================= */
$userId   = (int)($_SESSION["user_id"] ?? 0);
$userName = $_SESSION["user_name"] ?? "Admin";
$userRole = $_SESSION["user_role"] ?? "Administrator";

/* =========================================================
   NOTIFICATION FUNCTION
========================================================= */
function getUnreadNotificationCount($conn, $user_id)
{
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM notifications
        WHERE user_id = ?
        AND is_read = 0
    ");

    if (!$stmt) {
        return 0;
    }

    $stmt->bind_param("i", $user_id);
    $stmt->execute();

    $result = $stmt->get_result();
    $row = $result->fetch_assoc();

    $stmt->close();

    return (int)($row["total"] ?? 0);
}

$unreadCount = getUnreadNotificationCount($conn, $userId);

/* =========================================================
   HELPER FUNCTIONS
========================================================= */
function tableExists($conn, $table)
{
    $table = $conn->real_escape_string($table);

    $result = $conn->query("
        SHOW TABLES LIKE '$table'
    ");

    return $result && $result->num_rows > 0;
}

function columnExists($conn, $table, $column)
{
    $table  = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);

    $result = $conn->query("
        SHOW COLUMNS FROM `$table`
        LIKE '$column'
    ");

    return $result && $result->num_rows > 0;
}

function safeCount($conn, $table)
{
    if (!tableExists($conn, $table)) {
        return 0;
    }

    $table = preg_replace(
        '/[^a-zA-Z0-9_]/',
        '',
        $table
    );

    $result = $conn->query("
        SELECT COUNT(*) AS total
        FROM `$table`
    ");

    if (!$result) {
        return 0;
    }

    $row = $result->fetch_assoc();

    return (int)($row["total"] ?? 0);
}

function safeNumber($value)
{
    return number_format((float)$value, 2);
}

/* =========================================================
   REPORT TYPE
========================================================= */
$report = $_GET["report"] ?? "overview";

$allowedReports = [
    "overview",
    "attendance",
    "results",
    "fees",
    "payments",
    "salary",
    "people"
];

if (!in_array($report, $allowedReports, true)) {
    $report = "overview";
}

/* =========================================================
   SEARCH + DATE FILTER
========================================================= */
$search = trim($_GET["search"] ?? "");
$from   = trim($_GET["from"] ?? "");
$to     = trim($_GET["to"] ?? "");

/* =========================================================
   STATISTICS
========================================================= */
$totalStudents = safeCount($conn, "students");
$totalTeachers = safeCount($conn, "teachers");
$totalParents  = safeCount($conn, "parents");
$totalClasses  = safeCount($conn, "classes");

/* =========================================================
   FINANCIAL TOTALS
========================================================= */
$totalFees     = 0;
$totalPayments = 0;
$totalSalary   = 0;

/* Fees */
if (tableExists($conn, "fees")) {

    if (columnExists($conn, "fees", "amount")) {

        $result = $conn->query("
            SELECT COALESCE(SUM(amount),0) AS total
            FROM fees
        ");

        if ($result) {
            $row = $result->fetch_assoc();
            $totalFees = (float)($row["total"] ?? 0);
        }
    }
}

/* Payments */
if (tableExists($conn, "payments")) {

    if (columnExists($conn, "payments", "amount")) {

        $result = $conn->query("
            SELECT COALESCE(SUM(amount),0) AS total
            FROM payments
        ");

        if ($result) {
            $row = $result->fetch_assoc();
            $totalPayments = (float)($row["total"] ?? 0);
        }
    }
}

/* Salary */
if (tableExists($conn, "salary")) {

    if (columnExists($conn, "salary", "amount")) {

        $result = $conn->query("
            SELECT COALESCE(SUM(amount),0) AS total
            FROM salary
        ");

        if ($result) {
            $row = $result->fetch_assoc();
            $totalSalary = (float)($row["total"] ?? 0);
        }
    }
}

/* =========================================================
   ATTENDANCE SUMMARY
========================================================= */
$attendancePresent = 0;
$attendanceAbsent  = 0;
$attendanceLate    = 0;

if (tableExists($conn, "attendance")) {

    if (columnExists($conn, "attendance", "status")) {

        $result = $conn->query("
            SELECT
                SUM(status = 'Present') AS present_count,
                SUM(status = 'Absent') AS absent_count,
                SUM(status = 'Late') AS late_count
            FROM attendance
        ");

        if ($result) {

            $row = $result->fetch_assoc();

            $attendancePresent =
                (int)($row["present_count"] ?? 0);

            $attendanceAbsent =
                (int)($row["absent_count"] ?? 0);

            $attendanceLate =
                (int)($row["late_count"] ?? 0);
        }
    }
}

/* =========================================================
   EXPORT CSV
========================================================= */
if (
    isset($_GET["export"]) &&
    $_GET["export"] === "csv"
) {

    $exportReport = $_GET["report"] ?? "overview";

    header("Content-Type: text/csv; charset=utf-8");

    header(
        "Content-Disposition: attachment; filename=school_report_" .
        $exportReport . "_" .
        date("Y-m-d") .
        ".csv"
    );

    $output = fopen("php://output", "w");

    /* OVERVIEW */
    if ($exportReport === "overview") {

        fputcsv($output, [
            "School Management Report"
        ]);

        fputcsv($output, [
            "Generated Date",
            date("Y-m-d H:i:s")
        ]);

        fputcsv($output, []);

        fputcsv($output, [
            "Category",
            "Total"
        ]);

        fputcsv($output, [
            "Students",
            $totalStudents
        ]);

        fputcsv($output, [
            "Teachers",
            $totalTeachers
        ]);

        fputcsv($output, [
            "Parents",
            $totalParents
        ]);

        fputcsv($output, [
            "Classes",
            $totalClasses
        ]);

        fputcsv($output, [
            "Fees",
            $totalFees
        ]);

        fputcsv($output, [
            "Payments",
            $totalPayments
        ]);

        fputcsv($output, [
            "Salary",
            $totalSalary
        ]);
    }

    /* ATTENDANCE */
    elseif ($exportReport === "attendance") {

        if (tableExists($conn, "attendance")) {

            $sql = "
                SELECT *
                FROM attendance
                WHERE 1=1
            ";

            $params = [];
            $types  = "";

            if (
                $from !== "" &&
                columnExists(
                    $conn,
                    "attendance",
                    "date"
                )
            ) {

                $sql .= " AND date >= ?";

                $params[] = $from;
                $types .= "s";
            }

            if (
                $to !== "" &&
                columnExists(
                    $conn,
                    "attendance",
                    "date"
                )
            ) {

                $sql .= " AND date <= ?";

                $params[] = $to;
                $types .= "s";
            }

            $sql .= " ORDER BY id DESC";

            $stmt = $conn->prepare($sql);

            if ($stmt) {

                if (!empty($params)) {
                    $stmt->bind_param(
                        $types,
                        ...$params
                    );
                }

                $stmt->execute();

                $result = $stmt->get_result();

                if ($result->num_rows > 0) {

                    fputcsv(
                        $output,
                        array_keys(
                            $result->fetch_assoc()
                        )
                    );

                    $result->data_seek(0);

                    while (
                        $row = $result->fetch_assoc()
                    ) {
                        fputcsv(
                            $output,
                            $row
                        );
                    }

                } else {

                    fputcsv(
                        $output,
                        ["No attendance records found"]
                    );
                }

                $stmt->close();
            }
        }
    }

    /* RESULTS */
    elseif ($exportReport === "results") {

        if (tableExists($conn, "results")) {

            $result = $conn->query("
                SELECT *
                FROM results
                ORDER BY id DESC
            ");

            if (
                $result &&
                $result->num_rows > 0
            ) {

                fputcsv(
                    $output,
                    array_keys(
                        $result->fetch_assoc()
                    )
                );

                $result->data_seek(0);

                while (
                    $row = $result->fetch_assoc()
                ) {
                    fputcsv(
                        $output,
                        $row
                    );
                }

            } else {

                fputcsv(
                    $output,
                    ["No result records found"]
                );
            }
        }
    }

    /* FEES */
    elseif ($exportReport === "fees") {

        if (tableExists($conn, "fees")) {

            $result = $conn->query("
                SELECT *
                FROM fees
                ORDER BY id DESC
            ");

            if (
                $result &&
                $result->num_rows > 0
            ) {

                fputcsv(
                    $output,
                    array_keys(
                        $result->fetch_assoc()
                    )
                );

                $result->data_seek(0);

                while (
                    $row = $result->fetch_assoc()
                ) {
                    fputcsv(
                        $output,
                        $row
                    );
                }

            } else {

                fputcsv(
                    $output,
                    ["No fee records found"]
                );
            }
        }
    }

    /* PAYMENTS */
    elseif ($exportReport === "payments") {

        if (tableExists($conn, "payments")) {

            $result = $conn->query("
                SELECT *
                FROM payments
                ORDER BY id DESC
            ");

            if (
                $result &&
                $result->num_rows > 0
            ) {

                fputcsv(
                    $output,
                    array_keys(
                        $result->fetch_assoc()
                    )
                );

                $result->data_seek(0);

                while (
                    $row = $result->fetch_assoc()
                ) {
                    fputcsv(
                        $output,
                        $row
                    );
                }

            } else {

                fputcsv(
                    $output,
                    ["No payment records found"]
                );
            }
        }
    }

    /* SALARY */
    elseif ($exportReport === "salary") {

        if (tableExists($conn, "salary")) {

            $result = $conn->query("
                SELECT *
                FROM salary
                ORDER BY id DESC
            ");

            if (
                $result &&
                $result->num_rows > 0
            ) {

                fputcsv(
                    $output,
                    array_keys(
                        $result->fetch_assoc()
                    )
                );

                $result->data_seek(0);

                while (
                    $row = $result->fetch_assoc()
                ) {
                    fputcsv(
                        $output,
                        $row
                    );
                }

            } else {

                fputcsv(
                    $output,
                    ["No salary records found"]
                );
            }
        }
    }

    /* PEOPLE */
    elseif ($exportReport === "people") {

        fputcsv($output, [
            "Category",
            "Total"
        ]);

        fputcsv($output, [
            "Students",
            $totalStudents
        ]);

        fputcsv($output, [
            "Teachers",
            $totalTeachers
        ]);

        fputcsv($output, [
            "Parents",
            $totalParents
        ]);

        fputcsv($output, [
            "Classes",
            $totalClasses
        ]);
    }

    fclose($output);
    exit;
}

/* =========================================================
   ATTENDANCE REPORT DATA
========================================================= */
$attendanceRows = [];

if (
    $report === "attendance" &&
    tableExists($conn, "attendance")
) {

    $sql = "
        SELECT *
        FROM attendance
        WHERE 1=1
    ";

    $params = [];
    $types  = "";

    if ($search !== "") {

        $searchLike = "%" . $search . "%";

        if (
            columnExists(
                $conn,
                "attendance",
                "student_id"
            )
        ) {

            $sql .= "
                AND CAST(student_id AS CHAR) LIKE ?
            ";

            $params[] = $searchLike;
            $types .= "s";
        }
    }

    if (
        $from !== "" &&
        columnExists(
            $conn,
            "attendance",
            "date"
        )
    ) {

        $sql .= " AND date >= ?";

        $params[] = $from;
        $types .= "s";
    }

    if (
        $to !== "" &&
        columnExists(
            $conn,
            "attendance",
            "date"
        )
    ) {

        $sql .= " AND date <= ?";

        $params[] = $to;
        $types .= "s";
    }

    $sql .= " ORDER BY id DESC LIMIT 500";

    $stmt = $conn->prepare($sql);

    if ($stmt) {

        if (!empty($params)) {

            $stmt->bind_param(
                $types,
                ...$params
            );
        }

        $stmt->execute();

        $result = $stmt->get_result();

        while (
            $row = $result->fetch_assoc()
        ) {

            $attendanceRows[] = $row;
        }

        $stmt->close();
    }
}

/* =========================================================
   RESULTS REPORT DATA
========================================================= */
$resultRows = [];

if (
    $report === "results" &&
    tableExists($conn, "results")
) {

    $result = $conn->query("
        SELECT *
        FROM results
        ORDER BY id DESC
        LIMIT 500
    ");

    if ($result) {

        while (
            $row = $result->fetch_assoc()
        ) {

            $resultRows[] = $row;
        }
    }
}

/* =========================================================
   FEES REPORT DATA
========================================================= */
$feeRows = [];

if (
    $report === "fees" &&
    tableExists($conn, "fees")
) {

    $result = $conn->query("
        SELECT *
        FROM fees
        ORDER BY id DESC
        LIMIT 500
    ");

    if ($result) {

        while (
            $row = $result->fetch_assoc()
        ) {

            $feeRows[] = $row;
        }
    }
}

/* =========================================================
   PAYMENTS REPORT DATA
========================================================= */
$paymentRows = [];

if (
    $report === "payments" &&
    tableExists($conn, "payments")
) {

    $result = $conn->query("
        SELECT *
        FROM payments
        ORDER BY id DESC
        LIMIT 500
    ");

    if ($result) {

        while (
            $row = $result->fetch_assoc()
        ) {

            $paymentRows[] = $row;
        }
    }
}

/* =========================================================
   SALARY REPORT DATA
========================================================= */
$salaryRows = [];

if (
    $report === "salary" &&
    tableExists($conn, "salary")
) {

    $result = $conn->query("
        SELECT *
        FROM salary
        ORDER BY id DESC
        LIMIT 500
    ");

    if ($result) {

        while (
            $row = $result->fetch_assoc()
        ) {

            $salaryRows[] = $row;
        }
    }
}

/* =========================================================
   PEOPLE DATA
========================================================= */
$peopleRows = [

    [
        "category" => "Students",
        "total" => $totalStudents,
        "icon" => "fa-user-graduate"
    ],

    [
        "category" => "Teachers",
        "total" => $totalTeachers,
        "icon" => "fa-chalkboard-teacher"
    ],

    [
        "category" => "Parents",
        "total" => $totalParents,
        "icon" => "fa-users"
    ],

    [
        "category" => "Classes",
        "total" => $totalClasses,
        "icon" => "fa-school"
    ]

];

/* =========================================================
   PAGE TITLE
========================================================= */
$pageTitles = [

    "overview"   => "Reports Overview",
    "attendance" => "Attendance Report",
    "results"    => "Results Report",
    "fees"       => "Fees Report",
    "payments"   => "Payments Report",
    "salary"     => "Salary Report",
    "people"     => "People Report"

];

$pageTitle =
    $pageTitles[$report] ?? "Reports";

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
    <?php echo htmlspecialchars($pageTitle); ?>
    - Cilmi_Doon
</title>

<!-- FONT AWESOME -->
<link
    rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
>

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

    min-height: 100vh;

    transition:
        background-color .3s ease,
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

    top: 0;
    left: 0;

    width: 260px;
    height: 100vh;

    background: #0f172a;

    color: #fff;

    z-index: 1000;

    display: flex;

    flex-direction: column;

    transition: .3s ease;

    overflow-y: auto;
}

.logo {

    height: 80px;

    display: flex;

    align-items: center;

    padding: 15px 20px;

    border-bottom:
        1px solid
        rgba(255,255,255,.08);
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

    margin-right: 12px;
}

.logo-text {

    font-size: 18px;

    font-weight: 800;
}

.logo-text small {

    display: block;

    font-size: 11px;

    font-weight: 400;

    color: #94a3b8;

    margin-top: 3px;
}

.menu {

    list-style: none;

    padding: 18px 12px 90px;
}

.menu-title {

    color: #64748b;

    font-size: 11px;

    text-transform: uppercase;

    letter-spacing: 1px;

    font-weight: 700;

    padding: 18px 12px 8px;
}

.menu li a {

    display: flex;

    align-items: center;

    gap: 13px;

    padding: 12px 14px;

    margin-bottom: 4px;

    color: #cbd5e1;

    border-radius: 9px;

    font-size: 14px;

    transition: .2s ease;
}

.menu li a i {

    width: 20px;

    text-align: center;

    font-size: 15px;
}

.menu li a:hover {

    background: #1e293b;

    color: #fff;
}

.menu li a[href="reports.php"] {

    background: #2563eb;

    color: #fff;
}

.logout {

    position: absolute;

    bottom: 0;
    left: 0;

    width: 100%;

    padding: 14px;

    background: #0f172a;

    border-top:
        1px solid
        rgba(255,255,255,.08);
}

.logout a {

    display: flex;

    align-items: center;

    gap: 13px;

    padding: 12px 14px;

    color: #cbd5e1;

    border-radius: 9px;

    transition: .2s;
}

.logout a:hover {

    background: #dc2626;

    color: #fff;
}


/* =========================================================
   OVERLAY
========================================================= */

.overlay {

    display: none;

    position: fixed;

    inset: 0;

    background:
        rgba(15,23,42,.55);

    z-index: 900;
}

.overlay.active {
    display: block;
}


/* =========================================================
   MAIN
========================================================= */

.main {

    margin-left: 260px;

    min-height: 100vh;
}


/* =========================================================
   TOPBAR
========================================================= */

.topbar {

    height: 70px;

    background: #fff;

    border-bottom:
        1px solid
        #e2e8f0;

    display: flex;

    align-items: center;

    justify-content: flex-end;

    padding: 0 30px;

    position: sticky;

    top: 0;

    z-index: 800;

    transition:
        background-color .3s ease,
        border-color .3s ease;
}

.menu-btn {

    display: none;

    border: 0;

    background: transparent;

    font-size: 22px;

    cursor: pointer;

    color: #0f172a;
}


/* =========================================================
   TOP ACTIONS
========================================================= */

.top-actions {

    display: flex;

    align-items: center;

    gap: 12px;
}


/* =========================================================
   THEME BUTTON
========================================================= */

.theme-btn {

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

    transition: .3s ease;
}

.theme-btn:hover {

    background: #e2e8f0;

    color: #2563eb;

    transform: rotate(15deg);
}


/* =========================================================
   NOTIFICATION
========================================================= */

.notification {

    position: relative;

    width: 38px;
    height: 38px;

    display: flex;

    align-items: center;
    justify-content: center;

    color: #475569;

    border-radius: 50%;

    transition: .2s;
}

.notification:hover {

    background: #f1f5f9;

    color: #2563eb;
}

.notification i {
    font-size: 18px;
}

.notification-badge {

    position: absolute;

    top: -2px;
    right: -2px;

    min-width: 18px;
    height: 18px;

    padding: 0 5px;

    border-radius: 20px;

    background: #ef4444;

    color: #fff;

    font-size: 10px;

    display: flex;

    align-items: center;
    justify-content: center;

    font-weight: 700;

    border:
        2px solid
        #fff;
}


/* =========================================================
   USER
========================================================= */

.user-box {

    display: flex;

    align-items: center;

    gap: 10px;
}

.avatar {

    width: 40px;
    height: 40px;

    border-radius: 50%;

    background: #2563eb;

    color: #fff;

    display: flex;

    align-items: center;
    justify-content: center;

    font-weight: 700;
}

.user-info strong {

    display: block;

    font-size: 13px;
}

.user-info span {

    display: block;

    color: #64748b;

    font-size: 11px;

    margin-top: 2px;
}


/* =========================================================
   CONTENT
========================================================= */

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

    font-size: 26px;

    margin-bottom: 6px;
}

.page-header p {

    color: #64748b;

    font-size: 14px;
}

.header-actions {

    display: flex;

    gap: 10px;

    flex-wrap: wrap;
}


/* =========================================================
   BUTTONS
========================================================= */

.btn {

    border: 0;

    border-radius: 8px;

    padding: 10px 15px;

    font-size: 13px;

    font-weight: 600;

    cursor: pointer;

    display: inline-flex;

    align-items: center;

    justify-content: center;

    gap: 8px;

    transition: .2s;
}

.btn-primary {

    background: #2563eb;

    color: #fff;
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

.btn-success {

    background: #16a34a;

    color: #fff;
}

.btn-success:hover {
    background: #15803d;
}


/* =========================================================
   STAT CARDS
========================================================= */

.stats-grid {

    display: grid;

    grid-template-columns:
        repeat(4, 1fr);

    gap: 18px;

    margin-bottom: 25px;
}

.stat-card {

    background: #fff;

    border:
        1px solid
        #e2e8f0;

    border-radius: 14px;

    padding: 20px;

    display: flex;

    align-items: center;

    justify-content: space-between;

    box-shadow:
        0 2px 5px
        rgba(15,23,42,.03);

    transition:
        background-color .3s ease,
        border-color .3s ease,
        transform .2s ease;
}

.stat-card:hover {
    transform: translateY(-2px);
}

.stat-card .info span {

    display: block;

    color: #64748b;

    font-size: 12px;

    margin-bottom: 7px;
}

.stat-card .info strong {

    font-size: 24px;
}

.stat-card .icon {

    width: 48px;
    height: 48px;

    background: #eff6ff;

    color: #2563eb;

    border-radius: 12px;

    display: flex;

    align-items: center;
    justify-content: center;

    font-size: 19px;
}


/* =========================================================
   REPORT NAVIGATION
========================================================= */

.report-nav {

    background: #fff;

    border:
        1px solid
        #e2e8f0;

    border-radius: 14px;

    padding: 8px;

    display: flex;

    gap: 6px;

    flex-wrap: wrap;

    margin-bottom: 20px;
}

.report-nav a {

    padding: 10px 15px;

    border-radius: 8px;

    font-size: 13px;

    color: #64748b;

    display: flex;

    align-items: center;

    gap: 8px;

    transition: .2s;
}

.report-nav a:hover {

    background: #f1f5f9;

    color: #2563eb;
}

.report-nav a.active {

    background: #2563eb;

    color: #fff;
}


/* =========================================================
   FILTER
========================================================= */

.filter-card {

    background: #fff;

    border:
        1px solid
        #e2e8f0;

    border-radius: 14px;

    padding: 20px;

    margin-bottom: 20px;
}

.filter-grid {

    display: grid;

    grid-template-columns:
        2fr 1fr 1fr auto auto;

    gap: 12px;

    align-items: end;
}

.form-group label {

    display: block;

    font-size: 12px;

    font-weight: 600;

    color: #475569;

    margin-bottom: 7px;
}

.form-control {

    width: 100%;

    height: 42px;

    border:
        1px solid
        #cbd5e1;

    border-radius: 8px;

    padding: 0 12px;

    outline: none;

    background: #fff;

    color: #0f172a;

    font-size: 13px;

    transition: .2s;
}

.form-control:focus {

    border-color: #2563eb;

    box-shadow:
        0 0 0 3px
        rgba(37,99,235,.10);
}


/* =========================================================
   REPORT CARD
========================================================= */

.report-card {

    background: #fff;

    border:
        1px solid
        #e2e8f0;

    border-radius: 14px;

    overflow: hidden;

    margin-bottom: 20px;
}

.report-card-header {

    padding: 18px 20px;

    border-bottom:
        1px solid
        #e2e8f0;

    display: flex;

    justify-content: space-between;

    align-items: center;

    gap: 15px;
}

.report-card-header h2 {

    font-size: 16px;
}

.report-card-header p {

    color: #64748b;

    font-size: 12px;

    margin-top: 4px;
}


/* =========================================================
   TABLE
========================================================= */

.table-wrapper {

    width: 100%;

    overflow-x: auto;
}

table {

    width: 100%;

    border-collapse: collapse;

    min-width: 700px;
}

th {

    background: #f8fafc;

    color: #475569;

    font-size: 11px;

    text-transform: uppercase;

    letter-spacing: .5px;

    text-align: left;

    padding: 13px 15px;

    white-space: nowrap;
}

td {

    padding: 14px 15px;

    border-top:
        1px solid
        #e2e8f0;

    font-size: 13px;

    color: #334155;
}

tr:hover td {
    background: #f8fafc;
}


/* =========================================================
   BADGES
========================================================= */

.badge {

    display: inline-flex;

    align-items: center;

    justify-content: center;

    padding: 5px 9px;

    border-radius: 20px;

    font-size: 11px;

    font-weight: 600;
}

.badge-success {

    background: #dcfce7;

    color: #15803d;
}

.badge-danger {

    background: #fee2e2;

    color: #dc2626;
}

.badge-warning {

    background: #fef3c7;

    color: #b45309;
}

.badge-info {

    background: #dbeafe;

    color: #1d4ed8;
}


/* =========================================================
   OVERVIEW
========================================================= */

.overview-grid {

    display: grid;

    grid-template-columns:
        repeat(2, 1fr);

    gap: 20px;
}

.overview-box {

    background: #fff;

    border:
        1px solid
        #e2e8f0;

    border-radius: 14px;

    padding: 22px;
}

.overview-box h3 {

    font-size: 15px;

    margin-bottom: 18px;
}

.metric {

    display: flex;

    align-items: center;

    justify-content: space-between;

    padding: 12px 0;

    border-bottom:
        1px solid
        #f1f5f9;
}

.metric:last-child {
    border-bottom: 0;
}

.metric span {

    color: #64748b;

    font-size: 13px;
}

.metric strong {
    font-size: 15px;
}


/* =========================================================
   EMPTY
========================================================= */

.empty {

    text-align: center;

    padding: 55px 20px;

    color: #64748b;
}

.empty i {

    font-size: 40px;

    color: #cbd5e1;

    margin-bottom: 12px;
}

.empty h3 {

    color: #475569;

    font-size: 16px;

    margin-bottom: 6px;
}

.empty p {
    font-size: 13px;
}


/* =========================================================
   DARK MODE
========================================================= */

body.dark-mode {

    background: #0f172a;

    color: #e2e8f0;
}


/* SIDEBAR */

body.dark-mode .sidebar {
    background: #020617;
}

body.dark-mode .menu li a {
    color: #94a3b8;
}

body.dark-mode .menu li a:hover {
    background: #1e293b;
    color: #fff;
}

body.dark-mode .menu li a[href="reports.php"] {
    background: #2563eb;
    color: #fff;
}

body.dark-mode .logout {
    background: #020617;
    border-top-color: #1e293b;
}

body.dark-mode .logout a {
    color: #94a3b8;
}

body.dark-mode .logout a:hover {
    background: #dc2626;
    color: #fff;
}


/* TOPBAR */

body.dark-mode .topbar {

    background: #111827;

    border-bottom-color: #1e293b;
}

body.dark-mode .menu-btn {
    color: #e2e8f0;
}


/* THEME BUTTON */

body.dark-mode .theme-btn {

    background: #1e293b;

    color: #facc15;
}

body.dark-mode .theme-btn:hover {

    background: #334155;

    color: #fde047;
}


/* NOTIFICATION */

body.dark-mode .notification {
    color: #cbd5e1;
}

body.dark-mode .notification:hover {

    background: #1e293b;

    color: #60a5fa;
}

body.dark-mode .notification-badge {
    border-color: #111827;
}


/* USER */

body.dark-mode .user-info strong {
    color: #f8fafc;
}

body.dark-mode .user-info span {
    color: #94a3b8;
}


/* PAGE */

body.dark-mode .page-header h1 {
    color: #f8fafc;
}

body.dark-mode .page-header p {
    color: #94a3b8;
}


/* STAT CARDS */

body.dark-mode .stat-card {

    background: #111827;

    border-color: #1e293b;

    box-shadow:
        0 2px 8px
        rgba(0,0,0,.15);
}

body.dark-mode .stat-card .info span {
    color: #94a3b8;
}

body.dark-mode .stat-card .info strong {
    color: #f8fafc;
}

body.dark-mode .stat-card .icon {

    background: #172554;

    color: #60a5fa;
}


/* REPORT NAV */

body.dark-mode .report-nav {

    background: #111827;

    border-color: #1e293b;
}

body.dark-mode .report-nav a {
    color: #94a3b8;
}

body.dark-mode .report-nav a:hover {

    background: #1e293b;

    color: #60a5fa;
}

body.dark-mode .report-nav a.active {

    background: #2563eb;

    color: #fff;
}


/* FILTER */

body.dark-mode .filter-card {

    background: #111827;

    border-color: #1e293b;
}

body.dark-mode .form-group label {
    color: #cbd5e1;
}

body.dark-mode .form-control {

    background: #0f172a;

    color: #e2e8f0;

    border-color: #334155;
}

body.dark-mode .form-control::placeholder {
    color: #64748b;
}

body.dark-mode .form-control:focus {

    border-color: #3b82f6;

    background: #0f172a;
}

body.dark-mode select option {

    background: #0f172a;

    color: #e2e8f0;
}


/* REPORT CARD */

body.dark-mode .report-card {

    background: #111827;

    border-color: #1e293b;
}

body.dark-mode .report-card-header {

    border-bottom-color: #1e293b;
}

body.dark-mode .report-card-header h2 {
    color: #f8fafc;
}

body.dark-mode .report-card-header p {
    color: #94a3b8;
}


/* TABLE */

body.dark-mode th {

    background: #0f172a;

    color: #94a3b8;
}

body.dark-mode td {

    border-top-color: #1e293b;

    color: #cbd5e1;
}

body.dark-mode tr:hover td {
    background: #172033;
}


/* OVERVIEW */

body.dark-mode .overview-box {

    background: #111827;

    border-color: #1e293b;
}

body.dark-mode .overview-box h3 {
    color: #f8fafc;
}

body.dark-mode .metric {

    border-bottom-color: #1e293b;
}

body.dark-mode .metric span {
    color: #94a3b8;
}

body.dark-mode .metric strong {
    color: #f8fafc;
}


/* EMPTY */

body.dark-mode .empty {
    color: #94a3b8;
}

body.dark-mode .empty i {
    color: #475569;
}

body.dark-mode .empty h3 {
    color: #cbd5e1;
}


/* SECONDARY BUTTON */

body.dark-mode .btn-secondary {

    background: #1e293b;

    color: #cbd5e1;
}

body.dark-mode .btn-secondary:hover {

    background: #334155;

    color: #fff;
}


/* =========================================================
   PRINT
========================================================= */

@media print {

    .sidebar,
    .topbar,
    .report-nav,
    .filter-card,
    .header-actions {
        display: none !important;
    }

    .main {
        margin-left: 0;
    }

    .content {
        padding: 0;
    }

    .report-card {
        border: 0;
    }

    body {
        background: #fff;
        color: #000;
    }

    .stats-grid {
        grid-template-columns:
            repeat(4, 1fr);
    }
}


/* =========================================================
   1200px
========================================================= */

@media (max-width: 1200px) {

    .stats-grid {

        grid-template-columns:
            repeat(2, 1fr);
    }

    .filter-grid {

        grid-template-columns:
            repeat(2, 1fr);
    }

    .filter-grid .form-group:first-child {

        grid-column: span 2;
    }

    .overview-grid {

        grid-template-columns: 1fr;
    }
}


/* =========================================================
   900px
========================================================= */

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

        display: block;

        margin-right: auto;
    }

    .topbar {

        justify-content:
            space-between;

        padding: 0 20px;
    }

    .content {

        padding: 20px;
    }

    .page-header {

        align-items: flex-start;

        flex-direction: column;
    }
}


/* =========================================================
   650px
========================================================= */

@media (max-width: 650px) {

    .stats-grid {

        grid-template-columns: 1fr;
    }

    .filter-grid {

        grid-template-columns: 1fr;
    }

    .filter-grid .form-group:first-child {

        grid-column: span 1;
    }

    .top-actions {

        gap: 6px;
    }

    .user-info {

        display: none;
    }

    .report-nav {

        overflow-x: auto;

        flex-wrap: nowrap;
    }

    .report-nav a {

        white-space: nowrap;
    }
}


/* =========================================================
   480px
========================================================= */

@media (max-width: 480px) {

    .content {
        padding: 15px;
    }

    .topbar {
        padding: 0 15px;
    }

    .page-header h1 {
        font-size: 21px;
    }

    .page-header p {
        font-size: 12px;
    }

    .header-actions {
        width: 100%;
    }

    .header-actions .btn {
        flex: 1;
    }

    .report-card-header {

        align-items: flex-start;

        flex-direction: column;
    }

    .stat-card {
        padding: 16px;
    }

    .theme-btn {
        width: 36px;
        height: 36px;
    }

    .notification {
        width: 36px;
        height: 36px;
    }
}

</style>

</head>


<body>


<!-- =========================================================
     SIDEBAR
========================================================= -->

<aside
    class="sidebar"
    id="sidebar"
>

    <div class="logo">

        <div class="logo-icon">

            <i class="fas fa-school"></i>

        </div>

        <div class="logo-text">

            Cilmi_Doon

            <small>
                School Management
            </small>

        </div>

    </div>


    <ul class="menu">

        <li class="menu-title">
            Main Menu
        </li>

        <li>
            <a href="dashboard.php">
                <i class="fas fa-chart-line"></i>
                <span>Dashboard</span>
            </a>
        </li>


        <li class="menu-title">
            Academic
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
                <i class="fas fa-award"></i>
                <span>Results</span>
            </a>
        </li>


        <li class="menu-title">
            Finance
        </li>

        <li>
            <a href="fees.php">
                <i class="fas fa-file-invoice-dollar"></i>
                <span>Fees</span>
            </a>
        </li>

        <li>
            <a href="payments.php">
                <i class="fas fa-money-bill-wave"></i>
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
            Reports
        </li>

        <li>
            <a href="reports.php">
                <i class="fas fa-chart-pie"></i>
                <span>Reports</span>
            </a>
        </li>


        <li class="menu-title">
            System
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


<!-- =========================================================
     MAIN
========================================================= -->

<div class="main">


<!-- =========================================================
     TOPBAR
========================================================= -->

<header class="topbar">


    <button
        class="menu-btn"
        id="menuBtn"
        type="button"
    >

        <i class="fas fa-bars"></i>

    </button>


    <div class="top-actions">


        <!-- DARK / LIGHT MODE -->

        <button
            type="button"
            class="theme-btn"
            id="themeBtn"
            title="Switch to Dark Mode"
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

                    <?php

                    echo $unreadCount > 99
                        ? "99+"
                        : $unreadCount;

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


<!-- =========================================================
     CONTENT
========================================================= -->

<main class="content">


<!-- PAGE HEADER -->

<div class="page-header">

    <div>

        <h1>

            <?php

            echo htmlspecialchars(
                $pageTitle
            );

            ?>

        </h1>

        <p>
            View and analyze school management
            information and reports.
        </p>

    </div>


    <div class="header-actions">


        <!-- EXPORT -->

        <a
            href="reports.php?report=<?php echo urlencode($report); ?>&search=<?php echo urlencode($search); ?>&from=<?php echo urlencode($from); ?>&to=<?php echo urlencode($to); ?>&export=csv"
            class="btn btn-success"
        >

            <i class="fas fa-file-csv"></i>

            Export CSV

        </a>


        <!-- PRINT -->

        <button
            type="button"
            class="btn btn-primary"
            onclick="window.print()"
        >

            <i class="fas fa-print"></i>

            Print

        </button>

    </div>

</div>


<!-- =========================================================
     STATISTICS
========================================================= -->

<div class="stats-grid">


    <div class="stat-card">

        <div class="info">

            <span>
                Total Students
            </span>

            <strong>
                <?php echo $totalStudents; ?>
            </strong>

        </div>

        <div class="icon">

            <i class="fas fa-user-graduate"></i>

        </div>

    </div>


    <div class="stat-card">

        <div class="info">

            <span>
                Total Teachers
            </span>

            <strong>
                <?php echo $totalTeachers; ?>
            </strong>

        </div>

        <div class="icon">

            <i class="fas fa-chalkboard-teacher"></i>

        </div>

    </div>


    <div class="stat-card">

        <div class="info">

            <span>
                Total Parents
            </span>

            <strong>
                <?php echo $totalParents; ?>
            </strong>

        </div>

        <div class="icon">

            <i class="fas fa-users"></i>

        </div>

    </div>


    <div class="stat-card">

        <div class="info">

            <span>
                Total Classes
            </span>

            <strong>
                <?php echo $totalClasses; ?>
            </strong>

        </div>

        <div class="icon">

            <i class="fas fa-school"></i>

        </div>

    </div>

</div>


<!-- =========================================================
     REPORT NAVIGATION
========================================================= -->

<div class="report-nav">


    <a
        href="reports.php?report=overview"
        class="<?php echo $report === 'overview' ? 'active' : ''; ?>"
    >

        <i class="fas fa-chart-pie"></i>

        Overview

    </a>


    <a
        href="reports.php?report=attendance"
        class="<?php echo $report === 'attendance' ? 'active' : ''; ?>"
    >

        <i class="fas fa-calendar-check"></i>

        Attendance

    </a>


    <a
        href="reports.php?report=results"
        class="<?php echo $report === 'results' ? 'active' : ''; ?>"
    >

        <i class="fas fa-award"></i>

        Results

    </a>


    <a
        href="reports.php?report=fees"
        class="<?php echo $report === 'fees' ? 'active' : ''; ?>"
    >

        <i class="fas fa-file-invoice-dollar"></i>

        Fees

    </a>


    <a
        href="reports.php?report=payments"
        class="<?php echo $report === 'payments' ? 'active' : ''; ?>"
    >

        <i class="fas fa-money-bill-wave"></i>

        Payments

    </a>


    <a
        href="reports.php?report=salary"
        class="<?php echo $report === 'salary' ? 'active' : ''; ?>"
    >

        <i class="fas fa-wallet"></i>

        Salary

    </a>


    <a
        href="reports.php?report=people"
        class="<?php echo $report === 'people' ? 'active' : ''; ?>"
    >

        <i class="fas fa-users"></i>

        People

    </a>

</div>


<!-- =========================================================
     FILTER
========================================================= -->

<?php if (
    $report !== "overview" &&
    $report !== "people"
): ?>

<form
    method="GET"
    action="reports.php"
    class="filter-card"
>

    <input
        type="hidden"
        name="report"
        value="<?php echo htmlspecialchars($report); ?>"
    >


    <div class="filter-grid">


        <div class="form-group">

            <label>
                Search
            </label>

            <input
                type="text"
                name="search"
                class="form-control"
                placeholder="Search report..."
                value="<?php echo htmlspecialchars($search); ?>"
            >

        </div>


        <div class="form-group">

            <label>
                From Date
            </label>

            <input
                type="date"
                name="from"
                class="form-control"
                value="<?php echo htmlspecialchars($from); ?>"
            >

        </div>


        <div class="form-group">

            <label>
                To Date
            </label>

            <input
                type="date"
                name="to"
                class="form-control"
                value="<?php echo htmlspecialchars($to); ?>"
            >

        </div>


        <button
            type="submit"
            class="btn btn-primary"
        >

            <i class="fas fa-filter"></i>

            Apply

        </button>


        <a
            href="reports.php?report=<?php echo urlencode($report); ?>"
            class="btn btn-secondary"
        >

            <i class="fas fa-rotate-left"></i>

            Reset

        </a>

    </div>

</form>

<?php endif; ?>


<!-- =========================================================
     OVERVIEW
========================================================= -->

<?php if ($report === "overview"): ?>

<div class="overview-grid">


    <!-- PEOPLE -->

    <div class="overview-box">

        <h3>

            <i class="fas fa-users"></i>

            People Summary

        </h3>


        <div class="metric">

            <span>
                Students
            </span>

            <strong>
                <?php echo $totalStudents; ?>
            </strong>

        </div>


        <div class="metric">

            <span>
                Teachers
            </span>

            <strong>
                <?php echo $totalTeachers; ?>
            </strong>

        </div>


        <div class="metric">

            <span>
                Parents
            </span>

            <strong>
                <?php echo $totalParents; ?>
            </strong>

        </div>


        <div class="metric">

            <span>
                Classes
            </span>

            <strong>
                <?php echo $totalClasses; ?>
            </strong>

        </div>

    </div>


    <!-- FINANCE -->

    <div class="overview-box">

        <h3>

            <i class="fas fa-money-bill-wave"></i>

            Financial Summary

        </h3>


        <div class="metric">

            <span>
                Total Fees
            </span>

            <strong>
                $<?php echo safeNumber($totalFees); ?>
            </strong>

        </div>


        <div class="metric">

            <span>
                Total Payments
            </span>

            <strong>
                $<?php echo safeNumber($totalPayments); ?>
            </strong>

        </div>


        <div class="metric">

            <span>
                Outstanding
            </span>

            <strong>

                $<?php

                echo safeNumber(
                    max(
                        0,
                        $totalFees -
                        $totalPayments
                    )
                );

                ?>

            </strong>

        </div>


        <div class="metric">

            <span>
                Total Salary
            </span>

            <strong>
                $<?php echo safeNumber($totalSalary); ?>
            </strong>

        </div>

    </div>


    <!-- ATTENDANCE -->

    <div class="overview-box">

        <h3>

            <i class="fas fa-calendar-check"></i>

            Attendance Summary

        </h3>


        <div class="metric">

            <span>
                Present
            </span>

            <strong>

                <span class="badge badge-success">

                    <?php
                    echo $attendancePresent;
                    ?>

                </span>

            </strong>

        </div>


        <div class="metric">

            <span>
                Absent
            </span>

            <strong>

                <span class="badge badge-danger">

                    <?php
                    echo $attendanceAbsent;
                    ?>

                </span>

            </strong>

        </div>


        <div class="metric">

            <span>
                Late
            </span>

            <strong>

                <span class="badge badge-warning">

                    <?php
                    echo $attendanceLate;
                    ?>

                </span>

            </strong>

        </div>


        <div class="metric">

            <span>
                Total Attendance
            </span>

            <strong>

                <?php

                echo
                    $attendancePresent +
                    $attendanceAbsent +
                    $attendanceLate;

                ?>

            </strong>

        </div>

    </div>


    <!-- QUICK REPORTS -->

    <div class="overview-box">

        <h3>

            <i class="fas fa-chart-line"></i>

            Quick Reports

        </h3>


        <div class="metric">

            <span>
                Attendance
            </span>

            <a
                href="reports.php?report=attendance"
                class="btn btn-secondary"
            >
                View
            </a>

        </div>


        <div class="metric">

            <span>
                Results
            </span>

            <a
                href="reports.php?report=results"
                class="btn btn-secondary"
            >
                View
            </a>

        </div>


        <div class="metric">

            <span>
                Fees
            </span>

            <a
                href="reports.php?report=fees"
                class="btn btn-secondary"
            >
                View
            </a>

        </div>


        <div class="metric">

            <span>
                Payments
            </span>

            <a
                href="reports.php?report=payments"
                class="btn btn-secondary"
            >
                View
            </a>

        </div>

    </div>

</div>

<?php endif; ?>


<!-- =========================================================
     ATTENDANCE REPORT
========================================================= -->

<?php if ($report === "attendance"): ?>

<div class="report-card">

    <div class="report-card-header">

        <div>

            <h2>
                Attendance Records
            </h2>

            <p>
                Attendance information from the system.
            </p>

        </div>

        <span class="badge badge-info">

            <?php echo count($attendanceRows); ?>

            Records

        </span>

    </div>


    <?php if (!empty($attendanceRows)): ?>

    <div class="table-wrapper">

        <table>

            <thead>

                <tr>

                    <?php foreach (
                        array_keys(
                            $attendanceRows[0]
                        ) as $column
                    ): ?>

                    <th>

                        <?php

                        echo htmlspecialchars(
                            ucwords(
                                str_replace(
                                    "_",
                                    " ",
                                    $column
                                )
                            )
                        );

                        ?>

                    </th>

                    <?php endforeach; ?>

                </tr>

            </thead>


            <tbody>

                <?php foreach (
                    $attendanceRows as $row
                ): ?>

                <tr>

                    <?php foreach (
                        $row as $column => $value
                    ): ?>

                    <td>

                        <?php

                        if (
                            strtolower($column)
                            === "status"
                        ) {

                            $statusClass =
                                strtolower(
                                    trim(
                                        (string)$value
                                    )
                                );


                            if (
                                $statusClass
                                === "present"
                            ) {

                                echo
                                    '<span class="badge badge-success">'
                                    .
                                    htmlspecialchars(
                                        $value
                                    )
                                    .
                                    '</span>';

                            } elseif (
                                $statusClass
                                === "absent"
                            ) {

                                echo
                                    '<span class="badge badge-danger">'
                                    .
                                    htmlspecialchars(
                                        $value
                                    )
                                    .
                                    '</span>';

                            } elseif (
                                $statusClass
                                === "late"
                            ) {

                                echo
                                    '<span class="badge badge-warning">'
                                    .
                                    htmlspecialchars(
                                        $value
                                    )
                                    .
                                    '</span>';

                            } else {

                                echo htmlspecialchars(
                                    $value ?? ""
                                );
                            }

                        } else {

                            echo htmlspecialchars(
                                $value ?? ""
                            );
                        }

                        ?>

                    </td>

                    <?php endforeach; ?>

                </tr>

                <?php endforeach; ?>

            </tbody>

        </table>

    </div>

    <?php else: ?>

    <div class="empty">

        <i class="fas fa-calendar-xmark"></i>

        <h3>
            No Attendance Records
        </h3>

        <p>
            No attendance data was found
            for the selected filters.
        </p>

    </div>

    <?php endif; ?>

</div>

<?php endif; ?>


<!-- =========================================================
     RESULTS REPORT
========================================================= -->

<?php if ($report === "results"): ?>

<div class="report-card">

    <div class="report-card-header">

        <div>

            <h2>
                Results Records
            </h2>

            <p>
                Student examination results.
            </p>

        </div>

        <span class="badge badge-info">

            <?php echo count($resultRows); ?>

            Records

        </span>

    </div>


    <?php if (!empty($resultRows)): ?>

    <div class="table-wrapper">

        <table>

            <thead>

                <tr>

                    <?php foreach (
                        array_keys(
                            $resultRows[0]
                        ) as $column
                    ): ?>

                    <th>

                        <?php

                        echo htmlspecialchars(
                            ucwords(
                                str_replace(
                                    "_",
                                    " ",
                                    $column
                                )
                            )
                        );

                        ?>

                    </th>

                    <?php endforeach; ?>

                </tr>

            </thead>


            <tbody>

                <?php foreach (
                    $resultRows as $row
                ): ?>

                <tr>

                    <?php foreach (
                        $row as $value
                    ): ?>

                    <td>

                        <?php

                        echo htmlspecialchars(
                            $value ?? ""
                        );

                        ?>

                    </td>

                    <?php endforeach; ?>

                </tr>

                <?php endforeach; ?>

            </tbody>

        </table>

    </div>

    <?php else: ?>

    <div class="empty">

        <i class="fas fa-award"></i>

        <h3>
            No Results Found
        </h3>

        <p>
            There are no examination
            results available.
        </p>

    </div>

    <?php endif; ?>

</div>

<?php endif; ?>


<!-- =========================================================
     FEES REPORT
========================================================= -->

<?php if ($report === "fees"): ?>

<div class="report-card">

    <div class="report-card-header">

        <div>

            <h2>
                Fees Records
            </h2>

            <p>
                School fee information.
            </p>

        </div>

        <span class="badge badge-info">

            Total:
            $<?php echo safeNumber($totalFees); ?>

        </span>

    </div>


    <?php if (!empty($feeRows)): ?>

    <div class="table-wrapper">

        <table>

            <thead>

                <tr>

                    <?php foreach (
                        array_keys(
                            $feeRows[0]
                        ) as $column
                    ): ?>

                    <th>

                        <?php

                        echo htmlspecialchars(
                            ucwords(
                                str_replace(
                                    "_",
                                    " ",
                                    $column
                                )
                            )
                        );

                        ?>

                    </th>

                    <?php endforeach; ?>

                </tr>

            </thead>


            <tbody>

                <?php foreach (
                    $feeRows as $row
                ): ?>

                <tr>

                    <?php foreach (
                        $row as $column => $value
                    ): ?>

                    <td>

                        <?php

                        if (
                            strtolower($column)
                            === "amount"
                        ) {

                            echo "$"
                                .
                                safeNumber(
                                    $value
                                );

                        } else {

                            echo htmlspecialchars(
                                $value ?? ""
                            );
                        }

                        ?>

                    </td>

                    <?php endforeach; ?>

                </tr>

                <?php endforeach; ?>

            </tbody>

        </table>

    </div>

    <?php else: ?>

    <div class="empty">

        <i class="fas fa-file-invoice-dollar"></i>

        <h3>
            No Fee Records
        </h3>

        <p>
            No fee information was found.
        </p>

    </div>

    <?php endif; ?>

</div>

<?php endif; ?>


<!-- =========================================================
     PAYMENTS REPORT
========================================================= -->

<?php if ($report === "payments"): ?>

<div class="report-card">

    <div class="report-card-header">

        <div>

            <h2>
                Payment Records
            </h2>

            <p>
                All recorded school payments.
            </p>

        </div>

        <span class="badge badge-success">

            Total:
            $<?php echo safeNumber($totalPayments); ?>

        </span>

    </div>


    <?php if (!empty($paymentRows)): ?>

    <div class="table-wrapper">

        <table>

            <thead>

                <tr>

                    <?php foreach (
                        array_keys(
                            $paymentRows[0]
                        ) as $column
                    ): ?>

                    <th>

                        <?php

                        echo htmlspecialchars(
                            ucwords(
                                str_replace(
                                    "_",
                                    " ",
                                    $column
                                )
                            )
                        );

                        ?>

                    </th>

                    <?php endforeach; ?>

                </tr>

            </thead>


            <tbody>

                <?php foreach (
                    $paymentRows as $row
                ): ?>

                <tr>

                    <?php foreach (
                        $row as $column => $value
                    ): ?>

                    <td>

                        <?php

                        if (
                            strtolower($column)
                            === "amount"
                        ) {

                            echo "$"
                                .
                                safeNumber(
                                    $value
                                );

                        } else {

                            echo htmlspecialchars(
                                $value ?? ""
                            );
                        }

                        ?>

                    </td>

                    <?php endforeach; ?>

                </tr>

                <?php endforeach; ?>

            </tbody>

        </table>

    </div>

    <?php else: ?>

    <div class="empty">

        <i class="fas fa-money-bill-wave"></i>

        <h3>
            No Payment Records
        </h3>

        <p>
            No payment information was found.
        </p>

    </div>

    <?php endif; ?>

</div>

<?php endif; ?>


<!-- =========================================================
     SALARY REPORT
========================================================= -->

<?php if ($report === "salary"): ?>

<div class="report-card">

    <div class="report-card-header">

        <div>

            <h2>
                Salary Records
            </h2>

            <p>
                Employee and teacher salary information.
            </p>

        </div>

        <span class="badge badge-warning">

            Total:
            $<?php echo safeNumber($totalSalary); ?>

        </span>

    </div>


    <?php if (!empty($salaryRows)): ?>

    <div class="table-wrapper">

        <table>

            <thead>

                <tr>

                    <?php foreach (
                        array_keys(
                            $salaryRows[0]
                        ) as $column
                    ): ?>

                    <th>

                        <?php

                        echo htmlspecialchars(
                            ucwords(
                                str_replace(
                                    "_",
                                    " ",
                                    $column
                                )
                            )
                        );

                        ?>

                    </th>

                    <?php endforeach; ?>

                </tr>

            </thead>


            <tbody>

                <?php foreach (
                    $salaryRows as $row
                ): ?>

                <tr>

                    <?php foreach (
                        $row as $column => $value
                    ): ?>

                    <td>

                        <?php

                        if (
                            strtolower($column)
                            === "amount"
                        ) {

                            echo "$"
                                .
                                safeNumber(
                                    $value
                                );

                        } else {

                            echo htmlspecialchars(
                                $value ?? ""
                            );
                        }

                        ?>

                    </td>

                    <?php endforeach; ?>

                </tr>

                <?php endforeach; ?>

            </tbody>

        </table>

    </div>

    <?php else: ?>

    <div class="empty">

        <i class="fas fa-wallet"></i>

        <h3>
            No Salary Records
        </h3>

        <p>
            No salary information was found.
        </p>

    </div>

    <?php endif; ?>

</div>

<?php endif; ?>


<!-- =========================================================
     PEOPLE REPORT
========================================================= -->

<?php if ($report === "people"): ?>

<div class="report-card">

    <div class="report-card-header">

        <div>

            <h2>
                People Summary
            </h2>

            <p>
                Overview of people registered
                in the school system.
            </p>

        </div>

    </div>


    <div class="table-wrapper">

        <table>

            <thead>

                <tr>

                    <th>
                        Category
                    </th>

                    <th>
                        Total
                    </th>

                    <th>
                        Status
                    </th>

                </tr>

            </thead>


            <tbody>

                <?php foreach (
                    $peopleRows as $row
                ): ?>

                <tr>

                    <td>

                        <i
                            class="fas <?php echo htmlspecialchars($row["icon"]); ?>"
                            style="
                                width:25px;
                                color:#2563eb;
                            "
                        ></i>

                        <?php

                        echo htmlspecialchars(
                            $row["category"]
                        );

                        ?>

                    </td>


                    <td>

                        <strong>

                            <?php
                            echo $row["total"];
                            ?>

                        </strong>

                    </td>


                    <td>

                        <?php if (
                            $row["total"] > 0
                        ): ?>

                        <span
                            class="badge badge-success"
                        >
                            Available
                        </span>

                        <?php else: ?>

                        <span
                            class="badge badge-warning"
                        >
                            Empty
                        </span>

                        <?php endif; ?>

                    </td>

                </tr>

                <?php endforeach; ?>

            </tbody>

        </table>

    </div>

</div>

<?php endif; ?>


</main>

</div>


<!-- =========================================================
     JAVASCRIPT
========================================================= -->

<script>

/* =========================================================
   DARK / LIGHT MODE
========================================================= */

const themeBtn =
    document.getElementById("themeBtn");

const themeIcon =
    document.getElementById("themeIcon");


/* GET SAVED THEME */

const savedTheme =
    localStorage.getItem("schoolTheme");


/* APPLY SAVED THEME */

if (savedTheme === "dark") {

    document.body.classList.add(
        "dark-mode"
    );

    themeIcon.classList.remove(
        "fa-moon"
    );

    themeIcon.classList.add(
        "fa-sun"
    );

    themeBtn.title =
        "Switch to Light Mode";

}


/* =========================================================
   THEME BUTTON CLICK
========================================================= */

themeBtn.addEventListener(
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

            /* DARK MODE */

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

            themeBtn.title =
                "Switch to Light Mode";

        } else {

            /* LIGHT MODE */

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

            themeBtn.title =
                "Switch to Dark Mode";

        }

    }
);


/* =========================================================
   MOBILE SIDEBAR
========================================================= */

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


/* =========================================================
   CLOSE OVERLAY
========================================================= */

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


/* =========================================================
   CLOSE SIDEBAR WHEN LINK CLICKED
========================================================= */

document
    .querySelectorAll(".menu a")
    .forEach(function (link) {

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


/* =========================================================
   DATE VALIDATION
========================================================= */

const filterForm =
    document.querySelector(
        ".filter-card"
    );


if (filterForm) {

    filterForm.addEventListener(
        "submit",
        function (event) {

            const from =
                filterForm.querySelector(
                    'input[name="from"]'
                );

            const to =
                filterForm.querySelector(
                    'input[name="to"]'
                );


            if (
                from &&
                to &&
                from.value &&
                to.value &&
                from.value > to.value
            ) {

                event.preventDefault();

                alert(
                    "From Date cannot be later than To Date."
                );

            }

        }
    );

}


/* =========================================================
   RESPONSIVE SIDEBAR
========================================================= */

window.addEventListener(
    "resize",
    function () {

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