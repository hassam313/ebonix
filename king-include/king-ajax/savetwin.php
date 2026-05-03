<?php
/*
 * File: king-include/king-ajax/savetwin.php
 *
 * Saves a generated AI Twin result to the king_twins DB table.
 * Returns JSON: { "status": "saved" }
 *
 * IMPORTANT: require_once king-app/users.php FIRST — qa_get_logged_in_userid()
 * is not available without it and will cause a fatal error.
 */

if (!defined('QA_VERSION')) {
    header('Location: ../../');
    exit;
}

// ── Required dependencies (same pattern as aigenerate.php) ────────────────────
require_once QA_INCLUDE_DIR . 'king-app/users.php';
require_once QA_INCLUDE_DIR . 'king-app/limits.php';
require_once QA_INCLUDE_DIR . 'king-db/selects.php';

// ── Always respond as JSON ────────────────────────────────────────────────────
header('Content-Type: application/json');

ini_set('display_errors', 0);
ini_set('log_errors', 1);

set_exception_handler(function ($e) {
    error_log('SAVETWIN500: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    exit;
});

try {

// ── Auth check ────────────────────────────────────────────────────────────────
$userid = qa_get_logged_in_userid();
error_log('savetwin: userid=' . var_export($userid, true));

if (!$userid) {
    echo json_encode(['status' => 'error', 'message' => 'You must be logged in to save your twin.']);
    exit;
}

// ── Read POST params ──────────────────────────────────────────────────────────
$image_url = trim((string)($_POST['image_url'] ?? ''));
$vibe      = trim((string)($_POST['vibe']      ?? ''));
$format    = trim((string)($_POST['format']    ?? ''));
$details   = trim((string)($_POST['details']   ?? ''));

error_log('savetwin: image_url=' . substr($image_url, 0, 80) . ' vibe=' . $vibe . ' format=' . $format);

if (empty($image_url)) {
    echo json_encode(['status' => 'error', 'message' => 'No image URL provided.']);
    exit;
}

// ── Ensure king_twins table exists ────────────────────────────────────────────
try {
    qa_db_query_sub(
        'CREATE TABLE IF NOT EXISTS ^king_twins (
            id         INT(11) NOT NULL AUTO_INCREMENT,
            user_id    INT(11) NOT NULL,
            image_url  TEXT    NOT NULL,
            vibe       VARCHAR(64)  NOT NULL DEFAULT \'\',
            format     VARCHAR(16)  NOT NULL DEFAULT \'4:5\',
            details    TEXT,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    error_log('savetwin: table ensured');
} catch (Exception $e) {
    error_log('savetwin: table create error: ' . $e->getMessage());
    // Non-fatal — table may already exist
}

// ── Insert record ─────────────────────────────────────────────────────────────
try {
    qa_db_query_sub(
        'INSERT INTO ^king_twins (user_id, image_url, vibe, format, details, created_at)
         VALUES (#, $, $, $, $, NOW())',
        (int)$userid,
        $image_url,
        $vibe,
        $format,
        $details
    );
    error_log('savetwin: insert OK for user_id=' . (int)$userid);
} catch (Exception $e) {
    error_log('savetwin: insert error: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Could not save your twin. Please try again.']);
    exit;
}

echo json_encode(['status' => 'saved']);
exit;

} catch (Throwable $e) {
    error_log('SAVETWIN_CATCH: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    exit;
}
