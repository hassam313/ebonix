<?php
/*
 * File: king-include/king-app/coins.php
 *
 * Ebonix Coin System — all reusable coin helpers.
 * Include this file wherever coin operations are needed.
 */

if (!defined('QA_VERSION')) {
    header('Location: ../');
    exit;
}

// ── COIN COST LOOKUP ──────────────────────────────────────────────────────────

function ebonix_get_photo_tiers() {
    return [
        'standard' => [
            'model'  => 'flux_schnell',
            'coins'  => (int)(qa_opt('coin_cost_photo_standard') ?: 40),
            'label'  => 'Quick Draft',
            'desc'   => 'Fast · FLUX.1 schnell · 40 coins',
        ],
        'enhanced' => [
            'model'  => 'flux_dev',
            'coins'  => (int)(qa_opt('coin_cost_photo_enhanced') ?: 60),
            'label'  => 'Balanced',
            'desc'   => 'Best value · FLUX.1 dev · 60 coins',
        ],
        'beauty' => [
            'model'  => 'seedream_45',
            'coins'  => (int)(qa_opt('coin_cost_photo_beauty') ?: 80),
            'label'  => 'Portrait Mode',
            'desc'   => 'Skin & beauty · Seedream 4.5 · 80 coins',
        ],
        'premium' => [
            'model'  => 'flux_11_pro_ultra',
            'coins'  => (int)(qa_opt('coin_cost_photo_premium') ?: 120),
            'label'  => 'Ultra Quality',
            'desc'   => 'Best output · FLUX 1.1 Pro Ultra · 120 coins',
        ],
    ];
}

function ebonix_get_video_tiers() {
    return [
        'basic' => [
            'model'  => 'seedance_lite',
            'coins'  => (int)(qa_opt('coin_cost_video_basic') ?: 700),
            'label'  => 'Basic Short Video',
            'desc'   => 'Seedance 1.0 Lite — quick preview',
        ],
        'enhanced' => [
            'model'  => 'seedance_pro_fast',
            'coins'  => (int)(qa_opt('coin_cost_video_enhanced') ?: 1000),
            'label'  => 'Enhanced Short Video',
            'desc'   => 'Seedance 1.0 Pro Fast — good quality',
        ],
        'pro' => [
            'model'  => 'seedance_pro',
            'coins'  => (int)(qa_opt('coin_cost_video_pro') ?: 1500),
            'label'  => 'Pro Video',
            'desc'   => 'Seedance 1.0 Pro — high quality',
        ],
        'premium' => [
            'model'  => 'kling_3_pro',
            'coins'  => (int)(qa_opt('coin_cost_video_premium') ?: 2200),
            'label'  => 'Premium Video',
            'desc'   => 'Kling 3 Pro — cinematic best',
        ],
    ];
}

/**
 * Map an existing model key to a photo tier.
 * Used as fallback when $_POST['tier'] is not sent by older UI.
 */
function ebonix_model_to_photo_tier($model_key) {
    $map = [
        // Standard (40 coins)
        'sdn'               => 'standard',
        'sdxl'              => 'standard',
        'de'                => 'standard',   // DALL-E 2
        // Enhanced (60 coins)
        'de3'               => 'enhanced',   // DALL-E 3
        'banana'            => 'enhanced',
        'decart_img'        => 'enhanced',
        // Beauty (80 coins)
        'sdream'            => 'beauty',
        'seedream_45'       => 'beauty',
        'luma_img'          => 'beauty',
        // Premium (120 coins)
        'fluxkon_selfie'    => 'premium',
        'imagen4'           => 'premium',
        'fluxkon'           => 'premium',
        'flux_11_pro_ultra' => 'premium',
        // Ebonix Fal models
        'ebonix_10'         => 'standard',
        'ebonix_classic'    => 'standard',
        'ebonix_flash'      => 'standard',
        'ebonix_20'         => 'enhanced',
        'ebonix_advanced'   => 'enhanced',
        'ebonix_pro'        => 'premium',
        'ebonix_studio'     => 'premium',
    ];
    return $map[$model_key] ?? 'enhanced';
}

/**
 * Map an existing video provider key to a video tier.
 */
function ebonix_model_to_video_tier($model_key) {
    $map = [
        // Basic
        'kling'       => 'basic',
        'luma'        => 'basic',
        'wan2'        => 'basic',
        'decart'      => 'basic',
        'foxai'       => 'basic',
        'pixverse'    => 'basic',
        // Enhanced
        'kingstudio'   => 'enhanced',
        'seedance'     => 'enhanced',
        'seedance_vid' => 'enhanced',
        // Pro
        'veo3'         => 'pro',
        // Premium
        'kling3pro'    => 'premium',
        'kling_3_pro'  => 'premium',
        'kling_v3'     => 'premium',
    ];
    return $map[$model_key] ?? 'enhanced';
}

function ebonix_get_addon_costs() {
    return [
        'hd_export' => (int)(qa_opt('coin_cost_addon_hd')      ?: 150),
        'upscale'   => (int)(qa_opt('coin_cost_addon_upscale')  ?: 75),
        'priority'  => (int)(qa_opt('coin_cost_addon_priority') ?: 100),
    ];
}

// ── COIN BALANCE ──────────────────────────────────────────────────────────────

function ebonix_get_coins($user_id) {
    return (int)(qa_db_usermeta_get($user_id, 'ebonix_coins') ?: 0);
}

function ebonix_get_coins_breakdown($user_id) {
    return [
        'total'      => (int)(qa_db_usermeta_get($user_id, 'ebonix_coins')     ?: 0),
        'from_sub'   => (int)(qa_db_usermeta_get($user_id, 'coins_from_sub')   ?: 0),
        'from_topup' => (int)(qa_db_usermeta_get($user_id, 'coins_from_topup') ?: 0),
    ];
}

function ebonix_has_coins($user_id, $required) {
    return ebonix_get_coins($user_id) >= $required;
}

/**
 * If user has never had ebonix_coins set (null, not 0), grant the free starting
 * coins automatically. Idempotent: only runs once per user.
 */
function ebonix_ensure_initialized($user_id) {
    $existing = qa_db_usermeta_get($user_id, 'ebonix_coins');
    if ($existing === null) {
        $starting = (int)(qa_opt('free_plan_coins') ?: 300);
        qa_db_usermeta_set($user_id, 'ebonix_coins',    $starting);
        qa_db_usermeta_set($user_id, 'coins_from_sub',  0);
        qa_db_usermeta_set($user_id, 'coins_from_topup', 0);
        if ($starting > 0) {
            ebonix_log_coin($user_id, 'earn', $starting, $starting, 'registration_grant');
        }
    }
}

// ── COIN DEDUCTION (only call after confirmed generation success) ─────────────

function ebonix_deduct_coins($user_id, $amount, $reason, $model_used = '', $post_id = null) {
    $current = ebonix_get_coins($user_id);
    $new_bal = max(0, $current - $amount);
    qa_db_usermeta_set($user_id, 'ebonix_coins', $new_bal);

    // Reduce from_sub first, then from_topup
    $from_sub   = (int)(qa_db_usermeta_get($user_id, 'coins_from_sub')   ?: 0);
    $from_topup = (int)(qa_db_usermeta_get($user_id, 'coins_from_topup') ?: 0);
    if ($from_sub >= $amount) {
        qa_db_usermeta_set($user_id, 'coins_from_sub', $from_sub - $amount);
    } elseif ($from_sub > 0) {
        $remainder = $amount - $from_sub;
        qa_db_usermeta_set($user_id, 'coins_from_sub',   0);
        qa_db_usermeta_set($user_id, 'coins_from_topup', max(0, $from_topup - $remainder));
    } else {
        qa_db_usermeta_set($user_id, 'coins_from_topup', max(0, $from_topup - $amount));
    }

    ebonix_log_coin($user_id, 'spend', -$amount, $new_bal, $reason, $model_used, $post_id);
    return $new_bal;
}

// ── COIN ADDITION ─────────────────────────────────────────────────────────────

function ebonix_add_coins($user_id, $amount, $reason, $type = 'earn') {
    $current = ebonix_get_coins($user_id);
    $new_bal = $current + $amount;
    qa_db_usermeta_set($user_id, 'ebonix_coins', $new_bal);
    ebonix_log_coin($user_id, $type, $amount, $new_bal, $reason);
    return $new_bal;
}

/**
 * Grant monthly Flex subscription coins — resets from_sub, preserves topup coins.
 * Call on checkout.session.completed (new sub) and invoice.payment_succeeded (renewal).
 */
function ebonix_grant_subscription_coins($user_id) {
    $monthly    = (int)(qa_opt('flex_plan_monthly_coins') ?: 10000);
    $from_topup = (int)(qa_db_usermeta_get($user_id, 'coins_from_topup') ?: 0);
    $new_total  = $from_topup + $monthly;
    qa_db_usermeta_set($user_id, 'ebonix_coins',    $new_total);
    qa_db_usermeta_set($user_id, 'coins_from_sub',  $monthly);
    ebonix_log_coin($user_id, 'earn', $monthly, $new_total, 'subscription_grant');
    return $new_total;
}

/**
 * Add top-up coins (never expire, tracked separately).
 */
function ebonix_grant_topup_coins($user_id, $amount, $pack_name = '') {
    $current    = ebonix_get_coins($user_id);
    $new_bal    = $current + $amount;
    $cur_topup  = (int)(qa_db_usermeta_get($user_id, 'coins_from_topup') ?: 0);
    qa_db_usermeta_set($user_id, 'ebonix_coins',    $new_bal);
    qa_db_usermeta_set($user_id, 'coins_from_topup', $cur_topup + $amount);
    ebonix_log_coin($user_id, 'topup', $amount, $new_bal, 'topup_purchase' . ($pack_name ? '_' . $pack_name : ''));
    return $new_bal;
}

// ── COIN LOG ──────────────────────────────────────────────────────────────────

function ebonix_ensure_coin_log_table() {
    static $ensured = false;
    if ($ensured) return;
    qa_db_query_sub(
        'CREATE TABLE IF NOT EXISTS `^king_coin_log` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `user_id` int(11) NOT NULL,
          `type` enum(\'earn\',\'spend\',\'topup\',\'refund\') NOT NULL,
          `amount` int(11) NOT NULL DEFAULT 0,
          `balance_after` int(11) NOT NULL DEFAULT 0,
          `reason` varchar(100) DEFAULT NULL,
          `model_used` varchar(100) DEFAULT NULL,
          `post_id` int(11) DEFAULT NULL,
          `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `user_id` (`user_id`),
          KEY `created_at` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $ensured = true;
}

function ebonix_log_coin($user_id, $type, $amount, $balance_after, $reason, $model_used = '', $post_id = null) {
    ebonix_ensure_coin_log_table();
    qa_db_query_sub(
        'INSERT INTO ^king_coin_log (user_id, type, amount, balance_after, reason, model_used, post_id, created_at)
         VALUES (#, $, #, #, $, $, #, NOW())',
        (int)$user_id,
        (string)$type,
        (int)$amount,
        (int)$balance_after,
        (string)$reason,
        (string)$model_used,
        ($post_id !== null && $post_id > 0) ? (int)$post_id : 0
    );
}

// ── PLAN CHECK HELPERS ────────────────────────────────────────────────────────

/**
 * Returns current plan ID for user (0=Free, ≥1=paid plan from king_plans).
 * Auto-downgrades expired plans. Maps old legacy plan IDs (2,3,4) to 1 if not
 * found in king_plans — ensures backward compat with old Motion/Flex/Pro users.
 */
function ebonix_get_user_plan($user_id) {
    $plan   = (int)(qa_db_usermeta_get($user_id, 'membership_plan') ?: 0);
    $expiry = (int)(qa_db_usermeta_get($user_id, 'membership_expiry') ?: 0);
    if ($plan >= 1 && $expiry > 0 && $expiry < time()) {
        qa_db_usermeta_set($user_id, 'membership_plan', 0);
        return 0;
    }
    if ($plan >= 1) {
        // Verify plan exists in king_plans; if not, map to plan 1 (Flex)
        $row = ebonix_get_plan($plan);
        if (!$row) {
            // Old legacy plan — treat as Flex (plan 1)
            return 1;
        }
    }
    return $plan;
}

function ebonix_is_flex($user_id) {
    return ebonix_get_user_plan($user_id) >= 1;
}

function ebonix_is_free($user_id) {
    return ebonix_get_user_plan($user_id) === 0;
}

// ── DYNAMIC PLANS TABLE ───────────────────────────────────────────────────────

/**
 * Ensure the king_plans table exists and has the default Flex plan seeded.
 * Safe to call on every page load — CREATE TABLE IF NOT EXISTS is a no-op.
 */
function ebonix_ensure_plans_table() {
    qa_db_query_sub(
        'CREATE TABLE IF NOT EXISTS `^king_plans` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `name` varchar(100) NOT NULL DEFAULT \'Flex\',
          `price` decimal(10,2) NOT NULL DEFAULT 29.00,
          `monthly_coins` int(11) NOT NULL DEFAULT 10000,
          `features` text DEFAULT NULL COMMENT \'JSON array of feature strings\',
          `sort_order` int(11) NOT NULL DEFAULT 0,
          `is_active` tinyint(1) NOT NULL DEFAULT 1,
          `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    // Seed default Flex plan (id=1) if table is empty
    $count = (int)qa_db_read_one_value(qa_db_query_sub('SELECT COUNT(*) FROM ^king_plans'), true);
    if ($count === 0) {
        $default_features = json_encode([
            '10,000 Coins every month',
            'AI Image — all 4 quality tiers',
            'AI Video — all 4 quality tiers',
            'AI Twin access',
            'Save and reuse your looks',
            'Mix photos and videos freely',
            'Top up coins anytime',
        ]);
        qa_db_query_sub(
            'INSERT INTO ^king_plans (id, name, price, monthly_coins, features, sort_order, is_active) VALUES (1, $, #, #, $, 0, 1)',
            'Flex',
            (float)(qa_opt('flex_plan_price') ?: 29.00),
            (int)(qa_opt('flex_plan_monthly_coins') ?: 10000),
            $default_features
        );
    }
}

/**
 * Return all active plans ordered by sort_order.
 */
function ebonix_get_plans($active_only = true) {
    ebonix_ensure_plans_table();
    $where = $active_only ? 'WHERE is_active = 1' : '';
    return qa_db_read_all_assoc(
        qa_db_query_sub('SELECT * FROM ^king_plans ' . $where . ' ORDER BY sort_order ASC, id ASC')
    );
}

/**
 * Return a single plan by ID.
 */
function ebonix_get_plan($id) {
    ebonix_ensure_plans_table();
    return qa_db_read_one_assoc(
        qa_db_query_sub('SELECT * FROM ^king_plans WHERE id = #', (int)$id),
        true
    );
}

/**
 * Save (insert or update) a plan.
 * Pass $id = 0 to insert new.
 */
function ebonix_save_plan($id, $name, $price, $monthly_coins, $features_arr = [], $sort_order = 0) {
    ebonix_ensure_plans_table();
    $features_json = json_encode(array_values(array_filter($features_arr)));
    if ($id > 0) {
        qa_db_query_sub(
            'UPDATE ^king_plans SET name=$, price=#, monthly_coins=#, features=$, sort_order=# WHERE id=#',
            (string)$name,
            (float)$price,
            (int)$monthly_coins,
            $features_json,
            (int)$sort_order,
            (int)$id
        );
        return $id;
    } else {
        qa_db_query_sub(
            'INSERT INTO ^king_plans (name, price, monthly_coins, features, sort_order, is_active) VALUES ($, #, #, $, #, 1)',
            (string)$name,
            (float)$price,
            (int)$monthly_coins,
            $features_json,
            (int)$sort_order
        );
        return (int)qa_db_last_insert_id();
    }
}

/**
 * Delete a plan by ID (never delete plan 1).
 */
function ebonix_delete_plan($id) {
    $id = (int)$id;
    if ($id <= 1) return false; // protect default Flex plan
    ebonix_ensure_plans_table();
    qa_db_query_sub('DELETE FROM ^king_plans WHERE id = #', $id);
    return true;
}

/**
 * Toggle plan active/inactive.
 */
function ebonix_toggle_plan($id, $active) {
    ebonix_ensure_plans_table();
    qa_db_query_sub(
        'UPDATE ^king_plans SET is_active = # WHERE id = #',
        $active ? 1 : 0,
        (int)$id
    );
}

// ── TOP-UP PACKS — DB-BACKED ──────────────────────────────────────────────────

/** Default hardcoded packs used to seed the DB on first use */
function ebonix_default_topup_packs() {
    return [
        ['coins' => 1000,   'price_cents' => 500,    'label' => '1,000 Coins',   'pack_name' => '1k_coins',   'best_for' => 'Try it out',       'sort_order' => 5],
        ['coins' => 5000,   'price_cents' => 1500,   'label' => '5,000 Coins',   'pack_name' => '5k_coins',   'best_for' => 'Casual creators',  'sort_order' => 10],
        ['coins' => 10000,  'price_cents' => 3000,   'label' => '10,000 Coins',  'pack_name' => '10k_coins',  'best_for' => 'Regular creators', 'sort_order' => 20],
        ['coins' => 15000,  'price_cents' => 4500,   'label' => '15,000 Coins',  'pack_name' => '15k_coins',  'best_for' => '',                 'sort_order' => 30],
        ['coins' => 20000,  'price_cents' => 6000,   'label' => '20,000 Coins',  'pack_name' => '20k_coins',  'best_for' => 'Power users',      'sort_order' => 40],
        ['coins' => 25000,  'price_cents' => 7500,   'label' => '25,000 Coins',  'pack_name' => '25k_coins',  'best_for' => '',                 'sort_order' => 50],
        ['coins' => 30000,  'price_cents' => 9000,   'label' => '30,000 Coins',  'pack_name' => '30k_coins',  'best_for' => '',                 'sort_order' => 60],
        ['coins' => 45000,  'price_cents' => 13500,  'label' => '45,000 Coins',  'pack_name' => '45k_coins',  'best_for' => 'Heavy users',      'sort_order' => 70],
        ['coins' => 115000, 'price_cents' => 34500,  'label' => '115,000 Coins', 'pack_name' => '115k_coins', 'best_for' => 'Studio / team',    'sort_order' => 80],
        ['coins' => 500000, 'price_cents' => 150000, 'label' => '500,000 Coins', 'pack_name' => '500k_coins', 'best_for' => 'Enterprise',       'sort_order' => 90],
    ];
}

/** Ensure king_topup_packs table exists and is seeded */
function ebonix_ensure_topup_packs_table() {
    static $ensured = false;
    if ($ensured) return;
    qa_db_query_sub(
        'CREATE TABLE IF NOT EXISTS `^king_topup_packs` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `pack_name` varchar(50) NOT NULL,
          `coins` int(11) NOT NULL DEFAULT 0,
          `price_cents` int(11) NOT NULL DEFAULT 0,
          `label` varchar(100) NOT NULL DEFAULT \'\',
          `best_for` varchar(100) DEFAULT NULL,
          `sort_order` int(11) NOT NULL DEFAULT 0,
          `is_active` tinyint(1) NOT NULL DEFAULT 1,
          `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `pack_name` (`pack_name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    // Seed defaults if empty
    $count = (int)qa_db_read_one_value(
        qa_db_query_sub('SELECT COUNT(*) FROM ^king_topup_packs'), true
    );
    if ($count === 0) {
        foreach (ebonix_default_topup_packs() as $p) {
            qa_db_query_sub(
                'INSERT IGNORE INTO ^king_topup_packs (pack_name, coins, price_cents, label, best_for, sort_order, is_active) VALUES ($, #, #, $, $, #, 1)',
                $p['pack_name'], $p['coins'], $p['price_cents'], $p['label'], $p['best_for'], $p['sort_order']
            );
        }
    }
    $ensured = true;
}

/**
 * Return all active top-up packs ordered by sort_order.
 * Falls back to hardcoded defaults if table is unavailable.
 */
function ebonix_get_topup_packs($active_only = true) {
    ebonix_ensure_topup_packs_table();
    $where = $active_only ? 'WHERE is_active = 1' : '';
    $rows  = qa_db_read_all_assoc(
        qa_db_query_sub('SELECT * FROM ^king_topup_packs ' . $where . ' ORDER BY sort_order ASC, id ASC')
    );
    if (!empty($rows)) {
        return $rows;
    }
    return ebonix_default_topup_packs();
}

/** Return all packs (including inactive) for admin. */
function ebonix_get_all_topup_packs() {
    return ebonix_get_topup_packs(false);
}

/** Return a single pack by ID. */
function ebonix_get_topup_pack($id) {
    ebonix_ensure_topup_packs_table();
    return qa_db_read_one_assoc(
        qa_db_query_sub('SELECT * FROM ^king_topup_packs WHERE id = #', (int)$id),
        true
    );
}

/** Return a single pack by pack_name. */
function ebonix_get_topup_pack_by_name($pack_name) {
    ebonix_ensure_topup_packs_table();
    return qa_db_read_one_assoc(
        qa_db_query_sub('SELECT * FROM ^king_topup_packs WHERE pack_name = $', (string)$pack_name),
        true
    );
}

/** Save (insert or update) a top-up pack. $id=0 = insert new. */
function ebonix_save_topup_pack($id, $pack_name, $coins, $price_cents, $label, $best_for, $sort_order) {
    ebonix_ensure_topup_packs_table();
    $pack_name = preg_replace('/[^a-z0-9_]/', '', strtolower($pack_name));
    if ($id > 0) {
        qa_db_query_sub(
            'UPDATE ^king_topup_packs SET pack_name=$, coins=#, price_cents=#, label=$, best_for=$, sort_order=# WHERE id=#',
            $pack_name, (int)$coins, (int)$price_cents, (string)$label, (string)$best_for, (int)$sort_order, (int)$id
        );
        return $id;
    } else {
        qa_db_query_sub(
            'INSERT INTO ^king_topup_packs (pack_name, coins, price_cents, label, best_for, sort_order, is_active) VALUES ($, #, #, $, $, #, 1)',
            $pack_name, (int)$coins, (int)$price_cents, (string)$label, (string)$best_for, (int)$sort_order
        );
        return (int)qa_db_last_insert_id();
    }
}

/** Delete a top-up pack by ID. */
function ebonix_delete_topup_pack($id) {
    ebonix_ensure_topup_packs_table();
    qa_db_query_sub('DELETE FROM ^king_topup_packs WHERE id = #', (int)$id);
}

/** Toggle a top-up pack active/inactive. */
function ebonix_toggle_topup_pack($id, $active) {
    ebonix_ensure_topup_packs_table();
    qa_db_query_sub(
        'UPDATE ^king_topup_packs SET is_active = # WHERE id = #',
        $active ? 1 : 0, (int)$id
    );
}
