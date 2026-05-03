<?php
if (!defined('QA_VERSION')) { header('Location: ../../'); exit; }

require_once QA_INCLUDE_DIR . 'king-app/users.php';
require_once QA_INCLUDE_DIR . 'king-db/post-update.php';
require_once QA_INCLUDE_DIR . 'king-db/selects.php';

header('Content-Type: application/json');

// Must be logged in
if (!qa_is_logged_in()) {
    echo "QA_AJAX_RESPONSE\n0\n";
    exit;
}

$userid = qa_get_logged_in_userid();
$postid = (int)qa_post_text('postid');

if (!$postid) {
    echo "QA_AJAX_RESPONSE\n0\n";
    exit;
}

// Fetch post and verify ownership — admins may delete any post
$is_admin = qa_get_logged_in_level() >= QA_USER_LEVEL_ADMIN;

$post = qa_db_read_one_assoc(
    qa_db_query_sub('SELECT postid, userid FROM ^posts WHERE postid=# AND type=$', $postid, 'NOTE'),
    true
);

if (!$post) {
    echo "QA_AJAX_RESPONSE\n0\n";
    exit;
}

if (!$is_admin && (int)$post['userid'] !== (int)$userid) {
    // Ownership mismatch — silent deny, log the attempt
    error_log("deleteaipost: unauthorized delete attempt by userid={$userid} on postid={$postid}");
    echo "QA_AJAX_RESPONSE\n0\n";
    exit;
}

qa_db_post_delete($postid);

echo "QA_AJAX_RESPONSE\n1\n";
