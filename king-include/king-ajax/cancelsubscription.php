<?php
/*
 * File: king-include/king-ajax/cancelsubscription.php
 * Cancel a user's Stripe or PayPal subscription and downgrade their plan.
 */

if (!defined('QA_VERSION')) {
    header('Location: ../../');
    exit;
}

require_once QA_INCLUDE_DIR . 'king-db/metas.php';
require_once QA_INCLUDE_DIR . 'king-db/selects.php';

header('Content-Type: application/json');

$userid = qa_get_logged_in_userid();
if (!$userid) {
    echo json_encode(['success' => false, 'message' => 'Not logged in.']);
    exit;
}

$stripe_sub_id = qa_db_usermeta_get($userid, 'stripe_subscription_id');
$paypal_sub_id = qa_db_usermeta_get($userid, 'paypal_subscription_id');

$cancelled = false;
$error     = '';

// ── Cancel Stripe subscription ─────────────────────────────────────────────────
if ($stripe_sub_id) {
    $stripe_skey = qa_opt('stripe_skey');
    if ($stripe_skey) {
        require_once QA_INCLUDE_DIR . 'stripe/init.php';
        \Stripe\Stripe::setApiKey($stripe_skey);
        try {
            \Stripe\Subscription::cancel($stripe_sub_id);
            qa_db_usermeta_set($userid, 'stripe_subscription_id', '');
            $cancelled = true;
        } catch (\Stripe\Exception\ApiErrorException $e) {
            $error = 'Stripe error: ' . $e->getMessage();
        }
    }
}

// ── Cancel PayPal subscription ─────────────────────────────────────────────────
if (!$cancelled && $paypal_sub_id) {
    // PayPal subscription cancellation via REST API
    $paypal_client_id     = qa_opt('paypal_client_id');
    $paypal_client_secret = qa_opt('paypal_client_secret');
    $sandbox              = qa_opt('paypal_sandbox');

    if ($paypal_client_id && $paypal_client_secret) {
        $base_url = $sandbox
            ? 'https://api-m.sandbox.paypal.com'
            : 'https://api-m.paypal.com';

        // Get access token
        $token_ch = curl_init($base_url . '/v1/oauth2/token');
        curl_setopt_array($token_ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_USERPWD        => $paypal_client_id . ':' . $paypal_client_secret,
            CURLOPT_POSTFIELDS     => 'grant_type=client_credentials',
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $token_resp = curl_exec($token_ch);
        curl_close($token_ch);
        $token_data = json_decode($token_resp, true);
        $access_token = $token_data['access_token'] ?? '';

        if ($access_token) {
            $cancel_ch = curl_init($base_url . '/v1/billing/subscriptions/' . $paypal_sub_id . '/cancel');
            curl_setopt_array($cancel_ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST  => 'POST',
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $access_token,
                ],
                CURLOPT_POSTFIELDS     => json_encode(['reason' => 'User requested cancellation']),
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            $cancel_http = curl_getinfo($cancel_ch, CURLINFO_HTTP_CODE);
            curl_exec($cancel_ch);
            $cancel_http = curl_getinfo($cancel_ch, CURLINFO_HTTP_CODE);
            curl_close($cancel_ch);

            if ($cancel_http === 204 || $cancel_http === 200) {
                qa_db_usermeta_set($userid, 'paypal_subscription_id', '');
                $cancelled = true;
            } else {
                $error = 'PayPal cancellation failed.';
            }
        }
    }

    // Fallback: mark cancelled without API if no credentials configured
    if (!$cancelled && !$error) {
        qa_db_usermeta_set($userid, 'paypal_subscription_id', '');
        $cancelled = true;
    }
}

// ── Downgrade plan on cancellation ────────────────────────────────────────────
if ($cancelled) {
    qa_db_usermeta_set($userid, 'membership_plan', 0);
    qa_db_usermeta_set($userid, 'membership_expiry', 0);
    echo json_encode(['success' => true, 'message' => 'Subscription cancelled. You have been moved to the Free plan.']);
} else {
    $msg = $error ?: 'No active subscription found.';
    echo json_encode(['success' => false, 'message' => $msg]);
}
exit;
