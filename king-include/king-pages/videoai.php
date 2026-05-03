
<?php
/*
This program is free software; you can redistribute it and/or
modify it under the terms of the GNU General Public License
as published by the Free Software Foundation; either version 2
of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

More about this license: LICENCE.html
 */

if (!defined('QA_VERSION')) {
	// don't allow this page to be requested directly from browser
	header('Location: ../');
	exit;
}

require_once QA_INCLUDE_DIR.'king-app/format.php';
require_once QA_INCLUDE_DIR.'king-app/limits.php';
require_once QA_INCLUDE_DIR.'king-db/selects.php';
require_once QA_INCLUDE_DIR.'king-util/sort.php';
require_once QA_INCLUDE_DIR.'king-db/metas.php';
require_once QA_INCLUDE_DIR.'king-app/posts.php';
//    Check whether this is a follow-on question and get some info we need from the database

$in = array();

$followpostid     = qa_get('follow');
$in['categoryid'] = qa_clicked('doask') ? qa_get_category_field_value('category') : qa_get('cat');
$userid           = qa_get_logged_in_userid();
$handle           = qa_get_logged_in_handle();

list($categories, $followanswer, $completetags) = qa_db_select_with_pending(
	qa_db_category_nav_selectspec($in['categoryid'], true),
	isset($followpostid) ? qa_db_full_post_selectspec($userid, $followpostid) : null,
	qa_db_popular_tags_selectspec(0, QA_DB_RETRIEVE_COMPLETE_TAGS)
);
 
if (!isset($categories[$in['categoryid']])) {
	$in['categoryid'] = null;
}

if (@$followanswer['basetype'] != 'A') {
	$followanswer = null;
}

//    Check for permission error

$permiterror = qa_user_maximum_permit_error('permit_post_q', QA_LIMIT_QUESTIONS);

if ($permiterror && qa_clicked('doask')) {
	$errors = array();
	$errors['permiterror'] = qa_lang_html('question/ask_limit');
	$response['status'] = 'error';
	$response['message'] = $errors;
	echo json_encode($response); // Output response as JSON
	exit;
}

if ($permiterror || ! qa_opt('enable_aivideo')) {
	$qa_content = qa_content_prepare();

	switch ($permiterror) {
		case 'login':
		$qa_content['error']=qa_lang_html('users/no_permission');
		$econtent = qa_insert_login_links(qa_lang_html('question/ask_must_login'), qa_request(), isset($followpostid) ? array('follow' => $followpostid) : null);
		break;

		case 'confirm':
		$qa_content['error']=qa_lang_html('users/no_permission');
		$econtent = qa_insert_login_links(qa_lang_html('question/ask_must_confirm'), qa_request(), isset($followpostid) ? array('follow' => $followpostid) : null);
		break;

		case 'limit':
		$qa_content['error']=qa_lang_html('users/no_permission');
		$econtent=qa_lang_html('question/ask_limit');
		break;

		case 'membership':
		$qa_content['error']=qa_lang_html('users/no_permission');
		$econtent=qa_insert_login_links(qa_lang_html('misc/mem_message'));
		$qa_content['custom'] = '<div class="nopost"><i class="fa-solid fa-fingerprint fa-4x"></i><p>'.$econtent.'</p><a href="'. qa_path_html( 'membership' ) .'" class="meme-button">'.qa_lang_html('misc/see_plans').'</a></div>';
		break;

		case 'approve':
		$qa_content['error']=qa_lang_html('users/no_permission');
		$econtent=qa_lang_html('question/ask_must_be_approved');
		break;

		default:
		$econtent=qa_lang_html('users/no_permission');
		$qa_content['error']=qa_lang_html('users/no_permission');
		break;
	}

	if (empty($qa_content['custom'] )) {
		$qa_content['custom'] = '<div class="nopost"><i class="fa-solid fa-circle-user fa-4x"></i>'.$econtent.'</div>';
	}
	return $qa_content;
}


$captchareason = qa_user_captcha_reason();

$in['title'] = qa_get_post_title('title'); // allow title and tags to be posted by an external form


if (qa_using_tags()) {
	$in['tags'] = qa_get_tags_field_value('tags');
}

if (qa_clicked('doask')) {
    require_once QA_INCLUDE_DIR . 'king-app/post-create.php';
	require_once QA_INCLUDE_DIR . 'king-app/post-update.php';
    require_once QA_INCLUDE_DIR . 'king-util/string.php';
	$in['postid'] = qa_post_text('uniqueid');
	$post=qa_db_select_with_pending(qa_db_full_post_selectspec($userid, $in['postid']));
    $categoryids = array_keys(qa_category_path($categories, @$in['categoryid']));
    $userlevel   = qa_user_level_for_categories($categoryids);

    $in['nsfw']   = qa_post_text('nsfw');
    $in['prvt']   = qa_post_text('prvt');
	qa_get_post_content('editor', 'content', $in['editor'], $in['content'], $in['format'], $in['text']);

	$errors = array();
    if (!qa_check_form_security_code('ask', qa_post_text('code'))) {
        $errors['page'] = qa_lang_html('misc/form_security_again');
    } else {
        $filtermodules = qa_load_modules_with('filter', 'filter_question');
        foreach ($filtermodules as $filtermodule) {
            $oldin = $in;
            $filtermodule->filter_question($in, $errors, null);
            qa_update_post_text($in, $oldin);
        }

        if (qa_using_categories() && count($categories) && (!qa_opt('allow_no_category')) && !isset($in['categoryid'])) {
            $errors['categoryid'] = qa_lang_html('question/category_required');
        } elseif (qa_user_permit_error('permit_post_q', null, $userlevel)) {
            $errors['categoryid'] = qa_lang_html('question/category_ask_not_allowed');
        }

        if ($captchareason) {
            require_once QA_INCLUDE_DIR . 'king-app/captcha.php';
            qa_captcha_validate_post($errors);
        }

		if ( isset( $errors['title'] ) ) {
			$errors['title'] = qa_lang_html('main/title_field');
		}

        if (empty($errors)) {
            $cookieid = isset($userid) ? qa_cookie_get() : qa_cookie_get_create(); // create a new cookie if necessary

			king_update_ai_post($in['postid'], $in['title'], isset($in['tags']) ? qa_tags_to_tagstring($in['tags']) : '', $in['nsfw'], 'V');

			$answers         = qa_post_get_question_answers( $in['postid'] );
			$commentsfollows = qa_post_get_question_commentsfollows( $in['postid'] );
			$closepost       = qa_post_get_question_closepost( $in['postid'] );

			if ( qa_using_categories() && isset($in['categoryid']) ){
				qa_question_set_category( $post, $in['categoryid'], $userid, $handle, $cookieid,
					$answers, $commentsfollows, $closepost, false );
			}
            if (isset($in['prvt'])) {
                qa_post_set_hidden($in['postid'], true, null);
            }
            $response['status'] = 'success';
            $response['message'] = qa_lang_html('misc/published');
			$response['url'] = qa_q_request($in['postid'], $in['title']);
			$response['message2'] = qa_lang_html('misc/seep');
        } else {
            $response['status'] = 'error';
            $response['message'] = $errors;
        }
        echo json_encode($response); // Output response as JSON
        exit;
    }
}
	if (qa_is_logged_in() && ( qa_opt('ailimits') || qa_opt('ulimits') ) && qa_get_logged_in_level() <= QA_USER_LEVEL_ADMIN && qa_opt('enable_membership')) {
		$qa_content = qa_content_prepare();
		$mp  = qa_db_usermeta_get( $userid, 'membership_plan' );
		$pl = null;
		if ($mp) {
			$pl = (INT)qa_opt('plan_'.$mp.'_lmt');
		} elseif(qa_opt('ulimits')) {
			$pl = (INT)qa_opt('ulimit');
		}
		$alm = (INT)qa_db_usermeta_get( $userid, 'ailmt' );
		if ($alm >= $pl) {
			$qa_content['custom'] = '<div class="nopost"><i class="fa-solid fa-circle-user fa-4x"></i>'.qa_lang('misc/nocredits').'<p><a href="'.qa_path_html('membership').'">'.qa_lang('misc/buycredits').'</a></p></div>';
			return $qa_content;
		}
	}
//    Prepare content for theme

$qa_content = qa_content_prepare(false, array_keys(qa_category_path($categories, @$in['categoryid'])));

$qa_content['title'] = qa_lang_html('misc/king_aivid');
$qa_content['error'] = @$errors['page'];


$field['label'] = qa_lang_html('question/q_content_label');
$field['error'] = qa_html(@$errors['content']);

$custom = qa_opt('show_custom_ask') ? trim(qa_opt('custom_ask')) : '';


if (qa_is_logged_in()) {

$cont = '';

// ── Fetch recent twins for gallery ──────────────────────────────────────────
$gallery_twins_video = [];
try {
    qa_db_query_sub(
        'CREATE TABLE IF NOT EXISTS `^king_twins` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `user_id` int(11) NOT NULL,
          `image_url` text NOT NULL,
          `thumbnail_url` text DEFAULT NULL,
          `vibe` varchar(64) NOT NULL DEFAULT \'\',
          `format` varchar(16) NOT NULL DEFAULT \'4:5\',
          `details` text,
          `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `user_id` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $_vc = (int)qa_db_read_one_value(qa_db_query_sub('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=$ AND COLUMN_NAME=$', QA_MYSQL_TABLE_PREFIX.'king_twins', 'thumbnail_url'), true);
    if (!$_vc) qa_db_query_sub('ALTER TABLE ^king_twins ADD COLUMN thumbnail_url TEXT DEFAULT NULL');
    $gallery_twins_video = qa_db_read_all_assoc(
        qa_db_query_sub(
            'SELECT id, image_url, thumbnail_url, vibe, created_at FROM ^king_twins WHERE user_id=# ORDER BY created_at DESC LIMIT 12',
            (int)$userid
        )
    );
} catch (Exception $e) { $gallery_twins_video = []; }

if (!function_exists('king_ensure_twin_thumbnails')) require_once QA_INCLUDE_DIR . 'king-app/users.php';
king_ensure_twin_thumbnails($gallery_twins_video);

$king_ajax_url_video = rtrim((string)qa_opt('site_url'), '/') . '/king-include/king-ajax.php';

if ( qa_opt( 'king_leo_enable' ) && qa_opt( 'enable_aivideo' ) ) {
	$cont .= '<ul class="king-nav-kingsub-list" id="nav-kingsub">';
	if ( qa_opt( 'king_leo_enable' ) ) {
		$cont .='<li class="king-nav-kingsub-item">';
		$cont .='<a href="' . qa_path_html( 'submitai' ) . '" ><i class="fa-regular fa-image"></i> ' . qa_lang_html( 'misc/king_ai' ) . '</a>';
		$cont .='</li>';
	}
	if ( qa_opt( 'enable_aivideo' ) ) {
		$cont .='<li class="king-nav-kingsub-item">';
		$cont .='<a href="' . qa_path_html( 'videoai' ) . '" class="king-nav-kingsub-selected"><i class="fa-regular fa-circle-play"></i> ' . qa_lang_html( 'misc/king_aivid' ) . '</a>';
		$cont .='</li>';
	}
	if ( qa_opt( 'enable_aitwin' ) ) {
		$cont .='<li class="king-nav-kingsub-item">';
		$cont .='<a href="' . qa_path_html( 'aitwin' ) . '"><i class="fa-regular fa-user-circle"></i> AI Twin</a>';
		$cont .='</li>';
	}
	if ( qa_opt( 'enable_aichat' ) ) {
		$cont .='<li class="king-nav-kingsub-item">';
		$cont .='<a href="' . qa_path_html( 'aichat' ) . '"><i class="fa-regular fa-comment-dots"></i> AI Chat</a>';
		$cont .='</li>';
	}
	$cont .='</ul>';
}

// ── Twin Gallery ─────────────────────────────────────────────────────────────
$cont .= '<div class="twin-gallery-wrap" id="twin-gallery-wrap-video">';
$cont .= '<div class="twin-gallery-header">';
$cont .= '<i class="fa-solid fa-clone"></i> My Twins';
$cont .= '<a href="' . qa_path_html('aitwin') . '" class="twin-gallery-view-all">View all</a>';
$cont .= '</div>';
$cont .= '<div class="twin-gallery-scroll" id="twin-gallery-scroll-video">';
if (!empty($gallery_twins_video)) {
    foreach ($gallery_twins_video as $gt) {
        $vibe_esc  = qa_html($gt['vibe']);
        $thumb_src = !empty($gt['thumbnail_url']) ? $gt['thumbnail_url'] : $gt['image_url'];
        $fetch_url = $thumb_src;
        $url_js    = addslashes($fetch_url);
        $vibe_js   = addslashes($gt['vibe']);
        $cont .= '<div class="twin-gallery-item" data-url="' . qa_html($fetch_url) . '" data-vibe="' . $vibe_esc . '"';
        $cont .= ' onclick="videoTwinGallerySelect(this,\'' . $url_js . '\',\'' . $vibe_js . '\')">';
        $cont .= '<img src="' . qa_html($thumb_src) . '" alt="' . $vibe_esc . '" loading="eager" decoding="async" width="110" height="110"'
            . ' onerror="this.style.display=\'none\';this.parentNode.querySelector(\'.twin-gallery-broken\').style.display=\'flex\'">'
            . '<div class="twin-gallery-broken" style="display:none"><i class="fa-solid fa-rotate-right"></i><span>Regenerate</span></div>';
        $cont .= '<span class="twin-gallery-vibe">' . $vibe_esc . '</span>';
        $cont .= '</div>';
    }
} else {
    $cont .= '<div class="twin-gallery-empty">';
    $cont .= 'No saved twins yet. <a href="' . qa_path_html('aitwin') . '">Create one in the AI Twin tab.</a>';
    $cont .= '</div>';
}
$cont .= '</div>';
$cont .= '</div>';
// ── End Twin Gallery ─────────────────────────────────────────────────────────

$cont .= '<div class="kingai-box active">
<div class="king-form-tall-error" id="ai-error" style="display: none;"></div>';
if ($custom) {
$cont .= '<div class="snote" >'.$custom.'</div>';
}
// ── Input card: ref chip + textarea + footer row (paperclip | generate) ──────
$cont .= '<div class="kingai-input vid-input-wrap">';
$cont .= '<div id="video-ref-preview-wrap" class="ref-preview-wrap" style="display:none;">';
$cont .= '<div class="ref-img-chip">';
$cont .= '<img id="video-ref-image-thumb" src="" alt="preview">';
$cont .= '<span id="video-ref-chipname"></span>';
$cont .= '<button type="button" class="ref-img-chip-remove" onclick="videoClearRefImage()"><i class="fa-solid fa-xmark"></i></button>';
$cont .= '</div></div>';
$cont .= '<textarea id="ai-box" class="aiinput vid-textarea" oninput="adjustHeight(this)" placeholder="'.qa_lang('misc/dvideo').'" maxlength="600" autocomplete="off" rows="3"></textarea>';
// ── Card footer: left tools + right generate button ──────────────────────
$cont .= '<div class="vid-card-footer">';
$cont .= '<div class="vid-card-footer-left">';
$cont .= '<button type="button" id="video-ref-image-btn" class="ai-attach-btn" onclick="document.getElementById(\'video_ref_image\').click()" title="Add reference image"><i class="fa-solid fa-paperclip"></i></button>';
if (qa_opt('eprompter')) {
    $showElement = qa_opt('oaprompter') ? (qa_get_logged_in_level() >= QA_USER_LEVEL_ADMIN) : true;
    if ($showElement) {
        $cont .= '<button type="button" id="prompter" onclick="aipromter(this)" class="king-sbutton ai-create promter" data-toggle="tooltip" title="' . qa_lang('misc/prompter') . '" data-placement="left"><i class="fa-solid fa-feather"></i><div class="loader"></div></button>';
    }
}
$cont .= '</div>'; // end vid-card-footer-left
$cont .= '<button type="button" id="ai-submit" class="ai-submit ebx-vid-generate-btn" onclick="return videoSubmitWrapper(this);">';
$cont .= '<span><i class="fa-solid fa-paper-plane"></i> '.qa_lang('misc/generate').'</span><div class="loader"></div></button>';
$cont .= '</div>'; // end vid-card-footer
$cont .= '</div>'; // end kingai-input.vid-input-wrap

$cont .= '<input type="file" id="video_ref_image" accept="image/*" class="aiupload-file-hidden">';

// ── Video generation loading status ────────────────────────────────────────
$cont .= '<div id="video-status" class="video-gen-status" style="display:none;">';
$cont .= '<i class="fa-solid fa-spinner fa-spin"></i> <strong>Generating video&hellip;</strong>';
$cont .= '<div class="video-gen-status-hint">This may take 1&ndash;10 minutes depending on the model</div>';
$cont .= '</div>';

// ── Model / size / resolution selectors (below generate button) ────────────
// Define available models and their options
$gateway_enabled = (qa_opt('gateway_enabled') == '1' && !empty(qa_opt('gateway_url')));

$models = array(
	'veo3f' => array(
		'enabled' => true, // ✅ Always enabled for gateway
		'label' => qa_lang('misc/veo3f'),
	),
	'veo3' => array(
		'enabled' => true, // ✅ Always enabled for gateway
		'label' => qa_lang('misc/veo3'),
	),
	'seedance_vid' => array(
		'enabled' => true, // ✅ Standard Video via Ebonix Gateway (Seedance 2.0)
		'label' => 'Standard Video',
	),
	'kling_v3' => array(
		'enabled' => true, // ✅ Premium Video via Ebonix Gateway (Kling 3 Pro)
		'label' => 'Premium Video',
	),
	'decart_vid' => array(
		'enabled' => true, // ✅ Always enabled for gateway
		'label' => qa_lang('misc/decart_vid'),
	),
	'kst' => array(
		'enabled' => qa_opt('enable_kst'),
		'label' => qa_lang('misc/kst'),
	),
	'wan' => array(
		'enabled' => qa_opt('enable_wan'),
		'label' => qa_lang('misc/wan'),
	),
	'luma' => array(
		'enabled' => qa_opt('enable_luna'),
		'label' => qa_lang('misc/luma'),
	),
	'pixverse' => array(
		'enabled' => qa_opt('enable_pixverse'),
		'label' => qa_lang('misc/pixverse'),
	),
	'see' => array(
		'enabled' => qa_opt('enable_see'),
		'label' => qa_lang('misc/see'),
	),
);

// Define enabled status variables for each model
$luna_enabled = !empty($models['luma']['enabled']);
$pixverse_enabled = !empty($models['pixverse']['enabled']);
$wan_enabled = !empty($models['wan']['enabled']);
$veo_enabled = !empty($models['veo']['enabled']);
$see_enabled = !empty($models['see']['enabled']);
$kst_enabled = !empty($models['kst']['enabled']);
$veo3_enabled = !empty($models['veo3']['enabled']);
$veo3f_enabled = !empty($models['veo3f']['enabled']);

// Filter enabled models
$enabled_models = array_filter($models, function($model) {
	return !empty($model['enabled']);
});

$model_count = count($enabled_models);
$hide_model = ($model_count <= 1) ? ' hide' : '';

// Set default model (first enabled one)
$default_key = key($enabled_models);
$default_model = $default_value = '';
if ($enabled_models) {
	$first = reset($enabled_models);
	$default_model = $first['label'];
	$default_value = $default_key;
}

// Determine the model class if only one model is enabled
if ($model_count == 1) {
	$cmodel = $default_value;
} else {
	$cmodel = '';
}
$cont .= '<div class="' . qa_html($default_value) . ' video-options-wrap" id="chclass">';
$cont .= '<div class="kingai-down">';
$cont .= '<div class="kingai-downleft kingai-buttons">';
if ( qa_opt( 'enable_luna_img')) {
$cont .= '<div id="newsthumb" class="dropzone king-poll-file aiupload dhpix hveo"></div>';
}


$cont .= '<div class="king-dropdownup custom-select model-select' . qa_html($hide_model) . '">
	<div class="king-sbutton kings-button" id="model-select-btn" data-toggle="dropdown" aria-expanded="false" role="button">
		<span id="model-select-label">' . qa_html($default_model) . '</span>
	</div>
	<div class="king-dropdownc king-dropleft model-options">';
	if ($kst_enabled) {
		$checked = ($default_value == 'kst') ? 'checked' : '';
		$cont .= '<label class="cradio" data-toggle="tooltip" title="Video with audio">
			<input type="radio" name="aimodel" value="kst" ' . $checked . '>
			<span>' . qa_lang('misc/kst') . '</span>
		</label>';
	}
if ($wan_enabled) {
	$checked = ($default_value == 'wan') ? 'checked' : '';
	$cont .= '<label class="cradio">
		<input type="radio" name="aimodel" value="wan" ' . $checked . '>
		<span>' . qa_lang('misc/wan') . '</span>
	</label>';
}	
if ($luna_enabled) {
	$checked = ($default_value == 'luma') ? 'checked' : '';
	$cont .= '<label class="cradio">
		<input type="radio" name="aimodel" value="luma" ' . $checked . '>
		<span>Luma Ray</span>
	</label>';
}
if ($pixverse_enabled) {
	$checked = ($default_value == 'pixverse') ? 'checked' : '';
	$cont .= '<label class="cradio">
		<input type="radio" name="aimodel" value="pixverse" ' . $checked . '>
		<span>' . qa_lang('misc/pixverse') . '</span>
	</label>';
}

if ($veo_enabled) {
	$checked = ($default_value == 'veo') ? 'checked' : '';
	$cont .= '<label class="cradio">
		<input type="radio" name="aimodel" value="veo" ' . $checked . '>
		<span>' . qa_lang('misc/veo') . '</span>
	</label>';
}
if ($see_enabled) {
	$checked = ($default_value == 'see') ? 'checked' : '';
	$cont .= '<label class="cradio">
		<input type="radio" name="aimodel" value="see" ' . $checked . '>
		<span>Seedance</span>
	</label>';
}

if ($veo3_enabled) {
    $checked = ($default_value == 'veo3') ? 'checked' : '';
    $cont .= '<label class="cradio" data-toggle="tooltip" title="Professional quality, longer videos">
        <input type="radio" name="aimodel" value="veo3" ' . $checked . '>
        <span>' . qa_lang('misc/veo3') . '</span>
    </label>';
}
if ($veo3f_enabled) {
    $checked = ($default_value == 'veo3f') ? 'checked' : '';
    $cont .= '<label class="cradio" data-toggle="tooltip" title="Recommended: Fast & high-quality">
        <input type="radio" name="aimodel" value="veo3f" ' . $checked . '>
        <span>' . qa_lang('misc/veo3f') . '</span>
    </label>';
}

$decart_vid_enabled = !empty($models['decart_vid']['enabled']);
if ($decart_vid_enabled) {
    $checked = ($default_value == 'decart_vid') ? 'checked' : '';
    $cont .= '<label class="cradio" data-toggle="tooltip" title="Fast generation">
        <input type="radio" name="aimodel" value="decart_vid" ' . $checked . '>
        <span>' . qa_lang('misc/decart_vid') . '</span>
    </label>';
}
$seedance_vid_enabled = !empty($models['seedance_vid']['enabled']);
if ($seedance_vid_enabled) {
    $checked = ($default_value == 'seedance_vid') ? 'checked' : '';
    $cont .= '<label class="cradio" data-toggle="tooltip" title="Standard video — fast generation (Seedance 2.0)">
        <input type="radio" name="aimodel" value="seedance_vid" ' . $checked . '>
        <span>Standard Video</span>
    </label>';
}
$kling_v3_enabled = !empty($models['kling_v3']['enabled']);
if ($kling_v3_enabled) {
    $checked = ($default_value == 'kling_v3') ? 'checked' : '';
    $cont .= '<label class="cradio" data-toggle="tooltip" title="Premium video — cinematic quality (Kling 3 Pro)">
        <input type="radio" name="aimodel" value="kling_v3" ' . $checked . '>
        <span>Premium Video</span>
    </label>';
}
$cont .= '</div>';
$cont .= '</div>';





$cont .= '<div class="king-dropdownup custom-select hveo">
							<div class="king-sbutton kings-button" id="aivsizeb" data-toggle="dropdown" aria-expanded="false" role="button">16:9</div>
							<div class="king-dropdownc king-dropleft aivsize">                   
								<label class="cradio"><input type="radio" name="aisize" value="16:9" id="aivsize" checked class="hide"><span><i class="king-square s1"></i>16:9</span></label>
								<label class="cradio hwan"><input type="radio" name="aisize" value="4:3" class="hide"><span><i class="king-square s2"></i>4:3</span></label>
								<label class="cradio hwan dhpix"><input type="radio" name="aisize" value="1:1" class="hide"><span><i class="king-square"></i>1:1</span></label>
								<label class="cradio hwan"><input type="radio" name="aisize" value="3:4" class="hide"><span><i class="king-square s4"></i>3:4</span></label>
								<label class="cradio"><input type="radio" name="aisize" value="9:16" class="hide"><span><i class="king-square s5"></i>9:16</span></label>
							</div>
						</div>';

$cont .= '<div class="king-dropdownup custom-select video-reso-select dhpix hveo">
	<div class="king-sbutton kings-button" id="video-reso-btn" data-toggle="dropdown" aria-expanded="false" role="button">
		<span id="video-reso-label">540p</span>
	</div>
	<div class="king-dropdownc king-dropleft video-reso-options">
		<label class="cradio">
			<input type="radio" name="reso" value="540p" checked onchange="document.getElementById(\'video-reso-label\').innerText=\'540p\'">
			<span>540p</span>
		</label>
		<label class="cradio">
			<input type="radio" name="reso" value="720p" onchange="document.getElementById(\'video-reso-label\').innerText=\'720p\'">
			<span>720p</span>
		</label>
	</div>
</div>';
$cont .= '</div>'; // end kingai-downleft
$cont .= '</div>'; // end kingai-down
$cont .= '</div>'; // end chclass / video-options-wrap

// ── Coin cost preview bar ──────────────────────────────────────────────────
if (!function_exists('ebonix_get_coins')) {
    require_once QA_INCLUDE_DIR . 'king-app/coins.php';
}
$_vid_uid  = qa_get_logged_in_userid();
$_vid_bal  = $_vid_uid ? ebonix_get_coins($_vid_uid) : 0;
$_vid_tiers_js = [];
foreach (ebonix_get_video_tiers() as $tk => $tv) {
    $_vid_tiers_js[$tk] = ['coins' => $tv['coins'], 'label' => $tv['label']];
}
$cont .= '<div class="ebx-cost-preview" id="ebx-vid-cost-preview">';
$cont .= '<span class="ebx-cost-preview-icon"><i class="fa-solid fa-coins"></i></span>';
$cont .= '<span id="ebx-vid-cost-text">Select a model to see cost</span>';
$cont .= '<span class="ebx-cost-balance" id="ebx-vid-cost-balance">· ' . number_format($_vid_bal) . ' coins remaining</span>';
$cont .= '</div>';

$cont .= '</div>'; // end kingai-box

$cont .= '<div id="ai-results">'.king_ai_posts($userid, 'aivid').'</div>';
$cont .= '</div>';
// ── Coin cost preview JS variables ────────────────────────────────────────
$cont .= '<script>';
$cont .= 'var EBONIX_VIDEO_TIERS = ' . json_encode($_vid_tiers_js) . ';';
$cont .= 'var EBONIX_VID_BAL     = ' . (int)$_vid_bal . ';';
$cont .= <<<'VIDCOSTJS'

// ── Video model → tier mapping ─────────────────────────────────────────────
var VID_MODEL_TIER = {
    'see':          'basic',
    'kst':          'basic',
    'wan':          'basic',
    'luma':         'enhanced',
    'pixverse':     'enhanced',
    'veo':          'enhanced',
    'veo3f':        'pro',
    'veo3':         'pro',
    'luma_vid':     'enhanced',
    'decart_vid':   'basic',
    'seedance_vid': 'enhanced',
    'kling_v3':     'premium'
};

function ebxUpdateVideoCostPreview() {
    var preview  = document.getElementById('ebx-vid-cost-preview');
    var costText = document.getElementById('ebx-vid-cost-text');
    var costBal  = document.getElementById('ebx-vid-cost-balance');
    if (!preview || !costText) return;

    var model    = (document.querySelector('input[name="aimodel"]:checked') || {}).value || '';
    var tier     = VID_MODEL_TIER[model] || 'basic';
    var tierData = EBONIX_VIDEO_TIERS[tier] || null;

    if (!tierData) {
        costText.textContent = 'Select a model to see cost';
        preview.className    = 'ebx-cost-preview';
        return;
    }

    var cost = tierData.coins || 0;
    costText.innerHTML = '<strong>' + cost.toLocaleString() + ' coins</strong>'
        + ' &mdash; ' + (tierData.label || tier);

    var raw    = document.getElementById('ebonix-coin-count');
    var bal    = raw ? parseInt((raw.textContent || '').replace(/[^0-9]/g,''), 10) : EBONIX_VID_BAL;
    var enough = bal >= cost;
    preview.className  = 'ebx-cost-preview ' + (enough ? 'cost-ok' : 'cost-warning');
    if (costBal) {
        costBal.innerHTML = '&middot; ' + bal.toLocaleString() + ' remaining';
    }
}

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('input[name="aimodel"]').forEach(function(r) {
        r.addEventListener('change', ebxUpdateVideoCostPreview);
    });
    ebxUpdateVideoCostPreview();
});
VIDCOSTJS;
$cont .= '</script>';

$qa_content['custom'] = $cont;
$qa_content['custom'] .= '
<script>
(function(){
	function kingGetReusePayload(){
		var raw = null;
		try{ raw = sessionStorage.getItem("king_ai_reuse"); }catch(e){}
		if(!raw) return null;
		try{
			var data = JSON.parse(raw);
			try{ sessionStorage.removeItem("king_ai_reuse"); }catch(e){}
			return data;
		}catch(e){
			return null;
		}
	}

	function kingSetTextarea(id, val){
		var el = document.getElementById(id);
		if(!el) return;
		el.value = val || "";
		if(typeof adjustHeight === "function"){ try{ adjustHeight(el); }catch(e){} }
	}

	function kingSelectRadio(name, value){
		if(!value) return false;
		var input = document.querySelector(\'input[name="\' + name + \'"][value="\' + CSS.escape(value) + \'"]\');
		if(!input) return false;

		var label = input.closest("label");
		if(label && label.offsetParent === null) return false;

		input.checked = true;
		try{ input.dispatchEvent(new Event("change", {bubbles:true})); }catch(e){}
		try{ input.click(); }catch(e){}
		return true;
	}

	function kingSetVideoModel(model){
		if(!model) return;
		var input = document.querySelector(\'input[name="aimodel"][value="\' + CSS.escape(model) + \'"]\');
		if(!input) return;

		input.checked = true;
		try{ input.dispatchEvent(new Event("change", {bubbles:true})); }catch(e){}
		try{ input.click(); }catch(e){}

		// update dropdown label
		var labelEl = document.getElementById("model-select-label");
		if(labelEl){
			var lbl = input.closest("label");
			if(lbl){
				var t = (lbl.innerText || lbl.textContent || "").trim();
				if(t) labelEl.innerText = t;
			}
		}

		// update container class so css rules apply
		var ch = document.getElementById("chclass");
		if(ch) ch.className = model;
	}

	document.addEventListener("DOMContentLoaded", function(){
		var payload = kingGetReusePayload();
		if(!payload) return;

		// only apply for video page
		if(!payload.isVideo || parseInt(payload.isVideo, 10) !== 1) return;

		if(payload.prompt) kingSetTextarea("ai-box", payload.prompt);

		if(payload.model) kingSetVideoModel(payload.model);

		// size value on video page is like 16:9 9:16 etc
		if(payload.size){
			var ok = kingSelectRadio("aisize", payload.size);
			if(ok){
				var b = document.getElementById("aivsizeb");
				if(b) b.innerText = payload.size;
			}
		}

		// reso optional 540p 720p
		if(payload.reso){
			var ok2 = kingSelectRadio("reso", payload.reso);
			if(ok2){
				var r = document.getElementById("video-reso-label");
				if(r) r.innerText = payload.reso;
			}
		}

		var box = document.getElementById("ai-box");
		if(box){ try{ box.focus(); }catch(e){} }
	});
})();
</script>
';


$qa_content['custom'] .= '<script>
var videoRefImageB64 = "";

function videoClearRefImage() {
    videoRefImageB64 = "";
    var fi = document.getElementById("video_ref_image");
    if (fi) fi.value = "";
    var wrap = document.getElementById("video-ref-preview-wrap");
    if (wrap) wrap.style.display = "none";
    var thumb = document.getElementById("video-ref-image-thumb");
    if (thumb) thumb.src = "";
    var chip = document.getElementById("video-ref-chipname");
    if (chip) chip.textContent = "";
    var btn = document.getElementById("video-ref-image-btn");
    if (btn) btn.classList.remove("has-image");
    document.querySelectorAll("#twin-gallery-wrap-video .twin-gallery-item").forEach(function(el) {
        el.classList.remove("twin-gallery-selected");
    });
}

function videoShowRefPreview(dataUrl, filename) {
    var thumb = document.getElementById("video-ref-image-thumb");
    var chip  = document.getElementById("video-ref-chipname");
    var wrap  = document.getElementById("video-ref-preview-wrap");
    var btn   = document.getElementById("video-ref-image-btn");
    if (thumb) thumb.src = dataUrl;
    if (chip)  chip.textContent = filename && filename.length > 28 ? filename.substring(0, 25) + "..." : (filename || "reference image");
    if (wrap)  wrap.style.display = "block";
    if (btn)   btn.classList.add("has-image");
}

function videoTwinGallerySelect(el, url, vibe) {
    document.querySelectorAll("#twin-gallery-wrap-video .twin-gallery-item").forEach(function(item) {
        item.classList.remove("twin-gallery-selected");
    });
    el.classList.add("twin-gallery-selected");

    var img = new Image();
    img.crossOrigin = "anonymous";
    img.onload = function() {
        var maxSide = 1024, w = img.naturalWidth, h = img.naturalHeight;
        if (w > maxSide || h > maxSide) {
            if (w >= h) { h = Math.round(h * maxSide / w); w = maxSide; }
            else        { w = Math.round(w * maxSide / h); h = maxSide; }
        }
        var canvas = document.createElement("canvas");
        canvas.width = w; canvas.height = h;
        canvas.getContext("2d").drawImage(img, 0, 0, w, h);
        canvas.toBlob(function(blob) {
            var reader = new FileReader();
            reader.onload = function(e) {
                videoRefImageB64 = e.target.result;
                videoShowRefPreview(e.target.result, "twin-" + (vibe || "ref") + ".jpg");
            };
            reader.readAsDataURL(blob);
        }, "image/jpeg", 0.85);
    };
    img.onerror = function() {
        fetch(url).then(function(r) { return r.blob(); }).then(function(blob) {
            var reader = new FileReader();
            reader.onload = function(e) {
                videoRefImageB64 = e.target.result;
                videoShowRefPreview(e.target.result, "twin-" + (vibe || "ref") + ".jpg");
            };
            reader.readAsDataURL(blob);
        }).catch(function() {});
    };
    img.src = url;
}

document.addEventListener("DOMContentLoaded", function() {
    var fi = document.getElementById("video_ref_image");
    if (fi) {
        fi.addEventListener("change", function() {
            var file = fi.files && fi.files[0];
            if (!file) { videoClearRefImage(); return; }
            var maxSide = 1024;
            var reader0 = new FileReader();
            reader0.onload = function(e0) {
                var img = new Image();
                img.onload = function() {
                    var w = img.naturalWidth, h = img.naturalHeight;
                    if (w > maxSide || h > maxSide) {
                        if (w >= h) { h = Math.round(h * maxSide / w); w = maxSide; }
                        else        { w = Math.round(w * maxSide / h); h = maxSide; }
                    }
                    var canvas = document.createElement("canvas");
                    canvas.width = w; canvas.height = h;
                    canvas.getContext("2d").drawImage(img, 0, 0, w, h);
                    canvas.toBlob(function(blob) {
                        var reader2 = new FileReader();
                        reader2.onload = function(e2) {
                            videoRefImageB64 = e2.target.result;
                            videoShowRefPreview(e2.target.result, file.name);
                        };
                        reader2.readAsDataURL(blob);
                    }, "image/jpeg", 0.85);
                };
                img.src = e0.target.result;
            };
            reader0.readAsDataURL(file);
        });
    }
});

function videoSubmitWrapper(btn) {
    if (!videoRefImageB64) {
        return videogenerate(btn);
    }

    var input = document.getElementById("ai-box");
    var value = input ? input.value.trim() : "";
    if (!value) {
        alert("Please enter a prompt before generating video.");
        return false;
    }

    btn.disabled = true;
    if (input) input.disabled = true;
    btn.classList.add("loading");
    var statusDiv = document.getElementById("video-status");
    if (statusDiv) statusDiv.style.display = "block";

    var model  = (document.querySelector("input[name=\'aimodel\']:checked") || {}).value || "veo3f";
    var aisize = (document.querySelector("input[name=\'aisize\']:checked")  || {}).value || "16:9";
    var reso   = (document.querySelector("input[name=\'reso\']:checked")    || {}).value || "540p";
    var imageid_el = document.getElementById("news_thumb");

    var fd = new FormData();
    fd.append("qa_operation", "aivideo");
    fd.append("qa_request",   "videoai");
    fd.append("qa_root",      videoai_qa_root);
    fd.append("input",        value);
    fd.append("model",        model);
    fd.append("radio",        aisize);
    fd.append("reso",         reso);
    fd.append("imageid",      imageid_el ? imageid_el.value : "");
    fd.append("ref_image_b64", videoRefImageB64);

    var aierror = document.getElementById("ai-error");
    if (aierror) { aierror.style.display = "none"; aierror.innerHTML = ""; }

    function resetUI() {
        btn.disabled = false;
        if (input) input.disabled = false;
        btn.classList.remove("loading");
        if (statusDiv) statusDiv.style.display = "none";
    }

    var xhr = new XMLHttpRequest();
    xhr.open("POST", videoai_ajax_url, true);
    xhr.timeout = 900000;
    xhr.ontimeout = resetUI;
    xhr.onerror   = resetUI;
    xhr.onload = function() {
        resetUI();
        var text = (xhr.responseText || "").trim();
        if (text.indexOf("QA_AJAX_RESPONSE\n") === 0) text = text.substring(17);
        var lines = text.split("\n");
        var success = (lines[0] === "1");
        if (success) {
            try {
                var response = JSON.parse(lines[1]);
                if (response.success) {
                    var resultsDiv = document.getElementById("ai-results");
                    if (resultsDiv && lines.length > 2) resultsDiv.innerHTML = lines.slice(2).join("\n");
                    if (input) { input.value = ""; if (typeof adjustHeight === "function") { try { adjustHeight(input); } catch(e) {} } }
                    if (response.videourl && typeof generateVideoThumbnail === "function") {
                        generateVideoThumbnail(response.videourl, function(thumb) {
                            if (typeof qa_ajax_post === "function") {
                                qa_ajax_post("aividthumb", { thumb: thumb, postid: response.postid }, function(){});
                            }
                        });
                    }
                    // Update navbar coin count
                    if (typeof ebonixRefreshCoinDisplay === "function" && typeof response.coins_remaining !== "undefined") {
                        ebonixRefreshCoinDisplay(response.coins_remaining);
                    }
                    ebxUpdateVideoCostPreview();
                    // Show toast notification instead of alert
                    (function() {
                        var toast = document.createElement("div");
                        toast.style.cssText = "position:fixed;bottom:24px;left:50%;transform:translateX(-50%);background:#22c55e;color:#fff;padding:12px 22px;border-radius:12px;font-weight:700;font-size:14px;z-index:9999;box-shadow:0 4px 20px rgba(0,0,0,.18);";
                        toast.innerHTML = "\u2705 Video generated! " + (response.coins_deducted ? "(" + response.coins_deducted + " coins used)" : "");
                        document.body.appendChild(toast);
                        setTimeout(function() { toast.style.opacity = "0"; toast.style.transition = "opacity .4s"; setTimeout(function(){ toast.remove(); }, 450); }, 3500);
                    })();
                } else {
                    // Check for coin errors first
                    if (typeof ebonixHandleCoinError === "function" && ebonixHandleCoinError(response.message, response)) {
                        // Modal shown
                    } else if (aierror) {
                        aierror.style.display = "block";
                        aierror.innerHTML = "\u274c " + (response.message || "Video generation failed");
                    }
                }
            } catch(e) {
                if (aierror) { aierror.style.display = "block"; aierror.innerHTML = "\u274c " + e.message; }
            }
        } else {
            try {
                var errResp = JSON.parse(lines[1]);
                if (typeof ebonixHandleCoinError === "function" && ebonixHandleCoinError(errResp.message, errResp)) {
                    // Modal shown
                } else if (aierror) {
                    aierror.style.display = "block";
                    aierror.innerHTML = "\u274c " + (errResp.message || "Video generation failed");
                }
            } catch(e) {
                if (aierror) { aierror.style.display = "block"; aierror.innerHTML = "\u274c Server error"; }
            }
        }
    };
    xhr.send(fd);
    return false;
}
</script>';

$qa_content['form'] = array(
	'tags'    => 'name="ask" method="post" action="' . qa_self_html() . '" id="ai-form"',

	'style'   => 'tall',

	'fields'  => array(
		'close'    => array(
			'type' => 'custom',
			'html' => '<span onclick="aipublish(this)" class="aisclose"><i class="fa-solid fa-xmark"></i></span>',
		),
		'errorc'    => array(
			'type' => 'custom',
			'html' => '<div id="error-container"></div>',
		),		
		
		'title'     => array(
			'label' => qa_lang_html('question/q_title_label'),
			'tags'  => 'name="title" id="title" autocomplete="off" minlength="'.qa_opt('min_len_q_title').'"  required',
			'value' => qa_html(@$in['title']),
			'error' => qa_html(@$errors['title']),
		),

		'similar'   => array(
			'type' => 'custom',
			'html' => '<span id="similar"></span>',
		),
		'uniqueid'  => array(
			'label' => '',
			'tags'  => 'name="uniqueid" id="uniqueid" class="hide"',
		),


	),

	'buttons' => array(
		'ask' => array(
			'tags'  => 'onclick="submitAiform(event);" id="submitButton"',
			'label' => qa_lang_html('question/ask_button'),
		),
	),

	'hidden'  => array(
		'code'   => qa_get_form_security_code('ask'),
		'doask'  => '1',
	),
);

script_options($qa_content);
if (!strlen($custom)) {
	unset($qa_content['form']['fields']['custom']);
}

if (qa_opt('do_ask_check_qs') || qa_opt('do_example_tags')) {
	$qa_content['script_rel'][] = 'king-content/king-ask.js?' . QA_VERSION;
	$qa_content['form']['fields']['title']['tags'] .= ' onchange="qa_title_change(this.value);"';

	if (strlen(@$in['title'])) {
		$qa_content['script_onloads'][] = 'qa_title_change(' . qa_js($in['title']) . ');';
		

	}

}
$qa_content['script_var']['leoai'] = qa_path('submitai_ajax');
$qa_content['script_var']['videoai_ajax_url'] = $king_ajax_url_video;
$qa_content['script_var']['videoai_qa_root']  = rtrim((string)qa_opt('site_url'), '/') . '/';


if (isset($followanswer)) {
	$viewer = qa_load_viewer($followanswer['content'], $followanswer['format']);

	$field = array(
		'type'  => 'static',
		'label' => qa_lang_html('question/ask_follow_from_a'),
		'value' => $viewer->get_html($followanswer['content'], $followanswer['format'], array('blockwordspreg' => qa_get_block_words_preg())),
	);

	qa_array_insert($qa_content['form']['fields'], 'title', array('follows' => $field));
}

if (qa_using_categories() && count($categories)) {
	$field = array(
		'label' => qa_lang_html('question/q_category_label'),
		'error' => qa_html(@$errors['categoryid']),
	);

	qa_set_up_category_field($qa_content, $field, 'category', $categories, $in['categoryid'], true, qa_opt('allow_no_sub_category'));

	if (!qa_opt('allow_no_category')) // don't auto-select a category even though one is required
	{
		$field['options'][''] = '';
	}

	qa_array_insert($qa_content['form']['fields'], 'similar', array('category' => $field));
}


if (qa_using_tags()) {
	$field = array(
		'error' => qa_html(@$errors['tags']),
	);



	qa_set_up_tag_field($qa_content, $field, 'tags', isset($in['tags']) ? $in['tags'] : array(), array(),
		qa_opt('do_complete_tags') ? array_keys($completetags) : array(), qa_opt('page_size_ask_tags'));

	qa_array_insert($qa_content['form']['fields'], null, array('tags' => $field));

}


if ( qa_opt('enable_nsfw') || qa_opt('enable_pposts') ) {
	$nsfw = '';
	$prvt = '';
	if ( qa_opt('enable_pposts') ) {
		$prvt = '<input name="prvt" id="king_prvt" type="checkbox" class="hide" value="'.qa_html(@$in['prvt']).'"><label for="king_prvt" class="king-nsfw"><i class="fa-solid fa-user-ninja"></i> '.qa_lang('misc/prvt').'</label>';
	}
	if ( qa_opt('enable_nsfw') ) {
		$nsfw = '<input name="nsfw" id="king_nsfw" type="checkbox" value="'.qa_html(@$in['nsfw']).'"><label for="king_nsfw" class="king-nsfw">'.qa_lang_html('misc/nsfw').'</label>';
	}
	$field = array(
		'type' => 'custom',
		'html' => ''.$prvt.$nsfw.''
	);
	qa_array_insert($qa_content['form']['fields'], null, array('nsfw' => $field));
}

if (!isset($userid)) {
	qa_set_up_name_field($qa_content, $qa_content['form']['fields'], @$in['name']);
}


if ($captchareason) {
	require_once QA_INCLUDE_DIR . 'king-app/captcha.php';
	qa_set_up_captcha_field($qa_content, $qa_content['form']['fields'], @$errors, qa_captcha_reason_note($captchareason));
}

} else {
	$cont2  = '<div class="kingai-input">';
	$cont2 .= '<textarea type="textarea" id="ai-box" class="aiinput" data-toggle="modal" data-target="#loginmodal" placeholder="'.qa_lang('misc/aiplace').'" maxlength="600" autocomplete="off" style="height: 44px;" rows="1"></textarea>';
	$cont2 .= '<div class="kingai-buttons">';

	$cont2 .= '<button type="button" id="ai-submit" class="ai-submit" data-toggle="modal" data-target="#loginmodal">
<span><i class="fa-solid fa-paper-plane"></i> '.qa_lang('misc/generate').'</span><div class="loader"></div></button>';
	$cont2 .= '</div>';
	$cont2 .= '</div>';
	$qa_content['custom'] = $cont2;

}
$qa_content['class']=' ai-create';
$qa_content['focusid'] = 'ai-box';

return $qa_content;
/*
Omit PHP closing tag to help avoid accidental output
 */
