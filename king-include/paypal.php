<?php

require_once 'king-base.php';
require_once QA_INCLUDE_DIR . 'king-db/selects.php';
require_once QA_INCLUDE_DIR . 'king-app/options.php';
require_once QA_INCLUDE_DIR . 'king-app/users.php';
require_once QA_INCLUDE_DIR . 'king-db/metas.php';

$enableSandbox = qa_opt('paypal_sandbox');
$pageurl       = qa_opt('site_url');

$paypal_url = $enableSandbox
    ? 'https://www.sandbox.paypal.com/cgi-bin/webscr'
    : 'https://www.paypal.com/cgi-bin/webscr';

// ── Helpers ───────────────────────────────────────────────────────────────────

function verifyTransaction($data) {
    global $paypal_url;

    $req = 'cmd=_notify-validate';
    foreach ($data as $key => $value) {
        $value = urlencode(stripslashes($value));
        $value = preg_replace('/(.*[^%^0^D])(%0A)(.*)/i', '${1}%0D%0A${3}', $value);
        $req  .= "&$key=$value";
    }

    $ch = curl_init($paypal_url);
    curl_setopt($ch, CURLOPT_HTTP_VERSION,  CURL_HTTP_VERSION_1_1);
    curl_setopt($ch, CURLOPT_POST,          1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS,    $req);
    curl_setopt($ch, CURLOPT_SSLVERSION,    6);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_FORBID_REUSE,  1);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HTTPHEADER,    array('Connection: Close'));

    $res = curl_exec($ch);
    if (!$res) {
        $errno  = curl_errno($ch);
        $errstr = curl_error($ch);
        curl_close($ch);
        throw new Exception("cURL error: [$errno] $errstr");
    }
    $info     = curl_getinfo($ch);
    $httpCode = $info['http_code'];
    curl_close($ch);
    if ($httpCode != 200) {
        throw new Exception("PayPal responded with http code $httpCode");
    }
    return $res === 'VERIFIED';
}

function ebonix_paypal_activate($user_id, $plan_type, $amount, $currency, $txn_id) {
    $expiry_date      = date('Y-m-d', strtotime('+1 month'));
    $expiry_timestamp = strtotime('+1 month');

    qa_db_usermeta_set($user_id, 'membership_plan',   $plan_type);
    qa_db_usermeta_set($user_id, 'membership',        $expiry_date);
    qa_db_usermeta_set($user_id, 'membership_expiry', $expiry_timestamp);

    if (qa_opt('ailimits')) {
        qa_db_usermeta_set($user_id, 'ailmt', 0);
    }

    // Insert into king_payments
    try {
        qa_db_query_sub(
            'CREATE TABLE IF NOT EXISTS `^king_payments` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `user_id` int(11) NOT NULL,
              `plan` tinyint(2) NOT NULL,
              `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
              `currency` varchar(10) DEFAULT \'USD\',
              `gateway` varchar(20) NOT NULL,
              `transaction_id` varchar(255) DEFAULT NULL,
              `status` varchar(20) DEFAULT \'completed\',
              `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `user_id` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        qa_db_query_sub(
            'INSERT INTO ^king_payments (user_id, plan, amount, currency, gateway, transaction_id, status, created_at) VALUES (#, #, #, $, $, $, $, NOW())',
            (int)$user_id, (int)$plan_type, (float)$amount, strtoupper($currency), 'paypal', (string)$txn_id, 'completed'
        );
    } catch (Exception $e) { /* silent */ }

    // Also insert into legacy ^membership table
    king_insert_membership($plan_type, $amount, $user_id, $txn_id);
}

function ebonix_paypal_downgrade($user_id) {
    qa_db_usermeta_set($user_id, 'membership_plan',   0);
    qa_db_usermeta_set($user_id, 'membership',        '');
    qa_db_usermeta_set($user_id, 'membership_expiry', 0);
    qa_db_usermeta_set($user_id, 'paypal_subscription_id', '');
}

// ── Main IPN handler ──────────────────────────────────────────────────────────

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        exit;
    }

    if (!verifyTransaction($_POST)) {
        error_log('paypal.php: IPN verification FAILED');
        exit;
    }

    $txn_type       = isset($_POST['txn_type'])       ? $_POST['txn_type']       : '';
    $payment_status = isset($_POST['payment_status']) ? $_POST['payment_status'] : '';
    $txn_id         = isset($_POST['txn_id'])         ? $_POST['txn_id']         : '';
    $subscr_id      = isset($_POST['subscr_id'])      ? $_POST['subscr_id']      : '';
    $item_number    = isset($_POST['item_number'])    ? $_POST['item_number']    : '';
    $mc_gross       = isset($_POST['mc_gross'])       ? (float)$_POST['mc_gross'] : 0;
    $mc_currency    = isset($_POST['mc_currency'])    ? strtoupper($_POST['mc_currency']) : 'USD';
    $user_id        = isset($_POST['custom'])         ? $_POST['custom']         : null;
    $payer_email    = isset($_POST['payer_email'])    ? $_POST['payer_email']    : '';
    $item_name      = isset($_POST['item_name'])      ? $_POST['item_name']      : '';

    $auto_renewal = qa_opt('paypal_auto_renewal');

    // ── ONE-TIME PAYMENT (non-subscription mode) ──────────────────────────────
    if (!$auto_renewal && $payment_status === 'Completed') {
        if (qa_opt('enable_membership')) {
            ebonix_paypal_activate($user_id, $item_number, $mc_gross, $mc_currency, $txn_id);
        } else {
            $ocredit = (float)qa_db_usermeta_get($user_id, 'credit');
            $csize   = qa_opt('credits_size') ?: 1;
            qa_db_usermeta_set($user_id, 'credit', $ocredit + ($mc_gross * $csize));
        }
        exit;
    }

    if (!$auto_renewal) {
        exit; // No further handling if auto_renewal is off
    }

    // ── SUBSCRIPTION IPN EVENTS ───────────────────────────────────────────────

    switch ($txn_type) {

        case 'subscr_signup':
            // Subscription created — activate plan immediately
            if ($user_id && $item_number) {
                ebonix_paypal_activate($user_id, $item_number, 0, 'USD', $subscr_id);
                qa_db_usermeta_set($user_id, 'paypal_subscription_id', $subscr_id);
                error_log("paypal.php: subscr_signup user={$user_id} plan={$item_number} subscr_id={$subscr_id}");
            }
            break;

        case 'subscr_payment':
            // Recurring payment received — extend expiry, reset usage
            if ($user_id && $payment_status === 'Completed') {
                // Resolve plan number from subscr_id if item_number is empty
                $plan = $item_number;
                if (empty($plan)) {
                    // Try to find plan from stored subscription or item_name
                    $plan = qa_db_usermeta_get($user_id, 'membership_plan');
                }
                if ($plan) {
                    ebonix_paypal_activate($user_id, $plan, $mc_gross, $mc_currency, $txn_id);
                    error_log("paypal.php: subscr_payment user={$user_id} plan={$plan} amount={$mc_gross}");
                }
            }
            break;

        case 'subscr_cancel':
            // Subscription cancelled by user — plan remains active until expiry, then downgrades
            // We do nothing immediately — the expiry check in aigenerate.php handles it
            if ($user_id) {
                qa_db_usermeta_set($user_id, 'paypal_subscription_id', '');
                error_log("paypal.php: subscr_cancel user={$user_id} — plan active until expiry");
            }
            break;

        case 'subscr_failed':
            // Payment failed — 3-day grace period
            if ($user_id) {
                $grace = date('Y-m-d', strtotime('+3 days'));
                qa_db_usermeta_set($user_id, 'membership',        $grace);
                qa_db_usermeta_set($user_id, 'membership_expiry', strtotime('+3 days'));
                error_log("paypal.php: subscr_failed user={$user_id} grace until {$grace}");
            }
            break;

        case 'subscr_eot':
            // Subscription end-of-term — downgrade now
            if ($user_id) {
                ebonix_paypal_downgrade($user_id);
                error_log("paypal.php: subscr_eot user={$user_id} — downgraded to free");
            }
            break;

        default:
            // Unhandled IPN type — log only
            error_log("paypal.php: unhandled txn_type={$txn_type}");
            break;
    }

} catch (Exception $e) {
    error_log('paypal.php exception: ' . $e->getMessage());
}
