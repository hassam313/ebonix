<?php
/*
 * File: king-include/king-ajax/savetwin.php
 *
 * Saves a generated AI Twin result to the king_twins DB table.
 * Also generates a local 600×600 WebP thumbnail for fast gallery display.
 * Returns JSON: { "status": "saved", "thumbnail_url": "..." }
 */

if (!defined('QA_VERSION')) {
    header('Location: ../../');
    exit;
}

require_once QA_INCLUDE_DIR . 'king-app/users.php';
require_once QA_INCLUDE_DIR . 'king-app/limits.php';
require_once QA_INCLUDE_DIR . 'king-db/selects.php';

header('Content-Type: application/json');
ini_set('display_errors', 0);
ini_set('log_errors', 1);

set_exception_handler(function ($e) {
    error_log('SAVETWIN500: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    exit;
});

try {

$userid = qa_get_logged_in_userid();
if (!$userid) {
    echo json_encode(['status' => 'error', 'message' => 'You must be logged in to save your twin.']);
    exit;
}

$image_url = trim((string)($_POST['image_url'] ?? ''));
$vibe      = trim((string)($_POST['vibe']      ?? ''));
$format    = trim((string)($_POST['format']    ?? ''));
$details   = trim((string)($_POST['details']   ?? ''));

if (empty($image_url)) {
    echo json_encode(['status' => 'error', 'message' => 'No image URL provided.']);
    exit;
}

// ── Ensure king_twins table with thumbnail_url column ─────────────────────────
qa_db_query_sub(
    'CREATE TABLE IF NOT EXISTS ^king_twins (
        id            INT(11)     NOT NULL AUTO_INCREMENT,
        user_id       INT(11)     NOT NULL,
        image_url     TEXT        NOT NULL,
        thumbnail_url TEXT        DEFAULT NULL,
        vibe          VARCHAR(64) NOT NULL DEFAULT \'\',
        format        VARCHAR(16) NOT NULL DEFAULT \'4:5\',
        details       TEXT,
        created_at    DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY user_id (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);

$col_check = (int)qa_db_read_one_value(qa_db_query_sub('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=$ AND COLUMN_NAME=$', QA_MYSQL_TABLE_PREFIX.'king_twins', 'thumbnail_url'), true);
if (!$col_check) qa_db_query_sub('ALTER TABLE ^king_twins ADD COLUMN thumbnail_url TEXT DEFAULT NULL');

// ── Generate local 600×600 thumbnail for fast display/selection ───────────────
// Use curl (not file_get_contents) so SSL verification can be disabled on local dev.
$thumbnail_url = '';
try {
    set_time_limit(60);
    $ch = curl_init($image_url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT      => 'EbonixBot/1.0',
    ]);
    $raw  = curl_exec($ch);
    $cerr = curl_error($ch);
    curl_close($ch);
    if ($cerr) error_log('savetwin: curl download error: ' . $cerr);

    if ($raw) {
        $src = @imagecreatefromstring($raw);
        if ($src) {
            $ow    = imagesx($src);
            $oh    = imagesy($src);
            $size  = 600;
            $scale = min($size / $ow, $size / $oh);
            $nw    = max(1, (int)($ow * $scale));
            $nh    = max(1, (int)($oh * $scale));
            $dst   = imagecreatetruecolor($nw, $nh);
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $ow, $oh);
            imagedestroy($src);

            $folder  = 'uploads/' . date('Y') . '/' . date('m') . '/';
            $destDir = QA_INCLUDE_DIR . $folder;
            if (!is_dir($destDir)) @mkdir($destDir, 0755, true);
            $filename = 'twin-' . uniqid('', true) . '.webp';

            if (@imagewebp($dst, $destDir . $filename, 80)) {
                $thumbnail_url = rtrim((string)qa_opt('site_url'), '/') . '/king-include/' . $folder . $filename;
                error_log('savetwin: thumbnail saved ' . $thumbnail_url);
            }
            imagedestroy($dst);
        }
    }
} catch (Exception $e) {
    error_log('savetwin: thumbnail error: ' . $e->getMessage());
}

// ── Insert record ─────────────────────────────────────────────────────────────
qa_db_query_sub(
    'INSERT INTO ^king_twins (user_id, image_url, thumbnail_url, vibe, format, details, created_at)
     VALUES (#, $, $, $, $, $, NOW())',
    (int)$userid,
    $image_url,
    $thumbnail_url ?: null,
    $vibe,
    $format,
    $details
);

echo json_encode(['status' => 'saved', 'thumbnail_url' => $thumbnail_url]);
exit;

} catch (Throwable $e) {
    error_log('SAVETWIN_CATCH: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    exit;
}
