<?php
if (!file_exists('../db_config.php')) {
    die("Error: db_config.php file is missing.");
}
include '../db_config.php';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $ip = $_POST['ip'];
        $username = $_POST['username'];
        $password = $_POST['password']; // Consider encrypting in production
        $database_name = $_POST['database_name'] ?: 'syslog_db';

        $logger_conn = connect_db($logger_db);

        // Prepare and execute the statement
        $stmt = $logger_conn->prepare("INSERT INTO collectors (ip, username, password, database_name) VALUES (?, ?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param("ssss", $ip, $username, $password, $database_name);
            $message = $stmt->execute() ? "Collector added successfully!" : "Failed to add collector: {$stmt->error}";
            $stmt->close();
        } else {
            $message = "Failed to prepare statement: " . $logger_conn->error;
        }

        $logger_conn->close();
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
    }
}
?>