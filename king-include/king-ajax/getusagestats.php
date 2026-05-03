<?php
/*
 * File: king-include/king-ajax/getusagestats.php
 *
 * Returns current AI usage stats for the logged-in user.
 * JSON: { "used": N, "limit": N_or_null, "percent": N }
 */

if (!defined('QA_VERSION')) {
    header('Location: ../../');
    exit;
}

require_once QA_INCLUDE_DIR . 'king-app/users.php';
require_once QA_INCLUDE_DIR . 'king-db/metas.php';

header('Content-Type: application/json');
ini_set('display_errors', 0);
ini_set('log_errors', 1);

$userid = qa_get_logged_in_userid();

if (!$userid) {
    echo json_encode(['error' => 'not_logged_in']);
    exit;
}

$alm   = (int)qa_db_usermeta_get($userid, 'ailmt');
$vlmt  = (int)qa_db_usermeta_get($userid, 'aivlmt');
$mp    = (int)qa_db_usermeta_get($userid, 'membership_plan');

// Image limits
$img_defaults  = [1 => 60, 2 => 160, 3 => 350];
$vid_defaults  = [1 => 6,  2 => 15,  3 => 30];

$img_limit = null;
$vid_limit = null;

if ($mp > 0) {
    $raw_img = (int)qa_opt('plan_' . $mp . '_lmt');
    $img_limit = ($raw_img > 0) ? $raw_img : ($img_defaults[$mp] ?? null);

    $raw_vid = (int)qa_opt('plan_' . $mp . '_vlmt');
    $vid_limit = ($raw_vid > 0) ? $raw_vid : ($vid_defaults[$mp] ?? null);
} elseif (qa_opt('ulimits')) {
    $raw = (int)qa_opt('ulimit');
    if ($raw > 0) $img_limit = $raw;
}

// Free plan: 2 lifetime images, 0 videos
if ($mp === 0) {
    $img_limit = 2;
    $vid_limit = 0;
}

$img_percent = ($img_limit && $img_limit > 0)
    ? min(100, (int)round($alm * 100 / $img_limit))
    : 0;
$vid_percent = ($vid_limit && $vid_limit > 0)
    ? min(100, (int)round($vlmt * 100 / $vid_limit))
    : 0;

echo json_encode([
    'used'        => $alm,
    'limit'       => $img_limit,
    'percent'     => $img_percent,
    'video_used'  => $vlmt,
    'video_limit' => $vid_limit,
    'video_pct'   => $vid_percent,
    'plan'        => $mp,
]);
exit;
