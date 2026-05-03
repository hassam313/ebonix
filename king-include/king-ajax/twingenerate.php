<?php
/*
 * File: king-include/king-ajax/twingenerate.php
 *
 * AI Twin generation — routes through the Ebonix Python gateway at
 * http://127.0.0.1:8001/transform_selfie (Fal AI FLUX.1 Kontext).
 *
 * NEVER calls AI APIs directly from PHP. Never uses Kling JWT.
 */

if (!defined('QA_VERSION')) {
    header('Location: ../../');
    exit;
}

require_once QA_INCLUDE_DIR . 'king-app/users.php';
require_once QA_INCLUDE_DIR . 'king-app/limits.php';
require_once QA_INCLUDE_DIR . 'king-db/selects.php';
require_once QA_INCLUDE_DIR . 'king-app-video.php';
require_once QA_INCLUDE_DIR . 'king-app/coins.php';
require_once QA_INCLUDE_DIR . 'king-db/metas.php';

ini_set('display_errors', 0);
ini_set('log_errors', 1);
set_exception_handler(function($e) {
    error_log('TWIN500: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    exit;
});

try {

set_time_limit(150);
ini_set('max_execution_time', 150);
ini_set('memory_limit', '256M');

// ── Auth check ────────────────────────────────────────────────────────────────
$userid = qa_get_logged_in_userid();
if (!$userid) {
    echo json_encode(['status' => 'error', 'message' => 'You must be logged in to use AI Twin.']);
    exit;
}

// ── Plan + Coin enforcement — cost always calculated; enforcement for non-admins only ──
$is_admin  = qa_get_logged_in_level() >= QA_USER_LEVEL_ADMIN;
$twin_cost = (int)(qa_opt('coin_cost_twin') ?: 120);

ebonix_ensure_initialized($userid);
$user_plan_now = ebonix_get_user_plan($userid);

// Enforcement — non-admin only (admin can always generate)
if (!$is_admin) {
    // Free plan: AI Twin is not available — hard block
    if ($user_plan_now === 0) {
        echo json_encode([
            'status'  => 'error',
            'message' => 'upgrade_required',
            'feature' => 'twin',
            'msg'     => 'AI Twin requires the Flex plan.',
        ]);
        exit;
    }

    // Check coin balance
    if (!ebonix_has_coins($userid, $twin_cost)) {
        echo json_encode([
            'status'        => 'error',
            'message'       => 'insufficient_coins',
            'coins_needed'  => $twin_cost,
            'coins_balance' => ebonix_get_coins($userid),
            'shortfall'     => $twin_cost - ebonix_get_coins($userid),
        ]);
        exit;
    }
}

// ── Read POST params ──────────────────────────────────────────────────────────
$twin_vibe     = trim((string)($_POST['twin_vibe']     ?? ''));
$twin_details  = trim((string)($_POST['twin_details']  ?? ''));
$aspect_ratio  = trim((string)($_POST['aspect_ratio']  ?? '4:5'));
$ref_image_b64 = trim((string)($_POST['ref_image_b64'] ?? ''));

// ── Validate required fields ──────────────────────────────────────────────────
if (empty($ref_image_b64)) {
    echo json_encode(['status' => 'error', 'message' => 'Please upload a photo before generating.']);
    exit;
}
if (empty($twin_vibe)) {
    echo json_encode(['status' => 'error', 'message' => 'Please choose a Twin vibe before generating.']);
    exit;
}

// ── Sanitise aspect ratio ─────────────────────────────────────────────────────
$allowed_ratios = ['1:1', '4:5', '16:9', '5:4', '2:3'];
if (!in_array($aspect_ratio, $allowed_ratios, true)) {
    $aspect_ratio = '4:5';
}

// ── Strip data URI prefix and detect MIME type ────────────────────────────────
// e.g. "data:image/jpeg;base64,/9j/..." → "/9j/..."
$clean_b64 = $ref_image_b64;
$mime_type  = 'image/jpeg';
if (preg_match('/^data:([^;]+);base64,(.+)$/s', $ref_image_b64, $m)) {
    $mime_type = $m[1];
    $clean_b64 = $m[2];
}
$clean_b64 = trim($clean_b64);

if (empty($clean_b64)) {
    echo json_encode(['status' => 'error', 'message' => 'Could not read the uploaded image. Please try again.']);
    exit;
}

// ── Vibe → base prompt mapping ────────────────────────────────────────────────
$vibe_prompts = [
    'everyday'      => 'natural everyday look, clean skin, casual style, soft natural lighting, authentic beauty',
    'soft-glam'     => 'soft glam beauty portrait, glowing skin, subtle makeup, warm golden light, polished but natural',
    'luxury'        => 'luxury editorial portrait, high fashion styling, rich textures, professional studio lighting',
    'editorial'     => 'bold editorial fashion portrait, dramatic lighting, avant-garde styling, magazine quality',
    'fantasy'       => 'fantasy portrait, ethereal atmosphere, magical lighting, otherworldly beauty, dreamlike',
    'afro-futurist' => 'afrofuturist portrait, vibrant cultural aesthetics, futuristic elements, rich skin tones, regal presence',
];

$base_prompt  = $vibe_prompts[$twin_vibe] ?? 'natural portrait, authentic beauty, soft natural lighting';
$final_prompt = !empty($twin_details) ? $base_prompt . ', ' . $twin_details : $base_prompt;

error_log("twingenerate: vibe={$twin_vibe} ratio={$aspect_ratio} prompt=" . substr($final_prompt, 0, 80));

// ── POST to gateway /transform_selfie ─────────────────────────────────────────
$gateway_url   = 'http://127.0.0.1:8001/transform_selfie';
$gateway_token = 'ebonix_secret_12345';

$payload = json_encode([
    'image_b64'         => $clean_b64,
    'mime_type'         => $mime_type,
    'style_preset'      => $twin_vibe,
    'additional_prompt' => $final_prompt,
]);

$ch = curl_init($gateway_url);
curl_setopt($ch, CURLOPT_POST,           true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT,        120);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
curl_setopt($ch, CURLOPT_HTTPHEADER,     [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $gateway_token,
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

$raw_response = curl_exec($ch);
$curl_err     = curl_error($ch);
$http_code    = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

error_log("twingenerate: gateway HTTP {$http_code} response=" . substr((string)$raw_response, 0, 300));

if ($curl_err) {
    error_log("twingenerate: CURL error: {$curl_err}");
    echo json_encode(['status' => 'error', 'message' => 'Could not reach the AI gateway. Please try again.']);
    exit;
}

if ($http_code < 200 || $http_code >= 300) {
    error_log("twingenerate: gateway returned HTTP {$http_code}");
    echo json_encode(['status' => 'error', 'message' => 'AI gateway error (HTTP ' . $http_code . '). Please try again.']);
    exit;
}

// ── Parse gateway response ────────────────────────────────────────────────────
$result = json_decode($raw_response, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    error_log("twingenerate: JSON parse error on gateway response");
    echo json_encode(['status' => 'error', 'message' => 'Invalid response from AI gateway. Please try again.']);
    exit;
}

if (empty($result['success'])) {
    $err = $result['error'] ?? 'Generation failed. Please try again.';
    error_log("twingenerate: gateway reported failure: {$err}");
    echo json_encode(['status' => 'error', 'message' => $err]);
    exit;
}

// Gateway returns image_urls as an array — take the first one
$image_urls = $result['image_urls'] ?? [];
$image_url  = $image_urls[0] ?? '';

if (empty($image_url)) {
    error_log("twingenerate: no image URL in gateway response");
    echo json_encode(['status' => 'error', 'message' => 'No image was returned. Please try again.']);
    exit;
}

error_log("twingenerate: success image_url={$image_url}");

// ── Deduct coins on confirmed success (all users) ────────────────────────────
$new_coin_balance = ebonix_deduct_coins($userid, $twin_cost, 'twin_generate', 'fluxkon_selfie');

echo json_encode([
    'status'          => 'success',
    'image_url'       => $image_url,
    'vibe'            => $twin_vibe,
    'format'          => $aspect_ratio,
    'coins_deducted'  => $twin_cost,
    'coins_remaining' => $new_coin_balance,
]);
exit;

} catch (Throwable $e) {
    error_log('TWINCATCH: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    exit;
}
