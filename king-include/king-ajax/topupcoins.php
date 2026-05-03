<?php
/*
 * File: king-include/king-ajax/topupcoins.php
 *
 * Creates a Stripe one-time Checkout Session for a coin top-up pack purchase.
 * Returns { status: 'ok', checkout_url: '...' } on success.
 */

if (!defined('QA_VERSION')) {
    header('Location: ../../');
    exit;
}

header('Content-Type: application/json');

require_once QA_INCLUDE_DIR . 'king-app/users.php';
require_once QA_INCLUDE_DIR . 'king-app/coins.php';

$user_id = qa_get_logged_in_userid();
if (!$user_id) {
    echo json_encode(['status' => 'error', 'msg' => 'not_logged_in']);
    exit;
}

$pack_name = trim((string)(qa_post_text('pack_name') ?: ''));
$selected  = $pack_name ? ebonix_get_topup_pack_by_name($pack_name) : null;
if (!$selected || !(int)$selected['is_active']) {
    echo json_encode(['status' => 'error', 'msg' => 'invalid_pack']);
    exit;
}

if (!qa_opt('enable_stripe')) {
    echo json_encode(['status' => 'error', 'msg' => 'stripe_disabled']);
    exit;
}

require QA_INCLUDE_DIR . 'stripe/init.php';
\Stripe\Stripe::setApiKey(qa_opt('stripe_skey'));

$payment_methods = ['card'];
if (qa_opt('enable_cashapp')) {
    $payment_methods[] = 'cashapp';
}

$site_url    = rtrim((string)qa_opt('site_url'), '/');
$success_url = $site_url . '/myplan?topup=success&pack=' . urlencode($selected['pack_name']);
$cancel_url  = $site_url . '/membership#topup';

try {
    $session = \Stripe\Checkout\Session::create([
        'mode'                 => 'payment',
        'payment_method_types' => $payment_methods,
        'customer_email'       => qa_get_logged_in_user_field('email'),
        'client_reference_id'  => (string)$user_id,
        'line_items'           => [[
            'price_data' => [
                'currency'     => 'usd',
                'product_data' => [
                    'name' => $selected['label'] . ' — Ebonix Coins',
                ],
                'unit_amount'  => $selected['price_cents'],
            ],
            'quantity' => 1,
        ]],
        'metadata' => [
            'user_id'   => (string)$user_id,
            'coins'     => (string)$selected['coins'],
            'pack_name' => $selected['pack_name'],
            'type'      => 'coin_topup',
        ],
        'success_url' => $success_url,
        'cancel_url'  => $cancel_url,
    ]);

    echo json_encode(['status' => 'ok', 'checkout_url' => $session->url]);
} catch (Exception $e) {
    error_log('topupcoins Stripe error: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
}
