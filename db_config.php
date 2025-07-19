<?php

$logger_db = [
    'host' => 'localhost', // or 192.168.0.53 if different
    'user' => 'root',
    'password' => '',
    'database' => 'logger_db'
];

function connect_db($config) {
    $conn = new mysqli($config['host'], $config['user'], $config['password'], $config['database']);
    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }
    return $conn;
}
    
try {
    $logger_connection = connect_db($logger_db);
    echo "Connected to logger_db successfully.";
} catch (Exception $e) {}
?>