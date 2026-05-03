<?php
/*
 * File: king-include/king-pages/gallery.php
 * Purpose: Community gallery — shows recent AI-generated images
 */
if (!defined('QA_VERSION')) { header('Location: ../'); exit; }

require_once QA_INCLUDE_DIR . 'king-app/format.php';
require_once QA_INCLUDE_DIR . 'king-app/q-list.php';
require_once QA_INCLUDE_DIR . 'king-db/selects.php';

$userid   = qa_get_logged_in_userid();
$start    = qa_get_start();
$pagesize = qa_opt('page_size_home');

list($questions1, $questions2, $categories) = qa_db_select_with_pending(
    qa_db_qs_selectspec($userid, 'created', $start, null, null, false, false, $pagesize),
    qa_db_recent_a_qs_selectspec($userid, 0, null),
    qa_db_category_nav_selectspec(null, false, false, true)
);

qa_set_template('gallery');
$questions = qa_any_sort_and_dedupe(array_merge($questions1, $questions2));

// Resolve categoryid from first category
$categoryid = null;
if (!empty($categories)) {
    $first = reset($categories);
    $categoryid = $first['categoryid'];
}

$qa_content = qa_q_list_page_content(
    $questions,
    $pagesize,
    $start,
    qa_opt('cache_qcount'),
    'Community Gallery',
    'No images yet — be the first to create!',
    $categories,
    $categoryid,
    true,
    '',
    null,
    null,
    null,
    null
);

$qa_content['class']  = ' full-page';
$qa_content['header'] = '<h2 style="padding:16px 0;color:#fff;">Community Gallery</h2>';
return $qa_content;
