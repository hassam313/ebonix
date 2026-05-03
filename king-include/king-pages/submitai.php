<?php
/*
 * File: king-include/king-pages/submitai.php
 */

if (!defined('QA_VERSION')) { header('Location: ../'); exit; }

set_time_limit(600);
ini_set('max_execution_time', 300);
ini_set('memory_limit', '512M');

require_once QA_INCLUDE_DIR . 'king-app/format.php';
require_once QA_INCLUDE_DIR . 'king-app/limits.php';
require_once QA_INCLUDE_DIR . 'king-db/selects.php';
require_once QA_INCLUDE_DIR . 'king-util/sort.php';
require_once QA_INCLUDE_DIR . 'king-db/metas.php';
require_once QA_INCLUDE_DIR . 'king-app/posts.php';

$in               = array();
$followpostid     = qa_get('follow');
$in['categoryid'] = qa_clicked('doask') ? qa_get_category_field_value('category') : qa_get('cat');
$userid           = qa_get_logged_in_userid();
$handle           = qa_get_logged_in_handle();

list($categories, $followanswer, $completetags) = qa_db_select_with_pending(
    qa_db_category_nav_selectspec($in['categoryid'], true),
    isset($followpostid) ? qa_db_full_post_selectspec($userid, $followpostid) : null,
    qa_db_popular_tags_selectspec(0, QA_DB_RETRIEVE_COMPLETE_TAGS)
);

if (!isset($categories[$in['categoryid']])) $in['categoryid'] = null;
if (@$followanswer['basetype'] != 'A')       $followanswer    = null;

$permiterror = qa_user_maximum_permit_error('permit_post_q', QA_LIMIT_QUESTIONS);

if ($permiterror && qa_clicked('doask')) {
    $errors              = array();
    $errors['permiterror'] = qa_lang_html('question/ask_limit');
    $response['status']  = 'error';
    $response['message'] = $errors;
    echo json_encode($response);
    exit;
}

if ($permiterror || !qa_opt('king_leo_enable')) {
    $qa_content = qa_content_prepare();
    switch ($permiterror) {
        case 'login':
            $econtent = qa_insert_login_links(qa_lang_html('question/ask_must_login'), qa_request(),
                isset($followpostid) ? array('follow' => $followpostid) : null);
            break;
        case 'confirm':
            $econtent = qa_insert_login_links(qa_lang_html('question/ask_must_confirm'), qa_request(),
                isset($followpostid) ? array('follow' => $followpostid) : null);
            break;
        case 'limit':
            $econtent = qa_lang_html('question/ask_limit');
            break;
        case 'membership':
            $econtent = qa_insert_login_links(qa_lang_html('misc/mem_message'));
            $qa_content['custom'] = '<div class="nopost"><i class="fa-solid fa-fingerprint fa-4x"></i><p>' . $econtent . '</p>'
                . '<a href="' . qa_path_html('membership') . '" class="meme-button">' . qa_lang_html('misc/see_plans') . '</a></div>';
            break;
        case 'approve':
            $econtent = qa_lang_html('question/ask_must_be_approved');
            break;
        default:
            $econtent = qa_lang_html('users/no_permission');
            break;
    }
    if (empty($qa_content['custom'])) {
        $qa_content['custom'] = '<div class="nopost"><i class="fa-solid fa-circle-user fa-4x"></i>' . $econtent . '</div>';
    }
    return $qa_content;
}

if (qa_clicked('doask')) {
    require_once QA_INCLUDE_DIR . 'king-app/post-create.php';
    require_once QA_INCLUDE_DIR . 'king-app/post-update.php';
    require_once QA_INCLUDE_DIR . 'king-util/string.php';

    $in['postid'] = qa_post_text('uniqueid');
    $post         = qa_db_select_with_pending(qa_db_full_post_selectspec($userid, $in['postid']));
    $categoryids  = array_keys(qa_category_path($categories, @$in['categoryid']));
    $userlevel    = qa_user_level_for_categories($categoryids);
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
        // Clear ALL title errors — AI posts accept any title format
        unset($errors['title']);

        // Also ensure title is never empty — fall back to prompt text
        if (empty(trim((string)$in['title']))) {
            $in['title'] = !empty($input) ? $input : 'AI Generated Image';
        }

        if (qa_using_categories() && count($categories)
            && (!qa_opt('allow_no_category')) && !isset($in['categoryid'])) {
            $errors['categoryid'] = qa_lang_html('question/category_required');
        } elseif (qa_user_permit_error('permit_post_q', null, $userlevel)) {
            $errors['categoryid'] = qa_lang_html('question/category_ask_not_allowed');
        }
        if (empty($errors)) {
            $cookieid = isset($userid) ? qa_cookie_get() : qa_cookie_get_create();
            king_update_ai_post($in['postid'], $in['title'],
                isset($in['tags']) ? qa_tags_to_tagstring($in['tags']) : '',
                $in['nsfw'], 'I');
            $answers         = qa_post_get_question_answers($in['postid']);
            $commentsfollows = qa_post_get_question_commentsfollows($in['postid']);
            $closepost       = qa_post_get_question_closepost($in['postid']);
            if (qa_using_categories() && isset($in['categoryid'])) {
                qa_question_set_category($post, $in['categoryid'], $userid, $handle,
                    $cookieid, $answers, $commentsfollows, $closepost, false);
            }
            if (isset($in['prvt'])) qa_post_set_hidden($in['postid'], true, null);
            $response['status']   = 'success';
            $response['message']  = qa_lang_html('misc/published');
            $response['url']      = qa_q_request($in['postid'], $in['title']);
            $response['message2'] = qa_lang_html('misc/seep');
        } else {
            $response['status']  = 'error';
            $response['message'] = $errors;
        }
        echo json_encode($response);
        exit;
    }
}

if (qa_is_logged_in()
    && (qa_opt('ailimits') || qa_opt('ulimits'))
    && qa_get_logged_in_level() <= QA_USER_LEVEL_ADMIN
    && qa_opt('enable_membership')) {
    $qa_content = qa_content_prepare();
    $mp  = qa_db_usermeta_get($userid, 'membership_plan');
    $pl  = null;
    if ($mp) {
        $pl = (int)qa_opt('plan_' . $mp . '_lmt');
    } elseif (qa_opt('ulimits')) {
        $pl = (int)qa_opt('ulimit');
    }
    $alm = (int)qa_db_usermeta_get($userid, 'ailmt');
    if ($alm >= $pl) {
        $qa_content['custom'] = '<div class="nopost"><i class="fa-solid fa-circle-user fa-4x"></i>'
            . qa_lang('misc/nocredits')
            . '<p><a href="' . qa_path_html('membership') . '">' . qa_lang('misc/buycredits') . '</a></p></div>';
        return $qa_content;
    }
}

$qa_content          = qa_content_prepare(false,
    array_keys(qa_category_path($categories, @$in['categoryid'])));
$qa_content['title'] = qa_lang_html('main/image');
$qa_content['error'] = @$errors['page'];

$qa_content['head_lines'][] = '<script>if(typeof Dropzone!=="undefined"){Dropzone.autoDiscover=false;}</script>';

$captchareason = qa_user_captcha_reason();
$in['title']   = qa_get_post_title('title');
if (qa_using_tags()) $in['tags'] = qa_get_tags_field_value('tags');

$custom = qa_opt('show_custom_ask') ? trim(qa_opt('custom_ask')) : '';

// ═══════════════════════════════════════════════════════════════════════════
// MODEL DEFINITIONS
// i2i = true  → shows the image upload button in the toolbar
// selfie = true → shows selfie presets instead of standard size/style tabs
// ═══════════════════════════════════════════════════════════════════════════
$models = array(
    'ebonix_10'       => array('enabled' => qa_opt('enable_ebonix_10'),       'label' => 'Ebonix Images 1.0',      'i2i' => true,  'selfie' => false),
    'ebonix_20'       => array('enabled' => qa_opt('enable_ebonix_20'),       'label' => 'Ebonix Images 2.0',      'i2i' => true,  'selfie' => false),
    'ebonix_classic'  => array('enabled' => qa_opt('enable_ebonix_classic'),  'label' => 'Ebonix Images Classic',  'i2i' => true,  'selfie' => false),
    'ebonix_advanced' => array('enabled' => qa_opt('enable_ebonix_advanced'), 'label' => 'Ebonix Images Advanced', 'i2i' => false, 'selfie' => false),
    'ebonix_flash'    => array('enabled' => qa_opt('enable_ebonix_flash'),    'label' => 'Ebonix Images Flash',    'i2i' => false, 'selfie' => false),
    'ebonix_pro'      => array('enabled' => qa_opt('enable_ebonix_pro'),      'label' => 'Ebonix Images Pro',      'i2i' => false, 'selfie' => false),
    'ebonix_studio'   => array('enabled' => qa_opt('enable_ebonix_studio'),   'label' => 'Ebonix Images Studio',   'i2i' => true,  'selfie' => false),
    'fluxkon_selfie'  => array(
        'enabled' => qa_opt('enable_fluxkon_selfie'),
        'label'   => (qa_lang('misc/fluxkon_selfie') ?: 'AI Selfie Looks'),
        'i2i'     => true,
        'selfie'  => true,
    ),
);

$enabled_models = array();
foreach ($models as $key => $data) {
    if (!empty($data['enabled'])) $enabled_models[$key] = $data;
}
if (empty($enabled_models)) $enabled_models = $models;

$i2i_models    = array_keys(array_filter($enabled_models, function ($m) { return !empty($m['i2i']); }));
$selfie_models = array_keys(array_filter($enabled_models, function ($m) { return !empty($m['selfie']); }));

reset($enabled_models);
$first_model_key   = key($enabled_models);
$first_model_label = $enabled_models[$first_model_key]['label'] ?? '';

// ═══════════════════════════════════════════════════════════════════════════
// BUILD SETTINGS PANEL HTML
// ═══════════════════════════════════════════════════════════════════════════
$context  = '';
$context .= '<div id="chclass" class="' . qa_html($first_model_key) . '">';
$context .= '<div class="kingai-ext">';
$context .= '<div class="ail-settings">';

// Model dropdown
$context .= '<div class="king-dropdownup custom-select hveo">';
$context .= '<div class="king-sbutton kings-button" id="aimodelbtn" data-toggle="dropdown"'
    . ' aria-expanded="false" role="button">' . qa_html($first_model_label) . '</div>';
$context .= '<div class="king-dropdownc king-dropleft aimodels">';
foreach ($enabled_models as $key => $data) {
    $checked = ($key === $first_model_key) ? 'checked' : '';
    $badge   = ($key === 'fluxkon_selfie') ? ' 📸' : '';
    $context .= '<label class="cradio">'
        . '<input type="radio" name="aimodel" value="' . qa_html($key) . '" class="hide" '
        . $checked . ' onclick="updateModelLabel(this)">'
        . '<span>' . qa_html($data['label']) . $badge . '</span>'
        . '</label>';
}
$context .= '</div></div>'; // dropdown
$context .= '</div>'; // .ail-settings

// Standard size / style tabs
$context .= '<div id="desizes">';
$context .= '<ul class="nav nav-tabs" id="ssize">'
    . '<li class="active"><a href="#aisizes" data-toggle="tab">' . qa_lang('misc/aisizes') . '</a></li>'
    . '<li class="sdsize"><a href="#aistyles" data-toggle="tab">' . qa_lang('misc/ai_filter') . '</a></li>';
if (qa_opt('enprompt')) {
    $context .= '<li class="sdsize"><a href="#nprompt" data-toggle="tab">' . qa_lang('misc/ai_nprompt') . '</a></li>';
}
$context .= '</ul>';

$context .= '<div id="aisizes" role="tabpanel" class="tabcontent aistyles active">';
$sizes = array(
    'aisize9'  => array('1344x768',  'ailabel sdsize',         'widescreen', '16:9'),
    'aisize4'  => array('1152x896',  'ailabel sdsize',         'landscape',  '5:4'),
    'aisize10' => array('1792x1024', 'ailabel desize3',        'widescreen', '7:4'),
    'aisize1'  => array('512x512',   'ailabel desize',         'square',     '1:1'),
    'aisize3'  => array('1024x1024', 'ailabel',                'square',     '1:1'),
    'aisize11' => array('1024x1792', 'ailabel desize3',        'vertical',   '4:7'),
    'aisize8'  => array('896x1152',  'ailabel sdsize',         'portrait',   '4:5'),
    'aisize5'  => array('832x1216',  'ailabel sdsize aisize8', 'vertical',   '2:3'),
    'aisize7'  => array('768x1344',  'ailabel sdsize',         'long',       '9:16'),
);
foreach ($sizes as $id => $s) {
    $checked = ($id === 'aisize3') ? 'checked' : '';
    $sq = '';
    if ($id === 'aisize4')                          $sq = ' s2';
    elseif ($id === 'aisize11' || $id === 'aisize7') $sq = ' s5';
    elseif ($id === 'aisize8' || $id === 'aisize5')  $sq = ' s4';
    $context .= '<input type="radio" id="' . $id . '" name="aisize" value="' . $s[0] . '" class="hide" ' . $checked . '>';
    $context .= '<label for="' . $id . '" class="' . $s[1] . '" title="' . $s[0] . '" data-toggle="tooltip">'
        . '<i class="king-square' . $sq . '"></i> '
        . qa_lang('misc/' . $s[2]) . ' (' . $s[3] . ')</label>';
}
$context .= '</div>'; // #aisizes

if (qa_opt('enprompt')) {
    $context .= '<div id="nprompt" role="tabpanel" class="tabcontent aistyles">';
    $context .= '<textarea name="nprompt" id="n_prompt" rows="2" cols="40" class="king-form-tall-text"'
        . ' placeholder="' . qa_lang('misc/ai_nprompt') . '"></textarea>';
    $context .= '</div>';
}

$context .= '<div id="aistyles" role="tabpanel" class="tabcontent aistyles">';
foreach (array('none','3d-model','analog-film','anime','cinematic','comic-book','digital-art',
               'fantasy-art','isometric','line-art','low-poly','neon-punk','origami',
               'photographic','pixel-art') as $style) {
    $context .= '<input type="radio" id="aistyle_' . $style . '" name="aistyle" value="' . $style . '" class="hide">';
    $context .= '<label for="aistyle_' . $style . '" class="ailabel">' . $style . '</label>';
}
$context .= '</div>'; // #aistyles
$context .= '</div>'; // #desizes

// Selfie style presets
$selfie_presets = array(
    'selfie_luxury_editorial' => array('icon' => 'fa-crown',     'label' => 'Luxury Editorial', 'desc' => 'High-end magazine aesthetic'),
    'selfie_soft_glam'        => array('icon' => 'fa-heart',     'label' => 'Soft Glam',        'desc' => 'Natural glam beauty look'),
    'selfie_professional'     => array('icon' => 'fa-briefcase', 'label' => 'Professional',     'desc' => 'Corporate headshot quality'),
    'selfie_vacation'         => array('icon' => 'fa-sun',       'label' => 'Vacation',         'desc' => 'Golden hour travel vibes'),
    'selfie_afro_futurist'    => array('icon' => 'fa-star',      'label' => 'Afro-Futurist',    'desc' => 'Cultural sci-fi fusion'),
);

$context .= '<div id="selfie-presets-panel" style="display:none;">';
$context .= '<div class="selfie-presets-header"><i class="fa-solid fa-camera-retro"></i> Choose your look:</div>';
$context .= '<div class="selfie-presets-grid">';
$first_sp = true;
foreach ($selfie_presets as $spkey => $spdata) {
    $sid     = 'selfie_preset_' . $spkey;
    $checked = $first_sp ? 'checked' : '';
    $active  = $first_sp ? ' selfie-active' : '';
    $context .= '<label class="selfie-preset-item' . $active . '" for="' . $sid . '">';
    $context .= '<input type="radio" id="' . $sid . '" name="aistyle" value="' . $spkey . '" class="hide" ' . $checked . '>';
    $context .= '<i class="fa-solid ' . $spdata['icon'] . '"></i>';
    $context .= '<span class="selfie-preset-label">' . $spdata['label'] . '</span>';
    $context .= '<span class="selfie-preset-desc">' . $spdata['desc'] . '</span>';
    $context .= '</label>';
    $first_sp = false;
}
$context .= '</div></div>'; // selfie-presets-grid / selfie-presets-panel

$context .= '</div>'; // .kingai-ext
$context .= '</div>'; // #chclass

// Results area
$context .= '<div id="ai-results">' . king_ai_posts($userid, 'aimg') . '</div>';

$king_ajax_url = rtrim((string)qa_opt('site_url'), '/') . '/king-include/king-ajax.php';

// ═══════════════════════════════════════════════════════════════════════════
// LOGGED-IN UI
// ═══════════════════════════════════════════════════════════════════════════
if (qa_is_logged_in()) {
    $cont = '';

    if (qa_opt('king_leo_enable') && qa_opt('enable_aivideo')) {
        $cont .= '<ul class="king-nav-kingsub-list" id="nav-kingsub">';
        $cont .= '<li class="king-nav-kingsub-item"><a href="' . qa_path_html('submitai') . '" class="king-nav-kingsub-selected"><i class="fa-regular fa-image"></i> ' . qa_lang_html('misc/king_ai') . '</a></li>';
        $cont .= '<li class="king-nav-kingsub-item"><a href="' . qa_path_html('videoai') . '"><i class="fa-regular fa-circle-play"></i> ' . qa_lang_html('misc/king_aivid') . '</a></li>';
        if (qa_opt('enable_aitwin')) {
            $cont .= '<li class="king-nav-kingsub-item"><a href="' . qa_path_html('aitwin') . '"><i class="fa-regular fa-user-circle"></i> AI Twin</a></li>';
        }
        if (qa_opt('enable_aichat')) {
            $cont .= '<li class="king-nav-kingsub-item"><a href="' . qa_path_html('aichat') . '"><i class="fa-regular fa-comment-dots"></i> AI Chat</a></li>';
        }
        $cont .= '</ul>';
    }

    // ── Twin Gallery ─────────────────────────────────────────────────────────
    $gallery_twins = [];
    try {
        qa_db_query_sub(
            'CREATE TABLE IF NOT EXISTS `^king_twins` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `user_id` int(11) NOT NULL,
              `image_url` text NOT NULL,
              `vibe` varchar(64) NOT NULL DEFAULT \'\',
              `format` varchar(16) NOT NULL DEFAULT \'4:5\',
              `details` text,
              `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `user_id` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $gallery_twins = qa_db_read_all_assoc(
            qa_db_query_sub(
                'SELECT id, image_url, thumbnail_url, vibe, created_at FROM ^king_twins WHERE user_id=# ORDER BY created_at DESC LIMIT 12',
                (int)$userid
            )
        );
    } catch (Exception $e) { $gallery_twins = []; }

    $cont .= '<div class="twin-gallery-wrap" id="twin-gallery-wrap">';
    $cont .= '<div class="twin-gallery-header">';
    $cont .= '<i class="fa-solid fa-clone"></i> My Twins';
    $cont .= '<a href="' . qa_path_html('aitwin') . '" class="twin-gallery-view-all">View all</a>';
    $cont .= '</div>';
    $cont .= '<div class="twin-gallery-scroll" id="twin-gallery-scroll">';
    if (!empty($gallery_twins)) {
        foreach ($gallery_twins as $gt) {
            $vibe_esc   = qa_html($gt['vibe']);
            // thumbnail_url for fast display; image_url (full CDN) for generation reference
            $thumb_src  = !empty($gt['thumbnail_url']) ? $gt['thumbnail_url'] : $gt['image_url'];
            $fetch_url  = !empty($gt['thumbnail_url']) ? $gt['thumbnail_url'] : $gt['image_url'];
            $url_js     = addslashes($fetch_url);
            $vibe_js    = addslashes($gt['vibe']);
            $cont .= '<div class="twin-gallery-item" data-url="' . qa_html($fetch_url) . '" data-vibe="' . $vibe_esc . '"';
            $cont .= ' onclick="twinGallerySelect(this,\'' . $url_js . '\',\'' . $vibe_js . '\')">';
            $cont .= '<img src="' . qa_html($thumb_src) . '" alt="' . $vibe_esc . '" loading="eager" decoding="async">';
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
    // ── End Twin Gallery ─────────────────────────────────────────────────────

    $cont .= '<div class="kingai-box active">';
    $cont .= '<div class="king-form-tall-error" id="ai-error" style="display:none;"></div>';
    if ($custom) $cont .= '<div class="snote">' . $custom . '</div>';

    // ── Image preview chip (shows above prompt when an image is attached) ───
    $cont .= '<div id="ref-image-preview-wrap" style="display:none;">';
    $cont .= '<div class="ref-img-chip">';
    $cont .= '<img id="ref-image-thumb" src="" alt="preview">';
    $cont .= '<span id="ref-image-chipname"></span>';
    $cont .= '<button type="button" class="ref-img-chip-remove" onclick="clearRefImage()" title="Remove image">'
        . '<i class="fa-solid fa-xmark"></i></button>';
    $cont .= '</div>';
    $cont .= '</div>'; // #ref-image-preview-wrap

    // ── Scene / Background picker ─────────────────────────────────────────────
    $_ebx_img = rtrim(qa_opt('site_url'), '/') . '/king-include/uploads/Ebonix_Images/';
    $scenes = [
        ['key' => 'studio-dark',  'label' => 'Editorial',   'prompt' => 'professional dark studio backdrop, moody lighting',
         'img' => $_ebx_img . 'Editorial.jpeg'],
        ['key' => 'studio-white', 'label' => 'Studio',      'prompt' => 'clean white seamless studio backdrop, soft even lighting',
         'img' => $_ebx_img . 'Studio.jpeg'],
        ['key' => 'golden-hour',  'label' => 'Golden Hour', 'prompt' => 'golden hour outdoor, warm sunlight, bokeh background',
         'img' => $_ebx_img . 'Dreamworld.jpeg'],
        ['key' => 'city-night',   'label' => 'City Night',  'prompt' => 'city night background, neon lights, urban street scene',
         'img' => $_ebx_img . 'City_night.jpeg'],
        ['key' => 'luxury-room',  'label' => 'Luxury',      'prompt' => 'luxury penthouse interior, marble floors, floor-to-ceiling windows',
         'img' => $_ebx_img . 'Home_Body.jpeg'],
        ['key' => 'beach',        'label' => 'Beach',       'prompt' => 'tropical beach background, ocean waves, golden sand',
         'img' => $_ebx_img . 'Vacay.jpeg'],
        ['key' => 'garden',       'label' => 'Botanical',   'prompt' => 'lush botanical garden, flowering archway, natural sunlight',
         'img' => $_ebx_img . 'Botanical.jpeg'],
        ['key' => 'event',        'label' => 'Event',       'prompt' => 'upscale event venue, soft uplighting, elegant party setting',
         'img' => $_ebx_img . 'Spotlight.jpeg'],
        ['key' => 'rooftop',      'label' => 'Rooftop',     'prompt' => 'rooftop setting, city skyline at sunset, atmospheric haze',
         'img' => $_ebx_img . 'Rooftop.jpeg'],
        ['key' => 'desert',       'label' => 'Desert',      'prompt' => 'desert landscape background, warm sand dunes, dramatic sky',
         'img' => $_ebx_img . 'Desert.jpeg'],
    ];

    $cont .= '<div class="ebx-scene-section">';
    $cont .= '<div class="ebx-scene-label">Pick a scene</div>';
    $cont .= '<div class="ebx-scene-scroll">';
    foreach ($scenes as $scene) {
        $cont .= '<button type="button" class="ebx-scene-card" data-key="' . qa_html($scene['key']) . '" data-prompt="' . qa_html($scene['prompt']) . '" onclick="ebxSceneSelect(this)">';
        $cont .= '<img src="' . qa_html($scene['img']) . '" alt="' . qa_html($scene['label']) . '" class="ebx-scene-card-img" loading="lazy">';
        $cont .= '<span class="ebx-scene-card-label">' . qa_html($scene['label']) . '</span>';
        $cont .= '</button>';
    }
    $cont .= '</div>'; // .ebx-scene-scroll
    $cont .= '</div>'; // .ebx-scene-section

    // ── "Try this" style chips (accordion) ───────────────────────────────────
    $cont .= '<div class="ebx-style-chips-wrap" id="ebx-style-chips-wrap">';
    $cont .= '<button type="button" class="ebx-style-chips-toggle" id="ebx-chips-toggle" onclick="ebxToggleChips(this)" aria-expanded="false">';
    $cont .= '<span class="ebx-style-chips-label">WHAT YOU FEELIN’ LIKE <span class="ebx-chips-count" id="ebx-chips-count"></span></span>';
    $cont .= '<i class="fa-solid fa-chevron-down ebx-chips-chevron"></i>';
    $cont .= '</button>';
    $cont .= '<div class="ebx-chips-body" id="ebx-chips-body">';

    // Women's hair & beauty
    $women_chips = [
        'soft-glam'      => ['label' => 'Soft Glam',    'prompt' => 'soft glam makeup, glowing skin, lash extensions, glossy nude lip'],
        'braids'         => ['label' => 'Braids',        'prompt' => 'long knotless box braids'],
        'locs'           => ['label' => 'Locs',          'prompt' => 'butterfly locs, goddess locs'],
        'wig'            => ['label' => 'Wig',           'prompt' => 'sleek lace-front wig, body wave'],
        'ponytail'       => ['label' => 'Ponytail',      'prompt' => 'sleek high ponytail'],
        'natural-hair'   => ['label' => 'Natural Hair',  'prompt' => '4C natural afro, coily texture'],
        'soft-makeup'    => ['label' => 'Soft Makeup',   'prompt' => 'dewy skin, soft eye, nude lip'],
        'bold-lip'       => ['label' => 'Bold Lip',      'prompt' => 'bold red lip, sharp contour'],
    ];
    // Men's grooming
    $men_chips = [
        'fade'           => ['label' => 'Fade',          'prompt' => 'low skin fade, sharp lineup'],
        'fresh-cut'      => ['label' => 'Fresh Cut',     'prompt' => 'fresh taper cut with shape-up'],
        'waves'          => ['label' => 'Waves',         'prompt' => '360 waves, fresh lineup'],
        'locs-man'       => ['label' => 'Locs',          'prompt' => 'medium dreadlocks, neat locs'],
        'beard'          => ['label' => 'Beard',         'prompt' => 'full beard fade, well-groomed beard'],
    ];
    // Universal vibes
    $vibe_chips = [
        'editorial'      => ['label' => 'Editorial',     'prompt' => 'bold editorial fashion, magazine quality'],
        'luxury'         => ['label' => 'Luxury',        'prompt' => 'luxury lifestyle, aspirational aesthetic'],
        'outdoor'        => ['label' => 'Outdoor',       'prompt' => 'golden hour outdoor, warm sunlight'],
        'afro-futurist'  => ['label' => 'Futurist',      'prompt' => 'futuristic, vibrant cultural futurism, sci-fi aesthetic'],
        'birthday'       => ['label' => 'Birthday',      'prompt' => 'birthday glam, celebratory, festive'],
    ];

    $cont .= '<div class="ebx-chip-group">';
    $cont .= '<span class="ebx-chip-group-label">Her</span>';
    foreach ($women_chips as $key => $chip) {
        $cont .= '<button type="button" class="ebx-style-chip" data-key="' . qa_html($key) . '" data-prompt="' . qa_html($chip['prompt']) . '" onclick="ebxChipToggle(this)">' . qa_html($chip['label']) . '</button>';
    }
    $cont .= '</div>';

    $cont .= '<div class="ebx-chip-group">';
    $cont .= '<span class="ebx-chip-group-label">Him</span>';
    foreach ($men_chips as $key => $chip) {
        $cont .= '<button type="button" class="ebx-style-chip" data-key="' . qa_html($key) . '" data-prompt="' . qa_html($chip['prompt']) . '" onclick="ebxChipToggle(this)">' . qa_html($chip['label']) . '</button>';
    }
    $cont .= '</div>';

    $cont .= '<div class="ebx-chip-group">';
    $cont .= '<span class="ebx-chip-group-label">Vibe</span>';
    foreach ($vibe_chips as $key => $chip) {
        $cont .= '<button type="button" class="ebx-style-chip" data-key="' . qa_html($key) . '" data-prompt="' . qa_html($chip['prompt']) . '" onclick="ebxChipToggle(this)">' . qa_html($chip['label']) . '</button>';
    }
    $cont .= '</div>';

    $cont .= '</div>'; // .ebx-chips-body
    $cont .= '</div>'; // .ebx-style-chips-wrap

    // ── Prompt area ──────────────────────────────────────────────────────────
    $cont .= '<div class="kingai-input">';
    $cont .= '<textarea id="ai-box" class="aiinput" oninput="adjustHeight(this)"'
        . ' placeholder="' . qa_lang('misc/aiplace') . '"'
        . ' maxlength="600" autocomplete="off" style="height:44px;" rows="1"></textarea>';

    $cont .= '<div class="kingai-buttons">';

    // Hidden real file input — triggered by the attach button
    $cont .= '<input type="hidden" id="news_thumb" name="news_thumb" value="">';
    $cont .= '<input type="file" id="ref_image" name="ref_image"'
        . ' accept="image/jpeg,image/png,image/webp,image/gif"'
        . ' class="aiupload-file-hidden">';

    // Attach image button (clip icon, only visible for i2i models)
    $cont .= '<button type="button" id="ref-image-btn" style="display:none;"'
        . ' class="king-sbutton ai-attach-btn" onclick="document.getElementById(\'ref_image\').click()"'
        . ' data-toggle="tooltip" title="' . qa_lang('misc/attach_ref_image') . '" data-placement="top">'
        . '<i class="fa-solid fa-paperclip"></i>'
        . '</button>';

    // Prompter button
    if (qa_opt('eprompter')) {
        $showElement = qa_opt('oaprompter') ? (qa_get_logged_in_level() >= QA_USER_LEVEL_ADMIN) : true;
        if ($showElement) {
            $cont .= '<button type="button" id="prompter" onclick="aipromter(this)" class="king-sbutton ai-create promter"'
                . ' data-toggle="tooltip" title="' . qa_lang('misc/prompter') . '" data-placement="left">'
                . '<i class="fa-solid fa-feather"></i><div class="loader"></div></button>';
        }
    }

    // Settings toggle
    $cont .= '<div class="king-sbutton" onclick="toggleSwitcher(\'.kingai-box\', this)" role="button">'
        . '<i class="fa-solid fa-sliders"></i></div>';

    // Generate button
    $cont .= '<button type="button" id="ai-submit" class="ai-submit" onclick="return aigenerate(this);">'
        . '<span><i class="fa-solid fa-paper-plane"></i> ' . qa_lang('misc/generate') . '</span>'
        . '<div class="loader"></div></button>';

    $cont .= '</div></div>'; // .kingai-buttons / .kingai-input

    // ── Add-on toggles (HD Export / Priority) ───────────────────────────────
    if (!function_exists('ebonix_get_addon_costs')) {
        require_once QA_INCLUDE_DIR . 'king-app/coins.php';
    }
    $_addon = ebonix_get_addon_costs();
    $cont .= '<div class="ebx-addon-row" id="ebx-addon-row">';
    $cont .= '<label class="ebx-addon-toggle"><input type="checkbox" id="ebx-addon-hd" name="addon_hd" value="1">'
        . '<span><i class="fa-solid fa-photo-film"></i> HD Export <em>+' . (int)$_addon['hd_export'] . ' coins</em></span></label>';
    $cont .= '<label class="ebx-addon-toggle"><input type="checkbox" id="ebx-addon-priority" name="addon_priority" value="1">'
        . '<span><i class="fa-solid fa-bolt"></i> Priority <em>+' . (int)$_addon['priority'] . ' coins</em></span></label>';
    $cont .= '</div>';

    // ── Coin cost preview bar ────────────────────────────────────────────────
    if (!function_exists('ebonix_get_coins')) {
        require_once QA_INCLUDE_DIR . 'king-app/coins.php';
    }
    $_uid_sub = qa_get_logged_in_userid();
    $_bal     = $_uid_sub ? ebonix_get_coins($_uid_sub) : 0;
    $_tiers_js = [];
    foreach (ebonix_get_photo_tiers() as $tk => $tv) {
        $_tiers_js[$tk] = ['coins' => $tv['coins'], 'label' => $tv['label']];
    }
    $cont .= '<div class="ebx-cost-preview" id="ebx-cost-preview">';
    $cont .= '<span class="ebx-cost-preview-icon"><i class="fa-solid fa-coins"></i></span>';
    $cont .= '<span id="ebx-cost-text">Select a model to see cost</span>';
    $cont .= '<span class="ebx-cost-balance" id="ebx-cost-balance">· ' . number_format($_bal) . ' coins remaining</span>';
    $cont .= '</div>';

    // ── Identity Protected badge (shown after generation) ───────────────────
    $cont .= '<div class="ebx-id-badge" id="ebx-id-badge" style="display:none;">'
        . '<i class="fa-solid fa-shield-halved"></i>'
        . '<span>Identity Protected by Ebonix — your features are preserved, only the style transforms.</span>'
        . '</div>';

    $cont .= $context;
    $cont .= '</div>'; // .kingai-box

    // ═══════════════════════════════════════════════════════════════════════
    // INLINE JS
    // ═══════════════════════════════════════════════════════════════════════
    $cont .= '<script>';
    $cont .= 'var EBONIX_I2I_MODELS    = ' . json_encode(array_values($i2i_models)) . ';';
    $cont .= 'var EBONIX_SELFIE_MODELS = ' . json_encode(array_values($selfie_models)) . ';';
    $cont .= 'var EBONIX_UPLOAD_URL    = ' . json_encode($king_ajax_url) . ';';
    $cont .= 'var ebonix_qa_root       = ' . json_encode(rtrim(qa_opt('site_url'), '/') . '/') . ';';
    $cont .= 'var EBONIX_PHOTO_TIERS   = ' . json_encode($_tiers_js) . ';';
    $cont .= 'var EBONIX_ADDON_HD      = ' . (int)$_addon['hd_export'] . ';';
    $cont .= 'var EBONIX_ADDON_PRI     = ' . (int)$_addon['priority'] . ';';

    $cont .= <<<'JS'

// ── updateModelLabel ─────────────────────────────────────────────────────────
function updateModelLabel(radioEl) {
    var btn = document.getElementById('aimodelbtn');
    if (btn) {
        var lbl = radioEl.closest('label');
        if (lbl) btn.textContent = (lbl.innerText || lbl.textContent || '').trim();
    }
    var ch = document.getElementById('chclass');
    if (ch) ch.className = radioEl.value;

    var aivsize = document.getElementById('aivsize');
    if (aivsize) aivsize.checked = true;
    var aivsizeb = document.getElementById('aivsizeb');
    if (aivsizeb) aivsizeb.textContent = '16:9';
    var firstTabLink = document.querySelector('#ssize li:first-child a');
    if (firstTabLink) firstTabLink.click();

    ebonixUpdateModelUI(radioEl.value);
}

// ── ebonixUpdateModelUI ──────────────────────────────────────────────────────
function ebonixUpdateModelUI(modelValue) {
    var attachBtn   = document.getElementById('ref-image-btn');
    var selfiePanel = document.getElementById('selfie-presets-panel');
    var desizes     = document.getElementById('desizes');
    var aiBox       = document.getElementById('ai-box');

    var supportsI2I = (EBONIX_I2I_MODELS.indexOf(modelValue) !== -1);
    var isSelfie    = (EBONIX_SELFIE_MODELS.indexOf(modelValue) !== -1);

    // Show/hide the paperclip attach button in the toolbar
    if (attachBtn) attachBtn.style.display = supportsI2I ? 'inline-flex' : 'none';

    // Selfie presets vs standard size/style tabs
    if (selfiePanel) selfiePanel.style.display = isSelfie ? 'block' : 'none';
    if (desizes)     desizes.style.display     = isSelfie ? 'none'  : 'block';

    // Prompt placeholder text
    if (aiBox) {
        aiBox.placeholder = isSelfie
            ? 'Describe any additional details (optional)\u2026'
            : 'JS_AIPLACE';
    }

    // Clear any attached image when switching to a non-i2i model
    if (!supportsI2I) clearRefImage();
}

// ── clearRefImage ────────────────────────────────────────────────────────────
function clearRefImage() {
    var fi = document.getElementById('ref_image');
    if (fi) fi.value = '';

    var nt = document.getElementById('news_thumb');
    if (nt) nt.value = '';

    var wrap = document.getElementById('ref-image-preview-wrap');
    if (wrap) wrap.style.display = 'none';

    var thumb = document.getElementById('ref-image-thumb');
    if (thumb) { thumb.src = ''; }

    var chip = document.getElementById('ref-image-chipname');
    if (chip) chip.textContent = '';

    // Reset attach button to default state
    var btn = document.getElementById('ref-image-btn');
    if (btn) btn.classList.remove('has-image');
}

// ── File input change: show preview chip ─────────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {

    var fileInput = document.getElementById('ref_image');

    if (fileInput) {
        fileInput.addEventListener('change', function () {
            var file = fileInput.files && fileInput.files[0];
            if (!file) { clearRefImage(); return; }

            // Show preview chip above prompt
            var reader = new FileReader();
            reader.onload = function (e) {
                var thumb = document.getElementById('ref-image-thumb');
                var chip  = document.getElementById('ref-image-chipname');
                var wrap  = document.getElementById('ref-image-preview-wrap');
                var btn   = document.getElementById('ref-image-btn');

                if (thumb) thumb.src = e.target.result;
                if (chip)  chip.textContent = file.name.length > 28
                    ? file.name.substring(0, 25) + '...'
                    : file.name;
                if (wrap) wrap.style.display = 'block';
                // Highlight the attach button to show image is loaded
                if (btn)  btn.classList.add('has-image');
            };
            reader.readAsDataURL(file);
        });
    }

    // Selfie preset active highlight
    document.addEventListener('change', function (e) {
        if (e.target && e.target.name === 'aistyle') {
            document.querySelectorAll('.selfie-preset-item').forEach(function (el) {
                el.classList.remove('selfie-active');
            });
            var closest = e.target.closest('.selfie-preset-item');
            if (closest) closest.classList.add('selfie-active');
        }
    });

    // Wire model radio change
    document.querySelectorAll('input[name="aimodel"]').forEach(function (radio) {
        radio.addEventListener('change', function () {
            if (this.checked) ebonixUpdateModelUI(this.value);
        });
    });

    // Initial UI state for default model
    var def = document.querySelector('input[name="aimodel"]:checked');
    if (def) ebonixUpdateModelUI(def.value);

    // ── Fix 3: MutationObserver — force-load lazy images after AJAX inject ──
    var resultsEl = document.getElementById('ai-results');
    if (resultsEl) {
        var lazyObserver = new MutationObserver(function (mutations) {
            var added = false;
            for (var i = 0; i < mutations.length; i++) {
                if (mutations[i].addedNodes.length > 0) { added = true; break; }
            }
            if (!added) return;

            // Force data-src → src for all lazy images
            resultsEl.querySelectorAll('img[data-src]').forEach(function (img) {
                var ds = img.getAttribute('data-src');
                if (ds && (!img.src || img.src === window.location.href || img.src === '')) {
                    img.src = ds;
                }
            });
            resultsEl.querySelectorAll('img[data-lazy-src]').forEach(function (img) {
                var ds = img.getAttribute('data-lazy-src');
                if (ds && (!img.src || img.src === window.location.href || img.src === '')) {
                    img.src = ds;
                }
            });
            // Also fix broken images whose src is set but empty/placeholder
            resultsEl.querySelectorAll('img').forEach(function (img) {
                if (img.dataset.src && (!img.complete || img.naturalWidth === 0)) {
                    img.src = img.dataset.src;
                }
            });
            // Refresh global lazy-load library instances if present
            if (window.lazyLoadInstance && typeof window.lazyLoadInstance.update === 'function') {
                window.lazyLoadInstance.update();
            }
            if (typeof jQuery !== 'undefined') {
                try { jQuery(resultsEl).find('img.lazy,img.lazyload').trigger('appear'); } catch(e) {}
            }
        });
        lazyObserver.observe(resultsEl, { childList: true, subtree: true });
    }
});

// ── Reuse / Regenerate payload ────────────────────────────────────────────────
(function () {
    function kingGetReusePayload() {
        var raw = null;
        try { raw = sessionStorage.getItem('king_ai_reuse'); } catch (e) {}
        if (!raw) return null;
        try {
            var data = JSON.parse(raw);
            try { sessionStorage.removeItem('king_ai_reuse'); } catch (e) {}
            return data;
        } catch (e) { return null; }
    }
    function kingSetTextarea(id, val) {
        var el = document.getElementById(id);
        if (!el) return;
        el.value = val || '';
        if (typeof adjustHeight === 'function') { try { adjustHeight(el); } catch (e) {} }
    }
    function kingSelectRadio(name, value) {
        if (!value) return false;
        var input = document.querySelector('input[name="' + name + '"][value="' + CSS.escape(value) + '"]');
        if (!input) return false;
        input.checked = true;
        try { input.dispatchEvent(new Event('change', { bubbles: true })); } catch (e) {}
        return true;
    }
    function kingSetImageModel(model) {
        if (!model) return;
        var input = document.querySelector('input[name="aimodel"][value="' + CSS.escape(model) + '"]');
        if (!input) return;
        input.checked = true;
        try { input.click(); } catch (e) {}
        var btn = document.getElementById('aimodelbtn');
        if (btn) {
            var lbl = input.closest('label');
            if (lbl) { var t = (lbl.innerText || '').trim(); if (t) btn.textContent = t; }
        }
        var ch = document.getElementById('chclass');
        if (ch) ch.className = model;
    }
    document.addEventListener('DOMContentLoaded', function () {
        var payload = kingGetReusePayload();
        if (!payload || (payload.isVideo && parseInt(payload.isVideo, 10) === 1)) return;
        if (payload.prompt)  kingSetTextarea('ai-box', payload.prompt);
        if (payload.model)   kingSetImageModel(payload.model);
        if (payload.size)    kingSelectRadio('aisize',  payload.size);
        if (payload.style)   kingSelectRadio('aistyle', payload.style);
        if (payload.nprompt) kingSetTextarea('n_prompt', payload.nprompt);
        var box = document.getElementById('ai-box');
        if (box) { try { box.focus(); } catch (e) {} }
    });
}());

// ── Twin Gallery: select item as reference image ─────────────────────────────
function twinGallerySelect(el, url, vibe) {
    // Highlight selected item
    document.querySelectorAll('.twin-gallery-item').forEach(function (item) {
        item.classList.remove('twin-gallery-selected');
    });
    el.classList.add('twin-gallery-selected');

    function injectBlob(blob) {
        var filename = 'twin-' + (vibe || 'ref') + '.jpg';
        var file = new File([blob], filename, { type: blob.type || 'image/jpeg' });
        var fileInput = document.getElementById('ref_image');
        if (!fileInput) return;

        if (window.DataTransfer) {
            var dt = new DataTransfer();
            dt.items.add(file);
            fileInput.files = dt.files;
        }

        // Show preview chip explicitly — don't rely only on the change event
        var reader = new FileReader();
        reader.onload = function (e) {
            var thumb = document.getElementById('ref-image-thumb');
            var chip  = document.getElementById('ref-image-chipname');
            var wrap  = document.getElementById('ref-image-preview-wrap');
            var btn   = document.getElementById('ref-image-btn');
            if (thumb) thumb.src = e.target.result;
            if (chip)  chip.textContent = filename;
            if (wrap)  wrap.style.display = 'block';
            if (btn)   btn.classList.add('has-image');
        };
        reader.readAsDataURL(file);

        fileInput.dispatchEvent(new Event('change', { bubbles: true }));
    }

    // Always use fetch→blob so the image is available as a File regardless of
    // canvas cross-origin tainting (Fal CDN supports CORS but canvas tainting
    // can still fail in some browsers depending on cache state).
    fetch(url)
        .then(function (r) { return r.blob(); })
        .then(function (blob) { injectBlob(blob); })
        .catch(function () {
            // Last resort: load via canvas
            var img = new Image();
            img.crossOrigin = 'anonymous';
            img.onload = function () {
                var maxSide = 1024;
                var w = img.naturalWidth, h = img.naturalHeight;
                if (w > maxSide || h > maxSide) {
                    if (w >= h) { h = Math.round(h * maxSide / w); w = maxSide; }
                    else        { w = Math.round(w * maxSide / h); h = maxSide; }
                }
                var canvas = document.createElement('canvas');
                canvas.width = w; canvas.height = h;
                canvas.getContext('2d').drawImage(img, 0, 0, w, h);
                canvas.toBlob(function (blob) { injectBlob(blob); }, 'image/jpeg', 0.85);
            };
            img.src = url;
        });
}

JS;

    $cont = str_replace('JS_AIPLACE', addslashes(qa_lang('misc/aiplace')), $cont);

    // ── Coin cost preview + prompt history + identity badge JS ───────────────
    $cont .= <<<'COSTJS'

// ── Coin cost preview ────────────────────────────────────────────────────────
function ebxUpdateCostPreview() {
    var costText    = document.getElementById('ebx-cost-text');
    var costBalance = document.getElementById('ebx-cost-balance');
    var addonHd     = document.getElementById('ebx-addon-hd');
    var addonPri    = document.getElementById('ebx-addon-priority');
    if (!costText || typeof EBONIX_PHOTO_TIERS === 'undefined') return;

    // Determine current tier from selected model radio
    var modelEl = document.querySelector('input[name="aimodel"]:checked');
    var modelVal = modelEl ? modelEl.value : '';

    // Map model → tier
    var tierMap = {
        'ebonix_10': 'standard', 'ebonix_classic': 'standard', 'ebonix_flash': 'standard',
        'ebonix_20': 'enhanced', 'ebonix_advanced': 'enhanced',
        'ebonix_pro': 'premium', 'ebonix_studio': 'premium',
        'fluxkon_selfie': 'beauty',
    };
    var tier = tierMap[modelVal] || 'enhanced';
    var tierData = EBONIX_PHOTO_TIERS[tier] || EBONIX_PHOTO_TIERS['enhanced'];
    var base = tierData ? tierData.coins : 60;
    var addons = 0;
    if (addonHd  && addonHd.checked)  addons += (EBONIX_ADDON_HD  || 0);
    if (addonPri && addonPri.checked) addons += (EBONIX_ADDON_PRI || 0);
    var total = base + addons;

    var label = tierData ? tierData.label : '';
    costText.innerHTML = '<strong>' + total + ' coins</strong> · ' + label;
    costText.className = total <= 60 ? 'ebx-cost-ok' : (total <= 100 ? 'ebx-cost-med' : 'ebx-cost-high');

    // Update balance from navbar
    var navCoins = document.getElementById('ebonix-coin-count');
    if (navCoins && costBalance) {
        var raw = (navCoins.textContent || '').replace(/[^0-9]/g, '');
        var bal = parseInt(raw, 10) || 0;
        var enough = bal >= total;
        costBalance.innerHTML = '· ' + bal.toLocaleString() + ' remaining';
        costBalance.className = 'ebx-cost-balance ' + (enough ? '' : 'ebx-cost-warn');
    }
}

// Hook into model radio changes
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('input[name="aimodel"]').forEach(function(r) {
        r.addEventListener('change', ebxUpdateCostPreview);
    });
    // Addon checkbox toggling: update cost + visual state (JS fallback for :has() CSS)
    document.querySelectorAll('#ebx-addon-hd, #ebx-addon-priority').forEach(function(c) {
        c.addEventListener('change', function() {
            var label = c.closest('.ebx-addon-toggle');
            if (label) label.classList.toggle('checked', c.checked);
            ebxUpdateCostPreview();
        });
    });
    ebxUpdateCostPreview();
});

// ── Prompt history (localStorage) ──────────────────────────────────────────
var _ebxPromptHistory = JSON.parse(localStorage.getItem('ebx_prompt_history') || '[]');

function ebxSavePrompt(text) {
    if (!text || text.length < 5) return;
    _ebxPromptHistory = _ebxPromptHistory.filter(function(p) { return p !== text; });
    _ebxPromptHistory.unshift(text);
    if (_ebxPromptHistory.length > 20) _ebxPromptHistory = _ebxPromptHistory.slice(0, 20);
    localStorage.setItem('ebx_prompt_history', JSON.stringify(_ebxPromptHistory));
}

function ebxShowPromptHistory() {
    var box = document.getElementById('ai-box');
    if (!_ebxPromptHistory.length || !box) return;
    var existing = document.getElementById('ebx-history-drop');
    if (existing) { existing.remove(); return; }
    var drop = document.createElement('div');
    drop.id = 'ebx-history-drop';
    drop.className = 'ebx-history-drop';
    _ebxPromptHistory.forEach(function(p) {
        var item = document.createElement('div');
        item.className = 'ebx-history-item';
        item.textContent = p.length > 80 ? p.slice(0, 80) + '…' : p;
        item.onclick = function() { box.value = p; drop.remove(); ebxUpdateCostPreview(); };
        drop.appendChild(item);
    });
    box.parentNode.style.position = 'relative';
    box.parentNode.appendChild(drop);
    setTimeout(function() { document.addEventListener('click', function h(e) { if (!drop.contains(e.target)) { drop.remove(); document.removeEventListener('click', h); } }); }, 100);
}

document.addEventListener('DOMContentLoaded', function() {
    var box = document.getElementById('ai-box');
    if (!box) return;
    // Add history icon button next to textarea
    var histBtn = document.createElement('button');
    histBtn.type = 'button';
    histBtn.className = 'ebx-history-btn';
    histBtn.title = 'Prompt history';
    histBtn.innerHTML = '<i class="fa-solid fa-clock-rotate-left"></i>';
    histBtn.onclick = function(e) { e.stopPropagation(); ebxShowPromptHistory(); };
    box.parentNode.insertBefore(histBtn, box.nextSibling);
});

// ── Scene picker ─────────────────────────────────────────────────────────────
var _ebxActiveScene = null; // { key, prompt }

function ebxSceneSelect(btn) {
    var key    = btn.getAttribute('data-key');
    var prompt = btn.getAttribute('data-prompt') || '';
    var box    = document.getElementById('ai-box');

    // Deselect previous scene from textarea
    if (_ebxActiveScene) {
        document.querySelectorAll('.ebx-scene-card.active').forEach(function(c) { c.classList.remove('active'); });
        if (box && _ebxActiveScene.prompt) {
            var esc = _ebxActiveScene.prompt.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            box.value = box.value
                .replace(new RegExp(',?\\s*' + esc + ',?\\s*', 'gi'), ' ')
                .replace(/^[,\s]+|[,\s]+$/g, '').trim();
        }
        // If same card clicked again — just deselect
        if (_ebxActiveScene.key === key) {
            _ebxActiveScene = null;
            if (typeof adjustHeight === 'function') adjustHeight(box);
            return;
        }
    }

    // Select new scene
    btn.classList.add('active');
    _ebxActiveScene = { key: key, prompt: prompt };
    if (box && prompt) {
        var current = box.value.trim();
        box.value = current ? current + ', ' + prompt : prompt;
        if (typeof adjustHeight === 'function') adjustHeight(box);
    }
}

// ── Chips accordion ──────────────────────────────────────────────────────────
function ebxToggleChips(btn) {
    var body     = document.getElementById('ebx-chips-body');
    var chevron  = btn.querySelector('.ebx-chips-chevron');
    var expanded = btn.getAttribute('aria-expanded') === 'true';
    if (expanded) {
        body.style.maxHeight = '0';
        btn.setAttribute('aria-expanded', 'false');
        btn.classList.remove('open');
    } else {
        body.style.maxHeight = body.scrollHeight + 'px';
        btn.setAttribute('aria-expanded', 'true');
        btn.classList.add('open');
    }
}

// ── Style chip logic ─────────────────────────────────────────────────────────
var _ebxActiveChips = {}; // key → prompt text

function ebxChipToggle(btn) {
    var key    = btn.getAttribute('data-key');
    var prompt = btn.getAttribute('data-prompt') || '';
    var box    = document.getElementById('ai-box');

    if (btn.classList.contains('active')) {
        // Deactivate — remove its prompt fragment from textarea
        btn.classList.remove('active');
        delete _ebxActiveChips[key];
        if (box && prompt) {
            // Remove ", prompt" or "prompt, " or just "prompt"
            var escaped = prompt.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            box.value = box.value
                .replace(new RegExp(',?\\s*' + escaped + ',?\\s*', 'gi'), ' ')
                .replace(/^[,\s]+|[,\s]+$/g, '')
                .replace(/,\s*,/g, ',')
                .trim();
            if (typeof adjustHeight === 'function') adjustHeight(box);
        }
    } else {
        // Activate — append to textarea
        btn.classList.add('active');
        _ebxActiveChips[key] = prompt;
        if (box && prompt) {
            var current = box.value.trim();
            box.value = current ? current + ', ' + prompt : prompt;
            if (typeof adjustHeight === 'function') adjustHeight(box);
        }
    }
    // Update count badge on toggle button
    var count     = Object.keys(_ebxActiveChips).length;
    var countEl   = document.getElementById('ebx-chips-count');
    if (countEl) countEl.textContent = count > 0 ? count : '';
    ebxUpdateCostPreview();
}

function ebxGetActiveChipContext() {
    return Object.values(_ebxActiveChips).join(', ');
}

// ── Show identity badge + save prompt after generation ───────────────────
var _ebxOrigAigenerateHandle = typeof _aigenerate_handle_response !== 'undefined' ? _aigenerate_handle_response : null;
document.addEventListener('ebx:generation:success', function(e) {
    var badge = document.getElementById('ebx-id-badge');
    if (badge) badge.style.display = 'flex';
    // Save prompt to history
    var box = document.getElementById('ai-box');
    if (box && box.value.trim()) ebxSavePrompt(box.value.trim());
    // Refresh cost balance
    setTimeout(ebxUpdateCostPreview, 500);
});

COSTJS;

    $cont .= '</script>';

    // ═══════════════════════════════════════════════════════════════════════
    // INLINE CSS
    // ═══════════════════════════════════════════════════════════════════════
    $cont .= '<style>

/* ── Hidden real file input ── */
.aiupload-file-hidden {
    position: absolute;
    left: -9999px;
    opacity: 0;
    width: 1px;
    height: 1px;
    pointer-events: none;
}

/* ── Attach button in toolbar ── */
.ai-attach-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    border: none;
    background: transparent;
    color: #888;
    font-size: 15px;
    cursor: pointer;
    transition: color 0.2s, background 0.2s;
    flex-shrink: 0;
}
.ai-attach-btn:hover { color: #7b61ff; background: rgba(123,97,255,0.1); }
.ai-attach-btn.has-image { color: #7b61ff; }
.ai-attach-btn.has-image i { color: #7b61ff; }

/* ── Image preview chip ── */
#ref-image-preview-wrap {
    padding: 6px 12px 0;
}
.ref-img-chip {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: rgba(123,97,255,0.1);
    border: 1px solid rgba(123,97,255,0.3);
    border-radius: 10px;
    padding: 5px 10px 5px 6px;
    max-width: 100%;
    overflow: hidden;
}
.ref-img-chip img {
    width: 36px;
    height: 36px;
    object-fit: cover;
    border-radius: 7px;
    flex-shrink: 0;
    display: block;
}
#ref-image-chipname {
    font-size: 12px;
    color: #bbb;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    max-width: 200px;
    flex: 1;
}
.ref-img-chip-remove {
    flex-shrink: 0;
    background: rgba(220,50,50,0.15);
    border: 1px solid rgba(220,50,50,0.3);
    border-radius: 50%;
    width: 20px;
    height: 20px;
    cursor: pointer;
    color: #ff6b6b;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 10px;
    padding: 0;
    transition: background 0.2s;
    line-height: 1;
}
.ref-img-chip-remove:hover { background: rgba(220,50,50,0.35); }

/* ── Selfie presets ── */
#selfie-presets-panel { margin: 10px 0; }
.selfie-presets-header {
    font-size: 13px;
    font-weight: 600;
    color: #aaa;
    margin-bottom: 10px;
    display: flex;
    align-items: center;
    gap: 6px;
}
.selfie-presets-grid {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 8px;
}
@media (max-width: 600px) {
    .selfie-presets-grid { grid-template-columns: repeat(3, 1fr); }
}
.selfie-preset-item {
    cursor: pointer;
    border-radius: 10px;
    border: 2px solid transparent;
    background: rgba(255,255,255,0.04);
    padding: 12px 6px 10px;
    text-align: center;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 6px;
    transition: all 0.2s;
    user-select: none;
}
.selfie-preset-item:hover         { background: rgba(123,97,255,0.1); border-color: rgba(123,97,255,0.3); }
.selfie-preset-item.selfie-active { border-color: #7b61ff; background: rgba(123,97,255,0.15); }
.selfie-preset-item i     { font-size: 20px; color: #7b61ff; }
.selfie-preset-label      { font-size: 12px; font-weight: 600; color: #ddd; }
.selfie-preset-desc       { font-size: 10px; color: #888; line-height: 1.3; }

</style>';

    $qa_content['custom'] = $cont;

    // ── Form (publish sidebar) ────────────────────────────────────────────────
    $qa_content['form'] = array(
'tags'   => 'name="ask" method="post" action="' . qa_self_html() . '" id="ai-form" novalidate',
        'style'  => 'tall',
        'fields' => array(
            'close'    => array('type' => 'custom', 'html' => '<span onclick="aipublish(this)" class="aisclose"><i class="fa-solid fa-xmark"></i></span>'),
            'errorc'   => array('type' => 'custom', 'html' => '<div id="error-container"></div>'),
            'title'    => array(
                'label' => qa_lang_html('question/q_title_label'),
'tags'  => 'name="title" id="title" autocomplete="off"',                'value' => qa_html(@$in['title']),
                'error' => qa_html(@$errors['title']),
            ),
            'similar'  => array('type' => 'custom', 'html' => '<span id="similar"></span>'),
            'uniqueid' => array('label' => '', 'tags' => 'name="uniqueid" id="uniqueid" class="hide"'),
        ),
        'buttons' => array(
            'ask' => array(
                'tags'  => 'onclick="submitAiform(event);" id="submitButton"',
                'label' => qa_lang_html('question/ask_button'),
            ),
        ),
        'hidden' => array(
            'code'  => qa_get_form_security_code('ask'),
            'doask' => '1',
        ),
    );

    script_options($qa_content);
    if (!strlen($custom)) unset($qa_content['form']['fields']['custom']);

    if (qa_opt('do_ask_check_qs') || qa_opt('do_example_tags')) {
        $qa_content['script_rel'][] = 'king-content/king-ask.js?' . QA_VERSION;
        $qa_content['form']['fields']['title']['tags'] .= ' onchange="qa_title_change(this.value);"';
        if (strlen(@$in['title'])) {
            $qa_content['script_onloads'][] = 'qa_title_change(' . qa_js($in['title']) . ');';
        }
    }

    $qa_content['script_var']['leoai']           = $king_ajax_url;
    $qa_content['script_var']['ebonix_ajax_url'] = $king_ajax_url;
    $qa_content['script_var']['ebonix_qa_root']  = rtrim(qa_opt('site_url'), '/') . '/';

    if (isset($followanswer)) {
        $viewer = qa_load_viewer($followanswer['content'], $followanswer['format']);
        $field  = array(
            'type'  => 'static',
            'label' => qa_lang_html('question/ask_follow_from_a'),
            'value' => $viewer->get_html($followanswer['content'], $followanswer['format'],
                array('blockwordspreg' => qa_get_block_words_preg())),
        );
        qa_array_insert($qa_content['form']['fields'], 'title', array('follows' => $field));
    }

    if (qa_using_categories() && count($categories)) {
        $field = array(
            'label' => qa_lang_html('question/q_category_label'),
            'error' => qa_html(@$errors['categoryid']),
        );
        qa_set_up_category_field($qa_content, $field, 'category', $categories,
            $in['categoryid'], true, qa_opt('allow_no_sub_category'));
        if (!qa_opt('allow_no_category')) $field['options'][''] = '';
        qa_array_insert($qa_content['form']['fields'], 'similar', array('category' => $field));
    }

    if (qa_using_tags()) {
        $field = array('error' => qa_html(@$errors['tags']));
        qa_set_up_tag_field($qa_content, $field, 'tags',
            isset($in['tags']) ? $in['tags'] : array(), array(),
            qa_opt('do_complete_tags') ? array_keys($completetags) : array(),
            qa_opt('page_size_ask_tags'));
        qa_array_insert($qa_content['form']['fields'], null, array('tags' => $field));
    }

    if (qa_opt('enable_nsfw') || qa_opt('enable_pposts')) {
        $nsfw = ''; $prvt = '';
        if (qa_opt('enable_pposts')) {
            $prvt = '<input name="prvt" id="king_prvt" type="checkbox" class="hide" value="'
                . qa_html(@$in['prvt']) . '"><label for="king_prvt" class="king-nsfw">'
                . '<i class="fa-solid fa-user-ninja"></i> ' . qa_lang('misc/prvt') . '</label>';
        }
        if (qa_opt('enable_nsfw')) {
            $nsfw = '<input name="nsfw" id="king_nsfw" type="checkbox" value="'
                . qa_html(@$in['nsfw']) . '"><label for="king_nsfw" class="king-nsfw">'
                . qa_lang_html('misc/nsfw') . '</label>';
        }
        $field = array('type' => 'custom', 'html' => $prvt . $nsfw);
        qa_array_insert($qa_content['form']['fields'], null, array('nsfw' => $field));
    }

    if (!isset($userid)) qa_set_up_name_field($qa_content, $qa_content['form']['fields'], @$in['name']);

    if ($captchareason) {
        require_once QA_INCLUDE_DIR . 'king-app/captcha.php';
        qa_set_up_captcha_field($qa_content, $qa_content['form']['fields'],
            @$errors, qa_captcha_reason_note($captchareason));
    }

} else {
    $cont2  = '<div class="kingai-input">';
    $cont2 .= '<textarea id="ai-box" class="aiinput" data-toggle="modal" data-target="#loginmodal"'
        . ' placeholder="' . qa_lang('misc/aiplace') . '"'
        . ' maxlength="600" autocomplete="off" style="height:44px;" rows="1"></textarea>';
    $cont2 .= '<div class="kingai-buttons">';
    $cont2 .= '<div class="king-sbutton" data-toggle="modal" data-target="#loginmodal" role="button">'
        . '<i class="fa-solid fa-sliders"></i></div>';
    $cont2 .= '<button type="button" id="ai-submit" class="ai-submit"'
        . ' data-toggle="modal" data-target="#loginmodal">'
        . '<span><i class="fa-solid fa-paper-plane"></i> ' . qa_lang('misc/generate') . '</span>'
        . '<div class="loader"></div></button>';
    $cont2 .= '</div></div>';
    $qa_content['custom'] = $cont2;
}

$qa_content['class']   = ' ai-create';
$qa_content['focusid'] = 'ai-box';

return $qa_content;