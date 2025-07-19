<?php
require '/var/www/html/syslog/db_config.php';
require 'rules.php';

try {
    // Step 1: Connect to logger DB and prepare log file
    if (!file_exists('logs/')) {
        mkdir('logs/', 0777, true);
    }
    file_put_contents('/var/www/html/syslog/server-side/logs/syslog_sync.log', "Starting sync at " . date('Y-m-d H:i:s') . "\n", FILE_APPEND);

    $logger_conn = connect_db($logger_db);
    file_put_contents('/var/www/html/syslog/server-side/logs/syslog_sync.log', "Connected to logger DB\n", FILE_APPEND);

    // Fetch all active collectors
    $collectors = [];
    $result = $logger_conn->query("SELECT id, ip, username, password, database_name FROM collectors WHERE status = 'active'");
    while ($row = $result->fetch_assoc()) {
        $collectors[$row['id']] = [
            'host' => $row['ip'],
            'user' => $row['username'],
            'password' => $row['password'],
            'database' => $row['database_name']
        ];
    }

    if (empty($collectors)) {
        file_put_contents('/var/www/html/syslog/server-side/logs/syslog_sync.log', "No active collectors found.\n", FILE_APPEND);
        echo "No active collectors found.";
        exit;
    }

    // Step 2: Sync logs from each collector
    $total_rows_inserted = 0;
    foreach ($collectors as $collector_id => $collector) {
        // Get the last synced ID for this specific collector
        $last_id = 0;
        $stmt = $logger_conn->prepare("SELECT MAX(id) AS last_id FROM log_mirror WHERE collector_id = ?");
        $stmt->bind_param("i", $collector_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $last_id = $row['last_id'] !== null ? (int)$row['last_id'] : 0;
        file_put_contents('/var/www/html/syslog/server-side/logs/syslog_sync.log', "Last ID for collector $collector_id: $last_id\n", FILE_APPEND);

        // Connect to the collector
        file_put_contents('/var/www/html/syslog/server-side/logs/syslog_sync.log', "Connecting to collector $collector_id at {$collector['host']}\n", FILE_APPEND);
        $collector_conn = connect_db($collector);
        file_put_contents('/var/www/html/syslog/server-side/logs/syslog_sync.log', "Connected to collector $collector_id\n", FILE_APPEND);

        // Fetch new logs from this collector
        $query = "SELECT id, received_at, hostname, facility, message 
                  FROM remote_logs 
                  WHERE id > ? 
                  ORDER BY id ASC LIMIT 1000";
        $stmt = $collector_conn->prepare($query);
        $stmt->bind_param("i", $last_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if (!$result || $result->num_rows === 0) {
            file_put_contents('/var/www/html/syslog/server-side/logs/syslog_sync.log', "No new logs from collector $collector_id.\n", FILE_APPEND);
            $collector_conn->close();
            continue;
        }

        file_put_contents('/var/www/html/syslog/server-side/logs/syslog_sync.log', "Found " . $result->num_rows . " new logs from collector $collector_id\n", FILE_APPEND);

        // Prepare insert statement for log_mirror
        $insert_query = "INSERT IGNORE INTO log_mirror 
                         (id, collector_id, received_at, hostname, facility, synced_at, event, path, file_folder, size, user, ip, message, category) 
                         VALUES 
                         (?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt_insert = $logger_conn->prepare($insert_query);
        if (!$stmt_insert) {
            throw new Exception("Prepare failed for collector $collector_id: {$logger_conn->error}");
        }

        // Prepare statements for devices table
        $device_select = $logger_conn->prepare("SELECT id FROM devices WHERE host_name = ?");
        $device_insert = $logger_conn->prepare("INSERT INTO devices (host_name, status, created_at, updated_at) VALUES (?, 'active', NOW(), NOW())");

        // Prepare statements for users table
        $user_select = $logger_conn->prepare("SELECT id FROM users WHERE name = ?");
        $user_insert = $logger_conn->prepare("INSERT INTO users (name, ip, created_at, updated_at) VALUES (?, ?, NOW(), NOW())");

        $rows_inserted = 0;
        $logger_conn->begin_transaction();

        while ($row = $result->fetch_assoc()) {
            $id = $row['id'];
            $received_at = $row['received_at'];
            $hostname = $row['hostname'];
            $facility = $row['facility'];
            $message = $row['message'];

            $fields = categorize_message($message);
            $event = $fields['event'];
            $path = $fields['path'];
            $file_folder = $fields['file_folder'];
            $size = $fields['size'];
            $user = $fields['user'];
            $ip = $fields['ip'];
            $category = $fields['category'];

            // Step 1: Handle device (hostname)
            $device_id = null;
            if (!empty($hostname)) {
                $device_select->bind_param("s", $hostname);
                $device_select->execute();
                $device_result = $device_select->get_result();
                if ($device_result->num_rows > 0) {
                    $device_row = $device_result->fetch_assoc();
                    $device_id = $device_row['id'];
                    file_put_contents('/var/www/html/syslog/server-side/logs/syslog_sync.log', "Found device ID=$device_id for hostname=$hostname\n", FILE_APPEND);
                } else {
                    $device_insert->bind_param("s", $hostname);
                    if ($device_insert->execute()) {
                        $device_id = $logger_conn->insert_id;
                        file_put_contents('/var/www/html/syslog/server-side/logs/syslog_sync.log', "Inserted new device ID=$device_id for hostname=$hostname\n", FILE_APPEND);
                    } else {
                        file_put_contents('/var/www/html/syslog/server-side/logs/syslog_sync.log', "Failed to insert device for hostname=$hostname: {$device_insert->error}\n", FILE_APPEND);
                    }
                }
            }

            // Step 2: Handle user (name and ip)
            $user_id = null;
            if (!empty($user) && !empty($ip)) {
                $user_select->bind_param("s", $user);
                $user_select->execute();
                $user_result = $user_select->get_result();
                if ($user_result->num_rows > 0) {
                    $user_row = $user_result->fetch_assoc();
                    $user_id = $user_row['id'];
                    file_put_contents('/var/www/html/syslog/server-side/logs/syslog_sync.log', "Found user ID=$user_id for name=$user\n", FILE_APPEND);
                } else {
                    $user_insert->bind_param("s", $user);
                    if ($user_insert->execute()) {
                        $user_id = $logger_conn->insert_id;
                        file_put_contents('/var/www/html/syslog/server-side/logs/syslog_sync.log', "Inserted new user ID=$user_id for name=$user, ip=$ip\n", FILE_APPEND);
                    } else {
                        file_put_contents('/var/www/html/syslog/server-side/logs/syslog_sync.log', "Failed to insert user for name=$user, ip=$ip: {$user_insert->error}\n", FILE_APPEND);
                    }
                }
            }

            // Step 3: Insert into log_mirror
            if ($device_id !== null && $user_id !== null) {
                file_put_contents('/var/www/html/syslog/server-side/logs/syslog_sync.log', "Inserting ID=$id from collector $collector_id: DEVICE_ID=$device_id, EVENT=$event, PATH=$path, FILE_FOLDER=$file_folder, SIZE=$size, USER_ID=$user_id, IP=$ip, CATEGORY=$category\n", FILE_APPEND);

                $stmt_insert->bind_param("iisssssssssss", $id, $collector_id, $received_at, $device_id, $facility, $event, $path, $file_folder, $size, $user_id, $ip, $message, $category);
                if ($stmt_insert->execute()) {
                    $rows_inserted++;
                } else {
                    file_put_contents('/var/www/html/syslog/server-side/logs/syslog_sync.log', "Skipped ID=$id due to duplicate or error: {$stmt_insert->error}\n", FILE_APPEND);
                }
            } else {
                file_put_contents('/var/www/html/syslog/server-side/logs/syslog_sync.log', "Skipped ID=$id due to missing device_id or user_id\n", FILE_APPEND);
            }
        }

        if ($rows_inserted > 0) {
            $logger_conn->commit();
            $total_rows_inserted += $rows_inserted;
            file_put_contents('/var/www/html/syslog/server-side/logs/syslog_sync.log', "Committed $rows_inserted rows for collector $collector_id\n", FILE_APPEND);
        } else {
            $logger_conn->rollback();
            file_put_contents('/var/www/html/syslog/server-side/logs/syslog_sync.log', "No rows inserted for collector $collector_id, rolled back\n", FILE_APPEND);
        }

        // Close prepared statements
        $device_select->close();
        $device_insert->close();
        $user_select->close();
        $user_insert->close();
        $stmt_insert->close();

        $collector_conn->close();
    }

    $logger_conn->close();
    file_put_contents('/var/www/html/syslog/server-side/logs/syslog_sync.log', "Sync completed, total rows inserted: $total_rows_inserted\n", FILE_APPEND);
    echo "Logs synchronized successfully from all collectors! Total rows: $total_rows_inserted";

} catch (Exception $e) {
    if (isset($logger_conn) && $logger_conn->ping()) {
        $logger_conn->rollback();
        $logger_conn->close();
    }
    $error_msg = "Error at " . date('Y-m-d H:i:s') . ": " . $e->getMessage();
    error_log($error_msg);
    file_put_contents('/var/www/html/syslog/server-side/logs/syslog_sync.log', "$error_msg\n", FILE_APPEND);
    echo "An error occurred. Check logs.";
}
?>