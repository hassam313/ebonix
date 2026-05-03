<?php
/*
 * File: king-include/king-pages/landing.php
 * Purpose: Ebonix public landing page — shown to visitors before sign-up
 */
if (!defined('QA_VERSION')) { header('Location: ../'); exit; }

require_once QA_INCLUDE_DIR . 'king-app/format.php';

qa_set_template('landing');
$qa_content = qa_content_prepare();
$qa_content['title']          = 'Ebonix — The AI Built For Black Culture';
$qa_content['landing_images'] = [];
return $qa_content;
