<?php
if (!defined('QA_VERSION')) { header('Location: ../../'); exit; }

ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json');

require_once QA_INCLUDE_DIR . 'king-app/coins.php';

if (!session_id()) @session_start();

$userid = qa_get_logged_in_userid();
if (!$userid) {
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
    // Job already completed or expired — nothing to cancel, no coins to deduct.
    echo json_encode(['success' => true, 'message' => 'Job not found (already complete or expired).', 'coins_deducted' => 0]);
    exit;
}

if ((int)$job['user_id'] !== (int)$userid) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

// Remove from session immediately to prevent pollgeneration from picking it up.
unset($_SESSION[$session_key]);

// Deduct coins because Fal AI has already started processing (the job was submitted).
$coins = (int)($job['coins'] ?? 0);
$new_balance = 0;
if ($coins > 0) {
    $model  = (string)($job['model'] ?? 'fluxkon_selfie');
    $new_balance = ebonix_deduct_coins($userid, $coins, 'image_cancelled', $model, null);
    error_log("cancelgeneration: user={$userid} cancelled job token={$job_token} — deducted {$coins} coins (API already ran), remaining={$new_balance}");
} else {
    $new_balance = ebonix_get_coins($userid);
    error_log("cancelgeneration: user={$userid} cancelled job token={$job_token} — no coins to deduct");
}

echo json_encode([
    'success'         => true,
    'coins_deducted'  => $coins,
    'coins_remaining' => $new_balance,
]);
