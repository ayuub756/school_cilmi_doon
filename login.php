<?php
session_start();
require_once "config.php";

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $email = trim($_POST["email"]);
    $password = $_POST["password"];

    $stmt = $conn->prepare("SELECT id, name, email, password, role FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();

    $result = $stmt->get_result();

    if ($result->num_rows === 1) {

        $user = $result->fetch_assoc();

        // Password-ka SQL file-ka lagu geliyay SHA2
        $hashedPassword = hash("sha256", $password);

        if (hash_equals($user["password"], $hashedPassword)) {

            $_SESSION["user_id"] = $user["id"];
            $_SESSION["user_name"] = $user["name"];
            $_SESSION["user_email"] = $user["email"];
            $_SESSION["user_role"] = $user["role"];

            header("Location: dashboard.php");
            exit;

        } else {
            $error = "Email ama password ayaa khaldan.";
        }

    } else {
        $error = "Email ama password ayaa khaldan.";
    }

    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Student Management</title>

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: Arial, sans-serif;
        }

        body {
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            background: #f4f7fb;
        }

        .login-box {
            width: 400px;
            max-width: 92%;
            background: white;
            padding: 35px;
            border-radius: 15px;
            box-shadow: 0 10px 35px rgba(0,0,0,.1);
        }

        .login-box h1 {
            text-align: center;
            color: #020b26;
            margin-bottom: 8px;
        }

        .login-box p {
            text-align: center;
            color: #777;
            margin-bottom: 25px;
        }

        .input-group {
            margin-bottom: 18px;
        }

        .input-group label {
            display: block;
            margin-bottom: 7px;
            font-weight: bold;
        }

        .input-group input {
            width: 100%;
            padding: 13px;
            border: 1px solid #ddd;
            border-radius: 8px;
            outline: none;
        }

        .input-group input:focus {
            border-color: #3ad7e6;
        }

        button {
            width: 100%;
            padding: 13px;
            border: none;
            border-radius: 8px;
            background: #2563eb;
            color: white;
            font-size: 16px;
            cursor: pointer;
        }

        button:hover {
            background: #3ad7e6;
            color: #020b26;
        }

        .error {
            background: #ffe5e5;
            color: #c00;
            padding: 10px;
            border-radius: 7px;
            margin-bottom: 15px;
            text-align: center;
        }

        .demo {
            margin-top: 20px;
            padding: 12px;
            background: #f5f5f5;
            border-radius: 8px;
            font-size: 14px;
        }
    </style>
</head>

<body>

<div class="login-box">

    <h1>Admin Login</h1>
    <p>Student Management System</p>

    <?php if ($error): ?>
        <div class="error">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <form method="POST">

        <div class="input-group">
            <label>Email</label>
            <input
                type="email"
                name="email"
                placeholder="Enter email"
                required
            >
        </div>

        <div class="input-group">
            <label>Password</label>
            <input
                type="password"
                name="password"
                placeholder="Enter password"
                required
            >
        </div>

        <button type="submit">
            Login
        </button>

    </form>

    <div class="demo">
        <strong>Default Admin:</strong><br>
        Email: admin@example.com<br>
        Password: admin123
    </div>

</div>

</body>
</html>