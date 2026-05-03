<?php
require_once 'king-base.php';
require_once QA_INCLUDE_DIR . 'king-db/selects.php';
require_once QA_INCLUDE_DIR . 'king-app/options.php';
require_once QA_INCLUDE_DIR . 'king-app/users.php';
require_once QA_INCLUDE_DIR . 'king-db/metas.php';
require_once QA_INCLUDE_DIR . 'king-app/coins.php';
require QA_INCLUDE_DIR . 'stripe/init.php';

$stripe          = new \Stripe\StripeClient(qa_opt('stripe_skey'));
$endpoint_secret = qa_opt('webhook_key');

$payload    = @file_get_contents('php://input');
$sig_header = isset($_SERVER['HTTP_STRIPE_SIGNATURE']) ? $_SERVER['HTTP_STRIPE_SIGNATURE'] : '';
$event      = null;

try {
    $event = \Stripe\Webhook::constructEvent($payload, $sig_header, $endpoint_secret);
} catch (\UnexpectedValueException $e) {
    http_response_code(400);
    exit();
} catch (\Stripe\Exception\SignatureVerificationException $e) {
    http_response_code(400);
    exit();
}

// ── Helper: verify user_id is a real user in the DB ──────────────────────────
function ebonix_webhook_verify_user($user_id) {
    if (empty($user_id) || !is_numeric($user_id)) return false;
    $uid = (int)$user_id;
    if ($uid <= 0) return false;
    try {
        $row = qa_db_read_one_assoc(
            qa_db_query_sub('SELECT userid FROM ^users WHERE userid=#', $uid),
            true
        );
        return !empty($row);
    } catch (Exception $e) {
        error_log("webhook: DB user verify error: " . $e->getMessage());
        return false;
    }
}

// ── Helper: validate plan_type is a known plan ────────────────────────────────
function ebonix_webhook_valid_plan($plan_type) {
    return in_array((string)$plan_type, ['1', 'flex', '2', 'motion', '3', 'simp'], true);
}

// ── Helper: activate or extend a plan for a user ─────────────────────────────
function ebonix_activate_plan($user_id, $plan_type, $stripe_customer_id = null, $stripe_sub_id = null) {
    require_once QA_INCLUDE_DIR . 'king-db/metas.php';

    // Calculate expiry date (+1 month)
    $expiry_date      = date('Y-m-d', strtotime('+1 month'));
    $expiry_timestamp = strtotime('+1 month');

    qa_db_usermeta_set($user_id, 'membership_plan',   $plan_type);
    qa_db_usermeta_set($user_id, 'membership',        $expiry_date);
    qa_db_usermeta_set($user_id, 'membership_expiry', $expiry_timestamp);

    if ($stripe_customer_id) {
        qa_db_usermeta_set($user_id, 'stripe_customer_id', $stripe_customer_id);
    }
    if ($stripe_sub_id) {
        qa_db_usermeta_set($user_id, 'stripe_subscription_id', $stripe_sub_id);
    }

    // Grant monthly coins for new or renewed subscription
    ebonix_grant_subscription_coins($user_id);

    // Keep legacy king_payments table available
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
              `coins_added` INT DEFAULT 0,
              `topup_pack` VARCHAR(50) DEFAULT \'\',
              `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `user_id` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    } catch (Exception $e) { /* table creation failed silently */ }
}

function ebonix_downgrade_plan($user_id) {
    require_once QA_INCLUDE_DIR . 'king-db/metas.php';
    qa_db_usermeta_set($user_id, 'membership_plan',   0);
    qa_db_usermeta_set($user_id, 'membership',        '');
    qa_db_usermeta_set($user_id, 'membership_expiry', 0);
    qa_db_usermeta_set($user_id, 'stripe_subscription_id', '');
}

function ebonix_log_payment($user_id, $plan, $amount, $currency, $gateway, $transaction_id) {
    try {
        qa_db_query_sub(
            'INSERT INTO ^king_payments (user_id, plan, amount, currency, gateway, transaction_id, status, created_at) VALUES (#, #, #, $, $, $, $, NOW())',
            (int)$user_id, (int)$plan, (float)$amount, strtoupper($currency), $gateway, $transaction_id, 'completed'
        );
    } catch (Exception $e) { /* log silently */ }
}

// ── Handle events ─────────────────────────────────────────────────────────────
switch ($event->type) {

    // ── One-time payment succeeded (legacy PaymentIntent flow) ───────────────
    case 'payment_intent.succeeded':
        $pi            = $event->data->object;
        $transactionID = $pi->id;
        $paidAmount    = $pi->amount / 100;
        $paidCurrency  = strtoupper($pi->currency);
        $type          = $pi->description;
        $user_id       = $pi->metadata['user_id'] ?? null;

        if ($transactionID && $user_id && ebonix_webhook_verify_user($user_id)) {
            if (qa_opt('enable_membership')) {
                ebonix_activate_plan($user_id, $type, null, null);
                ebonix_log_payment($user_id, (int)$type, $paidAmount, $paidCurrency, 'stripe', $transactionID);
                // Also insert into legacy ^membership table
                king_insert_membership($type, $paidAmount, $user_id, $transactionID);
            } else {
                $ocredit = (float)qa_db_usermeta_get($user_id, 'credit');
                $csize   = qa_opt('credits_size') ?: 1;
                qa_db_usermeta_set($user_id, 'credit', $ocredit + ($paidAmount * $csize));
            }
        }
        break;

    // ── Checkout Session completed (new subscription/one-time checkout flow) ─
    case 'checkout.session.completed':
        $session     = $event->data->object;
        $user_id     = $session->client_reference_id ?: ($session->metadata['user_id'] ?? null);
        $customer_id = $session->customer;

        // ── Coin top-up purchase ─────────────────────────────────────────────
        if (isset($session->metadata['type']) && $session->metadata['type'] === 'coin_topup') {
            if ($user_id && ebonix_webhook_verify_user($user_id)) {
                $coins     = (int)($session->metadata['coins'] ?? 0);
                $pack_name = $session->metadata['pack_name'] ?? '';
                if ($coins > 0) {
                    ebonix_grant_topup_coins((int)$user_id, $coins, $pack_name);
                }
                $amount_paid = ($session->amount_total ?? 0) / 100;
                try {
                    qa_db_query_sub(
                        'INSERT INTO ^king_payments (user_id, plan, amount, currency, gateway, transaction_id, status, coins_added, topup_pack, created_at)
                         VALUES (#, #, #, $, $, $, $, #, $, NOW())',
                        (int)$user_id, 0, (float)$amount_paid, 'USD', 'stripe',
                        $session->id, 'completed', $coins, $pack_name
                    );
                } catch (Exception $e) { /* log silently */ }
            }
            break;
        }

        // ── Subscription or one-time plan purchase ───────────────────────────
        $plan_type = null;
        $sub_id    = null;

        if ($session->mode === 'subscription' && $session->subscription) {
            try {
                $sub       = $stripe->subscriptions->retrieve($session->subscription);
                $plan_type = $sub->metadata['plan'] ?? null;
                $sub_id    = $session->subscription;
            } catch (Exception $e) { $sub_id = null; }
        } elseif ($session->mode === 'payment' && $session->payment_intent) {
            try {
                $pi        = $stripe->paymentIntents->retrieve($session->payment_intent);
                $plan_type = !empty($pi->metadata['plan']) ? $pi->metadata['plan'] : ($pi->description ?: null);
                $sub_id    = null;
            } catch (Exception $e) { $sub_id = null; }
        }

        // Fallback: metadata on session itself
        if (!$plan_type && isset($session->metadata['plan'])) {
            $plan_type = $session->metadata['plan'];
        }

        if ($user_id && $plan_type && ebonix_webhook_verify_user($user_id) && ebonix_webhook_valid_plan($plan_type)) {
            $amount   = ($session->amount_total ?? 0) / 100;
            $currency = strtoupper($session->currency ?? 'USD');
            ebonix_activate_plan($user_id, $plan_type, $customer_id, $sub_id);
            ebonix_log_payment($user_id, (int)$plan_type, $amount, $currency, 'stripe', $session->id);
            king_insert_membership($plan_type, $amount, $user_id, $session->id);
        } elseif ($user_id && !ebonix_webhook_verify_user($user_id)) {
            error_log("webhook: checkout.session.completed — invalid user_id={$user_id}, ignoring");
        }
        break;

    // ── Subscription renewal succeeded — extend expiry, reset usage ──────────
    case 'invoice.payment_succeeded':
        $invoice = $event->data->object;
        // Only for subscription renewals (not the first invoice, which checkout handles)
        if (empty($invoice->subscription) || $invoice->billing_reason === 'subscription_create') {
            break;
        }
        try {
            $sub       = $stripe->subscriptions->retrieve($invoice->subscription);
            $user_id   = $sub->metadata['user_id'] ?? null;
            $plan_type = $sub->metadata['plan'] ?? null;
        } catch (Exception $e) { break; }

        if ($user_id && $plan_type && ebonix_webhook_verify_user($user_id) && ebonix_webhook_valid_plan($plan_type)) {
            $amount   = ($invoice->amount_paid ?? 0) / 100;
            $currency = strtoupper($invoice->currency ?? 'USD');
            ebonix_activate_plan($user_id, $plan_type, $invoice->customer, $invoice->subscription);
            ebonix_log_payment($user_id, (int)$plan_type, $amount, $currency, 'stripe', $invoice->id);
        } elseif ($user_id && !ebonix_webhook_verify_user($user_id)) {
            error_log("webhook: invoice.payment_succeeded — invalid user_id={$user_id}, ignoring");
        }
        break;

    // ── Invoice payment failed — 3-day grace period then downgrade ───────────
    case 'invoice.payment_failed':
        $invoice = $event->data->object;
        if (empty($invoice->subscription)) break;
        try {
            $sub       = $stripe->subscriptions->retrieve($invoice->subscription);
            $user_id   = $sub->metadata['user_id'] ?? null;
        } catch (Exception $e) { break; }

        if ($user_id && ebonix_webhook_verify_user($user_id)) {
            $grace_expiry = date('Y-m-d', strtotime('+3 days'));
            qa_db_usermeta_set($user_id, 'membership',        $grace_expiry);
            qa_db_usermeta_set($user_id, 'membership_expiry', strtotime('+3 days'));
            error_log("webhook: payment_failed grace period for user {$user_id} until {$grace_expiry}");
        }
        break;

    // ── Subscription cancelled / deleted — downgrade at period end ───────────
    case 'customer.subscription.deleted':
        $sub       = $event->data->object;
        $user_id   = $sub->metadata['user_id'] ?? null;

        if ($user_id && ebonix_webhook_verify_user($user_id)) {
            qa_db_usermeta_set($user_id, 'stripe_subscription_id', '');
            error_log("webhook: subscription deleted for user {$user_id}");
        } elseif ($user_id) {
            error_log("webhook: subscription.deleted — invalid user_id={$user_id}, ignoring");
        }
        break;

    default:
        // No action for other event types
        break;
}

http_response_code(200);
