```php
<?php

/*
=========================================================
ROLE PERMISSIONS SYSTEM
School Management System
=========================================================
*/

/*
|--------------------------------------------------------------------------
| DEFAULT ROLE
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION['user_role'])) {
    $_SESSION['user_role'] = 'Admin';
}


/*
|--------------------------------------------------------------------------
| CURRENT ROLE
|--------------------------------------------------------------------------
*/

$currentRole = strtolower(
    trim($_SESSION['user_role'])
);


/*
|--------------------------------------------------------------------------
| ROLE PERMISSIONS
|--------------------------------------------------------------------------
|
| true  = allowed
| false = not allowed
|
*/

$permissions = [

    'admin' => [

        'dashboard'      => true,

        'students'       => true,
        'teachers'       => true,
        'parents'        => true,

        'classes'        => true,
        'subjects'       => true,

        'attendance'     => true,

        'exams'          => true,
        'results'        => true,

        'fees'           => true,
        'payments'       => true,
        'salary'         => true,

        'reports'        => true,

        'notifications'  => true,

        'users'          => true,

        'settings'       => true,

        'backup'         => true
    ],


    'teacher' => [

        'dashboard'      => true,

        'students'       => true,
        'teachers'       => false,
        'parents'        => true,

        'classes'        => true,
        'subjects'       => true,

        'attendance'     => true,

        'exams'          => true,
        'results'        => true,

        'fees'           => false,
        'payments'       => false,
        'salary'         => false,

        'reports'        => true,

        'notifications'  => true,

        'users'          => false,

        'settings'       => true,

        'backup'         => false
    ],


    'accountant' => [

        'dashboard'      => true,

        'students'       => true,
        'teachers'       => false,
        'parents'        => true,

        'classes'        => true,
        'subjects'       => false,

        'attendance'     => false,

        'exams'          => false,
        'results'        => false,

        'fees'           => true,
        'payments'       => true,
        'salary'         => true,

        'reports'        => true,

        'notifications'  => true,

        'users'          => false,

        'settings'       => true,

        'backup'         => false
    ],


    'parent' => [

        'dashboard'      => true,

        'students'       => true,
        'teachers'       => false,
        'parents'        => false,

        'classes'        => true,
        'subjects'       => true,

        'attendance'     => true,

        'exams'          => true,
        'results'        => true,

        'fees'           => true,
        'payments'       => true,
        'salary'         => false,

        'reports'        => false,

        'notifications'  => true,

        'users'          => false,

        'settings'       => true,

        'backup'         => false
    ]

];


/*
|--------------------------------------------------------------------------
| CHECK PERMISSION
|--------------------------------------------------------------------------
*/

function hasPermission($permission)
{
    global $permissions, $currentRole;

    if (!isset($permissions[$currentRole])) {
        return false;
    }

    return !empty(
        $permissions[$currentRole][$permission]
    );
}


/*
|--------------------------------------------------------------------------
| REQUIRE PERMISSION
|--------------------------------------------------------------------------
|
| Use this at the top of every protected page.
|
*/

function requirePermission($permission)
{
    if (!hasPermission($permission)) {

        http_response_code(403);

        ?>
        <!DOCTYPE html>
        <html lang="en">

        <head>

            <meta charset="UTF-8">

            <meta
                name="viewport"
                content="width=device-width, initial-scale=1.0"
            >

            <title>Access Denied</title>

            <style>

                * {
                    margin: 0;
                    padding: 0;
                    box-sizing: border-box;
                }

                body {

                    min-height: 100vh;

                    display: flex;

                    align-items: center;

                    justify-content: center;

                    background: #f8fafc;

                    font-family:
                        Arial,
                        Helvetica,
                        sans-serif;

                    color: #0f172a;

                    padding: 20px;
                }

                .access-box {

                    width: 100%;

                    max-width: 450px;

                    background: white;

                    border: 1px solid #e2e8f0;

                    border-radius: 16px;

                    padding: 40px 30px;

                    text-align: center;

                    box-shadow:
                        0 15px 40px
                        rgba(15, 23, 42, .10);
                }

                .icon {

                    width: 70px;
                    height: 70px;

                    margin: 0 auto 20px;

                    border-radius: 50%;

                    background: #fee2e2;

                    color: #dc2626;

                    display: flex;

                    align-items: center;

                    justify-content: center;

                    font-size: 30px;
                }

                h1 {

                    font-size: 24px;

                    margin-bottom: 10px;
                }

                p {

                    color: #64748b;

                    line-height: 1.6;

                    margin-bottom: 25px;
                }

                a {

                    display: inline-block;

                    background: #2563eb;

                    color: white;

                    text-decoration: none;

                    padding: 12px 20px;

                    border-radius: 8px;

                    font-weight: bold;
                }

                a:hover {

                    background: #1d4ed8;
                }

            </style>

        </head>

        <body>

            <div class="access-box">

                <div class="icon">
                    🔒
                </div>

                <h1>
                    Access Denied
                </h1>

                <p>
                    You do not have permission to access this page.
                    Please contact your administrator.
                </p>

                <a href="dashboard.php">
                    Back to Dashboard
                </a>

            </div>

        </body>

        </html>

        <?php

        exit();
    }
}


/*
|--------------------------------------------------------------------------
| GET CURRENT ROLE
|--------------------------------------------------------------------------
*/

function getCurrentRole()
{
    return ucfirst(
        strtolower(
            trim(
                $_SESSION['user_role'] ?? 'Admin'
            )
        )
    );
}
?>
```

### 2. `backup.php`

File-kan Admin-ka ayaa kaliya geli kara. Wuxuu sameynayaa **SQL backup dhammaan database-ka `student_management`**, kadib browser-ka ayuu `.sql` file ahaan u download-gareynayaa.

:::writing{variant="document" id="83016" title="2. backup.php"}
```php
<?php

session_start();

/*
=========================================================
DATABASE BACKUP
School Management System
=========================================================
*/


/*
|--------------------------------------------------------------------------
| LOGIN CHECK
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION['user_id'])) {

    header("Location: login.php");

    exit();
}


/*
|--------------------------------------------------------------------------
| ADMIN CHECK
|--------------------------------------------------------------------------
*/

$userRole = strtolower(
    trim(
        $_SESSION['user_role'] ?? ''
    )
);

if ($userRole !== 'admin') {

    http_response_code(403);

    die("
        <h2 style='font-family:Arial;'>
            Access Denied
        </h2>

        <p style='font-family:Arial;'>
            Only administrators can create database backups.
        </p>

        <a href='dashboard.php'>
            Back to Dashboard
        </a>
    ");
}


/*
|--------------------------------------------------------------------------
| DATABASE
|--------------------------------------------------------------------------
*/

$host = "localhost";
$user = "root";
$password = "";
$database = "student_management";


$conn = new mysqli(
    $host,
    $user,
    $password,
    $database
);


if ($conn->connect_error) {

    die(
        "Database connection failed: "
        . $conn->connect_error
    );
}


$conn->set_charset("utf8mb4");


/*
|--------------------------------------------------------------------------
| START BACKUP
|--------------------------------------------------------------------------
*/

$backup = "";


/*
|--------------------------------------------------------------------------
| HEADER
|--------------------------------------------------------------------------
*/

$backup .= "-- ==============================================\n";

$backup .= "-- SCHOOL MANAGEMENT SYSTEM DATABASE BACKUP\n";

$backup .= "-- Database: {$database}\n";

$backup .= "-- Generated: "
    . date("Y-m-d H:i:s")
    . "\n";

$backup .= "-- ==============================================\n\n";


$backup .= "SET FOREIGN_KEY_CHECKS=0;\n\n";


/*
|--------------------------------------------------------------------------
| GET TABLES
|--------------------------------------------------------------------------
*/

$tablesResult = $conn->query("SHOW TABLES");


if (!$tablesResult) {

    die(
        "Unable to read database tables: "
        . $conn->error
    );
}


while (
    $tableRow =
    $tablesResult->fetch_row()
) {

    $table = $tableRow[0];


    /*
    |--------------------------------------------------------------------------
    | TABLE STRUCTURE
    |--------------------------------------------------------------------------
    */

    $backup .= "-- ----------------------------------------------\n";

    $backup .= "-- TABLE: {$table}\n";

    $backup .= "-- ----------------------------------------------\n\n";


    $createResult = $conn->query(
        "SHOW CREATE TABLE `{$table}`"
    );


    if ($createResult) {

        $createRow =
            $createResult->fetch_assoc();


        $createTable =
            $createRow['Create Table']
            ?? "";


        $backup .=
            "DROP TABLE IF EXISTS `{$table}`;\n\n";


        $backup .=
            $createTable . ";\n\n";

    }


    /*
    |--------------------------------------------------------------------------
    | TABLE DATA
    |--------------------------------------------------------------------------
    */

    $dataResult = $conn->query(
        "SELECT * FROM `{$table}`"
    );


    if (
        $dataResult &&
        $dataResult->num_rows > 0
    ) {

        $fields = [];


        $fieldInfo =
            $conn->query(
                "SHOW COLUMNS FROM `{$table}`"
            );


        while (
            $field =
            $fieldInfo->fetch_assoc()
        ) {

            $fields[] =
                "`" . $field['Field'] . "`";

        }


        $fieldList =
            implode(
                ", ",
                $fields
            );


        while (
            $row =
            $dataResult->fetch_assoc()
        ) {

            $values = [];


            foreach (
                $row as $value
            ) {

                if ($value === null) {

                    $values[] = "NULL";

                } else {

                    $values[] =
                        "'" .
                        $conn->real_escape_string(
                            $value
                        ) .
                        "'";

                }

            }


            $backup .=
                "INSERT INTO `{$table}` "
                . "({$fieldList}) VALUES "
                . "("
                . implode(
                    ", ",
                    $values
                )
                . ");\n";

        }


        $backup .= "\n";

    }

}


/*
|--------------------------------------------------------------------------
| FOREIGN KEYS ON
|--------------------------------------------------------------------------
*/

$backup .=
    "SET FOREIGN_KEY_CHECKS=1;\n";


/*
|--------------------------------------------------------------------------
| CLOSE DATABASE
|--------------------------------------------------------------------------
*/

$conn->close();


/*
|--------------------------------------------------------------------------
| DOWNLOAD FILE
|--------------------------------------------------------------------------
*/

$fileName =
    "school_management_backup_"
    . date("Y-m-d_H-i-s")
    . ".sql";


header(
    "Content-Type: application/sql"
);

header(
    "Content-Disposition: attachment; filename=\"{$fileName}\""
);

header(
    "Content-Length: " . strlen($backup)
);

header(
    "Cache-Control: no-cache, no-store, must-revalidate"
);

header(
    "Pragma: no-cache"
);

header(
    "Expires: 0"
);


echo $backup;

exit();

?>
```

## 3. `users.php`

Kan wuxuu Admin-ka u oggolaanayaa inuu user-ka role-kiisa beddelo.

Tusaale:

```text
Ahmed → Teacher
Ali → Accountant
Hassan → Parent
Admin → Admin
```

:::writing{variant="document" id="27594" title="3. users.php"}
```php
<?php

session_start();


/*
=========================================================
USER MANAGEMENT
School Management System
=========================================================
*/


/*
|--------------------------------------------------------------------------
| LOGIN
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION['user_id'])) {

    header("Location: login.php");

    exit();
}


/*
|--------------------------------------------------------------------------
| ADMIN ONLY
|--------------------------------------------------------------------------
*/

$userRole = strtolower(
    trim(
        $_SESSION['user_role'] ?? ''
    )
);

if ($userRole !== 'admin') {

    http_response_code(403);

    die("
        <h2 style='font-family:Arial;'>
            Access Denied
        </h2>

        <p style='font-family:Arial;'>
            Only administrators can manage users.
        </p>

        <a href='dashboard.php'>
            Back to Dashboard
        </a>
    ");
}


/*
|--------------------------------------------------------------------------
| DATABASE
|--------------------------------------------------------------------------
*/

$conn = new mysqli(
    "localhost",
    "root",
    "",
    "student_management"
);


if ($conn->connect_error) {

    die(
        "Database connection failed: "
        . $conn->connect_error
    );
}


$conn->set_charset("utf8mb4");


/*
|--------------------------------------------------------------------------
| MESSAGE
|--------------------------------------------------------------------------
*/

$message = "";
$messageType = "";


/*
|--------------------------------------------------------------------------
| UPDATE ROLE
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['update_role'])
) {

    $userId =
        intval(
            $_POST['user_id'] ?? 0
        );


    $role =
        strtolower(
            trim(
                $_POST['role'] ?? ''
            )
        );


    $allowedRoles = [

        'admin',
        'teacher',
        'accountant',
        'parent'

    ];


    if ($userId <= 0) {

        $message =
            "Invalid user.";

        $messageType =
            "error";

    } elseif (
        !in_array(
            $role,
            $allowedRoles,
            true
        )
    ) {

        $message =
            "Invalid role selected.";

        $messageType =
            "error";

    } else {

        /*
        |--------------------------------------------------------------------------
        | PREVENT ADMIN FROM REMOVING OWN ADMIN ROLE
        |--------------------------------------------------------------------------
        */

        if (
            $userId ==
            intval($_SESSION['user_id'])
            &&
            $role !== 'admin'
        ) {

            $message =
                "You cannot remove your own Admin role.";

            $messageType =
                "error";

        } else {

            $stmt =
                $conn->prepare("
                    UPDATE users
                    SET role = ?
                    WHERE id = ?
                ");


            $stmt->bind_param(
                "si",
                $role,
                $userId
            );


            if ($stmt->execute()) {

                $message =
                    "User role updated successfully.";

                $messageType =
                    "success";

            } else {

                $message =
                    "Failed to update user role.";

                $messageType =
                    "error";
            }


            $stmt->close();
        }
    }
}


/*
|--------------------------------------------------------------------------
| GET USERS
|--------------------------------------------------------------------------
*/

$result =
    $conn->query("
        SELECT
            id,
            name,
            email,
            role
        FROM users
        ORDER BY id DESC
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
    Users | School Management System
</title>

<style>

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {

    font-family:
        Arial,
        Helvetica,
        sans-serif;

    background: #f8fafc;

    color: #0f172a;

    padding: 30px;
}

.container {

    max-width: 1100px;

    margin: auto;
}

.header {

    display: flex;

    justify-content: space-between;

    align-items: center;

    margin-bottom: 25px;

    gap: 15px;
}

.header h1 {

    font-size: 28px;
}

.header p {

    color: #64748b;

    margin-top: 5px;
}

.back {

    text-decoration: none;

    background: #2563eb;

    color: white;

    padding: 11px 16px;

    border-radius: 8px;

    font-weight: bold;
}

.alert {

    padding: 13px 16px;

    border-radius: 8px;

    margin-bottom: 20px;
}

.alert.success {

    background: #dcfce7;

    color: #166534;
}

.alert.error {

    background: #fee2e2;

    color: #991b1b;
}

.card {

    background: white;

    border: 1px solid #e2e8f0;

    border-radius: 14px;

    overflow-x: auto;

    box-shadow:
        0 5px 20px
        rgba(15,23,42,.06);
}

table {

    width: 100%;

    border-collapse: collapse;

    min-width: 700px;
}

th {

    background: #f8fafc;

    color: #475569;

    font-size: 13px;

    text-align: left;

    padding: 15px;
}

td {

    padding: 15px;

    border-top:
        1px solid #e2e8f0;

    font-size: 14px;
}

.role-form {

    display: flex;

    align-items: center;

    gap: 8px;
}

select {

    height: 38px;

    border:
        1px solid #cbd5e1;

    border-radius: 7px;

    padding: 0 10px;

    background: white;

    color: #0f172a;

}

button {

    border: none;

    background: #2563eb;

    color: white;

    padding: 9px 13px;

    border-radius: 7px;

    cursor: pointer;

    font-weight: bold;
}

button:hover {

    background: #1d4ed8;
}

.role-badge {

    display: inline-block;

    padding: 5px 10px;

    border-radius: 20px;

    background: #dbeafe;

    color: #1d4ed8;

    font-size: 12px;

    font-weight: bold;

}

@media (max-width: 600px) {

    body {
        padding: 15px;
    }

    .header {

        align-items: flex-start;

        flex-direction: column;
    }

    .header h1 {
        font-size: 23px;
    }

}

</style>

</head>

<body>

<div class="container">


    <div class="header">

        <div>

            <h1>
                User Management
            </h1>

            <p>
                Manage system users and their roles.
            </p>

        </div>


        <a
            href="dashboard.php"
            class="back"
        >
            Dashboard
        </a>

    </div>


    <?php if ($message !== ''): ?>

        <div
            class="alert <?php echo htmlspecialchars($messageType); ?>"
        >

            <?php
            echo htmlspecialchars($message);
            ?>

        </div>

    <?php endif; ?>


    <div class="card">

        <table>

            <thead>

                <tr>

                    <th>
                        #
                    </th>

                    <th>
                        Name
                    </th>

                    <th>
                        Email
                    </th>

                    <th>
                        Current Role
                    </th>

                    <th>
                        Change Role
                    </th>

                </tr>

            </thead>


            <tbody>

                <?php

                if (
                    $result &&
                    $result->num_rows > 0
                ):

                    $number = 1;

                    while (
                        $user =
                        $result->fetch_assoc()
                    ):

                ?>

                    <tr>

                        <td>
                            <?php echo $number++; ?>
                        </td>


                        <td>

                            <?php

                            echo htmlspecialchars(
                                $user['name']
                            );

                            ?>

                        </td>


                        <td>

                            <?php

                            echo htmlspecialchars(
                                $user['email']
                            );

                            ?>

                        </td>


                        <td>

                            <span class="role-badge">

                                <?php

                                echo htmlspecialchars(
                                    ucfirst(
                                        $user['role']
                                    )
                                );

                                ?>

                            </span>

                        </td>


                        <td>

                            <form
                                method="POST"
                                class="role-form"
                            >

                                <input
                                    type="hidden"
                                    name="user_id"
                                    value="<?php echo intval($user['id']); ?>"
                                >


                                <select
                                    name="role"
                                >

                                    <option
                                        value="admin"
                                        <?php
                                        echo strtolower($user['role']) === 'admin'
                                            ? 'selected'
                                            : '';
                                        ?>
                                    >
                                        Admin
                                    </option>


                                    <option
                                        value="teacher"
                                        <?php
                                        echo strtolower($user['role']) === 'teacher'
                                            ? 'selected'
                                            : '';
                                        ?>
                                    >
                                        Teacher
                                    </option>


                                    <option
                                        value="accountant"
                                        <?php
                                        echo strtolower($user['role']) === 'accountant'
                                            ? 'selected'
                                            : '';
                                        ?>
                                    >
                                        Accountant
                                    </option>


                                    <option
                                        value="parent"
                                        <?php
                                        echo strtolower($user['role']) === 'parent'
                                            ? 'selected'
                                            : '';
                                        ?>
                                    >
                                        Parent
                                    </option>

                                </select>


                                <button
                                    type="submit"
                                    name="update_role"
                                >
                                    Save
                                </button>

                            </form>

                        </td>

                    </tr>

                <?php

                    endwhile;

                else:

                ?>

                    <tr>

                        <td
                            colspan="5"
                            style="text-align:center;"
                        >
                            No users found.
                        </td>

                    </tr>

                <?php endif; ?>

            </tbody>

        </table>

    </div>

</div>

</body>

</html>

<?php

$conn->close();

?>
```

## 4. Sida `permissions.php` loogu isticmaalo pages-ka

Tani waa qaybta **ugu muhiimsan**.

Tusaale `attendance.php`, PHP-ga ugu sarreeya ku dar:

```php
<?php

session_start();

if (!isset($_SESSION['user_id'])) {

    header("Location: login.php");

    exit();
}

require_once "permissions.php";

requirePermission("attendance");

?>
```

Markaas Teacher:

```text
Teacher → Attendance = ALLOWED
```

laakiin Accountant:

```text
Accountant → Attendance = DENIED
```

---

### `fees.php`

```php
<?php

session_start();

if (!isset($_SESSION['user_id'])) {

    header("Location: login.php");

    exit();
}

require_once "permissions.php";

requirePermission("fees");

?>
```

### `payments.php`

```php
<?php

session_start();

if (!isset($_SESSION['user_id'])) {

    header("Location: login.php");

    exit();
}

require_once "permissions.php";

requirePermission("payments");

?>
```

### `teachers.php`

```php
<?php

session_start();

if (!isset($_SESSION['user_id'])) {

    header("Location: login.php");

    exit();
}

require_once "permissions.php";

requirePermission("teachers");

?>
```

### `backup.php`

Backup-ka hore ayuu u leeyahay Admin protection, sidaas darteed **Admin kaliya** ayaa isticmaali kara.

---

# 5. Sidebar-ka sidoo kale qari pages-ka aan role-ka loo oggolayn

Tani waxay ka dhigaysaa system-ka professional.

Tusaale sidebar-kaaga:

```php
<?php if (hasPermission("students")): ?>

<li>
    <a href="students.php">
        <i class="fas fa-user-graduate"></i>
        <span>Students</span>
    </a>
</li>

<?php endif; ?>
```

Teachers:

```php
<?php if (hasPermission("teachers")): ?>

<li>
    <a href="teachers.php">
        <i class="fas fa-chalkboard-teacher"></i>
        <span>Teachers</span>
    </a>
</li>

<?php endif; ?>
```

Attendance:

```php
<?php if (hasPermission("attendance")): ?>

<li>
    <a href="attendance.php">
        <i class="fas fa-calendar-check"></i>
        <span>Attendance</span>
    </a>
</li>

<?php endif; ?>
```

Fees:

```php
<?php if (hasPermission("fees")): ?>

<li>
    <a href="fees.php">
        <i class="fas fa-money-bill-wave"></i>
        <span>Fees</span>
    </a>
</li>

<?php endif; ?>
```

Users:

```php
<?php if (hasPermission("users")): ?>

<li>
    <a href="users.php">
        <i class="fas fa-users-cog"></i>
        <span>Users</span>
    </a>
</li>

<?php endif; ?>
```

Backup:

```php
<?php if (hasPermission("backup")): ?>

<li>
    <a href="backup.php">
        <i class="fas fa-database"></i>
        <span>Database Backup</span>
    </a>
</li>

<?php endif; ?>
```

**Muhiim:** Sidebar-ka qarin keliya security ma aha. Sida aan kor kuugu sameeyay, `requirePermission()` waa inuu sidoo kale ku jiraa **bilowga page-ka**, sababtoo ah qofku wuxuu si toos ah u qori karaa:

```text
backup.php
```

ama

```text
users.php
```

browser-ka. `requirePermission()` ayaa markaas ka hor istaagaya.

### Hal shay oo aad hubiso

`users` table-kaaga waa inuu leeyahay:

```text
id
name
email
password
role
```

`role`-kana ha isticmaalo values-kan:

```text
admin
teacher
accountant
parent
```

Haddii database-kaaga hadda uu isticmaalo `Admin`, `Teacher`, `Accountant` iwm oo **capital letters** leh, code-kan weli wuu shaqaynayaa sababtoo ah waxaan isticmaalay `strtolower()`.

**Tallaabada xigta:** ku dar `requirePermission()` dhammaan pages-kaaga, kadib sidebar-ka ku samee `hasPermission()`. Markaas labada feature waxay noqonayaan kuwo dhab ah: **Backup wuxuu ilaalinayaa database-ka, Role Permissions-na wuxuu ilaalinayaa access-ka system-ka.**