<?php

session_start();

require_once "config.php";
require_once "notification_helper.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

$user_id = (int) $_SESSION["user_id"];

createNotification(
    $conn,
    $user_id,
    "Automatic Notification",
    "Notification-kan system-ka ayaa si automatic ah u abuuray.",
    "success",
    "notifications.php"
);

header("Location: notifications.php");
exit;