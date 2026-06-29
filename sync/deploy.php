<?php
require_once __DIR__ . '/../core/db_config.php';
require_once __DIR__ . '/config.php';
header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

// Action: create zip of oro-store (excludes config files)
if ($action === 'package') {
    $zip_path = sys_get_temp_dir() . '/oro_deploy_' . time() . '.zip';
    $base = realpath(__DIR__ . '/../');
    $zip = new ZipArchive();
    if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        echo json_encode(['success' => false, 'message' => 'Cannot create zip']); exit;
    }

    // Files/folders to skip
    $skip = ['sync/config.php', 'core/db_config.php', 'sync/.main_server', 'old/', 'backups/', 'sync/sync.log'];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, RecursiveDirectoryIterator::SKIP_DOTS));
    $count = 0;
    foreach ($files as $file) {
        if ($file->isDir()) continue;
        $rel = str_replace('\\', '/', substr($file->getRealPath(), strlen($base) + 1));

        $should_skip = false;
        foreach ($skip as $s) {
            if ($s === $rel || strpos($rel, rtrim($s, '/') . '/') === 0) { $should_skip = true; break; }
        }
        if ($should_skip) continue;

        $zip->addFile($file->getRealPath(), $rel);
        $count++;
    }
    $zip->close();

    echo json_encode(['success' => true, 'file' => $zip_path, 'files' => $count, 'size' => round(filesize($zip_path) / 1024) . ' KB']);
    exit;
}

// Action: download the zip (called by branch device)
if ($action === 'download') {
    $key = $_GET['key'] ?? '';
    if ($key !== SYNC_PASSWORD) { http_response_code(401); echo 'Unauthorized'; exit; }

    // Find latest deploy zip
    $pattern = sys_get_temp_dir() . '/oro_deploy_*.zip';
    $zips = glob($pattern);
    if (empty($zips)) { http_response_code(404); echo 'No package found. Create one first.'; exit; }
    rsort($zips);
    $zip_path = $zips[0];

    header('Content-Type: application/zip');
    header('Content-Length: ' . filesize($zip_path));
    readfile($zip_path);
    exit;
}

// Action: deploy to a remote branch device
if ($action === 'push') {
    $target_ip = trim($_GET['ip'] ?? '');
    if (!$target_ip) { echo json_encode(['success' => false, 'message' => 'No target IP']); exit; }

    // Step 1: Create package
    $pkg_url = 'http://127.0.0.1/oro-store/sync/deploy.php?action=package';
    $pkg_raw = @file_get_contents($pkg_url);
    $pkg = json_decode($pkg_raw, true);
    if (!$pkg || !$pkg['success']) {
        echo json_encode(['success' => false, 'message' => 'Failed to create package']); exit;
    }

    // Step 2: Tell branch to pull and apply
    $deploy_url = "http://$target_ip/oro-store/sync/deploy.php?action=pull&source_ip=" . urlencode($_SERVER['SERVER_ADDR'] ?? '10.219.18.80') . "&key=" . urlencode(SYNC_PASSWORD);
    $ctx = stream_context_create(['http' => ['timeout' => 60]]);
    $result_raw = @file_get_contents($deploy_url, false, $ctx);

    if (!$result_raw) {
        echo json_encode(['success' => false, 'message' => "Cannot reach $target_ip"]); exit;
    }

    $result = json_decode($result_raw, true);
    echo json_encode($result ?: ['success' => false, 'message' => 'Invalid response from branch']);
    exit;
}

// Action: pull from Device A and apply (runs on branch device)
if ($action === 'pull') {
    $key = $_GET['key'] ?? '';
    if ($key !== SYNC_PASSWORD) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }

    $source_ip = trim($_GET['source_ip'] ?? '');
    if (!$source_ip) {
        // Try to get Device A's IP from stores
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if (!$conn->connect_error) {
            $r = $conn->query("SELECT device_ip FROM stores WHERE device_id = 'DEVICE_A' AND device_ip IS NOT NULL LIMIT 1");
            if ($r && $row = $r->fetch_assoc()) $source_ip = $row['device_ip'];
            $conn->close();
        }
    }
    if (!$source_ip) { echo json_encode(['success' => false, 'message' => 'No source IP']); exit; }

    // Download zip from Device A
    $zip_url = "http://$source_ip/oro-store/sync/deploy.php?action=download&key=" . urlencode(SYNC_PASSWORD);
    $zip_data = @file_get_contents($zip_url);
    if (!$zip_data || strlen($zip_data) < 100) {
        echo json_encode(['success' => false, 'message' => 'Failed to download package from Device A']); exit;
    }

    $zip_path = sys_get_temp_dir() . '/oro_deploy_received.zip';
    file_put_contents($zip_path, $zip_data);

    // Extract to oro-store folder
    $zip = new ZipArchive();
    if ($zip->open($zip_path) !== true) {
        echo json_encode(['success' => false, 'message' => 'Invalid zip file']); exit;
    }

    $base = realpath(__DIR__ . '/../');
    $extracted = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        $target = $base . '/' . $name;
        $dir = dirname($target);
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        $content = $zip->getFromIndex($i);
        if ($content !== false) {
            file_put_contents($target, $content);
            $extracted++;
        }
    }
    $zip->close();
    @unlink($zip_path);

    echo json_encode(['success' => true, 'extracted' => $extracted, 'size' => round(strlen($zip_data) / 1024) . ' KB']);
    exit;
}

echo json_encode(['error' => 'Unknown action']);
