<?php
require_once __DIR__ . '/../core/db_config.php';
header('Content-Type: application/json');

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) { echo json_encode(['error' => $conn->connect_error]); exit; }

$dropped = 0;
$r = $conn->query("SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = '" . DB_NAME . "'");
if ($r) {
    while ($row = $r->fetch_assoc()) {
        $conn->query("DROP TRIGGER IF EXISTS `" . $row['TRIGGER_NAME'] . "`");
        $dropped++;
    }
}

$conn->close();
echo json_encode(['success' => true, 'dropped' => $dropped]);
