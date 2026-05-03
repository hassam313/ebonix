<?php
// router.php
$path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);

// Absorb VS Code / Claude Code extension internal requests silently.
// These hit the local PHP server by mistake and would otherwise log 404s.
if ($path === '/api/health' || $path === '/api/extension/sync-messages') {
    http_response_code(200);
    header('Content-Type: application/json');
    echo '{}';
    exit;
}
$file = __DIR__ . $path;

// serve existing files directly (css/js/images)
if ($path !== '/' && is_file($file)) {
    return false;
}

// everything else goes through index.php
require __DIR__ . '/index.php';
