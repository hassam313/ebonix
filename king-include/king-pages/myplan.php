<?php
/*
 * File: king-include/king-pages/myplan.php
 *
 * My Plan page — current plan status, usage stats, features, billing history,
 * and upgrade/cancel actions for the logged-in user.
 */

if (!defined('QA_VERSION')) { header('Location: ../'); exit; }


require_once QA_INCLUDE_DIR . 'king-app/users.php';
require_once QA_INCLUDE_DIR . 'king-app/limits.php';
require_once QA_INCLUDE_DIR . 'king-db/selects.php';
require_once QA_INCLUDE_DIR . 'king-db/metas.php';
require_once QA_INCLUDE_DIR . 'king-app/coins.php';

$userid = qa_get_logged_in_userid();

if (!$userid) {
    $qa_content = qa_content_prepare();
    $qa_content['custom'] = '<div class="nopost"><i class="fa-solid fa-circle-user fa-4x"></i>'
        . qa_insert_login_links(qa_lang_html('question/ask_must_login'), qa_request()) . '</div>';
    return $qa_content;
}

$qa_content          = qa_content_prepare();
$qa_content['title'] = 'My Plan';

// ── Ensure king_payments table has all required columns ──────────────────────
try {
    qa_db_query_sub(
        'CREATE TABLE IF NOT EXISTS `^king_payments` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `user_id` int(11) NOT NULL,
          `plan` tinyint(2) NOT NULL DEFAULT 0,
          `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
          `currency` varchar(10) DEFAULT \'USD\',
          `gateway` varchar(20) NOT NULL,
          `transaction_id` varchar(255) DEFAULT NULL,
          `status` varchar(20) DEFAULT \'completed\',
          `coins_added` int(11) DEFAULT 0,
          `topup_pack` varchar(50) DEFAULT \'\',
          `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `user_id` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
} catch (Exception $e) { /* table exists */ }
// Add missing columns to pre-existing tables
foreach (['coins_added INT DEFAULT 0', 'topup_pack VARCHAR(50) DEFAULT \'\''] as $_col_def) {
    qa_db_query_sub('ALTER TABLE ^king_payments ADD COLUMN IF NOT EXISTS ' . $_col_def);
}

// ── Stripe top-up verification (fallback for when webhook hasn't fired) ───────
$topup_notice = '';
$stripe_session_id = trim((string)($_GET['session_id'] ?? ''));
if (!empty($stripe_session_id) && ($_GET['topup'] ?? '') === 'success' && qa_opt('enable_stripe') && qa_opt('stripe_skey')) {
    try {
        require_once QA_INCLUDE_DIR . 'stripe/init.php';
        \Stripe\Stripe::setApiKey(qa_opt('stripe_skey'));
        $sess = \Stripe\Checkout\Session::retrieve($stripe_session_id);

        // Only process paid sessions for this user that are coin top-ups
        if (
            $sess &&
            $sess->payment_status === 'paid' &&
            isset($sess->metadata['type']) && $sess->metadata['type'] === 'coin_topup' &&
            (int)($sess->metadata['user_id'] ?? 0) === (int)$userid
        ) {
            // Check we haven't already processed this session
            $already = qa_db_read_one_value(
                qa_db_query_sub('SELECT COUNT(*) FROM ^king_payments WHERE transaction_id=$', $sess->id),
                true
            );
            if (!$already) {
                $coins     = (int)($sess->metadata['coins'] ?? 0);
                $pack_name = $sess->metadata['pack_name'] ?? '';
                if ($coins > 0) {
                    ebonix_grant_topup_coins((int)$userid, $coins, $pack_name);
                    $amount_paid = ($sess->amount_total ?? 0) / 100;
                    try {
                        qa_db_query_sub(
                            'INSERT INTO ^king_payments (user_id, plan, amount, currency, gateway, transaction_id, status, coins_added, topup_pack, created_at)
                             VALUES (#, #, #, $, $, $, $, #, $, NOW())',
                            (int)$userid, 0, (float)$amount_paid, 'USD', 'stripe',
                            $sess->id, 'completed', $coins, $pack_name
                        );
                    } catch (Exception $e) { /* ignore duplicate */ }
                    $topup_notice = '<div class="myplan-success-banner"><i class="fa-solid fa-coins"></i> ' . number_format($coins) . ' coins added to your balance!</div>';
                }
            } else {
                $topup_notice = '<div class="myplan-success-banner"><i class="fa-solid fa-check"></i> Top-up already applied. Your coins are in your balance.</div>';
            }
        }
    } catch (Exception $e) {
        error_log('myplan topup verify error: ' . $e->getMessage());
    }
}

// ── Auto-downgrade expired plans ─────────────────────────────────────────────
$expiry_date = qa_db_usermeta_get($userid, 'membership');
if ($expiry_date && date('Y-m-d') > $expiry_date) {
    qa_db_usermeta_set($userid, 'membership_plan',   0);
    qa_db_usermeta_set($userid, 'membership',        '');
    qa_db_usermeta_set($userid, 'membership_expiry', 0);
    $expiry_date = '';
}

// ── Pull plan and usage data ─────────────────────────────────────────────────
$mp = ebonix_get_user_plan($userid); // 0=Free, ≥1=paid plan (auto-downgrades expired)

// ── Plan metadata from DB ─────────────────────────────────────────────────────
$db_plan   = ($mp > 0) ? ebonix_get_plan($mp) : null;
$plan_name = $db_plan ? $db_plan['name'] : 'Free Plan';

// Monthly coins for this plan
$monthly_allot_plan = $db_plan ? (int)$db_plan['monthly_coins'] : 0;

// Features from DB (JSON array), with sensible free-plan fallback
if ($db_plan && !empty($db_plan['features'])) {
    $features = json_decode($db_plan['features'], true) ?: [];
} elseif ($mp > 0) {
    // Paid plan but no features set in DB — use generic defaults
    $features = [
        number_format($monthly_allot_plan) . ' Coins every month',
        'AI Image — all quality tiers',
        'AI Video — all quality tiers',
        'AI Twin access',
        'Save and reuse your looks',
        'Top up coins anytime',
    ];
} else {
    $features = [
        'Starting coins to explore',
        'AI Image generation (standard quality)',
        'Upgrade to unlock AI Twin, AI Video & premium models',
    ];
}

// ── Plan badges ───────────────────────────────────────────────────────────────
$badge_icon  = ($mp > 0) ? 'fa-gem' : 'fa-user';
$badge_class = ($mp > 0) ? 'myplan-badge myplan-badge-paid' : 'myplan-badge myplan-badge-free';

// ── Expiry display ────────────────────────────────────────────────────────────
$expiry_label = '';
if ($expiry_date) {
    $expiry_label = 'Renews: ' . date('F j, Y', strtotime($expiry_date));
} elseif ($mp) {
    $expiry_label = 'No active subscription';
}

// ── Stripe / PayPal subscription IDs ─────────────────────────────────────────
$stripe_sub_id  = qa_db_usermeta_get($userid, 'stripe_subscription_id');
$paypal_sub_id  = qa_db_usermeta_get($userid, 'paypal_subscription_id');
$has_active_sub = !empty($stripe_sub_id) || !empty($paypal_sub_id);

// ── Billing history ───────────────────────────────────────────────────────────
$billing_history = array();
try {
    // Table already ensured at top of page — just query directly
    $dummy = null;
    $billing_history = qa_db_read_all_assoc(
        qa_db_query_sub(
            'SELECT plan, amount, currency, gateway, transaction_id, status, created_at FROM ^king_payments WHERE user_id=# ORDER BY created_at DESC LIMIT 5',
            (int)$userid
        )
    );
} catch (Exception $e) {
    $billing_history = array();
}

// ── Recent AI image generations (from qa_posts) ───────────────────────────────
// Uses king_ai_posts() which correctly reads posts + postmeta + blobs
$recent_gen_html = king_ai_posts($userid, 'aimg');

// ── AJAX URLs ─────────────────────────────────────────────────────────────────
$site_url_trim = rtrim((string)qa_opt('site_url'), '/');
$ajax_url      = $site_url_trim . '/king-include/king-ajax.php';
$qa_root       = $site_url_trim . '/';

// ═════════════════════════════════════════════════════════════════════════════
// BUILD PAGE HTML
// ═════════════════════════════════════════════════════════════════════════════
$cont = '';

if (!empty($topup_notice)) {
    $cont .= $topup_notice;
}

// ── SECTION 1 — Current Plan Status ──────────────────────────────────────────
$cont .= '<div class="myplan-card">';
$cont .= '<div class="myplan-card-header"><i class="fa-solid fa-id-card"></i> Current Plan</div>';
$cont .= '<div class="' . $badge_class . '">';
$cont .= '<div class="myplan-badge-icon"><i class="fa-solid ' . $badge_icon . '"></i></div>';
$cont .= '<div class="myplan-badge-info">';
$cont .= '<h2 class="myplan-plan-name">' . qa_html($plan_name) . '</h2>';
$cont .= '<p class="myplan-plan-status">' . (($mp > 0) ? qa_html($expiry_label ?: 'Active membership') : 'Free tier — upgrade to unlock more') . '</p>';
$cont .= '</div>';
$cont .= '</div>';

// Subscription management / upgrade buttons
$cont .= '<div class="myplan-action-row">';
if (($mp > 0) && $has_active_sub && qa_opt('stripe_auto_renewal') && !empty($stripe_sub_id)) {
    // Stripe customer portal link
    $cont .= '<a href="' . qa_path_html('membership') . '" class="myplan-manage-btn"><i class="fa-solid fa-gears"></i> Manage Subscription</a>';
    $cont .= '<button type="button" class="myplan-cancel-btn" onclick="myplanCancelConfirm()"><i class="fa-solid fa-ban"></i> Cancel Subscription</button>';
} elseif ($mp > 0) {
    $cont .= '<a href="' . qa_path_html('membership') . '" class="myplan-upgrade-btn-small"><i class="fa-solid fa-arrow-up"></i> Change Plan</a>';
} else {
    $cont .= '<a href="' . qa_path_html('membership') . '" class="myplan-upgrade-btn-small"><i class="fa-solid fa-rocket"></i> Upgrade Now</a>';
}
$cont .= '</div>';
$cont .= '</div>';

// Ensure coin log table exists before any coin operation (ebonix_log_coin uses die() not exceptions)
ebonix_ensure_coin_log_table();

// ── SECTION 2 — Coin Balance & Usage ─────────────────────────────────────────
ebonix_ensure_initialized($userid);
$coin_data  = ebonix_get_coins_breakdown($userid);
$coin_total = $coin_data['total'];
$coin_sub   = $coin_data['from_sub'];
$coin_topup = $coin_data['from_topup'];
// Use system option as authoritative monthly allotment (DB plan may differ per plan tier)
$monthly_allot = ($monthly_allot_plan > 0) ? $monthly_allot_plan : (int)(qa_opt('flex_plan_monthly_coins') ?: 10000);
$sub_used      = max(0, $monthly_allot - $coin_sub);
// Bar shows REMAINING coins (full = all coins left, empty = all used)
$sub_remaining_perc = $monthly_allot > 0 ? min(100, (int)round($coin_sub * 100 / $monthly_allot)) : 0;
$coin_bar_col  = $sub_remaining_perc <= 10 ? '#ef4444' : ($sub_remaining_perc <= 30 ? '#f59e0b' : '#7c3aed');

// Usage stats from coin_log (spend entries)
// Note: ebonix_log_coin stores spend amounts as negative (-$amount), so we use ABS
$usage_stats = ['photo' => 0, 'video' => 0, 'twin' => 0, 'other' => 0];
$usage_log = qa_db_read_all_assoc(
    qa_db_query_sub(
        'SELECT reason, ABS(SUM(amount)) as total_coins, COUNT(*) as cnt FROM ^king_coin_log WHERE user_id=# AND type=$ GROUP BY reason',
        (int)$userid, 'spend'
    )
);
$total_spent = 0;
$gen_count   = 0;
foreach ($usage_log as $row) {
    $amt = abs((int)$row['total_coins']);
    $total_spent += $amt;
    $gen_count   += (int)$row['cnt'];
    $r = strtolower((string)$row['reason']);
    if (strpos($r, 'video') !== false)                                          $usage_stats['video'] += $amt;
    elseif (strpos($r, 'twin') !== false)                                       $usage_stats['twin']  += $amt;
    elseif (strpos($r, 'photo') !== false || strpos($r, 'image') !== false)    $usage_stats['photo'] += $amt;
    else                                                                         $usage_stats['other'] += $amt;
}

$cont .= '<div class="myplan-card" id="myplan-coin-card">';
$cont .= '<div class="myplan-card-header">';
$cont .= '<i class="fa-solid fa-coins"></i> Coins &amp; Usage';
$cont .= ' <button type="button" class="myplan-refresh-btn" id="myplan-coin-refresh-btn" onclick="myplanRefreshCoins(this)" title="Refresh balance"><i class="fa-solid fa-rotate-right"></i></button>';
$cont .= '</div>';
$cont .= '<div id="myplan-coin-block">';

// ── Big total ───────────────────────────────────────────────────────────────
$cont .= '<div class="myplan-coin-total" id="myplan-coin-total">';
$cont .= '<span class="myplan-coin-number" id="myplan-coin-number-val"><i class="fa-solid fa-coins" style="font-size:.75em;margin-right:6px;color:#7c3aed"></i>' . number_format($coin_total) . '</span>';
$cont .= '<span class="myplan-coin-unit">coins remaining</span>';
$cont .= '</div>';

// ── Subscription coins bar ──────────────────────────────────────────────────
if ($mp >= 1 && $monthly_allot > 0) {
    $renew_label = $expiry_date ? date('M j, Y', strtotime($expiry_date)) : '';
    $cont .= '<div class="myplan-usage-section">';
    $cont .= '<div class="myplan-usage-row-top">';
    $cont .= '<span class="myplan-usage-label-txt"><i class="fa-solid fa-rotate"></i> Monthly subscription coins</span>';
    if ($renew_label) $cont .= '<span class="myplan-usage-reset">Resets ' . qa_html($renew_label) . '</span>';
    $cont .= '</div>';
    // Bar width = remaining% (full bar = coins untouched, empty = all used)
    $cont .= '<div class="myplan-progress-bar"><div class="myplan-progress-fill" id="myplan-coin-fill" style="width:' . $sub_remaining_perc . '%; background:' . $coin_bar_col . '"></div></div>';
    $cont .= '<div class="myplan-usage-stats-row">';
    $cont .= '<span><strong id="myplan-sub-coins">' . number_format($coin_sub) . '</strong> remaining</span>';
    $cont .= '<span>' . number_format($sub_used) . ' used of ' . number_format($monthly_allot) . '</span>';
    $cont .= '</div>';
    $cont .= '</div>';
}

// ── Top-up coins ────────────────────────────────────────────────────────────
$cont .= '<div class="myplan-topup-stat">';
$cont .= '<i class="fa-solid fa-bolt"></i>';
$cont .= '<span><strong id="myplan-topup-coins">' . number_format($coin_topup) . ' top-up coins</strong> — never expire</span>';
$cont .= '</div>';

$cont .= '</div>'; // #myplan-coin-block

// ── Usage breakdown ─────────────────────────────────────────────────────────
$cont .= '<div class="myplan-usage-breakdown">';
$cont .= '<div class="myplan-usage-breakdown-title">All-time coin usage — ' . number_format($gen_count) . ' generation' . ($gen_count !== 1 ? 's' : '') . ' &middot; ' . number_format($total_spent) . ' coins spent</div>';
$cont .= '<div class="myplan-usage-cats">';
$cats = [
    ['icon' => 'fa-image',       'label' => 'AI Photos', 'key' => 'photo', 'color' => '#7c3aed'],
    ['icon' => 'fa-film',        'label' => 'AI Videos', 'key' => 'video', 'color' => '#0ea5e9'],
    ['icon' => 'fa-user-pen',    'label' => 'AI Twin',   'key' => 'twin',  'color' => '#d946ef'],
    ['icon' => 'fa-circle-nodes','label' => 'Other',     'key' => 'other', 'color' => '#10b981'],
];
foreach ($cats as $cat) {
    $spent = $usage_stats[$cat['key']]; // already abs() from the loop above
    $perc  = ($total_spent > 0) ? min(100, (int)round($spent * 100 / $total_spent)) : 0;
    $cont .= '<div class="myplan-usage-cat">';
    $cont .= '<div class="myplan-usage-cat-head">';
    $cont .= '<i class="fa-solid ' . $cat['icon'] . '" style="color:' . $cat['color'] . '"></i> ' . $cat['label'];
    $cont .= ' <span class="myplan-usage-cat-coins">' . ($spent > 0 ? number_format($spent) . ' coins' : '—') . '</span>';
    $cont .= '</div>';
    $cont .= '<div class="myplan-usage-cat-bar"><div class="myplan-usage-cat-fill" style="width:' . $perc . '%;background:' . $cat['color'] . '"></div></div>';
    $cont .= '</div>';
}
$cont .= '</div>'; // .myplan-usage-cats
$cont .= '</div>'; // .myplan-usage-breakdown

$cont .= '<div class="myplan-action-row" style="margin-top:16px">';
$cont .= '<a href="' . qa_path_html('membership') . '#topup" class="myplan-upgrade-btn-small"><i class="fa-solid fa-plus"></i> Top Up Coins</a>';
$cont .= '</div>';
$cont .= '</div>'; // .myplan-card

// ── SECTION 3 — Plan Features ─────────────────────────────────────────────────
$cont .= '<div class="myplan-card">';
$cont .= '<div class="myplan-card-header"><i class="fa-solid fa-list-check"></i> What\'s Included</div>';
$cont .= '<ul class="myplan-features-list">';
foreach ($features as $feature) {
    $cont .= '<li><i class="fa-solid fa-circle-check"></i> ' . qa_html($feature) . '</li>';
}
$cont .= '</ul>';
$cont .= '</div>';

// ── SECTION 3.5 — Coin History ───────────────────────────────────────────────
// Table already ensured above via ebonix_ensure_coin_log_table()
$coin_history = qa_db_read_all_assoc(
    qa_db_query_sub(
        'SELECT type, amount, balance_after, reason, model_used, created_at FROM ^king_coin_log WHERE user_id=# ORDER BY created_at DESC LIMIT 20',
        (int)$userid
    )
);

$cont .= '<div class="myplan-card">';
$cont .= '<div class="myplan-card-header"><i class="fa-solid fa-clock-rotate-left"></i> Coin History</div>';
if (!empty($coin_history)) {
    $cont .= '<table class="myplan-history-table">';
    $cont .= '<thead><tr><th>Date</th><th>Type</th><th>Amount</th><th>Reason</th><th>Balance</th></tr></thead>';
    $cont .= '<tbody>';
    foreach ($coin_history as $entry) {
        $type_badge_map = [
            'earn'   => 'myplan-status-completed',
            'spend'  => 'myplan-status-spend',
            'topup'  => 'myplan-status-topup',
            'refund' => 'myplan-status-refund',
        ];
        $badge_cls   = $type_badge_map[$entry['type']] ?? 'myplan-status-completed';
        $amount_int  = (int)$entry['amount'];
        $amount_disp = ($amount_int >= 0 ? '+' : '') . number_format($amount_int);
        $amt_color   = $amount_int >= 0 ? '#22c55e' : '#ef4444';
        $reason_disp = ucwords(str_replace('_', ' ', $entry['reason'] ?? ''));
        $date_str    = date('M j, Y H:i', strtotime($entry['created_at']));
        $cont .= '<tr>';
        $cont .= '<td class="myplan-history-date">' . qa_html($date_str) . '</td>';
        $cont .= '<td><span class="' . $badge_cls . '">' . qa_html(ucfirst($entry['type'])) . '</span></td>';
        $cont .= '<td style="color:' . $amt_color . ';font-weight:600">' . qa_html($amount_disp) . '</td>';
        $cont .= '<td>' . qa_html($reason_disp) . '</td>';
        $cont .= '<td>' . number_format((int)$entry['balance_after']) . '</td>';
        $cont .= '</tr>';
    }
    $cont .= '</tbody></table>';
} else {
    $cont .= '<p class="myplan-no-billing">No coin activity yet.</p>';
}
$cont .= '</div>';

// ── SECTION 4 — Billing History ───────────────────────────────────────────────
$cont .= '<div class="myplan-card">';
$cont .= '<div class="myplan-card-header"><i class="fa-solid fa-receipt"></i> Billing History</div>';
if (!empty($billing_history)) {
    $cont .= '<table class="myplan-history-table">';
    $cont .= '<thead><tr><th>Date</th><th>Plan</th><th>Amount</th><th>Gateway</th><th>Status</th></tr></thead>';
    $cont .= '<tbody>';
    foreach ($billing_history as $payment) {
        $prow     = ebonix_get_plan((int)$payment['plan']);
        $plan_lbl = $prow ? $prow['name'] : ($payment['plan'] ? ('Plan ' . $payment['plan']) : 'Free');
        $date_str = date('M j, Y', strtotime($payment['created_at']));
        $amount   = '$' . number_format((float)$payment['amount'], 2);
        $gw_label = ucfirst($payment['gateway']);
        $status   = ucfirst($payment['status'] ?? 'completed');
        $cont .= '<tr>';
        $cont .= '<td class="myplan-history-date">' . qa_html($date_str) . '</td>';
        $cont .= '<td>' . qa_html($plan_lbl) . '</td>';
        $cont .= '<td>' . qa_html($amount) . '</td>';
        $cont .= '<td>' . qa_html($gw_label) . '</td>';
        $cont .= '<td><span class="myplan-status-' . qa_html(strtolower($payment['status'] ?? 'completed')) . '">' . qa_html($status) . '</span></td>';
        $cont .= '</tr>';
    }
    $cont .= '</tbody></table>';
} else {
    $cont .= '<p class="myplan-no-billing">No billing history yet.</p>';
}
$cont .= '</div>';

// ── SECTION 5 — Generation History ───────────────────────────────────────────
$cont .= '<div class="myplan-card">';
$cont .= '<div class="myplan-card-header"><i class="fa-solid fa-clock-rotate-left"></i> Recent Generations</div>';
// king_ai_posts renders a <div class="king-aiposts">...</div> with all images
// It returns empty-state text inside the wrapper when there are no posts
$cont .= $recent_gen_html;
$cont .= '</div>';

// ── Upgrade CTA (not shown on Flex plan) ──────────────────────────────────────
if (!$mp || (int)$mp < 1) {
    $cont .= '<div class="myplan-card myplan-upgrade-card">';
    $cont .= '<i class="fa-solid fa-rocket myplan-upgrade-icon"></i>';
    $cont .= '<h3>Unlock More With Flex</h3>';
    $cont .= '<p>More AI generations, faster speeds, and higher quality results.</p>';
    $cont .= '<a href="' . qa_path_html('membership') . '" class="myplan-upgrade-btn"><i class="fa-solid fa-arrow-up"></i> Upgrade Your Plan</a>';
    $cont .= '</div>';
}

// ── Cancel confirmation dialog ─────────────────────────────────────────────────
if ($mp && $has_active_sub) {
    $cont .= '<div id="myplan-cancel-dialog" class="myplan-cancel-dialog" style="display:none;">';
    $cont .= '<div class="myplan-cancel-inner">';
    $cont .= '<h3><i class="fa-solid fa-triangle-exclamation"></i> Cancel Subscription?</h3>';
    $cont .= '<p>Your plan will stay active until <strong>' . qa_html($expiry_date ?: 'end of period') . '</strong>. After that, you\'ll revert to the free plan.</p>';
    $cont .= '<div class="myplan-cancel-btns">';
    $cont .= '<button type="button" class="myplan-cancel-confirm-btn" onclick="myplanDoCancel()">Yes, Cancel</button>';
    $cont .= '<button type="button" class="myplan-cancel-abort-btn" onclick="document.getElementById(\'myplan-cancel-dialog\').style.display=\'none\'">Keep My Plan</button>';
    $cont .= '</div>';
    $cont .= '</div>';
    $cont .= '</div>';
}

// ── Inline JS ─────────────────────────────────────────────────────────────────
$cont .= '<script>';
$cont .= 'var MYPLAN_AJAX_URL     = ' . json_encode($ajax_url) . ';';
$cont .= 'var MYPLAN_QA_ROOT      = ' . json_encode($qa_root) . ';';
$cont .= 'var MYPLAN_MONTHLY_ALLOT = ' . (int)$monthly_allot . ';';
$cont .= <<<'JS'

function myplanRefreshCoins(btn) {
    if (btn) { btn.disabled = true; var icon = btn.querySelector('i'); if (icon) icon.classList.add('fa-spin'); }
    var fd = new FormData();
    fd.append('qa_operation', 'getcoins');
    fd.append('qa_request', 'myplan');
    fd.append('qa_root', MYPLAN_QA_ROOT);
    var xhr = new XMLHttpRequest();
    xhr.open('POST', MYPLAN_AJAX_URL, true);
    xhr.timeout = 10000;
    function resetBtn() { if (btn) { btn.disabled = false; var icon = btn.querySelector('i'); if (icon) icon.classList.remove('fa-spin'); } }
    xhr.onload = function() {
        resetBtn();
        try {
            var r = JSON.parse((xhr.responseText || '').trim());
            if (r.status === 'ok') {
                // Update big total
                var numEl = document.getElementById('myplan-coin-number-val');
                if (numEl) numEl.innerHTML = '<i class="fa-solid fa-coins" style="font-size:.75em;margin-right:6px;color:#7c3aed"></i>' + r.coins.toLocaleString();

                // Update subscription bar (remaining %)
                var subRemaining = r.from_sub || 0;
                var allot = MYPLAN_MONTHLY_ALLOT || 10000;
                var subRemPct = allot > 0 ? Math.min(100, Math.round(subRemaining * 100 / allot)) : 0;
                var barColor = subRemPct <= 10 ? '#ef4444' : (subRemPct <= 30 ? '#f59e0b' : '#7c3aed');
                var fillEl = document.getElementById('myplan-coin-fill');
                if (fillEl) { fillEl.style.width = subRemPct + '%'; fillEl.style.background = barColor; }
                var subEl = document.getElementById('myplan-sub-coins');
                if (subEl) subEl.textContent = subRemaining.toLocaleString();

                // Update topup coins
                var topupEl = document.getElementById('myplan-topup-coins');
                if (topupEl) topupEl.innerHTML = '<strong id="myplan-topup-coins">' + (r.from_topup || 0).toLocaleString() + ' top-up coins</strong>';

                // Update navbar coin display
                var navEl = document.getElementById('ebonix-coin-count');
                if (navEl) navEl.textContent = r.coins.toLocaleString() + ' coins';

                // Low balance warning
                if (r.low_balance && !document.getElementById('ebonix-low-coin-bar')) {
                    if (typeof ebonixShowLowCoinWarning === 'function') ebonixShowLowCoinWarning(r.coins);
                }
            }
        } catch(e) {}
    };
    xhr.onerror = xhr.ontimeout = resetBtn;
    xhr.send(fd);
}

function myplanRefreshStats(btn) {
    if (btn) {
        btn.disabled = true;
        var icon = btn.querySelector('i');
        if (icon) icon.classList.add('fa-spin');
    }
    var fd = new FormData();
    fd.append('qa_operation', 'getusagestats');
    fd.append('qa_request',   'myplan');
    fd.append('qa_root',      MYPLAN_QA_ROOT);
    var xhr = new XMLHttpRequest();
    xhr.open('POST', MYPLAN_AJAX_URL, true);
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.timeout = 10000;
    function resetBtn() {
        if (btn) {
            btn.disabled = false;
            var icon = btn.querySelector('i');
            if (icon) icon.classList.remove('fa-spin');
        }
    }
    xhr.onload = function() {
        resetBtn();
        try {
            var data   = JSON.parse((xhr.responseText || '').trim());
            var usedEl = document.getElementById('myplan-usage-text');
            var fillEl = document.getElementById('myplan-fill');
            var vUsedEl = document.getElementById('myplan-video-text');
            var vFillEl = document.getElementById('myplan-vfill');

            // Image bar
            if (typeof data.used !== 'undefined') {
                var periodLabel = (data.plan === 0) ? ' lifetime' : ' this period';
                var remaining = (data.limit !== null && data.limit !== undefined)
                    ? ' \u2014 ' + Math.max(0, data.limit - data.used) + ' remaining' : '';
                var txt = (data.limit !== null && data.limit !== undefined)
                    ? data.used + ' of ' + data.limit + periodLabel + ' used' + remaining
                    : data.used + ' used \u2014 Unlimited';
                if (usedEl) usedEl.textContent = txt;
                var perc = data.percent || 0;
                if (fillEl) {
                    fillEl.style.width = perc + '%';
                    fillEl.style.background = perc >= 90 ? '#ef4444' : (perc >= 70 ? '#f59e0b' : '#22c55e');
                }
            }

            // Video bar
            if (typeof data.video_used !== 'undefined') {
                if (data.plan === 0) {
                    if (vUsedEl) vUsedEl.innerHTML = 'Not included in Free plan';
                } else if (data.video_limit !== null && data.video_limit !== undefined && data.video_limit > 0) {
                    var vRem = Math.max(0, data.video_limit - data.video_used);
                    if (vUsedEl) vUsedEl.textContent = data.video_used + ' of ' + data.video_limit + ' used this period \u2014 ' + vRem + ' remaining';
                    if (vFillEl) {
                        vFillEl.style.width = (data.video_pct || 0) + '%';
                        vFillEl.style.background = data.video_pct >= 90 ? '#ef4444' : (data.video_pct >= 70 ? '#f59e0b' : '#6366f1');
                    }
                } else {
                    if (vUsedEl) vUsedEl.textContent = data.video_used + ' used \u2014 Unlimited';
                }
            }
        } catch(e) {}
    };
    xhr.onerror   = resetBtn;
    xhr.ontimeout = resetBtn;
    xhr.send(fd);
}

function myplanCancelConfirm() {
    var d = document.getElementById('myplan-cancel-dialog');
    if (d) d.style.display = 'flex';
}

function myplanDoCancel() {
    var fd = new FormData();
    fd.append('qa_operation', 'cancelsubscription');
    fd.append('qa_request',   'myplan');
    fd.append('qa_root',      MYPLAN_QA_ROOT);
    var xhr = new XMLHttpRequest();
    xhr.open('POST', MYPLAN_AJAX_URL, true);
    xhr.timeout = 15000;
    xhr.onload = function() {
        var d = document.getElementById('myplan-cancel-dialog');
        if (d) d.style.display = 'none';
        try {
            var res = JSON.parse((xhr.responseText || '').trim());
            if (res.status === 'cancelled' || res.status === 'success') {
                alert('Subscription cancelled. Your plan remains active until expiry.');
                window.location.reload();
            } else {
                alert('Could not cancel: ' + (res.message || 'Please try again.'));
            }
        } catch(e) { alert('Request failed. Please try again.'); }
    };
    xhr.onerror = xhr.ontimeout = function() {
        var d = document.getElementById('myplan-cancel-dialog');
        if (d) d.style.display = 'none';
        alert('Request failed. Please try again.');
    };
    xhr.send(fd);
}
JS;
$cont .= '</script>';

$qa_content['custom'] = $cont;
return $qa_content;
