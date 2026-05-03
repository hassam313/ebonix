<?php
ob_start();

require_once 'king-base.php';
require_once QA_INCLUDE_DIR . 'king-db/selects.php';
require_once QA_INCLUDE_DIR . 'king-app/options.php';
require_once QA_INCLUDE_DIR . 'king-db.php';
require_once QA_INCLUDE_DIR . 'king-app/users.php';
require_once QA_INCLUDE_DIR . 'king-app/coins.php';
require QA_INCLUDE_DIR . 'stripe/init.php';

/**
 * Look up a plan's price from king_plans (for plan IDs 1+)
 * or fall back to qa_opt for legacy top-up (numeric dollar amount passed as type).
 */
function ebx_resolve_plan_price($type, $is_membership) {
    if ($is_membership) {
        $plan = ebonix_get_plan((int)$type);
        if ($plan) {
            return [(float)$plan['price'], (string)$plan['name']];
        }
        // Legacy fallback
        $usd = (float) qa_opt('plan_usd_' . $type);
        if ($usd <= 0 && (int)$type == 1) {
            $usd = (float)(qa_opt('flex_plan_price') ?: 29.00);
        }
        $title = qa_opt('plan_' . $type . '_title') ?: ('Plan ' . $type);
        return [$usd, $title];
    } else {
        // coin top-up: $type is a dollar amount
        $usd   = (float)$type;
        $title = number_format($usd * (int)(qa_opt('credits_size') ?: 1), 0) . ' Coins';
        return [$usd, $title];
    }
}

header('Content-Type: application/json');
ob_clean();

// ── Stripe secret key ──────────────────────────────────────────────────────────
\Stripe\Stripe::setApiKey(qa_opt('stripe_skey'));

// ── Parse request body ─────────────────────────────────────────────────────────
$jsonStr = file_get_contents('php://input');
$jsonObj = json_decode($jsonStr);

if (empty($jsonObj) || empty($jsonObj->request_type)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request.']);
    exit;
}

$request_type = $jsonObj->request_type;

// ==============================================================================
// 1. CREATE PAYMENT INTENT  (inline Stripe Elements flow)
// ==============================================================================
if ($request_type === 'create_payment_intent') {

    try {
        $type = !empty($jsonObj->price) ? $jsonObj->price : '';
        $uid  = qa_get_logged_in_userid();

        if (!$uid) {
            http_response_code(401);
            echo json_encode(['error' => 'You must be logged in.']);
            exit;
        }

        [$usd] = ebx_resolve_plan_price($type, (bool)qa_opt('enable_membership'));

        if ($usd <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid plan price. Please configure plan prices in admin settings.']);
            exit;
        }

        $amount   = (int) round($usd * 100); // convert to cents
        $currency = strtolower(qa_opt('currency') ?: 'usd');

        $paymentIntent = \Stripe\PaymentIntent::create([
            'currency'    => $currency,
            'amount'      => $amount,
            'description' => (string) $type,
            'metadata'    => [
                'user_id' => (string) $uid,
                'plan'    => (string) $type,
            ],
            'automatic_payment_methods' => ['enabled' => true],
        ]);

        echo json_encode([
            'status'       => 'success',
            'id'           => $paymentIntent->id,
            'clientSecret' => $paymentIntent->client_secret,
        ]);

    } catch (\Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }

// ==============================================================================
// 2. CREATE CUSTOMER  (attach customer to payment intent)
// ==============================================================================
} elseif ($request_type === 'create_customer') {

    $payment_intent_id = !empty($jsonObj->payment_intent_id) ? $jsonObj->payment_intent_id : '';
    $name              = !empty($jsonObj->name)  ? $jsonObj->name  : '';
    $email             = !empty($jsonObj->email) ? $jsonObj->email : '';
    $api_error         = '';
    $customer          = null;

    try {
        $customer = \Stripe\Customer::create([
            'name'  => $name,
            'email' => $email,
        ]);
    } catch (\Exception $e) {
        $api_error = $e->getMessage();
    }

    if (empty($api_error) && $customer) {
        try {
            \Stripe\PaymentIntent::update($payment_intent_id, [
                'customer' => $customer->id,
            ]);
        } catch (\Exception $e) {
            // non-fatal — log if needed
        }

        echo json_encode([
            'id'          => $payment_intent_id,
            'customer_id' => $customer->id,
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => $api_error]);
    }

// ==============================================================================
// 3. CREATE CHECKOUT SESSION  (Stripe-hosted checkout page)
// ==============================================================================
} elseif ($request_type === 'create_checkout_session') {

    try {
        $type   = !empty($jsonObj->price) ? (int) $jsonObj->price : 0;
        $uid    = qa_get_logged_in_userid();
        $email  = qa_get_logged_in_user_field('email');
        $auto_r = (bool) qa_opt('stripe_auto_renewal');

        if (!$uid) {
            http_response_code(401);
            echo json_encode(['error' => 'You must be logged in to purchase.']);
            exit;
        }

        // Price in cents — resolved from king_plans DB (or legacy fallback)
        [$usd_raw, $title] = ebx_resolve_plan_price($type, (bool)qa_opt('enable_membership'));

        $amount_cents = (int) round($usd_raw * 100);

        if ($amount_cents < 50) {
            http_response_code(400);
            echo json_encode(['error' => 'Plan price is not configured. Please set plan prices in admin settings.']);
            exit;
        }

        $currency    = strtolower(qa_opt('currency') ?: 'usd');
        $site_url    = rtrim((string) qa_opt('site_url'), '/');
        $success_url = $site_url . '/membership?pay=succes&session_id={CHECKOUT_SESSION_ID}';
        $cancel_url  = $site_url . '/membership?pay=error';

        $payment_methods = ['card'];
        if (qa_opt('enable_cashapp')) {
            $payment_methods[] = 'cashapp';
        }

        $session_params = [
            'customer_email'       => $email,
            'client_reference_id'  => (string) $uid,
            'success_url'          => $success_url,
            'cancel_url'           => $cancel_url,
            'payment_method_types' => $payment_methods,
            'metadata'             => [
                'plan'    => (string) $type,
                'user_id' => (string) $uid,
                'type'    => $auto_r ? 'subscription' : 'one_time',
            ],
        ];

        if ($auto_r) {
            // ── Recurring subscription ──────────────────────────────────────
            $session_params['mode']       = 'subscription';
            $session_params['line_items'] = [[
                'price_data' => [
                    'currency'     => $currency,
                    'product_data' => [
                        'name'     => $title,
                        'metadata' => ['plan' => (string) $type, 'user_id' => (string) $uid],
                    ],
                    'unit_amount' => $amount_cents,
                    'recurring'   => ['interval' => 'month'],
                ],
                'quantity' => 1,
            ]];
            $session_params['subscription_data'] = [
                'metadata' => ['plan' => (string) $type, 'user_id' => (string) $uid],
            ];
        } else {
            // ── One-time payment ────────────────────────────────────────────
            $session_params['mode']       = 'payment';
            $session_params['line_items'] = [[
                'price_data' => [
                    'currency'     => $currency,
                    'product_data' => ['name' => $title],
                    'unit_amount'  => $amount_cents,
                ],
                'quantity' => 1,
            ]];
            $session_params['payment_intent_data'] = [
                'description' => (string) $type,
                'metadata'    => ['user_id' => (string) $uid, 'plan' => (string) $type],
            ];
        }

        $session = \Stripe\Checkout\Session::create($session_params);

        echo json_encode([
            'status' => 'success',
            'url'    => $session->url,
            'id'     => $session->id,
        ]);

    } catch (\Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }

// ==============================================================================
// 4. PAYMENT INSERT  (post-payment record keeping)
// ==============================================================================
} elseif ($request_type === 'payment_insert') {

    $payment_intent_id = !empty($jsonObj->payment_intent) ? $jsonObj->payment_intent : '';
    $customer_id       = !empty($jsonObj->customer_id)    ? $jsonObj->customer_id    : '';

    try {
        $intent = \Stripe\PaymentIntent::retrieve($payment_intent_id);

        $uid    = $intent->metadata['user_id'] ?? qa_get_logged_in_userid();
        $amount = $intent->amount / 100;
        $type   = $intent->description;

        if ($intent->status === 'succeeded' && $uid) {
            if (qa_opt('enable_membership')) {
                king_insert_membership($type, $amount, $uid, $payment_intent_id);
            } else {
                require_once QA_INCLUDE_DIR . 'king-db/metas.php';
                $ocredit = (float) qa_db_usermeta_get($uid, 'credit');
                $csize   = !empty(qa_opt('credits_size')) ? (float) qa_opt('credits_size') : 1;
                $credit  = $amount * $csize;
                qa_db_usermeta_set($uid, 'credit', $ocredit + $credit);
            }
            echo json_encode(['status' => 'success']);
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Payment not completed or user not found.']);
        }

    } catch (\Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }

// ==============================================================================
// UNKNOWN REQUEST
// ==============================================================================
} else {
    http_response_code(400);
    echo json_encode(['error' => 'Unknown request type: ' . htmlspecialchars($request_type)]);
}