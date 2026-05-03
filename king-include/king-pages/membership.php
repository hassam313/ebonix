<?php
/*
 * File: king-include/king-pages/membership.php
 * Ebonix — Pricing page: Free / Flex plans + Coin Top-Up packs.
 */

if (!defined('QA_VERSION')) {
    header('Location: ../');
    exit;
}

require_once QA_INCLUDE_DIR . 'king-db/selects.php';
require_once QA_INCLUDE_DIR . 'king-app/format.php';
require_once QA_INCLUDE_DIR . 'king-db/metas.php';
require_once QA_INCLUDE_DIR . 'king-app/coins.php';

// ── Helper: safe qa_opt wrapper ───────────────────────────────────────────────
function ebx_opt($key, $default = '') {
    $v = qa_opt($key);
    return ($v !== false && $v !== null && $v !== '') ? $v : $default;
}

// ── Current user ──────────────────────────────────────────────────────────────
$userid = qa_get_logged_in_userid();

// ── Payment gateway flags ─────────────────────────────────────────────────────
$stripe_enabled  = qa_opt('enable_stripe')
                   && !empty(qa_opt('stripe_pkey'))
                   && !empty(qa_opt('stripe_skey'));
$paypal_enabled  = qa_opt('enable_paypal') && !empty(qa_opt('paypal_email'));
$cashapp_enabled = $stripe_enabled && qa_opt('enable_cashapp');
$auto_stripe     = qa_opt('stripe_auto_renewal'); // subscribe vs one-time

// ── URLs ──────────────────────────────────────────────────────────────────────
$site_url   = rtrim((string)qa_opt('site_url'), '/');
$create_url = $site_url . '/king-include/create.php';

// ── Load dynamic plans from DB ────────────────────────────────────────────────
$db_plans = ebonix_get_plans(true); // active plans only

// Flex plan display price (first plan, or fallback)
$primary_plan      = !empty($db_plans) ? $db_plans[0] : null;
$flex_price_raw    = $primary_plan ? (float)$primary_plan['price'] : (float)(qa_opt('flex_plan_price') ?: 29.00);
$flex_price_disp   = '$' . number_format($flex_price_raw, 0);

// ── Monthly coins for Flex ────────────────────────────────────────────────────
$monthly_coins_raw = $primary_plan ? (int)$primary_plan['monthly_coins'] : (int)(qa_opt('flex_plan_monthly_coins') ?: 10000);
$monthly_coins     = number_format($monthly_coins_raw);

// ── Current user plan ─────────────────────────────────────────────────────────
$user_plan_id = $userid ? ebonix_get_user_plan($userid) : 0;

// ── Coin top-up packs — sourced from coins.php ────────────────────────────────
$_raw_packs  = ebonix_get_topup_packs();
$topup_packs = [];
foreach ($_raw_packs as $p) {
    $topup_packs[] = [
        'coins'     => $p['label'],
        'price'     => $p['price_cents'] / 100,
        'best_for'  => $p['best_for'],
        'pack_name' => $p['pack_name'],
    ];
}

// ── "What can I make" table rows ──────────────────────────────────────────────
$base_coins   = $monthly_coins_raw; // 10,000
$value_items  = [
    ['icon' => 'fa-image',        'label' => 'Standard Photos',    'cost' =>   40, 'type' => 'photo'],
    ['icon' => 'fa-image',        'label' => 'Enhanced Photos',    'cost' =>   80, 'type' => 'photo'],
    ['icon' => 'fa-star',         'label' => 'Beauty / Editorial', 'cost' =>  100, 'type' => 'photo'],
    ['icon' => 'fa-gem',          'label' => 'Premium Pro Photos', 'cost' =>  120, 'type' => 'photo'],
    ['icon' => 'fa-film',         'label' => 'Basic Short Videos', 'cost' =>  700, 'type' => 'video'],
    ['icon' => 'fa-film',         'label' => 'Enhanced Videos',    'cost' => 1000, 'type' => 'video'],
    ['icon' => 'fa-clapperboard', 'label' => 'Pro Videos',         'cost' => 1500, 'type' => 'video'],
    ['icon' => 'fa-clapperboard', 'label' => 'Premium Videos',     'cost' => 2200, 'type' => 'video'],
];

// ── Handle PayPal POST redirect ───────────────────────────────────────────────
if (isset($_POST['mplan']) && qa_check_form_security_code('paypal', qa_post_text('code'))) {
    $enableSandbox = qa_opt('paypal_sandbox');
    $paypalConfig  = [
        'email'      => qa_opt('paypal_email'),
        'return_url' => qa_path_absolute('membership', ['pay' => 'succes']),
        'cancel_url' => qa_path_absolute('membership', ['pay' => 'error']),
        'notify_url' => qa_opt('site_url') . 'king-include/paypal.php',
    ];
    $paypalUrl = $enableSandbox
        ? 'https://www.sandbox.paypal.com/cgi-bin/webscr'
        : 'https://www.paypal.com/cgi-bin/webscr';

    $data = [];
    foreach ($_POST as $k => $v) { $data[$k] = stripslashes($v); }
    $data['business']      = $paypalConfig['email'];
    $data['return']        = $paypalConfig['return_url'];
    $data['cancel_return'] = $paypalConfig['cancel_url'];
    $data['notify_url']    = $paypalConfig['notify_url'];
    $data['item_name']     = 'Flex Plan — Ebonix AI';
    $data['amount']        = number_format($flex_price_raw, 2);
    $data['currency_code'] = qa_opt('currency') ?: 'USD';
    $data['item_number']   = 1;
    $data['custom']        = $userid ?? '';
    $data['cmd']           = '_xclick';
    $data['no_note']       = '1';
    header('location:' . $paypalUrl . '?' . http_build_query($data));
    exit();
}

// ── Build page ────────────────────────────────────────────────────────────────
$qa_content                 = qa_content_prepare();
$qa_content['title']        = 'Choose Your Plan — Ebonix';
$qa_content['script_src'][] = 'https://js.stripe.com/v3/';

$out = '';

// ── Status banner ─────────────────────────────────────────────────────────────
$pay = qa_get('pay');
if ($pay === 'succes') {
    $out .= '<div class="mem-banner mem-banner-success"><i class="fa-regular fa-circle-check"></i> '
          . 'Payment successful! Your plan has been activated.</div>';
} elseif ($pay === 'error') {
    $out .= '<div class="mem-banner mem-banner-error"><i class="fa-regular fa-circle-xmark"></i> '
          . 'Payment was cancelled or failed. Please try again.</div>';
} elseif (qa_get('topup') === 'success') {
    $out .= '<div class="mem-banner mem-banner-success"><i class="fa-regular fa-circle-check"></i> '
          . 'Top-up purchased! Your coins have been added.</div>';
}

$out .= '<div class="ebx-pricing-wrap">';

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION 1 — PLAN CARDS
// ═══════════════════════════════════════════════════════════════════════════════
$out .= '<div class="ebx-pricing-header">';
$out .= '<h1 class="ebx-pricing-title">Create With Ebonix</h1>';
$out .= '<p class="ebx-pricing-sub">AI photos, videos, and your AI Twin — powered by coins.</p>';
$out .= '</div>';

$out .= '<div class="ebx-plan-grid">';

// ── FREE PLAN ─────────────────────────────────────────────────────────────────
$out .= '<div class="ebx-plan-card ebx-plan-card-free">';
$out .= '<div class="ebx-plan-badge-wrap">';
$out .= '<h2 class="ebx-plan-name">FREE</h2>';
$out .= '<div class="ebx-plan-price"><span class="ebx-plan-price-amount">$0</span></div>';
$out .= '<p class="ebx-plan-tagline">Try it out.</p>';
$out .= '</div>';
$out .= '<ul class="ebx-plan-features">';
$out .= '<li class="ebx-feat-on"><i class="fa-solid fa-circle-check"></i> Starting coins to explore</li>';
$out .= '<li class="ebx-feat-on"><i class="fa-solid fa-circle-check"></i> AI Image — standard quality</li>';
$out .= '<li class="ebx-feat-off"><i class="fa-solid fa-circle-xmark"></i> AI Twin</li>';
$out .= '<li class="ebx-feat-off"><i class="fa-solid fa-circle-xmark"></i> AI Video</li>';
$out .= '<li class="ebx-feat-off"><i class="fa-solid fa-circle-xmark"></i> Save your looks</li>';
$out .= '<li class="ebx-feat-off"><i class="fa-solid fa-circle-xmark"></i> Premium quality tiers</li>';
$out .= '</ul>';
$out .= '<div class="ebx-plan-cta">';
if (!$userid) {
    $out .= '<a href="' . qa_path_html('register') . '" class="ebx-cta-btn ebx-cta-free">Get Started Free</a>';
} elseif ($user_plan_id === 0) {
    $out .= '<button class="ebx-cta-btn ebx-cta-current" disabled>Current Free Plan</button>';
} else {
    $out .= '<a href="' . qa_path_html('myplan') . '" class="ebx-cta-btn ebx-cta-free">View My Plan</a>';
}
$out .= '</div>';
$out .= '</div>'; // .ebx-plan-card-free

// ── PAID PLANS (from DB) ──────────────────────────────────────────────────────
foreach ($db_plans as $idx => $plan) {
    $plan_id        = (int)$plan['id'];
    $plan_price_raw = (float)$plan['price'];
    $plan_price_str = '$' . number_format($plan_price_raw, 0);
    $plan_mcoins    = number_format((int)$plan['monthly_coins']);
    $is_current     = ($userid && $user_plan_id === $plan_id);
    $is_popular     = ($idx === 0); // first plan = most popular

    $plan_feats = [];
    if (!empty($plan['features'])) {
        $plan_feats = json_decode($plan['features'], true) ?: [];
    }

    $out .= '<div class="ebx-plan-card ebx-plan-card-highlight">';
    if ($is_popular) {
        $out .= '<div class="ebx-plan-popular">Most Popular</div>';
    }
    $out .= '<div class="ebx-plan-badge-wrap">';
    $out .= '<h2 class="ebx-plan-name">' . qa_html(strtoupper($plan['name'])) . '</h2>';
    $out .= '<div class="ebx-plan-price">';
    $out .= '<span class="ebx-plan-price-amount">' . $plan_price_str . '</span>';
    $out .= '<span class="ebx-plan-price-period">/month</span>';
    $out .= '</div>';
    $out .= '<p class="ebx-plan-tagline">You, but elevated.</p>';
    $out .= '</div>';

    $out .= '<ul class="ebx-plan-features">';
    if (!empty($plan_feats)) {
        foreach ($plan_feats as $feat) {
            $out .= '<li class="ebx-feat-on"><i class="fa-solid fa-circle-check"></i> ' . qa_html($feat) . '</li>';
        }
    } else {
        $out .= '<li class="ebx-feat-on"><i class="fa-solid fa-circle-check"></i> <strong>' . $plan_mcoins . ' Coins</strong> every month</li>';
        $out .= '<li class="ebx-feat-on"><i class="fa-solid fa-circle-check"></i> AI Image — all quality tiers</li>';
        $out .= '<li class="ebx-feat-on"><i class="fa-solid fa-circle-check"></i> AI Video — all quality tiers</li>';
        $out .= '<li class="ebx-feat-on"><i class="fa-solid fa-circle-check"></i> AI Twin access</li>';
        $out .= '<li class="ebx-feat-on"><i class="fa-solid fa-circle-check"></i> Save and reuse your looks</li>';
        $out .= '<li class="ebx-feat-on"><i class="fa-solid fa-circle-check"></i> Top up coins anytime</li>';
    }
    $out .= '</ul>';

    $out .= '<div class="ebx-plan-cta">';
    if ($is_current) {
        $out .= '<button class="ebx-cta-btn ebx-cta-current" disabled>Current Plan</button>';
    } elseif (!$userid) {
        $out .= '<a href="' . qa_path_html('login') . '" class="ebx-cta-btn ebx-cta-primary">Log in to Subscribe</a>';
    } else {
        if ($stripe_enabled) {
            $card_label = '<i class="fa-regular fa-credit-card"></i> '
                        . ($auto_stripe ? 'Subscribe — ' . $plan_price_str . '/mo' : 'Pay with Card — ' . $plan_price_str);
            $out .= '<button class="ebx-cta-btn ebx-cta-primary ebx-stripe-plan-btn"'
                  . ' onclick="ebxStripeCheckout(' . $plan_id . ', this)">'
                  . $card_label . '</button>';
        }
        if ($cashapp_enabled) {
            $out .= '<button class="ebx-cta-btn ebx-cta-cashapp ebx-stripe-plan-btn"'
                  . ' onclick="ebxStripeCheckout(' . $plan_id . ', this)" style="margin-top:8px">'
                  . '<i class="fa-solid fa-dollar-sign"></i> Pay with Cash App</button>';
        }
        if ($paypal_enabled) {
            $ppcode = qa_get_form_security_code('paypal');
            $out .= '<form method="post" action="" style="margin-top:8px">';
            $out .= '<input type="hidden" name="mplan"  value="' . $plan_id . '">';
            $out .= '<input type="hidden" name="userid" value="' . qa_html((string)$userid) . '">';
            $out .= '<input type="hidden" name="code"   value="' . qa_html($ppcode) . '">';
            $out .= '<button type="submit" class="ebx-cta-btn ebx-cta-paypal">'
                  . '<i class="fa-brands fa-paypal"></i> Pay with PayPal</button>';
            $out .= '</form>';
        }
        if (!$stripe_enabled && !$paypal_enabled) {
            $out .= '<span class="ebx-cta-contact">No payment gateway configured.</span>';
        }
    }
    $out .= '</div>';
    $out .= '</div>'; // .ebx-plan-card
}

$out .= '</div>'; // .ebx-plan-grid

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION 2 — WHAT CAN I MAKE WITH X COINS?
// ═══════════════════════════════════════════════════════════════════════════════
$out .= '<div class="ebx-coin-value-section">';
$out .= '<h2 class="ebx-section-title">What can I create with ' . $monthly_coins . ' coins?</h2>';
$out .= '<p class="ebx-section-sub">Mix and match freely — no category quotas.</p>';
$out .= '<div class="ebx-coin-value-grid">';

foreach ($value_items as $item) {
    $count = (int) floor($base_coins / $item['cost']);
    $bg    = $item['type'] === 'video' ? 'ebx-cv-video' : 'ebx-cv-photo';
    $out .= '<div class="ebx-coin-value-card ' . $bg . '">';
    $out .= '<div class="ebx-cv-icon"><i class="fa-solid ' . $item['icon'] . '"></i></div>';
    $out .= '<div class="ebx-cv-count">' . number_format($count) . '</div>';
    $out .= '<div class="ebx-cv-label">' . qa_html($item['label']) . '</div>';
    $out .= '<div class="ebx-cv-cost">' . number_format($item['cost']) . ' coins each</div>';
    $out .= '</div>';
}

$out .= '</div>'; // .ebx-coin-value-grid
$out .= '</div>'; // .ebx-coin-value-section

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION 3 — COIN TOP-UP PACKS
// ═══════════════════════════════════════════════════════════════════════════════
$out .= '<div class="ebx-topup-section" id="topup">';
$out .= '<h2 class="ebx-section-title">Top Up Your Coins</h2>';
$out .= '<p class="ebx-section-sub">Buy extra coins anytime. Top-up coins never expire and stack on top of your monthly allowance.</p>';

$standard_packs = array_slice($topup_packs, 0, 6);
$heavy_packs    = array_slice($topup_packs, 6);

// Standard packs
$out .= '<div class="ebx-topup-label">STANDARD PACKS</div>';
$out .= '<div class="ebx-topup-grid">';
foreach ($standard_packs as $pack) {
    $out .= '<div class="ebx-topup-card">';
    $out .= '<div class="ebx-topup-coins">' . qa_html($pack['coins']) . '</div>';
    $out .= '<div class="ebx-topup-price">$' . number_format($pack['price']) . '</div>';
    if (!empty($pack['best_for'])) {
        $out .= '<div class="ebx-topup-for">' . qa_html($pack['best_for']) . '</div>';
    }
    if (!$userid) {
        $out .= '<a href="' . qa_path_html('login') . '" class="ebx-cta-btn ebx-cta-topup">Log in to buy</a>';
    } elseif ($stripe_enabled) {
        $out .= '<button class="ebx-cta-btn ebx-cta-topup"'
              . ' data-pack="' . qa_html($pack['pack_name']) . '" onclick="ebxBuyTopup(this.getAttribute(\'data-pack\'), this)">Buy Now</button>';
    } else {
        $out .= '<span class="ebx-cta-contact">Stripe not configured</span>';
    }
    $out .= '</div>';
}
$out .= '</div>'; // standard grid

// Heavy-user packs
$out .= '<div class="ebx-topup-label" style="margin-top:32px">HEAVY-USER PACKS</div>';
$out .= '<div class="ebx-topup-grid">';
foreach ($heavy_packs as $pack) {
    $out .= '<div class="ebx-topup-card ebx-topup-card-heavy">';
    $out .= '<div class="ebx-topup-coins">' . qa_html($pack['coins']) . '</div>';
    $out .= '<div class="ebx-topup-price">$' . number_format($pack['price']) . '</div>';
    if (!empty($pack['best_for'])) {
        $out .= '<div class="ebx-topup-for">' . qa_html($pack['best_for']) . '</div>';
    }
    if (!$userid) {
        $out .= '<a href="' . qa_path_html('login') . '" class="ebx-cta-btn ebx-cta-topup">Log in to buy</a>';
    } elseif ($stripe_enabled) {
        $out .= '<button class="ebx-cta-btn ebx-cta-topup"'
              . ' data-pack="' . qa_html($pack['pack_name']) . '" onclick="ebxBuyTopup(this.getAttribute(\'data-pack\'), this)">Buy Now</button>';
    } else {
        $out .= '<span class="ebx-cta-contact">Stripe not configured</span>';
    }
    $out .= '</div>';
}
$out .= '</div>'; // heavy grid

$out .= '</div>'; // .ebx-topup-section
$out .= '</div>'; // .ebx-pricing-wrap

// ═══════════════════════════════════════════════════════════════════════════════
// JAVASCRIPT — only injected when Stripe is enabled
// ═══════════════════════════════════════════════════════════════════════════════
if ($stripe_enabled) {
    $stripe_pkey = qa_opt('stripe_pkey');
    $ajax_url    = rtrim((string)qa_opt('site_url'), '/') . '/king-include/king-ajax.php';
    $out .= '<script>';
    $out .= 'var ebxStripePkey = ' . json_encode($stripe_pkey) . ';';
    $out .= 'var ebxCreateUrl  = ' . json_encode($create_url) . ';';
    $out .= 'var ebxAjaxUrl    = ' . json_encode($ajax_url) . ';';
    $out .= <<<'JSCODE'

/* ── Subscribe to a paid plan ───────────────────────────────────────────── */
function ebxStripeCheckout(planId, btn) {
    document.querySelectorAll('.ebx-stripe-plan-btn').forEach(function(b) {
        b.disabled     = true;
        b.dataset.orig = b.innerHTML;
        b.innerHTML    = 'Loading…';
    });

    fetch(ebxCreateUrl, {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({ request_type: 'create_checkout_session', price: planId }),
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.url) {
            window.location.href = data.url;
        } else {
            alert('Payment error: ' + (data.error || 'Unknown error. Please try again.'));
            document.querySelectorAll('.ebx-stripe-plan-btn').forEach(function(b) {
                b.disabled  = false;
                b.innerHTML = b.dataset.orig || 'Try Again';
            });
        }
    })
    .catch(function() {
        alert('Could not reach the payment server. Please try again.');
        document.querySelectorAll('.ebx-stripe-plan-btn').forEach(function(b) {
            b.disabled  = false;
            b.innerHTML = b.dataset.orig || 'Try Again';
        });
    });
}

/* ── Buy a coin top-up pack ─────────────────────────────────────────────── */
function ebxBuyTopup(packName, btn) {
    if (btn) {
        btn.disabled     = true;
        btn.dataset.orig = btn.innerHTML;
        btn.innerHTML    = 'Loading…';
    }

    var fd = new FormData();
    fd.append('qa_operation', 'topupcoins');
    fd.append('qa_request',   'membership');
    fd.append('qa_root',      window.location.origin + '/');
    fd.append('pack_name',    packName);

    fetch(ebxAjaxUrl, { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.status === 'ok' && data.checkout_url) {
            window.location.href = data.checkout_url;
        } else {
            alert('Payment error: ' + (data.msg || 'Unknown error. Please try again.'));
            if (btn) { btn.disabled = false; btn.innerHTML = btn.dataset.orig || 'Buy Now'; }
        }
    })
    .catch(function() {
        alert('Could not reach the payment server. Please try again.');
        if (btn) { btn.disabled = false; btn.innerHTML = btn.dataset.orig || 'Buy Now'; }
    });
}

JSCODE;
    $out .= '</script>';
}

$qa_content['custom'] = $out;
return $qa_content;