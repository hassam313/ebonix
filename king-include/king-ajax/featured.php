<?php
if (!defined('QA_VERSION')) { header('Location: ../../'); exit; }

require_once QA_INCLUDE_DIR . 'king-app/users.php';
require_once QA_INCLUDE_DIR . 'king-app/limits.php';
require_once QA_INCLUDE_DIR . 'king-db/selects.php';

// Admin only
if (!qa_is_logged_in() || qa_get_logged_in_level() < QA_USER_LEVEL_ADMIN) {
    echo "QA_AJAX_RESPONSE\n0\n";
    exit;
}

$pid = (int)qa_post_text('pids');
if (!$pid) {
    echo "QA_AJAX_RESPONSE\n0\n";
    exit;
}

$query = qa_db_read_one_value(
    qa_db_query_sub('SELECT featured FROM ^posts WHERE postid=#', $pid),
    true
);

if ($query) {
    qa_db_query_sub('UPDATE ^posts SET featured=# WHERE postid=#', 0, $pid);
    echo "QA_AJAX_RESPONSE\n0\n";
} else {
    qa_db_query_sub('UPDATE ^posts SET featured=# WHERE postid=#', 1, $pid);
    echo "QA_AJAX_RESPONSE\n1\n";
}
