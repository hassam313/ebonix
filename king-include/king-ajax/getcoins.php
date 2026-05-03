<?php
/*
 * File: king-include/king-ajax/getcoins.php
 *
 * Returns current coin balance and breakdown for the logged-in user.
 * Used by the navbar display and post-generation UI updates.
 */

if (!defined('QA_VERSION')) {
    header('Location: ../../');
    exit;
}

header('Content-Type: application/json');

require_once QA_INCLUDE_DIR . 'king-app/users.php';
require_once QA_INCLUDE_DIR . 'king-app/coins.php';
require_once QA_INCLUDE_DIR . 'king-db/metas.php';

$user_id = qa_get_logged_in_userid();
if (!$user_id) {
    echo json_encode(['status' => 'error', 'msg' => 'not_logged_in']);
    exit;
}

// Ensure the user has been initialised in the coin system
ebonix_ensure_initialized($user_id);

$breakdown = ebonix_get_coins_breakdown($user_id);
$plan      = ebonix_get_user_plan($user_id);
$expiry    = (int)(qa_db_usermeta_get($user_id, 'membership_expiry') ?: 0);

echo json_encode([
    'status'       => 'ok',
    'coins'        => $breakdown['total'],
    'from_sub'     => $breakdown['from_sub'],
    'from_topup'   => $breakdown['from_topup'],
    'plan'         => $plan,
    'plan_label'   => $plan >= 1 ? 'Flex' : 'Free',
    'expiry_ts'    => $expiry,
    'expiry_date'  => $expiry > 0 ? date('M j, Y', $expiry) : null,
    'low_balance'  => $breakdown['total'] < 2000,
]);
