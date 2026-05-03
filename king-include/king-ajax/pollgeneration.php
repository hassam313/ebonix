<?php
if (!defined('QA_VERSION')) { header('Location: ../'); exit; }

// Suppress deprecated/notice output so PHP warnings never corrupt the QA_AJAX_RESPONSE wire format.
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ob_start(); // capture any accidental output before our echo

header('Content-Type: application/json');

require_once QA_INCLUDE_DIR . 'king-app/users.php';
require_once QA_INCLUDE_DIR . 'king-app/limits.php';
require_once QA_INCLUDE_DIR . 'king-db/selects.php';
require_once QA_INCLUDE_DIR . 'king-app-video.php';
require_once QA_INCLUDE_DIR . 'king-app/cookies.php';
require_once QA_INCLUDE_DIR . 'king-db/metas.php';
require_once QA_INCLUDE_DIR . 'king-app/gateway.php';
require_once QA_INCLUDE_DIR . 'king-app/blobs.php';
require_once QA_INCLUDE_DIR . 'king-app/post-create.php';
require_once QA_INCLUDE_DIR . 'king-app/coins.php';

if (!session_id()) @session_start();

$userid       = qa_get_logged_in_userid();
$is_logged_in = !is_null($userid);

if (!$is_logged_in) {
    echo json_encode(['success' => false, 'message' => 'Not logged in.']);
    exit;
}

$job_token = trim((string)(isset($_POST['job_token']) ? $_POST['job_token'] : ''));
if (empty($job_token) || !preg_match('/^[a-f0-9]{24}$/', $job_token)) {
    echo json_encode(['success' => false, 'message' => 'Invalid job token.']);
    exit;
}

$session_key = 'fal_job_' . $job_token;
$job = isset($_SESSION[$session_key]) ? $_SESSION[$session_key] : null;

if (!$job) {
    echo json_encode(['success' => false, 'status' => 'expired', 'message' => 'Job expired or not found. Please try generating again.']);
    exit;
}

if ((int)$job['user_id'] !== (int)$userid) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

if (!empty($job['expiry']) && time() > (int)$job['expiry']) {
    unset($_SESSION[$session_key]);
    echo json_encode(['success' => false, 'status' => 'expired', 'message' => 'Job timed out. Please try again.']);
    exit;
}

$response_url  = (string)($job['response_url'] ?? '');
$request_id_fb = (string)($job['request_id'] ?? '');
// Fallback: construct response_url from request_id if missing (king_fal_queue_submit omits it)
if (empty($response_url) && !empty($request_id_fb)) {
    $response_url = 'https://queue.fal.run/fal-ai/flux-pro/kontext/requests/' . $request_id_fb;
    error_log("pollgeneration: response_url was empty, constructed from request_id: {$response_url}");
}
if (empty($response_url)) {
    echo json_encode(['success' => false, 'status' => 'expired', 'message' => 'No response URL in job. Please try again.']);
    exit;
}

// ── Poll Fal response_url once ─────────────────────────────────────────────
$fal_api_key = trim((string)qa_opt('fal_api'));
if (empty($fal_api_key)) $fal_api_key = trim((string)qa_opt('fal_key_ebonix_studio'));
if (empty($fal_api_key)) $fal_api_key = trim((string)qa_opt('fal_key_ebonix_10'));
if (empty($fal_api_key)) $fal_api_key = trim((string)qa_opt('fal_key_ebonix_20'));
if (empty($fal_api_key)) $fal_api_key = trim((string)qa_opt('fal_key_ebonix_pro'));

$ch = curl_init($response_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Key ' . $fal_api_key,
    'Content-Type: application/json',
]);
$raw  = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$cerr = curl_error($ch);
curl_close($ch);

if ($cerr || !$raw) {
    error_log("pollgeneration: curl error polling {$response_url} — {$cerr}");
    echo json_encode(['success' => true, 'status' => 'pending']);
    exit;
}

$fal = json_decode($raw, true);
if (!is_array($fal)) {
    error_log("pollgeneration: bad JSON from Fal — http={$http} raw=" . substr($raw, 0, 200));
    echo json_encode(['success' => true, 'status' => 'pending']);
    exit;
}

$fal_status = $fal['status'] ?? '';

if (in_array($fal_status, ['IN_QUEUE', 'IN_PROGRESS'], true)) {
    $queue_pos = isset($fal['queue_position']) ? (int)$fal['queue_position'] : null;
    $resp = ['success' => true, 'status' => 'pending'];
    if ($queue_pos !== null) $resp['queue_position'] = $queue_pos;
    echo json_encode($resp);
    exit;
}

// On COMPLETED the full result is embedded directly
$result_images = $fal['images'] ?? ($fal['output']['images'] ?? []);

if (empty($result_images)) {
    error_log("pollgeneration: no images yet — status={$fal_status} http={$http}");
    echo json_encode(['success' => true, 'status' => 'pending']);
    exit;
}

// ── Job done — remove from session before DB ops to prevent double-deduct ──
unset($_SESSION[$session_key]);

// ── Save images ────────────────────────────────────────────────────────────
$uploaded_images = [];
$thumbs          = [];

foreach ($result_images as $img) {
    $image_url = is_array($img) ? ($img['url'] ?? '') : (string)$img;
    $image_url = trim($image_url);
    if (empty($image_url)) continue;

    error_log("pollgeneration: saving image " . $image_url);

    $is_fal_url = (strpos($image_url, 'fal.media') !== false || strpos($image_url, 'fal.run') !== false);

    if ($is_fal_url) {
        // Store CDN URL directly — no download needed. Downloading a 5-15MB Fal image
        // just to resize to 400px was causing 2+ minute delays on VPS and XHR timeouts.
        $cdn_rec = king_store_cdn_url($image_url);
        if (!empty($cdn_rec)) {
            $uploaded_images[] = $cdn_rec;
            $thumbs[] = $cdn_rec;
        }
    } else {
        $thumb = king_urlupload($image_url, true, 400);
        if (!empty($thumb)) $thumbs[] = $thumb;
        $full = king_urlupload($image_url);
        if (!empty($full)) $uploaded_images[] = $full;
    }
}

if (empty($uploaded_images)) {
    error_log("pollgeneration: failed to save any images from Fal result");
    echo json_encode(['success' => false, 'message' => 'Failed to save generated image. Please try again.']);
    exit;
}

// ── Create post ────────────────────────────────────────────────────────────
$extra         = serialize($uploaded_images);
$thumb         = end($thumbs);
$cookieid      = qa_cookie_get();
$aistyle         = (string)($job['aistyle'] ?? '');
$user_prompt     = trim((string)($job['prompt'] ?? ''));
// Show user's typed prompt; fall back to style name if they left the box empty
$prompt_for_post = $user_prompt ?: ($aistyle ?: 'Identity-preserved photo transformation');

$postid = qa_question_create(
    null, $userid,
    qa_get_logged_in_handle(),
    $cookieid, null, $thumb, '', null, null, null, null, null,
    $extra, 'NOTE', null, 'aimg', $prompt_for_post, null
);

qa_db_postmeta_set($postid, 'wai',   true);
qa_db_postmeta_set($postid, 'model', 'fluxkon_selfie');
if (!empty($aistyle)) qa_db_postmeta_set($postid, 'stle', $aistyle);
qa_db_postmeta_set($postid, 'pimage', 'b64');
// Store thumbnail upload IDs so king_ai_posts can show small previews (fast load)
if (!empty($thumbs)) qa_db_postmeta_set($postid, 'img_thumbs', serialize($thumbs));

// ── Deduct coins (only after confirmed save + post creation) ───────────────
$coins       = (int)($job['coins'] ?? 0);
$new_balance = 0;
if ($coins > 0) {
    $new_balance = ebonix_deduct_coins($userid, $coins, 'image_kontext', 'fluxkon_selfie', $postid);
} else {
    $new_balance = ebonix_get_coins($userid);
}

error_log("pollgeneration: complete postid={$postid} coins_deducted={$coins} remaining={$new_balance}");

// ── Render HTML (same post-render path as aigenerate.php) ─────────────────
$posts_html = king_ai_posts($userid, 'aimg');
$posts_html = str_replace('/king-include/king-include/', '/king-include/', $posts_html);

$site_root  = rtrim((string)qa_opt('site_url'), '/');
$posts_html = preg_replace_callback(
    '/(src|href)=["\'](?!https?:\/\/|\/\/|\/)(king-include\/[^"\']*)["\']/',
    function ($m) use ($site_root) {
        return $m[1] . '="' . $site_root . '/' . $m[2] . '"';
    },
    $posts_html
);

ob_end_clean(); // discard any PHP warnings captured above before sending clean response

echo "QA_AJAX_RESPONSE\n1\n" . json_encode([
    'success'         => true,
    'status'          => 'done',
    'postid'          => $postid,
    'model_label'     => 'Identity Transformation',
    'coins_deducted'  => $coins,
    'coins_remaining' => $new_balance,
]) . "\n";

echo $posts_html;
