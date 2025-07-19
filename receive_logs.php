<?php
header('Content-Type: application/json');

$servername = "localhost";
$username = "ruser";
$password = "ruser1@Analyzer";
$dbname = "logger_db";

try {
    $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Expecting JSON data from collector
    $input = json_decode(file_get_contents('php://input'), true);

    if (isset($input['timestamp']) && isset($input['source']) && isset($input['message'])) {
        $stmt = $conn->prepare("INSERT INTO logs (timestamp, source, message) VALUES (:timestamp, :source, :message)");
        $stmt->bindParam(':timestamp', $input['timestamp']);
        $stmt->bindParam(':source', $input['source']);
        $stmt->bindParam(':message', $input['message']);
        $stmt->execute();

        echo json_encode(["status" => "success"]);
    } else {
        echo json_encode(["status" => "error", "message" => "Invalid data"]);
    }
} catch (PDOException $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>