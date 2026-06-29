<?php
require_once __DIR__ . '/config.php';
header('Content-Type: application/json');

$action = $_GET['action'] ?? '';
$conn = getLocalConnection();

if ($action === 'force_sync') {
    require_once __DIR__ . '/http_sync.php';
    $result = httpSync();
    echo json_encode($result);
    exit;
}

if ($action === 'clear_synced') {
    $count = 0;
    $r = $conn->query("SELECT COUNT(*) as c FROM sync_log WHERE synced = 1");
    if ($r) $count = $r->fetch_assoc()['c'];
    $conn->query("DELETE FROM sync_log WHERE synced = 1");
    echo json_encode(['success' => true, 'cleared' => $count]);
    $conn->close();
    exit;
}

if ($action === 'remote_status') {
    $url = 'http://' . REMOTE_IP . '/oro-store/sync/sync_api.php?action=status&key=' . urlencode(SYNC_PASSWORD);
    $ctx = stream_context_create(['http' => ['timeout' => 5, 'header' => "X-Sync-Key: " . SYNC_PASSWORD . "\r\n"]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw) {
        $data = json_decode($raw, true);
        if ($data && !empty($data['success'])) {
            echo json_encode(['success' => true, 'remote' => $data]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Remote error: ' . ($data['error'] ?? 'invalid response')]);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Cannot reach remote at ' . REMOTE_IP]);
    }
    $conn->close();
    exit;
}

if ($action === 'backup_db') {
    $backup_dir = __DIR__ . '/../backups';
    if (!is_dir($backup_dir)) @mkdir($backup_dir, 0777, true);

    $filename = 'product_db_' . date('Y-m-d_His') . '.sql';
    $filepath = $backup_dir . '/' . $filename;

    $mysqldump = 'C:\\xampp\\mysql\\bin\\mysqldump.exe';
    if (!file_exists($mysqldump)) $mysqldump = 'mysqldump';

    $cmd = "\"$mysqldump\" --user=root --host=localhost product_db > \"$filepath\" 2>&1";
    $output = shell_exec($cmd);

    if (file_exists($filepath) && filesize($filepath) > 100) {
        $size = round(filesize($filepath) / 1024, 1);
        echo json_encode(['success' => true, 'file' => $filename, 'size' => $size . ' KB', 'path' => 'backups/' . $filename]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Backup failed. ' . ($output ?: 'mysqldump not found or no data.')]);
    }
    $conn->close();
    exit;
}

if ($action === 'view_pending') {
    $pending = [];
    $r = $conn->query("SELECT table_name, operation, COUNT(*) as cnt FROM sync_log WHERE synced = 0 GROUP BY table_name, operation ORDER BY cnt DESC LIMIT 20");
    if ($r) {
        while ($row = $r->fetch_assoc()) $pending[] = $row;
    }
    $total = $conn->query("SELECT COUNT(*) as c FROM sync_log WHERE synced = 0")->fetch_assoc()['c'];
    echo json_encode(['success' => true, 'total' => intval($total), 'breakdown' => $pending]);
    $conn->close();
    exit;
}

echo json_encode(['error' => 'Unknown action']);
$conn->close();
