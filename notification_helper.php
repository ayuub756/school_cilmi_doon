<?php

function createNotification(
    $conn,
    $user_id,
    $title,
    $message,
    $type = "info",
    $link = null
) {

    $stmt = $conn->prepare("
        INSERT INTO notifications
        (user_id, title, message, type, link, is_read)
        VALUES (?, ?, ?, ?, ?, 0)
    ");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param(
        "issss",
        $user_id,
        $title,
        $message,
        $type,
        $link
    );

    $success = $stmt->execute();

    $stmt->close();

    return $success;
}

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

    return (int) $row["total"];
}