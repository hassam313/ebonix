<?php
/*
 * File: king-include/king-db/migrate-coins.php
 *
 * One-time migration: creates king_coin_log table, adds columns to king_payments,
 * and initialises ebonix_coins / coins_from_sub / coins_from_topup for every user
 * who has not yet been seeded.
 *
 * Run once via browser: /king-include/king-db/migrate-coins.php
 * (Protected: only admins can run it.)
 * After success, a flag 'migration_coins_done' is stored in qa_options so it never
 * runs twice.
 */

if (!defined('QA_VERSION')) {
    header('Location: ../../');
    exit;
}

// Only admins may run migrations
if (qa_get_logged_in_level() < QA_USER_LEVEL_ADMIN) {
    echo json_encode(['status' => 'error', 'msg' => 'Admin access required.']);
    exit;
}

// Guard: run only once
if (qa_opt('migration_coins_done')) {
    echo json_encode(['status' => 'already_done', 'msg' => 'Migration was already completed.']);
    exit;
}

$errors = [];

// ── Step 1: Create king_coin_log ──────────────────────────────────────────────
try {
    qa_db_query_sub(
        'CREATE TABLE IF NOT EXISTS `^king_coin_log` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `user_id` INT NOT NULL,
          `type` ENUM(\'earn\',\'spend\',\'topup\',\'refund\') NOT NULL,
          `amount` INT NOT NULL,
          `balance_after` INT NOT NULL DEFAULT 0,
          `reason` VARCHAR(100) DEFAULT \'\',
          `model_used` VARCHAR(100) DEFAULT \'\',
          `post_id` INT DEFAULT NULL,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          INDEX `idx_user_id` (`user_id`),
          INDEX `idx_created_at` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
} catch (Exception $e) {
    $errors[] = 'king_coin_log create: ' . $e->getMessage();
}

// ── Step 2: Alter king_payments — add coins_added and topup_pack columns ──────
// We use raw queries since ADD COLUMN IF NOT EXISTS is MySQL 8+; handle gracefully
$cols_to_add = [
    'coins_added' => 'ALTER TABLE `^king_payments` ADD COLUMN `coins_added` INT DEFAULT 0',
    'topup_pack'  => 'ALTER TABLE `^king_payments` ADD COLUMN `topup_pack` VARCHAR(50) DEFAULT \'\'',
];
foreach ($cols_to_add as $col => $sql) {
    try {
        qa_db_query_sub($sql);
    } catch (Exception $e) {
        // Column likely already exists — not a fatal error
        if (strpos($e->getMessage(), 'Duplicate column') === false) {
            $errors[] = "king_payments add {$col}: " . $e->getMessage();
        }
    }
}

// ── Step 3: Initialise coin meta for every existing user ─────────────────────
require_once QA_INCLUDE_DIR . 'king-db/metas.php';
require_once QA_INCLUDE_DIR . 'king-app/coins.php';

$starting_coins = (int)(qa_opt('free_plan_coins') ?: 300);
$seeded = 0;

try {
    $users = qa_db_read_all_assoc(
        qa_db_query_sub('SELECT userid FROM ^users')
    );

    foreach ($users as $row) {
        $uid = (int)$row['userid'];
        if ($uid <= 0) continue;

        // Only initialise users who have never had the key set
        $existing = qa_db_usermeta_get($uid, 'ebonix_coins');
        if ($existing === null) {
            // Determine starting coin grant
            $plan = (int)(qa_db_usermeta_get($uid, 'membership_plan') ?: 0);
            if ($plan >= 1) {
                // Existing paid users: grant them a full monthly allotment
                $grant = (int)(qa_opt('flex_plan_monthly_coins') ?: 10000);
                qa_db_usermeta_set($uid, 'ebonix_coins',    $grant);
                qa_db_usermeta_set($uid, 'coins_from_sub',  $grant);
                qa_db_usermeta_set($uid, 'coins_from_topup', 0);
                ebonix_log_coin($uid, 'earn', $grant, $grant, 'migration_grant_flex');
            } else {
                // Free users: grant the configured starting coins
                qa_db_usermeta_set($uid, 'ebonix_coins',    $starting_coins);
                qa_db_usermeta_set($uid, 'coins_from_sub',  0);
                qa_db_usermeta_set($uid, 'coins_from_topup', 0);
                if ($starting_coins > 0) {
                    ebonix_log_coin($uid, 'earn', $starting_coins, $starting_coins, 'migration_grant_free');
                }
            }
            $seeded++;
        }
    }
} catch (Exception $e) {
    $errors[] = 'User seed: ' . $e->getMessage();
}

// ── Done ──────────────────────────────────────────────────────────────────────
if (empty($errors)) {
    qa_set_option('migration_coins_done', 1);
    echo json_encode([
        'status'  => 'ok',
        'msg'     => 'Migration complete.',
        'seeded'  => $seeded,
    ]);
} else {
    echo json_encode([
        'status' => 'partial',
        'errors' => $errors,
        'seeded' => $seeded,
    ]);
}
