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


/* =========================
   UNREAD NOTIFICATIONS
========================= */

$unreadCount = getUnreadNotificationCount(
    $conn,
    (int) $_SESSION['user_id']
);


$message = "";
$messageType = "success";


/* =========================
   DELETE SUBJECT
========================= */

if (isset($_GET['delete'])) {

    $id = intval($_GET['delete']);

    if ($id > 0) {

        $stmt = $conn->prepare(
            "DELETE FROM subjects WHERE id = ?"
        );

        if ($stmt === false) {

            $message = "SQL Error: " . $conn->error;
            $messageType = "error";

        } else {

            $stmt->bind_param("i", $id);

            if ($stmt->execute()) {

                $stmt->close();

                header("Location: subjects.php?msg=deleted");
                exit();

            } else {

                $message = "Unable to delete subject.";
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

            $message = "Subject registered successfully.";
            $messageType = "success";

            break;

        case 'updated':

            $message = "Subject updated successfully.";
            $messageType = "success";

            break;

        case 'deleted':

            $message = "Subject deleted successfully.";
            $messageType = "success";

            break;
    }
}


/* =========================
   ADD / UPDATE SUBJECT
========================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';

    $subjectName = trim(
        $_POST['subject_name'] ?? ''
    );

    $classId = intval(
        $_POST['class_id'] ?? 0
    );


    /* =========================
       VALIDATION
    ========================= */

    if ($subjectName === '') {

        $message = "Subject name is required.";
        $messageType = "error";

    } elseif ($classId <= 0) {

        $message = "Please select a class.";
        $messageType = "error";

    } else {


        /* =========================
           UPDATE SUBJECT
        ========================= */

        if ($action === 'update') {

            $id = intval(
                $_POST['id'] ?? 0
            );

            if ($id <= 0) {

                $message = "Invalid subject ID.";
                $messageType = "error";

            } else {

                $stmt = $conn->prepare("
                    UPDATE subjects
                    SET
                        subject_name = ?,
                        class_id = ?
                    WHERE id = ?
                ");

                if ($stmt === false) {

                    $message =
                        "SQL Prepare Error: " .
                        $conn->error;

                    $messageType = "error";

                } else {

                    $stmt->bind_param(
                        "sii",
                        $subjectName,
                        $classId,
                        $id
                    );

                    if ($stmt->execute()) {

                        $stmt->close();

                        header(
                            "Location: subjects.php?msg=updated"
                        );

                        exit();

                    } else {

                        if ($stmt->errno == 1062) {

                            $message =
                                "This subject already exists.";

                        } else {

                            $message =
                                "Unable to update subject: " .
                                $stmt->error;
                        }

                        $messageType = "error";

                        $stmt->close();
                    }
                }
            }


        /* =========================
           ADD SUBJECT
        ========================= */

        } else {

            $stmt = $conn->prepare("
                INSERT INTO subjects
                (
                    subject_name,
                    class_id
                )
                VALUES (?, ?)
            ");

            if ($stmt === false) {

                $message =
                    "SQL Prepare Error: " .
                    $conn->error;

                $messageType = "error";

            } else {

                $stmt->bind_param(
                    "si",
                    $subjectName,
                    $classId
                );

                if ($stmt->execute()) {

                    /* =========================
                       AUTOMATIC NOTIFICATION
                    ========================= */

                    createNotification(
                        $conn,
                        (int) $_SESSION['user_id'],
                        "New Subject Registered",
                        $subjectName .
                        " has been successfully registered.",
                        "success",
                        "subjects.php"
                    );


                    $stmt->close();

                    header(
                        "Location: subjects.php?msg=added"
                    );

                    exit();

                } else {

                    if ($stmt->errno == 1062) {

                        $message =
                            "This subject already exists.";

                    } else {

                        $message =
                            "Unable to register subject: " .
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
   EDIT SUBJECT
========================= */

$editSubject = null;

if (isset($_GET['edit'])) {

    $editId = intval(
        $_GET['edit']
    );

    if ($editId > 0) {

        $stmt = $conn->prepare("
            SELECT
                id,
                subject_name,
                class_id
            FROM subjects
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

            if (
                $resultEdit->num_rows === 1
            ) {

                $editSubject =
                    $resultEdit->fetch_assoc();
            }

            $stmt->close();
        }
    }
}


/* =========================
   GET CLASSES
========================= */

$classesList = $conn->query("
    SELECT
        id,
        class_name,
        section
    FROM classes
    ORDER BY class_name ASC
");


/* =========================
   SEARCH SUBJECTS
========================= */

$search = trim(
    $_GET['search'] ?? ''
);

if ($search !== '') {

    $searchTerm =
        "%" . $search . "%";

    $stmt = $conn->prepare("
        SELECT
            s.id,
            s.subject_name,
            s.class_id,
            s.created_at,
            c.class_name,
            c.section

        FROM subjects s

        LEFT JOIN classes c
            ON s.class_id = c.id

        WHERE
            s.subject_name LIKE ?
            OR c.class_name LIKE ?
            OR c.section LIKE ?

        ORDER BY s.id DESC
    ");

    $stmt->bind_param(
        "sss",
        $searchTerm,
        $searchTerm,
        $searchTerm
    );

    $stmt->execute();

    $subjects =
        $stmt->get_result();

} else {

    $subjects = $conn->query("
        SELECT
            s.id,
            s.subject_name,
            s.class_id,
            s.created_at,
            c.class_name,
            c.section

        FROM subjects s

        LEFT JOIN classes c
            ON s.class_id = c.id

        ORDER BY s.id DESC
    ");
}


/* =========================
   TOTAL SUBJECTS
========================= */

$totalSubjects = 0;

$totalSubjectsResult =
    $conn->query("
        SELECT COUNT(*) AS total
        FROM subjects
    ");

if ($totalSubjectsResult) {

    $row =
        $totalSubjectsResult->fetch_assoc();

    $totalSubjects =
        $row['total'];
}

?>

<!DOCTYPE html>

<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport"
      content="width=device-width, initial-scale=1.0">

<title>Subjects | School Management</title>


<!-- FONT AWESOME -->

<link rel="stylesheet"
      href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">


<!-- =========================
     PREVENT THEME FLASH
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


/* =========================
   LIGHT THEME VARIABLES
========================= */

:root {

    --primary: #2563eb;
    --primary-dark: #1d4ed8;

    --body-bg: #f8fafc;
    --text: #0f172a;
    --text-secondary: #64748b;

    --card-bg: #ffffff;

    --border: #e2e8f0;

    --input-bg: #ffffff;
    --input-border: #cbd5e1;

    --hover-bg: #f8fafc;

    --table-head: #f8fafc;

    --table-border: #f1f5f9;

    --sidebar-bg: #0f172a;
    --sidebar-hover: #1e293b;
    --sidebar-text: #cbd5e1;

    --success-bg: #dcfce7;
    --success-text: #166534;
    --success-border: #bbf7d0;

    --error-bg: #fee2e2;
    --error-text: #991b1b;
    --error-border: #fecaca;

    --subject-bg: #dbeafe;
    --subject-text: #1d4ed8;

    --class-bg: #f1f5f9;
    --class-text: #475569;

    --secondary-btn: #e2e8f0;
    --secondary-text: #334155;

    --edit-bg: #dbeafe;
    --edit-text: #2563eb;

    --delete-bg: #fee2e2;
    --delete-text: #dc2626;

    --muted: #94a3b8;

    --overlay: rgba(15,23,42,0.5);

    --shadow:
        0 8px 25px rgba(15,23,42,0.05);

    --focus:
        rgba(37,99,235,0.12);

    --theme-btn-bg: #f1f5f9;
    --theme-btn-color: #334155;

    --theme-btn-hover: #e2e8f0;
}


/* =========================
   DARK THEME VARIABLES
========================= */

[data-theme="dark"] {

    --body-bg: #0b1120;

    --text: #f1f5f9;

    --text-secondary: #94a3b8;

    --card-bg: #111827;

    --border: #263244;

    --input-bg: #0f172a;

    --input-border: #334155;

    --hover-bg: #172033;

    --table-head: #0f172a;

    --table-border: #1e293b;

    --sidebar-bg: #020617;

    --sidebar-hover: #1e293b;

    --sidebar-text: #cbd5e1;

    --success-bg: #052e16;

    --success-text: #86efac;

    --success-border: #166534;

    --error-bg: #450a0a;

    --error-text: #fca5a5;

    --error-border: #991b1b;

    --subject-bg: #172554;

    --subject-text: #60a5fa;

    --class-bg: #1e293b;

    --class-text: #cbd5e1;

    --secondary-btn: #1e293b;

    --secondary-text: #cbd5e1;

    --edit-bg: #172554;

    --edit-text: #60a5fa;

    --delete-bg: #450a0a;

    --delete-text: #f87171;

    --muted: #64748b;

    --overlay: rgba(0,0,0,0.7);

    --shadow:
        0 10px 30px rgba(0,0,0,0.25);

    --focus:
        rgba(96,165,250,0.15);

    --theme-btn-bg: #1e293b;

    --theme-btn-color: #f8fafc;

    --theme-btn-hover: #334155;
}


/* =========================
   BODY
========================= */

body {

    background: var(--body-bg);

    color: var(--text);

    transition:
        background-color 0.25s ease,
        color 0.25s ease;
}

a {
    text-decoration: none;
}

button,
input,
select {
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

    background: var(--sidebar-bg);

    color: white;

    z-index: 1000;

    display: flex;
    flex-direction: column;

    overflow-y: auto;
    overflow-x: hidden;

    transition:
        0.3s ease;
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
        1px solid rgba(255,255,255,0.08);
}

.logo-icon {

    width: 45px;
    height: 45px;

    background: var(--primary);

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

    color: var(--sidebar-text);

    border-radius: 9px;

    margin-bottom: 4px;

    font-size: 14px;

    transition: 0.2s;
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


/* =========================
   LOGOUT
========================= */

.logout {

    position: static;

    margin-top: auto;

    padding: 15px 12px;

    border-top:
        1px solid rgba(255,255,255,0.08);
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
}


/* =========================
   TOPBAR
========================= */

.topbar {

    height: 70px;

    background: var(--card-bg);

    border-bottom:
        1px solid var(--border);

    display: flex;

    align-items: center;

    justify-content: flex-end;

    padding: 0 30px;

    gap: 15px;

    position: sticky;

    top: 0;

    z-index: 900;

    transition:
        background-color 0.25s ease,
        border-color 0.25s ease;
}


/* =========================
   MOBILE MENU BUTTON
========================= */

.menu-btn {

    display: none;

    border: none;

    background: var(--primary);

    color: white;

    width: 40px;
    height: 40px;

    border-radius: 8px;

    cursor: pointer;

    font-size: 18px;

    align-items: center;

    justify-content: center;

    transition: .2s;
}

.menu-btn:hover {

    background: var(--primary-dark);

    transform: translateY(-1px);
}


/* =========================
   THEME TOGGLE
========================= */

.theme-toggle {

    width: 40px;
    height: 40px;

    border-radius: 50%;

    border: 1px solid var(--border);

    background: var(--theme-btn-bg);

    color: var(--theme-btn-color);

    display: flex;

    align-items: center;
    justify-content: center;

    cursor: pointer;

    font-size: 16px;

    transition:
        .25s ease;
}

.theme-toggle:hover {

    background: var(--theme-btn-hover);

    transform: translateY(-1px);
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

    color: var(--text-secondary);

    font-size: 19px;

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
}


/* =========================
   USER
========================= */

.user-info {

    display: flex;

    align-items: center;

    gap: 10px;

    margin-left: 3px;
}

.avatar {

    width: 38px;
    height: 38px;

    border-radius: 50%;

    background: var(--primary);

    color: white;

    display: flex;

    align-items: center;
    justify-content: center;

    font-weight: bold;
}

.user-text strong {

    display: block;

    font-size: 13px;

    color: var(--text);
}

.user-text span {

    font-size: 11px;

    color: var(--text-secondary);
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

    color: var(--text);
}

.page-header p {

    color: var(--text-secondary);

    font-size: 14px;
}


/* =========================
   BUTTON
========================= */

.btn {

    border: none;

    background: var(--primary);

    color: white;

    padding: 11px 17px;

    border-radius: 8px;

    cursor: pointer;

    font-size: 13px;

    display: inline-flex;

    align-items: center;

    gap: 8px;

    transition: 0.2s;
}

.btn:hover {

    background: var(--primary-dark);

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

    background: var(--success-bg);

    color: var(--success-text);

    border:
        1px solid var(--success-border);
}

.alert.error {

    background: var(--error-bg);

    color: var(--error-text);

    border:
        1px solid var(--error-border);
}


/* =========================
   FORM CARD
========================= */

.form-card {

    background: var(--card-bg);

    border:
        1px solid var(--border);

    border-radius: 14px;

    padding: 25px;

    margin-bottom: 25px;

    box-shadow: var(--shadow);

    transition:
        background-color 0.25s ease,
        border-color 0.25s ease;
}

.form-title {

    font-size: 18px;

    font-weight: bold;

    margin-bottom: 20px;

    color: var(--text);
}

.form-grid {

    display: grid;

    grid-template-columns:
        repeat(2, 1fr);

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

    color: var(--text);
}

.form-group input,
.form-group select {

    width: 100%;

    padding: 12px;

    border:
        1px solid var(--input-border);

    border-radius: 8px;

    outline: none;

    font-size: 13px;

    background: var(--input-bg);

    color: var(--text);

    transition:
        .2s;
}

.form-group input::placeholder {

    color: var(--text-secondary);
}

.form-group input:focus,
.form-group select:focus {

    border-color: var(--primary);

    box-shadow:
        0 0 0 3px var(--focus);
}

.form-group select option {

    background: var(--input-bg);

    color: var(--text);
}

.form-actions {

    margin-top: 20px;

    display: flex;

    gap: 10px;
}

.btn-secondary {

    background: var(--secondary-btn);

    color: var(--secondary-text);
}

.btn-secondary:hover {

    background:
        var(--input-border);

    color: var(--text);
}


/* =========================
   TABLE CARD
========================= */

.table-card {

    background: var(--card-bg);

    border:
        1px solid var(--border);

    border-radius: 14px;

    overflow: hidden;

    box-shadow: var(--shadow);

    transition:
        background-color 0.25s ease,
        border-color 0.25s ease;
}

.table-top {

    padding: 20px;

    display: flex;

    align-items: center;

    justify-content: space-between;

    gap: 15px;

    border-bottom:
        1px solid var(--border);
}

.table-top h3 {

    font-size: 17px;

    color: var(--text);
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
        1px solid var(--input-border);

    border-radius: 8px;

    outline: none;

    font-size: 13px;

    background: var(--input-bg);

    color: var(--text);

    transition: .2s;
}

.search-box input::placeholder {

    color: var(--text-secondary);
}

.search-box input:focus {

    border-color: var(--primary);

    box-shadow:
        0 0 0 3px var(--focus);
}

.search-btn {

    border: none;

    background: var(--primary);

    color: white;

    padding: 10px 13px;

    border-radius: 8px;

    cursor: pointer;

    transition: .2s;
}

.search-btn:hover {

    background: var(--primary-dark);
}


/* =========================
   TABLE
========================= */

.table-wrapper {

    overflow-x: auto;
}

table {

    width: 100%;

    border-collapse: collapse;

    min-width: 650px;
}

th,
td {

    padding: 14px 18px;

    text-align: left;

    border-bottom:
        1px solid var(--table-border);

    font-size: 13px;
}

th {

    background: var(--table-head);

    color: var(--text-secondary);

    font-size: 12px;

    text-transform: uppercase;
}

td {

    color: var(--text);
}

tr:hover td {

    background: var(--hover-bg);
}


/* =========================
   SUBJECT BADGE
========================= */

.subject-badge {

    display: inline-block;

    background: var(--subject-bg);

    color: var(--subject-text);

    padding: 6px 10px;

    border-radius: 6px;

    font-size: 12px;

    font-weight: 600;
}


/* =========================
   CLASS BADGE
========================= */

.class-badge {

    display: inline-block;

    background: var(--class-bg);

    color: var(--class-text);

    padding: 5px 9px;

    border-radius: 6px;

    font-size: 12px;
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

    background: var(--edit-bg);

    color: var(--edit-text);
}

.delete-btn {

    background: var(--delete-bg);

    color: var(--delete-text);
}

.action-btn:hover {

    transform: translateY(-2px);
}


/* =========================
   EMPTY
========================= */

.empty {

    text-align: center;

    padding: 45px 20px;

    color: var(--text-secondary);
}

.empty i {

    font-size: 35px;

    margin-bottom: 10px;

    color: var(--muted);

    display: block;
}


/* =========================
   OVERLAY
========================= */

.overlay {

    display: none;

    position: fixed;

    inset: 0;

    background:
        var(--overlay);

    z-index: 999;
}


/* =========================
   RESPONSIVE 900px
========================= */

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
    }

    .topbar {

        justify-content:
            space-between;

        padding: 0 20px;
    }

    .overlay.active {

        display: block;
    }

}


/* =========================
   RESPONSIVE 650px
========================= */

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
    }

}


/* =========================
   RESPONSIVE 480px
========================= */

@media (max-width: 480px) {

    .topbar {

        height: 62px;

        padding:
            0 15px;

        gap: 8px;
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

    .form-actions .btn {

        width: 100%;
    }

    .theme-toggle,
    .notification,
    .menu-btn {

        width: 38px;
        height: 38px;
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


        <a href="classes.php">

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


        <!-- ACTIVE SUBJECTS -->

        <a href="subjects.php"
           class="active">

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


    <!-- TOPBAR -->

    <div class="topbar">


        <!-- MOBILE MENU -->

        <button
            class="menu-btn"
            id="menuBtn"
            type="button"
            aria-label="Open Menu"
        >

            <i class="fas fa-bars"></i>

        </button>


        <!-- THEME TOGGLE -->

        <button
            class="theme-toggle"
            id="themeToggle"
            type="button"
            aria-label="Switch to Dark Mode"
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


    <!-- CONTENT -->

    <main class="content">


        <!-- PAGE HEADER -->

        <div class="page-header">


            <div>

                <h1>
                    Subjects
                </h1>

                <p>
                    Manage all subjects assigned to school classes.
                </p>

            </div>


            <button
                class="btn"
                type="button"
                onclick="showForm()"
            >

                <i class="fas fa-plus"></i>

                Add Subject

            </button>


        </div>


        <!-- ALERT -->

        <?php if ($message !== ""): ?>

            <div class="alert
                <?php echo htmlspecialchars($messageType); ?>"
            >

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
            id="subjectForm"
            style="<?php

                echo $editSubject
                    ? ''
                    : 'display:none;';

            ?>"
        >


            <div class="form-title">

                <?php

                echo $editSubject
                    ? 'Edit Subject'
                    : 'Add New Subject';

                ?>

            </div>


            <form
                method="POST"
                action="subjects.php"
            >


                <input
                    type="hidden"
                    name="action"
                    value="<?php

                        echo $editSubject
                            ? 'update'
                            : 'add';

                    ?>"
                >


                <?php if ($editSubject): ?>

                    <input
                        type="hidden"
                        name="id"
                        value="<?php
                            echo $editSubject['id'];
                        ?>"
                    >

                <?php endif; ?>


                <div class="form-grid">


                    <!-- SUBJECT NAME -->

                    <div class="form-group">

                        <label>
                            Subject Name *
                        </label>

                        <input
                            type="text"
                            name="subject_name"
                            placeholder="e.g. Mathematics"
                            required
                            value="<?php

                                echo htmlspecialchars(
                                    $editSubject[
                                        'subject_name'
                                    ] ?? ''
                                );

                            ?>"
                        >

                    </div>


                    <!-- CLASS -->

                    <div class="form-group">

                        <label>
                            Class *
                        </label>

                        <select
                            name="class_id"
                            required
                        >

                            <option value="">
                                -- Select Class --
                            </option>


                            <?php if (
                                $classesList &&
                                $classesList->num_rows > 0
                            ): ?>


                                <?php while (
                                    $class =
                                    $classesList->fetch_assoc()
                                ): ?>

                                    <option
                                        value="<?php
                                            echo $class['id'];
                                        ?>"
                                        <?php

                                        if (
                                            $editSubject &&
                                            $editSubject[
                                                'class_id'
                                            ] ==
                                            $class['id']
                                        ) {

                                            echo 'selected';
                                        }

                                        ?>
                                    >

                                        <?php

                                        echo htmlspecialchars(
                                            $class['class_name']
                                        );

                                        if (
                                            !empty(
                                                $class['section']
                                            )
                                        ) {

                                            echo " - " .
                                                htmlspecialchars(
                                                    $class['section']
                                                );
                                        }

                                        ?>

                                    </option>

                                <?php endwhile; ?>


                            <?php endif; ?>


                        </select>

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

                        echo $editSubject
                            ? 'Update Subject'
                            : 'Save Subject';

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

                    All Subjects
                    (<?php echo $totalSubjects; ?>)

                </h3>


                <!-- SEARCH -->

                <form
                    method="GET"
                    class="search-box"
                >

                    <input
                        type="text"
                        name="search"
                        placeholder="Search subject..."
                        value="<?php

                            echo htmlspecialchars(
                                $search
                            );

                        ?>"
                    >


                    <button
                        type="submit"
                        class="search-btn"
                        aria-label="Search"
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
                                Subject Name
                            </th>

                            <th>
                                Class
                            </th>

                            <th>
                                Section
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
                        $subjects &&
                        $subjects->num_rows > 0
                    ): ?>


                        <?php $number = 1; ?>


                        <?php while (
                            $subject =
                            $subjects->fetch_assoc()
                        ): ?>


                            <tr>


                                <!-- NUMBER -->

                                <td>

                                    <?php

                                    echo $number++;

                                    ?>

                                </td>


                                <!-- SUBJECT -->

                                <td>

                                    <span
                                        class="subject-badge"
                                    >

                                        <?php

                                        echo htmlspecialchars(
                                            $subject[
                                                'subject_name'
                                            ]
                                        );

                                        ?>

                                    </span>

                                </td>


                                <!-- CLASS -->

                                <td>

                                    <?php

                                    if (
                                        !empty(
                                            $subject[
                                                'class_name'
                                            ]
                                        )
                                    ) {

                                        echo htmlspecialchars(
                                            $subject[
                                                'class_name'
                                            ]
                                        );

                                    } else {

                                        echo '<span class="muted-text">—</span>';

                                    }

                                    ?>

                                </td>


                                <!-- SECTION -->

                                <td>

                                    <?php if (
                                        !empty(
                                            $subject['section']
                                        )
                                    ): ?>

                                        <span
                                            class="class-badge"
                                        >

                                            <?php

                                            echo htmlspecialchars(
                                                $subject[
                                                    'section'
                                                ]
                                            );

                                            ?>

                                        </span>

                                    <?php else: ?>

                                        <span
                                            class="muted-text"
                                        >
                                            —
                                        </span>

                                    <?php endif; ?>

                                </td>


                                <!-- CREATED DATE -->

                                <td>

                                    <?php

                                    if (
                                        !empty(
                                            $subject[
                                                'created_at'
                                            ]
                                        )
                                    ) {

                                        echo date(
                                            'd M Y',
                                            strtotime(
                                                $subject[
                                                    'created_at'
                                                ]
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
                                            href="subjects.php?edit=<?php
                                                echo $subject['id'];
                                            ?>"
                                            class="action-btn edit-btn"
                                            title="Edit"
                                            aria-label="Edit Subject"
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
                                            aria-label="Delete Subject"
                                            onclick="deleteSubject(
                                                <?php
                                                echo $subject['id'];
                                                ?>,
                                                <?php

                                                echo htmlspecialchars(
                                                    json_encode(
                                                        $subject[
                                                            'subject_name'
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

                            <td colspan="6">

                                <div class="empty">

                                    <i
                                        class="fas fa-book"
                                    ></i>

                                    <p>
                                        No subjects found.
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

/* =========================
   MOBILE SIDEBAR
========================= */

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


/* =========================
   CLOSE SIDEBAR ON LINK CLICK
========================= */

document.querySelectorAll(".menu a").forEach(
    function (link) {

        link.addEventListener(
            "click",
            function () {

                if (window.innerWidth <= 900) {

                    sidebar.classList.remove("active");

                    overlay.classList.remove("active");

                }

            }
        );

    }
);


/* =========================
   SHOW FORM
========================= */

function showForm() {

    const form =
        document.getElementById("subjectForm");

    form.style.display = "block";

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
        document.getElementById("subjectForm");

    form.style.display = "none";

}


/* =========================
   DELETE SUBJECT
========================= */

function deleteSubject(
    id,
    subjectName
) {

    const confirmDelete = confirm(
        'Are you sure you want to delete "' +
        subjectName +
        '"?'
    );

    if (confirmDelete) {

        window.location.href =
            "subjects.php?delete=" +
            encodeURIComponent(id);

    }

}


/* =========================
   DARK / LIGHT MODE
========================= */

const themeToggle =
    document.getElementById("themeToggle");

const themeIcon =
    document.getElementById("themeIcon");

const root =
    document.documentElement;


/* =========================
   UPDATE THEME UI
========================= */

function updateThemeUI(theme) {

    if (theme === "dark") {

        themeIcon.classList.remove(
            "fa-moon"
        );

        themeIcon.classList.add(
            "fa-sun"
        );

        themeToggle.setAttribute(
            "aria-label",
            "Switch to Light Mode"
        );

        themeToggle.setAttribute(
            "title",
            "Switch to Light Mode"
        );

    } else {

        themeIcon.classList.remove(
            "fa-sun"
        );

        themeIcon.classList.add(
            "fa-moon"
        );

        themeToggle.setAttribute(
            "aria-label",
            "Switch to Dark Mode"
        );

        themeToggle.setAttribute(
            "title",
            "Switch to Dark Mode"
        );

    }

}


/* =========================
   LOAD SAVED THEME
========================= */

const savedTheme =
    localStorage.getItem("schoolTheme") || "light";

root.setAttribute(
    "data-theme",
    savedTheme
);

updateThemeUI(
    savedTheme
);


/* =========================
   TOGGLE THEME
========================= */

themeToggle.addEventListener(
    "click",
    function () {

        const currentTheme =
            root.getAttribute("data-theme") || "light";

        const newTheme =
            currentTheme === "dark"
                ? "light"
                : "dark";


        root.setAttribute(
            "data-theme",
            newTheme
        );


        localStorage.setItem(
            "schoolTheme",
            newTheme
        );


        updateThemeUI(
            newTheme
        );

    }
);


/* =========================
   ESC KEY
========================= */

document.addEventListener(
    "keydown",
    function (event) {

        if (event.key === "Escape") {

            sidebar.classList.remove("active");

            overlay.classList.remove("active");

        }

    }
);

</script>


</body>

</html>


<?php

$conn->close();

?>
```